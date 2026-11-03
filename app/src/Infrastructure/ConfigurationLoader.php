<?php
declare(strict_types=1);
namespace Martelli\Infrastructure;

use RuntimeException;

/**
 * Lê a configuração local e adapta nomes legados das chaves de conexão.
 * Nunca envia credenciais para HTML, logs ou respostas da API.
 */
final class ConfigurationLoader
{
    public static function load(string $appDirectory): array
    {
        $path = $appDirectory . '/config.php';
        if (!is_file($path)) {
            $path = $appDirectory . '/config.local.php';
        }
        if (!is_file($path)) {
            throw new RuntimeException('Configure app/config.php conforme o manual.');
        }
        $config = require $path;
        if (!is_array($config)) {
            throw new RuntimeException('Configuração inválida.');
        }
        foreach (['db_host' => 'host', 'db_port' => 'port', 'db_name' => 'database',
                  'db_user' => 'username', 'db_password' => 'password'] as $new => $legacy) {
            if (!array_key_exists($new, $config) && array_key_exists($legacy, $config)) {
                $config[$new] = $config[$legacy];
            }
        }
        return $config;
    }
}
