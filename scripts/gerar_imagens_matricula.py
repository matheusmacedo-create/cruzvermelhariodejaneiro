#!/usr/bin/env python3
"""Gera as fotos dos cursos da página de matrícula a partir das imagens publicadas na escola.

Lê site/matricula-cursos-presenciais/cursos.json (campo imagem_url, preenchido por
scripts/sincronizar_catalogo.py), baixa a foto de cada curso, recorta ao centro em 4:3 (o mesmo
enquadramento que a escola usa na página do curso) e grava WebP com 960 e 480 px de largura em
site/matricula-cursos-presenciais/img/<slug>-960.webp e <slug>-480.webp. Arquivos .webp da pasta
que não correspondem a nenhum curso são apagados.

Uso:  python3 scripts/gerar_imagens_matricula.py
Requer Pillow com suporte a WebP:  pip install pillow
"""
from __future__ import annotations

import io
import json
import urllib.request
from pathlib import Path

from PIL import Image

RAIZ = Path(__file__).resolve().parent.parent
DADOS = RAIZ / "site" / "matricula-cursos-presenciais" / "cursos.json"
PASTA = RAIZ / "site" / "matricula-cursos-presenciais" / "img"
PROPORCAO = (4, 3)
LARGURAS = (960, 480)
QUALIDADE = 82


def baixar(url: str) -> bytes:
    req = urllib.request.Request(url, headers={"User-Agent": "cvb-rj-matricula-presencial/1.0"})
    with urllib.request.urlopen(req, timeout=60) as resp:
        return resp.read()


def recortar(im: Image.Image) -> Image.Image:
    """Recorte central na proporção PROPORCAO, sem distorcer."""
    w, h = im.size
    alvo = PROPORCAO[0] / PROPORCAO[1]
    if w / h > alvo:
        nw = round(h * alvo)
        x = (w - nw) // 2
        return im.crop((x, 0, x + nw, h))
    nh = round(w / alvo)
    y = (h - nh) // 2
    return im.crop((0, y, w, y + nh))


def main() -> int:
    dados = json.loads(DADOS.read_text(encoding="utf-8"))
    PASTA.mkdir(parents=True, exist_ok=True)
    esperados: set[str] = set()
    for c in dados["cursos"]:
        url = c.get("imagem_url")
        if not url:
            print(f"  - {c['nome']}: sem foto na escola; a página fica sem imagem para este curso")
            continue
        im = recortar(Image.open(io.BytesIO(baixar(url))).convert("RGB"))
        tamanhos = []
        for larg in LARGURAS:
            alt = round(larg * PROPORCAO[1] / PROPORCAO[0])
            saida = PASTA / f"{c['imagem']}-{larg}.webp"
            im.resize((larg, alt), Image.LANCZOS).save(saida, "WEBP", quality=QUALIDADE, method=6)
            esperados.add(saida.name)
            tamanhos.append(f"{larg}px={saida.stat().st_size // 1024} KB")
        print(f"  - {c['nome']}: {url.rsplit('/', 1)[-1]} -> {c['imagem']} ({', '.join(tamanhos)})")
    for f in sorted(PASTA.glob("*.webp")):
        if f.name not in esperados:
            f.unlink()
            print(f"  apagado {f.name} (sem curso correspondente)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
