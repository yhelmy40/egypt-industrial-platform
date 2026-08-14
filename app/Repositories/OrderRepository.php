<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * مستودع الطلبات | Order repository.
 *
 * مقيَّد بالمنشأة البائعة. المشتري يصل لطلبه عبر رمز التتبّع أو حسابه، لا عبر
 * هذا المستودع — فهو واجهة البائع.
 * Tenant-scoped to the selling organization. Buyers reach their order through
 * a tracking token or their account, not through this repository.
 */
final class OrderRepository extends BaseRepository
{
    protected string $table = 'orders';

    protected bool $tenantScoped = true;

    protected bool $softDeletes = true;

    protected array $sortable = ['id', 'order_number', 'status', 'total', 'created_at'];

    /** طلبات البائع مع التصفية | The seller's orders. */
    public function forOrganization(int $organizationId, ?string $status, string $term, int $page, int $perPage = 20): array
    {
        $where    = ['o.organization_id = ?', 'o.deleted_at IS NULL'];
        $bindings = [$organizationId];

        if ($status !== null && $status !== '') {
            $where[]    = 'o.status = ?';
            $bindings[] = $status;
        }

        if (trim($term) !== '') {
            $where[]    = '(o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)';
            $like       = '%' . trim($term) . '%';
            $bindings   = array_merge($bindings, [$like, $like, $like]);
        }

        $whereSql = implode(' AND ', $where);
        $total    = (int) Database::scalar("SELECT COUNT(*) FROM orders o WHERE {$whereSql}", $bindings);

        $perPage = min(100, max(1, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            "SELECT o.*, g.name_ar AS governorate_name,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
               FROM orders o
               LEFT JOIN governorates g ON g.id = o.governorate_id
              WHERE {$whereSql}
              ORDER BY FIELD(o.status,'new','confirmed','preparing','ready','shipped','delivered','disputed','completed','cancelled','refunded'),
                       o.created_at DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $bindings,
        );

        return [
            'data'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /** طلب مع تفاصيله | An order with its lines and history. */
    public function findWithDetails(int $orderId, int $organizationId): ?array
    {
        $order = Database::selectOne(
            'SELECT o.*, g.name_ar AS governorate_name, c.name_ar AS city_name,
                    pm.name_ar AS payment_method_name
               FROM orders o
               LEFT JOIN governorates g ON g.id = o.governorate_id
               LEFT JOIN cities c ON c.id = o.city_id
               LEFT JOIN payment_methods pm ON pm.id = o.payment_method_id
              WHERE o.id = ? AND o.organization_id = ? AND o.deleted_at IS NULL
              LIMIT 1',
            [$orderId, $organizationId],
        );

        if ($order === null) {
            return null;
        }

        $order['items']   = $this->items($orderId);
        $order['history'] = $this->history($orderId);

        return $order;
    }

    /**
     * طلب عبر رمز التتبّع | An order by its tracking token.
     *
     * يسمح للزائر بمتابعة طلبه دون حساب. الرمز عشوائي 48 حرفاً فلا يُخمَّن،
     * وهو المُعرِّف الوحيد المقبول هنا — لا يُقبل معرّف الطلب الرقمي إطلاقاً.
     * Lets a guest track an order without an account. The token is 48 random
     * characters and is the only accepted identifier here — never the numeric id.
     */
    public function findByTrackingToken(string $token): ?array
    {
        if (strlen($token) !== 48) {
            return null;
        }

        $order = Database::selectOne(
            'SELECT o.*, g.name_ar AS governorate_name, c.name_ar AS city_name,
                    pm.name_ar AS payment_method_name, pm.instructions_ar AS payment_instructions,
                    org.legal_name AS seller_legal_name, org.trading_name AS seller_trading_name,
                    org.slug AS seller_slug, org.public_phone AS seller_phone
               FROM orders o
               JOIN organizations org ON org.id = o.organization_id
               LEFT JOIN governorates g ON g.id = o.governorate_id
               LEFT JOIN cities c ON c.id = o.city_id
               LEFT JOIN payment_methods pm ON pm.id = o.payment_method_id
              WHERE o.tracking_token = ? AND o.deleted_at IS NULL
              LIMIT 1',
            [$token],
        );

        if ($order === null) {
            return null;
        }

        $order['items']   = $this->items((int) $order['id']);
        $order['history'] = $this->history((int) $order['id']);

        return $order;
    }

    /** @return array<int,array<string,mixed>> */
    public function items(int $orderId): array
    {
        return Database::select(
            'SELECT oi.*, l.slug AS listing_slug, l.primary_media_id
               FROM order_items oi
               LEFT JOIN listings l ON l.id = oi.listing_id
              WHERE oi.order_id = ?
              ORDER BY oi.id ASC',
            [$orderId],
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $orderId): array
    {
        return Database::select(
            'SELECT h.*, u.name AS actor_name
               FROM order_status_history h
               LEFT JOIN users u ON u.id = h.actor_user_id
              WHERE h.order_id = ?
              ORDER BY h.created_at ASC, h.id ASC',
            [$orderId],
        );
    }

    /** طلبات عميل مسجَّل | A registered customer's orders. */
    public function forCustomer(int $userId, int $limit = 50): array
    {
        $limit = min(100, max(1, $limit));

        return Database::select(
            "SELECT o.*, org.legal_name, org.trading_name, org.slug AS seller_slug
               FROM orders o
               JOIN organizations org ON org.id = o.organization_id
              WHERE o.customer_user_id = ? AND o.deleted_at IS NULL
              ORDER BY o.created_at DESC
              LIMIT {$limit}",
            [$userId],
        );
    }

    /** @return array<string,int> */
    public function countsByStatus(int $organizationId): array
    {
        $rows = Database::select(
            'SELECT status, COUNT(*) AS total FROM orders
              WHERE organization_id = ? AND deleted_at IS NULL GROUP BY status',
            [$organizationId],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * ملخّص مبيعات المنشأة | Sales summary for the seller dashboard.
     *
     * يُحتسب فقط ما وصل إلى «تم التسليم» أو «مكتمل» — احتساب الطلبات الملغاة
     * أو محل النزاع ضمن المبيعات يعطي صاحب المشروع رقماً لا يقبضه.
     * Only delivered and completed orders count: including cancelled or
     * disputed ones would show revenue the owner never receives.
     *
     * @return array<string,mixed>
     */
    public function salesSummary(int $organizationId, int $days = 30): array
    {
        $row = Database::selectOne(
            "SELECT
                COUNT(*) AS order_count,
                COALESCE(SUM(CASE WHEN status IN ('delivered','completed') THEN total ELSE 0 END), 0) AS realised_sales,
                COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','refunded') THEN total ELSE 0 END), 0) AS pipeline_value,
                SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) AS new_orders,
                SUM(CASE WHEN status = 'disputed' THEN 1 ELSE 0 END) AS disputed_orders
               FROM orders
              WHERE organization_id = ?
                AND deleted_at IS NULL
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$organizationId, $days],
        );

        return $row ?? [];
    }

    /** هل للعميل طلب مكتمل مع هذه المنشأة؟ | Completed-order check for reviews. */
    public function hasCompletedOrder(int $orderId, ?int $customerUserId, ?string $trackingToken): ?array
    {
        if ($trackingToken !== null && $trackingToken !== '') {
            return Database::selectOne(
                "SELECT * FROM orders
                  WHERE id = ? AND tracking_token = ? AND status = 'completed' AND deleted_at IS NULL
                  LIMIT 1",
                [$orderId, $trackingToken],
            );
        }

        if ($customerUserId !== null) {
            return Database::selectOne(
                "SELECT * FROM orders
                  WHERE id = ? AND customer_user_id = ? AND status = 'completed' AND deleted_at IS NULL
                  LIMIT 1",
                [$orderId, $customerUserId],
            );
        }

        return null;
    }
}
