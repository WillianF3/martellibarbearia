-- MARTELLI V22: NOVA tabela de solicitações VIP; execute UMA VEZ após vip_v21.sql.
-- Migração aditiva: não altera clientes, agendamentos, VIP vigentes ou caixa.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS mt_vip_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 client_name VARCHAR(120) NOT NULL,
 client_phone VARCHAR(15) NOT NULL,
 client_email VARCHAR(190) DEFAULT NULL,
 plan_id INT UNSIGNED NOT NULL,
 plan_name VARCHAR(100) NOT NULL,
 monthly_price_cents INT UNSIGNED NOT NULL,
 included_cuts SMALLINT UNSIGNED NOT NULL,
 notes VARCHAR(300) NOT NULL DEFAULT '',
 status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
 client_id BIGINT UNSIGNED DEFAULT NULL,
 membership_id BIGINT UNSIGNED DEFAULT NULL,
 reviewed_by BIGINT UNSIGNED DEFAULT NULL,
 reviewed_at DATETIME DEFAULT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_vip_requests_status (status,created_at),
 INDEX idx_vip_requests_phone (client_phone,created_at),
 CONSTRAINT mt_vip_request_plan FOREIGN KEY (plan_id) REFERENCES mt_vip_plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
