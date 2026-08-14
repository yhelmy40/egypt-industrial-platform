<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\HttpException;
use App\Repositories\BdsCaseRepository;
use App\Services\BdsCaseService;
use Tests\TestCase;

/**
 * مسار حالة الدعم | BDS case workflow (§4.8).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * تُثبت هذه المجموعة القواعد الثلاث التي تحكم ملف الدعم:
 *
 *  1. **إدارة الحالة بيد المركز.** المشروع صاحب الطلب لا صاحب القرار: لا يُسنِد
 *     الحالة لأخصائي ولا يُغلقها ولا يُعيد فتحها.
 *  2. **الإحالة توصية موثّقة لا التزام.** لا تُنشئ طلباً ولا تُلزم الجهة
 *     المُحال إليها، ولا تُوجَّه إلا إلى عرض معتمد من جهة موثّقة.
 *  3. **الإغلاق يستوجب نتيجة مكتوبة.** حالة تُغلق بلا ملخّص تترك المشروع بلا
 *     أثر لما جرى.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class BdsCaseWorkflowTest extends TestCase
{
    private BdsCaseRepository $cases;

    private BdsCaseService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cases   = new BdsCaseRepository();
        $this->service = new BdsCaseService();
    }

    // ═══════════════════ الطلب | Requesting ═══════════════════

    /** الطلب يُنشأ بحالة «مُقدَّم» ورقم مرجعي | A request starts as `requested` with a reference. */
    public function test_a_request_starts_as_requested_with_a_reference_number(): void
    {
        [$smeId, $centerId, $caseId] = $this->scenario();

        $case = $this->cases->findFor(BdsCaseRepository::SIDE_ORGANIZATION, $caseId, $smeId);

        $this->assertSame('requested', $case['status']);
        $this->assertNotSame('', (string) $case['case_number']);
        $this->assertSame($centerId, (int) $case['center_organization_id']);
        $this->assertNull($case['assigned_to']);
    }

    /** لا يُطلب الدعم إلا من مركز فعلي | Support can only be requested from an actual centre. */
    public function test_support_cannot_be_requested_from_a_non_center(): void
    {
        $smeId    = $this->createOrganization(['status' => 'verified']);
        $notACenter = $this->createOrganization(['status' => 'verified']);

        $this->expectException(HttpException::class);

        $this->service->request(
            organizationId: $smeId,
            centerOrganizationId: $notACenter,
            data: [
                'title_ar'           => 'طلب إلى جهة ليست مركزاً',
                'request_details_ar' => 'تفاصيل كافية لوصف الاحتياج المطلوب من الجهة.',
            ],
            actorId: $this->createUser(),
            request: $this->request('POST', '/request'),
        );
    }

    // ═══════════════════ آلة الحالات | The state machine ═══════════════════

    /** المسار الكامل من الطلب إلى الإغلاق | The full path from request to closure. */
    public function test_the_full_lifecycle_reaches_a_documented_closure(): void
    {
        [, $centerId, $caseId] = $this->scenario();
        $specialist = $this->specialistOf($centerId);

        $this->center($caseId, 'start_triage', $centerId, $specialist);
        $this->assertStatus($caseId, 'triage');

        $this->center($caseId, 'assign', $centerId, $specialist, extra: ['assigned_to' => $specialist]);
        $this->assertStatus($caseId, 'assigned');

        $this->center($caseId, 'start_work', $centerId, $specialist);
        $this->assertStatus($caseId, 'in_progress');

        $this->center($caseId, 'hold', $centerId, $specialist, note: 'بانتظار مستندات من المشروع.');
        $this->assertStatus($caseId, 'on_hold');

        $this->center($caseId, 'resume', $centerId, $specialist);
        $this->assertStatus($caseId, 'in_progress');

        $this->center($caseId, 'close_completed', $centerId, $specialist, note: 'أُنجزت خطة تنظيم الحسابات.');

        $case = $this->cases->findFor(BdsCaseRepository::SIDE_CENTER, $caseId, $centerId);

        $this->assertSame('closed_completed', $case['status']);
        $this->assertNotNull($case['closed_at']);
        $this->assertStringContainsString('خطة تنظيم الحسابات', (string) $case['outcome_summary_ar']);

        // كل انتقال مسجَّل: قيد الإنشاء + ستة انتقالات
        // Every transition is recorded: the creation entry plus six transitions.
        $history = $this->cases->history($caseId);

        $this->assertCount(7, $history);
        $this->assertNull($history[0]['from_status']);
        $this->assertSame('requested', $history[0]['to_status']);
    }

    /** الانتقال غير المسموح مرفوض | An illegal transition is refused. */
    public function test_an_illegal_transition_is_refused(): void
    {
        [, $centerId, $caseId] = $this->scenario();
        $specialist = $this->specialistOf($centerId);

        // «بدء العمل» لا يصلح على حالة لم تُسنَد بعد
        try {
            $this->center($caseId, 'start_work', $centerId, $specialist);
            $this->fail('كان يجب رفض بدء العمل قبل الإسناد.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertStatus($caseId, 'requested');
    }

    /** الحالة الملغاة نهائية | A cancelled case is terminal. */
    public function test_a_cancelled_case_accepts_nothing_further(): void
    {
        [$smeId, $centerId, $caseId] = $this->scenario();

        $this->service->transition(
            caseId: $caseId,
            action: 'cancel',
            side: BdsCaseRepository::SIDE_ORGANIZATION,
            actorUserId: $this->createUser(),
            actorOrganizationId: $smeId,
            request: $this->request('POST', '/cancel'),
            note: 'عالجنا الأمر داخلياً.',
        );

        $this->assertStatus($caseId, 'cancelled');
        $this->assertSame([], $this->service->availableActions('cancelled'));

        $this->expectException(HttpException::class);
        $this->center($caseId, 'start_triage', $centerId, $this->specialistOf($centerId));
    }

    /** إعادة الفتح تمحو ختم الإغلاق | Reopening clears the closure stamp. */
    public function test_reopening_clears_the_closure_stamp(): void
    {
        [, $centerId, $caseId] = $this->scenario();
        $specialist = $this->specialistOf($centerId);

        $this->center($caseId, 'start_triage', $centerId, $specialist);
        $this->center($caseId, 'assign', $centerId, $specialist, extra: ['assigned_to' => $specialist]);
        $this->center($caseId, 'start_work', $centerId, $specialist);
        $this->center($caseId, 'close_completed', $centerId, $specialist, note: 'أُنجز المطلوب.');

        $this->center($caseId, 'reopen', $centerId, $specialist);

        $case = $this->cases->findFor(BdsCaseRepository::SIDE_CENTER, $caseId, $centerId);

        $this->assertSame('in_progress', $case['status']);
        $this->assertNull($case['closed_at']);
        $this->assertNull($case['closed_by']);
    }

    // ═══════════════════ اختصاص المركز | Centre-only authority ═══════════════════

    /** المشروع لا يُسنِد الحالة | The SME cannot assign the case. */
    public function test_the_sme_cannot_assign_the_case(): void
    {
        [$smeId, $centerId, $caseId] = $this->scenario();

        try {
            $this->service->transition(
                caseId: $caseId,
                action: 'assign',
                side: BdsCaseRepository::SIDE_ORGANIZATION,
                actorUserId: $this->createUser(),
                actorOrganizationId: $smeId,
                request: $this->request('POST', '/assign'),
                extra: ['assigned_to' => $this->specialistOf($centerId)],
            );
            $this->fail('كان يجب رفض إسناد المشروع للحالة.');
        } catch (AuthorizationException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertStatus($caseId, 'requested');
    }

    /** الإجراءات المعروضة للمشروع لا تشمل إدارة الحالة | The SME's action list excludes management. */
    public function test_the_action_list_offered_to_each_side_differs(): void
    {
        $centerActions = $this->service->actionsFor('requested', BdsCaseRepository::SIDE_CENTER);
        $smeActions    = $this->service->actionsFor('requested', BdsCaseRepository::SIDE_ORGANIZATION);

        $this->assertContains('assign', $centerActions);
        $this->assertContains('start_triage', $centerActions);

        $this->assertSame(['cancel'], $smeActions);
    }

    /** الأخصائي المسنَد إليه يجب أن يكون عضواً في المركز | The specialist must belong to the centre. */
    public function test_a_specialist_from_another_organization_cannot_be_assigned(): void
    {
        [, $centerId, $caseId] = $this->scenario();
        $insider  = $this->specialistOf($centerId);
        $outsider = $this->specialistOf($this->createOrganization(['type_code' => 'bds_center', 'status' => 'verified']));

        $this->center($caseId, 'start_triage', $centerId, $insider);

        try {
            $this->center($caseId, 'assign', $centerId, $insider, extra: ['assigned_to' => $outsider]);
            $this->fail('كان يجب رفض إسناد أخصائي من مركز آخر.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('ليس عضواً', $e->getMessage());
        }

        $this->assertStatus($caseId, 'triage');
    }

    /** الإسناد بلا أخصائي مرفوض | Assignment without a specialist is refused. */
    public function test_assignment_without_a_specialist_is_refused(): void
    {
        [, $centerId, $caseId] = $this->scenario();

        $this->expectException(HttpException::class);
        $this->center($caseId, 'assign', $centerId, $this->specialistOf($centerId));
    }

    // ═══════════════════ الإغلاق المكتوب | Documented closure ═══════════════════

    /** الإغلاق بلا ملخّص مرفوض | Closing without a summary is refused. */
    public function test_a_case_cannot_be_closed_without_a_written_outcome(): void
    {
        [, $centerId, $caseId] = $this->scenario();
        $specialist = $this->specialistOf($centerId);

        $this->center($caseId, 'start_triage', $centerId, $specialist);
        $this->center($caseId, 'assign', $centerId, $specialist, extra: ['assigned_to' => $specialist]);
        $this->center($caseId, 'start_work', $centerId, $specialist);

        try {
            $this->center($caseId, 'close_completed', $centerId, $specialist, note: '   ');
            $this->fail('كان يجب رفض الإغلاق بلا ملخّص.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('ملخّص', $e->getMessage());
        }

        $this->assertStatus($caseId, 'in_progress');
    }

    /** التعليق يستوجب سبباً كذلك | Placing a case on hold also requires a reason. */
    public function test_holding_a_case_requires_a_reason(): void
    {
        $this->assertTrue($this->service->requiresText('hold'));
        $this->assertTrue($this->service->requiresText('cancel'));
        $this->assertFalse($this->service->requiresText('start_work'));
    }

    // ═══════════════════ الجلسات | Consultations ═══════════════════

    /** الجلسة المكتملة تستوجب ملخّصاً | A completed session requires a summary. */
    public function test_a_completed_session_requires_a_summary(): void
    {
        [, $centerId, $caseId] = $this->scenario();

        $consultationId = $this->service->scheduleConsultation(
            $caseId,
            $centerId,
            $this->createUser(),
            ['title_ar' => 'جلسة أولى', 'scheduled_at' => date('Y-m-d H:i:s'), 'mode' => 'phone'],
        );

        try {
            $this->service->recordConsultation($consultationId, $centerId, 'completed', null, null);
            $this->fail('كان يجب رفض إكمال جلسة بلا ملخّص.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // «لم يحضر» لا يستوجب ملخّصاً — لا شيء جرى ليُلخَّص
        $this->service->recordConsultation($consultationId, $centerId, 'no_show', null, null);

        $sessions = $this->cases->consultationsFor(BdsCaseRepository::SIDE_CENTER, $caseId);
        $this->assertSame('no_show', $sessions[0]['status']);
        $this->assertNull($sessions[0]['completed_at']);
    }

    /** مركز آخر لا يسجّل نتيجة جلسة ليست له | Another centre cannot record the session. */
    public function test_a_stranger_center_cannot_record_the_session(): void
    {
        [, $centerId, $caseId] = $this->scenario();
        $intruder = $this->createOrganization(['type_code' => 'bds_center', 'status' => 'verified']);

        $consultationId = $this->service->scheduleConsultation(
            $caseId,
            $centerId,
            $this->createUser(),
            ['title_ar' => 'جلسة', 'scheduled_at' => date('Y-m-d H:i:s'), 'mode' => 'online'],
        );

        try {
            $this->service->recordConsultation($consultationId, $intruder, 'completed', 'ملخّص.', null);
            $this->fail('كان يجب حجب الجلسة عن مركز آخر.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    // ═══════════════════ المهام | Plan tasks ═══════════════════

    /** كل طرف يحدّث مهامه هو | Each side updates only its own tasks. */
    public function test_a_side_cannot_update_the_other_sides_task(): void
    {
        [$smeId, $centerId, $caseId] = $this->scenario();

        $planId = $this->service->createPlan($caseId, $centerId, $this->createUser(), ['title_ar' => 'خطة']);

        $smeTask = $this->service->addTask($planId, $centerId, [
            'title_ar'   => 'مهمة على المشروع',
            'owner_side' => 'organization',
        ]);

        $centerTask = $this->service->addTask($planId, $centerId, [
            'title_ar'   => 'مهمة على المركز',
            'owner_side' => 'center',
        ]);

        $this->service->sharePlan($planId, $centerId);

        // المشروع يحدّث مهمته
        $this->service->updateTask($smeTask, BdsCaseRepository::SIDE_ORGANIZATION, $smeId, $this->createUser(), 'done');

        // ولا يحدّث مهمة المركز
        try {
            $this->service->updateTask(
                $centerTask,
                BdsCaseRepository::SIDE_ORGANIZATION,
                $smeId,
                $this->createUser(),
                'done',
            );
            $this->fail('كان يجب رفض تحديث المشروع لمهمة المركز.');
        } catch (AuthorizationException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $tasks = $this->tasksOf($planId);

        $this->assertSame('done', $tasks[$smeTask]['status']);
        $this->assertNotNull($tasks[$smeTask]['completed_at']);
        $this->assertSame('pending', $tasks[$centerTask]['status']);
    }

    /** مهمة خطة غير مشتركة لا يبلغها المشروع | An unshared plan's task is out of the SME's reach. */
    public function test_the_sme_cannot_update_a_task_of_an_unshared_plan(): void
    {
        [$smeId, $centerId, $caseId] = $this->scenario();

        $planId = $this->service->createPlan($caseId, $centerId, $this->createUser(), ['title_ar' => 'مسودة']);
        $taskId = $this->service->addTask($planId, $centerId, [
            'title_ar'   => 'مهمة لم تُشارك بعد',
            'owner_side' => 'organization',
        ]);

        try {
            $this->service->updateTask(
                $taskId,
                BdsCaseRepository::SIDE_ORGANIZATION,
                $smeId,
                $this->createUser(),
                'done',
            );
            $this->fail('كان يجب حجب مهمة خطة غير مشتركة.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame('pending', $this->tasksOf($planId)[$taskId]['status']);
    }

    // ═══════════════════ الإحالات | Referrals ═══════════════════

    /** الإحالة توصية موثّقة لا التزام | A referral is a documented recommendation, not a commitment. */
    public function test_a_referral_records_a_recommendation_without_creating_an_application(): void
    {
        [$smeId, $centerId, $caseId] = $this->scenario();

        $bankId    = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $productId = $this->createFinancingProduct($bankId);

        $referralId = $this->service->refer(
            $caseId,
            $centerId,
            $this->createUser(),
            'financing_product',
            $productId,
            'يناسب احتياج رأس المال العامل الذي ظهر في التشخيص.',
        );

        $referrals = $this->cases->referralsFor($caseId);

        $this->assertCount(1, $referrals);
        $this->assertSame('suggested', $referrals[0]['status']);

        // لا طلب تمويل أُنشئ نيابةً عن المشروع
        $this->assertSame(0, $this->countRows('financing_applications', 'organization_id = ?', [$smeId]));

        // القرار للمشروع
        $this->service->respondToReferral($referralId, $smeId, 'declined', 'سنؤجل التمويل.');

        $referrals = $this->cases->referralsFor($caseId);
        $this->assertSame('declined', $referrals[0]['status']);
        $this->assertNotNull($referrals[0]['responded_at']);
    }

    /** لا إحالة إلى عرض غير معتمد | No referral to an unapproved offer. */
    public function test_a_referral_cannot_point_at_an_unapproved_offer(): void
    {
        [, $centerId, $caseId] = $this->scenario();

        $bankId  = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $pending = $this->createFinancingProduct($bankId, ['status' => 'pending_review', 'published_at' => null]);

        try {
            $this->service->refer(
                $caseId,
                $centerId,
                $this->createUser(),
                'financing_product',
                $pending,
                'سبب مكتوب بطول كافٍ لتجاوز الحدّ الأدنى.',
            );
            $this->fail('كان يجب رفض الإحالة إلى منتج غير معتمد.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame([], $this->cases->referralsFor($caseId));
    }

    /** لا إحالة إلى عرض جهة غير موثّقة | No referral to an unverified provider's offer. */
    public function test_a_referral_cannot_point_at_an_unverified_providers_offer(): void
    {
        [, $centerId, $caseId] = $this->scenario();

        $provider  = $this->createOrganization(['type_code' => 'service_provider', 'status' => 'under_review']);
        $offeringId = $this->createServiceOffering($provider);

        $this->expectException(HttpException::class);

        $this->service->refer(
            $caseId,
            $centerId,
            $this->createUser(),
            'service_offering',
            $offeringId,
            'سبب مكتوب بطول كافٍ لتجاوز الحدّ الأدنى.',
        );
    }

    /** الإحالة بلا سبب مرفوضة | A referral without a reason is refused. */
    public function test_a_referral_requires_a_written_reason(): void
    {
        [, $centerId, $caseId] = $this->scenario();

        $bankId    = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $productId = $this->createFinancingProduct($bankId);

        try {
            $this->service->refer($caseId, $centerId, $this->createUser(), 'financing_product', $productId, 'قصير');
            $this->fail('كان يجب رفض إحالة بلا سبب.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    /** منشأة أخرى لا تردّ على توصية ليست لها | Another SME cannot answer the referral. */
    public function test_a_stranger_sme_cannot_respond_to_the_referral(): void
    {
        [, $centerId, $caseId] = $this->scenario();

        $bankId    = $this->createOrganization(['type_code' => 'bank', 'status' => 'verified']);
        $productId = $this->createFinancingProduct($bankId);

        $referralId = $this->service->refer(
            $caseId,
            $centerId,
            $this->createUser(),
            'financing_product',
            $productId,
            'يناسب احتياج رأس المال العامل الذي ظهر في التشخيص.',
        );

        $intruder = $this->createOrganization(['status' => 'verified']);

        try {
            $this->service->respondToReferral($referralId, $intruder, 'accepted', null);
            $this->fail('كان يجب حجب التوصية عن منشأة أخرى.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame('suggested', $this->cases->referralsFor($caseId)[0]['status']);
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /** @return array{0:int,1:int,2:int} [smeId, centerId, caseId] */
    private function scenario(): array
    {
        $smeId    = $this->createOrganization(['status' => 'verified']);
        $centerId = $this->createOrganization(['type_code' => 'bds_center', 'status' => 'verified']);

        $result = $this->service->request(
            organizationId: $smeId,
            centerOrganizationId: $centerId,
            data: [
                'title_ar'           => 'طلب دعم اختباري',
                'request_details_ar' => 'نحتاج مساعدة في تنظيم حسابات المشروع وإعداد تقرير شهري.',
            ],
            actorId: $this->createUser(),
            request: $this->request('POST', '/request'),
        );

        return [$smeId, $centerId, $result['id']];
    }

    /** أخصائي عضو نشط في المركز | An active specialist of the centre. */
    private function specialistOf(int $centerId): int
    {
        $userId = $this->createUser();
        $this->addMember($centerId, $userId, 'bds_specialist');

        return $userId;
    }

    /** @param array<string,mixed> $extra */
    private function center(
        int $caseId,
        string $action,
        int $centerId,
        int $actorUserId,
        ?string $note = null,
        array $extra = [],
    ): array {
        return $this->service->transition(
            caseId: $caseId,
            action: $action,
            side: BdsCaseRepository::SIDE_CENTER,
            actorUserId: $actorUserId,
            actorOrganizationId: $centerId,
            request: $this->request('POST', '/bds/cases/' . $caseId . '/' . $action),
            note: $note,
            extra: $extra,
        );
    }

    private function assertStatus(int $caseId, string $expected): void
    {
        $this->assertDatabaseHas('bds_cases', ['id' => $caseId, 'status' => $expected]);
    }

    /** @return array<int,array<string,mixed>> */
    private function tasksOf(int $planId): array
    {
        $rows = \App\Core\Database::select(
            'SELECT * FROM bds_plan_tasks WHERE plan_id = ?',
            [$planId],
        );

        return array_column($rows, null, 'id');
    }
}
