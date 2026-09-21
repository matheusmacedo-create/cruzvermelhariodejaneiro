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

import minificar_css

RAIZ = Path(__file__).resolve().parent.parent
PASTA = RAIZ / "site" / "chat"
URL = "/chat/"
PAGINAS_MANUAIS = ["site/index.html", "site/equipe.html", "site/doacao.html", "site/campanha-agasalho.html", "site/404.html"]
MARCA_INI = "/* chat:cursos"
MARCA_FIM = "/* /chat:cursos */"
MARCA_RESP_INI = "/* chat:respostas"
MARCA_RESP_FIM = "/* /chat:respostas */"
FAQ_HOME = RAIZ / "site" / "faq-home.json"
PADRAO_TAGS = re.compile(
    r'  <!-- Chat de contato por e-mail[^\n]*\n'
    r'  <link rel="stylesheet" href="/chat/chat(?:\.min)?\.css\?v=[0-9a-f]+">\n'
    r'  <script src="/chat/chat\.js\?v=[0-9a-f]+" defer></script>'
)


def url(nome: str) -> str:
    """URL do arquivo com hash do conteúdo. CSS vai minificado; o .css fonte fica legível no repo."""
    arquivo = PASTA / nome
    if arquivo.suffix == ".css":
        arquivo = minificar_css.gerar_min(arquivo)
    return f"{URL}{arquivo.name}?v={hashlib.sha256(arquivo.read_bytes()).hexdigest()[:10]}"


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
    """Reescreve a lista de cursos do chat.js entre os marcadores. Devolve True se mudou.

    Cada curso leva a ficha (carga horária, escolaridade, valor) e as perguntas frequentes que a
    escola publica. O chat mostra a ficha assim que a pessoa escolhe o curso e oferece as perguntas
    como botões: a resposta é sempre a que a escola escreveu, sem o chat ter de adivinhar o que
    a pessoa quis dizer.
    """
    arquivo = PASTA / "chat.js"
    js = arquivo.read_text(encoding="utf-8")
    a = js.index(MARCA_INI)
    a = js.index("*/", a) + len("*/")
    b = js.index(MARCA_FIM)
    j = lambda v: json.dumps(v, ensure_ascii=False)
    linhas = []
    for c in cursos:
        campos = [f"slug: {j(c['slug'])}", f"nome: {j(c['nome'])}"]
        for chave in ("carga", "escolaridade", "valor", "descricao"):
            if c.get(chave):
                campos.append(f"{chave}: {j(c[chave])}")
        if c.get("faq"):
            duvidas = ",\n".join(
                f"        {{ p: {j(q['pergunta'])}, r: {j(q['resposta'])} }}" for q in c["faq"])
            campos.append("faq: [\n" + duvidas + "\n      ]")
        linhas.append("    { " + ", ".join(campos) + " }")
    novo = js[:a] + "\n  var CURSOS = [\n" + ",\n".join(linhas) + "\n  ];\n  " + js[b:]
    if novo == js:
        return False
    arquivo.write_text(novo, encoding="utf-8")
    return True


def atualizar_respostas() -> bool:
    """Leva para o chat.js as respostas da FAQ da home marcadas com "chat". Devolve True se mudou.

    A marca fica no próprio site/faq-home.json (`"chat": ["voluntariado"]`): quem edita a FAQ vê,
    na mesma linha, em que assunto do chat aquela resposta se oferece. O chat mostra as perguntas
    como botões, então a resposta é sempre a que foi escrita e revisada — nada é interpretado.
    """
    dados = json.loads(FAQ_HOME.read_text(encoding="utf-8"))
    itens = [q for g in dados["grupos"] for q in g["perguntas"] if q.get("chat")]
    arquivo = PASTA / "chat.js"
    js = arquivo.read_text(encoding="utf-8")
    a = js.index(MARCA_RESP_INI)
    a = js.index("*/", a) + len("*/")
    b = js.index(MARCA_RESP_FIM)
    j = lambda v: json.dumps(v, ensure_ascii=False)
    # "rotulo" é o texto curto do botão; "p" é a pergunta completa, que vai na conversa. A pergunta
    # da página carrega o nome completo da filial, que num botão de celular vira três linhas.
    linhas = [f"    {{ assuntos: {j(q['chat'])}, rotulo: {j(q.get('chatRotulo') or q['pergunta'])}, "
              f"p: {j(q['pergunta'])}, r: {j(q['resposta'])} }}" for q in itens]
    novo = js[:a] + "\n  var RESPOSTAS = [\n" + ",\n".join(linhas) + "\n  ];\n  " + js[b:]
    if novo == js:
        return False
    arquivo.write_text(novo, encoding="utf-8")
    return True


def main() -> int:
    if atualizar_respostas():
        print("atualizado site/chat/chat.js (respostas da FAQ)")
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
