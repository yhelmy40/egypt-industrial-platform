<?php
/**
 * functions.php
 * دوال مساعدة عامة | Global helper functions.
 */

/** المسار الأساسي المكتشف تلقائياً | Auto-detected base path */
function base_path(): string
{
    if (defined('BASE_URL') && BASE_URL !== '') {
        return rtrim(BASE_URL, '/');
    }
    // اكتشاف من SCRIPT_NAME (.../public/index.php => .../public)
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = str_replace('\\', '/', dirname($script));
    return ($base === '/' || $base === '.') ? '' : rtrim($base, '/');
}

/** توليد رابط داخلي | Build an internal URL */
function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $base = base_path();
    // عند تفعيل .htaccess تكون الروابط نظيفة | clean URLs with .htaccess
    return $base . '/' . $path;
}

/** رابط أصول ثابتة | Asset URL */
function asset(string $path): string
{
    return base_path() . '/assets/' . ltrim($path, '/');
}

/** تهريب آمن للإخراج (XSS) | Escape output for HTML */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** قيمة سابقة بعد فشل التحقق | Old input value after validation failure */
function old(string $key, $default = ''): string
{
    return e($_SESSION['_old'][$key] ?? $default);
}

/** هل القيمة السابقة تساوي؟ (للقوائم) | helper for selects/radios */
function old_is(string $key, $value): bool
{
    return ($_SESSION['_old'][$key] ?? null) == $value;
}

/** تخزين المدخلات السابقة | Store old input for redisplay */
function store_old(array $input): void
{
    // لا نخزّن كلمات المرور | never store passwords
    unset($input['password'], $input['password_confirm'], $input['csrf_token']);
    $_SESSION['_old'] = $input;
}

function clear_old(): void
{
    unset($_SESSION['_old']);
}

/** عرض رسائل الفلاش | Render flash messages (Bootstrap alerts) */
function render_flash(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $html = '';
    foreach ($_SESSION['flash'] as $f) {
        $type = e($f['type']);
        $msg  = e($f['message']);
        $html .= "<div class='alert alert-{$type} alert-dismissible fade show' role='alert'>
                    {$msg}
                    <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='إغلاق'></button>
                  </div>";
    }
    unset($_SESSION['flash']);
    return $html;
}

/** تنسيق التاريخ بالعربي | Format date */
function fmt_date(?string $date, bool $withTime = false): string
{
    if (empty($date) || $date === '0000-00-00') {
        return '—';
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return e($date);
    }
    return date($withTime ? 'Y-m-d H:i' : 'Y-m-d', $ts);
}

/** تنسيق المبلغ المالي | Format currency (EGP) */
function fmt_money($amount): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    return number_format((float) $amount, 0) . ' ج.م';
}

/** الاسم العربي لحالة التحدي | Arabic label for a challenge status */
function challenge_status_label(string $status): string
{
    return [
        'pending'      => 'بانتظار المراجعة',
        'open'         => 'مفتوح',
        'under_review' => 'قيد المراجعة',
        'matched'      => 'تم الربط',
        'in_progress'  => 'قيد التنفيذ',
        'solved'       => 'تم الحل',
        'rejected'     => 'مرفوض',
    ][$status] ?? $status;
}

/** لون شارة الحالة | Bootstrap badge color for status */
function challenge_status_color(string $status): string
{
    return [
        'pending'      => 'secondary',
        'open'         => 'info',
        'under_review' => 'warning',
        'matched'      => 'primary',
        'in_progress'  => 'warning',
        'solved'       => 'success',
        'rejected'     => 'danger',
    ][$status] ?? 'secondary';
}

function priority_label(string $p): string
{
    return ['low' => 'منخفضة', 'medium' => 'متوسطة', 'high' => 'عالية'][$p] ?? $p;
}

function priority_color(string $p): string
{
    return ['low' => 'success', 'medium' => 'warning', 'high' => 'danger'][$p] ?? 'secondary';
}

function project_status_label(string $s): string
{
    return [
        'planned'   => 'مخطط',
        'active'    => 'نشط',
        'completed' => 'مكتمل',
        'cancelled' => 'ملغي',
    ][$s] ?? $s;
}

function project_status_color(string $s): string
{
    return [
        'planned'   => 'info',
        'active'    => 'primary',
        'completed' => 'success',
        'cancelled' => 'danger',
    ][$s] ?? 'secondary';
}

function energy_label(string $e): string
{
    return ['low' => 'منخفض', 'medium' => 'متوسط', 'high' => 'مرتفع'][$e] ?? $e;
}

function knowledge_category_label(string $c): string
{
    return [
        'research'   => 'بحث علمي',
        'patent'     => 'براءة اختراع',
        'case_study' => 'دراسة حالة',
        'regulation' => 'تشريع / لائحة',
        'funding'    => 'تمويل',
        'guide'      => 'دليل إرشادي',
    ][$c] ?? $c;
}
