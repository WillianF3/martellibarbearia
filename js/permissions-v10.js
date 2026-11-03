
'use strict';
(()=>{
 const grants=new Set(window.MARTELLI_PERMISSIONS||[]), owner=Boolean(window.MARTELLI_OWNER);
 const has=key=>grants.has(key);
 const featureOn=key=>window.MARTELLI_FEATURES?.[key]!==false;
 const tabPerm={agenda:'agenda',dia:'agenda',todos:'agenda',clientes:'cadastros',servicos:'cadastros',barbeiros:'cadastros',horarios:'horarios',configuracoes:'configuracoes',administradores:'owner'};
 const sectionPerm={comandas:'financeiro',contas:'financeiro',financeiro:'relatorios',caixa:'financeiro',movimentos:'financeiro',comissoes:'financeiro',estoque:'estoque',dashboard:'relatorios',relatorios:'relatorios',configuracoes:'financeiro'};
 const allow=(url)=>{
  let u;try{u=new URL(url,location.origin)}catch{return true}
  if(u.pathname==='/acessos.php'||u.pathname==='/funcoes.php')return owner;
  if(u.pathname==='/vip.php')return has('cadastros')||has('financeiro');
  if(u.pathname==='/gestao.php')return has('lista_espera');
  if(u.pathname==='/painel.php')return has(sectionPerm[u.hash.slice(1)]||'relatorios') && (u.hash!=='#comissoes'||featureOn('comissoes'));
  if(u.pathname==='/admin.php')return has(tabPerm[u.hash.slice(1)]||'agenda');
  return true;
 };
 function filter(){
  document.querySelectorAll('#admin-nav-drawer a[href],#admin-nav-drawer [data-tab],.admin-mobile-jump option,.biz-mobile option').forEach(el=>{
   let ok=true;
   if(el.dataset.tab)ok=(tabPerm[el.dataset.tab]==='owner')?owner:has(tabPerm[el.dataset.tab]);
   else if(el.value&&el.tagName==='OPTION'){
    const v=el.value;ok=v.startsWith('url:')?allow(v.slice(4)):(v in tabPerm?(tabPerm[v]==='owner'?owner:has(tabPerm[v])):(has(sectionPerm[v]||'financeiro')&&(v!=='comissoes'||featureOn('comissoes'))));
   }else if(el.getAttribute('href'))ok=allow(el.getAttribute('href'));
   el.hidden=!ok; if(!ok)el.setAttribute('aria-hidden','true');
  });
  document.querySelectorAll('#admin-nav-drawer .cabra-nav-group').forEach(el=>{if(!el.querySelector('a:not([hidden]),button[data-tab]:not([hidden])'))el.hidden=true;});
  const newBtn=document.getElementById('new-booking');if(newBtn&&!has('agenda'))newBtn.hidden=true;
  document.querySelectorAll('.admin-actions a[href],.biz-heading-actions a[href]').forEach(a=>{if(!allow(a.href))a.hidden=true;});
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',filter,{once:true});else filter();
 window.MartelliAllowedTab=tab=>tabPerm[tab]==='owner'?owner:has(tabPerm[tab]);
 window.MartelliAllowedSection=section=>has(sectionPerm[section])&&(section!=='comissoes'||featureOn('comissoes'));
})();
