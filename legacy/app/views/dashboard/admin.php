<?php $pageTitle = 'لوحة معلومات الوزارة'; ?>

<div class="mb-4">
    <h2 class="section-title mb-1">المؤشرات العامة للمنصة</h2>
    <p class="text-muted small mb-0">نظرة شاملة على منظومة البحث والتطوير الصناعي</p>
</div>

<!-- KPI cards -->
<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['🏭', 'المصانع المسجلة', $kpi['factories'], 'bg-navy'],
        ['🔬', 'الباحثون', $kpi['researchers'], 'bg-teal'],
        ['🎓', 'الخبراء', $kpi['experts'], 'bg-navy'],
        ['🎯', 'إجمالي التحديات', $kpi['challenges_total'], 'bg-teal'],
        ['📂', 'تحديات مفتوحة', $kpi['challenges_open'], 'bg-navy'],
        ['🔗', 'تحديات تم ربطها', $kpi['challenges_matched'], 'bg-teal'],
        ['🧪', 'مشاريع نشطة', $kpi['projects_active'], 'bg-navy'],
        ['✅', 'مشاريع مكتملة', $kpi['projects_completed'], 'bg-teal'],
        ['💰', 'فرص التمويل', $kpi['funding'], 'bg-navy'],
        ['⏳', 'بانتظار المراجعة', $kpi['challenges_pending'], 'bg-teal'],
    ];
    foreach ($cards as [$ico, $lbl, $val, $bg]): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="kpi-card">
            <div class="kpi-ico <?= $bg ?>"><?= $ico ?></div>
            <div>
                <div class="kpi-val"><?= (int) $val ?></div>
                <div class="kpi-lbl"><?= e($lbl) ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Charts -->
<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">المشاريع حسب القطاع الصناعي</div>
            <div class="card-body" style="height:300px">
                <?php if (!empty($sectorCounts)): ?>
                    <canvas id="sectorChart"></canvas>
                <?php else: ?>
                    <div class="empty-state"><div class="ico">📊</div><p>لا توجد مشاريع لعرضها بعد.</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">التحديات حسب الأولوية</div>
            <div class="card-body" style="height:300px">
                <canvas id="priorityChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Recent challenges -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>أحدث التحديات الصناعية</span>
        <a href="<?= url('challenge') ?>" class="btn btn-sm btn-outline-navy">عرض الكل</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>العنوان</th><th>المصنع</th><th>القطاع</th>
                        <th>الأولوية</th><th>الحالة</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($recentChallenges)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">لا توجد تحديات بعد.</td></tr>
                <?php else: foreach ($recentChallenges as $c): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($c['title']) ?></td>
                        <td><?= e($c['factory_name'] ?? '—') ?></td>
                        <td><?= e($c['sector_name'] ?? '—') ?></td>
                        <td><span class="badge bg-<?= priority_color($c['priority']) ?>"><?= priority_label($c['priority']) ?></span></td>
                        <td><span class="badge bg-<?= challenge_status_color($c['status']) ?>"><?= challenge_status_label($c['status']) ?></span></td>
                        <td><a href="<?= url('challenge/show/' . $c['id']) ?>" class="btn btn-sm btn-teal">عرض</a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// تجهيز بيانات الرسوم | prepare chart data
$sectorLabels = array_map(fn($r) => $r['sector'], $sectorCounts);
$sectorData   = array_map(fn($r) => (int) $r['c'], $sectorCounts);
$inlineScript = "
EGCharts.doughnut('sectorChart', " . json_encode($sectorLabels, JSON_UNESCAPED_UNICODE) . ", " . json_encode($sectorData) . ");
EGCharts.bar('priorityChart',
    ['عالية','متوسطة','منخفضة'],
    [" . (int)$priorityCounts['high'] . "," . (int)$priorityCounts['medium'] . "," . (int)$priorityCounts['low'] . "],
    'عدد التحديات');
";
?>
