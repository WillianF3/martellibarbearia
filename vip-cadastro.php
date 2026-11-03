<?php
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/layout.php';
require_once __DIR__.'/app/vip_auth.php';
// Um assinante autenticado deve ir diretamente à agenda, nunca a outro pré-cadastro.
// Não inferir login apenas porque a pessoa mantém o Gmail aberto no navegador.
try {
    $vipLogged = vip_auth_identity();
    if ($vipLogged && (int)($vipLogged['must_change_password']??0)===0) {
        $check=db()->prepare("SELECT 1 FROM mt_vip_memberships WHERE client_id=? AND status='active' AND ends_on>=CURDATE() LIMIT 1");
        $check->execute([(int)$vipLogged['client_id']]);
        if ($check->fetchColumn()) {header('Location: /vip-agenda.php',true,303);exit;}
    }
} catch (Throwable $e) {error_log('Martelli VIP: verificação de sessão indisponível.');}

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
page_head('Cadastro Martelli VIP', 'Escolha seu plano de assinatura Martelli VIP e envie sua solicitação de adesão. A equipe entrará em contato para confirmar as condições.');
site_header();
?>
<link rel="stylesheet" href="/css/cpf-v42.css?v=42"><main id="conteudo" class="vip-join">
  <section class="vip-join-top container">
    <span class="eyebrow">MARTELLI CLUB · SUA ASSINATURA</span>
    <h1>Faça parte do <em>Martelli VIP.</em></h1>
    <p>Escolha o plano que combina com sua rotina. Preencha seus dados e a equipe confirmará a adesão e as condições com você.</p>
    <div class="vip-login-banner"><div><strong>Já é cliente Martelli VIP?</strong><span>Faça login para acessar sua assinatura e os horários.</span></div><div class="vip-v47-actions"><a href="/vip-entrar.php" class="vip-login-cta">Fazer login ↗</a></div></div>
    <div class="vip-join-steps"><span><b>01</b> Escolha um plano</span><span><b>02</b> Informe seus dados</span><span><b>03</b> Aguarde a confirmação</span></div>
  </section>
  <section class="container vip-join-content" aria-labelledby="vip-join-plans-title">
    <div class="vip-join-plans">
      <div class="vip-join-section-heading"><span class="eyebrow">SUA ESCOLHA</span><h2 id="vip-join-plans-title">Planos disponíveis</h2><p>Cada novo ciclo VIP tem 30 dias corridos a partir da data de início. Os valores e atendimentos incluídos são definidos pela barbearia.</p></div>
      <div id="vip-join-plan-grid" class="vip-join-grid" aria-live="polite"><p class="vip-join-muted">Carregando planos…</p></div>
    </div>
    <div class="vip-join-form-panel" id="vip-formulario">
      <span class="eyebrow">PRÉ-CADASTRO VIP</span><h2>Seus dados</h2>
      <p>Este cadastro é uma <strong>solicitação de adesão</strong>. Ele não ativa uma assinatura nem gera cobrança.</p>
      <div class="vip-v51-password-intro">Crie sua senha de acesso agora. Sua conta será liberada depois que a barbearia aprovar sua assinatura.</div>
      <form id="vip-join-form" novalidate>
        <label for="vip-join-plan">Plano escolhido</label>
        <select name="plan_id" id="vip-join-plan" required><option value="">Selecione um plano</option></select>
        <div class="vip-join-selected" id="vip-join-selected" aria-live="polite">Selecione um plano para ver os detalhes.</div>
        <label for="vip-join-name">Nome completo</label>
        <input name="name" id="vip-join-name" type="text" autocomplete="name" maxlength="120" minlength="3" placeholder="Seu nome completo" value="" required>
        <label for="vip-join-phone">WhatsApp com DDD</label>
        <input name="phone" id="vip-join-phone" type="tel" autocomplete="tel" inputmode="tel" maxlength="22" placeholder="(49) 99999-9999" required>
        <label for="vip-join-email">E-mail <small>(recomendado para recuperar a senha)</small></label>
        <input name="email" id="vip-join-email" type="email" autocomplete="email" maxlength="190" placeholder="voce@email.com" value="">
        <label for="vip-join-cpf">CPF <small>(obrigatório · para evitar cadastros duplicados)</small></label>
        <input name="cpf" id="vip-join-cpf" type="text" inputmode="numeric" maxlength="14" minlength="11" autocomplete="off" placeholder="000.000.000-00" required>
        <label for="vip-join-password">Crie sua senha <small>(mínimo 10 caracteres)</small></label>
        <div class="vip-v51-passbox"><input name="password" id="vip-join-password" type="password" autocomplete="new-password" minlength="10" maxlength="128" required aria-describedby="vip-v51-pass-note"><button type="button" class="vip-v51-toggle" data-toggle="vip-join-password" aria-label="Mostrar senha" aria-pressed="false">Mostrar</button></div>
        <p id="vip-v51-pass-note" class="vip-v51-pass-note">Use uma senha exclusiva, com pelo menos 10 caracteres. Não compartilhe sua senha com a barbearia.</p>
        <label for="vip-join-password-confirm">Confirme sua senha</label>
        <div class="vip-v51-passbox"><input name="password_confirm" id="vip-join-password-confirm" type="password" autocomplete="new-password" minlength="10" maxlength="128" required><button type="button" class="vip-v51-toggle" data-toggle="vip-join-password-confirm" aria-label="Mostrar confirmação da senha" aria-pressed="false">Mostrar</button></div>
        <label for="vip-join-notes">Observações <small>(opcional)</small></label>
        <textarea name="notes" id="vip-join-notes" maxlength="300" rows="3" placeholder="Alguma informação para nossa equipe?"></textarea>
        <div class="vip-honeypot" aria-hidden="true"><label for="vip-join-site">Site<input type="text" name="website" id="vip-join-site" tabindex="-1" autocomplete="off"></label></div>
        <label class="vip-join-consent" for="vip-join-consent"><input type="checkbox" id="vip-join-consent" name="consent" value="1" required><span>Autorizo o uso dos meus dados para contato sobre esta solicitação, conforme a <a href="/privacidade.php" target="_blank" rel="noopener">Política de privacidade</a>. Li as <a href="/termos.php" target="_blank" rel="noopener">condições</a>.</span></label>
        <div class="vip-join-alert" id="vip-join-alert" role="alert" aria-live="assertive" hidden></div>
        <button class="vip-join-submit" type="submit" id="vip-join-submit">Enviar solicitação VIP <span aria-hidden="true">↗</span></button>
      </form>
      <div class="vip-join-success" id="vip-join-success" hidden tabindex="-1" role="status">
        <span class="vip-join-check" aria-hidden="true">✓</span><h3>Solicitação recebida!</h3>
        <p>Seu pré-cadastro foi enviado à equipe Martelli. A senha já foi registrada com proteção criptográfica e funcionará no login após a aprovação da assinatura. Aguarde o contato pelo WhatsApp.</p>
        <a href="/" class="vip-join-submit">Voltar ao site</a>
      </div>
    </div>
  </section>
  <section class="container vip-join-footnote"><p>As mensalidades não são cobradas neste site. O envio não garante ativação, reserva de horário nem desconto automático em comandas. A equipe entrará em contato para combinar a contratação.</p></section>
</main>
<link rel="stylesheet" href="/css/vip-signup-password-v51.css?v=51">
<script>window.MARTELLI_VIP_CSRF=<?=json_encode(csrf())?>;</script>
<?php site_footer(); page_end('<link rel="stylesheet" href="/css/vip-login-gate-v47.css?v=47"><script src="/js/cpf-v42.js?v=52" defer></script><script src="/js/vip-signup.js?v=52" defer></script><script src="/js/vip-login-gate-v47.js?v=47" defer></script>'); ?>
