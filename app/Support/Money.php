<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * قيمة نقدية | Money value object (§8, §16).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * تُحفظ القيمة داخلياً بالقروش (عدد صحيح) لا بالجنيهات العشرية. السبب أن
 * الحساب بالأعداد العشرية العائمة يُنتج فروقاً تظهر في مجاميع الطلبات
 * والفواتير: 0.1 + 0.2 لا تساوي 0.3 في الحساب العائم، ومع مئات الأصناف
 * تتراكم القروش الضائعة حتى يشتكي صاحب المشروع من مجموع لا يطابق أصنافه.
 *
 * Stored internally as integer piastres, not decimal pounds. Floating-point
 * arithmetic produces drift that surfaces in order and invoice totals — a
 * total that does not equal the sum of its lines is the fastest way to lose an
 * SME's trust in the platform.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class Money
{
    private const SCALE = 100; // قرش | piastres per pound

    private function __construct(
        private readonly int $minorUnits,
        private readonly string $currency,
    ) {
    }

    /** من قيمة عشرية (من قاعدة البيانات أو النموذج) | From a decimal amount. */
    public static function fromDecimal(float|int|string $amount, string $currency = 'EGP'): self
    {
        if (!is_numeric($amount)) {
            throw new InvalidArgumentException('قيمة نقدية غير صالحة.');
        }

        // التقريب نصف-لأعلى عند حدّ القرش | Round half up at the piastre
        return new self((int) round(((float) $amount) * self::SCALE), $currency);
    }

    public static function fromMinor(int $minorUnits, string $currency = 'EGP'): self
    {
        return new self($minorUnits, $currency);
    }

    public static function zero(string $currency = 'EGP'): self
    {
        return new self(0, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    /**
     * الضرب في كمية | Multiply by a quantity.
     * الكميات قد تكون كسرية (2.5 كيلوجرام)، والتقريب يقع مرة واحدة على الناتج.
     */
    public function times(float|int|string $quantity): self
    {
        return new self((int) round($this->minorUnits * (float) $quantity), $this->currency);
    }

    /** نسبة مئوية (لضريبة القيمة المضافة) | A percentage of this amount. */
    public function percentage(float|int|string $rate): self
    {
        return new self((int) round($this->minorUnits * ((float) $rate) / 100), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minorUnits === $other->minorUnits;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits > $other->minorUnits;
    }

    public function minorUnits(): int
    {
        return $this->minorUnits;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /** للتخزين في عمود DECIMAL | For a DECIMAL column. */
    public function toDecimal(): string
    {
        $sign  = $this->minorUnits < 0 ? '-' : '';
        $value = abs($this->minorUnits);

        return $sign . intdiv($value, self::SCALE) . '.' . str_pad((string) ($value % self::SCALE), 2, '0', STR_PAD_LEFT);
    }

    public function toFloat(): float
    {
        return $this->minorUnits / self::SCALE;
    }

    /** نص معروض للمستخدم | Display string. */
    public function format(): string
    {
        return money($this->toFloat(), $this->currency);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                'لا يمكن الجمع بين عملتين مختلفتين: ' . $this->currency . ' و' . $other->currency,
            );
        }
    }
}
