<?php
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/layout.php';
page_head('O corte perfeito tem sobrenome. Martelli.', 'Barbearia de alma italiana: tradição, cortes precisos e uma experiência pensada para quem valoriza cada detalhe.');
site_header();
?>
<main id="conteudo">
  <section class="hero hero-v4-ref hero-bruno-v3" aria-labelledby="hero-title">
    <img class="hero-photo" src="/assets/hero-barbearia.jpg" alt="" fetchpriority="high" width="1920" height="1088">
    <div class="hero-gradient" aria-hidden="true"></div>
    <div class="container hero-content">
      <span class="eyebrow">La tradizione continua</span>
      <a href="/" aria-label="Voltar à página inicial da Barbearia Martelli"><img class="hero-logo" src="/assets/logo-martelli-hero.png" alt="Barbearia Martelli" width="240" height="175"></a>
      <h1 id="hero-title">O corte perfeito<br>tem sobrenome.<br><em>Martelli.</em></h1>
      <p>Barbearia de alma italiana: tradição, cortes precisos e uma experiência pensada para quem valoriza cada detalhe.</p>
      <div class="hero-actions">
        <a class="button button-gold" href="/agendar.php">Agendar horário <span aria-hidden="true">↗</span></a>
        <a class="button button-outline" href="#servicos">Ver serviços</a>
        <a class="button hero-vip-cta" href="/vip-cadastro.php" aria-label="Escolher um plano e fazer cadastro Martelli VIP"><span aria-hidden="true">✦</span> Martelli VIP <span aria-hidden="true">↗</span></a>
      </div>
    </div>
    <span class="hero-side" aria-hidden="true">EST. MARTELLI · BARBEARIA</span>
  </section>

  <section id="servicos" class="section container v4-services" aria-labelledby="services-heading">
    <div class="section-heading" data-reveal data-motion>
      <span class="eyebrow">Il menu</span>
      <h2 id="services-heading">Serviços</h2>
      <div class="tricolore-line"></div>
      <p>Experiência clássica com estilo contemporâneo.</p>
    </div>
    <!-- O React mantém a busca/filtros novos; o JS nativo continua disponível caso o CDN falhe. -->
    <div id="react-services" class="react-services" aria-live="polite"></div>
    <div id="services-list" class="services-list services-fallback" aria-live="polite"><p class="text-muted" role="status">Carregando serviços...</p></div>
  </section>


  <!-- Martelli VIP: planos definidos exclusivamente pela administração. -->
  <section id="vip" class="martelli-vip" aria-labelledby="vip-heading">
    <div class="container vip-public-inner">
      <div data-reveal data-motion>
        <span class="eyebrow">Martelli Club · Assinaturas</span>
        <h2 id="vip-heading">Seu estilo merece<br><em>frequência.</em></h2>
        <p>Conheça o <strong>Martelli VIP</strong>: a assinatura mensal para quem gosta de manter o corte em dia. Escolha um plano de cortes, envie seu cadastro e aguarde a equipe confirmar sua adesão.</p>
        <ul class="vip-feature">
          <li>Cortes incluídos de acordo com o plano escolhido</li>
          <li>Controle de validade e utilização da assinatura</li>
          <li>Agendamento pela agenda habitual da barbearia</li>
        </ul>
        <div class="vip-public-actions">
          <a class="button button-gold" href="/vip-cadastro.php">Escolher meu plano VIP ↗</a>
          <a class="button button-outline" href="#contato" id="vip-whatsapp-cta">Tirar dúvidas pelo WhatsApp ↗</a>
          <a class="button button-outline" href="/agendar.php">Agendar horário</a>
        </div>
        <p class="vip-smallprint">Mensalidade, quantidade de cortes e demais condições dependem do plano contratado. A disponibilidade de horários e os serviços não incluídos seguem as regras da barbearia. A assinatura não é cobrada automaticamente pelo site.</p>
      </div>
      <div class="vip-display" data-motion>
        <div class="vip-display-heading"><strong>Martelli VIP</strong><span class="vip-gold-tag">CLUBE DE CORTES</span></div>
        <div id="vip-public-plans" aria-live="polite"><p class="vip-no-plans">Consultando planos disponíveis…</p></div>
        <p class="vip-section-mark">La tradizione continua</p>
      </div>
    </div>
  </section>

  <section id="barbeiros" class="section section-alt v4-team" aria-labelledby="barbers-heading">
    <div class="container">
      <div class="section-heading" data-reveal data-motion>
        <span class="eyebrow">I maestri</span>
        <h2 id="barbers-heading">Nossos barbeiros</h2>
        <div class="tricolore-line"></div>
        <p>Profissionais dedicados a cada detalhe.</p>
      </div>
      <div id="barbers-list" class="barbers-grid"><p class="text-muted">Carregando profissionais...</p></div>
    </div>
  </section>

  <section class="manifesto v4-manifesto" aria-labelledby="manifesto-heading">
    <div class="container" data-reveal data-motion>
      <span class="eyebrow">O nosso jeito</span>
      <h2 id="manifesto-heading">Um bom corte não é só estilo.<br><em>É tradição.</em></h2>
      <p>Uma barbearia feita para quem valoriza técnica, conversa boa e atendimento de verdade.</p>
      <a class="button button-gold" href="/agendar.php">Reservar minha cadeira</a>
    </div>
  </section>

  <section id="horarios" class="section container hours-layout v4-hours" aria-labelledby="hours-heading">
    <div data-reveal data-motion>
      <span class="eyebrow">Orari</span>
      <h2 id="hours-heading">Horário de<br><em>atendimento</em></h2>
      <p class="text-muted">Escolha seu melhor momento e garanta sua cadeira com antecedência.</p>
      <a class="button button-outline" href="/agendar.php">Escolher horário</a>
    </div>
    <div id="hours-list" class="hours-card" data-motion><p class="text-muted">Carregando horários...</p></div>
  </section>

  <section id="contato" class="section section-alt v4-contact" aria-labelledby="contact-heading">
    <div class="container contact-grid">
      <div data-reveal data-motion>
        <span class="eyebrow">Dove siamo</span>
        <h2 id="contact-heading">Entre em <em>contato.</em></h2>
        <p class="text-muted">Fale com a barbearia ou consulte nosso endereço para sua próxima visita.</p>
      </div>
      <div id="contact-list" class="contact-details" data-motion aria-live="polite"><p class="text-muted">Consultando canais de atendimento...</p></div>
    </div>
  </section>

  <section class="closing v4-closing" aria-labelledby="closing-heading">
    <div class="container" data-reveal data-motion>
      <span class="eyebrow">Benvenuto</span>
      <h2 id="closing-heading">Seu próximo corte começa aqui.</h2>
      <a class="button button-gold" href="/agendar.php">Agendar agora <span aria-hidden="true">↗</span></a>
    </div>
  </section>
</main>
<?php
site_footer();
page_end('<script src="/js/vip-public.js?v=36" defer></script><script src="/js/home.js?v=martelli-v4-1" defer></script><script src="https://cdn.jsdelivr.net/npm/react@18.3.1/umd/react.production.min.js" crossorigin="anonymous" defer></script><script src="https://cdn.jsdelivr.net/npm/react-dom@18.3.1/umd/react-dom.production.min.js" crossorigin="anonymous" defer></script><script src="/js/react-home.js?v=martelli-v4-1" defer></script><script src="/js/motion-bruno-v3.js?v=bruno-v3-4" defer></script><script src="/js/martelli-effects-v4.js?v=20261025-40" defer></script>');
?>
