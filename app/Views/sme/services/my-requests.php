<?php
/** طلبات الخدمة — عرض المشروع | The applicant's service requests (§4.6). */
echo $view->partial('partials/service-request-list', [
    'requests'         => $requests,
    'counts'           => $counts,
    'filters'          => $filters,
    'service'          => $service,
    'basePath'         => '/app/services/my-requests',
    'counterpartLabel' => 'مقدّم الخدمة',
    'emptyMessage'     => 'لم تطلب أي خدمة بعد. تصفّح الخدمات المعتمدة وابدأ من هناك.',
]);
