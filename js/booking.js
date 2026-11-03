'use strict';
(async()=>{
  const {api,escape:esc,money,note}=window.Martelli;
  const form=document.getElementById('booking-form'),alert=document.getElementById('booking-alert');
  const serviceEl=document.getElementById('choose-service'),barberEl=document.getElementById('choose-barber');
  const dateEl=document.getElementById('booking-date'),slotsEl=document.getElementById('slots-list');
  const summary=document.getElementById('booking-summary'),submit=document.getElementById('booking-submit'),message=document.getElementById('booking-message');
  const state={catalog:null,service:null,barber:null,date:'',time:'',slots:[],calendar:[],loading:false,sending:false,editingTime:true};
  const now=new Date();
  const formatYmd=d=>{const p=new Intl.DateTimeFormat('en-US',{timeZone:'America/Sao_Paulo',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(d);const o=Object.fromEntries(p.map(x=>[x.type,x.value]));return `${o.year}-${o.month}-${o.day}`};
  const today=formatYmd(now),maxDate=formatYmd(new Date(now.getTime()+30*86400000));dateEl.min=today;dateEl.max=maxDate;dateEl.value=today;state.date=today;
  const dayList=document.getElementById('booking-day-options'),monthLabel=document.getElementById('booking-month-label'),selectedDateLabel=document.getElementById('booking-selected-date');
  const prevWeek=document.getElementById('booking-prev-week'),nextWeek=document.getElementById('booking-next-week');
  const picker=document.getElementById('booking-time-picker'),picked=document.getElementById('booking-time-picked'),pickedLabel=document.getElementById('booking-time-picked-label'),changeTime=document.getElementById('booking-time-change');
  const extendedInfo=document.getElementById('booking-extended-info');
  const pickedHelper=document.getElementById('booking-picked-helper');
  const after18=time=>typeof time==='string' && time.slice(0,5)>='18:00';
  // Navegação guiada: só avança após uma escolha explícita do cliente.
  // O scroll respeita a opção de reduzir movimento e o cabeçalho fixo.
  const prefersReducedMotion=window.matchMedia('(prefers-reduced-motion: reduce)');
  const mobileViewport=window.matchMedia('(max-width: 760px)');
  function advanceTo(target){
    if(!target||!mobileViewport.matches)return;
    requestAnimationFrame(()=>requestAnimationFrame(()=>{
      target.scrollIntoView({behavior:prefersReducedMotion.matches?'auto':'smooth',block:'start'});
    }));
  }
  const slotsHeading=document.querySelector('.booking-slots-heading');
  const stepPanels = Array.from(form.querySelectorAll('.booking-fields > [data-booking-step]'));
  const progressLabel = document.getElementById('booking-progress-label');
  const progress = document.getElementById('booking-progress');
  const back = document.getElementById('booking-back');
  const slotFilter = document.getElementById('booking-slot-filter');
  const slotsLegend = document.querySelector('.booking-day-legend');
  const stepNames = ['Serviço', 'Barbeiro', 'Dia e horário', 'Dados e confirmação'];
  let activeStep = 1;
  let showUnavailable = false;

  function showStep(step, moveFocus = true) {
    activeStep = step;
    form.dataset.activeStep = String(step);
    progress.value = step;
    progressLabel.textContent = `Etapa ${step} de 4 · ${stepNames[step - 1]}`;
    back.hidden = step === 1;
    if (moveFocus && mobileViewport.matches) {
      const heading = stepPanels[step - 1].querySelector('h2');
      heading.tabIndex = -1;
      heading.focus({preventScroll: true});
      advanceTo(form);
    }
  }

  document.getElementById('booking-time-continue').addEventListener('click', () => showStep(4));
  back.addEventListener('click', () => showStep(activeStep - 1));
  slotFilter.addEventListener('click', () => {
    showUnavailable = !showUnavailable;
    updateSlotFilter();
  });
  function updateSlotFilter() {
    const hasUnavailable = Boolean(slotsEl.querySelector('.slot:disabled'));
    slotFilter.hidden = !mobileViewport.matches || !hasUnavailable;
    slotFilter.setAttribute('aria-pressed', String(showUnavailable));
    slotsEl.classList.toggle('booking-free-only', mobileViewport.matches && !showUnavailable);
    slotsLegend.classList.toggle('booking-legend-collapsed', mobileViewport.matches && !showUnavailable);
  }
  mobileViewport.addEventListener('change', updateSlotFilter);

  let weekOffset=0;
  const asUTC=ymd=>new Date(`${ymd}T12:00:00Z`);
  const addDays=(ymd,n)=>{const d=asUTC(ymd);d.setUTCDate(d.getUTCDate()+n);return d.toISOString().slice(0,10);};
  const dayDiff=(a,b)=>Math.round((asUTC(b)-asUTC(a))/86400000);
  const dateDisplay=ymd=>asUTC(ymd).toLocaleDateString('pt-BR',{timeZone:'UTC',day:'2-digit',month:'long'});
  const shortWeekday=ymd=>asUTC(ymd).toLocaleDateString('pt-BR',{timeZone:'UTC',weekday:'short'}).replace('.','');
  function dayIsOpen(ymd){const hours=state.catalog?.hours;if(!Array.isArray(hours)||!hours.length)return true;const weekday=asUTC(ymd).getUTCDay(),h=hours.find(x=>Number(x.weekday)===weekday);return Boolean(h&&Number(h.is_open));}
  function renderDayOptions(){
    const start=addDays(today,weekOffset*7);const dates=Array.from({length:7},(_,i)=>addDays(start,i)).filter(d=>d<=maxDate);
    prevWeek.disabled=weekOffset===0;nextWeek.disabled=addDays(today,(weekOffset+1)*7)>maxDate;
    monthLabel.textContent=dates.length?asUTC(dates[0]).toLocaleDateString('pt-BR',{timeZone:'UTC',month:'long',year:'numeric'}):'Escolha sua data';
    selectedDateLabel.textContent=state.date?`· ${dateDisplay(state.date)}`:'';
    dayList.innerHTML=dates.map(date=>{const open=dayIsOpen(date);const chosen=date===state.date;return `<button type="button" class="booking-day ${chosen?'is-selected':''}" data-booking-date="${date}" ${open?'':'disabled'} aria-pressed="${chosen}"><span class="booking-weekday">${esc(shortWeekday(date))}</span><strong>${date.slice(8)}</strong><span class="booking-day-hint">${!open?'Fechado':date===today?'Hoje':'Aberto'}</span></button>`;}).join('')||'<p class="text-muted">Sem dias nesta semana.</p>';
  }
  dayList.addEventListener('click',e=>{const target=e.target.closest('button[data-booking-date]');if(!target||target.disabled)return;state.date=target.dataset.bookingDate;dateEl.value=state.date;reloadSlots();advanceTo(slotsHeading);});
  prevWeek.addEventListener('click',()=>{weekOffset=Math.max(0,weekOffset-1);renderDayOptions();});
  nextWeek.addEventListener('click',()=>{if(addDays(today,(weekOffset+1)*7)<=maxDate)weekOffset++;renderDayOptions();});

  function render(){renderDayOptions();const cat=state.catalog;if(!cat)return;
    const collapsed=Boolean(state.time&&!state.editingTime);
    picker.hidden=collapsed;picked.hidden=!collapsed;
    if(collapsed){pickedLabel.textContent=`${dateDisplay(state.date)} · ${state.time.slice(0,5)}`;}
    if(collapsed){const symbol=picked.querySelector('.booking-picked-icon');if(symbol)symbol.textContent=after18(state.time)?'⌛':'✓';}
    if(pickedHelper)pickedHelper.textContent=after18(state.time)
      ?'Aguardando aprovação: o barbeiro precisa confirmar se pode atender após as 18h.'
      :'Agora preencha seus dados para confirmar a reserva.';
    if(extendedInfo)extendedInfo.hidden=!state.slots.some(slot=>after18(slot.time));
    serviceEl.innerHTML=cat.services.map(s=>`<button type="button" class="option ${String(s.name).includes('+')?'option-combo':''} ${state.service===String(s.id)?'selected':''}" data-service="${esc(s.id)}" aria-pressed="${state.service===String(s.id)}"><strong>${esc(s.name)}</strong>${String(s.name).includes('+')?'<span class="combo-chip">COMBO</span>':''}<span>${esc(s.duration_minutes)} min · ${money(s.price)}</span></button>`).join('');
    barberEl.innerHTML=cat.barbers.map(b=>`<button type="button" class="option ${state.barber===String(b.id)?'selected':''}" data-barber="${esc(b.id)}" aria-pressed="${state.barber===String(b.id)}"><strong>${esc(b.name)}</strong><span>${esc(b.bio||'Profissional')}</span></button>`).join('');
    const s=cat.services.find(x=>String(x.id)===state.service),b=cat.barbers.find(x=>String(x.id)===state.barber);
    if(state.loading)slotsEl.innerHTML='<p class="small text-muted">Consultando disponibilidade...</p>';
    else if(state.date&&s&&b){
      const source=state.calendar.length?state.calendar:state.slots.map(slot=>({...slot,state:'available'}));
      const available=new Map(state.slots.map(slot=>[slot.time,slot]));
      slotsEl.innerHTML=source.length?source.map(sl=>{
        const free=sl.state==='available'&&available.has(sl.time);
        const real=available.get(sl.time);
        const text=free?(after18(sl.time)?'sob consulta':real?.extended?'estendido':'Livre'):
          sl.state==='occupied'?'Ocupado':sl.state==='past'?'Encerrado':'Indisponível';
        return `<button type="button" class="slot booking-slot-${free?'free':esc(sl.state)} ${state.time===sl.time?'selected':''}" aria-pressed="${state.time===sl.time}" ${free?`data-time="${esc(sl.time)}"`:'disabled'}><strong>${esc(sl.time)}</strong><small>${esc(text)}</small></button>`;
      }).join(''):'<p class="small text-muted">Nenhum horário disponível nesta data.</p>';
    }
    else slotsEl.innerHTML='<p class="small text-muted">Selecione serviço, barbeiro e data.</p>';
    if (!state.loading && slotsEl.querySelector('.slot:disabled') && !state.slots.length) {
      slotsEl.insertAdjacentHTML('afterbegin', '<p class="small text-muted booking-no-free">Sem horários livres neste dia. Escolha outra data acima.</p>');
    }
    updateSlotFilter();
    const approval=after18(state.time);
    const items=[['Serviço',s?s.name:'—'],['Profissional',b?b.name:'—'],['Data',state.date?state.date.split('-').reverse().join('/'):'—'],['Horário',state.time||'—'],['Valor',s?money(s.price):'—']];
    if(state.time)items.push(['Situação',approval?'Aguardando aprovação do barbeiro':'Confirmação imediata']);
    summary.innerHTML=items.map(([k,v])=>`<div class="summary-line"><span>${esc(k)}</span><span>${esc(v)}</span></div>`).join('');
    const ready=Boolean(cat.bookings_enabled&&s&&b&&state.date&&state.time&&!state.loading&&!state.sending&&form.elements.privacy_ack.checked);
    submit.disabled=!ready;submit.setAttribute('aria-busy',String(state.sending));
    submit.textContent=approval?'Solicitar horário ao barbeiro ↗':'Confirmar agendamento ↗';
    message.textContent=!cat.bookings_enabled?'Agendamentos online estão desativados. Consulte a barbearia.':approval
      ?'Horários a partir das 18h NÃO são confirmados automaticamente. A solicitação ficará pendente até a aprovação do barbeiro.'
      :'Confirme a política de privacidade, seus dados e o horário para reservar.';
  }
  let requestId=0;
  async function reloadSlots(){const ticket=++requestId;state.time='';state.editingTime=true;state.slots=[];state.calendar=[];state.loading=!!(state.service&&state.barber&&state.date);showUnavailable=false;render();
    if(!state.loading)return;
    try{const r=await api('slots',null,{date:state.date,barber_id:state.barber,service_id:state.service});if(ticket!==requestId)return;state.slots=r.slots||[];state.calendar=r.calendar||[];}
    catch(e){if(ticket!==requestId)return;note(alert,e.message,true);state.slots=[];state.calendar=[];}
    finally{if(ticket===requestId){state.loading=false;render();}}
  }
  serviceEl.addEventListener('click',e=>{const el=e.target.closest('[data-service]');if(!el)return;if(state.service!==el.dataset.service){state.service=el.dataset.service;reloadSlots();}showStep(2);});
  barberEl.addEventListener('click',e=>{const el=e.target.closest('[data-barber]');if(!el)return;if(state.barber!==el.dataset.barber){state.barber=el.dataset.barber;reloadSlots();}showStep(state.service ? 3 : 1);});
  dateEl.addEventListener('change',()=>{if(dateEl.value<today||dateEl.value>maxDate){dateEl.value=state.date;return;}state.date=dateEl.value;weekOffset=Math.max(0,Math.floor(dayDiff(today,state.date)/7));reloadSlots();advanceTo(slotsHeading);});
  form.elements.privacy_ack.addEventListener('change',render);
  slotsEl.addEventListener('click',e=>{const el=e.target.closest('[data-time]');if(!el||el.disabled||state.loading)return;state.time=el.dataset.time;state.editingTime=false;render();
    showStep(4);
  });
  changeTime.addEventListener('click',()=>{state.editingTime=true;render();advanceTo(picker);});
  try{const data=await api('bootstrap');state.catalog=data;state.service= new URLSearchParams(location.search).get('servico');if(!data.services.some(s=>String(s.id)===state.service))state.service=null;state.barber=new URLSearchParams(location.search).get('barbeiro');if(!data.barbers.some(b=>String(b.id)===state.barber))state.barber=null;if(!dayIsOpen(state.date)){for(let i=1;i<=30;i++){const d=addDays(today,i);if(d<=maxDate&&dayIsOpen(d)){state.date=d;dateEl.value=d;weekOffset=Math.floor(i/7);break;}}}showStep(state.service ? (state.barber ? 3 : 2) : 1, false);render();if(state.service&&state.barber)reloadSlots();}
  catch(e){note(alert,e.message,true);submit.disabled=true;}
  const clientPhone=form.elements.phone;
  const rememberKey='martelli-client-v1';
  document.getElementById('booking-client-panel').insertAdjacentHTML('beforeend','<div style="margin-top:1rem"><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" id="remember-client" style="width:auto"> Lembrar meu nome e WhatsApp neste aparelho</label><small class="text-muted">Use apenas em um aparelho pessoal. Os dados ficam salvos neste navegador.</small><button type="button" id="forget-client" class="button button-outline button-small" hidden>Esquecer meus dados</button><p id="remember-client-status" class="small text-muted" role="status"></p></div>');
  const remember=document.getElementById('remember-client'),forget=document.getElementById('forget-client'),rememberStatus=document.getElementById('remember-client-status');
  const forgetClient=()=>{try{localStorage.removeItem(rememberKey);}catch(_){}remember.checked=false;forget.hidden=true;};
  try{
    const saved=JSON.parse(localStorage.getItem(rememberKey)||'null');
    if(saved&&saved.expires>Date.now()&&typeof saved.name==='string'&&saved.name.length<=120&&window.MartelliPhone.toLocal(saved.phone)){
      form.elements.name.value=saved.name;clientPhone.value=saved.phone;remember.checked=true;forget.hidden=false;
      rememberStatus.textContent='Dados preenchidos deste aparelho. Confira antes de confirmar.';
    }else if(saved){forgetClient();}
  }catch(_){forgetClient();}
  remember.addEventListener('change',()=>{if(!remember.checked){forgetClient();rememberStatus.textContent='Dados salvos removidos deste aparelho.';}});
  forget.addEventListener('click',()=>{forgetClient();form.elements.name.value='';clientPhone.value='';clientPhone.setCustomValidity('');rememberStatus.textContent='Dados removidos deste aparelho.';});

  clientPhone.addEventListener('input',()=>clientPhone.setCustomValidity(''));
  form.addEventListener('submit',async e=>{
    e.preventDefault();if(state.sending||!state.catalog||!state.time||!state.date||!form.elements.privacy_ack.checked)return;
    const localPhone=window.MartelliPhone.validateInput(clientPhone);
    if(!localPhone)return;
    state.sending=true;submit.disabled=true;render();
    const payload={name:form.elements.name.value,phone:localPhone,cpf:form.elements.cpf.value,notes:form.elements.notes.value,
      website:form.elements.website.value,privacy_ack:form.elements.privacy_ack.checked,date:state.date,time:state.time,service_id:Number(state.service),barber_id:Number(state.barber)};
    try{const r=await api('book',payload);
      if(remember.checked){try{localStorage.setItem(rememberKey,JSON.stringify({name:payload.name,phone:localPhone,expires:Date.now()+180*86400000}));}catch(_){}}

      form.hidden=true;alert.hidden=true;const success=document.getElementById('booking-success');success.hidden=false;
      const needsApproval=r.appointment.status==='pending' && r.appointment.requires_approval===true;
      const successTitle=document.getElementById('booking-success-title');
      successTitle.textContent=needsApproval?'Solicitação enviada ao sistema!':'Horário confirmado!';
      success.querySelector('.success-symbol').textContent=needsApproval?'⌛':'✓';
      document.getElementById('success-description').textContent=`${needsApproval?'Solicitação':'Reserva'} #${r.appointment.id} — ${state.date.split('-').reverse().join('/')} às ${state.time}. ${r.appointment.service} (${money(r.appointment.price)}). ${needsApproval?'Atendimento NÃO confirmado: peça a aprovação do barbeiro pelo WhatsApp.':'Agendamento confirmado.'}`;
      const link=document.getElementById('success-whatsapp');
      const hint=document.getElementById('success-whatsapp-hint');
      const appointment=r.appointment;
      const tel=String(appointment.whatsapp||'').replace(/\D/g,'');
      const barber=String(appointment.barber_name||state.catalog.barbers.find(x=>String(x.id)===state.barber)?.name||'').trim();
      const professional=appointment.whatsapp_recipient==='barber';
      if(tel && /^55\d{10,11}$/.test(tel)){
        const text=needsApproval
          ?window.MartelliWhatsapp.buildApprovalRequestMessage(appointment, {
            clientName:payload.name,barberName:barber,
            recipient:professional?'barber':'business',money
          })
          :window.MartelliWhatsapp.buildConfirmationMessage(appointment, {
            clientName:payload.name,barberName:barber,
            recipient:professional?'barber':'business',money
          });
        link.href=window.MartelliWhatsapp.buildWhatsAppUrl(tel, text);
        link.setAttribute('aria-label',needsApproval?'Abrir WhatsApp com pedido de aprovação do horário':'Abrir WhatsApp com detalhes do horário confirmado');
        link.textContent=needsApproval
          ?(professional?'Pedir aprovação ao barbeiro no WhatsApp ↗':'Consultar disponibilidade pelo WhatsApp ↗')
          :(professional?'Enviar confirmação ao barbeiro pelo WhatsApp ↗':'Enviar confirmação à barbearia pelo WhatsApp ↗');
        link.hidden=false;
        hint.textContent=needsApproval
          ?'Sua solicitação foi salva como PENDENTE. Toque no botão e confirme o envio no WhatsApp; aguarde o barbeiro aprovar o atendimento.'
          :'Seu horário já está confirmado. Toque no botão e depois em Enviar no WhatsApp para compartilhar os detalhes.';
        hint.hidden=false;
      }else{
        link.hidden=true;
        link.removeAttribute('href');
        hint.textContent=needsApproval
          ?'Solicitação PENDENTE. Não há WhatsApp cadastrado para enviar a consulta. Entre em contato com a barbearia; o administrador também pode aprovar pelo painel.'
          :'A reserva está confirmada. Para compartilhar pelo WhatsApp, a administração precisa cadastrar o contato do barbeiro ou da barbearia.';
        hint.hidden=false;
      }
      success.scrollIntoView({behavior:'smooth',block:'start'});
      if(needsApproval){
        const popup=document.getElementById('booking-consult-dialog');
        const popupLink=document.getElementById('booking-consult-whatsapp');
        const close=document.getElementById('booking-consult-close');
        if(popup){
          if(!link.hidden && link.href){popupLink.href=link.href;popupLink.hidden=false;}
          else{popupLink.hidden=true;popupLink.removeAttribute('href');}
          document.getElementById('booking-consult-description').innerHTML=popupLink.hidden
            ?'Sua solicitação está <strong>pendente e ainda não está confirmada</strong>. Não existe um WhatsApp cadastrado para este profissional. Entre em contato diretamente com a barbearia e aguarde a aprovação.'
            :`Sua solicitação foi registrada, mas <strong>o horário ainda não está confirmado</strong>. Toque no botão abaixo para consultar a disponibilidade com ${professional?'o barbeiro':'a barbearia'}. Aguarde uma resposta antes de considerar o horário confirmado.`;
          close.onclick=()=>popup.close();
          popup.onclick=event=>{if(event.target===popup)popup.close();};
          if(typeof popup.showModal==='function')popup.showModal();
        }
      }
    }catch(err){
      note(alert,err.message,true);
      if(err.code==='vip_active_login_required'||err.code==='contact_verification_required'){
        // Acesso a dados VIP requer login; jamais coloca CPF/telefone na URL.
        const link=document.createElement('a');
        link.href=err.loginUrl||'/vip-entrar.php';
        link.className='booking-vip-login-link';
        link.textContent=err.code==='vip_active_login_required'
          ?'Entrar no Login e ver meus cortes VIP ↗'
          :'Entrar para confirmar meu WhatsApp ↗';
        alert.appendChild(link);
      }
      showStep(3, false);
      await reloadSlots();alert.scrollIntoView({behavior:prefersReducedMotion.matches?'auto':'smooth'});
    }finally{state.sending=false;if(!form.hidden)render();}
  });
})();
