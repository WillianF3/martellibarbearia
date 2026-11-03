 'use strict';
(()=>{
 const {api,escape:esc}=window.Martelli;
 const form=document.getElementById('staff-form');
 const list=document.getElementById('staff-list');
 const select=document.getElementById('staff-barber');
 const msg=document.getElementById('staff-message');
 let accounts=[];let barbers=[];
 function message(text,bad=false){msg.textContent=text;msg.hidden=false;msg.style.borderColor=bad?'#af5454':'#896c43';}
 function clear(){form.reset();select.disabled=false;form.elements.can_book.checked=true;msg.hidden=true;document.getElementById('staff-form-title').textContent='Criar / editar acesso';}
 async function load(){
   try{const data=await api('staff_accounts');accounts=data.accounts;barbers=data.barbers;
    select.innerHTML='<option value="">Selecione...</option>'+barbers.filter(b=>Number(b.active)).map(b=>`<option value="${Number(b.id)}">${esc(b.name)}</option>`).join('');
    list.innerHTML=accounts.length?accounts.map(a=>`<div class="restricted-account"><div><strong>${esc(a.barber_name)}</strong><small>${esc(a.email)} · <span class="${Number(a.active)?'':'status-disabled'}">${Number(a.active)?'Ativo':'Desativado'}</span> · ${a.can_book?'Pode agendar':'Somente consulta'}</small></div><div><button class="restricted-outline" type="button" data-edit="${Number(a.barber_id)}">Editar</button><button class="restricted-outline" type="button" data-toggle="${Number(a.id)}" data-active="${Number(a.active)?0:1}">${Number(a.active)?'Desativar':'Ativar'}</button></div></div>`).join(''):'<p class="restricted-empty">Ainda não existem acessos de barbeiros.</p>';
   }catch(e){list.textContent=e.message;}
 }
 list.addEventListener('click',async e=>{
  const edit=e.target.closest('[data-edit]');if(edit){const a=accounts.find(x=>Number(x.barber_id)===Number(edit.dataset.edit));if(!a)return;clear();select.value=String(a.barber_id);form.elements.email.value=a.email;form.elements.password.value='';form.elements.can_book.checked=Boolean(a.can_book);document.getElementById('staff-form-title').textContent='Editar acesso: '+a.barber_name;form.elements.email.focus();return;}
  const toggle=e.target.closest('[data-toggle]');if(!toggle)return;
  const pw=prompt('Confirme sua senha de administrador para ativar/desativar o acesso:');if(pw===null)return;
  toggle.disabled=true;
  try{await api('staff_account_status',{id:Number(toggle.dataset.toggle),active:Number(toggle.dataset.active),admin_password:pw});await load();message('Acesso atualizado.');}catch(err){message(err.message,true);}finally{toggle.disabled=false;}
 });
 form.addEventListener('submit',async e=>{
  e.preventDefault();const btn=form.querySelector('[type="submit"]');btn.disabled=true;
  const data={barber_id:Number(select.value),email:form.elements.email.value,password:form.elements.password.value,can_book:form.elements.can_book.checked?1:0,admin_password:form.elements.admin_password.value};
  try{const out=await api('staff_account_save',data);clear();await load();message(out.message);}catch(err){message(err.message,true);}finally{btn.disabled=false;form.elements.admin_password.value='';form.elements.password.value='';}
 });
 document.getElementById('staff-clear').addEventListener('click',clear);
 load();
})();
