<?php

declare(strict_types=1);

namespace App\Adapters\Sms;

use App\Contracts\SmsSenderInterface;
use App\Core\Logger;

/**
 * محوّل رسائل نصية يكتب في السجل | Log-only SMS adapter.
 *
 * لا يرسل رسائل فعلية — تكامل مزوّد SMS مؤجّل حتى تتوفر بيانات الاعتماد.
 */
final class LogSmsSender implements SmsSenderInterface
{
    public function send(string $toPhone, string $message, array $context = []): bool
    {
        Logger::info('Outbound SMS (log driver — not delivered)', [
            // الرقم يُخفى جزئياً في السجل | Number partially masked in logs
            'to'      => mask_phone($toPhone),
            'length'  => mb_strlen($message),
            'context' => $context,
        ]);

        return true;
    }

    public function driver(): string
    {
        return 'log';
    }
}
