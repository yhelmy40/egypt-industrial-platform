<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Repositories\InventoryRepository;

/**
 * خدمة الأصناف والمخزون | Item and stock service (§4.10, §14).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * **القاعدة الحاكمة: الرصيد مشتقّ لا مُدخَل.**
 *
 * `quantity_on_hand` لا يُكتب من نموذج ولا من متحكّم. كل تغيّر يمرّ من
 * `record()` وحدها، فيُنشئ حركة في الدفتر ويحدّث الرصيد في المعاملة نفسها.
 * التصحيح **حركة تسوية جديدة بسبب مكتوب**، لا كتابة فوق الرقم.
 *
 * الفرق عملي لا نظري: صاحب مشروع يجد رصيده أقلّ مما يظنّ يحتاج أن يعرف
 * **متى نقص ولماذا ومن سجّل النقص**. الكتابة فوق الرصيد تمحو هذا كله وتترك
 * رقماً لا يُسأل عنه أحد.
 *
 * ولذلك: **لا تُصدَّر دالة تكتب `quantity_on_hand` مباشرةً من هذا الملف.**
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class InventoryService
{
    /** حركات تستوجب سبباً مكتوباً | Movements requiring a written reason. */
    private const REASON_REQUIRED = ['adjustment', 'damage', 'transfer_out'];

    /** حركات واردة | Inbound movement types. */
    private const INBOUND = ['opening', 'purchase', 'return_in', 'production_in'];

    /** @var array<int,string> */
    private const TYPES = [
        'opening', 'purchase', 'sale', 'return_in', 'return_out',
        'adjustment', 'damage', 'transfer_out', 'production_in',
    ];

    /**
     * لا مدقّق منفصل هنا: **الدفتر نفسه هو سجلّ التدقيق.** كل حركة تحمل نوعها
     * وسببها وفاعلها ورصيدها، وتسجيلها مرتين في مكانين يخلق مصدرين قد يتناقضان.
     */
    public function __construct(
        private readonly InventoryRepository $items = new InventoryRepository(),
    ) {
    }

    // ═══════════════════ الأصناف | Items ═══════════════════

    /**
     * إنشاء صنف | Create an item.
     *
     * الرصيد الافتتاحي — إن وُجد — يُسجَّل **كحركة** لا كقيمة ابتدائية، فيبدأ
     * الدفتر مكتملاً من أول سطر.
     *
     * @param array<string,mixed> $data
     */
    public function create(int $organizationId, array $data, ?int $actorUserId): int
    {
        $payload = $this->validate($organizationId, $data, null);

        return Database::transaction(function () use ($organizationId, $payload, $data, $actorUserId): int {
            $itemId = Database::insert(
                'INSERT INTO erp_items
                    (organization_id, sku, name_ar, description_ar, item_type,
                     unit_of_measure, track_stock, reorder_level, cost_price,
                     sale_price, vat_rate, listing_id, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $organizationId,
                    $payload['sku'],
                    $payload['name_ar'],
                    $payload['description_ar'],
                    $payload['item_type'],
                    $payload['unit_of_measure'],
                    $payload['track_stock'],
                    $payload['reorder_level'],
                    $payload['cost_price'],
                    $payload['sale_price'],
                    $payload['vat_rate'],
                    $payload['listing_id'],
                    $payload['is_active'],
                ],
            );

            $opening = $this->decimal($data['opening_quantity'] ?? null);

            if ($payload['track_stock'] === 1 && $opening !== null && abs($opening) > 0.0001) {
                $this->record(
                    itemId: $itemId,
                    organizationId: $organizationId,
                    type: 'opening',
                    delta: $opening,
                    actorUserId: $actorUserId,
                    reason: 'رصيد افتتاحي عند إنشاء الصنف.',
                    unitCost: $payload['cost_price'],
                );
            }

            return $itemId;
        });
    }

    /**
     * تعديل بيانات صنف | Update an item's details.
     *
     * **لا يمسّ الرصيد.** `quantity_on_hand` غير مسموح به في المدخلات أصلاً،
     * ولو أُرسل من المتصفّح فلا يقرؤه هذا المسار.
     *
     * @param array<string,mixed> $data
     */
    public function update(int $itemId, int $organizationId, array $data): void
    {
        $item    = $this->requireItem($itemId, $organizationId);
        $payload = $this->validate($organizationId, $data, $itemId);

        // إيقاف تتبّع المخزون لصنف له رصيد يترك كمية معلّقة بلا دفتر
        if ($payload['track_stock'] === 0 && (float) $item['quantity_on_hand'] != 0.0) {
            throw new HttpException(
                422,
                'لا يمكن إيقاف تتبّع المخزون لصنف رصيده ليس صفراً. '
                . 'سجّل تسوية تُصفّر الرصيد أولاً ليبقى الدفتر مفسِّراً لما جرى.',
            );
        }

        Database::statement(
            'UPDATE erp_items
                SET sku = ?, name_ar = ?, description_ar = ?, item_type = ?,
                    unit_of_measure = ?, track_stock = ?, reorder_level = ?,
                    cost_price = ?, sale_price = ?, vat_rate = ?, listing_id = ?,
                    is_active = ?, updated_at = NOW()
              WHERE id = ? AND organization_id = ?',
            [
                $payload['sku'],
                $payload['name_ar'],
                $payload['description_ar'],
                $payload['item_type'],
                $payload['unit_of_measure'],
                $payload['track_stock'],
                $payload['reorder_level'],
                $payload['cost_price'],
                $payload['sale_price'],
                $payload['vat_rate'],
                $payload['listing_id'],
                $payload['is_active'],
                $itemId,
                $organizationId,
            ],
        );
    }

    /** أرشفة صنف | Archive an item (soft delete). */
    public function archive(int $itemId, int $organizationId): void
    {
        $this->requireItem($itemId, $organizationId);

        Database::statement(
            'UPDATE erp_items SET deleted_at = NOW(), is_active = 0
              WHERE id = ? AND organization_id = ?',
            [$itemId, $organizationId],
        );
    }

    // ═══════════════════ دفتر الحركات | The movement ledger ═══════════════════

    /**
     * تسجيل حركة مخزون | Record a stock movement.
     *
     * **المسار الوحيد الذي يغيّر الرصيد في المنصة كلها.** يقفل صفّ الصنف،
     * يحسب الرصيد الجديد، يكتب الحركة برصيدها، ثم يحدّث الصنف — كله في
     * معاملة واحدة، فلا يوجد وضع يظهر فيه رصيد لا تفسّره حركة.
     */
    public function record(
        int $itemId,
        int $organizationId,
        string $type,
        float $delta,
        ?int $actorUserId,
        ?string $reason = null,
        ?float $unitCost = null,
        string $referenceType = 'manual',
        ?int $referenceId = null,
        ?string $movedAt = null,
    ): int {
        if (!in_array($type, self::TYPES, true)) {
            throw new HttpException(422, 'نوع حركة المخزون غير معروف.');
        }

        if (abs($delta) < 0.0001) {
            throw new HttpException(422, 'كمية الحركة لا يمكن أن تكون صفراً.');
        }

        // الاتجاه محكوم بالنوع لا بإشارة يرسلها المتصفّح: «مشتريات» بكمية
        // سالبة أو «مبيعات» بكمية موجبة تجعل الدفتر يناقض تسمياته.
        if (in_array($type, self::INBOUND, true) && $delta < 0) {
            throw new HttpException(422, 'حركة واردة بكمية سالبة. راجع نوع الحركة.');
        }

        if (in_array($type, ['sale', 'return_out', 'damage', 'transfer_out'], true) && $delta > 0) {
            $delta = -$delta;
        }

        if (in_array($type, self::REASON_REQUIRED, true) && trim((string) $reason) === '') {
            throw new HttpException(
                422,
                'اكتب سبب الحركة. تسوية بلا سبب تترك فرقاً في الرصيد لا يفسّره شيء.',
            );
        }

        $movedAt = $this->timestamp($movedAt);

        return Database::transaction(function () use (
            $itemId, $organizationId, $type, $delta, $actorUserId,
            $reason, $unitCost, $referenceType, $referenceId, $movedAt
        ): int {
            // القفل يمنع حركتين متزامنتين من قراءة الرصيد نفسه
            $item = Database::selectOne(
                'SELECT id, name_ar, track_stock, quantity_on_hand
                   FROM erp_items
                  WHERE id = ? AND organization_id = ? AND deleted_at IS NULL
                  FOR UPDATE',
                [$itemId, $organizationId],
            );

            if ($item === null) {
                throw new HttpException(404, 'الصنف غير موجود.');
            }

            if ((int) $item['track_stock'] !== 1) {
                throw new HttpException(
                    422,
                    'هذا الصنف غير متتبَّع المخزون، فلا تُسجَّل عليه حركات.',
                );
            }

            $balanceAfter = round((float) $item['quantity_on_hand'] + $delta, 3);

            // الرصيد السالب يعني بيع ما ليس موجوداً؛ السماح به يجعل الدفتر
            // يصف واقعاً مستحيلاً ويُفسد أي تقدير لقيمة المخزون.
            if ($balanceAfter < 0) {
                throw new HttpException(
                    422,
                    'الكمية المطلوبة أكبر من الرصيد المتاح ('
                    . rtrim(rtrim(number_format((float) $item['quantity_on_hand'], 3), '0'), '.')
                    . '). سجّل واردات أولاً أو راجع الكمية.',
                );
            }

            $movementId = Database::insert(
                'INSERT INTO erp_stock_movements
                    (organization_id, item_id, movement_type, quantity_delta, balance_after,
                     unit_cost, reference_type, reference_id, reason_ar, moved_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $organizationId,
                    $itemId,
                    $type,
                    $delta,
                    $balanceAfter,
                    $unitCost,
                    $referenceType,
                    $referenceId,
                    $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 500),
                    $movedAt,
                    $actorUserId,
                ],
            );

            Database::statement(
                'UPDATE erp_items
                    SET quantity_on_hand = ?, updated_at = NOW()
                  WHERE id = ?',
                [$balanceAfter, $itemId],
            );

            // آخر تكلفة شراء معروفة تُحدَّث من الوارد فقط
            if ($unitCost !== null && in_array($type, ['purchase', 'opening'], true)) {
                Database::statement(
                    'UPDATE erp_items SET cost_price = ? WHERE id = ?',
                    [$unitCost, $itemId],
                );
            }

            $this->syncLinkedListing($itemId, $balanceAfter);

            return $movementId;
        });
    }

    /**
     * تسوية جرد | A stocktake adjustment.
     *
     * يستقبل **الرصيد المعدود** لا الفرق: صاحب المشروع يعدّ ما في المخزن ولا
     * يحسب الفرق ذهنياً، وحساب الفرق هنا يمنع خطأ الطرح اليدوي.
     */
    public function adjustToCount(
        int $itemId,
        int $organizationId,
        float $countedQuantity,
        string $reason,
        ?int $actorUserId,
    ): ?int {
        if ($countedQuantity < 0) {
            throw new HttpException(422, 'الرصيد المعدود لا يكون سالباً.');
        }

        $item  = $this->requireItem($itemId, $organizationId);
        $delta = round($countedQuantity - (float) $item['quantity_on_hand'], 3);

        if (abs($delta) < 0.0001) {
            return null; // الرصيد مطابق؛ لا حركة بلا تغيّر
        }

        return $this->record(
            itemId: $itemId,
            organizationId: $organizationId,
            type: 'adjustment',
            delta: $delta,
            actorUserId: $actorUserId,
            reason: $reason,
        );
    }

    /**
     * صرف مخزون مقابل بيع | Issue stock against a sale.
     *
     * تُستدعى من خدمة الفواتير عند الإصدار. الصنف غير المتتبَّع أو غير المرتبط
     * يُتخطّى بصمت: بند خدمة على فاتورة لا مخزون له.
     */
    public function issueForSale(
        int $itemId,
        int $organizationId,
        float $quantity,
        int $invoiceId,
        ?int $actorUserId,
    ): void {
        $item = $this->items->findOwned($itemId, $organizationId);

        if ($item === null || (int) $item['track_stock'] !== 1) {
            return;
        }

        $this->record(
            itemId: $itemId,
            organizationId: $organizationId,
            type: 'sale',
            delta: -abs($quantity),
            actorUserId: $actorUserId,
            referenceType: 'invoice',
            referenceId: $invoiceId,
        );
    }

    // ═══════════════════ أدوات داخلية | Internals ═══════════════════

    /**
     * مزامنة الإعلان المرتبط | Keep the linked listing's quantity in step.
     *
     * حين يُربط صنف بإعلان في السوق يصير **الصنف مصدر الرصيد الوحيد**. بقاء
     * رقمين مستقلّين كان سيعني أن السوق يعرض توفّراً لا يطابق المخزن، وأول
     * طلب على كمية غير موجودة يكلّف المشروع سمعته لا عمولة.
     */
    private function syncLinkedListing(int $itemId, float $balance): void
    {
        Database::statement(
            'UPDATE listings l
               JOIN erp_items i ON i.listing_id = l.id
                SET l.available_quantity = ?, l.updated_at = NOW()
              WHERE i.id = ? AND l.track_inventory = 1',
            [$balance, $itemId],
        );
    }

    /** @return array<string,mixed> */
    private function requireItem(int $itemId, int $organizationId): array
    {
        $item = $this->items->findOwned($itemId, $organizationId);

        if ($item === null) {
            throw new HttpException(404, 'الصنف غير موجود.');
        }

        return $item;
    }

    /**
     * التحقّق من مدخلات الصنف | Validate item input.
     *
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function validate(int $organizationId, array $data, ?int $exceptId): array
    {
        $name = trim((string) ($data['name_ar'] ?? ''));
        $sku  = trim((string) ($data['sku'] ?? ''));

        if ($name === '') {
            throw new HttpException(422, 'اكتب اسم الصنف.');
        }

        if ($sku === '') {
            throw new HttpException(422, 'اكتب كود الصنف (SKU).');
        }

        if ($this->items->skuExists($organizationId, $sku, $exceptId)) {
            throw new HttpException(422, 'كود الصنف مستخدم بالفعل في مشروعك. اختر كوداً آخر.');
        }

        $type = (string) ($data['item_type'] ?? 'product');

        if (!in_array($type, ['product', 'raw_material', 'supply', 'service'], true)) {
            $type = 'product';
        }

        // الخدمة لا مخزون لها؛ لا يُترك الخيار لخطأ في النموذج
        $trackStock = $type === 'service' ? 0 : (int) (bool) ($data['track_stock'] ?? 1);

        $listingId = $this->nullableInt($data['listing_id'] ?? null);

        if ($listingId !== null) {
            $owns = Database::scalar(
                'SELECT 1 FROM listings
                  WHERE id = ? AND organization_id = ? AND deleted_at IS NULL LIMIT 1',
                [$listingId, $organizationId],
            );

            if ($owns === null) {
                throw new HttpException(404, 'الإعلان المطلوب ربطه غير موجود في مشروعك.');
            }

            $taken = Database::scalar(
                'SELECT 1 FROM erp_items
                  WHERE listing_id = ? AND deleted_at IS NULL'
                . ($exceptId !== null ? ' AND id <> ?' : '') . ' LIMIT 1',
                $exceptId !== null ? [$listingId, $exceptId] : [$listingId],
            );

            if ($taken !== null) {
                throw new HttpException(422, 'هذا الإعلان مرتبط بصنف آخر بالفعل.');
            }
        }

        return [
            'sku'             => mb_substr($sku, 0, 60),
            'name_ar'         => mb_substr($name, 0, 200),
            'description_ar'  => $this->nullableText($data['description_ar'] ?? null, 1000),
            'item_type'       => $type,
            'unit_of_measure' => mb_substr(trim((string) ($data['unit_of_measure'] ?? '')) ?: 'قطعة', 0, 40),
            'track_stock'     => $trackStock,
            'reorder_level'   => $trackStock === 1 ? $this->decimal($data['reorder_level'] ?? null) : null,
            'cost_price'      => $this->decimal($data['cost_price'] ?? null),
            'sale_price'      => $this->decimal($data['sale_price'] ?? null),
            'vat_rate'        => (float) ($data['vat_rate'] ?? 0),
            'listing_id'      => $listingId,
            'is_active'       => (int) (bool) ($data['is_active'] ?? 1),
        ];
    }

    private function decimal(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return round((float) $value, 3);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' || !is_numeric($value) ? null : (int) $value;
    }

    private function nullableText(mixed $value, int $length): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $length);
    }

    private function timestamp(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return date('Y-m-d H:i:s');
        }

        $time = strtotime($value);

        return $time === false ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', $time);
    }

    /** أنواع الحركات وتسمياتها | Movement types and their labels. */
    public function movementLabel(string $type): string
    {
        return match ($type) {
            'opening'       => 'رصيد افتتاحي',
            'purchase'      => 'مشتريات',
            'sale'          => 'مبيعات',
            'return_in'     => 'مرتجع وارد',
            'return_out'    => 'مرتجع صادر',
            'adjustment'    => 'تسوية جرد',
            'damage'        => 'تالف',
            'transfer_out'  => 'تحويل صادر',
            'production_in' => 'إنتاج وارد',
            default         => $type,
        };
    }
}
