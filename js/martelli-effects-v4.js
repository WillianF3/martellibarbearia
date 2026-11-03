/* Martelli V4.0: movimento progressivo e profundidade 3D apenas abaixo do hero.
   Não altera os botões de reserva, dados, hero ou API. */
'use strict';
(() => {
  if (!document.querySelector('#servicos')) return; // só na homepage
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
  const fine = window.matchMedia('(hover: hover) and (pointer: fine)');
  const selectors = [
    '.v4-team .barber-card',
    '.v4-services .service-v5-card',
    '.v4-hours .hours-card',
    '.v4-contact .instagram-spotlight'
  ];
  const entranceSelectors = [
    '.v4-services .section-heading', '.v4-team .section-heading',
    '.v4-team .barber-card', '.v4-services .service-v5-card',
    '.v4-manifesto .container', '.v4-contact .instagram-spotlight',
    '.v4-hours .hours-card', '.v4-closing .container'
  ];

  const io = !reduce.matches && 'IntersectionObserver' in window
    ? new IntersectionObserver(entries => {
        for (const entry of entries) {
          if (!entry.isIntersecting) continue;
          entry.target.classList.add('martelli-section-entered');
          io.unobserve(entry.target);
        }
      }, { threshold:0.12, rootMargin:'0px 0px -16px 0px' })
    : null;

  function attachTilt(el) {
    if (el.dataset.mtTiltBound || !fine.matches || reduce.matches) return;
    el.dataset.mtTiltBound = '1';
    el.classList.add('martelli-tilt');
    let raf = 0, nextX = 0, nextY = 0;
    const paint = () => {
      raf = 0;
      el.style.setProperty('--mt-rx',`${nextY.toFixed(2)}deg`);
      el.style.setProperty('--mt-ry',`${nextX.toFixed(2)}deg`);
    };
    el.addEventListener('pointermove', e => {
      const rect=el.getBoundingClientRect();
      if (!rect.width || !rect.height) return;
      const x=(e.clientX-rect.left)/rect.width-.5;
      const y=(e.clientY-rect.top)/rect.height-.5;
      nextX=Math.max(-3,Math.min(3,x*6));
      nextY=Math.max(-2.5,Math.min(2.5,-y*5));
      el.style.setProperty('--mt-shine-x',`${(x+.5)*100}%`);
      el.style.setProperty('--mt-shine-y',`${(y+.5)*100}%`);
      if (!raf) raf=requestAnimationFrame(paint);
    },{passive:true});
    const reset = () => {
      if (raf) cancelAnimationFrame(raf);
      raf=0;nextX=0;nextY=0;
      el.style.setProperty('--mt-rx','0deg');
      el.style.setProperty('--mt-ry','0deg');
    };
    el.addEventListener('pointerleave',reset);
    el.addEventListener('pointercancel',reset);
  }

  const animated = new WeakSet();
  function prepare(root=document) {
    if (io) for(const sel of entranceSelectors) root.querySelectorAll(sel).forEach(el => {
      if (animated.has(el)) return;
      animated.add(el);
      io.observe(el);
    });
    for(const sel of selectors) root.querySelectorAll(sel).forEach(attachTilt);
  }

  prepare();
  const dynamicRoots = ['#react-services','#barbers-list','#contact-list','#services-list'].map(s=>document.querySelector(s)).filter(Boolean);
  // O React e a API acrescentam cartões após o carregamento inicial.
  if ('MutationObserver' in window) {
    const mo = new MutationObserver(() => prepare());
    for(const root of dynamicRoots) mo.observe(root,{childList:true,subtree:true});
    window.addEventListener('pagehide', () => mo.disconnect(),{once:true});
  }

  // Sublinhar a seção que aparece durante a navegação do site.
  if ('IntersectionObserver' in window) {
    const links=[...document.querySelectorAll('.site-header .main-nav a[href^="/#"]')];
    const sections=links.map(a=>({a,section:document.getElementById(a.hash.slice(1))})).filter(x=>x.section);
    const spy=new IntersectionObserver(entries=>{
      for(const entry of entries){
        if(!entry.isIntersecting)continue;
        for(const {a,section} of sections) a.classList.toggle('is-current',section===entry.target);
      }
    },{rootMargin:'-23% 0px -62% 0px',threshold:0});
    sections.forEach(x=>spy.observe(x.section));
  }
})();
