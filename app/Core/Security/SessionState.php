<?php

declare(strict_types=1);

namespace Mofu\Core\Security;

/**
 * 请求生命周期内的会话状态（由 SessionMiddleware 创建，经请求属性传递）。
 */
final class SessionState
{
    /** @var array<string, mixed>|null 当前会话记录 */
    public ?array $session = null;

    /** @var array<string, mixed>|null 当前登录用户 */
    public ?array $user = null;

    /** 需要随响应下发的会话 cookie 值（新建或轮换后） */
    public ?string $cookieValue = null;

    /** 是否需要清除客户端 cookie（登出） */
    public bool $clearCookie = false;

    /** 是否已登录 */
    public function isAuthenticated(): bool
    {
        return $this->user !== null;
    }

    /** 当前 CSRF token */
    public function csrfToken(): string
    {
        return (string) ($this->session['csrf_token'] ?? '');
    }

    public function userId(): ?int
    {
        $id = $this->session['user_id'] ?? null;

        return $id === null ? null : (int) $id;
    }
}
