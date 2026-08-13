<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Session;
use Throwable;

/**
 * سجل التدقيق | Audit logging service (§9).
 *
 * يسجّل الأحداث المهمة: الدخول والفشل، تغييرات الأدوار والصلاحيات، قرارات
 * التوثيق، تغييرات حالات الطلبات والتمويل، السجلات المالية، تنزيل الوثائق،
 * التصدير، تغييرات الإعدادات، والحذف والاسترجاع.
 *
 * مبدأ التصميم: فشل التدقيق لا يُفشل عملية المستخدم، لكنه يُسجَّل كخطأ حرج،
 * لأن فقدان أثر التدقيق مشكلة تشغيلية يجب أن تُرى.
 * Design principle: an audit failure never breaks the user's operation, but it
 * is logged as critical — a missing audit trail is an operational problem that
 * must be visible.
 */
final class AuditLogger
{
    // فئات الأحداث | Event categories
    public const CATEGORY_AUTH         = 'auth';
    public const CATEGORY_RBAC         = 'rbac';
    public const CATEGORY_VERIFICATION = 'verification';
    public const CATEGORY_ORDER        = 'order';
    public const CATEGORY_APPLICATION  = 'application';
    public const CATEGORY_FINANCE      = 'finance';
    public const CATEGORY_DOCUMENT     = 'document';
    public const CATEGORY_EXPORT       = 'export';
    public const CATEGORY_CONFIG       = 'config';
    public const CATEGORY_RECORD       = 'record';
    public const CATEGORY_SECURITY     = 'security';

    /** الحقول التي لا تُكتب في السجل مطلقاً | Never-audited fields. */
    private const SENSITIVE_FIELDS = [
        'password', 'password_hash', 'password_confirmation', 'token', 'token_hash',
        'remember_token', 'api_key', 'secret', 'national_id', 'iban',
        'account_number', 'card_number', 'cvv', 'otp', 'pin',
    ];

    private ?Request $request = null;

    public function setRequest(?Request $request): void
    {
        $this->request = $request;
    }

    /**
     * تسجيل حدث | Record an audit event.
     *
     * @param array{before?:array,after?:array}|null $changes
     */
    public function log(
        string $action,
        string $category,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $changes = null,
        ?string $description = null,
        string $severity = 'info',
        ?int $userId = null,
        ?int $organizationId = null,
    ): void {
        try {
            Database::statement(
                'INSERT INTO audit_logs
                    (user_id, organization_id, action, category, severity,
                     entity_type, entity_id, changes, description,
                     ip_address, user_agent, route, method)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId ?? $this->currentUserId(),
                    $organizationId ?? $this->currentOrganizationId(),
                    $action,
                    $category,
                    $severity,
                    $entityType,
                    $entityId,
                    $changes === null ? null : $this->encodeChanges($changes),
                    $description === null ? null : mb_substr($description, 0, 500),
                    $this->packedIp(),
                    $this->request?->userAgent(),
                    $this->request?->path(),
                    $this->request?->method(),
                ],
            );
        } catch (Throwable $e) {
            // لا نُفشل عملية المستخدم بسبب فشل التدقيق، لكن نُبلّغ بوضوح.
            Logger::critical('Audit log write failed', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    // ---------------- اختصارات الأحداث الشائعة | Convenience wrappers ----------------

    public function logLogin(int $userId, string $email): void
    {
        $this->log(
            action: 'auth.login',
            category: self::CATEGORY_AUTH,
            entityType: 'user',
            entityId: $userId,
            description: 'تسجيل دخول ناجح',
            userId: $userId,
        );
    }

    public function logFailedLogin(string $email, string $reason): void
    {
        $this->log(
            action: 'auth.login_failed',
            category: self::CATEGORY_AUTH,
            entityType: 'user',
            // البريد المُدخل يُسجَّل مخفياً جزئياً — قد يكون بريد شخص آخر
            // The attempted email is masked; it may belong to someone else.
            description: 'محاولة دخول فاشلة (' . $reason . ') للبريد ' . mask_email($email),
            severity: 'warning',
        );
    }

    public function logLogout(int $userId): void
    {
        $this->log(
            action: 'auth.logout',
            category: self::CATEGORY_AUTH,
            entityType: 'user',
            entityId: $userId,
            description: 'تسجيل خروج',
            userId: $userId,
        );
    }

    public function logRegistration(int $userId, string $accountType): void
    {
        $this->log(
            action: 'auth.register',
            category: self::CATEGORY_AUTH,
            entityType: 'user',
            entityId: $userId,
            description: 'إنشاء حساب جديد من نوع: ' . $accountType,
            userId: $userId,
        );
    }

    public function logPasswordReset(int $userId, string $stage): void
    {
        $this->log(
            action: 'auth.password_reset.' . $stage,
            category: self::CATEGORY_AUTH,
            entityType: 'user',
            entityId: $userId,
            description: $stage === 'requested'
                ? 'طلب استعادة كلمة المرور'
                : 'تم تغيير كلمة المرور عبر الاستعادة',
            severity: 'notice',
            userId: $userId,
        );
    }

    public function logRoleChange(int $targetUserId, array $before, array $after, ?int $organizationId = null): void
    {
        $this->log(
            action: 'rbac.role_changed',
            category: self::CATEGORY_RBAC,
            entityType: 'user',
            entityId: $targetUserId,
            changes: ['before' => $before, 'after' => $after],
            description: 'تعديل أدوار أو صلاحيات المستخدم',
            severity: 'notice',
            organizationId: $organizationId,
        );
    }

    public function logAuthorizationDenied(Request $request, ?string $permission, ?string $resource): void
    {
        $this->setRequest($request);

        $this->log(
            action: 'security.authorization_denied',
            category: self::CATEGORY_SECURITY,
            entityType: $resource,
            description: 'محاولة وصول غير مصرّح بها'
                . ($permission !== null ? ' — الصلاحية المطلوبة: ' . $permission : ''),
            severity: 'warning',
        );
    }

    public function logStatusChange(
        string $entityType,
        int $entityId,
        string $from,
        string $to,
        string $category,
        ?int $organizationId = null,
    ): void {
        $this->log(
            action: $entityType . '.status_changed',
            category: $category,
            entityType: $entityType,
            entityId: $entityId,
            changes: ['before' => ['status' => $from], 'after' => ['status' => $to]],
            description: 'تغيير الحالة من ' . $from . ' إلى ' . $to,
            severity: 'notice',
            organizationId: $organizationId,
        );
    }

    public function logDocumentDownload(int $mediaId, string $filename, ?int $organizationId = null): void
    {
        $this->log(
            action: 'document.downloaded',
            category: self::CATEGORY_DOCUMENT,
            entityType: 'media',
            entityId: $mediaId,
            description: 'تنزيل وثيقة: ' . $filename,
            organizationId: $organizationId,
        );
    }

    public function logExport(string $reportType, int $rowCount, ?int $organizationId = null): void
    {
        $this->log(
            action: 'export.generated',
            category: self::CATEGORY_EXPORT,
            entityType: 'report',
            description: 'تصدير تقرير: ' . $reportType . ' (' . $rowCount . ' سجل)',
            severity: 'notice',
            organizationId: $organizationId,
        );
    }

    public function logConfigChange(string $settingKey, mixed $before, mixed $after): void
    {
        $this->log(
            action: 'config.changed',
            category: self::CATEGORY_CONFIG,
            entityType: 'system_setting',
            changes: ['before' => [$settingKey => $before], 'after' => [$settingKey => $after]],
            description: 'تعديل إعداد النظام: ' . $settingKey,
            severity: 'notice',
        );
    }

    public function logRecordDeleted(string $entityType, int $entityId, ?int $organizationId = null): void
    {
        $this->log(
            action: $entityType . '.deleted',
            category: self::CATEGORY_RECORD,
            entityType: $entityType,
            entityId: $entityId,
            description: 'حذف سجل',
            severity: 'notice',
            organizationId: $organizationId,
        );
    }

    public function logRecordRestored(string $entityType, int $entityId, ?int $organizationId = null): void
    {
        $this->log(
            action: $entityType . '.restored',
            category: self::CATEGORY_RECORD,
            entityType: $entityType,
            entityId: $entityId,
            description: 'استرجاع سجل محذوف',
            severity: 'notice',
            organizationId: $organizationId,
        );
    }

    // ---------------- أدوات داخلية | Internals ----------------

    /**
     * تنقيح وترميز التغييرات | Redact and encode the change payload.
     */
    private function encodeChanges(array $changes): string
    {
        $clean = [];

        foreach (['before', 'after'] as $side) {
            if (!isset($changes[$side]) || !is_array($changes[$side])) {
                continue;
            }

            $clean[$side] = $this->redact($changes[$side]);
        }

        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{}' : mb_substr($json, 0, 8000);
    }

    private function redact(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);

            foreach (self::SENSITIVE_FIELDS as $sensitive) {
                if (str_contains($lower, $sensitive)) {
                    $out[$key] = '[REDACTED]';
                    continue 2;
                }
            }

            if (is_array($value)) {
                $out[$key] = $this->redact($value);
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = is_string($value) ? mb_substr($value, 0, 300) : $value;
            } else {
                $out[$key] = '[object]';
            }
        }

        return $out;
    }

    private function currentUserId(): ?int
    {
        $id = Session::get('user_id');

        return is_int($id) ? $id : null;
    }

    private function currentOrganizationId(): ?int
    {
        $id = Session::get('active_organization_id');

        return is_int($id) ? $id : null;
    }

    /** ضغط عنوان IP لتخزينه في VARBINARY(16) | Pack an IP for VARBINARY(16). */
    private function packedIp(): ?string
    {
        $ip = $this->request?->ip();

        if ($ip === null || $ip === '') {
            return null;
        }

        $packed = @inet_pton($ip);

        return $packed === false ? null : $packed;
    }
}
