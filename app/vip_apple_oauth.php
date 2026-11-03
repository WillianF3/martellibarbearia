<?php
/** Sign in with Apple para clientes VIP, separado do login administrativo. */
declare(strict_types=1);
require_once __DIR__.'/vip_google_oauth.php'; // apenas helpers base64url e validação de assinatura Google existentes
function vip_apple_config(): array {
    $path=__DIR__.'/apple_oauth.local.php';
    if (!is_file($path)) throw new RuntimeException('APPLE_CONFIG');
    $c=require $path;
    if (!is_array($c)) throw new RuntimeException('APPLE_CONFIG');
    foreach (['client_id','team_id','key_id','private_key_path','redirect_uri'] as $k) {
        if (!is_string($c[$k]??null) || trim($c[$k])==='') throw new RuntimeException('APPLE_CONFIG');
    }
    if ($c['redirect_uri']!=='https://martellibarbearia.com/vip-apple.php' || !is_file($c['private_key_path']) || !is_readable($c['private_key_path'])) throw new RuntimeException('APPLE_CONFIG');
    if (!preg_match('/^[A-Za-z0-9.\-]{4,180}$/D',$c['client_id'])||!preg_match('/^[A-Z0-9]{10}$/D',$c['team_id'])||!preg_match('/^[A-Z0-9]{10}$/D',$c['key_id'])) throw new RuntimeException('APPLE_CONFIG');
    // Rejeita configuracao de exemplo e chaves invalidas antes do redirecionamento.
    if (preg_match('/SEU_|EXEMPLO|COLE_AQUI|YOUR_|PLACEHOLDER/i',$c['client_id'].' '.$c['team_id'].' '.$c['key_id'])) throw new RuntimeException('APPLE_CONFIG');
    if (!function_exists('openssl_pkey_get_private')) throw new RuntimeException('APPLE_CONFIG');
    $material=@file_get_contents($c['private_key_path']);
    $key=is_string($material)?@openssl_pkey_get_private($material):false;
    if ($key===false) throw new RuntimeException('APPLE_CONFIG');
    $details=openssl_pkey_get_details($key);
    if (!is_array($details) || ($details['type']??null)!==OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name']??'')!=='prime256v1') throw new RuntimeException('APPLE_CONFIG');
    return $c;
}
function vip_apple_ready(): bool {
    try{return db()->query("SHOW TABLES LIKE 'mt_vip_apple_accounts'")->fetchColumn()!==false;}
    catch(Throwable $e){return false;}
}
function vip_apple_signup_ready(): bool {
    try{return db()->query("SHOW TABLES LIKE 'mt_vip_apple_signup_requests'")->fetchColumn()!==false;}
    catch(Throwable $e){return false;}
}
function vip_apple_asn1_len(int $n): string {
    if($n<128)return chr($n);
    $s='';while($n>0){$s=chr($n&255).$s;$n>>=8;}return chr(0x80|strlen($s)).$s;
}
function vip_apple_asn1_int(string $bytes): string {
    $bytes=ltrim($bytes,"\0"); if($bytes==='')$bytes="\0";
    if(ord($bytes[0])&0x80)$bytes="\0".$bytes;
    return "\x02".vip_apple_asn1_len(strlen($bytes)).$bytes;
}
function vip_apple_jwk_pem(string $n,string $e): string {
    $mod=vip_google_decode($n);$ex=vip_google_decode($e);
    if($mod===false||$ex===false||strlen($mod)<200||strlen($ex)>8)throw new RuntimeException('APPLE_VERIFY');
    $rsa=vip_apple_asn1_int($mod).vip_apple_asn1_int($ex);
    $rsa="\x30".vip_apple_asn1_len(strlen($rsa)).$rsa;
    $oid="\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $bit="\x03".vip_apple_asn1_len(strlen($rsa)+1)."\0".$rsa;
    $key=$oid.$bit;$key="\x30".vip_apple_asn1_len(strlen($key)).$key;
    return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($key),64,"\n")."-----END PUBLIC KEY-----\n";
}
/** Apple exige ES256 (ECDSA). Converte DER do OpenSSL em assinatura JOSE de 64 bytes. */
function vip_apple_der_to_jose(string $der): string {
    if(strlen($der)<8 || $der[0]!=="\x30")throw new RuntimeException('APPLE_SIGN');
    $pos=1;$first=ord($der[$pos++]);
    if(($first&0x80)!==0){$l=$first&0x7f;if($l<1||$l>2)throw new RuntimeException('APPLE_SIGN');$pos+=$l;}
    $nums=[];
    for($i=0;$i<2;$i++){
        if(($der[$pos++]??'')!=="\x02")throw new RuntimeException('APPLE_SIGN');
        $len=ord($der[$pos++]??"\0");if($len<1||$len>34)throw new RuntimeException('APPLE_SIGN');
        $chunk=substr($der,$pos,$len);$pos+=$len;
        $chunk=ltrim($chunk,"\0"); if(strlen($chunk)>32)throw new RuntimeException('APPLE_SIGN');
        $nums[]=str_pad($chunk,32,"\0",STR_PAD_LEFT);
    }
    return implode('',$nums);
}
function vip_apple_client_secret(array $cfg): string {
    $now=time();$header=['alg'=>'ES256','kid'=>$cfg['key_id'],'typ'=>'JWT'];
    $payload=['iss'=>$cfg['team_id'],'iat'=>$now,'exp'=>$now+3600,'aud'=>'https://appleid.apple.com','sub'=>$cfg['client_id']];
    $a=vip_google_b64url(json_encode($header,JSON_UNESCAPED_SLASHES));
    $b=vip_google_b64url(json_encode($payload,JSON_UNESCAPED_SLASHES));
    $key=openssl_pkey_get_private(file_get_contents($cfg['private_key_path']));
    if($key===false || !openssl_sign($a.'.'.$b,$sig,$key,OPENSSL_ALGO_SHA256)) throw new RuntimeException('APPLE_SIGN');
    return $a.'.'.$b.'.'.vip_google_b64url(vip_apple_der_to_jose($sig));
}
function vip_apple_http(string $url,?array $data=null): string {
    if(!function_exists('curl_init')||parse_url($url,PHP_URL_SCHEME)!=='https'||parse_url($url,PHP_URL_HOST)!=='appleid.apple.com')throw new RuntimeException('APPLE_CONNECT');
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'Martelli-VIP-Apple-OIDC/1.0']);
    if(defined('CURLOPT_PROTOCOLS')&&defined('CURLPROTO_HTTPS'))curl_setopt($ch,CURLOPT_PROTOCOLS,CURLPROTO_HTTPS);
    if($data!==null){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($data,'','&',PHP_QUERY_RFC3986));curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type: application/x-www-form-urlencoded']);}
    $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if(!is_string($body)||$code!==200||strlen($body)>100000)throw new RuntimeException('APPLE_CONNECT');
    return $body;
}
function vip_apple_verify_token(string $jwt,string $aud,string $nonce): array {
    if(strlen($jwt)>12000)throw new RuntimeException('APPLE_VERIFY');
    $parts=explode('.',$jwt);if(count($parts)!==3)throw new RuntimeException('APPLE_VERIFY');
    [$a,$b,$c]=$parts;
    $head=json_decode(vip_google_decode($a)?:'',true);$claims=json_decode(vip_google_decode($b)?:'',true);$sig=vip_google_decode($c);
    if(!is_array($head)||!is_array($claims)||$sig===false||($head['alg']??null)!=='RS256'||!is_string($head['kid']??null))throw new RuntimeException('APPLE_VERIFY');
    $keys=json_decode(vip_apple_http('https://appleid.apple.com/auth/keys'),true);
    $jwk=null;
    foreach(($keys['keys']??[]) as $k){if(($k['kid']??null)===$head['kid']&&($k['kty']??null)==='RSA'&&($k['alg']??'RS256')==='RS256'){$jwk=$k;break;}}
    if(!$jwk||!is_string($jwk['n']??null)||!is_string($jwk['e']??null))throw new RuntimeException('APPLE_VERIFY');
    $key=openssl_pkey_get_public(vip_apple_jwk_pem($jwk['n'],$jwk['e']));
    if($key===false||openssl_verify($a.'.'.$b,$sig,$key,OPENSSL_ALGO_SHA256)!==1)throw new RuntimeException('APPLE_VERIFY');
    $now=time();
    if(($claims['iss']??null)!=='https://appleid.apple.com'||($claims['aud']??null)!==$aud
      ||!is_numeric($claims['exp']??null)||(int)$claims['exp']<=$now
      ||!is_numeric($claims['iat']??null)||(int)$claims['iat']>$now+120
      ||!is_string($claims['nonce']??null)||!hash_equals($nonce,$claims['nonce']))throw new RuntimeException('APPLE_VERIFY');
    $sub=$claims['sub']??null;$email=$claims['email']??null;
    if(!is_string($sub)||strlen($sub)<4||strlen($sub)>255||!is_string($email)||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('APPLE_VERIFY');
    if(!in_array($claims['email_verified']??null,[true,'true','1',1],true))throw new RuntimeException('APPLE_VERIFY');
    return ['sub'=>$sub,'email'=>strtolower($email),'name'=>''];
}
