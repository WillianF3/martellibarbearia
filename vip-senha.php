<?php
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/layout.php';
require_once __DIR__.'/app/vip_oauth_availability.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
require __DIR__.'/app/vip_auth.php';
$identity=vip_auth_identity();
$hasPassword=true;
if($identity){
    try {
        $query=db()->prepare('SELECT password_hash FROM mt_vip_customer_accounts WHERE client_id=? LIMIT 1');
        $query->execute([(int)$identity['client_id']]);
        $hash=$query->fetchColumn();
        $hasPassword=is_string($hash)&&$hash!=='';
    }catch(Throwable $e){$hasPassword=false;}
}
page_head('Alterar senha VIP','Atualize sua senha de acesso ao Martelli VIP.');site_header();
?>
<main class="vip-login-page" id="conteudo"><div class="vip-login-container">
 <span class="vip-login-kicker">MARTELLI VIP · SEGURANÇA</span><h1>Minha <em>senha.</em></h1>
 <div id="vip-account-feedback" class="vip-login-feedback" role="alert" hidden></div>
 <?php if(!$identity): ?>
  <section class="vip-login-panel"><h2>Entre para alterar sua senha</h2><p>Para sua segurança, é necessário confirmar primeiro a identidade com a senha atual. O CPF sozinho não autoriza alterar o cadastro.</p><a class="vip-login-primary vip-action-link" href="/vip-entrar.php?proximo=senha">Entrar na minha conta ↗</a><p>Não tem acesso à senha ou ao WhatsApp antigo? <a href="/vip-recuperar.php">Veja como recuperar</a>.</p></section>
 <?php elseif(!$hasPassword): ?>
  <section class="vip-login-panel"><h2>Sua conta usa Google ou Apple</h2><p>Esta conta ainda não possui senha tradicional. Você pode continuar usando o provedor vinculado ou solicitar à barbearia a ativação de uma senha após a verificação da sua identidade.</p><a class="vip-action-outline" href="/vip-recuperar.php">Como ativar uma senha</a></section>
 <?php else: ?>
  <section class="vip-login-panel"><h2>Definir nova senha</h2><p>Confirme sua senha atual e escolha uma nova com pelo menos 10 caracteres.</p>
   <form id="vip-account-change">
    <label for="vip-current-password">Senha atual ou temporária</label><input type="password" id="vip-current-password" autocomplete="current-password" required maxlength="128">
    <label for="vip-new-password">Nova senha</label><div class="vip-password-wrap"><input type="password" id="vip-new-password" autocomplete="new-password" minlength="10" maxlength="128" required><button type="button" class="vip-password-toggle" aria-controls="vip-new-password" aria-label="Mostrar senha">Mostrar</button></div>
    <label for="vip-confirm-password">Repita a nova senha</label><input type="password" id="vip-confirm-password" autocomplete="new-password" minlength="10" maxlength="128" required>
    <button type="submit" class="vip-login-primary">Salvar nova senha</button>
   </form>
  </section>
 <?php endif; ?>
 <div class="vip-login-bottom"><a href="/vip-entrar.php">Voltar ao login</a><a href="/vip-agenda.php">Minha agenda</a></div>
</div></main>
<script>window.MARTELLI_VIP_CSRF=<?=json_encode(csrf())?>;</script>
<?php site_footer();page_end('<script src="/js/vip-account-v44.js?v=44" defer></script>'); ?>
