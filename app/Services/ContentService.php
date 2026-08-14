<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Repositories\ContentRepository;
use App\Support\HtmlSanitizer;

/**
 * خدمة المحتوى | Content service (§4.11).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * **القاعدة الحاكمة: الكتابة ليست النشر.**
 *
 * محرّر المحتوى يكتب ويرسل للمراجعة، ولا ينشر. النشر إجراء مستقلّ محروس
 * بصلاحية `content.item.publish`، ويُسجَّل باسم متّخذه وتاريخه.
 *
 * هذه نفس القاعدة التي سرت على المنتجات التمويلية وباقات الخدمات في المرحلة
 * الرابعة، والسبب واحد: **ما يظهر باسم المبادرة يُنسب إليها.** مقال إرشادي
 * خاطئ عن التمويل أو صياغة شروط غير مراجَعة تُلزم المبادرة بما لم تقصده.
 *
 * ولا تُطبَّق هنا `ApprovableCatalogService`: تلك مبنية على ملكية منشأة
 * (`organization_id`) وحدّ رؤية يفحص توثيق الجهة. المحتوى **ملك المنصة**،
 * فلا مالك ولا توثيق جهة — والقاعدة وحدها هي المشتركة، لا التنفيذ.
 *
 * ثلاثة قيود إضافية تحرس القاعدة:
 *  1. **التعديل بعد النشر يُعيد المادة للطابور.** نصّ مختلف لم يراجعه أحد.
 *  2. **الرفض يستوجب سبباً**، ويمسح ختم النشر فلا تبدو المادة معتمدة.
 *  3. **من يكتب لا يعتمد نفسه**: النشر صلاحية منفصلة عن الكتابة.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class ContentService
{
    /** الحالات التي يجوز فيها التحرير | Statuses that accept editing. */
    private const EDITABLE = ['draft', 'rejected'];

    /** أقسام الأسئلة الشائعة | FAQ sections. */
    public const FAQ_SECTIONS = [
        'general', 'account', 'marketplace', 'financing', 'services', 'bds', 'erp', 'privacy',
    ];

    /** الصفحات الثابتة التي يعرفها الكود | Static pages the code links to. */
    public const CORE_PAGES = ['about', 'terms', 'privacy'];

    public function __construct(
        private readonly ContentRepository $content = new ContentRepository(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    // ═══════════════════ المقالات | Articles ═══════════════════

    /**
     * إنشاء مقال | Create an article.
     *
     * يُنشأ **مسودة دائماً**. لا مسار في هذه الخدمة يُنشئ مادة منشورة مباشرةً.
     *
     * @param array<string,mixed> $data
     */
    public function createArticle(array $data, ?int $actorId): int
    {
        $payload = $this->validateArticle($data, null);

        return Database::insert(
            'INSERT INTO articles
                (title_ar, slug, excerpt_ar, body_ar, category_id, author_id,
                 author_name_ar, reading_minutes, is_featured, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $payload['title_ar'],
                $payload['slug'],
                $payload['excerpt_ar'],
                $payload['body_ar'],
                $payload['category_id'],
                $actorId,
                $payload['author_name_ar'],
                $payload['reading_minutes'],
                $payload['is_featured'],
                'draft',
            ],
        );
    }

    /**
     * تعديل مقال | Update an article.
     *
     * **التعديل بعد النشر يُعيد المادة للطابور.** نصّ تغيّر بعد الاعتماد نصّ
     * لم يراجعه أحد، وبقاؤه منشوراً يجعل الاعتماد ختماً على عنوان لا على محتوى.
     *
     * @param array<string,mixed> $data
     */
    public function updateArticle(int $id, array $data, ?int $actorId, ?Request $request = null): void
    {
        $article = $this->requireArticle($id);
        $payload = $this->validateArticle($data, $id);

        $wasPublished = (string) $article['status'] === 'published';
        $status       = $wasPublished ? 'pending_review' : (string) $article['status'];

        Database::statement(
            'UPDATE articles
                SET title_ar = ?, slug = ?, excerpt_ar = ?, body_ar = ?, category_id = ?,
                    author_name_ar = ?, reading_minutes = ?, is_featured = ?,
                    status = ?,
                    published_by = CASE WHEN ? = \'published\' THEN published_by ELSE NULL END,
                    published_at = CASE WHEN ? = \'published\' THEN published_at ELSE NULL END,
                    updated_at = NOW()
              WHERE id = ?',
            [
                $payload['title_ar'],
                $payload['slug'],
                $payload['excerpt_ar'],
                $payload['body_ar'],
                $payload['category_id'],
                $payload['author_name_ar'],
                $payload['reading_minutes'],
                $payload['is_featured'],
                $status,
                $status,
                $status,
                $id,
            ],
        );

        if ($wasPublished) {
            $this->audit->setRequest($request);
            $this->audit->logStatusChange(
                'article',
                $id,
                'published',
                'pending_review',
                AuditLogger::CATEGORY_CONFIG,
            );
        }
    }

    /** إرسال للمراجعة | Submit for review. */
    public function submitArticle(int $id, ?int $actorId, ?Request $request = null): void
    {
        $article = $this->requireArticle($id);

        if (!in_array((string) $article['status'], self::EDITABLE, true)) {
            throw new HttpException(422, 'هذه المادة ليست مسودة قابلة للإرسال.');
        }

        $missing = $this->missingForSubmission($article);

        if ($missing !== []) {
            throw new HttpException(
                422,
                'أكمل ما يلي قبل الإرسال للمراجعة: ' . implode('، ', $missing) . '.',
            );
        }

        Database::statement(
            "UPDATE articles SET status = 'pending_review', review_note_ar = NULL, updated_at = NOW()
              WHERE id = ?",
            [$id],
        );

        $this->notifyPublishers('مادة جديدة بانتظار النشر', (string) $article['title_ar'], $id);

        $this->audit->setRequest($request);
        $this->audit->logStatusChange(
            'article',
            $id,
            (string) $article['status'],
            'pending_review',
            AuditLogger::CATEGORY_CONFIG,
        );
    }

    /**
     * قرار النشر | The publication decision.
     *
     * **الرفض يمسح ختم النشر.** بقاء `published_by` على مادة مرفوضة يجعلها
     * تبدو معتمدة لأي استعلام يعتمد على العمود — نفس العيب الذي ظهر مع
     * `verified_at` في المرحلة الثانية ومُنع تكراره هنا باختبار.
     */
    public function decideArticle(
        int $id,
        string $decision,
        ?string $note,
        ?int $actorId,
        ?Request $request = null,
    ): void {
        $article = $this->requireArticle($id);

        if (!in_array($decision, ['published', 'rejected'], true)) {
            throw new HttpException(422, 'قرار غير معروف.');
        }

        if ((string) $article['status'] !== 'pending_review') {
            throw new HttpException(422, 'هذه المادة ليست في طابور المراجعة.');
        }

        if ($decision === 'rejected' && trim((string) $note) === '') {
            throw new HttpException(
                422,
                'اكتب سبب الرفض. رفض بلا سبب لا يُعلم المحرّر بما يصحّحه.',
            );
        }

        Database::statement(
            "UPDATE articles
                SET status = ?,
                    review_note_ar = ?,
                    published_by = CASE WHEN ? = 'published' THEN ? ELSE NULL END,
                    published_at = CASE WHEN ? = 'published' THEN NOW() ELSE NULL END,
                    updated_at = NOW()
              WHERE id = ?",
            [
                $decision,
                $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 1000),
                $decision,
                $actorId,
                $decision,
                $id,
            ],
        );

        $this->audit->setRequest($request);
        $this->audit->logStatusChange(
            'article',
            $id,
            'pending_review',
            $decision,
            AuditLogger::CATEGORY_CONFIG,
        );
    }

    /** أرشفة مقال | Archive an article (removes it from public view). */
    public function archiveArticle(int $id, ?int $actorId, ?Request $request = null): void
    {
        $article = $this->requireArticle($id);

        Database::statement(
            "UPDATE articles SET status = 'archived', updated_at = NOW() WHERE id = ?",
            [$id],
        );

        $this->audit->setRequest($request);
        $this->audit->logStatusChange(
            'article',
            $id,
            (string) $article['status'],
            'archived',
            AuditLogger::CATEGORY_CONFIG,
        );
    }

    // ═══════════════════ الأسئلة الشائعة | FAQs ═══════════════════

    /** @param array<string,mixed> $data */
    public function saveFaq(?int $id, array $data, ?int $actorId): int
    {
        $question = trim((string) ($data['question_ar'] ?? ''));
        $answer   = HtmlSanitizer::clean(trim((string) ($data['answer_ar'] ?? '')));

        if ($question === '') {
            throw new HttpException(422, 'اكتب نصّ السؤال.');
        }

        if ($answer === '') {
            throw new HttpException(422, 'اكتب الإجابة.');
        }

        $section = (string) ($data['section'] ?? 'general');

        if (!in_array($section, self::FAQ_SECTIONS, true)) {
            $section = 'general';
        }

        $sortOrder = (int) ($data['sort_order'] ?? 0);

        if ($id === null) {
            return Database::insert(
                'INSERT INTO faqs (section, question_ar, answer_ar, sort_order, status)
                 VALUES (?, ?, ?, ?, ?)',
                [$section, mb_substr($question, 0, 300), $answer, $sortOrder, 'draft'],
            );
        }

        $faq = $this->requireFaq($id);

        // التعديل بعد النشر يُعيد السؤال للطابور — كالمقال تماماً
        $status = (string) $faq['status'] === 'published' ? 'pending_review' : (string) $faq['status'];

        Database::statement(
            "UPDATE faqs
                SET section = ?, question_ar = ?, answer_ar = ?, sort_order = ?, status = ?,
                    published_by = CASE WHEN ? = 'published' THEN published_by ELSE NULL END,
                    published_at = CASE WHEN ? = 'published' THEN published_at ELSE NULL END,
                    updated_at = NOW()
              WHERE id = ?",
            [$section, mb_substr($question, 0, 300), $answer, $sortOrder, $status, $status, $status, $id],
        );

        return $id;
    }

    public function submitFaq(int $id): void
    {
        $faq = $this->requireFaq($id);

        if (!in_array((string) $faq['status'], self::EDITABLE, true)) {
            throw new HttpException(422, 'هذا السؤال ليس مسودة قابلة للإرسال.');
        }

        Database::statement(
            "UPDATE faqs SET status = 'pending_review', review_note_ar = NULL, updated_at = NOW()
              WHERE id = ?",
            [$id],
        );
    }

    public function decideFaq(int $id, string $decision, ?string $note, ?int $actorId): void
    {
        $faq = $this->requireFaq($id);

        if (!in_array($decision, ['published', 'rejected'], true)) {
            throw new HttpException(422, 'قرار غير معروف.');
        }

        if ((string) $faq['status'] !== 'pending_review') {
            throw new HttpException(422, 'هذا السؤال ليس في طابور المراجعة.');
        }

        if ($decision === 'rejected' && trim((string) $note) === '') {
            throw new HttpException(422, 'اكتب سبب الرفض.');
        }

        Database::statement(
            "UPDATE faqs
                SET status = ?, review_note_ar = ?,
                    published_by = CASE WHEN ? = 'published' THEN ? ELSE NULL END,
                    published_at = CASE WHEN ? = 'published' THEN NOW() ELSE NULL END,
                    updated_at = NOW()
              WHERE id = ?",
            [
                $decision,
                $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 1000),
                $decision,
                $actorId,
                $decision,
                $id,
            ],
        );
    }

    public function archiveFaq(int $id): void
    {
        $this->requireFaq($id);

        Database::statement(
            "UPDATE faqs SET status = 'archived', updated_at = NOW() WHERE id = ?",
            [$id],
        );
    }

    // ═══════════════════ الصفحات الثابتة | Static pages ═══════════════════

    /**
     * حفظ صفحة ثابتة | Save a static page.
     *
     * الصفحة **تُحرَّر ولا تُنشأ ولا تُحذف**: مفاتيحها يعرفها الكود، وحذف صفحة
     * الشروط يكسر رابطاً في تذييل كل صفحة في المنصة.
     *
     * @param array<string,mixed> $data
     */
    public function savePage(string $slug, array $data, ?int $actorId): void
    {
        $page = $this->content->findPageBySlug($slug);

        if ($page === null) {
            throw new HttpException(404, 'الصفحة غير موجودة.');
        }

        $title = trim((string) ($data['title_ar'] ?? ''));
        $body  = HtmlSanitizer::clean(trim((string) ($data['body_ar'] ?? '')));

        if ($title === '' || $body === '') {
            throw new HttpException(422, 'اكتب عنوان الصفحة ونصّها.');
        }

        // التعديل بعد النشر يُعيد الصفحة للطابور
        $status = (string) $page['status'] === 'published' ? 'pending_review' : (string) $page['status'];

        Database::statement(
            "UPDATE static_pages
                SET title_ar = ?, body_ar = ?, status = ?, updated_by = ?,
                    needs_legal_review = ?,
                    published_by = CASE WHEN ? = 'published' THEN published_by ELSE NULL END,
                    published_at = CASE WHEN ? = 'published' THEN published_at ELSE NULL END,
                    updated_at = NOW()
              WHERE slug = ?",
            [
                mb_substr($title, 0, 200),
                $body,
                $status,
                $actorId,
                (int) (bool) ($data['needs_legal_review'] ?? 1),
                $status,
                $status,
                $slug,
            ],
        );
    }

    public function decidePage(string $slug, string $decision, ?string $note, ?int $actorId): void
    {
        $page = $this->content->findPageBySlug($slug);

        if ($page === null) {
            throw new HttpException(404, 'الصفحة غير موجودة.');
        }

        if (!in_array($decision, ['published', 'draft'], true)) {
            throw new HttpException(422, 'قرار غير معروف.');
        }

        if ((string) $page['status'] !== 'pending_review') {
            throw new HttpException(422, 'هذه الصفحة ليست في طابور المراجعة.');
        }

        if ($decision === 'draft' && trim((string) $note) === '') {
            throw new HttpException(422, 'اكتب سبب الإعادة للمحرّر.');
        }

        Database::statement(
            "UPDATE static_pages
                SET status = ?, review_note_ar = ?,
                    published_by = CASE WHEN ? = 'published' THEN ? ELSE NULL END,
                    published_at = CASE WHEN ? = 'published' THEN NOW() ELSE NULL END,
                    updated_at = NOW()
              WHERE slug = ?",
            [
                $decision,
                $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 1000),
                $decision,
                $actorId,
                $decision,
                $slug,
            ],
        );
    }

    // ═══════════════════ أدوات | Helpers ═══════════════════

    /**
     * ما ينقص قبل الإرسال | What is missing before submission.
     *
     * @param  array<string,mixed> $article
     * @return array<int,string>
     */
    private function missingForSubmission(array $article): array
    {
        $missing = [];

        if (trim((string) $article['excerpt_ar']) === '') {
            $missing[] = 'الملخّص';
        }

        if (mb_strlen(trim(strip_tags((string) $article['body_ar']))) < 200) {
            $missing[] = 'نصّ لا يقلّ عن ٢٠٠ حرف';
        }

        if ($article['category_id'] === null) {
            $missing[] = 'التصنيف';
        }

        return $missing;
    }

    /**
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function validateArticle(array $data, ?int $exceptId): array
    {
        $title = trim((string) ($data['title_ar'] ?? ''));

        if ($title === '') {
            throw new HttpException(422, 'اكتب عنوان المقال.');
        }

        $body = trim((string) ($data['body_ar'] ?? ''));

        if ($body === '') {
            throw new HttpException(422, 'اكتب نصّ المقال.');
        }

        $categoryId = $this->nullableInt($data['category_id'] ?? null);

        if ($categoryId !== null) {
            $exists = Database::scalar(
                "SELECT 1 FROM categories WHERE id = ? AND type = 'article' LIMIT 1",
                [$categoryId],
            );

            if ($exists === null) {
                throw new HttpException(422, 'التصنيف غير معروف.');
            }
        }

        $excerpt = trim((string) ($data['excerpt_ar'] ?? ''));

        if ($excerpt === '') {
            // ملخّص مشتقّ خير من ملخّص فارغ يظهر في نتائج البحث والمشاركة
            $excerpt = mb_substr(trim(strip_tags($body)), 0, 200);
        }

        return [
            'title_ar'        => mb_substr($title, 0, 200),
            'slug'            => $this->uniqueSlug($data['slug'] ?? $title, $exceptId),
            'excerpt_ar'      => mb_substr($excerpt, 0, 500),
            // المحتوى يُعرض دون هروب ليظهر بتنسيقه، فيُنقَّى عند الحفظ لا عند
            // العرض: التنقية عند العرض تعني أن أي قالب جديد ينسى استدعاءها ثغرة.
            'body_ar'         => HtmlSanitizer::clean($body),
            'category_id'     => $categoryId,
            'author_name_ar'  => $this->text($data['author_name_ar'] ?? null, 150),
            'reading_minutes' => $this->readingMinutes($body, $data['reading_minutes'] ?? null),
            'is_featured'     => (int) (bool) ($data['is_featured'] ?? 0),
        ];
    }

    /**
     * زمن القراءة | Reading time.
     *
     * يُقدَّر من عدد الكلمات إن لم يكتبه المحرّر: رقم مشتقّ خير من حقل يُترك
     * فارغاً في كل مرة.
     */
    private function readingMinutes(string $body, mixed $given): ?int
    {
        if ($given !== null && $given !== '' && is_numeric($given)) {
            return max(1, min(255, (int) $given));
        }

        $words = str_word_count(strip_tags($body), 0, 'أ-ي');

        if ($words === 0) {
            $words = count(preg_split('/\s+/u', trim(strip_tags($body))) ?: []);
        }

        return max(1, min(255, (int) ceil($words / 200)));
    }

    /** مُعرّف نصّي فريد | A unique slug. */
    private function uniqueSlug(mixed $source, ?int $exceptId): string
    {
        $base = trim((string) $source);
        $slug = preg_replace('/[^\p{Arabic}\p{L}\p{N}]+/u', '-', $base) ?? '';
        $slug = trim(mb_strtolower($slug), '-');

        if ($slug === '') {
            $slug = 'article';
        }

        $slug      = mb_substr($slug, 0, 200);
        $candidate = $slug;
        $suffix    = 1;

        while (true) {
            $sql      = 'SELECT 1 FROM articles WHERE slug = ?';
            $bindings = [$candidate];

            if ($exceptId !== null) {
                $sql       .= ' AND id <> ?';
                $bindings[] = $exceptId;
            }

            if (Database::scalar($sql . ' LIMIT 1', $bindings) === null) {
                return $candidate;
            }

            $suffix++;
            $candidate = $slug . '-' . $suffix;
        }
    }

    /** @return array<string,mixed> */
    private function requireArticle(int $id): array
    {
        $article = $this->content->findArticle($id);

        if ($article === null) {
            throw new HttpException(404, 'المقال غير موجود.');
        }

        return $article;
    }

    /** @return array<string,mixed> */
    private function requireFaq(int $id): array
    {
        $faq = $this->content->findFaq($id);

        if ($faq === null) {
            throw new HttpException(404, 'السؤال غير موجود.');
        }

        return $faq;
    }

    /**
     * إشعار من يملك النشر | Notify those who may publish.
     *
     * الجمهور يُحدَّد بصلاحية النشر لا بدور بعينه، فلا يُغرَق مراجعو التوثيق
     * بطابور لا يعملون عليه — نفس التصحيح الذي جرى في المرحلة الرابعة.
     */
    private function notifyPublishers(string $title, string $body, int $articleId): void
    {
        (new NotificationService())->notifyPlatformReviewers(
            type: 'content.pending_review',
            title: $title,
            body: $body,
            severity: 'info',
            actionUrl: url('/admin/articles?status=pending_review'),
            entityType: 'article',
            entityId: $articleId,
            permission: 'content.item.publish',
        );
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' || !is_numeric($value) ? null : (int) $value;
    }

    private function text(mixed $value, int $length): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $length);
    }

    // ═══════════════════ تسميات | Labels ═══════════════════

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'draft'          => 'مسودة',
            'pending_review' => 'بانتظار النشر',
            'published'      => 'منشور',
            'rejected'       => 'مُعاد للتعديل',
            'archived'       => 'مؤرشف',
            default          => $status,
        };
    }

    public function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'draft'          => 'np-badge--draft',
            'pending_review' => 'np-badge--pending',
            'published'      => 'np-badge--success',
            'rejected'       => 'np-badge--danger',
            'archived'       => 'np-badge--muted',
            default          => 'np-badge--draft',
        };
    }

    public function sectionLabel(string $section): string
    {
        return match ($section) {
            'general'     => 'أسئلة عامة',
            'account'     => 'الحساب والتسجيل',
            'marketplace' => 'السوق والطلبات',
            'financing'   => 'التمويل',
            'services'    => 'الخدمات غير المالية',
            'bds'         => 'مراكز تطوير الأعمال',
            'erp'         => 'إدارة العملاء والموارد',
            'privacy'     => 'الخصوصية والبيانات',
            default       => $section,
        };
    }
}
