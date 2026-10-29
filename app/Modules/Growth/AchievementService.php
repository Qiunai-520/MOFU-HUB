<?php

declare(strict_types=1);

namespace Mofu\Modules\Growth;

use Mofu\Core\Database\Query;
use Mofu\Modules\Notifications\NotificationService;

/**
 * 成就系统。进度由服务端根据真实数据计算，客户端只读。
 */
final class AchievementService
{
    /** @var array<int, array<string, int>> */
    private array $progressCache = [];

    /** 条件类型 → 说明文案（进度来源见 progressFor） */
    public const CONDITION_LABELS = [
        'post_count' => '发布动态',
        'comment_count' => '发表评论',
        'checkin_streak' => '连续签到',
        'likes_received' => '获得点赞',
        'mofu_earned' => '累计获得 M币',
    ];

    public function __construct(
        private readonly Query $query,
        private readonly WalletService $wallet,
        private readonly LevelService $levels,
        private readonly TitleService $titles,
        private readonly NotificationService $notifications,
        private readonly \Mofu\Core\Support\AuditLogger $audit,
    ) {
    }

    /** @return list<array<string, mixed>> 后台列表（含停用） */
    public function adminList(): array
    {
        return $this->query->all('SELECT * FROM achievements ORDER BY sort ASC, id ASC');
    }

    /** @param array<string, mixed> $data */
    public function save(?int $id, array $data, int $actorId): int
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw \Mofu\Core\Http\HttpException::badRequest('请填写成就名称');
        }

        $payload = [
            'name' => mb_substr($name, 0, 48),
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 255),
            'icon' => mb_substr(trim((string) ($data['icon'] ?? 'emoji_events')), 0, 64),
            'category' => mb_substr(trim((string) ($data['category'] ?? 'general')), 0, 32),
            'condition_type' => (string) ($data['condition_type'] ?? 'post_count'),
            'condition_value' => max(1, (int) ($data['condition_value'] ?? 1)),
            'reward_mofu' => max(0, (int) ($data['reward_mofu'] ?? 0)),
            'reward_xp' => max(0, (int) ($data['reward_xp'] ?? 0)),
            'reward_title_id' => isset($data['reward_title_id']) && (int) $data['reward_title_id'] > 0 ? (int) $data['reward_title_id'] : null,
            'hidden' => !empty($data['hidden']) ? 1 : 0,
            'active' => array_key_exists('active', $data) && !$data['active'] ? 0 : 1,
            'sort' => (int) ($data['sort'] ?? 0),
        ];

        if ($id !== null && $id > 0) {
            $this->query->update('achievements', $payload, 'id = :id', ['id' => $id]);
            $this->audit->log($actorId, 'achievement.update', 'achievement', $id, ['name' => $name]);

            return $id;
        }

        $slug = preg_replace('/[^a-z0-9\-]/', '', mb_strtolower(trim((string) ($data['slug'] ?? '')))) ?: ('ach-' . bin2hex(random_bytes(4)));
        $newId = $this->query->insert('achievements', $payload + ['slug' => $slug, 'created_at' => date('Y-m-d H:i:s')]);
        $this->audit->log($actorId, 'achievement.create', 'achievement', $newId, ['name' => $name]);

        return $newId;
    }

    public function delete(int $id, int $actorId): void
    {
        $this->query->delete('achievements', 'id = :id', ['id' => $id]);
        $this->audit->log($actorId, 'achievement.delete', 'achievement', $id, []);
    }

    /** @return array{items: list<array<string, mixed>>, unlocked: int, total: int} */
    public function forUser(int $userId): array
    {
        $progress = $this->progressFor($userId);
        $unlockedAt = [];

        foreach ($this->query->all(
            'SELECT achievement_id, unlocked_at FROM user_achievements WHERE user_id = :user_id',
            ['user_id' => $userId],
        ) as $row) {
            $unlockedAt[(int) $row['achievement_id']] = $row['unlocked_at'];
        }

        $items = [];
        $unlocked = 0;

        foreach ($this->query->all('SELECT * FROM achievements WHERE active = 1 ORDER BY sort ASC, id ASC') as $row) {
            $id = (int) $row['id'];
            $type = (string) $row['condition_type'];
            $target = max(1, (int) $row['condition_value']);
            $current = min($target, $progress[$type] ?? 0);
            $isUnlocked = isset($unlockedAt[$id]) && $unlockedAt[$id] !== null;

            if ($isUnlocked) {
                $unlocked++;
                $current = $target;
            }

            $items[] = [
                'id' => $id,
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
                'description' => (string) $row['description'],
                'icon' => (string) $row['icon'],
                'category' => (string) $row['category'],
                'condition_type' => $type,
                'condition_text' => sprintf('%s %d', self::CONDITION_LABELS[$type] ?? '达成条件', $target),
                'progress' => $current,
                'target' => $target,
                'percent' => (int) min(100, round($current / $target * 100)),
                'unlocked' => $isUnlocked,
                'reward_mofu' => (int) $row['reward_mofu'],
                'reward_xp' => (int) $row['reward_xp'],
                'hidden' => (int) $row['hidden'] === 1,
            ];
        }

        return ['items' => $items, 'unlocked' => $unlocked, 'total' => count($items)];
    }

    /**
     * 重新计算成就进度并解锁达标项。
     *
     * @return list<string> 新解锁的成就 slug
     */
    public function evaluate(int $userId): array
    {
        $progress = $this->progressFor($userId);
        $granted = [];
        $achievements = $this->query->all('SELECT * FROM achievements WHERE active = 1');
        $existing = [];

        foreach ($this->query->all(
            'SELECT achievement_id, unlocked_at, progress FROM user_achievements WHERE user_id = :user_id',
            ['user_id' => $userId],
        ) as $row) {
            $existing[(int) $row['achievement_id']] = $row;
        }

        $now = date('Y-m-d H:i:s');
        $values = [];
        $params = [];
        $ready = [];

        foreach ($achievements as $index => $row) {
            $achievementId = (int) $row['id'];
            $type = (string) $row['condition_type'];
            $target = max(1, (int) $row['condition_value']);
            $state = $existing[$achievementId] ?? null;
            $isUnlocked = $state !== null && $state['unlocked_at'] !== null;
            $current = $isUnlocked ? $target : min($target, $progress[$type] ?? 0);
            $currentProgress = $state === null ? -1 : (int) $state['progress'];

            if ($current !== $currentProgress) {
                $values[] = "(:user_$index, :achievement_$index, :progress_$index, :now_$index)";
                $params["user_$index"] = $userId;
                $params["achievement_$index"] = $achievementId;
                $params["progress_$index"] = $current;
                $params["now_$index"] = $now;
            }

            if (!$isUnlocked && $current >= $target) {
                $ready[] = $row;
            }
        }

        if ($values !== []) {
            $this->query->run(
                'INSERT INTO user_achievements (user_id, achievement_id, progress, created_at)
                 VALUES ' . implode(', ', $values) . '
                 ON DUPLICATE KEY UPDATE progress = VALUES(progress)',
                $params,
            );
        }

        foreach ($ready as $row) {
            $this->unlock($userId, $row);
            $granted[] = (string) $row['slug'];
        }

        return $granted;
    }

    /** @param array<string, mixed> $row */
    private function unlock(int $userId, array $row): void
    {
        $id = (int) $row['id'];

        $this->query->update(
            'user_achievements',
            ['unlocked_at' => date('Y-m-d H:i:s'), 'progress' => max(1, (int) $row['condition_value'])],
            'user_id = :user_id AND achievement_id = :achievement_id',
            ['user_id' => $userId, 'achievement_id' => $id],
        );

        $mofu = (int) $row['reward_mofu'];
        $xp = (int) $row['reward_xp'];

        if ($mofu > 0) {
            $this->wallet->credit($userId, $mofu, 'achievement', '成就奖励：' . (string) $row['name'], null, 'achievement:' . $userId . ':' . $id);
        }

        if ($xp > 0) {
            $this->levels->addXp($userId, $xp, 'achievement', (string) $row['name']);
        }

        if ($row['reward_title_id'] !== null) {
            $this->titles->grant($userId, (int) $row['reward_title_id'], 'achievement');
        }

        $this->notifications->push($userId, null, 'system', 'achievement', $id, [
            'message' => sprintf('达成成就「%s」', (string) $row['name']),
            'url' => '/me/growth',
        ]);
    }

    /** @return array<string, int> */
    private function progressFor(int $userId): array
    {
        if (isset($this->progressCache[$userId])) {
            return $this->progressCache[$userId];
        }

        $stats = $this->query->one(
            'SELECT
                COALESCE((SELECT COUNT(*) FROM posts WHERE user_id = :post_user AND deleted_at IS NULL), 0) AS post_count,
                COALESCE((SELECT COUNT(*) FROM comments WHERE user_id = :comment_user AND status = \'published\'), 0) AS comment_count,
                COALESCE((SELECT checkin_streak FROM user_stats WHERE user_id = :checkin_user), 0) AS checkin_streak,
                COALESCE((SELECT SUM(like_count) FROM posts WHERE user_id = :like_user AND deleted_at IS NULL), 0) AS likes_received,
                COALESCE((SELECT total_earned FROM user_wallets WHERE user_id = :wallet_user), 0) AS mofu_earned',
            [
                'post_user' => $userId,
                'comment_user' => $userId,
                'checkin_user' => $userId,
                'like_user' => $userId,
                'wallet_user' => $userId,
            ],
        ) ?? [];

        return $this->progressCache[$userId] = [
            'post_count' => (int) ($stats['post_count'] ?? 0),
            'comment_count' => (int) ($stats['comment_count'] ?? 0),
            'checkin_streak' => (int) ($stats['checkin_streak'] ?? 0),
            'likes_received' => (int) ($stats['likes_received'] ?? 0),
            'mofu_earned' => (int) ($stats['mofu_earned'] ?? 0),
        ];
    }
}
