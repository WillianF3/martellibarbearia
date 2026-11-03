<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store');
$status = '';
$ready = false;
try {
    $cfg = config();
    $db = db();
    $existing = (int)$db->query('SELECT COUNT(*) FROM mt_admins')->fetchColumn();
    if ($existing > 0) {
        $status = 'Já existe um administrador. Entre no painel, ou use a recuperação via terminal descrita no manual.';
    } elseif (empty($cfg['setup_enabled'])) {
        $status = 'Instalação protegida: ative temporariamente setup_enabled no arquivo privado app/config.php e atualize esta página.';
    } elseif (strlen((string)($cfg['install_key'] ?? '')) < 32) {
        $status = 'Configure uma chave de instalação aleatória e privada com pelo menos 32 caracteres.';
    } else {
        $ready = true;
    }
} catch (Throwable $e) {
    error_log('Martelli setup: '.$e->getMessage());
    $status = 'Confira a conexão do banco e a tabela mt_admins antes de continuar.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    $key = (string)($_POST['install_key'] ?? '');
    $secret = (string)($cfg['install_key'] ?? '');
    $name = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirmation = (string)($_POST['confirm_password'] ?? '');
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) {
        $status = 'Sessão expirada. Atualize a página.';
    } elseif (!hash_equals($secret, $key)) {
        $status = 'Chave de instalação inválida.';
    } elseif (text_len($name) < 2 || text_len($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $status = 'Informe nome e e-mail válidos.';
    } elseif (strlen($password) < 12 || strlen($password) > 256 || !hash_equals($password, $passwordConfirmation)) {
        $status = 'Use uma senha entre 12 e 256 caracteres e confirme-a corretamente.';
    } else {
        $locked = false;
        try {
            $stmt = $db->query("SELECT GET_LOCK('martelli_first_admin_v2', 5)");
            $locked = ((int)$stmt->fetchColumn() === 1);
            if (!$locked) throw new RuntimeException('Não foi possível obter o bloqueio de instalação.');
            if ((int)$db->query('SELECT COUNT(*) FROM mt_admins')->fetchColumn() > 0) {
                $status = 'Já existe um administrador. Faça login ou use a recuperação via terminal.';
            } else {
                $stmt = $db->prepare('INSERT INTO mt_admins (name, email, password_hash) VALUES (?, ?, ?)');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
                $status = 'Administrador criado com sucesso! Exclua setup.php, desative setup_enabled e faça login.';
            }
            $ready = false;
        } catch (Throwable $e) {
            error_log('Martelli setup: '.$e->getMessage());
            $status = 'Não foi possível criar o administrador. Confira o log PHP e a tabela mt_admins.';
        } finally {
            if ($locked) $db->query("SELECT RELEASE_LOCK('martelli_first_admin_v2')");
        }
    }
}
require __DIR__.'/app/layout.php';
page_head('Primeiro administrador');
?>
<main class="login-page"><div class="login-box">
<a href="/"><img src="/assets/logo-martelli-site.png" width="200" alt="Barbearia Martelli"></a>
<div class="panel">
<span class="eyebrow">Instalação protegida</span>
<h1>Criar administrador</h1>
<p class="text-muted">Esta página é exclusiva para criar a primeira conta, usando a chave privada do servidor. Depois, novas contas são criadas dentro do painel.</p>
<?php if ($status): ?><p class="notice" role="status"><?=h($status)?></p><?php endif; ?>
<?php if ($ready): ?>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?=h(csrf())?>">
<label for="setup-key">Chave de instalação</label><input id="setup-key" type="password" name="install_key" required autocomplete="off">
<label for="setup-name">Nome</label><input id="setup-name" name="name" required maxlength="120" autocomplete="name">
<label for="setup-email">E-mail</label><input id="setup-email" type="email" name="email" required maxlength="190" autocomplete="username">
<label for="setup-password">Senha (mínimo 12 caracteres)</label><input id="setup-password" type="password" name="password" required minlength="12" maxlength="256" autocomplete="new-password">
<label for="setup-confirm">Confirmar senha</label><input id="setup-confirm" type="password" name="confirm_password" required minlength="12" maxlength="256" autocomplete="new-password">
<button class="button button-gold button-full" type="submit">Criar primeiro administrador</button>
</form>
<?php else: ?><a class="button button-gold button-full" href="/login.php">Ir para login</a><?php endif; ?>
<p class="small text-muted" style="margin-top:20px">Nunca envie sua chave de instalação ou senha em capturas de tela.</p>
</div></div></main>
<?php page_end(); ?>
