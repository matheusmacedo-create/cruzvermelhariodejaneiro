#!/usr/bin/env python3
"""Gera site/assets/otim/og-acervo.jpg, a imagem de compartilhamento (1200x630) do acervo.

É o og:image de /acervo/ (Redação, lib/acervo/paginas.ts), de /en/archive/ e de /es/acervo/, e o
das páginas de item sem imagem própria (documentos em PDF). Cartão tipográfico com o logo oficial
da filial; nenhuma cruz avulsa, porque o uso do emblema é regulado — o logo já é a forma certa.

A pasta site/assets/ não é versionada (fica só no servidor): depois de gerar, publicar com
  scripts/publicar_hostinger.sh site/assets/otim/og-acervo.jpg

Precisa do Pillow e de rede na primeira vez (baixa a Inter do Google Fonts para a pasta temporária).
"""
from __future__ import annotations

import tempfile
import urllib.request
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

RAIZ = Path(__file__).resolve().parent.parent
LOGO = RAIZ / "site" / "assets" / "otim" / "logo-cvb-rj-480.png"
SAIDA = RAIZ / "site" / "assets" / "otim" / "og-acervo.jpg"
# Um Safari antigo recebe a fonte em TrueType (WOFF), que o Pillow lê; navegador novo recebe WOFF2.
AGENTE = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_6_8) AppleWebKit/534.59.10 (KHTML, like Gecko) Version/5.1.9 Safari/534.59.10"


def inter(peso: int) -> Path:
    destino = Path(tempfile.gettempdir()) / f"inter-{peso}.woff"
    if not destino.exists():
        css = urllib.request.urlopen(urllib.request.Request(
            f"https://fonts.googleapis.com/css2?family=Inter:wght@{peso}", headers={"User-Agent": AGENTE}), timeout=30).read().decode()
        url = css.split("url(", 1)[1].split(")", 1)[0]
        destino.write_bytes(urllib.request.urlopen(url, timeout=30).read())
    return destino


def main() -> int:
    largura, altura = 1200, 630
    img = Image.new("RGB", (largura, altura), "#ffffff")
    d = ImageDraw.Draw(img)
    titulo = ImageFont.truetype(str(inter(800)), 150)
    texto = ImageFont.truetype(str(inter(500)), 38)
    endereco = ImageFont.truetype(str(inter(800)), 32)
    # Fichas de arquivo à direita: formas neutras.
    for n, (x, y) in enumerate([(820, 150), (860, 190), (900, 230)]):
        d.rounded_rectangle([x, y, x + 240, y + 300], radius=18, fill="#f7f8fa" if n < 2 else "#ffffff", outline="#d6dde6", width=3)
    x, y = 900, 230
    d.rounded_rectangle([x + 28, y + 36, x + 212, y + 150], radius=10, fill="#e2e8f0")
    for k, tamanho in enumerate([184, 150, 170]):
        d.rounded_rectangle([x + 28, y + 178 + k * 30, x + 28 + tamanho, y + 192 + k * 30], radius=7, fill="#e2e8f0")
    d.rectangle([x + 28, y + 36, x + 36, y + 150], fill="#cc0000")
    logo = Image.open(LOGO).convert("RGBA")
    logo = logo.resize((round(logo.width * 104 / logo.height), 104), Image.LANCZOS)
    img.paste(logo, (80, 70), logo)
    d.text((74, 205), "Acervo", font=titulo, fill="#0f1318")
    for k, linha in enumerate(["Documentos, fotos, vídeos e", "a história da filial"]):
        d.text((80, 395 + k * 50), linha, font=texto, fill="#3d4550")
    d.text((80, 525), "cruzvermelhariodejaneiro.org/acervo", font=endereco, fill="#cc0000")
    d.rectangle([0, altura - 14, largura, altura], fill="#cc0000")
    SAIDA.parent.mkdir(parents=True, exist_ok=True)
    img.save(SAIDA, "JPEG", quality=86, optimize=True, progressive=True)
    print(f"gravado {SAIDA.relative_to(RAIZ)} ({SAIDA.stat().st_size} bytes)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
