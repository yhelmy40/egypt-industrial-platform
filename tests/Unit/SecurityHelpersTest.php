<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Logger;
use App\Services\AuditLogger;
use Tests\TestCase;

/**
 * اختبارات دوال الحماية والعرض | Escaping, masking and redaction tests (§17).
 */
final class SecurityHelpersTest extends TestCase
{
    // ───────────────────── الهروب من XSS | Output escaping ─────────────────────

    public function test_html_escaping_neutralises_script_injection(): void
    {
        $malicious = '<script>alert("xss")</script>';

        $escaped = e($malicious);

        $this->assertStringNotContainsString('<script>', $escaped);
        $this->assertStringContainsString('&lt;script&gt;', $escaped);
    }

    public function test_escaping_covers_quotes_used_to_break_out_of_attributes(): void
    {
        $escaped = e('" onmouseover="alert(1)');

        $this->assertStringNotContainsString('" onmouseover', $escaped);
        $this->assertStringContainsString('&quot;', $escaped);

        // تُقبل الصيغتان: &#039; (HTML4) و &apos; (HTML5) — كلاهما آمن، والمهم
        // ألّا تبقى علامة اقتباس مفردة خام تسمح بالخروج من سياق الخاصيّة.
        // Both &#039; and &apos; are acceptable; what matters is that no raw
        // single quote survives to break out of the attribute context.
        $single = e("' onfocus='alert(1)");
        $this->assertStringNotContainsString("' onfocus", $single);
        $this->assertMatchesRegularExpression('/&(#039|apos);/', $single);
    }

    public function test_escaping_preserves_arabic_text_unchanged(): void
    {
        $arabic = 'شركة النيل للصناعات الغذائية — مصر';

        $this->assertSame($arabic, e($arabic), 'الهروب يجب ألّا يشوّه النص العربي.');
    }

    public function test_escaping_handles_null_and_non_scalar_safely(): void
    {
        $this->assertSame('', e(null));
        $this->assertSame('', e(['array']));
        $this->assertSame('', e(new \stdClass()));
    }

    public function test_javascript_embedding_escapes_closing_tags(): void
    {
        $encoded = e_js('</script><script>alert(1)</script>');

        $this->assertStringNotContainsString('</script>', $encoded);
        $this->assertStringNotContainsString('<script>', $encoded);
    }

    public function test_javascript_embedding_keeps_arabic_readable(): void
    {
        $encoded = e_js(['name' => 'مشروع تجريبي']);

        $this->assertStringContainsString('مشروع تجريبي', $encoded);
    }

    // ───────────────────── إخفاء البيانات | Masking (§4.11, §10) ─────────────────────

    public function test_email_masking_hides_most_of_the_local_part(): void
    {
        $masked = mask_email('mohamed.ali@example.com');

        $this->assertStringStartsWith('mo', $masked);
        $this->assertStringContainsString('@example.com', $masked);
        $this->assertStringNotContainsString('mohamed.ali', $masked);
    }

    public function test_phone_masking_keeps_only_the_last_four_digits(): void
    {
        $masked = mask_phone('01012345678');

        $this->assertStringEndsWith('5678', $masked);
        $this->assertStringNotContainsString('0101234', $masked);
    }

    public function test_masking_handles_missing_values(): void
    {
        $this->assertSame('—', mask_email(null));
        $this->assertSame('—', mask_email('not-an-email'));
        $this->assertSame('—', mask_phone(null));
    }

    // ───────────────────── تنقيح السجلات | Log redaction (§9) ─────────────────────

    public function test_logger_redacts_credentials_and_tokens(): void
    {
        $redacted = Logger::redact([
            'email'         => 'user@example.com',
            'password'      => 'SuperSecret!2026',
            'password_hash' => '$2y$12$abcdefghijklmnop',
            'reset_token'   => 'abc123token',
            'csrf_token'    => 'xyz789',
            'api_key'       => 'key-abcdef',
            'session_id'    => 'sess-123',
            'national_id'   => '29001011234567',
            'iban'          => 'EG380019000500000000263180002',
        ]);

        $this->assertSame('user@example.com', $redacted['email'], 'البريد ليس سرّاً في سياق السجل.');

        foreach (['password', 'password_hash', 'reset_token', 'csrf_token', 'api_key', 'session_id', 'national_id', 'iban'] as $key) {
            $this->assertSame('[REDACTED]', $redacted[$key], "الحقل {$key} يجب ألّا يُسجَّل.");
        }
    }

    public function test_logger_redacts_nested_structures(): void
    {
        $redacted = Logger::redact([
            'request' => [
                'body' => ['password' => 'secret', 'name' => 'اسم'],
            ],
        ]);

        $this->assertSame('[REDACTED]', $redacted['request']['body']['password']);
        $this->assertSame('اسم', $redacted['request']['body']['name']);
    }

    public function test_logger_truncates_oversized_values(): void
    {
        $redacted = Logger::redact(['note' => str_repeat('ا', 900)]);

        $this->assertLessThan(900, mb_strlen($redacted['note']));
        $this->assertStringEndsWith('…', $redacted['note']);
    }

    // ───────────────────── سجل التدقيق | Audit log (§9) ─────────────────────

    public function test_audit_entries_never_store_sensitive_field_values(): void
    {
        $userId = $this->createUser();
        $audit  = new AuditLogger();

        $audit->log(
            action: 'test.change',
            category: AuditLogger::CATEGORY_RECORD,
            entityType: 'user',
            entityId: $userId,
            changes: [
                'before' => ['name' => 'قديم', 'password_hash' => '$2y$12$old'],
                'after'  => ['name' => 'جديد', 'password_hash' => '$2y$12$new'],
            ],
            userId: $userId,
        );

        $row = \App\Core\Database::selectOne(
            "SELECT changes FROM audit_logs WHERE action = 'test.change' AND user_id = ? ORDER BY id DESC LIMIT 1",
            [$userId],
        );

        $this->assertNotNull($row);
        $this->assertStringNotContainsString('$2y$12$old', (string) $row['changes']);
        $this->assertStringContainsString('[REDACTED]', (string) $row['changes']);
        $this->assertStringContainsString('قديم', (string) $row['changes'], 'التغييرات غير الحسّاسة يجب أن تبقى مقروءة.');
    }

    public function test_audit_records_the_actor_and_context(): void
    {
        $userId  = $this->createUser();
        $orgId   = $this->createOrganization();
        $request = \App\Core\Request::create('POST', '/admin/verifications/1', [], [], [], [
            'REMOTE_ADDR'     => '192.0.2.55',
            'HTTP_USER_AGENT' => 'PHPUnit Browser',
        ]);

        $audit = new AuditLogger();
        $audit->setRequest($request);
        $audit->log(
            action: 'org.account.verify',
            category: AuditLogger::CATEGORY_VERIFICATION,
            entityType: 'organization',
            entityId: $orgId,
            description: 'اعتماد توثيق المنشأة',
            userId: $userId,
            organizationId: $orgId,
        );

        $row = \App\Core\Database::selectOne(
            "SELECT * FROM audit_logs WHERE action = 'org.account.verify' AND entity_id = ? ORDER BY id DESC LIMIT 1",
            [$orgId],
        );

        $this->assertNotNull($row);
        $this->assertSame($userId, (int) $row['user_id']);
        $this->assertSame('/admin/verifications/1', $row['route']);
        $this->assertSame('POST', $row['method']);
        $this->assertSame('192.0.2.55', inet_ntop($row['ip_address']));
    }

    public function test_failed_login_audit_masks_the_attempted_email(): void
    {
        $audit = new AuditLogger();
        $audit->setRequest(\App\Core\Request::create('POST', '/auth/login'));
        $audit->logFailedLogin('victim@example.com', 'invalid_credentials');

        $row = \App\Core\Database::selectOne(
            "SELECT description FROM audit_logs WHERE action = 'auth.login_failed' ORDER BY id DESC LIMIT 1",
        );

        $this->assertNotNull($row);
        $this->assertStringNotContainsString(
            'victim@example.com',
            (string) $row['description'],
            'البريد المُدخل قد يخصّ شخصاً آخر، فيُخفى جزئياً.',
        );
        $this->assertStringContainsString('@example.com', (string) $row['description']);
    }

    // ───────────────────── التنسيق | Formatting helpers ─────────────────────

    public function test_money_formatting_uses_egyptian_pounds_by_default(): void
    {
        $this->assertSame('1,250.50 ج.م', money(1250.5));
        $this->assertSame('0.00 ج.م', money(0));
    }

    public function test_dates_are_formatted_with_arabic_month_names(): void
    {
        $this->assertStringContainsString('يناير', format_date('2026-01-15'));
        $this->assertSame('—', format_date(null));
        $this->assertSame('—', format_date('0000-00-00'));
    }

    public function test_excerpts_truncate_multibyte_text_without_breaking_it(): void
    {
        $text     = str_repeat('كلمة ', 60);
        $excerpt  = str_excerpt($text, 50);

        $this->assertLessThanOrEqual(51, mb_strlen($excerpt));
        $this->assertStringEndsWith('…', $excerpt);
    }

    public function test_excerpt_strips_html_to_prevent_markup_leaking_into_summaries(): void
    {
        $this->assertStringNotContainsString('<b>', str_excerpt('<b>نص</b> عادي'));
    }
}
