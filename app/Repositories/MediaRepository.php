<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * مستودع الوسائط | Media repository.
 *
 * مقيّد بالمنشأة: كل ملف مملوك لمنشأة لا يُقرأ إلا من نطاقها. الملفات التابعة
 * للمنصة (organization_id = NULL) تُقرأ عبر النطاق العام الموثّق فقط.
 * Tenant-scoped: an organization's files are unreadable outside its scope.
 */
final class MediaRepository extends BaseRepository
{
    protected string $table = 'media';

    protected bool $tenantScoped = true;

    protected bool $softDeletes = true;

    protected array $sortable = ['id', 'created_at', 'size_bytes', 'collection'];

    /**
     * جلب ملف عام للعرض على الصفحة العامة | Fetch a public file.
     *
     * استثناء مقصود ومحدود: الملفات المعلَّمة `public` (الشعارات وصور المنتجات)
     * تُقرأ دون سياق منشأة لأنها معروضة للعامة أصلاً. الملفات الخاصة لا تمرّ
     * من هنا إطلاقاً — الشرط على `visibility` جزء من الاستعلام لا من المُستدعي.
     * A deliberate, narrow exception: files explicitly marked public are
     * readable without tenant context because they are already published. The
     * visibility predicate lives in the query, not in the caller.
     */
    public function findPublic(int $mediaId): ?array
    {
        return Database::selectOne(
            "SELECT * FROM media
              WHERE id = ? AND visibility = 'public' AND deleted_at IS NULL
              LIMIT 1",
            [$mediaId],
        );
    }

    /** إجمالي المساحة المستخدمة لمنشأة | Total storage used by an organization. */
    public function totalBytesForOrganization(int $organizationId): int
    {
        return (int) Database::scalar(
            'SELECT COALESCE(SUM(size_bytes), 0) FROM media
              WHERE organization_id = ? AND deleted_at IS NULL',
            [$organizationId],
        );
    }
}
