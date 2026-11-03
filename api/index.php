<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/management.php';
require __DIR__.'/../app/cpf.php';
header('X-Content-Type-Options: nosniff');
$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$write = in_array($action, ['book','login','logout','admin_save','admin_delete','admin_booking','admin_reschedule','admin_user_create','admin_password_change','staff_account_save','staff_account_status','admin_access_save','admin_account_delete','barber_day_booking','barber_day_reschedule','barber_day_complete','barber_day_decide','barber_day_status'], true);
if ($write && $method !== 'POST') fail('Método não permitido.', 405);
if ($write) check_csrf();
try {
    if (in_array($action,['management_list','management_report','management_add','management_remove'],true)) management_dispatch($action,$method);
    switch($action) {
      case 'bootstrap':
        $services = db()->query('SELECT id,name,description,price,duration_minutes FROM mt_services WHERE active=1 ORDER BY sort_order,id')->fetchAll();
        $barbers = db()->query('SELECT id,name,bio FROM mt_barbers WHERE active=1 ORDER BY sort_order,id')->fetchAll();
        json_out(['ok'=>true,'csrf'=>csrf(),'services'=>$services,'barbers'=>$barbers,
          'hours'=>fetch_all('mt_business_hours','weekday'),
          'bookings_enabled'=>setting('bookings_enabled')==='1',
          'whatsapp'=>setting('whatsapp'),'instagram'=>setting('instagram'),'address'=>setting('address'),
          'notice'=>setting('notice')]);
      case 'slots':
        $date=(string)($_GET['date']??''); $barber=(int)($_GET['barber_id']??0); $service=(int)($_GET['service_id']??0);
        if ($barber<=0 || $service<=0) fail('Selecione o serviço e o profissional.');
        $slots=available_slots($date,$barber,$service);
        // Grade pública sem nomes, telefones ou IDs de clientes; horários ocupados são
        // informativos e jamais podem ser selecionados para realizar uma reserva.
        $busyUntil=mt_busy_until_sql();
        $q=db()->prepare("SELECT starts_at,{$busyUntil} AS ends_at,status FROM mt_appointments WHERE barber_id=? AND status NOT IN ('cancelled','no_show') AND starts_at<? AND {$busyUntil}>?");
        $next=(new DateTimeImmutable($date,new DateTimeZone('America/Sao_Paulo')))->modify('+1 day')->format('Y-m-d');
        $q->execute([$barber,$next.' 00:00:00',$date.' 00:00:00']);
        $calendar=day_slot_matrix($date,$barber,$q->fetchAll(),[['id'=>$service]]);
        json_out(['ok'=>true,'slots'=>$slots,'calendar'=>array_map(
            static fn($slot)=>['time'=>$slot['time'],'state'=>$slot['state']],$calendar)]);
      case 'book':
        $data=input();
        $result=create_booking($data,false);
        json_out(['ok'=>true,'appointment'=>$result],201);
      case 'login':
        $data=input(); $email=strtolower(trim((string)($data['email']??''))); $pass=(string)($data['password']??'');
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($pass)>256) fail('Credenciais incorretas.',401);
        $lim=$_SESSION['login_limit']??['count'=>0,'until'=>0];
        if (($lim['until']??0) < time()) $lim=['count'=>0,'until'=>time()+900];
        if ($lim['count']>=6) fail('Muitas tentativas. Tente novamente mais tarde.',429);
        // Administradores continuam na tabela existente. Barbeiros usam credenciais
        // próprias com escopo exclusivo, sem admin_id na sessão.
        $stmt=db()->prepare('SELECT id,name,password_hash FROM mt_admins WHERE email=? LIMIT 1');
        $stmt->execute([$email]); $admin=$stmt->fetch();
        $role=null;$identity=null;
        if ($admin && password_verify($pass,$admin['password_hash'])) {
            $role='admin';$identity=$admin;
        } elseif (!$admin) {
            // A tabela é criada pela migração aditiva V9. Antes dela só há logins admin.
            $table=db()->query("SHOW TABLES LIKE 'mt_barber_users'")->fetchColumn();
            if ($table) {
                $stmt=db()->prepare('SELECT u.id,u.barber_id,u.password_hash,b.name FROM mt_barber_users u JOIN mt_barbers b ON b.id=u.barber_id WHERE u.email=? AND u.active=1 AND b.active=1 LIMIT 1');
                $stmt->execute([$email]);$barber=$stmt->fetch();
                if ($barber && password_verify($pass,$barber['password_hash'])) {
                    $role='barber';$identity=$barber;
                }
            }
        }
        if ($role===null || !$identity) {
          $lim['count']++; $_SESSION['login_limit']=$lim; fail('Credenciais incorretas.',401);
        }
        session_regenerate_id(true);
        unset($_SESSION['admin_id'],$_SESSION['admin_name'],$_SESSION['staff_user_id']);
        if ($role==='admin') {
            $_SESSION['admin_id']=(int)$identity['id'];
            $_SESSION['admin_name']=$identity['name'];
        } else {
            $_SESSION['staff_user_id']=(int)$identity['id'];
        }
        $_SESSION['login_limit']=['count'=>0,'until'=>0];
        if ($role==='admin' && !mt_active_admin()) {
            unset($_SESSION['admin_id'],$_SESSION['admin_name']);
            fail('Seu acesso está desativado. Solicite autorização ao responsável.',403);
        }
        json_out(['ok'=>true,'role'=>$role,'redirect'=>$role==='barber'?'/barbeiro.php':mt_start_page()]);
      case 'logout':
        unset($_SESSION['admin_id'],$_SESSION['admin_name'],$_SESSION['staff_user_id']);
        session_regenerate_id(true);
        json_out(['ok'=>true]);
      case 'barber_day':
        // A data selecionada é apenas um filtro de leitura. A identidade do barbeiro
        // permanece vinculada à sessão; ações de escrita continuam limitadas a hoje.
        if ($method!=='GET') fail('Método não permitido.',405);
        $identity=require_barber();
        $timezone=new DateTimeZone('America/Sao_Paulo');
        $today=date('Y-m-d');
        $date=(string)($_GET['date']??$today);
        $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$timezone);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || !$day || $day->format('Y-m-d')!==$date)
            fail('Data inválida.',400);
        $todayDay=new DateTimeImmutable($today,$timezone);
        $minDate=$todayDay->modify('-365 days')->format('Y-m-d');
        $maxDate=$todayDay->modify('+30 days')->format('Y-m-d');
        if ($date<$minDate || $date>$maxDate) fail('Escolha uma data dentro do período disponível.',400);
        $end=$day->modify('+1 day')->format('Y-m-d');
        $finishedAt=mt_finished_at_select('a');
        $q=db()->prepare("SELECT a.id,a.starts_at,a.ends_at,{$finishedAt},a.status,c.name client_name,c.phone client_phone,s.name service_name FROM mt_appointments a JOIN mt_clients c ON c.id=a.client_id JOIN mt_services s ON s.id=a.service_id WHERE a.barber_id=? AND a.starts_at>=? AND a.starts_at<? ORDER BY a.starts_at ASC,a.id ASC");
        $q->execute([$identity['barber_id'],$date.' 00:00:00',$end.' 00:00:00']);
        $appointments=$q->fetchAll();
        $canBook=$date===$today && barber_can_book_today();
        $services=db()->query('SELECT id,name,price,duration_minutes FROM mt_services WHERE active=1 ORDER BY sort_order,id')->fetchAll();
        $slots=day_slot_matrix($date,(int)$identity['barber_id'],$appointments,$services);
        json_out(['ok'=>true,'date'=>$date,'today'=>$today,'min_date'=>$minDate,'max_date'=>$maxDate,'barber_name'=>$identity['name'],'appointments'=>$appointments,
            'slots'=>$slots,'can_book'=>$canBook,'services'=>$canBook?$services:[]]);
      case 'barber_day_booking':
        // Só permite agendamento para o próprio profissional autenticado.
        // Contas da equipe são configuradas pelo titular; administradores delegados
        // continuam dependentes da permissão específica agendar_meu_dia.
        if ($method!=='POST' || !barber_can_book_today())
            fail('Sem permissão para cadastrar na sua agenda.',403);
        $identity=require_barber();
        $data=input();
        $today=date('Y-m-d');
        if (($data['date']??$today)!==$today) fail('Somente agendamentos para hoje são permitidos.',403);
        if (isset($data['barber_id']) && (int)$data['barber_id']!==(int)$identity['barber_id'])
            fail('Você não pode cadastrar para outro barbeiro.',403);
        $data['barber_id']=(int)$identity['barber_id'];
        $data['date']=$today;
        $result=create_booking($data,true); // Já verifica horário, duração, bloqueios e colisões sob lock.
        json_out(['ok'=>true,'appointment'=>$result],201);
      case 'barber_day_reschedule_slots':
        // Agenda de hoje: somente reservas pertencentes ao profissional da sessão.
        if ($method!=='GET') fail('Método não permitido.',405);
        if (!barber_can_book_today()) fail('Sem permissão para remarcar clientes.',403);
        $identity=require_barber();
        $id=(int)($_GET['id']??0);
        $date=(string)($_GET['date']??'');
        if ($id<=0) fail('Agendamento inválido.');
        $q=db()->prepare('SELECT barber_id,service_id,status,starts_at FROM mt_appointments WHERE id=? LIMIT 1');
        $q->execute([$id]);$appointment=$q->fetch();
        if (!$appointment || (int)$appointment['barber_id']!==(int)$identity['barber_id'])
            fail('Agendamento não encontrado na sua agenda.',404);
        if (substr((string)$appointment['starts_at'],0,10)!==date('Y-m-d'))
            fail('Somente clientes da sua agenda de hoje podem ser remarcados.',403);
        if ($appointment['status']!=='confirmed') fail('Somente agendamentos confirmados podem ser remarcados.',409);
        $slots=available_slots($date,(int)$identity['barber_id'],(int)$appointment['service_id'],$id);
        json_out(['ok'=>true,'date'=>$date,'slots'=>$slots]);
      case 'barber_day_reschedule':
        if (!barber_can_book_today()) fail('Sem permissão para remarcar clientes.',403);
        $identity=require_barber();
        $data=input();
        $id=(int)($data['id']??0);
        if ($id<=0) fail('Agendamento inválido.');
        // Não confiar em barber_id enviado pelo navegador. A identidade vem da sessão.
        $updated=admin_reschedule($data,(int)$identity['barber_id'],true);
        json_out(['ok'=>true,'appointment'=>$updated,'message'=>'Horário remarcado. Avise o cliente sobre a nova data e hora.']);
      case 'barber_day_decide':
        // A decisão de um pedido pendente é exclusiva do profissional autenticado,
        // com permissão de gerenciar seus agendamentos do dia. Nunca confie no
        // barber_id ou no status recebido do navegador.
        if (!barber_can_book_today()) fail('Sem permissão para responder às solicitações.',403);
        $identity=require_barber();
        $data=input();
        $id=(int)($data['id']??0);
        $decision=(string)($data['decision']??'');
        if ($id<=0 || !in_array($decision,['accept','reject'],true)) fail('Decisão inválida.',400);
        $barberId=(int)$identity['barber_id'];
        $pdo=db();
        $lockName='martelli_barber_'.$barberId;
        $q=$pdo->prepare('SELECT GET_LOCK(?,5)');$q->execute([$lockName]);
        if ((int)$q->fetchColumn()!==1) fail('Agenda ocupada. Tente novamente.',409);
        $decisionError=null;
        try {
            $pdo->beginTransaction();
            $q=$pdo->prepare('SELECT id,barber_id,status,starts_at,ends_at FROM mt_appointments WHERE id=? FOR UPDATE');
            $q->execute([$id]);$appointment=$q->fetch();
            if (!$appointment || (int)$appointment['barber_id']!==$barberId)
                throw new DomainException('Solicitação não encontrada na sua agenda.',404);
            if (substr((string)$appointment['starts_at'],0,10)!==date('Y-m-d'))
                throw new DomainException('Somente solicitações de hoje podem ser respondidas.',403);
            if ($appointment['status']!=='pending')
                throw new DomainException('Esta solicitação já foi respondida. Atualize a agenda.',409);
            if ($decision==='accept') {
                // O pedido pendente já reserva o intervalo. Ainda assim, validar
                // conflitos antes de confirmá-lo, sem contabilizar o próprio pedido.
                management_assert_free($barberId,$appointment['starts_at'],$appointment['ends_at']);
                $busyUntil=mt_busy_until_sql();
                $q=$pdo->prepare("SELECT COUNT(*) FROM mt_appointments WHERE id<>? AND barber_id=? AND status NOT IN ('cancelled','no_show') AND starts_at<? AND {$busyUntil}>?");
                $q->execute([$id,$barberId,$appointment['ends_at'],$appointment['starts_at']]);
                if ((int)$q->fetchColumn()>0)
                    throw new DomainException('Horário em conflito com outra reserva. Não foi possível aceitar.',409);
            }
            $newStatus=$decision==='accept'?'confirmed':'cancelled';
            $q=$pdo->prepare("UPDATE mt_appointments SET status=? WHERE id=? AND barber_id=? AND status='pending'");
            $q->execute([$newStatus,$id,$barberId]);
            if ($q->rowCount()!==1)
                throw new DomainException('Solicitação alterada por outra pessoa. Atualize a agenda.',409);
            $pdo->commit();
        } catch (DomainException $e) {
            $decisionError=$e;
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);
        }
        if ($decisionError) fail($decisionError->getMessage(),$decisionError->getCode() ?: 400);
        json_out(['ok'=>true,'id'=>$id,'status'=>$newStatus,
            'message'=>$decision==='accept'?'Solicitação aceita. Horário confirmado.':'Solicitação recusada. O horário foi liberado, caso ainda esteja disponível.']);
      case 'barber_day_status':
        // O barbeiro pode cancelar ou registrar falta SOMENTE para os próprios
        // agendamentos de hoje, anteriormente confirmados. A autorização é
        // conferida novamente em cada requisição e não depende da interface.
        if ($method!=='POST') fail('Método não permitido.',405);
        if (!barber_can_book_today()) fail('Sem permissão para alterar seus agendamentos.',403);
        $identity=require_barber();
        $data=input();
        $id=(int)($data['id']??0);
        $choice=(string)($data['decision']??'');
        if ($id<=0 || !in_array($choice,['cancel','no_show'],true)) fail('Ação inválida.',400);
        $barberId=(int)$identity['barber_id'];
        $pdo=db();
        $lockName='martelli_barber_'.$barberId;
        $lock=$pdo->prepare('SELECT GET_LOCK(?,5)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn()!==1) fail('Agenda ocupada. Tente novamente.',409);
        $failure=null;
        try {
            $pdo->beginTransaction();
            $q=$pdo->prepare('SELECT id,barber_id,starts_at,status FROM mt_appointments WHERE id=? FOR UPDATE');
            $q->execute([$id]);
            $appointment=$q->fetch();
            if (!$appointment || (int)$appointment['barber_id']!==$barberId || substr((string)$appointment['starts_at'],0,10)!==date('Y-m-d'))
                throw new DomainException('Agendamento não encontrado na sua agenda de hoje.',404);
            if ($appointment['status']!=='confirmed')
                throw new DomainException('Somente agendamentos confirmados podem ser cancelados ou marcados como falta.',409);
            // Registro antecipado só é aceito após a confirmação explícita da interface.
            // A regra de acesso ao próprio barbeiro e ao dia atual permanece obrigatória.
            if ($choice==='no_show' && date('Y-m-d H:i:s')<$appointment['starts_at'] && ($data['early_confirm']??null)!==true)
                throw new DomainException('Este horário ainda não começou. Confirme o registro antecipado da falta.',409);
            $status=$choice==='cancel'?'cancelled':'no_show';
            $q=$pdo->prepare("UPDATE mt_appointments SET status=? WHERE id=? AND barber_id=? AND status='confirmed'");
            $q->execute([$status,$id,$barberId]);
            if ($q->rowCount()!==1)
                throw new DomainException('O agendamento já foi alterado. Atualize a agenda.',409);
            $pdo->commit();
        } catch (DomainException $e) {
            $failure=$e;
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);
        }
        if ($failure) fail($failure->getMessage(),$failure->getCode() ?: 400);
        json_out(['ok'=>true,'id'=>$id,'status'=>$status,
          'message'=>$choice==='cancel'?'Agendamento cancelado. O horário foi atualizado.':'Falta registrada. O horário foi atualizado.']);
      case 'barber_day_complete':
        if ($method!=='POST') fail('Método não permitido.',405);
        // Mesmo nível de autorização de cadastrar e remarcar em sua própria agenda.
        if (!barber_can_book_today()) fail('Sem permissão para concluir atendimentos.',403);
        $identity=require_barber();
        $data=input();
        $result=mt_finish_appointment((int)($data['id']??0),(int)$identity['barber_id'],true,($data['early_confirm']??null)===true);
        json_out(['ok'=>true,'appointment'=>$result,'message'=>'Atendimento concluído. Disponibilidade atualizada.']);
      case 'staff_accounts':
        mt_require_owner();
        if ($method!=='GET') fail('Método não permitido.',405);
        $users=db()->query('SELECT u.id,u.barber_id,u.email,u.active,u.created_at,b.name barber_name FROM mt_barber_users u JOIN mt_barbers b ON b.id=u.barber_id ORDER BY b.name,u.id')->fetchAll();
        foreach ($users as &$user) {
            $user['can_book']=setting('staff_booking_'.(int)$user['id'],'1')==='1';
        }
        unset($user);
        $barbers=db()->query('SELECT id,name,active FROM mt_barbers ORDER BY sort_order,id')->fetchAll();
        json_out(['ok'=>true,'accounts'=>$users,'barbers'=>$barbers]);
      case 'staff_account_save':
        mt_require_owner();
        $d=input();
        if (!verify_admin_password((string)($d['admin_password']??''))) fail('Confirme a senha de administrador.',403);
        $barberId=(int)($d['barber_id']??0);
        $allowBooking=$d['can_book']??1;
        if (!in_array($allowBooking,[0,1,'0','1',false,true],true)) fail('Permissão de agendamento inválida.',400);
        $allowBooking=(int)(bool)$allowBooking;
        $email=strtolower(trim((string)($d['email']??'')));
        $password=(string)($d['password']??'');
        if ($barberId<=0 || strlen($email)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL)) fail('Selecione o barbeiro e informe um e-mail válido.');
        if ($password!=='' && (strlen($password)<12 || strlen($password)>256)) fail('A senha deve conter entre 12 e 256 caracteres.');
        $q=db()->prepare('SELECT id FROM mt_barbers WHERE id=? AND active=1');$q->execute([$barberId]);
        if (!$q->fetchColumn()) fail('Selecione um barbeiro ativo.',400);
        $q=db()->prepare('SELECT id FROM mt_admins WHERE email=? LIMIT 1');$q->execute([$email]);
        if ($q->fetchColumn()) fail('E-mail já utilizado por um administrador.',409);
        $q=db()->prepare('SELECT id FROM mt_barber_users WHERE barber_id=? LIMIT 1');$q->execute([$barberId]);
        $existingId=(int)($q->fetchColumn() ?: 0);
        $q=db()->prepare('SELECT id FROM mt_barber_users WHERE email=? AND barber_id<>? LIMIT 1');$q->execute([$email,$barberId]);
        if ($q->fetchColumn()) fail('E-mail já utilizado por outro barbeiro.',409);
        if ($existingId) {
            if ($password!=='') {
                $q=db()->prepare('UPDATE mt_barber_users SET email=?,password_hash=? WHERE id=?');
                $q->execute([$email,password_hash($password,PASSWORD_DEFAULT),$existingId]);
            } else {
                $q=db()->prepare('UPDATE mt_barber_users SET email=? WHERE id=?');$q->execute([$email,$existingId]);
            }
        } else {
            if ($password==='') fail('Defina uma senha para a nova conta (mínimo 12 caracteres).');
            $q=db()->prepare('INSERT INTO mt_barber_users (barber_id,email,password_hash,active) VALUES (?,?,?,1)');
            $q->execute([$barberId,$email,password_hash($password,PASSWORD_DEFAULT)]);
        }
        $staffId=$existingId ?: (int)db()->lastInsertId();
        if ($staffId<=0) fail('Não foi possível identificar a conta de barbeiro.',500);
        $q=db()->prepare('INSERT INTO mt_settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
        $q->execute(['staff_booking_'.$staffId,$allowBooking?'1':'0']);
        json_out(['ok'=>true,'message'=>$existingId?'Acesso atualizado.':'Acesso criado.']);
      case 'staff_account_status':
        mt_require_owner();
        $d=input();
        if (!verify_admin_password((string)($d['admin_password']??''))) fail('Confirme a senha de administrador.',403);
        $id=(int)($d['id']??0);$active=($d['active']??null);
        if ($id<=0 || !in_array($active,[0,1,'0','1'],true)) fail('Dados inválidos.',400);
        $q=db()->prepare('UPDATE mt_barber_users SET active=? WHERE id=?');$q->execute([(int)$active,$id]);
        json_out(['ok'=>true,'message'=>(int)$active?'Acesso ativado.':'Acesso desativado.']);
      case 'admin_dashboard':
        mt_require_any(['agenda','cadastros','horarios','configuracoes']);
        $sql='SELECT a.id,a.client_id,a.barber_id,a.service_id,a.starts_at,a.ends_at,a.price,a.status,a.is_extended,a.notes,
             c.name client_name,c.phone client_phone,b.name barber_name,s.name service_name
             FROM mt_appointments a INNER JOIN mt_clients c ON c.id=a.client_id
             INNER JOIN mt_barbers b ON b.id=a.barber_id INNER JOIN mt_services s ON s.id=a.service_id
             ORDER BY a.starts_at DESC LIMIT 800';
        $a=mt_can('agenda') ? db()->query($sql)->fetchAll() : [];
        json_out(['ok'=>true,'appointments'=>$a,'clients'=>mt_can_any(['agenda','cadastros']) ? db()->query("SELECT c.id,c.name,c.phone,c.notes,c.created_at,CASE WHEN c.cpf IS NULL THEN 0 ELSE 1 END AS cpf_registered FROM mt_clients c WHERE NOT EXISTS (SELECT 1 FROM mt_settings s WHERE s.`key`=CONCAT('archived_client_',c.id) AND s.`value`='1') ORDER BY c.name")->fetchAll() : [],
          'services'=>fetch_all('mt_services','sort_order'),'barbers'=>fetch_all('mt_barbers','sort_order'),
          'hours'=>fetch_all('mt_business_hours','weekday'),
          'settings'=>mt_can('configuracoes') ? db()->query('SELECT `key`,`value` FROM mt_settings')->fetchAll() : [],
          'admin_name'=>$_SESSION['admin_name']??'Administrador']);
      case 'admin_day':
        mt_require('agenda');
        header('Cache-Control: private, no-store');
        if ($method !== 'GET') fail('Método não permitido.', 405);
        $date=(string)($_GET['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) fail('Data inválida.', 400);
        [$year,$month,$day]=array_map('intval',explode('-', $date));
        if ($year<2000 || $year>2100 || !checkdate($month,$day,$year)) fail('Data inválida.', 400);
        $dateEnd=(new DateTimeImmutable($date, new DateTimeZone('America/Sao_Paulo')))->modify('+1 day')->format('Y-m-d');
        $finishedAt=mt_finished_at_select('a');
        $stmt=db()->prepare("SELECT a.id,a.client_id,a.barber_id,a.service_id,a.starts_at,a.ends_at,{$finishedAt},a.price,a.status,a.is_extended,a.notes,
             c.name client_name,c.phone client_phone,b.name barber_name,s.name service_name
             FROM mt_appointments a INNER JOIN mt_clients c ON c.id=a.client_id
             INNER JOIN mt_barbers b ON b.id=a.barber_id INNER JOIN mt_services s ON s.id=a.service_id
             WHERE a.starts_at >= ? AND a.starts_at < ? ORDER BY a.starts_at ASC,a.barber_id ASC,a.id ASC");
        $stmt->execute([$date.' 00:00:00',$dateEnd.' 00:00:00']);
        $appointments=$stmt->fetchAll();
        $services=db()->query('SELECT id,name,price,duration_minutes FROM mt_services WHERE active=1 ORDER BY sort_order,id')->fetchAll();
        $barbers=db()->query('SELECT id FROM mt_barbers WHERE active=1 ORDER BY sort_order,id')->fetchAll();
        $grids=[];
        foreach ($barbers as $b) {
            $id=(int)$b['id'];
            $grids[(string)$id]=day_slot_matrix($date,$id,$appointments,$services);
        }
        json_out(['ok'=>true,'date'=>$date,'appointments'=>$appointments,'slots_by_barber'=>$grids]);
      case 'admin_appointments':
        mt_require('agenda');
        header('Cache-Control: private, no-store');
        if ($method !== 'GET') fail('Método não permitido.', 405);
        // Paginação no servidor: a lista completa não depende do limite do dashboard.
        $pageRaw=(string)($_GET['page']??'1');
        if (!preg_match('/^[1-9][0-9]{0,6}$/D',$pageRaw)) fail('Página inválida.',400);
        $page=(int)$pageRaw;
        $perPage=100;
        $total=(int)db()->query('SELECT COUNT(*) FROM mt_appointments')->fetchColumn();
        $pages=max(1,(int)ceil($total/$perPage));
        if ($page>$pages) $page=$pages;
        $stmt=db()->prepare('SELECT a.id,a.client_id,a.barber_id,a.service_id,a.starts_at,a.ends_at,a.price,a.status,a.is_extended,a.notes,
             c.name client_name,c.phone client_phone,b.name barber_name,s.name service_name
             FROM mt_appointments a INNER JOIN mt_clients c ON c.id=a.client_id
             INNER JOIN mt_barbers b ON b.id=a.barber_id INNER JOIN mt_services s ON s.id=a.service_id
             ORDER BY a.starts_at DESC,a.id DESC LIMIT ? OFFSET ?');
        $stmt->bindValue(1,$perPage,PDO::PARAM_INT);
        $stmt->bindValue(2,($page-1)*$perPage,PDO::PARAM_INT);
        $stmt->execute();
        json_out(['ok'=>true,'appointments'=>$stmt->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>$perPage]);
      case 'admin_clear_day':
        fail('A limpeza em massa de agendamentos foi desativada.', 404);
      case 'admin_reschedule_slots':
        mt_require('agenda');
        if ($method !== 'GET') fail('Método não permitido.',405);
        $id=(int)($_GET['id']??0);
        $date=(string)($_GET['date']??'');
        if ($id<=0) fail('Reserva inválida.');
        $q=db()->prepare('SELECT id,barber_id,service_id,status FROM mt_appointments WHERE id=? LIMIT 1');
        $q->execute([$id]); $existing=$q->fetch();
        if (!$existing) fail('Reserva não encontrada.',404);
        if ($existing['status']!=='confirmed') fail('Somente reservas confirmadas podem ser remarcadas.',409);
        $slots=available_slots($date,(int)$existing['barber_id'],(int)$existing['service_id'],$id);
        json_out(['ok'=>true,'slots'=>$slots,'date'=>$date]);
      case 'admin_reschedule':
        mt_require('agenda');
        $updated=admin_reschedule(input());
        json_out(['ok'=>true,'appointment'=>$updated,'message'=>'Agendamento remarcado com sucesso.']);
      case 'admin_users':
        mt_require_owner();
        $users=db()->query('SELECT a.id,a.name,a.email,a.created_at,p.enabled,p.permissions,p.barber_id FROM mt_admins a LEFT JOIN mt_admin_access p ON p.admin_id=a.id ORDER BY a.id')->fetchAll();
        $ownerId=(int)db()->query('SELECT admin_id FROM mt_access_owner WHERE id=1')->fetchColumn();
        foreach($users as &$user) {
            $user['is_owner']=(int)$user['id']===$ownerId;
            $user['enabled']=$user['is_owner']?1:(int)($user['enabled']??0);
            $user['permissions']=$user['is_owner']?MT_ACCESS_KEYS:(json_decode((string)($user['permissions']??'[]'),true)?:[]);
        } unset($user);
        json_out(['ok'=>true,'admins'=>$users,'barbers'=>fetch_all('mt_barbers','sort_order'),'current_admin_id'=>(int)$_SESSION['admin_id']]);
      case 'admin_access_save':
        mt_require_owner();
        $d=input();
        if (!verify_admin_password((string)($d['current_password']??''))) fail('Confirme sua senha para alterar os acessos.',403);
        $id=(int)($d['id']??0);
        $ownerId=(int)db()->query('SELECT admin_id FROM mt_access_owner WHERE id=1')->fetchColumn();
        if ($id<=0 || $id===$ownerId) fail('Não é permitido modificar o acesso total do responsável.',403);
        $q=db()->prepare('SELECT id FROM mt_admins WHERE id=?');$q->execute([$id]);
        if (!$q->fetchColumn()) fail('Conta não encontrada.',404);
        $enabled=($d['enabled']??false)===true || ($d['enabled']??null)===1;
        $permissions=$d['permissions']??[];
        if (!is_array($permissions) || count($permissions)>count(MT_ACCESS_KEYS)) fail('Permissões inválidas.');
        foreach($permissions as $permission) if (!is_string($permission) || !in_array($permission, MT_ACCESS_KEYS,true)) fail('Permissão inválida.');
        $permissions=array_values(array_unique($permissions));
        $barberId=(int)($d['barber_id']??0);
        if (in_array('agendar_meu_dia',$permissions,true) && !in_array('minha_agenda',$permissions,true))
            fail('Para cadastrar na agenda individual, libere também Minha agenda de hoje.',400);
        if (in_array('minha_agenda',$permissions,true)) {
            if ($barberId<=0) fail('Vincule a conta a um barbeiro para liberar a agenda individual.');
            $q=db()->prepare('SELECT id FROM mt_barbers WHERE id=? AND active=1');$q->execute([$barberId]);
            if (!$q->fetchColumn()) fail('Barbeiro não encontrado ou inativo.');
        } else $barberId=0;
        $q=db()->prepare('INSERT INTO mt_admin_access (admin_id,enabled,permissions,barber_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),permissions=VALUES(permissions),barber_id=VALUES(barber_id)');
        $q->execute([$id,$enabled?1:0,json_encode($permissions,JSON_UNESCAPED_UNICODE),$barberId?:null]);
        json_out(['ok'=>true,'message'=>'Permissões atualizadas. A mudança vale na próxima requisição.']);
      case 'admin_account_delete':
        // Exclusão de login administrativo delegada exclusivamente ao titular.
        // NUNCA exclui cliente, barbeiro, agendamento, histórico financeiro ou a conta proprietária.
        mt_require_owner();
        $data=input();
        $targetId=(int)($data['id']??0);
        $confirmEmail=strtolower(trim((string)($data['confirm_email']??'')));
        $password=(string)($data['current_password']??'');
        if ($targetId<=0 || $targetId===(int)($_SESSION['admin_id']??0))
            fail('Não é permitido excluir a conta principal.',403);
        if ($confirmEmail==='' || strlen($confirmEmail)>190 || ($data['confirmation']??'')!=='EXCLUIR')
            fail('Informe o e-mail da conta e a confirmação de exclusão.',400);
        if (!verify_admin_password($password))
            fail('Senha do administrador principal incorreta.',403);
        $pdo=db();
        try {
            $pdo->beginTransaction();
            // Bloqueia a linha do proprietário para evitar exclusão simultânea de uma conta protegida.
            $q=$pdo->query('SELECT admin_id FROM mt_access_owner WHERE id=1 FOR UPDATE');
            $ownerId=(int)$q->fetchColumn();
            if ($ownerId<=0 || $targetId===$ownerId) {
                $pdo->rollBack();fail('A conta principal não pode ser excluída.',403);
            }
            $q=$pdo->prepare('SELECT id,email FROM mt_admins WHERE id=? FOR UPDATE');
            $q->execute([$targetId]);$target=$q->fetch();
            if (!$target) {$pdo->rollBack();fail('Esta conta não existe mais. Atualize a lista.',404);}
            if (!hash_equals(strtolower(trim((string)$target['email'])),$confirmEmail)) {
                $pdo->rollBack();fail('O e-mail de confirmação não corresponde à conta selecionada.',400);
            }
            // mt_admin_access tem ON DELETE CASCADE; as demais tabelas não são afetadas.
            $q=$pdo->prepare('DELETE FROM mt_admins WHERE id=? AND id<>?');
            $q->execute([$targetId,$ownerId]);
            if ($q->rowCount()!==1) {
                $pdo->rollBack();fail('Não foi possível excluir a conta.',409);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ((string)$e->getCode()==='23000') fail('A conta possui vínculos protegidos. Desative o acesso ou revise os vínculos antes de excluir.',409);
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        json_out(['ok'=>true,'message'=>'Conta de acesso excluída. Clientes, agendamentos e histórico mantidos.']);
      case 'admin_user_create':
        mt_require_owner();
        $data = input();
        $name = trim((string)($data['name'] ?? ''));
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $password = (string)($data['password'] ?? '');
        $confirmation = (string)($data['confirm_password'] ?? '');
        $currentPassword = (string)($data['current_password'] ?? '');
        if (text_len($name)<2 || text_len($name)>120 || strlen($email)>190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Informe nome e e-mail válidos.');
        if (strlen($password)<12 || strlen($password)>256 || !hash_equals($password,$confirmation)) fail('A nova senha deve ter 12 a 256 caracteres e ser confirmada.');
        if (!verify_admin_password($currentPassword)) fail('A sua senha atual não foi confirmada.',403);
        $q=db()->prepare('SELECT COUNT(*) FROM mt_admins WHERE email=?');
        $q->execute([$email]); if ((int)$q->fetchColumn()>0) fail('Já existe um administrador com este e-mail.',409);
        try {
          $q=db()->prepare('INSERT INTO mt_admins (name,email,password_hash) VALUES (?,?,?)');
          $q->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
        } catch (PDOException $e) {
          if ((string)$e->getCode()==='23000') fail('Este e-mail já está cadastrado.',409);
          throw $e;
        }
        json_out(['ok'=>true,'message'=>'Administrador cadastrado com sucesso.'],201);
      case 'admin_password_change':
        require_admin();
        $data=input();
        $current=(string)($data['current_password']??'');
        $new=(string)($data['new_password']??'');
        $confirmed=(string)($data['confirm_password']??'');
        if (!verify_admin_password($current)) fail('A senha atual está incorreta.',403);
        if (strlen($new)<12 || strlen($new)>256 || !hash_equals($new,$confirmed) || hash_equals($current,$new)) fail('Nova senha inválida: utilize 12 a 256 caracteres, diferente da atual, e confirme.');
        $q=db()->prepare('UPDATE mt_admins SET password_hash=? WHERE id=?');
        $q->execute([password_hash($new,PASSWORD_DEFAULT), (int)$_SESSION['admin_id']]);
        session_regenerate_id(true);
        json_out(['ok'=>true,'message'=>'Sua senha foi alterada.']);
      case 'admin_save':
        require_admin(); $data=input();
        $entity=(string)($data['entity']??'');
        if ($entity==='appointment_status') mt_require('agenda');
        elseif (in_array($entity,['clients','services','barbers'],true)) mt_require('cadastros');
        elseif ($entity==='hours') mt_require('horarios');
        elseif ($entity==='settings') mt_require('configuracoes');
        else fail('Operação não autorizada.',403);
        $result=admin_save($entity,$data);
        json_out(['ok'=>true,'result'=>$result]);
      case 'admin_delete':
        mt_require('cadastros'); $data=input(); $entity=(string)($data['entity']??'');
        if (!in_array($entity,['services','barbers','clients'],true)) fail('Operação não permitida.');
        if ($entity==='clients') {
            $clientId=(int)($data['id']??0);
            $q=db()->prepare('SELECT id FROM mt_clients WHERE id=?');$q->execute([$clientId]);
            if (!$q->fetchColumn()) fail('Cliente não encontrado.',404);
            $q=db()->prepare('INSERT INTO mt_settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
            $q->execute(['archived_client_'.$clientId,'1']);
            json_out(['ok'=>true,'message'=>'Cliente removido da lista ativa. Agendamentos preservados.']);
        }
        $table=['services'=>'mt_services','barbers'=>'mt_barbers','clients'=>'mt_clients'][$entity];
        $stmt=db()->prepare("DELETE FROM `$table` WHERE id=?");
        $deletedId=(int)($data['id']??0);
        $stmt->execute([$deletedId]);
        if ($entity==='barbers' && $deletedId>0 && $stmt->rowCount()>0) {
            $remove=db()->prepare('DELETE FROM mt_settings WHERE `key`=?');
            $remove->execute(['barber_whatsapp_'.$deletedId]);
        }
        json_out(['ok'=>true]);
      case 'admin_booking':
        mt_require('agenda');
        $result=create_booking(input(),true);
        json_out(['ok'=>true,'appointment'=>$result],201);
      default: fail('Rota não encontrada.',404);
    }
} catch (\Martelli\Domain\VipBookingLoginRequired $e) {
    // A transação já foi revertida e o lock do barbeiro foi liberado no finally.
    // Não divulga números, CPF ou detalhes da agenda na URL de login.
    $message = !$e->contactMatches
        ? 'Existe uma conta VIP vinculada a estes dados. Entre no Login para confirmar seu acesso.'
        : ($e->hasBookedCuts
            ? 'Este CPF tem cortes VIP agendados. Entre na sua conta para consultar ou remarcar seus horários.'
            : 'Este CPF possui um plano VIP ativo. Entre na sua conta para agendar seus cortes.');
    json_out(['ok'=>false,'code'=>'vip_active_login_required','error'=>$message,'login_url'=>'/vip-entrar.php'],409);
} catch (\Martelli\Domain\ClientConflict $e) {
    json_out(['ok'=>false,'code'=>'contact_verification_required','error'=>$e->getMessage(),'login_url'=>'/vip-entrar.php'],409);
} catch(PDOException $e) {
    error_log('Martelli database: '.$e->getMessage());
    if (str_starts_with((string)$e->getCode(),'23')) fail('Registro vinculado a outros dados. Desative em vez de excluir.',409);
    fail('Erro interno do banco de dados. Confira a configuração e os logs PHP.',503);
} catch(Throwable $e) {
    if ($e instanceof RuntimeException && str_contains($e->getMessage(),'Configure app/config.php')) fail('Banco ainda não configurado no servidor.',503);
    error_log('Martelli app: '.$e->getMessage());
    fail('Erro interno do servidor. Confira os logs PHP.',500);
}
/** Valida dia solicitado no calendário público. */
function validate_day(string $date): DateTimeImmutable {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('America/Sao_Paulo'));
    if (!$d || $d->format('Y-m-d')!==$date) fail('Data inválida.');
    $today=new DateTimeImmutable('today',new DateTimeZone('America/Sao_Paulo'));
    if ($d<$today || $d>$today->modify('+30 days')) fail('Escolha um dia nos próximos 30 dias.');
    return $d;
}
/** Confere serviço e barbeiro ativos antes do agendamento. */
function service_and_barber(int $barber,int $service): array {
    $a=db()->prepare('SELECT * FROM mt_barbers WHERE id=? AND active=1'); $a->execute([$barber]); $b=$a->fetch();
    $a=db()->prepare('SELECT * FROM mt_services WHERE id=? AND active=1'); $a->execute([$service]); $s=$a->fetch();
    if (!$s || !$b) fail('Serviço ou profissional indisponível.');
    if ((int)$s['duration_minutes'] < 5 || (int)$s['duration_minutes'] > 480) fail('Serviço com duração inválida.');
    return [$b,$s];
}
/** Busca agendamentos existentes do profissional na data. */
function daily_schedule(string $date): array {
    $d=validate_day($date); $wd=(int)$d->format('w');
    $st=db()->prepare('SELECT * FROM mt_business_hours WHERE weekday=?'); $st->execute([$wd]); $h=$st->fetch();
    if (!$h || !(int)$h['is_open']) return [];
    return $h;
}
/** Restrição atômica: só o próprio barbeiro e somente uma reserva confirmada
 * do dia corrente pode ser concluída. Registro antes da hora requer confirmação explícita.
 */
function mt_finish_appointment(int $id,int $barberId,bool $todayOnly,bool $allowEarly=false): array {
    if ($id<=0 || $barberId<=0) fail('Agendamento inválido.',400);
    if (!mt_has_finished_at()) fail('Instale primeiro a migração SQL da versão V16.',503);
    $pdo=db();
    $lockName='martelli_barber_'.$barberId;
    $q=$pdo->prepare('SELECT GET_LOCK(?,5)'); $q->execute([$lockName]);
    if ((int)$q->fetchColumn()!==1) fail('Agenda ocupada. Tente novamente.',409);
    $failure=null;
    try {
        $pdo->beginTransaction();
        $q=$pdo->prepare('SELECT id,barber_id,starts_at,ends_at,status FROM mt_appointments WHERE id=? FOR UPDATE');
        $q->execute([$id]); $a=$q->fetch();
        if (!$a || (int)$a['barber_id']!==$barberId) throw new DomainException('Agendamento não encontrado na sua agenda.',404);
        if ($todayOnly && substr((string)$a['starts_at'],0,10)!==date('Y-m-d'))
            throw new DomainException('Somente atendimentos de hoje podem ser concluídos.',403);
        if ($a['status']!=='confirmed') throw new DomainException('Somente atendimentos confirmados podem ser concluídos.',409);
        $now=date('Y-m-d H:i:s');
        if ($now < $a['starts_at'] && (!$todayOnly || !$allowEarly))
            throw new DomainException('Horário ainda não iniciado. Confirme a conclusão antecipada na sua agenda.',409);
        $q=$pdo->prepare("UPDATE mt_appointments SET status='completed', finished_at=? WHERE id=? AND status='confirmed'");
        $q->execute([$now,$id]);
        if ($q->rowCount()!==1) throw new DomainException('Atendimento alterado. Atualize a página.',409);
        $pdo->commit();
        return ['id'=>$id,'finished_at'=>$now,'released_early'=>$now < $a['ends_at']];
    } catch (DomainException $e) {
        $failure=$e;
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $q=$pdo->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lockName]);
    }
    if ($failure) fail($failure->getMessage(),$failure->getCode() ?: 400);
    fail('Não foi possível concluir o atendimento.',500);
}

/** Calcula a disponibilidade respeitando serviço, horário e reservas. */
function available_slots(string $date,int $barber,int $service,int $excludeId=0): array {
    validate_day($date); [, $s]=service_and_barber($barber,$service);
    $h=daily_schedule($date); if (!$h) return [];
    $start=substr($h['open_time'],0,5); $normal=substr($h['close_time'],0,5);
    $end=substr($h['extended_close_time']?:$h['close_time'],0,5);
    if ($end<$normal) $end=$normal;
    $busyUntil=mt_busy_until_sql();
    $query=db()->prepare("SELECT starts_at,{$busyUntil} AS ends_at FROM mt_appointments WHERE id<>? AND barber_id=? AND status NOT IN ('cancelled','no_show') AND starts_at<? AND {$busyUntil}>?");
    $query->execute([$excludeId,$barber,$date.' 23:59:59',$date.' 00:00:00']);
    $busy=array_merge($query->fetchAll(),management_blocks($barber));
    $startTime=strtotime($date.' '.$start.':00'); $endTime=strtotime($date.' '.$end.':00');
    $normalEnd=strtotime($date.' '.$normal.':00');
    $duration=(int)$s['duration_minutes']*60; $now=time()+15*60; $list=[];
    for ($t=$startTime; $t+$duration<=$endTime; $t+=15*60) {
       if ($t<=$now) continue;
       $a=date('Y-m-d H:i:s',$t); $b=date('Y-m-d H:i:s',$t+$duration); $collision=false;
       foreach ($busy as $o) if ($a<$o['ends_at'] && $b>$o['starts_at']) { $collision=true; break; }
       if (!$collision) $list[]=['time'=>date('H:i',$t),'extended'=>$t+$duration>$normalEnd];
    }
    return $list;
}

/** Grade de horários do backup V7, adaptada à autorização do V10.
 * Compartilha as regras de disponibilidade com o agendamento público e evita inferir
 * disponibilidade apenas pela aparência dos blocos na tela.
 */
function day_slot_matrix(string $date, int $barberId, array $appointments, array $services): array {
    $today=date('Y-m-d');
    $limit=(new DateTimeImmutable('today',new DateTimeZone('America/Sao_Paulo')))->modify('+30 days')->format('Y-m-d');
    if ($date<$today || $date>$limit) return [];
    $hours=daily_schedule($date);
    if (!$hours) return [];
    $open=substr((string)$hours['open_time'],0,5);
    $normal=substr((string)$hours['close_time'],0,5);
    $end=substr((string)($hours['extended_close_time']?:$hours['close_time']),0,5);
    if ($end<$normal) $end=$normal;
    $available=[];
    foreach ($services as $service) {
        foreach (available_slots($date,$barberId,(int)$service['id']) as $slot) {
            $available[$slot['time']][]=(int)$service['id'];
        }
    }
    $startTs=strtotime($date.' '.$open.':00');
    $endTs=strtotime($date.' '.$end.':00');
    $slots=[];
    for ($ts=$startTs;$ts<$endTs;$ts+=900) {
        $from=date('Y-m-d H:i:s',$ts);
        $to=date('Y-m-d H:i:s',$ts+900);
        $busy=false;
        foreach ($appointments as $a) {
            if (isset($a['barber_id']) && (int)$a['barber_id']!==$barberId) continue;
            if (in_array($a['status'],['cancelled','no_show'],true)) continue;
            $effectiveEnd=$a['ends_at'];
            if ($a['status']==='completed' && !empty($a['finished_at'])) {
                $effectiveEnd=min($effectiveEnd,max($a['starts_at'],$a['finished_at']));
            }
            if ($from<$effectiveEnd && $to>$a['starts_at']) {$busy=true;break;}
        }
        $time=date('H:i',$ts);
        $ids=$available[$time]??[];
        $state=$busy?'occupied':($ts<=time()+900?'past':($ids?'available':'unavailable'));
        $slots[]=['time'=>$time,'state'=>$state,'service_ids'=>$state==='available'?$ids:[]];
    }
    return $slots;
}

/** Cria reserva com validações de CPF, horário e concorrência. */
function create_booking(array $data,bool $admin): array {
    if (!$admin && setting('bookings_enabled')!=='1') fail('Agendamentos online estão desativados.',403);
    if (!$admin && !empty($data['website'])) fail('Solicitação inválida.',400);
    if (!$admin && ($data['privacy_ack']??false) !== true) fail('Leia e confirme a ciência da política de privacidade.');
    if (!$admin) {
      $attempts=$_SESSION['booking_attempts']??[];
      $attempts=array_values(array_filter($attempts,fn($t)=>$t>time()-600));
      if(count($attempts)>=4) fail('Limite de solicitações atingido. Tente novamente mais tarde.',429);
      $attempts[]=time(); $_SESSION['booking_attempts']=$attempts;
    }
    $name=trim((string)($data['name']??'')); $phone=normalize_br_phone((string)($data['phone']??''));
    mt_cpf_schema();$cpf=mt_cpf((string)($data['cpf']??''));
    $notes=trim((string)($data['notes']??'')); $barber=(int)($data['barber_id']??0); $service=(int)($data['service_id']??0);
    $date=(string)($data['date']??''); $time=(string)($data['time']??'');
    if (text_len($name)<2||text_len($name)>120) fail('Informe um nome válido.');
    if ($phone==='') fail('Informe um WhatsApp válido com DDD, com ou sem +55.');
    if (text_len($notes)>500) fail('Observação muito longa.');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$time)) fail('Horário inválido.');
    $minutes=(int)substr($time,3,2); if ($minutes%15!==0) fail('Escolha um horário da lista.');
    $d=validate_day($date); [$b, $s]=service_and_barber($barber,$service);
    $h=daily_schedule($date); if (!$h) fail('A barbearia não atende neste dia.');
    $start=new DateTimeImmutable($date.' '.$time.':00',new DateTimeZone('America/Sao_Paulo'));
    $end=$start->modify('+'.(int)$s['duration_minutes'].' minutes');
    $normal=substr($h['close_time'],0,5);
    $limit=substr($h['extended_close_time']?:$h['close_time'],0,5);
    if ($limit<$normal) $limit=$normal;
    if ($start->getTimestamp()<=time()+15*60 || $start->format('H:i')<substr($h['open_time'],0,5) ||
       $end->format('Y-m-d')!==$date || $end->format('H:i')>$limit) fail('Horário fora do atendimento.');
    $pdo=db(); $lockName='martelli_barber_'.$barber;
    $stmt=$pdo->prepare('SELECT GET_LOCK(?,5)'); $stmt->execute([$lockName]);
    if ((int)$stmt->fetchColumn()!==1) fail('Agenda ocupada, tente novamente.',409);
    try {
        $pdo->beginTransaction();
        management_assert_free($barber,$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'));
        $busyUntil=mt_busy_until_sql();
        $q=$pdo->prepare("SELECT COUNT(*) FROM mt_appointments WHERE barber_id=? AND status NOT IN ('cancelled','no_show') AND starts_at<? AND {$busyUntil}>?");
        $q->execute([$barber,$end->format('Y-m-d H:i:s'),$start->format('Y-m-d H:i:s')]);
        if ((int)$q->fetchColumn()>0) { $pdo->rollBack(); fail('Esse horário acabou de ser reservado.',409); }
        // A sessão VIP é verificada no servidor; o navegador não envia client_id.
        // Só o titular autenticado pode atualizar seu WhatsApp ao agendar.
        $verifiedClientId=0;
        if (!empty($_SESSION['vip_client_id'])) {
            require_once __DIR__.'/../app/vip_auth.php';
            $identity=vip_auth_identity();
            if ($identity && (int)($identity['must_change_password']??0)===0) {
                $verifiedClientId=(int)$identity['client_id'];
            }
        }
        $client=mt_cpf_client($pdo,$name,$phone,$cpf,$verifiedClientId,!$admin);
        $q=$pdo->prepare('DELETE FROM mt_settings WHERE `key`=?');$q->execute(['archived_client_'.$client]);
        // A partir das 18h a solicitação do cliente reserva o slot, mas precisa
        // de aprovação explícita no painel. A equipe pode criar reservas confirmadas.
        $requiresApproval = !$admin && $time >= '18:00';
        $bookingStatus = $requiresApproval ? 'pending' : 'confirmed';
        $q=$pdo->prepare('INSERT INTO mt_appointments (client_id,barber_id,service_id,starts_at,ends_at,price,is_extended,notes,status) VALUES(?,?,?,?,?,?,?,?,?)');
        $q->execute([$client,$barber,$service,$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),
            $s['price'],(int)($end->format('H:i')>$normal),$notes?:null,$bookingStatus]);
        $id=(int)$pdo->lastInsertId(); $pdo->commit();
        // Após a confirmação, informa o contato do barbeiro escolhido, sem expor
        // o telefone particular no catálogo público. Se não houver, usa o
        // WhatsApp geral da barbearia (quando cadastrado).
        $barberContact = whatsapp_for_link(setting('barber_whatsapp_'.$barber));
        $businessContact = whatsapp_for_link(setting('whatsapp'));
        return ['id'=>$id,'date'=>$date,'time'=>$time,'service'=>$s['name'],
            'price'=>$s['price'],'barber_name'=>$b['name'],
            'status'=>$bookingStatus,'requires_approval'=>$requiresApproval,
            'whatsapp'=>$barberContact ?: $businessContact,
            'whatsapp_recipient'=>$barberContact ? 'barber' : ($businessContact ? 'business' : 'none')];
    } finally {
        if($pdo->inTransaction()) $pdo->rollBack();
        $q=$pdo->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lockName]);
    }
}
/** Atualiza somente data e horário de uma reserva confirmada.
 * Usa o mesmo lock por barbeiro da criação de reservas para impedir conflitos simultâneos.
 */
function admin_reschedule(array $data, ?int $requiredBarberId=null, bool $requireCurrentDay=false): array {
    $id=(int)($data['id']??0);
    $date=(string)($data['date']??'');
    $time=(string)($data['time']??'');
    if ($id<=0) fail('Reserva inválida.');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D',$time) || (int)substr($time,3,2)%15!==0)
        fail('Selecione um horário válido em intervalos de 15 minutos.');
    validate_day($date);
    $pdo=db();
    $q=$pdo->prepare('SELECT barber_id,status FROM mt_appointments WHERE id=?');
    $q->execute([$id]); $current=$q->fetch();
    if (!$current || ($requiredBarberId!==null && (int)$current['barber_id']!==$requiredBarberId))
        fail('Agendamento não encontrado na sua agenda.',404);
    if ($requireCurrentDay) {
        $q=$pdo->prepare('SELECT starts_at FROM mt_appointments WHERE id=? LIMIT 1');
        $q->execute([$id]);
        $startsAt=(string)$q->fetchColumn();
        if (substr($startsAt,0,10)!==date('Y-m-d'))
            fail('Somente clientes de hoje podem ser remarcados.',403);
    }
    if ($current['status']!=='confirmed') fail('Somente agendamentos confirmados podem ser remarcados.',409);
    $barber=(int)$current['barber_id'];
    $lockName='martelli_barber_'.$barber;
    $q=$pdo->prepare('SELECT GET_LOCK(?,5)');$q->execute([$lockName]);
    if ((int)$q->fetchColumn()!==1) fail('Agenda ocupada. Tente novamente.',409);
    try {
        $pdo->beginTransaction();
        $q=$pdo->prepare('SELECT barber_id,service_id,status,starts_at FROM mt_appointments WHERE id=? FOR UPDATE');
        $q->execute([$id]);$existing=$q->fetch();
        if (!$existing || $existing['status']!=='confirmed' || (int)$existing['barber_id']!==$barber
            || ($requiredBarberId!==null && (int)$existing['barber_id']!==$requiredBarberId)
            || ($requireCurrentDay && substr((string)$existing['starts_at'],0,10)!==date('Y-m-d')))
            fail('Este agendamento foi alterado ou não pertence à sua agenda de hoje.',409);
        [, $service]=service_and_barber($barber,(int)$existing['service_id']);
        $hours=daily_schedule($date);
        if (!$hours) fail('A barbearia não atende nessa data.');
        $start=new DateTimeImmutable($date.' '.$time.':00',new DateTimeZone('America/Sao_Paulo'));
        $end=$start->modify('+'.(int)$service['duration_minutes'].' minutes');
        $open=substr((string)$hours['open_time'],0,5);
        $normal=substr((string)$hours['close_time'],0,5);
        $limit=substr((string)($hours['extended_close_time']?:$hours['close_time']),0,5);
        if ($limit<$normal) $limit=$normal;
        if ($start->getTimestamp()<=time()+15*60 || $time<$open ||
            $end->format('Y-m-d')!==$date || $end->format('H:i')>$limit)
            fail('Horário fora do atendimento ou próximo demais do horário atual.');
        $newStart=$start->format('Y-m-d H:i:s');
        $newEnd=$end->format('Y-m-d H:i:s');
        management_assert_free($barber,$newStart,$newEnd);
        $busyUntil=mt_busy_until_sql();
        $q=$pdo->prepare("SELECT COUNT(*) FROM mt_appointments WHERE id<>? AND barber_id=? AND status NOT IN ('cancelled','no_show') AND starts_at<? AND {$busyUntil}>?");
        $q->execute([$id,$barber,$newEnd,$newStart]);
        if ((int)$q->fetchColumn()>0) fail('Este horário foi ocupado por outra reserva. Escolha outro.',409);
        $q=$pdo->prepare('UPDATE mt_appointments SET starts_at=?,ends_at=?,is_extended=? WHERE id=?');
        $q->execute([$newStart,$newEnd,(int)($end->format('H:i')>$normal),$id]);
        $pdo->commit();
        return ['id'=>$id,'date'=>$date,'time'=>$time];
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);
    }
}
/** Salva alterações autorizadas do painel. */
function admin_save(string $entity,array $d): array {
    $pdo=db(); $id=(int)($d['id']??0);
    if ($entity==='appointment_status') {
        $status=(string)($d['status']??'');
        if (!in_array($status,['confirmed','completed','cancelled','no_show'],true)) fail('Status inválido.');
        if ($id <= 0) fail('Agendamento inválido.');
        if ($status==='completed') {
            $q=$pdo->prepare('SELECT barber_id FROM mt_appointments WHERE id=?');
            $q->execute([$id]); $barberId=(int)($q->fetchColumn()?:0);
            if (!$barberId) fail('Agendamento não encontrado.',404);
            return mt_finish_appointment($id,$barberId,false);
        }
        $q=$pdo->prepare('SELECT barber_id,starts_at,ends_at,status FROM mt_appointments WHERE id=?');
        $q->execute([$id]); $existing=$q->fetch();
        if (!$existing) fail('Agendamento não encontrado.',404);
        // Pedidos pendentes só podem ser aprovados ou cancelados pelo painel.
        if ($existing['status'] === 'completed' && $status === 'no_show') fail('Atendimento concluído não pode ser marcado como falta.',409);
        if ($existing['status'] === 'pending' && !in_array($status,['confirmed','cancelled'],true)) {
            fail('A solicitação precisa ser aprovada ou recusada antes de alterar seu status.',409);
        }
        if ($status === 'confirmed' && $existing['status'] !== 'confirmed') {
            // Aprovação e reativação usam o mesmo lock de criação de reservas.
            $lockName='martelli_barber_'.(int)$existing['barber_id'];
            $q=$pdo->prepare('SELECT GET_LOCK(?,5)'); $q->execute([$lockName]);
            if ((int)$q->fetchColumn()!==1) fail('Agenda ocupada. Tente novamente.',409);
            try {
                $pdo->beginTransaction();
                management_assert_free((int)$existing['barber_id'],$existing['starts_at'],$existing['ends_at']);
                $q=$pdo->prepare('SELECT status FROM mt_appointments WHERE id=? FOR UPDATE');
                $q->execute([$id]); $oldStatus=$q->fetchColumn();
                if ($oldStatus===false) { $pdo->rollBack(); fail('Agendamento não encontrado.',404); }
                if ($oldStatus !== $existing['status']) { $pdo->rollBack(); fail('Este agendamento mudou. Atualize o painel.',409); }
                $busyUntil=mt_busy_until_sql();
                $q=$pdo->prepare("SELECT COUNT(*) FROM mt_appointments WHERE id<>? AND barber_id=? AND status NOT IN ('cancelled','no_show') AND starts_at<? AND {$busyUntil}>?");
                $q->execute([$id,$existing['barber_id'],$existing['ends_at'],$existing['starts_at']]);
                if ((int)$q->fetchColumn()>0) { $pdo->rollBack(); fail('Este horário já está ocupado. Não é possível reativar a reserva.',409); }
                $q=$pdo->prepare(mt_has_finished_at()
                    ?'UPDATE mt_appointments SET status=?,finished_at=NULL WHERE id=?'
                    :'UPDATE mt_appointments SET status=? WHERE id=?');
                $q->execute([$status,$id]);
                $pdo->commit();
            } finally {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $q=$pdo->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lockName]);
            }
        } else {
            // Não sobrescreve uma decisão concorrente sobre um pedido pendente.
            if ($existing['status']==='pending') {
                $q=$pdo->prepare("UPDATE mt_appointments SET status=? WHERE id=? AND status='pending'");
                $q->execute([$status,$id]);
                if ($q->rowCount()!==1) fail('A solicitação já foi alterada. Atualize o painel.',409);
            } else {
                $q=$pdo->prepare('UPDATE mt_appointments SET status=? WHERE id=?'); $q->execute([$status,$id]);
            }
        }
        return ['id'=>$id];
    }
    if ($entity==='settings') {
        $allowed=['bookings_enabled','whatsapp','instagram','address','notice'];
        $key=(string)($d['key']??''); if (!in_array($key,$allowed,true)) fail('Configuração inválida.');
        $value=trim((string)($d['value']??''));
        if ($key==='bookings_enabled') $value=boolish($value)?'1':'0';
        if ($key==='whatsapp' && $value!=='') {
            $value=whatsapp_for_link($value);
            if ($value==='') fail('Informe WhatsApp válido, com ou sem +55.');
        }
        if (strlen($value)>500) fail('Valor muito longo.');
        $q=$pdo->prepare('INSERT INTO mt_settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
        $q->execute([$key,$value]); return ['key'=>$key];
    }
    if ($entity==='hours') {
        $wd=(int)($d['weekday']??-1); if ($wd<0||$wd>6) fail('Dia inválido.');
        $open=(string)($d['open_time']??'');$close=(string)($d['close_time']??'');$ext=trim((string)($d['extended_close_time']??''));
        foreach ([$open,$close] as $v) if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$v)) fail('Horário inválido.');
        if ($ext!=='' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$ext)) fail('Horário estendido inválido.');
        if ($close<=$open || ($ext!==''&&$ext<$close)) fail('Confira os horários de abertura e fechamento.');
        $q=$pdo->prepare('INSERT INTO mt_business_hours (weekday,is_open,open_time,close_time,extended_close_time) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE is_open=VALUES(is_open),open_time=VALUES(open_time),close_time=VALUES(close_time),extended_close_time=VALUES(extended_close_time)');
        $q->execute([$wd,boolish($d['is_open']??0),$open,$close,$ext?:null]); return ['weekday'=>$wd];
    }
    if ($entity==='services' || $entity==='barbers') {
        $table=$entity==='services'?'mt_services':'mt_barbers';
        if ($entity==='barbers' && array_key_exists('whatsapp',$d)) {
            // Aceita número com DDD, ou DDI 55 + DDD. Não altera o esquema MySQL:
            // usa mt_settings para guardar o contato de cada profissional.
            $rawWhatsapp=trim((string)($d['whatsapp']??''));
            $barberWhatsapp=whatsapp_for_link($rawWhatsapp);
            if ($rawWhatsapp!=='' && $barberWhatsapp==='') fail('Informe WhatsApp válido, com DDD (e DDI 55 opcional).');
        }
        $name=trim((string)($d['name']??'')); $bio=trim((string)($d[$entity==='services'?'description':'bio']??''));
        if (text_len($name)<2||text_len($name)>120||text_len($bio)>500) fail('Nome ou descrição inválidos.');
        $active=boolish($d['active']??1);$order=(int)($d['sort_order']??0);
        if ($entity==='services') {
            $price=(float)($d['price']??-1);$duration=(int)($d['duration_minutes']??0);
            if($price<0||$price>10000||$duration<5||$duration>480) fail('Preço ou duração inválidos.');
            $fields=['name'=>$name,'description'=>$bio,'price'=>number_format($price,2,'.',''),'duration_minutes'=>$duration,'active'=>$active,'sort_order'=>$order];
        } else $fields=['name'=>$name,'bio'=>$bio,'active'=>$active,'sort_order'=>$order];
    } else if ($entity==='clients') {
        $name=trim((string)($d['name']??''));$phone=normalize_br_phone((string)($d['phone']??''));$notes=trim((string)($d['notes']??''));
        if(text_len($name)<2||text_len($name)>120||$phone===''||text_len($notes)>500) fail('Confira nome, telefone e observações.');
        mt_cpf_schema();
        $cpfRaw=trim((string)($d['cpf']??''));
        if($cpfRaw==='' && $id<=0)fail('Informe o CPF do novo cliente.',422);
        $table='mt_clients';$fields=['name'=>$name,'phone'=>$phone,'notes'=>$notes];
        if($cpfRaw!==''){
          $cpf=mt_cpf($cpfRaw);
          $q=$pdo->prepare('SELECT id FROM mt_clients WHERE cpf=? AND id<>? LIMIT 1');$q->execute([$cpf,$id]);
          if($q->fetchColumn())fail('CPF já cadastrado. Procure o cadastro existente.',409);
          $fields['cpf']=$cpf;
        }
    } else fail('Operação não permitida.');
    $names=array_keys($fields);$vals=array_values($fields);
    if ($id>0) {
       $assign=implode(',',array_map(fn($k)=>"`$k`=?",$names));
       $q=$pdo->prepare("UPDATE `$table` SET $assign WHERE id=?"); $q->execute([...$vals,$id]);
    } else {
       $columns=implode(',',array_map(fn($k)=>"`$k`",$names));$placeholders=implode(',',array_fill(0,count($names),'?'));
       $q=$pdo->prepare("INSERT INTO `$table` ($columns) VALUES ($placeholders)"); $q->execute($vals);
       $id=(int)$pdo->lastInsertId();
    }
    if ($entity==='barbers' && array_key_exists('whatsapp',$d)) {
        $settingKey='barber_whatsapp_'.$id;
        if ($barberWhatsapp==='') {
            $q=$pdo->prepare('DELETE FROM mt_settings WHERE `key`=?');
            $q->execute([$settingKey]);
        } else {
            $q=$pdo->prepare('INSERT INTO mt_settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
            $q->execute([$settingKey,$barberWhatsapp]);
        }
    }
    return ['id'=>$id];
}

/** Valida novamente a senha administrativa para ação sensível. */
function verify_admin_password(string $password): bool {
    $q=db()->prepare('SELECT password_hash FROM mt_admins WHERE id=? LIMIT 1');
    $q->execute([(int)($_SESSION['admin_id']??0)]);
    $stored=$q->fetchColumn();
    return is_string($stored) && strlen($password)<=256 && password_verify($password,$stored);
}
