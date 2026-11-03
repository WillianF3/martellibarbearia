(()=>{
 const qs=new URLSearchParams(location.search),token=qs.get('chave');
 const activate=document.getElementById('vip-calendar-activate'),logout=document.getElementById('vip-calendar-logout');
 if(token && /^[a-f0-9]{64}$/.test(token)){activate.href='/vip-ativar.php?chave='+encodeURIComponent(token);activate.hidden=false;}
 else {activate.hidden=true;logout.hidden=false;}
 logout?.addEventListener('click',async()=>{
  if(!confirm('Deseja sair da sua conta VIP?'))return;
  const response=await fetch('/api/vip-login.php?action=logout',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.MARTELLI_VIP_CSRF},body:'{}',credentials:'same-origin'});
  if(response.ok)location.href='/vip-entrar.php';else alert('Não foi possível sair. Atualize a página e tente novamente.');
 });
})();