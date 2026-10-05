#!/usr/bin/env python3
"""Criativos de remarketing com o certificado de amostra (Meta: feed 1080x1080 e stories 1080x1920).

Para quem visitou a página de matrícula ou abriu o checkout e não pagou. O certificado é a amostra de
scripts/gerar_certificado_modelo.py ("Seu nome aqui", marca MODELO): o anúncio diz "Imagem de modelo".

Copy só com afirmações que têm fonte (as mesmas da página):
  - "reconhecida nacional e internacionalmente pela tradição em formação humanitária" (faq-home.json, FAQ do Bombeiro Civil);
  - carga horária e escolaridade do cursos.json; a frase de cada curso vem do COPY_CURSO do gerador da página.
Nada de emprego, renda, MEC, "ampla aceitação" ou urgência inventada.

Saída: docs/criativos/certificado-<slug>-feed.jpg e -stories.jpg.
Uso: python3 scripts/gerar_criativos_certificado.py   (Pillow e Playwright/Chromium do ambiente; fontes do Google Fonts)
"""
from __future__ import annotations

import base64
import html
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path

from PIL import Image

RAIZ = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(RAIZ / "scripts"))
from gerar_matricula_presencial import COPY_CURSO, NOME_CURTO  # noqa: E402

DADOS = RAIZ / "site" / "matricula-cursos-presenciais" / "cursos.json"
IMG = RAIZ / "site" / "matricula-cursos-presenciais" / "img"
LOGO = RAIZ / "site" / "matricula-cursos-presenciais" / "api" / "lib" / "comprovante-logo.png"
SAIDA = RAIZ / "docs" / "criativos"
CURSOS = ["puncao-venosa", "primeiros-socorros-basico"]  # um da saúde e um aberto a qualquer pessoa
PESO = "Cruz Vermelha: reconhecida nacional e internacionalmente pela tradição em formação humanitária."
FORMATOS = {"feed": (1080, 1080), "stories": (1080, 1920)}


def b64(caminho: Path, tipo: str) -> str:
    return f"data:{tipo};base64,{base64.b64encode(caminho.read_bytes()).decode()}"


def pagina(curso: dict, insc: str, formato: str) -> str:
    largura, altura = FORMATOS[formato]
    stories = formato == "stories"
    s = curso["slug"]
    nome = NOME_CURTO.get(s, curso["nome"])
    promessa = COPY_CURSO[s]["promessa"]
    meta = f'{curso["carga_horaria"]} presenciais · Centro do Rio'
    # O preço inteiro, como na página: a inscrição agora e o curso depois (nunca só os R$ 99).
    v = curso.get("valor_curso_centavos")
    valor_curso = f"+ curso R$ {int(v) // 100}, pago depois" if v else "+ valor do curso, pago depois"
    return f"""<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;700;800;900&display=block" rel="stylesheet">
<style>
  html, body {{ margin: 0; }}
  .c {{ width: {largura}px; height: {altura}px; box-sizing: border-box; position: relative; overflow: hidden;
        font-family: Inter, Arial, sans-serif; color: #0f1318; background: linear-gradient(180deg, #ffffff 0%, #f4f5f7 100%);
        padding: {'150px 80px 120px' if stories else '48px 60px 44px'}; display: flex; flex-direction: column; }}
  .c::before {{ content: ""; position: absolute; left: 0; right: 0; top: 0; height: 14px; background: #cc0000; }}
  .logo {{ height: {'74px' if stories else '52px'}; width: auto; align-self: flex-start; mix-blend-mode: multiply; }}
  h1 {{ font-size: {'84px' if stories else '56px'}; line-height: 1.02; letter-spacing: -.03em; font-weight: 900; margin: {'60px 0 18px' if stories else '22px 0 8px'}; }}
  h1 em {{ font-style: normal; color: #cc0000; }}
  .curso {{ font-size: {'40px' if stories else '28px'}; font-weight: 800; margin: 0; }}
  .meta {{ font-size: {'30px' if stories else '24px'}; color: #4a5568; font-weight: 600; margin: 6px 0 0; }}
  .cert {{ margin: {'56px 0 0' if stories else '18px 0 0'}; align-self: center; width: {'100%' if stories else '64%'}; transform: rotate(-2deg);
           box-shadow: 0 30px 60px rgba(15, 19, 24, .22), 0 6px 14px rgba(15, 19, 24, .12); border-radius: 10px; display: block; }}
  .modelo {{ text-align: center; color: #718096; font-size: {'24px' if stories else '17px'}; margin: {'14px 0 0' if stories else '10px 0 0'}; }}
  .promessa {{ font-size: {'34px' if stories else '0'}; line-height: 1.35; color: #2d3748; margin: {'48px 0 0' if stories else '0'}; {'display:none;' if not stories else ''} }}
  .peso {{ font-size: {'28px' if stories else '21px'}; line-height: 1.4; color: #2d3748; margin: {'34px 0 0' if stories else '12px 0 16px'}; font-weight: 600; }}
  .rodape {{ margin-top: auto; display: flex; align-items: center; justify-content: space-between; gap: 24px; }}
  .cta {{ background: #cc0000; color: #fff; font-weight: 800; font-size: {'40px' if stories else '28px'}; padding: {'30px 48px' if stories else '20px 34px'}; border-radius: 999px; white-space: nowrap; }}
  .preco {{ font-size: {'28px' if stories else '19px'}; color: #4a5568; line-height: 1.3; margin: 0; }}
  .preco b {{ display: block; color: #0f1318; font-size: {'46px' if stories else '32px'}; }}
</style></head><body><div class="c">
  <img class="logo" src="{b64(LOGO, 'image/png')}" alt="">
  <h1>Coloque o <em>seu nome</em> neste certificado.</h1>
  <p class="curso">{html.escape(nome)}</p>
  <p class="meta">{html.escape(meta)}</p>
  <img class="cert" src="{b64(IMG / f'certificado-{s}-1200.webp', 'image/webp')}" alt="">
  <p class="modelo">Imagem de modelo</p>
  <p class="promessa">{html.escape(promessa)}</p>
  <p class="peso">{html.escape(PESO)}</p>
  <div class="rodape">
    <p class="preco">Inscrição<b>{html.escape(insc)}</b>garante sua vaga<br>{html.escape(valor_curso)}</p>
    <span class="cta">Fazer matrícula</span>
  </div>
</div></body></html>"""


CAPTURA_JS = r"""
const { chromium } = require('playwright');
const [,, lista] = process.argv;
(async () => {
  const b = await chromium.launch();
  for (const [entrada, saida, w, h] of JSON.parse(lista)) {
    const p = await b.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
    await p.goto('file://' + entrada, { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    await p.locator('.c').screenshot({ path: saida });
    await p.close();
  }
  await b.close();
})();
"""


def main() -> int:
    dados = json.loads(DADOS.read_text(encoding="utf-8"))
    cursos = {c["slug"]: c for c in dados["cursos"]}
    insc = f'R$ {dados["inscricao_centavos"] // 100}'
    SAIDA.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory() as tmp:
        tmp = Path(tmp)
        pares = []
        for s in CURSOS:
            for formato, (w, h) in FORMATOS.items():
                entrada = tmp / f"{s}-{formato}.html"
                entrada.write_text(pagina(cursos[s], insc, formato), encoding="utf-8")
                pares.append([str(entrada), str(tmp / f"{s}-{formato}.png"), w, h])
        runner = tmp / "captura.js"
        runner.write_text(CAPTURA_JS, encoding="utf-8")
        ambiente = dict(os.environ)
        if "NODE_PATH" not in ambiente:
            ambiente["NODE_PATH"] = subprocess.run(["npm", "root", "-g"], capture_output=True, text=True).stdout.strip()
        subprocess.run(["node", str(runner), json.dumps(pares)], check=True, env=ambiente)
        for entrada, png, _, _ in pares:
            alvo = SAIDA / f"certificado-{Path(png).stem}.jpg"
            Image.open(png).convert("RGB").save(alvo, "JPEG", quality=88, optimize=True, progressive=True)
            print(f"gravado {alvo.relative_to(RAIZ)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
