<?php
/**
 * public/index.php
 * نقطة الدخول الوحيدة للتطبيق | Single application entry point (front controller).
 * كل الطلبات تمر من هنا عبر .htaccess.
 */

// 1) التكوين | Configuration
require_once dirname(__DIR__) . '/config/config.php';

// 2) بدء الجلسة بإعدادات آمنة | Start session with safe settings
session_name(SESSION_NAME);
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// 3) تحميل النواة | Load core
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/core/Model.php';
require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/core/Router.php';

// 4) تحميل المساعدات | Load helpers
require_once APP_PATH . '/helpers/functions.php';
require_once APP_PATH . '/helpers/Auth.php';
require_once APP_PATH . '/helpers/Csrf.php';
require_once APP_PATH . '/helpers/Validator.php';

// 5) التوجيه | Dispatch
$router = new Router();
$router->dispatch();
