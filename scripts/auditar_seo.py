#!/usr/bin/env python3
"""Auditoria de SEO on-page das páginas do ecossistema, lendo cada URL ao vivo.

Para cada página: status, título e descrição (com tamanho), canonical, robots, lang, viewport,
Open Graph e Twitter, H1/H2, imagens (sem alt, sem lazy, sem dimensões), links internos/externos,
JSON-LD (tipos e erros de parse), hreflang, favicon, tamanho e compressão da resposta.

Uso:  python3 scripts/auditar_seo.py [--json saida.json] [URL ...]
Sem URLs, audita a lista padrão (site principal, notícias de amostra, subdomínios e escola).
"""
from __future__ import annotations

import argparse
import gzip
import json
import re
import sys
import urllib.error
import urllib.request
import zlib
from html import unescape
from html.parser import HTMLParser
from urllib.parse import urljoin, urlsplit

PADRAO = [
    "https://cruzvermelhariodejaneiro.org/",
    "https://cruzvermelhariodejaneiro.org/matricula-cursos-presenciais/",
    "https://cruzvermelhariodejaneiro.org/doe/",
    "https://cruzvermelhariodejaneiro.org/campanha-agasalho.html",
    "https://cruzvermelhariodejaneiro.org/equipe.html",
    "https://cruzvermelhariodejaneiro.org/noticias/",
    "https://cruzvermelhariodejaneiro.org/noticias/setembro-amarelo-cruz-vermelha-rj-e-a-valorizacao-da-vida/",
    "https://cruzvermelhariodejaneiro.org/termos/",
    "https://cruzvermelhariodejaneiro.org/privacidade/",
    "https://cruzvermelhariodejaneiro.org/matricula-cursos-presenciais/checkout/",
    "https://projetocores.cruzvermelhariodejaneiro.org/",
    "https://puncaovenosav1.cruzvermelhariodejaneiro.org/",
    "https://escola.cursoscruzvermelha.org/",
    "https://escola.cursoscruzvermelha.org/cursos",
]
AGENTE = "Mozilla/5.0 (compatible; cvb-rj-seo-audit/1.0; +https://cruzvermelhariodejaneiro.org/)"


class Coletor(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.dados: dict = {"title": "", "metas": [], "links": [], "h1": [], "h2": [], "imgs": [], "anchors": [], "jsonld": [], "html_attrs": {}, "scripts_externos": 0, "css_externos": 0}
        self._em_title = False
        self._em_h: str | None = None
        self._em_jsonld = False
        self._buf = ""

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "html":
            self.dados["html_attrs"] = a
        elif tag == "title" and not self.dados["title"]:  # só o primeiro: <title> de SVG inline não conta
            self._em_title = True
            self._buf = ""
        elif tag == "meta":
            self.dados["metas"].append(a)
        elif tag == "link":
            self.dados["links"].append(a)
            if (a.get("rel") or "").lower() == "stylesheet":
                self.dados["css_externos"] += 1
        elif tag in ("h1", "h2"):
            self._em_h = tag
            self._buf = ""
        elif tag == "img":
            self.dados["imgs"].append(a)
        elif tag == "a":
            self.dados["anchors"].append(a)
        elif tag == "script":
            if (a.get("type") or "").lower() == "application/ld+json":
                self._em_jsonld = True
                self._buf = ""
            elif a.get("src"):
                self.dados["scripts_externos"] += 1

    def handle_endtag(self, tag):
        if tag == "title" and self._em_title:
            self.dados["title"] = " ".join(self._buf.split())
            self._em_title = False
        elif tag in ("h1", "h2") and self._em_h == tag:
            self.dados[tag].append(" ".join(self._buf.split()))
            self._em_h = None
        elif tag == "script" and self._em_jsonld:
            self.dados["jsonld"].append(self._buf)
            self._em_jsonld = False

    def handle_data(self, data):
        if self._em_title or self._em_h or self._em_jsonld:
            self._buf += data


def baixar(url: str) -> tuple[int, dict, bytes, int]:
    req = urllib.request.Request(url, headers={"User-Agent": AGENTE, "Accept": "text/html,*/*", "Accept-Encoding": "gzip, deflate, br"})
    try:
        with urllib.request.urlopen(req, timeout=40) as r:
            bruto = r.read()
            cab = {k.lower(): v for k, v in r.headers.items()}
            status = r.status
    except urllib.error.HTTPError as e:
        bruto = e.read()
        cab = {k.lower(): v for k, v in e.headers.items()}
        status = e.code
    except Exception as e:  # noqa: BLE001
        return 0, {"erro": str(e)}, b"", 0
    enc = cab.get("content-encoding", "")
    corpo = bruto
    try:
        if "gzip" in enc:
            corpo = gzip.decompress(bruto)
        elif "deflate" in enc:
            corpo = zlib.decompress(bruto)
        elif "br" in enc:
            try:
                import brotli  # type: ignore
                corpo = brotli.decompress(bruto)
            except ImportError:
                # sem brotli: pede de novo sem compressão
                req2 = urllib.request.Request(url, headers={"User-Agent": AGENTE, "Accept": "text/html,*/*"})
                with urllib.request.urlopen(req2, timeout=40) as r2:
                    corpo = r2.read()
    except Exception:  # noqa: BLE001
        pass
    return status, cab, corpo, len(bruto)


def meta(metas: list[dict], chave: str, valor: str) -> str | None:
    for m in metas:
        if (m.get(chave) or "").lower() == valor.lower():
            return unescape(m.get("content") or "")
    return None


def auditar(url: str) -> dict:
    status, cab, corpo, transferido = baixar(url)
    r: dict = {"url": url, "status": status, "transferido_kb": round(transferido / 1024), "encoding": cab.get("content-encoding", "(sem)"),
               "cache_control": cab.get("cache-control", "(sem)"), "problemas": []}
    if status != 200 or not corpo:
        r["problemas"].append(f"HTTP {status}")
        return r
    html = corpo.decode("utf-8", errors="replace")
    c = Coletor()
    try:
        c.feed(html)
    except Exception as e:  # noqa: BLE001
        r["problemas"].append(f"HTML não parseável: {e}")
    d = c.dados
    host = urlsplit(url).netloc
    r["html_kb"] = round(len(corpo) / 1024)
    r["lang"] = d["html_attrs"].get("lang")
    r["title"] = d["title"]
    r["title_len"] = len(d["title"])
    desc = meta(d["metas"], "name", "description")
    r["description"] = desc
    r["description_len"] = len(desc) if desc else 0
    r["robots"] = meta(d["metas"], "name", "robots")
    r["viewport"] = bool(meta(d["metas"], "name", "viewport"))
    canon = [l.get("href") for l in d["links"] if (l.get("rel") or "").lower() == "canonical"]
    r["canonical"] = canon[0] if canon else None
    r["canonical_ok"] = bool(canon) and canon[0].rstrip("/") == url.rstrip("/")
    r["hreflang"] = [l.get("hreflang") for l in d["links"] if (l.get("rel") or "").lower() == "alternate" and l.get("hreflang")]
    r["favicon"] = any("icon" in (l.get("rel") or "").lower() for l in d["links"])
    r["og"] = {k: meta(d["metas"], "property", f"og:{k}") for k in ("title", "description", "image", "url", "type", "locale", "site_name")}
    r["twitter_card"] = meta(d["metas"], "name", "twitter:card")
    r["h1"] = d["h1"]
    r["h2_qtd"] = len(d["h2"])
    imgs = d["imgs"]
    r["imgs"] = len(imgs)
    r["imgs_sem_alt"] = [i.get("src") for i in imgs if "alt" not in i]  # alt="" é decorativo e vale
    r["imgs_sem_dimensao"] = sum(1 for i in imgs if not (i.get("width") and i.get("height")))
    r["imgs_sem_lazy"] = sum(1 for i in imgs if (i.get("loading") or "").lower() != "lazy")
    internos = externos = nofollow = 0
    for a in d["anchors"]:
        href = a.get("href") or ""
        if not href or href.startswith(("#", "mailto:", "tel:", "javascript:")):
            continue
        alvo = urljoin(url, href)
        if urlsplit(alvo).netloc == host:
            internos += 1
        else:
            externos += 1
            if "nofollow" in (a.get("rel") or ""):
                nofollow += 1
    r["links_internos"], r["links_externos"], r["externos_nofollow"] = internos, externos, nofollow
    tipos = []
    for bloco in d["jsonld"]:
        try:
            dados = json.loads(bloco)
        except json.JSONDecodeError as e:
            r["problemas"].append(f"JSON-LD inválido: {e}")
            continue
        itens = dados if isinstance(dados, list) else [dados]
        for item in itens:
            if isinstance(item, dict):
                if "@graph" in item:
                    tipos += [str(g.get("@type")) for g in item["@graph"] if isinstance(g, dict)]
                else:
                    tipos.append(str(item.get("@type")))
    r["jsonld_tipos"] = tipos
    r["scripts_externos"], r["css_externos"] = d["scripts_externos"], d["css_externos"]

    # diagnóstico
    p = r["problemas"]
    if not r["title"]:
        p.append("sem <title>")
    elif r["title_len"] > 60:
        p.append(f"título longo ({r['title_len']} > 60)")
    elif r["title_len"] < 25:
        p.append(f"título curto ({r['title_len']})")
    if not desc:
        p.append("sem meta description")
    elif r["description_len"] > 160:
        p.append(f"description longa ({r['description_len']} > 160)")
    elif r["description_len"] < 70:
        p.append(f"description curta ({r['description_len']})")
    if not canon:
        p.append("sem canonical")
    elif not r["canonical_ok"]:
        p.append(f"canonical aponta para {canon[0]}")
    if not r["lang"]:
        p.append("sem lang no <html>")
    if not r["viewport"]:
        p.append("sem viewport")
    if len(d["h1"]) != 1:
        p.append(f"{len(d['h1'])} H1")
    for k in ("title", "description", "image"):
        if not r["og"][k]:
            p.append(f"sem og:{k}")
    if not r["twitter_card"]:
        p.append("sem twitter:card")
    if r["imgs_sem_alt"]:
        p.append(f"{len(r['imgs_sem_alt'])} imagens sem alt")
    if r["imgs_sem_dimensao"]:
        p.append(f"{r['imgs_sem_dimensao']} imagens sem width/height")
    if not tipos:
        p.append("sem JSON-LD")
    if r["encoding"] == "(sem)":
        p.append("resposta sem compressão")
    if not r["favicon"]:
        p.append("sem favicon declarado")
    if r["robots"] and "noindex" in r["robots"].lower():
        p.append("noindex")
    return r


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--json")
    ap.add_argument("urls", nargs="*")
    args = ap.parse_args()
    urls = args.urls or PADRAO
    resultados = []
    for u in urls:
        r = auditar(u)
        resultados.append(r)
        print(f"\n== {u}\n   HTTP {r['status']} · {r.get('html_kb', '?')} KB html · {r['transferido_kb']} KB transferidos · {r['encoding']}")
        if r["status"] == 200:
            print(f"   title ({r['title_len']}): {r['title']}")
            print(f"   description ({r['description_len']}): {(r['description'] or '')[:150]}")
            print(f"   canonical: {r['canonical']} · robots: {r['robots']} · lang: {r['lang']} · H1: {r['h1']} · H2: {r['h2_qtd']}")
            print(f"   og: title={bool(r['og']['title'])} desc={bool(r['og']['description'])} image={r['og']['image']} · twitter: {r['twitter_card']}")
            print(f"   imgs: {r['imgs']} (sem alt {len(r['imgs_sem_alt'])}, sem dimensão {r['imgs_sem_dimensao']}, sem lazy {r['imgs_sem_lazy']}) · links int/ext: {r['links_internos']}/{r['links_externos']} · JSON-LD: {r['jsonld_tipos']} · scripts ext: {r['scripts_externos']} · css ext: {r['css_externos']}")
        print("   PROBLEMAS: " + ("; ".join(r["problemas"]) if r["problemas"] else "nenhum"))
    if args.json:
        with open(args.json, "w", encoding="utf-8") as f:
            json.dump(resultados, f, ensure_ascii=False, indent=1)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
