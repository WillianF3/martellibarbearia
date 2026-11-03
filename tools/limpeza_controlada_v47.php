<?php
/**
 * MARTELLI V47 - LIMPEZA CONTROLADA DE CADASTROS E RESERVAS.
 * EXCLUSIVAMENTE VIA CLI (SSH). Nenhum endpoint web.
 * Por padrão faz somente prévia; exige --execute, --backup-confirmado e frase exata.
 * NÃO remove: planos, barbeiros, serviços, horários, admins, configurações, produtos.
 * NÃO remove histórico financeiro; RECUSA limpar clientes se existir qualquer dado financeiro.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
require __DIR__.'/../app/bootstrap.php';
$execute = in_array('--execute', $argv, true);
$backup = in_array('--backup-confirmado', $argv, true);
$phrase = in_array('--confirm=EXCLUIR_CLIENTES_E_RESERVAS', $argv, true);
$deleteInOrder = [
    'mt_vip_google_signup_requests', 'mt_vip_apple_signup_requests',
    'mt_vip_password_reset_tokens', 'mt_vip_appointments',
    'mt_vip_access_links', 'mt_vip_uses', 'mt_vip_google_accounts',
    'mt_vip_apple_accounts', 'mt_vip_customer_accounts', 'mt_vip_requests',
    'mt_vip_memberships', 'mt_appointments', 'mt_clients'
];
// Proteção conservadora: o sistema não apaga pagamentos, comandas, auditorias nem estoque.
$financeOrAudit = [
    'mt_orders', 'mt_order_items', 'mt_cash_sessions', 'mt_ledger',
    'mt_commissions', 'mt_stock_moves', 'mt_business_audit'
];
$alwaysKeep = [
    'mt_vip_plans','mt_barbers','mt_services','mt_business_hours',
    'mt_settings','mt_admins','mt_access_owner','mt_admin_access',
    'mt_barber_users','mt_products',...$financeOrAudit
];
$pdo=db();
$database=$pdo->query('SELECT DATABASE()')->fetchColumn();
if (!is_string($database) || $database==='') throw new RuntimeException('Selecione o banco Martelli.');
$st=$pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema=? AND table_name LIKE ?');
$st->execute([$database,'mt\\_%']);
$found=array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN),true);
$rows=[];
foreach (array_unique([...$deleteInOrder,...$financeOrAudit,...$alwaysKeep]) as $table) {
    if (!isset($found[$table]))continue;
    // Os nomes vêm de lista constante interna; não recebem entrada do usuário.
    $rows[$table]=(int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
}
echo "BANCO: ".$database."\nAÇÃO: ".($execute?'EXECUÇÃO SOLICITADA':'SOMENTE PRÉVIA')."\n\n";
foreach ($deleteInOrder as $table) echo sprintf("%6d  %s  %s\n",$rows[$table]??0,$table,isset($found[$table])?'SERIA EXCLUÍDO':'inexistente');
echo "\nPRESERVADOS (inclui finance, se houver):\n";
foreach ($alwaysKeep as $table) echo sprintf("%6d  %s\n",$rows[$table]??0,$table);
if (!$execute) {echo "\nNenhuma modificação realizada. Faça BACKUP COMPLETO antes de uma limpeza.\n";exit(0);}
if (!$backup || !$phrase)throw new RuntimeException('Execução negada. Requer --backup-confirmado e --confirm=EXCLUIR_CLIENTES_E_RESERVAS.');
// Não permitir apagar cadastros que tenham ligação financeira, fiscal ou auditável.
foreach ($financeOrAudit as $table) {
    if (($rows[$table]??0)>0)throw new RuntimeException('Limpeza recusada: '.$table.' contém registros financeiros/auditoria. Preserve o histórico e revise com responsável.');
}
// Qualquer tabela inesperada com linhas pode ter vínculo com clientes não coberto pela lista de exclusão.
foreach ($found as $table=>$_) {
    if (in_array($table,$deleteInOrder,true)||in_array($table,$alwaysKeep,true))continue;
    $count=(int)$pdo->query('SELECT COUNT(*) FROM `'.str_replace('`','``',$table).'`')->fetchColumn();
    if ($count>0)throw new RuntimeException('Limpeza recusada: tabela desconhecida '.$table.' possui dados.');
}
// Detectar dependências de chaves estrangeiras que apontam aos registros a remover.
$q=$pdo->prepare('SELECT table_name,referenced_table_name FROM information_schema.key_column_usage WHERE table_schema=? AND referenced_table_name IS NOT NULL');
$q->execute([$database]);
foreach ($q->fetchAll() as $fk) {
    if (in_array($fk['referenced_table_name'],$deleteInOrder,true)
       && !in_array($fk['table_name'],$deleteInOrder,true)) {
        throw new RuntimeException('Limpeza recusada: FK inesperada '.$fk['table_name'].' -> '.$fk['referenced_table_name']);
    }
}
$lk=$pdo->query("SELECT GET_LOCK('martelli_limpeza_v47',5)")->fetchColumn();
if ((int)$lk!==1)throw new RuntimeException('Outro processo de limpeza está em andamento.');
try {
    $pdo->beginTransaction();
    foreach ($deleteInOrder as $table) {
        if (isset($found[$table])) {
            $deleted=$pdo->exec('DELETE FROM `'.$table.'`');
            echo 'EXCLUÍDOS: '.$table.' = '.$deleted."\n";
        }
    }
    $pdo->commit();
    echo "Concluído: somente cadastros/reservas, preservando as tabelas protegidas.\n";
} catch(Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    throw $e;
} finally {
    $pdo->query("SELECT RELEASE_LOCK('martelli_limpeza_v47')");
}
