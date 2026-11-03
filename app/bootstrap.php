<?php
declare(strict_types=1);
/** Porta de entrada legada: helpers globais delegam a classes do domínio. */
require_once __DIR__ . '/autoload.php';
date_default_timezone_set('America/Sao_Paulo');
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('martelli_session');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
}
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
/** Carrega configurações locais e aceita as chaves legadas da Hostinger. */
function config(): array {
    return \Martelli\Infrastructure\ConfigurationLoader::load(__DIR__);
}

/** Obtém a conexão PDO compartilhada nesta requisição. */
function db(): PDO {
    static $pdo = null;
    return $pdo ??= \Martelli\Infrastructure\DatabaseConnection::connect(config());
}

/** Escapa conteúdo dinâmico para saída segura em HTML. */
function h($str): string { return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
/** Obtém o token CSRF da sessão atual. */
function csrf(): string { return (string)$_SESSION['csrf']; }
/** Bloqueia requisições mutáveis sem token CSRF válido. */
function check_csrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf']??'');
    if (!is_string($token) || !hash_equals(csrf(), $token)) fail('Sessão expirada. Recarregue a página.', 419);
}
/** Emite resposta JSON e encerra a requisição HTTP. */
function json_out(array $data, int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
/** Padroniza respostas JSON de erro sem expor exceções internas. */
function fail(string $message, int $status=400): never { json_out(['ok'=>false,'error'=>$message], $status); }
/** Decodifica o corpo JSON da requisição ou retorna erro HTTP. */
function input(): array {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) fail('Envie um JSON válido.');
    return $data;
}
require_once __DIR__.'/access_v10.php';
/** Restringe a operação a um administrador autenticado e ativo. */
function require_admin(): void { if (!mt_active_admin()) fail('Acesso administrativo desativado.',403); }
/** Consulta o estado administrativo sem alterar a sessão. */
function admin_logged(): bool { return mt_active_admin(); }
/** Identidade de barbeiro verificada novamente no banco em cada requisição.
 * Isso revoga o acesso imediatamente ao desativar a conta ou o profissional. */
function barber_identity(): ?array {
    if (!empty($_SESSION['admin_id'])) {
        $access=mt_access_state();
        if (!$access['enabled'] || !mt_can('minha_agenda') || !$access['barber_id']) return null;
        $q=db()->prepare('SELECT id,name FROM mt_barbers WHERE id=? AND active=1 LIMIT 1');
        $q->execute([$access['barber_id']]);
        $b=$q->fetch();
        return $b ? ['id'=>(int)$_SESSION['admin_id'],'barber_id'=>(int)$b['id'],'name'=>$b['name']] : null;
    }
    if (empty($_SESSION['staff_user_id'])) return null;
    $q=db()->prepare('SELECT u.id, u.barber_id, b.name FROM mt_barber_users u INNER JOIN mt_barbers b ON b.id=u.barber_id WHERE u.id=? AND u.active=1 AND b.active=1 LIMIT 1');
    $q->execute([(int)$_SESSION['staff_user_id']]);
    $identity=$q->fetch();
    if (!$identity) return null;
    return ['id'=>(int)$identity['id'], 'barber_id'=>(int)$identity['barber_id'], 'name'=>$identity['name']];
}
/** Exige uma sessão de barbeiro válida antes de acessar agenda privada. */
function require_barber(): array {
    $identity=barber_identity();
    if ($identity===null) fail('Acesso restrito à agenda do profissional.',403);
    return $identity;
}
/** Identifica sessões de equipe separadas dos administradores. */
function staff_logged(): bool { return !empty($_SESSION['staff_user_id']) && empty($_SESSION['admin_id']); }
/** Verifica permissão de novos horários na agenda individual. */
function barber_can_book_today(): bool {
    // Always resolve the logged-in barber again, including account status and active professional.
    if (barber_identity() === null) return false;
    if (staff_logged()) {
        // Existing staff accounts start with own-day booking enabled. Owner may revoke it.
        $staffId=(int)$_SESSION['staff_user_id'];
        return $staffId>0 && setting('staff_booking_'.$staffId,'1') === '1';
    }
    // Delegated administrator accounts require the explicit permissions granted by the owner.
    return mt_can('minha_agenda') && mt_can('agendar_meu_dia');
}


/** Encerramento antecipado: não descarta o agendamento, apenas deixa de bloquear
 * o tempo posterior ao término real. Sem migração, mantém a agenda totalmente
 * ocupada (comportamento anterior), sem interromper o agendamento público.
 */
function mt_has_finished_at(): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    $q=db()->query("SHOW COLUMNS FROM mt_appointments LIKE 'finished_at'");
    return $ready=(bool)$q->fetchColumn();
}
/** Produz expressão SQL para calcular fim efetivo do horário ocupado. */
function mt_busy_until_sql(string $alias=''): string {
    $p=$alias!=='' ? $alias.'.' : '';
    if (!mt_has_finished_at()) return $p.'ends_at';
    return "(CASE WHEN {$p}status='completed' AND {$p}finished_at IS NOT NULL "
        ."THEN LEAST({$p}ends_at,GREATEST({$p}starts_at,{$p}finished_at)) ELSE {$p}ends_at END)";
}
/** Monta seleção opcional da coluna finished_at sem quebrar versões antigas. */
function mt_finished_at_select(string $alias=''): string {
    $p=$alias!=='' ? $alias.'.' : '';
    return mt_has_finished_at() ? $p.'finished_at' : 'NULL AS finished_at';
}
/** Lê configuração operacional persistida em mt_settings. */
function setting(string $key, string $fallback=''): string {
    $stmt=db()->prepare('SELECT `value` FROM mt_settings WHERE `key`=?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $fallback : (string)$v;
}
/** Lista somente tabelas e ordenações da lista permitida. */
function fetch_all(string $table, string $order='id'): array {
    $allowed = [
      'mt_services'=>['sort_order','id'], 'mt_barbers'=>['sort_order','id'],
      'mt_business_hours'=>['weekday'], 'mt_clients'=>['name','id'],
    ];
    if (!isset($allowed[$table]) || !in_array($order, $allowed[$table], true)) throw new InvalidArgumentException('Consulta inválida.');
    return db()->query("SELECT * FROM `$table` ORDER BY `$order`")->fetchAll();
}
/** Conta caracteres UTF-8 para validar limites de campos. */
function text_len(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value); }
/** Mantém apenas dígitos, sem validar formato. */
function digits(string $v): string {
    return \Martelli\Domain\BrazilianPhone::digits($v);
}

/** Retorna DDD+número para persistência compatível com cadastros existentes. */
function normalize_br_phone(string $value): string {
    return \Martelli\Domain\BrazilianPhone::toLocal($value);
}

/** Retorna DDI+DDD+número ou vazio para telefone inválido. */
function whatsapp_for_link(string $value): string {
    return \Martelli\Domain\BrazilianPhone::forWhatsApp($value);
}

/** Converte valores de checkbox em inteiro 0/1. */
function boolish(mixed $value): int { return in_array((string)$value, ['1','true','on'], true) ? 1 : 0; }
