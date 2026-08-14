<?php
/**
 * قائمة الاقتراحات | Match suggestions with their reasons (§4.7).
 *
 * الأسباب تُعرض دائماً مع الاقتراح لا خلف زر: اقتراح بلا تفسير صندوق أسود.
 * الأسطر المبدوءة بـ ⚠ فجوات لا مزايا، وتُميَّز بصرياً لهذا السبب.
 *
 * @var array<int,array<string,mixed>> $suggestions
 * @var string $hrefPrefix
 * @var string $emptyMessage
 */
?>
<?php if ($suggestions === []): ?>
    <div class="np-empty py-4">
        <p class="fs-sm mb-0"><?= e($emptyMessage) ?></p>
    </div>
<?php else: ?>
    <?php foreach ($suggestions as $suggestion): ?>
        <?php
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", (string) $suggestion['reasons_ar'])),
            static fn (string $line): bool => $line !== '',
        ));
        ?>
        <div class="p-3 border-bottom border-np">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div class="flex-grow-1">
                    <a class="fw-bold" href="<?= e(url($hrefPrefix . $suggestion['slug'])) ?>">
                        <?= e($suggestion['name_ar']) ?></a>
                    <div class="fs-xs text-muted-np">
                        <?= e($suggestion['trading_name'] ?: $suggestion['legal_name']) ?>
                    </div>
                </div>
                <span class="np-badge <?= (int) $suggestion['score'] >= 65
                    ? 'np-badge--success' : 'np-badge--info' ?>">
                    مطابقة <?= e(number_ar((int) $suggestion['score'])) ?>٪
                </span>
            </div>

            <ul class="fs-sm mt-2 mb-0 ps-3">
                <?php foreach ($lines as $line): ?>
                    <?php $isGap = str_starts_with($line, '⚠'); ?>
                    <li class="<?= $isGap ? 'text-muted-np' : '' ?>">
                        <?= e($isGap ? ltrim($line, '⚠ ') : $line) ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <form method="post"
                  action="<?= e(url('/app/assessment/suggestions/' . $suggestion['id'] . '/dismiss')) ?>"
                  class="mt-2">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-link text-muted-np p-0 fs-xs">
                    لا يناسبني — أخفِ هذا الاقتراح</button>
            </form>
        </div>
    <?php endforeach; ?>

    <div class="p-3">
        <p class="fs-xs text-muted-np mb-0">
            درجة المطابقة ترتيب استرشادي مبني على بياناتك المعلنة، وليست تقييماً ائتمانياً
            ولا مؤشراً على قبول أي جهة لطلبك. يمكنك التقديم على أي منتج بصرف النظر عن درجته.
        </p>
    </div>
<?php endif; ?>
