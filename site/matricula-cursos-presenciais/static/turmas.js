/* Turmas sob demanda (seção #turmas-sob-demanda da página de matrícula).
   Um formulário só: com 15 alunos ou mais é pedido de turma fechada; com menos, lista de interesse do curso
   naquele idioma. Em português, curso do catálogo tem turma aberta: sem grupo, o caminho é a matrícula na hora.
   As regras são as mesmas de api/lib/turmas.php; o servidor confere tudo de novo. */
(function () {
  'use strict';
  var MINIMO = 15, MAXIMO = 30, LIMITE = 300;
  var API = '/matricula-cursos-presenciais/api/turmas.php';
  var CHECKOUT = '/matricula-cursos-presenciais/checkout/?curso=';
  var form = document.getElementById('turma-form');
  if (!form || !window.fetch) return;
  var bloco = document.getElementById('turma-form-bloco');
  var ok = document.getElementById('turma-ok');
  var situacao = document.getElementById('tf-situacao');
  var erroGeral = document.getElementById('tf-erro');
  var botao = document.getElementById('tf-enviar');
  var matricula = document.getElementById('tf-matricula');
  var campos = {
    curso: form.elements.curso, pessoas: form.elements.pessoas, organizacao: form.elements.organizacao,
    local_endereco: form.elements.local_endereco, periodo: form.elements.periodo, nome: form.elements.nome,
    email: form.elements.email, telefone: form.elements.telefone, observacoes: form.elements.observacoes,
    consentimento: form.elements.consentimento
  };
  var enviando = false;

  function valorRadio(nome) { var r = form.querySelector('input[name="' + nome + '"]:checked'); return r ? r.value : ''; }
  function marcarRadio(nome, valor) { var r = form.querySelector('input[name="' + nome + '"][value="' + valor + '"]'); if (r) r.checked = true; }
  function opcaoCurso() { return campos.curso.options[campos.curso.selectedIndex] || null; }
  function nomeCurso() { var o = opcaoCurso(); return o && o.value ? o.getAttribute('data-nome') || o.text : ''; }
  function doCatalogo() { var o = opcaoCurso(); return !!(o && o.getAttribute('data-catalogo') === '1'); }
  function pessoas() { var n = parseInt(campos.pessoas.value, 10); return isNaN(n) ? 0 : n; }
  function alunos(n) { return n + (n === 1 ? ' aluno' : ' alunos'); }
  function mostrar(el, sim) { if (el) el.hidden = !sim; }

  /* O que vai acontecer com o pedido, recalculado a cada mudança. */
  function cenario() {
    var n = pessoas(), idioma = valorRadio('idioma') || 'pt';
    if (!campos.curso.value || n < 1) return 'vazio';
    if (n > LIMITE) return 'grande';
    if (n >= MINIMO) return 'fechada';
    return idioma === 'pt' && doCatalogo() ? 'matricula' : 'lista';
  }
  function atualizar() {
    var c = cenario(), n = pessoas(), curso = nomeCurso(), ingles = valorRadio('idioma') === 'en';
    var rotulo = curso + (ingles ? ', em inglês' : '');
    var texto = '', classe = '';
    if (c === 'vazio') texto = 'Escolha o curso e diga quantos alunos são para ver como fica a turma.';
    else if (c === 'grande') { texto = 'Para mais de ' + LIMITE + ' alunos, fale com a gente pelo chat da página: montamos um plano com você.'; classe = 'aviso'; }
    else if (c === 'fechada') {
      var turmas = Math.ceil(n / MAXIMO);
      texto = '<strong>Turma fechada com prioridade.</strong> ' + alunos(n) + ' de ' + rotulo + (turmas > 1 ? ', em ' + turmas + ' turmas de até ' + MAXIMO : '')
        + '. A secretaria responde em até 3 dias úteis com as datas. Mesmo valor por pessoa dos cursos; nada é cobrado agora.';
      classe = 'fechada';
    } else if (c === 'matricula') {
      texto = '<strong>Este curso já tem turma aberta em português.</strong> Com menos de ' + MINIMO + ' alunos, cada pessoa faz a matrícula na hora, sem esperar. Turma exclusiva é a partir de ' + MINIMO + '.';
      classe = 'aviso';
    } else {
      texto = '<strong>Lista de interesse.</strong> ' + (n > 1 ? 'Vocês entram com ' + alunos(n) : 'Você entra') + ' na lista de ' + rotulo
        + '. A turma abre quando juntarmos ' + MINIMO + ' alunos, e avisamos por e-mail e WhatsApp. Se você conseguir ' + MINIMO + ', a turma é sua, com prioridade.';
      classe = 'lista';
    }
    situacao.innerHTML = texto;
    situacao.className = 'mr-tf-situacao' + (classe ? ' ' + classe : '');
    // Campos que só fazem sentido para turma fechada: local (sede ou outro).
    mostrar(document.getElementById('tf-bloco-local'), c === 'fechada');
    mostrar(document.getElementById('tf-bloco-endereco'), c === 'fechada' && valorRadio('local') === 'outro');
    mostrar(document.getElementById('tf-nota-jovens'), campos.curso.value === 'primeiros-socorros-jovens');
    var pode = c === 'fechada' || c === 'lista';
    mostrar(document.getElementById('tf-dados'), pode || c === 'vazio');
    mostrar(botao, c !== 'matricula');
    botao.disabled = !pode || enviando;
    botao.textContent = enviando ? 'Enviando…' : c === 'lista' ? 'Entrar na lista de interesse' : 'Pedir minha turma';
    mostrar(matricula, c === 'matricula');
    if (c === 'matricula') matricula.href = CHECKOUT + encodeURIComponent(campos.curso.value) + utms('&');
  }

  /* utm_*, fbclid e gclid da visita, como no chat: só com consentimento de estatística. */
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
  function utms(prefixo) {
    var q = new URLSearchParams();
    new URLSearchParams(location.search).forEach(function (v, k) { if (/^(utm_|fbclid$|gclid$)/.test(k)) q.set(k, v); });
    var s = q.toString();
    return s ? prefixo + s : '';
  }

  function limparErros() {
    Array.prototype.forEach.call(form.querySelectorAll('.mr-tf-campo.erro'), function (el) { el.classList.remove('erro'); });
    Array.prototype.forEach.call(form.querySelectorAll('[aria-invalid]'), function (el) { el.removeAttribute('aria-invalid'); });
    Array.prototype.forEach.call(form.querySelectorAll('.mr-tf-erro'), function (el) { el.textContent = ''; el.hidden = true; });
    erroGeral.hidden = true; erroGeral.textContent = '';
  }
  function erroNoCampo(nome, mensagem) {
    var alvo = document.getElementById('tf-erro-' + nome);
    var campo = campos[nome] || form.querySelector('[name="' + nome + '"]');
    if (!alvo || !campo) { erroGeral.textContent = mensagem; erroGeral.hidden = false; erroGeral.focus(); return; }
    var caixa = alvo.closest('.mr-tf-campo');
    if (caixa) caixa.classList.add('erro');
    alvo.textContent = mensagem; alvo.hidden = false;
    // O leitor de tela lê a mensagem junto com o campo quando o foco chega nele.
    if (campo.setAttribute) { campo.setAttribute('aria-invalid', 'true'); campo.setAttribute('aria-describedby', alvo.id); }
    if (campo.focus) campo.focus();
  }

  /* Conferência local, espelho da do servidor: poupa uma ida e volta e mostra o erro no campo certo. */
  function conferir() {
    if (!campos.curso.value) return ['curso', 'Escolha o curso.'];
    var n = pessoas();
    if (n < 1) return ['pessoas', 'Diga quantos alunos vão fazer o curso.'];
    if (cenario() === 'fechada' && valorRadio('local') === 'outro' && campos.local_endereco.value.trim().length < 5) return ['local_endereco', 'Diga onde seriam as aulas (bairro e cidade, ou o endereço).'];
    if (campos.nome.value.trim().length < 2) return ['nome', 'Digite seu nome.'];
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(campos.email.value.trim())) return ['email', 'Esse e-mail não parece válido.'];
    var tel = campos.telefone.value.replace(/\D/g, '');
    if (tel.length === 13 && tel.indexOf('55') === 0) tel = tel.slice(2);
    if ((tel.length !== 10 && tel.length !== 11) || tel.charAt(0) === '0') return ['telefone', 'Informe o WhatsApp com DDD: é por ele que avisamos quando a turma fechar.'];
    if (!campos.consentimento.checked) return ['consentimento', 'Marque a autorização para a secretaria falar com você sobre esta turma.'];
    return null;
  }

  function rastrear(d, corpo) {
    var dados = { content_name: nomeCurso(), content_category: 'turma-' + d.tipo, content_ids: [corpo.curso] };
    // Evento próprio (SubmitApplication / turma_pedido): o Lead e o generate_lead são do funil pago do checkout,
    // e um pedido de turma misturado neles atrapalharia a otimização dos anúncios de matrícula.
    try { if (window.fbq) window.fbq('track', 'SubmitApplication', dados, corpo.evento_id ? { eventID: corpo.evento_id } : undefined); } catch (e) { /* pixel ausente */ }
    try { if (window.gtag) window.gtag('event', 'turma_pedido', { turma_tipo: d.tipo, curso: corpo.curso, idioma: corpo.idioma, alunos: corpo.pessoas }); } catch (e) { /* GA4 ausente */ }
  }

  function concluir(d) {
    var fechada = d.tipo === 'fechada';
    document.getElementById('turma-ok-titulo').textContent = fechada ? 'Pedido de turma recebido, ' + d.nome + '!' : 'Você está na lista, ' + d.nome + '!';
    document.getElementById('turma-ok-texto').textContent = fechada
      ? 'A secretaria responde em até ' + d.prazo + ' com as datas para a turma de ' + d.curso + '. Guarde o protocolo ' + d.protocolo + '.'
      : 'Quando juntarmos ' + MINIMO + ' alunos para ' + d.curso + ', avisamos por e-mail e WhatsApp. Guarde o protocolo ' + d.protocolo + '.';
    document.getElementById('turma-ok-copia').textContent = d.copia_enviada
      ? 'Mandamos a confirmação para ' + d.email + '. Se não aparecer, olhe no spam.'
      : 'Não conseguimos mandar a confirmação por e-mail agora, mas o pedido está registrado.';
    form.hidden = true; ok.hidden = false;
    ok.focus();
  }

  function enviar(ev) {
    ev.preventDefault();
    if (enviando) return;
    limparErros();
    var falha = conferir();
    if (falha) { erroNoCampo(falha[0], falha[1]); return; }
    var c = cenario();
    var corpo = {
      curso: campos.curso.value, idioma: valorRadio('idioma') || 'pt', pessoas: pessoas(),
      local: c === 'fechada' ? (valorRadio('local') || 'sede') : 'sede',
      local_endereco: campos.local_endereco.value, organizacao: campos.organizacao.value, periodo: campos.periodo.value,
      nome: campos.nome.value, email: campos.email.value, telefone: campos.telefone.value,
      observacoes: campos.observacoes.value, consentimento: campos.consentimento.checked,
      pagina: location.pathname + location.search, origem: origem(), site: form.elements.site.value
    };
    // Mesmo id no Pixel e na API de Conversões (o servidor só manda com "sim" para marketing).
    try { if (window.cvrjMedicao && window.cvrjMedicao.novoId) corpo.evento_id = window.cvrjMedicao.novoId('ld'); } catch (e) { /* sem bloco de medição */ }
    enviando = true; atualizar();
    fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(corpo) })
      .then(function (r) { return r.json().catch(function () { return null; }).then(function (d) { return { http: r.status, d: d }; }); })
      .catch(function () { return { http: 0, d: null }; })
      .then(function (x) {
        enviando = false; atualizar();
        var d = x.d && typeof x.d === 'object' ? x.d : { ok: false, erro: x.http === 0 ? 'Sem conexão. Verifique a internet e tente de novo.' : 'Não conseguimos enviar agora. Tente de novo em instantes.' };
        if (d.ok) { rastrear(d, corpo); concluir(d); return; }
        if (d.matricula) { atualizar(); }
        if (d.campo) erroNoCampo(d.campo, d.erro); else { erroGeral.textContent = d.erro || 'Não conseguimos enviar agora.'; erroGeral.hidden = false; erroGeral.focus(); }
      });
  }

  /* Botões dos cartões e o link de cada curso: preenchem o formulário e levam até ele. */
  function preencher(dados) {
    if (dados.curso && campos.curso.querySelector('option[value="' + dados.curso + '"]')) campos.curso.value = dados.curso;
    if (dados.idioma === 'en' || dados.idioma === 'pt') marcarRadio('idioma', dados.idioma);
    if (dados.pessoas && !pessoas()) campos.pessoas.value = dados.pessoas;
    atualizar();
  }
  Array.prototype.forEach.call(document.querySelectorAll('[data-turma-abrir]'), function (el) {
    el.addEventListener('click', function (e) {
      e.preventDefault();
      if (!ok.hidden) { ok.hidden = true; form.hidden = false; }
      preencher({ curso: el.getAttribute('data-curso'), idioma: el.getAttribute('data-idioma'), pessoas: el.getAttribute('data-pessoas') });
      bloco.scrollIntoView({ behavior: 'smooth', block: 'start' });
      var foco = campos.curso.value ? campos.pessoas : campos.curso;
      setTimeout(function () { try { foco.focus({ preventScroll: true }); } catch (err) { foco.focus(); } }, 350);
      try { if (window.gtag) window.gtag('event', 'turma_abrir', { origem: el.getAttribute('data-turma-abrir') || 'botao', curso: el.getAttribute('data-curso') || '' }); } catch (err) { /* GA4 ausente */ }
    });
  });
  document.getElementById('turma-ok-outro').addEventListener('click', function () {
    form.reset(); ok.hidden = true; form.hidden = false; atualizar(); campos.curso.focus();
  });

  form.addEventListener('input', atualizar);
  form.addEventListener('change', atualizar);
  form.addEventListener('submit', enviar);

  // Link de anúncio: ?turma=1 (ou o próprio #turmas-sob-demanda) com curso, idioma e alunos já preenchidos.
  var q = new URLSearchParams(location.search);
  if (q.get('turma') || location.hash === '#turmas-sob-demanda') {
    preencher({ curso: q.get('turma_curso') || q.get('curso'), idioma: q.get('idioma'), pessoas: q.get('alunos') });
    if (q.get('turma')) setTimeout(function () { bloco.scrollIntoView({ block: 'start' }); }, 50);
  } else {
    atualizar();
  }
})();
