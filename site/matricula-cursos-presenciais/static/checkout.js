/* Checkout da matrícula em cursos presenciais: um arquivo para as três telas.
   A tela é escolhida por <body data-tela="checkout|pendente|parabens">. Conversa só com
   /matricula-cursos-presenciais/api/ (mesma origem). Nada aqui guarda dado de cartão. */
(function () {
  'use strict';

  var API = '/matricula-cursos-presenciais/api/';
  var URL_CURSOS = '/matricula-cursos-presenciais/';
  var URL_CHECKOUT = URL_CURSOS + 'checkout/';
  var TOKEN = /^[a-f0-9]{40}$/;
  var INTERVALO_STATUS = 5000;

  // ------------------------------------------------------------------ utilidades
  function q(seletor, raiz) { return (raiz || document).querySelector(seletor); }
  function brl(centavos) { return (centavos / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
  function digitos(v) { return String(v || '').replace(/\D+/g, ''); }
  function param(chave) { return new URLSearchParams(location.search).get(chave) || ''; }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  /* Só http(s) entra em href: a API é nossa, mas o link do PIX vem do provedor. */
  function urlSegura(u) { return /^https?:\/\//i.test(String(u || '')) ? String(u) : ''; }
  function voltarCursos(mensagem) {
    return '<p>' + esc(mensagem) + ' <a href="' + URL_CURSOS + '">Voltar para os cursos</a>.</p>';
  }

  /* utm_*, fbclid e gclid da URL, guardados na sessão para sobreviver à navegação entre páginas. */
  function origem() {
    var o = {};
    new URLSearchParams(location.search).forEach(function (v, k) { if (/^(utm_|fbclid$|gclid$)/.test(k)) o[k] = v.slice(0, 255); });
    try {
      var salvo = JSON.parse(sessionStorage.getItem('mcp_origem') || '{}');
      Object.keys(salvo).forEach(function (k) { if (!o[k]) o[k] = salvo[k]; });
      sessionStorage.setItem('mcp_origem', JSON.stringify(o));
    } catch (e) { /* armazenamento bloqueado: segue sem origem salva */ }
    return o;
  }

  /* Rastreio: Meta recebe o evento padrão com os parâmetros content_*; GA4 recebe o evento de
     comércio equivalente com os parâmetros que os relatórios de funil esperam (items, value,
     currency, transaction_id). opcoes.eventID vai para o Meta (deduplicação com a API de conversões). */
  function rastrear(eventoMeta, dadosMeta, eventoGa, dadosGa, opcoes) {
    try { if (window.fbq && eventoMeta) window.fbq('track', eventoMeta, dadosMeta || {}, opcoes && opcoes.eventID ? { eventID: opcoes.eventID } : undefined); } catch (e) { /* pixel ausente */ }
    try { if (window.gtag && eventoGa) window.gtag('event', eventoGa, dadosGa || {}); } catch (e) { /* GA4 ausente */ }
  }
  function itemGa(slug, nome, centavos) {
    return { currency: 'BRL', value: centavos / 100, items: [{ item_id: slug, item_name: nome, item_category: 'Cursos presenciais', price: centavos / 100, quantity: 1 }] };
  }

  /* Chamada à API. Sempre devolve um objeto com ok/erro e o código HTTP em `http`. */
  function api(caminho, opcoes) {
    var cabecalhos = { 'Accept': 'application/json' };
    if (opcoes && opcoes.body) cabecalhos['Content-Type'] = 'application/json';
    return fetch(API + caminho, Object.assign({ credentials: 'same-origin', headers: cabecalhos }, opcoes || {}))
      .then(function (r) {
        return r.json().catch(function () { return null; }).then(function (d) {
          if (!d || typeof d !== 'object') d = { ok: false, erro: 'Resposta inesperada do servidor. Tente novamente.' };
          d.http = r.status;
          return d;
        });
      })
      .catch(function () { return { ok: false, erro: 'Sem conexão. Verifique a internet e tente novamente.', http: 0 }; });
  }

  /* Consulta status.php até a inscrição sair de pendente. Devolve uma função que interrompe. */
  function acompanhar(token, aoPagar, aoFalhar) {
    var parado = false;
    function passo() {
      if (parado) return;
      api('status.php?t=' + encodeURIComponent(token)).then(function (d) {
        if (parado) return;
        if (d.status === 'pago') { parado = true; aoPagar(d); return; }
        if (d.status === 'expirado' || d.status === 'recusado' || d.status === 'estornado') { parado = true; aoFalhar(d); return; }
        setTimeout(passo, document.hidden ? INTERVALO_STATUS * 3 : INTERVALO_STATUS);
      });
    }
    setTimeout(passo, INTERVALO_STATUS);
    return function () { parado = true; };
  }

  /* Painel do PIX (checkout e pendente): QR code, copia e cola, botão de copiar. */
  function painelPix(d, alvo) {
    var copia = d.pix && d.pix.copia_cola ? d.pix.copia_cola : '';
    var linkPix = urlSegura(d.pix && d.pix.url);
    alvo.innerHTML = '<div class="ck-pix">'
      + '<p class="eyebrow">PIX gerado</p>'
      + '<h2>Pague ' + brl(d.total_centavos) + ' para garantir sua vaga</h2>'
      + '<p class="lead" style="font-size:1rem;max-width:none">Abra o app do seu banco e leia o QR code ou use o <b>PIX Copia e Cola</b>. A confirmação aparece aqui sozinha.</p>'
      + '<div class="ck-qr" id="ck-qr"></div>'
      + '<label class="ck-campo"><span class="sr-only">Código PIX copia e cola</span><textarea class="ck-copia" id="ck-copia" readonly>' + esc(copia) + '</textarea></label>'
      + '<div class="cta-row" style="justify-content:center;margin-top:12px"><button class="btn btn-red" type="button" id="ck-copiar">Copiar código PIX</button>'
      + (linkPix ? '<a class="btn btn-outline" href="' + esc(linkPix) + '" target="_blank" rel="noopener">Abrir página do PIX</a>' : '') + '</div>'
      + '<div class="ck-status" role="status"><span class="pulso" aria-hidden="true"></span> Aguardando pagamento…</div>'
      + '<p class="ck-nota" style="margin-top:14px">Enviamos este código para <b>' + esc(d.email) + '</b>. Ele vale por 24 horas. Se fechar esta página, volte pelo link do e-mail.</p>'
      + '</div>';
    var qr = q('#ck-qr', alvo);
    if (window.QRCode && copia) {
      try { new window.QRCode(qr, { text: copia, width: 216, height: 216, correctLevel: window.QRCode.CorrectLevel.M }); } catch (e) { qr.innerHTML = ''; }
    }
    var imagem = urlSegura(d.pix && d.pix.imagem);
    if (!qr.firstChild && imagem) qr.innerHTML = '<img src="' + esc(imagem) + '" alt="QR code do PIX" width="216" height="216">';
    q('#ck-copiar', alvo).addEventListener('click', function () {
      var botao = this, area = q('#ck-copia', alvo);
      area.select();
      var copiado = Promise.resolve(false);
      if (navigator.clipboard) copiado = navigator.clipboard.writeText(copia).then(function () { return true; }, function () { return false; });
      copiado.then(function (ok) {
        if (!ok) { try { ok = document.execCommand('copy'); } catch (e) { ok = false; } }
        botao.textContent = ok ? 'Código copiado' : 'Selecione e copie o código';
        setTimeout(function () { botao.textContent = 'Copiar código PIX'; }, 2500);
      });
    });
  }

  function mostrarAvisoTeste(d) { if (d && d.teste) q('#ck-teste').hidden = false; }

  // ------------------------------------------------------------------ tela: checkout
  function cpfValido(cpf) {
    cpf = digitos(cpf);
    if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
    for (var t = 9; t < 11; t++) {
      var soma = 0;
      for (var i = 0; i < t; i++) soma += parseInt(cpf[i], 10) * ((t + 1) - i);
      if (parseInt(cpf[t], 10) !== ((10 * soma) % 11) % 10) return false;
    }
    return true;
  }
  function luhnValido(numero) {
    var soma = 0, dobrar = false;
    for (var i = numero.length - 1; i >= 0; i--) {
      var n = parseInt(numero[i], 10);
      if (dobrar) n = n * 2 > 9 ? n * 2 - 9 : n * 2;
      soma += n; dobrar = !dobrar;
    }
    return numero.length > 0 && soma % 10 === 0;
  }
  var mascaras = {
    cpf: function (v) { v = digitos(v).slice(0, 11); return v.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2'); },
    telefone: function (v) {
      v = digitos(v).slice(0, 11);
      var corte = v.length > 10 ? 7 : 6;
      if (v.length > 6) return '(' + v.slice(0, 2) + ') ' + v.slice(2, corte) + '-' + v.slice(corte);
      return v.length > 2 ? '(' + v.slice(0, 2) + ') ' + v.slice(2) : v;
    },
    cartaoNumero: function (v) { return digitos(v).slice(0, 19).replace(/(\d{4})(?=\d)/g, '$1 '); },
    validade: function (v) { v = digitos(v).slice(0, 4); return v.length > 2 ? v.slice(0, 2) + '/' + v.slice(2) : v; },
    cvv: function (v) { return digitos(v).slice(0, 4); }
  };
  function aplicarMascara(el, fn) { el.addEventListener('input', function () { el.value = fn(el.value); }); }

  function telaCheckout() {
    var form = q('#ck-form'), sel = q('#ck-curso'), erroEl = q('#ck-erro'), btn = q('#ck-pagar');
    var info = null, cursos = {}, origemAtual = origem(), checkoutIniciado = false;
    try { cursos = JSON.parse(q('#ck-cursos').textContent) || {}; } catch (e) { cursos = {}; }
    function marcarInicio() {
      var c = cursos[sel.value];
      if (checkoutIniciado || !info || !c) return;
      checkoutIniciado = true;
      rastrear('InitiateCheckout', { content_name: c.nome, content_ids: [sel.value], content_type: 'product', num_items: 1, value: info.inscricao_centavos / 100, currency: 'BRL' },
        'begin_checkout', itemGa(sel.value, c.nome, info.inscricao_centavos));
    }
    var campos = {
      curso: '#ck-curso', nome: '#ck-nome', cpf: '#ck-cpf', email: '#ck-email', telefone: '#ck-telefone',
      cartao_numero: '#ck-cartao-numero', cartao_nome: '#ck-cartao-nome', cartao_validade: '#ck-cartao-validade', cartao_cvv: '#ck-cartao-cvv'
    };

    function metodo() { return (form.querySelector('input[name=metodo]:checked') || {}).value || 'pix'; }
    function taxaAtual() { return info ? (metodo() === 'pix' ? info.taxa.pix : info.taxa.cartao) : 0; }

    function fichaDoCurso(c) {
      q('#ck-r-curso').textContent = c ? c.nome : 'Escolha o curso';
      q('#ck-r-meta').textContent = c ? [c.carga_horaria, c.escolaridade].filter(Boolean).join(' · ') : '7 cursos presenciais no Centro do Rio';
      q('#ck-escolaridade').textContent = c && c.escolaridade ? 'escolaridade mínima: ' + c.escolaridade : 'escolaridade mínima';
      q('#ck-r-curso-valor').textContent = c && c.valor_curso_centavos ? brl(c.valor_curso_centavos) : 'consulte a escola';
      var meta = q('#ck-curso-meta');
      meta.hidden = !c;
      if (c) { q('#ck-m-carga').textContent = c.carga_horaria || ''; q('#ck-m-escolaridade').textContent = c.escolaridade ? 'Mínimo: ' + c.escolaridade : ''; }
      var foto = q('#ck-r-foto');
      if (c && c.imagem && foto.getAttribute('src') !== c.imagem) { foto.src = c.imagem; foto.alt = c.nome; }
    }

    function atualizar() {
      var c = cursos[sel.value];
      var inscricao = info ? info.inscricao_centavos : 9900;
      var taxa = taxaAtual();
      var cobre = q('#ck-cobre').checked;
      var total = inscricao + (cobre ? taxa : 0);
      fichaDoCurso(c);
      q('#ck-cobre-opcao').classList.toggle('marcado', cobre);
      marcarInicio();
      q('#ck-taxa-valor').textContent = '+ ' + brl(taxa);
      q('#ck-r-inscricao').textContent = brl(inscricao);
      q('#ck-r-taxa-linha').hidden = !cobre;
      q('#ck-r-taxa').textContent = brl(taxa);
      q('#ck-r-total').textContent = brl(total);
      q('#ck-total-btn').textContent = brl(total);
      var cartao = metodo() === 'cartao';
      q('#ck-cartao').hidden = !cartao;
      form.querySelectorAll('#ck-cartao input').forEach(function (i) { i.disabled = !cartao; });
      form.querySelectorAll('.ck-metodo').forEach(function (m) { m.classList.toggle('ativo', m.querySelector('input').checked); });
      if (sel.value) { var u = new URL(location.href); u.searchParams.set('curso', sel.value); history.replaceState(null, '', u); }
    }

    function erro(mensagem, campo) {
      form.querySelectorAll('.ck-campo.erro').forEach(function (c) { c.classList.remove('erro'); });
      erroEl.textContent = mensagem || '';
      if (!mensagem) return;
      var el = campos[campo] && q(campos[campo]);
      if (el) { el.closest('.ck-campo').classList.add('erro'); el.focus(); }
      else erroEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    /* Validação local: as mesmas regras do servidor, para a mensagem aparecer sem ida e volta. */
    function validar(dados) {
      if (!dados.curso) return erro('Escolha o curso.', 'curso');
      if (dados.nome.length < 5 || dados.nome.indexOf(' ') < 0) return erro('Informe seu nome completo.', 'nome');
      if (!cpfValido(dados.cpf)) return erro('CPF inválido. Confira os 11 números.', 'cpf');
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(dados.email)) return erro('E-mail inválido.', 'email');
      if (dados.telefone.length < 10 || dados.telefone.length > 11) return erro('Informe o WhatsApp com DDD.', 'telefone');
      if (dados.cartao) {
        if (dados.cartao.numero.length < 13 || !luhnValido(dados.cartao.numero)) return erro('Número do cartão inválido.', 'cartao_numero');
        if (dados.cartao.nome.length < 3) return erro('Informe o nome como está no cartão.', 'cartao_nome');
        if (digitos(dados.cartao.validade).length !== 4) return erro('Validade inválida. Use MM/AA.', 'cartao_validade');
        if (digitos(dados.cartao.cvv).length < 3) return erro('Código de segurança inválido.', 'cartao_cvv');
      }
      if (!dados.requisitos) return erro('Confirme que leu os requisitos do curso.');
      return true;
    }

    function lerFormulario() {
      var dados = {
        curso: sel.value, nome: q('#ck-nome').value.trim(), cpf: digitos(q('#ck-cpf').value), email: q('#ck-email').value.trim(),
        telefone: digitos(q('#ck-telefone').value), metodo: metodo(), cobre_taxa: q('#ck-cobre').checked,
        requisitos: q('#ck-requisitos').checked, site: q('#ck-site').value, origem: origemAtual
      };
      if (dados.metodo === 'cartao') {
        dados.cartao = { numero: digitos(q('#ck-cartao-numero').value), nome: q('#ck-cartao-nome').value.trim(), validade: q('#ck-cartao-validade').value, cvv: q('#ck-cartao-cvv').value };
      }
      return dados;
    }

    function enviar(e) {
      e.preventDefault();
      erro('');
      var dados = lerFormulario();
      if (validar(dados) !== true) return;
      var rotulo = btn.innerHTML;
      btn.disabled = true;
      btn.textContent = dados.metodo === 'pix' ? 'Gerando o PIX…' : 'Processando o pagamento…';
      var nomeCurso = cursos[dados.curso] ? cursos[dados.curso].nome : dados.curso, centavosInscricao = info ? info.inscricao_centavos : 9900;
      rastrear('Lead', { content_name: nomeCurso, content_ids: [dados.curso], content_category: 'matricula-cursos-presenciais', value: centavosInscricao / 100, currency: 'BRL' },
        'generate_lead', { currency: 'BRL', value: centavosInscricao / 100, curso: dados.curso, metodo: dados.metodo });
      api('pagamentos.php', { method: 'POST', body: JSON.stringify(dados) }).then(function (r) {
        dados.cartao = null;
        if (!r.ok) {
          btn.disabled = false;
          btn.innerHTML = rotulo;
          erro(r.erro || 'Não foi possível processar. Tente novamente.', r.campo);
          return;
        }
        // Dados de pagamento aceitos pelo provedor (PIX gerado ou cartão enviado): mesmo evento nos dois métodos.
        rastrear('AddPaymentInfo', { content_name: nomeCurso, content_ids: [dados.curso], content_type: 'product', value: r.total_centavos / 100, currency: 'BRL' },
          'add_payment_info', Object.assign({ payment_type: dados.metodo }, itemGa(dados.curso, nomeCurso, r.total_centavos)), { eventID: r.token + '-pagamento' });
        if (r.status === 'pago') { location.href = r.urls.parabens; return; }
        if (r.metodo !== 'pix') { location.href = r.urls.pendente; return; } // cartão em análise
        form.hidden = true;
        var painel = q('#ck-pix');
        painel.hidden = false;
        painelPix(r, painel);
        acompanhar(r.token, function (d) { location.href = d.urls.parabens; }, function (d) { location.href = d.urls.pendente; });
        window.scrollTo({ top: painel.getBoundingClientRect().top + window.scrollY - 90, behavior: 'smooth' });
      });
    }

    aplicarMascara(q('#ck-cpf'), mascaras.cpf);
    aplicarMascara(q('#ck-telefone'), mascaras.telefone);
    aplicarMascara(q('#ck-cartao-numero'), mascaras.cartaoNumero);
    aplicarMascara(q('#ck-cartao-validade'), mascaras.validade);
    aplicarMascara(q('#ck-cartao-cvv'), mascaras.cvv);
    form.addEventListener('change', atualizar);
    form.addEventListener('submit', enviar);
    var pedido = param('curso');
    if (pedido && cursos[pedido]) sel.value = pedido;
    atualizar();

    api('info.php').then(function (d) {
      if (!d.ok) { erro('O checkout está indisponível no momento. Tente novamente em instantes.'); btn.disabled = true; return; }
      info = d;
      d.cursos.forEach(function (c) { cursos[c.slug] = Object.assign(cursos[c.slug] || {}, c); });
      mostrarAvisoTeste(d);
      var pedido = param('curso');
      if (pedido && cursos[pedido]) sel.value = pedido;
      atualizar();
    });
  }

  // ------------------------------------------------------------------ tela: pendente
  function telaPendente() {
    var token = param('t'), card = q('#pd-card');
    if (!TOKEN.test(token)) { card.innerHTML = voltarCursos('Link inválido.'); return; }

    function falhou(d) {
      var voltar = URL_CHECKOUT + '?curso=' + encodeURIComponent(d.curso.slug);
      var textos = {
        expirado: ['Este código PIX venceu', 'Nenhum valor foi cobrado. Gere um novo código para continuar a matrícula em <b>' + esc(d.curso.nome) + '</b>.', 'Gerar novo PIX', 'btn-red'],
        recusado: ['Pagamento recusado', 'O emissor não autorizou a cobrança. Nenhum valor foi cobrado. Tente outro cartão ou pague com PIX.', 'Tentar de novo', 'btn-red'],
        estornado: ['Pagamento estornado', 'Esta inscrição foi estornada. Se quiser refazer a matrícula, use o botão abaixo.', 'Nova inscrição', 'btn-outline']
      };
      var t = textos[d.status] || textos.estornado;
      card.innerHTML = '<h2>' + t[0] + '</h2><p class="lead" style="font-size:1rem">' + t[1] + '</p><div class="cta-row"><a class="btn ' + t[3] + '" href="' + voltar + '">' + t[2] + '</a></div>';
    }

    api('status.php?t=' + encodeURIComponent(token)).then(function (d) {
      if (!d.ok) { card.innerHTML = voltarCursos(d.erro || 'Inscrição não encontrada.'); return; }
      mostrarAvisoTeste(d);
      if (d.status === 'pago') { location.replace(d.urls.parabens); return; }
      if (d.status !== 'pendente') { falhou(d); return; }
      if (d.metodo === 'pix') painelPix(d, card);
      else card.innerHTML = '<h2>Cartão em análise</h2><p class="lead" style="font-size:1rem">A operadora ainda está processando a cobrança de ' + brl(d.total_centavos) + ' para <b>' + esc(d.curso.nome) + '</b>. Esta página atualiza sozinha.</p><div class="ck-status" role="status"><span class="pulso" aria-hidden="true"></span> Aguardando confirmação…</div>';
      acompanhar(token, function (x) { location.href = x.urls.parabens; }, falhou);
    });
  }

  // ------------------------------------------------------------------ tela: parabéns
  function telaParabens() {
    var token = param('t'), card = q('#pb-card');
    if (!TOKEN.test(token)) { card.innerHTML = voltarCursos('Link inválido.'); return; }
    var ESTORNO = 'A inscrição reserva sua vaga; se não houver horário compatível ou você desistir antes da confirmação da aula, o valor é estornado.';

    function blocoEscola(d) {
      var e = d.escola || {};
      if (e.usuario || e.url) {
        return '<div class="ck-bloco"><h2>Seu acesso à secretaria da escola</h2><div class="ck-acesso"><div>Usuário: <b>' + esc(e.usuario || d.email) + '</b></div>'
          + (e.senha ? '<div>Senha temporária: <b>' + esc(e.senha) + '</b> <span class="ck-nota">(troque no primeiro acesso)</span></div>' : '')
          + '</div><div class="cta-row"><a class="btn btn-red" href="' + esc(urlSegura(e.url) || d.escola_url) + '">Acessar ambiente da secretaria</a></div>'
          + '<p class="ck-nota" style="margin-top:12px">Horário e turma você escolhe lá. ' + ESTORNO + '</p></div>';
      }
      if (e.configurada && e.status !== 'ok') {
        setTimeout(function () { api('status.php?t=' + encodeURIComponent(token)).then(function (x) { if (x.ok) render(x); }); }, 15000);
        return '<div class="ck-bloco"><h2>Pagamento confirmado, acesso em instantes</h2><p>Estamos criando sua matrícula na escola. O acesso aparece aqui e chega no seu e-mail em instantes.</p><div class="ck-status" role="status"><span class="pulso" aria-hidden="true"></span> Gerando acesso…</div></div>';
      }
      return '<div class="ck-bloco"><h2>Próximo passo: a secretaria fala com você</h2><p><b>A secretaria da Escola entra em contato pelo WhatsApp em até 2 dias úteis</b> para fechar turma e horário. Você não precisa se inscrever de novo na plataforma.</p>'
        + '<p class="ck-nota">O valor do curso é pago depois, na plataforma da escola. ' + ESTORNO + '</p>'
        + '<div class="cta-row"><a class="btn btn-outline" href="' + esc(d.escola_url) + '" target="_blank" rel="noopener">Conhecer a plataforma da escola</a></div></div>';
    }

    function render(d) {
      var metodo = d.metodo === 'pix' ? 'PIX' : 'cartão' + (d.cartao && d.cartao.ultimos4 ? ' final ' + esc(d.cartao.ultimos4) : '');
      var custos = d.taxa_centavos ? ', incluindo ' + brl(d.taxa_centavos) + ' de custos de processamento que você escolheu cobrir. Obrigado.' : '.';
      card.innerHTML = '<div class="ck-bloco"><p class="ck-ok"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Inscrição paga</p><h2>' + esc(d.curso.nome) + ' · ' + brl(d.total_centavos) + '</h2><p class="ck-nota">Pago por ' + metodo + custos + '</p></div>'
        + blocoEscola(d)
        + '<div class="ck-bloco"><p class="ck-nota" style="margin:0">Mandamos a confirmação para <b>' + esc(d.email) + '</b>. Guarde este link: <a href="' + esc(d.urls.parabens) + '">' + esc(d.urls.parabens) + '</a></p></div>';
    }

    function registrarCompra(d) {
      try {
        var chave = 'mcp_purchase_' + token;
        if (localStorage.getItem(chave)) return;
        localStorage.setItem(chave, '1');
      } catch (e) { /* sem localStorage: registra assim mesmo */ }
      rastrear('Purchase', { content_name: d.curso.nome, content_ids: [d.curso.slug], content_type: 'product', num_items: 1, value: d.total_centavos / 100, currency: 'BRL' },
        'purchase', Object.assign({ transaction_id: token, payment_type: d.metodo }, itemGa(d.curso.slug, d.curso.nome, d.total_centavos)), { eventID: token });
    }

    api('status.php?t=' + encodeURIComponent(token)).then(function (d) {
      if (!d.ok) { card.innerHTML = voltarCursos(d.erro || 'Inscrição não encontrada.'); return; }
      mostrarAvisoTeste(d);
      if (d.status !== 'pago') { location.replace(d.status === 'pendente' ? d.urls.pendente : URL_CHECKOUT + '?curso=' + encodeURIComponent(d.curso.slug)); return; }
      render(d);
      registrarCompra(d);
    });
  }

  var telas = { checkout: telaCheckout, pendente: telaPendente, parabens: telaParabens };
  var tela = telas[document.body.getAttribute('data-tela')];
  if (tela) tela();
})();
