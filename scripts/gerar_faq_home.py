#!/usr/bin/env python3
"""Reescreve a seção "Perguntas frequentes" da home (site/index.html) a partir de site/faq-home.json.

O conteúdo fica no JSON (título da seção, grupos e perguntas com resposta e links); este script gera o
HTML entre os marcadores <!-- faq:inicio --> e <!-- faq:fim --> e o bloco FAQPage de dados
estruturados (script id="faq-ld"), para a FAQ ranquear em buscas orgânicas. Regras de texto: nome
completo "Cruz Vermelha Brasileira Rio de Janeiro", respostas de 45 a 110 palavras, links só internos, dos
canais oficiais ou do verbete da filial na Wikipédia (lista PERMITIDOS).

Uso:  python3 scripts/gerar_faq_home.py     (depois, publicar site/index.html)
"""
from __future__ import annotations

import html
import json
import re
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
HOME = RAIZ / "site" / "index.html"
DADOS = RAIZ / "site" / "faq-home.json"
ORIGEM = "https://cruzvermelhariodejaneiro.org"
MARCA_INI = "<!-- faq:inicio -->"
MARCA_FIM = "<!-- faq:fim -->"
PERMITIDOS = re.compile(r"^(/[a-z0-9\-./#?=&]*|https://escola\.cursoscruzvermelha\.org(/.*)?|https://form\.spotform\.com\.br/voluntariocruzvermelharj|https://pt\.wikipedia\.org/wiki/Cruz_Vermelha_Brasileira_-_Rio_de_Janeiro)$")
NACIONAL_SOZINHA = re.compile(r"Cruz Vermelha Brasileira(?!\s*(?:[–\-—·,])?\s*(?:Filial|Rio de Janeiro|do Rio|no Rio|RJ\b|Rio\b))")


def esc(s: str) -> str:
    return html.escape(s, quote=True)


def resposta_html(resposta: str, links: list[dict]) -> str:
    texto = esc(resposta)
    for l in links:
        if not PERMITIDOS.match(l["url"]):
            raise SystemExit(f"link fora da lista permitida: {l['url']}")
        alvo = esc(l["texto"])
        if alvo not in texto:
            raise SystemExit(f"trecho do link não está na resposta: {l['texto']!r}")
        externo = ' target="_blank" rel="noopener"' if l["url"].startswith("http") else ""
        texto = texto.replace(alvo, f'<a href="{esc(l["url"])}"{externo}>{alvo}</a>', 1)
    return texto


def main() -> int:
    dados = json.loads(DADOS.read_text(encoding="utf-8"))
    home = HOME.read_text(encoding="utf-8")
    a = home.index(MARCA_INI) + len(MARCA_INI)
    b = home.index(MARCA_FIM)

    partes = [f'\n        <p class="eyebrow">Perguntas frequentes</p>\n        <h2>{esc(dados["titulo"])}</h2>\n'
              f'        <p class="lead" style="margin-bottom:28px">{esc(dados["subtitulo"])}</p>\n        <div class="faq-list">']
    entidades = []
    primeira = True
    for g in dados["grupos"]:
        partes.append(f'          <h3 class="faq-grupo">{esc(g["titulo"])}</h3>')
        for q in g["perguntas"]:
            pergunta, resposta = q["pergunta"].strip(), q["resposta"].strip()
            if NACIONAL_SOZINHA.search(pergunta + " " + resposta):
                raise SystemExit(f"nome incompleto da filial em: {pergunta}")
            palavras = len(resposta.split())
            if not 40 <= palavras <= 120:
                print(f"  ! {palavras} palavras em: {pergunta}", file=sys.stderr)
            partes.append(f'          <details class="faq-item"{" open" if primeira else ""}>\n            <summary>{esc(pergunta)}</summary>\n'
                          f'            <div class="faq-answer">{resposta_html(resposta, q.get("links", []))}</div>\n          </details>')
            entidades.append({"@type": "Question", "name": pergunta, "acceptedAnswer": {"@type": "Answer", "text": resposta}})
            primeira = False
    partes.append("        </div>\n      ")
    home = home[:a] + "\n".join(partes) + home[b:]

    ld = {"@context": "https://schema.org", "@type": "FAQPage", "@id": f"{ORIGEM}/#faq", "url": f"{ORIGEM}/#faq",
          "name": dados["titulo"], "inLanguage": "pt-BR", "isPartOf": {"@id": f"{ORIGEM}/#site"}, "mainEntity": entidades}
    ld_json = json.dumps(ld, ensure_ascii=False, indent=2)
    assert "</" not in ld_json
    bloco = f'<script type="application/ld+json" id="faq-ld">\n{ld_json}\n  </script>'
    if 'id="faq-ld"' in home:
        home = re.sub(r'<script type="application/ld\+json" id="faq-ld">.*?</script>', lambda m: bloco, home, count=1, flags=re.S)
    else:
        home = home.replace("</head>", "  " + bloco + "\n</head>", 1)
    HOME.write_text(home, encoding="utf-8")
    print(f"gravado {HOME.relative_to(RAIZ)}: {len(entidades)} perguntas em {len(dados['grupos'])} grupos")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
