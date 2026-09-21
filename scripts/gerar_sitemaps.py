#!/usr/bin/env python3
"""Gera os sitemaps do domínio cruzvermelhariodejaneiro.org para o Google Search Console.

Saídas (em site/, publicadas na raiz do public_html):

  sitemap-index.xml        índice: aponta para os quatro sitemaps abaixo (é o único endereço que
                           precisa ser enviado no Search Console)
  sitemap-paginas.xml      páginas fixas do domínio principal, com a data de modificação real
                           (cabeçalho Last-Modified do servidor) e as imagens de cada página
  sitemap-noticias.xml     notícias publicadas pela Redação (lidas do sitemap.xml dela), com a data
                           de modificação do artigo (article:modified_time) e suas imagens
  sitemap-subdominios.xml  landing pages nos subdomínios (doar., projetocores., puncaovenosav1.),
                           cobertos pela propriedade de domínio do Search Console
  + sitemap.xml            o da Redação (páginas fixas + notícias, sempre atual): só referenciado

Fora do índice, de propósito: sitemap-escola.xml (a escola fica em cursoscruzvermelha.org, outro
domínio; gerado por gerar_sitemap_escola.py e enviado à parte, depois que a escola estiver verificada
na mesma conta do Search Console).

Regras:
  - toda URL e toda imagem são conferidas ao vivo e só entram se responderem 200;
  - página com noindex ou com canonical apontando para outro endereço fica de fora (avisa);
  - lastmod só quando há fonte confiável (Last-Modified do servidor ou article:modified_time);
    sem fonte, o campo é omitido, nunca inventado;
  - logotipos, logos de parceiros e pixels de rastreio não entram como imagem.

Uso:  python3 scripts/gerar_sitemaps.py
Sem dependências além da biblioteca padrão. Precisa de rede (confere tudo ao vivo).
"""
from __future__ import annotations

import html
import re
import sys
import urllib.error
import urllib.request
from datetime import datetime, timezone
from email.utils import parsedate_to_datetime
from pathlib import Path
from urllib.parse import urljoin, urlsplit

RAIZ = Path(__file__).resolve().parent.parent
SITE = RAIZ / "site"
ORIGEM = "https://cruzvermelhariodejaneiro.org"
AGENTE = "cvb-rj-sitemap/2.0 (+https://cruzvermelhariodejaneiro.org/)"

# Páginas fixas do domínio principal: (caminho, arquivo local com as imagens ou None, changefreq, priority, nota)
PAGINAS = [
    ("/", "index.html", "weekly", "1.0", "Home"),
    ("/matricula-cursos-presenciais/", "matricula-cursos-presenciais/index.html", "weekly", "0.9", "Matrícula em cursos presenciais"),
    ("/bio/", "bio/index.html", "monthly", "0.6", "Links da bio do Instagram"),
    ("/doe/", "doe/index.html", "monthly", "0.9", "Doação: PIX ou cartão, dentro do domínio"),
    ("/campanha-agasalho.html", "campanha-agasalho.html", "monthly", "0.6", "Campanha do Agasalho"),
    ("/equipe.html", "equipe.html", "monthly", "0.5", "Equipe"),
    ("/noticias/", None, "daily", "0.8", "Notícias (Redação)"),
    ("/termos/", None, "yearly", "0.3", "Termos de uso (Redação)"),
    ("/privacidade/", None, "yearly", "0.3", "Política de privacidade (Redação)"),
    ("/en/", "en/index.html", "monthly", "0.7", "Institucional em inglês"),
    ("/en/donate/", "en/donate/index.html", "monthly", "0.6", "Doação em inglês"),
    ("/es/", "es/index.html", "monthly", "0.7", "Institucional em espanhol"),
    ("/es/donar/", "es/donar/index.html", "monthly", "0.6", "Doação em espanhol"),
    ("/en/faq/", "en/faq/index.html", "monthly", "0.6", "Perguntas frequentes em inglês"),
    ("/es/preguntas-frecuentes/", "es/preguntas-frecuentes/index.html", "monthly", "0.6", "Perguntas frequentes em espanhol"),
]

# Versões da mesma página em outros idiomas, declaradas no sitemap com xhtml:link. O Google
# aceita o hreflang na página, no sitemap ou nos dois; declarar nos dois é o recomendado.
ALTERNATIVAS = {
    "/": {"pt-BR": "/", "en": "/en/", "es": "/es/"},
    "/en/": {"pt-BR": "/", "en": "/en/", "es": "/es/"},
    "/es/": {"pt-BR": "/", "en": "/en/", "es": "/es/"},
    "/doe/": {"pt-BR": "/doe/", "en": "/en/donate/", "es": "/es/donar/"},
    "/en/donate/": {"pt-BR": "/doe/", "en": "/en/donate/", "es": "/es/donar/"},
    "/es/donar/": {"pt-BR": "/doe/", "en": "/en/donate/", "es": "/es/donar/"},
    # A FAQ só existe em inglês e espanhol: em português ela é uma seção da home, e âncora não
    # serve de hreflang. O par fica sem pt-BR, como nas próprias páginas.
    "/en/faq/": {"en": "/en/faq/", "es": "/es/preguntas-frecuentes/", "x-default": "/"},
    "/es/preguntas-frecuentes/": {"en": "/en/faq/", "es": "/es/preguntas-frecuentes/", "x-default": "/"},
}

# Landing pages nos subdomínios: (URL exatamente como o canonical, changefreq, priority, nota)
SUBDOMINIOS = [
    ("https://projetocores.cruzvermelhariodejaneiro.org/", "monthly", "0.6", "Projeto Impacto das Cores (Hostinger)"),
    ("https://puncaovenosav1.cruzvermelhariodejaneiro.org", "monthly", "0.7", "Curso de Punção Venosa (Vercel)"),
]

SITEMAP_REDACAO = f"{ORIGEM}/sitemap.xml"
NS_SITEMAP = "http://www.sitemaps.org/schemas/sitemap/0.9"
NS_IMAGEM = "http://www.google.com/schemas/sitemap-image/1.1"
NS_XHTML = "http://www.w3.org/1999/xhtml"
EXTENSOES_IMAGEM = (".jpg", ".jpeg", ".png", ".webp", ".gif", ".avif")
IGNORAR_IMAGEM = re.compile(r"logo|/parceiros/|facebook\.com/tr|^data:", re.I)


# ----------------------------------------------------------------------------- rede
def requisitar(url: str, metodo: str = "GET") -> tuple[int, dict[str, str], str]:
    """(status, cabeçalhos em minúsculas, corpo). Erros HTTP viram status; erro de rede vira 0."""
    req = urllib.request.Request(url, method=metodo, headers={"User-Agent": AGENTE, "Accept": "*/*"})
    try:
        with urllib.request.urlopen(req, timeout=40) as resp:
            corpo = "" if metodo == "HEAD" else resp.read().decode("utf-8", errors="replace")
            return resp.status, {k.lower(): v for k, v in resp.headers.items()}, corpo
    except urllib.error.HTTPError as erro:
        return erro.code, {k.lower(): v for k, v in erro.headers.items()}, ""
    except Exception as erro:  # noqa: BLE001
        print(f"  ! sem resposta de {url}: {erro}", file=sys.stderr)
        return 0, {}, ""


def existe(url: str) -> tuple[bool, str | None]:
    """Confere ao vivo (HEAD, com GET de reserva). Devolve (200?, Last-Modified em W3C ou None)."""
    status, cabecalhos, _ = requisitar(url, "HEAD")
    if status in (0, 403, 405, 501):
        status, cabecalhos, _ = requisitar(url, "GET")
    return status == 200, w3c_de_http(cabecalhos.get("last-modified"))


def w3c_de_http(valor: str | None) -> str | None:
    if not valor:
        return None
    try:
        return parsedate_to_datetime(valor).astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S+00:00")
    except (TypeError, ValueError):
        return None


def w3c_de_iso(valor: str | None) -> str | None:
    if not valor:
        return None
    try:
        return datetime.fromisoformat(valor.replace("Z", "+00:00")).astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S+00:00")
    except ValueError:
        return valor[:10] if re.match(r"\d{4}-\d{2}-\d{2}", valor) else None


# ----------------------------------------------------------------------------- páginas e imagens
def meta(pagina: str, nome: str) -> str | None:
    """Conteúdo de <meta name|property="nome" content="..."> (qualquer ordem dos atributos)."""
    for padrao in (rf'<meta[^>]+(?:name|property)="{re.escape(nome)}"[^>]+content="([^"]*)"',
                   rf'<meta[^>]+content="([^"]*)"[^>]+(?:name|property)="{re.escape(nome)}"'):
        achado = re.search(padrao, pagina, re.I)
        if achado:
            return html.unescape(achado.group(1))
    return None


def canonical(pagina: str) -> str | None:
    achado = re.search(r'<link[^>]+rel="canonical"[^>]+href="([^"]*)"', pagina, re.I) or \
        re.search(r'<link[^>]+href="([^"]*)"[^>]+rel="canonical"', pagina, re.I)
    return html.unescape(achado.group(1)) if achado else None


def imagens_de(pagina: str, base: str) -> list[tuple[str, str]]:
    """[(URL absoluta, título)] das imagens de conteúdo: <img src/alt>, fundos em CSS e og:image."""
    achadas: dict[str, str] = {}

    def guardar(src: str, titulo: str = "") -> None:
        src = html.unescape(src.strip())
        if not src or IGNORAR_IMAGEM.search(src):
            return
        url = urljoin(base, src)
        if urlsplit(url).scheme not in ("http", "https") or not url.lower().split("?")[0].endswith(EXTENSOES_IMAGEM):
            return
        if url not in achadas or (titulo and not achadas[url]):
            achadas[url] = titulo.strip()

    for tag in re.findall(r"<img\b[^>]*>", pagina, re.I):
        src = re.search(r'\ssrc="([^"]*)"', tag)
        alt = re.search(r'\salt="([^"]*)"', tag)
        if src:
            guardar(src.group(1), html.unescape(alt.group(1)) if alt else "")
    for src in re.findall(r"""url\(\s*['"]?([^'")]+)['"]?\s*\)""", pagina):
        guardar(src)
    og = meta(pagina, "og:image")
    if og:
        guardar(og)
    return list(achadas.items())


def entrada(loc: str, lastmod: str | None, changefreq: str, priority: str, imagens: list[tuple[str, str]],
            alternativas: dict[str, str] | None = None) -> str:
    linhas = ["  <url>", f"    <loc>{html.escape(loc, quote=True)}</loc>"]
    # As versões da mesma página em outros idiomas. O Google aceita o hreflang na página, no
    # sitemap ou nos dois; declarar nos dois é o recomendado, e é o que fazemos.
    for codigo, caminho in (alternativas or {}).items():
        linhas.append(f'    <xhtml:link rel="alternate" hreflang="{codigo}" href="{ORIGEM}{caminho}"/>')
    # O x-default sai do português, quando existe. Página que só tem en+es (a FAQ) declara o seu
    # na própria tabela, e aí o laço acima já o emitiu — não duplicar.
    if alternativas and alternativas.get("pt-BR") and "x-default" not in alternativas:
        linhas.append(f'    <xhtml:link rel="alternate" hreflang="x-default" href="{ORIGEM}{alternativas["pt-BR"]}"/>')
    if lastmod:
        linhas.append(f"    <lastmod>{lastmod}</lastmod>")
    linhas += [f"    <changefreq>{changefreq}</changefreq>", f"    <priority>{priority}</priority>"]
    for url, titulo in imagens:
        linhas.append("    <image:image>")
        linhas.append(f"      <image:loc>{html.escape(url, quote=True)}</image:loc>")
        if titulo:
            linhas.append(f"      <image:title>{html.escape(titulo, quote=False)}</image:title>")
        linhas.append("    </image:image>")
    linhas.append("  </url>")
    return "\n".join(linhas)


def urlset(comentario: str, entradas: list[str]) -> str:
    return "\n".join([
        '<?xml version="1.0" encoding="UTF-8"?>',
        f"<!-- {comentario}\n     Gerado por scripts/gerar_sitemaps.py em {agora_w3c()}. Não editar à mão. -->",
        f'<urlset xmlns="{NS_SITEMAP}" xmlns:image="{NS_IMAGEM}" xmlns:xhtml="{NS_XHTML}">',
        *entradas,
        "</urlset>",
        "",
    ])


def agora_w3c() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S+00:00")


def conferir_imagens(imagens: list[tuple[str, str]]) -> list[tuple[str, str]]:
    validas = []
    for url, titulo in imagens:
        ok, _ = existe(url)
        if ok:
            validas.append((url, titulo))
        else:
            print(f"  ! imagem fora do ar, ignorada: {url}", file=sys.stderr)
    return validas


def pagina_indexavel(url: str, esperado: str) -> tuple[bool, str, dict[str, str]]:
    """Baixa a página e diz se pode entrar no sitemap: 200, sem noindex, canonical (se houver) igual à URL."""
    status, cabecalhos, corpo = requisitar(url)
    if status != 200:
        print(f"  ! {url} respondeu {status}, fora do sitemap", file=sys.stderr)
        return False, corpo, cabecalhos
    robots = (meta(corpo, "robots") or "") + " " + cabecalhos.get("x-robots-tag", "")
    if "noindex" in robots.lower():
        print(f"  ! {url} tem noindex, fora do sitemap", file=sys.stderr)
        return False, corpo, cabecalhos
    canon = canonical(corpo)
    if canon and canon.rstrip("/") != esperado.rstrip("/"):
        print(f"  ! {url} tem canonical {canon}, fora do sitemap", file=sys.stderr)
        return False, corpo, cabecalhos
    return True, corpo, cabecalhos


# ----------------------------------------------------------------------------- os quatro arquivos
def gerar_paginas() -> tuple[str, int]:
    entradas = []
    for caminho, arquivo, changefreq, priority, nota in PAGINAS:
        url = ORIGEM + caminho
        print(f"- {nota}: {url}")
        ok, corpo, cabecalhos = pagina_indexavel(url, url)
        if not ok:
            continue
        lastmod = w3c_de_http(cabecalhos.get("last-modified"))
        fonte = (SITE / arquivo).read_text(encoding="utf-8") if arquivo else ""
        imagens = conferir_imagens(imagens_de(fonte, url)) if fonte else []
        entradas.append(entrada(url, lastmod, changefreq, priority, imagens, ALTERNATIVAS.get(caminho)))
        print(f"  lastmod {lastmod or '(sem fonte)'} · {len(imagens)} imagens")
    return urlset("Páginas fixas do site institucional da Cruz Vermelha Brasileira, filial Rio de Janeiro.", entradas), len(entradas)


def gerar_noticias() -> tuple[str, int]:
    status, _, xml = requisitar(SITEMAP_REDACAO)
    if status != 200:
        raise SystemExit(f"não consegui ler {SITEMAP_REDACAO} (HTTP {status})")
    entradas = []
    for bloco in re.findall(r"<url>(.*?)</url>", xml, re.S):
        loc = html.unescape(re.search(r"<loc>(.*?)</loc>", bloco, re.S).group(1).strip())
        if "/noticias/" not in loc or loc.rstrip("/") == ORIGEM + "/noticias":
            continue
        data_sitemap = re.search(r"<lastmod>(.*?)</lastmod>", bloco)
        print(f"- notícia: {loc}")
        ok, corpo, _ = pagina_indexavel(loc, loc)
        if not ok:
            continue
        lastmod = w3c_de_iso(meta(corpo, "article:modified_time") or meta(corpo, "article:published_time")) \
            or (data_sitemap.group(1).strip() if data_sitemap else None)
        imagens = conferir_imagens(imagens_de(corpo, loc))
        entradas.append(entrada(loc, lastmod, "monthly", "0.6", imagens))
        print(f"  lastmod {lastmod or '(sem fonte)'} · {len(imagens)} imagens")
    return urlset("Notícias publicadas pela Redação (redacao.cruzvermelhariodejaneiro.org), com data de modificação e imagens "
                  "de cada artigo. Complementa o sitemap.xml da Redação, que continua sendo a lista sempre atual.", entradas), len(entradas)


def gerar_subdominios() -> tuple[str, int]:
    entradas = []
    for url, changefreq, priority, nota in SUBDOMINIOS:
        print(f"- {nota}: {url}")
        ok, corpo, cabecalhos = pagina_indexavel(url, url)
        if not ok:
            continue
        lastmod = w3c_de_http(cabecalhos.get("last-modified"))
        imagens = conferir_imagens(imagens_de(corpo, url))
        entradas.append(entrada(url, lastmod, changefreq, priority, imagens))
        print(f"  lastmod {lastmod or '(sem fonte)'} · {len(imagens)} imagens")
    return urlset("Landing pages nos subdomínios de cruzvermelhariodejaneiro.org (propriedade de domínio no Search Console).", entradas), len(entradas)


def gerar_indice(gerados: list[str]) -> str:
    linhas = ['<?xml version="1.0" encoding="UTF-8"?>',
              "<!-- Índice de sitemaps da Cruz Vermelha Brasileira, filial Rio de Janeiro. Envie este endereço no Google\n"
              f"     Search Console (propriedade de domínio cruzvermelhariodejaneiro.org). Gerado por scripts/gerar_sitemaps.py em {agora_w3c()}.\n"
              "     sitemap.xml é mantido pela Redação (regenerado a cada notícia); os outros três vêm deste repositório. -->",
              f'<sitemapindex xmlns="{NS_SITEMAP}">']
    for nome in gerados:
        linhas += ["  <sitemap>", f"    <loc>{ORIGEM}/{nome}</loc>", f"    <lastmod>{agora_w3c()}</lastmod>", "  </sitemap>"]
    _, redacao_lastmod = existe(SITEMAP_REDACAO)
    linhas += ["  <sitemap>", f"    <loc>{SITEMAP_REDACAO}</loc>"] + ([f"    <lastmod>{redacao_lastmod}</lastmod>"] if redacao_lastmod else []) + ["  </sitemap>"]
    linhas += ["</sitemapindex>", ""]
    return "\n".join(linhas)


def main() -> int:
    saidas = {}
    for nome, gerador in (("sitemap-paginas.xml", gerar_paginas), ("sitemap-noticias.xml", gerar_noticias), ("sitemap-subdominios.xml", gerar_subdominios)):
        print(f"\n== {nome}")
        xml, quantidade = gerador()
        if quantidade == 0:
            print(f"nenhuma URL válida para {nome}; nada foi gravado", file=sys.stderr)
            return 1
        saidas[nome] = (xml, quantidade)
    saidas["sitemap-index.xml"] = (gerar_indice(list(saidas)), len(saidas) + 1)
    print()
    for nome, (xml, quantidade) in saidas.items():
        (SITE / nome).write_text(xml, encoding="utf-8")
        print(f"gravado site/{nome} ({quantidade} entradas, {len(xml.encode('utf-8'))} bytes)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
