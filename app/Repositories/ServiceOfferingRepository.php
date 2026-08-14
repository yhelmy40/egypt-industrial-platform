<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * مستودع باقات الخدمات | Service offering repository (§4.6).
 *
 * التغطية الجغرافية هنا بمنطق مختلف عن المنتجات التمويلية: الباقة إما تغطي كل
 * المحافظات (`covers_all_governorates`) أو قائمة محدَّدة، والتصفية تحترم
 * الحالتين حتى لا تختفي باقة عامة عند تحديد محافظة.
 */
final class ServiceOfferingRepository extends ApprovableCatalogRepository
{
    protected function table(): string
    {
        return 'service_offerings';
    }

    protected function publicColumns(): array
    {
        return [
            't.id', 't.name_ar', 't.slug', 't.short_description', 't.service_type',
            't.delivery_mode', 't.duration_note_ar',
            't.pricing_mode', 't.price_from', 't.price_to', 't.currency_code', 't.funded_by_ar',
            't.target_audience_ar', 't.rating_average', 't.rating_count',
            't.is_demo', 't.published_at',
            't.covers_all_governorates', 't.governorate_ids', 't.sector_ids',
        ];
    }

    protected function sorts(): array
    {
        return [
            'newest'    => 't.published_at DESC, t.id DESC',
            'price_asc' => 't.price_from IS NULL, t.price_from ASC',
            'popular'   => 't.request_count DESC, t.view_count DESC',
            'rating'    => 't.rating_average DESC, t.rating_count DESC',
        ];
    }

    /** @param array<string,mixed> $filters */
    protected function applyFilters(array $filters, array &$where, array &$bindings): void
    {
        if (!empty($filters['service_type'])
            && array_key_exists((string) $filters['service_type'], \App\Services\ServiceOfferingService::TYPES)
        ) {
            $where[]    = 't.service_type = ?';
            $bindings[] = (string) $filters['service_type'];
        }

        if (!empty($filters['delivery_mode'])
            && array_key_exists((string) $filters['delivery_mode'], \App\Services\ServiceOfferingService::DELIVERY_MODES)
        ) {
            $where[]    = 't.delivery_mode = ?';
            $bindings[] = (string) $filters['delivery_mode'];
        }

        if (!empty($filters['free_only'])) {
            $where[] = "t.pricing_mode = 'free'";
        }

        if (!empty($filters['category_id'])) {
            $where[]    = 't.category_id = ?';
            $bindings[] = (int) $filters['category_id'];
        }

        if (!empty($filters['provider_organization_id'])) {
            $where[]    = 't.organization_id = ?';
            $bindings[] = (int) $filters['provider_organization_id'];
        }

        // الباقة العامة لا تختفي عند تحديد محافظة
        if (!empty($filters['governorate_id'])) {
            $where[]    = '(t.covers_all_governorates = 1 OR FIND_IN_SET(?, t.governorate_ids))';
            $bindings[] = (int) $filters['governorate_id'];
        }

        $this->matchIdList(
            't.sector_ids',
            isset($filters['sector_id']) ? (int) $filters['sector_id'] : null,
            $where,
            $bindings,
        );
    }

    /**
     * الباقات المعتمدة المرشَّحة للمطابقة | Approved offerings eligible for matching.
     *
     * @return array<int,array<string,mixed>>
     */
    public function publishedForMatching(int $limit = 200): array
    {
        $limit = min(500, max(1, $limit));

        return Database::select(
            "SELECT t.*, o.legal_name, o.trading_name, o.slug AS provider_slug
               FROM service_offerings t
               JOIN organizations o ON o.id = t.organization_id
              WHERE t.status = 'published'
                AND t.deleted_at IS NULL
                AND o.status = 'verified'
                AND o.deleted_at IS NULL
              ORDER BY t.published_at DESC
              LIMIT {$limit}",
        );
    }

    public function incrementRequests(int $id): void
    {
        Database::statement(
            'UPDATE service_offerings SET request_count = request_count + 1 WHERE id = ?',
            [$id],
        );
    }
}
