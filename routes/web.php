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

        // ─── تسجيل منشأة جديدة | Registering a new organization ───
        // بلا صلاحية: أي مستخدم مصادق عليه يحق له تسجيل منشأة خاصة به.
        $r->get('/organization/new', [Sme\OrganizationController::class, 'showTypeSelection'])
            ->name('organization.new');
        $r->get('/organization/new/form', [Sme\OrganizationController::class, 'showCreateForm'])
            ->name('organization.create');
        $r->post('/organization', [Sme\OrganizationController::class, 'store']);

        // ─── ملف المنشأة | Organization profile ───
        $r->get('/organization', [Sme\OrganizationController::class, 'show'])
            ->permission('org.profile.view')
            ->name('organization.show');
        $r->post('/organization/basics', [Sme\OrganizationController::class, 'updateBasics'])
            ->permission('org.profile.update');
        $r->post('/organization/profile', [Sme\OrganizationController::class, 'updateProfile'])
            ->permission('org.profile.update');
        $r->post('/organization/logo', [Sme\OrganizationController::class, 'uploadLogo'])
            ->permission('org.profile.update');
        $r->post('/organization/submit', [Sme\OrganizationController::class, 'submit'])
            ->permission('org.profile.update');

        // قوائم مرتبطة عبر JSON | Dependent selects
        $r->get('/reference/cities', [Sme\OrganizationController::class, 'citiesJson']);
        $r->get('/reference/sub-sectors', [Sme\OrganizationController::class, 'subSectorsJson']);

        // ─── مستندات المنشأة | Documents ───
        $r->get('/organization/documents', [Sme\DocumentController::class, 'index'])
            ->permission('org.document.view')
            ->name('organization.documents');
        $r->post('/organization/documents', [Sme\DocumentController::class, 'upload'])
            ->permission('org.document.upload');
        $r->post('/organization/documents/{id:\d+}/delete', [Sme\DocumentController::class, 'delete'])
            ->permission('org.document.upload');

        // ─── الإشعارات | Notifications ───
        $r->get('/notifications', [Sme\NotificationController::class, 'index'])
            ->name('notifications');
        $r->post('/notifications/{id:\d+}/read', [Sme\NotificationController::class, 'markRead']);
        $r->post('/notifications/read-all', [Sme\NotificationController::class, 'markAllRead']);
    },
);

// ═══════════════════ الملفات المرفوعة | Uploaded files ═══════════════════
// المنفذ الوحيد للملفات؛ التفويض داخل المتحكّم لأن الملفات العامة (الشعارات)
// يجب أن تعمل للزوار بينما الوثائق الخاصة تتطلّب عضوية أو صلاحية مراجعة.
$router->group('/files', [ResolveTenant::class], static function (Router $r): void {
    $r->get('/{id:\d+}', [App\Controllers\FileController::class, 'show'])->name('files.show');
});

// ═══════════════════ إدارة المنصة | Platform administration ═══════════════════
$router->group(
    '/admin',
    [Authenticate::class, ResolveTenant::class, Authorize::class],
    static function (Router $r): void {
        $r->get('', [Admin\DashboardController::class, 'index'])
            ->permission('reports.platform.view')
            ->name('admin.dashboard');

        // ─── طابور مراجعة التوثيق | Verification queue ───
        $r->get('/verifications', [Admin\VerificationController::class, 'index'])
            ->permission('org.account.view_any')
            ->name('admin.verifications');
        $r->get('/verifications/{id:\d+}', [Admin\VerificationController::class, 'show'])
            ->permission('org.account.view_any')
            ->name('admin.verifications.show');

        // القرارات تتطلّب صلاحية التوثيق تحديداً، لا مجرد الاطلاع
        $r->post('/verifications/{id:\d+}/decide', [Admin\VerificationController::class, 'decide'])
            ->permission('org.account.verify');
        $r->post('/verifications/{id:\d+}/documents/{documentId:\d+}', [Admin\VerificationController::class, 'reviewDocument'])
            ->permission('org.account.verify');
    },
);

Application::instance()?->setRouter($router);

return $router;
