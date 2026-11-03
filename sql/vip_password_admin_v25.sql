-- MARTELLI V25: troca segura de senha de assinantes VIP.
-- Execute APÓS sql/vip_login_v24.sql. Não remove dados existentes.
SET NAMES utf8mb4;
SET @v25_col = (
 SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mt_vip_customer_accounts'
 AND COLUMN_NAME = 'must_change_password'
);
SET @v25_sql = IF(@v25_col=0,
 'ALTER TABLE mt_vip_customer_accounts ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash',
 'SELECT 1 AS installed');
PREPARE martelli_v25_stmt FROM @v25_sql;
EXECUTE martelli_v25_stmt;
DEALLOCATE PREPARE martelli_v25_stmt;
