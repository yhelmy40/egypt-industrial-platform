<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Support\TenantContext;

/**
 * المتحكّم الأساسي | Base controller.
 *
 * المتحكّمات هنا رقيقة عمداً: تتحقق من المدخلات، تستدعي خدمة، وتختار العرض.
 * لا استعلامات SQL ولا قواعد أعمال داخلها (§6).
 * Controllers are deliberately thin: validate input, call a service, pick a
 * view. No SQL and no business rules live here.
 */
abstract class Controller
{
    protected function view(string $template, array $data = [], string $layout = 'app'): Response
    {
        /** @var View $view */
        $view = Container::getInstance()->make(View::class);

        $data += [
            'pageTitle'    => null,
            'flash'        => Session::pullFlash(),
            'currentUser'  => Session::get('_user'),
            'organization' => TenantContext::organization(),
        ];

        return Response::html($view->render($template, $data, $layout));
    }

    protected function redirect(string $path): Response
    {
        return Response::redirect(url($path));
    }

    /** إعادة توجيه للخلف مع المدخلات والأخطاء | Redirect back preserving state. */
    protected function back(Request $request, array $errors = [], ?string $fallback = null): Response
    {
        if ($errors !== []) {
            Session::flashErrors($errors);
        }

        Session::flashInput($request->all());

        $referer = $request->header('Referer');
        $target  = $fallback ?? '/';

        // يُقبل المرجع فقط إذا كان داخلياً، منعاً لإعادة التوجيه المفتوح.
        // The Referer is honoured only when internal — prevents open redirects.
        if (is_string($referer) && $referer !== '') {
            $path = parse_url($referer, PHP_URL_PATH);
            $host = parse_url($referer, PHP_URL_HOST);
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

            if (is_string($path) && ($host === null || $host === $appHost)) {
                return Response::redirect($path);
            }
        }

        return $this->redirect($target);
    }

    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function flash(string $type, string $message): void
    {
        Session::flash($type, $message);
    }

    protected function currentUser(): ?array
    {
        $user = Session::get('_user');

        return is_array($user) ? $user : null;
    }

    protected function currentUserId(): ?int
    {
        $id = Session::get('user_id');

        return is_int($id) ? $id : null;
    }

    /**
     * فرض صلاحية | Require a permission (defence layer two).
     *
     * يُستخدم للفحوص التي لا يغطيها جدول المسارات، مثل صلاحية تعتمد على حالة
     * السجل نفسه.
     */
    protected function authorize(string $permission, ?string $resource = null): void
    {
        if (!TenantContext::can($permission)) {
            throw new AuthorizationException(
                'ليس لديك صلاحية للقيام بهذا الإجراء.',
                $permission,
                $resource,
            );
        }
    }

    /** يتطلّب وجود منشأة نشطة | Require an active organization context. */
    protected function requireOrganization(): int
    {
        $organizationId = TenantContext::organizationId();

        if ($organizationId === null) {
            throw new AuthorizationException('يجب اختيار منشأة نشطة للوصول إلى هذه الصفحة.');
        }

        return $organizationId;
    }

    /** رقم الصفحة من الطلب | Current page number. */
    protected function page(Request $request): int
    {
        return max(1, $request->integer('page', 1) ?? 1);
    }
}
