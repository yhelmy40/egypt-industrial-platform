<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Services\DataExportService;
use Tests\TestCase;

/**
 * خصوصية التصدير | Export privacy (§4.12, §10, §16).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * التصدير **إخراج بيانات من النظام**، وهو أخطر ما يفعله مستخدم إداري: ملف
 * يخرج مرة واحدة يبقى خارج سيطرة المنصة إلى الأبد.
 *
 * قاعدتان محروستان هنا:
 *  1. **لا بيانات شخصية في أي مجموعة.** لا بريد ولا هاتف ولا اسم فرد ولا
 *     ملاحظة داخلية. التجميع على مستوى المحافظة والقطاع والحالة.
 *  2. **لا يستقبل التصدير استعلاماً ولا اسم جدول من الطلب.** الاسم وحده
 *     يأتي من المستخدم، ويُطابَق بقائمة سماح مغلقة.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class DataExportPrivacyTest extends TestCase
{
    private DataExportService $exports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exports = new DataExportService();
    }

    // ═══════════════════ لا بيانات شخصية | No personal data ═══════════════════

    /**
     * لا مجموعة تُخرج بيانات شخصية | No dataset emits personal data.
     *
     * الاختبار يزرع بيانات شخصية مميّزة ثم يصدّر **كل** المجموعات ويبحث عنها.
     * لو أضاف مطوّر مجموعة تقرأ بريداً أو هاتفاً، يسقط هذا الاختبار.
     */
    public function test_no_dataset_emits_personal_data(): void
    {
        $needleEmail = 'canary-' . bin2hex(random_bytes(4)) . '@private.test';
        $needlePhone = '01099887766';
        $needleName  = 'كناري الخصوصية الفريد';

        $userId = $this->createUser(['email' => $needleEmail, 'name' => $needleName]);
        $orgId  = $this->createOrganization(['status' => 'verified']);

        Database::statement('UPDATE users SET phone = ? WHERE id = ?', [$needlePhone, $userId]);

        // ملاحظة مراجعة داخلية: لا يجوز أن تخرج في أي تصدير
        $needleNote = 'ملاحظة داخلية سرّية للغاية';
        Database::insert(
            'INSERT INTO organization_verifications
                (organization_id, from_status, to_status, action, actor_user_id, internal_note)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$orgId, 'submitted', 'verified', 'approve', $userId, $needleNote],
        );

        foreach (array_keys($this->exports->datasets()) as $dataset) {
            $csv = $this->exports->export($dataset, $userId)['content'];

            foreach ([$needleEmail, $needlePhone, $needleName, $needleNote] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $csv,
                    "المجموعة «{$dataset}» سرّبت بياناً شخصياً.",
                );
            }
        }
    }

    /** لا استعلام من الطلب | The dataset name is matched, never interpolated. */
    public function test_an_unknown_dataset_is_refused(): void
    {
        try {
            $this->exports->export('users', null);
            $this->fail('كان يجب رفض مجموعة غير معروفة.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    /** محاولة حقن استعلام تُرفض كاسم مجهول | An injected query is just an unknown name. */
    public function test_an_injected_query_is_refused_as_an_unknown_name(): void
    {
        foreach ([
            'organizations_by_type; DROP TABLE users',
            "' UNION SELECT email FROM users --",
            '../../etc/passwd',
        ] as $payload) {
            try {
                $this->exports->export($payload, null);
                $this->fail("كان يجب رفض: {$payload}");
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }

        // والجداول ما زالت قائمة: الاسم يُطابَق بقائمة سماح ولا يُدرَج في استعلام
        $this->createUser();
        $this->assertGreaterThan(0, (int) Database::scalar('SELECT COUNT(*) FROM users'));
    }

    // ═══════════════════ كل تصدير يُسجَّل | Every export is logged ═══════════════════

    /** التصدير يترك أثراً في سجلّ التدقيق | An export leaves an audit trail. */
    public function test_every_export_is_recorded_in_the_audit_log(): void
    {
        $before = (int) Database::scalar(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'data.exported'",
        );

        $this->exports->export('organizations_by_type', $this->createUser());

        $after = (int) Database::scalar(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'data.exported'",
        );

        $this->assertSame($before + 1, $after);

        $entry = Database::selectOne(
            "SELECT category, description FROM audit_logs
              WHERE action = 'data.exported' ORDER BY id DESC LIMIT 1",
        );

        $this->assertSame('export', $entry['category']);
        $this->assertStringContainsString('الحسابات حسب النوع', (string) $entry['description']);
    }

    // ═══════════════════ سلامة الملف | File safety ═══════════════════

    /** الملف يبدأ بعلامة ترتيب البايتات | The file carries a UTF-8 BOM. */
    public function test_the_csv_starts_with_a_utf8_bom(): void
    {
        $csv = $this->exports->export('organizations_by_type', null)['content'];

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    /**
     * حقن الصيغ محيَّد | Formula injection is neutralised.
     *
     * اسم منشأة يبدأ بـ `=` يُنفَّذ كصيغة عند فتح الملف في برنامج جداول.
     */
    public function test_formula_injection_is_neutralised(): void
    {
        $orgId = $this->createOrganization(['status' => 'verified']);

        Database::statement(
            'UPDATE organizations SET legal_name = ?, trading_name = ? WHERE id = ?',
            ['=cmd|calc!A1', '=cmd|calc!A1', $orgId],
        );

        // المركز يظهر في مجموعة المراكز فقط، فنحوّل المنشأة لمركز
        Database::statement(
            "UPDATE organizations SET organization_type_id =
                (SELECT id FROM organization_types WHERE code = 'bds_center' LIMIT 1)
              WHERE id = ?",
            [$orgId],
        );

        $csv = $this->exports->export('bds_centre_activity', null)['content'];

        $this->assertStringNotContainsString(',=cmd', $csv);
        $this->assertStringContainsString("'=cmd", $csv);
    }

    /** أسماء الملفات لا تحمل مسارات | Filenames carry no path separators. */
    public function test_filenames_are_safe(): void
    {
        foreach (array_keys($this->exports->datasets()) as $dataset) {
            $filename = $this->exports->export($dataset, null)['filename'];

            $this->assertStringNotContainsString('/', $filename);
            $this->assertStringNotContainsString('\\', $filename);
            $this->assertStringNotContainsString('..', $filename);
            $this->assertStringEndsWith('.csv', $filename);
        }
    }

    /** كل مجموعة معلنة تعمل فعلاً | Every advertised dataset actually runs. */
    public function test_every_advertised_dataset_runs(): void
    {
        $datasets = $this->exports->datasets();

        $this->assertNotSame([], $datasets);

        foreach ($datasets as $key => $label) {
            $result = $this->exports->export($key, null);

            $this->assertNotSame('', $result['content'], "المجموعة «{$key}» فارغة تماماً.");
            $this->assertNotSame('', $label);
        }
    }
}
