<?php
/**
 * مستخدم بلا منشأة | User with no organization yet.
 * يظهر بعد إنشاء حساب جديد وقبل تسجيل أي منشأة.
 */
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="np-card">
            <div class="np-card__body text-center py-5">
                <div class="intent-card__icon mx-auto mb-3" style="width:64px;height:64px;font-size:1.8rem">🏢</div>
                <h2 class="h4 mb-2">لم تُسجّل منشأة بعد</h2>
                <p class="text-muted-np mb-4">
                    لبدء استخدام مساحة العمل، سجّل مشروعك أو منشأتك. تحتاج إلى بيانات النشاط
                    والموقع ووسيلة تواصل — وتستكمل المستندات لاحقاً قبل الإرسال للمراجعة.
                </p>

                <a class="btn btn-primary btn-lg" href="<?= e(url('/app/organization/new')) ?>">
                    تسجيل منشأة جديدة
                </a>

                <p class="fs-sm text-muted-np mt-4 mb-0">
                    يمكنك أيضاً <a href="<?= e(url('/')) ?>">تصفّح المنصة</a> قبل التسجيل.
                </p>
            </div>
        </div>
    </div>
</div>
