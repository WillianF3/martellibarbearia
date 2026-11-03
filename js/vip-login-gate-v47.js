/* Martelli V47: no reload da página de planos, privilegiar acesso autenticado.
   NÃO presume que estar conectado ao Google seja ter acesso VIP; isso requer OAuth. */
(()=>{
  'use strict';
  const el=document.querySelector('main.vip-join');
  if(!el || el.dataset.oauthPending==='1')return;
  const nav=performance.getEntriesByType('navigation')[0];
  if(nav?.type==='reload') {
    location.replace('/vip-entrar.php?origem=planos');
  }
})();
