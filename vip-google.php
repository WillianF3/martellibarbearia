<?php
/** Autenticação Google com OAuth2 Authorization Code, PKCE, state, nonce e ID token assinado. */
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/vip_schedule.php';
require __DIR__.'/app/vip_auth.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
function vip_google_redirect(string $code): never {
    header('Location: /vip-login.php?google='.rawurlencode($code),true,303);exit;
}
function vip_google_signup_redirect(string $code): never {
    header('Location: /vip-cadastro.php?google='.rawurlencode($code).'#vip-formulario',true,303);exit;
}
function vip_google_signup_ready(): bool {
    try { return db()->query("SHOW TABLES LIKE 'mt_vip_google_signup_requests'")->fetchColumn()!==false; }
    catch (Throwable $e) { return false; }
}
try {
    $cfg=vip_google_config();
    if(!vip_google_ready())vip_google_redirect('instalacao');
    $action=(string)($_GET['action']??(isset($_GET['code'])||isset($_GET['state'])||isset($_GET['error'])?'callback':'login'));
    if($action==='login'||$action==='link'||$action==='register'){
        if(!empty($_GET['code'])||!empty($_GET['state']))vip_google_redirect('erro');
        $proof='';$clientId=0;
        if($action==='register'){
            if(!vip_google_signup_ready())vip_google_signup_redirect('instalacao');
            unset($_SESSION['vip_google_signup'],$_SESSION['vip_apple_signup']);
        }
        if($action==='link'){
            $proof=strtolower(trim((string)($_GET['chave']??'')));
            if($proof!==''){
                // Somente um link VIP pessoal válido permite a vinculação sem senha.
                $m=vip_schedule_identity($proof);
                if($m['status']!=='active'||(string)$m['ends_on']<date('Y-m-d'))vip_google_redirect('assinatura');
                $clientId=(int)$m['client_id'];
            }else{
                $identity=vip_auth_identity();
                if(!$identity || (int)($identity['must_change_password']??0)===1)vip_google_redirect('primeiro-acesso');
                $clientId=(int)$identity['client_id'];
            }
            if(!vip_google_valid_membership($clientId))vip_google_redirect('assinatura');
        }
        $state=vip_google_b64url(random_bytes(32));
        $nonce=vip_google_b64url(random_bytes(32));
        $verifier=vip_google_b64url(random_bytes(48));
        $_SESSION['vip_google_oauth']=['state'=>$state,'nonce'=>$nonce,'verifier'=>$verifier,'purpose'=>$action,'client_id'=>$clientId,'proof'=>$proof,'created'=>time()];
        $q=[
            'client_id'=>$cfg['client_id'],'redirect_uri'=>$cfg['redirect_uri'],
            'response_type'=>'code','scope'=>'openid email profile','state'=>$state,'nonce'=>$nonce,
            'code_challenge'=>vip_google_b64url(hash('sha256',$verifier,true)),'code_challenge_method'=>'S256',
            'prompt'=>'select_account',
        ];
        header('Location: https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($q,'','&',PHP_QUERY_RFC3986),true,302);exit;
    }
    if($action!=='callback')vip_google_redirect('erro');
    $pending=$_SESSION['vip_google_oauth']??null;
    unset($_SESSION['vip_google_oauth']);
    $isPendingSignup=is_array($pending) && ($pending['purpose']??'')==='register';
    if(!is_array($pending)||time()-(int)($pending['created']??0)>600||!is_string($_GET['state']??null)
      ||!hash_equals((string)($pending['state']??''),(string)$_GET['state'])){
        if($isPendingSignup)vip_google_signup_redirect('sessao');
        vip_google_redirect('sessao');
    }
    if(!empty($_GET['error'])){
        if($isPendingSignup)vip_google_signup_redirect('cancelado');
        vip_google_redirect('cancelado');
    }
    $code=$_GET['code']??'';
    if(!is_string($code)||strlen($code)<10||strlen($code)>2048)vip_google_redirect('erro');
    $tokenData=json_decode(vip_google_request('https://oauth2.googleapis.com/token',[
        'code'=>$code,'client_id'=>$cfg['client_id'],'client_secret'=>$cfg['client_secret'],
        'redirect_uri'=>$cfg['redirect_uri'],'grant_type'=>'authorization_code',
        'code_verifier'=>$pending['verifier'],
    ]),true);
    if(!is_array($tokenData)||!is_string($tokenData['id_token']??null))vip_google_redirect('erro');
    $person=vip_google_verify_id_token($tokenData['id_token'],$cfg['client_id'],$pending['nonce']);
    $pdo=db();
    if(($pending['purpose']??'')==='register'){
        if(!vip_google_signup_ready())vip_google_signup_redirect('instalacao');
        // O Google comprova identidade/e-mail, não a titularidade do WhatsApp.
        // Nunca cria conta VIP nem concede acesso ao histórico nesta etapa.
        $linked=$pdo->prepare('SELECT client_id FROM mt_vip_google_accounts WHERE google_sub=? LIMIT 1');
        $linked->execute([$person['sub']]);
        $linkedClient=$linked->fetchColumn();
        if($linkedClient!==false){
            // Identidade Google já vinculada: usar o mesmo fluxo de autenticação
            // da agenda, sem abrir outro pré-cadastro ou duplicar assinatura.
            $q=$pdo->prepare('SELECT session_version,must_change_password FROM mt_vip_customer_accounts WHERE client_id=? LIMIT 1');
            $q->execute([(int)$linkedClient]);$account=$q->fetch();
            if(!$account || (int)$account['must_change_password']===1) {
                header('Location: /vip-entrar.php?google=senha-temporaria',true,303);exit;
            }
            if(!vip_google_valid_membership((int)$linkedClient)) {
                header('Location: /vip-entrar.php?google=assinatura',true,303);exit;
            }
            $pdo->prepare('UPDATE mt_vip_google_accounts SET google_email=?,last_login_at=NOW() WHERE client_id=?')
                ->execute([(string)$person['email'],(int)$linkedClient]);
            vip_auth_session((int)$linkedClient,(int)$account['session_version']);
            header('Location: /vip-agenda.php',true,303);exit;
        }
        // Mesmo que a conta Google ainda não esteja vinculada, não iniciar nova
        // solicitação quando o e-mail já corresponde a um cliente VIP aprovado.
        $candidate=vip_google_candidate_for_verified_email($pdo,(string)$person['email']);
        if($candidate!==null){
            $pw=$pdo->prepare('SELECT password_hash FROM mt_vip_customer_accounts WHERE client_id=? LIMIT 1');
            $pw->execute([$candidate]);$existingHash=$pw->fetchColumn();
            if(is_string($existingHash)&&$existingHash!==''){
                $_SESSION['vip_google_link_pending']=[
                    'client_id'=>$candidate,'sub'=>$person['sub'],
                    'email'=>$person['email'],'created'=>time(),
                ];
                header('Location: /vip-entrar.php?google=confirmar-senha',true,303);exit;
            }
            header('Location: /vip-entrar.php?google=ativar-acesso',true,303);exit;
        }
        $previous=$pdo->prepare("SELECT r.status FROM mt_vip_google_signup_requests g JOIN mt_vip_requests r ON r.id=g.request_id WHERE g.google_sub=? LIMIT 1");
        $previous->execute([$person['sub']]);
        $status=$previous->fetchColumn();
        if($status!==false)vip_google_signup_redirect('solicitacao-existente');
        $_SESSION['vip_google_signup']=[
            'sub'=>$person['sub'], 'email'=>$person['email'],
            'name'=>trim((string)($person['name']??'')), 'created'=>time(),
        ];
        vip_google_signup_redirect('verificado');
    }
    if(($pending['purpose']??'')==='link'){
        $clientId=(int)($pending['client_id']??0);
        if($clientId<1)vip_google_redirect('erro');
        if(($pending['proof']??'')!==''){
            // Prova de posse do link deve continuar válida mesmo após o redirecionamento Google.
            $m=vip_schedule_identity((string)$pending['proof']);
            if((int)$m['client_id']!==$clientId)vip_google_redirect('erro');
        }else{
            $identity=vip_auth_identity();
            if(!$identity||(int)$identity['client_id']!==$clientId||(int)($identity['must_change_password']??0)===1)vip_google_redirect('sessao');
        }
        if(!vip_google_valid_membership($clientId))vip_google_redirect('assinatura');
        $pdo->beginTransaction();
        try{
            $q=$pdo->prepare('SELECT client_id FROM mt_vip_google_accounts WHERE google_sub=? FOR UPDATE');
            $q->execute([$person['sub']]);$existing=$q->fetchColumn();
            if($existing!==false && (int)$existing!==$clientId)throw new RuntimeException('Google já vinculado a outro cliente.');
            $q=$pdo->prepare('SELECT google_sub FROM mt_vip_google_accounts WHERE client_id=? FOR UPDATE');
            $q->execute([$clientId]);$oldSub=$q->fetchColumn();
            if($oldSub!==false && $oldSub!==$person['sub'])throw new RuntimeException('Já existe outra conta Google vinculada.');
            $pdo->prepare('INSERT INTO mt_vip_customer_accounts(client_id,password_hash) VALUES(?,NULL) ON DUPLICATE KEY UPDATE client_id=client_id')->execute([$clientId]);
            if($oldSub===false)$pdo->prepare('INSERT INTO mt_vip_google_accounts(client_id,google_sub,google_email,last_login_at) VALUES(?,?,?,NOW())')->execute([$clientId,$person['sub'],$person['email']]);
            else $pdo->prepare('UPDATE mt_vip_google_accounts SET google_email=?,last_login_at=NOW() WHERE client_id=?')->execute([$person['email'],$clientId]);
            $q=$pdo->prepare('SELECT session_version,must_change_password FROM mt_vip_customer_accounts WHERE client_id=?');
            $q->execute([$clientId]);$account=$q->fetch();
            if(!$account || (int)$account['must_change_password']===1)throw new RuntimeException('Troque a senha temporária antes de vincular o Google.');
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        vip_auth_session($clientId,(int)$account['session_version']);
        header('Location: /vip-agenda.php?google=vinculado',true,303);exit;
    }
    if(($pending['purpose']??'')!=='login')vip_google_redirect('erro');
    $q=$pdo->prepare('SELECT g.client_id,a.session_version,a.must_change_password FROM mt_vip_google_accounts g JOIN mt_vip_customer_accounts a ON a.client_id=g.client_id WHERE g.google_sub=? LIMIT 1');
    $q->execute([$person['sub']]);$account=$q->fetch();
    if(!$account){
        // Conta Google autenticada, porém ainda não vinculada.
        // Buscar e-mail somente em solicitações VIP aprovadas. Nunca realizar
        // associação apenas pelo e-mail: exigir a senha da conta existente.
        $candidate=vip_google_candidate_for_verified_email($pdo,(string)$person['email']);
        if($candidate!==null){
            $q=$pdo->prepare('SELECT password_hash FROM mt_vip_customer_accounts WHERE client_id=? LIMIT 1');
            $q->execute([$candidate]);
            $hasPassword=$q->fetchColumn();
            if (is_string($hasPassword) && $hasPassword!=='') {
                $_SESSION['vip_google_link_pending']=[
                    'client_id'=>$candidate,'sub'=>$person['sub'],
                    'email'=>$person['email'],'created'=>time(),
                ];
                header('Location: /vip-entrar.php?google=confirmar-senha',true,303);exit;
            }
            header('Location: /vip-entrar.php?google=ativar-acesso',true,303);exit;
        }
        // Algumas assinaturas são cadastradas diretamente no administrativo.
        // Nesses casos, mt_clients não guarda e-mail e não há uma solicitação
        // aprovada para encontrar automaticamente o cadastro. A conta Google
        // JÁ foi verificada, mas somente a senha da conta VIP autoriza o vínculo.
        // Nunca cria conta, autoriza agenda ou vincula Google somente pelo e-mail.
        $_SESSION['vip_google_link_pending']=[
            'client_id'=>0, // cliente será identificado APÓS login VIP com senha
            'sub'=>$person['sub'],
            'email'=>$person['email'],
            'created'=>time(),
        ];
        header('Location: /vip-entrar.php?google=confirmar-conta',true,303);exit;
    }
    if((int)$account['must_change_password']===1)vip_google_redirect('senha-temporaria');
    if(!vip_google_valid_membership((int)$account['client_id']))vip_google_redirect('assinatura');
    $pdo->prepare('UPDATE mt_vip_google_accounts SET google_email=?,last_login_at=NOW() WHERE client_id=?')->execute([$person['email'],(int)$account['client_id']]);
    vip_auth_session((int)$account['client_id'],(int)$account['session_version']);
    header('Location: /vip-agenda.php',true,303);exit;
}catch(Throwable $e){
    // Mostra ao cliente apenas uma categoria segura; detalhes ficam nos logs PHP.
    // Nunca inclua tokens, query strings OAuth ou credenciais nos logs.
    $message=$e->getMessage();
    $category='erro';
    if($e instanceof PDOException)$category='banco';
    elseif($message==='MT_GOOGLE_INVALID_CLIENT')$category='oauth-cliente';
    elseif($message==='MT_GOOGLE_INVALID_GRANT')$category='oauth-codigo';
    elseif($message==='MT_GOOGLE_REDIRECT_URI')$category='oauth-redirecionamento';
    elseif($message==='MT_GOOGLE_CONNECT')$category='google-conexao';
    elseif($message==='MT_GOOGLE_CONFIG'||str_contains($message,'configurad')||str_contains($message,'Configuração Google'))$category='config';
    elseif(str_contains($message,'Google')||str_contains($message,'Token'))$category='google-verificacao';
    $sqlstate=$e instanceof PDOException ? (string)$e->getCode() : '';
    error_log('Martelli Google VIP OAuth categoria='.$category.' tipo='.get_class($e).($sqlstate!=='' ? ' sqlstate='.preg_replace('/[^A-Za-z0-9]/','',$sqlstate) : ''));
    $isSignup=(($_GET['action']??'')==='register') || (isset($pending) && ($pending['purpose']??'')==='register');
    if($isSignup)vip_google_signup_redirect($category);
    vip_google_redirect($category);
}
