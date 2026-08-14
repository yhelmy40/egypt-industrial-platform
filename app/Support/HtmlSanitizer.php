<?php

declare(strict_types=1);

namespace App\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * تنقية HTML للمحتوى المحرَّر | HTML sanitiser for edited content (§10, §16).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * محتوى المنصة (المقالات والصفحات الثابتة والأسئلة الشائعة) يُكتب بتنسيق
 * غنيّ، فيُحفظ HTML ويُعرض دون هروب. هذا **مسار حقن نصّي مباشر** لو تُرك بلا
 * تنقية: محرّر — أو حساب محرّر مُخترَق — يزرع سكربتاً يُنفَّذ في متصفّح كل
 * زائر، ومنه سرقة الجلسات.
 *
 * سياسة أمن المحتوى (`script-src 'self'` بلا `unsafe-inline`) تحجب السكربت
 * السطري فعلاً، لكن **الاعتماد على طبقة واحدة خطأ**: ترويسة واحدة تسقط في
 * إعداد خادم خاطئ فيسقط الحاجز كله. التنقية هنا هي الطبقة الثانية.
 *
 * المنهج **قائمة سماح لا قائمة منع**: كل وسم وسمة غير مذكورين صراحةً
 * يُزالان. قائمة المنع تُهزم دائماً بوسم لم يخطر ببال كاتبها.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class HtmlSanitizer
{
    /**
     * الوسوم المسموحة | Allowed tags.
     *
     * لا `script` ولا `iframe` ولا `object` ولا `embed` ولا `form` ولا `style`:
     * الأربعة الأولى تنفّذ أو تُضمّن، و`form` يزرع نموذجاً يوجّه بيانات المستخدم
     * لخارج المنصة، و`style` يسمح بإخفاء عناصر أو تغطية الصفحة.
     *
     * @var array<string,array<int,string>> الوسم => سماته المسموحة
     */
    private const ALLOWED = [
        'p'          => [],
        'br'         => [],
        'hr'         => [],
        'strong'     => [],
        'b'          => [],
        'em'         => [],
        'i'          => [],
        'u'          => [],
        'h2'         => [],
        'h3'         => [],
        'h4'         => [],
        'ul'         => [],
        'ol'         => [],
        'li'         => [],
        'blockquote' => [],
        'a'          => ['href', 'title', 'target', 'rel'],
        'span'       => ['dir'],
        'div'        => ['dir'],
        'table'      => [],
        'thead'      => [],
        'tbody'      => [],
        'tr'         => [],
        'th'         => [],
        'td'         => [],
        'code'       => [],
        'pre'        => [],
        'figure'     => [],
        'figcaption' => [],
        'img'        => ['src', 'alt', 'width', 'height'],
    ];

    /** مخطّطات الروابط المسموحة | Allowed URL schemes. */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * وسوم تُحذف بمحتواها | Tags dropped together with their contents.
     *
     * هذان وحدهما لأن **محتواهما هو الحمولة** لا نصّاً يُقرأ. أي وسم آخر غير
     * مسموح يُستبدل بمحتواه، فلا يُفقد نصّ مشروع بسبب وسم تنسيق واحد.
     */
    private const DROP_WITH_CONTENTS = ['script', 'style'];

    /**
     * تنقية نصّ HTML | Sanitise an HTML fragment.
     */
    public static function clean(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');

        // libxml تُصدر تحذيرات على HTML5؛ نلتقطها بدل طباعتها
        $previous = libxml_use_internal_errors(true);

        // الترميز يُفرَض بترويسة صريحة: بدونها تُفسَّر العربية كـ ISO-8859-1
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="np-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('np-root');

        if (!$root instanceof DOMElement) {
            // تعذّر التحليل: نعيد نصّاً مهروباً لا HTML غير منقّى
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        self::cleanNode($root, $document);

        $output = '';

        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

    /**
     * تنقية عقدة وأبنائها | Clean a node and its descendants.
     *
     * التكرار يمشي على نسخة من قائمة الأبناء: حذف عقدة أثناء المرور على
     * `childNodes` الحيّة يُسقط العقدة التالية من الفحص.
     */
    private static function cleanNode(DOMNode $node, DOMDocument $document): void
    {
        $children = [];

        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->nodeName);

                if (!array_key_exists($tag, self::ALLOWED)) {
                    // `script` و`style` **محتواهما هو الحمولة**، فيُحذفان به.
                    if (in_array($tag, self::DROP_WITH_CONTENTS, true)) {
                        $node->removeChild($child);

                        continue;
                    }

                    // ما عداهما يُستبدل بمحتواه: الوسم يختفي والنصّ يبقى.
                    // الأهمّ أن هذا يحمي من فقد المحتوى بسبب تحليل libxml —
                    // فهي تعدّ `<embed>` حاوية فتُدخِل الفقرة التالية داخله،
                    // وحذفه بمحتواه كان سيبتلع فقرة مشروعة معه.
                    self::cleanNode($child, $document);
                    self::unwrap($child, $document);

                    continue;
                }

                self::cleanAttributes($child, $tag);
                self::cleanNode($child, $document);
            } elseif (
                !($child instanceof \DOMText)
                && !($child instanceof \DOMCdataSection)
            ) {
                // تعليقات وتعليمات معالجة: لا قيمة لها ولا تُعرض
                $node->removeChild($child);
            }
        }
    }

    /** إزالة السمات غير المسموحة | Strip attributes outside the allow-list. */
    private static function cleanAttributes(DOMElement $element, string $tag): void
    {
        $allowed    = self::ALLOWED[$tag];
        $attributes = [];

        foreach ($element->attributes as $attribute) {
            if ($attribute instanceof DOMAttr) {
                $attributes[] = $attribute;
            }
        }

        foreach ($attributes as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (!in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if (in_array($name, ['href', 'src'], true)
                && !self::isSafeUrl($attribute->nodeValue ?? '')
            ) {
                $element->removeAttribute($attribute->nodeName);
            }
        }

        // رابط خارجي يُفتح في تبويب جديد يجب أن يحمل rel المانع لـ window.opener
        if ($tag === 'a' && $element->getAttribute('target') !== '') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /**
     * هل الرابط آمن؟ | Is the URL safe?
     *
     * `javascript:` و`data:` منفذا تنفيذ. الروابط النسبية مسموحة لأنها داخل
     * المنصة.
     */
    private static function isSafeUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        // إزالة المحارف الضابطة التي تُخفي المخطّط: "java\0script:" مثلاً
        $normalised = strtolower(preg_replace('/[\x00-\x20]+/', '', $url) ?? '');

        if (!str_contains($normalised, ':')) {
            return true; // رابط نسبي
        }

        if (str_starts_with($normalised, '//')) {
            return true; // بروتوكول موروث
        }

        $scheme = strstr($normalised, ':', true);

        return $scheme !== false && in_array($scheme, self::ALLOWED_SCHEMES, true);
    }

    /** استبدال عنصر بمحتواه | Replace an element with its children. */
    private static function unwrap(DOMElement $element, DOMDocument $document): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    /** @return array<int,string> الوسوم المسموحة، للعرض في واجهة التحرير */
    public static function allowedTags(): array
    {
        return array_keys(self::ALLOWED);
    }
}
