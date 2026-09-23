#!/usr/bin/env python3
"""Gera as páginas em inglês e espanhol a partir de site/idiomas.json.

  site/en/            site/es/            página institucional
  site/en/donate/     site/es/donar/      página de doação

O visual é o mesmo da home: cabeçalho, rodapé e CSS saem de partes_da_home(), e só os textos
mudam. Cada página declara hreflang para as três versões, com x-default no português — sem isso
o Google trata as versões como conteúdo duplicado e escolhe uma.

A página de doação explica e encaminha para /doe/, que é a engrenagem que recebe o dinheiro e
está em português. Traduzir aquele fluxo é mexer em página que recebe pagamento, e não entra
aqui: a página em inglês diz, com todas as letras, que a tela de pagamento é em português, e
traduz os botões que a pessoa vai encontrar.

Uso:  python3 scripts/gerar_idiomas.py     (depois, publicar site/en/ e site/es/)
"""
from __future__ import annotations

import html
import json
import re
import sys
from pathlib import Path

import gerar_matricula_presencial as base
import icones

RAIZ = Path(__file__).resolve().parent.parent
SITE = RAIZ / "site"
DADOS = SITE / "idiomas.json"
HOME = SITE / "index.html"
ORIGEM = "https://cruzvermelhariodejaneiro.org"
WIKI = "https://pt.wikipedia.org/wiki/Cruz_Vermelha_Brasileira_-_Rio_de_Janeiro"
ESCOLA = "https://escola.cursoscruzvermelha.org"
EMAIL = "contato@cruzvermelhariodejaneiro.org"
ENDERECO = "Praça da Cruz Vermelha, 10 · Centro · Rio de Janeiro · RJ · 20230-130"
VOLUNTARIO = "https://form.spotform.com.br/voluntariocruzvermelharj"
# Caminho de cada página, por idioma. O português é o x-default.
PAGINAS = {"home": {"pt": "/", "en": "/en/", "es": "/es/"},
           "doar": {"pt": "/doe/", "en": "/en/donate/", "es": "/es/donar/"},
           # Em português a FAQ é uma seção da home, não uma página: daí o "#".
           "faq": {"pt": "/#faq", "en": "/en/faq/", "es": "/es/preguntas-frecuentes/"},
           # A página em português dos cursos é a de matrícula, com checkout: é outro tipo de
           # página, não a mesma em outra língua. Fica de x-default, fora do par hreflang — o "#"
           # é o que sinaliza isso para alternativas(), como na FAQ.
           "cursos": {"pt": "/matricula-cursos-presenciais/#cursos", "en": "/en/courses/", "es": "/es/cursos/"},
           # A equipe existe nas três línguas e são a mesma página: cluster completo.
           "equipe": {"pt": "/equipe.html", "en": "/en/our-team/", "es": "/es/nuestro-equipo/"}}
EQUIPE = SITE / "equipe.html"
FAQ = SITE / "faq-idiomas.json"
CURSOS = SITE / "matricula-cursos-presenciais" / "cursos.json"


def esc(s: str) -> str:
    return html.escape(str(s), quote=True)


def alternativas(pagina: str, atual: str) -> str:
    """As tags hreflang da página, para os três idiomas, mais o x-default no português."""
    caminhos = PAGINAS[pagina]
    pt = caminhos["pt"]
    linhas = []
    # Âncora não serve de hreflang. Quando o português é só uma seção ("/#faq"), o cluster fica
    # com en e es, e o x-default aponta para a página inteira, sem o "#".
    if "#" not in pt:
        linhas.append(f'  <link rel="alternate" hreflang="pt-BR" href="{ORIGEM}{pt}">')
    linhas += [f'  <link rel="alternate" hreflang="en" href="{ORIGEM}{caminhos["en"]}">',
               f'  <link rel="alternate" hreflang="es" href="{ORIGEM}{caminhos["es"]}">',
               f'  <link rel="alternate" hreflang="x-default" href="{ORIGEM}{pt.split("#")[0] or "/"}">']
    return "\n".join(linhas)


def seletor(pagina: str, atual: str) -> str:
    """Seletor de idioma: link direto, nunca redirecionamento pelo idioma do navegador.

    Redirecionar por Accept-Language prenderia o Googlebot — que vem "em inglês", dos EUA — numa
    versão só, e tiraria de quem quer ler em português a chance de escolher.
    """
    siglas = {"pt": ("PT", "Português", "pt-BR"), "en": ("EN", "English", "en"), "es": ("ES", "Español", "es")}
    itens = []
    for codigo, caminho in PAGINAS[pagina].items():
        sigla, nome, marca = siglas[codigo]
        if codigo == atual:
            itens.append(f'<span class="idioma-atual" aria-current="true" lang="{marca}">{sigla}</span>')
        else:
            itens.append(f'<a href="{caminho}" hreflang="{marca}" lang="{marca}" aria-label="{nome}">{sigla}</a>')
    return '<div class="seletor-idioma" role="navigation" aria-label="Language">' + " ".join(itens) + "</div>"


ESTILO_EXTRA = """
    /* O CSS de .seletor-idioma vem do CSS da home, copiado por partes_da_home(). */
    .i18n-hero { background:var(--soft); border-bottom:1px solid var(--line); padding:64px 0 48px }
    .i18n-hero h1 { font-size:clamp(2rem,3.4vw,3rem); color:var(--black); margin:0 0 14px }
    .i18n-hero p { color:var(--muted); max-width:720px; font-size:1.05rem; margin:0 }
    .i18n-bloco { padding:48px 0; border-bottom:1px solid var(--line) }
    .i18n-bloco h2 { font-size:1.5rem; margin:0 0 14px }
    .i18n-bloco p { color:var(--muted); max-width:760px; margin:0 0 14px }
    .i18n-principios { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-top:28px; list-style:none; padding:0 }
    .i18n-principios li { background:#fff; border:1px solid var(--line); border-radius:var(--radius); padding:18px 20px }
    .i18n-principios strong { display:block; color:var(--black); font-size:.95rem; margin-bottom:4px }
    .i18n-principios span { color:var(--muted); font-size:.9rem }
    .i18n-passos { display:grid; grid-template-columns:repeat(3,1fr); gap:20px; margin:28px 0 0; list-style:none; padding:0 }
    .i18n-passos li { background:#fff; border:1px solid var(--line); border-radius:var(--radius); padding:22px }
    .i18n-passos strong { display:block; margin-bottom:6px }
    .i18n-passos span { color:var(--muted); font-size:.94rem }
    .i18n-nota { color:var(--muted); font-size:.9rem; margin:18px 0 0; max-width:760px }
    .i18n-caixa { background:var(--soft); border:1px solid var(--line); border-radius:var(--radius); padding:24px; margin-top:24px }
    .i18n-caixa p { margin:0 }
    .i18n-caixa p + p { margin-top:14px }
    .i18n-tabela-rolo { overflow-x:auto; margin-top:24px }
    .i18n-tabela { width:100%; border-collapse:collapse; min-width:620px }
    .i18n-tabela th, .i18n-tabela td { text-align:left; padding:12px 14px; border-bottom:1px solid var(--line); font-size:.95rem }
    .i18n-tabela th { background:var(--soft); font-weight:800; font-size:.85rem; text-transform:uppercase; letter-spacing:.02em }
    .i18n-tabela td:last-child, .i18n-tabela th:last-child { white-space:nowrap }
    .i18n-fichas { display:grid; gap:18px; margin-top:24px }
    .i18n-fichas article { background:#fff; border:1px solid var(--line); border-radius:var(--radius); padding:20px 22px }
    .i18n-fichas h3 { margin:0 0 8px; font-size:1.05rem }
    .i18n-fichas h3 small { color:var(--muted); font-weight:600; font-size:.85rem; margin-left:8px }
    .i18n-fichas p { margin:0; color:var(--muted); font-size:.95rem }
    .i18n-porta { margin-top:12px !important }
    .i18n-porta a { color:var(--red); font-weight:800; text-decoration:none }
    .i18n-porta a:hover { text-decoration:underline }
    @media (min-width:920px) { .i18n-portas { grid-template-columns:repeat(2,1fr) } }
    .i18n-fichas ul { margin:10px 0 0; padding-left:18px; color:var(--muted); font-size:.92rem }
    .i18n-fichas li { margin:2px 0 }
    .i18n-diretoria article { text-align:center }
    .i18n-diretoria h3 { font-size:1rem }
    .i18n-diretoria p { color:var(--red); font-weight:800; font-size:.85rem; text-transform:uppercase; letter-spacing:.02em }
    @media (min-width:920px) { .i18n-fichas { grid-template-columns:1fr 1fr } .i18n-diretoria, .i18n-areas { grid-template-columns:repeat(3,1fr) } }
    @media (max-width:920px) { .i18n-principios, .i18n-passos { grid-template-columns:1fr } }
"""


def cabecalho(idioma: dict, pagina: str) -> str:
    """Cabeçalho da home com o menu traduzido e o seletor de idioma."""
    m, pasta = idioma["menu"], idioma["pasta"]
    links = [(f'/{pasta}/#sobre', m["sobre"]), (f'/{pasta}/#principios', m["principios"]),
             (PAGINAS["equipe"][idioma["codigo"]], m["equipe"]),
             (PAGINAS["cursos"][idioma["codigo"]], m["cursos"]),
             (PAGINAS["faq"][idioma["codigo"]], m["faq"]),
             (PAGINAS["doar"][idioma["codigo"]], m["doar"]), (f'/{pasta}/#contato', m["contato"]),
             ("/", m["portugues"])]
    nav = "\n          ".join(f'<a href="{a}">{esc(b)}</a>' for a, b in links)
    return f"""  <header class="main-header">
    <div class="header-container">
      <a href="/{pasta}/" class="logo-area" style="text-decoration:none;color:inherit;">
        <img alt="{esc(idioma['instituicao'])}" class="logo-img" src="/assets/otim/logo-cvb-rj-520.webp" width="520" height="156">
      </a>
      <button class="nav-toggle" type="button" aria-label="Menu" aria-expanded="false">
        <i class="fa-solid fa-bars"><svg class="ico ico-bars" aria-hidden="true" focusable="false"><use href="#i-bars"/></svg><svg class="ico ico-xmark" aria-hidden="true" focusable="false"><use href="#i-xmark"/></svg></i>
      </button>
      <div class="header-collapse">
        <nav class="nav-links">
          {nav}
        </nav>
        <div class="header-actions">
          {seletor(pagina, idioma['codigo'])}
        </div>
      </div>
    </div>
  </header>"""


def rodape(idioma: dict) -> str:
    r, pasta = idioma["rodape"], idioma["pasta"]
    return f"""  <footer>
    <div class="footer-grid">
      <div class="footer-brand">
        <img class="footer-logo" alt="{esc(idioma['instituicao'])}" src="/assets/otim/logo-cvb-rj-520.webp" width="520" height="156" loading="lazy" decoding="async">
        <p>{esc(r['principios'])}</p>
      </div>
      <div class="footer-col">
        <h4>{esc(r['sobre'])}</h4>
        <p>{esc(idioma['instituicao'])}</p>
        <p><a href="/">{esc(r['portugues'])}</a></p>
        <p><a href="{ESCOLA}" target="_blank" rel="noopener">{esc(r['plataforma'])}</a></p>
        <p><a href="{WIKI}" target="_blank" rel="noopener">{esc(r['wikipedia'])}</a></p>
      </div>
      <div class="footer-col">
        <h4>{esc(r['contato'])}</h4>
        <p>{esc(ENDERECO)}</p>
        <p><a href="mailto:{EMAIL}">{EMAIL}</a></p>
        <p>CNPJ 08.560.973/0001-97</p>
      </div>
    </div>
    <div class="footer-bottom">
      <div class="wrap">
        <span>&copy; 2026 {esc(idioma['instituicao'])}</span>
        <span class="sep">|</span>
        <a href="/privacidade/" hreflang="pt-BR" lang="pt-BR">{esc(r['privacidade'])}</a>
        <span class="sep">|</span>
        <a href="/termos/" hreflang="pt-BR" lang="pt-BR">{esc(r['termos'])}</a>
      </div>
    </div>
  </footer>"""


def moldura(idioma: dict, pagina: str, titulo: str, descricao: str, corpo: str, ld: list, partes: dict) -> str:
    caminho = PAGINAS[pagina][idioma["codigo"]]
    ld_json = json.dumps(ld, ensure_ascii=False, separators=(",", ":"))
    assert "</" not in ld_json, "JSON-LD não pode conter </ (fecharia o <script>)"
    html_ = f"""<!DOCTYPE html>
<html lang="{idioma['codigo']}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{esc(titulo)}</title>
  <meta name="description" content="{esc(descricao)}">
  <link rel="canonical" href="{ORIGEM}{caminho}">
{alternativas(pagina, idioma['codigo'])}
  <meta property="og:type" content="website">
  <meta property="og:locale" content="{idioma['og_locale']}">
  <meta property="og:title" content="{esc(titulo)}">
  <meta property="og:description" content="{esc(descricao)}">
  <meta property="og:url" content="{ORIGEM}{caminho}">
  <meta property="og:image" content="{ORIGEM}/assets/otim/og-home.jpg">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta name="twitter:card" content="summary_large_image">
  <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
  <link rel="apple-touch-icon" href="/assets/favicon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"></noscript>
  <style>{partes['estilo_sem_tag']}{ESTILO_EXTRA}</style>
  <script type="application/ld+json">{ld_json}</script>
{partes['ga4']}
{partes['pixel']}
</head>
<body>
{cabecalho(idioma, pagina)}
  <main>
{corpo}
  </main>
{rodape(idioma)}
{partes['menu_js']}
</body>
</html>
"""
    return icones.converter(html_)  # troca as tags do Font Awesome, injeta o sprite e o CSS


def pagina_home(idioma: dict, partes: dict) -> str:
    h = idioma["home"]
    blocos = []
    for i, b in enumerate(h["blocos"]):
        ident = ["sobre", "atuacao", "sede"][i] if i < 3 else f"bloco{i}"
        paras = "\n        ".join(f"<p>{esc(p)}</p>" for p in b["paragrafos"])
        blocos.append(f"""    <section class="i18n-bloco" id="{ident}">
      <div class="wrap">
        <h2>{esc(b['titulo'])}</h2>
        {paras}
      </div>
    </section>""")
    principios = "\n          ".join(
        f"<li><strong>{esc(a)}</strong><span>{esc(b)}</span></li>" for a, b in h["principios"])
    programas = "\n          ".join(
        f"<article><h3>{esc(a)}</h3><p>{esc(b)}</p></article>" for a, b in h["programas"])
    def porta(titulo: str, texto: str, rotulo: str, chave: str) -> str:
        """Uma porta de entrada: página gerada deste idioma, ou o formulário do voluntariado."""
        if chave == "voluntario":
            url = VOLUNTARIO
            extra = ' target="_blank" rel="noopener" hreflang="pt-BR" lang="pt-BR"'
        else:
            url, extra = PAGINAS[chave][idioma["codigo"]], ""
        return (f"<article><h3>{esc(titulo)}</h3><p>{esc(texto)}</p>"
                f'<p class="i18n-porta"><a href="{url}"{extra}>{esc(rotulo)} &rarr;</a></p></article>')

    caminhos = "\n          ".join(porta(*x) for x in h["caminhos"])
    corpo = f"""    <section class="i18n-hero">
      <div class="wrap">
        <p class="eyebrow">{esc(h['sobrancelha'])}</p>
        <h1>{esc(h['h1'])}</h1>
        <p>{esc(h['linha'])}</p>
      </div>
    </section>
{chr(10).join(blocos)}
    <section class="i18n-bloco" id="movimento">
      <div class="wrap">
        <h2>{esc(h['movimento_titulo'])}</h2>
        {chr(10).join("        <p>" + esc(x) + "</p>" for x in h["movimento_paragrafos"]).strip()}
      </div>
    </section>
    <section class="i18n-bloco" id="programas">
      <div class="wrap">
        <h2>{esc(h['programas_titulo'])}</h2>
        <p>{esc(h['programas_linha'])}</p>
        <div class="i18n-fichas">
          {programas}
        </div>
      </div>
    </section>
    <section class="i18n-bloco" id="caminhos">
      <div class="wrap">
        <h2>{esc(h['caminhos_titulo'])}</h2>
        <p>{esc(h['caminhos_linha'])}</p>
        <div class="i18n-fichas i18n-portas">
          {caminhos}
        </div>
      </div>
    </section>
    <section class="i18n-bloco" id="principios">
      <div class="wrap">
        <h2>{esc(h['principios_titulo'])}</h2>
        <ul class="i18n-principios">
          {principios}
        </ul>
      </div>
    </section>
    <section class="i18n-bloco" id="contato">
      <div class="wrap">
        <h2>{esc(h['cta_titulo'])}</h2>
        <p>{esc(h['cta_texto'])}</p>
        <div class="cta-row" style="margin-top:20px">
          <a class="btn btn-red" href="{PAGINAS['doar'][idioma['codigo']]}">{esc(h['cta_doar'])}</a>
          <a class="btn btn-outline" href="/matricula-cursos-presenciais/" hreflang="pt-BR" lang="pt-BR">{esc(h['cta_cursos'])}</a>
          <a class="btn btn-outline" href="mailto:{EMAIL}">{EMAIL}</a>
        </div>
        <p class="i18n-nota">{esc(h['aviso_cursos'])}</p>
      </div>
    </section>"""
    ld = [{"@context": "https://schema.org", "@type": "NGO", "@id": f"{ORIGEM}/#organizacao",
           "name": idioma["instituicao"], "url": f"{ORIGEM}{PAGINAS['home'][idioma['codigo']]}",
           # inLanguage é de CreativeWork, não de Organization: no NGO o validador recusa.
           "parentOrganization": {"@type": "NGO", "name": idioma["nacional"]},
           "taxID": "08.560.973/0001-97", "email": EMAIL,
           "address": {"@type": "PostalAddress", "streetAddress": "Praça da Cruz Vermelha, 10",
                       "addressLocality": "Rio de Janeiro", "addressRegion": "RJ",
                       "postalCode": "20230-130", "addressCountry": "BR"},
           "sameAs": [WIKI]},
          {"@context": "https://schema.org", "@type": "WebPage",
           "url": f"{ORIGEM}{PAGINAS['home'][idioma['codigo']]}", "name": idioma["home"]["titulo"],
           "description": idioma["home"]["descricao"], "inLanguage": idioma["codigo"],
           "isPartOf": {"@id": f"{ORIGEM}/#site"}}]
    return moldura(idioma, "home", h["titulo"], h["descricao"], corpo, ld, partes)


def pagina_doar(idioma: dict, partes: dict) -> str:
    d = idioma["doar"]
    passos = "\n          ".join(
        f"<li><strong>{esc(a)}</strong><span>{esc(b)}</span></li>" for a, b in d["como"])
    exterior = "\n          ".join(f"<p>{esc(x)}</p>" for x in d["exterior_paragrafos"])
    corpo = f"""    <section class="i18n-hero">
      <div class="wrap">
        <p class="eyebrow">{esc(d['sobrancelha'])}</p>
        <h1>{esc(d['h1'])}</h1>
        <p>{esc(d['linha'])}</p>
      </div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap">
        <h2>{esc(d['como_titulo'])}</h2>
        <ul class="i18n-passos">
          {passos}
        </ul>
        <div class="cta-row" style="margin-top:32px">
          <a class="btn btn-red" href="/doe/" hreflang="pt-BR" lang="pt-BR">{esc(d['botao'])}</a>
        </div>
        <p class="i18n-nota">{esc(d['botao_nota'])}</p>
      </div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap">
        <h2>{esc(d['exterior_titulo'])}</h2>
        <div class="i18n-caixa">
          {exterior}
        </div>
      </div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap">
        <h2>{esc(d['idioma_titulo'])}</h2>
        <div class="i18n-caixa"><p>{d['idioma_texto']}</p></div>
      </div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap">
        <h2>{esc(d['transparencia_titulo'])}</h2>
        <p>{esc(d['transparencia'])}</p>
        <h2 style="margin-top:32px">{esc(d['outra_forma_titulo'])}</h2>
        <p>{esc(d['outra_forma'])}</p>
        <div class="cta-row" style="margin-top:20px">
          <a class="btn btn-outline" href="mailto:{EMAIL}">{EMAIL}</a>
        </div>
      </div>
    </section>"""
    caminho = PAGINAS["doar"][idioma["codigo"]]
    ld = [{"@context": "https://schema.org", "@type": "WebPage", "url": f"{ORIGEM}{caminho}",
           "name": d["titulo"], "description": d["descricao"], "inLanguage": idioma["codigo"],
           "isPartOf": {"@id": f"{ORIGEM}/#site"}},
          {"@context": "https://schema.org", "@type": "DonateAction",
           "name": d["titulo"], "target": f"{ORIGEM}/doe/",
           "recipient": {"@id": f"{ORIGEM}/#organizacao"}}]
    return moldura(idioma, "doar", d["titulo"], d["descricao"], corpo, ld, partes)


def resposta_html(texto: str, links: list) -> str:
    """Transforma em <a> os trechos listados em `links`, casando pelo texto exato.

    Link para página que só existe em português (a matrícula, o formulário do voluntariado) leva
    hreflang/lang="pt-BR": avisa o buscador, e o leitor de tela, que ali a língua muda.
    """
    texto = esc(texto)
    for l in links:
        alvo, url = esc(l["texto"]), l["url"]
        if alvo not in texto:
            raise SystemExit(f"trecho do link não está na resposta: {l['texto']!r}")
        if url.startswith("http"):
            extra = ' target="_blank" rel="noopener"'
        elif url.startswith(("/en/", "/es/")):
            extra = ""
        else:
            extra = ' hreflang="pt-BR" lang="pt-BR"'
        texto = texto.replace(alvo, f'<a href="{esc(url)}"{extra}>{alvo}</a>', 1)
    return texto


def ler_equipe() -> tuple[list, list]:
    """Diretoria e coordenações lidas de equipe.html, que é a fonte de quem é quem.

    Os nomes não são traduzidos e não podem divergir entre as versões: manter uma segunda lista
    no idiomas.json garantiria que um dia as duas ficassem diferentes. Daqui sai só gente; os
    rótulos de cargo e de área são traduzidos no idiomas.json, casados pelo nome em português.
    """
    fonte = EQUIPE.read_text(encoding="utf-8")
    limpar = lambda s: html.unescape(re.sub(r"<[^>]+>", "", s)).strip()
    diretoria = [(limpar(c), limpar(n)) for c, n in
                 re.findall(r'<div class="role">(.*?)</div>\s*<div class="name">(.*?)</div>', fonte, re.S)]
    coordenacoes = [(limpar(a), [limpar(x) for x in re.findall(r"<li[^>]*>(.*?)</li>", pessoas, re.S)])
                    for a, _, pessoas in
                    re.findall(r'<div class="area-name">(.*?)</div>(.*?)<ul>(.*?)</ul>', fonte, re.S)]
    if len(diretoria) != 3 or len(coordenacoes) != 11:
        raise SystemExit(f"equipe.html mudou de forma: {len(diretoria)} na diretoria, "
                         f"{len(coordenacoes)} coordenações (esperado 3 e 11)")
    return diretoria, coordenacoes


def pagina_equipe(idioma: dict, partes: dict, equipe: tuple) -> str:
    """Diretoria, coordenações, a sede e como fazer parceria — em inglês e espanhol."""
    e = idioma["equipe"]
    diretoria, coordenacoes = equipe
    cartoes = "\n          ".join(
        f'<article><h3>{esc(nome)}</h3><p>{esc(e["cargos"].get(cargo, cargo))}</p></article>'
        for cargo, nome in diretoria)
    areas = []
    for area, pessoas in coordenacoes:
        titulo, sobre = e["areas"].get(area, (area, ""))
        gente = "".join(f"<li>{esc(x)}</li>" for x in pessoas)
        areas.append(f'<article><h3>{esc(titulo)}</h3><p>{esc(sobre)}</p><ul>{gente}</ul></article>')
    como = "\n          ".join(f"<p>{esc(x)}</p>" for x in e["como_paragrafos"])
    sede = "\n          ".join(f"<p>{esc(x)}</p>" for x in e["sede_paragrafos"])
    parceria = "\n        ".join(f"<p>{esc(x)}</p>" for x in e["parceria_paragrafos"])
    corpo = f"""    <section class="i18n-hero">
      <div class="wrap">
        <p class="eyebrow">{esc(e['sobrancelha'])}</p>
        <h1>{esc(e['h1'])}</h1>
        <p>{esc(e['linha'])}</p>
      </div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap">
        <h2>{esc(e['diretoria_titulo'])}</h2>
        <div class="i18n-fichas i18n-diretoria">
          {cartoes}
        </div>
      </div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap">
        <h2>{esc(e['como_titulo'])}</h2>
        <div class="i18n-caixa">
          {como}
        </div>
      </div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap">
        <h2>{esc(e['areas_titulo'])}</h2>
        <p>{esc(e['areas_linha'])}</p>
        <div class="i18n-fichas i18n-areas">
          {chr(10).join("          " + a for a in areas).strip()}
        </div>
      </div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap">
        <h2>{esc(e['sede_titulo'])}</h2>
        {sede}
      </div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap">
        <h2>{esc(e['parceria_titulo'])}</h2>
        {parceria}
        <div class="cta-row" style="margin-top:22px">
          <a class="btn btn-red" href="mailto:{EMAIL}">{EMAIL}</a>
          <a class="btn btn-outline" href="{VOLUNTARIO}" target="_blank" rel="noopener" hreflang="pt-BR" lang="pt-BR">{esc(e['cta_voluntario'])}</a>
        </div>
      </div>
    </section>"""
    caminho = PAGINAS["equipe"][idioma["codigo"]]
    ld = [{"@context": "https://schema.org", "@type": "AboutPage", "url": f"{ORIGEM}{caminho}",
           "name": e["titulo_aba"], "description": e["descricao"], "inLanguage": idioma["codigo"]}]
    return moldura(idioma, "equipe", e["titulo_aba"], e["descricao"], corpo, ld, partes)


def pagina_cursos(idioma: dict, partes: dict, cursos: dict) -> str:
    """Os sete cursos em inglês e espanhol.

    Não é tradução da página de vendas: é a ficha honesta, com a restrição dita antes da tabela
    (aula em português, presencial, no Rio, e matrícula que pede CPF). Carga horária, escolaridade
    e valor saem de cursos.json, a mesma fonte da página em português, para não saírem de sincronia.
    """
    c = idioma["cursos"]
    por_slug = {x["slug"]: x for x in cursos["cursos"]}
    linhas = []
    for slug, nome in c["nomes"].items():
        d_ = por_slug[slug]
        escolaridade = c["escolaridade"].get(d_["escolaridade"], d_["escolaridade"])
        horas = re.sub(r"\s*horas?", "", d_["carga_horaria"]).strip()
        valor = f'R$ {d_["valor_curso_centavos"] / 100:,.0f}'.replace(",", ".")
        linhas.append(f"            <tr><td>{esc(nome)}</td><td>{esc(horas)}</td>"
                      f"<td>{esc(escolaridade)}</td><td>{esc(valor)}</td></tr>")
    avisos = "\n          ".join(f"<p>{esc(x)}</p>" for x in c["aviso_paragrafos"])
    fichas = "\n          ".join(
        f'<article><h3>{esc(c["nomes"][s])} <small>{esc(por_slug[s]["carga_horaria"])}</small></h3>'
        f"<p>{esc(x)}</p></article>" for s, x in c["detalhes"].items())
    passos = "\n          ".join(
        f"<li><strong>{esc(a)}</strong><span>{esc(b)}</span></li>" for a, b in c["como"])
    corpo = f"""    <section class="i18n-hero">
      <div class="wrap">
        <p class="eyebrow">{esc(c['sobrancelha'])}</p>
        <h1>{esc(c['h1'])}</h1>
        <p>{esc(c['linha'])}</p>
      </div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap">
        <h2>{esc(c['aviso_titulo'])}</h2>
        <div class="i18n-caixa">
          {avisos}
        </div>
      </div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap">
        <h2>{esc(c['tabela_titulo'])}</h2>
        <div class="i18n-tabela-rolo">
          <table class="i18n-tabela">
            <thead><tr>{''.join(f'<th>{esc(h)}</th>' for h in c['cabecalho'])}</tr></thead>
            <tbody>
{chr(10).join(linhas)}
            </tbody>
          </table>
        </div>
        <p class="i18n-nota">{esc(c['tabela_nota'])}</p>
      </div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap">
        <h2>{esc(c['detalhe_titulo'])}</h2>
        <div class="i18n-fichas">
          {fichas}
        </div>
        <p class="i18n-nota">{esc(c['detalhe_nota'])}</p>
      </div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap">
        <h2>{esc(c['como_titulo'])}</h2>
        <ul class="i18n-passos">
          {passos}
        </ul>
        <div class="cta-row" style="margin-top:32px">
          <a class="btn btn-red" href="{PAGINAS['cursos']['pt']}" hreflang="pt-BR" lang="pt-BR">{esc(c['botao'])}</a>
          <a class="btn btn-outline" href="{ESCOLA}" target="_blank" rel="noopener" hreflang="pt-BR" lang="pt-BR">{esc(idioma['menu']['plataforma'])}</a>
        </div>
        <p class="i18n-nota">{esc(c['botao_nota'])}</p>
      </div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap">
        <h2>{esc(c['faq_titulo'])}</h2>
        <p>{esc(c['faq_texto'])}</p>
        <div class="cta-row" style="margin-top:20px">
          <a class="btn btn-outline" href="{PAGINAS['faq'][idioma['codigo']]}">{esc(c['faq_link'])}</a>
        </div>
      </div>
    </section>"""
    caminho = PAGINAS["cursos"][idioma["codigo"]]
    ld = [{"@context": "https://schema.org", "@type": "WebPage", "url": f"{ORIGEM}{caminho}",
           "name": c["titulo_aba"], "description": c["descricao"], "inLanguage": idioma["codigo"]}]
    return moldura(idioma, "cursos", c["titulo_aba"], c["descricao"], corpo, ld, partes)


def pagina_faq(idioma: dict, partes: dict, faq: dict) -> str:
    """A FAQ do idioma, com a mesma marcação <details> da home em português (o CSS vem copiado)."""
    d = faq[idioma["codigo"]]
    blocos, entidades, primeira = [], [], True
    for g in d["grupos"]:
        blocos.append(f'          <h3 class="faq-grupo">{esc(g["titulo"])}</h3>')
        for q in g["perguntas"]:
            pergunta, resposta = q["pergunta"].strip(), q["resposta"].strip()
            blocos.append(
                f'          <details class="faq-item"{" open" if primeira else ""}>\n'
                f'            <summary>{esc(pergunta)}</summary>\n'
                f'            <div class="faq-answer">{resposta_html(resposta, q.get("links", []))}</div>\n'
                f'          </details>')
            entidades.append({"@type": "Question", "name": pergunta,
                              "acceptedAnswer": {"@type": "Answer", "text": resposta}})
            primeira = False
    corpo = f"""    <section class="i18n-hero">
      <div class="wrap">
        <p class="eyebrow">{esc(d['sobrancelha'])}</p>
        <h1>{esc(d['titulo'])}</h1>
        <p>{esc(d['subtitulo'])}</p>
      </div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap">
        <div class="faq-list">
{chr(10).join(blocos)}
        </div>
        <p class="i18n-nota">{esc(d['nota'])}</p>
        <div class="cta-row" style="margin-top:20px">
          <a class="btn btn-outline" href="mailto:{EMAIL}">{EMAIL}</a>
        </div>
      </div>
    </section>"""
    caminho = PAGINAS["faq"][idioma["codigo"]]
    ld = [{"@context": "https://schema.org", "@type": "FAQPage", "@id": f"{ORIGEM}{caminho}#faq",
           "url": f"{ORIGEM}{caminho}", "name": d["titulo"], "inLanguage": idioma["codigo"],
           "mainEntity": entidades}]
    return moldura(idioma, "faq", d["titulo_aba"], d["descricao"], corpo, ld, partes)


def main() -> int:
    dados = json.loads(DADOS.read_text(encoding="utf-8"))
    partes = base.partes_da_home(HOME.read_text(encoding="utf-8"))
    # partes['estilo'] vem com as tags <style>: aqui o CSS entra numa tag nossa, com o extra junto.
    partes["estilo_sem_tag"] = re.sub(r"^\s*<style>|</style>\s*$", "", partes["estilo"])
    faq = json.loads(FAQ.read_text(encoding="utf-8"))
    cursos = json.loads(CURSOS.read_text(encoding="utf-8"))
    equipe = ler_equipe()
    total = 0
    for codigo, idioma in dados["idiomas"].items():
        for nome, gerar in (("home", pagina_home), ("doar", pagina_doar),
                            ("faq", lambda i, p: pagina_faq(i, p, faq)),
                            ("cursos", lambda i, p: pagina_cursos(i, p, cursos)),
                            ("equipe", lambda i, p: pagina_equipe(i, p, equipe))):
            destino = SITE / PAGINAS[nome][codigo].strip("/") / "index.html"
            destino.parent.mkdir(parents=True, exist_ok=True)
            # Sem o widget de chat: a interface dele é toda em português, e abrir um chat em
            # português para quem está lendo em inglês é pior do que não ter chat. Estas páginas
            # põem o e-mail em evidência, e a equipe responde nos três idiomas.
            html_ = gerar(idioma, partes)
            destino.write_text(html_, encoding="utf-8")
            print(f"gravado {destino.relative_to(RAIZ)} ({len(html_.encode('utf-8'))} bytes)")
            total += 1
    print(f"{total} páginas em {len(dados['idiomas'])} idiomas")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
