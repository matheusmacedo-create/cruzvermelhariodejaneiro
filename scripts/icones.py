#!/usr/bin/env python3
"""Ícones em SVG inline no lugar do Font Awesome (economia de ~450 KB e 4 requisições por página).

Os desenhos vêm de scripts/icones.json (Font Awesome Free 6.4.0, licença CC BY 4.0, baixados uma vez
de cdnjs). Cada `<i class="fa-solid fa-nome"></i>` vira `<i class="fa-solid fa-nome"><svg class="ico">
<use href="#i-nome"/></svg></i>`: o <i> continua existindo, então o CSS que estiliza os ícones não muda;
o desenho entra por um sprite <svg> escondido no começo do <body>, só com os símbolos usados na página.

Uso direto (páginas mantidas à mão): python3 scripts/icones.py site/index.html site/equipe.html
Os geradores importam trocar_tags() e injetar_sprite().
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
ICONES = json.loads((RAIZ / "scripts" / "icones.json").read_text(encoding="utf-8"))
TAG = re.compile(r'<i class="fa-(?:solid|regular|brands) fa-([a-z0-9-]+)"([^>]*)>(?:<svg class="ico".*?</svg>)*</i>')
USO = re.compile(r'href="#i-([a-z0-9-]+)"')
CSS = """    /* Ícones em SVG inline (scripts/icones.py) */
    i[class*="fa-"] { display: inline-block; font-style: normal; line-height: 1; }
    .ico { display: inline-block; width: 1em; height: 1em; fill: currentColor; vertical-align: -.125em; overflow: visible; }
    .nav-toggle .ico-xmark { display: none; }
    .main-header.nav-open .nav-toggle .ico-bars { display: none; }
    .main-header.nav-open .nav-toggle .ico-xmark { display: inline-block; }
"""


def svg_uso(nome: str) -> str:
    return f'<svg class="ico ico-{nome}" aria-hidden="true" focusable="false"><use href="#i-{nome}"/></svg>'


def trocar_tags(html: str) -> str:
    """Troca (ou refaz) cada <i class="fa-..."> pelo <i> com o SVG dentro. O botão do menu leva bars + xmark."""
    def troca(m: re.Match) -> str:
        nome, extras = m.group(1), m.group(2)
        if nome not in ICONES:
            print(f"  ! ícone sem desenho: {nome}", file=sys.stderr)
            return m.group(0)
        dentro = svg_uso(nome) + (svg_uso("xmark") if nome == "bars" else "")
        return f'<i class="fa-{ICONES[nome]["estilo"]} fa-{nome}"{extras}>{dentro}</i>'
    return TAG.sub(troca, html)


def injetar_sprite(html: str, extras: set[str] | None = None) -> str:
    """Põe (ou repõe) o sprite com os símbolos usados na página logo depois de <body>."""
    html = re.sub(r'\n?<svg xmlns="http://www.w3.org/2000/svg" id="sprite-icones".*?</svg>', "", html, count=1, flags=re.S)
    usados = sorted(set(USO.findall(html)) | (extras or set()))
    if not usados:
        return html
    simbolos = "".join(f'<symbol id="i-{n}" viewBox="{ICONES[n]["viewBox"]}"><path d="{ICONES[n]["d"]}"/></symbol>' for n in usados if n in ICONES)
    sprite = f'\n<svg xmlns="http://www.w3.org/2000/svg" id="sprite-icones" style="display:none" aria-hidden="true">{simbolos}</svg>'
    return re.sub(r"(<body[^>]*>)", lambda m: m.group(1) + sprite, html, count=1)


def remover_font_awesome(html: str) -> str:
    html = re.sub(r'  <link rel="preconnect" href="https://cdnjs\.cloudflare\.com" crossorigin>\n', "", html)
    html = re.sub(r'  <link rel="preload" href="https://cdnjs\.cloudflare\.com/ajax/libs/font-awesome/[^"]+" as="style"[^>]*>\n', "", html)
    html = re.sub(r'  <noscript><link rel="stylesheet" href="https://cdnjs\.cloudflare\.com/ajax/libs/font-awesome/[^"]+"></noscript>\n', "", html)
    html = re.sub(r'  <link rel="stylesheet" href="https://cdnjs\.cloudflare\.com/ajax/libs/font-awesome/[^"]+">\n', "", html)
    html = html.replace('      .main-header.nav-open .nav-toggle i::before { content: "\\f00d"; }\n', "")
    return html


def garantir_css(html: str) -> str:
    if ".ico {" in html:
        return html
    return html.replace("  <style>\n", "  <style>\n" + CSS, 1)


def converter(html: str, extras: set[str] | None = None) -> str:
    return injetar_sprite(garantir_css(remover_font_awesome(trocar_tags(html))), extras)


def main() -> int:
    for caminho in sys.argv[1:]:
        p = Path(caminho)
        novo = converter(p.read_text(encoding="utf-8"))
        p.write_text(novo, encoding="utf-8")
        print(f"{caminho}: {len(USO.findall(novo))} usos de ícone, {len(set(USO.findall(novo)))} símbolos no sprite")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
