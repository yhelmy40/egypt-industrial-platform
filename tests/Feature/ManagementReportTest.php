<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CustomerService;
use App\Services\ExpenseService;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\ManagementReportService;
use Tests\TestCase;

/**
 * التقارير الإدارية | Management reports (§4.10, §14).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * شرط صريح في المواصفة: **مخرجات وحدة إدارة الموارد تقارير إدارية يجب أن
 * يراجعها محاسب مؤهّل، ويجب عرض تنويه بذلك.**
 *
 * هذه المجموعة تثبت أن التنويه **جزء من مخرجات الخدمة لا نصّ في قالب**: كل
 * تقرير يعيده `ManagementReportService` يحمل `disclaimer`، فلا يمكن أن يُبنى
 * تقرير جديد وينسى مطوّر أن يكتب التنويه في قالبه.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class ManagementReportTest extends TestCase
{
    private ManagementReportService $reports;

    private InvoiceService $invoices;

    private ExpenseService $expenses;

    private InventoryService $inventory;

    private CustomerService $customers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reports   = new ManagementReportService();
        $this->invoices  = new InvoiceService();
        $this->expenses  = new ExpenseService();
        $this->inventory = new InventoryService();
        $this->customers = new CustomerService();
    }

    // ═══════════════════ التنويه الإلزامي | The mandatory disclaimer ═══════════════════

    /** كل تقرير يحمل التنويه | Every report carries the disclaimer. */
    public function test_every_report_carries_the_accountant_disclaimer(): void
    {
        $orgId          = $this->createOrganization(['status' => 'verified']);
        [$from, $to]    = array_values($this->reports->defaultPeriod());

        $reports = [
            'overview'    => $this->reports->overview($orgId, $from, $to),
            'sales'       => $this->reports->sales($orgId, $from, $to),
            'cash'        => $this->reports->cashFlow($orgId, $from, $to),
            'expenses'    => $this->reports->expensesByCategory($orgId, $from, $to),
            'receivables' => $this->reports->receivables($orgId),
            'stock'       => $this->reports->stock($orgId),
        ];

        foreach ($reports as $name => $report) {
            $this->assertArrayHasKey('disclaimer', $report, "التقرير «{$name}» بلا تنويه.");
            $this->assertSame(ManagementReportService::DISCLAIMER_AR, $report['disclaimer']);
        }
    }

    /** نصّ التنويه يذكر ما تشترطه المواصفة | The disclaimer says what the spec requires. */
    public function test_the_disclaimer_states_the_required_facts(): void
    {
        $text = ManagementReportService::DISCLAIMER_AR;

        $this->assertStringContainsString('تقارير إدارية', $text);
        $this->assertStringContainsString('محاسب مؤهّل', $text);
        $this->assertStringContainsString('ليست قوائم مالية معتمدة', $text);
        $this->assertStringContainsString('القيد المزدوج', $text);
    }

    /** صافي النقدية لا يُسمّى ربحاً | The cash net is never labelled profit. */
    public function test_the_cash_net_is_not_presented_as_profit(): void
    {
        $orgId       = $this->createOrganization(['status' => 'verified']);
        [$from, $to] = array_values($this->reports->defaultPeriod());

        $cash = $this->reports->cashFlow($orgId, $from, $to);

        $this->assertArrayHasKey('note_ar', $cash);
        $this->assertStringContainsString('ليس ربحاً', $cash['note_ar']);
        $this->assertStringContainsString('تكلفة البضاعة المباعة', $cash['note_ar']);
    }

    /** تقدير المخزون موسوم بأنه تقدير | The stock valuation is labelled an estimate. */
    public function test_the_stock_valuation_is_labelled_an_estimate(): void
    {
        $orgId = $this->createOrganization(['status' => 'verified']);
        $stock = $this->reports->stock($orgId);

        $this->assertStringContainsString('تقدير', $stock['note_ar']);
        $this->assertStringContainsString('وليست تقييماً محاسبياً', $stock['note_ar']);
    }

    // ═══════════════════ صحّة الأرقام | The numbers themselves ═══════════════════

    /** الفاتورة الملغاة ليست إيراداً | A cancelled invoice is not revenue. */
    public function test_a_cancelled_invoice_is_excluded_from_sales(): void
    {
        $orgId = $this->createOrganization(['status' => 'verified']);

        $kept = $this->invoices->create($orgId, ['customer_name_ar' => 'عميل'], null)['id'];
        $this->invoices->addLine($kept, $orgId, ['name_ar' => 'بند', 'quantity' => 1, 'unit_price' => 300]);
        $this->invoices->issue($kept, $orgId, null);

        $voided = $this->invoices->create($orgId, ['customer_name_ar' => 'عميل'], null)['id'];
        $this->invoices->addLine($voided, $orgId, ['name_ar' => 'بند', 'quantity' => 1, 'unit_price' => 900]);
        $this->invoices->issue($voided, $orgId, null);
        $this->invoices->cancel($voided, $orgId, 'أُصدرت بالخطأ لعميل غير صحيح.', null);

        [$from, $to] = array_values($this->reports->defaultPeriod());
        $sales       = $this->reports->sales($orgId, $from, $to);

        $this->assertSame(1, (int) $sales['totals']['invoice_count']);
        $this->assertSame('300.00', (string) $sales['totals']['total']);
    }

    /**
     * المسودة ليست بيعاً | A draft is not a sale.
     *
     * المسودة ورقة داخلية قابلة للتعديل والحذف، وعدّها إيراداً يضخّم الرقم الذي
     * يبني عليه صاحب المشروع قراره بالتوسّع.
     */
    public function test_a_draft_invoice_is_excluded_from_sales(): void
    {
        $orgId = $this->createOrganization(['status' => 'verified']);

        $issued = $this->invoices->create($orgId, ['customer_name_ar' => 'عميل'], null)['id'];
        $this->invoices->addLine($issued, $orgId, ['name_ar' => 'بند', 'quantity' => 1, 'unit_price' => 200]);
        $this->invoices->issue($issued, $orgId, null);

        // مسودة بمبلغ كبير لا يجوز أن تظهر في الإيراد
        $draft = $this->invoices->create($orgId, ['customer_name_ar' => 'عميل'], null)['id'];
        $this->invoices->addLine($draft, $orgId, ['name_ar' => 'بند', 'quantity' => 1, 'unit_price' => 9000]);

        [$from, $to] = array_values($this->reports->defaultPeriod());
        $sales       = $this->reports->sales($orgId, $from, $to);

        $this->assertSame(1, (int) $sales['totals']['invoice_count']);
        $this->assertSame('200.00', (string) $sales['totals']['total']);

        // ولا في أكثر البنود إيراداً
        $this->assertCount(1, $sales['top_items']);
        $this->assertSame('200.00', (string) $sales['top_items'][0]['revenue']);
    }

    /** المسودة لا تدخل ملخّص العميل | A draft stays out of the customer summary. */
    public function test_a_draft_invoice_is_excluded_from_the_customer_summary(): void
    {
        $orgId      = $this->createOrganization(['status' => 'verified']);
        $customerId = $this->customers->create($orgId, ['name_ar' => 'عميل']);

        $draft = $this->invoices->create($orgId, ['customer_id' => $customerId], null)['id'];
        $this->invoices->addLine($draft, $orgId, ['name_ar' => 'بند', 'quantity' => 1, 'unit_price' => 5000]);

        $summary = (new \App\Repositories\CustomerRepository())->summary($customerId, $orgId);

        $this->assertSame('0.00', (string) $summary['invoiced_total']);
        $this->assertNull($summary['last_invoice_date']);

        [$from, $to] = array_values($this->reports->defaultPeriod());
        $this->assertSame([], $this->reports->overview($orgId, $from, $to)['customers']);
    }

    /** الداخل من المقبوضات والخارج من المصروفات | Cash in from receipts, out from expenses. */
    public function test_cash_flow_counts_receipts_and_expenses(): void
    {
        $orgId     = $this->createOrganization(['status' => 'verified']);
        $invoiceId = $this->invoices->create($orgId, ['customer_name_ar' => 'عميل'], null)['id'];

        $this->invoices->addLine($invoiceId, $orgId, ['name_ar' => 'بند', 'quantity' => 1, 'unit_price' => 1000]);
        $this->invoices->issue($invoiceId, $orgId, null);
        $this->invoices->recordPayment($invoiceId, $orgId, ['amount' => 600], null);

        $this->expenses->create($orgId, ['description_ar' => 'إيجار', 'amount' => 250], null);

        [$from, $to] = array_values($this->reports->defaultPeriod());
        $cash        = $this->reports->cashFlow($orgId, $from, $to);

        // الفاتورة 1000 لكن الداخل 600: الأساس نقدي لا استحقاق
        $this->assertEqualsWithDelta(600.0, $cash['received'], 0.01);
        $this->assertEqualsWithDelta(250.0, $cash['spent'], 0.01);
        $this->assertEqualsWithDelta(350.0, $cash['net'], 0.01);
    }

    /** الذمم تُصنَّف بأعمارها | Receivables fall into ageing buckets. */
    public function test_receivables_are_bucketed_by_age(): void
    {
        $orgId = $this->createOrganization(['status' => 'verified']);

        // فاتورة غير مستحقّة بعد
        $future = $this->invoices->create($orgId, [
            'customer_name_ar' => 'عميل',
            'due_date'         => date('Y-m-d', time() + 86400 * 20),
        ], null)['id'];
        $this->invoices->addLine($future, $orgId, ['name_ar' => 'بند', 'quantity' => 1, 'unit_price' => 100]);
        $this->invoices->issue($future, $orgId, null);

        // فاتورة متأخّرة 45 يوماً
        $late = $this->invoices->create($orgId, [
            'customer_name_ar' => 'عميل',
            'issue_date'       => date('Y-m-d', time() - 86400 * 60),
            'due_date'         => date('Y-m-d', time() - 86400 * 45),
        ], null)['id'];
        $this->invoices->addLine($late, $orgId, ['name_ar' => 'بند', 'quantity' => 1, 'unit_price' => 700]);
        $this->invoices->issue($late, $orgId, null);

        $receivables = $this->reports->receivables($orgId);

        $this->assertEqualsWithDelta(100.0, $receivables['buckets']['not_due']['amount'], 0.01);
        $this->assertEqualsWithDelta(700.0, $receivables['buckets']['d31_60']['amount'], 0.01);
        $this->assertEqualsWithDelta(800.0, $receivables['total'], 0.01);
        $this->assertCount(1, $receivables['overdue_list']);
    }

    /** المسدَّد بالكامل يخرج من الذمم | A fully paid invoice leaves the receivables. */
    public function test_a_paid_invoice_leaves_the_receivables(): void
    {
        $orgId     = $this->createOrganization(['status' => 'verified']);
        $invoiceId = $this->invoices->create($orgId, ['customer_name_ar' => 'عميل'], null)['id'];

        $this->invoices->addLine($invoiceId, $orgId, ['name_ar' => 'بند', 'quantity' => 1, 'unit_price' => 400]);
        $this->invoices->issue($invoiceId, $orgId, null);
        $this->invoices->recordPayment($invoiceId, $orgId, ['amount' => 400], null);

        $this->assertEqualsWithDelta(0.0, $this->reports->receivables($orgId)['total'], 0.01);
    }

    /** تقدير المخزون يُعلن الأصناف بلا تكلفة | The valuation declares items with no known cost. */
    public function test_the_valuation_declares_items_without_a_known_cost(): void
    {
        $orgId = $this->createOrganization(['status' => 'verified']);

        $this->inventory->create($orgId, [
            'sku' => 'A', 'name_ar' => 'صنف بتكلفة',
            'cost_price' => 20.00, 'opening_quantity' => 10,
        ], null);

        $this->inventory->create($orgId, [
            'sku' => 'B', 'name_ar' => 'صنف بلا تكلفة', 'opening_quantity' => 5,
        ], null);

        $valuation = $this->reports->stock($orgId)['valuation'];

        $this->assertSame(2, (int) $valuation['item_count']);
        $this->assertEqualsWithDelta(200.0, (float) $valuation['total_cost'], 0.01);

        // الصنف بلا تكلفة معلن لا مدسوس بصفر
        $this->assertSame(1, (int) $valuation['items_without_cost']);
    }

    /** التقارير لا تخلط المنشآت | Reports never mix organizations. */
    public function test_reports_never_mix_organizations(): void
    {
        $orgA = $this->createOrganization(['status' => 'verified']);
        $orgB = $this->createOrganization(['status' => 'verified']);

        foreach ([$orgA => 500, $orgB => 900] as $orgId => $amount) {
            $invoiceId = $this->invoices->create($orgId, ['customer_name_ar' => 'عميل'], null)['id'];
            $this->invoices->addLine($invoiceId, $orgId, [
                'name_ar' => 'بند', 'quantity' => 1, 'unit_price' => $amount,
            ]);
            $this->invoices->issue($invoiceId, $orgId, null);
        }

        [$from, $to] = array_values($this->reports->defaultPeriod());

        $this->assertSame('500.00', (string) $this->reports->sales($orgA, $from, $to)['totals']['total']);
        $this->assertSame('900.00', (string) $this->reports->sales($orgB, $from, $to)['totals']['total']);
    }

    /** الفترة المقلوبة تُصحَّح | A reversed period is corrected, not refused. */
    public function test_a_reversed_period_is_corrected(): void
    {
        $orgId = $this->createOrganization(['status' => 'verified']);

        $overview = $this->reports->overview($orgId, '2026-08-31', '2026-08-01');

        $this->assertSame('2026-08-01', $overview['period']['from']);
        $this->assertSame('2026-08-31', $overview['period']['to']);
    }

    /** أفضل العملاء يقيسون الإيراد المفوتَر | Top customers rank by invoiced value. */
    public function test_top_customers_rank_by_invoiced_value(): void
    {
        $orgId = $this->createOrganization(['status' => 'verified']);

        $big   = $this->customers->create($orgId, ['name_ar' => 'عميل كبير']);
        $small = $this->customers->create($orgId, ['name_ar' => 'عميل صغير']);

        foreach ([[$big, 5000], [$small, 300]] as [$customerId, $amount]) {
            $invoiceId = $this->invoices->create($orgId, ['customer_id' => $customerId], null)['id'];
            $this->invoices->addLine($invoiceId, $orgId, [
                'name_ar' => 'بند', 'quantity' => 1, 'unit_price' => $amount,
            ]);
            $this->invoices->issue($invoiceId, $orgId, null);
        }

        [$from, $to] = array_values($this->reports->defaultPeriod());
        $top         = $this->reports->overview($orgId, $from, $to)['customers'];

        $this->assertCount(2, $top);
        $this->assertSame('عميل كبير', $top[0]['name_ar']);
        $this->assertSame('5000.00', (string) $top[0]['revenue']);
    }
}
