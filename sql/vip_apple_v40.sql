-- Martelli V40: primeiro aplique V24, V25, V38, V39.
-- Importar no banco Martelli selecionado, após backup.
-- Não modifica nem exclui históricos existentes.
CREATE TABLE IF NOT EXISTS mt_vip_apple_accounts (
 client_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 apple_sub VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 apple_email VARCHAR(190) NOT NULL,
 linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_login_at DATETIME DEFAULT NULL,
 UNIQUE KEY uniq_martelli_apple_sub (apple_sub),
 CONSTRAINT fk_martelli_apple_client FOREIGN KEY (client_id) REFERENCES mt_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS mt_vip_apple_signup_requests (
 request_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 apple_sub VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 apple_email VARCHAR(190) NOT NULL,
 verified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_martelli_apple_signup_sub (apple_sub),
 CONSTRAINT fk_martelli_apple_request FOREIGN KEY (request_id) REFERENCES mt_vip_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
