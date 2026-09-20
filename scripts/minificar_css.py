#!/usr/bin/env python3
"""Minificador de CSS conservador, usado no CSS que os geradores copiam da home.

Só faz o que é seguro sem entender a gramática inteira do CSS: tira comentários, junta espaços,
remove espaço em volta da pontuação e o ponto e vírgula antes de fechar bloco. Strings e url()
passam intactos, porque o conteúdo deles é significativo (aspas, espaços e parênteses).

Uso como módulo:  from minificar_css import minificar
Uso na linha de comando (só para conferir o ganho):  python3 scripts/minificar_css.py site/index.html
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

# Pedaços que não podem ser tocados: strings entre aspas e url(...) sem aspas.
INTOCAVEL = re.compile(r"""("(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|url\([^)'"]*\))""")


def _minificar_trecho(css: str) -> str:
    css = re.sub(r"/\*.*?\*/", "", css, flags=re.S)          # comentários
    css = re.sub(r"\s+", " ", css)                            # espaços em sequência viram um só
    css = re.sub(r"\s*([{}:;,>~])\s*", r"\1", css)            # espaço em volta da pontuação
    css = re.sub(r";\}", "}", css)                            # ; inútil antes de fechar
    # O combinador "+" e o "-" precisam do espaço dentro de calc() e em seletores; não mexemos neles.
    css = re.sub(r"\)\s+([{,])", r")\1", css)
    # Sem strip: o espaço nas bordas separa este trecho do texto intocável vizinho
    # (em "url(a.png) no-repeat", perder esse espaço junta os dois valores).
    return css


def minificar(css: str) -> str:
    """Minifica preservando strings e url()."""
    partes = INTOCAVEL.split(css)
    for i in range(0, len(partes), 2):                        # índices pares são código; ímpares, intocáveis
        partes[i] = _minificar_trecho(partes[i])
    return "".join(partes).strip()


def main() -> int:
    for caminho in sys.argv[1:]:
        texto = Path(caminho).read_text(encoding="utf-8")
        blocos = re.findall(r"<style>(.*?)</style>", texto, re.S)
        for b in blocos:
            m = minificar(b)
            print(f"{caminho}: {len(b)} → {len(m)} bytes ({100 - len(m) * 100 // max(len(b), 1)}% menor)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
