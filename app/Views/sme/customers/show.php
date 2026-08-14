<?php
/**
 * ملفّ العميل | The customer file (§4.9).
 *
 * @var array<string,mixed> $customer
 * @var array<int,array<string,mixed>> $contacts
 * @var array<string,mixed> $summary
 * @var array<int,array<string,mixed>> $activities
 * @var array<int,array<string,mixed>> $opportunities
 * @var array<int,array<string,mixed>> $invoices
 * @var \App\Services\CustomerService $service
 * @var \App\Services\PipelineService $pipeline
 */
$customerId = (int) $customer['id'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1"><?= e($customer['name_ar']) ?></h1>
        <p class="fs-sm text-muted-np mb-0">
            <span class="numeric" dir="ltr"><?= e($customer['code']) ?></span> ·
            <?= e($service->typeLabel((string) $customer['customer_type'])) ?> ·
            مصدره: <?= e($service->sourceLabel((string) $customer['source'])) ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-primary"
           href="<?= e(url('/app/customers/' . $customerId . '/edit')) ?>">تعديل</a>
        <a class="btn btn-sm btn-primary"
           href="<?= e(url('/app/invoices/new?customer_id=' . $customerId)) ?>">فاتورة جديدة</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">إجمالي المفوتَر</div>
            <div class="stat-value"><?= e(money((float) $summary['invoiced_total'])) ?></div>
            <div class="stat-tile__meta">عدا الفواتير الملغاة</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">المحصَّل</div>
            <div class="stat-value"><?= e(money((float) $summary['paid_total'])) ?></div>
            <div class="stat-tile__meta">مقبوضات مسجَّلة</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">المستحقّ عليه</div>
            <div class="stat-value"><?= e(money((float) $summary['outstanding'])) ?></div>
            <div class="stat-tile__meta">
                <?php if ($customer['credit_limit'] !== null): ?>
                    الحدّ الإرشادي <?= e(money((float) $customer['credit_limit'])) ?>
                <?php else: ?>
                    لا حدّ ائتماني مسجَّل
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-tile">
            <div class="stat-tile__label">عدد الفواتير</div>
            <div class="stat-value"><?= e(number_ar((int) $summary['invoice_count'])) ?></div>
            <div class="stat-tile__meta">
                <?= $summary['last_invoice_date'] !== null
                    ? 'آخرها ' . e(format_date((string) $summary['last_invoice_date']))
                    : 'لا فواتير بعد' ?>
            </div>
        </div>
    </div>
</div>

<?php if ($customer['credit_limit'] !== null
    && (float) $summary['outstanding'] > (float) $customer['credit_limit']): ?>
    <div class="alert alert-warning" role="alert">
        المستحقّ على هذا العميل تجاوز الحدّ الائتماني الذي وضعته. هذا تنبيه لك،
        والقرار بمواصلة البيع أو إيقافه قرارك وحدك.
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <!-- الفواتير | Invoices -->
        <div class="np-card mb-3">
            <div class="np-card__header d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">آخر الفواتير</h2>
                <a class="btn btn-sm btn-outline-primary"
                   href="<?= e(url('/app/invoices?customer_id=' . $customerId)) ?>">الكل</a>
            </div>
            <div class="np-card__body p-0">
                <?php if ($invoices === []): ?>
                    <div class="np-empty"><p class="mb-0 fs-sm">لا فواتير لهذا العميل بعد.</p></div>
                <?php else: ?>
                    <div class="table-scroll" style="border:0">
                        <table class="np-table">
                            <thead><tr>
                                <th>الرقم</th><th>التاريخ</th><th>الإجمالي</th>
                                <th>المتبقّي</th><th>الحالة</th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($invoices as $invoice): ?>
                                    <tr>
                                        <td>
                                            <a class="numeric fw-bold" dir="ltr"
                                               href="<?= e(url('/app/invoices/' . $invoice['id'])) ?>">
                                                <?= e($invoice['invoice_number']) ?></a>
                                        </td>
                                        <td class="fs-sm"><?= e(format_date((string) $invoice['issue_date'])) ?></td>
                                        <td class="numeric fs-sm"><?= e(money((float) $invoice['total'])) ?></td>
                                        <td class="numeric fs-sm"><?= e(money((float) $invoice['balance_due'])) ?></td>
                                        <td class="fs-xs text-muted-np"><?= e((string) $invoice['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- سجلّ التواصل | Activity log -->
        <div class="np-card">
            <div class="np-card__header"><h2 class="h6 mb-0">سجلّ التواصل</h2></div>
            <div class="np-card__body">
                <form method="post" action="<?= e(url('/app/pipeline/activities')) ?>" class="row g-2 mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="customer_id" value="<?= e((string) $customerId) ?>">
                    <div class="col-md-3">
                        <label class="visually-hidden" for="activity_type">النوع</label>
                        <select class="form-select form-select-sm" id="activity_type" name="activity_type">
                            <?php foreach (['call' => 'مكالمة', 'visit' => 'زيارة', 'meeting' => 'اجتماع',
                                            'message' => 'رسالة', 'note' => 'ملاحظة'] as $key => $label): ?>
                                <option value="<?= e($key) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="visually-hidden" for="subject_ar">الموضوع</label>
                        <input type="text" class="form-control form-control-sm" id="subject_ar"
                               name="subject_ar" required maxlength="200" placeholder="موضوع التواصل…">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-sm btn-outline-primary w-100">تسجيل</button>
                    </div>
                </form>

                <?php if ($activities === []): ?>
                    <p class="fs-sm text-muted-np mb-0">لا تواصل مسجَّل بعد.</p>
                <?php else: ?>
                    <ol class="list-unstyled mb-0">
                        <?php foreach ($activities as $activity): ?>
                            <li class="step-item">
                                <span class="step-item__marker" aria-hidden="true">
                                    <?= (string) $activity['status'] === 'planned' ? '◌' : '✓' ?>
                                </span>
                                <div class="flex-grow-1">
                                <div class="d-flex justify-content-between gap-2">
                                    <strong class="fs-sm">
                                        <?= e($pipeline->activityTypeLabel((string) $activity['activity_type'])) ?>:
                                        <?= e($activity['subject_ar']) ?>
                                    </strong>
                                    <span class="fs-xs text-muted-np">
                                        <?= e(format_date((string) ($activity['occurred_at']
                                            ?? $activity['due_at'] ?? $activity['created_at']), true)) ?>
                                    </span>
                                </div>
                                <?php if (!empty($activity['body_ar'])): ?>
                                    <p class="fs-sm mb-0 mt-1"><?= nl2br(e($activity['body_ar'])) ?></p>
                                <?php endif; ?>
                                <?php if ((string) $activity['status'] === 'planned'): ?>
                                    <span class="np-badge np-badge--pending mt-1">مهمة مخطَّطة</span>
                                <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <!-- بيانات الاتصال | Contact details -->
        <div class="np-card mb-3">
            <div class="np-card__header"><h2 class="h6 mb-0">بيانات العميل</h2></div>
            <div class="np-card__body">
                <dl class="row mb-0 fs-sm">
                    <dt class="col-5">الهاتف</dt>
                    <dd class="col-7 numeric" dir="ltr"><?= e($customer['phone'] ?? '—') ?></dd>
                    <dt class="col-5">البريد</dt>
                    <dd class="col-7" dir="ltr"><?= e($customer['email'] ?? '—') ?></dd>
                    <dt class="col-5">الرقم الضريبي</dt>
                    <dd class="col-7 numeric" dir="ltr"><?= e($customer['tax_number'] ?? '—') ?></dd>
                    <dt class="col-5">المحافظة</dt>
                    <dd class="col-7"><?= e($customer['governorate_name'] ?? '—') ?></dd>
                    <dt class="col-5">العنوان</dt>
                    <dd class="col-7"><?= e($customer['address'] ?? '—') ?></dd>
                </dl>
                <?php if (!empty($customer['notes_ar'])): ?>
                    <hr>
                    <p class="fs-sm mb-0"><?= nl2br(e($customer['notes_ar'])) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- جهات الاتصال | Contacts -->
        <div class="np-card mb-3">
            <div class="np-card__header"><h2 class="h6 mb-0">جهات الاتصال</h2></div>
            <div class="np-card__body">
                <?php if ($contacts === []): ?>
                    <p class="fs-sm text-muted-np">لا جهات اتصال مسجَّلة.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-3">
                        <?php foreach ($contacts as $contact): ?>
                            <li class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <div>
                                    <strong class="fs-sm"><?= e($contact['name_ar']) ?></strong>
                                    <?php if ((int) $contact['is_primary'] === 1): ?>
                                        <span class="np-badge np-badge--info">رئيسية</span>
                                    <?php endif; ?>
                                    <div class="fs-xs text-muted-np">
                                        <?= e($contact['job_title_ar'] ?? '') ?>
                                        <?php if (!empty($contact['phone'])): ?>
                                            · <span class="numeric" dir="ltr"><?= e($contact['phone']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <form method="post"
                                      action="<?= e(url('/app/customers/' . $customerId . '/contacts/remove')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="contact_id" value="<?= e((string) $contact['id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            data-confirm="حذف جهة الاتصال؟">حذف</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <form method="post" action="<?= e(url('/app/customers/' . $customerId . '/contacts')) ?>"
                      class="row g-2">
                    <?= csrf_field() ?>
                    <div class="col-md-6">
                        <label class="visually-hidden" for="contact_name">الاسم</label>
                        <input type="text" class="form-control form-control-sm" id="contact_name"
                               name="name_ar" required maxlength="150" placeholder="الاسم">
                    </div>
                    <div class="col-md-6">
                        <label class="visually-hidden" for="contact_title">الوظيفة</label>
                        <input type="text" class="form-control form-control-sm" id="contact_title"
                               name="job_title_ar" maxlength="120" placeholder="الوظيفة">
                    </div>
                    <div class="col-md-6">
                        <label class="visually-hidden" for="contact_phone">الهاتف</label>
                        <input type="tel" class="form-control form-control-sm numeric" dir="ltr"
                               id="contact_phone" name="phone" maxlength="30" placeholder="الهاتف">
                    </div>
                    <div class="col-md-6 d-flex align-items-center gap-2">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" id="contact_primary"
                                   name="is_primary" value="1">
                            <label class="form-check-label fs-sm" for="contact_primary">رئيسية</label>
                        </div>
                        <button type="submit" class="btn btn-sm btn-outline-primary ms-auto">إضافة</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- الفرص | Opportunities -->
        <div class="np-card mb-3">
            <div class="np-card__header"><h2 class="h6 mb-0">الفرص المرتبطة</h2></div>
            <div class="np-card__body">
                <?php if ($opportunities === []): ?>
                    <p class="fs-sm text-muted-np mb-0">لا فرص مفتوحة مع هذا العميل.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($opportunities as $opportunity): ?>
                            <li class="mb-2">
                                <a class="fs-sm fw-bold"
                                   href="<?= e(url('/app/pipeline/opportunities/' . $opportunity['id'])) ?>">
                                    <?= e($opportunity['title_ar']) ?></a>
                                <span class="np-badge <?= e($pipeline->stageBadgeClass((string) $opportunity['stage'])) ?>">
                                    <?= e($pipeline->stageLabel((string) $opportunity['stage'])) ?></span>
                                <?php if ($opportunity['expected_value'] !== null): ?>
                                    <div class="fs-xs text-muted-np">
                                        قيمة متوقّعة: <?= e(money((float) $opportunity['expected_value'])) ?>
                                        <span class="text-muted-np">(تقديرك)</span>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <form method="post" action="<?= e(url('/app/customers/' . $customerId . '/archive')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm btn-outline-danger w-100"
                    data-confirm="أرشفة هذا العميل؟ لن يظهر في القوائم بعدها.">أرشفة العميل</button>
        </form>
    </div>
</div>
