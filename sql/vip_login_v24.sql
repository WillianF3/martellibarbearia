-- MARTELLI V24: login exclusivo dos clientes VIP. Aplicar DEPOIS das versões V21, V22 e V23.
-- Migração aditiva e idempotente; não altera agendas, cadastros ou assinaturas antigas.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS mt_vip_customer_accounts (
  client_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  password_hash VARCHAR(255) DEFAULT NULL,
  failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME DEFAULT NULL,
  session_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_vip_customer_account_client FOREIGN KEY(client_id) REFERENCES mt_clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
