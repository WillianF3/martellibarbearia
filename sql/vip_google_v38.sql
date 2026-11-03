-- MARTELLI V38 — Contas Google vinculadas a cadastros VIP.
-- Execute após as migrações de login VIP V24/V25, no banco Martelli selecionado.
-- Idempotente: não exclui clientes, senhas, assinaturas ou agendamentos.
CREATE TABLE IF NOT EXISTS mt_vip_google_accounts (
    client_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    google_sub VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    google_email VARCHAR(190) NOT NULL,
    linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME DEFAULT NULL,
    UNIQUE KEY mt_vip_google_sub_unique (google_sub),
    CONSTRAINT fk_mt_vip_google_client FOREIGN KEY (client_id) REFERENCES mt_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
