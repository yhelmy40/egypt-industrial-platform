<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Contracts\MailerInterface;
use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\PermissionResolver;
use App\Validation\Validator;

/**
 * إنشاء الحسابات | Account registration.
 *
 * تسجيل الحساب منفصل عن تسجيل المنشأة: يُنشئ المستخدم حسابه أولاً، ثم يختار
 * نوع المنشأة ويكمل بياناتها في المرحلة الثانية. هذا يسمح لمستخدم واحد أن
 * ينتمي لعدة منشآت لاحقاً (§7).
 * Account registration is separate from organization registration: the user
 * creates an account, then registers one or more organizations.
 */
final class RegisterController extends Controller
{
    private AuthService $auth;

    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly PermissionResolver $permissions = new PermissionResolver(),
    ) {
        $this->auth = new AuthService(
            mailer: Container::getInstance()->make(MailerInterface::class),
        );
    }

    public function showRegistrationForm(Request $request): Response
    {
        return $this->view('auth/register', [
            'pageTitle'     => __('auth.register_title'),
            'policyVersion' => \App\Services\SettingsService::get('general', 'policy_version', '1.0'),
        ], 'auth');
    }

    public function register(Request $request): Response
    {
        $validator = Validator::make($request->all())
            ->labels([
                'name'                  => __('auth.name'),
                'email'                 => __('auth.email'),
                'phone'                 => __('auth.phone'),
                'password'              => __('auth.password'),
                'password_confirmation' => __('auth.password_confirmation'),
                'accept_terms'          => __('auth.terms_link'),
            ])
            ->required('name')->minLength('name', 3)->maxLength('name', 150)
            ->required('email')->email('email')->maxLength('email', 190)
            ->phone('phone')
            ->required('password')->password('password')
            ->required('password_confirmation')->matches('password_confirmation', 'password')
            ->accepted('accept_terms');

        // فحص تكرار البريد بعد التحقق الشكلي لتقليل الاستعلامات
        if (!$validator->fails() && $this->users->emailExists((string) $request->input('email'))) {
            $validator->addError('email', __('auth.email_taken'));
        }

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/auth/register');
        }

        $result = $this->auth->register(
            (string) $request->input('name'),
            (string) $request->input('email'),
            (string) $request->input('password'),
            $request->filled('phone') ? (string) $request->input('phone') : null,
            $request,
        );

        $userId = $result['user_id'];

        // موافقات صريحة موثّقة بالنسخة والتاريخ (§10)
        $this->auth->recordConsent($userId, 'terms', $request);
        $this->auth->recordConsent($userId, 'privacy', $request);

        // كل حساب جديد يحصل على دور عميل السوق كحد أدنى، ليتمكن من التصفّح
        // والتواصل قبل تسجيل أي منشأة.
        $customerRole = $this->permissions->findRoleByCode('marketplace_customer');
        if ($customerRole !== null) {
            $this->permissions->assignPlatformRole($userId, (int) $customerRole['id']);
        }

        $this->sendVerificationEmail(
            (string) $request->input('email'),
            (string) $request->input('name'),
            $result['verification_token'],
        );

        $this->flash('success', __('auth.register_success'));

        return $this->redirect('/auth/verify-notice');
    }

    private function sendVerificationEmail(string $email, string $name, string $token): void
    {
        /** @var MailerInterface $mailer */
        $mailer = Container::getInstance()->make(MailerInterface::class);

        $link = rtrim((string) Config::get('app.url'), '/')
            . url('/auth/verify') . '?token=' . $token;

        $mailer->send(
            $email,
            $name,
            'تأكيد بريدك الإلكتروني — ' . Config::get('app.name'),
            '<p>مرحباً ' . e($name) . '،</p>'
            . '<p>شكراً لتسجيلك في ' . e((string) Config::get('app.name')) . '.</p>'
            . '<p>لتفعيل حسابك، اضغط على الرابط التالي:</p>'
            . '<p><a href="' . e($link) . '">' . e($link) . '</a></p>'
            . '<p>الرابط صالح لمدة 24 ساعة.</p>',
            "مرحباً {$name},\n\nلتفعيل حسابك افتح الرابط:\n{$link}\n\nالرابط صالح لمدة 24 ساعة.",
        );
    }

    public function verifyNotice(Request $request): Response
    {
        return $this->view('auth/verify-notice', [
            'pageTitle' => __('auth.verify_title'),
        ], 'auth');
    }

    public function verify(Request $request): Response
    {
        $token = (string) ($request->input('token') ?? '');

        if ($token === '') {
            $this->flash('danger', __('auth.verification_invalid'));

            return $this->redirect('/auth/login');
        }

        $result = $this->auth->verifyEmail($token);

        $this->flash($result['success'] ? 'success' : 'danger', $result['message']);

        return $this->redirect('/auth/login');
    }
}
