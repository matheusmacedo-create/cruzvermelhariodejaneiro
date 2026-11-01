/* Pagar a matrícula (01/11/2026): a tela pagar-matricula/, aberta pelo botão das mensagens da matrícula pendente
   (WhatsApp ou e-mail) de quem pagou só a taxa de inscrição. Conversa só com api/matricula.php (mesma origem): o token
   do link vem num cookie só da api/ (o api/ir.php grava), nunca na URL desta página. O navegador nunca manda preço nem
   dado do pagador (nome, CPF, e-mail e telefone saem da inscrição, no servidor); manda a forma de pagamento e, no
   cartão, os dados do cartão, que vão direto à operadora e não são guardados.
   Arquivo próprio, e não o checkout.js, de propósito: o checkout, por onde entra toda inscrição, não muda. Os estilos
   são os do checkout (checkout.css e o CSS do pagar tudo da página). */
(function () {
  'use strict';

  var API = '/matricula-cursos-presenciais/api/matricula.php';
  var INTERVALO = 5000;
  var CONTATO = 'contato@cruzvermelhariodejaneiro.org';
  var ICONE_CHECK = '<svg class="ico ico-check" aria-hidden="true" focusable="false"><use href="#i-check"/></svg>';

  function q(s, r) { return (r || document).querySelector(s); }
  function brl(c) { return (c / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
  function digitos(v) { return String(v || '').replace(/\D+/g, ''); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function urlSegura(u) { return /^https?:\/\//i.test(String(u || '')) ? String(u) : ''; }
  function api(caminho, opcoes) {
    return fetch(caminho, Object.assign({ credentials: 'same-origin', headers: { Accept: 'application/json' } }, opcoes || {}))
      .then(function (r) {
        return r.json().catch(function () { return null; }).then(function (d) {
          d = d || { ok: false, erro: 'Resposta inesperada do servidor. Tente de novo.' };
          d.http = r.status;
          return d;
        });
      })
      .catch(function () { return { ok: false, erro: 'Sem conexão. Verifique a internet e tente novamente.', http: 0 }; });
  }
  function luhn(n) {
    var soma = 0, dobrar = false;
    for (var i = n.length - 1; i >= 0; i--) {
      var x = +n[i];
      if (dobrar) { x *= 2; if (x > 9) x -= 9; }
      soma += x; dobrar = !dobrar;
    }
    return n.length >= 13 && soma % 10 === 0;
  }
  var mascaras = {
    numero: function (v) { return digitos(v).slice(0, 19).replace(/(\d{4})(?=\d)/g, '$1 '); },
    validade: function (v) { v = digitos(v).slice(0, 4); return v.length > 2 ? v.slice(0, 2) + '/' + v.slice(2) : v; },
    cvv: function (v) { return digitos(v).slice(0, 4); }
  };
  // As UTMs do link (utm_source whatsapp|email, utm_campaign mat1|mat2): é por elas que o painel conta quem pagou pela mensagem.
  function origem() {
    var o = {};
    new URLSearchParams(location.search).forEach(function (v, k) { if (k === 'utm_source' || k === 'utm_campaign') o[k] = v.slice(0, 120); });
    return o;
  }

  var card = q('#mt-card');
  if (!card) return;

  function resumo(d) {
    var turma = d.turma ? esc(d.turma.rotulo) + (d.turma.hora ? ', às ' + esc(d.turma.hora) : '') : '';
    return '<dl class="mt-resumo">'
      + '<div><dt>Curso</dt><dd>' + esc(d.curso.nome) + '</dd></div>'
      + (turma ? '<div><dt>Turma</dt><dd>' + turma + '</dd></div>' : '')
      + '<div><dt>Matrícula</dt><dd><strong>' + brl(d.valor_centavos) + '</strong> à vista</dd></div>'
      + '</dl>';
  }

  function aviso(titulo, texto, extra) {
    card.innerHTML = '<h2>' + esc(titulo) + '</h2><p>' + texto + '</p>' + (extra || '')
      + '<p class="ck-nota">Dúvidas? Fale com a secretaria pelo chat ou escreva para <a href="mailto:' + CONTATO + '">' + CONTATO + '</a>.</p>';
  }

  function pago(d) {
    var c = d.pagamento && d.pagamento.cartao ? ' no cartão ' + esc(d.pagamento.cartao.bandeira || '') + ' final ' + esc(d.pagamento.cartao.ultimos4 || '') : '';
    card.innerHTML = '<div class="mt-ok"><span class="mt-selo">' + ICONE_CHECK + '</span><h2>Matrícula paga, ' + esc(d.nome) + '!</h2>'
      + '<p>Recebemos o pagamento da matrícula' + c + '. Com a taxa de inscrição e a matrícula pagas, a sua entrada na turma está garantida.</p></div>'
      + resumo(d)
      + '<p>O comprovante foi enviado para <b>' + esc(d.email) + '</b>, e a secretaria da Escola já foi avisada. No dia da aula, chegue com 15 minutos de antecedência à Praça da Cruz Vermelha, 10, Centro.</p>';
  }

  // Acompanha o pagamento até sair de pendente.
  function acompanhar(token) {
    function passo() {
      api(API + '?c=' + encodeURIComponent(token)).then(function (d) {
        var st = d.pagamento && d.pagamento.status;
        if (st === 'pago') { var itens = card.querySelectorAll('.ck-etapa'); if (itens[1]) { itens[1].className = 'ck-etapa feito'; } setTimeout(function () { pago(d); }, itens.length ? 900 : 0); return; }
        if (st === 'expirado') { aviso('O código PIX venceu.', 'Ele valia por 24 horas. Gere outro, sem custo.', '<p><button class="btn btn-red" type="button" id="mt-de-novo">Gerar outro código</button></p>'); ligarDeNovo(); return; }
        if (st === 'recusado' || st === 'estornado') { aviso('O pagamento não passou.', 'Nada foi cobrado. Tente de novo, por PIX ou com outro cartão.', '<p><button class="btn btn-red" type="button" id="mt-de-novo">Tentar de novo</button></p>'); ligarDeNovo(); return; }
        setTimeout(passo, document.hidden ? INTERVALO * 3 : INTERVALO);
      });
    }
    setTimeout(passo, INTERVALO);
  }

  function ligarDeNovo() {
    var b = q('#mt-de-novo');
    if (b) b.addEventListener('click', carregar);
  }

  function painelPix(d) {
    var pix = d.pagamento.pix || {};
    var copia = pix.copia_cola || '';
    var link = urlSegura(pix.url);
    card.innerHTML = '<div class="ck-pix"><h2>Escaneie e pague</h2>'
      + '<p class="lead" style="font-size:1rem;max-width:none">Abra o app do banco e aponte a câmera, ou use o código Copia e Cola.</p>'
      + '<p class="ck-pix-valor"><span>Matrícula</span><strong>' + brl(d.valor_centavos) + '</strong></p>'
      + '<div class="ck-qr" id="mt-qr"></div>'
      + '<label class="ck-campo"><span class="sr-only">Código PIX copia e cola</span><textarea class="ck-copia" id="mt-copia" readonly>' + esc(copia) + '</textarea></label>'
      + '<div class="cta-row" style="justify-content:center;margin-top:12px"><button class="btn btn-red" type="button" id="mt-copiar">Copiar código PIX</button>'
      + (link ? '<a class="btn btn-outline" href="' + esc(link) + '" target="_blank" rel="noopener">Abrir página do PIX</a>' : '') + '</div>'
      + '<div class="ck-status" role="status"><span class="pulso" aria-hidden="true"></span> Verificando automaticamente…</div>'
      + '<ol class="ck-etapas" aria-label="Etapas do pagamento">'
      + '<li class="ck-etapa feito"><span class="ck-etapa-n">' + ICONE_CHECK + '</span><p><small>Passo 1</small>QR Code gerado</p><span class="ck-etapa-badge">Pronto</span></li>'
      + '<li class="ck-etapa atual"><span class="ck-etapa-n">2</span><p><small>Passo 2</small>Aguardando pagamento</p><span class="ck-etapa-badge">Aguardando</span></li>'
      + '<li class="ck-etapa"><span class="ck-etapa-n">3</span><p><small>Passo 3</small>Matrícula paga</p><span class="ck-etapa-badge">Em breve</span></li></ol>'
      + '<p class="ck-nota" style="margin-top:14px">O código vale por 24 horas. Se fechar esta página, volte pelo botão da mensagem: o mesmo código aparece de novo.</p></div>';
    var qr = q('#mt-qr');
    if (window.QRCode && copia) {
      try { new window.QRCode(qr, { text: copia, width: 216, height: 216, correctLevel: window.QRCode.CorrectLevel.M }); } catch (e) { qr.innerHTML = ''; }
    }
    var imagem = urlSegura(pix.imagem);
    if (!qr.firstChild && imagem) qr.innerHTML = '<img src="' + esc(imagem) + '" alt="QR code do PIX" width="216" height="216">';
    q('#mt-copiar').addEventListener('click', function () {
      var botao = this;
      q('#mt-copia').select();
      var copiado = navigator.clipboard ? navigator.clipboard.writeText(copia).then(function () { return true; }, function () { return false; }) : Promise.resolve(false);
      copiado.then(function (ok) {
        if (!ok) { try { ok = document.execCommand('copy'); } catch (e) { ok = false; } }
        botao.textContent = ok ? 'Código copiado' : 'Selecione e copie o código';
        setTimeout(function () { botao.textContent = 'Copiar código PIX'; }, 2500);
      });
    });
    acompanhar(d.pagamento.token);
  }

  function formulario(d) {
    card.innerHTML = '<p>Oi, ' + esc(d.nome) + '. A sua taxa de inscrição já está paga e a vaga, garantida. A entrada na aula é liberada com a matrícula paga.</p>'
      + resumo(d)
      + '<form id="mt-form" novalidate>'
      + '<div class="ck-armadilha" aria-hidden="true"><label>Não preencha este campo<input id="mt-site" name="site" tabindex="-1" autocomplete="off"></label></div>'
      + '<p class="ck-rot-secao" id="mt-metodos-titulo">Forma de pagamento</p>'
      + '<div class="ck-metodos" role="radiogroup" aria-labelledby="mt-metodos-titulo">'
      + '<label class="ck-metodo ativo"><input type="radio" name="metodo" value="pix" checked><span class="ck-metodo-ico"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-pix"/></svg></span><b>PIX</b></label>'
      + '<label class="ck-metodo"><input type="radio" name="metodo" value="cartao"><span class="ck-metodo-ico"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-credit-card"/></svg></span><b>Crédito</b></label>'
      + '</div>'
      + '<div class="ck-cartao-box" id="mt-cartao" hidden>'
      + '<label class="ck-campo"><span>Número do cartão</span><input id="mt-numero" inputmode="numeric" autocomplete="cc-number" placeholder="0000 0000 0000 0000" disabled></label>'
      + '<label class="ck-campo"><span>Nome impresso no cartão</span><input id="mt-nome" autocomplete="cc-name" disabled></label>'
      + '<div class="ck-2col"><label class="ck-campo"><span>Validade</span><input id="mt-validade" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/AA" disabled></label>'
      + '<label class="ck-campo"><span>CVV</span><input id="mt-cvv" inputmode="numeric" autocomplete="cc-csc" placeholder="123" disabled></label></div>'
      + '<p class="ck-nota">Os dados vão direto para a operadora. Não guardamos o cartão. À vista, sem parcelas.</p></div>'
      + '<div class="ck-erro" id="mt-erro" role="alert" aria-live="assertive"></div>'
      + '<div class="ck-acao"><div class="ck-acao-valor"><em>Matrícula</em><strong>' + brl(d.valor_centavos) + '</strong></div>'
      + '<button class="btn btn-red ck-btn" type="submit" id="mt-pagar"><span id="mt-pagar-texto">Gerar QR Code</span></button></div>'
      + '<p class="ck-nota">Prefere pagar pessoalmente? A matrícula também pode ser paga na secretaria da Escola, antes da aula. Já pagou? Não pague de novo.</p>'
      + '</form>';
    var form = q('#mt-form');
    [['#mt-numero', mascaras.numero], ['#mt-validade', mascaras.validade], ['#mt-cvv', mascaras.cvv]].forEach(function (c) {
      var el = q(c[0]);
      el.addEventListener('input', function () { el.value = c[1](el.value); });
    });
    function metodo() { return (form.querySelector('input[name=metodo]:checked') || {}).value || 'pix'; }
    function atualizar() {
      var cartao = metodo() === 'cartao';
      q('#mt-cartao').hidden = !cartao;
      form.querySelectorAll('#mt-cartao input').forEach(function (i) { i.disabled = !cartao; });
      form.querySelectorAll('.ck-metodo').forEach(function (m) { m.classList.toggle('ativo', m.querySelector('input').checked); });
      q('#mt-pagar-texto').textContent = cartao ? 'Pagar ' + brl(d.valor_centavos) : 'Gerar QR Code';
    }
    form.querySelectorAll('input[name=metodo]').forEach(function (r) { r.addEventListener('change', atualizar); });
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var erro = q('#mt-erro');
      erro.textContent = '';
      var corpo = { metodo: metodo(), origem: origem(), site: q('#mt-site').value };
      if (corpo.metodo === 'cartao') {
        var numero = digitos(q('#mt-numero').value);
        if (!luhn(numero)) { erro.textContent = 'Número do cartão inválido.'; q('#mt-numero').focus(); return; }
        if (q('#mt-nome').value.trim().length < 3) { erro.textContent = 'Informe o nome como está no cartão.'; q('#mt-nome').focus(); return; }
        if (digitos(q('#mt-validade').value).length !== 4) { erro.textContent = 'Validade inválida. Use MM/AA.'; q('#mt-validade').focus(); return; }
        if (digitos(q('#mt-cvv').value).length < 3) { erro.textContent = 'Código de segurança inválido.'; q('#mt-cvv').focus(); return; }
        corpo.cartao = { numero: numero, nome: q('#mt-nome').value.trim(), validade: q('#mt-validade').value, cvv: q('#mt-cvv').value };
      }
      var botao = q('#mt-pagar');
      botao.disabled = true;
      q('#mt-pagar-texto').textContent = corpo.metodo === 'cartao' ? 'Processando…' : 'Gerando o QR Code…';
      api(API, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(corpo) }).then(function (r) {
        corpo = null;
        var st = r.pagamento && r.pagamento.status;
        if (r.ok && st === 'pago') { pago(r); return; }
        if (r.ok && r.pagamento && r.pagamento.metodo === 'pix' && st === 'pendente') { painelPix(r); return; }
        if (r.ok && st === 'pendente') { aviso('Pagamento em análise.', 'O banco está conferindo o pagamento. Esta página avança sozinha quando ele confirmar.'); acompanhar(r.pagamento.token); return; }
        botao.disabled = false;
        atualizar();
        erro.textContent = r.erro || 'Não foi possível gerar o pagamento. Tente de novo.';
      });
    });
    atualizar();
  }

  function carregar() {
    card.innerHTML = '<p>Carregando…</p>';
    api(API).then(function (d) {
      if (!d.ok && d.http === 404) {
        aviso('Este link não vale mais.', 'Ele não veio inteiro ou venceu. Abra de novo o botão da mensagem mais recente sobre a matrícula.');
        return;
      }
      if (!d.ok) { aviso('Não foi possível abrir o pagamento agora.', esc(d.erro || 'Tente de novo em alguns minutos.')); return; }
      if (d.motivo === 'paga' || d.motivo === 'completa') {
        if (d.pagamento && d.pagamento.status === 'pago') { pago(d); return; }
        aviso('A matrícula já está paga.', 'A matrícula de ' + esc(d.curso.nome) + ' já foi paga. Não pague de novo.', resumo(d));
        return;
      }
      if (d.motivo === 'turma_passou') { aviso('A turma já começou.', 'A turma de ' + esc(d.curso.nome) + ' já começou. Fale com a secretaria para ver a próxima.'); return; }
      if (!d.pode_pagar) { aviso('A matrícula não pode ser paga por aqui.', 'Fale com a secretaria da Escola: ela ajuda a concluir a matrícula.'); return; }
      if (d.pagamento && d.pagamento.metodo === 'pix' && d.pagamento.status === 'pendente') { painelPix(d); return; }
      formulario(d);
    });
  }

  // Estilos só desta tela (o resto vem do checkout).
  var estilo = document.createElement('style');
  estilo.textContent = '.mt-resumo{display:grid;gap:8px;margin:16px 0 20px;padding:14px 16px;border:1px solid var(--line);border-radius:14px;background:var(--soft)}'
    + '.mt-resumo div{display:flex;justify-content:space-between;gap:12px}.mt-resumo dt{color:var(--muted);font-weight:600}.mt-resumo dd{margin:0;text-align:right;color:var(--black)}'
    + '.mt-ok{text-align:center}.mt-selo{display:inline-flex;width:56px;height:56px;border-radius:50%;background:#e8f6ee;color:#0f7b3e;align-items:center;justify-content:center;font-size:1.6rem}'
    + '.mt-selo .ico{width:28px;height:28px}';
  document.head.appendChild(estilo);
  carregar();
})();
