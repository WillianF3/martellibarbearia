<?php
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/layout.php';
require_once __DIR__.'/app/vip_oauth_availability.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
page_head('Ativar conta VIP','Crie sua senha usando o link pessoal de ativação recebido da barbearia.');site_header();
?>
<main class="vip-login-page" id="conteudo"><div class="vip-login-container">
 <span class="vip-login-kicker">MARTELLI VIP · PRIMEIRO ACESSO</span><h1>Ativar <em>minha conta.</em></h1>
 <div id="vip-account-feedback" class="vip-login-feedback" role="alert" hidden></div>
 <section class="vip-login-panel"><h2>Crie sua senha</h2><p>Use somente o link pessoal enviado pela barbearia após a aprovação do plano. Não compartilhe esse link.</p>
  <form id="vip-account-activate"><label for="vip-activate-password">Nova senha (mínimo 10 caracteres)</label><input type="password" id="vip-activate-password" autocomplete="new-password" required minlength="10" maxlength="128">
   <label for="vip-activate-confirm">Confirme a senha</label><input type="password" id="vip-activate-confirm" autocomplete="new-password" required minlength="10" maxlength="128">
   <button class="vip-login-primary" type="submit">Ativar minha conta</button>
  </form>
 </section>
 <div class="vip-login-bottom"><a href="/vip-entrar.php">Já tenho senha</a><a href="/vip-recuperar.php">Preciso de ajuda</a></div>
</div></main>
<script>window.MARTELLI_VIP_CSRF=<?=json_encode(csrf())?>;</script>
<?php site_footer();page_end('<script src="/js/vip-account-v44.js?v=44" defer></script>'); ?>
