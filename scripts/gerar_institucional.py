#!/usr/bin/env python3
"""Gera as páginas institucionais da filial a partir de site/institucional.json (11/10/2026).

Páginas: /quem-somos/, /principios-fundamentais/, /base-legal/, /emblema/, /movimento-internacional/,
/direito-internacional-humanitario/, /cursos-gratuitos/ e /voluntariado/. O conteúdo (textos, fontes oficiais,
perguntas, termos e normas) fica no JSON; aqui só a moldura: cabeçalho, rodapé, CSS, GA4, Pixel (com o aviso de
cookies) e o script do menu vêm de site/index.html (partes_da_home), como nos outros geradores, e o chat de
contato entra no fim.

Regras do conteúdo (docs/institucional/mapa-leis-principios-2026-10.md):
- Textos oficiais em <blockquote class="inst-oficial"> com a fonte; leis e decretos podem ser reproduzidos
  (Lei 9.610/1998, art. 8º, IV); textos do CICV só em citação curta, com crédito e link.
- Cada página tem mais texto próprio da filial do que trechos citados e links (regra do Google contra páginas
  copiadas ou feitas só de links). O canonical é sempre a própria página, nunca a fonte.
- Cursos gratuitos (filial) e pagos (escola, O-CVB Filial Rio de Janeiro Ensino Ltda) ficam em ambientes
  separados; a página dos gratuitos só aponta para os pagos num bloco final, dizendo quem vende.

JSON-LD: WebPage (AboutPage em /quem-somos/) ligado a #site e #organizacao, BreadcrumbList, FAQPage (só
semântica: o Google tirou o resultado rico de FAQ da busca em 2026), DefinedTermSet com os 7 princípios e
Legislation para cada norma da base legal (vocabulário schema.org, sem resultado rico prometido).

Uso:  python3 scripts/gerar_institucional.py
Depois: publicar as pastas geradas (lista em scripts/publicacao-institucional.txt) e o sitemap.
"""
from __future__ import annotations

import json
import re
import sys
from datetime import date
from html import escape

import chat_widget
import icones
from gerar_matricula_presencial import HOME, RAIZ, partes_da_home

ORIGEM = "https://cruzvermelhariodejaneiro.org"
CONTEUDO = RAIZ / "site" / "institucional.json"
ATUALIZADO = date(2026, 10, 11)
IMAGEM_OG = f"{ORIGEM}/assets/otim/og-home.jpg"
ORDEM = ["quem-somos", "principios-fundamentais", "movimento-internacional", "emblema",
         "direito-internacional-humanitario", "base-legal", "cursos-gratuitos", "voluntariado"]
# Páginas de "Quem somos" (trilha Início › Quem somos › Página) e de "Atuação" (Início › Página).
QUEM_SOMOS = {"principios-fundamentais", "movimento-internacional", "emblema", "direito-internacional-humanitario", "base-legal"}
NOMES_CURTOS = {"quem-somos": "Quem somos", "principios-fundamentais": "Princípios Fundamentais",
                "movimento-internacional": "Movimento Internacional", "emblema": "O emblema",
                "direito-internacional-humanitario": "Direito Internacional Humanitário", "base-legal": "Base legal",
                "cursos-gratuitos": "Cursos gratuitos", "voluntariado": "Voluntariado"}
# Páginas que já existem no site e podem aparecer em "relacionadas".
EXISTENTES = {"historia": ("/historia/", "Nossa história", "Da fundação da Cruz Vermelha Brasileira, em 1908, ao Palácio da Cruz Vermelha."),
              "equipe": ("/equipe.html", "Equipe", "Quem dirige a filial e coordena cada frente de trabalho."),
              "transparencia": ("/transparencia/", "Transparência", "O portal da transparência da filial.")}
FORCA_LEGAL = {"vigente": "https://schema.org/InForce", "revogado": "https://schema.org/NotInForce",
               "revogada": "https://schema.org/NotInForce", "revogado em parte": "https://schema.org/PartiallyInForce"}
DOMINIO_PROIBIDO = "cruzvermelharj.org.br"

CSS = """  <style>
    /* Páginas institucionais (scripts/gerar_institucional.py). */
    main.inst-pagina .inst-topo { background: var(--soft); border-bottom: 1px solid var(--line); padding: 40px 0 34px; }
    main.inst-pagina .inst-relacionadas { padding: 40px 0 48px; }
    .inst-trilha { display: block !important; font-size: .82rem; color: var(--muted); margin: 0 0 18px; }  /* a home esconde todo <nav> no celular */
    main.inst-pagina section.inst-secao, main.inst-pagina section.inst-perguntas { padding: 0; }  /* a home dá 86 px a todo <section> */
    .inst-trilha ol { list-style: none; display: flex; flex-wrap: wrap; gap: 6px; margin: 0; padding: 0; }
    .inst-trilha li + li::before { content: "›"; margin-right: 6px; color: var(--line); }
    .inst-trilha a { color: var(--muted); }
    .inst-trilha a:hover { color: var(--red); }
    .inst-topo h1 { color: var(--black); font-size: clamp(2rem, 4.4vw, 3.1rem); line-height: 1.08; letter-spacing: -.03em; margin: 6px 0 14px; max-width: 22ch; }
    .inst-topo .lead { max-width: 66ch; }
    .inst-indice { display: flex; flex-wrap: wrap; gap: 8px; margin: 22px 0 0; padding: 0; list-style: none; }
    .inst-indice a { display: inline-block; padding: 7px 12px; border-radius: 999px; background: var(--paper); border: 1px solid var(--line); font-size: .84rem; font-weight: 600; color: var(--text); }
    .inst-indice a:hover { border-color: var(--red); color: var(--red); }
    .inst-corpo { padding: 44px 0 20px; }
    .inst-corpo .wrap { max-width: 860px; }
    .inst-secao { scroll-margin-top: 110px; margin: 0 0 38px; }
    .inst-secao h2 { font-size: clamp(1.45rem, 2.6vw, 1.85rem); line-height: 1.2; letter-spacing: -.02em; margin: 0 0 14px; }
    .inst-secao h3 { font-size: 1.1rem; margin: 22px 0 8px; }
    .inst-secao p, .inst-secao li { font-size: 1.04rem; line-height: 1.72; color: var(--text); }
    .inst-secao p { margin: 0 0 14px; }
    .inst-secao ul, .inst-secao ol { margin: 0 0 16px; padding-left: 22px; }
    .inst-secao li { margin: 0 0 6px; }
    .inst-secao a { color: var(--red); text-decoration: underline; text-underline-offset: 3px; text-decoration-thickness: 1px; }
    .inst-oficial { margin: 18px 0 18px; padding: 18px 22px; border-left: 4px solid var(--red); background: var(--soft); border-radius: 0 12px 12px 0; }
    .inst-oficial p { font-size: 1rem; font-style: italic; margin: 0 0 8px; }
    .inst-oficial cite { display: block; font-size: .84rem; font-style: normal; color: var(--muted); }
    .inst-oficial cite a { color: var(--muted); }
    .inst-tabela-rolagem { overflow-x: auto; margin: 0 0 18px; border: 1px solid var(--line); border-radius: 12px; }
    .inst-tabela { width: 100%; border-collapse: collapse; font-size: .95rem; min-width: 620px; }
    .inst-tabela th, .inst-tabela td { text-align: left; vertical-align: top; padding: 11px 14px; border-bottom: 1px solid var(--line); line-height: 1.5; }
    .inst-tabela thead th { background: var(--soft); font-size: .78rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
    .inst-tabela tbody tr:last-child td { border-bottom: 0; }
    .inst-perguntas details { border: 1px solid var(--line); border-radius: 12px; padding: 0 18px; margin: 0 0 10px; background: var(--paper); }
    .inst-perguntas summary { cursor: pointer; font-weight: 700; padding: 15px 0; list-style-position: outside; }
    .inst-perguntas details p { margin: 0 0 16px; color: var(--text); line-height: 1.7; }
    .inst-fontes { margin: 8px 0 40px; padding: 22px 24px; border: 1px solid var(--line); border-radius: 14px; background: var(--soft); }
    .inst-fontes h2 { font-size: 1.05rem; margin: 0 0 12px; letter-spacing: 0; }
    .inst-fontes ul { margin: 0; padding-left: 20px; }
    .inst-fontes li { font-size: .92rem; line-height: 1.6; margin: 0 0 6px; }
    .inst-fontes a { color: var(--text); text-decoration: underline; text-underline-offset: 3px; text-decoration-color: var(--line); }
    .inst-fontes a:hover { color: var(--red); }
    .inst-atualizado { font-size: .82rem; color: var(--muted); margin: 0 0 40px; }
    .inst-relacionadas { background: var(--soft); border-top: 1px solid var(--line); }
    .inst-relacionadas h2 { font-size: 1.35rem; margin: 0 0 18px; }
    .inst-cartoes { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
    .inst-cartao { display: block; padding: 18px 20px; border: 1px solid var(--line); border-radius: 14px; background: var(--paper); color: var(--text); transition: border-color .2s, transform .18s; }
    .inst-cartao:hover { border-color: var(--red); transform: translateY(-2px); }
    .inst-cartao b { display: block; font-size: 1rem; margin: 0 0 6px; color: var(--black); }
    .inst-cartao span { font-size: .9rem; color: var(--muted); line-height: 1.5; }
    @media (max-width: 620px) {
      main.inst-pagina .inst-topo { padding: 28px 0 26px; }
      .inst-corpo { padding: 32px 0 12px; }
      .inst-oficial { padding: 14px 16px; }
    }
  </style>"""


def esc(t: str) -> str:
    return escape(str(t), quote=True)


def carregar() -> dict:
    dados = json.loads(CONTEUDO.read_text(encoding="utf-8"))
    paginas = {p["slug"]: p for p in dados["paginas"]}
    faltam = [s for s in ORDEM if s not in paginas]
    if faltam:
        raise SystemExit(f"institucional.json sem as páginas: {', '.join(faltam)}")
    texto = json.dumps(dados, ensure_ascii=False)
    if DOMINIO_PROIBIDO in texto:
        raise SystemExit(f"institucional.json cita {DOMINIO_PROIBIDO}, que o site não pode usar nem linkar")
    return paginas


def tratar_html(html: str) -> str:
    """Links externos abrem em outra aba; tabelas ganham rolagem horizontal no celular."""
    def link(m: re.Match) -> str:
        attrs = m.group(1)
        href = re.search(r'href="([^"]+)"', attrs)
        if href and href.group(1).startswith("http") and not href.group(1).startswith(ORIGEM) and "target=" not in attrs:
            attrs += ' target="_blank" rel="noopener"'
        return f"<a{attrs}>"
    html = re.sub(r"<a(\s[^>]*)>", link, html)
    html = re.sub(r'<table class="inst-tabela">', '<div class="inst-tabela-rolagem"><table class="inst-tabela">', html)
    html = html.replace("</table>", "</table></div>")
    return html


def url_de(slug: str) -> str:
    return f"{ORIGEM}/{slug}/"


def trilha(p: dict) -> list[tuple[str, str]]:
    itens = [("Início", f"{ORIGEM}/")]
    if p["slug"] in QUEM_SOMOS:
        itens.append(("Quem somos", url_de("quem-somos")))
    itens.append((NOMES_CURTOS[p["slug"]], url_de(p["slug"])))
    return itens


def cabecalho(partes: dict, slug: str) -> str:
    header = partes["header"].replace(' aria-current="page"', "")
    alvo = f'<a href="/{slug}/">'
    if alvo in header:
        header = header.replace(alvo, f'<a href="/{slug}/" aria-current="page">', 1)
    return header


def json_ld(p: dict, paginas: dict) -> str:
    url = url_de(p["slug"])
    tipo = "AboutPage" if p["slug"] == "quem-somos" else "WebPage"
    pagina = {
        "@type": tipo, "@id": f"{url}#pagina", "url": url, "name": p["h1"], "description": p["descricao_seo"],
        "inLanguage": "pt-BR", "isPartOf": {"@id": f"{ORIGEM}/#site"},
        "publisher": {"@id": f"{ORIGEM}/#organizacao"}, "breadcrumb": {"@id": f"{url}#trilha"},
        "dateModified": ATUALIZADO.isoformat(),
        "citation": [f["url"] for f in p["fontes"]],
    }
    if p["slug"] == "quem-somos":
        pagina["about"] = {"@id": f"{ORIGEM}/#organizacao"}
        pagina["mainEntity"] = {"@id": f"{ORIGEM}/#organizacao"}
    grafo = [pagina, {
        "@type": "BreadcrumbList", "@id": f"{url}#trilha",
        "itemListElement": [{"@type": "ListItem", "position": i + 1, "name": n, "item": u} for i, (n, u) in enumerate(trilha(p))],
    }]
    if p.get("termos"):
        conjunto = f"{url}#principios"
        pagina["mainEntity"] = {"@id": conjunto}
        pagina["isBasedOn"] = "https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2016/decreto/d8885.htm"
        pagina["about"] = {"@type": "Thing", "name": "Princípios Fundamentais do Movimento Internacional da Cruz Vermelha e do Crescente Vermelho",
                           "sameAs": "https://www.wikidata.org/wiki/Q19284407"}
        grafo.append({"@type": "DefinedTermSet", "@id": conjunto, "name": "Princípios Fundamentais do Movimento Internacional da Cruz Vermelha e do Crescente Vermelho",
                      "inLanguage": "pt-BR",
                      "hasDefinedTerm": [{"@type": "DefinedTerm", "@id": f"{url}#{t['id']}", "name": t["nome"], "description": t["descricao"],
                                          "url": f"{url}#{t['id']}", "inDefinedTermSet": {"@id": conjunto}} for t in p["termos"]]})
    if p.get("normas"):
        lista = f"{url}#normas"
        pagina["mainEntity"] = {"@id": lista}
        itens = []
        for i, n in enumerate(p["normas"]):
            norma = {"@type": "Legislation", "name": n["nome"], "legislationIdentifier": n["identificador"],
                     "legislationDate": n["data"], "legislationType": n["tipo"], "url": n["url"],
                     "legislationJurisdiction": n["jurisdicao"]}
            forca = FORCA_LEGAL.get(n["situacao"].strip().lower())
            if forca:
                norma["legislationLegalForce"] = forca
            itens.append({"@type": "ListItem", "position": i + 1, "item": norma})
        grafo.append({"@type": "ItemList", "@id": lista, "name": "Leis e decretos da Cruz Vermelha Brasileira e da filial", "itemListElement": itens})
    if p.get("perguntas"):
        grafo.append({"@type": "FAQPage", "@id": f"{url}#perguntas", "isPartOf": {"@id": f"{url}#pagina"},
                      "mainEntity": [{"@type": "Question", "name": q["pergunta"],
                                      "acceptedAnswer": {"@type": "Answer", "text": q["resposta"]}} for q in p["perguntas"]]})
    ld = {"@context": "https://schema.org", "@graph": grafo}
    texto = json.dumps(ld, ensure_ascii=False, indent=1).replace("</", "<\\/")
    return f'  <script type="application/ld+json">\n{texto}\n  </script>'


def relacionadas(p: dict, paginas: dict) -> str:
    cartoes = []
    for slug in p.get("relacionadas", []):
        if slug in paginas and slug != p["slug"]:
            q = paginas[slug]
            cartoes.append((f"/{slug}/", NOMES_CURTOS.get(slug, q["h1"]), q["lead"]))
        elif slug in EXISTENTES:
            cartoes.append(EXISTENTES[slug])
    if not cartoes:
        return ""
    itens = "\n          ".join(f'<a class="inst-cartao" href="{esc(u)}"><b>{esc(t)}</b><span>{esc(d)}</span></a>' for u, t, d in cartoes[:4])
    return f"""    <section class="inst-relacionadas" aria-labelledby="inst-relacionadas-titulo">
      <div class="wrap">
        <h2 id="inst-relacionadas-titulo">Veja também</h2>
        <div class="inst-cartoes">
          {itens}
        </div>
      </div>
    </section>"""


def gerar(p: dict, paginas: dict, partes: dict) -> str:
    slug, url = p["slug"], url_de(p["slug"])
    ga4 = partes["ga4"].replace("gtag('config', 'G-", "gtag('set', { content_group: 'institucional' });\n    gtag('config', 'G-", 1)
    assert "content_group: 'institucional'" in ga4, "não achei o gtag('config') da home para inserir o content_group"
    trilha_html = "".join(
        f'<li><a href="{esc(u.replace(ORIGEM, "") or "/")}">{esc(n)}</a></li>' if i < len(trilha(p)) - 1
        else f'<li aria-current="page">{esc(n)}</li>' for i, (n, u) in enumerate(trilha(p)))
    indice = "\n          ".join(f'<li><a href="#{esc(s["id"])}">{esc(s["h2"])}</a></li>' for s in p["secoes"])
    secoes = "\n\n".join(
        f'      <section class="inst-secao" id="{esc(s["id"])}" aria-labelledby="{esc(s["id"])}-titulo">\n'
        f'        <h2 id="{esc(s["id"])}-titulo">{esc(s["h2"])}</h2>\n'
        f'        {tratar_html(s["html"])}\n      </section>' for s in p["secoes"])
    perguntas = ""
    if p.get("perguntas"):
        itens = "\n        ".join(f"<details><summary>{esc(q['pergunta'])}</summary><p>{esc(q['resposta'])}</p></details>" for q in p["perguntas"])
        perguntas = f"""
      <section class="inst-secao inst-perguntas" id="perguntas" aria-labelledby="perguntas-titulo">
        <h2 id="perguntas-titulo">Perguntas frequentes</h2>
        {itens}
      </section>"""
    fontes = "\n          ".join(f'<li><a href="{esc(f["url"])}" target="_blank" rel="noopener">{esc(f["rotulo"])}</a></li>' for f in p["fontes"])
    titulo = p["titulo_seo"]
    return f"""<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <title>{esc(titulo)}</title>
  <meta name="description" content="{esc(p['descricao_seo'])}">
  <link rel="canonical" href="{url}">
  <meta name="robots" content="index, follow, max-image-preview:large">
  <meta property="og:type" content="website">
  <meta property="og:locale" content="pt_BR">
  <meta property="og:site_name" content="Cruz Vermelha Brasileira Rio de Janeiro">
  <meta property="og:title" content="{esc(titulo)}">
  <meta property="og:description" content="{esc(p['descricao_seo'])}">
  <meta property="og:url" content="{url}">
  <meta property="og:image" content="{IMAGEM_OG}">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="{esc(titulo)}">
  <meta name="twitter:description" content="{esc(p['descricao_seo'])}">
  <meta name="twitter:image" content="{IMAGEM_OG}">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"></noscript>
  <!-- Estilos copiados da home (site/index.html): mesmo padrão visual da filial. -->
{partes["estilo"]}
{CSS}
{ga4}
{partes["pixel"]}
{json_ld(p, paginas)}
</head>
<body>
{cabecalho(partes, slug)}

  <main id="conteudo" class="inst-pagina" data-pagina="{esc(slug)}">
    <section class="inst-topo" aria-labelledby="inst-titulo">
      <div class="wrap">
        <nav class="inst-trilha" aria-label="Você está em"><ol>{trilha_html}</ol></nav>
        <p class="eyebrow">{esc(p['eyebrow'])}</p>
        <h1 id="inst-titulo">{esc(p['h1'])}</h1>
        <p class="lead">{esc(p['lead'])}</p>
        <ul class="inst-indice" aria-label="Nesta página">
          {indice}
        </ul>
      </div>
    </section>

    <div class="inst-corpo">
      <div class="wrap">
{secoes}
{perguntas}

      <aside class="inst-fontes" aria-labelledby="inst-fontes-titulo">
        <h2 id="inst-fontes-titulo">Fontes oficiais</h2>
        <ul>
          {fontes}
        </ul>
      </aside>
      <p class="inst-atualizado">Atualizado em {ATUALIZADO:%d/%m/%Y}.</p>
      </div>
    </div>

{relacionadas(p, paginas)}
  </main>

{partes["footer"]}

{partes["menu_js"]}
{chat_widget.tags()}
</body>
</html>
"""


def main() -> int:
    paginas = carregar()
    partes = partes_da_home(HOME.read_text(encoding="utf-8"))
    so = set(sys.argv[1:]) or set(ORDEM)
    for slug in ORDEM:
        if slug not in so:
            continue
        html = icones.converter(gerar(paginas[slug], paginas, partes))
        pasta = RAIZ / "site" / slug
        pasta.mkdir(parents=True, exist_ok=True)
        (pasta / "index.html").write_text(html, encoding="utf-8")
        print(f"gravado site/{slug}/index.html ({len(html.encode('utf-8'))} bytes)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
