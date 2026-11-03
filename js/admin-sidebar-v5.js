'use strict';
/* Martelli V8: navegação acessível. Mantém os eventos originais de agenda e gestão. */
(() => {
  const sidebar = document.getElementById('admin-nav-drawer');
  const trigger = document.querySelector('[data-admin-drawer-trigger]');
  const closeButton = sidebar?.querySelector('[data-admin-drawer-close]');
  const backdrop = document.getElementById('admin-drawer-backdrop');
  const nav = sidebar?.querySelector('.cabra-nav');
  if (!sidebar || !trigger || !closeButton || !backdrop || !nav) return;

  const mobileMedia = window.matchMedia('(max-width: 959px)');
  const storageKey = 'martelli-menu-v8:' + location.pathname;
  let collapsed = {};
  try { collapsed = JSON.parse(localStorage.getItem(storageKey) || '{}') || {}; } catch (_) { collapsed = {}; }
  let previousFocus = null;
  const groups = [];

  function setDrawer(open, restoreFocus = true) {
    const mobile = mobileMedia.matches;
    const shouldOpen = mobile && open;
    const wasOpen = document.body.classList.contains('admin-drawer-open');
    document.body.classList.toggle('admin-drawer-open', shouldOpen);
    trigger.setAttribute('aria-expanded', String(shouldOpen));
    sidebar.inert = mobile && !shouldOpen;
    sidebar.setAttribute('aria-hidden', String(mobile && !shouldOpen));
    backdrop.setAttribute('aria-hidden', String(!shouldOpen));
    if (shouldOpen && !wasOpen) {
      previousFocus = document.activeElement;
      closeButton.focus();
    } else if (!shouldOpen && wasOpen && restoreFocus && mobile) {
      (previousFocus?.isConnected && previousFocus.getClientRects().length ? previousFocus : trigger).focus();
    }
  }
  // Elementos de ação do menu continuam habilitados quando o menu está fixo.
  function syncMode() { setDrawer(false, false); }
  syncMode();
  if (mobileMedia.addEventListener) mobileMedia.addEventListener('change', syncMode);
  else mobileMedia.addListener(syncMode);
  trigger.addEventListener('click', () => setDrawer(!document.body.classList.contains('admin-drawer-open')));
  closeButton.addEventListener('click', () => setDrawer(false));
  backdrop.addEventListener('click', () => setDrawer(false));

  const searchLabel = document.createElement('label');
  searchLabel.className = 'cabra-menu-search';
  searchLabel.innerHTML = '<span>Encontrar uma função</span><input type="search" autocomplete="off" aria-label="Pesquisar opção no menu administrativo" placeholder="Buscar no menu…">';
  sidebar.insertBefore(searchLabel, nav);
  const search = searchLabel.querySelector('input');
  const empty = document.createElement('p');
  empty.className = 'cabra-nav-empty';
  empty.textContent = 'Nenhuma opção encontrada. Tente outro termo.';
  empty.setAttribute('role', 'status');
  empty.hidden = true;
  nav.after(empty);

  const norm = text => String(text || '').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
  function setGroupOpen(group, isOpen, persist = false) {
    group.body.hidden = !isOpen;
    group.toggle.setAttribute('aria-expanded', String(isOpen));
    if (persist) {
      collapsed[group.id] = !isOpen;
      try { localStorage.setItem(storageKey, JSON.stringify(collapsed)); } catch (_) { /* sessão privada */ }
    }
  }

  [...nav.querySelectorAll('.cabra-nav-group')].forEach((node, idx) => {
    const label = node.querySelector('.cabra-nav-label');
    if (!label) return;
    const id = 'cabra-menu-grupo-' + idx;
    const body = document.createElement('div');
    body.className = 'cabra-nav-group-items';
    body.id = id;
    const links = [...node.children].filter(el => el !== label);
    links.forEach(el => body.appendChild(el));
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'cabra-nav-group-toggle';
    toggle.textContent = label.textContent.trim();
    toggle.setAttribute('aria-controls', id);
    label.replaceWith(toggle);
    node.appendChild(body);
    const group = {id, node, body, toggle, links, label: norm(toggle.textContent)};
    groups.push(group);
    setGroupOpen(group, !collapsed[id]);
    toggle.addEventListener('click', () => {
      if (search.value.trim()) { search.value = ''; filterMenu(); }
      setGroupOpen(group, group.body.hidden, true);
    });
  });

  function filterMenu() {
    const query = norm(search.value);
    let found = 0;
    groups.forEach(group => {
      const groupMatch = query && group.label.includes(query);
      let visible = 0;
      group.links.forEach(link => {
        const match = !link.hasAttribute('aria-hidden') && (!query || groupMatch || norm(link.textContent).includes(query));
        link.hidden = !match;
        if (match) visible++;
      });
      group.node.hidden = visible === 0;
      found += visible;
      setGroupOpen(group, query ? true : !collapsed[group.id]);
    });
    empty.hidden = found > 0;
    if (!query) activeItem();
  }
  search.addEventListener('input', filterMenu);
  search.addEventListener('keydown', event => {
    if (event.key === 'Escape' && search.value) {
      event.stopPropagation(); search.value = ''; filterMenu();
    }
  });

  function activeItem() {
    const path = location.pathname.replace(/\/$/, '') || '/';
    const currentHash = location.hash || (path.endsWith('/painel.php') ? '#dashboard' : path.endsWith('/gestao.php') ? '#lista-espera' : '#agenda');
    let active = null;
    const items = nav.querySelectorAll('.cabra-nav-group-items > a[href], .cabra-nav-group-items > button[data-tab]');
    items.forEach(el => {
      let matches = false;
      if (el.matches('button[data-tab]')) matches = path.endsWith('/admin.php') && '#' + el.dataset.tab === currentHash;
      else {
        try {
          const url = new URL(el.getAttribute('href'), location.href);
          matches = url.origin === location.origin && url.pathname.replace(/\/$/, '') === path && url.hash === currentHash;
        } catch (_) { matches = false; }
      }
      el.classList.toggle('is-current', matches);
      if (matches) {el.setAttribute('aria-current', 'page');active = el;}
      else el.removeAttribute('aria-current');
    });
    if (active && !search.value.trim()) {
      const group = groups.find(g => g.body.contains(active));
      if (group && group.body.hidden) setGroupOpen(group, true);
    }
  }
  activeItem();
  window.addEventListener('hashchange', activeItem);
  window.addEventListener('pageshow', activeItem);
  nav.addEventListener('click', event => {
    const link = event.target.closest('a[href],button[data-tab]');
    if (!link) return;
    if (mobileMedia.matches) setDrawer(false);
    // admin.js navega com history.replaceState, que não dispara hashchange.
    queueMicrotask(activeItem);
  });
  // Evita que Tab saia do menu suspenso no celular.
  document.addEventListener('keydown', event => {
    if (!mobileMedia.matches || !document.body.classList.contains('admin-drawer-open')) return;
    if (event.key === 'Escape') { event.preventDefault();setDrawer(false);return; }
    if (event.key !== 'Tab') return;
    const available = [...sidebar.querySelectorAll('a[href],button:not([disabled]),input:not([disabled])')]
      .filter(el => !el.closest('[hidden]') && el.getClientRects().length);
    if (!available.length) return;
    const first = available[0], last = available[available.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault();last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault();first.focus(); }
  });
})();
