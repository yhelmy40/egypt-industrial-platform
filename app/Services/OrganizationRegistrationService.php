<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Repositories\MembershipRepository;
use App\Repositories\OrganizationProfileRepository;
use App\Repositories\OrganizationRepository;
use App\Services\PermissionResolver;

/**
 * تسجيل المنشآت | Organization registration (§4.2).
 *
 * ينشئ المنشأة ويربط منشئها كمالك في معاملة واحدة: منشأة بلا عضو مالك سجل
 * يتيم لا يستطيع أحد إدارته، ولذلك لا يُقبل نجاح جزئي.
 * Creates the organization and its owner membership in one transaction: an
 * organization with no owning member is an orphan nobody can administer, so a
 * partial success is never accepted.
 *
 * الدور المُسند يُشتقّ من نوع المنشأة ولا يأتي من النموذج إطلاقاً — وإلا لأمكن
 * لمُسجِّل أن يمنح نفسه دوراً أوسع بتعديل الطلب.
 * The assigned role is derived from the organization type, never taken from the
 * form; otherwise a registrant could grant themselves a broader role.
 */
final class OrganizationRegistrationService
{
    /** الدور الافتراضي لمنشئ كل نوع منشأة | Owner role per organization type. */
    private const OWNER_ROLE_BY_TYPE = [
        'sme'              => 'sme_owner',
        'bank'             => 'bank_admin',
        'ngo'              => 'ngo_admin',
        'service_provider' => 'provider_admin',
        'bds_center'       => 'bds_manager',
        'government'       => 'government_admin',
    ];

    public function __construct(
        private readonly OrganizationRepository $organizations = new OrganizationRepository(),
        private readonly OrganizationProfileRepository $profiles = new OrganizationProfileRepository(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
        private readonly PermissionResolver $permissions = new PermissionResolver(),
        private readonly ProfileCompletionService $completion = new ProfileCompletionService(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /** @return array<int,array<string,mixed>> أنواع المنشآت المتاحة للتسجيل */
    public function availableTypes(): array
    {
        return Database::select(
            'SELECT * FROM organization_types WHERE is_active = 1 ORDER BY sort_order ASC',
        );
    }

    public function findType(string $code): ?array
    {
        return Database::selectOne(
            'SELECT * FROM organization_types WHERE code = ? AND is_active = 1 LIMIT 1',
            [$code],
        );
    }

    /**
     * إنشاء منشأة جديدة كمسودة | Create a new organization as a draft.
     *
     * @param array<string,mixed> $data
     * @return array{organization_id:int,slug:string}
     */
    public function create(string $typeCode, array $data, int $ownerUserId, Request $request): array
    {
        $type = $this->findType($typeCode);

        if ($type === null) {
            throw new HttpException(422, 'نوع المنشأة المختار غير متاح.');
        }

        $roleCode = self::OWNER_ROLE_BY_TYPE[$typeCode] ?? null;
        $role     = $roleCode === null ? null : $this->permissions->findRoleByCode($roleCode);

        if ($role === null) {
            throw new HttpException(500, 'تعذّر تحديد دور مالك المنشأة.');
        }

        $legalName = trim((string) ($data['legal_name'] ?? ''));

        return Database::transaction(function () use (
            $type, $typeCode, $data, $ownerUserId, $legalName, $role, $request
        ): array {
            $slug = $this->organizations->generateUniqueSlug(
                trim((string) ($data['trading_name'] ?? '')) ?: $legalName,
            );

            $organizationId = $this->organizations->create([
                'organization_type_id' => (int) $type['id'],
                'legal_name'           => mb_substr($legalName, 0, 200),
                'trading_name'         => $this->nullable($data['trading_name'] ?? null, 200),
                'slug'                 => $slug,
                'status'               => 'draft',
                'sector_id'            => $this->nullableInt($data['sector_id'] ?? null),
                'sub_sector_id'        => $this->nullableInt($data['sub_sector_id'] ?? null),
                'governorate_id'       => $this->nullableInt($data['governorate_id'] ?? null),
                'city_id'              => $this->nullableInt($data['city_id'] ?? null),
                'short_description'    => $this->nullable($data['short_description'] ?? null, 500),
                'public_email'         => $this->nullable($data['public_email'] ?? null, 190),
                'public_phone'         => $this->nullable($data['public_phone'] ?? null, 30),
                'owner_user_id'        => $ownerUserId,
            ]);

            // المالك عضو نشط بالدور المشتقّ من النوع | Owner membership
            $this->memberships->addMember(
                organizationId: $organizationId,
                userId: $ownerUserId,
                roleId: (int) $role['id'],
                status: 'active',
                isPrimaryContact: true,
            );

            // صف الملف التفصيلي يُنشأ فارغاً ليُستكمل في خطوات المعالج
            $this->profiles->save($organizationId, $typeCode, []);

            $this->completion->recalculate($organizationId);

            $this->audit->setRequest($request);
            $this->audit->log(
                action: 'organization.created',
                category: AuditLogger::CATEGORY_VERIFICATION,
                entityType: 'organization',
                entityId: $organizationId,
                description: 'إنشاء منشأة جديدة: ' . $legalName . ' (' . $type['name_ar'] . ')',
                severity: 'notice',
                userId: $ownerUserId,
                organizationId: $organizationId,
            );

            return ['organization_id' => $organizationId, 'slug' => $slug];
        });
    }

    /**
     * تحديث البيانات الأساسية | Update the base organization record.
     *
     * @param array<string,mixed> $data
     */
    public function updateBasics(int $organizationId, array $data, ?int $actorId, Request $request): void
    {
        $before = $this->organizations->find($organizationId);

        if ($before === null) {
            throw new HttpException(404, 'المنشأة غير موجودة.');
        }

        $update = [
            'legal_name'        => mb_substr(trim((string) ($data['legal_name'] ?? $before['legal_name'])), 0, 200),
            'trading_name'      => $this->nullable($data['trading_name'] ?? null, 200),
            'sector_id'         => $this->nullableInt($data['sector_id'] ?? null),
            'sub_sector_id'     => $this->nullableInt($data['sub_sector_id'] ?? null),
            'governorate_id'    => $this->nullableInt($data['governorate_id'] ?? null),
            'city_id'           => $this->nullableInt($data['city_id'] ?? null),
            'address'           => $this->nullable($data['address'] ?? null, 500),
            'public_email'      => $this->nullable($data['public_email'] ?? null, 190),
            'public_phone'      => $this->nullable($data['public_phone'] ?? null, 30),
            'website'           => $this->nullable($data['website'] ?? null, 255),
            'short_description' => $this->nullable($data['short_description'] ?? null, 500),
            'description'       => $this->nullable($data['description'] ?? null, 5000),
        ];

        Database::transaction(function () use ($organizationId, $update, $before, $actorId, $request): void {
            $this->organizations->update($organizationId, $update);

            $this->audit->setRequest($request);
            $this->audit->log(
                action: 'organization.updated',
                category: AuditLogger::CATEGORY_RECORD,
                entityType: 'organization',
                entityId: $organizationId,
                changes: [
                    'before' => array_intersect_key($before, $update),
                    'after'  => $update,
                ],
                description: 'تحديث بيانات المنشأة',
                userId: $actorId,
                organizationId: $organizationId,
            );
        });

        $this->completion->recalculate($organizationId);
    }

    /**
     * تحديث الملف التفصيلي حسب النوع | Update the type-specific profile.
     *
     * @param array<string,mixed> $data
     */
    public function updateProfile(int $organizationId, string $typeCode, array $data, ?int $actorId, Request $request): void
    {
        Database::transaction(function () use ($organizationId, $typeCode, $data, $actorId, $request): void {
            $this->profiles->save($organizationId, $typeCode, $data);

            $this->audit->setRequest($request);
            $this->audit->log(
                action: 'organization.profile_updated',
                category: AuditLogger::CATEGORY_RECORD,
                entityType: 'organization',
                entityId: $organizationId,
                description: 'تحديث الملف التفصيلي للمنشأة',
                userId: $actorId,
                organizationId: $organizationId,
            );
        });

        $this->completion->recalculate($organizationId);
    }

    // ---------------- أدوات | Helpers ----------------

    private function nullable(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
