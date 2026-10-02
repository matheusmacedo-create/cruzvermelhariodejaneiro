/* Checkout da matrícula em cursos presenciais: um arquivo para as telas do site.
   A tela é escolhida por <body data-tela="checkout|pendente|parabens|horarios|comparecimento|conferir">. Conversa só com
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
  /* Data AAAA-MM-DD (turma da escola) como DD/MM/AAAA. */
  function dataBr(iso) { var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || ''); return m ? m[3] + '/' + m[2] + '/' + m[1] : ''; }
  function voltarCursos(mensagem) {
    return '<p>' + esc(mensagem) + ' <a href="' + URL_CURSOS + '">Voltar para os cursos</a>.</p>';
  }

  /* A escolha do aviso de cookies (bloco de medição da página, window.cvrjMedicao). */
  function consentimento() {
    try { return (window.cvrjMedicao && window.cvrjMedicao.ler()) || null; } catch (e) { return null; }
  }

  /* utm_*, fbclid e gclid da URL, guardados na sessão para sobreviver à navegação entre páginas.
     Só com consentimento de estatística: sem ele, a inscrição segue sem a origem da visita. */
  function origem() {
    var o = {};
    var c = consentimento();
    if (!c || !c.estatistica) return o;
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
     currency, transaction_id). opcoes.eventID vai para o Meta: é o mesmo id que o servidor usa na API de
     Conversões, e a Meta junta os dois. Lead, AddPaymentInfo e Purchase o servidor manda sozinho (tem os
     dados da inscrição); o InitiateCheckout vai pelo repasse (opcoes.repassar, só com "sim" para marketing). */
  function rastrear(eventoMeta, dadosMeta, eventoGa, dadosGa, opcoes) {
    var id = opcoes && opcoes.eventID;
    try { if (window.fbq && eventoMeta) window.fbq('track', eventoMeta, dadosMeta || {}, id ? { eventID: id } : undefined); } catch (e) { /* pixel ausente */ }
    try { if (window.gtag && eventoGa) window.gtag('event', eventoGa, dadosGa || {}); } catch (e) { /* GA4 ausente */ }
    try { if (id && opcoes.repassar && window.cvrjMedicao && window.cvrjMedicao.servidor) window.cvrjMedicao.servidor(eventoMeta, id, opcoes.curso); } catch (e) { /* sem repasse */ }
  }
  /* id de evento do bloco de medição da página; sem ele (página antiga em cache), fica só o Pixel. */
  function novoIdEvento(prefixo) {
    try { return window.cvrjMedicao && window.cvrjMedicao.novoId ? window.cvrjMedicao.novoId(prefixo) : ''; } catch (e) { return ''; }
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
        'begin_checkout', itemGa(sel.value, c.nome, info.inscricao_centavos), { eventID: novoIdEvento('ic'), repassar: true, curso: sel.value });
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
      if (dados.telefone.length < 10 || dados.telefone.length > 11) return erro('Informe o telefone com DDD.', 'telefone');
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
      // O id do Lead vai junto com a inscrição: o servidor manda o mesmo Lead à API de Conversões.
      dados.evento_id = novoIdEvento('lead') || undefined;
      rastrear('Lead', { content_name: nomeCurso, content_ids: [dados.curso], content_category: 'matricula-cursos-presenciais', value: centavosInscricao / 100, currency: 'BRL' },
        'generate_lead', { currency: 'BRL', value: centavosInscricao / 100, curso: dados.curso, metodo: dados.metodo }, { eventID: dados.evento_id });
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
      if (e.status === 'ok' && e.resultado) {
        var semTurma = e.resultado === 'sem_turma', data = dataBr(e.turma_inicio);
        var link = e.aluno_novo ? urlSegura(e.link) : '';
        var entrada = e.aluno_novo
          ? (link
            ? 'Sua conta foi criada com o seu CPF. Para entrar pela primeira vez, <b>crie sua senha no botão abaixo</b>' + (e.link_validade ? ' (o link vale até ' + esc(e.link_validade) + ')' : '') + '. Depois é só entrar com seu <b>CPF</b> ou e-mail e a senha que você criou.'
            : 'Sua conta foi criada com o seu CPF. Para entrar pela primeira vez, use <b>"Esqueci minha senha"</b> na tela de entrada, com o e-mail <b>' + esc(d.email) + '</b>: a escola manda um link para você criar sua senha.')
          : 'Você já tinha conta na escola' + (!e.email_confere && e.email_conta ? ', com o e-mail <b>' + esc(e.email_conta) + '</b>' : '')
            + '. Entre com seu <b>CPF</b> ou e-mail e a senha de sempre. Esqueceu? Use "Esqueci minha senha" na tela de entrada.';
        return '<div class="ck-bloco"><h2>' + (semTurma ? 'Sua conta na plataforma da escola está pronta' : 'Sua matrícula já está na plataforma da escola') + '</h2>'
          + '<p>' + (semTurma
            ? 'Ainda não há turma aberta para este curso. <b>A secretaria matricula você na próxima turma e avisa por e-mail.</b> Você não precisa se inscrever de novo.'
            : 'A taxa de inscrição já aparece confirmada' + (data ? ' e sua turma começa em <b>' + esc(data) + '</b>' : '') + '. Datas, horários e o andamento do curso ficam na plataforma.') + '</p>'
          + '<div class="ck-acesso"><div>' + entrada + '</div></div>'
          + '<div class="cta-row">' + (link
            ? '<a class="btn btn-red" href="' + esc(link) + '">Criar minha senha</a><a class="btn btn-outline" href="' + esc(urlSegura(e.url) || d.escola_url) + '">Já criei: entrar</a>'
            : '<a class="btn btn-red" href="' + esc(urlSegura(e.url) || d.escola_url) + '">Entrar na plataforma da escola</a>') + '</div>'
          + '<p class="ck-nota" style="margin-top:12px">O valor do curso é pago depois, na plataforma da escola ou com a secretaria, no valor à vista. ' + ESTORNO + '</p></div>';
      }
      if (e.configurada && (e.status === 'pendente' || (e.status === 'erro' && !e.esgotado))) {
        setTimeout(function () { api('status.php?t=' + encodeURIComponent(token)).then(function (x) { if (x.ok) render(x); }); }, 15000);
        return '<div class="ck-bloco"><h2>Pagamento confirmado, matrícula em instantes</h2><p>Estamos criando sua matrícula na plataforma da escola. O acesso aparece aqui e chega no seu e-mail em instantes.</p><div class="ck-status" role="status"><span class="pulso" aria-hidden="true"></span> Criando matrícula…</div></div>';
      }
      return '<div class="ck-bloco"><h2>Próximo passo: a secretaria escreve para você</h2><p><b>A secretaria da Escola entra em contato por e-mail em até 3 dias úteis</b> para fechar turma e horário. Fique de olho na caixa de entrada e no spam. Você não precisa se inscrever de novo na plataforma.</p>'
        + '<p class="ck-nota">O valor do curso é pago depois, na plataforma da escola. ' + ESTORNO + '</p>'
        + '<p class="ck-nota">Alguma dúvida enquanto espera? <a href="#chat" data-abrir-chat data-assunto="matricula" data-curso="' + esc(d.curso.slug) + '">Fale com a gente pelo chat</a>.</p>'
        + '<div class="cta-row"><a class="btn btn-outline" href="' + esc(d.escola_url) + '" target="_blank" rel="noopener">Conhecer a plataforma da escola</a></div></div>';
    }

    /* Questionário de dias e horários: enquanto o aluno não responde, um alerta no topo (é o passo que
       falta); depois, o resumo embaixo, com o link para mudar. */
    function alertaHorarios(d) {
      var h = d.horarios;
      if (!h || !h.url || h.respondido) return '';
      return '<div class="ck-alerta" role="status"><div><p class="ck-alerta-titulo">Falta 1 passo: seus horários</p>'
        + '<p>Toque nos dias e horários em que você consegue vir às aulas. Leva 30 segundos e ajuda a secretaria a encaixar você na turma certa.</p></div>'
        + '<a class="btn btn-red" href="' + esc(h.url) + '">Escolher meus horários</a></div>';
    }
    function blocoHorarios(d) {
      var h = d.horarios;
      if (!h || !h.url || !h.respondido) return '';
      return '<div class="ck-bloco"><h2>Seus horários</h2><p>' + esc(h.resumo) + '</p><p class="ck-nota"><a href="' + esc(h.url) + '">Mudar meus horários</a></p></div>';
    }

    function render(d) {
      var metodo = d.metodo === 'pix' ? 'PIX' : 'cartão' + (d.cartao && d.cartao.ultimos4 ? ' final ' + esc(d.cartao.ultimos4) : '');
      var custos = d.taxa_centavos ? ', incluindo ' + brl(d.taxa_centavos) + ' de custos de processamento que você escolheu cobrir. Obrigado.' : '.';
      card.innerHTML = alertaHorarios(d)
        + '<div class="ck-bloco"><p class="ck-ok"><i class="fa-solid fa-circle-check" aria-hidden="true"><svg class="ico ico-circle-check" aria-hidden="true" focusable="false"><use href="#i-circle-check"/></svg></i> Inscrição paga</p><h2>' + esc(d.curso.nome) + ' · ' + brl(d.total_centavos) + '</h2><p class="ck-nota">Pago por ' + metodo + custos + '</p></div>'
        + blocoEscola(d)
        + blocoHorarios(d)
        + '<div class="ck-bloco"><p class="ck-nota" style="margin:0">Mandamos a confirmação para <b>' + esc(d.email) + '</b>. Guarde este link: <a href="' + esc(d.urls.parabens) + '">' + esc(d.urls.parabens) + '</a></p></div>';
    }

    function registrarCompra(d) {
      var c = consentimento();
      if (!c || (!c.estatistica && !c.marketing)) return; // sem consentimento, nada a medir nem a marcar
      try {
        var chave = 'mcp_purchase_' + token;
        if (localStorage.getItem(chave)) return;
        localStorage.setItem(chave, '1');
      } catch (e) { /* sem localStorage: registra assim mesmo */ }
      rastrear('Purchase', { content_name: d.curso.nome, content_ids: [d.curso.slug], content_type: 'product', value: d.total_centavos / 100, currency: 'BRL' },
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

  // ------------------------------------------------------------------ tela: dias e horários
  /* Grade dia × período: cada célula é um checkbox ("seg-noite"). Atalhos marcam conjuntos comuns, e o
     nome do dia ou do período marca a linha ou a coluna inteira. O contador mostra quantos horários
     estão marcados. Começo e "a data serve" são botões de escolha única; o recado fica recolhido. */
  function telaHorarios() {
    var token = param('t'), card = q('#hr-card');
    if (!TOKEN.test(token)) { card.innerHTML = voltarCursos('Link inválido.'); return; }

    function escolha(nome, lista, marcado) {
      return '<div class="hr-escolha">' + Object.keys(lista).map(function (k) {
        return '<label class="hr-opcao' + (k === marcado ? ' marcado' : '') + '"><input type="radio" name="' + nome + '" value="' + esc(k) + '"' + (k === marcado ? ' checked' : '') + '><span>' + esc(lista[k]) + '</span></label>';
      }).join('') + '</div>';
    }
    function grupo(id, titulo, dica, conteudo) {
      return '<div class="ck-bloco-form" role="group" aria-labelledby="hr-t-' + id + '" id="hr-' + id + '"><h2 class="ck-bloco-titulo" id="hr-t-' + id + '">' + titulo
        + (dica ? '<small>' + dica + '</small>' : '') + '</h2>' + conteudo + '</div>';
    }
    function grade(o, marcados) {
      var dias = Object.keys(o.dias), periodos = Object.keys(o.periodos);
      var html = '<div class="hr-atalhos" aria-label="Atalhos">' + o.atalhos.map(function (a) {
        return '<button type="button" class="hr-atalho" data-horarios="' + esc(a.horarios.join(' ')) + '" aria-pressed="false">' + esc(a.rotulo) + '</button>';
      }).join('') + '</div><table class="hr-grade"><thead><tr><td></td>' + periodos.map(function (p) {
        return '<th scope="col"><button type="button" class="hr-cab" data-horarios="' + dias.map(function (d) { return d + '-' + p; }).join(' ') + '" title="Marcar ' + esc(o.periodos[p].toLowerCase()) + ' em todos os dias">'
          + esc(o.periodos[p]) + '<small>' + esc(o.horas[p]) + '</small></button></th>';
      }).join('') + '</tr></thead><tbody>' + dias.map(function (d) {
        return '<tr><th scope="row"><button type="button" class="hr-cab" data-horarios="' + periodos.map(function (p) { return d + '-' + p; }).join(' ') + '" title="Marcar ' + esc(o.dias[d].toLowerCase()) + ' inteira">'
          + '<span class="hr-longo">' + esc(o.dias[d]) + '</span><span class="hr-curto">' + esc(o.dias_curtos[d]) + '</span></button></th>' + periodos.map(function (p) {
            var v = d + '-' + p, on = marcados.indexOf(v) >= 0;
            return '<td><label class="hr-slot' + (on ? ' marcado' : '') + '"><input type="checkbox" name="horarios" value="' + v + '"' + (on ? ' checked' : '')
              + ' aria-label="' + esc(o.dias[d] + ', ' + o.periodos[p].toLowerCase() + ' (' + o.horas[p] + ')') + '"><span aria-hidden="true"></span></label></td>';
          }).join('') + '</tr>';
      }).join('') + '</tbody></table><p class="hr-contador" id="hr-contador" aria-live="polite"></p>';
      return html;
    }
    function valores(form, nome) {
      return Array.prototype.map.call(form.querySelectorAll('input[name="' + nome + '"]:checked'), function (i) { return i.value; });
    }

    function formulario(d) {
      var r = d.resposta || {}, o = d.opcoes, data = dataBr(d.turma_inicio);
      card.innerHTML = '<p class="hr-curso"><b>' + esc(d.curso.nome) + '</b> · ' + (data ? 'sua turma começa em <b>' + esc(data) + '</b>' : 'a secretaria vai definir sua turma') + '</p>'
        + '<form id="hr-form" novalidate>'
        + grupo('horarios', 'Quando você consegue vir?', 'Toque em todos os horários possíveis', grade(o, r.horarios || []))
        + grupo('inicio', 'A partir de quando você pode começar?', '', escolha('inicio', o.inicio, r.inicio))
        + (data ? grupo('turma_serve', 'Sua turma começa em ' + esc(data) + '. Essa data funciona?', '', escolha('turma_serve', o.turma, r.turma_serve)) : '')
        + '<div class="ck-bloco-form"><details class="hr-recado"' + (r.observacao ? ' open' : '') + '><summary>Quer deixar um recado para a secretaria? (opcional)</summary>'
        + '<label class="ck-campo" for="hr-obs"><span class="hr-oculto">Recado para a secretaria</span><textarea id="hr-obs" name="observacao" maxlength="' + o.observacao_max + '" rows="3" placeholder="Ex.: só chego depois das 18h30; às quintas não posso.">' + esc(r.observacao || '') + '</textarea></label></details>'
        + '<div class="ck-erro" id="hr-erro" role="alert"></div>'
        + '<div class="cta-row"><button class="btn btn-red" type="submit" id="hr-enviar">' + (d.resposta ? 'Salvar alterações' : 'Enviar meus horários') + '</button></div></div>'
        + '</form>';
      ligar(d);
    }

    function ligar(d) {
      var form = q('#hr-form'), erro = q('#hr-erro'), botao = q('#hr-enviar'), rotulo = botao.textContent, contador = q('#hr-contador');
      var caixas = Array.prototype.slice.call(form.querySelectorAll('input[name="horarios"]'));
      function atualizar() {
        caixas.forEach(function (c) { c.parentNode.classList.toggle('marcado', c.checked); });
        Array.prototype.forEach.call(form.querySelectorAll('.hr-opcao'), function (l) { l.classList.toggle('marcado', q('input', l).checked); });
        Array.prototype.forEach.call(form.querySelectorAll('.hr-atalho'), function (b) {
          b.setAttribute('aria-pressed', b.getAttribute('data-horarios').split(' ').every(function (v) { return form.querySelector('input[value="' + v + '"]').checked; }) ? 'true' : 'false');
        });
        var n = valores(form, 'horarios').length;
        contador.textContent = n === 0 ? 'Nenhum horário marcado ainda.'
          : n + (n === 1 ? ' horário marcado.' : ' horários marcados.') + (n < 3 ? ' Quanto mais opções, mais fácil encaixar você numa turma.' : '');
      }
      /* Erro some assim que o aluno mexe no formulário: a mensagem velha não fica depois de corrigido. */
      function limparErros() {
        erro.textContent = '';
        Array.prototype.forEach.call(form.querySelectorAll('.ck-bloco-form.erro'), function (g) { g.classList.remove('erro'); g.removeAttribute('aria-describedby'); });
        Array.prototype.forEach.call(form.querySelectorAll('.hr-msg'), function (m) { m.parentNode.removeChild(m); });
      }
      /* Atalho, dia ou período: se todos já estão marcados, desmarca; senão, marca todos. */
      Array.prototype.forEach.call(form.querySelectorAll('[data-horarios]'), function (b) {
        b.addEventListener('click', function () {
          var alvo = b.getAttribute('data-horarios').split(' ').map(function (v) { return form.querySelector('input[value="' + v + '"]'); });
          var todos = alvo.every(function (c) { return c.checked; });
          alvo.forEach(function (c) { c.checked = !todos; });
          limparErros();
          atualizar();
        });
      });
      form.addEventListener('change', function () { limparErros(); atualizar(); });
      atualizar();
      /* A mensagem aparece também no grupo com erro: a de baixo, junto do botão, sai da tela quando a página rola até o grupo. */
      function falhar(campo, mensagem) {
        erro.textContent = mensagem;
        var g = campo && q('#hr-' + campo);
        if (!g) return;
        var m = document.createElement('p');
        m.className = 'hr-msg'; m.id = 'hr-msg-' + campo; m.textContent = mensagem;
        g.insertBefore(m, g.children[1] || null);
        g.classList.add('erro'); g.setAttribute('aria-describedby', m.id);
        g.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        limparErros();
        var corpo = { t: token, horarios: valores(form, 'horarios'), inicio: valores(form, 'inicio')[0] || '',
          turma_serve: valores(form, 'turma_serve')[0] || '', observacao: q('#hr-obs').value };
        var falta = !corpo.horarios.length ? ['horarios', 'Marque pelo menos um horário em que você consegue vir.']
          : !corpo.inicio ? ['inicio', 'Diga a partir de quando você pode começar.']
          : (d.turma_inicio && !corpo.turma_serve) ? ['turma_serve', 'Diga se a data da sua turma funciona para você.'] : null;
        if (falta) { falhar(falta[0], falta[1]); return; }
        botao.disabled = true; botao.textContent = 'Enviando…';
        api('horarios.php', { method: 'POST', body: JSON.stringify(corpo) }).then(function (x) {
          botao.disabled = false; botao.textContent = rotulo;
          if (!x.ok) { falhar(x.campo, x.erro || 'Não foi possível salvar agora. Tente de novo.'); return; }
          resumo(x, true);
          window.scrollTo(0, 0); // o botão fica no fim do formulário, bem mais longo que o resumo
        });
      });
    }

    function resumo(d, recemSalvo) {
      card.innerHTML = '<div class="ck-bloco" style="border-top:0;padding-top:0">'
        + (recemSalvo ? '<p class="ck-ok"><i class="fa-solid fa-circle-check" aria-hidden="true"><svg class="ico ico-circle-check" aria-hidden="true" focusable="false"><use href="#i-circle-check"/></svg></i> Horários recebidos</p>' : '')
        + '<h2>' + (recemSalvo ? 'Pronto, ' + esc(d.nome) + '!' : 'Seus horários') + '</h2>'
        + '<p>A secretaria da Escola usa estas respostas para montar as turmas' + (d.turma_inicio ? ' e, se a data da sua turma não funcionar, fala com você sobre outra.' : ' e avisa por e-mail quando a sua turma for definida.') + '</p>'
        + '<div class="ck-acesso"><div><b>' + esc(d.curso.nome) + '</b></div><div>' + esc(d.resumo) + '</div>'
        + (d.resposta && d.resposta.observacao ? '<div class="ck-nota">“' + esc(d.resposta.observacao) + '”</div>' : '') + '</div>'
        + '<div class="cta-row"><a class="btn btn-red" href="' + esc(d.urls.parabens) + '">Voltar para minha inscrição</a><button class="btn btn-outline" type="button" id="hr-editar">Mudar meus horários</button></div>'
        + '<p class="ck-nota">Você pode mudar quando quiser, por este mesmo link.</p></div>';
      q('#hr-editar').addEventListener('click', function () { formulario(d); window.scrollTo(0, 0); });
    }

    api('horarios.php?t=' + encodeURIComponent(token)).then(function (d) {
      if (!d.ok) {
        card.innerHTML = d.http === 403 ? '<p>' + esc(d.erro) + '</p><p class="ck-nota">Assim que o pagamento for aprovado, este link abre o questionário.</p>' : voltarCursos(d.erro || 'Inscrição não encontrada.');
        return;
      }
      if (d.resposta) resumo(d, false); else formulario(d);
    });
  }

  // ------------------------------------------------------------------ comprovante de comparecimento
  /* Link pessoal do comprovante (comparecimento/?t=): antes do fim da aula mostra quando fica pronto e
     atualiza sozinho; depois, o botão do PDF e o código de verificação. */
  function telaComparecimento() {
    var card = q('#cp-card');
    var token = param('t');
    var ok = '<p class="ck-ok"><i class="fa-solid fa-circle-check" aria-hidden="true"><svg class="ico ico-circle-check" aria-hidden="true" focusable="false"><use href="#i-circle-check"/></svg></i> ';
    if (!TOKEN.test(token)) { card.innerHTML = voltarCursos('Link incompleto. Abra o link do e-mail ou o que apareceu no ponto da sede.'); return; }
    function mostrar(d) {
      if (!d.ok) { card.innerHTML = voltarCursos(d.erro || 'Comprovante não encontrado.'); return; }
      var aula = '<div class="ck-acesso"><div><b>' + esc(d.curso) + '</b></div><div>' + esc((d.dia_semana ? d.dia_semana + ', ' : '') + d.data + ', ' + d.horario) + '</div>'
        + '<div class="ck-nota">' + esc(d.nome) + ' · chegada registrada às ' + esc(d.chegada) + '</div></div>';
      if (d.status !== 'valida') {
        card.innerHTML = '<h2>Presença cancelada</h2>' + aula
          + '<p>A secretaria cancelou esta presença, e o comprovante não vale mais. Se foi engano, escreva para contato@cruzvermelhariodejaneiro.org.</p>';
        return;
      }
      if (!d.disponivel) {
        card.innerHTML = ok + 'Presença registrada</p><h2>O comprovante fica pronto no fim da aula</h2>' + aula
          + '<p>Ele aparece aqui em <b>' + esc(d.disponivel_em) + '</b>' + (d.email ? ' e vai também para o e-mail <b>' + esc(d.email) + '</b>' : '') + '.</p>'
          + '<div class="ck-status" role="status"><span class="pulso" aria-hidden="true"></span> Esta página atualiza sozinha</div>';
        setTimeout(carregar, 60000);
        return;
      }
      card.innerHTML = ok + 'Comprovante pronto</p><h2>Seu comprovante de comparecimento</h2>' + aula
        + '<div class="cta-row"><a class="btn btn-red" href="' + esc(urlSegura(d.pdf)) + '">Baixar comprovante (PDF)</a></div>'
        + '<div class="ck-codigo"><span>Código de verificação</span><b>' + esc(d.codigo) + '</b>'
        + '<small>Quem receber o comprovante pode conferir se ele é verdadeiro em <a href="' + esc(urlSegura(d.conferir)) + '">cruzvermelhariodejaneiro.org/conferir</a>.</small></div>'
        + (d.email ? '<p class="ck-nota">' + (d.enviado ? 'Também enviamos o comprovante para ' + esc(d.email) + '.' : 'Uma cópia vai para ' + esc(d.email) + ' em alguns minutos.') + '</p>' : '');
    }
    function carregar() { api('comparecimento.php?t=' + encodeURIComponent(token)).then(mostrar); }
    carregar();
  }

  // ------------------------------------------------------------------ conferência de documentos
  /* conferir/?c=: quem recebeu um comprovante ou uma declaração digita o código e vê o que foi declarado. */
  function telaConferir() {
    var card = q('#cf-card');
    function formulario(codigo, erro) {
      card.innerHTML = '<form id="cf-form" novalidate><label class="ck-campo"><span>Código de verificação</span>'
        + '<input id="cf-codigo" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="Ex.: K7QM-4XPA" maxlength="14" value="' + esc(codigo || '') + '"></label>'
        + '<div class="ck-erro" role="alert">' + esc(erro || '') + '</div>'
        + '<button class="btn btn-red ck-btn" type="submit">Conferir</button></form>'
        + '<p class="ck-nota">O código está no quadro “Código de verificação” do comprovante de comparecimento ou da declaração de horas voluntárias.</p>';
      q('#cf-form').addEventListener('submit', function (e) { e.preventDefault(); conferir(q('#cf-codigo').value); });
    }
    function conferir(codigo) {
      var limpo = String(codigo || '').toUpperCase().replace(/[\s.\-]+/g, '');
      if (!/^[A-HJ-NP-Z2-9]{8}$/.test(limpo)) { formulario(codigo, 'O código tem 8 letras e números, como K7QM-4XPA.'); return; }
      try { history.replaceState(null, '', '?c=' + limpo); } catch (e) { /* sem história: segue */ }
      card.innerHTML = '<p>Conferindo…</p>';
      api('conferir.php?c=' + encodeURIComponent(limpo)).then(function (d) {
        if (!d.ok) { formulario(codigo, d.erro); return; }
        card.innerHTML = '<p class="ck-selo ' + (d.valido ? 'ok' : 'erro') + '">' + esc(d.situacao) + '</p>'
          + '<h2>' + esc(d.tipo) + '</h2>'
          + '<p><b>' + esc(d.nome) + '</b><br><span class="ck-nota">CPF ' + esc(d.cpf) + ' · código ' + esc(d.codigo) + '</span></p>'
          + d.linhas.map(function (l) { return '<div class="ck-linha"><span>' + esc(l[0]) + '</span><b>' + esc(l[1]) + '</b></div>'; }).join('')
          + '<p class="ck-nota">Estes são os dados que a Cruz Vermelha Brasileira Rio de Janeiro registrou. Confira se batem com o documento que você recebeu.</p>'
          + '<div class="cta-row"><button class="btn btn-outline" type="button" id="cf-outro">Conferir outro código</button></div>';
        q('#cf-outro').addEventListener('click', function () {
          try { history.replaceState(null, '', location.pathname); } catch (e) { /* segue */ }
          formulario('');
        });
      });
    }
    if (param('c')) conferir(param('c')); else formulario('');
  }

  var telas = { checkout: telaCheckout, pendente: telaPendente, parabens: telaParabens, horarios: telaHorarios, comparecimento: telaComparecimento, conferir: telaConferir };
  var tela = telas[document.body.getAttribute('data-tela')];
  if (tela) tela();
})();
