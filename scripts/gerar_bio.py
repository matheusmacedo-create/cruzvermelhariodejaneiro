#!/usr/bin/env python3
"""Gera site/bio/index.html: a página de links da bio do Instagram, dentro do domínio.

Substitui (19/09/2026) a página que ficava em smartpa.ge/rWPY. Ficaram só os três destinos que o
Matheus pediu (cursos na escola, formulário do voluntário e WhatsApp do voluntariado), com os links
exatamente como estavam, no padrão visual da home (cabeçalho, rodapé, GA4, Meta Pixel e chat), para o
tráfego do Instagram entrar em cruzvermelhariodejaneiro.org e cada clique ser medido (GA4 `bio_click`).
Só a copy dos rótulos foi ajustada. Os blocos ficam em BLOCOS.

Imagens em site/bio/img/: WebP gerados uma vez a partir das imagens da página original (avatar e os
cartões de cursos e voluntário) e a imagem de compartilhamento og-bio.jpg.

Uso:  python3 scripts/gerar_bio.py
Depois: publicar site/bio/ (index.html + img/) com scripts/publicar_hostinger.sh e limpar o cache.
Endereço para a bio do Instagram: https://cruzvermelhariodejaneiro.org/bio/?utm_source=ig&utm_medium=social&utm_content=link_in_bio
"""
from __future__ import annotations

import json
from pathlib import Path

import chat_widget
import icones
from gerar_matricula_presencial import HOME, RAIZ, esc, partes_da_home

PASTA = RAIZ / "site" / "bio"
SAIDA = PASTA / "index.html"
ORIGEM = "https://cruzvermelhariodejaneiro.org"
URL_PAGINA = f"{ORIGEM}/bio/"

TITULO = "Cruz Vermelha Brasileira Rio de Janeiro | Links oficiais"
DESCRICAO = ("Links oficiais da Cruz Vermelha Brasileira Rio de Janeiro: cursos presenciais com certificado, cadastro de "
             "voluntário e WhatsApp do voluntariado.")
URL_MATRICULA = f"{ORIGEM}/matricula-cursos-presenciais/"
URL_DOACAO = f"{ORIGEM}/doe/"

# Perguntas que as pessoas fazem ao Google sobre a filial: cada resposta cita os caminhos oficiais e liga
# o restante do ecossistema (matrícula, doação, chat). Texto puro no FAQPage; links só no HTML.
FAQ = [
    ("Como ser voluntário da Cruz Vermelha no Rio de Janeiro?",
     "Preencha o cadastro no formulário oficial do voluntariado, no botão acima. A equipe do voluntariado entra em contato "
     "para explicar as frentes de atuação, a formação inicial e os próximos encontros. Dúvidas antes de se cadastrar podem "
     "ser tiradas no WhatsApp do voluntariado ou no chat deste site.", []),
    ("Quais cursos a Cruz Vermelha Brasileira Rio de Janeiro oferece?",
     "Primeiros socorros (básico e Lei Lucas), suporte básico de vida, punção venosa, bombeiro civil, cuidador de idosos e "
     "micropigmentação labial, todos presenciais na sede, no Centro do Rio, com certificado da Cruz Vermelha Brasileira Rio de Janeiro. "
     "As turmas e os valores completos estão na plataforma da escola; a inscrição de R$ 99 que garante a vaga pode ser "
     "feita na página de matrícula em cursos presenciais.",
     [("página de matrícula em cursos presenciais", "URL_MATRICULA")]),
    ("Como falar com o voluntariado da Cruz Vermelha Brasileira Rio de Janeiro?",
     "Pelo WhatsApp do voluntariado, no botão acima: é o número exclusivo dessa equipe, diferente da secretaria de cursos. "
     "Para cursos, matrícula, doações e parcerias, use o chat de contato deste site: a equipe responde por e-mail em até "
     "3 dias úteis.", []),
    ("Como doar para a Cruz Vermelha do Rio de Janeiro?",
     "Pela página de doação do site, com PIX ou cartão, e nas campanhas de arrecadação de roupas e alimentos da filial. "
     "Toda ajuda vai para as ações humanitárias no estado do Rio de Janeiro.",
     [("página de doação", "URL_DOACAO")]),
    ("Onde fica a Cruz Vermelha no Rio de Janeiro?",
     "Na Praça da Cruz Vermelha, 10, Centro, Rio de Janeiro, CEP 20230-130. É na sede que acontecem os cursos presenciais e "
     "a formação de voluntários.", []),
]
NOME = "Cruz Vermelha Brasileira – Filial Rio de Janeiro"
CHAMADA = "Maior rede de ajuda humanitária do 🌎<br>Doe e nos ajude a salvar vidas!"
IMAGEM_OG = f"{URL_PAGINA}img/og-bio.jpg"

# Só os três destinos que o Matheus pediu (19/09/2026), na ordem em que apareciam na página original.
# Ficaram de fora, por decisão dele: desfile de 7 de Setembro, SOS Venezuela, e-mail do RFL, endereço e Instagram.
BLOCOS = [
    {"tipo": "cartao", "imagem": "cursos", "alt": "Descubra quais cursos temos disponíveis: Cruz Vermelha Brasileira, Rio de Janeiro",
     "titulo": "Saiba mais sobre os nossos cursos", "descricao": "Turmas, valores e inscrição na plataforma da escola.",
     "link": "https://escola.cruzvermelhariodejaneiro.org", "id": "escola"},
    {"tipo": "cartao", "imagem": "voluntario", "alt": "Seja voluntário: junte-se à equipe de voluntários da Cruz Vermelha Brasileira do Rio de Janeiro",
     "titulo": "Quero ser voluntário da Cruz Vermelha Brasileira Rio de Janeiro", "descricao": "Cadastro rápido no formulário do voluntariado.",
     "link": "https://form.spotform.com.br/voluntariocruzvermelharj", "id": "voluntario"},
    {"tipo": "titulo", "texto": "WhatsApp do voluntário"},
    {"tipo": "botao", "estilo": "claro whatsapp", "icone": "fa-brands fa-whatsapp", "titulo": "Falar com o voluntariado no WhatsApp",
     "link": "https://api.whatsapp.com/send?phone=+5521970360264&text=Ol%C3%A1%20vim%20pelo%20link%20da%20bio%20do%20Instagram%2C%20gostaria%20de%20ajuda%20sobre%20o%20voluntariado.%20",
     "id": "whatsapp-voluntariado"},
]
IMAGENS = {"cursos": (700, 367), "voluntario": (700, 367)}


def externo(link: str) -> str:
    """Links para fora do domínio abrem em nova aba; mailto e páginas do próprio site, não."""
    return ' target="_blank" rel="noopener"' if link.startswith("http") and not link.startswith(ORIGEM) else ""


def bloco_html(b: dict, primeiro: bool = False) -> str:
    tipo = b["tipo"]
    if tipo == "separador":
        return '<hr class="bio-sep">'
    if tipo == "titulo":
        return f'<p class="bio-titulo">{esc(b["texto"])}</p>'
    if tipo == "botao":
        estilo = f' {b["estilo"]}' if b.get("estilo") else ""
        return (f'<a class="bio-botao{estilo}" href="{esc(b["link"])}" data-bio="{b["id"]}"{externo(b["link"])}>'
                f'<i class="{b["icone"]}" aria-hidden="true"></i><span>{esc(b["titulo"])}</span>'
                f'<span class="bio-seta" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span></a>')
    if tipo == "cartao":
        img = b["imagem"]
        w, h = IMAGENS[img]
        srcset = f'img/{img}-700.webp 700w, img/{img}-420.webp 420w' if (PASTA / "img" / f"{img}-420.webp").exists() else f'img/{img}-700.webp 700w, img/{img}-1200.webp 1200w'
        carga = 'loading="eager" fetchpriority="high"' if primeiro else 'loading="lazy"'
        return (f'<a class="bio-cartao" href="{esc(b["link"])}" data-bio="{b["id"]}"{externo(b["link"])}>'
                f'<img src="img/{img}-700.webp" srcset="{srcset}" sizes="(max-width: 680px) 100vw, 640px" alt="" width="{w}" height="{h}" {carga} decoding="async">'
                f'<span class="bio-cartao-corpo"><span class="bio-cartao-texto"><b>{esc(b["titulo"])}</b><small>{esc(b["descricao"])}</small></span>'
                f'<span class="bio-cta" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span></span></a>')
    if tipo == "endereco":
        return (f'<div class="bio-endereco"><i class="fa-solid fa-location-dot" aria-hidden="true"></i><div><b>{esc(b["titulo"])}</b>'
                f'<p>{esc(b["texto"])}</p><a href="{esc(b["link"])}" data-bio="{b["id"]}" target="_blank" rel="noopener">Abrir no Google Maps</a></div></div>')
    if tipo == "social":
        return (f'<div class="bio-social"><a href="{esc(b["link"])}" data-bio="{b["id"]}" target="_blank" rel="noopener">'
                f'<i class="fa-brands fa-instagram" aria-hidden="true"></i>{esc(b["rotulo"])}</a></div>')
    raise SystemExit(f"bloco desconhecido: {tipo}")


CSS = """
  <style>
    .bio-topo { background: var(--soft); border-bottom: 1px solid var(--line); padding: 44px 0 36px; text-align: center; }
    .bio-avatar { width: 128px; height: 128px; border-radius: 50%; margin: 0 auto 16px; background: #fff; border: 4px solid #fff; box-shadow: var(--shadow); object-fit: cover; }
    .bio-topo h1 { color: var(--black); font-size: clamp(1.7rem, 4vw, 2.4rem); letter-spacing: -.03em; line-height: 1.1; margin: 8px 0 10px; }
    .bio-topo .lead { max-width: 36ch; margin: 0 auto; font-size: 1.05rem; color: #5b6776; }
    .bio-lista { padding: 32px 0 72px; }
    .bio-lista .wrap { max-width: 640px; display: grid; gap: 16px; }
    .bio-botao { display: flex; align-items: center; gap: 14px; padding: 15px 20px; border-radius: 999px; background: var(--red); color: #fff; font-weight: 800; line-height: 1.25; box-shadow: 0 12px 30px rgba(237, 27, 46, .22); transition: transform .18s, background .18s, border-color .18s; text-align: left; }
    .bio-botao:hover { transform: translateY(-2px); background: var(--red-dark); }
    .bio-botao > i { width: 40px; height: 40px; border-radius: 50%; background: rgba(255, 255, 255, .18); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 1.15rem; }
    .bio-botao span { flex: 1; min-width: 0; }
    .bio-botao .bio-seta { flex: none; display: inline-flex; opacity: .85; font-size: .9rem; }
    .bio-botao.claro { background: #fff; color: var(--black); border: 1.5px solid var(--line); box-shadow: none; }
    .bio-botao.claro > i { background: var(--soft); color: var(--red); }
    .bio-botao.claro:hover { background: #fff; border-color: var(--red); }
    .bio-botao.whatsapp > i { color: #128c7e; }
    .bio-cartao { display: block; background: #fff; border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; box-shadow: 0 6px 22px rgba(16, 24, 40, .06); transition: transform .18s, box-shadow .18s; color: var(--text); }
    .bio-cartao:hover { transform: translateY(-2px); box-shadow: var(--shadow); }
    .bio-cartao img { width: 100%; height: auto; aspect-ratio: 700 / 367; object-fit: cover; display: block; background: var(--soft); }
    .bio-cartao-corpo { padding: 18px 20px 20px; display: flex; align-items: center; gap: 14px; }
    .bio-cartao-texto { flex: 1; min-width: 0; display: block; }
    .bio-cartao b { display: block; color: var(--black); font-size: 1.08rem; line-height: 1.25; }
    .bio-cartao small { display: block; color: #5b6776; margin-top: 4px; font-size: .92rem; line-height: 1.45; }
    .bio-cta { flex: none; width: 40px; height: 40px; border-radius: 50%; background: var(--red); color: #fff; display: inline-flex; align-items: center; justify-content: center; }
    .bio-sep { border: 0; height: 3px; width: 64px; margin: 2px auto; background: var(--red); border-radius: 3px; }
    .bio-titulo { margin: 10px 0 -6px; text-align: center; color: #5b6776; font-size: .78rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
    .bio-endereco { display: flex; gap: 14px; align-items: flex-start; background: #fff; border: 1px solid var(--line); border-radius: var(--radius); padding: 18px 20px; }
    .bio-endereco > i { width: 40px; height: 40px; border-radius: 12px; background: var(--soft); color: var(--red); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 1.1rem; }
    .bio-endereco b { display: block; color: var(--black); }
    .bio-endereco p { margin: 4px 0 8px; color: var(--muted); font-size: .95rem; }
    .bio-endereco a { color: var(--red); font-weight: 700; text-decoration: underline; }
    .bio-faq { background: var(--soft); border-top: 1px solid var(--line); padding: 48px 0 64px; }
    .bio-faq .wrap { max-width: 720px; }
    .bio-faq h2 { font-size: clamp(1.4rem, 3vw, 1.9rem); letter-spacing: -.025em; margin: 6px 0 14px; }
    .bio-faq details { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 14px 18px; margin-bottom: 10px; }
    .bio-faq summary { cursor: pointer; font-weight: 700; color: var(--black); }
    .bio-faq details p { margin: 10px 0 0; color: var(--text); }
    .bio-faq a { color: var(--red); font-weight: 700; text-decoration: underline; }
    .bio-social { text-align: center; padding-top: 8px; }
    .bio-social a { display: inline-flex; align-items: center; gap: 10px; padding: 10px 20px 10px 10px; border-radius: 999px; border: 1.5px solid var(--line); background: #fff; color: var(--black); font-weight: 800; transition: border-color .18s; }
    .bio-social a:hover { border-color: var(--red); }
    .bio-social a i { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(45deg, #f9ce34, #ee2a7b, #6228d7); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 1.1rem; }
    @media (max-width: 620px) {
      .bio-topo { padding: 30px 0 26px; }
      .bio-avatar { width: 108px; height: 108px; }
      .bio-lista { padding: 22px 0 60px; }
      .bio-lista .wrap { gap: 14px; }
      .bio-cartao-corpo { padding: 14px 16px 16px; }
      .bio-botao { padding: 13px 16px; }
      /* O CSS da home esconde qualquer <nav> abaixo de 920px; aqui os links do menu voltam com o menu aberto. */
      .main-header .header-collapse .nav-links { display: flex !important; }
    }
    @media (min-width: 621px) and (max-width: 920px) { .main-header .header-collapse .nav-links { display: flex !important; } }
  </style>"""

JS = """
  <script>
    (function () {
      // UTMs, fbclid e gclid com que a pessoa chegou (ex.: ?utm_source=ig da bio) seguem para a plataforma da
      // escola, que fica no nosso domínio: lá o GA4 e o Pixel enxergam a origem mesmo sem cookie compartilhado.
      var origem = new URLSearchParams();
      new URLSearchParams(location.search).forEach(function (v, k) { if (/^(utm_|fbclid$|gclid$)/.test(k)) origem.set(k, v); });
      var escola = document.querySelector('[data-bio="escola"]');
      if (escola && Array.from(origem.keys()).length) {
        try { var u = new URL(escola.href); origem.forEach(function (v, k) { u.searchParams.set(k, v); }); escola.href = u.toString(); } catch (e) {}
      }
      // Cada clique vira um evento: GA4 bio_click (link_id, link_url, link_text, outbound) e, no Meta, BioClick;
      // o WhatsApp também dispara o evento padrão Contact (pessoa iniciando contato com a organização).
      document.querySelectorAll('[data-bio]').forEach(function (a) {
        a.addEventListener('click', function () {
          var id = a.getAttribute('data-bio'), url = a.href, destino = url;
          try { var d = new URL(url); destino = d.host + d.pathname; } catch (e) {}
          var titulo = a.querySelector('b') || a.querySelector('span');
          var dados = { link_id: id, link_url: destino.slice(0, 100), link_text: ((titulo ? titulo.textContent : a.textContent) || '').replace(/\s+/g, ' ').trim().slice(0, 100), outbound: !url.startsWith(location.origin) };
          try { if (window.gtag) gtag('event', 'bio_click', dados); } catch (e) {}
          try {
            if (window.fbq) {
              fbq('trackCustom', 'BioClick', { link_id: id });
              if (id === 'whatsapp-voluntariado') fbq('track', 'Contact', { content_name: 'WhatsApp do voluntariado', content_category: 'bio' });
            }
          } catch (e) {}
        });
      });
    })();
  </script>"""


def main() -> int:
    home = HOME.read_text(encoding="utf-8")
    partes = partes_da_home(home)
    header = partes["header"].replace(' aria-current="page"', "")  # a matrícula não é a página atual aqui
    blocos = "\n          ".join(bloco_html(b, i == 0) for i, b in enumerate(BLOCOS))

    def faq_html(pergunta: str, resposta: str, links: list) -> str:
        texto = esc(resposta)
        for rotulo, alvo in links:
            url = {"URL_MATRICULA": URL_MATRICULA, "URL_DOACAO": URL_DOACAO}[alvo]
            texto = texto.replace(esc(rotulo), f'<a href="{url}">{esc(rotulo)}</a>', 1)
        return f"<details><summary>{esc(pergunta)}</summary><p>{texto}</p></details>"
    faq = "".join(faq_html(p, r, l) for p, r, l in FAQ)

    # GA4: esta página entra no grupo de conteúdo "bio" (relatórios por grupo). O snippet vem da home.
    ga4 = partes["ga4"].replace("gtag('config', 'G-", "gtag('set', { content_group: 'bio' });\n    gtag('config', 'G-", 1)
    assert "content_group: 'bio'" in ga4, "não achei o gtag('config') da home para inserir o content_group"
    # Nesta página o gtag.js entra sem esperar o load: quem chega do Instagram toca num link em segundos e o
    # bio_click precisa do GA4 carregado. O Pixel segue adiado como na home.
    pixel = partes["pixel"]
    adiado = "['https://www.googletagmanager.com/gtag/js?id=G-HDYZZ5JZHF', 'https://connect.facebook.net/en_US/fbevents.js']"
    assert adiado in pixel, "o carregador adiado da home mudou; ajuste gerar_bio.py"
    pixel = pixel.replace(adiado, "['https://connect.facebook.net/en_US/fbevents.js']", 1)
    ga4 = '  <script async src="https://www.googletagmanager.com/gtag/js?id=G-HDYZZ5JZHF"></script>\n' + ga4

    ld = [
        {"@context": "https://schema.org", "@type": "BreadcrumbList", "itemListElement": [
            {"@type": "ListItem", "position": 1, "name": "Início", "item": f"{ORIGEM}/"},
            {"@type": "ListItem", "position": 2, "name": "Links", "item": URL_PAGINA}]},
        {"@context": "https://schema.org", "@type": "WebPage", "@id": f"{URL_PAGINA}#pagina", "url": URL_PAGINA, "name": TITULO,
         "description": DESCRICAO, "inLanguage": "pt-BR", "isPartOf": {"@id": f"{ORIGEM}/#site"}, "about": {"@id": f"{ORIGEM}/#organizacao"},
         "primaryImageOfPage": {"@type": "ImageObject", "url": IMAGEM_OG, "width": 1200, "height": 630},
         "significantLink": [b["link"] for b in BLOCOS if b.get("link") and b["link"].startswith("http")]},
        {"@context": "https://schema.org", "@type": "FAQPage", "mainEntity": [
            {"@type": "Question", "name": p, "acceptedAnswer": {"@type": "Answer", "text": r}} for p, r, _ in FAQ]},
    ]
    ld_html = "".join(f'\n  <script type="application/ld+json">\n{json.dumps(d, ensure_ascii=False, indent=2)}\n  </script>' for d in ld)
    for d in ld:
        assert "</" not in json.dumps(d, ensure_ascii=False)

    pagina = f"""<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <title>{esc(TITULO)}</title>
  <meta name="description" content="{esc(DESCRICAO)}">
  <link rel="canonical" href="{URL_PAGINA}">
  <meta name="robots" content="index, follow">
  <meta property="og:type" content="website">
  <meta property="og:locale" content="pt_BR">
  <meta property="og:site_name" content="Cruz Vermelha Brasileira - Rio de Janeiro">
  <meta property="og:title" content="{esc(TITULO)}">
  <meta property="og:description" content="{esc(DESCRICAO)}">
  <meta property="og:url" content="{URL_PAGINA}">
  <meta property="og:image" content="{IMAGEM_OG}">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="Faça a diferença ou construa seu futuro: seja voluntário ou faça cursos na Cruz Vermelha Brasileira Rio de Janeiro">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="{esc(TITULO)}">
  <meta name="twitter:description" content="{esc(DESCRICAO)}">
  <meta name="twitter:image" content="{IMAGEM_OG}">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"></noscript>
  <link rel="preload" as="image" href="img/avatar-256.webp" imagesrcset="img/avatar-256.webp 256w, img/avatar-512.webp 512w" imagesizes="128px">
  <!-- Estilos copiados da home (site/index.html): mesmo padrão visual da filial. -->
{partes["estilo"]}
{CSS}
{ga4}
{pixel}
{ld_html}
</head>
<body>
{header}

  <main id="bio">
    <section class="bio-topo" aria-labelledby="bio-titulo">
      <div class="wrap">
        <img class="bio-avatar" src="img/avatar-256.webp" srcset="img/avatar-256.webp 256w, img/avatar-512.webp 512w" sizes="128px" alt="Cruz Vermelha Brasileira – Rio de Janeiro" width="256" height="256" fetchpriority="high">
        <p class="eyebrow">Instagram · links oficiais</p>
        <h1 id="bio-titulo">{esc(NOME)}</h1>
        <p class="lead">{CHAMADA}</p>
      </div>
    </section>

    <section class="bio-lista" aria-label="Links oficiais">
      <div class="wrap">
          {blocos}
      </div>
    </section>

    <section class="bio-faq" aria-labelledby="bio-faq-titulo">
      <div class="wrap">
        <p class="eyebrow">Perguntas frequentes</p>
        <h2 id="bio-faq-titulo">Cursos, voluntariado e contato da Cruz Vermelha Brasileira Rio de Janeiro</h2>
        {faq}
      </div>
    </section>
  </main>

{partes["footer"]}

{partes["menu_js"]}
{JS}
{chat_widget.tags()}
</body>
</html>
"""
    pagina = icones.converter(pagina)
    SAIDA.parent.mkdir(parents=True, exist_ok=True)
    SAIDA.write_text(pagina, encoding="utf-8")
    print(f"gravado {SAIDA.relative_to(RAIZ)} ({len(pagina.encode('utf-8'))} bytes, {len(BLOCOS)} blocos)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
