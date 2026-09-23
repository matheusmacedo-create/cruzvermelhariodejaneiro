#!/usr/bin/env python3
"""Troca as imagens das páginas mantidas à mão pelas versões otimizadas de site/assets/otim/.

Para cada <img src="assets/X.ext"> com versões geradas por otimizar_imagens.py: src e srcset em
WebP, sizes a partir da largura real medida em desktop e celular (tabela MEDIDAS), width/height
da versão escolhida, loading="lazy" fora da primeira dobra e fetchpriority="high" na imagem
principal. Fundos do hero em CSS e o mosaico do Instagram têm tratamento próprio.

Uso:  python3 scripts/aplicar_imagens_otimizadas.py   (idempotente: já convertida, não mexe)
"""
from __future__ import annotations

import re
from pathlib import Path

from PIL import Image

RAIZ = Path(__file__).resolve().parent.parent
SITE = RAIZ / "site"
OTIM = SITE / "assets" / "otim"

# Largura renderizada (desktop 1366 px, celular 390 px) de cada <img>, na ordem em que aparece na
# página, medida ao vivo em 19/09/2026 com Playwright. 0 = escondida (slide inativo).
MEDIDAS = {
    "index.html": [("logo-cvb-rj.png", 173, 186), ("impacto-cores-banner.jpg", 1366, 390), ("agasalho-banner.jpg", 0, 0),
                   ("reuniao-cicv.jpg", 255, 174), ("reuniao-cicv-grupo.jpg", 255, 174), ("impacto-cores-banner.jpg", 349, 360),
                   ("agasalho-hero-rua.jpg", 349, 360), ("curso-primeiros-socorros-novo.jpg", 576, 360), ("curso-puncao-venosa.jpg", 0, 0),
                   ("curso-cuidador-idosos.jpg", 0, 0), ("curso-bombeiro-civil.jpg", 351, 360), ("curso-lei-lucas.jpg", 351, 360),
                   ("curso-suporte-basico-vida.jpg", 351, 360), ("hero-formacao-voluntarios.jpg", 620, 362), ("voluntario-microfone.jpg", 150, 360),
                   ("equipe-corredor.jpg", 150, 360), ("acao-comunitaria-morro.jpg", 150, 360)],
    "doacao.html": [("logo-rj.png", 54, 46), ("acao-comunitaria-morro.jpg", 618, 362), ("curso-pratico-sala.jpg", 618, 362),
                    ("auditorio-voluntarios.jpg", 580, 362), ("equipe-corredor.jpg", 580, 362), ("logo-rj.png", 58, 58)],
    "campanha-agasalho.html": [("logo-rj.png", 54, 54), ("agasalho-hero-rua.jpg", 415, 360), ("agasalho-hero-favela.jpg", 281, 360),
                               ("agasalho-cartaz.jpg", 533, 360), ("agasalho-fila-comunidade.jpg", 589, 360), ("logo-rj.png", 54, 54)],
    "equipe.html": [("logo-cvb-rj.png", 173, 186), ("logo-cvb-rj.png", 310, 342)],
}
# Imagens da primeira dobra (não levam lazy) e a principal de cada página (fetchpriority=high).
PRIMEIRA_DOBRA = {"index.html": {"logo-cvb-rj.png", "impacto-cores-banner.jpg", "agasalho-banner.jpg"},
                  "doacao.html": {"logo-rj.png"}, "campanha-agasalho.html": {"logo-rj.png", "agasalho-hero-rua.jpg", "agasalho-hero-favela.jpg"},
                  "equipe.html": {"logo-cvb-rj.png"}}
PRINCIPAL = {"index.html": "impacto-cores-banner.jpg", "campanha-agasalho.html": "agasalho-hero-rua.jpg"}
TAG = re.compile(r"<img\b[^>]*>")


def variantes(base: str) -> list[tuple[int, str]]:
    achadas = []
    for arq in OTIM.glob(f"{base}-*.webp"):
        m = re.fullmatch(rf"{re.escape(base)}-(\d+)\.webp", arq.relative_to(OTIM).as_posix())
        if m:
            achadas.append((int(m.group(1)), arq))
    return sorted(achadas)


def dimensoes(arq: Path) -> tuple[int, int]:
    with Image.open(arq) as im:
        return im.size


def caminho_publico(arq: Path) -> str:
    return "assets/otim/" + arq.relative_to(OTIM).as_posix()


def atributo(tag: str, nome: str) -> str | None:
    m = re.search(rf'\s{nome}="([^"]*)"', tag)
    return m.group(1) if m else None


def sem(tag: str, *nomes: str) -> str:
    for n in nomes:
        tag = re.sub(rf'\s{n}="[^"]*"', "", tag)
    return tag


def converter_pagina(nome: str) -> int:
    arq = SITE / nome
    html = arq.read_text(encoding="utf-8")
    medidas = MEDIDAS.get(nome, [])
    trocas = 0
    indice = -1
    priorizada = False  # só a primeira ocorrência da imagem principal leva fetchpriority

    def substituir(m: re.Match) -> str:
        nonlocal trocas, indice, priorizada
        tag = m.group(0)
        src = atributo(tag, "src") or ""
        if not src.startswith("assets/") or src.startswith("assets/otim/"):
            return tag
        indice += 1
        base = Path(src).stem if "/parceiros/" not in src else "parceiros/" + Path(src).stem
        vs = variantes(base)
        if not vs:
            return tag
        arquivo = src.split("assets/")[1]
        wd = wm = 0
        if indice < len(medidas) and medidas[indice][0] == arquivo:
            _, wd, wm = medidas[indice]
        if wd == 0:  # escondida na medição: usa a maior medida da mesma imagem, senão a largura do container
            candidatos = [d for a, d, _ in medidas if a == arquivo and d]
            wd = max(candidatos) if candidatos else 1100
        if wm == 0:
            candidatos = [mm for a, _, mm in medidas if a == arquivo and mm]
            wm = max(candidatos) if candidatos else 360
        mosaico = "insta-foto" in html[max(0, m.start() - 200):m.start()]
        grande = mosaico and "grande" in html[max(0, m.start() - 200):m.start()]
        alvo = wd * 1.5
        escolhida = next((v for v in vs if v[0] >= alvo), vs[-1])
        if mosaico:
            escolhida = next((v for v in vs if v[0] >= (960 if grande else 480)), vs[-1])
        w, h = dimensoes(escolhida[1])
        novo = sem(tag, "src", "srcset", "sizes", "width", "height", "loading", "decoding", "fetchpriority")
        extras = f' src="{caminho_publico(escolhida[1])}"'
        if len(vs) > 1 and not mosaico:
            extras += ' srcset="' + ", ".join(f"{caminho_publico(a)} {l}w" for l, a in vs) + '"'
            movel = "100vw" if wm >= 340 else f"{wm}px"
            desktop = "100vw" if wd >= 1200 else f"{wd}px"
            extras += f' sizes="{desktop}"' if movel == desktop else f' sizes="(max-width: 620px) {movel}, {desktop}"'
        extras += f' width="{w}" height="{h}"'
        if arquivo == PRINCIPAL.get(nome) and not priorizada:
            # A mesma imagem pode voltar mais abaixo (na home, a miniatura do card de campanha):
            # essa cópia fora da dobra não pode disputar banda com o LCP.
            priorizada = True
            extras += ' fetchpriority="high"'
        elif arquivo not in PRIMEIRA_DOBRA.get(nome, set()) or indice > 3 or arquivo == PRINCIPAL.get(nome):
            extras += ' loading="lazy" decoding="async"'
        trocas += 1
        return novo[:-1] + extras + ">"

    html = TAG.sub(substituir, html)

    if nome == "index.html":
        # fundos do hero em CSS e preload da imagem do hero
        html = html.replace('url("assets/hero-equipe-grupo.jpg") center 30% / cover no-repeat;', 'url("assets/otim/hero-equipe-grupo-1440.webp") center 30% / cover no-repeat;')
        html = html.replace('url("assets/hero-equipe-grupo.jpg") center 20% / cover no-repeat;', 'url("assets/otim/hero-equipe-grupo-960.webp") center 20% / cover no-repeat;')
        if 'rel="preload" as="image"' not in html:
            html = html.replace('  <link rel="preconnect" href="https://fonts.googleapis.com">',
                                '  <link rel="preload" as="image" href="assets/otim/hero-equipe-grupo-1440.webp" media="(min-width: 921px)">\n'
                                '  <link rel="preload" as="image" href="assets/otim/hero-equipe-grupo-960.webp" media="(max-width: 920px)">\n'
                                '  <link rel="preconnect" href="https://fonts.googleapis.com">', 1)
        # mosaico do Instagram: lista de troca com a menor versão a partir de 480 (blocos pequenos)
        # e a partir de 960 (bloco grande), escolhidas entre as larguras que existem para cada foto.
        def pool(m: re.Match) -> str:
            base = m.group(1)
            vs = variantes(base)
            if not vs:
                return m.group(0)
            pequena = next((a for l, a in vs if l >= 480), vs[-1][1])
            grande = next((a for l, a in vs if l >= 960), vs[-1][1])
            return f'"src": "{caminho_publico(pequena)}", "grande": "{caminho_publico(grande)}"'
        html = re.sub(r'"src": "assets/(?:otim/)?([a-z0-9-]+?)(?:-\d+)?\.(?:jpg|webp)"(?:, "grande": "[^"]*")?', pool, html)
    arq.write_text(html, encoding="utf-8")
    return trocas


def main() -> int:
    total = 0
    for nome in MEDIDAS:
        n = converter_pagina(nome)
        total += n
        print(f"{nome}: {n} imagens convertidas")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
