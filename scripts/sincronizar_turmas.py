#!/usr/bin/env python3
"""Sincroniza as turmas do oferta.json com as turmas abertas que a escola publica.

Lê a página pública de cada curso (url_escola, no cursos.json) em escola.cursoscruzvermelha.org: a lista "Escolha sua
turma" traz, de cada turma, a data ("Início em 12 de novembro de 2026"), o horário ("09:00 - 17:00") e o id da turma
na escola (o link /inscrever/<id>), que é o turma_id que a matrícula manda à escola depois do pagamento.

Regras:
  - turma nova na escola: entra no oferta.json, com inscricoes_ate na véspera do início, às 23:59:59 (a mesma regra das
    turmas cadastradas à mão até aqui; a escola não publica o prazo);
  - turma que mudou de data ou horário: atualiza;
  - turma futura do oferta.json que sumiu da escola (lotou, cancelou ou fechou): ganha "lotada": true, e a venda dela
    fecha. Não é apagada: pode ter aluno que já pagou por ela;
  - turma que já começou: fica como está (histórico);
  - "parcelado_no_ar" e "sem_turma" não mudam.
Se uma página da escola não abrir ou não tiver a seção de turmas, nada é gravado: uma falha de rede nunca pode fechar
turmas.

Depois de gravar, regerar e publicar: ver "Rotina das turmas" em docs/turmas.md.

Uso:  python3 scripts/sincronizar_turmas.py            (grava se mudou; sai com 0)
      python3 scripts/sincronizar_turmas.py --conferir (só mostra; sai com 1 se o oferta.json está desatualizado)
      python3 scripts/sincronizar_turmas.py --teste    (confere a leitura de uma página de exemplo, sem rede)
"""
from __future__ import annotations

import html
import json
import os
import re
import sys
import urllib.request
from datetime import datetime, timedelta, timezone
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
PASTA = RAIZ / "site" / "matricula-cursos-presenciais"
CURSOS = PASTA / "cursos.json"
OFERTA = Path(os.environ.get("OFERTA_ARQUIVO") or PASTA / "oferta.json")
BRT = timezone(timedelta(hours=-3))
MESES = ["janeiro", "fevereiro", "março", "abril", "maio", "junho", "julho", "agosto", "setembro", "outubro",
         "novembro", "dezembro"]


def baixar(url: str) -> str:
    req = urllib.request.Request(url, headers={"User-Agent": "cvb-rj-matricula-presencial/1.0"})
    with urllib.request.urlopen(req, timeout=30) as resp:
        return resp.read().decode("utf-8", errors="replace")


def ler_turmas(pagina: str) -> list[dict]:
    """As turmas da seção "Escolha sua turma". Sem a seção (curso sem turma aberta), lista vazia; com a seção e sem
    conseguir ler uma turma, erro (a página mudou: melhor parar que fechar turmas)."""
    if "Sem turma aberta no momento" in pagina and "Escolha sua turma" not in pagina:
        return []
    inicio = pagina.find("Escolha sua turma")
    if inicio < 0:
        raise ValueError("a página não tem nem a seção de turmas nem o aviso de sem turma")
    fim = pagina.find('class="v-aviso"', inicio)
    secao = pagina[inicio:fim if fim > 0 else inicio + 20000]
    blocos = re.split(r'<div class="v-turma">', secao)[1:]
    if not blocos:
        raise ValueError("seção de turmas sem nenhuma turma")
    turmas = []
    for bloco in blocos:
        texto = html.unescape(re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", bloco)))
        data = re.search(r"Início em (\d{1,2}) de ([a-zç]+) de (\d{4})", texto)
        hora = re.search(r"(\d{2}):(\d{2})\s*-\s*(\d{2}):(\d{2})", texto)
        ident = re.search(r'/inscrever/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})"', bloco)
        if not (data and hora and ident) or data.group(2) not in MESES:
            raise ValueError(f"turma ilegível: {texto[:120]!r}")
        dia = datetime(int(data.group(3)), MESES.index(data.group(2)) + 1, int(data.group(1)), tzinfo=BRT)
        comeca = dia.replace(hour=int(hora.group(1)), minute=int(hora.group(2)))
        termina = dia.replace(hour=int(hora.group(3)), minute=int(hora.group(4)))
        prazo = (dia - timedelta(days=1)).replace(hour=23, minute=59, second=59)
        turmas.append({"id_escola": ident.group(1), "inicio": comeca.isoformat(), "fim": termina.isoformat(),
                       "inscricoes_ate": prazo.isoformat()})
    return turmas


def sincronizar(oferta: dict, da_escola: dict[str, list[dict]], agora: datetime) -> tuple[dict, list[str]]:
    """Junta as turmas da escola ao oferta.json. Devolve o oferta novo e o que mudou, em português."""
    mudancas: list[str] = []
    turmas = [dict(t) for t in oferta.get("turmas") or []]
    por_id = {t["id_escola"]: t for t in turmas}
    vistos = set()
    for curso, lista in da_escola.items():
        for nova in lista:
            vistos.add(nova["id_escola"])
            atual = por_id.get(nova["id_escola"])
            if atual is None:
                turmas.append({"curso": curso, **nova, "lotada": False})
                mudancas.append(f"nova: {curso}, {nova['inicio'][:16]}")
                continue
            for campo in ("inicio", "fim", "inscricoes_ate"):
                if atual.get(campo) != nova[campo]:
                    mudancas.append(f"{campo} de {curso}: {atual.get(campo)} -> {nova[campo]}")
                    atual[campo] = nova[campo]
            if atual.get("lotada") is True:
                atual["lotada"] = False
                mudancas.append(f"reaberta: {curso}, {nova['inicio'][:16]}")
    for t in turmas:
        futura = datetime.fromisoformat(t["inicio"]) > agora
        if futura and t["id_escola"] not in vistos and t.get("lotada") is not True and t["curso"] in da_escola:
            t["lotada"] = True
            mudancas.append(f"fechada (sumiu da escola): {t['curso']}, {t['inicio'][:16]}")
    turmas.sort(key=lambda t: (t["inicio"], t["curso"]))
    return {**oferta, "turmas": turmas}, mudancas


def gravar(oferta: dict) -> None:
    """O mesmo formato do arquivo mantido à mão: uma turma por bloco e o sem_turma com um curso por linha."""
    linhas = ["{", f'  "parcelado_no_ar": {json.dumps(oferta.get("parcelado_no_ar") is True)},', '  "turmas": [']
    blocos = []
    for t in oferta["turmas"]:
        campos = [f'      "{k}": {json.dumps(t[k], ensure_ascii=False)}' for k in
                  ("curso", "id_escola", "inicio", "fim", "inscricoes_ate", "lotada")]
        blocos.append("    {\n" + ",\n".join(campos) + "\n    }")
    linhas.append(",\n".join(blocos))
    linhas.append("  ],")
    sem = oferta.get("sem_turma") or {}
    itens = [f'    "{k}": {json.dumps(v, ensure_ascii=False, separators=(", ", ": "))}' for k, v in sem.items()]
    linhas.append('  "sem_turma": {\n' + ",\n".join(itens) + "\n  }")
    linhas.append("}")
    OFERTA.write_text("\n".join(linhas) + "\n", encoding="utf-8")


EXEMPLO = """<h2>Escolha sua turma</h2> <div class="v-turmas"> <div class="v-turma"> <div class="v-data"><b>12</b>
<span>novembro</span></div> <div class="v-turma-txt"> <strong>Início em 12 de novembro de 2026</strong> <span>09:00 - 17:00
· Praça da Cruz Vermelha, 10 · Centro</span> </div> <a class="v-btn v-btn-red"
href="/inscrever/bd0e9f3c-5268-4572-bc9f-04237aa9de38">Inscrever-se</a> </div> </div> <p class="v-aviso">"""


def teste() -> int:
    falhas = 0

    def ver(nome, obtido, esperado):
        nonlocal falhas
        ok = obtido == esperado
        falhas += not ok
        print(("ok    " if ok else "FALHA ") + nome + ("" if ok else f": {obtido!r} != {esperado!r}"))

    lidas = ler_turmas(EXEMPLO)
    ver("lê a turma do exemplo", lidas, [{"id_escola": "bd0e9f3c-5268-4572-bc9f-04237aa9de38",
                                         "inicio": "2026-11-12T09:00:00-03:00", "fim": "2026-11-12T17:00:00-03:00",
                                         "inscricoes_ate": "2026-11-11T23:59:59-03:00"}])
    ver("curso sem turma: lista vazia", ler_turmas("<p>Sem turma aberta no momento</p>"), [])
    try:
        ler_turmas("<html>outra coisa</html>")
        ver("página estranha: erro", "sem erro", "erro")
    except ValueError:
        ver("página estranha: erro", "erro", "erro")
    agora = datetime(2026, 10, 25, 12, tzinfo=BRT)
    velha = {"curso": "primeiros-socorros-basico", "id_escola": "velha", "inicio": "2026-10-21T09:00:00-03:00",
             "fim": "2026-10-21T17:00:00-03:00", "inscricoes_ate": "2026-10-20T23:59:59-03:00", "lotada": False}
    sumiu = {**velha, "id_escola": "sumiu", "inicio": "2026-12-01T09:00:00-03:00", "inscricoes_ate": "2026-11-30T23:59:59-03:00"}
    novo, mud = sincronizar({"parcelado_no_ar": False, "turmas": [velha, sumiu], "sem_turma": {}},
                            {"primeiros-socorros-basico": lidas, "puncao-venosa": []}, agora)
    por = {t["id_escola"]: t for t in novo["turmas"]}
    ver("turma passada fica como está", por["velha"], velha)
    ver("turma futura que sumiu fecha (lotada)", por["sumiu"]["lotada"], True)
    ver("turma nova entra aberta", (por["bd0e9f3c-5268-4572-bc9f-04237aa9de38"]["curso"], por["bd0e9f3c-5268-4572-bc9f-04237aa9de38"]["lotada"]),
        ("primeiros-socorros-basico", False))
    ver("duas mudanças: a nova e a que fechou", len(mud), 2)
    _, mud2 = sincronizar(novo, {"primeiros-socorros-basico": lidas}, agora)
    ver("rodar de novo não muda nada", mud2, [])
    print(f"{falhas} falha(s)")
    return 1 if falhas else 0


def main() -> int:
    if "--teste" in sys.argv:
        return teste()
    cursos = json.loads(CURSOS.read_text(encoding="utf-8"))["cursos"]
    oferta = json.loads(OFERTA.read_text(encoding="utf-8"))
    da_escola: dict[str, list[dict]] = {}
    for c in cursos:
        try:
            da_escola[c["slug"]] = ler_turmas(baixar(c["url_escola"]))
        except Exception as erro:  # rede ou página mudada: não grava nada
            print(f"ERRO em {c['slug']} ({c['url_escola']}): {erro}. Nada foi gravado.")
            return 2
        print(f"{c['slug']}: {len(da_escola[c['slug']])} turma(s) aberta(s) na escola")
    novo, mudancas = sincronizar(oferta, da_escola, datetime.now(BRT))
    if not mudancas:
        print("oferta.json já está igual à escola.")
        return 0
    print("\n".join("  - " + m for m in mudancas))
    if "--conferir" in sys.argv:
        print("oferta.json desatualizado (nada gravado: --conferir).")
        return 1
    gravar(novo)
    print(f"gravado {OFERTA.relative_to(RAIZ) if OFERTA.is_relative_to(RAIZ) else OFERTA}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
