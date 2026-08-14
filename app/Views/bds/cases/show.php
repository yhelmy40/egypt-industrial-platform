<?php
/** حالة دعم — عرض المركز | BDS case, centre view (§4.8). */
echo $view->partial('partials/bds-case-detail', [
    'case'          => $case,
    'notes'         => $notes,
    'consultations' => $consultations,
    'plans'         => $plans,
    'referrals'     => $referrals,
    'history'       => $history,
    'actions'       => $actions,
    'sections'      => $sections,
    'service'       => $service,
    'specialists'   => $specialists,
    'referable'     => $referable,
    'side'          => 'center',
    'basePath'      => '/app/bds/cases',
]);
