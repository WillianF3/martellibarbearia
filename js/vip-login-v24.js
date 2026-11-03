'use strict';
(()=>{
 const $=id=>document.getElementById(id),params=new URLSearchParams(location.search),token=params.get('chave')||'';
 const activate=Boolean(/^[a-f0-9]{64}$/.test(token));
 const wantsChange=params.get('alterar')==='1' && !activate;
 const cpfRecovery=wantsChange&&params.get('origem')==='cpf';
 $('vip-login-form-section').hidden=activate || wantsChange;
 $('vip-activate-section').hidden=!activate;
 $('vip-change-section').hidden=!wantsChange;
 const recoveryNote=$('vip-recovery-notice');
 if(recoveryNote)recoveryNote.hidden=!cpfRecovery;
 if(wantsChange){
   fetch('/api/vip-login.php?action=state',{credentials:'same-origin',cache:'no-store'})
     .then(r=>r.json()).then(data=>{
       if(!data.logged_in){
        $('vip-change-section').hidden=true;$('vip-login-form-section').hidden=false;
        $('vip-login-heading').textContent='Entre para alterar sua senha';
        $('vip-login-feedback').textContent='Use o WhatsApp já vinculado à sua conta VIP e a senha atual. Após entrar, você poderá definir uma nova senha.';
        $('vip-login-feedback').hidden=false;
       }
     }).catch(()=>{$('vip-change-section').hidden=true;$('vip-login-form-section').hidden=false;});
 }
 const googleMessages={
   'nao-vinculado':'Esta conta Google ainda não está vinculada. Entre pelo seu link VIP pessoal e escolha “Vincular minha conta Google”.',
   'config':'O login com Google ainda não foi configurado pela barbearia.',
   'instalacao':'O acesso com Google ainda não foi instalado no banco de dados.',
   'primeiro-acesso':'Primeiro ative seu acesso VIP ou entre com WhatsApp e senha, depois vincule o Google.',
   'sessao':'Sua tentativa de conexão expirou. Tente novamente.',
   'assinatura':'É necessário ter uma assinatura VIP aprovada e válida.',
   'senha-temporaria':'Altere sua senha temporária antes de usar o login com Google.',
   'cancelado':'A conexão com Google foi cancelada.',
   'oauth-cliente':'O login Google está indisponível: a barbearia precisa conferir as credenciais OAuth.',
   'oauth-codigo':'A autorização Google expirou ou já foi utilizada. Tente entrar novamente.',
   'oauth-redirecionamento':'O endereço de retorno do Google precisa ser conferido pela barbearia.',
   'google-conexao':'Não foi possível contatar o Google. Tente novamente mais tarde.',
   'google-verificacao':'Não foi possível validar a identidade Google com segurança.',
   'banco':'O login Google está indisponível. A barbearia precisa verificar o banco de dados.',
   'erro':'Não foi possível entrar pelo Google. Verifique a configuração ou tente novamente.'
 };
 if(params.get('google')){
   $('vip-login-feedback').textContent=googleMessages[params.get('google')]||googleMessages.erro;
   $('vip-login-feedback').hidden=false;
 }
 const appleMessages={'config':'A barbearia ainda precisa configurar o login Apple.','instalacao':'O banco do login Apple ainda não foi instalado.','nao-vinculado':'Sua conta Apple ainda não está vinculada. Use seu link pessoal VIP para vincular.','assinatura':'É necessária uma assinatura VIP aprovada e válida.','senha-temporaria':'Altere sua senha temporária antes de vincular a Apple.','sessao':'A conexão Apple expirou. Tente novamente.','cancelado':'A autenticação Apple foi cancelada.','conexao':'Não foi possível comunicar com a Apple. Tente mais tarde.','banco':'O cadastro Apple está temporariamente indisponível.','erro':'Não foi possível autenticar com a Apple. Verifique a configuração.','conta-existente':'Esta conta Apple já está vinculada. Entre na agenda VIP.'};
 if(params.get('apple')){$('vip-login-feedback').textContent=appleMessages[params.get('apple')]||appleMessages.erro;$('vip-login-feedback').hidden=false;}
 const feedback=$('vip-login-feedback');
 async function send(action,values){
  const response=await fetch('/api/vip-login.php?action='+action,{method:'POST',credentials:'same-origin',cache:'no-store',referrerPolicy:'no-referrer',headers:{'Content-Type':'application/json','X-CSRF-Token':window.MARTELLI_VIP_CSRF},body:JSON.stringify(values)});
  const data=await response.json().catch(()=>({error:'Resposta inesperada do servidor.'}));
  if(!response.ok||!data.ok)throw Error(data.error||'Não foi possível continuar.');
  // Recuperação solicitada pelo próprio usuário: após login válido, abrir a troca de senha.
  const target=(action==='login'&&wantsChange&&data.redirect==='/vip-agenda.php')
   ?'/vip-login.php?alterar=1'+(cpfRecovery?'&origem=cpf':'')
   :(data.redirect||'/vip-agenda.php');
  location.replace(target);
 }
 function bind(form,action,getData){
  $(form).addEventListener('submit',async e=>{e.preventDefault();feedback.hidden=true;
   const button=e.currentTarget.querySelector('[type=submit]');button.disabled=true;
   try {await send(action,getData());}
   catch(error){feedback.textContent=error.message;feedback.hidden=false;button.disabled=false;feedback.scrollIntoView({behavior:'smooth',block:'center'});}
  });
 }
 bind('vip-login-form','login',()=>({phone:$('vip-login-phone').value,password:$('vip-login-password').value}));
 bind('vip-change-form','change_password',()=>{
  const newPassword=$('vip-new-password').value;
  if(newPassword!==$('vip-confirm-password').value)throw Error('As novas senhas não coincidem.');
  return {current_password:$('vip-current-password').value,new_password:newPassword,confirm_password:$('vip-confirm-password').value};
 });
 bind('vip-activate-form','activate',()=>{
  const password=$('vip-activate-password').value;
  if(password!==$('vip-activate-confirm').value)throw Error('As senhas não coincidem.');
  return {chave:token,password};
 });
})();
