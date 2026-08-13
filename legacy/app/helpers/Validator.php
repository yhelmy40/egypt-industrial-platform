<?php
/**
 * Validator.php
 * مُحقّق بسيط للمدخلات | Simple input validator.
 *
 * مثال | Example:
 *   $v = new Validator($_POST);
 *   $v->required('title', 'العنوان')->email('email', 'البريد');
 *   if ($v->fails()) { ... $v->errors() ... }
 */
class Validator
{
    private array $data;
    private array $errors = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    private function val(string $field): string
    {
        return trim((string) ($this->data[$field] ?? ''));
    }

    public function required(string $field, string $label): self
    {
        if ($this->val($field) === '') {
            $this->errors[$field] = "حقل «{$label}» مطلوب.";
        }
        return $this;
    }

    public function email(string $field, string $label): self
    {
        $v = $this->val($field);
        if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = "حقل «{$label}» يجب أن يكون بريداً إلكترونياً صحيحاً.";
        }
        return $this;
    }

    public function min(string $field, string $label, int $len): self
    {
        $v = $this->val($field);
        if ($v !== '' && mb_strlen($v) < $len) {
            $this->errors[$field] = "حقل «{$label}» يجب ألا يقل عن {$len} أحرف.";
        }
        return $this;
    }

    public function numeric(string $field, string $label): self
    {
        $v = $this->val($field);
        if ($v !== '' && !is_numeric($v)) {
            $this->errors[$field] = "حقل «{$label}» يجب أن يكون رقماً.";
        }
        return $this;
    }

    public function in(string $field, string $label, array $allowed): self
    {
        $v = $this->val($field);
        if ($v !== '' && !in_array($v, $allowed, true)) {
            $this->errors[$field] = "قيمة «{$label}» غير صالحة.";
        }
        return $this;
    }

    public function date(string $field, string $label): self
    {
        $v = $this->val($field);
        if ($v !== '' && strtotime($v) === false) {
            $this->errors[$field] = "حقل «{$label}» يجب أن يكون تاريخاً صحيحاً.";
        }
        return $this;
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    /** أول رسالة خطأ مجمّعة | First combined error message */
    public function firstError(): string
    {
        return $this->errors ? reset($this->errors) : '';
    }

    /** كل الرسائل كنص واحد | All messages as one string */
    public function allErrors(): string
    {
        return implode(' ', $this->errors);
    }
}
