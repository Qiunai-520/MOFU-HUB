<?php

declare(strict_types=1);

/*
 * 部署安全门禁
 * ----------------
 * 这个脚本不能替代 PHP 官方安全更新：PHP 核心 CVE 必须在服务器运行时升级。
 * 它做的是阻止站点在低于安全基线的 PHP 上启动，并检查部署时最常见的
 * 高危配置（调试泄漏、缺少扩展、上传目录可执行、存储不可写等）。
 *
 * 用法：
 *   php bin/security-check.php
 *   composer security
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('MOFU_ROOT', dirname(__DIR__));

require MOFU_ROOT . '/vendor/autoload.php';

use Mofu\Bootstrap\Config;

$errors = [];
$warnings = [];
$passes = [];

$ok = static function (string $message) use (&$passes): void {
    $passes[] = $message;
};

$warn = static function (string $message) use (&$warnings): void {
    $warnings[] = $message;
};

$fail = static function (string $message) use (&$errors): void {
    $errors[] = $message;
};

/** @return int bytes, -1 when the value cannot be parsed */
$iniBytes = static function (string $value): int {
    $value = trim($value);

    if ($value === '' || $value === '-1') {
        return -1;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float) $value;

    return (int) match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
};

// 1. PHP security floor. Keep this aligned with composer.json.
// PHP2Shell fixed versions are branch-specific:
// 8.2.35, 8.3.36, 8.4.27, 8.5.12 (and any later 8.6+).
$phpPatched = PHP_VERSION_ID >= 80600
    || (PHP_VERSION_ID >= 80512 && PHP_VERSION_ID < 80600)
    || (PHP_VERSION_ID >= 80427 && PHP_VERSION_ID < 80500)
    || (PHP_VERSION_ID >= 80336 && PHP_VERSION_ID < 80400)
    || (PHP_VERSION_ID >= 80235 && PHP_VERSION_ID < 80300);

if (!$phpPatched) {
    $fail(sprintf(
        'PHP %s is vulnerable to PHP2Shell (CVE-2026-109618 / CVE-2026-109619). '
        . 'Upgrade to 8.2.35, 8.3.36, 8.4.27 or 8.5.12 before deploying.',
        PHP_VERSION,
    ));
} else {
    $ok('PHP runtime: ' . PHP_VERSION . ' (PHP2Shell patched)');
}

// 2. Extensions used by the application.
$requiredExtensions = [
    'pdo' => 'database access',
    'pdo_mysql' => 'MySQL / MariaDB',
    'mbstring' => 'UTF-8 text handling',
    'json' => 'API / configuration',
    'fileinfo' => 'upload MIME verification',
    'session' => 'session support',
    'openssl' => 'secure random / TLS helpers',
];

foreach ($requiredExtensions as $extension => $purpose) {
    if (extension_loaded($extension)) {
        $ok('Extension loaded: ' . $extension);
    } else {
        $fail(sprintf('Missing PHP extension %s (%s).', $extension, $purpose));
    }
}

if (extension_loaded('gd')) {
    $ok('Extension loaded: gd');
} else {
    $warn('PHP extension gd is missing; image upload processing and PWA icon generation will fail.');
}

// 3. Application configuration.
try {
    $config = Config::fromEnvironment(MOFU_ROOT);
} catch (Throwable $e) {
    $fail('Unable to load configuration: ' . $e->getMessage());
    $config = null;
}

if ($config !== null) {
    $environment = (string) $config->get('app.env', 'local');
    $debug = (bool) $config->get('app.debug', false);
    $key = (string) $config->get('app.key', '');
    $appUrl = (string) $config->get('app.url', '');

    if ($environment === 'local') {
        $ok('APP_ENV=local (development mode)');

        if ($debug) {
            $warn('APP_DEBUG=true in local mode; never use this on a public server.');
        }
    } elseif ($debug) {
        $fail('APP_DEBUG must be false when APP_ENV is not local.');
    } else {
        $ok('APP_DEBUG is disabled for production.');
    }

    if (strlen($key) < 32) {
        $fail('APP_KEY is missing or shorter than 32 characters. Generate one with: php -r "echo bin2hex(random_bytes(32));"');
    } else {
        $ok('APP_KEY is configured.');
    }

    if ($environment !== 'local' && $appUrl !== '' && !str_starts_with(strtolower($appUrl), 'https://')) {
        $warn('APP_URL is not HTTPS; session cookies will not receive the Secure flag.');
    }
}

// 4. Dangerous PHP runtime settings.
$exposePhp = strtolower((string) ini_get('expose_php'));

if (in_array($exposePhp, ['1', 'on', 'true'], true)) {
    $warn('expose_php is enabled; hide the PHP version with expose_php=Off.');
} else {
    $ok('expose_php is disabled.');
}

if (($config?->get('app.env') ?? 'local') !== 'local') {
    $displayErrors = strtolower((string) ini_get('display_errors'));

    if (in_array($displayErrors, ['1', 'on', 'true', 'stdout'], true)) {
        $fail('display_errors is enabled in a non-local environment; this can leak paths and secrets.');
    } else {
        $ok('display_errors is disabled.');
    }
}

$allowUrlInclude = strtolower((string) ini_get('allow_url_include'));

if (in_array($allowUrlInclude, ['1', 'on', 'true'], true)) {
    $fail('allow_url_include is enabled; set allow_url_include=Off immediately.');
} else {
    $ok('allow_url_include is disabled.');
}

$uploadProgress = strtolower((string) ini_get('session.upload_progress.enabled'));

if (in_array($uploadProgress, ['1', 'on', 'true'], true)) {
    $warn('session.upload_progress.enabled is on. Disable it in php.ini/FPM pool if you do not use upload progress (defense in depth for PHP2Shell).');
} else {
    $ok('session.upload_progress.enabled is disabled.');
}

// 5. Upload limits should not silently undercut the application limits.
$uploadMax = $iniBytes((string) ini_get('upload_max_filesize'));
$postMax = $iniBytes((string) ini_get('post_max_size'));
$imageLimit = (int) ($config?->get('upload.max_mb', 8) ?? 8) * 1024 * 1024;

if ($uploadMax > 0 && $uploadMax < $imageLimit) {
    $warn(sprintf(
        'upload_max_filesize (%s) is below UPLOAD_MAX_MB (%d MB).',
        (string) ini_get('upload_max_filesize'),
        (int) ($config?->get('upload.max_mb', 8) ?? 8),
    ));
}

if ($postMax > 0 && $postMax < $imageLimit) {
    $warn(sprintf(
        'post_max_size (%s) is below UPLOAD_MAX_MB (%d MB).',
        (string) ini_get('post_max_size'),
        (int) ($config?->get('upload.max_mb', 8) ?? 8),
    ));
}

// 6. Writable runtime directories.
foreach (['storage/logs', 'storage/cache', 'storage/sessions', 'public/uploads'] as $directory) {
    $path = MOFU_ROOT . '/' . $directory;

    if (!is_dir($path)) {
        $warn('Directory is missing: ' . $directory);
    } elseif (!is_writable($path)) {
        $fail('Directory is not writable: ' . $directory);
    } else {
        $ok('Writable directory: ' . $directory);
    }
}

// 7. Never expose secrets or executable files through the web root.
foreach (['.env', '.git', 'composer.json', 'composer.lock'] as $sensitive) {
    if (file_exists(MOFU_ROOT . '/public/' . $sensitive)) {
        $fail('Sensitive path is inside public/: ' . $sensitive);
    }
}

if (!is_file(MOFU_ROOT . '/vendor/autoload.php')) {
    $fail('vendor/autoload.php is missing. Run composer install --no-dev.');
} else {
    $ok('Composer autoloader is present.');
}

if (!is_file(MOFU_ROOT . '/composer.lock')) {
    $fail('composer.lock is missing; dependency versions are not pinned.');
} else {
    $ok('composer.lock is present.');
}

// 8. Uploads must never contain server-executable files.
$uploads = MOFU_ROOT . '/public/uploads';
$dangerous = [];

if (is_dir($uploads)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }

        if (preg_match('/\.(php\d*|phtml|phar|user\.ini)$/i', $file->getFilename()) === 1) {
            $dangerous[] = str_replace(MOFU_ROOT . '/', '', $file->getPathname());
        }
    }
}

if ($dangerous !== []) {
    $fail('Executable/configuration files found under public/uploads: ' . implode(', ', $dangerous));
} else {
    $ok('No executable files found under public/uploads.');
}

$uploadHtaccess = $uploads . '/.htaccess';

if (is_file($uploadHtaccess)) {
    $rules = (string) file_get_contents($uploadHtaccess);

    if (str_contains($rules, 'RemoveHandler') || str_contains($rules, 'php_flag engine off')) {
        $ok('Upload directory deny rules are present (.htaccess).');
    } else {
        $warn('public/uploads/.htaccess exists but does not clearly disable script execution.');
    }
} else {
    $warn('public/uploads/.htaccess is missing; Apache hosts should deny script execution in uploads.');
}

// 9. Report.
echo "MOFU security check\n";
echo "===================\n\n";

foreach ($passes as $message) {
    echo "[OK]   {$message}\n";
}

foreach ($warnings as $message) {
    echo "[WARN] {$message}\n";
}

foreach ($errors as $message) {
    echo "[FAIL] {$message}\n";
}

echo "\n";

if ($errors !== []) {
    echo sprintf("%d error(s), %d warning(s). Deployment gate failed.\n", count($errors), count($warnings));
    exit(1);
}

echo sprintf("0 errors, %d warning(s). Deployment gate passed.\n", count($warnings));
exit(0);


