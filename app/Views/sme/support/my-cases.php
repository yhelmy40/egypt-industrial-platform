<?php
/** طلبات الدعم — عرض المشروع | The SME's support cases (§4.8). */
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h5 mb-0">طلبات الدعم</h1>
    <a class="btn btn-primary" href="<?= e(url('/app/bds/centers')) ?>">+ طلب دعم من مركز</a>
</div>

<?= $view->partial('partials/bds-case-list', [
    'cases'            => $cases,
    'counts'           => $counts,
    'filters'          => $filters,
    'service'          => $service,
    'basePath'         => '/app/bds/my-cases',
    'counterpartLabel' => 'المركز',
    'emptyMessage'     => 'لم تطلب دعماً بعد. تصفّح مراكز تطوير الأعمال وابدأ من هناك.',
    'showSpecialist'   => false,
]) ?>
