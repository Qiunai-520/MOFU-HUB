<?php

declare(strict_types=1);

namespace Mofu\Modules\Themes;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;

/**
 * 用户主题的保存、激活与读取。
 * css_vars 为前端（HCT 算法）生成后提交的变量集合，服务端严格白名单校验。
 */
final class ThemeService
{
    public const VAR_NAME_PATTERN = '/^--md-sys-[a-z0-9-]{1,60}$/';
    public const VAR_VALUE_PATTERN = '/^(#[0-9a-fA-F]{3,8}|rgba?\(\s*[0-9.,%\s]+\)|-?[0-9]+(\.[0-9]+)?)$/';

    public const VARIANTS = [
        'tonalSpot', 'vibrant', 'expressive', 'content', 'fidelity',
        'neutral', 'monochrome', 'rainbow', 'fruitSalad',
    ];

    private const MAX_VARS = 600;

    public function __construct(private readonly Query $query)
    {
    }

    /** @return array<string, mixed>|null 当前激活主题 */
    public function activeFor(int $userId): ?array
    {
        return $this->query->one(
            'SELECT * FROM user_themes WHERE user_id = :id AND is_active = 1 ORDER BY id DESC LIMIT 1',
            ['id' => $userId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function allFor(int $userId): array
    {
        return $this->query->all(
            'SELECT id, name, source_color, scheme, contrast, variant, density, motion, is_active, updated_at
             FROM user_themes WHERE user_id = :id ORDER BY updated_at DESC',
            ['id' => $userId],
        );
    }

    /**
     * 保存并激活一套主题（同名则覆盖更新）。
     *
     * @param array<string, mixed> $input
     * @return int 主题 id
     */
    public function save(int $userId, array $input): int
    {
        $name = trim((string) ($input['name'] ?? '我的主题'));

        if ($name === '' || mb_strlen($name) > 24) {
            throw HttpException::badRequest('主题名称需为 1-24 字');
        }

        // The brand accent is fixed. Appearance is the only user-controlled
        // dimension; legacy palette fields are accepted for API compatibility
        // but deliberately ignored.
        $sourceColor = '#FFCC38';
        $scheme = (string) ($input['scheme'] ?? 'auto');
        $contrast = 'standard';
        $variant = 'tonalSpot';
        $motion = 'standard';
        $density = 0;

        if (!in_array($scheme, ['auto', 'light', 'dark'], true)) {
            throw HttpException::badRequest('明暗方案无效');
        }

        // css_vars 结构为 {"light": {...}, "dark": {...}}，分别白名单校验后合并
        $cssVars = [];

        foreach (['light', 'dark'] as $set) {
            $vars = $input['css_vars'][$set] ?? null;

            if (is_array($vars)) {
                $cssVars[$set] = ThemeVars::sanitize($vars);
            }
        }

        if (($cssVars['light'] ?? []) === [] && ($cssVars['dark'] ?? []) === []) {
            // Appearance-only clients may omit the legacy palette payload.
            $cssVars = [
                'light' => ['--md-sys-color-primary' => '#FFCC38'],
                'dark' => ['--md-sys-color-primary' => '#FFCC38'],
            ];
        }

        $now = date('Y-m-d H:i:s');
        $payload = [
            'name' => $name,
            'source_color' => $sourceColor,
            'scheme' => $scheme,
            'contrast' => $contrast,
            'variant' => $variant,
            'density' => $density,
            'motion' => $motion,
            'css_vars' => (string) json_encode($cssVars, JSON_UNESCAPED_UNICODE),
            'is_active' => 1,
            'updated_at' => $now,
        ];

        $existing = $this->query->one(
            'SELECT id FROM user_themes WHERE user_id = :user_id AND name = :name LIMIT 1',
            ['user_id' => $userId, 'name' => $name],
        );

        return $this->query->transaction(function (Query $q) use ($userId, $payload, $existing, $now): int {
            $q->run('UPDATE user_themes SET is_active = 0 WHERE user_id = :user_id', ['user_id' => $userId]);

            if ($existing !== null) {
                $q->update('user_themes', $payload, 'id = :id', ['id' => (int) $existing['id']]);

                return (int) $existing['id'];
            }

            return $q->insert('user_themes', $payload + ['user_id' => $userId, 'created_at' => $now]);
        });
    }

    public function activate(int $userId, int $themeId): void
    {
        $theme = $this->query->one(
            'SELECT id FROM user_themes WHERE id = :id AND user_id = :user_id',
            ['id' => $themeId, 'user_id' => $userId],
        );

        if ($theme === null) {
            throw HttpException::notFound('主题不存在');
        }

        $this->query->transaction(function (Query $q) use ($userId, $themeId): void {
            $q->run('UPDATE user_themes SET is_active = 0 WHERE user_id = :user_id', ['user_id' => $userId]);
            $q->update('user_themes', ['is_active' => 1, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $themeId]);
        });
    }

    public function delete(int $userId, int $themeId): void
    {
        $deleted = $this->query->delete('user_themes', 'id = :id AND user_id = :user_id', [
            'id' => $themeId,
            'user_id' => $userId,
        ]);

        if ($deleted === 0) {
            throw HttpException::notFound('主题不存在');
        }
    }

    /**
     * 由 light/dark 两套变量与方案生成内联 CSS。
     * 结构：{"light": {...}, "dark": {...}}
     *
     * @param array<mixed> $vars
     */
}
