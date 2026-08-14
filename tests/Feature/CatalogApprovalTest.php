<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Repositories\FinancingProductRepository;
use App\Repositories\ServiceOfferingRepository;
use App\Services\FinancingProductService;
use App\Services\ServiceOfferingService;
use Tests\TestCase;

/**
 * بوابة الاعتماد | The approval gate (§4.5, §4.6).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * القاعدة التي تحرسها هذه المجموعة: **لا يظهر منتج تمويلي ولا باقة خدمة لأي
 * مشروع قبل قرار اعتماد موثّق من المنصة.** المزوّد يُنشئ ويُرسل، والمنصة تقرّر.
 *
 * ثغرة هنا تعني عرض التزام تجاري لم يراجعه أحد على صاحب مشروع.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class CatalogApprovalTest extends TestCase
{
    private FinancingProductService $products;

    private ServiceOfferingService $offerings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->products  = new FinancingProductService();
        $this->offerings = new ServiceOfferingService();
    }

    // ═══════════════════ حدّ الرؤية | The visibility boundary ═══════════════════

    public function test_only_approved_products_reach_the_public_catalogue(): void
    {
        $bankId = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);

        foreach (['draft', 'pending_review', 'rejected', 'archived'] as $status) {
            $this->createFinancingProduct($bankId, [
                'name_ar' => 'منتج مخفي ' . $status, 'status' => $status, 'published_at' => null,
            ]);
        }

        $this->createFinancingProduct($bankId, ['name_ar' => 'منتج معتمد ظاهر']);

        $results = (new FinancingProductRepository())->searchPublic([]);

        $this->assertSame(1, $results['total']);
        $this->assertSame('منتج معتمد ظاهر', $results['data'][0]['name_ar']);
    }

    public function test_only_approved_offerings_reach_the_public_catalogue(): void
    {
        $providerId = $this->createOrganization(['type_code' => 'service_provider', 'status' => 'verified']);

        foreach (['draft', 'pending_review', 'rejected', 'archived'] as $status) {
            $this->createServiceOffering($providerId, [
                'name_ar' => 'باقة مخفية ' . $status, 'status' => $status, 'published_at' => null,
            ]);
        }

        $this->createServiceOffering($providerId, ['name_ar' => 'باقة معتمدة ظاهرة']);

        $results = (new ServiceOfferingRepository())->searchPublic([]);

        $this->assertSame(1, $results['total']);
    }

    /**
     * الجهة غير الموثّقة لا يظهر عرضها | An unverified provider's offer stays hidden.
     *
     * حتى لو كان العنصر نفسه معتمداً: توثيق الجهة شرط مستقل، وسحبه لاحقاً يجب
     * أن يُخفي عروضها فوراً دون الحاجة لمرور أحد عليها واحداً واحداً.
     */
    public function test_offers_of_an_unverified_provider_are_hidden_even_when_approved(): void
    {
        foreach (['draft', 'submitted', 'rejected', 'suspended'] as $status) {
            $bankId = $this->createOrganization(['type_code' => 'bank', 'status' => $status]);
            $this->createFinancingProduct($bankId, ['name_ar' => 'منتج جهة ' . $status]);
        }

        $this->assertSame(0, (new FinancingProductRepository())->searchPublic([])['total']);
    }

    public function test_suspending_a_provider_removes_its_offers_from_the_catalogue(): void
    {
        $bankId = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $this->createFinancingProduct($bankId);

        $repository = new FinancingProductRepository();
        $this->assertSame(1, $repository->searchPublic([])['total']);

        Database::statement("UPDATE organizations SET status = 'suspended' WHERE id = ?", [$bankId]);

        $this->assertSame(0, $repository->searchPublic([])['total']);
    }

    // ═══════════════════ الإرسال للاعتماد | Submission ═══════════════════

    public function test_an_unverified_provider_cannot_submit_for_approval(): void
    {
        $bankId    = $this->createOrganization(['type_code' => 'bank', 'status' => 'submitted']);
        $productId = $this->createFinancingProduct($bankId, [
            'status' => 'draft', 'published_at' => null,
        ]);

        $this->expectException(HttpException::class);

        $this->products->submitForApproval(
            $productId,
            $bankId,
            $this->createUser(),
            $this->request('POST', '/submit'),
        );
    }

    public function test_submission_is_refused_when_required_fields_are_missing(): void
    {
        $bankId    = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $productId = $this->createFinancingProduct($bankId, [
            'status'       => 'draft',
            'published_at' => null,
            // بيان التكلفة غائب: عرض تمويل بلا تكلفة يضلّل صاحب المشروع
            'rate_note_ar' => null,
        ]);

        try {
            $this->products->submitForApproval(
                $productId,
                $bankId,
                $this->createUser(),
                $this->request('POST', '/submit'),
            );
            $this->fail('كان يجب رفض الإرسال بلا بيان تكلفة.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('التكلفة', $e->getMessage());
        }

        $this->assertDatabaseHas('financing_products', ['id' => $productId, 'status' => 'draft']);
    }

    /** الإرسال لا ينشر | Submitting never publishes on its own. */
    public function test_submission_moves_to_pending_review_never_to_published(): void
    {
        $bankId    = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $productId = $this->createFinancingProduct($bankId, [
            'status' => 'draft', 'published_at' => null,
        ]);

        $this->products->submitForApproval(
            $productId,
            $bankId,
            $this->createUser(),
            $this->request('POST', '/submit'),
        );

        $this->assertDatabaseHas('financing_products', [
            'id' => $productId, 'status' => 'pending_review',
        ]);
        $this->assertSame(0, (new FinancingProductRepository())->searchPublic([])['total']);
    }

    public function test_a_free_offering_must_name_its_funder_before_submission(): void
    {
        $providerId = $this->createOrganization(['type_code' => 'service_provider', 'status' => 'verified']);
        $offeringId = $this->createServiceOffering($providerId, [
            'status'       => 'draft',
            'published_at' => null,
            'pricing_mode' => 'free',
            'funded_by_ar' => null,
        ]);

        try {
            $this->offerings->submitForApproval(
                $offeringId,
                $providerId,
                $this->createUser(),
                $this->request('POST', '/submit'),
            );
            $this->fail('كان يجب رفض باقة مجانية بلا جهة ممولة.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('الممولة', $e->getMessage());
        }
    }

    // ═══════════════════ القرار | The decision ═══════════════════

    public function test_approval_records_the_moderator_and_publishes(): void
    {
        $bankId      = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $moderatorId = $this->createUser();
        $productId   = $this->createFinancingProduct($bankId, [
            'status' => 'pending_review', 'published_at' => null,
        ]);

        $this->products->moderate(
            id: $productId,
            decision: 'approve',
            note: null,
            moderatorId: $moderatorId,
            request: $this->request('POST', '/decide'),
        );

        $product = Database::selectOne('SELECT * FROM financing_products WHERE id = ?', [$productId]);

        $this->assertSame('published', $product['status']);
        $this->assertSame($moderatorId, (int) $product['approved_by']);
        $this->assertNotNull($product['approved_at']);
        $this->assertNotNull($product['published_at']);
    }

    public function test_rejection_without_a_reason_is_refused(): void
    {
        $bankId    = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $productId = $this->createFinancingProduct($bankId, [
            'status' => 'pending_review', 'published_at' => null,
        ]);

        $this->expectException(HttpException::class);

        $this->products->moderate(
            id: $productId,
            decision: 'reject',
            note: '   ',
            moderatorId: $this->createUser(),
            request: $this->request('POST', '/decide'),
        );
    }

    /**
     * الرفض يمسح ختم الاعتماد | Rejection clears any approval stamp.
     *
     * بقاء `approved_by` على عنصر مرفوض يجعله يبدو معتمداً لأي استعلام يعتمد
     * على العمود — وهو نفس العيب الذي ظهر في المرحلة الثانية مع `verified_at`.
     */
    public function test_rejecting_a_previously_approved_item_clears_the_approval_stamp(): void
    {
        $bankId      = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $moderatorId = $this->createUser();
        $productId   = $this->createFinancingProduct($bankId, [
            'status'       => 'pending_review',
            'published_at' => null,
            'approved_by'  => $moderatorId,
            'approved_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->products->moderate(
            id: $productId,
            decision: 'reject',
            note: 'الشروط غير واضحة.',
            moderatorId: $moderatorId,
            request: $this->request('POST', '/decide'),
        );

        $product = Database::selectOne('SELECT * FROM financing_products WHERE id = ?', [$productId]);

        $this->assertSame('rejected', $product['status']);
        $this->assertNull($product['approved_by']);
        $this->assertNull($product['approved_at']);
        $this->assertNull($product['published_at']);
    }

    public function test_only_a_pending_item_can_be_decided(): void
    {
        $bankId    = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $productId = $this->createFinancingProduct($bankId, ['status' => 'published']);

        $this->expectException(HttpException::class);

        $this->products->moderate(
            id: $productId,
            decision: 'approve',
            note: null,
            moderatorId: $this->createUser(),
            request: $this->request('POST', '/decide'),
        );
    }

    // ═══════════════════ التعديل بعد الاعتماد | Editing after approval ═══════════════════

    /**
     * تعديل المنشور يُعيده للاعتماد | Editing a published item re-queues it.
     *
     * تغيير الشريحة أو الشروط بعد النشر يُنتج منتجاً مختلفاً لم يراجعه أحد.
     * بقاؤه منشوراً يعني أن الاعتماد صار ختماً على اسم لا على محتوى.
     */
    public function test_editing_a_published_product_returns_it_to_the_queue(): void
    {
        $bankId    = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $productId = $this->createFinancingProduct($bankId, ['status' => 'published']);

        $this->products->update(
            $productId,
            $bankId,
            [
                'name_ar'                => 'اسم معدَّل',
                'min_amount'             => 1000,
                'max_amount'             => 900000,
                'rate_note_ar'           => 'عائد مُعدَّل',
                'eligibility_summary_ar' => 'شروط',
                'required_documents_ar'  => 'مستندات',
            ],
            $this->createUser(),
            $this->request('POST', '/update'),
        );

        $this->assertDatabaseHas('financing_products', [
            'id' => $productId, 'status' => 'pending_review',
        ]);
        $this->assertSame(0, (new FinancingProductRepository())->searchPublic([])['total']);
    }

    public function test_a_product_under_review_cannot_be_edited(): void
    {
        $bankId    = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $productId = $this->createFinancingProduct($bankId, [
            'status' => 'pending_review', 'published_at' => null,
        ]);

        $this->expectException(HttpException::class);

        $this->products->update(
            $productId,
            $bankId,
            ['name_ar' => 'محاولة تعديل'],
            $this->createUser(),
            $this->request('POST', '/update'),
        );
    }

    // ═══════════════════ العزل | Isolation ═══════════════════

    public function test_a_provider_cannot_submit_another_providers_product(): void
    {
        $ownerBank    = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $intruderBank = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);

        $productId = $this->createFinancingProduct($ownerBank, [
            'status' => 'draft', 'published_at' => null,
        ]);

        try {
            $this->products->submitForApproval(
                $productId,
                $intruderBank,
                $this->createUser(),
                $this->request('POST', '/submit'),
            );
            $this->fail('كان يجب رفض إرسال منتج جهة أخرى.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertDatabaseHas('financing_products', ['id' => $productId, 'status' => 'draft']);
    }

    public function test_a_provider_cannot_archive_another_providers_offering(): void
    {
        $owner    = $this->createOrganization(['type_code' => 'service_provider', 'status' => 'verified']);
        $intruder = $this->createOrganization(['type_code' => 'service_provider', 'status' => 'verified']);

        $offeringId = $this->createServiceOffering($owner, ['status' => 'published']);

        try {
            $this->offerings->archive(
                $offeringId,
                $intruder,
                $this->createUser(),
                $this->request('POST', '/archive'),
            );
            $this->fail('كان يجب رفض سحب باقة جهة أخرى.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertDatabaseHas('service_offerings', ['id' => $offeringId, 'status' => 'published']);
    }

    public function test_provider_listing_never_includes_another_providers_items(): void
    {
        $first  = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $second = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);

        $this->createFinancingProduct($first);
        $this->createFinancingProduct($first, ['status' => 'draft', 'published_at' => null]);
        $this->createFinancingProduct($second);

        $repository = new FinancingProductRepository();

        $this->assertCount(2, $repository->forOrganization($first));
        $this->assertCount(1, $repository->forOrganization($second));
        $this->assertSame(2, array_sum($repository->countsByStatus($first)));
    }
}
