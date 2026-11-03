<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require_admin();
mt_require_owner();
require __DIR__.'/app/layout.php';
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
$results=[];$error='';$cpf='';$phone='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
 check_csrf();
 $cpf=preg_replace('/\D+/','',(string)($_POST['cpf']??''))??'';
 $phone=normalize_br_phone((string)($_POST['phone']??''));
 if(strlen($cpf)!==11||$phone===''){
  $error='Informe CPF (11 números) e WhatsApp com DDD válidos.';
 }else{
  try{
   $query=db()->prepare('SELECT id,name,phone,cpf,created_at FROM mt_clients WHERE cpf=? OR phone=? ORDER BY id LIMIT 10');
   $query->execute([$cpf,$phone]);
   $results=$query->fetchAll();
   foreach($results as &$row){
    $id=(int)$row['id'];
    foreach(['agendamentos'=>'mt_appointments','assinaturas'=>'mt_vip_memberships','comandas'=>'mt_orders','conta_vip'=>'mt_vip_customer_accounts','google'=>'mt_vip_google_accounts','apple'=>'mt_vip_apple_accounts'] as $key=>$table){
     $q=db()->prepare("SELECT COUNT(*) FROM `$table` WHERE client_id=?");$q->execute([$id]);$row[$key]=(int)$q->fetchColumn();
    }
    $q=db()->prepare('SELECT google_email FROM mt_vip_google_accounts WHERE client_id=? LIMIT 1');
    $q->execute([$id]);$row['google_email']=(string)($q->fetchColumn()?:'');
    $q=db()->prepare('SELECT client_email FROM mt_vip_requests WHERE client_id=? AND client_email IS NOT NULL ORDER BY id DESC LIMIT 1');
    $q->execute([$id]);$row['request_email']=(string)($q->fetchColumn()?:'');
   }unset($row);
  }catch(Throwable $e){error_log('V49 owner identity preview: '.get_class($e));$error='Não foi possível verificar os vínculos. Confirme as migrações VIP.';}
 }
}
page_head('Conferir cadastros | Martelli');
?>
<main id="conteudo" style="max-width:1000px;margin:3rem auto;padding:1.5rem;color:#f8e8d5">
 <a href="/vip.php" style="color:#e7c794">← Voltar para o VIP</a>
 <p style="color:#e7c794;font-size:.8rem;letter-spacing:.15em;margin-top:2rem">ADMINISTRADOR PRINCIPAL · IDENTIDADE</p>
 <h1>Conferir cadastros com CPF e WhatsApp</h1>
 <p>Esta ferramenta apenas consulta registros. Nunca vincula ou apaga clientes automaticamente. Confirme a identidade diretamente com a pessoa antes de qualquer conciliação.</p>
 <form method="post" style="border:1px solid #81623e;background:#211813;padding:1.4rem;border-radius:12px;display:grid;gap:1rem">
  <input type="hidden" name="csrf" value="<?=h(csrf())?>">
  <label>CPF informado<input name="cpf" required minlength="11" maxlength="14" inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" value="<?=h($cpf)?>" style="display:block;width:100%;padding:.8rem;margin-top:.4rem"></label>
  <label>WhatsApp informado<input name="phone" required maxlength="22" inputmode="tel" autocomplete="off" placeholder="(49) 99999-9999" value="<?=h($phone)?>" style="display:block;width:100%;padding:.8rem;margin-top:.4rem"></label>
  <button type="submit" style="padding:1rem;background:#e7c794;color:#271a10;border:0;border-radius:6px;font-weight:bold">Conferir cadastros e vínculos</button>
 </form>
 <?php if($error!==''):?><p role="alert" style="color:#ffa6a6"><?=h($error)?></p><?php endif;?>
 <?php if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&$error===''):?>
 <h2>Resultado da conferência</h2>
 <?php if(!$results):?><p>Nenhum cadastro encontrado para os dados informados.</p><?php else:?>
 <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse"><thead><tr><th>ID</th><th>Nome</th><th>WhatsApp</th><th>CPF</th><th>Agendamentos</th><th>VIP</th><th>Comandas</th><th>Logins</th><th>E-mail vinculado</th></tr></thead><tbody>
 <?php foreach($results as $r):?><tr style="border-top:1px solid #81623e"><td><?=h($r['id'])?></td><td><?=h($r['name'])?></td><td><?=h($r['phone'])?></td><td><?=h($r['cpf']??'Pendente')?></td><td><?=h($r['agendamentos'])?></td><td><?=h($r['assinaturas'])?></td><td><?=h($r['comandas'])?></td><td><?=h($r['conta_vip']+$r['google']+$r['apple'])?></td><td><?=h($r['google_email']?:$r['request_email']?:'—')?></td></tr><?php endforeach;?></tbody></table></div>
 <?php endif; ?>
 <p style="padding:1rem;border:1px solid #81623e;background:#302018">Se houver dois cadastros da mesma pessoa, confira os dois IDs e os vínculos. A conciliação deve ser executada somente após backup e confirmação da identidade pelo responsável principal. Consulte o arquivo <code>sql/LEIA-ME-V49.md</code> do pacote.</p>
 <?php endif;?>
</main>
<?php page_end(); ?>
