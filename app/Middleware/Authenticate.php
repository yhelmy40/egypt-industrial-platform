<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\UserRepository;
use Closure;

/**
 * التحقق من تسجيل الدخول | Require an authenticated, usable account.
 *
 * لا يكفي وجود user_id في الجلسة: تُعاد قراءة المستخدم من قاعدة البيانات في
 * كل طلب للتأكد أن الحساب لم يُوقَف أو يُحذف بعد بدء الجلسة (§9 — إيقاف الحساب
 * يجب أن ينهي الجلسات الجارية).
 * A session user_id is not enough: the user row is re-read on every request so
 * suspension or deletion takes effect on live sessions immediately.
 */
final class Authenticate implements MiddlewareInterface
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $userId = Session::get('user_id');

        if (!is_int($userId)) {
            return $this->unauthenticated($request, 'يجب تسجيل الدخول للوصول إلى هذه الصفحة.');
        }

        $user = $this->users->find($userId);

        if ($user === null) {
            Session::invalidate();

            return $this->unauthenticated($request, 'انتهت صلاحية الجلسة. يرجى تسجيل الدخول مجدداً.');
        }

        if (in_array($user['status'], ['suspended', 'deactivated'], true)) {
            Session::invalidate();

            return $this->unauthenticated(
                $request,
                $user['status'] === 'suspended'
                    ? 'تم إيقاف هذا الحساب. يرجى التواصل مع الدعم الفني.'
                    : 'هذا الحساب غير مُفعَّل.',
            );
        }

        // يُتاح كائن المستخدم لبقية الطلب دون إعادة استعلام
        Session::put('_user', $user);

        return $next($request);
    }

    private function unauthenticated(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            throw new HttpException(401, $message);
        }

        Session::flash('warning', $message);

        // حفظ الوجهة المقصودة للعودة إليها بعد الدخول
        if ($request->method() === 'GET') {
            Session::put('_intended_url', $request->path());
        }

        return Response::redirect(url('/auth/login'));
    }
}
