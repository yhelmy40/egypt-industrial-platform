<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Support\TenantContext;
use App\Repositories\ContentRepository;
use App\Services\ContentService;
use App\Support\HtmlSanitizer;

/**
 * إدارة المحتوى | Content administration (§4.11).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * **الكتابة ليست النشر.** المسارات هنا مقسومة على صلاحيتين:
 * `content.article.manage` للكتابة والإرسال، و`content.item.publish` للقرار.
 * محرّر يملك الأولى وحدها يكتب ويرسل ولا يرى أزرار النشر أصلاً.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class ContentController extends Controller
{
    public function __construct(
        private readonly ContentRepository $content = new ContentRepository(),
        private readonly ContentService $service = new ContentService(),
    ) {
    }

    // ═══════════════════ المقالات | Articles ═══════════════════

    public function articles(Request $request): Response
    {
        $filters = [
            'status' => (string) ($request->input('status') ?? ''),
            'q'      => (string) ($request->input('q') ?? ''),
        ];

        return $this->view('admin/content/articles', [
            'pageTitle' => 'مركز المعرفة',
            'results'   => $this->content->adminArticles($filters, $this->page($request)),
            'counts'    => $this->content->articleCountsByStatus(),
            'filters'   => $filters,
            'canPublish' => TenantContext::can('content.item.publish'),
            'service'   => $this->service,
        ], 'admin');
    }

    public function createArticle(Request $request): Response
    {
        return $this->view('admin/content/article-form', [
            'pageTitle'   => 'مقال جديد',
            'article'     => null,
            'categories'  => $this->content->articleCategories(),
            'allowedTags' => HtmlSanitizer::allowedTags(),
            'service'     => $this->service,
        ], 'admin');
    }

    public function storeArticle(Request $request): Response
    {
        try {
            $id = $this->service->createArticle($request->all(), $this->currentUserId());
            $this->flash('success', 'أُنشئت المسودة. أرسلها للمراجعة عند اكتمالها.');

            return $this->redirect('/admin/articles/' . $id . '/edit');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());

            return $this->back($request, [], '/admin/articles/new');
        }
    }

    public function editArticle(Request $request): Response
    {
        $article = $this->requireArticle($request);

        return $this->view('admin/content/article-form', [
            'pageTitle'   => 'تعديل المقال',
            'article'     => $article,
            'categories'  => $this->content->articleCategories(),
            'allowedTags' => HtmlSanitizer::allowedTags(),
            'canPublish'  => TenantContext::can('content.item.publish'),
            'service'     => $this->service,
        ], 'admin');
    }

    public function updateArticle(Request $request): Response
    {
        $id = $this->routeId($request);

        try {
            $this->service->updateArticle($id, $request->all(), $this->currentUserId(), $request);
            $this->flash('success', 'حُفظ المقال.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/admin/articles/' . $id . '/edit');
    }

    public function submitArticle(Request $request): Response
    {
        $id = $this->routeId($request);

        try {
            $this->service->submitArticle($id, $this->currentUserId(), $request);
            $this->flash('success', 'أُرسل المقال للمراجعة.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/admin/articles/' . $id . '/edit');
    }

    /** قرار النشر | The publication decision — a separate permission. */
    public function decideArticle(Request $request): Response
    {
        $id = $this->routeId($request);

        try {
            $this->service->decideArticle(
                $id,
                (string) ($request->input('decision') ?? ''),
                $request->input('review_note_ar'),
                $this->currentUserId(),
                $request,
            );
            $this->flash('success', 'سُجّل القرار.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/admin/articles');
    }

    public function archiveArticle(Request $request): Response
    {
        $id = $this->routeId($request);

        try {
            $this->service->archiveArticle($id, $this->currentUserId(), $request);
            $this->flash('success', 'أُرشِف المقال ولم يعد ظاهراً للعامة.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/admin/articles');
    }

    // ═══════════════════ الأسئلة الشائعة | FAQs ═══════════════════

    public function faqs(Request $request): Response
    {
        $status = (string) ($request->input('status') ?? '');

        return $this->view('admin/content/faqs', [
            'pageTitle'  => 'الأسئلة الشائعة',
            'faqs'       => $this->content->adminFaqs($status),
            'sections'   => ContentService::FAQ_SECTIONS,
            'filters'    => ['status' => $status],
            'canPublish' => TenantContext::can('content.item.publish'),
            'service'    => $this->service,
        ], 'admin');
    }

    public function saveFaq(Request $request): Response
    {
        $id = $request->routeInt('id');

        try {
            $this->service->saveFaq($id, $request->all(), $this->currentUserId());
            $this->flash('success', $id === null ? 'أُضيف السؤال كمسودة.' : 'حُفظ السؤال.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/admin/faqs');
    }

    public function submitFaq(Request $request): Response
    {
        try {
            $this->service->submitFaq($this->routeId($request));
            $this->flash('success', 'أُرسل السؤال للمراجعة.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/admin/faqs');
    }

    public function decideFaq(Request $request): Response
    {
        try {
            $this->service->decideFaq(
                $this->routeId($request),
                (string) ($request->input('decision') ?? ''),
                $request->input('review_note_ar'),
                $this->currentUserId(),
            );
            $this->flash('success', 'سُجّل القرار.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/admin/faqs');
    }

    public function archiveFaq(Request $request): Response
    {
        try {
            $this->service->archiveFaq($this->routeId($request));
            $this->flash('success', 'أُرشِف السؤال.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/admin/faqs');
    }

    // ═══════════════════ الصفحات الثابتة | Static pages ═══════════════════

    public function pages(Request $request): Response
    {
        return $this->view('admin/content/pages', [
            'pageTitle'   => 'الصفحات الثابتة',
            'pages'       => $this->content->allPages(),
            'allowedTags' => HtmlSanitizer::allowedTags(),
            'canPublish'  => TenantContext::can('content.item.publish'),
            'service'     => $this->service,
        ], 'admin');
    }

    public function editPage(Request $request): Response
    {
        $slug = (string) $request->route('slug');
        $page = $this->content->findPageBySlug($slug);

        if ($page === null) {
            throw new HttpException(404, 'الصفحة غير موجودة.');
        }

        return $this->view('admin/content/page-form', [
            'pageTitle'   => 'تحرير ' . $page['title_ar'],
            'page'        => $page,
            'allowedTags' => HtmlSanitizer::allowedTags(),
            'canPublish'  => TenantContext::can('content.item.publish'),
            'service'     => $this->service,
        ], 'admin');
    }

    public function updatePage(Request $request): Response
    {
        $slug = (string) $request->route('slug');

        try {
            $this->service->savePage($slug, $request->all(), $this->currentUserId());
            $this->flash('success', 'حُفظت الصفحة وأُرسلت للمراجعة إن كانت منشورة.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/admin/pages/' . $slug);
    }

    public function decidePage(Request $request): Response
    {
        $slug = (string) $request->route('slug');

        try {
            $this->service->decidePage(
                $slug,
                (string) ($request->input('decision') ?? ''),
                $request->input('review_note_ar'),
                $this->currentUserId(),
            );
            $this->flash('success', 'سُجّل القرار.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/admin/pages/' . $slug);
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    private function routeId(Request $request): int
    {
        $id = $request->routeInt('id');

        if ($id === null) {
            throw new HttpException(404, 'العنصر غير موجود.');
        }

        return $id;
    }

    /** @return array<string,mixed> */
    private function requireArticle(Request $request): array
    {
        $article = $this->content->findArticle($this->routeId($request));

        if ($article === null) {
            throw new HttpException(404, 'المقال غير موجود.');
        }

        return $article;
    }
}
