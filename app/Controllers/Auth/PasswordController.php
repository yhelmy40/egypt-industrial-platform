<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Contracts\MailerInterface;
use App\Controllers\Controller;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use App\Validation\Validator;

/**
 * استعادة وتغيير كلمة المرور | Password reset and change.
 */
final class PasswordController extends Controller
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService(
            mailer: Container::getInstance()->make(MailerInterface::class),
        );
    }

    public function showForgotForm(Request $request): Response
    {
        return $this->view('auth/forgot-password', [
            'pageTitle' => __('auth.forgot_title'),
        ], 'auth');
    }

    public function sendResetLink(Request $request): Response
    {
        $validator = Validator::make($request->all())
            ->labels(['email' => __('auth.email')])
            ->required('email')->email('email');

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/auth/forgot-password');
        }

        $result = $this->auth->requestPasswordReset((string) $request->input('email'), $request);

        // رسالة موحّدة سواء وُجد الحساب أم لا (منع استكشاف الحسابات)
        $this->flash('info', $result['message']);

        return $this->redirect('/auth/login');
    }

    public function showResetForm(Request $request): Response
    {
        $token = (string) ($request->input('token') ?? '');

        if ($token === '') {
            $this->flash('danger', __('auth.reset_invalid'));

            return $this->redirect('/auth/forgot-password');
        }

        return $this->view('auth/reset-password', [
            'pageTitle' => __('auth.reset_title'),
            'token'     => $token,
        ], 'auth');
    }

    public function resetPassword(Request $request): Response
    {
        $validator = Validator::make($request->all())
            ->labels([
                'password'              => __('auth.new_password'),
                'password_confirmation' => __('auth.password_confirmation'),
            ])
            ->required('token')
            ->required('password')->password('password')
            ->required('password_confirmation')->matches('password_confirmation', 'password');

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/auth/forgot-password');
        }

        $result = $this->auth->resetPassword(
            (string) $request->input('token'),
            (string) $request->input('password'),
            $request,
        );

        $this->flash($result['success'] ? 'success' : 'danger', $result['message']);

        return $this->redirect('/auth/login');
    }

    // ---------------- تغيير كلمة المرور من داخل الحساب ----------------

    public function showChangeForm(Request $request): Response
    {
        return $this->view('auth/change-password', [
            'pageTitle' => __('auth.new_password'),
        ], 'app');
    }

    public function changePassword(Request $request): Response
    {
        $userId = $this->currentUserId();

        if ($userId === null) {
            return $this->redirect('/auth/login');
        }

        $validator = Validator::make($request->all())
            ->labels([
                'current_password'      => __('auth.current_password'),
                'password'              => __('auth.new_password'),
                'password_confirmation' => __('auth.password_confirmation'),
            ])
            ->required('current_password')
            ->required('password')->password('password')
            ->required('password_confirmation')->matches('password_confirmation', 'password');

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/app/account/password');
        }

        $result = $this->auth->changePassword(
            $userId,
            (string) $request->input('current_password'),
            (string) $request->input('password'),
            $request,
        );

        if (!$result['success']) {
            return $this->back($request, ['current_password' => $result['message']], '/app/account/password');
        }

        $this->flash('success', $result['message']);

        return $this->redirect('/app');
    }
}
