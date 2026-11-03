'use strict';
(() => {
  const toggle = document.querySelector('.menu-toggle');
  const nav = document.querySelector('.main-nav');
  const header = document.querySelector('.site-header');
  const mobile = window.matchMedia('(max-width:760px)');
  let scrim = null;

  function setMenu(open, restoreFocus = false) {
    if (!nav || !toggle) return;
    const shouldOpen = Boolean(open && mobile.matches);
    nav.classList.toggle('open', shouldOpen);
    toggle.setAttribute('aria-expanded', String(shouldOpen));
    toggle.setAttribute('aria-label', shouldOpen ? 'Fechar menu' : 'Abrir menu');
    document.body.classList.toggle('martelli-menu-open', shouldOpen);
    if (shouldOpen) {
      if (!scrim) {
        scrim = document.createElement('button');
        scrim.type = 'button';
        scrim.className = 'martelli-menu-scrim';
        scrim.setAttribute('aria-label', 'Fechar menu de navegação');
        scrim.addEventListener('click', () => setMenu(false));
        document.body.appendChild(scrim);
      }
      scrim.hidden = false;
    } else if (scrim) {
      scrim.hidden = true;
    }
    if (restoreFocus) toggle.focus();
  }

  toggle?.addEventListener('click', () => setMenu(!nav?.classList.contains('open')));
  nav?.querySelectorAll('a').forEach(link => link.addEventListener('click', () => setMenu(false)));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && nav?.classList.contains('open')) setMenu(false, true);
  });
  document.addEventListener('click', event => {
    if (nav?.classList.contains('open') && header && !header.contains(event.target)) setMenu(false);
  });
  mobile.addEventListener?.('change', () => setMenu(false));

  const updateHeader=()=>header?.classList.toggle('is-scrolled',window.scrollY>30);
  window.addEventListener('scroll',updateHeader,{passive:true});updateHeader();
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if(!reduced&&'IntersectionObserver' in window){
    document.documentElement.classList.add('js-enhanced');
    const targets=document.querySelectorAll('[data-reveal]');
    const observer=new IntersectionObserver(entries=>{
      entries.forEach(entry=>{
        if(!entry.isIntersecting)return;
        entry.target.classList.add('is-visible');observer.unobserve(entry.target);
      });
    },{threshold:.1,rootMargin:'0px 0px -25px 0px'});
    targets.forEach(el=>observer.observe(el));
    window.MartelliObserveReveal = root => {
      if (!root) return;
      root.querySelectorAll('[data-reveal]:not(.is-visible)').forEach(el=>observer.observe(el));
    };
  }
})();
window.Martelli={
  escape(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));},
  money(v){return Number(v||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});},
  weekdays:['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'],
  async api(action,data=null,params={}){
    const url=new URL('/api/index.php',location.origin);url.searchParams.set('action',action);
    Object.entries(params).forEach(([k,v])=>url.searchParams.set(k,String(v)));
    const opt=data===null?{}:{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':window.MARTELLI_CSRF||''},body:JSON.stringify(data)};
    let response;try{response=await fetch(url,opt);}catch{throw Error('Sem conexão com o servidor. Tente novamente.');}
    let result;try{result=await response.json();}catch{throw Error('Resposta inesperada. Confira a instalação PHP.');}
    if(result.csrf)window.MARTELLI_CSRF=result.csrf;
    if(!response.ok||result.ok===false){
      const error=new Error(result.error||`Erro HTTP ${response.status}`);
      // Metadados de erro controlados pelo servidor (não redireciona automaticamente).
      error.code=typeof result.code==='string'?result.code:'';
      error.loginUrl=result.login_url==='/vip-entrar.php'?result.login_url:null;
      throw error;
    }
    return result;
  },
  note(element,message,isError=false){if(!element)return;element.hidden=false;element.textContent=message;element.className=isError?'error-banner':'notice';}
};


// BRUNO V3.4 — Atalhos sociais flutuantes (contatos publicados pelo administrativo).
(() => {
  if(!document.querySelector('.site-header')||!document.querySelector('main'))return;

  const container=document.createElement('nav');
  container.className='martelli-social-float';
  container.setAttribute('aria-label','Redes sociais da barbearia');
  document.body.append(container);
  const icons={
    whatsapp:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.3 11.7a8.3 8.3 0 0 1-12.2 7.4L3.8 20l.9-4.3a8.3 8.3 0 1 1 15.6-4z"/><path d="M8.4 8.4c.3-.4.6-.4.8.1l.7 1.4c.1.3.1.5-.2.8l-.5.5c.6 1.2 1.5 2 2.7 2.7l.5-.5c.3-.3.5-.3.8-.2l1.4.7c.5.2.5.5.1.8-.5.7-1.2.9-2.1.7-2.5-.6-4.7-2.8-5.3-5.3-.2-.9 0-1.6.7-2.1z"/></svg>',
    instagram:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.4" cy="6.8" r=".9" fill="currentColor" stroke="none"/></svg>'
  };
  function makeLink(label,url,kind){
    const a=document.createElement('a');
    a.className=`martelli-social-button martelli-social-${kind}`;
    a.href=url;a.target='_blank';a.rel='noopener noreferrer';
    a.setAttribute('aria-label',label);a.title=label;a.innerHTML=icons[kind];
    container.append(a);
  }
  function instagramUrl(value){
    const entry=String(value||'').trim();
    if(/^@?[a-zA-Z0-9._]{1,30}$/.test(entry))return 'https://www.instagram.com/'+entry.replace(/^@/,'')+'/';
    try{
      const url=new URL(entry);
      if(url.protocol==='https:'&&['instagram.com','www.instagram.com'].includes(url.hostname))return url.href;
    }catch{}
    return null;
  }
  function renderContactLinks(data){
    if(!data||container.dataset.loaded==='1')return;
    container.dataset.loaded='1';
    let phone=String(data.whatsapp||'').replace(/\D/g,'');
    if(/^\d{10,11}$/.test(phone))phone='55'+phone;
    if(/^55\d{10,11}$/.test(phone))makeLink('Conversar com a Barbearia Martelli pelo WhatsApp','https://wa.me/'+phone,'whatsapp');
    const ig=instagramUrl(data.instagram);
    if(ig)makeLink('Visitar Instagram da Barbearia Martelli',ig,'instagram');
    if(!container.children.length)container.remove();
  }
  if(window.MARTELLI_BOOTSTRAP)renderContactLinks(window.MARTELLI_BOOTSTRAP);
  else if(document.getElementById('services-list')){
    // A homepage já consulta a API — reutilizamos a mesma resposta.
    window.addEventListener('martelli:bootstrap-ready',event=>renderContactLinks(event.detail),{once:true});
  }else{
    window.Martelli.api('bootstrap').then(renderContactLinks).catch(()=>container.remove());
  }
})();
