<?php

declare(strict_types=1);

namespace App\Adapters\Mail;

use App\Contracts\MailerInterface;
use App\Core\Config;
use App\Core\Logger;

/**
 * محوّل بريد يكتب في السجل | Log-only mail adapter.
 *
 * لا يرسل بريداً فعلياً. الغرض منه أن تعمل المسارات (تحقق، استعادة كلمة مرور،
 * إشعارات) بشكل كامل وقابل للاختبار قبل توفّر بيانات مزوّد البريد، دون ادّعاء
 * أن الرسالة سُلِّمت.
 * Sends nothing. It lets verification, password-reset and notification flows
 * work end-to-end and be tested before mail credentials exist — without
 * claiming a message was delivered.
 */
final class LogMailer implements MailerInterface
{
    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody = '',
        array $context = [],
    ): bool {
        Logger::info('Outbound email (log driver — not delivered)', [
            'to'      => $toEmail,
            'to_name' => $toName,
            'subject' => $subject,
            'context' => $context,
        ]);

        // في التطوير نكتب الرسالة كاملة في ملف منفصل ليتمكن المطوّر من فتح
        // روابط التحقق والاستعادة. لا يُفعَّل هذا في الإنتاج.
        // In development the full message is written to a separate file so the
        // developer can follow verification/reset links. Never in production.
        if (Config::get('app.env') !== 'production') {
            $this->writeToOutbox($toEmail, $subject, $htmlBody, $textBody);
        }

        return true;
    }

    private function writeToOutbox(string $to, string $subject, string $html, string $text): void
    {
        $dir = rtrim((string) Config::get('app.storage_path'), '/') . '/logs';

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $entry = str_repeat('=', 70) . "\n"
            . 'DATE:    ' . date('Y-m-d H:i:s') . "\n"
            . 'TO:      ' . $to . "\n"
            . 'SUBJECT: ' . $subject . "\n"
            . str_repeat('-', 70) . "\n"
            . ($text !== '' ? $text : strip_tags($html)) . "\n\n";

        @file_put_contents($dir . '/mail-outbox.log', $entry, FILE_APPEND | LOCK_EX);
    }

    public function driver(): string
    {
        return 'log';
    }
}
