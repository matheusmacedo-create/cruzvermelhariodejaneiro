/* Páginas pessoais dos avisos do ponto (ponto/lembretes/, ponto/saida/, ponto/opiniao/, ponto/sair/).
   O link traz o token (#t=, no fragmento), que vai em cada chamada a /matricula-cursos-presenciais/api/avisos.php.
   A página diz qual é pelo atributo data-pagina do <body>. Sem dependências. */
(function () {
  'use strict';

  var API = '/matricula-cursos-presenciais/api/avisos.php';
  var pagina = document.body.getAttribute('data-pagina');
  var tela = document.getElementById('av-tela');
  // O token vem no fragmento (#t=), que o navegador não manda ao servidor; links antigos traziam ?t=.
  var token = new URLSearchParams(location.hash.replace(/^#/, '')).get('t') || new URLSearchParams(location.search).get('t') || '';

  // ---------------------------------------------------------------- utilidades
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function q(sel) { return tela.querySelector(sel); }
  function erroHtml(msg) { return msg ? '<p class="pt-erro" role="alert">' + esc(msg) + '</p>' : ''; }
  function okHtml(msg) { return msg ? '<p class="pt-aviso ok" role="status">' + esc(msg) + '</p>' : ''; }
  function maiuscula(s) { s = String(s || ''); return s.charAt(0).toUpperCase() + s.slice(1); }

  function api(corpo) {
    corpo.t = token;
    return fetch(API, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify(corpo)
    }).then(function (r) {
      return r.json().catch(function () { return null; }).then(function (d) {
        if (!d || typeof d !== 'object') d = { ok: false, erro: 'Resposta inesperada do servidor. Tente de novo.' };
        d.http = r.status;
        return d;
      });
    }).catch(function () { return { ok: false, erro: 'Sem conexão. Confira a internet e tente de novo.', http: 0 }; });
  }

  /* Desenha a tela e leva o foco ao título: quem usa leitor de tela ouve onde está, e não o formulário inteiro. */
  function desenhar(html) {
    tela.innerHTML = html;
    var h = tela.querySelector('h1');
    if (h) { h.tabIndex = -1; h.focus({ preventScroll: true }); }
  }

  function telaErro(msg, contato) {
    desenhar('<h1>Não foi possível abrir</h1>' + erroHtml(msg)
      + '<p class="pt-nota">' + (contato ? 'Fale com a secretaria pelo e-mail <a href="mailto:' + esc(contato) + '">' + esc(contato) + '</a> ou pessoalmente, na recepção da sede.'
        : 'Fale com a secretaria na recepção da sede.') + '</p>');
  }

  function travar(form, sim) {
    form.querySelectorAll('button, input, textarea, select').forEach(function (el) { el.disabled = sim; });
  }

  function enviarForm(form, corpo, depois) {
    travar(form, true);
    var antigo = form.querySelector('.pt-erro');
    if (antigo) antigo.remove();
    form.querySelectorAll('[aria-invalid]').forEach(function (el) { el.removeAttribute('aria-invalid'); });
    return api(corpo).then(function (d) {
      if (!d.ok) {
        travar(form, false);
        var campo = d.campo && form.querySelector('[name="' + d.campo + '"]');
        // O erro vai junto do campo (no celular, no topo do formulário ele ficaria fora da tela).
        var perto = campo && campo.closest('label, fieldset');
        if (perto && perto.parentNode) perto.insertAdjacentHTML('beforebegin', erroHtml(d.erro));
        else form.insertAdjacentHTML('afterbegin', erroHtml(d.erro));
        var erro = form.querySelector('.pt-erro');
        if (campo) { campo.setAttribute('aria-invalid', 'true'); if (campo.focus) campo.focus({ preventScroll: true }); }
        if (erro) erro.scrollIntoView({ block: 'center', behavior: 'smooth' });
        return;
      }
      depois(d);
    });
  }

  // ---------------------------------------------------------------- lembretes
  var ORDEM_DIAS = ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'];

  function telaLembretes(d, msg) {
    var p = d.prefs;
    // A equipe contratada só vê de terça a sexta (o lembrete dela não sai no fim de semana nem em feriado).
    var dias = ORDEM_DIAS.filter(function (k) { return d.dias_opcoes[k]; }).map(function (k) {
      return '<label class="av-chip"><input type="checkbox" name="dias" value="' + k + '" aria-label="' + esc(d.dias_opcoes[k] || k) + '"' + (p.dias.indexOf(k) >= 0 ? ' checked' : '') + '><span>'
        + esc((d.dias_opcoes[k] || k).slice(0, 3)) + '</span></label>';
    }).join('');
    var html = '<h1>Seus lembretes, ' + esc(d.nome) + '</h1>' + okHtml(msg)
      + '<p class="pt-sub">Escolha como quer ser lembrado de registrar a chegada e a saída na sede. Você pode mudar quando quiser, por este mesmo link.</p>'
      + '<form id="av-form" novalidate>'
      + '<fieldset class="av-grupo"><legend>Em que dias você costuma vir à sede?</legend>'
      + '<p class="av-ajuda">Na véspera desses dias, às 18h, chega um lembrete. Não marque nada se não quiser o lembrete da véspera.'
      + (d.voluntario ? '' : ' Para a equipe, o lembrete só sai em dia útil (por isso não há o de segunda) e só se você pedir aqui.') + '</p>'
      + '<div class="av-dias">' + dias + '</div>'
      + (!d.vespera_ligada ? '<p class="av-ajuda">Os lembretes da véspera começam quando a secretaria ligar. Suas escolhas já ficam guardadas.</p>' : '')
      + '</fieldset>'
      + '<fieldset class="av-grupo"><legend>Como prefere receber?</legend>'
      + '<label class="pt-check"><input type="checkbox" name="email"' + (p.email && d.email ? ' checked' : '') + (d.email ? '' : ' disabled') + '> E-mail'
      + (d.email ? ' <span class="av-mascara">' + esc(d.email) + '</span>' : ' <span class="av-mascara">(sem e-mail cadastrado: peça à secretaria)</span>') + '</label>'
      + '<label class="pt-check"><input type="checkbox" name="whatsapp"' + (p.whatsapp ? ' checked' : '') + '> Também pelo WhatsApp da Cruz Vermelha Brasileira RJ'
      + (d.whatsapp ? ' <span class="av-mascara">' + esc(d.whatsapp) + '</span>' : '') + '</label>'
      + '<p class="av-ajuda">É opcional: sem ele, tudo vai por e-mail, e você pode desligar quando quiser.'
      + (d.whatsapp ? ' Para trocar o número, fale com a secretaria.' : '') + '</p>'
      + (d.whatsapp ? '' : '<div class="av-numero"><label class="pt-campo"><span>Número do seu WhatsApp</span>'
        + '<input name="telefone" inputmode="tel" autocomplete="tel" placeholder="(21) 99999-9999" maxlength="20"></label></div>')
      + (!d.whatsapp_disponivel ? '<p class="av-ajuda">O WhatsApp ainda não está ligado. Por enquanto, os avisos vão por e-mail; sua escolha já fica guardada.</p>' : '')
      + '</fieldset>'
      + '<fieldset class="av-grupo"><legend>Outros avisos</legend>'
      + (d.voluntario ? '<label class="pt-check"><input type="checkbox" name="saida"' + (p.saida ? ' checked' : '') + '> Me avise quando eu esquecer de registrar a saída</label>' : '')
      + '<label class="pt-check"><input type="checkbox" name="comunicados"' + (p.comunicados ? ' checked' : '') + '> Receber os comunicados sobre o ponto (novidades e a pesquisa de opinião)</label>'
      + '</fieldset>'
      + '<button class="pt-btn" type="submit">Salvar minhas escolhas</button></form>'
      + '<p class="pt-nota">Usamos seu e-mail e seu celular para os avisos do ponto e para a secretaria falar com você, nunca para propaganda. '
      + 'Os lembretes saem das 8h às 20h. Para parar tudo, desmarque e-mail e WhatsApp e salve.</p>';
    desenhar(html);
    q('[name=whatsapp]').addEventListener('change', function (e) {
      if (e.target.checked && !d.whatsapp) q('[name=telefone]').focus();
    });
    q('#av-form').addEventListener('submit', function (e) {
      e.preventDefault();
      var f = e.target;
      var marcados = Array.prototype.map.call(f.querySelectorAll('[name=dias]:checked'), function (i) { return i.value; });
      enviarForm(f, {
        acao: 'lembretes_salvar', dias: marcados, email: f.email.checked, whatsapp: f.whatsapp.checked, telefone: f.telefone ? f.telefone.value : '',
        saida: f.saida ? f.saida.checked : false, comunicados: f.comunicados.checked
      }, function (r) { telaLembretes(r, r.mensagem); window.scrollTo(0, 0); });
    });
  }

  // ---------------------------------------------------------------- saída sem registro
  function telaSaida(d, msg) {
    var html = '<h1>' + (d.saida ? 'Saída registrada' : 'A que horas você saiu?') + '</h1>' + okHtml(msg);
    if (d.saida) {
      html += '<p class="pt-sub">' + esc(maiuscula(d.quando)) + ' você entrou às <b>' + esc(d.entrada) + '</b> e saiu às <b>' + esc(d.saida) + '</b>. Está tudo certo, obrigado!</p>';
    } else if (!d.pode) {
      html += '<p class="pt-sub">' + esc(maiuscula(d.quando)) + ' você registrou a entrada às <b>' + esc(d.entrada) + '</b>.</p>'
        + '<p class="pt-status">Esta saída não pode mais ser informada por aqui. Fale com a secretaria para corrigir.</p>';
    } else {
      html += '<p class="pt-sub">' + esc(maiuscula(d.quando)) + ' você registrou a chegada na sede às <b>' + esc(d.entrada) + '</b>, e a saída ficou em aberto. '
        + 'Se quiser que essas horas entrem no seu histórico de horas voluntárias, informe a que horas saiu. Se preferir não informar, tudo bem.</p>'
        + (d.informada ? '<p class="pt-status ok">Você informou a saída às ' + esc(d.informada) + '. A secretaria vai conferir. Se errou, corrija abaixo.</p>' : '')
        + '<form id="av-form" novalidate><label class="pt-campo"><span>Hora em que saiu</span><input type="time" name="hora" required step="60" value="' + esc(d.informada || '') + '"></label>'
        + '<label class="pt-check"><input type="checkbox" name="dia_seguinte"> Saí depois da meia-noite</label>'
        + '<button class="pt-btn" type="submit">' + (d.informada ? 'Corrigir a hora' : 'Informar a hora da saída') + '</button></form>'
        + '<p class="pt-nota">A secretaria confere o horário antes de ele entrar nas suas horas. No tablet da recepção, o ponto também pergunta.</p>';
    }
    desenhar(html);
    var form = q('#av-form');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!/^\d{2}:\d{2}$/.test(form.hora.value)) {
        var velho = form.querySelector('.pt-erro');
        if (velho) velho.remove();
        form.insertAdjacentHTML('afterbegin', erroHtml('Escolha a hora em que você saiu.'));
        form.hora.focus();
        return;
      }
      enviarForm(form, { acao: 'saida_informar', hora: form.hora.value, dia_seguinte: form.dia_seguinte.checked }, function (r) { telaSaida(r, r.mensagem); });
    });
  }

  // ---------------------------------------------------------------- opinião
  function opcoesRadio(nome, opcoes, atual) {
    return Object.keys(opcoes).map(function (k) {
      return '<label class="av-opcao"><input type="radio" name="' + nome + '" value="' + esc(k) + '"' + (String(atual) === String(k) ? ' checked' : '') + '><span>' + esc(opcoes[k]) + '</span></label>';
    }).join('');
  }

  function telaOpiniao(d, msg) {
    var r = d.resposta || {};
    var aluno = d.publico === 'aluno';
    var escala = Object.keys(d.opcoes.facilidade).map(function (k) {
      return '<label class="av-nota"><input type="radio" name="facilidade" value="' + k + '"' + (String(r.facilidade) === k ? ' checked' : '') + '><span><b>' + k + '</b><small>'
        + esc(d.opcoes.facilidade[k]) + '</small></span></label>';
    }).join('');
    var problemas = Object.keys(d.opcoes.problemas).map(function (k) {
      return '<label class="pt-check"><input type="checkbox" name="problemas" value="' + esc(k) + '"' + ((r.problemas || []).indexOf(k) >= 0 ? ' checked' : '') + '> ' + esc(d.opcoes.problemas[k]) + '</label>';
    }).join('');
    var n = 0;
    function pergunta(texto) { n++; return '<legend><span class="av-n">' + n + '</span>' + esc(texto) + '</legend>'; }
    var html = '<h1>' + (d.nome ? 'Como está sendo, ' + esc(d.nome) + '?' : 'Como está sendo?') + '</h1>' + okHtml(msg)
      + '<p class="pt-sub">' + (aluno ? 'Conte como foi confirmar a presença nas aulas pelo ponto da recepção.' : 'Conte como está sendo registrar a chegada e a saída no ponto da sede.')
      + ' Leva 1 minuto. A secretaria vê as respostas sem o seu nome (a não ser que você marque “Pode falar comigo sobre a minha resposta”).'
      + ' Ao fim da pesquisa, a resposta deixa de ficar ligada ao seu link, e tudo é apagado em 1 ano.</p>'
      + (d.resposta && !msg ? '<p class="pt-aviso ok">Você já respondeu. Se quiser, mude o que quiser e salve de novo.</p>' : '')
      + '<form id="av-form" novalidate>'
      + '<fieldset class="av-grupo">' + pergunta(aluno ? 'Confirmar a presença no ponto foi…' : 'Registrar a entrada e a saída no ponto é…') + '<div class="av-escala">' + escala + '</div></fieldset>'
      + '<fieldset class="av-grupo">' + pergunta(aluno ? 'Como você confirmou a presença?' : 'Como você costuma registrar?') + '<div class="av-opcoes">' + opcoesRadio('como', d.opcoes.como, r.como) + '</div></fieldset>'
      + '<fieldset class="av-grupo">' + pergunta('Teve algum problema? Marque quantos quiser.') + problemas + '</fieldset>'
      + (d.opcoes.lembretes ? '<fieldset class="av-grupo">' + pergunta('Os lembretes do ponto…') + '<div class="av-opcoes">' + opcoesRadio('lembretes', d.opcoes.lembretes, r.lembretes) + '</div></fieldset>' : '')
      + '<fieldset class="av-grupo" aria-labelledby="av-melhorar">' + pergunta('O que podemos melhorar? (opcional)').replace('<legend>', '<legend id="av-melhorar">')
      + '<textarea name="comentario" maxlength="1000" rows="4" placeholder="Escreva à vontade." aria-labelledby="av-melhorar">' + esc(r.comentario || '') + '</textarea></fieldset>'
      + '<label class="pt-check"><input type="checkbox" name="contato_ok"' + (r.contato_ok ? ' checked' : '') + '> Pode falar comigo sobre a minha resposta</label>'
      + (aluno ? '<div class="av-contato" hidden><label class="pt-campo"><span>Seu nome</span><input name="nome" maxlength="160" autocomplete="name"></label>'
        + '<label class="pt-campo"><span>E-mail ou telefone</span><input name="contato" maxlength="190" autocomplete="email"></label></div>' : '')
      + '<button class="pt-btn" type="submit">' + (d.resposta ? 'Salvar de novo' : 'Enviar minha opinião') + '</button></form>';
    desenhar(html);
    var form = q('#av-form');
    var contato = q('.av-contato');
    if (contato) {
      var mostrar = function () { contato.hidden = !form.contato_ok.checked; };
      form.contato_ok.addEventListener('change', mostrar);
      mostrar();
    }
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var marcado = function (nome) { var i = form.querySelector('[name=' + nome + ']:checked'); return i ? i.value : ''; };
      enviarForm(form, {
        acao: 'opiniao_salvar', facilidade: Number(marcado('facilidade') || 0), como: marcado('como'), lembretes: marcado('lembretes'),
        problemas: Array.prototype.map.call(form.querySelectorAll('[name=problemas]:checked'), function (i) { return i.value; }),
        comentario: form.comentario.value, contato_ok: form.contato_ok.checked,
        nome: form.nome ? form.nome.value : '', contato: form.contato ? form.contato.value : ''
      }, function (r2) {
        desenhar('<div class="pt-sucesso"><div class="pt-marca" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17.5 19 7.5"/></svg></div>'
          + '<h1>Obrigado!</h1><p class="pt-sub" role="status">' + esc(r2.mensagem) + '</p><button type="button" class="pt-link" id="av-editar">Mudar minha resposta</button></div>');
        q('#av-editar').addEventListener('click', function () { telaOpiniao(r2); });
        window.scrollTo(0, 0);
      });
    });
  }

  // ---------------------------------------------------------------- sair (aluno)
  function telaSair(d, msg) {
    if (d.bloqueado) {
      desenhar('<h1>Pronto</h1>' + okHtml(msg || 'Você já pediu para não receber os lembretes das aulas e os avisos do ponto.')
        + '<p class="pt-nota">Os e-mails da sua matrícula e o comprovante de comparecimento continuam chegando. Mudou de ideia? Fale com a secretaria.</p>');
      return;
    }
    desenhar('<h1>Não quer mais receber?</h1>'
      + '<p class="pt-sub">Você deixa de receber os lembretes das aulas (na véspera) e os avisos do ponto da sede. Os e-mails da sua matrícula e o comprovante de comparecimento continuam chegando.</p>'
      + '<form id="av-form"><button class="pt-btn pt-btn-saida" type="submit">Não quero mais receber</button></form>');
    q('#av-form').addEventListener('submit', function (e) {
      e.preventDefault();
      enviarForm(e.target, { acao: 'sair_confirmar' }, function (r) { telaSair(r, r.mensagem); });
    });
  }

  // ---------------------------------------------------------------- começo
  var leitura = { lembretes: 'lembretes_ler', saida: 'saida_ler', opiniao: 'opiniao_ler', sair: 'sair_ler' }[pagina];
  var telas = { lembretes: telaLembretes, saida: telaSaida, opiniao: telaOpiniao, sair: telaSair };
  if (!leitura || !token) {
    telaErro('Este endereço precisa do link completo que chegou no aviso. Abra o link de novo, sem cortar nada.');
    return;
  }
  api({ acao: leitura }).then(function (d) {
    if (!d.ok) { telaErro(d.erro, d.contato); return; }
    telas[pagina](d);
  });
})();
