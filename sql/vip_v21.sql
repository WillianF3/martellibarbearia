-- MARTELLI VIP V21 — migração aditiva, executar UMA VEZ no phpMyAdmin.
-- Não exclui, altera ou reinicializa clientes, agendamentos, caixas ou comandas.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mt_vip_plans (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  monthly_price_cents INT UNSIGNED NOT NULL,
  cuts_per_month SMALLINT UNSIGNED NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mt_vip_memberships (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  -- Congela preço e quantidade contratados no início da assinatura.
  monthly_price_cents INT UNSIGNED NOT NULL,
  included_cuts SMALLINT UNSIGNED NOT NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NOT NULL,
  status ENUM('active','paused','cancelled') NOT NULL DEFAULT 'active',
  notes VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX mt_vip_membership_client (client_id,starts_on,ends_on),
  INDEX mt_vip_membership_plan (plan_id),
  CONSTRAINT mt_vip_member_client FOREIGN KEY (client_id) REFERENCES mt_clients(id),
  CONSTRAINT mt_vip_member_plan FOREIGN KEY (plan_id) REFERENCES mt_vip_plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mt_vip_uses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  membership_id BIGINT UNSIGNED NOT NULL,
  used_on DATE NOT NULL,
  notes VARCHAR(200) NOT NULL DEFAULT '',
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX mt_vip_use_member (membership_id,used_on),
  CONSTRAINT mt_vip_use_member FOREIGN KEY (membership_id) REFERENCES mt_vip_memberships(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
