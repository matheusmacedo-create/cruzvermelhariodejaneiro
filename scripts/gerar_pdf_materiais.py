#!/usr/bin/env python3
"""Gera os dois materiais em PDF das páginas para instituições, e a capa de cada um (11/11/2026).

Saídas (A4, 2 folhas cada, mais a capa em WebP de 480 px que a página mostra ao lado do formulário):
  site/lei-lucas-para-escolas/kit-lei-lucas-escolas-cruz-vermelha-rj.pdf  + capa-kit-lei-lucas.webp
      Folha 1: checklist de adequação à Lei Lucas.
      Folha 2: ficha dos profissionais capacitados (para afixar, como pede o art. 4º da lei) e o cartaz de emergência
               da sala dos professores.
  site/plano-de-contingencia/roteiro-plano-de-contingencia-cruz-vermelha-rj.pdf  + capa-roteiro-plano-de-contingencia.webp
      Folha 1: as perguntas que o plano precisa responder, com linhas para preencher.
      Folha 2: a ficha de quem faz o quê, os níveis de alerta e os telefones.

O texto mora aqui (é do papel, não da página). Mudou o texto na página (site/institucional.json)? Confira se o papel
ainda diz o mesmo. Mesmas escolhas da lista do kit (scripts/gerar_pdf_kit.py): letra de 10 pt para cima, alto contraste,
pouco vermelho, Arial/Liberation Sans (TrueType; a Inter sai Type 3) e o logotipo do site no ar.

Os materiais são entregues pela Redação (lib/newsletter/materiais.ts, ids lei-lucas-escolas e plano-de-contingencia):
o nome dos arquivos não muda sem mudar lá também.

Uso: python3 scripts/gerar_pdf_materiais.py   (Playwright/Chromium do ambiente, pdftoppm e Pillow)
"""
from __future__ import annotations

import base64
import io
import os
import re
import subprocess
import sys
import tempfile
import urllib.request
from datetime import date
from pathlib import Path

from PIL import Image

RAIZ = Path(__file__).resolve().parent.parent
LOGO = "https://cruzvermelhariodejaneiro.org/assets/logo-cvb-rj.png"
HOJE = date(2026, 11, 11)

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

CSS = """
  @page { size: A4; margin: 13mm 14mm 12mm; }
  * { box-sizing: border-box; }
  html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { margin: 0; font: 11pt/1.35 Arial, "Liberation Sans", Arimo, sans-serif; color: #111; }
  .folha + .folha { break-before: page; }
  header { display: flex; align-items: center; justify-content: space-between; padding-bottom: 3mm; border-bottom: 1.2mm solid #cc0000; }
  header img { height: 13mm; width: auto; }
  header span { font-size: 10pt; font-weight: 600; }
  h1 { font-size: 20pt; line-height: 1.15; margin: 5mm 0 1.5mm; letter-spacing: -.01em; }
  .sub { font-size: 11pt; margin: 0 0 3mm; color: #333; }
  h2 { font-size: 13pt; margin: 4mm 0 1.6mm; padding-bottom: 1mm; border-bottom: .3mm solid #999; }
  ul.marcar { list-style: none; margin: 0; padding: 0; }
  ul.marcar li { display: flex; gap: 3mm; align-items: flex-start; padding: 1.6mm 0; border-bottom: .2mm solid #ccc; break-inside: avoid; }
  ul.marcar li:last-child { border-bottom: 0; }
  .q { flex: 0 0 5.2mm; height: 5.2mm; border: .5mm solid #111; border-radius: .8mm; margin-top: .4mm; }
  ul.marcar b { font-size: 11.2pt; }
  ul.marcar p { margin: .4mm 0 0; font-size: 9.8pt; line-height: 1.3; color: #333; }
  ol.perg { margin: 0; padding: 0; list-style: none; counter-reset: p; }
  ol.perg li { counter-increment: p; padding: .9mm 0 0; break-inside: avoid; }
  ol.perg li > b::before { content: counter(p) ". "; }
  ol.perg li > b { font-size: 11.2pt; }
  ol.perg li p { margin: .3mm 0 0; font-size: 9.6pt; color: #333; }
  .linha { height: 6.6mm; border-bottom: .3mm solid #555; }
  ol.perg .linha { height: 5.4mm; }
  .caixa { border: .4mm solid #111; border-radius: 2mm; padding: 3mm 4mm; }
  table { width: 100%; border-collapse: collapse; }
  th { text-align: left; font-size: 9.5pt; font-weight: 600; color: #333; padding: 0 2mm 1mm 0; border-bottom: .4mm solid #111; }
  td { height: 7.4mm; border-bottom: .3mm solid #555; padding: 0 2mm 0 0; font-size: 10pt; vertical-align: bottom; }
  td.f { font-weight: 600; font-size: 9.8pt; width: 34%; }
  .tel { margin: 0; padding: 0; list-style: none; }
  .tel li { display: flex; align-items: baseline; gap: 3mm; padding: .7mm 0; font-size: 11pt; }
  .tel b { font-size: 16pt; min-width: 16mm; }
  .duas { display: grid; grid-template-columns: 1.2fr 1fr; gap: 6mm; }
  .passos { margin: 0; padding-left: 6mm; }
  .passos li { margin: 0 0 1.6mm; font-size: 11pt; }
  p { margin: 0 0 2mm; }
  .dica { font-size: 10pt; }
  .pe { margin-top: 3mm; padding-top: 2mm; border-top: .3mm solid #999; font-size: 8.2pt; line-height: 1.3; color: #333; }
  .cartaz { border: .8mm solid #cc0000; border-radius: 3mm; padding: 3.5mm 5mm; margin-top: 4mm; }
  .cartaz h2 { border: 0; margin-top: 0; padding: 0; font-size: 16pt; }
"""


def cabeca(logo: str, endereco: str, n: int) -> str:
    return f'<header><img src="data:image/png;base64,{logo}" alt="Cruz Vermelha Brasileira – Rio de Janeiro"><span>{endereco} · folha {n} de 2</span></header>'


def marcar(itens: list[tuple[str, str]]) -> str:
    return "\n".join(f'<li><span class="q"></span><div><b>{t}</b>{f"<p>{d}</p>" if d else ""}</div></li>' for t, d in itens)


def lei_lucas(logo: str) -> str:
    end = "cruzvermelhariodejaneiro.org/lei-lucas-para-escolas"
    checklist = marcar([
        ("Listamos todos que lidam com as crianças", "Professores, auxiliares, monitores, portaria, cozinha, limpeza e transporte."),
        ("Escolhemos quem será capacitado", "Com gente treinada em todos os turnos, no recreio, no transporte e nos passeios."),
        ("Conferimos a regra do nosso município", "Com a secretaria de educação ou o órgão que autoriza o funcionamento da escola."),
        ("Agendamos a capacitação presencial", "Com prática em manequim de criança e de bebê: engasgo e RCP não se aprendem só com vídeo."),
        ("Montamos os kits de primeiros socorros", "Um na secretaria e um portátil para os passeios. Conferimos a validade de tudo."),
        ("Afixamos a ficha dos capacitados", "Com o certificado, num lugar que pais e fiscalização vejam (folha 2)."),
        ("Combinamos quem faz o quê numa emergência", "Quem atende, quem liga para o 192, quem avisa a família, quem acompanha a criança."),
        ("Deixamos a ficha de saúde de cada criança à mão", "Alergias, remédios de uso contínuo e telefone dos responsáveis."),
        ("Avisamos as famílias", "Que a equipe foi capacitada e como a escola age numa emergência."),
        ("Marcamos a reciclagem para daqui a um ano", "A lei prevê o curso todo ano. Data marcada: ____/____/________"),
    ])
    linhas = "\n".join("<tr><td></td><td></td><td></td></tr>" for _ in range(7))
    return f"""<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8">
<title>Kit Lei Lucas para escolas – Cruz Vermelha Brasileira, Rio de Janeiro</title>
<style>{CSS}</style></head>
<body>
<section class="folha">
  {cabeca(logo, end, 1)}
  <h1>Lei Lucas na escola: checklist de adequação</h1>
  <p class="sub">A Lei 13.722/2018 obriga escolas de educação básica, públicas e privadas, e espaços de recreação infantil a capacitar professores e funcionários em noções básicas de primeiros socorros. Marque com um X o que a sua escola já fez.</p>
  <ul class="marcar">
{checklist}
  </ul>
  <div class="caixa" style="margin-top:3mm">
    <p class="dica" style="margin:0"><b>O que a lei pede, em resumo:</b> capacitação todo ano (curso e reciclagem); gente capacitada na proporção da equipe; kits de primeiros socorros; e a comprovação, com o nome dos capacitados, afixada em lugar visível. Quem não cumpre está sujeito a notificação, multa e, na reincidência, à cassação do alvará (escolas particulares) ou à responsabilização do agente público (escolas públicas).</p>
  </div>
  <p class="pe">Cruz Vermelha Brasileira – Filial do Estado do Rio de Janeiro · Material de orientação, não substitui a lei nem as regras do seu município · Lei completa e fontes em {end} · {HOJE:%d/%m/%Y} · Pode copiar e distribuir, sem alterar.</p>
</section>
<section class="folha">
  {cabeca(logo, end, 2)}
  <h1 style="font-size:17pt">Profissionais capacitados em primeiros socorros</h1>
  <p class="sub">Lei 13.722/2018 (Lei Lucas). Afixe esta ficha, com o certificado da capacitação, em lugar visível.</p>
  <p class="dica"><b>Escola:</b> ________________________________________________ <b>Ano:</b> ________</p>
  <table><thead><tr><th>Nome</th><th>Função</th><th>Data da capacitação</th></tr></thead><tbody>
{linhas}
  </tbody></table>

  <div class="cartaz">
    <h2>Emergência com uma criança</h2>
    <div class="duas">
      <ol class="passos">
        <li><b>Mantenha a calma</b> e afaste a criança do perigo, se for seguro.</li>
        <li><b>Chame quem foi capacitado</b> (a lista está ao lado).</li>
        <li><b>Ligue 192</b> e responda com calma o que perguntarem.</li>
        <li><b>Não dê comida, água nem remédio</b> sem orientação.</li>
        <li><b>Avise a família</b> e mande alguém acompanhar a criança.</li>
      </ol>
      <div>
        <ul class="tel">
          <li><b>192</b> SAMU</li>
          <li><b>193</b> Bombeiros</li>
          <li><b>190</b> Polícia</li>
        </ul>
        <p class="dica" style="margin-top:2mm"><b>Endereço da escola</b> (para dizer ao 192):</p>
        <div class="linha"></div><div class="linha"></div>
      </div>
    </div>
  </div>
  <p class="dica" style="margin-top:3.5mm"><b>Capacitação da equipe:</b> curso presencial Primeiros Socorros Lei Lucas – Ambientes com Crianças (8 horas), também em turma fechada para a escola, no Palácio da Cruz Vermelha, Centro do Rio. Vendido pela escola ligada à filial (O-CVB Filial Rio de Janeiro Ensino Ltda): {end}</p>
  <p class="pe">Cruz Vermelha Brasileira – Filial do Estado do Rio de Janeiro · {HOJE:%d/%m/%Y} · Pode copiar e distribuir, sem alterar.</p>
</section>
</body></html>"""


def contingencia(logo: str) -> str:
    end = "cruzvermelhariodejaneiro.org/plano-de-contingencia"
    perguntas = [
        ("Qual é o risco?", "Enchente, deslizamento, vendaval, calor extremo, incêndio. Onde acontece e em que época do ano."),
        ("Quem está exposto?", "Quantas pessoas moram, estudam ou trabalham na área. Quem precisa de ajuda para sair: crianças, idosos, pessoas com deficiência, acamados."),
        ("O que dispara o alerta, e quem decide?", "Chuva acumulada, nível do rio, aviso da Defesa Civil. Nome de quem decide e de um substituto."),
        ("Como avisamos todo mundo?", "Sirene, SMS 40199, grupo de mensagens, carro de som, porta a porta. Deixe a mensagem já escrita."),
        ("Por onde saímos, e para onde vamos?", "A rota mais segura, sinalizada, e o ponto de encontro: alto, firme e conhecido de todos."),
        ("Onde as pessoas ficam, se não puderem voltar?", "Abrigo, casa de parentes, escola, igreja. Quem cuida da água, da comida e da higiene."),
        ("Que recursos temos?", "Veículos, lanternas, rádio, kit de primeiros socorros, kit de emergência, contatos de quem pode ajudar."),
        ("Quando treinamos?", "Um simulado por ano, pelo menos. Data do próximo:"),
        ("Quando revisamos o plano?", "Depois de cada simulado e de cada emergência real. Próxima revisão:"),
    ]
    itens = "\n".join(f'<li><b>{t}</b><p>{d}</p><div class="linha"></div><div class="linha"></div></li>' for t, d in perguntas)
    funcoes = ["Coordenação geral", "Acompanhar os alertas", "Avisar as pessoas", "Conduzir a saída pela rota",
               "Contar quem chegou ao ponto de encontro", "Ajudar quem tem dificuldade de sair", "Primeiros socorros",
               "Falar com a Defesa Civil e a imprensa"]
    linhas = "\n".join(f'<tr><td class="f">{f}</td><td></td><td></td><td></td></tr>' for f in funcoes)
    return f"""<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8">
<title>Roteiro do plano de contingência – Cruz Vermelha Brasileira, Rio de Janeiro</title>
<style>{CSS}</style></head>
<body>
<section class="folha">
  {cabeca(logo, end, 1)}
  <h1>Roteiro do plano de contingência</h1>
  <p class="sub">Responda em reunião, com quem decide e com quem vai executar. As respostas são o esqueleto do plano do seu município, escola, empresa, condomínio ou comunidade.</p>
  <ol class="perg">
{itens}
  </ol>
  <p class="pe">Cruz Vermelha Brasileira – Filial do Estado do Rio de Janeiro · Roteiro de apoio: não substitui o modelo oficial do seu município nem a orientação da Defesa Civil local · Fontes em {end} · {HOJE:%d/%m/%Y} · Pode copiar e distribuir, sem alterar.</p>
</section>
<section class="folha">
  {cabeca(logo, end, 2)}
  <h1 style="font-size:17pt">Quem faz o quê</h1>
  <p class="sub">Cada tarefa com um responsável e um substituto. Revise os nomes e os telefones a cada seis meses.</p>
  <table><thead><tr><th>Tarefa</th><th>Responsável</th><th>Substituto</th><th>Telefone</th></tr></thead><tbody>
{linhas}
  </tbody></table>

  <h2>Níveis de alerta: o que fazemos em cada um</h2>
  <table><thead><tr><th>Nível</th><th>O que dispara</th><th>O que fazemos</th></tr></thead><tbody>
    <tr style="height:12mm"><td class="f">Observação</td><td></td><td></td></tr>
    <tr style="height:12mm"><td class="f">Alerta</td><td></td><td></td></tr>
    <tr style="height:12mm"><td class="f">Alarme (sair agora)</td><td></td><td></td></tr>
  </tbody></table>

  <div class="duas" style="margin-top:4mm">
    <div>
      <h2 style="margin-top:0">Ponto de encontro e abrigo</h2>
      <p class="dica" style="margin:0"><b>Ponto de encontro:</b></p><div class="linha"></div>
      <p class="dica" style="margin:2mm 0 0"><b>Abrigo:</b></p><div class="linha"></div>
      <p class="dica" style="margin:2mm 0 0"><b>Defesa Civil do município (telefone):</b></p><div class="linha"></div>
    </div>
    <div class="caixa">
      <h2 style="margin-top:0;border:0;padding:0">Em caso de emergência</h2>
      <ul class="tel">
        <li><b>199</b> Defesa Civil</li>
        <li><b>193</b> Bombeiros</li>
        <li><b>192</b> SAMU</li>
      </ul>
      <p class="dica" style="margin:2mm 0 0"><b>Alertas no celular:</b> envie um SMS com o CEP para <b>40199</b>.</p>
    </div>
  </div>
  <p class="dica" style="margin-top:3.5mm">Cada família da área também precisa do próprio kit: a lista do kit de emergência está em cruzvermelhariodejaneiro.org/kit-de-emergencia.</p>
  <p class="pe">Cruz Vermelha Brasileira – Filial do Estado do Rio de Janeiro · {HOJE:%d/%m/%Y} · Pode copiar e distribuir, sem alterar.</p>
</section>
</body></html>"""


MATERIAIS = [
    (lei_lucas, RAIZ / "site" / "lei-lucas-para-escolas" / "kit-lei-lucas-escolas-cruz-vermelha-rj.pdf",
     RAIZ / "site" / "lei-lucas-para-escolas" / "capa-kit-lei-lucas.webp"),
    (contingencia, RAIZ / "site" / "plano-de-contingencia" / "roteiro-plano-de-contingencia-cruz-vermelha-rj.pdf",
     RAIZ / "site" / "plano-de-contingencia" / "capa-roteiro-plano-de-contingencia.webp"),
]


def main() -> int:
    pedido = urllib.request.Request(LOGO, headers={"User-Agent": "gerar_pdf_materiais.py"})
    logo = base64.b64encode(urllib.request.urlopen(pedido, timeout=30).read()).decode()
    ambiente = dict(os.environ)
    if "NODE_PATH" not in ambiente:
        ambiente["NODE_PATH"] = subprocess.run(["npm", "root", "-g"], capture_output=True, text=True).stdout.strip()
    erro = 0
    with tempfile.TemporaryDirectory() as tmp:
        runner = Path(tmp) / "pdf.js"
        runner.write_text(PDF_JS, encoding="utf-8")
        for montar, saida, capa in MATERIAIS:
            entrada = Path(tmp) / f"{saida.stem}.html"
            entrada.write_text(montar(logo), encoding="utf-8")
            saida.parent.mkdir(parents=True, exist_ok=True)
            subprocess.run(["node", str(runner), str(entrada), str(saida)], check=True, env=ambiente)
            folhas = len(re.findall(rb"/Type\s*/Page\b", saida.read_bytes()))
            # A capa: a folha 1 em 480 px de largura (a página reserva 480 × 679, a proporção do A4).
            png = subprocess.run(["pdftoppm", "-f", "1", "-l", "1", "-png", "-scale-to-x", "480", "-scale-to-y", "-1",
                                  str(saida)], capture_output=True, check=True).stdout
            Image.open(io.BytesIO(png)).convert("RGB").resize((480, 679)).save(capa, "WEBP", quality=80, method=6)
            print(f"gravado {saida.relative_to(RAIZ)} ({saida.stat().st_size} bytes, {folhas} folhas) e {capa.name}")
            if folhas != 2:
                print(f"ATENÇÃO: {saida.name} saiu com {folhas or '?'} folhas, e são 2. Ajuste o texto antes de publicar.")
                erro = 1
    return erro


if __name__ == "__main__":
    sys.exit(main())
