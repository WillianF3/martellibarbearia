<?php
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/layout.php';
require_once __DIR__.'/app/vip_oauth_availability.php';
require_once __DIR__.'/app/vip_auth.php';

/** Mensagem amigável quando o acesso VIP for bloqueado pelo status do plano. */
function vip_login_block_message(string $code): ?string {
    return match($code){
        'cancelado' => 'Seu plano VIP está cancelado. Entre em contato com a barbearia para reativar o plano e voltar a acessar seus cortes de cabelo agendados.',
        'pausado' => 'Seu plano VIP está pausado. Entre em contato com a barbearia para reativar o plano e voltar a acessar seus cortes de cabelo agendados.',
        'sem-plano' => 'Nenhum plano VIP foi encontrado para este acesso. Entre em contato com a barbearia para verificar o seu cadastro.',
        default => null,
    };
}

$loginStatusCode=preg_replace('/[^a-z-]/','',strtolower((string)($_GET['bloqueio']??'')));
$loginStatusMessage=vip_login_block_message($loginStatusCode) ?? '';

// Se a sessão VIP já estiver válida, o login é desnecessário.
// Uma confirmação pendente de vínculo Google precisa mostrar o formulário.
if (empty($_SESSION['vip_google_link_pending'])) {
    try {
        $vipLogged=vip_auth_identity();
        if ($vipLogged) {
            $blocked=vip_auth_login_block((int)$vipLogged['client_id']);
            if($blocked){
                vip_auth_clear();
                $_SESSION['csrf']=bin2hex(random_bytes(24));
                $loginStatusCode=(string)($blocked['code']??'');
                $loginStatusMessage=(string)($blocked['message']??'');
            } elseif ((int)($vipLogged['must_change_password']??0)===0) {
                header('Location: /vip-agenda.php', true, 303);exit;
            }
        }
    } catch (Throwable $e) {error_log('Martelli VIP: falha ao verificar acesso no login.');}
}

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
$googleLogin=vip_oauth_available('google');
$appleLogin=vip_oauth_available('apple');
$pending=$_SESSION['vip_google_link_pending']??null;
$awaitingGoogle=is_array($pending)&&time()-(int)($pending['created']??0)<=600&&isset($pending['email']);
$googleHasCandidate=$awaitingGoogle && (int)($pending['client_id']??0)>0;
page_head('Login VIP', 'Acesse sua conta Martelli VIP com WhatsApp, e-mail ou provedor vinculado.');site_header();
?>
<main class="vip-login-page" id="conteudo"><div class="vip-login-container">
  <span class="vip-login-kicker">MARTELLI CLUB · ACESSO DO ASSINANTE</span>
  <h1>Entrar no <em>Martelli VIP.</em></h1>
  <p class="vip-login-intro">Acompanhe seus cortes e reserve quando desejar, dentro do seu plano.</p>

  <?php if($awaitingGoogle): ?>
    <div class="vip-connection-notice" role="status">
      <strong>Google verificado · Confirme seu acesso VIP</strong>
      <?php if($googleHasCandidate): ?>
        <p>Encontramos um cadastro VIP com esse e-mail. Confirme a <b>senha da conta existente</b> para vincular seu Google. Você não precisará criar outro usuário.</p>
      <?php else: ?>
        <p>O Google confirmou seu e-mail, mas ainda não está vinculado a uma assinatura. Informe o <b>WhatsApp cadastrado na barbearia e sua senha VIP</b> para conectar as contas com segurança. A confirmação do Google, sozinha, não libera sua agenda.</p>
      <?php endif; ?>
      <p class="vip-link-privacy">Não tem senha VIP? <a href="/vip-recuperar.php">Solicite seu link pessoal de ativação</a>. O vínculo expira após 10 minutos.</p>
    </div>
  <?php endif; ?>
  <div id="vip-account-feedback" class="vip-login-feedback" role="alert"<?= $loginStatusMessage!=='' ? '' : ' hidden' ?>><?= $loginStatusMessage!=='' ? '<strong>Plano temporariamente indisponível</strong><p>'.h($loginStatusMessage).'</p>' : '' ?></div>
  <section class="vip-login-panel" aria-labelledby="login-title">
    <span class="vip-login-kicker">JÁ SOU CLIENTE</span><h2 id="login-title">Acesse sua conta</h2>
    <form id="vip-account-login">
      <label for="vip-account-identifier">WhatsApp com DDD ou e-mail do cadastro</label>
      <input id="vip-account-identifier" name="identifier" autocomplete="username" required maxlength="190" placeholder="WhatsApp ou e-mail" value="<?=h($googleHasCandidate?(string)$pending['email']:'')?>">
      <label for="vip-account-password">Sua senha</label>
      <div class="vip-password-wrap"><input id="vip-account-password" type="password" autocomplete="current-password" required maxlength="128"><button type="button" class="vip-password-toggle" aria-controls="vip-account-password" aria-label="Mostrar senha">Mostrar</button></div>
      <button class="vip-login-primary" type="submit">Entrar ↗</button>
    </form>
    <div class="vip-login-support-links"><a href="/vip-recuperar.php">Esqueci minha senha</a><a href="/vip-senha.php">Alterar minha senha</a></div>
    <div class="vip-google-separator"><span>ou entre com</span></div>
    <?php if($googleLogin): ?><a class="vip-google-button" href="/vip-google.php?action=login"><span class="vip-google-icon" aria-hidden="true">G</span> Continuar com Google</a><?php else: ?><p class="vip-social-setup">Google temporariamente indisponível.</p><?php endif; ?>
    <?php if($appleLogin): ?><a class="vip-apple-button" href="/vip-apple.php?action=login">Continuar com Apple</a><?php endif; ?>
    <p class="vip-login-google-help">No primeiro vínculo, confirme seu login VIP com WhatsApp ou e-mail e senha. Seus agendamentos e o plano existente serão preservados.</p>
  </section>
  <div class="vip-login-bottom"><a href="/vip-cadastro.php">Não sou VIP · Ver planos</a><a href="/">Voltar ao site</a></div>
</div></main>
<script>window.MARTELLI_VIP_CSRF=<?=json_encode(csrf())?>;</script>
<?php site_footer();page_end('<script src="/js/vip-account-v44.js?v=44" defer></script>'); ?>
