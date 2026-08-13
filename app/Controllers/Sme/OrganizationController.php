<?php

declare(strict_types=1);

namespace App\Controllers\Sme;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Policies\OrganizationPolicy;
use App\Repositories\DocumentRepository;
use App\Repositories\MembershipRepository;
use App\Repositories\OrganizationProfileRepository;
use App\Repositories\OrganizationRepository;
use App\Services\FileStorageService;
use App\Services\NotificationService;
use App\Services\OrganizationRegistrationService;
use App\Services\ProfileCompletionService;
use App\Services\VerificationService;
use App\Support\TenantContext;
use App\Validation\Validator;

/**
 * تسجيل المنشأة وإدارة ملفها | Organization registration and profile (§4.2).
 *
 * معالج متعدد الخطوات يحفظ بعد كل خطوة، فلا يفقد صاحب المشروع عمله إذا انقطع
 * الاتصال أو أغلق المتصفح — وهي مشكلة حقيقية للمستخدمين على شبكات محدودة.
 * A multi-step wizard that saves after each step, so an SME owner on a flaky
 * connection never loses work.
 */
final class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationRepository $organizations = new OrganizationRepository(),
        private readonly OrganizationProfileRepository $profiles = new OrganizationProfileRepository(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
        private readonly DocumentRepository $documents = new DocumentRepository(),
        private readonly OrganizationRegistrationService $registration = new OrganizationRegistrationService(),
        private readonly ProfileCompletionService $completion = new ProfileCompletionService(),
        private readonly VerificationService $verification = new VerificationService(),
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly FileStorageService $storage = new FileStorageService(),
        private readonly OrganizationPolicy $policy = new OrganizationPolicy(),
    ) {
    }

    // ═══════════════════ إنشاء منشأة جديدة | Registration ═══════════════════

    public function showTypeSelection(Request $request): Response
    {
        return $this->view('sme/organization/choose-type', [
            'pageTitle' => 'تسجيل منشأة جديدة',
            'types'     => $this->registration->availableTypes(),
        ], 'app');
    }

    public function showCreateForm(Request $request): Response
    {
        $typeCode = (string) ($request->input('type') ?? 'sme');
        $type     = $this->registration->findType($typeCode);

        if ($type === null) {
            $this->flash('danger', 'نوع المنشأة المختار غير متاح.');

            return $this->redirect('/app/organization/new');
        }

        return $this->view('sme/organization/create', [
            'pageTitle'    => 'تسجيل ' . $type['name_ar'],
            'type'         => $type,
            'sectors'      => $this->sectors(),
            'governorates' => $this->governorates(),
        ], 'app');
    }

    public function store(Request $request): Response
    {
        $typeCode = (string) ($request->input('type_code') ?? '');
        $type     = $this->registration->findType($typeCode);

        if ($type === null) {
            $this->flash('danger', 'نوع المنشأة المختار غير متاح.');

            return $this->redirect('/app/organization/new');
        }

        $validator = Validator::make($request->all())
            ->labels([
                'legal_name'        => 'الاسم القانوني',
                'trading_name'      => 'الاسم التجاري',
                'sector_id'         => 'القطاع',
                'governorate_id'    => 'المحافظة',
                'public_phone'      => 'رقم الهاتف',
                'public_email'      => 'البريد الإلكتروني',
                'short_description' => 'وصف مختصر',
            ])
            ->required('legal_name')->minLength('legal_name', 3)->maxLength('legal_name', 200)
            ->maxLength('trading_name', 200)
            ->required('sector_id')->integer('sector_id')
            ->required('governorate_id')->integer('governorate_id')
            ->required('public_phone')->phone('public_phone')
            ->email('public_email')
            ->required('short_description')->minLength('short_description', 20)->maxLength('short_description', 500);

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/app/organization/new/form?type=' . $typeCode);
        }

        $result = $this->registration->create(
            $typeCode,
            $request->all(),
            (int) $this->currentUserId(),
            $request,
        );

        // تفعيل المنشأة الجديدة كمنشأة نشطة للمستخدم
        Session::put('active_organization_id', $result['organization_id']);

        $this->flash('success', 'تم إنشاء ملف المنشأة. استكمل البيانات والمستندات ثم أرسله للمراجعة.');

        return $this->redirect('/app/organization');
    }

    // ═══════════════════ عرض وتحرير الملف | Profile view/edit ═══════════════════

    public function show(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $organization   = $this->organizations->findWithDetails($organizationId);

        if ($organization === null) {
            throw new \App\Core\Exceptions\HttpException(404, 'المنشأة غير موجودة.');
        }

        $this->policy->authorizeView($organization);

        $typeCode = (string) $organization['type_code'];

        return $this->view('sme/organization/show', [
            'pageTitle'     => 'ملف المنشأة',
            'organization'  => $organization,
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
            'profile'       => $this->profiles->findForOrganization($organizationId, $typeCode) ?? [],
            'completion'    => $this->completion->evaluate($organizationId),
            'checklist'     => $this->documents->checklistFor($organizationId, $typeCode),
            'history'       => $this->verification->history($organizationId),
            // تُستخدم في القالب لترجمة أسماء الإجراءات إلى تسميات عربية
            'verificationService' => $this->verification,
            'canEdit'       => $this->policy->canUpdate($organization),
            'canSubmit'     => $this->policy->canSubmit($organization),
            'sectors'       => $this->sectors(),
            'governorates'  => $this->governorates(),
            'cities'        => $this->cities((int) ($organization['governorate_id'] ?? 0)),
            'subSectors'    => $this->subSectors((int) ($organization['sector_id'] ?? 0)),
        ], 'app');
    }

    public function updateBasics(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $organization   = $this->organizations->findWithDetails($organizationId);

        if ($organization === null) {
            throw new \App\Core\Exceptions\HttpException(404, 'المنشأة غير موجودة.');
        }

        $this->policy->authorizeUpdate($organization);

        $validator = Validator::make($request->all())
            ->labels([
                'legal_name'        => 'الاسم القانوني',
                'sector_id'         => 'القطاع',
                'governorate_id'    => 'المحافظة',
                'public_phone'      => 'رقم الهاتف',
                'public_email'      => 'البريد الإلكتروني',
                'website'           => 'الموقع الإلكتروني',
                'short_description' => 'وصف مختصر',
            ])
            ->required('legal_name')->minLength('legal_name', 3)->maxLength('legal_name', 200)
            ->required('sector_id')->integer('sector_id')
            ->required('governorate_id')->integer('governorate_id')
            ->phone('public_phone')
            ->email('public_email')
            ->url('website')
            ->maxLength('short_description', 500)
            ->maxLength('description', 5000);

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/app/organization');
        }

        $this->registration->updateBasics(
            $organizationId,
            $request->all(),
            $this->currentUserId(),
            $request,
        );

        $this->flash('success', 'تم حفظ بيانات المنشأة.');

        return $this->redirect('/app/organization');
    }

    public function updateProfile(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $organization   = $this->organizations->findWithDetails($organizationId);

        if ($organization === null) {
            throw new \App\Core\Exceptions\HttpException(404, 'المنشأة غير موجودة.');
        }

        $this->policy->authorizeUpdate($organization);

        $typeCode  = (string) $organization['type_code'];
        $validator = $this->validateProfile($request, $typeCode);

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/app/organization');
        }

        $this->registration->updateProfile(
            $organizationId,
            $typeCode,
            $request->all(),
            $this->currentUserId(),
            $request,
        );

        $this->flash('success', 'تم حفظ البيانات التفصيلية.');

        return $this->redirect('/app/organization');
    }

    /** رفع شعار المنشأة | Upload the organization logo. */
    public function uploadLogo(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $organization   = $this->organizations->findWithDetails($organizationId);

        if ($organization === null) {
            throw new \App\Core\Exceptions\HttpException(404, 'المنشأة غير موجودة.');
        }

        $this->policy->authorizeUpdate($organization);

        $file = $request->file('logo');

        if ($file === null) {
            return $this->back($request, ['logo' => __('validation.file_required')], '/app/organization');
        }

        try {
            $mediaId = $this->storage->store(
                file: $file,
                typeKey: 'image',
                collection: 'logos',
                organizationId: $organizationId,
                uploadedBy: $this->currentUserId(),
                // الشعار يظهر على الصفحة العامة للمنشأة
                visibility: 'public',
            );
        } catch (\App\Core\Exceptions\ValidationException $e) {
            return $this->back($request, $e->errors(), '/app/organization');
        }

        $previousLogoId = $organization['logo_media_id'] ?? null;

        $this->organizations->update($organizationId, ['logo_media_id' => $mediaId]);

        if ($previousLogoId !== null) {
            $this->storage->delete((int) $previousLogoId);
        }

        $this->completion->recalculate($organizationId);
        $this->flash('success', 'تم تحديث شعار المنشأة.');

        return $this->redirect('/app/organization');
    }

    // ═══════════════════ الإرسال للمراجعة | Submit for review ═══════════════════

    public function submit(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $organization   = $this->organizations->findWithDetails($organizationId);

        if ($organization === null) {
            throw new \App\Core\Exceptions\HttpException(404, 'المنشأة غير موجودة.');
        }

        if (!$this->policy->canSubmit($organization)) {
            $this->flash('warning', 'لا يمكن إرسال الطلب في الحالة الحالية للمنشأة.');

            return $this->redirect('/app/organization');
        }

        $this->verification->assertActorMayPerform(
            'submit',
            TenantContext::isPlatformStaff(),
            TenantContext::organizationId() === $organizationId,
        );

        try {
            $this->verification->transition(
                organizationId: $organizationId,
                action: 'submit',
                actorUserId: $this->currentUserId(),
                actorRole: (string) (TenantContext::membership()['role_code'] ?? ''),
                request: $request,
            );
        } catch (\App\Core\Exceptions\HttpException $e) {
            // رسالة الشروط الناقصة تُعرض للمستخدم كتنبيه مفهوم لا كصفحة خطأ
            $this->flash('warning', $e->getMessage());

            return $this->redirect('/app/organization');
        }

        // إعلام فريق المراجعة بوصول طلب جديد
        $this->notifications->notifyPlatformReviewers(
            type: 'verification.queued',
            title: 'طلب توثيق جديد',
            body: 'وصل طلب توثيق من «' . ($organization['trading_name'] ?: $organization['legal_name']) . '».',
            actionUrl: url('/admin/verifications'),
            organizationId: $organizationId,
            entityType: 'organization',
            entityId: $organizationId,
        );

        $this->flash('success', 'تم إرسال ملف المنشأة للمراجعة. سنُعلمك فور صدور القرار.');

        return $this->redirect('/app/organization');
    }

    // ═══════════════════ أدوات | Helpers ═══════════════════

    private function validateProfile(Request $request, string $typeCode): Validator
    {
        $validator = Validator::make($request->all())->labels([
            'contact_person_name'  => 'اسم مسؤول التواصل',
            'contact_person_phone' => 'هاتف مسؤول التواصل',
            'contact_person_email' => 'بريد مسؤول التواصل',
            'employees_count'      => 'عدد العاملين',
            'establishment_date'   => 'تاريخ التأسيس',
            'facebook_url'         => 'رابط فيسبوك',
            'instagram_url'        => 'رابط إنستجرام',
            'linkedin_url'         => 'رابط لينكدإن',
        ]);

        $validator
            ->maxLength('contact_person_name', 150)
            ->phone('contact_person_phone')
            ->email('contact_person_email');

        if ($typeCode === 'sme') {
            $validator
                ->in('formalization_status', [
                    'informal', 'sole_proprietorship', 'partnership', 'llc',
                    'joint_stock', 'cooperative', 'other',
                ])
                ->in('company_size', ['micro', 'small', 'medium'])
                ->integer('employees_count')->between('employees_count', 0, 100000)
                ->integer('female_employees_count')->between('female_employees_count', 0, 100000)
                ->date('establishment_date')
                ->url('facebook_url')->url('instagram_url')->url('linkedin_url')
                ->numeric('financing_amount_needed');

            // اتساق منطقي: العاملات جزء من إجمالي العاملين
            $total  = $request->integer('employees_count');
            $female = $request->integer('female_employees_count');

            if ($total !== null && $female !== null && $female > $total) {
                $validator->addError(
                    'female_employees_count',
                    'عدد العاملات لا يمكن أن يتجاوز إجمالي عدد العاملين.',
                );
            }
        }

        if (in_array($typeCode, ['service_provider', 'bank', 'ngo', 'government'], true)) {
            $validator
                ->integer('years_experience')->between('years_experience', 0, 200)
                ->integer('team_size')->between('team_size', 0, 100000)
                ->maxLength('specializations', 1000);
        }

        if ($typeCode === 'bds_center') {
            $validator
                ->integer('monthly_capacity')->between('monthly_capacity', 0, 100000)
                ->integer('specialists_count')->between('specialists_count', 0, 10000)
                ->maxLength('services_offered', 2000);
        }

        return $validator;
    }

    /** @return array<int,array<string,mixed>> */
    private function sectors(): array
    {
        return Database::select('SELECT id, name_ar FROM sectors WHERE is_active = 1 ORDER BY sort_order ASC');
    }

    private function subSectors(int $sectorId): array
    {
        if ($sectorId === 0) {
            return [];
        }

        return Database::select(
            'SELECT id, name_ar FROM sub_sectors WHERE sector_id = ? AND is_active = 1 ORDER BY sort_order ASC',
            [$sectorId],
        );
    }

    private function governorates(): array
    {
        return Database::select('SELECT id, name_ar FROM governorates WHERE is_active = 1 ORDER BY sort_order ASC');
    }

    private function cities(int $governorateId): array
    {
        if ($governorateId === 0) {
            return [];
        }

        return Database::select(
            'SELECT id, name_ar FROM cities WHERE governorate_id = ? AND is_active = 1 ORDER BY name_ar ASC',
            [$governorateId],
        );
    }

    /**
     * نقاط JSON لتحديث القوائم المرتبطة | JSON endpoints for dependent selects.
     * تُستخدم عبر fetch لتحديث المدن والأنشطة الفرعية دون إعادة تحميل الصفحة.
     */
    public function citiesJson(Request $request): Response
    {
        return $this->json(['data' => $this->cities($request->integer('governorate_id', 0) ?? 0)]);
    }

    public function subSectorsJson(Request $request): Response
    {
        return $this->json(['data' => $this->subSectors($request->integer('sector_id', 0) ?? 0)]);
    }
}
