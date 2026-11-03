-- MARTELLI V23: agenda VIP individual. Aplicar APÓS migrações V21 e V22.
-- Apenas tabelas adicionais: não altera cadastros, agendamentos ou assinaturas existentes.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS mt_vip_access_links (
  membership_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  rotated_at DATETIME DEFAULT NULL,
  CONSTRAINT mt_vip_access_membership FOREIGN KEY (membership_id) REFERENCES mt_vip_memberships(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS mt_vip_appointments (
  membership_id BIGINT UNSIGNED NOT NULL,
  appointment_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_vip_appointments_membership (membership_id),
  CONSTRAINT mt_vip_appt_membership FOREIGN KEY (membership_id) REFERENCES mt_vip_memberships(id),
  CONSTRAINT mt_vip_appt_booking FOREIGN KEY (appointment_id) REFERENCES mt_appointments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
