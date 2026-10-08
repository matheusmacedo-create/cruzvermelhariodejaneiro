#!/usr/bin/env python3
"""Gera as políticas do site em português, inglês e espanhol a partir de site/politicas.json.

  /privacidade/   /en/privacy/   /es/privacidad/    Política de Privacidade (LGPD)
  /cookies/       /en/cookies/   /es/cookies/       Política de Cookies (Guia de Cookies da ANPD, 2022)
  /termos/        /en/terms/     /es/terminos/      Termos de Uso
  /reembolso/     /en/refunds/   /es/reembolsos/    Cancelamento e reembolso (CDC, art. 49; Decreto 7.962/2013)

Até 27/09/2026, /privacidade/ e /termos/ eram gerados pela Redação (lib/site/juridico.ts), só em
português. Passaram para cá para ficarem junto do resto do site, nas três línguas, com a Política de
Cookies (que o aviso de cookies linka) e a de cancelamento e reembolso (que a matrícula exige). A
Redação deixou de publicar as duas: se voltasse a publicar, sobrescreveria estas.

Português: cabeçalho, rodapé e CSS da home (partes_da_home), como o 404, com o seletor de idioma
levando à mesma política nas outras línguas. Inglês e espanhol: a moldura() de gerar_idiomas.py.
hreflang recíproco entre as três versões (PAGINAS de gerar_idiomas.py); o português é o x-default
e a versão oficial (as traduções dizem isso no topo).

O texto mora em site/politicas.json. Marcações aceitas no texto: [texto](url), **negrito** e
{chave}, trocada por SUBSTITUICOES (e-mail, valor da inscrição lido de cursos.json, caminhos).

Uso:  python3 scripts/gerar_politicas.py
      (depois de consentimento.py, que atualiza o bloco de medição da home que estas páginas copiam)
"""
from __future__ import annotations

import html
import json
import re
from datetime import date
from pathlib import Path

import chat_widget
import gerar_idiomas as idiomas
import icones
from gerar_matricula_presencial import HOME, partes_da_home

RAIZ = Path(__file__).resolve().parent.parent
SITE = RAIZ / "site"
DADOS = SITE / "politicas.json"
CURSOS = SITE / "matricula-cursos-presenciais" / "cursos.json"
ORIGEM = idiomas.ORIGEM
ORDEM = ["privacidade", "cookies", "termos", "reembolso"]
LINGUA_HTML = {"pt": "pt-BR", "en": "en", "es": "es"}
MESES = {
    "pt": ["janeiro", "fevereiro", "março", "abril", "maio", "junho", "julho", "agosto", "setembro",
           "outubro", "novembro", "dezembro"],
    "en": ["January", "February", "March", "April", "May", "June", "July", "August", "September",
           "October", "November", "December"],
    "es": ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre",
           "octubre", "noviembre", "diciembre"],
}
URL_PUNCAO = "https://puncaovenosav1.cruzvermelhariodejaneiro.org/politica-de-privacidade"

CSS = """
    .pol-topo { background:var(--soft); border-bottom:1px solid var(--line); padding:56px 0 40px }
    .pol-topo h1 { color:var(--black); font-size:clamp(2rem,4vw,2.8rem); letter-spacing:-.03em; line-height:1.1; margin:6px 0 10px }
    .pol-data { color:var(--muted); font-size:.95rem; margin:0 }
    .pol-traducao { color:var(--muted); font-size:.9rem; margin:10px 0 0; font-style:italic }
    .pol-corpo { max-width:820px; padding:32px 0 72px }
    .pol-corpo p, .pol-corpo li, .pol-corpo dd { font-size:1.02rem; line-height:1.75; color:#2b2b2b }
    .pol-corpo p { margin:0 0 14px }
    .pol-corpo section { scroll-margin-top:96px; padding:0 }
    .pol-corpo h2 { font-size:1.45rem; color:var(--black); margin:40px 0 12px; letter-spacing:-.01em }
    .pol-corpo h3 { font-size:1.05rem; color:var(--black); margin:0 0 10px }
    .pol-corpo ul { padding-left:22px; margin:0 0 16px }
    .pol-corpo li { margin:6px 0 }
    .pol-corpo a { color:var(--red); font-weight:700; text-underline-offset:2px; overflow-wrap:anywhere }
    .pol-corpo a.btn { color:#fff; text-decoration:none }
    .pol-indice { background:#fff; border:1px solid var(--line); border-radius:var(--radius); padding:18px 22px; margin:24px 0 8px }
    .pol-indice p { font-weight:800; font-size:.8rem; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); margin:0 0 8px }
    .pol-indice ol { margin:0; padding-left:20px; columns:2; column-gap:32px }
    .pol-indice li { margin:4px 0; break-inside:avoid; font-size:.97rem }
    .pol-indice a { font-weight:600 }
    .pol-filial { background:var(--soft); border:1px solid var(--line); border-radius:var(--radius); padding:16px 20px; margin:16px 0 }
    .pol-filial p { margin:2px 0; font-size:.97rem }
    .pol-atividades { display:grid; gap:14px; margin:18px 0 22px }
    .pol-atividade { border:1px solid var(--line); border-radius:var(--radius); padding:18px 20px; background:#fff }
    .pol-atividade dl { margin:0; display:grid; grid-template-columns:118px 1fr; gap:6px 16px }
    .pol-atividade dt { font-weight:800; font-size:.78rem; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; padding-top:5px }
    .pol-atividade dd { margin:0 }
    .pol-tabela-rolo { overflow-x:auto; margin:16px 0; border:1px solid var(--line); border-radius:var(--radius) }
    .pol-tabela { width:100%; border-collapse:collapse; min-width:700px }
    .pol-tabela th, .pol-tabela td { text-align:left; vertical-align:top; padding:11px 14px; border-bottom:1px solid var(--line); font-size:.92rem; line-height:1.5 }
    .pol-tabela tr:last-child th, .pol-tabela tr:last-child td { border-bottom:0 }
    .pol-tabela thead th { background:var(--soft); font-size:.76rem; text-transform:uppercase; letter-spacing:.04em }
    .pol-tabela tbody th { font-weight:700 }
    .pol-tabela code { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:.86em; background:var(--soft); padding:1px 5px; border-radius:5px; white-space:nowrap }
    .pol-acoes { margin:18px 0 }
    .pol-estado { background:#fff; border-left:4px solid var(--red); padding:10px 14px; margin:16px 0; font-weight:600 }
    .pol-estado:empty { display:none }
    .pol-outras { border-top:1px solid var(--line); margin-top:48px; padding-top:20px; font-size:.95rem }
    @media (max-width:640px) {
      .pol-atividade dl { grid-template-columns:1fr; gap:2px }
      .pol-atividade dt { padding-top:8px }
      .pol-indice ol { columns:1 }
      /* No celular, cada linha da tabela vira um cartão, com o nome da coluna antes do valor. */
      .pol-tabela-rolo { overflow:visible }
      .pol-tabela { min-width:0 }
      .pol-tabela thead { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0) }
      .pol-tabela, .pol-tabela tbody, .pol-tabela tr, .pol-tabela th, .pol-tabela td { display:block; width:auto }
      .pol-tabela tr { padding:12px 14px; border-bottom:1px solid var(--line) }
      .pol-tabela tr:last-child { border-bottom:0 }
      .pol-tabela th, .pol-tabela td { border:0; padding:2px 0 }
      .pol-tabela tbody th { font-size:.98rem; padding-bottom:4px }
      .pol-tabela td::before { content:attr(data-rotulo) ": "; font-weight:700; color:var(--muted) }
    }
"""

# Mostra a escolha atual na Política de Cookies e acompanha as mudanças feitas no painel.
ESTADO_JS = """  <script>
    (function () {
      var alvo = document.getElementById('pol-estado');
      if (!alvo) return;
      var T = JSON.parse(alvo.getAttribute('data-textos'));
      function mostrar() {
        var api = window.cvrjConsentimento || window.cvrjMedicao;
        var c = api ? api.ler() : null;
        if (!c) { alvo.textContent = T.rotulo + ' ' + T.nenhuma; return; }
        var texto = T.rotulo + ' ' + T.estatistica + ': ' + (c.estatistica ? T.sim : T.nao) + '; ' +
          T.marketing + ': ' + (c.marketing ? T.sim : T.nao);
        if (c.em) texto += ' (' + T.em + ' ' + new Date(c.em * 1000).toLocaleDateString(document.documentElement.lang) + ')';
        alvo.textContent = texto + '.';
      }
      document.addEventListener('cvrj:consentimento', mostrar);
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mostrar); else mostrar();
    })();
  </script>"""

PAGINA_PT = """<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <title>@@TITULO@@</title>
  <meta name="description" content="@@DESCRICAO@@">
  <link rel="canonical" href="@@URL@@">
@@ALTERNATIVAS@@
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Cruz Vermelha Brasileira Rio de Janeiro">
  <meta property="og:locale" content="pt_BR">
  <meta property="og:url" content="@@URL@@">
  <meta property="og:title" content="@@TITULO@@">
  <meta property="og:description" content="@@DESCRICAO@@">
  <meta property="og:image" content="https://cruzvermelhariodejaneiro.org/assets/otim/og-home.jpg">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta name="twitter:card" content="summary_large_image">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"></noscript>
@@ESTILO@@
  <style>@@CSS@@  </style>
  <script type="application/ld+json">@@LD@@</script>
@@GA4@@
@@PIXEL@@
</head>
<body>
@@HEADER@@
  <main>
@@CORPO@@
  </main>
@@FOOTER@@
@@MENU_JS@@
</body>
</html>
"""

LINK = re.compile(r"\[([^\]]+)\]\(([^)\s]+)\)")
NEGRITO = re.compile(r"\*\*(.+?)\*\*")
CHAVE = re.compile(r"\{([a-z]+)\}")


def esc(s: str) -> str:
    return html.escape(str(s), quote=True)


def data_legivel(d: date, lingua: str) -> str:
    mes = MESES[lingua][d.month - 1]
    if lingua == "en":
        return f"{d.day} {mes} {d.year}"
    return f"{d.day} de {mes} de {d.year}"


def brl(centavos: int) -> str:
    reais, cent = divmod(int(centavos), 100)
    return f"R$ {reais:,}".replace(",", ".") + (f",{cent:02d}" if cent else "")


class Pagina:
    """Monta o HTML de uma política numa língua."""

    def __init__(self, chave: str, lingua: str, dados: dict, rotulos: dict, subs: dict, revisao: date):
        self.chave, self.lingua, self.d, self.r, self.subs, self.revisao = chave, lingua, dados, rotulos, subs, revisao
        self.caminho = idiomas.PAGINAS[chave][lingua]
        self.url = f"{ORIGEM}{self.caminho}"

    # ------------------------------------------------------------------ texto
    def trocar(self, texto: str) -> str:
        def troca(m: re.Match) -> str:
            if m.group(1) not in self.subs:
                raise SystemExit(f"{self.chave}/{self.lingua}: chave desconhecida {{{m.group(1)}}}")
            return self.subs[m.group(1)]
        return CHAVE.sub(troca, texto)

    def atributos(self, url: str) -> str:
        if url.startswith("http"):
            return ' target="_blank" rel="noopener"'
        if url.startswith(("mailto:", "#")):
            return ""
        # Em inglês e espanhol, link para página que só existe em português avisa a troca de língua.
        if self.lingua != "pt" and not url.startswith(("/en/", "/es/")):
            return ' hreflang="pt-BR" lang="pt-BR"'
        return ""

    def inline(self, texto: str) -> str:
        texto = esc(self.trocar(texto))
        texto = LINK.sub(lambda m: f'<a href="{m.group(2)}"{self.atributos(html.unescape(m.group(2)))}>{m.group(1)}</a>', texto)
        return NEGRITO.sub(r"<strong>\1</strong>", texto)

    # ------------------------------------------------------------------ blocos
    def filial(self) -> str:
        linhas = []
        for i, linha in enumerate(self.r["filial"]):
            t = esc(self.trocar(linha))
            email = self.subs["email"]
            t = t.replace(email, f'<a href="mailto:{email}">{email}</a>')
            linhas.append(f"<p><strong>{t}</strong></p>" if i == 0 else f"<p>{t}</p>")
        return '<div class="pol-filial">\n          ' + "\n          ".join(linhas) + "\n        </div>"

    def atividades(self, itens: list) -> str:
        r = self.r
        cartoes = []
        for a in itens:
            cartoes.append(
                f'<article class="pol-atividade">\n            <h3>{self.inline(a["titulo"])}</h3>\n'
                f'            <dl>\n'
                f'              <dt>{esc(r["dados"])}</dt><dd>{self.inline(a["dados"])}</dd>\n'
                f'              <dt>{esc(r["finalidade"])}</dt><dd>{self.inline(a["finalidade"])}</dd>\n'
                f'              <dt>{esc(r["base"])}</dt><dd>{self.inline(a["base"])}</dd>\n'
                f'            </dl>\n          </article>')
        return '<div class="pol-atividades">\n          ' + "\n          ".join(cartoes) + "\n        </div>"

    def tabela(self, t: dict, rotulo: str) -> str:
        def nome(celula: str) -> str:
            # Nomes técnicos (com "_") vão em <code>; o resto é texto.
            return " ".join(f"<code>{esc(p)}</code>" if "_" in p else esc(p) for p in celula.split(" "))
        cab = "".join(f'<th scope="col">{esc(c)}</th>' for c in t["colunas"])
        linhas = []
        for linha in t["linhas"]:
            primeira, resto = linha[0], linha[1:]
            celulas = "".join(f'<td data-rotulo="{esc(r)}">{self.inline(c)}</td>' for r, c in zip(t["colunas"][1:], resto))
            linhas.append(f'<tr><th scope="row">{nome(primeira)}</th>{celulas}</tr>')
        corpo = "\n              ".join(linhas)
        return (f'<div class="pol-tabela-rolo" role="region" aria-label="{esc(rotulo)}" tabindex="0">\n'
                f'          <table class="pol-tabela">\n            <thead><tr>{cab}</tr></thead>\n'
                f'            <tbody>\n              {corpo}\n            </tbody>\n          </table>\n        </div>')

    def botao(self, rotulo: str) -> str:
        destino = "#preferencias" if self.chave == "cookies" else f'{idiomas.PAGINAS["cookies"][self.lingua]}#preferencias'
        return (f'<p class="pol-acoes"><a class="btn btn-red" href="{destino}" data-cvrj-cookies>{esc(rotulo)}</a></p>\n'
                f'        <noscript><p>{esc(self.r["noscript"])}</p></noscript>')

    def estado(self) -> str:
        textos = esc(json.dumps(self.r["estado"], ensure_ascii=False))
        return f'<p class="pol-estado" id="pol-estado" aria-live="polite" data-textos="{textos}"></p>'

    def bloco(self, b: dict, titulo_secao: str) -> str:
        if "p" in b:
            return f"<p>{self.inline(b['p'])}</p>"
        if "lista" in b:
            itens = "\n          ".join(f"<li>{self.inline(x)}</li>" for x in b["lista"])
            return f"<ul>\n          {itens}\n        </ul>"
        if b.get("filial"):
            return self.filial()
        if "atividades" in b:
            return self.atividades(b["atividades"])
        if "tabela" in b:
            return self.tabela(b["tabela"], titulo_secao)
        if "botao_cookies" in b:
            return self.botao(b["botao_cookies"])
        if b.get("estado_cookies"):
            return self.estado()
        raise SystemExit(f"{self.chave}/{self.lingua}: bloco desconhecido {sorted(b)}")

    # ------------------------------------------------------------------ página
    def corpo(self) -> str:
        d, r = self.d, self.r
        traducao = f'\n        <p class="pol-traducao">{esc(r["traducao"])}</p>' if r.get("traducao") else ""
        intro = "\n        ".join(f"<p>{self.inline(p)}</p>" for p in d["intro"])
        indice = ""
        if len(d["secoes"]) >= 5:
            itens = "\n            ".join(f'<li><a href="#{s["id"]}">{esc(s["titulo"])}</a></li>' for s in d["secoes"])
            indice = (f'\n        <nav class="pol-indice" aria-label="{esc(r["indice"])}">\n          <p>{esc(r["indice"])}</p>\n'
                      f'          <ol>\n            {itens}\n          </ol>\n        </nav>')
        secoes = []
        for s in d["secoes"]:
            blocos = "\n        ".join(self.bloco(b, s["titulo"]) for b in s["blocos"])
            secoes.append(f'      <section id="{s["id"]}" aria-labelledby="t-{s["id"]}">\n'
                          f'        <h2 id="t-{s["id"]}">{esc(s["titulo"])}</h2>\n        {blocos}\n      </section>')
        outras = " · ".join(f'<a href="{idiomas.PAGINAS[k][self.lingua]}">{esc(r["nomes"][k])}</a>' for k in ORDEM if k != self.chave)
        return f"""    <section class="pol-topo">
      <div class="wrap">
        <p class="eyebrow">{esc(d['sobrancelha'])}</p>
        <h1>{esc(d['h1'])}</h1>
        <p class="pol-data">{esc(r['atualizada'])} <time datetime="{self.revisao.isoformat()}">{data_legivel(self.revisao, self.lingua)}</time>.</p>{traducao}
      </div>
    </section>
    <div class="wrap pol-corpo">
      <div class="pol-intro">
        {intro}
      </div>{indice}
{chr(10).join(secoes)}
      <p class="pol-outras">{esc(r['outras'])} {outras}</p>
    </div>"""

    def ld(self) -> list:
        inicio = idiomas.PAGINAS["home"][self.lingua]
        return [{"@context": "https://schema.org", "@type": "WebPage", "@id": f"{self.url}#pagina", "url": self.url,
                 "name": self.d["h1"], "description": self.d["descricao"], "inLanguage": LINGUA_HTML[self.lingua],
                 "dateModified": self.revisao.isoformat(), "isPartOf": {"@id": f"{ORIGEM}/#site"},
                 "about": {"@id": f"{ORIGEM}/#organizacao"}},
                {"@context": "https://schema.org", "@type": "BreadcrumbList", "itemListElement": [
                    {"@type": "ListItem", "position": 1, "name": self.r["inicio"], "item": f"{ORIGEM}{inicio}"},
                    {"@type": "ListItem", "position": 2, "name": self.d["h1"], "item": self.url}]}]

    def html_pt(self, partes: dict) -> str:
        ld = json.dumps(self.ld(), ensure_ascii=False, separators=(",", ":"))
        assert "</" not in ld
        # O cabeçalho da home marca "Matrícula cursos presenciais" como página atual (é da matrícula)
        # e o seletor de idioma dela leva às homes: aqui, leva a esta mesma política.
        header = partes["header"].replace(' aria-current="page"', "")
        seletor = idiomas.seletor(self.chave, "pt").replace('aria-label="Language"', 'aria-label="Idioma"')
        header, n = re.subn(r'<div class="seletor-idioma".*?</div>', lambda m: seletor, header, count=1, flags=re.S)
        assert n == 1, "seletor de idioma não encontrado no cabeçalho da home"
        corpo = self.corpo() + ("\n" + ESTADO_JS if self.chave == "cookies" else "")
        trocas = {"@@TITULO@@": esc(self.d["titulo_aba"]), "@@DESCRICAO@@": esc(self.d["descricao"]), "@@URL@@": self.url,
                  "@@ALTERNATIVAS@@": idiomas.alternativas(self.chave, "pt"), "@@ESTILO@@": partes["estilo"], "@@CSS@@": CSS,
                  "@@LD@@": ld, "@@GA4@@": partes["ga4"], "@@PIXEL@@": partes["pixel"], "@@HEADER@@": header,
                  "@@CORPO@@": corpo, "@@FOOTER@@": partes["footer"], "@@MENU_JS@@": partes["menu_js"]}
        pagina = PAGINA_PT
        for a, b in trocas.items():
            pagina = pagina.replace(a, b)
        return chat_widget.aplicar(icones.converter(pagina))

    def html_idioma(self, idioma: dict, partes_idioma: dict) -> str:
        corpo = self.corpo() + ("\n" + ESTADO_JS if self.chave == "cookies" else "")
        return idiomas.moldura(idioma, self.chave, self.d["titulo_aba"], self.d["descricao"], corpo, self.ld(),
                               partes_idioma, css=CSS)


def main() -> int:
    dados = json.loads(DADOS.read_text(encoding="utf-8"))
    revisao = date.fromisoformat(dados["revisao"])
    inscricao = brl(json.loads(CURSOS.read_text(encoding="utf-8"))["inscricao_centavos"])
    home = HOME.read_text(encoding="utf-8")
    partes = partes_da_home(home)
    partes_idioma = dict(partes)
    partes_idioma["estilo_sem_tag"] = re.sub(r"^\s*<style>|</style>\s*$", "", partes["estilo"])
    idiomas_json = json.loads(idiomas.DADOS.read_text(encoding="utf-8"))["idiomas"]
    total = 0
    for chave in ORDEM:
        for lingua in ("pt", "en", "es"):
            subs = {"email": idiomas.EMAIL, "inscricao": inscricao, "matricula": "/matricula-cursos-presenciais/",
                    "escola": idiomas.ESCOLA, "links": "/bio/", "puncao": URL_PUNCAO}
            subs.update({k: idiomas.PAGINAS[k][lingua] for k in ORDEM})
            # Data de revisão: a da política, se ela mudou sozinha (paginas.<chave>.revisao); senão, a de todas.
            revisao_pagina = date.fromisoformat(dados["paginas"][chave]["revisao"]) if "revisao" in dados["paginas"][chave] else revisao
            pagina = Pagina(chave, lingua, dados["paginas"][chave][lingua], dados["rotulos"][lingua], subs, revisao_pagina)
            saida = pagina.html_pt(partes) if lingua == "pt" else pagina.html_idioma(idiomas_json[lingua], partes_idioma)
            destino = SITE / pagina.caminho.strip("/") / "index.html"
            destino.parent.mkdir(parents=True, exist_ok=True)
            destino.write_text(saida, encoding="utf-8")
            print(f"gravado {destino.relative_to(RAIZ)} ({len(saida.encode('utf-8'))} bytes)")
            total += 1
    print(f"{total} páginas de políticas")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
