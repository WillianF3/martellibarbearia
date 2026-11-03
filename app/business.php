<?php
declare(strict_types=1);
final class BusinessError extends RuntimeException {}
/** Lança exceção de domínio tratada pela API financeira. */
function biz_error(string $s): never {throw new BusinessError($s);}
/** Executa SQL parametrizado com a conexão compartilhada. */
function biz_query(string $sql,array $p=[]): PDOStatement {$q=db()->prepare($sql);$q->execute($p);return $q;}
/** Gera data e hora operacional no fuso configurado. */
function biz_now(): string {return date('Y-m-d H:i:s');}
/** Obtém ID do administrador que executa a operação. */
function biz_actor(): int {return (int)($_SESSION['admin_id']??0);}
/** Converte entradas financeiras para centavos, sem ponto flutuante. */
function biz_money(mixed $v): int {
    try {
        return \Martelli\Domain\Money::toCents($v);
    } catch (InvalidArgumentException $e) {
        biz_error($e->getMessage());
    }
}
/** Valida inteiros dentro dos limites da regra de negócio. */
function biz_int(mixed $v,int $min,int $max): int {if(filter_var($v,FILTER_VALIDATE_INT)===false||(int)$v<$min||(int)$v>$max)biz_error('Número fora do intervalo permitido.');return (int)$v;}
/** Valida campos textuais e limites de tamanho. */
function biz_text(mixed $v,int $max,bool $required=false): string {$s=trim((string)$v);if(($required&&$s==='')||text_len($s)>$max)biz_error('Preencha o texto respeitando o tamanho permitido.');return $s;}
/** Aceita somente meios de pagamento definidos pelo caixa. */
function biz_method(mixed $v): string {$s=(string)$v;if(!in_array($s,['cash','pix','credit','debit','transfer'],true))biz_error('Pagamento inválido.');return $s;}
/** Verifica token idempotente das operações financeiras. */
function biz_key(mixed $v): string {$s=(string)$v;if(!preg_match('/^[a-zA-Z0-9-]{16,64}$/D',$s))biz_error('Identificador inválido. Atualize a página.');return $s;}
/** Valida uma data civil antes de consultar relatórios. */
function biz_day(string $s): string {$d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);if(!$d||$d->format('Y-m-d')!==$s||$s<'2000-01-01'||$s>'2100-12-31')biz_error('Data inválida.');return $s;}
/** Confere se todas as tabelas financeiras foram instaladas. */
function biz_ready(): bool {
 try {foreach(['orders','order_items','products','cash_sessions','ledger','commissions','stock_moves','business_audit'] as $t)db()->query('SELECT 1 FROM mt_'.$t.' LIMIT 1');return true;}
 catch(PDOException $e){if($e->getCode()==='42S02'||str_contains($e->getMessage(),'no such table'))return false;throw $e;}
}
/** Executa o esquema SQL do módulo financeiro. */
function biz_install(): void {foreach(explode(';',file_get_contents(__DIR__.'/../sql/business_v2.sql')) as $s)if(trim($s)!=='')db()->exec($s);}
/** Registra um evento administrativo para rastreabilidade. */
function biz_audit(string $event,?int $id=null,string $detail=''): void {biz_query('INSERT INTO mt_business_audit (actor_id,event,entity_id,created_at,detail) VALUES (?,?,?,?,?)',[biz_actor(),$event,$id,biz_now(),$detail]);}
/** Executa operações financeiras com transação e trava de concorrência. */
function biz_transaction(callable $fn): mixed {
 $pdo=db();$mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
 if($mysql&&(int)biz_query("SELECT GET_LOCK('martelli_business_v8',10)")->fetchColumn()!==1)biz_error('Outra operação está em andamento. Tente novamente.');
 try{$pdo->beginTransaction();$r=$fn();$pdo->commit();return $r;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 finally{if($mysql)biz_query("SELECT RELEASE_LOCK('martelli_business_v8')");}
}
/** Consulta comanda e soma itens e pagamentos para calcular saldos. */
function biz_order(int $id): array {
 // O cadastro do cliente e do agendamento é a fonte dos dados exibidos na comanda.
 // Itens e preços registrados na comanda permanecem independentes, preservando o histórico financeiro.
 $o=biz_query('SELECT o.*, c.name client_name, c.phone client_phone, c.notes client_notes, '
    .'a.starts_at appointment_starts_at, a.ends_at appointment_ends_at, '
    .'a.notes appointment_notes, a.price appointment_price, a.status appointment_status, '
    .'s.name appointment_service_name, b.name appointment_barber_name '
    .'FROM mt_orders o '
    .'LEFT JOIN mt_clients c ON c.id=o.client_id '
    .'LEFT JOIN mt_appointments a ON a.id=o.appointment_id '
    .'LEFT JOIN mt_services s ON s.id=a.service_id '
    .'LEFT JOIN mt_barbers b ON b.id=a.barber_id '
    .'WHERE o.id=?',[$id])->fetch();if(!$o)biz_error('Comanda não encontrada.');
 $o['items']=biz_query('SELECT i.*,b.name barber_name FROM mt_order_items i LEFT JOIN mt_barbers b ON b.id=i.barber_id WHERE order_id=? ORDER BY i.id',[$id])->fetchAll();
 $o['subtotal_cents']=array_sum(array_map(fn($i)=>(int)$i['unit_cents']*(int)$i['quantity'],$o['items']));
 $o['total_cents']=$o['subtotal_cents']-(int)$o['discount_cents'];
 $o['payments']=biz_query('SELECT * FROM mt_ledger WHERE order_id=? ORDER BY id',[$id])->fetchAll();
 $o['paid_cents']=(int)array_sum(array_column($o['payments'],'amount_cents'));
 $o['due_cents']=$o['status']==='cancelled'?0:$o['total_cents']-$o['paid_cents'];return $o;
}
/** Impede edição de comanda fechada ou com pagamentos. */
function biz_editable(int $id): array {$o=biz_order($id);if($o['status']!=='open')biz_error('Comanda não está aberta.');if($o['paid_cents']>0)biz_error('Há recebimentos. Finalize ou cancele com estorno antes de alterar itens.');return $o;}
/** Busca o caixa aberto para registrar lançamentos. */
function biz_cash(): array {$c=biz_query('SELECT * FROM mt_cash_sessions WHERE closed_at IS NULL ORDER BY id DESC LIMIT 1')->fetch();if(!$c)biz_error('Abra um caixa antes de registrar movimentações.');return $c;}
/** Calcula saldo de caixa a partir de lançamentos registrados. */
function biz_balance(array $c): int {return (int)$c['opening_cents']+(int)biz_query("SELECT COALESCE(SUM(amount_cents),0) FROM mt_ledger WHERE cash_id=? AND method='cash'",[$c['id']])->fetchColumn();}
/** Registra movimentação financeira de forma idempotente. */
function biz_ledger(array $cash,int $amount,string $method,string $category,string $description,string $key,?int $order=null,?int $reversal=null): int {
 if(!$amount)biz_error('O valor deve ser maior que zero.');if($method==='cash'&&biz_balance($cash)+$amount<0)biz_error('Saldo em dinheiro insuficiente no caixa.');
 biz_query('INSERT INTO mt_ledger (cash_id,order_id,amount_cents,method,category,description,created_at,created_by,request_key,reversal_of) VALUES (?,?,?,?,?,?,?,?,?,?)',[$cash['id'],$order,$amount,$method,$category,$description,biz_now(),biz_actor(),$key,$reversal]);return (int)db()->lastInsertId();
}
/** Obtém percentual de comissão configurado para o profissional. */
function biz_rate(int $barber): int {return (int)setting('biz:commission:'.$barber,'0');}
/** Registra movimento e atualiza o estoque do produto. */
function biz_stock(int $id,int $delta,string $reason,?int $order=null): void {
 $p=biz_query('SELECT * FROM mt_products WHERE id=?',[$id])->fetch();if(!$p||(int)$p['stock']+$delta<0||(int)$p['stock']+$delta>1000000)biz_error('Estoque insuficiente ou quantidade inválida.');
 biz_query('UPDATE mt_products SET stock=stock+? WHERE id=?',[$delta,$id]);biz_query('INSERT INTO mt_stock_moves (product_id,delta,reason,order_id,created_at,created_by) VALUES (?,?,?,?,?,?)',[$id,$delta,$reason,$order,biz_now(),biz_actor()]);
}
/** Despacha alterações financeiras após permissão e validação. */
function biz_mutate(string $action,array $d): array {
 return biz_transaction(function()use($action,$d){
 $id=(int)($d['id']??0);
 switch($action){
 case 'order_create':
  $client=(int)($d['client_id']??0);$appointment=(int)($d['appointment_id']??0);$a=null;
  if($appointment){
   $exists=biz_query('SELECT id FROM mt_orders WHERE appointment_id=?',[$appointment])->fetchColumn();if($exists)return ['id'=>(int)$exists];
   $a=biz_query('SELECT a.*,s.name service_name FROM mt_appointments a JOIN mt_services s ON s.id=a.service_id WHERE a.id=?',[$appointment])->fetch();
   if(!$a||!in_array($a['status'],['confirmed','completed'],true))biz_error('Use um agendamento confirmado ou concluído.');$client=(int)$a['client_id'];
  }
  if($client&&!biz_query('SELECT id FROM mt_clients WHERE id=?',[$client])->fetchColumn())biz_error('Cliente inválido.');
  biz_query('INSERT INTO mt_orders (client_id,appointment_id,notes,created_at,created_by) VALUES (?,?,?,?,?)',[$client?:null,$appointment?:null,biz_text($d['notes']??'',500),biz_now(),biz_actor()]);$id=(int)db()->lastInsertId();
  if($a)biz_query('INSERT INTO mt_order_items (order_id,kind,reference_id,description,quantity,unit_cents,barber_id,commission_bps) VALUES (?,?,?,?,?,?,?,?)',[$id,'service',$a['service_id'],$a['service_name'],1,biz_money($a['price']),$a['barber_id'],biz_rate((int)$a['barber_id'])]);
  biz_audit('order_create',$id);return ['id'=>$id];
 case 'item_add':
  biz_editable($id);$kind=(string)($d['kind']??'');$ref=(int)($d['reference_id']??0);$qty=biz_int($d['quantity']??1,1,1000);
  if(!in_array($kind,['service','product'],true))biz_error('Tipo de item inválido.');$table=$kind==='service'?'mt_services':'mt_products';
  $item=biz_query("SELECT * FROM $table WHERE id=? AND active=1",[$ref])->fetch();if(!$item)biz_error('Item indisponível.');
  $barber=$kind==='service'?biz_int($d['barber_id']??0,1,2147483647):null;
  if($barber&&!biz_query('SELECT id FROM mt_barbers WHERE id=? AND active=1',[$barber])->fetchColumn())biz_error('Profissional inválido.');
  $price=$kind==='service'?biz_money($item['price']):(int)$item['price_cents'];if($price*$qty>999999999)biz_error('Valor total acima do limite.');
  biz_query('INSERT INTO mt_order_items (order_id,kind,reference_id,description,quantity,unit_cents,barber_id,commission_bps) VALUES (?,?,?,?,?,?,?,?)',[$id,$kind,$ref,$item['name'],$qty,$price,$barber,$barber?biz_rate($barber):0]);biz_audit('item_add',$id);return ['id'=>$id];
 case 'item_remove':
  biz_editable($id);biz_query('DELETE FROM mt_order_items WHERE id=? AND order_id=?',[(int)($d['item_id']??0),$id]);if(biz_order($id)['total_cents']<0)biz_query('UPDATE mt_orders SET discount_cents=0 WHERE id=?',[$id]);biz_audit('item_remove',$id);return ['id'=>$id];
 case 'order_discount':
  $o=biz_editable($id);$v=biz_money($d['amount']??'');if($v>$o['subtotal_cents'])biz_error('Desconto maior que subtotal.');biz_query('UPDATE mt_orders SET discount_cents=? WHERE id=?',[$v,$id]);biz_audit('order_discount',$id);return ['id'=>$id];
 case 'order_pay':
  $key=biz_key($d['request_key']??'');$prior=biz_query('SELECT order_id FROM mt_ledger WHERE request_key=?',[$key])->fetch();if($prior){if((int)$prior['order_id']!==$id)biz_error('Identificador já utilizado.');return ['id'=>$id];}
  $o=biz_order($id);if($o['status']!=='open')biz_error('Comanda não está aberta.');$v=biz_money($d['amount']??'');if($v<=0||$v>$o['due_cents'])biz_error('Informe um valor positivo até o saldo da comanda.');
  biz_ledger(biz_cash(),$v,biz_method($d['method']??''),'order','Recebimento da comanda #'.$id,$key,$id);biz_audit('order_payment',$id);return ['id'=>$id];
 case 'order_close':
  $o=biz_order($id);
  if($o['status']==='closed')return ['id'=>$id];
  if($o['status']!=='open')biz_error('Esta comanda não está aberta e não pode ser fechada.');
  if(!$o['items'])biz_error('Adicione ao menos um serviço ou produto antes de fechar esta comanda.');
  if($o['due_cents']>0)biz_error('Pagamento pendente: R$ '.number_format($o['due_cents']/100,2,',','.').'. Registre o recebimento antes de fechar.');
  if($o['due_cents']<0)biz_error('O valor recebido é maior que o total da comanda. Confira os lançamentos.');
  foreach($o['items'] as $i){
   if($i['kind']==='product')biz_stock((int)$i['reference_id'],-(int)$i['quantity'],'Venda na comanda #'.$id,$id);
   if($i['barber_id']&&(int)$i['commission_bps']>0){
    $base=(int)$i['unit_cents']*(int)$i['quantity'];$net=$o['subtotal_cents']>0?(int)round($base*$o['total_cents']/$o['subtotal_cents']):0;$amount=(int)round($net*(int)$i['commission_bps']/10000);
    if($amount>0)biz_query('INSERT INTO mt_commissions (order_id,item_id,barber_id,amount_cents,created_at) VALUES (?,?,?,?,?)',[$id,$i['id'],$i['barber_id'],$amount,biz_now()]);
   }
  }
  biz_query("UPDATE mt_orders SET status='closed',closed_at=? WHERE id=?",[biz_now(),$id]);if($o['appointment_id'])biz_query(mt_has_finished_at()
       ?"UPDATE mt_appointments SET status='completed',finished_at=CASE WHEN NOW()>=starts_at THEN NOW() ELSE NULL END WHERE id=? AND status='confirmed'"
       :"UPDATE mt_appointments SET status='completed' WHERE id=? AND status='confirmed'",[$o['appointment_id']]);biz_audit('order_close',$id);return ['id'=>$id];
 case 'order_cancel':
  $o=biz_order($id);if($o['status']==='cancelled')return ['id'=>$id];$reason=biz_text($d['reason']??'',200,true);
  if(biz_query('SELECT id FROM mt_commissions WHERE order_id=? AND paid_ledger_id IS NOT NULL AND voided_at IS NULL',[$id])->fetchColumn())biz_error('Comissão já paga. Regularize o pagamento antes de cancelar.');
  if($o['paid_cents']>0){$cash=biz_cash();foreach($o['payments'] as $p)if((int)$p['amount_cents']>0)biz_ledger($cash,-(int)$p['amount_cents'],$p['method'],'refund','Estorno da comanda #'.$id,'refund-'.$id.'-'.$p['id'],$id,(int)$p['id']);}
  if($o['status']==='closed')foreach($o['items'] as $i)if($i['kind']==='product')biz_stock((int)$i['reference_id'],(int)$i['quantity'],'Cancelamento da comanda #'.$id,$id);
  biz_query('UPDATE mt_commissions SET voided_at=? WHERE order_id=?',[biz_now(),$id]);biz_query("UPDATE mt_orders SET status='cancelled',cancelled_at=? WHERE id=?",[biz_now(),$id]);biz_audit('order_cancel',$id,$reason);return ['id'=>$id];
 case 'cash_open':
  if(biz_query('SELECT id FROM mt_cash_sessions WHERE closed_at IS NULL')->fetchColumn())biz_error('Já existe um caixa aberto.');
  biz_query('INSERT INTO mt_cash_sessions (opened_at,opening_cents,opened_by) VALUES (?,?,?)',[biz_now(),biz_money($d['amount']??''),biz_actor()]);$id=(int)db()->lastInsertId();biz_audit('cash_open',$id);return ['id'=>$id];
 case 'cash_close':
  $cash=biz_cash();if((int)$cash['id']!==$id)biz_error('Caixa alterado. Atualize a tela.');
  biz_query('UPDATE mt_cash_sessions SET closed_at=?,counted_cents=?,expected_cents=?,closed_by=? WHERE id=?',[biz_now(),biz_money($d['amount']??''),biz_balance($cash),biz_actor(),$id]);biz_audit('cash_close',$id);return ['id'=>$id];
 case 'ledger_add':
  $key=biz_key($d['request_key']??'');if(biz_query('SELECT id FROM mt_ledger WHERE request_key=?',[$key])->fetchColumn())return [];
  $v=biz_money($d['amount']??'');$direction=(string)($d['direction']??'');if(!in_array($direction,['in','out'],true))biz_error('Selecione entrada ou saída.');
  $category=(string)($d['category']??'');if(!in_array($category,['other','supplies','rent','withdrawal','deposit','tip','advance'],true))biz_error('Categoria inválida.');
  $id=biz_ledger(biz_cash(),$direction==='out'?-$v:$v,biz_method($d['method']??''),$category,biz_text($d['description']??'',200,true),$key);biz_audit('ledger_add',$id);return ['id'=>$id];
 case 'ledger_reverse':
  $p=biz_query('SELECT * FROM mt_ledger WHERE id=?',[$id])->fetch();if(!$p||$p['order_id']||$p['reversal_of']||in_array($p['category'],['commission','reversal'],true))biz_error('Trate este lançamento no módulo de origem.');
  if(biz_query('SELECT id FROM mt_ledger WHERE reversal_of=?',[$id])->fetchColumn())biz_error('Lançamento já estornado.');
  biz_ledger(biz_cash(),-(int)$p['amount_cents'],$p['method'],'reversal','Estorno: '.biz_text($d['reason']??'',160,true),'ledger-reversal-'.$id,null,$id);biz_audit('ledger_reverse',$id);return [];
 case 'product_save':
  $name=biz_text($d['name']??'',120,true);$price=biz_money($d['price']??'');$min=biz_int($d['min_stock']??0,0,1000000);$active=biz_int($d['active']??1,0,1);
  if($id){if(!biz_query('SELECT id FROM mt_products WHERE id=?',[$id])->fetchColumn())biz_error('Produto não encontrado.');biz_query('UPDATE mt_products SET name=?,price_cents=?,min_stock=?,active=? WHERE id=?',[$name,$price,$min,$active,$id]);}
  else{biz_query('INSERT INTO mt_products (name,price_cents,min_stock,active) VALUES (?,?,?,?)',[$name,$price,$min,$active]);$id=(int)db()->lastInsertId();}
  biz_audit('product_save',$id);return ['id'=>$id];
 case 'stock_adjust':
  $delta=biz_int($d['delta']??0,-1000000,1000000);if(!$delta)biz_error('Informe uma quantidade diferente de zero.');biz_stock($id,$delta,biz_text($d['reason']??'',200,true));biz_audit('stock_adjust',$id);return ['id'=>$id];
 case 'commission_pay':
  $key=biz_key($d['request_key']??'');if(biz_query('SELECT id FROM mt_ledger WHERE request_key=?',[$key])->fetchColumn())return [];
  $ids=$d['ids']??[];if(!is_array($ids)||!count($ids)||count($ids)>500)biz_error('Selecione até 500 comissões.');$ids=array_values(array_unique(array_map(fn($v)=>biz_int($v,1,PHP_INT_MAX),$ids)));$place=implode(',',array_fill(0,count($ids),'?'));
  $rows=biz_query("SELECT * FROM mt_commissions WHERE id IN ($place) AND paid_ledger_id IS NULL AND voided_at IS NULL",$ids)->fetchAll();if(count($rows)!==count($ids))biz_error('Uma comissão já foi paga ou cancelada. Atualize.');
  if(count(array_unique(array_column($rows,'barber_id')))!==1)biz_error('Pague um profissional de cada vez.');
  $amount=(int)array_sum(array_column($rows,'amount_cents'));$ledger=biz_ledger(biz_cash(),-$amount,biz_method($d['method']??''),'commission','Comissões do profissional #'.$rows[0]['barber_id'],$key);
  biz_query("UPDATE mt_commissions SET paid_ledger_id=? WHERE id IN ($place)",array_merge([$ledger],$ids));biz_audit('commission_pay',$ledger);return [];
 case 'commission_reverse':
  $p=biz_query("SELECT * FROM mt_ledger WHERE id=? AND category='commission'",[$id])->fetch();if(!$p)biz_error('Pagamento não encontrado.');
  if(biz_query('SELECT id FROM mt_ledger WHERE reversal_of=?',[$id])->fetchColumn())biz_error('Pagamento já estornado.');
  biz_ledger(biz_cash(),-(int)$p['amount_cents'],$p['method'],'reversal','Devolução de comissão: '.biz_text($d['reason']??'',140,true),'commission-reversal-'.$id,null,$id);
  biz_query('UPDATE mt_commissions SET paid_ledger_id=NULL WHERE paid_ledger_id=?',[$id]);biz_audit('commission_reverse',$id);return [];
 case 'settings_save':
  $rates=$d['rates']??[];if(!is_array($rates))biz_error('Configuração inválida.');$allowed=array_map('intval',array_column(biz_query('SELECT id FROM mt_barbers')->fetchAll(),'id'));
  foreach($rates as $barber=>$v){if(!in_array((int)$barber,$allowed,true))biz_error('Profissional inválido.');$bps=biz_money($v);if($bps>10000)biz_error('Comissão deve ficar entre 0 e 100%.');$key='biz:commission:'.(int)$barber;
   if(biz_query('SELECT `key` FROM mt_settings WHERE `key`=?',[$key])->fetchColumn()!==false)biz_query('UPDATE mt_settings SET `value`=? WHERE `key`=?',[(string)$bps,$key]);else biz_query('INSERT INTO mt_settings (`key`,`value`) VALUES (?,?)',[$key,(string)$bps]);}
  biz_audit('settings_save');return [];
 default:biz_error('Operação inválida.');
 }
 });
}
/** Valida intervalo de datas dos relatórios. */
function biz_range(array $p): array {
 $from=biz_day((string)($p['from']??date('Y-m-01')));$to=biz_day((string)($p['to']??date('Y-m-d')));
 if($to<$from||(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days>366)biz_error('Escolha um período de até 367 dias.');
 return [$from,(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d')];
}
/** Filtra exclusivamente serviços em comandas efetivamente fechadas. */
function biz_barber_report_scope(array $p): array {
 $range=biz_range($p);
 $barber=($p['barber_id']??'')==='' ? 0 : biz_int($p['barber_id'],0,2147483647);
 if($barber>0 && !biz_query('SELECT id FROM mt_barbers WHERE id=?',[$barber])->fetchColumn())biz_error('Barbeiro não encontrado.');
 $from=' FROM mt_order_items i JOIN mt_orders o ON o.id=i.order_id '
  .'JOIN (SELECT order_id,SUM(quantity*unit_cents) subtotal_cents FROM mt_order_items GROUP BY order_id) s ON s.order_id=o.id '
  .'LEFT JOIN mt_barbers b ON b.id=i.barber_id '
  .'LEFT JOIN mt_commissions c ON c.item_id=i.id AND c.voided_at IS NULL ';
 $where=" WHERE o.status='closed' AND i.kind='service' AND i.barber_id IS NOT NULL AND o.closed_at>=? AND o.closed_at<?";
 $args=$range;
 if($barber){$where.=' AND i.barber_id=?';$args[]=$barber;}
 return [$from.$where,$args,$barber,$range];
}
/** Valores calculados sobre as comandas fechadas, sem estimar comissões da agenda. */
function biz_barber_report_totals(array $p): array {
 [$scope,$args,$barber,$range]=biz_barber_report_scope($p);
 $net='CASE WHEN s.subtotal_cents>0 THEN ROUND(i.quantity*i.unit_cents*(s.subtotal_cents-o.discount_cents)/s.subtotal_cents) ELSE 0 END';
 $columns='COUNT(DISTINCT o.id) orders,COALESCE(SUM(i.quantity),0) services,'
  .'COALESCE(SUM(i.quantity*i.unit_cents),0) gross_cents,COALESCE(SUM('.$net.'),0) net_cents,'
  .'COALESCE(SUM(c.amount_cents),0) commission_cents,'
  .'COALESCE(SUM(CASE WHEN c.paid_ledger_id IS NULL THEN c.amount_cents ELSE 0 END),0) pending_cents,'
  .'COALESCE(SUM(CASE WHEN c.paid_ledger_id IS NOT NULL THEN c.amount_cents ELSE 0 END),0) paid_cents';
 $totals=biz_query('SELECT '.$columns.$scope,$args)->fetch();
 $perBarber=biz_query('SELECT i.barber_id,b.name,'.$columns.$scope.' GROUP BY i.barber_id,b.name ORDER BY b.name',$args)->fetchAll();
 return ['totals'=>$totals,'professionals'=>$perBarber,'range'=>['from'=>$p['from']??date('Y-m-01'),'to'=>$p['to']??date('Y-m-d')], 'barber_id'=>$barber];
}
/** Detalhes reutilizados pela paginação e pelo CSV integral. */
function biz_barber_report_rows(array $p,?int $page=1): PDOStatement {
 [$scope,$args]=biz_barber_report_scope($p);
 $sql='SELECT i.id item_id,i.barber_id,b.name barber_name,o.id order_id,o.closed_at,'
  .'i.description,i.quantity,i.unit_cents,i.commission_bps,i.quantity*i.unit_cents gross_cents,'
  .'CASE WHEN s.subtotal_cents>0 THEN ROUND(i.quantity*i.unit_cents*(s.subtotal_cents-o.discount_cents)/s.subtotal_cents) ELSE 0 END net_cents,'
  .'COALESCE(c.amount_cents,0) commission_cents,c.id commission_id,c.paid_ledger_id'
  .$scope.' ORDER BY o.closed_at DESC,i.id DESC';
 if($page!==null){$page=biz_int($page,1,1000000);$sql.=' LIMIT 150 OFFSET '.(($page-1)*150);}
 return biz_query($sql,$args);
}
/** Resumo com paginador de 150 serviços por página. */
function biz_barber_commission_report(array $p): array {
 $summary=biz_barber_report_totals($p);
 $page=biz_int($p['page']??1,1,1000000);
 [$scope,$args]=biz_barber_report_scope($p);
 $total=(int)biz_query('SELECT COUNT(*)'.$scope,$args)->fetchColumn();
 return $summary+['rows'=>biz_barber_report_rows($p,$page)->fetchAll(),'total'=>$total,'page'=>$page,'page_size'=>150];
}
/** CSV do período completo, gerado no servidor sem limite da página exibida. */
function biz_export_barber_commissions(array $p): never {
 $summary=biz_barber_report_totals($p);
 $file='martelli-comissoes-'.$summary['range']['from'].'-a-'.$summary['range']['to'].'.csv';
 header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="'.$file.'"');header('Cache-Control: no-store, private');
 $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");
 $cell=static function(mixed $value): string {
  $v=(string)($value??'');return preg_match('/^\s*[=+@\-\t\r\n]/u',$v) ? "'".$v : $v;
 };
 $write=static function(array $row) use($out,$cell): void {fputcsv($out,array_map($cell,$row),';','"','');};
 $brl=static fn($c):string=>number_format(((int)$c)/100,2,',','.');
 $write(['BARBEARIA MARTELLI - RELATÓRIO DE COMISSÕES']);
 $write(['Data inicial',$summary['range']['from'],'Data final',$summary['range']['to']]);
 $write(['Fonte','Serviços em comandas fechadas no período (data do fechamento)']);
 $t=$summary['totals'];$write(['Comandas','Serviços','Bruto R$','Líquido R$','Comissão R$','Pendente R$','Pago R$']);
 $write([$t['orders'],$t['services'],$brl($t['gross_cents']),$brl($t['net_cents']),$brl($t['commission_cents']),$brl($t['pending_cents']),$brl($t['paid_cents'])]);
 $write([]);$write(['POR BARBEIRO']);
 $write(['Profissional','Comandas','Serviços','Líquido R$','Comissão R$','Pendente R$','Pago R$']);
 foreach($summary['professionals'] as $b)$write([$b['name'],$b['orders'],$b['services'],$brl($b['net_cents']),$brl($b['commission_cents']),$brl($b['pending_cents']),$brl($b['paid_cents'])]);
 $write([]);$write(['DETALHAMENTO']);
 $write(['Fechamento','Barbeiro','Comanda','Serviço','Quantidade','Bruto R$','Líquido R$','Percentual %','Comissão R$','Status']);
 $q=biz_barber_report_rows($p,null);
 while($r=$q->fetch())$write([$r['closed_at'],$r['barber_name'],'#'.$r['order_id'],$r['description'],$r['quantity'],$brl($r['gross_cents']),$brl($r['net_cents']),number_format($r['commission_bps']/100,2,',','.'),$brl($r['commission_cents']),!(int)$r['commission_id']?'Sem comissão':($r['paid_ledger_id']?'Paga':'Pendente')]);
 fclose($out);exit;
}
/** Página de impressão com TODAS as linhas do período, sem paginação. */
function biz_print_barber_commissions(array $p): never {
 $report=biz_barber_report_totals($p);
 $e=static fn($value)=>htmlspecialchars((string)($value??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 $brl=static fn($c):string=>'R$ '.number_format(((int)$c)/100,2,',','.');
 header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store, private');
 echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Relatório de comissões | Martelli</title>';
 echo '<style>body{font:14px Arial,sans-serif;margin:26px;color:#171717}h1{font-size:26px;margin:0 0 8px}h2{font-size:17px;margin-top:28px}small,.meta{color:#555}.totals{display:flex;gap:18px;flex-wrap:wrap}.totals div{border:1px solid #999;border-radius:6px;padding:12px;min-width:135px}.totals strong{display:block;font-size:18px;margin-top:8px}table{width:100%;border-collapse:collapse;margin-top:12px;font-size:12px}th,td{padding:7px;text-align:left;border-bottom:1px solid #ddd}th{background:#f1f1f1}.actions{margin:20px 0}.actions button{background:#e8c18b;border:1px solid #997647;border-radius:5px;padding:11px 18px;cursor:pointer}@media print{body{margin:10mm}.actions{display:none}thead{display:table-header-group}tr{break-inside:avoid}h2{break-after:avoid}}</style></head><body>';
 echo '<header><h1>Barbearia Martelli · Comissões</h1><p class="meta">Período de '.$e($report['range']['from']).' a '.$e($report['range']['to']).' · Data de fechamento das comandas</p></header>';
 echo '<div class="actions"><button type="button" onclick="window.print()">Imprimir ou salvar em PDF</button></div>';
 $t=$report['totals'];
 echo '<div class="totals">';foreach([['Serviços',$t['services']],['Valor líquido',$brl($t['net_cents'])],['Comissão total',$brl($t['commission_cents'])],['Pendente',$brl($t['pending_cents'])],['Paga',$brl($t['paid_cents'])]] as [$k,$v])echo '<div><span>'.$e($k).'</span><strong>'.$e($v).'</strong></div>';echo '</div>';
 echo '<h2>Resumo por profissional</h2><table><thead><tr><th>Barbeiro</th><th>Serviços</th><th>Comissão</th><th>Pendente</th><th>Paga</th></tr></thead><tbody>';
 foreach($report['professionals'] as $b)echo '<tr><td>'.$e($b['name']).'</td><td>'.$e($b['services']).'</td><td>'.$e($brl($b['commission_cents'])).'</td><td>'.$e($brl($b['pending_cents'])).'</td><td>'.$e($brl($b['paid_cents'])).'</td></tr>';
 echo '</tbody></table><h2>Serviços e comissões</h2><table><thead><tr><th>Fechamento</th><th>Barbeiro</th><th>Comanda</th><th>Serviço</th><th>Qtd.</th><th>Líquido</th><th>Taxa</th><th>Comissão</th><th>Status</th></tr></thead><tbody>';
 $q=biz_barber_report_rows($p,null);
 while($r=$q->fetch()){
  $status=!(int)$r['commission_id']?'Sem comissão':($r['paid_ledger_id']?'Paga':'Pendente');
  $cells=[$r['closed_at'],$r['barber_name'],'#'.$r['order_id'],$r['description'],$r['quantity'],$brl($r['net_cents']),number_format((int)$r['commission_bps']/100,2,',','.').'%', $brl($r['commission_cents']),$status];
  echo '<tr>';foreach($cells as $c)echo '<td>'.$e($c).'</td>';echo '</tr>';
 }
 echo '</tbody></table><p class="meta">Comissões registradas somente para serviços em comandas fechadas. Agendamentos concluídos sem fechamento não entram neste relatório.</p></body></html>';exit;
}
/** Monta expressão agregada usada pelo relatório financeiro. */
function biz_totals_sql(): string {
 return "SELECT o.*,c.name client_name,COALESCE(i.subtotal,0)-o.discount_cents total_cents,COALESCE(p.paid,0) paid_cents FROM mt_orders o LEFT JOIN mt_clients c ON c.id=o.client_id LEFT JOIN (SELECT order_id,SUM(quantity*unit_cents) subtotal FROM mt_order_items GROUP BY order_id) i ON i.order_id=o.id LEFT JOIN (SELECT order_id,SUM(amount_cents) paid FROM mt_ledger WHERE order_id IS NOT NULL GROUP BY order_id) p ON p.order_id=o.id";
}
/** Consulta dados administrativos sem realizar alterações. */
function biz_read(string $action,array $p): array {
 if($action==='barber_commission_report')return biz_barber_commission_report($p);
 if($action==='catalog')return ['clients'=>mt_can('financeiro') ? biz_query('SELECT id,name FROM mt_clients ORDER BY name')->fetchAll() : [],'services'=>mt_can('financeiro')?fetch_all('mt_services','sort_order'):[],'barbers'=>mt_can('financeiro')?fetch_all('mt_barbers','sort_order'):[],'products'=>mt_can_any(['estoque','financeiro'])?biz_query('SELECT * FROM mt_products ORDER BY name')->fetchAll():[]];
 if($action==='order')return ['order'=>biz_order((int)($p['id']??0))];
 if($action==='settings')return ['rates'=>biz_query("SELECT `key`,`value` FROM mt_settings WHERE `key` LIKE 'biz:commission:%'")->fetchAll(),'audit'=>biz_query('SELECT a.*,u.name actor FROM mt_business_audit a LEFT JOIN mt_admins u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 100')->fetchAll()];
 if($action==='products')return ['products'=>biz_query('SELECT * FROM mt_products ORDER BY name')->fetchAll(),'movements'=>biz_query('SELECT m.*,p.name FROM mt_stock_moves m JOIN mt_products p ON p.id=m.product_id ORDER BY m.id DESC LIMIT 100')->fetchAll()];
 if($action==='accounts')return ['accounts'=>biz_query('SELECT t.client_id,COALESCE(t.client_name,\'Balcão\') name,COUNT(*) orders,SUM(t.total_cents-t.paid_cents) due_cents FROM ('.biz_totals_sql().") t WHERE t.status='open' AND t.total_cents>t.paid_cents GROUP BY t.client_id,t.client_name ORDER BY due_cents DESC")->fetchAll()];
 if($action==='cash'){
  $cash=biz_query('SELECT * FROM mt_cash_sessions WHERE closed_at IS NULL ORDER BY id DESC LIMIT 1')->fetch();
  $methods=$cash?biz_query('SELECT method,SUM(amount_cents) amount_cents FROM mt_ledger WHERE cash_id=? GROUP BY method',[$cash['id']])->fetchAll():[];
  if($cash)$cash['balance_cents']=biz_balance($cash);
  return ['cash'=>$cash?:null,'methods'=>$methods,'history'=>biz_query('SELECT * FROM mt_cash_sessions ORDER BY id DESC LIMIT 100')->fetchAll()];
 }
 if($action==='commissions'){
  $where=" WHERE c.voided_at IS NULL";$args=[];
  if(($p['status']??'pending')==='pending')$where.=' AND c.paid_ledger_id IS NULL';else{$range=biz_range($p);$where.=' AND c.created_at>=? AND c.created_at<?';$args=$range;}
  if(!empty($p['barber_id'])){$where.=' AND c.barber_id=?';$args[]=(int)$p['barber_id'];}
  $page=biz_int($p['page']??1,1,1000000);$offset=($page-1)*100;
  return ['rows'=>biz_query('SELECT c.*,b.name FROM mt_commissions c LEFT JOIN mt_barbers b ON b.id=c.barber_id'.$where." ORDER BY c.id DESC LIMIT 100 OFFSET $offset",$args)->fetchAll(),'total'=>(int)biz_query('SELECT COUNT(*) FROM mt_commissions c'.$where,$args)->fetchColumn(),'page'=>$page];
 }
 $range=biz_range($p);$page=biz_int($p['page']??1,1,1000000);$offset=($page-1)*100;
 if($action==='orders'){
  $status=(string)($p['status']??'open');$where=' WHERE o.created_at>=? AND o.created_at<?';$args=$range;
  if($status==='open'){$where=" WHERE o.status='open'";$args=[];}elseif(in_array($status,['closed','cancelled'],true)){$where.=' AND o.status=?';$args[]=$status;}
  if(($p['client_id']??'')==='balcao')$where.=' AND o.client_id IS NULL';elseif(!empty($p['client_id'])){$where.=' AND o.client_id=?';$args[]=(int)$p['client_id'];}
  return ['rows'=>biz_query(biz_totals_sql().$where." ORDER BY o.id DESC LIMIT 100 OFFSET $offset",$args)->fetchAll(),'total'=>(int)biz_query('SELECT COUNT(*) FROM mt_orders o'.$where,$args)->fetchColumn(),'page'=>$page];
 }
 if($action==='ledger')return ['rows'=>biz_query('SELECT l.*,r.id reversed_by FROM mt_ledger l LEFT JOIN mt_ledger r ON r.reversal_of=l.id WHERE l.created_at>=? AND l.created_at<? ORDER BY l.id DESC LIMIT 100 OFFSET '.$offset,$range)->fetchAll(),'total'=>(int)biz_query('SELECT COUNT(*) FROM mt_ledger WHERE created_at>=? AND created_at<?',$range)->fetchColumn(),'page'=>$page];
 if($action==='report'){
  $financial=biz_query('SELECT COALESCE(SUM(CASE WHEN amount_cents>0 THEN amount_cents ELSE 0 END),0) income_cents,COALESCE(SUM(CASE WHEN amount_cents<0 THEN -amount_cents ELSE 0 END),0) expense_cents,COALESCE(SUM(amount_cents),0) net_cents FROM mt_ledger WHERE created_at>=? AND created_at<?',$range)->fetch();
  $orders=biz_query("SELECT COUNT(*) quantity,COALESCE(SUM(total_cents),0) total_cents FROM (".biz_totals_sql().") t WHERE t.status='closed' AND t.closed_at>=? AND t.closed_at<?",$range)->fetch();
  return ['financial'=>$financial,'sales'=>$orders,
   'open_orders'=>(int)biz_query("SELECT COUNT(*) FROM mt_orders WHERE status='open'")->fetchColumn(),
   'receivable_cents'=>(int)biz_query("SELECT COALESCE(SUM(total_cents-paid_cents),0) FROM (".biz_totals_sql().") t WHERE t.status='open'")->fetchColumn(),
   'low_stock'=>biz_query('SELECT name,stock,min_stock FROM mt_products WHERE active=1 AND stock<=min_stock ORDER BY stock,name')->fetchAll(),
   'methods'=>biz_query('SELECT method,SUM(amount_cents) amount_cents FROM mt_ledger WHERE created_at>=? AND created_at<? GROUP BY method',$range)->fetchAll(),
   'categories'=>biz_query('SELECT category,SUM(amount_cents) amount_cents FROM mt_ledger WHERE created_at>=? AND created_at<? GROUP BY category',$range)->fetchAll(),
   'daily'=>biz_query('SELECT DATE(created_at) day,SUM(amount_cents) amount_cents FROM mt_ledger WHERE created_at>=? AND created_at<? GROUP BY DATE(created_at) ORDER BY day',$range)->fetchAll(),
   'appointments'=>biz_query('SELECT status,COUNT(*) quantity,COALESCE(SUM(price),0) amount FROM mt_appointments WHERE starts_at>=? AND starts_at<? GROUP BY status',$range)->fetchAll(),
   'professionals'=>biz_query("SELECT b.name,COUNT(*) quantity,SUM(a.price) amount FROM mt_appointments a LEFT JOIN mt_barbers b ON b.id=a.barber_id WHERE a.status='completed' AND a.starts_at>=? AND a.starts_at<? GROUP BY b.id,b.name ORDER BY amount DESC",$range)->fetchAll(),
   'services'=>biz_query("SELECT i.description,SUM(i.quantity) quantity,SUM(i.quantity*i.unit_cents) gross_cents FROM mt_order_items i JOIN mt_orders o ON o.id=i.order_id WHERE o.status='closed' AND o.closed_at>=? AND o.closed_at<? GROUP BY i.kind,i.reference_id,i.description ORDER BY gross_cents DESC",$range)->fetchAll()];
 }
 biz_error('Consulta inválida.');
}
