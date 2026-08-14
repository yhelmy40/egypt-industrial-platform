<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * وسائل الدفع | Payment methods (§4.4, §14).
 *
 * ⚠ كل الوسائل هنا تعمل بمحرّك `offline`: المنصة تسجّل نية الدفع وتعليماته
 * فقط، ولا تنفّذ تحصيلاً إلكترونياً ولا تحتفظ ببيانات بطاقات. تفعيل بوابة دفع
 * حقيقية يتطلّب بيانات اعتماد من مزوّد وموافقة صريحة، ولا يُحاكى هنا (§14).
 * Every method runs on the `offline` driver: the platform records payment
 * intent and instructions only. No online capture, no card data. A real
 * gateway needs provider credentials and explicit approval — never simulated.
 */
final class PaymentMethodsSeeder extends Seeder
{
    public function order(): int
    {
        return 15;
    }

    public function run(): void
    {
        $methods = [
            [
                'code'            => 'cash_on_delivery',
                'name_ar'         => 'الدفع عند الاستلام',
                'instructions_ar' => 'يُسدَّد المبلغ نقداً للمنشأة البائعة أو لمندوبها عند تسليم الطلب. '
                    . 'يُرجى تجهيز المبلغ بالجنيه المصري.',
                'driver'          => 'offline',
                'requires_proof'  => 0,
                'sort_order'      => 10,
            ],
            [
                'code'            => 'bank_transfer',
                'name_ar'         => 'تحويل بنكي',
                'instructions_ar' => 'تُرسل المنشأة البائعة بيانات الحساب بعد تأكيد الطلب. '
                    . 'بعد التحويل أرفق صورة الإيصال أو رقم العملية ليتحقّق منها البائع.',
                'driver'          => 'offline',
                'requires_proof'  => 1,
                'sort_order'      => 20,
            ],
            [
                'code'            => 'mobile_wallet',
                'name_ar'         => 'محفظة إلكترونية',
                'instructions_ar' => 'يُحوَّل المبلغ إلى رقم المحفظة الذي ترسله المنشأة البائعة بعد تأكيد الطلب، '
                    . 'ثم يُرفَق رقم العملية. التحويل يتم خارج المنصة.',
                'driver'          => 'offline',
                'requires_proof'  => 1,
                'sort_order'      => 30,
            ],
            [
                'code'            => 'agreement',
                'name_ar'         => 'اتفاق مباشر مع المنشأة',
                'instructions_ar' => 'تتفق مع المنشأة على وسيلة السداد وموعده مباشرةً. '
                    . 'المنصة تسجّل الطلب فقط ولا تتدخّل في التحصيل.',
                'driver'          => 'offline',
                'requires_proof'  => 0,
                'sort_order'      => 40,
            ],
        ];

        foreach ($methods as $method) {
            $this->upsert('payment_methods', $method + ['is_active' => 1], ['code']);
        }

        $this->info(count($methods) . ' وسائل دفع (كلها خارج المنصة — لا تحصيل إلكتروني).');
    }
}
