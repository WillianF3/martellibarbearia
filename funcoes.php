<?php
require __DIR__.'/app/bootstrap.php';
if (!mt_is_owner()) { header('Location: '.mt_start_page()); exit; }
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');
require __DIR__.'/app/layout.php';
page_head('Ativar ou desativar funções');
?>
<main class="functions-page">
  <header class="functions-header">
    <a href="/admin.php#configuracoes" class="functions-back">← Voltar ao painel</a>
    <img src="/assets/logo-martelli-site.png" alt="Barbearia Martelli" width="132" height="78">
    <span class="functions-eyebrow">PAINEL ADMINISTRATIVO · CONFIGURAÇÕES</span>
    <h1>Ativar ou desativar funções</h1>
    <p>Escolha quais módulos estarão disponíveis na administração. Suas alterações são salvas no banco de dados e valem para todos os acessos do painel.</p>
  </header>
  <section class="functions-content" aria-labelledby="functions-title">
    <div class="functions-title"><div><h2 id="functions-title">Controle de módulos</h2><p>Ative ou desative cada função usando o interruptor.</p></div><span id="functions-count" aria-live="polite">Carregando…</span></div>
    <div id="functions-message" class="functions-message" role="status" aria-live="polite" hidden></div>
    <div id="functions-list" aria-busy="true"><p>Carregando configurações…</p></div>
  </section>
  <aside class="functions-note"><strong>Importante:</strong> desativar um módulo oculta seu acesso administrativo e bloqueia suas rotas protegidas; nenhum registro será excluído. Agendamentos pelo site, serviços públicos e acesso próprio dos barbeiros não são desligados por esses interruptores. Para parar novas reservas, use <a href="/admin.php#configuracoes">Configurações da agenda</a> e desative “Permitir reservas pelo site”.</aside>
</main>
<script>window.MARTELLI_CSRF=<?=json_encode(csrf())?>;</script>
<?php page_end('<script src="/js/functions-panel.js?v=1" defer></script>'); ?>
