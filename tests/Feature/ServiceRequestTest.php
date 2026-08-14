<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\HttpException;
use App\Services\ServiceRequestService;
use Tests\TestCase;

/**
 * طلبات الخدمات غير المالية | Non-financial service requests (§4.6).
 *
 * القاعدة المحفوظة هنا: **كل طرف يملك قراره ولا يملك قرار الآخر.** المزوّد
 * يقدّم العرض ولا يقبله، والمشروع يقبل ولا يقدّم، والتسليم يسجّله المنفّذ
 * بينما الاستلام يؤكّده المستفيد.
 */
final class ServiceRequestTest extends TestCase
{
    private ServiceRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ServiceRequestService();
    }

    // ═══════════════════ التقديم | Submission ═══════════════════

    public function test_a_request_can_be_submitted_against_an_approved_offering(): void
    {
        [$smeId, $providerId, $offeringId] = $this->scenario();

        $result = $this->submit($smeId, $offeringId);

        $this->assertStringStartsWith('SRV-', $result['number']);
        $this->assertSame(48, strlen($result['token']));
        $this->assertDatabaseHas('service_requests', [
            'id'                       => $result['id'],
            'status'                   => 'submitted',
            'organization_id'          => $smeId,
            'provider_organization_id' => $providerId,
        ]);
    }

    public function test_an_unapproved_offering_cannot_receive_requests(): void
    {
        $smeId      = $this->createOrganization(['status' => 'verified']);
        $providerId = $this->createOrganization(['type_code' => 'service_provider', 'status' => 'verified']);

        foreach (['draft', 'pending_review', 'rejected', 'archived'] as $status) {
            $offeringId = $this->createServiceOffering($providerId, [
                'status' => $status, 'published_at' => null,
            ]);

            try {
                $this->submit($smeId, $offeringId);
                $this->fail("كان يجب رفض الطلب على باقة حالتها: {$status}");
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
    }

    public function test_an_offering_of_an_unverified_provider_cannot_receive_requests(): void
    {
        $smeId      = $this->createOrganization(['status' => 'verified']);
        $providerId = $this->createOrganization(['type_code' => 'service_provider', 'status' => 'submitted']);
        $offeringId = $this->createServiceOffering($providerId);

        $this->expectException(HttpException::class);
        $this->submit($smeId, $offeringId);
    }

    public function test_a_too_short_description_is_refused(): void
    {
        [$smeId, , $offeringId] = $this->scenario();

        $this->expectException(HttpException::class);
        $this->submit($smeId, $offeringId, ['details_ar' => 'محتاج']);
    }

    // ═══════════════════ من يملك أي قرار | Who owns which decision ═══════════════════

    public function test_the_provider_cannot_accept_its_own_proposal(): void
    {
        [$smeId, $providerId, $offeringId] = $this->scenario();
        $requestId = $this->submit($smeId, $offeringId)['id'];

        $this->providerAction($requestId, $providerId, 'start_review');
        $this->propose($requestId, $providerId);

        $this->expectException(AuthorizationException::class);

        $this->service->transition(
            requestId: $requestId,
            action: 'accept',
            actorType: 'provider',
            actorUserId: $this->createUser(),
            actorOrganizationId: $providerId,
            request: $this->request('POST', '/action'),
        );
    }

    public function test_the_applicant_cannot_submit_a_proposal(): void
    {
        [$smeId, $providerId, $offeringId] = $this->scenario();
        $requestId = $this->submit($smeId, $offeringId)['id'];

        $this->providerAction($requestId, $providerId, 'start_review');

        $this->expectException(AuthorizationException::class);

        $this->service->transition(
            requestId: $requestId,
            action: 'propose',
            actorType: 'applicant',
            actorUserId: $this->createUser(),
            actorOrganizationId: $smeId,
            request: $this->request('POST', '/action'),
            proposal: ['proposed_price' => 1, 'proposal_note_ar' => 'محاولة'],
        );
    }

    public function test_the_provider_cannot_confirm_receipt_on_the_applicants_behalf(): void
    {
        [$smeId, $providerId, $offeringId] = $this->scenario();
        $requestId = $this->submit($smeId, $offeringId)['id'];

        $this->driveToDelivered($requestId, $smeId, $providerId);

        $this->expectException(AuthorizationException::class);

        $this->service->transition(
            requestId: $requestId,
            action: 'complete',
            actorType: 'provider',
            actorUserId: $this->createUser(),
            actorOrganizationId: $providerId,
            request: $this->request('POST', '/action'),
        );
    }

    public function test_actions_are_split_correctly_between_the_two_sides(): void
    {
        $this->assertSame(['start_review'], $this->service->actionsFor('submitted', 'provider'));
        $this->assertSame(['cancel'], $this->service->actionsFor('submitted', 'applicant'));

        $this->assertSame(['propose', 'decline'], $this->service->actionsFor('provider_review', 'provider'));
        $this->assertSame(
            ['accept', 'reject_proposal', 'cancel'],
            $this->service->actionsFor('proposed', 'applicant'),
        );
        $this->assertSame([], $this->service->actionsFor('proposed', 'provider'));

        $this->assertSame(['complete'], $this->service->actionsFor('delivered', 'applicant'));
        $this->assertSame([], $this->service->actionsFor('delivered', 'provider'));
    }

    // ═══════════════════ المسار الكامل | The full happy path ═══════════════════

    public function test_the_full_flow_from_request_to_completion(): void
    {
        [$smeId, $providerId, $offeringId] = $this->scenario();
        $requestId = $this->submit($smeId, $offeringId)['id'];

        $this->providerAction($requestId, $providerId, 'start_review');
        $this->assertStatus($requestId, 'provider_review');

        $this->propose($requestId, $providerId);
        $this->assertStatus($requestId, 'proposed');

        $this->applicantAction($requestId, $smeId, 'accept');
        $this->assertStatus($requestId, 'accepted');

        $this->providerAction($requestId, $providerId, 'start_work');
        $this->assertStatus($requestId, 'in_progress');

        $this->providerAction($requestId, $providerId, 'deliver');
        $this->assertStatus($requestId, 'delivered');

        $this->applicantAction($requestId, $smeId, 'complete');
        $this->assertStatus($requestId, 'completed');

        $this->assertNotNull(
            Database::scalar('SELECT completed_at FROM service_requests WHERE id = ?', [$requestId]),
        );
        // سبعة قيود: الإنشاء + ستة انتقالات
        $this->assertSame(7, $this->countRows('service_request_history', 'service_request_id = ?', [$requestId]));
    }

    public function test_a_proposal_without_a_scope_is_refused(): void
    {
        [$smeId, $providerId, $offeringId] = $this->scenario();
        $requestId = $this->submit($smeId, $offeringId)['id'];
        $this->providerAction($requestId, $providerId, 'start_review');

        $this->expectException(HttpException::class);

        $this->service->transition(
            requestId: $requestId,
            action: 'propose',
            actorType: 'provider',
            actorUserId: $this->createUser(),
            actorOrganizationId: $providerId,
            request: $this->request('POST', '/action'),
            proposal: ['proposed_price' => 5000, 'proposal_note_ar' => '   '],
        );
    }

    /** العرض المجاني مقبول | A zero-price proposal is legitimate. */
    public function test_a_zero_price_proposal_is_accepted_as_a_free_service(): void
    {
        [$smeId, $providerId, $offeringId] = $this->scenario();
        $requestId = $this->submit($smeId, $offeringId)['id'];
        $this->providerAction($requestId, $providerId, 'start_review');

        $this->propose($requestId, $providerId, ['proposed_price' => 0]);

        $this->assertDatabaseHas('service_requests', ['id' => $requestId, 'status' => 'proposed']);
        $this->assertSame(
            '0.00',
            Database::scalar('SELECT proposed_price FROM service_requests WHERE id = ?', [$requestId]),
        );
    }

    public function test_declining_without_a_reason_is_refused(): void
    {
        [$smeId, $providerId, $offeringId] = $this->scenario();
        $requestId = $this->submit($smeId, $offeringId)['id'];
        $this->providerAction($requestId, $providerId, 'start_review');

        $this->expectException(HttpException::class);

        $this->service->transition(
            requestId: $requestId,
            action: 'decline',
            actorType: 'provider',
            actorUserId: $this->createUser(),
            actorOrganizationId: $providerId,
            request: $this->request('POST', '/action'),
            note: '',
        );
    }

    // ═══════════════════ المراحل | Milestones ═══════════════════

    public function test_milestones_are_scoped_to_the_owning_provider(): void
    {
        [$smeId, $providerId, $offeringId] = $this->scenario();
        $requestId = $this->submit($smeId, $offeringId)['id'];

        $milestoneId = $this->service->addMilestone(
            $requestId,
            $providerId,
            'جمع البيانات',
            'زيارة ميدانية',
            null,
        );

        $this->assertDatabaseHas('service_milestones', [
            'id' => $milestoneId, 'organization_id' => $smeId, 'provider_organization_id' => $providerId,
        ]);

        $intruder = $this->createOrganization(['type_code' => 'service_provider', 'status' => 'verified']);

        $this->expectException(HttpException::class);
        $this->service->updateMilestone($milestoneId, $intruder, 'done');
    }

    public function test_another_provider_cannot_add_a_milestone(): void
    {
        [$smeId, , $offeringId] = $this->scenario();
        $requestId = $this->submit($smeId, $offeringId)['id'];
        $intruder  = $this->createOrganization(['type_code' => 'service_provider', 'status' => 'verified']);

        $this->expectException(HttpException::class);
        $this->service->addMilestone($requestId, $intruder, 'مرحلة مدسوسة', null, null);
    }

    // ═══════════════════ العزل | Isolation ═══════════════════

    public function test_each_side_only_lists_its_own_requests(): void
    {
        [$smeA, $providerA, $offeringA] = $this->scenario();
        [$smeB, $providerB, $offeringB] = $this->scenario();

        $this->submit($smeA, $offeringA);
        $this->submit($smeA, $offeringA);
        $this->submit($smeB, $offeringB);

        $this->assertCount(2, $this->service->listFor('applicant', $smeA));
        $this->assertCount(1, $this->service->listFor('applicant', $smeB));
        $this->assertCount(2, $this->service->listFor('provider', $providerA));
        $this->assertCount(1, $this->service->listFor('provider', $providerB));
    }

    public function test_a_stranger_cannot_reach_the_request(): void
    {
        [$smeId, , $offeringId] = $this->scenario();
        $requestId = $this->submit($smeId, $offeringId)['id'];
        $intruder  = $this->createOrganization(['status' => 'verified']);

        try {
            $this->service->findForApplicant($requestId, $intruder);
            $this->fail('كان يجب حجب الطلب عن منشأة أخرى.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        try {
            $this->service->findForProvider($requestId, $intruder);
            $this->fail('كان يجب حجب الطلب عن مزوّد آخر.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /** @return array{0:int,1:int,2:int} */
    private function scenario(): array
    {
        $smeId      = $this->createOrganization(['status' => 'verified']);
        $providerId = $this->createOrganization(['type_code' => 'service_provider', 'status' => 'verified']);
        $offeringId = $this->createServiceOffering($providerId);

        return [$smeId, $providerId, $offeringId];
    }

    /**
     * @param  array<string,mixed> $overrides
     * @return array{id:int,number:string,token:string}
     */
    private function submit(int $smeId, int $offeringId, array $overrides = []): array
    {
        return $this->service->submit(
            organizationId: $smeId,
            offeringId: $offeringId,
            data: array_merge([
                'details_ar' => 'نحتاج دراسة جدوى لتوسعة خط الإنتاج خلال الربع القادم.',
            ], $overrides),
            actorId: $this->createUser(),
            request: $this->request('POST', '/request'),
        );
    }

    private function providerAction(int $requestId, int $providerId, string $action, ?string $note = null): void
    {
        $this->service->transition(
            requestId: $requestId,
            action: $action,
            actorType: 'provider',
            actorUserId: $this->createUser(),
            actorOrganizationId: $providerId,
            request: $this->request('POST', '/action'),
            note: $note,
        );
    }

    private function applicantAction(int $requestId, int $smeId, string $action, ?string $note = null): void
    {
        $this->service->transition(
            requestId: $requestId,
            action: $action,
            actorType: 'applicant',
            actorUserId: $this->createUser(),
            actorOrganizationId: $smeId,
            request: $this->request('POST', '/action'),
            note: $note,
        );
    }

    /** @param array<string,mixed> $overrides */
    private function propose(int $requestId, int $providerId, array $overrides = []): void
    {
        $this->service->transition(
            requestId: $requestId,
            action: 'propose',
            actorType: 'provider',
            actorUserId: $this->createUser(),
            actorOrganizationId: $providerId,
            request: $this->request('POST', '/action'),
            proposal: array_merge([
                'proposed_price'         => 15000,
                'proposed_duration_days' => 21,
                'proposal_note_ar'       => 'دراسة جدوى كاملة تشمل تحليل السوق ونموذجاً مالياً.',
            ], $overrides),
        );
    }

    private function driveToDelivered(int $requestId, int $smeId, int $providerId): void
    {
        $this->providerAction($requestId, $providerId, 'start_review');
        $this->propose($requestId, $providerId);
        $this->applicantAction($requestId, $smeId, 'accept');
        $this->providerAction($requestId, $providerId, 'start_work');
        $this->providerAction($requestId, $providerId, 'deliver');
    }

    private function assertStatus(int $requestId, string $expected): void
    {
        $this->assertSame(
            $expected,
            Database::scalar('SELECT status FROM service_requests WHERE id = ?', [$requestId]),
        );
    }
}
