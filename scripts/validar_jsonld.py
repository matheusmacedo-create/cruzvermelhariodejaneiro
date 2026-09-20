#!/usr/bin/env python3
"""Confere o JSON-LD das páginas contra o vocabulário do Schema.org.

O Semrush (e o Google) recusam propriedade que não existe no tipo em que ela foi escrita — foi
assim que 56 erros vieram de um `courseMode` posto em `Course`, onde ele é de `CourseInstance`.
Este script pega o vocabulário oficial e reclama de:

  - propriedade que não existe no vocabulário;
  - propriedade que existe, mas cujo domínio não inclui o tipo em que ela aparece;
  - tipo que não existe;
  - `@id` citado e nunca definido na mesma página (referência solta).

Uso:  python3 scripts/validar_jsonld.py [arquivo.html ...]     (sem argumento: todo o site/)
O vocabulário é baixado uma vez para scripts/.cache-schemaorg.jsonld.
"""
from __future__ import annotations

import json
import re
import sys
import urllib.request
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
CACHE = Path(__file__).resolve().parent / ".cache-schemaorg.jsonld"
FONTE = "https://schema.org/version/latest/schemaorg-current-https.jsonld"
# Palavras do próprio JSON-LD, não do vocabulário.
RESERVADAS = {"@context", "@type", "@id", "@graph", "@value", "@language", "@list", "@reverse"}


def vocabulario() -> tuple[dict[str, set[str]], set[str]]:
    """Devolve (domínios de cada propriedade, tipos existentes), já com a herança resolvida."""
    if not CACHE.exists():
        with urllib.request.urlopen(FONTE, timeout=120) as r:
            CACHE.write_bytes(r.read())
    grafo = json.loads(CACHE.read_text(encoding="utf-8"))["@graph"]
    # O arquivo traz vocabulários de terceiros junto (fibo:, unece:, dtype:). Há três "Offer", e só
    # o schema: tem a herança; misturar os três apaga o subClassOf e todo Thing.name vira erro.
    nosso = lambda u: str(u).startswith("schema:")
    curto = lambda u: str(u).split(":", 1)[-1]
    lista = lambda v: [] if v is None else (v if isinstance(v, list) else [v])

    tipos, pai = set(), {}
    for n in grafo:
        if "rdfs:Class" not in lista(n.get("@type")) or not nosso(n["@id"]):
            continue
        t = curto(n["@id"])
        tipos.add(t)
        pai[t] = [curto(x["@id"]) for x in lista(n.get("rdfs:subClassOf"))
                  if isinstance(x, dict) and nosso(x["@id"])]

    def ancestrais(t: str, visto=None) -> set[str]:
        visto = visto or set()
        if t in visto:
            return set()
        visto.add(t)
        saida = {t}
        for p in pai.get(t, []):
            saida |= ancestrais(p, visto)
        return saida

    dominios: dict[str, set[str]] = {}
    for n in grafo:
        if "rdf:Property" not in lista(n.get("@type")) or not nosso(n["@id"]):
            continue
        p = curto(n["@id"])
        # A propriedade vale no tipo declarado e em todos os que descendem dele.
        declarados = {curto(x["@id"]) for x in lista(n.get("schema:domainIncludes"))
                      if isinstance(x, dict) and nosso(x["@id"])}
        aceita = set()
        for t in tipos:
            if ancestrais(t) & declarados:
                aceita.add(t)
        dominios[p] = aceita
    return dominios, tipos


def blocos(html: str) -> list:
    saida = []
    for b in re.findall(r'<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>', html, re.S):
        try:
            saida.append(json.loads(b))
        except json.JSONDecodeError as e:
            saida.append(e)
    return saida


def conferir(no, dominios, tipos, caminho, erros, definidos, citados):
    if isinstance(no, list):
        for i, x in enumerate(no):
            conferir(x, dominios, tipos, f"{caminho}[{i}]", erros, definidos, citados)
        return
    if not isinstance(no, dict):
        return
    t = no.get("@type")
    t = t[0] if isinstance(t, list) and t else t
    if isinstance(t, str) and t not in tipos:
        erros.append(f"{caminho}: tipo '{t}' não existe no Schema.org")
    if "@id" in no:
        (definidos if t else citados).add(no["@id"])
    for k, v in no.items():
        if k in RESERVADAS:
            continue
        onde = f"{caminho}.{k}"
        if k not in dominios:
            erros.append(f"{onde}: propriedade '{k}' não existe no Schema.org")
        elif isinstance(t, str) and t in tipos and t not in dominios[k]:
            erros.append(f"{onde}: '{k}' não vale em '{t}' (vale em: {', '.join(sorted(dominios[k])[:4])}…)")
        conferir(v, dominios, tipos, onde, erros, definidos, citados)


def main(argv: list[str]) -> int:
    dominios, tipos = vocabulario()
    arquivos = [Path(a) for a in argv] or sorted(
        f for f in (RAIZ / "site").rglob("*.html") if "assets" not in f.parts)
    total = 0
    for f in arquivos:
        html = f.read_text(encoding="utf-8")
        erros: list[str] = []
        definidos: set[str] = set()
        citados: set[str] = set()
        for i, b in enumerate(blocos(html)):
            if isinstance(b, json.JSONDecodeError):
                erros.append(f"bloco[{i}]: JSON inválido: {b}")
            else:
                conferir(b, dominios, tipos, f"bloco[{i}]", erros, definidos, citados)
        # @id apontando para um nó definido em outra página do site é prática corrente de JSON-LD
        # (a home define #organizacao e #site); fica como aviso, não como erro.
        avisos = [f"@id '{s}' é citado aqui e definido em outra página" for s in sorted(citados - definidos)]
        rel = f.relative_to(RAIZ)
        if erros:
            total += len(erros)
            print(f"✗ {rel} — {len(erros)} erro(s)")
            for e in erros:
                print(f"    {e}")
        else:
            print(f"✓ {rel}" + (f"  ({len(avisos)} aviso)" if avisos else ""))
        for a in avisos if erros else []:
            print(f"    aviso: {a}")
    print(f"\n{total} erro(s) em {len(arquivos)} arquivo(s)")
    return 1 if total else 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
