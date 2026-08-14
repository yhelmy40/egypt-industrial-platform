<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ListingRepository;
use App\Services\ListingService;
use App\Validation\Validator;

/**
 * مراجعة محتوى السوق | Marketplace content moderation (§3.1, §4.4).
 *
 * الإدارة تراجع الإعلانات قبل نشرها وتدير الشكاوى. الرفض يستوجب سبباً يصل
 * لصاحب المنشأة، فالرفض الصامت يترك المشروع بلا طريق للتصحيح.
 */
final class ModerationController extends Controller
{
    public function __construct(
        private readonly ListingRepository $listings = new ListingRepository(),
        private readonly ListingService $service = new ListingService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $status = (string) ($request->input('status') ?? 'pending_review');

        if (!in_array($status, ['pending_review', 'published', 'rejected', 'draft', 'archived'], true)) {
            $status = 'pending_review';
        }

        return $this->view('admin/moderation/index', [
            'pageTitle' => 'مراجعة الإعلانات',
            'results'   => $this->listings->moderationQueue($status, $this->page($request)),
            'filters'   => ['status' => $status],
            'counts'    => $this->countsByStatus(),
            'service'   => $this->service,
        ], 'admin');
    }

    public function show(Request $request): Response
    {
        $listingId = $request->routeInt('id');

        if ($listingId === null) {
            throw new HttpException(404, 'الإعلان المطلوب غير موجود.');
        }

        // النطاق العام مبرَّر ومُسجَّل: المراجعة وظيفة عابرة للمنشآت بطبيعتها
        $listing = $this->listings->globalScope('مراجعة إدارية لإعلان')->find($listingId);

        if ($listing === null) {
            throw new HttpException(404, 'الإعلان المطلوب غير موجود.');
        }

        $organization = Database::selectOne(
            'SELECT o.*, g.name_ar AS governorate_name, s.name_ar AS sector_name
               FROM organizations o
               LEFT JOIN governorates g ON g.id = o.governorate_id
               LEFT JOIN sectors s ON s.id = o.sector_id
              WHERE o.id = ?',
            [(int) $listing['organization_id']],
        );

        return $this->view('admin/moderation/show', [
            'pageTitle'    => 'مراجعة: ' . $listing['name_ar'],
            'listing'      => $listing,
            'organization' => $organization,
            'images'       => $this->listings->images($listingId),
            'service'      => $this->service,
        ], 'admin');
    }

    public function decide(Request $request): Response
    {
        $listingId = $request->routeInt('id');
        $decision  = (string) ($request->input('decision') ?? '');

        if ($listingId === null) {
            throw new HttpException(404, 'الإعلان المطلوب غير موجود.');
        }

        $validator = Validator::make($request->all())
            ->labels(['note' => 'سبب القرار'])
            ->maxLength('note', 1000)
            ->requiredIf('note', $decision === 'reject');

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/admin/moderation/' . $listingId);
        }

        try {
            $this->service->moderate(
                listingId: $listingId,
                decision: $decision,
                note: $request->filled('note') ? (string) $request->input('note') : null,
                moderatorId: (int) $this->currentUserId(),
                request: $request,
            );

            $this->flash('success', $decision === 'approve' ? 'تم اعتماد نشر الإعلان.' : 'تم رفض نشر الإعلان.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/admin/moderation');
    }

    /** الشكاوى | Complaints queue (§4.4). */
    public function complaints(Request $request): Response
    {
        $status = (string) ($request->input('status') ?? 'new');

        $where    = ['c.deleted_at IS NULL'];
        $bindings = [];

        if ($status !== '') {
            $where[]    = 'c.status = ?';
            $bindings[] = $status;
        }

        $whereSql = implode(' AND ', $where);

        return $this->view('admin/moderation/complaints', [
            'pageTitle'  => 'الشكاوى والنزاعات',
            'complaints' => Database::select(
                "SELECT c.*, o.legal_name, o.trading_name, ord.order_number
                   FROM complaints c
                   LEFT JOIN organizations o ON o.id = c.organization_id
                   LEFT JOIN orders ord ON ord.id = c.order_id
                  WHERE {$whereSql}
                  ORDER BY c.created_at DESC
                  LIMIT 100",
                $bindings,
            ),
            'disputed'   => Database::select(
                "SELECT ord.*, o.legal_name, o.trading_name
                   FROM orders ord
                   JOIN organizations o ON o.id = ord.organization_id
                  WHERE ord.status = 'disputed' AND ord.deleted_at IS NULL
                  ORDER BY ord.updated_at DESC
                  LIMIT 50",
            ),
            'filters'    => ['status' => $status],
        ], 'admin');
    }

    /** @return array<string,int> */
    private function countsByStatus(): array
    {
        $rows = Database::select(
            'SELECT status, COUNT(*) AS total FROM listings WHERE deleted_at IS NULL GROUP BY status',
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }
}
