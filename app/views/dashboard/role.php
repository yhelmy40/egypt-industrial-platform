<?php
/** لوحة مبسطة حسب الدور | Simplified role dashboard */
$pageTitle = 'لوحة المعلومات';
$roleLabel = Auth::roleLabel($role);
?>

<div class="mb-4">
    <h2 class="section-title mb-1">مرحباً، <?= e(Auth::user()['name'] ?? '') ?></h2>
    <p class="text-muted small mb-0">حساب <?= e($roleLabel) ?> — منصة مصر للبحث والتطوير الصناعي</p>
</div>

<?php if ($role === 'factory'): ?>
    <?php if (empty($factory)): ?>
        <div class="card mb-4">
            <div class="card-body text-center py-5">
                <div class="empty-state">
                    <div class="ico">🏭</div>
                    <p>لم تقم بإنشاء ملف المصنع بعد. ابدأ بتسجيل بيانات مصنعك للاستفادة من خدمات المنصة.</p>
                    <a href="<?= url('factory/create') ?>" class="btn btn-primary mt-2">➕ إنشاء ملف المصنع</a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-4">
                <div class="kpi-card"><div class="kpi-ico bg-navy">🎯</div>
                    <div><div class="kpi-val"><?= count($myChallenges) ?></div><div class="kpi-lbl">تحدياتي</div></div></div>
            </div>
            <div class="col-6 col-md-4">
                <div class="kpi-card"><div class="kpi-ico bg-teal">💰</div>
                    <div><div class="kpi-val"><?= (int) $fundingCount ?></div><div class="kpi-lbl">فرص تمويل متاحة</div></div></div>
            </div>
            <div class="col-12 col-md-4">
                <div class="kpi-card"><div class="kpi-ico bg-navy">🏭</div>
                    <div><div class="kpi-val" style="font-size:1.1rem"><?= e($factory['name']) ?></div><div class="kpi-lbl">مصنعك</div></div></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>أحدث التحديات المقدّمة</span>
                <a href="<?= url('challenge/create') ?>" class="btn btn-teal btn-sm">➕ تقديم تحدٍّ جديد</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($myChallenges)): ?>
                    <div class="empty-state p-4"><div class="ico">🎯</div><p>لا توجد تحديات بعد.</p></div>
                <?php else: ?>
                <table class="table mb-0">
                    <thead><tr><th>العنوان</th><th>القطاع</th><th>الأولوية</th><th>الحالة</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($myChallenges, 0, 6) as $c): ?>
                        <tr>
                            <td><?= e($c['title']) ?></td>
                            <td><?= e($c['sector_name'] ?? '—') ?></td>
                            <td><span class="badge bg-<?= priority_color($c['priority']) ?>"><?= e(priority_label($c['priority'])) ?></span></td>
                            <td><span class="badge bg-<?= challenge_status_color($c['status']) ?>"><?= e(challenge_status_label($c['status'])) ?></span></td>
                            <td><a href="<?= url('challenge/show/' . $c['id']) ?>" class="btn btn-outline-navy btn-sm">عرض</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

<?php elseif (in_array($role, ['researcher', 'expert'], true)): ?>
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-6">
            <div class="card h-100">
                <div class="card-header">ملفي المهني</div>
                <div class="card-body">
                    <?php if (empty($profile)): ?>
                        <div class="empty-state"><div class="ico">🔬</div>
                            <p>لم تنشئ ملفك المهني بعد. أنشئ ملفك ليظهر في نتائج المطابقة الذكية.</p>
                            <a href="<?= url('researcher/create') ?>" class="btn btn-primary mt-2">➕ إنشاء ملفي</a>
                        </div>
                    <?php else: ?>
                        <h5 class="text-navy mb-1"><?= e($profile['name']) ?></h5>
                        <p class="text-muted mb-2"><?= e($profile['organization'] ?? '') ?></p>
                        <p class="mb-2"><strong>التخصص:</strong> <?= e($profile['specialization']) ?></p>
                        <div>
                            <?php foreach (array_filter(array_map('trim', preg_split('/[،,]/u', $profile['expertise_keywords'] ?? ''))) as $kw): ?>
                                <span class="kw-chip"><?= e($kw) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <a href="<?= url('researcher/edit/' . $profile['id']) ?>" class="btn btn-outline-navy btn-sm mt-3">تعديل الملف</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="card h-100">
                <div class="card-header">تحديات مفتوحة بانتظار حلول</div>
                <div class="card-body p-0">
                    <?php if (empty($openChallenges)): ?>
                        <div class="empty-state p-4"><div class="ico">🎯</div><p>لا توجد تحديات مفتوحة حالياً.</p></div>
                    <?php else: ?>
                    <table class="table mb-0">
                        <thead><tr><th>التحدي</th><th>القطاع</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($openChallenges, 0, 7) as $c): ?>
                            <tr>
                                <td><?= e($c['title']) ?></td>
                                <td><?= e($c['sector_name'] ?? '—') ?></td>
                                <td><a href="<?= url('challenge/show/' . $c['id']) ?>" class="btn btn-outline-navy btn-sm">عرض</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($role === 'investor'): ?>
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="kpi-card"><div class="kpi-ico bg-navy">💰</div>
                <div><div class="kpi-val"><?= count($myFunding) ?></div><div class="kpi-lbl">برامج التمويل الخاصة بي</div></div></div>
        </div>
        <div class="col-6 col-md-4">
            <div class="kpi-card"><div class="kpi-ico bg-teal">🎯</div>
                <div><div class="kpi-val"><?= (int) $challengeCount ?></div><div class="kpi-lbl">تحديات صناعية</div></div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>برامج التمويل الخاصة بي</span>
            <a href="<?= url('funding/create') ?>" class="btn btn-teal btn-sm">➕ إضافة فرصة تمويل</a>
        </div>
        <div class="card-body p-0">
            <?php if (empty($myFunding)): ?>
                <div class="empty-state p-4"><div class="ico">💰</div><p>لم تقم بإضافة أي فرصة تمويل بعد.</p></div>
            <?php else: ?>
            <table class="table mb-0">
                <thead><tr><th>البرنامج</th><th>الجهة</th><th>الحد الأقصى</th><th>الموعد النهائي</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($myFunding as $o): ?>
                    <tr>
                        <td><?= e($o['program_name']) ?></td>
                        <td><?= e($o['funding_entity']) ?></td>
                        <td><?= fmt_money($o['max_funding_amount']) ?></td>
                        <td><?= fmt_date($o['application_deadline']) ?></td>
                        <td><a href="<?= url('funding/show/' . $o['id']) ?>" class="btn btn-outline-navy btn-sm">عرض</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
