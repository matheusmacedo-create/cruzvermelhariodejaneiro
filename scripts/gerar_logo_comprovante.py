#!/usr/bin/env python3
"""Gera o logo embutido no comprovante de inscrição em PDF.

O PDF é montado à mão em PHP (lib/pdf.php), sem biblioteca: o servidor não tem Composer e o
download do FPDF é bloqueado aqui. O PDF sabe desenhar direto os dados de um PNG (os blocos IDAT
já são zlib com os filtros PNG, que o PDF chama de /Predictor 15), então o PHP só precisa ler os
blocos do arquivo — sem GD no servidor.

Este script prepara esse PNG uma vez, a partir do logo original (1730x520, o mesmo do site):

- compõe sobre branco: a página é branca, e o alfa custaria uma segunda imagem de máscara;
- reduz para 1325 px, que dá 300 dpi no tamanho em que é desenhado (318 pt de largura);
- reduz a paleta a 32 cores: o logo é vermelho, preto e branco mais o antisserrilhado, e com 3x
  de zoom não se distingue do original. Fica com ~30 KB em vez dos ~97 KB em RGB.

Saída: site/matricula-cursos-presenciais/api/lib/comprovante-logo.png (PNG normal, dá para abrir).

Uso:  python3 scripts/gerar_logo_comprovante.py [caminho-do-logo.png]
Sem argumento, baixa o original de https://cruzvermelhariodejaneiro.org/assets/logo-cvb-rj.png.
"""
from __future__ import annotations

import io
import sys
import urllib.request
from pathlib import Path

from PIL import Image

RAIZ = Path(__file__).resolve().parent.parent
DESTINO = RAIZ / "site" / "matricula-cursos-presenciais" / "api" / "lib" / "comprovante-logo.png"
ORIGEM = "https://cruzvermelhariodejaneiro.org/assets/logo-cvb-rj.png"
LARGURA = 1325
CORES = 32


def carregar(caminho: str | None) -> Image.Image:
    if caminho:
        return Image.open(caminho)
    with urllib.request.urlopen(ORIGEM, timeout=30) as r:
        return Image.open(io.BytesIO(r.read()))


def main() -> int:
    img = carregar(sys.argv[1] if len(sys.argv) > 1 else None).convert("RGBA")
    fundo = Image.new("RGBA", img.size, (255, 255, 255, 255))
    img = Image.alpha_composite(fundo, img).convert("RGB")
    altura = round(img.height * LARGURA / img.width)
    img = img.resize((LARGURA, altura), Image.LANCZOS)
    img = img.quantize(colors=CORES, method=Image.MEDIANCUT, dither=Image.NONE)
    # bits=8 explícito: o PHP espera 1 byte por pixel (o PDF aceita outros, mas não precisa)
    img.save(DESTINO, "PNG", optimize=True, bits=8)
    print(f"gravado {DESTINO.relative_to(RAIZ)}: {LARGURA}x{altura}, {CORES} cores, "
          f"{DESTINO.stat().st_size} bytes")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
