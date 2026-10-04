<?php

declare(strict_types=1);

namespace Mofu\Install;

use Dotenv\Dotenv;
use Mofu\Bootstrap\Config;
use Mofu\Core\Database\Connection;
use Mofu\Core\Database\Migrator;
use Mofu\Core\Database\Query;
use Mofu\Core\Support\Logger;
use Mofu\Core\View\TwigRenderer;
use Mofu\Core\View\ViteAssets;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Web 安装向导：环境自检 → 数据库配置（写 .env）→ 迁移与管理员 → 完成加锁。
 *
 * 表单使用无状态双提交 Cookie 令牌（安装期不依赖数据库会话）；模板为
 * app/Views/layouts/install.twig + pages/install.twig，自含样式，不依赖前端构建产物。
 */
final class Installer
{
    private const TOKEN_COOKIE = 'mofu_install_token';

    /** 用于识别目标库是否属于 MOFU 的特征表 */
    private const MOFU_TABLES = ['users', 'user_profiles', 'roles', 'user_sessions', 'posts', 'system_settings'];

    private readonly Logger $logger;

    public function __construct(private readonly string $root)
    {
        $this->logger = new Logger(rtrim($root, '/') . '/storage/logs/install.log', true);
    }

    /**
     * @param array<string, mixed> $query
     */
    public function handle(string $method, string $path, array $query): void
    {
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');

        try {
            if ($method === 'GET') {
                $this->handleGet($query);

                return;
            }

            if ($method === 'POST') {
                $this->handlePost($path);

                return;
            }

            header('Location: /install', true, 303);
        } catch (Throwable $e) {
            $this->logger->error('installer failed', ['message' => $e->getMessage()]);
            $this->render('error', [
                'title' => '安装出错',
                'step' => null,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /** @param array<string, mixed> $query */
    private function handleGet(array $query): void
    {
        if (isset($query['done'])) {
            $this->renderDone();

            return;
        }

        if (($query['step'] ?? '') === '3') {
            $this->renderStep3();

            return;
        }

        if (($query['step'] ?? '') === '2') {
            $this->renderStep2();

            return;
        }

        $this->renderStep1();
    }

    private function handlePost(string $path): void
    {
        if (!$this->verifyToken()) {
            $this->render('error', [
                'title' => '请求已过期',
                'step' => null,
                'message' => '安装令牌校验失败（Cookie 可能被拦截），请返回安装首页重试。',
            ], 422);

            return;
        }

        if ($this->siteRunning()) {
            $this->render('blocked', ['title' => '站点已在运行', 'step' => null], 403);

            return;
        }

        if ($path === '/install/db') {
            $this->handleDatabase();

            return;
        }

        if ($path === '/install/init') {
            $this->handleInit();

            return;
        }

        header('Location: /install', true, 303);
    }

    // ---------------------------------------------------------------- 步骤 1

    private function renderStep1(): void
    {
        $checks = EnvChecker::check($this->root);
        $probe = (new InstallGate($this->root))->probe();
        $envExists = is_file($this->root . '/.env');

        $state = match (true) {
            $probe === true => 'installed',
            $probe === false => 'half',
            $envExists => 'configured',
            default => 'fresh',
        };

        $this->render('step1', [
            'title' => '环境自检',
            'step' => 1,
            'checks' => $checks,
            'state' => $state,
            'requiredPassed' => EnvChecker::allRequiredPassed($checks),
            'lockWritable' => is_writable(dirname(InstallGate::lockPath($this->root))),
        ]);
    }

    // ---------------------------------------------------------------- 步骤 2

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function renderStep2(array $values = [], array $errors = [], int $status = 200): void
    {
        if ($this->siteRunning()) {
            $this->render('blocked', ['title' => '站点已在运行', 'step' => null], 403);

            return;
        }

        $env = $this->currentEnv();

        $values += [
            'site_name' => $env['APP_NAME'] ?? 'MOFU社区',
            'site_url' => $env['APP_URL'] ?? $this->suggestedUrl(),
            'db_host' => $env['DB_HOST'] ?? '127.0.0.1',
            'db_port' => $env['DB_PORT'] ?? '3306',
            'db_name' => $env['DB_DATABASE'] ?? 'mofu',
            'db_user' => $env['DB_USERNAME'] ?? '',
            'db_pass' => $env['DB_PASSWORD'] ?? '',
            'debug' => ($env['APP_DEBUG'] ?? '') === 'true' ? '1' : '',
        ];

        $this->render('step2', [
            'title' => '数据库与站点',
            'step' => 2,
            'values' => $values,
            'errors' => $errors,
        ], $status);
    }

    private function handleDatabase(): void
    {
        ignore_user_abort(true);
        set_time_limit(0);

        $checks = EnvChecker::check($this->root);

        if (!EnvChecker::allRequiredPassed($checks)) {
            $this->render('envblocked', [
                'title' => '环境自检未通过',
                'step' => 1,
                'checks' => $checks,
            ], 422);

            return;
        }

        $input = [
            'site_name' => trim((string) ($_POST['site_name'] ?? '')),
            'site_url' => trim((string) ($_POST['site_url'] ?? '')),
            'db_host' => trim((string) ($_POST['db_host'] ?? '')),
            'db_port' => trim((string) ($_POST['db_port'] ?? '3306')),
            'db_name' => trim((string) ($_POST['db_name'] ?? '')),
            'db_user' => trim((string) ($_POST['db_user'] ?? '')),
            'db_pass' => (string) ($_POST['db_pass'] ?? ''),
            'twikoo_env' => mb_substr(trim((string) ($_POST['twikoo_env'] ?? '')), 0, 190),
            'debug' => isset($_POST['debug']) ? '1' : '',
        ];

        $errors = [];

        if ($input['site_name'] === '') {
            $input['site_name'] = 'MOFU社区';
        }

        if ($input['site_url'] === ''
            || filter_var($input['site_url'], FILTER_VALIDATE_URL) === false
            || preg_match('#^https?://#i', $input['site_url']) !== 1) {
            $errors['site_url'] = '请填写完整的站点地址（以 http:// 或 https:// 开头）';
        }

        $port = (int) $input['db_port'];

        if ($input['db_host'] === '') {
            $errors['db_host'] = '请填写数据库主机';
        }

        if ($port < 1 || $port > 65535) {
            $errors['db_port'] = '端口需在 1-65535 之间';
        }

        if (preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $input['db_name']) !== 1) {
            $errors['db_name'] = '数据库名只能包含字母、数字、下划线与连字符';
        }

        if ($input['db_user'] === '') {
            $errors['db_user'] = '请填写数据库用户名';
        }

        if ($errors !== []) {
            $this->renderStep2($input, $errors, 422);

            return;
        }

        try {
            $server = new PDO(
                sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $input['db_host'], $port),
                $input['db_user'],
                $input['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );

            $exists = $server->query(
                'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . $server->quote($input['db_name']),
            )->fetchColumn();

            if ($exists === false) {
                $server->exec(sprintf(
                    'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                    str_replace('`', '', $input['db_name']),
                ));
            }

            new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $input['db_host'], $port, $input['db_name']),
                $input['db_user'],
                $input['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (Throwable $e) {
            $this->logger->warning('db test failed', ['message' => $e->getMessage()]);
            $this->renderStep2($input, [
                'db' => '数据库连接失败：' . $this->shortError($e) . '（若数据库尚不存在，请先在主机控制面板创建）',
            ], 422);

            return;
        }

        $result = EnvFileWriter::write($this->root, self::envValues($input, $port));

        if (!$result['ok']) {
            $this->logger->warning('env write failed', ['message' => $result['error']]);
            $this->render('manual-env', [
                'title' => '手动创建 .env',
                'step' => 2,
                'message' => $result['error'],
                'content' => $result['content'],
            ], 500);

            return;
        }

        $this->logger->info('env written', ['backup' => $result['backup'] ?? '']);
        header('Location: /install?step=3', true, 303);
    }

    /**
     * 安装向导写入 .env 的键值。抽成纯函数是为了可测：
     * 以前新增配置（如 Twikoo）忘了加进这份清单，部署到服务器就会静默失效。
     *
     * @param array<string, string> $input
     *
     * @return array<string, string>
     */
    public static function envValues(array $input, int $port = 3306): array
    {
        return [
            'APP_NAME' => (string) ($input['site_name'] ?? ''),
            'APP_ENV' => ($input['debug'] ?? '') === '1' ? 'local' : 'production',
            'APP_DEBUG' => ($input['debug'] ?? '') === '1' ? 'true' : 'false',
            'APP_URL' => (string) ($input['site_url'] ?? ''),
            'DB_HOST' => (string) ($input['db_host'] ?? ''),
            'DB_PORT' => (string) $port,
            'DB_DATABASE' => (string) ($input['db_name'] ?? ''),
            'DB_USERNAME' => (string) ($input['db_user'] ?? ''),
            'DB_PASSWORD' => (string) ($input['db_pass'] ?? ''),
            'SESSION_COOKIE' => 'mofu_session',
            'SESSION_LIFETIME' => '2592000',
            'VITE_DEV_SERVER' => '',
            'UPLOAD_MAX_MB' => '8',
            'UPLOAD_VIDEO_MAX_MB' => '60',
            'THEME_DEFAULT_COLOR' => '#775A0D',
            // Twikoo 评论：环境 ID 留空时前端完全不加载外部脚本，直接用站内评论
            'TWIKOO_ENV_ID' => (string) ($input['twikoo_env'] ?? ''),
            'TWIKOO_SCRIPT_URL' => '/vendor/twikoo.all.min.js',
            'TWIKOO_LANG' => 'zh-CN',
        ];
    }

    // ---------------------------------------------------------------- 步骤 3

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function renderStep3(array $values = [], array $errors = [], int $status = 200): void
    {
        if ($this->siteRunning()) {
            $this->render('blocked', ['title' => '站点已在运行', 'step' => null], 403);

            return;
        }

        try {
            $pdo = Connection::connect(Config::fromEnvironment($this->root));
        } catch (Throwable) {
            header('Location: /install?step=2', true, 303);

            return;
        }

        $query = new Query($pdo);
        $migrator = new Migrator($query, $this->root . '/migrations', $this->logger);

        $applied = array_flip($migrator->applied());
        $pending = [];

        foreach (array_keys($migrator->available()) as $version) {
            if (!isset($applied[$version])) {
                $pending[] = (string) $version;
            }
        }

        $userCount = 0;

        try {
            $userCount = (int) $query->value('SELECT COUNT(*) FROM users');
        } catch (Throwable) {
            $userCount = 0;
        }

        $values += ['username' => '', 'email' => '', 'nickname' => ''];

        $this->render('step3', [
            'title' => '初始化数据',
            'step' => 3,
            'pending' => $pending,
            'userCount' => $userCount,
            'values' => $values,
            'errors' => $errors,
        ], $status);
    }

    private function handleInit(): void
    {
        ignore_user_abort(true);
        set_time_limit(0);

        try {
            $config = Config::fromEnvironment($this->root);
            $pdo = Connection::connect($config);
        } catch (Throwable $e) {
            $this->logger->warning('init connect failed', ['message' => $e->getMessage()]);
            $this->renderStep2([], ['db' => '数据库连接失败：' . $this->shortError($e) . '，请检查上一步配置'], 422);

            return;
        }

        $tables = $this->tableNames($pdo);
        $kind = self::classifyTables($tables);

        if ($kind === 'partial' || $kind === 'foreign') {
            $this->renderConflict($kind, $tables, $pdo);

            return;
        }

        $values = [
            'username' => strtolower(trim((string) ($_POST['username'] ?? ''))),
            'email' => strtolower(trim((string) ($_POST['email'] ?? ''))),
            'nickname' => trim((string) ($_POST['nickname'] ?? '')),
        ];
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        $errors = [];

        if (preg_match('/^[a-z0-9_]{3,20}$/', $values['username']) !== 1) {
            $errors['username'] = '用户名需为 3-20 位字母、数字或下划线';
        }

        if (filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false || mb_strlen($values['email']) > 190) {
            $errors['email'] = '邮箱格式不正确';
        }

        if (mb_strlen($password) < 8) {
            $errors['password'] = '密码至少 8 位';
        }

        if ($password !== $confirm) {
            $errors['password_confirm'] = '两次输入的密码不一致';
        }

        if (mb_strlen($values['nickname']) > 24) {
            $errors['nickname'] = '昵称最多 24 字';
        }

        if ($errors !== []) {
            $this->renderStep3($values, $errors, 422);

            return;
        }

        $query = new Query($pdo);
        $migrator = new Migrator($query, $this->root . '/migrations', $this->logger);

        try {
            $executed = $migrator->migrate();
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $this->logger->error('migrate failed', ['message' => $message]);

            // 表已存在（1050）通常意味着库中有旧数据但缺少迁移记录：给出可操作的引导而不是原始 SQL 报错
            if (str_contains($message, '1050') || stripos($message, 'already exists') !== false) {
                $this->renderConflict('partial', $this->tableNames($pdo), $pdo, $this->shortError($e));

                return;
            }

            $this->render('error', [
                'title' => '数据库初始化失败',
                'step' => 3,
                'message' => $this->shortError($e),
            ], 500);

            return;
        }

        $this->logger->info('migrated', ['count' => count($executed), 'versions' => implode(',', $executed)]);

        $userCount = 0;

        try {
            $userCount = (int) $query->value('SELECT COUNT(*) FROM users');
        } catch (Throwable) {
            $userCount = 0;
        }

        $adminCreated = false;

        if ($userCount === 0) {
            $now = date('Y-m-d H:i:s');

            try {
                // 与 AuthController::register 的用户创建保持一致（表结构变更时需同步）
                $query->transaction(function (Query $q) use ($values, $password, $now): void {
                    $id = $q->insert('users', [
                        'username' => $values['username'],
                        'email' => $values['email'],
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $q->insert('user_profiles', [
                        'user_id' => $id,
                        'nickname' => $values['nickname'] !== '' ? $values['nickname'] : $values['username'],
                        'bio' => '',
                        'updated_at' => $now,
                    ]);

                    $role = $q->one("SELECT id FROM roles WHERE slug = 'super_admin'");

                    if ($role === null) {
                        throw new RuntimeException('角色数据缺失（super_admin），迁移可能未完成');
                    }

                    $q->insert('user_roles', [
                        'user_id' => $id,
                        'role_id' => (int) $role['id'],
                        'assigned_at' => $now,
                    ]);
                });

                $adminCreated = true;
            } catch (Throwable $e) {
                $this->logger->error('admin create failed', ['message' => $e->getMessage()]);
                $this->render('error', [
                    'title' => '管理员账号创建失败',
                    'step' => 3,
                    'message' => $this->shortError($e) . '（数据库结构已完成初始化，可返回本页修改后重新提交）',
                ], 500);

                return;
            }
        }

        $gate = new InstallGate($this->root);
        $locked = $gate->writeLock([
            'installed_at' => date('c'),
            'app_name' => (string) $config->get('app.name', ''),
            'app_url' => (string) $config->get('app.url', ''),
            'php_version' => PHP_VERSION,
        ]);

        if (!$locked) {
            $this->logger->error('lock write failed');
            $this->render('error', [
                'title' => '安装完成但锁定失败',
                'step' => 3,
                'message' => '无法写入 storage/install.lock，请确认 storage/ 目录可写后重新提交本页（不会重复创建数据）。',
            ], 500);

            return;
        }

        $this->logger->info('install finished', ['admin' => $values['username'], 'created' => $adminCreated]);
        header('Location: /install?done=1', true, 303);
    }

    // ---------------------------------------------------------------- 完成页

    public function renderDone(): void
    {
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');

        $this->render('done', ['title' => '安装完成', 'step' => null]);
    }

    // ---------------------------------------------------------------- 工具

    /**
     * 目标库表结构状态：empty=空库 / installed=有迁移记录表 / partial=有 MOFU 表但无迁移记录 / foreign=非空且不像 MOFU。
     *
     * @param list<string> $tables
     */
    public static function classifyTables(array $tables): string
    {
        $names = array_map(static fn (string $name): string => strtolower($name), $tables);

        if ($names === []) {
            return 'empty';
        }

        if (in_array('schema_migrations', $names, true)) {
            return 'installed';
        }

        foreach (self::MOFU_TABLES as $known) {
            if (in_array($known, $names, true)) {
                return 'partial';
            }
        }

        return 'foreign';
    }

    /** @return list<string> */
    private function tableNames(PDO $pdo): array
    {
        try {
            $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn (mixed $name): string => (string) $name, $rows);
    }

    /** @param list<string> $tables */
    private function renderConflict(string $kind, array $tables, ?PDO $pdo = null, string $raw = ''): void
    {
        $registryEmpty = false;

        if ($pdo !== null) {
            try {
                $registryEmpty = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 0;
            } catch (Throwable) {
                $registryEmpty = false;
            }
        }

        $this->render('conflict', [
            'title' => '数据库无法直接安装',
            'step' => null,
            'kind' => $kind,
            'tables' => array_slice($tables, 0, 8),
            'tableCount' => count($tables),
            'registryEmpty' => $registryEmpty,
            'raw' => $raw,
        ], 409);
    }

    private function siteRunning(): bool
    {
        return (new InstallGate($this->root))->probe() === true;
    }

    /** @return array<string, string> */
    private function currentEnv(): array
    {
        $path = $this->root . '/.env';

        if (!is_file($path)) {
            return [];
        }

        try {
            $parsed = Dotenv::parse((string) file_get_contents($path));
        } catch (Throwable) {
            return [];
        }

        $values = [];

        foreach ($parsed as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $values[$key] = (string) $value;
            }
        }

        return $values;
    }

    private function suggestedUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return ($https ? 'https' : 'http') . '://' . $host;
    }

    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    private function shortError(Throwable $e): string
    {
        $message = trim($e->getMessage());

        return mb_strlen($message) > 300 ? mb_substr($message, 0, 300) . '…' : $message;
    }

    private function token(): string
    {
        $current = (string) ($_COOKIE[self::TOKEN_COOKIE] ?? '');

        if (preg_match('/^[a-f0-9]{32}$/', $current) === 1) {
            return $current;
        }

        $token = bin2hex(random_bytes(16));
        setcookie(self::TOKEN_COOKIE, $token, [
            'expires' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $this->isHttps(),
        ]);
        $_COOKIE[self::TOKEN_COOKIE] = $token;

        return $token;
    }

    private function verifyToken(): bool
    {
        $sent = (string) ($_POST['_token'] ?? '');
        $cookie = (string) ($_COOKIE[self::TOKEN_COOKIE] ?? '');

        return preg_match('/^[a-f0-9]{32}$/', $sent) === 1 && hash_equals($cookie, $sent);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(string $template, array $data, int $status = 200): void
    {
        $twig = new TwigRenderer(
            $this->root . '/app/Views',
            $this->root . '/storage/cache/twig',
            true,
            new ViteAssets($this->root . '/public/assets/manifest.json'),
        );

        $html = $twig->render('pages/install.twig', $data + ['token' => $this->token(), 'view' => $template]);

        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }
}
