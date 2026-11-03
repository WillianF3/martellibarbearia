<?php
/** OAuth/OIDC Google apenas para a área VIP; nunca para o painel administrativo. */
declare(strict_types=1);
function vip_google_config(): array {
    $path=__DIR__.'/google_oauth.local.php';
    if (!is_file($path)) throw new RuntimeException('Google ainda não foi configurado pela barbearia.');
    $cfg=require $path;
    if (!is_array($cfg)) throw new RuntimeException('Configuração Google inválida.');
    foreach (['client_id','client_secret','redirect_uri'] as $key) {
        if (!isset($cfg[$key]) || !is_string($cfg[$key]) || trim($cfg[$key])==='') throw new RuntimeException('Configuração Google incompleta.');
    }
    // O arquivo .example.php nao e configuracao real: nao aceite placeholders
    // que deixariam o botao visivel, mas falhariam apenas no retorno do Google.
    if (!preg_match('/^[0-9]+-[a-z0-9_-]+\.apps\.googleusercontent\.com$/D',trim($cfg['client_id']))
        || strlen(trim($cfg['client_secret'])) < 12
        || preg_match('/COLE|EXEMPLO|SEU_|YOUR_|PLACEHOLDER/i',$cfg['client_secret'])) {
        throw new RuntimeException('MT_GOOGLE_CONFIG');
    }
    if ($cfg['redirect_uri'] !== 'https://martellibarbearia.com/vip-google.php') {
        throw new RuntimeException('MT_GOOGLE_CONFIG');
    }
    return $cfg;
}
function vip_google_ready(): bool {
    static $ready=null;
    if ($ready!==null) return $ready;
    try {$ready=db()->query("SHOW TABLES LIKE 'mt_vip_google_accounts'")->fetchColumn()!==false;}
    catch(Throwable $e){$ready=false;}
    return $ready;
}
function vip_google_b64url(string $raw): string {return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');}
function vip_google_decode(string $chunk): string|false {
    if (!preg_match('/^[A-Za-z0-9_-]+$/D',$chunk)) return false;
    return base64_decode(strtr($chunk,'-_','+/').str_repeat('=',(4-strlen($chunk)%4)%4),true);
}
/** Somente HTTPS em destinos fixos do Google e certificado TLS válido. */
function vip_google_request(string $url,?array $post=null): string {
    if (!function_exists('curl_init')) throw new RuntimeException('A extensão PHP cURL é necessária para Google Login.');
    $allowedHosts=['oauth2.googleapis.com','www.googleapis.com'];
    $host=parse_url($url,PHP_URL_HOST);
    if(parse_url($url,PHP_URL_SCHEME)!=='https' || !in_array($host,$allowedHosts,true)) throw new RuntimeException('Destino de autenticação inválido.');
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'Martelli-VIP-Google-OIDC/1.0']);
    if(defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS'))curl_setopt($ch,CURLOPT_PROTOCOLS,CURLPROTO_HTTPS);
    if($post!==null){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post,'','&',PHP_QUERY_RFC3986));}
    $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    // Nunca registre respostas brutas do Google: podem conter tokens.
    // Classifique somente códigos OAuth conhecidos para facilitar o suporte.
    if ($post!==null && $code!==200 && is_string($body)) {
        $error=json_decode($body,true);
        $oauthError=is_array($error) ? ($error['error']??'') : '';
        if ($oauthError==='invalid_client' || $oauthError==='unauthorized_client')
            throw new RuntimeException('MT_GOOGLE_INVALID_CLIENT');
        if ($oauthError==='invalid_grant')
            throw new RuntimeException('MT_GOOGLE_INVALID_GRANT');
        if ($oauthError==='redirect_uri_mismatch')
            throw new RuntimeException('MT_GOOGLE_REDIRECT_URI');
    }
    if(!is_string($body)||$code!==200||strlen($body)>100000)
        throw new RuntimeException('MT_GOOGLE_CONNECT');
    return $body;
}
/** Validação criptográfica de assinatura, público-alvo, emissor, expiração e nonce. */
function vip_google_verify_id_token(string $idToken,string $clientId,string $nonce): array {
    if(strlen($idToken)>12000)throw new RuntimeException('Resposta Google inválida.');
    $parts=explode('.',$idToken);if(count($parts)!==3)throw new RuntimeException('Token Google inválido.');
    [$head,$payload,$signature]=$parts;
    $header=json_decode(vip_google_decode($head)?:'',true);
    $claims=json_decode(vip_google_decode($payload)?:'',true);
    $sig=vip_google_decode($signature);
    if(!is_array($header)||!is_array($claims)||$sig===false||($header['alg']??'')!=='RS256')throw new RuntimeException('Formato de assinatura Google inválido.');
    $kid=$header['kid']??'';
    if(!is_string($kid)||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$kid))throw new RuntimeException('Certificado Google inválido.');
    $certs=json_decode(vip_google_request('https://www.googleapis.com/oauth2/v1/certs'),true);
    if(!is_array($certs)||!isset($certs[$kid])||!is_string($certs[$kid]))throw new RuntimeException('Certificado de autenticação Google indisponível.');
    $key=openssl_pkey_get_public($certs[$kid]);
    if($key===false||openssl_verify($head.'.'.$payload,$sig,$key,OPENSSL_ALGO_SHA256)!==1)throw new RuntimeException('Assinatura Google não verificada.');
    $now=time();
    if(!in_array($claims['iss']??'',['accounts.google.com','https://accounts.google.com'],true)
      ||($claims['aud']??null)!==$clientId
      ||!isset($claims['exp'],$claims['iat'])||(int)$claims['exp']<=$now
      ||(int)$claims['iat']>$now+120
      ||!is_string($claims['nonce']??null)||!hash_equals($nonce,$claims['nonce']))throw new RuntimeException('Verificação de identidade Google falhou.');
    if(($claims['email_verified']??false)!==true||!filter_var($claims['email']??'',FILTER_VALIDATE_EMAIL))throw new RuntimeException('A conta Google precisa ter um e-mail verificado.');
    $sub=$claims['sub']??'';
    if(!is_string($sub)||strlen($sub)<5||strlen($sub)>255)throw new RuntimeException('Identificador Google inválido.');
    $displayName=trim((string)($claims['name']??''));
    if(text_len($displayName)>120)$displayName='';
    return ['sub'=>$sub,'email'=>strtolower((string)$claims['email']),'name'=>$displayName];
}
function vip_google_valid_membership(int $clientId): bool {
    $q=db()->prepare("SELECT id FROM mt_vip_memberships WHERE client_id=? AND status='active' AND ends_on>=CURDATE() LIMIT 1");
    $q->execute([$clientId]);return (bool)$q->fetchColumn();
}

/**
 * Procura somente UMA conta VIP associada a um e-mail de solicitação APROVADA.
 * E-mails de cadastros gerais não são prova de titularidade. Nunca vincule
 * a conta Google pelo e-mail sem confirmar a senha da conta correspondente.
 */
function vip_google_candidate_for_verified_email(PDO $pdo, string $email): ?int {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    $q = $pdo->prepare("SELECT DISTINCT m.client_id
          FROM mt_vip_requests r
          JOIN mt_vip_memberships m ON m.id=r.membership_id
          JOIN mt_vip_customer_accounts a ON a.client_id=m.client_id
          WHERE r.status='approved' AND LOWER(TRIM(r.client_email))=?
          LIMIT 2");
    $q->execute([strtolower(trim($email))]);
    $ids = $q->fetchAll(PDO::FETCH_COLUMN);
    return count($ids) === 1 ? (int)$ids[0] : null;
}
/**
 * Vincula Google somente após prova da senha da conta VIP + identidade Google
 * verificada no callback (state, PKCE, nonce, assinatura e e-mail verificado).
 * A solicitação aprovada é um indício de qual conta mostrar, não substitui senha.
 * Contas cadastradas pelo admin não têm e-mail em mt_clients, portanto elas
 * também podem vincular após informar o WhatsApp e comprovar a senha.
 */
function vip_google_finish_pending_link(int $clientId, bool $passwordConfirmed = false): bool {
    if (!$passwordConfirmed || $clientId < 1) return false;
    $pending = $_SESSION['vip_google_link_pending'] ?? null;
    if (!is_array($pending)) return false;
    unset($_SESSION['vip_google_link_pending']); // tentativa de vínculo tem uso único
    $candidate = (int)($pending['client_id'] ?? -1);
    if (time() - (int)($pending['created'] ?? 0) > 600
        || !in_array($candidate, [0, $clientId], true) || !vip_google_ready()) return false;
    $sub = (string)($pending['sub'] ?? '');
    $email = (string)($pending['email'] ?? '');
    if (strlen($sub) < 5 || strlen($sub) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $pdo=db();
    try {
        $pdo->beginTransaction();
        // Nunca permitir vincular o Google de uma conta identificada pela
        // solicitação aprovada a outra conta. Quando não há e-mail no cadastro,
        // a identidade é comprovada EXCLUSIVAMENTE por login com senha.
        if ($candidate > 0 && vip_google_candidate_for_verified_email($pdo,$email) !== $clientId) { $pdo->rollBack(); return false; }
        $account=$pdo->prepare('SELECT must_change_password FROM mt_vip_customer_accounts WHERE client_id=? FOR UPDATE');
        $account->execute([$clientId]);
        $requiresChange=$account->fetchColumn();
        if ($requiresChange===false || (int)$requiresChange !== 0 || !vip_google_valid_membership($clientId)) { $pdo->rollBack(); return false; }
        $bySub=$pdo->prepare('SELECT client_id FROM mt_vip_google_accounts WHERE google_sub=? FOR UPDATE');
        $bySub->execute([$sub]); $other=$bySub->fetchColumn();
        if($other !== false && (int)$other !== $clientId) { $pdo->rollBack(); return false; }
        $byClient=$pdo->prepare('SELECT google_sub FROM mt_vip_google_accounts WHERE client_id=? FOR UPDATE');
        $byClient->execute([$clientId]); $previous=$byClient->fetchColumn();
        if($previous !== false && !hash_equals((string)$previous,$sub)) { $pdo->rollBack(); return false; }
        if ($previous === false) {
            $insert=$pdo->prepare('INSERT INTO mt_vip_google_accounts(client_id,google_sub,google_email,last_login_at) VALUES(?,?,?,NOW())');
            $insert->execute([$clientId,$sub,$email]);
        } else {
            $update=$pdo->prepare('UPDATE mt_vip_google_accounts SET google_email=?,last_login_at=NOW() WHERE client_id=?');
            $update->execute([$email,$clientId]);
        }
        $pdo->commit();return true;
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        error_log('VIP Google: vinculação após senha não concluída ('.get_class($e).')');
        return false;
    }
}
