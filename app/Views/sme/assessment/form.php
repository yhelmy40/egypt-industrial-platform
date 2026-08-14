<?php
/**
 * استبيان التقييم | The assessment questionnaire (§4.7).
 *
 * @var array<string,mixed> $assessment
 * @var array<string,array<int,array<string,mixed>>> $questions
 * @var array<int,array<string,mixed>> $answers
 * @var array<string,string> $sections
 * @var \App\Services\AssessmentService $service
 */
?>
<a class="fs-sm text-muted-np" href="<?= e(url('/app/assessment')) ?>">→ نتيجة التقييم</a>

<div class="np-card mt-1 mb-3">
    <div class="np-card__body">
        <h1 class="h5 mb-2">استبيان تقييم الاحتياجات</h1>
        <p class="fs-sm text-muted-np mb-0">
            أسئلة عن ممارسات مشروعك الفعلية لا عن نواياك. الإجابة الصريحة تنتج اقتراحات أنفع؛
            الإجابة المجاملة تنتج اقتراحات لا تحتاجها. النتيجة لا تُعرض لأي جهة تمويل ولا
            تؤثّر على قبول أي طلب.
        </p>
    </div>
</div>

<form method="post" action="<?= e(url('/app/assessment/' . $assessment['id'] . '/submit')) ?>" data-guard>
    <?= csrf_field() ?>

    <?php foreach ($questions as $section => $sectionQuestions): ?>
        <div class="np-card mb-3">
            <div class="np-card__header"><?= e($sections[$section] ?? $section) ?></div>
            <div class="np-card__body">
                <?php foreach ($sectionQuestions as $question): ?>
                    <?php
                    $id      = (int) $question['id'];
                    $current = $answers[$id]['answer_value'] ?? '';
                    $name    = 'answers[' . $id . ']';
                    ?>
                    <fieldset class="mb-4 pb-3 border-bottom border-np">
                        <legend class="form-label mb-1">
                            <?= e($question['question_ar']) ?>
                            <?php if ((int) $question['is_required'] === 1): ?>
                                <span class="required">*</span>
                            <?php endif; ?>
                        </legend>
                        <?php if (!empty($question['help_ar'])): ?>
                            <p class="form-text mt-0"><?= e($question['help_ar']) ?></p>
                        <?php endif; ?>

                        <?php if ($question['answer_type'] === 'boolean'): ?>
                            <?php foreach (['1' => 'نعم', '0' => 'لا'] as $optionValue => $optionLabel): ?>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio"
                                           name="<?= e($name) ?>" value="<?= e($optionValue) ?>"
                                           id="q<?= e((string) $id . '_' . $optionValue) ?>"
                                        <?= (string) $current === $optionValue ? ' checked' : '' ?>
                                        <?= (int) $question['is_required'] === 1 ? ' required' : '' ?>>
                                    <label class="form-check-label"
                                           for="q<?= e((string) $id . '_' . $optionValue) ?>">
                                        <?= e($optionLabel) ?></label>
                                </div>
                            <?php endforeach; ?>

                        <?php elseif ($question['answer_type'] === 'choice'): ?>
                            <?php
                            $options = array_values(array_filter(
                                array_map('trim', preg_split('/\r?\n/', (string) $question['options_ar']) ?: []),
                                static fn (string $o): bool => $o !== '',
                            ));
                            ?>
                            <?php foreach ($options as $optionIndex => $option): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="<?= e($name) ?>"
                                           value="<?= e($option) ?>"
                                           id="q<?= e((string) $id . '_' . $optionIndex) ?>"
                                        <?= (string) $current === $option ? ' checked' : '' ?>
                                        <?= (int) $question['is_required'] === 1 ? ' required' : '' ?>>
                                    <label class="form-check-label"
                                           for="q<?= e((string) $id . '_' . $optionIndex) ?>">
                                        <?= e($option) ?></label>
                                </div>
                            <?php endforeach; ?>

                        <?php elseif ($question['answer_type'] === 'scale'): ?>
                            <div class="d-flex flex-wrap gap-3 align-items-center">
                                <span class="fs-xs text-muted-np">١ = إطلاقاً</span>
                                <?php foreach ([1, 2, 3, 4, 5] as $point): ?>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="<?= e($name) ?>"
                                               value="<?= e((string) $point) ?>"
                                               id="q<?= e((string) $id . '_' . $point) ?>"
                                            <?= (string) $current === (string) $point ? ' checked' : '' ?>
                                            <?= (int) $question['is_required'] === 1 ? ' required' : '' ?>>
                                        <label class="form-check-label"
                                               for="q<?= e((string) $id . '_' . $point) ?>">
                                            <?= e(number_ar($point)) ?></label>
                                    </div>
                                <?php endforeach; ?>
                                <span class="fs-xs text-muted-np">٥ = تماماً</span>
                            </div>

                        <?php elseif ($question['answer_type'] === 'number'): ?>
                            <label class="visually-hidden" for="q<?= e((string) $id) ?>">
                                <?= e($question['question_ar']) ?></label>
                            <input type="number" class="form-control" dir="ltr" name="<?= e($name) ?>"
                                   id="q<?= e((string) $id) ?>" value="<?= e((string) $current) ?>"
                                <?= (int) $question['is_required'] === 1 ? ' required' : '' ?>>

                        <?php else: ?>
                            <label class="visually-hidden" for="q<?= e((string) $id) ?>">
                                <?= e($question['question_ar']) ?></label>
                            <textarea class="form-control" name="<?= e($name) ?>" id="q<?= e((string) $id) ?>"
                                      rows="2" maxlength="500"
                                <?= (int) $question['is_required'] === 1 ? ' required' : '' ?>
                            ><?= e((string) $current) ?></textarea>
                        <?php endif; ?>
                    </fieldset>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <button type="submit" class="btn btn-primary">إنهاء التقييم وعرض النتيجة</button>
</form>
