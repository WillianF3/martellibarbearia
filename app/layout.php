<?php
declare(strict_types=1);

function mt_site_base_url(): string {
    // Domínio público canônico: não confiar no cabeçalho Host enviado pelo visitante.
    return 'https://martellibarbearia.com';
}

function page_head(string $title, string $description = ''): void {
    $rawTitle = $title . ' | Barbearia Martelli';
    $rawDescription = $description ?: 'O corte perfeito tem sobrenome. Martelli. Agende um horário e descubra a tradição italiana em cada detalhe.';
    $title = h($rawTitle);
    $description = h($rawDescription);
    $baseUrl = mt_site_base_url();
    $requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $requestPath = is_string($requestPath) && str_starts_with($requestPath, '/') ? $requestPath : '/';
    $canonical = h($baseUrl . $requestPath);
    $ogImage = h($baseUrl . '/assets/og-image-black.jpg');

    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">';
    echo '<title>' . $title . '</title>';
    echo '<meta name="description" content="' . $description . '">';
    echo '<meta name="theme-color" content="#120f0d">';
    echo '<meta name="apple-mobile-web-app-capable" content="yes">';
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">';
    echo '<link rel="canonical" href="' . $canonical . '">';

    echo '<meta property="og:type" content="website">';
    echo '<meta property="og:site_name" content="Barbearia Martelli">';
    echo '<meta property="og:locale" content="pt_BR">';
    echo '<meta property="og:title" content="' . $title . '">';
    echo '<meta property="og:description" content="' . $description . '">';
    echo '<meta property="og:url" content="' . $canonical . '">';
    echo '<meta property="og:image" content="' . $ogImage . '">';
    echo '<meta property="og:image:secure_url" content="' . $ogImage . '">';
    echo '<meta property="og:image:type" content="image/jpeg">';
    echo '<meta property="og:image:width" content="1200">';
    echo '<meta property="og:image:height" content="630">';

    echo '<meta name="twitter:card" content="summary_large_image">';
    echo '<meta name="twitter:title" content="' . $title . '">';
    echo '<meta name="twitter:description" content="' . $description . '">';
    echo '<meta name="twitter:image" content="' . $ogImage . '">';

    echo '<link rel="icon" href="/assets/favicon.ico" sizes="any">';
    echo '<link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png">';
    echo '<link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16x16.png">';
    echo '<link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">';
    echo '<link rel="manifest" href="/assets/site.webmanifest">';

    echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="/css/martelli-v4-1.bundle.css?v=20261025-41">';
    if (in_array($requestPath,['/','/index.php','/vip.php','/vip-cadastro.php'],true)) echo '<link rel="stylesheet" href="/css/vip.css?v=37">';
    if ($requestPath === '/vip.php') echo '<link rel="stylesheet" href="/css/vip-google-signup-v39.css?v=39">';
    if (in_array($requestPath, ['/admin', '/admin/', '/admin.php'], true)) {
        echo '<link rel="stylesheet" href="/css/admin-day.css?v=20261028-14">';
    }
    if (in_array($requestPath, ['/barbeiro.php','/acessos.php'], true)) {
        echo '<link rel="stylesheet" href="/css/martelli-restricted-v9.css?v=20261101-day-nav">';
    }
    if ($requestPath === '/vip-cadastro.php') echo '<link rel="stylesheet" href="/css/vip-signup-v22.css?v=24"><link rel="stylesheet" href="/css/vip-login-v24.css?v=38"><link rel="stylesheet" href="/css/vip-google-signup-v39.css?v=39"><link rel="stylesheet" href="/css/vip-recovery-v43.css?v=43">';
    if (in_array($requestPath, ['/vip-login.php','/vip-entrar.php','/vip-senha.php','/vip-ativar.php','/vip-recuperar.php','/vip-redefinir.php'], true)) echo '<link rel="stylesheet" href="/css/vip-login-v24.css?v=38"><link rel="stylesheet" href="/css/vip-google-signup-v39.css?v=39"><link rel="stylesheet" href="/css/vip-recovery-v43.css?v=43">';
    if (in_array($requestPath,['/vip-entrar.php','/vip-senha.php','/vip-ativar.php','/vip-recuperar.php','/vip-redefinir.php'],true)) echo '<link rel="stylesheet" href="/css/vip-account-v44.css?v=44">';
    if (in_array($requestPath,['/vip-recuperar.php','/vip-redefinir.php'],true)) echo '<link rel="stylesheet" href="/css/vip-reset-v46.css?v=46">';
    if ($requestPath === '/vip-agenda.php') echo '<link rel="stylesheet" href="/css/vip-calendar-v23.css?v=23"><link rel="stylesheet" href="/css/vip-calendar-v26.css?v=55"><link rel="stylesheet" href="/css/vip-reschedule-v35.css?v=55">';
    if ($requestPath === '/agendar.php') {
        echo '<link rel="stylesheet" href="/css/booking-approval-v4-3.css?v=20261028-15">';
    }
    // Áreas internas: módulos de gestão do Martelli V8, sem alterar o visual público.
    if ($requestPath === '/funcoes.php') echo '<link rel="stylesheet" href="/css/functions-panel.css?v=1">';
    if ($requestPath === '/painel.php') {
        echo '<link rel="stylesheet" href="/css/business.css?v=martelli-v20">';
    }
    if (in_array($requestPath, ['/admin','/admin/','/admin.php','/gestao.php','/painel.php'], true)) {
        echo '<link rel="stylesheet" href="/css/admin-unified-v3.css?v=martelli-v8">';
        echo '<link rel="stylesheet" href="/css/admin-reference-v4.css?v=martelli-v8">';
        echo '<link rel="stylesheet" href="/css/admin-sidebar-v5.css?v=martelli-v8">';
        echo '<link rel="stylesheet" href="/css/martelli-admin-v8.css?v=martelli-v8">';
    }
    // Mantém as regras de cartões recolhíveis V13 como a última camada do painel.
    if (in_array($requestPath, ['/admin', '/admin/', '/admin.php'], true)) {
        echo '<link rel="stylesheet" href="/css/permissions-v10.css?v=13">';
        echo '<link rel="stylesheet" href="/css/barber-commissions-v22.css?v=20261102-1">';
    }
    echo '<link rel="stylesheet" href="/css/mobile-nav.css?v=2">';
    echo '</head><body>';
}

function site_header(): void {
    echo '<header class="site-header"><div class="container header-inner"><a href="/" class="brand" aria-label="Barbearia Martelli — início"><img src="/assets/logo-martelli-site.png" width="170" height="125" alt="Barbearia Martelli"></a>';
    echo '<nav class="main-nav" id="martelli-main-nav" aria-label="Navegação principal"><a href="/#servicos">Serviços</a><a href="/#barbeiros">Barbeiros</a><a href="/vip-cadastro.php">Martelli VIP</a><a href="/vip-entrar.php">Login</a><a href="/#horarios">Horários</a><a href="/#contato">Contato</a><a href="/login.php">Área do barbeiro</a><a class="button button-gold button-small" href="/agendar.php">Agendar <span aria-hidden="true">↗</span></a></nav>';
    echo '<button class="menu-toggle" type="button" aria-controls="martelli-main-nav" aria-expanded="false" aria-label="Abrir menu"><span class="menu-toggle-bars" aria-hidden="true"><span></span><span></span><span></span></span></button></div><div class="tricolore"></div></header>';
}

function site_footer(): void {
    echo '<footer class="footer"><div class="container footer-inner"><div><a href="/" aria-label="Voltar à página inicial da Barbearia Martelli"><img src="/assets/logo-martelli-site.png" width="170" height="125" alt="Barbearia Martelli"></a><p>O corte perfeito tem sobrenome. Martelli.</p></div><div><strong>Explorar</strong><a href="/#servicos">Serviços</a><a href="/agendar.php">Agendar horário</a><a href="/vip-cadastro.php">Martelli VIP</a><a href="/vip-entrar.php">Login</a><a href="/#contato">Contato</a><a href="/login.php">Área do barbeiro</a></div><div><strong>Informações</strong><a href="/privacidade.php">Privacidade</a><a href="/termos.php">Termos de uso</a><span>Brasil</span></div></div><div class="container copyright martelli-footer-bottom"><span>© ' . date('Y') . ' Barbearia Martelli · Todos os direitos reservados.</span><span class="developer-credit">Design e desenvolvimento por <a href="https://devwillian.com/" target="_blank" rel="noopener noreferrer" aria-label="Visitar site devwillian.com, responsável pelo design e desenvolvimento">devwillian.com <span aria-hidden="true">↗</span></a></span></div></footer>';
    site_mobile_nav();
}

function site_mobile_nav(): void {
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $current = match ($path) {
        '/', '/index.php' => 'home',
        '/agendar.php', '/agendar', '/agendar/' => 'booking',
        '/vip-cadastro.php', '/vip-entrar.php', '/vip-ativar.php', '/vip-senha.php',
        '/vip-redefinir.php', '/vip-recuperar.php' => 'vip',
        default => null,
    };
    $links = [
        'home' => ['/', 'Início', 'm3 10 9-7 9 7v10H3z M9 20v-7h6v7'],
        'booking' => ['/agendar.php', 'Agendar', 'M4 5h16v16H4z M8 3v4 M16 3v4 M4 10h16 M9 15l2 2 4-4'],
        'vip' => ['/vip-entrar.php', 'Área VIP', 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z M5 21v-2a7 7 0 0 1 14 0v2'],
        'contact' => ['/#contato', 'Contato', 'M21 11.5a8.5 8.5 0 0 1-8.5 8.5 9 9 0 0 1-3.6-.8L4 21l1.8-4.9a8.5 8.5 0 1 1 15.2-4.6Z'],
    ];
    echo '<nav class="martelli-mobile-nav" aria-label="Navegação rápida">';
    foreach ($links as $key => [$href, $label, $icon]) {
        echo '<a href="' . h($href) . '"' . ($key === $current ? ' aria-current="page"' : '') . '>';
        echo '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="' . h($icon) . '"/></svg>';
        echo '<span>' . h($label) . '</span></a>';
    }
    echo '</nav>';
}

function page_end(string $extraScripts = ''): void {
    $requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $adminScripts = in_array($requestPath, ['/admin','/admin/','/admin.php','/gestao.php','/painel.php'], true)
        ? '<script src="/js/admin-sidebar-v5.js?v=martelli-v10" defer></script>' : '';
    echo '<script src="/js/site.js?v=53" defer></script>' . $adminScripts . $extraScripts . '</body></html>';
}
