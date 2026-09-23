#!/usr/bin/env python3
"""Calcula as medidas da "Inter Reserva": a fonte do aparelho redimensionada para ocupar o mesmo
espaço que a Inter, para a troca de fonte não empurrar o layout (CLS).

Baixa do Google Fonts a Inter e as reservas (Arimo, gêmea métrica da Arial, e Roboto, a do Android),
mede a largura média de cada uma ponderada pelo texto visível das páginas do site e imprime
size-adjust, ascent-override e descent-override para as regras @font-face do primeiro <style> de
site/index.html (copiado pelos geradores; equipe.html e campanha-agasalho.html têm cópia à mão).

Uso:  pip install fonttools brotli && python3 scripts/calcular_reserva_fonte.py
Rodar de novo se a fonte do site mudar ou se o texto das páginas mudar muito. O negrito usa a média
entre os pesos 700 e 800 da Inter, que cobre os títulos (600 a 900).

O resultado é o ponto de partida: os valores publicados em 23/09/2026 foram conferidos no navegador,
comparando a altura das páginas com a Inter carregada e bloqueada, e diferem em décimos. O negrito
da Roboto ficou no valor do peso 700 (108,31%), que casou melhor a home e a matrícula.
"""
import collections
import html
import io
import re
import urllib.request
from pathlib import Path

from fontTools.ttLib import TTFont

RAIZ = Path(__file__).resolve().parent.parent
PAGINAS = ["index.html", "doe/index.html", "matricula-cursos-presenciais/index.html", "equipe.html"]
UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36"


def baixar(url: str) -> bytes:
    with urllib.request.urlopen(urllib.request.Request(url, headers={"User-Agent": UA}), timeout=30) as r:
        return r.read()


def fonte(familia: str, peso: int) -> TTFont:
    """Subconjunto latin do Google Fonts (cobre os acentos do português)."""
    css = baixar(f"https://fonts.googleapis.com/css2?family={familia}:wght@{peso}").decode()
    bloco = css[css.index("/* latin */"):]
    url = re.search(r"src: url\((https://[^)]+)\)", bloco).group(1)
    return TTFont(io.BytesIO(baixar(url)))


def frequencias() -> collections.Counter:
    texto = ""
    for p in PAGINAS:
        bruto = (RAIZ / "site" / p).read_text(encoding="utf-8")
        bruto = re.sub(r"(?is)<(script|style|svg|noscript)\b.*?</\1>", " ", bruto)
        texto += html.unescape(re.sub(r"<[^>]+>", " ", bruto))
    return collections.Counter(re.sub(r"\s+", " ", texto))


def largura(f: TTFont, freq: collections.Counter) -> float:
    upm, cmap, hmtx = f["head"].unitsPerEm, f.getBestCmap(), f["hmtx"]
    soma = peso = 0
    for ch, n in freq.items():
        g = cmap.get(ord(ch))
        if g:
            soma += hmtx[g][0] * n
            peso += n
    return soma / peso / upm


def main() -> None:
    freq = frequencias()
    inter = fonte("Inter", 400)
    upm = inter["head"].unitsPerEm
    asc, desc = inter["hhea"].ascent / upm, abs(inter["hhea"].descent) / upm
    regular = largura(inter, freq)
    negrito = (largura(fonte("Inter", 700), freq) + largura(fonte("Inter", 800), freq)) / 2
    print(f"texto analisado: {sum(freq.values())} caracteres de {len(PAGINAS)} páginas")
    for nome, familia in (('"Inter Reserva" (Arial, Arimo, Liberation)', "Arimo"), ('"Inter Reserva Android" (Roboto)', "Roboto")):
        for rotulo, alvo, peso in (("regular", regular, 400), ("negrito", negrito, 700)):
            sa = alvo / largura(fonte(familia, peso), freq)
            print(f"{nome} {rotulo}: size-adjust {sa * 100:.2f}%; ascent-override {asc / sa * 100:.2f}%; "
                  f"descent-override {desc / sa * 100:.2f}%; line-gap-override 0%")


if __name__ == "__main__":
    main()
