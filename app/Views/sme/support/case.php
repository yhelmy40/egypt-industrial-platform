<?php
/**
 * حالة دعم — عرض المشروع | BDS case, SME view (§4.8).
 *
 * `$notes` و`$plans` وصلا هنا مُصفّاة من المستودع: الملاحظات الداخلية والخطط
 * غير المشتركة لم تُجلب أصلاً. القالب لا يفلتر شيئاً.
 */
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
    'specialists'   => [],
    'referable'     => [],
    'side'          => 'organization',
    'basePath'      => '/app/bds/my-cases',
]);
