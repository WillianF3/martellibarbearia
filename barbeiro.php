<?php
require __DIR__.'/app/bootstrap.php';
if (!staff_logged() && !mt_can('minha_agenda')) { header('Location: /login.php'); exit; }
try { $identity=barber_identity(); } catch (Throwable $e) { $identity=null; }
if (!$identity) { if (mt_is_owner()) { header('Location: /admin.php'); exit; } unset($_SESSION['admin_id'],$_SESSION['admin_name'],$_SESSION['staff_user_id']); header('Location: /login.php'); exit; }
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');
require __DIR__.'/app/layout.php';
page_head('Minha agenda');
?>
<main class="restricted-shell">
 <header class="restricted-header">
  <a href="/" aria-label="Voltar ao site principal da Barbearia Martelli" title="Voltar ao site principal"><img src="/assets/logo-martelli-site.png" alt="Barbearia Martelli" width="108" height="80"></a>
  <div><span class="eyebrow">ÁREA DO BARBEIRO</span><h1>Minha agenda</h1><p>Olá, <?=h($identity['name'])?>. Consulte seus atendimentos por dia.</p></div>
  <button type="button" id="barber-logout" class="restricted-outline">Sair</button>
 </header>
 <section class="restricted-content" aria-label="Agenda do barbeiro por data">
   <div class="restricted-overview"><div><span class="eyebrow">ATENDIMENTOS</span><strong id="barber-count">—</strong><small>Seus clientes do dia</small></div><div class="barber-date-summary"><span class="eyebrow">DATA SELECIONADA</span>
    <div class="barber-date-nav" role="group" aria-label="Navegar pelas datas da agenda">
      <button type="button" id="barber-day-prev" class="barber-date-arrow" aria-label="Visualizar o dia anterior" title="Dia anterior" disabled>‹</button>
      <strong id="barber-today" aria-live="polite">—</strong>
      <button type="button" id="barber-day-next" class="barber-date-arrow" aria-label="Visualizar o próximo dia" title="Próximo dia" disabled>›</button>
    </div>
    <div class="barber-date-foot"><small id="barber-weekday">Horário de Brasília</small><button type="button" id="barber-day-reset" class="barber-date-reset" hidden>Hoje</button></div>
   </div><button id="barber-refresh" type="button" class="restricted-primary">Atualizar agenda ↻</button></div>
   <section class="barber-slots-section" aria-label="Horários do barbeiro na data selecionada">
     <div class="barber-slots-title"><div><h2 id="barber-slots-heading">Horários de hoje</h2><p id="barber-slots-help">Toque em um horário livre para cadastrar um cliente. Horários ocupados ou encerrados não podem ser escolhidos.</p></div>
       <div class="barber-slot-legend"><span class="barber-legend-free">Livre</span><span class="barber-legend-busy">Ocupado</span><span class="barber-legend-past">Encerrado</span></div>
     </div>
     <div id="barber-slot-grid" class="barber-slot-grid" aria-live="polite"><p>Carregando horários...</p></div>
   </section>
   <div id="barber-booking-panel" class="barber-booking-panel" aria-live="polite" hidden>
     <h2 id="barber-booking-heading">Cadastrar cliente</h2>
     <p class="restricted-help">Preencha os dados para confirmar um agendamento no seu nome, apenas hoje e em horário disponível.</p>
     <form id="barber-booking-form" class="restricted-form">
       <label>Nome do cliente<input name="name" autocomplete="name" maxlength="120" minlength="2" required></label>
       <label>CPF do cliente<input name="cpf" inputmode="numeric" maxlength="14" placeholder="000.000.000-00" required></label>
       <label>WhatsApp com DDD<input name="phone" type="tel" autocomplete="tel" placeholder="(49) 99999-9999" required></label>
       <label>Serviço<select name="service_id" required></select></label>
       <label>Observações (opcional)<input name="notes" maxlength="500"></label>
       <div class="barber-booking-buttons"><button type="submit" class="restricted-primary">Confirmar agendamento</button><button id="barber-booking-cancel" type="button" class="restricted-outline">Cancelar</button></div>
     </form>
   </div>
   <div id="barber-reschedule-panel" class="barber-booking-panel barber-reschedule-panel" hidden>
      <h2 id="barber-reschedule-heading">Remarcar atendimento</h2>
      <p class="restricted-help">Escolha uma nova data e um horário disponível para o seu cliente. O cliente deve ser avisado da alteração.</p>
      <form id="barber-reschedule-form" class="restricted-form">
        <label>Nova data<input type="date" name="date" required></label>
        <label>Novo horário<select name="time" required disabled><option value="">Escolha primeiro a data</option></select></label>
        <div id="barber-reschedule-feedback" class="restricted-help" role="status" aria-live="polite"></div>
        <div class="barber-booking-buttons"><button type="submit" class="restricted-primary" disabled>Confirmar remarcação</button><button type="button" id="barber-reschedule-cancel" class="restricted-outline">Cancelar</button></div>
      </form>
   </div>
   <div class="restricted-list-header"><h2>Clientes agendados</h2><p>Os agendamentos de outros barbeiros não são exibidos.</p></div>
   <div id="barber-alert" class="restricted-alert" role="status" hidden></div>
   <div id="barber-appointments" aria-live="polite"><p>Carregando seus atendimentos...</p></div>
 </section>
 <p class="restricted-footnote" id="barber-permission-hint">Suas permissões são definidas pelo administrador principal. Dados de outros profissionais nunca são exibidos.</p>
</main>
<script>window.MARTELLI_CSRF=<?=json_encode(csrf())?>;</script>
<?php page_end('<script src="/js/cpf-v42.js?v=42" defer></script><script src="/js/barber-day-v9.js?v=20261101-day-nav" defer></script>'); ?>
