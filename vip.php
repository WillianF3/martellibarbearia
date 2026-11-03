<?php
require __DIR__.'/app/bootstrap.php';
if(!mt_can_any(['cadastros','financeiro'])){header('Location: '.mt_start_page());exit;}
require __DIR__.'/app/layout.php';
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
page_head('Martelli VIP | Assinaturas');
?>
<main class="vip-admin" id="conteudo">
  <header class="vip-admin-header">
    <a class="vip-admin-brand" href="/" aria-label="Voltar ao site Martelli"><img src="/assets/logo-martelli-site.png" alt="Barbearia Martelli" width="110" height="80"></a>
    <div class="vip-admin-intro"><span class="eyebrow">PAINEL MARTELLI / GESTÃO</span><h1>Clientes <em>VIP.</em></h1><p>Gerencie assinaturas mensais e acompanhe a utilização de cada cliente.</p></div>
    <nav aria-label="Acesso rápido" class="vip-admin-links"><a href="/painel.php" class="vip-back-link">← Voltar ao painel</a><?php if(mt_is_owner()):?><a href="/oauth-diagnostico.php">Verificar login Google / Apple</a><?php endif;?><?php if(mt_is_owner()):?><a href="/clientes-conciliar.php">Conferir CPF / WhatsApp</a><?php endif;?><a href="#vip-plans-title">Gerenciar planos VIP</a><a href="/admin.php#clientes">Clientes</a><a href="/painel.php#comandas">Comandas</a><a href="/" >Ver site ↗</a></nav>
  </header>
  <div id="vip-alert" role="status" aria-live="polite" hidden></div>
  <section class="vip-summary" aria-label="Indicadores VIP">
    <article><span>Assinaturas vigentes</span><strong id="vip-count-active">—</strong><small>Dentro do prazo e ativas</small></article>
    <article><span>Cortes registrados</span><strong id="vip-count-used">—</strong><small>Nas assinaturas listadas</small></article>
    <article><span>Assinaturas próximas do vencimento</span><strong id="vip-count-expiring">—</strong><small>Próximos 7 dias</small></article>
  </section>
  <section class="vip-admin-panel" aria-labelledby="vip-plans-title">
   <div class="vip-admin-toolbar"><div><span class="eyebrow">ASSINATURAS · CICLO DE 30 DIAS</span><h2 id="vip-plans-title">Planos VIP</h2><p>Inclua, edite ou oculte planos com nome, mensalidade e quantidade de atendimentos. Os ativos aparecem na página de cadastro VIP.</p></div><?php if(mt_is_owner()):?><button type="button" class="vip-button vip-outline" id="vip-new-plan">+ Criar plano</button><?php endif;?></div>
   <div id="vip-plan-cards" class="vip-plan-grid"><p>Carregando planos…</p></div>
  </section>
  <section class="vip-admin-panel" aria-labelledby="vip-requests-title" id="solicitacoes-vip">
   <div class="vip-admin-toolbar"><div><span class="eyebrow">CADASTROS RECEBIDOS PELO SITE</span><h2 id="vip-requests-title">Solicitações de adesão VIP <span id="vip-requests-count" class="vip-requests-count">—</span></h2><p>Analise os dados e converse com o cliente antes de ativar a assinatura. Nenhuma cobrança é feita automaticamente.</p></div><a class="vip-button vip-outline" href="/vip-cadastro.php" target="_blank" rel="noopener">Ver página de cadastro ↗</a></div>
   <div id="vip-requests-message" class="vip-requests-message" hidden></div>
   <div class="vip-table-scroll"><table class="vip-table"><thead><tr><th>Cliente</th><th>Plano escolhido</th><th>Solicitado em</th><th>Status</th><th>Ações</th></tr></thead><tbody id="vip-request-rows"><tr><td colspan="5">Carregando solicitações…</td></tr></tbody></table></div>
  </section>
  <section class="vip-admin-panel" aria-labelledby="vip-members-title">
   <div class="vip-admin-toolbar"><div><span class="eyebrow">GESTÃO DE ASSINATURAS</span><h2 id="vip-members-title">Clientes assinantes</h2><p>Cadastre um novo cliente ou selecione um existente. Crie assinaturas tradicionais ou personalizadas de 30 dias, sem gerar cobranças automáticas.</p></div><div class="vip-admin-toolbar-actions"><?php if(mt_is_owner()):?><button type="button" class="vip-button vip-outline" id="vip-duplicates-review">Verificar duplicatas</button><?php endif;?><button type="button" class="vip-button vip-primary" id="vip-new-member">+ Novo cliente VIP</button><?php if(mt_is_owner()):?><button type="button" class="vip-button vip-outline" id="vip-new-custom">+ Assinatura personalizada</button><?php endif;?></div></div>
   <div class="vip-admin-filters"><label for="vip-search">Buscar cliente<input type="search" id="vip-search" placeholder="Nome ou telefone" autocomplete="off"></label><label for="vip-filter">Situação<select id="vip-filter"><option value="all">Todas</option><option value="active">Ativas</option><option value="expired">Vencidas</option><option value="paused">Pausadas</option><option value="cancelled">Canceladas</option><option value="scheduled">Programadas</option></select></label></div>
   <div class="vip-table-scroll"><table class="vip-table"><thead><tr><th>Cliente</th><th>Plano</th><th>Vigência</th><th>Cortes usados</th><th>Situação</th><th>Ações</th></tr></thead><tbody id="vip-member-rows"><tr><td colspan="6">Carregando clientes VIP…</td></tr></tbody></table></div>
  </section>
  <p class="vip-admin-disclaimer"><strong>Importante:</strong> os registros VIP são manuais. A criação de uma assinatura não cobra o cliente, não gera pagamento recorrente e não quita comandas automaticamente. Registre recebimentos no módulo financeiro e cada corte efetivamente realizado na assinatura.</p>
</main>
<dialog id="vip-dialog" class="vip-dialog" aria-labelledby="vip-dialog-title"><form id="vip-form"><div class="vip-dialog-header"><h2 id="vip-dialog-title">Assinatura VIP</h2><button type="button" class="vip-icon-close" data-vip-close aria-label="Fechar">×</button></div><div id="vip-fields" class="vip-dialog-fields"></div><p id="vip-dialog-error" class="vip-dialog-error" role="alert" hidden></p><div class="vip-dialog-actions"><button class="vip-button vip-outline" type="button" data-vip-close>Cancelar</button><button class="vip-button vip-primary" type="submit">Salvar</button></div></form></dialog>
<dialog id="vip-link-dialog" class="vip-dialog" aria-labelledby="vip-link-title"><div class="vip-dialog-header"><h2 id="vip-link-title">Link pessoal do cliente VIP</h2><button type="button" class="vip-icon-close" data-vip-link-close aria-label="Fechar">×</button></div><p class="vip-note">O link dá acesso à agenda deste cliente. Compartilhe somente com ele. Gerar outro link desativa o anterior.</p><label class="vip-link-label">Link de agendamento<input type="text" id="vip-link-url" readonly aria-label="Link VIP gerado"></label><div class="vip-dialog-actions"><button type="button" id="vip-link-copy" class="vip-button vip-outline">Copiar link</button><a id="vip-whatsapp-link" class="vip-button vip-primary" href="#" target="_blank" rel="noopener noreferrer">Enviar pelo WhatsApp ↗</a><button type="button" class="vip-button vip-outline" data-vip-link-close>Fechar</button></div><p class="vip-note">O WhatsApp será aberto com uma mensagem pronta; confira e toque em enviar.</p></dialog>
<script>window.MARTELLI_CSRF=<?=json_encode(csrf())?>;window.MARTELLI_VIP_OWNER=<?=mt_is_owner()?'true':'false'?>;</script>
<?php page_end('<script src="/js/cpf-v42.js?v=42" defer></script><script src="/js/vip-admin.js?v=42" defer></script>'); ?>
