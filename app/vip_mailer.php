<?php
/** Envio SMTP TLS exclusivo para recuperação VIP; sem dependências ou credenciais no ZIP. */
declare(strict_types=1);

function vip_mail_settings(): ?array {
    $file=__DIR__.'/smtp.local.php';
    if (!is_file($file)) return null;
    $cfg=require $file;
    if (!is_array($cfg)) return null;
    $host=trim((string)($cfg['host']??''));
    $port=(int)($cfg['port']??0);
    $user=trim((string)($cfg['username']??''));
    $password=(string)($cfg['password']??'');
    $from=trim((string)($cfg['from_email']??$user));
    $name=trim((string)($cfg['from_name']??'Barbearia Martelli'));
    if ($host===''||!preg_match('/^[a-zA-Z0-9.-]+$/D',$host)||!in_array($port,[465,587],true)||
        !filter_var($user,FILTER_VALIDATE_EMAIL)||($password===''||str_contains($password,'COLOQUE_'))||
        !filter_var($from,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$name)) return null;
    return compact('host','port','user','password','from','name');
}
function vip_mail_expect($socket, array $expected): string {
    $last='';
    do {
        $line=fgets($socket,1024);
        if($line===false) throw new RuntimeException('SMTP_READ');
        $last=$line;
        if(!preg_match('/^([0-9]{3})([ -])/',$line,$m)) throw new RuntimeException('SMTP_PROTOCOL');
    } while($m[2]==='-');
    if(!in_array((int)$m[1],$expected,true)) throw new RuntimeException('SMTP_STATUS_'.$m[1]);
    return $last;
}
function vip_mail_write($socket, string $data): void {
    $length=strlen($data);$offset=0;
    while($offset<$length){
        $sent=fwrite($socket,substr($data,$offset));
        if($sent===false||$sent===0)throw new RuntimeException('SMTP_WRITE');
        $offset+=$sent;
    }
}
function vip_mail_command($socket, string $command, array $expected): void {
    vip_mail_write($socket,$command."\r\n");
    vip_mail_expect($socket,$expected);
}
function vip_mail_send(string $to,string $subject,string $body): bool {
    if(!filter_var($to,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$to)) return false;
    $cfg=vip_mail_settings();
    if($cfg===null){error_log('VIP email: SMTP não configurado.');return false;}
    $host=$cfg['host'];$port=$cfg['port'];
    $context=stream_context_create(['ssl'=>[
        'verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,
        'peer_name'=>$host,'SNI_enabled'=>true
    ]]);
    $socket=false;
    try {
        $transport=$port===465?'ssl://':'';
        $socket=@stream_socket_client($transport.$host.':'.$port,$errorNum,$errorStr,10,STREAM_CLIENT_CONNECT,$context);
        if($socket===false)throw new RuntimeException('SMTP_CONNECT');
        stream_set_timeout($socket,10);
        vip_mail_expect($socket,[220]);
        vip_mail_command($socket,'EHLO martellibarbearia.com',[250]);
        if($port===587){
            vip_mail_command($socket,'STARTTLS',[220]);
            if(@stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)!==true)
                throw new RuntimeException('SMTP_TLS');
            vip_mail_command($socket,'EHLO martellibarbearia.com',[250]);
        }
        vip_mail_command($socket,'AUTH LOGIN',[334]);
        vip_mail_command($socket,base64_encode($cfg['user']),[334]);
        vip_mail_command($socket,base64_encode($cfg['password']),[235]);
        vip_mail_command($socket,'MAIL FROM:<'.$cfg['from'].'>',[250]);
        vip_mail_command($socket,'RCPT TO:<'.$to.'>',[250,251]);
        vip_mail_command($socket,'DATA',[354]);
        $mime=function(string $value):string {return '=?UTF-8?B?'.base64_encode($value).'?=';};
        $fromName=$mime($cfg['name']);
        $headers=[
            'Date: '.gmdate('D, d M Y H:i:s').' +0000',
            'From: '.$fromName.' <'.$cfg['from'].'>',
            'To: <'.$to.'>',
            'Subject: '.$mime($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Auto-Response-Suppress: All',
        ];
        $encoded=rtrim(chunk_split(base64_encode($body),76,"\r\n"),"\r\n");
        $message=implode("\r\n",$headers)."\r\n\r\n".$encoded."\r\n.\r\n";
        vip_mail_write($socket,$message);
        vip_mail_expect($socket,[250]);
        vip_mail_command($socket,'QUIT',[221]);
        fclose($socket);
        return true;
    } catch(Throwable $e) {
        // Nunca registrar usuário, senha SMTP, token, endereço do destinatário ou corpo do e-mail.
        error_log('VIP email: falha SMTP ['.preg_replace('/[^A-Z0-9_]/','', $e->getMessage()).'].');
        if(is_resource($socket))fclose($socket);
        return false;
    }
}
