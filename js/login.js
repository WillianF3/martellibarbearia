'use strict';
document.getElementById('login-form')?.addEventListener('submit',async e=>{
  e.preventDefault();const form=e.currentTarget;const button=form.querySelector('button');button.disabled=true;
  const msg=document.getElementById('login-alert');msg.hidden=true;
  try{const result=await window.Martelli.api('login',{email:form.email.value,password:form.password.value});location.assign(result.redirect||'/login.php');}
  catch(err){window.Martelli.note(msg,err.message,true);button.disabled=false;}
});
