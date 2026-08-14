<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\FinancingProductRepository;
use App\Repositories\ServiceOfferingRepository;
use App\Services\FinancingProductService;
use App\Services\ServiceOfferingService;

/**
 * دليل الفرص العام | Public catalogue of financing and services (§4.5, §4.6).
 *
 * الظاهر هنا معتمد من المنصة ومملوك لجهة موثّقة، بلا استثناء. الزائر يرى
 * الشروط المعلنة كاملة قبل أي تسجيل، لأن إخفاءها خلف تسجيل الدخول يحوّل
 * الاطلاع على الشروط إلى مقايضة ببيانات شخصية.
 */
final class FinancingController extends Controller
{
    public function __construct(
        private readonly FinancingProductRepository $products = new FinancingProductRepository(),
        private readonly ServiceOfferingRepository $offerings = new ServiceOfferingRepository(),
        private readonly FinancingProductService $productService = new FinancingProductService(),
        private readonly ServiceOfferingService $offeringService = new ServiceOfferingService(),
    ) {
    }

    // ═══════════════════ التمويل | Financing ═══════════════════

    public function financingIndex(Request $request): Response
    {
        $filters = [
            'q'              => (string) ($request->input('q') ?? ''),
            'financing_type' => (string) ($request->input('financing_type') ?? ''),
            'amount'         => (string) ($request->input('amount') ?? ''),
            'governorate_id' => (int) ($request->input('governorate_id') ?? 0),
            'sector_id'      => (int) ($request->input('sector_id') ?? 0),
            'sort'           => (string) ($request->input('sort') ?? 'newest'),
        ];

        return $this->view('public/financing/index', [
            'pageTitle'      => 'فرص التمويل',
            'metaDescription' => 'منتجات تمويلية معتمدة من مؤسسات مالية موثّقة، بشروط معلنة قبل التقديم.',
            'results'        => $this->products->searchPublic($filters, $this->page($request)),
            'filters'        => $filters,
            'types'          => FinancingProductService::TYPES,
            'governorates'   => $this->governorates(),
            'sectors'        => $this->sectors(),
            'service'        => $this->productService,
        ], 'public');
    }

    public function financingShow(Request $request): Response
    {
        $product = $this->products->findPublicBySlug((string) $request->route('slug'));

        if ($product === null) {
            throw new HttpException(404, 'المنتج التمويلي المطلوب غير متاح.');
        }

        $this->products->incrementViews((int) $product['id']);

        return $this->view('public/financing/show', [
            'pageTitle'       => (string) $product['name_ar'],
            'metaDescription' => (string) ($product['short_description'] ?? ''),
            'product'         => $product,
            'service'         => $this->productService,
            'related'         => $this->products->searchPublic([
                'financing_type' => (string) $product['financing_type'],
            ], 1, 4)['data'],
        ], 'public');
    }

    // ═══════════════════ الخدمات | Services ═══════════════════

    public function servicesIndex(Request $request): Response
    {
        $filters = [
            'q'              => (string) ($request->input('q') ?? ''),
            'service_type'   => (string) ($request->input('service_type') ?? ''),
            'delivery_mode'  => (string) ($request->input('delivery_mode') ?? ''),
            'governorate_id' => (int) ($request->input('governorate_id') ?? 0),
            'sector_id'      => (int) ($request->input('sector_id') ?? 0),
            'free_only'      => $request->input('free_only') ? '1' : '',
            'sort'           => (string) ($request->input('sort') ?? 'newest'),
        ];

        return $this->view('public/services/index', [
            'pageTitle'       => 'خدمات تطوير الأعمال',
            'metaDescription' => 'باقات خدمات معتمدة من مقدّمي خدمات ومنظمات موثّقة لدعم المشروعات الصغيرة والمتوسطة.',
            'results'         => $this->offerings->searchPublic($filters, $this->page($request)),
            'filters'         => $filters,
            'types'           => ServiceOfferingService::TYPES,
            'deliveryModes'   => ServiceOfferingService::DELIVERY_MODES,
            'governorates'    => $this->governorates(),
            'sectors'         => $this->sectors(),
            'service'         => $this->offeringService,
        ], 'public');
    }

    public function servicesShow(Request $request): Response
    {
        $offering = $this->offerings->findPublicBySlug((string) $request->route('slug'));

        if ($offering === null) {
            throw new HttpException(404, 'الخدمة المطلوبة غير متاحة.');
        }

        $this->offerings->incrementViews((int) $offering['id']);

        return $this->view('public/services/show', [
            'pageTitle'       => (string) $offering['name_ar'],
            'metaDescription' => (string) ($offering['short_description'] ?? ''),
            'offering'        => $offering,
            'service'         => $this->offeringService,
            'related'         => $this->offerings->searchPublic([
                'service_type' => (string) $offering['service_type'],
            ], 1, 4)['data'],
        ], 'public');
    }

    // ─────────────────── مراجع | Reference lookups ───────────────────

    /** @return array<int,array<string,mixed>> */
    private function governorates(): array
    {
        return Database::select(
            'SELECT id, name_ar FROM governorates WHERE is_active = 1 ORDER BY sort_order ASC',
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function sectors(): array
    {
        return Database::select(
            'SELECT id, name_ar FROM sectors WHERE is_active = 1 ORDER BY sort_order ASC',
        );
    }
}
