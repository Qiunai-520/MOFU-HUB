<?php

declare(strict_types=1);

namespace Mofu\Core\Database;

use Mofu\Bootstrap\Config;
use PDO;

final class Connection
{
    public static function connect(Config $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) $config->get('db.host'),
            (int) $config->get('db.port'),
            (string) $config->get('db.database'),
        );

        return new PDO($dsn, (string) $config->get('db.username'), (string) $config->get('db.password'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
