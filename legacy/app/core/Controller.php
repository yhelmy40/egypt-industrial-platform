<?php
/**
 * Controller.php
 * المتحكم الأساسي | Base controller.
 * يوفّر عرض القوالب، إعادة التوجيه، وحماية الصلاحيات.
 */
abstract class Controller
{
    /**
     * عرض قالب داخل تخطيط | Render a view inside a layout.
     */
    protected function view(string $view, array $data = [], ?string $layout = 'main'): void
    {
        extract($data, EXTR_SKIP);
        $viewFile = APP_PATH . '/views/' . $view . '.php';

        if (!file_exists($viewFile)) {
            http_response_code(500);
            die("View not found: {$view}");
        }

        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        if ($layout) {
            require APP_PATH . '/views/layouts/' . $layout . '.php';
        } else {
            echo $content;
        }
    }

    /** تحميل نموذج | Load a model instance */
    protected function model(string $name): Model
    {
        require_once APP_PATH . '/models/' . $name . '.php';
        return new $name();
    }

    /** إعادة توجيه | Redirect to a route */
    protected function redirect(string $path): void
    {
        header('Location: ' . url($path));
        exit;
    }

    /** رسالة فلاش | Set a flash message */
    protected function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    // ---------------- Auth guards ----------------

    /** يتطلب تسجيل الدخول | Require an authenticated user */
    protected function requireLogin(): void
    {
        if (!Auth::check()) {
            $this->flash('warning', 'يجب تسجيل الدخول أولاً للوصول إلى هذه الصفحة.');
            $this->redirect('auth/login');
        }
    }

    /**
     * يتطلب دوراً محدداً | Require one of the given roles.
     * @param string|string[] $roles
     */
    protected function requireRole($roles): void
    {
        $this->requireLogin();
        $roles = (array) $roles;
        if (!in_array(Auth::role(), $roles, true)) {
            http_response_code(403);
            $this->flash('danger', 'ليس لديك صلاحية الوصول إلى هذه الصفحة.');
            $this->redirect('dashboard');
        }
    }

    /** التحقق من رمز CSRF في الطلبات | Verify CSRF token on POST */
    protected function verifyCsrf(): void
    {
        $token = $_POST['csrf_token'] ?? '';
        if (!Csrf::verify($token)) {
            http_response_code(419);
            die('رمز الحماية (CSRF) غير صالح. يرجى تحديث الصفحة وإعادة المحاولة.');
        }
    }

    /** هل الطلب من نوع POST؟ | Is this a POST request? */
    protected function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }
}
