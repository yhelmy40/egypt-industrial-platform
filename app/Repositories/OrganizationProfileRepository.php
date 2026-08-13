<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use RuntimeException;

/**
 * مستودع ملفات المنشآت التفصيلية | Organization profile repository.
 *
 * يتعامل مع الجداول 1:1 الثلاثة (`sme_profiles`، `provider_profiles`،
 * `bds_centers`) خلف واجهة واحدة يختار الجدول حسب نوع المنشأة.
 * Handles the three 1:1 profile tables behind one interface that selects the
 * table from the organization type.
 *
 * لا يرث BaseRepository لأنه لا يخدم جدولاً واحداً مفتاحه `id`: هو يمتدّ على
 * ثلاثة جداول مفتاحها `organization_id`، فعقد الأساس لا ينطبق عليه. العزل هنا
 * مضمون بأن كل استعلام مقيّد بـ organization_id الممرَّر من سياق مُتحقَّق منه.
 * It does not extend BaseRepository: it spans three tables keyed by
 * organization_id rather than one table keyed by id, so the base contract does
 * not apply. Isolation holds because every query is bound to an
 * organization_id that comes from a verified context.
 *
 * الأمان: اسم الجدول لا يأتي من مدخلات المستخدم أبداً — يُشتقّ من `code` في
 * `organization_types` عبر خريطة ثابتة، ثم يُتحقق منه قبل الاستخدام.
 * The table name never comes from user input: it is derived from the
 * organization type code through a fixed map and validated before use.
 */
final class OrganizationProfileRepository
{

    /** خريطة ثابتة: نوع المنشأة → جدول الملف | Fixed type → table map. */
    private const TABLES = [
        'sme'              => 'sme_profiles',
        'service_provider' => 'provider_profiles',
        'bank'             => 'provider_profiles',
        'ngo'              => 'provider_profiles',
        'government'       => 'provider_profiles',
        'bds_center'       => 'bds_centers',
    ];

    public function tableFor(string $organizationTypeCode): string
    {
        $table = self::TABLES[$organizationTypeCode] ?? null;

        if ($table === null) {
            throw new RuntimeException('نوع منشأة غير مدعوم: ' . $organizationTypeCode);
        }

        return $table;
    }

    public function findForOrganization(int $organizationId, string $organizationTypeCode): ?array
    {
        $table = $this->tableFor($organizationTypeCode);

        return Database::selectOne(
            "SELECT * FROM `{$table}` WHERE organization_id = ? LIMIT 1",
            [$organizationId],
        );
    }

    /**
     * إنشاء أو تحديث الملف | Create or update the profile row.
     *
     * الأعمدة تُصفّى مقابل مخطط الجدول الفعلي، فلا يمكن لحقل مُرسَل من النموذج
     * أن يكتب في عمود غير متوقّع.
     * Columns are filtered against the real table schema, so a posted field
     * cannot write into an unexpected column.
     */
    public function save(int $organizationId, string $organizationTypeCode, array $data): int
    {
        $table   = $this->tableFor($organizationTypeCode);
        $allowed = $this->columnsOf($table);

        $clean = [];
        foreach ($data as $column => $value) {
            if (in_array($column, $allowed, true)
                && !in_array($column, ['id', 'organization_id', 'created_at', 'updated_at'], true)
            ) {
                $clean[$column] = $value === '' ? null : $value;
            }
        }

        $existing = Database::selectOne(
            "SELECT id FROM `{$table}` WHERE organization_id = ? LIMIT 1",
            [$organizationId],
        );

        if ($existing === null) {
            $clean['organization_id'] = $organizationId;
            $columns                  = array_keys($clean);

            return Database::insert(
                "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . '`) VALUES ('
                . implode(', ', array_fill(0, count($columns), '?')) . ')',
                array_values($clean),
            );
        }

        if ($clean === []) {
            return (int) $existing['id'];
        }

        $sets = [];
        foreach (array_keys($clean) as $column) {
            $sets[] = "`{$column}` = ?";
        }

        Database::statement(
            "UPDATE `{$table}` SET " . implode(', ', $sets) . ' WHERE organization_id = ?',
            array_merge(array_values($clean), [$organizationId]),
        );

        return (int) $existing['id'];
    }

    /**
     * أعمدة الجدول من المخطط | Table columns from the schema.
     *
     * @return array<int,string>
     */
    private function columnsOf(string $table): array
    {
        static $cache = [];

        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $rows = Database::select(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );

        return $cache[$table] = array_column($rows, 'COLUMN_NAME');
    }
}
