<?php
/**
 * Router.php
 * موجّه بسيط | Simple router.
 * النمط: controller/action/param1/param2 ...
 * المصدر: $_GET['url'] (عبر .htaccess) أو المسار المباشر.
 */
class Router
{
    public function dispatch(): void
    {
        $url = $this->parseUrl();

        // الافتراضي | Defaults
        $controllerName = !empty($url[0]) ? ucfirst($url[0]) . 'Controller' : 'AuthController';
        $action         = !empty($url[1]) ? $url[1] : 'index';
        $params         = array_slice($url, 2);

        $controllerFile = APP_PATH . '/controllers/' . $controllerName . '.php';

        if (!file_exists($controllerFile)) {
            $this->notFound("Controller not found: {$controllerName}");
            return;
        }

        require_once $controllerFile;

        if (!class_exists($controllerName)) {
            $this->notFound("Controller class missing: {$controllerName}");
            return;
        }

        $controller = new $controllerName();

        if (!method_exists($controller, $action)) {
            $this->notFound("Action not found: {$controllerName}::{$action}");
            return;
        }

        call_user_func_array([$controller, $action], $params);
    }

    /** تحليل عنوان URL إلى أجزاء | Parse URL into segments */
    private function parseUrl(): array
    {
        $url = $_GET['url'] ?? '';
        $url = rtrim($url, '/');
        $url = filter_var($url, FILTER_SANITIZE_URL);
        if ($url === '') {
            return [];
        }
        return explode('/', $url);
    }

    private function notFound(string $msg): void
    {
        http_response_code(404);
        echo '<div style="font-family:sans-serif;direction:rtl;text-align:center;margin-top:60px">';
        echo '<h1>404</h1><p>الصفحة غير موجودة</p>';
        if (APP_DEBUG) {
            echo '<p style="color:#888">' . htmlspecialchars($msg) . '</p>';
        }
        echo '<p><a href="' . url('dashboard') . '">العودة للرئيسية</a></p>';
        echo '</div>';
    }
}
