<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * رفض التفويض | Authorization denial (HTTP 403).
 *
 * تُلتقط مركزياً وتُسجَّل في سجل التدقيق، لأن محاولات الوصول غير المصرّح بها
 * مؤشر أمني يجب تتبّعه.
 * Caught centrally and written to the audit log — unauthorized attempts are a
 * security signal worth tracking.
 */
final class AuthorizationException extends HttpException
{
    public function __construct(
        string $message = 'ليس لديك صلاحية للقيام بهذا الإجراء.',
        private readonly ?string $permission = null,
        private readonly ?string $resource = null,
    ) {
        parent::__construct(403, $message);
    }

    public function permission(): ?string
    {
        return $this->permission;
    }

    public function resource(): ?string
    {
        return $this->resource;
    }
}
