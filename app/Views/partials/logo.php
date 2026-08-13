<?php
/**
 * شعار مؤقت قابل للاستبدال | Configurable placeholder logo (§5).
 *
 * تصميم محايد لا ينتحل أي شعار رسمي. يُستبدل بالهوية الرسمية عند تسليمها
 * عبر تغيير config('app.operator.logo') فقط.
 * A neutral mark that impersonates no official logo. Replaced by the official
 * brand asset via config('app.operator.logo') alone.
 */
?>
<svg class="portal-brand__mark" viewBox="0 0 48 48" role="img" aria-label="<?= __e('portal.platform_name') ?>" focusable="false">
    <rect width="48" height="48" rx="11" fill="currentColor" opacity=".1"></rect>
    <path d="M13 34V15c0-.6.7-.9 1.1-.5l19 19c.4.4.1 1.1-.5 1.1H14a1 1 0 0 1-1-1Z"
          fill="currentColor" opacity=".85"></path>
    <path d="M35 14v19c0 .6-.7.9-1.1.5l-6.4-6.4a1 1 0 0 1 0-1.4l6.4-6.4c.4-.4 1.1-.1 1.1.5Z"
          fill="#0f8a6a"></path>
</svg>
