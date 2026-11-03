/* Martelli V46 - solicitação segura de recuperação e redefinição de senha VIP. */
'use strict';
(()=>{
 const csrf=window.MARTELLI_VIP_CSRF;
 async function post(action,data){
  const response=await fetch('/api/vip-login.php?action='+action,{
    method:'POST',credentials:'same-origin',cache:'no-store',referrerPolicy:'no-referrer',
    headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data)
  });
  const result=await response.json().catch(()=>({error:'Erro ao processar a solicitação.'}));
  if(!response.ok||!result.ok)throw Error(result.error||'Não foi possível concluir.');
  return result;
 }
 const request=document.getElementById('vip-email-recovery');
 if(request){
  request.addEventListener('submit',async ev=>{
   ev.preventDefault();
   const b=request.querySelector('[type="submit"]');
   const fb=document.getElementById('vip-recovery-feedback');
   b.disabled=true;fb.hidden=true;
   try{
    const response=await post('request_reset',{email:document.getElementById('vip-recovery-email').value.trim()});
    fb.textContent=response.message;fb.hidden=false;request.reset();
   }catch(e){fb.textContent=e.message;fb.hidden=false;}
   finally{b.disabled=false;}
  });
 }
 const reset=document.getElementById('vip-email-new-password');
 if(reset){
  reset.addEventListener('submit',async ev=>{
   ev.preventDefault();
   const fb=document.getElementById('vip-reset-feedback');
   const b=reset.querySelector('[type="submit"]');
   const password=document.getElementById('vip-reset-password').value;
   const confirm=document.getElementById('vip-reset-confirm').value;
   if(password!==confirm){fb.textContent='As senhas não coincidem.';fb.hidden=false;return;}
   b.disabled=true;fb.hidden=true;
   try{
    const result=await post('reset_password',{
      token:new URLSearchParams(location.search).get('token')||'',new_password:password,confirm_password:confirm
    });
    location.assign(result.redirect||'/vip-entrar.php?senha=redefinida');
   }catch(e){fb.textContent=e.message;fb.hidden=false;b.disabled=false;}
  });
 }
})();
