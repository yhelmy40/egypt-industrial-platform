<?php
/**
 * الأسئلة الشائعة | Frequently asked questions (§4.11).
 *
 * الإجابات نُقّيت عند الحفظ، فتُطبع بتنسيقها.
 *
 * @var array<string,array<int,array<string,mixed>>> $grouped
 * @var \App\Services\ContentService $service
 */
?>
<section class="hero" style="padding:2.5rem 0">
    <div class="container">
        <h1>الأسئلة الشائعة</h1>
        <p class="mb-0">إجابات مختصرة عن أكثر ما يُسأل عنه في المنصة.</p>
    </div>
</section>

<section class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <?php if ($grouped === []): ?>
                <div class="np-empty">
                    <div class="np-empty__icon" aria-hidden="true">❓</div>
                    <p class="mb-1">لا أسئلة منشورة بعد.</p>
                    <p class="fs-sm mb-0">
                        يمكنك مراسلتنا من <a href="<?= e(url('/contact')) ?>">صفحة التواصل</a>.
                    </p>
                </div>
            <?php else: ?>
                <?php foreach ($grouped as $section => $items): ?>
                    <h2 class="h5 mb-3 mt-4"><?= e($service->sectionLabel((string) $section)) ?></h2>

                    <div class="accordion mb-3" id="faq-<?= e((string) $section) ?>">
                        <?php foreach ($items as $index => $faq): ?>
                            <?php $id = 'faq-' . (int) $faq['id']; ?>
                            <div class="accordion-item">
                                <h3 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#<?= e($id) ?>"
                                            aria-expanded="false" aria-controls="<?= e($id) ?>">
                                        <?= e($faq['question_ar']) ?>
                                    </button>
                                </h3>
                                <div id="<?= e($id) ?>" class="accordion-collapse collapse"
                                     data-bs-parent="#faq-<?= e((string) $section) ?>">
                                    <div class="accordion-body np-prose fs-sm">
                                        <?= $faq['answer_ar'] ?>
                                        <?php if ((int) $faq['is_demo'] === 1): ?>
                                            <span class="np-badge np-badge--muted">بيانات تجريبية</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <p class="text-center fs-sm text-muted-np mt-4 mb-0">
                لم تجد إجابتك؟ تصفّح <a href="<?= e(url('/knowledge')) ?>">مركز المعرفة</a>
                أو <a href="<?= e(url('/contact')) ?>">تواصل معنا</a>.
            </p>
        </div>
    </div>
</section>
