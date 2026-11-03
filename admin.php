<?php require __DIR__.'/app/bootstrap.php'; if (!mt_can_any(['agenda','cadastros','horarios','configuracoes'])) {header('Location: '.mt_start_page());exit;} require __DIR__.'/app/layout.php'; header('X-Robots-Tag: noindex, nofollow'); header('Cache-Control: no-store'); page_head('Painel administrativo'); ?>
<div class="admin-shell"><aside id="admin-nav-drawer" class="admin-sidebar cabra-unified-sidebar" aria-label="Navegação administrativa">
<button type="button" class="admin-drawer-close" data-admin-drawer-close aria-label="Fechar menu administrativo">×</button>
<a class="cabra-sidebar-logo" href="/" aria-label="Voltar à página inicial da Barbearia Martelli"><img src="/assets/logo-martelli-site.png" width="174" alt="Logo Barbearia Martelli"><span>PAINEL ADMINISTRATIVO</span></a>
<nav aria-label="Menu administrativo" id="admin-nav" class="cabra-nav">
 <div class="cabra-nav-group"><p class="cabra-nav-label">01 · Agendamentos</p>
  <button type="button" class="active" data-tab="agenda">Agenda geral</button>
  <button type="button" data-tab="dia">Agendamentos do dia</button>
  <button type="button" data-tab="todos">Todos os agendamentos</button>
  <a href="/gestao.php#lista-espera">Lista de espera e bloqueios</a>
 </div>
 <div class="cabra-nav-group"><p class="cabra-nav-label">02 · Cadastros e equipe</p>
  <button type="button" data-tab="clientes">Clientes</button>
  <button type="button" data-tab="servicos">Serviços</button>
  <button type="button" data-tab="barbeiros">Barbeiros</button>
  <button type="button" data-tab="horarios">Horários</button>
 </div>
 <div class="cabra-nav-group"><p class="cabra-nav-label">03 · Financeiro e estoque</p>
  <a href="/painel.php#comandas">Comandas</a>
  <a href="/painel.php#financeiro">Visão financeira</a>
  <a href="/painel.php#caixa">Caixa e histórico</a>
  <a href="/painel.php#estoque">Produtos e estoque</a>
  <a href="/painel.php#comissoes">Comissões</a><a href="/vip.php">Clientes VIP · Assinaturas</a>
 </div>
 <div class="cabra-nav-group"><p class="cabra-nav-label">04 · Análises e sistema</p>
  <a href="/painel.php#dashboard">Dashboard</a>
  <a href="/painel.php#relatorios">Relatórios</a>
  <button type="button" data-tab="configuracoes">Configurações da agenda</button>
  <button type="button" data-tab="administradores">Administradores e permissões</button><a href="/acessos.php">Acessos dos barbeiros</a>
  <a href="/painel.php#configuracoes">Configurações de gestão</a><?php if(mt_is_owner()): ?><a href="/funcoes.php">Ativar / desativar funções</a><?php endif; ?>
 </div>
</nav>
<div class="cabra-sidebar-bottom"><a class="cabra-site-link" href="/">← Ver site</a><button id="logout" class="button button-outline button-full">Sair com segurança</button></div>
</aside><button type="button" class="admin-drawer-backdrop" id="admin-drawer-backdrop" tabindex="-1" aria-label="Fechar menu administrativo" aria-hidden="true"></button><main class="admin-content"><header class="admin-heading"><div><span class="eyebrow">Painel Barbearia Martelli</span><h1 id="tab-heading">Agenda</h1></div><div class="admin-actions"><button type="button" class="admin-menu-trigger" data-admin-drawer-trigger aria-controls="admin-nav-drawer" aria-expanded="false" aria-label="Abrir menu administrativo"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg><span>Menu</span></button><a class="button button-outline" href="/painel.php#dashboard">Gestão completa</a><label class="admin-mobile-jump" for="admin-mobile-nav">Ir para <select id="admin-mobile-nav" aria-label="Seção do painel">
<optgroup label="Agendamentos"><option value="agenda">Agenda geral</option><option value="dia">Agendamentos do dia</option><option value="todos">Todos os agendamentos</option><option value="url:/gestao.php#lista-espera">Lista de espera</option></optgroup>
<optgroup label="Cadastros"><option value="clientes">Clientes</option><option value="servicos">Serviços</option><option value="barbeiros">Barbeiros</option><option value="horarios">Horários</option></optgroup>
<optgroup label="Financeiro"><option value="url:/painel.php#comandas">Comandas</option><option value="url:/painel.php#financeiro">Financeiro</option><option value="url:/painel.php#caixa">Caixa</option><option value="url:/painel.php#estoque">Estoque</option><option value="url:/vip.php">Clientes VIP</option></optgroup>
<optgroup label="Relatórios e sistema"><option value="url:/painel.php#dashboard">Dashboard</option><option value="url:/painel.php#relatorios">Relatórios</option><option value="configuracoes">Configurações da agenda</option><option value="administradores">Administradores</option><option value="url:/acessos.php">Acessos dos barbeiros</option><option value="url:/painel.php#configuracoes">Configurações de gestão</option><?php if(mt_is_owner()): ?><option value="url:/funcoes.php">Ativar / desativar funções</option><?php endif; ?></optgroup>
</select></label><span>Olá, <?=h($_SESSION['admin_name']??'Administrador')?></span><button type="button" class="button button-gold" id="new-booking">+ Novo agendamento</button></div></header><div id="admin-message" class="notice" hidden role="status"></div><div id="admin-view"><p>Carregando informações...</p></div></main></div>
<dialog id="edit-dialog" class="modal"><form id="edit-form" method="dialog"><h2 id="dialog-title">Editar</h2><div id="dialog-fields" class="modal-fields"></div><div class="modal-actions"><button class="button button-outline" type="button" id="dialog-cancel">Cancelar</button><button class="button button-gold" type="submit">Salvar</button></div></form></dialog>
<script>window.MARTELLI_FEATURES=<?=json_encode(mt_feature_states())?>;window.MARTELLI_CSRF=<?=json_encode(csrf())?>;window.MARTELLI_PERMISSIONS=<?=json_encode(array_values(array_filter(MT_ACCESS_KEYS,'mt_can')))?>;window.MARTELLI_OWNER=<?=mt_is_owner()?'true':'false'?>;</script>
<?php page_end('<script src="/js/phone.js?v=20261026-1" defer></script><script src="/js/permissions-v10.js?v=20261101-features" defer></script><script src="/js/cpf-v42.js?v=42" defer></script><script src="/js/admin.js?v=20261102-comissoes" defer></script>'); ?>
