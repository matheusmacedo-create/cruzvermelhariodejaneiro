#!/usr/bin/env python3
"""Confere todos os links e recursos internos das páginas do site, ao vivo.

Link interno que responde 301 não quebra nada para o visitante, mas gasta um salto em toda
visita, dilui o sinal de link e aparece na auditoria como "Permanent redirects" — foi assim que
um `/privacidade` sem barra no rodapé virou 32 ocorrências. Aqui, redirecionamento é falha:
o link deve apontar para o destino final.

Uso:  python3 scripts/conferir_links.py            confere o que está publicado
      python3 scripts/conferir_links.py --local    confere contra um servidor em 127.0.0.1:8767
"""
from __future__ import annotations

import html as H
import posixpath
import re
import subprocess
import sys
from collections import defaultdict
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
SITE = RAIZ / "site"
ORIGEM = "https://cruzvermelhariodejaneiro.org"
LOCAL = "http://127.0.0.1:8767"
IGNORAR = ("mailto:", "tel:", "javascript:", "data:", "#")
# O checkout responde 200 mas é noindex de propósito; as âncoras são conferidas à parte.
EXTERNOS_NOSSOS = re.compile(r"^https://(?:[a-z-]+\.)?cruzvermelhariodejaneiro\.org")
# Páginas que o .htaccess redireciona antes de servir: o arquivo fica no repositório como histórico,
# mas ninguém chega nele, e os links de dentro não valem nada.
NAO_SERVIDAS = {"doacao.html", "cursos.html", "doar/index.html"}
# Pastas publicadas escondidas (noindex e sem link de nenhuma página; ver o README, "Verificação de
# documentos em /verificar/"). Ficam fora da conferência, que é das páginas públicas: os arquivos de prova
# que /verificar/ aponta são gravados pela Redação por FTP e podem ainda não existir. E o contrário vale
# como falha: página pública com link para uma delas desfaz o lançamento escondido.
ESCONDIDAS = ("verificar",)


def paginas() -> list[Path]:
    return sorted(f for f in SITE.rglob("*.html")
                  if "assets" not in f.parts and str(f.relative_to(SITE)) not in NAO_SERVIDAS
                  and f.relative_to(SITE).parts[0] not in ESCONDIDAS)


def aponta_escondida(alvo: str, pagina: str) -> bool:
    """O link (já sem âncora) leva a uma pasta escondida? Vale caminho absoluto, relativo e URL completa."""
    caminho = absoluta(re.sub(r"^https?://(?:www\.)?cruzvermelhariodejaneiro\.org", "", alvo), pagina, "")
    caminho = posixpath.normpath(caminho.split("?")[0])
    return any(caminho == f"/{e}" or caminho.startswith(f"/{e}/") for e in ESCONDIDAS)


def coletar() -> tuple[dict[str, set[str]], list[tuple[str, str]]]:
    """URL interna -> páginas que a usam (href e src, já sem a âncora), e os links para pastas escondidas."""
    achados: dict[str, set[str]] = defaultdict(set)
    vazamentos: list[tuple[str, str]] = []
    for f in paginas():
        texto = f.read_text(encoding="utf-8")
        pagina = str(f.relative_to(SITE))
        for bruto in re.findall(r'(?:href|src)="([^"]*)"', texto):
            alvo = H.unescape(bruto).split("#")[0]
            if not alvo or alvo.startswith(IGNORAR):
                continue
            if alvo.startswith("http") and not EXTERNOS_NOSSOS.match(alvo):
                continue
            if aponta_escondida(alvo, pagina):
                vazamentos.append((alvo, pagina))
                continue
            achados[alvo].add(pagina)
    return achados, vazamentos


def absoluta(alvo: str, pagina: str, base: str) -> str:
    if alvo.startswith("http"):
        return alvo.replace(ORIGEM, base) if base != ORIGEM else alvo
    if alvo.startswith("/"):
        return base + alvo
    pasta = "/".join(("/" + pagina).split("/")[:-1])
    return f"{base}{pasta}/{alvo}"


def estado(url: str) -> tuple[str, str]:
    saida = subprocess.run(
        ["curl", "-s", "-o", "/dev/null", "-w", "%{http_code} %{redirect_url}", "--max-time", "25", url],
        capture_output=True, text=True).stdout.split(" ", 1)
    return saida[0], (saida[1].strip() if len(saida) > 1 else "")


def main(argv: list[str]) -> int:
    base = LOCAL if "--local" in argv else ORIGEM
    achados, vazamentos = coletar()
    testadas: dict[str, tuple[str, str]] = {}
    falhas = len(vazamentos)
    for alvo, pagina in vazamentos:
        print(f"✗ {alvo}  (link para página escondida)\n    em: {pagina}")
    for alvo, usos in sorted(achados.items()):
        url = absoluta(alvo, sorted(usos)[0], base)
        if url not in testadas:
            testadas[url] = estado(url)
        codigo, destino = testadas[url]
        if codigo == "200":
            continue
        falhas += 1
        rotulo = "redireciona" if codigo.startswith("3") else "não responde 200"
        print(f"✗ {alvo}  ({codigo}, {rotulo})")
        if destino:
            print(f"    destino: {destino}")
        print(f"    em: {', '.join(sorted(usos))}")
    print(f"\n{len(testadas)} URLs internas em {len(paginas())} páginas · "
          + ("todas respondem 200" if not falhas else f"{falhas} com problema"))
    return 1 if falhas else 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
