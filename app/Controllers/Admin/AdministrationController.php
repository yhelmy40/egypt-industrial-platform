<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditLogger;
use App\Services\SettingsService;

/**
 * ضبط المنصة | Platform administration (§4.13, §16).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ثلاث شاشات يجمعها أنها **تتعامل مع النظام نفسه** لا مع محتواه:
 * المستخدمون، والإعدادات، وسجلّ التدقيق.
 *
 * قواعد تحكمها:
 *  1. **سجلّ التدقيق للقراءة فقط.** لا تعديل ولا حذف من أي شاشة: سجلّ يمكن
 *     تحريره لا يصلح دليلاً على شيء.
 *  2. **إيقاف الحساب يستوجب سبباً**، ولا يحذف الحساب: الحذف يُفقد نسبة
 *     السجلّات لصاحبها.
 *  3. **لا يوقف المسؤول حسابه**: قفل النفس خارج النظام يترك المنصة بلا مدير.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class AdministrationController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    // ═══════════════════ المستخدمون | Users ═══════════════════

    public function users(Request $request): Response
    {
        $filters = [
            'q'      => (string) ($request->input('q') ?? ''),
            'status' => (string) ($request->input('status') ?? ''),
            'role'   => (string) ($request->input('role') ?? ''),
        ];

        $where    = ['u.deleted_at IS NULL'];
        $bindings = [];

        if ($filters['q'] !== '') {
            $where[]    = '(u.name LIKE ? OR u.email LIKE ?)';
            $bindings[] = '%' . $filters['q'] . '%';
            $bindings[] = '%' . $filters['q'] . '%';
        }

        if ($filters['status'] !== '') {
            $where[]    = 'u.status = ?';
            $bindings[] = $filters['status'];
        }

        if ($filters['role'] !== '') {
            $where[]    = 'EXISTS (SELECT 1 FROM user_roles ur
                                     JOIN roles r ON r.id = ur.role_id
                                    WHERE ur.user_id = u.id AND r.code = ?)';
            $bindings[] = $filters['role'];
        }

        $clause  = implode(' AND ', $where);
        $page    = $this->page($request);
        $perPage = 25;
        $offset  = ($page - 1) * $perPage;

        $total = (int) Database::scalar("SELECT COUNT(*) FROM users u WHERE {$clause}", $bindings);

        // البريد يظهر لمن يدير المستخدمين وحده، ولا يُصدَّر في أي تقرير (§10)
        $users = Database::select(
            "SELECT u.id, u.name, u.email, u.status, u.last_login_at, u.created_at,
                    u.must_change_password, u.suspended_reason,
                    (SELECT GROUP_CONCAT(r.name_ar SEPARATOR '، ')
                       FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                      WHERE ur.user_id = u.id) AS platform_roles,
                    (SELECT COUNT(*) FROM organization_members m
                      WHERE m.user_id = u.id AND m.status = 'active') AS memberships
               FROM users u
              WHERE {$clause}
           ORDER BY u.created_at DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $bindings,
        );

        return $this->view('admin/administration/users', [
            'pageTitle' => 'المستخدمون',
            'users'     => $users,
            'filters'   => $filters,
            'roles'     => Database::select(
                "SELECT code, name_ar FROM roles WHERE scope = 'platform' ORDER BY name_ar",
            ),
            'pagination' => [
                'page'      => $page,
                'total'     => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ], 'admin');
    }

    /**
     * إيقاف حساب | Suspend an account.
     *
     * لا حذف: الحذف يُفقد سجلّات التدقيق نسبتها لصاحبها. والإيقاف قابل للرفع.
     */
    public function suspendUser(Request $request): Response
    {
        $userId = $request->routeInt('id');
        $reason = trim((string) ($request->input('reason') ?? ''));

        if ($userId === null) {
            throw new HttpException(404, 'المستخدم غير موجود.');
        }

        if ($userId === $this->currentUserId()) {
            $this->flash('warning', 'لا يمكنك إيقاف حسابك أنت.');

            return $this->redirect('/admin/users');
        }

        if (mb_strlen($reason) < 5) {
            $this->flash('warning', 'اكتب سبب الإيقاف. إيقاف بلا سبب لا يمكن التظلّم منه.');

            return $this->redirect('/admin/users');
        }

        $user = Database::selectOne(
            'SELECT id, status FROM users WHERE id = ? AND deleted_at IS NULL',
            [$userId],
        );

        if ($user === null) {
            throw new HttpException(404, 'المستخدم غير موجود.');
        }

        Database::statement(
            "UPDATE users SET status = 'suspended', suspended_at = NOW(), suspended_reason = ?
              WHERE id = ?",
            [mb_substr($reason, 0, 500), $userId],
        );

        $this->audit->setRequest($request);
        $this->audit->logStatusChange(
            'user',
            $userId,
            (string) $user['status'],
            'suspended',
            AuditLogger::CATEGORY_SECURITY,
        );

        $this->flash('success', 'أُوقف الحساب.');

        return $this->redirect('/admin/users');
    }

    public function restoreUser(Request $request): Response
    {
        $userId = $request->routeInt('id');

        if ($userId === null) {
            throw new HttpException(404, 'المستخدم غير موجود.');
        }

        $affected = Database::affectingStatement(
            "UPDATE users SET status = 'active', suspended_at = NULL, suspended_reason = NULL,
                              failed_login_count = 0, locked_until = NULL
              WHERE id = ? AND status = 'suspended' AND deleted_at IS NULL",
            [$userId],
        );

        if ($affected === 0) {
            $this->flash('warning', 'الحساب غير موقوف.');
        } else {
            $this->audit->setRequest($request);
            $this->audit->logStatusChange(
                'user',
                $userId,
                'suspended',
                'active',
                AuditLogger::CATEGORY_SECURITY,
            );
            $this->flash('success', 'أُعيد تفعيل الحساب.');
        }

        return $this->redirect('/admin/users');
    }

    // ═══════════════════ الأدوار | Roles ═══════════════════

    /**
     * مصفوفة الأدوار | The role matrix — read only.
     *
     * الشاشة **للاطّلاع والمراجعة لا للتحرير**: الأدوار والصلاحيات تُعرَّف في
     * بذرة مرجعية تحت مراجعة الكود، لا من واجهة. تحرير الصلاحيات من الويب
     * يجعل تصعيد الامتياز خطوةً واحدة لمن يخترق حساب مدير.
     */
    public function roles(Request $request): Response
    {
        $roles = Database::select(
            'SELECT r.id, r.code, r.name_ar, r.scope, r.organization_type_code, r.description_ar,
                    COUNT(DISTINCT rp.permission_id) AS permission_count,
                    (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS platform_holders,
                    (SELECT COUNT(*) FROM organization_members m WHERE m.role_id = r.id) AS org_holders
               FROM roles r
          LEFT JOIN role_permissions rp ON rp.role_id = r.id
           GROUP BY r.id
           ORDER BY FIELD(r.scope, \'platform\', \'organization\'), r.id',
        );

        $permissions = Database::select(
            'SELECT p.id, p.code, p.name_ar, p.module, p.is_sensitive
               FROM permissions p
           ORDER BY p.module, p.code',
        );

        $matrix = [];

        foreach (Database::select('SELECT role_id, permission_id FROM role_permissions') as $row) {
            $matrix[(int) $row['role_id']][(int) $row['permission_id']] = true;
        }

        return $this->view('admin/administration/roles', [
            'pageTitle'   => 'الأدوار والصلاحيات',
            'roles'       => $roles,
            'permissions' => $permissions,
            'matrix'      => $matrix,
        ], 'admin');
    }

    // ═══════════════════ الإعدادات | Settings ═══════════════════

    public function settings(Request $request): Response
    {
        return $this->view('admin/administration/settings', [
            'pageTitle' => 'إعدادات المنصة',
            'grouped'   => SettingsService::grouped(),
        ], 'admin');
    }

    public function updateSettings(Request $request): Response
    {
        $submitted = $request->input('settings');

        if (!is_array($submitted)) {
            $this->flash('warning', 'لا توجد إعدادات للحفظ.');

            return $this->redirect('/admin/settings');
        }

        $changed = 0;

        // الإعدادات المعروفة فقط تُحدَّث: مفتاح جديد يأتي من الطلب يُتجاهل،
        // فلا يصير النموذج بوابة لكتابة أي صفّ في جدول الإعدادات.
        foreach (SettingsService::grouped() as $group => $settings) {
            foreach ($settings as $setting) {
                $key = (string) $setting['setting_key'];

                if (!isset($submitted[$group][$key])) {
                    continue;
                }

                $value = (string) $submitted[$group][$key];

                if ($value === (string) $setting['value']) {
                    continue;
                }

                SettingsService::set($group, $key, $value, $this->currentUserId());
                $changed++;
            }
        }

        if ($changed > 0) {
            $this->audit->setRequest($request);
            $this->audit->log(
                action: 'settings.updated',
                category: AuditLogger::CATEGORY_CONFIG,
                entityType: 'system_setting',
                description: "تحديث {$changed} إعداداً",
                severity: 'notice',
            );
        }

        $this->flash('success', $changed === 0 ? 'لا تغييرات.' : "حُفظ {$changed} إعداداً.");

        return $this->redirect('/admin/settings');
    }

    // ═══════════════════ سجلّ التدقيق | The audit log ═══════════════════

    /**
     * سجلّ التدقيق | The audit trail — read only, always.
     *
     * لا مسار في المنصة يعدّل صفّاً في `audit_logs` أو يحذفه. سجلّ يمكن
     * تحريره لا يصلح دليلاً على شيء، وهذا هو الغرض الوحيد من وجوده.
     */
    public function audit(Request $request): Response
    {
        $filters = [
            'category' => (string) ($request->input('category') ?? ''),
            'action'   => (string) ($request->input('action') ?? ''),
            'severity' => (string) ($request->input('severity') ?? ''),
            'user_id'  => (string) ($request->input('user_id') ?? ''),
            'from'     => (string) ($request->input('from') ?? ''),
            'to'       => (string) ($request->input('to') ?? ''),
        ];

        $where    = ['1=1'];
        $bindings = [];

        foreach (['category' => 'a.category', 'severity' => 'a.severity'] as $key => $column) {
            if ($filters[$key] !== '') {
                $where[]    = "{$column} = ?";
                $bindings[] = $filters[$key];
            }
        }

        if ($filters['action'] !== '') {
            $where[]    = 'a.action LIKE ?';
            $bindings[] = '%' . $filters['action'] . '%';
        }

        if ($filters['user_id'] !== '' && is_numeric($filters['user_id'])) {
            $where[]    = 'a.user_id = ?';
            $bindings[] = (int) $filters['user_id'];
        }

        if ($filters['from'] !== '') {
            $where[]    = 'DATE(a.created_at) >= ?';
            $bindings[] = $filters['from'];
        }

        if ($filters['to'] !== '') {
            $where[]    = 'DATE(a.created_at) <= ?';
            $bindings[] = $filters['to'];
        }

        $clause  = implode(' AND ', $where);
        $page    = $this->page($request);
        $perPage = 50;
        $offset  = ($page - 1) * $perPage;

        $total = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs a WHERE {$clause}", $bindings);

        $entries = Database::select(
            "SELECT a.*, u.name AS actor_name, o.legal_name AS organization_name,
                    INET6_NTOA(a.ip_address) AS ip_text
               FROM audit_logs a
          LEFT JOIN users u ON u.id = a.user_id
          LEFT JOIN organizations o ON o.id = a.organization_id
              WHERE {$clause}
           ORDER BY a.created_at DESC, a.id DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $bindings,
        );

        return $this->view('admin/administration/audit', [
            'pageTitle'  => 'سجل التدقيق',
            'entries'    => $entries,
            'filters'    => $filters,
            'categories' => Database::select(
                'SELECT DISTINCT category FROM audit_logs ORDER BY category',
            ),
            'pagination' => [
                'page'      => $page,
                'total'     => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ], 'admin');
    }
}
