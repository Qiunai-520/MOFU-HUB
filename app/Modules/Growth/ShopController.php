<?php

declare(strict_types=1);

namespace Mofu\Modules\Growth;

use Mofu\Core\Http\Ctx;
use Mofu\Core\View\Page;
use Mofu\Modules\Payment\PaymentService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * MOFU小店（前台）：商品同时支持 M币 购买与真实货币在线支付（易支付）。
 */
final class ShopController
{
    public function __construct(
        private readonly ShopService $shop,
        private readonly WalletService $wallet,
        private readonly PaymentService $payments,
        private readonly Page $page,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = Ctx::user($request);
        $userId = $user !== null ? (int) $user['id'] : null;

        return $this->page->render($request, 'pages/shop.twig', [
            'products' => $this->shop->products(true, $userId),
            'balance' => $userId !== null ? $this->wallet->balance($userId) : null,
            'inventory' => $userId !== null ? $this->shop->inventory($userId) : [],
            'paymentReady' => $this->payments->configured(),
        ]);
    }
}
