#!/usr/bin/env python3
"""Gera versões otimizadas (WebP, larguras fixas) das fotos e logos usados pelas páginas.

Lê os originais em site/assets/ (cópia do public_html/assets do servidor, fora do Git) e grava em
site/assets/otim/ arquivos <nome>-<largura>.webp, além das imagens de compartilhamento (og-*.jpg,
1200x630). As páginas referenciam essas versões com srcset; os originais ficam no servidor.

Uso:  python3 scripts/otimizar_imagens.py
Depois: scripts/publicar_hostinger.sh site/assets/otim/*  (os arquivos novos precisam ir ao servidor)
"""
from __future__ import annotations

import sys
from pathlib import Path

from PIL import Image, ImageOps

RAIZ = Path(__file__).resolve().parent.parent
ORIGEM = RAIZ / "site" / "assets"
DESTINO = ORIGEM / "otim"
QUALIDADE = 78

# Fotos: larguras geradas (só as menores ou iguais à largura original, mais a original se for menor que a maior pedida).
FOTOS = {
    "hero-equipe-grupo.jpg": [960, 1440],
    "impacto-cores-banner.jpg": [640, 1200, 1920],
    "agasalho-banner.jpg": [640, 1200, 1920],
    "reuniao-cicv.jpg": [480, 960],
    "reuniao-cicv-grupo.jpg": [480, 960],
    "agasalho-hero-rua.jpg": [640, 1264],
    "agasalho-hero-favela.jpg": [640, 1264],
    "agasalho-fila-comunidade.jpg": [640, 1264],
    "agasalho-cartaz.jpg": [640, 1200],
    "curso-primeiros-socorros-novo.jpg": [480, 768],
    "curso-puncao-venosa.jpg": [480, 768],
    "curso-cuidador-idosos.jpg": [480, 768],
    "curso-bombeiro-civil.jpg": [480, 768],
    "curso-lei-lucas.jpg": [480, 768],
    "curso-suporte-basico-vida.jpg": [480, 768],
    "hero-formacao-voluntarios.jpg": [540, 1080],
    "voluntario-microfone.jpg": [480, 960, 1440],
    "equipe-corredor.jpg": [480, 960, 1440],
    "acao-comunitaria-morro.jpg": [480, 1080],
    "auditorio-voluntarios.jpg": [480, 960, 1440],
    "curso-pratico-sala.jpg": [480, 1080],
    "protocolo-trauma.jpg": [480, 1080],
    "dia-cruz-vermelha.jpg": [640, 1254],
}
# Logos com transparência: uma largura só.
LOGOS = {
    "logo-cvb-rj.png": 520,
    "logo-rj.png": 240,
    "parceiros/avon.png": 400,
    "parceiros/carrefour.png": 400,
    "parceiros/embelleze.png": 400,
    "parceiros/johnson-johnson.png": 400,
    "parceiros/leite-de-rosas.png": 320,
    "parceiros/ortobom.png": 400,
    "parceiros/renner.webp": 400,
}
# Imagens de compartilhamento (Open Graph): recorte central 1200x630 em JPEG.
OG = {
    "og-home.jpg": ("hero-equipe-grupo.jpg", 0.35),
    "og-doacao.jpg": ("acao-comunitaria-morro.jpg", 0.5),
    "og-campanha-agasalho.jpg": ("agasalho-hero-rua.jpg", 0.5),
    "og-equipe.jpg": ("equipe-corredor.jpg", 0.4),
    "og-matricula.jpg": ("auditorio-voluntarios.jpg", 0.5),
}


def redimensionar(im: Image.Image, largura: int) -> Image.Image:
    if im.width <= largura:
        return im
    altura = round(im.height * largura / im.width)
    return im.resize((largura, altura), Image.LANCZOS)


def main() -> int:
    if not ORIGEM.is_dir():
        print(f"pasta {ORIGEM} não existe: baixe os originais do servidor (public_html/assets)", file=sys.stderr)
        return 1
    DESTINO.mkdir(exist_ok=True)
    (DESTINO / "parceiros").mkdir(exist_ok=True)
    gerados = 0
    antes = depois = 0
    for nome, larguras in FOTOS.items():
        arq = ORIGEM / nome
        if not arq.is_file():
            print(f"  ! falta {nome}", file=sys.stderr)
            continue
        im = ImageOps.exif_transpose(Image.open(arq)).convert("RGB")
        antes += arq.stat().st_size
        for largura in sorted(set(min(l, im.width) for l in larguras)):
            saida = DESTINO / f"{arq.stem}-{largura}.webp"
            redimensionar(im, largura).save(saida, "WEBP", quality=QUALIDADE, method=6)
            gerados += 1
            depois += saida.stat().st_size
    for nome, largura in LOGOS.items():
        arq = ORIGEM / nome
        if not arq.is_file():
            print(f"  ! falta {nome}", file=sys.stderr)
            continue
        im = Image.open(arq).convert("RGBA")
        antes += arq.stat().st_size
        saida = DESTINO / nome.replace(".png", "").replace(".webp", "")
        saida = saida.with_name(f"{saida.name}-{min(largura, im.width)}.webp")
        redimensionar(im, largura).save(saida, "WEBP", quality=85, method=6)
        gerados += 1
        depois += saida.stat().st_size
    for nome, (fonte, foco) in OG.items():
        arq = ORIGEM / fonte
        if not arq.is_file():
            continue
        im = ImageOps.exif_transpose(Image.open(arq)).convert("RGB")
        recorte = ImageOps.fit(im, (1200, 630), Image.LANCZOS, centering=(0.5, foco))
        recorte.save(DESTINO / nome, "JPEG", quality=82, optimize=True, progressive=True)
        gerados += 1
    print(f"{gerados} arquivos em site/assets/otim/ · originais {antes // 1024} KB → versões {depois // 1024} KB (todas as larguras somadas)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
