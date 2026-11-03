<?php
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/layout.php';
require_once __DIR__.'/app/vip_auth.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
$token=strtolower(trim((string)($_GET['chave']??'')));
if($token===''){
  try {
    $identity=vip_auth_identity();
    if($identity){
      $blocked=vip_auth_login_block((int)$identity['client_id']);
      if($blocked){
        vip_auth_clear();
        $_SESSION['csrf']=bin2hex(random_bytes(24));
        header('Location: /vip-entrar.php?bloqueio='.rawurlencode((string)($blocked['code']??'cancelado')), true, 303);exit;
      }
    }
  } catch (Throwable $e) {error_log('Martelli VIP agenda: falha ao validar status da assinatura.');}
}
page_head('Minha agenda VIP | Barbearia Martelli','Área privada para agendar atendimentos incluídos na assinatura Martelli VIP.');
?>
<main class="vip-calendar" id="conteudo">
  <header class="vip-calendar-header">
   <a href="/" title="Voltar ao site principal" aria-label="Voltar à Barbearia Martelli"><img src="/assets/logo-martelli-site.png" alt="Barbearia Martelli" width="124" height="96"></a>
   <div><span class="vip-kicker">ACESSO EXCLUSIVO · MARTELLI VIP</span><h1>Minha agenda <em>VIP</em></h1><p>Escolha quando deseja realizar seu próximo atendimento, conforme a disponibilidade.</p></div>
   <div class="vip-calendar-links"><a class="vip-calendar-outline" href="/">Voltar ao site ↗</a><a class="vip-calendar-outline" id="vip-calendar-activate" href="/vip-entrar.php">Ativar meu login VIP</a><a class="vip-calendar-outline" href="/vip-senha.php">Alterar minha senha</a><a class="vip-calendar-outline" href="/vip-google.php?action=link<?=isset($_GET['chave']) && preg_match('/^[a-f0-9]{64}$/D',(string)$_GET['chave']) ? '&amp;chave='.h($_GET['chave']) : ''?>">Vincular minha conta Google</a><a class="vip-calendar-outline" href="/vip-apple.php?action=link<?=isset($_GET['chave']) && preg_match('/^[a-f0-9]{64}$/D',(string)$_GET['chave']) ? '&amp;chave='.h($_GET['chave']) : ''?>">Vincular minha conta Apple</a><button class="vip-calendar-outline" type="button" id="vip-calendar-logout" hidden>Sair</button></div>
  </header>
  <div id="vip-calendar-error" role="alert" hidden></div>
  <div id="vip-calendar-loading" role="status">Carregando sua assinatura…</div>
  <div id="vip-calendar-content" hidden>
   <section class="vip-calendar-plan" aria-label="Seu plano VIP">
     <div><span class="vip-kicker">ASSINATURA</span><h2 id="vip-calendar-plan">—</h2><p id="vip-calendar-client">—</p><small id="vip-calendar-validity">—</small></div>
     <div class="vip-calendar-stat"><strong id="vip-calendar-left">—</strong><span>Atendimentos disponíveis</span></div>
     <div class="vip-calendar-stat"><strong id="vip-calendar-reserved">—</strong><span>Já reservados</span></div>
   </section>
   <section class="vip-calendar-period-panel" aria-label="Data atual e período da assinatura">
    <div class="vip-calendar-period-dates"><strong id="vip-calendar-today">Hoje</strong><span id="vip-calendar-cycle">Vigência do seu plano</span></div>
    <p id="vip-calendar-guidance" class="vip-cycle-guidance" role="status" aria-live="polite"></p>
    <div class="vip-calendar-date-actions"><button type="button" class="vip-calendar-outline" id="vip-calendar-jump-start" hidden>Ver datas do meu plano →</button><button type="button" class="vip-calendar-outline" id="vip-calendar-show-today" hidden>Voltar ao início do período</button><a href="#vip-calendar-history" class="vip-calendar-outline" id="vip-calendar-has-quota" hidden>Ver meus atendimentos ↓</a></div>
   </section>
   <section class="vip-calendar-panel vip-calendar-booking-panel" aria-labelledby="vip-calendar-title">
    <div class="vip-calendar-heading"><div><span class="vip-kicker">AGENDAMENTO EXCLUSIVO</span><h2 id="vip-calendar-booking-title">Escolha seu próximo atendimento</h2><p>Você não precisa marcar todos os cortes de uma vez. Agende um por vez, quando preferir, durante a validade do plano.</p></div></div>
    <div class="vip-booking-unavailable" id="vip-booking-unavailable" hidden><strong id="vip-booking-unavailable-title">Não é possível reservar neste momento.</strong><p id="vip-booking-unavailable-text"></p><a href="#vip-calendar-history">Ver meus agendamentos e histórico ↓</a></div>
    <nav class="vip-booking-steps" aria-label="Etapas do agendamento">
     <span data-vip-step="barber" class="current"><b>1</b> Barbeiro</span>
     <span data-vip-step="date"><b>2</b> Dia</span>
     <span data-vip-step="time"><b>3</b> Horário</span>
     <span data-vip-step="confirm"><b>4</b> Confirmar</span>
    </nav>
    <div class="vip-booking-layout">
     <form id="vip-calendar-form" class="vip-booking-choices" novalidate>
      <section class="vip-booking-step" aria-labelledby="vip-choice-barber-title">
       <div class="vip-booking-step-title"><span class="vip-step-num">01</span><div><h3 id="vip-choice-barber-title">Escolha seu barbeiro</h3><p>Toque no profissional que deseja atender você.</p></div></div>
       <select id="vip-calendar-barber" name="barber_id" hidden><option value="">Selecione o barbeiro</option></select>
       <div id="vip-calendar-barber-cards" class="vip-barber-cards" role="group" aria-label="Profissionais disponíveis"></div>
      </section>
      <section class="vip-booking-step" aria-labelledby="vip-choice-date-title">
       <div class="vip-booking-step-title"><span class="vip-step-num">02</span><div><h3 id="vip-choice-date-title">Escolha o dia</h3><p>O calendário mostra somente os dias do seu plano VIP. Dias que já possuem um corte reservado ficam bloqueados.</p></div></div>
       <div class="vip-calendar-date-navigation">
        <button type="button" class="vip-small-nav" id="vip-calendar-prev-week" aria-label="Mostrar semana anterior">‹</button>
        <strong id="vip-calendar-week-label">Selecionar data</strong>
        <button type="button" class="vip-small-nav" id="vip-calendar-next-week" aria-label="Mostrar próxima semana">›</button>
       </div>
       <div class="vip-calendar-date-rail" id="vip-calendar-date-rail" role="group" aria-label="Dias disponíveis para consulta"></div>
       <label class="vip-calendar-manual-date">Ou escolha outra data <input id="vip-calendar-date" name="date" type="date" required></label>
      </section>
      <section class="vip-booking-step" aria-labelledby="vip-choice-time-title">
       <div class="vip-booking-step-title"><span class="vip-step-num">03</span><div><h3 id="vip-choice-time-title">Escolha o horário</h3><p>Mostramos apenas os horários livres na agenda.</p></div></div>
       <div id="vip-calendar-shift-filters" class="vip-calendar-shifts" role="group" aria-label="Filtrar horários por período" hidden>
        <button type="button" class="is-active" data-vip-shift="all" aria-pressed="true">Todos</button>
        <button type="button" data-vip-shift="morning" aria-pressed="false">Manhã</button>
        <button type="button" data-vip-shift="afternoon" aria-pressed="false">Tarde</button>
        <button type="button" data-vip-shift="evening" aria-pressed="false">Após 18h</button>
       </div>
       <p id="vip-calendar-slots-message" class="vip-calendar-hint" role="status" aria-live="polite">Escolha um barbeiro para consultar horários.</p>
       <fieldset class="vip-calendar-slots" id="vip-calendar-slots" aria-label="Horários livres" disabled><legend class="vip-sr-only">Horários disponíveis</legend><div id="vip-calendar-grid" class="vip-calendar-grid"></div></fieldset>
       <button class="vip-calendar-show-more" id="vip-calendar-show-more" type="button" hidden>Mostrar mais horários</button><button class="vip-next-day" id="vip-calendar-next-day" type="button" hidden>Consultar o próximo dia <span aria-hidden="true">→</span></button>
       <p class="vip-calendar-hint vip-calendar-information"><span aria-hidden="true">ⓘ</span> Após as 18h, o agendamento depende da aprovação do barbeiro. O atendimento VIP incluído não gera uma nova mensalidade.</p>
      </section>
     </form>
     <aside class="vip-booking-summary" aria-labelledby="vip-booking-summary-title">
      <span class="vip-kicker">04 · CONFIRMAÇÃO</span>
      <h3 id="vip-booking-summary-title">Sua reserva</h3>
      <p class="vip-summary-intro">Confira os dados antes de confirmar.</p>
      <dl>
       <div><dt>Plano</dt><dd id="vip-summary-plan">—</dd></div>
       <div><dt>Profissional</dt><dd id="vip-summary-barber">Escolha acima</dd></div>
       <div><dt>Data</dt><dd id="vip-summary-date">Escolha acima</dd></div>
       <div><dt>Horário</dt><dd id="vip-summary-time">Escolha acima</dd></div>
      </dl>
      <p id="vip-summary-approval" class="vip-summary-notice" hidden>Esse horário será enviado para aprovação do barbeiro.</p>
      <p id="vip-summary-remaining" class="vip-summary-remaining"></p><p id="vip-summary-block-note" class="vip-summary-block" hidden></p>
      <button type="submit" form="vip-calendar-form" class="vip-calendar-confirm" id="vip-calendar-confirm" disabled>Confirmar meu agendamento <span aria-hidden="true">→</span></button>
      <p class="vip-summary-security"><span aria-hidden="true">🔒</span> A reserva utiliza um atendimento do seu plano. Não há nova cobrança.</p>
      <div id="vip-calendar-feedback" role="status" aria-live="polite" hidden></div>
     </aside>
    </div>
   </section>
   <section class="vip-calendar-panel" aria-labelledby="vip-calendar-history"><span class="vip-kicker">SEUS ATENDIMENTOS</span><h2 id="vip-calendar-history">Meus agendamentos</h2><p>Você pode reservar um corte por vez. Para mudar a data ou o horário de uma reserva futura, clique em <strong>Remarcar</strong> — isso não consome outro atendimento do plano.</p><div id="vip-reschedule-feedback" role="status" aria-live="polite" hidden></div><div id="vip-calendar-bookings">—</div></section>
  </div>
  <dialog id="vip-calendar-review" class="vip-booking-dialog" aria-labelledby="vip-review-title">
    <div class="vip-booking-dialog-inner"><span class="vip-kicker">MARTELLI VIP</span><h2 id="vip-review-title">Confirmar seu atendimento?</h2><p id="vip-review-description"></p><p id="vip-review-note" class="vip-summary-notice" hidden></p><div class="vip-review-actions"><button type="button" id="vip-review-back" class="vip-calendar-outline">Voltar</button><button type="button" id="vip-review-confirm" class="vip-calendar-confirm">Sim, reservar</button></div></div>
  </dialog>
  <dialog id="vip-reschedule-dialog" class="vip-booking-dialog vip-reschedule-dialog" aria-labelledby="vip-reschedule-title">
    <form id="vip-reschedule-form" class="vip-booking-dialog-inner">
      <div class="vip-reschedule-top"><div><span class="vip-kicker">AJUSTAR RESERVA · MARTELLI VIP</span><h2 id="vip-reschedule-title">Remarcar atendimento</h2></div><button type="button" class="vip-reschedule-x" id="vip-reschedule-x" aria-label="Fechar remarcação">×</button></div>
      <p id="vip-reschedule-old" class="vip-reschedule-old"></p>
      <p class="vip-reschedule-intro">Escolha outra data, horário ou profissional. Você continua usando o <strong>mesmo corte</strong>, sem descontar outro do plano.</p>
      <p id="vip-reschedule-validity" class="vip-reschedule-validity" role="status"></p>
      <label class="vip-reschedule-label">1. Profissional <select id="vip-reschedule-barber" required></select></label>
      <div class="vip-reschedule-date-header"><strong>2. Escolha o dia</strong><span>Somente datas dentro do seu plano</span></div>
      <div class="vip-reschedule-date-controls"><button type="button" id="vip-reschedule-prev-week" aria-label="Semana anterior">‹</button><strong id="vip-reschedule-week-label">—</strong><button type="button" id="vip-reschedule-next-week" aria-label="Próxima semana">›</button></div>
      <div id="vip-reschedule-day-rail" class="vip-reschedule-day-rail" role="group" aria-label="Dias para remarcar"></div>
      <label class="vip-reschedule-manual">Ou selecione outra data <input type="date" id="vip-reschedule-date" required></label>
      <div class="vip-reschedule-slots-heading"><strong>3. Escolha o horário</strong><span id="vip-reschedule-slot-count" aria-live="polite"></span></div>
      <div class="vip-reschedule-filter" id="vip-reschedule-filters" role="group" aria-label="Filtrar horários">
        <button type="button" data-reschedule-shift="all" class="active" aria-pressed="true">Todos</button>
        <button type="button" data-reschedule-shift="morning" aria-pressed="false">Manhã</button>
        <button type="button" data-reschedule-shift="afternoon" aria-pressed="false">Tarde</button>
        <button type="button" data-reschedule-shift="evening" aria-pressed="false">Após 18h</button>
      </div>
      <p id="vip-reschedule-hint" role="status" aria-live="polite">Selecione a data e o profissional.</p>
      <div id="vip-reschedule-slots" class="vip-reschedule-slots" role="group" aria-label="Novos horários disponíveis"></div>
      <button type="button" id="vip-reschedule-more" class="vip-reschedule-more" hidden>Mostrar todos os horários</button>
      <div class="vip-reschedule-footer"><p id="vip-reschedule-selected" class="vip-reschedule-selected" aria-live="polite">Nenhum novo horário selecionado.</p>
      <div class="vip-review-actions"><button type="button" class="vip-calendar-outline" id="vip-reschedule-close">Cancelar</button><button type="submit" class="vip-calendar-confirm" id="vip-reschedule-save" disabled>Confirmar remarcação →</button></div></div>
    </form>
  </dialog>
  <footer class="vip-calendar-footer">Seu acesso é pessoal. Não compartilhe seus links ou senha. Em caso de dúvidas, fale com a barbearia.</footer>
</main>
<script>window.MARTELLI_VIP_CSRF=<?=json_encode(csrf())?>;</script>
<?php page_end('<script src="/js/vip-calendar-v26.js?v=55" defer></script><script src="/js/vip-calendar-login-v24.js?v=24" defer></script>'); ?>
