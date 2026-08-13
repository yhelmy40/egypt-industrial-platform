<?php

declare(strict_types=1);

use App\Controllers\Admin;
use App\Controllers\Auth;
use App\Controllers\Public as PublicController;
use App\Controllers\Sme;
use App\Core\Application;
use App\Core\Router;
use App\Middleware\Authenticate;
use App\Middleware\Authorize;
use App\Middleware\EnforceRateLimit;
use App\Middleware\RedirectIfAuthenticated;
use App\Middleware\ResolveTenant;

/**
 * جدول المسارات | Route table.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * كل مسار مُعرَّف صراحةً مع وسائطه والصلاحية المطلوبة له. لا يُشتقّ أي متحكّم
 * أو دالة من عنوان URL، فلا تصبح أي دالة عامة نقطة نهاية بالخطأ.
 * Every route is declared explicitly with its middleware and permission.
 * Nothing is derived from the URL, so no public method accidentally becomes an
 * endpoint. This file is the auditable permission surface of the platform.
 * ═══════════════════════════════════════════════════════════════════════════
 */

/** @var Router $router */
$router = new Router();

// ═══════════════════ البوابة العامة | Public portal ═══════════════════
// وسيط ResolveTenant مُدرج هنا أيضاً حتى تعرف الصفحات العامة ما إذا كان
// الزائر مسجّلاً (لعرض القائمة الصحيحة)، دون أن تفترض أي صلاحية.
$router->group('', [ResolveTenant::class], static function (Router $r): void {
    $r->get('/', [PublicController\HomeController::class, 'index'])->name('home');
    $r->get('/about', [PublicController\HomeController::class, 'about'])->name('about');
    $r->get('/contact', [PublicController\HomeController::class, 'contact'])->name('contact');
    $r->get('/terms', [PublicController\HomeController::class, 'terms'])->name('terms');
    $r->get('/privacy', [PublicController\HomeController::class, 'privacy'])->name('privacy');
});

// ═══════════════════ المصادقة | Authentication ═══════════════════
// صفحات الضيوف فقط | Guest-only pages
$router->group('/auth', [ResolveTenant::class, RedirectIfAuthenticated::class], static function (Router $r): void {
    $r->get('/login', [Auth\LoginController::class, 'showLoginForm'])->name('login');
    $r->get('/register', [Auth\RegisterController::class, 'showRegistrationForm'])->name('register');
    $r->get('/forgot-password', [Auth\PasswordController::class, 'showForgotForm'])->name('password.forgot');
    $r->get('/reset-password', [Auth\PasswordController::class, 'showResetForm'])->name('password.reset');
    $r->get('/verify-notice', [Auth\RegisterController::class, 'verifyNotice'])->name('verify.notice');
});

// إجراءات المصادقة — محدودة المعدّل | Rate-limited authentication actions
$router->group('/auth', [ResolveTenant::class, EnforceRateLimit::class], static function (Router $r): void {
    $r->post('/login', [Auth\LoginController::class, 'login']);
    $r->post('/register', [Auth\RegisterController::class, 'register']);
    $r->post('/forgot-password', [Auth\PasswordController::class, 'sendResetLink']);
    $r->post('/reset-password', [Auth\PasswordController::class, 'resetPassword']);
});

// التحقق من البريد وتسجيل الخروج | Verification and logout
$router->group('/auth', [ResolveTenant::class], static function (Router $r): void {
    $r->get('/verify', [Auth\RegisterController::class, 'verify'])->name('verify');
    $r->post('/logout', [Auth\LoginController::class, 'logout'])->name('logout');
});

// ═══════════════════ مساحة عمل المنشأة | Organization workspace ═══════════════════
$router->group(
    '/app',
    [Authenticate::class, ResolveTenant::class, Authorize::class],
    static function (Router $r): void {
        $r->get('', [Sme\DashboardController::class, 'index'])->name('app.dashboard');
        $r->post('/switch-organization', [Sme\DashboardController::class, 'switchOrganization'])
            ->name('app.switch_organization');

        // إدارة الحساب الشخصي — متاحة لكل مستخدم مصادق عليه بلا صلاحية إضافية
        $r->get('/account/password', [Auth\PasswordController::class, 'showChangeForm'])
            ->name('account.password');
        $r->post('/account/password', [Auth\PasswordController::class, 'changePassword']);
    },
);

// ═══════════════════ إدارة المنصة | Platform administration ═══════════════════
$router->group(
    '/admin',
    [Authenticate::class, ResolveTenant::class, Authorize::class],
    static function (Router $r): void {
        $r->get('', [Admin\DashboardController::class, 'index'])
            ->permission('reports.platform.view')
            ->name('admin.dashboard');
    },
);

Application::instance()?->setRouter($router);

return $router;
