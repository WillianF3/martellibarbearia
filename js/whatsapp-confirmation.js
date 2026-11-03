/* Barbearia Martelli \u2014 mensagem WhatsApp V3.2.
 * Script ASCII com Unicode escapes para evitar caracteres quebrados (\ufffd).
 * A API retorna a reserva salva; nada aqui fica fixo em um cliente.
 * O texto tem exatamente um asterisco de cada lado dos trechos em negrito.
 */
(function (root) {
  'use strict';

  const T = Object.freeze({
    head: "\u2702\ufe0f *BARBEARIA MARTELLI* \ud83c\uddee\ud83c\uddf9",
    title: "*CONFIRMA\u00c7\u00c3O DE AGENDAMENTO* \u2705",
    hello: "Ol\u00e1, ",
    helloEnd: "! Tudo bem?",
    helloTeam: "Ol\u00e1, equipe Martelli! Tudo bem?",
    desc: "Acabei de realizar meu agendamento na Barbearia Martelli. Seguem os detalhes:",
    number: "\ud83d\udccb *Reserva:* #",
    client: "\ud83d\udc64 *Cliente:* ",
    service: "\u2702\ufe0f *Servi\u00e7o:* ",
    barber: "\ud83d\udc88 *Barbeiro:* ",
    date: "\ud83d\udcc5 *Data:* ",
    time: "\ud83d\udd52 *Hor\u00e1rio:* ",
    value: "\ud83d\udcb0 *Valor:* ",
    success: "\u2705 *Agendamento realizado com sucesso!*",
    bye: "Obrigado! At\u00e9 l\u00e1! \ud83d\udc88\ud83c\uddee\ud83c\uddf9",
    team: "Equipe Martelli",
  });

  // Remove apenas caracteres de controle e marcadores de formata\xe7\xe3o em campos livres.
  function safe(value) {
    return String(value == null ? '' : value)
      .replace(/[\r\n\t*_~`]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function datePtBr(ymd) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(ymd || ''));
    if (!match) return safe(ymd);
    const utc = new Date(`${match[1]}-${match[2]}-${match[3]}T12:00:00Z`);
    if (Number.isNaN(utc.getTime())) return safe(ymd);
    const weekday = new Intl.DateTimeFormat('pt-BR', {
      weekday: 'long', timeZone: 'UTC'
    }).format(utc);
    return `${match[3]}/${match[2]}/${match[1]} (${weekday})`;
  }

  function timePtBr(value) {
    const match = /^(\d{2}):(\d{2})/.exec(String(value || ''));
    return match ? `${match[1]}h${match[2]}` : safe(value);
  }

  function buildConfirmationMessage(booking, options = {}) {
    const barber = safe(booking.barber_name || options.barberName) || T.team;
    const greeting = options.recipient === 'barber'
      ? T.hello + barber + T.helloEnd : T.helloTeam;
    const amount = typeof options.money === 'function'
      ? options.money(booking.price)
      : Number(booking.price || 0).toLocaleString('pt-BR', {style:'currency',currency:'BRL'});

    return [
      T.head,
      T.title,
      '',
      greeting,
      '',
      T.desc,
      '',
      T.number + safe(booking.id),
      T.client + safe(options.clientName),
      T.service + safe(booking.service),
      T.barber + barber,
      T.date + datePtBr(booking.date),
      T.time + timePtBr(booking.time),
      T.value + safe(amount),
      '',
      T.success,
      '',
      T.bye
    ].join('\n');
  }

  // Para reservas das 18h em diante: é um PEDIDO, nunca uma confirmação.
  function buildApprovalRequestMessage(booking, options = {}) {
    const barber = safe(booking.barber_name || options.barberName) || T.team;
    const greeting = options.recipient === 'barber'
      ? T.hello + barber + T.helloEnd : T.helloTeam;
    const amount = typeof options.money === 'function'
      ? options.money(booking.price)
      : Number(booking.price || 0).toLocaleString('pt-BR', {style:'currency',currency:'BRL'});
    return [
      T.head,
      '*SOLICITA\u00c7\u00c3O DE ATENDIMENTO AP\u00d3S AS 18H*',
      '',
      greeting,
      '',
      'Gostaria de verificar se voc\u00ea consegue me atender nesse hor\u00e1rio.',
      '',
      T.number + safe(booking.id),
      T.client + safe(options.clientName),
      T.service + safe(booking.service),
      T.barber + barber,
      T.date + datePtBr(booking.date),
      T.time + timePtBr(booking.time),
      T.value + safe(amount),
      '',
      '*Status: aguardando aprova\u00e7\u00e3o.*',
      'Voc\u00ea pode confirmar se ser\u00e1 poss\u00edvel realizar o atendimento?',
      '',
      'Obrigado!'
    ].join('\n');
  }

  // encodeURIComponent alone leaves '*' unescaped. Convert all punctuation used
  // in WhatsApp's markdown to percent encoding, while preserving it after decode.
  function buildWhatsAppUrl(phone, text) {
    const digits = String(phone || '').replace(/\D/g, '');
    if (!/^55\d{10,11}$/.test(digits)) return '';
    const encoded = encodeURIComponent(text).replace(/[!'()*]/g, char =>
      '%' + char.charCodeAt(0).toString(16).toUpperCase());
    return `https://wa.me/${digits}?text=${encoded}`;
  }

  const exports = Object.freeze({buildConfirmationMessage, buildApprovalRequestMessage, buildWhatsAppUrl});
  root.MartelliWhatsapp = exports;
  if (typeof module !== 'undefined' && module.exports) module.exports = exports;
})(typeof window !== 'undefined' ? window : globalThis);
