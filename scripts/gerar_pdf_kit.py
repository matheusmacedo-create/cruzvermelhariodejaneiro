#!/usr/bin/env python3
"""Gera a lista do kit de emergência em PDF, para imprimir (09/11/2026).

Saída: site/kit-de-emergencia/kit-de-emergencia-cruz-vermelha-rj.pdf (A4, 2 folhas).
  Folha 1: o kit de emergência, item por item, com quadradinho para marcar.
  Folha 2: o kit de primeiros socorros, o que acrescentar, os telefones da família (para preencher à mão), os
           telefones de emergência e as datas de conferência.

As duas listas NÃO ficam aqui: saem de site/institucional.json, da página /kit-de-emergencia/ (a tabela da seção
"lista-emergencia" e o <ul> da seção "lista-primeiros-socorros"). Mudou a lista na página, rode este script de novo
e publique o PDF junto. O texto fixo das duas folhas (título, telefones, rodapé) fica neste arquivo.

Pensado para quem tem mais de 50 anos e vai imprimir em casa ou na lan house: letra de 11 pt para cima, alto
contraste, pouco vermelho (gasta menos tinta) e nada que dependa de cor para ser entendido.

Uso: python3 scripts/gerar_pdf_kit.py   (precisa do Playwright/Chromium do ambiente; o logotipo vem do site no ar).
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
from html import escape, unescape
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
CONTEUDO = RAIZ / "site" / "institucional.json"
SAIDA = RAIZ / "site" / "kit-de-emergencia" / "kit-de-emergencia-cruz-vermelha-rj.pdf"
LOGO = "https://cruzvermelhariodejaneiro.org/assets/logo-cvb-rj.png"
ENDERECO = "cruzvermelhariodejaneiro.org/kit-de-emergencia"

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
    return unescape(re.sub(r"<[^>]+>", "", html)).strip()


def listas() -> tuple[list[tuple[str, str, str]], list[str], date]:
    dados = json.loads(CONTEUDO.read_text(encoding="utf-8"))
    pagina = next(p for p in dados["paginas"] if p["slug"] == "kit-de-emergencia")
    secoes = {s["id"]: s["html"] for s in pagina["secoes"]}
    corpo = re.search(r"<tbody>(.*?)</tbody>", secoes["lista-emergencia"], re.S).group(1)
    kit = [tuple(texto(c) for c in re.findall(r"<td>(.*?)</td>", linha, re.S)) for linha in re.findall(r"<tr>(.*?)</tr>", corpo, re.S)]
    ul = re.search(r"<ul>(.*?)</ul>", secoes["lista-primeiros-socorros"], re.S).group(1)
    socorros = [texto(li).rstrip(";.") for li in re.findall(r"<li>(.*?)</li>", ul, re.S)]
    if len(kit) < 10 or any(len(l) != 3 for l in kit) or len(socorros) < 10:
        raise SystemExit("não achei as duas listas na página do kit (a tabela ou o <ul> mudaram de formato?)")
    return kit, [s[0].upper() + s[1:] for s in socorros], date.fromisoformat(pagina["atualizado"])


def pagina_html(kit: list[tuple[str, str, str]], socorros: list[str], atualizado: date, logo_b64: str) -> str:
    e = escape
    # No papel, "veja a seção…" não leva a lugar nenhum: a folha diz onde a coisa está nela mesma.
    troca = {"Veja a lista mais abaixo.": "A lista está na folha 2.",
             "Se couber, guarde 4 litros: veja a seção sobre a água.": "Se couber, guarde 4 litros, para a higiene e a comida."}
    def porque(t: str) -> str:
        for de, para in troca.items():
            t = t.replace(de, para)
        return t
    itens = "\n".join(
        f'<li><span class="q"></span><div><b>{e(nome)}</b> <span class="quanto">{e(quanto)}</span><p>{e(porque(pq))}</p></div></li>'
        for nome, quanto, pq in kit)
    ps = "\n".join(f'<li><span class="q"></span><div>{e(s)}</div></li>' for s in socorros)
    linhas = "\n".join('<tr><td></td><td></td></tr>' for _ in range(4))
    def cabeca(n: int) -> str:
        return f'<header><img src="data:image/png;base64,{logo_b64}" alt="Cruz Vermelha Brasileira – Rio de Janeiro"><span>{ENDERECO} · folha {n} de 2</span></header>'
    return f"""<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8">
<title>Kit de emergência: lista para conferir – Cruz Vermelha Brasileira, Rio de Janeiro</title>
<style>
  @page {{ size: A4; margin: 13mm 14mm 12mm; }}
  * {{ box-sizing: border-box; }}
  html {{ -webkit-print-color-adjust: exact; print-color-adjust: exact; }}
  body {{ margin: 0; font: 11pt/1.35 Inter, Arial, "Liberation Sans", sans-serif; color: #111; }}
  .folha + .folha {{ break-before: page; }}
  header {{ display: flex; align-items: center; justify-content: space-between; padding-bottom: 3mm; border-bottom: 1.2mm solid #cc0000; }}
  header img {{ height: 13mm; width: auto; }}
  header span {{ font-size: 10pt; font-weight: 600; }}
  h1 {{ font-size: 21pt; line-height: 1.15; margin: 5mm 0 1.5mm; letter-spacing: -.01em; }}
  .sub {{ font-size: 11.5pt; margin: 0 0 3.5mm; color: #333; }}
  h2 {{ font-size: 13.5pt; margin: 3.6mm 0 1.6mm; padding-bottom: 1mm; border-bottom: .3mm solid #999; }}
  ul.marcar {{ list-style: none; margin: 0; padding: 0; }}
  ul.marcar li {{ display: flex; gap: 3mm; align-items: flex-start; break-inside: avoid; }}
  .q {{ flex: 0 0 5.2mm; height: 5.2mm; border: .5mm solid #111; border-radius: .8mm; margin-top: .6mm; }}
  .kit li {{ padding: 1.3mm 0; border-bottom: .2mm solid #ccc; }}
  .kit li:last-child {{ border-bottom: 0; }}
  .kit b {{ font-size: 11.5pt; }}
  .quanto {{ font-size: 10.5pt; color: #333; }}
  .quanto::before {{ content: "· "; }}
  .kit p {{ margin: .3mm 0 0; font-size: 9.6pt; line-height: 1.3; color: #333; }}
  .ps {{ columns: 2; column-gap: 8mm; }}
  .ps li {{ padding: .8mm 0; font-size: 10.6pt; }}
  .caixa {{ border: .4mm solid #111; border-radius: 2mm; padding: 3mm 4mm; }}
  .duas {{ display: grid; grid-template-columns: 1.15fr 1fr; gap: 6mm; }}
  table {{ width: 100%; border-collapse: collapse; }}
  th {{ text-align: left; font-size: 9.5pt; font-weight: 600; color: #333; padding: 0 0 1mm; }}
  td {{ height: 7mm; border-bottom: .3mm solid #555; }}
  td + td, th + th {{ width: 42%; padding-left: 4mm; }}
  .tel {{ margin: 0; padding: 0; list-style: none; }}
  .tel li {{ display: flex; align-items: baseline; gap: 3mm; padding: .9mm 0; font-size: 11pt; }}
  .tel b {{ font-size: 16pt; min-width: 16mm; }}
  .datas {{ display: flex; gap: 5mm; margin-top: 1mm; }}
  .datas span {{ flex: 1; border-bottom: .3mm solid #555; height: 7mm; font-size: 9.5pt; color: #555; }}
  p {{ margin: 0 0 2mm; }}
  .pe {{ margin-top: 3mm; padding-top: 2mm; border-top: .3mm solid #999; font-size: 8.2pt; line-height: 1.3; color: #333; }}
  .dica {{ font-size: 10.5pt; }}
</style></head>
<body>
<section class="folha">
  {cabeca(1)}
  <h1>Kit de emergência: lista para conferir</h1>
  <p class="sub">Marque com um X o que já está separado. Guarde tudo numa mochila ou caixa, perto da saída, num lugar que a família inteira conheça. O kit é para os 3 primeiros dias (72 horas).</p>
  <ul class="marcar kit">
{itens}
  </ul>
</section>
<section class="folha">
  {cabeca(2)}
  <h2 style="margin-top:5mm">Kit de primeiros socorros (para uma família de 4 pessoas)</h2>
  <ul class="marcar ps">
{ps}
  </ul>
  <p class="dica" style="margin-top:2mm"><b>Remédios:</b> guarde só os de uso contínuo da família, com a lista de nome, dose e horário. Outros remédios, só com orientação de um profissional de saúde. Na tampa da caixa, use a cruz branca sobre fundo verde ou escreva “Primeiros socorros”.</p>

  <h2>Acrescente o que a sua casa precisa</h2>
  <ul class="marcar ps">
    <li><span class="q"></span><div>Bebês: fraldas, mamadeira, leite ou fórmula</div></li>
    <li><span class="q"></span><div>Idosos: óculos reserva e aparelho auditivo</div></li>
    <li><span class="q"></span><div>Animais: ração, água e guia ou caixa</div></li>
    <li><span class="q"></span><div>Cartões de vacina e de benefícios</div></li>
  </ul>

  <div class="duas" style="margin-top:3.5mm">
    <div>
      <h2 style="margin-top:0">Telefones da família</h2>
      <table><thead><tr><th>Nome</th><th>Telefone</th></tr></thead><tbody>
{linhas}
      </tbody></table>
      <p class="dica" style="margin-top:3mm"><b>Nosso ponto de encontro:</b></p>
      <div class="datas"><span></span></div>
    </div>
    <div class="caixa">
      <h2 style="margin-top:0;border:0;padding:0">Em caso de emergência</h2>
      <ul class="tel">
        <li><b>192</b> SAMU</li>
        <li><b>193</b> Corpo de Bombeiros</li>
        <li><b>199</b> Defesa Civil</li>
      </ul>
      <p class="dica" style="margin:2mm 0 0"><b>Alertas no celular:</b> envie um SMS com o seu CEP para <b>40199</b>. É gratuito.</p>
    </div>
  </div>

  <h2>Conferimos o kit em</h2>
  <p class="dica">Troque o que venceu ou foi usado. Uma boa hora é o início do verão, antes das chuvas fortes.</p>
  <div class="datas"><span>data</span><span>data</span><span>data</span><span>data</span></div>

  <p class="dica" style="margin-top:3.5mm"><b>O kit ajuda, mas é a pessoa que socorre.</b> Aprenda primeiros socorros na prática, no Palácio da Cruz Vermelha, no Centro do Rio. Cursos presenciais pagos, vendidos pela escola (O-CVB Filial Rio de Janeiro Ensino Ltda): cruzvermelhariodejaneiro.org/matricula-cursos-presenciais</p>

  <p class="pe">Cruz Vermelha Brasileira – Filial do Estado do Rio de Janeiro · Listas adaptadas da Cruz Vermelha Americana e da Defesa Civil; fontes em {ENDERECO} · Atualizado em {atualizado:%d/%m/%Y} · Pode copiar e distribuir, sem alterar.</p>
</section>
</body></html>"""


def main() -> int:
    kit, socorros, atualizado = listas()
    pedido = urllib.request.Request(LOGO, headers={"User-Agent": "gerar_pdf_kit.py"})
    logo_b64 = base64.b64encode(urllib.request.urlopen(pedido, timeout=30).read()).decode()
    with tempfile.TemporaryDirectory() as tmp:
        entrada, runner = Path(tmp) / "kit.html", Path(tmp) / "pdf.js"
        entrada.write_text(pagina_html(kit, socorros, atualizado, logo_b64), encoding="utf-8")
        runner.write_text(PDF_JS, encoding="utf-8")
        ambiente = dict(os.environ)
        if "NODE_PATH" not in ambiente:
            ambiente["NODE_PATH"] = subprocess.run(["npm", "root", "-g"], capture_output=True, text=True).stdout.strip()
        subprocess.run(["node", str(runner), str(entrada), str(SAIDA)], check=True, env=ambiente)
    print(f"gravado {SAIDA.relative_to(RAIZ)} ({SAIDA.stat().st_size} bytes; {len(kit)} itens no kit, {len(socorros)} nos primeiros socorros)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
