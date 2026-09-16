#!/usr/bin/env python3
"""Gera o sitemap da Escola de Educação e Saúde CVB-RJ a partir do catálogo público.

Lê https://escola.cursoscruzvermelha.org/cursos, descobre os cursos publicados
(/cursos/<uuid>) e grava dois arquivos idênticos:

  escola/sitemap.xml        -> para servir dentro do app da escola (public/ ou rota)
  site/sitemap-escola.xml   -> cópia hospedada no domínio principal (sitemap cross-domain,
                               usada no Search Console depois de verificar a escola)

Uso:  python3 scripts/gerar_sitemap_escola.py
Sem dependências além da biblioteca padrão.
"""
from __future__ import annotations

import html
import os
import re
import sys
import urllib.request
from pathlib import Path

ORIGEM = os.environ.get("ESCOLA_ORIGEM", "https://escola.cursoscruzvermelha.org").rstrip("/")
PAGINAS_FIXAS = ["/", "/cursos", "/sobre"]
RAIZ = Path(__file__).resolve().parent.parent
SAIDAS = [RAIZ / "escola" / "sitemap.xml", RAIZ / "site" / "sitemap-escola.xml"]


def baixar(url: str) -> str:
    req = urllib.request.Request(url, headers={"User-Agent": "cvb-rj-sitemap/1.0"})
    with urllib.request.urlopen(req, timeout=30) as resp:
        return resp.read().decode("utf-8", errors="replace")


def cursos_publicados(pagina_html: str) -> list[tuple[str, str]]:
    """Devolve [(uuid, título)] na ordem em que aparecem no catálogo, sem repetição."""
    vistos: dict[str, str] = {}
    padrao = re.compile(
        r'href="/cursos/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})"'
        r'(?:[^>]*aria-label="Ver ([^"]+)")?',
        re.I,
    )
    for uuid, titulo in padrao.findall(pagina_html):
        uuid = uuid.lower()
        if uuid not in vistos or (titulo and not vistos[uuid]):
            vistos[uuid] = html.unescape(titulo or "")
    return list(vistos.items())


def gerar_xml(urls: list[str]) -> str:
    linhas = ['<?xml version="1.0" encoding="UTF-8"?>',
              '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">']
    for u in urls:
        linhas += ["  <url>", f"    <loc>{html.escape(u, quote=True)}</loc>", "  </url>"]
    linhas += ["</urlset>", ""]
    return "\n".join(linhas)


def main() -> int:
    try:
        catalogo = baixar(f"{ORIGEM}/cursos")
    except Exception as erro:  # noqa: BLE001
        print(f"erro ao baixar {ORIGEM}/cursos: {erro}", file=sys.stderr)
        return 1
    cursos = cursos_publicados(catalogo)
    if not cursos:
        print("nenhum curso encontrado no catálogo; nada foi gravado", file=sys.stderr)
        return 1
    urls = [f"{ORIGEM}{p}" for p in PAGINAS_FIXAS] + [f"{ORIGEM}/cursos/{uuid}" for uuid, _ in cursos]
    xml = gerar_xml(urls)
    for saida in SAIDAS:
        saida.parent.mkdir(parents=True, exist_ok=True)
        saida.write_text(xml, encoding="utf-8")
        print(f"gravado {saida.relative_to(RAIZ)} ({len(urls)} URLs)")
    for uuid, titulo in cursos:
        print(f"  - {titulo or '(sem título)'} -> /cursos/{uuid}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
