<?php
/**
 * فريق المنشأة | Organization team (§3.4, §3.5).
 *
 * @var array<string,mixed> $organization
 * @var array<int,array<string,mixed>> $members
 * @var array<int,array{grant:array<int,string>,deny:array<int,string>}> $overrides
 * @var array<int,array<string,mixed>> $invitations
 * @var array<int,array<string,mixed>> $roles
 * @var array<int,array<string,mixed>> $permissions
 */

// الصلاحيات مجمّعة حسب الوحدة لتبقى القائمة مقروءة
$byModule = [];
foreach ($permissions as $permission) {
    $byModule[(string) $permission['module']][] = $permission;
}
?>
<div class="row g-3">
    <div class="col-lg-8">
        <?php // ─── الأعضاء ─── ?>
        <div class="np-card mb-3">
            <div class="np-card__header">
                الأعضاء
                <span class="fs-sm text-muted-np fw-normal">
                    <?= e(number_ar(count($members))) ?></span>
            </div>
            <div class="np-card__body p-0">
                <div class="table-scroll" style="border:0">
                    <table class="np-table">
                        <thead><tr>
                            <th>العضو</th><th>الدور</th><th>الحالة</th><th>آخر دخول</th><th></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($members as $member): ?>
                                <tr>
                                    <td>
                                        <?= e($member['name']) ?>
                                        <?php if ((int) $member['is_primary_contact'] === 1): ?>
                                            <span class="np-badge np-badge--info">جهة الاتصال الرئيسية</span>
                                        <?php endif; ?>
                                        <div class="fs-xs text-muted-np" dir="ltr">
                                            <?= e(mask_email($member['email'])) ?></div>
                                    </td>
                                    <td class="fs-sm"><?= e($member['role_name']) ?></td>
                                    <td>
                                        <span class="np-badge <?= $member['status'] === 'active'
                                            ? 'np-badge--success' : 'np-badge--pending' ?>">
                                            <?= e($member['status'] === 'active' ? 'نشط' : 'بانتظار القبول') ?></span>
                                    </td>
                                    <td class="fs-xs text-muted-np">
                                        <?= $member['last_login_at'] !== null
                                            ? e(time_ago($member['last_login_at'])) : 'لم يدخل بعد' ?>
                                    </td>
                                    <td>
                                        <?php if ((int) $member['is_primary_contact'] !== 1): ?>
                                            <form method="post"
                                                  action="<?= e(url('/app/team/members/' . $member['id'] . '/remove')) ?>"
                                                  data-guard>
                                                <?= csrf_field() ?>
                                                <button type="submit" class="btn btn-sm btn-link text-danger p-0"
                                                        data-confirm="إزالة هذا العضو من المنشأة؟">إزالة</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php // ─── الصلاحيات الفردية ─── ?>
        <div class="np-card">
            <div class="np-card__header">صلاحيات الأعضاء</div>
            <div class="np-card__body">
                <p class="form-text mt-0">
                    كل عضو يرث صلاحيات دوره. يمكنك هنا منح صلاحية إضافية أو منعها. المنع يغلب المنح
                    دائماً، ولا تظهر في هذه القائمة إلا الصلاحيات التي يجوز لصاحب المنشأة إسنادها —
                    فلا يمكن تمرير صلاحية إشرافية على المنصة.
                </p>

                <?php foreach ($members as $member): ?>
                    <?php if ((int) $member['is_primary_contact'] === 1) { continue; } ?>
                    <?php
                    $memberId      = (int) $member['id'];
                    $memberGrants  = $overrides[$memberId]['grant'] ?? [];
                    $memberDenies  = $overrides[$memberId]['deny'] ?? [];
                    ?>
                    <details class="border-bottom border-np pb-2 mb-2">
                        <summary style="cursor:pointer">
                            <strong><?= e($member['name']) ?></strong>
                            <span class="fs-sm text-muted-np">— <?= e($member['role_name']) ?></span>
                            <?php if ($memberGrants !== [] || $memberDenies !== []): ?>
                                <span class="np-badge np-badge--warning">
                                    <?= e(number_ar(count($memberGrants) + count($memberDenies))) ?> تجاوز</span>
                            <?php endif; ?>
                        </summary>

                        <form method="post"
                              action="<?= e(url('/app/team/members/' . $memberId . '/permissions')) ?>"
                              class="mt-2" data-guard>
                            <?= csrf_field() ?>

                            <?php foreach ($byModule as $module => $modulePermissions): ?>
                                <p class="fs-sm fw-bold mb-1 mt-2"><?= e(__('modules.' . $module)) ?></p>
                                <?php foreach ($modulePermissions as $permission): ?>
                                    <?php $code = (string) $permission['code']; ?>
                                    <div class="d-flex flex-wrap align-items-center gap-3 fs-sm mb-1">
                                        <span class="flex-grow-1"><?= e($permission['name_ar']) ?></span>

                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   id="grant_<?= e((string) $memberId . '_' . $code) ?>"
                                                   name="grant[]" value="<?= e($code) ?>"
                                                <?= in_array($code, $memberGrants, true) ? ' checked' : '' ?>>
                                            <label class="form-check-label fs-xs"
                                                   for="grant_<?= e((string) $memberId . '_' . $code) ?>">منح</label>
                                        </div>

                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   id="deny_<?= e((string) $memberId . '_' . $code) ?>"
                                                   name="deny[]" value="<?= e($code) ?>"
                                                <?= in_array($code, $memberDenies, true) ? ' checked' : '' ?>>
                                            <label class="form-check-label fs-xs"
                                                   for="deny_<?= e((string) $memberId . '_' . $code) ?>">منع</label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>

                            <button type="submit" class="btn btn-sm btn-primary mt-2">حفظ صلاحيات العضو</button>
                        </form>
                    </details>
                <?php endforeach; ?>

                <?php if (count($members) <= 1): ?>
                    <p class="fs-sm text-muted-np mb-0">لا يوجد أعضاء آخرون بعد.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <?php // ─── دعوة عضو ─── ?>
        <div class="np-card mb-3">
            <div class="np-card__header">دعوة عضو جديد</div>
            <div class="np-card__body">
                <form method="post" action="<?= e(url('/app/team/invite')) ?>" data-guard>
                    <?= csrf_field() ?>

                    <label class="form-label" for="email">البريد الإلكتروني <span class="required">*</span></label>
                    <input type="email" class="form-control <?= has_error('email') ? 'is-invalid' : '' ?>"
                           id="email" name="email" dir="ltr" required maxlength="190"
                           value="<?= e(old('email')) ?>">
                    <?php if (has_error('email')): ?>
                        <div class="invalid-feedback"><?= e(error_for('email')) ?></div>
                    <?php endif; ?>

                    <label class="form-label mt-3" for="role_code">الدور <span class="required">*</span></label>
                    <select class="form-select <?= has_error('role_code') ? 'is-invalid' : '' ?>"
                            id="role_code" name="role_code" required>
                        <option value="">اختر الدور</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= e((string) $role['code']) ?>"
                                <?= old('role_code') === $role['code'] ? ' selected' : '' ?>>
                                <?= e($role['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (has_error('role_code')): ?>
                        <div class="invalid-feedback"><?= e(error_for('role_code')) ?></div>
                    <?php endif; ?>

                    <p class="form-text">
                        تصل الدعوة على البريد المُدخل وتنتهي صلاحيتها بعد سبعة أيام. القبول يتطلّب تسجيل
                        الدخول بنفس البريد.
                    </p>

                    <button type="submit" class="btn btn-primary w-100">إرسال الدعوة</button>
                </form>
            </div>
        </div>

        <?php // ─── الدعوات المعلّقة ─── ?>
        <div class="np-card">
            <div class="np-card__header">دعوات معلّقة</div>
            <div class="np-card__body p-0">
                <?php if ($invitations === []): ?>
                    <div class="np-empty py-4">
                        <p class="fs-sm mb-0">لا توجد دعوات معلّقة.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($invitations as $invitation): ?>
                        <div class="p-3 border-bottom border-np">
                            <div class="fs-sm" dir="ltr" style="word-break:break-all">
                                <?= e(mask_email($invitation['email'])) ?></div>
                            <div class="fs-xs text-muted-np">
                                <?= e($invitation['role_name']) ?>
                                · تنتهي <?= e(format_date($invitation['expires_at'])) ?>
                            </div>
                            <form method="post"
                                  action="<?= e(url('/app/team/invitations/' . $invitation['id'] . '/revoke')) ?>"
                                  data-guard>
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-link text-danger p-0 fs-xs">
                                    إلغاء الدعوة</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
