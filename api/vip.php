<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/management.php';
require __DIR__.'/../app/vip_schedule.php';
require __DIR__.'/../app/cpf.php';
require_once __DIR__.'/../app/vip_auth.php';
header('X-Content-Type-Options: nosniff');
$action=(string)($_GET['action']??'');
$method=$_SERVER['REQUEST_METHOD']??'GET';
/** Verifica estrutura básica de assinaturas VIP. */
function vip_ready():bool {
 try {
  $r=db()->query("SHOW TABLES LIKE 'mt_vip_plans'")->fetchColumn();
  $m=db()->query("SHOW TABLES LIKE 'mt_vip_memberships'")->fetchColumn();
  $u=db()->query("SHOW TABLES LIKE 'mt_vip_uses'")->fetchColumn();
  return $r!==false&&$m!==false&&$u!==false;
 } catch(Throwable $e){return false;}
}
/** Confere existência da tabela de solicitações públicas. */
function vip_requests_ready(): bool {
 try {return db()->query("SHOW TABLES LIKE 'mt_vip_requests'")->fetchColumn()!==false;}
 catch(Throwable $e){return false;}
}
/** Bloqueia cadastro caso a migração necessária não exista. */
function vip_require_request_schema(): void {
 if(!vip_ready()||!vip_requests_ready())fail('O cadastro VIP ainda não foi ativado. A equipe deve instalar a migração V22.',503);
}
/** Normaliza WhatsApp e rejeita contatos inválidos. */
function vip_phone(mixed $value):string {
 $phone=normalize_br_phone((string)$value);
 if($phone==='')fail('Informe um WhatsApp válido com DDD.');
 return $phone;
}
/** Valida nome completo preservando caracteres Unicode. */
function vip_client_name(mixed $value):string {
 $name=vip_name($value,120);
 if((function_exists('mb_strlen')?mb_strlen($name):strlen($name))<3)fail('Informe seu nome completo.');
 if(!preg_match('/^[\p{L}][\p{L}\p{M} \'’.-]+$/u',$name))fail('Informe um nome válido.');
 return $name;
}
/** Converte e valida identificadores públicos positivos. */
function vip_id(mixed $v):int {
 if(!is_scalar($v)||!preg_match('/^[1-9][0-9]{0,17}$/D',(string)$v))fail('Identificador inválido.');
 return (int)$v;
}
/** Valida datas no formato ISO sem correção silenciosa. */
function vip_date(mixed $v):string {
 $d=(string)$v;
 if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$d))fail('Data inválida.');
 $x=DateTimeImmutable::createFromFormat('!Y-m-d',$d);
 if(!$x||$x->format('Y-m-d')!==$d)fail('Data inválida.');
 return $d;
}
/** Vigência de 30 dias corridos contando a data de início (dia 1). */
function vip_end(string $start): string {
    return \Martelli\Domain\VipCycle::lastDay($start);
}
/** Normaliza e limita textos enviados à API. */
function vip_name(mixed $v,int $max=100):string {
 $v=trim((string)$v);
 $len=function_exists('mb_strlen')?mb_strlen($v,'UTF-8'):strlen($v);
 if($len>$max)fail('Texto muito longo.');
 return $v;
}
/** Executa operações SQL com parâmetros em consulta preparada. */
function vip_query(string $sql,array $params=[]):PDOStatement {
 $s=db()->prepare($sql);$s->execute($params);return $s;
}
/** Exige permissão administrativa específica para módulo VIP. */
function vip_require():void { require_admin(); mt_require_any(['cadastros','financeiro']); }
/** Registra operação VIP e ator nos logs privados do servidor. */
function vip_audit(string $event,int $id):void {
 error_log('Martelli VIP '. $event.' #'.$id.' actor:'.(int)($_SESSION['admin_id']??0));
}
/** Busca somente cópias EXATAS de assinaturas canceladas, sem vínculos/histórico.
 * Não altera renovações legítimas, períodos diferentes, reservas ou cortes registrados.
 */
function vip_safe_duplicate_ids():array {
 if(!vip_requests_ready()||!vip_schedule_ready())return [];
 $sql="SELECT m.id FROM mt_vip_memberships m
 WHERE m.status='cancelled'
 AND EXISTS(SELECT 1 FROM mt_vip_memberships earlier
  WHERE earlier.id<m.id AND earlier.client_id=m.client_id AND earlier.plan_id=m.plan_id
   AND earlier.starts_on=m.starts_on AND earlier.ends_on=m.ends_on
   AND earlier.monthly_price_cents=m.monthly_price_cents
   AND earlier.included_cuts=m.included_cuts AND earlier.status=m.status)
 AND NOT EXISTS(SELECT 1 FROM mt_vip_uses u WHERE u.membership_id=m.id)
 AND NOT EXISTS(SELECT 1 FROM mt_vip_appointments a WHERE a.membership_id=m.id)
 AND NOT EXISTS(SELECT 1 FROM mt_vip_access_links l WHERE l.membership_id=m.id)
 AND NOT EXISTS(SELECT 1 FROM mt_vip_requests r WHERE r.membership_id=m.id)
 ORDER BY m.id ASC LIMIT 250";
 return array_map('intval',vip_query($sql)->fetchAll(PDO::FETCH_COLUMN));
}
/** Checagem de senha usada somente por ações destrutivas do proprietário principal. */
function vip_owner_confirmation(string $password):void {
 mt_require_owner();
 $attempts=array_values(array_filter((array)($_SESSION['vip_cleanup_attempts']??[]),static fn($at)=>(int)$at>time()-900));
 if(count($attempts)>=5)fail('Muitas tentativas. Tente novamente mais tarde.',429);
 $attempts[]=time();$_SESSION['vip_cleanup_attempts']=$attempts;
 if($password===''||strlen($password)>256)fail('Confirme sua senha de administrador.',403);
 $stored=vip_query('SELECT password_hash FROM mt_admins WHERE id=? LIMIT 1',[(int)($_SESSION['admin_id']??0)])->fetchColumn();
 if(!is_string($stored)||!password_verify($password,$stored))fail('Senha administrativa incorreta.',403);
 unset($_SESSION['vip_cleanup_attempts']);
}
/** Bloqueia cadastro na transação para evitar condições de corrida. */
function vip_lock_client(int $id):void {
 $q=vip_query('SELECT id FROM mt_clients WHERE id=? FOR UPDATE',[$id]);
 if(!$q->fetchColumn())fail('Cliente não encontrado.',404);
}
try {
 if(!in_array($method,['GET','POST'],true))fail('Método não permitido.',405);
 if($action==='public_plans'&&$method==='GET') {
  if(!vip_ready())json_out(['ok'=>true,'plans'=>[]]);
  $rows=vip_query('SELECT id,name,description,monthly_price_cents,cuts_per_month,service_name FROM mt_vip_plans WHERE active=1 ORDER BY monthly_price_cents,id LIMIT 12')->fetchAll();
  json_out(['ok'=>true,'plans'=>$rows]);
 }
 if($action==='request_create'&&$method==='POST') {
  check_csrf();
  vip_require_request_schema();
  $d=input();
  // Campo oculto antispam; não revela detalhes de terceiros.
  if(trim((string)($d['website']??''))!=='')json_out(['ok'=>true]);
  if((string)($d['consent']??'')!=='1')fail('Confirme a política de privacidade e os termos.');
  $name=vip_client_name($d['name']??'');
  $phone=vip_phone($d['phone']??'');
  mt_cpf_schema();$cpf=mt_cpf((string)($d['cpf']??''));
  $email=trim((string)($d['email']??''));
  // V51: apenas cadastro por formulário com senha, sem novos vínculos OAuth no pré-cadastro.
  // A autenticação Google/Apple de contas que já possuem vínculo continua disponível no login.
  if(!is_string($d['password']??null) || !is_string($d['password_confirm']??null))fail('Crie uma senha e confirme-a.',422);
  $password=$d['password'];
  $passwordConfirm=$d['password_confirm'];
  vip_auth_password($password);
  if(trim($password)==='')fail('Escolha uma senha válida.',422);
  if(!hash_equals($password,$passwordConfirm))fail('As senhas informadas não conferem.',422);
  try {
    $passwordColumn=db()->query("SHOW COLUMNS FROM mt_vip_requests LIKE 'registration_password_hash'")->fetchColumn();
  } catch (Throwable $e) {$passwordColumn=false;}
  if(!$passwordColumn)fail('A barbearia precisa instalar a atualização SQL V51 antes de receber cadastros com senha.',503);
  // Hash gerado no servidor; senha nunca será incluída nos dados administrativos da solicitação.
  $registrationHash=password_hash($password,PASSWORD_DEFAULT);
  unset($password,$passwordConfirm);
  if(strlen($email)>190||($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)))fail('E-mail inválido.');
  $notes=vip_name($d['notes']??'',300);
  $plan=vip_id($d['plan_id']??'');
  $chosen=vip_query('SELECT id,name,monthly_price_cents,cuts_per_month,service_name FROM mt_vip_plans WHERE id=? AND active=1',[$plan])->fetch();
  if(!$chosen)fail('Plano não disponível. Escolha outro plano.',409);
  // Locks determinísticos por CPF e WhatsApp impedem solicitações simultâneas
  // com o mesmo CPF em telefones diferentes, ou mesmo telefone com outro CPF.
  if((int)($_SESSION['vip_signup_last']??0)>time()-90)fail('Aguarde um momento antes de enviar outra solicitação.',429);
  $mutexes=['vip_cpf_'.substr(hash('sha256',$cpf),0,48),'vip_phone_'.substr(hash('sha256',$phone),0,48)];
  sort($mutexes,SORT_STRING);$held=[];
  foreach($mutexes as $mutex){
   if((int)vip_query('SELECT GET_LOCK(?,5)',[$mutex])->fetchColumn()!==1){
    foreach(array_reverse($held) as $lock)vip_query('SELECT RELEASE_LOCK(?)',[$lock]);
    fail('Cadastro ocupado. Tente novamente em instantes.',503);
   }
   $held[]=$mutex;
  }
  $pdo=db();
  try {
   $pdo->beginTransaction();
   // A CPF belonging to another contact requires manual identity confirmation.
   $cpfClient=vip_query('SELECT id,phone FROM mt_clients WHERE cpf=? FOR UPDATE',[$cpf])->fetch();
   if($cpfClient && $cpfClient['phone']!==$phone)throw new RuntimeException('Já existe uma conta associada aos dados informados. Entre com sua conta existente ou solicite a atualização do WhatsApp à barbearia.');
   $client=vip_query('SELECT id,cpf FROM mt_clients WHERE phone=? FOR UPDATE',[$phone])->fetch();
   if($client && $client['cpf']!==null && $client['cpf']!==$cpf)throw new RuntimeException('Já existe uma conta associada aos dados informados. Entre com sua conta existente ou solicite a verificação do cadastro à barbearia.');
   if($client){
    $accountReady=vip_query("SHOW TABLES LIKE 'mt_vip_customer_accounts'")->fetchColumn()!==false;
    if($accountReady){$hasAccount=vip_query('SELECT password_hash FROM mt_vip_customer_accounts WHERE client_id=?',[(int)$client['id']])->fetchColumn();
      if(is_string($hasAccount)&&$hasAccount!=='')throw new RuntimeException('Já existe um login para este cliente. Entre com a senha cadastrada ou solicite recuperação de acesso.');}
    $member=vip_query('SELECT id FROM mt_vip_memberships WHERE client_id=? LIMIT 1',[(int)$client['id']])->fetchColumn();
    if($member)throw new RuntimeException('Este WhatsApp já possui cadastro VIP. Utilize o acesso VIP ou entre em contato com a barbearia para renovação.');
   }
   $already=vip_query("SELECT status FROM mt_vip_requests WHERE (client_phone=? OR cpf=?) AND status IN ('pending','approved') ORDER BY id DESC LIMIT 1",[$phone,$cpf])->fetchColumn();
   if($already)throw new RuntimeException($already==='pending'?'Já existe uma solicitação VIP para este WhatsApp. Aguarde a avaliação da equipe.':'Este WhatsApp já possui cadastro VIP. Use o acesso de assinante.');
   $recent=(int)vip_query('SELECT COUNT(*) FROM mt_vip_requests WHERE client_phone=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)',[$phone])->fetchColumn();
   if($recent>=3)throw new RuntimeException('Limite diário de solicitações atingido. Entre em contato com a barbearia.');
   vip_query('INSERT INTO mt_vip_requests(client_name,client_phone,client_email,plan_id,plan_name,monthly_price_cents,included_cuts,service_name,notes,cpf,registration_password_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$name,$phone,$email!==''?$email:null,$plan,$chosen['name'],(int)$chosen['monthly_price_cents'],(int)$chosen['cuts_per_month'],$chosen['service_name'],$notes,$cpf,$registrationHash]);
   $pdo->commit();
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  finally {foreach(array_reverse($held) as $lock)vip_query('SELECT RELEASE_LOCK(?)',[$lock]);}
  $_SESSION['vip_signup_last']=time();
  json_out(['ok'=>true,'message'=>'Solicitação recebida. Sua senha será habilitada após aprovação da assinatura.']);
 }
 vip_require();
 if($method==='POST')check_csrf();
 if($action==='ready'&&$method==='GET')json_out(['ok'=>true,'ready'=>vip_ready()]);
 if(!vip_ready())fail('Instale o arquivo sql/vip_v21.sql no phpMyAdmin para ativar o Martelli VIP.',409);
 if($method==='GET') {
  if($action==='list') {
   $plans=vip_query('SELECT * FROM mt_vip_plans ORDER BY active DESC,monthly_price_cents,id')->fetchAll();
   $clients=vip_query('SELECT id,name,phone FROM mt_clients ORDER BY name,id LIMIT 3000')->fetchAll();
   $vipExtra=vip_schedule_ready()
     ? "COALESCE(u.used,0)+COALESCE(v.done,0) used_cuts,COALESCE(v.reserved,0) reserved_cuts,"
     : "COALESCE(u.used,0) used_cuts,0 reserved_cuts,";
   $vipJoin=vip_schedule_ready()
     ? "LEFT JOIN (SELECT va.membership_id,SUM(CASE WHEN a.status='completed' THEN 1 ELSE 0 END) done,SUM(CASE WHEN a.status IN ('confirmed','pending') THEN 1 ELSE 0 END) reserved FROM mt_vip_appointments va JOIN mt_appointments a ON a.id=va.appointment_id GROUP BY va.membership_id) v ON v.membership_id=m.id"
     : "";
   $members=vip_query("SELECT m.*,c.name client_name,c.phone client_phone,p.name plan_name,m.included_cuts cuts_per_month,m.monthly_price_cents,{$vipExtra} m.id AS membership_key FROM mt_vip_memberships m JOIN mt_clients c ON c.id=m.client_id JOIN mt_vip_plans p ON p.id=m.plan_id LEFT JOIN (SELECT membership_id,COUNT(*) used FROM mt_vip_uses GROUP BY membership_id) u ON u.membership_id=m.id {$vipJoin} ORDER BY m.id DESC LIMIT 500")->fetchAll();
   $hasGoogleSignup=(bool)db()->query("SHOW TABLES LIKE 'mt_vip_google_signup_requests'")->fetchColumn();
   $hasAppleSignup=(bool)db()->query("SHOW TABLES LIKE 'mt_vip_apple_signup_requests'")->fetchColumn();
   $appleFlag=$hasAppleSignup ? ",EXISTS(SELECT 1 FROM mt_vip_apple_signup_requests ar WHERE ar.request_id=r.id) AS apple_signup" : ",0 AS apple_signup";
   $googleFlag=$hasGoogleSignup ? ",EXISTS(SELECT 1 FROM mt_vip_google_signup_requests gr WHERE gr.request_id=r.id) AS google_signup" : ",0 AS google_signup";
   $requests=vip_requests_ready()?vip_query("SELECT r.id,r.client_name,r.client_phone,r.client_email,r.plan_id,r.plan_name,r.monthly_price_cents,r.included_cuts,r.notes,r.status,r.created_at,r.reviewed_at,r.membership_id {$googleFlag} {$appleFlag} FROM mt_vip_requests r ORDER BY (r.status='pending') DESC,r.created_at DESC,r.id DESC LIMIT 300")->fetchAll():[];
   json_out(['ok'=>true,'plans'=>$plans,'clients'=>$clients,'memberships'=>$members,'requests'=>$requests,'requests_ready'=>vip_requests_ready(),'agenda_ready'=>vip_schedule_ready(),'barbers'=>vip_query('SELECT id,name FROM mt_barbers WHERE active=1 ORDER BY sort_order,id')->fetchAll(),'owner'=>mt_is_owner()]);
  }
  if($action==='member_delete_preview') {
  mt_require_owner();
  $id=vip_id($_GET['id']??'');
  $member=vip_query('SELECT id,client_id FROM mt_vip_memberships WHERE id=?',[$id])->fetch();
  if(!$member)fail('Assinatura não encontrada.',404);
  $clientId=(int)$member['client_id'];
  $counts=vip_query("SELECT COUNT(*) total, SUM(CASE WHEN status<>'cancelled' THEN 1 ELSE 0 END) not_cancelled FROM mt_vip_memberships WHERE client_id=?",[$clientId])->fetch();
  $used=(int)vip_query('SELECT COUNT(*) FROM mt_vip_uses u JOIN mt_vip_memberships m ON m.id=u.membership_id WHERE m.client_id=?',[$clientId])->fetchColumn();
  $bookings=vip_schedule_ready()?(int)vip_query('SELECT COUNT(*) FROM mt_vip_appointments a JOIN mt_vip_memberships m ON m.id=a.membership_id WHERE m.client_id=?',[$clientId])->fetchColumn():0;
  $canDelete=(int)$counts['not_cancelled']===0&&$used===0&&$bookings===0&&vip_schedule_ready();
  json_out(['ok'=>true,'can_delete'=>$canDelete,'memberships'=>(int)$counts['total'],'cuts'=>$used,'bookings'=>$bookings,'not_cancelled'=>(int)$counts['not_cancelled']]);
 }
 if($action==='duplicates_preview') {
   mt_require_owner();
   if(!vip_requests_ready()||!vip_schedule_ready())fail('Instale as migrações VIP V22 e V23 para executar a verificação segura.',409);
   $ids=vip_safe_duplicate_ids();
   json_out(['ok'=>true,'count'=>count($ids),'ids'=>$ids]);
  }

 if($action==='admin_slots' && $method==='GET') {
  vip_schedule_require_schema();
  $id=vip_id($_GET['membership_id']??'');
  $day=vip_date($_GET['date']??'');
  $barber=vip_id($_GET['barber_id']??'');
  $m=vip_query('SELECT * FROM mt_vip_memberships WHERE id=?',[$id])->fetch();
  if(!$m)fail('Assinatura não encontrada.',404);
  vip_schedule_check_membership_date($m,$day);
  $counts=vip_schedule_counts($id);
  if($counts['total_committed'] >= (int)$m['included_cuts'])json_out(['ok'=>true,'slots'=>[], 'message'=>'Este plano já teve todos os atendimentos utilizados ou reservados.']);
  $services=vip_schedule_services((string)$m['service_name']);
  if(!$services)fail('Serviço contratado inativo ou não cadastrado.',409);
  json_out(['ok'=>true,'slots'=>vip_schedule_availability($day,$barber,(int)$services[0]['id'])]);
 }
  if($action==='uses') {
   $id=vip_id($_GET['membership_id']??'');
   $rows=vip_query('SELECT used_on,notes,created_at FROM mt_vip_uses WHERE membership_id=? ORDER BY id DESC LIMIT 100',[$id])->fetchAll();
   json_out(['ok'=>true,'uses'=>$rows]);
  }
  fail('Consulta inválida.',404);
 }
 $d=input();
 if($action==='request_delete') {
  mt_require_owner();vip_require_request_schema();
  $id=vip_id($d['id']??'');
  if(($d['confirmation']??'')!=='EXCLUIR')fail('Digite EXCLUIR para confirmar.',422);
  vip_owner_confirmation((string)($d['admin_password']??''));
  $pdo=db();$pdo->beginTransaction();
  try {
   $request=vip_query('SELECT id,status,membership_id FROM mt_vip_requests WHERE id=? FOR UPDATE',[$id])->fetch();
   if(!$request)fail('Solicitação não encontrada.',404);
   // Exclui somente a solicitação selecionada, preservando cliente e assinatura aprovada.
   vip_query('DELETE FROM mt_vip_requests WHERE id=?',[$id]);
   $pdo->commit();
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  vip_audit('request_deleted',$id);
  json_out(['ok'=>true,'message'=>'Solicitação excluída. Clientes, assinaturas e agendamentos foram preservados.']);
 }
 if($action==='member_delete') {
  mt_require_owner();
  $id=vip_id($d['id']??'');
  if(($d['confirmation']??'')!=='EXCLUIR')fail('Digite EXCLUIR para confirmar.',422);
  vip_owner_confirmation((string)($d['admin_password']??''));
  if(!vip_schedule_ready())fail('Instale a migração V23 antes de excluir um assinante.',409);
  $pdo=db();$pdo->beginTransaction();
  try {
   $member=vip_query('SELECT client_id FROM mt_vip_memberships WHERE id=?',[$id])->fetch();
   if(!$member)fail('Assinante não encontrado.',404);
   $clientId=(int)$member['client_id'];
   vip_lock_client($clientId);
   $items=vip_query('SELECT id,status FROM mt_vip_memberships WHERE client_id=? ORDER BY id FOR UPDATE',[$clientId])->fetchAll();
   if(!$items)fail('Assinaturas não encontradas.',404);
   foreach($items as $m){if($m['status']!=='cancelled')fail('Cancele todas as assinaturas do cliente antes de excluir o cadastro VIP.',409);}
   $cuts=(int)vip_query('SELECT COUNT(*) FROM mt_vip_uses u JOIN mt_vip_memberships m ON m.id=u.membership_id WHERE m.client_id=?',[$clientId])->fetchColumn();
   $bookings=(int)vip_query('SELECT COUNT(*) FROM mt_vip_appointments v JOIN mt_vip_memberships m ON m.id=v.membership_id WHERE m.client_id=?',[$clientId])->fetchColumn();
   if($cuts||$bookings)fail('Este cliente tem cortes ou agendamentos vinculados. Para preservar o histórico, não é possível excluir o cadastro VIP.',409);
   // Solicitações aprovadas geradas por estas assinaturas também são removidas:
   // deixar um pedido 'approved' órfão impediria o cliente de voltar a se cadastrar.
   if(vip_requests_ready())vip_query("DELETE FROM mt_vip_requests WHERE client_id=? AND status='approved'",[$clientId]);
   vip_query('DELETE l FROM mt_vip_access_links l JOIN mt_vip_memberships m ON m.id=l.membership_id WHERE m.client_id=?',[$clientId]);
   vip_query("DELETE FROM mt_vip_memberships WHERE client_id=? AND status='cancelled'",[$clientId]);
   // A conta VIP não deve continuar autenticando após remoção da última assinatura.
   $authReady=(bool)vip_query("SHOW TABLES LIKE 'mt_vip_customer_accounts'")->fetchColumn();
   if($authReady)vip_query('DELETE FROM mt_vip_customer_accounts WHERE client_id=?',[$clientId]);
   $pdo->commit();
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  vip_audit('member_deleted',$id);
  json_out(['ok'=>true,'message'=>'Assinaturas canceladas, login VIP e pedidos aprovados vinculados foram excluídos. O cadastro geral do cliente foi preservado.']);
 }
 if($action==='duplicates_cleanup') {
  mt_require_owner();
  if(!vip_requests_ready()||!vip_schedule_ready())fail('Instale as migrações VIP V22 e V23 antes da limpeza.',409);
  if(($d['confirmation']??'')!=='EXCLUIR')fail('Digite EXCLUIR para confirmar a limpeza.',422);
  vip_owner_confirmation((string)($d['admin_password']??''));
  $pdo=db();$pdo->beginTransaction();
  try {
   // Trava as assinaturas durante a auditoria e a remoção, evitando alterações simultâneas.
   vip_query("SELECT id FROM mt_vip_memberships WHERE status='cancelled' FOR UPDATE")->fetchAll();
   $ids=vip_safe_duplicate_ids();
   foreach($ids as $candidate)vip_query("DELETE FROM mt_vip_memberships WHERE id=? AND status='cancelled'",[$candidate]);
   $pdo->commit();
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  error_log('Martelli VIP safe_duplicate_cleanup ids='.implode(',', $ids).' actor='.(int)($_SESSION['admin_id']??0));
  json_out(['ok'=>true,'deleted'=>count($ids),'message'=>count($ids)?'Cópias sem vínculo removidas.':'Nenhuma cópia segura para remover. Assinaturas históricas e registros vinculados foram preservados.']);
 }
 if($action==='request_decide') {
  vip_require_request_schema();
  $id=vip_id($d['id']??'');
  $decision=(string)($d['decision']??'');
  if(!in_array($decision,['approve','reject'],true))fail('Decisão inválida.');
  $start=$decision==='approve'?vip_date($d['starts_on']??''):null;
  if($decision==='approve'&&($d['confirm_contract']??'')!=='yes')fail('Confirme que a contratação e as condições foram verificadas com o cliente.');
  if($start!==null&&($start<date('Y-m-d',strtotime('-30 days'))||$start>date('Y-m-d',strtotime('+90 days'))))fail('Data inicial fora do período permitido.');
  $pdo=db();$pdo->beginTransaction();
  try{
   $req=vip_query('SELECT * FROM mt_vip_requests WHERE id=? FOR UPDATE',[$id])->fetch();
   if(!$req)throw new RuntimeException('Solicitação não encontrada.');
   if($req['status']!=='pending')throw new RuntimeException('Esta solicitação já foi analisada. Atualize a lista.');
   $clientId=null;$membershipId=null;
   $googleRequest=null;
   $hasGoogleSignup=(bool)db()->query("SHOW TABLES LIKE 'mt_vip_google_signup_requests'")->fetchColumn();
   if($hasGoogleSignup){
     $googleRequest=vip_query('SELECT google_sub,google_email FROM mt_vip_google_signup_requests WHERE request_id=? FOR UPDATE',[$id])->fetch() ?: null;
   }
   $appleRequest=null;
   if((bool)db()->query("SHOW TABLES LIKE 'mt_vip_apple_signup_requests'")->fetchColumn()){
     $appleRequest=vip_query('SELECT apple_sub,apple_email FROM mt_vip_apple_signup_requests WHERE request_id=? FOR UPDATE',[$id])->fetch() ?: null;
   }
   if($decision==='approve' && $appleRequest){
     mt_require_owner();
     if(($d['confirm_apple_phone']??'')!=='yes')fail('Confirme com o cliente a titularidade do WhatsApp antes de vincular sua conta Apple.',422);
   }
   if($decision==='approve' && $googleRequest){
     mt_require_owner();
     if(($d['confirm_google_phone']??'')!=='yes')fail('Confirme com o cliente a titularidade do WhatsApp antes de vincular seu Google.',422);
   }
   if($decision==='approve'){
    $active=vip_query('SELECT id FROM mt_vip_plans WHERE id=? AND active=1',[(int)$req['plan_id']])->fetchColumn();
    if(!$active)throw new RuntimeException('O plano foi desativado. Reative-o ou recuse a solicitação.');
    $phone=$req['client_phone'];
    $cpf=(string)($req['cpf']??'');
    if($cpf!==''){mt_cpf_schema();$existingCpf=vip_query('SELECT id,phone FROM mt_clients WHERE cpf=? FOR UPDATE',[$cpf])->fetch();
      if($existingCpf && $existingCpf['phone']!==$phone)throw new RuntimeException('CPF já possui cadastro com outro telefone. Confira a identidade antes de aprovar.');}
    $client=vip_query('SELECT id,cpf FROM mt_clients WHERE phone=? FOR UPDATE',[$phone])->fetch();
    if($client){$clientId=(int)$client['id'];vip_lock_client($clientId);
      if($cpf!=='' && $client['cpf']!==null && $client['cpf']!==$cpf)throw new RuntimeException('CPF não coincide com o cadastro do WhatsApp.');
      if($cpf!=='' && $client['cpf']===null)vip_query('UPDATE mt_clients SET cpf=? WHERE id=?',[$cpf,$clientId]);
    }
    else{
     vip_query('INSERT INTO mt_clients(name,phone,cpf) VALUES (?,?,?)',[$req['client_name'],$phone,$cpf!==''?$cpf:null]);
     $clientId=(int)$pdo->lastInsertId();
    }
    $end=vip_end($start);
    $alreadyVip=vip_query("SELECT id FROM mt_vip_memberships WHERE client_id=? LIMIT 1",[$clientId])->fetchColumn();
    if($alreadyVip)throw new RuntimeException('Este cliente já possui cadastro VIP. Utilize a renovação no administrativo, sem aprovar outro pré-cadastro.');
    // Mantém preço e quantidade da data da solicitação, mesmo que o plano tenha sido editado.
    vip_query('INSERT INTO mt_vip_memberships(client_id,plan_id,monthly_price_cents,included_cuts,service_name,starts_on,ends_on,status,notes) VALUES (?,?,?,?,?,?,?,?,?)',[$clientId,$req['plan_id'],$req['monthly_price_cents'],$req['included_cuts'],$req['service_name'],$start,$end,'active','Cadastro VIP via solicitação #'.$id]);
    $membershipId=(int)$pdo->lastInsertId();
    // V51: ativar senha cadastrada no formulário somente após aprovação.
    // Solicitações legadas sem senha continuam exigindo o link pessoal de ativação.
    $pendingHash=(string)($req['registration_password_hash']??'');
    if($pendingHash!==''){
      if(!preg_match('/^\$(?:2[aby]\$|argon2(?:i|id)\$)/D',$pendingHash))throw new RuntimeException('Formato de hash da solicitação inválido.');
      $current=vip_query('SELECT password_hash FROM mt_vip_customer_accounts WHERE client_id=? FOR UPDATE',[$clientId])->fetch();
      if($current && is_string($current['password_hash']) && $current['password_hash']!==''){
        throw new RuntimeException('Este cliente já possui senha VIP. Não substitua credenciais pelo pré-cadastro; use recuperação de acesso.');
      }
      vip_query('INSERT INTO mt_vip_customer_accounts(client_id,password_hash,must_change_password) VALUES(?,?,0) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),must_change_password=0',[$clientId,$pendingHash]);
    }
    if($googleRequest){
      // O proprietário confirmou pessoalmente o WhatsApp do solicitante.
      // Só agora a conta Google verificada recebe acesso ao cadastro aprovado.
      $alreadyLinked=vip_query('SELECT client_id FROM mt_vip_google_accounts WHERE google_sub=? FOR UPDATE',[$googleRequest['google_sub']])->fetchColumn();
      if($alreadyLinked!==false && (int)$alreadyLinked!==$clientId)throw new RuntimeException('Essa conta Google já está vinculada a outro assinante.');
      $otherGoogle=vip_query('SELECT google_sub FROM mt_vip_google_accounts WHERE client_id=? FOR UPDATE',[$clientId])->fetchColumn();
      if($otherGoogle!==false && $otherGoogle!==$googleRequest['google_sub'])throw new RuntimeException('O cliente já possui outra conta Google vinculada.');
      vip_query('INSERT INTO mt_vip_customer_accounts(client_id,password_hash) VALUES(?,NULL) ON DUPLICATE KEY UPDATE client_id=client_id',[$clientId]);
      if($alreadyLinked===false){
        vip_query('INSERT INTO mt_vip_google_accounts(client_id,google_sub,google_email,last_login_at) VALUES(?,?,?,NULL)',[$clientId,$googleRequest['google_sub'],$googleRequest['google_email']]);
      }
    }
    if($appleRequest){
      $alreadyLinked=vip_query('SELECT client_id FROM mt_vip_apple_accounts WHERE apple_sub=? FOR UPDATE',[$appleRequest['apple_sub']])->fetchColumn();
      if($alreadyLinked!==false&&(int)$alreadyLinked!==$clientId)throw new RuntimeException('Conta Apple já vinculada a outro assinante.');
      $otherApple=vip_query('SELECT apple_sub FROM mt_vip_apple_accounts WHERE client_id=? FOR UPDATE',[$clientId])->fetchColumn();
      if($otherApple!==false&&$otherApple!==$appleRequest['apple_sub'])throw new RuntimeException('Cliente já possui outra conta Apple vinculada.');
      vip_query('INSERT INTO mt_vip_customer_accounts(client_id,password_hash) VALUES(?,NULL) ON DUPLICATE KEY UPDATE client_id=client_id',[$clientId]);
      if($alreadyLinked===false)vip_query('INSERT INTO mt_vip_apple_accounts(client_id,apple_sub,apple_email) VALUES(?,?,?)',[$clientId,$appleRequest['apple_sub'],$appleRequest['apple_email']]);
    }
   }
   vip_query('UPDATE mt_vip_requests SET status=?,client_id=?,membership_id=?,reviewed_by=?,reviewed_at=NOW(),registration_password_hash=NULL WHERE id=?',[$decision==='approve'?'approved':'rejected',$clientId,$membershipId,(int)($_SESSION['admin_id']??0),$id]);
   $pdo->commit();
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  vip_audit('request_'.$decision,$id);json_out(['ok'=>true,'membership_id'=>$membershipId]);
 }
 if($action==='password_set') {
  // Somente o proprietário pode definir uma senha temporária. Exige a senha atual do proprietário.
  mt_require_owner();
  require_once __DIR__.'/../app/vip_auth.php';
  vip_auth_require_schema();
  try {
    $col=vip_query("SHOW COLUMNS FROM mt_vip_customer_accounts LIKE 'must_change_password'")->fetch();
  } catch(Throwable $e){$col=false;}
  if(!$col)fail('Instale primeiro a migração SQL V25 para alterar senhas VIP.',503);
  $membershipId=vip_id($d['membership_id']??'');
  $password=(string)($d['new_password']??'');
  $confirmation=(string)($d['confirm_password']??'');
  $adminPassword=(string)($d['admin_password']??'');
  vip_auth_password($password);
  if(!hash_equals($password,$confirmation))fail('A confirmação da nova senha não corresponde.',422);
  if($adminPassword===''||strlen($adminPassword)>256)fail('Confirme sua senha administrativa.',403);
  $attempts=array_values(array_filter((array)($_SESSION['vip_owner_password_attempts']??[]),static fn($at)=>(int)$at>time()-900));
  if(count($attempts)>=5)fail('Limite de tentativas de confirmação atingido. Tente novamente mais tarde.',429);
  $attempts[]=time();$_SESSION['vip_owner_password_attempts']=$attempts;
  $admin=vip_query('SELECT password_hash FROM mt_admins WHERE id=? LIMIT 1',[(int)($_SESSION['admin_id']??0)])->fetchColumn();
  if(!is_string($admin)||!password_verify($adminPassword,$admin))fail('Sua senha administrativa está incorreta.',403);
  if(hash_equals($password,$adminPassword))fail('Use uma senha VIP diferente da senha do administrador.',422);
  // O mesmo cliente pode ter múltiplas assinaturas: a senha pertence à conta do cliente.
  $pdo=db();$pdo->beginTransaction();
  try {
    $member=vip_query('SELECT m.client_id,c.name FROM mt_vip_memberships m JOIN mt_clients c ON c.id=m.client_id WHERE m.id=? FOR UPDATE',[$membershipId])->fetch();
    if(!$member)fail('Assinatura não encontrada.',404);
    $clientId=(int)$member['client_id'];
    $hash=password_hash($password,PASSWORD_DEFAULT);
    vip_query('INSERT INTO mt_vip_customer_accounts (client_id,password_hash,must_change_password,failed_attempts,locked_until,session_version) VALUES (?,?,1,0,NULL,1) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),must_change_password=1,failed_attempts=0,locked_until=NULL,session_version=session_version+1',[$clientId,$hash]);
    // Qualquer link VIP antigo do cliente perde validade ao redefinir a senha.
    if(vip_schedule_ready())vip_query('DELETE l FROM mt_vip_access_links l JOIN mt_vip_memberships m ON m.id=l.membership_id WHERE m.client_id=?',[$clientId]);
    $pdo->commit();
  } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  unset($_SESSION['vip_owner_password_attempts']);
  vip_audit('temporary_password_created',$membershipId);
  json_out(['ok'=>true,'message'=>'Senha temporária definida. As sessões e os links anteriores foram revogados. Oriente o cliente a alterá-la no próximo acesso.']);
 }
 if($action==='login_reset') {
  mt_require_owner();vip_schedule_require_schema();require_once __DIR__.'/../app/vip_auth.php';vip_auth_require_schema();
  $id=vip_id($d['membership_id']??'');
  $q=vip_query('SELECT m.client_id,m.status,m.ends_on,c.phone client_phone,c.name client_name FROM mt_vip_memberships m JOIN mt_clients c ON c.id=m.client_id WHERE m.id=?',[$id]);
  $member=$q->fetch();if(!$member)fail('Assinatura não encontrada.',404);
  if($member['status']!=='active'||$member['ends_on']<date('Y-m-d'))fail('Somente assinaturas vigentes podem redefinir o login.',409);
  $pdo=db();$pdo->beginTransaction();
  try {
    $q=vip_query('UPDATE mt_vip_customer_accounts SET password_hash=NULL,must_change_password=0,session_version=session_version+1,failed_attempts=0,locked_until=NULL WHERE client_id=?',[(int)$member['client_id']]);
    $link=vip_schedule_link($id);
    $pdo->commit();
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  vip_audit('login_reset',$id);
  json_out(['ok'=>true,'link'=>str_replace('/vip-agenda.php?chave=','/vip-login.php?chave=',$link),'phone'=>$member['client_phone'],'name'=>$member['client_name'],'reset'=>true]);
 }
 if($action==='link_generate') {
  mt_require_owner();vip_schedule_require_schema();
  $id=vip_id($d['membership_id']??'');
  $q=vip_query('SELECT m.status,m.ends_on,c.name client_name,c.phone client_phone FROM mt_vip_memberships m JOIN mt_clients c ON c.id=m.client_id WHERE m.id=?',[$id]);
  $member=$q->fetch();
  if(!$member)fail('Assinatura não encontrada.',404);
  if($member['status']!=='active'||$member['ends_on']<date('Y-m-d'))fail('A assinatura deve estar ativa e dentro do prazo para gerar o link.',409);
  $link=vip_schedule_link($id);
  vip_audit('private_link_rotated',$id);
  json_out(['ok'=>true,'link'=>$link,'phone'=>$member['client_phone'],'name'=>$member['client_name']]);
 }
 if($action==='plan_save') {
  mt_require_owner();
  $id=isset($d['id'])&&$d['id']!==''?vip_id($d['id']):0;
  $name=vip_name($d['name']??'');if($name==='')fail('Informe o nome do plano.');
  $description=vip_name($d['description']??'',500);
  $serviceName=trim((string)($d['service_name']??'Corte'));
  $allowedServices=['Corte','Barba','Corte + Barba','Corte + Barba + Sobrancelha','Barba + Sobrancelha'];
  if(!in_array($serviceName,$allowedServices,true))fail('Selecione o serviço incluído na assinatura.',422);
  if(!vip_query('SELECT id FROM mt_services WHERE name=? AND active=1 LIMIT 1',[$serviceName])->fetchColumn())fail('Serviço não cadastrado ou inativo. Instale a migração V31 e confira os serviços.',409);
  $price=(string)($d['price']??'');
  if(!preg_match('/^\d{1,7}(?:[.,]\d{1,2})?$/D',$price))fail('Preço inválido.');
  $cents=(int)round((float)str_replace(',','.',$price)*100);
  $cuts=filter_var($d['cuts_per_month']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>30]]);
  if(!$cuts)fail('Informe de 1 a 30 atendimentos por ciclo de 30 dias.');
  $active=($d['active']??true)===true||($d['active']??'')==='1'?1:0;
  if($active===1 && $cents===0)fail('Para publicar uma assinatura VIP, informe uma mensalidade maior que zero. Use o WhatsApp para condições sob consulta.',422);
  if($id){
   if(!vip_query('SELECT id FROM mt_vip_plans WHERE id=?',[$id])->fetchColumn())fail('Plano não encontrado.',404);
   vip_query('UPDATE mt_vip_plans SET name=?,description=?,monthly_price_cents=?,cuts_per_month=?,service_name=?,active=? WHERE id=?',[$name,$description,$cents,$cuts,$serviceName,$active,$id]);
  }else{vip_query('INSERT INTO mt_vip_plans(name,description,monthly_price_cents,cuts_per_month,service_name,active) VALUES (?,?,?,?,?,?)',[$name,$description,$cents,$cuts,$serviceName,$active]);$id=(int)db()->lastInsertId();}
  vip_audit('plan_saved',$id);json_out(['ok'=>true,'id'=>$id]);
 }

 if($action==='admin_reserve') {
  vip_schedule_require_schema();
  $id=vip_id($d['membership_id']??'');$day=vip_date($d['date']??'');
  $barber=vip_id($d['barber_id']??'');$time=(string)($d['time']??'');
  if(!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D',$time)||(int)substr($time,3,2)%15!==0)fail('Selecione um horário válido.',422);
  $mutex='martelli_barber_'.$barber;
  if((int)vip_query('SELECT GET_LOCK(?,5)',[$mutex])->fetchColumn()!==1)fail('Barbeiro ocupado, tente novamente.',409);
  $pdo=db();
  try {
   $pdo->beginTransaction();
   $m=vip_query('SELECT * FROM mt_vip_memberships WHERE id=? FOR UPDATE',[$id])->fetch();
   if(!$m)fail('Assinatura não encontrada.',404);
   $booking=vip_schedule_insert_reservation($m,$day,$time,$barber,true);
   $pdo->commit();
  }finally{
   if($pdo->inTransaction())$pdo->rollBack();
   vip_query('SELECT RELEASE_LOCK(?)',[$mutex]);
  }
  vip_audit('admin_booking_created',$id);
  json_out(['ok'=>true,'message'=>$booking['status']==='pending'?'Pedido registrado para aprovação do barbeiro.':'Atendimento VIP confirmado na agenda do barbeiro.','appointment'=>$booking]);
 }
 if($action==='membership_dates') {
  mt_require_owner();
  $id=vip_id($d['id']??'');
  $start=vip_date($d['starts_on']??'');
  // O servidor calcula o vencimento; não recebe uma data final arbitrária do navegador.
  $end=vip_end($start);
  if($start<date('Y-m-d',strtotime('-365 days'))||$start>date('Y-m-d',strtotime('+365 days')))fail('Início fora do prazo permitido.',422);
  vip_owner_confirmation((string)($d['admin_password']??''));
  $pdo=db();$pdo->beginTransaction();
  try {
   $member=vip_query('SELECT * FROM mt_vip_memberships WHERE id=? FOR UPDATE',[$id])->fetch();
   if(!$member)fail('Assinatura não encontrada.',404);
   if($member['status']==='cancelled')fail('Não altere uma assinatura cancelada. Crie uma nova.',409);
   vip_lock_client((int)$member['client_id']);
   $overlap=vip_query("SELECT id FROM mt_vip_memberships WHERE id<>? AND client_id=? AND status IN ('active','paused') AND starts_on<=? AND ends_on>=? LIMIT 1",[$id,$member['client_id'],$end,$start])->fetchColumn();
   if($overlap)fail('Este cliente possui outra assinatura nesse período.',409);
   if(vip_schedule_ready()) {
    $outside=vip_query("SELECT COUNT(*) FROM mt_vip_appointments v JOIN mt_appointments a ON a.id=v.appointment_id WHERE v.membership_id=? AND a.status NOT IN ('cancelled','no_show') AND DATE(a.starts_at) NOT BETWEEN ? AND ?",[$id,$start,$end])->fetchColumn();
    if((int)$outside>0)fail('Existem agendamentos fora da nova vigência. Remarque-os antes.',409);
   }
   $manual=vip_query('SELECT COUNT(*) FROM mt_vip_uses WHERE membership_id=? AND used_on NOT BETWEEN ? AND ?',[$id,$start,$end])->fetchColumn();
   if((int)$manual>0)fail('Existem cortes registrados fora do novo período. Preserve o histórico.',409);
   vip_query('UPDATE mt_vip_memberships SET starts_on=?,ends_on=? WHERE id=?',[$start,$end,$id]);
   $pdo->commit();
  }finally{if($pdo->inTransaction())$pdo->rollBack();}
  vip_audit('subscription_dates_edited',$id);
  json_out(['ok'=>true,'message'=>'Vigência de 30 dias corridos atualizada sem alterar agendamentos ou valores.','starts_on'=>$start,'ends_on'=>$end]);
 }
 if($action==='membership_create') {
  // Uma operação transacional cria o cliente (quando novo) e a assinatura, sem cadastros órfãos.
  // Personalizações não alteram os planos públicos nem contratos já existentes.
  $mode=(string)($d['client_mode']??'existing');
  $kind=(string)($d['membership_mode']??'standard');
  if(!in_array($mode,['existing','new'],true)||!in_array($kind,['standard','custom'],true))fail('Escolha uma modalidade válida.',422);
  if($mode==='new'||$kind==='custom')mt_require_owner();
  $client=$mode==='existing'?vip_id($d['client_id']??''):0;
  $start=vip_date($d['starts_on']??'');$end=vip_end($start);
  $notes=vip_name($d['notes']??'',500);
  $tz=new DateTimeZone('America/Sao_Paulo');$now=new DateTimeImmutable('today',$tz);
  if($start<$now->modify('-365 days')->format('Y-m-d')||$start>$now->modify('+365 days')->format('Y-m-d'))fail('Data inicial fora do período permitido.',422);
  if($mode==='new'){
   $newName=vip_client_name($d['new_client_name']??'');
   $newPhone=vip_phone($d['new_client_phone']??'');
   mt_cpf_schema();$newCpf=mt_cpf((string)($d['new_client_cpf']??''));
  }
  if($kind==='custom'){
   $customName=vip_name($d['custom_name']??'',75);
   if($customName==='')fail('Informe o nome da assinatura personalizada.',422);
   $customDescription=vip_name($d['custom_description']??'',350);
   $raw=(string)($d['custom_price']??'');
   if(!preg_match('/^\d{1,5}(?:[.,]\d{1,2})?$/D',$raw))fail('Informe um valor válido para a assinatura.',422);
   $customPrice=(int)round((float)str_replace(',','.',$raw)*100);
   if($customPrice<1||$customPrice>9999999)fail('A assinatura personalizada precisa ter valor entre R$ 0,01 e R$ 99.999,99.',422);
   $customCuts=filter_var($d['custom_cuts']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>30]]);
   if(!$customCuts)fail('Informe entre 1 e 30 atendimentos por ciclo.',422);
   $customService=(string)($d['custom_service']??'');
   if(!in_array($customService,['Corte','Barba','Corte + Barba','Corte + Barba + Sobrancelha','Barba + Sobrancelha'],true))fail('Selecione um serviço válido.',422);
  }else{
   $plan=vip_id($d['plan_id']??'');
  }
  $pdo=db();$pdo->beginTransaction();
  try {
   if($mode==='new'){
    if(vip_query('SELECT id FROM mt_clients WHERE phone=? OR cpf=? FOR UPDATE',[$newPhone,$newCpf])->fetchColumn())fail('CPF ou WhatsApp já cadastrado. Selecione o cliente existente.',409);
    vip_query('INSERT INTO mt_clients(name,phone,cpf) VALUES (?,?,?)',[$newName,$newPhone,$newCpf]);
    $client=(int)$pdo->lastInsertId();
   }
   vip_lock_client($client);
   if($kind==='custom'){
    if(!vip_query('SELECT id FROM mt_services WHERE name=? AND active=1 LIMIT 1',[$customService])->fetchColumn())fail('Serviço personalizado inativo ou não cadastrado. Verifique a lista de serviços.',409);
    $name='PERSONALIZADO · '.$customName;
    $description=$customDescription!==''?$customDescription:'Plano privado com condições negociadas individualmente.';
    vip_query('INSERT INTO mt_vip_plans(name,description,monthly_price_cents,cuts_per_month,service_name,active) VALUES (?,?,?,?,?,0)',[$name,$description,$customPrice,$customCuts,$customService]);
    $plan=(int)$pdo->lastInsertId();
    $chosenPlan=['monthly_price_cents'=>$customPrice,'cuts_per_month'=>$customCuts,'service_name'=>$customService];
   }else{
    $chosenPlan=vip_query('SELECT id,monthly_price_cents,cuts_per_month,service_name FROM mt_vip_plans WHERE id=? AND active=1',[$plan])->fetch();
    if(!$chosenPlan)fail('Plano inativo ou inexistente.',409);
   }
   $overlap=vip_query("SELECT id FROM mt_vip_memberships WHERE client_id=? AND status IN ('active','paused') AND starts_on<=? AND ends_on>=? LIMIT 1",[$client,$end,$start])->fetchColumn();
   if($overlap)fail('Cliente já possui assinatura VIP vigente ou programada neste período. Consulte o histórico antes de criar outra.',409);
   $samePeriod=vip_query('SELECT id FROM mt_vip_memberships WHERE client_id=? AND plan_id=? AND starts_on=? AND ends_on=? LIMIT 1',[$client,$plan,$start,$end])->fetchColumn();
   if($samePeriod)fail('Já existe uma assinatura deste plano para o período informado.',409);
   vip_query('INSERT INTO mt_vip_memberships(client_id,plan_id,monthly_price_cents,included_cuts,service_name,starts_on,ends_on,status,notes) VALUES (?,?,?,?,?,?,?,?,?)',[$client,$plan,(int)$chosenPlan['monthly_price_cents'],(int)$chosenPlan['cuts_per_month'],$chosenPlan['service_name'],$start,$end,'active',$notes]);
   $id=(int)$pdo->lastInsertId();$pdo->commit();
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  vip_audit($kind==='custom'?'custom_subscription_created':'subscription_created',$id);
  json_out(['ok'=>true,'id'=>$id,'client_id'=>$client,'message'=>'Cliente e assinatura VIP salvos. O ciclo de 30 dias foi criado, sem realizar cobrança automática.']);
 }
 if($action==='membership_status') {
  $id=vip_id($d['id']??'');$status=(string)($d['status']??'');
  if(!in_array($status,['active','paused','cancelled'],true))fail('Situação inválida.');
  $pdo=db();$pdo->beginTransaction();
  try{
   $row=vip_query('SELECT * FROM mt_vip_memberships WHERE id=? FOR UPDATE',[$id])->fetch();
   if(!$row)fail('Assinatura não encontrada.',404);
   vip_lock_client((int)$row['client_id']);
   if($row['status']==='cancelled'&&$status!=='cancelled')fail('Assinatura cancelada não pode ser reativada. Crie outra.');
   if($status==='active'){
    if($row['ends_on']<date('Y-m-d'))fail('Assinatura vencida. Crie uma renovação.');
    $overlap=vip_query("SELECT id FROM mt_vip_memberships WHERE id<>? AND client_id=? AND status IN ('active','paused') AND starts_on<=? AND ends_on>=? LIMIT 1",[$id,$row['client_id'],$row['ends_on'],$row['starts_on']])->fetchColumn();
    if($overlap)fail('Existe uma assinatura conflitante no período.');
   }
   vip_query('UPDATE mt_vip_memberships SET status=? WHERE id=?',[$status,$id]);
   if($status==='cancelled'&&vip_schedule_ready())vip_query('DELETE FROM mt_vip_access_links WHERE membership_id=?',[$id]);
   $pdo->commit();
  }catch(Throwable $e){$pdo->rollBack();throw $e;}
  vip_audit('subscription_status',$id);json_out(['ok'=>true]);
 }
 if($action==='use_create') {
  $id=vip_id($d['membership_id']??'');$day=vip_date($d['used_on']??'');$notes=vip_name($d['notes']??'',200);
  if($day>date('Y-m-d'))fail('Não registre cortes em datas futuras.');
  $pdo=db();$pdo->beginTransaction();
  try{
   $row=vip_query('SELECT * FROM mt_vip_memberships WHERE id=? FOR UPDATE',[$id])->fetch();
   if(!$row)fail('Assinatura não encontrada.',404);
   if($row['status']!=='active'||$day<$row['starts_on']||$day>$row['ends_on'])fail('Assinatura inativa ou data fora da vigência.');
   $counts=vip_schedule_ready()?vip_schedule_counts($id):null;
   $used=$counts?$counts['total_committed']:(int)vip_query('SELECT COUNT(*) FROM mt_vip_uses WHERE membership_id=?',[$id])->fetchColumn();
   if($used>=(int)$row['included_cuts'])fail('Todos os cortes deste ciclo já foram realizados ou reservados.');
   if(vip_schedule_ready()){
    $q=vip_query("SELECT COUNT(*) FROM mt_vip_appointments v JOIN mt_appointments a ON a.id=v.appointment_id WHERE v.membership_id=? AND a.status IN ('pending','confirmed','completed') AND DATE(a.starts_at)=?",[$id,$day]);
    if((int)$q->fetchColumn()>0)fail('Já existe um corte VIP reservado ou realizado neste dia. Evite registrar o mesmo corte duas vezes.');
   }
   vip_query('INSERT INTO mt_vip_uses(membership_id,used_on,notes,created_by) VALUES (?,?,?,?)',[$id,$day,$notes,(int)($_SESSION['admin_id']??0)]);
   $pdo->commit();
  }catch(Throwable $e){$pdo->rollBack();throw $e;}
  vip_audit('cut_redeemed',$id);json_out(['ok'=>true]);
 }
 fail('Operação inválida.',404);
} catch(Throwable $e){
 if($e instanceof PDOException){error_log('Martelli VIP SQL: '.$e->getMessage());fail('Falha no banco de dados. Verifique a instalação do VIP.',503);}
 if($e instanceof RuntimeException){fail($e->getMessage(),409);}
 throw $e;
}
