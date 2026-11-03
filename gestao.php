<?php
require __DIR__.'/app/bootstrap.php';
if (!mt_can('lista_espera')) { header('Location: '.mt_start_page()); exit; }
header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow');
require __DIR__.'/app/layout.php'; page_head('Gestão e relatórios');
?>
<div class="management-shell"><aside id="admin-nav-drawer" class="management-sidebar cabra-unified-sidebar" aria-label="Menu de gestão">
<button type="button" class="admin-drawer-close" data-admin-drawer-close aria-label="Fechar menu administrativo">×</button>
<a class="cabra-sidebar-logo" href="/" aria-label="Voltar à página inicial da Barbearia Martelli"><img src="/assets/logo-martelli-site.png" width="174" alt="Barbearia Martelli"><span>GESTÃO DO NEGÓCIO</span></a>
<nav aria-label="Menu administrativo" class="cabra-nav">
  <div class="cabra-nav-group"><p class="cabra-nav-label">01 · Agendamentos</p>
    <a href="/admin.php#agenda">Agenda geral</a><a href="/admin.php#dia">Agendamentos do dia</a><a href="/admin.php#todos">Todos os agendamentos</a><a href="/gestao.php#lista-espera">Lista de espera e bloqueios</a>
  </div>
  <div class="cabra-nav-group"><p class="cabra-nav-label">02 · Clientes e equipe</p>
    <a href="/admin.php#clientes">Clientes</a><a href="/vip.php">Clientes VIP</a><a href="/admin.php#servicos">Serviços</a><a href="/admin.php#barbeiros">Barbeiros</a><a href="/admin.php#horarios">Horários</a>
  </div>
  <div class="cabra-nav-group"><p class="cabra-nav-label">03 · Financeiro e estoque</p>
    <a href="/painel.php#comandas">Comandas</a><a href="/painel.php#contas">Contas de clientes</a><a href="/painel.php#financeiro">Visão financeira</a><a href="/painel.php#caixa">Caixa e histórico</a><a href="/painel.php#movimentos">Entradas e saídas</a><a href="/painel.php#comissoes">Comissões</a><a href="/painel.php#estoque">Produtos e estoque</a>
  </div>
  <div class="cabra-nav-group"><p class="cabra-nav-label">04 · Gestão e sistema</p>
    <a href="/painel.php#dashboard">Dashboard</a><a href="/painel.php#relatorios">Relatórios</a><a href="/admin.php#configuracoes">Configurações da agenda</a><a href="/admin.php#administradores">Administradores</a><a href="/acessos.php">Acessos dos barbeiros</a><a href="/painel.php#configuracoes">Configurações de gestão</a><?php if(mt_is_owner()): ?><a href="/funcoes.php">Ativar / desativar funções</a><?php endif; ?>
  </div>
</nav>
<div class="cabra-sidebar-bottom"><a class="cabra-site-link" href="/">← Ver site</a></div></aside><button type="button" class="admin-drawer-backdrop" id="admin-drawer-backdrop" tabindex="-1" aria-label="Fechar menu administrativo" aria-hidden="true"></button>
<main class="management-content">
<div class="management-top-actions"><button type="button" class="admin-menu-trigger" data-admin-drawer-trigger aria-controls="admin-nav-drawer" aria-expanded="false" aria-label="Abrir menu administrativo"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg><span>Menu</span></button><a href="/admin.php#agenda" class="management-back">← Voltar à agenda</a></div>
<div class="management-intro"><span class="eyebrow">BARBEARIA MARTELLI / ORGANIZAÇÃO</span>
<h1>Lista de espera e bloqueios</h1>
<p>Gerencie a fila de clientes, os períodos indisponíveis e o relatório da agenda em áreas separadas.</p></div>
<div id="management-message" role="status" class="notice" hidden></div>
<section id="lista-espera" class="admin-section management-section"><h2>Lista de espera</h2>
<p>O cadastro não reserva horário. Ao surgir uma vaga, use Novo agendamento na agenda e depois remova a solicitação da lista.</p>
<form id="wait-form" class="modal-fields">
<label>Nome<input name="name" required minlength="2" maxlength="120" autocomplete="off"></label>
<label>Telefone com DDD<input name="phone" type="tel" required maxlength="20" autocomplete="off"></label>
<label>Data desejada<input name="date" type="date" required></label>
<label>Profissional<select name="barber_id" required></select></label>
<label>Serviço<select name="service_id" required></select></label>
<label>Preferências de horário<textarea name="notes" maxlength="500"></textarea></label>
<button class="button button-gold">Adicionar à espera</button></form>
<div id="wait-list"></div></section>
<section id="bloqueios" class="admin-section management-section"><h2>Bloqueios de horários</h2>
<p>Bloqueios impedem novas reservas e remarcações, inclusive no agendamento público. Reservas existentes precisam ser remarcadas antes do bloqueio.</p>
<form id="block-form" class="modal-fields">
<label>Data<input type="date" name="date" required></label>
<label>Profissional<select name="barber_id" required></select></label>
<label>Início<input type="time" name="start" required></label>
<label>Fim<input type="time" name="end" required></label>
<label>Motivo<textarea name="notes" maxlength="500"></textarea></label>
<button class="button button-gold">Bloquear período</button></form><div id="block-list"></div></section>
<?php if (mt_can('relatorios')): ?><section id="relatorios-periodo" class="admin-section management-section"><h2>Relatórios por período</h2>
<p>Valores de serviços registrados na agenda. Concluído não significa pagamento recebido; este resumo não substitui caixa ou conciliação financeira.</p>
<form id="report-form" class="modal-fields"><label>De<input type="date" name="from" required></label><label>Até<input type="date" name="to" required></label><button class="button button-gold">Consultar</button></form>
<div id="report-results" aria-live="polite"></div><button type="button" id="report-csv" class="button button-outline" hidden>Exportar resumo CSV</button></section><?php endif; ?>
</main></div>
<script>window.MARTELLI_FEATURES=<?=json_encode(mt_feature_states())?>;window.MARTELLI_CSRF=<?=json_encode(csrf())?>;window.MARTELLI_PERMISSIONS=<?=json_encode(array_values(array_filter(MT_ACCESS_KEYS,'mt_can')))?>;window.MARTELLI_OWNER=<?=mt_is_owner()?'true':'false'?>;</script>
<?php page_end('<script src="/js/permissions-v10.js?v=20261101-features" defer></script><script src="/js/management.js?v=20261028-10" defer></script>'); ?>
