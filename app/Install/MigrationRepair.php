<?php

declare(strict_types=1);

namespace Mofu\Install;

use PDO;

/**
 * 半装库检测：按每个迁移的代表性结构（表 / 列 / 索引）判断该库已应用到哪一版。
 *
 * 典型场景是「库里有 MOFU 数据表、却没有 schema_migrations 记录」——
 * 多因旧库/备份恢复或迁移记录表被误删。安装向导据此判断能否安全补写记录。
 * 命令行工具 bin/repair-migrations.php 与向导冲突页共用本类。
 */
final class MigrationRepair
{
    /**
     * 判定每个迁移「是否已应用」的代表性结构对象；null = 该迁移无结构变更，只能按前缀推断。
     * tables/columns/indexes = 必须存在；absentTables/absentColumns = 必须不存在。
     */
    public const CHECKS = [
        '001_users_and_sessions'           => ['tables' => ['users', 'user_profiles', 'user_sessions']],
        '002_roles_and_permissions'        => ['tables' => ['roles', 'permissions', 'role_permissions', 'user_roles']],
        '003_content_core'                 => ['tables' => ['media', 'posts', 'post_media', 'topics', 'post_topics']],
        '004_comments_and_reactions'       => ['tables' => ['comments', 'reactions']],
        '005_notifications_and_moderation' => ['tables' => ['notifications', 'reports', 'moderation_records']],
        '006_preferences_themes_system'    => ['tables' => ['user_preferences', 'user_themes', 'system_settings', 'audit_logs']],
        '007_follows'                      => ['tables' => ['follows']],
        '008_confessions'                  => ['tables' => ['confessions', 'confession_comments', 'confession_media']],
        '009_reactions_extend'             => null,
        '010_settings_update'              => null,
        '011_messages_and_blocks'          => ['tables' => ['conversations', 'messages', 'user_blocks']],
        '012_user_appeals'                 => ['tables' => ['user_appeals']],
        '013_communities_and_boards'       => ['tables' => ['communities', 'community_members', 'boards', 'board_entries', 'board_entry_comments'], 'columns' => [['posts', 'community_id']]],
        '014_events'                       => ['tables' => ['events', 'event_signups']],
        '015_news'                         => ['tables' => ['news']],
        '016_phase4'                       => ['tables' => ['post_views'], 'columns' => [['posts', 'repost_of_id'], ['posts', 'repost_count']]],
        '017_backgrounds'                  => null,
        '018_indexes'                      => ['indexes' => [['users', 'idx_users_status'], ['notifications', 'idx_notifications_user_id']]],
        '019_appeal_token'                 => ['columns' => [['user_appeals', 'access_token']]],
        '020_board_logo'                   => ['columns' => [['boards', 'logo_media_id']]],
        '021_brand_color'                  => null,
        // 022 建的 games / game_records 会在 025 被删除，故不作为 022 的判据
        '022_community_systems'            => ['tables' => ['user_bans', 'announcements', 'announcement_reads', 'user_wallets', 'wallet_transactions', 'level_configs', 'user_levels', 'user_stats', 'titles', 'user_titles', 'achievements', 'user_achievements', 'check_ins', 'check_in_rewards', 'shop_products', 'shop_orders', 'user_inventory', 'growth_daily_counters'], 'columns' => [['users', 'equipped_title_id']]],
        '023_membership_and_coins'         => ['tables' => ['email_verifications', 'quiz_questions', 'quiz_attempts', 'post_coins'], 'columns' => [['users', 'email_verified_at'], ['users', 'membership'], ['posts', 'coin_count']]],
        '024_user_tasks'                   => ['tables' => ['user_tasks']],
        '025_drop_games'                   => ['tables' => ['user_stats'], 'absentTables' => ['games', 'game_records'], 'absentColumns' => [['user_stats', 'games_played']]],
        '028_flash_level'                  => ['columns' => [['users', 'is_flash'], ['quiz_questions', 'tier'], ['quiz_attempts', 'tier']]],
        '029_wallet_decimals_and_paid_posts' => ['tables' => ['post_purchases'], 'columns' => [['posts', 'is_paid'], ['posts', 'price']]],
        '030_message_recall'               => ['columns' => [['messages', 'deleted_at']]],
    ];

    /** @var array<string, bool> */
    private array $cache = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function tableExists(string $table): bool
    {
        return $this->cached('table:' . $table, static function (PDO $pdo) use ($table): bool {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);

            return (int) $stmt->fetchColumn() > 0;
        });
    }

    public function columnExists(string $table, string $column): bool
    {
        return $this->cached('column:' . $table . '.' . $column, static function (PDO $pdo) use ($table, $column): bool {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);

            return (int) $stmt->fetchColumn() > 0;
        });
    }

    public function indexExists(string $table, string $index): bool
    {
        return $this->cached('index:' . $table . '.' . $index, static function (PDO $pdo) use ($table, $index): bool {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
            $stmt->execute([$table, $index]);

            return (int) $stmt->fetchColumn() > 0;
        });
    }

    /** 全部条件都满足才算该迁移已应用。@param array<string, mixed> $spec */
    public function matches(array $spec): bool
    {
        foreach ($spec['tables'] ?? [] as $table) {
            if (!$this->tableExists($table)) {
                return false;
            }
        }

        foreach ($spec['columns'] ?? [] as [$table, $column]) {
            if (!$this->columnExists($table, $column)) {
                return false;
            }
        }

        foreach ($spec['indexes'] ?? [] as [$table, $index]) {
            if (!$this->indexExists($table, $index)) {
                return false;
            }
        }

        foreach ($spec['absentTables'] ?? [] as $table) {
            if ($this->tableExists($table)) {
                return false;
            }
        }

        foreach ($spec['absentColumns'] ?? [] as [$table, $column]) {
            if ($this->columnExists($table, $column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 分析一批迁移版本在该库上的应用情况。
     *
     * 只有可判定的迁移恰好构成一个「干净前缀」时才认为可安全补写记录；
     * 一旦发现后面的迁移已应用、更早的却没有（结构错乱），consistent=false。
     *
     * @param list<string> $versions 迁移版本，按执行顺序升序
     * @return array{statuses: array<string, bool|null>, applied: list<string>, pending: list<string>, consistent: bool, reason: string}
     */
    public function analyze(array $versions): array
    {
        $statuses = [];

        foreach ($versions as $version) {
            $spec = self::CHECKS[$version] ?? null;
            $statuses[$version] = $spec === null ? null : $this->matches($spec);
        }

        $boundary = null;

        foreach ($versions as $index => $version) {
            if ($statuses[$version] === false) {
                $boundary = $index;
                break;
            }
        }

        if ($boundary !== null) {
            for ($i = $boundary + 1; $i < count($versions); $i++) {
                if ($statuses[$versions[$i]] === true) {
                    return [
                        'statuses' => $statuses,
                        'applied' => [],
                        'pending' => $versions,
                        'consistent' => false,
                        'reason' => sprintf(
                            '结构不连续：%s 的结构已存在，但更早的 %s 缺失（库可能被手工改动过）',
                            $versions[$i],
                            $versions[$boundary],
                        ),
                    ];
                }
            }
        }

        return [
            'statuses' => $statuses,
            'applied' => $boundary === null ? $versions : array_slice($versions, 0, $boundary),
            'pending' => $boundary === null ? [] : array_slice($versions, $boundary),
            'consistent' => true,
            'reason' => '',
        ];
    }

    private function cached(string $key, callable $compute): bool
    {
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $compute($this->pdo);
        }

        return $this->cache[$key];
    }
}
