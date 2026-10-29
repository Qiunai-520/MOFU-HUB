<?php

declare(strict_types=1);

namespace Mofu\Modules\Growth;

use Mofu\Core\Http\ApiController;
use Mofu\Modules\Payment\PaymentService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GrowthApiController extends ApiController
{
    public function __construct(
        private readonly GrowthService $growth,
        private readonly WalletService $wallet,
        private readonly CheckInService $checkin,
        private readonly TitleService $titles,
        private readonly ShopService $shop,
        private readonly PaymentService $payments,
    ) {
    }

    public function profile(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $userId = (int) $user['id'];

        $this->growth->refresh($userId);

        return $this->ok($response, $this->growth->profile($userId));
    }

    public function wallet(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $page = max(1, (int) ($request->getQueryParams()['page'] ?? 1));

        $result = $this->wallet->history((int) $user['id'], $page, 20);

        return $this->ok($response, $result['items'], [
            'wallet' => $this->wallet->wallet((int) $user['id']),
            'pagination' => [
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'pages' => $result['pages'],
            ],
        ]);
    }

    public function checkin(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $userId = (int) $user['id'];

        $result = $this->checkin->checkin($userId);
        $this->growth->refresh($userId);

        return $this->ok($response, $result);
    }

    public function calendar(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $month = (string) ($request->getQueryParams()['month'] ?? '');

        return $this->ok($response, $this->checkin->calendar((int) $user['id'], $month));
    }

    public function equipTitle(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $input = $this->input($request);
        $titleId = (int) ($input['title_id'] ?? 0);

        $this->titles->equip((int) $user['id'], $titleId > 0 ? $titleId : null);

        return $this->ok($response, ['equipped_id' => $titleId > 0 ? $titleId : null]);
    }

    public function shop(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);

        return $this->ok($response, $this->shop->products(true, (int) $user['id']), [
            'balance' => $this->wallet->balance((int) $user['id']),
        ]);
    }

    /** @param array<string, string> $args */
    public function purchase(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);

        $result = $this->shop->purchase((int) $user['id'], (int) $args['id']);

        return $this->ok($response, $result, [], 201);
    }

    /** @param array<string, string> $args 真实货币商品：下单并返回支付跳转地址 */
    public function payProduct(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $userId = (int) $user['id'];
        $productId = (int) ($args['id'] ?? 0);

        $begin = $this->shop->beginCnyOrder($userId, $productId);

        $order = $this->payments->create(
            $userId,
            PaymentService::KIND_SHOP,
            $productId,
            (string) $begin['product']['name'],
            (float) $begin['amount'],
            ['product_id' => $productId],
        );

        $this->shop->attachCnyOrder($userId, $productId, (string) $order['order_no']);

        return $this->ok($response, [
            'order_no' => (string) $order['order_no'],
            'pay_url' => '/pay/' . (string) $order['order_no'],
        ]);
    }

    public function orders(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $page = max(1, (int) ($request->getQueryParams()['page'] ?? 1));

        $result = $this->shop->orders($page, 20, (int) $user['id']);

        return $this->ok($response, $result['items'], [
            'pagination' => [
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'pages' => $result['pages'],
            ],
        ]);
    }
}
