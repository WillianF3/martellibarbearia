-- MARTELLI V39 — Pré-cadastro VIP por Google (após V22 e V38).
-- Execute no BANCO MARTELLI selecionado. Não apaga clientes, assinaturas ou agendamentos.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS mt_vip_google_signup_requests (
  request_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  google_sub VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  google_email VARCHAR(190) NOT NULL,
  verified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_vip_signup_google_sub (google_sub),
  CONSTRAINT fk_vip_signup_request FOREIGN KEY (request_id)
    REFERENCES mt_vip_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
