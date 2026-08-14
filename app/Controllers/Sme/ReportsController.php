<?php

declare(strict_types=1);

namespace App\Controllers\Sme;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\MembershipRepository;
use App\Repositories\OrganizationRepository;
use App\Services\CustomerService;
use App\Services\ExpenseService;
use App\Services\ManagementReportService;
use App\Services\PipelineService;

/**
 * التقارير الإدارية | Management reports (§4.10, §14).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * كل تقرير هنا يعرض **التنويه الإلزامي**: هذه تقارير إدارية على الأساس النقدي
 * يجب أن يراجعها محاسب مؤهّل، وليست قوائم مالية معتمدة ولا مخرجات نظام
 * محاسبي بالقيد المزدوج.
 *
 * التنويه يأتي من الخدمة مع بيانات التقرير لا من نصّ في القالب، فلا يمكن أن
 * يُضاف تقرير جديد بلا تنويه.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class ReportsController extends Controller
{
    public function __construct(
        private readonly ManagementReportService $reports = new ManagementReportService(),
        private readonly ExpenseService $expenses = new ExpenseService(),
        private readonly CustomerService $customers = new CustomerService(),
        private readonly PipelineService $pipeline = new PipelineService(),
        private readonly OrganizationRepository $organizations = new OrganizationRepository(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
    ) {
    }

    public function index(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $default        = $this->reports->defaultPeriod();

        $from = (string) ($request->input('from') ?? $default['from']);
        $to   = (string) ($request->input('to') ?? $default['to']);

        $overview = $this->reports->overview($organizationId, $from, $to);

        return $this->view('sme/reports/index', [
            'pageTitle'      => 'التقارير الإدارية',
            'organization'   => $this->organizations->findWithDetails($organizationId),
            'organizations'  => $this->memberships->organizationsForUser((int) $this->currentUserId()),
            'report'         => $overview,
            'filters'        => $overview['period'],
            'expenseService' => $this->expenses,
            'customerService' => $this->customers,
            'pipelineService' => $this->pipeline,
        ], 'app');
    }
}
