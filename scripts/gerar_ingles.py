#!/usr/bin/env python3
"""Gera as páginas em inglês que não saem de gerar_idiomas.py.

  site/en/news/                   índice das notícias traduzidas
  site/en/news/<slug>/            uma página por matéria (traducoes/en/noticias/<slug>.md)
  site/en/winter-clothing-drive/  Campanha do Agasalho
  site/en/404.html                servido pelo site/en/.htaccess para tudo que estiver sob /en/

Privacidade, Cookies, Termos e Reembolso em inglês são gerados por gerar_politicas.py, não por
este script (desde 27/09/2026).

Cabeçalho, rodapé, CSS e moldura vêm de gerar_idiomas.py: o visual é o das outras páginas em
inglês. As notícias são traduções das matérias da Redação (que continuam sendo a fonte): cada uma
declara hreflang para a original em português, cita a data da original e linka para ela.

Cada .md de traducoes/en/noticias/ tem um cabeçalho com os campos da Redação (título ≤ 120,
linha fina ≤ 200, endereço ≤ 80, que é o nome do arquivo) e o corpo em Markdown. O gerador
recusa campo fora do limite, link interno para página que não existe e marcador ⟦ ⟧ esquecido.

Uso:  python3 scripts/gerar_ingles.py   (depois de gerar_idiomas.py; publicar site/en/)
"""
from __future__ import annotations

import html
import json
import math
import re
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path
from urllib.parse import quote

import gerar_idiomas as gi

RAIZ = gi.RAIZ
SITE = gi.SITE
ORIGEM = gi.ORIGEM
FONTE = RAIZ / "traducoes" / "en"
MESES = ["January", "February", "March", "April", "May", "June", "July", "August", "September",
         "October", "November", "December"]
BRASILIA = timezone(timedelta(hours=-3))
LIMITES = {"titulo": 120, "linha_fina": 200, "slug": 80, "corpo": 20000}
esc = gi.esc

ESTILO = """
    .migalhas { font-size:.85rem; color:var(--muted); margin:0 0 14px }
    .migalhas a { color:var(--muted) } .migalhas a:hover { color:var(--red) }
    .materia-estreita { max-width:780px }
    .materia-linhafina { font-size:1.12rem !important; color:var(--muted); margin:0 0 18px !important }
    .materia-meta { font-size:.9rem !important; color:var(--muted); margin:0 !important }
    .materia-meta b { color:var(--black) }
    .materia-compartilhar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:16px; font-size:.85rem; color:var(--muted) }
    .materia-compartilhar a, .materia-compartilhar button { display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; border:1px solid var(--line); background:#fff; color:var(--black); cursor:pointer; font:inherit }
    .materia-compartilhar a:hover, .materia-compartilhar button:hover { color:var(--red); border-color:var(--red) }
    .materia-compartilhar .ico { width:16px; height:16px }
    .materia { padding:32px 0 8px }
    .materia figure { margin:0 0 28px }
    .materia figure img { width:100%; height:auto; border-radius:var(--radius); display:block; background:var(--soft) }
    .materia figure.retrato img { max-width:480px; margin:0 auto }
    .materia figcaption { font-size:.85rem; color:var(--muted); margin-top:8px }
    .materia figcaption .credito { display:block; font-size:.78rem; opacity:.85 }
    .i18n-prosa { font-size:1.04rem; line-height:1.7; color:var(--text, #222) }
    .i18n-prosa h2 { font-size:1.4rem; margin:36px 0 12px; color:var(--black) }
    .i18n-prosa h3 { font-size:1.12rem; margin:26px 0 10px; color:var(--black) }
    .i18n-prosa p, .i18n-prosa ul, .i18n-prosa ol { margin:0 0 16px }
    .i18n-prosa li { margin:4px 0 }
    .i18n-prosa a { color:var(--red); font-weight:700; text-decoration:underline; text-underline-offset:2px; overflow-wrap:anywhere }
    .i18n-prosa blockquote { margin:0 0 18px; padding:16px 20px; background:var(--soft); border-left:4px solid var(--red); border-radius:0 var(--radius) var(--radius) 0 }
    .i18n-prosa .cta-row { margin:22px 0 }
    .i18n-prosa .cta-row a { color:inherit; text-decoration:none }
    .i18n-prosa .cta-row a.btn-red { color:#fff }
    .materia-original { font-size:.92rem; color:var(--muted); border-top:1px solid var(--line); padding-top:16px; margin-top:28px }
    .materia-original a { color:var(--red); font-weight:700 }
    .materia-grade { display:grid; gap:28px }
    .materia-grade h2 { font-size:1.2rem; margin:0 0 12px }
    .materia-lista { list-style:none; padding:0; margin:0 }
    .materia-lista li { border-bottom:1px solid var(--line); padding:12px 0 }
    .materia-lista a { color:var(--black); font-weight:700; text-decoration:none }
    .materia-lista a:hover { color:var(--red) }
    .materia-lista time { display:block; color:var(--muted); font-size:.82rem; margin-top:2px }
    .materia-caixa ul { margin:0 0 14px; padding-left:18px; color:var(--muted); font-size:.94rem }
    .materia-caixa li { margin:6px 0 }
    .materia-caixa a { color:var(--red); font-weight:800; text-decoration:none }
    @media (min-width:920px) { .materia-grade { grid-template-columns:1.4fr 1fr } }
    .noticias-cartoes { display:grid; gap:20px; margin-top:24px }
    .noticias-cartoes article { background:#fff; border:1px solid var(--line); border-radius:var(--radius); overflow:hidden; display:flex; flex-direction:column }
    .noticias-cartoes .capa { display:block; aspect-ratio:16/9; overflow:hidden; background:var(--soft) }
    .noticias-cartoes .capa img { width:100%; height:100%; object-fit:cover; display:block }
    .noticias-cartoes .corpo { padding:16px 18px 18px }
    .noticias-cartoes h3 { font-size:1.02rem; margin:0 0 6px; line-height:1.35 }
    .noticias-cartoes h3 a { color:var(--black); text-decoration:none }
    .noticias-cartoes h3 a:hover { color:var(--red) }
    .noticias-cartoes time { display:block; color:var(--muted); font-size:.8rem; margin-bottom:8px }
    .noticias-cartoes p { color:var(--muted); font-size:.92rem; margin:0 }
    @media (min-width:700px) { .noticias-cartoes { grid-template-columns:repeat(2,1fr) } }
    @media (min-width:1080px) { .noticias-cartoes { grid-template-columns:repeat(3,1fr) } }
    .i18n-legal-aviso, .i18n-hero .i18n-legal-aviso { background:var(--soft); border:1px solid var(--line); border-radius:var(--radius); padding:14px 18px; font-size:.92rem; color:var(--muted); margin:18px 0 0; max-width:760px }
    .i18n-legal-aviso a { color:var(--red); font-weight:700 }
    .i18n-fotos { display:grid; gap:16px; margin-top:28px }
    .i18n-fotos figure { margin:0 }
    .i18n-fotos img { width:100%; height:auto; border-radius:var(--radius); display:block }
    .i18n-fotos figcaption { font-size:.85rem; color:var(--muted); margin-top:6px }
    @media (min-width:700px) { .i18n-fotos { grid-template-columns:1fr 1fr } }
    .i18n-faixa { display:grid; gap:12px; list-style:none; padding:0; margin:0 }
    .i18n-faixa li { background:#fff; border:1px solid var(--line); border-radius:var(--radius); padding:14px 16px }
    .i18n-faixa strong { display:block; font-size:.8rem; text-transform:uppercase; letter-spacing:.03em; color:var(--red) }
    .i18n-faixa span { color:var(--black); font-weight:600; overflow-wrap:anywhere }
    @media (min-width:920px) { .i18n-faixa { grid-template-columns:repeat(4,1fr) } }
    .i18n-lado { display:grid; gap:28px; align-items:start }
    .i18n-lado img { width:100%; height:auto; border-radius:var(--radius); display:block }
    @media (min-width:920px) { .i18n-lado { grid-template-columns:1fr 1fr } }
    .i18n-erro-links { display:flex; flex-wrap:wrap; gap:10px; margin-top:24px }
"""


# ----------------------------------------------------------------------------- dados

def ler_cabecalho(texto: str, arquivo: Path) -> tuple[dict, str]:
    """Separa o cabeçalho `chave: valor` (entre linhas ---) do corpo em Markdown."""
    m = re.match(r"---\n(.*?)\n---\n(.*)", texto.replace("\r\n", "\n"), re.S)
    if not m:
        raise SystemExit(f"{arquivo.name}: falta o cabeçalho entre linhas ---")
    campos = {}
    for linha in m.group(1).splitlines():
        chave, _, valor = linha.partition(":")
        campos[chave.strip()] = valor.strip()
    return campos, m.group(2).strip() + "\n"


def ler_noticias() -> list[dict]:
    noticias = []
    for arquivo in sorted((FONTE / "noticias").glob("*.md")):
        campos, corpo = ler_cabecalho(arquivo.read_text(encoding="utf-8"), arquivo)
        campos["slug"], campos["corpo"] = arquivo.stem, corpo
        for chave, limite in LIMITES.items():
            if len(campos[chave]) > limite:
                raise SystemExit(f"{arquivo.name}: {chave} com {len(campos[chave])} caracteres (limite {limite})")
        if "⟦" in corpo or "⟧" in corpo:
            raise SystemExit(f"{arquivo.name}: marcador ⟦ ⟧ esquecido no texto")
        campos["url"] = f"/en/news/{campos['slug']}/"
        campos["url_pt"] = f"/noticias/{campos['slug_pt']}/"
        campos["pasta_img"] = f"/noticias/{campos['slug_pt']}/"
        noticias.append(campos)
    noticias.sort(key=lambda n: n["publicado"], reverse=True)
    return noticias


def data_extenso(iso: str) -> str:
    """'2026-09-03T04:14:21.408Z' → '3 September 2026', no horário de Brasília."""
    quando = datetime.fromisoformat(iso.replace("Z", "+00:00")).astimezone(BRASILIA)
    return f"{quando.day} {MESES[quando.month - 1]} {quando.year}"


def medidas(texto: str) -> tuple[int, int]:
    largura, altura = texto.lower().split("x")
    return int(largura), int(altura)


# ----------------------------------------------------------------------------- Markdown

def atributos_link(url: str) -> str:
    """Link para página só em português avisa a troca de língua; externo abre em outra aba."""
    if url.startswith(("http://", "https://")):
        return ' target="_blank" rel="noopener"'
    if url.startswith(("/en/", "mailto:", "#")):
        return ""
    return ' hreflang="pt-BR" lang="pt-BR"'


def em_linha(texto: str) -> str:
    """Negrito, itálico e links, com o resto escapado."""
    partes, pos = [], 0
    for m in re.finditer(r"\[([^\]]+)\]\(([^)\s]+)\)", texto):
        partes.append(esc(texto[pos:m.start()]))
        url = m.group(2)
        partes.append(f'<a href="{esc(url)}"{atributos_link(url)}>{esc(m.group(1))}</a>')
        pos = m.end()
    partes.append(esc(texto[pos:]))
    # Os enfeites vêm depois dos links: "**[texto](url)**" precisa virar negrito em volta do link.
    return enfeites("".join(partes))


def enfeites(texto: str) -> str:
    texto = re.sub(r"\*\*(.+?)\*\*", r"<strong>\1</strong>", texto)
    return re.sub(r"(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])", r"<em>\1</em>", texto)


def figura(alt: str, src: str, dim: str, legenda: str, credito: str, pasta: str, prioridade: bool = False) -> str:
    largura, altura = medidas(dim)
    carga = 'fetchpriority="high" decoding="async"' if prioridade else 'loading="lazy" decoding="async"'
    classe = ' class="retrato"' if altura > largura else ""
    legenda_html = ""
    if legenda or credito:
        legenda_html = f"<figcaption>{em_linha(legenda)}"
        legenda_html += f'<span class="credito">{em_linha(credito)}</span>' if credito else ""
        legenda_html += "</figcaption>"
    return (f'<figure{classe}><img src="{esc(pasta + src)}" alt="{esc(alt)}" width="{largura}" '
            f'height="{altura}" {carga}>{legenda_html}</figure>')


def markdown(corpo: str, pasta_img: str = "") -> str:
    """O Markdown das matérias: títulos, parágrafos, listas, citações, imagens com legenda.

    Um parágrafo feito só de links separados por " · " vira fileira de botões (o primeiro em
    vermelho): é assim que as matérias de curso levam à página em inglês e à da escola.
    """
    blocos = re.split(r"\n\s*\n", corpo.strip())
    saida = []
    for bloco in blocos:
        linhas = bloco.split("\n")
        img = re.match(r'!\[([^\]]*)\]\(([^)\s]+)\s+"(\d+x\d+)"\)$', linhas[0])
        if img:
            # Legenda na linha de baixo, em itálico; o crédito vem depois do último " — ".
            legenda = linhas[1].strip().strip("*") if len(linhas) > 1 else ""
            texto, _, credito = legenda.rpartition(" — ") if " — " in legenda else (legenda, "", "")
            saida.append(figura(img.group(1), img.group(2), img.group(3), texto, credito, pasta_img))
        elif bloco.startswith("### "):
            saida.append(f"<h3>{em_linha(bloco[4:])}</h3>")
        elif bloco.startswith("## "):
            saida.append(f"<h2>{em_linha(bloco[3:])}</h2>")
        elif all(l.startswith("- ") for l in linhas):
            itens = "".join(f"<li>{em_linha(l[2:])}</li>" for l in linhas)
            saida.append(f"<ul>{itens}</ul>")
        elif all(re.match(r"\d+\. ", l) for l in linhas):
            itens = "".join("<li>" + em_linha(re.sub(r"^\d+\. ", "", l)) + "</li>" for l in linhas)
            saida.append(f"<ol>{itens}</ol>")
        elif all(l.startswith(">") for l in linhas):
            dentro = "<br>".join(em_linha(l.lstrip("> ").strip()) for l in linhas)
            saida.append(f"<blockquote><p>{dentro}</p></blockquote>")
        elif re.fullmatch(r"\[[^\]]+\]\([^)]+\)( · \[[^\]]+\]\([^)]+\))+", bloco):
            botoes = []
            for i, (rotulo, url) in enumerate(re.findall(r"\[([^\]]+)\]\(([^)]+)\)", bloco)):
                classe = "btn btn-red" if i == 0 else "btn btn-outline"
                botoes.append(f'<a class="{classe}" href="{esc(url)}"{atributos_link(url)}>{esc(rotulo)}</a>')
            saida.append(f'<div class="cta-row">{"".join(botoes)}</div>')
        else:
            saida.append(f"<p>{em_linha(' '.join(l.strip() for l in linhas))}</p>")
    return "\n".join(saida)


# ----------------------------------------------------------------------------- moldura

def registrar(chave: str, pt: str, en: str) -> None:
    """Página só em inglês e português: o seletor leva o ES à institucional em espanhol."""
    gi.PAGINAS[chave] = {"pt": pt, "en": en, "es": "/es/"}
    SO_PT_EN.add(chave)


SO_PT_EN: set[str] = set()
_alternativas_originais = gi.alternativas


def alternativas(pagina: str, atual: str) -> str:
    """Nas páginas sem espanhol, o cluster é pt-BR + en, com x-default no português."""
    if pagina not in SO_PT_EN:
        return _alternativas_originais(pagina, atual)
    pt, en = gi.PAGINAS[pagina]["pt"], gi.PAGINAS[pagina]["en"]
    return "\n".join([f'  <link rel="alternate" hreflang="pt-BR" href="{ORIGEM}{pt}">',
                      f'  <link rel="alternate" hreflang="en" href="{ORIGEM}{en}">',
                      f'  <link rel="alternate" hreflang="x-default" href="{ORIGEM}{pt}">'])


gi.alternativas = alternativas
gi.ESTILO_EXTRA += ESTILO


def moldura(idioma: dict, pagina: str, titulo: str, descricao: str, corpo: str, ld: list, partes: dict,
            og_imagem: tuple[str, int, int] | None = None, artigo: dict | None = None) -> str:
    """A moldura de gerar_idiomas.py, com imagem de compartilhamento própria e og:type article."""
    html_ = gi.moldura(idioma, pagina, titulo, descricao, corpo, ld, partes)
    if og_imagem:
        url, largura, altura = og_imagem
        html_ = re.sub(r'<meta property="og:image" content="[^"]*">',
                       f'<meta property="og:image" content="{esc(url)}">', html_, count=1)
        html_ = html_.replace('<meta property="og:image:width" content="1200">',
                              f'<meta property="og:image:width" content="{largura}">', 1)
        html_ = html_.replace('<meta property="og:image:height" content="630">',
                              f'<meta property="og:image:height" content="{altura}">', 1)
    if artigo:
        html_ = html_.replace('<meta property="og:type" content="website">',
                              '<meta property="og:type" content="article">\n'
                              f'  <meta property="article:published_time" content="{artigo["publicado"]}">\n'
                              f'  <meta property="article:modified_time" content="{artigo["modificado"]}">', 1)
    return html_


def migalhas(idioma: dict, itens: list[tuple[str, str]]) -> tuple[str, dict]:
    """Trilha visível e o BreadcrumbList correspondente."""
    trilha = [(idioma["instituicao_curta"], "/en/")] + itens
    visivel = " &rsaquo; ".join(f'<a href="{u}">{esc(n)}</a>' for n, u in trilha[:-1])
    ld = {"@context": "https://schema.org", "@type": "BreadcrumbList", "itemListElement": [
        {"@type": "ListItem", "position": i, "name": n, "item": f"{ORIGEM}{u}"} for i, (n, u) in enumerate(trilha, 1)]}
    # div, não nav: o CSS da home esconde todo <nav> no celular (é a regra do menu sanfona).
    return f'<div class="migalhas" role="navigation" aria-label="Breadcrumb">{visivel}</div>', ld


def organizacao(idioma: dict) -> dict:
    return {"@type": "NGO", "@id": f"{ORIGEM}/#organizacao", "name": gi.NOME_OFICIAL,
            "alternateName": idioma["instituicao"], "url": f"{ORIGEM}/"}


# ----------------------------------------------------------------------------- páginas

def compartilhar(rot: dict, url: str, titulo: str) -> str:
    u, t = quote(url, safe=""), quote(titulo, safe="")
    redes = [("WhatsApp", f"https://wa.me/?text={t}%20{u}", "whatsapp"),
             ("Facebook", f"https://www.facebook.com/sharer/sharer.php?u={u}", "facebook-f"),
             ("LinkedIn", f"https://www.linkedin.com/sharing/share-offsite/?url={u}", "linkedin-in")]
    links = "".join(f'<a href="{esc(h)}" target="_blank" rel="noopener" aria-label="{esc(rot["compartilhar_em"])} {n}">'
                    f'<i class="fa-brands fa-{i}"></i></a>' for n, h, i in redes)
    botao = (f'<button type="button" class="copiar-link" data-url="{esc(url)}" data-ok="{esc(rot["copiado"])}" '
             f'aria-label="{esc(rot["copiar"])}"><i class="fa-regular fa-copy"></i></button>')
    return f'<div class="materia-compartilhar"><span>{esc(rot["compartilhe"])}</span>{links}{botao}</div>'


COPIAR_JS = """<script>
document.querySelectorAll('.copiar-link').forEach(function (b) {
  b.addEventListener('click', function () {
    if (!navigator.clipboard) return;
    navigator.clipboard.writeText(b.dataset.url).then(function () { b.setAttribute('aria-label', b.dataset.ok); b.style.color = 'var(--red)'; });
  });
});
</script>"""


def pagina_noticia(idioma: dict, partes: dict, P: dict, n: dict, todas: list[dict]) -> str:
    rot = P["materia"]
    url = f"{ORIGEM}{n['url']}"
    palavras = len(re.sub(r"[#*\[\]()!>-]", " ", n["corpo"]).split())
    minutos = max(1, math.ceil(palavras / 200))
    trilha, ld_trilha = migalhas(idioma, [(rot["breadcrumb"], "/en/news/"), (n["titulo"], n["url"])])
    capa = ""
    if n.get("capa"):
        capa = figura(n["capa_alt"], n["capa"], n["capa_medidas"], n.get("capa_legenda", ""),
                      n.get("capa_credito", ""), n["pasta_img"], prioridade=True)
    # Leia também: as mais recentes do mesmo grupo, completadas com as do outro.
    outras = [x for x in todas if x is not n]
    relacionadas = ([x for x in outras if x["grupo"] == n["grupo"]] + [x for x in outras if x["grupo"] != n["grupo"]])[:4]
    lista = "".join(f'<li><a href="{x["url"]}">{esc(x["titulo"])}</a><time datetime="{x["publicado"]}">'
                    f'{data_extenso(x["publicado"])}</time></li>' for x in relacionadas)
    itens_caixa = "".join(f"<li>{esc(t)}</li>" for t in rot["para_entender_itens"])
    original = esc(rot["original"].replace("{data}", data_extenso(n["publicado"])))
    corpo = f"""    <section class="i18n-hero">
      <div class="wrap materia-estreita">
        {trilha}
        <p class="eyebrow">{esc(rot['kicker'][n['grupo']])}</p>
        <h1>{esc(n['titulo'])}</h1>
        <p class="materia-linhafina">{esc(n['linha_fina'])}</p>
        <p class="materia-meta"><b>{esc(rot['por'])}</b> · <time datetime="{n['publicado']}">{data_extenso(n['publicado'])}</time> · {minutos} {esc(rot['leitura'])}</p>
        {compartilhar(rot, url, n['titulo'])}
      </div>
    </section>
    <article class="materia">
      <div class="wrap materia-estreita">
        {capa}
        <div class="i18n-prosa">
{markdown(n['corpo'], n['pasta_img'])}
        </div>
        <p class="materia-original">{original} <a href="{n['url_pt']}" hreflang="pt-BR" lang="pt-BR">{esc(rot['original_link'])} &rarr;</a></p>
      </div>
    </article>
    <section class="i18n-bloco">
      <div class="wrap materia-estreita">
        <div class="materia-grade">
          <div>
            <h2>{esc(rot['leia_tambem'])}</h2>
            <ul class="materia-lista">{lista}</ul>
            <p class="i18n-porta"><a href="/en/news/">{esc(rot['todas'])} &rarr;</a></p>
          </div>
          <aside class="i18n-caixa materia-caixa">
            <h2>{esc(rot['para_entender'])}</h2>
            <ul>{itens_caixa}</ul>
            <a href="{rot['cta_url']}">{esc(rot['cta'])} &rarr;</a>
          </aside>
        </div>
      </div>
    </section>
{COPIAR_JS}"""
    img_url = f"{ORIGEM}{n['pasta_img']}{n['capa']}" if n.get("capa") else None
    ld_artigo = {"@context": "https://schema.org", "@type": "NewsArticle", "@id": f"{url}#artigo",
                 "headline": n["titulo"], "description": n["linha_fina"], "inLanguage": "en",
                 "datePublished": n["publicado"], "dateModified": n["modificado"],
                 "mainEntityOfPage": {"@type": "WebPage", "@id": url},
                 "author": organizacao(idioma), "publisher": organizacao(idioma),
                 "isPartOf": {"@type": "WebSite", "@id": f"{ORIGEM}/#site", "url": f"{ORIGEM}/"},
                 "translationOfWork": {"@type": "NewsArticle", "@id": f"{ORIGEM}{n['url_pt']}", "inLanguage": "pt-BR"},
                 "wordCount": palavras}
    if img_url:
        ld_artigo["image"] = [img_url]
    chave = f"noticia:{n['slug']}"
    registrar(chave, n["url_pt"], n["url"])
    og = (img_url, *medidas(n["capa_medidas"])) if img_url else None
    return moldura(idioma, chave, n["titulo_seo"], n["linha_fina"], corpo, [ld_artigo, ld_trilha], partes,
                   og_imagem=og, artigo=n)


def pagina_indice(idioma: dict, partes: dict, P: dict, todas: list[dict]) -> str:
    d = P["noticias"]
    trilha, ld_trilha = migalhas(idioma, [(d["sobrancelha"], "/en/news/")])
    secoes = []
    for grupo, (titulo, linha) in d["grupos"].items():
        cartoes = []
        for n in (x for x in todas if x["grupo"] == grupo):
            largura, altura = medidas(n["capa_medidas"])
            cartoes.append(
                f'<article><a class="capa" href="{n["url"]}" tabindex="-1" aria-hidden="true"><img src="{esc(n["pasta_img"] + n["capa"])}" '
                f'alt="" width="{largura}" height="{altura}" loading="lazy" decoding="async"></a>'
                f'<div class="corpo"><h3><a href="{n["url"]}">{esc(n["titulo"])}</a></h3>'
                f'<time datetime="{n["publicado"]}">{data_extenso(n["publicado"])}</time>'
                f'<p>{esc(n["linha_fina"])}</p></div></article>')
        secoes.append(f"""    <section class="i18n-bloco" id="{grupo}">
      <div class="wrap">
        <h2>{esc(titulo)}</h2>
        <p>{esc(linha)}</p>
        <div class="noticias-cartoes">
          {chr(10).join(cartoes)}
        </div>
      </div>
    </section>""")
    corpo = f"""    <section class="i18n-hero">
      <div class="wrap">
        {trilha}
        <p class="eyebrow">{esc(d['sobrancelha'])}</p>
        <h1>{esc(d['h1'])}</h1>
        <p>{esc(d['linha'])}</p>
        <p class="i18n-legal-aviso">{esc(d['aviso'])} <a href="/noticias/" hreflang="pt-BR" lang="pt-BR">{esc(d['portugues'])} &rarr;</a></p>
      </div>
    </section>
{chr(10).join(secoes)}"""
    url = f"{ORIGEM}/en/news/"
    ld = [{"@context": "https://schema.org", "@type": "CollectionPage", "@id": f"{url}#pagina", "url": url,
           "name": d["h1"], "description": d["descricao"], "inLanguage": "en",
           "isPartOf": {"@type": "WebSite", "@id": f"{ORIGEM}/#site", "url": f"{ORIGEM}/"},
           "mainEntity": {"@type": "ItemList", "numberOfItems": len(todas), "itemListElement": [
               {"@type": "ListItem", "position": i, "url": f"{ORIGEM}{n['url']}", "name": n["titulo"]}
               for i, n in enumerate(todas, 1)]}},
          ld_trilha]
    registrar("noticias", "/noticias/", "/en/news/")
    return moldura(idioma, "noticias", d["titulo"], d["descricao"], corpo, ld, partes)


def pagina_campanha(idioma: dict, partes: dict, P: dict) -> str:
    c = P["campanha"]
    trilha, ld_trilha = migalhas(idioma, [(c["h1"], P["caminhos"]["campanha"])])

    def foto(nome: str, dim: str, alt: str, legenda: str = "", prioridade: bool = False) -> str:
        largura, altura = medidas(dim)
        grande = 1264 if nome != "agasalho-cartaz" else 768
        carga = 'fetchpriority="high"' if prioridade else 'loading="lazy" decoding="async"'
        leg = f"<figcaption>{esc(legenda)}</figcaption>" if legenda else ""
        return (f'<figure><img src="/assets/otim/{nome}-640.webp" srcset="/assets/otim/{nome}-640.webp 640w, '
                f'/assets/otim/{nome}-{grande}.webp {grande}w" sizes="(max-width: 700px) 100vw, 560px" '
                f'alt="{esc(alt)}" width="{largura}" height="{altura}" {carga}>{leg}</figure>')

    fotos = "".join(foto(n, d, a, l, prioridade=(i == 0)) for i, (n, d, a, l) in enumerate(c["fotos"]))
    faixa = "".join(f"<li><strong>{esc(a)}</strong><span>{esc(b)}</span></li>" for a, b in c["faixa"])
    itens = "".join(f"<article><h3>{esc(a)}</h3><p>{esc(b)}</p></article>" for a, b in c["itens"])
    como = "".join(f'<article><h3>{i}. {esc(a)}</h3><p>{esc(b)}</p><p class="i18n-porta"><a href="{esc(u)}">{esc(r)} &rarr;</a></p></article>'
                   for i, (a, b, r, u) in enumerate(c["como"], 1))
    principios = "".join(f"<li><strong>{esc(a)}</strong><span>{esc(b)}</span></li>" for a, b in c["principios"])
    caso = "".join(f"<p>{esc(p)}</p>" for p in c["caso_paragrafos"])
    agradecimento = "".join(f"<p>{esc(p)}</p>" for p in c["agradecimento_paragrafos"])
    doar, entregar = c["como"][0][3], "#entregar"
    corpo = f"""    <section class="i18n-hero">
      <div class="wrap">
        {trilha}
        <p class="eyebrow">{esc(c['sobrancelha'])}</p>
        <h1>{esc(c['h1'])}</h1>
        <p>{esc(c['linha'])}</p>
        <div class="cta-row" style="margin-top:24px">
          <a class="btn btn-red" href="{doar}">{esc(c['final_doar'])}</a>
          <a class="btn btn-outline" href="{entregar}">{esc(c['final_entregar'])}</a>
        </div>
        <div class="i18n-fotos">{fotos}</div>
      </div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap"><ul class="i18n-faixa">{faixa}</ul></div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap i18n-lado">
        <div>
          <h2>{esc(c['caso_titulo'])}</h2>
          {caso}
          <div class="i18n-caixa"><p>{c['caso_destaque']}</p></div>
        </div>
        {foto('agasalho-cartaz', '768x1376', c['cartaz_alt'])}
      </div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap">
        <h2>{esc(c['itens_titulo'])}</h2>
        <p>{esc(c['itens_linha'])}</p>
        <div class="i18n-fichas">{itens}</div>
      </div>
    </section>
    <section class="i18n-bloco" id="entregar">
      <div class="wrap">
        <h2>{esc(c['como_titulo'])}</h2>
        <p>{esc(c['como_linha'])}</p>
        <div class="i18n-fichas">{como}</div>
      </div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap i18n-lado">
        <div>
          <h2>{esc(c['agradecimento_titulo'])}</h2>
          {agradecimento}
          <div class="i18n-caixa"><p>{c['agradecimento_nota']}</p></div>
        </div>
        {foto('agasalho-fila-comunidade', '1264x848', c['fila_alt'])}
      </div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap">
        <h2>{esc(c['principios_titulo'])}</h2>
        <p>{esc(c['principios_linha'])}</p>
        <ul class="i18n-principios">{principios}</ul>
      </div>
    </section>
    <section class="i18n-bloco">
      <div class="wrap">
        <h2>{esc(c['final_titulo'])}</h2>
        <p>{esc(c['final_texto'])}</p>
        <div class="cta-row" style="margin-top:20px">
          <a class="btn btn-red" href="{doar}">{esc(c['final_doar'])}</a>
          <a class="btn btn-outline" href="{entregar}">{esc(c['final_entregar'])}</a>
        </div>
        <p class="i18n-nota"><a href="/campanha-agasalho.html" hreflang="pt-BR" lang="pt-BR">{esc(c['portugues'])}</a></p>
      </div>
    </section>"""
    url = f"{ORIGEM}{P['caminhos']['campanha']}"
    ld = [{"@context": "https://schema.org", "@type": "WebPage", "@id": f"{url}#pagina", "url": url,
           "name": c["titulo"], "description": c["descricao"], "inLanguage": "en",
           "isPartOf": {"@type": "WebSite", "@id": f"{ORIGEM}/#site", "url": f"{ORIGEM}/"},
           "about": organizacao(idioma)}, ld_trilha]
    registrar("campanha", "/campanha-agasalho.html", P["caminhos"]["campanha"])
    og = (f"{ORIGEM}/assets/otim/og-campanha-agasalho.jpg", 1200, 630)  # a mesma da página em português
    return moldura(idioma, "campanha", c["titulo"], c["descricao"], corpo, ld, partes, og_imagem=og)


def pagina_erro(idioma: dict, partes: dict, P: dict) -> str:
    e = P["erro"]
    links = "".join(f'<a class="btn {"btn-red" if i == 0 else "btn-outline"}" href="{u}"'
                    f'{atributos_link(u) if u == "/" else ""}>{esc(n)}</a>' for i, (n, u) in enumerate(e["links"]))
    corpo = f"""    <section class="i18n-hero">
      <div class="wrap">
        <p class="eyebrow">{esc(e['sobrancelha'])}</p>
        <h1>{esc(e['h1'])}</h1>
        <p>{esc(e['texto'])}</p>
        <div class="i18n-erro-links">{links}</div>
      </div>
    </section>"""
    registrar("erro", "/404.html", P["caminhos"]["erro"])
    html_ = moldura(idioma, "erro", e["titulo"], e["texto"], corpo, [], partes)
    # Página de erro: sem canonical nem hreflang, e fora da busca.
    html_ = re.sub(r'  <link rel="(canonical|alternate)"[^>]*>\n', "", html_)
    html_ = re.sub(r'  <meta property="og:url"[^>]*>\n', "", html_)
    return html_.replace('<meta name="viewport"', '<meta name="robots" content="noindex">\n  <meta name="viewport"', 1)


# ----------------------------------------------------------------------------- conferência

def conferir_links(paginas: dict[Path, str]) -> None:
    """Todo link interno para /en/ precisa apontar para uma página que existe."""
    def endereco(p: Path) -> str:
        url = "/" + p.relative_to(SITE).as_posix()
        return url[:-len("index.html")] if url.endswith("/index.html") else url
    existentes = {endereco(p) for p in (SITE / "en").rglob("*.html")} | {endereco(p) for p in paginas}
    problemas = []
    for arquivo, html_ in paginas.items():
        for alvo in re.findall(r'href="(/en/[^"#?]*)', html_):
            if alvo not in existentes:
                problemas.append(f"{arquivo.relative_to(SITE)} → {alvo}")
    if problemas:
        raise SystemExit("links internos quebrados:\n  " + "\n  ".join(sorted(set(problemas))))


def main() -> int:
    dados = json.loads(gi.DADOS.read_text(encoding="utf-8"))
    idioma = dados["idiomas"]["en"]
    P = json.loads((FONTE / "paginas.json").read_text(encoding="utf-8"))
    partes = gi.base.partes_da_home(gi.HOME.read_text(encoding="utf-8"))
    partes["estilo_sem_tag"] = re.sub(r"^\s*<style>|</style>\s*$", "", partes["estilo"])
    noticias = ler_noticias()
    for n in noticias:
        n["modificado"] = P["traduzido_em"]
    paginas: dict[Path, str] = {}
    for n in noticias:
        paginas[SITE / "en" / "news" / n["slug"] / "index.html"] = pagina_noticia(idioma, partes, P, n, noticias)
    paginas[SITE / "en" / "news" / "index.html"] = pagina_indice(idioma, partes, P, noticias)
    paginas[SITE / "en" / "winter-clothing-drive" / "index.html"] = pagina_campanha(idioma, partes, P)
    paginas[SITE / "en" / "404.html"] = pagina_erro(idioma, partes, P)
    conferir_links(paginas)
    for destino, html_ in paginas.items():
        destino.parent.mkdir(parents=True, exist_ok=True)
        destino.write_text(html_, encoding="utf-8")
        print(f"gravado {destino.relative_to(RAIZ)} ({len(html_.encode('utf-8'))} bytes)")
    # Para o sitemap (gerar_sitemaps.py) e para a Redação declarar o hreflang das originais.
    mapa = {n["url_pt"]: n["url"] for n in noticias}
    (FONTE / "mapa-hreflang.json").write_text(json.dumps(mapa, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"{len(paginas)} páginas; {len(noticias)} notícias; mapa em {(FONTE / 'mapa-hreflang.json').relative_to(RAIZ)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
