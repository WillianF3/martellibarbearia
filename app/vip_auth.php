<?php
/** Acesso VIP independente de contas administrativas e de barbeiros. */
declare(strict_types=1);
require_once __DIR__.'/vip_google_oauth.php';
require_once __DIR__.'/vip_apple_oauth.php';
/** Detecta se as tabelas da autenticação VIP estão disponíveis. */
function vip_auth_ready(): bool {
    try {return db()->query("SHOW TABLES LIKE 'mt_vip_customer_accounts'")->fetchColumn()!==false;}
    catch(Throwable $e){return false;}
}
/** Impede uso da autenticação sem migrações necessárias. */
function vip_auth_require_schema(): void {
    if(!vip_auth_ready())fail('O acesso VIP ainda não foi instalado. Peça à equipe a atualização V24.',503);
}
/** Revoga identidade VIP da sessão atual. */
function vip_auth_clear(): void {
    unset($_SESSION['vip_client_id'],$_SESSION['vip_session_version']);
}
/** Revalida identidade VIP e estado da conta no banco. */
function vip_auth_identity(): ?array {
    if(empty($_SESSION['vip_client_id']) || empty($_SESSION['vip_session_version']) || !vip_auth_ready())return null;
    // Um cliente pode usar senha tradicional, Google vinculado, ou ambos.
    // A tabela Google é opcional até a migração V38 ser instalada.
    $googleAllowed=vip_google_ready()
        ? " OR EXISTS(SELECT 1 FROM mt_vip_google_accounts ga WHERE ga.client_id=a.client_id)"
        : '';
    $appleAllowed=vip_apple_ready() ? " OR EXISTS(SELECT 1 FROM mt_vip_apple_accounts aa WHERE aa.client_id=a.client_id)" : '';
    $q=db()->prepare('SELECT a.client_id,a.session_version,a.must_change_password,c.name,c.phone FROM mt_vip_customer_accounts a JOIN mt_clients c ON c.id=a.client_id WHERE a.client_id=? AND (a.password_hash IS NOT NULL'.$googleAllowed.$appleAllowed.') LIMIT 1');
    $q->execute([(int)$_SESSION['vip_client_id']]);$row=$q->fetch();
    if(!$row || (int)$row['session_version']!==(int)$_SESSION['vip_session_version']){vip_auth_clear();return null;}
    return $row;
}
/** Carrega a assinatura vinculada à sessão, sob lock opcional. */
function vip_auth_member(bool $lock=false): array {
    $identity=vip_auth_identity();
    if(!$identity)fail('Entre com sua conta VIP para acessar a agenda.',401);
    if((int)($identity['must_change_password']??0)===1)fail('Por segurança, altere sua senha temporária antes de acessar a agenda VIP.',403);
    vip_schedule_require_schema();
    // Nunca recebe client_id ou membership_id do browser. O banco resolve pela sessão.
    $sql="SELECT m.*,c.name client_name,p.name plan_name FROM mt_vip_memberships m JOIN mt_clients c ON c.id=m.client_id JOIN mt_vip_plans p ON p.id=m.plan_id WHERE m.client_id=? ORDER BY (m.status='active' AND m.ends_on>=CURDATE()) DESC,m.starts_on DESC,m.id DESC LIMIT 1";
    if($lock)$sql.=" FOR UPDATE";
    $q=db()->prepare($sql);
    $q->execute([(int)$identity['client_id']]);$m=$q->fetch();
    if(!$m)fail('Não existe assinatura VIP vinculada a essa conta.',403);
    return $m;
}
/** Busca a última assinatura do cliente para decidir se o acesso VIP deve continuar liberado. */
function vip_auth_latest_membership(int $clientId): ?array {
    vip_schedule_require_schema();
    $q=db()->prepare("SELECT m.*,p.name plan_name FROM mt_vip_memberships m LEFT JOIN mt_vip_plans p ON p.id=m.plan_id WHERE m.client_id=? ORDER BY (m.status='active' AND m.ends_on>=CURDATE()) DESC,m.starts_on DESC,m.id DESC LIMIT 1");
    $q->execute([$clientId]);
    $row=$q->fetch();
    return $row ?: null;
}
/**
 * Bloqueia o acesso à agenda quando o plano estiver cancelado ou pausado.
 * Planos vencidos continuam visíveis, porque a regra solicitada vale apenas
 * para assinaturas pausadas/canceladas e o cliente pode precisar consultar o histórico.
 */
function vip_auth_login_block(int $clientId): ?array {
    $m=vip_auth_latest_membership($clientId);
    if(!$m)return ['code'=>'sem-plano','message'=>'Nenhum plano VIP foi encontrado para este CPF. Entre em contato com a barbearia para verificar o seu cadastro.'];
    $status=strtolower(trim((string)($m['status']??'')));
    if($status==='cancelled'){
        return ['code'=>'cancelado','message'=>'Seu plano VIP está cancelado. Entre em contato com a barbearia para reativar o plano e voltar a acessar seus cortes de cabelo agendados.'];
    }
    if($status==='paused'){
        return ['code'=>'pausado','message'=>'Seu plano VIP está pausado. Entre em contato com a barbearia para reativar o plano e voltar a acessar seus cortes de cabelo agendados.'];
    }
    return null;
}
/** Registra vínculo de sessão VIP após autenticação válida. */
function vip_auth_session(int $clientId,int $sessionVersion): void {
    session_regenerate_id(true);
    unset($_SESSION['admin_id'],$_SESSION['admin_name'],$_SESSION['staff_user_id']);
    $_SESSION['vip_client_id']=$clientId;
    $_SESSION['vip_session_version']=$sessionVersion;
    $_SESSION['csrf']=bin2hex(random_bytes(24));
}
/** Confere a senha da conta VIP usando hash seguro. */
function vip_auth_password(string $password): void {
    if(strlen($password)<10||strlen($password)>128)fail('Escolha uma senha entre 10 e 128 caracteres.');
}
