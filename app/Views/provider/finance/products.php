<?php
/** المنتجات التمويلية | The institution's financing products (§4.5). */
echo $view->partial('partials/approvable-list', [
    'items'        => $products,
    'counts'       => $counts,
    'filters'      => $filters,
    'service'      => $service,
    'basePath'     => '/app/finance/products',
    'newLabel'     => 'منتج تمويلي جديد',
    'emptyMessage' => 'لم تُضف منتجات تمويلية بعد.',
    'summaryLine'  => static function (array $item) use ($service): string {
        $band = $item['min_amount'] === null
            ? 'الشريحة غير محدَّدة'
            : money((float) $item['min_amount']) . ' – ' . money((float) $item['max_amount']);

        return $service->typeLabel((string) $item['financing_type']) . ' · ' . $band
            . ' · ' . number_ar((int) $item['application_count']) . ' طلباً';
    },
]);
