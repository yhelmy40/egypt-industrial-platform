<?php
/**
 * مستخدم بلا منشأة | User with no organization yet.
 * يظهر عند تسجيل حساب جديد قبل تسجيل أي منشأة، أو بعد إلغاء آخر عضوية.
 */
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="np-card">
            <div class="np-card__body text-center py-5">
                <div class="intent-card__icon mx-auto mb-3" style="width:64px;height:64px;font-size:1.8rem">🏢</div>
                <h2 class="h4 mb-2">لم تُسجّل منشأة بعد</h2>
                <p class="text-muted-np mb-4">
                    لبدء استخدام مساحة العمل، سجّل مشروعك أو منشأتك. ستحتاج إلى بيانات النشاط
                    والموقع، والمستندات الرسمية إن وُجدت.
                </p>

                <div class="alert alert-info text-start fs-sm" role="alert">
                    <strong>ملاحظة:</strong> وحدة تسجيل المنشآت قيد التنفيذ ضمن المرحلة الثانية
                    من خطة التطوير، وستُفعَّل هنا فور اكتمالها.
                </div>

                <a class="btn btn-outline-primary" href="<?= e(url('/')) ?>"><?= __e('common.home') ?></a>
            </div>
        </div>
    </div>
</div>
