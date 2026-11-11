#!/usr/bin/env python3
"""Gera o PDF de um guia gratuito, no padrão único dos guias (11/11/2026). Regras em docs/padrao-guias-gratuitos.md.

Cada guia tem uma ficha em guias/<slug>.json, que diz o que vai em cada folha. O desenho (cabeçalho, letra, cor,
quadradinhos, telefones, convite do curso, rodapé) fica só aqui: guia novo é ficha nova, sem mexer no desenho.

Saída: site/<slug>/<arquivo da ficha>.pdf, em A4: as folhas da ficha e, no fim, a folha das fontes, que o script monta
sozinho com TODAS as fontes da página (nome e endereço por extenso). Se o texto não couber, o script avisa e sai com
erro (encurte o texto, não a letra).

A lista principal NÃO se copia para a ficha: o bloco com "da_pagina" lê a tabela ou o <ul> da seção da página, em
site/institucional.json. Mudou a lista na página, rode este script de novo e publique o PDF junto.

Identidade, do Manual de Identidade Institucional da Cruz Vermelha Brasileira, edição de 2022 (págs. 17 a 22):
  - cores primárias: vermelho RGB 227/34/25, cinza-escuro #1f2324 no texto e cinza #a7afb2 nos fios; da paleta
    secundária, o azul-marinho #011e41 nos subtítulos. O vermelho só em filete e detalhe: gasta pouca tinta e nada
    depende de cor;
  - Montserrat nos títulos e Open Sans no corpo do texto, as duas com licença OFL, em guias/fontes/;
  - o emblema só aparece dentro do logotipo, nunca solto como enfeite nem para marcar primeiros socorros.
Texto de 10 pt para cima (o detalhe de cada item da lista, 9,2 pt): o público tem mais de 50 anos e imprime em casa.

Uso: python3 scripts/gerar_guia_pdf.py <slug> [<slug>...]   (sem slug: todas as fichas de guias/)
     Precisa do Playwright/Chromium do ambiente; o logotipo vem do site no ar. Confira as fontes com `pdffonts`.
"""
from __future__ import annotations

import base64
import json
import os
import re
import subprocess
import sys
import tempfile
import urllib.request
from datetime import date
from html import escape as e, unescape
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
CONTEUDO = RAIZ / "site" / "institucional.json"
FICHAS = RAIZ / "guias"
FONTES = FICHAS / "fontes"
LOGO = "https://cruzvermelhariodejaneiro.org/assets/logo-cvb-rj.png"
SITE = "cruzvermelhariodejaneiro.org"
VERMELHO, ESCURO, MARINHO, CINZA = "#e32219", "#1f2324", "#011e41", "#a7afb2"

# O que não faz sentido no papel: a folha não tem link nem "mais abaixo". Achou, a ficha precisa de um "no_papel".
SO_NA_TELA = re.compile(r"\b(veja|clique|toque|abaixo|acima|nesta página|no link)\b", re.I)

PDF_JS = r"""
const { chromium } = require('playwright');
const [,, entrada, saida] = process.argv;
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage();
  await p.goto('file://' + entrada, { waitUntil: 'networkidle' });
  await p.evaluate(() => document.fonts.ready);
  await p.pdf({ path: saida, format: 'A4', printBackground: true, preferCSSPageSize: true });
  await b.close();
})();
"""


def texto(html: str) -> str:
    return re.sub(r"\s+", " ", unescape(re.sub(r"<[^>]+>", "", html))).strip()


def da_pagina(pagina: dict, origem: dict, no_papel: dict) -> list[tuple[str, ...]]:
    """As linhas da tabela (uma tupla por linha) ou os itens do <ul> (tuplas de um elemento) de uma seção da página."""
    html = next((s["html"] for s in pagina["secoes"] if s["id"] == origem["secao"]), None)
    if html is None:
        raise SystemExit(f'{pagina["slug"]}: a página não tem a seção "{origem["secao"]}"')
    if origem["formato"] == "tabela":
        corpo = re.search(r"<tbody>(.*?)</tbody>", html, re.S)
        linhas = [tuple(texto(c) for c in re.findall(r"<td>(.*?)</td>", l, re.S)) for l in re.findall(r"<tr>(.*?)</tr>", corpo.group(1), re.S)] if corpo else []
    else:
        ul = re.search(r"<ul>(.*?)</ul>", html, re.S)
        linhas = [(texto(li).rstrip(";."),) for li in re.findall(r"<li>(.*?)</li>", ul.group(1), re.S)] if ul else []
    if len(linhas) < 3:
        raise SystemExit(f'{pagina["slug"]}: não achei a lista na seção "{origem["secao"]}" (a tabela ou o <ul> mudaram de formato?)')

    def trocar(t: str) -> str:
        for de, para in no_papel.items():
            t = t.replace(de, para)
        return t.strip()
    return [tuple(trocar(c) for c in l) for l in linhas]


def q(conteudo: str) -> str:
    return f'<li><span class="q"></span><div>{conteudo}</div></li>'


def bloco(b: dict, pagina: dict) -> str:
    tipo = b["tipo"]
    titulo = f'<h2>{e(b["titulo"])}</h2>' if b.get("titulo") else ""
    if tipo == "lista_detalhada":  # item, quanto e por quê, com quadradinho: a lista principal, na folha 1
        linhas = da_pagina(pagina, b["da_pagina"], b.get("no_papel", {})) if "da_pagina" in b else [tuple(l) for l in b["itens"]]
        if any(len(l) != 3 for l in linhas):
            raise SystemExit(f'{pagina["slug"]}: a lista detalhada pede 3 colunas (item, quanto, detalhe)')
        itens = "\n".join(q(f'<b>{e(n)}</b> <span class="quanto">{e(qt)}</span><p>{e(d)}</p>') for n, qt, d in linhas)
        return f'{titulo}<ul class="marcar det">\n{itens}\n</ul>'
    if tipo == "lista_curta":  # só o item, em duas colunas
        linhas = [l[0] for l in da_pagina(pagina, b["da_pagina"], b.get("no_papel", {}))] if "da_pagina" in b else b["itens"]
        if b.get("sem_fonte"):  # tira o "(St John)" do fim de cada item: a fonte está na folha das fontes
            linhas = [re.sub(r"\s*\([^()]*\)$", "", i) for i in linhas]
        itens = "\n".join(q(e(i[0].upper() + i[1:])) for i in linhas)
        return f'{titulo}<ul class="marcar curta">\n{itens}\n</ul>'
    if tipo == "passos":  # o que fazer, em ordem
        return f'{titulo}<ol class="passos">\n' + "\n".join(f"<li>{e(i)}</li>" for i in b["itens"]) + "\n</ol>"
    if tipo == "aviso":  # um recado em destaque, de uma frase ou duas
        return f'<p class="aviso"><b>{e(b["rotulo"])}</b> {e(b["texto"])}</p>'
    if tipo == "datas":
        return f'{titulo}<p class="dica">{e(b["texto"])}</p><div class="linhas"><span>data</span><span>data</span><span>data</span><span>data</span></div>'
    if tipo == "familia_e_emergencia":  # a família preenche, os telefones já vêm. A ficha pode trocar o título e a dica
        encontro = ('<p class="dica" style="margin-top:3mm"><b>Nosso ponto de encontro:</b></p>\n    <div class="linhas"><span></span></div>'
                    if b.get("ponto_de_encontro", True) else "")
        dica = (f'<b>{e(b["dica"]["rotulo"])}</b> {e(b["dica"]["texto"])}' if "dica" in b
                else "<b>Alertas no celular:</b> envie um SMS com o seu CEP para <b>40199</b>.")
        vazias = "\n".join("<tr><td></td><td></td></tr>" for _ in range(4 if b.get("ponto_de_encontro", True) else 3))
        return f"""<div class="duas">
  <div>
    <h2>{e(b.get("titulo", "Telefones da família"))}</h2>
    <table><thead><tr><th>Nome</th><th>Telefone</th></tr></thead><tbody>
{vazias}
    </tbody></table>
    {encontro}
  </div>
  <div class="caixa">
    <h2>Em caso de emergência</h2>
    <ul class="tel"><li><b>192</b> SAMU</li><li><b>193</b> Corpo de Bombeiros</li><li><b>199</b> Defesa Civil</li></ul>
    <p class="dica">{dica}</p>
  </div>
</div>"""
    if tipo == "checklist":  # o que fazer, com quadradinho para marcar: título e uma linha de detalhe (sem a coluna "quanto")
        itens = "\n".join(q(f'<b>{e(t)}</b><p>{e(d)}</p>' if d else f'<b>{e(t)}</b>') for t, d in b["itens"])
        return f'{titulo}<ul class="marcar det">\n{itens}\n</ul>'
    if tipo == "campos":  # rótulos com uma linha para escrever ao lado: "Escola", "Ponto de encontro"
        itens = "\n".join(f'<p class="campo"><b>{e(r)}</b><span></span></p>' for r in b["itens"])
        return f'{titulo}<div class="campos">\n{itens}\n</div>'
    if tipo == "ficha":  # tabela para preencher: colunas e linhas em branco, ou a 1ª coluna já escrita (rotulos)
        colunas = b["colunas"]
        rotulos = b.get("rotulos") or [""] * b.get("linhas", 6)
        altura = f' style="height:{b["altura_mm"]}mm"' if b.get("altura_mm") else ""
        cab = "".join(f"<th>{e(c)}</th>" for c in colunas)
        linhas = "\n".join(f'<tr{altura}><td class="rot">{e(r)}</td>' + "<td></td>" * (len(colunas) - 1) + "</tr>" for r in rotulos)
        return f'{titulo}<table class="ficha"><thead><tr>{cab}</tr></thead><tbody>\n{linhas}\n</tbody></table>'
    if tipo == "perguntas":  # perguntas numeradas, com uma linha de explicação e linhas para a resposta
        vazias = '<span class="linha"></span>' * b.get("linhas", 2)
        itens = "\n".join(f'<li><b>{e(t)}</b><p>{e(d)}</p>{vazias}</li>' for t, d in b["itens"])
        return f'{titulo}<ol class="perguntas">\n{itens}\n</ol>'
    if tipo == "emergencia":  # o cartaz: passos à esquerda, telefones (e um campo para preencher) à direita
        passos = "\n".join(f"<li>{e(i)}</li>" for i in b["passos"])
        tels = "".join(f"<li><b>{e(n)}</b> {e(o)}</li>" for n, o in b["telefones"])
        campo = (f'<p class="dica"><b>{e(b["campo"])}</b></p><div class="linhas"><span></span></div><div class="linhas"><span></span></div>'
                 if b.get("campo") else "")
        dica = f'<p class="dica"><b>{e(b["dica"]["rotulo"])}</b> {e(b["dica"]["texto"])}</p>' if b.get("dica") else ""
        return f"""<div class="cartaz">
  <h2>{e(b["titulo"])}</h2>
  <div class="duas">
    <ol class="passos">
{passos}
    </ol>
    <div>
      <ul class="tel">{tels}</ul>
      {dica}
      {campo}
    </div>
  </div>
</div>"""
    if tipo == "curso":  # o convite, igual em todos: diz que o curso é pago e quem vende (regra do Ad Grants)
        return (f'<p class="curso"><b>A lista ajuda, mas é a pessoa que socorre.</b> Aprenda primeiros socorros na prática, no Palácio da '
                f'Cruz Vermelha, no Centro do Rio. Cursos presenciais pagos, vendidos pela escola (O-CVB Filial Rio de Janeiro Ensino Ltda): '
                f'{SITE}/matricula-cursos-presenciais</p>')
    raise SystemExit(f'{pagina["slug"]}: bloco de tipo desconhecido: {tipo}')


def fontes_css() -> str:
    regras = []
    for familia, arquivo, pesos in (("Montserrat", "montserrat", (700, 800)), ("Open Sans", "open-sans", (400, 600, 700))):
        for peso in pesos:
            dados = base64.b64encode((FONTES / f"{arquivo}-latin-{peso}-normal.woff2").read_bytes()).decode()
            regras.append(f'@font-face {{ font-family: "{familia}"; font-weight: {peso}; src: url(data:font/woff2;base64,{dados}) format("woff2"); }}')
    return "\n".join(regras)


def documento(ficha: dict, pagina: dict, logo_b64: str) -> str:
    total = len(ficha["folhas"]) + 1  # a última é sempre a das fontes
    endereco = f'{SITE}/{ficha["slug"]}'
    atualizado = date.fromisoformat(pagina["atualizado"])
    folhas = []
    for n, folha in enumerate(ficha["folhas"], 1):
        abre = f'<h1>{e(ficha["titulo"])}</h1>\n<p class="sub">{e(ficha["sub"])}</p>' if n == 1 else ""
        pe = (f'<p class="pe">Cruz Vermelha Brasileira – Filial do Estado do Rio de Janeiro · {e(ficha["credito"])}; fontes '
              f'na folha {total} e em {endereco} · Atualizado em {atualizado:%d/%m/%Y} · Pode copiar e distribuir, sem alterar.</p>') if n == total - 1 else ""
        corpo = "\n".join(bloco(b, pagina) for b in folha["blocos"])
        folhas.append(f"""<section class="folha">
<header><img src="data:image/png;base64,{logo_b64}" alt="Cruz Vermelha Brasileira – Rio de Janeiro"><span><i>Guia gratuito</i>{endereco} · folha {n} de {total}</span></header>
{abre}
{corpo}
{pe}
</section>""")
    # A folha das fontes: todas as da página, com o endereço por extenso (no papel não há link para tocar).
    lista = "\n".join(f'<li><b>{e(f["rotulo"])}</b><span>{e(f["url"])}</span></li>' for f in pagina["fontes"])
    folhas.append(f"""<section class="folha fontes">
<header><img src="data:image/png;base64,{logo_b64}" alt="Cruz Vermelha Brasileira – Rio de Janeiro"><span><i>Guia gratuito</i>{endereco} · folha {total} de {total}</span></header>
<h2>Fontes deste guia</h2>
<p class="dica">Tudo o que está nas folhas anteriores vem destas {len(pagina["fontes"])} fontes públicas. O guia completo, atualizado em {atualizado:%d/%m/%Y}, mostra o que veio de cada uma: {endereco}.</p>
<ol>
{lista}
</ol>
</section>""")
    return f"""<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8">
<title>{e(ficha["titulo"])} – Cruz Vermelha Brasileira, Rio de Janeiro</title>
<style>
{fontes_css()}
  @page {{ size: A4; margin: 13mm 14mm 12mm; }}
  * {{ box-sizing: border-box; }}
  html {{ -webkit-print-color-adjust: exact; print-color-adjust: exact; }}
  body {{ margin: 0; font: 10.4pt/1.35 "Open Sans", Arial, "Liberation Sans", sans-serif; color: {ESCURO}; }}
  h1, h2, header span, .tel b, .passos li::before {{ font-family: "Montserrat", Arial, "Liberation Sans", sans-serif; }}
  .folha + .folha {{ break-before: page; }}
  header {{ display: flex; align-items: center; justify-content: space-between; padding-bottom: 3mm; border-bottom: 1.2mm solid {VERMELHO}; }}
  header img {{ height: 13mm; width: auto; }}
  header span {{ font-size: 8.8pt; font-weight: 700; text-align: right; }}
  header i {{ display: block; font-style: normal; font-weight: 800; font-size: 9pt; letter-spacing: .12em; text-transform: uppercase; color: {VERMELHO}; }}
  h1 {{ font-size: 20pt; font-weight: 800; line-height: 1.12; margin: 5mm 0 1.5mm; letter-spacing: -.01em; }}
  .sub {{ font-size: 10.8pt; margin: 0 0 3mm; }}
  h2 {{ font-size: 12.5pt; font-weight: 700; color: {MARINHO}; margin: 3.4mm 0 1.5mm; padding-bottom: 1mm; border-bottom: .3mm solid {CINZA}; }}
  .folha > h2:first-of-type {{ margin-top: 4.5mm; }}
  ul.marcar {{ list-style: none; margin: 0; padding: 0; }}
  ul.marcar li {{ display: flex; gap: 3mm; align-items: flex-start; break-inside: avoid; }}
  .q {{ flex: 0 0 5.2mm; height: 5.2mm; border: .5mm solid {ESCURO}; border-radius: .8mm; margin-top: .6mm; }}
  .det li {{ padding: 1.3mm 0; border-bottom: .2mm solid {CINZA}; }}
  .det li:last-child {{ border-bottom: 0; }}
  .det b {{ font-size: 10.8pt; }}
  .quanto {{ font-size: 10pt; }}
  .quanto::before {{ content: "· "; }}
  .det p {{ margin: .2mm 0 0; font-size: 9.2pt; line-height: 1.3; }}
  .curta {{ columns: 2; column-gap: 8mm; }}
  .curta li {{ padding: .6mm 0; font-size: 10.2pt; }}
  .passos {{ list-style: none; margin: 0; padding: 0; counter-reset: passo; }}
  .passos li {{ counter-increment: passo; display: flex; gap: 3mm; align-items: baseline; padding: .5mm 0; font-size: 10.4pt; break-inside: avoid; }}
  .passos li::before {{ content: counter(passo); flex: 0 0 5.2mm; font-weight: 800; font-size: 12pt; color: {VERMELHO}; text-align: center; }}
  .aviso {{ margin: 2.5mm 0 0; padding: 2mm 3.5mm; border-left: 1.2mm solid {VERMELHO}; background: #f4f4f4; font-size: 10.4pt; }}
  .caixa {{ border: .4mm solid {ESCURO}; border-radius: 2mm; padding: 2.5mm 4mm; align-self: start; }}
  .caixa h2 {{ border: 0; padding: 0; }}
  .duas {{ display: grid; grid-template-columns: 1.15fr 1fr; gap: 6mm; margin-top: 3.5mm; }}
  .duas h2 {{ margin-top: 0; }}
  table {{ width: 100%; border-collapse: collapse; }}
  th {{ text-align: left; font-size: 9.2pt; font-weight: 600; padding: 0 0 1mm; }}
  td {{ height: 6.6mm; border-bottom: .3mm solid {ESCURO}; }}
  td + td, th + th {{ width: 42%; padding-left: 4mm; }}
  .tel {{ margin: 0; padding: 0; list-style: none; }}
  .tel li {{ display: flex; align-items: baseline; gap: 3mm; padding: .5mm 0; font-size: 10.4pt; }}
  .tel b {{ font-size: 14pt; font-weight: 800; min-width: 15mm; }}
  .linhas {{ display: flex; gap: 5mm; margin-top: 1mm; }}
  .linhas span {{ flex: 1; border-bottom: .3mm solid {ESCURO}; height: 7mm; font-size: 9pt; }}
  p {{ margin: 0 0 2mm; }}
  .dica {{ font-size: 10pt; }}
  .caixa .dica {{ margin: 2mm 0 0; }}
  .curso {{ margin-top: 3mm; font-size: 10pt; }}
  .fontes ol {{ margin: 2mm 0 0; padding-left: 6mm; font-size: 8.6pt; line-height: 1.3; }}
  .fontes li {{ padding: .7mm 0; break-inside: avoid; }}
  .fontes li b {{ font-weight: 600; }}
  .fontes li span {{ display: block; font-size: 7.4pt; overflow-wrap: anywhere; }}
  .campos {{ display: flex; flex-wrap: wrap; gap: 1mm 6mm; margin: 1mm 0 2mm; }}
  .campo {{ flex: 1 1 60mm; display: flex; align-items: flex-end; gap: 2mm; margin: 0; font-size: 10.4pt; }}
  .campo span {{ flex: 1; border-bottom: .3mm solid {ESCURO}; height: 6.6mm; }}
  table.ficha th {{ border-bottom: .4mm solid {ESCURO}; padding-right: 2mm; }}
  table.ficha td + td, table.ficha th + th {{ width: auto; padding-left: 2mm; }}
  table.ficha td.rot {{ font-weight: 600; font-size: 9.8pt; vertical-align: bottom; padding-bottom: .8mm; width: 34%; }}
  .perguntas {{ list-style: none; margin: 0; padding: 0; counter-reset: pergunta; }}
  .perguntas li {{ counter-increment: pergunta; padding: .9mm 0 0; break-inside: avoid; }}
  .perguntas li > b {{ font-size: 10.8pt; }}
  .perguntas li > b::before {{ content: counter(pergunta) ". "; color: {VERMELHO}; font-family: "Montserrat", Arial, sans-serif; font-weight: 800; }}
  .perguntas p {{ margin: .2mm 0 0; font-size: 9.2pt; line-height: 1.3; }}
  .perguntas .linha {{ display: block; height: 5.4mm; border-bottom: .3mm solid {ESCURO}; }}
  .cartaz {{ margin-top: 4mm; padding: 3mm 4.5mm; border: .8mm solid {VERMELHO}; border-radius: 3mm; }}
  .cartaz h2 {{ border: 0; padding: 0; margin: 0 0 1.5mm; font-size: 15pt; }}
  .cartaz .duas {{ margin-top: 0; }}
  .pe {{ margin: 2.5mm 0 0; padding-top: 1.5mm; border-top: .3mm solid {CINZA}; font-size: 7.8pt; line-height: 1.3; }}
</style></head>
<body>
{chr(10).join(folhas)}
</body></html>"""


def gerar(slug: str, paginas: dict, logo_b64: str) -> bool:
    ficha = json.loads((FICHAS / f"{slug}.json").read_text(encoding="utf-8"))
    if ficha["slug"] != slug or slug not in paginas:
        raise SystemExit(f"{slug}: a ficha não bate com o nome do arquivo, ou a página não existe em site/institucional.json")
    html = documento(ficha, paginas[slug], logo_b64)
    corpo = texto(re.sub(r'<(style|title|header)\b.*?</\1>|<section class="folha fontes">.*?</section>', "", html, flags=re.S))
    sobras = sorted({m.group(0).lower() for m in SO_NA_TELA.finditer(corpo)})
    saida = RAIZ / "site" / slug / ficha["arquivo"]
    with tempfile.TemporaryDirectory() as tmp:
        entrada, runner = Path(tmp) / "guia.html", Path(tmp) / "pdf.js"
        entrada.write_text(html, encoding="utf-8")
        runner.write_text(PDF_JS, encoding="utf-8")
        ambiente = dict(os.environ)
        if "NODE_PATH" not in ambiente:
            ambiente["NODE_PATH"] = subprocess.run(["npm", "root", "-g"], capture_output=True, text=True).stdout.strip()
        subprocess.run(["node", str(runner), str(entrada), str(saida)], check=True, env=ambiente)
    folhas = len(re.findall(rb"/Type\s*/Page\b", saida.read_bytes()))
    print(f"gravado {saida.relative_to(RAIZ)} ({saida.stat().st_size} bytes, {folhas} folhas)")
    if ficha.get("capa"):  # a folha 1 em 480 x 679 (a proporção do A4), que a página mostra ao lado do formulário
        from io import BytesIO
        from PIL import Image
        png = subprocess.run(["pdftoppm", "-f", "1", "-l", "1", "-png", "-scale-to-x", "480", "-scale-to-y", "-1", str(saida)],
                             capture_output=True, check=True).stdout
        capa = RAIZ / "site" / slug / ficha["capa"]
        Image.open(BytesIO(png)).convert("RGB").resize((480, 679)).save(capa, "WEBP", quality=80, method=6)
        print(f"gravado {capa.relative_to(RAIZ)} (capa)")
    certo = True
    esperadas = len(ficha["folhas"]) + 1
    if folhas != esperadas:
        print(f'  ATENÇÃO: saíram {folhas or "?"} folhas, e são {esperadas} (as da ficha e a das fontes). Encurte o texto antes de publicar.')
        certo = False
    if sobras:
        print(f'  ATENÇÃO: texto de tela no papel ({", ".join(sobras)}). Acerte com "no_papel", na ficha.')
        certo = False
    return certo


def main() -> int:
    slugs = sys.argv[1:] or sorted(f.stem for f in FICHAS.glob("*.json"))
    paginas = {p["slug"]: p for p in json.loads(CONTEUDO.read_text(encoding="utf-8"))["paginas"]}
    pedido = urllib.request.Request(LOGO, headers={"User-Agent": "gerar_guia_pdf.py"})
    logo_b64 = base64.b64encode(urllib.request.urlopen(pedido, timeout=30).read()).decode()
    return 0 if all([gerar(s, paginas, logo_b64) for s in slugs]) else 1


if __name__ == "__main__":
    sys.exit(main())
