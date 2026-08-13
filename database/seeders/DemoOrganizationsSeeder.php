<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database;

/**
 * منشآت العرض التوضيحي | Demonstration organizations (§15).
 *
 * ⚠ كل المنشآت هنا موسومة is_demo = 1 وتظهر في الواجهة بوسم «بيانات تجريبية».
 * لا تمثّل أي جهة حقيقية، ولا تُنسب لأي بنك أو منظمة قائمة.
 * Every organization here is flagged is_demo = 1 and rendered with the
 * "بيانات تجريبية" marker. None represents a real institution.
 *
 * الغرض من هذه البذرة في المرحلة الأولى: إثبات العزل بين المستأجرين عملياً
 * ووجود مستخدم ينتمي لأكثر من منشأة بأدوار مختلفة (§7).
 * Its Phase 1 purpose: demonstrate tenant isolation in practice and the
 * multi-organization membership case.
 */
final class DemoOrganizationsSeeder extends Seeder
{
    public function isDemo(): bool
    {
        return true;
    }

    public function order(): int
    {
        return 60;
    }

    public function run(): void
    {
        $this->guardProduction();

        $created = 0;

        foreach ($this->organizations() as $definition) {
            $ownerId = $this->userId($definition['owner_email']);
            $typeId  = $this->typeId($definition['type_code']);
            $roleId  = $this->roleId($definition['owner_role']);

            if ($ownerId === null || $typeId === null || $roleId === null) {
                continue;
            }

            $organizationId = $this->upsert('organizations', [
                'slug'                 => $definition['slug'],
                'organization_type_id' => $typeId,
                'legal_name'           => $definition['legal_name'],
                'trading_name'         => $definition['trading_name'],
                'status'               => $definition['status'],
                'sector_id'            => $this->sectorId($definition['sector_code']),
                'governorate_id'       => $this->governorateId($definition['governorate_code']),
                'short_description'    => $definition['short_description'],
                'description'          => $definition['description'],
                'public_email'         => $definition['public_email'],
                'public_phone'         => $definition['public_phone'],
                'owner_user_id'        => $ownerId,
                'completion_score'     => $definition['completion_score'],
                'is_demo'              => 1,
                'submitted_at'         => in_array($definition['status'], ['draft'], true)
                    ? null
                    : date('Y-m-d H:i:s', strtotime('-' . random_int(3, 40) . ' days')),
                'verified_at'          => $definition['status'] === 'verified'
                    ? date('Y-m-d H:i:s', strtotime('-' . random_int(1, 20) . ' days'))
                    : null,
            ], ['slug']);

            $this->ensureMember($organizationId, $ownerId, $roleId, true);

            // أعضاء إضافيون — يُثبتون حالة «مستخدم في عدة منشآت بأدوار مختلفة»
            foreach ($definition['extra_members'] as [$email, $roleCode]) {
                $memberId = $this->userId($email);
                $memberRoleId = $this->roleId($roleCode);

                if ($memberId !== null && $memberRoleId !== null) {
                    $this->ensureMember($organizationId, $memberId, $memberRoleId, false);
                }
            }

            $created++;
        }

        $this->info($created . ' منشأة تجريبية موسومة «بيانات تجريبية».');
    }

    private function ensureMember(int $organizationId, int $userId, int $roleId, bool $isPrimary): void
    {
        $existing = Database::selectOne(
            'SELECT id FROM organization_members WHERE organization_id = ? AND user_id = ? LIMIT 1',
            [$organizationId, $userId],
        );

        if ($existing !== null) {
            Database::statement(
                "UPDATE organization_members SET role_id = ?, status = 'active' WHERE id = ?",
                [$roleId, (int) $existing['id']],
            );

            return;
        }

        Database::statement(
            'INSERT INTO organization_members
                (organization_id, user_id, role_id, status, is_primary_contact, joined_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$organizationId, $userId, $roleId, 'active', $isPrimary ? 1 : 0],
        );
    }

    private function userId(string $email): ?int
    {
        $row = Database::selectOne('SELECT id FROM users WHERE email = ? LIMIT 1', [$email]);

        return $row === null ? null : (int) $row['id'];
    }

    private function typeId(string $code): ?int
    {
        $row = Database::selectOne('SELECT id FROM organization_types WHERE code = ? LIMIT 1', [$code]);

        return $row === null ? null : (int) $row['id'];
    }

    private function roleId(string $code): ?int
    {
        $row = Database::selectOne('SELECT id FROM roles WHERE code = ? LIMIT 1', [$code]);

        return $row === null ? null : (int) $row['id'];
    }

    private function sectorId(?string $code): ?int
    {
        if ($code === null) {
            return null;
        }

        $row = Database::selectOne('SELECT id FROM sectors WHERE code = ? LIMIT 1', [$code]);

        return $row === null ? null : (int) $row['id'];
    }

    private function governorateId(?string $code): ?int
    {
        if ($code === null) {
            return null;
        }

        $row = Database::selectOne('SELECT id FROM governorates WHERE code = ? LIMIT 1', [$code]);

        return $row === null ? null : (int) $row['id'];
    }

    /** @return array<int,array<string,mixed>> */
    private function organizations(): array
    {
        return [
            [
                'slug'             => 'مصنع-النيل-للصناعات-الغذائية',
                'type_code'        => 'sme',
                'owner_email'      => 'sme@nilepreneurs.test',
                'owner_role'       => 'sme_owner',
                'legal_name'       => 'شركة النيل للصناعات الغذائية (بيانات تجريبية)',
                'trading_name'     => 'مصنع النيل للأغذية',
                'status'           => 'verified',
                'sector_code'      => 'FOOD',
                'governorate_code' => 'GIZ',
                'short_description' => 'تصنيع وتعبئة المواد الغذائية والحاصلات المجففة للسوق المحلي والتصدير.',
                'description'      => 'منشأة تجريبية أُنشئت لأغراض العرض والاختبار داخل المنصة. تعمل في تصنيع وتعبئة المواد الغذائية والحاصلات الزراعية المجففة، وتخدم عملاء التجزئة والجملة في عدة محافظات.',
                'public_email'     => 'info@nile-foods.test',
                'public_phone'     => '01000000010',
                'completion_score' => 85,
                'extra_members'    => [['employee@nilepreneurs.test', 'sme_employee']],
            ],
            [
                'slug'             => 'ورشة-دلتا-للملابس-الجاهزة',
                'type_code'        => 'sme',
                'owner_email'      => 'employee@nilepreneurs.test',
                'owner_role'       => 'sme_owner',
                'legal_name'       => 'ورشة دلتا للملابس الجاهزة (بيانات تجريبية)',
                'trading_name'     => 'دلتا للملابس',
                'status'           => 'submitted',
                'sector_code'      => 'TEXT',
                'governorate_code' => 'GHR',
                'short_description' => 'تصنيع الملابس الجاهزة وملابس الأطفال بكميات صغيرة ومتوسطة.',
                'description'      => 'منشأة تجريبية للعرض. متخصصة في تصنيع الملابس الجاهزة وملابس الأطفال، وتنفّذ طلبيات التصنيع لدى الغير.',
                'public_email'     => 'info@delta-wear.test',
                'public_phone'     => '01000000011',
                'completion_score' => 55,
                // نفس المستخدم صاحب المشروع الأول يظهر هنا كموظف: إثبات تعدّد
                // العضويات بأدوار مختلفة (§7).
                'extra_members'    => [['sme@nilepreneurs.test', 'sme_employee']],
            ],
            [
                'slug'             => 'بنك-التنمية-التجريبي',
                'type_code'        => 'bank',
                'owner_email'      => 'bank@nilepreneurs.test',
                'owner_role'       => 'bank_admin',
                'legal_name'       => 'بنك التنمية التجريبي (بيانات تجريبية — جهة غير حقيقية)',
                'trading_name'     => 'بنك التنمية التجريبي',
                'status'           => 'verified',
                'sector_code'      => 'PROF',
                'governorate_code' => 'CAI',
                'short_description' => 'جهة تمويلية تجريبية لأغراض العرض داخل المنصة فقط.',
                'description'      => 'كيان تجريبي لا يمثّل أي بنك أو مؤسسة مالية حقيقية، وأُنشئ لعرض مسار نشر المنتجات التمويلية ومراجعة الطلبات داخل المنصة.',
                'public_email'     => 'sme@demo-bank.test',
                'public_phone'     => '01000000020',
                'completion_score' => 90,
                'extra_members'    => [],
            ],
            [
                'slug'             => 'بيت-الخبرة-للاستشارات',
                'type_code'        => 'service_provider',
                'owner_email'      => 'provider@nilepreneurs.test',
                'owner_role'       => 'provider_admin',
                'legal_name'       => 'بيت الخبرة للاستشارات المالية والإدارية (بيانات تجريبية)',
                'trading_name'     => 'بيت الخبرة',
                'status'           => 'verified',
                'sector_code'      => 'PROF',
                'governorate_code' => 'CAI',
                'short_description' => 'خدمات محاسبية وضريبية ودراسات جدوى وخطط عمل للمشروعات الصغيرة.',
                'description'      => 'مقدّم خدمة تجريبي للعرض. يقدّم خدمات المحاسبة والالتزام الضريبي ودراسات الجدوى وخطط العمل والجاهزية للتمويل.',
                'public_email'     => 'info@expertise-house.test',
                'public_phone'     => '01000000030',
                'completion_score' => 80,
                'extra_members'    => [],
            ],
            [
                'slug'             => 'مؤسسة-تمكين-للتنمية',
                'type_code'        => 'ngo',
                'owner_email'      => 'ngo@nilepreneurs.test',
                'owner_role'       => 'ngo_admin',
                'legal_name'       => 'مؤسسة تمكين للتنمية المجتمعية (بيانات تجريبية)',
                'trading_name'     => 'مؤسسة تمكين',
                'status'           => 'verified',
                'sector_code'      => 'EDUC',
                'governorate_code' => 'ALX',
                'short_description' => 'برامج تدريب وبناء قدرات وإرشاد لرواد الأعمال والمشروعات الناشئة.',
                'description'      => 'منظمة تجريبية للعرض. تقدّم برامج تدريبية وإرشادية وبناء قدرات لأصحاب المشروعات الصغيرة ورواد الأعمال.',
                'public_email'     => 'info@tamkeen-demo.test',
                'public_phone'     => '01000000040',
                'completion_score' => 75,
                'extra_members'    => [],
            ],
            [
                'slug'             => 'مركز-تطوير-الأعمال-القاهرة',
                'type_code'        => 'bds_center',
                'owner_email'      => 'bds@nilepreneurs.test',
                'owner_role'       => 'bds_manager',
                'legal_name'       => 'مركز تطوير الأعمال — القاهرة (بيانات تجريبية)',
                'trading_name'     => 'مركز تطوير الأعمال — القاهرة',
                'status'           => 'verified',
                'sector_code'      => 'PROF',
                'governorate_code' => 'CAI',
                'short_description' => 'خدمات غير مالية وإرشاد وتقييم احتياجات ومتابعة حالات الدعم للمشروعات.',
                'description'      => 'مركز تجريبي للعرض. يقدّم الإرشاد وتقييم الاحتياجات وخطط العمل والإحالة إلى الجهات المموّلة ومقدّمي الخدمات.',
                'public_email'     => 'cairo@bds-demo.test',
                'public_phone'     => '01000000050',
                'completion_score' => 88,
                'extra_members'    => [],
            ],
        ];
    }
}
