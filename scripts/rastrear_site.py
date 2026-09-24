#!/usr/bin/env python3
"""Rastreia o site como um robô de busca e mede o que a auditoria mede.

Serve para diagnosticar as pontuações de Rastreabilidade e Links internos sem depender de esperar
a próxima auditoria. Percorre a partir da home, só dentro do domínio, e reporta por página:
status, profundidade, noindex, canonical, links internos de entrada e de saída.

No fim, lista as ocorrências que a auditoria conta:
  - 4xx/5xx, redirecionamento em link interno, cadeia de redirecionamento
  - página bloqueada (noindex), órfã (no sitemap e sem link), com um único link de entrada
  - link sem texto âncora, âncora pouco descritiva, nofollow interno
  - título longo (>60), pouco texto, proporção texto/HTML baixa (<10%)
  - link para página escondida (/verificar/) ou página escondida no sitemap (os dois têm de ser 0)

Uso:  python3 scripts/rastrear_site.py [--max N]
"""
from __future__ import annotations

import html as H
import json
import re
import subprocess
import sys
from collections import defaultdict, deque
from urllib.parse import urljoin, urlparse, urlunparse

ORIGEM = "https://cruzvermelhariodejaneiro.org"
SITEMAPS = [f"{ORIGEM}/sitemap-index.xml", f"{ORIGEM}/sitemap.xml"]
ANCORA_FRACA = {"clique aqui", "aqui", "leia mais", "saiba mais", "veja mais", "mais", "link", "ver"}
IGNORAR = ("mailto:", "tel:", "javascript:", "data:")
BINARIO = re.compile(r"\.(png|jpe?g|webp|svg|gif|ico|css|js|xml|txt|pdf|woff2?|mp4)(\?|$)", re.I)
# Páginas publicadas escondidas (noindex e sem link de nenhuma página; ver o README, "Verificação de
# documentos em /verificar/"). O rastreio não entra nelas, e link de qualquer página para elas é problema.
ESCONDIDAS = ("/verificar",)


def escondida(url: str) -> bool:
    caminho = urlparse(url).path
    return any(caminho == e or caminho.startswith(e + "/") for e in ESCONDIDAS)


def buscar(url: str) -> tuple[str, str, str]:
    """Devolve (status, destino do redirecionamento, corpo). Não segue redirecionamento.

    O ambiente sai por um proxy, e o primeiro bloco de cabeçalho é o aperto de mão dele
    ("HTTP/1.1 200 Connection Established"). Ler esse bloco faz todo 301 parecer 200, então o
    bloco do proxy é descartado e vale o último cabeçalho antes do corpo.
    """
    bruto = subprocess.run(
        ["curl", "-s", "-D", "-", "--max-time", "30", url],
        capture_output=True, text=True, errors="replace").stdout
    resto = bruto.replace("\r\n", "\n")
    while True:
        cabecalho, sep, corpo = resto.partition("\n\n")
        if not sep:
            cabecalho, corpo = resto, ""
            break
        if "Connection Established" in cabecalho.splitlines()[0]:
            resto = corpo          # era o proxy; a resposta de verdade vem depois
            continue
        break
    status = re.search(r"HTTP/[\d.]+ (\d{3})", cabecalho)
    local = re.search(r"(?im)^location:\s*(\S+)", cabecalho)
    return (status.group(1) if status else "000"), (local.group(1) if local else ""), corpo


def limpar(url: str) -> str:
    p = urlparse(url)
    return urlunparse(p._replace(fragment=""))


def texto_visivel(corpo: str) -> str:
    t = re.sub(r"<(script|style|noscript|template)[^>]*>.*?</\1>", " ", corpo, flags=re.S)
    t = re.sub(r"<[^>]+>", " ", t)
    t = H.unescape(t)
    return " ".join(t.split())


def links_de(corpo: str, base: str) -> list[tuple[str, str, bool]]:
    """(url absoluta, texto âncora, tem nofollow) de cada <a href> interno."""
    saida = []
    for tag in re.findall(r"<a\b[^>]*>.*?</a>", corpo, re.S | re.I):
        m = re.search(r'href="([^"]*)"', tag)
        if not m:
            continue
        alvo = H.unescape(m.group(1))
        if not alvo or alvo.startswith(IGNORAR) or alvo.startswith("#"):
            continue
        url = limpar(urljoin(base, alvo))
        if urlparse(url).netloc != urlparse(ORIGEM).netloc:
            continue
        ancora = texto_visivel(tag)
        if not ancora:                                    # link de imagem: o alt é a âncora
            ancora = " ".join(H.unescape(a) for a in re.findall(r'\balt="([^"]*)"', tag)).strip()
        saida.append((url, ancora, "nofollow" in (re.search(r'rel="([^"]*)"', tag) or [""," "])[1 if re.search(r'rel="([^"]*)"', tag) else 0]))
    return saida


def do_sitemap() -> set[str]:
    urls: set[str] = set()
    fila = list(SITEMAPS)
    visto = set()
    while fila:
        s = fila.pop()
        if s in visto:
            continue
        visto.add(s)
        _, _, corpo = buscar(s)
        achados = [H.unescape(u) for u in re.findall(r"<loc>([^<]+)</loc>", corpo)]
        for u in achados:
            (fila if u.endswith(".xml") else urls).append(u) if u.endswith(".xml") else urls.add(u)
    return {u for u in urls if urlparse(u).netloc == urlparse(ORIGEM).netloc}


def main(argv: list[str]) -> int:
    limite = int(argv[argv.index("--max") + 1]) if "--max" in argv else 120
    fila = deque([(ORIGEM + "/", 0)])
    paginas: dict[str, dict] = {}
    entrada: dict[str, set[str]] = defaultdict(set)
    redirecionam: list[tuple[str, str, str]] = []
    ancora_ruim: list[tuple[str, str, str]] = []
    nofollow: list[tuple[str, str]] = []
    para_escondidas: list[tuple[str, str]] = []

    while fila and len(paginas) < limite:
        url, prof = fila.popleft()
        if url in paginas:
            continue
        status, destino, corpo = buscar(url)
        titulo = (re.search(r"<title[^>]*>(.*?)</title>", corpo, re.S | re.I) or [None, ""])[1]
        titulo = H.unescape(" ".join(titulo.split()))
        texto = texto_visivel(corpo)
        canon = (re.search(r'<link[^>]+rel="canonical"[^>]+href="([^"]+)"', corpo) or [None, ""])[1]
        robots = (re.search(r'<meta[^>]+name="robots"[^>]+content="([^"]+)"', corpo, re.I) or [None, ""])[1]
        paginas[url] = {
            "status": status, "destino": destino, "prof": prof, "titulo": titulo,
            "palavras": len(re.findall(r"[A-Za-zÀ-ÿ]{2,}", texto)),
            "html": len(corpo), "texto": len(texto),
            "razao": round(100 * len(texto) / max(len(corpo), 1), 1),
            "canonical": H.unescape(canon), "robots": robots.lower(), "saida": 0,
        }
        if status != "200" or BINARIO.search(url):
            continue
        vistos_aqui = set()
        for alvo, ancora, nf in links_de(corpo, url):
            if BINARIO.search(alvo):
                continue
            if escondida(alvo):
                para_escondidas.append((url, alvo))
                continue
            paginas[url]["saida"] += 1
            entrada[alvo].add(url)
            if nf:
                nofollow.append((url, alvo))
            a = ancora.strip().lower()
            if not a:
                ancora_ruim.append((url, alvo, "(sem texto)"))
            elif a in ANCORA_FRACA:
                ancora_ruim.append((url, alvo, ancora.strip()))
            if alvo not in paginas and alvo not in vistos_aqui:
                vistos_aqui.add(alvo)
                fila.append((alvo, prof + 1))

    # segunda passada: quem redireciona
    for url, d in paginas.items():
        if d["status"].startswith("3"):
            redirecionam.append((url, d["destino"], ", ".join(sorted(entrada.get(url, ()))[:3])))

    mapa = do_sitemap()
    encontradas = {u for u, d in paginas.items() if d["status"] == "200"}

    print(f"=== {len(paginas)} URLs visitadas a partir da home ===\n")
    print(f"{'URL':58} {'st':>3} {'prof':>4} {'ent':>4} {'sai':>4} {'pal':>5} {'t/h':>5}  obs")
    for u, d in sorted(paginas.items()):
        obs = []
        if "noindex" in d["robots"]:
            obs.append("noindex")
        if d["canonical"] and limpar(d["canonical"]) != u and d["status"] == "200":
            obs.append("canonical→outra")
        if d["status"] == "200" and len(d["titulo"]) > 60:
            obs.append(f"título {len(d['titulo'])}")
        if d["status"] == "200" and d["palavras"] < 200:
            obs.append(f"pouco texto")
        if d["status"] == "200" and d["razao"] < 10:
            obs.append(f"t/h baixo")
        curta = u.replace(ORIGEM, "") or "/"
        print(f"{curta[:58]:58} {d['status']:>3} {d['prof']:>4} {len(entrada.get(u,())):>4} {d['saida']:>4} "
              f"{d['palavras']:>5} {d['razao']:>5}  {', '.join(obs)}")

    def secao(titulo, itens, formatar=lambda x: str(x)):
        print(f"\n== {titulo}: {len(itens)}")
        for i in itens[:20]:
            print("   " + formatar(i))
        if len(itens) > 20:
            print(f"   … mais {len(itens)-20}")

    secao("links internos que redirecionam", redirecionam,
          lambda x: f"{x[0].replace(ORIGEM,'')} → {x[1].replace(ORIGEM,'')}  (de: {x[2].replace(ORIGEM,'')})")
    secao("4xx/5xx", [(u, d["status"]) for u, d in paginas.items() if d["status"][0] in "45"],
          lambda x: f"{x[1]}  {x[0].replace(ORIGEM,'')}")
    secao("bloqueadas (noindex)", [u for u, d in paginas.items() if "noindex" in d["robots"]],
          lambda u: u.replace(ORIGEM, ""))
    um_link = [u for u in encontradas if len(entrada.get(u, ())) == 1 and u != ORIGEM + "/"]
    secao("com um único link interno de entrada", sorted(um_link), lambda u: u.replace(ORIGEM, ""))
    orfas = sorted(u for u in mapa if u not in paginas)
    secao("no sitemap e não alcançadas por link (órfãs)", orfas, lambda u: u.replace(ORIGEM, ""))
    fora = sorted(u for u in encontradas if u not in mapa and "?" not in u)
    secao("alcançadas por link e fora do sitemap", fora, lambda u: u.replace(ORIGEM, ""))
    secao("links sem texto âncora ou com âncora fraca", ancora_ruim,
          lambda x: f"{x[2]!r} em {x[0].replace(ORIGEM,'')} → {x[1].replace(ORIGEM,'')}")
    secao("nofollow em link interno", nofollow, lambda x: f"{x[0].replace(ORIGEM,'')} → {x[1].replace(ORIGEM,'')}")
    secao("links para página escondida (tem de ser 0)", para_escondidas,
          lambda x: f"{x[0].replace(ORIGEM,'')} → {x[1].replace(ORIGEM,'')}")
    na_lista = sorted(u for u in mapa if escondida(u))
    secao("página escondida no sitemap (tem de ser 0)", na_lista, lambda u: u.replace(ORIGEM, ""))
    prof = [(u, d["prof"]) for u, d in paginas.items() if d["prof"] >= 4 and d["status"] == "200"]
    secao("profundidade 4 ou mais", prof, lambda x: f"{x[1]}  {x[0].replace(ORIGEM,'')}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
