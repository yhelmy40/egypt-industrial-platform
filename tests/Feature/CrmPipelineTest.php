<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Repositories\CustomerRepository;
use App\Repositories\PipelineRepository;
use App\Services\CustomerService;
use App\Services\InvoiceService;
use App\Services\PipelineService;
use Tests\TestCase;

/**
 * العملاء وخطّ الفرص | Customers and the sales pipeline (§4.9).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ثلاث قواعد محروسة: **المهتمّ ليس عميلاً**، و**التحويل مرة واحدة**،
 * و**الخسارة تستوجب سبباً**.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class CrmPipelineTest extends TestCase
{
    private CustomerService $customers;

    private CustomerRepository $customerRepo;

    private PipelineService $pipeline;

    private PipelineRepository $pipelineRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customers    = new CustomerService();
        $this->customerRepo = new CustomerRepository();
        $this->pipeline     = new PipelineService();
        $this->pipelineRepo = new PipelineRepository();
    }

    // ═══════════════════ العملاء | Customers ═══════════════════

    /** الكود يُولَّد حين لا يُكتب | A code is generated when none is given. */
    public function test_a_customer_code_is_generated_when_omitted(): void
    {
        $orgId = $this->createOrganization(['status' => 'verified']);

        $first  = $this->customers->create($orgId, ['name_ar' => 'عميل أول']);
        $second = $this->customers->create($orgId, ['name_ar' => 'عميل ثانٍ']);

        $this->assertSame('CUS-0001', $this->customerRepo->findOwned($first, $orgId)['code']);
        $this->assertSame('CUS-0002', $this->customerRepo->findOwned($second, $orgId)['code']);
    }

    /** الكود فريد داخل المنشأة لا عبرها | The code is unique per organization only. */
    public function test_the_customer_code_is_unique_within_the_organization(): void
    {
        $orgA = $this->createOrganization(['status' => 'verified']);
        $orgB = $this->createOrganization(['status' => 'verified']);

        $this->customers->create($orgA, ['name_ar' => 'عميل', 'code' => 'VIP-1']);
        $this->customers->create($orgB, ['name_ar' => 'عميل', 'code' => 'VIP-1']);

        $this->expectException(HttpException::class);
        $this->customers->create($orgA, ['name_ar' => 'مكرّر', 'code' => 'VIP-1']);
    }

    /** عميل عليه مستحقّات لا يُؤرشَف | A customer with a balance cannot be archived. */
    public function test_a_customer_with_an_outstanding_balance_cannot_be_archived(): void
    {
        $orgId      = $this->createOrganization(['status' => 'verified']);
        $customerId = $this->customers->create($orgId, ['name_ar' => 'عميل مدين']);

        $invoices  = new InvoiceService();
        $invoiceId = $invoices->create($orgId, ['customer_id' => $customerId], null)['id'];
        $invoices->addLine($invoiceId, $orgId, ['name_ar' => 'بند', 'quantity' => 1, 'unit_price' => 500]);
        $invoices->issue($invoiceId, $orgId, null);

        try {
            $this->customers->archive($customerId, $orgId);
            $this->fail('كان يجب رفض أرشفة عميل عليه مستحقّات.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('مستحقّات', $e->getMessage());
        }

        // وبعد السداد تصير الأرشفة ممكنة
        $invoices->recordPayment($invoiceId, $orgId, ['amount' => 500], null);
        $this->customers->archive($customerId, $orgId);

        $this->assertNull($this->customerRepo->findOwned($customerId, $orgId));
    }

    /** الرصيد المستحق محسوب من الفواتير | The balance is computed from the invoices. */
    public function test_the_outstanding_balance_comes_from_the_invoices(): void
    {
        $orgId      = $this->createOrganization(['status' => 'verified']);
        $customerId = $this->customers->create($orgId, ['name_ar' => 'عميل']);

        $invoices  = new InvoiceService();
        $invoiceId = $invoices->create($orgId, ['customer_id' => $customerId], null)['id'];
        $invoices->addLine($invoiceId, $orgId, ['name_ar' => 'بند', 'quantity' => 2, 'unit_price' => 150]);
        $invoices->issue($invoiceId, $orgId, null);
        $invoices->recordPayment($invoiceId, $orgId, ['amount' => 100], null);

        $summary = $this->customerRepo->summary($customerId, $orgId);

        $this->assertSame('300.00', (string) $summary['invoiced_total']);
        $this->assertSame('100.00', (string) $summary['paid_total']);
        $this->assertSame('200.00', (string) $summary['outstanding']);
    }

    /** جهة اتصال رئيسية واحدة | Only one primary contact at a time. */
    public function test_only_one_contact_stays_primary(): void
    {
        $orgId      = $this->createOrganization(['status' => 'verified']);
        $customerId = $this->customers->create($orgId, ['name_ar' => 'شركة']);

        $this->customers->addContact($customerId, $orgId, ['name_ar' => 'أول', 'is_primary' => 1]);
        $this->customers->addContact($customerId, $orgId, ['name_ar' => 'ثانٍ', 'is_primary' => 1]);

        $contacts = $this->customerRepo->contacts($customerId, $orgId);
        $primary  = array_filter($contacts, static fn (array $c): bool => (int) $c['is_primary'] === 1);

        $this->assertCount(2, $contacts);
        $this->assertCount(1, $primary);
        $this->assertSame('ثانٍ', array_values($primary)[0]['name_ar']);
    }

    /** عميل منشأة أخرى غير مرئي | Another organization's customer is invisible. */
    public function test_another_organizations_customer_is_invisible(): void
    {
        $orgId      = $this->createOrganization(['status' => 'verified']);
        $intruder   = $this->createOrganization(['status' => 'verified']);
        $customerId = $this->customers->create($orgId, ['name_ar' => 'عميل']);

        $this->assertNull($this->customerRepo->findOwned($customerId, $intruder));

        $this->expectException(HttpException::class);
        $this->customers->update($customerId, $intruder, ['name_ar' => 'اختطاف']);
    }

    // ═══════════════════ المهتمّون والتحويل | Leads and conversion ═══════════════════

    /** التحويل ينشئ عميلاً ويترك أثراً | Conversion creates a customer and leaves a trace. */
    public function test_converting_a_lead_creates_a_customer_and_records_the_link(): void
    {
        $orgId  = $this->createOrganization(['status' => 'verified']);
        $leadId = $this->pipeline->createLead($orgId, [
            'name_ar'     => 'مصنع الأمل',
            'phone'       => '01000000000',
            'source'      => 'event',
            'interest_ar' => 'يسأل عن توريد شهري.',
        ], null);

        $customerId = $this->pipeline->convertLead($leadId, $orgId);

        $lead     = $this->pipelineRepo->findLead($leadId, $orgId);
        $customer = $this->customerRepo->findOwned($customerId, $orgId);

        $this->assertSame('converted', $lead['status']);
        $this->assertSame($customerId, (int) $lead['converted_customer_id']);
        $this->assertNotNull($lead['converted_at']);

        // بيانات المهتمّ انتقلت للعميل
        $this->assertSame('مصنع الأمل', $customer['name_ar']);
        $this->assertSame('01000000000', $customer['phone']);
        $this->assertSame('event', $customer['source']);
    }

    /** التحويل مرة واحدة | A lead converts exactly once. */
    public function test_a_lead_cannot_be_converted_twice(): void
    {
        $orgId  = $this->createOrganization(['status' => 'verified']);
        $leadId = $this->pipeline->createLead($orgId, ['name_ar' => 'مهتمّ'], null);

        $this->pipeline->convertLead($leadId, $orgId);

        try {
            $this->pipeline->convertLead($leadId, $orgId);
            $this->fail('كان يجب رفض التحويل مرتين.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // ولم يُنشأ عميل ثانٍ
        $this->assertSame(1, $this->countRows('crm_customers', 'organization_id = ?', [$orgId]));
    }

    /** أنشطة المهتمّ تنتقل مع العلاقة | The lead's activities follow the relationship. */
    public function test_activities_follow_the_lead_into_the_customer_record(): void
    {
        $orgId  = $this->createOrganization(['status' => 'verified']);
        $leadId = $this->pipeline->createLead($orgId, ['name_ar' => 'مهتمّ'], null);

        $this->pipeline->logActivity($orgId, [
            'lead_id'       => $leadId,
            'activity_type' => 'call',
            'subject_ar'    => 'مكالمة تعارف',
        ], null);

        $customerId = $this->pipeline->convertLead($leadId, $orgId);

        $this->assertCount(1, $this->pipelineRepo->activitiesFor('customer', $customerId, $orgId));
    }

    /** المفقود لا يُحوَّل | A lost lead is not converted. */
    public function test_a_lost_lead_cannot_be_converted(): void
    {
        $orgId  = $this->createOrganization(['status' => 'verified']);
        $leadId = $this->pipeline->createLead($orgId, ['name_ar' => 'مهتمّ'], null);

        $this->pipeline->updateLeadStatus($leadId, $orgId, 'lost', 'اختار مورّداً آخر.');

        $this->expectException(HttpException::class);
        $this->pipeline->convertLead($leadId, $orgId);
    }

    /** الفقد يستوجب سبباً | Marking a lead lost requires a reason. */
    public function test_marking_a_lead_lost_requires_a_reason(): void
    {
        $orgId  = $this->createOrganization(['status' => 'verified']);
        $leadId = $this->pipeline->createLead($orgId, ['name_ar' => 'مهتمّ'], null);

        $this->expectException(HttpException::class);
        $this->pipeline->updateLeadStatus($leadId, $orgId, 'lost', '');
    }

    /** حالة «محوَّل» لا تُكتب يدوياً | The converted status is not set by hand. */
    public function test_the_converted_status_cannot_be_set_manually(): void
    {
        $orgId  = $this->createOrganization(['status' => 'verified']);
        $leadId = $this->pipeline->createLead($orgId, ['name_ar' => 'مهتمّ'], null);

        try {
            $this->pipeline->updateLeadStatus($leadId, $orgId, 'converted', null);
            $this->fail('كان يجب رفض ضبط حالة التحويل يدوياً.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('بإنشاء عميل', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows('crm_customers', 'organization_id = ?', [$orgId]));
    }

    // ═══════════════════ الفرص | Opportunities ═══════════════════

    /** الانتقال بين المراحل محكوم | Stage transitions are governed. */
    public function test_an_opportunity_cannot_skip_stages(): void
    {
        [$orgId, $opportunityId] = $this->opportunity();

        try {
            $this->pipeline->moveStage($opportunityId, $orgId, 'won');
            $this->fail('كان يجب رفض القفز من «جديدة» إلى «مكسوبة».');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame('new', $this->pipelineRepo->findOpportunity($opportunityId, $orgId)['stage']);
    }

    /** المسار الكامل حتى الكسب | The full path to won. */
    public function test_the_full_pipeline_path_reaches_won(): void
    {
        [$orgId, $opportunityId] = $this->opportunity();

        foreach (['qualified', 'proposal', 'negotiation', 'won'] as $stage) {
            $this->pipeline->moveStage($opportunityId, $orgId, $stage);
        }

        $opportunity = $this->pipelineRepo->findOpportunity($opportunityId, $orgId);

        $this->assertSame('won', $opportunity['stage']);
        $this->assertNotNull($opportunity['closed_at']);
    }

    /** الخسارة تستوجب سبباً | Losing an opportunity requires a reason. */
    public function test_losing_an_opportunity_requires_a_reason(): void
    {
        [$orgId, $opportunityId] = $this->opportunity();

        try {
            $this->pipeline->moveStage($opportunityId, $orgId, 'lost', '   ');
            $this->fail('كان يجب رفض الخسارة بلا سبب.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('سبب الخسارة', $e->getMessage());
        }

        $this->pipeline->moveStage($opportunityId, $orgId, 'lost', 'السعر أعلى من المنافس.');

        $opportunity = $this->pipelineRepo->findOpportunity($opportunityId, $orgId);
        $this->assertSame('lost', $opportunity['stage']);
        $this->assertStringContainsString('المنافس', (string) $opportunity['close_reason_ar']);
    }

    /** الفرصة المغلقة لا تُعدَّل | A closed opportunity is not edited. */
    public function test_a_closed_opportunity_cannot_be_edited(): void
    {
        [$orgId, $opportunityId] = $this->opportunity();
        $this->pipeline->moveStage($opportunityId, $orgId, 'lost', 'انسحب العميل.');

        $this->expectException(HttpException::class);
        $this->pipeline->updateOpportunity($opportunityId, $orgId, ['title_ar' => 'عنوان جديد']);
    }

    /** إعادة الفتح تمحو ختم الإغلاق | Reopening clears the closure stamp. */
    public function test_reopening_a_lost_opportunity_clears_the_closure_stamp(): void
    {
        [$orgId, $opportunityId] = $this->opportunity();

        $this->pipeline->moveStage($opportunityId, $orgId, 'lost', 'تأجّل القرار.');
        $this->pipeline->moveStage($opportunityId, $orgId, 'new');

        $opportunity = $this->pipelineRepo->findOpportunity($opportunityId, $orgId);

        $this->assertSame('new', $opportunity['stage']);
        $this->assertNull($opportunity['closed_at']);
    }

    /** عميل منشأة أخرى لا تُفتح له فرصة | No opportunity on a foreign customer. */
    public function test_no_opportunity_can_be_opened_on_a_foreign_customer(): void
    {
        $orgId = $this->createOrganization(['status' => 'verified']);
        $other = $this->createOrganization(['status' => 'verified']);

        $foreign = $this->customers->create($other, ['name_ar' => 'عميل غريب']);

        $this->expectException(HttpException::class);
        $this->pipeline->createOpportunity($orgId, [
            'customer_id' => $foreign,
            'title_ar'    => 'صفقة',
        ], null);
    }

    // ═══════════════════ الأنشطة | Activities ═══════════════════

    /** النشاط يجب أن يرتبط بشيء | An activity must attach to something. */
    public function test_an_unattached_activity_is_refused(): void
    {
        $orgId = $this->createOrganization(['status' => 'verified']);

        $this->expectException(HttpException::class);
        $this->pipeline->logActivity($orgId, ['subject_ar' => 'ملاحظة معلّقة'], null);
    }

    /** المهمة بلا موعد مرفوضة | A planned task without a due date is refused. */
    public function test_a_planned_task_needs_a_due_date(): void
    {
        [$orgId, , $customerId] = $this->opportunity();

        $this->expectException(HttpException::class);
        $this->pipeline->logActivity($orgId, [
            'customer_id' => $customerId,
            'subject_ar'  => 'متابعة',
            'status'      => 'planned',
        ], null);
    }

    /** إنهاء المهمة يثبّت وقتها | Completing a task stamps its time. */
    public function test_completing_a_task_stamps_the_time(): void
    {
        [$orgId, , $customerId] = $this->opportunity();

        $activityId = $this->pipeline->logActivity($orgId, [
            'customer_id' => $customerId,
            'subject_ar'  => 'مكالمة متابعة',
            'status'      => 'planned',
            'due_at'      => date('Y-m-d H:i:s', time() + 3600),
        ], null);

        $this->assertCount(1, $this->pipelineRepo->dueTasks($orgId));

        $this->pipeline->completeActivity($activityId, $orgId, 'تمّت المكالمة ووافق على العرض.');

        $activity = $this->pipelineRepo->findActivity($activityId, $orgId);

        $this->assertSame('done', $activity['status']);
        $this->assertNotNull($activity['completed_at']);
        $this->assertNotNull($activity['occurred_at']);
        $this->assertSame([], $this->pipelineRepo->dueTasks($orgId));
    }

    /** المنجز لا يُنهى مرة أخرى | A done activity is not completed again. */
    public function test_a_done_activity_cannot_be_completed_again(): void
    {
        [$orgId, , $customerId] = $this->opportunity();

        $activityId = $this->pipeline->logActivity($orgId, [
            'customer_id' => $customerId,
            'subject_ar'  => 'زيارة',
        ], null);

        $this->expectException(HttpException::class);
        $this->pipeline->completeActivity($activityId, $orgId, null);
    }

    /** الإسناد لغير عضو يُهمَل | Assignment to a non-member is dropped. */
    public function test_assignment_to_a_non_member_is_dropped(): void
    {
        [$orgId, , $customerId] = $this->opportunity();
        $stranger               = $this->createUser();

        $activityId = $this->pipeline->logActivity($orgId, [
            'customer_id' => $customerId,
            'subject_ar'  => 'مهمة',
            'assigned_to' => $stranger,
        ], null);

        $this->assertNull($this->pipelineRepo->findActivity($activityId, $orgId)['assigned_to']);
    }

    /** كيان مجهول في قراءة الأنشطة مرفوض | An unknown entity type is refused. */
    public function test_an_unknown_activity_entity_is_refused(): void
    {
        $orgId = $this->createOrganization(['status' => 'verified']);

        $this->expectException(\InvalidArgumentException::class);
        $this->pipelineRepo->activitiesFor('anything', 1, $orgId);
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /** @return array{0:int,1:int,2:int} [organizationId, opportunityId, customerId] */
    private function opportunity(): array
    {
        $orgId      = $this->createOrganization(['status' => 'verified']);
        $customerId = $this->customers->create($orgId, ['name_ar' => 'عميل الفرصة']);

        $opportunityId = $this->pipeline->createOpportunity($orgId, [
            'customer_id'    => $customerId,
            'title_ar'       => 'توريد ربع سنوي',
            'expected_value' => 50000,
        ], null);

        return [$orgId, $opportunityId, $customerId];
    }
}
