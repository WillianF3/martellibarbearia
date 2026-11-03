<?php
/** API do cliente VIP. Nunca aceita ID de cliente ou assinatura fornecido pelo navegador. */
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/management.php';
require __DIR__.'/../app/vip_schedule.php';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');
$action=(string)($_GET['action']??'');$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
try {
 if($action==='details'&&$method==='GET'){
  $m=vip_schedule_identity((string)($_GET['chave']??''));
  $counts=vip_schedule_counts((int)$m['id']);
  $valid=$m['status']==='active'&&$m['ends_on']>=vip_schedule_today();
  $barbers=$valid?db()->query('SELECT id,name FROM mt_barbers WHERE active=1 ORDER BY sort_order,id')->fetchAll():[];
  $services=$valid?vip_schedule_services((string)$m['service_name']):[];
  $q=db()->prepare("SELECT a.id,a.barber_id,a.starts_at,a.status,b.name barber_name FROM mt_vip_appointments v JOIN mt_appointments a ON a.id=v.appointment_id JOIN mt_barbers b ON b.id=a.barber_id WHERE v.membership_id=? ORDER BY a.starts_at DESC LIMIT 40");
  $q->execute([(int)$m['id']]);
  $blockedDates=vip_schedule_blocked_days((int)$m['client_id'],(string)$m['starts_on'],(string)$m['ends_on']);
  json_out(['ok'=>true,'blocked_dates'=>$blockedDates,'member'=>[
   'name'=>$m['client_name'],'plan'=>$m['plan_name'],'service_name'=>$m['service_name'],'starts_on'=>$m['starts_on'],'ends_on'=>$m['ends_on'],
   'status'=>$m['status'],'included_cuts'=>(int)$m['included_cuts'],'used'=>$counts['used'],
   'reserved'=>$counts['reserved'],'remaining'=>max(0,(int)$m['included_cuts']-$counts['total_committed']),
   'can_book'=>$valid && $m['starts_on']<=vip_schedule_max_day() && setting('bookings_enabled')==='1',
  ],'barbers'=>$barbers,'services'=>$services,'appointments'=>$q->fetchAll(),
  'bookings_enabled'=>setting('bookings_enabled')==='1']);
 }
 if($action==='slots'&&$method==='GET'){
  $m=vip_schedule_identity((string)($_GET['chave']??''));
  $date=(string)($_GET['date']??'');
  vip_schedule_check_membership_date($m,$date);
  vip_schedule_assert_day_available((int)$m['client_id'],$date);
  if(setting('bookings_enabled')!=='1')fail('Agendamentos temporariamente desativados.',409);
  $counts=vip_schedule_counts((int)$m['id']);
  if($counts['total_committed']>=(int)$m['included_cuts'])json_out(['ok'=>true,'slots'=>[],'message'=>'Todos os cortes deste ciclo já estão utilizados ou reservados.']);
  $services=vip_schedule_services((string)$m['service_name']);if(!$services)fail('O serviço contratado não está disponível. Consulte a barbearia.',409);
  $slots=vip_schedule_availability($date,(int)($_GET['barber_id']??0),(int)$services[0]['id']);
  json_out(['ok'=>true,'slots'=>$slots]);
 }
 // A reserva é feita uma a uma. A remarcação edita a mesma reserva (não consome outra vaga do plano).
 if($action==='reschedule_slots'&&$method==='GET'){
  $m=vip_schedule_identity((string)($_GET['chave']??''));
  if(setting('bookings_enabled')!=='1')fail('Agendamentos online desativados. Consulte a barbearia.',403);
  $id=(int)($_GET['appointment_id']??0);$date=(string)($_GET['date']??'');$barber=(int)($_GET['barber_id']??0);
  if($id<1||$barber<1)fail('Selecione seu atendimento e um barbeiro.',422);
  vip_schedule_check_membership_date($m,$date);
  $q=db()->prepare("SELECT a.id,a.starts_at,a.barber_id,a.service_id,a.status FROM mt_vip_appointments v JOIN mt_appointments a ON a.id=v.appointment_id WHERE v.membership_id=? AND a.client_id=? AND a.id=? LIMIT 1");
  $q->execute([(int)$m['id'],(int)$m['client_id'],$id]);$old=$q->fetch();
  if(!$old||!in_array($old['status'],['confirmed','pending'],true))fail('Este atendimento não pode mais ser remarcado.',409);
  vip_schedule_assert_day_available((int)$m['client_id'],$date,$id);
  $tz=new DateTimeZone('America/Sao_Paulo');
  if(new DateTimeImmutable((string)$old['starts_at'],$tz)<=(new DateTimeImmutable('now',$tz))->modify('+15 minutes'))fail('Para alterar um atendimento próximo ou já iniciado, fale com a barbearia.',409);
  $slots=vip_schedule_availability($date,$barber,(int)$old['service_id'],$id);
  // Não oferecer o mesmo horário já reservado.
  $slots=array_values(array_filter($slots,static fn($slot)=>!($barber===(int)$old['barber_id']&&$date===substr((string)$old['starts_at'],0,10)&&$slot['time']===substr((string)$old['starts_at'],11,5))));
  json_out(['ok'=>true,'slots'=>$slots]);
 }
 if($action==='reschedule'&&$method==='POST'){
  check_csrf();$d=input();
  $id=(int)($d['appointment_id']??0);$date=(string)($d['date']??'');$time=(string)($d['time']??'');$barber=(int)($d['barber_id']??0);
  if($id<1||$barber<1||!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D',$time)||(int)substr($time,3,2)%15!==0)fail('Escolha data, barbeiro e horário válidos.',422);
  if(setting('bookings_enabled')!=='1')fail('Agendamentos online desativados. Consulte a barbearia.',403);
  $attempts=$_SESSION['vip_reschedule_attempts']??[];
  $attempts=array_values(array_filter($attempts,static fn($t)=>$t>time()-600));
  if(count($attempts)>=8)fail('Muitas alterações. Aguarde alguns minutos.',429);
  $attempts[]=time();$_SESSION['vip_reschedule_attempts']=$attempts;
  $token=(string)($d['chave']??'');$pdo=db();
  $m=vip_schedule_identity($token);
  vip_schedule_check_membership_date($m,$date);
  $q=$pdo->prepare('SELECT a.barber_id FROM mt_vip_appointments v JOIN mt_appointments a ON a.id=v.appointment_id WHERE v.membership_id=? AND a.client_id=? AND a.id=?');
  $q->execute([(int)$m['id'],(int)$m['client_id'],$id]);$oldBarber=(int)$q->fetchColumn();
  if($oldBarber<1)fail('Reserva VIP não encontrada.',404);
  $locks=array_unique([$oldBarber,$barber]);sort($locks,SORT_NUMERIC);$locked=[];
  try {
   foreach($locks as $lockId){
    $lockName='martelli_barber_'.$lockId;
    $q=$pdo->prepare('SELECT GET_LOCK(?,5)');$q->execute([$lockName]);
    if((int)$q->fetchColumn()!==1)fail('A agenda está sendo atualizada. Tente novamente.',409);
    $locked[]=$lockName;
   }
   $pdo->beginTransaction();
   $m=vip_schedule_identity($token,true);
   vip_schedule_check_membership_date($m,$date);
   $q=$pdo->prepare('SELECT a.id,a.barber_id,a.service_id,a.starts_at,a.ends_at,a.status,a.notes FROM mt_vip_appointments v JOIN mt_appointments a ON a.id=v.appointment_id WHERE v.membership_id=? AND a.client_id=? AND a.id=? FOR UPDATE');
   $q->execute([(int)$m['id'],(int)$m['client_id'],$id]);$old=$q->fetch();
   if(!$old||!in_array($old['status'],['confirmed','pending'],true)||(int)$old['barber_id']!==$oldBarber)fail('Este agendamento foi alterado. Atualize a agenda.',409);
   // Bloqueia concorrência de remarcação e reserva de outros profissionais.
   $clientLock=$pdo->prepare('SELECT id FROM mt_clients WHERE id=? FOR UPDATE');
   $clientLock->execute([(int)$m['client_id']]);
   if(!$clientLock->fetchColumn())fail('Cliente não encontrado. Atualize a página.',404);
   vip_schedule_assert_day_available((int)$m['client_id'],$date,$id);
   $tz=new DateTimeZone('America/Sao_Paulo');$now=new DateTimeImmutable('now',$tz);
   $oldStart=new DateTimeImmutable((string)$old['starts_at'],$tz);
   if($oldStart<=$now->modify('+15 minutes'))fail('O atendimento está muito próximo ou já começou. Fale com a barbearia.',409);
   $services=vip_schedule_services((string)$m['service_name']);
   if(!$services||(int)$old['service_id']!==(int)$services[0]['id'])fail('O serviço mudou. Consulte a barbearia antes de remarcar.',409);
   $slots=vip_schedule_availability($date,$barber,(int)$old['service_id'],$id);
   $selected=null;foreach($slots as $slot){if($slot['time']===$time){$selected=$slot;break;}}
   if(!$selected)fail('Este horário não está livre. Escolha outro.',409);
   $start=new DateTimeImmutable($date.' '.$time.':00',$tz);
   $end=$start->modify('+'.(int)$services[0]['duration_minutes'].' minutes');
   $newStatus=$selected['requires_approval']?'pending':'confirmed';
   $q=$pdo->prepare('SELECT close_time FROM mt_business_hours WHERE weekday=?');$q->execute([(int)$start->format('w')]);
   $normal=substr((string)($q->fetchColumn()?:'18:00:00'),0,5);
   $newExtended=(int)($end->format('H:i')>$normal);
   $notes=(string)($old['notes']??'');
   $audit=' | Remarcado de '.$oldStart->format('d/m/Y H:i').' em '.$now->format('d/m/Y H:i');
   if(strlen($notes.$audit)<=500)$notes.=$audit;
   $q=$pdo->prepare('UPDATE mt_appointments SET barber_id=?,starts_at=?,ends_at=?,is_extended=?,status=?,notes=? WHERE id=? AND status IN (\'confirmed\',\'pending\')');
   $q->execute([$barber,$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),$newExtended,$newStatus,$notes,$id]);
   if($q->rowCount()!==1)fail('A reserva foi alterada. Atualize a página.',409);
   $pdo->commit();
   $response=['ok'=>true,'appointment'=>['id'=>$id,'date'=>$date,'time'=>$time,'status'=>$newStatus],
    'message'=>$newStatus==='pending'?'Remarcação solicitada. Aguarde a aprovação do barbeiro.':'Atendimento remarcado com sucesso.'];
  }finally{
   if($pdo->inTransaction())$pdo->rollBack();
   foreach(array_reverse($locked) as $lockName){$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);}
  }
  json_out($response,200);
 }
 if($action==='reserve'&&$method==='POST'){
  check_csrf();$d=input();
  if(!empty($d['website']))fail('Solicitação inválida.',400);
  $token=(string)($d['chave']??'');$date=(string)($d['date']??'');$time=(string)($d['time']??'');$barber=(int)($d['barber_id']??0);
  if(!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D',$time)||(int)substr($time,3,2)%15!==0)fail('Selecione um horário válido.');
  if($barber<1)fail('Selecione um barbeiro.');
  // Protege de envio em massa com token compartilhado, sem bloquear outros clientes.
  $attempts=$_SESSION['vip_booking_attempts']??[];
  $attempts=array_values(array_filter($attempts,static fn($t)=>$t>time()-600));
  if(count($attempts)>=5)fail('Muitas tentativas. Aguarde alguns minutos.',429);
  $attempts[]=time();$_SESSION['vip_booking_attempts']=$attempts;
  if(setting('bookings_enabled')!=='1')fail('Agendamentos online temporariamente desativados.',403);
  $lockName='martelli_barber_'.$barber;$pdo=db();
  $q=$pdo->prepare('SELECT GET_LOCK(?,5)');$q->execute([$lockName]);
  if((int)$q->fetchColumn()!==1)fail('O profissional está sendo atualizado. Tente novamente.',409);
  try {
   $pdo->beginTransaction();
   $m=vip_schedule_identity($token,true);
   $appointment=vip_schedule_insert_reservation($m,$date,$time,$barber);
   $pdo->commit();
   $response=['ok'=>true,'appointment'=>$appointment,
     'message'=>$appointment['status']==='pending'?'Pedido recebido. Aguarde a aprovação do barbeiro.':'Seu atendimento VIP foi reservado com sucesso.'];
  } finally {
   if($pdo->inTransaction())$pdo->rollBack();
   $q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);
  }
  json_out($response,201);
 }
 fail('Operação indisponível.',404);
}catch(PDOException $e){error_log('Martelli VIP Agenda SQL: '.$e->getMessage());fail('Erro ao consultar a agenda. Verifique a migração V23.',503);}
