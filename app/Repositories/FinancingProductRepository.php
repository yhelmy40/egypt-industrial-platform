<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * مستودع المنتجات التمويلية | Financing product repository (§4.5).
 *
 * التصفية هنا تعكس ما يبحث عنه صاحب المشروع فعلاً: مبلغ يحتاجه، نوع تمويل،
 * ومحافظة يعمل فيها. تصفية المبلغ تُطابق **شريحة المنتج** لا سعراً واحداً:
 * منتج شريحته 50–500 ألف يجب أن يظهر لمن يطلب 100 ألف.
 */
final class FinancingProductRepository extends ApprovableCatalogRepository
{
    protected function table(): string
    {
        return 'financing_products';
    }

    protected function publicColumns(): array
    {
        return [
            't.id', 't.name_ar', 't.slug', 't.short_description', 't.financing_type',
            't.min_amount', 't.max_amount', 't.currency_code',
            't.min_tenor_months', 't.max_tenor_months', 't.rate_note_ar',
            't.eligibility_summary_ar', 't.is_demo', 't.published_at',
            't.min_years_in_business', 't.min_annual_revenue', 't.requires_formal_registration',
            't.eligible_governorate_ids', 't.eligible_sector_ids',
        ];
    }

    protected function sorts(): array
    {
        return [
            'newest'     => 't.published_at DESC, t.id DESC',
            'amount_asc' => 't.min_amount ASC',
            'amount_desc' => 't.max_amount DESC',
            'popular'    => 't.application_count DESC, t.view_count DESC',
        ];
    }

    /** @param array<string,mixed> $filters */
    protected function applyFilters(array $filters, array &$where, array &$bindings): void
    {
        if (!empty($filters['financing_type'])
            && array_key_exists((string) $filters['financing_type'], \App\Services\FinancingProductService::TYPES)
        ) {
            $where[]    = 't.financing_type = ?';
            $bindings[] = (string) $filters['financing_type'];
        }

        // المبلغ المطلوب يقع داخل شريحة المنتج | The requested amount fits the band
        if (isset($filters['amount']) && is_numeric($filters['amount']) && (float) $filters['amount'] > 0) {
            $where[]    = '(t.min_amount IS NULL OR t.min_amount <= ?)';
            $bindings[] = (float) $filters['amount'];
            $where[]    = '(t.max_amount IS NULL OR t.max_amount >= ?)';
            $bindings[] = (float) $filters['amount'];
        }

        if (isset($filters['tenor_months']) && is_numeric($filters['tenor_months'])) {
            $where[]    = '(t.max_tenor_months IS NULL OR t.max_tenor_months >= ?)';
            $bindings[] = (int) $filters['tenor_months'];
        }

        if (!empty($filters['provider_organization_id'])) {
            $where[]    = 't.organization_id = ?';
            $bindings[] = (int) $filters['provider_organization_id'];
        }

        $this->matchIdList(
            't.eligible_governorate_ids',
            isset($filters['governorate_id']) ? (int) $filters['governorate_id'] : null,
            $where,
            $bindings,
        );

        $this->matchIdList(
            't.eligible_sector_ids',
            isset($filters['sector_id']) ? (int) $filters['sector_id'] : null,
            $where,
            $bindings,
        );
    }

    /**
     * المنتجات المعتمدة المرشَّحة للمطابقة | Approved products eligible for matching.
     *
     * تُقرأ كاملة لأن المطابقة تحتاج قواعد الأهلية نفسها لا بطاقة العرض.
     * العدد محدود بطبعه (منتجات معتمدة من مؤسسات موثّقة)، فالقراءة الكاملة
     * أبسط من محاولة ترجمة قواعد المطابقة إلى SQL.
     *
     * @return array<int,array<string,mixed>>
     */
    public function publishedForMatching(int $limit = 200): array
    {
        $limit = min(500, max(1, $limit));

        return Database::select(
            "SELECT t.*, o.legal_name, o.trading_name, o.slug AS provider_slug
               FROM financing_products t
               JOIN organizations o ON o.id = t.organization_id
              WHERE t.status = 'published'
                AND t.deleted_at IS NULL
                AND o.status = 'verified'
                AND o.deleted_at IS NULL
              ORDER BY t.published_at DESC
              LIMIT {$limit}",
        );
    }

    public function incrementApplications(int $id): void
    {
        Database::statement(
            'UPDATE financing_products SET application_count = application_count + 1 WHERE id = ?',
            [$id],
        );
    }
}
