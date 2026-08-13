<?php

declare(strict_types=1);

namespace App\Policies;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\HttpException;
use App\Support\TenantContext;

/**
 * سياسة الوصول للمنشآت ووثائقها | Organization & document access policy (§9).
 *
 * طبقة الدفاع الثانية بعد صلاحيات المسار: هنا تُفحص **ملكية السجل بعينه**، وهو
 * ما يمنع الوصول غير المباشر عبر تخمين المعرّفات (IDOR).
 * The second defence layer after route permissions: this checks ownership of
 * the specific record, which is what stops IDOR.
 */
final class OrganizationPolicy
{
    /**
     * هل يجوز عرض هذه المنشأة؟ | May the actor view this organization?
     *
     * مسموح لعضو نشط فيها، أو لمن يملك صلاحية الاطلاع على أي منشأة (فريق المنصة).
     */
    public function canView(array $organization): bool
    {
        if (TenantContext::can('org.account.view_any')) {
            return true;
        }

        return TenantContext::organizationId() === (int) $organization['id']
            && TenantContext::can('org.profile.view');
    }

    /**
     * هل يجوز تعديل هذه المنشأة؟ | May the actor edit it?
     *
     * التعديل يقتصر على أعضاء المنشأة نفسها. فريق المنصة يراجع ويقرّر، ولا
     * يحرّر بيانات المنشأة نيابةً عنها في هذه النسخة — فصلٌ يحفظ مسؤولية
     * صاحب البيانات عن دقتها.
     * Editing is limited to the organization's own members. Platform staff
     * review and decide but do not edit an organization's data on its behalf,
     * keeping responsibility for accuracy with the data owner.
     */
    public function canUpdate(array $organization): bool
    {
        if (TenantContext::organizationId() !== (int) $organization['id']) {
            return false;
        }

        if (!TenantContext::can('org.profile.update')) {
            return false;
        }

        // المنشأة الموقوفة تُقرأ ولا تُعدَّل حتى يُرفع الإيقاف
        return (string) $organization['status'] !== 'suspended';
    }

    /** هل يجوز إرسال المنشأة للمراجعة؟ | May the actor submit for review? */
    public function canSubmit(array $organization): bool
    {
        return $this->canUpdate($organization)
            && in_array((string) $organization['status'], ['draft', 'more_info_required', 'rejected'], true);
    }

    /** هل يجوز اتخاذ قرار توثيق؟ | May the actor decide verification? */
    public function canDecideVerification(): bool
    {
        return TenantContext::can('org.account.verify');
    }

    /** هل يجوز عرض وثائق المنشأة؟ | May the actor view its documents? */
    public function canViewDocuments(array $organization): bool
    {
        // فريق المراجعة يطّلع على الوثائق لأداء التوثيق
        if (TenantContext::can('org.account.verify') || TenantContext::can('org.account.view_any')) {
            return true;
        }

        return TenantContext::organizationId() === (int) $organization['id']
            && TenantContext::can('org.document.view');
    }

    public function canUploadDocuments(array $organization): bool
    {
        return TenantContext::organizationId() === (int) $organization['id']
            && TenantContext::can('org.document.upload')
            && (string) $organization['status'] !== 'suspended';
    }

    /**
     * حذف وثيقة | Deleting a document.
     * ممنوع بعد اعتماد الوثيقة: الوثيقة المعتمدة جزء من سجل قرار التوثيق.
     */
    public function canDeleteDocument(array $organization, array $document): bool
    {
        if (!$this->canUploadDocuments($organization)) {
            return false;
        }

        return (string) $document['status'] !== 'accepted';
    }

    // ---------------- إصدارات ترمي استثناءً | Throwing variants ----------------

    public function authorizeView(array $organization): void
    {
        if (!$this->canView($organization)) {
            // 404 لا 403: لا نؤكد وجود منشأة لا يملك الفاعل حق رؤيتها
            throw new HttpException(404, 'المنشأة المطلوبة غير موجودة.');
        }
    }

    public function authorizeUpdate(array $organization): void
    {
        if (!$this->canUpdate($organization)) {
            throw new AuthorizationException(
                (string) $organization['status'] === 'suspended'
                    ? 'لا يمكن تعديل بيانات منشأة موقوفة. يرجى التواصل مع الدعم الفني.'
                    : 'ليس لديك صلاحية تعديل بيانات هذه المنشأة.',
                'org.profile.update',
                'organization',
            );
        }
    }

    public function authorizeViewDocuments(array $organization): void
    {
        if (!$this->canViewDocuments($organization)) {
            throw new HttpException(404, 'المستند المطلوب غير موجود.');
        }
    }

    public function authorizeUploadDocuments(array $organization): void
    {
        if (!$this->canUploadDocuments($organization)) {
            throw new AuthorizationException(
                'ليس لديك صلاحية رفع مستندات لهذه المنشأة.',
                'org.document.upload',
                'organization',
            );
        }
    }

    public function authorizeDecideVerification(): void
    {
        if (!$this->canDecideVerification()) {
            throw new AuthorizationException(
                'قرارات التوثيق يتخذها فريق المنصة فقط.',
                'org.account.verify',
                'organization',
            );
        }
    }
}
