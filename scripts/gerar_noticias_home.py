#!/usr/bin/env python3
"""Escreve as últimas notícias na home (site/index.html), entre <!-- noticias:inicio --> e <!-- noticias:fim -->.

As notícias são da Redação: ela publica cada matéria e regera o índice /noticias/ a cada publicação
(lib/site/indice-noticias.ts). Este script lê esse índice, o mesmo que o público vê, e põe na home as
QUANTAS mais recentes, com título, linha fina e data visível. Não inventa nada: sem índice legível, recusa.

Ficam fora da home as matérias de venda de curso da escola (lista FORA_DA_HOME, achado C7 da auditoria do
Ad Grants, 19/10/2026): a home é institucional. Elas continuam em /noticias/. A lista vai também no atributo
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
QUANTAS = 3
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
# Uma matéria do índice: o link (manchete ou item da grade), o título, a linha fina opcional e a data.
ITEM = re.compile(r'<a (?:class="manchete[^"]*" )?href="(?P<url>[^"]+)">\s*(?:<div class="texto">|<figure>.*?</figure>)?.*?'
                  r'<h[23]>(?P<titulo>.*?)</h[23]>\s*(?:<p>(?P<descricao>.*?)</p>)?\s*'
                  r'<time datetime="(?P<quando>[^"]+)">', re.S)


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


def noticias(indice: str) -> list[dict]:
    itens = []
    for m in ITEM.finditer(indice):
        url = html.unescape(m["url"])
        if not url.startswith(f"{ORIGEM}/noticias/") or slug(url) in FORA_DA_HOME:
            continue
        quando = datetime.fromisoformat(m["quando"].replace("Z", "+00:00"))
        itens.append({"url": url.replace(ORIGEM, ""), "titulo": html.unescape(m["titulo"]).strip(),
                      "descricao": html.unescape(m["descricao"] or "").strip(), "quando": quando})
    itens.sort(key=lambda n: n["quando"], reverse=True)
    return itens


def data_legivel(d: datetime) -> str:
    # O índice da Redação grava em UTC e mostra a data de São Paulo (UTC-3, sem horário de verão desde 2019).
    local = d.astimezone(SAO_PAULO)
    return f"{local.day} de {MESES[local.month - 1]} de {local.year}"


def bloco(itens: list[dict]) -> str:
    cartoes = []
    for n in itens[:QUANTAS]:
        descricao = f'\n              <p>{esc(n["descricao"])}</p>' if n["descricao"] else ""
        cartoes.append(f'''          <a class="campaign-card" href="{esc(n["url"])}">
            <div class="campaign-card-body">
              <p class="eyebrow" style="font-size:.7rem"><time datetime="{n["quando"].isoformat().replace("+00:00", "Z")}">{data_legivel(n["quando"])}</time></p>
              <h3>{esc(n["titulo"])}</h3>{descricao}
              <span class="btn btn-outline">Ler a notícia</span>
            </div>
          </a>''')
    fora = " ".join(sorted(FORA_DA_HOME))
    return (f'\n        <div class="campaigns-grid" data-noticias-home data-quantas="{QUANTAS}" data-fora="{esc(fora)}">\n'
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
