<?php

declare(strict_types=1);

namespace App\Controllers\Sme;

use App\Controllers\Controller;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\MembershipRepository;
use App\Repositories\OrganizationRepository;
use App\Services\FileStorageService;
use App\Services\PublicPageService;
use App\Validation\Validator;

/**
 * محرّر الصفحة التعريفية | Public page editor (§4.3).
 *
 * محرّر مقيَّد بأقسام وسمات معرّفة مسبقاً — لا سحب وإفلات حرّ (§14).
 */
final class PageController extends Controller
{
    public function __construct(
        private readonly PublicPageService $pages = new PublicPageService(),
        private readonly OrganizationRepository $organizations = new OrganizationRepository(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
        private readonly FileStorageService $storage = new FileStorageService(),
    ) {
    }

    public function edit(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $organization   = $this->organizations->findWithDetails($organizationId);

        if ($organization === null) {
            throw new HttpException(404, 'المنشأة غير موجودة.');
        }

        return $this->view('sme/page/edit', [
            'pageTitle'     => 'الصفحة التعريفية',
            'organization'  => $organization,
            'organizations' => $this->memberships->organizationsForUser((int) $this->currentUserId()),
            'page'          => $this->pages->findOrCreate($organizationId),
            'sections'      => $this->pages->sections($organizationId),
            'sectionLabels' => PublicPageService::SECTIONS,
            'themes'        => PublicPageService::THEMES,
            'gallery'       => $this->pages->media($organizationId, 'gallery'),
            'certificates'  => $this->pages->media($organizationId, 'certificate'),
            'canPublish'    => $organization['status'] === 'verified',
        ], 'app');
    }

    public function saveSettings(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        $validator = Validator::make($request->all())
            ->labels([
                'headline'         => 'العنوان الرئيسي',
                'tagline'          => 'الجملة التعريفية',
                'meta_title'       => 'عنوان محركات البحث',
                'meta_description' => 'وصف محركات البحث',
            ])
            ->maxLength('headline', 200)
            ->maxLength('tagline', 300)
            ->maxLength('story', 10000)
            ->maxLength('operating_hours', 500)
            ->maxLength('meta_title', 200)
            ->maxLength('meta_description', 300);

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/app/page');
        }

        $this->pages->saveSettings($organizationId, $request->all(), $this->currentUserId(), $request);
        $this->flash('success', 'تم حفظ إعدادات الصفحة.');

        return $this->redirect('/app/page');
    }

    public function saveSections(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        $this->pages->saveSections($organizationId, [
            'visible' => $request->array('visible'),
            'order'   => $request->array('order'),
            'title'   => $request->array('title'),
            'body'    => $request->array('body'),
        ], $this->currentUserId());

        $this->flash('success', 'تم حفظ ترتيب الأقسام ومحتواها.');

        return $this->redirect('/app/page');
    }

    public function publish(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        try {
            $this->pages->publish($organizationId, $this->currentUserId(), $request);

            $organization = $this->organizations->find($organizationId);
            $this->flash(
                'success',
                'تم نشر الصفحة. رابطها: ' . url('/business/' . (string) $organization['slug']),
            );
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        return $this->redirect('/app/page');
    }

    public function unpublish(Request $request): Response
    {
        $organizationId = $this->requireOrganization();

        $this->pages->unpublish($organizationId, $this->currentUserId(), $request);
        $this->flash('success', 'تم إلغاء نشر الصفحة.');

        return $this->redirect('/app/page');
    }

    /** رفع صورة الغلاف أو المعرض | Upload a cover or gallery image. */
    public function uploadMedia(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $collection     = (string) ($request->input('collection') ?? 'gallery');

        if (!in_array($collection, ['cover', 'gallery', 'certificate'], true)) {
            throw new HttpException(422, 'نوع الصورة غير معروف.');
        }

        $file = $request->file('image');

        if ($file === null) {
            return $this->back($request, ['image' => __('validation.file_required')], '/app/page');
        }

        try {
            $mediaId = $this->storage->store(
                file: $file,
                typeKey: 'image',
                collection: $collection === 'cover' ? 'covers' : 'content',
                organizationId: $organizationId,
                uploadedBy: $this->currentUserId(),
                visibility: 'public',
            );
        } catch (ValidationException $e) {
            return $this->back($request, $e->errors(), '/app/page');
        }

        if ($collection === 'cover') {
            $page = $this->pages->findOrCreate($organizationId);

            \App\Core\Database::statement(
                'UPDATE public_pages SET cover_media_id = ? WHERE organization_id = ?',
                [$mediaId, $organizationId],
            );

            if ($page['cover_media_id'] !== null) {
                $this->storage->delete((int) $page['cover_media_id']);
            }
        } else {
            \App\Core\Database::statement(
                'INSERT INTO page_media (organization_id, media_id, collection, caption, sort_order)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $organizationId, $mediaId, $collection,
                    $request->filled('caption') ? mb_substr((string) $request->input('caption'), 0, 200) : null,
                    (int) \App\Core\Database::scalar(
                        'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM page_media
                          WHERE organization_id = ? AND collection = ?',
                        [$organizationId, $collection],
                    ),
                ],
            );
        }

        $this->flash('success', 'تمت إضافة الصورة.');

        return $this->redirect('/app/page');
    }

    public function deleteMedia(Request $request): Response
    {
        $organizationId = $this->requireOrganization();
        $pageMediaId    = $request->routeInt('id');

        if ($pageMediaId === null) {
            throw new HttpException(404, 'الصورة المطلوبة غير موجودة.');
        }

        // القيد على المنشأة يمنع حذف صورة منشأة أخرى
        $row = \App\Core\Database::selectOne(
            'SELECT * FROM page_media WHERE id = ? AND organization_id = ?',
            [$pageMediaId, $organizationId],
        );

        if ($row === null) {
            throw new HttpException(404, 'الصورة المطلوبة غير موجودة.');
        }

        \App\Core\Database::statement('DELETE FROM page_media WHERE id = ?', [$pageMediaId]);
        $this->storage->delete((int) $row['media_id']);

        $this->flash('success', 'تم حذف الصورة.');

        return $this->redirect('/app/page');
    }
}
