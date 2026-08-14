<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\CartService;
use App\Services\OrderService;
use App\Validation\Validator;

/**
 * السلة وإتمام الشراء | Cart and checkout (§4.4).
 *
 * يعمل للزائر والمستخدم المسجَّل. سلة الزائر مرتبطة بكوكي HttpOnly يحمل رمزاً
 * عشوائياً — لا معرّف سلة رقمي، وإلا لأمكن تصفّح سلال الآخرين بتغيير الرقم.
 * Works for guests and signed-in users. A guest cart is bound to an HttpOnly
 * cookie holding a random token, never a numeric cart id — a numeric id would
 * let anyone browse other people's carts by changing it.
 */
final class CartController extends Controller
{
    public function __construct(
        private readonly CartService $carts = new CartService(),
        private readonly OrderService $orders = new OrderService(),
    ) {
    }

    public function show(Request $request): Response
    {
        $cart = $this->currentCart($request, false);

        $contents = $cart === null
            ? ['groups' => [], 'totals' => [], 'count' => 0]
            : $this->carts->contents((int) $cart['id']);

        return $this->view('public/cart/show', [
            'pageTitle' => 'سلة المشتريات',
            'contents'  => $contents,
        ], 'public');
    }

    public function add(Request $request): Response
    {
        $listingId = $request->integer('listing_id');
        $quantity  = (float) ($request->input('quantity') ?? 1);

        if ($listingId === null) {
            throw new HttpException(422, 'لم يُحدَّد الصنف.');
        }

        [$cart, $response] = $this->ensureCart($request);

        try {
            $this->carts->add((int) $cart['id'], $listingId, max(0.001, $quantity));
            $this->flash('success', 'تمت إضافة الصنف إلى السلة.');
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());
        }

        $redirect = $this->back($request, [], '/cart');

        // الكوكي يُضبط على استجابة إعادة التوجيه حتى يبقى الزائر مرتبطاً بسلته
        return $response === null ? $redirect : $this->withCartCookie($redirect, $response);
    }

    public function update(Request $request): Response
    {
        $cart = $this->currentCart($request, false);

        if ($cart !== null) {
            $itemId   = $request->integer('item_id');
            $quantity = (float) ($request->input('quantity') ?? 0);

            if ($itemId !== null) {
                $this->carts->updateQuantity((int) $cart['id'], $itemId, $quantity);
            }
        }

        return $this->redirect('/cart');
    }

    public function remove(Request $request): Response
    {
        $cart = $this->currentCart($request, false);

        if ($cart !== null) {
            $itemId = $request->integer('item_id');

            if ($itemId !== null) {
                $this->carts->remove((int) $cart['id'], $itemId);
                $this->flash('success', 'تمت إزالة الصنف من السلة.');
            }
        }

        return $this->redirect('/cart');
    }

    // ═══════════════════ إتمام الشراء | Checkout ═══════════════════

    public function checkoutForm(Request $request): Response
    {
        $cart = $this->currentCart($request, false);

        if ($cart === null || $this->carts->itemCount((int) $cart['id']) === 0) {
            $this->flash('info', 'سلة المشتريات فارغة.');

            return $this->redirect('/marketplace');
        }

        $issues = $this->carts->blockingIssues((int) $cart['id']);

        return $this->view('public/cart/checkout', [
            'pageTitle'      => 'إتمام الطلب',
            'contents'       => $this->carts->contents((int) $cart['id']),
            'issues'         => $issues,
            'paymentMethods' => $this->paymentMethods(),
            'governorates'   => Database::select(
                'SELECT id, name_ar FROM governorates WHERE is_active = 1 ORDER BY sort_order ASC',
            ),
            'currentUser'    => $this->currentUser(),
        ], 'public');
    }

    public function placeOrder(Request $request): Response
    {
        $cart = $this->currentCart($request, false);

        if ($cart === null || $this->carts->itemCount((int) $cart['id']) === 0) {
            $this->flash('info', 'سلة المشتريات فارغة.');

            return $this->redirect('/marketplace');
        }

        $validator = Validator::make($request->all())
            ->labels([
                'customer_name'    => 'الاسم',
                'customer_phone'   => 'رقم الهاتف',
                'customer_email'   => 'البريد الإلكتروني',
                'governorate_id'   => 'المحافظة',
                'delivery_address' => 'عنوان التسليم',
            ])
            ->required('customer_name')->minLength('customer_name', 3)->maxLength('customer_name', 150)
            ->required('customer_phone')->phone('customer_phone')
            ->email('customer_email')
            ->required('governorate_id')->integer('governorate_id')
            ->required('delivery_address')->minLength('delivery_address', 10)->maxLength('delivery_address', 500)
            ->maxLength('customer_note', 1000);

        if ($validator->fails()) {
            return $this->back($request, $validator->errors(), '/checkout');
        }

        // فحص أخير قبل الإنشاء: قد يكون صنف نفد أو سُحب أثناء ملء النموذج
        $issues = $this->carts->blockingIssues((int) $cart['id']);

        if ($issues !== []) {
            $this->flash('warning', 'تعذّر إتمام الطلب: ' . implode(' · ', $issues));

            return $this->redirect('/cart');
        }

        try {
            $orderIds = $this->orders->checkout(
                cartId: (int) $cart['id'],
                customer: [
                    'user_id'        => $this->currentUserId(),
                    'name'           => (string) $request->input('customer_name'),
                    'phone'          => (string) $request->input('customer_phone'),
                    'email'          => $request->filled('customer_email') ? (string) $request->input('customer_email') : null,
                    'governorate_id' => $request->integer('governorate_id'),
                    'city_id'        => $request->integer('city_id'),
                    'address'        => (string) $request->input('delivery_address'),
                    'note'           => $request->filled('customer_note') ? (string) $request->input('customer_note') : null,
                    'source'         => 'marketplace',
                ],
                paymentMethodId: $request->integer('payment_method_id'),
                request: $request,
            );
        } catch (HttpException $e) {
            $this->flash('warning', $e->getMessage());

            return $this->redirect('/cart');
        }

        // رموز التتبّع تُعرض في صفحة التأكيد: هي وسيلة الزائر الوحيدة لمتابعة طلبه
        $tokens = [];
        foreach ($orderIds as $orderId) {
            $token = Database::scalar('SELECT tracking_token FROM orders WHERE id = ?', [$orderId]);
            if (is_string($token)) {
                $tokens[] = $token;
            }
        }

        Session::put('_recent_order_tokens', $tokens);

        return $this->redirect('/checkout/confirmation');
    }

    public function confirmation(Request $request): Response
    {
        $tokens = Session::get('_recent_order_tokens', []);

        if (!is_array($tokens) || $tokens === []) {
            return $this->redirect('/marketplace');
        }

        $orders = [];
        foreach ($tokens as $token) {
            $order = Database::selectOne(
                'SELECT o.*, org.legal_name, org.trading_name
                   FROM orders o
                   JOIN organizations org ON org.id = o.organization_id
                  WHERE o.tracking_token = ? LIMIT 1',
                [$token],
            );

            if ($order !== null) {
                $orders[] = $order;
            }
        }

        return $this->view('public/cart/confirmation', [
            'pageTitle' => 'تم استلام طلبك',
            'orders'    => $orders,
        ], 'public');
    }

    // ─────────────────── أدوات | Helpers ───────────────────

    /**
     * السلة الحالية | The current cart.
     * @return array<string,mixed>|null
     */
    private function currentCart(Request $request, bool $create = true): ?array
    {
        return $this->carts->resolveCart(
            $this->currentUserId(),
            $request->cookie(CartService::COOKIE_NAME),
            $create,
        );
    }

    /**
     * ضمان وجود سلة، وإرجاع رمز الزائر إن أُنشئ | Ensure a cart exists.
     * @return array{0:array<string,mixed>,1:string|null}
     */
    private function ensureCart(Request $request): array
    {
        $existingToken = $request->cookie(CartService::COOKIE_NAME);
        $cart          = $this->carts->resolveCart($this->currentUserId(), $existingToken, true);

        if ($cart === null) {
            throw new HttpException(500, 'تعذّر تجهيز سلة المشتريات.');
        }

        $newToken = null;

        if ($this->currentUserId() === null
            && is_string($cart['guest_token'])
            && $cart['guest_token'] !== $existingToken
        ) {
            $newToken = (string) $cart['guest_token'];
        }

        return [$cart, $newToken];
    }

    private function withCartCookie(Response $response, string $token): Response
    {
        return $response->withCookie(
            CartService::COOKIE_NAME,
            $token,
            time() + (30 * 24 * 60 * 60), // 30 يوماً
            httpOnly: true,
            sameSite: 'Lax',
        );
    }

    private function paymentMethods(): array
    {
        return Database::select(
            'SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY sort_order ASC',
        );
    }
}
