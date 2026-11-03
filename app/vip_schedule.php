<?php
/** Funções compartilhadas pela API VIP pública e pelo painel administrativo. */
declare(strict_types=1);
/** Verifica estrutura da agenda VIP antes de operar. */
function vip_schedule_ready(): bool {
    try {
        return db()->query("SHOW TABLES LIKE 'mt_vip_access_links'")->fetchColumn()!==false
            && db()->query("SHOW TABLES LIKE 'mt_vip_appointments'")->fetchColumn()!==false;
    }catch(Throwable $e){return false;}
}
/** Bloqueia rotas VIP quando a migração da agenda não existe. */
function vip_schedule_require_schema(): void {
    if(!vip_schedule_ready())fail('A agenda VIP ainda não foi instalada. Execute a migração V23 no banco de dados.',503);
}
/** Conta utilizações, reservas e atendimentos concluídos do ciclo. */
function vip_schedule_counts(int $id): array {
    $q=db()->prepare("SELECT COUNT(*) FROM mt_vip_uses WHERE membership_id=?");$q->execute([$id]);$manual=(int)$q->fetchColumn();
    $q=db()->prepare("SELECT a.status,COUNT(*) quantity FROM mt_vip_appointments v INNER JOIN mt_appointments a ON a.id=v.appointment_id WHERE v.membership_id=? AND a.status IN ('pending','confirmed','completed') GROUP BY a.status");
    $q->execute([$id]);$reserved=0;$completed=0;
    foreach($q->fetchAll() as $r){if($r['status']==='completed')$completed+=(int)$r['quantity'];else $reserved+=(int)$r['quantity'];}
    return ['used'=>$manual+$completed,'reserved'=>$reserved,'total_committed'=>$manual+$completed+$reserved];
}
/** Token presente somente em URL privada ou solicitação POST; banco guarda somente SHA-256. */
function vip_schedule_identity(string $token, bool $lock=false): array {
    if($token===''){require_once __DIR__.'/vip_auth.php';return vip_auth_member($lock);}
    if(!preg_match('/^[a-f0-9]{64}$/D',$token))fail('Link VIP inválido. Solicite um novo link à barbearia.',403);
    vip_schedule_require_schema();
    $sql='SELECT m.*,c.name client_name,p.name plan_name FROM mt_vip_access_links l JOIN mt_vip_memberships m ON m.id=l.membership_id JOIN mt_clients c ON c.id=m.client_id JOIN mt_vip_plans p ON p.id=m.plan_id WHERE l.token_hash=?';
    if($lock)$sql.=' FOR UPDATE';
    $q=db()->prepare($sql);$q->execute([hash('sha256',$token)]);$m=$q->fetch();
    if(!$m)fail('Este link não está mais válido. Peça outro à barbearia.',403);
    return $m;
}
/** Aceita somente serviços contratados e disponíveis. */
function vip_schedule_services(string $serviceName='Corte'): array {
    // Serviço contratado fica congelado na assinatura, independentemente de edições futuras do plano.
    $allowed=['Corte','Barba','Corte + Barba','Corte + Barba + Sobrancelha','Barba + Sobrancelha'];
    if(!in_array($serviceName,$allowed,true))fail('Serviço VIP inválido. Consulte a barbearia.',409);
    $q=db()->prepare('SELECT id,name,duration_minutes FROM mt_services WHERE active=1 AND name=? ORDER BY id LIMIT 1');
    $q->execute([$serviceName]);return $q->fetchAll();
}
/** Plano futuro pode receber reservas antecipadas, mas nunca fora do prazo contratado. */
function vip_schedule_max_day(): string {
    return (new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo')))->modify('+180 days')->format('Y-m-d');
}
/** Retorna dia atual no fuso de São Paulo. */
function vip_schedule_today(): string {
    return (new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
}
/** Confere situação e vigência do plano antes de agendar. */
function vip_schedule_check_membership_date(array $m,string $day): void {
    $tz=new DateTimeZone('America/Sao_Paulo');
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$day,$tz);
    if(!$d||$d->format('Y-m-d')!==$day)fail('Informe uma data válida.',422);
    if(($m['status']??'')!=='active')fail('A assinatura está pausada ou cancelada.',409);
    if($day<vip_schedule_today()||$day>vip_schedule_max_day())fail('Escolha um dia futuro dentro de 180 dias.',409);
    if($day<(string)$m['starts_on']||$day>(string)$m['ends_on'])fail('Selecione um dia dentro da vigência do plano VIP.',409);
}
/**
 * Busca dias já usados ou reservados pelo cliente em qualquer assinatura VIP.
 * Reservas canceladas e faltas não bloqueiam o dia; as pendentes, confirmadas
 * e concluídas bloqueiam. O retorno serve ao calendário e às validações PHP.
 */
function vip_schedule_blocked_days(int $clientId, string $first, string $last, int $exceptAppointmentId=0): array {
    $q=db()->prepare("SELECT DISTINCT DATE(a.starts_at) AS booked_on
        FROM mt_vip_appointments v
        JOIN mt_appointments a ON a.id=v.appointment_id
        WHERE a.client_id=? AND a.status IN ('pending','confirmed','completed')
          AND a.id<>? AND a.starts_at>=? AND a.starts_at<DATE_ADD(?,INTERVAL 1 DAY)");
    $q->execute([$clientId,$exceptAppointmentId,$first.' 00:00:00',$last]);
    $days=array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));
    // Registros manuais feitos pelo administrativo também consomem um dia.
    $q=db()->prepare('SELECT DISTINCT u.used_on FROM mt_vip_uses u
        JOIN mt_vip_memberships m ON m.id=u.membership_id
        WHERE m.client_id=? AND u.used_on BETWEEN ? AND ?');
    $q->execute([$clientId,$first,$last]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $day)$days[]=(string)$day;
    $days=array_values(array_unique($days));
    sort($days,SORT_STRING);
    return $days;
}
/** Não permite dois atendimentos VIP no mesmo dia, mesmo com barbeiros diferentes. */
function vip_schedule_assert_day_available(int $clientId,string $day,int $exceptAppointmentId=0): void {
    if(in_array($day,vip_schedule_blocked_days($clientId,$day,$day,$exceptAppointmentId),true)){
        fail('Você já possui um corte VIP reservado ou registrado nessa data. Escolha outro dia ou remarque o atendimento existente.',409);
    }
}
/** Inserção compartilhada por cliente e administrador, dentro da transação e lock do barbeiro. */
function vip_schedule_insert_reservation(array $m,string $day,string $time,int $barber,bool $administrative=false): array {
    if(!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D',$time)||(int)substr($time,3,2)%15!==0)fail('Selecione um horário válido.');
    if($barber<1)fail('Selecione um barbeiro.');
    vip_schedule_check_membership_date($m,$day);
    if(!$administrative && setting('bookings_enabled')!=='1')fail('Agendamentos online temporariamente desativados.',403);
    // Serializa reservas de um mesmo cliente, inclusive com barbeiros/plano diferentes.
    // A chamada ocorre dentro da transação iniciada pela API ou painel administrativo.
    $lock=db()->prepare('SELECT id FROM mt_clients WHERE id=? FOR UPDATE');
    $lock->execute([(int)$m['client_id']]);
    if(!$lock->fetchColumn())fail('Cliente não encontrado. Atualize a página.',404);
    $counts=vip_schedule_counts((int)$m['id']);
    if($counts['total_committed'] >= (int)$m['included_cuts'])fail('Todos os atendimentos deste ciclo já foram utilizados ou reservados.',409);
    vip_schedule_assert_day_available((int)$m['client_id'],$day);
    $services=vip_schedule_services((string)$m['service_name']);
    if(!$services)fail('O serviço deste plano VIP está indisponível.',409);
    $service=$services[0];
    $slots=vip_schedule_availability($day,$barber,(int)$service['id']);
    $selected=null;
    foreach($slots as $slot)if($slot['time']===$time){$selected=$slot;break;}
    if(!$selected)fail('Este horário não está livre. Escolha outro.',409);
    $start=new DateTimeImmutable($day.' '.$time.':00',new DateTimeZone('America/Sao_Paulo'));
    $end=$start->modify('+'.(int)$service['duration_minutes'].' minutes');
    $status=$selected['requires_approval']?'pending':'confirmed';
    $hours=db()->prepare('SELECT close_time FROM mt_business_hours WHERE weekday=?');
    $hours->execute([(int)$start->format('w')]);
    $normal=(string)$hours->fetchColumn();
    $extended=$end->format('H:i:s')>substr($normal,0,8);
    $q=db()->prepare('INSERT INTO mt_appointments(client_id,barber_id,service_id,starts_at,ends_at,price,is_extended,notes,status) VALUES(?,?,?,?,?,?,?,?,?)');
    $q->execute([(int)$m['client_id'],$barber,(int)$service['id'],$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),0,
        (int)$extended,'Atendimento '.(string)$service['name'].' incluído na assinatura VIP #'.(int)$m['id'],$status]);
    $appointmentId=(int)db()->lastInsertId();
    $q=db()->prepare('INSERT INTO mt_vip_appointments(membership_id,appointment_id) VALUES (?,?)');
    $q->execute([(int)$m['id'],$appointmentId]);
    return ['date'=>$day,'time'=>$time,'status'=>$status,'appointment_id'=>$appointmentId];
}
/** Calcula slots livres e aplica duração e bloqueios do profissional. */
function vip_schedule_availability(string $day,int $barber,int $service,int $excludeAppointmentId=0): array {
    $tz=new DateTimeZone('America/Sao_Paulo');
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$day,$tz);
    $now=new DateTimeImmutable('now',$tz);
    if(!$d||$d->format('Y-m-d')!==$day||$d<$now->setTime(0,0)||$d>$now->setTime(0,0)->modify('+180 days'))fail('Selecione uma data nos próximos 180 dias.');
    $q=db()->prepare('SELECT id,duration_minutes FROM mt_services WHERE id=? AND active=1');$q->execute([$service]);$s=$q->fetch();
    $q=db()->prepare('SELECT id FROM mt_barbers WHERE id=? AND active=1');$q->execute([$barber]);
    if(!$s||!$q->fetchColumn())fail('Selecione um serviço e barbeiro disponíveis.',409);
    $q=db()->prepare('SELECT * FROM mt_business_hours WHERE weekday=? AND is_open=1');$q->execute([(int)$d->format('w')]);$h=$q->fetch();
    if(!$h)return [];
    $start=substr($h['open_time'],0,5);$normal=substr($h['close_time'],0,5);$end=substr($h['extended_close_time']?:$h['close_time'],0,5);
    if($end<$normal)$end=$normal;
    $duration=(int)$s['duration_minutes'];if($duration<5||$duration>480)fail('Duração do corte inválida.',409);
    $t=$d->setTime((int)substr($start,0,2),(int)substr($start,3,2));
    $last=$d->setTime((int)substr($end,0,2),(int)substr($end,3,2));
    $q=db()->prepare('SELECT starts_at,'.mt_busy_until_sql().' AS ends_at FROM mt_appointments WHERE barber_id=? AND id<>? AND status NOT IN (\'cancelled\',\'no_show\') AND starts_at<? AND '.mt_busy_until_sql().'>?');
    $q->execute([$barber,$excludeAppointmentId,$day.' 23:59:59',$day.' 00:00:00']);$busy=$q->fetchAll();
    // Indisponibilidades administrativas também são respeitadas.
    foreach(management_blocks($barber) as $block)$busy[]=$block;
    $result=[];
    while($t->modify('+'.$duration.' minutes')<=$last){
        $finish=$t->modify('+'.$duration.' minutes');
        if($t->getTimestamp()>$now->getTimestamp()+900){
            $a=$t->format('Y-m-d H:i:s');$b=$finish->format('Y-m-d H:i:s');$free=true;
            foreach($busy as $o)if($a<(string)$o['ends_at']&&$b>(string)$o['starts_at']){$free=false;break;}
            if($free)$result[]=['time'=>$t->format('H:i'),'requires_approval'=>$t->format('H:i')>='18:00'];
        }
        $t=$t->modify('+15 minutes');
    }
    return $result;
}
/** Gera link privado e armazena somente hash do token. */
function vip_schedule_link(int $membershipId): string {
    $raw=bin2hex(random_bytes(32));
    $q=db()->prepare('INSERT INTO mt_vip_access_links(membership_id,token_hash) VALUES(?,?) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash),rotated_at=NOW()');
    $q->execute([$membershipId,hash('sha256',$raw)]);
    // Domínio canônico, evitando que HTTP_HOST fornecido pelo cliente altere o link.
    return 'https://martellibarbearia.com/vip-agenda.php?chave='.$raw;
}
