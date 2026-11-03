/* Martelli VIP V44: login, ativação e alteração em páginas separadas. */
'use strict';
(()=>{
 const $=id=>document.getElementById(id), params=new URLSearchParams(location.search);
 const feedback=$('vip-account-feedback');
 if(params.get('senha')==='redefinida'&&feedback){feedback.textContent='Senha alterada com sucesso. Entre com sua nova senha.';feedback.hidden=false;}
 const googleMessages={
  'confirmar-senha':'Sua conta Google foi verificada. Confirme a senha da conta VIP existente para vinculá-la com segurança.',
  'ativar-acesso':'O e-mail corresponde a um cadastro VIP que ainda não tem senha. Solicite seu link pessoal de ativação à barbearia.',
  'nao-vinculado':'Esta conta Google não está vinculada. Entre com seu WhatsApp e senha VIP para conectar as contas, ou solicite uma adesão.',
  'confirmar-conta':'Google verificado. Para vincular à conta VIP existente, informe seu WhatsApp e senha. Se você não tem senha, solicite seu link de ativação.',
  'conta-existente':'Esta conta Google já tem um cadastro VIP. Entre pela sua conta existente.',
  'instalacao':'A integração Google ainda não foi instalada no servidor.',
  'config':'A barbearia precisa terminar a configuração do Google.',
  'oauth-cliente':'O Google rejeitou as credenciais da aplicação. Avise a barbearia.',
  'sessao':'A autenticação Google expirou. Tente novamente.',
  'assinatura':'Sua assinatura VIP não está ativa para entrar. Se o plano estiver cancelado ou pausado, entre em contato com a barbearia para reativá-lo.',
  'google-verificacao':'Não foi possível validar sua identidade Google.',
  'erro':'Não foi possível entrar pelo Google. Tente novamente.'
 };
 const appleMessages={
  'config':'A barbearia ainda está configurando o login Apple.',
  'nao-vinculado':'Esta conta Apple ainda não foi vinculada à sua assinatura.',
  'assinatura':'Sua assinatura VIP não está ativa para entrar. Se o plano estiver cancelado ou pausado, entre em contato com a barbearia para reativá-lo.',
  'sessao':'A autenticação Apple expirou. Tente novamente.',
  'erro':'Não foi possível entrar com a Apple.'
 };
 if(feedback){
  const msg=googleMessages[params.get('google')]||appleMessages[params.get('apple')];
  if(msg){feedback.textContent=msg;feedback.hidden=false;}
 }
 document.querySelectorAll('.vip-password-toggle').forEach(btn=>{
  btn.addEventListener('click',()=>{const inp=$(btn.getAttribute('aria-controls'));if(!inp)return;const show=inp.type==='password';inp.type=show?'text':'password';btn.textContent=show?'Ocultar':'Mostrar';btn.setAttribute('aria-label',show?'Ocultar senha':'Mostrar senha');});
 });
 async function send(action,data){
  const response=await fetch('/api/vip-login.php?action='+encodeURIComponent(action),{
   method:'POST',credentials:'same-origin',cache:'no-store',referrerPolicy:'no-referrer',
   headers:{'Content-Type':'application/json','X-CSRF-Token':window.MARTELLI_VIP_CSRF},body:JSON.stringify(data)
  });
  const result=await response.json().catch(()=>({error:'Resposta inesperada do servidor.'}));
  if(!response.ok||!result.ok)throw Error(result.error||'Não foi possível continuar.');
  let dest=result.redirect||'/vip-agenda.php';
  if(action==='login'&&params.get('proximo')==='senha'&&dest.startsWith('/vip-agenda.php'))dest='/vip-senha.php';
  location.assign(dest);
 }
 function bind(id,action,values){
  const form=$(id);if(!form)return;
  form.addEventListener('submit',async ev=>{
   ev.preventDefault();
   const btn=form.querySelector('[type="submit"]');if(btn)btn.disabled=true;
   if(feedback)feedback.hidden=true;
   try{await send(action,values());}
   catch(err){if(feedback){feedback.textContent=err.message||'Não foi possível continuar.';feedback.hidden=false;feedback.scrollIntoView({behavior:'smooth',block:'center'});}if(btn)btn.disabled=false;}
  });
 }
 bind('vip-account-login','login',()=>({identifier:$('vip-account-identifier').value,password:$('vip-account-password').value}));
 bind('vip-account-change','change_password',()=>{
   const pass=$('vip-new-password').value;if(pass!==$('vip-confirm-password').value)throw Error('As senhas não coincidem.');
   return {current_password:$('vip-current-password').value,new_password:pass,confirm_password:$('vip-confirm-password').value};
 });
 bind('vip-account-activate','activate',()=>{
   const token=params.get('chave')||'';if(!/^[a-f0-9]{64}$/i.test(token))throw Error('Link inválido. Peça um link pessoal atualizado à barbearia.');
   const pass=$('vip-activate-password').value;if(pass!==$('vip-activate-confirm').value)throw Error('As senhas não coincidem.');
   return {chave:token,password:pass};
 });
})();
