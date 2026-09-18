#!/usr/bin/env python3
"""Gera as páginas do checkout da matrícula: checkout/, pendente/ e parabens/.

Todas usam o cabeçalho, o rodapé, o CSS, o GA4 e o Meta Pixel da home (via
scripts/gerar_matricula_presencial.py) e conversam com o backend em
site/matricula-cursos-presenciais/api/ (PHP, Unicopag). As três levam noindex.

Uso:  python3 scripts/gerar_checkout.py   (depois de gerar_matricula_presencial.py)
"""
from __future__ import annotations

import json
from pathlib import Path

from gerar_matricula_presencial import DADOS, HOME, RAIZ, esc, partes_da_home

PASTA = RAIZ / "site" / "matricula-cursos-presenciais"
API = "/matricula-cursos-presenciais/api/"

CSS = """
    .ck-teste { background: #fff3cd; border-bottom: 1px solid #ffe69c; color: #664d03; padding: 10px 0; font-size: .92rem; text-align: center; }
    .ck-hero { background: var(--soft); border-bottom: 1px solid var(--line); padding: 40px 0 28px; }
    .ck-hero h1 { color: var(--black); font-size: clamp(1.8rem, 3.6vw, 2.6rem); letter-spacing: -.03em; line-height: 1.05; margin: 8px 0 10px; }
    .ck-hero .lead { max-width: 70ch; margin: 0; }
    .ck-secao { padding: 36px 0 64px; }
    .ck-grid { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 32px; align-items: start; }
    .ck-card { background: #fff; border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow); padding: 28px; }
    .ck-resumo { position: sticky; top: 96px; }
    .ck-resumo h2 { font-size: 1.05rem; margin: 0 0 10px; }
    .ck-linha { display: flex; justify-content: space-between; gap: 12px; padding: 10px 0; border-top: 1px solid var(--line); font-size: .95rem; }
    .ck-linha b { text-align: right; color: var(--black); }
    .ck-linha.total { font-weight: 800; font-size: 1.15rem; color: var(--black); }
    .ck-campo { display: block; margin-bottom: 16px; }
    .ck-campo > span { display: block; font-size: .85rem; font-weight: 700; color: var(--black); margin-bottom: 6px; }
    .ck-campo input, .ck-campo select { width: 100%; min-height: 46px; padding: 10px 14px; border: 1px solid var(--line); border-radius: 12px; font: inherit; font-size: 1rem; color: var(--text); background: #fff; }
    .ck-campo input:focus, .ck-campo select:focus { outline: 2px solid var(--red); outline-offset: 1px; border-color: var(--red); }
    .ck-campo.erro input, .ck-campo.erro select { border-color: var(--red); background: #fff8f8; }
    .ck-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .ck-metodos { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 6px 0 16px; }
    .ck-metodo { border: 1px solid var(--line); border-radius: 12px; padding: 14px; cursor: pointer; display: flex; gap: 10px; align-items: flex-start; background: #fff; }
    .ck-metodo input { margin-top: 4px; }
    .ck-metodo b { display: block; color: var(--black); }
    .ck-metodo small { color: var(--muted); }
    .ck-metodo.ativo { border-color: var(--red); box-shadow: inset 0 0 0 1px var(--red); }
    .ck-check { display: flex; gap: 10px; align-items: flex-start; font-size: .95rem; margin: 12px 0; cursor: pointer; }
    .ck-check input { margin-top: 4px; flex-shrink: 0; }
    .ck-check small { display: block; color: var(--muted); }
    .ck-erro { background: #fff0f2; border: 1px solid #f5c2c7; color: #8a1c1c; border-radius: 12px; padding: 12px 14px; margin: 14px 0; display: none; }
    .ck-erro.on { display: block; }
    .ck-nota { color: var(--muted); font-size: .88rem; }
    .ck-nota a { color: var(--red); text-decoration: underline; }
    .ck-btn { width: 100%; font-size: 1.05rem; }
    .ck-btn[disabled] { opacity: .6; cursor: wait; transform: none; }
    .ck-pix { text-align: center; }
    .ck-pix h2 { color: var(--black); font-size: 1.5rem; letter-spacing: -.02em; margin: 6px 0 8px; }
    .ck-qr { width: 232px; height: 232px; margin: 14px auto; border: 1px solid var(--line); border-radius: 12px; display: flex; align-items: center; justify-content: center; background: #fff; padding: 8px; }
    .ck-qr img, .ck-qr canvas { width: 216px !important; height: 216px !important; display: block; }
    .ck-copia { width: 100%; min-height: 86px; padding: 12px; border: 1px solid var(--line); border-radius: 12px; font: 12px/1.4 ui-monospace, Menlo, Consolas, monospace; word-break: break-all; resize: none; background: var(--soft); color: var(--text); }
    .ck-status { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 999px; background: var(--soft); border: 1px solid var(--line); font-weight: 700; font-size: .92rem; margin-top: 14px; }
    .ck-status .pulso { width: 10px; height: 10px; border-radius: 50%; background: var(--red); animation: ckPulso 1.2s infinite; }
    @keyframes ckPulso { 0%, 100% { opacity: .3; } 50% { opacity: 1; } }
    .ck-bloco { border-top: 1px solid var(--line); padding: 18px 0; }
    .ck-bloco:first-child { border-top: 0; padding-top: 0; }
    .ck-bloco h2 { color: var(--black); font-size: 1.15rem; margin: 0 0 8px; }
    .ck-acesso { background: var(--soft); border: 1px solid var(--line); border-radius: 12px; padding: 16px 18px; margin: 12px 0; }
    .ck-acesso div { margin: 4px 0; }
    .ck-acesso b { color: var(--black); font-size: 1.05rem; }
    .ck-ok { display: inline-flex; align-items: center; gap: 8px; color: #0f7b3e; font-weight: 800; }
    .ck-ok i { font-size: 1.3rem; }
    @media (max-width: 920px) {
      .ck-grid { grid-template-columns: 1fr; }
      .ck-resumo { position: static; }
      .ck-2col, .ck-metodos { grid-template-columns: 1fr; }
      .ck-card { padding: 20px; }
    }
"""

JS_COMUM = r"""
    var API = '__API__';
    function brl(c) { return (c / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
    function q(s, r) { return (r || document).querySelector(s); }
    function param(k) { return new URLSearchParams(location.search).get(k) || ''; }
    function origem() {
      var o = {};
      new URLSearchParams(location.search).forEach(function (v, k) { if (/^(utm_|fbclid$|gclid$)/.test(k)) o[k] = v; });
      try {
        var salvo = JSON.parse(sessionStorage.getItem('mcp_origem') || '{}');
        Object.keys(salvo).forEach(function (k) { if (!o[k]) o[k] = salvo[k]; });
        sessionStorage.setItem('mcp_origem', JSON.stringify(o));
      } catch (e) {}
      return o;
    }
    function rastrear(meta, dados, ga) {
      try { if (window.fbq && meta) fbq('track', meta, dados || {}); } catch (e) {}
      try { if (window.gtag && ga) gtag('event', ga, dados || {}); } catch (e) {}
    }
    async function api(caminho, opcoes) {
      var r, d = null;
      try {
        r = await fetch(API + caminho, Object.assign({ headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' } }, opcoes || {}));
        try { d = await r.json(); } catch (e) {}
        if (!d) d = { ok: false, erro: 'Resposta inesperada do servidor. Tente novamente.' };
        d.http = r.status;
      } catch (e) {
        d = { ok: false, erro: 'Sem conexão. Verifique a internet e tente novamente.', http: 0 };
      }
      return d;
    }
    function textoEscapado(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function painelPix(d, alvo) {
      var copia = d.pix && d.pix.copia_cola ? d.pix.copia_cola : '';
      alvo.innerHTML = '<div class="ck-pix">'
        + '<p class="eyebrow">PIX gerado</p>'
        + '<h2>Pague ' + brl(d.total_centavos) + ' para garantir sua vaga</h2>'
        + '<p class="lead" style="font-size:1rem;max-width:none">Abra o app do seu banco e leia o QR code ou use o <b>PIX Copia e Cola</b>. A confirmação aparece aqui sozinha.</p>'
        + '<div class="ck-qr" id="ck-qr"></div>'
        + '<textarea class="ck-copia" id="ck-copia" readonly>' + textoEscapado(copia) + '</textarea>'
        + '<div class="cta-row" style="justify-content:center;margin-top:12px"><button class="btn btn-red" type="button" id="ck-copiar">Copiar código PIX</button>'
        + (d.pix && d.pix.url ? '<a class="btn btn-outline" href="' + textoEscapado(d.pix.url) + '" target="_blank" rel="noopener">Abrir página do PIX</a>' : '') + '</div>'
        + '<div class="ck-status"><span class="pulso"></span> Aguardando pagamento…</div>'
        + '<p class="ck-nota" style="margin-top:14px">Enviamos este código para <b>' + textoEscapado(d.email) + '</b>. Ele vale por 24 horas. Se fechar esta página, volte pelo link do e-mail.</p>'
        + '</div>';
      var qr = q('#ck-qr', alvo);
      if (window.QRCode && copia) {
        try { new QRCode(qr, { text: copia, width: 216, height: 216, correctLevel: QRCode.CorrectLevel.M }); } catch (e) { qr.innerHTML = ''; }
      }
      if (!qr.firstChild && d.pix && d.pix.imagem) qr.innerHTML = '<img src="' + textoEscapado(d.pix.imagem) + '" alt="QR code do PIX" width="216" height="216">';
      q('#ck-copiar', alvo).addEventListener('click', function () {
        var ta = q('#ck-copia', alvo); ta.select();
        var ok = false;
        try { if (navigator.clipboard) { navigator.clipboard.writeText(copia); ok = true; } else { ok = document.execCommand('copy'); } } catch (e) {}
        this.textContent = ok ? 'Código copiado' : 'Selecione e copie o código';
        var b = this; setTimeout(function () { b.textContent = 'Copiar código PIX'; }, 2500);
      });
    }
    function acompanhar(token, aoPagar, aoFalhar, intervalo) {
      var parado = false;
      async function passo() {
        if (parado) return;
        var d = await api('status.php?t=' + encodeURIComponent(token));
        if (d.status === 'pago') { parado = true; aoPagar(d); return; }
        if (d.status === 'expirado' || d.status === 'recusado' || d.status === 'estornado') { parado = true; aoFalhar(d); return; }
        setTimeout(passo, document.hidden ? (intervalo || 5000) * 3 : (intervalo || 5000));
      }
      setTimeout(passo, intervalo || 5000);
      return function () { parado = true; };
    }
"""

JS_CHECKOUT = r"""
    (function () {
      var info = null, cursos = {};
      var form = q('#ck-form'), sel = q('#ck-curso'), erroEl = q('#ck-erro'), btn = q('#ck-pagar');
      var origemAtual = origem();

      function metodo() { return (form.querySelector('input[name=metodo]:checked') || {}).value || 'pix'; }
      function taxaAtual() { return info ? (metodo() === 'pix' ? info.taxa.pix : info.taxa.cartao) : 0; }
      function atualizar() {
        var c = cursos[sel.value];
        q('#ck-r-curso').textContent = c ? c.nome : '—';
        q('#ck-escolaridade').textContent = c && c.escolaridade ? 'escolaridade mínima: ' + c.escolaridade : 'escolaridade mínima';
        q('#ck-r-curso-valor').textContent = c && c.valor_curso_centavos ? brl(c.valor_curso_centavos) : 'consulte a escola';
        var inscricao = info ? info.inscricao_centavos : 9900;
        var taxa = taxaAtual();
        var cobre = q('#ck-cobre').checked;
        q('#ck-taxa-valor').textContent = '+ ' + brl(taxa);
        q('#ck-r-inscricao').textContent = brl(inscricao);
        q('#ck-r-taxa-linha').hidden = !cobre;
        q('#ck-r-taxa').textContent = brl(taxa);
        var total = inscricao + (cobre ? taxa : 0);
        q('#ck-r-total').textContent = brl(total);
        q('#ck-total-btn').textContent = brl(total);
        q('#ck-cartao').hidden = metodo() !== 'cartao';
        document.querySelectorAll('.ck-metodo').forEach(function (m) { m.classList.toggle('ativo', m.querySelector('input').checked); });
        if (sel.value) { var u = new URL(location.href); u.searchParams.set('curso', sel.value); history.replaceState(null, '', u); }
      }
      function mascara(el, fn) { el.addEventListener('input', function () { var p = el.selectionStart; el.value = fn(el.value); }); }
      var digitos = function (v) { return v.replace(/\D+/g, ''); };
      mascara(q('#ck-cpf'), function (v) { v = digitos(v).slice(0, 11); return v.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2'); });
      mascara(q('#ck-telefone'), function (v) { v = digitos(v).slice(0, 11); if (v.length > 6) return '(' + v.slice(0, 2) + ') ' + v.slice(2, v.length > 10 ? 7 : 6) + '-' + v.slice(v.length > 10 ? 7 : 6); if (v.length > 2) return '(' + v.slice(0, 2) + ') ' + v.slice(2); return v; });
      mascara(q('#ck-cartao-numero'), function (v) { return digitos(v).slice(0, 19).replace(/(\d{4})(?=\d)/g, '$1 '); });
      mascara(q('#ck-cartao-validade'), function (v) { v = digitos(v).slice(0, 4); return v.length > 2 ? v.slice(0, 2) + '/' + v.slice(2) : v; });
      mascara(q('#ck-cartao-cvv'), function (v) { return digitos(v).slice(0, 4); });

      form.addEventListener('change', atualizar);
      sel.addEventListener('change', atualizar);

      function erro(msg, campo) {
        document.querySelectorAll('.ck-campo.erro').forEach(function (c) { c.classList.remove('erro'); });
        if (!msg) { erroEl.className = 'ck-erro'; erroEl.textContent = ''; return; }
        erroEl.textContent = msg; erroEl.className = 'ck-erro on';
        var mapa = { curso: '#ck-curso', nome: '#ck-nome', cpf: '#ck-cpf', email: '#ck-email', telefone: '#ck-telefone', cartao_numero: '#ck-cartao-numero', cartao_nome: '#ck-cartao-nome', cartao_validade: '#ck-cartao-validade', cartao_cvv: '#ck-cartao-cvv' };
        var el = mapa[campo] && q(mapa[campo]);
        if (el) { el.closest('.ck-campo').classList.add('erro'); el.focus(); }
        else erroEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      function cpfValido(cpf) {
        cpf = digitos(cpf); if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
        for (var t = 9; t < 11; t++) { var s = 0; for (var i = 0; i < t; i++) s += parseInt(cpf[i], 10) * ((t + 1) - i); if (parseInt(cpf[t], 10) !== ((10 * s) % 11) % 10) return false; }
        return true;
      }

      form.addEventListener('submit', async function (e) {
        e.preventDefault(); erro('');
        var dados = {
          curso: sel.value, nome: q('#ck-nome').value.trim(), cpf: digitos(q('#ck-cpf').value), email: q('#ck-email').value.trim(),
          telefone: digitos(q('#ck-telefone').value), metodo: metodo(), cobre_taxa: q('#ck-cobre').checked, requisitos: q('#ck-requisitos').checked, origem: origemAtual
        };
        if (!dados.curso) return erro('Escolha o curso.', 'curso');
        if (dados.nome.length < 5 || dados.nome.indexOf(' ') < 0) return erro('Informe seu nome completo.', 'nome');
        if (!cpfValido(dados.cpf)) return erro('CPF inválido. Confira os 11 números.', 'cpf');
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(dados.email)) return erro('E-mail inválido.', 'email');
        if (dados.telefone.length < 10 || dados.telefone.length > 11) return erro('Informe o WhatsApp com DDD.', 'telefone');
        if (dados.metodo === 'cartao') {
          dados.cartao = { numero: digitos(q('#ck-cartao-numero').value), nome: q('#ck-cartao-nome').value.trim(), validade: q('#ck-cartao-validade').value, cvv: q('#ck-cartao-cvv').value };
          if (dados.cartao.numero.length < 13) return erro('Número do cartão inválido.', 'cartao_numero');
          if (dados.cartao.nome.length < 3) return erro('Informe o nome como está no cartão.', 'cartao_nome');
          if (digitos(dados.cartao.validade).length !== 4) return erro('Validade inválida. Use MM/AA.', 'cartao_validade');
          if (digitos(dados.cartao.cvv).length < 3) return erro('Código de segurança inválido.', 'cartao_cvv');
        }
        if (!dados.requisitos) return erro('Confirme que leu os requisitos do curso.');

        btn.disabled = true; var rotulo = btn.innerHTML; btn.textContent = dados.metodo === 'pix' ? 'Gerando o PIX…' : 'Processando o pagamento…';
        rastrear('Lead', { content_name: cursos[dados.curso] ? cursos[dados.curso].nome : dados.curso, content_ids: [dados.curso], content_category: 'matricula-cursos-presenciais' }, 'matricula_dados');
        var r = await api('pagamentos.php', { method: 'POST', body: JSON.stringify(dados) });
        dados.cartao = null;
        if (!r.ok) {
          btn.disabled = false; btn.innerHTML = rotulo;
          return erro(r.erro || 'Não foi possível processar. Tente novamente.', r.campo);
        }
        if (r.status === 'pago') { location.href = r.urls.parabens; return; }
        if (r.metodo === 'pix') {
          form.hidden = true;
          var painel = q('#ck-pix'); painel.hidden = false; painelPix(r, painel);
          rastrear('AddPaymentInfo', { content_ids: [dados.curso], value: r.total_centavos / 100, currency: 'BRL' }, 'matricula_pix_gerado');
          acompanhar(r.token, function (d) { location.href = d.urls.parabens; }, function (d) { location.href = d.urls.pendente; });
          window.scrollTo({ top: painel.getBoundingClientRect().top + window.scrollY - 90, behavior: 'smooth' });
          return;
        }
        // Cartão em análise: acompanha na página de pendente.
        location.href = r.urls.pendente;
      });

      api('info.php').then(function (d) {
        if (!d.ok) { erro('O checkout está indisponível no momento. Tente novamente em instantes.'); btn.disabled = true; return; }
        info = d;
        d.cursos.forEach(function (c) { cursos[c.slug] = c; });
        if (d.teste) q('#ck-teste').hidden = false;
        var pedido = param('curso');
        if (pedido && cursos[pedido]) sel.value = pedido;
        atualizar();
      });
      atualizar();
    })();
"""

JS_PENDENTE = r"""
    (function () {
      var token = param('t'), card = q('#pd-card');
      if (!/^[a-f0-9]{40}$/.test(token)) { card.innerHTML = '<p>Link inválido. <a href="/matricula-cursos-presenciais/">Voltar para os cursos</a>.</p>'; return; }
      function falhou(d) {
        var voltar = '/matricula-cursos-presenciais/checkout/?curso=' + encodeURIComponent(d.curso.slug);
        if (d.status === 'expirado') card.innerHTML = '<h2>Este código PIX venceu</h2><p class="lead" style="font-size:1rem">Nenhum valor foi cobrado. Gere um novo código para continuar a matrícula em <b>' + textoEscapado(d.curso.nome) + '</b>.</p><div class="cta-row"><a class="btn btn-red" href="' + voltar + '">Gerar novo PIX</a></div>';
        else if (d.status === 'recusado') card.innerHTML = '<h2>Pagamento recusado</h2><p class="lead" style="font-size:1rem">O emissor não autorizou a cobrança. Nenhum valor foi cobrado. Tente outro cartão ou pague com PIX.</p><div class="cta-row"><a class="btn btn-red" href="' + voltar + '">Tentar de novo</a></div>';
        else card.innerHTML = '<h2>Pagamento estornado</h2><p class="lead" style="font-size:1rem">Esta inscrição foi estornada. Se quiser refazer a matrícula, use o botão abaixo.</p><div class="cta-row"><a class="btn btn-outline" href="' + voltar + '">Nova inscrição</a></div>';
      }
      api('status.php?t=' + encodeURIComponent(token)).then(function (d) {
        if (!d.ok) { card.innerHTML = '<p>' + textoEscapado(d.erro || 'Inscrição não encontrada.') + ' <a href="/matricula-cursos-presenciais/">Voltar para os cursos</a>.</p>'; return; }
        if (d.teste) q('#ck-teste').hidden = false;
        if (d.status === 'pago') { location.replace(d.urls.parabens); return; }
        if (d.status !== 'pendente') { falhou(d); return; }
        if (d.metodo === 'pix') painelPix(d, card);
        else card.innerHTML = '<h2>Cartão em análise</h2><p class="lead" style="font-size:1rem">A operadora ainda está processando a cobrança de ' + brl(d.total_centavos) + ' para <b>' + textoEscapado(d.curso.nome) + '</b>. Esta página atualiza sozinha.</p><div class="ck-status"><span class="pulso"></span> Aguardando confirmação…</div>';
        acompanhar(token, function (x) { location.href = x.urls.parabens; }, falhou);
      });
    })();
"""

JS_PARABENS = r"""
    (function () {
      var token = param('t'), card = q('#pb-card');
      if (!/^[a-f0-9]{40}$/.test(token)) { card.innerHTML = '<p>Link inválido. <a href="/matricula-cursos-presenciais/">Voltar para os cursos</a>.</p>'; return; }
      function render(d) {
        var metodo = d.metodo === 'pix' ? 'PIX' : 'cartão' + (d.cartao && d.cartao.ultimos4 ? ' final ' + d.cartao.ultimos4 : '');
        var html = '<div class="ck-bloco"><p class="ck-ok"><i class="fa-solid fa-circle-check"></i> Inscrição paga</p><h2>' + textoEscapado(d.curso.nome) + ' · ' + brl(d.total_centavos) + '</h2><p class="ck-nota">Pago por ' + metodo + (d.taxa_centavos ? ', incluindo ' + brl(d.taxa_centavos) + ' de custos de processamento que você escolheu cobrir. Obrigado.' : '.') + '</p></div>';
        var e = d.escola || {};
        if (e.usuario || e.url) {
          html += '<div class="ck-bloco"><h2>Seu acesso à secretaria da escola</h2><div class="ck-acesso"><div>Usuário: <b>' + textoEscapado(e.usuario || d.email) + '</b></div>'
            + (e.senha ? '<div>Senha temporária: <b>' + textoEscapado(e.senha) + '</b> <span class="ck-nota">(troque no primeiro acesso)</span></div>' : '')
            + '</div><div class="cta-row"><a class="btn btn-red" href="' + textoEscapado(e.url || d.escola_url) + '">Acessar ambiente da secretaria</a></div><p class="ck-nota" style="margin-top:12px">Horário e turma você escolhe lá. A inscrição reserva sua vaga; se não houver horário compatível ou você desistir antes da confirmação da aula, o valor é estornado.</p></div>';
        } else if (e.configurada && e.status !== 'ok') {
          html += '<div class="ck-bloco"><h2>Pagamento confirmado, acesso em instantes</h2><p>Estamos criando sua matrícula na escola. O acesso aparece aqui e chega no seu e-mail em instantes.</p><div class="ck-status"><span class="pulso"></span> Gerando acesso…</div></div>';
          setTimeout(function () { api('status.php?t=' + encodeURIComponent(token)).then(render); }, 15000);
        } else {
          html += '<div class="ck-bloco"><h2>Próximo passo: a secretaria fala com você</h2><p><b>A secretaria da Escola entra em contato pelo WhatsApp em até 2 dias úteis</b> para fechar turma e horário. Você não precisa se inscrever de novo na plataforma.</p><p class="ck-nota">O valor do curso é pago depois, na plataforma da escola. A inscrição reserva sua vaga; se não houver horário compatível ou você desistir antes da confirmação da aula, o valor é estornado.</p><div class="cta-row"><a class="btn btn-outline" href="' + textoEscapado(d.escola_url) + '" target="_blank" rel="noopener">Conhecer a plataforma da escola</a></div></div>';
        }
        html += '<div class="ck-bloco"><p class="ck-nota" style="margin:0">Mandamos a confirmação para <b>' + textoEscapado(d.email) + '</b>. Guarde este link: <a href="' + textoEscapado(d.urls.parabens) + '">' + textoEscapado(d.urls.parabens) + '</a></p></div>';
        card.innerHTML = html;
      }
      api('status.php?t=' + encodeURIComponent(token)).then(function (d) {
        if (!d.ok) { card.innerHTML = '<p>' + textoEscapado(d.erro || 'Inscrição não encontrada.') + ' <a href="/matricula-cursos-presenciais/">Voltar para os cursos</a>.</p>'; return; }
        if (d.teste) q('#ck-teste').hidden = false;
        if (d.status !== 'pago') { location.replace(d.status === 'pendente' ? d.urls.pendente : '/matricula-cursos-presenciais/checkout/?curso=' + encodeURIComponent(d.curso.slug)); return; }
        render(d);
        try {
          var chave = 'mcp_purchase_' + token;
          if (!localStorage.getItem(chave)) {
            localStorage.setItem(chave, '1');
            rastrear('Purchase', { content_name: d.curso.nome, content_ids: [d.curso.slug], content_type: 'product', value: d.total_centavos / 100, currency: 'BRL' }, 'purchase');
          }
        } catch (e) {}
      });
    })();
"""

PAGINA = """<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <title>@@TITULO@@</title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
@@QRCODE@@
  <!-- Estilos copiados da home (site/index.html): mesmo padrão visual da filial. -->
@@ESTILO@@
  <style>@@CSS@@  </style>
@@GA4@@
@@PIXEL@@
</head>
<body>
  <script>document.documentElement.classList.add('js');</script>
@@HEADER@@

  <main id="@@ID@@">
    <div class="ck-teste" id="ck-teste" hidden>Modo de teste: o valor cobrado não é o preço da inscrição.</div>
    <section class="ck-hero">
      <div class="wrap">
        <p class="eyebrow">Matrícula cursos presenciais</p>
        <h1>@@H1@@</h1>
        <p class="lead">@@LEAD@@</p>
      </div>
    </section>
    <section class="ck-secao">
      <div class="wrap"@@WRAP_EXTRA@@>
@@CORPO@@
      </div>
    </section>
  </main>

@@FOOTER@@

@@MENU_JS@@
  <script>
@@JS_COMUM@@
@@JS@@
  </script>
</body>
</html>
"""

CORPO_CHECKOUT = """        <div class="ck-grid">
          <div class="ck-card">
            <form id="ck-form" novalidate autocomplete="on">
              <label class="ck-campo"><span>Curso</span>
                <select id="ck-curso" name="curso" required>
                  <option value="" disabled selected>Escolha o curso</option>@@OPCOES@@
                </select>
              </label>
              <label class="ck-campo"><span>Nome completo</span><input id="ck-nome" name="nome" autocomplete="name" required></label>
              <div class="ck-2col">
                <label class="ck-campo"><span>CPF</span><input id="ck-cpf" name="cpf" inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" required></label>
                <label class="ck-campo"><span>WhatsApp</span><input id="ck-telefone" name="telefone" inputmode="tel" autocomplete="tel" placeholder="(21) 99999-9999" required></label>
              </div>
              <label class="ck-campo"><span>E-mail</span><input id="ck-email" type="email" name="email" autocomplete="email" required><small class="ck-nota">A confirmação e o acesso chegam neste e-mail.</small></label>
              <p class="ck-campo" style="margin-bottom:6px"><span>Forma de pagamento</span></p>
              <div class="ck-metodos">
                <label class="ck-metodo ativo"><input type="radio" name="metodo" value="pix" checked><span><b>PIX</b><small>Confirmação em segundos</small></span></label>
                <label class="ck-metodo"><input type="radio" name="metodo" value="cartao"><span><b>Cartão de crédito</b><small>À vista</small></span></label>
              </div>
              <div id="ck-cartao" hidden>
                <label class="ck-campo"><span>Número do cartão</span><input id="ck-cartao-numero" inputmode="numeric" autocomplete="cc-number" placeholder="0000 0000 0000 0000"></label>
                <label class="ck-campo"><span>Nome como está no cartão</span><input id="ck-cartao-nome" autocomplete="cc-name"></label>
                <div class="ck-2col">
                  <label class="ck-campo"><span>Validade</span><input id="ck-cartao-validade" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/AA"></label>
                  <label class="ck-campo"><span>CVV</span><input id="ck-cartao-cvv" inputmode="numeric" autocomplete="cc-csc" placeholder="123"></label>
                </div>
                <p class="ck-nota">Os dados do cartão vão direto para o processador de pagamento e não ficam guardados no site.</p>
              </div>
              <label class="ck-check"><input type="checkbox" id="ck-cobre" name="cobre_taxa"><span>Quero cobrir os custos de processamento (<b id="ck-taxa-valor">+ R$ 0,00</b>)<small>Opcional. Assim a Cruz Vermelha recebe o valor integral da inscrição.</small></span></label>
              <label class="ck-check"><input type="checkbox" id="ck-requisitos" name="requisitos" required><span>Li os requisitos do curso (<span id="ck-escolaridade">escolaridade mínima</span>) e confirmo que os atendo.</span></label>
              <div class="ck-erro" id="ck-erro" role="alert"></div>
              <button class="btn btn-red ck-btn" type="submit" id="ck-pagar">Pagar inscrição · <span id="ck-total-btn">R$ 99,00</span></button>
              <p class="ck-nota" style="margin:12px 0 0">Seus dados são usados só para a matrícula e a cobrança. <a href="/privacidade">Política de privacidade</a>.</p>
            </form>
            <div id="ck-pix" hidden></div>
          </div>
          <aside class="ck-card ck-resumo">
            <h2>Resumo</h2>
            <div class="ck-linha"><span>Curso</span><b id="ck-r-curso">—</b></div>
            <div class="ck-linha"><span>Inscrição</span><span id="ck-r-inscricao">R$ 99,00</span></div>
            <div class="ck-linha" id="ck-r-taxa-linha" hidden><span>Custos de processamento</span><span id="ck-r-taxa">R$ 0,00</span></div>
            <div class="ck-linha total"><span>Total agora</span><span id="ck-r-total">R$ 99,00</span></div>
            <p class="ck-nota">O valor do curso (<span id="ck-r-curso-valor">—</span>) é pago depois, na plataforma da escola.</p>
            <p class="ck-nota">A inscrição reserva sua vaga. Se não houver horário compatível ou você desistir antes da confirmação da aula, o valor é estornado.</p>
          </aside>
        </div>"""

CORPO_PENDENTE = """        <div class="ck-card" id="pd-card"><p>Carregando…</p></div>"""
CORPO_PARABENS = """        <div class="ck-card" id="pb-card"><p>Carregando…</p></div>"""

QRCODE = '  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>'


def brl(centavos: int) -> str:
    return "R$ " + f"{centavos / 100:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")


def montar(partes: dict, titulo: str, id_: str, h1: str, lead: str, corpo: str, js: str, qrcode: bool, wrap_extra: str = "") -> str:
    return (PAGINA
            .replace("@@TITULO@@", esc(titulo)).replace("@@QRCODE@@", QRCODE if qrcode else "")
            .replace("@@ESTILO@@", partes["estilo"]).replace("@@CSS@@", CSS)
            .replace("@@GA4@@", partes["ga4"]).replace("@@PIXEL@@", partes["pixel"])
            .replace("@@HEADER@@", partes["header"]).replace("@@FOOTER@@", partes["footer"]).replace("@@MENU_JS@@", partes["menu_js"])
            .replace("@@ID@@", id_).replace("@@H1@@", h1).replace("@@LEAD@@", lead).replace("@@WRAP_EXTRA@@", wrap_extra)
            .replace("@@CORPO@@", corpo).replace("@@JS_COMUM@@", JS_COMUM.replace("__API__", API)).replace("@@JS@@", js))


def main() -> int:
    home = HOME.read_text(encoding="utf-8")
    dados = json.loads(DADOS.read_text(encoding="utf-8"))
    cursos = {c["slug"]: c for c in dados["cursos"]}
    partes = partes_da_home(home)
    inscricao = brl(dados["inscricao_centavos"])

    opcoes = ""
    for g in dados["grupos"]:
        itens = "".join(f'\n                    <option value="{s}">{esc(cursos[s]["nome"])} · {esc(cursos[s]["carga_horaria"])}</option>' for s in g["cursos"] if s in cursos)
        opcoes += f'\n                  <optgroup label="{esc(g["titulo"])}">{itens}\n                  </optgroup>'

    paginas = {
        "checkout": montar(partes, "Pagar a inscrição | Cruz Vermelha Brasileira RJ", "checkout",
                           "Pagar a inscrição e garantir a vaga",
                           f"Inscrição de {inscricao}, por PIX ou cartão. Sem criar conta e sem escolher turma agora; a secretaria confirma o horário depois.",
                           CORPO_CHECKOUT.replace("@@OPCOES@@", opcoes), JS_CHECKOUT, qrcode=True),
        "pendente": montar(partes, "Pagamento ainda não confirmado | Cruz Vermelha Brasileira RJ", "pendente",
                           "Pagamento ainda não confirmado",
                           "Esta tela não cria login. Quando o pagamento for aprovado, a matrícula é aberta e os dados aparecem aqui e no seu e-mail.",
                           CORPO_PENDENTE, JS_PENDENTE, qrcode=True, wrap_extra=' style="max-width:820px"'),
        "parabens": montar(partes, "Inscrição paga | Cruz Vermelha Brasileira RJ", "parabens",
                           "Parabéns, sua inscrição está paga.",
                           "Guarde este link: ele mostra sua inscrição e o próximo passo.",
                           CORPO_PARABENS, JS_PARABENS, qrcode=False, wrap_extra=' style="max-width:820px"'),
    }
    for nome, html in paginas.items():
        destino = PASTA / nome / "index.html"
        destino.parent.mkdir(parents=True, exist_ok=True)
        destino.write_text(html, encoding="utf-8")
        print(f"gravado {destino.relative_to(RAIZ)} ({len(html.encode('utf-8'))} bytes)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
