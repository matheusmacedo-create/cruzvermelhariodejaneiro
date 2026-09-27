#!/usr/bin/env python3
"""Separa, nos dados do Google Analytics 4, as visitas de gente das de robôs, por país.

O GA4 já descarta sozinho os robôs que se declaram (a lista da IAB), mas não os navegadores
automatizados que rodam o JavaScript da página a partir de data centers: raspadores, ferramentas
de SEO, testes de velocidade, robôs de IA e as ondas de tráfego automatizado que o mundo inteiro
viu vindas da China e de Singapura. Eles chegam como "visitas" de outros países, quase sempre com
tempo de engajamento zero, uma página só, entrada direta e cidades de data center.

Este script lê o GA4 pela Data API, com uma conta de serviço só de leitura, e classifica cada
combinação de país, cidade, navegador, sistema, resolução, idioma, origem e página de entrada:

  robô            sem engajamento nenhum e com marca de automação (cidade de data center,
                  resolução típica de navegador automatizado, entrada "(not set)", Linux de
                  servidor fora do Brasil)
  provável robô   sem engajamento e com marca fraca, ou cidade de data center com engajamento baixo
  pessoa          sessões engajadas (10 s ou mais em primeiro plano, 2 páginas ou mais)
  indefinido      o resto: gente que saiu na primeira página e robô discreto têm a mesma cara

Por país, mostra a faixa de pessoas reais: o mínimo (só sessões engajadas, fora de data center) e
o máximo (tudo que não parece robô). Separa o antes e o depois do aviso de cookies (27/09/2026):
desde então o GA4 só carrega depois de alguém clicar em "Aceitar", e robô não clica.

Credenciais, nunca no repositório:
  GA4_CHAVE_JSON   caminho da chave JSON da conta de serviço (Leitor na propriedade do GA4)
  GA4_PROPRIEDADE  ID numérico da propriedade (Administrador → Detalhes da propriedade); sem ele,
                   o script lista as propriedades que a conta de serviço enxerga

Uso:  python3 scripts/analisar_visitantes_ga4.py [--desde AAAA-MM-DD] [--ate AAAA-MM-DD] [--csv arquivo]
      python3 scripts/analisar_visitantes_ga4.py --teste     (confere a classificação, sem rede)
"""
from __future__ import annotations

import argparse
import base64
import csv
import json
import os
import sys
import time
from collections import defaultdict
from dataclasses import dataclass, field

import requests

ESCOPO = "https://www.googleapis.com/auth/analytics.readonly"
DATA_API = "https://analyticsdata.googleapis.com/v1beta"
ADMIN_API = "https://analyticsadmin.googleapis.com/v1beta"
AVISO_DE_COOKIES = "2026-09-27"  # a partir deste dia o GA4 só carrega com consentimento
TIMEOUT = 60

# Cidades que o GA4 atribui a data centers (nomes como o GA4 os escreve). Quase ninguém mora nelas.
DATA_CENTERS = {
    "Ashburn", "Sterling", "Boardman", "Council Bluffs", "The Dalles", "Moncks Corner", "Lenoir",
    "Quincy", "Prineville", "Forest City", "Papillion", "Mayes County", "Pryor", "Midlothian",
    "Lanzhou",  # a onda de tráfego automatizado vinda da China
}
# Cidades grandes que também abrigam data centers muito usados por robôs: pesam como marca fraca.
DATA_CENTERS_FRACOS = {
    "Singapore", "Frankfurt", "Dublin", "Amsterdam", "Santa Clara", "San Jose", "Des Moines",
    "Columbus", "Hillsboro", "Portland", "Seattle", "Los Angeles", "Hong Kong", "Tokyo", "Beijing",
    "Shanghai", "Shenzhen", "Hangzhou", "Guangzhou", "Chicago", "Dallas", "Montreal", "Toronto",
}
# Resoluções típicas de navegador automatizado (headless) ou sem valor.
RESOLUCOES_DE_ROBO = {"800x600", "1024x768", "1280x1200", "1x1", "0x0", "(not set)"}
BRASIL = "Brazil"


@dataclass
class Linha:
    periodo: str
    pais: str
    cidade: str
    navegador: str
    sistema: str
    resolucao: str
    idioma: str
    origem: str
    entrada: str
    sessoes: int
    engajadas: int
    engajamento_s: float
    paginas: int
    classe: str = ""
    motivos: list = field(default_factory=list)


# ----------------------------------------------------------------------------- classificação
def classificar(l: Linha) -> None:
    """Põe em l.classe um de: robô, provável robô, pessoa, indefinido (com os motivos)."""
    s = max(l.sessoes, 1)
    taxa = l.engajadas / s
    media = l.engajamento_s / s
    paginas = l.paginas / s
    sem_engajamento = l.engajadas == 0 and media < 1.0
    fortes, fracos = [], []
    if l.cidade in DATA_CENTERS:
        fortes.append(f"cidade de data center ({l.cidade})")
    elif l.cidade in DATA_CENTERS_FRACOS and l.pais != BRASIL:
        fracos.append(f"cidade com muitos data centers ({l.cidade})")
    if l.resolucao in RESOLUCOES_DE_ROBO:
        fortes.append(f"resolução de navegador automatizado ({l.resolucao})")
    if l.entrada in ("(not set)", "") and l.origem.startswith("(direct)"):
        fracos.append("entrada direta sem página de entrada")
    if l.sistema == "Linux" and l.pais != BRASIL:
        fracos.append("Linux de computador fora do Brasil")
    if l.cidade == "(not set)":
        fracos.append("cidade desconhecida")

    if sem_engajamento and fortes:
        l.classe, l.motivos = "robô", ["sem engajamento"] + fortes + fracos
    elif sem_engajamento and fracos:
        l.classe, l.motivos = "provável robô", ["sem engajamento"] + fracos
    elif fortes and taxa < 0.1:
        l.classe, l.motivos = "provável robô", [f"engajamento de {taxa:.0%}"] + fortes
    elif l.engajadas > 0 and (media >= 10 or paginas >= 2) and not fortes:
        l.classe, l.motivos = "pessoa", [f"{taxa:.0%} engajadas", f"{media:.0f} s por sessão"]
    else:
        l.classe, l.motivos = "indefinido", [f"{taxa:.0%} engajadas", f"{media:.0f} s por sessão"]


def pessoas_minimo(l: Linha) -> int:
    """Piso de pessoas: só as sessões engajadas de linhas que não são robô."""
    return 0 if l.classe in ("robô", "provável robô") else l.engajadas


def pessoas_maximo(l: Linha) -> int:
    """Teto de pessoas: todas as sessões que não parecem robô (inclui quem saiu na primeira página)."""
    return 0 if l.classe in ("robô", "provável robô") else l.sessoes


# ----------------------------------------------------------------------------- Google
def b64url(dados: bytes) -> str:
    return base64.urlsafe_b64encode(dados).rstrip(b"=").decode()


def token_de_acesso(chave: dict) -> str:
    """Troca a chave da conta de serviço por um token de acesso (JWT assinado, RS256)."""
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import padding

    agora = int(time.time())
    cabecalho = b64url(json.dumps({"alg": "RS256", "typ": "JWT"}).encode())
    corpo = b64url(json.dumps({"iss": chave["client_email"], "scope": ESCOPO, "aud": chave["token_uri"],
                               "iat": agora, "exp": agora + 3600}).encode())
    privada = serialization.load_pem_private_key(chave["private_key"].encode(), password=None)
    assinatura = privada.sign(f"{cabecalho}.{corpo}".encode(), padding.PKCS1v15(), hashes.SHA256())
    jwt = f"{cabecalho}.{corpo}.{b64url(assinatura)}"
    r = requests.post(chave["token_uri"], data={"grant_type": "urn:ietf:params:oauth:grant-type:jwt-bearer",
                                                "assertion": jwt}, timeout=TIMEOUT)
    if r.status_code != 200:
        raise SystemExit(f"o Google recusou a chave da conta de serviço (HTTP {r.status_code}): {r.text[:300]}")
    return r.json()["access_token"]


def listar_propriedades(token: str) -> None:
    r = requests.get(f"{ADMIN_API}/accountSummaries", headers={"Authorization": f"Bearer {token}"}, timeout=TIMEOUT)
    if r.status_code != 200:
        raise SystemExit("sem GA4_PROPRIEDADE e sem como listar as propriedades (ative a Google Analytics Admin API "
                         f"no projeto ou informe o ID): HTTP {r.status_code} {r.text[:200]}")
    contas = r.json().get("accountSummaries", [])
    if not contas:
        raise SystemExit("a conta de serviço não enxerga nenhuma propriedade: adicione o e-mail dela como Leitor no GA4")
    for c in contas:
        for p in c.get("propertySummaries", []):
            print(f"{p['property'].split('/')[-1]}\t{p.get('displayName')}\t(conta {c.get('displayName')})")


def relatorio(token: str, propriedade: str, corpo: dict) -> list[dict]:
    r = requests.post(f"{DATA_API}/properties/{propriedade}:runReport", headers={"Authorization": f"Bearer {token}"},
                      json=corpo, timeout=TIMEOUT)
    if r.status_code != 200:
        raise SystemExit(f"a Data API recusou o relatório (HTTP {r.status_code}): {r.text[:400]}")
    dados = r.json()
    dims = [d["name"] for d in dados.get("dimensionHeaders", [])]
    mets = [m["name"] for m in dados.get("metricHeaders", [])]
    linhas = []
    for row in dados.get("rows", []):
        item = {n: v["value"] for n, v in zip(dims, row.get("dimensionValues", []))}
        item.update({n: v["value"] for n, v in zip(mets, row.get("metricValues", []))})
        linhas.append(item)
    return linhas


def intervalos(desde: str, ate: str) -> list[dict]:
    """Antes e depois do aviso de cookies, quando o período cruza o dia 27/09/2026."""
    if ate < AVISO_DE_COOKIES:
        return [{"startDate": desde, "endDate": ate, "name": "antes do aviso"}]
    if desde >= AVISO_DE_COOKIES:
        return [{"startDate": desde, "endDate": ate, "name": "depois do aviso"}]
    return [{"startDate": desde, "endDate": "2026-09-26", "name": "antes do aviso"},
            {"startDate": AVISO_DE_COOKIES, "endDate": ate, "name": "depois do aviso"}]


def buscar(token: str, propriedade: str, desde: str, ate: str) -> tuple[list[Linha], list[dict]]:
    dims = ["country", "city", "browser", "operatingSystem", "screenResolution", "language",
            "sessionSourceMedium", "landingPage"]
    corpo = {"dateRanges": intervalos(desde, ate), "dimensions": [{"name": d} for d in dims],
             "metrics": [{"name": m} for m in ("sessions", "engagedSessions", "userEngagementDuration", "screenPageViews")],
             "limit": 250000}
    linhas = []
    for x in relatorio(token, propriedade, corpo):
        linhas.append(Linha(periodo=x.get("dateRange", "período"), pais=x["country"], cidade=x["city"],
                            navegador=x["browser"], sistema=x["operatingSystem"], resolucao=x["screenResolution"],
                            idioma=x["language"], origem=x["sessionSourceMedium"], entrada=x["landingPage"],
                            sessoes=int(x["sessions"]), engajadas=int(x["engagedSessions"]),
                            engajamento_s=float(x["userEngagementDuration"]), paginas=int(x["screenPageViews"])))
    # Endereço em que o código do GA4 rodou: cópia do site em outro domínio, o tradutor do Google
    # (…translate.goog, gente de fora lendo em outra língua) ou páginas de teste.
    hosts = relatorio(token, propriedade, {"dateRanges": [{"startDate": desde, "endDate": ate}],
                                           "dimensions": [{"name": "hostName"}, {"name": "country"}],
                                           "metrics": [{"name": "sessions"}, {"name": "engagedSessions"}],
                                           "limit": 1000})
    return linhas, hosts


# ----------------------------------------------------------------------------- relatório
def resumo(linhas: list[Linha], hosts: list[dict]) -> str:
    for l in linhas:
        classificar(l)
    saida = []
    periodos = sorted({l.periodo for l in linhas})
    for periodo in periodos:
        grupo = [l for l in linhas if l.periodo == periodo]
        total = sum(l.sessoes for l in grupo)
        if not total:
            continue
        por_pais = defaultdict(lambda: {"s": 0, "robo": 0, "min": 0, "max": 0})
        for l in grupo:
            p = por_pais[l.pais]
            p["s"] += l.sessoes
            p["robo"] += l.sessoes if l.classe in ("robô", "provável robô") else 0
            p["min"] += pessoas_minimo(l)
            p["max"] += pessoas_maximo(l)
        robo = sum(p["robo"] for p in por_pais.values())
        pmin = sum(p["min"] for p in por_pais.values())
        pmax = sum(p["max"] for p in por_pais.values())
        fora = {k: v for k, v in por_pais.items() if k != BRASIL}
        s_fora = sum(v["s"] for v in fora.values())
        saida.append(f"## {periodo}\n")
        saida.append(f"- Sessões: {total}; parecem robô: {robo} ({robo / total:.0%}); "
                     f"pessoas reais: entre {pmin} e {pmax} ({pmin / total:.0%} a {pmax / total:.0%}).")
        if s_fora:
            fmin = sum(v["min"] for v in fora.values())
            fmax = sum(v["max"] for v in fora.values())
            frob = sum(v["robo"] for v in fora.values())
            saida.append(f"- Fora do Brasil: {s_fora} sessões ({s_fora / total:.0%} do total); parecem robô: {frob} "
                         f"({frob / s_fora:.0%}); pessoas reais: entre {fmin} e {fmax}.")
        saida.append("\n| País | Sessões | Parecem robô | Pessoas (mín.–máx.) |\n|---|---:|---:|---:|")
        for pais, v in sorted(por_pais.items(), key=lambda kv: -kv[1]["s"])[:25]:
            saida.append(f"| {pais} | {v['s']} | {v['robo']} ({v['robo'] / v['s']:.0%}) | {v['min']}–{v['max']} |")
        # Por que gente de fora chega: página de entrada e origem das sessões com cara de pessoa.
        razoes = defaultdict(int)
        for l in grupo:
            if l.pais != BRASIL and l.classe == "pessoa":
                razoes[(l.pais, l.origem, l.entrada, l.idioma)] += l.sessoes
        if razoes:
            saida.append("\nPessoas de fora do Brasil: de onde vieram e onde entraram\n\n"
                         "| País | Origem | Página de entrada | Idioma do navegador | Sessões |\n|---|---|---|---|---:|")
            for (pais, origem, entrada, idioma), n in sorted(razoes.items(), key=lambda kv: -kv[1])[:20]:
                saida.append(f"| {pais} | {origem} | {entrada} | {idioma} | {n} |")
        # As maiores fontes de robô, para reconhecer o padrão.
        robos = defaultdict(int)
        for l in grupo:
            if l.classe in ("robô", "provável robô"):
                robos[(l.pais, l.cidade, l.sistema, l.resolucao, "; ".join(l.motivos[:3]))] += l.sessoes
        if robos:
            saida.append("\nMaiores fontes de robô\n\n| País | Cidade | Sistema | Resolução | Por quê | Sessões |\n"
                         "|---|---|---|---|---|---:|")
            for (pais, cidade, sistema, res, motivo), n in sorted(robos.items(), key=lambda kv: -kv[1])[:15]:
                saida.append(f"| {pais} | {cidade} | {sistema} | {res} | {motivo} | {n} |")
        saida.append("")
    if hosts:
        saida.append("## Endereços em que o GA4 rodou\n\n| Endereço | País | Sessões | Engajadas |\n|---|---|---:|---:|")
        for h in sorted(hosts, key=lambda h: -int(h["sessions"]))[:25]:
            saida.append(f"| {h['hostName']} | {h['country']} | {h['sessions']} | {h['engagedSessions']} |")
    return "\n".join(saida)


def teste() -> int:
    """Confere a classificação com casos conhecidos, sem rede."""
    def L(pais, cidade, s, eng, dur, pag, res="1920x1080", so="Windows", origem="google / organic", entrada="/"):
        return Linha("teste", pais, cidade, "Chrome", so, res, "pt-br", origem, entrada, s, eng, dur, pag)
    casos = [
        (L("United States", "Ashburn", 40, 0, 0, 40, so="Linux"), "robô"),
        (L("China", "Lanzhou", 90, 0, 0, 90, res="1280x1200"), "robô"),
        (L("Singapore", "Singapore", 30, 0, 0, 30, origem="(direct) / (none)", entrada="(not set)"), "provável robô"),
        (L("Brazil", "Rio de Janeiro", 50, 30, 3000, 120), "pessoa"),
        (L("Portugal", "Lisbon", 4, 3, 400, 9), "pessoa"),
        (L("Brazil", "Sao Paulo", 10, 0, 0, 10), "indefinido"),
        (L("United States", "Ashburn", 20, 1, 30, 21), "provável robô"),
    ]
    falhas = 0
    for linha, esperado in casos:
        classificar(linha)
        ok = linha.classe == esperado
        falhas += not ok
        print(("ok   " if ok else "FALHA") + f" {linha.pais}/{linha.cidade}: {linha.classe} (esperado {esperado})")
    print(resumo([c[0] for c in casos], []))
    return 1 if falhas else 0


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--desde", default="2026-06-01")
    ap.add_argument("--ate", default=time.strftime("%Y-%m-%d"))
    ap.add_argument("--csv", help="grava as combinações classificadas neste arquivo (dados agregados)")
    ap.add_argument("--teste", action="store_true")
    a = ap.parse_args()
    if a.teste:
        return teste()
    caminho = os.environ.get("GA4_CHAVE_JSON")
    if not caminho:
        raise SystemExit("defina GA4_CHAVE_JSON com o caminho da chave JSON da conta de serviço")
    with open(caminho, encoding="utf-8") as f:
        chave = json.load(f)
    token = token_de_acesso(chave)
    propriedade = os.environ.get("GA4_PROPRIEDADE", "").strip()
    if not propriedade:
        listar_propriedades(token)
        return 0
    linhas, hosts = buscar(token, propriedade, a.desde, a.ate)
    print(resumo(linhas, hosts))
    if a.csv:
        with open(a.csv, "w", newline="", encoding="utf-8") as f:
            w = csv.writer(f)
            w.writerow(["periodo", "pais", "cidade", "navegador", "sistema", "resolucao", "idioma", "origem", "entrada",
                        "sessoes", "engajadas", "engajamento_s", "paginas", "classe", "motivos"])
            for l in linhas:
                w.writerow([l.periodo, l.pais, l.cidade, l.navegador, l.sistema, l.resolucao, l.idioma, l.origem,
                            l.entrada, l.sessoes, l.engajadas, round(l.engajamento_s), l.paginas, l.classe,
                            "; ".join(l.motivos)])
    return 0


if __name__ == "__main__":
    sys.exit(main())
