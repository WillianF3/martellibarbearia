<?php
/** Verificações leves de disponibilidade; nunca envia segredos para o navegador. */
declare(strict_types=1);
require_once __DIR__.'/vip_google_oauth.php';
require_once __DIR__.'/vip_apple_oauth.php';
function vip_oauth_available(string $provider, bool $signup=false): bool {
    try {
        if ($provider==='google') {
            vip_google_config();
            if (!vip_google_ready()) return false;
            return !$signup || db()->query("SHOW TABLES LIKE 'mt_vip_google_signup_requests'")->fetchColumn()!==false;
        }
        if ($provider==='apple') {
            vip_apple_config();
            if (!vip_apple_ready()) return false;
            return !$signup || vip_apple_signup_ready();
        }
    } catch (Throwable $e) { /* indisponivel, nunca expor detalhes ao visitante */ }
    return false;
}
