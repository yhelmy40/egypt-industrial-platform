<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database;
use App\Services\PublicPageService;

/**
 * سوق العرض التوضيحي | Demonstration marketplace data (§15).
 *
 * ⚠ كل ما تنشئه هذه البذرة تجريبي: المنشآت المالكة موسومة is_demo = 1 وتظهر
 * صفحاتها بوسم «بيانات تجريبية». الأسعار والكميات والطلبات هنا أرقام توضيحية
 * لا تمثّل عروضاً حقيقية من أي جهة.
 * Everything here is demonstration data. Prices, stock levels and orders are
 * illustrative and represent no real offer from any party.
 *
 * الغرض: أن يفتح المُراجِع السوق فيجد نتائج بحث حقيقية البنية — أصناف منشورة،
 * صفحة منشأة مكتملة، طلب في كل حالة مهمة — بدل شاشات فارغة.
 */
final class DemoMarketplaceSeeder extends Seeder
{
    public function isDemo(): bool
    {
        return true;
    }

    public function order(): int
    {
        return 70;
    }

    public function run(): void
    {
        $this->guardProduction();

        $pages     = new PublicPageService();
        $listings  = 0;

        foreach ($this->catalogue() as $slug => $definition) {
            $organization = Database::selectOne(
                'SELECT id, status FROM organizations WHERE slug = ? AND is_demo = 1 LIMIT 1',
                [$slug],
            );

            if ($organization === null) {
                continue;
            }

            $organizationId = (int) $organization['id'];

            // الصفحة التعريفية تُنشر فقط للمنشأة الموثّقة — نفس قاعدة التطبيق
            $pages->findOrCreate($organizationId);
            $this->fillPage($organizationId, $definition['page']);

            if ($organization['status'] === 'verified') {
                $this->publishPage($organizationId);
            }

            foreach ($definition['listings'] as $listing) {
                $this->createListing($organizationId, $listing);
                $listings++;
            }
        }

        $orders = $this->createOrders();

        $this->info("{$listings} صنفاً تجريبياً و{$orders} طلباً تجريبياً.");
    }

    // ─────────────────── الصفحات | Pages ───────────────────

    /** @param array<string,mixed> $content */
    private function fillPage(int $organizationId, array $content): void
    {
        Database::statement(
            'UPDATE public_pages
                SET headline = ?, tagline = ?, story = ?, operating_hours = ?,
                    show_phone = 1, show_address = 1, enable_enquiry_form = 1,
                    enable_quote_request = 1, meta_description = ?
              WHERE organization_id = ?',
            [
                $content['headline'],
                $content['tagline'],
                $content['story'],
                $content['hours'],
                mb_substr((string) $content['tagline'], 0, 300),
                $organizationId,
            ],
        );

        // الأقسام النصّية تُملأ ليبدو القالب مكتملاً عند المراجعة
        Database::statement(
            "UPDATE page_sections SET body = ?, is_visible = 1
              WHERE organization_id = ? AND section_type = 'about'",
            [$content['about'], $organizationId],
        );
    }

    private function publishPage(int $organizationId): void
    {
        Database::statement(
            "UPDATE public_pages
                SET status = 'published', published_at = NOW()
              WHERE organization_id = ? AND status != 'published'",
            [$organizationId],
        );
    }

    // ─────────────────── الأصناف | Listings ───────────────────

    /** @param array<string,mixed> $data */
    private function createListing(int $organizationId, array $data): void
    {
        $categoryId = Database::scalar(
            "SELECT id FROM categories WHERE type = 'listing' AND code = ? LIMIT 1",
            [$data['category']],
        );

        $slug = $this->uniqueSlug((string) $data['name']);

        $this->upsert('listings', [
            'organization_id'    => $organizationId,
            'category_id'        => $categoryId === null ? null : (int) $categoryId,
            'listing_type'       => $data['type'],
            'name_ar'            => $data['name'],
            'slug'               => $slug,
            'short_description'  => $data['short'],
            'description'        => $data['description'],
            'pricing_mode'       => $data['pricing_mode'],
            'price'              => $data['price'],
            'currency_code'      => 'EGP',
            'vat_included'       => $data['vat_included'] ?? 0,
            'vat_rate'           => $data['vat_rate'] ?? 14.00,
            'sku'                => $data['sku'],
            'unit_of_measure'    => $data['unit'],
            'available_quantity' => $data['quantity'],
            'track_inventory'    => $data['quantity'] === null ? 0 : 1,
            'min_order_quantity' => $data['min_order'] ?? 1,
            'lead_time_days'     => $data['lead_time'] ?? null,
            'delivery_area'      => $data['delivery_area'] ?? null,
            'status'             => $data['status'],
            'published_at'       => $data['status'] === 'published' ? date('Y-m-d H:i:s') : null,
            'is_featured'        => $data['featured'] ?? 0,
        ], ['slug']);
    }

    /**
     * رابط فريد | A unique slug.
     *
     * البذرة قابلة لإعادة التشغيل، فالرابط يُشتق من الاسم ولا يُولَّد عشوائياً:
     * التشغيل الثاني يحدّث نفس الصف بدل أن ينشئ نسخة ثانية.
     */
    private function uniqueSlug(string $name): string
    {
        $slug = preg_replace('/[^\p{Arabic}\p{L}\p{N}]+/u', '-', $name) ?? '';

        return trim(mb_substr($slug, 0, 200), '-');
    }

    // ─────────────────── الطلبات | Orders ───────────────────

    private function createOrders(): int
    {
        $seller = Database::selectOne(
            "SELECT id FROM organizations WHERE slug = 'مصنع-النيل-للصناعات-الغذائية' LIMIT 1",
        );

        if ($seller === null) {
            return 0;
        }

        $sellerId = (int) $seller['id'];

        $listings = Database::select(
            "SELECT * FROM listings
              WHERE organization_id = ? AND status = 'published' AND pricing_mode = 'fixed'
              ORDER BY id ASC LIMIT 2",
            [$sellerId],
        );

        if ($listings === []) {
            return 0;
        }

        $paymentMethodId = Database::scalar(
            "SELECT id FROM payment_methods WHERE code = 'cash_on_delivery' LIMIT 1",
        );

        $governorateId = Database::scalar("SELECT id FROM governorates WHERE code = 'C' LIMIT 1");

        // طلب في كل حالة مهمة ليتمكّن المُراجِع من رؤية آلة الحالة عاملة
        $scenarios = [
            ['NP-DEMO-0001', 'new', 'سارة عبد الرحمن (بيانات تجريبية)', '01000000001'],
            ['NP-DEMO-0002', 'confirmed', 'محمود فتحي (بيانات تجريبية)', '01000000002'],
            ['NP-DEMO-0003', 'delivered', 'هند مصطفى (بيانات تجريبية)', '01000000003'],
            ['NP-DEMO-0004', 'completed', 'كريم السيد (بيانات تجريبية)', '01000000004'],
        ];

        $created = 0;

        foreach ($scenarios as $index => [$number, $status, $customer, $phone]) {
            if (Database::selectOne('SELECT id FROM orders WHERE order_number = ?', [$number]) !== null) {
                continue;
            }

            $listing  = $listings[$index % count($listings)];
            $quantity = 2 + $index;
            $unit     = (float) $listing['price'];
            $subtotal = round($unit * $quantity, 2);
            $vat      = round($subtotal * (float) $listing['vat_rate'] / 100, 2);
            $total    = round($subtotal + $vat, 2);

            $orderId = Database::insert(
                'INSERT INTO orders
                    (order_number, organization_id, customer_name, customer_phone,
                     governorate_id, delivery_address, tracking_token, status,
                     subtotal, vat_amount, delivery_fee, total, currency_code,
                     payment_method_id, payment_status, source,
                     confirmed_at, delivered_at, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $number,
                    $sellerId,
                    $customer,
                    $phone,
                    $governorateId === null ? null : (int) $governorateId,
                    'عنوان تجريبي — القاهرة',
                    // الرمز اشتقاق ثابت من رقم الطلب: البذرة قابلة لإعادة التشغيل
                    substr(hash('sha256', 'demo-order-' . $number), 0, 48),
                    $status,
                    $subtotal,
                    $vat,
                    $total,
                    'EGP',
                    $paymentMethodId === null ? null : (int) $paymentMethodId,
                    $status === 'completed' ? 'paid' : 'unpaid',
                    'marketplace',
                    in_array($status, ['confirmed', 'delivered', 'completed'], true) ? date('Y-m-d H:i:s') : null,
                    in_array($status, ['delivered', 'completed'], true) ? date('Y-m-d H:i:s') : null,
                    $status === 'completed' ? date('Y-m-d H:i:s') : null,
                ],
            );

            Database::statement(
                'INSERT INTO order_items
                    (order_id, organization_id, listing_id, name_ar, sku, unit_of_measure,
                     quantity, unit_price, vat_rate, line_subtotal, line_vat, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $orderId, $sellerId, (int) $listing['id'], $listing['name_ar'],
                    $listing['sku'], $listing['unit_of_measure'],
                    $quantity, $unit, $listing['vat_rate'], $subtotal, $vat, $total,
                ],
            );

            Database::statement(
                "INSERT INTO order_status_history
                    (order_id, organization_id, from_status, to_status, actor_type, note)
                 VALUES (?, ?, NULL, 'new', 'customer', 'طلب تجريبي أُنشئ ببذور العرض التوضيحي')",
                [$orderId, $sellerId],
            );

            if ($status !== 'new') {
                Database::statement(
                    "INSERT INTO order_status_history
                        (order_id, organization_id, from_status, to_status, actor_type, note)
                     VALUES (?, ?, 'new', ?, 'seller', 'انتقال تجريبي')",
                    [$orderId, $sellerId, $status],
                );
            }

            Database::statement(
                'UPDATE listings SET order_count = order_count + 1 WHERE id = ?',
                [(int) $listing['id']],
            );

            $created++;
        }

        return $created;
    }

    // ─────────────────── البيانات | Data ───────────────────

    /** @return array<string,array<string,mixed>> */
    private function catalogue(): array
    {
        return [
            'مصنع-النيل-للصناعات-الغذائية' => [
                'page' => [
                    'headline' => 'شركة النيل للصناعات الغذائية (بيانات تجريبية)',
                    'tagline'  => 'تصنيع وتعبئة منتجات غذائية للأسواق المحلية والفنادق والمطاعم.',
                    'story'    => 'منشأة تجريبية أُنشئت لعرض إمكانات المنصة. البيانات الواردة هنا '
                        . 'لا تخصّ شركة قائمة ولا تمثّل عروضاً حقيقية.',
                    'hours'    => 'السبت – الخميس: ٩ ص – ٥ م' . "\n" . 'الجمعة: إجازة',
                    'about'    => 'خط إنتاج تجريبي يشمل التعبئة والتغليف والحاصلات المجفّفة، '
                        . 'مع القدرة على التوريد بكميات الجملة داخل القاهرة الكبرى.',
                ],
                'listings' => [
                    [
                        'type' => 'product', 'category' => 'food_packaged',
                        'name' => 'عبوة تمر مجفّف ١ كجم (تجريبي)',
                        'short' => 'تمر مجفّف معبّأ في عبوات كرتونية، صالح للتوريد بالجملة.',
                        'description' => "عبوة تجريبية للعرض على المنصة.\nالمواصفات والأسعار المعروضة "
                            . 'أرقام توضيحية لا تمثّل عرضاً حقيقياً.',
                        'pricing_mode' => 'fixed', 'price' => 180.00, 'sku' => 'NILE-DATE-1KG',
                        'unit' => 'عبوة', 'quantity' => 320, 'min_order' => 10,
                        'lead_time' => 3, 'delivery_area' => 'القاهرة الكبرى',
                        'status' => 'published', 'featured' => 1,
                    ],
                    [
                        'type' => 'product', 'category' => 'food_agri',
                        'name' => 'أعشاب مجفّفة معبّأة ٥٠٠ جم (تجريبي)',
                        'short' => 'خلطات أعشاب مجفّفة معبّأة بأوزان ثابتة.',
                        'description' => 'صنف تجريبي لعرض بطاقة المنتج وتفاصيل التسعير.',
                        'pricing_mode' => 'fixed', 'price' => 95.50, 'sku' => 'NILE-HERB-500',
                        'unit' => 'عبوة', 'quantity' => 140, 'min_order' => 20,
                        'lead_time' => 5, 'delivery_area' => 'القاهرة والجيزة',
                        'status' => 'published',
                    ],
                    [
                        'type' => 'service', 'category' => 'food_processing_services',
                        'name' => 'خدمة تعبئة وتغليف لحساب الغير (تجريبي)',
                        'short' => 'تعبئة منتجاتك على خطوطنا بأوزان وتصميمات متفق عليها.',
                        'description' => 'خدمة تجريبية تُسعَّر حسب الكمية والمواصفات، '
                            . 'ولذلك تُعرض بوضع «اطلب عرض سعر».',
                        'pricing_mode' => 'quote', 'price' => null, 'sku' => 'NILE-COPACK',
                        'unit' => 'طن', 'quantity' => null,
                        'lead_time' => 14, 'delivery_area' => 'داخل مصر',
                        'status' => 'published',
                    ],
                    [
                        'type' => 'product', 'category' => 'packaging',
                        'name' => 'كرتون تعبئة مطبوع (تجريبي)',
                        'short' => 'كراتين تعبئة بمقاسات قياسية — قيد المراجعة.',
                        'description' => 'صنف تجريبي متروك في حالة «بانتظار المراجعة» '
                            . 'ليظهر في قائمة المراجعة الإدارية.',
                        'pricing_mode' => 'fixed', 'price' => 12.75, 'sku' => 'NILE-BOX-STD',
                        'unit' => 'قطعة', 'quantity' => 5000, 'min_order' => 500,
                        'lead_time' => 7, 'delivery_area' => 'القاهرة الكبرى',
                        'status' => 'pending_review',
                    ],
                ],
            ],

            'بيت-الخبرة-للاستشارات' => [
                'page' => [
                    'headline' => 'بيت الخبرة للاستشارات (بيانات تجريبية)',
                    'tagline'  => 'استشارات محاسبية وإدارية للمشروعات الصغيرة والمتوسطة.',
                    'story'    => 'مقدّم خدمات تجريبي لعرض شكل صفحة مقدّم الخدمة على المنصة.',
                    'hours'    => 'الأحد – الخميس: ١٠ ص – ٦ م',
                    'about'    => 'خدمات تجريبية تشمل إعداد القوائم المالية الإدارية وتنظيم الدورة '
                        . 'المستندية. لا تُغني هذه الخدمات عن مراجعة محاسب قانوني مؤهّل.',
                ],
                'listings' => [
                    [
                        'type' => 'service', 'category' => 'service_accounting',
                        'name' => 'إعداد الدورة المستندية للمنشأة (تجريبي)',
                        'short' => 'مراجعة وتنظيم مستندات المشتريات والمبيعات والمخزون.',
                        'description' => 'خدمة تجريبية. أي مخرجات محاسبية تحتاج مراجعة محاسب '
                            . 'قانوني مؤهّل قبل الاعتماد عليها.',
                        'pricing_mode' => 'fixed', 'price' => 4500.00, 'sku' => 'EXP-DOCCYCLE',
                        'unit' => 'باقة', 'quantity' => null, 'min_order' => 1,
                        'lead_time' => 21, 'delivery_area' => 'القاهرة أو عن بُعد',
                        'status' => 'published',
                    ],
                    [
                        'type' => 'service', 'category' => 'service_training',
                        'name' => 'تدريب فريق المبيعات (تجريبي)',
                        'short' => 'برنامج تدريبي قصير لفرق المبيعات في المشروعات الصغيرة.',
                        'description' => 'برنامج تجريبي يُسعَّر حسب عدد المتدربين ومكان التنفيذ.',
                        'pricing_mode' => 'quote', 'price' => null, 'sku' => 'EXP-SALESTRN',
                        'unit' => 'برنامج', 'quantity' => null,
                        'lead_time' => 10, 'delivery_area' => 'داخل مصر',
                        'status' => 'published',
                    ],
                ],
            ],

            'ورشة-دلتا-للملابس-الجاهزة' => [
                'page' => [
                    'headline' => 'ورشة دلتا للملابس الجاهزة (بيانات تجريبية)',
                    'tagline'  => 'تفصيل وإنتاج ملابس جاهزة بكميات متوسطة.',
                    'story'    => 'منشأة تجريبية ما زالت في مرحلة التوثيق، فصفحتها غير منشورة.',
                    'hours'    => 'السبت – الخميس: ٨ ص – ٤ م',
                    'about'    => 'ورشة تجريبية تُستخدم لإثبات أن المنشأة غير الموثّقة لا تُنشر '
                        . 'صفحتها ولا تظهر أصنافها في السوق.',
                ],
                'listings' => [
                    [
                        'type' => 'product', 'category' => 'textiles_readymade',
                        'name' => 'قميص قطن رجالي (تجريبي — منشأة غير موثّقة)',
                        'short' => 'صنف تجريبي لمنشأة لم تُوثَّق بعد.',
                        'description' => 'هذا الصنف موجود في قاعدة البيانات بحالة «مسودة» ولا يظهر '
                            . 'في السوق: المنشأة غير موثّقة.',
                        'pricing_mode' => 'fixed', 'price' => 260.00, 'sku' => 'DELTA-SHIRT',
                        'unit' => 'قطعة', 'quantity' => 80, 'min_order' => 12,
                        'lead_time' => 10, 'delivery_area' => 'الدقهلية',
                        'status' => 'draft',
                    ],
                ],
            ],
        ];
    }
}
