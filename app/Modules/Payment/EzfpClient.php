<?php

declare(strict_types=1);

namespace Mofu\Modules\Payment;

/**
 * 易支付 V1（MD5 签名）客户端。
 *
 * 页面跳转支付：POST {api}submit.php
 *   pid, type, out_trade_no, notify_url, return_url, name, money, sitename, sign, sign_type=MD5
 * 异步通知：GET notify_url，参数含 trade_status（TRADE_SUCCESS 才算成功），需返回 `success`。
 *
 * 签名算法：所有参与参数按参数名 ASCII 升序，拼成 a=b&c=d（值不做 urlencode，
 * 排除 sign / sign_type / 空值），末尾拼接商户密钥后取 md5（小写）。
 */
final class EzfpClient
{
    public function __construct(
        private readonly string $api,
        private readonly string $pid,
        private readonly string $key,
    ) {
    }

    public function configured(): bool
    {
        return $this->pid !== '' && $this->key !== '';
    }

    /** 生成签名（发送与验签共用） */
    public function sign(array $params): string
    {
        unset($params['sign'], $params['sign_type']);

        $filtered = [];

        foreach ($params as $name => $value) {
            $value = is_scalar($value) ? (string) $value : '';

            if ($value === '') {
                continue;
            }

            $filtered[(string) $name] = $value;
        }

        ksort($filtered, SORT_STRING);

        $pairs = [];

        foreach ($filtered as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return md5(implode('&', $pairs) . $this->key);
    }

    /**
     * 页面跳转支付表单参数（含签名）。
     *
     * @return array<string, string>
     */
    public function submitParams(string $outTradeNo, string $name, string $money, string $type, string $notifyUrl, string $returnUrl, string $siteName): array
    {
        $params = [
            'pid' => $this->pid,
            'type' => $type,
            'out_trade_no' => $outTradeNo,
            'notify_url' => $notifyUrl,
            'return_url' => $returnUrl,
            'name' => $name,
            'money' => $money,
            'sitename' => $siteName,
            'sign_type' => 'MD5',
        ];

        $params['sign'] = $this->sign($params);

        return $params;
    }

    public function submitUrl(): string
    {
        return $this->api . 'submit.php';
    }

    /** 校验异步通知签名 */
    public function verify(array $params): bool
    {
        $given = (string) ($params['sign'] ?? '');

        if ($given === '') {
            return false;
        }

        return hash_equals($this->sign($params), strtolower($given));
    }
}
