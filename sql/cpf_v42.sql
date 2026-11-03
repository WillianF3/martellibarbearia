-- MARTELLI V42 — CPF único para cadastros futuros; não exclui clientes existentes.
-- Selecione o banco MARTELLI no phpMyAdmin ANTES de executar.
-- Execute somente após backup. Clientes antigos permanecem com CPF NULL até atualização.
SET NAMES utf8mb4;
SET @q = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mt_clients' AND COLUMN_NAME='cpf')=0,
 'ALTER TABLE mt_clients ADD COLUMN cpf CHAR(11) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL', 'SELECT 1');
PREPARE mt_cpf_ddl FROM @q; EXECUTE mt_cpf_ddl; DEALLOCATE PREPARE mt_cpf_ddl;
SET @q = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mt_clients' AND INDEX_NAME='mt_clients_cpf_unique')=0,
 'ALTER TABLE mt_clients ADD UNIQUE KEY mt_clients_cpf_unique (cpf)', 'SELECT 1');
PREPARE mt_cpf_ddl FROM @q; EXECUTE mt_cpf_ddl; DEALLOCATE PREPARE mt_cpf_ddl;
SET @q = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mt_vip_requests' AND COLUMN_NAME='cpf')=0,
 'ALTER TABLE mt_vip_requests ADD COLUMN cpf CHAR(11) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL', 'SELECT 1');
PREPARE mt_cpf_ddl FROM @q; EXECUTE mt_cpf_ddl; DEALLOCATE PREPARE mt_cpf_ddl;
SET @q = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mt_vip_requests' AND INDEX_NAME='mt_vip_requests_cpf_idx')=0,
 'ALTER TABLE mt_vip_requests ADD KEY mt_vip_requests_cpf_idx (cpf,status)', 'SELECT 1');
PREPARE mt_cpf_ddl FROM @q; EXECUTE mt_cpf_ddl; DEALLOCATE PREPARE mt_cpf_ddl;
-- Checar os registros; CPFs antigos são NULL e devem ser conferidos manualmente.
SELECT COUNT(*) AS total_clientes, SUM(cpf IS NULL) AS cpf_pendente FROM mt_clients;
