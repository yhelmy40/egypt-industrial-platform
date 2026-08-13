<?php
/** فرص التمويل | Funding opportunities list */
$pageTitle = 'فرص التمويل';
?>

<div class="page-head d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="section-title mb-1">فرص التمويل</h2>
        <p class="text-muted small mb-0">إجمالي <?= count($opportunities) ?> برنامج تمويل</p>
    </div>
    <?php if (Auth::isAdmin() || Auth::is('investor')): ?>
        <a href="<?= url('funding/create') ?>" class="btn btn-primary">➕ إضافة فرصة</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body">
        <input type="text" class="form-control mb-3" placeholder="🔍 بحث..." data-table-filter="#fundTable">
        <div class="table-responsive">
        <table class="table align-middle" id="fundTable">
            <thead><tr><th>#</th><th>البرنامج</th><th>جهة التمويل</th><th>الحد الأقصى</th><th>الموعد النهائي</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($opportunities)): ?>
                <tr><td colspan="6"><div class="empty-state p-4"><div class="ico">💰</div><p>لا توجد فرص تمويل.</p></div></td></tr>
            <?php else: foreach ($opportunities as $i => $o):
                $expired = !empty($o['application_deadline']) && strtotime($o['application_deadline']) < strtotime('today');
            ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><strong><?= e($o['program_name']) ?></strong></td>
                    <td><?= e($o['funding_entity']) ?></td>
                    <td><?= fmt_money($o['max_funding_amount']) ?></td>
                    <td>
                        <?= fmt_date($o['application_deadline']) ?>
                        <?php if ($expired): ?><span class="badge bg-danger">منتهٍ</span><?php endif; ?>
                    </td>
                    <td><a href="<?= url('funding/show/' . $o['id']) ?>" class="btn btn-outline-navy btn-sm">عرض</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
