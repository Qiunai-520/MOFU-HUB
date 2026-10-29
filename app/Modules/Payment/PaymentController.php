<?php

declare(strict_types=1);

namespace Mofu\Modules\Payment;

use Mofu\Bootstrap\Config;
use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Guard;
use Mofu\Core\View\Page;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PaymentController
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly Page $page,
        private readonly Config $config,
    ) {
    }

    /** @param array<string, string> $args 跳转易支付：渲染自动提交表单 */
    public function pay(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $order = $this->payments->findByOrderNo((string) ($args['orderNo'] ?? ''));

        if ($order === null || (int) $order['user_id'] !== (int) $user['id']) {
            throw HttpException::notFound('订单不存在');
        }

        if ((string) $order['status'] !== 'pending') {
            return $response
                ->withHeader('Location', '/payment/result?order=' . rawurlencode((string) $order['order_no']))
                ->withStatus(302);
        }

        $client = $this->payments->client();
        $base = rtrim((string) $this->config->get('app.url', ''), '/');

        $params = $client->submitParams(
            (string) $order['order_no'],
            (string) $order['subject'],
            number_format((float) $order['amount_cny'], 2, '.', ''),
            'alipay',
            $base . '/api/v1/payment/ezfp/notify',
            $base . '/payment/result',
            (string) $this->config->get('app.name', 'MOFU'),
        );

        return $this->page->render($request, 'pages/payment/submit.twig', [
            'action' => $client->submitUrl(),
            'params' => $params,
        ]);
    }

    /** 易支付异步通知：验签后发货，需返回 success */
    public function notify(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $client = $this->payments->client();

        if (!$client->verify($params)) {
            $response->getBody()->write('fail');

            return $response->withStatus(400);
        }

        if ((string) ($params['trade_status'] ?? '') === 'TRADE_SUCCESS') {
            $this->payments->markPaid(
                (string) ($params['out_trade_no'] ?? ''),
                (string) ($params['trade_no'] ?? ''),
                (string) ($params['type'] ?? ''),
            );
        }

        $response->getBody()->write('success');

        return $response;
    }

    /** 页面跳转回调（return_url） */
    public function result(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $orderNo = (string) ($params['out_trade_no'] ?? $params['order'] ?? '');
        $client = $this->payments->client();
        $status = 'pending';

        if (
            $orderNo !== ''
            && isset($params['sign'])
            && $client->verify($params)
            && (string) ($params['trade_status'] ?? '') === 'TRADE_SUCCESS'
        ) {
            $this->payments->markPaid(
                $orderNo,
                (string) ($params['trade_no'] ?? ''),
                (string) ($params['type'] ?? ''),
            );
            $status = 'paid';
        } elseif ($orderNo !== '') {
            $order = $this->payments->findByOrderNo($orderNo);
            $status = $order !== null ? (string) $order['status'] : 'missing';
        }

        return $this->page->render($request, 'pages/payment/result.twig', [
            'orderNo' => $orderNo,
            'status' => $status,
        ]);
    }
}
