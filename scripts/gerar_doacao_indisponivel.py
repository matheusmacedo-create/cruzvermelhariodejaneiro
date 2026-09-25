#!/usr/bin/env python3
"""Gera site/doacao-indisponivel.html: o aviso de doação online fora do ar (ErrorDocument 503).

Desde 25/09/2026, a pedido do Matheus, as páginas de doação (/doe/, /en/donate/, /es/donar/) estão
fora do ar: o .htaccess da raiz responde 503 para elas e o Apache mostra esta página no lugar.
503 diz à busca que a saída é temporária (a página não é trocada por outra no índice). Os arquivos
das páginas continuam no servidor: para voltar, basta tirar as regras do .htaccess.

Mesmo cabeçalho, rodapé e CSS da home (como o 404). Os links são absolutos, porque a página
aparece no endereço pedido (/doe/, /en/donate/…), e não no próprio.
"""
from __future__ import annotations

import icones
from gerar_matricula_presencial import HOME, RAIZ, partes_da_home

PAGINA = """<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <title>Doação online suspensa | Cruz Vermelha Brasileira Rio de Janeiro</title>
  <meta name="robots" content="noindex, follow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"></noscript>
@@ESTILO@@
  <style>
    .e404 { padding: 72px 0 88px; background: var(--soft); border-bottom: 1px solid var(--line); }
    .e404 h1 { color: var(--black); font-size: clamp(2rem, 4.6vw, 3.2rem); letter-spacing: -.03em; line-height: 1.05; margin: 8px 0 14px; }
    .e404 .lead { max-width: 60ch; }
    .e404-links { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-top: 28px; max-width: 760px; }
    .e404-links a { display: flex; align-items: center; gap: 12px; padding: 14px 16px; background: #fff; border: 1px solid var(--line); border-radius: 14px; color: var(--black); font-weight: 700; text-decoration: none; }
    .e404-links a:hover { border-color: var(--red); }
    .e404-links i { color: var(--red); }
    .e404-idiomas { margin-top: 32px; max-width: 60ch; color: var(--muted); font-size: .95rem; display: grid; gap: 8px; }
  </style>
@@GA4@@
@@PIXEL@@
</head>
<body>
@@HEADER@@
  <main>
    <section class="e404">
      <div class="wrap">
        <p class="eyebrow">Doação online</p>
        <h1>A doação online está suspensa no momento.</h1>
        <p class="lead">A página de doação da Cruz Vermelha Brasileira Rio de Janeiro está fora do ar e nenhuma cobrança nova é gerada por aqui. Quem já doou continua recebendo o comprovante por e-mail. Dúvidas: contato@cruzvermelhariodejaneiro.org.</p>
        <div class="e404-links">
          <a href="/campanha-agasalho.html"><i class="fa-solid fa-hand" aria-hidden="true"></i> Campanha do Agasalho</a>
          <a href="/#contato"><i class="fa-regular fa-envelope" aria-hidden="true"></i> Fale com a gente</a>
          <a href="/"><i class="fa-solid fa-house" aria-hidden="true"></i> Página inicial</a>
        </div>
        <div class="e404-idiomas">
          <p lang="en">Online donations are suspended for now. Questions: contato@cruzvermelhariodejaneiro.org.</p>
          <p lang="es">Las donaciones en línea están suspendidas por ahora. Consultas: contato@cruzvermelhariodejaneiro.org.</p>
        </div>
      </div>
    </section>
  </main>
@@FOOTER@@
@@MENU_JS@@
</body>
</html>
"""


def main() -> int:
    partes = partes_da_home(HOME.read_text(encoding="utf-8"))
    html = (PAGINA.replace("@@ESTILO@@", partes["estilo"]).replace("@@HEADER@@", partes["header"])
            .replace("@@FOOTER@@", partes["footer"]).replace("@@MENU_JS@@", partes["menu_js"])
            .replace("@@GA4@@", partes["ga4"]).replace("@@PIXEL@@", partes["pixel"]))
    destino = RAIZ / "site" / "doacao-indisponivel.html"
    html = icones.converter(html)
    destino.write_text(html, encoding="utf-8")
    print(f"gravado {destino.relative_to(RAIZ)} ({len(html.encode('utf-8'))} bytes)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
