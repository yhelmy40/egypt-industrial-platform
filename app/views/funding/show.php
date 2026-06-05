<?php
/** عرض فرصة تمويل | Funding opportunity details */
$pageTitle  = $opportunity['program_name'];
$canManage  = Auth::isAdmin() || (int)($opportunity['user_id'] ?? 0) === (int) Auth::id();
$sectorIds  = array_filter(array_map('trim', explode(',', $opportunity['eligible_sectors'] ?? '')));
?>

<div class="page-head d-flex justify-content-between align-items-center mb-3">
    <h2 class="section-title mb-0"><?= e($opportunity['program_name']) ?></h2>
    <div>
        <a href="<?= url('funding') ?>" class="btn btn-outline-navy btn-sm">← القائمة</a>
        <?php if ($canManage): ?>
            <a href="<?= url('funding/edit/' . $opportunity['id']) ?>" class="btn btn-teal btn-sm">تعديل</a>
            <form method="post" action="<?= url('funding/destroy/' . $opportunity['id']) ?>" class="d-inline"
                  data-confirm="هل تريد حذف فرصة التمويل؟">
                <?= Csrf::field() ?>
                <button class="btn btn-outline-navy btn-sm">حذف</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">التفاصيل المالية</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr><th class="text-muted" style="width:45%">جهة التمويل</th><td><?= e($opportunity['funding_entity']) ?></td></tr>
                    <tr><th class="text-muted">الحد الأقصى للتمويل</th><td class="text-navy"><strong><?= fmt_money($opportunity['max_funding_amount']) ?></strong></td></tr>
                    <tr><th class="text-muted">الموعد النهائي للتقديم</th><td><?= fmt_date($opportunity['application_deadline']) ?></td></tr>
                    <tr><th class="text-muted">عدد القطاعات المؤهلة</th><td><?= $sectorIds ? count($sectorIds) . ' قطاع' : 'كل القطاعات' ?></td></tr>
                    <tr><th class="text-muted">أضيفت بواسطة</th><td><?= e($opportunity['owner_name'] ?? '—') ?></td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header">وصف البرنامج</div>
            <div class="card-body"><?= nl2br(e($opportunity['description'] ?: '—')) ?></div>
        </div>
        <div class="card">
            <div class="card-header">بيانات التواصل</div>
            <div class="card-body"><?= nl2br(e($opportunity['contact_details'] ?: '—')) ?></div>
        </div>
    </div>
</div>
