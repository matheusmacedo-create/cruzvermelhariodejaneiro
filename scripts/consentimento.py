#!/usr/bin/env python3
"""Aviso de cookies (site/consentimento/consentimento.js) e o bloco de medição que depende dele.

O bloco de medição da home (entre <!-- Google tag (gtag.js) --> e <!-- End Meta Pixel Code -->) só
baixa o Google Analytics e o Pixel da Meta com consentimento, e termina com a tag do aviso:
<script src="/consentimento/consentimento.js?v=HASH" defer></script>. O hash é do conteúdo do
arquivo: mudou o aviso, muda a URL, e o cache de um ano (site/consentimento/.htaccess) nunca serve
a versão velha.

Este script:
  1. carimba o hash atual na tag do bloco da home;
  2. copia o bloco inteiro da home para as páginas mantidas à mão que têm os mesmos marcadores
     (nas de outro host, como o Impacto das Cores, com o endereço completo do aviso).
Os geradores (matrícula, checkout, bio, doação, 404, idiomas, verificar, políticas) copiam o bloco
da home por partes_da_home(): rode este script ANTES deles.

Uso:  python3 scripts/consentimento.py
"""
from __future__ import annotations

import hashlib
import re
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
ARQUIVO = RAIZ / "site" / "consentimento" / "consentimento.js"
HOME = RAIZ / "site" / "index.html"
PAGINAS_MANUAIS = ["site/equipe.html", "site/campanha-agasalho.html", "site/doacao.html", "site/historia/index.html"]
# Páginas servidas em outro host (subdomínio com pasta dentro de public_html): a tag do aviso vai
# com o endereço completo do site principal, porque /consentimento/ não existe nesse host.
PAGINAS_OUTRO_HOST = ["site/projetocores/index.html"]
ORIGEM = "https://cruzvermelhariodejaneiro.org"
TAG = re.compile(r'<script src="/consentimento/consentimento\.js\?v=[0-9a-f]{10}" defer></script>')


def versao() -> str:
    return hashlib.sha256(ARQUIVO.read_bytes()).hexdigest()[:10]


def tag() -> str:
    return f'<script src="/consentimento/consentimento.js?v={versao()}" defer></script>'


MARCA_INICIO = re.compile(r"^[ \t]*<!-- Google tag \(gtag\.js\) -->\n", re.M)
MARCA_FIM = re.compile(r"^[ \t]*<!-- End Meta Pixel Code -->\n", re.M)


def bloco(html: str) -> tuple[int, int] | None:
    """Onde começa e termina o bloco de medição (com ou sem recuo antes dos marcadores)."""
    a = MARCA_INICIO.search(html)
    b = MARCA_FIM.search(html, a.end()) if a else None
    return (a.start(), b.end()) if a and b else None


def main() -> int:
    home = HOME.read_text(encoding="utf-8")
    if not TAG.search(home):
        print("a home não tem a tag do aviso de cookies no bloco de medição", file=sys.stderr)
        return 1
    nova = TAG.sub(tag(), home, count=1)
    if nova != home:
        HOME.write_text(nova, encoding="utf-8")
        print(f"atualizado site/index.html (aviso de cookies v={versao()})")
    a, b = bloco(nova)
    medicao = nova[a:b]
    for caminho in PAGINAS_MANUAIS + PAGINAS_OUTRO_HOST:
        p = RAIZ / caminho
        html = p.read_text(encoding="utf-8")
        posicao = bloco(html)
        if not posicao:
            print(f"  ! sem os marcadores do bloco de medição: {caminho}", file=sys.stderr)
            continue
        x, y = posicao
        copia = medicao
        if caminho in PAGINAS_OUTRO_HOST:
            copia = medicao.replace('src="/consentimento/', f'src="{ORIGEM}/consentimento/')
        novo = html[:x] + copia + html[y:]
        if novo != html:
            p.write_text(novo, encoding="utf-8")
            print(f"atualizado {caminho}")
        else:
            print(f"sem mudança {caminho}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
