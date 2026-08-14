<?php

declare(strict_types=1);

use App\Controllers\Admin;
use App\Controllers\Auth;
use App\Controllers\Bds;
use App\Controllers\Provider;
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

    // ─── السوق العام | Public marketplace (§4.4) ───
    $r->get('/marketplace', [PublicController\MarketplaceController::class, 'index'])->name('marketplace');
    $r->get('/marketplace/{slug}', [PublicController\MarketplaceController::class, 'show'])->name('marketplace.listing');
    $r->get('/directory', [PublicController\MarketplaceController::class, 'directory'])->name('directory');

    // ─── دليل التمويل والخدمات | Financing and services catalogue (§4.5, §4.6) ───
    // معروضة للزوار عمداً: إخفاء الشروط خلف تسجيل الدخول يحوّل الاطّلاع عليها
    // إلى مقايضة ببيانات شخصية.
    $r->get('/financing', [PublicController\FinancingController::class, 'financingIndex'])->name('financing');
    $r->get('/financing/{slug}', [PublicController\FinancingController::class, 'financingShow'])
        ->name('financing.show');
    $r->get('/services', [PublicController\FinancingController::class, 'servicesIndex'])->name('services');
    $r->get('/services/{slug}', [PublicController\FinancingController::class, 'servicesShow'])
        ->name('services.show');

    // ─── الصفحة التعريفية للمنشأة | SME storefront (§4.3) ───
    $r->get('/business/{slug}', [PublicController\MarketplaceController::class, 'businessPage'])->name('business.show');

    // ─── السلة | Cart ───
    $r->get('/cart', [PublicController\CartController::class, 'show'])->name('cart');
    $r->post('/cart/add', [PublicController\CartController::class, 'add']);
    $r->post('/cart/update', [PublicController\CartController::class, 'update']);
    $r->post('/cart/remove', [PublicController\CartController::class, 'remove']);

    // ─── إتمام الشراء | Checkout ───
    $r->get('/checkout', [PublicController\CartController::class, 'checkoutForm'])->name('checkout');
    $r->get('/checkout/confirmation', [PublicController\CartController::class, 'confirmation']);

    // ─── تتبّع الطلبات وعروض الأسعار | Tracking (guest-accessible via token) ───
    $r->get('/orders/track/{token}', [PublicController\CustomerController::class, 'trackOrder'])->name('orders.track');
    $r->get('/orders/mine', [PublicController\CustomerController::class, 'myOrders'])->name('orders.mine');
    $r->get('/quotations/track/{token}', [PublicController\CustomerController::class, 'trackQuotation'])->name('quotations.track');

    // ─── قبول دعوة الانضمام | Accept a team invitation ───
    $r->get('/invitations/accept', [Sme\TeamController::class, 'acceptInvitation'])->name('invitations.accept');
});

// إجراءات عامة تغيّر الحالة — محدودة المعدّل لمنع الإساءة (§9)
// Public state-changing actions, rate-limited against abuse.
$router->group('', [ResolveTenant::class, EnforceRateLimit::class], static function (Router $r): void {
    $r->post('/checkout', [PublicController\CartController::class, 'placeOrder']);
    $r->post('/enquiry', [PublicController\CustomerController::class, 'submitEnquiry'])->name('enquiry.submit');
    $r->post('/quotations/request', [PublicController\CustomerController::class, 'requestQuotation'])->name('quotations.request');
    $r->post('/quotations/track/{token}/respond', [PublicController\CustomerController::class, 'respondToQuotation']);
    $r->post('/orders/track/{token}/dispute', [PublicController\CustomerController::class, 'disputeOrder']);
    $r->post('/orders/track/{token}/review', [PublicController\CustomerController::class, 'submitReview']);
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

        // ─── الصفحة التعريفية | Public page editor (§4.3) ───
        $r->get('/page', [Sme\PageController::class, 'edit'])
            ->permission('org.page.manage')->name('page.edit');
        $r->post('/page/settings', [Sme\PageController::class, 'saveSettings'])
            ->permission('org.page.manage');
        $r->post('/page/sections', [Sme\PageController::class, 'saveSections'])
            ->permission('org.page.manage');
        $r->post('/page/media', [Sme\PageController::class, 'uploadMedia'])
            ->permission('org.page.manage');
        $r->post('/page/media/{id:\d+}/delete', [Sme\PageController::class, 'deleteMedia'])
            ->permission('org.page.manage');
        $r->post('/page/publish', [Sme\PageController::class, 'publish'])
            ->permission('org.page.publish');
        $r->post('/page/unpublish', [Sme\PageController::class, 'unpublish'])
            ->permission('org.page.publish');

        // ─── المنتجات والخدمات | Listings (§4.4) ───
        $r->get('/listings', [Sme\ListingController::class, 'index'])
            ->permission('marketplace.listing.view')->name('listings');
        $r->get('/listings/new', [Sme\ListingController::class, 'create'])
            ->permission('marketplace.listing.create');
        $r->post('/listings', [Sme\ListingController::class, 'store'])
            ->permission('marketplace.listing.create');
        $r->get('/listings/{id:\d+}', [Sme\ListingController::class, 'edit'])
            ->permission('marketplace.listing.view');
        $r->post('/listings/{id:\d+}', [Sme\ListingController::class, 'update'])
            ->permission('marketplace.listing.update');
        $r->post('/listings/{id:\d+}/submit', [Sme\ListingController::class, 'submit'])
            ->permission('marketplace.listing.publish');
        $r->post('/listings/{id:\d+}/archive', [Sme\ListingController::class, 'archive'])
            ->permission('marketplace.listing.update');
        $r->post('/listings/{id:\d+}/delete', [Sme\ListingController::class, 'destroy'])
            ->permission('marketplace.listing.delete');
        $r->post('/listings/{id:\d+}/images', [Sme\ListingController::class, 'uploadImage'])
            ->permission('marketplace.listing.update');
        $r->post('/listings/{id:\d+}/images/{imageId:\d+}/delete', [Sme\ListingController::class, 'deleteImage'])
            ->permission('marketplace.listing.update');

        // ─── الطلبات | Orders ───
        $r->get('/orders', [Sme\SalesController::class, 'orders'])
            ->permission('marketplace.order.view')->name('orders');
        $r->get('/orders/{id:\d+}', [Sme\SalesController::class, 'showOrder'])
            ->permission('marketplace.order.view');
        $r->post('/orders/{id:\d+}/status', [Sme\SalesController::class, 'updateOrderStatus'])
            ->permission('marketplace.order.update_status');

        // ─── الاستفسارات | Enquiries ───
        $r->get('/enquiries', [Sme\SalesController::class, 'enquiries'])
            ->permission('marketplace.enquiry.view')->name('enquiries');
        $r->get('/enquiries/{id:\d+}', [Sme\SalesController::class, 'showEnquiry'])
            ->permission('marketplace.enquiry.view');
        $r->post('/enquiries/{id:\d+}/reply', [Sme\SalesController::class, 'replyToEnquiry'])
            ->permission('marketplace.enquiry.respond');

        // ─── عروض الأسعار | Quotations ───
        $r->get('/quotations', [Sme\SalesController::class, 'quotations'])
            ->permission('marketplace.quotation.manage')->name('quotations');
        $r->get('/quotations/{id:\d+}', [Sme\SalesController::class, 'showQuotation'])
            ->permission('marketplace.quotation.manage');
        $r->post('/quotations/{id:\d+}/quote', [Sme\SalesController::class, 'submitQuote'])
            ->permission('marketplace.quotation.manage');

        // ─── فريق المنشأة | Team (§3.4) ───
        $r->get('/team', [Sme\TeamController::class, 'index'])
            ->permission('org.member.view')->name('team');
        $r->post('/team/invite', [Sme\TeamController::class, 'invite'])
            ->permission('org.member.manage');
        $r->post('/team/invitations/{id:\d+}/revoke', [Sme\TeamController::class, 'revokeInvitation'])
            ->permission('org.member.manage');
        $r->post('/team/members/{id:\d+}/permissions', [Sme\TeamController::class, 'updatePermissions'])
            ->permission('org.member.manage');
        $r->post('/team/members/{id:\d+}/remove', [Sme\TeamController::class, 'removeMember'])
            ->permission('org.member.manage');

        // ═══════════ الخدمات المالية | Financial services (§4.5) ═══════════

        // — جانب المشروع | Applicant side —
        $r->get('/finance/opportunities', [Sme\FinanceController::class, 'opportunities'])
            ->permission('finance.product.view')->name('finance.opportunities');
        $r->get('/finance/products/{id:\d+}/apply', [Sme\FinanceController::class, 'applyForm'])
            ->permission('finance.application.submit');
        $r->post('/finance/products/{id:\d+}/apply', [Sme\FinanceController::class, 'apply'])
            ->permission('finance.application.submit');
        $r->get('/finance/applications', [Sme\FinanceController::class, 'applications'])
            ->permission('finance.application.view')->name('finance.applications');
        $r->get('/finance/applications/{id:\d+}', [Sme\FinanceController::class, 'showApplication'])
            ->permission('finance.application.view');
        $r->post('/finance/applications/{id:\d+}/action', [Sme\FinanceController::class, 'applicationAction'])
            ->permission('finance.application.submit');
        $r->post('/finance/applications/{id:\d+}/documents', [Sme\FinanceController::class, 'uploadDocument'])
            ->permission('finance.application.submit');

        // — جانب المؤسسة المالية | Provider side —
        // المنتجات تُنشأ هنا وتُرسل للاعتماد؛ النشر ليس قراراً للمؤسسة.
        $r->get('/finance/products', [Provider\FinanceController::class, 'products'])
            ->permission('finance.product.manage')->name('finance.products');
        $r->get('/finance/products/new', [Provider\FinanceController::class, 'createForm'])
            ->permission('finance.product.manage');
        $r->post('/finance/products', [Provider\FinanceController::class, 'store'])
            ->permission('finance.product.manage');
        $r->get('/finance/products/{id:\d+}', [Provider\FinanceController::class, 'editForm'])
            ->permission('finance.product.manage');
        $r->post('/finance/products/{id:\d+}', [Provider\FinanceController::class, 'update'])
            ->permission('finance.product.manage');
        $r->post('/finance/products/{id:\d+}/submit', [Provider\FinanceController::class, 'submit'])
            ->permission('finance.product.publish');
        $r->post('/finance/products/{id:\d+}/archive', [Provider\FinanceController::class, 'archive'])
            ->permission('finance.product.manage');

        $r->get('/finance/requests', [Provider\FinanceController::class, 'requests'])
            ->permission('finance.application.review')->name('finance.requests');
        $r->get('/finance/requests/{id:\d+}', [Provider\FinanceController::class, 'showRequest'])
            ->permission('finance.application.review');
        $r->post('/finance/requests/{id:\d+}/documents', [Provider\FinanceController::class, 'requestDocument'])
            ->permission('finance.application.review');
        // القرار وحده يتطلّب finance.application.decide — المراجعة لا تكفي
        $r->post('/finance/requests/{id:\d+}/action', [Provider\FinanceController::class, 'requestAction'])
            ->permission('finance.application.decide');

        // ═══════════ الخدمات غير المالية | Business services (§4.6) ═══════════

        // — جانب المشروع | Applicant side —
        $r->get('/services/browse', [Sme\ServicesController::class, 'browse'])
            ->permission('services.offering.view')->name('services.browse');
        $r->get('/services/browse/{id:\d+}/request', [Sme\ServicesController::class, 'requestForm'])
            ->permission('services.request.submit');
        $r->post('/services/browse/{id:\d+}/request', [Sme\ServicesController::class, 'submitRequest'])
            ->permission('services.request.submit');
        $r->get('/services/my-requests', [Sme\ServicesController::class, 'myRequests'])
            ->permission('services.request.submit')->name('services.my_requests');
        $r->get('/services/my-requests/{id:\d+}', [Sme\ServicesController::class, 'showRequest'])
            ->permission('services.request.submit');
        $r->post('/services/my-requests/{id:\d+}/action', [Sme\ServicesController::class, 'requestAction'])
            ->permission('services.request.submit');

        // — جانب مقدّم الخدمة | Provider side —
        $r->get('/services/offerings', [Provider\ServicesController::class, 'offerings'])
            ->permission('services.offering.manage')->name('services.offerings');
        $r->get('/services/offerings/new', [Provider\ServicesController::class, 'createForm'])
            ->permission('services.offering.manage');
        $r->post('/services/offerings', [Provider\ServicesController::class, 'store'])
            ->permission('services.offering.manage');
        $r->get('/services/offerings/{id:\d+}', [Provider\ServicesController::class, 'editForm'])
            ->permission('services.offering.manage');
        $r->post('/services/offerings/{id:\d+}', [Provider\ServicesController::class, 'update'])
            ->permission('services.offering.manage');
        $r->post('/services/offerings/{id:\d+}/submit', [Provider\ServicesController::class, 'submit'])
            ->permission('services.offering.publish');
        $r->post('/services/offerings/{id:\d+}/archive', [Provider\ServicesController::class, 'archive'])
            ->permission('services.offering.manage');

        // البوابة هنا services.quotation.submit لا services.request.view: الأخيرة
        // يحملها الطرفان بحكم أن كليهما يرى طلبات يخصّه، فلا تميّز جانب المزوّد.
        $r->get('/services/requests', [Provider\ServicesController::class, 'requests'])
            ->permission('services.quotation.submit')->name('services.requests');
        $r->get('/services/requests/{id:\d+}', [Provider\ServicesController::class, 'showRequest'])
            ->permission('services.quotation.submit');
        $r->post('/services/requests/{id:\d+}/action', [Provider\ServicesController::class, 'requestAction'])
            ->permission('services.quotation.submit');
        $r->post('/services/requests/{id:\d+}/milestones', [Provider\ServicesController::class, 'addMilestone'])
            ->permission('services.milestone.manage');
        $r->post('/services/requests/{id:\d+}/milestones/update', [Provider\ServicesController::class, 'updateMilestone'])
            ->permission('services.milestone.manage');

        // ═══════════ دعم مراكز تطوير الأعمال | BDS support (§4.8) ═══════════

        // — جانب المشروع | The SME side —
        $r->get('/bds/centers', [Sme\SupportController::class, 'centers'])
            ->permission('bds.case.request')->name('bds.centers');
        $r->get('/bds/centers/{id:\d+}/request', [Sme\SupportController::class, 'requestForm'])
            ->permission('bds.case.request');
        $r->post('/bds/centers/{id:\d+}/request', [Sme\SupportController::class, 'submitRequest'])
            ->permission('bds.case.request');
        $r->get('/bds/my-cases', [Sme\SupportController::class, 'myCases'])
            ->permission('bds.case.request')->name('bds.my_cases');
        $r->get('/bds/my-cases/{id:\d+}', [Sme\SupportController::class, 'showCase'])
            ->permission('bds.case.request');
        $r->post('/bds/my-cases/{id:\d+}/action', [Sme\SupportController::class, 'action'])
            ->permission('bds.case.request');
        $r->post('/bds/my-cases/{id:\d+}/notes', [Sme\SupportController::class, 'addNote'])
            ->permission('bds.case.request');
        $r->post('/bds/my-cases/{id:\d+}/tasks', [Sme\SupportController::class, 'updateTask'])
            ->permission('bds.case.request');
        $r->post('/bds/my-cases/{id:\d+}/referrals', [Sme\SupportController::class, 'respondToReferral'])
            ->permission('bds.case.request');
        $r->post('/bds/my-cases/{id:\d+}/rate', [Sme\SupportController::class, 'rate'])
            ->permission('bds.case.request');

        // — جانب المركز | The centre side —
        // البوابة bds.case.manage لا bds.case.view: الأخيرة يحملها صاحب المشروع
        // أيضاً ليرى حالته، فلا تميّز مساحة عمل المركز.
        $r->get('/bds/cases', [Bds\CaseController::class, 'index'])
            ->permission('bds.case.manage')->name('bds.cases');
        $r->get('/bds/cases/{id:\d+}', [Bds\CaseController::class, 'show'])
            ->permission('bds.case.manage');
        $r->post('/bds/cases/{id:\d+}/action', [Bds\CaseController::class, 'action'])
            ->permission('bds.case.manage');
        // كتابة الملاحظات تتطلّب صلاحية الملاحظات الداخلية؛ المشتركة تُفحص
        // إضافةً داخل المتحكّم لأنها تصل للمشروع.
        $r->post('/bds/cases/{id:\d+}/notes', [Bds\CaseController::class, 'addNote'])
            ->permission('bds.note.internal');
        $r->post('/bds/cases/{id:\d+}/consultations', [Bds\CaseController::class, 'scheduleConsultation'])
            ->permission('bds.consultation.manage');
        $r->post('/bds/cases/{id:\d+}/consultations/record', [Bds\CaseController::class, 'recordConsultation'])
            ->permission('bds.consultation.manage');
        $r->post('/bds/cases/{id:\d+}/plans', [Bds\CaseController::class, 'createPlan'])
            ->permission('bds.plan.manage');
        $r->post('/bds/cases/{id:\d+}/plans/tasks', [Bds\CaseController::class, 'addTask'])
            ->permission('bds.plan.manage');
        $r->post('/bds/cases/{id:\d+}/plans/share', [Bds\CaseController::class, 'sharePlan'])
            ->permission('bds.plan.manage');
        $r->post('/bds/cases/{id:\d+}/plans/tasks/update', [Bds\CaseController::class, 'updateTask'])
            ->permission('bds.plan.manage');
        $r->post('/bds/cases/{id:\d+}/referrals', [Bds\CaseController::class, 'refer'])
            ->permission('bds.referral.create');

        // ═══════════ تقييم الاحتياجات والاقتراحات | Assessment and matching (§4.7) ═══════════
        $r->get('/assessment', [Sme\AssessmentController::class, 'show'])
            ->permission('assessment.needs.view')->name('assessment');
        $r->get('/assessment/form', [Sme\AssessmentController::class, 'form'])
            ->permission('assessment.needs.submit');
        $r->post('/assessment/{id:\d+}/submit', [Sme\AssessmentController::class, 'submit'])
            ->permission('assessment.needs.submit');
        $r->post('/assessment/refresh', [Sme\AssessmentController::class, 'refreshSuggestions'])
            ->permission('assessment.needs.view');
        $r->post('/assessment/suggestions/{id:\d+}/dismiss', [Sme\AssessmentController::class, 'dismissSuggestion'])
            ->permission('assessment.needs.view');

        // ═══════════ إدارة العملاء | Customer management (§4.9) ═══════════
        $r->get('/customers', [Sme\CustomersController::class, 'index'])
            ->permission('crm.contact.view')->name('customers');
        $r->get('/customers/new', [Sme\CustomersController::class, 'create'])
            ->permission('crm.contact.manage');
        $r->post('/customers', [Sme\CustomersController::class, 'store'])
            ->permission('crm.contact.manage');
        $r->get('/customers/{id:\d+}', [Sme\CustomersController::class, 'show'])
            ->permission('crm.contact.view');
        $r->get('/customers/{id:\d+}/edit', [Sme\CustomersController::class, 'edit'])
            ->permission('crm.contact.manage');
        $r->post('/customers/{id:\d+}', [Sme\CustomersController::class, 'update'])
            ->permission('crm.contact.manage');
        $r->post('/customers/{id:\d+}/archive', [Sme\CustomersController::class, 'archive'])
            ->permission('crm.contact.manage');
        $r->post('/customers/{id:\d+}/contacts', [Sme\CustomersController::class, 'addContact'])
            ->permission('crm.contact.manage');
        $r->post('/customers/{id:\d+}/contacts/remove', [Sme\CustomersController::class, 'removeContact'])
            ->permission('crm.contact.manage');

        // ─── المهتمّون والفرص والمهام | Leads, opportunities and tasks ───
        $r->get('/pipeline/leads', [Sme\PipelineController::class, 'leads'])
            ->permission('crm.lead.view')->name('leads');
        $r->post('/pipeline/leads', [Sme\PipelineController::class, 'storeLead'])
            ->permission('crm.lead.manage');
        $r->post('/pipeline/leads/{id:\d+}/status', [Sme\PipelineController::class, 'updateLead'])
            ->permission('crm.lead.manage');
        // التحويل يُنشئ عميلاً، فيتطلّب صلاحية إدارة العملاء لا المهتمّين وحدها
        $r->post('/pipeline/leads/{id:\d+}/convert', [Sme\PipelineController::class, 'convertLead'])
            ->permission('crm.contact.manage');

        $r->get('/pipeline/opportunities', [Sme\PipelineController::class, 'opportunities'])
            ->permission('crm.opportunity.view')->name('opportunities');
        $r->post('/pipeline/opportunities', [Sme\PipelineController::class, 'storeOpportunity'])
            ->permission('crm.opportunity.manage');
        $r->get('/pipeline/opportunities/{id:\d+}', [Sme\PipelineController::class, 'showOpportunity'])
            ->permission('crm.opportunity.view');
        $r->post('/pipeline/opportunities/{id:\d+}', [Sme\PipelineController::class, 'updateOpportunity'])
            ->permission('crm.opportunity.manage');
        $r->post('/pipeline/opportunities/{id:\d+}/stage', [Sme\PipelineController::class, 'moveStage'])
            ->permission('crm.opportunity.manage');

        $r->get('/pipeline/tasks', [Sme\PipelineController::class, 'tasks'])
            ->permission('crm.task.manage')->name('crm_tasks');
        $r->post('/pipeline/activities', [Sme\PipelineController::class, 'storeActivity'])
            ->permission('crm.activity.manage');
        $r->post('/pipeline/activities/{id:\d+}/complete', [Sme\PipelineController::class, 'completeActivity'])
            ->permission('crm.activity.manage');
        $r->post('/pipeline/activities/{id:\d+}/cancel', [Sme\PipelineController::class, 'cancelActivity'])
            ->permission('crm.activity.manage');

        // ═══════════ الأصناف والمخزون | Items and stock (§4.10) ═══════════
        $r->get('/inventory', [Sme\InventoryController::class, 'index'])
            ->permission('erp.item.view')->name('inventory');
        $r->get('/inventory/new', [Sme\InventoryController::class, 'create'])
            ->permission('erp.item.manage');
        $r->post('/inventory', [Sme\InventoryController::class, 'store'])
            ->permission('erp.item.manage');
        $r->get('/inventory/{id:\d+}', [Sme\InventoryController::class, 'show'])
            ->permission('erp.item.view');
        $r->get('/inventory/{id:\d+}/edit', [Sme\InventoryController::class, 'edit'])
            ->permission('erp.item.manage');
        $r->post('/inventory/{id:\d+}', [Sme\InventoryController::class, 'update'])
            ->permission('erp.item.manage');
        $r->post('/inventory/{id:\d+}/archive', [Sme\InventoryController::class, 'archive'])
            ->permission('erp.item.manage');
        // تحريك الرصيد صلاحية مستقلّة عن تحرير بيانات الصنف
        $r->post('/inventory/{id:\d+}/movements', [Sme\InventoryController::class, 'recordMovement'])
            ->permission('erp.stock.manage');
        $r->post('/inventory/{id:\d+}/adjust', [Sme\InventoryController::class, 'adjust'])
            ->permission('erp.stock.manage');

        // ═══════════ فواتير البيع والمقبوضات | Invoices and receipts (§4.10) ═══════════
        $r->get('/invoices', [Sme\InvoicesController::class, 'index'])
            ->permission('erp.invoice.view')->name('invoices');
        $r->get('/invoices/new', [Sme\InvoicesController::class, 'create'])
            ->permission('erp.invoice.manage');
        $r->post('/invoices', [Sme\InvoicesController::class, 'store'])
            ->permission('erp.invoice.manage');
        $r->get('/invoices/{id:\d+}', [Sme\InvoicesController::class, 'show'])
            ->permission('erp.invoice.view');
        $r->get('/invoices/{id:\d+}/print', [Sme\InvoicesController::class, 'print'])
            ->permission('erp.invoice.view');
        $r->post('/invoices/{id:\d+}', [Sme\InvoicesController::class, 'update'])
            ->permission('erp.invoice.manage');
        $r->post('/invoices/{id:\d+}/lines', [Sme\InvoicesController::class, 'addLine'])
            ->permission('erp.invoice.manage');
        $r->post('/invoices/{id:\d+}/lines/remove', [Sme\InvoicesController::class, 'removeLine'])
            ->permission('erp.invoice.manage');
        $r->post('/invoices/{id:\d+}/discount', [Sme\InvoicesController::class, 'applyDiscount'])
            ->permission('erp.invoice.manage');
        $r->post('/invoices/{id:\d+}/issue', [Sme\InvoicesController::class, 'issue'])
            ->permission('erp.invoice.manage');
        $r->post('/invoices/{id:\d+}/cancel', [Sme\InvoicesController::class, 'cancel'])
            ->permission('erp.invoice.manage');
        // تسجيل المقبوضات صلاحية مستقلّة: من يحصّل ليس بالضرورة من يفوتر
        $r->post('/invoices/{id:\d+}/payments', [Sme\InvoicesController::class, 'recordPayment'])
            ->permission('erp.receipt.manage');
        $r->post('/invoices/{id:\d+}/payments/remove', [Sme\InvoicesController::class, 'deletePayment'])
            ->permission('erp.receipt.manage');

        // ═══════════ المشتريات والموردون والمصروفات | Purchasing (§4.10) ═══════════
        $r->get('/purchasing/suppliers', [Sme\PurchasingController::class, 'suppliers'])
            ->permission('erp.supplier.manage')->name('suppliers');
        $r->post('/purchasing/suppliers', [Sme\PurchasingController::class, 'storeSupplier'])
            ->permission('erp.supplier.manage');
        $r->post('/purchasing/suppliers/{id:\d+}', [Sme\PurchasingController::class, 'updateSupplier'])
            ->permission('erp.supplier.manage');
        $r->post('/purchasing/suppliers/{id:\d+}/archive', [Sme\PurchasingController::class, 'archiveSupplier'])
            ->permission('erp.supplier.manage');

        $r->get('/purchasing/orders', [Sme\PurchasingController::class, 'orders'])
            ->permission('erp.purchase_order.manage')->name('purchase_orders');
        $r->post('/purchasing/orders', [Sme\PurchasingController::class, 'storeOrder'])
            ->permission('erp.purchase_order.manage');
        $r->get('/purchasing/orders/{id:\d+}', [Sme\PurchasingController::class, 'showOrder'])
            ->permission('erp.purchase_order.manage');
        $r->post('/purchasing/orders/{id:\d+}/lines', [Sme\PurchasingController::class, 'addOrderLine'])
            ->permission('erp.purchase_order.manage');
        $r->post('/purchasing/orders/{id:\d+}/lines/remove', [Sme\PurchasingController::class, 'removeOrderLine'])
            ->permission('erp.purchase_order.manage');
        $r->post('/purchasing/orders/{id:\d+}/send', [Sme\PurchasingController::class, 'sendOrder'])
            ->permission('erp.purchase_order.manage');
        // الاستلام يحرّك المخزون، فيُحرَس بصلاحية المخزون لا الشراء
        $r->post('/purchasing/orders/{id:\d+}/receive', [Sme\PurchasingController::class, 'receiveOrder'])
            ->permission('erp.stock.manage');
        $r->post('/purchasing/orders/{id:\d+}/cancel', [Sme\PurchasingController::class, 'cancelOrder'])
            ->permission('erp.purchase_order.manage');

        $r->get('/purchasing/expenses', [Sme\PurchasingController::class, 'expenses'])
            ->permission('erp.expense.manage')->name('expenses');
        $r->post('/purchasing/expenses', [Sme\PurchasingController::class, 'storeExpense'])
            ->permission('erp.expense.manage');
        $r->post('/purchasing/expenses/{id:\d+}/delete', [Sme\PurchasingController::class, 'deleteExpense'])
            ->permission('erp.expense.manage');

        // ═══════════ التقارير الإدارية | Management reports (§4.10) ═══════════
        $r->get('/reports', [Sme\ReportsController::class, 'index'])
            ->permission('erp.report.view')->name('erp_reports');

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

        // ─── مراجعة الإعلانات والشكاوى | Listing moderation and complaints ───
        $r->get('/moderation', [Admin\ModerationController::class, 'index'])
            ->permission('marketplace.listing.moderate')->name('admin.moderation');
        $r->get('/moderation/{id:\d+}', [Admin\ModerationController::class, 'show'])
            ->permission('marketplace.listing.moderate');
        $r->post('/moderation/{id:\d+}/decide', [Admin\ModerationController::class, 'decide'])
            ->permission('marketplace.listing.moderate');
        $r->get('/complaints', [Admin\ModerationController::class, 'complaints'])
            ->permission('marketplace.complaint.view')->name('admin.complaints');

        // ─── اعتماد المنتجات التمويلية | Financing product approval (§4.5) ───
        $r->get('/finance/products', [Admin\CatalogApprovalController::class, 'financingIndex'])
            ->permission('finance.product.moderate')->name('admin.finance.products');
        $r->get('/finance/products/{id:\d+}', [Admin\CatalogApprovalController::class, 'financingShow'])
            ->permission('finance.product.moderate');
        $r->post('/finance/products/{id:\d+}/decide', [Admin\CatalogApprovalController::class, 'financingDecide'])
            ->permission('finance.product.moderate');

        // ─── اعتماد باقات الخدمات | Service offering approval (§4.6) ───
        $r->get('/services/offerings', [Admin\CatalogApprovalController::class, 'servicesIndex'])
            ->permission('services.offering.moderate')->name('admin.services.offerings');
        $r->get('/services/offerings/{id:\d+}', [Admin\CatalogApprovalController::class, 'servicesShow'])
            ->permission('services.offering.moderate');
        $r->post('/services/offerings/{id:\d+}/decide', [Admin\CatalogApprovalController::class, 'servicesDecide'])
            ->permission('services.offering.moderate');

        // ─── فرز طلبات التمويل | Financing application screening (§4.5) ───
        // الفرز والإحالة فقط. لا يوجد هنا مسار اعتماد أو رفض: القرار للمموّل.
        $r->get('/finance/applications', [Admin\FinanceScreeningController::class, 'index'])
            ->permission('finance.application.view_any')->name('admin.finance.applications');
        $r->get('/finance/applications/{id:\d+}', [Admin\FinanceScreeningController::class, 'show'])
            ->permission('finance.application.view_any');
        $r->post('/finance/applications/{id:\d+}/action', [Admin\FinanceScreeningController::class, 'action'])
            ->permission('finance.application.screen');
    },
);

Application::instance()?->setRouter($router);

return $router;
