<?php
/** Copie como app/apple_oauth.local.php e guarde a chave privada fora do repositório. */
return [
    'client_id' => 'com.martelli.vip.web', // Apple Services ID cadastrado
    'team_id' => 'SEU_TEAM_ID',
    'key_id' => 'SEU_KEY_ID',
    'private_key_path' => __DIR__ . '/apple_auth_key.p8',
    'redirect_uri' => 'https://martellibarbearia.com/vip-apple.php',
];
