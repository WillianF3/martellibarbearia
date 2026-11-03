<?php
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/layout.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
$token=(string)($_GET['token']??'');
$valid=(bool)preg_match('/^[a-f0-9]{64}$/D',$token);
page_head('Redefinir senha VIP','Crie uma nova senha para sua conta Martelli VIP.');site_header();
?>
<main class="vip-login-page" id="conteudo"><div class="vip-login-container">
 <span class="vip-login-kicker">MARTELLI VIP · SEGURANÇA</span>
 <h1>Nova <em>senha.</em></h1>
 <section class="vip-login-panel">
  <?php if(!$valid): ?>
    <h2>Link de recuperação inválido</h2><p>Solicite um novo link de recuperação para continuar.</p><a class="vip-login-primary vip-action-link" href="/vip-recuperar.php">Solicitar outro e-mail</a>
  <?php else: ?>
   <h2>Escolha sua nova senha</h2><p>Use pelo menos 10 caracteres. Após a alteração, os acessos anteriores serão encerrados.</p>
   <form id="vip-email-new-password">
    <label for="vip-reset-password">Nova senha</label>
    <input type="password" id="vip-reset-password" minlength="10" maxlength="128" required autocomplete="new-password">
    <label for="vip-reset-confirm">Repita a nova senha</label>
    <input type="password" id="vip-reset-confirm" minlength="10" maxlength="128" required autocomplete="new-password">
    <button class="vip-login-primary" type="submit">Salvar nova senha</button>
   </form>
   <div id="vip-reset-feedback" class="vip-login-feedback" role="alert" hidden></div>
  <?php endif; ?>
  <div class="vip-recovery-actions"><a class="vip-action-outline" href="/vip-entrar.php">Voltar ao login</a></div>
 </section>
</div></main>
<script>window.MARTELLI_VIP_CSRF=<?=json_encode(csrf())?>;</script>
<?php site_footer();page_end('<script src="/js/vip-reset-v46.js?v=46" defer></script>'); ?>
