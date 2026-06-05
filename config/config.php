<?php
/**
 * ============================================================
 *  إعدادات المنصة العامة | Global platform configuration
 *  Egypt Industrial Research & Development Platform
 * ============================================================
 *  عدّل هذه القيم بما يتوافق مع بيئتك المحلية (XAMPP)
 *  Adjust these values to match your local (XAMPP) environment
 * ============================================================
 */

// --- Database credentials (XAMPP defaults) -----------------
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'egypt_irdp');
define('DB_USER', 'root');
define('DB_PASS', ''); // XAMPP default root password is empty
define('DB_CHARSET', 'utf8mb4');

/**
 * BASE_URL : المسار الأساسي للوصول للمنصة من المتصفح
 * Set this to the public path under which the app is served.
 *
 * Examples:
 *   - Apache docroot points to /public      => '' (empty)
 *   - http://localhost/egypt-irdp/public/    => '/egypt-irdp/public'
 *
 * Leave it empty to let the app auto-detect from SCRIPT_NAME.
 */
define('BASE_URL', '');

// --- Paths --------------------------------------------------
define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('UPLOAD_PATH', ROOT_PATH . '/uploads');

// --- App meta ----------------------------------------------
define('APP_NAME_AR', 'منصة مصر للبحث والتطوير الصناعي');
define('APP_NAME_EN', 'Egypt Industrial Research & Development Platform');
define('APP_VERSION', '1.0.0-MVP');

// --- Session / security ------------------------------------
define('SESSION_NAME', 'EG_IRDP_SESSION');

// --- Error reporting (set to false on production) ----------
define('APP_DEBUG', true);
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

date_default_timezone_set('Africa/Cairo');
