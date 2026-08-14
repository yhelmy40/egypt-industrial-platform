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


    public function contact(Request $request): Response
    {
        return $this->view('public/contact', [
            'pageTitle' => __('portal.nav_contact'),
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

}
