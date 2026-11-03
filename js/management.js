'use strict';
(()=>{
  const {api,escape:esc,money,note}=window.Martelli;
  const message=document.getElementById('management-message');
  const today=new Intl.DateTimeFormat('sv-SE',{timeZone:'America/Sao_Paulo'}).format(new Date());
  const labels={confirmed:'Confirmados',pending:'Pendentes',completed:'Concluídos',cancelled:'Cancelados',no_show:'Faltas'};
  let report=null;
  document.querySelectorAll('input[type=date]').forEach(el=>el.value=today);
  const table=(heads,rows)=>`<div class="table-scroll"><table class="data-table"><thead><tr>${heads.map(v=>`<th scope="col">${esc(v)}</th>`).join('')}</tr></thead><tbody>${rows||`<tr><td colspan="${heads.length}">Nenhum registro.</td></tr>`}</tbody></table></div>`;
  async function refresh(){
    const data=await api('management_list');
    for(const [key,items] of [['barber_id',data.barbers],['service_id',data.services]]) {
      document.querySelectorAll(`select[name=${key}]`).forEach(el=>{
        const selected=el.value;
        el.innerHTML='<option value="">Selecione</option>'+items.filter(v=>Number(v.active)).map(v=>`<option value="${Number(v.id)}">${esc(v.name)}</option>`).join('');
        el.value=selected;
      });
    }
    const name=(items,id)=>items.find(v=>Number(v.id)===Number(id))?.name||'Cadastro indisponível';
    const remove=(r,kind)=>`<button type="button" class="button button-outline" data-remove="${esc(r.id)}" data-kind="${kind}">Remover</button>`;
    data.waitlist.sort((a,b)=>a.date.localeCompare(b.date)||a.created_at.localeCompare(b.created_at));
    data.blocks.sort((a,b)=>a.starts_at.localeCompare(b.starts_at));
    document.getElementById('wait-list').innerHTML=table(['Data','Cliente','Telefone','Profissional / serviço','Preferências','Ação'],data.waitlist.map(r=>`<tr><td>${esc(r.date)}</td><td>${esc(r.name)}</td><td>${esc(r.phone)}</td><td>${esc(name(data.barbers,r.barber_id))} / ${esc(name(data.services,r.service_id))}</td><td>${esc(r.notes)}</td><td>${remove(r,'wait')}</td></tr>`).join(''));
    document.getElementById('block-list').innerHTML=table(['Início','Fim','Profissional','Motivo','Ação'],data.blocks.map(r=>`<tr><td>${esc(r.starts_at)}</td><td>${esc(r.ends_at)}</td><td>${esc(name(data.barbers,r.barber_id))}</td><td>${esc(r.notes)}</td><td>${remove(r,'block')}</td></tr>`).join(''));
  }
  for(const kind of ['wait','block']) document.getElementById(kind+'-form').addEventListener('submit',async e=>{
    e.preventDefault(); const form=e.currentTarget,button=form.querySelector('button');button.disabled=true;
    try { await api('management_add',{...Object.fromEntries(new FormData(form)),kind});form.reset();form.elements.date.value=today;await refresh();note(message,'Registro adicionado.'); }
    catch(error){note(message,error.message,true);}finally{button.disabled=false;}
  });
  document.addEventListener('click',async e=>{
    const button=e.target.closest('[data-remove]');if(!button)return;
    if(!confirm('Remover este registro? A remoção de um bloqueio libera o período para novas reservas.'))return;
    button.disabled=true;
    try{await api('management_remove',{id:button.dataset.remove,kind:button.dataset.kind});await refresh();note(message,'Registro removido.');}
    catch(error){note(message,error.message,true);button.disabled=false;}
  });
  const csvButton=document.getElementById('report-csv');
  if (csvButton && document.getElementById('report-form')) {
  document.getElementById('report-form').addEventListener('submit',async e=>{
    e.preventDefault();const form=e.currentTarget,button=form.querySelector('button');button.disabled=true;csvButton.hidden=true;report=null;
    const params=Object.fromEntries(new FormData(form));
    try{
      report={...await api('management_report',null,params),...params};
      document.getElementById('report-results').innerHTML=`<h3>${esc(report.from)} até ${esc(report.to)}</h3>`+table(['Situação','Quantidade','Valor de serviços'],report.summary.map(r=>`<tr><td>${esc(labels[r.status]||r.status)}</td><td>${Number(r.quantity)}</td><td>${money(r.amount)}</td></tr>`).join(''))+'<h3>Serviços concluídos por profissional</h3>'+table(['Profissional','Quantidade','Valor de serviços'],report.professionals.map(r=>`<tr><td>${esc(r.name)}</td><td>${Number(r.quantity)}</td><td>${money(r.amount)}</td></tr>`).join(''));csvButton.hidden=false;
    }catch(error){document.getElementById('report-results').textContent='Consulta indisponível.';note(message,error.message,true);}finally{button.disabled=false;}
  });
  csvButton.addEventListener('click',()=>{
    if(!report)return;
    const cell=v=>'"'+String(v).replace(/^[=+@\-\t\r\n]/,"'$&").replaceAll('"','""')+'"';
    const rows=[['Período',report.from,report.to],['Situação','Quantidade','Valor de serviços'],...report.summary.map(r=>[labels[r.status]||r.status,r.quantity,r.amount]),[],['Profissional (concluídos)','Quantidade','Valor de serviços'],...report.professionals.map(r=>[r.name,r.quantity,r.amount])];
    const url=URL.createObjectURL(new Blob(['\uFEFF'+rows.map(r=>r.map(cell).join(';')).join('\r\n')],{type:'text/csv;charset=utf-8'}));
    const a=document.createElement('a');a.href=url;a.download=`resumo-${report.from}-${report.to}.csv`;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
  });
  }
  refresh().catch(error=>note(message,error.message,true));
})();
