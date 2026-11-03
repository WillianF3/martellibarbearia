'use strict';
(()=>{
  const {escape:esc,money,weekdays,api}=window.Martelli;
  const services=document.getElementById('services-list'); const barbers=document.getElementById('barbers-list'); const hours=document.getElementById('hours-list'); const contact=document.getElementById('contact-list');
  let loading=false;
  async function loadCatalog(){
    if(loading)return; loading=true;
    try{
      if(!window.MARTELLI_BOOTSTRAP)services.innerHTML='<p class="text-muted" role="status">Carregando serviços…</p>';
    const result=await api('bootstrap');
    window.MARTELLI_BOOTSTRAP=result;
    window.dispatchEvent(new CustomEvent('martelli:bootstrap-ready',{detail:result}));
    services.innerHTML=result.services.length?result.services.map(s=>`<a class="service-row" href="/agendar.php?servico=${encodeURIComponent(s.id)}"><div class="service-top"><strong class="service-name">${esc(s.name)}</strong><span class="dotted"></span><strong class="service-price">${money(s.price)}</strong></div><div class="service-description">${esc(s.description||'')} · ${esc(s.duration_minutes)} min</div></a>`).join(''):'<p class="text-muted">Serviços em atualização.</p>';
    barbers.innerHTML=result.barbers.length?result.barbers.map(b=>`<article class="barber-card"><div class="avatar" aria-hidden="true">${esc(b.name.slice(0,1))}</div><div><h3>${esc(b.name)}</h3><p>${esc(b.bio||'Profissional da Barbearia Martelli')}</p><a class="barber-book-link" href="/agendar.php?barbeiro=${encodeURIComponent(b.id)}">Agendar com ${esc(b.name)} <span aria-hidden="true">↗</span></a></div></article>`).join(''):'<p class="text-muted">Profissionais em atualização.</p>';
    const all=[1,2,3,4,5,6,0];
    hours.innerHTML=all.map(wd=>{const h=result.hours.find(x=>Number(x.weekday)===wd);const label=h&&Number(h.is_open)?`${esc(String(h.open_time).slice(0,5))} – ${esc(String(h.close_time).slice(0,5))}`:'Fechado';return `<div class="hours-row"><strong>${weekdays[wd]}</strong><span>${label}</span></div>`;}).join('');
    const links=[];
    const phone=String(result.whatsapp||'').replace(/\D/g,'');
    if(phone.length>=12&&phone.length<=13)links.push(`<a class="button button-gold" href="https://wa.me/${phone}" target="_blank" rel="noopener noreferrer">Conversar pelo WhatsApp <span aria-hidden="true">↗</span></a>`);
    const rawIg=String(result.instagram||'').trim();
    let igUrl='';
    if(/^@?[a-zA-Z0-9._]{1,30}$/.test(rawIg))igUrl='https://www.instagram.com/'+rawIg.replace(/^@/,'')+'/';
    else if(rawIg){try{const url=new URL(rawIg);if(url.protocol==='https:'&&(url.hostname==='instagram.com'||url.hostname==='www.instagram.com'))igUrl=url.href;}catch{/* Link inválido: não publicar */}}
    const address=String(result.address||'').trim();const notice=String(result.notice||'').trim();
    const socialIcon='<svg class="martelli-instagram-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.3" cy="6.7" r=".8" fill="currentColor" stroke="none"/></svg>';
    const card=igUrl?`<a class="instagram-spotlight is-clickable" href="${esc(igUrl)}" target="_blank" rel="noopener noreferrer" aria-label="Visitar o Instagram da Barbearia Martelli em uma nova aba"><span class="instagram-spotlight-icon">${socialIcon}</span><span class="instagram-spotlight-copy"><span class="instagram-spotlight-eyebrow">SIGA NOSSO INSTAGRAM</span><strong>Bastidores, cortes e novidades.</strong><span>Conheça nosso trabalho e acompanhe as novidades da Martelli.</span><span class="instagram-spotlight-cta">Visitar Instagram <span aria-hidden="true">↗</span></span></span></a>`:
      `<div class="instagram-spotlight is-unavailable"><span class="instagram-spotlight-icon">${socialIcon}</span><span class="instagram-spotlight-copy"><span class="instagram-spotlight-eyebrow">NOSSO INSTAGRAM</span><strong>Estilo em cada detalhe.</strong><span>Em breve, nosso Instagram estará disponível por aqui.</span></span></div>`;
    contact.innerHTML=`<div class="contact-primary">${card}</div>${address||notice||links.length?`<div class="contact-meta">${address?`<p><strong>Endereço:</strong> ${esc(address)}</p>`:''}${notice?`<p>${esc(notice)}</p>`:''}${links.length?`<div class="contact-actions">${links.join('')}</div>`:''}</div>`:''}`;


  }catch(e){
    services.innerHTML='<div class="mt-retry-services error-banner" role="alert"><strong>Não foi possível carregar os serviços.</strong><p>Verifique a conexão e tente novamente. Se o erro persistir, avise a barbearia.</p><button class="button button-outline" type="button" id="mt-retry-services">Tentar novamente ↻</button></div>';
    barbers.innerHTML='<p class="text-muted">Não foi possível carregar a equipe agora.</p>';
    hours.innerHTML='<p class="text-muted">Horários temporariamente indisponíveis.</p>';
    contact.innerHTML='<p class="text-muted">Contato temporariamente indisponível.</p>';
    services.querySelector('#mt-retry-services')?.addEventListener('click',loadCatalog,{once:true});
    console.warn('Não foi possível carregar dados públicos:',e.message);
  }finally{loading=false;}
  }
  loadCatalog();
})();
