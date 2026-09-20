/* Página de doação (/doe/): escolha do valor, dados de quem doa, PIX ou cartão e acompanhamento
   do pagamento sem sair da página. Conversa com /doe/api/ (PHP, Unicopag).

   O número do cartão vai direto para a API e nunca é guardado aqui. O estado da doação vive no
   token devolvido pelo servidor: com ?t=<token> na URL a página volta para onde parou. */
(function () {
  'use strict';
  var API = '/doe/api/';
  var URL_OBRIGADO = '/doe/obrigado/';
  var TOKEN = /^[a-f0-9]{40}$/;
  var INTERVALO_STATUS = 5000;
  var info = null, origemAtual = null, valorAtual = 0, iniciado = false, pararStatus = null;

  function q(s, raiz) { return (raiz || document).querySelector(s); }
  function qa(s, raiz) { return Array.prototype.slice.call((raiz || document).querySelectorAll(s)); }
  function digitos(v) { return String(v || '').replace(/\D+/g, ''); }
  function brl(centavos) { return (centavos / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
  function param(chave) { return new URLSearchParams(location.search).get(chave) || ''; }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  /* Só http(s) entra em href: a API é nossa, mas o link do PIX vem do provedor. */
  function urlSegura(u) { return /^https?:\/\//i.test(String(u || '')) ? String(u) : ''; }

  /* UTMs e identificadores de anúncio: os da URL valem mais; os guardados sobrevivem à navegação. */
  function origem() {
    var p = new URLSearchParams(location.search), dados = {}, salvo = {};
    try { salvo = JSON.parse(sessionStorage.getItem('mcp_origem') || '{}'); } catch (e) { salvo = {}; }
    ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid'].forEach(function (k) {
      var v = p.get(k) || salvo[k] || '';
      if (v) dados[k] = String(v).slice(0, 160);
    });
    if (!dados.utm_source && document.referrer && document.referrer.indexOf(location.host) < 0) {
      try { dados.utm_source = new URL(document.referrer).hostname.slice(0, 120); dados.utm_medium = 'referral'; } catch (e) { /* referrer estranho */ }
    }
    try { sessionStorage.setItem('mcp_origem', JSON.stringify(dados)); } catch (e) { /* segue sem guardar */ }
    return dados;
  }

  /* Um evento para o Meta e outro para o GA4, com os mesmos números. */
  function rastrear(eventoMeta, dadosMeta, eventoGa, dadosGa, opcoes) {
    if (window.fbq && eventoMeta) try { fbq('track', eventoMeta, dadosMeta || {}, opcoes || {}); } catch (e) { /* bloqueador */ }
    if (window.gtag && eventoGa) try { gtag('event', eventoGa, dadosGa || {}); } catch (e) { /* bloqueador */ }
  }

  function api(caminho, opcoes) {
    var cabecalhos = { Accept: 'application/json' };
    if (opcoes && opcoes.method === 'POST') cabecalhos['Content-Type'] = 'application/json';
    return fetch(API + caminho, Object.assign({ credentials: 'same-origin', headers: cabecalhos }, opcoes || {}))
      .then(function (r) { return r.json().then(function (d) { d.http = r.status; return d; }, function () { return { ok: false, http: r.status, erro: 'Resposta inválida do servidor.' }; }); })
      .catch(function () { return { ok: false, http: 0, erro: 'Sem conexão com o servidor. Tente novamente.' }; });
  }

  // ------------------------------------------------------------------ validação (as mesmas regras do servidor)
  function cpfValido(cpf) {
    cpf = digitos(cpf);
    if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
    for (var t = 9; t < 11; t++) {
      var soma = 0;
      for (var i = 0; i < t; i++) soma += parseInt(cpf[i], 10) * (t + 1 - i);
      var d = (soma * 10) % 11 % 10;
      if (d !== parseInt(cpf[t], 10)) return false;
    }
    return true;
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

  var mascaras = {
    cpf: function (v) { v = digitos(v).slice(0, 11); return v.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2'); },
    telefone: function (v) { v = digitos(v).slice(0, 11); return v.length > 10 ? v.replace(/(\d{2})(\d{5})(\d{0,4})/, '($1) $2-$3') : v.replace(/(\d{2})(\d{4})(\d{0,4})/, '($1) $2-$3').replace(/[-\s]*$/, ''); },
    cartao: function (v) { return digitos(v).slice(0, 19).replace(/(\d{4})(?=\d)/g, '$1 '); },
    validade: function (v) { v = digitos(v).slice(0, 4); return v.length > 2 ? v.slice(0, 2) + '/' + v.slice(2) : v; },
    cvv: function (v) { return digitos(v).slice(0, 4); },
    /* Valor em reais: digita-se só números e os centavos entram sozinhos. */
    valor: function (v) { var c = digitos(v).slice(0, 9); return c ? (parseInt(c, 10) / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : ''; }
  };
  function aplicarMascara(el, fn) { if (el) el.addEventListener('input', function () { el.value = fn(el.value); }); }

  // ------------------------------------------------------------------ telas
  function mostrar(tela) {
    ['valor', 'dados', 'pix', 'obrigado'].forEach(function (t) {
      var el = q('#doe-' + t);
      if (el) el.hidden = t !== tela;
    });
    var passo = tela === 'valor' ? 1 : (tela === 'dados' ? 2 : 3);
    qa('.doe-passo').forEach(function (el, i) {
      el.classList.toggle('ativo', i + 1 === passo);
      el.classList.toggle('feito', i + 1 < passo);
    });
  }

  function erro(mensagem, campo) {
    qa('.doe-campo.erro').forEach(function (c) { c.classList.remove('erro'); });
    var alvo = q('#doe-erro');
    alvo.textContent = mensagem || '';
    if (!mensagem) return false;
    var el = campo && q('#doe-' + campo);
    if (el && el.closest('.doe-campo')) { el.closest('.doe-campo').classList.add('erro'); el.focus(); }
    else alvo.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return false;
  }

  function metodo() { var b = q('.doe-metodo.ativo'); return b ? b.getAttribute('data-metodo') : 'pix'; }
  function taxaAtual() {
    if (!info || !info.taxa) return 0;
    var t = info.taxa[metodo()] || { pct: 0, fixa: 0 };
    return Math.max(0, Math.round(valorAtual * (t.pct || 0) / 100) + (t.fixa || 0));
  }
  function cobreTaxa() { var c = q('#doe-cobre'); return !!(c && c.checked); }
  function total() { return valorAtual + (cobreTaxa() ? taxaAtual() : 0); }

  function atualizarTotais() {
    var t = total();
    var vTaxa = q('#doe-taxa-valor');
    if (vTaxa) vTaxa.textContent = brl(taxaAtual());
    var caixa = q('#doe-cobre-caixa');
    if (caixa) caixa.classList.toggle('marcado', cobreTaxa());
    qa('.doe-total').forEach(function (el) { el.textContent = brl(t); });
    var botao = q('#doe-enviar');
    if (botao) botao.textContent = 'Doar ' + brl(t);
  }

  function marcarValor(centavos) {
    valorAtual = centavos;
    qa('.doe-valor').forEach(function (b) { b.classList.toggle('ativo', parseInt(b.getAttribute('data-valor'), 10) === centavos); });
    atualizarTotais();
  }

  // ------------------------------------------------------------------ PIX e agradecimento
  function painelPix(d) {
    var copia = (d.pix && d.pix.copia_cola) || '';
    var link = urlSegura(d.pix && d.pix.url);
    q('#doe-pix').innerHTML = '<div class="doe-pix">'
      + '<h2>Falta só pagar ' + brl(d.total_centavos) + '</h2>'
      + '<p>Abra o app do seu banco, leia o QR code ou use o <b>PIX copia e cola</b>. A confirmação aparece aqui sozinha.</p>'
      + '<div class="doe-qr" id="doe-qr"></div>'
      + '<label><span class="sr-only">Código PIX copia e cola</span><textarea class="doe-copia" id="doe-copia" readonly>' + esc(copia) + '</textarea></label>'
      + '<button class="btn btn-red doe-acao" type="button" id="doe-copiar" style="margin-top:12px">Copiar código PIX</button>'
      + (link ? '<a class="btn btn-outline doe-acao" href="' + esc(link) + '" target="_blank" rel="noopener" style="margin-top:8px">Abrir a página do PIX</a>' : '')
      + '<div class="doe-status" role="status"><span class="doe-pulso" aria-hidden="true"></span> Aguardando o pagamento…</div>'
      + '<p class="doe-aviso">Enviamos este código para <b>' + esc(d.email) + '</b>. Ele vale por 24 horas.</p>'
      + '</div>';
    var qr = q('#doe-qr');
    if (window.QRCode && copia) {
      try { new window.QRCode(qr, { text: copia, width: 208, height: 208, correctLevel: window.QRCode.CorrectLevel.M }); } catch (e) { qr.innerHTML = ''; }
    }
    var imagem = urlSegura(d.pix && d.pix.imagem);
    if (!qr.firstChild && imagem) qr.innerHTML = '<img src="' + esc(imagem) + '" alt="QR code do PIX" width="208" height="208">';
    q('#doe-copiar').addEventListener('click', function () {
      var botao = this, area = q('#doe-copia');
      area.select();
      var copiado = Promise.resolve(false);
      if (navigator.clipboard) copiado = navigator.clipboard.writeText(copia).then(function () { return true; }, function () { return false; });
      copiado.then(function (ok) {
        if (!ok) { try { ok = document.execCommand('copy'); } catch (e) { ok = false; } }
        botao.textContent = ok ? 'Código copiado' : 'Selecione e copie o código';
        setTimeout(function () { botao.textContent = 'Copiar código PIX'; }, 2500);
      });
    });
    mostrar('pix');
  }

  function telaObrigado(d) {
    var mensal = d.frequencia === 'mensal';
    q('#doe-obrigado').innerHTML = '<div class="doe-obrigado">'
      + '<div class="doe-selo" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></div>'
      + '<h2>Obrigado, ' + esc(d.nome) + '!</h2>'
      + '<p>Sua doação de <b>' + brl(d.total_centavos) + '</b>' + (mensal ? ', que se repete todo mês,' : '') + ' foi confirmada. Enviamos o comprovante para <b>' + esc(d.email) + '</b>.</p>'
      + '<div class="doe-recibo">'
      + '<div><span>Protocolo</span><b>' + esc(d.protocolo) + '</b></div>'
      + '<div><span>Forma de pagamento</span><b>' + (d.metodo === 'pix' ? 'PIX' : 'Cartão' + (d.cartao && d.cartao.ultimos4 ? ' ···· ' + esc(d.cartao.ultimos4) : '')) + '</b></div>'
      + '<div><span>Valor</span><b>' + brl(d.total_centavos) + '</b></div>'
      + '</div>'
      + '<p>Sua doação mantém a formação de voluntários, a capacitação em primeiros socorros e as ações da Cruz Vermelha Brasileira Rio de Janeiro no estado.</p>'
      + '<a class="btn btn-red doe-acao" href="/noticias/">Ver as ações da filial</a>'
      + '<a class="btn btn-outline doe-acao" href="https://www.instagram.com/cruzvermelhabrasileirarj/" target="_blank" rel="noopener" style="margin-top:8px">Seguir no Instagram</a>'
      + '</div>';
    mostrar('obrigado');
    rastrear('Purchase', { content_name: 'Doação', content_category: 'doacao', value: d.total_centavos / 100, currency: 'BRL' },
      'purchase', { transaction_id: d.token, value: d.total_centavos / 100, currency: 'BRL', items: [{ item_id: 'doacao', item_name: 'Doação', price: d.total_centavos / 100, quantity: 1 }] },
      { eventID: d.token + '-doacao' });
  }

  function esperandoCartao() {
    q('#doe-pix').innerHTML = '<div class="doe-pix"><h2>Confirmando o pagamento…</h2>'
      + '<p>Seu cartão foi enviado ao banco. Assim que a confirmação chegar, o agradecimento aparece aqui.</p>'
      + '<div class="doe-status" role="status"><span class="doe-pulso" aria-hidden="true"></span> Aguardando o banco…</div></div>';
    mostrar('pix');
  }

  function acompanhar(token) {
    var parado = false;
    function passo() {
      if (parado) return;
      api('status.php?t=' + encodeURIComponent(token)).then(function (d) {
        if (parado) return;
        if (d.status === 'pago') { parado = true; telaObrigado(d); return; }
        if (d.status === 'expirado' || d.status === 'recusado' || d.status === 'estornado') {
          parado = true;
          mostrar('valor');
          erro(d.status === 'expirado' ? 'O código PIX venceu. Gere outro para concluir sua doação.' : 'O pagamento não foi concluído. Tente de novo.');
          return;
        }
        setTimeout(passo, document.hidden ? INTERVALO_STATUS * 3 : INTERVALO_STATUS);
      });
    }
    setTimeout(passo, INTERVALO_STATUS);
    return function () { parado = true; };
  }

  // ------------------------------------------------------------------ envio
  function lerFormulario() {
    var dados = {
      frequencia: (q('.doe-freq.ativo') || {}).getAttribute ? q('.doe-freq.ativo').getAttribute('data-freq') : 'unica',
      valor_centavos: valorAtual,
      nome: q('#doe-nome').value.trim(),
      email: q('#doe-email').value.trim(),
      cpf: digitos(q('#doe-cpf').value),
      telefone: digitos(q('#doe-telefone').value),
      metodo: metodo(),
      cobre_taxa: cobreTaxa(),
      aceite: q('#doe-aceite').checked,
      site: q('#doe-site').value,
      origem: origemAtual
    };
    if (dados.metodo === 'cartao') {
      dados.cartao = {
        numero: digitos(q('#doe-cartao_numero').value), nome: q('#doe-cartao_nome').value.trim(),
        validade: q('#doe-cartao_validade').value, cvv: q('#doe-cartao_cvv').value
      };
    }
    return dados;
  }

  function validar(d) {
    if (!d.valor_centavos || d.valor_centavos < (info ? info.minimo_centavos : 500)) return erro('Escolha um valor a partir de ' + brl(info ? info.minimo_centavos : 500) + '.', 'outro');
    if (info && d.valor_centavos > info.maximo_centavos) return erro('Para doar mais de ' + brl(info.maximo_centavos) + ', fale com a gente pelo chat.', 'outro');
    if (d.nome.length < 5 || d.nome.indexOf(' ') < 0) return erro('Informe seu nome completo.', 'nome');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.email)) return erro('E-mail inválido.', 'email');
    if (!cpfValido(d.cpf)) return erro('CPF inválido. Confira os 11 números.', 'cpf');
    if (d.telefone.length < 10 || d.telefone.length > 11) return erro('Informe o telefone com DDD.', 'telefone');
    if (d.cartao) {
      if (d.cartao.numero.length < 13 || !luhnValido(d.cartao.numero)) return erro('Número do cartão inválido.', 'cartao_numero');
      if (d.cartao.nome.length < 3) return erro('Informe o nome como está no cartão.', 'cartao_nome');
      if (digitos(d.cartao.validade).length !== 4) return erro('Validade inválida. Use MM/AA.', 'cartao_validade');
      if (digitos(d.cartao.cvv).length < 3) return erro('Código de segurança inválido.', 'cartao_cvv');
    }
    if (!d.aceite) return erro('Confirme que leu o aviso de privacidade para concluir.', 'aceite');
    return true;
  }

  function enviar(e) {
    e.preventDefault();
    erro('');
    var dados = lerFormulario();
    if (validar(dados) !== true) return;
    var botao = q('#doe-enviar'), rotulo = botao.textContent;
    botao.disabled = true;
    botao.textContent = dados.metodo === 'pix' ? 'Gerando o PIX…' : 'Processando…';
    rastrear('AddPaymentInfo', { content_name: 'Doação', content_category: 'doacao', value: total() / 100, currency: 'BRL' },
      'add_payment_info', { currency: 'BRL', value: total() / 100, payment_type: dados.metodo });
    api('doacoes.php', { method: 'POST', body: JSON.stringify(dados) }).then(function (r) {
      dados.cartao = null;
      botao.disabled = false;
      botao.textContent = rotulo;
      if (!r.ok) { erro(r.erro || 'Não foi possível concluir. Tente novamente.', r.campo); return; }
      // A doação criada ganha endereço próprio: dá para voltar, recarregar e compartilhar.
      location.href = URL_OBRIGADO + '?t=' + encodeURIComponent(r.token);
    });
  }

  // ------------------------------------------------------------------ início
  function iniciar() {
    var form = q('#doe-form');
    if (!form) return;
    origemAtual = origem();

    qa('.doe-freq').forEach(function (b) {
      b.addEventListener('click', function () {
        qa('.doe-freq').forEach(function (o) { o.classList.remove('ativo'); });
        b.classList.add('ativo');
      });
    });
    qa('.doe-valor').forEach(function (b) {
      b.addEventListener('click', function () {
        q('#doe-outro').value = '';
        marcarValor(parseInt(b.getAttribute('data-valor'), 10));
      });
    });
    var outro = q('#doe-outro');
    aplicarMascara(outro, mascaras.valor);
    outro.addEventListener('input', function () {
      var centavos = parseInt(digitos(outro.value) || '0', 10);
      marcarValor(centavos);
    });
    qa('.doe-metodo').forEach(function (b) {
      b.addEventListener('click', function () {
        qa('.doe-metodo').forEach(function (o) { o.classList.remove('ativo'); });
        b.classList.add('ativo');
        var cartao = b.getAttribute('data-metodo') === 'cartao';
        q('#doe-cartao').hidden = !cartao;
        qa('#doe-cartao input').forEach(function (i) { i.disabled = !cartao; });
        atualizarTotais();
      });
    });
    q('#doe-cobre').addEventListener('change', atualizarTotais);

    q('#doe-continuar').addEventListener('click', function () {
      erro('');
      if (!valorAtual || valorAtual < (info ? info.minimo_centavos : 500)) { erro('Escolha um valor a partir de ' + brl(info ? info.minimo_centavos : 500) + '.', 'outro'); return; }
      mostrar('dados');
      q('#doe-nome').focus();
      if (!iniciado) {
        iniciado = true;
        rastrear('InitiateCheckout', { content_name: 'Doação', content_category: 'doacao', value: valorAtual / 100, currency: 'BRL' },
          'begin_checkout', { currency: 'BRL', value: valorAtual / 100, items: [{ item_id: 'doacao', item_name: 'Doação', price: valorAtual / 100, quantity: 1 }] });
      }
    });
    qa('.doe-voltar').forEach(function (b) { b.addEventListener('click', function () { erro(''); mostrar('valor'); }); });
    form.addEventListener('submit', enviar);

    aplicarMascara(q('#doe-cpf'), mascaras.cpf);
    aplicarMascara(q('#doe-telefone'), mascaras.telefone);
    aplicarMascara(q('#doe-cartao_numero'), mascaras.cartao);
    aplicarMascara(q('#doe-cartao_validade'), mascaras.validade);
    aplicarMascara(q('#doe-cartao_cvv'), mascaras.cvv);

    api('info.php').then(function (d) {
      if (!d.ok) { erro('A doação está indisponível no momento. Tente novamente em instantes.'); q('#doe-continuar').disabled = true; return; }
      info = d;
      if (d.teste) q('#doe-teste').hidden = false;
      if (d.mensal) q('#doe-frequencia').hidden = false;
      var pedido = parseInt(digitos(param('valor')) || '0', 10);
      marcarValor(pedido >= d.minimo_centavos && pedido <= d.maximo_centavos ? pedido : (valorAtual || d.valor_padrao));
    });
    marcarValor(valorAtual || 6000);
  }

  /* Página /doe/obrigado/?t=<token>: mostra o PIX a pagar ou o agradecimento, e acompanha sozinha. */
  function iniciarObrigado() {
    var t = param('t');
    var falha = function (texto) {
      q('#doe-pix').innerHTML = '<div class="doe-pix"><h2>Não encontramos essa doação</h2><p>' + esc(texto)
        + '</p><a class="btn btn-red doe-acao" href="/doe/">Fazer uma doação</a></div>';
      mostrar('pix');
    };
    if (!TOKEN.test(t)) { falha('O link está incompleto. Comece de novo na página de doação.'); return; }
    api('status.php?t=' + encodeURIComponent(t)).then(function (d) {
      if (!d.ok) { falha('O link pode ter expirado. Se você já pagou, confira seu e-mail: o comprovante foi enviado.'); return; }
      if (d.status === 'pago') { telaObrigado(d); return; }
      if (d.status === 'pendente' && d.metodo === 'pix' && d.pix && d.pix.copia_cola) { painelPix(d); pararStatus = acompanhar(d.token); return; }
      if (d.status === 'pendente') { esperandoCartao(); pararStatus = acompanhar(d.token); return; }
      falha(d.status === 'expirado' ? 'O código PIX venceu. Gere outro para concluir sua doação.' : 'O pagamento não foi concluído.');
    });
  }

  function comecar() {
    if (document.body && document.body.getAttribute('data-tela') === 'obrigado') iniciarObrigado();
    else iniciar();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', comecar); else comecar();
})();
