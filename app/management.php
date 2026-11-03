<?php
declare(strict_types=1);

// Separate records in the existing settings store keep upgrades non-destructive.
/** Lista esperas ou bloqueios guardados em configurações. */
function management_records(string $kind): array {
    $q=db()->prepare('SELECT `key`,`value` FROM mt_settings WHERE `key` LIKE ?');
    $q->execute(['mgmt:'.$kind.':%']);
    $rows=[];
    foreach ($q->fetchAll() as $r) {
        $v=json_decode($r['value'],true);
        if (is_array($v)) { $v['id']=$r['key']; $rows[]=$v; }
    }
    return $rows;
}
/** Detecta interseção de intervalos da agenda. */
function management_overlap(string $start,string $end,array $row): bool {
    return $start<$row['ends_at'] && $end>$row['starts_at'];
}
/** Obtém bloqueios de um profissional específico. */
function management_blocks(int $barber): array {
    return array_values(array_filter(management_records('block'),fn($r)=>(int)$r['barber_id']===$barber));
}
/** Impede sobreposição de bloqueios operacionais. */
function management_assert_free(int $barber,string $start,string $end): void {
    foreach (management_blocks($barber) as $row)
        if (management_overlap($start,$end,$row)) fail('O profissional está indisponível neste período.',409);
}
/** Valida data do gerenciamento de agenda. */
function management_day(string $value): string {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if (!$d || $d->format('Y-m-d')!==$value || $value<'2000-01-01' || $value>'2100-12-31') fail('Data inválida.');
    return $value;
}
/** Roteia lista de espera, bloqueios e relatórios administrativos. */
function management_dispatch(string $action,string $method): void {
    mt_require($action==='management_report' ? 'relatorios' : 'lista_espera');
    if ($action==='management_list') {
        if ($method!=='GET') fail('Método não permitido.',405);
        json_out(['ok'=>true,'waitlist'=>management_records('wait'),'blocks'=>management_records('block'),
            'barbers'=>fetch_all('mt_barbers','sort_order'),'services'=>fetch_all('mt_services','sort_order')]);
    }
    if ($action==='management_report') {
        if ($method!=='GET') fail('Método não permitido.',405);
        $from=management_day((string)($_GET['from']??''));
        $to=management_day((string)($_GET['to']??''));
        if ($to<$from || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days>366) fail('Escolha um período de até 367 dias.');
        $q=db()->prepare("SELECT a.status,COUNT(*) quantity,COALESCE(SUM(a.price),0) amount FROM mt_appointments a WHERE a.starts_at>=? AND a.starts_at<? GROUP BY a.status");
        $end=(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
        $q->execute([$from,$end]); $summary=$q->fetchAll();
        $q=db()->prepare("SELECT b.name,COUNT(*) quantity,COALESCE(SUM(a.price),0) amount FROM mt_appointments a JOIN mt_barbers b ON b.id=a.barber_id WHERE a.starts_at>=? AND a.starts_at<? AND a.status='completed' GROUP BY b.id,b.name ORDER BY amount DESC,b.id");
        $q->execute([$from,$end]);
        json_out(['ok'=>true,'summary'=>$summary,'professionals'=>$q->fetchAll()]);
    }
    if ($method!=='POST') fail('Método não permitido.',405);
    check_csrf(); $d=input(); $kind=(string)($d['kind']??'');
    if (!in_array($kind,['wait','block'],true)) fail('Tipo inválido.');
    if ($action==='management_remove') {
        $id=(string)($d['id']??'');
        if (!preg_match('/^mgmt:'. $kind .':[a-f0-9]{24}$/D',$id)) fail('Registro inválido.');
        $q=db()->prepare('DELETE FROM mt_settings WHERE `key`=?');$q->execute([$id]);
        json_out(['ok'=>true]);
    }
    if ($action!=='management_add') fail('Ação inválida.',404);
    $date=management_day((string)($d['date']??''));
    if ($date<date('Y-m-d')) fail('Escolha hoje ou uma data futura.');
    $barber=(int)($d['barber_id']??0);
    $q=db()->prepare('SELECT id FROM mt_barbers WHERE id=? AND active=1');$q->execute([$barber]);
    if (!$q->fetchColumn()) fail('Selecione um profissional ativo.');
    $notes=trim((string)($d['notes']??''));
    if (text_len($notes)>500) fail('Observação muito longa.');
    $row=['date'=>$date,'barber_id'=>$barber,'notes'=>$notes,'created_at'=>date('Y-m-d H:i:s')];
    if ($kind==='wait') {
        $name=trim((string)($d['name']??''));$phone=normalize_br_phone((string)($d['phone']??''));
        if (text_len($name)<2 || text_len($name)>120 || !$phone) fail('Informe nome e telefone com DDD.');
        $service=(int)($d['service_id']??0);service_and_barber($barber,$service);
        $row+=['name'=>$name,'phone'=>$phone,'service_id'=>$service];
    } else {
        foreach (['start','end'] as $field) if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D',(string)($d[$field]??''))) fail('Horário inválido.');
        if ($d['end']<=$d['start']) fail('O fim deve ser posterior ao início.');
        $row+=['starts_at'=>$date.' '.$d['start'].':00','ends_at'=>$date.' '.$d['end'].':00'];
    }
    $pdo=db();$lock='martelli_barber_'.$barber;
    $q=$pdo->prepare('SELECT GET_LOCK(?,5)');$q->execute([$lock]);
    if ((int)$q->fetchColumn()!==1) fail('Agenda ocupada. Tente novamente.',409);
    try {
        if ($kind==='block') {
            management_assert_free($barber,$row['starts_at'],$row['ends_at']);
            $busyUntil=mt_busy_until_sql();
            $q=$pdo->prepare("SELECT COUNT(*) FROM mt_appointments WHERE barber_id=? AND status NOT IN ('cancelled','no_show') AND starts_at<? AND {$busyUntil}>?");
            $q->execute([$barber,$row['ends_at'],$row['starts_at']]);
            if ((int)$q->fetchColumn()) fail('Há agendamentos neste período. Remarque-os antes de bloquear.',409);
        }
        $records=management_records($kind);
        if (count($records)>=2000) fail('Limite de 2.000 registros atingido. Remova registros antigos.',409);
        if ($kind==='wait') foreach ($records as $existing)
            if ($existing['date']===$date && $existing['phone']===$phone && (int)$existing['barber_id']===$barber && (int)$existing['service_id']===$service) fail('Este cliente já está na lista para esta data, serviço e profissional.',409);
        $q=$pdo->prepare('INSERT INTO mt_settings (`key`,`value`) VALUES (?,?)');
        $q->execute(['mgmt:'.$kind.':'.bin2hex(random_bytes(12)),json_encode($row,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    } finally { $q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]); }
    json_out(['ok'=>true],201);
}
