#!/usr/bin/env python3
"""Gera o certificado de AMOSTRA de cada curso (frente), para a página de matrícula e os e-mails de recuperação.

Fonte: o modelo de certificado da filial (Punção Venosa, enviado pelo Matheus em 04/10/2026). O fundo
scripts/certificado/fundo-certificado.png é a frente desse modelo renderizada em 200 dpi com a área do aluno
apagada (nome, CPF, texto, data e assinatura): ele não guarda nenhum dado pessoal. O PDF original, que tem nome
e CPF de uma pessoa, NÃO entra no repositório.

Sobre o fundo, o gerador escreve, com as fontes do modelo (Great Vibes e Arimo):
  - "Seu nome aqui", CPF 000.000.000-00 e a data em branco;
  - o curso e a carga horária, tirados do cursos.json ("Curso Livre de PUNÇÃO VENOSA … 08 horas");
  - a assinatura só com o cargo (Coordenadora de Cursos Livres), sem nome;
  - a marca d'água "MODELO", para a imagem não servir de certificado de verdade.

Saída em site/matricula-cursos-presenciais/img/:
  certificado-<slug>-640.webp e -1200.webp (página) e certificado-<slug>-email.jpg (600 px; e-mail não abre WebP).

Uso: python3 scripts/gerar_certificado_modelo.py   (precisa de Pillow e do Playwright/Chromium do ambiente;
as fontes vêm do Google Fonts na hora de gerar).
"""
from __future__ import annotations

import base64
import html
import json
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path

from PIL import Image

RAIZ = Path(__file__).resolve().parent.parent
FUNDO = RAIZ / "scripts" / "certificado" / "fundo-certificado.png"
DADOS = RAIZ / "site" / "matricula-cursos-presenciais" / "cursos.json"
SAIDA = RAIZ / "site" / "matricula-cursos-presenciais" / "img"
LARGURA = 1600  # px do HTML; as medidas abaixo são do modelo em 702 px (K converte)
K = LARGURA / 702


def nome_no_certificado(nome: str) -> str:
    """Como o curso aparece no certificado: em maiúsculas, sem "(Curso Livre)"."""
    return re.sub(r"\s*\(Curso Livre\)\s*", "", nome).strip().upper()


def horas(carga: str) -> str:
    m = re.search(r"\d+", carga or "")
    return f"{int(m.group(0)):02d} horas" if m else carga


def pagina_html(curso: dict, fundo_b64: str) -> str:
    def px(v: float) -> str:
        return f"{v * K:.1f}px"
    curso_txt = html.escape(nome_no_certificado(curso["nome"]))
    carga = html.escape(horas(curso.get("carga_horaria", "")))
    return f"""<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">
<link href="https://fonts.googleapis.com/css2?family=Great+Vibes&family=Arimo:wght@400;700&display=block" rel="stylesheet">
<style>
  html, body {{ margin: 0; padding: 0; }}
  .c {{ position: relative; width: {LARGURA}px; height: {round(LARGURA * 1653 / 2339)}px; background: url(data:image/png;base64,{fundo_b64}) 0 0 / 100% 100% no-repeat; overflow: hidden; font-family: Arimo, Arial, sans-serif; color: #1f1f1f; }}
  .nome {{ position: absolute; left: 0; right: 0; top: {px(180)}; text-align: center; font-family: 'Great Vibes', cursive; font-size: {px(34)}; color: #2f5f9e; }}
  .texto {{ position: absolute; left: {px(88)}; right: {px(88)}; top: {px(236)}; text-align: center; font-size: {px(11.6)}; line-height: 1.75; }}
  .data {{ position: absolute; left: 0; right: 0; top: {px(311)}; text-align: center; font-size: {px(10)}; }}
  .linha {{ position: absolute; left: {px(247)}; width: {px(208)}; top: {px(366)}; border-top: {px(.9)} solid #222; }}
  .cargo {{ position: absolute; left: 0; right: 0; top: {px(372)}; text-align: center; font-size: {px(9.6)}; line-height: 1.45; }}
  .cargo small {{ display: block; font-size: {px(7)}; font-weight: 700; }}
  .modelo {{ position: absolute; left: 50%; top: 52%; transform: translate(-50%, -50%) rotate(-16deg); font-family: Arimo, Arial, sans-serif; font-weight: 700; font-size: {px(118)}; letter-spacing: {px(12)}; color: rgba(204, 0, 0, .085); white-space: nowrap; pointer-events: none; }}
</style></head><body><div class="c">
  <div class="nome">Seu nome aqui</div>
  <div class="texto">Portador(a) do <b>CPF 000.000.000-00</b>, concluiu o <b>Curso Livre de {curso_txt}</b>, ministrado pela Cruz Vermelha Brasileira – Filial Rio de Janeiro com carga horária de {carga} na data de __/__/____.</div>
  <div class="data">Rio de Janeiro, ___ de ____________ de 20___.</div>
  <div class="linha"></div>
  <div class="cargo">Coordenadora de Cursos Livres<small>Cruz Vermelha Brasileira - Rio de Janeiro</small></div>
  <div class="modelo">MODELO</div>
</div></body></html>"""


CAPTURA_JS = r"""
const { chromium } = require('playwright');
const [,, lista] = process.argv;
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1600, height: 1131 }, deviceScaleFactor: 1 });
  for (const [entrada, saida] of JSON.parse(lista)) {
    await p.goto('file://' + entrada, { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    await p.locator('.c').screenshot({ path: saida });
  }
  await b.close();
})();
"""


def main() -> int:
    dados = json.loads(DADOS.read_text(encoding="utf-8"))
    fundo_b64 = base64.b64encode(FUNDO.read_bytes()).decode()
    with tempfile.TemporaryDirectory() as tmp:
        tmp = Path(tmp)
        pares = []
        for c in dados["cursos"]:
            entrada = tmp / f"{c['slug']}.html"
            entrada.write_text(pagina_html(c, fundo_b64), encoding="utf-8")
            pares.append([str(entrada), str(tmp / f"{c['slug']}.png")])
        runner = tmp / "captura.js"
        runner.write_text(CAPTURA_JS, encoding="utf-8")
        ambiente = dict(os.environ)
        if "NODE_PATH" not in ambiente:
            ambiente["NODE_PATH"] = subprocess.run(["npm", "root", "-g"], capture_output=True, text=True).stdout.strip()
        subprocess.run(["node", str(runner), json.dumps(pares)], check=True, env=ambiente)
        for c, (_, png) in zip(dados["cursos"], pares):
            im = Image.open(png).convert("RGB")
            for largura in (640, 1200):
                alvo = SAIDA / f"certificado-{c['slug']}-{largura}.webp"
                im.resize((largura, round(largura * im.height / im.width)), Image.LANCZOS).save(alvo, "WEBP", quality=82, method=6)
            alvo = SAIDA / f"certificado-{c['slug']}-email.jpg"
            im.resize((600, round(600 * im.height / im.width)), Image.LANCZOS).save(alvo, "JPEG", quality=84, optimize=True, progressive=True)
            print(f"gravado img/certificado-{c['slug']}-(640|1200).webp e -email.jpg")
    return 0


if __name__ == "__main__":
    sys.exit(main())
