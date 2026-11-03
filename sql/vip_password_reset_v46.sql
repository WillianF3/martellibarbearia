-- MARTELLI V46: recuperação VIP por e-mail. Execute no banco Martelli selecionado.
-- Antes: importe V24/V25. Sem exclusões ou mudanças em agendamentos/assinaturas.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS mt_vip_password_reset_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vip_reset_hash (token_hash),
  KEY idx_vip_reset_client_created (client_id,created_at),
  KEY idx_vip_reset_expires (expires_at),
  CONSTRAINT fk_vip_reset_client FOREIGN KEY(client_id) REFERENCES mt_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
