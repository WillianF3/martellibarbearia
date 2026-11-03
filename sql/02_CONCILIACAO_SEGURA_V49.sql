-- MARTELLI V49: PROCEDIMENTO COM VERIFICAÇÕES PARA MESCLAR DOIS CADASTROS
-- IMPORTE ESTE ARQUIVO SOMENTE APÓS BACKUP COMPLETO E CONFERÊNCIA DOCUMENTAL PELO ADMINISTRADOR.
-- Não executa nenhuma mesclagem ao ser importado. Leia o manual antes de chamar o procedimento.
-- Fonte = cadastro que possui o WhatsApp correto; Destino = cadastro que possui o CPF correto.
-- Conserva agendamentos, comandas, assinaturas, vínculos Google/Apple e movimentos financeiros.
-- Recusa mesclagem se as duas contas tiverem credenciais distintas ou assinaturas vigentes sobrepostas,
-- ou se existirem relações desconhecidas com mt_clients.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS mt_client_merges_v49 (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 old_client_id BIGINT UNSIGNED NOT NULL,
 kept_client_id BIGINT UNSIGNED NOT NULL,
 old_phone VARCHAR(15) NOT NULL,
 kept_phone_before VARCHAR(15) NOT NULL,
 merged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 note VARCHAR(240) NOT NULL,
 KEY idx_client_merges_old (old_client_id),
 KEY idx_client_merges_kept (kept_client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP PROCEDURE IF EXISTS mt_conciliar_cadastro_v49;
DELIMITER $$
CREATE PROCEDURE mt_conciliar_cadastro_v49(
 IN p_cpf_client BIGINT UNSIGNED,
 IN p_phone_client BIGINT UNSIGNED,
 IN p_expected_cpf VARCHAR(11),
 IN p_expected_phone VARCHAR(15),
 IN p_confirm VARCHAR(80)
)
BEGIN
 DECLARE v_cpf VARCHAR(11);
 DECLARE v_phone_cpf VARCHAR(15);
 DECLARE v_phone_from VARCHAR(15);
 DECLARE v_name_from VARCHAR(120);
 DECLARE v_fk_unknown INT DEFAULT 0;
 DECLARE v_conflicts INT DEFAULT 0;
 DECLARE v_count INT DEFAULT 0;
 DECLARE v_table VARCHAR(64);
 DECLARE v_tables VARCHAR(2000) DEFAULT 'mt_appointments,mt_orders,mt_vip_memberships,mt_vip_customer_accounts,mt_vip_google_accounts,mt_vip_apple_accounts,mt_vip_password_reset_tokens';
 DECLARE EXIT HANDLER FOR SQLEXCEPTION
 BEGIN
   ROLLBACK;
   RESIGNAL;
 END;
 IF DATABASE() IS NULL OR DATABASE()='information_schema' THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Selecione o banco correto do Martelli.';
 END IF;
 IF p_confirm <> 'CONFERI_IDENTIDADE_E_BACKUP_V49' THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Confirme identidade e backup antes de conciliar.';
 END IF;
 IF p_cpf_client=0 OR p_phone_client=0 OR p_cpf_client=p_phone_client OR LENGTH(p_expected_cpf)<>11 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='IDs/CPF invalidos.';
 END IF;
 -- FK adicional aponta para mt_clients? Recusar esquema desconhecido, nunca forçar FOREIGN_KEY_CHECKS=0.
 SELECT COUNT(*) INTO v_fk_unknown FROM information_schema.KEY_COLUMN_USAGE
 WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='mt_clients'
 AND NOT (FIND_IN_SET(TABLE_NAME, v_tables)>0 AND COLUMN_NAME='client_id');
 IF v_fk_unknown>0 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Encontrada tabela ligada a clientes nao mapeada: revisar estrutura do banco.';
 END IF;
 START TRANSACTION;
 -- Bloqueia e confirma que o CPF e o telefone pertencem aos IDs informados.
 SELECT COUNT(*) INTO v_count FROM mt_clients WHERE id IN(p_cpf_client,p_phone_client);
 IF v_count<>2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Cadastros esperados nao encontrados.'; END IF;
 SELECT cpf,phone INTO v_cpf,v_phone_cpf FROM mt_clients WHERE id=p_cpf_client FOR UPDATE;
 SELECT phone,name INTO v_phone_from,v_name_from FROM mt_clients WHERE id=p_phone_client FOR UPDATE;
 IF v_cpf IS NULL OR v_cpf<>p_expected_cpf OR v_phone_from IS NULL OR v_phone_from<>p_expected_phone THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CPF/telefone divergentes; revise os registros. Nenhuma alteracao realizada.';
 END IF;
 -- Bloqueia conta de outro CPF no cadastro que possui telefone.
 SELECT COUNT(*) INTO v_conflicts FROM mt_clients WHERE id=p_phone_client AND cpf IS NOT NULL AND cpf<>p_expected_cpf;
 IF v_conflicts<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='O cadastro do WhatsApp possui CPF diferente.'; END IF;
 -- Não mistura senhas/Google/Apple de contas distintas sem validação adicional.
 SELECT COUNT(*) INTO v_conflicts FROM mt_vip_customer_accounts WHERE client_id IN(p_cpf_client,p_phone_client);
 IF v_conflicts>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ambos cadastros possuem login VIP. Conciliacao manual obrigatoria.'; END IF;
 SELECT COUNT(*) INTO v_conflicts FROM mt_vip_google_accounts WHERE client_id IN(p_cpf_client,p_phone_client);
 IF v_conflicts>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ambos cadastros possuem contas Google. Conciliacao manual obrigatoria.'; END IF;
 SELECT COUNT(*) INTO v_conflicts FROM mt_vip_apple_accounts WHERE client_id IN(p_cpf_client,p_phone_client);
 IF v_conflicts>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ambos cadastros possuem contas Apple. Conciliacao manual obrigatoria.'; END IF;
 -- Também impede unir dois contratos ativos/pausados de períodos sobrepostos.
 SELECT COUNT(*) INTO v_conflicts
 FROM mt_vip_memberships x JOIN mt_vip_memberships y
   ON x.client_id=p_cpf_client AND y.client_id=p_phone_client
 WHERE x.status<>'cancelled' AND y.status<>'cancelled'
   AND x.starts_on<=y.ends_on AND y.starts_on<=x.ends_on;
 IF v_conflicts>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ha assinaturas vigentes sobrepostas; revisar antes de unir.'; END IF;
 -- Relações históricas permanecem; somente a identidade do cliente é consolidada.
 UPDATE mt_appointments SET client_id=p_cpf_client WHERE client_id=p_phone_client;
 UPDATE mt_orders SET client_id=p_cpf_client WHERE client_id=p_phone_client;
 UPDATE mt_vip_memberships SET client_id=p_cpf_client WHERE client_id=p_phone_client;
 UPDATE mt_vip_requests SET client_id=p_cpf_client WHERE client_id=p_phone_client;
 UPDATE mt_vip_customer_accounts SET client_id=p_cpf_client,session_version=session_version+1 WHERE client_id=p_phone_client;
 UPDATE mt_vip_google_accounts SET client_id=p_cpf_client WHERE client_id=p_phone_client;
 UPDATE mt_vip_apple_accounts SET client_id=p_cpf_client WHERE client_id=p_phone_client;
 -- Tokens de recuperação são temporários e deixam de valer após conciliação.
 DELETE FROM mt_vip_password_reset_tokens WHERE client_id IN(p_cpf_client,p_phone_client);
 INSERT INTO mt_client_merges_v49(old_client_id,kept_client_id,old_phone,kept_phone_before,note)
 VALUES(p_phone_client,p_cpf_client,v_phone_from,v_phone_cpf,'Mesclagem autorizada após verificar identidade e backup; históricos preservados.');
 DELETE FROM mt_clients WHERE id=p_phone_client;
 -- O telefone confirmado torna-se o telefone do cadastro canônico.
 UPDATE mt_clients SET phone=p_expected_phone WHERE id=p_cpf_client;
 UPDATE mt_vip_customer_accounts SET session_version=session_version+1 WHERE client_id=p_cpf_client;
 COMMIT;
 SELECT 'CONCILIACAO_CONCLUIDA' AS situacao, p_cpf_client AS cliente_consolidado;
END$$
DELIMITER ;
-- NÃO CHAME AUTOMATICAMENTE. EXEMPLO (somente após conferir os IDs e possuir backup):
-- CALL mt_conciliar_cadastro_v49(ID_COM_CPF, ID_COM_WHATSAPP, 'CPF_11_DIGITOS', 'DDD_NUMERO', 'CONFERI_IDENTIDADE_E_BACKUP_V49');
