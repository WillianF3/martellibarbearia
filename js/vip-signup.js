/*
 * Cadastro Martelli VIP: controla planos, valida a senha e envia a solicitação.
 * Não aprova assinaturas; a decisão e a integridade dos dados pertencem à API.
 */
'use strict';
(() => {
  /** Controller isolado: sem variáveis globais e sem dependência de bibliotecas externas. */
  class VipSignupController {
    constructor(form) {
      this.form = form;
      this.grid = document.getElementById('vip-join-plan-grid');
      this.select = document.getElementById('vip-join-plan');
      this.alert = document.getElementById('vip-join-alert');
      this.button = document.getElementById('vip-join-submit');
      this.password = document.getElementById('vip-join-password');
      this.confirmation = document.getElementById('vip-join-password-confirm');
      this.plans = [];
    }

    /** Escapa dados vindos da API antes de utilizá-los em HTML dinâmico. */
    static escape(value) {
      return String(value ?? '').replace(/[&<>"']/g, character =>
        ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character]));
    }

    /** Mostra preços vindos da API, que são persistidos em centavos. */
    static money(cents) {
      return (Number(cents || 0) / 100).toLocaleString('pt-BR', {style: 'currency', currency: 'BRL'});
    }

    /** Inicializa os eventos apenas quando o formulário existe na página. */
    init() {
      document.querySelectorAll('.vip-v51-toggle').forEach(toggle =>
        toggle.addEventListener('click', () => this.togglePassword(toggle)));
      this.password.addEventListener('input', () => this.validatePasswords());
      this.confirmation.addEventListener('input', () => this.validatePasswords());
      this.select.addEventListener('change', () => this.updateSelection());
      this.grid.addEventListener('click', event => this.selectPlan(event));
      this.form.addEventListener('submit', event => this.submit(event));
      this.loadPlans();
    }

    /** Alterna visibilidade sem modificar o valor nem armazenar a senha. */
    togglePassword(button) {
      const field = document.getElementById(button.dataset.toggle);
      if (!field) return;
      const show = field.type === 'password';
      field.type = show ? 'text' : 'password';
      button.textContent = show ? 'Ocultar' : 'Mostrar';
      button.setAttribute('aria-pressed', String(show));
      button.setAttribute('aria-label', (show ? 'Ocultar' : 'Mostrar') + ' senha');
    }

    /** Impede o envio se senha e confirmação forem diferentes. */
    validatePasswords() {
      this.confirmation.setCustomValidity(
        this.confirmation.value && this.password.value !== this.confirmation.value
          ? 'As senhas precisam ser iguais.' : '');
    }

    /** Descrição pública do plano escolhido; não define valores de cobrança. */
    planDetails(plan) {
      const cuts = Number(plan.cuts_per_month);
      return `${VipSignupController.money(plan.monthly_price_cents)} / mês · ${cuts} ${cuts === 1 ? 'atendimento' : 'atendimentos'} por ciclo`;
    }

    /** Mantém seletor, resumo e cartão destacado sincronizados. */
    updateSelection() {
      const selected = this.plans.find(plan => String(plan.id) === this.select.value);
      document.getElementById('vip-join-selected').textContent = selected
        ? `${selected.name} — ${this.planDetails(selected)}`
        : 'Selecione um plano para ver os detalhes.';
      this.grid.querySelectorAll('[data-plan]').forEach(element =>
        element.classList.toggle('chosen', element.dataset.plan === this.select.value));
    }

    /** Consulta apenas planos publicados; dados HTML são escapados antes do uso. */
    async loadPlans() {
      try {
        const response = await fetch('/api/vip.php?action=public_plans', {credentials: 'same-origin'});
        if (!response.ok) throw new Error('Não foi possível consultar os planos.');
        const body = await response.json();
        this.plans = Array.isArray(body.plans) ? body.plans : [];
        const esc = VipSignupController.escape;
        const money = VipSignupController.money;
        this.select.innerHTML = '<option value="">Selecione um plano</option>' +
          this.plans.map(plan => `<option value="${Number(plan.id)}">${esc(plan.name)} — ${money(plan.monthly_price_cents)}</option>`).join('');
        if (!this.plans.length) {
          this.grid.innerHTML = '<p class="vip-join-muted">Ainda não há planos disponíveis. Converse com a equipe para conhecer as próximas opções.</p>';
          this.button.disabled = true;
          return;
        }
        this.grid.innerHTML = this.plans.map(plan => `<button type="button" class="vip-join-plan" data-plan="${Number(plan.id)}" aria-label="Selecionar plano ${esc(plan.name)}"><span class="vip-join-plan-kicker">MARTELLI VIP</span><strong>${esc(plan.name)}</strong><span class="vip-join-plan-desc">${esc(plan.description || 'Assinatura mensal de atendimentos.')}</span><span class="vip-join-plan-price">${money(plan.monthly_price_cents)} <small>/ ciclo de 30 dias</small></span><span class="vip-join-plan-cuts">${Number(plan.cuts_per_month)} ${Number(plan.cuts_per_month) === 1 ? 'atendimento incluído' : 'atendimentos incluídos'} por ciclo</span><span class="vip-join-select-label">Escolher este plano ↗</span></button>`).join('');
        const planId = new URLSearchParams(location.search).get('plano');
        if (planId && this.plans.some(plan => String(plan.id) === planId)) {
          this.select.value = planId;
          this.updateSelection();
        }
      } catch (error) {
        this.grid.textContent = error.message;
        this.button.disabled = true;
      }
    }

    /** Permite escolher um cartão e avança para os campos do cadastro. */
    selectPlan(event) {
      const element = event.target.closest('[data-plan]');
      if (!element) return;
      this.select.value = element.dataset.plan;
      this.updateSelection();
      document.getElementById('vip-formulario').scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    /** Envia os dados com token CSRF; só a API pode criar a solicitação. */
    async submit(event) {
      event.preventDefault();
      this.alert.hidden = true;
      this.validatePasswords();
      if (!this.form.reportValidity()) return;
      if (!this.plans.some(plan => String(plan.id) === this.select.value)) {
        this.alert.textContent = 'Escolha um plano disponível.';
        this.alert.hidden = false;
        return;
      }
      this.button.disabled = true;
      const previousLabel = this.button.innerHTML;
      this.button.textContent = 'Enviando solicitação…';
      try {
        const data = Object.fromEntries(new FormData(this.form));
        const response = await fetch('/api/vip.php?action=request_create', {
          method: 'POST', credentials: 'same-origin',
          headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.MARTELLI_VIP_CSRF},
          body: JSON.stringify(data),
        });
        const result = await response.json().catch(() => { throw new Error('Resposta inesperada do servidor.'); });
        if (!response.ok || !result.ok) throw new Error(result.error || 'Não foi possível enviar agora.');
        this.password.value = '';
        this.confirmation.value = '';
        this.form.hidden = true;
        const success = document.getElementById('vip-join-success');
        success.hidden = false;
        success.focus();
      } catch (error) {
        this.showError(String(error?.message || 'Não foi possível enviar agora.'));
        this.button.disabled = false;
        this.button.innerHTML = previousLabel;
      }
    }

    /** Em CPF/WhatsApp existente, orienta recuperação sem expor dados privados. */
    showError(message) {
      this.alert.replaceChildren(document.createTextNode(message));
      const conflictingIdentity = /CPF já cadastrado com outro WhatsApp|CPF já está cadastrado com outro contato|WhatsApp já vinculado a outro CPF|Já existe uma conta associada aos dados informados/i.test(message);
      const existingVip = /já possui cadastro VIP|Já existe uma solicitação VIP|já está vinculada a um assinante VIP/i.test(message);
      if (conflictingIdentity || existingVip) {
        const help = document.createElement('div');
        help.className = 'vip-duplicate-help';
        const explanation = document.createElement('p');
        explanation.textContent = conflictingIdentity
          ? 'Já existe uma conta associada aos dados informados. Não crie outra conta. Entre com seu login VIP; se o telefone estiver desatualizado, solicite a correção à barbearia após confirmar sua identidade.'
          : 'Se você já possui uma conta VIP aprovada, entre com seu login existente. Caso não consiga acessar, peça auxílio à barbearia.';
        const actions = document.createElement('div');
        actions.className = 'vip-duplicate-actions';
        const login = document.createElement('a');
        login.href = '/vip-entrar.php';
        login.textContent = 'Acessar minha conta existente ↗';
        login.className = 'vip-recovery-primary';
        const support = document.createElement('a');
        support.href = '/#contato';
        support.textContent = 'Solicitar atualização do WhatsApp ↗';
        support.className = 'vip-recovery-secondary';
        actions.append(login, support);
        help.append(explanation, actions);
        this.alert.appendChild(help);
      }
      this.alert.hidden = false;
    }
  }

  const form = document.getElementById('vip-join-form');
  if (form) new VipSignupController(form).init();
})();
