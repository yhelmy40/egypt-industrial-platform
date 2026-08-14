<?php
/** طلب خدمة — عرض المشروع | Service request, applicant view (§4.6). */
echo $view->partial('partials/service-request-detail', [
    'serviceRequest' => $serviceRequest,
    'history'        => $history,
    'milestones'     => $milestones,
    'actions'        => $actions,
    'service'        => $service,
    'side'           => 'applicant',
    'actionPath'     => '/app/services/my-requests/' . $serviceRequest['id'] . '/action',
]);
