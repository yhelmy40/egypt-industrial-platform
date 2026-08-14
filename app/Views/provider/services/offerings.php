<?php
/** باقات الخدمات | The provider's service offerings (§4.6). */
echo $view->partial('partials/approvable-list', [
    'items'        => $offerings,
    'counts'       => $counts,
    'filters'      => $filters,
    'service'      => $service,
    'basePath'     => '/app/services/offerings',
    'newLabel'     => 'باقة خدمة جديدة',
    'emptyMessage' => 'لم تُضف باقات خدمات بعد.',
    'summaryLine'  => static function (array $item) use ($service): string {
        return $service->typeLabel((string) $item['service_type']) . ' · '
            . $service->priceLabel($item) . ' · '
            . number_ar((int) $item['request_count']) . ' طلباً';
    },
]);
