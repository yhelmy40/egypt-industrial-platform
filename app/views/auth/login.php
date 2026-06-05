<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-head">
            <div class="logo-badge">🏭</div>
            <h1><?= e(APP_NAME_AR) ?></h1>
            <p><?= e(APP_NAME_EN) ?></p>
        </div>
        <div class="auth-body">
            <?= render_flash() ?>
            <form method="post" action="<?= url('auth/login') ?>" class="needs-validation" novalidate>
                <?= Csrf::field() ?>
                <div class="mb-3">
                    <label class="form-label">البريد الإلكتروني</label>
                    <input type="email" name="email" class="form-control" dir="ltr"
                           value="<?= e($email ?? '') ?>" required placeholder="name@industry.gov.eg">
                    <div class="invalid-feedback">يرجى إدخال بريد إلكتروني صحيح.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">كلمة المرور</label>
                    <input type="password" name="password" class="form-control" dir="ltr" required placeholder="••••••••">
                    <div class="invalid-feedback">يرجى إدخال كلمة المرور.</div>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">تسجيل الدخول</button>
            </form>

            <div class="demo-box">
                <strong class="text-navy">حسابات تجريبية:</strong><br>
                مدير الوزارة: <code>admin@industry.gov.eg / Admin123!</code><br>
                مصنع: <code>factory@demo.com / Factory123!</code><br>
                باحث: <code>researcher@demo.com / Research123!</code><br>
                خبير: <code>expert@demo.com / Expert123!</code><br>
                مستثمر: <code>investor@demo.com / Investor123!</code>
            </div>
        </div>
    </div>
</div>
