<?php

declare(strict_types=1);

namespace App\Controllers\Sme;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\MembershipRepository;
use App\Support\TenantContext;

/**
 * لوحة تحكم المنشأة | Organization workspace dashboard (§4.13).
 *
 * في المرحلة الأولى تعرض اللوحة هيكل مساحة العمل وحالة المنشأة والخطوات
 * التالية؛ تُملأ البطاقات ببيانات حقيقية مع كل مرحلة لاحقة.
 * In Phase 1 the dashboard renders the workspace shell, the organization's
 * status and next steps; cards are populated with live data as later phases
 * land.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly MembershipRepository $memberships = new MembershipRepository(),
    ) {
    }

    public function index(Request $request): Response
    {
        $userId        = (int) $this->currentUserId();
        $organizations = $this->memberships->organizationsForUser($userId);

        // مستخدم بلا منشأة → يُوجَّه لإنشاء واحدة أو تصفّح السوق
        if (!TenantContext::hasOrganization()) {
            return $this->view('sme/no-organization', [
                'pageTitle'     => __('common.dashboard'),
                'organizations' => $organizations,
            ], 'app');
        }

        $organization = TenantContext::organization() ?? [];

        return $this->view('sme/dashboard', [
            'pageTitle'      => __('common.dashboard'),
            'organization'   => $organization,
            'organizations'  => $organizations,
            'nextSteps'      => $this->nextSteps($organization),
            'statusMessage'  => $this->statusMessage((string) ($organization['status'] ?? 'draft')),
        ], 'app');
    }

    /**
     * تبديل المنشأة النشطة | Switch the active organization.
     *
     * أمني: لا يُقبل المعرّف إلا بعد إثبات عضوية نشطة. هذا المسار هو المكان
     * الوحيد الذي يغيّر active_organization_id، ولا يثق بالقيمة القادمة أبداً.
     * Security: the id is accepted only after an active membership is proven.
     * This is the only route that changes active_organization_id.
     */
    public function switchOrganization(Request $request): Response
    {
        $userId         = (int) $this->currentUserId();
        $organizationId = $request->integer('organization_id');

        if ($organizationId === null) {
            $this->flash('danger', 'لم يتم تحديد المنشأة.');

            return $this->redirect('/app');
        }

        $membership = $this->memberships->findActiveMembership($userId, $organizationId);

        if ($membership === null) {
            // لا نكشف ما إذا كانت المنشأة موجودة أصلاً.
            $this->flash('danger', 'ليس لديك صلاحية الوصول إلى هذه المنشأة.');

            return $this->redirect('/app');
        }

        Session::put('active_organization_id', $organizationId);
        (new \App\Repositories\UserRepository())->setLastOrganization($userId, $organizationId);

        $this->flash('success', 'تم تبديل المنشأة النشطة.');

        return $this->redirect('/app');
    }

    /**
     * الخطوات التالية المقترحة | Suggested next steps.
     *
     * @return array<int,array{title:string,description:string,url:string,done:bool}>
     */
    private function nextSteps(array $organization): array
    {
        $status = (string) ($organization['status'] ?? 'draft');
        $score  = (int) ($organization['completion_score'] ?? 0);

        return [
            [
                'title'       => 'استكمال بيانات المنشأة',
                'description' => 'كلّما اكتملت بياناتك زادت فرص ظهورك في نتائج البحث والترشيحات.',
                'url'         => url('/app/organization'),
                'done'        => $score >= 80,
            ],
            [
                'title'       => 'رفع المستندات المطلوبة',
                'description' => 'السجل التجاري والبطاقة الضريبية إن وُجدت، لتسريع مراجعة التوثيق.',
                'url'         => url('/app/organization/documents'),
                'done'        => false,
            ],
            [
                'title'       => 'إرسال المنشأة للمراجعة',
                'description' => 'بعد استكمال البيانات، أرسل ملفك لفريق المنصة لاعتماد التوثيق.',
                'url'         => url('/app/organization'),
                'done'        => in_array($status, ['submitted', 'under_review', 'verified'], true),
            ],
            [
                'title'       => 'إنشاء الصفحة التعريفية',
                'description' => 'صفحة عامة بالعربية تعرّف بمشروعك ومنتجاتك ووسائل التواصل معك.',
                'url'         => url('/app/page'),
                'done'        => false,
            ],
        ];
    }

    /** @return array{type:string,text:string} */
    private function statusMessage(string $status): array
    {
        return match ($status) {
            'draft' => [
                'type' => 'info',
                'text' => 'ملف منشأتك ما زال مسودة. استكمل البيانات ثم أرسله للمراجعة.',
            ],
            'submitted' => [
                'type' => 'info',
                'text' => 'تم استلام طلب التوثيق وهو في انتظار المراجعة من فريق المنصة.',
            ],
            'under_review' => [
                'type' => 'info',
                'text' => 'ملف منشأتك قيد المراجعة حالياً. سنُعلمك فور صدور القرار.',
            ],
            'more_info_required' => [
                'type' => 'warning',
                'text' => 'يحتاج ملفك إلى بيانات أو مستندات إضافية. يرجى مراجعة الملاحظات واستكمال المطلوب.',
            ],
            'verified' => [
                'type' => 'success',
                'text' => 'منشأتك موثّقة. يمكنك الآن نشر صفحتك التعريفية ومنتجاتك في السوق.',
            ],
            'rejected' => [
                'type' => 'danger',
                'text' => 'لم يتم اعتماد طلب التوثيق. يمكنك مراجعة سبب الرفض وتعديل البيانات وإعادة الإرسال.',
            ],
            'suspended' => [
                'type' => 'danger',
                'text' => 'تم إيقاف المنشأة. يرجى التواصل مع الدعم الفني.',
            ],
            default => ['type' => 'info', 'text' => ''],
        };
    }
}
