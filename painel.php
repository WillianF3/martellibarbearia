<?php
require __DIR__.'/app/bootstrap.php';
if(!mt_can_any(['financeiro','estoque','relatorios'])){header('Location: '.mt_start_page());exit;}
header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');
require __DIR__.'/app/layout.php';page_head('Gestão Barbearia Martelli');
?>
<div class="biz-shell">
<aside id="admin-nav-drawer" class="biz-sidebar cabra-unified-sidebar" aria-label="Navegação da gestão">
 <button type="button" class="admin-drawer-close" data-admin-drawer-close aria-label="Fechar menu administrativo">×</button>
 <a class="cabra-sidebar-logo" href="/" aria-label="Voltar à página inicial da Barbearia Martelli"><img src="/assets/logo-martelli-site.png" alt="Barbearia Martelli" width="174"><span>GESTÃO DO NEGÓCIO</span></a>
 <nav aria-label="Menu administrativo" class="cabra-nav">
  <div class="cabra-nav-group"><p class="cabra-nav-label">01 · Agendamentos</p>
    <a href="/admin.php#agenda">Agenda geral</a><a href="/admin.php#dia">Agendamentos do dia</a><a href="/admin.php#todos">Todos os agendamentos</a><a href="/gestao.php#lista-espera">Lista de espera e bloqueios</a>
  </div>
  <div class="cabra-nav-group"><p class="cabra-nav-label">02 · Clientes e equipe</p>
    <a href="/admin.php#clientes">Clientes</a><a href="/vip.php">Clientes VIP · Assinaturas</a><a href="/admin.php#servicos">Serviços</a><a href="/admin.php#barbeiros">Barbeiros</a><a href="/admin.php#horarios">Horários</a>
  </div>
  <div class="cabra-nav-group"><p class="cabra-nav-label">03 · Financeiro e estoque</p>
    <a href="/painel.php#comandas">Comandas</a><a href="/painel.php#contas">Contas de clientes</a><a href="/painel.php#financeiro">Visão financeira</a><a href="/painel.php#caixa">Caixa e histórico</a><a href="/painel.php#movimentos">Entradas e saídas</a><a href="/painel.php#comissoes">Comissões</a><a href="/painel.php#estoque">Produtos e estoque</a>
  </div>
  <div class="cabra-nav-group"><p class="cabra-nav-label">04 · Gestão e sistema</p>
    <a href="/painel.php#dashboard">Dashboard</a><a href="/painel.php#relatorios">Relatórios</a><a href="/admin.php#configuracoes">Configurações da agenda</a><a href="/admin.php#administradores">Administradores</a><a href="/acessos.php">Acessos dos barbeiros</a><a href="/painel.php#configuracoes">Configurações de gestão</a><?php if(mt_is_owner()): ?><a href="/funcoes.php">Ativar / desativar funções</a><?php endif; ?>
  </div>
</nav><div class="cabra-sidebar-bottom"><a class="cabra-site-link" href="/">← Ver site</a></div>
</aside><button type="button" class="admin-drawer-backdrop" id="admin-drawer-backdrop" tabindex="-1" aria-label="Fechar menu administrativo" aria-hidden="true"></button>
<main class="biz-main"><header class="biz-heading"><div><span class="biz-eyebrow">PAINEL MARTELLI / GESTÃO</span><h1 id="biz-title">Dashboard</h1></div><div class="biz-heading-actions"><button type="button" class="admin-menu-trigger" data-admin-drawer-trigger aria-controls="admin-nav-drawer" aria-expanded="false" aria-label="Abrir menu administrativo"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg><span>Menu</span></button><a href="/admin.php#agenda">Ir para agenda</a><span>Olá, <?=h($_SESSION['admin_name']??'Administrador')?></span></div></header>
<div class="biz-mobile"><label for="biz-mobile-nav">Área do painel</label><select id="biz-mobile-nav" aria-label="Navegar na gestão">
<optgroup label="Atendimentos"><option value="url:/admin.php#agenda">Agenda geral</option><option value="url:/admin.php#clientes">Clientes e cadastros</option><option value="url:/vip.php">Clientes VIP</option><option value="url:/gestao.php#lista-espera">Lista de espera</option></optgroup>
<optgroup label="Comandas"><option value="comandas">Comandas</option><option value="contas">Contas de clientes</option></optgroup>
<optgroup label="Financeiro"><option value="financeiro">Resumo financeiro</option><option value="caixa">Caixa e histórico</option><option value="movimentos">Entradas e saídas</option><option value="comissoes">Comissões</option></optgroup>
<optgroup label="Resultados e sistema"><option value="estoque">Produtos e estoque</option><option value="dashboard">Dashboard</option><option value="relatorios">Relatórios</option><option value="configuracoes">Configurações</option><?php if(mt_is_owner()): ?><option value="url:/funcoes.php">Ativar / desativar funções</option><?php endif; ?><option value="url:/acessos.php">Acesso dos barbeiros</option></optgroup>
</select></div>
<div id="biz-message" role="status" aria-live="polite" hidden></div>
<form id="biz-period" class="biz-filter"><div class="biz-filter-intro"><strong>Filtrar por período</strong><span>Selecione as datas para consultar os registros.</span></div><label>De<input type="date" name="from" required></label><label>Até<input type="date" name="to" required></label><button type="submit">Aplicar período</button><button type="button" id="biz-today">Hoje</button></form>
<div id="biz-view" aria-live="polite"><p>Carregando módulos...</p></div>
</main></div>
<dialog id="biz-dialog"><form id="biz-form"><header><h2 id="biz-dialog-title"></h2><button type="button" data-dialog-close aria-label="Fechar">×</button></header><div id="biz-fields" class="biz-form-grid"></div><p id="biz-form-error" role="alert" hidden></p><footer><button type="button" data-dialog-close>Cancelar</button><button type="submit" class="biz-primary">Salvar</button></footer></form></dialog>
<script>window.MARTELLI_FEATURES=<?=json_encode(mt_feature_states())?>;window.MARTELLI_PERMISSIONS=<?=json_encode(array_values(array_filter(MT_ACCESS_KEYS,'mt_can')))?>;window.MARTELLI_OWNER=<?=mt_is_owner()?'true':'false'?>;window.MARTELLI_CSRF=<?=json_encode(csrf())?>;</script>
<?php page_end('<script src="/js/permissions-v10.js?v=20261101-features" defer></script><script src="/js/business.js?v=20261101-features" defer></script>'); ?>
