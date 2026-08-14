<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Repositories\ContentRepository;
use App\Services\ContentService;
use Tests\TestCase;

/**
 * بوابة نشر المحتوى | The content publication gate (§4.11).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * القاعدة المحروسة: **الكتابة ليست النشر.** ما يظهر باسم المبادرة يُنسب
 * إليها، فلا يخرج إلا بقرار موثّق باسم متّخذه — نفس القاعدة التي سرت على
 * المنتجات التمويلية وباقات الخدمات في المرحلة الرابعة.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class ContentPublishingTest extends TestCase
{
    private ContentService $service;

    private ContentRepository $content;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ContentService();
        $this->content = new ContentRepository();
    }

    // ═══════════════════ المسودة لا تظهر | A draft is invisible ═══════════════════

    /** المادة تُنشأ مسودة دائماً | Content is always created as a draft. */
    public function test_an_article_is_always_created_as_a_draft(): void
    {
        $id      = $this->service->createArticle($this->payload(), $this->createUser());
        $article = $this->content->findArticle($id);

        $this->assertSame('draft', $article['status']);
        $this->assertNull($article['published_at']);
        $this->assertNull($article['published_by']);
    }

    /** المسودة لا يبلغها الوجه العام | A draft never reaches the public query. */
    public function test_a_draft_is_absent_from_the_public_query(): void
    {
        $id      = $this->service->createArticle($this->payload(), $this->createUser());
        $article = $this->content->findArticle($id);

        $this->assertNull($this->content->publicArticle((string) $article['slug']));
        $this->assertSame([], $this->content->publicArticles()['data']);
    }

    /** المادة المرسلة للمراجعة لا تظهر بعد | Pending review is still not public. */
    public function test_a_pending_article_is_still_not_public(): void
    {
        $id = $this->submittedArticle();

        $this->assertSame([], $this->content->publicArticles()['data']);
    }

    /** المنشور وحده يظهر | Only published content is public. */
    public function test_only_published_content_becomes_public(): void
    {
        $id = $this->submittedArticle();
        $this->service->decideArticle($id, 'published', null, $this->createUser());

        $article = $this->content->findArticle($id);

        $this->assertSame('published', $article['status']);
        $this->assertNotNull($article['published_at']);
        $this->assertNotNull($article['published_by']);
        $this->assertCount(1, $this->content->publicArticles()['data']);
        $this->assertNotNull($this->content->publicArticle((string) $article['slug']));
    }

    // ═══════════════════ شروط الإرسال والقرار | Submission and decision ═══════════════════

    /** الإرسال يستوجب اكتمال المادة | Submission requires a complete article. */
    public function test_an_incomplete_article_cannot_be_submitted(): void
    {
        $id = $this->service->createArticle([
            'title_ar' => 'مقال ناقص',
            'body_ar'  => 'نصّ قصير جداً.',
        ], $this->createUser());

        try {
            $this->service->submitArticle($id, $this->createUser());
            $this->fail('كان يجب رفض إرسال مادة ناقصة.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('٢٠٠ حرف', $e->getMessage());
            $this->assertStringContainsString('التصنيف', $e->getMessage());
        }

        $this->assertSame('draft', $this->content->findArticle($id)['status']);
    }

    /** الرفض يستوجب سبباً | Rejection requires a reason. */
    public function test_rejection_requires_a_written_reason(): void
    {
        $id = $this->submittedArticle();

        try {
            $this->service->decideArticle($id, 'rejected', '   ', $this->createUser());
            $this->fail('كان يجب رفض القرار بلا سبب.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame('pending_review', $this->content->findArticle($id)['status']);
    }

    /** الرفض يمسح ختم النشر | Rejection clears the publication stamp. */
    public function test_rejection_clears_the_publication_stamp(): void
    {
        $id        = $this->submittedArticle();
        $publisher = $this->createUser();

        $this->service->decideArticle($id, 'published', null, $publisher);
        $this->assertNotNull($this->content->findArticle($id)['published_by']);

        // تعديل ثم رفض
        $this->service->updateArticle($id, $this->payload(['title_ar' => 'عنوان معدَّل']), $publisher);
        $this->service->decideArticle($id, 'rejected', 'المعلومة الثالثة غير دقيقة.', $publisher);

        $article = $this->content->findArticle($id);

        $this->assertSame('rejected', $article['status']);
        $this->assertNull($article['published_by']);
        $this->assertNull($article['published_at']);
        $this->assertSame([], $this->content->publicArticles()['data']);
    }

    /** لا قرار على مادة ليست في الطابور | No decision outside the review queue. */
    public function test_no_decision_on_an_article_outside_the_queue(): void
    {
        $id = $this->service->createArticle($this->payload(), $this->createUser());

        $this->expectException(HttpException::class);
        $this->service->decideArticle($id, 'published', null, $this->createUser());
    }

    // ═══════════════════ التعديل بعد النشر | Editing after publication ═══════════════════

    /** التعديل بعد النشر يُعيد المادة للطابور | Editing a published article requeues it. */
    public function test_editing_a_published_article_returns_it_to_the_queue(): void
    {
        $id = $this->submittedArticle();
        $this->service->decideArticle($id, 'published', null, $this->createUser());

        $this->assertCount(1, $this->content->publicArticles()['data']);

        $this->service->updateArticle(
            $id,
            $this->payload(['body_ar' => str_repeat('نصّ مختلف تماماً عمّا رُوجع. ', 30)]),
            $this->createUser(),
        );

        $article = $this->content->findArticle($id);

        $this->assertSame('pending_review', $article['status']);
        $this->assertNull($article['published_by']);

        // والأهمّ: اختفت من الوجه العام حتى تُراجَع من جديد
        $this->assertSame([], $this->content->publicArticles()['data']);
    }

    /** الأرشفة تُخفي المادة | Archiving removes it from public view. */
    public function test_archiving_removes_the_article_from_public_view(): void
    {
        $id = $this->submittedArticle();
        $this->service->decideArticle($id, 'published', null, $this->createUser());

        $this->service->archiveArticle($id, $this->createUser());

        $this->assertSame('archived', $this->content->findArticle($id)['status']);
        $this->assertSame([], $this->content->publicArticles()['data']);
    }

    // ═══════════════════ التنقية | Sanitisation ═══════════════════

    /** النصّ يُنقَّى عند الحفظ لا عند العرض | Content is sanitised on save. */
    public function test_article_bodies_are_sanitised_on_save(): void
    {
        $id = $this->service->createArticle($this->payload([
            'body_ar' => '<p>نصّ سليم</p><script>alert(1)</script>'
                       . '<a href="javascript:alert(2)">رابط</a>',
        ]), $this->createUser());

        // القراءة المباشرة من قاعدة البيانات: النصّ نُقّي قبل التخزين
        $stored = (string) Database::scalar('SELECT body_ar FROM articles WHERE id = ?', [$id]);

        $this->assertStringNotContainsString('script', strtolower($stored));
        $this->assertStringNotContainsString('javascript', strtolower($stored));
        $this->assertStringContainsString('نصّ سليم', $stored);
    }

    /** إجابة السؤال تُنقَّى كذلك | FAQ answers are sanitised too. */
    public function test_faq_answers_are_sanitised_on_save(): void
    {
        $id = $this->service->saveFaq(null, [
            'question_ar' => 'سؤال',
            'answer_ar'   => '<p>إجابة</p><script>steal()</script>',
            'section'     => 'general',
        ], $this->createUser());

        $stored = (string) Database::scalar('SELECT answer_ar FROM faqs WHERE id = ?', [$id]);

        $this->assertStringNotContainsString('script', strtolower($stored));
        $this->assertStringContainsString('إجابة', $stored);
    }

    // ═══════════════════ الأسئلة الشائعة | FAQs ═══════════════════

    /** السؤال يمرّ بالبوابة نفسها | An FAQ passes the same gate. */
    public function test_an_faq_follows_the_same_gate(): void
    {
        $id = $this->service->saveFaq(null, [
            'question_ar' => 'كيف أوثّق منشأتي؟',
            'answer_ar'   => 'ارفع المستندات المطلوبة من صفحة المستندات.',
            'section'     => 'account',
        ], $this->createUser());

        $this->assertSame([], $this->content->publicFaqs());

        $this->service->submitFaq($id);
        $this->assertSame([], $this->content->publicFaqs());

        $this->service->decideFaq($id, 'published', null, $this->createUser());

        $grouped = $this->content->publicFaqs();
        $this->assertArrayHasKey('account', $grouped);
        $this->assertCount(1, $grouped['account']);
    }

    // ═══════════════════ الصفحات الثابتة | Static pages ═══════════════════

    /** الصفحة المعدَّلة تعود للطابور والزائر يبقى على المنشور | Editing requeues the page. */
    public function test_editing_a_published_page_requeues_it(): void
    {
        // الصفحة موجودة من البذرة المرجعية — وهي تُزرع في الاختبارات كما في
        // الإنتاج، فالاختبار يجري على الصفّ الحقيقي لا على صفّ مُصطنَع.
        $this->assertNotNull($this->content->publicPage('terms'));

        $this->service->savePage('terms', [
            'title_ar' => 'الشروط',
            'body_ar'  => '<p>نصّ جديد لم يُراجَع</p>',
        ], $this->createUser());

        $page = $this->content->findPageBySlug('terms');

        $this->assertSame('pending_review', $page['status']);
        $this->assertNull($this->content->publicPage('terms'));
    }

    /** صفحة غير معروفة مرفوضة | An unknown page slug is refused. */
    public function test_an_unknown_page_slug_is_refused(): void
    {
        $this->expectException(HttpException::class);
        $this->service->savePage('anything', ['title_ar' => 'س', 'body_ar' => 'ص'], null);
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /** @param array<string,mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title_ar'    => 'كيف تجهّز مشروعك لطلب تمويل',
            'excerpt_ar'  => 'خطوات عملية قبل التقديم.',
            'body_ar'     => str_repeat('نصّ إرشادي كافٍ لتجاوز الحدّ الأدنى للطول. ', 20),
            'category_id' => $this->articleCategoryId(),
        ], $overrides);
    }

    private function articleCategoryId(): int
    {
        $id = Database::scalar("SELECT id FROM categories WHERE type = 'article' LIMIT 1");

        if ($id === null) {
            $id = Database::insert(
                "INSERT INTO categories (type, code, name_ar, is_active) VALUES ('article', ?, ?, 1)",
                ['art_test_' . bin2hex(random_bytes(3)), 'تصنيف اختباري'],
            );
        }

        return (int) $id;
    }

    private function submittedArticle(): int
    {
        $id = $this->service->createArticle($this->payload(), $this->createUser());
        $this->service->submitArticle($id, $this->createUser());

        return $id;
    }
}
