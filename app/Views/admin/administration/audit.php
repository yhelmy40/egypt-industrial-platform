<?php
/**
 * سجل التدقيق | The audit trail (§4.13, §16).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * **للقراءة فقط، دائماً.** لا يوجد في المنصة كلّها مسار يعدّل صفّاً في
 * `audit_logs` أو يحذفه. سجلّ يمكن تحريره لا يصلح دليلاً على شيء، وهذا هو
 * الغرض الوحيد من وجوده.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * @var array<int,array<string,mixed>> $entries
 * @var array<string,string> $filters
 * @var array<int,array<string,mixed>> $categories
 * @var array{page:int,total:int,last_page:int} $pagination
 */

$severities = ['' => 'كل المستويات', 'info' => 'معلومة', 'notice' => 'ملحوظة',
               'warning' => 'تحذير', 'critical' => 'حرِج'];

$categoryLabels = [
    'auth' => 'المصادقة', 'rbac' => 'الأدوار', 'verification' => 'التوثيق',
    'order' => 'الطلبات', 'application' => 'الطلبات المقدَّمة', 'finance' => 'المالية',
    'document' => 'المستندات', 'export' => 'التصدير', 'config' => 'الإعدادات',
    'record' => 'السجلّات', 'security' => 'الأمان',
];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">سجل التدقيق</h1>
        <p class="fs-sm text-muted-np mb-0">
            <?= e(number_ar($pagination['total'])) ?> قيداً. السجلّ للقراءة فقط ولا يُعدَّل ولا يُحذف.
        </p>
    </div>
</div>

<form method="get" action="<?= e(url('/admin/audit')) ?>" class="np-card mb-3">
    <div class="np-card__body row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label fs-sm" for="category">الفئة</label>
            <select class="form-select form-select-sm" id="category" name="category">
                <option value="">الكل</option>
                <?php foreach ($categories as $row): ?>
                    <?php $code = (string) $row['category']; ?>
                    <option value="<?= e($code) ?>" <?= $filters['category'] === $code ? 'selected' : '' ?>>
                        <?= e($categoryLabels[$code] ?? $code) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label fs-sm" for="severity">المستوى</label>
            <select class="form-select form-select-sm" id="severity" name="severity">
                <?php foreach ($severities as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['severity'] === $value ? 'selected' : '' ?>>
                        <?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fs-sm" for="action">الإجراء</label>
            <input type="search" class="form-control form-control-sm" id="action" name="action"
                   value="<?= e($filters['action']) ?>" placeholder="مثال: user.status_changed" dir="ltr">
        </div>
        <div class="col-md-2">
            <label class="form-label fs-sm" for="from">من</label>
            <input type="date" class="form-control form-control-sm" dir="ltr" id="from" name="from"
                   value="<?= e($filters['from']) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label fs-sm" for="to">إلى</label>
            <input type="date" class="form-control form-control-sm" dir="ltr" id="to" name="to"
                   value="<?= e($filters['to']) ?>">
        </div>
        <div class="col-md-1">
            <button type="submit" class="btn btn-sm btn-outline-primary w-100">تصفية</button>
        </div>
    </div>
</form>

<div class="np-card">
    <div class="np-card__body p-0">
        <?php if ($entries === []): ?>
            <div class="np-empty"><p class="mb-0">لا قيود مطابقة.</p></div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>الوقت</th><th>الفاعل</th><th>الإجراء</th>
                        <th>الوصف</th><th>المستوى</th><th>المصدر</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <?php $severity = (string) $entry['severity']; ?>
                            <tr>
                                <td class="fs-xs text-muted-np" style="white-space:nowrap">
                                    <?= e(format_date((string) $entry['created_at'], true)) ?></td>
                                <td class="fs-sm">
                                    <?= e($entry['actor_name'] ?? 'النظام') ?>
                                    <?php if (!empty($entry['organization_name'])): ?>
                                        <div class="fs-xs text-muted-np">
                                            <?= e($entry['organization_name']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="fs-xs" dir="ltr"><?= e($entry['action']) ?></td>
                                <td class="fs-sm">
                                    <?= e($entry['description'] ?? '—') ?>
                                    <?php if (!empty($entry['entity_type'])): ?>
                                        <div class="fs-xs text-muted-np" dir="ltr">
                                            <?= e($entry['entity_type']) ?>
                                            <?= $entry['entity_id'] !== null
                                                ? '#' . e((string) $entry['entity_id']) : '' ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="np-badge <?= e(match ($severity) {
                                        'critical' => 'np-badge--danger',
                                        'warning'  => 'np-badge--warning',
                                        'notice'   => 'np-badge--review',
                                        default    => 'np-badge--muted',
                                    }) ?>"><?= e($severities[$severity] ?? $severity) ?></span>
                                </td>
                                <td class="fs-xs text-muted-np" dir="ltr">
                                    <?= e($entry['ip_text'] ?? '—') ?>
                                    <?php if (!empty($entry['method'])): ?>
                                        <div><?= e($entry['method']) ?> <?= e($entry['route'] ?? '') ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($pagination['last_page'] > 1): ?>
    <?php
    $pageUrl = static function (int $page) use ($filters): string {
        $params = array_filter($filters + ['page' => $page], static fn ($v) => $v !== '' && $v !== null);

        return url('/admin/audit') . '?' . http_build_query($params);
    };
    $window = 10;
    $start  = max(1, $pagination['page'] - (int) ($window / 2));
    $end    = min($pagination['last_page'], $start + $window - 1);
    ?>
    <nav class="mt-3" aria-label="تصفّح الصفحات">
        <ul class="pagination pagination-sm justify-content-center mb-0">
            <?php for ($page = $start; $page <= $end; $page++): ?>
                <li class="page-item <?= $page === $pagination['page'] ? 'active' : '' ?>">
                    <a class="page-link" href="<?= e($pageUrl($page)) ?>"><?= e(number_ar($page)) ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>
