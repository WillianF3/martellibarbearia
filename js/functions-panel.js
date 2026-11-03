"use strict";
(()=>{
 const list=document.getElementById('functions-list'),message=document.getElementById('functions-message'),count=document.getElementById('functions-count');
 const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const notify=(txt,error=false)=>{message.textContent=txt;message.hidden=false;message.classList.toggle('is-error',error)};
 async function call(payload){
  const opts={credentials:'same-origin',cache:'no-store'};
  if(payload){opts.method='POST';opts.headers={'Content-Type':'application/json','X-CSRF-Token':window.MARTELLI_CSRF};opts.body=JSON.stringify(payload)}
  const response=await fetch('/api/features.php',opts);
  const data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'Não foi possível carregar as funções.');return data;
 }
 function updateCount(){const controls=[...list.querySelectorAll('input[type=checkbox]')];count.textContent=`${controls.filter(el=>el.checked).length} de ${controls.length} ativas`}
 async function load(){
  try{
   const data=await call();
   list.innerHTML=data.features.map(item=>`<div class="functions-row"><div><h3>${esc(item.title)}</h3><p>${esc(item.description)}</p></div><label class="functions-switch"><input type="checkbox" data-feature="${esc(item.key)}" ${item.enabled?'checked':''} aria-label="${esc(item.title)}"><span data-feature-label>${item.enabled?'Ativada':'Desativada'}</span></label></div>`).join('');
   list.removeAttribute('aria-busy');updateCount();
  }catch(error){list.removeAttribute('aria-busy');list.textContent='Não foi possível carregar as configurações.';notify(error.message,true)}
 }
 list.addEventListener('change',async event=>{
  const input=event.target.closest('input[data-feature]');if(!input)return;
  const desired=input.checked,key=input.dataset.feature;
  if(!desired && !confirm('Desativar este módulo para os usuários administrativos? Os registros serão preservados.')){input.checked=true;return;}
  input.disabled=true;
  try{const result=await call({key,enabled:desired});input.closest('label').querySelector('[data-feature-label]').textContent=result.enabled?'Ativada':'Desativada';updateCount();notify(result.message)}
  catch(error){input.checked=!desired;notify(error.message,true)}
  finally{input.disabled=false;updateCount()}
 });
 load();
})();
