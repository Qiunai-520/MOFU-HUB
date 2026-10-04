<?php

declare(strict_types=1);

namespace Mofu\Core\Http;

use RuntimeException;

/**
 * 携带 HTTP 状态码的业务异常。错误处理器据此生成响应。
 */
final class HttpException extends RuntimeException
{
    /** @param array<string, mixed> $headers */
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        private readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, mixed> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public static function notFound(string $message = '页面不存在'): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = '没有权限执行此操作'): self
    {
        return new self(403, $message);
    }

    public static function unauthorized(string $message = '请先登录'): self
    {
        return new self(401, $message);
    }

    /**
     * 需要登录时跳转到登录页（浏览器请求）；API 请求由 ErrorHandler 转为 401 JSON。
     */
    public static function redirect(string $to, string $message = '请先登录'): self
    {
        return new self(302, $message, ['Location' => $to]);
    }

    public static function badRequest(string $message = '请求参数有误'): self
    {
        return new self(422, $message);
    }

    public static function tooManyRequests(string $message = '操作过于频繁，请稍后再试'): self
    {
        return new self(429, $message);
    }
}
