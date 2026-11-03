<?php
/** OAuth Apple exclusivo de clientes VIP (Apple ID / e-mail iCloud). */
declare(strict_types=1);
// Apple devolve autorização via POST cross-site: sessão deve permitir cookie SameSite=None.
// A cookie é Secure+HttpOnly; state e nonce são verificados no servidor.
if(empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off') {http_response_code(400);exit('Acesso HTTPS necessário.');}
ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');
if(session_status()!==PHP_SESSION_ACTIVE){
 session_name('martelli_session');
 session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'None']);
 session_start();
}
// Reemite cookie do mesmo ID para garantir suporte ao form_post do Apple.
setcookie(session_name(),session_id(),['expires'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'None']);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/vip_schedule.php';
require __DIR__.'/app/vip_auth.php';
require_once __DIR__.'/app/vip_apple_oauth.php';
header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
function vip_apple_back(string $reason,bool $signup=false): never {
 $to=$signup?'/vip-cadastro.php?apple=':'/vip-login.php?apple=';
 header('Location: '.$to.rawurlencode($reason).($signup?'#vip-formulario':''),true,303);exit;
}
try {
 $cfg=vip_apple_config();
 if(!vip_apple_ready()||!vip_apple_signup_ready())vip_apple_back('instalacao',($_GET['action']??'')==='register');
 $action=(string)($_GET['action']??($_SERVER['REQUEST_METHOD']==='POST'?'callback':'login'));
 if(in_array($action,['login','register','link'],true)){
   if($_SERVER['REQUEST_METHOD']!=='GET')vip_apple_back('erro');
   $clientId=0;$proof='';
   if($action==='register'){
      unset($_SESSION['vip_apple_signup'],$_SESSION['vip_google_signup']);
   }elseif($action==='link'){
      $proof=strtolower(trim((string)($_GET['chave']??'')));
      if($proof!==''){
         if(!preg_match('/^[a-f0-9]{64}$/D',$proof))vip_apple_back('sessao');
         $member=vip_schedule_identity($proof);
         if(($member['status']??null)!=='active'||(string)$member['ends_on']<date('Y-m-d'))vip_apple_back('assinatura');
         $clientId=(int)$member['client_id'];
      }else{
         $identity=vip_auth_identity();
         if(!$identity||(int)($identity['must_change_password']??0)===1)vip_apple_back('sessao');
         $clientId=(int)$identity['client_id'];
      }
      if(!vip_google_valid_membership($clientId))vip_apple_back('assinatura');
   }
   $state=vip_google_b64url(random_bytes(32));$nonce=vip_google_b64url(random_bytes(32));
   $_SESSION['vip_apple_oauth']=['state'=>$state,'nonce'=>$nonce,'purpose'=>$action,'proof'=>$proof,'client_id'=>$clientId,'created'=>time()];
   session_write_close();
   $q=['response_type'=>'code','client_id'=>$cfg['client_id'],'redirect_uri'=>$cfg['redirect_uri'],
       'response_mode'=>'form_post','scope'=>'email name','state'=>$state,'nonce'=>$nonce];
   header('Location: https://appleid.apple.com/auth/authorize?'.http_build_query($q,'','&',PHP_QUERY_RFC3986),true,302);exit;
 }
 if($action!=='callback'||$_SERVER['REQUEST_METHOD']!=='POST')vip_apple_back('erro');
 $pending=$_SESSION['vip_apple_oauth']??null;unset($_SESSION['vip_apple_oauth']);
 $signup=is_array($pending)&&($pending['purpose']??null)==='register';
 if(!is_array($pending)||time()-(int)($pending['created']??0)>600
    ||!is_string($_POST['state']??null)||!hash_equals((string)$pending['state'],(string)$_POST['state']))vip_apple_back('sessao',$signup);
 if(!empty($_POST['error']))vip_apple_back('cancelado',$signup);
 $code=$_POST['code']??'';
 if(!is_string($code)||strlen($code)<10||strlen($code)>2048)vip_apple_back('erro',$signup);
 $token=json_decode(vip_apple_http('https://appleid.apple.com/auth/token',[
   'client_id'=>$cfg['client_id'],'client_secret'=>vip_apple_client_secret($cfg),'code'=>$code,
   'grant_type'=>'authorization_code','redirect_uri'=>$cfg['redirect_uri'],
 ]),true);
 if(!is_array($token)||!is_string($token['id_token']??null))vip_apple_back('erro',$signup);
 $person=vip_apple_verify_token($token['id_token'],$cfg['client_id'],$pending['nonce']);
 // E-mail Apple é opcionalmente relay (Ocultar Meu E-mail). Identidade principal é o sub verificado.
 $pdo=db();
 if($signup){
   $q=$pdo->prepare('SELECT client_id FROM mt_vip_apple_accounts WHERE apple_sub=?');$q->execute([$person['sub']]);
   if($q->fetchColumn()!==false)vip_apple_back('conta-existente',true);
   $q=$pdo->prepare('SELECT request_id FROM mt_vip_apple_signup_requests WHERE apple_sub=?');$q->execute([$person['sub']]);
   if($q->fetchColumn()!==false)vip_apple_back('solicitacao-existente',true);
   $_SESSION['vip_apple_signup']=['sub'=>$person['sub'],'email'=>$person['email'],'name'=>'','created'=>time()];
   vip_apple_back('verificado',true);
 }
 if(($pending['purpose']??'')==='link'){
   $clientId=(int)$pending['client_id'];
   if($clientId<1)vip_apple_back('sessao');
   if($pending['proof']!==''){
      $member=vip_schedule_identity((string)$pending['proof']);
      if((int)$member['client_id']!==$clientId)vip_apple_back('sessao');
   }else{
      $identity=vip_auth_identity();
      if(!$identity||(int)$identity['client_id']!==$clientId||(int)$identity['must_change_password']===1)vip_apple_back('sessao');
   }
   if(!vip_google_valid_membership($clientId))vip_apple_back('assinatura');
   $pdo->beginTransaction();
   try{
      $q=$pdo->prepare('SELECT client_id FROM mt_vip_apple_accounts WHERE apple_sub=? FOR UPDATE');$q->execute([$person['sub']]);$other=$q->fetchColumn();
      if($other!==false&&(int)$other!==$clientId)throw new RuntimeException('APPLE_LINK');
      $q=$pdo->prepare('SELECT apple_sub FROM mt_vip_apple_accounts WHERE client_id=? FOR UPDATE');$q->execute([$clientId]);$existing=$q->fetchColumn();
      if($existing!==false&&$existing!==$person['sub'])throw new RuntimeException('APPLE_LINK');
      $pdo->prepare('INSERT INTO mt_vip_customer_accounts(client_id,password_hash) VALUES (?,NULL) ON DUPLICATE KEY UPDATE client_id=client_id')->execute([$clientId]);
      if($existing===false)$pdo->prepare('INSERT INTO mt_vip_apple_accounts(client_id,apple_sub,apple_email,last_login_at) VALUES(?,?,?,NOW())')->execute([$clientId,$person['sub'],$person['email']]);
      else $pdo->prepare('UPDATE mt_vip_apple_accounts SET apple_email=?,last_login_at=NOW() WHERE client_id=?')->execute([$person['email'],$clientId]);
      $q=$pdo->prepare('SELECT session_version,must_change_password FROM mt_vip_customer_accounts WHERE client_id=?');$q->execute([$clientId]);$account=$q->fetch();
      if(!$account||(int)$account['must_change_password']===1)throw new RuntimeException('APPLE_LINK');
      $pdo->commit();
   }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
   vip_auth_session($clientId,(int)$account['session_version']);header('Location: /vip-agenda.php?apple=vinculado',true,303);exit;
 }
 if(($pending['purpose']??'')!=='login')vip_apple_back('erro');
 $q=$pdo->prepare('SELECT a.client_id,c.session_version,c.must_change_password FROM mt_vip_apple_accounts a JOIN mt_vip_customer_accounts c ON c.client_id=a.client_id WHERE a.apple_sub=?');$q->execute([$person['sub']]);$account=$q->fetch();
 if(!$account)vip_apple_back('nao-vinculado');
 if((int)$account['must_change_password']===1)vip_apple_back('senha-temporaria');
 if(!vip_google_valid_membership((int)$account['client_id']))vip_apple_back('assinatura');
 $pdo->prepare('UPDATE mt_vip_apple_accounts SET last_login_at=NOW(),apple_email=? WHERE client_id=?')->execute([$person['email'],(int)$account['client_id']]);
 vip_auth_session((int)$account['client_id'],(int)$account['session_version']);header('Location: /vip-agenda.php',true,303);exit;
}catch(Throwable $e){
 $category=$e instanceof PDOException?'banco':($e->getMessage()==='APPLE_CONFIG'?'config':($e->getMessage()==='APPLE_CONNECT'?'conexao':'erro'));
 error_log('Martelli Apple OAuth category='.$category.' class='.get_class($e));
 $signup=($_GET['action']??'')==='register'||(!empty($pending)&&($pending['purpose']??'')==='register');
 vip_apple_back($category,$signup);
}
