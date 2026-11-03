<?php
/** Diagnóstico seguro do login social; exclusivo da conta principal Martelli. */
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
if (!mt_is_owner()) {http_response_code(403);exit('Acesso restrito ao administrador principal.');}
require_once __DIR__.'/app/vip_google_oauth.php';
require_once __DIR__.'/app/vip_apple_oauth.php';
$checks=[];
$add=function(string $title,bool $success,string $detail)use(&$checks):void{
    $checks[]=compact('title','success','detail');
};
$add('HTTPS',!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','Use o site https://martellibarbearia.com.');
$add('Extensão PHP cURL',extension_loaded('curl'),'Necessária para comunicar com Google e Apple.');
$add('Extensão PHP OpenSSL',extension_loaded('openssl'),'Necessária para validar os tokens assinados.');
$add('Extensão PDO MySQL',extension_loaded('pdo_mysql'),'Necessária para o banco do Martelli.');
try{ vip_google_config();$add('Google: credenciais locais',true,'Arquivo e formato parecem corretos. Isso não confirma a validade da chave no Google Cloud.'); }
catch(Throwable $e){$add('Google: credenciais locais',false,'Revise app/google_oauth.local.php e a chave ativa do Google Cloud; não envie as credenciais no chat.');}
try{ vip_apple_config();$add('Apple: credenciais e chave .p8',true,'Arquivo e chave ES256 válidos localmente. Confirme Services ID e Return URL no Apple Developer.'); }
catch(Throwable $e){$add('Apple: credenciais e chave .p8',false,'Configure app/apple_oauth.local.php, Services ID, Team ID, Key ID e chave .p8 privada.');}
foreach([
 'mt_vip_customer_accounts'=>'VIP: contas de clientes (V24)',
 'mt_vip_google_accounts'=>'Google: vínculos (V38)',
 'mt_vip_google_signup_requests'=>'Google: solicitações (V39)',
 'mt_vip_apple_accounts'=>'Apple: vínculos (V40)',
 'mt_vip_apple_signup_requests'=>'Apple: solicitações (V40)',
] as $table=>$title){
 try{$st=db()->query("SHOW TABLES LIKE ".db()->quote($table));$ok=$st->fetchColumn()!==false;
   $add($title,$ok,$ok?'Tabela instalada.':'Importe a migração SQL correspondente no banco Martelli.');}
 catch(Throwable $e){$add($title,false,'Não foi possível consultar o banco; confira app/config.local.php e a conexão.');}
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Diagnóstico OAuth | Martelli VIP</title><style>
:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#100c0a;color:#f8e7d5;font:15px/1.55 system-ui,-apple-system,Segoe UI,Arial,sans-serif}.wrap{max-width:850px;margin:3rem auto;padding:0 1.25rem}h1{font:normal 2.1rem Georgia,serif}.eyebrow{color:#e1bc80;letter-spacing:.12em;font-size:.75rem;text-transform:uppercase}p{color:#d0bcaa}.check{border:1px solid #654b34;background:#221913;border-radius:10px;padding:1rem;margin:.7rem 0;display:flex;gap:1rem;align-items:flex-start}.status{font-weight:bold;min-width:5.4rem}.ok{color:#79d1a2}.bad{color:#f0a49c}.check small{display:block;color:#d5c0ae;margin-top:.25rem}a{color:#eac795}footer{margin-top:2rem;color:#cbb7a6}
</style></head><body><main class="wrap"><span class="eyebrow">Painel exclusivo do responsável</span><h1>Verificação do login Google e Apple</h1><p>Esta página verifica a instalação local. Não envia credenciais, não realiza login e não confirma a configuração externa do Google ou Apple Developer.</p>
<?php foreach($checks as $c): ?><div class="check"><span class="status <?=$c['success']?'ok':'bad'?>"><?=$c['success']?'✓ OK':'! Rever'?></span><div><strong><?=h($c['title'])?></strong><small><?=h($c['detail'])?></small></div></div><?php endforeach; ?>
<footer><p>Se algum item indicar “Rever”, corrija-o na Hostinger e recarregue a página. Não compartilhe Client Secret, chave .p8 ou arquivos *.local.php.</p><a href="/vip.php">← Voltar a Clientes VIP</a></footer></main></body></html>
