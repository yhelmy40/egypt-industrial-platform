<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * تحديد المعدّل | Rate limiting (§9).
 *
 * نافذة منزلقة مخزّنة في قاعدة البيانات — بلا Redis، اتساقاً مع قرار تقليل
 * الاعتماديات. الدقة كافية لحماية نقاط الدخول والتسجيل والتواصل والطلبات.
 * A database-backed sliding window — no Redis, consistent with the
 * minimal-dependency decision. Accurate enough to protect login, registration,
 * contact and application endpoints.
 */
final class RateLimiter
{
    /**
     * هل تجاوز الطلب الحد؟ | Has this signature exceeded the limit?
     *
     * @param string $bucket    اسم القاعدة من config/security.php
     * @param string $signature عنوان IP أو IP+معرّف
     */
    public function tooManyAttempts(string $bucket, string $signature): bool
    {
        if (Config::get('security.rate_limit.enabled', true) !== true) {
            return false;
        }

        $rule = $this->rule($bucket);

        return $this->attempts($bucket, $signature, $rule['minutes']) >= $rule['attempts'];
    }

    /** تسجيل محاولة | Record one attempt. */
    public function hit(string $bucket, string $signature): void
    {
        if (Config::get('security.rate_limit.enabled', true) !== true) {
            return;
        }

        Database::statement(
            'INSERT INTO rate_limits (bucket, signature) VALUES (?, ?)',
            [mb_substr($bucket, 0, 60), mb_substr($signature, 0, 190)],
        );

        // تنظيف انتهازي خفيف بدل مهمة مجدولة منفصلة
        // Opportunistic cleanup instead of a dedicated cron job.
        if (random_int(1, 100) === 1) {
            $this->prune();
        }
    }

    public function attempts(string $bucket, string $signature, ?int $minutes = null): int
    {
        $minutes = $minutes ?? $this->rule($bucket)['minutes'];

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM rate_limits
              WHERE bucket = ? AND signature = ?
                AND hit_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$bucket, $signature, $minutes],
        );
    }

    /** المحاولات المتبقية | Remaining attempts before the limit. */
    public function remaining(string $bucket, string $signature): int
    {
        $rule = $this->rule($bucket);

        return max(0, $rule['attempts'] - $this->attempts($bucket, $signature, $rule['minutes']));
    }

    /** ثواني الانتظار المتبقية | Seconds until the window frees up. */
    public function availableIn(string $bucket, string $signature): int
    {
        $rule = $this->rule($bucket);

        $oldest = Database::scalar(
            'SELECT MIN(hit_at) FROM rate_limits
              WHERE bucket = ? AND signature = ?
                AND hit_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$bucket, $signature, $rule['minutes']],
        );

        if ($oldest === null) {
            return 0;
        }

        $expiresAt = strtotime((string) $oldest) + ($rule['minutes'] * 60);

        return max(0, $expiresAt - time());
    }

    /** مسح المحاولات بعد نجاح العملية | Clear after a successful action. */
    public function clear(string $bucket, string $signature): void
    {
        Database::statement(
            'DELETE FROM rate_limits WHERE bucket = ? AND signature = ?',
            [$bucket, $signature],
        );
    }

    /** حذف السجلات المنتهية | Remove expired rows. */
    public function prune(int $olderThanHours = 24): int
    {
        return Database::affectingStatement(
            'DELETE FROM rate_limits WHERE hit_at < DATE_SUB(NOW(), INTERVAL ? HOUR)',
            [$olderThanHours],
        );
    }

    /** @return array{attempts:int,minutes:int} */
    private function rule(string $bucket): array
    {
        $rules = Config::get('security.rate_limit.rules', []);
        $rule  = $rules[$bucket] ?? ['attempts' => 30, 'minutes' => 10];

        return [
            'attempts' => (int) ($rule['attempts'] ?? 30),
            'minutes'  => (int) ($rule['minutes'] ?? 10),
        ];
    }

    /** رسالة عربية واضحة للمستخدم | Human-readable Arabic wait message. */
    public function waitMessage(int $seconds): string
    {
        if ($seconds < 60) {
            return 'يرجى الانتظار ' . $seconds . ' ثانية قبل إعادة المحاولة.';
        }

        return 'يرجى الانتظار ' . (int) ceil($seconds / 60) . ' دقيقة قبل إعادة المحاولة.';
    }
}
