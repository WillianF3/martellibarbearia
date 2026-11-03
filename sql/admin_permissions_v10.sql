-- Martelli V10: migração ADITIVA, sem excluir contas ou agendamentos.
-- Antes de executar, confirme SELECT id,name,email FROM mt_admins ORDER BY id;
-- A conta principal é vinculada pelo ID encontrado para o e-mail indicado uma ÚNICA vez.
-- Caso o SELECT final não mostre o dono, NÃO coloque o site em produção.
CREATE TABLE IF NOT EXISTS mt_access_owner (
 id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
 admin_id BIGINT UNSIGNED NOT NULL UNIQUE,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT mt_access_owner_admin FOREIGN KEY (admin_id) REFERENCES mt_admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS mt_admin_access (
 admin_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 enabled TINYINT(1) NOT NULL DEFAULT 0,
 permissions TEXT NOT NULL,
 barber_id INT UNSIGNED NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT mt_access_admin FOREIGN KEY (admin_id) REFERENCES mt_admins(id) ON DELETE CASCADE,
 CONSTRAINT mt_access_barber FOREIGN KEY (barber_id) REFERENCES mt_barbers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Se ainda não houver proprietário, seleciona somente a conta cadastrada com o e-mail autorizado.
-- Nunca altera automaticamente o proprietário já definido.
INSERT IGNORE INTO mt_access_owner (id,admin_id)
 SELECT 1,id FROM mt_admins WHERE LOWER(TRIM(email))='willianf0071@gmail.com' LIMIT 1;
-- Todas as outras contas começam sem acesso; permissões serão concedidas pelo responsável.
INSERT IGNORE INTO mt_admin_access (admin_id, enabled, permissions, barber_id)
 SELECT id,0,'[]',NULL FROM mt_admins WHERE id NOT IN (SELECT admin_id FROM mt_access_owner);
SELECT o.admin_id AS proprietario_id,a.name,a.email FROM mt_access_owner o JOIN mt_admins a ON a.id=o.admin_id;
