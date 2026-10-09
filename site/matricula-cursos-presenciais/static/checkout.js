/* Checkout da matrícula em cursos presenciais: um arquivo para as telas do site.
   A tela é escolhida por <body data-tela="checkout|pendente|parabens|horarios|comparecimento|conferir">. Conversa só com
   /matricula-cursos-presenciais/api/ (mesma origem). Nada aqui guarda dado de cartão.

   Pagar tudo (10/2026; spec 1.12, 1.13, 10.4 e 10.5): o checkout vende "Taxa de inscrição + matrícula" (marcada) ou "Só
   a taxa de inscrição", conforme os planos que o servidor devolve para o curso (api/info.php), com parcelas no cartão só
   na opção 1 (api/parcelas.php, a simulação da Unicopag), a caixa da oferta a prazo, o Resumo das condições e a barra de
   ação. O navegador nunca manda preço: manda as escolhas e o total que mostrou (total_mostrado_centavos), para conferir.
   Tolerância a cache (T9): todo elemento novo é conferido antes de usar. Com o HTML antigo (sem input[name=plano]), o
   POST vai sem plano, sem parcelas e sem total, e o servidor cobra só a taxa, como antes. */
(function () {
  'use strict';

  var API = '/matricula-cursos-presenciais/api/';
  var URL_CURSOS = '/matricula-cursos-presenciais/';
  var URL_CHECKOUT = URL_CURSOS + 'checkout/';
  var TOKEN = /^[a-f0-9]{40}$/;
  var INTERVALO_STATUS = 5000;
  var NOTA_ESTORNO = 'Você pode desistir em até 7 dias depois do pagamento e recebe de volta tudo o que pagou. <a href="/reembolso/">Regras de cancelamento e reembolso</a>.';
  var PASSO_03 = 'Antes da primeira aula, você recebe por e-mail e na sua conta as orientações da turma: data, horário, local e o que vestir. A entrada na aula é liberada com a matrícula paga.';
  var ENDERECO = 'Praça da Cruz Vermelha, 10, Centro, Rio de Janeiro/RJ';
  var CONTATO = 'contato@cruzvermelhariodejaneiro.org';
  // Quem vende (decisão 6 do dono). O info.php manda o do config do servidor; este é só o padrão até ele chegar.
  var RECEBEDOR_PADRAO = { nome: 'O-CVB Filial Rio de Janeiro Ensino Ltda', cnpj: '67.733.551/0001-35' };
  // Etapas do topo (spec 1.12 e 10.4): os rótulos da escola, com "Curso escolhido" no lugar de "Contrato".
  var PASSOS = {
    completo: ['Curso escolhido', 'Pagamento', 'Matrícula confirmada'],
    semTurma: ['Curso escolhido', 'Pagamento', 'Data por e-mail'],
    taxa: ['Curso escolhido', 'Taxa de inscrição', 'Matrícula']
  };
  var ICONE_CHECK = '<svg class="ico ico-check" aria-hidden="true" focusable="false"><use href="#i-check"/></svg>';
  var ICONE_OK = '<svg class="ico ico-circle-check" aria-hidden="true" focusable="false"><use href="#i-circle-check"/></svg>';

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
  function dataBr(iso) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || ''); return m ? m[3] + '/' + m[2] + '/' + m[1] : ''; }
  function voltarCursos(mensagem) {
    return '<p>' + esc(mensagem) + ' <a href="' + URL_CURSOS + '">Voltar para os cursos</a>.</p>';
  }
  /* Elementos conferidos (T9): o JS novo com o HTML antigo, ou o contrário, nunca quebra por falta de um id. */
  function texto(seletor, t) { var el = q(seletor); if (el) el.textContent = t; return el; }
  function conteudo(seletor, h) { var el = q(seletor); if (el) el.innerHTML = h; return el; }
  function mostrar(seletor, sim) { var el = typeof seletor === 'string' ? q(seletor) : seletor; if (el) el.hidden = !sim; return el; }
  /* H1 e lead do topo da página (iguais no HTML antigo e no novo). */
  function topo(h1, lead) {
    if (h1 != null) texto('.ck-hero h1', h1);
    if (lead != null) { var el = q('.ck-hero .lead'); if (el) { el.textContent = lead; el.hidden = !lead; } }
  }
  /* Rótulos das etapas do topo, pelo plano (spec 1.12; 10.4 na venda sem turma). */
  function rotularPassos(tipo) {
    var rotulos = PASSOS[tipo] || PASSOS.taxa;
    Array.prototype.forEach.call(document.querySelectorAll('.ck-passos li'), function (li, i) {
      var spans = li.querySelectorAll('span');
      if (rotulos[i] && spans.length) spans[spans.length - 1].textContent = rotulos[i];
    });
  }
  function categoria(plano) { return plano === 'taxa_e_matricula' ? 'taxa-e-matricula' : 'taxa-inscricao'; }
  /* "10 parcelas mensais: 1ª de R$ 35,46 e 9 de R$ 35,43" ou "3 parcelas mensais de R$ 98,58" (a soma é sempre o total). */
  function parcelasTexto(n, parcela, primeira) {
    return primeira && primeira !== parcela
      ? n + ' parcelas mensais: 1ª de ' + brl(primeira) + ' e ' + (n - 1) + ' de ' + brl(parcela)
      : n + ' parcelas mensais de ' + brl(parcela);
  }
  /* A regra da desistência depois dos 7 dias (P4, versão B), a mesma do e-mail e do comprovante (api/lib/email.php). */
  function regraP4(nomeRecebedor) {
    return 'tudo volta se você desistir antes de a turma ser confirmada; com a turma confirmada, se você desistir antes da primeira aula, '
      + 'a matrícula volta, com os juros do parcelamento que correspondem a ela, e a taxa de inscrição fica com ' + nomeRecebedor;
  }
  function botaoChat(rotulo, assunto, slug, classe) {
    return '<a class="btn ' + (classe || 'btn-outline') + '" href="#chat" data-abrir-chat data-assunto="' + esc(assunto) + '"' + (slug ? ' data-curso="' + esc(slug) + '"' : '') + '>' + esc(rotulo) + '</a>';
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
     dados da inscrição); o InitiateCheckout vai pelo repasse (opcoes.repassar, só com "sim" para marketing),
     com o plano marcado (opcoes.plano; o servidor só aceita um plano da lista dele). Valores sempre sem juros (E9). */
  function rastrear(eventoMeta, dadosMeta, eventoGa, dadosGa, opcoes) {
    var id = opcoes && opcoes.eventID;
    try { if (window.fbq && eventoMeta) window.fbq('track', eventoMeta, dadosMeta || {}, id ? { eventID: id } : undefined); } catch (e) { /* pixel ausente */ }
    try { if (window.gtag && eventoGa) window.gtag('event', eventoGa, dadosGa || {}); } catch (e) { /* GA4 ausente */ }
    try { if (id && opcoes.repassar && window.cvrjMedicao && window.cvrjMedicao.servidor) window.cvrjMedicao.servidor(eventoMeta, id, opcoes.curso, opcoes.plano); } catch (e) { /* sem repasse */ }
  }
  /* id de evento do bloco de medição da página; sem ele (página antiga em cache), fica só o Pixel. */
  function novoIdEvento(prefixo) {
    try { return window.cvrjMedicao && window.cvrjMedicao.novoId ? window.cvrjMedicao.novoId(prefixo) : ''; } catch (e) { return ''; }
  }
  /* Itens do GA4 (spec 4.1): taxa e matrícula separadas na opção 1; só a taxa na opção 2. value = o do evento. */
  function itemGa(slug, nome, plano, inscricao, matricula, valor) {
    var itens = [{ item_id: slug, item_name: nome + ' — Taxa de inscrição', item_category: 'Cursos presenciais', item_category2: 'taxa', price: inscricao / 100, quantity: 1 }];
    if (plano === 'taxa_e_matricula' && matricula > 0) {
      itens.push({ item_id: slug + '-matricula', item_name: nome + ' — Matrícula', item_category: 'Cursos presenciais', item_category2: 'matricula', price: matricula / 100, quantity: 1 });
    }
    return { currency: 'BRL', value: valor / 100, items: itens };
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

  /* Consulta status.php até a inscrição sair de pendente. Devolve uma função que interrompe. PIX do plano completo cuja
     turma fechou (pix_turma_fechada) também para: a tela Pendente troca o QR pelo aviso (T8). */
  function acompanhar(token, aoPagar, aoFalhar) {
    var parado = false;
    function passo() {
      if (parado) return;
      api('status.php?t=' + encodeURIComponent(token)).then(function (d) {
        if (parado) return;
        if (d.status === 'pago') { parado = true; aoPagar(d); return; }
        if (d.status === 'expirado' || d.status === 'recusado' || d.status === 'estornado' || (d.status === 'pendente' && (d.pix_turma_fechada || d.pix_ja_pago))) { parado = true; aoFalhar(d); return; }
        setTimeout(passo, document.hidden ? INTERVALO_STATUS * 3 : INTERVALO_STATUS);
      });
    }
    setTimeout(passo, INTERVALO_STATUS);
    return function () { parado = true; };
  }

  /* O certificado de amostra do curso (scripts/gerar_certificado_modelo.py), abaixo do PIX: o que a pessoa leva ao
     concluir. Sem a imagem do curso, o bloco some (onerror). */
  function certificadoPix(d) {
    var slug = d.curso && d.curso.slug;
    if (!slug || !/^[a-z0-9-]+$/.test(slug)) return '';
    return '<div class="ck-cert"><img src="/matricula-cursos-presenciais/img/certificado-' + slug + '-640.webp" alt="Modelo do certificado de '
      + esc(d.curso.nome || '') + ' da Cruz Vermelha Brasileira Rio de Janeiro" width="640" height="452" loading="lazy" onerror="this.parentNode.hidden=true">'
      + '<p><b>Ao concluir, você recebe o certificado da Cruz Vermelha</b>, reconhecida no Brasil e no mundo, com o seu nome, o curso e a carga horária. <span>Imagem de modelo.</span></p></div>';
  }

  /* Etapas do PIX (spec 1.13): QR Code gerado (Pronto), Aguardando pagamento, Banco confirma a transação, e o último
     passo: Matrícula confirmada (opção 1), Inscrição confirmada (opção 2) ou Pagamento confirmado (sem turma, 10.5). */
  function etapasPix(ultimo) {
    var etapas = [['QR Code gerado', 'Pronto', 'feito'], ['Aguardando pagamento', 'Aguardando', 'atual'], ['Banco confirma a transação', 'Em breve', ''], [ultimo, 'Em breve', '']];
    return '<ol class="ck-etapas" id="ck-etapas" aria-label="Etapas do pagamento">' + etapas.map(function (e, i) {
      return '<li class="ck-etapa ' + e[2] + '"><span class="ck-etapa-n">' + (e[2] === 'feito' ? ICONE_CHECK : i + 1) + '</span><p><small>Passo ' + (i + 1) + '</small>' + esc(e[0])
        + '</p><span class="ck-etapa-badge">' + e[1] + '</span></li>';
    }).join('') + '</ol>';
  }
  /* Pagamento confirmado: as etapas avançam (Pago, Confirmado, Registrando…) antes de a página seguir. */
  function avancarEtapas(alvo, depois) {
    var itens = alvo ? alvo.querySelectorAll('.ck-etapa') : [];
    function marcar(i, classe, badge) {
      var li = itens[i];
      if (!li) return;
      li.className = 'ck-etapa ' + classe;
      li.querySelector('.ck-etapa-badge').textContent = badge;
      if (classe === 'feito') li.querySelector('.ck-etapa-n').innerHTML = ICONE_CHECK;
    }
    marcar(1, 'feito', 'Pago');
    marcar(2, 'feito', 'Confirmado');
    marcar(3, 'atual', 'Registrando…');
    setTimeout(depois, itens.length ? 1200 : 0);
  }

  /* Painel do PIX (checkout e pendente; spec 1.13 e 10.5): valor, prazo da turma, QR code, copia e cola, etapas.
     turmaReserva: a turma do curso no checkout, para o prazo da opção 2 (a visão pública só traz a turma da opção 1). */
  function painelPix(d, alvo, turmaReserva) {
    var copia = d.pix && d.pix.copia_cola ? d.pix.copia_cola : '';
    var linkPix = urlSegura(d.pix && d.pix.url);
    var completo = d.plano === 'taxa_e_matricula';
    var semTurma = completo && !!d.sem_turma;
    var turma = semTurma ? null : (d.turma || (completo ? null : turmaReserva) || null);
    var valor = d.total_cobrado_centavos || d.total_centavos;
    // O PIX vale 24 h e a turma fecha antes: o prazo fica sempre à vista quando há turma (T8).
    var prazo = turma && turma.prazo && turma.dia ? '<p class="ck-prazo">Pague até ' + esc(turma.prazo) + ', para entrar na turma de ' + esc(turma.dia) + '.</p>' : '';
    alvo.innerHTML = '<div class="ck-pix">'
      + '<h2>Escaneie e pague</h2>'
      + '<p class="lead" style="font-size:1rem;max-width:none">Abra o app do banco e aponte a câmera, ou use o código Copia e Cola.</p>'
      + '<p class="ck-pix-valor"><span>' + (completo ? 'Total' : 'Taxa de inscrição') + '</span><strong>' + brl(valor) + '</strong></p>'
      + prazo
      + '<div class="ck-qr" id="ck-qr"></div>'
      + '<label class="ck-campo"><span class="sr-only">Código PIX copia e cola</span><textarea class="ck-copia" id="ck-copia" readonly>' + esc(copia) + '</textarea></label>'
      + '<div class="cta-row" style="justify-content:center;margin-top:12px"><button class="btn btn-red" type="button" id="ck-copiar">Copiar código PIX</button>'
      + (linkPix ? '<a class="btn btn-outline" href="' + esc(linkPix) + '" target="_blank" rel="noopener">Abrir página do PIX</a>' : '') + '</div>'
      + '<div class="ck-status" role="status"><span class="pulso" aria-hidden="true"></span> Verificando automaticamente…</div>'
      + etapasPix(completo ? (semTurma ? 'Pagamento confirmado' : 'Matrícula confirmada') : 'Inscrição confirmada')
      + '<p class="ck-nota" style="margin-top:14px">Enviamos este código para <b>' + esc(d.email) + '</b>. Ele vale por 24 horas. Se fechar esta página, volte pelo link do e-mail.</p>'
      + certificadoPix(d)
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

  /* PIX do plano completo com a turma fechada (passou o fim das inscrições, lotou ou saiu do oferta.json): o QR some e a
     pessoa não paga um código que já não vale para a turma (spec 1.13, T8). */
  function pixTurmaFechada(d, alvo) {
    var slug = d.curso && d.curso.slug ? d.curso.slug : '';
    var link = URL_CHECKOUT + '?curso=' + encodeURIComponent(slug);
    if (d.pix_ja_pago) {
      // Outra compra deste curso, do mesmo CPF, já foi paga: pagar este código levaria a uma devolução manual.
      alvo.innerHTML = '<div class="ck-pix"><h2>Você já pagou este curso.</h2>'
        + '<p class="lead" style="font-size:1rem;max-width:none"><b>Não pague este código.</b> A confirmação do pagamento já está no seu e-mail.</p>'
        + '<div class="cta-row" style="justify-content:center">' + botaoChat('Falar com a secretaria', 'pagamento', slug) + '</div></div>';
      return;
    }
    // A turma fechou, mas o curso continua vendendo tudo sem turma (10.3): a pessoa se inscreve de novo, na fila.
    alvo.innerHTML = '<div class="ck-pix"><h2>As inscrições desta turma fecharam.</h2>'
      + '<p class="lead" style="font-size:1rem;max-width:none"><b>Não pague este código.</b>' + (d.pix_fila_disponivel ? ' Você pode se inscrever de novo e entrar na fila da próxima turma, com tudo pago.' : '') + '</p>'
      + '<div class="cta-row" style="justify-content:center"><a class="btn btn-red" href="' + link + '">' + (d.pix_fila_disponivel ? 'Inscrever-se de novo' : 'Pagar só a taxa de inscrição') + '</a></div></div>';
  }

  /* Selo de teste só visual e só com ?teste=1 (spec 2.2): o preço de teste vale apenas para os CPFs da lista (E16). */
  function mostrarAvisoTeste(d) { var el = q('#ck-teste'); if (d && d.teste && el && param('teste') === '1') el.hidden = false; }

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
  function aplicarMascara(el, fn) { if (el) el.addEventListener('input', function () { el.value = fn(el.value); }); }

  function telaCheckout() {
    var form = q('#ck-form'), sel = q('#ck-curso'), erroEl = q('#ck-erro'), btn = q('#ck-pagar');
    var info = null, cursos = {}, origemAtual = origem(), checkoutIniciado = false;
    try { cursos = JSON.parse(q('#ck-cursos').textContent) || {}; } catch (e) { cursos = {}; }
    // HTML novo (com o grupo de planos) ou antigo em cache: no antigo, tudo funciona como antes do pagar tudo.
    var temPlanos = !!form.querySelector('input[name=plano]');
    var inscricaoHtml = parseInt(form.getAttribute('data-inscricao-centavos'), 10) || 9900;
    var bloqueado = {};       // cursos em que o servidor recusou a opção 1 nesta visita (422 de plano)
    var escolhaManual = '';   // o plano que a pessoa marcou à mão (a opção 1 não volta a ser marcada por cima dela)
    var ofereciaOpcao1 = !!(q('#ck-plano-completo') && !q('#ck-plano-completo').hidden);
    var simulacoes = {};      // curso|cobre|divulgacao -> { estado: 'carregando' | 'ok' | 'falha', opcoes, motivo }
    var agendada = null;      // simulação esperando os 300 ms
    var opcoesNoSeletor = null, nEscolhido = 1;
    var ajusteTotal = null;   // { chave, valor }: total à vista que o servidor mandou num 422 de total
    var atual = {};           // o último cálculo da tela (plano, parcelas, total mostrado, turma)
    var campos = {
      curso: '#ck-curso', nome: '#ck-nome', cpf: '#ck-cpf', email: '#ck-email', telefone: '#ck-telefone',
      cartao_numero: '#ck-cartao-numero', cartao_nome: '#ck-cartao-nome', cartao_validade: '#ck-cartao-validade', cartao_cvv: '#ck-cartao-cvv'
    };

    function metodo() { return (form.querySelector('input[name=metodo]:checked') || {}).value || 'pix'; }
    function planoMarcado() { var r = form.querySelector('input[name=plano]:checked'); return r ? r.value : 'so_taxa'; }
    function inscricaoAtual() { return info && typeof info.inscricao_centavos === 'number' ? info.inscricao_centavos : inscricaoHtml; }
    function taxaAtual() { return info ? (metodo() === 'pix' ? info.taxa.pix : info.taxa.cartao) : 0; }
    /* Contribuição para a divulgação: o valor do info.php; antes dele, o que veio no HTML. 0 = opção desligada. */
    function divulgacaoAtual() {
      if (info && typeof info.divulgacao_centavos === 'number') return info.divulgacao_centavos;
      var d = q('#ck-divulgacao');
      return d ? parseInt(d.getAttribute('data-centavos'), 10) || 0 : 0;
    }
    function matriculaDe(c) {
      if (!c) return 0;
      if (typeof c.matricula_centavos === 'number') return c.matricula_centavos;
      return c.valor_curso_centavos || 0;
    }
    /* A turma do curso com inscrições abertas, com a mesma regra de prazo do servidor (fim das inscrições e lotação). */
    function turmaDe(c) {
      var t = c && c.turma;
      if (!t || !t.inscricoes_ate) return null;
      var fim = Date.parse(t.inscricoes_ate);
      return !isNaN(fim) && Date.now() <= fim && !t.lotada ? t : null;
    }
    /* Os planos do curso: os do info.php (fonte), ou os da página até ele chegar. Fora deles, só a taxa. */
    function planosDe(slug, c, turma) {
      if (!c || !temPlanos || bloqueado[slug] || !(matriculaDe(c) > 0)) return ['so_taxa'];
      var lista = info && Array.isArray(c.planos) ? c.planos : (Array.isArray(c.planos_padrao) ? c.planos_padrao : []);
      if (lista.indexOf('taxa_e_matricula') < 0) return ['so_taxa'];
      // A turma fechou com a página aberta: sem a venda sem turma confirmada pelo servidor, a opção 1 sai.
      if (!turma && !(info && c.sem_turma)) return ['so_taxa'];
      return ['taxa_e_matricula', 'so_taxa'];
    }
    function agente() { return (info && info.agente_financiador) || (info && info.recebedor) || RECEBEDOR_PADRAO; }
    function recebedor() { return (info && info.recebedor) || RECEBEDOR_PADRAO; }
    function dataLimite() { return info && info.espera_data_limite ? info.espera_data_limite : ''; }

    function marcarInicio() {
      var c = cursos[sel.value];
      if (checkoutIniciado || !info || !c) return;
      checkoutIniciado = true;
      var plano = atual.plano || 'so_taxa', inscricao = inscricaoAtual(), matricula = plano === 'taxa_e_matricula' ? matriculaDe(c) : 0;
      // InitiateCheckout: o plano marcado na hora, sem opcionais (spec 4.1).
      rastrear('InitiateCheckout', { content_name: c.nome, content_ids: [sel.value], content_type: 'product', num_items: 1, value: (inscricao + matricula) / 100, currency: 'BRL', content_category: categoria(plano), plano: plano },
        'begin_checkout', Object.assign({ plano: plano }, itemGa(sel.value, c.nome, plano, inscricao, matricula, inscricao + matricula)), { eventID: novoIdEvento('ic'), repassar: true, curso: sel.value, plano: plano });
    }

    // ---------------------------------------------------------------- parcelas (spec 2.4; api/parcelas.php)
    function chaveSimulacao(slug, cobre, ajuda) { return slug + '|' + (cobre ? 1 : 0) + '|' + (ajuda ? 1 : 0); }
    /* Pede as parcelas 300 ms depois da última mudança de curso ou opcionais. A pedida que ficou para trás é esquecida. */
    function pedirParcelas(chave, slug, cobre, ajuda) {
      if (agendada && agendada.chave !== chave) { clearTimeout(agendada.timer); delete simulacoes[agendada.chave]; }
      simulacoes[chave] = { estado: 'carregando' };
      agendada = { chave: chave, timer: setTimeout(function () {
        agendada = null;
        api('parcelas.php?curso=' + encodeURIComponent(slug) + '&cobre=' + (cobre ? 1 : 0) + '&divulgacao=' + (ajuda ? 1 : 0)).then(function (r) {
          if (r.ok && Array.isArray(r.opcoes) && r.opcoes.length) {
            simulacoes[chave] = { estado: r.parcelado ? 'ok' : 'falha', motivo: r.motivo || null, opcoes: r.opcoes };
          } else if (r.http === 422 && r.campo === 'plano') {
            // O servidor não oferece mais a opção 1 neste curso: a página volta para só a taxa.
            bloquearOpcao1(slug, r.planos);
            simulacoes[chave] = { estado: 'falha', motivo: 'plano' };
          } else {
            simulacoes[chave] = { estado: 'falha', motivo: 'falha' };
          }
          atualizar();
        });
      }, 300) };
    }
    function opcaoN(opcoes, n) {
      for (var i = 0; i < (opcoes || []).length; i++) if (opcoes[i].n === n) return opcoes[i];
      return null;
    }
    /* Celular (até 620 px): o seletor fechado corta linhas longas, então o rótulo fica curto ("12× · total R$ 371,07") e a
       linha completa das parcelas aparece logo abaixo (#ck-parcelas-linha). No desktop, a linha completa da spec. */
    var rotuloCurto = !!(window.matchMedia && window.matchMedia('(max-width: 620px)').matches);
    function rotuloParcela(o) {
      if (o.n === 1) return 'À vista · ' + brl(o.total_centavos) + (rotuloCurto ? '' : ' · um pagamento só');
      return rotuloCurto ? o.n + '× · total ' + brl(o.total_centavos)
        : parcelasTexto(o.n, o.parcela_centavos, o.primeira_centavos) + ' · total ' + brl(o.total_centavos);
    }
    /* Preenche o seletor só quando a lista muda, mantendo o N escolhido se ele ainda existir (senão volta a 1). */
    function preencherParcelas(opcoes, forcar) {
      var seletor = q('#ck-parcelas');
      if (!seletor || (opcoesNoSeletor === opcoes && !forcar)) return;
      opcoesNoSeletor = opcoes;
      seletor.innerHTML = opcoes.map(function (o) { return '<option value="' + o.n + '">' + esc(rotuloParcela(o)) + '</option>'; }).join('');
      if (!opcaoN(opcoes, nEscolhido)) nEscolhido = 1;
      seletor.value = String(nEscolhido);
    }
    function bloquearOpcao1(slug, planos) {
      bloqueado[slug] = true;
      if (cursos[slug]) cursos[slug].planos = Array.isArray(planos) && planos.length ? planos : ['so_taxa'];
    }

    // ---------------------------------------------------------------- textos que dependem do curso e do plano
    function fichaDoCurso(c, turma) {
      texto('#ck-r-curso', c ? c.nome : 'Escolha o curso');
      // Spec 1.12: "{carga} · {escolaridade} · Início em 21 de outubro de 2026 · 09:00 - 17:00" ou "… · Turmas em breve".
      texto('#ck-r-meta', c ? [c.carga_horaria, c.escolaridade, turma ? 'Início em ' + turma.data_longa + (turma.horario ? ' · ' + turma.horario : '') : 'Turmas em breve'].filter(Boolean).join(' · ')
        : '7 cursos presenciais no Centro do Rio');
      var meta = mostrar('#ck-curso-meta', !!c);
      if (meta && c) { texto('#ck-m-carga', c.carga_horaria || ''); texto('#ck-m-escolaridade', c.escolaridade ? 'Escolaridade mínima: ' + c.escolaridade.replace(/^Ensino /, '') : ''); }
      var foto = q('#ck-r-foto');
      if (foto && c && c.imagem && foto.getAttribute('src') !== c.imagem) { foto.src = c.imagem; foto.alt = c.nome; }
    }

    /* O Resumo das condições (spec 1.12; sem turma, 10.4): o mesmo texto do e-mail e do comprovante. */
    function resumoCondicoes(slug, c, turma, semTurma) {
      var carga = c && c.carga_horaria ? ' de ' + c.carga_horaria : '';
      // Bombeiro Civil: a frase literal da escola (decisão 12).
      var homologacao = slug === 'bombeiro-civil' ? ' Não inclui a homologação: somente no final do curso, valor a consultar, a cargo do aluno.' : '';
      var desistencia = 'Desistência: até 7 dias depois do pagamento, tudo de volta; depois, ' + regraP4(recebedor().nome) + '.';
      if (semTurma) {
        var limite = dataLimite();
        var ate = limite ? 'até ' + limite : 'em até ' + ((info && info.espera_prazo_dias) || 90) + ' dias da inscrição';
        return 'Inclui: aulas presenciais' + carga + ' na primeira turma deste curso que tiver vaga, na Praça da Cruz Vermelha, 10, e o certificado para quem cumprir os requisitos do curso.' + homologacao
          + ' Ainda não há turma marcada. Quando a Escola marcar uma, você entra nela, por ordem de pagamento, e recebe por e-mail a data, o horário e o local, pelo menos 10 dias antes da primeira aula.'
          + ' Se a data ou o horário não servirem, avise em até 7 dias depois desse e-mail e você recebe tudo de volta.'
          + ' Se você desistir antes de a turma ser confirmada (você recebe um e-mail quando ela for), também recebe tudo de volta.'
          + ' Se não houver turma marcada para começar ' + ate + ', devolvemos tudo, sem você precisar pedir.'
          + ' Se a turma for cancelada ou mudar de data, você escolhe outra turma ou recebe tudo de volta.'
          + ' Se a Escola não puder aceitar a sua matrícula por um requisito que não estava nesta página, devolvemos tudo.'
          + ' "Tudo" é a taxa de inscrição, a matrícula, os juros do parcelamento e os opcionais.'
          + ' No PIX, a devolução feita mais de 90 dias depois do pagamento é uma transferência para uma conta no seu nome. ' + desistencia;
      }
      var naTurma = turma ? 'na turma de ' + turma.data + (turma.horario ? ', das ' + turma.horario.replace(' - ', ' às ') : '') : 'na turma escolhida';
      return 'Inclui: aulas presenciais' + carga + ' ' + naTurma + ', na Praça da Cruz Vermelha, 10, e o certificado para quem cumprir os requisitos do curso.' + homologacao
        + ' A turma precisa de um número mínimo de alunos; se não for formada ou for adiada, você escolhe outra data ou recebe tudo de volta. ' + desistencia;
    }

    /* A caixa da oferta a prazo (CDC, arts. 52 e 54-B; Decreto 5.903, art. 3º; spec 1.12 e 10.4). */
    function ofertaPrazo(o, avista, semTurma) {
      var ag = agente(), p1 = o.primeira_centavos, p = o.parcela_centavos;
      var parcelas = p1 && p1 !== p ? o.n + ' parcelas mensais (1ª de ' + brl(p1) + ' e ' + (o.n - 1) + ' de ' + brl(p) + ')' : o.n + ' parcelas mensais de ' + brl(p);
      return 'Preço à vista ' + esc(brl(avista)) + '. No cartão: ' + esc(parcelas) + ', total ' + esc(brl(o.total_centavos)) + ' (' + esc(brl(o.juros_centavos)) + ' de juros). '
        + 'Juros de ' + esc(o.taxa_mes_rotulo) + ' ao mês. Custo Efetivo Total (CET): ' + esc(o.cet_ano_rotulo) + ' ao ano. '
        + 'Parcelamento concedido por ' + esc(ag.nome) + ', CNPJ ' + esc(ag.cnpj) + ', ' + ENDERECO + ', ' + CONTATO + '. '
        + 'As parcelas vêm na fatura do seu cartão; não cobramos encargos por atraso, e o atraso da fatura segue o contrato do seu cartão. '
        + 'Você pode quitar antes as parcelas que faltam, com redução proporcional dos juros (art. 52, § 2º, do CDC): peça <a href="#chat" data-abrir-chat data-assunto="pagamento" data-curso="' + esc(sel.value) + '">pelo chat</a>. '
        + 'Condições válidas até ' + esc(o.valido_ate_rotulo) + '.'
        + (semTurma ? ' Este curso ainda não tem turma: as parcelas começam na próxima fatura do seu cartão, antes de a turma ser marcada. Se o valor voltar para você, o estorno é do total, com os juros. Como ele aparece na fatura (crédito de uma vez ou fim das parcelas que faltam) depende do banco emissor.' : '');
    }

    function aceiteTexto(c, completo, semTurma) {
      var base = 'Tenho a escolaridade mínima do curso' + (c && c.escolaridade ? ' (' + c.escolaridade + ')' : '');
      if (completo && semTurma) return base + (c && c.requisito_aceite ? c.requisito_aceite : '') + '. Sei que o curso ainda não tem turma: entro na primeira que tiver vaga, por ordem de pagamento, e recebo a data por e-mail.';
      if (completo) return base + '.';
      return base + '. Sei que a participação nas aulas é liberada só com a matrícula paga, além da taxa de inscrição.';
    }

    function atualizar() {
      var slug = sel.value, c = cursos[slug] || null;
      var turma = turmaDe(c);
      var planos = planosDe(slug, c, turma);
      var oferece = planos.indexOf('taxa_e_matricula') >= 0;
      var semTurma = !!(c && oferece && !turma);  // venda sem turma (10.4): a opção 1 entra na fila da próxima turma
      var listaInteresse = !!(c && !turma && info && info.plano_completo_sem_turma && c.na_lista_sem_turma);
      var p1 = q('#ck-plano-completo input'), p2 = q('#ck-plano-taxa input');
      if (temPlanos && p1 && p2) {
        mostrar('#ck-plano-completo', oferece);
        if (!oferece && p1.checked) { p1.checked = false; p2.checked = true; }
        // A opção 1 vem marcada (decisão 2 do dono) quando passa a ser oferecida, a não ser que a pessoa tenha escolhido só a taxa.
        if (oferece && !ofereciaOpcao1 && escolhaManual !== 'so_taxa') p1.checked = true;
        if (!p1.checked && !p2.checked) (oferece ? p1 : p2).checked = true;
        ofereciaOpcao1 = oferece;
        q('#ck-plano-completo').classList.toggle('ativo', p1.checked);
        q('#ck-plano-taxa').classList.toggle('ativo', p2.checked);
      }
      var plano = temPlanos ? planoMarcado() : 'so_taxa';
      var completo = plano === 'taxa_e_matricula';
      var inscricao = inscricaoAtual(), matricula = matriculaDe(c);
      var met = metodo(), cartao = met === 'cartao';
      var taxa = taxaAtual();
      var cobre = q('#ck-cobre').checked;
      var divulgacao = divulgacaoAtual();
      if (divulgacao <= 0) q('#ck-divulgacao').checked = false;
      var ajuda = q('#ck-divulgacao').checked;
      var amount = inscricao + (completo ? matricula : 0) + (cobre ? taxa : 0) + (ajuda ? divulgacao : 0);

      // Parcelas: só a opção 1, no Crédito, com o teto do servidor acima de 1 (E3, 2.4).
      var maxParcelas = info && info.parcelas_max ? info.parcelas_max : 1;
      var querParcelas = temPlanos && !!c && completo && cartao && maxParcelas > 1;
      var sim = null, opcao = null, n = 1;
      if (querParcelas) {
        var chave = chaveSimulacao(slug, cobre, ajuda);
        if (!simulacoes[chave]) pedirParcelas(chave, slug, cobre, ajuda);
        sim = simulacoes[chave];
        if (sim.estado === 'ok') {
          preencherParcelas(sim.opcoes);
          n = nEscolhido;
          opcao = opcaoN(sim.opcoes, n);
          if (!opcao) { n = 1; opcao = opcaoN(sim.opcoes, 1); }
        }
      }
      var parcelado = !!(opcao && n > 1);
      var chaveTotal = [slug, plano, met, cobre ? 1 : 0, ajuda ? 1 : 0, n].join('|');
      var total = parcelado ? opcao.total_centavos : (ajusteTotal && ajusteTotal.chave === chaveTotal ? ajusteTotal.valor : amount);
      atual = { slug: slug, plano: plano, completo: completo, n: n, total: total, turma: turma, semTurma: semTurma };

      fichaDoCurso(c, turma);
      // Topo (1.12): o nome do curso e a turma, ou "Ainda não há turma aberta."
      topo(c ? c.nome : 'Inscrição', c ? (turma ? turma.lead : 'Ainda não há turma aberta.') : 'Escolha o curso e veja as turmas abertas, os horários e os valores.');
      rotularPassos(completo ? (semTurma ? 'semTurma' : 'completo') : 'taxa');

      // Planos: valores e legendas (1.12 e 10.4).
      texto('#ck-plano-completo-valor', c && matricula > 0 ? ' — ' + brl(inscricao + matricula) : '');
      texto('#ck-plano-taxa-valor', brl(inscricao));
      var depoisCompleto = semTurma ? 'Você entra na primeira turma deste curso que tiver vaga, por ordem de pagamento, e recebe a data por e-mail.' : 'A entrada na aula é liberada com a matrícula paga.';
      texto('#ck-plano-completo-legenda', (parcelado ? n + ' parcelas mensais no cartão, total ' + brl(total) + ' com juros. ' : 'um pagamento só. ') + depoisCompleto);
      texto('#ck-plano-taxa-legenda', !c ? 'Quem pagou apenas a inscrição não tem a entrada liberada.'
        : turma ? 'Quem pagou apenas a inscrição não tem a entrada liberada. A matrícula (' + brl(matricula) + ' à vista) é paga antes da aula, com a secretaria da Escola, que escreve para você por e-mail.'
        : listaInteresse ? 'Você entra na lista de interesse da próxima turma. Quando a turma for marcada, a secretaria avisa por e-mail, e o lugar fica com quem pagar a matrícula enquanto houver vaga. Quem já pagou tudo entra primeiro.'
        : 'Você entra na lista da próxima turma. A matrícula fica para quando a turma abrir.');

      // Forma de pagamento: aviso do PIX, cartão e parcelas.
      mostrar('#ck-pix-aviso', !cartao);
      var boxCartao = mostrar('#ck-cartao', cartao);
      if (boxCartao) form.querySelectorAll('#ck-cartao input').forEach(function (i) { i.disabled = !cartao; });
      form.querySelectorAll('.ck-metodo').forEach(function (m) { m.classList.toggle('ativo', m.querySelector('input').checked); });
      var semParcelado = sim && sim.estado === 'falha' && (sim.motivo === 'desligado' || sim.motivo === 'plano');
      mostrar('#ck-parcelas-box', querParcelas && !semParcelado);
      mostrar('#ck-parcelas-campo', !!(sim && sim.estado === 'ok'));
      var linhaParcelas = !!(rotuloCurto && opcao);
      mostrar('#ck-parcelas-linha', linhaParcelas);
      if (linhaParcelas) texto('#ck-parcelas-linha', opcao.n === 1 ? 'À vista · ' + brl(opcao.total_centavos) + ' · um pagamento só' : parcelasTexto(opcao.n, opcao.parcela_centavos, opcao.primeira_centavos) + ' · total ' + brl(opcao.total_centavos));
      texto('#ck-parcelas-nota', !sim ? '' : sim.estado === 'carregando' ? 'Calculando as parcelas…' : sim.estado === 'falha' ? 'No momento, o cartão está só à vista.' : '');
      mostrar('#ck-parcelas-nota', !!(sim && sim.estado !== 'ok'));
      mostrar('#ck-juros-conta', parcelado);
      if (parcelado) {
        var avista = (opcaoN(sim.opcoes, 1) || {}).total_centavos || amount;
        texto('#ck-juros-rotulo', 'Juros do parcelamento (acréscimo de ' + opcao.acrescimo_rotulo + ' sobre o valor à vista)');
        texto('#ck-juros-valor', '+ ' + brl(opcao.juros_centavos));
        texto('#ck-juros-total', brl(opcao.total_centavos));
        texto('#ck-juros-nota', 'Inclui ' + opcao.acrescimo_rotulo + ' de acréscimo. Os juros incidem sobre o total, inclusive os opcionais marcados.');
        conteudo('#ck-oferta-prazo', ofertaPrazo(opcao, avista, semTurma));
        texto('#ck-vocepaga-valor', parcelasTexto(n, opcao.parcela_centavos, opcao.primeira_centavos));
      }

      // Opcionais (E4: os custos são 5% só da taxa, nos dois planos).
      q('#ck-cobre-opcao').classList.toggle('marcado', cobre);
      texto('#ck-cobre-legenda', completo ? 'Opcional. Cobre os custos de processamento da taxa de inscrição.' : 'Opcional. Assim a Cruz Vermelha recebe a taxa de inscrição inteira.');
      q('#ck-divulgacao-opcao').hidden = divulgacao <= 0;
      q('#ck-divulgacao-opcao').classList.toggle('marcado', ajuda);
      texto('#ck-divulgacao-valor', '+ ' + brl(divulgacao));
      texto('#ck-taxa-valor', '+ ' + brl(taxa));

      // Aceite (1.12 e 10.4). No HTML antigo, a escolaridade entra no texto antigo, como antes.
      if (!texto('#ck-requisitos-texto', aceiteTexto(c, completo, semTurma))) texto('#ck-escolaridade', c && c.escolaridade ? 'escolaridade mínima: ' + c.escolaridade : 'escolaridade mínima');

      // Resumo das condições, só na opção 1, logo antes da barra.
      mostrar('#ck-condicoes', !!(c && completo));
      if (c && completo) texto('#ck-condicoes-texto', resumoCondicoes(slug, c, turma, semTurma));

      // Barra de ação e botão (1.12): "Total" na opção 1, "Agora" na 2; no parcelado, o total com juros e "Pagar N× de…".
      texto('#ck-acao-rotulo', completo ? 'Total' : 'Agora');
      texto('#ck-total-btn', brl(total));
      // Parcelas iguais: "Pagar 12× de R$ X" (1.12). Com a 1ª parcela diferente, o botão diz o total, para a conta fechar.
      var parcelasIguais = parcelado && (!opcao.primeira_centavos || opcao.primeira_centavos === opcao.parcela_centavos);
      texto('#ck-pagar-texto', !cartao ? 'Gerar QR Code' : parcelado ? (parcelasIguais ? 'Pagar ' + n + '× de ' + brl(opcao.parcela_centavos) : 'Pagar ' + brl(total) + ' em ' + n + '×') : 'Pagar ' + brl(total));

      // Resumo lateral (1.12).
      mostrar('#ck-r-matricula-linha', completo);
      texto('#ck-r-matricula', brl(matricula));
      texto('#ck-r-inscricao-rotulo', completo ? 'Taxa de inscrição' : 'Taxa de inscrição (agora)');
      texto('#ck-r-inscricao', brl(inscricao));
      mostrar('#ck-r-taxa-linha', cobre);
      texto('#ck-r-taxa', brl(taxa));
      mostrar('#ck-r-divulgacao-linha', ajuda);
      texto('#ck-r-divulgacao', brl(divulgacao));
      mostrar('#ck-r-juros-linha', parcelado);
      if (parcelado) { texto('#ck-r-juros-rotulo', 'Juros do parcelamento (' + opcao.acrescimo_rotulo + ')'); texto('#ck-r-juros', brl(opcao.juros_centavos)); }
      texto('#ck-r-total-rotulo', completo ? 'Total' : 'Agora');
      texto('#ck-r-total', brl(total));
      mostrar('#ck-r-so-taxa', !!(c && !completo && matricula > 0));
      texto('#ck-r-curso-valor', matricula > 0 ? brl(matricula) : '—');
      texto('#ck-r-inscricao-avista', brl(inscricao));
      texto('#ck-r-total-curso', matricula > 0 ? brl(matricula + inscricao) : '—');
      mostrar('#ck-r-homologacao', slug === 'bombeiro-civil');
      // "O que acontece depois", itens 2 e 3 (1.12 e 10.4).
      var item2 = !c || turma ? PASSO_03
        : completo ? 'Quando a Escola marcar a turma, você entra na primeira que tiver vaga, já com tudo pago, e recebe a data por e-mail. Se a data ou o horário não servirem, você recebe tudo de volta.'
        : listaInteresse ? 'Quando a turma for marcada, a secretaria avisa por e-mail e diz como pagar a matrícula. O lugar fica com quem pagar primeiro, enquanto houver vaga.'
        : 'Quando a data sair, a secretaria coloca você na turma e avisa por e-mail.';
      texto('#ck-depois-turma span', item2);
      var item3 = turma && turma.prazo ? 'Pague até ' + turma.prazo + ', para entrar na turma de ' + turma.dia + '.'
        : c && completo && semTurma ? 'Se não houver turma marcada para começar ' + (dataLimite() ? 'até ' + dataLimite() : 'em até ' + ((info && info.espera_prazo_dias) || 90) + ' dias da inscrição') + ', devolvemos tudo o que você pagou.' : '';
      if (mostrar('#ck-depois-prazo', !!item3)) texto('#ck-depois-prazo span', item3);

      // Selo e linha do fornecedor (1.12): "à vista ou parcelado" só com o parcelado ligado no servidor.
      texto('#ck-selo-pagamento', maxParcelas > 1 ? 'PIX ou cartão, à vista ou parcelado' : 'PIX ou cartão, à vista');
      var rec = recebedor(), ag = agente();
      var forn = q('#ck-fornecedor');
      if (forn) forn.innerHTML = esc((maxParcelas > 1 && ag.cnpj === rec.cnpj ? 'Venda, recebimento e parcelamento: ' : 'Venda e recebimento: ') + rec.nome + ', CNPJ ')
        + '<span class="ck-nowrap">' + esc(rec.cnpj) + '</span>' + esc(' · ' + ENDERECO + ' · ' + CONTATO + '.');
      // Nota legal e fornecedor: os novos só com o plano completo ligado no servidor; desligado (passo 3, volta atrás),
      // os de hoje, iguais a /reembolso/ e /termos/ do ar. Antes do info.php, fica o que veio no HTML.
      if (info && q('#ck-legal-novo') && q('#ck-legal-hoje')) {
        mostrar('#ck-legal-novo', !!info.plano_completo);
        mostrar('#ck-legal-hoje', !info.plano_completo);
      }
      marcarInicio();
      ajustarResumo();
    }

    /* O resumo lateral preso (desktop) não pode passar da altura da tela: o Total ficaria cortado ou embaixo do botão do
       chat (72 px de folga). Se não couber, ele solta e rola com a página. */
    function ajustarResumo() {
      var a = q('.ck-resumo');
      if (!a || !window.getComputedStyle) return;
      a.classList.remove('ck-resumo-solto');
      var cs = getComputedStyle(a);
      if (cs.position === 'sticky' && a.offsetHeight + (parseFloat(cs.top) || 0) + 72 > window.innerHeight) a.classList.add('ck-resumo-solto');
    }
    window.addEventListener('resize', function () {
      var curto = !!(window.matchMedia && window.matchMedia('(max-width: 620px)').matches);
      if (curto !== rotuloCurto) { rotuloCurto = curto; if (opcoesNoSeletor) preencherParcelas(opcoesNoSeletor, true); atualizar(); return; }
      ajustarResumo();
    });

    function erro(mensagem, campo, extraHtml) {
      form.querySelectorAll('.ck-campo.erro').forEach(function (c) { c.classList.remove('erro'); });
      erroEl.textContent = mensagem || '';
      if (!mensagem) return;
      if (extraHtml) erroEl.insertAdjacentHTML('beforeend', extraHtml);
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
        // O valor mostrado vai junto: o servidor só cobra a contribuição se for o valor de agora.
        ajuda_divulgacao: q('#ck-divulgacao').checked, divulgacao_centavos: divulgacaoAtual(),
        requisitos: q('#ck-requisitos').checked, site: q('#ck-site').value, origem: origemAtual
      };
      // Pagar tudo: o plano sai só do input marcado. Sem ele (HTML antigo em cache), nada disso vai e o servidor cobra só
      // a taxa, como antes (T9).
      var marcado = form.querySelector('input[name=plano]:checked');
      if (marcado) {
        dados.plano = marcado.value;
        if (dados.metodo === 'cartao' && marcado.value === 'taxa_e_matricula') dados.parcelas = atual.n || 1;
        dados.total_mostrado_centavos = atual.total;
        // A turma que a página mostrou, sempre na opção 1 (vazia = curso sem turma): se mudou, o servidor pede para conferir.
        if (marcado.value === 'taxa_e_matricula') dados.turma_id = atual.turma && atual.turma.id_escola ? atual.turma.id_escola : '';
      }
      // Prova do aceite (F12): a versão do texto (gerada com a página) e o texto exato que a pessoa marcou.
      var aceite = q('#ck-requisitos-texto'), versao = q('#ck-requisitos').getAttribute('data-aceite-versao');
      if (aceite && versao) { dados.aceite_versao = versao; dados.aceite_texto = aceite.textContent.trim(); }
      if (dados.metodo === 'cartao') {
        dados.cartao = { numero: digitos(q('#ck-cartao-numero').value), nome: q('#ck-cartao-nome').value.trim(), validade: q('#ck-cartao-validade').value, cvv: q('#ck-cartao-cvv').value };
      }
      return dados;
    }

    /* Os 422 do pagar tudo (spec 1.12): nada foi cobrado; a tela se ajusta ao que o servidor disse e mostra a mensagem. */
    function tratarRecusa(r, dados) {
      var slug = dados.curso, chave = chaveSimulacao(slug, dados.cobre_taxa, dados.ajuda_divulgacao);
      if (r.campo === 'divulgacao' && typeof r.divulgacao_centavos === 'number') {
        // A contribuição mudou de valor (ou saiu) depois que a página abriu: mostra o valor novo antes da mensagem.
        if (info) info.divulgacao_centavos = r.divulgacao_centavos;
        else q('#ck-divulgacao').setAttribute('data-centavos', String(r.divulgacao_centavos));
      } else if (r.campo === 'plano' && (r.motivo === 'prazo_fila' || r.motivo === 'condicoes')) {
        // A turma fechou num curso que ainda vende tudo sem turma, ou a turma mudou: a opção 1 continua, com as condições
        // novas (sem turma: fila, data limite, aceite e Resumo da 10.4). Recarrega os planos e pede o aceite de novo.
        var aceite = q('#ck-requisitos');
        if (aceite) aceite.checked = false;
        api('info.php').then(function (d) {
          if (d && d.ok && Array.isArray(d.cursos)) { info = d; d.cursos.forEach(function (c) { cursos[c.slug] = Object.assign(cursos[c.slug] || {}, c); }); }
          atualizar();
        });
      } else if (r.campo === 'plano') {
        // As inscrições fecharam, a turma lotou ou a opção 1 foi desligada: esconde a opção 1 e marca a 2.
        bloquearOpcao1(slug, r.planos);
      } else if (r.campo === 'parcelas' && Array.isArray(r.opcoes)) {
        simulacoes[chave] = { estado: r.opcoes.length > 1 ? 'ok' : 'falha', motivo: r.opcoes.length > 1 ? null : 'falha', opcoes: r.opcoes };
      } else if (r.campo === 'total') {
        if ((dados.parcelas || 1) > 1 && Array.isArray(r.opcoes) && r.opcoes.length > 1) {
          simulacoes[chave] = { estado: 'ok', opcoes: r.opcoes };
        } else if (typeof r.total_centavos === 'number') {
          // À vista: a barra passa a mostrar o valor do servidor (no modo de teste, o preço de teste do CPF da lista).
          ajusteTotal = { chave: [slug, dados.plano || 'so_taxa', dados.metodo, dados.cobre_taxa ? 1 : 0, dados.ajuda_divulgacao ? 1 : 0, dados.parcelas || 1].join('|'), valor: r.total_centavos };
        }
      }
      atualizar();
      if (r.campo === 'ja_pago') {
        erro(r.erro || 'Você já pagou este curso. Não pague de novo.', null, '<div>' + botaoChat('Falar com a secretaria', 'pagamento', slug) + '</div>');
        return;
      }
      erro(r.erro || 'Não foi possível processar. Tente novamente.', ['plano', 'parcelas', 'total', 'indisponivel'].indexOf(r.campo) >= 0 ? null : r.campo);
    }

    function enviar(e) {
      e.preventDefault();
      erro('');
      atualizar(); // o prazo da turma e o total mostrado conferidos na hora de pagar
      var dados = lerFormulario();
      if (validar(dados) !== true) return;
      var rotulo = btn.innerHTML;
      btn.disabled = true;
      btn.textContent = dados.metodo === 'pix' ? 'Gerando o QR Code…' : 'Processando…';
      var c = cursos[dados.curso] || {};
      var nomeCurso = c.nome || dados.curso, plano = dados.plano || 'so_taxa', inscricao = inscricaoAtual();
      var matricula = plano === 'taxa_e_matricula' ? matriculaDe(c) : 0;
      // O id do Lead vai junto com a inscrição: o servidor manda o mesmo Lead à API de Conversões.
      dados.evento_id = novoIdEvento('lead') || undefined;
      rastrear('Lead', { content_name: nomeCurso, content_ids: [dados.curso], content_category: categoria(plano), value: (inscricao + matricula) / 100, currency: 'BRL' },
        'generate_lead', { currency: 'BRL', value: (inscricao + matricula) / 100, curso: dados.curso, metodo: dados.metodo, plano: plano }, { eventID: dados.evento_id });
      var turmaVista = atual.turma;
      api('pagamentos.php', { method: 'POST', body: JSON.stringify(dados) }).then(function (r) {
        dados.cartao = null;
        if (!r.ok) {
          btn.disabled = false;
          btn.innerHTML = rotulo;
          tratarRecusa(r, dados);
          return;
        }
        // Dados de pagamento aceitos pelo provedor (PIX gerado ou cartão enviado): mesmo evento nos dois métodos. O valor
        // é o total sem juros (E9), com o plano e as parcelas.
        var planoR = r.plano || plano, parcelasR = r.parcelas || 1;
        rastrear('AddPaymentInfo', { content_name: nomeCurso, content_ids: [dados.curso], content_type: 'product', value: r.total_centavos / 100, currency: 'BRL', content_category: categoria(planoR), plano: planoR, parcelas: parcelasR },
          'add_payment_info', Object.assign({ payment_type: dados.metodo, plano: planoR, parcelas: parcelasR }, itemGa(dados.curso, nomeCurso, planoR, r.inscricao_centavos || inscricao, r.matricula_centavos || 0, r.total_centavos)), { eventID: r.id_compra + '-pagamento' });
        if (r.status === 'pago') { location.href = r.urls.parabens; return; }
        if (r.metodo !== 'pix') { location.href = r.urls.pendente; return; } // cartão em análise
        form.hidden = true;
        var painel = q('#ck-pix');
        painel.hidden = false;
        painelPix(r, painel, turmaVista);
        acompanhar(r.token, function (d) { avancarEtapas(painel, function () { location.href = d.urls.parabens; }); }, function (d) { location.href = d.urls.pendente; });
        window.scrollTo({ top: painel.getBoundingClientRect().top + window.scrollY - 90, behavior: 'smooth' });
      });
    }

    aplicarMascara(q('#ck-cpf'), mascaras.cpf);
    aplicarMascara(q('#ck-telefone'), mascaras.telefone);
    aplicarMascara(q('#ck-cartao-numero'), mascaras.cartaoNumero);
    aplicarMascara(q('#ck-cartao-validade'), mascaras.validade);
    aplicarMascara(q('#ck-cartao-cvv'), mascaras.cvv);
    form.addEventListener('change', function (e) {
      var alvo = e.target || {};
      if (alvo.name === 'plano') escolhaManual = alvo.value;
      if (alvo.id === 'ck-parcelas') nEscolhido = parseInt(alvo.value, 10) || 1;
      if (alvo.id !== 'ck-requisitos') erro('');
      atualizar();
    });
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
      if (pedido && cursos[pedido] && !sel.value) sel.value = pedido;
      atualizar();
    });
  }

  // ------------------------------------------------------------------ tela: pendente
  function telaPendente() {
    var token = param('t'), card = q('#pd-card');
    if (!TOKEN.test(token)) { card.innerHTML = voltarCursos('Link inválido.'); return; }

    /* Recusado, PIX vencido, estornado (spec 1.13) e o PIX da opção 1 com a turma fechada. */
    function falhou(d) {
      var voltar = URL_CHECKOUT + '?curso=' + encodeURIComponent(d.curso.slug);
      if (d.status === 'pendente' && (d.pix_turma_fechada || d.pix_ja_pago)) { pixTurmaFechada(d, card); return; }
      var textos = {
        expirado: ['Este código PIX venceu', 'Nenhum valor foi cobrado. Gere um novo código para continuar a inscrição em <b>' + esc(d.curso.nome) + '</b>.', 'Gerar novo PIX', 'btn-red'],
        recusado: ['O pagamento não passou', 'O banco recusou a cobrança. Tente outro cartão. Nenhum valor foi cobrado.', 'Tentar de novo', 'btn-red'],
        estornado: ['Valor devolvido', 'Esta inscrição foi estornada. Se quiser fazer a inscrição de novo, use o botão abaixo.', 'Nova inscrição', 'btn-outline']
      };
      var t = textos[d.status] || textos.estornado;
      topo(t[0], '');
      card.innerHTML = '<p class="lead" style="font-size:1rem;margin-top:0">' + t[1] + '</p><div class="cta-row"><a class="btn ' + t[3] + '" href="' + voltar + '">' + t[2] + '</a></div>';
    }

    /* Cartão em análise (1.13): o valor e as parcelas, com os textos da tela de cartão da escola. */
    function cartaoEmAnalise(d) {
      var completo = d.plano === 'taxa_e_matricula', n = d.parcelas || 1, valor = d.total_cobrado_centavos || d.total_centavos;
      var p = d.parcela_centavos, p1 = d.primeira_parcela_centavos;
      var linha = n > 1 ? (p1 && p1 !== p ? n + '× no crédito: 1ª de ' + brl(p1) + ' e ' + (n - 1) + ' de ' + brl(p) + '.' : n + '× de ' + brl(p) + ' no crédito.') : 'Um pagamento único no crédito.';
      card.innerHTML = '<div class="ck-pix"><p class="ck-pix-valor"><span>' + (completo ? 'Total' : 'Taxa de inscrição') + '</span><strong>' + brl(valor) + '</strong></p>'
        + '<p>' + esc(linha) + '</p><div class="ck-status" role="status"><span class="pulso" aria-hidden="true"></span> Verificando automaticamente…</div></div>';
    }

    api('status.php?t=' + encodeURIComponent(token)).then(function (d) {
      if (!d.ok) { card.innerHTML = voltarCursos(d.erro || 'Inscrição não encontrada.'); return; }
      mostrarAvisoTeste(d);
      rotularPassos(d.plano === 'taxa_e_matricula' ? (d.sem_turma ? 'semTurma' : 'completo') : 'taxa');
      if (d.status === 'pago') { location.replace(d.urls.parabens); return; }
      if (d.status !== 'pendente' || d.pix_turma_fechada || d.pix_ja_pago) { falhou(d); return; }
      if (d.metodo === 'pix') painelPix(d, card);
      else cartaoEmAnalise(d);
      acompanhar(token, function (x) { avancarEtapas(card, function () { location.href = x.urls.parabens; }); }, falhou);
    });
  }

  // ------------------------------------------------------------------ tela: parabéns
  function telaParabens() {
    var token = param('t'), card = q('#pb-card');
    if (!TOKEN.test(token)) { card.innerHTML = voltarCursos('Link inválido.'); return; }
    var esperas = 0;          // novas consultas enquanto a escola registra (até ~5 minutos)
    var listaInteresse = null; // só a taxa sem turma: o curso está na venda sem turma? (info.php; F5)

    /* O parágrafo de acesso à área do aluno (conta nova com link, conta nova sem link ou conta existente) e os botões. */
    function acesso(d) {
      var e = d.escola || {};
      if (!(e.status === 'ok' && e.resultado)) return null;
      var link = e.aluno_novo ? urlSegura(e.link) : '';
      var textoAcesso = e.aluno_novo
        ? (link
          ? 'Sua conta na área do aluno foi criada com o seu CPF. Para entrar pela primeira vez, <b>crie sua senha no botão abaixo</b>' + (e.link_validade ? ' (o link vale até ' + esc(e.link_validade) + ')' : '') + '. Depois é só entrar com seu <b>CPF</b> ou e-mail e a senha que você criou.'
          : 'Sua conta na área do aluno foi criada com o seu CPF. Para entrar pela primeira vez, use <b>"Esqueci minha senha"</b> na tela de entrada, com o e-mail <b>' + esc(d.email) + '</b>: você recebe um link para criar sua senha.')
        : 'Você já tinha conta na área do aluno' + (!e.email_confere && e.email_conta ? ', com o e-mail <b>' + esc(e.email_conta) + '</b>' : '')
          + '. Entre com seu <b>CPF</b> ou e-mail e a senha de sempre. Esqueceu? Use "Esqueci minha senha" na tela de entrada.';
      return { html: '<div class="ck-acesso"><div>' + textoAcesso + '</div></div>', link: link, url: urlSegura(e.url) || urlSegura(d.escola_url) };
    }
    /* Botões das variantes pagas (1.13): "Criar minha senha" (com o link) e "Ver minhas inscrições". */
    function botoes(ac, d, extra) {
      var url = ac ? ac.url : urlSegura(d.escola_url);
      var html = ac && ac.link
        ? '<a class="btn btn-red" href="' + esc(ac.link) + '">Criar minha senha</a><a class="btn btn-outline" href="' + esc(url) + '">Ver minhas inscrições</a>'
        : (url ? '<a class="btn btn-red" href="' + esc(url) + '">Ver minhas inscrições</a>' : '');
      return '<div class="cta-row">' + html + (extra || '') + '</div>';
    }
    function linhaTurma(t) {
      if (!t || !t.data_longa) return '';
      return '<p class="ck-turma-linha">Início em ' + esc(t.data_longa) + (t.horario ? ' · ' + esc(t.horario) : '') + ' · Praça da Cruz Vermelha, 10 · Centro</p>';
    }
    function matriculaAVista(d) { return d.matricula_preco_centavos ? brl(d.matricula_preco_centavos) + ' à vista' : 'à vista'; }
    /* Quanto da taxa paga em dobro volta, com os juros proporcionais: taxa × total cobrado ÷ amount (spec 2.6). */
    function notaTaxaEmDobro(d) {
      var amount = d.total_centavos || 0, cobrado = d.total_cobrado_centavos || amount;
      var valor = amount > 0 ? Math.round((d.inscricao_centavos || 0) * cobrado / amount) : (d.inscricao_centavos || 0);
      return '<p class="ck-rosa">Você já tinha pago a taxa de inscrição deste curso. Os ' + brl(valor) + ' pagos a mais, com os juros do parcelamento correspondentes, voltam para você: a secretaria avisa por e-mail quando a devolução for feita.</p>';
    }
    function processando(completo) {
      return { h1: 'Pagamento confirmado', esperar: true, html: '<div class="ck-bloco"><h2>' + (completo ? 'Estamos registrando sua matrícula.' : 'Estamos registrando sua inscrição.') + '</h2>'
        + '<p>Estamos criando o seu acesso à área do aluno. Ele aparece aqui e chega no seu e-mail em instantes.</p>'
        + '<div class="ck-status" role="status"><span class="pulso" aria-hidden="true"></span> Registrando…</div></div>' };
    }

    /* Opção 1: "Taxa de inscrição + matrícula" (spec 1.13 e 10.5). */
    function varianteCompleta(d) {
      var e = d.escola || {}, av = e.avisos || [], esp = d.espera || null, slug = d.curso.slug, nome = esc(d.curso.nome);
      var ac = acesso(d), total = brl(d.total_cobrado_centavos || d.total_centavos);
      var limite = (esp && esp.data_limite) || d.data_limite || '';
      var ateLimite = limite ? 'até ' + esc(limite) : 'no prazo da sua inscrição';
      var chat = botaoChat('Falar com a secretaria', 'matricula', slug);
      var emInstantes = '<div class="ck-acesso"><div>O acesso à área do aluno chega no seu e-mail em instantes.</div></div>';
      if (esp && esp.status === 'devolver') {
        return { h1: 'Devolução em andamento', html: '<div class="ck-bloco"><p>' + (d.metodo === 'pix'
          ? 'Vamos devolver os ' + total + ' que você pagou em ' + nome + ', por PIX, em até 2 dias úteis.'
          : 'Vamos pedir o estorno dos ' + total + ' que você pagou em ' + nome + ' em até 2 dias úteis. Como ele aparece na fatura depende do banco emissor.') + '</p>'
          + '<div class="cta-row">' + botaoChat('Falar com a secretaria', 'pagamento', slug, 'btn-red') + '</div></div>' };
      }
      if (esp && esp.status === 'turma') {
        return { h1: 'Matrícula confirmada', html: '<div class="ck-bloco"><p class="ck-pilula">' + ICONE_OK + ' Taxa de inscrição confirmada</p>' + linhaTurma(esp.turma || d.turma)
          + (ac ? ac.html : '') + '<p>' + PASSO_03 + '</p>'
          + (esp.janela_ate ? '<p>Se a data ou o horário não servirem, avise até ' + esc(esp.janela_ate) + ', e você recebe tudo de volta.</p>' : '')
          + botoes(ac, d) + '<p class="ck-nota" style="margin-top:12px">' + NOTA_ESTORNO + '</p></div>' };
      }
      if ((esp && esp.status === 'aguardando') || av.indexOf('matricula_paga_sem_turma') >= 0) {
        var aguardar = !ac && e.status !== 'ok';
        if (d.turma && d.turma.data) {
          // A turma vendida fechou antes de o pagamento cair (PIX que caiu após o fim das inscrições): a compra vai para a fila.
          return { h1: 'Pagamento confirmado', esperar: aguardar, html: '<div class="ck-bloco"><p>Recebemos o pagamento da inscrição e da matrícula em ' + nome + ', mas as inscrições da turma de ' + esc(d.turma.data)
            + ' fecharam antes da confirmação do seu pagamento. Você fica na fila da próxima turma, com tudo pago: quando ela for marcada, você entra na primeira que tiver vaga e recebe a data por e-mail. '
            + 'Se preferir, peça pelo chat a devolução de tudo o que pagou. Se não houver turma marcada para começar ' + ateLimite + ', devolvemos tudo, sem você precisar pedir.</p>'
            + (ac ? ac.html : emInstantes) + '<div class="cta-row">' + (ac && ac.link ? '<a class="btn btn-red" href="' + esc(ac.link) + '">Criar minha senha</a>' : '') + chat + '</div></div>' };
        }
        return { h1: 'Inscrição e matrícula pagas', esperar: aguardar, html: '<div class="ck-bloco"><p class="ck-pilula breve">Turmas em breve</p>'
          + '<p>Recebemos o pagamento da inscrição e da matrícula em ' + nome + '. Ainda não há turma marcada: quando a Escola marcar uma, você entra na primeira que tiver vaga, por ordem de pagamento, e recebe por e-mail a data, o horário e o local.</p>'
          + '<p>Se a data ou o horário não servirem, avise em até 7 dias depois desse e-mail e você recebe tudo de volta. Se não houver turma marcada para começar ' + ateLimite + ', devolvemos tudo, sem você precisar pedir.</p>'
          + (ac ? ac.html : emInstantes)
          + '<p class="ck-nota">Na área do aluno, este curso só aparece quando a turma abrir. Não pague de novo e não se inscreva pela Escola: você entra sozinho na turma.</p>'
          + '<div class="cta-row">' + (ac && ac.link ? '<a class="btn btn-red" href="' + esc(ac.link) + '">Criar minha senha</a>' : '') + chat + '</div></div>' };
      }
      if (e.configurada && (e.status === 'pendente' || (e.status === 'erro' && !e.esgotado))) return processando(true);
      var dobro = av.indexOf('taxa_em_dobro') >= 0 ? notaTaxaEmDobro(d) : '';
      if (av.indexOf('curso_ja_pago') >= 0) {
        return { h1: 'Sua matrícula já estava paga', html: '<div class="ck-bloco"><p>Sua matrícula em ' + nome + ' já estava paga na área do aluno. Este pagamento será devolvido por inteiro: a secretaria avisa por e-mail quando a devolução for feita. Não pague de novo.</p>'
          + botoes(ac ? { url: ac.url } : null, d) + '</div>' };
      }
      if (av.indexOf('turma_diferente') >= 0) {
        var anunciada = d.turma_vendida && d.turma_vendida.data ? 'A turma de ' + esc(d.turma_vendida.data) : 'A turma que você escolheu';
        var daEscola = d.turma && d.turma.data ? esc(d.turma.data) : dataBr(e.turma && (e.turma.primeira_aula || e.turma.inicio));
        return { h1: 'Pagamento confirmado', html: '<div class="ck-bloco"><p>Pagamento confirmado. ' + anunciada + ' lotou ou fechou antes da confirmação do seu pagamento, e reservamos para você a turma de ' + (daEscola || 'outra data')
          + '. Se a data não servir, peça pelo chat a devolução de tudo o que pagou.</p>' + linhaTurma(d.turma) + dobro + (ac ? ac.html : '') + botoes(ac, d, chat)
          + '<p class="ck-nota" style="margin-top:12px">' + NOTA_ESTORNO + '</p></div>' };
      }
      if (av.indexOf('matricula_nao_marcada') >= 0 || e.matricula_paga !== true || e.status !== 'ok') {
        return { h1: 'Pagamento confirmado', html: '<div class="ck-bloco"><p>Recebemos o pagamento da inscrição e da matrícula em ' + nome + '. A secretaria está concluindo o registro da sua matrícula na área do aluno e confirma por e-mail. Se a área do aluno mostrar a matrícula como pendente, não pague de novo: ela já está paga.</p>'
          + dobro + (ac ? ac.html : '') + botoes(ac, d) + '</div>' };
      }
      // turma_lotada (P3, decisão 14): quem pagou tudo entra acima da vaga e lê "Matrícula confirmada", sem nota.
      return { h1: 'Matrícula confirmada', html: '<div class="ck-bloco"><p class="ck-pilula">' + ICONE_OK + ' Taxa de inscrição confirmada</p>' + linhaTurma(d.turma)
        + (ac ? ac.html : '') + '<p>' + PASSO_03 + '</p>' + dobro + botoes(ac, d)
        + '<p class="ck-nota" style="margin-top:12px">' + NOTA_ESTORNO + '</p></div>' };
    }

    /* Opção 2: "Só a taxa de inscrição" (spec 1.13). */
    function varianteTaxa(d) {
      var e = d.escola || {}, av = e.avisos || [], slug = d.curso.slug, nome = esc(d.curso.nome);
      var ac = acesso(d);
      if (e.configurada && (e.status === 'pendente' || (e.status === 'erro' && !e.esgotado))) return processando(false);
      if (!ac) {
        // Escola não configurada ou em erro definitivo: a secretaria conclui a inscrição.
        return { h1: 'Taxa de inscrição paga. A secretaria vai concluir sua inscrição.', html: '<div class="ck-bloco"><h2>A secretaria vai concluir sua inscrição</h2>'
          + '<p>A secretaria conclui a sua inscrição na área do aluno e avisa por e-mail, com o jeito de pagar a matrícula (' + esc(matriculaAVista(d)) + '). <b>Não se inscreva de novo pela área do aluno: sua taxa já está paga.</b></p>'
          + '<p class="ck-nota">' + NOTA_ESTORNO + '</p>'
          + '<p class="ck-nota">Alguma dúvida enquanto espera? <a href="#chat" data-abrir-chat data-assunto="matricula" data-curso="' + esc(slug) + '">Fale com a gente pelo chat</a>.</p>'
          + (urlSegura(d.escola_url) ? '<div class="cta-row"><a class="btn btn-outline" href="' + esc(d.escola_url) + '" target="_blank" rel="noopener">Conhecer a área do aluno</a></div>' : '') + '</div>' };
      }
      if (av.indexOf('taxa_ja_confirmada') >= 0) {
        return { h1: 'Você já tinha pago a inscrição deste curso', html: '<div class="ck-bloco"><p>Este pagamento de ' + brl(d.total_centavos) + ' será devolvido por inteiro. Não pague de novo.</p>' + botoes({ url: ac.url }, d) + '</div>' };
      }
      if (e.resultado === 'sem_turma') {
        if (listaInteresse === null) buscarListaInteresse(d);
        var entrar = esc(ac.url);
        return { h1: 'Taxa de inscrição paga. Você está na lista da próxima turma.', html: '<div class="ck-bloco"><p>' + (listaInteresse
          ? 'Ainda não há data para este curso. Quando a turma for marcada, a secretaria avisa por e-mail e diz como pagar a matrícula (' + esc(matriculaAVista(d)) + '). O lugar fica com quem pagar primeiro, enquanto houver vaga. Quem já pagou tudo entra primeiro.'
          : 'Ainda não há data para este curso. Quando a turma abrir, a secretaria coloca você nela, avisa por e-mail e orienta o pagamento da matrícula (' + esc(matriculaAVista(d)) + ').')
          + ' <b>Não se inscreva de novo pela área do aluno: sua taxa já está paga.</b></p>' + ac.html
          + '<div class="cta-row">' + (ac.link ? '<a class="btn btn-red" href="' + esc(ac.link) + '">Criar minha senha</a><a class="btn btn-outline" href="' + entrar + '">Já criei: entrar</a>'
            : '<a class="btn btn-red" href="' + entrar + '">Entrar na área do aluno</a>') + '</div>'
          + '<p class="ck-nota" style="margin-top:12px">' + NOTA_ESTORNO + ' Até a turma ser confirmada, você também pode pedir a taxa inteira de volta, pelo chat.</p></div>' };
      }
      var data = dataBr(e.turma_inicio);
      return { h1: 'Taxa de inscrição confirmada. Falta só o pagamento da matrícula.', html: '<div class="ck-bloco">'
        + '<div class="ck-falta"><b>Falta pagar a matrícula.</b>A taxa de inscrição reserva a sua vaga, mas sem o pagamento da matrícula a entrada na aula não é liberada.</div>'
        + '<p>' + (data ? 'Sua vaga na turma de ' + esc(data) + ' está reservada. ' : 'Sua vaga está reservada. ')
        + 'A matrícula, de ' + esc(matriculaAVista(d)) + ', é paga antes da aula: a secretaria da Escola escreve para você com o jeito de pagar.</p>'
        + ac.html + botoes(ac, d) + '<p class="ck-nota" style="margin-top:12px">' + NOTA_ESTORNO + '</p></div>' };
    }
    /* Só a taxa sem turma: com a venda sem turma no ar para o curso, a lista é de interesse (quem pagou tudo entra
       primeiro; 10.4, F5). O info.php diz; sem resposta, fica o texto de sempre. */
    function buscarListaInteresse(d) {
      listaInteresse = false;
      api('info.php').then(function (i) {
        if (!i || !i.ok || !i.plano_completo_sem_turma || !Array.isArray(i.cursos)) return;
        i.cursos.forEach(function (c) { if (c.slug === d.curso.slug && c.na_lista_sem_turma) listaInteresse = true; });
        if (listaInteresse) render(d);
      });
    }

    /* Questionário de dias e horários: enquanto o aluno não responde, um alerta logo depois do próximo passo; depois, o
       resumo embaixo, com o link para mudar. Com turma marcada, o texto pede para confirmar a data. */
    function alertaHorarios(d) {
      var h = d.horarios, e = d.escola || {};
      if (!h || !h.url || h.respondido) return '';
      var data = e.status === 'ok' && e.resultado && e.resultado !== 'sem_turma' ? dataBr(e.turma_inicio) : '';
      // Quem pagou tudo e espera turma entra pela ordem de pagamento: os horários ajudam a Escola a marcar turmas.
      var naFila = d.espera && d.espera.status === 'aguardando';
      return '<div class="ck-alerta" role="status"><div><p class="ck-alerta-titulo">Também importante: seus dias e horários</p>'
        + '<p>' + (data
          ? 'Sua turma é em ' + esc(data) + '. Confirme se a data serve para você e marque os dias e horários em que pode vir. Leva 30 segundos e ajuda a secretaria a achar outra data, se essa não servir.'
          : naFila ? 'Toque nos dias e horários em que você consegue vir. Leva 30 segundos e ajuda a Escola a marcar turmas em horários que sirvam a quem está na fila.'
          : 'Toque nos dias e horários em que você consegue vir às aulas. Leva 30 segundos e ajuda a secretaria a encaixar você na turma certa.') + '</p></div>'
        + '<a class="btn btn-red" href="' + esc(h.url) + '">Escolher meus horários</a></div>';
    }
    function blocoHorarios(d) {
      var h = d.horarios;
      if (!h || !h.url || !h.respondido) return '';
      return '<div class="ck-bloco"><h2>Seus horários</h2><p>' + esc(h.resumo) + '</p><p class="ck-nota"><a href="' + esc(h.url) + '">Mudar meus horários</a></p></div>';
    }

    /* A linha de pagamento (1.13): "À vista · PIX", "À vista · Crédito, final 1234" ou "Crédito em 10 parcelas mensais
       (1ª de R$ 35,46 e 9 de R$ 35,43), final 1234". Nunca o "CREDITO" do banco. */
    function linhaPagamento(d) {
      var n = d.parcelas || 1, final = d.cartao && d.cartao.ultimos4 ? ', final ' + esc(d.cartao.ultimos4) : '';
      if (d.metodo === 'pix') return 'À vista · PIX';
      if (n > 1 && d.parcela_centavos) {
        var p = d.parcela_centavos, p1 = d.primeira_parcela_centavos;
        return 'Crédito em ' + n + ' parcelas mensais ' + (p1 && p1 !== p ? '(1ª de ' + brl(p1) + ' e ' + (n - 1) + ' de ' + brl(p) + ')' : 'de ' + brl(p)) + final;
      }
      return 'À vista · Crédito' + final;
    }

    function render(d) {
      var completo = d.plano === 'taxa_e_matricula';
      var esperaTurma = d.espera && d.espera.status === 'turma';
      rotularPassos(completo ? (d.sem_turma && !esperaTurma ? 'semTurma' : 'completo') : 'taxa');
      var extras = [];
      if (d.taxa_centavos) extras.push(brl(d.taxa_centavos) + ' de custos de processamento que você escolheu cobrir');
      if (d.divulgacao_centavos) extras.push(brl(d.divulgacao_centavos) + ' de contribuição para a divulgação dos cursos');
      var custos = extras.length ? ', incluindo ' + extras.join(' e ') + '. Obrigado.' : '.';
      var v = completo ? varianteCompleta(d) : varianteTaxa(d);
      topo(v.h1, null);
      var diferenca = d.diferenca_devolver_centavos > 0
        ? '<p class="ck-rosa">Cobramos ' + brl(d.diferenca_devolver_centavos) + ' a mais que o valor mostrado. A diferença volta por PIX em até 2 dias úteis.</p>' : '';
      card.innerHTML = '<div class="ck-bloco"><p class="ck-selo-pago">Pago</p><h2>' + esc(d.curso.nome) + ' · ' + brl(d.total_cobrado_centavos || d.total_centavos) + '</h2>'
        + '<p class="ck-nota">' + linhaPagamento(d) + custos + '</p>' + diferenca + '</div>'
        + v.html
        + alertaHorarios(d)
        + blocoHorarios(d)
        + '<div class="ck-bloco"><p class="ck-nota" style="margin:0">Mandamos a confirmação para <b>' + esc(d.email) + '</b>. Guarde este link: <a href="' + esc(d.urls.parabens) + '">' + esc(d.urls.parabens) + '</a></p></div>';
      if (v.esperar && esperas < 20) {
        esperas++;
        setTimeout(function () { api('status.php?t=' + encodeURIComponent(token)).then(function (x) { if (x.ok && x.status === 'pago') render(x); }); }, 15000);
      }
    }

    /* O id da compra (d.id_compra) é um hash do token: o token abre a inscrição e não vai à Meta nem ao GA.
       A compra conta uma vez: o servidor manda o Purchase na hora do pagamento (API de Conversões) e a Meta
       só junta os dois em 48 h. Quem abre esta tela mais de um dia depois (outro navegador, o e-mail de
       confirmação, a página de horários) ou pelo painel da secretaria não manda de novo. O valor é o total sem juros
       (E9), com o plano, as parcelas e o order_id (spec 4.1). */
    function registrarCompra(d) {
      var c = consentimento();
      if (!c || (!c.estatistica && !c.marketing)) return; // sem consentimento, nada a medir nem a marcar
      if (param('painel') === '1') return;
      var pagoEm = d.pago_em ? Date.parse(String(d.pago_em).replace(' ', 'T') + 'Z') : NaN;
      if (!isNaN(pagoEm) && Date.now() - pagoEm > 864e5) return;
      try {
        var chave = 'mcp_purchase_' + token;
        if (localStorage.getItem(chave)) return;
        localStorage.setItem(chave, '1');
      } catch (e) { /* sem localStorage: registra assim mesmo */ }
      var plano = d.plano === 'taxa_e_matricula' ? 'taxa_e_matricula' : 'so_taxa';
      var parcelas = d.metodo === 'pix' || plano !== 'taxa_e_matricula' ? 1 : (d.parcelas || 1);
      var extra = { content_category: categoria(plano), plano: plano, parcelas: parcelas, order_id: d.id_compra };
      if (plano === 'taxa_e_matricula') extra.estado_turma = d.sem_turma ? 'breve' : 'aberta';
      rastrear('Purchase', Object.assign({ content_name: d.curso.nome, content_ids: [d.curso.slug], content_type: 'product', value: d.total_centavos / 100, currency: 'BRL' }, extra),
        'purchase', Object.assign({ transaction_id: d.id_compra, payment_type: d.metodo, plano: plano, parcelas: parcelas },
          itemGa(d.curso.slug, d.curso.nome, plano, d.inscricao_centavos || 0, d.matricula_centavos || 0, d.total_centavos)), { eventID: d.id_compra });
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
