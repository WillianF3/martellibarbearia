/* Martelli V4.1 — catálogo React progressivo: filtros, combos, pesquisa e ordenação.
 * Mantém o catálogo PHP/JS nativo como fallback se React/CDN estiver indisponível. */
'use strict';
(() => {
  const host = document.getElementById('react-services');
  const fallback = document.getElementById('services-list');
  if (!host || !fallback || !window.React || !window.ReactDOM?.createRoot) return;

  const h = React.createElement;
  const money = value => Number(value || 0).toLocaleString('pt-BR', {style:'currency',currency:'BRL'});
  const norm = text => String(text ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('pt-BR');
  const category = item => {
    const name = norm(item.name);
    if (name.includes('+') || /combo|pacote/.test(name)) return 'combos';
    if (/barba|barbear/.test(name)) return 'barba';
    if (/corte|degrade|fade/.test(name)) return 'cortes';
    return 'outros';
  };
  const FILTERS = [['todos','Todos'],['cortes','Cortes'],['barba','Barba'],['combos','Combos'],['outros','Outros']];
  const SORTS = [
    ['padrao','Ordem da casa'],['menor-preco','Menor preço'],['maior-preco','Maior preço'],
    ['duracao','Menor duração'],['nome','Nome A–Z']
  ];

  function Explorer({services}) {
    const [filter,setFilter] = React.useState('todos');
    const [search,setSearch] = React.useState('');
    const [sort,setSort] = React.useState('padrao');
    const normalizedQuery = norm(search.trim());
    const shown = React.useMemo(() => {
      const filtered = services.filter(item =>
        (filter === 'todos' || category(item) === filter) &&
        norm(`${item.name} ${item.description || ''}`).includes(normalizedQuery)
      );
      if (sort !== 'padrao') filtered.sort((a,b) => {
        switch (sort) {
          case 'menor-preco': return Number(a.price)-Number(b.price);
          case 'maior-preco': return Number(b.price)-Number(a.price);
          case 'duracao': return Number(a.duration_minutes)-Number(b.duration_minutes);
          case 'nome': return String(a.name).localeCompare(String(b.name),'pt-BR');
          default: return 0;
        }
      });
      return filtered;
    },[services,filter,normalizedQuery,sort]);
    const filtered = filter !== 'todos' || search.trim() || sort !== 'padrao';
    const clear = () => { setFilter('todos');setSearch('');setSort('padrao'); };
    // Anunciar a montagem somente após o React efetivamente inserir a UI.
    React.useEffect(() => {
      fallback.hidden = true;
      host.classList.add('react-ready');
      return () => { fallback.hidden = false;host.classList.remove('react-ready'); };
    }, []);

    return h(React.Fragment,null,
      h('div',{className:'service-explorer-toolbar'},
        h('div',{className:'service-filter-tabs',role:'group','aria-label':'Categorias de serviço'},
          FILTERS.map(([key,label])=>h('button',{
            key,type:'button',className:`filter-pill${key===filter?' active':''}`,
            onClick:()=>setFilter(key),'aria-pressed':key===filter
          },label))
        ),
        h('div',{className:'mt-search-sort'},
          h('label',{className:'service-search'},
            h('span',{className:'visually-hidden'},'Buscar um serviço'),
            h('span',{className:'service-search-icon','aria-hidden':'true'},'⌕'),
            h('input',{type:'search',value:search,maxLength:80,placeholder:'Buscar serviço…',
              onChange:e=>setSearch(e.target.value),autoComplete:'off','aria-label':'Buscar serviços'})
          ),
          h('label',{className:'mt-sort-control'},
            h('span','Ordenar'),
            h('select',{value:sort,onChange:e=>setSort(e.target.value),'aria-label':'Ordenar serviços'},
              SORTS.map(([key,label])=>h('option',{key,value:key},label))
            )
          )
        ),
        h('div',{className:'mt-results-row'},
          h('p',{className:'mt-results-count',role:'status','aria-live':'polite'},
            `${shown.length} ${shown.length===1?'serviço encontrado':'serviços encontrados'}`),
          filtered && h('button',{type:'button',className:'mt-clear-filters',onClick:clear},'Limpar filtros ↺')
        )
      ),
      shown.length
        ? h('div',{className:'service-cards-grid'},shown.map((item,index)=>
            h('article',{className:'service-v5-card',key:String(item.id)},
              h('div',{className:'service-card-top'},
                h('span',{className:'service-card-number','aria-hidden':'true'},String(index+1).padStart(2,'0')),
                h('span',{className:'service-card-duration'},`${Number(item.duration_minutes)} min`),
                category(item)==='combos' && h('span',{className:'mt-combo-label'},'Combo')
              ),
              h('div',{className:'service-card-body'},
                h('h3',null,item.name),
                h('p',null,item.description||'Cuidado, técnica e precisão em cada detalhe.')
              ),
              h('div',{className:'service-card-bottom'},
                h('strong',{className:'service-card-price'},money(item.price)),
                h('a',{className:'service-card-link',href:`/agendar.php?servico=${encodeURIComponent(item.id)}`,
                  'aria-label':`Agendar ${item.name}`},h('span',null,'Agendar'),h('span',{'aria-hidden':'true'},'↗'))
              )
            )
          ))
        : h('div',{className:'mt-empty-services',role:'status'},
            h('strong',null,'Nenhum serviço encontrado.'),
            h('p',null,'Tente outro termo ou remova os filtros.'),
            h('button',{type:'button',className:'mt-clear-filters',onClick:clear},'Ver todos os serviços')
          )
    );
  }

  let root = null;
  function mount(data) {
    if (!Array.isArray(data?.services)) return;
    try {
      if (!root) root = ReactDOM.createRoot(host);
      root.render(h(Explorer,{services:data.services}));
    } catch (error) {
      fallback.hidden=false;host.classList.remove('react-ready');
      console.warn('Catálogo React indisponível; catálogo tradicional mantido.',error.message);
    }
  }
  window.addEventListener('martelli:bootstrap-ready',event=>mount(event.detail));
  if (window.MARTELLI_BOOTSTRAP) mount(window.MARTELLI_BOOTSTRAP);
})();
