<?php

declare(strict_types=1);

/**
 * نقطة الدخول الوحيدة للتطبيق | Single front controller.
 *
 * جذر الويب هو هذا المجلد فقط (/public). كل ما عدا ذلك — الكود والإعدادات
 * والمرفوعات — خارج نطاق الويب ولا يمكن الوصول إليه مباشرة (§9).
 * The web root is this directory only. Code, configuration and uploads live
 * outside it and are unreachable over HTTP.
 */

use App\Core\Application;
use App\Core\Request;

$basePath = dirname(__DIR__);

require $basePath . '/vendor/autoload.php';

$app     = Application::boot($basePath);
$request = Request::capture();

$app->handle($request)->send();
