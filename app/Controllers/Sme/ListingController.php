<?php

declare(strict_types=1);

namespace App\Controllers\Sme;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ListingRepository;
use App\Repositories\MembershipRepository;
use App\Repositories\OrganizationRepository;
use App\Services\FileStorageService;
use App\Services\ListingService;
use App\Validation\Validator;

/**
 * إدارة المنتجات والخدمات | Listing management for the seller (§4.4).
 *
 * كل استعلام هنا مقيَّد بالمنشأة النشطة عبر المستودع، فمحاولة تعديل إعلان منشأة
 * أخرى تعود 404 لا 403.
 */
final class ListingController extends Controller
{
    public function __construct(
        private readonly ListingRepository $listings = new ListingRepository(),
        private readonly OrganizationRepository $organizations = new OrganizationRepository(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
        private readonly ListingService $service = new ListingService(),
        private readonly FileStorageService $storage = new FileStorageService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $status         = (string) ($request->input('status') ?? '');

        return $this->view('sme/listings/index', [
            'pageTitle'     => 'المنتجات والخدمات',
            'results'       => $this->listings->forOrganization(
                $organizationId,
                $status === '' ? null : $status,
                (string) ($request->input('q') ?? ''),
                $this->page($request),
            ),
            'counts'        => $this->listings->countsByStatus($organizationId),
            'filters'       => ['status' => $status, 'q' => (string) ($request->input('q') ?? '')],
            'organization'  => $this->organizations->findWithDetails($organizationId),
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
            'service'       => $this->service,
        ], 'app');
    }

    public function create(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        return $this->view('sme/listings/form', [
            'pageTitle'     => 'إضافة منتج أو خدمة',
            'listing'       => null,
            'images'        => [],
            'categories'    => $this->categories(),
            'organization'  => $this->organizations->findWithDetails($organizationId),
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
            'defaultVat'    => \App\Services\SettingsService::decimal('marketplace', 'default_vat_rate', 14.0),
        ], 'app');
    }

    public function store(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $validator      = $this->validate($request);

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/app/listings/new');
        }

        try {
            $listingId = $this->service->create($organizationId, $request->all(), $this->currentUserId(), $request);
        } catch (HttpException $e) {
            return $this->back($request, ['price' => $e->getMessage()], '/app/listings/new');
        }

        $this->flash('success', 'تم حفظ الصنف كمسودة. أضف صوره ثم أرسله للنشر.');

        return $this->redirect('/app/listings/' . $listingId);
    }

    public function edit(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $listingId      = $request->routeInt('id');

        if ($listingId === null) {
            throw new HttpException(404, 'الصنف المطلوب غير موجود.');
        }

        $listing = $this->listings->scopedToTenant($organizationId)->findOrFail($listingId);

        return $this->view('sme/listings/form', [
            'pageTitle'     => 'تعديل: ' . $listing['name_ar'],
            'listing'       => $listing,
            'images'        => $this->listings->images($listingId),
            'categories'    => $this->categories(),
            'organization'  => $this->organizations->findWithDetails($organizationId),
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
            'service'       => $this->service,
            'defaultVat'    => \App\Services\SettingsService::decimal('marketplace', 'default_vat_rate', 14.0),
        ], 'app');
    }

    public function update(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $listingId      = $request->routeInt('id');

        if ($listingId === null) {
            throw new HttpException(404, 'الصنف المطلوب غير موجود.');
        }

        $validator = $this->validate($request);

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/app/listings/' . $listingId);
        }

        try {
            $this->service->update($listingId, $organizationId, $request->all(), $this->currentUserId(), $request);
        } catch (HttpException $e) {
            return $this->back($request, ['price' => $e->getMessage()], '/app/listings/' . $listingId);
        }

        $this->flash('success', 'تم حفظ التعديلات.');

        return $this->redirect('/app/listings/' . $listingId);
    }

    /** إرسال للنشر | Submit for publication. */
    public function submit(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $listingId      = $request->routeInt('id');

        if ($listingId === null) {
            throw new HttpException(404, 'الصنف المطلوب غير موجود.');
        }

        try {
            $status = $this->service->submitForPublication($listingId, $organizationId, $this->currentUserId(), $request);

            $this->flash(
                'success',
                $status === 'published'
                    ? 'تم نشر الصنف وأصبح ظاهراً في السوق.'
                    : 'تم إرسال الصنف لمراجعة فريق المنصة قبل النشر.',
            );
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/listings/' . $listingId);
    }

    public function archive(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $listingId      = $request->routeInt('id');

        if ($listingId === null) {
            throw new HttpException(404, 'الصنف المطلوب غير موجود.');
        }

        $this->service->archive($listingId, $organizationId, $this->currentUserId(), $request);
        $this->flash('success', 'تم إخفاء الصنف من السوق.');

        return $this->redirect('/app/listings/' . $listingId);
    }

    public function destroy(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $listingId      = $request->routeInt('id');

        if ($listingId === null) {
            throw new HttpException(404, 'الصنف المطلوب غير موجود.');
        }

        // الحذف ناعم: الطلبات السابقة تشير إلى الصنف ويجب أن يبقى تاريخها مفهوماً
        $this->listings->scopedToTenant($organizationId)->delete($listingId);
        $this->flash('success', 'تم حذف الصنف.');

        return $this->redirect('/app/listings');
    }

    // ─────────────────── الصور | Images ───────────────────

    public function uploadImage(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $listingId      = $request->routeInt('id');

        if ($listingId === null) {
            throw new HttpException(404, 'الصنف المطلوب غير موجود.');
        }

        $listing = $this->listings->scopedToTenant($organizationId)->findOrFail($listingId);
        $file    = $request->file('image');

        if ($file === null) {
            return $this->back($request, ['image' => __('validation.file_required')], '/app/listings/' . $listingId);
        }

        try {
            $mediaId = $this->storage->store(
                file: $file,
                typeKey: 'image',
                collection: 'listings',
                organizationId: $organizationId,
                uploadedBy: $this->currentUserId(),
                // صور المنتجات تظهر للعامة في السوق
                visibility: 'public',
            );
        } catch (ValidationException $e) {
            return $this->back($request, $e->errors(), '/app/listings/' . $listingId);
        }

        Database::transaction(function () use ($listingId, $organizationId, $mediaId, $listing, $request): void {
            Database::statement(
                'INSERT INTO listing_images (listing_id, organization_id, media_id, alt_text, sort_order)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $listingId, $organizationId, $mediaId,
                    $request->filled('alt_text')
                        ? mb_substr((string) $request->input('alt_text'), 0, 200)
                        : mb_substr((string) $listing['name_ar'], 0, 200),
                    (int) Database::scalar(
                        'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM listing_images WHERE listing_id = ?',
                        [$listingId],
                    ),
                ],
            );

            // أول صورة تصبح الصورة الرئيسية تلقائياً
            if ($listing['primary_media_id'] === null) {
                $this->listings->scopedToTenant($organizationId)
                    ->update($listingId, ['primary_media_id' => $mediaId]);
            }
        });

        $this->flash('success', 'تمت إضافة الصورة.');

        return $this->redirect('/app/listings/' . $listingId);
    }

    public function deleteImage(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $listingId      = $request->routeInt('id');
        $imageId        = $request->routeInt('imageId');

        if ($listingId === null || $imageId === null) {
            throw new HttpException(404, 'الصورة المطلوبة غير موجودة.');
        }

        // القيد على المنشأة يمنع حذف صورة منشأة أخرى بتخمين المعرّف
        $image = Database::selectOne(
            'SELECT * FROM listing_images WHERE id = ? AND listing_id = ? AND organization_id = ?',
            [$imageId, $listingId, $organizationId],
        );

        if ($image === null) {
            throw new HttpException(404, 'الصورة المطلوبة غير موجودة.');
        }

        Database::transaction(function () use ($image, $listingId, $organizationId): void {
            Database::statement('DELETE FROM listing_images WHERE id = ?', [(int) $image['id']]);

            $listing = $this->listings->scopedToTenant($organizationId)->find($listingId);

            if ($listing !== null && (int) $listing['primary_media_id'] === (int) $image['media_id']) {
                $replacement = Database::scalar(
                    'SELECT media_id FROM listing_images WHERE listing_id = ? ORDER BY sort_order ASC LIMIT 1',
                    [$listingId],
                );

                $this->listings->scopedToTenant($organizationId)->update($listingId, [
                    'primary_media_id' => $replacement === null ? null : (int) $replacement,
                ]);
            }

            $this->storage->delete((int) $image['media_id']);
        });

        $this->flash('success', 'تم حذف الصورة.');

        return $this->redirect('/app/listings/' . $listingId);
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    private function validate(Request $request): Validator
    {
        return Validator::make($request->all())
            ->labels([
                'name_ar'            => 'اسم الصنف',
                'listing_type'       => 'النوع',
                'short_description'  => 'الوصف المختصر',
                'price'              => 'السعر',
                'vat_rate'           => 'نسبة الضريبة',
                'available_quantity' => 'الكمية المتاحة',
                'min_order_quantity' => 'الحد الأدنى للطلب',
                'lead_time_days'     => 'مدة التنفيذ',
                'delivery_fee'       => 'رسوم التوصيل',
                'sku'                => 'كود الصنف',
            ])
            ->required('name_ar')->minLength('name_ar', 3)->maxLength('name_ar', 200)
            ->required('listing_type')->in('listing_type', ['product', 'service'])
            ->required('pricing_mode')->in('pricing_mode', ['fixed', 'quote'])
            ->required('short_description')->minLength('short_description', 10)->maxLength('short_description', 500)
            ->maxLength('description', 20000)
            ->numeric('price')->between('price', 0, 99999999)
            ->numeric('vat_rate')->between('vat_rate', 0, 100)
            ->numeric('available_quantity')
            ->numeric('min_order_quantity')
            ->integer('lead_time_days')->between('lead_time_days', 0, 3650)
            ->numeric('delivery_fee')
            ->maxLength('sku', 60);
    }

    private function categories(): array
    {
        return Database::select(
            "SELECT id, name_ar FROM categories
              WHERE type = 'listing' AND is_active = 1 ORDER BY sort_order ASC",
        );
    }
}
