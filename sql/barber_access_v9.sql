-- Martelli V9 — migração ADITIVA (importar apenas no banco de dados Martelli).
-- Não executa DROP, DELETE, nem altera agendamentos existentes.
CREATE TABLE IF NOT EXISTS mt_barber_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  barber_id INT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_barber_user (barber_id),
  UNIQUE KEY uniq_barber_email (email),
  CONSTRAINT fk_mt_barber_user_barber FOREIGN KEY (barber_id) REFERENCES mt_barbers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
