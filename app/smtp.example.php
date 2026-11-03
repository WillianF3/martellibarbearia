<?php
// Copie este arquivo para app/smtp.local.php; NUNCA publique ou envie sua senha de e-mail.
// A senha deve ser da CAIXA DE E-MAIL Hostinger, não a senha de login do hPanel.
return [
    'host' => 'smtp.hostinger.com',
    'port' => 465, // SSL/TLS. Alternativa: 587 com STARTTLS.
    'username' => 'contato@martellibarbearia.com',
    'password' => 'COLOQUE_A_SENHA_DA_CAIXA_EMAIL_AQUI',
    'from_email' => 'contato@martellibarbearia.com',
    'from_name' => 'Barbearia Martelli',
];
