<?php
/**
 * رسائل النظام المؤقتة | Flash messages.
 * @var array<int,array{type:string,message:string}> $flash
 */

if (empty($flash)) {
    return;
}
?>
<div class="container mt-3">
    <?php foreach ($flash as $message): ?>
        <?php
        $type = in_array($message['type'], ['success', 'danger', 'warning', 'info'], true)
            ? $message['type']
            : 'info';
        ?>
        <div class="alert alert-<?= e($type) ?> alert-dismissible fade show" role="alert">
            <?= e($message['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"
                    aria-label="<?= __e('common.close') ?>"></button>
        </div>
    <?php endforeach; ?>
</div>
