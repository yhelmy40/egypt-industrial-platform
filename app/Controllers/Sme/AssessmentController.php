<?php

declare(strict_types=1);

namespace App\Controllers\Sme;

use App\Controllers\Controller;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\MembershipRepository;
use App\Services\AssessmentService;
use App\Services\MatchingService;

/**
 * تقييم الاحتياجات والاقتراحات | Needs assessment and suggestions (§4.7).
 *
 * الاستبيان ونتيجته والاقتراحات المبنية عليه في مكان واحد، لأن قيمة التقييم
 * تظهر فيما يترتّب عليه: صاحب المشروع يجيب فيرى فوراً ما يُقترح عليه ولماذا.
 *
 * الاقتراح إرشاد لا قرار: لا يمنع أحداً من التقديم على أي منتج، ولا يُقرأ
 * كتقييم جدارة (§14).
 */
final class AssessmentController extends Controller
{
    public function __construct(
        private readonly AssessmentService $assessments = new AssessmentService(),
        private readonly MatchingService $matching = new MatchingService(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
    ) {
    }

    public function show(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        $latest = $this->assessments->latestCompleted($organizationId);

        return $this->view('sme/assessment/index', [
            'pageTitle'     => 'تقييم احتياجات المشروع',
            'latest'        => $latest,
            'sections'      => AssessmentService::SECTIONS,
            'service'       => $this->assessments,
            'financing'     => $this->matching->storedFor($organizationId, 'financing_product'),
            'services'      => $this->matching->storedFor($organizationId, 'service_offering'),
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    /** بدء تقييم جديد أو استكمال مسودة | Start or resume the questionnaire. */
    public function form(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        $draft = $this->assessments->openDraft($organizationId);

        return $this->view('sme/assessment/form', [
            'pageTitle'     => 'استبيان التقييم',
            'assessment'    => $draft,
            'questions'     => $this->assessments->questions(),
            'answers'       => $this->assessments->answers((int) $draft['id']),
            'sections'      => AssessmentService::SECTIONS,
            'service'       => $this->assessments,
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ], 'app');
    }

    public function submit(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $assessmentId   = $request->routeInt('id');

        if ($assessmentId === null) {
            throw new HttpException(404, 'التقييم غير موجود.');
        }

        /** @var array<int|string,mixed> $answers */
        $answers = $request->input('answers');

        if (!is_array($answers)) {
            $answers = [];
        }

        try {
            $this->assessments->complete(
                assessmentId: $assessmentId,
                organizationId: $organizationId,
                input: $answers,
                actorId: $this->currentUserId(),
                request: $request,
            );

            // الاقتراحات تُحدَّث فور اكتمال التقييم: قيمة التقييم في ما يليه
            $this->matching->refreshFor($organizationId);

            $this->flash('success', 'اكتمل التقييم. حدّثنا الاقتراحات بناءً على إجاباتك.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());

            return $this->redirect('/app/assessment/form');
        }

        return $this->redirect('/app/assessment');
    }

    /** تحديث الاقتراحات يدوياً | Refresh suggestions on demand. */
    public function refreshSuggestions(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        $this->matching->refreshFor($organizationId);
        $this->flash('success', 'حُدِّثت الاقتراحات وفق بيانات مشروعك الحالية.');

        return $this->redirect('/app/assessment');
    }

    public function dismissSuggestion(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $suggestionId   = $request->routeInt('id');

        if ($suggestionId === null) {
            throw new HttpException(404, 'الاقتراح غير موجود.');
        }

        $this->matching->dismiss(
            $suggestionId,
            $organizationId,
            $request->filled('reason') ? (string) $request->input('reason') : null,
        );

        $this->flash('success', 'لن يظهر هذا الاقتراح مرة أخرى.');

        return $this->redirect('/app/assessment');
    }
}
