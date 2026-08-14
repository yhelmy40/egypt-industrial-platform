<?php
/**
 * المستخدمون | Users (§4.13, §10).
 *
 * البريد يظهر هنا لمن يملك إدارة المستخدمين وحده، ولا يخرج في أي تصدير.
 *
 * @var array<int,array<string,mixed>> $users
 * @var array<string,string> $filters
 * @var array<int,array<string,mixed>> $roles
 * @var array{page:int,total:int,last_page:int} $pagination
 */

$statuses = ['' => 'كل الحالات', 'active' => 'نشط', 'pending' => 'بانتظار التفعيل',
             'suspended' => 'موقوف', 'deactivated' => 'مُعطَّل'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">المستخدمون</h1>
        <p class="fs-sm text-muted-np mb-0">
            <?= e(number_ar($pagination['total'])) ?> حساباً. الإيقاف قابل للرفع ولا يحذف الحساب.
        </p>
    </div>
</div>

<form method="get" action="<?= e(url('/admin/users')) ?>" class="np-card mb-3">
    <div class="np-card__body row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label fs-sm" for="q">بحث</label>
            <input type="search" class="form-control form-control-sm" id="q" name="q"
                   value="<?= e($filters['q']) ?>" placeholder="الاسم أو البريد…">
        </div>
        <div class="col-md-3">
            <label class="form-label fs-sm" for="status">الحالة</label>
            <select class="form-select form-select-sm" id="status" name="status">
                <?php foreach ($statuses as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>>
                        <?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fs-sm" for="role">دور المنصة</label>
            <select class="form-select form-select-sm" id="role" name="role">
                <option value="">كل الأدوار</option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= e($role['code']) ?>"
                        <?= $filters['role'] === $role['code'] ? 'selected' : '' ?>>
                        <?= e($role['name_ar']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-sm btn-outline-primary w-100">تصفية</button>
        </div>
    </div>
</form>

<div class="np-card">
    <div class="np-card__body p-0">
        <?php if ($users === []): ?>
            <div class="np-empty"><p class="mb-0">لا مستخدمين مطابقين.</p></div>
        <?php else: ?>
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead><tr>
                        <th>المستخدم</th><th>أدوار المنصة</th><th>العضويات</th>
                        <th>الحالة</th><th>آخر دخول</th><th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <?php $status = (string) $user['status']; ?>
                            <tr>
                                <td>
                                    <strong class="fs-sm"><?= e($user['name']) ?></strong>
                                    <div class="fs-xs text-muted-np" dir="ltr"><?= e($user['email']) ?></div>
                                    <?php if ((int) $user['must_change_password'] === 1): ?>
                                        <span class="np-badge np-badge--warning">يلزم تغيير كلمة المرور</span>
                                    <?php endif; ?>
                                </td>
                                <td class="fs-sm"><?= e($user['platform_roles'] ?? '—') ?></td>
                                <td class="numeric fs-sm"><?= e(number_ar((int) $user['memberships'])) ?></td>
                                <td>
                                    <span class="np-badge <?= e(match ($status) {
                                        'active'      => 'np-badge--success',
                                        'suspended'   => 'np-badge--danger',
                                        'pending'     => 'np-badge--pending',
                                        default       => 'np-badge--muted',
                                    }) ?>"><?= e($statuses[$status] ?? $status) ?></span>
                                    <?php if ($status === 'suspended' && !empty($user['suspended_reason'])): ?>
                                        <div class="fs-xs text-muted-np">
                                            <?= e($user['suspended_reason']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="fs-xs text-muted-np">
                                    <?= $user['last_login_at'] !== null
                                        ? e(format_date((string) $user['last_login_at'], true)) : 'لم يدخل بعد' ?>
                                </td>
                                <td>
                                    <?php if ($status === 'suspended'): ?>
                                        <form method="post"
                                              action="<?= e(url('/admin/users/' . $user['id'] . '/restore')) ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-primary"
                                                    data-confirm="إعادة تفعيل هذا الحساب؟">إعادة تفعيل</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" class="d-flex gap-1"
                                              action="<?= e(url('/admin/users/' . $user['id'] . '/suspend')) ?>">
                                            <?= csrf_field() ?>
                                            <label class="visually-hidden"
                                                   for="reason_<?= e((string) $user['id']) ?>">سبب الإيقاف</label>
                                            <input type="text" class="form-control form-control-sm"
                                                   id="reason_<?= e((string) $user['id']) ?>" name="reason"
                                                   maxlength="500" placeholder="سبب الإيقاف"
                                                   style="min-width:10rem">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                                    data-confirm="إيقاف هذا الحساب؟">إيقاف</button>
                                        </form>
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
    <nav class="mt-3" aria-label="تصفّح الصفحات">
        <ul class="pagination pagination-sm justify-content-center mb-0">
            <?php for ($page = 1; $page <= $pagination['last_page']; $page++): ?>
                <li class="page-item <?= $page === $pagination['page'] ? 'active' : '' ?>">
                    <a class="page-link" href="<?= e(url('/admin/users?page=' . $page)) ?>">
                        <?= e(number_ar($page)) ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>
