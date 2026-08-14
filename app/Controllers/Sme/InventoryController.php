<?php

declare(strict_types=1);

namespace App\Controllers\Sme;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\InventoryRepository;
use App\Repositories\MembershipRepository;
use App\Repositories\OrganizationRepository;
use App\Services\InventoryService;

/**
 * الأصناف والمخزون | Items and stock (§4.10).
 *
 * الرصيد لا يُحرَّر من أي شاشة هنا. تغييره يمرّ بنموذج حركة يحمل نوعها وسببها،
 * فيظهر في الدفتر سطر يفسّر كل فرق.
 */
final class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryRepository $items = new InventoryRepository(),
        private readonly InventoryService $service = new InventoryService(),
        private readonly OrganizationRepository $organizations = new OrganizationRepository(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
    ) {
    }

    public function index(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        $filters = [
            'q'         => (string) ($request->input('q') ?? ''),
            'item_type' => (string) ($request->input('item_type') ?? ''),
            'status'    => (string) ($request->input('status') ?? ''),
            'low_stock' => $request->input('low_stock') !== null,
        ];

        return $this->view('sme/inventory/index', array_merge(
            $this->chrome($organizationId, 'الأصناف والمخزون'),
            [
                'results'   => $this->items->search($organizationId, $filters, $this->page($request)),
                'filters'   => $filters,
                'lowStock'  => $this->items->lowStock($organizationId, 10),
                'valuation' => $this->items->valuation($organizationId),
                'service'   => $this->service,
            ],
        ), 'app');
    }

    public function create(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        return $this->view('sme/inventory/form', array_merge(
            $this->chrome($organizationId, 'إضافة صنف'),
            [
                'item'     => null,
                'listings' => $this->linkableListings($organizationId, null),
                'service'  => $this->service,
            ],
        ), 'app');
    }

    public function store(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        try {
            $itemId = $this->service->create($organizationId, $request->all(), $this->currentUserId());
            $this->flash('success', 'أُضيف الصنف.');

            return $this->redirect('/app/inventory/' . $itemId);
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());

            return $this->back($request, [], '/app/inventory/new');
        }
    }

    public function show(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $itemId         = $this->routeId($request);
        $item           = $this->items->findOwned($itemId, $organizationId);

        if ($item === null) {
            throw new HttpException(404, 'الصنف غير موجود.');
        }

        return $this->view('sme/inventory/show', array_merge(
            $this->chrome($organizationId, $item['name_ar']),
            [
                'item'      => $item,
                'movements' => $this->items->movementsFor($itemId, $organizationId, 100),
                'service'   => $this->service,
            ],
        ), 'app');
    }

    public function edit(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $itemId         = $this->routeId($request);
        $item           = $this->items->findOwned($itemId, $organizationId);

        if ($item === null) {
            throw new HttpException(404, 'الصنف غير موجود.');
        }

        return $this->view('sme/inventory/form', array_merge(
            $this->chrome($organizationId, 'تعديل الصنف'),
            [
                'item'     => $item,
                'listings' => $this->linkableListings($organizationId, $itemId),
                'service'  => $this->service,
            ],
        ), 'app');
    }

    public function update(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $itemId         = $this->routeId($request);

        try {
            $this->service->update($itemId, $organizationId, $request->all());
            $this->flash('success', 'حُفظت بيانات الصنف.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/inventory/' . $itemId);
    }

    public function archive(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $itemId         = $this->routeId($request);

        try {
            $this->service->archive($itemId, $organizationId);
            $this->flash('success', 'أُرشِف الصنف.');

            return $this->redirect('/app/inventory');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());

            return $this->redirect('/app/inventory/' . $itemId);
        }
    }

    // ═══════════════════ الحركات | Movements ═══════════════════

    /** تسجيل حركة يدوية | Record a manual movement. */
    public function recordMovement(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $itemId         = $this->routeId($request);

        try {
            $this->service->record(
                itemId: $itemId,
                organizationId: $organizationId,
                type: (string) ($request->input('movement_type') ?? ''),
                delta: (float) ($request->input('quantity') ?? 0),
                actorUserId: $this->currentUserId(),
                reason: $request->input('reason_ar'),
                unitCost: is_numeric($request->input('unit_cost'))
                    ? (float) $request->input('unit_cost')
                    : null,
                movedAt: $request->input('moved_at'),
            );
            $this->flash('success', 'سُجّلت الحركة وحُدِّث الرصيد.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/inventory/' . $itemId);
    }

    /** تسوية جرد | A stocktake adjustment. */
    public function adjust(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $itemId         = $this->routeId($request);

        try {
            $movementId = $this->service->adjustToCount(
                itemId: $itemId,
                organizationId: $organizationId,
                countedQuantity: (float) ($request->input('counted_quantity') ?? 0),
                reason: (string) ($request->input('reason_ar') ?? ''),
                actorUserId: $this->currentUserId(),
            );

            $this->flash(
                'success',
                $movementId === null
                    ? 'الرصيد المعدود مطابق للمسجَّل، فلم تُسجَّل حركة.'
                    : 'سُجّلت تسوية الجرد.',
            );
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/inventory/' . $itemId);
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    private function routeId(Request $request): int
    {
        $id = $request->routeInt('id');

        if ($id === null) {
            throw new HttpException(404, 'الصنف غير موجود.');
        }

        return $id;
    }

    /** @return array<string,mixed> */
    private function chrome(int $organizationId, string $title): array
    {
        return [
            'pageTitle'     => $title,
            'organization'  => $this->organizations->findWithDetails($organizationId),
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
        ];
    }

    /**
     * إعلانات قابلة للربط | Listings still free to link.
     *
     * الإعلان المرتبط بصنف آخر لا يُعرض: عرضه يدعو لخطأ ترفضه الخدمة بعد
     * إرسال النموذج، وحجبه أوضح من رسالة خطأ.
     *
     * @return array<int,array<string,mixed>>
     */
    private function linkableListings(int $organizationId, ?int $exceptItemId): array
    {
        return Database::select(
            'SELECT l.id, l.name_ar, l.sku
               FROM listings l
          LEFT JOIN erp_items i ON i.listing_id = l.id AND i.deleted_at IS NULL
              WHERE l.organization_id = ? AND l.deleted_at IS NULL
                AND (i.id IS NULL' . ($exceptItemId !== null ? ' OR i.id = ?' : '') . ')
           ORDER BY l.name_ar ASC
              LIMIT 200',
            $exceptItemId !== null ? [$organizationId, $exceptItemId] : [$organizationId],
        );
    }
}
