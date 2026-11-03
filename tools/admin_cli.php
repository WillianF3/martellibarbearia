<?php
/**
 * Ferramenta local para recuperação segura do administrador. SOMENTE CLI/SSH.
 * Nunca exponha reset de senha via navegador, mesmo conhecendo a install_key.
 * php tools/admin_cli.php list
 * php tools/admin_cli.php create "Nome" "email@example.com"   (somente se tabela vazia)
 * php tools/admin_cli.php reset "email@example.com"
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
$action = $argv[1] ?? '';
try {
    $pdo = db();
    if ($action === 'list') {
        foreach ($pdo->query('SELECT id,name,email FROM mt_admins ORDER BY id') as $admin) {
            echo $admin['id'].' | '.$admin['name'].' | '.$admin['email'].PHP_EOL;
        }
        exit;
    }
    if ($action === 'create') {
        if ((int)$pdo->query('SELECT COUNT(*) FROM mt_admins')->fetchColumn() !== 0) {
            throw new RuntimeException('Ja existe administrador. Use o painel ou reset.');
        }
        $name = trim((string)($argv[2] ?? ''));
        $email = strtolower(trim((string)($argv[3] ?? '')));
        if (text_len($name)<2 || text_len($name)>120 || strlen($email)>190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Uso: php tools/admin_cli.php create "Nome" "email@exemplo.com"');
        }
        $temporary = bin2hex(random_bytes(16));
        $q=$pdo->prepare('INSERT INTO mt_admins (name,email,password_hash) VALUES (?,?,?)');
        $q->execute([$name,$email,password_hash($temporary,PASSWORD_DEFAULT)]);
        echo 'Administrador criado. Senha temporaria (anote e altere depois do login): '. $temporary .PHP_EOL;
        exit;
    }
    if ($action === 'reset') {
        $email=strtolower(trim((string)($argv[2]??'')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Uso: php tools/admin_cli.php reset "email@exemplo.com"');
        $temporary=bin2hex(random_bytes(16));
        $q=$pdo->prepare('UPDATE mt_admins SET password_hash=? WHERE email=?');
        $q->execute([password_hash($temporary,PASSWORD_DEFAULT),$email]);
        if($q->rowCount()===0)throw new RuntimeException('Conta nao encontrada. Consulte o comando list.');
        echo 'Senha temporaria para '.$email.' (anote e altere depois do login): '.$temporary.PHP_EOL;
        exit;
    }
    echo 'Uso: php tools/admin_cli.php list|create|reset'.PHP_EOL;
    exit(1);
} catch(Throwable $e) {
    fwrite(STDERR, 'Falha: '.$e->getMessage().PHP_EOL);
    exit(1);
}
