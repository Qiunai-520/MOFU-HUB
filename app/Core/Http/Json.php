<?php

declare(strict_types=1);

namespace Mofu\Core\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * 统一 JSON 响应格式：
 *   成功 {"ok":true,"data":...,"meta":{...}}
 *   失败 {"ok":false,"error":{"code":"...","message":"..."}}
 */
final class Json
{
    /** @param array<string, mixed> $meta */
    public static function ok(ResponseInterface $response, mixed $data = null, array $meta = [], int $status = 200): ResponseInterface
    {
        $payload = ['ok' => true, 'data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return self::write($response, $payload, $status);
    }

    public static function error(ResponseInterface $response, string $code, string $message, int $status = 400): ResponseInterface
    {
        return self::write($response, [
            'ok' => false,
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }

    /** @param array<string, mixed> $payload */
    private static function write(ResponseInterface $response, array $payload, int $status): ResponseInterface
    {
        $response->getBody()->write((string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }
}
