<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Services\AuthService;
use App\Services\RateLimiter;
use Tests\TestCase;

/**
 * اختبارات المصادقة | Authentication tests (§17).
 */
final class AuthenticationTest extends TestCase
{
    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = new AuthService();
    }

    protected function request(string $method = 'POST', string $path = '/auth/login', array $body = [], array $query = []): Request
    {
        return Request::create($method, $path, $body, $query, [], [
            'REMOTE_ADDR'     => '203.0.113.' . random_int(1, 254), // IP فريد لتفادي حدّ المعدّل بين الاختبارات
            'HTTP_USER_AGENT' => 'PHPUnit',
        ]);
    }

    // ───────────────────────── التسجيل | Registration ─────────────────────────

    public function test_registration_creates_a_pending_account_with_a_hashed_password(): void
    {
        $email  = 'newuser_' . bin2hex(random_bytes(4)) . '@test.local';
        $result = $this->auth->register('صاحب مشروع جديد', $email, 'StrongPass!2026', '01012345678', $this->request());

        $user = Database::selectOne('SELECT * FROM users WHERE id = ?', [$result['user_id']]);

        $this->assertNotNull($user);
        $this->assertSame('pending', $user['status'], 'الحساب الجديد يجب أن يبدأ بحالة pending حتى يُؤكَّد البريد.');
        $this->assertNull($user['email_verified_at']);

        // كلمة المرور لا تُخزَّن نصاً صريحاً أبداً
        $this->assertNotSame('StrongPass!2026', $user['password_hash']);
        $this->assertTrue(password_verify('StrongPass!2026', $user['password_hash']));
    }

    public function test_registration_normalises_the_email_to_lower_case(): void
    {
        $email  = 'MiXeD_' . bin2hex(random_bytes(4)) . '@TEST.LOCAL';
        $result = $this->auth->register('اختبار', $email, 'StrongPass!2026', null, $this->request());

        $stored = Database::scalar('SELECT email FROM users WHERE id = ?', [$result['user_id']]);

        $this->assertSame(mb_strtolower($email), $stored);
    }

    public function test_registration_issues_a_verification_token_stored_only_as_a_hash(): void
    {
        $result = $this->auth->register('اختبار', 'verify_' . bin2hex(random_bytes(4)) . '@test.local', 'StrongPass!2026', null, $this->request());
        $token  = $result['verification_token'];

        $this->assertDatabaseHas('verification_tokens', ['token_hash' => hash('sha256', $token)]);
        $this->assertDatabaseMissing('verification_tokens', ['token_hash' => $token]);
    }

    public function test_email_verification_activates_the_account(): void
    {
        $result = $this->auth->register('اختبار', 'act_' . bin2hex(random_bytes(4)) . '@test.local', 'StrongPass!2026', null, $this->request());

        $outcome = $this->auth->verifyEmail($result['verification_token']);

        $this->assertTrue($outcome['success']);

        $user = Database::selectOne('SELECT * FROM users WHERE id = ?', [$result['user_id']]);
        $this->assertSame('active', $user['status']);
        $this->assertNotNull($user['email_verified_at']);
    }

    public function test_a_verification_token_cannot_be_reused(): void
    {
        $result = $this->auth->register('اختبار', 'reuse_' . bin2hex(random_bytes(4)) . '@test.local', 'StrongPass!2026', null, $this->request());

        $this->assertTrue($this->auth->verifyEmail($result['verification_token'])['success']);
        $this->assertFalse(
            $this->auth->verifyEmail($result['verification_token'])['success'],
            'رمز التحقق المستخدَم يجب ألّا يُقبل مرة أخرى.',
        );
    }

    public function test_expired_verification_token_is_rejected(): void
    {
        $result = $this->auth->register('اختبار', 'exp_' . bin2hex(random_bytes(4)) . '@test.local', 'StrongPass!2026', null, $this->request());

        Database::statement(
            'UPDATE verification_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE user_id = ?',
            [$result['user_id']],
        );

        $outcome = $this->auth->verifyEmail($result['verification_token']);

        $this->assertFalse($outcome['success']);
        $this->assertStringContainsString('انتهت صلاحية', $outcome['message']);
    }

    // ───────────────────────── تسجيل الدخول | Login ─────────────────────────

    public function test_login_succeeds_with_correct_credentials(): void
    {
        $email  = 'login_' . bin2hex(random_bytes(4)) . '@test.local';
        $userId = $this->createUser(['email' => $email, 'password_hash' => password_hash('Correct!2026', PASSWORD_DEFAULT)]);

        $result = $this->auth->attemptLogin($email, 'Correct!2026', $this->request());

        $this->assertTrue($result['success']);
        $this->assertSame($userId, Session::get('user_id'));
    }

    public function test_login_fails_with_a_wrong_password(): void
    {
        $email = 'wrong_' . bin2hex(random_bytes(4)) . '@test.local';
        $this->createUser(['email' => $email, 'password_hash' => password_hash('Correct!2026', PASSWORD_DEFAULT)]);

        $result = $this->auth->attemptLogin($email, 'Incorrect!2026', $this->request());

        $this->assertFalse($result['success']);
        $this->assertNull(Session::get('user_id'), 'لا يجوز إنشاء جلسة عند فشل التحقق.');
    }

    public function test_login_error_message_is_identical_for_unknown_email_and_wrong_password(): void
    {
        $email = 'enum_' . bin2hex(random_bytes(4)) . '@test.local';
        $this->createUser(['email' => $email, 'password_hash' => password_hash('Correct!2026', PASSWORD_DEFAULT)]);

        $wrongPassword = $this->auth->attemptLogin($email, 'Nope!2026', $this->request());
        $unknownEmail  = $this->auth->attemptLogin('ghost_' . bin2hex(random_bytes(4)) . '@test.local', 'Nope!2026', $this->request());

        // رسالة موحّدة تمنع استكشاف البُرد المسجّلة
        $this->assertSame(
            $wrongPassword['message'],
            $unknownEmail['message'],
            'اختلاف الرسالة يكشف أي البُرد مسجّل لدى المنصة.',
        );
    }

    public function test_suspended_account_cannot_log_in(): void
    {
        $email = 'susp_' . bin2hex(random_bytes(4)) . '@test.local';
        $this->createUser([
            'email'         => $email,
            'password_hash' => password_hash('Correct!2026', PASSWORD_DEFAULT),
            'status'        => 'suspended',
        ]);

        $result = $this->auth->attemptLogin($email, 'Correct!2026', $this->request());

        $this->assertFalse($result['success']);
        $this->assertSame('suspended', $result['reason']);
    }

    public function test_unverified_account_cannot_log_in(): void
    {
        $email = 'unver_' . bin2hex(random_bytes(4)) . '@test.local';
        $this->createUser([
            'email'             => $email,
            'password_hash'     => password_hash('Correct!2026', PASSWORD_DEFAULT),
            'status'            => 'pending',
            'email_verified_at' => null,
        ]);

        $result = $this->auth->attemptLogin($email, 'Correct!2026', $this->request());

        $this->assertFalse($result['success']);
        $this->assertSame('unverified', $result['reason']);
    }

    public function test_repeated_failures_lock_the_account(): void
    {
        $email = 'lock_' . bin2hex(random_bytes(4)) . '@test.local';
        $this->createUser(['email' => $email, 'password_hash' => password_hash('Correct!2026', PASSWORD_DEFAULT)]);

        // كل محاولة من IP مختلف حتى نختبر القفل على مستوى الحساب لا حدّ المعدّل
        for ($i = 0; $i < 5; $i++) {
            $this->auth->attemptLogin($email, 'Wrong!2026', $this->request());
        }

        $result = $this->auth->attemptLogin($email, 'Correct!2026', $this->request());

        $this->assertFalse($result['success'], 'كلمة المرور الصحيحة يجب ألّا تعمل أثناء قفل الحساب.');
        $this->assertSame('locked', $result['reason']);
    }

    public function test_successful_login_clears_the_failure_counter(): void
    {
        $email  = 'clear_' . bin2hex(random_bytes(4)) . '@test.local';
        $userId = $this->createUser(['email' => $email, 'password_hash' => password_hash('Correct!2026', PASSWORD_DEFAULT)]);

        $this->auth->attemptLogin($email, 'Wrong!2026', $this->request());
        $this->auth->attemptLogin($email, 'Correct!2026', $this->request());

        $this->assertSame(0, (int) Database::scalar('SELECT failed_login_count FROM users WHERE id = ?', [$userId]));
    }

    public function test_every_login_attempt_is_recorded(): void
    {
        $email = 'record_' . bin2hex(random_bytes(4)) . '@test.local';
        $this->createUser(['email' => $email, 'password_hash' => password_hash('Correct!2026', PASSWORD_DEFAULT)]);

        $this->auth->attemptLogin($email, 'Wrong!2026', $this->request());
        $this->auth->attemptLogin($email, 'Correct!2026', $this->request());

        $this->assertSame(1, $this->countRows('login_attempts', 'identifier = ? AND successful = 0', [$email]));
        $this->assertSame(1, $this->countRows('login_attempts', 'identifier = ? AND successful = 1', [$email]));
    }

    public function test_login_writes_an_audit_entry(): void
    {
        $email  = 'audit_' . bin2hex(random_bytes(4)) . '@test.local';
        $userId = $this->createUser(['email' => $email, 'password_hash' => password_hash('Correct!2026', PASSWORD_DEFAULT)]);

        $this->auth->attemptLogin($email, 'Correct!2026', $this->request());

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $userId]);
    }

    public function test_logout_destroys_the_session(): void
    {
        $email  = 'out_' . bin2hex(random_bytes(4)) . '@test.local';
        $userId = $this->createUser(['email' => $email, 'password_hash' => password_hash('Correct!2026', PASSWORD_DEFAULT)]);

        $this->auth->attemptLogin($email, 'Correct!2026', $this->request());
        $this->assertSame($userId, Session::get('user_id'));

        $this->auth->logout($this->request());

        $this->assertNull(Session::get('user_id'));
    }

    // ───────────────────── حدّ المعدّل | Rate limiting ─────────────────────

    public function test_login_is_rate_limited_per_ip_and_email(): void
    {
        $email = 'rl_' . bin2hex(random_bytes(4)) . '@test.local';
        $this->createUser(['email' => $email, 'password_hash' => password_hash('Correct!2026', PASSWORD_DEFAULT)]);

        // نفس عنوان IP في كل المحاولات ⇒ يجب أن يتدخّل حدّ المعدّل
        $fixedIp = Request::create('POST', '/auth/login', [], [], [], [
            'REMOTE_ADDR'     => '198.51.100.7',
            'HTTP_USER_AGENT' => 'PHPUnit',
        ]);

        $reasons = [];
        for ($i = 0; $i < 7; $i++) {
            $reasons[] = $this->auth->attemptLogin($email, 'Wrong!2026', $fixedIp)['reason'] ?? null;
        }

        $this->assertContains('rate_limited', $reasons, 'يجب أن يمنع حدّ المعدّل المحاولات المتكررة من نفس المصدر.');
    }

    public function test_rate_limiter_counts_and_clears_correctly(): void
    {
        $limiter = new RateLimiter();

        $this->assertFalse($limiter->tooManyAttempts('login', 'sig-test'));

        for ($i = 0; $i < 5; $i++) {
            $limiter->hit('login', 'sig-test');
        }

        $this->assertTrue($limiter->tooManyAttempts('login', 'sig-test'));
        $this->assertSame(0, $limiter->remaining('login', 'sig-test'));

        $limiter->clear('login', 'sig-test');

        $this->assertFalse($limiter->tooManyAttempts('login', 'sig-test'));
    }

    // ───────────────── استعادة كلمة المرور | Password reset ─────────────────

    public function test_password_reset_request_reveals_nothing_about_account_existence(): void
    {
        $email = 'known_' . bin2hex(random_bytes(4)) . '@test.local';
        $this->createUser(['email' => $email]);

        $known   = $this->auth->requestPasswordReset($email, $this->request());
        $unknown = $this->auth->requestPasswordReset('missing_' . bin2hex(random_bytes(4)) . '@test.local', $this->request());

        $this->assertSame($known['message'], $unknown['message']);
        $this->assertTrue($known['success']);
        $this->assertTrue($unknown['success']);
    }

    public function test_reset_token_is_stored_hashed_and_changes_the_password(): void
    {
        $email  = 'reset_' . bin2hex(random_bytes(4)) . '@test.local';
        $userId = $this->createUser(['email' => $email, 'password_hash' => password_hash('OldPass!2026', PASSWORD_DEFAULT)]);

        $this->auth->requestPasswordReset($email, $this->request());

        // استخراج الرمز يتطلّب معرفته؛ نولّده هنا كما يفعل النظام لاختبار المسار
        $token = bin2hex(random_bytes(32));
        Database::statement(
            'UPDATE password_resets SET token_hash = ? WHERE user_id = ? AND used_at IS NULL',
            [hash('sha256', $token), $userId],
        );

        $result = $this->auth->resetPassword($token, 'BrandNew!2026', $this->request());

        $this->assertTrue($result['success']);

        $hash = (string) Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        $this->assertTrue(password_verify('BrandNew!2026', $hash));
        $this->assertFalse(password_verify('OldPass!2026', $hash));
    }

    public function test_reset_token_cannot_be_reused(): void
    {
        $email  = 'once_' . bin2hex(random_bytes(4)) . '@test.local';
        $userId = $this->createUser(['email' => $email]);

        $this->auth->requestPasswordReset($email, $this->request());

        $token = bin2hex(random_bytes(32));
        Database::statement(
            'UPDATE password_resets SET token_hash = ? WHERE user_id = ? AND used_at IS NULL',
            [hash('sha256', $token), $userId],
        );

        $this->assertTrue($this->auth->resetPassword($token, 'FirstNew!2026', $this->request())['success']);
        $this->assertFalse(
            $this->auth->resetPassword($token, 'SecondNew!2026', $this->request())['success'],
            'رمز الاستعادة المستخدَم يجب ألّا يُقبل مرة أخرى.',
        );
    }

    public function test_expired_reset_token_is_rejected(): void
    {
        $email  = 'expired_' . bin2hex(random_bytes(4)) . '@test.local';
        $userId = $this->createUser(['email' => $email]);

        $this->auth->requestPasswordReset($email, $this->request());

        $token = bin2hex(random_bytes(32));
        Database::statement(
            'UPDATE password_resets SET token_hash = ?, expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR)
              WHERE user_id = ? AND used_at IS NULL',
            [hash('sha256', $token), $userId],
        );

        $this->assertFalse($this->auth->resetPassword($token, 'New!2026', $this->request())['success']);
    }

    public function test_reset_invalidates_other_pending_reset_links(): void
    {
        $email  = 'multi_' . bin2hex(random_bytes(4)) . '@test.local';
        $userId = $this->createUser(['email' => $email]);

        $this->auth->requestPasswordReset($email, $this->request());
        $this->auth->requestPasswordReset($email, $this->request());

        $token = bin2hex(random_bytes(32));
        Database::statement(
            'UPDATE password_resets SET token_hash = ? WHERE user_id = ? AND used_at IS NULL LIMIT 1',
            [hash('sha256', $token), $userId],
        );

        $this->auth->resetPassword($token, 'New!2026', $this->request());

        $this->assertSame(
            0,
            $this->countRows('password_resets', 'user_id = ? AND used_at IS NULL', [$userId]),
            'كل روابط الاستعادة المعلّقة يجب أن تُبطَل بعد استخدام أحدها.',
        );
    }

    public function test_changing_password_requires_the_current_password(): void
    {
        $userId = $this->createUser(['password_hash' => password_hash('Current!2026', PASSWORD_DEFAULT)]);

        $wrong = $this->auth->changePassword($userId, 'NotIt!2026', 'Another!2026', $this->request());
        $this->assertFalse($wrong['success']);

        $right = $this->auth->changePassword($userId, 'Current!2026', 'Another!2026', $this->request());
        $this->assertTrue($right['success']);

        $hash = (string) Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        $this->assertTrue(password_verify('Another!2026', $hash));
    }

    public function test_password_change_clears_the_forced_change_flag(): void
    {
        $userId = $this->createUser([
            'password_hash'        => password_hash('Current!2026', PASSWORD_DEFAULT),
            'must_change_password' => 1,
        ]);

        $this->auth->changePassword($userId, 'Current!2026', 'Another!2026', $this->request());

        $this->assertSame(0, (int) Database::scalar('SELECT must_change_password FROM users WHERE id = ?', [$userId]));
    }

    // ───────────────────── الموافقات | Consent (§10) ─────────────────────

    public function test_consent_is_recorded_with_its_policy_version(): void
    {
        $userId = $this->createUser();

        $this->auth->recordConsent($userId, 'privacy', $this->request());

        $consent = Database::selectOne(
            "SELECT * FROM user_consents WHERE user_id = ? AND consent_type = 'privacy'",
            [$userId],
        );

        $this->assertNotNull($consent);
        $this->assertNotSame('', (string) $consent['policy_version'], 'يجب تسجيل نسخة السياسة مع الموافقة.');
        $this->assertNotNull($consent['granted_at']);
    }
}
