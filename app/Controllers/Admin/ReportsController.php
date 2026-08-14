<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\DataExportService;
use App\Services\PlatformReportService;

/**
 * تقارير المنصة | Platform reports (§4.12).
 *
 * التصدير محروس بصلاحية مستقلّة (`reports.data.export`) عن قراءة التقارير:
 * إخراج البيانات من النظام قرار أكبر من الاطّلاع عليها داخله.
 */
final class ReportsController extends Controller
{
    public function __construct(
        private readonly PlatformReportService $reports = new PlatformReportService(),
        private readonly DataExportService $exports = new DataExportService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $default = $this->reports->defaultPeriod();

        $report = $this->reports->overview(
            (string) ($request->input('from') ?? $default['from']),
            (string) ($request->input('to') ?? $default['to']),
        );

        return $this->view('admin/reports/index', [
            'pageTitle' => 'تقارير المنصة',
            'report'    => $report,
            'filters'   => $report['period'],
            'datasets'  => $this->exports->datasets(),
        ], 'admin');
    }

    /**
     * تصدير مجموعة بيانات | Export a dataset as CSV.
     *
     * الملف يُبنى في الذاكرة ويُرسل بترويسات التنزيل: لا يُكتب على القرص، فلا
     * يبقى ملف يحمل بيانات المنصة في مجلّد مؤقّت بعد انتهاء الطلب.
     */
    public function export(Request $request): Response
    {
        $dataset = (string) ($request->input('dataset') ?? '');
        $file    = $this->exports->export($dataset, $this->currentUserId(), $request);

        return Response::make($file['content'])
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . $file['filename'] . '"',
            )
            ->withHeader('Cache-Control', 'no-store, max-age=0')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
