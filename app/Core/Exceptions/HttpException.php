<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;
use Throwable;

/**
 * خطأ HTTP معروف | A known HTTP error with an Arabic user-facing message.
 *
 * الرسالة تُعرض للمستخدم، لذا يجب ألّا تحتوي تفاصيل داخلية.
 * The message is shown to the user and must not contain internal detail.
 */
class HttpException extends RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
