<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * فشل التحقق من المدخلات | Input validation failure (HTTP 422).
 */
final class ValidationException extends HttpException
{
    /** @param array<string,string> $errors */
    public function __construct(
        private readonly array $errors,
        string $message = 'يرجى مراجعة البيانات المُدخلة.',
    ) {
        parent::__construct(422, $message);
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
