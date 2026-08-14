<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * تقارير المنصة | Platform-wide reporting (§4.12).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * **ما تقيسه هذه التقارير هو نشاط المنصة لا أداء الاقتصاد.** الأرقام هنا
 * تُجيب عن أسئلة تشغيلية: كم منشأة سُجّلت وكم وُثّقت، وكم طلب تمويل أُحيل وكم
 * قرّرت فيه الجهات، وأين تتعثّر الطوابير.
 *
 * وثلاثة حدود مكتوبة في التقارير نفسها لا في هامش:
 *
 *  1. **لا يُعرض طلب كمقبول إلا إذا سجّلت الجهة المموّلة قبوله.** إحصاء
 *     «الموافقات» يعدّ قرارات الجهات لا قرارات المنصة، والمنصة لا تملك أصلاً
 *     إجراءً يقبل طلباً (§13).
 *  2. **البيانات التجريبية تُستبعد أو تُعلَن.** رقم يخلط الحقيقي بالتجريبي
 *     يُبنى عليه قرار خاطئ.
 *  3. **لا بيانات شخصية في التقارير ولا في التصدير.** التجميع على مستوى
 *     المنشأة والمحافظة والقطاع، لا على مستوى الأفراد (§10).
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class PlatformReportService
{
    /** التنويه المرافق لكل تقرير | The note carried by every report. */
    public const NOTE_AR =
        'هذه أرقام تشغيلية عن نشاط المنصة، تُحتسب لحظة العرض من قاعدة البيانات. '
        . 'لا تمثّل مؤشّرات اقتصادية معتمدة، ولا تُعدّ إحصاءً رسمياً عن قطاع '
        . 'المشروعات الصغيرة والمتوسطة في مصر.';

    /** تنويه القرارات | The decisions note (§13). */
    public const DECISIONS_NOTE_AR =
        'الموافقات والرفض المعروضة هنا قرارات سجّلتها الجهات المموّلة ومقدّمو '
        . 'الخدمات بأنفسهم. المنصة تفرز وتحيل ولا تقرّر، ولا يوجد فيها إجراء '
        . 'يقبل طلب تمويل أو يرفضه.';

    /**
     * لوحة تقارير المنصة | The platform report dashboard.
     *
     * @return array<string,mixed>
     */
    public function overview(string $from, string $to): array
    {
        [$from, $to] = $this->normalizePeriod($from, $to);

        return [
            'period'         => ['from' => $from, 'to' => $to],
            'organizations'  => $this->organizations($from, $to),
            'geography'      => $this->byGovernorate(),
            'sectors'        => $this->bySector(),
            'marketplace'    => $this->marketplace($from, $to),
            'financing'      => $this->financing($from, $to),
            'services'       => $this->services($from, $to),
            'bds'            => $this->bds($from, $to),
            'content'        => $this->content(),
            'queues'         => $this->queues(),
            'note'           => self::NOTE_AR,
            'decisions_note' => self::DECISIONS_NOTE_AR,
        ];
    }

    /** المنشآت والتوثيق | Organizations and verification. */
    public function organizations(string $from, string $to): array
    {
        $byStatus = Database::select(
            'SELECT o.status, COUNT(*) AS total
               FROM organizations o
              WHERE o.deleted_at IS NULL
           GROUP BY o.status',
        );

        $byType = Database::select(
            'SELECT t.code, t.name_ar, COUNT(o.id) AS total,
                    SUM(CASE WHEN o.status = \'verified\' THEN 1 ELSE 0 END) AS verified
               FROM organization_types t
          LEFT JOIN organizations o ON o.organization_type_id = t.id AND o.deleted_at IS NULL
           GROUP BY t.id, t.code, t.name_ar
           ORDER BY total DESC',
        );

        $registeredInPeriod = (int) Database::scalar(
            'SELECT COUNT(*) FROM organizations
              WHERE deleted_at IS NULL AND DATE(created_at) BETWEEN ? AND ?',
            [$from, $to],
        );

        // زمن التوثيق: مؤشّر خدمة يقيس المنصة نفسها لا المنشآت.
        // `organization_verifications` سجلّ انتقالات لا جدول طلبات، فالمدة
        // تُحسب بين قيد التقديم وقيد القرار للمنشأة نفسها.
        $verificationDays = Database::selectOne(
            "SELECT COUNT(*) AS decided,
                    AVG(DATEDIFF(d.created_at, s.created_at)) AS avg_days,
                    MAX(DATEDIFF(d.created_at, s.created_at)) AS max_days
               FROM organization_verifications d
               JOIN organization_verifications s
                 ON s.organization_id = d.organization_id
                AND s.to_status = 'submitted'
                AND s.created_at <= d.created_at
              WHERE d.to_status IN ('verified','rejected')
                AND DATE(d.created_at) BETWEEN ? AND ?",
            [$from, $to],
        );

        return [
            'by_status'            => $this->keyed($byStatus, 'status', 'total'),
            'by_type'              => $byType,
            'registered_in_period' => $registeredInPeriod,
            'verification'         => $verificationDays ?? [],
        ];
    }

    /** الانتشار الجغرافي | Geographic spread across the 27 governorates. */
    public function byGovernorate(): array
    {
        return Database::select(
            "SELECT g.id, g.name_ar,
                    COUNT(o.id) AS total,
                    SUM(CASE WHEN o.status = 'verified' THEN 1 ELSE 0 END) AS verified
               FROM governorates g
          LEFT JOIN organizations o ON o.governorate_id = g.id AND o.deleted_at IS NULL
           GROUP BY g.id, g.name_ar
           ORDER BY total DESC, g.name_ar ASC",
        );
    }

    /** التوزيع القطاعي | Sector distribution. */
    public function bySector(): array
    {
        return Database::select(
            'SELECT s.id, s.name_ar, COUNT(o.id) AS total
               FROM sectors s
          LEFT JOIN organizations o ON o.sector_id = s.id AND o.deleted_at IS NULL
           GROUP BY s.id, s.name_ar
           ORDER BY total DESC, s.name_ar ASC',
        );
    }

    /** السوق | Marketplace activity. */
    public function marketplace(string $from, string $to): array
    {
        $listings = Database::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published,
                    SUM(CASE WHEN status = 'pending_review' THEN 1 ELSE 0 END) AS pending
               FROM listings WHERE deleted_at IS NULL",
        );

        $orders = Database::selectOne(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','refunded')
                                      THEN total ELSE 0 END), 0) AS value,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                    SUM(CASE WHEN status = 'disputed' THEN 1 ELSE 0 END) AS disputed
               FROM orders
              WHERE deleted_at IS NULL AND DATE(created_at) BETWEEN ? AND ?",
            [$from, $to],
        );

        return ['listings' => $listings ?? [], 'orders' => $orders ?? []];
    }

    /**
     * التمويل | Financing.
     *
     * التقرير يفصل صراحةً بين **ما أحالته المنصة** و**ما قرّرته الجهات**:
     * دمجهما في رقم واحد يوحي بأن المنصة تقرّر، وهي لا تفعل (§13).
     */
    public function financing(string $from, string $to): array
    {
        $applications = Database::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) AS awaiting_screening,
                    SUM(CASE WHEN status = 'forwarded' THEN 1 ELSE 0 END) AS forwarded_by_platform,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_by_provider,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_by_provider,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_by_applicant
               FROM financing_applications
              WHERE deleted_at IS NULL AND DATE(created_at) BETWEEN ? AND ?",
            [$from, $to],
        );

        $products = Database::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published,
                    SUM(CASE WHEN status = 'pending_review' THEN 1 ELSE 0 END) AS pending,
                    SUM(is_demo) AS demo
               FROM financing_products WHERE deleted_at IS NULL",
        );

        return [
            'applications' => $applications ?? [],
            'products'     => $products ?? [],
            'note'         => self::DECISIONS_NOTE_AR,
        ];
    }

    /** الخدمات غير المالية | Non-financial services. */
    public function services(string $from, string $to): array
    {
        $requests = Database::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
               FROM service_requests
              WHERE deleted_at IS NULL AND DATE(created_at) BETWEEN ? AND ?",
            [$from, $to],
        );

        $offerings = Database::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published,
                    SUM(CASE WHEN status = 'pending_review' THEN 1 ELSE 0 END) AS pending
               FROM service_offerings WHERE deleted_at IS NULL",
        );

        return ['requests' => $requests ?? [], 'offerings' => $offerings ?? []];
    }

    /** مراكز تطوير الأعمال | BDS centre activity. */
    public function bds(string $from, string $to): array
    {
        $cases = Database::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'closed_completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN status IN ('requested','triage') THEN 1 ELSE 0 END) AS unassigned,
                    AVG(CASE WHEN closed_at IS NOT NULL
                             THEN DATEDIFF(closed_at, created_at) END) AS avg_days_to_close
               FROM bds_cases
              WHERE deleted_at IS NULL AND DATE(created_at) BETWEEN ? AND ?",
            [$from, $to],
        );

        $centres = Database::select(
            "SELECT o.id, o.trading_name, o.legal_name,
                    COUNT(c.id) AS cases,
                    SUM(CASE WHEN c.status = 'closed_completed' THEN 1 ELSE 0 END) AS completed
               FROM organizations o
               JOIN organization_types t ON t.id = o.organization_type_id
          LEFT JOIN bds_cases c ON c.center_organization_id = o.id AND c.deleted_at IS NULL
              WHERE t.code = 'bds_center' AND o.deleted_at IS NULL
           GROUP BY o.id, o.trading_name, o.legal_name
           ORDER BY cases DESC
              LIMIT 20",
        );

        return ['cases' => $cases ?? [], 'centres' => $centres];
    }

    /** المحتوى | Published content. */
    public function content(): array
    {
        $articles = Database::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published,
                    SUM(CASE WHEN status = 'pending_review' THEN 1 ELSE 0 END) AS pending,
                    COALESCE(SUM(view_count), 0) AS views
               FROM articles WHERE deleted_at IS NULL",
        );

        $faqs = Database::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published
               FROM faqs WHERE deleted_at IS NULL",
        );

        return ['articles' => $articles ?? [], 'faqs' => $faqs ?? []];
    }

    /**
     * الطوابير المعلّقة | Outstanding queues.
     *
     * هذا **مؤشّر عن المنصة نفسها**: طابور يطول يعني أن المنشآت تنتظر، وهو
     * أوّل ما يجب أن يراه مسؤول التشغيل.
     *
     * @return array<string,array{label:string,count:int,url:string,oldest_days:?int}>
     */
    public function queues(): array
    {
        return [
            // الطابور يُقرأ من حالة المنشأة نفسها لا من سجلّ الانتقالات:
            // السجلّ يحمل كل انتقال، والحالة الراهنة هي ما ينتظر قراراً.
            'verifications' => $this->queue(
                'توثيق منشآت',
                "SELECT COUNT(*) AS c, MAX(DATEDIFF(NOW(), updated_at)) AS d
                   FROM organizations
                  WHERE status IN ('submitted','under_review') AND deleted_at IS NULL",
                '/admin/verifications',
            ),
            'listings' => $this->queue(
                'مراجعة إعلانات',
                "SELECT COUNT(*) AS c, MAX(DATEDIFF(NOW(), updated_at)) AS d
                   FROM listings WHERE status = 'pending_review' AND deleted_at IS NULL",
                '/admin/moderation',
            ),
            'financing_products' => $this->queue(
                'اعتماد منتجات تمويلية',
                "SELECT COUNT(*) AS c, MAX(DATEDIFF(NOW(), updated_at)) AS d
                   FROM financing_products WHERE status = 'pending_review' AND deleted_at IS NULL",
                '/admin/finance/products',
            ),
            'service_offerings' => $this->queue(
                'اعتماد باقات خدمات',
                "SELECT COUNT(*) AS c, MAX(DATEDIFF(NOW(), updated_at)) AS d
                   FROM service_offerings WHERE status = 'pending_review' AND deleted_at IS NULL",
                '/admin/services/offerings',
            ),
            'financing_applications' => $this->queue(
                'فرز طلبات تمويل',
                "SELECT COUNT(*) AS c, MAX(DATEDIFF(NOW(), created_at)) AS d
                   FROM financing_applications WHERE status = 'submitted' AND deleted_at IS NULL",
                '/admin/finance/applications',
            ),
            'content' => $this->queue(
                'نشر محتوى',
                "SELECT COUNT(*) AS c, MAX(DATEDIFF(NOW(), updated_at)) AS d
                   FROM articles WHERE status = 'pending_review' AND deleted_at IS NULL",
                '/admin/articles?status=pending_review',
            ),
            'complaints' => $this->queue(
                'شكاوى مفتوحة',
                "SELECT COUNT(*) AS c, MAX(DATEDIFF(NOW(), created_at)) AS d
                   FROM complaints WHERE status IN ('new','under_review')",
                '/admin/complaints',
            ),
        ];
    }

    /** @return array{label:string,count:int,url:string,oldest_days:?int} */
    private function queue(string $label, string $sql, string $url): array
    {
        $row = Database::selectOne($sql);

        return [
            'label'       => $label,
            'count'       => (int) ($row['c'] ?? 0),
            'oldest_days' => $row['d'] === null ? null : (int) $row['d'],
            'url'         => $url,
        ];
    }

    // ═══════════════════ أدوات | Helpers ═══════════════════

    /**
     * @param  array<int,array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function keyed(array $rows, string $keyColumn, string $valueColumn): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row[$keyColumn]] = (int) $row[$valueColumn];
        }

        return $out;
    }

    /** @return array{0:string,1:string} */
    private function normalizePeriod(string $from, string $to): array
    {
        $fromTime = strtotime($from);
        $toTime   = strtotime($to);

        $from = $fromTime === false ? date('Y-m-01') : date('Y-m-d', $fromTime);
        $to   = $toTime === false ? date('Y-m-d') : date('Y-m-d', $toTime);

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    /** @return array{from:string,to:string} */
    public function defaultPeriod(): array
    {
        return ['from' => date('Y-m-d', strtotime('-90 days')), 'to' => date('Y-m-d')];
    }
}
