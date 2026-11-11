#!/usr/bin/env python3
"""Escreve as últimas notícias na home (site/index.html), entre <!-- noticias:inicio --> e <!-- noticias:fim -->.

As notícias são da Redação: ela publica cada matéria e regera o índice /noticias/ a cada publicação
(lib/site/indice-noticias.ts). Este script lê esse índice, o mesmo que o público vê, e põe na home as
QUANTAS mais recentes, com foto, assunto, título e data visível (a linha fina só na principal). Não inventa nada: sem índice legível, recusa.

Ficam fora da home as matérias de venda de curso da escola (lista FORA_DA_HOME, achado C7 da auditoria do
Ad Grants, 19/10/2026): a home é institucional. Elas continuam em /noticias/. Pelo mesmo motivo ficam fora o caderno "Cursos e formação"
e as matérias marcadas com esse assunto. A lista vai também no atributo
data-fora do bloco, para o script da home, que refaz a lista no navegador a partir do mesmo índice (assim a
home acompanha a Redação sem esperar a próxima publicação de site/index.html).

Uso:  python3 scripts/gerar_noticias_home.py                 (lê https://cruzvermelhariodejaneiro.org/noticias/)
      python3 scripts/gerar_noticias_home.py caminho.html    (lê um índice salvo)
Depois: publicar site/index.html. Na ordem de geração do README, roda junto com gerar_faq_home.py.
"""
from __future__ import annotations

import html
import re
import sys
import urllib.request
from datetime import datetime, timedelta, timezone
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
HOME = RAIZ / "site" / "index.html"
INDICE = "https://cruzvermelhariodejaneiro.org/noticias/"
ORIGEM = "https://cruzvermelhariodejaneiro.org"
MARCA_INI = "<!-- noticias:inicio -->"
MARCA_FIM = "<!-- noticias:fim -->"
QUANTAS = 6  # 11/11/2026: eram 3; a principal com foto grande e mais cinco
SAO_PAULO = timezone(timedelta(hours=-3))
MESES = ["janeiro", "fevereiro", "março", "abril", "maio", "junho", "julho", "agosto", "setembro", "outubro",
         "novembro", "dezembro"]
# Matérias que vendem curso da escola (C7). Pelo slug, que não muda quando o título é revisto.
FORA_DA_HOME = {
    "homologacao-de-bombeiro-civil-no-cbmerj-como-funciona",
    "curso-de-socorrista-o-que-e-e-o-que-um-curso-livre-ensina",
    "conhecimento-tambem-salva-vidas-cruz-vermelha-brasileira-rio-de-janeiro-leva",
    "curso-de-primeiros-socorros-lei-lucas-o-que-a-lei-exige-das-escolas",
    "curso-de-suporte-basico-de-vida-o-que-e-e-quem-deveria-fazer",
    "curso-de-puncao-venosa-para-quem-e-e-o-que-aprofunda",
    "curso-de-primeiros-socorros-basico-para-o-dia-a-dia-em-casa",
    "curso-de-micropigmentacao-labial-o-que-e-e-como-se-especializar",
    "curso-de-cuidador-de-idosos-o-que-faz-e-como-se-capacitar",
    "curso-de-bombeiro-civil-o-que-faz-e-como-se-formar",
    "curso-de-primeiros-socorros-domine-com-poucas-aulas",
}
# O índice da Redação (layout "jornal", 11/2026): manchete, secundárias, um destaque e uma lista por caderno. A mesma
# matéria pode aparecer em mais de um lugar, às vezes com foto e às vezes sem: os dados de cada aparição se somam.
LINK = re.compile(r'<a (?:class="(?:manchete|destaque)[^"]*" )?href="(?P<url>[^"]+)">(?P<miolo>.*?)</a>', re.S)
CADERNO = re.compile(r'<section class="caderno" id="(?P<id>[^"]+)"(?P<corpo>.*?)</section>', re.S)
# Caderno e assunto que ficam fora da home pelo mesmo motivo do C7: vendem curso da escola.
CADERNO_FORA = "cursos"
ASSUNTO_FORA = "Cursos e formação"
def esc(s: str) -> str:
    return html.escape(s, quote=True)


def slug(url: str) -> str:
    return url.rstrip("/").rsplit("/", 1)[-1]


def ler_indice(fonte: str | None) -> str:
    if fonte:
        return Path(fonte).read_text(encoding="utf-8")
    pedido = urllib.request.Request(INDICE, headers={"User-Agent": "Mozilla/5.0 (gerar_noticias_home.py)"})
    with urllib.request.urlopen(pedido, timeout=30) as r:
        return r.read().decode("utf-8")


def _texto(padrao: str, miolo: str) -> str:
    m = re.search(padrao, miolo, re.S)
    return html.unescape(re.sub(r"<[^>]+>", "", m[1])).strip() if m else ""


def noticias(indice: str) -> list[dict]:
    fora = set(FORA_DA_HOME)
    for c in CADERNO.finditer(indice):
        if c["id"] == CADERNO_FORA:
            fora |= {slug(html.unescape(m["url"])) for m in LINK.finditer(c["corpo"])}
    por_url: dict[str, dict] = {}
    for m in LINK.finditer(indice):
        url, miolo = html.unescape(m["url"]), m["miolo"]
        quando = re.search(r'<time datetime="([^"]+)"', miolo)
        titulo = _texto(r"<h[23]>(.*?)</h[23]>", miolo)
        if not url.startswith(f"{ORIGEM}/noticias/") or not quando or not titulo:
            continue
        webp = re.search(r'<source type="image/webp" srcset="([^"]+)"', miolo)
        img = re.search(r'<img src="([^"]+)" width="(\d+)" height="(\d+)"', miolo)
        n = por_url.setdefault(url, {"url": url.replace(ORIGEM, ""), "slug": slug(url), "titulo": titulo,
                                     "quando": datetime.fromisoformat(quando[1].replace("Z", "+00:00")),
                                     "assunto": "", "descricao": "", "srcset": "", "img": None})
        n["assunto"] = n["assunto"] or _texto(r'<span class="kicker">(.*?)</span>', miolo)
        n["descricao"] = n["descricao"] or _texto(r"<p[^>]*>(.*?)</p>", miolo)
        if img and not n["img"]:
            n["img"], n["largura"], n["altura"] = html.unescape(img[1]), img[2], img[3]
            n["srcset"] = html.unescape(webp[1]) if webp else ""
    itens = [n for n in por_url.values() if n["slug"] not in fora and n["assunto"] != ASSUNTO_FORA]
    itens.sort(key=lambda n: n["quando"], reverse=True)
    return itens


def data_legivel(d: datetime) -> str:
    # O índice da Redação grava em UTC e mostra a data de São Paulo (UTC-3, sem horário de verão desde 2019).
    local = d.astimezone(SAO_PAULO)
    return f"{local.day} de {MESES[local.month - 1]} de {local.year}"


def _foto(n: dict, sizes: str) -> str:
    if not n["img"]:
        return '<div class="campaign-card-thumb hp-noticia-semfoto" aria-hidden="true"></div>'
    # Só a versão webp de 480 e 960 px: a de 1600 é para a página da matéria, não para um cartão.
    srcset = ", ".join(x.strip() for x in n["srcset"].split(",") if not x.strip().endswith(" 1600w"))
    src = srcset.split(" ", 1)[0] if srcset else n["img"]
    extra = f' srcset="{esc(srcset)}" sizes="{sizes}"' if srcset else ""
    return (f'<div class="campaign-card-thumb"><img alt="" src="{esc(src)}"{extra} width="{n["largura"]}" '
            f'height="{n["altura"]}" loading="lazy" decoding="async"></div>')


def bloco(itens: list[dict]) -> str:
    cartoes = []
    for i, n in enumerate(itens[:QUANTAS]):
        principal = i == 0
        assunto = f'{esc(n["assunto"])} · ' if n["assunto"] else ""
        descricao = f'\n              <p>{esc(n["descricao"])}</p>' if principal and n["descricao"] else ""
        botao = '\n              <span class="btn btn-outline">Ler a notícia</span>' if principal else ""
        sizes = "(max-width: 920px) 100vw, 740px" if principal else "(max-width: 920px) 120px, 360px"
        cartoes.append(f'''          <a class="campaign-card{" hp-noticia-principal" if principal else ""}" href="{esc(n["url"])}">
            {_foto(n, sizes)}
            <div class="campaign-card-body">
              <p class="eyebrow">{assunto}<time datetime="{n["quando"].isoformat().replace("+00:00", "Z")}">{data_legivel(n["quando"])}</time></p>
              <h3>{esc(n["titulo"])}</h3>{descricao}{botao}
            </div>
          </a>''')
    fora = " ".join(sorted(FORA_DA_HOME))
    return (f'\n        <div class="campaigns-grid hp-noticias-grade" data-noticias-home data-quantas="{QUANTAS}" '
            f'data-fora="{esc(fora)}" data-caderno-fora="{CADERNO_FORA}" data-assunto-fora="{esc(ASSUNTO_FORA)}">\n'
            + "\n".join(cartoes) + "\n        </div>\n        ")


def main() -> int:
    itens = noticias(ler_indice(sys.argv[1] if len(sys.argv) > 1 else None))
    if not itens:
        raise SystemExit("nenhuma notícia lida do índice /noticias/: a home não foi alterada")
    home = HOME.read_text(encoding="utf-8")
    if home.count(MARCA_INI) != 1 or home.count(MARCA_FIM) != 1:
        raise SystemExit("a home não tem os marcadores <!-- noticias:inicio --> e <!-- noticias:fim -->")
    a = home.index(MARCA_INI) + len(MARCA_INI)
    b = home.index(MARCA_FIM)
    nova = home[:a] + bloco(itens) + home[b:]
    if nova != home:
        HOME.write_text(nova, encoding="utf-8")
    print(f"gravado {HOME.relative_to(RAIZ)}: {min(QUANTAS, len(itens))} notícias na home "
          f"(a mais recente de {data_legivel(itens[0]['quando'])}; {len(FORA_DA_HOME)} slugs de curso ficam fora)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
