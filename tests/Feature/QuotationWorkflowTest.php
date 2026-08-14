<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Services\QuotationService;
use Tests\TestCase;

/**
 * مسار عرض السعر | Request-for-quote workflow (§4.4).
 *
 * الطلب ← عرض البائع ← قبول العميل ← طلب. الاختبارات تحرس أن العميل لا يُلزَم
 * بعرض لم يُقدَّم، وأن البائع لا يعدّل عرضاً بعد قبوله، وأن رمز المتابعة هو
 * المفتاح الوحيد للزائر.
 */
final class QuotationWorkflowTest extends TestCase
{
    private QuotationService $quotations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quotations = new QuotationService();
    }

    public function test_a_visitor_can_request_a_quote_from_a_verified_organization(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);

        $result = $this->requestQuote($organizationId);

        $this->assertDatabaseHas('quotations', ['id' => $result['id'], 'status' => 'requested']);
        $this->assertSame(48, strlen($result['token']), 'رمز المتابعة يجب أن يكون ٤٨ حرفاً.');
        $this->assertStringStartsWith('RFQ-', $result['number']);
    }

    public function test_an_unverified_organization_cannot_receive_quote_requests(): void
    {
        $organizationId = $this->createOrganization(['status' => 'submitted']);

        $this->expectException(HttpException::class);
        $this->requestQuote($organizationId);
    }

    public function test_submitting_a_quote_computes_totals_and_moves_the_status(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $quotation      = $this->requestQuote($organizationId);

        $this->quotations->submitQuote(
            quotationId: $quotation['id'],
            organizationId: $organizationId,
            items: [
                ['description' => 'توريد ١٠٠ عبوة', 'quantity' => 100, 'unit_price' => 25, 'vat_rate' => 14],
                ['description' => 'رسوم تجهيز', 'quantity' => 1, 'unit_price' => 500, 'vat_rate' => 0],
            ],
            terms: ['delivery_fee' => 150, 'lead_time_days' => 7, 'terms' => 'السداد عند الاستلام'],
            actorId: $this->createUser(),
            request: $this->request('POST', '/app/quotations/quote'),
        );

        $row = Database::selectOne('SELECT * FROM quotations WHERE id = ?', [$quotation['id']]);

        $this->assertSame('quoted', $row['status']);
        // 2500 + 500 = 3000 قبل الضريبة، الضريبة 350 على البند الأول فقط، + 150 توصيل
        $this->assertSame('3000.00', $row['subtotal']);
        $this->assertSame('350.00', $row['vat_amount']);
        $this->assertSame('3500.00', $row['total']);
        $this->assertSame(2, $this->countRows('quotation_items', 'quotation_id = ?', [$quotation['id']]));
    }

    public function test_a_quote_with_no_valid_line_is_refused(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $quotation      = $this->requestQuote($organizationId);

        $this->expectException(HttpException::class);

        $this->quotations->submitQuote(
            quotationId: $quotation['id'],
            organizationId: $organizationId,
            items: [['description' => '  ', 'quantity' => 1, 'unit_price' => null, 'vat_rate' => 0]],
            terms: [],
            actorId: $this->createUser(),
            request: $this->request('POST', '/quote'),
        );
    }

    public function test_another_organization_cannot_quote_on_the_request(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $intruderId     = $this->createOrganization(['status' => 'verified']);
        $quotation      = $this->requestQuote($organizationId);

        try {
            $this->quotations->submitQuote(
                quotationId: $quotation['id'],
                organizationId: $intruderId,
                items: [['description' => 'بند', 'quantity' => 1, 'unit_price' => 10, 'vat_rate' => 0]],
                terms: [],
                actorId: $this->createUser(),
                request: $this->request('POST', '/quote'),
            );
            $this->fail('كان يجب رفض تسعير طلب منشأة أخرى.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertDatabaseHas('quotations', ['id' => $quotation['id'], 'status' => 'requested']);
    }

    /** لا قبول لعرض لم يُقدَّم | A request that was never quoted cannot be accepted. */
    public function test_a_request_still_awaiting_a_quote_cannot_be_accepted(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $quotation      = $this->requestQuote($organizationId);

        $this->expectException(HttpException::class);

        $this->quotations->respond($quotation['token'], 'accept', null, $this->request('POST', '/respond'));
    }

    public function test_accepting_a_quote_creates_an_order_with_the_quoted_totals(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $quotation      = $this->requestQuote($organizationId);

        $this->quotations->submitQuote(
            quotationId: $quotation['id'],
            organizationId: $organizationId,
            items: [['description' => 'توريد', 'quantity' => 10, 'unit_price' => 100, 'vat_rate' => 14]],
            terms: ['delivery_fee' => 0],
            actorId: $this->createUser(),
            request: $this->request('POST', '/quote'),
        );

        $orderId = $this->quotations->respond(
            $quotation['token'],
            'accept',
            null,
            $this->request('POST', '/respond'),
        );

        $this->assertIsInt($orderId);

        $order = Database::selectOne('SELECT * FROM orders WHERE id = ?', [$orderId]);

        $this->assertSame('quotation', $order['source']);
        // العرض المقبول ينشئ طلباً «مؤكَّداً» مباشرة: الطرفان اتفقا على السعر
        $this->assertSame('confirmed', $order['status']);
        $this->assertSame('1140.00', $order['total']);
        $this->assertSame($organizationId, (int) $order['organization_id']);

        $this->assertDatabaseHas('quotations', [
            'id' => $quotation['id'], 'status' => 'accepted', 'converted_order_id' => $orderId,
        ]);
    }

    public function test_rejecting_a_quote_creates_no_order(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $quotation      = $this->requestQuote($organizationId);

        $this->quotations->submitQuote(
            quotationId: $quotation['id'],
            organizationId: $organizationId,
            items: [['description' => 'توريد', 'quantity' => 1, 'unit_price' => 100, 'vat_rate' => 0]],
            terms: [],
            actorId: $this->createUser(),
            request: $this->request('POST', '/quote'),
        );

        $before = $this->countRows('orders');

        $orderId = $this->quotations->respond(
            $quotation['token'],
            'reject',
            null,
            $this->request('POST', '/respond'),
        );

        $this->assertNull($orderId);
        $this->assertSame($before, $this->countRows('orders'));
        $this->assertDatabaseHas('quotations', ['id' => $quotation['id'], 'status' => 'rejected']);
    }

    /** العرض المنتهي لا يُقبل | An expired quote cannot be accepted. */
    public function test_an_expired_quote_cannot_be_accepted(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $quotation      = $this->requestQuote($organizationId);

        $this->quotations->submitQuote(
            quotationId: $quotation['id'],
            organizationId: $organizationId,
            items: [['description' => 'توريد', 'quantity' => 1, 'unit_price' => 100, 'vat_rate' => 0]],
            terms: ['valid_until' => date('Y-m-d', strtotime('-1 day'))],
            actorId: $this->createUser(),
            request: $this->request('POST', '/quote'),
        );

        try {
            $this->quotations->respond($quotation['token'], 'accept', null, $this->request('POST', '/respond'));
            $this->fail('كان يجب رفض قبول عرض منتهي الصلاحية.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertDatabaseHas('quotations', ['id' => $quotation['id'], 'status' => 'expired']);
    }

    /**
     * الرمز هو المفتاح الوحيد | The token is the only key.
     *
     * الزائر لا يملك حساباً، فرمز المتابعة العشوائي هو حجّته. رمز خاطئ يجب
     * ألّا يصل إلى أي عرض سعر مهما كان.
     */
    public function test_an_unknown_tracking_token_reaches_nothing(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $this->requestQuote($organizationId);

        $this->expectException(HttpException::class);
        $this->quotations->respond(str_repeat('a', 48), 'accept', null, $this->request('POST', '/respond'));
    }

    /** @return array{id:int,number:string,token:string} */
    private function requestQuote(int $organizationId): array
    {
        return $this->quotations->request(
            $organizationId,
            null,
            [
                'customer_name'      => 'عميل عرض السعر',
                'customer_phone'     => '01000000000',
                'request_details'    => 'أرغب في توريد كمية شهرية منتظمة.',
                'requested_quantity' => 100,
            ],
            $this->request('POST', '/quotations/request'),
        );
    }
}
