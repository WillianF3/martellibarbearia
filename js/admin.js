'use strict';
(async()=>{
  const {api,escape:esc,money,weekdays,note}=window.Martelli;
  const view=document.getElementById('admin-view'),message=document.getElementById('admin-message'),nav=document.getElementById('admin-nav');
  const modal=document.getElementById('edit-dialog'),form=document.getElementById('edit-form'),fields=document.getElementById('dialog-fields');
  const headings={agenda:'Agenda',dia:'Agendamentos do dia',todos:'Todos os agendamentos',clientes:'Clientes',servicos:'Serviços',barbeiros:'Barbeiros',horarios:'Horários',configuracoes:'Configurações',administradores:'Administradores'};
  const options=(list,current)=>list.map(x=>`<option value="${esc(x.id)}" ${String(x.id)===String(current)?'selected':''}>${esc(x.name)}</option>`).join('');
  let store=null,adminUsers=null,tab='agenda',modalEntity='',modalId=0,filterStatus='',periodFilter='upcoming',searchTerm='';
  let commissionReport=null,commissionReportLoading=false,commissionReportSeq=0;
  const reportFilter={barber_id:'',from:'',to:'',page:1};
  const maySeeCommissions=Boolean((window.MARTELLI_PERMISSIONS||[]).includes('financeiro') && window.MARTELLI_FEATURES?.comissoes);
  const commissionMoney=c=>(Number(c||0)/100).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
  let chosenDay='',quickDay='',dayItems=[],daySlots={},dayLoading=false,dayBarber='',dayQuerySeq=0,rescheduleSeq=0,allItems=[],allPage=1,allTotal=0,allPages=1,allQuerySeq=0;
  const formatYmd=d=>{const p=new Intl.DateTimeFormat('en-US',{timeZone:'America/Sao_Paulo',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(d);const v=Object.fromEntries(p.map(x=>[x.type,x.value]));return `${v.year}-${v.month}-${v.day}`;};
  if(!(window.MARTELLI_PERMISSIONS||[]).includes('agenda'))tab=(window.MARTELLI_PERMISSIONS||[]).includes('cadastros')?'clientes':((window.MARTELLI_PERMISSIONS||[]).includes('horarios')?'horarios':'configuracoes');
  reportFilter.to=formatYmd(new Date());reportFilter.from=reportFilter.to.slice(0,7)+'-01';
  chosenDay=formatYmd(new Date());
  quickDay=chosenDay;
  const actionBtn=(entity,id)=>`<button type="button" data-edit="${entity}" data-id="${id}">Editar</button>`;
  const statusText={pending:'Aguardando aprovação',confirmed:'Confirmado',completed:'Concluído',cancelled:'Cancelado',no_show:'Não compareceu'};
  function setTab(t){if(!Object.hasOwn(headings,t)||!window.MartelliAllowedTab?.(t))return;tab=t;if(location.hash.slice(1)!==t)history.replaceState(null,'','#'+t);document.getElementById('tab-heading').textContent=headings[t];nav.querySelectorAll('button').forEach(b=>b.classList.toggle('active',b.dataset.tab===tab));const mobileNav=document.getElementById('admin-mobile-nav');if(mobileNav)mobileNav.value=tab;if(tab==='administradores')void loadAdmins();else if(tab==='dia'){renderDay();void loadDay();}else if(tab==='todos'){renderAll();void loadAll();}else render();}
  async function loadAdmins(){try{adminUsers=await api('admin_users');renderAdmins();}catch(e){note(message,e.message,true);view.innerHTML='<p class="error-banner">Não foi possível carregar os administradores.</p>';}}
  async function load(){try{store=await api('admin_dashboard');if(tab==='dia'){renderDay();await loadDay();}else if(tab==='todos'){renderAll();await loadAll();}else render();}catch(e){note(message,e.message,true);view.innerHTML='<p class="error-banner">Falha ao consultar o painel. Verifique a API e o banco de dados.</p>';}}
  const t=(head,rows)=>`<div class="table-scroll"><table class="data-table"><thead><tr>${head.map(x=>`<th scope="col">${esc(x)}</th>`).join('')}</tr></thead><tbody>${rows||`<tr><td colspan="${head.length}" class="empty">Nenhum registro encontrado.</td></tr>`}</tbody></table></div>`;
  function render(){if(!store)return;
    if(tab==='agenda')return renderAgenda();
    if(tab==='dia')return renderDay();
    if(tab==='todos')return renderAll();
    if(tab==='clientes')return renderClients();
    if(tab==='servicos')return renderCatalog('services');
    if(tab==='barbeiros')return renderCatalog('barbers');
    if(tab==='horarios')return renderHours();
    if(tab==='configuracoes')return renderSettings();
    if(tab==='administradores')return adminUsers?renderAdmins():void loadAdmins();
  }
  const formatAppointmentDate=value=>{const d=String(value||'');const parts=d.slice(0,10).split('-');return parts.length===3?`${parts[2]}/${parts[1]}/${parts[0]}`:d;};
  const appointmentActions=a=>{
    const id=Number(a.id);const buttons=[];
    if(a.status==='pending'){
      buttons.push(`<button type="button" class="appointment-action is-success" data-status="confirmed" data-id="${id}" aria-label="Aprovar atendimento ${id}">✓ Aprovar</button>`);
      buttons.push(`<button type="button" class="appointment-action is-danger" data-status="cancelled" data-id="${id}" aria-label="Recusar atendimento ${id}">Recusar</button>`);
    } else if(a.status==='confirmed'){
      buttons.push(`<button type="button" class="appointment-action is-reschedule" data-reschedule="${id}" aria-label="Remarcar data e horário da reserva ${id}">↻ Remarcar</button>`);
      buttons.push(`<button type="button" class="appointment-action is-success" data-status="completed" data-id="${id}" aria-label="Concluir agendamento ${id}">Concluir</button>`);
      buttons.push(`<button type="button" class="appointment-action is-danger" data-status="cancelled" data-id="${id}" aria-label="Cancelar agendamento ${id}">Cancelar</button>`);
      buttons.push(`<button type="button" class="appointment-action" data-status="no_show" data-id="${id}" aria-label="Marcar falta para agendamento ${id}">Falta</button>`);
    } else if(a.status==='cancelled'){
      buttons.push(`<button type="button" class="appointment-action" data-status="confirmed" data-id="${id}" aria-label="Reativar agendamento ${id}">Reativar</button>`);
    }
    if(['confirmed','completed'].includes(a.status))buttons.push('<a class="button button-outline" href="/painel.php?appointment_id='+id+'#comandas">Comanda</a>');
    return buttons.length?`<div class="appointment-actions">${buttons.join('')}</div>`:'<span class="text-muted">Sem ações pendentes</span>';
  };
  const appointmentRows=records=>records.map(a=>`<tr><td><strong>${formatAppointmentDate(a.starts_at)}</strong><br><small>${esc(String(a.starts_at||'').slice(11,16))} · #${Number(a.id)}</small><br><button type="button" class="day-inline-link" data-open-day="${esc(String(a.starts_at||'').slice(0,10))}">Ver dia</button></td><td><strong>${esc(a.client_name)}</strong><br><small>${esc(a.client_phone)}</small></td><td>${esc(a.service_name)}</td><td>${esc(a.barber_name)}</td><td>${money(a.price)}</td><td><span class="status status-${esc(a.status)}">${esc(statusText[a.status]||a.status)}</span></td><td>${appointmentActions(a)}</td></tr>`).join('');
  const appointmentCards=records=>records.map(a=>`<article class="appointment-card"><div class="appointment-card-head"><div><span class="appointment-card-date">${formatAppointmentDate(a.starts_at)} · ${esc(String(a.starts_at||'').slice(11,16))}</span><small>Agendamento #${Number(a.id)}</small><button type="button" class="day-inline-link" data-open-day="${esc(String(a.starts_at||'').slice(0,10))}">Ver dia</button></div><span class="status status-${esc(a.status)}">${esc(statusText[a.status]||a.status)}</span></div><h3>${esc(a.client_name)}</h3><p class="appointment-card-phone">${esc(a.client_phone)}</p><dl><div><dt>Serviço</dt><dd>${esc(a.service_name)}</dd></div><div><dt>Barbeiro</dt><dd>${esc(a.barber_name)}</dd></div><div><dt>Valor</dt><dd>${money(a.price)}</dd></div></dl>${appointmentActions(a)}</article>`).join('');
  function filteredAppointments(){const all=store?.appointments||[];const today=formatYmd(new Date());const seven=formatYmd(new Date(Date.now()+7*86400000));const search=searchTerm.trim().toLocaleLowerCase('pt-BR');
    return all.filter(a=>{
      const d=String(a.starts_at||'').slice(0,10);
      if(filterStatus&&a.status!==filterStatus)return false;
      if(periodFilter==='today'&&d!==today)return false;
      if(periodFilter==='week'&&(d<today||d>seven))return false;
      if(periodFilter==='upcoming'&&(d<today||!['confirmed','pending'].includes(a.status)))return false;
      return !search||[a.client_name,a.client_phone,a.service_name,a.barber_name,String(a.id)].some(v=>String(v||'').toLocaleLowerCase('pt-BR').includes(search));
    }).sort((a,b)=>periodFilter==='upcoming'||periodFilter==='week'||periodFilter==='today'?String(a.starts_at).localeCompare(String(b.starts_at)):String(b.starts_at).localeCompare(String(a.starts_at)));
  }
  function paintAgendaResults(){if(!store)return;const items=filteredAppointments();const count=view.querySelector('#agenda-filter-count'),tbody=view.querySelector('#agenda-table-body'),cards=view.querySelector('#agenda-mobile-list');if(count)count.textContent=`${items.length} agendamento${items.length===1?'':'s'}`;if(tbody)tbody.innerHTML=appointmentRows(items)||'<tr><td colspan="7" class="empty">Nenhum agendamento encontrado para os filtros atuais.</td></tr>';if(cards)cards.innerHTML=appointmentCards(items)||'<p class="empty">Nenhum agendamento encontrado. Tente outro período ou filtro.</p>';}
  const shiftDay=(date,days)=>{const d=new Date(`${date}T12:00:00Z`);d.setUTCDate(d.getUTCDate()+days);return d.toISOString().slice(0,10);};
  const readableDay=date=>new Intl.DateTimeFormat('pt-BR',{timeZone:'UTC',weekday:'long',day:'2-digit',month:'long',year:'numeric'}).format(new Date(`${date}T12:00:00Z`));
  const validDay=date=>/^\d{4}-\d{2}-\d{2}$/.test(date)&&!Number.isNaN(new Date(`${date}T12:00:00Z`).getTime())&&new Date(`${date}T12:00:00Z`).toISOString().slice(0,10)===date;
  function showDay(date){
    if(!validDay(date)){note(message,'Escolha uma data válida.',true);return;}
    chosenDay=date;
    setTab('dia');
    view.scrollIntoView({behavior:'smooth',block:'start'});
  }
  function renderAll(){
    if(!store)return;
    view.innerHTML=`<section class="admin-section all-bookings-section" aria-labelledby="all-title"><div class="agenda-section-head"><div><span class="eyebrow">Histórico completo</span><h2 id="all-title">Todos os agendamentos</h2><p class="text-muted">Histórico de todas as datas, com 100 registros por página.</p></div><button type="button" class="button button-outline button-small" id="all-refresh">Atualizar ↻</button></div><div id="all-bookings-results" aria-live="polite" aria-busy="true">Carregando histórico...</div></section>`;
  }
  async function loadAll(){
    if(tab!=='todos')return;
    const seq=++allQuerySeq, target=view.querySelector('#all-bookings-results');
    if(target){target.setAttribute('aria-busy','true');target.textContent='Carregando agendamentos...';}
    try{
      const result=await api('admin_appointments',null,{page:allPage});
      if(seq!==allQuerySeq||tab!=='todos')return;
      allItems=Array.isArray(result.appointments)?result.appointments:[];
      allPage=Number(result.page)||1;allPages=Number(result.pages)||1;allTotal=Number(result.total)||0;
      paintAll();
    }catch(err){
      if(seq!==allQuerySeq||tab!=='todos')return;
      if(target){target.setAttribute('aria-busy','false');target.innerHTML=`<p class="error-banner" role="alert">${esc(err.message)}</p>`;}
    }
  }
  function paintAll(){
    const target=view.querySelector('#all-bookings-results');if(!target)return;
    target.setAttribute('aria-busy','false');
    const first=allTotal===0?0:(allPage-1)*100+1,last=Math.min(allPage*100,allTotal);
    target.innerHTML=`<p class="all-count">Exibindo ${first}–${last} de <strong>${allTotal}</strong> agendamentos</p>
      <div class="agenda-desktop-table">${t(['Data e hora','Cliente','Serviço','Barbeiro','Valor','Status','Ações'],appointmentRows(allItems))}</div>
      <div class="agenda-mobile-cards">${appointmentCards(allItems)||'<p class="empty">Nenhum agendamento encontrado.</p>'}</div>
      <nav class="all-pagination" aria-label="Navegar pelo histórico"><button type="button" class="button button-outline" id="all-prev" ${allPage<=1?'disabled':''}>← Anterior</button><span>Página ${allPage} de ${allPages}</span><button type="button" class="button button-outline" id="all-next" ${allPage>=allPages?'disabled':''}>Próxima →</button></nav>`;
  }
  function renderDay(){
    if(!store)return;
    const opts=store.barbers.map(b=>`<option value="${Number(b.id)}" ${String(b.id)===dayBarber?'selected':''}>${esc(b.name)}</option>`).join('');
    view.innerHTML=`<section class="admin-section day-panel" aria-labelledby="day-panel-title">
      <div class="day-panel-head"><div><span class="eyebrow">Agenda por data</span><h2 id="day-panel-title">Todos os agendamentos do dia</h2><p class="text-muted">Consulte qualquer dia e confira os clientes e horários marcados, incluindo concluídos e cancelados.</p></div><button type="button" class="button button-outline button-small" id="day-go-today">Hoje</button></div>
      <div class="day-picker-row"><button type="button" class="day-chevron" id="day-prev" aria-label="Ver dia anterior">‹</button><label for="admin-day-date">Escolha a data<input type="date" id="admin-day-date" value="${esc(chosenDay)}" min="2000-01-01" max="2100-12-31"></label><button type="button" class="day-chevron" id="day-next" aria-label="Ver próximo dia">›</button><button type="button" class="button button-gold" id="day-open">Ver agendamentos</button><button type="button" class="button button-outline" id="day-open-all">Ver todos os agendamentos</button></div>
      <div id="day-results" aria-live="polite" aria-busy="true"><p class="text-muted">Carregando agendamentos...</p></div>
    </section>`;
  }
  async function loadDay(){
    if(tab!=='dia')return;
    const seq=++dayQuerySeq;const target=view.querySelector('#day-results');
    if(target){target.setAttribute('aria-busy','true');target.innerHTML='<p class="text-muted">Consultando todos os agendamentos desta data...</p>';}
    dayLoading=true;
    try {
      const result=await api('admin_day',null,{date:chosenDay});
      if(seq!==dayQuerySeq||tab!=='dia')return;
      dayItems=Array.isArray(result.appointments)?result.appointments:[];
      daySlots=result.slots_by_barber&&typeof result.slots_by_barber==='object'?result.slots_by_barber:{};
      paintDay();
    }catch(err){if(seq!==dayQuerySeq||tab!=='dia')return;const el=view.querySelector('#day-results');if(el){el.setAttribute('aria-busy','false');el.innerHTML=`<p class="error-banner" role="alert">${esc(err.message)}</p>`;}}
    finally{if(seq===dayQuerySeq)dayLoading=false;}
  }
  function renderMainBarberGrids(){
    const barbers=(store.barbers||[]).filter(b=>String(b.active)==='1'&&(!dayBarber||String(b.id)===dayBarber));
    if(!barbers.length)return '';
    return `<section class="main-day-calendars" aria-label="Horários de cada barbeiro">
      <h3 class="day-list-title">Horários disponíveis e ocupados por barbeiro</h3>
      <p class="text-muted">Agendas separadas por profissional. Verde: livre para cadastrar · Vermelho: ocupado · Cinza: horário encerrado ou indisponível.</p>
      ${barbers.map(b=>{
        const slots=Array.isArray(daySlots[String(b.id)])?daySlots[String(b.id)]:[];
        const count=dayItems.filter(a=>Number(a.barber_id)===Number(b.id)&&a.status!=='cancelled').length;
        return `<details class="main-day-barber"><summary class="main-day-barber-heading" aria-label="Expandir ou recolher horários de ${esc(b.name)}"><span class="main-day-barber-name">${esc(b.name)}</span><span class="main-day-barber-meta"><span>${count} agendamento${count===1?'':'s'}</span><span class="main-day-barber-hint"><span class="when-closed">Mostrar horários</span><span class="when-open">Ocultar horários</span></span></span></summary>
        <div class="main-day-barber-body">${slots.length?`<div class="staff-slots-grid">${slots.map(slot=>{
          const state=['available','occupied','past','unavailable'].includes(slot.state)?slot.state:'unavailable';
          const labels={available:'Livre · Cadastrar',occupied:'Ocupado',past:'Encerrado',unavailable:'Indisponível'};
          return `<button type="button" class="staff-slot staff-slot-${state}" ${state==='available'?`data-main-slot="${esc(slot.time)}" data-main-barber="${Number(b.id)}"`:'disabled'} aria-label="${esc(b.name)} · ${esc(slot.time)} · ${labels[state]}"><strong>${esc(slot.time)}</strong><small>${labels[state]}</small></button>`;
        }).join('')}</div>`:`<p class="text-muted">${chosenDay<formatYmd(new Date())?'Os horários antigos não estão disponíveis para novos cadastros.':'Sem horários disponíveis nesta data (ou fora dos próximos 30 dias).'}</p>`}
        </div></details>`;
      }).join('')}
    </section>`;
  }
  function paintDay(){
    const target=view.querySelector('#day-results');if(!target)return;
    target.setAttribute('aria-busy','false');
    const filtered=dayBarber?dayItems.filter(a=>String(a.barber_id)===dayBarber):dayItems;
    const pending=filtered.filter(a=>a.status==='pending').length;
    const confirmed=filtered.filter(a=>a.status==='confirmed').length;
    const completed=filtered.filter(a=>a.status==='completed').length;
    const cancelled=filtered.filter(a=>a.status==='cancelled').length;
    const missing=filtered.filter(a=>a.status==='no_show').length;
    const revenue=filtered.filter(a=>a.status==='confirmed'||a.status==='completed').reduce((sum,a)=>sum+Number(a.price||0),0);
    target.innerHTML=`<div class="day-overview"><div><span class="eyebrow">${esc(chosenDay===formatYmd(new Date())?'Hoje':'Data selecionada')}</span><h3>${esc(readableDay(chosenDay))}</h3><p class="text-muted">${filtered.length} agendamento${filtered.length===1?'':'s'} nesta seleção</p></div><label for="day-barber-filter">Barbeiro<select id="day-barber-filter"><option value="">Todos os profissionais</option>${store.barbers.map(b=>`<option value="${Number(b.id)}" ${String(b.id)===dayBarber?'selected':''}>${esc(b.name)}</option>`).join('')}</select></label></div>
      <div class="day-totals" aria-label="Resumo do dia"><div><strong>${pending}</strong><span>Aguardando aprovação</span></div><div><strong>${confirmed}</strong><span>Confirmados</span></div><div><strong>${completed}</strong><span>Concluídos</span></div><div><strong>${cancelled}</strong><span>Cancelados</span></div><div><strong>${missing}</strong><span>Faltas</span></div><div><strong>${money(revenue)}</strong><span>Valor previsto + concluído</span></div></div>
      ${renderMainBarberGrids()}
      ${filtered.length?`<h3 class="day-list-title">Clientes agendados, por horário</h3><div class="day-appointments">${filtered.map(a=>`<article class="day-entry"><div class="day-time"><strong>${esc(String(a.starts_at||'').slice(11,16))}</strong><small>até ${esc(String(a.ends_at||'').slice(11,16))}</small></div><div class="day-client"><h4>${esc(a.client_name)}</h4><p>${esc(a.client_phone)}</p><span>${esc(a.service_name)} · ${esc(a.barber_name)}</span><span class="day-notes">${a.notes?'Obs.: '+esc(a.notes):''}</span></div><div class="day-state"><span class="status status-${esc(a.status)}">${esc(statusText[a.status]||a.status)}</span><strong>${money(a.price)}</strong></div><div class="day-entry-actions">${appointmentActions(a)}</div></article>`).join('')}</div>`:'<div class="day-empty"><strong>Nenhum agendamento para este dia.</strong><p>Escolha outra data ou consulte o calendário de atendimentos.</p></div>'}`;
  }
  function renderAgenda(){const today=formatYmd(new Date());const all=store.appointments;
    const future=all.filter(a=>['confirmed','pending'].includes(a.status)&&String(a.starts_at).slice(0,10)>=today);
    const total=future.filter(a=>a.status==='confirmed').reduce((n,a)=>n+Number(a.price||0),0);
    const stats=[['Hoje',all.filter(a=>a.starts_at.slice(0,10)===today&&a.status!=='cancelled').length,'Reservas do dia'],['Próximos',future.length,'Confirmados + aguardando aprovação'],['Clientes',store.clients.length,'Cadastrados'],['Receita prevista',money(total),'Reservas confirmadas']];
    view.innerHTML=`<section aria-label="Resumo da agenda" class="stats-grid">${stats.map(([name,val,description])=>`<div class="stat-card"><span class="stat-label">${esc(name)}</span><strong>${esc(val)}</strong><small>${esc(description)}</small></div>`).join('')}</section><section class="day-shortcut" aria-label="Consulta rápida por dia"><div><strong>Quem está agendado hoje?</strong><p>Abra a lista completa de clientes e horários de hoje ou escolha outra data.</p></div><div class="day-shortcut-actions"><span class="day-shortcut-label">Selecionar dia</span><div class="day-shortcut-navigation"><button type="button" class="day-shortcut-arrow" id="agenda-quick-prev" aria-label="Selecionar dia anterior" title="Dia anterior">‹</button><label class="day-shortcut-date-label" for="agenda-quick-day"><span class="visually-hidden">Data para consultar agendamentos</span><input type="date" id="agenda-quick-day" value="${esc(quickDay)}" min="2000-01-01" max="2100-12-31" aria-describedby="agenda-quick-hint"></label><button type="button" class="day-shortcut-arrow" id="agenda-quick-next" aria-label="Selecionar próximo dia" title="Próximo dia">›</button></div><span class="day-shortcut-hint" id="agenda-quick-hint" aria-live="polite">${esc(readableDay(quickDay))}</span><button type="button" class="button button-gold day-shortcut-open" id="agenda-open-day" title="Mostrar agendamentos da data selecionada" aria-label="Ver agendamentos do dia selecionado">Ver agenda do dia <span aria-hidden="true">↗</span></button></div></section><section class="admin-section agenda-section" aria-labelledby="agenda-list-title"><div class="agenda-section-head"><div><span class="eyebrow">Organização da agenda</span><h2 id="agenda-list-title">Agendamentos</h2><p class="text-muted small" id="agenda-filter-count" role="status" aria-live="polite"></p></div><button type="button" class="button button-outline button-small" id="agenda-refresh">Atualizar agenda ↻</button><button type="button" class="button button-outline button-small" id="agenda-open-all">Ver todos os agendamentos</button></div><div class="agenda-toolbar"><label for="agenda-period">Período<select id="agenda-period"><option value="upcoming" ${periodFilter==='upcoming'?'selected':''}>Próximos (incluindo pendentes)</option><option value="today" ${periodFilter==='today'?'selected':''}>Hoje</option><option value="week" ${periodFilter==='week'?'selected':''}>Próximos 7 dias</option><option value="all" ${periodFilter==='all'?'selected':''}>Todos</option></select></label><label for="status-filter">Situação<select id="status-filter"><option value="" ${filterStatus===''?'selected':''}>Todas</option><option value="confirmed" ${filterStatus==='confirmed'?'selected':''}>Confirmados</option><option value="pending" ${filterStatus==='pending'?'selected':''}>Aguardando aprovação</option><option value="completed" ${filterStatus==='completed'?'selected':''}>Concluídos</option><option value="cancelled" ${filterStatus==='cancelled'?'selected':''}>Cancelados</option><option value="no_show" ${filterStatus==='no_show'?'selected':''}>Faltas</option></select></label><label class="agenda-search-label" for="agenda-search">Buscar cliente ou serviço<input type="search" id="agenda-search" value="${esc(searchTerm)}" placeholder="Nome, telefone, serviço..." autocomplete="off"></label></div><div class="agenda-desktop-table">${t(['Data e hora','Cliente','Serviço','Barbeiro','Valor','Status','Ações'],'')}</div><div class="agenda-mobile-cards" id="agenda-mobile-list"></div></section>`;
    const tb=view.querySelector('.agenda-desktop-table tbody');if(tb)tb.id='agenda-table-body';paintAgendaResults();
  }
  function renderClients(){view.innerHTML=`<div class="admin-section"><div class="toolbar"><h2>Clientes (${store.clients.length})</h2><a class="button button-outline" href="/vip.php">Clientes VIP · Assinaturas ↗</a><button class="button button-gold" data-new="clients">+ Novo cliente</button></div>${t(['Nome','Telefone','Observações','Ações'],store.clients.map(c=>`<tr><td>${esc(c.name)}</td><td>${esc(c.phone)}<small>${Number(c.cpf_registered)?' · CPF cadastrado':' · CPF pendente'}</small></td><td>${esc(c.notes||'—')}</td><td>${actionBtn('clients',c.id)}<button data-delete="clients" data-id="${c.id}">Excluir</button></td></tr>`).join(''))}</div>`;}
  function renderCatalog(entity){const services=entity==='services',data=services?store.services:store.barbers;
    const phones=Object.fromEntries(store.settings.filter(x=>String(x.key).startsWith('barber_whatsapp_')).map(x=>[String(x.key),String(x.value||'')]));
    view.innerHTML=`<div class="admin-section"><div class="toolbar"><h2>${services?'Serviços':'Barbeiros'} (${data.length})</h2><button class="button button-gold" data-new="${entity}">+ Adicionar</button></div>${!services?'<p class="small text-muted">Cadastre o WhatsApp de cada profissional para permitir que o cliente envie a confirmação diretamente após reservar. O contato não é exibido no catálogo público.</p>':''}${t(services?['Serviço','Valor','Duração','Ativo','Ações']:['Nome','Descrição','WhatsApp','Ativo','Ações'],data.map(x=>`<tr><td>${esc(x.name)}</td>${services?`<td>${money(x.price)}</td><td>${esc(x.duration_minutes)} min</td>`:`<td>${esc(x.bio||'—')}</td><td>${esc(phones['barber_whatsapp_'+x.id]||'Não cadastrado')}</td>`}<td>${Number(x.active)?'Sim':'Não'}</td><td>${actionBtn(entity,x.id)}${!services&&maySeeCommissions?`<button type="button" data-report-barber="${Number(x.id)}">Comissões</button>`:''}<button data-delete="${entity}" data-id="${x.id}">Excluir</button></td></tr>`).join(''))}</div>${!services&&maySeeCommissions?barberReportPanel():''}`;if(!services&&maySeeCommissions)paintBarberReport();}
  function barberReportPanel(){
    const barberOptions=`<option value="">Todos os barbeiros</option>`+store.barbers.map(b=>`<option value="${Number(b.id)}" ${String(b.id)===reportFilter.barber_id?'selected':''}>${esc(b.name)}</option>`).join('');
    return `<section class="admin-section barber-commission-section" id="relatorio-comissoes" aria-labelledby="barber-commission-title">
      <div class="toolbar barber-commission-head"><div><span class="eyebrow">Financeiro da equipe</span><h2 id="barber-commission-title">Relatório de comissões</h2><p class="small text-muted">Consulte os serviços das comandas fechadas pelo dia do fechamento. Valores pagos e pendentes são separados automaticamente.</p></div><a class="button button-outline button-small" href="/painel.php#configuracoes">Configurar percentuais</a></div>
      <form id="barber-commission-form" class="barber-commission-filters"><label for="commission-report-barber">Barbeiro<select name="barber_id" id="commission-report-barber">${barberOptions}</select></label><label for="commission-report-from">De<input type="date" id="commission-report-from" name="from" min="2000-01-01" max="2100-12-31" required value="${esc(reportFilter.from)}"></label><label for="commission-report-to">Até<input type="date" id="commission-report-to" name="to" min="2000-01-01" max="2100-12-31" required value="${esc(reportFilter.to)}"></label><button type="submit" class="button button-gold">Gerar relatório</button></form>
      <div id="commission-report-results" role="status" aria-live="polite"></div>
    </section>`;
  }
  function paintBarberReport(){
    const target=view.querySelector('#commission-report-results');if(!target)return;
    if(commissionReportLoading){target.innerHTML='<p class="small text-muted">Consultando comissões...</p>';return;}
    if(!commissionReport){target.innerHTML='<p class="barber-commission-intro">Selecione o barbeiro e o período desejado e clique em <strong>Gerar relatório</strong>.</p>';return;}
    const d=commissionReport,q=d.totals||{},pageCount=Math.max(1,Math.ceil(Number(d.total||0)/Number(d.page_size||150)));
    const stat=(label,val,subtitle)=>`<article class="barber-commission-stat"><span>${esc(label)}</span><strong>${esc(String(val))}</strong><small>${esc(subtitle)}</small></article>`;
    let html=`<div class="barber-commission-summary">${stat('Serviços realizados',q.services||0,'Em comandas fechadas')}${stat('Valor líquido dos serviços',commissionMoney(q.net_cents),'Descontos considerados')}${stat('Total de comissões',commissionMoney(q.commission_cents),'Comissões registradas')}${stat('Pendente',commissionMoney(q.pending_cents),'Ainda não pago')}${stat('Pago',commissionMoney(q.paid_cents),'Já registrado no financeiro')}</div>`;
    if(!reportFilter.barber_id && (d.professionals||[]).length){html+=`<h3 class="barber-commission-subtitle">Resumo por profissional</h3>`+t(['Barbeiro','Serviços','Líquido','Comissão','Pendente','Pago'],d.professionals.map(b=>`<tr><td>${esc(b.name||'Cadastro removido')}</td><td>${Number(b.services||0)}</td><td>${commissionMoney(b.net_cents)}</td><td><strong>${commissionMoney(b.commission_cents)}</strong></td><td>${commissionMoney(b.pending_cents)}</td><td>${commissionMoney(b.paid_cents)}</td></tr>`).join(''));}
    html+=`<div class="barber-commission-detail-head"><div><h3>Detalhamento dos serviços</h3><p class="small text-muted">${Number(d.total||0)} itens encontrados · período de ${formatAppointmentDate(reportFilter.from)} até ${formatAppointmentDate(reportFilter.to)} · filtros pela data de fechamento da comanda.</p></div><div class="barber-commission-actions"><button type="button" class="button button-gold button-small" data-report-export>Exportar Excel (CSV)</button><button type="button" class="button button-outline button-small" data-report-print>Imprimir relatório</button></div></div>`;
    html+=t(['Fechamento','Barbeiro','Comanda','Serviço','Qtd.','Líquido','Comissão %','Comissão','Situação'],(d.rows||[]).map(r=>`<tr><td>${formatAppointmentDate(r.closed_at)}</td><td>${esc(r.barber_name||'Cadastro removido')}</td><td>#${Number(r.order_id)}</td><td>${esc(r.description)}</td><td>${Number(r.quantity)}</td><td>${commissionMoney(r.net_cents)}</td><td>${(Number(r.commission_bps)/100).toLocaleString('pt-BR',{minimumFractionDigits:0,maximumFractionDigits:2})}%</td><td><strong>${commissionMoney(r.commission_cents)}</strong></td><td><span class="commission-state ${!Number(r.commission_id)?'none':r.paid_ledger_id?'paid':'pending'}">${!Number(r.commission_id)?'Sem comissão':r.paid_ledger_id?'Paga':'Pendente'}</span></td></tr>`).join(''));
    if(pageCount>1)html+=`<div class="barber-commission-pages"><button type="button" data-report-page="${Math.max(1,d.page-1)}" ${d.page<=1?'disabled':''}>← Anterior</button><span>Página ${Number(d.page)} de ${pageCount} · ${Number(d.total)} serviços</span><button type="button" data-report-page="${Math.min(pageCount,Number(d.page)+1)}" ${Number(d.page)>=pageCount?'disabled':''}>Próxima →</button></div>`;
    html+='<p class="small text-muted barber-commission-footnote">Comissões geradas no fechamento da comanda. Agendamentos apenas marcados como concluídos, sem comanda fechada, não representam recebimento e não entram nos totais. Percentuais são os registrados na inclusão do serviço.</p>';
    target.innerHTML=html;
  }
  async function fetchBarberReport(){
    const seq=++commissionReportSeq;commissionReportLoading=true;paintBarberReport();
    const qs=new URLSearchParams({action:'barber_commission_report',...reportFilter});
    try{
      const response=await fetch('/api/business.php?'+qs,{credentials:'same-origin',cache:'no-store'});
      const d=await response.json();if(!response.ok||!d.ok)throw Error(d.error||'Não foi possível gerar o relatório.');
      if(seq!==commissionReportSeq)return;commissionReport=d;
    }catch(err){if(seq!==commissionReportSeq)return;commissionReport=null;note(message,err.message||'Erro ao consultar comissões.',true);}
    finally{if(seq===commissionReportSeq){commissionReportLoading=false;paintBarberReport();}}
  }
  function commissionReportUrl(action){return '/api/business.php?'+new URLSearchParams({action,...reportFilter,page:1}).toString();}
  function renderHours(){view.innerHTML=`<div class="admin-section"><h2>Horários de atendimento</h2><p class="text-muted">Ajuste os horários normais e os períodos estendidos da equipe.</p>${t(['Dia','Aberto','Horário normal','Estendido','Ação'],[1,2,3,4,5,6,0].map(wd=>{const h=store.hours.find(x=>Number(x.weekday)===wd);return `<tr><td>${weekdays[wd]}</td><td>${h&&Number(h.is_open)?'Sim':'Não'}</td><td>${esc(h?.open_time?.slice(0,5)||'—')} até ${esc(h?.close_time?.slice(0,5)||'—')}</td><td>${esc(h?.extended_close_time?.slice(0,5)||'—')}</td><td><button data-edit="hours" data-id="${wd}">Editar</button></td></tr>`}).join(''))}</div>`;}
  function renderSettings(){const s=Object.fromEntries(store.settings.map(x=>[x.key,x.value]));
    const field=(k,title,description)=>`<div class="setting-block"><label for="setting-${k}">${title}</label><p class="small text-muted">${description}</p><input id="setting-${k}" data-setting-input="${k}" value="${esc(s[k]||'')}" maxlength="500"><button type="button" class="button button-outline" data-setting-save="${k}">Salvar</button></div>`;
    const featureLink=window.MARTELLI_OWNER?`<section class="admin-section"><h2>Funções do painel</h2><p class="small text-muted">Ative ou desative os módulos administrativos, sem apagar agendamentos ou registros.</p><a href="/funcoes.php" class="button button-gold">Ativar / desativar funções ↗</a></section>`:'';
    view.innerHTML=`<div class="admin-section"><h2>Agendamentos online</h2><div class="setting-block"><div class="switch-row"><input type="checkbox" id="bookings-enabled" ${s.bookings_enabled==='1'?'checked':''}><label for="bookings-enabled">Permitir reservas pelo site</label></div><p class="small text-muted">Antes de ativar, confira serviços, valores, profissionais e horários.</p><button class="button button-gold" id="save-bookings">Salvar disponibilidade</button></div><div class="settings-list">${field('whatsapp','WhatsApp','Aceita +55, 55 ou somente DDD e número. Ex.: +55 (49) 99999-9999.')}${field('instagram','Instagram','Endereço completo do perfil.')}${field('address','Endereço','Endereço de atendimento exibido no site após ser configurado.')}${field('notice','Aviso','Comunicado opcional para os clientes.')}</div></div>`+featureLink;
  }
  function renderAdmins(){
    if(!adminUsers){view.innerHTML='<p>Carregando administradores...</p>';return;}
    const permissionLabels={minha_agenda:'Minha agenda de hoje (somente meus clientes)',agendar_meu_dia:'Cadastrar clientes em horários livres da minha agenda de hoje',agenda:'Agenda completa e agendamentos',cadastros:'Clientes, serviços e barbeiros',horarios:'Horários da equipe',lista_espera:'Lista de espera e bloqueios',financeiro:'Comandas, caixa e comissões',estoque:'Produtos e estoque',relatorios:'Dashboard e relatórios',configuracoes:'Configurações da agenda'};
    const barberOptions=(id)=>'<option value="">Selecione o barbeiro</option>'+adminUsers.barbers.filter(b=>Number(b.active)).map(b=>`<option value="${Number(b.id)}" ${Number(b.id)===Number(id)?'selected':''}>${esc(b.name)}</option>`).join('');
    const rows=adminUsers.admins.map(a=>{
      const id=Number(a.id);
      if(a.is_owner)return `<details class="access-profile access-owner access-collapsible"><summary class="access-collapsible-summary"><span class="access-summary-identity"><strong>${esc(a.name)}</strong><small>${esc(a.email)}</small><span class="access-tag">Acesso total protegido · conta principal</span></span><span class="access-chevron" aria-hidden="true">⌄</span></summary><div class="access-collapsible-body"><p class="text-muted">Esta é a conta proprietária. Não é possível removê-la nem restringir suas permissões por este painel.</p></div></details>`;
      const selected=new Set(a.permissions||[]);
      const active=Boolean(Number(a.enabled));
      return `<details class="access-profile access-collapsible" data-admin-details="${id}"><summary class="access-collapsible-summary"><span class="access-summary-identity"><strong>${esc(a.name)}</strong><small>${esc(a.email)}</small><span class="access-state ${active?'is-active':'is-inactive'}">${active?'Conta ativa':'Conta desativada'}</span><span class="access-summary-count">${selected.size} ${selected.size===1?'permissão':'permissões'}</span></span><span class="access-chevron" aria-hidden="true">⌄</span></summary><div class="access-collapsible-body"><form class="access-profile-edit" data-admin-access="${id}"><div class="access-profile-head"><strong>Permissões da conta</strong><label class="access-toggle"><input type="checkbox" name="enabled" ${active?'checked':''}> Conta ativa</label></div><fieldset><legend>Funções permitidas</legend><div class="access-permission-grid">${Object.entries(permissionLabels).map(([key,label])=>`<label><input type="checkbox" name="permissions" value="${key}" ${selected.has(key)?'checked':''}><span>${label}</span></label>`).join('')}</div></fieldset><label class="access-barber-select">Profissional vinculado para agenda individual<select name="barber_id">${barberOptions(a.barber_id)}</select></label><div class="access-approval"><label>Confirme sua senha para salvar<input type="password" name="current_password" autocomplete="current-password" required minlength="12"></label><button type="submit" class="button button-gold">Salvar permissões</button></div></form><details class="access-delete-disclosure"><summary>Excluir conta de acesso</summary><div class="access-delete-description"><strong>Esta ação é permanente.</strong> O login de ${esc(a.name)} será removido, sem apagar os clientes, agendamentos ou o histórico da barbearia. Se deseja apenas suspender a entrada, desmarque <em>Conta ativa</em> e salve.</div><form data-admin-account-delete="${id}" data-email="${esc(a.email)}" class="access-delete-form" autocomplete="off"><label>Digite o e-mail <strong>${esc(a.email)}</strong> para confirmar<input name="confirm_email" type="email" placeholder="E-mail desta conta" required autocomplete="off" maxlength="190" spellcheck="false"></label><label>Sua senha de administrador principal<input name="current_password" type="password" required autocomplete="current-password"></label><button type="submit" class="access-danger-button">Excluir esta conta definitivamente</button></form></details></div></details>`;
    }).join('');
    view.innerHTML=`<div class="admin-section access-admin-screen"><span class="eyebrow">Controle de permissões</span><h2>Administradores cadastrados (${adminUsers.admins.length})</h2><p class="text-muted">Clique no nome de uma conta para abrir as permissões. Você pode desativar o acesso temporariamente ou excluir o login definitivamente; a conta principal permanece protegida.</p><div class="access-profiles">${rows}</div></div>
    <div class="admin-section"><h2>Criar conta com acesso bloqueado</h2><p class="small text-muted">A nova conta nasce sem permissões; após criá-la, configure os acessos acima.</p><form id="admin-create-form" class="admin-account-form" autocomplete="off"><div><label>Nome completo<input name="name" required minlength="2" maxlength="120"></label></div><div><label>E-mail<input name="email" type="email" required maxlength="190"></label></div><div><label>Senha<input name="password" type="password" required minlength="12" maxlength="256"></label></div><div><label>Confirmação<input name="confirm_password" type="password" required minlength="12" maxlength="256"></label></div><div class="wide"><label>Sua senha atual<input name="current_password" type="password" required autocomplete="current-password"></label></div><div class="wide"><button type="submit" class="button button-gold">Criar conta</button></div></form></div>
    <div class="admin-section"><h2>Alterar minha senha</h2><form id="admin-password-form" class="admin-account-form"><div><label>Senha atual<input name="current_password" type="password" required autocomplete="current-password"></label></div><div><label>Nova senha<input name="new_password" type="password" required minlength="12"></label></div><div><label>Confirme a senha<input name="confirm_password" type="password" required minlength="12"></label></div><div class="wide"><button class="button button-outline" type="submit">Atualizar minha senha</button></div></form></div>`;
  }
  function field(label,name,value='',type='text',extra=''){
    return `<div><label>${esc(label)}</label><input name="${esc(name)}" type="${type}" value="${esc(value??'')}" ${extra}></div>`;
  }
  function textField(label,name,value=''){
    return `<div class="wide"><label>${esc(label)}</label><textarea name="${esc(name)}" rows="3" maxlength="500">${esc(value??'')}</textarea></div>`;
  }
  function selectField(label,name,list,current){return `<div><label>${esc(label)}</label><select name="${esc(name)}" required>${options(list,current)}</select></div>`;}
  function boolField(name,checked=true){return `<div class="wide switch-row"><input type="checkbox" name="${name}" id="field-${name}" ${checked?'checked':''}><label for="field-${name}">Ativo</label></div>`;}
  function findBooking(id){
    return (tab==='dia'?dayItems:tab==='todos'?allItems:store?.appointments||[]).find(a=>Number(a.id)===Number(id))
      ||(store?.appointments||[]).find(a=>Number(a.id)===Number(id));
  }
  async function loadRescheduleSlots(){
    if(modalEntity!=='reschedule')return;
    const day=form.elements.date?.value||'';
    const output=fields.querySelector('#reschedule-slots');
    const submit=form.querySelector('[type="submit"]');
    const timeField=form.elements.time;
    if(!output||!timeField)return;
    const seq=++rescheduleSeq;
    timeField.value='';
    if(submit)submit.disabled=true;
    output.setAttribute('aria-busy','true');
    output.innerHTML='<p class="text-muted">Consultando horários livres...</p>';
    if(!validDay(day)){
      output.textContent='Escolha uma data válida.';
      output.setAttribute('aria-busy','false');return;
    }
    try{
      const result=await api('admin_reschedule_slots',null,{id:modalId,date:day});
      if(seq!==rescheduleSeq||modalEntity!=='reschedule'||!modal.open)return;
      const list=Array.isArray(result.slots)?result.slots:[];
      output.innerHTML=list.length
        ?`<div class="reschedule-time-grid" role="group" aria-label="Horários disponíveis">${list.map(slot=>`<button type="button" data-reschedule-time="${esc(slot.time)}" class="reschedule-time" aria-pressed="false">${esc(slot.time)}</button>`).join('')}</div><p class="reschedule-hint">Escolha um horário disponível para habilitar a remarcação.</p>`
        :'<p class="reschedule-empty">Nenhum horário disponível nessa data. Escolha outro dia.</p>';
    }catch(err){
      if(seq!==rescheduleSeq||modalEntity!=='reschedule')return;
      output.innerHTML=`<p role="alert" class="error-banner">${esc(err.message)}</p>`;
    }finally{if(seq===rescheduleSeq)output.setAttribute('aria-busy','false');}
  }
  function openEditor(entity,id=0){modalEntity=entity;modalId=id;let item={};const list=entity==='services'?store.services:entity==='barbers'?store.barbers:entity==='clients'?store.clients:store.hours;
    if(entity==='hours')item=list.find(x=>Number(x.weekday)===id)||{weekday:id,open_time:'08:00',close_time:'18:00',extended_close_time:'',is_open:1};
    else item=list?.find(x=>Number(x.id)===id)||{};
    document.getElementById('dialog-title').textContent=id&&entity!=='hours'?'Editar registro':entity==='booking'?'Novo agendamento':entity==='hours'?`Horários — ${weekdays[id]}`:'Novo cadastro';
    if(entity==='services')fields.innerHTML=field('Nome','name',item.name,'text','required maxlength="120"')+field('Preço (R$)','price',item.price??'0','number','required min="0" max="10000" step="0.01"')+field('Duração (min)','duration_minutes',item.duration_minutes??30,'number','required min="5" max="480"')+field('Ordem','sort_order',item.sort_order??0,'number')+textField('Descrição','description',item.description)+boolField('active',!!Number(item.active??1));
    if(entity==='barbers'){
      const contact=store.settings.find(x=>x.key==='barber_whatsapp_'+id)?.value||'';
      fields.innerHTML=field('Nome','name',item.name,'text','required maxlength="120"')+field('WhatsApp do barbeiro (com ou sem +55)','whatsapp',contact,'tel','inputmode="tel" autocomplete="tel" placeholder="+55 (49) 99999-9999" maxlength="22"')+field('Ordem','sort_order',item.sort_order??0,'number')+textField('Descrição','bio',item.bio)+boolField('active',!!Number(item.active??1));
    }
    if(entity==='clients')fields.innerHTML=field('Nome','name',item.name,'text','required maxlength="120"')+field('WhatsApp com DDD (+55 opcional)','phone',item.phone,'tel','required inputmode="tel" autocomplete="tel" maxlength="22" placeholder="+55 (49) 99999-9999"')+field('CPF (novo obrigatório; em edição, deixe vazio para manter)','cpf','','text',`${id?'':'required'} inputmode="numeric" maxlength="14" placeholder="000.000.000-00"`)+textField('Observações','notes',item.notes);
    if(entity==='hours')fields.innerHTML=field('Abre','open_time',String(item.open_time||'08:00').slice(0,5),'time','required')+field('Fecha','close_time',String(item.close_time||'18:00').slice(0,5),'time','required')+field('Até (estendido, opcional)','extended_close_time',String(item.extended_close_time||'').slice(0,5),'time')+boolField('is_open',!!Number(item.is_open));
    if(entity==='booking')fields.innerHTML=field('Nome completo','name','','text','required maxlength="120"')+field('WhatsApp com DDD (+55 opcional)','phone','','tel','required inputmode="tel" autocomplete="tel" maxlength="22" placeholder="+55 (49) 99999-9999"')+field('CPF do cliente','cpf','','text','required inputmode="numeric" maxlength="14" placeholder="000.000.000-00"')+selectField('Serviço','service_id',store.services.filter(x=>Number(x.active)),null)+selectField('Barbeiro','barber_id',store.barbers.filter(x=>Number(x.active)),null)+field('Data','date',formatYmd(new Date()),'date','required')+field('Horário','time','14:00','time','required step="900"')+textField('Observações','notes');
    if(entity==='booking'){
      fields.insertAdjacentHTML('afterbegin','<div class="wide"><label for="existing-client-search">Buscar cliente cadastrado</label><input id="existing-client-search" type="search" placeholder="Digite nome ou WhatsApp" autocomplete="off"><div id="existing-client-results" aria-live="polite"></div><small class="text-muted">Selecione para preencher nome e telefone, ou preencha um novo cliente abaixo.</small></div>');
      const search=document.getElementById('existing-client-search'),results=document.getElementById('existing-client-results');
      const normalize=value=>String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
      search.addEventListener('input',()=>{
        const q=search.value.trim(),digits=q.replace(/\D/g,'');
        if(q.length<2){results.innerHTML='';return;}
        const phone=window.MartelliPhone.toLocal(q)||digits;
        const matches=store.clients.filter(c=>normalize(c.name).includes(normalize(q))||(digits.length>=3&&String(c.phone).includes(phone))).slice(0,12);
        results.innerHTML=matches.length?matches.map(c=>'<button type="button" class="button button-outline button-small" style="margin:4px" data-pick-client="'+Number(c.id)+'">'+esc(c.name)+' · '+esc(c.phone)+'</button>').join(''):'<p>Nenhum cliente encontrado.</p>';
      });
      results.addEventListener('click',event=>{
        const button=event.target.closest('[data-pick-client]');if(!button)return;
        const client=store.clients.find(c=>Number(c.id)===Number(button.dataset.pickClient));if(!client)return;
        form.elements.name.value=client.name;form.elements.phone.value=window.MartelliPhone.toLocal(client.phone)||client.phone;
        // CPF must be entered by the customer; do not expose the database value.
        form.elements.phone.setCustomValidity('');results.textContent='Cliente selecionado: '+client.name;
      });
      form.elements.phone.addEventListener('change',()=>{
        const phone=window.MartelliPhone.toLocal(form.elements.phone.value);if(!phone)return;
        const matches=store.clients.filter(c=>window.MartelliPhone.toLocal(c.phone)===phone);
        if(matches.length===1&&!form.elements.name.value.trim())form.elements.name.value=matches[0].name;
      });
    }
    if(entity==='reschedule'){
      const booking=findBooking(id);
      if(!booking||booking.status!=='confirmed'){note(message,'Somente reservas confirmadas podem ser remarcadas.',true);return;}
      const originalDay=String(booking.starts_at||'').slice(0,10);
      const today=formatYmd(new Date());
      const latest=shiftDay(today,30);
      const selectedDate=originalDay>=today&&originalDay<=latest?originalDay:today;
      const text=`${esc(booking.client_name)} · ${esc(booking.service_name)} · ${esc(booking.barber_name)}`;
      document.getElementById('dialog-title').textContent='Remarcar agendamento #'+Number(id);
      fields.innerHTML=`<div class="wide reschedule-summary"><span class="eyebrow">Reserva atual</span><strong>${text}</strong><p>${formatAppointmentDate(booking.starts_at)} às ${esc(String(booking.starts_at||'').slice(11,16))} · ${esc(statusText[booking.status])}</p><small>A remarcação mantém o cliente, o barbeiro, o serviço e o número da reserva.</small></div>
        <div class="wide"><label for="reschedule-date">Nova data</label><input id="reschedule-date" type="date" name="date" min="${today}" max="${latest}" value="${selectedDate}" required></div>
        <div class="wide"><label>Horários disponíveis</label><input name="time" type="hidden" value=""><div id="reschedule-slots" class="reschedule-slots" aria-live="polite" aria-busy="true"></div></div>`;
      form.querySelector('[type="submit"]').textContent='Confirmar remarcação';
      form.querySelector('[type="submit"]').disabled=true;
      modal.showModal();void loadRescheduleSlots();return;
    }
    form.querySelector('[type="submit"]').textContent='Salvar';
    form.querySelector('[type="submit"]').disabled=false;
    modal.showModal();}
  form.addEventListener('input',e=>{if(e.target.name==='phone'||e.target.name==='whatsapp')e.target.setCustomValidity('');});
  form.addEventListener('change',e=>{
    if(modalEntity==='reschedule'&&e.target.name==='date')void loadRescheduleSlots();
  });
  fields.addEventListener('click',e=>{
    const slot=e.target.closest('button[data-reschedule-time]');
    if(!slot||modalEntity!=='reschedule')return;
    form.elements.time.value=slot.dataset.rescheduleTime;
    fields.querySelectorAll('button[data-reschedule-time]').forEach(b=>{
      const picked=b===slot;
      b.classList.toggle('is-selected',picked);
      b.setAttribute('aria-pressed',String(picked));
    });
    form.querySelector('[type="submit"]').disabled=false;
  });
  modal.addEventListener('close',()=>{rescheduleSeq++;});
  document.getElementById('new-booking').addEventListener('click',()=>{if(store)openEditor('booking');});
  document.getElementById('dialog-cancel').addEventListener('click',()=>modal.close());
  document.getElementById('logout').addEventListener('click',async()=>{try{await api('logout',{});location.assign('/login.php');}catch(e){note(message,e.message,true);}});
  nav.addEventListener('click',e=>{const el=e.target.closest('button[data-tab]');if(el)setTab(el.dataset.tab);});
  document.getElementById('admin-mobile-nav')?.addEventListener('change',e=>{const target=e.target.value;if(target.startsWith('url:/'))location.assign(target.slice(4));else setTab(target);});
  view.addEventListener('change',e=>{if(e.target.id==='agenda-quick-day'){if(validDay(e.target.value)){quickDay=e.target.value;const hint=view.querySelector('#agenda-quick-hint');if(hint)hint.textContent=readableDay(quickDay);}else e.target.value=quickDay;}if(e.target.id==='day-barber-filter'){dayBarber=e.target.value;paintDay();}if(e.target.id==='status-filter'){filterStatus=e.target.value;paintAgendaResults();}if(e.target.id==='agenda-period'){periodFilter=e.target.value;paintAgendaResults();}});
  view.addEventListener('input',e=>{if(e.target.id==='agenda-search'){searchTerm=e.target.value;paintAgendaResults();}});
  view.addEventListener('click',async e=>{
    const el=e.target.closest('button');if(!el)return;
    try{
      if(el.dataset.reportBarber){reportFilter.barber_id=el.dataset.reportBarber;reportFilter.page=1;
        const select=view.querySelector('#commission-report-barber');if(select)select.value=reportFilter.barber_id;
        await fetchBarberReport();view.querySelector('#relatorio-comissoes')?.scrollIntoView({behavior:'smooth',block:'start'});return;}
      if(el.dataset.reportPage){reportFilter.page=Number(el.dataset.reportPage);await fetchBarberReport();view.querySelector('#commission-report-results')?.scrollIntoView({behavior:'smooth',block:'start'});return;}
      if(el.hasAttribute('data-report-export')){location.assign(commissionReportUrl('barber_commission_csv'));return;}
      if(el.hasAttribute('data-report-print')){window.open(commissionReportUrl('barber_commission_print'),'_blank','noopener');return;}
      if(el.dataset.openDay){showDay(el.dataset.openDay);return;}
      if(el.id==='agenda-quick-prev'||el.id==='agenda-quick-next'){
        const candidate=shiftDay(quickDay,el.id==='agenda-quick-prev'?-1:1);
        if(candidate<'2000-01-01'||candidate>'2100-12-31')return;
        quickDay=candidate;
        const input=view.querySelector('#agenda-quick-day');if(input)input.value=quickDay;
        const hint=view.querySelector('#agenda-quick-hint');if(hint)hint.textContent=readableDay(quickDay);
        return;
      }
      if(el.id==='agenda-open-day'){showDay(quickDay);return;}
      if(el.id==='day-open-all'||el.id==='agenda-open-all'){allPage=1;setTab('todos');return;}
      if(el.id==='all-refresh'){await loadAll();return;}
      if(el.id==='all-prev'||el.id==='all-next'){allPage=Math.max(1,Math.min(allPages,allPage+(el.id==='all-prev'?-1:1)));await loadAll();view.scrollIntoView({behavior:'smooth',block:'start'});return;}
      if(el.id==='day-open'){const v=view.querySelector('#admin-day-date')?.value;if(!validDay(v)){note(message,'Selecione uma data válida.',true);return;}chosenDay=v;await loadDay();return;}
      if(el.id==='day-go-today'){chosenDay=formatYmd(new Date());renderDay();await loadDay();return;}
      if(el.id==='day-prev'||el.id==='day-next'){chosenDay=shiftDay(chosenDay,el.id==='day-prev'?-1:1);if(chosenDay<'2000-01-01'||chosenDay>'2100-12-31'){chosenDay=shiftDay(chosenDay,el.id==='day-prev'?1:-1);return;}renderDay();await loadDay();return;}
      if(el.id==='agenda-refresh'){el.disabled=true;await load();note(message,'Agenda atualizada.');return;}
      if(el.dataset.mainSlot&&el.dataset.mainBarber){
        const barberId=Number(el.dataset.mainBarber),slotTime=el.dataset.mainSlot;
        const slot=(daySlots[String(barberId)]||[]).find(s=>s.time===slotTime&&s.state==='available');
        if(!slot){note(message,'Este horário deixou de estar disponível. Atualize a agenda.',true);return;}
        const ids=new Set((slot.service_ids||[]).map(Number));
        if(!ids.size){note(message,'Nenhum serviço cabe neste horário.',true);return;}
        openEditor('booking');
        form.elements.date.value=chosenDay;
        form.elements.time.value=slotTime;
        form.elements.barber_id.value=String(barberId);
        const serviceSelect=form.elements.service_id;
        Array.from(serviceSelect.options).forEach(option=>{option.disabled=!ids.has(Number(option.value));});
        serviceSelect.value=String([...ids][0]);
        document.getElementById('dialog-title').textContent=`Novo agendamento · ${slotTime} · ${store.barbers.find(b=>Number(b.id)===barberId)?.name||'Barbeiro'}`;
        return;
      }
      if(el.dataset.reschedule){openEditor('reschedule',Number(el.dataset.reschedule));return;}
      if(el.dataset.new){openEditor(el.dataset.new);return;}
      if(el.dataset.edit){openEditor(el.dataset.edit,Number(el.dataset.id));return;}
      if(el.dataset.delete){if(!confirm('Excluir o registro? Essa ação não poderá ser desfeita.'))return;await api('admin_delete',{entity:el.dataset.delete,id:Number(el.dataset.id)});await load();return;}
      if(el.dataset.status){const question=el.dataset.status==='cancelled'?'Cancelar/recusar este agendamento?':el.dataset.status==='completed'?'Marcar este atendimento como concluído?':el.dataset.status==='no_show'?'Registrar que o cliente não compareceu?':el.dataset.status==='confirmed'?'Confirmar que o barbeiro aprovou ou reativou este atendimento?':'Atualizar este agendamento?';if(!window.confirm(question))return;el.disabled=true;await api('admin_save',{entity:'appointment_status',id:Number(el.dataset.id),status:el.dataset.status});await load();note(message,'Agendamento atualizado.');return;}
      if(el.id==='save-bookings'){await api('admin_save',{entity:'settings',key:'bookings_enabled',value:document.getElementById('bookings-enabled').checked?'1':'0'});note(message,'Configuração salva.');await load();return;}
      if(el.dataset.settingSave){const k=el.dataset.settingSave;const v=document.querySelector(`[data-setting-input="${k}"]`).value;await api('admin_save',{entity:'settings',key:k,value:v});note(message,'Configuração salva.');await load();return;}
    }catch(err){note(message,err.message,true);window.scrollTo({top:0,behavior:'smooth'});}
  });
  view.addEventListener('submit',async e=>{
    if(e.target.id!=='barber-commission-form')return;e.preventDefault();
    const form=e.target;const fields=Object.fromEntries(new FormData(form));
    if(!validDay(fields.from)||!validDay(fields.to)||fields.from>fields.to){note(message,'Selecione um período válido.',true);return;}
    reportFilter.barber_id=fields.barber_id;reportFilter.from=fields.from;reportFilter.to=fields.to;reportFilter.page=1;
    await fetchBarberReport();
  });
  view.addEventListener('submit',async e=>{
    const target=e.target.closest('form[data-admin-access]');
    if(!target)return;
    e.preventDefault();
    const btn=target.querySelector('[type="submit"]');btn.disabled=true;
    try{
      const permissions=[...target.querySelectorAll('input[name="permissions"]:checked')].map(el=>el.value);
      const data={id:Number(target.dataset.adminAccess),enabled:target.elements.enabled.checked,permissions,barber_id:Number(target.elements.barber_id.value||0),current_password:target.elements.current_password.value};
      const result=await api('admin_access_save',data);target.elements.current_password.value='';await loadAdmins();const reopened=view.querySelector(`[data-admin-details="${Number(data.id)}"]`);if(reopened)reopened.open=true;note(message,result.message);
    }catch(err){note(message,err.message,true);target.elements.current_password.value='';}
    finally{if(btn.isConnected)btn.disabled=false;}
  });
  view.addEventListener('submit',async e=>{
    const target=e.target.closest('form[data-admin-account-delete]');
    if(!target)return;
    e.preventDefault();
    const id=Number(target.dataset.adminAccountDelete);
    const email=target.dataset.email;
    const typed=target.elements.confirm_email.value.trim().toLowerCase();
    if(!email || typed!==email.toLowerCase()) {note(message,'Digite o e-mail exato da conta para confirmar.',true);return;}
    if(!target.elements.current_password.value) {note(message,'Informe a senha da conta principal.',true);return;}
    if(!window.confirm(`Excluir DEFINITIVAMENTE o login ${email}?\n\nEsta ação é irreversível. Clientes, reservas e histórico serão mantidos.`))return;
    const submit=target.querySelector('[type="submit"]');
    if(submit)submit.disabled=true;
    try {
      const result=await api('admin_account_delete',{id,confirm_email:typed,current_password:target.elements.current_password.value,confirmation:'EXCLUIR'});
      target.elements.current_password.value='';
      await loadAdmins();
      note(message,result.message||'Conta excluída com sucesso.');
    } catch(err) {
      target.elements.current_password.value='';
      note(message,err.message,true);
      if(submit?.isConnected)submit.disabled=false;
    }
  });
  view.addEventListener('submit',async e=>{
    if (e.target.id!=='admin-create-form' && e.target.id!=='admin-password-form') return;
    e.preventDefault();
    const target=e.target;
    const submit=target.querySelector('button[type="submit"]');
    const data=Object.fromEntries(new FormData(target).entries());
    if(data.password && data.confirm_password!==data.password){note(message,'As senhas não coincidem.',true);return;}
    if(data.new_password && data.confirm_password!==data.new_password){note(message,'As senhas não coincidem.',true);return;}
    if(submit)submit.disabled=true;
    try{
      const creating=target.id==='admin-create-form';
      const result=await api(creating?'admin_user_create':'admin_password_change',data);
      target.reset();
      if(creating)await loadAdmins();
      note(message,result.message||'Alteração concluída.');
      window.scrollTo({top:0,behavior:'smooth'});
    }catch(err){note(message,err.message,true);window.scrollTo({top:0,behavior:'smooth'});}
    finally{if(submit.isConnected)submit.disabled=false;}
  });
  form.addEventListener('submit',async e=>{
    e.preventDefault();const payload={};for(const [key,val] of new FormData(form).entries())payload[key]=val;
    if(modalEntity==='clients'||modalEntity==='booking'||modalEntity==='barbers') {
      const phoneField=form.elements[modalEntity==='barbers'?'whatsapp':'phone'];
      if(phoneField && (phoneField.value.trim()!==''||modalEntity!=='barbers')) {
        const local=window.MartelliPhone.validateInput(phoneField);
        if(!local)return;
        payload[phoneField.name]=local;
      }
    }
    if(modalEntity==='services'||modalEntity==='barbers')payload.active=form.elements.active.checked?1:0;
    if(modalEntity==='hours')payload.is_open=form.elements.is_open.checked?1:0;
    const submit=form.querySelector('[type="submit"]');
    if(modalEntity==='reschedule'&&!payload.time){alert('Escolha um horário livre antes de continuar.');return;}
    if(submit)submit.disabled=true;
    try{
      if(modalEntity==='reschedule'){
        const result=await api('admin_reschedule',{id:modalId,date:payload.date,time:payload.time});
        if(tab==='dia')chosenDay=payload.date;
        modal.close();await load();
        note(message,result.message||'Data e horário atualizados.');
      }else{
        if(modalEntity==='booking')await api('admin_booking',payload);
        else await api('admin_save',{entity:modalEntity,id:modalId,...payload,...(modalEntity==='hours'?{weekday:modalId}:{})});
        modal.close();note(message,'Alterações salvas.');await load();
      }
    }catch(err){alert(err.message);}
    finally{if(submit?.isConnected && modal.open)submit.disabled=modalEntity==='reschedule'?!form.elements.time?.value:false;}
  });
  window.addEventListener('hashchange',()=>{const name=location.hash.slice(1);if(Object.hasOwn(headings,name)&&name!==tab)setTab(name);});
  await load();
  if(Object.hasOwn(headings,location.hash.slice(1))&&window.MartelliAllowedTab?.(location.hash.slice(1)))setTab(location.hash.slice(1));else setTab(tab);
})();
