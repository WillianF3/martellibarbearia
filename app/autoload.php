<?php
declare(strict_types=1);

/**
 * Autocarregador pequeno para o domínio Martelli.
 * Não depende de Composer e mantém compatibilidade com hospedagem compartilhada.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Martelli\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    foreach (explode('\\', $relative) as $segment) {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $segment)) return;
    }
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) require_once $path;
});
