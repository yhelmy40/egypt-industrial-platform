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
use App\Services\ExpenseService;
use App\Services\PurchaseOrderService;

/**
 * المشتريات والموردون والمصروفات | Purchasing, suppliers and expenses (§4.10).
 *
 * الاستلام وحده يزيد المخزون: إرسال الأمر للمورّد لا يُنشئ حركة، لأن بضاعة لم
 * تصل ليست بضاعة في المخزن.
 */
final class PurchasingController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $service = new PurchaseOrderService(),
        private readonly ExpenseService $expenses = new ExpenseService(),
        private readonly InventoryRepository $items = new InventoryRepository(),
        private readonly OrganizationRepository $organizations = new OrganizationRepository(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
    ) {
    }

    // ═══════════════════ الموردون | Suppliers ═══════════════════

    public function suppliers(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $term           = (string) ($request->input('q') ?? '');

        $where    = ['organization_id = ?', 'deleted_at IS NULL'];
        $bindings = [$organizationId];

        if ($term !== '') {
            $where[]    = '(name_ar LIKE ? OR code LIKE ? OR phone LIKE ?)';
            $bindings[] = '%' . $term . '%';
            $bindings[] = '%' . $term . '%';
            $bindings[] = '%' . $term . '%';
        }

        return $this->view('sme/purchasing/suppliers', array_merge(
            $this->chrome($organizationId, 'الموردون'),
            [
                'suppliers'    => Database::select(
                    'SELECT * FROM erp_suppliers WHERE ' . implode(' AND ', $where)
                    . ' ORDER BY name_ar ASC LIMIT 200',
                    $bindings,
                ),
                'governorates' => Database::select('SELECT id, name_ar FROM governorates ORDER BY name_ar ASC'),
                'filters'      => ['q' => $term],
            ],
        ), 'app');
    }

    public function storeSupplier(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        try {
            $this->service->createSupplier($organizationId, $request->all());
            $this->flash('success', 'أُضيف المورّد.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/purchasing/suppliers');
    }

    public function updateSupplier(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $supplierId     = $this->routeId($request, 'المورّد غير موجود.');

        try {
            $this->service->updateSupplier($supplierId, $organizationId, $request->all());
            $this->flash('success', 'حُفظت بيانات المورّد.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/purchasing/suppliers');
    }

    public function archiveSupplier(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $supplierId     = $this->routeId($request, 'المورّد غير موجود.');

        try {
            $this->service->archiveSupplier($supplierId, $organizationId);
            $this->flash('success', 'أُرشِف المورّد.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/purchasing/suppliers');
    }

    // ═══════════════════ أوامر الشراء | Purchase orders ═══════════════════

    public function orders(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $status         = (string) ($request->input('status') ?? '');

        $where    = ['p.organization_id = ?', 'p.deleted_at IS NULL'];
        $bindings = [$organizationId];

        if ($status !== '') {
            $where[]    = 'p.status = ?';
            $bindings[] = $status;
        }

        return $this->view('sme/purchasing/orders', array_merge(
            $this->chrome($organizationId, 'أوامر الشراء'),
            [
                'orders'    => Database::select(
                    'SELECT p.*, s.name_ar AS supplier_name
                       FROM erp_purchase_orders p
                       JOIN erp_suppliers s ON s.id = p.supplier_id
                      WHERE ' . implode(' AND ', $where) . '
                   ORDER BY p.order_date DESC, p.id DESC
                      LIMIT 200',
                    $bindings,
                ),
                'suppliers' => $this->selectableSuppliers($organizationId),
                'filters'   => ['status' => $status],
                'service'   => $this->service,
            ],
        ), 'app');
    }

    public function storeOrder(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        try {
            $result = $this->service->create($organizationId, $request->all(), $this->currentUserId());
            $this->flash('success', 'أُنشئ أمر الشراء برقم ' . $result['number'] . '.');

            return $this->redirect('/app/purchasing/orders/' . $result['id']);
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());

            return $this->redirect('/app/purchasing/orders');
        }
    }

    public function showOrder(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $poId           = $this->routeId($request, 'أمر الشراء غير موجود.');

        $po = Database::selectOne(
            'SELECT p.*, s.name_ar AS supplier_name, s.phone AS supplier_phone
               FROM erp_purchase_orders p
               JOIN erp_suppliers s ON s.id = p.supplier_id
              WHERE p.id = ? AND p.organization_id = ? AND p.deleted_at IS NULL
              LIMIT 1',
            [$poId, $organizationId],
        );

        if ($po === null) {
            throw new HttpException(404, 'أمر الشراء غير موجود.');
        }

        return $this->view('sme/purchasing/order', array_merge(
            $this->chrome($organizationId, 'أمر شراء ' . $po['po_number']),
            [
                'order'    => $po,
                'lines'    => Database::select(
                    'SELECT * FROM erp_purchase_order_items
                      WHERE purchase_order_id = ? ORDER BY sort_order ASC, id ASC',
                    [$poId],
                ),
                'items'    => $this->items->selectable($organizationId),
                'editable' => (string) $po['status'] === 'draft',
                'canReceive' => in_array((string) $po['status'], ['sent', 'partially_received'], true),
                'service'  => $this->service,
            ],
        ), 'app');
    }

    public function addOrderLine(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $poId           = $this->routeId($request, 'أمر الشراء غير موجود.');

        try {
            $this->service->addLine($poId, $organizationId, $request->all());
            $this->flash('success', 'أُضيف البند.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/purchasing/orders/' . $poId);
    }

    public function removeOrderLine(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $poId           = $this->routeId($request, 'أمر الشراء غير موجود.');

        try {
            $this->service->removeLine($poId, $organizationId, (int) ($request->input('line_id') ?? 0));
            $this->flash('success', 'حُذف البند.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/purchasing/orders/' . $poId);
    }

    public function sendOrder(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $poId           = $this->routeId($request, 'أمر الشراء غير موجود.');

        try {
            $this->service->send($poId, $organizationId);
            $this->flash('success', 'أُرسل أمر الشراء. المخزون لا يزيد إلا بتسجيل الاستلام.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/purchasing/orders/' . $poId);
    }

    /**
     * تسجيل استلام | Record a goods receipt.
     *
     * النموذج يرسل كمية لكل بند؛ البنود الفارغة تُتخطّى فيمكن استلام بعض
     * الأصناف دون غيرها.
     */
    public function receiveOrder(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $poId           = $this->routeId($request, 'أمر الشراء غير موجود.');

        $quantities = $request->input('received');
        $received   = [];

        if (is_array($quantities)) {
            foreach ($quantities as $lineId => $quantity) {
                if (!is_numeric($quantity) || (float) $quantity <= 0) {
                    continue;
                }

                $received[] = ['line_id' => (int) $lineId, 'quantity' => (float) $quantity];
            }
        }

        try {
            $this->service->receive($poId, $organizationId, $received, $this->currentUserId());
            $this->flash('success', 'سُجّل الاستلام وزاد المخزون بحركة مرجعية.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/purchasing/orders/' . $poId);
    }

    public function cancelOrder(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $poId           = $this->routeId($request, 'أمر الشراء غير موجود.');

        try {
            $this->service->cancel(
                $poId,
                $organizationId,
                (string) ($request->input('cancelled_reason_ar') ?? ''),
            );
            $this->flash('success', 'أُلغي أمر الشراء.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/purchasing/orders/' . $poId);
    }

    // ═══════════════════ المصروفات | Expenses ═══════════════════

    public function expenses(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        $from = (string) ($request->input('from') ?? date('Y-m-01'));
        $to   = (string) ($request->input('to') ?? date('Y-m-d'));

        $where    = ['e.organization_id = ?', 'e.deleted_at IS NULL', 'e.spent_at BETWEEN ? AND ?'];
        $bindings = [$organizationId, $from, $to];

        $category = (string) ($request->input('category') ?? '');

        if ($category !== '') {
            $where[]    = 'e.category = ?';
            $bindings[] = $category;
        }

        $clause = implode(' AND ', $where);

        return $this->view('sme/purchasing/expenses', array_merge(
            $this->chrome($organizationId, 'المصروفات'),
            [
                'expenses'  => Database::select(
                    "SELECT e.*, s.name_ar AS supplier_name
                       FROM erp_expenses e
                  LEFT JOIN erp_suppliers s ON s.id = e.supplier_id
                      WHERE {$clause}
                   ORDER BY e.spent_at DESC, e.id DESC
                      LIMIT 300",
                    $bindings,
                ),
                'total'     => (float) Database::scalar(
                    "SELECT COALESCE(SUM(e.amount), 0) FROM erp_expenses e WHERE {$clause}",
                    $bindings,
                ),
                'suppliers' => $this->selectableSuppliers($organizationId),
                'filters'   => ['from' => $from, 'to' => $to, 'category' => $category],
                'service'   => $this->expenses,
            ],
        ), 'app');
    }

    public function storeExpense(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        try {
            $result = $this->expenses->create($organizationId, $request->all(), $this->currentUserId());
            $this->flash('success', 'سُجّل المصروف برقم ' . $result['number'] . '.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->back($request, [], '/app/purchasing/expenses');
    }

    public function deleteExpense(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $expenseId      = $this->routeId($request, 'المصروف غير موجود.');

        try {
            $this->expenses->delete($expenseId, $organizationId);
            $this->flash('success', 'حُذف المصروف.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->back($request, [], '/app/purchasing/expenses');
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    private function routeId(Request $request, string $message): int
    {
        $id = $request->routeInt('id');

        if ($id === null) {
            throw new HttpException(404, $message);
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

    /** @return array<int,array<string,mixed>> */
    private function selectableSuppliers(int $organizationId): array
    {
        return Database::select(
            "SELECT id, code, name_ar FROM erp_suppliers
              WHERE organization_id = ? AND deleted_at IS NULL AND status = 'active'
           ORDER BY name_ar ASC LIMIT 300",
            [$organizationId],
        );
    }
}
