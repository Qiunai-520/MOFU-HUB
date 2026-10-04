<?php

declare(strict_types=1);

namespace Mofu\Core\Http;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * API 控制器基类：当前用户与 JSON 响应便捷方法。
 */
abstract class ApiController
{
    /** @return array<string, mixed>|null */
    protected function user(Request $request): ?array
    {
        return Ctx::user($request);
    }

    /** @return array<string, mixed> */
    protected function requireUser(Request $request): array
    {
        $user = Ctx::user($request);

        if ($user === null) {
            throw HttpException::unauthorized();
        }

        return $user;
    }

    /** @param array<string, mixed> $input */
    protected function input(Request $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    /** @param array<string, mixed> $meta */
    protected function ok(Response $response, mixed $data = null, array $meta = [], int $status = 200): Response
    {
        return Json::ok($response, $data, $meta, $status);
    }

    protected function error(Response $response, string $code, string $message, int $status = 400): Response
    {
        return Json::error($response, $code, $message, $status);
    }
}
