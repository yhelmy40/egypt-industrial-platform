<?php
/** طلب خدمة — عرض المزوّد | Service request, provider view (§4.6). */
echo $view->partial('partials/service-request-detail', [
    'serviceRequest' => $serviceRequest,
    'history'        => $history,
    'milestones'     => $milestones,
    'actions'        => $actions,
    'service'        => $service,
    'side'           => 'provider',
    'actionPath'     => '/app/services/requests/' . $serviceRequest['id'] . '/action',
]);
