#!/usr/bin/env python3
"""Sincroniza o catálogo da matrícula em cursos presenciais com o catálogo público da escola.

Lê https://escola.cursoscruzvermelha.org/cursos e a página de cada curso publicado e grava
site/matricula-cursos-presenciais/cursos.json com nome, descrição, carga horária, escolaridade, valores,
observações, dúvidas frequentes e a URL da foto do curso (imagem_url), exatamente como a escola
publica. As fotos em WebP são geradas por scripts/gerar_imagens_matricula.py e a página
site/matricula-cursos-presenciais/index.html por scripts/gerar_matricula_presencial.py.

Regra do projeto: só existem aqui os cursos que a escola publica. Curso que sair do catálogo
da escola sai daqui na próxima sincronização.

Uso:  python3 scripts/sincronizar_catalogo.py
Sem dependências além da biblioteca padrão.
"""
from __future__ import annotations

import html
import json
import re
import sys
import unicodedata
import urllib.request
from pathlib import Path

ORIGEM = "https://escola.cursoscruzvermelha.org"
RAIZ = Path(__file__).resolve().parent.parent
SAIDA = RAIZ / "site" / "matricula-cursos-presenciais" / "cursos.json"

GRUPOS = [
    ("emergencia", "Emergência e vida", ["primeiros-socorros-basico", "primeiros-socorros-lei-lucas", "suporte-basico-de-vida", "puncao-venosa"]),
    ("formacao", "Formação profissional", ["bombeiro-civil", "cuidador-de-idosos"]),
    ("outros", "Outros cursos", []),  # recebe o que não estiver nos grupos acima
]


def baixar(url: str) -> str:
    req = urllib.request.Request(url, headers={"User-Agent": "cvb-rj-matricula-presencial/1.0"})
    with urllib.request.urlopen(req, timeout=30) as resp:
        return resp.read().decode("utf-8", errors="replace")


def slugificar(nome: str) -> str:
    base = unicodedata.normalize("NFKD", nome).encode("ascii", "ignore").decode()
    base = re.sub(r"\(.*?\)", " ", base)            # "(Curso Livre)" não entra no slug
    base = re.sub(r"\s+-\s+.*$", "", base)           # "Lei Lucas - Ambientes com Crianças" -> até o hífen
    base = re.sub(r"[^a-zA-Z0-9]+", "-", base).strip("-").lower()
    return base


def texto_em_linhas(pagina: str) -> list[str]:
    corpo = pagina[pagina.find("<main"):] if "<main" in pagina else pagina
    corpo = re.sub(r"<script.*?</script>|<style.*?</style>", "", corpo, flags=re.S)
    corpo = re.sub(r"</(p|h[1-6]|li|div|section|article|tr|dt|dd|summary|details|button|a|span)>", "\n", corpo)
    txt = html.unescape(re.sub(r"<[^>]+>", " ", corpo))
    linhas = [re.sub(r"\s+", " ", l).strip() for l in txt.split("\n")]
    return [l for l in linhas if l]


def centavos(valor: str) -> int | None:
    m = re.search(r"R\$\s*([\d.]+),(\d{2})", valor)
    if not m:
        return None
    return int(m.group(1).replace(".", "")) * 100 + int(m.group(2))


def depois_de(linhas: list[str], rotulo: str) -> str:
    for i, l in enumerate(linhas):
        if l.lower().startswith(rotulo.lower()) and i + 1 < len(linhas):
            # valor pode estar na mesma linha ("Carga horária 80 horas") ou na seguinte
            resto = l[len(rotulo):].strip(" :")
            return resto or linhas[i + 1]
    return ""


def bloco_entre(linhas: list[str], inicio: str, fins: list[str]) -> list[str]:
    try:
        i = next(k for k, l in enumerate(linhas) if l.lower() == inicio.lower())
    except StopIteration:
        return []
    saida = []
    for l in linhas[i + 1:]:
        if any(l.lower().startswith(f.lower()) for f in fins):
            break
        saida.append(l)
    return saida


def extrair_curso(uuid: str, nome_catalogo: str) -> dict:
    url = f"{ORIGEM}/cursos/{uuid}"
    pagina = baixar(url)
    linhas = texto_em_linhas(pagina)
    h1 = re.search(r"<h1[^>]*>(.*?)</h1>", pagina, re.S)
    nome = html.unescape(re.sub(r"<[^>]+>", "", h1.group(1))).strip() if h1 else nome_catalogo
    meta = re.search(r'<meta name="description" content="([^"]*)"', pagina)
    # Foto do curso: a mesma que a escola mostra no topo da página do curso.
    foto = re.search(r'<img class="cx-hero-img" src="([^"]+)"', pagina)

    # Descrição curta: a linha logo depois do h1.
    try:
        idx = linhas.index(nome)
        descricao = linhas[idx + 1] if idx + 1 < len(linhas) else ""
    except ValueError:
        descricao = html.unescape(meta.group(1)) if meta else ""
    if descricao.lower().startswith("carga hor"):
        descricao = ""
    # Complemento entre parênteses depois do título ("(+Lei Lucas)", "(VALOR DE HOMOLOGAÇÃO A PARTE)")
    # não é descrição: vira observação e a descrição passa a ser a primeira frase de "Sobre o curso".
    complementos = []
    if re.fullmatch(r"\(.*\)", descricao):
        complementos.append(descricao.strip("()").strip())
        descricao = ""
    m_hom = re.search(r"\s*\((?:VALOR DE )?HOMOLOGA[^)]*\)\s*$", descricao, re.I)
    if m_hom:
        descricao = descricao[: m_hom.start()].strip()

    sobre = bloco_entre(linhas, "Sobre o curso", ["Turmas abertas", "Dúvidas frequentes", "Investimento"])
    observacoes = [l for l in sobre if re.search(r"homologa|alimento|documento|a cargo do aluno", l, re.I)]
    sobre = [l for l in sobre if l not in observacoes]
    for comp in complementos:
        if comp.startswith("+"):
            observacoes.insert(0, "Inclui " + comp.lstrip("+ ").strip() + ".")
        elif not re.search(r"homologa", comp, re.I):
            observacoes.insert(0, comp)
    if not descricao and sobre:
        descricao = re.split(r"(?<=[.!?])\s", sobre[0])[0]

    faq_linhas = bloco_entre(linhas, "Dúvidas frequentes", ["Investimento"])
    faq = []
    for k in range(0, len(faq_linhas) - 1, 2):
        if faq_linhas[k].endswith("?"):
            faq.append({"pergunta": faq_linhas[k], "resposta": faq_linhas[k + 1]})

    investimento = bloco_entre(linhas, "Investimento", ["Inscrever-se", "©"])
    def valor(rotulo: str) -> int | None:
        for i, l in enumerate(investimento):
            if l.lower().startswith(rotulo.lower()):
                return centavos(l) if "R$" in l else (centavos(investimento[i + 1]) if i + 1 < len(investimento) else None)
        return None

    slug = slugificar(nome)
    return {
        "slug": slug,
        "uuid": uuid,
        "nome": nome,
        "url_escola": url,
        "descricao": descricao,
        "sobre": sobre,
        "observacoes": observacoes,
        "carga_horaria": depois_de(linhas, "Carga horária"),
        "escolaridade": depois_de(linhas, "Escolaridade mínima"),
        "valor_curso_centavos": valor("Valor do curso"),
        "valor_matricula_escola_centavos": valor("Valor da matrícula"),
        "total_escola_centavos": valor("Total à vista"),
        "faq": faq,
        "imagem": slug,  # base dos arquivos em img/ (<slug>-480.webp e <slug>-960.webp)
        "imagem_url": html.unescape(foto.group(1)) if foto else None,
    }


def main() -> int:
    catalogo = baixar(f"{ORIGEM}/cursos")
    vistos: dict[str, str] = {}
    for uuid, rotulo in re.findall(r'href="/cursos/([0-9a-f-]{36})"(?:[^>]*aria-label="Ver ([^"]+)")?', catalogo):
        if uuid not in vistos or (rotulo and not vistos[uuid]):
            vistos[uuid] = html.unescape(rotulo or "")
    if not vistos:
        print("nenhum curso no catálogo; nada gravado", file=sys.stderr)
        return 1

    cursos = [extrair_curso(uuid, nome) for uuid, nome in vistos.items()]
    cursos.sort(key=lambda c: c["nome"].lower())

    # Grupos: os slugs listados primeiro; o resto cai em "Outros cursos".
    conhecidos = {s for _, _, slugs in GRUPOS for s in slugs}
    grupos = []
    for gid, titulo, slugs in GRUPOS:
        membros = [c["slug"] for c in cursos if c["slug"] in slugs] if slugs else [c["slug"] for c in cursos if c["slug"] not in conhecidos]
        if membros:
            grupos.append({"id": gid, "titulo": titulo, "cursos": membros})

    dados = {
        "fonte": f"{ORIGEM}/cursos",
        "inscricao_centavos": 9900,
        "grupos": grupos,
        "cursos": cursos,
    }
    SAIDA.parent.mkdir(parents=True, exist_ok=True)
    SAIDA.write_text(json.dumps(dados, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"gravado {SAIDA.relative_to(RAIZ)} com {len(cursos)} cursos")
    for c in cursos:
        faltando = [k for k in ("descricao", "carga_horaria", "escolaridade", "valor_curso_centavos") if not c[k]]
        print(f"  - {c['nome']} [{c['slug']}] {c['carga_horaria']} · {c['escolaridade']} · curso R$ {c['valor_curso_centavos'] or 0:,} cent · {len(c['sobre'])} par. · {len(c['faq'])} FAQ" + (f" · FALTANDO {faltando}" if faltando else ""))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
