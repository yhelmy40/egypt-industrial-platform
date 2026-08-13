<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Request;
use App\Core\Session;
use App\Support\Csrf;
use Tests\TestCase;

/**
 * اختبارات النواة والوسائط | HTTP kernel, CSRF and routing tests (§17).
 *
 * تنفَّذ عبر النواة كاملة (Application::handle) لا عبر استدعاء المتحكّم مباشرة،
 * حتى تُختبَر سلسلة الوسائط الحقيقية.
 * Dispatched through the full kernel rather than by calling controllers
 * directly, so the real middleware chain is exercised.
 */
final class HttpKernelTest extends TestCase
{
    // ───────────────────────── التوجيه | Routing ─────────────────────────

    public function test_public_home_page_is_reachable_without_authentication(): void
    {
        $response = $this->handle(Request::create('GET', '/'));

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('منصة رواد النيل', $response->body());
    }

    public function test_unknown_route_returns_404(): void
    {
        $response = $this->handle(Request::create('GET', '/no-such-page'));

        $this->assertSame(404, $response->status());
    }

    public function test_wrong_http_verb_returns_405_not_404(): void
    {
        // المسار موجود لكن بأسلوب مختلف — التمييز يساعد التشخيص دون كشف زائد
        $response = $this->handle(Request::create('DELETE', '/'));

        $this->assertSame(405, $response->status());
    }

    public function test_controller_methods_are_not_reachable_by_url_guessing(): void
    {
        // في التصميم القديم كان /home/platformStats يستدعي دالة المتحكّم مباشرة.
        // جدول المسارات الصريح يجعل ذلك مستحيلاً.
        foreach (['/home/platformStats', '/HomeController/index', '/admin/dashboard/headlineCounts'] as $path) {
            $this->assertSame(
                404,
                $this->handle(Request::create('GET', $path))->status(),
                "المسار {$path} يجب ألّا يكون قابلاً للوصول.",
            );
        }
    }

    // ───────────────────────── الحماية | CSRF ─────────────────────────

    public function test_post_without_a_csrf_token_is_rejected(): void
    {
        $response = $this->handle(Request::create('POST', '/auth/login', [
            'email'    => 'someone@test.local',
            'password' => 'whatever',
        ]));

        $this->assertSame(419, $response->status());
    }

    public function test_post_with_an_invalid_csrf_token_is_rejected(): void
    {
        Csrf::token(); // توليد رمز الجلسة

        $response = $this->handle(Request::create('POST', '/auth/login', [
            '_token'   => str_repeat('a', 64),
            'email'    => 'someone@test.local',
            'password' => 'whatever',
        ]));

        $this->assertSame(419, $response->status());
    }

    public function test_post_with_a_valid_csrf_token_passes_the_middleware(): void
    {
        $token = Csrf::token();

        $response = $this->handle(Request::create('POST', '/auth/login', [
            '_token'   => $token,
            'email'    => 'nobody_' . bin2hex(random_bytes(4)) . '@test.local',
            'password' => 'WrongPass!2026',
        ]));

        // بيانات الدخول خاطئة ⇒ إعادة توجيه، لا رفض بسبب CSRF
        $this->assertNotSame(419, $response->status());
        $this->assertTrue($response->isRedirect());
    }

    public function test_get_requests_do_not_require_a_csrf_token(): void
    {
        $this->assertSame(200, $this->handle(Request::create('GET', '/about'))->status());
    }

    public function test_csrf_token_is_rotated_on_login(): void
    {
        $before = Csrf::token();
        Csrf::rotate();
        $after = Csrf::token();

        $this->assertNotSame($before, $after, 'يجب تدوير رمز الحماية عند تغيّر مستوى الجلسة.');
    }

    public function test_csrf_verification_uses_the_session_token_only(): void
    {
        Session::clear();

        $this->assertFalse(Csrf::verify('anything'), 'بلا رمز في الجلسة يجب رفض أي رمز مُرسل.');
        $this->assertFalse(Csrf::verify(null));
        $this->assertFalse(Csrf::verify(''));

        $token = Csrf::token();
        $this->assertTrue(Csrf::verify($token));
    }

    // ───────────────────── حماية المسارات | Route guards ─────────────────────

    public function test_workspace_requires_authentication(): void
    {
        $response = $this->handle(Request::create('GET', '/app'));

        $this->assertTrue($response->isRedirect());
        $this->assertStringContainsString('/auth/login', (string) $response->header('Location'));
    }

    public function test_admin_dashboard_is_denied_without_the_reporting_permission(): void
    {
        $userId = $this->createUser();
        $this->assignPlatformRole($userId, 'marketplace_customer');
        Session::put('user_id', $userId);

        $response = $this->handle(Request::create('GET', '/admin'));

        $this->assertSame(403, $response->status());
    }

    public function test_admin_dashboard_is_allowed_for_a_super_admin(): void
    {
        $userId = $this->createUser();
        $this->assignPlatformRole($userId, 'super_admin');
        Session::put('user_id', $userId);

        $response = $this->handle(Request::create('GET', '/admin'));

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('لوحة تحكم المنصة', $response->body());
    }

    public function test_suspended_user_session_is_terminated_on_the_next_request(): void
    {
        $userId = $this->createUser(['status' => 'suspended']);
        Session::put('user_id', $userId);

        $response = $this->handle(Request::create('GET', '/app'));

        $this->assertTrue($response->isRedirect());
        $this->assertNull(Session::get('user_id'), 'إيقاف الحساب يجب أن ينهي الجلسة الجارية.');
    }

    public function test_authenticated_users_are_redirected_away_from_guest_pages(): void
    {
        $userId = $this->createUser();
        Session::put('user_id', $userId);

        $response = $this->handle(Request::create('GET', '/auth/login'));

        $this->assertTrue($response->isRedirect());
        $this->assertStringContainsString('/app', (string) $response->header('Location'));
    }

    public function test_authorization_denial_is_written_to_the_audit_log(): void
    {
        $userId = $this->createUser();
        $this->assignPlatformRole($userId, 'marketplace_customer');
        Session::put('user_id', $userId);

        $this->handle(Request::create('GET', '/admin'));

        $this->assertDatabaseHas('audit_logs', [
            'action'  => 'security.authorization_denied',
            'user_id' => $userId,
        ]);
    }

    // ───────────────────── ترويسات الأمان | Security headers ─────────────────────

    public function test_security_headers_are_present_on_every_response(): void
    {
        $response = $this->handle(Request::create('GET', '/'));

        $this->assertSame('DENY', $response->header('X-Frame-Options'));
        $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->header('Referrer-Policy'));
        $this->assertNotNull($response->header('Permissions-Policy'));
    }

    public function test_content_security_policy_forbids_inline_and_external_sources(): void
    {
        $csp = (string) $this->handle(Request::create('GET', '/'))->header('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp, 'الأصول مستضافة محلياً فلا حاجة لـ unsafe-inline.');
        $this->assertStringNotContainsString('unsafe-eval', $csp);
    }

    public function test_security_headers_are_present_on_error_responses_too(): void
    {
        $response = $this->handle(Request::create('GET', '/definitely-missing'));

        $this->assertSame(404, $response->status());
        $this->assertSame('DENY', $response->header('X-Frame-Options'));
    }

    public function test_authenticated_pages_are_not_cached(): void
    {
        $cacheControl = (string) $this->handle(Request::create('GET', '/'))->header('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
    }

    // ───────────────────── معالجة الأخطاء | Error handling ─────────────────────

    public function test_error_pages_render_an_arabic_message(): void
    {
        $body = $this->handle(Request::create('GET', '/missing-page'))->body();

        $this->assertStringContainsString('الصفحة المطلوبة غير موجودة', $body);
        $this->assertStringContainsString('dir="rtl"', $body);
        $this->assertStringContainsString('lang="ar"', $body);
    }

    public function test_json_requests_receive_json_errors(): void
    {
        $request = Request::create('GET', '/api/v1/missing', [], [], [], [
            'HTTP_ACCEPT'  => 'application/json',
            'REMOTE_ADDR'  => '127.0.0.1',
        ]);

        $response = $this->handle($request);

        $this->assertSame(404, $response->status());
        $this->assertStringContainsString('application/json', (string) $response->header('Content-Type'));

        $decoded = json_decode($response->body(), true);
        $this->assertTrue($decoded['error']);
    }

    // ───────────────────── الترجمة والاتجاه | Localisation ─────────────────────

    public function test_all_pages_render_right_to_left_arabic(): void
    {
        foreach (['/', '/about', '/terms', '/privacy', '/contact', '/auth/login', '/auth/register'] as $path) {
            $body = $this->handle(Request::create('GET', $path))->body();

            $this->assertStringContainsString('dir="rtl"', $body, "الصفحة {$path} يجب أن تكون RTL.");
            $this->assertStringContainsString('lang="ar"', $body, "الصفحة {$path} يجب أن تُعلن اللغة العربية.");
            $this->assertStringContainsString('charset="utf-8"', $body);
        }
    }

    public function test_pages_do_not_reference_external_hosts(): void
    {
        // شرط عمل CSP الصارمة، وشرط الأداء على الاتصالات المحدودة (§16)
        foreach (['/', '/auth/login'] as $path) {
            $body = $this->handle(Request::create('GET', $path))->body();

            $this->assertStringNotContainsString('cdn.jsdelivr.net', $body);
            $this->assertStringNotContainsString('fonts.googleapis.com', $body);
            $this->assertStringNotContainsString('fonts.gstatic.com', $body);
        }
    }

    public function test_translation_keys_are_resolved_not_printed_raw(): void
    {
        $body = $this->handle(Request::create('GET', '/'))->body();

        // ظهور مفتاح مثل portal.hero_title يعني ترجمة مفقودة
        $this->assertDoesNotMatchRegularExpression(
            '/\b(portal|common|auth|validation)\.[a-z_]+\b(?![^<]*<\/code>)/',
            strip_tags($body),
            'ظهر مفتاح ترجمة غير مُترجَم في الصفحة.',
        );
    }
}
