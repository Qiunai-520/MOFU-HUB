<?php

declare(strict_types=1);

namespace Mofu\Modules\Lab;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Modules\Growth\LevelService;

/**
 * 社区实验室：实验功能开关 + 灰度。
 *
 * 灰度：all（所有登录用户）/ verified（正式会员）/ flash（硬核会员）/ invite（仅后台开通）。
 * 用户自助开启只有前三种可用；invite 只能由管理员写 lab_access。
 */
final class LabService
{
    public const ROLLOUT_LABELS = [
        'all' => '所有登录用户',
        'verified' => '正式会员',
        'flash' => '硬核会员（闪电）',
        'invite' => '仅限邀请',
    ];

    public function __construct(
        private readonly Query $query,
        private readonly LevelService $levels,
    ) {
    }

    /** @return list<array<string, mixed>> 后台列表（含未启用） */
    public function adminList(): array
    {
        return array_map($this->present(...), $this->query->all('SELECT * FROM lab_features ORDER BY sort ASC, id ASC'));
    }

    /**
     * 前台列表：附「是否可开启」与「我已开启」。
     *
     * @return list<array<string, mixed>>
     */
    public function features(int $userId): array
    {
        $joined = array_flip($this->joinedKeys($userId));
        $out = [];

        foreach ($this->adminList() as $feature) {
            $key = (string) $feature['key'];
            $feature['available'] = $this->eligible($userId, $feature) || isset($joined[$key]);
            $feature['joined'] = isset($joined[$key]);
            $out[] = $feature;
        }

        return $out;
    }

    /** @return list<string> */
    public function joinedKeys(int $userId): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['feature_key'],
            $this->query->all('SELECT feature_key FROM lab_access WHERE user_id = :id', ['id' => $userId]),
        );
    }

    /** 代码里判断某实验功能对该用户是否开启 */
    public function isEnabled(int $userId, string $key): bool
    {
        $row = $this->query->one('SELECT * FROM lab_features WHERE feature_key = :key', ['key' => $key]);

        if ($row === null || (int) $row['enabled'] !== 1) {
            return false;
        }

        $access = $this->query->value(
            'SELECT feature_key FROM lab_access WHERE user_id = :id AND feature_key = :key',
            ['id' => $userId, 'key' => $key],
        );

        return $access !== null;
    }

    public function join(int $userId, string $key): void
    {
        $row = $this->query->one('SELECT * FROM lab_features WHERE feature_key = :key', ['key' => $key]);

        if ($row === null) {
            throw HttpException::notFound('实验功能不存在');
        }

        if (!$this->eligible($userId, $row)) {
            throw HttpException::forbidden('你暂时还不能开启这个实验功能（' . (self::ROLLOUT_LABELS[(string) $row['rollout']] ?? '') . '）');
        }

        $this->query->run(
            'INSERT IGNORE INTO lab_access (user_id, feature_key, enabled_at) VALUES (:id, :key, :now)',
            ['id' => $userId, 'key' => $key, 'now' => date('Y-m-d H:i:s')],
        );
    }

    public function leave(int $userId, string $key): void
    {
        $this->query->delete('lab_access', 'user_id = :id AND feature_key = :key', ['id' => $userId, 'key' => $key]);
    }

    /** 后台新建 / 更新 */
    public function save(?int $id, array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw HttpException::badRequest('请填写功能名称');
        }

        $key = trim((string) ($data['feature_key'] ?? ''));

        if ($id === null || $id <= 0) {
            $key = $key !== '' ? $key : 'lab-' . bin2hex(random_bytes(4));
            $key = preg_replace('/[^a-z0-9\-_]/', '', mb_strtolower($key)) ?: ('lab-' . bin2hex(random_bytes(4)));

            if ($this->query->value('SELECT id FROM lab_features WHERE feature_key = :key', ['key' => $key]) !== null) {
                throw HttpException::badRequest('功能标识已存在');
            }
        }

        $rollout = (string) ($data['rollout'] ?? 'flash');

        $payload = [
            'name' => mb_substr($name, 0, 64),
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 255),
            'enabled' => !empty($data['enabled']) ? 1 : 0,
            'rollout' => array_key_exists($rollout, self::ROLLOUT_LABELS) ? $rollout : 'flash',
            'sort' => (int) ($data['sort'] ?? 0),
        ];

        if ($id !== null && $id > 0) {
            $this->query->update('lab_features', $payload, 'id = :id', ['id' => $id]);

            return $id;
        }

        return $this->query->insert('lab_features', $payload + [
            'feature_key' => $key,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function toggle(int $id, bool $enabled): void
    {
        $this->query->update('lab_features', ['enabled' => $enabled ? 1 : 0], 'id = :id', ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $key = $this->query->value('SELECT feature_key FROM lab_features WHERE id = :id', ['id' => $id]);

        $this->query->delete('lab_features', 'id = :id', ['id' => $id]);

        if ($key !== null) {
            $this->query->delete('lab_access', 'feature_key = :key', ['key' => (string) $key]);
        }
    }

    /** 用户是否满足该功能的灰度条件 */
    private function eligible(int $userId, array $feature): bool
    {
        if ((int) $feature['enabled'] !== 1) {
            return false;
        }

        $rollout = (string) $feature['rollout'];

        if ($rollout === 'invite') {
            return false;
        }

        $stats = $this->levels->stats($userId);

        return match ($rollout) {
            'all' => true,
            'verified' => (bool) ($stats['is_formal'] ?? false),
            'flash' => (bool) ($stats['is_flash'] ?? false),
            default => false,
        };
    }

    /** @param array<string, mixed> $row */
    private function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'key' => (string) $row['feature_key'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'enabled' => (int) $row['enabled'] === 1,
            'rollout' => (string) $row['rollout'],
            'rollout_label' => self::ROLLOUT_LABELS[(string) $row['rollout']] ?? (string) $row['rollout'],
            'sort' => (int) $row['sort'],
        ];
    }
}
