'use strict';
(()=>{
 const host=document.getElementById('vip-public-plans');if(!host)return;
 const esc=window.Martelli?.escape||((s)=>String(s));
 const money=c=>(Number(c||0)/100).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
 const renderNoPlans=()=>{host.innerHTML='<p class="vip-no-plans">Planos e valores sob consulta. Converse com a equipe para conhecer as opções de assinatura.</p>';};
 fetch('/api/vip.php?action=public_plans',{credentials:'same-origin'})
  .then(async r=>{if(!r.ok)throw Error('Planos indisponíveis');return r.json();})
  .then(data=>{
   const plans=Array.isArray(data.plans)?data.plans:[];
   if(!plans.length){renderNoPlans();return;}
   host.innerHTML=plans.map(p=>`<div class="vip-public-plan"><div><h3>${esc(p.name)}</h3><p>${esc(p.description||'Assinatura mensal de atendimentos.')}</p><small>${Number(p.cuts_per_month)} ${Number(p.cuts_per_month)===1?'atendimento incluído':'atendimentos incluídos'} por ciclo de 30 dias</small></div><div class="vip-public-price">${money(p.monthly_price_cents)}<small>/30 dias</small></div><a class="vip-card-select" href="/vip-cadastro.php?plano=${Number(p.id)}">Escolher plano ↗</a></div>`).join('');
  }).catch(renderNoPlans);
 const cta=document.getElementById('vip-whatsapp-cta');
 async function setWhatsApp(data){
  let n=String(data?.whatsapp||'').replace(/\D/g,'');if(/^\d{10,11}$/.test(n))n='55'+n;
  if(!/^55\d{10,11}$/.test(n))return;
  cta.href=`https://wa.me/${n}?text=${encodeURIComponent('Olá! Quero conhecer os planos de assinatura Martelli VIP e as condições para ser assinante.')}`;
  cta.target='_blank';cta.rel='noopener noreferrer';cta.textContent='Consultar planos pelo WhatsApp ↗';
 }
 if(window.MARTELLI_BOOTSTRAP)setWhatsApp(window.MARTELLI_BOOTSTRAP);
 else window.addEventListener('martelli:bootstrap-ready',e=>setWhatsApp(e.detail),{once:true});
})();
