<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use Closure;

/**
 * واجهة الوسيط | Middleware contract.
 *
 * كل وسيط يستقبل الطلب ويمرّره للتالي أو يقطع السلسلة باستجابة.
 * Each middleware either passes the request onward or short-circuits with a
 * response of its own.
 */
interface MiddlewareInterface
{
    /**
     * @param Closure(Request):Response $next
     */
    public function handle(Request $request, Closure $next): Response;
}
