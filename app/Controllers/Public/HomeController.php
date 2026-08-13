<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * البوابة العامة | Public portal (§4.1).
 *
 * صفحات عامة لا تتطلّب تسجيل دخول. الإحصاءات المعروضة مجمّعة ولا تكشف بيانات
 * أي منشأة بعينها.
 * Public pages requiring no login. Displayed statistics are aggregate and
 * expose no individual organization's data.
 */
final class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('public/home', [
            'pageTitle'   => __('portal.platform_full'),
            'metaDescription' => __('portal.hero_subtitle'),
            'stats'       => $this->platformStats(),
            'sectors'     => $this->topSectors(),
        ], 'public');
    }

    public function about(Request $request): Response
    {
        return $this->view('public/about', [
            'pageTitle' => __('portal.about_title'),
            'objectives' => $this->initiativeObjectives(),
        ], 'public');
    }

    public function contact(Request $request): Response
    {
        return $this->view('public/contact', [
            'pageTitle' => __('portal.nav_contact'),
        ], 'public');
    }

    public function terms(Request $request): Response
    {
        return $this->view('public/terms', [
            'pageTitle' => __('portal.footer_terms'),
        ], 'public');
    }

    public function privacy(Request $request): Response
    {
        return $this->view('public/privacy', [
            'pageTitle' => __('portal.footer_privacy'),
        ], 'public');
    }

    /**
     * إحصاءات المنصة | Aggregate platform statistics.
     *
     * @return array<string,int>
     */
    private function platformStats(): array
    {
        $row = Database::selectOne(
            "SELECT
                (SELECT COUNT(*) FROM organizations o
                   JOIN organization_types t ON t.id = o.organization_type_id
                  WHERE t.code = 'sme' AND o.deleted_at IS NULL) AS total_smes,
                (SELECT COUNT(*) FROM organizations o
                   JOIN organization_types t ON t.id = o.organization_type_id
                  WHERE t.code = 'sme' AND o.status = 'verified' AND o.deleted_at IS NULL) AS verified_smes,
                (SELECT COUNT(*) FROM organizations o
                   JOIN organization_types t ON t.id = o.organization_type_id
                  WHERE t.code IN ('service_provider','bds_center','ngo')
                    AND o.status = 'verified' AND o.deleted_at IS NULL) AS providers,
                (SELECT COUNT(*) FROM governorates WHERE is_active = 1) AS governorates,
                (SELECT COUNT(*) FROM sectors WHERE is_active = 1) AS sectors"
        );

        return [
            'total_smes'    => (int) ($row['total_smes'] ?? 0),
            'verified_smes' => (int) ($row['verified_smes'] ?? 0),
            'providers'     => (int) ($row['providers'] ?? 0),
            'governorates'  => (int) ($row['governorates'] ?? 0),
            'sectors'       => (int) ($row['sectors'] ?? 0),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function topSectors(): array
    {
        return Database::select(
            'SELECT id, name_ar, icon FROM sectors WHERE is_active = 1 ORDER BY sort_order ASC LIMIT 8'
        );
    }

    /**
     * أهداف المبادرة | Initiative objectives (§2).
     * نص مؤسسي مستمد من السياق المُعطى، دون إضافات.
     *
     * @return array<int,string>
     */
    private function initiativeObjectives(): array
    {
        return [
            'إطلاق الطاقات الكامنة لدى الشباب ورواد الأعمال.',
            'زيادة عدد المشروعات الصغيرة والمتوسطة ودعم المشروعات القائمة.',
            'تشجيع خلق فرص العمل وخفض معدلات البطالة.',
            'دعم التصنيع المحلي والمنتجات القادرة على إحلال الواردات والمنافسة دولياً.',
            'تشجيع التحول الرقمي والميكنة.',
            'دعم وصول المشروعات إلى الخدمات المالية وغير المالية.',
            'تقديم الدعم الاستشاري والفني للمشروعات ورواد الأعمال.',
            'مساعدة الأفكار الابتكارية على التحول إلى مشروعات قابلة للتنفيذ.',
            'مساندة البنوك وجهاز تنمية المشروعات في دعم وتمويل المشروعات.',
            'تحقيق انتشار جغرافي يغطي جميع محافظات مصر.',
        ];
    }
}
