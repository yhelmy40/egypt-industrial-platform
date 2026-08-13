<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * واجهة إرسال البريد | Mail transport contract (§12).
 *
 * نقطة التوسعة: يمكن إضافة محوّل SMTP لاحقاً دون تعديل منطق الأعمال.
 * في هذه النسخة يُستخدم محوّل السجل لأن بيانات اعتماد المزوّد غير متوفرة —
 * ولا تُحاكى عملية إرسال ناجحة زوراً.
 * Extension point: an SMTP adapter can be added later without touching
 * business logic. The MVP uses the log adapter because no provider
 * credentials exist — a successful send is never falsely simulated.
 */
interface MailerInterface
{
    /**
     * @param  array<string,string> $context بيانات إضافية للسجل
     * @return bool هل قُبل الطلب للإرسال؟ (ليس تأكيداً بالتسليم)
     */
    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody = '',
        array $context = [],
    ): bool;

    /** اسم المحوّل النشط | Active driver name, for diagnostics. */
    public function driver(): string;
}
