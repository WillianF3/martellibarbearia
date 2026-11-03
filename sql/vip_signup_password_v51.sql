-- MARTELLI V51 – senha criada no pré-cadastro, ativada somente após a aprovação.
-- Faça backup. No phpMyAdmin, selecione o banco Martelli antes de importar.
-- Adiciona SOMENTE uma coluna nullable; não altera clientes, planos, profissionais ou agendamentos.
-- Execute depois das migrações V22, V24 e V25.
USE `u664280783_martellidb`;
SET @has_password_col = (
 SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mt_vip_requests'
   AND COLUMN_NAME = 'registration_password_hash'
);
SET @add_password_col = IF(@has_password_col = 0,
 'ALTER TABLE mt_vip_requests ADD COLUMN registration_password_hash VARCHAR(255) DEFAULT NULL',
 'SELECT ''V51 já instalada'' AS estado');
PREPARE v51_stmt FROM @add_password_col;
EXECUTE v51_stmt;
DEALLOCATE PREPARE v51_stmt;
