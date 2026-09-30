/* Ponto da sede (/ponto/): entrada e saída dos colaboradores e presença dos alunos nas aulas.
   O modo vem do servidor (api/ponto.php):
     - aparelho da sede, liberado pela secretaria no portal: teclado numérico grande, volta sozinho
       ao início depois de cada registro e não lembra de ninguém;
     - celular, pelo QR code: pede a localização a cada consulta e pode lembrar do CPF neste celular.
   Conversa só com /matricula-cursos-presenciais/api/ponto.php (mesma origem). */
(function () {
  'use strict';

  var API = '/matricula-cursos-presenciais/api/ponto.php';
  var VOLTA_SUCESSO = 8;   // segundos até voltar ao início depois de registrar (só no aparelho)
  var VOLTA_PARADO = 45;   // segundos parado na tela da pessoa até voltar ao início (só no aparelho)
  var tela = document.getElementById('pt-tela');
  var estado = { modo: null, aparelho: null, lembrado: null, outroCpf: false, sessao: null, cpfDigitado: '' };
  var relogios = [];
  var teclado = null;      // função que recebe as teclas físicas quando o teclado da tela está aberto
  var instalar = null;     // pedido de instalação do Chrome (ponto na tela inicial do celular)
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); instalar = e; });

  // ---------------------------------------------------------------- utilidades
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function digitos(v) { return String(v || '').replace(/\D+/g, ''); }
  function mascaraCpf(v) {
    v = digitos(v).slice(0, 11);
    return v.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
  }
  function cpfMascarado(cpf) { return '***.' + cpf.slice(3, 6) + '.' + cpf.slice(6, 9) + '-**'; }
  function cpfValido(cpf) {
    cpf = digitos(cpf);
    if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
    for (var t = 9; t < 11; t++) {
      var soma = 0;
      for (var i = 0; i < t; i++) soma += Number(cpf[i]) * (t + 1 - i);
      if (Number(cpf[t]) !== ((10 * soma) % 11) % 10) return false;
    }
    return true;
  }
  function q(sel) { return tela.querySelector(sel); }
  function limparRelogios() { relogios.forEach(clearTimeout); relogios = []; teclado = null; }
  function depois(segundos, fn) { relogios.push(setTimeout(fn, segundos * 1000)); }
  function erroHtml(msg) { return msg ? '<p class="pt-erro" role="alert">' + esc(msg) + '</p>' : ''; }
  function foco(sel) { var el = q(sel); if (el) el.focus(); }

  function api(corpo) {
    var opcoes = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (corpo) {
      opcoes.method = 'POST';
      opcoes.headers['Content-Type'] = 'application/json';
      opcoes.body = JSON.stringify(corpo);
    }
    return fetch(API, opcoes).then(function (r) {
      return r.json().catch(function () { return null; }).then(function (d) {
        if (!d || typeof d !== 'object') d = { ok: false, erro: 'Resposta inesperada do servidor. Tente de novo.' };
        d.http = r.status;
        return d;
      });
    }).catch(function () { return { ok: false, erro: 'Sem conexão. Confira a internet e tente de novo.', http: 0 }; });
  }

  // ---------------------------------------------------------------- relógio
  var hora = document.getElementById('pt-hora');
  var data = document.getElementById('pt-data');
  function relogio() {
    var agora = new Date();
    try {
      hora.textContent = agora.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' });
      var dia = agora.toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'America/Sao_Paulo' });
      data.textContent = dia.charAt(0).toUpperCase() + dia.slice(1);
    } catch (e) { hora.textContent = agora.toTimeString().slice(0, 5); }
  }
  relogio();
  setInterval(relogio, 10000);

  // ---------------------------------------------------------------- telas
  function inicio(erro) {
    limparRelogios();
    estado.sessao = null;
    if (estado.modo === 'aparelho') telaTeclado(erro); else telaCelular(erro);
  }

  function carregando(msg) {
    tela.innerHTML = '<div class="pt-carregando"><span class="pt-giro" aria-hidden="true"></span><p>' + esc(msg) + '</p></div>';
  }

  /* Aparelho da sede: teclado numérico na tela (e o teclado físico, se houver). */
  function telaTeclado(erro) {
    var cpf = '';
    var teclas = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'apagar', '0', 'ok'];
    tela.innerHTML = '<h1>Digite seu CPF</h1>'
      + '<p class="pt-sub">Colaborador: registre a entrada e a saída. Aluno: confirme a presença na aula de hoje.</p>'
      + '<div class="pt-visor vazio" id="pt-visor" aria-live="polite">000.000.000-00</div>'
      + erroHtml(erro)
      + '<div class="pt-teclado" role="group" aria-label="Teclado numérico">'
      + teclas.map(function (t) {
        if (t === 'apagar') return '<button type="button" class="pt-tecla apagar" data-t="apagar" aria-label="Apagar o último número">Apagar</button>';
        if (t === 'ok') return '<button type="button" class="pt-tecla ok" data-t="ok" disabled>Continuar</button>';
        return '<button type="button" class="pt-tecla" data-t="' + t + '">' + t + '</button>';
      }).join('') + '</div>';
    var visor = q('#pt-visor');
    var ok = q('[data-t=ok]');
    function mostrar() {
      visor.textContent = cpf ? mascaraCpf(cpf) : '000.000.000-00';
      visor.classList.toggle('vazio', !cpf);
      ok.disabled = cpf.length !== 11;
    }
    function tecla(t) {
      if (/^\d$/.test(t) && cpf.length < 11) cpf += t;
      else if (t === 'apagar') cpf = cpf.slice(0, -1);
      else if (t === 'ok' && cpf.length === 11) {
        if (!cpfValido(cpf)) { telaTeclado('CPF inválido. Confira os números.'); return; }
        identificar({ cpf: cpf });
        return;
      }
      mostrar();
    }
    tela.querySelectorAll('.pt-tecla').forEach(function (b) {
      b.addEventListener('click', function () { tecla(b.getAttribute('data-t')); });
    });
    teclado = tecla;
  }

  /* Celular: campo com o teclado do próprio celular, e a pessoa lembrada, se houver. */
  function telaCelular(erro) {
    if (estado.lembrado && !estado.outroCpf) {
      tela.innerHTML = '<h1>Olá de novo!</h1>'
        + '<p class="pt-sub">Este celular lembra do CPF <b>' + esc(estado.lembrado) + '</b>.</p>'
        + erroHtml(erro)
        + '<button type="button" class="pt-btn" id="pt-continuar">Continuar</button>'
        + '<button type="button" class="pt-link" id="pt-outro">Usar outro CPF</button>'
        + '<p class="pt-nota">A página vai pedir a localização do celular: ela só confirma que você está na sede e não fica guardada.</p>';
      q('#pt-continuar').addEventListener('click', function () { identificar({}); });
      q('#pt-outro').addEventListener('click', function () { estado.outroCpf = true; telaCelular(); });
      return;
    }
    tela.innerHTML = '<h1>Ponto da sede</h1>'
      + '<p class="pt-sub">Colaborador: registre a entrada e a saída. Aluno: confirme a presença na aula de hoje.</p>'
      + erroHtml(erro)
      + '<form id="pt-form" novalidate>'
      + '<label class="pt-campo"><span>Seu CPF</span><input id="pt-cpf" inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" maxlength="14" required value="' + esc(mascaraCpf(estado.cpfDigitado)) + '"></label>'
      + '<label class="pt-check"><input type="checkbox" id="pt-lembrar"> Lembrar de mim neste celular</label>'
      + '<button class="pt-btn" type="submit">Continuar</button></form>'
      + '<p class="pt-nota">A página vai pedir a localização do celular: ela só confirma que você está na sede e não fica guardada.</p>';
    var campo = q('#pt-cpf');
    campo.addEventListener('input', function () { campo.value = mascaraCpf(campo.value); });
    q('#pt-form').addEventListener('submit', function (e) {
      e.preventDefault();
      var cpf = digitos(campo.value);
      if (!cpfValido(cpf)) { telaCelular('CPF inválido. Confira os números.'); foco('#pt-cpf'); return; }
      var lembrar = q('#pt-lembrar').checked;
      // Se der erro (localização, por exemplo), o CPF volta preenchido; depois de identificar, some do campo.
      estado.cpfDigitado = cpf;
      identificar({ cpf: cpf, lembrar: lembrar }).then(function (ok) {
        if (ok) { estado.lembrado = lembrar ? cpfMascarado(cpf) : null; estado.outroCpf = false; estado.cpfDigitado = ''; }
      });
    });
  }

  /* Localização do celular (só no modo celular). Resolve com {lat, lng, precisao} ou rejeita com a mensagem. */
  function localizar() {
    return new Promise(function (resolve, reject) {
      if (!navigator.geolocation) { reject('Este navegador não informa a localização. Use o aparelho da recepção.'); return; }
      navigator.geolocation.getCurrentPosition(function (p) {
        resolve({ lat: p.coords.latitude, lng: p.coords.longitude, precisao: p.coords.accuracy });
      }, function (e) {
        reject(e && e.code === 1
          ? 'A localização não foi permitida. Para registrar pelo celular, permita a localização para este site nas configurações do navegador e tente de novo. Ou use o aparelho da recepção.'
          : 'Não conseguimos a localização do celular agora. Confira se ela está ligada e tente de novo.');
      }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 });
    });
  }

  /* Confere o CPF (e a localização, no celular). Resolve com true se abriu a tela da pessoa. */
  function identificar(dados) {
    var corpo = { acao: 'identificar', cpf: dados.cpf || '' };
    if (dados.lembrar !== undefined) corpo.lembrar = dados.lembrar;
    var passo = Promise.resolve();
    if (estado.modo === 'celular') {
      carregando('Confirmando que você está na sede…');
      passo = localizar().then(function (pos) { corpo.posicao = pos; });
    }
    return passo.then(function () {
      carregando('Buscando seu cadastro…');
      return api(corpo);
    }).then(function (d) {
      if (!d.ok) { inicio(d.erro); return false; }
      estado.sessao = d.sessao;
      telaPessoa(d);
      return true;
    }, function (msg) { inicio(msg); return false; });
  }

  /* Formulário "a que horas você saiu?" de um registro sem saída (voluntário). */
  function formSaida(id, rotulo) {
    return '<form class="pt-saida" data-registro="' + esc(id) + '" novalidate>'
      + '<label class="pt-campo"><span>' + esc(rotulo) + '</span><input type="time" name="hora" required step="60"></label>'
      + '<label class="pt-check"><input type="checkbox" name="dia_seguinte"> Saí depois da meia-noite</label>'
      + '<button class="pt-btn pt-btn-sec" type="submit">Informar a hora da saída</button></form>';
  }

  function telaPessoa(d) {
    limparRelogios();
    var html = '<h1>Olá, ' + esc(d.nome) + '!</h1>';
    if (d.confirmacao) html += '<p class="pt-aviso ok" role="status">' + esc(d.confirmacao) + '</p>';
    // Avisos dos comunicados no ar: só o texto (o link pessoal vai por e-mail ou WhatsApp, nunca na tela).
    (d.avisos || []).forEach(function (a) {
      html += '<p class="pt-aviso">' + esc(a.texto) + (a.dica ? ' <span class="pt-dica">' + esc(a.dica) + '</span>' : '') + '</p>';
    });
    if (d.colaborador) {
      // Voluntários e diretoria veem as horas doadas; os outros vínculos registram só a presença.
      var c = d.colaborador;
      html += '<section class="pt-bloco"><h2>' + (c.horas ? 'Horas doadas' : 'Presença na sede') + '</h2>';
      if (c.na_sede && c.desde_dia) {
        // Entrada aberta de outro dia: plantão que virou a noite ou saída esquecida.
        html += '<p class="pt-status erro">Entrada registrada ' + esc(c.desde_dia) + ' às ' + esc(c.desde) + ', sem saída.</p>'
          + '<button type="button" class="pt-btn pt-btn-saida" data-acao="saida">Estou saindo agora</button>'
          + (c.aberto_id && estado.modo === 'aparelho' ? '<details class="pt-detalhe"><summary>Saí ' + esc(c.desde_dia) + ': informar o horário</summary>'
            + formSaida(c.aberto_id, 'A que horas você saiu ' + c.desde_dia + '?') + '</details>'
            : (c.aberto_id ? '<p class="pt-nota">Saiu ' + esc(c.desde_dia) + ' e esqueceu de registrar? Informe o horário pelo link do aviso que chega ao seu e-mail ou WhatsApp, ou no tablet da recepção.</p>' : ''));
      } else {
        html += (c.na_sede ? '<p class="pt-status ok">Na sede desde ' + esc(c.desde) + (c.horas ? ' · ' + esc(c.agora) + ' até agora' : '') + '</p>'
            : '<p class="pt-status">Sem entrada registrada agora.</p>')
          + '<button type="button" class="pt-btn' + (c.na_sede ? ' pt-btn-saida' : '') + '" data-acao="' + (c.na_sede ? 'saida' : 'entrada') + '">'
          + (c.na_sede ? 'Registrar saída' : 'Registrar entrada') + '</button>';
      }
      html += (c.horas ? '<p class="pt-horas">Horas registradas hoje: <b>' + esc(c.hoje) + '</b> · em ' + esc(c.mes_nome) + ': <b>' + esc(c.mes) + '</b></p>' : '')
        + (c.termo_pendente ? '<p class="pt-nota">Seu termo de adesão ao voluntariado ainda não foi registrado. Fale com a secretaria.</p>' : '')
        + '</section>';
      // Saídas esquecidas de dias anteriores: a pessoa informa o horário ali mesmo; a secretaria confere.
      (d.pendencias || []).forEach(function (p) {
        html += '<section class="pt-bloco pt-pendencia"><h2>Saída sem registro</h2>';
        if (p.informada) {
          html += '<p class="pt-status">' + esc(p.quando.charAt(0).toUpperCase() + p.quando.slice(1)) + ' você entrou às ' + esc(p.entrada)
            + ' e informou a saída às <b>' + esc(p.informada) + '</b>. A secretaria vai conferir.</p>';
        } else {
          html += '<p class="pt-status">' + esc(p.quando.charAt(0).toUpperCase() + p.quando.slice(1)) + ' você registrou a entrada às ' + esc(p.entrada)
            + ' e a saída ficou em aberto. Se quiser, informe a que horas saiu: as horas desse dia entram no seu histórico.</p>'
            + formSaida(p.id, 'A que horas você saiu?');
        }
        html += '</section>';
      });
    }
    (d.aulas || []).forEach(function (a) {
      html += '<section class="pt-bloco"><h2>Aula de hoje</h2>'
        + '<p class="pt-aula"><b>' + esc(a.curso) + '</b><span>' + esc(a.horario) + '</span></p>';
      if (a.registrada) html += '<p class="pt-status ok">Presença registrada às ' + esc(a.registrada) + '. O comprovante sai às ' + esc(a.disponivel_em) + '.</p>';
      else if (a.cancelada) html += '<p class="pt-status erro">A presença nesta aula foi cancelada pela secretaria.</p>';
      else if (a.pode) html += '<button type="button" class="pt-btn" data-acao="presenca" data-aula="' + esc(a.id) + '">Confirmar presença</button>';
      else html += '<p class="pt-status">' + esc(a.motivo) + '</p>';
      html += '</section>';
    });
    // Só interessa a quem pode ser aluno: para o colaborador, a nota sobre a escola seria ruído.
    if (d.escola_indisponivel && !d.colaborador) html += '<p class="pt-nota">Não conseguimos consultar as aulas na escola agora. Se você é aluno, tente de novo em instantes.</p>';
    html += '<button type="button" class="pt-link" id="pt-voltar">' + (estado.modo === 'aparelho' ? 'Não é você? Voltar' : 'Voltar') + '</button>';
    tela.innerHTML = html;
    tela.querySelectorAll('[data-acao]').forEach(function (b) {
      b.addEventListener('click', function () { registrar(b.getAttribute('data-acao'), b.getAttribute('data-aula')); });
    });
    tela.querySelectorAll('form.pt-saida').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        e.preventDefault();
        var hora = f.querySelector('[name=hora]').value;
        if (!/^\d{2}:\d{2}$/.test(hora)) {
          var velho = f.querySelector('.pt-erro');
          if (velho) velho.remove();
          f.insertAdjacentHTML('afterbegin', erroHtml('Escolha a hora em que você saiu.'));
          f.querySelector('[name=hora]').focus();
          return;
        }
        informarSaida(d, f.getAttribute('data-registro'), hora, f.querySelector('[name=dia_seguinte]').checked, f);
      });
    });
    q('#pt-voltar').addEventListener('click', function () { inicio(); });
    if (estado.modo === 'aparelho') {
      depois(VOLTA_PARADO, function () { inicio(); });
      // Quem está mexendo na tela não volta ao início no meio do que fazia: cada toque ou tecla recomeça a conta.
      ['pointerdown', 'keydown', 'input'].forEach(function (ev) {
        tela.addEventListener(ev, function reinicia() {
          if (!tela.contains(q('#pt-voltar'))) { tela.removeEventListener(ev, reinicia); return; }
          relogios.forEach(clearTimeout); relogios = [];
          depois(VOLTA_PARADO, function () { inicio(); });
        });
      });
    }
    var titulo = tela.querySelector('h1');
    if (titulo) { titulo.tabIndex = -1; titulo.focus(); }
  }

  /* Manda a hora da saída esquecida e volta à tela da pessoa, já atualizada. */
  function informarSaida(d, registro, hora, diaSeguinte, form) {
    limparRelogios();
    form.querySelectorAll('button, input').forEach(function (el) { el.disabled = true; });
    var antigo = form.querySelector('.pt-erro');
    if (antigo) antigo.remove();
    api({ acao: 'informar_saida', sessao: estado.sessao, registro: Number(registro), hora: hora, dia_seguinte: diaSeguinte }).then(function (r) {
      if (!r.ok) {
        if (r.motivo === 'sessao') { telaAviso(r.erro, true); return; }
        form.querySelectorAll('button, input').forEach(function (el) { el.disabled = false; });
        form.insertAdjacentHTML('afterbegin', erroHtml(r.erro));
        if (estado.modo === 'aparelho') depois(VOLTA_PARADO, function () { inicio(); });
        return;
      }
      d.colaborador = r.colaborador;
      d.pendencias = r.pendencias;
      d.confirmacao = r.mensagem + ' A secretaria confere e as horas desse dia entram no seu histórico de horas voluntárias.';
      telaPessoa(d);
    });
  }

  function registrar(acao, aula) {
    limparRelogios();
    tela.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
    var corpo = { acao: acao, sessao: estado.sessao };
    if (aula) corpo.aula = aula;
    api(corpo).then(function (d) {
      if (!d.ok) { telaAviso(d.erro, d.motivo === 'sessao'); return; }
      telaSucesso(d);
    });
  }

  function telaAviso(msg, recomecar) {
    tela.innerHTML = '<h1>Não foi possível registrar</h1>' + erroHtml(msg)
      + '<button type="button" class="pt-btn" id="pt-fim">' + (recomecar ? 'Digitar o CPF de novo' : 'Voltar ao início') + '</button>';
    q('#pt-fim').addEventListener('click', function () { inicio(); });
    if (estado.modo === 'aparelho') depois(VOLTA_PARADO, function () { inicio(); });
  }

  function telaSucesso(d) {
    var marca = '<div class="pt-marca" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17.5 19 7.5"/></svg></div>';
    var html = '<div class="pt-sucesso">' + marca + '<h1>' + esc(d.mensagem) + '</h1>';
    if (d.registrado === 'entrada') {
      html += '<p class="pt-sub">Bom trabalho! Na hora de ir embora, registre a saída.</p>';
    } else if (d.registrado === 'saida') {
      if (d.duracao) html += '<p class="pt-sub">Desta vez: <b>' + esc(d.duracao) + '</b>. Em ' + esc(d.colaborador.mes_nome) + ': <b>' + esc(d.colaborador.mes) + '</b>.</p>';
    } else {
      html += '<p class="pt-sub">Aula de <b>' + esc(d.curso) + '</b>, ' + esc(d.horario) + '. O comprovante de comparecimento fica disponível hoje às <b>'
        + esc(d.disponivel_hora) + '</b>' + (d.email ? ' e vai para o e-mail <b>' + esc(d.email) + '</b>' : '') + '.</p>';
      if (d.link) html += '<a class="pt-btn pt-btn-sec" href="' + esc(d.link) + '">Ver meu comprovante</a>';
    }
    html += '<button type="button" class="pt-btn" id="pt-fim">Concluir</button>';
    if (estado.modo === 'aparelho') html += '<p class="pt-nota" id="pt-contagem" aria-live="off"></p>';
    else html += dicaApp();
    tela.innerHTML = html + '</div>';
    q('#pt-fim').addEventListener('click', function () { inicio(); });
    ligarDicaApp();
    if (estado.modo === 'aparelho') {
      var resta = VOLTA_SUCESSO;
      var contagem = q('#pt-contagem');
      (function tique() {
        contagem.textContent = 'Voltando ao início em ' + resta + ' s…';
        if (resta-- <= 0) { inicio(); return; }
        depois(1, tique);
      })();
    }
  }

  /* Dica, no celular, de pôr o ponto na tela inicial (até 3 vezes, e nunca dentro do app instalado). */
  function lerDica() { try { return Number(localStorage.getItem('pt_dica_app') || 0); } catch (e) { return 9; } }
  function gravarDica(n) { try { localStorage.setItem('pt_dica_app', String(n)); } catch (e) { /* sem armazenamento: tudo bem */ } }
  function dicaApp() {
    var instalado = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
    if (instalado || lerDica() >= 3) return '';
    gravarDica(lerDica() + 1);
    var ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
    return '<div class="pt-aviso pt-app"><b>Da próxima vez, um toque:</b> '
      + (instalar ? 'ponha o ponto na tela inicial do celular. <button type="button" class="pt-link" id="pt-instalar">Adicionar à tela inicial</button>'
        : (ios ? 'no Safari, toque em Compartilhar e depois em <b>Adicionar à Tela de Início</b>.' : 'no menu do navegador (⋮), toque em <b>Adicionar à tela inicial</b>.'))
      + ' <button type="button" class="pt-link" id="pt-dica-nao">Não mostrar de novo</button></div>';
  }
  function ligarDicaApp() {
    var b = q('#pt-instalar');
    if (b) b.addEventListener('click', function () { instalar.prompt(); instalar = null; b.remove(); });
    var nao = q('#pt-dica-nao');
    if (nao) nao.addEventListener('click', function () { gravarDica(9); var d = q('.pt-app'); if (d) d.remove(); });
  }

  // Teclado físico no aparelho da sede (números, apagar e Enter).
  document.addEventListener('keydown', function (e) {
    if (!teclado || e.ctrlKey || e.metaKey || e.altKey) return;
    if (/^\d$/.test(e.key)) teclado(e.key);
    else if (e.key === 'Backspace') teclado('apagar');
    else if (e.key === 'Enter') teclado('ok');
    else return;
    e.preventDefault();
  });

  // ---------------------------------------------------------------- começo
  api().then(function (d) {
    if (!d.ok) { tela.innerHTML = '<p>' + esc(d.erro || 'Não foi possível abrir o ponto agora.') + '</p>'; return; }
    estado.modo = d.modo;
    estado.aparelho = d.aparelho;
    estado.lembrado = d.lembrado;
    document.body.classList.add('pt-' + d.modo);
    document.getElementById('pt-modo').textContent = d.modo === 'aparelho' ? 'Aparelho da sede · ' + d.aparelho : 'Pelo celular, na sede';
    inicio();
  });
})();
