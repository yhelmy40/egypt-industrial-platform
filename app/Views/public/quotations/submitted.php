<?php
/** تأكيد إرسال طلب عرض السعر | RFQ submitted confirmation. */
?>
<section class="container py-5">
    <div class="row justify-content-center"><div class="col-lg-7">
        <div class="np-card"><div class="np-card__body text-center py-4">
            <div class="intent-card__icon mx-auto mb-3" style="width:64px;height:64px;font-size:1.8rem">📨</div>
            <h1 class="h4 mb-2">تم إرسال طلب عرض السعر</h1>
            <p class="text-muted-np">
                رقم الطلب: <strong dir="ltr"><?= e($quotation['number']) ?></strong>
            </p>
            <p class="fs-sm text-muted-np mb-4">
                ستراجع المنشأة طلبك وترسل عرض السعر. احفظ الرابط التالي لمتابعة العرض والرد عليه:
            </p>
            <a class="btn btn-primary" href="<?= e($trackUrl) ?>">متابعة طلب عرض السعر</a>
        </div></div>
    </div></div>
</section>
