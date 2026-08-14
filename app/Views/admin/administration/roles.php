<?php
/**
 * الأدوار والصلاحيات | Roles and permissions (§4.13, §16).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * **شاشة اطّلاع لا تحرير.** الأدوار والصلاحيات تُعرَّف في بذرة مرجعية تحت
 * مراجعة الكود. تحرير الصلاحيات من الويب يجعل تصعيد الامتياز خطوةً واحدة لمن
 * يخترق حساب مدير، ويجعل مصفوفة الصلاحيات غير قابلة للمراجعة في فرق العمل.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * @var array<int,array<string,mixed>> $roles
 * @var array<int,array<string,mixed>> $permissions
 * @var array<int,array<int,bool>> $matrix
 */

$byModule = [];

foreach ($permissions as $permission) {
    $byModule[(string) $permission['module']][] = $permission;
}
?>
<div class="mb-3">
    <h1 class="h4 mb-1">الأدوار والصلاحيات</h1>
    <p class="fs-sm text-muted-np mb-0">
        <?= e(number_ar(count($roles))) ?> دوراً و<?= e(number_ar(count($permissions))) ?> صلاحية.
    </p>
</div>

<div class="alert alert-info fs-sm" role="alert">
    هذه الشاشة <strong>للاطّلاع والمراجعة لا للتحرير</strong>. الأدوار والصلاحيات
    تُعرَّف في بذرة مرجعية تحت مراجعة الكود، لأن تحريرها من الويب يجعل تصعيد الامتياز
    خطوةً واحدة لمن يخترق حساب مدير.
</div>

<div class="np-card mb-3">
    <div class="np-card__header"><h2 class="h6 mb-0">الأدوار</h2></div>
    <div class="np-card__body p-0">
        <div class="table-scroll" style="border:0">
            <table class="np-table">
                <thead><tr>
                    <th>الدور</th><th>النطاق</th><th>نوع المنشأة</th>
                    <th>الصلاحيات</th><th>يحملونه</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($roles as $role): ?>
                        <tr>
                            <td>
                                <strong class="fs-sm"><?= e($role['name_ar']) ?></strong>
                                <div class="fs-xs text-muted-np" dir="ltr"><?= e($role['code']) ?></div>
                                <?php if (!empty($role['description_ar'])): ?>
                                    <div class="fs-xs text-muted-np"><?= e($role['description_ar']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="np-badge <?= (string) $role['scope'] === 'platform'
                                    ? 'np-badge--info' : 'np-badge--muted' ?>">
                                    <?= (string) $role['scope'] === 'platform' ? 'المنصة' : 'المنشأة' ?>
                                </span>
                            </td>
                            <td class="fs-xs text-muted-np" dir="ltr">
                                <?= e($role['organization_type_code'] ?? '—') ?></td>
                            <td class="numeric fs-sm"><?= e(number_ar((int) $role['permission_count'])) ?></td>
                            <td class="numeric fs-sm">
                                <?= e(number_ar((int) $role['platform_holders'] + (int) $role['org_holders'])) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php foreach ($byModule as $module => $items): ?>
    <div class="np-card mb-2">
        <div class="np-card__header">
            <h2 class="h6 mb-0" dir="ltr"><?= e((string) $module) ?></h2>
        </div>
        <div class="np-card__body p-0">
            <div class="table-scroll" style="border:0">
                <table class="np-table">
                    <thead>
                        <tr>
                            <th style="min-width:16rem">الصلاحية</th>
                            <?php foreach ($roles as $role): ?>
                                <th class="fs-xs" style="writing-mode:vertical-rl;height:8rem">
                                    <?= e($role['name_ar']) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $permission): ?>
                            <tr>
                                <td>
                                    <div class="fs-sm"><?= e($permission['name_ar']) ?></div>
                                    <div class="fs-xs text-muted-np" dir="ltr">
                                        <?= e($permission['code']) ?>
                                        <?php if ((int) $permission['is_sensitive'] === 1): ?>
                                            <span class="np-badge np-badge--warning">حسّاسة</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <?php foreach ($roles as $role): ?>
                                    <?php $has = isset($matrix[(int) $role['id']][(int) $permission['id']]); ?>
                                    <td class="text-center">
                                        <?php if ($has): ?>
                                            <span class="text-success" aria-label="ممنوحة">✓</span>
                                        <?php else: ?>
                                            <span class="text-muted-np" aria-label="غير ممنوحة">·</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endforeach; ?>
