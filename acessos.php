<?php
require __DIR__.'/app/bootstrap.php';
if (!mt_is_owner()) { header('Location: '.mt_start_page()); exit; }
header('X-Robots-Tag: noindex, nofollow');header('Cache-Control: private, no-store');
require __DIR__.'/app/layout.php';page_head('Acesso dos barbeiros');
?>
<main class="restricted-shell">
 <header class="restricted-header"><a href="/" aria-label="Voltar à página inicial da Barbearia Martelli"><img src="/assets/logo-martelli-site.png" alt="Barbearia Martelli" width="108" height="80"></a>
 <div><span class="eyebrow">GESTÃO DE PERMISSÕES</span><h1>Acesso dos barbeiros</h1><p>Controle quais profissionais podem consultar e cadastrar clientes nos próprios horários de hoje.</p></div>
 <a href="/admin.php#barbeiros" class="restricted-outline">← Voltar ao painel</a></header>
 <div class="restricted-admin-grid">
  <section class="restricted-content"><h2>Contas da equipe</h2><p class="restricted-help">Cada conta é vinculada a um único barbeiro. Desative o acesso sem excluir seus agendamentos.</p><div id="staff-list">Carregando...</div></section>
  <section class="restricted-content"><h2 id="staff-form-title">Criar / editar acesso</h2><form id="staff-form" class="restricted-form" autocomplete="off">
   <label>Barbeiro<select name="barber_id" id="staff-barber" required><option value="">Selecione...</option></select></label>
   <label>E-mail de acesso<input name="email" type="email" maxlength="190" required autocomplete="off"></label>
   <label class="staff-booking-permission"><input type="checkbox" name="can_book" id="staff-can-book" checked> <span>Permitir cadastrar clientes nos horários livres da própria agenda de hoje</span></label>
   <label>Nova senha <small>(mínimo 12 caracteres; deixe em branco ao editar para manter)</small><input name="password" type="password" minlength="12" maxlength="256" autocomplete="new-password"></label>
   <label>Sua senha de administrador <small>(para confirmar esta alteração)</small><input name="admin_password" type="password" minlength="12" required autocomplete="current-password"></label>
   <div id="staff-message" class="restricted-alert" role="status" hidden></div>
   <button class="restricted-primary" type="submit">Salvar acesso</button><button type="button" id="staff-clear" class="restricted-outline">Novo cadastro</button>
  </form><p class="restricted-help">Após criar a conta, envie ao profissional o endereço <strong>/login.php</strong> e informe suas credenciais por um canal seguro. Ele terá acesso somente a <strong>/barbeiro.php</strong> e às funções autorizadas acima.</p></section>
 </div>
</main>
<script>window.MARTELLI_CSRF=<?=json_encode(csrf())?>;</script>
<?php page_end('<script src="/js/staff-access-v9.js?v=12" defer></script>'); ?>
