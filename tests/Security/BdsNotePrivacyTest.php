<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Database;
use App\Core\Exceptions\AuthorizationException;
use App\Repositories\BdsCaseRepository;
use App\Services\BdsCaseService;
use Tests\TestCase;

/**
 * خصوصية ملاحظات الأخصائي | Specialist note privacy (§4.8, §10).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * شرط صريح في المواصفة: **ملاحظات أخصائي تطوير الأعمال الداخلية محمية من
 * اطّلاع المشروع.** هذه المجموعة تثبت أن الحماية تقع في الاستعلام لا في القالب،
 * أي أن الملاحظة الداخلية **لا تُجلب من قاعدة البيانات أصلاً** حين تكون القراءة
 * نيابةً عن المشروع.
 *
 * الفرق ليس شكلياً: لو كان الحجب في العرض، لكان كل قالب جديد فرصة تسريب، ولكان
 * أي إخراج تصحيحي أو استجابة JSON كشفاً كاملاً لتقييم صريح كتبه أخصائي عن منشأة.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class BdsNotePrivacyTest extends TestCase
{
    private BdsCaseRepository $cases;

    private BdsCaseService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cases   = new BdsCaseRepository();
        $this->service = new BdsCaseService();
    }

    /** الملاحظة الداخلية لا تصل نسخة المشروع | The internal note never reaches the SME. */
    public function test_internal_notes_are_absent_from_the_sme_query(): void
    {
        [$smeId, $centerId, $caseId] = $this->scenario();

        $this->service->addNote(
            $caseId,
            BdsCaseRepository::SIDE_CENTER,
            $centerId,
            $this->createUser(),
            'تقييم صريح لا يجوز أن يقرأه صاحب المشروع.',
            'internal',
        );

        $this->service->addNote(
            $caseId,
            BdsCaseRepository::SIDE_CENTER,
            $centerId,
            $this->createUser(),
            'ملاحظة موجّهة للمشروع.',
            'shared',
        );

        $centerNotes = $this->cases->notesFor(BdsCaseRepository::SIDE_CENTER, $caseId);
        $smeNotes    = $this->cases->notesFor(BdsCaseRepository::SIDE_ORGANIZATION, $caseId);

        $this->assertCount(2, $centerNotes);
        $this->assertCount(1, $smeNotes);

        $smeJson = (string) json_encode($smeNotes, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('تقييم صريح', $smeJson);
        $this->assertStringContainsString('ملاحظة موجّهة للمشروع', $smeJson);

        // الصف الداخلي لم يُجلب أصلاً، لا أنه جُلب وأُخفي
        foreach ($smeNotes as $note) {
            $this->assertSame('shared', $note['visibility']);
        }
    }

    /** ملاحظة الجلسة الخاصة كذلك | The session's private note is equally excluded. */
    public function test_consultation_internal_notes_are_absent_from_the_sme_query(): void
    {
        [$smeId, $centerId, $caseId] = $this->scenario();

        $consultationId = $this->service->scheduleConsultation(
            $caseId,
            $centerId,
            $this->createUser(),
            ['title_ar' => 'جلسة تشخيص', 'scheduled_at' => date('Y-m-d H:i:s'), 'mode' => 'onsite'],
        );

        $this->service->recordConsultation(
            $consultationId,
            $centerId,
            'completed',
            'ملخّص يراه المشروع.',
            'انطباع خاص لا يُقال لصاحب المشروع.',
        );

        $centerSessions = $this->cases->consultationsFor(BdsCaseRepository::SIDE_CENTER, $caseId);
        $smeSessions    = $this->cases->consultationsFor(BdsCaseRepository::SIDE_ORGANIZATION, $caseId);

        $centerJson = (string) json_encode($centerSessions, JSON_UNESCAPED_UNICODE);
        $smeJson    = (string) json_encode($smeSessions, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('انطباع خاص', $centerJson);
        $this->assertStringNotContainsString('انطباع خاص', $smeJson);
        $this->assertStringContainsString('ملخّص يراه المشروع', $smeJson);

        // العمود نفسه غير موجود في نسخة المشروع، لا أنه فارغ
        $this->assertArrayNotHasKey('internal_note_ar', $smeSessions[0]);
        $this->assertArrayHasKey('internal_note_ar', $centerSessions[0]);
    }

    /** المشروع لا يكتب ملاحظة داخلية | The SME cannot author an internal note. */
    public function test_the_sme_cannot_write_an_internal_note(): void
    {
        [$smeId, , $caseId] = $this->scenario();

        $this->expectException(AuthorizationException::class);

        $this->service->addNote(
            $caseId,
            BdsCaseRepository::SIDE_ORGANIZATION,
            $smeId,
            $this->createUser(),
            'محاولة كتابة ملاحظة داخلية.',
            'internal',
        );
    }

    /** الخطة المسودة لا يراها المشروع | A draft plan is invisible to the SME. */
    public function test_an_unshared_plan_is_absent_from_the_sme_query(): void
    {
        [, $centerId, $caseId] = $this->scenario();

        $planId = $this->service->createPlan(
            $caseId,
            $centerId,
            $this->createUser(),
            ['title_ar' => 'خطة قيد الصياغة'],
        );

        $this->assertCount(1, $this->cases->plansFor(BdsCaseRepository::SIDE_CENTER, $caseId));
        $this->assertSame([], $this->cases->plansFor(BdsCaseRepository::SIDE_ORGANIZATION, $caseId));

        $this->service->addTask($planId, $centerId, ['title_ar' => 'مهمة أولى']);
        $this->service->sharePlan($planId, $centerId);

        $this->assertCount(1, $this->cases->plansFor(BdsCaseRepository::SIDE_ORGANIZATION, $caseId));
    }

    /** خطة بلا مهام لا تُشارك | An empty plan cannot be shared. */
    public function test_an_empty_plan_cannot_be_shared(): void
    {
        [, $centerId, $caseId] = $this->scenario();

        $planId = $this->service->createPlan(
            $caseId,
            $centerId,
            $this->createUser(),
            ['title_ar' => 'خطة فارغة'],
        );

        $this->expectException(\App\Core\Exceptions\HttpException::class);
        $this->service->sharePlan($planId, $centerId);
    }

    // ═══════════════════ العزل بين المنشآت | Cross-tenant isolation ═══════════════════

    public function test_a_stranger_center_cannot_read_the_case(): void
    {
        [, , $caseId] = $this->scenario();
        $intruder     = $this->createOrganization(['type_code' => 'bds_center', 'status' => 'verified']);

        try {
            $this->cases->findFor(BdsCaseRepository::SIDE_CENTER, $caseId, $intruder);
            $this->fail('كان يجب حجب الحالة عن مركز آخر.');
        } catch (\App\Core\Exceptions\HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_a_stranger_sme_cannot_read_the_case(): void
    {
        [, , $caseId] = $this->scenario();
        $intruder     = $this->createOrganization(['status' => 'verified']);

        $this->expectException(\App\Core\Exceptions\HttpException::class);
        $this->cases->findFor(BdsCaseRepository::SIDE_ORGANIZATION, $caseId, $intruder);
    }

    /**
     * الجهة الخطأ لا تفتح النسخة الأوسع | Reading as the wrong side opens nothing.
     *
     * المشروع الذي يمرّر `center` لا يحصل على نسخة المركز: القيد يقع على عمود
     * المركز فلا تطابق منشأته أي حالة.
     */
    public function test_an_sme_passing_the_center_side_still_reaches_nothing(): void
    {
        [$smeId, , $caseId] = $this->scenario();

        $this->expectException(\App\Core\Exceptions\HttpException::class);
        $this->cases->findFor(BdsCaseRepository::SIDE_CENTER, $caseId, $smeId);
    }

    public function test_an_unknown_side_is_refused_rather_than_defaulted(): void
    {
        [$smeId, , ] = $this->scenario();

        $this->expectException(\InvalidArgumentException::class);
        $this->cases->listFor('anything', $smeId);
    }

    public function test_case_lists_never_mix_organizations(): void
    {
        [$smeA, $centerA, ] = $this->scenario();
        [$smeB, $centerB, ] = $this->scenario();

        $this->assertCount(1, $this->cases->listFor(BdsCaseRepository::SIDE_ORGANIZATION, $smeA));
        $this->assertCount(1, $this->cases->listFor(BdsCaseRepository::SIDE_ORGANIZATION, $smeB));
        $this->assertCount(1, $this->cases->listFor(BdsCaseRepository::SIDE_CENTER, $centerA));
        $this->assertCount(1, $this->cases->listFor(BdsCaseRepository::SIDE_CENTER, $centerB));
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
}
