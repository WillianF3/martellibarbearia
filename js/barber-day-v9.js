'use strict';
// Martelli V18.2: controles sempre clicáveis para agendamentos confirmados de hoje, com confirmação explícita de registro antecipado.
// Toda autorização e conflito são verificados na API, nunca apenas nesta página.
(()=>{
 const {api,escape:esc,money}=window.Martelli;
 const list=document.getElementById('barber-appointments');
 const alert=document.getElementById('barber-alert');
 const prevDay=document.getElementById('barber-day-prev');
 const nextDay=document.getElementById('barber-day-next');
 const resetDay=document.getElementById('barber-day-reset');
 const dateTitle=document.getElementById('barber-today');
 const weekdayTitle=document.getElementById('barber-weekday');
 const slotsHeading=document.getElementById('barber-slots-heading');
 const slotsHelp=document.getElementById('barber-slots-help');
 const grid=document.getElementById('barber-slot-grid');
 const panel=document.getElementById('barber-booking-panel');
 const form=document.getElementById('barber-booking-form');
 const movePanel=document.getElementById('barber-reschedule-panel');
 const moveForm=document.getElementById('barber-reschedule-form');
 const moveDate=moveForm.elements.date;
 const moveTime=moveForm.elements.time;
 const moveFeedback=document.getElementById('barber-reschedule-feedback');
 const moveSubmit=moveForm.querySelector('[type="submit"]');
 let moving=null,moveRevision=0;
 const status={pending:'Aguardando aprovação',confirmed:'Confirmado',completed:'Concluído',cancelled:'Cancelado',no_show:'Falta'};
 const labels={available:'Livre',occupied:'Ocupado',past:'Encerrado',unavailable:'Indisponível'};
 let current=null,chosenTime='',saving=false,finishing=false,deciding=false,updatingStatus=false;
 let selectedDate=null,requestedVersion=0,isLoading=false,lastToday=null;
 const time=d=>String(d||'').slice(11,16);
 const nowBrazil=()=>new Intl.DateTimeFormat('sv-SE',{timeZone:'America/Sao_Paulo',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).format(new Date()).replace(' ','T');
 function hideForm(){panel.hidden=true;chosenTime='';form.reset();grid.querySelectorAll('[data-barber-slot]').forEach(b=>b.setAttribute('aria-pressed','false'));}
 function hideMove(){moveRevision++;moving=null;movePanel.hidden=true;moveForm.reset();moveTime.innerHTML='<option value="">Escolha uma data</option>';moveTime.disabled=true;moveSubmit.disabled=true;moveFeedback.textContent='';}
 const addDays=(date,days)=>{const d=new Date(date+'T12:00:00Z');d.setUTCDate(d.getUTCDate()+days);return d.toISOString().slice(0,10);};
 function beginMove(id){
  if(!current?.can_book)return;
  const a=(current.appointments||[]).find(a=>Number(a.id)===id && a.status==='confirmed');
  if(!a)return;
  hideForm();hideMove();moving=a;
  moveDate.min=current.date;moveDate.max=addDays(current.date,30);moveDate.value=current.date;
  document.getElementById('barber-reschedule-heading').textContent='Remarcar · '+a.client_name;
  movePanel.hidden=false;
  void loadMoveSlots();
  movePanel.scrollIntoView({behavior:'smooth',block:'center'});
  moveDate.focus();
 }
 async function loadMoveSlots(){
  const a=moving;if(!a)return;
  const version=++moveRevision;
  moveTime.disabled=true;moveSubmit.disabled=true;
  moveTime.innerHTML='<option value="">Buscando horários...</option>';
  moveFeedback.textContent='Consultando disponibilidade do novo horário...';
  try{
   const result=await api('barber_day_reschedule_slots',null,{id:a.id,date:moveDate.value});
   if(version!==moveRevision||!moving)return;
   const slots=(result.slots||[]).filter(s=>!(moveDate.value===current.date&&s.time===time(a.starts_at)));
   moveTime.innerHTML='<option value="">Selecione o novo horário</option>'+slots.map(s=>`<option value="${esc(s.time)}">${esc(s.time)}${s.extended?' · horário estendido':''}</option>`).join('');
   moveTime.disabled=!slots.length;
   moveFeedback.textContent=slots.length?'Escolha um horário e confirme a alteração. Avise o cliente após salvar.':'Nenhum horário livre nesta data. Escolha outro dia.';
  }catch(err){
   if(version!==moveRevision)return;
   moveTime.innerHTML='<option value="">Indisponível</option>';
   moveFeedback.textContent=err.message;
  }
 }

 function renderSlots(data){
  const slots=Array.isArray(data.slots)?data.slots:[];
  grid.innerHTML=slots.length?slots.map(s=>{
   const state=Object.hasOwn(labels,s.state)?s.state:'unavailable';
   const canEdit=Boolean(data.can_book)&&state==='available';
   const tag=canEdit?'button':'div';
   const attrs=canEdit?`type="button" data-barber-slot="${esc(s.time)}" aria-pressed="${chosenTime===s.time?'true':'false'}" title="Agendar cliente às ${esc(s.time)}"`:'';
   return `<${tag} class="barber-slot barber-slot-${state}" ${attrs} aria-label="${esc(s.time)} · ${esc(labels[state])}"><strong>${esc(s.time)}</strong><small>${canEdit?'Livre · Agendar':labels[state]}</small></${tag}>`;
  }).join(''):'<p class="restricted-empty">Não há grade disponível nesta data.</p>';
  document.getElementById('barber-permission-hint').textContent=data.date!==data.today
   ?'Consulta de atendimentos desta data. O cadastro e as alterações de horários ficam disponíveis somente na agenda de hoje.'
   :data.can_book
   ?'Toque num horário verde para cadastrar. Em Clientes agendados, aceite ou recuse pedidos pendentes; nos confirmados, você pode remarcar, concluir, cancelar ou registrar falta. Outros profissionais continuam restritos.'
   :'Agenda apenas para consulta. Solicite ao responsável principal a permissão para cadastrar clientes em seus horários livres.';
 }
 function renderRows(items,canBook){
  const dt=nowBrazil();
  const action=a=>{
   if(!canBook)return '<span class="restricted-muted">—</span>';
   if(a.status==='pending')return `<div class="barber-row-actions"><button class="barber-accept-action" type="button" data-request-id="${Number(a.id)}" data-request-decision="accept" aria-label="Aceitar solicitação de ${esc(a.client_name)}">✓ Aceitar</button><button class="barber-reject-action" type="button" data-request-id="${Number(a.id)}" data-request-decision="reject" aria-label="Recusar solicitação de ${esc(a.client_name)}">✕ Recusar</button></div>`;
   if(a.status!=='confirmed')return '<span class="restricted-muted">—</span>';
   const began=String(a.starts_at||'').replace(' ','T')<=dt;
   // Os botões não são bloqueados pela hora prevista: o barbeiro pode registrar
   // a execução real ou uma ausência antecipada com confirmação explícita.
   const complete=`<button class="restricted-complete barber-complete-action" type="button" data-complete-id="${Number(a.id)}" aria-label="Concluir atendimento de ${esc(a.client_name)}">✓ Concluir</button>`;
   const noShow=`<button class="barber-no-show-action" type="button" data-status-id="${Number(a.id)}" data-status-decision="no_show" aria-label="Registrar falta de ${esc(a.client_name)}">Falta</button>`;
   const hint=began?'':`<small class="barber-actions-locked-note" data-appointment-id="${Number(a.id)}">Antes das ${esc(time(a.starts_at))}: use somente após confirmar a situação real.</small>`;
   return `<div class="barber-row-actions barber-status-actions"><button class="restricted-outline barber-move-action" type="button" data-move-id="${Number(a.id)}" aria-label="Remarcar ${esc(a.client_name)}">↻ Remarcar</button>${complete}<button class="barber-cancel-action" type="button" data-status-id="${Number(a.id)}" data-status-decision="cancel" aria-label="Cancelar agendamento de ${esc(a.client_name)}">Cancelar</button>${noShow}${hint}</div>`;
  };
  list.innerHTML=items.length?`<div class="restricted-table-wrapper"><table class="restricted-table"><thead><tr><th>Horário</th><th>Cliente</th><th>Serviço</th><th>Situação</th><th>Ações</th></tr></thead><tbody>${items.map(a=>`<tr><td><strong>${esc(time(a.starts_at))}</strong><small>até ${esc(time(a.ends_at))}</small></td><td><strong>${esc(a.client_name)}</strong><small>${esc(a.client_phone||'')}</small></td><td>${esc(a.service_name)}</td><td><span class="restricted-chip">${esc(status[a.status]||a.status)}</span></td><td>${action(a)}</td></tr>`).join('')}</tbody></table></div>`:'<div class="restricted-empty">Nenhum cliente agendado nesta data.</div>';
 }
 // Atualiza avisos de horário e a data atual, sem bloquear os botões de ação.
 // O servidor permanece responsável por validar proprietário, situação e permissões.
 function updateTimedActions(){
  if(!current || finishing || updatingStatus || deciding)return;
  const now=nowBrazil();
  const today=now.slice(0,10);
  if(lastToday!==today){
   // Ao virar o dia, quem estava visualizando "hoje" acompanha o novo dia;
   // quem navegou para outra data permanece nela.
   if(selectedDate===lastToday){selectedDate=today;void load();}
   lastToday=today;
   return;
  }
  if(!current.can_book || current.date!==today)return;
  for(const a of (current.appointments||[])){
   if(a.status!=='confirmed' || String(a.starts_at||'').replace(' ','T')>now)continue;
   const id=Number(a.id);
   list.querySelector(`.barber-actions-locked-note[data-appointment-id="${id}"]`)?.remove();
  }
 }
 window.setInterval(updateTimedActions,15000);
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)updateTimedActions();});
 const dateForDisplay=date=>new Date(date+'T12:00:00Z');
 function updateDateNavigation(data){
  const day=dateForDisplay(data.date);
  dateTitle.textContent=day.toLocaleDateString('pt-BR',{day:'2-digit',month:'long',year:'numeric',timeZone:'UTC'});
  weekdayTitle.textContent=day.toLocaleDateString('pt-BR',{weekday:'long',timeZone:'UTC'});
  const isToday=data.date===data.today;
  resetDay.hidden=isToday;
  prevDay.disabled=data.date<=data.min_date;
  nextDay.disabled=data.date>=data.max_date;
  slotsHeading.textContent=isToday?'Horários de hoje':'Horários do dia';
  slotsHelp.textContent=isToday
   ?'Toque em um horário livre para cadastrar um cliente. Horários ocupados ou encerrados não podem ser escolhidos.'
   :'Visualização da agenda nesta data. Para cadastrar ou alterar atendimentos, volte para hoje.';
  if(!isToday && !data.slots?.length)slotsHelp.textContent+=' A grade de horários é exibida apenas para hoje e os próximos 30 dias.';
 }
 async function load(){
  if(isLoading)return;
  const version=++requestedVersion;
  const requestedDate=selectedDate;
  isLoading=true;
  const refresh=document.getElementById('barber-refresh');
  refresh.disabled=true;prevDay.disabled=true;nextDay.disabled=true;resetDay.disabled=true;
  alert.hidden=true;
  try{
   const data=await api('barber_day',null,requestedDate?{date:requestedDate}:{});
   if(version!==requestedVersion)return;
   const changedDay=current && current.date!==data.date;
   current=data;selectedDate=data.date;lastToday=data.today;
   if(changedDay){hideForm();hideMove();}
   else if(chosenTime && !(data.slots||[]).some(s=>s.time===chosenTime&&s.state==='available'))hideForm();
   updateDateNavigation(data);
   document.getElementById('barber-count').textContent=String(data.appointments.filter(a=>a.status!=='cancelled').length);
   renderRows(data.appointments,Boolean(data.can_book));
   renderSlots(data);
   // Se a reserva foi movida ou deixou de estar confirmada, feche o formulário antigo.
   if(moving && !data.appointments.some(a=>Number(a.id)===Number(moving.id)&&a.status==='confirmed'))hideMove();
  }catch(e){
   // Uma data que falhou ao carregar não pode ficar selecionada na próxima atualização.
   selectedDate=current?.date??null;
   alert.textContent=e.message;alert.hidden=false;
   if(/acesso|restrito|sessão/i.test(e.message))location.assign('/login.php');
  }finally{
   isLoading=false;refresh.disabled=false;resetDay.disabled=false;
   if(current)updateDateNavigation(current);
  }
 }
 function navigateTo(date){
  if(isLoading||!current||date<current.min_date||date>current.max_date||date===current.date)return;
  selectedDate=date;void load();
 }
 prevDay.addEventListener('click',()=>{if(current)navigateTo(addDays(current.date,-1));});
 nextDay.addEventListener('click',()=>{if(current)navigateTo(addDays(current.date,1));});
 resetDay.addEventListener('click',()=>{if(current)navigateTo(current.today);});
 list.addEventListener('click',async event=>{
  const decisionBtn=event.target.closest('button[data-request-id][data-request-decision]');
  if(decisionBtn){
   if(deciding||!current?.can_book)return;
   const id=Number(decisionBtn.dataset.requestId);
   const decision=decisionBtn.dataset.requestDecision;
   if(!['accept','reject'].includes(decision))return;
   const appointment=(current.appointments||[]).find(a=>Number(a.id)===id && a.status==='pending');
   if(!appointment)return;
   const verb=decision==='accept'?'aceitar e confirmar':'recusar e cancelar';
   if(!window.confirm(`Deseja ${verb} a solicitação de ${appointment.client_name}, às ${time(appointment.starts_at)}?${decision==='reject'?' O horário será liberado para novas reservas.':''}`))return;
   deciding=true;
   list.querySelectorAll('button[data-request-id]').forEach(button=>{button.disabled=true;});
   try{
    const result=await api('barber_day_decide',{id,decision});
    hideForm();hideMove();await load();
    alert.textContent=result.message||'Solicitação atualizada.';
    alert.hidden=false;
    const phone=String(appointment.client_phone||'').replace(/\D/g,'').replace(/^55(?=\d{10,11}$)/,'');
    if(/^\d{10,11}$/.test(phone)){
     const day=String(appointment.starts_at||'').slice(0,10).split('-').reverse().join('/');
     const notification=decision==='accept'
      ?`Olá, ${appointment.client_name}! Seu agendamento na Barbearia Martelli para ${day} às ${time(appointment.starts_at)} (${appointment.service_name}) foi confirmado. Até breve!`
      :`Olá, ${appointment.client_name}! Não foi possível confirmar seu agendamento na Barbearia Martelli para ${day} às ${time(appointment.starts_at)} (${appointment.service_name}). Entre em contato para consultar outra disponibilidade.`;
     const a=document.createElement('a');a.href=`https://wa.me/55${phone}?text=${encodeURIComponent(notification)}`;
     a.target='_blank';a.rel='noopener noreferrer';a.className='barber-notify-link';a.textContent='Avisar cliente pelo WhatsApp ↗';
     alert.append(' ',a);
    }
   }catch(err){alert.textContent=err.message;alert.hidden=false;await load().catch(()=>{});
    alert.textContent=err.message;alert.hidden=false;}
   finally{deciding=false;}
   return;
  }
  const statusBtn=event.target.closest('button[data-status-id][data-status-decision]');
  if(statusBtn){
   if(updatingStatus||statusBtn.disabled||!current?.can_book)return;
   const id=Number(statusBtn.dataset.statusId);
   const decision=statusBtn.dataset.statusDecision;
   if(!['cancel','no_show'].includes(decision))return;
   const appointment=(current.appointments||[]).find(a=>Number(a.id)===id&&a.status==='confirmed');
   if(!appointment)return;
   const isNoShow=decision==='no_show';
   const beforeStart=String(appointment.starts_at||'').replace(' ','T')>nowBrazil();
   const question=isNoShow
    ?`Registrar FALTA para ${appointment.client_name}, às ${time(appointment.starts_at)}?\n\n${beforeStart?'ATENÇÃO: o horário ainda não começou. Registre a falta antecipadamente somente se tiver certeza de que o cliente não comparecerá.\n\n':''}O histórico será mantido e a reserva deixará de ocupar o horário.`
    :`Cancelar o agendamento de ${appointment.client_name} às ${time(appointment.starts_at)}? O horário poderá ficar disponível para novas reservas e o histórico será mantido.`;
   if(!window.confirm(question))return;
   updatingStatus=true;statusBtn.disabled=true;
   try{
    // A confirmação é obrigatória mesmo se o relógio local estiver diferente do servidor.
    const response=await api('barber_day_status',{id,decision,early_confirm:isNoShow});
    hideForm();hideMove();await load();
    alert.textContent=response.message||'Agendamento atualizado.';alert.hidden=false;
    if(!isNoShow){
     const phone=String(appointment.client_phone||'').replace(/\D/g,'').replace(/^55(?=\d{10,11}$)/,'');
     if(/^\d{10,11}$/.test(phone)){
      const text=`Olá, ${appointment.client_name}! O agendamento na Barbearia Martelli de hoje às ${time(appointment.starts_at)} (${appointment.service_name}) foi cancelado. Para consultar outro horário, entre em contato conosco.`;
      const link=document.createElement('a');link.href=`https://wa.me/55${phone}?text=${encodeURIComponent(text)}`;
      link.target='_blank';link.rel='noopener noreferrer';link.className='barber-notify-link';link.textContent='Avisar cliente pelo WhatsApp ↗';
      alert.append(' ',link);
     }
    }
   }catch(err){await load().catch(()=>{});alert.textContent=err.message;alert.hidden=false;}
   finally{updatingStatus=false;}
   return;
  }
  const finishBtn=event.target.closest('button[data-complete-id]');
  if(finishBtn){
   if(finishing||!current?.can_book)return;
   const id=Number(finishBtn.dataset.completeId);
   const a=(current.appointments||[]).find(a=>Number(a.id)===id && a.status==='confirmed');
   if(!a)return;
   const beforeStart=String(a.starts_at||'').replace(' ','T')>nowBrazil();
   const warning=beforeStart?`\n\nATENÇÃO: a hora marcada (${time(a.starts_at)}) ainda não chegou. Confirme apenas se o atendimento realmente terminou; isso pode liberar a reserva antecipadamente.`:'';
   if(!window.confirm(`Concluir o atendimento de ${a.client_name}? O serviço será registrado como concluído e horários futuros ainda disponíveis poderão ser liberados.${warning}`))return;
   finishing=true;finishBtn.disabled=true;
   try{
    // Autoriza explicitamente o registro antecipado somente após confirmação humana.
    const result=await api('barber_day_complete',{id,early_confirm:true});
    hideMove();hideForm();await load();
    alert.textContent=result.appointment?.released_early
     ?'Atendimento concluído! Os próximos horários foram recalculados e poderão receber novas reservas, se houver tempo livre suficiente.'
     :'Atendimento concluído! A grade de horários foi atualizada.';
    alert.hidden=false;
   }catch(err){alert.textContent=err.message;alert.hidden=false;finishBtn.disabled=false;}
   finally{finishing=false;}
   return;
  }
  const btn=event.target.closest('button[data-move-id]');
  if(btn)beginMove(Number(btn.dataset.moveId));
 });
 moveDate.addEventListener('change',()=>{
  if(!moving)return;
  if(moveDate.value<moveDate.min||moveDate.value>moveDate.max){moveFeedback.textContent='Escolha uma data válida nos próximos 30 dias.';moveTime.disabled=true;moveSubmit.disabled=true;return;}
  void loadMoveSlots();
 });
 moveTime.addEventListener('change',()=>{moveSubmit.disabled=!moveTime.value||!moving;});
 document.getElementById('barber-reschedule-cancel').addEventListener('click',hideMove);
 moveForm.addEventListener('submit',async event=>{
  event.preventDefault();if(!moving||moveSubmit.disabled)return;
  const appointment=moving;
  const newDate=moveDate.value,newTime=moveTime.value;
  moveSubmit.disabled=true;moveTime.disabled=true;moveDate.disabled=true;
  moveFeedback.textContent='Salvando novo horário...';
  try{
   const response=await api('barber_day_reschedule',{id:Number(appointment.id),date:newDate,time:newTime});
   hideMove();await load();
   alert.hidden=false;
   alert.textContent=`${response.message||'Agendamento remarcado.'} ${newDate.split('-').reverse().join('/')} às ${newTime}.`;
   const phone=String(appointment.client_phone||'').replace(/\D/g,'').replace(/^55(?=\d{10,11}$)/,'');
   if(/^\d{10,11}$/.test(phone)){
    const text=`Olá, ${appointment.client_name}! Seu atendimento na Barbearia Martelli foi remarcado para ${newDate.split('-').reverse().join('/')} às ${newTime} (${appointment.service_name}). Pode confirmar o recebimento?`;
    const a=document.createElement('a');a.href=`https://wa.me/55${phone}?text=${encodeURIComponent(text)}`;
    a.target='_blank';a.rel='noopener noreferrer';a.className='barber-notify-link';a.textContent='Avisar cliente pelo WhatsApp ↗';
    alert.append(' ',a);
   }
  }catch(err){moveFeedback.textContent=err.message;moveDate.disabled=false;moveTime.disabled=false;moveSubmit.disabled=!moveTime.value;}
  finally{moveDate.disabled=false;}
 });
 grid.addEventListener('click',event=>{
  const button=event.target.closest('[data-barber-slot]');
  if(!button||!current?.can_book)return;
  const slot=current.slots.find(s=>s.time===button.dataset.barberSlot&&s.state==='available');
  if(!slot)return;
  const allowed=new Set((slot.service_ids||[]).map(Number));
  const services=(current.services||[]).filter(s=>allowed.has(Number(s.id)));
  if(!services.length)return;
  chosenTime=slot.time;
  document.getElementById('barber-booking-heading').textContent='Cadastrar cliente · '+chosenTime;
  form.elements.service_id.innerHTML=services.map(s=>`<option value="${Number(s.id)}">${esc(s.name)} · ${money(s.price)} · ${Number(s.duration_minutes)} min</option>`).join('');
  grid.querySelectorAll('[data-barber-slot]').forEach(b=>b.setAttribute('aria-pressed',String(b===button)));
  panel.hidden=false;panel.scrollIntoView({behavior:'smooth',block:'center'});
  form.elements.name.focus();
 });
 document.getElementById('barber-booking-cancel').addEventListener('click',hideForm);
 form.addEventListener('submit',async e=>{
  e.preventDefault();if(saving||!current?.can_book||!chosenTime)return;
  const slot=current.slots.find(s=>s.time===chosenTime&&s.state==='available');
  if(!slot || !(slot.service_ids||[]).map(Number).includes(Number(form.elements.service_id.value)))return;
  const submit=form.querySelector('[type="submit"]');saving=true;submit.disabled=true;alert.hidden=true;
  try{
   await api('barber_day_booking',{date:current.date,time:chosenTime,name:form.elements.name.value,
    phone:form.elements.phone.value,cpf:form.elements.cpf.value,service_id:Number(form.elements.service_id.value),notes:form.elements.notes.value});
   hideForm();await load();
   alert.textContent='Cliente cadastrado com sucesso. Horário atualizado.';alert.hidden=false;
  }catch(err){alert.textContent=err.message;alert.hidden=false;await load();}
  finally{saving=false;submit.disabled=false;}
 });
 document.getElementById('barber-refresh').addEventListener('click',load);
 document.getElementById('barber-logout').addEventListener('click',async()=>{
  try{await api('logout',{});location.assign('/login.php');}
  catch(e){alert.textContent=e.message;alert.hidden=false;}
 });
 load();
})();
