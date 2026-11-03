<?php
/**
 * Barbearia Martelli V30 — rotina de exclusão automática desativada.
 *
 * Mantido um arquivo inofensivo neste caminho para neutralizar tarefas cron
 * antigas que possam ainda chamá-lo na Hostinger. Não usa conexão MySQL.
 *
 * Remova também o cron correspondente no painel da Hostinger.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
fwrite(STDOUT, "Martelli V30: limpeza automatica de agendamentos DESATIVADA. Nenhum dado foi removido.\n");
exit(0);
