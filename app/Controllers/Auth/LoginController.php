<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\Controller;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuthService;
use App\Validation\Validator;

/**
 * تسجيل الدخول والخروج | Login and logout.
 */
final class LoginController extends Controller
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService(
            mailer: Container::getInstance()->make(\App\Contracts\MailerInterface::class),
        );
    }

    public function showLoginForm(Request $request): Response
    {
        return $this->view('auth/login', [
            'pageTitle' => __('auth.login_title'),
        ], 'auth');
    }

    public function login(Request $request): Response
    {
        $validator = Validator::make($request->all())
            ->labels([
                'email'    => __('auth.email'),
                'password' => __('auth.password'),
            ])
            ->required('email')->email('email')
            ->required('password');

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/auth/login');
        }

        $result = $this->auth->attemptLogin(
            (string) $request->input('email'),
            (string) $request->input('password'),
            $request,
        );

        if (!$result['success']) {
            $this->flash('danger', $result['message']);

            return $this->back($request, ['email' => $result['message']], '/auth/login');
        }

        $this->flash('success', $result['message']);

        // إجبار تغيير كلمة المرور لحسابات العرض التجريبي (§15)
        if ((int) ($result['user']['must_change_password'] ?? 0) === 1) {
            $this->flash('warning', __('auth.must_change_password'));

            return $this->redirect('/app/account/password');
        }

        // العودة إلى الوجهة المقصودة قبل إعادة التوجيه لصفحة الدخول
        $intended = Session::get('_intended_url');
        Session::forget('_intended_url');

        if (is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//')) {
            return $this->redirect($intended);
        }

        return $this->redirect('/app');
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout($request);

        Session::start();
        Session::flash('success', __('auth.logout_success'));

        return $this->redirect('/');
    }
}
