<?php

declare(strict_types=1);

namespace Mofu\Modules\Growth;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Modules\Notifications\NotificationService;

/**
 * 称号 / 徽章体系。
 * 佩戴状态存在 users.equipped_title_id（保证同时只戴一个），拥有关系在 user_titles。
 */
final class TitleService
{
    public const RARITY_LABELS = [
        'common' => '普通',
        'rare' => '稀有',
        'epic' => '史诗',
        'legendary' => '传说',
        'special' => '特殊',
    ];

    public const CONDITION_LABELS = [
        'register' => '注册账号',
        'checkin_streak' => '连续签到',
        'account_age' => '注册天数',
        'interactions' => '累计互动',
        'night_checkin' => '深夜签到',
        'shop' => '小店消费',
        'manual' => '管理员授予',
    ];

    public function __construct(
        private readonly Query $query,
        private readonly NotificationService $notifications,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function catalogue(): array
    {
        return array_map(
            $this->present(...),
            $this->query->all('SELECT * FROM titles WHERE active = 1 ORDER BY sort ASC, id ASC'),
        );
    }

    /** @return array{titles: list<array<string, mixed>>, equipped_id: int|null} */
    public function catalogueFor(int $userId): array
    {
        $owned = $this->ownedIds($userId);
        $equippedId = (int) ($this->query->value('SELECT equipped_title_id FROM users WHERE id = :id', ['id' => $userId]) ?? 0);

        $titles = [];

        foreach ($this->catalogue() as $title) {
            $isOwned = in_array((int) $title['id'], $owned, true);
            $title['owned'] = $isOwned;
            $title['equipped'] = $isOwned && (int) $title['id'] === $equippedId;
            $titles[] = $title;
        }

        return ['titles' => $titles, 'equipped_id' => $equippedId > 0 ? $equippedId : null];
    }

    /** @return list<int> */
    public function ownedIds(int $userId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['title_id'],
            $this->query->all('SELECT title_id FROM user_titles WHERE user_id = :user_id', ['user_id' => $userId]),
        );
    }

    public function grant(int $userId, int $titleId, string $source = 'system'): bool
    {
        $title = $this->query->one('SELECT * FROM titles WHERE id = :id', ['id' => $titleId]);

        if ($title === null) {
            throw HttpException::notFound('称号不存在');
        }

        return $this->grantRow($userId, $title, $source);
    }

    public function grantBySlug(int $userId, string $slug, string $source = 'system'): bool
    {
        $title = $this->query->one('SELECT * FROM titles WHERE slug = :slug', ['slug' => $slug]);

        return $title !== null && $this->grantRow($userId, $title, $source);
    }

    public function revoke(int $userId, int $titleId): void
    {
        $this->query->transaction(function (Query $q) use ($userId, $titleId): void {
            $q->delete('user_titles', 'user_id = :user_id AND title_id = :title_id', [
                'user_id' => $userId,
                'title_id' => $titleId,
            ]);
            $q->run(
                'UPDATE users SET equipped_title_id = NULL WHERE id = :id AND equipped_title_id = :title',
                ['id' => $userId, 'title' => $titleId],
            );
        });
    }

    public function equip(int $userId, ?int $titleId): void
    {
        if ($titleId === null || $titleId <= 0) {
            $this->query->update('users', ['equipped_title_id' => null], 'id = :id', ['id' => $userId]);

            return;
        }

        $owned = $this->query->value(
            'SELECT title_id FROM user_titles WHERE user_id = :user_id AND title_id = :title_id',
            ['user_id' => $userId, 'title_id' => $titleId],
        );

        if ($owned === null) {
            throw HttpException::badRequest('你还没有获得这个称号');
        }

        $wearable = (int) ($this->query->value('SELECT wearable FROM titles WHERE id = :id AND active = 1', ['id' => $titleId]) ?? 0);

        if ($wearable !== 1) {
            throw HttpException::badRequest('该称号不可佩戴');
        }

        $this->query->update('users', ['equipped_title_id' => $titleId], 'id = :id', ['id' => $userId]);
    }

    /**
     * 佩戴称号的兜底表达式（返回 title_id）：
     * 用户没主动佩戴时，取最早获得的「可佩戴」且已启用的称号。
     * 只 JOIN users.equipped_title_id 的话，从未手动佩戴过的用户一个称号都不显示。
     *
     * @param string $userColumn 关联到当前用户的 SQL 列（如 `u.id`）；只传代码内置字面量
     */
    public static function equippedTitleExpr(string $userColumn): string
    {
        return '(SELECT ut.title_id FROM user_titles ut'
            . ' JOIN titles ft ON ft.id = ut.title_id AND ft.active = 1 AND ft.wearable = 1'
            . ' WHERE ut.user_id = ' . $userColumn . ' ORDER BY ut.acquired_at ASC, ut.title_id ASC LIMIT 1)';
    }

    /** @return array<string, mixed>|null */
    public function equippedFor(int $userId): ?array
    {
        $row = $this->query->one(
            'SELECT t.* FROM users u JOIN titles t ON t.id = COALESCE(u.equipped_title_id, '
            . self::equippedTitleExpr('u.id') . ') WHERE u.id = :id AND t.active = 1',
            ['id' => $userId],
        );

        return $row === null ? null : $this->present($row);
    }

    /**
     * 批量取用户佩戴的称号（列表页展示用）。
     *
     * @param list<int> $userIds
     * @return array<int, array<string, mixed>>
     */
    public function equippedForMany(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));

        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));

        $rows = $this->query->all(
            sprintf(
                'SELECT u.id AS user_id, t.* FROM users u JOIN titles t
                   ON t.id = COALESCE(u.equipped_title_id, %s)
                 WHERE t.active = 1 AND u.id IN (%s)',
                self::equippedTitleExpr('u.id'),
                $placeholders,
            ),
            $userIds,
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['user_id']] = $this->present($row);
        }

        return $map;
    }

    /**
     * 重新评估可自动获得的称号；返回本次新获得的称号 slug 列表。
     *
     * @return list<string>
     */
    public function evaluate(int $userId): array
    {
        $owned = $this->ownedIds($userId);
        $granted = [];
        $metrics = $this->metricsFor($userId);

        $candidates = $this->query->all(
            "SELECT * FROM titles WHERE active = 1 AND condition_type <> 'manual'
             AND (starts_at IS NULL OR starts_at <= :now_start) AND (ends_at IS NULL OR ends_at >= :now_end)",
            ['now_start' => date('Y-m-d H:i:s'), 'now_end' => date('Y-m-d H:i:s')],
        );

        foreach ($candidates as $title) {
            if (in_array((int) $title['id'], $owned, true)) {
                continue;
            }

            if ($this->conditionMet((string) $title['condition_type'], (int) $title['condition_value'], $metrics)) {
                if ($this->grantRow($userId, $title, 'condition')) {
                    $granted[] = (string) $title['slug'];
                }
            }
        }

        return $granted;
    }

    /** @param array<string, mixed> $data */
    public function save(?int $id, array $data, int $actorId): int
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw HttpException::badRequest('请填写称号名称');
        }

        $rarity = (string) ($data['rarity'] ?? 'common');
        $payload = [
            'name' => mb_substr($name, 0, 48),
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 255),
            'icon' => mb_substr(trim((string) ($data['icon'] ?? 'military_tech')), 0, 64),
            'rarity' => array_key_exists($rarity, self::RARITY_LABELS) ? $rarity : 'common',
            'condition_type' => (string) ($data['condition_type'] ?? 'manual'),
            'condition_value' => max(0, (int) ($data['condition_value'] ?? 0)),
            'hidden' => !empty($data['hidden']) ? 1 : 0,
            'wearable' => !empty($data['wearable']) ? 1 : 0,
            'limited' => !empty($data['limited']) ? 1 : 0,
            'active' => array_key_exists('active', $data) && !$data['active'] ? 0 : 1,
            'starts_at' => self::nullableDate($data['starts_at'] ?? null),
            'ends_at' => self::nullableDate($data['ends_at'] ?? null),
            'sort' => (int) ($data['sort'] ?? 0),
        ];

        if ($id !== null && $id > 0) {
            $this->query->update('titles', $payload, 'id = :id', ['id' => $id]);

            return $id;
        }

        $slug = preg_replace('/[^a-z0-9\-]/', '', mb_strtolower(trim((string) ($data['slug'] ?? '')))) ?: ('title-' . bin2hex(random_bytes(4)));

        return $this->query->insert('titles', $payload + [
            'slug' => $slug,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function delete(int $id): void
    {
        $this->query->delete('titles', 'id = :id', ['id' => $id]);
    }

    /** @return list<array<string, mixed>> 后台列表（含未上架） */
    public function adminList(): array
    {
        return array_map(
            $this->present(...),
            $this->query->all('SELECT * FROM titles ORDER BY sort ASC, id ASC'),
        );
    }

    private static function nullableDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    /** @return array<string, int> */
    private function metricsFor(int $userId): array
    {
        $row = $this->query->one(
            'SELECT
                COALESCE((SELECT checkin_streak FROM user_stats WHERE user_id = :streak_user), 0) AS checkin_streak,
                COALESCE((SELECT SUM(like_count) FROM posts WHERE user_id = :like_user AND deleted_at IS NULL), 0) AS likes,
                COALESCE((SELECT COUNT(*) FROM comments WHERE user_id = :comment_user AND status = \'published\'), 0) AS comments,
                COALESCE((SELECT COUNT(*) FROM check_ins WHERE user_id = :night_user AND HOUR(created_at) < 6), 0) AS night_checkins,
                COALESCE((SELECT COUNT(*) FROM user_inventory WHERE user_id = :inventory_user), 0) AS inventory_count,
                UNIX_TIMESTAMP((SELECT created_at FROM users WHERE id = :created_user)) AS created_at',
            [
                'streak_user' => $userId,
                'like_user' => $userId,
                'comment_user' => $userId,
                'night_user' => $userId,
                'inventory_user' => $userId,
                'created_user' => $userId,
            ],
        ) ?? [];

        return [
            'checkin_streak' => (int) ($row['checkin_streak'] ?? 0),
            'interactions' => (int) ($row['likes'] ?? 0) + (int) ($row['comments'] ?? 0),
            'night_checkins' => (int) ($row['night_checkins'] ?? 0),
            'inventory_count' => (int) ($row['inventory_count'] ?? 0),
            'created_at' => (int) ($row['created_at'] ?? 0),
        ];
    }

    /** @param array<string, int> $metrics */
    private function conditionMet(string $type, int $value, array $metrics): bool
    {
        switch ($type) {
            case 'register':
                return true;

            case 'checkin_streak':
                return $metrics['checkin_streak'] >= $value;

            case 'account_age':
                return $metrics['created_at'] > 0
                    && (time() - $metrics['created_at']) >= $value * 86400;

            case 'interactions':
                return $metrics['interactions'] >= $value;

            case 'night_checkin':
                return $metrics['night_checkins'] > 0;

            case 'shop':
                return $metrics['inventory_count'] > 0;

            default:
                return false;
        }
    }

    private function grantRow(int $userId, array $title, string $source): bool
    {
        $inserted = $this->query->run(
            'INSERT IGNORE INTO user_titles (user_id, title_id, source, acquired_at) VALUES (:user_id, :title_id, :source, :now)',
            [
                'user_id' => $userId,
                'title_id' => (int) $title['id'],
                'source' => $source,
                'now' => date('Y-m-d H:i:s'),
            ],
        )->rowCount() > 0;

        if ($inserted) {
            $this->equipIfNone($userId, $title);

            $this->notifications->push($userId, null, 'system', 'title', (int) $title['id'], [
                'message' => sprintf('获得新称号「%s」', (string) $title['name']),
                'url' => '/me/growth',
            ]);
        }

        return $inserted;
    }

    /**
     * 默认佩戴：用户还没有佩戴任何称号时，自动戴上这一个（仅限可佩戴的）。
     * 没有这一步，绝大多数用户 equipped_title_id 为空，帖子与主页就永远看不到称号。
     *
     * @param array<string, mixed> $title
     */
    private function equipIfNone(int $userId, array $title): void
    {
        if ((int) ($title['wearable'] ?? 0) !== 1) {
            return;
        }

        $current = $this->query->value('SELECT equipped_title_id FROM users WHERE id = :id', ['id' => $userId]);

        if ($current !== null) {
            return;
        }

        $this->query->update('users', ['equipped_title_id' => (int) $title['id']], 'id = :id', ['id' => $userId]);
    }

    /** @param array<string, mixed> $row */
    private function present(array $row): array
    {
        $type = (string) $row['condition_type'];
        $hidden = (int) $row['hidden'] === 1;

        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'icon' => (string) $row['icon'],
            'rarity' => (string) $row['rarity'],
            'rarity_label' => self::RARITY_LABELS[(string) $row['rarity']] ?? (string) $row['rarity'],
            'condition_type' => $type,
            'condition_value' => (int) $row['condition_value'],
            'condition_text' => $hidden ? '达成条件后揭晓' : self::conditionText($type, (int) $row['condition_value']),
            'hidden' => $hidden,
            'wearable' => (int) $row['wearable'] === 1,
            'limited' => (int) $row['limited'] === 1,
            'starts_at' => $row['starts_at'] !== null ? (string) $row['starts_at'] : null,
            'ends_at' => $row['ends_at'] !== null ? (string) $row['ends_at'] : null,
        ];
    }

    public static function conditionText(string $type, int $value): string
    {
        return match ($type) {
            'register' => '注册账号即可获得',
            'checkin_streak' => sprintf('连续签到 %d 天', $value),
            'account_age' => sprintf('注册满 %d 天', $value),
            'interactions' => sprintf('累计获得 %d 次互动', $value),
            'night_checkin' => '在凌晨 0-6 点完成一次签到',
            'shop' => '在小店消费获得',
            default => '由管理员授予',
        };
    }
}
