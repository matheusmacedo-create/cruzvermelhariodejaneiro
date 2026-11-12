/* Página de doação em inglês (/en/donate/): doação internacional, só cartão de crédito, uma vez, sem CPF.
   Conversa com o mesmo backend de /doe/ (/doe/api/), mandando internacional: true, o país e um documento
   só com números no lugar do CPF. Irmã do doe.js: as regras de validação são as mesmas do servidor.

   O número do cartão vai direto para a API e nunca é guardado aqui. A cobrança é em reais; os valores em
   dólar, euro e libra são aproximações pelo câmbio de referência que o info.php devolve.
   Com ?t=<token> na URL a página mostra o andamento ou o agradecimento daquela doação.

   Os textos ficam em inglês porque são o que a pessoa lê; os comentários seguem em português, como no resto. */
(function () {
  'use strict';
  var API = '/doe/api/';
  var TOKEN = /^[a-f0-9]{40}$/;
  var INTERVALO_STATUS = 5000;
  var EMAIL = 'contato@cruzvermelhariodejaneiro.org';
  var PAISES = 'AD AE AF AG AI AL AM AO AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BW BY BZ CA CD CF CG CH CI CK CL CM CN CO CR CU CV CW CY CZ DE DJ DK DM DO DZ EC EE EG ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GT GU GW GY HK HN HR HT HU ID IE IL IM IN IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TG TH TJ TL TM TN TO TR TT TV TW TZ UA UG US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW'.split(' ');
  var info = null, origemAtual = null, valorAtual = 0, iniciado = false;

  function q(s, raiz) { return (raiz || document).querySelector(s); }
  function qa(s, raiz) { return Array.prototype.slice.call((raiz || document).querySelectorAll(s)); }
  function digitos(v) { return String(v || '').replace(/\D+/g, ''); }
  function param(chave) { return new URLSearchParams(location.search).get(chave) || ''; }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  /* Reais escritos para quem lê em inglês: R$ 250.00 (e R$ 250 nos botões). */
  function brl(centavos, semCentavos) {
    var n = centavos / 100;
    return 'R$ ' + n.toLocaleString('en-US', { minimumFractionDigits: semCentavos && n % 1 === 0 ? 0 : 2, maximumFractionDigits: 2 });
  }
  /* Aproximação na moeda de quem doa. Sem câmbio (Banco Central fora do ar), devolve '' e nada aparece. */
  function aproximado(centavos, moedas) {
    var c = info && info.cambio;
    if (!c || !centavos) return '';
    var rotulos = { usd: 'US$ ', eur: '€ ', gbp: '£ ' };
    return (moedas || ['usd', 'eur', 'gbp']).filter(function (m) { return c[m] > 0; }).map(function (m) {
      var v = centavos / 100 / c[m];
      return rotulos[m] + (v >= 10 ? Math.round(v).toLocaleString('en-US') : v.toFixed(2));
    }).join(' · ');
  }

  /* Origem da visita: mesma regra do doe.js (UTMs da URL, depois os guardados, depois o referrer). */
  function origem() {
    var p = new URLSearchParams(location.search), dados = {}, salvo = {};
    try { salvo = JSON.parse(sessionStorage.getItem('mcp_origem') || '{}'); } catch (e) { salvo = {}; }
    ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid'].forEach(function (k) {
      var v = p.get(k) || salvo[k] || '';
      if (v) dados[k] = String(v).slice(0, 160);
    });
    if (!dados.utm_source && document.referrer) {
      try {
        var ref = new URL(document.referrer);
        if (ref.host === location.host) {
          dados.utm_source = 'site';
          dados.utm_medium = (ref.pathname.replace(/^\/|\/$/g, '').replace(/\.html$/, '') || 'home').slice(0, 120);
          dados.utm_campaign = dados.utm_campaign || 'doacao';
        } else {
          dados.utm_source = ref.hostname.slice(0, 120);
          dados.utm_medium = 'referral';
        }
      } catch (e) { /* referrer estranho */ }
    }
    try { sessionStorage.setItem('mcp_origem', JSON.stringify(dados)); } catch (e) { /* segue sem guardar */ }
    return dados;
  }

  function rastrear(eventoMeta, dadosMeta, eventoGa, dadosGa, opcoes) {
    if (window.fbq && eventoMeta) try { fbq('track', eventoMeta, dadosMeta || {}, opcoes || {}); } catch (e) { /* bloqueador */ }
    if (window.gtag && eventoGa) try { gtag('event', eventoGa, dadosGa || {}); } catch (e) { /* bloqueador */ }
  }

  function api(caminho, opcoes) {
    var cabecalhos = { Accept: 'application/json' };
    if (opcoes && opcoes.method === 'POST') cabecalhos['Content-Type'] = 'application/json';
    return fetch(API + caminho, Object.assign({ credentials: 'same-origin', headers: cabecalhos }, opcoes || {}))
      .then(function (r) { return r.json().then(function (d) { d.http = r.status; return d; }, function () { return { ok: false, http: r.status }; }); })
      .catch(function () { return { ok: false, http: 0 }; });
  }

  function luhnValido(numero) {
    var soma = 0, dobra = false;
    for (var i = numero.length - 1; i >= 0; i--) {
      var n = parseInt(numero[i], 10);
      if (dobra) { n *= 2; if (n > 9) n -= 9; }
      soma += n; dobra = !dobra;
    }
    return numero.length > 0 && soma % 10 === 0;
  }
  /* Documento: espaço, ponto, hífen e barra saem; letra não é cortada (seria outro documento). */
  function documentoLimpo(v) { return String(v || '').replace(/[\s.\-\/]+/g, ''); }

  var mascaras = {
    cartao: function (v) { return digitos(v).slice(0, 19).replace(/(\d{4})(?=\d)/g, '$1 '); },
    validade: function (v) { v = digitos(v).slice(0, 4); return v.length > 2 ? v.slice(0, 2) + '/' + v.slice(2) : v; },
    cvv: function (v) { return digitos(v).slice(0, 4); },
    telefone: function (v) { return String(v || '').replace(/[^\d+\s()\-]/g, '').slice(0, 24); },
    valor: function (v) { return digitos(v).slice(0, 6); }
  };
  function aplicarMascara(el, fn) { if (el) el.addEventListener('input', function () { el.value = fn(el.value); }); }

  // ------------------------------------------------------------------ mensagens (o servidor responde em português)
  var MENSAGENS = {
    valor: 'Choose an amount within the limits shown.',
    nome: 'Please enter your full name.',
    email: 'Please enter a valid e-mail address.',
    pais: 'Please select your country.',
    telefone: 'Please enter your phone number with the country code, for example +1 415 555 2671.',
    documento: 'Please enter an ID number that has digits only (5 to 20 digits). If your passport number has letters, use another government ID that is all digits, or write to us.',
    cartao_numero: 'That card number does not look right. Please check it.',
    cartao_nome: 'Please enter the name as it appears on the card.',
    cartao_validade: 'Please enter the expiry date as MM/YY.',
    cartao_cvv: 'Please enter the security code on your card.',
    aceite: 'Please confirm that you have read the Privacy Policy.',
    internacional: 'Card donations from outside Brazil are not available right now. Please write to ' + EMAIL + '.',
    frequencia: 'From outside Brazil, donations are one-off for now.',
    metodo: 'From outside Brazil, donations are by credit card.'
  };
  function mensagemDoServidor(r) {
    if (r.campo && MENSAGENS[r.campo]) return MENSAGENS[r.campo];
    if (r.http === 402) return 'Your card was declined by the issuer. Some banks block a first charge from another country: approve it in your banking app, try another card, or write to us.';
    if (r.http === 429) return 'Too many attempts in a row. Please wait a few minutes and try again.';
    if (r.http === 0) return 'No connection to the server. Please check your internet and try again.';
    if (r.http === 422 || r.http === 400) return 'The payment was not approved. Please check your card details or try another card. If it keeps failing, write to ' + EMAIL + '.';
    return 'We could not complete your donation. Please try again in a moment, or write to ' + EMAIL + '.';
  }

  // ------------------------------------------------------------------ telas
  function mostrar(tela) {
    ['valor', 'dados', 'andamento', 'obrigado', 'fora'].forEach(function (t) {
      var el = q('#doa-' + t);
      if (el) el.hidden = t !== tela;
    });
    var passo = tela === 'valor' ? 1 : (tela === 'dados' ? 2 : 3);
    qa('.doe-passo').forEach(function (el, i) {
      el.classList.toggle('ativo', i + 1 === passo);
      el.classList.toggle('feito', i + 1 < passo);
    });
    var passos = q('.doe-passos');
    if (passos) passos.hidden = tela === 'fora';
  }

  function erro(mensagem, campo) {
    qa('.doe-campo.erro').forEach(function (c) { c.classList.remove('erro'); });
    qa('.doe-erro').forEach(function (a) { a.textContent = ''; });
    if (!mensagem) return false;
    var visivel = q('#doa-dados').hidden ? q('#doa-erro-valor') : q('#doa-erro');
    visivel.textContent = mensagem;
    var el = campo && q('#doa-' + campo);
    if (el && el.closest('.doe-campo')) { el.closest('.doe-campo').classList.add('erro'); el.focus(); }
    else visivel.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return false;
  }

  function taxaAtual() {
    var t = (info && info.taxa && info.taxa.cartao) || { pct: 0, fixa: 0 };
    return Math.max(0, Math.round(valorAtual * (t.pct || 0) / 100) + (t.fixa || 0));
  }
  function cobreTaxa() { var c = q('#doa-cobre'); return !!(c && c.checked); }
  function total() { return valorAtual + (cobreTaxa() ? taxaAtual() : 0); }

  function atualizarTotais() {
    var t = total();
    q('#doa-taxa-valor').textContent = brl(taxaAtual());
    q('#doa-cobre-caixa').classList.toggle('marcado', cobreTaxa());
    // Sem custos de processamento configurados, a opção de cobri-los não aparece.
    q('#doa-cobre-caixa').hidden = taxaAtual() <= 0;
    qa('.doe-total').forEach(function (el) { el.textContent = brl(t); });
    q('#doa-enviar').textContent = 'Donate ' + brl(t);
    var perto = aproximado(valorAtual);
    q('#doa-aprox').textContent = perto ? 'About ' + perto : '';
    var pertoTotal = aproximado(t);
    q('#doa-aprox-total').textContent = pertoTotal ? 'About ' + pertoTotal + ', converted by your card issuer.' : 'Charged in Brazilian reais; your card issuer converts it to your currency.';
    qa('.doe-valor').forEach(function (b) {
      var pequeno = q('small', b), a = aproximado(parseInt(b.getAttribute('data-valor'), 10), ['usd']);
      if (pequeno) pequeno.textContent = a ? '≈ ' + a : '';
    });
  }

  function marcarValor(centavos) {
    valorAtual = centavos;
    qa('.doe-valor').forEach(function (b) { b.classList.toggle('ativo', parseInt(b.getAttribute('data-valor'), 10) === centavos); });
    atualizarTotais();
  }
  function valorDentroDosLimites() {
    var min = info ? info.minimo_centavos : 500, max = info ? info.maximo_centavos : 5000000;
    if (!valorAtual || valorAtual < min) return erro('Please choose an amount of at least ' + brl(min, true) + '.', 'outro');
    if (valorAtual > max) return erro('To give more than ' + brl(max, true) + ', please write to ' + EMAIL + '.', 'outro');
    return true;
  }

  // ------------------------------------------------------------------ andamento e agradecimento
  function andamento() {
    q('#doa-andamento').innerHTML = '<div class="doe-pix"><h2>Confirming your payment…</h2>'
      + '<p>Your card was sent to the bank. As soon as the confirmation arrives, your thank-you appears here. You can keep this page open.</p>'
      + '<div class="doe-status" role="status"><span class="doe-pulso" aria-hidden="true"></span> Waiting for the bank…</div></div>';
    mostrar('andamento');
  }

  function telaObrigado(d) {
    var perto = aproximado(d.total_centavos);
    q('#doa-obrigado').innerHTML = '<div class="doe-obrigado">'
      + '<div class="doe-selo" aria-hidden="true"><i class="fa-solid fa-circle-check"><svg class="ico ico-circle-check" aria-hidden="true" focusable="false"><use href="#i-circle-check"/></svg></i></div>'
      + '<h2>Thank you, ' + esc(d.nome) + '!</h2>'
      + '<p>Your donation of <b>' + brl(d.total_centavos) + '</b> is confirmed. We sent the receipt to <b>' + esc(d.email) + '</b>.</p>'
      + '<div class="doe-recibo">'
      + '<div><span>Reference</span><b>' + esc(d.protocolo) + '</b></div>'
      + '<div><span>Payment method</span><b>Credit card' + (d.cartao && d.cartao.ultimos4 ? ' ···· ' + esc(d.cartao.ultimos4) : '') + '</b></div>'
      + '<div><span>Amount</span><b>' + brl(d.total_centavos) + '</b></div>'
      + '</div>'
      + (perto ? '<p>That is about ' + esc(perto) + '. Your statement will show the amount converted by your card issuer.</p>' : '<p>Your statement will show the amount converted by your card issuer.</p>')
      + (d.anonima ? '<p>We recorded your donation as <b>anonymous</b>: your name will not appear in public acknowledgements.</p>' : '')
      + '<p>Your gift funds volunteer training, first-aid training and community action across the state of Rio de Janeiro.</p>'
      + '<a class="btn btn-red doe-acao" href="/en/news/">Read our news</a>'
      + '<a class="btn btn-outline doe-acao" href="https://www.instagram.com/cruzvermelhabrasileirarj/" target="_blank" rel="noopener" style="margin-top:8px">Follow us on Instagram</a>'
      + '</div>';
    mostrar('obrigado');
    registrarDoacao(d);
  }

  /* A doação conta uma vez, só com consentimento, e o id é o protocolo (mesma regra do doe.js). */
  function registrarDoacao(d) {
    var id = d.protocolo, c = null;
    try { c = window.cvrjMedicao && window.cvrjMedicao.ler(); } catch (e) { /* bloco de medição ausente */ }
    if (!id || !c || (!c.estatistica && !c.marketing)) return;
    var pagoEm = d.pago_em ? Date.parse(String(d.pago_em).replace(' ', 'T') + 'Z') : NaN;
    if (!isNaN(pagoEm) && Date.now() - pagoEm > 864e5) return;
    try {
      var chave = 'doe_purchase_' + id;
      if (localStorage.getItem(chave)) return;
      localStorage.setItem(chave, '1');
    } catch (e) { /* sem localStorage: registra assim mesmo */ }
    rastrear('Purchase', { content_name: 'Doação internacional', content_category: 'doacao', value: d.total_centavos / 100, currency: 'BRL' },
      'purchase', { transaction_id: id, value: d.total_centavos / 100, currency: 'BRL', items: [{ item_id: 'doacao-internacional', item_name: 'Doação internacional', item_category: 'Doação', price: d.total_centavos / 100, quantity: 1 }] },
      { eventID: id + '-doacao' });
  }

  function naoConcluiu(mensagem) {
    try { history.replaceState(null, '', location.pathname); } catch (e) { /* segue */ }
    mostrar(valorAtual ? 'dados' : 'valor');
    erro(mensagem);
  }

  function acompanhar(token) {
    function passo() {
      api('status.php?t=' + encodeURIComponent(token)).then(function (d) {
        if (d.status === 'pago') { telaObrigado(d); return; }
        if (d.status === 'recusado' || d.status === 'expirado' || d.status === 'estornado') {
          naoConcluiu(mensagemDoServidor({ http: 402 }));
          return;
        }
        setTimeout(passo, document.hidden ? INTERVALO_STATUS * 3 : INTERVALO_STATUS);
      });
    }
    setTimeout(passo, INTERVALO_STATUS);
  }

  // ------------------------------------------------------------------ envio
  function lerFormulario() {
    return {
      internacional: true,
      idioma: 'en',
      frequencia: 'unica',
      metodo: 'cartao',
      valor_centavos: valorAtual,
      nome: q('#doa-nome').value.trim(),
      email: q('#doa-email').value.trim(),
      pais: q('#doa-pais').value,
      telefone: digitos(q('#doa-telefone').value),
      documento: documentoLimpo(q('#doa-documento').value),
      cobre_taxa: cobreTaxa(),
      anonimo: q('#doa-anonimo').checked,
      aceite: q('#doa-aceite').checked,
      site: q('#doa-site').value,
      origem: origemAtual,
      cartao: {
        numero: digitos(q('#doa-cartao_numero').value), nome: q('#doa-cartao_nome').value.trim(),
        validade: q('#doa-cartao_validade').value, cvv: q('#doa-cartao_cvv').value
      }
    };
  }

  function validar(d) {
    if (valorDentroDosLimites() !== true) return false;
    if (d.nome.length < 5 || d.nome.indexOf(' ') < 0) return erro(MENSAGENS.nome, 'nome');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.email)) return erro(MENSAGENS.email, 'email');
    if (!/^[A-Z]{2}$/.test(d.pais)) return erro(MENSAGENS.pais, 'pais');
    var tel = d.telefone.replace(/^0+/, '');
    if (tel.length < 8 || tel.length > 15) return erro(MENSAGENS.telefone, 'telefone');
    if (!/^\d{5,20}$/.test(d.documento)) return erro(MENSAGENS.documento, 'documento');
    if (d.cartao.numero.length < 13 || !luhnValido(d.cartao.numero)) return erro(MENSAGENS.cartao_numero, 'cartao_numero');
    if (d.cartao.nome.length < 3) return erro(MENSAGENS.cartao_nome, 'cartao_nome');
    var validade = digitos(d.cartao.validade), mes = parseInt(validade.slice(0, 2), 10);
    if (validade.length !== 4 || mes < 1 || mes > 12) return erro(MENSAGENS.cartao_validade, 'cartao_validade');
    if (digitos(d.cartao.cvv).length < 3) return erro(MENSAGENS.cartao_cvv, 'cartao_cvv');
    if (!d.aceite) return erro(MENSAGENS.aceite, 'aceite');
    return true;
  }

  function enviar(e) {
    e.preventDefault();
    erro('');
    var dados = lerFormulario();
    if (validar(dados) !== true) return;
    var botao = q('#doa-enviar'), rotulo = botao.textContent;
    botao.disabled = true;
    botao.textContent = 'Processing…';
    rastrear('AddPaymentInfo', { content_name: 'Doação internacional', content_category: 'doacao', value: total() / 100, currency: 'BRL' },
      'add_payment_info', { currency: 'BRL', value: total() / 100, payment_type: 'cartao' });
    api('doacoes.php', { method: 'POST', body: JSON.stringify(dados) }).then(function (r) {
      dados.cartao = null;
      botao.disabled = false;
      botao.textContent = rotulo;
      if (!r.ok || r.status === 'recusado') { erro(mensagemDoServidor(r.ok ? { http: 402 } : r), MENSAGENS[r.campo] ? r.campo : ''); return; }
      // O cartão sai da tela assim que a cobrança é criada.
      ['cartao_numero', 'cartao_validade', 'cartao_cvv'].forEach(function (c) { q('#doa-' + c).value = ''; });
      // A doação ganha endereço próprio (o mesmo do link do e-mail): dá para recarregar sem perder o andamento.
      try { history.replaceState(null, '', location.pathname + '?t=' + encodeURIComponent(r.token)); } catch (e2) { /* segue */ }
      if (r.status === 'pago') { telaObrigado(r); return; }
      andamento();
      acompanhar(r.token);
    });
  }

  // ------------------------------------------------------------------ início
  function montarPaises() {
    var seletor = q('#doa-pais'), nomes = null;
    try { nomes = new Intl.DisplayNames(['en'], { type: 'region' }); } catch (e) { nomes = null; }
    var lista = PAISES.map(function (c) {
      var nome = c;
      try { nome = (nomes && nomes.of(c)) || c; } catch (e) { nome = c; }
      return { codigo: c, nome: nome };
    }).sort(function (a, b) { return a.nome.localeCompare(b.nome, 'en'); });
    lista.forEach(function (p) {
      var o = document.createElement('option');
      o.value = p.codigo; o.textContent = p.nome;
      seletor.appendChild(o);
    });
    // Palpite pelo idioma do navegador (en-US → US); a pessoa confere e troca se não for.
    var regiao = ((navigator.language || '').split('-')[1] || '').toUpperCase();
    if (PAISES.indexOf(regiao) !== -1 && regiao !== 'BR') seletor.value = regiao;
  }

  function semFormulario() {
    mostrar('fora');
  }

  function iniciar() {
    var form = q('#doa-form');
    if (!form) return;
    origemAtual = origem();
    montarPaises();

    qa('.doe-valor').forEach(function (b) {
      b.addEventListener('click', function () {
        q('#doa-outro').value = '';
        erro('');
        marcarValor(parseInt(b.getAttribute('data-valor'), 10));
      });
    });
    var outro = q('#doa-outro');
    aplicarMascara(outro, mascaras.valor);
    outro.addEventListener('input', function () { marcarValor(parseInt(digitos(outro.value) || '0', 10) * 100); });
    q('#doa-cobre').addEventListener('change', atualizarTotais);
    q('#doa-anonimo').addEventListener('change', function () { q('#doa-anonimo-caixa').classList.toggle('marcado', this.checked); });
    q('#doa-aceite').addEventListener('change', function () { q('#doa-aceite-caixa').classList.toggle('marcado', this.checked); });

    q('#doa-continuar').addEventListener('click', function () {
      erro('');
      if (valorDentroDosLimites() !== true) return;
      mostrar('dados');
      q('#doa-nome').focus();
      if (!iniciado) {
        iniciado = true;
        rastrear('InitiateCheckout', { content_name: 'Doação internacional', content_category: 'doacao', value: valorAtual / 100, currency: 'BRL' },
          'begin_checkout', { currency: 'BRL', value: valorAtual / 100, items: [{ item_id: 'doacao-internacional', item_name: 'Doação internacional', price: valorAtual / 100, quantity: 1 }] });
      }
    });
    qa('.doe-voltar').forEach(function (b) { b.addEventListener('click', function () { erro(''); mostrar('valor'); }); });
    form.addEventListener('submit', enviar);

    // Quem corrige um campo marcado deixa de ver o aviso antigo.
    qa('.doe-campo input, .doe-campo select', form).forEach(function (el) {
      el.addEventListener('input', function () {
        var campo = el.closest('.doe-campo');
        if (campo && campo.classList.contains('erro')) erro('');
      });
    });
    aplicarMascara(q('#doa-telefone'), mascaras.telefone);
    aplicarMascara(q('#doa-cartao_numero'), mascaras.cartao);
    aplicarMascara(q('#doa-cartao_validade'), mascaras.validade);
    aplicarMascara(q('#doa-cartao_cvv'), mascaras.cvv);

    var padrao = parseInt(form.getAttribute('data-valor-padrao'), 10) || 25000;
    marcarValor(padrao);

    api('info.php?cambio=1').then(function (d) {
      // Sem resposta, sem cartão ou com a doação internacional desligada no servidor: a página não promete
      // o que não consegue cobrar e mostra como doar escrevendo para a filial.
      if (!d.ok || !d.internacional) { if (!TOKEN.test(param('t'))) semFormulario(); return; }
      info = d;
      if (d.teste) q('#doa-teste').hidden = false;
      q('#doa-continuar').disabled = false;
      var pedido = parseInt(digitos(param('amount')) || '0', 10) * 100;
      marcarValor(pedido >= d.minimo_centavos && pedido <= d.maximo_centavos ? pedido : valorAtual);
    });

    // Volta pelo link do e-mail ou recarga da página: ?t=<token> mostra o andamento ou o agradecimento.
    var t = param('t');
    if (TOKEN.test(t)) {
      andamento();
      api('status.php?t=' + encodeURIComponent(t)).then(function (d) {
        if (d.ok && d.status === 'pago') { telaObrigado(d); return; }
        if (d.ok && d.status === 'pendente') { acompanhar(t); return; }
        naoConcluiu(d.ok ? mensagemDoServidor({ http: 402 }) : 'We could not find that donation. If you have already paid, check your e-mail: the receipt was sent there.');
        if (!info) api('info.php').then(function (i) { if (!i.ok || !i.internacional) semFormulario(); });
      });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
})();
