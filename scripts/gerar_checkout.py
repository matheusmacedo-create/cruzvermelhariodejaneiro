#!/usr/bin/env python3
"""Gera as páginas do checkout da matrícula: checkout/, pendente/, parabens/ e horarios/, e as do
ponto da sede: ponto/ (com o cartaz do QR code), comparecimento/ e conferir/.

Todas usam o cabeçalho, o rodapé, o CSS, o GA4 e o Meta Pixel da home (via
scripts/gerar_matricula_presencial.py) e conversam com o backend em
site/matricula-cursos-presenciais/api/ (PHP, Unicopag). As três levam noindex.

O CSS e o JS próprios do checkout ficam em site/matricula-cursos-presenciais/static/
(checkout.css e checkout.js): as páginas só os referenciam, com um hash do conteúdo na
query string para o navegador buscar a versão nova a cada mudança.

Uso:  python3 scripts/gerar_checkout.py   (depois de gerar_matricula_presencial.py)
"""
from __future__ import annotations

import hashlib
import json

import chat_widget
import icones
from gerar_matricula_presencial import DADOS, HOME, RAIZ, esc, partes_da_home

PASTA = RAIZ / "site" / "matricula-cursos-presenciais"
STATIC = PASTA / "static"
STATIC_URL = "/matricula-cursos-presenciais/static/"






PAGINA = """<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <title>@@TITULO@@</title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"></noscript>
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
  <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
@@QRCODE@@
  <!-- Estilos copiados da home (site/index.html): mesmo padrão visual da filial. -->
@@ESTILO@@
  <link rel="stylesheet" href="@@CSS_URL@@">
  <script src="@@JS_URL@@" defer></script>
@@GA4@@
@@PIXEL@@
</head>
<body data-tela="@@ID@@">
  <script>document.documentElement.classList.add('js');</script>
@@HEADER@@

  <main id="@@ID@@">
    <div class="ck-teste" id="ck-teste" hidden>Modo de teste: o valor cobrado não é o preço da inscrição.</div>
    <section class="ck-hero">
      <div class="wrap">
        <p class="eyebrow">@@EYEBROW@@</p>
        <h1>@@H1@@</h1>
        <p class="lead">@@LEAD@@</p>
@@PASSOS@@
      </div>
    </section>
    <section class="ck-secao">
      <div class="wrap"@@WRAP_EXTRA@@>
        <noscript><p class="ck-noscript">@@NOSCRIPT@@ Ative o JavaScript ou escreva para contato@cruzvermelhariodejaneiro.org.</p></noscript>
@@CORPO@@
      </div>
    </section>
  </main>

@@FOOTER@@

@@MENU_JS@@
@@CHAT@@
</body>
</html>
"""

CORPO_CHECKOUT = """        <div class="ck-grid">
          <div class="ck-card">
            <form id="ck-form" novalidate autocomplete="on">
              <div class="ck-armadilha" aria-hidden="true"><label>Não preencha este campo<input id="ck-site" name="site" tabindex="-1" autocomplete="off"></label></div>
              <section class="ck-bloco-form" aria-labelledby="ck-t1">
                <h2 class="ck-bloco-titulo" id="ck-t1"><span class="ck-num">1</span> Curso</h2>
                <label class="ck-campo"><span>Curso presencial</span>
                  <select id="ck-curso" name="curso" required>
                    <option value="" disabled selected>Escolha o curso</option>@@OPCOES@@
                  </select>
                </label>
                <ul class="ck-curso-meta" id="ck-curso-meta" hidden>
                  <li><i class="fa-regular fa-clock" aria-hidden="true"></i> <span id="ck-m-carga"></span></li>
                  <li><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i> <span id="ck-m-escolaridade"></span></li>
                  <li><i class="fa-solid fa-location-dot" aria-hidden="true"></i> Praça da Cruz Vermelha, 10 · Centro</li>
                </ul>
              </section>
              <section class="ck-bloco-form" aria-labelledby="ck-t2">
                <h2 class="ck-bloco-titulo" id="ck-t2"><span class="ck-num">2</span> Seus dados <small>só o necessário para a matrícula</small></h2>
                <label class="ck-campo"><span>Nome completo</span><input id="ck-nome" name="nome" autocomplete="name" placeholder="Como está no seu documento" required></label>
                <div class="ck-2col">
                  <label class="ck-campo"><span>CPF</span><input id="ck-cpf" name="cpf" inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" required></label>
                  <label class="ck-campo"><span>Telefone (celular)</span><input id="ck-telefone" name="telefone" inputmode="tel" autocomplete="tel" placeholder="(21) 99999-9999" required></label>
                </div>
                <label class="ck-campo"><span>E-mail</span><input id="ck-email" type="email" name="email" autocomplete="email" placeholder="voce@exemplo.com" required><small class="ck-nota">A confirmação da inscrição chega neste e-mail.</small></label>
              </section>
              <section class="ck-bloco-form" aria-labelledby="ck-t3">
                <h2 class="ck-bloco-titulo" id="ck-t3"><span class="ck-num">3</span> Pagamento</h2>
                <div class="ck-metodos" role="radiogroup" aria-label="Forma de pagamento">
                  <label class="ck-metodo ativo"><input type="radio" name="metodo" value="pix" checked><i class="fa-brands fa-pix ck-metodo-icone" aria-hidden="true"></i><span class="ck-metodo-tag">Na hora</span><b>PIX</b><small>QR code ou copia e cola</small></label>
                  <label class="ck-metodo"><input type="radio" name="metodo" value="cartao"><i class="fa-regular fa-credit-card ck-metodo-icone" aria-hidden="true"></i><b>Cartão de crédito</b><small>À vista, aprovação em segundos</small></label>
                </div>
                <div class="ck-cartao-box" id="ck-cartao" hidden>
                  <label class="ck-campo"><span>Número do cartão</span><input id="ck-cartao-numero" inputmode="numeric" autocomplete="cc-number" placeholder="0000 0000 0000 0000"></label>
                  <label class="ck-campo"><span>Nome como está no cartão</span><input id="ck-cartao-nome" autocomplete="cc-name"></label>
                  <div class="ck-2col">
                    <label class="ck-campo"><span>Validade</span><input id="ck-cartao-validade" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/AA"></label>
                    <label class="ck-campo"><span>CVV</span><input id="ck-cartao-cvv" inputmode="numeric" autocomplete="cc-csc" placeholder="123"></label>
                  </div>
                  <p class="ck-nota"><i class="fa-solid fa-lock" aria-hidden="true"></i> Os dados do cartão vão direto para o processador de pagamento e não ficam guardados no site.</p>
                </div>
                <label class="ck-check" id="ck-cobre-opcao"><input type="checkbox" id="ck-cobre" name="cobre_taxa"><span class="ck-check-texto">Quero cobrir os custos de processamento<small>Opcional. Assim a Cruz Vermelha recebe o valor integral da inscrição.</small></span><span class="ck-check-valor" id="ck-taxa-valor">+ R$ 0,00</span></label>
                <label class="ck-check"><input type="checkbox" id="ck-requisitos" name="requisitos" required><span class="ck-check-texto">Li os requisitos do curso (<span id="ck-escolaridade">escolaridade mínima</span>) e confirmo que os atendo.</span></label>
                <div class="ck-erro" id="ck-erro" role="alert" aria-live="assertive"></div>
                <button class="btn btn-red ck-btn" type="submit" id="ck-pagar">Pagar inscrição · <span id="ck-total-btn">R$ 99,00</span></button>
                <ul class="ck-confianca">
                  <li><i class="fa-solid fa-lock" aria-hidden="true"></i> Pagamento seguro pela ÚnicoPag</li>
                  <li><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> 7 dias para desistir, com o valor de volta</li>
                  <li><i class="fa-solid fa-certificate" aria-hidden="true"></i> Certificado da Cruz Vermelha Brasileira Rio de Janeiro</li>
                </ul>
                <p class="ck-nota ck-legal" style="margin:14px 0 0">Ao pagar, você concorda com os <a href="/termos/">Termos de Uso</a> e com as regras de <a href="/reembolso/">cancelamento e reembolso</a>: dá para desistir em até 7 dias depois do pagamento e receber o valor de volta (art. 49 do Código de Defesa do Consumidor). Seus dados são usados só para a matrícula e a cobrança, como explica a <a href="/privacidade/">Política de Privacidade</a>.</p>
                <p class="ck-nota ck-legal" style="margin:8px 0 0">Cruz Vermelha Brasileira — Filial do Estado do Rio de Janeiro · CNPJ 08.560.973/0001-97 · Praça da Cruz Vermelha, 10, Centro, Rio de Janeiro/RJ · contato@cruzvermelhariodejaneiro.org</p>
              </section>
            </form>
            <div id="ck-pix" hidden aria-live="polite"></div>
          </div>
          <aside class="ck-card ck-resumo" aria-label="Resumo da inscrição">
            <img class="ck-resumo-foto" id="ck-r-foto" src="/assets/hero-cursos-banner-1.jpg" alt="" width="480" height="360">
            <p class="ck-resumo-rotulo">Sua inscrição</p>
            <p class="ck-resumo-curso" id="ck-r-curso">Escolha o curso</p>
            <span class="ck-resumo-meta" id="ck-r-meta">7 cursos presenciais no Centro do Rio</span>
            <div class="ck-linha"><span>Inscrição</span><span id="ck-r-inscricao">R$ 99,00</span></div>
            <div class="ck-linha" id="ck-r-taxa-linha" hidden><span>Custos de processamento</span><span id="ck-r-taxa">R$ 0,00</span></div>
            <div class="ck-linha total"><span>Total agora</span><span id="ck-r-total">R$ 99,00</span></div>
            <p class="ck-nota">O valor do curso (<span id="ck-r-curso-valor">—</span>) é pago depois, na plataforma da escola.</p>
            <ul class="ck-depois" aria-label="O que você garante">
              <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Vaga reservada na hora, com confirmação por e-mail</li>
              <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Sem criar conta e sem escolher turma agora</li>
              <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Estorno se não houver horário compatível ou se você desistir antes da confirmação da aula</li>
            </ul>
          </aside>
        </div>
        <script type="application/json" id="ck-cursos">@@CURSOS_JSON@@</script>"""

CORPO_PENDENTE = """        <div class="ck-card" id="pd-card" aria-live="polite"><p>Carregando…</p></div>"""
CORPO_PARABENS = """        <div class="ck-card" id="pb-card" aria-live="polite"><p>Carregando…</p></div>"""
CORPO_HORARIOS = """        <div class="ck-card" id="hr-card" aria-live="polite"><p>Carregando…</p></div>"""
CORPO_COMPARECIMENTO = """        <div class="ck-card" id="cp-card" aria-live="polite"><p>Carregando…</p></div>"""
CORPO_CONFERIR = """        <div class="ck-card" id="cf-card" aria-live="polite"><p>Carregando…</p></div>"""

# Ponto da sede (29/09/2026): página própria, sem o cabeçalho do site, sem medição e sem chat. Fica
# aberta no aparelho da recepção e também no celular de quem lê o QR code do cartaz.
PAGINA_PONTO = """<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#cc0000">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <link rel="manifest" href="/matricula-cursos-presenciais/ponto/manifest.json">
  <link rel="apple-touch-icon" href="/matricula-cursos-presenciais/ponto/icone-192.png">
  <meta name="apple-mobile-web-app-title" content="Ponto CVB-RJ">
  <meta name="mobile-web-app-capable" content="yes">
  <title>Ponto da sede | Cruz Vermelha Brasileira Rio de Janeiro</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap">
  <link rel="stylesheet" href="@@CSS_URL@@">
  <script src="@@JS_URL@@" defer></script>
</head>
<body>
  <div class="pt-faixa"></div>
  <header class="pt-topo">
    <img src="/assets/otim/logo-cvb-rj-480.png" alt="Cruz Vermelha Brasileira · Rio de Janeiro" width="160" height="48">
    <div class="pt-titulo"><b>Ponto da sede</b><small id="pt-modo">Colaboradores e alunos</small></div>
    <div class="pt-relogio"><b id="pt-hora">--:--</b><small id="pt-data"></small></div>
  </header>
  <main class="pt-main">
    <section class="pt-card" id="pt-tela" aria-live="polite"><p>Carregando…</p></section>
    <noscript><p class="pt-card">O ponto precisa de JavaScript. Ative o JavaScript ou fale com a secretaria.</p></noscript>
  </main>
  <footer class="pt-rodape">
    <p>O registro serve para reconhecer as horas doadas pelos voluntários e para saber quem está na sede. Não é controle de jornada. No celular, a localização só confirma que você está na sede e não fica guardada. <a href="/privacidade/">Privacidade</a></p>
  </footer>
</body>
</html>
"""

# Páginas pessoais dos avisos do ponto (30/09/2026): lembretes, saída sem registro, opinião e "não quero
# mais receber". Mesmo visual do ponto; o conteúdo vem de static/avisos.js com o token do link.
PAGINA_AVISO = """<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="no-referrer">
  <meta name="theme-color" content="#cc0000">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <title>@@TITULO@@ | Cruz Vermelha Brasileira Rio de Janeiro</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap">
  <link rel="stylesheet" href="@@CSS_URL@@">
  <script src="@@JS_URL@@" defer></script>
</head>
<body data-pagina="@@PAGINA@@">
  <div class="pt-faixa"></div>
  <header class="pt-topo">
    <a href="/"><img src="/assets/otim/logo-cvb-rj-480.png" alt="Cruz Vermelha Brasileira · Rio de Janeiro" width="160" height="48"></a>
    <div class="pt-titulo"><b>@@CABECALHO@@</b><small>@@SUBTITULO@@</small></div>
  </header>
  <main class="pt-main">
    <section class="pt-card" id="av-tela" aria-live="polite"><p>Carregando…</p></section>
    <noscript><p class="pt-card">Esta página precisa de JavaScript. Ative o JavaScript ou fale com a secretaria.</p></noscript>
  </main>
  <footer class="pt-rodape">
    <p>@@RODAPE@@ <a href="/privacidade/">Privacidade</a></p>
  </footer>
</body>
</html>
"""
AVISOS_PAGINAS = {
    "lembretes": ("Seus lembretes", "Ponto da sede", "Seus lembretes",
                  "Os lembretes servem para você não esquecer de registrar a chegada e a saída. Você muda ou para quando quiser."),
    "saida": ("Saída sem registro", "Ponto da sede", "Saída sem registro",
              "A secretaria confere o horário informado antes de ele entrar nas suas horas doadas."),
    "opiniao": ("Sua opinião sobre o ponto", "Ponto da sede", "Sua opinião",
                "A secretaria vê as respostas sem o seu nome, a não ser que você autorize o contato."),
    "sair": ("Não receber mais avisos", "Escola de Educação e Saúde", "Avisos por e-mail e WhatsApp",
             "Os e-mails da matrícula e o comprovante de comparecimento continuam chegando."),
}
# Instalar o ponto no celular como um aplicativo (tela inicial). O ícone é o do site (cruz vermelha em fundo branco).
MANIFESTO_PONTO = {
    "name": "Ponto da sede · Cruz Vermelha Brasileira RJ",
    "short_name": "Ponto CVB-RJ",
    "description": "Registre a chegada e a saída na sede da Cruz Vermelha Brasileira Rio de Janeiro.",
    "start_url": "/matricula-cursos-presenciais/ponto/?origem=app",
    "scope": "/matricula-cursos-presenciais/ponto/",
    "display": "standalone",
    "background_color": "#f5f6f8",
    "theme_color": "#cc0000",
    "lang": "pt-BR",
    "icons": [
        {"src": "/matricula-cursos-presenciais/ponto/icone-192.png", "sizes": "192x192", "type": "image/png", "purpose": "any"},
        {"src": "/matricula-cursos-presenciais/ponto/icone-512.png", "sizes": "512x512", "type": "image/png", "purpose": "any"},
        {"src": "/matricula-cursos-presenciais/ponto/icone-512-mascara.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable"},
    ],
}


def gravar_icones_ponto(pasta: Path) -> None:
    """Ícones do ponto instalado no celular: a cruz do favicon.svg desenhada em PNG (192 e 512 px, e o de máscara com margem)."""
    from PIL import Image, ImageDraw
    for tamanho, nome, escala, arredondar in ((192, "icone-192.png", 1.0, True), (512, "icone-512.png", 1.0, True), (512, "icone-512-mascara.png", 0.72, False)):
        fator = 4  # desenha grande e reduz, para as bordas ficarem lisas
        t = tamanho * fator
        img = Image.new("RGBA", (t, t), (255, 255, 255, 0 if arredondar else 255))
        d = ImageDraw.Draw(img)
        if arredondar:
            d.rounded_rectangle([0, 0, t - 1, t - 1], radius=int(t * 12 / 64), fill=(255, 255, 255, 255))
        # Cruz do favicon.svg (viewBox 64): braços de 16 de largura, de 8 a 56.
        u = t / 64 * escala
        c = t / 2
        braco, meia = 16 * u, 24 * u
        d.rectangle([c - braco / 2, c - meia, c + braco / 2, c + meia], fill=(237, 27, 46, 255))
        d.rectangle([c - meia, c - braco / 2, c + meia, c + braco / 2], fill=(237, 27, 46, 255))
        img.resize((tamanho, tamanho), Image.LANCZOS).save(pasta / nome, optimize=True)


# Cartaz A4 para imprimir e colar na recepção: o QR code abre o ponto no celular.
PAGINA_CARTAZ = """<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <title>Cartaz do ponto da sede | Cruz Vermelha Brasileira Rio de Janeiro</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>
  <style>
    @page{size:A4;margin:0}
    *{box-sizing:border-box}
    body{margin:0;background:#e9ecf0;font-family:Inter,Arial,sans-serif;color:#1a202c;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .acoes{max-width:210mm;margin:16px auto;display:flex;gap:14px;align-items:center;flex-wrap:wrap;padding:0 12px;font-size:14px;color:#4a5568}
    .acoes button{font:inherit;font-weight:800;border:0;border-radius:999px;background:#cc0000;color:#fff;padding:12px 22px;cursor:pointer}
    .cartaz{width:210mm;height:297mm;overflow:hidden;margin:0 auto 24px;background:#fff;position:relative;padding:18mm 18mm 12mm;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,.12)}
    .cartaz:before{content:"";position:absolute;left:0;right:0;top:0;height:8mm;background:#cc0000}
    .logo{height:19mm;width:auto;display:block;margin:2mm auto 7mm}
    h1{margin:0;font-size:21mm;line-height:1;font-weight:900;letter-spacing:-.03em;color:#0f1318}
    .lead{margin:4mm 0 7mm;font-size:6.6mm;font-weight:700;color:#cc0000;line-height:1.2}
    #qr{width:84mm;height:84mm;margin:0 auto;padding:4.5mm;border:1.2mm solid #0f1318;border-radius:6mm;display:flex;align-items:center;justify-content:center}
    #qr img,#qr canvas{width:73mm!important;height:73mm!important}
    .url{margin:4mm 0 7mm;font-size:5.6mm;font-weight:800;color:#0f1318;letter-spacing:.02em}
    ol{list-style:none;counter-reset:passo;margin:0 auto;padding:0;max-width:152mm;text-align:left}
    ol li{counter-increment:passo;display:flex;gap:4.5mm;align-items:flex-start;margin:0 0 4mm;font-size:5mm;line-height:1.3}
    ol li:before{content:counter(passo);flex:0 0 9mm;height:9mm;border-radius:50%;background:#cc0000;color:#fff;font-weight:900;display:flex;align-items:center;justify-content:center;font-size:5mm}
    ol li span{padding-top:1.3mm}
    .duas{display:grid;grid-template-columns:1fr 1fr;gap:5mm;margin:6mm auto 0;max-width:174mm;text-align:left}
    .duas div{background:#f5f6f8;border-radius:5mm;padding:4mm 5mm}
    .duas b{display:block;font-size:4.6mm;color:#0f1318;margin:0 0 1.2mm}
    .duas p{margin:0;font-size:3.9mm;line-height:1.35;color:#4a5568}
    .rodape{margin:7mm 0 0;font-size:4.4mm;font-weight:700;color:#4a5568}
    @media print{body{background:#fff}.acoes{display:none}.cartaz{margin:0;box-shadow:none}}
  </style>
</head>
<body>
  <div class="acoes"><button type="button" onclick="window.print()">Imprimir cartaz</button><span>Imprima em A4, em cores, e cole perto da entrada ou da recepção.</span></div>
  <article class="cartaz">
    <img class="logo" src="/assets/otim/logo-cvb-rj-480.png" alt="Cruz Vermelha Brasileira · Rio de Janeiro">
    <h1>Ponto da sede</h1>
    <p class="lead">Registre a chegada e a saída pelo celular</p>
    <div id="qr" role="img" aria-label="QR code que abre o ponto da sede: @@URL@@"></div>
    <p class="url">@@URL_VISIVEL@@</p>
    <ol>
      <li><span>Aponte a câmera do celular para o código.</span></li>
      <li><span>Digite seu CPF.</span></li>
      <li><span>Permita a localização e toque em <b>Registrar entrada</b>, <b>Registrar saída</b> ou <b>Confirmar presença</b>.</span></li>
    </ol>
    <div class="duas">
      <div><b>Colaboradores</b><p>Voluntários somam as horas doadas a cada entrada e saída. Os demais registram a presença na sede.</p></div>
      <div><b>Alunos</b><p>Confirme a presença na aula. O comprovante de comparecimento chega por e-mail no fim da aula.</p></div>
    </div>
    <p class="rodape">Sem celular? Use o aparelho da recepção.</p>
  </article>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      if (window.QRCode) new QRCode(document.getElementById('qr'), { text: '@@URL@@', width: 600, height: 600, correctLevel: QRCode.CorrectLevel.M });
      else document.getElementById('qr').textContent = '@@URL_VISIVEL@@';
    });
  </script>
</body>
</html>
"""
URL_PONTO = "https://cruzvermelhariodejaneiro.org/ponto/"

QRCODE = '  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>'


def brl(centavos: int) -> str:
    return "R$ " + f"{centavos / 100:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")


def url_estatico(nome: str) -> str:
    """URL do arquivo em static/ com hash do conteúdo: muda o arquivo, muda a URL, o cache não segura versão velha."""
    conteudo = (STATIC / nome).read_bytes()
    return f"{STATIC_URL}{nome}?v={hashlib.sha256(conteudo).hexdigest()[:10]}"


def passos(atual: int) -> str:
    """Etapas da matrícula no topo: 1 curso escolhido, 2 pagamento, 3 confirmação da turma (0 = sem etapas)."""
    if atual == 0:
        return ""
    etapas = ["Curso escolhido", "Pagamento da inscrição", "Confirmação da turma"]
    itens = []
    for i, nome in enumerate(etapas, 1):
        classe = "feito" if i < atual else ("atual" if i == atual else "")
        marca = '<i class="fa-solid fa-check" aria-hidden="true"></i>' if i < atual else str(i)
        atual_attr = ' aria-current="step"' if i == atual else ""
        itens.append(f'<li class="{classe}"{atual_attr}><span class="ck-passo-n">{marca}</span><span>{nome}</span></li>')
    return '        <ol class="ck-passos" aria-label="Etapas da matrícula">' + "".join(itens) + "</ol>"


# O aviso sem JavaScript diz o que a página faria: gerar o pagamento, conferir o pagamento ou mostrar a inscrição.
NOSCRIPT = {
    "checkout": "Esta página precisa de JavaScript para gerar o pagamento.",
    "pendente": "Esta página precisa de JavaScript para conferir o pagamento.",
    "parabens": "Esta página precisa de JavaScript para mostrar os dados da sua inscrição.",
    "horarios": "Esta página precisa de JavaScript para mostrar o questionário de dias e horários.",
    "comparecimento": "Esta página precisa de JavaScript para mostrar o comprovante.",
    "conferir": "Esta página precisa de JavaScript para conferir o código.",
}


def montar(partes: dict, titulo: str, id_: str, h1: str, lead: str, corpo: str, qrcode: bool, wrap_extra: str = "", passo: int = 2,
           eyebrow: str = "Matrícula cursos presenciais") -> str:
    return (PAGINA
            .replace("@@EYEBROW@@", esc(eyebrow))
            .replace("@@NOSCRIPT@@", NOSCRIPT[id_])
            .replace("@@TITULO@@", esc(titulo)).replace("@@QRCODE@@", QRCODE if qrcode else "")
            .replace("@@ESTILO@@", partes["estilo"])
            .replace("@@CSS_URL@@", url_estatico("checkout.css")).replace("@@JS_URL@@", url_estatico("checkout.js"))
            .replace("@@GA4@@", partes["ga4"]).replace("@@PIXEL@@", partes["pixel"])
            .replace("@@HEADER@@", partes["header"]).replace("@@FOOTER@@", partes["footer"]).replace("@@MENU_JS@@", partes["menu_js"])
            .replace("@@CHAT@@", chat_widget.tags())
            .replace("@@ID@@", id_).replace("@@H1@@", h1).replace("@@LEAD@@", lead).replace("@@WRAP_EXTRA@@", wrap_extra)
            .replace("@@PASSOS@@", passos(passo)).replace("@@CORPO@@", corpo))


def main() -> int:
    home = HOME.read_text(encoding="utf-8")
    dados = json.loads(DADOS.read_text(encoding="utf-8"))
    cursos = {c["slug"]: c for c in dados["cursos"]}
    partes = partes_da_home(home)
    inscricao = brl(dados["inscricao_centavos"])

    opcoes = ""
    for g in dados["grupos"]:
        itens = "".join(f'\n                    <option value="{s}">{esc(cursos[s]["nome"])} · {esc(cursos[s]["carga_horaria"])}</option>' for s in g["cursos"] if s in cursos)
        opcoes += f'\n                  <optgroup label="{esc(g["titulo"])}">{itens}\n                  </optgroup>'

    # Dados que a página precisa antes da API responder: nome, foto e ficha de cada curso.
    cursos_json = json.dumps({s: {
        "nome": c["nome"], "carga_horaria": c.get("carga_horaria", ""), "escolaridade": c.get("escolaridade", ""),
        "valor_curso_centavos": int(c["valor_curso_centavos"]) if c.get("valor_curso_centavos") else None,
        "imagem": f"/matricula-cursos-presenciais/img/{c['imagem']}-480.webp" if c.get("imagem") else None,
    } for s, c in cursos.items()}, ensure_ascii=False).replace("</", "<\\/")

    paginas = {
        "checkout": montar(partes, "Pagar a inscrição | Cruz Vermelha Brasileira Rio de Janeiro", "checkout",
                           "Pagar a inscrição e garantir a vaga",
                           f"Inscrição de {inscricao}, por PIX ou cartão. Sem criar conta e sem escolher turma agora.",
                           CORPO_CHECKOUT.replace("@@OPCOES@@", opcoes).replace("@@CURSOS_JSON@@", cursos_json), qrcode=True, passo=2),
        "pendente": montar(partes, "Pagamento ainda não confirmado | Cruz Vermelha Brasileira Rio de Janeiro", "pendente",
                           "Pagamento ainda não confirmado",
                           "Esta tela não cria login. Quando o pagamento for aprovado, a matrícula é aberta e os dados aparecem aqui e no seu e-mail.",
                           CORPO_PENDENTE, qrcode=True, wrap_extra=' style="max-width:820px"', passo=2),
        "parabens": montar(partes, "Inscrição paga | Cruz Vermelha Brasileira Rio de Janeiro", "parabens",
                           "Parabéns, sua inscrição está paga.",
                           "Guarde este link: ele mostra sua inscrição e o próximo passo.",
                           CORPO_PARABENS, qrcode=False, wrap_extra=' style="max-width:820px"', passo=3),
        # Questionário de dias e horários (29/09/2026): link pessoal, abre só com a inscrição paga.
        "horarios": montar(partes, "Seus horários | Cruz Vermelha Brasileira Rio de Janeiro", "horarios",
                           "Quando você pode fazer as aulas?",
                           "Toque nos dias e horários em que você consegue vir. Leva 30 segundos e ajuda a secretaria a encaixar você na turma certa.",
                           CORPO_HORARIOS, qrcode=False, wrap_extra=' style="max-width:820px"', passo=3),
        # Ponto da sede (29/09/2026): comprovante de comparecimento pelo link pessoal e conferência do código.
        "comparecimento": montar(partes, "Comprovante de comparecimento | Cruz Vermelha Brasileira Rio de Janeiro", "comparecimento",
                                 "Comprovante de comparecimento",
                                 "O comprovante da sua aula presencial. Ele fica disponível quando a aula termina e também chega no seu e-mail.",
                                 CORPO_COMPARECIMENTO, qrcode=False, wrap_extra=' style="max-width:820px"', passo=0, eyebrow="Escola de Educação e Saúde"),
        "conferir": montar(partes, "Conferir documento | Cruz Vermelha Brasileira Rio de Janeiro", "conferir",
                           "Conferir um comprovante ou declaração",
                           "Digite o código de verificação impresso no documento para confirmar que ele foi emitido pela Cruz Vermelha Brasileira Rio de Janeiro.",
                           CORPO_CONFERIR, qrcode=False, wrap_extra=' style="max-width:820px"', passo=0, eyebrow="Conferência de documentos"),
    }
    for nome, html in paginas.items():
        destino = PASTA / nome / "index.html"
        destino.parent.mkdir(parents=True, exist_ok=True)
        html = icones.converter(html, extras={"circle-check"})  # SVG inline; circle-check vem do checkout.js
        destino.write_text(html, encoding="utf-8")
        print(f"gravado {destino.relative_to(RAIZ)} ({len(html.encode('utf-8'))} bytes)")

    # Ponto da sede e cartaz do QR code: páginas próprias, fora do layout do site.
    ponto = {
        PASTA / "ponto" / "index.html": PAGINA_PONTO.replace("@@CSS_URL@@", url_estatico("ponto.css")).replace("@@JS_URL@@", url_estatico("ponto.js")),
        PASTA / "ponto" / "cartaz" / "index.html": PAGINA_CARTAZ.replace("@@URL_VISIVEL@@", URL_PONTO.replace("https://", "").rstrip("/")).replace("@@URL@@", URL_PONTO),
    }
    for uso, (titulo, cabecalho, subtitulo, rodape) in AVISOS_PAGINAS.items():
        ponto[PASTA / "ponto" / uso / "index.html"] = (PAGINA_AVISO
            .replace("@@CSS_URL@@", url_estatico("ponto.css")).replace("@@JS_URL@@", url_estatico("avisos.js"))
            .replace("@@TITULO@@", esc(titulo)).replace("@@PAGINA@@", uso).replace("@@CABECALHO@@", esc(cabecalho))
            .replace("@@SUBTITULO@@", esc(subtitulo)).replace("@@RODAPE@@", esc(rodape)))
    ponto[PASTA / "ponto" / "manifest.json"] = json.dumps(MANIFESTO_PONTO, ensure_ascii=False, indent=2) + "\n"
    for destino, html in ponto.items():
        destino.parent.mkdir(parents=True, exist_ok=True)
        destino.write_text(html, encoding="utf-8")
        print(f"gravado {destino.relative_to(RAIZ)} ({len(html.encode('utf-8'))} bytes)")
    gravar_icones_ponto(PASTA / "ponto")
    print("gravados os ícones do ponto (192, 512 e 512 de máscara)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
