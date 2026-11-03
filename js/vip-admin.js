'use strict';
(() => {
 const $=id=>document.getElementById(id),esc=window.Martelli.escape;
 const dialog=$('vip-dialog'),form=$('vip-form'),fields=$('vip-fields');
 const notice=$('vip-alert'),money=c=>(Number(c||0)/100).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
 const today=new Intl.DateTimeFormat('sv-SE',{timeZone:'America/Sao_Paulo'}).format(new Date());
 const seven=new Date(today+'T12:00:00-03:00');seven.setDate(seven.getDate()+7);
 const soon=new Intl.DateTimeFormat('sv-SE',{timeZone:'America/Sao_Paulo'}).format(seven);
 let state={clients:[],plans:[],memberships:[],requests:[],owner:false},action='';
 async function api(task,payload=null,query={}){
  const url=new URL('/api/vip.php',location.origin);url.searchParams.set('action',task);
  Object.entries(query).forEach(([k,v])=>url.searchParams.set(k,v));
  const res=await fetch(url,{method:payload===null?'GET':'POST',credentials:'same-origin',headers:payload===null?{}:{'Content-Type':'application/json','X-CSRF-Token':window.MARTELLI_CSRF},body:payload===null?undefined:JSON.stringify(payload)});
  const body=await res.json().catch(()=>{throw Error('Resposta inesperada do servidor.');});
  if(!res.ok||body.ok===false)throw Error(body.error||'Falha ao salvar.');return body;
 }
 function alertMsg(text,error=false){notice.hidden=false;notice.className=error?'vip-error':'vip-success';notice.textContent=text;notice.scrollIntoView({behavior:'smooth',block:'nearest'});}
 const input=(title,name,value='',type='text',attrs='')=>`<label>${esc(title)}<input name="${esc(name)}" type="${esc(type)}" value="${esc(value)}" ${attrs}></label>`;
 const options=(rows,selected='')=>rows.map(r=>`<option value="${Number(r.id)}" ${String(r.id)===String(selected)?'selected':''}>${esc(r.name)}</option>`).join('');
 const select=(title,name,rows,selected='')=>`<label>${esc(title)}<select name="${esc(name)}" required><option value="">Selecione</option>${options(rows,selected)}</select></label>`;
 function open(title,task,html){action=task;$('vip-dialog-title').textContent=title;fields.innerHTML=html;$('vip-dialog-error').hidden=true;dialog.showModal();fields.querySelector('input,select,textarea')?.focus();}
 function status(m){if(m.status==='cancelled')return 'cancelled';if(m.status==='paused')return 'paused';if(m.ends_on<today)return 'expired';if(m.starts_on>today)return 'scheduled';return 'active';}
 const statusLabels={active:'Ativa',expired:'Vencida',paused:'Pausada',cancelled:'Cancelada',scheduled:'Programada'};
 function render(){
  const all=state.memberships;
  const groups=new Map();
  // Uma linha por cliente. Assinaturas antigas/canceladas ficam disponíveis no histórico.
  all.forEach(m=>{const key=String(m.client_id);if(!groups.has(key))groups.set(key,[]);groups.get(key).push(m);});
  const rank=m=>({active:5,scheduled:4,paused:3,expired:2,cancelled:1}[status(m)]||0);
  const members=[...groups.values()].map(items=>{
   items.sort((a,b)=>rank(b)-rank(a)||String(b.starts_on).localeCompare(String(a.starts_on))||Number(b.id)-Number(a.id));
   return {...items[0],previous:items.slice(1)};
  });
  $('vip-count-active').textContent=members.filter(m=>status(m)==='active').length;
  $('vip-count-used').textContent=all.reduce((n,m)=>n+Number(m.used_cuts||0),0);
  $('vip-count-expiring').textContent=members.filter(m=>status(m)==='active'&&m.ends_on<=soon).length;
  const search=$('vip-search').value.toLocaleLowerCase('pt-BR').trim(),filter=$('vip-filter').value;
  const rows=members.filter(m=>(filter==='all'||status(m)===filter)&&(`${m.client_name} ${m.client_phone}`.toLocaleLowerCase('pt-BR').includes(search)));
  $('vip-member-rows').innerHTML=rows.length?rows.map(m=>{
   const s=status(m),use=Number(m.used_cuts),total=Number(m.cuts_per_month);
   const actions=[];
   if(state.owner && state.agenda_ready && ['active','scheduled'].includes(s)){actions.push(`<button type="button" data-act="share-link" data-id="${Number(m.id)}">Link VIP / WhatsApp</button>`);actions.push(`<button type="button" data-act="reset-login" data-id="${Number(m.id)}">Redefinir login VIP</button>`);}
   if(['active','scheduled'].includes(s) && state.agenda_ready)actions.push(`<button type="button" data-act="vip-book" data-id="${Number(m.id)}">Agendar corte VIP</button>`);
   if(state.owner && ['active','scheduled','paused'].includes(s))actions.push(`<button type="button" data-act="membership-dates" data-id="${Number(m.id)}">Corrigir vigência</button>`);
   if(state.owner)actions.push(`<button type="button" data-act="password-set" data-id="${Number(m.id)}">Alterar senha VIP</button>`);
   if(s==='active'&&use+Number(m.reserved_cuts||0)<total)actions.push(`<button type="button" data-act="use" data-id="${Number(m.id)}">Registrar corte</button>`);
   if(m.status!=='cancelled'&&s!=='expired'){
    if(m.status==='active')actions.push(`<button type="button" data-act="status" data-value="paused" data-id="${Number(m.id)}">Pausar</button>`);
    else actions.push(`<button type="button" data-act="status" data-value="active" data-id="${Number(m.id)}">Reativar</button>`);
    actions.push(`<button type="button" class="vip-danger" data-act="status" data-value="cancelled" data-id="${Number(m.id)}">Cancelar</button>`);
   }
   if(['expired','cancelled'].includes(s))actions.push(`<button type="button" data-act="renew" data-id="${Number(m.id)}">Nova assinatura</button>`);
   actions.push(`<button type="button" data-act="history" data-id="${Number(m.id)}">Cortes</button>`);
   if(state.owner)actions.push(`<button type="button" class="vip-danger" data-act="member-delete" data-id="${Number(m.id)}" aria-label="Excluir cadastro VIP de ${esc(m.client_name)}">Excluir assinante</button>`);
   if(m.previous.length)actions.push(`<button type="button" data-act="subscriptions-history" data-id="${Number(m.id)}">Ver ${m.previous.length} assinatura(s) anterior(es)</button>`);
   return `<tr><td><strong>${esc(m.client_name)}</strong><small>${esc(m.client_phone)}</small></td><td>${esc(m.plan_name)}<small>${money(m.monthly_price_cents)}/mês</small></td><td>${esc(m.starts_on)}<small>até ${esc(m.ends_on)}</small></td><td><strong>${use} / ${total}</strong><small>${Number(m.reserved_cuts||0)} reservado(s)</small><div class="vip-progress"><span style="width:${Math.min(100,Math.round(use/Math.max(total,1)*100))}%"></span></div></td><td><span class="vip-status ${s}">${statusLabels[s]}</span></td><td><div class="vip-row-actions">${actions.join('')}</div></td></tr>`;
  }).join(''):'<tr><td colspan="6" class="vip-empty">Nenhuma assinatura encontrada. Cadastre um plano e adicione um cliente.</td></tr>';
  renderRequests();
  $('vip-plan-cards').innerHTML=state.plans.some(p=>!String(p.name).startsWith('PERSONALIZADO · '))?state.plans.filter(p=>!String(p.name).startsWith('PERSONALIZADO · ')).map(p=>`<article class="vip-plan-item ${Number(p.active)?'':'vip-inactive'}"><div class="vip-plan-top"><span class="vip-status ${Number(p.active)?'active':'paused'}">${Number(p.active)?'Publicado':'Oculto'}</span>${state.owner?`<button type="button" data-act="edit-plan" data-id="${Number(p.id)}">Editar</button>`:''}</div><h3>${esc(p.name)}</h3><strong>${money(p.monthly_price_cents)}<small>/mês</small></strong><p>${esc(p.description||'Assinatura mensal de atendimentos.')}</p><small>Serviço: ${esc(p.service_name||'Corte')}</small><span>${Number(p.cuts_per_month)} ${Number(p.cuts_per_month)===1?'atendimento incluído':'atendimentos incluídos'} por período</span></article>`).join(''):'<p>Você ainda não criou planos VIP. Cadastre seu primeiro plano para divulgar a assinatura no site.</p>';
 }
 function renderRequests(){
  const ready=Boolean(state.requests_ready),items=Array.isArray(state.requests)?state.requests:[];
  const pending=items.filter(x=>x.status==='pending');
  $('vip-requests-count').textContent=`${pending.length} pendente${pending.length===1?'':'s'}`;
  $('vip-requests-message').hidden=ready;
  if(!ready)$('vip-requests-message').textContent='Para receber inscrições pelo site, importe sql/vip_inscricoes_v22.sql no phpMyAdmin.';
  const labels={pending:'Aguardando análise',approved:'Aprovada',rejected:'Recusada'};
  $('vip-request-rows').innerHTML=!ready?'<tr><td colspan="5">Instale a migração V22 para receber solicitações.</td></tr>':items.length?items.map(q=>{
   const act=[];
   if(q.status==='pending')act.push(`<button type="button" data-act="request-approve" data-id="${Number(q.id)}">Aprovar e ativar</button>`,`<button type="button" class="vip-danger" data-act="request-reject" data-id="${Number(q.id)}">Recusar</button>`);
   if(q.status==='approved'&&q.membership_id&&state.owner&&state.agenda_ready)act.push(`<button type="button" data-act="share-link" data-id="${Number(q.membership_id)}">Gerar link VIP</button>`);
   if(state.owner)act.push(`<button type="button" class="vip-danger" data-act="request-delete" data-id="${Number(q.id)}">Excluir solicitação</button>`);
   return `<tr><td><strong>${esc(q.client_name)}</strong><small>${esc(q.client_phone)}${q.client_email?'<br>'+esc(q.client_email):''}</small>${q.notes?`<small>Observações: ${esc(q.notes)}</small>`:''}${Number(q.google_signup)===1?'<small class="vip-google-request">✓ Cadastro por Google verificado</small>':''}${Number(q.apple_signup)===1?'<small class="vip-google-request">✓ Cadastro por Apple verificado</small>':''}</td><td><strong>${esc(q.plan_name)}</strong><small>${money(q.monthly_price_cents)} · ${Number(q.included_cuts)} corte(s)/ciclo</small></td><td>${esc(q.created_at)}</td><td><span class="vip-status ${q.status==='approved'?'active':q.status==='rejected'?'cancelled':'scheduled'}">${labels[q.status]||'Pendente'}</span></td><td><div class="vip-row-actions">${act.join('')||'—'}</div></td></tr>`;
  }).join(''):'<tr><td colspan="5" class="vip-empty">Nenhuma solicitação recebida.</td></tr>';
 }
 async function refresh(){const r=await api('list');state=r;render();}
 const vipServices=['Corte','Barba','Corte + Barba','Corte + Barba + Sobrancelha','Barba + Sobrancelha'];
 function newMember(clientId='',start=today,preferCustom=false,preferNew=false){
  const plans=state.plans.filter(p=>Number(p.active));
  if(!plans.length&&!state.owner){alertMsg('Cadastre um plano ativo antes de adicionar assinantes.',true);return;}
  const custom=Boolean(preferCustom&&state.owner), owner=Boolean(state.owner);
  const clientType=owner?`<fieldset class="vip-choice-set vip-wide"><legend>01 · Quem receberá a assinatura?</legend>
   <label><input type="radio" name="client_mode" value="existing" ${preferNew?'':'checked'}> Cliente já cadastrado</label>
   <label><input type="radio" name="client_mode" value="new" ${preferNew?'checked':''}> Cadastrar um novo cliente</label></fieldset>`:'<input type="hidden" name="client_mode" value="existing">';
  const planType=owner?`<fieldset class="vip-choice-set vip-wide"><legend>02 · Qual tipo de assinatura?</legend>
   <label><input type="radio" name="membership_mode" value="standard" ${!custom?'checked':''}> Plano existente</label>
   <label><input type="radio" name="membership_mode" value="custom" ${custom?'checked':''}> Personalizar para este cliente</label></fieldset>`:'<input type="hidden" name="membership_mode" value="standard">';
  const html=clientType+
   `<div class="vip-wide vip-form-section" data-client-section="existing">${select('Cliente cadastrado','client_id',state.clients,clientId)}<p class="vip-note">Se o cliente já existe no cadastro geral, selecione-o aqui para evitar registros duplicados.</p></div>`+
   (owner?`<div class="vip-wide vip-form-section" data-client-section="new" hidden>
      ${input('Nome completo','new_client_name','','text','maxlength="120" autocomplete="name"')}
      ${input('WhatsApp com DDD','new_client_phone','','tel','maxlength="22" inputmode="tel" autocomplete="tel" placeholder="(49) 99999-9999"')}
      ${input('CPF do cliente','new_client_cpf','','text','maxlength="14" inputmode="numeric" placeholder="000.000.000-00"')}
      <p class="vip-note vip-wide">O telefone deve ser único. Se já existir, selecione o cadastro na opção acima.</p></div>`:'')+
   planType+
   `<div class="vip-wide vip-form-section" data-plan-section="standard">${select('Plano VIP cadastrado','plan_id',plans)}</div>`+
   (owner?`<div class="vip-wide vip-form-section" data-plan-section="custom" hidden>
      ${input('Nome da assinatura personalizada','custom_name','','text','maxlength="75" placeholder="Ex.: Plano especial mensal"')}
      ${input('Valor do ciclo (R$)','custom_price','','number','min="0.01" max="99999.99" step="0.01" placeholder="150.00"')}
      ${input('Atendimentos incluídos nos 30 dias','custom_cuts','','number','min="1" max="30" step="1"')}
      <label>Serviço de cada atendimento<select name="custom_service">${vipServices.map(n=>`<option value="${esc(n)}">${esc(n)}</option>`).join('')}</select></label>
      <label class="vip-wide">Benefícios e condições especiais<textarea name="custom_description" maxlength="350" rows="3" placeholder="Opcional: condições combinadas com o cliente"></textarea></label>
      <p class="vip-note vip-wide">A assinatura personalizada será privada e não aparecerá como plano disponível para outros clientes. Não gera cobrança automática.</p></div>`:'')+
   `<div class="vip-wide vip-form-section"><span class="vip-step-label">03 · Vigência do contrato</span>
       ${input('Início do ciclo (30 dias corridos)','starts_on',start,'date','required')}
       <p class="vip-note" id="vip-end-preview" role="status"></p>
       <label class="vip-wide">Observações internas<textarea name="notes" maxlength="500" rows="2" placeholder="Opcional"></textarea></label>
       <p class="vip-note vip-wide">Confirme as condições e o pagamento diretamente com o cliente. Salvar não realiza cobrança nem reserva horários automaticamente.</p></div>`;
  open(owner?'Novo cliente / assinatura VIP':'Nova assinatura VIP','membership_create',html);
  const section=(mode,attr)=>{fields.querySelectorAll(`[${attr}]`).forEach(el=>{
    const active=el.getAttribute(attr)===mode;el.hidden=!active;
    el.querySelectorAll('input,select,textarea').forEach(control=>{
      control.disabled=!active;
      if(active && (control.name==='client_id'||control.name==='plan_id'||['new_client_name','new_client_phone','new_client_cpf','custom_name','custom_price','custom_cuts'].includes(control.name)))control.required=true;
    });
  });};
  const update=()=>{
   const cm=form.querySelector('[name=client_mode]:checked')?.value||'existing';
   const pm=form.querySelector('[name=membership_mode]:checked')?.value||'standard';
   section(cm,'data-client-section');section(pm,'data-plan-section');
  };
  form.querySelectorAll('[name=client_mode],[name=membership_mode]').forEach(el=>el.addEventListener('change',update));
  const fmtDay=iso=>{const p=iso.split('-');return p.length===3?`${p[2]}/${p[1]}/${p[0]}`:iso;};
  const updateEnd=()=>{
   const day=form.elements.starts_on.value;
   const d=new Date(`${day}T12:00:00-03:00`);
   if(!day||Number.isNaN(d.valueOf())){$('vip-end-preview').textContent='Selecione o início do ciclo.';return;}
   d.setUTCDate(d.getUTCDate()+29);
   const last=new Intl.DateTimeFormat('sv-SE',{timeZone:'America/Sao_Paulo'}).format(d);
   $('vip-end-preview').textContent=`Ciclo de 30 dias: ${fmtDay(day)} a ${fmtDay(last)} (inclusive).`;
  };
  form.elements.starts_on.addEventListener('change',updateEnd);
  update();updateEnd();
 }
 function editPlan(p={}){
  open(p.id?'Editar plano VIP':'Novo plano VIP','plan_save',input('Nome do plano','name',p.name||'','text','required maxlength="100"')+input('Mensalidade (R$)','price',(Number(p.monthly_price_cents||0)/100).toFixed(2),'number','required min="0" step="0.01"')+input('Atendimentos por ciclo de 30 dias','cuts_per_month',p.cuts_per_month||'','number','required min="1" max="30" step="1"')+`<label class="vip-wide">Serviço incluído<select name="service_name" required>${['Corte','Barba','Corte + Barba','Corte + Barba + Sobrancelha','Barba + Sobrancelha'].map(n=>`<option value="${esc(n)}" ${p.service_name===n?'selected':''}>${esc(n)}</option>`).join('')}</select></label><label class="vip-wide">Benefícios / descrição<textarea name="description" maxlength="500" rows="3">${esc(p.description||'')}</textarea></label><label class="vip-check"><input name="active" type="checkbox" ${p.id&&!Number(p.active)?'':'checked'}> Exibir este plano no site</label><input type="hidden" name="id" value="${p.id||''}"><p class="vip-note">As condições dos contratos atuais são preservadas. Planos publicados precisam ter valor maior que R$ 0,00. Não use um plano grátis para consultas por WhatsApp.</p>`);
 }
 function showPrivateLink(data){
  // Segredo mostrado somente nesta sessão; o servidor armazena apenas o hash.
  const message=data.reset?`Olá, ${data.name}! Seu acesso Martelli VIP foi redefinido. Use este link pessoal para criar sua nova senha e acessar a agenda: ${data.link}  Não compartilhe este link.`:`Olá, ${data.name}! Sua assinatura Martelli VIP está ativa. Acesse sua agenda pelo link pessoal e, em seu primeiro acesso, clique em Ativar meu login VIP: ${data.link}  Não compartilhe este link.`;
  const digits=String(data.phone||'').replace(/\D/g,'');
  const whatsapp=`https://wa.me/${digits.startsWith('55')?digits:'55'+digits}?text=${encodeURIComponent(message)}`;
  $('vip-link-url').value=data.link;
  $('vip-whatsapp-link').href=whatsapp;
  $('vip-link-dialog').showModal();
 }
 async function generateLink(id){
  if(!state.agenda_ready){alertMsg('Instale a migração SQL V23 para habilitar links VIP.',true);return;}
  if(!confirm('Gerar um novo link pessoal? Se este cliente já recebeu outro link, ele deixará de funcionar.'))return;
  const r=await api('link_generate',{membership_id:id});showPrivateLink(r);
 }
 $('vip-duplicates-review')?.addEventListener('click',async()=>{
  try{
   const result=await api('duplicates_preview');
   if(!result.count){alertMsg('Nenhuma cópia exata e sem histórico foi encontrada. Assinaturas canceladas em outros períodos são registros históricos, não duplicatas.');return;}
   open(`Revisar ${result.count} cópia(s) redundante(s)`,'duplicates_cleanup',
     `<p class="vip-note">Apenas assinaturas CANCELADAS exatamente iguais a outras (cliente, plano, período e valores), sem cortes, agendamentos, links ou solicitações vinculadas, podem ser excluídas. Nenhum cliente ou atendimento será apagado.</p>`+
     `<p class="vip-note">Antes de continuar, faça backup do MySQL na Hostinger. IDs elegíveis: ${result.ids.map(Number).join(', ')}.</p>`+
     `<label class="vip-wide">Digite EXCLUIR<input type="text" name="confirmation" autocomplete="off" placeholder="EXCLUIR" required pattern="EXCLUIR"></label>`+
     `<label class="vip-wide">Senha do administrador principal<input name="admin_password" type="password" autocomplete="current-password" required></label>`);
  }catch(e){alertMsg(e.message,true);}
 });
 $('vip-link-copy').addEventListener('click',async()=>{
  const txt=$('vip-link-url').value;
  try{await navigator.clipboard.writeText(txt);$('vip-link-copy').textContent='Link copiado ✓';}
  catch(_){$('vip-link-url').select();$('vip-link-copy').textContent='Copie o link selecionado';}
 });
 document.querySelectorAll('[data-vip-link-close]').forEach(b=>b.addEventListener('click',()=>$('vip-link-dialog').close()));
 $('vip-link-dialog').addEventListener('close',()=>{$('vip-link-url').value='';$('vip-link-copy').textContent='Copiar link';});
 async function clickAction(e){
  const btn=e.target.closest('button[data-act]');if(!btn)return;
  const id=Number(btn.dataset.id),task=btn.dataset.act;
  const member=state.memberships.find(m=>Number(m.id)===id);
  try{
   if(task==='request-approve'||task==='request-reject'){
    const request=(state.requests||[]).find(q=>Number(q.id)===id);
    if(!request)return;
    if(task==='request-reject'){
     if(!confirm(`Recusar a solicitação VIP de ${request.client_name}? O cadastro será preservado para consulta.`))return;
     await api('request_decide',{id,decision:'reject'});await refresh();alertMsg('Solicitação recusada.');return;
    }
    open(`Ativar VIP · ${request.client_name}`,'request_decide',
      `<input type="hidden" name="id" value="${id}"><input type="hidden" name="decision" value="approve">`+
      `<p class="vip-note">Cliente: <strong>${esc(request.client_name)}</strong> · WhatsApp: ${esc(request.client_phone)}. Plano: <strong>${esc(request.plan_name)}</strong> · ${money(request.monthly_price_cents)}, com ${Number(request.included_cuts)} atendimentos. Confirme a contratação e os valores diretamente com o cliente antes de ativar.</p>`+
      input('Início dos 30 dias da assinatura','starts_on',today,'date','required')+
      `<label class="vip-check vip-wide"><input type="checkbox" name="confirm_contract" value="yes" required> Confirmei a contratação e as condições com o cliente. Quero ativar a assinatura manualmente.</label>`+
      (Number(request.apple_signup)===1?`<label class="vip-check vip-wide"><input type="checkbox" name="confirm_apple_phone" value="yes" required> Confirmei diretamente que o WhatsApp pertence ao solicitante. A aprovação vinculará sua conta Apple à agenda VIP.</label>`:'')+
      (Number(request.google_signup)===1?`<label class="vip-check vip-wide"><input type="checkbox" name="confirm_google_phone" value="yes" required> Confirmei diretamente que o WhatsApp informado pertence ao solicitante. Entendo que aprovar vinculará sua conta Google à agenda VIP.</label>`:'')+
      `<p class="vip-note">O ciclo começa na data indicada (por padrão hoje) e termina 29 dias depois, completando 30 dias corridos. Se houver cadastro com o mesmo telefone, ele será reutilizado. A aprovação não registra pagamento automaticamente.</p>`);
    return;
   }
   if(task==='request-delete'){
     if(!state.owner)return;
     const request=(state.requests||[]).find(q=>Number(q.id)===id);if(!request)return;
     open(`Excluir solicitação · ${request.client_name}`,'request_delete',
       `<input name="id" type="hidden" value="${id}">`+
       `<p class="vip-note vip-delete-warning">A solicitação #${id} será excluída permanentemente. ${request.status==='approved'?'A assinatura já aprovada, os cortes e os agendamentos do cliente permanecerão intactos.':'Nenhum cliente ou assinatura será removido por esta ação.'} Faça backup antes de continuar.</p>`+
       `<label class="vip-wide">Digite EXCLUIR<input name="confirmation" type="text" autocomplete="off" required pattern="EXCLUIR" placeholder="EXCLUIR"></label>`+
       `<label class="vip-wide">Senha do administrador principal<input name="admin_password" type="password" autocomplete="current-password" required></label>`);
     return;
   }
   if(task==='member-delete'){
     if(!state.owner||!member)return;
     const result=await api('member_delete_preview',null,{id});
     if(!result.can_delete){
       const reasons=[];
       if(result.not_cancelled)reasons.push('cancele todas as assinaturas antes da exclusão');
       if(result.cuts||result.bookings)reasons.push('há cortes ou agendamentos vinculados que precisam ser preservados');
       if(!result.not_cancelled&&!result.cuts&&!result.bookings)reasons.push('o módulo de agenda VIP precisa estar instalado');
       alertMsg(`Não é possível excluir este assinante: ${reasons.join('; ')}. O cliente pode continuar no histórico com a assinatura cancelada.`,true);return;
     }
     open(`Excluir cadastro VIP · ${member.client_name}`,'member_delete',
       `<input name="id" type="hidden" value="${id}">`+
       `<p class="vip-note vip-delete-warning">Serão removidas ${result.memberships} assinatura(s) cancelada(s) do cadastro VIP deste cliente. O acesso VIP será revogado. Pedidos aprovados vinculados serão removidos; cadastro geral e lançamentos financeiros permanecerão. Esta ação não pode ser desfeita.</p>`+
       `<label class="vip-wide">Digite EXCLUIR<input name="confirmation" type="text" autocomplete="off" required pattern="EXCLUIR" placeholder="EXCLUIR"></label>`+
       `<label class="vip-wide">Senha do administrador principal<input name="admin_password" type="password" autocomplete="current-password" required></label>`);
     return;
   }
   if(task==='vip-book') {
     if(!member)return;
     const min=member.starts_on>today?member.starts_on:today;
     const horizon=new Date(today+'T12:00:00-03:00');horizon.setUTCDate(horizon.getUTCDate()+180);
     const maxDay=new Intl.DateTimeFormat('sv-SE',{timeZone:'America/Sao_Paulo'}).format(horizon);
     const max=member.ends_on<maxDay?member.ends_on:maxDay;
     if(min>max){alertMsg('A vigência está fora da janela de reserva de 180 dias. Corrija as datas contratadas ou aguarde a abertura do período.',true);return;}
     open(`Agendar VIP · ${member.client_name}`,'admin_reserve',
       `<input type="hidden" name="membership_id" value="${id}">`+
       `<p class="vip-note vip-wide">Plano: <strong>${esc(member.plan_name)}</strong> · ${esc(member.service_name||'Corte')} · Vigência: ${esc(member.starts_on)} a ${esc(member.ends_on)}. Saldo: ${Math.max(0,Number(member.cuts_per_month)-Number(member.used_cuts)-Number(member.reserved_cuts||0))} atendimento(s).</p>`+
       select('Barbeiro','barber_id',state.barbers||[])+
       input('Dia do corte','date',min,'date',`required min="${min}" max="${max}"`)+
       `<label class="vip-wide">Horário disponível<select name="time" id="vip-admin-book-time" required><option value="">Escolha barbeiro e data</option></select></label>`+
       `<p class="vip-note vip-wide" id="vip-admin-slot-hint" role="status">Escolha o barbeiro e o dia para consultar os horários livres.</p>`+
       `<p class="vip-note vip-wide">O atendimento será registrado no plano VIP, sem cobrança adicional. Após as 18h, dependerá da aprovação do barbeiro.</p>`);
     const timeSelect=form.elements.time, hint=$('vip-admin-slot-hint');
     let request=0;
     const updateSlots=async()=>{
       const run=++request,day=form.elements.date.value,barber=form.elements.barber_id.value;
       timeSelect.innerHTML='<option value="">Selecione um horário</option>';
       if(!day||!barber){hint.textContent='Selecione barbeiro e dia.';return;}
       hint.textContent='Consultando os horários livres…';
       try{
        const r=await api('admin_slots',null,{membership_id:id,date:day,barber_id:barber});
        if(run!==request||!dialog.open)return;
        timeSelect.innerHTML='<option value="">Selecione um horário</option>'+r.slots.map(s=>`<option value="${esc(s.time)}">${esc(s.time)}${s.requires_approval?' — Sob consulta':''}</option>`).join('');
        hint.textContent=r.slots.length?`${r.slots.length} horário(s) livre(s) nessa data.`:(r.message||'Nenhum horário livre. Escolha outro dia.');
       }catch(e){if(run===request)hint.textContent=e.message;}
     };
     form.elements.barber_id.addEventListener('change',updateSlots);
     form.elements.date.addEventListener('change',updateSlots);
     return;
   }
   if(task==='membership-dates') {
     if(!state.owner || !member)return;
     open(`Corrigir vigência · ${member.client_name}`,'membership_dates',
       `<input type="hidden" name="id" value="${id}">`+
       `<p class="vip-note vip-wide">Corrija somente se as datas do contrato estiverem erradas. Assinaturas de períodos futuros podem ser agendadas antecipadamente. Não serão apagados cortes, reservas ou pagamentos.</p>`+
       input('Início do ciclo de 30 dias','starts_on',member.starts_on,'date','required')+
       `<p class="vip-note vip-wide">O vencimento será calculado automaticamente (início + 29 dias). Se já houver agendamentos ou cortes fora do novo período, a alteração será bloqueada para proteger os registros.</p>`+
       `<label class="vip-wide">Senha do administrador principal<input name="admin_password" type="password" autocomplete="current-password" required></label>`);
     return;
   }
   if(task==='password-set'){
     if(!state.owner || !member)return;
     open(`Alterar senha VIP · ${member.client_name}`,'password_set',
       `<input type="hidden" name="membership_id" value="${id}">`+
       `<p class="vip-note">A senha anterior nunca é exibida. Ao salvar, o cliente será desconectado, os links antigos perderão validade e ele precisará criar uma senha pessoal no próximo login.</p>`+
       `<label class="vip-wide">Nova senha temporária<input name="new_password" type="password" autocomplete="new-password" minlength="10" maxlength="128" required></label>`+
       `<label class="vip-wide">Confirme a senha temporária<input name="confirm_password" type="password" autocomplete="new-password" minlength="10" maxlength="128" required></label>`+
       `<label class="vip-wide">Sua senha de administrador<input name="admin_password" type="password" autocomplete="current-password" required maxlength="256"></label>`+
       `<p class="vip-note">Compartilhe a senha temporária com o cliente por um canal privado, sem publicá-la em grupos ou no site.</p>`
     );return;
   }
   if(task==='share-link'){await generateLink(id);return;}
   if(task==='reset-login'){
     if(!confirm(`Redefinir o login VIP de ${member.client_name}? Isso revogará as sessões atuais e o link de agendamento anterior. Envie o novo link apenas ao cliente.`))return;
     const r=await api('login_reset',{membership_id:id});showPrivateLink(r);return;
   }
   if(task==='edit-plan'){editPlan(state.plans.find(p=>Number(p.id)===id));return;}
   if(task==='renew'){
     const next=new Date(member.ends_on+'T12:00:00-03:00');next.setUTCDate(next.getUTCDate()+1);
     const following=new Intl.DateTimeFormat('sv-SE',{timeZone:'America/Sao_Paulo'}).format(next);
     newMember(member.client_id,member.ends_on>=today?following:today);return;
   }
   if(task==='use'){
    open(`Registrar corte · ${member.client_name}`,'use_create',`<input type="hidden" name="membership_id" value="${id}">`+input('Data do corte realizado','used_on',today,'date',`required min="${esc(member.starts_on)}" max="${esc(today<member.ends_on?today:member.ends_on)}"`)+`<label class="vip-wide">Observações<textarea name="notes" maxlength="200" rows="2"></textarea></label><p class="vip-note">Saldo para agendamento: ${Math.max(0,Number(member.cuts_per_month)-Number(member.used_cuts)-Number(member.reserved_cuts||0))} corte(s). Registre somente atendimentos realmente realizados.</p>`);return;
   }
   if(task==='subscriptions-history'){
    if(!member)return;
    const items=state.memberships.filter(m=>String(m.client_id)===String(member.client_id)&&Number(m.id)!==id);
    const html=items.map(m=>`<li><strong>${esc(m.plan_name)}</strong> · ${esc(m.starts_on)} a ${esc(m.ends_on)} · ${statusLabels[status(m)]||esc(m.status)} · ID ${Number(m.id)}</li>`).join('');
    open(`Assinaturas anteriores · ${member.client_name}`,'none',`<p class="vip-note">Renovações e registros cancelados não são necessariamente duplicatas. Consulte os vínculos antes de removê-los.</p><ul class="vip-usage-history">${html||'<li>Sem outras assinaturas.</li>'}</ul>`);
    form.querySelector('button[type=submit]').hidden=true;return;
   }
   if(task==='status'){
    const status=btn.dataset.value,verb={paused:'pausar',active:'reativar',cancelled:'cancelar'}[status];
    if(!confirm(`Deseja ${verb} a assinatura de ${member.client_name}?`))return;
    await api('membership_status',{id,status});await refresh();alertMsg('Situação da assinatura atualizada.');return;
   }
   if(task==='history'){
    const result=await api('uses',null,{membership_id:id});
    const rows=result.uses.map(u=>`<li><strong>${esc(u.used_on)}</strong> — ${esc(u.notes||'Corte registrado')}</li>`).join('');
    open(`Histórico · ${member.client_name}`,'none',`<p>${Number(member.used_cuts)} de ${Number(member.cuts_per_month)} cortes utilizados.</p><ul class="vip-usage-history">${rows||'<li>Nenhum corte registrado.</li>'}</ul>`);form.querySelector('button[type=submit]').hidden=true;return;
   }
  }catch(err){alertMsg(err.message,true);}
 }
 form.addEventListener('submit',async e=>{
  e.preventDefault();if(action==='none')return;
  if(action==='admin_reserve' && !confirm('Confirmar o agendamento VIP no horário escolhido? Ele utilizará um atendimento do plano.'))return;
  const submit=form.querySelector('[type=submit]');submit.disabled=true;$('vip-dialog-error').hidden=true;
  try{
   const obj=Object.fromEntries(new FormData(form));if(action==='plan_save')obj.active=Boolean(form.elements.active?.checked);
   const response=await api(action,obj);
   if(['request_delete','member_delete'].includes(action)){dialog.close();await refresh();alertMsg(response.message||'Exclusão realizada.');return;}
   if(action==='duplicates_cleanup'){
     dialog.close();await refresh();alertMsg(response.message||`${response.deleted} cópia(s) removida(s).`);return;
   }
   if(action==='membership_create'&&response.id&&state.owner&&state.agenda_ready&&confirm('Assinatura criada! Deseja gerar um link VIP pessoal para enviar ao cliente pelo WhatsApp?')){await generateLink(Number(response.id));}
   if(action==='password_set'){
     form.reset();dialog.close();alertMsg('Senha temporária definida com sucesso. O cliente deve entrar em /vip-entrar.php com WhatsApp e senha temporária e escolher uma nova senha.');
     return;
   }
   dialog.close();await refresh();alertMsg(response.message||'Dados VIP salvos com sucesso.');
   if(action==='request_decide' && obj.decision==='approve' && state.owner && state.agenda_ready){
    if(confirm('Assinatura ativada! Deseja gerar agora o link pessoal para enviar ao cliente pelo WhatsApp?'))await generateLink(Number(response.membership_id));
   }
  }catch(err){$('vip-dialog-error').textContent=err.message;$('vip-dialog-error').hidden=false;}
  finally{submit.disabled=false;}
 });
 dialog.addEventListener('close',()=>{form.querySelector('button[type=submit]').hidden=false;});
 document.querySelectorAll('[data-vip-close]').forEach(b=>b.addEventListener('click',()=>dialog.close()));
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&dialog.open)dialog.close();});
 $('vip-request-rows').addEventListener('click',clickAction); $('vip-member-rows').addEventListener('click',clickAction);$('vip-plan-cards').addEventListener('click',clickAction);
 $('vip-new-plan')?.addEventListener('click',()=>editPlan());$('vip-new-member').addEventListener('click',()=>newMember('',today,false,true));$('vip-new-custom')?.addEventListener('click',()=>newMember('',today,true));
 $('vip-search').addEventListener('input',render);$('vip-filter').addEventListener('change',render);
 refresh().catch(err=>alertMsg(err.message,true));
})();
