<?php
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/layout.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
page_head('Recuperar senha VIP','Solicite um link seguro de redefinição de senha por e-mail.');site_header();
?>
<main class="vip-login-page" id="conteudo"><div class="vip-login-container">
 <span class="vip-login-kicker">MARTELLI VIP · RECUPERAÇÃO</span>
 <h1>Recuperar <em>senha.</em></h1>
 <section class="vip-login-panel">
  <h2>Receba um link no seu e-mail</h2>
  <p>Informe o e-mail vinculado à sua conta VIP. Se ele estiver habilitado para recuperação, enviaremos um link válido por <b>30 minutos</b>.</p>
  <form id="vip-email-recovery" novalidate>
   <label for="vip-recovery-email">E-mail do cadastro</label>
   <input id="vip-recovery-email" name="email" type="email" autocomplete="email" maxlength="190" required placeholder="seuemail@exemplo.com">
   <button class="vip-login-primary" type="submit">Enviar link de recuperação ↗</button>
  </form>
  <div id="vip-recovery-feedback" class="vip-login-feedback" role="status" aria-live="polite" hidden></div>
  <p class="vip-recovery-hint">Não recebeu? Confira a pasta de spam. Se seu cadastro não tem e-mail, ou você perdeu o acesso ao endereço antigo, peça à barbearia para verificar sua identidade e atualizar os dados.</p>
  <div class="vip-recovery-actions"><a class="vip-action-outline" href="/vip-entrar.php">Voltar ao login</a><a class="vip-action-outline" href="/#contato">Falar com a barbearia</a></div>
 </section>
</div></main>
<script>window.MARTELLI_VIP_CSRF=<?=json_encode(csrf())?>;</script>
<?php site_footer();page_end('<script src="/js/vip-reset-v46.js?v=46" defer></script>'); ?>
