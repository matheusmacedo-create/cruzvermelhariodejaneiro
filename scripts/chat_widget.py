#!/usr/bin/env python3
"""Chat de contato por e-mail (site/chat/): tags com hash para as páginas e lista de cursos no chat.js.

O chat é um só para o site inteiro: cada página carrega /chat/chat.css e /chat/chat.js com um hash do
conteúdo na query string (muda o arquivo, muda a URL, o cache não segura versão velha). Os geradores
(gerar_matricula_presencial.py, gerar_checkout.py) importam tags() e atualizar_cursos(); as páginas
mantidas à mão (home, equipe, doação, agasalho, 404) são atualizadas por este script.

Uso:  python3 scripts/chat_widget.py
Ordem completa quando o chat.js muda: gerar_matricula_presencial.py (reescreve a lista de cursos do
chat.js e gera a matrícula) → gerar_checkout.py → chat_widget.py (páginas manuais).
"""
from __future__ import annotations

import hashlib
import json
import re
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
PASTA = RAIZ / "site" / "chat"
URL = "/chat/"
PAGINAS_MANUAIS = ["site/index.html", "site/equipe.html", "site/doacao.html", "site/campanha-agasalho.html", "site/404.html"]
MARCA_INI = "/* chat:cursos"
MARCA_FIM = "/* /chat:cursos */"
PADRAO_TAGS = re.compile(
    r'  <!-- Chat de contato por e-mail[^\n]*\n'
    r'  <link rel="stylesheet" href="/chat/chat\.css\?v=[0-9a-f]+">\n'
    r'  <script src="/chat/chat\.js\?v=[0-9a-f]+" defer></script>'
)


def url(nome: str) -> str:
    conteudo = (PASTA / nome).read_bytes()
    return f"{URL}{nome}?v={hashlib.sha256(conteudo).hexdigest()[:10]}"


def tags() -> str:
    """As duas tags que toda página leva antes de </body> (CSS não bloqueia a primeira dobra; JS adiado)."""
    return ('  <!-- Chat de contato por e-mail (site/chat/, scripts/chat_widget.py) -->\n'
            f'  <link rel="stylesheet" href="{url("chat.css")}">\n'
            f'  <script src="{url("chat.js")}" defer></script>')


def aplicar(html: str) -> str:
    """Troca as tags existentes pelas atuais ou as insere antes de </body>."""
    novo = tags()
    if PADRAO_TAGS.search(html):
        return PADRAO_TAGS.sub(lambda m: novo, html, count=1)
    if "</body>" not in html:
        raise SystemExit("página sem </body>")
    return html.replace("</body>", novo + "\n</body>", 1)


def atualizar_cursos(cursos: list[dict]) -> bool:
    """Reescreve a lista de cursos do chat.js (slug e nome) entre os marcadores. Devolve True se mudou."""
    arquivo = PASTA / "chat.js"
    js = arquivo.read_text(encoding="utf-8")
    a = js.index(MARCA_INI)
    a = js.index("*/", a) + len("*/")
    b = js.index(MARCA_FIM)
    itens = ",\n".join(
        f"    {{ slug: {json.dumps(c['slug'], ensure_ascii=False)}, nome: {json.dumps(c['nome'], ensure_ascii=False)} }}" for c in cursos
    )
    novo = js[:a] + "\n  var CURSOS = [\n" + itens + "\n  ];\n  " + js[b:]
    if novo == js:
        return False
    arquivo.write_text(novo, encoding="utf-8")
    return True


def main() -> int:
    for caminho in PAGINAS_MANUAIS:
        p = RAIZ / caminho
        if not p.exists():
            print(f"  ! não existe: {caminho}", file=sys.stderr)
            continue
        html = p.read_text(encoding="utf-8")
        novo = aplicar(html)
        if novo != html:
            p.write_text(novo, encoding="utf-8")
            print(f"atualizado {caminho}")
        else:
            print(f"sem mudança {caminho}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
