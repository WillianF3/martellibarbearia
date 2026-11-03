<?php
/** Login VIP e ativação por link privado fornecido pelo administrador. */
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/management.php';
require __DIR__.'/../app/vip_schedule.php';
require __DIR__.'/../app/vip_auth.php';
require __DIR__.'/../app/vip_mailer.php';
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
$action=(string)($_GET['action']??'');
$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
try {
  if($action==='state' && $method==='GET') {
    $ident=vip_auth_identity();
    if($ident){
      $blocked=vip_auth_login_block((int)$ident['client_id']);
      if($blocked){
        vip_auth_clear();
        json_out(['ok'=>true,'logged_in'=>false,'blocked'=>$blocked]);
      }
    }
    json_out(['ok'=>true,'logged_in'=>$ident!==null,'name'=>$ident['name']??null,'must_change_password'=>$ident?((int)$ident['must_change_password']===1):false]);
  }
  if($method!=='POST')fail('Operação indisponível.',405);
  check_csrf();
  vip_auth_require_schema();
  $d=input();
  // Recuperação de acesso: resposta idêntica para contas existentes ou inexistentes.
  if($action==='request_reset') {
    $email=strtolower(trim((string)($d['email']??'')));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)fail('Informe um e-mail válido.',422);
    $generic=['ok'=>true,'message'=>'Se este e-mail estiver habilitado para recuperação VIP, você receberá um link em alguns minutos. Confira também o spam.'];
    // Limite de solicitações por sessão; o banco aplica limite independente da sessão por conta.
    $at=array_values(array_filter((array)($_SESSION['vip_reset_requests']??[]),
      static fn($t)=>(int)$t>time()-3600));
    if(count($at)>=5){$_SESSION['vip_reset_requests']=$at;json_out($generic);}
    $at[]=time();$_SESSION['vip_reset_requests']=$at;
    try {
      $pdo=db();
      $ready=$pdo->query("SHOW TABLES LIKE 'mt_vip_password_reset_tokens'")->fetchColumn();
      if(!$ready){error_log('VIP reset: falta migração V46.');json_out($generic);}
      // Solicitações aprovadas vinculadas ao cliente + e-mail Google previamente verificado.
      // Nunca escolher arbitrariamente se o e-mail pertencer a mais de um cliente.
      $sql="SELECT DISTINCT x.client_id FROM (SELECT COALESCE(r.client_id,m.client_id) AS client_id
          FROM mt_vip_requests r LEFT JOIN mt_vip_memberships m ON m.id=r.membership_id
          WHERE r.status='approved' AND LOWER(TRIM(r.client_email))=?";
      $args=[$email];
      if(vip_google_ready()){
        $sql.=" UNION ALL SELECT g.client_id FROM mt_vip_google_accounts g WHERE LOWER(TRIM(g.google_email))=?";
        $args[]=$email;
      }
      $sql.=") x JOIN mt_vip_customer_accounts a ON a.client_id=x.client_id
              WHERE x.client_id IS NOT NULL LIMIT 2";
      $q=$pdo->prepare($sql);$q->execute($args);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
      if(count($ids)!==1){json_out($generic);}
      $clientId=(int)$ids[0];
      $recent=$pdo->prepare("SELECT COUNT(*) FROM mt_vip_password_reset_tokens
                            WHERE client_id=? AND created_at>DATE_SUB(NOW(),INTERVAL 1 HOUR)");
      $recent->execute([$clientId]);
      if((int)$recent->fetchColumn()>=3)json_out($generic);
      $cooldown=$pdo->prepare("SELECT COUNT(*) FROM mt_vip_password_reset_tokens
                              WHERE client_id=? AND created_at>DATE_SUB(NOW(),INTERVAL 3 MINUTE)");
      $cooldown->execute([$clientId]);
      if((int)$cooldown->fetchColumn()>0)json_out($generic);
      if(vip_mail_settings()===null){error_log('VIP reset: configure app/smtp.local.php.');json_out($generic);}
      $token=bin2hex(random_bytes(32));
      $hash=hash('sha256',$token);
      $insert=$pdo->prepare("INSERT INTO mt_vip_password_reset_tokens
                  (client_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))");
      $insert->execute([$clientId,$hash]);
      $resetId=(int)$pdo->lastInsertId();
      $link='https://martellibarbearia.com/vip-redefinir.php?token='.$token;
      $body="Olá!\n\nRecebemos um pedido de redefinição da senha da sua conta Martelli VIP.\n\n".
           "Para criar uma nova senha, abra o link abaixo em até 30 minutos:\n".$link."\n\n".
           "O link só pode ser utilizado uma vez. Se você não solicitou esta alteração, ignore este e-mail.\n\n".
           "Barbearia Martelli\nhttps://martellibarbearia.com/";
      if(!vip_mail_send($email,'Redefinir senha da sua conta Martelli VIP',$body)){
        // Nunca deixar token utilizável quando houve falha ao entregar a mensagem.
        $revoke=$pdo->prepare('UPDATE mt_vip_password_reset_tokens SET used_at=NOW() WHERE id=?');
        $revoke->execute([$resetId]);
      }
    } catch(Throwable $e){
      error_log('VIP reset: falha de integração ('.get_class($e).').');
    }
    json_out($generic);
  }
  if($action==='reset_password') {
    $token=strtolower(trim((string)($d['token']??'')));
    $new=(string)($d['new_password']??'');
    $confirm=(string)($d['confirm_password']??'');
    if(!preg_match('/^[a-f0-9]{64}$/D',$token))fail('Link inválido ou expirado. Solicite outro e-mail.',422);
    vip_auth_password($new);
    if(!hash_equals($new,$confirm))fail('As senhas não coincidem.',422);
    $attempts=array_values(array_filter((array)($_SESSION['vip_reset_attempts']??[]),
      static fn($t)=>(int)$t>time()-900));
    if(count($attempts)>=8)fail('Muitas tentativas. Tente novamente mais tarde.',429);
    $attempts[]=time();$_SESSION['vip_reset_attempts']=$attempts;
    $pdo=db();$pdo->beginTransaction();
    try {
      $q=$pdo->prepare("SELECT t.id,t.client_id FROM mt_vip_password_reset_tokens t
               WHERE t.token_hash=? AND t.used_at IS NULL AND t.expires_at>NOW() FOR UPDATE");
      $q->execute([hash('sha256',$token)]);$reset=$q->fetch();
      if(!$reset)fail('Link inválido, expirado ou já utilizado. Solicite outro e-mail.',410);
      $q=$pdo->prepare('UPDATE mt_vip_customer_accounts SET password_hash=?,must_change_password=0,
                session_version=session_version+1,failed_attempts=0,locked_until=NULL WHERE client_id=?');
      $q->execute([password_hash($new,PASSWORD_DEFAULT),(int)$reset['client_id']]);
      if($q->rowCount()!==1)fail('Conta não encontrada. Solicite ajuda à barbearia.',404);
      // Revoga todos os links de recuperação ainda não usados desse cliente.
      $q=$pdo->prepare('UPDATE mt_vip_password_reset_tokens SET used_at=NOW()
                        WHERE client_id=? AND used_at IS NULL');
      $q->execute([(int)$reset['client_id']]);
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    vip_auth_clear();
    session_regenerate_id(true);
    $_SESSION['csrf']=bin2hex(random_bytes(24));
    unset($_SESSION['vip_reset_attempts']);
    json_out(['ok'=>true,'redirect'=>'/vip-entrar.php?senha=redefinida']);
  }
  if($action==='change_password') {
    $ident=vip_auth_identity();
    if(!$ident)fail('Entre com sua senha temporária antes de definir uma nova senha.',401);
    $old=(string)($d['current_password']??'');
    $new=(string)($d['new_password']??'');
    $confirm=(string)($d['confirm_password']??'');
    vip_auth_password($new);
    if(!hash_equals($new,$confirm))fail('A confirmação da senha não corresponde.',422);
    if($old===''||strlen($old)>128)fail('Informe a senha atual.',422);
    if(hash_equals($old,$new))fail('Escolha uma senha diferente da atual.',422);
    $tries=array_values(array_filter((array)($_SESSION['vip_change_attempts']??[]),static fn($at)=>(int)$at>time()-900));
    if(count($tries)>=8)fail('Muitas tentativas de alteração de senha. Tente novamente em 15 minutos.',429);
    $tries[]=time();$_SESSION['vip_change_attempts']=$tries;
    $pdo=db();$pdo->beginTransaction();
    try {
      $q=$pdo->prepare('SELECT password_hash,session_version FROM mt_vip_customer_accounts WHERE client_id=? FOR UPDATE');
      $q->execute([(int)$ident['client_id']]);$account=$q->fetch();
      if(!$account || (int)$account['session_version']!==(int)$ident['session_version'] || !password_verify($old,$account['password_hash'])) fail('Senha atual incorreta.',403);
      $hash=password_hash($new,PASSWORD_DEFAULT);
      $q=$pdo->prepare('UPDATE mt_vip_customer_accounts SET password_hash=?,must_change_password=0,session_version=session_version+1,failed_attempts=0,locked_until=NULL WHERE client_id=?');
      $q->execute([$hash,(int)$ident['client_id']]);
      $version=(int)$account['session_version']+1;
      $pdo->commit();
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    vip_auth_session((int)$ident['client_id'],$version);
    $linkedGoogle=vip_google_finish_pending_link((int)$ident['client_id'], true);
    unset($_SESSION['vip_change_attempts']);
    json_out(['ok'=>true,'redirect'=>$linkedGoogle?'/vip-agenda.php?google=vinculado':'/vip-agenda.php']);
  }
  if($action==='activate') {
    $token=strtolower(trim((string)($d['chave']??'')));
    $password=(string)($d['password']??'');
    vip_auth_password($password);
    $attempts=$_SESSION['vip_activation_attempts']??[];
    $attempts=array_filter($attempts,static fn($t)=>$t>time()-900);
    if(count($attempts)>=6)fail('Muitas tentativas. Tente novamente mais tarde.',429);
    $attempts[]=time();$_SESSION['vip_activation_attempts']=array_values($attempts);
    $m=vip_schedule_identity($token);
    if($m['status']!=='active'||$m['ends_on']<date('Y-m-d'))fail('A assinatura precisa estar ativa para criar o login.',403);
    $clientId=(int)$m['client_id'];
    $pdo=db();$pdo->beginTransaction();
    try {
      $q=$pdo->prepare('SELECT client_id,password_hash,session_version FROM mt_vip_customer_accounts WHERE client_id=? FOR UPDATE');
      $q->execute([$clientId]);$account=$q->fetch();
      if($account && $account['password_hash']!==null)fail('Conta já ativada. Entre com sua senha ou solicite redefinição à barbearia.',409);
      $hash=password_hash($password,PASSWORD_DEFAULT);
      if($account){
         $q=$pdo->prepare('UPDATE mt_vip_customer_accounts SET password_hash=?,must_change_password=0,failed_attempts=0,locked_until=NULL WHERE client_id=? AND password_hash IS NULL');
         $q->execute([$hash,$clientId]);
         $version=(int)$account['session_version'];
      }else{
         $q=$pdo->prepare('INSERT INTO mt_vip_customer_accounts(client_id,password_hash) VALUES(?,?)');
         $q->execute([$clientId,$hash]);$version=1;
      }
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    vip_auth_session($clientId,$version);
    json_out(['ok'=>true,'redirect'=>'/vip-agenda.php']);
  }
  if($action==='login') {
    // Duplo limite: sessão e conta, evitando tentativas repetidas ao alternar sessão.
    $lim=$_SESSION['vip_login_limit']??['count'=>0,'until'=>0];
    if((int)($lim['until']??0)<time())$lim=['count'=>0,'until'=>time()+900];
    if((int)$lim['count']>=8)fail('Muitas tentativas. Tente novamente em 15 minutos.',429);
    $lim['count']++;$_SESSION['vip_login_limit']=$lim;
    $identifier=trim((string)($d['identifier']??$d['phone']??''));
    $password=(string)($d['password']??'');
    if($identifier===''||strlen($identifier)>190||strlen($password)>128)fail('Dados de acesso incorretos.',401);
    $row=null;
    if(filter_var($identifier,FILTER_VALIDATE_EMAIL)){
       // mt_clients não contém e-mail: somente solicitações VIP aprovadas têm client_email.
       // Se o e-mail estiver repetido entre contas distintas, nunca escolher uma ao acaso.
       $q=db()->prepare("SELECT DISTINCT a.client_id,a.password_hash,a.session_version,a.must_change_password,a.failed_attempts,a.locked_until
             FROM mt_vip_requests r
             JOIN mt_vip_memberships m ON m.id=r.membership_id
             JOIN mt_vip_customer_accounts a ON a.client_id=m.client_id
             WHERE r.status='approved' AND LOWER(TRIM(r.client_email))=? LIMIT 2");
       $q->execute([strtolower($identifier)]);
       $matches=$q->fetchAll();
       if(count($matches)===1)$row=$matches[0];
    } else {
       $phone=normalize_br_phone($identifier);
       if($phone!==''){
         $q=db()->prepare('SELECT a.client_id,a.password_hash,a.session_version,a.must_change_password,a.failed_attempts,a.locked_until FROM mt_vip_customer_accounts a JOIN mt_clients c ON c.id=a.client_id WHERE c.phone=? LIMIT 1');
         $q->execute([$phone]);$row=$q->fetch();
       }
    }
    if(!$row || !$row['password_hash']){password_verify($password,'$2y$10$LMMTeHNcY9I0gA/Pqdouu.l/5/m8K5c4o8Oxeo47UeJJ/O.7t3dgm');fail('Telefone ou senha incorretos.',401);}
    if($row['locked_until'] && $row['locked_until']>date('Y-m-d H:i:s'))fail('Muitas tentativas. Tente novamente em 15 minutos.',429);
    if(!password_verify($password,$row['password_hash'])){
       $q=db()->prepare('UPDATE mt_vip_customer_accounts SET failed_attempts=failed_attempts+1,locked_until=CASE WHEN failed_attempts+1>=8 THEN DATE_ADD(NOW(),INTERVAL 15 MINUTE) ELSE NULL END WHERE client_id=?');
       $q->execute([(int)$row['client_id']]);
       fail('Telefone ou senha incorretos.',401);
    }
    $clientId=(int)$row['client_id'];
    $blocked=vip_auth_login_block($clientId);
    if($blocked){
       $q=db()->prepare('UPDATE mt_vip_customer_accounts SET failed_attempts=0,locked_until=NULL WHERE client_id=?');
       $q->execute([$clientId]);
       fail($blocked['message'],403);
    }
    // Limpa bloqueios vencidos e atualiza hash antigo quando necessário.
    $hash=password_needs_rehash($row['password_hash'],PASSWORD_DEFAULT)?password_hash($password,PASSWORD_DEFAULT):$row['password_hash'];
    $q=db()->prepare('UPDATE mt_vip_customer_accounts SET failed_attempts=0,locked_until=NULL,password_hash=? WHERE client_id=?');
    $q->execute([$hash,$clientId]);
    vip_auth_session($clientId,(int)$row['session_version']);
    $linkedGoogle=(int)$row['must_change_password']===0 && vip_google_finish_pending_link($clientId, true);
    $_SESSION['vip_login_limit']=['count'=>0,'until'=>0];
    json_out(['ok'=>true,'redirect'=>((int)$row['must_change_password']===1?'/vip-senha.php':($linkedGoogle?'/vip-agenda.php?google=vinculado':'/vip-agenda.php'))]);
  }
  if($action==='logout') {
    vip_auth_clear();session_regenerate_id(true);
    $_SESSION['csrf']=bin2hex(random_bytes(24));
    json_out(['ok'=>true,'redirect'=>'/vip-entrar.php']);
  }
  fail('Operação indisponível.',404);
}catch(PDOException $e){error_log('VIP auth SQL: '.$e->getMessage());fail('Não foi possível acessar sua conta VIP.',503);}
