#!/usr/bin/env python3
"""Gera site/dia-das-criancas/: a página da ação do Dia das Crianças na Praça da Cruz Vermelha (13/10/2026).

Ação aberta e gratuita na praça em frente ao Palácio da Cruz Vermelha, com três setores da filial
(Juventude, Primeiros Socorros e Educação e Saúde, como no quadro de setores da sede), das 9h às 16h.
Os dados do evento ficam num lugar só (EVENTO, abaixo; data e horário por extenso saem dele) e alimentam a
página, o JSON-LD Event (o que o Google usa para mostrar o evento na busca), o link do Google Agenda e o
arquivo .ics (iPhone e Outlook). O card da home (site/index.html, "Campanhas ativas") é editado à mão.

Fotos: ações anteriores dos voluntários com crianças, com autorização de uso de imagem dos responsáveis
(confirmada pelo Matheus em 05/10/2026). Ficam só no servidor, como as outras fotos do site
(site/assets/, fora do Git); as versões otimizadas saem de scripts/otimizar_imagens.py.

Cabeçalho, rodapé, CSS, GA4, Pixel (com o aviso de cookies) e o script do menu vêm de site/index.html,
como nos outros geradores; o chat de contato entra no fim.

Uso:  python3 scripts/gerar_dia_das_criancas.py
Depois: publicar a lista scripts/publicacao-dia-das-criancas.txt e limpar o cache.
Depois do evento: trocar a página por um "como foi" (ou tirá-la do card da home e do sitemap).
"""
from __future__ import annotations

import json
from datetime import datetime, timedelta, timezone
from urllib.parse import quote, urlencode

import chat_widget
import icones
from gerar_matricula_presencial import HOME, RAIZ, esc, partes_da_home

PASTA = RAIZ / "site" / "dia-das-criancas"
SAIDA = PASTA / "index.html"
SAIDA_ICS = PASTA / "dia-das-criancas.ics"
OTIM = RAIZ / "site" / "assets" / "otim"
ORIGEM = "https://cruzvermelhariodejaneiro.org"
URL_PAGINA = f"{ORIGEM}/dia-das-criancas/"
URL_VOLUNTARIO = "https://form.spotform.com.br/voluntariocruzvermelharj"

# ----------------------------------------------------------------------------- o evento (fonte única)
FUSO = timezone(timedelta(hours=-3))  # Rio de Janeiro: sem horário de verão desde 2019
EVENTO = {
    "nome": "Dia das Crianças na Praça da Cruz Vermelha",
    "inicio": datetime(2026, 10, 13, 9, 0, tzinfo=FUSO),
    "fim": datetime(2026, 10, 13, 16, 0, tzinfo=FUSO),
    "local": "Praça da Cruz Vermelha, em frente ao Palácio da Cruz Vermelha",
    "endereco": "Praça da Cruz Vermelha, 10",
    "bairro": "Centro",
    "cidade": "Rio de Janeiro",
    "uf": "RJ",
    "cep": "20230-130",
    "resumo": ("Brincadeiras, primeiros socorros e saúde para crianças e famílias, com os setores de Juventude, "
               "Primeiros Socorros e Educação e Saúde da Cruz Vermelha Brasileira Rio de Janeiro. Aberto ao público e gratuito."),
}
# Data e horário por extenso, sempre a partir de EVENTO (título, descrição, selo, fatos, WhatsApp e serviço).
DIAS = ["segunda-feira", "terça-feira", "quarta-feira", "quinta-feira", "sexta-feira", "sábado", "domingo"]
MESES = ["janeiro", "fevereiro", "março", "abril", "maio", "junho", "julho", "agosto", "setembro", "outubro",
         "novembro", "dezembro"]
_ini, _fim = EVENTO["inicio"], EVENTO["fim"]
assert _ini.minute == 0 and _fim.minute == 0 and _ini.date() == _fim.date(), "os textos supõem horas cheias num dia só"
DIA_SEMANA = DIAS[_ini.weekday()]                                  # terça-feira
DIA_CURTO = DIA_SEMANA.split("-")[0].capitalize()                  # Terça
DATA_LONGA = f"{_ini.day} de {MESES[_ini.month - 1]}"              # 13 de outubro
DATA_CURTA = f"{_ini:%d/%m}"                                       # 13/10
HORARIO = f"{_ini.hour}h às {_fim.hour}h"                          # 9h às 16h
ARTIGO = "um" if _ini.weekday() >= 5 else "uma"                    # um sábado / uma terça-feira
ENDERECO_COMPLETO = f'{EVENTO["endereco"]}, {EVENTO["bairro"]}, {EVENTO["cidade"]} - {EVENTO["uf"]}, {EVENTO["cep"]}'
# O mesmo endereço de mapa do rodapé da home.
URL_MAPA = "https://www.google.com/maps/search/?api=1&query=" + quote(f'{EVENTO["endereco"]}, {EVENTO["bairro"]}, {EVENTO["cidade"]}, {EVENTO["cep"]}')

TITULO = f'{EVENTO["nome"]} – {DATA_CURTA}'
DESCRICAO = (f"{DIA_CURTO}, {DATA_LONGA}, das {HORARIO}: brincadeiras, primeiros socorros e saúde para crianças na Praça "
             "da Cruz Vermelha, no Centro do Rio. Aberto e gratuito.")
IMAGEM_OG = f"{ORIGEM}/assets/otim/og-dia-das-criancas.jpg"

# Os setores do quadro da sede para o dia 13 ("Juventude, PS e Educação e Saúde").
SETORES = [
    ("juventude", "fa-solid fa-children", "Juventude", "Brincadeiras e jogos",
     "Jogos, desafios e brincadeiras em grupo com os voluntários da Juventude, para a criançada se divertir e fazer amigos."),
    ("primeiros-socorros", "fa-solid fa-kit-medical", "Primeiros Socorros", "Primeiros socorros para pequenos",
     "Noções básicas de primeiros socorros em linguagem que criança entende, e quando e como pedir ajuda."),
    ("educacao-saude", "fa-solid fa-heart-pulse", "Educação e Saúde", "Saúde e cuidado",
     "Atividades educativas e orientações de saúde para as crianças e para quem cuida delas."),
]

# Fotos (site/assets/otim/, de scripts/otimizar_imagens.py): base, versões (largura, altura), alt e legenda.
HERO = ("dia-criancas-juventude", [(480, 419), (720, 629), (960, 838), (1080, 943)],
        "Coordenadora de Juventude da Cruz Vermelha Brasileira Rio de Janeiro sorrindo com crianças do projeto Impacto das Cores")
GALERIA = [
    ("dia-criancas-brincadeira-arcos", [(480, 459), (932, 891)],
     "Voluntária da Cruz Vermelha conduz uma brincadeira de pular nos arcos com crianças numa quadra",
     "Brincadeira com arcos conduzida por voluntária"),
    ("dia-criancas-primeiros-socorros", [(480, 413), (960, 827)],
     "Voluntária da Cruz Vermelha orienta jovens numa atividade prática de primeiros socorros",
     "Atividade prática de primeiros socorros"),
    ("dia-criancas-corrida-saco", [(480, 455), (960, 910)],
     "Crianças se preparam para a corrida de saco com uma voluntária da Cruz Vermelha",
     "Corrida de saco com a turma"),
    ("dia-criancas-voluntaria", [(480, 418), (960, 836)],
     "Voluntária da Cruz Vermelha abraçada a três meninas numa ação na rua",
     "Voluntária com crianças numa ação na rua"),
]


def conferir_fotos() -> None:
    """Se as fotos estão aqui (site/assets/ fica fora do Git), as medidas da tabela têm de bater com os arquivos."""
    if not OTIM.is_dir():
        return
    from PIL import Image
    for base, versoes, *_ in [HERO, *GALERIA]:
        for largura, altura in versoes:
            arq = OTIM / f"{base}-{largura}.webp"
            if arq.exists():
                with Image.open(arq) as im:
                    assert im.size == (largura, altura), f"{arq.name}: {im.size} na tabela {(largura, altura)}"


def srcset(base: str, versoes: list, ext: str) -> str:
    return ", ".join(f"/assets/otim/{base}-{l}.{ext} {l}w" for l, _ in versoes)


def url_agenda_google() -> str:
    i, f = EVENTO["inicio"], EVENTO["fim"]
    return "https://calendar.google.com/calendar/render?" + urlencode({
        "action": "TEMPLATE", "text": EVENTO["nome"],
        "dates": f'{i:%Y%m%dT%H%M%S}/{f:%Y%m%dT%H%M%S}', "ctz": "America/Sao_Paulo",
        "details": f'{EVENTO["resumo"]}\n\n{URL_PAGINA}', "location": ENDERECO_COMPLETO,
    })


def url_whatsapp() -> str:
    texto = (f'{EVENTO["nome"]}! {DIA_CURTO}, {DATA_CURTA}, das {HORARIO}, em frente ao Palácio da Cruz Vermelha, no Centro do Rio. '
             "Brincadeiras, primeiros socorros e saúde para as crianças. Aberto e gratuito. "
             f"{URL_PAGINA}?utm_source=whatsapp&utm_medium=compartilhamento&utm_campaign=dia-das-criancas")
    return "https://api.whatsapp.com/send?" + urlencode({"text": texto})


def ics() -> str:
    """Evento em iCalendar (RFC 5545): horários em UTC, texto escapado e linhas dobradas em 75 bytes."""
    def texto(v: str) -> str:
        return v.replace("\\", "\\\\").replace(";", "\\;").replace(",", "\\,").replace("\n", "\\n")

    def dobrar(linha: str) -> str:
        pedacos, atual = [], b""
        for ch in linha:
            b = ch.encode("utf-8")
            if len(atual) + len(b) > (75 if not pedacos else 74):
                pedacos.append(atual.decode("utf-8"))
                atual = b""
            atual += b
        pedacos.append(atual.decode("utf-8"))
        return "\r\n ".join(pedacos)

    utc = lambda d: d.astimezone(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    linhas = [
        "BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//Cruz Vermelha Brasileira Rio de Janeiro//Dia das Criancas//PT",
        "CALSCALE:GREGORIAN", "METHOD:PUBLISH", "BEGIN:VEVENT",
        "UID:dia-das-criancas-2026@cruzvermelhariodejaneiro.org",
        "DTSTAMP:20261005T120000Z",  # fixo: o gerador reproduz o mesmo arquivo
        f'DTSTART:{utc(EVENTO["inicio"])}', f'DTEND:{utc(EVENTO["fim"])}',
        f'SUMMARY:{texto(EVENTO["nome"])}',
        f'DESCRIPTION:{texto(EVENTO["resumo"] + chr(10) + chr(10) + URL_PAGINA)}',
        f'LOCATION:{texto(EVENTO["local"] + " - " + ENDERECO_COMPLETO)}',
        f"URL:{URL_PAGINA}", "END:VEVENT", "END:VCALENDAR",
    ]
    return "\r\n".join(dobrar(l) for l in linhas) + "\r\n"


def json_ld() -> str:
    organizacao = {"@type": "NGO", "@id": f"{ORIGEM}/#organizacao",
                   "name": "Cruz Vermelha Brasileira - Filial do Estado do Rio de Janeiro", "url": f"{ORIGEM}/"}
    blocos = [
        {"@context": "https://schema.org", "@type": "BreadcrumbList", "itemListElement": [
            {"@type": "ListItem", "position": 1, "name": "Início", "item": f"{ORIGEM}/"},
            {"@type": "ListItem", "position": 2, "name": "Dia das Crianças na Praça", "item": URL_PAGINA}]},
        {"@context": "https://schema.org", "@type": "Event", "@id": f"{URL_PAGINA}#evento",
         "name": EVENTO["nome"], "description": EVENTO["resumo"], "url": URL_PAGINA, "inLanguage": "pt-BR",
         "startDate": EVENTO["inicio"].isoformat(), "endDate": EVENTO["fim"].isoformat(),
         "eventStatus": "https://schema.org/EventScheduled",
         "eventAttendanceMode": "https://schema.org/OfflineEventAttendanceMode",
         "isAccessibleForFree": True,
         "location": {"@type": "Place", "name": EVENTO["local"], "address": {
             "@type": "PostalAddress", "streetAddress": EVENTO["endereco"], "addressLocality": EVENTO["cidade"],
             "addressRegion": EVENTO["uf"], "postalCode": EVENTO["cep"], "addressCountry": "BR"}},
         "image": [IMAGEM_OG, f'{ORIGEM}/assets/otim/{HERO[0]}-1080.webp'],
         "organizer": organizacao, "performer": organizacao,
         "offers": {"@type": "Offer", "price": "0", "priceCurrency": "BRL", "availability": "https://schema.org/InStock",
                    "url": URL_PAGINA, "validFrom": "2026-10-05T00:00:00-03:00"},
         "audience": {"@type": "PeopleAudience", "audienceType": "Crianças e famílias"}},
        {"@context": "https://schema.org", "@type": "WebPage", "@id": f"{URL_PAGINA}#pagina", "url": URL_PAGINA,
         "name": TITULO, "description": DESCRICAO, "inLanguage": "pt-BR", "isPartOf": {"@id": f"{ORIGEM}/#site"},
         "about": {"@id": f"{URL_PAGINA}#evento"},
         "primaryImageOfPage": {"@type": "ImageObject", "url": IMAGEM_OG, "width": 1200, "height": 630}},
    ]
    saida = ""
    for d in blocos:
        texto = json.dumps(d, ensure_ascii=False, indent=2)
        assert "</" not in texto
        saida += f'\n  <script type="application/ld+json">\n{texto}\n  </script>'
    return saida


CSS = """
  <style>
    /* Cinza de apoio com contraste AA (5,9:1 no branco); o --muted da home (#718096) fica em 4,0:1. */
    #dia-das-criancas { --dc-suave: #5b6576; }
    .dc-topo { background: linear-gradient(180deg, #fff 0%, var(--soft) 100%); border-bottom: 1px solid var(--line); padding: 56px 0 64px; }
    /* Topo sem caixas aninhadas: texto na coluna 1, foto na 2 (ocupando as linhas do texto). No celular a ordem
       do HTML vale, e a foto aparece logo depois do título. A última linha (1fr) absorve sobra se a foto for mais alta. */
    .dc-topo-grade { display: grid; grid-template-columns: 1.1fr .9fr; grid-template-rows: repeat(6, auto) 1fr; column-gap: 48px; }
    .dc-topo-grade > * { grid-column: 1; }
    .dc-topo-grade > .dc-topo-foto { grid-column: 2; grid-row: 1 / -1; align-self: center; }
    .dc-topo h1 { color: var(--black); font-size: clamp(2rem, 4.6vw, 3.2rem); line-height: 1.05; letter-spacing: -.035em; margin: 10px 0 16px; }
    .dc-topo .lead { font-size: 1.12rem; color: #4a5565; }
    .dc-fatos { list-style: none; padding: 0; margin: 26px 0 0; display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .dc-fatos li { display: flex; gap: 12px; align-items: flex-start; background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 13px 14px; line-height: 1.35; color: var(--text); font-size: .95rem; }
    .dc-fatos li > i { width: 38px; height: 38px; border-radius: 11px; background: #fdecec; color: var(--red); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 1.05rem; }
    .dc-fatos b { display: block; color: var(--black); }
    .dc-acoes { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 26px; }
    .dc-acoes .btn i { margin-right: 8px; }
    .dc-ics { margin: 12px 0 0; font-size: .9rem; color: var(--dc-suave); }
    .dc-ics a, .dc-dl a { color: var(--red); font-weight: 700; text-decoration: underline; }
    .dc-topo-foto { margin: 0; position: relative; }
    .dc-topo-foto img { width: 100%; height: auto; display: block; border-radius: 22px; box-shadow: 0 24px 60px rgba(16, 24, 40, .18); aspect-ratio: 1080 / 943; object-fit: cover; background: var(--soft); }
    .dc-selo { position: absolute; left: -14px; bottom: 22px; background: var(--red); color: #fff; border-radius: 16px; padding: 12px 16px; box-shadow: 0 14px 30px rgba(204, 0, 0, .3); line-height: 1.1; text-align: center; }
    .dc-selo b { display: block; font-size: 1.9rem; letter-spacing: -.03em; }
    .dc-selo span { font-size: .78rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
    .dc-setores .lead, .dc-fotos .lead { margin-bottom: 0; color: var(--dc-suave); }
    .dc-cartoes { display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px; margin-top: 34px; }
    .dc-cartao { background: #fff; border: 1px solid var(--line); border-radius: var(--radius); padding: 26px 24px; box-shadow: 0 6px 22px rgba(16, 24, 40, .05); }
    .dc-icone { width: 54px; height: 54px; border-radius: 16px; background: var(--red); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 1.45rem; box-shadow: 0 10px 24px rgba(204, 0, 0, .25); }
    .dc-setor { margin: 18px 0 2px; color: var(--red); font-size: .78rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
    .dc-cartao h3 { margin: 0 0 8px; color: var(--black); font-size: 1.2rem; }
    .dc-cartao p:last-child { margin: 0; color: var(--dc-suave); }
    .dc-nota { margin: 18px 0 0; font-size: .88rem; color: var(--dc-suave); }
    .dc-fotos { background: var(--soft); }
    .dc-galeria { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-top: 30px; }
    .dc-galeria figure { margin: 0; background: #fff; border: 1px solid var(--line); border-radius: 14px; overflow: hidden; }
    .dc-galeria img { width: 100%; height: auto; aspect-ratio: 1 / 1; object-fit: cover; display: block; background: var(--line); }
    .dc-galeria figcaption { padding: 10px 12px 12px; font-size: .85rem; color: var(--dc-suave); line-height: 1.35; }
    .dc-servico-grade { display: grid; grid-template-columns: 1.25fr .75fr; gap: 40px; align-items: start; }
    .dc-dl { display: grid; grid-template-columns: 150px 1fr; gap: 0; margin: 22px 0 0; border-top: 1px solid var(--line); }
    .dc-dl dt, .dc-dl dd { margin: 0; padding: 14px 0; border-bottom: 1px solid var(--line); }
    .dc-dl dt { font-weight: 800; color: var(--black); }
    .dc-dl dd { color: var(--text); }
    .dc-compartilhar { background: var(--soft); border: 1px solid var(--line); border-radius: var(--radius); padding: 26px 24px; display: grid; gap: 12px; }
    .dc-compartilhar h3 { margin: 0; color: var(--black); font-size: 1.25rem; }
    .dc-compartilhar p { margin: 0; color: var(--dc-suave); }
    .dc-compartilhar .btn { width: 100%; }
    .dc-compartilhar .btn i { margin-right: 8px; }
    .dc-whats { background: #0e7266; color: #fff; box-shadow: 0 10px 24px rgba(14, 114, 102, .25); }
    .dc-whats:hover { background: #075e54; }
    .dc-copiado { min-height: 1.2em; font-size: .88rem; color: #0e7266; font-weight: 700; }
    .dc-voluntario { background: var(--red); color: #fff; text-align: center; padding: 64px 0; }
    .dc-voluntario-titulo { color: #fff; margin: 0 0 10px; font-size: clamp(1.5rem, 3vw, 2.1rem); font-weight: 800; line-height: 1.15; letter-spacing: -.03em; }
    .dc-voluntario p:not(.dc-voluntario-titulo) { margin: 0 auto 22px; max-width: 56ch; color: rgba(255, 255, 255, .92); }
    @media (max-width: 920px) {
      .dc-topo { padding: 34px 0 44px; }
      .dc-servico-grade { grid-template-columns: 1fr; gap: 30px; }
      .dc-topo-grade { grid-template-columns: 1fr; grid-template-rows: none; }
      .dc-topo-grade > .dc-topo-foto { grid-column: 1; grid-row: auto; margin: 4px 0 22px; }
      .dc-cartoes { grid-template-columns: 1fr; gap: 14px; }
      .dc-galeria { grid-template-columns: 1fr 1fr; gap: 12px; }
      .dc-selo { left: 12px; bottom: 12px; }
      /* O CSS da home esconde qualquer <nav> abaixo de 920px; aqui os links do menu voltam com o menu aberto. */
      .main-header .header-collapse .nav-links { display: flex !important; }
    }
    @media (max-width: 560px) {
      .dc-topo .lead { font-size: 1.02rem; }
      /* No celular os quatro fatos viram uma lista só, com divisórias: menos rolagem até os botões. */
      .dc-fatos { grid-template-columns: 1fr; gap: 0; margin-top: 18px; background: #fff; border: 1px solid var(--line); border-radius: 14px; }
      .dc-fatos li { border: 0; border-radius: 0; background: none; padding: 11px 14px; align-items: center; }
      .dc-fatos li + li { border-top: 1px solid var(--line); }
      .dc-fatos li > i { width: 34px; height: 34px; border-radius: 10px; font-size: .95rem; }
      .dc-acoes .btn { flex: 1 1 100%; }
      .dc-dl { grid-template-columns: 1fr; }
      .dc-dl dt { padding-bottom: 2px; border-bottom: 0; }
      .dc-dl dd { padding-top: 0; }
    }
  </style>"""

JS = """
  <script>
    (function () {
      // Copiar o link da página (para colar no Instagram, no grupo da escola...). Sem a API, mostra o endereço.
      var copiar = document.querySelector('[data-dc="copiar"]');
      var aviso = document.querySelector('.dc-copiado');
      if (copiar) copiar.addEventListener('click', function () {
        var url = copiar.getAttribute('data-url');
        var mostrar = function (t) { if (aviso) aviso.textContent = t; };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(function () { mostrar('Link copiado!'); }, function () { mostrar(url); });
        } else { mostrar(url); }
      });
      // Cliques que importam (agenda, mapa, compartilhar, voluntário) viram o evento dia_criancas_click no GA4.
      // O gtag só manda com a permissão de estatística (Consent Mode e aviso de cookies).
      document.querySelectorAll('[data-dc]').forEach(function (el) {
        el.addEventListener('click', function () {
          try { if (window.gtag) gtag('event', 'dia_criancas_click', { acao: el.getAttribute('data-dc') }); } catch (e) {}
        });
      });
    })();
  </script>"""


def main() -> int:
    conferir_fotos()
    home = HOME.read_text(encoding="utf-8")
    partes = partes_da_home(home)
    header = partes["header"].replace(' aria-current="page"', "")  # a matrícula não é a página atual aqui
    # GA4: a página entra no grupo de conteúdo "eventos" (relatórios por grupo). O snippet vem da home.
    ga4 = partes["ga4"].replace("gtag('config', 'G-", "gtag('set', { content_group: 'eventos' });\n    gtag('config', 'G-", 1)
    assert "content_group: 'eventos'" in ga4, "não achei o gtag('config') da home para inserir o content_group"

    base, versoes, alt_hero = HERO
    sizes_hero = "(max-width: 620px) calc(100vw - 28px), (max-width: 920px) calc(100vw - 40px), 473px"  # .wrap da home
    hero = (f'<picture>\n          <source type="image/avif" srcset="{srcset(base, versoes, "avif")}" sizes="{sizes_hero}">\n'
            f'          <img src="/assets/otim/{base}-960.webp" srcset="{srcset(base, versoes, "webp")}" sizes="{sizes_hero}" '
            f'width="1080" height="943" alt="{esc(alt_hero)}" fetchpriority="high">\n        </picture>')
    preload = (f'  <link rel="preload" as="image" type="image/avif" href="/assets/otim/{base}-960.avif" '
               f'imagesrcset="{srcset(base, versoes, "avif")}" imagesizes="{sizes_hero}" fetchpriority="high">')
    fatos = "\n          ".join([
        f'<li><i class="fa-solid fa-calendar-days" aria-hidden="true"></i><span><b>{DIA_SEMANA.capitalize()}, {DATA_LONGA}</b> de {_ini.year}</span></li>',
        f'<li><i class="fa-regular fa-clock" aria-hidden="true"></i><span><b>Das {HORARIO}</b> chegue quando quiser</span></li>',
        '<li><i class="fa-solid fa-location-dot" aria-hidden="true"></i><span><b>Praça da Cruz Vermelha</b> em frente ao nº 10, Centro</span></li>',
        '<li><i class="fa-solid fa-ticket" aria-hidden="true"></i><span><b>Aberto e gratuito</b> sem inscrição</span></li>',
    ])
    cartoes = "\n        ".join(
        f'<article class="dc-cartao" id="setor-{sid}"><span class="dc-icone"><i class="{icone}" aria-hidden="true"></i></span>'
        f'<p class="dc-setor">{esc(setor)}</p><h3>{esc(titulo)}</h3><p>{esc(texto)}</p></article>'
        for sid, icone, setor, titulo, texto in SETORES)
    galeria = "\n        ".join(
        f'<figure><img src="/assets/otim/{b}-480.webp" srcset="{srcset(b, v, "webp")}" '
        f'sizes="(max-width: 620px) calc(50vw - 20px), (max-width: 920px) calc(50vw - 26px), 263px" width="{v[0][0]}" height="{v[0][1]}" alt="{esc(alt)}" '
        f'loading="lazy" decoding="async"><figcaption>{esc(legenda)}</figcaption></figure>'
        for b, v, alt, legenda in GALERIA)
    url_compartilhada = f"{URL_PAGINA}?utm_source=link&utm_medium=compartilhamento&utm_campaign=dia-das-criancas"

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
  <meta name="robots" content="index, follow, max-image-preview:large">
  <meta property="og:type" content="website">
  <meta property="og:locale" content="pt_BR">
  <meta property="og:site_name" content="Cruz Vermelha Brasileira Rio de Janeiro">
  <meta property="og:title" content="{esc(TITULO)}">
  <meta property="og:description" content="{esc(DESCRICAO)}">
  <meta property="og:url" content="{URL_PAGINA}">
  <meta property="og:image" content="{IMAGEM_OG}">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="{esc(alt_hero)}">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="{esc(TITULO)}">
  <meta name="twitter:description" content="{esc(DESCRICAO)}">
  <meta name="twitter:image" content="{IMAGEM_OG}">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"></noscript>
{preload}
  <!-- Estilos copiados da home (site/index.html): mesmo padrão visual da filial. -->
{partes["estilo"]}
{CSS}
{ga4}
{partes["pixel"]}
{json_ld()}
</head>
<body>
{header}

  <main id="dia-das-criancas">
    <section class="dc-topo" aria-labelledby="dc-titulo">
      <div class="wrap dc-topo-grade">
        <p class="eyebrow">Ação na praça · Dia das Crianças</p>
        <h1 id="dc-titulo">{esc(EVENTO["nome"])}</h1>
        <figure class="dc-topo-foto">
        {hero}
          <p class="dc-selo" aria-hidden="true"><b>{DATA_CURTA}</b><span>{HORARIO}</span></p>
        </figure>
        <p class="lead">{ARTIGO.capitalize()} {DIA_SEMANA} de brincadeiras, cuidado e aprendizado em frente ao Palácio da Cruz Vermelha, no Centro do Rio, com voluntários de vários setores da Cruz Vermelha Brasileira Rio de Janeiro. Aberto a todas as famílias, de graça.</p>
        <ul class="dc-fatos">
          {fatos}
        </ul>
        <div class="dc-acoes">
          <a class="btn btn-red" href="{esc(url_agenda_google())}" target="_blank" rel="noopener" data-dc="agenda-google"><i class="fa-solid fa-calendar-plus" aria-hidden="true"></i>Salvar na agenda</a>
          <a class="btn btn-outline" href="{esc(URL_MAPA)}" target="_blank" rel="noopener" data-dc="mapa"><i class="fa-solid fa-location-dot" aria-hidden="true"></i>Como chegar</a>
        </div>
        <p class="dc-ics">No iPhone ou no Outlook: <a href="dia-das-criancas.ics" download data-dc="agenda-ics">baixe o evento para a agenda (.ics)</a>.</p>
      </div>
    </section>

    <section class="dc-setores" aria-labelledby="dc-setores-titulo">
      <div class="wrap">
        <p class="eyebrow">Programação</p>
        <h2 id="dc-setores-titulo">O que vai ter na praça</h2>
        <p class="lead">Três setores da Cruz Vermelha Brasileira Rio de Janeiro passam o dia com as crianças e as famílias.</p>
        <div class="dc-cartoes">
        {cartoes}
        </div>
        <p class="dc-nota">A programação de cada setor pode mudar ao longo do dia.</p>
      </div>
    </section>

    <section class="dc-fotos" aria-labelledby="dc-fotos-titulo">
      <div class="wrap">
        <p class="eyebrow">Como são as nossas ações</p>
        <h2 id="dc-fotos-titulo">A Cruz Vermelha RJ com as crianças</h2>
        <p class="lead">Fotos de ações anteriores dos nossos voluntários com crianças, publicadas com a autorização dos responsáveis.</p>
        <div class="dc-galeria">
        {galeria}
        </div>
      </div>
    </section>

    <section class="dc-servico" aria-labelledby="dc-servico-titulo">
      <div class="wrap dc-servico-grade">
        <div>
          <p class="eyebrow">Anote</p>
          <h2 id="dc-servico-titulo">Informações</h2>
          <dl class="dc-dl">
            <dt>Quando</dt><dd>{DIA_SEMANA.capitalize()}, {DATA_LONGA} de {_ini.year}, das {HORARIO}</dd>
            <dt>Onde</dt><dd>Na praça em frente ao Palácio da Cruz Vermelha: {esc(EVENTO["endereco"])}, {esc(EVENTO["bairro"])}, {esc(EVENTO["cidade"])} - {esc(EVENTO["uf"])}, CEP {esc(EVENTO["cep"])} · <a href="{esc(URL_MAPA)}" target="_blank" rel="noopener" data-dc="mapa-informacoes">abrir no mapa</a></dd>
            <dt>Entrada</dt><dd>Gratuita e aberta ao público, sem inscrição</dd>
            <dt>Quem organiza</dt><dd>Cruz Vermelha Brasileira Rio de Janeiro, com os setores de Juventude, Primeiros Socorros e Educação e Saúde</dd>
            <dt>Dúvidas</dt><dd>Fale com a gente pelo chat deste site, no botão "Fale com a gente".</dd>
          </dl>
        </div>
        <aside class="dc-compartilhar" aria-labelledby="dc-compartilhar-titulo">
          <h3 id="dc-compartilhar-titulo">Chame outras famílias</h3>
          <p>Mande o convite para quem tem criança em casa.</p>
          <a class="btn dc-whats" href="{esc(url_whatsapp())}" target="_blank" rel="noopener" data-dc="whatsapp"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i>Compartilhar no WhatsApp</a>
          <button type="button" class="btn btn-outline" data-dc="copiar" data-url="{esc(url_compartilhada)}"><i class="fa-solid fa-link" aria-hidden="true"></i>Copiar o link</button>
          <p class="dc-copiado" role="status" aria-live="polite"></p>
        </aside>
      </div>
    </section>

    <section class="dc-voluntario" aria-label="Voluntariado">
      <div class="wrap">
        <p class="dc-voluntario-titulo">Quer fazer parte das próximas ações?</p>
        <p>Os voluntários da Cruz Vermelha Brasileira Rio de Janeiro estão nas ruas, nas escolas e na sede o ano todo.</p>
        <a class="btn btn-white" href="{URL_VOLUNTARIO}" target="_blank" rel="noopener" data-dc="voluntario">Quero ser voluntário</a>
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
    PASTA.mkdir(parents=True, exist_ok=True)
    SAIDA.write_text(pagina, encoding="utf-8")
    SAIDA_ICS.write_bytes(ics().encode("utf-8"))
    print(f"gravado {SAIDA.relative_to(RAIZ)} ({len(pagina.encode('utf-8'))} bytes) e {SAIDA_ICS.relative_to(RAIZ)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
