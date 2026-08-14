<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Support\Money;

/**
 * سلة الشراء | Shopping cart (§4.4).
 *
 * تدعم الزائر والمستخدم المسجَّل. سلة الزائر مرتبطة برمز عشوائي في كوكي، وعند
 * تسجيل الدخول تُدمج في سلة المستخدم بدل أن تُفقد — فقدان السلة عند تسجيل
 * الدخول سبب شائع لهجر الشراء.
 * Guest carts are keyed by a random cookie token and merged into the user's
 * cart at login rather than discarded; losing a cart at sign-in is a common
 * cause of abandonment.
 *
 * الأصناف بوضع «اطلب عرض سعر» لا تدخل السلة إطلاقاً — لها مسارها الخاص.
 */
final class CartService
{
    public const COOKIE_NAME = 'np_cart';

    /** جلب سلة أو إنشاؤها | Get or create the active cart. */
    public function resolveCart(?int $userId, ?string $guestToken, bool $createIfMissing = true): ?array
    {
        if ($userId !== null) {
            $cart = Database::selectOne(
                "SELECT * FROM carts WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
                [$userId],
            );

            if ($cart !== null) {
                return $cart;
            }

            if (!$createIfMissing) {
                return null;
            }

            $id = Database::insert('INSERT INTO carts (user_id, status) VALUES (?, ?)', [$userId, 'active']);

            return Database::selectOne('SELECT * FROM carts WHERE id = ?', [$id]);
        }

        if ($guestToken !== null && $guestToken !== '') {
            $cart = Database::selectOne(
                "SELECT * FROM carts WHERE guest_token = ? AND status = 'active' LIMIT 1",
                [$guestToken],
            );

            if ($cart !== null) {
                return $cart;
            }
        }

        if (!$createIfMissing) {
            return null;
        }

        $token = $guestToken !== null && $guestToken !== '' ? $guestToken : bin2hex(random_bytes(32));
        $id    = Database::insert('INSERT INTO carts (guest_token, status) VALUES (?, ?)', [$token, 'active']);

        return Database::selectOne('SELECT * FROM carts WHERE id = ?', [$id]);
    }

    /**
     * دمج سلة الزائر في سلة المستخدم بعد تسجيل الدخول | Merge guest cart at login.
     */
    public function mergeGuestCart(string $guestToken, int $userId): void
    {
        Database::transaction(function () use ($guestToken, $userId): void {
            $guestCart = Database::selectOne(
                "SELECT * FROM carts WHERE guest_token = ? AND status = 'active' LIMIT 1",
                [$guestToken],
            );

            if ($guestCart === null) {
                return;
            }

            $userCart = $this->resolveCart($userId, null);

            if ($userCart === null || (int) $userCart['id'] === (int) $guestCart['id']) {
                return;
            }

            foreach (Database::select('SELECT * FROM cart_items WHERE cart_id = ?', [(int) $guestCart['id']]) as $item) {
                // الصنف الموجود في السلتين: تُجمع الكميتان بدل استبدال إحداهما
                Database::statement(
                    'INSERT INTO cart_items
                        (cart_id, listing_id, seller_organization_id, quantity, unit_price, vat_rate)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)',
                    [
                        (int) $userCart['id'], (int) $item['listing_id'],
                        (int) $item['seller_organization_id'], (float) $item['quantity'],
                        $item['unit_price'], $item['vat_rate'],
                    ],
                );
            }

            Database::statement('DELETE FROM cart_items WHERE cart_id = ?', [(int) $guestCart['id']]);
            Database::statement("UPDATE carts SET status = 'abandoned' WHERE id = ?", [(int) $guestCart['id']]);
        });
    }

    /** إضافة صنف | Add a listing to the cart. */
    public function add(int $cartId, int $listingId, float $quantity): void
    {
        $listing = Database::selectOne(
            "SELECT l.*, o.status AS seller_status
               FROM listings l
               JOIN organizations o ON o.id = l.organization_id
              WHERE l.id = ? AND l.deleted_at IS NULL
              LIMIT 1",
            [$listingId],
        );

        if ($listing === null || $listing['status'] !== 'published') {
            throw new HttpException(404, 'الصنف المطلوب غير متاح.');
        }

        if ($listing['seller_status'] !== 'verified') {
            throw new HttpException(422, 'المنشأة البائعة غير متاحة حالياً.');
        }

        if ($listing['pricing_mode'] !== 'fixed') {
            throw new HttpException(
                422,
                'هذا الصنف يُطلب بعرض سعر. استخدم زر «اطلب عرض سعر» بدلاً من السلة.',
            );
        }

        $minimum = (float) $listing['min_order_quantity'];

        if ($quantity < $minimum) {
            $quantity = $minimum;
        }

        if ((int) $listing['track_inventory'] === 1 && (float) $listing['available_quantity'] < $quantity) {
            throw new HttpException(
                422,
                'الكمية المتاحة هي ' . number_ar((float) $listing['available_quantity'], 0) . ' فقط.',
            );
        }

        Database::statement(
            'INSERT INTO cart_items
                (cart_id, listing_id, seller_organization_id, quantity, unit_price, vat_rate)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity),
                                     unit_price = VALUES(unit_price),
                                     vat_rate = VALUES(vat_rate)',
            [
                $cartId, $listingId, (int) $listing['organization_id'], $quantity,
                $listing['price'], $listing['vat_rate'],
            ],
        );
    }

    public function updateQuantity(int $cartId, int $itemId, float $quantity): void
    {
        if ($quantity <= 0) {
            $this->remove($cartId, $itemId);

            return;
        }

        Database::statement(
            'UPDATE cart_items SET quantity = ? WHERE id = ? AND cart_id = ?',
            [$quantity, $itemId, $cartId],
        );
    }

    public function remove(int $cartId, int $itemId): void
    {
        Database::statement('DELETE FROM cart_items WHERE id = ? AND cart_id = ?', [$itemId, $cartId]);
    }

    public function clear(int $cartId): void
    {
        Database::statement('DELETE FROM cart_items WHERE cart_id = ?', [$cartId]);
    }

    /**
     * محتويات السلة مجمّعة حسب البائع | Cart contents grouped by seller.
     *
     * تُعرض السلة مجمّعة لأنها ستنقسم إلى طلبات منفصلة عند الدفع — إخفاء ذلك
     * حتى صفحة التأكيد يفاجئ العميل بثلاثة طلبات بدل واحد.
     * The cart is shown grouped because it will split into separate orders at
     * checkout; hiding that until the confirmation page surprises the customer.
     *
     * @return array{groups:array<int,array<string,mixed>>,totals:array<string,string>,count:int}
     */
    public function contents(int $cartId): array
    {
        $items = Database::select(
            'SELECT ci.*, l.name_ar, l.slug, l.status AS listing_status, l.pricing_mode,
                    l.price AS current_price, l.vat_rate AS current_vat_rate,
                    l.available_quantity, l.track_inventory, l.min_order_quantity,
                    l.unit_of_measure, l.primary_media_id, l.delivery_fee,
                    o.id AS seller_id, o.legal_name, o.trading_name, o.slug AS seller_slug,
                    o.status AS seller_status
               FROM cart_items ci
               JOIN listings l ON l.id = ci.listing_id
               JOIN organizations o ON o.id = ci.seller_organization_id
              WHERE ci.cart_id = ?
              ORDER BY o.id, ci.id',
            [$cartId],
        );

        $groups   = [];
        $subtotal = Money::zero();
        $vatTotal = Money::zero();
        $delivery = Money::zero();
        $count    = 0;

        foreach ($items as $item) {
            $sellerId = (int) $item['seller_id'];

            $quantity  = (float) $item['quantity'];
            $unitPrice = Money::fromDecimal((string) ($item['current_price'] ?? 0));
            $lineSub   = $unitPrice->times($quantity);
            $lineVat   = $lineSub->percentage((float) $item['current_vat_rate']);

            // مشكلات تمنع إتمام الشراء تُعرض على الصنف نفسه لا كخطأ عام
            $issue = null;
            if ($item['listing_status'] !== 'published') {
                $issue = 'لم يعد هذا الصنف متاحاً.';
            } elseif ($item['seller_status'] !== 'verified') {
                $issue = 'المنشأة البائعة غير متاحة حالياً.';
            } elseif ((int) $item['track_inventory'] === 1 && (float) $item['available_quantity'] < $quantity) {
                $issue = 'الكمية المتاحة ' . number_ar((float) $item['available_quantity'], 0) . ' فقط.';
            } elseif ($item['current_price'] !== null
                && (float) $item['current_price'] !== (float) $item['unit_price']) {
                $issue = 'تغيّر سعر هذا الصنف منذ إضافته.';
            }

            $groups[$sellerId] ??= [
                'seller_id'    => $sellerId,
                'seller_name'  => $item['trading_name'] ?: $item['legal_name'],
                'seller_slug'  => $item['seller_slug'],
                'items'        => [],
                'subtotal'     => Money::zero(),
                'delivery_fee' => Money::zero(),
            ];

            $groups[$sellerId]['items'][] = [
                'id'           => (int) $item['id'],
                'listing_id'   => (int) $item['listing_id'],
                'name'         => (string) $item['name_ar'],
                'slug'         => (string) $item['slug'],
                'media_id'     => $item['primary_media_id'],
                'unit'         => $item['unit_of_measure'],
                'quantity'     => $quantity,
                'unit_price'   => $unitPrice,
                'line_total'   => $lineSub->plus($lineVat),
                'issue'        => $issue,
            ];

            $groups[$sellerId]['subtotal'] = $groups[$sellerId]['subtotal']->plus($lineSub);

            if ($item['delivery_fee'] !== null) {
                $fee = Money::fromDecimal((string) $item['delivery_fee']);
                if ($fee->greaterThan($groups[$sellerId]['delivery_fee'])) {
                    $groups[$sellerId]['delivery_fee'] = $fee;
                }
            }

            $subtotal = $subtotal->plus($lineSub);
            $vatTotal = $vatTotal->plus($lineVat);
            $count++;
        }

        foreach ($groups as $group) {
            $delivery = $delivery->plus($group['delivery_fee']);
        }

        return [
            'groups' => array_values($groups),
            'totals' => [
                'subtotal' => $subtotal->format(),
                'vat'      => $vatTotal->format(),
                'delivery' => $delivery->format(),
                'total'    => $subtotal->plus($vatTotal)->plus($delivery)->format(),
            ],
            'count' => $count,
        ];
    }

    public function itemCount(int $cartId): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM cart_items WHERE cart_id = ?', [$cartId]);
    }

    /** هل السلة صالحة للدفع؟ | Are there blocking issues? @return array<int,string> */
    public function blockingIssues(int $cartId): array
    {
        $issues = [];

        foreach ($this->contents($cartId)['groups'] as $group) {
            foreach ($group['items'] as $item) {
                if ($item['issue'] !== null) {
                    $issues[] = $item['name'] . ': ' . $item['issue'];
                }
            }
        }

        return $issues;
    }
}
