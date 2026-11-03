/* Martelli - apresentação da validação de WhatsApp; PHP continua sendo a autoridade. */
(function (root) {
  'use strict';

  /** Serviço de telefone; não altera o formato legado salvo no banco (DDD + número). */
  class BrazilianPhone {
    /** Aceita DDI 55 opcional e retorna os 10/11 dígitos locais. */
    static toLocal(value) {
      let number = String(value == null ? '' : value).replace(/\D/g, '');
      if ((number.length === 12 || number.length === 13) && number.startsWith('55')) {
        number = number.slice(2);
      }
      return /^[1-9][0-9][0-9]{8,9}$/.test(number) ? number : '';
    }

    /** Adiciona o DDI apenas aos números aprovados pela validação. */
    static forWhatsApp(value) {
      const local = BrazilianPhone.toLocal(value);
      return local ? '55' + local : '';
    }

    /** Exibe o erro no próprio campo antes do envio do agendamento. */
    static validateInput(input, required = true) {
      if (!input) return null;
      const raw = input.value.trim();
      const valid = BrazilianPhone.toLocal(raw);
      if (!valid && (required || raw !== '')) {
        input.setCustomValidity('Informe WhatsApp com DDD, com ou sem +55.');
        input.reportValidity();
        return null;
      }
      input.setCustomValidity('');
      return valid;
    }
  }

  // Contrato público preservado: booking.js e scripts antigos usam MartelliPhone.*.
  const api = Object.freeze({
    toLocal: BrazilianPhone.toLocal,
    forWhatsApp: BrazilianPhone.forWhatsApp,
    validateInput: BrazilianPhone.validateInput,
  });
  root.MartelliPhone = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
})(typeof window !== 'undefined' ? window : globalThis);
