<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ContentRepository;
use App\Services\ContentService;

/**
 * مركز المعرفة والصفحات الثابتة | Knowledge centre and static pages (§4.11).
 *
 * كل قراءة هنا تمرّ بحدّ الرؤية العامة في المستودع: المنشور وغير المحذوف فقط.
 * لا مسار في هذا المتحكّم يقرأ مسودة أو مادة مرفوضة.
 */
final class ContentController extends Controller
{
    public function __construct(
        private readonly ContentRepository $content = new ContentRepository(),
        private readonly ContentService $service = new ContentService(),
    ) {
    }

    /** مركز المعرفة | The knowledge centre index. */
    public function articles(Request $request): Response
    {
        $filters = [
            'q'        => (string) ($request->input('q') ?? ''),
            'category' => (string) ($request->input('category') ?? ''),
        ];

        return $this->view('public/knowledge/index', [
            'pageTitle'  => 'مركز المعرفة',
            'results'    => $this->content->publicArticles($filters, $this->page($request)),
            'categories' => $this->content->articleCategories(),
            'filters'    => $filters,
            'service'    => $this->service,
        ], 'public');
    }

    /** مقال منشور | A published article. */
    public function article(Request $request): Response
    {
        $slug    = (string) $request->route('slug');
        $article = $this->content->publicArticle($slug);

        if ($article === null) {
            throw new HttpException(404, 'المقال غير موجود.');
        }

        $this->content->recordArticleView((int) $article['id']);

        return $this->view('public/knowledge/show', [
            'pageTitle'   => (string) $article['title_ar'],
            'metaDesc'    => (string) $article['excerpt_ar'],
            'article'     => $article,
            'related'     => $this->content->relatedArticles(
                (int) $article['id'],
                $article['category_id'] === null ? null : (int) $article['category_id'],
            ),
            'service'     => $this->service,
        ], 'public');
    }

    /** الأسئلة الشائعة | The FAQ page. */
    public function faqs(Request $request): Response
    {
        return $this->view('public/knowledge/faqs', [
            'pageTitle' => 'الأسئلة الشائعة',
            'grouped'   => $this->content->publicFaqs(),
            'service'   => $this->service,
        ], 'public');
    }

    /**
     * صفحة ثابتة | A static page.
     *
     * المسار يمرّر المُعرّف من جدول المسارات لا من عنوان حرّ، فلا يصير هذا
     * المتحكّم بوابة لقراءة أي صفّ بالاسم.
     */
    private function renderStaticPage(string $slug): Response
    {
        // الاسم ليس `page()` عمداً: المتحكّم الأساسي يُعرّفها لترقيم الصفحات،
        // وإعادة تعريفها بتوقيع مختلف خطأ مميت عند التحميل لا عند الاستدعاء.
        if (!in_array($slug, ContentService::CORE_PAGES, true)) {
            throw new HttpException(404, 'الصفحة غير موجودة.');
        }

        $page = $this->content->publicPage($slug);

        if ($page === null) {
            throw new HttpException(404, 'الصفحة غير موجودة.');
        }

        return $this->view('public/static-page', [
            'pageTitle' => (string) $page['title_ar'],
            'page'      => $page,
        ], 'public');
    }

    public function about(Request $request): Response
    {
        return $this->renderStaticPage('about');
    }

    public function terms(Request $request): Response
    {
        return $this->renderStaticPage('terms');
    }

    public function privacy(Request $request): Response
    {
        return $this->renderStaticPage('privacy');
    }
}
