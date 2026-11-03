/* Máscara visual de CPF; a validação definitiva ocorre no PHP e no MySQL. */
(() => {
  'use strict';

  /** Aplica a pontuação sem aceitar mais de 11 dígitos no campo. */
  class CpfMask {
    static format(value) {
      const digits = String(value || '').replace(/\D/g, '').slice(0, 11);
      if (digits.length > 9) return digits.replace(/^(\d{3})(\d{3})(\d{3})(\d{1,2})$/, '$1.$2.$3-$4');
      if (digits.length > 6) return digits.replace(/^(\d{3})(\d{3})(\d{1,3})$/, '$1.$2.$3');
      if (digits.length > 3) return digits.replace(/^(\d{3})(\d{1,3})$/, '$1.$2');
      return digits;
    }

    /** Utiliza delegação de evento: funciona também nos campos criados dinamicamente. */
    static handleInput(event) {
      const target = event.target;
      if (!(target instanceof HTMLInputElement) || !target.matches('input[name="cpf"],input[name="new_client_cpf"]')) return;
      const masked = CpfMask.format(target.value);
      if (target.value !== masked) target.value = masked;
    }
  }

  document.addEventListener('input', CpfMask.handleInput);
})();
