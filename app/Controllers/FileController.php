<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\MediaRepository;
use App\Services\AuditLogger;
use App\Services\FileStorageService;
use App\Support\TenantContext;

/**
 * تقديم الملفات المرفوعة | Authorized file delivery (§9, §10).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * الملفات مخزّنة خارج جذر الويب، فلا يوجد رابط مباشر إليها إطلاقاً. هذا هو
 * المنفذ الوحيد، ويطبّق بالترتيب:
 *   1. الملف موجود في سجل الوسائط وغير محذوف.
 *   2. الملف عام ⇒ يُقدَّم (شعار، صورة منتج).
 *   3. الملف خاص ⇒ يجب أن يكون الطالب عضواً في المنشأة المالكة،
 *      أو من فريق المراجعة المخوَّل بالاطلاع على الوثائق.
 *   4. كل تنزيل لملف خاص يُسجَّل في سجل التدقيق (§9).
 *
 * الرفض يعيد 404 لا 403: تأكيد وجود ملف لمنشأة أخرى يسمح باستكشاف المعرّفات.
 *
 * Files live outside the web root, so no direct URL exists. This is the only
 * door. Private files require membership or review authority, every private
 * download is audited, and refusals return 404 so identifiers cannot be probed.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class FileController extends Controller
{
    public function __construct(
        private readonly MediaRepository $media = new MediaRepository(),
        private readonly FileStorageService $storage = new FileStorageService(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    public function show(Request $request): Response
    {
        $mediaId = $request->routeInt('id');

        if ($mediaId === null) {
            throw new HttpException(404, 'الملف المطلوب غير موجود.');
        }

        // 1) الملفات العامة | Public files — no tenant context required
        $public = $this->media->findPublic($mediaId);

        if ($public !== null) {
            return $this->stream($public, cacheable: true);
        }

        // 2) الملفات الخاصة | Private files
        $record = Database::selectOne(
            'SELECT * FROM media WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$mediaId],
        );

        if ($record === null) {
            throw new HttpException(404, 'الملف المطلوب غير موجود.');
        }

        $this->authorizePrivateAccess($record);

        $this->audit->setRequest($request);
        $this->audit->logDocumentDownload(
            $mediaId,
            (string) $record['original_name'],
            $record['organization_id'] === null ? null : (int) $record['organization_id'],
        );

        return $this->stream($record, cacheable: false);
    }

    /**
     * فحص حق الاطلاع على ملف خاص | Authorize access to a private file.
     *
     * @throws HttpException 404
     */
    private function authorizePrivateAccess(array $record): void
    {
        $ownerOrganizationId = $record['organization_id'] === null ? null : (int) $record['organization_id'];

        // ملفات المنصة نفسها: لفريق المنصة فقط
        if ($ownerOrganizationId === null) {
            if (!TenantContext::isPlatformStaff()) {
                throw new HttpException(404, 'الملف المطلوب غير موجود.');
            }

            return;
        }

        // فريق المراجعة يطّلع على وثائق المنشآت لأداء التوثيق
        if (TenantContext::can('org.account.verify') || TenantContext::can('org.account.view_any')) {
            return;
        }

        // خلاف ذلك: عضوية نشطة في المنشأة المالكة + صلاحية عرض الوثائق
        $isMember = TenantContext::organizationId() === $ownerOrganizationId;

        if (!$isMember || !TenantContext::can('org.document.view')) {
            throw new HttpException(404, 'الملف المطلوب غير موجود.');
        }
    }

    private function stream(array $record, bool $cacheable): Response
    {
        if (!$this->storage->exists($record)) {
            throw new HttpException(404, 'الملف المطلوب غير موجود على الخادم.');
        }

        $path = $this->storage->absolutePath($record);

        $response = Response::download(
            $path,
            (string) $record['original_name'],
            (string) $record['mime_type'],
        );

        // الصور العامة يمكن تخزينها مؤقتاً؛ الوثائق الخاصة لا تُخزَّن إطلاقاً
        $response->withHeader(
            'Cache-Control',
            $cacheable ? 'public, max-age=86400' : 'no-store, no-cache, must-revalidate, private',
        );

        return $response;
    }
}
