<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * واجهة إرسال الرسائل النصية | SMS transport contract (§4.11, §12).
 *
 * التكامل مع مزوّد SMS مؤجّل؛ الواجهة موجودة حتى يُضاف المحوّل عبر الإعدادات
 * دون تعديل أي منطق أعمال.
 */
interface SmsSenderInterface
{
    /** @return bool هل قُبل الطلب للإرسال؟ */
    public function send(string $toPhone, string $message, array $context = []): bool;

    public function driver(): string;
}
