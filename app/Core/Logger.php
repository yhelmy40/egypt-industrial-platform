<?php

declare(strict_types=1);

namespace App\Core;

/**
 * مُسجّل الأحداث | File logger with mandatory redaction.
 *
 * متطلّب أمني (§9): لا تُسجَّل كلمات المرور ولا رموز الاستعادة ولا معرّفات
 * الجلسات ولا البيانات البنكية. التنقيح يتم هنا مركزياً حتى لا يعتمد على
 * انضباط كل مُستدعٍ على حدة.
 * Security requirement: passwords, reset tokens, session identifiers and bank
 * data are never written. Redaction happens centrally rather than relying on
 * every caller remembering.
 */
final class Logger
{
    /** الحقول التي تُنقَّح دائماً | Always-redacted keys (substring match). */
    private const REDACTED_KEYS = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'token', 'csrf_token', 'remember_token', 'reset_token', 'api_key', 'apikey',
        'secret', 'authorization', 'session', 'session_id', 'cookie',
        'iban', 'account_number', 'bank_account', 'card', 'cvv', 'national_id',
        'pin', 'otp', 'private_key',
    ];

    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];

    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::log('critical', $message, $context);
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        if (!in_array($level, self::LEVELS, true)) {
            $level = 'info';
        }

        // في وضع التطوير نسجّل كل شيء؛ في الإنتاج نتجاهل debug
        if ($level === 'debug' && Config::get('app.env') === 'production') {
            return;
        }

        $entry = sprintf(
            "[%s] %s: %s %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context === [] ? '' : self::encode(self::redact($context)),
        );

        $path = self::path();
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        @file_put_contents($path, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * تنقيح البيانات الحسّاسة | Redact sensitive values recursively.
     */
    public static function redact(array $context): array
    {
        $out = [];

        foreach ($context as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isSecret = false;

            foreach (self::REDACTED_KEYS as $needle) {
                if (str_contains($lowerKey, $needle)) {
                    $isSecret = true;
                    break;
                }
            }

            if ($isSecret) {
                $out[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $out[$key] = self::redact($value);
                continue;
            }

            if (is_object($value)) {
                $out[$key] = '[object ' . $value::class . ']';
                continue;
            }

            // قصّ القيم الطويلة لتفادي تضخّم السجل
            if (is_string($value) && mb_strlen($value) > 500) {
                $out[$key] = mb_substr($value, 0, 500) . '…';
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    private static function encode(array $context): string
    {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{"log_encode_error":true}' : $json;
    }

    private static function path(): string
    {
        $base = Config::get('app.storage_path', dirname(__DIR__, 2) . '/storage');

        return rtrim((string) $base, '/') . '/logs/app-' . date('Y-m-d') . '.log';
    }
}
