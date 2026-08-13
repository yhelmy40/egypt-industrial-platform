<?php

declare(strict_types=1);

namespace App\Controllers\Sme;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Policies\OrganizationPolicy;
use App\Repositories\DocumentRepository;
use App\Repositories\MembershipRepository;
use App\Repositories\OrganizationRepository;
use App\Services\AuditLogger;
use App\Services\FileStorageService;
use App\Services\ProfileCompletionService;
use App\Validation\Validator;

/**
 * مستندات المنشأة | Organization documents (§4.2).
 *
 * الرفع والحذف يمرّان بسياسة الملكية أولاً ثم بطبقة فحص الملفات. المستندات
 * بيانات مراجعة حسّاسة ولا تُقدَّم إلا عبر متحكّم التنزيل المُفوَّض.
 */
final class DocumentController extends Controller
{
    public function __construct(
        private readonly OrganizationRepository $organizations = new OrganizationRepository(),
        private readonly DocumentRepository $documents = new DocumentRepository(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
        private readonly FileStorageService $storage = new FileStorageService(),
        private readonly ProfileCompletionService $completion = new ProfileCompletionService(),
        private readonly OrganizationPolicy $policy = new OrganizationPolicy(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    public function index(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $organization   = $this->organizations->findWithDetails($organizationId);

        if ($organization === null) {
            throw new HttpException(404, 'المنشأة غير موجودة.');
        }

        $this->policy->authorizeViewDocuments($organization);

        $typeCode = (string) $organization['type_code'];

        return $this->view('sme/organization/documents', [
            'pageTitle'      => 'مستندات المنشأة',
            'organization'   => $organization,
            'organizations'  => $this->memberships->organizationsForUser((int) $this->currentUserId()),
            'checklist'      => $this->documents->checklistFor($organizationId, $typeCode),
            'documents'      => $this->documents->forOrganization($organizationId),
            'canUpload'      => $this->policy->canUploadDocuments($organization),
            'maxSize'        => $this->storage->formatBytes((int) config('uploads.types.document.max_size', 10485760)),
            'allowedTypes'   => implode('، ', (array) config('uploads.types.document.extensions', [])),
        ], 'app');
    }

    public function upload(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $organization   = $this->organizations->findWithDetails($organizationId);

        if ($organization === null) {
            throw new HttpException(404, 'المنشأة غير موجودة.');
        }

        $this->policy->authorizeUploadDocuments($organization);

        $validator = Validator::make($request->all())
            ->labels([
                'document_type_id' => 'نوع المستند',
                'document_number'  => 'رقم المستند',
                'issue_date'       => 'تاريخ الإصدار',
                'expiry_date'      => 'تاريخ الانتهاء',
            ])
            ->required('document_type_id')->integer('document_type_id')
            ->maxLength('document_number', 100)
            ->date('issue_date')
            ->date('expiry_date');

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/app/organization/documents');
        }

        // نوع المستند يجب أن ينطبق على نوع هذه المنشأة تحديداً — وإلا أمكن
        // إرفاق مستند بنك بملف مشروع صغير عبر تعديل قيمة الحقل.
        $documentType = $this->findApplicableType(
            (int) $request->integer('document_type_id'),
            (string) $organization['type_code'],
        );

        if ($documentType === null) {
            return $this->back(
                $request,
                ['document_type_id' => 'نوع المستند غير متاح لهذا النوع من المنشآت.'],
                '/app/organization/documents',
            );
        }

        $file = $request->file('document');

        if ($file === null) {
            return $this->back($request, ['document' => __('validation.file_required')], '/app/organization/documents');
        }

        try {
            $documentId = Database::transaction(function () use ($file, $organizationId, $documentType, $request): int {
                $mediaId = $this->storage->store(
                    file: $file,
                    typeKey: 'document',
                    collection: 'documents',
                    organizationId: $organizationId,
                    uploadedBy: $this->currentUserId(),
                    visibility: 'private',
                );

                $documentId = $this->documents->scopedToTenant($organizationId)->create([
                    'document_type_id' => (int) $documentType['id'],
                    'media_id'         => $mediaId,
                    'status'           => 'pending',
                    'document_number'  => $request->filled('document_number')
                        ? mb_substr((string) $request->input('document_number'), 0, 100) : null,
                    'issue_date'       => $request->filled('issue_date') ? $request->input('issue_date') : null,
                    'expiry_date'      => $request->filled('expiry_date') ? $request->input('expiry_date') : null,
                    'uploaded_by'      => $this->currentUserId(),
                ]);

                // رفع مستند من نفس النوع يستبدل السابق بدل تكديس النسخ
                $this->documents->supersedePrevious(
                    $organizationId,
                    (int) $documentType['id'],
                    $documentId,
                );

                return $documentId;
            });
        } catch (ValidationException $e) {
            return $this->back($request, $e->errors(), '/app/organization/documents');
        }

        $this->audit->setRequest($request);
        $this->audit->log(
            action: 'document.uploaded',
            category: AuditLogger::CATEGORY_DOCUMENT,
            entityType: 'organization_document',
            entityId: $documentId,
            description: 'رفع مستند: ' . $documentType['name_ar'],
            userId: $this->currentUserId(),
            organizationId: $organizationId,
        );

        $this->completion->recalculate($organizationId);
        $this->flash('success', 'تم رفع المستند وسيُراجع مع ملف المنشأة.');

        return $this->redirect('/app/organization/documents');
    }

    public function delete(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $organization   = $this->organizations->findWithDetails($organizationId);

        if ($organization === null) {
            throw new HttpException(404, 'المنشأة غير موجودة.');
        }

        $documentId = $request->routeInt('id');

        if ($documentId === null) {
            throw new HttpException(404, 'المستند المطلوب غير موجود.');
        }

        // المستودع مقيّد بالمنشأة: مستند منشأة أخرى يعود null ⇒ 404
        $document = $this->documents->scopedToTenant($organizationId)->findOrFail($documentId);

        if (!$this->policy->canDeleteDocument($organization, $document)) {
            $this->flash('warning', 'لا يمكن حذف مستند تم اعتماده ضمن قرار التوثيق.');

            return $this->redirect('/app/organization/documents');
        }

        Database::transaction(function () use ($documentId, $organizationId, $document): void {
            $this->documents->scopedToTenant($organizationId)->delete($documentId);
            $this->storage->delete((int) $document['media_id']);
        });

        $this->audit->setRequest($request);
        $this->audit->logRecordDeleted('organization_document', $documentId, $organizationId);

        $this->completion->recalculate($organizationId);
        $this->flash('success', 'تم حذف المستند.');

        return $this->redirect('/app/organization/documents');
    }

    private function findApplicableType(int $documentTypeId, string $organizationTypeCode): ?array
    {
        return Database::selectOne(
            "SELECT * FROM document_types
              WHERE id = ? AND is_active = 1 AND (applies_to = ? OR applies_to = 'all')
              LIMIT 1",
            [$documentTypeId, $organizationTypeCode],
        );
    }
}
