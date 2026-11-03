/* BRUNO V3 — Efeitos progressivos de apresentação. Nenhuma ação de API ou reserva é alterada. */
'use strict';
(() => {
  const hero = document.querySelector('.hero-bruno-v3');
  if (!hero) return;
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  if (reduceMotion.matches) return;

  // O CSS só esconde itens temporariamente quando o observador existe.
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        entry.target.classList.remove('bruno-motion-pending');
        entry.target.classList.add('bruno-motion-visible');
        io.unobserve(entry.target);
      });
    }, { threshold: .08, rootMargin: '0px 0px 40px 0px' });
    const prepare = element => {
      if (!element || element.dataset.brunoObserved === '1') return;
      element.dataset.brunoObserved = '1';
      // Não ocultar elementos acima da dobra durante a primeira pintura.
      const r = element.getBoundingClientRect();
      if (r.top < window.innerHeight && r.bottom > 0) {
        element.classList.add('bruno-motion-visible');
      } else {
        element.classList.add('bruno-motion-pending');
        io.observe(element);
      }
    };
    document.documentElement.classList.add('bruno-motion-ready');
    document.querySelectorAll('[data-motion]').forEach(prepare);
    // Alguns cartões são inseridos depois pela resposta da API. Eles permanecem visíveis,
    // mas podem receber transição ao aparecer, sem reexecutar o carregamento de dados.
    const delayed = document.querySelectorAll('#barbers-list, #services-list');
    const mo = new MutationObserver(() => {
      document.querySelectorAll('#barbers-list .barber-card, #services-list .service-row').forEach(el => {
        if (!el.hasAttribute('data-motion')) {
          el.setAttribute('data-motion', '');
          prepare(el);
        }
      });
    });
    delayed.forEach(root => mo.observe(root, {childList:true,subtree:false}));
  }

  // Indicador de rolagem, limitado a uma escrita por quadro.
  const progress = document.createElement('div');
  progress.className = 'bruno-scroll-progress';
  progress.setAttribute('aria-hidden', 'true');
  document.body.append(progress);
  let scheduled = false;
  const paint = () => {
    scheduled = false;
    const max = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
    progress.style.width = `${Math.min(100, Math.max(0, window.scrollY / max * 100))}%`;
  };
  const schedule = () => {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(paint);
  };
  window.addEventListener('scroll', schedule, {passive:true});
  window.addEventListener('resize', schedule, {passive:true});
  schedule();

  // Movimento muito sutil da foto, somente com mouse preciso.
  if (window.matchMedia('(hover:hover) and (pointer:fine)').matches) {
    let raf = 0;
    hero.addEventListener('pointermove', event => {
      if (raf) return;
      const rect = hero.getBoundingClientRect();
      const relX = Math.max(-.5, Math.min(.5, (event.clientX - rect.left) / rect.width - .5));
      const relY = Math.max(-.5, Math.min(.5, (event.clientY - rect.top) / rect.height - .5));
      raf = requestAnimationFrame(() => {
        hero.style.setProperty('--bruno-bg-x', `${(-relX * 8).toFixed(2)}px`);
        hero.style.setProperty('--bruno-bg-y', `${(-relY * 6).toFixed(2)}px`);
        raf = 0;
      });
    }, {passive:true});
    hero.addEventListener('pointerleave', () => {
      if (raf) cancelAnimationFrame(raf);
      raf = 0;
      hero.style.setProperty('--bruno-bg-x', '0px');
      hero.style.setProperty('--bruno-bg-y', '0px');
    });
  }
})();
