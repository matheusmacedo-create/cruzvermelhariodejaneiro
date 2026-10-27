/* Cadastro de voluntário da Cruz Vermelha Brasileira Rio de Janeiro (site/voluntariado/cadastro/), sem dependências.

   Sem JavaScript o <form> faz um POST comum (application/x-www-form-urlencoded) para a API, que responde com um
   redirect para /voluntariado/cadastro/?protocolo=VOL-... ou ?erro=<campo>. Com JavaScript, este arquivo:
   - valida campo a campo no navegador, com mensagens em português, e leva o foco ao primeiro campo com erro;
   - envia a ficha em JSON (fetch, mesmo padrão de site/chat/chat.js) e trata 201, 409, 422, 429 e falha de rede;
   - troca o formulário pela tela de sucesso, com o protocolo em destaque e os próximos passos;
   - lê ?protocolo= e ?erro= da URL (o caminho sem JavaScript, quando a página volta da API);
   - aplica máscaras simples de telefone e CPF.
   O CPF nunca vai para o GA4 nem para o Pixel: os eventos de medição levam só o id do formulário.
   Fonte: site/voluntariado/cadastro/cadastro.js; a página referencia cadastro.js?v=<hash> (scripts/gerar_voluntariado_cadastro.py). */
(function () {
  'use strict';
  var form = document.getElementById('ficha');
  if (!form) return;
  var API = form.getAttribute('action') || '/matricula-cursos-presenciais/api/voluntarios.php';
  var FORM_ID = 'voluntariado-cadastro';
  var aviso = document.getElementById('vc-aviso');
  var sucesso = document.getElementById('vc-sucesso');
  var botao = form.querySelector('button[type="submit"]');
  var rotuloBotao = botao ? botao.textContent : '';
  var enviando = false, comecou = false;

  function $(sel, raiz) { return (raiz || document).querySelector(sel); }
  function $$(sel, raiz) { return Array.prototype.slice.call((raiz || document).querySelectorAll(sel)); }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function digitos(s) { return String(s || '').replace(/\D/g, ''); }
  function valor(nome) { var c = form.elements[nome]; return c && typeof c.value === 'string' ? c.value.trim() : ''; }
  function marcados(nome) { return $$('input[name="' + nome + '"]:checked', form).map(function (c) { return c.value; }); }
  function marcado(nome) { var c = form.elements[nome]; return !!(c && c.checked); }

  // GA4 só sai com a permissão de estatística e o Pixel só com a de marketing (aviso de cookies da home); sem eles,
  // gtag/fbq não existem e nada acontece. Nenhum dado pessoal vai nesses eventos.
  function ga(evento, dados) { try { if (window.gtag) window.gtag('event', evento, dados || {}); } catch (e) { /* GA4 ausente */ } }
  function pixel(evento, dados, id) { try { if (window.fbq) window.fbq('track', evento, dados || {}, id ? { eventID: id } : undefined); } catch (e) { /* Pixel ausente */ } }
  function novoId(prefixo) {
    try { if (window.cvrjMedicao && window.cvrjMedicao.novoId) return window.cvrjMedicao.novoId(prefixo); } catch (e) { /* sem bloco de medição */ }
    return prefixo + '.' + Date.now().toString(36) + '.' + Math.random().toString(36).slice(2, 10);
  }
  /* utm_*, fbclid e gclid: da URL atual ou os que o checkout/chat guardaram na sessão (mesma chave, mcp_origem).
     Só com consentimento de estatística no aviso de cookies (window.cvrjMedicao), como no chat. */
  function origem() {
    var o = {}, c = null;
    try { c = window.cvrjMedicao && window.cvrjMedicao.ler(); } catch (e) { c = null; }
    if (!c || !c.estatistica) return o;
    new URLSearchParams(location.search).forEach(function (v, k) { if (/^(utm_|fbclid$|gclid$)/.test(k)) o[k] = v.slice(0, 255); });
    try {
      var salvo = JSON.parse(sessionStorage.getItem('mcp_origem') || '{}');
      Object.keys(salvo).forEach(function (k) { if (!o[k]) o[k] = salvo[k]; });
    } catch (e) { /* armazenamento bloqueado */ }
    return o;
  }

  // ------------------------------------------------------------------ campos: caixa [data-campo], controle, erro
  function caixa(campo) { return $('[data-campo="' + campo + '"]', form); }
  function controle(campo) {
    var w = caixa(campo);
    return w ? $('input:not([type="hidden"]), select, textarea', w) : null;
  }
  function rotulo(campo) {
    var w = caixa(campo);
    if (!w) return campo;
    var l = w.tagName === 'FIELDSET' ? $('legend', w) : $('label', w);
    return l ? l.textContent.replace(/\*/g, '').replace(/\(opcional\)/i, '').replace(/\s+/g, ' ').trim() : campo;
  }
  function limparErros() {
    $$('.vc-erro', form).forEach(function (e) { e.textContent = ''; e.hidden = true; });
    $$('[aria-invalid]', form).forEach(function (c) { c.removeAttribute('aria-invalid'); });
    $$('.vc-invalido', form).forEach(function (w) { w.classList.remove('vc-invalido'); });
    aviso.hidden = true;
    aviso.innerHTML = '';
  }
  function marcarErro(campo, texto) {
    var w = caixa(campo);
    if (!w) return false;
    var e = $('.vc-erro', w);
    if (e) { e.textContent = texto; e.hidden = false; }
    w.classList.add('vc-invalido');
    // Nos grupos de caixas (frentes, dias...) o estado vai no fieldset; nos outros, no próprio controle.
    var alvo = w.tagName === 'FIELDSET' ? w : controle(campo);
    if (alvo) {
      alvo.setAttribute('aria-invalid', 'true');
      if (e && e.id) {
        var desc = (alvo.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        if (desc.indexOf(e.id) < 0) { desc.push(e.id); alvo.setAttribute('aria-describedby', desc.join(' ')); }
      }
    }
    return true;
  }
  function focar(campo) {
    var c = controle(campo);
    if (!c) return;
    var w = caixa(campo) || c;
    if (w.hidden) w.hidden = false;
    try { c.focus({ preventScroll: true }); } catch (e) { c.focus(); }
    try { w.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) { w.scrollIntoView(); }
  }
  function mostrarAviso(html, focarAviso) {
    aviso.innerHTML = html;
    aviso.hidden = false;
    if (focarAviso) {
      try { aviso.focus({ preventScroll: true }); } catch (e) { aviso.focus(); }
      try { aviso.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) { aviso.scrollIntoView(); }
    }
  }
  function p(texto) { return '<p>' + esc(texto) + '</p>'; }
  function resumoErros(erros) {
    var itens = erros.map(function (e) {
      return '<li><a href="#" data-campo="' + esc(e.campo) + '">' + esc(rotulo(e.campo)) + '</a>: ' + esc(e.texto) + '</li>';
    }).join('');
    return '<p><b>' + (erros.length === 1 ? 'Falta acertar um campo:' : 'Faltam acertar ' + erros.length + ' campos:') + '</b></p><ul>' + itens + '</ul>';
  }
  aviso.addEventListener('click', function (ev) {
    var a = ev.target.closest ? ev.target.closest('a[data-campo]') : null;
    if (!a) return;
    ev.preventDefault();
    focar(a.getAttribute('data-campo'));
  });

  // ------------------------------------------------------------------ validação
  // Aceita AAAA-MM-DD (type=date) ou DD/MM/AAAA (navegador sem suporte a type=date).
  function dataIso(v) {
    var m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(v);
    return m ? m[3] + '-' + m[2] + '-' + m[1] : v;
  }
  function idade(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
    if (!m) return null;
    var d = new Date(+m[1], +m[2] - 1, +m[3]);
    if (d.getFullYear() !== +m[1] || d.getMonth() !== +m[2] - 1 || d.getDate() !== +m[3]) return null;
    var hoje = new Date(), anos = hoje.getFullYear() - d.getFullYear();
    if (hoje.getMonth() < d.getMonth() || (hoje.getMonth() === d.getMonth() && hoje.getDate() < d.getDate())) anos--;
    return anos;
  }
  function cpfValido(c) {
    if (!/^\d{11}$/.test(c) || /^(\d)\1{10}$/.test(c)) return false;
    for (var t = 9; t < 11; t++) {
      var soma = 0;
      for (var i = 0; i < t; i++) soma += Number(c[i]) * (t + 1 - i);
      if ((soma * 10) % 11 % 10 !== Number(c[t])) return false;
    }
    return true;
  }
  var MSG_MENOR = 'No momento, o voluntariado aceita só quem tem 18 anos ou mais.';
  function validar() {
    var erros = [];
    function erro(campo, texto) { erros.push({ campo: campo, texto: texto }); }
    var nome = valor('nome');
    // As mesmas regras da API (api/voluntarios.php): nome com 5+ caracteres e um espaço, textos livres até 1000, motivação 20 a 1500.
    if (nome.length < 5 || nome.indexOf(' ') < 0) erro('nome', 'Digite seu nome completo, com nome e sobrenome.');
    var anos = idade(dataIso(valor('data_nascimento')));
    if (anos === null) erro('data_nascimento', 'Digite a data de nascimento completa: dia, mês e ano.');
    else if (anos < 0 || anos > 110) erro('data_nascimento', 'Confira a data de nascimento.');
    else if (anos < 18) erro('data_nascimento', MSG_MENOR);
    var cpf = digitos(valor('cpf'));
    if (cpf && !cpfValido(cpf)) erro('cpf', 'Esse CPF não parece válido. Confira os números ou deixe em branco por enquanto.');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(valor('email'))) erro('email', 'Digite um e-mail válido: é por ele que a confirmação chega.');
    var tel = digitos(valor('telefone'));
    if (tel.length < 10 || tel.length > 11) erro('telefone', 'Digite o telefone com DDD, por exemplo (21) 99999-9999.');
    if (valor('cidade').length < 2) erro('cidade', 'Digite a cidade onde você mora.');
    if (!valor('estado')) erro('estado', 'Escolha o estado.');
    if (!valor('escolaridade')) erro('escolaridade', 'Escolha a sua escolaridade.');
    var saude = marcados('saude')[0];
    if (!saude) erro('saude', 'Diga se você tem formação na área da saúde.');
    else if (saude === 'sim' && valor('saude_qual').length < 2) erro('saude_qual', 'Conte qual é a sua formação na área da saúde.');
    if (!marcados('frentes[]').length) erro('frentes', 'Marque pelo menos uma frente em que você quer ajudar.');
    if (!marcados('dias[]').length) erro('dias', 'Marque pelo menos um dia da semana.');
    if (!marcados('periodos[]').length) erro('periodos', 'Marque pelo menos um período.');
    var motivacao = valor('motivacao');
    if (motivacao.length < 20) erro('motivacao', 'Conte um pouco mais: a motivação precisa ter pelo menos 20 caracteres.');
    else if (motivacao.length > 1500) erro('motivacao', 'A motivação pode ter até 1500 caracteres.');
    ['experiencia', 'habilidades'].forEach(function (c) { if (valor(c).length > 1000) erro(c, 'Esse texto pode ter até 1000 caracteres.'); });
    if (!marcado('consentimento')) erro('consentimento', 'Para seguir, você precisa ler e aceitar a Política de Privacidade.');
    if (!marcado('veracidade')) erro('veracidade', 'Confirme que as informações são verdadeiras.');
    return erros;
  }

  // ------------------------------------------------------------------ envio
  function montarCorpo() {
    var saude = marcados('saude')[0] || '';
    var cpf = digitos(valor('cpf'));
    var corpo = {
      nome: valor('nome'), data_nascimento: dataIso(valor('data_nascimento')), email: valor('email').toLowerCase(),
      telefone: valor('telefone'), cidade: valor('cidade'), bairro: valor('bairro'), estado: valor('estado'),
      escolaridade: valor('escolaridade'), profissao: valor('profissao'), saude: saude,
      saude_qual: saude === 'sim' ? valor('saude_qual') : '', experiencia: valor('experiencia'), habilidades: valor('habilidades'),
      frentes: marcados('frentes[]'), dias: marcados('dias[]'), periodos: marcados('periodos[]'),
      motivacao: valor('motivacao'), como_conheceu: valor('como_conheceu'),
      aceita_contato: marcado('aceita_contato'), consentimento: marcado('consentimento'), veracidade: marcado('veracidade'),
      // pagina vai sempre, mas só o caminho: os utm_* da query string só seguem em origem, com consentimento.
      site: valor('site'), pagina: location.pathname.slice(0, 255), origem: origem(),
      // O id do CompleteRegistration do Pixel: o servidor pode mandar o mesmo evento à API de Conversões com esse id.
      evento_id: novoId('vol')
    };
    if (cpf) corpo.cpf = cpf;
    return corpo;
  }
  function ocupado(sim) {
    enviando = sim;
    if (!botao) return;
    botao.disabled = sim;
    if (sim) { botao.setAttribute('aria-busy', 'true'); botao.textContent = 'Enviando…'; }
    else { botao.removeAttribute('aria-busy'); botao.textContent = rotuloBotao; }
  }
  form.addEventListener('input', function () {
    if (comecou) return;
    comecou = true;
    ga('form_start', { form_id: FORM_ID });
  });
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (enviando) return;
    limparErros();
    var erros = validar();
    if (erros.length) {
      // Só o nome do primeiro campo recusado e quantos foram; nunca o que foi digitado (docs/rastreamento.md, 24/10/2026).
      ga('cadastro_erro', { form_id: FORM_ID, tipo: 'validacao', campo: erros[0].campo, erros: erros.length });
      erros.forEach(function (e) { marcarErro(e.campo, e.texto); });
      mostrarAviso(resumoErros(erros), false);
      focar(erros[0].campo);
      return;
    }
    var corpo = montarCorpo();
    ocupado(true);
    fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(corpo) })
      .then(function (resp) { return resp.json().catch(function () { return null; }).then(function (d) { return { http: resp.status, d: d }; }); })
      .catch(function () { return { http: 0, d: null }; })
      .then(function (x) {
        ocupado(false);
        var d = x.d && typeof x.d === 'object' ? x.d : {};
        if (d.ok === true || x.http === 201) {
          concluir(d, corpo);
          return;
        }
        ga('cadastro_erro', { form_id: FORM_ID, tipo: 'servidor', http: x.http, campo: String(d.campo || '').slice(0, 40) });
        if (x.http === 0) {
          mostrarAviso(p('Sem conexão. Verifique a internet e tente de novo.'), true);
        } else if (x.http === 422 && d.campo && caixa(d.campo)) {
          var texto = d.erro || 'Confira este campo.';
          marcarErro(d.campo, texto);
          mostrarAviso(resumoErros([{ campo: d.campo, texto: texto }]), false);
          focar(d.campo);
        } else if (x.http === 409) {
          mostrarAviso(p(d.erro || 'Já recebemos a sua ficha há pouco tempo. A equipe do voluntariado entra em contato pelo WhatsApp, no telefone informado.'), true);
        } else if (x.http === 429) {
          mostrarAviso(p(d.erro || 'Recebemos muitos envios em pouco tempo. Aguarde um pouco e tente de novo.'), true);
        } else {
          mostrarAviso(p(d.erro || 'Não consegui enviar agora. Tente de novo em instantes.'), true);
        }
      });
  });
  function concluir(d, corpo) {
    mostrarSucesso({ protocolo: d.protocolo, nome: d.nome, email: d.email || corpo.email, prazo: d.prazo, aviso: d.aviso, menor: d.menor === true });
    // sign_up, não generate_lead: o generate_lead é o envio do checkout, e os dois são eventos-chave separados (24/10/2026).
    ga('sign_up', { method: 'voluntariado', form_id: FORM_ID, lead_source: 'site' });
    pixel('CompleteRegistration', { content_name: 'voluntariado', content_category: 'voluntariado' }, corpo.evento_id);
  }

  // ------------------------------------------------------------------ tela de sucesso
  function mostrarSucesso(o) {
    if (!sucesso) return;
    var texto = function (chave, t) { $$('[data-vc="' + chave + '"]', sucesso).forEach(function (el) { el.textContent = t; }); };
    texto('protocolo', o.protocolo || '');
    texto('saudacao', o.nome ? 'Obrigado, ' + o.nome + '! Recebemos o seu cadastro.' : 'Obrigado! Recebemos o seu cadastro.');
    var emailFrase = $('[data-vc="email-frase"]', sucesso);
    if (emailFrase) emailFrase.innerHTML = o.email ? 'Enviamos a confirmação para <b>' + esc(o.email) + '</b>.' : 'Enviamos a confirmação para o e-mail que você informou.';
    // "aviso" é a frase pronta da API (turma do CBFI até 28/11, depois as novas entradas em janeiro); "prazo" ficou para respostas antigas.
    texto('prazo-frase', o.aviso ? o.aviso : o.prazo ? 'As novas entradas abrem em ' + o.prazo + ': a equipe do voluntariado entra em contato com você pelo WhatsApp nessa época, e até lá não é preciso esperar resposta.' : 'A equipe do voluntariado entra em contato pelo WhatsApp para a próxima etapa.');
    $$('[data-vc="menor"]', sucesso).forEach(function (el) { el.hidden = !o.menor; });
    var intro = document.getElementById('vc-intro-form');
    if (intro) intro.hidden = true;
    form.hidden = true;
    sucesso.hidden = false;
    try { sucesso.focus({ preventScroll: true }); } catch (e) { sucesso.focus(); }
    try { sucesso.scrollIntoView({ block: 'start' }); } catch (e) { /* segue */ }
  }

  // ------------------------------------------------------------------ máscaras, contador, campo condicional, ocultos
  // Formata só enquanto a pessoa digita (não ao apagar), para o backspace não brigar com a pontuação.
  function mascara(el, fn) {
    if (!el) return;
    var anterior = el.value;
    el.addEventListener('input', function () {
      if (el.value.length < anterior.length) { anterior = el.value; return; }
      var novo = fn(el.value);
      if (novo !== el.value) {
        el.value = novo;
        try { el.setSelectionRange(novo.length, novo.length); } catch (e) { /* tipo sem seleção */ }
      }
      anterior = el.value;
    });
  }
  function telefoneBonito(v) {
    var d = digitos(v).slice(0, 11);
    if (!d) return '';
    if (d.length <= 2) return '(' + d;
    if (d.length <= 6) return '(' + d.slice(0, 2) + ') ' + d.slice(2);
    if (d.length <= 10) return '(' + d.slice(0, 2) + ') ' + d.slice(2, 6) + '-' + d.slice(6);
    return '(' + d.slice(0, 2) + ') ' + d.slice(2, 7) + '-' + d.slice(7);
  }
  function cpfBonito(v) {
    var d = digitos(v).slice(0, 11);
    return d.replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3}\.\d{3})(\d)/, '$1.$2').replace(/^(\d{3}\.\d{3}\.\d{3})(\d)/, '$1-$2');
  }
  mascara(form.elements.telefone, telefoneBonito);
  mascara(form.elements.cpf, cpfBonito);

  // Contador da motivação: o número visível muda a cada tecla; o leitor de tela ouve só quando a pessoa para de digitar.
  var motivacao = form.elements.motivacao, contador = document.getElementById('vc-motivacao-contador');
  var contadorSr = document.getElementById('vc-motivacao-contador-sr'), contadorTimer = null;
  function contar() {
    if (!motivacao || !contador) return;
    var n = motivacao.value.length;
    var t = n < 20 ? n + ' de 20 caracteres, no mínimo' : n + ' de 1500 caracteres';
    contador.textContent = t;
    if (contadorSr) {
      clearTimeout(contadorTimer);
      contadorTimer = setTimeout(function () { contadorSr.textContent = t; }, 1200);
    }
  }
  if (motivacao) { motivacao.addEventListener('input', contar); contar(); }

  // "Qual formação?" só aparece quando a pessoa diz que tem formação em saúde (sem JavaScript fica sempre visível).
  var qualCaixa = document.getElementById('vc-saude-qual-caixa');
  function alternarSaude() { if (qualCaixa) qualCaixa.hidden = marcados('saude')[0] !== 'sim'; }
  $$('input[name="saude"]', form).forEach(function (r) { r.addEventListener('change', alternarSaude); });
  alternarSaude();

  // A data de nascimento não pode ser no futuro.
  var nasc = form.elements.data_nascimento;
  if (nasc && nasc.type === 'date') {
    var hoje = new Date();
    nasc.max = hoje.getFullYear() + '-' + ('0' + (hoje.getMonth() + 1)).slice(-2) + '-' + ('0' + hoje.getDate()).slice(-2);
  }

  // Campos ocultos (caminho sem JavaScript): a página atual, só o caminho, e a origem da visita (utm_*, fbclid,
  // gclid) apenas com consentimento de estatística; sem ele, os ocultos de UTM seguem vazios.
  if (form.elements.pagina) form.elements.pagina.value = location.pathname.slice(0, 255);
  var o = origem();
  Object.keys(o).forEach(function (k) { var c = form.elements['origem[' + k + ']']; if (c) c.value = o[k]; });

  // ------------------------------------------------------------------ volta da API sem JavaScript (?protocolo= / ?erro=)
  // ?erro= traz um nome de campo do formulário ou um pseudo-campo: duplicada (já há ficha deste e-mail nos últimos
  // 30 dias), limite (muitas tentativas) ou envio (falha genérica: armadilha ou origem de outro site).
  var q = new URLSearchParams(location.search);
  var protocolo = q.get('protocolo');
  var erroUrl = q.get('erro');
  if (protocolo && /^VOL-[0-9A-Za-z-]{1,24}$/.test(protocolo)) {
    mostrarSucesso({ protocolo: protocolo, menor: false });
  } else if (erroUrl) {
    var MENSAGENS = {
      data_nascimento: MSG_MENOR,
      email: 'Confira o e-mail: é por ele que a confirmação chega.',
      telefone: 'Digite o telefone com DDD, por exemplo (21) 99999-9999.',
      cpf: 'Esse CPF não parece válido. Confira os números ou deixe em branco por enquanto.',
      motivacao: 'A motivação precisa ter de 20 a 1500 caracteres.',
      consentimento: 'Para seguir, você precisa ler e aceitar a Política de Privacidade.',
      veracidade: 'Confirme que as informações são verdadeiras.'
    };
    // Só nomes de campo conhecidos viram seletor: qualquer outro valor cai na mensagem genérica abaixo.
    if (/^[a-z_]{1,30}$/.test(erroUrl) && caixa(erroUrl)) {
      var textoErro = MENSAGENS[erroUrl] || 'Confira este campo e envie de novo.';
      marcarErro(erroUrl, textoErro);
      mostrarAviso('<p><b>O cadastro ainda não foi recebido.</b></p>' + resumoErros([{ campo: erroUrl, texto: textoErro }]), false);
      focar(erroUrl);
    } else if (erroUrl === 'duplicada') {
      mostrarAviso(p('Já recebemos a sua ficha nos últimos 30 dias. Não precisa enviar de novo: a equipe do voluntariado entra em contato pelo WhatsApp, no telefone informado.'), true);
    } else if (erroUrl === 'limite') {
      mostrarAviso(p('Recebemos muitos envios em pouco tempo. Aguarde um pouco e tente de novo.'), true);
    } else {
      // "envio" e qualquer outro valor: a ficha não foi gravada.
      mostrarAviso(p('O cadastro ainda não foi recebido. Confira os campos e envie de novo.'), true);
    }
  }
})();
