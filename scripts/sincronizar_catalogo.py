#!/usr/bin/env python3
"""Sincroniza o catálogo da matrícula em cursos presenciais com o catálogo público da escola.

Lê https://escola.cursoscruzvermelha.org/cursos e a página de cada curso publicado e grava
site/matricula-cursos-presenciais/cursos.json com nome, descrição, carga horária, escolaridade, valores,
observações, dúvidas frequentes e a URL da foto do curso (imagem_url), exatamente como a escola
publica. As fotos em WebP são geradas por scripts/gerar_imagens_matricula.py e a página
site/matricula-cursos-presenciais/index.html por scripts/gerar_matricula_presencial.py.

Formato da escola desde 11/2026: a página do curso é /cursos/<nome-do-curso> (o endereço antigo, /cursos/<id>, redireciona
para ele), a carga horária e a escolaridade vêm nos "chips" do topo (sem rótulo), e o id do curso (o "uuid", que a
matrícula manda à escola depois do pagamento) só aparece na imagem de compartilhamento (og:image .../compartilhar/curso/
<id>.jpg). Sem esse id, ou com um id diferente do já gravado para o mesmo curso, nada é gravado.

Curso cuja taxa de inscrição na escola é diferente da do checkout (inscricao_centavos) entra com o preço padrão do site
(a matrícula da escola + a taxa do checkout, a mesma de todos os cursos), e a sincronização avisa: o valor da escola, se
for menor, vira um cupom de vitrine em api/lib/cupons.php (decisão do Matheus de 06/11/2026, no Curativos e Tratamento de
Feridas, que a escola vende sem taxa em novembro).

Regra do projeto: só existem aqui os cursos que a escola publica. Curso que sair do catálogo
da escola sai daqui na próxima sincronização. E nem todo curso da escola entra: os de FORA_DO_DOMINIO
ficam só na plataforma da escola, e a sincronização não os traz de volta.

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

# Cursos que a escola publica e que não são vendidos no domínio da filial (cruzvermelhariodejaneiro.org): ficam só
# na plataforma da escola. Slug -> motivo e data da decisão. Tirar daqui é o único jeito de o curso voltar.
FORA_DO_DOMINIO = {
    "micropigmentacao-labial": ("procedimento estético, sem relação com a missão humanitária da filial; decisão do "
                                "Matheus de 19/10/2026, na segunda recusa do Google Ad Grants"),
}

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


UUID = re.compile(r"[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}")


def chips(pagina: str) -> list[str]:
    """Os "chips" do topo da página do curso (formato de 11/2026): carga horária, escolaridade, presencial, certificado."""
    bloco = re.search(r'<div class="v-curso-chips">(.*?)</div>', pagina, re.S)
    if not bloco:
        return []
    itens = re.findall(r"<span[^>]*>(.*?)</span>", bloco.group(1), re.S)
    return [re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", " ", i))).strip() for i in itens]


def extrair_curso(caminho: str, nome_catalogo: str) -> dict:
    url = f"{ORIGEM}/cursos/{caminho}"
    pagina = baixar(url)
    linhas = texto_em_linhas(pagina)
    h1 = re.search(r"<h1[^>]*>(.*?)</h1>", pagina, re.S)
    nome = html.unescape(re.sub(r"<[^>]+>", "", h1.group(1))).strip() if h1 else nome_catalogo
    meta = re.search(r'<meta name="description" content="([^"]*)"', pagina)
    # Foto do curso: a mesma que a escola mostra no topo da página do curso (classe antiga e a de 11/2026).
    foto = re.search(r'<img class="(?:cx-hero-img|v-curso-foto)" src="([^"]+)"', pagina)
    # O id do curso na escola: o próprio caminho (formato antigo) ou o da imagem de compartilhamento (11/2026).
    uuid = caminho if UUID.fullmatch(caminho) else None
    if uuid is None:
        m_id = re.search(r'/compartilhar/curso/(' + UUID.pattern + r')\.', pagina)
        uuid = m_id.group(1) if m_id else None
    if uuid is None:
        raise ValueError(f"sem o id do curso na página {url}")
    canonica = re.search(r'<link rel="canonical" href="([^"]+)"', pagina)
    url = canonica.group(1) if canonica and canonica.group(1).startswith(ORIGEM + "/cursos/") else url
    topo = chips(pagina)

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
    # Aviso de promoção da escola no fim da descrição ("(MATRÍCULA GRÁTIS EM NOVEMBRO)"): aqui a promoção é um cupom.
    descricao = re.sub(r"\s*\([^)]*GR[ÁA]TIS[^)]*\)\s*$", "", descricao, flags=re.I)
    m_hom = re.search(r"\s*\((?:VALOR DE )?HOMOLOGA[^)]*\)\s*$", descricao, re.I)
    if m_hom:
        descricao = descricao[: m_hom.start()].strip()
    # Tagline da escola: frases "Inclui X." viram observação; se o que sobrar for curto demais
    # ("Capacitação essencial."), a descrição passa a ser a primeira frase de "Sobre o curso".
    frases = [f.strip() for f in re.split(r"(?<=[.!?])\s+", descricao) if f.strip()]
    inclui = [f if f.endswith(".") else f + "." for f in frases if re.match(r"inclui\b", f, re.I)]
    descricao = " ".join(f for f in frases if not re.match(r"inclui\b", f, re.I))
    if len(descricao) < 30:
        descricao = ""

    sobre = bloco_entre(linhas, "Sobre o curso", ["Turmas abertas", "Dúvidas frequentes", "Investimento"])
    # Subtítulo da seção (11/2026) e a condição promocional da escola não são texto do curso.
    sobre = [l for l in sobre if l != "O que você vai aprender" and not l.lower().startswith("condição promocional")]
    observacoes = [l for l in sobre if re.search(r"homologa|alimento|documento|a cargo do aluno", l, re.I)]
    sobre = [l for l in sobre if l not in observacoes]
    observacoes = inclui + observacoes
    for comp in complementos:
        if comp.startswith("+"):
            observacoes.insert(0, "Inclui " + comp.lstrip("+ ").strip() + ".")
        elif not re.search(r"homologa", comp, re.I):
            observacoes.insert(0, comp)
    if not descricao and sobre:
        descricao = re.split(r"(?<=[.!?])\s", sobre[0])[0]

    # Dúvidas: pergunta (termina em "?") seguida da resposta. Desde 11/2026 o bloco vem depois do Investimento e acaba
    # em "Veja também" (os outros cursos).
    faq_linhas = bloco_entre(linhas, "Dúvidas frequentes", ["Investimento", "Veja também", "Outros cursos da Escola"])
    faq = []
    k = 0
    while k < len(faq_linhas) - 1:
        if faq_linhas[k].endswith("?") and not faq_linhas[k + 1].endswith("?"):
            faq.append({"pergunta": faq_linhas[k], "resposta": faq_linhas[k + 1]})
            k += 2
        else:
            k += 1

    investimento = bloco_entre(linhas, "Investimento", ["Inscrever-se", "Valores para pagamento", "©"])
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
        "carga_horaria": depois_de(linhas, "Carga horária") or next((c for c in topo if re.fullmatch(r"\d+ horas?", c)), ""),
        "escolaridade": depois_de(linhas, "Escolaridade mínima") or next((c for c in topo if re.match(r"(?i)ensino ", c)), ""),
        # Desde 11/2026 a escola chama de "matrícula" o valor do curso, e de "taxa de inscrição" a taxa.
        "valor_curso_centavos": valor("Valor do curso") if valor("Valor do curso") is not None else valor("Valor da matrícula"),
        "valor_matricula_escola_centavos": valor("Taxa de inscrição") if valor("Taxa de inscrição") is not None else valor("Valor da matrícula"),
        "total_escola_centavos": valor("Total à vista"),
        "faq": faq,
        "imagem": slug,  # base dos arquivos em img/ (<slug>-480.webp e <slug>-960.webp)
        "imagem_url": html.unescape(foto.group(1)) if foto else None,
    }


def main() -> int:
    catalogo = baixar(f"{ORIGEM}/cursos")
    vistos: dict[str, str] = {}
    # Link de cada curso: /cursos/<id> (até 10/2026, aria-label "Ver X") ou /cursos/<nome-do-curso> (11/2026, aria-label
    # "X: ver turmas e valores").
    for caminho, rotulo in re.findall(r'href="/cursos/([a-z0-9][a-z0-9-]*)"(?:[^>]*aria-label="(?:Ver )?([^"]+?)(?:: ver turmas e valores)?")?', catalogo):
        if caminho not in vistos or (rotulo and not vistos[caminho]):
            vistos[caminho] = html.unescape(rotulo or "")
    if not vistos:
        print("nenhum curso no catálogo; nada gravado", file=sys.stderr)
        return 1

    anterior = json.loads(SAIDA.read_text(encoding="utf-8")) if SAIDA.exists() else {}
    inscricao = int(anterior.get("inscricao_centavos") or 9900)
    try:
        cursos = [extrair_curso(caminho, nome) for caminho, nome in vistos.items()]
    except (ValueError, OSError) as erro:
        print(f"ERRO: {erro}. Nada foi gravado.", file=sys.stderr)
        return 1
    # O id de um curso que já existe aqui não muda: é o que a matrícula manda à escola. Mudou, alguém precisa olhar.
    ids = {c["slug"]: c.get("uuid") for c in anterior.get("cursos", [])}
    trocados = [c["slug"] for c in cursos if ids.get(c["slug"]) and ids[c["slug"]] != c["uuid"]]
    if trocados:
        print(f"ERRO: o id na escola mudou para {', '.join(trocados)}. Nada foi gravado.", file=sys.stderr)
        return 1
    for c in cursos:
        if c["valor_matricula_escola_centavos"] is not None and c["valor_matricula_escola_centavos"] != inscricao:
            print(f"  AVISO: taxa de inscrição da escola R$ {c['valor_matricula_escola_centavos'] / 100:.2f} em {c['nome']} [{c['slug']}]; "
                  f"o site cobra a padrão, R$ {inscricao / 100:.2f}. O preço da escola, se for promoção, vai num cupom (api/lib/cupons.php).")
    for c in cursos:
        if c["slug"] in FORA_DO_DOMINIO:
            print(f"  fora do domínio da filial: {c['nome']} [{c['slug']}] ({FORA_DO_DOMINIO[c['slug']]})")
    cursos = [c for c in cursos if c["slug"] not in FORA_DO_DOMINIO]
    cursos.sort(key=lambda c: c["nome"].lower())

    # Grupos: os slugs listados primeiro; o resto cai em "Outros cursos".
    conhecidos = {s for _, _, slugs in GRUPOS for s in slugs}
    grupos = []
    for gid, titulo, slugs in GRUPOS:
        membros = [c["slug"] for c in cursos if c["slug"] in slugs] if slugs else [c["slug"] for c in cursos if c["slug"] not in conhecidos]
        if membros:
            grupos.append({"id": gid, "titulo": titulo, "cursos": membros})

    # Contribuição opcional para a divulgação (07/10/2026): o valor em teste não vem da escola; fica o do arquivo.
    dados = {
        "fonte": f"{ORIGEM}/cursos",
        "inscricao_centavos": inscricao,
        "divulgacao_centavos": 0,
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
