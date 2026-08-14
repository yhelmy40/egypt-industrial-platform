<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\CustomerRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\PipelineRepository;

/**
 * تقارير إدارية | Management reports (§4.10, §14).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * **القاعدة الحاكمة: هذه تقارير إدارية لا قوائم مالية.**
 *
 * المواصفة تُلزم بعرض تنويه صريح بأن مخرجات هذه الوحدة تقارير إدارية يجب أن
 * يراجعها محاسب مؤهّل. التنويه هنا **ثابت في الخدمة لا نصّ في قالب**:
 * `DISCLAIMER_AR` يُعاد مع كل تقرير، فلا يمكن أن يُنشأ تقرير جديد وينسى
 * مطوّر أن يكتب التنويه في قالبه.
 *
 * وحدود ما تفعله هذه الوحدة صريحة:
 *  - **أساس نقدي:** الإيراد ما صدرت به فاتورة، والتدفّق ما قُبض وما صُرف فعلاً.
 *  - **لا قيد مزدوج ولا ميزانية ولا قائمة دخل معتمدة** (§14).
 *  - **تقييم المخزون بآخر تكلفة معروفة** لا بمتوسط مرجّح ولا بوارد أولاً؛
 *    وهو تقدير إداري يُعرض موسوماً بذلك.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class ManagementReportService
{
    /**
     * التنويه الإلزامي | The mandatory disclaimer (§4.10).
     *
     * يُعاد مع كل تقرير من هذه الخدمة. لا تُصدَّر دالة تقرير بدونه.
     */
    public const DISCLAIMER_AR =
        'هذه تقارير إدارية للاسترشاد الداخلي، أُعدَّت على الأساس النقدي من البيانات '
        . 'التي أدخلها المشروع، وليست قوائم مالية معتمدة ولا مخرجات نظام محاسبي '
        . 'بالقيد المزدوج. يجب أن يراجعها محاسب مؤهّل قبل الاعتماد عليها في أي إقرار '
        . 'ضريبي أو طلب تمويل أو قرار استثماري.';

    /** تنويه تقدير المخزون | The stock valuation caveat. */
    public const STOCK_NOTE_AR =
        'قيمة المخزون تقدير بآخر تكلفة شراء معروفة لكل صنف، وليست تقييماً محاسبياً '
        . 'بمتوسط مرجّح أو بطريقة الوارد أولاً صادر أولاً.';

    public function __construct(
        private readonly InvoiceRepository $invoices = new InvoiceRepository(),
        private readonly InventoryRepository $items = new InventoryRepository(),
        private readonly CustomerRepository $customers = new CustomerRepository(),
        private readonly PipelineRepository $pipeline = new PipelineRepository(),
    ) {
    }

    /**
     * لوحة التقارير | The report dashboard for a period.
     *
     * @return array<string,mixed>
     */
    public function overview(int $organizationId, string $from, string $to): array
    {
        [$from, $to] = $this->normalizePeriod($from, $to);

        return [
            'period'      => ['from' => $from, 'to' => $to],
            'sales'       => $this->sales($organizationId, $from, $to),
            'cash'        => $this->cashFlow($organizationId, $from, $to),
            'expenses'    => $this->expensesByCategory($organizationId, $from, $to),
            'receivables' => $this->receivables($organizationId),
            'stock'       => $this->stock($organizationId),
            'customers'   => $this->customers->topByRevenue($organizationId, $from, $to),
            'pipeline'    => $this->pipeline->pipelineSummary($organizationId),
            'disclaimer'  => self::DISCLAIMER_AR,
        ];
    }

    /**
     * ملخّص المبيعات | Sales summary.
     *
     * **المسودات والملغاة مستبعدتان.** الملغاة بيع لم يتمّ، والمسودة بيع لم
     * يبدأ أصلاً: ورقة داخلية قابلة للتعديل والحذف. عدّ أيٍّ منهما إيراداً يضخّم
     * الرقم الذي يبني عليه صاحب المشروع قراره بالتوسّع.
     *
     * @return array<string,mixed>
     */
    public function sales(int $organizationId, string $from, string $to): array
    {
        $totals = Database::selectOne(
            "SELECT COUNT(*) AS invoice_count,
                    COALESCE(SUM(subtotal), 0) AS subtotal,
                    COALESCE(SUM(discount_amount), 0) AS discounts,
                    COALESCE(SUM(vat_amount), 0) AS vat,
                    COALESCE(SUM(total), 0) AS total,
                    COALESCE(SUM(amount_paid), 0) AS collected
               FROM erp_invoices
              WHERE organization_id = ? AND deleted_at IS NULL
                AND status NOT IN ('cancelled','draft')
                AND issue_date BETWEEN ? AND ?",
            [$organizationId, $from, $to],
        );

        $monthly = Database::select(
            "SELECT DATE_FORMAT(issue_date, '%Y-%m') AS month,
                    COUNT(*) AS invoice_count,
                    COALESCE(SUM(total), 0) AS total
               FROM erp_invoices
              WHERE organization_id = ? AND deleted_at IS NULL
                AND status NOT IN ('cancelled','draft')
                AND issue_date BETWEEN ? AND ?
           GROUP BY month
           ORDER BY month ASC",
            [$organizationId, $from, $to],
        );

        $topItems = Database::select(
            "SELECT li.name_ar,
                    COALESCE(SUM(li.quantity), 0) AS quantity,
                    COALESCE(SUM(li.line_total), 0) AS revenue
               FROM erp_invoice_items li
               JOIN erp_invoices v ON v.id = li.invoice_id
              WHERE v.organization_id = ? AND v.deleted_at IS NULL
                AND v.status NOT IN ('cancelled','draft')
                AND v.issue_date BETWEEN ? AND ?
           GROUP BY li.name_ar
           ORDER BY revenue DESC
              LIMIT 10",
            [$organizationId, $from, $to],
        );

        return [
            'totals'     => $totals ?? [],
            'monthly'    => $monthly,
            'top_items'  => $topItems,
            'disclaimer' => self::DISCLAIMER_AR,
        ];
    }

    /**
     * التدفّق النقدي | Cash movement, strictly cash-basis.
     *
     * الداخل من إيصالات القبض بتاريخ القبض، والخارج من المصروفات بتاريخ الصرف.
     * **ليس قائمة دخل:** لا يعرف تكلفة البضاعة المباعة ولا الإهلاك ولا
     * المستحقّات، ولا يدّعي أن الفرق «ربح».
     *
     * @return array<string,mixed>
     */
    public function cashFlow(int $organizationId, string $from, string $to): array
    {
        $in = (float) Database::scalar(
            'SELECT COALESCE(SUM(p.amount), 0)
               FROM erp_payments p
               JOIN erp_invoices v ON v.id = p.invoice_id
              WHERE p.organization_id = ? AND v.deleted_at IS NULL
                AND p.paid_at BETWEEN ? AND ?',
            [$organizationId, $from, $to],
        );

        $out = (float) Database::scalar(
            'SELECT COALESCE(SUM(amount), 0)
               FROM erp_expenses
              WHERE organization_id = ? AND deleted_at IS NULL
                AND spent_at BETWEEN ? AND ?',
            [$organizationId, $from, $to],
        );

        return [
            'received'   => $in,
            'spent'      => $out,
            'net'        => round($in - $out, 2),
            'note_ar'    => 'الفرق أعلاه صافي حركة نقدية للفترة، وليس ربحاً: '
                          . 'لا يتضمّن تكلفة البضاعة المباعة ولا الإهلاك ولا المستحقّات.',
            'disclaimer' => self::DISCLAIMER_AR,
        ];
    }

    /**
     * المصروفات ببنودها | Expenses by category.
     *
     * @return array<string,mixed>
     */
    public function expensesByCategory(int $organizationId, string $from, string $to): array
    {
        $rows = Database::select(
            'SELECT category, COUNT(*) AS entry_count, COALESCE(SUM(amount), 0) AS total
               FROM erp_expenses
              WHERE organization_id = ? AND deleted_at IS NULL
                AND spent_at BETWEEN ? AND ?
           GROUP BY category
           ORDER BY total DESC',
            [$organizationId, $from, $to],
        );

        $total = 0.0;

        foreach ($rows as $row) {
            $total += (float) $row['total'];
        }

        return [
            'rows'       => $rows,
            'total'      => round($total, 2),
            'disclaimer' => self::DISCLAIMER_AR,
        ];
    }

    /**
     * الذمم المدينة | Receivables with ageing.
     *
     * @return array<string,mixed>
     */
    public function receivables(int $organizationId): array
    {
        $buckets = $this->invoices->ageing($organizationId);

        $total = 0.0;

        foreach ($buckets as $bucket) {
            $total += $bucket['amount'];
        }

        $overdue = Database::select(
            "SELECT v.id, v.invoice_number, v.customer_name_ar, v.due_date,
                    (v.total - v.amount_paid) AS balance_due,
                    DATEDIFF(CURDATE(), v.due_date) AS days_overdue
               FROM erp_invoices v
              WHERE v.organization_id = ? AND v.deleted_at IS NULL
                AND v.status IN ('issued','partially_paid')
                AND v.due_date IS NOT NULL AND v.due_date < CURDATE()
           ORDER BY v.due_date ASC
              LIMIT 25",
            [$organizationId],
        );

        return [
            'buckets'      => $buckets,
            'total'        => round($total, 2),
            'overdue_list' => $overdue,
            'disclaimer'   => self::DISCLAIMER_AR,
        ];
    }

    /**
     * حالة المخزون | Stock position.
     *
     * @return array<string,mixed>
     */
    public function stock(int $organizationId): array
    {
        $valuation = $this->items->valuation($organizationId);

        return [
            'valuation'  => $valuation,
            'low_stock'  => $this->items->lowStock($organizationId, 20),
            'note_ar'    => self::STOCK_NOTE_AR,
            'disclaimer' => self::DISCLAIMER_AR,
        ];
    }

    /**
     * حدود الفترة | Normalize the requested period.
     *
     * فترة مقلوبة تُصحَّح بدل رفضها: صاحب مشروع اختار التاريخين بالعكس يريد
     * تقريراً لا رسالة خطأ.
     *
     * @return array{0:string,1:string}
     */
    private function normalizePeriod(string $from, string $to): array
    {
        $fromTime = strtotime($from);
        $toTime   = strtotime($to);

        $from = $fromTime === false ? date('Y-m-01') : date('Y-m-d', $fromTime);
        $to   = $toTime === false ? date('Y-m-d') : date('Y-m-d', $toTime);

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    /** الفترة الافتراضية: الشهر الجاري | Default period: the current month. */
    public function defaultPeriod(): array
    {
        return ['from' => date('Y-m-01'), 'to' => date('Y-m-d')];
    }
}
