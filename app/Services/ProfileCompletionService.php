<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\DocumentRepository;
use App\Repositories\OrganizationProfileRepository;
use App\Repositories\OrganizationRepository;

/**
 * نسبة اكتمال ملف المنشأة | Profile completion score (§4.2, §4.13).
 *
 * الدرجة أداة إرشاد لصاحب المنشأة، لا معيار قبول. لذلك تُعرض دائماً مع قائمة
 * البنود الناقصة بالاسم — رقم بلا تفسير لا يساعد أحداً على التصرّف.
 * The score guides the owner; it is not an acceptance criterion. It is always
 * accompanied by the named missing items, because a bare number tells nobody
 * what to do next.
 *
 * الأوزان مُعرَّفة هنا كبيانات لا كمنطق متناثر، فيمكن تعديلها في مكان واحد.
 */
final class ProfileCompletionService
{
    public function __construct(
        private readonly OrganizationRepository $organizations = new OrganizationRepository(),
        private readonly OrganizationProfileRepository $profiles = new OrganizationProfileRepository(),
        private readonly DocumentRepository $documents = new DocumentRepository(),
    ) {
    }

    /**
     * حساب الدرجة والبنود الناقصة | Compute score and missing items.
     *
     * @return array{score:int,completed:array<int,string>,missing:array<int,array{label:string,weight:int,url:string}>}
     */
    public function evaluate(int $organizationId): array
    {
        $organization = $this->organizations->findWithDetails($organizationId);

        if ($organization === null) {
            return ['score' => 0, 'completed' => [], 'missing' => []];
        }

        $typeCode = (string) ($organization['type_code'] ?? 'sme');
        $profile  = $this->profiles->findForOrganization($organizationId, $typeCode) ?? [];

        $checks = $this->checksFor($typeCode, $organization, $profile, $organizationId);

        $earned    = 0;
        $total     = 0;
        $completed = [];
        $missing   = [];

        foreach ($checks as $check) {
            $total += $check['weight'];

            if ($check['satisfied']) {
                $earned     += $check['weight'];
                $completed[] = $check['label'];
                continue;
            }

            $missing[] = [
                'label'  => $check['label'],
                'weight' => $check['weight'],
                'url'    => $check['url'],
            ];
        }

        $score = $total === 0 ? 0 : (int) round(($earned / $total) * 100);

        return ['score' => $score, 'completed' => $completed, 'missing' => $missing];
    }

    /** حساب الدرجة وحفظها | Compute and persist the score. */
    public function recalculate(int $organizationId): int
    {
        $result = $this->evaluate($organizationId);
        $this->organizations->updateCompletionScore($organizationId, $result['score']);

        return $result['score'];
    }

    /**
     * بنود التقييم | The scored checks.
     *
     * @return array<int,array{label:string,weight:int,satisfied:bool,url:string}>
     */
    private function checksFor(string $typeCode, array $organization, array $profile, int $organizationId): array
    {
        $basicUrl    = url('/app/organization');
        $documentUrl = url('/app/organization/documents');

        // بنود مشتركة بين كل أنواع المنشآت | Common to every organization type
        $checks = [
            [
                'label'     => 'الاسم القانوني للمنشأة',
                'weight'    => 10,
                'satisfied' => $this->filled($organization, 'legal_name'),
                'url'       => $basicUrl,
            ],
            [
                'label'     => 'وصف مختصر للنشاط',
                'weight'    => 10,
                'satisfied' => $this->filled($organization, 'short_description'),
                'url'       => $basicUrl,
            ],
            [
                'label'     => 'وصف تفصيلي للمنشأة',
                'weight'    => 5,
                'satisfied' => $this->filled($organization, 'description'),
                'url'       => $basicUrl,
            ],
            [
                'label'     => 'القطاع والنشاط',
                'weight'    => 10,
                'satisfied' => $this->filled($organization, 'sector_id'),
                'url'       => $basicUrl,
            ],
            [
                'label'     => 'المحافظة والمدينة',
                'weight'    => 10,
                'satisfied' => $this->filled($organization, 'governorate_id'),
                'url'       => $basicUrl,
            ],
            [
                'label'     => 'بيانات التواصل العامة',
                'weight'    => 10,
                'satisfied' => $this->filled($organization, 'public_phone')
                    || $this->filled($organization, 'public_email'),
                'url'       => $basicUrl,
            ],
            [
                'label'     => 'شعار المنشأة',
                'weight'    => 5,
                'satisfied' => $this->filled($organization, 'logo_media_id'),
                'url'       => $basicUrl,
            ],
            [
                'label'     => 'مسؤول التواصل',
                'weight'    => 10,
                'satisfied' => $this->filled($profile, 'contact_person_name')
                    && $this->filled($profile, 'contact_person_phone'),
                'url'       => $basicUrl,
            ],
        ];

        // بنود تخصّ المشروعات الصغيرة والمتوسطة
        if ($typeCode === 'sme') {
            $checks[] = [
                'label'     => 'الوضع القانوني للمنشأة',
                'weight'    => 10,
                'satisfied' => $this->filled($profile, 'formalization_status'),
                'url'       => $basicUrl,
            ];
            $checks[] = [
                'label'     => 'حجم المنشأة وعدد العاملين',
                'weight'    => 5,
                'satisfied' => $this->filled($profile, 'company_size')
                    && $profile['employees_count'] !== null,
                'url'       => $basicUrl,
            ];
            $checks[] = [
                'label'     => 'تاريخ التأسيس',
                'weight'    => 5,
                'satisfied' => $this->filled($profile, 'establishment_date'),
                'url'       => $basicUrl,
            ];
            $checks[] = [
                'label'     => 'الاحتياجات التمويلية أو التطويرية',
                'weight'    => 5,
                'satisfied' => $this->filled($profile, 'financing_needs')
                    || $this->filled($profile, 'bds_needs'),
                'url'       => $basicUrl,
            ];
        }

        if ($typeCode === 'bds_center') {
            $checks[] = [
                'label'     => 'الخدمات التي يقدّمها المركز',
                'weight'    => 10,
                'satisfied' => $this->filled($profile, 'services_offered'),
                'url'       => $basicUrl,
            ];
            $checks[] = [
                'label'     => 'نطاق التغطية الجغرافية',
                'weight'    => 5,
                'satisfied' => (int) ($profile['serves_all_governorates'] ?? 0) === 1
                    || $this->filled($profile, 'coverage_governorates'),
                'url'       => $basicUrl,
            ];
        }

        if (in_array($typeCode, ['service_provider', 'bank', 'ngo', 'government'], true)) {
            $checks[] = [
                'label'     => 'مجالات التخصص',
                'weight'    => 10,
                'satisfied' => $this->filled($profile, 'specializations'),
                'url'       => $basicUrl,
            ];
            $checks[] = [
                'label'     => 'نطاق الخدمة الجغرافي',
                'weight'    => 5,
                'satisfied' => (int) ($profile['serves_all_governorates'] ?? 0) === 1
                    || $this->filled($profile, 'service_governorates'),
                'url'       => $basicUrl,
            ];
        }

        // المستندات الإلزامية | Required documents
        $documentStatus = $this->documents->requiredDocumentStatus($organizationId, $typeCode);

        if ($documentStatus['required'] > 0) {
            $checks[] = [
                'label'     => 'المستندات المطلوبة (' . $documentStatus['provided']
                    . ' من ' . $documentStatus['required'] . ')',
                'weight'    => 15,
                'satisfied' => $documentStatus['missing'] === [],
                'url'       => $documentUrl,
            ];
        }

        return $checks;
    }

    private function filled(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;

        return $value !== null && $value !== '' && $value !== 0 && $value !== '0';
    }
}
