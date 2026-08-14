<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\HttpException;
use App\Services\FinancingApplicationService;
use Tests\TestCase;

/**
 * طلبات التمويل | Financing applications (§4.5).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * أهم اختبار في هذه المجموعة — وربما في المرحلة كلها — هو أن **المنصة لا
 * تستطيع قبول طلب تمويل ولا رفضه**. عرض طلب كمقبول دون قرار المموّل يعد صاحب
 * مشروع بتمويل لم يوافق عليه أحد، وهو أخطر ما يمكن أن تفعله منصة وساطة.
 *
 * The single most important test here: the platform cannot approve or reject.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class FinancingApplicationTest extends TestCase
{
    private FinancingApplicationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FinancingApplicationService();
    }

    // ═══════════════════ التقديم | Submission ═══════════════════

    public function test_a_verified_sme_can_apply_to_an_approved_product(): void
    {
        [$smeId, $bankId, $productId] = $this->scenario();

        $result = $this->apply($smeId, $productId);

        $this->assertStringStartsWith('FIN-', $result['number']);
        $this->assertSame(48, strlen($result['token']));
        $this->assertDatabaseHas('financing_applications', [
            'id'                       => $result['id'],
            'status'                   => 'submitted',
            'organization_id'          => $smeId,
            'provider_organization_id' => $bankId,
        ]);
        $this->assertDatabaseHas('financing_application_history', [
            'application_id' => $result['id'], 'to_status' => 'submitted', 'actor_type' => 'applicant',
        ]);
    }

    public function test_an_unverified_sme_cannot_apply(): void
    {
        $smeId              = $this->createOrganization(['status' => 'submitted']);
        $bankId             = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $productId          = $this->createFinancingProduct($bankId);

        $this->expectException(HttpException::class);
        $this->apply($smeId, $productId);
    }

    public function test_applying_to_an_unapproved_product_is_refused(): void
    {
        $smeId  = $this->createOrganization(['status' => 'verified']);
        $bankId = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);

        foreach (['draft', 'pending_review', 'rejected', 'archived'] as $status) {
            $productId = $this->createFinancingProduct($bankId, [
                'status' => $status, 'published_at' => null,
            ]);

            try {
                $this->apply($smeId, $productId);
                $this->fail("كان يجب رفض التقديم على منتج حالته: {$status}");
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
    }

    public function test_an_amount_outside_the_product_band_is_refused(): void
    {
        [$smeId, , $productId] = $this->scenario();

        foreach ([500, 9_000_000] as $amount) {
            try {
                $this->apply($smeId, $productId, ['requested_amount' => $amount]);
                $this->fail('كان يجب رفض مبلغ خارج شريحة المنتج.');
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
    }

    public function test_a_vague_purpose_is_refused(): void
    {
        [$smeId, , $productId] = $this->scenario();

        $this->expectException(HttpException::class);
        $this->apply($smeId, $productId, ['purpose_ar' => 'تمويل']);
    }

    // ═══════════════════ القاعدة الحاكمة | The governing rule ═══════════════════

    /**
     * المنصة لا تقبل | The platform cannot approve.
     *
     * الرفض هنا استثناء تفويض لا خطأ تحقّق: هذه ليست بيانات ناقصة بل تجاوز
     * صلاحية يستحقّ التسجيل.
     */
    public function test_the_platform_cannot_approve_an_application(): void
    {
        [$smeId, , $productId] = $this->scenario();
        $applicationId = $this->apply($smeId, $productId)['id'];

        $this->forwardToProvider($applicationId);
        $this->startProviderReview($applicationId);

        try {
            $this->service->transition(
                applicationId: $applicationId,
                action: 'approve',
                actorType: 'platform',
                actorUserId: $this->createUser(),
                actorOrganizationId: null,
                request: $this->request('POST', '/action'),
                note: 'موافقة من المنصة',
            );
            $this->fail('كان يجب رفض تسجيل موافقة من المنصة.');
        } catch (AuthorizationException) {
            // متوقّع
        }

        $application = Database::selectOne(
            'SELECT status, decided_by FROM financing_applications WHERE id = ?',
            [$applicationId],
        );

        $this->assertSame('provider_review', $application['status']);
        $this->assertNull($application['decided_by']);
    }

    public function test_the_platform_cannot_reject_an_application(): void
    {
        [$smeId, , $productId] = $this->scenario();
        $applicationId = $this->apply($smeId, $productId)['id'];

        $this->forwardToProvider($applicationId);
        $this->startProviderReview($applicationId);

        $this->expectException(AuthorizationException::class);

        $this->service->transition(
            applicationId: $applicationId,
            action: 'reject',
            actorType: 'platform',
            actorUserId: $this->createUser(),
            actorOrganizationId: null,
            request: $this->request('POST', '/action'),
            note: 'رفض من المنصة',
        );
    }

    public function test_the_applicant_cannot_approve_their_own_application(): void
    {
        [$smeId, , $productId] = $this->scenario();
        $applicationId = $this->apply($smeId, $productId)['id'];

        $this->forwardToProvider($applicationId);
        $this->startProviderReview($applicationId);

        $this->expectException(AuthorizationException::class);

        $this->service->transition(
            applicationId: $applicationId,
            action: 'approve',
            actorType: 'applicant',
            actorUserId: $this->createUser(),
            actorOrganizationId: $smeId,
            request: $this->request('POST', '/action'),
        );
    }

    /** الموافقة تكتب فاعلها دائماً | Approval always records who decided. */
    public function test_provider_approval_records_the_decider_and_the_amount(): void
    {
        [$smeId, $bankId, $productId] = $this->scenario();
        $applicationId = $this->apply($smeId, $productId)['id'];
        $officerId     = $this->createUser();

        $this->forwardToProvider($applicationId);
        $this->startProviderReview($applicationId, $bankId);

        $this->service->transition(
            applicationId: $applicationId,
            action: 'approve',
            actorType: 'provider',
            actorUserId: $officerId,
            actorOrganizationId: $bankId,
            request: $this->request('POST', '/action'),
            note: 'موافقة بمبلغ مخفّض.',
            decision: ['approved_amount' => 70000, 'approved_tenor_months' => 24],
        );

        $application = Database::selectOne(
            'SELECT * FROM financing_applications WHERE id = ?',
            [$applicationId],
        );

        $this->assertSame('approved', $application['status']);
        $this->assertSame($officerId, (int) $application['decided_by']);
        $this->assertNotNull($application['decided_at']);
        $this->assertSame('70000.00', $application['approved_amount']);
    }

    /**
     * لا حالة «مقبول» بلا فاعل | No approved row without a decider.
     *
     * القيد بنيوي لا اتفاقي: جملة التحديث الوحيدة التي تكتب `approved` تكتب
     * `decided_by` في نفس العبارة.
     */
    public function test_no_approved_application_exists_without_a_recorded_decider(): void
    {
        [$smeId, $bankId, $productId] = $this->scenario();
        $applicationId = $this->apply($smeId, $productId)['id'];

        $this->forwardToProvider($applicationId);
        $this->startProviderReview($applicationId, $bankId);

        $this->service->transition(
            applicationId: $applicationId,
            action: 'approve',
            actorType: 'provider',
            actorUserId: $this->createUser(),
            actorOrganizationId: $bankId,
            request: $this->request('POST', '/action'),
        );

        $orphans = (int) Database::scalar(
            "SELECT COUNT(*) FROM financing_applications
              WHERE status = 'approved' AND decided_by IS NULL",
        );

        $this->assertSame(0, $orphans, 'لا يجوز وجود طلب مقبول بلا فاعل مسجَّل.');
    }

    public function test_rejection_without_a_reason_is_refused(): void
    {
        [$smeId, $bankId, $productId] = $this->scenario();
        $applicationId = $this->apply($smeId, $productId)['id'];

        $this->forwardToProvider($applicationId);
        $this->startProviderReview($applicationId, $bankId);

        $this->expectException(HttpException::class);

        $this->service->transition(
            applicationId: $applicationId,
            action: 'reject',
            actorType: 'provider',
            actorUserId: $this->createUser(),
            actorOrganizationId: $bankId,
            request: $this->request('POST', '/action'),
            note: '  ',
        );
    }

    // ═══════════════════ آلة الحالة | The state machine ═══════════════════

    public function test_the_transition_map_matches_the_documented_flow(): void
    {
        $this->assertTrue($this->service->can('submitted', 'forward'));
        $this->assertTrue($this->service->can('forwarded', 'start_review'));
        $this->assertTrue($this->service->can('provider_review', 'approve'));
        $this->assertTrue($this->service->can('provider_review', 'request_info'));
        $this->assertTrue($this->service->can('info_requested', 'resubmit'));

        $this->assertFalse($this->service->can('submitted', 'approve'));
        $this->assertFalse($this->service->can('forwarded', 'approve'));
        $this->assertSame([], $this->service->availableActions('approved'));
        $this->assertSame([], $this->service->availableActions('rejected'));
    }

    public function test_actions_offered_to_the_platform_never_include_a_decision(): void
    {
        foreach (['submitted', 'screening', 'forwarded', 'provider_review', 'info_requested'] as $status) {
            $actions = $this->service->actionsFor($status, 'platform');

            $this->assertNotContains('approve', $actions, "«اعتماد» ظهر للمنصة في الحالة {$status}.");
            $this->assertNotContains('reject', $actions, "«رفض» ظهر للمنصة في الحالة {$status}.");
        }
    }

    public function test_actions_offered_to_the_applicant_never_include_a_decision(): void
    {
        foreach (['submitted', 'forwarded', 'provider_review', 'info_requested'] as $status) {
            $actions = $this->service->actionsFor($status, 'applicant');

            $this->assertNotContains('approve', $actions);
            $this->assertNotContains('reject', $actions);
            $this->assertNotContains('forward', $actions);
        }
    }

    // ═══════════════════ العزل والخصوصية | Isolation and privacy ═══════════════════

    public function test_another_provider_cannot_reach_the_application(): void
    {
        [$smeId, , $productId] = $this->scenario();
        $applicationId = $this->apply($smeId, $productId)['id'];
        $intruderBank  = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);

        try {
            $this->service->findForProvider($applicationId, $intruderBank);
            $this->fail('كان يجب حجب الطلب عن مؤسسة أخرى.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_another_sme_cannot_reach_the_application(): void
    {
        [$smeId, , $productId] = $this->scenario();
        $applicationId = $this->apply($smeId, $productId)['id'];
        $intruderSme   = $this->createOrganization(['status' => 'verified']);

        $this->expectException(HttpException::class);
        $this->service->findForApplicant($applicationId, $intruderSme);
    }

    public function test_a_stranger_organization_cannot_transition_the_application(): void
    {
        [$smeId, , $productId] = $this->scenario();
        $applicationId = $this->apply($smeId, $productId)['id'];
        $intruder      = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);

        try {
            $this->service->transition(
                applicationId: $applicationId,
                action: 'start_review',
                actorType: 'provider',
                actorUserId: $this->createUser(),
                actorOrganizationId: $intruder,
                request: $this->request('POST', '/action'),
            );
            $this->fail('كان يجب رفض تحريك طلب لا يخصّ الجهة.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertDatabaseHas('financing_applications', [
            'id' => $applicationId, 'status' => 'submitted',
        ]);
    }

    /** الملاحظة الداخلية لا تصل للمشروع | Internal notes never reach the applicant. */
    public function test_internal_notes_are_stripped_from_the_applicant_history(): void
    {
        [$smeId, , $productId] = $this->scenario();
        $applicationId = $this->apply($smeId, $productId)['id'];

        $this->service->transition(
            applicationId: $applicationId,
            action: 'forward',
            actorType: 'platform',
            actorUserId: $this->createUser(),
            actorOrganizationId: null,
            request: $this->request('POST', '/action'),
            note: 'أُحيل للمؤسسة.',
            internalNote: 'تقييم داخلي حسّاس لا يراه المشروع.',
        );

        $applicantHistory = $this->service->history($applicationId, false);
        $providerHistory  = $this->service->history($applicationId, true);

        $applicantJson = json_encode($applicantHistory, JSON_UNESCAPED_UNICODE);
        $providerJson  = json_encode($providerHistory, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('تقييم داخلي حسّاس', (string) $applicantJson);
        $this->assertStringContainsString('تقييم داخلي حسّاس', (string) $providerJson);
        // الملاحظة الموجّهة للمشروع تصله كاملة
        $this->assertStringContainsString('أُحيل للمؤسسة', (string) $applicantJson);
    }

    /** ملاحظة المشروع الداخلية لا تُخزَّن أصلاً | An applicant cannot write internal notes. */
    public function test_an_applicant_cannot_write_an_internal_note(): void
    {
        [$smeId, , $productId] = $this->scenario();
        $applicationId = $this->apply($smeId, $productId)['id'];

        $this->service->transition(
            applicationId: $applicationId,
            action: 'withdraw',
            actorType: 'applicant',
            actorUserId: $this->createUser(),
            actorOrganizationId: $smeId,
            request: $this->request('POST', '/action'),
            internalNote: 'محاولة كتابة ملاحظة داخلية',
        );

        $this->assertSame(
            0,
            $this->countRows(
                'financing_application_history',
                'application_id = ? AND internal_note_ar IS NOT NULL',
                [$applicationId],
            ),
        );
    }

    // ═══════════════════ المستندات | Documents ═══════════════════

    public function test_a_provider_can_request_a_document_and_the_applicant_attaches_it(): void
    {
        [$smeId, $bankId, $productId] = $this->scenario();
        $applicationId = $this->apply($smeId, $productId)['id'];

        $documentId = $this->service->requestDocument(
            applicationId: $applicationId,
            providerOrganizationId: $bankId,
            label: 'كشف حساب بنكي',
            note: 'لآخر ستة أشهر.',
            required: true,
            actorId: $this->createUser(),
        );

        $this->assertDatabaseHas('financing_application_documents', [
            'id' => $documentId, 'organization_id' => $smeId, 'provider_organization_id' => $bankId,
        ]);

        $mediaId = $this->createMedia($smeId);

        $this->service->attachDocument($documentId, $smeId, $mediaId, $this->createUser());

        $this->assertDatabaseHas('financing_application_documents', [
            'id' => $documentId, 'media_id' => $mediaId,
        ]);
    }

    public function test_another_organization_cannot_attach_a_document_to_the_request(): void
    {
        [$smeId, $bankId, $productId] = $this->scenario();
        $applicationId = $this->apply($smeId, $productId)['id'];

        $documentId = $this->service->requestDocument(
            applicationId: $applicationId,
            providerOrganizationId: $bankId,
            label: 'مستند',
            note: null,
            required: true,
            actorId: $this->createUser(),
        );

        $intruder = $this->createOrganization(['status' => 'verified']);

        $this->expectException(HttpException::class);
        $this->service->attachDocument($documentId, $intruder, $this->createMedia($intruder), null);
    }

    public function test_a_provider_cannot_request_a_document_on_another_providers_application(): void
    {
        [$smeId, , $productId] = $this->scenario();
        $applicationId = $this->apply($smeId, $productId)['id'];
        $intruderBank  = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);

        $this->expectException(HttpException::class);

        $this->service->requestDocument(
            applicationId: $applicationId,
            providerOrganizationId: $intruderBank,
            label: 'مستند',
            note: null,
            required: true,
            actorId: $this->createUser(),
        );
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /** @return array{0:int,1:int,2:int} [smeId, bankId, productId] */
    private function scenario(): array
    {
        $smeId     = $this->createOrganization(['status' => 'verified']);
        $bankId    = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $productId = $this->createFinancingProduct($bankId);

        return [$smeId, $bankId, $productId];
    }

    /**
     * @param  array<string,mixed> $overrides
     * @return array{id:int,number:string,token:string}
     */
    private function apply(int $smeId, int $productId, array $overrides = []): array
    {
        return $this->service->submit(
            organizationId: $smeId,
            productId: $productId,
            data: array_merge([
                'requested_amount' => 80000,
                'purpose_ar'       => 'شراء خامات لدورة إنتاج جديدة وتوسيع خط التعبئة.',
            ], $overrides),
            actorId: $this->createUser(),
            request: $this->request('POST', '/apply'),
        );
    }

    private function forwardToProvider(int $applicationId): void
    {
        $this->service->transition(
            applicationId: $applicationId,
            action: 'forward',
            actorType: 'platform',
            actorUserId: $this->createUser(),
            actorOrganizationId: null,
            request: $this->request('POST', '/action'),
        );
    }

    private function startProviderReview(int $applicationId, ?int $bankId = null): void
    {
        $bankId ??= (int) Database::scalar(
            'SELECT provider_organization_id FROM financing_applications WHERE id = ?',
            [$applicationId],
        );

        $this->service->transition(
            applicationId: $applicationId,
            action: 'start_review',
            actorType: 'provider',
            actorUserId: $this->createUser(),
            actorOrganizationId: $bankId,
            request: $this->request('POST', '/action'),
        );
    }

    private function createMedia(int $organizationId): int
    {
        return Database::insert(
            'INSERT INTO media
                (organization_id, original_name, disk_path, mime_type,
                 extension, size_bytes, checksum, visibility, collection)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $organizationId,
                'statement.pdf',
                'financing/' . bin2hex(random_bytes(8)) . '.pdf',
                'application/pdf',
                'pdf',
                1024,
                hash('sha256', bin2hex(random_bytes(8))),
                'private',
                'financing',
            ],
        );
    }
}
