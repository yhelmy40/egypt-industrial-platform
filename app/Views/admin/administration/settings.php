<?php
/**
 * إعدادات المنصة | Platform settings (§4.13).
 *
 * المفاتيح المعروفة فقط تُحدَّث: مفتاح جديد يأتي من الطلب يُتجاهل في المتحكّم،
 * فلا يصير النموذج بوابة لكتابة أي صفّ في جدول الإعدادات.
 *
 * @var array<string,array<int,array<string,mixed>>> $grouped
 */

$groupLabels = [
    'general'     => 'عام',
    'marketplace' => 'السوق',
    'security'    => 'الأمان',
    'uploads'     => 'المرفوعات',
    'mail'        => 'البريد',
    'finance'     => 'التمويل',
];
?>
<div class="mb-3">
    <h1 class="h4 mb-1">إعدادات المنصة</h1>
    <p class="fs-sm text-muted-np mb-0">كل تغيير يُسجَّل في سجلّ التدقيق باسم من أجراه.</p>
</div>

<form method="post" action="<?= e(url('/admin/settings')) ?>">
    <?= csrf_field() ?>

    <?php foreach ($grouped as $group => $settings): ?>
        <div class="np-card mb-3">
            <div class="np-card__header">
                <h2 class="h6 mb-0"><?= e($groupLabels[$group] ?? $group) ?></h2>
            </div>
            <div class="np-card__body row g-3">
                <?php foreach ($settings as $setting): ?>
                    <?php
                    $key   = (string) $setting['setting_key'];
                    $id    = 'set_' . $group . '_' . $key;
                    $type  = (string) $setting['value_type'];
                    $value = (string) $setting['value'];
                    ?>
                    <div class="col-md-6">
                        <label class="form-label fs-sm" for="<?= e($id) ?>">
                            <?= e($setting['label_ar'] ?: $key) ?>
                            <?php if ((int) $setting['is_public'] === 1): ?>
                                <span class="np-badge np-badge--info">عام</span>
                            <?php endif; ?>
                        </label>

                        <?php if ($type === 'bool'): ?>
                            <select class="form-select form-select-sm" id="<?= e($id) ?>"
                                    name="settings[<?= e($group) ?>][<?= e($key) ?>]">
                                <option value="1" <?= $value === '1' ? 'selected' : '' ?>>مُفعَّل</option>
                                <option value="0" <?= $value !== '1' ? 'selected' : '' ?>>مُعطَّل</option>
                            </select>
                        <?php elseif ($type === 'int' || $type === 'decimal'): ?>
                            <input type="number" <?= $type === 'decimal' ? 'step="0.01"' : '' ?>
                                   dir="ltr" class="form-control form-control-sm numeric"
                                   id="<?= e($id) ?>"
                                   name="settings[<?= e($group) ?>][<?= e($key) ?>]"
                                   value="<?= e($value) ?>">
                        <?php else: ?>
                            <input type="text" class="form-control form-control-sm" id="<?= e($id) ?>"
                                   name="settings[<?= e($group) ?>][<?= e($key) ?>]"
                                   value="<?= e($value) ?>">
                        <?php endif; ?>

                        <div class="form-text fs-xs">
                            <span dir="ltr"><?= e($key) ?></span>
                            <?php if (!empty($setting['description_ar'])): ?>
                                — <?= e($setting['description_ar']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="np-card">
        <div class="np-card__body">
            <button type="submit" class="btn btn-primary">حفظ الإعدادات</button>
        </div>
    </div>
</form>
