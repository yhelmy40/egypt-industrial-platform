<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;

/**
 * تصدير البيانات | Data export (§4.12, §10, §16).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * **قاعدتان تحكمان كل تصدير:**
 *
 * 1. **لا بيانات شخصية تخرج من المنصة.** المجموعات المتاحة للتصدير **مسرودة
 *    صراحةً** في `DATASETS` باستعلاماتها المكتوبة مسبقاً — لا يستقبل هذا
 *    الملف استعلاماً ولا اسم جدول ولا عمود من الطلب. لو قَبِل، لصار التصدير
 *    بوابة قراءة لكل شيء في قاعدة البيانات لمن يملك صلاحية واحدة.
 *
 *    ولذلك لا يوجد هنا استعلام واحد يقرأ `users.email` أو `users.phone` أو
 *    مستنداً أو ملاحظة داخلية. التجميع على مستوى المنشأة والمحافظة والقطاع.
 *
 * 2. **كل تصدير يُسجَّل في سجلّ التدقيق.** مَن صدّر، وماذا، ومتى، ومن أي
 *    عنوان. التصدير إخراج بيانات من النظام، وإخراج لا يُسجَّل لا يمكن التحقيق
 *    فيه لاحقاً.
 *
 * وحقن الصيغ (`=cmd|…`) مُعالَج في `escapeCell()`: خلية تبدأ بـ `=` أو `+`
 * أو `-` أو `@` قد تُنفَّذ عند فتح الملف في برنامج جداول.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class DataExportService
{
    /**
     * المجموعات المتاحة للتصدير | The exportable datasets.
     *
     * قائمة سماح مغلقة: الاسم وحده يأتي من الطلب، ويُطابَق بها.
     *
     * @var array<string,array{label:string,filename:string,headers:array<int,string>,sql:string}>
     */
    private const DATASETS = [
        'organizations_by_governorate' => [
            'label'    => 'المنشآت حسب المحافظة',
            'filename' => 'organizations-by-governorate',
            'headers'  => ['المحافظة', 'إجمالي المنشآت', 'الموثّقة', 'قيد المراجعة'],
            'sql'      => "SELECT g.name_ar,
                                  COUNT(o.id) AS total,
                                  SUM(CASE WHEN o.status = 'verified' THEN 1 ELSE 0 END) AS verified,
                                  SUM(CASE WHEN o.status IN ('submitted','under_review')
                                           THEN 1 ELSE 0 END) AS under_review
                             FROM governorates g
                        LEFT JOIN organizations o ON o.governorate_id = g.id AND o.deleted_at IS NULL
                         GROUP BY g.id, g.name_ar
                         ORDER BY total DESC",
        ],
        'organizations_by_sector' => [
            'label'    => 'المشروعات حسب القطاع',
            'filename' => 'organizations-by-sector',
            'headers'  => ['القطاع', 'عدد المشروعات'],
            'sql'      => 'SELECT s.name_ar, COUNT(o.id) AS total
                             FROM sectors s
                        LEFT JOIN organizations o ON o.sector_id = s.id AND o.deleted_at IS NULL
                         GROUP BY s.id, s.name_ar
                         ORDER BY total DESC',
        ],
        'organizations_by_type' => [
            'label'    => 'الحسابات حسب النوع',
            'filename' => 'organizations-by-type',
            'headers'  => ['نوع الحساب', 'الإجمالي', 'الموثّقة'],
            'sql'      => "SELECT t.name_ar, COUNT(o.id) AS total,
                                  SUM(CASE WHEN o.status = 'verified' THEN 1 ELSE 0 END) AS verified
                             FROM organization_types t
                        LEFT JOIN organizations o ON o.organization_type_id = t.id AND o.deleted_at IS NULL
                         GROUP BY t.id, t.name_ar
                         ORDER BY total DESC",
        ],
        'financing_funnel' => [
            'label'    => 'مسار طلبات التمويل',
            'filename' => 'financing-funnel',
            'headers'  => ['الحالة', 'عدد الطلبات'],
            'sql'      => 'SELECT status, COUNT(*) AS total
                             FROM financing_applications
                            WHERE deleted_at IS NULL
                         GROUP BY status
                         ORDER BY total DESC',
        ],
        'marketplace_activity' => [
            'label'    => 'نشاط السوق شهرياً',
            'filename' => 'marketplace-activity',
            'headers'  => ['الشهر', 'عدد الطلبات', 'قيمة الطلبات (ج.م)'],
            'sql'      => "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month,
                                  COUNT(*) AS orders,
                                  COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','refunded')
                                                    THEN total ELSE 0 END), 0) AS value
                             FROM orders
                            WHERE deleted_at IS NULL
                         GROUP BY month
                         ORDER BY month DESC
                            LIMIT 36",
        ],
        'bds_centre_activity' => [
            'label'    => 'نشاط مراكز تطوير الأعمال',
            'filename' => 'bds-centre-activity',
            'headers'  => ['المركز', 'إجمالي الحالات', 'المكتملة', 'متوسط أيام الإغلاق'],
            'sql'      => "SELECT COALESCE(o.trading_name, o.legal_name) AS centre,
                                  COUNT(c.id) AS cases,
                                  SUM(CASE WHEN c.status = 'closed_completed' THEN 1 ELSE 0 END) AS completed,
                                  ROUND(AVG(CASE WHEN c.closed_at IS NOT NULL
                                                 THEN DATEDIFF(c.closed_at, c.created_at) END), 1) AS avg_days
                             FROM organizations o
                             JOIN organization_types t ON t.id = o.organization_type_id
                        LEFT JOIN bds_cases c ON c.center_organization_id = o.id AND c.deleted_at IS NULL
                            WHERE t.code = 'bds_center' AND o.deleted_at IS NULL
                         GROUP BY o.id, centre
                         ORDER BY cases DESC",
        ],
        'verification_turnaround' => [
            'label'    => 'زمن البتّ في التوثيق',
            'filename' => 'verification-turnaround',
            'headers'  => ['الشهر', 'القرارات', 'متوسط الأيام', 'أطول مدة'],
            'sql'      => "SELECT DATE_FORMAT(d.created_at, '%Y-%m') AS month,
                                  COUNT(*) AS decided,
                                  ROUND(AVG(DATEDIFF(d.created_at, s.created_at)), 1) AS avg_days,
                                  MAX(DATEDIFF(d.created_at, s.created_at)) AS max_days
                             FROM organization_verifications d
                             JOIN organization_verifications s
                               ON s.organization_id = d.organization_id
                              AND s.to_status = 'submitted'
                              AND s.created_at <= d.created_at
                            WHERE d.to_status IN ('verified','rejected')
                         GROUP BY month
                         ORDER BY month DESC
                            LIMIT 36",
        ],
    ];

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /**
     * توليد ملف CSV | Produce the CSV payload.
     *
     * @return array{filename:string,content:string}
     */
    public function export(string $dataset, ?int $actorId, ?Request $request = null): array
    {
        if (!array_key_exists($dataset, self::DATASETS)) {
            throw new HttpException(404, 'مجموعة البيانات غير معروفة.');
        }

        $definition = self::DATASETS[$dataset];
        $rows       = Database::select($definition['sql']);

        // التسجيل قبل الإرجاع: تصدير يخرج بلا أثر لا يمكن التحقيق فيه
        $this->audit->setRequest($request);
        $this->audit->log(
            action: 'data.exported',
            category: AuditLogger::CATEGORY_EXPORT,
            entityType: 'export',
            description: 'تصدير مجموعة «' . $definition['label'] . '» — '
                       . count($rows) . ' صفّاً',
        );

        return [
            'filename' => $definition['filename'] . '-' . date('Ymd-His') . '.csv',
            'content'  => $this->toCsv($definition['headers'], $rows),
        ];
    }

    /**
     * بناء نصّ CSV | Build the CSV text.
     *
     * يبدأ بعلامة ترتيب البايتات (BOM) لأن Excel على ويندوز يقرأ الملف بترميز
     * النظام المحلي دونها، فتظهر العربية حروفاً مشوّشة — وهو أول ما يشتكي منه
     * مستخدم يفتح تقريراً عربياً.
     *
     * @param array<int,string>              $headers
     * @param array<int,array<string,mixed>> $rows
     */
    private function toCsv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new HttpException(500, 'تعذّر تجهيز ملف التصدير.');
        }

        fwrite($handle, "\xEF\xBB\xBF");

        // معاملات fputcsv الأربعة صريحة: PHP 8.4 يحذّر من الاعتماد على قيمة
        // `$escape` الافتراضية لأنها ستتغيّر، والتحذير يصير خطأً في هذا الإعداد.
        fputcsv($handle, $headers, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (mixed $value): string => $this->escapeCell($value),
                array_values($row),
            ), ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * تحييد حقن الصيغ | Neutralise formula injection.
     *
     * خلية تبدأ بـ `=` أو `+` أو `-` أو `@` تُفسَّر كصيغة في برامج الجداول،
     * وقد تُنفّذ أمراً على جهاز من يفتح الملف. البادئة `'` تجعلها نصّاً.
     */
    private function escapeCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = (string) $value;

        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $text;
        }

        return $text;
    }

    /**
     * المجموعات المتاحة للعرض | The datasets, for the interface.
     *
     * @return array<string,string>
     */
    public function datasets(): array
    {
        $out = [];

        foreach (self::DATASETS as $key => $definition) {
            $out[$key] = $definition['label'];
        }

        return $out;
    }
}
