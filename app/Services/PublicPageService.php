<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;

/**
 * الصفحة التعريفية العامة | SME public landing page (§4.3).
 *
 * الأقسام والسمات مجموعات مغلقة يتحقق منها الخادم. صاحب المنشأة يرتّب ويُظهر
 * ويُخفي ويكتب المحتوى، لكنه لا يحقن هيكلاً أو أنماطاً — وهذا ما يبقي الصفحات
 * سليمة على الهاتف ومتوافقة مع سياسة أمن المحتوى.
 * Sections and themes are closed sets validated server-side. The owner may
 * reorder, toggle and write content, but cannot inject structure or styles.
 */
final class PublicPageService
{
    /** الأقسام المتاحة | The closed set of sections (§4.3). */
    public const SECTIONS = [
        'about'        => 'نبذة عن المنشأة',
        'products'     => 'المنتجات',
        'services'     => 'الخدمات',
        'gallery'      => 'معرض الصور',
        'certificates' => 'الشهادات والاعتمادات',
        'story'        => 'قصة المنشأة',
        'hours'        => 'مواعيد العمل',
        'contact'      => 'بيانات التواصل',
        'social'       => 'روابط التواصل الاجتماعي',
    ];

    /** السمات المتاحة | Available themes. */
    public const THEMES = [
        'classic' => 'كلاسيكي',
        'modern'  => 'عصري',
        'warm'    => 'دافئ',
        'minimal' => 'بسيط',
    ];

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /** جلب الصفحة أو إنشاؤها بالأقسام الافتراضية | Get or create the page. */
    public function findOrCreate(int $organizationId): array
    {
        $page = Database::selectOne(
            'SELECT * FROM public_pages WHERE organization_id = ? LIMIT 1',
            [$organizationId],
        );

        if ($page !== null) {
            return $page;
        }

        return Database::transaction(function () use ($organizationId): array {
            $pageId = Database::insert(
                'INSERT INTO public_pages (organization_id, status) VALUES (?, ?)',
                [$organizationId, 'draft'],
            );

            $order = 0;
            foreach (array_keys(self::SECTIONS) as $type) {
                $order += 10;

                Database::statement(
                    'INSERT INTO page_sections
                        (public_page_id, organization_id, section_type, is_visible, sort_order)
                     VALUES (?, ?, ?, ?, ?)',
                    [
                        $pageId, $organizationId, $type,
                        // الأقسام التي تحتاج محتوى يكتبه المستخدم تبدأ مخفية
                        in_array($type, ['about', 'products', 'contact'], true) ? 1 : 0,
                        $order,
                    ],
                );
            }

            return Database::selectOne('SELECT * FROM public_pages WHERE id = ?', [$pageId]) ?? [];
        });
    }

    /** @return array<int,array<string,mixed>> */
    public function sections(int $organizationId): array
    {
        return Database::select(
            'SELECT * FROM page_sections WHERE organization_id = ? ORDER BY sort_order ASC, id ASC',
            [$organizationId],
        );
    }

    /**
     * حفظ إعدادات الصفحة | Save page settings.
     *
     * @param array<string,mixed> $data
     */
    public function saveSettings(int $organizationId, array $data, ?int $actorId, Request $request): void
    {
        $page = $this->findOrCreate($organizationId);

        $theme = (string) ($data['theme'] ?? 'classic');
        if (!array_key_exists($theme, self::THEMES)) {
            $theme = 'classic';
        }

        // اللون يُتحقق من صيغته: قيمة حرة هنا تعني حقن CSS في سمة style
        $color = (string) ($data['primary_color'] ?? '#0b4f8a');
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
            $color = '#0b4f8a';
        }

        Database::statement(
            'UPDATE public_pages
                SET theme = ?, primary_color = ?, headline = ?, tagline = ?, story = ?,
                    operating_hours = ?, show_phone = ?, show_email = ?, show_address = ?,
                    show_whatsapp = ?, enable_enquiry_form = ?, enable_quote_request = ?,
                    meta_title = ?, meta_description = ?
              WHERE organization_id = ?',
            [
                $theme,
                $color,
                $this->nullable($data['headline'] ?? null, 200),
                $this->nullable($data['tagline'] ?? null, 300),
                $this->nullable($data['story'] ?? null, 10000),
                $this->nullable($data['operating_hours'] ?? null, 500),
                !empty($data['show_phone']) ? 1 : 0,
                !empty($data['show_email']) ? 1 : 0,
                !empty($data['show_address']) ? 1 : 0,
                !empty($data['show_whatsapp']) ? 1 : 0,
                !empty($data['enable_enquiry_form']) ? 1 : 0,
                !empty($data['enable_quote_request']) ? 1 : 0,
                $this->nullable($data['meta_title'] ?? null, 200),
                $this->nullable($data['meta_description'] ?? null, 300),
                $organizationId,
            ],
        );

        $this->audit->setRequest($request);
        $this->audit->log(
            action: 'public_page.updated',
            category: AuditLogger::CATEGORY_RECORD,
            entityType: 'public_page',
            entityId: (int) $page['id'],
            description: 'تحديث إعدادات الصفحة التعريفية',
            userId: $actorId,
            organizationId: $organizationId,
        );
    }

    /**
     * حفظ الأقسام: الترتيب والظهور والمحتوى | Save sections.
     *
     * @param array<string,mixed> $input
     */
    public function saveSections(int $organizationId, array $input, ?int $actorId): void
    {
        $page = $this->findOrCreate($organizationId);

        $visible = (array) ($input['visible'] ?? []);
        $order   = (array) ($input['order'] ?? []);
        $titles  = (array) ($input['title'] ?? []);
        $bodies  = (array) ($input['body'] ?? []);

        Database::transaction(function () use ($organizationId, $page, $visible, $order, $titles, $bodies): void {
            foreach (array_keys(self::SECTIONS) as $type) {
                Database::statement(
                    'UPDATE page_sections
                        SET is_visible = ?, sort_order = ?, title = ?, body = ?
                      WHERE public_page_id = ? AND organization_id = ? AND section_type = ?',
                    [
                        in_array($type, $visible, true) ? 1 : 0,
                        // الترتيب رقم صحيح مُتحقَّق منه لا قيمة حرة
                        max(0, min(999, (int) ($order[$type] ?? 0))),
                        $this->nullable($titles[$type] ?? null, 200),
                        $this->nullable($bodies[$type] ?? null, 5000),
                        (int) $page['id'], $organizationId, $type,
                    ],
                );
            }
        });
    }

    /**
     * نشر الصفحة | Publish the page.
     *
     * لا تُنشر صفحة منشأة غير موثّقة: الصفحة العامة تحمل شارة الثقة، ونشرها
     * قبل التوثيق يجعل الشارة بلا معنى.
     * An unverified organization cannot publish: the public page carries the
     * trust badge, and publishing before verification empties it of meaning.
     */
    public function publish(int $organizationId, ?int $actorId, Request $request): void
    {
        $organization = Database::selectOne(
            'SELECT * FROM organizations WHERE id = ? AND deleted_at IS NULL',
            [$organizationId],
        );

        if ($organization === null) {
            throw new HttpException(404, 'المنشأة غير موجودة.');
        }

        if ($organization['status'] !== 'verified') {
            throw new HttpException(
                422,
                'لا يمكن نشر الصفحة التعريفية قبل توثيق المنشأة.',
            );
        }

        $page = $this->findOrCreate($organizationId);

        if (trim((string) $organization['short_description']) === '') {
            throw new HttpException(422, 'أضف وصفاً مختصراً للمنشأة قبل النشر.');
        }

        Database::statement(
            "UPDATE public_pages
                SET status = 'published', published_at = NOW(), published_by = ?
              WHERE organization_id = ?",
            [$actorId, $organizationId],
        );

        $this->audit->setRequest($request);
        $this->audit->logStatusChange(
            'public_page', (int) $page['id'], (string) $page['status'], 'published',
            AuditLogger::CATEGORY_RECORD, $organizationId,
        );
    }

    public function unpublish(int $organizationId, ?int $actorId, Request $request): void
    {
        $page = $this->findOrCreate($organizationId);

        Database::statement(
            "UPDATE public_pages SET status = 'unpublished' WHERE organization_id = ?",
            [$organizationId],
        );

        $this->audit->setRequest($request);
        $this->audit->logStatusChange(
            'public_page', (int) $page['id'], (string) $page['status'], 'unpublished',
            AuditLogger::CATEGORY_RECORD, $organizationId,
        );
    }

    /**
     * الصفحة العامة كاملة للعرض | The full public page for rendering.
     *
     * تُعيد null إذا لم تكن الصفحة منشورة أو المنشأة غير موثّقة — الزائر يرى
     * 404 لا صفحة نصف جاهزة.
     */
    public function publicPageBySlug(string $slug): ?array
    {
        $organization = Database::selectOne(
            "SELECT o.*, t.code AS type_code, t.name_ar AS type_name,
                    s.name_ar AS sector_name, g.name_ar AS governorate_name,
                    c.name_ar AS city_name
               FROM organizations o
               JOIN organization_types t ON t.id = o.organization_type_id
               LEFT JOIN sectors s ON s.id = o.sector_id
               LEFT JOIN governorates g ON g.id = o.governorate_id
               LEFT JOIN cities c ON c.id = o.city_id
              WHERE o.slug = ? AND o.deleted_at IS NULL AND o.status = 'verified'
              LIMIT 1",
            [$slug],
        );

        if ($organization === null) {
            return null;
        }

        $page = Database::selectOne(
            "SELECT * FROM public_pages WHERE organization_id = ? AND status = 'published' LIMIT 1",
            [(int) $organization['id']],
        );

        if ($page === null) {
            return null;
        }

        $sections = Database::select(
            'SELECT * FROM page_sections
              WHERE organization_id = ? AND is_visible = 1
              ORDER BY sort_order ASC, id ASC',
            [(int) $organization['id']],
        );

        return [
            'organization' => $organization,
            'page'         => $page,
            'sections'     => $sections,
            'gallery'      => $this->media((int) $organization['id'], 'gallery'),
            'certificates' => $this->media((int) $organization['id'], 'certificate'),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function media(int $organizationId, string $collection): array
    {
        return Database::select(
            'SELECT pm.*, m.original_name
               FROM page_media pm
               JOIN media m ON m.id = pm.media_id
              WHERE pm.organization_id = ? AND pm.collection = ?
              ORDER BY pm.sort_order ASC, pm.id ASC',
            [$organizationId, $collection === 'certificate' ? 'certificate' : 'gallery'],
        );
    }

    public function incrementViews(int $organizationId): void
    {
        Database::statement(
            'UPDATE public_pages SET view_count = view_count + 1 WHERE organization_id = ?',
            [$organizationId],
        );
    }

    private function nullable(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
