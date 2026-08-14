<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Services\PublicPageService;
use Tests\TestCase;

/**
 * الصفحة التعريفية للمنشأة | SME public page (§4.3).
 *
 * الصفحة العامة تحمل شارة «منشأة موثّقة»، فنشرها قبل التوثيق يفرّغ الشارة من
 * معناها. كما أن ما يظهر من بيانات تواصل قرارٌ لصاحب المنشأة، والافتراضي هو
 * الأقل كشفاً.
 */
final class PublicPageTest extends TestCase
{
    private PublicPageService $pages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pages = new PublicPageService();
    }

    public function test_a_new_page_starts_as_a_draft_with_the_default_sections(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);

        $page = $this->pages->findOrCreate($organizationId);

        $this->assertSame('draft', $page['status']);
        $this->assertSame(
            count(PublicPageService::SECTIONS),
            $this->countRows('page_sections', 'organization_id = ?', [$organizationId]),
        );
    }

    public function test_creating_the_page_twice_does_not_duplicate_it(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);

        $first  = $this->pages->findOrCreate($organizationId);
        $second = $this->pages->findOrCreate($organizationId);

        $this->assertSame((int) $first['id'], (int) $second['id']);
        $this->assertSame(1, $this->countRows('public_pages', 'organization_id = ?', [$organizationId]));
    }

    /** لا نشر قبل التوثيق | No publishing before verification. */
    public function test_an_unverified_organization_cannot_publish_its_page(): void
    {
        foreach (['draft', 'submitted', 'under_review', 'rejected', 'suspended'] as $status) {
            // الوصف مكتمل عمداً: الرفض هنا يجب أن يكون بسبب التوثيق لا بسبب نقص بيانات
            $organizationId = $this->createOrganization([
                'status'            => $status,
                'short_description' => 'وصف مختصر مكتمل.',
            ]);
            $this->pages->findOrCreate($organizationId);

            try {
                $this->pages->publish($organizationId, $this->createUser(), $this->request('POST', '/app/page/publish'));
                $this->fail("كان يجب رفض النشر لمنشأة حالتها: {$status}");
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }

            $this->assertDatabaseHas('public_pages', [
                'organization_id' => $organizationId, 'status' => 'draft',
            ]);
        }
    }

    public function test_a_verified_organization_can_publish_and_unpublish(): void
    {
        $organizationId = $this->createOrganization([
            'status'            => 'verified',
            'short_description' => 'منشأة اختبار لها وصف مختصر منشور.',
        ]);
        $actorId        = $this->createUser();
        $this->pages->findOrCreate($organizationId);

        $this->pages->publish($organizationId, $actorId, $this->request('POST', '/app/page/publish'));

        $this->assertDatabaseHas('public_pages', [
            'organization_id' => $organizationId, 'status' => 'published', 'published_by' => $actorId,
        ]);

        $this->pages->unpublish($organizationId, $actorId, $this->request('POST', '/app/page/unpublish'));

        $this->assertDatabaseHas('public_pages', [
            'organization_id' => $organizationId, 'status' => 'unpublished',
        ]);
    }

    /**
     * الافتراضي هو الأقل كشفاً | The default is the least disclosure.
     *
     * بيانات التواصل لا تظهر ما لم يختر صاحب المنشأة إظهارها صراحةً.
     */
    public function test_contact_disclosure_is_off_by_default(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);

        $page = $this->pages->findOrCreate($organizationId);

        $this->assertSame(0, (int) $page['show_phone']);
        $this->assertSame(0, (int) $page['show_email']);
        $this->assertSame(0, (int) $page['show_address']);
        $this->assertSame(0, (int) $page['show_whatsapp']);
    }

    /**
     * اللون يُتحقَّق من صيغته | The brand colour is format-checked.
     *
     * القيمة تُطبع داخل سمة style، فلو قُبلت حرّة لصارت طريقاً لحقن CSS.
     */
    public function test_an_invalid_brand_colour_falls_back_to_the_platform_colour(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $this->pages->findOrCreate($organizationId);

        foreach (['red; background:url(x)', 'javascript:alert(1)', '#12', 'rgb(0,0,0)'] as $malicious) {
            $this->pages->saveSettings(
                $organizationId,
                ['primary_color' => $malicious],
                $this->createUser(),
                $this->request('POST', '/app/page/settings'),
            );

            $this->assertSame(
                '#0b4f8a',
                Database::scalar(
                    'SELECT primary_color FROM public_pages WHERE organization_id = ?',
                    [$organizationId],
                ),
                "قيمة اللون غير الصالحة يجب أن تُستبدل: {$malicious}",
            );
        }
    }

    public function test_a_valid_brand_colour_is_kept(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $this->pages->findOrCreate($organizationId);

        $this->pages->saveSettings(
            $organizationId,
            ['primary_color' => '#A1B2C3'],
            $this->createUser(),
            $this->request('POST', '/app/page/settings'),
        );

        $this->assertSame(
            '#A1B2C3',
            Database::scalar('SELECT primary_color FROM public_pages WHERE organization_id = ?', [$organizationId]),
        );
    }

    public function test_an_unknown_theme_falls_back_to_the_default(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $this->pages->findOrCreate($organizationId);

        $this->pages->saveSettings(
            $organizationId,
            ['theme' => '../../etc/passwd'],
            $this->createUser(),
            $this->request('POST', '/app/page/settings'),
        );

        $this->assertSame(
            'classic',
            Database::scalar('SELECT theme FROM public_pages WHERE organization_id = ?', [$organizationId]),
        );
    }

    /** الأقسام محصورة بمجموعة معروفة | Sections come from a closed set (§14). */
    public function test_an_unknown_section_type_cannot_be_introduced(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $this->pages->findOrCreate($organizationId);

        $before = $this->countRows('page_sections', 'organization_id = ?', [$organizationId]);

        $this->pages->saveSections($organizationId, [
            'visible' => ['about', 'malicious_section'],
            'order'   => ['about' => 10, 'malicious_section' => 20],
            'title'   => ['malicious_section' => 'قسم مدسوس'],
            'body'    => ['malicious_section' => '<script>alert(1)</script>'],
        ], $this->createUser());

        $this->assertSame(
            $before,
            $this->countRows('page_sections', 'organization_id = ?', [$organizationId]),
            'لا يُضاف قسم خارج المجموعة المعروفة.',
        );
        $this->assertSame(
            0,
            $this->countRows('page_sections', 'section_type = ?', ['malicious_section']),
        );
    }

    public function test_section_order_is_clamped_to_a_sane_range(): void
    {
        $organizationId = $this->createOrganization(['status' => 'verified']);
        $this->pages->findOrCreate($organizationId);

        $this->pages->saveSections($organizationId, [
            'visible' => ['about'],
            'order'   => ['about' => 99999, 'products' => -5],
            'title'   => [],
            'body'    => [],
        ], $this->createUser());

        $this->assertSame(
            999,
            (int) Database::scalar(
                "SELECT sort_order FROM page_sections WHERE organization_id = ? AND section_type = 'about'",
                [$organizationId],
            ),
        );
        $this->assertSame(
            0,
            (int) Database::scalar(
                "SELECT sort_order FROM page_sections WHERE organization_id = ? AND section_type = 'products'",
                [$organizationId],
            ),
        );
    }

    public function test_sections_of_one_organization_are_never_touched_by_another(): void
    {
        $organizationA = $this->createOrganization(['status' => 'verified']);
        $organizationB = $this->createOrganization(['status' => 'verified']);

        $this->pages->findOrCreate($organizationA);
        $this->pages->findOrCreate($organizationB);

        $this->pages->saveSections($organizationA, [
            'visible' => [],
            'order'   => [],
            'title'   => ['about' => 'عنوان المنشأة أ'],
            'body'    => [],
        ], $this->createUser());

        $this->assertNull(
            Database::scalar(
                "SELECT title FROM page_sections WHERE organization_id = ? AND section_type = 'about'",
                [$organizationB],
            ),
            'حفظ أقسام منشأة لا يمسّ أقسام منشأة أخرى.',
        );
    }
}
