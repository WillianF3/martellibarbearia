'use strict';
// Martelli V7.7: agenda do barbeiro, cadastro, aprovação e remarcação dos próprios atendimentos.
// Restrições reais de acesso e disponibilidade são verificadas também na API.
(async () => {
  const {api, escape: esc, money, note} = window.Martelli;
  const view = document.getElementById('admin-view');
  const message = document.getElementById('admin-message');
  const dialog = document.getElementById('edit-dialog');
  const form = document.getElementById('edit-form');
  const dialogFields = document.getElementById('dialog-fields');
  const dialogTitle = document.getElementById('dialog-title');
  const status = {
    pending:'Aguardando aprovação', confirmed:'Confirmado',
    completed:'Concluído', cancelled:'Cancelado', no_show:'Não compareceu'
  };
  const slotStatus = {
    available:'Livre · Cadastrar', occupied:'Ocupado',
    past:'Encerrado', unavailable:'Indisponível'
  };
  const afterSix = a => String(a.starts_at || '').slice(11,16) >= '18:00';
  function whatsappConfirmation(a) {
    if (a.status !== 'confirmed' || !afterSix(a)) return '';
    const phone = window.MartelliPhone?.forWhatsApp(a.client_phone);
    if (!phone) return '';
    const date = String(a.starts_at || '').slice(0,10).split('-').reverse().join('/');
    const time = String(a.starts_at || '').slice(11,16);
    const messageText = [
      `Olá, ${a.client_name}! Aqui é da Barbearia Martelli.`,
      '', 'Seu atendimento está confirmado!',
      `Data: ${date}`, `Horário: ${time}`,
      `Serviço: ${a.service_name}`, `Barbeiro: ${a.barber_name}`,
      '', 'Aguardamos você!'
    ].join('\n');
    const href = `https://wa.me/${phone}?text=${encodeURIComponent(messageText)}`;
    return `<a class="staff-confirm-whatsapp" href="${esc(href)}" target="_blank" rel="noopener noreferrer" title="Abrir WhatsApp para enviar confirmação">WhatsApp: avisar cliente ↗</a>`;
  }
  const today = () => {
    const parts = new Intl.DateTimeFormat('en-US', {
      timeZone:'America/Sao_Paulo', year:'numeric', month:'2-digit', day:'2-digit'
    }).formatToParts(new Date());
    const obj = Object.fromEntries(parts.map(part => [part.type,part.value]));
    return `${obj.year}-${obj.month}-${obj.day}`;
  };
  let busy = false;
  let saving = false;
  let agenda = null;
  let chosenTime = '';
  let editorMode = 'booking';
  let rescheduleId = 0;
  let rescheduleTime = '';
  let rescheduleSeq = 0;

  async function loadDay() {
    if (busy) return;
    busy = true;
    view.innerHTML = '<section class="admin-section"><p class="text-muted">Consultando seus horários e agendamentos...</p></section>';
    try {
      const date = today();
      const result = await api('admin_day', null, {date});
      agenda = result;
      if (result.warning) {
        view.innerHTML = `<section class="admin-section"><p class="error-banner">${esc(result.warning)}</p></section>`;
        return;
      }
      const items = Array.isArray(result.appointments) ? result.appointments : [];
      const slots = Array.isArray(result.slots) ? result.slots : [];
      const free = slots.filter(s => s.state === 'available').length;
      const formatted = new Intl.DateTimeFormat('pt-BR', {
        timeZone:'UTC',weekday:'long',day:'numeric',month:'long',year:'numeric'
      }).format(new Date(`${date}T12:00:00Z`));

      view.innerHTML = `<section class="admin-section day-panel">
        <div class="day-panel-head"><div>
          <span class="eyebrow">Acesso do profissional</span>
          <h2>Meus agendamentos de hoje</h2>
          <p class="text-muted">${esc(formatted)} · Agenda exclusiva do seu barbeiro. Nenhum atendimento de outros profissionais é exibido.</p>
        </div><button type="button" id="staff-refresh" class="button button-outline button-small">Atualizar ↻</button></div>
        <div class="day-totals staff-day-totals">
          <div><strong>${items.filter(a=>a.status!=='cancelled').length}</strong><span>Atendimentos do dia</span></div>
          <div><strong>${free}</strong><span>Horários livres</span></div>
          <div><strong>${items.filter(a=>a.status==='pending').length}</strong><span>Aguardando aprovação</span></div>
          <div><strong>${items.filter(a=>a.status==='confirmed').length}</strong><span>Confirmados</span></div>
          <div><strong>${items.filter(a=>a.status==='completed').length}</strong><span>Concluídos</span></div>
        </div>
        <div class="staff-calendar">
          <div class="staff-calendar-heading"><div><h3>Horários de hoje</h3>
            <p class="text-muted">Toque em um horário livre para adicionar um cliente. O serviço escolhido deve caber no período disponível.</p></div>
            <div class="staff-legend"><span class="staff-legend-free">Livre</span><span class="staff-legend-busy">Ocupado</span><span class="staff-legend-past">Encerrado</span></div>
          </div>
          ${slots.length ? `<div class="staff-slots-grid">${slots.map(slot => {
            const available = slot.state === 'available';
            const state = Object.hasOwn(slotStatus,slot.state) ? slot.state : 'unavailable';
            return `<button type="button" class="staff-slot staff-slot-${state}"
              ${available ? `data-staff-slot="${esc(slot.time)}"` : 'disabled'}
              aria-label="${esc(slot.time)} — ${esc(slotStatus[state])}">
              <strong>${esc(slot.time)}</strong><small>${esc(slotStatus[state])}</small>
            </button>`;
          }).join('')}</div>` : '<p class="day-empty">Não há horários de atendimento disponíveis para hoje.</p>'}
        </div>
        <div class="staff-appointment-section">
          <h3 class="day-list-title">Clientes agendados (${items.filter(a=>a.status!=='cancelled').length})</h3>
          ${items.filter(a=>a.status!=='cancelled').length ? `<div class="day-appointments">${items.filter(a=>a.status!=='cancelled').map(a => `<article class="day-entry staff-day-entry">
            <div class="day-time"><strong>${esc(String(a.starts_at).slice(11,16))}</strong><small>até ${esc(String(a.ends_at).slice(11,16))}</small></div>
            <div class="day-client"><h4>${esc(a.client_name)}</h4><p>${esc(a.client_phone)}</p>
              <span>${esc(a.service_name)} · ${esc(a.barber_name)}</span>
              ${a.notes ? `<span class="day-notes">Obs.: ${esc(a.notes)}</span>` : ''}
            </div>
            <div class="day-state"><span class="status status-${esc(a.status)}">${esc(status[a.status]||a.status)}</span><strong>${money(a.price)}</strong>
              ${a.status==='pending' && afterSix(a) ? `<button type="button" class="staff-approve-button" data-staff-approve="${Number(a.id)}" aria-label="Aprovar atendimento de ${esc(a.client_name)} às ${esc(String(a.starts_at).slice(11,16))}">✓ Aprovar atendimento</button>` : ''}
              ${a.status==='confirmed'?`<button type="button" class="staff-reschedule-button" data-staff-reschedule="${Number(a.id)}" aria-label="Remarcar ${esc(a.client_name)}">↻ Remarcar</button>`:''}
              ${whatsappConfirmation(a)}
            </div>
          </article>`).join('')}</div>` : '<p class="day-empty">Nenhum cliente agendado hoje. Selecione um horário livre para cadastrar o primeiro.</p>'}
        </div>
        <p class="text-muted staff-security-note">Você pode cadastrar clientes e remarcar os seus atendimentos confirmados de hoje. Preços, horários de funcionamento e dados de outros barbeiros continuam restritos ao administrador principal.</p>
      </section>`;
    } catch(e) {
      agenda = null;
      view.innerHTML = `<section class="admin-section"><p class="error-banner">${esc(e.message)}</p></section>`;
    } finally { busy = false; }
  }

  function showBooking(time) {
    if (!agenda || saving || !dialog || !form) return;
    const slot = (agenda.slots || []).find(s => s.time === time && s.state === 'available');
    if (!slot) return;
    const allowed = new Set((slot.service_ids || []).map(Number));
    const services = (agenda.services || []).filter(s => allowed.has(Number(s.id)));
    if (!services.length) {note(message,'Não há serviços que caibam neste horário. Atualize a agenda.',true);return;}
    editorMode = 'booking';
    chosenTime = time;
    dialogTitle.textContent = `Novo agendamento · ${time}`;
    dialogFields.innerHTML = `
      <p class="staff-modal-hint">Cadastro para <strong>hoje às ${esc(time)}</strong>, somente na sua agenda. O valor será calculado pelo serviço cadastrado.</p>
      <label class="staff-form-field">Nome do cliente *
        <input name="client_name" type="text" autocomplete="name" maxlength="120" minlength="2" required placeholder="Nome completo">
      </label>
      <label class="staff-form-field">WhatsApp com DDD *
        <input name="client_phone" type="tel" autocomplete="tel" required placeholder="(49) 99999-9999">
      </label>
      <label class="staff-form-field staff-field-full">Serviço *
        <select name="service_id" required><option value="">Selecione um serviço</option>${services.map(s=>`<option value="${Number(s.id)}">${esc(s.name)} · ${money(s.price)} · ${Number(s.duration_minutes)} min</option>`).join('')}</select>
      </label>
      <label class="staff-form-field staff-field-full">Observações (opcional)
        <textarea name="notes" maxlength="500" rows="3" placeholder="Informações para o atendimento"></textarea>
      </label>`;
    form.querySelector('[type="submit"]').textContent='Cadastrar cliente';
    dialog.showModal();
    form.elements.namedItem('client_name')?.focus();
  }

  const addDays = (day, count) => {
    const d=new Date(`${day}T12:00:00Z`);d.setUTCDate(d.getUTCDate()+count);
    return d.toISOString().slice(0,10);
  };

  function showReschedule(booking) {
    if (!booking || booking.status!=='confirmed' || String(booking.starts_at).slice(0,10)!==today() || saving || !dialog || !form) return;
    editorMode='reschedule';chosenTime='';rescheduleId=Number(booking.id);rescheduleTime='';
    const currentDate=today();
    dialogTitle.textContent='Remarcar atendimento';
    dialogFields.innerHTML=`<p class="staff-modal-hint"><strong>${esc(booking.client_name)}</strong> · ${esc(booking.service_name)}<br>
      Horário atual: ${esc(String(booking.starts_at).slice(11,16))}. O cliente e o profissional serão mantidos. Avise o cliente após salvar.</p>
      <label class="staff-form-field staff-field-full">Nova data (até 30 dias)
        <input name="date" type="date" min="${currentDate}" max="${addDays(currentDate,30)}" value="${currentDate}" required>
      </label>
      <div class="staff-field-full"><label class="staff-form-field">Novo horário</label>
        <input name="time" type="hidden" value="">
        <div id="staff-reschedule-slots" class="reschedule-slots" role="group" aria-label="Horários livres" aria-live="polite">Consultando horários...</div>
      </div>`;
    const submit=form.querySelector('[type="submit"]');
    submit.textContent='Salvar remarcação';submit.disabled=true;
    dialog.showModal();
    void loadRescheduleSlots();
  }

  async function loadRescheduleSlots() {
    if (editorMode!=='reschedule' || !rescheduleId || !dialog?.open) return;
    const seq=++rescheduleSeq;
    const date=form.elements.namedItem('date')?.value;
    const area=dialogFields.querySelector('#staff-reschedule-slots');
    const submit=form.querySelector('[type="submit"]');
    if(!area)return;
    rescheduleTime='';form.elements.namedItem('time').value='';submit.disabled=true;
    area.innerHTML='<p class="text-muted">Consultando horários livres...</p>';
    try {
      const result=await api('staff_reschedule_slots',null,{id:rescheduleId,date});
      if(seq!==rescheduleSeq || editorMode!=='reschedule' || !dialog?.open)return;
      const slots=Array.isArray(result.slots)?result.slots:[];
      area.innerHTML=slots.length?`<div class="reschedule-time-grid">${slots.map(slot=>`<button type="button" data-staff-newtime="${esc(slot.time)}" class="reschedule-time" aria-pressed="false">${esc(slot.time)}</button>`).join('')}</div>`:
        '<p class="reschedule-empty">Nenhum horário livre nessa data. Escolha outro dia.</p>';
    } catch(e) {
      if(seq!==rescheduleSeq || !dialog?.open)return;
      area.innerHTML=`<p class="error-banner" role="alert">${esc(e.message)}</p>`;
    }
  }

  dialogFields?.addEventListener('change',event=>{
    if (editorMode==='reschedule' && event.target.name==='date') void loadRescheduleSlots();
  });
  dialogFields?.addEventListener('click',event=>{
    const button=event.target.closest('[data-staff-newtime]');
    if(!button || editorMode!=='reschedule')return;
    rescheduleTime=button.dataset.staffNewtime;
    form.elements.namedItem('time').value=rescheduleTime;
    dialogFields.querySelectorAll('[data-staff-newtime]').forEach(b=>{
      const active=b===button;b.classList.toggle('is-selected',active);b.setAttribute('aria-pressed',String(active));
    });
    form.querySelector('[type="submit"]').disabled=false;
  });

  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (saving) return;
    if (editorMode==='reschedule') {
      if (!rescheduleId || !rescheduleTime) return;
      const submit=form.querySelector('[type="submit"]');
      const chosenDate=form.elements.namedItem('date')?.value;
      saving=true;submit.disabled=true;submit.textContent='Remarcando...';
      dialogFields.querySelector('.staff-form-error')?.remove();
      try {
        const result=await api('staff_reschedule',{id:rescheduleId,date:chosenDate,time:rescheduleTime});
        dialog.close();await loadDay();
        note(message,result.message||'Agendamento remarcado. Avise o cliente.');
      } catch(e) {
        const error=document.createElement('p');error.className='error-banner staff-form-error';error.textContent=e.message;
        dialogFields.prepend(error);submit.disabled=false;submit.textContent='Salvar remarcação';
        void loadRescheduleSlots();
      } finally {saving=false;}
      return;
    }
    if (!chosenTime) return;
    const name = form.elements.namedItem('client_name')?.value.trim() || '';
    const phone = form.elements.namedItem('client_phone')?.value.trim() || '';
    const service = Number(form.elements.namedItem('service_id')?.value || 0);
    const notes = form.elements.namedItem('notes')?.value.trim() || '';
    const error = dialogFields.querySelector('.staff-form-error');
    error?.remove();
    if (!window.MartelliPhone?.toLocal(phone)) {
      const banner = document.createElement('p');
      banner.className = 'error-banner staff-form-error';
      banner.textContent = 'Informe um WhatsApp válido com DDD, com ou sem +55.';
      dialogFields.prepend(banner); return;
    }
    const submit = form.querySelector('[type="submit"]');
    saving = true;
    submit.disabled = true;
    submit.textContent = 'Salvando...';
    try {
      await api('staff_booking',{name,phone,service_id:service,time:chosenTime,notes});
      dialog.close();
      chosenTime = '';
      await loadDay();
      note(message,`Cliente ${name} agendado com sucesso. A agenda foi atualizada.`);
    } catch(e) {
      const banner = document.createElement('p');
      banner.className='error-banner staff-form-error';
      banner.textContent=e.message;
      dialogFields.prepend(banner);
      // Se houver conflito por outra reserva simultânea, atualizar só após fechar.
    } finally {saving=false;submit.disabled=false;submit.textContent='Cadastrar cliente';}
  });

  let approving = false;
  async function approveAppointment(button) {
    if (approving || !agenda) return;
    const id = Number(button.dataset.staffApprove);
    const booking = (agenda.appointments || []).find(a => Number(a.id) === id);
    if (!Number.isSafeInteger(id) || id<=0 || !booking || booking.status !== 'pending' || !afterSix(booking)) return;
    if (!window.confirm(`Aprovar o atendimento de ${booking.client_name} às ${String(booking.starts_at).slice(11,16)}?`)) return;
    approving = true;
    button.disabled = true;
    button.textContent = 'Aprovando...';
    try {
      await api('staff_approve',{id});
      await loadDay();
      note(message,'Atendimento aprovado! Para avisar o cliente, toque em "WhatsApp: avisar cliente" na reserva confirmada.');
    } catch(e) {
      note(message,e.message,true);
      button.disabled = false;
      button.textContent = '✓ Aprovar atendimento';
    } finally { approving = false; }
  }
  document.getElementById('dialog-cancel')?.addEventListener('click', () => dialog?.close());
  dialog?.addEventListener('close', () => {
    rescheduleSeq++;chosenTime='';rescheduleTime='';rescheduleId=0;editorMode='booking';form?.reset();
    const submit=form?.querySelector('[type="submit"]');if(submit){submit.disabled=false;submit.textContent='Salvar';}
  });
  view.addEventListener('click', event => {
    if (event.target.closest('#staff-refresh')) {void loadDay();return;}
    const reschedule=event.target.closest('[data-staff-reschedule]');
    if(reschedule){
      const booking=(agenda?.appointments||[]).find(a=>Number(a.id)===Number(reschedule.dataset.staffReschedule));
      showReschedule(booking);return;
    }
    const approve = event.target.closest('[data-staff-approve]');
    if (approve) { void approveAppointment(approve); return; }
    const slot = event.target.closest('[data-staff-slot]');
    if (slot) showBooking(slot.dataset.staffSlot);
  });
  document.getElementById('logout')?.addEventListener('click',async()=>{
    try {await api('logout',{});location.assign('/login.php');}
    catch(e){note(message,e.message,true);}
  });
  document.getElementById('admin-nav')?.addEventListener('click',event=>{
    if (event.target.closest('[data-tab="dia"]')) void loadDay();
  });
  document.getElementById('admin-mobile-nav')?.addEventListener('change',()=>void loadDay());
  await loadDay();
})();
