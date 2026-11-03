'use strict';
(()=>{
 const $=id=>document.getElementById(id);
 const token=new URLSearchParams(location.search).get('chave')||'';
 let details=null,selectedTime='',slots=[];
 const fmt=d=>{if(!/^\d{4}-\d{2}-\d{2}$/.test(d||''))return d||'—';const [y,m,day]=d.split('-');return `${day}/${m}/${y}`;};
 const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const saoDate=()=>new Intl.DateTimeFormat('sv-SE',{timeZone:'America/Sao_Paulo',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
 const addDays=(date,n)=>{const d=new Date(date+'T12:00:00-03:00');d.setDate(d.getDate()+n);return new Intl.DateTimeFormat('sv-SE',{timeZone:'America/Sao_Paulo',year:'numeric',month:'2-digit',day:'2-digit'}).format(d);};
 async function api(action,payload=null,query={}){
  const url=new URL('/api/vip-agenda.php',location.origin);url.searchParams.set('action',action);
  if(payload===null)url.searchParams.set('chave',token);
  Object.entries(query).forEach(([k,v])=>url.searchParams.set(k,v));
  const response=await fetch(url,{method:payload===null?'GET':'POST',credentials:'same-origin',cache:'no-store',referrerPolicy:'no-referrer',headers:payload?{'Content-Type':'application/json','X-CSRF-Token':window.MARTELLI_VIP_CSRF}:{},body:payload?JSON.stringify({...payload,chave:token}):undefined});
  const body=await response.json().catch(()=>{throw Error('Resposta inválida do servidor.');});
  if(!response.ok||body.ok===false)throw Error(body.error||'Não foi possível consultar a agenda.');
  return body;
 }
 function error(message){$('vip-calendar-error').textContent=message;$('vip-calendar-error').hidden=false;}
 function render(){
  const m=details.member;const enabled=Boolean(m.can_book)&&m.remaining>0&&details.services.length>0;
  $('vip-calendar-client').textContent=m.name;
  $('vip-calendar-plan').textContent=m.plan;
  $('vip-calendar-validity').textContent=`Vigência: ${fmt(m.starts_on)} a ${fmt(m.ends_on)} · ${m.included_cuts} cortes no plano`;
  $('vip-calendar-left').textContent=m.remaining;
  $('vip-calendar-reserved').textContent=m.reserved;
  $('vip-calendar-barber').innerHTML='<option value="">Selecione o barbeiro</option>'+details.barbers.map(b=>`<option value="${Number(b.id)}">${esc(b.name)}</option>`).join('');
  const today=saoDate(),min=today>m.starts_on?today:m.starts_on,max=addDays(today,30)<m.ends_on?addDays(today,30):m.ends_on;
  $('vip-calendar-date').min=min;$('vip-calendar-date').max=max;$('vip-calendar-date').value=min;
  if(!enabled){$('vip-calendar-form').querySelectorAll('input,select,button').forEach(x=>x.disabled=true);
   $('vip-calendar-slots-message').textContent=m.status!=='active'?'Assinatura não está ativa. Consulte a barbearia.':m.starts_on>today?'Sua assinatura ainda não começou.':m.ends_on<today?'Assinatura vencida. Solicite renovação.':!details.bookings_enabled?'Agendamentos online temporariamente desativados.':!details.services.length?'Serviço Corte indisponível. Consulte a barbearia.':'Você já utilizou ou reservou todos os cortes do ciclo.';
  }
  const labels={pending:'Aguardando aprovação',confirmed:'Confirmado',completed:'Concluído',cancelled:'Cancelado',no_show:'Falta'};
  $('vip-calendar-bookings').innerHTML=details.appointments.length?`<div class="vip-calendar-history">${details.appointments.map(a=>`<div><strong>${esc(fmt(a.starts_at.slice(0,10)))} · ${esc(a.starts_at.slice(11,16))}</strong><span>${esc(a.barber_name)}</span><small>${labels[a.status]||'Registrado'}</small></div>`).join('')}</div>`:'<p class="vip-calendar-hint">Nenhum corte agendado neste ciclo.</p>';
  $('vip-calendar-loading').hidden=true;$('vip-calendar-content').hidden=false;
 }
 async function loadSlots(){
  selectedTime='';$('vip-calendar-confirm').disabled=true;
  const barber=$('vip-calendar-barber').value,day=$('vip-calendar-date').value;
  $('vip-calendar-grid').innerHTML='';$('vip-calendar-slots').disabled=true;
  if(!barber||!day){$('vip-calendar-slots-message').textContent='Selecione o barbeiro e o dia.';return;}
  $('vip-calendar-slots-message').textContent='Consultando disponibilidade…';
  try{
   const r=await api('slots',null,{date:day,barber_id:barber});slots=r.slots||[];
   $('vip-calendar-slots-message').textContent=r.message||(slots.length?'Selecione um dos horários livres abaixo.':'Nenhum horário disponível nesse dia. Escolha outra data.');
   $('vip-calendar-grid').innerHTML=slots.map(s=>`<button type="button" class="vip-calendar-slot" data-time="${esc(s.time)}" aria-pressed="false">${esc(s.time)}${s.requires_approval?'<small>Sob consulta</small>':'<small>Livre</small>'}</button>`).join('');
   $('vip-calendar-slots').disabled=!slots.length;
  }catch(e){$('vip-calendar-slots-message').textContent=e.message;}
 }
 $('vip-calendar-grid').addEventListener('click',e=>{
  const btn=e.target.closest('button[data-time]');if(!btn)return;
  selectedTime=btn.dataset.time;
  $('vip-calendar-grid').querySelectorAll('button').forEach(x=>{const active=x===btn;x.classList.toggle('chosen',active);x.setAttribute('aria-pressed',String(active));});
  $('vip-calendar-confirm').disabled=false;
 });
 $('vip-calendar-date').addEventListener('change',loadSlots);
 $('vip-calendar-barber').addEventListener('change',loadSlots);
 $('vip-calendar-form').addEventListener('submit',async e=>{
  e.preventDefault();if(!selectedTime)return;
  const day=$('vip-calendar-date').value,barber=Number($('vip-calendar-barber').value),choice=slots.find(s=>s.time===selectedTime);
  if(!confirm(`Deseja reservar ${fmt(day)} às ${selectedTime}?${choice?.requires_approval?' O barbeiro precisará aprovar este horário.':''}`))return;
  $('vip-calendar-confirm').disabled=true;
  $('vip-calendar-feedback').hidden=true;
  try{
   const r=await api('reserve',{date:day,time:selectedTime,barber_id:barber});
   const f=$('vip-calendar-feedback');f.textContent=r.message;f.hidden=false;f.className='vip-calendar-ok';
   details=await api('details');render();
   // Preserva a mensagem positiva após renderizar, mas nunca repete a reserva.
   $('vip-calendar-feedback').textContent=r.message;$('vip-calendar-feedback').hidden=false;
  }catch(err){const f=$('vip-calendar-feedback');f.textContent=err.message;f.hidden=false;f.className='vip-calendar-bad';$('vip-calendar-confirm').disabled=false;await loadSlots();}
 });
 if(token && !/^[a-f0-9]{64}$/.test(token)){error('Link VIP inválido. Solicite seu link pessoal à barbearia.');$('vip-calendar-loading').hidden=true;return;}
 api('details').then(r=>{details=r;render();}).catch(e=>{if(!token && /Entre com sua conta VIP/i.test(e.message)){location.replace('/vip-entrar.php');return;}error(e.message);$('vip-calendar-loading').hidden=true;});
})();
