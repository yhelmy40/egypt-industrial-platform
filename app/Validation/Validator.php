<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\Config;
use App\Core\Exceptions\ValidationException;

/**
 * مُحقّق المدخلات | Server-side input validator (§9).
 *
 * كل تحقق يقع على الخادم؛ تحقق المتصفح تحسين للتجربة فقط ولا يُعتمد عليه.
 * All validation is server-side; browser validation is a UX nicety only.
 *
 * الرسائل تأتي من ملفات الترجمة (§5) وليست مكتوبة داخل المنطق.
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /** @var array<string,string> */
    private array $labels = [];

    public function __construct(
        private readonly array $data,
    ) {
    }

    public static function make(array $data): self
    {
        return new self($data);
    }

    /** تسمية الحقول بالعربية لرسائل واضحة | Arabic field labels. */
    public function labels(array $labels): self
    {
        $this->labels = $labels;

        return $this;
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? $field;
    }

    private function value(string $field): mixed
    {
        $value = $this->data[$field] ?? null;

        return is_string($value) ? trim($value) : $value;
    }

    private function isEmpty(string $field): bool
    {
        $value = $this->value($field);

        return $value === null || $value === '' || $value === [];
    }

    private function fail(string $field, string $message): void
    {
        // أول خطأ لكل حقل فقط، حتى لا تتراكم الرسائل على نفس الحقل.
        $this->errors[$field] ??= $message;
    }

    // ---------------- قواعد التحقق | Rules ----------------

    public function required(string $field): self
    {
        if ($this->isEmpty($field)) {
            $this->fail($field, __('validation.required', ['field' => $this->label($field)]));
        }

        return $this;
    }

    public function requiredIf(string $field, bool $condition): self
    {
        return $condition ? $this->required($field) : $this;
    }

    public function email(string $field): self
    {
        if (!$this->isEmpty($field) && filter_var((string) $this->value($field), FILTER_VALIDATE_EMAIL) === false) {
            $this->fail($field, __('validation.email', ['field' => $this->label($field)]));
        }

        return $this;
    }

    public function minLength(string $field, int $min): self
    {
        if (!$this->isEmpty($field) && mb_strlen((string) $this->value($field)) < $min) {
            $this->fail($field, __('validation.min_length', [
                'field' => $this->label($field),
                'min'   => $min,
            ]));
        }

        return $this;
    }

    public function maxLength(string $field, int $max): self
    {
        if (!$this->isEmpty($field) && mb_strlen((string) $this->value($field)) > $max) {
            $this->fail($field, __('validation.max_length', [
                'field' => $this->label($field),
                'max'   => $max,
            ]));
        }

        return $this;
    }

    public function numeric(string $field): self
    {
        if (!$this->isEmpty($field) && !is_numeric($this->value($field))) {
            $this->fail($field, __('validation.numeric', ['field' => $this->label($field)]));
        }

        return $this;
    }

    public function integer(string $field): self
    {
        if (!$this->isEmpty($field) && filter_var($this->value($field), FILTER_VALIDATE_INT) === false) {
            $this->fail($field, __('validation.integer', ['field' => $this->label($field)]));
        }

        return $this;
    }

    public function between(string $field, float $min, float $max): self
    {
        if (!$this->isEmpty($field) && is_numeric($this->value($field))) {
            $value = (float) $this->value($field);
            if ($value < $min || $value > $max) {
                $this->fail($field, __('validation.between', [
                    'field' => $this->label($field),
                    'min'   => $min,
                    'max'   => $max,
                ]));
            }
        }

        return $this;
    }

    public function in(string $field, array $allowed): self
    {
        if (!$this->isEmpty($field) && !in_array((string) $this->value($field), array_map('strval', $allowed), true)) {
            $this->fail($field, __('validation.invalid_choice', ['field' => $this->label($field)]));
        }

        return $this;
    }

    public function date(string $field): self
    {
        if (!$this->isEmpty($field) && strtotime((string) $this->value($field)) === false) {
            $this->fail($field, __('validation.date', ['field' => $this->label($field)]));
        }

        return $this;
    }

    /**
     * رقم هاتف مصري | Egyptian phone number.
     * يقبل 01XXXXXXXXX أو +201XXXXXXXXX أو أرقاماً أرضية من 8 إلى 15 رقماً.
     */
    public function phone(string $field): self
    {
        if ($this->isEmpty($field)) {
            return $this;
        }

        $value  = (string) $this->value($field);
        $digits = (string) preg_replace('/[^0-9+]/', '', $value);

        $isMobile   = preg_match('/^(\+?20)?1[0125][0-9]{8}$/', $digits) === 1;
        $isLandline = preg_match('/^\+?[0-9]{8,15}$/', $digits) === 1;

        if (!$isMobile && !$isLandline) {
            $this->fail($field, __('validation.phone', ['field' => $this->label($field)]));
        }

        return $this;
    }

    public function url(string $field): self
    {
        if ($this->isEmpty($field)) {
            return $this;
        }

        $value = (string) $this->value($field);

        // يُقبل http/https فقط — javascript: و data: نواقل XSS.
        if (
            filter_var($value, FILTER_VALIDATE_URL) === false
            || preg_match('#^https?://#i', $value) !== 1
        ) {
            $this->fail($field, __('validation.url', ['field' => $this->label($field)]));
        }

        return $this;
    }

    public function matches(string $field, string $otherField): self
    {
        if ($this->value($field) !== $this->value($otherField)) {
            $this->fail($field, __('validation.confirmed', ['field' => $this->label($field)]));
        }

        return $this;
    }

    public function accepted(string $field): self
    {
        if (!in_array($this->value($field), ['1', 1, true, 'on', 'yes', 'true'], true)) {
            $this->fail($field, __('validation.accepted', ['field' => $this->label($field)]));
        }

        return $this;
    }

    /**
     * قوة كلمة المرور | Password strength (§9).
     * الطول والتنوّع من الإعدادات، مع قائمة منع لكلمات المرور الشائعة
     * وكلمات العرض التجريبي حتى لا تصل إلى الإنتاج.
     */
    public function password(string $field): self
    {
        if ($this->isEmpty($field)) {
            return $this;
        }

        $value  = (string) $this->value($field);
        $config = Config::get('security.password', []);
        $min    = (int) ($config['min_length'] ?? 10);

        if (mb_strlen($value) < $min) {
            $this->fail($field, __('validation.password_min', ['min' => $min]));

            return $this;
        }

        if (($config['require_mixed_case'] ?? true) === true) {
            if (preg_match('/[a-z]/', $value) !== 1 || preg_match('/[A-Z]/', $value) !== 1) {
                $this->fail($field, __('validation.password_mixed_case'));

                return $this;
            }
        }

        if (($config['require_number'] ?? true) === true && preg_match('/[0-9]/', $value) !== 1) {
            $this->fail($field, __('validation.password_number'));

            return $this;
        }

        if (($config['require_symbol'] ?? false) === true && preg_match('/[^A-Za-z0-9]/', $value) !== 1) {
            $this->fail($field, __('validation.password_symbol'));

            return $this;
        }

        foreach ((array) ($config['blocklist'] ?? []) as $blocked) {
            if (strcasecmp($value, (string) $blocked) === 0) {
                $this->fail($field, __('validation.password_blocked'));

                return $this;
            }
        }

        return $this;
    }

    /** تحقق مخصّص | Custom rule. */
    public function custom(string $field, callable $check, string $message): self
    {
        if (!$this->isEmpty($field) && $check($this->value($field)) !== true) {
            $this->fail($field, $message);
        }

        return $this;
    }

    /** إضافة خطأ يدوياً (مثلاً بعد فحص في قاعدة البيانات). */
    public function addError(string $field, string $message): self
    {
        $this->fail($field, $message);

        return $this;
    }

    // ---------------- النتائج | Results ----------------

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors === [] ? null : reset($this->errors);
    }

    /**
     * التحقق أو رمي استثناء | Validate or throw.
     *
     * @return array<string,mixed> البيانات المطلوبة فقط
     */
    public function validate(array $only = []): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }

        if ($only === []) {
            return $this->data;
        }

        $result = [];
        foreach ($only as $field) {
            $result[$field] = $this->value($field);
        }

        return $result;
    }
}
