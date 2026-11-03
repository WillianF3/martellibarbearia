'use strict';
(()=>{
 const esc=window.Martelli.escape,view=document.getElementById('biz-view'),message=document.getElementById('biz-message'),dialog=document.getElementById('biz-dialog'),form=document.getElementById('biz-form'),fields=document.getElementById('biz-fields'),period=document.getElementById('biz-period');
 const money=v=>(Number(v||0)/100).toLocaleString('pt-BR',{style:'currency',currency:'BRL'}),decimal=v=>(Number(v||0)/100).toFixed(2),today=new Intl.DateTimeFormat('sv-SE',{timeZone:'America/Sao_Paulo'}).format(new Date());
 const titles={comandas:'Comandas',financeiro:'Financeiro',dashboard:'Dashboard',relatorios:'Relatórios',configuracoes:'Configurações',caixa:'Caixa e histórico',movimentos:'Entradas e saídas',comissoes:'Comissões',contas:'Conta dos clientes',estoque:'Produtos e estoque'};
 const methods={cash:'Dinheiro',pix:'PIX',credit:'Cartão de crédito',debit:'Cartão de débito',transfer:'Transferência'},statuses={open:'Aberta',closed:'Fechada',cancelled:'Cancelada'},appointments={confirmed:'Confirmados',completed:'Concluídos',cancelled:'Cancelados',pending:'Pendentes',no_show:'Faltas'},categories={order:'Comandas',refund:'Estornos de comandas',commission:'Comissões',reversal:'Estornos',other:'Outros',supplies:'Insumos',rent:'Aluguel',withdrawal:'Retirada',deposit:'Suprimento',tip:'Caixinha',advance:'Vale / adiantamento'};
 let catalog={clients:[],services:[],barbers:[],products:[]},section='dashboard',page=1,status='open',commissionStatus='pending',barber='',selectedOrder=0,report=null,data=null,revision=0,formAction='',ready=false;
 period.elements.from.value=today.slice(0,7)+'-01';period.elements.to.value=today;
 const params=()=>({...Object.fromEntries(new FormData(period)),page});
 const uuid=()=>crypto.randomUUID();
 async function api(action,payload=null,query={}){
  const url=new URL('/api/business.php',location.origin);url.searchParams.set('action',action);Object.entries(query).forEach(([k,v])=>url.searchParams.set(k,String(v)));
  let r;try{r=await fetch(url,{method:payload===null?'GET':'POST',credentials:'same-origin',headers:payload===null?{}:{'Content-Type':'application/json','X-CSRF-Token':window.MARTELLI_CSRF},body:payload===null?undefined:JSON.stringify(payload)});}catch{throw Error('Sem conexão. Confira o histórico antes de repetir uma operação.');}
  let d;try{d=await r.json();}catch{throw Error('Resposta inesperada do servidor.');}if(!r.ok||!d.ok)throw Error(d.error||'Operação não concluída.');return d;
 }
 function notice(text,error=false){message.hidden=false;message.className=error?'error':'';message.textContent=text;}
 const btn=(label,action,id='',extra='')=>`<button type="button" data-action="${action}" data-id="${esc(id)}" ${extra}>${esc(label)}</button>`;
 const table=(heads,rows)=>`<div class="biz-table-wrap"><table class="biz-table"><thead><tr>${heads.map(h=>`<th scope="col">${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows||`<tr><td class="biz-empty" colspan="${heads.length}">Nenhum registro encontrado.</td></tr>`}</tbody></table></div>`;
 const panel=(title,body,actions='')=>`<section class="biz-panel"><div class="biz-toolbar"><h2>${esc(title)}</h2><div class="biz-actions">${actions}</div></div>${body}</section>`;
 const cards=items=>`<div class="biz-cards">${items.map(([label,value,hint])=>`<article class="biz-card"><span>${esc(label)}</span><strong>${esc(value)}</strong><small>${esc(hint||'')}</small></article>`).join('')}</div>`;
 const selectOptions=(items,value='',blank='Selecione')=>`<option value="">${esc(blank)}</option>`+items.map(i=>`<option value="${esc(i.id)}" ${String(i.id)===String(value)?'selected':''}>${esc(i.name)}</option>`).join('');
 const field=(label,name,value='',type='text',extra='')=>`<label>${esc(label)}<input name="${name}" type="${type}" value="${esc(value)}" ${extra}></label>`;
 const select=(label,name,items,value='',blank='Selecione')=>`<label>${esc(label)}<select name="${name}" required>${selectOptions(items,value,blank)}</select></label>`;
 const payment=()=>select('Forma de pagamento','method',Object.entries(methods).map(([id,name])=>({id,name})),'cash');
 const amount=(label='Valor',value='')=>field(label,'amount',value,'number','required min="0" max="9999999.99" step="0.01"');
 const hidden=(name,value)=>`<input type="hidden" name="${name}" value="${esc(value)}">`;
 const pager=d=>`<div class="biz-pagination">${btn('Anterior','prev','',d.page<=1?'disabled':'')}<span>Página ${d.page} de ${Math.max(1,Math.ceil(d.total/100))} · ${d.total} registros</span>${btn('Próxima','next','',d.page*100>=d.total?'disabled':'')}</div>`;
 function openForm(title,action,html){formAction=action;document.getElementById('biz-dialog-title').textContent=title;fields.innerHTML=html+hidden('request_key',uuid());document.getElementById('biz-form-error').hidden=true;dialog.showModal();}
 async function refreshCatalog(){catalog=await api('catalog');}
 async function render(){
  const seq=++revision;section=location.hash.slice(1)||'dashboard';if(!titles[section] || !window.MartelliAllowedSection?.(section))section=Object.keys(titles).find(key=>window.MartelliAllowedSection?.(key))||'dashboard';
  document.getElementById('biz-title').textContent=titles[section];document.getElementById('biz-mobile-nav').value=section;
  document.querySelectorAll('[data-section]').forEach(a=>a.classList.toggle('active',a.dataset.section===section));
  period.hidden=['estoque','caixa','contas','configuracoes'].includes(section);view.innerHTML='<p>Carregando...</p>';
  try{
   if(!ready){const state=await api('status');if(seq!==revision)return;if(!state.ready){period.hidden=true;view.innerHTML=panel('Preparar módulos administrativos','<p>Esta atualização adiciona tabelas para comandas, caixa, estoque e auditoria. Faça backup do banco antes de preparar os módulos.</p>',(window.MARTELLI_OWNER?btn('Preparar módulos','install','','class="biz-primary"'):'<p>Solicite ao administrador principal que prepare os módulos.</p>'));return;}ready=true;await refreshCatalog();}
   if(seq!==revision)return;
   let response;
   // Quando o administrador entra pela ação COMANDA de um agendamento, abre
   // automaticamente a comanda correspondente, usando o cadastro já salvo.
   // POST idempotente: se existir, a API devolve a mesma comanda (sem duplicar itens).
   if(section==='comandas' && !selectedOrder){
    const link=new URL(location.href),appointmentId=link.searchParams.get('appointment_id');
    if(appointmentId!==null){
     // Remover o parâmetro só após sucesso; se a API falhar, o link permite tentar novamente.
     const appointmentNumber=Number(appointmentId);
     if(!/^\d+$/.test(appointmentId)||!Number.isSafeInteger(appointmentNumber)||appointmentNumber<1){
      notice('Identificador do agendamento inválido.',true);
     }else{
      response=await api('order_create',{appointment_id:appointmentNumber});
      if(seq!==revision)return;
      selectedOrder=Number(response.id);
      link.searchParams.delete('appointment_id');history.replaceState(null,'',link);
      notice('Comanda carregada automaticamente com os dados do agendamento.');
     }
    }
   }
   if(section==='comandas'&&selectedOrder){response=await api('order',null,{id:selectedOrder});if(seq!==revision)return;data=response;renderOrder(response.order);return;}
   const actions={comandas:'orders',financeiro:'report',dashboard:'report',relatorios:'report',caixa:'cash',movimentos:'ledger',comissoes:'commissions',contas:'accounts',estoque:'products',configuracoes:'settings'};
   response=await api(actions[section],null,{...params(),status:section==='comissoes'?commissionStatus:status,barber_id:barber,client_id:clientFilter});if(seq!==revision)return;data=response;
   if(['dashboard','financeiro','relatorios'].includes(section)){report={...response,range:{...params()}};renderReport(response);}
   else if(section==='comandas')renderOrders(response);
   else if(section==='caixa')renderCash(response);
   else if(section==='movimentos')renderLedger(response);
   else if(section==='estoque')renderStock(response);
   else if(section==='comissoes')renderCommissions(response);
   else if(section==='contas')view.innerHTML=panel('Saldos em aberto', '<p>Valores ainda não recebidos nas comandas abertas, em todas as datas. O pagamento é registrado dentro de cada comanda.</p>'+table(['Cliente','Comandas','Saldo a receber',''],response.accounts.map(r=>`<tr><td>${esc(r.name)}</td><td>${r.orders}</td><td>${money(r.due_cents)}</td><td>${btn('Consultar comandas','client-orders',r.client_id||'')}</td></tr>`).join('')));
   else renderSettings(response);
  }catch(e){if(seq===revision){view.innerHTML='<p>Não foi possível carregar esta área.</p>';notice(e.message,true);}}
 }
 function renderReport(d){
  const f=d.financial,s=d.sales;
  let html=cards([['Entradas',money(f.income_cents),'Movimentações no período'],['Saídas',money(f.expense_cents),'Inclui estornos e comissões'],['Saldo do período',money(f.net_cents),'Entradas menos saídas'],['Comandas fechadas',String(s.quantity),`Total: ${money(s.total_cents)}`]]);
  html+=panel('Resumo da operação',cards([['Comandas abertas',d.open_orders,'Todas as datas'],['A receber',money(d.receivable_cents),'Saldo das comandas abertas'],['Ticket médio',money(Number(s.quantity)?Math.round(Number(s.total_cents)/Number(s.quantity)):0),'Comandas fechadas no período'],['Estoque baixo',d.low_stock.length,'Produtos no mínimo ou abaixo']]),btn('Exportar CSV','export')+btn('Imprimir','print'));
  const max=Math.max(1,...d.daily.map(r=>Math.abs(Number(r.amount_cents))));
  html+=`<div class="biz-grid">`+panel('Fluxo de caixa diário',d.daily.length?d.daily.map(r=>`<div class="biz-bar-row"><span>${esc(r.day)}</span><div class="biz-bar"><i class="${Number(r.amount_cents)<0?'negative':''}" style="width:${Math.abs(Number(r.amount_cents))/max*100}%"></i></div><span>${money(r.amount_cents)}</span></div>`).join(''):'<p>Sem movimentos neste período.</p>')+panel('Saldo por forma de pagamento',table(['Forma','Saldo líquido'],d.methods.map(r=>`<tr><td>${esc(methods[r.method]||r.method)}</td><td>${money(r.amount_cents)}</td></tr>`).join('')))+`</div>`;
  html+=panel('Alertas de estoque',table(['Produto','Em estoque','Mínimo'],d.low_stock.map(r=>`<tr><td>${esc(r.name)}</td><td>${r.stock}</td><td>${r.min_stock}</td></tr>`).join('')));
  if(section==='relatorios'){
   html+=panel('Financeiro por categoria',table(['Categoria','Saldo líquido'],d.categories.map(r=>`<tr><td>${esc(categories[r.category]||r.category)}</td><td>${money(r.amount_cents)}</td></tr>`).join('')));
   html+=panel('Agendamentos por situação','<p>Valores da agenda não comprovam pagamento. Recebimentos estão no financeiro.</p>'+table(['Situação','Quantidade','Valor dos serviços'],d.appointments.map(r=>`<tr><td>${esc(appointments[r.status]||r.status)}</td><td>${r.quantity}</td><td>${money(Number(r.amount)*100)}</td></tr>`).join('')));
   html+=panel('Atendimentos concluídos por profissional',table(['Profissional','Quantidade','Valor da agenda'],d.professionals.map(r=>`<tr><td>${esc(r.name||'Cadastro removido')}</td><td>${r.quantity}</td><td>${money(Number(r.amount)*100)}</td></tr>`).join('')));
   html+=panel('Itens vendidos em comandas fechadas', '<p>Valores brutos dos itens, antes dos descontos da comanda.</p>'+table(['Item','Quantidade','Valor bruto'],d.services.map(r=>`<tr><td>${esc(r.description)}</td><td>${r.quantity}</td><td>${money(r.gross_cents)}</td></tr>`).join('')));
  }
  view.innerHTML=html;
 }
 let clientFilter='';
 function renderOrders(d){
  const filter=`<label>Situação<select id="order-status">${[['open','Abertas (todas as datas)'],['closed','Fechadas'],['cancelled','Canceladas'],['all','Todas no período']].map(([v,l])=>`<option value="${v}" ${status===v?'selected':''}>${l}</option>`).join('')}</select></label>`;
  view.innerHTML=panel('Comandas',(clientFilter?'<p>Filtro por cliente ativo. '+btn('Mostrar todos','clear-client')+'</p>':'')+filter+table(['Número','Cliente','Criada em','Situação','Total','Recebido','Saldo',''],d.rows.map(r=>`<tr><td>#${r.id}</td><td>${esc(r.client_name||'Balcão')}</td><td>${esc(r.created_at)}</td><td><span class="biz-tag ${esc(r.status)}">${esc(statuses[r.status])}</span></td><td>${money(r.total_cents)}</td><td>${money(r.paid_cents)}</td><td>${money(r.status==='cancelled'?0:r.total_cents-r.paid_cents)}</td><td>${btn('Abrir','order-view',r.id)}</td></tr>`).join(''))+pager(d),btn('Nova comanda','order-new','','class="biz-primary"'));
 }
 function renderOrder(o){
  const editable=o.status==='open'&&Number(o.paid_cents)===0;
  const rows=o.items.map(i=>`<tr><td>${esc(i.description)}</td><td>${i.quantity}</td><td>${money(i.unit_cents)}</td><td>${money(i.quantity*i.unit_cents)}</td><td>${esc(i.barber_name||'—')}</td><td>${editable?btn('Remover','item-remove',i.id):''}</td></tr>`).join('');
  const phone=String(o.client_phone||'').replace(/\D/g,'');
  const phoneLink=phone?`<a href="https://wa.me/55${esc(phone)}" target="_blank" rel="noopener noreferrer">${esc(o.client_phone)} · WhatsApp ↗</a>`:'Não informado';
  const appointmentId=Number(o.appointment_id||0);
  const appointmentStatusLabels={pending:'Aguardando aprovação',confirmed:'Confirmado',completed:'Concluído',cancelled:'Cancelado',no_show:'Falta'};
  const appointmentInfo=appointmentId?`<div class="biz-booking-summary"><h3>Dados do agendamento #${appointmentId}</h3><dl>
   <div><dt>Cliente</dt><dd>${esc(o.client_name||'Não informado')}</dd></div>
   <div><dt>Telefone</dt><dd>${phoneLink}</dd></div>
   <div><dt>Data e horário</dt><dd>${esc(String(o.appointment_starts_at||'').replace(' ',' às ').slice(0,19))||'Não informado'}${o.appointment_ends_at?' · até '+esc(String(o.appointment_ends_at).slice(11,16)):''}</dd></div>
   <div><dt>Serviço reservado</dt><dd>${esc(o.appointment_service_name||'Não informado')}</dd></div>
   <div><dt>Profissional</dt><dd>${esc(o.appointment_barber_name||'Não informado')}</dd></div>
   <div><dt>Preço do agendamento</dt><dd>${o.appointment_price!==null&&o.appointment_price!==undefined?money(Math.round(Number(o.appointment_price)*100)):'Não informado'}</dd></div>
   <div><dt>Situação do agendamento</dt><dd>${esc(appointmentStatusLabels[o.appointment_status]||o.appointment_status||'Não informado')}</dd></div>
   ${o.appointment_notes?`<div class="wide"><dt>Observações do agendamento</dt><dd>${esc(o.appointment_notes)}</dd></div>`:''}
   ${o.client_notes?`<div class="wide"><dt>Observações do cadastro</dt><dd>${esc(o.client_notes)}</dd></div>`:''}
  </dl><small>Preenchido automaticamente a partir do cadastro e da agenda. O valor cobrado e os itens abaixo são os registrados na comanda.</small></div>`
  :`<div class="biz-booking-summary"><h3>Dados do cliente</h3><dl><div><dt>Cliente</dt><dd>${esc(o.client_name||'Balcão')}</dd></div><div><dt>Telefone</dt><dd>${phoneLink}</dd></div>${o.client_notes?`<div class="wide"><dt>Observações</dt><dd>${esc(o.client_notes)}</dd></div>`:''}</dl></div>`;
  const isOpen=o.status==='open';
  const hasItems=Array.isArray(o.items)&&o.items.length>0;
  const balance=Number(o.due_cents||0);
  const paymentReady=hasItems&&balance===0;
  const closingGuide=isOpen?`<div class="biz-order-flow" role="status" aria-label="Etapas para fechar a comanda">
    <h3>Para fechar esta comanda</h3>
    <div class="biz-order-flow-grid">
      <div class="${hasItems?'is-ok':'is-pending'}"><strong>${hasItems?'✓':'1.'} Itens da comanda</strong><span>${hasItems?`${o.items.length} item(ns) registrado(s)`:'Adicione o serviço ou produto realizado'}</span></div>
      <div class="${balance===0?'is-ok':'is-pending'}"><strong>${balance===0?'✓':'2.'} Pagamento</strong><span>${balance>0?`Falta receber ${money(balance)}`:balance===0?'Saldo quitado':'Saldo inconsistente: verifique os recebimentos'}</span></div>
      <div class="${paymentReady?'is-ok':'is-pending'}"><strong>3. Fechamento</strong><span>${paymentReady?'Pronta para fechar':'Após incluir itens e quitar o saldo'}</span></div>
    </div>
  </div>`:'';
  view.innerHTML=panel(`Comanda #${o.id} · ${o.client_name||'Balcão'}`,`<p><span class="biz-tag ${esc(o.status)}">${esc(statuses[o.status])}</span> · Criada em ${esc(o.created_at)}${appointmentId?' · Agendamento #'+appointmentId:''}</p>${appointmentInfo}${o.notes?`<p>Observações da comanda: ${esc(o.notes)}</p>`:''}`+table(['Item','Qtd.','Unitário','Subtotal','Profissional',''],rows)+`<div class="biz-detail-summary">Subtotal: ${money(o.subtotal_cents)} · Desconto: ${money(o.discount_cents)}<br><strong>Total: ${money(o.total_cents)}</strong> · Recebido: ${money(o.paid_cents)} · Saldo: ${money(o.due_cents)}</div>${closingGuide}<p>O estoque é baixado e as comissões são geradas no fechamento. O registro de pagamento não realiza uma cobrança bancária.</p>`,btn('Voltar','order-back')+(editable?btn('Adicionar item','item-new')+btn('Desconto','discount'):'')+(o.status==='open'?(balance>0?btn('Registrar pagamento','pay','','class="biz-primary"'):'')+btn('Fechar comanda','close-order','','class="biz-close-action"'):'')+(o.status!=='cancelled'?btn('Cancelar / estornar','cancel-order'):'')+btn('Imprimir','print'))+panel('Histórico de recebimentos',table(['Data','Descrição','Forma','Valor'],o.payments.map(p=>`<tr><td>${esc(p.created_at)}</td><td>${esc(p.description)}</td><td>${esc(methods[p.method])}</td><td>${money(p.amount_cents)}</td></tr>`).join('')));
 }
 // O caixa deve estar aberto para registrar recebimentos financeiros. Nunca dá baixa automaticamente.
 async function openOrderPayment(o,fromClose=false){
   const current=await api('cash');
   if(!current.cash){
     notice('Para registrar um recebimento é preciso abrir o caixa primeiro.',true);
     return openForm('Abrir caixa para receber','cash_open',amount('Dinheiro inicial do caixa','0.00')+'<p class="wide biz-form-note">Não há caixa aberto. Informe o valor inicial em dinheiro e salve. Depois volte à comanda e registre o pagamento recebido.</p>');
   }
   const due=Number(o.due_cents||0);
   if(due<=0)throw Error('Esta comanda não tem saldo pendente. Atualize e confira os recebimentos.');
   return openForm(fromClose?'Quitar comanda #'+o.id:'Registrar recebimento','order_pay',hidden('id',o.id)+amount('Valor efetivamente recebido',decimal(due))+payment()+`<p class="wide biz-form-note"><strong>Saldo pendente: ${money(due)}.</strong> Registre apenas o pagamento já recebido do cliente. Após quitar a comanda, clique em Fechar comanda. Nenhuma cobrança bancária é realizada automaticamente.</p>`);
 }
 function openOrderItem(o){
   return openForm('Adicionar item à comanda #'+o.id,'item_add',hidden('id',o.id)+select('Tipo','kind',[{id:'service',name:'Serviço'},{id:'product',name:'Produto'}],'service')+select('Item','reference_id',catalog.services.filter(x=>Number(x.active)))+select('Profissional','barber_id',catalog.barbers.filter(x=>Number(x.active)))+field('Quantidade','quantity','1','number','required min="1" max="1000"'));
 }
 function renderCash(d){
  let html=d.cash?panel('Caixa aberto #'+d.cash.id,cards([['Abertura',money(d.cash.opening_cents),d.cash.opened_at],['Dinheiro esperado',money(d.cash.balance_cents),'Abertura + entradas − saídas em dinheiro']])+table(['Forma','Movimento líquido'],d.methods.map(r=>`<tr><td>${esc(methods[r.method])}</td><td>${money(r.amount_cents)}</td></tr>`).join('')),btn('Encerrar caixa','cash-close',d.cash.id,'class="biz-primary"')+btn('Entrada / saída','ledger-new')):panel('Nenhum caixa aberto','<p>Informe o dinheiro inicial para começar a registrar movimentações.</p>',btn('Abrir caixa','cash-open','','class="biz-primary"'));
  html+=panel('Histórico de caixas (100 mais recentes)',table(['Caixa','Abertura','Fechamento','Inicial','Esperado (dinheiro)','Contado','Diferença'],d.history.map(c=>`<tr><td>#${c.id}</td><td>${esc(c.opened_at)}</td><td>${esc(c.closed_at||'Aberto')}</td><td>${money(c.opening_cents)}</td><td>${c.closed_at?money(c.expected_cents):'—'}</td><td>${c.closed_at?money(c.counted_cents):'—'}</td><td>${c.closed_at?money(c.counted_cents-c.expected_cents):'—'}</td></tr>`).join('')));view.innerHTML=html;
 }
 function renderLedger(d){view.innerHTML=panel('Movimentações do período',table(['Data','Caixa','Descrição','Categoria','Forma','Valor',''],d.rows.map(r=>`<tr><td>${esc(r.created_at)}</td><td>#${r.cash_id}</td><td>${esc(r.description)}</td><td>${esc(categories[r.category]||r.category)}</td><td>${esc(methods[r.method])}</td><td>${money(r.amount_cents)}</td><td>${!r.reversed_by&&!r.order_id&&!r.reversal_of&&!['reversal'].includes(r.category)?btn('Estornar',r.category==='commission'?'commission-reverse':'ledger-reverse',r.id):r.reversed_by?'Estornado':''}</td></tr>`).join(''))+pager(d),btn('Nova entrada / saída','ledger-new','','class="biz-primary"'));}
 function renderStock(d){view.innerHTML=panel('Produtos',table(['Produto','Preço','Estoque','Mínimo','Ativo',''],d.products.map(p=>`<tr><td>${esc(p.name)}</td><td>${money(p.price_cents)}</td><td>${p.stock}${Number(p.stock)<=Number(p.min_stock)?' · Baixo':''}</td><td>${p.min_stock}</td><td>${Number(p.active)?'Sim':'Não'}</td><td><div class="biz-actions">${btn('Editar','product-edit',p.id)}${btn('Ajustar estoque','stock-adjust',p.id)}</div></td></tr>`).join('')),btn('Novo produto','product-new','','class="biz-primary"'))+panel('Movimentos de estoque (100 mais recentes)',table(['Data','Produto','Quantidade','Motivo'],d.movements.map(r=>`<tr><td>${esc(r.created_at)}</td><td>${esc(r.name)}</td><td>${r.delta}</td><td>${esc(r.reason)}</td></tr>`).join('')));}
 function renderCommissions(d){view.innerHTML=panel('Comissões de serviços',`<p>Calculadas sobre o valor líquido dos serviços após o desconto proporcional. A taxa é registrada ao incluir o serviço na comanda.</p><div class="biz-filter"><label>Profissional<select id="commission-barber">${selectOptions(catalog.barbers,barber,'Todos')}</select></label><label>Situação<select id="commission-status"><option value="pending" ${commissionStatus==='pending'?'selected':''}>Pendentes (todas as datas)</option><option value="all" ${commissionStatus==='all'?'selected':''}>Todas no período</option></select></label></div>`+table(['','Data','Profissional','Comanda','Comissão','Situação'],d.rows.map(r=>`<tr><td>${!r.paid_ledger_id?`<input type="checkbox" name="commission-check" value="${r.id}" aria-label="Selecionar comissão ${r.id}">`:''}</td><td>${esc(r.created_at)}</td><td>${esc(r.name||'Profissional #'+r.barber_id)}</td><td>#${r.order_id}</td><td>${money(r.amount_cents)}</td><td>${r.paid_ledger_id?'Paga':'Pendente'}</td></tr>`).join(''))+pager(d),btn('Registrar pagamento selecionado','commission-pay','','class="biz-primary"'));}
 function renderSettings(d){const rates=Object.fromEntries(d.rates.map(r=>[r.key,r.value]));view.innerHTML=panel('Comissão por profissional','<p>Defina a porcentagem aplicada a novos itens de serviço. Itens já incluídos mantêm a taxa registrada.</p>'+`<form id="rates-form" class="biz-form-grid">${catalog.barbers.map(b=>field(b.name,String(b.id),decimal(rates['biz:commission:'+b.id]||0),'number','min="0" max="100" step="0.01" required')).join('')}<button class="biz-primary" type="submit">Salvar comissões</button></form>`)+panel('Configurações já existentes','<p>Horários, serviços, equipe, administradores e configurações públicas continuam disponíveis no painel da agenda.</p><div class="biz-actions"><a href="/admin.php#configuracoes">Parâmetros do site</a><a href="/admin.php#horarios">Funcionamento</a><a href="/admin.php#administradores">Administradores</a><a href="/gestao.php">Lista de espera e bloqueios</a></div>')+(window.MARTELLI_OWNER?panel('Ativar ou desativar funções','<p>Controle os módulos da administração, como agenda, financeiro, cadastros, estoque e relatórios. As alterações são salvas no MySQL e não apagam registros.</p><div class="biz-actions"><a href="/funcoes.php">Abrir controles de funções ↗</a></div>'):'')+panel('Auditoria (100 eventos mais recentes)',table(['Data','Administrador','Evento','Registro','Detalhe'],d.audit.map(r=>`<tr><td>${esc(r.created_at)}</td><td>${esc(r.actor||'Administrador #'+r.actor_id)}</td><td>${esc(r.event)}</td><td>${r.entity_id?'#'+r.entity_id:'—'}</td><td>${esc(r.detail)}</td></tr>`).join('')));}
 document.addEventListener('click',async e=>{
  if(e.target.closest('[data-dialog-close]')){dialog.close();return;}
  const b=e.target.closest('[data-action]');if(!b)return;const a=b.dataset.action,id=Number(b.dataset.id||0),o=data?.order;
  try{
   if(a==='install')return openForm('Preparar módulos','install','<p class="wide">Confirme sua senha atual para adicionar as novas tabelas.</p>'+field('Sua senha','password','','password','required autocomplete="current-password"'));
   if(a==='prev'||a==='next'){page+=a==='prev'?-1:1;return render();}
   if(a==='order-view'){selectedOrder=id;return render();}if(a==='order-back'){selectedOrder=0;return render();}
   if(a==='client-orders'){clientFilter=b.dataset.id||'balcao';status='open';location.hash='comandas';return;}
   if(a==='clear-client'){clientFilter='';page=1;return render();}
   if(a==='order-new')return openForm('Nova comanda','order_create',select('Cliente (opcional)','client_id',catalog.clients,'','Balcão').replace(' required','')+field('Agendamento (opcional)','appointment_id','','number','min="1"')+field('Observações','notes','','text','maxlength="500"')+'<p class="wide">Se informar agendamento, serão usados os dados dele.</p>');
   if(a==='order-appointment')return openForm('Comanda do agendamento #'+id,'order_create',hidden('appointment_id',id)+'<p class="wide">Criar a comanda com os dados da agenda ou abrir a já vinculada.</p>');
   if(a==='item-new')return openOrderItem(o);
   if(a==='item-remove')return openForm('Remover item','item_remove',hidden('id',o.id)+hidden('item_id',id)+'<p class="wide">Remover este item da comanda aberta?</p>');
   if(a==='discount')return openForm('Desconto','order_discount',hidden('id',o.id)+amount('Desconto em reais',decimal(o.discount_cents)));
   if(a==='pay')return await openOrderPayment(o);
   if(a==='close-order'){
      if(o.status!=='open')throw Error('Somente comandas abertas podem ser fechadas.');
      if(!o.items?.length){
        if(Number(o.paid_cents)>0)throw Error('Há recebimentos em uma comanda sem itens. Verifique o lançamento antes do fechamento.');
        notice('A comanda ainda não tem itens. Adicione o serviço ou produto antes de fechar.',true);
        return openOrderItem(o);
      }
      if(Number(o.due_cents)>0)return await openOrderPayment(o,true);
      if(Number(o.due_cents)<0)throw Error('A comanda apresenta recebimentos acima do total. Regularize antes de fechar.');
      return openForm('Confirmar fechamento da comanda #'+o.id,'order_close',hidden('id',o.id)+`<p class="wide biz-form-note biz-ready-close"><strong>Saldo quitado: ${money(o.paid_cents)} recebido, ${money(o.due_cents)} pendente.</strong> Ao confirmar, o sistema registrará o fechamento, baixará os produtos do estoque e calculará as comissões. O agendamento confirmado vinculado também será marcado como concluído.</p>`);
    }
   if(a==='cancel-order')return openForm('Cancelar e registrar estorno','order_cancel',hidden('id',o.id)+field('Motivo','reason','','text','required maxlength="200"')+`<p class="wide">Estorno: ${money(o.paid_cents)}, nas formas originais, no caixa aberto. Produtos de comanda fechada retornam ao estoque. O agendamento é preservado. Registre após devolver o valor ao cliente; nenhuma transferência é automática.</p>`);
   if(a==='cash-open')return openForm('Abrir caixa','cash_open',amount('Dinheiro inicial','0.00'));
   if(a==='cash-close')return openForm('Encerrar caixa #'+id,'cash_close',hidden('id',id)+amount('Dinheiro contado')+`<p class="wide">Esperado: ${money(data.cash.balance_cents)}. Conte somente notas e moedas.</p>`);
   if(a==='ledger-new')return openForm('Entrada / saída','ledger_add',select('Movimento','direction',[{id:'in',name:'Entrada'},{id:'out',name:'Saída'}],'out')+amount()+payment()+select('Categoria','category',Object.entries(categories).filter(([k])=>['other','supplies','rent','withdrawal','deposit','tip','advance'].includes(k)).map(([id,name])=>({id,name})),'other')+field('Descrição / responsável','description','','text','required maxlength="200"')+'<p class="wide">Registre uma movimentação realizada. Recebimentos de comandas e pagamentos de comissões devem ser registrados nos módulos correspondentes.</p>');
   if(a==='ledger-reverse'||a==='commission-reverse')return openForm('Registrar estorno',a==='ledger-reverse'?'ledger_reverse':'commission_reverse',hidden('id',id)+field('Motivo','reason','','text','required maxlength="140"')+'<p class="wide">Um movimento contrário será registrado no caixa aberto. Registre após a devolução efetiva do valor.</p>');
   if(a==='product-new'||a==='product-edit'){
    const p=a==='product-edit'?data.products.find(p=>Number(p.id)===id):{id:0,name:'',price_cents:0,min_stock:0,active:1};
    return openForm(id?'Editar produto':'Novo produto','product_save',hidden('id',p.id)+field('Nome','name',p.name,'text','required maxlength="120"')+field('Preço','price',decimal(p.price_cents),'number','required min="0" step="0.01"')+field('Estoque mínimo','min_stock',p.min_stock,'number','required min="0" max="1000000"')+select('Situação','active',[{id:1,name:'Ativo'},{id:0,name:'Inativo'}],p.active));
   }
   if(a==='stock-adjust')return openForm('Ajustar estoque','stock_adjust',hidden('id',id)+field('Quantidade (negativa para saída)','delta','','number','required min="-1000000" max="1000000"')+field('Motivo','reason','','text','required maxlength="200"'));
   if(a==='commission-pay'){
    const ids=[...view.querySelectorAll('[name=commission-check]:checked')].map(el=>Number(el.value));if(!ids.length)throw Error('Selecione as comissões de um profissional.');
    const rows=data.rows.filter(r=>ids.includes(Number(r.id)));if(new Set(rows.map(r=>String(r.barber_id))).size!==1)throw Error('Selecione um profissional por pagamento.');
    return openForm('Registrar pagamento de comissões','commission_pay',hidden('ids',ids.join(','))+payment()+`<p class="wide">Total: ${money(rows.reduce((s,r)=>s+Number(r.amount_cents),0))}. Registre após pagar o profissional; o sistema não transfere valores.</p>`);
   }
   if(a==='print')return window.print();if(a==='export')exportReport();
  }catch(e){notice(e.message,true);}
 });
 form.addEventListener('change',e=>{if(formAction==='item_add'&&e.target.name==='kind'){const s=e.target.value==='service';form.elements.reference_id.innerHTML=selectOptions((s?catalog.services:catalog.products).filter(x=>Number(x.active)));form.elements.barber_id.required=s;form.elements.barber_id.disabled=!s;}});
 form.addEventListener('submit',async e=>{
  e.preventDefault();const button=form.querySelector('[type=submit]');button.disabled=true;const payload=Object.fromEntries(new FormData(form));if(formAction==='commission_pay')payload.ids=payload.ids.split(',').map(Number);
  const error=document.getElementById('biz-form-error');error.hidden=true;
  try{const r=await api(formAction,payload);dialog.close();notice(formAction==='order_pay'?'Recebimento registrado. Confira o saldo e, quando estiver zerado, clique em Fechar comanda.':formAction==='cash_open'?'Caixa aberto. Agora registre o pagamento efetivamente recebido na comanda.':'Operação registrada com sucesso.');if(formAction==='install')ready=false;else{if(formAction==='order_create')selectedOrder=r.id;await refreshCatalog();}await render();}
  catch(e){error.hidden=false;error.textContent=e.message;}finally{button.disabled=false;}
 });
 view.addEventListener('submit',async e=>{if(e.target.id!=='rates-form')return;e.preventDefault();const button=e.target.querySelector('button');button.disabled=true;try{await api('settings_save',{rates:Object.fromEntries(new FormData(e.target))});notice('Comissões atualizadas.');await render();}catch(e){notice(e.message,true);}finally{button.disabled=false;}});
 view.addEventListener('change',e=>{if(e.target.id==='order-status'){status=e.target.value;page=1;render();}if(e.target.id==='commission-status'){commissionStatus=e.target.value;page=1;render();}if(e.target.id==='commission-barber'){barber=e.target.value;page=1;render();}});
 period.addEventListener('submit',e=>{e.preventDefault();page=1;render();});document.getElementById('biz-today').addEventListener('click',()=>{period.elements.from.value=today;period.elements.to.value=today;page=1;render();});
 document.getElementById('biz-mobile-nav').addEventListener('change',e=>{const target=e.target.value;if(target.startsWith('url:/'))location.assign(target.slice(4));else location.hash=target;});window.addEventListener('hashchange',()=>{page=1;selectedOrder=0;render();});
 function exportReport(){
  if(!report)return;const r=report,range=r.range;
  const rows=[['Relatório Barbearia Martelli',range.from,range.to],['Indicador','Valor em reais'],['Entradas',decimal(r.financial.income_cents)],['Saídas',decimal(r.financial.expense_cents)],['Saldo líquido',decimal(r.financial.net_cents)],['Comandas fechadas',r.sales.quantity],['Total comandas fechadas',decimal(r.sales.total_cents)],[],['Forma','Saldo líquido'],...r.methods.map(x=>[methods[x.method],decimal(x.amount_cents)]),[],['Categoria','Saldo líquido'],...r.categories.map(x=>[categories[x.category]||x.category,decimal(x.amount_cents)]),[],['Dia','Saldo líquido'],...r.daily.map(x=>[x.day,decimal(x.amount_cents)]),[],['Agendamentos','Quantidade','Valor da agenda'],...r.appointments.map(x=>[appointments[x.status]||x.status,x.quantity,x.amount]),[],['Profissional','Concluídos','Valor da agenda'],...r.professionals.map(x=>[x.name,x.quantity,x.amount]),[],['Item vendido','Quantidade','Bruto antes dos descontos'],...r.services.map(x=>[x.description,x.quantity,decimal(x.gross_cents)])];
  const cell=v=>'"'+String(v??'').replace(/^(\s*[=+@\-\t\r\n])/u,"'$1").replaceAll('"','""')+'"';const url=URL.createObjectURL(new Blob(['\uFEFF'+rows.map(row=>row.map(cell).join(';')).join('\r\n')],{type:'text/csv;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download=`martelli-relatorio-${range.from}-${range.to}.csv`;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
 }
 render();
})();
