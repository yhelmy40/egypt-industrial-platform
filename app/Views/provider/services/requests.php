<?php
/** طلبات الخدمة الواردة | Incoming service requests (§4.6). */
echo $view->partial('partials/service-request-list', [
    'requests'         => $requests,
    'counts'           => $counts,
    'filters'          => $filters,
    'service'          => $service,
    'basePath'         => '/app/services/requests',
    'counterpartLabel' => 'المشروع',
    'emptyMessage'     => 'لا توجد طلبات واردة. تظهر هنا فور طلب أي مشروع لإحدى باقاتك المعتمدة.',
]);
