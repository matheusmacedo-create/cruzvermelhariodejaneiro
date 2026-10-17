#!/usr/bin/env python3
"""Gera site/gestao/: "Como funciona a nossa gestão" (17/10/2026).

Pedido do Matheus: explicar, com gráfico e imagens, como funciona hoje a gestão da filial e como a criptografia e
a inteligência artificial tornaram isso possível. Tudo o que a página afirma tem lastro no que está no ar:

- a trilha pública de auditoria (Redação): cada documento oficial registrado com impressão digital SHA-256, um
  lote por dia com carimbo de tempo RFC 3161 (FreeTSA), âncora no Bitcoin (OpenTimestamps) e manifesto assinado
  (Ed25519); os arquivos de prova ficam em /verificar/lotes/ (README, seção da verificação);
- o gráfico lê /verificar/lotes/indice.json no navegador (mesma origem). O gerador grava uma cópia do índice do dia
  da geração na página, que vale se a leitura falhar; o encadeamento ("anterior" de um dia = "compromisso" do dia
  anterior) foi conferido no manifesto de 16/10/2026;
- os sistemas citados em "O que já funciona assim" estão publicados (portal, verificação, canais oficiais, pedido de
  documentos, acervo, ponto da sede, cadastro de voluntários, atendimento por e-mail);
- a parte da IA diz só o que é fato: os sistemas foram construídos e são mantidos com assistentes de IA, sob
  direção e revisão da equipe; a IA não decide, não aprova, não assina; a confiança vem da matemática.

Sem pedir doação, sem WhatsApp, sem CNPJ/recursos públicos (a filial está regularizando o CNPJ).

Cabeçalho, rodapé, CSS, GA4, Pixel (com o aviso de cookies) e o script do menu vêm de site/index.html, como nos
outros geradores; o chat entra no fim. O texto digitado na demonstração da impressão digital não sai do aparelho
(crypto.subtle no navegador) e não gera evento de medição.

Uso:  python3 scripts/gerar_gestao.py
"""
from __future__ import annotations

import json
import urllib.request

import chat_widget
import icones
from gerar_matricula_presencial import HOME, RAIZ, esc, partes_da_home

PASTA = RAIZ / "site" / "gestao"
SAIDA = PASTA / "index.html"
ORIGEM = "https://cruzvermelhariodejaneiro.org"
URL_PAGINA = f"{ORIGEM}/gestao/"
URL_INDICE = f"{ORIGEM}/verificar/lotes/indice.json"
TITULO = "Como funciona a nossa gestão – Cruz Vermelha Brasileira Rio de Janeiro"
DESCRICAO = ("Como a Cruz Vermelha Brasileira Rio de Janeiro registra seus documentos oficiais de um jeito que qualquer "
             "pessoa confere: impressão digital, selo diário no Bitcoin e o papel da inteligência artificial.")
IMAGEM_OG = f"{ORIGEM}/assets/otim/og-home.jpg"
ESTATUTO_SHA = "1ad2bd0bdafbba622fa0b6b553053444d4bdcc2c9ee69b4fd08bf4d92d88b601"


def indice_atual() -> list[dict]:
    """O índice de lotes no ar, só com os campos que a página usa (cópia de reserva para o gráfico)."""
    pedido = urllib.request.Request(URL_INDICE, headers={"User-Agent": "cvb-rj-gerador/1.0"})
    with urllib.request.urlopen(pedido, timeout=30) as r:
        dados = json.load(r)
    lotes = [{"dia": d["dia"], "bloco": d.get("bloco"), "tsa": bool(d.get("tsa")), "compromisso": d["compromisso"]}
             for d in dados if isinstance(d, dict) and d.get("dia") and d.get("compromisso")]
    if len(lotes) < 2:
        raise SystemExit("o índice de lotes veio vazio; a página precisa de pelo menos dois dias para mostrar a corrente")
    return sorted(lotes, key=lambda d: d["dia"])


CSS = """  <style>
    #gestao { --gs-suave: #5b6576; --gs-ok: #0ca30c; --gs-ok-texto: #1d6b1d; }
    .gs-topo { background: linear-gradient(180deg, #fff 0%, var(--soft) 100%); border-bottom: 1px solid var(--line); padding: 56px 0 60px; }
    .gs-topo-grade { display: grid; grid-template-columns: 1.1fr .9fr; gap: 48px; align-items: center; }
    .gs-topo h1 { color: var(--black); font-size: clamp(2rem, 4.6vw, 3.2rem); line-height: 1.05; letter-spacing: -.03em; margin: 10px 0 16px; }
    .gs-topo .lead { color: #4a5565; font-size: 1.1rem; }
    .gs-foto { margin: 0; }
    .gs-foto img { display: block; width: 100%; height: auto; border-radius: 16px; box-shadow: 0 18px 40px rgba(0,0,0,.14); }
    .gs-foto figcaption { color: var(--gs-suave); font-size: .86rem; margin-top: 10px; }
    .gs-numeros { list-style: none; padding: 0; margin: 26px 0 0; display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
    .gs-numeros li { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; }
    .gs-numeros b { display: block; font-size: 1.7rem; line-height: 1.1; color: var(--black); font-variant-numeric: tabular-nums; }
    .gs-numeros span { color: var(--gs-suave); font-size: .86rem; line-height: 1.35; display: block; margin-top: 4px; }
    .gs-secao { padding: 64px 0; }
    .gs-secao + .gs-secao { border-top: 1px solid var(--line); }
    .gs-secao-suave { background: var(--soft); }
    .gs-secao h2 { font-size: clamp(1.6rem, 3.2vw, 2.3rem); margin: .2rem 0 .8rem; color: var(--black); }
    .gs-secao .lead { max-width: 68ch; color: #4a5565; }
    /* Caminho de um documento: cinco passos ligados por seta. */
    .gs-caminho { list-style: none; padding: 0; margin: 30px 0 0; display: grid; grid-template-columns: repeat(5, 1fr); gap: 14px; counter-reset: passo; }
    .gs-caminho li { position: relative; background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 18px 16px 16px; counter-increment: passo; }
    .gs-caminho li:not(:last-child)::after { content: ""; position: absolute; top: 34px; right: -13px; width: 12px; height: 12px; border-top: 2px solid var(--red); border-right: 2px solid var(--red); transform: rotate(45deg); }
    .gs-caminho .gs-icone { width: 44px; height: 44px; border-radius: 50%; background: #fff1f1; color: var(--red); display: inline-flex; align-items: center; justify-content: center; font-size: 1.2rem; }
    .gs-caminho h3 { font-size: 1rem; margin: 12px 0 6px; color: var(--black); }
    .gs-caminho h3::before { content: counter(passo) ". "; color: var(--red); }
    .gs-caminho p { margin: 0; color: #4a5565; font-size: .92rem; line-height: 1.5; }
    /* Demonstração da impressão digital. */
    .gs-demo { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; align-items: start; margin-top: 26px; }
    .gs-demo-caixa { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 20px; }
    .gs-demo label { font-weight: 700; display: block; margin-bottom: 8px; }
    .gs-demo textarea { width: 100%; min-height: 110px; border: 1px solid #cbd5e0; border-radius: 10px; padding: 12px; font: inherit; resize: vertical; }
    .gs-demo textarea:focus { outline: 3px solid rgba(204,0,0,.25); border-color: var(--red); }
    .gs-hash { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .92rem; line-height: 1.55; word-break: break-all; background: #0f1318; color: #e2e8f0; border-radius: 10px; padding: 14px; margin: 10px 0 0; min-height: 4.8em; }
    .gs-hash .gs-mudou { color: #ff8a8a; }
    .gs-demo-botoes { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
    .gs-demo-botoes button { cursor: pointer; }
    .gs-nota { color: var(--gs-suave); font-size: .88rem; line-height: 1.5; margin: 10px 0 0; }
    .gs-demo ul { margin: 0; padding-left: 20px; line-height: 1.6; color: #2d3748; }
    .gs-demo code { font-size: .85rem; word-break: break-all; }
    /* Três selos. */
    .gs-selos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 26px; }
    .gs-selo { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 22px; }
    .gs-selo .gs-icone { width: 48px; height: 48px; border-radius: 12px; background: var(--red); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 1.3rem; }
    .gs-selo h3 { margin: 14px 0 6px; font-size: 1.08rem; color: var(--black); }
    .gs-selo p { margin: 0 0 8px; color: #4a5565; line-height: 1.55; font-size: .95rem; }
    .gs-selo small { color: var(--gs-suave); }
    /* A corrente ao vivo. */
    .gs-cadeia { display: flex; align-items: stretch; gap: 0; margin: 28px 0 8px; overflow-x: auto; padding-bottom: 6px; }
    .gs-bloco { flex: 1 0 210px; background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; font-size: .88rem; }
    .gs-bloco b { display: block; font-size: 1rem; color: var(--black); margin-bottom: 6px; }
    .gs-bloco dl { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 3px 8px; }
    .gs-bloco dt { color: var(--gs-suave); }
    .gs-bloco dd { margin: 0; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .82rem; }
    .gs-bloco dd.gs-liga { color: var(--red); font-weight: 700; }
    .gs-elo { flex: 0 0 34px; display: flex; align-items: center; justify-content: center; color: var(--red); font-size: 1.2rem; }
    .gs-grafico { margin-top: 30px; background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 20px; }
    .gs-grafico h3 { margin: 0 0 4px; font-size: 1.05rem; color: var(--black); }
    .gs-grafico > p { margin: 0 0 14px; color: var(--gs-suave); font-size: .9rem; }
    .gs-dias { display: grid; grid-template-columns: repeat(auto-fill, minmax(30px, 1fr)); gap: 4px; }
    .gs-dia { position: relative; aspect-ratio: 1; border-radius: 4px; border: 0; padding: 0; display: flex; align-items: center; justify-content: center; font-size: .72rem; cursor: default; color: #fff; }
    .gs-dia:focus-visible { outline: 3px solid var(--black); outline-offset: 2px; }
    .gs-dia-ok { background: var(--gs-ok); }
    .gs-dia-aberto { background: #fff; border: 2px dashed #a0aec0; color: #4a5565; }
    .gs-dia-falta { background: #fff; border: 2px solid #d03b3b; color: #d03b3b; }
    .gs-dia .ico { width: .9em; height: .9em; }
    .gs-eixo { display: flex; justify-content: space-between; color: var(--gs-suave); font-size: .8rem; margin-top: 8px; }
    .gs-legenda { display: flex; flex-wrap: wrap; gap: 16px; margin-top: 12px; font-size: .86rem; color: #2d3748; }
    .gs-legenda span { display: inline-flex; align-items: center; gap: 6px; }
    .gs-legenda i.gs-amostra { width: 14px; height: 14px; border-radius: 3px; display: inline-block; }
    .gs-dica { position: absolute; z-index: 5; pointer-events: none; background: #0f1318; color: #fff; font-size: .8rem; line-height: 1.4; padding: 8px 10px; border-radius: 8px; white-space: nowrap; box-shadow: 0 8px 20px rgba(0,0,0,.2); }
    .gs-tabela-lotes { margin-top: 14px; }
    .gs-tabela-lotes summary { cursor: pointer; font-weight: 700; color: var(--red); }
    .gs-tabela-lotes table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: .88rem; }
    .gs-tabela-lotes th, .gs-tabela-lotes td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--line); }
    .gs-tabela-lotes td:nth-child(2) { font-variant-numeric: tabular-nums; }
    .gs-ao-vivo { display: inline-flex; align-items: center; gap: 6px; font-size: .8rem; color: var(--gs-ok-texto); font-weight: 700; }
    .gs-ao-vivo[hidden] { display: none; }
    .gs-ao-vivo::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: var(--gs-ok); }
    /* O que já funciona. */
    .gs-grade-cartoes { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 24px; }
    .gs-cartao { display: block; background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 20px; color: inherit; transition: border-color .15s, transform .15s; }
    a.gs-cartao:hover { border-color: var(--red); transform: translateY(-2px); }
    .gs-cartao i { color: var(--red); font-size: 1.3rem; }
    .gs-cartao h3 { margin: 10px 0 6px; font-size: 1.02rem; color: var(--black); }
    .gs-cartao p { margin: 0; color: #4a5565; font-size: .92rem; line-height: 1.5; }
    .gs-subtitulo { margin: 36px 0 0; font-size: 1.15rem; color: var(--black); }
    /* IA. */
    .gs-ia { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-top: 26px; }
    .gs-ia-coluna { border-radius: 14px; padding: 22px; border: 1px solid var(--line); background: #fff; }
    .gs-ia-coluna h3 { margin: 0 0 12px; display: flex; gap: 10px; align-items: center; font-size: 1.1rem; color: var(--black); }
    .gs-ia-coluna ul { margin: 0; padding: 0; list-style: none; display: grid; gap: 10px; }
    .gs-ia-coluna li { display: flex; gap: 10px; line-height: 1.5; color: #2d3748; }
    .gs-ia-coluna li i { flex-shrink: 0; margin-top: 3px; }
    .gs-ia-sim h3 i, .gs-ia-sim li i { color: var(--gs-ok-texto); }
    .gs-ia-nao h3 i, .gs-ia-nao li i { color: var(--red); }
    .gs-porque { margin-top: 18px; background: #0f1318; color: #e2e8f0; border-radius: 14px; padding: 24px; line-height: 1.6; }
    .gs-porque h3 { color: #fff; margin: 0 0 8px; font-size: 1.1rem; }
    .gs-porque p { margin: 0 0 8px; }
    /* Prova / não prova. */
    .gs-limites { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-top: 24px; }
    .gs-limites > div { border-radius: 14px; padding: 22px; border: 1px solid var(--line); background: #fff; }
    .gs-limites h3 { margin: 0 0 10px; font-size: 1.05rem; color: var(--black); }
    .gs-limites ul { margin: 0; padding-left: 20px; line-height: 1.65; color: #2d3748; }
    .gs-acoes { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 26px; }
    @media (max-width: 920px) {
      .gs-topo-grade, .gs-demo, .gs-ia, .gs-limites { grid-template-columns: 1fr; }
      .gs-caminho { grid-template-columns: 1fr; }
      .gs-caminho li:not(:last-child)::after { top: auto; bottom: -13px; right: auto; left: 34px; transform: rotate(135deg); }
      .gs-selos, .gs-grade-cartoes { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 620px) {
      .gs-numeros, .gs-selos, .gs-grade-cartoes { grid-template-columns: 1fr; }
      .gs-acoes .btn { width: 100%; }
      .gs-bloco { flex-basis: 190px; }
    }
  </style>"""

JS = """  <script>
  (function () {
    // ---- impressão digital (SHA-256 no navegador; o texto não sai do aparelho) ----
    var campo = document.getElementById('gs-texto'), saida = document.getElementById('gs-hash');
    var anterior = '';
    function hex(buf) { return Array.prototype.map.call(new Uint8Array(buf), function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
    function calcular() {
      if (!window.crypto || !crypto.subtle) { saida.textContent = 'Seu navegador não permite o cálculo aqui.'; return; }
      crypto.subtle.digest('SHA-256', new TextEncoder().encode(campo.value)).then(function (b) {
        var h = hex(b); saida.textContent = '';
        for (var i = 0; i < h.length; i++) {
          var s = document.createElement('span'); s.textContent = h[i];
          if (anterior && anterior[i] !== h[i]) s.className = 'gs-mudou';
          saida.appendChild(s);
        }
        var mudou = anterior ? h.split('').filter(function (c, i) { return c !== anterior[i]; }).length : 0;
        document.getElementById('gs-mudancas').textContent = anterior ? mudou + ' de 64 caracteres mudaram (em vermelho).' : '';
        anterior = h;
      });
    }
    if (campo) {
      campo.addEventListener('input', calcular);
      document.getElementById('gs-virgula').addEventListener('click', function () {
        var t = campo.value, i = t.indexOf(',');
        campo.value = i >= 0 ? t.slice(0, i) + t.slice(i + 1) : t + ','; calcular();
      });
      document.getElementById('gs-restaurar').addEventListener('click', function () { campo.value = campo.defaultValue; anterior = ''; calcular(); });
      calcular();
    }

    // ---- a corrente ao vivo ----
    var reserva = __INDICE__;
    var MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    function hojeBRT() { return new Date(Date.now() - 3 * 3600 * 1000).toISOString().slice(0, 10); }
    function maisUm(d) { var x = new Date(d + 'T12:00:00Z'); x.setUTCDate(x.getUTCDate() + 1); return x.toISOString().slice(0, 10); }
    function curta(d) { return +d.slice(8, 10) + ' ' + MESES[+d.slice(5, 7) - 1]; }
    function br(d) { return d.slice(8, 10) + '/' + d.slice(5, 7) + '/' + d.slice(0, 4); }
    function milhar(n) { return String(n).replace(/\\B(?=(\\d{3})+(?!\\d))/g, '.'); }
    function icone(nome) { return '<svg class="ico" aria-hidden="true" focusable="false"><use href="#i-' + nome + '"/></svg>'; }

    function desenhar(lotes, aoVivo) {
      lotes = lotes.filter(function (l) { return l && /^\\d{4}-\\d{2}-\\d{2}$/.test(l.dia) && /^[0-9a-f]{64}$/.test(l.compromisso); })
        .sort(function (a, b) { return a.dia < b.dia ? -1 : 1; });
      if (lotes.length < 2) return;
      var porDia = {}; lotes.forEach(function (l) { porDia[l.dia] = l; });
      var primeiro = lotes[0].dia, hoje = hojeBRT(), dias = [];
      for (var d = primeiro; d <= hoje; d = maisUm(d)) dias.push(d);
      // dias seguidos fechados, contando para trás a partir de ontem
      var seguidos = 0;
      for (var k = dias.length - 1; k >= 0; k--) { if (dias[k] === hoje && !porDia[hoje]) continue; if (porDia[dias[k]]) seguidos++; else break; }
      var ultimo = lotes[lotes.length - 1];
      document.querySelector('[data-gs="seguidos"]').textContent = seguidos;
      document.querySelector('[data-gs="ultimo"]').textContent = curta(ultimo.dia);
      document.querySelector('[data-gs="bloco"]').textContent = 'último dia selado' + (ultimo.bloco ? ', no bloco ' + milhar(ultimo.bloco) + ' do Bitcoin' : ', prova do Bitcoin em confirmação');
      document.querySelector('[data-gs="desde"]').textContent = br(primeiro);
      if (aoVivo) document.querySelector('[data-gs="aovivo"]').hidden = false;

      // corrente: os três últimos dias, cada um apontando para o anterior
      var ult = lotes.slice(-3), cadeia = document.getElementById('gs-cadeia'), html = '';
      ult.forEach(function (l, i) {
        var ant = lotes[lotes.indexOf(l) - 1];
        if (i > 0) html += '<div class="gs-elo" aria-hidden="true">' + icone('link') + '</div>';
        html += '<div class="gs-bloco"><b>Lote de ' + br(l.dia) + '</b><dl>'
          + '<dt>Resumo do dia</dt><dd>' + l.compromisso.slice(0, 12) + '…</dd>'
          + '<dt>Aponta para</dt><dd class="' + (i > 0 ? 'gs-liga' : '') + '">' + (ant ? ant.compromisso.slice(0, 12) + '…' : '—') + '</dd>'
          + '<dt>Bitcoin</dt><dd>' + (l.bloco ? 'bloco ' + milhar(l.bloco) : 'em confirmação') + '</dd></dl></div>';
      });
      cadeia.innerHTML = html;

      // grade de dias
      var grade = document.getElementById('gs-dias'); grade.innerHTML = '';
      dias.forEach(function (d) {
        var l = porDia[d], b = document.createElement('button');
        b.type = 'button';
        var estado = l ? 'ok' : (d === hoje ? 'aberto' : 'falta');
        b.className = 'gs-dia gs-dia-' + estado;
        b.innerHTML = icone(estado === 'ok' ? 'check' : (estado === 'aberto' ? 'clock' : 'xmark'));
        var texto = br(d) + ' · ' + (estado === 'ok'
          ? 'selado' + (l.tsa ? ' com carimbo de tempo' : '') + (l.bloco ? ' e no bloco ' + milhar(l.bloco) + ' do Bitcoin' : ', Bitcoin em confirmação')
          : estado === 'aberto' ? 'dia em andamento: o lote fecha à meia-noite' : 'sem lote publicado');
        b.setAttribute('aria-label', texto); b.dataset.dica = texto;
        grade.appendChild(b);
      });
      document.getElementById('gs-eixo-ini').textContent = curta(primeiro);
      document.getElementById('gs-eixo-fim').textContent = curta(hoje);

      // tabela (a mesma informação, para quem não vê o gráfico)
      var corpo = document.getElementById('gs-tabela-corpo'), linhas = '';
      lotes.slice().reverse().forEach(function (l) {
        linhas += '<tr><td>' + br(l.dia) + '</td><td>' + (l.bloco ? milhar(l.bloco) : 'em confirmação') + '</td><td>' + (l.tsa ? 'sim' : 'não') + '</td>'
          + '<td><a href="/verificar/lotes/' + l.dia + '/manifesto.json">manifesto</a></td></tr>';
      });
      corpo.innerHTML = linhas;
    }

    // dica ao passar o mouse ou focar um dia
    var dica = document.createElement('div'); dica.className = 'gs-dica'; dica.hidden = true;
    var grafico = document.querySelector('.gs-grafico'); if (grafico) { grafico.style.position = 'relative'; grafico.appendChild(dica); }
    function mostrar(ev) {
      var alvo = ev.target.closest && ev.target.closest('.gs-dia'); if (!alvo) { dica.hidden = true; return; }
      dica.textContent = alvo.dataset.dica; dica.hidden = false;
      var g = grafico.getBoundingClientRect(), r = alvo.getBoundingClientRect();
      var x = r.left - g.left + r.width / 2 - dica.offsetWidth / 2;
      dica.style.left = Math.max(8, Math.min(x, g.width - dica.offsetWidth - 8)) + 'px';
      dica.style.top = (r.top - g.top - dica.offsetHeight - 8) + 'px';
    }
    if (grafico) {
      grafico.addEventListener('mouseover', mostrar); grafico.addEventListener('focusin', mostrar);
      grafico.addEventListener('mouseleave', function () { dica.hidden = true; }); grafico.addEventListener('focusout', function () { dica.hidden = true; });
    }

    desenhar(reserva, false);
    fetch('/verificar/lotes/indice.json', { cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
      .then(function (d) { if (Array.isArray(d)) desenhar(d, true); })
      .catch(function () {});
  })();
  </script>"""


def json_ld() -> str:
    blocos = [
        {"@context": "https://schema.org", "@type": "BreadcrumbList", "itemListElement": [
            {"@type": "ListItem", "position": 1, "name": "Início", "item": f"{ORIGEM}/"},
            {"@type": "ListItem", "position": 2, "name": "Como funciona a nossa gestão", "item": URL_PAGINA}]},
        {"@context": "https://schema.org", "@type": "WebPage", "@id": f"{URL_PAGINA}#pagina", "url": URL_PAGINA,
         "name": TITULO, "description": DESCRICAO, "inLanguage": "pt-BR", "isPartOf": {"@id": f"{ORIGEM}/#site"},
         "about": {"@id": f"{ORIGEM}/#organizacao"}},
    ]
    saida = ""
    for d in blocos:
        texto = json.dumps(d, ensure_ascii=False, indent=2)
        assert "</" not in texto
        saida += f'\n  <script type="application/ld+json">\n{texto}\n  </script>'
    return saida


def main() -> int:
    lotes = indice_atual()
    home = HOME.read_text(encoding="utf-8")
    partes = partes_da_home(home)
    header = partes["header"].replace(' aria-current="page"', "")
    ga4 = partes["ga4"].replace("gtag('config', 'G-", "gtag('set', { content_group: 'institucional' });\n    gtag('config', 'G-", 1)
    assert "content_group: 'institucional'" in ga4, "não achei o gtag('config') da home"
    js = JS.replace("__INDICE__", json.dumps(lotes, separators=(",", ":")))
    ultimo = lotes[-1]
    dias_seguidos = len(lotes)  # valor inicial; o JS recalcula com o índice ao vivo

    pagina = f"""<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <title>{esc(TITULO)}</title>
  <meta name="description" content="{esc(DESCRICAO)}">
  <link rel="canonical" href="{URL_PAGINA}">
  <meta name="robots" content="index, follow, max-image-preview:large">
  <meta property="og:type" content="website">
  <meta property="og:locale" content="pt_BR">
  <meta property="og:site_name" content="Cruz Vermelha Brasileira Rio de Janeiro">
  <meta property="og:title" content="{esc(TITULO)}">
  <meta property="og:description" content="{esc(DESCRICAO)}">
  <meta property="og:url" content="{URL_PAGINA}">
  <meta property="og:image" content="{IMAGEM_OG}">
  <meta name="twitter:card" content="summary_large_image">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"></noscript>
  <!-- Estilos copiados da home (site/index.html): mesmo padrão visual da filial. -->
{partes["estilo"]}
{CSS}
{ga4}
{partes["pixel"]}
{json_ld()}
</head>
<body>
{header}

  <main id="gestao">
    <section class="gs-topo" aria-labelledby="gs-titulo">
      <div class="wrap gs-topo-grade">
        <div>
          <p class="eyebrow">Gestão e transparência</p>
          <h1 id="gs-titulo">Como funciona a nossa gestão</h1>
          <p class="lead">O que a Cruz Vermelha Brasileira Rio de Janeiro publica como oficial fica registrado de um jeito que qualquer pessoa confere, sem precisar acreditar na nossa palavra. Aqui explicamos, sem tecnicês, como isso funciona e como a criptografia e a inteligência artificial tornaram possível fazer isso numa filial com equipe pequena.</p>
          <ul class="gs-numeros" aria-label="A trilha pública em números">
            <li><b data-gs="seguidos">{dias_seguidos}</b><span>dias seguidos com o registro do dia selado</span></li>
            <li><b data-gs="ultimo">{int(ultimo["dia"][8:])}/{ultimo["dia"][5:7]}</b><span data-gs="bloco">último dia selado</span></li>
            <li><b>3</b><span>provas independentes por dia: relógio, Bitcoin e assinatura</span></li>
          </ul>
        </div>
        <figure class="gs-foto">
          <img src="/assets/otim/turma-cbfi-salao-960.webp" srcset="/assets/otim/turma-cbfi-salao-480.webp 480w, /assets/otim/turma-cbfi-salao-960.webp 960w" sizes="(max-width: 920px) calc(100vw - 40px), 460px" width="960" height="720" alt="Voluntários de camiseta da Cruz Vermelha Brasileira, vistos de costas, na formação do CBFI no salão nobre da sede" fetchpriority="high">
          <figcaption>Turma do CBFI no salão nobre da sede. Os certificados, os ofícios e os documentos da filial entram na mesma trilha pública.</figcaption>
        </figure>
      </div>
    </section>

    <section class="gs-secao" aria-labelledby="gs-caminho-titulo">
      <div class="wrap">
        <p class="eyebrow">O caminho de um documento</p>
        <h2 id="gs-caminho-titulo">Do papel assinado à conferência por qualquer pessoa</h2>
        <p class="lead">Estatuto, atas, ofícios, certificados de curso, comunicados e matérias passam pelo mesmo caminho. Cada etapa deixa uma prova que não depende de quem a criou.</p>
        <ol class="gs-caminho">
          <li><span class="gs-icone"><i class="fa-solid fa-file-arrow-up" aria-hidden="true"></i></span><h3>Publicado</h3><p>O documento oficial é emitido ou publicado pela filial.</p></li>
          <li><span class="gs-icone"><i class="fa-solid fa-fingerprint" aria-hidden="true"></i></span><h3>Impressão digital</h3><p>O arquivo vira um código único de 64 caracteres (SHA-256). Mudou uma vírgula, muda o código inteiro.</p></li>
          <li><span class="gs-icone"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span><h3>Trilha pública</h3><p>O registro entra numa trilha com data e hora, ligado ao anterior: alterar um registro antigo quebraria todos os seguintes.</p></li>
          <li><span class="gs-icone"><i class="fa-solid fa-lock" aria-hidden="true"></i></span><h3>Selo do dia</h3><p>Todo dia, um código que resume todos os registros recebe três provas independentes.</p></li>
          <li><span class="gs-icone"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span><h3>Você confere</h3><p>Pelo código, pelo QR code ou pelo próprio arquivo, em <a href="/verificar/">Verificar um documento</a>.</p></li>
        </ol>
      </div>
    </section>

    <section class="gs-secao gs-secao-suave" aria-labelledby="gs-demo-titulo">
      <div class="wrap">
        <p class="eyebrow">Teste você mesmo</p>
        <h2 id="gs-demo-titulo">A impressão digital de um texto</h2>
        <p class="lead">É assim que sabemos que um documento não foi trocado: a impressão digital do arquivo baixado tem de ser igual à publicada. Digite qualquer coisa e veja o código mudar por completo com uma única letra.</p>
        <div class="gs-demo">
          <div class="gs-demo-caixa">
            <label for="gs-texto">Seu texto</label>
            <textarea id="gs-texto" spellcheck="false">Cruz Vermelha Brasileira, filial do Rio de Janeiro.</textarea>
            <p class="gs-nota">O cálculo é feito no seu navegador: o que você digita não sai do seu aparelho.</p>
            <div class="gs-demo-botoes">
              <button type="button" class="btn btn-outline" id="gs-virgula">Tirar (ou pôr) uma vírgula</button>
              <button type="button" class="btn btn-outline" id="gs-restaurar">Voltar ao texto original</button>
            </div>
          </div>
          <div class="gs-demo-caixa">
            <label for="gs-hash">Impressão digital (SHA-256)</label>
            <output class="gs-hash" id="gs-hash" for="gs-texto" aria-live="polite"></output>
            <p class="gs-nota" id="gs-mudancas" aria-live="polite"></p>
            <p class="gs-nota">Com um documento de verdade é igual. O <a href="/transparencia/">Estatuto publicado no portal</a> tem esta impressão digital:</p>
            <p class="gs-nota"><code>{ESTATUTO_SHA}</code></p>
            <p class="gs-nota">Quem baixar o PDF e calcular (no Windows, <code>certutil -hashfile arquivo.pdf SHA256</code>; no Mac ou Linux, <code>shasum -a 256 arquivo.pdf</code>) tem de chegar ao mesmo código.</p>
          </div>
        </div>
      </div>
    </section>

    <section class="gs-secao" aria-labelledby="gs-selos-titulo">
      <div class="wrap">
        <p class="eyebrow">Os três selos de cada dia</p>
        <h2 id="gs-selos-titulo">Três provas que não dependem da filial</h2>
        <p class="lead">No fim de cada dia, todos os registros viram um único código de resumo. Esse resumo recebe três selos, cada um de uma fonte diferente. Para fraudar a trilha, seria preciso enganar as três ao mesmo tempo.</p>
        <div class="gs-selos">
          <article class="gs-selo"><span class="gs-icone"><i class="fa-solid fa-clock" aria-hidden="true"></i></span><h3>Relógio independente</h3><p>Uma autoridade de carimbo de tempo externa atesta o momento exato em que o resumo existia.</p><small>Padrão RFC 3161, pela FreeTSA.</small></article>
          <article class="gs-selo"><span class="gs-icone"><i class="fa-solid fa-link" aria-hidden="true"></i></span><h3>Âncora no Bitcoin</h3><p>O resumo é gravado num bloco público do Bitcoin, um registro mundial que ninguém consegue reescrever, nem a filial.</p><small>Pelo OpenTimestamps.</small></article>
          <article class="gs-selo"><span class="gs-icone"><i class="fa-solid fa-certificate" aria-hidden="true"></i></span><h3>Assinatura da filial</h3><p>A filial assina digitalmente o manifesto do dia. A chave pública fica no site, e qualquer pessoa confere quem fechou o lote.</p><small>Assinatura Ed25519.</small></article>
        </div>
      </div>
    </section>

    <section class="gs-secao gs-secao-suave" id="corrente" aria-labelledby="gs-corrente-titulo">
      <div class="wrap">
        <p class="eyebrow">A corrente, ao vivo <span class="gs-ao-vivo" data-gs="aovivo" hidden>lido agora do site</span></p>
        <h2 id="gs-corrente-titulo">Um dia ligado ao outro, desde <span data-gs="desde">{lotes[0]["dia"][8:]}/{lotes[0]["dia"][5:7]}/{lotes[0]["dia"][:4]}</span></h2>
        <p class="lead">O resumo de cada dia carrega o resumo do dia anterior. Por isso não dá para apagar ou trocar um dia antigo sem que todos os dias seguintes deixem de bater. Estes são os três últimos lotes, lidos agora dos arquivos públicos do site:</p>
        <div class="gs-cadeia" id="gs-cadeia" aria-label="Os três últimos lotes da trilha"></div>
        <div class="gs-grafico">
          <h3>Todos os dias da trilha</h3>
          <p>Cada quadrado é um dia. Passe o mouse ou toque para ver o detalhe.</p>
          <div class="gs-dias" id="gs-dias" role="group" aria-label="Situação do lote de cada dia"></div>
          <div class="gs-eixo" aria-hidden="true"><span id="gs-eixo-ini"></span><span id="gs-eixo-fim"></span></div>
          <div class="gs-legenda">
            <span><i class="gs-amostra" style="background:var(--gs-ok)"></i><i class="fa-solid fa-check" aria-hidden="true"></i> Dia selado (relógio, Bitcoin e assinatura)</span>
            <span><i class="gs-amostra" style="border:2px dashed #a0aec0"></i><i class="fa-solid fa-clock" aria-hidden="true"></i> Hoje: o lote fecha à meia-noite</span>
            <span><i class="gs-amostra" style="border:2px solid #d03b3b"></i><i class="fa-solid fa-xmark" aria-hidden="true"></i> Dia sem lote publicado</span>
          </div>
          <details class="gs-tabela-lotes">
            <summary>Ver os lotes em tabela</summary>
            <table>
              <thead><tr><th scope="col">Dia</th><th scope="col">Bloco do Bitcoin</th><th scope="col">Carimbo de tempo</th><th scope="col">Arquivo</th></tr></thead>
              <tbody id="gs-tabela-corpo"></tbody>
            </table>
          </details>
        </div>
        <p class="gs-nota">Os arquivos de prova de cada dia (manifesto, assinatura, carimbo de tempo e prova do Bitcoin) ficam públicos em /verificar/lotes/. A página <a href="/verificar/">Verificar um documento</a> explica como conferir tudo com ferramentas livres, sem depender da Cruz Vermelha.</p>
      </div>
    </section>

    <section class="gs-secao" aria-labelledby="gs-funciona-titulo">
      <div class="wrap">
        <p class="eyebrow">O que já funciona assim</p>
        <h2 id="gs-funciona-titulo">Transparência que você pode usar hoje</h2>
        <div class="gs-grade-cartoes">
          <a class="gs-cartao" href="/transparencia/"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i><h3>Portal da transparência</h3><p>Estatuto e atas com versão que não se troca em silêncio, impressão digital e código de verificação.</p></a>
          <a class="gs-cartao" href="/verificar/"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><h3>Verificar um documento</h3><p>Ofícios, certificados e publicações conferidos pelo código, pelo QR code ou pelo arquivo.</p></a>
          <a class="gs-cartao" href="/canais-oficiais/"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><h3>Canais oficiais</h3><p>A lista registrada de site, e-mail, telefone e endereço oficiais, com histórico de versões.</p></a>
          <a class="gs-cartao" href="/pedido-de-documentos/"><i class="fa-solid fa-envelope" aria-hidden="true"></i><h3>Pedido de documentos</h3><p>Para pedir a íntegra de um documento, com comprovação de quem pede e resposta por e-mail.</p></a>
          <a class="gs-cartao" href="/acervo/"><i class="fa-solid fa-book-medical" aria-hidden="true"></i><h3>Acervo histórico</h3><p>Documentos e fotos da história da filial, catalogados e guardados com cópia de segurança.</p></a>
          <a class="gs-cartao" href="/quem-somos/#como-nos-financiamos"><i class="fa-solid fa-hand-holding-heart" aria-hidden="true"></i><h3>Como a filial se financia</h3><p>De onde vem o dinheiro que mantém o Palácio e as atividades humanitárias.</p></a>
        </div>
        <h3 class="gs-subtitulo">E na gestão do dia a dia</h3>
        <div class="gs-grade-cartoes">
          <div class="gs-cartao"><i class="fa-solid fa-clock" aria-hidden="true"></i><h3>Ponto da sede</h3><p>Voluntários e equipe registram presença na sede, e quem precisa recebe comprovante de comparecimento com código de conferência.</p></div>
          <div class="gs-cartao"><i class="fa-solid fa-people-group" aria-hidden="true"></i><h3>Cadastro de voluntários</h3><p>Ficha digital e termo de adesão ao serviço voluntário (Lei 9.608/1998), com os dados protegidos pela LGPD.</p></div>
          <div class="gs-cartao"><i class="fa-solid fa-lock" aria-hidden="true"></i><h3>Cópias de segurança cifradas</h3><p>Os dados dos sistemas têm cópia diária cifrada, guardada fora do servidor principal.</p></div>
        </div>
      </div>
    </section>

    <section class="gs-secao gs-secao-suave" aria-labelledby="gs-ia-titulo">
      <div class="wrap">
        <p class="eyebrow">Onde entra a inteligência artificial</p>
        <h2 id="gs-ia-titulo">A IA acelera o trabalho. A confiança vem da matemática.</h2>
        <p class="lead">Boa parte desses sistemas foi construída e é mantida com a ajuda de assistentes de inteligência artificial, sob a direção e a revisão da equipe da filial. Mas a IA tem um papel bem delimitado.</p>
        <div class="gs-ia">
          <div class="gs-ia-coluna gs-ia-sim">
            <h3><i class="fa-solid fa-circle-check" aria-hidden="true"></i>A IA nos ajuda a</h3>
            <ul>
              <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>construir e manter o site, o portal, a verificação, o ponto e o cadastro, sem um departamento de TI;</span></li>
              <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>revisar código e textos, procurar falhas de segurança e erros antes de publicar;</span></li>
              <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>organizar informação e traduzir páginas para o inglês e o espanhol.</span></li>
            </ul>
          </div>
          <div class="gs-ia-coluna gs-ia-nao">
            <h3><i class="fa-solid fa-ban" aria-hidden="true"></i>A IA não</h3>
            <ul>
              <li><i class="fa-solid fa-xmark" aria-hidden="true"></i><span>decide nada sobre a filial, nem aprova gastos ou assina documentos;</span></li>
              <li><i class="fa-solid fa-xmark" aria-hidden="true"></i><span>publica sem que uma pessoa da equipe revise e autorize;</span></li>
              <li><i class="fa-solid fa-xmark" aria-hidden="true"></i><span>é a base da confiança: as provas são matemática que qualquer pessoa confere com ferramentas livres, sem IA e sem a filial.</span></li>
            </ul>
          </div>
        </div>
        <div class="gs-porque">
          <h3>Por que isso ficou possível agora</h3>
          <p>Impressão digital, assinatura digital e carimbo de tempo existem há décadas e protegem bancos, cartórios e governos. O que mudou foi o custo de colocar isso de pé. Com assistentes de IA, uma filial sem equipe de tecnologia consegue construir e manter o mesmo tipo de sistema, e usar o tempo das pessoas no que importa: a missão humanitária.</p>
        </div>
      </div>
    </section>

    <section class="gs-secao" aria-labelledby="gs-limites-titulo">
      <div class="wrap">
        <p class="eyebrow">Com honestidade</p>
        <h2 id="gs-limites-titulo">O que isso prova, e o que não prova</h2>
        <div class="gs-limites">
          <div>
            <h3>Prova</h3>
            <ul>
              <li>que o documento é exatamente o que foi publicado;</li>
              <li>quando ele foi registrado;</li>
              <li>que foi a filial que o registrou;</li>
              <li>se ele continua valendo, foi substituído ou revogado.</li>
            </ul>
          </div>
          <div>
            <h3>Não prova</h3>
            <ul>
              <li>que o conteúdo do documento é verdadeiro ou está certo;</li>
              <li>que as contas da filial estão corretas: isso cabe à prestação de contas, à auditoria e aos órgãos de controle.</li>
            </ul>
          </div>
        </div>
        <div class="gs-acoes">
          <a class="btn btn-red" href="/transparencia/"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i>Abrir o portal da transparência</a>
          <a class="btn btn-outline" href="/verificar/"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>Verificar um documento</a>
          <a class="btn btn-outline" href="/pedido-de-documentos/"><i class="fa-solid fa-envelope" aria-hidden="true"></i>Pedir um documento</a>
        </div>
      </div>
    </section>
  </main>

{partes["footer"]}

{partes["menu_js"]}
{js}
{chat_widget.tags()}
</body>
</html>
"""
    pagina = icones.converter(pagina, {"check", "clock", "xmark", "link"})
    PASTA.mkdir(parents=True, exist_ok=True)
    SAIDA.write_text(pagina, encoding="utf-8")
    print(f"gravado {SAIDA.relative_to(RAIZ)} ({len(pagina.encode('utf-8'))} bytes, {len(lotes)} lotes no índice)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
