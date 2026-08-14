<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database;
use App\Services\CustomerService;
use App\Services\ExpenseService;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\PipelineService;
use App\Services\PurchaseOrderService;

/**
 * بيانات العملاء والموارد للعرض | Demonstration CRM and ERP data (§15).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠ **كل ما هنا بيانات تجريبية.** العملاء والأصناف والفواتير والمصروفات جميعها
 * مُختلقة لعرض المنصة، ولا تمثّل أي جهة أو معاملة قائمة.
 *
 * البذرة تمرّ **بالخدمات نفسها** لا بإدراج مباشر في الجداول: الرصيد يُبنى من
 * حركات دفتر حقيقية، والفواتير تمرّ بدورة الإصدار كاملةً. لو زرعنا الأرقام
 * مباشرةً لأنتجنا بيانات عرض لا يمكن أن ينتجها الاستخدام الفعلي — وهي أسوأ
 * أنواع بيانات العرض، لأنها تُخفي عيوب المسار الحقيقي.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class DemoBusinessDataSeeder extends Seeder
{
    public function isDemo(): bool
    {
        return true;
    }

    public function order(): int
    {
        return 85;
    }

    public function run(): void
    {
        $this->guardProduction();

        $organizationId = $this->demoSmeOrganization();

        if ($organizationId === null) {
            $this->info('لا توجد منشأة مشروع تجريبية — تُخطّى بذرة العملاء والموارد.');

            return;
        }

        if ($this->alreadySeeded($organizationId)) {
            $this->info('بيانات العملاء والموارد التجريبية موجودة بالفعل.');

            return;
        }

        $actorId = $this->demoOwner($organizationId);

        $customers = $this->seedCustomers($organizationId);
        $items     = $this->seedItems($organizationId, $actorId);
        $this->seedLeadsAndPipeline($organizationId, $customers, $actorId);
        $this->seedPurchasing($organizationId, $items, $actorId);
        $invoices = $this->seedInvoices($organizationId, $customers, $items, $actorId);
        $this->seedExpenses($organizationId, $actorId);

        $this->info(
            count($customers) . ' عملاء و' . count($items) . ' أصناف و'
            . $invoices . ' فواتير — كلها بيانات تجريبية.',
        );
    }

    // ═══════════════════ العملاء | Customers ═══════════════════

    /** @return array<int,int> */
    private function seedCustomers(int $organizationId): array
    {
        $service = new CustomerService();
        $ids     = [];

        $rows = [
            ['name_ar' => 'شركة النيل للأثاث المكتبي (بيانات تجريبية)', 'customer_type' => 'company',
             'phone' => '0201000000001', 'source' => 'marketplace', 'credit_limit' => 50000],
            ['name_ar' => 'مؤسسة الدلتا للتوريدات (بيانات تجريبية)', 'customer_type' => 'company',
             'phone' => '0201000000002', 'source' => 'referral', 'credit_limit' => 25000],
            ['name_ar' => 'محمود عبد الرحمن (بيانات تجريبية)', 'customer_type' => 'individual',
             'phone' => '0201000000003', 'source' => 'walk_in'],
            ['name_ar' => 'جمعية تنمية المجتمع بالمنيا (بيانات تجريبية)', 'customer_type' => 'ngo',
             'phone' => '0201000000004', 'source' => 'event'],
        ];

        foreach ($rows as $row) {
            $ids[] = $service->create($organizationId, $row);
        }

        $service->addContact($ids[0], $organizationId, [
            'name_ar'      => 'هالة سمير (بيانات تجريبية)',
            'job_title_ar' => 'مديرة المشتريات',
            'phone'        => '0201000000011',
            'is_primary'   => 1,
        ]);

        return $ids;
    }

    // ═══════════════════ الأصناف | Items ═══════════════════

    /** @return array<int,int> */
    private function seedItems(int $organizationId, ?int $actorId): array
    {
        $service = new InventoryService();
        $ids     = [];

        $rows = [
            ['sku' => 'DEMO-CHR-01', 'name_ar' => 'كرسي مكتب دوّار (تجريبي)',
             'unit_of_measure' => 'قطعة', 'cost_price' => 480, 'sale_price' => 750,
             'vat_rate' => 14, 'reorder_level' => 10, 'opening_quantity' => 60],
            ['sku' => 'DEMO-DSK-01', 'name_ar' => 'مكتب خشبي ١٢٠سم (تجريبي)',
             'unit_of_measure' => 'قطعة', 'cost_price' => 1100, 'sale_price' => 1800,
             'vat_rate' => 14, 'reorder_level' => 5, 'opening_quantity' => 18],
            ['sku' => 'DEMO-WD-01', 'name_ar' => 'ألواح خشب MDF (تجريبي)',
             'item_type' => 'raw_material', 'unit_of_measure' => 'لوح',
             'cost_price' => 210, 'reorder_level' => 40, 'opening_quantity' => 35],
            ['sku' => 'DEMO-INS-01', 'name_ar' => 'خدمة التركيب بالموقع (تجريبي)',
             'item_type' => 'service', 'unit_of_measure' => 'زيارة', 'sale_price' => 350],
        ];

        foreach ($rows as $row) {
            $ids[] = $service->create($organizationId, $row, $actorId);
        }

        // حركة تالف تُظهر أن الدفتر يفسّر كل فرق في الرصيد
        $service->record(
            itemId: $ids[1],
            organizationId: $organizationId,
            type: 'damage',
            delta: 2,
            actorUserId: $actorId,
            reason: 'تلف سطح مكتبين أثناء التخزين (بيانات تجريبية).',
        );

        return $ids;
    }

    // ═══════════════════ المهتمّون والفرص | Leads and pipeline ═══════════════════

    /** @param array<int,int> $customers */
    private function seedLeadsAndPipeline(int $organizationId, array $customers, ?int $actorId): void
    {
        $service = new PipelineService();

        $leadId = $service->createLead($organizationId, [
            'name_ar'     => 'مدرسة النور الخاصة (بيانات تجريبية)',
            'phone'       => '0201000000021',
            'source'      => 'referral',
            'interest_ar' => 'تجهيز فصول دراسية بمكاتب وكراسي.',
        ], $actorId);

        $service->updateLeadStatus($leadId, $organizationId, 'contacted', null);

        $lost = $service->createLead($organizationId, [
            'name_ar'     => 'شركة الوادي (بيانات تجريبية)',
            'source'      => 'social',
            'interest_ar' => 'استفسار عن أسعار الجملة.',
        ], $actorId);

        $service->updateLeadStatus($lost, $organizationId, 'lost', 'اختار مورّداً أقرب لموقعه.');

        $opportunity = $service->createOpportunity($organizationId, [
            'customer_id'         => $customers[0],
            'title_ar'            => 'تجهيز مقر إداري جديد (بيانات تجريبية)',
            'description_ar'      => 'توريد ٤٠ كرسياً و٢٠ مكتباً على دفعتين.',
            'expected_value'      => 62000,
            'probability'         => 60,
            'expected_close_date' => date('Y-m-d', strtotime('+21 days')),
        ], $actorId);

        $service->moveStage($opportunity, $organizationId, 'qualified');
        $service->moveStage($opportunity, $organizationId, 'proposal');

        $service->logActivity($organizationId, [
            'opportunity_id' => $opportunity,
            'activity_type'  => 'meeting',
            'subject_ar'     => 'زيارة الموقع وقياس المساحات',
            'body_ar'        => 'اتُّفق على التوريد على دفعتين (بيانات تجريبية).',
        ], $actorId);

        $service->logActivity($organizationId, [
            'customer_id'   => $customers[0],
            'activity_type' => 'task',
            'subject_ar'    => 'متابعة اعتماد عرض السعر',
            'status'        => 'planned',
            'due_at'        => date('Y-m-d H:i:s', strtotime('+3 days')),
        ], $actorId);

        // مهمة فات موعدها لتظهر القائمة مرتّبة بالمتأخّر أولاً
        $service->logActivity($organizationId, [
            'customer_id'   => $customers[1],
            'activity_type' => 'call',
            'subject_ar'    => 'تذكير بسداد الدفعة الثانية',
            'status'        => 'planned',
            'due_at'        => date('Y-m-d H:i:s', strtotime('-2 days')),
        ], $actorId);
    }

    // ═══════════════════ المشتريات | Purchasing ═══════════════════

    /** @param array<int,int> $items */
    private function seedPurchasing(int $organizationId, array $items, ?int $actorId): void
    {
        $service = new PurchaseOrderService();

        $supplierId = $service->createSupplier($organizationId, [
            'name_ar'           => 'مصنع الشرق للأخشاب (بيانات تجريبية)',
            'contact_person_ar' => 'سامي فؤاد',
            'phone'             => '0201000000031',
            'payment_terms_ar'  => 'سداد خلال ٣٠ يوماً من التوريد',
        ]);

        $po   = $service->create($organizationId, [
            'supplier_id'   => $supplierId,
            'order_date'    => date('Y-m-d', strtotime('-12 days')),
            'expected_date' => date('Y-m-d', strtotime('-5 days')),
        ], $actorId);

        $line = $service->addLine($po['id'], $organizationId, [
            'item_id'          => $items[2],
            'quantity_ordered' => 100,
            'unit_cost'        => 210,
            'vat_rate'         => 14,
        ]);

        $service->send($po['id'], $organizationId);

        // استلام جزئي: يُظهر أن الحالة تتبع البنود وأن المخزون يزيد بالاستلام
        $service->receive(
            $po['id'],
            $organizationId,
            [['line_id' => $line, 'quantity' => 60]],
            $actorId,
        );
    }

    // ═══════════════════ الفواتير | Invoices ═══════════════════

    /**
     * @param  array<int,int> $customers
     * @param  array<int,int> $items
     */
    private function seedInvoices(int $organizationId, array $customers, array $items, ?int $actorId): int
    {
        $service = new InvoiceService();
        $count   = 0;

        // فاتورة مسدَّدة بالكامل
        $paid = $service->create($organizationId, [
            'customer_id' => $customers[0],
            'issue_date'  => date('Y-m-d', strtotime('-40 days')),
            'due_date'    => date('Y-m-d', strtotime('-25 days')),
        ], $actorId);
        $service->addLine($paid['id'], $organizationId, ['item_id' => $items[0], 'quantity' => 12]);
        $service->addLine($paid['id'], $organizationId, ['item_id' => $items[3], 'quantity' => 1]);
        $service->issue($paid['id'], $organizationId, $actorId);
        $total = (float) Database::scalar('SELECT total FROM erp_invoices WHERE id = ?', [$paid['id']]);
        $service->recordPayment($paid['id'], $organizationId, [
            'amount'  => $total,
            'method'  => 'bank_transfer',
            'paid_at' => date('Y-m-d', strtotime('-24 days')),
        ], $actorId);
        $count++;

        // فاتورة متأخّرة مسدَّدة جزئياً — تُغذّي تقرير أعمار الذمم
        $overdue = $service->create($organizationId, [
            'customer_id' => $customers[1],
            'issue_date'  => date('Y-m-d', strtotime('-55 days')),
            'due_date'    => date('Y-m-d', strtotime('-40 days')),
        ], $actorId);
        $service->addLine($overdue['id'], $organizationId, ['item_id' => $items[1], 'quantity' => 4]);
        $service->issue($overdue['id'], $organizationId, $actorId);
        $service->recordPayment($overdue['id'], $organizationId, [
            'amount'  => 2000,
            'method'  => 'cash',
            'paid_at' => date('Y-m-d', strtotime('-30 days')),
        ], $actorId);
        $count++;

        // فاتورة صادرة غير مستحقّة بعد
        $open = $service->create($organizationId, [
            'customer_id' => $customers[2],
            'issue_date'  => date('Y-m-d', strtotime('-4 days')),
            'due_date'    => date('Y-m-d', strtotime('+11 days')),
        ], $actorId);
        $service->addLine($open['id'], $organizationId, ['item_id' => $items[0], 'quantity' => 3]);
        $service->issue($open['id'], $organizationId, $actorId);
        $count++;

        // مسودة تُظهر الفرق بين ما يُحرَّر وما لا يُحرَّر
        $draft = $service->create($organizationId, [
            'customer_id' => $customers[3],
            'issue_date'  => date('Y-m-d'),
        ], $actorId);
        $service->addLine($draft['id'], $organizationId, ['item_id' => $items[1], 'quantity' => 2]);
        $count++;

        return $count;
    }

    // ═══════════════════ المصروفات | Expenses ═══════════════════

    private function seedExpenses(int $organizationId, ?int $actorId): void
    {
        $service = new ExpenseService();

        $rows = [
            ['category' => 'rent', 'description_ar' => 'إيجار الورشة (بيانات تجريبية)',
             'amount' => 6000, 'spent_at' => date('Y-m-d', strtotime('-28 days'))],
            ['category' => 'utilities', 'description_ar' => 'كهرباء ومياه (بيانات تجريبية)',
             'amount' => 1450, 'spent_at' => date('Y-m-d', strtotime('-20 days'))],
            ['category' => 'salaries', 'description_ar' => 'أجور العمالة (بيانات تجريبية)',
             'amount' => 12000, 'spent_at' => date('Y-m-d', strtotime('-15 days'))],
            ['category' => 'transport', 'description_ar' => 'نقل وتوصيل الطلبات (بيانات تجريبية)',
             'amount' => 900, 'spent_at' => date('Y-m-d', strtotime('-9 days'))],
            ['category' => 'marketing', 'description_ar' => 'إعلان ممول (بيانات تجريبية)',
             'amount' => 700, 'spent_at' => date('Y-m-d', strtotime('-3 days'))],
        ];

        foreach ($rows as $row) {
            $service->create($organizationId, $row, $actorId);
        }
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    private function demoSmeOrganization(): ?int
    {
        $id = Database::scalar(
            "SELECT o.id FROM organizations o
               JOIN organization_types t ON t.id = o.organization_type_id
              WHERE t.code = 'sme' AND o.deleted_at IS NULL
           ORDER BY o.id ASC LIMIT 1",
        );

        return $id === null ? null : (int) $id;
    }

    private function demoOwner(int $organizationId): ?int
    {
        $id = Database::scalar(
            "SELECT user_id FROM organization_members
              WHERE organization_id = ? AND status = 'active'
           ORDER BY id ASC LIMIT 1",
            [$organizationId],
        );

        return $id === null ? null : (int) $id;
    }

    /** البذرة لا تُكرّر نفسها | The seeder does not duplicate itself. */
    private function alreadySeeded(int $organizationId): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM crm_customers WHERE organization_id = ?',
            [$organizationId],
        ) > 0;
    }
}
