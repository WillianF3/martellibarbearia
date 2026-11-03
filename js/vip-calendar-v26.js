"use strict";
(() => {
  const $ = id => document.getElementById(id);
  const token = new URLSearchParams(location.search).get('chave') || '';
  const tz = 'America/Sao_Paulo';
  let details = null, chosenTime = '', slots = [], shift = 'all', weekStart = '', currentRequest = 0, saving = false, selectedDate = '', showAllSlots = false, lastToday = '';
  let editingAppointment = null, newRescheduleTime = '', rescheduleSlots = [], rescheduleLoading = false, rescheduleSequence = 0, rescheduleWeekStart = '', rescheduleShift = 'all', rescheduleShowAll = false;
  const safe = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  const validDate = value => /^\d{4}-\d{2}-\d{2}$/.test(String(value || ''));
  const brDate = value => validDate(value) ? value.slice(8,10)+'/'+value.slice(5,7)+'/'+value.slice(0,4) : '—';
  const todaySP = () => new Intl.DateTimeFormat('sv-SE', {timeZone:tz, year:'numeric', month:'2-digit', day:'2-digit'}).format(new Date());
  function plusDays(value, n) { const d = new Date(value+'T12:00:00Z'); d.setUTCDate(d.getUTCDate()+n); return d.toISOString().slice(0,10); }
  const labelDate = value => validDate(value) ? new Intl.DateTimeFormat('pt-BR', {weekday:'long',day:'numeric',month:'long',timeZone:tz}).format(new Date(value+'T12:00:00-03:00')) : '—';
  const shortWeekday = value => new Intl.DateTimeFormat('pt-BR', {weekday:'short',timeZone:tz}).format(new Date(value+'T12:00:00-03:00')).replace('.','');
  const member = () => details?.member || {};
  function bounds() { const m=member(), t=todaySP(), min=m.starts_on>t?m.starts_on:t, end=plusDays(t,180); return {min, max:m.ends_on<end?m.ends_on:end}; }
  /** Os dias bloqueados vêm do servidor, nunca apenas do navegador. */
  const dayIsReserved = date => Array.isArray(details?.blocked_dates) && details.blocked_dates.includes(date);
  function firstAvailableDate() {
    if(!details)return '';
    const {min,max}=bounds();
    if(!validDate(min)||!validDate(max)||min>max)return '';
    for(let day=min;day<=max;day=plusDays(day,1))if(!dayIsReserved(day))return day;
    return '';
  }
  const canSchedule = () => Boolean(member().can_book && Number(member().remaining)>0 && details?.services?.length && firstAvailableDate());
  function bookingBlockReason() {
    if(!details)return 'Carregando sua assinatura…';
    const m=member(),today=todaySP(),b=bounds();
    if(Number(m.remaining)<=0)return `Seus ${m.included_cuts} atendimentos já estão utilizados ou reservados. Você pode remarcar um atendimento futuro em “Meus agendamentos”, sem perder cortes.`;
    if(m.status!=='active')return 'Sua assinatura não está ativa. Fale com a barbearia para regularizar.';
    if(m.ends_on<today)return `Seu plano venceu em ${brDate(m.ends_on)}. Solicite a renovação para agendar.`;
    if(b.min>b.max)return `Seu ciclo VIP vai de ${brDate(m.starts_on)} até ${brDate(m.ends_on)}. Não existem dias liberados para novas reservas neste momento.`;
    if(!firstAvailableDate())return 'Todos os dias disponíveis do seu plano já possuem cortes VIP reservados. Para alterar uma data, utilize Remarcar em Meus agendamentos.';
    if(!details.bookings_enabled)return 'Os agendamentos online estão temporariamente indisponíveis. Fale com a barbearia.';
    if(!details.services?.length)return 'O serviço do seu plano está indisponível. Consulte a barbearia.';
    return '';
  }
  function renderMembershipGuidance() {
    const m=member(),today=todaySP(),hint=$('vip-calendar-guidance');
    $('vip-calendar-today').textContent=`Hoje: ${labelDate(today)} · Horário de Brasília`;
    $('vip-calendar-cycle').textContent=`Período para agendar: ${brDate(m.starts_on)} a ${brDate(m.ends_on)} · ciclo contratado`;
    $('vip-calendar-jump-start').hidden=true; // Calendário já começa no primeiro dia elegível.
    const reason=bookingBlockReason();
    hint.hidden=false;hint.className='vip-cycle-guidance '+(reason?'is-blocked':m.starts_on>today?'is-future':'is-ready');
    if(reason){
      hint.textContent=reason;
    } else if(m.starts_on>today){
      hint.textContent=`Sua assinatura começa em ${brDate(m.starts_on)}. O calendário mostra somente os dias dentro da vigência de ${brDate(m.starts_on)} até ${brDate(m.ends_on)}. Você pode agendar antecipadamente.`;
    } else {
      hint.textContent=`Você tem ${m.remaining} atendimento(s) para agendar. Não precisa escolher todas as datas agora: reserve um corte por vez, quando precisar.`;
    }
    $('vip-calendar-has-quota').hidden=Number(m.remaining)>0;
    const blocked=!canSchedule();
    $('vip-calendar-booking-title').textContent=Number(m.remaining)>0?'Agende um corte de cada vez':'Seu plano já tem todas as reservas feitas';
    $('vip-booking-unavailable').hidden=!blocked;
    $('vip-booking-unavailable-title').textContent=Number(m.remaining)<=0?`${m.included_cuts} de ${m.included_cuts} atendimentos já utilizados ou reservados`:'Agendamento indisponível';
    $('vip-booking-unavailable-text').textContent=bookingBlockReason();
    document.querySelector('.vip-booking-steps').hidden=blocked;
    document.querySelector('.vip-booking-layout').hidden=blocked;
    $('vip-calendar-show-today').hidden=(weekStart===bounds().min);
  }

  async function api(action, payload=null, query={}) {
    const url = new URL('/api/vip-agenda.php', location.origin);
    url.searchParams.set('action',action);
    if (payload === null) url.searchParams.set('chave',token);
    Object.entries(query).forEach(([key,value]) => url.searchParams.set(key,value));
    const res = await fetch(url,{method:payload===null?'GET':'POST', credentials:'same-origin',cache:'no-store',referrerPolicy:'no-referrer',headers:payload?{'Content-Type':'application/json','X-CSRF-Token':window.MARTELLI_VIP_CSRF}:{},body:payload?JSON.stringify({...payload,chave:token}):undefined});
    const data = await res.json().catch(()=>{throw Error('O servidor retornou uma resposta inválida.');});
    if (!res.ok || data.ok===false) throw Error(data.error || 'Não foi possível consultar a agenda.');
    return data;
  }
  function showError(message) { const el=$('vip-calendar-error');el.textContent=message;el.hidden=false; }
  function setStepState() {
    const done = {barber:Boolean($('vip-calendar-barber').value),date:Boolean(selectedDate),time:Boolean(chosenTime)};
    const current = !done.barber?'barber':!done.date?'date':!done.time?'time':'confirm';
    const order=['barber','date','time','confirm'];
    for (const stage of order) {
      const el=document.querySelector(`[data-vip-step="${stage}"]`);
      if(!el)continue;
      el.classList.toggle('current',stage===current);
      el.classList.toggle('is-done',order.indexOf(stage)<order.indexOf(current));
    }
  }
  function updateSummary() {
    const b=details?.barbers?.find(v=>String(v.id)===$('vip-calendar-barber').value);
    const chosen=slots.find(s=>s.time===chosenTime);
    $('vip-summary-plan').textContent=member().plan||'—';
    $('vip-summary-barber').textContent=b?.name||'Escolha acima';
    $('vip-summary-date').textContent=selectedDate?labelDate(selectedDate):'Escolha acima';
    $('vip-summary-time').textContent=chosenTime||'Escolha acima';
    $('vip-summary-approval').hidden=!chosen?.requires_approval;
    $('vip-summary-remaining').textContent=details ? `${member().remaining} de ${member().included_cuts} atendimento(s) ainda disponível(is) · ${member().reserved} já reservado(s)` : '';
    $('vip-summary-date').textContent=selectedDate?labelDate(selectedDate):'Selecione um dia disponível';
    $('vip-summary-time').textContent=b && chosenTime?chosenTime:'Selecione um horário';
    $('vip-summary-block-note').textContent=canSchedule()?'':bookingBlockReason();
    $('vip-summary-block-note').hidden=canSchedule();
    $('vip-calendar-confirm').disabled=Boolean(saving || !canSchedule() || !b || !selectedDate || !chosenTime || !chosen);
    setStepState();
  }
  function drawBarbers() {
    const parent=$('vip-calendar-barber-cards');
    parent.replaceChildren();
    $('vip-calendar-barber').innerHTML='<option value="">Selecione o barbeiro</option>'+(details.barbers||[]).map(b=>`<option value="${Number(b.id)}">${safe(b.name)}</option>`).join('');
    for(const barber of details.barbers||[]) {
      const button=document.createElement('button');button.type='button';button.className='vip-barber-choice';button.dataset.id=barber.id;
      button.innerHTML=`<span class="vip-barber-avatar" aria-hidden="true">✂</span><span>${safe(barber.name)}</span><small>Selecionar</small>`;
      button.addEventListener('click',()=>{
        if(!canSchedule())return;
        $('vip-calendar-barber').value=String(barber.id);
        parent.querySelectorAll('button').forEach(item=>{const active=item===button;item.classList.toggle('selected',active);item.setAttribute('aria-pressed',String(active));item.querySelector('small').textContent=active?'Selecionado ✓':'Selecionar';});
        loadSlots();updateSummary();
      });
      button.setAttribute('aria-pressed','false');button.disabled=!canSchedule();parent.append(button);
    }
    if(!details.barbers?.length)parent.textContent='Nenhum barbeiro disponível no momento.';
  }
  function drawDates() {
    const today=todaySP(), {min,max}=bounds(), rail=$('vip-calendar-date-rail');rail.replaceChildren();
    if(!weekStart || weekStart<min)weekStart=min;
    if(weekStart>max)weekStart=max;
    const end=plusDays(weekStart,6)>max?max:plusDays(weekStart,6);
    $('vip-calendar-week-label').textContent=min>max?'Fora do período do plano':`${brDate(weekStart)} — ${brDate(end)}`;
    $('vip-calendar-prev-week').disabled=weekStart<=min;
    $('vip-calendar-next-week').disabled=plusDays(weekStart,7)>max;
    for(let i=0;i<7;i++) {
      const value=plusDays(weekStart,i);if(value>max||value<min)break;
      const reserved=dayIsReserved(value);
      const isBookable=canSchedule() && value>=min && value<=max && !reserved;
      const button=document.createElement('button');button.type='button';button.className='vip-date-choice';button.dataset.date=value;
      const month=new Intl.DateTimeFormat('pt-BR',{month:'short',timeZone:tz}).format(new Date(value+'T12:00:00-03:00')).replace('.','');
      button.innerHTML=`<span>${safe(shortWeekday(value))}</span><strong>${safe(value.slice(8,10))}</strong><small>${reserved?'Reservado':value===today?'Hoje':safe(month)}</small>`;
      button.classList.toggle('chosen',value===selectedDate);button.classList.toggle('out-of-plan',!isBookable);button.classList.toggle('is-reserved',reserved);
      button.setAttribute('aria-pressed',String(value===selectedDate));
      button.disabled=!isBookable;
      if(reserved)button.title='Você já tem um atendimento VIP neste dia. Escolha outro dia.';
      else if(!isBookable)button.title=value<min?'Fora da vigência da assinatura':'Data indisponível para este plano';
      button.addEventListener('click',()=>chooseDate(value));rail.appendChild(button);
    }
    $('vip-calendar-date').min=min;
    $('vip-calendar-date').max=max;
    $('vip-calendar-date').disabled=!canSchedule();
    $('vip-calendar-show-today').hidden=(weekStart===bounds().min);
  }
  function chooseDate(value) {
    const {min,max}=bounds();
    if(dayIsReserved(value)){
      $('vip-calendar-slots-message').textContent='Você já possui um corte VIP nesse dia. Escolha outra data ou remarque seu atendimento em Meus agendamentos.';
      $('vip-calendar-date').value=selectedDate;
      return;
    }
    if(!canSchedule()||!validDate(value)||value<min||value>max){$('vip-calendar-date').value=selectedDate;return;}
    selectedDate=value;$('vip-calendar-date').value=value;
    if(value<weekStart||value>plusDays(weekStart,6))weekStart=value;
    drawDates();loadSlots();updateSummary();
  }
  function slotShift(s) {return s.time<'12:00'?'morning':s.time<'18:00'?'afternoon':'evening';}
  function drawSlots() {
    if(!canSchedule()) {clearSlots(bookingBlockReason());return;}
    const filtered=slots.filter(s=>shift==='all'||slotShift(s)===shift);
    const displayed=showAllSlots?filtered:filtered.slice(0,12);
    const grid=$('vip-calendar-grid');grid.replaceChildren();
    for(const item of displayed) {
      const button=document.createElement('button');button.type='button';button.className='vip-calendar-slot';button.dataset.time=item.time;
      button.innerHTML=`<strong>${safe(item.time)}</strong><small>${item.requires_approval?'Sob consulta':'Disponível'}</small>`;
      const active=chosenTime===item.time;button.classList.toggle('chosen',active);button.classList.toggle('requires-approval',Boolean(item.requires_approval));button.setAttribute('aria-pressed',String(active));
      button.addEventListener('click',()=>{chosenTime=item.time;drawSlots();updateSummary();});
      grid.append(button);
    }
    if(!filtered.length && slots.length)grid.innerHTML='<p class="vip-calendar-hint vip-calendar-no-slots">Nenhum horário neste período. Escolha outro filtro.</p>';
    $('vip-calendar-show-more').hidden=filtered.length<=12;
    $('vip-calendar-show-more').textContent=showAllSlots?'Mostrar menos horários':`Mostrar mais ${filtered.length-12} horários`;
    $('vip-calendar-slots').disabled=!slots.length;
    $('vip-calendar-shift-filters').hidden=!slots.length;
    $('vip-calendar-next-day').hidden=slots.length>0 || !canSchedule() || selectedDate>=bounds().max;
    $('vip-calendar-slots-message').textContent=slots.length?`${slots.length} horário(s) da barbearia disponível(is) em ${brDate(selectedDate)}. Selecione um para continuar.`:'Nenhum horário livre neste dia. Escolha outra data.';
    for(const b of $('vip-calendar-shift-filters').querySelectorAll('[data-vip-shift]')){
      const key=b.dataset.vipShift;b.textContent=({all:'Todos',morning:'Manhã',afternoon:'Tarde',evening:'Após 18h'})[key]+' · '+slots.filter(s=>key==='all'||slotShift(s)===key).length;
      b.classList.toggle('is-active',key===shift);b.setAttribute('aria-pressed',String(key===shift));
    }
    updateSummary();
  }
  function clearSlots(message) {
    slots=[];chosenTime='';$('vip-calendar-grid').replaceChildren();
    $('vip-calendar-slots').disabled=true;$('vip-calendar-shift-filters').hidden=true;
    $('vip-calendar-next-day').hidden=true;$('vip-calendar-show-more').hidden=true;
    $('vip-calendar-slots-message').textContent=message;
    updateSummary();
  }
  async function loadSlots() {
    const run=++currentRequest;chosenTime='';slots=[];shift='all';showAllSlots=false;updateSummary();
    const barber=$('vip-calendar-barber').value;
    $('vip-calendar-grid').replaceChildren();$('vip-calendar-slots').disabled=true;
    $('vip-calendar-shift-filters').hidden=true;$('vip-calendar-next-day').hidden=true;$('vip-calendar-show-more').hidden=true;
    if(!canSchedule()){clearSlots(bookingBlockReason());return;}
    if(dayIsReserved(selectedDate)){clearSlots('Você já possui um atendimento VIP nessa data. Escolha outro dia.');return;}
    if(!barber){clearSlots('Etapa 1: selecione seu barbeiro para ver os horários.');return;}
    if(!selectedDate){clearSlots('Etapa 2: escolha uma data liberada no seu plano.');return;}
    $('vip-calendar-slots-message').textContent='Buscando horários livres…';
    try {
      const result=await api('slots',null,{date:selectedDate,barber_id:barber});if(run!==currentRequest)return;
      slots=Array.isArray(result.slots)?result.slots.filter(s=>/^([01]\d|2[0-3]):[0-5]\d$/.test(s.time)):[];
      drawSlots();
      if(!slots.length && result.message)$('vip-calendar-slots-message').textContent=result.message;
    }catch(ex){if(run!==currentRequest)return;clearSlots(ex.message);}
  }
  const labels={pending:'Aguardando aprovação',confirmed:'Confirmado',completed:'Concluído',cancelled:'Cancelado',no_show:'Falta'};
  function canReschedule(a) {
    if (!details?.bookings_enabled || member().status !== 'active') return false;
    if (!['pending','confirmed'].includes(a.status)) return false;
    const start = new Date(a.starts_at.replace(' ','T')+'-03:00');
    return Number.isFinite(start.getTime()) && start.getTime() > Date.now()+15*60*1000;
  }
  function drawHistory() {
    const target=$('vip-calendar-bookings'),items=details.appointments||[];
    target.replaceChildren();
    if(!items.length){target.innerHTML='<p class="vip-calendar-hint">Você ainda não reservou um corte. Pode escolher seu primeiro horário quando preferir, dentro do período do plano.</p>';return;}
    const list=document.createElement('div');list.className='vip-calendar-history';
    for(const a of items){
      const row=document.createElement('div');row.className='vip-history-row';
      row.innerHTML=`<strong>${safe(brDate(a.starts_at.slice(0,10)))} · ${safe(a.starts_at.slice(11,16))}</strong><span>${safe(a.barber_name)}</span><small>${safe(labels[a.status]||'Registrado')}</small>`;
      if(canReschedule(a)){
        const button=document.createElement('button');button.type='button';button.className='vip-history-reschedule';button.textContent='↻ Remarcar';
        button.setAttribute('aria-label',`Remarcar atendimento de ${brDate(a.starts_at.slice(0,10))} às ${a.starts_at.slice(11,16)}`);
        button.addEventListener('click',()=>openReschedule(a));row.appendChild(button);
      }
      list.appendChild(row);
    }
    target.appendChild(list);
  }
  const rescheduleDateTaken = date => dayIsReserved(date) && date!==editingAppointment?.starts_at.slice(0,10);
  const rescheduleValid = date => { const b=bounds();return validDate(date)&&date>=b.min&&date<=b.max&&!rescheduleDateTaken(date); };
  const rescheduleShiftMatches = slot => {
    const h=Number(slot.time.slice(0,2));
    return rescheduleShift==='all'||rescheduleShift==='morning'&&h<12||rescheduleShift==='afternoon'&&h>=12&&h<18||rescheduleShift==='evening'&&h>=18;
  };
  function renderRescheduleDates() {
    const {min,max}=bounds();
    if(!rescheduleWeekStart||rescheduleWeekStart<min)rescheduleWeekStart=min;
    if(rescheduleWeekStart>max)rescheduleWeekStart=max;
    const end=plusDays(rescheduleWeekStart,6);
    $('vip-reschedule-week-label').textContent=`${brDate(rescheduleWeekStart)} – ${brDate(end>max?max:end)}`;
    $('vip-reschedule-prev-week').disabled=rescheduleWeekStart<=min;
    $('vip-reschedule-next-week').disabled=plusDays(rescheduleWeekStart,7)>max;
    const rail=$('vip-reschedule-day-rail');rail.replaceChildren();
    const days=['dom','seg','ter','qua','qui','sex','sáb'];
    for(let i=0;i<7;i++) {
      const date=plusDays(rescheduleWeekStart,i);
      if(date>max)break;
      const button=document.createElement('button');button.type='button';button.className='vip-reschedule-day';
      const d=new Date(date+'T12:00:00-03:00');
      const reserved=rescheduleDateTaken(date);
      button.innerHTML=`<span>${days[d.getUTCDay()]}</span><strong>${date.slice(8,10)}</strong><small>${reserved?'Reservado':new Intl.DateTimeFormat('pt-BR',{month:'short',timeZone:tz}).format(d).replace('.','')}</small>`;
      const active=date===$('vip-reschedule-date').value;
      button.classList.toggle('chosen',active);button.classList.toggle('is-reserved',reserved);button.setAttribute('aria-pressed',String(active));
      button.disabled=date<min||date>max||reserved;
      if(reserved)button.title='Já existe um corte VIP nessa data';
      if(!button.disabled)button.addEventListener('click',()=>{
        $('vip-reschedule-date').value=date;renderRescheduleDates();loadRescheduleSlots();
      });
      rail.append(button);
    }
  }
  function renderRescheduleSlots(){
    const grid=$('vip-reschedule-slots');grid.replaceChildren();
    const filtered=rescheduleSlots.filter(rescheduleShiftMatches);
    $('vip-reschedule-slot-count').textContent=rescheduleSlots.length ? `${filtered.length} horário(s) nesta faixa` : '';
    const visible=rescheduleShowAll?filtered:filtered.slice(0,12);
    for(const slot of visible){
      const button=document.createElement('button');button.type='button';button.className='vip-reschedule-slot';
      button.innerHTML=`<strong>${safe(slot.time)}</strong><small>${slot.requires_approval?'Sob consulta':'Disponível'}</small>`;
      const active=newRescheduleTime===slot.time;
      button.classList.toggle('chosen',active);button.setAttribute('aria-pressed',String(active));
      button.setAttribute('aria-label',`${slot.time}, ${slot.requires_approval?'sob consulta':'livre'}`);
      button.addEventListener('click',()=>{
        newRescheduleTime=slot.time;
        $('vip-reschedule-selected').textContent=`Novo horário: ${labelDate($('vip-reschedule-date').value)} às ${slot.time}${slot.requires_approval?' · sujeito à aprovação do barbeiro':''}`;
        $('vip-reschedule-save').disabled=false;
        renderRescheduleSlots();
      });grid.append(button);
    }
    const more=$('vip-reschedule-more');more.hidden=filtered.length<=12;
    more.textContent=rescheduleShowAll?'Mostrar menos':`Ver todos os ${filtered.length} horários`;
    if(rescheduleSlots.length && !filtered.length){
      const p=document.createElement('p');p.className='vip-reschedule-empty';p.textContent='Nenhum horário neste período. Tente outro filtro.';grid.append(p);
    }
  }
  async function loadRescheduleSlots() {
    const sequence=++rescheduleSequence;
    newRescheduleTime='';rescheduleSlots=[];rescheduleShowAll=false;
    $('vip-reschedule-slots').replaceChildren();
    const barber=$('vip-reschedule-barber').value,date=$('vip-reschedule-date').value;
    $('vip-reschedule-save').disabled=true;
    $('vip-reschedule-selected').textContent='Nenhum novo horário selecionado.';
    renderRescheduleDates();renderRescheduleSlots();
    if(!editingAppointment||!barber||!rescheduleValid(date)){
      const m=member(),{min,max}=bounds();
      $('vip-reschedule-hint').textContent=rescheduleDateTaken(date)?'Você já tem outro corte VIP nessa data. Escolha um dia diferente.':validDate(date)&&date<min?
        `Essa data é anterior à vigência. Seu plano permite reservas de ${brDate(m.starts_on)} a ${brDate(m.ends_on)}. Se essas datas estiverem erradas, peça ao administrador para corrigir sua assinatura.`:
        validDate(date)&&date>max?`Data fora do período permitido. Escolha entre ${brDate(min)} e ${brDate(max)}.`:
        'Escolha um profissional e uma data válida para continuar.';
      return;
    }
    $('vip-reschedule-hint').textContent='Consultando horários disponíveis…';rescheduleLoading=true;
    try {
      const data=await api('reschedule_slots',null,{appointment_id:editingAppointment.id,barber_id:barber,date});
      if(sequence!==rescheduleSequence)return;
      rescheduleSlots=data.slots||[];
      $('vip-reschedule-hint').textContent=rescheduleSlots.length?`${rescheduleSlots.length} opção(ões) disponível(is) em ${brDate(date)}. Toque em um horário para selecioná-lo.`:'Nenhum horário livre nessa data. Selecione outro dia ou profissional.';
      renderRescheduleSlots();
    }catch(ex){if(sequence===rescheduleSequence)$('vip-reschedule-hint').textContent=ex.message;}
    finally{if(sequence===rescheduleSequence)rescheduleLoading=false;}
  }
  function openReschedule(a){
    if(!canReschedule(a))return;
    editingAppointment=a;newRescheduleTime='';rescheduleShift='all';rescheduleShowAll=false;
    const {min,max}=bounds();
    $('vip-reschedule-old').textContent=`Reserva atual: ${labelDate(a.starts_at.slice(0,10))} às ${a.starts_at.slice(11,16)} · ${a.barber_name}`;
    const m=member();$('vip-reschedule-validity').textContent=`Escolha um dia dentro do período contratado: ${brDate(m.starts_on)} a ${brDate(m.ends_on)}. Fora dessas datas não é possível remarcar. Se a vigência estiver errada, solicite correção à barbearia.`;
    const select=$('vip-reschedule-barber');select.replaceChildren();
    for(const barber of details.barbers||[]){const option=new Option(barber.name,String(barber.id));select.add(option);}
    select.value=String(a.barber_id);
    $('vip-reschedule-date').min=min;$('vip-reschedule-date').max=max;
    const start=a.starts_at.slice(0,10);
    $('vip-reschedule-date').value=rescheduleValid(start)?start:min;
    rescheduleWeekStart=$('vip-reschedule-date').value;
    $('vip-reschedule-selected').textContent='Nenhum novo horário selecionado.';
    $('vip-reschedule-save').disabled=true;
    $('vip-reschedule-filters').querySelectorAll('button').forEach(button=>{const active=button.dataset.rescheduleShift==='all';button.classList.toggle('active',active);button.setAttribute('aria-pressed',String(active));});
    renderRescheduleDates();
    const dialog=$('vip-reschedule-dialog');
    if(typeof dialog.showModal==='function')dialog.showModal();else dialog.setAttribute('open','');
    loadRescheduleSlots();
  }
  async function confirmReschedule(e){
    e.preventDefault();
    if(!editingAppointment||!newRescheduleTime||rescheduleLoading||saving)return;
    const date=$('vip-reschedule-date').value,barber=Number($('vip-reschedule-barber').value),time=newRescheduleTime;
    if(!rescheduleValid(date)||!rescheduleSlots.some(s=>s.time===time))return;
    if(!confirm(`Confirmar remarcação para ${brDate(date)} às ${time}? A reserva anterior será substituída sem descontar outro corte.`))return;
    saving=true;$('vip-reschedule-save').disabled=true;
    try{
      const result=await api('reschedule',{appointment_id:Number(editingAppointment.id),barber_id:barber,date,time});
      const dialog=$('vip-reschedule-dialog');if(dialog.open&&dialog.close)dialog.close();else dialog.removeAttribute('open');
      editingAppointment=null;details=await api('details');render();
      const feedback=$('vip-reschedule-feedback');feedback.className='vip-calendar-ok';feedback.textContent=result.message;feedback.hidden=false;
      $('vip-calendar-history').scrollIntoView({behavior:'smooth',block:'start'});
    }catch(ex){$('vip-reschedule-hint').textContent=ex.message;await loadRescheduleSlots();}
    finally{saving=false;updateSummary();}
  }
  const closeReschedule=()=>{const d=$('vip-reschedule-dialog');if(d.close)d.close();else d.removeAttribute('open');};
  $('vip-reschedule-barber').addEventListener('change',loadRescheduleSlots);
  $('vip-reschedule-date').addEventListener('change',()=>{const date=$('vip-reschedule-date').value;if(rescheduleValid(date))rescheduleWeekStart=date;loadRescheduleSlots();});
  $('vip-reschedule-prev-week').addEventListener('click',()=>{const min=bounds().min;const earlier=plusDays(rescheduleWeekStart,-7);rescheduleWeekStart=earlier<min?min:earlier;$('vip-reschedule-date').value=rescheduleWeekStart;loadRescheduleSlots();});
  $('vip-reschedule-next-week').addEventListener('click',()=>{const max=bounds().max;const later=plusDays(rescheduleWeekStart,7);if(later>max)return;rescheduleWeekStart=later;$('vip-reschedule-date').value=later;loadRescheduleSlots();});
  $('vip-reschedule-filters').addEventListener('click',e=>{const button=e.target.closest('[data-reschedule-shift]');if(!button)return;rescheduleShift=button.dataset.rescheduleShift;rescheduleShowAll=false;$('vip-reschedule-filters').querySelectorAll('button').forEach(b=>{const active=b===button;b.classList.toggle('active',active);b.setAttribute('aria-pressed',String(active));});renderRescheduleSlots();});
  $('vip-reschedule-more').addEventListener('click',()=>{rescheduleShowAll=!rescheduleShowAll;renderRescheduleSlots();});
  $('vip-reschedule-form').addEventListener('submit',confirmReschedule);
  $('vip-reschedule-close').addEventListener('click',closeReschedule);
  $('vip-reschedule-x').addEventListener('click',closeReschedule);
  $('vip-reschedule-dialog').addEventListener('close',()=>{editingAppointment=null;++rescheduleSequence;});
  function render() {
    const m=member();$('vip-calendar-client').textContent=m.name;
    $('vip-calendar-plan').textContent=m.plan;
    $('vip-calendar-validity').textContent=`Vigência: ${brDate(m.starts_on)} a ${brDate(m.ends_on)} · ${m.included_cuts} atendimentos no plano · Serviço: ${m.service_name||'Corte'}`;
    $('vip-calendar-left').textContent=m.remaining;$('vip-calendar-reserved').textContent=m.reserved;
    const today=todaySP(),{min,max}=bounds();
    if(!lastToday)lastToday=today;
    if(!weekStart)weekStart=min<=max?min:today;
    // Exibir somente as datas elegíveis do ciclo, inclusive quando a assinatura começa no futuro.
    if(!canSchedule() || selectedDate<min || selectedDate>max || dayIsReserved(selectedDate))selectedDate='';
    if(!selectedDate && canSchedule())selectedDate=firstAvailableDate();
    if(selectedDate && (selectedDate<weekStart || selectedDate>plusDays(weekStart,6)))weekStart=selectedDate;
    $('vip-calendar-date').value=selectedDate;
    drawBarbers();drawDates();drawHistory();renderMembershipGuidance();
    $('vip-calendar-loading').hidden=true;$('vip-calendar-content').hidden=false;
    if(!canSchedule()) {
      clearSlots(bookingBlockReason());
    }else if(!selectedDate){
      clearSlots(m.starts_on>today?'Escolha um dia dentro dos 30 dias da assinatura, a partir de '+brDate(m.starts_on)+'.':'Escolha um dia do seu ciclo VIP e o barbeiro para consultar horários.');
    }else{
      clearSlots('Etapa 1: selecione seu barbeiro para consultar os horários.');
    }
    updateSummary();
  }
  async function submitReservation() {
    if(saving || !canSchedule()||!chosenTime)return;
    const b=$('vip-calendar-barber').value,day=selectedDate,time=chosenTime;
    saving=true;updateSummary();$('vip-review-confirm').disabled=true;
    const feedback=$('vip-calendar-feedback');feedback.hidden=true;
    try {
      const r=await api('reserve',{date:day,time,barber_id:Number(b)});
      $('vip-calendar-review').close();
      details=await api('details');render();
      if(details.barbers.some(x=>String(x.id)===b) && canSchedule()) {
        // A seleção anterior continua visível, mas não mantém um horário que acabou de ser reservado.
        const card=$('vip-calendar-barber-cards').querySelector(`button[data-id="${b}"]`);
        if(card)card.click();
      }
      feedback.className='vip-calendar-ok';feedback.textContent=r.message;feedback.hidden=false;
      feedback.scrollIntoView({behavior:'smooth',block:'nearest'});
    }catch(ex){feedback.className='vip-calendar-bad';feedback.textContent=ex.message;feedback.hidden=false;await loadSlots();}
    finally{saving=false;$('vip-review-confirm').disabled=false;updateSummary();}
  }
  $('vip-calendar-prev-week').addEventListener('click',()=>{const min=bounds().min,previous=plusDays(weekStart,-7);weekStart=previous<min?min:previous;selectedDate='';$('vip-calendar-date').value='';drawDates();loadSlots();});
  $('vip-calendar-next-week').addEventListener('click',()=>{const max=bounds().max;if(plusDays(weekStart,7)<=max){weekStart=plusDays(weekStart,7);selectedDate='';$('vip-calendar-date').value='';drawDates();loadSlots();}});
  $('vip-calendar-jump-start').addEventListener('click',()=>{if(canSchedule()){weekStart=bounds().min;chooseDate(firstAvailableDate());}});
  $('vip-calendar-show-today').addEventListener('click',()=>{weekStart=bounds().min;selectedDate='';if(canSchedule())selectedDate=firstAvailableDate();$('vip-calendar-date').value=selectedDate;drawDates();loadSlots();});
  $('vip-calendar-show-more').addEventListener('click',()=>{showAllSlots=!showAllSlots;drawSlots();});
  $('vip-calendar-date').addEventListener('change',e=>chooseDate(e.target.value));
  $('vip-calendar-next-day').addEventListener('click',()=>{
    const max=bounds().max;
    for(let day=plusDays(selectedDate,1);day<=max;day=plusDays(day,1)){
      if(!dayIsReserved(day)){chooseDate(day);return;}
    }
    $('vip-calendar-slots-message').textContent='Não existem mais datas livres neste ciclo VIP.';
  });
  $('vip-calendar-shift-filters').addEventListener('click',e=>{const b=e.target.closest('[data-vip-shift]');if(!b)return;shift=b.dataset.vipShift;showAllSlots=false;drawSlots();});
  $('vip-calendar-form').addEventListener('submit',e=>{
    e.preventDefault();if(!chosenTime||saving||!canSchedule())return;
    const b=details.barbers.find(x=>String(x.id)===$('vip-calendar-barber').value),slot=slots.find(s=>s.time===chosenTime);
    if(!b||!slot)return;
    $('vip-review-description').textContent=`${member().plan} · ${b.name} · ${labelDate(selectedDate)} às ${chosenTime}. Este atendimento será contabilizado no seu plano VIP.`;
    $('vip-review-note').hidden=!slot.requires_approval;
    if(slot.requires_approval)$('vip-review-note').textContent='Horário sob consulta: o barbeiro precisará aprovar esta solicitação.';
    const dialog=$('vip-calendar-review');if(typeof dialog.showModal==='function')dialog.showModal();else if(confirm('Confirmar seu atendimento VIP?'))submitReservation();
  });
  $('vip-review-back').addEventListener('click',()=>$('vip-calendar-review').close());
  $('vip-review-confirm').addEventListener('click',submitReservation);
  if(token && !/^[a-f0-9]{64}$/.test(token)) {showError('Link VIP inválido. Solicite um link pessoal à barbearia.');$('vip-calendar-loading').hidden=true;return;}
  // Se a página atravessar a meia-noite, o calendário recebe automaticamente a nova data.
  setInterval(()=>{if(!details)return;const current=todaySP();if(current!==lastToday){lastToday=current;weekStart=current;selectedDate='';++currentRequest;render();}},60000);
  api('details').then(r=>{details=r;render();}).catch(ex=>{if(!token&&/Entre com sua conta VIP/i.test(ex.message)){location.replace('/vip-entrar.php');return;}showError(ex.message);$('vip-calendar-loading').hidden=true;});
})();
