<?php
declare(strict_types=1);
namespace Martelli\Infrastructure;

use PDO;

/** Fábrica de conexão PDO com consultas preparadas nativas e horário da operação. */
final class DatabaseConnection
{
    public static function connect(array $config): PDO
    {
        $dsn = 'mysql:host=' . ($config['db_host'] ?? 'localhost')
            . ';port=' . (int)($config['db_port'] ?? 3306)
            . ';dbname=' . ($config['db_name'] ?? '') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $config['db_user'] ?? '', $config['db_password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '-03:00'");
        return $pdo;
    }
}
