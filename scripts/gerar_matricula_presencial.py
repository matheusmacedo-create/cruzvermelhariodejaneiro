#!/usr/bin/env python3
"""Gera site/matricula-cursos-presenciais/index.html a partir de cursos.json e do padrão visual da home.

A página é montada com o MESMO <style>, cabeçalho, rodapé, GA4, Meta Pixel e script de menu
de site/index.html, para ficar indistinguível da home. O conteúdo dos cursos vem de
site/matricula-cursos-presenciais/cursos.json (gerado por scripts/sincronizar_catalogo.py a partir
do catálogo público da escola) e as fotos de img/ (geradas por scripts/gerar_imagens_matricula.py).

Decisões de 18/09 (após a primeira publicação): o topo e o bloco do curso focam em "faça sua
matrícula agora e garanta sua vaga" (desde 19/09 o H1 lidera com o certificado da Cruz Vermelha e
a urgência fica na linha de apoio); a regra "a secretaria confirma horário depois" fica só em
"Como funciona" e no FAQ; a página não mostra telefone nem WhatsApp da secretaria, porque desviavam
da matrícula (desde 19/09 nenhuma página cita WhatsApp: dúvidas vão pelo chat de contato por e-mail,
site/chat/, presente em todas as páginas); botão único por curso, com link discreto para a plataforma
da escola no fim do detalhe.

Uso:  python3 scripts/sincronizar_catalogo.py && python3 scripts/gerar_imagens_matricula.py
      && python3 scripts/gerar_matricula_presencial.py
Depois: publicar site/matricula-cursos-presenciais/ (index.html + img/) com scripts/publicar_hostinger.sh.

O botão "Fazer matrícula agora" leva ao checkout (CHECKOUT_URL) com ?curso=<slug>; o script da
página acrescenta as UTMs/fbclid/gclid da URL atual. Sem JavaScript o link já funciona.
"""
from __future__ import annotations

import html
import json
import re
from pathlib import Path

import chat_widget
import icones

RAIZ = Path(__file__).resolve().parent.parent
HOME = RAIZ / "site" / "index.html"
DADOS = RAIZ / "site" / "matricula-cursos-presenciais" / "cursos.json"
SAIDA = RAIZ / "site" / "matricula-cursos-presenciais" / "index.html"
PASTA_IMG = RAIZ / "site" / "matricula-cursos-presenciais" / "img"

ORIGEM = "https://cruzvermelhariodejaneiro.org"
URL_PAGINA = f"{ORIGEM}/matricula-cursos-presenciais/"
ESCOLA = "https://escola.cursoscruzvermelha.org"
CHECKOUT_URL = "/matricula-cursos-presenciais/checkout/"

TITULO = "Matrícula em cursos presenciais no RJ | Cruz Vermelha"
DESCRICAO = ("Matricule-se nos cursos presenciais da Cruz Vermelha no Rio: primeiros socorros, bombeiro "
             "civil, cuidador de idosos e mais. Inscrição de R$ 99 garante a vaga.")
IMAGEM_OG = f"{ORIGEM}/assets/otim/og-matricula.jpg"
IMAGEM_OG_TAMANHO = (1200, 630)
ENDERECO = {"@type": "PostalAddress", "streetAddress": "Praça da Cruz Vermelha, 10", "addressLocality": "Rio de Janeiro",
            "addressRegion": "RJ", "postalCode": "20230-130", "addressCountry": "BR"}
LOCAL = {"@type": "Place", "name": "Cruz Vermelha Brasileira – Filial do Estado do Rio de Janeiro", "address": ENDERECO}

TEXTO_ESTORNO = ("A inscrição reserva sua vaga. Se não houver horário compatível ou você desistir antes da "
                 "confirmação da aula, o valor é estornado. O prazo para aparecer na conta depende de PIX ou cartão.")

FAQ_PAGINA = [
    ("O que é a inscrição de R$ 99?",
     "É a taxa que reserva sua vaga e abre a matrícula na Escola de Educação e Saúde CVB-RJ. O valor do curso é pago "
     "depois, na plataforma da escola, no valor à vista informado em cada curso."),
    ("Quais são as formas de pagamento?",
     "A inscrição de R$ 99 é paga à vista, por PIX ou cartão. O valor do curso é pago depois, na plataforma da escola, "
     "no valor à vista informado em cada curso."),
    ("Preciso criar conta ou escolher turma agora?",
     "Não. Você escolhe o curso e paga a inscrição. A secretaria entra em contato por e-mail em até 2 dias úteis "
     "para confirmar turma e horário."),
    ("E se não houver horário compatível?", TEXTO_ESTORNO),
    ("Os cursos são presenciais? Onde acontecem?",
     "Sim. Todos acontecem na sede da Cruz Vermelha Brasileira, na Praça da Cruz Vermelha, 10, Centro do Rio de "
     "Janeiro, com certificado emitido pela Cruz Vermelha Brasileira."),
    ("Posso ver as turmas abertas antes de pagar?",
     "Sim. As turmas, datas e valores completos estão na plataforma da escola, que continua disponível para quem "
     "prefere o caminho completo de inscrição."),
    ("Como tiro dúvidas antes de me matricular?",
     "Pelo chat no canto da página: você deixa a mensagem e a equipe responde por e-mail em até 2 dias úteis. "
     "Se preferir, escreva para contato@cruzvermelhariodejaneiro.org."),
]


def esc(s: str) -> str:
    return html.escape(s, quote=True)


def brl(centavos: int | None) -> str:
    if centavos is None:
        return "consulte a escola"
    reais = centavos / 100
    return "R$ " + f"{reais:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")


def horas_iso(carga: str) -> str | None:
    m = re.search(r"(\d+)", carga or "")
    return f"PT{m.group(1)}H" if m else None


def bloco(texto: str, inicio: str, fim: str, incluir_fim: bool = True) -> str:
    a = texto.index(inicio)
    b = texto.index(fim, a) + (len(fim) if incluir_fim else 0)
    return texto[a:b]


MARCA_INI = "<!-- matricula:cursos"
MARCA_FIM = "<!-- /matricula:cursos -->"


def atualizar_seletor_home(home: str, dados: dict, cursos: dict) -> str:
    """Reescreve as pílulas (radios) do bloco "Já escolheu seu curso?" da home entre os marcadores."""
    a = home.index(MARCA_INI)
    a = home.index("-->", a) + len("-->")
    b = home.index(MARCA_FIM)
    grupos = []
    for g in dados["grupos"]:
        itens = "".join(
            f'\n                <input type="radio" name="curso" value="{s}" id="mc-{s}">'
            f'\n                <label class="matricula-pilula" for="mc-{s}">{esc(cursos[s]["nome"])} <small>{esc(cursos[s]["carga_horaria"])}</small></label>'
            for s in g["cursos"] if s in cursos
        )
        grupos.append(f'\n              <div class="matricula-grupo"><span class="matricula-grupo-titulo">{esc(g["titulo"])}</span>{itens}\n              </div>')
    return home[:a] + "".join(grupos) + "\n              " + home[b:]


def partes_da_home(home: str) -> dict:
    """Cabeçalho, rodapé, CSS, GA4, Pixel e script do menu da home, já ajustados para /matricula-cursos-presenciais/."""
    # --- pedaços da home -------------------------------------------------------------
    estilo = bloco(home, "  <style>", "  </style>")                       # primeiro <style>: todo o CSS da home
    header = bloco(home, '  <header class="main-header">', "  </header>")
    footer = bloco(home, "  <footer>", "  </footer>")
    # Só o script do menu sanfona; os outros scripts da home (seletor de curso, contato) são da home.
    ini = home.index("  <script>\n    document.querySelector('.nav-toggle')")
    menu_js = home[ini:home.index("</script>", ini) + len("</script>")]
    ga4 = bloco(home, "  <!-- Google tag (gtag.js) -->", "  </script>")
    pixel = bloco(home, "  <!-- Meta Pixel Code -->", "  <!-- End Meta Pixel Code -->") if "<!-- Meta Pixel Code -->" in home else ""
    if not pixel:
        pixel = home[home.index("  <script>\n!function(f,b,e,v,n,t,s)"):home.index("</noscript>") + len("</noscript>")]

    # Caminhos relativos da home viram absolutos (a página fica em /matricula-cursos-presenciais/).
    def absolutizar(trecho: str) -> str:
        trecho = trecho.replace('src="assets/', 'src="/assets/')
        trecho = re.sub(r'href="#([a-z]+)"', r'href="/#\1"', trecho)
        trecho = trecho.replace('href="equipe.html"', 'href="/equipe.html"')
        trecho = trecho.replace('href="cursos.html"', 'href="/cursos.html"')
        trecho = trecho.replace('href="/#inicio"', 'href="/"')
        return trecho

    header = absolutizar(header)
    footer = absolutizar(footer)
    # Sem telefone da secretaria nesta página: o caminho do lead é o botão de matrícula.
    footer_sem_telefone = re.sub(r'\s*<p><i class="fa-solid fa-phone"[^>]*>(?:<svg.*?</svg>)*</i>[^<]*</p>', "", footer, flags=re.S)
    assert footer_sem_telefone != footer, "linha do telefone não encontrada no rodapé da home"
    footer = footer_sem_telefone
    padrao_menu = re.compile(r'<a href="/matricula-cursos-presenciais/"([^>]*)>Matrícula cursos presenciais</a>')
    if not padrao_menu.search(header):
        raise SystemExit("o menu da home ainda não tem o link Matrícula cursos presenciais; rode as edições do menu antes")
    header = padrao_menu.sub(lambda m: f'<a href="/matricula-cursos-presenciais/"{m.group(1)} aria-current="page">Matrícula cursos presenciais</a>', header, count=1)
    return {"estilo": estilo, "header": header, "footer": footer, "menu_js": menu_js, "ga4": ga4, "pixel": pixel}


def main() -> int:
    home = HOME.read_text(encoding="utf-8")
    dados = json.loads(DADOS.read_text(encoding="utf-8"))
    cursos = {c["slug"]: c for c in dados["cursos"]}
    inscricao = dados["inscricao_centavos"]

    # Seletor de curso da home: sempre com o mesmo catálogo desta página.
    home_nova = atualizar_seletor_home(home, dados, cursos)
    if home_nova != home:
        HOME.write_text(home_nova, encoding="utf-8")
        print(f"atualizado {HOME.relative_to(RAIZ)} (seletor de cursos)")
        home = home_nova

    partes = partes_da_home(home)
    estilo, header, footer, menu_js, ga4, pixel = (partes[k] for k in ("estilo", "header", "footer", "menu_js", "ga4", "pixel"))

    # --- catálogo --------------------------------------------------------------------
    def link_lista(slug: str) -> str:
        c = cursos[slug]
        return (f'<a href="?curso={slug}" data-curso="{slug}">{esc(c["nome"])}'
                f'<small>{esc(c["carga_horaria"])} · {esc(c["escolaridade"])}</small></a>')

    lista = "".join(
        f'<div class="mr-grupo"><h3>{esc(g["titulo"])}</h3>{"".join(link_lista(s) for s in g["cursos"] if s in cursos)}</div>'
        for g in dados["grupos"]
    )

    def detalhe(slug: str, primeiro: bool) -> str:
        c = cursos[slug]
        img = c["imagem"]
        sobre = "".join(f"<p>{esc(p)}</p>" for p in c["sobre"])
        obs = "".join(f'<p class="mr-nota"><i class="fa-solid fa-circle-info"></i> {esc(o)}</p>' for o in c["observacoes"])
        faq = "".join(
            f"<details><summary>{esc(f['pergunta'])}</summary><p>{esc(f['resposta'])}</p></details>" for f in c["faq"]
        )
        faq_html = f'<div class="mr-faq"><h3>Dúvidas frequentes sobre {esc(c["nome"])}</h3>{faq}</div>' if faq else ""
        loading = "eager" if primeiro else "lazy"
        prioridade = ' fetchpriority="high"' if primeiro else ""  # a primeira foto é o maior elemento visível no celular
        foto = ""
        if (PASTA_IMG / f"{img}-960.webp").exists():
            foto = (f'<img class="mr-foto" src="img/{img}-960.webp" srcset="img/{img}-480.webp 480w, img/{img}-960.webp 960w" '
                    f'sizes="(max-width: 920px) 100vw, 760px" alt="{esc(c["nome"])} na Cruz Vermelha Brasileira do Rio de Janeiro" '
                    f'loading="{loading}"{prioridade} width="960" height="720">')
        return f'''
        <article class="mr-detalhe" id="curso-{slug}" data-curso="{slug}" data-nome="{esc(c["nome"])}">
          {foto}
          <div class="mr-corpo">
            <p class="eyebrow">Curso presencial</p>
            <h2>{esc(c["nome"])}</h2>
            <p class="lead">{esc(c["descricao"])}</p>
            <div class="mr-chips">
              <span class="mr-chip"><i class="fa-regular fa-clock"></i> {esc(c["carga_horaria"])}</span>
              <span class="mr-chip"><i class="fa-solid fa-graduation-cap"></i> {esc(c["escolaridade"])}</span>
              <span class="mr-chip"><i class="fa-solid fa-location-dot"></i> Presencial · Centro do Rio</span>
            </div>
            <div class="mr-preco">
              <div><span>Inscrição agora</span><b>{brl(inscricao)}</b><span>garante sua vaga neste curso</span></div>
              <div><span>Valor do curso</span><b class="mr-preco-curso">{brl(c["valor_curso_centavos"])}</b><span>à vista, pago depois, na plataforma da escola</span></div>
            </div>
            <div class="cta-row">
              <a class="btn btn-red mr-cta" href="{CHECKOUT_URL}?curso={slug}" data-curso="{slug}" data-nome="{esc(c["nome"])}">Fazer matrícula agora</a>
            </div>
            <p class="mr-regra-curta">Sem criar conta e sem burocracia. Pagamento por PIX ou cartão.</p>
            <h3>Sobre o curso</h3>
            {sobre}
            {obs}
            {faq_html}
            <p class="mr-link-escola">Prefere comparar turmas e datas antes? <a href="{esc(c["url_escola"])}" target="_blank" rel="noopener">Veja este curso na plataforma da escola</a>.</p>
          </div>
        </article>'''

    ordem = [s for g in dados["grupos"] for s in g["cursos"] if s in cursos]
    detalhes = "".join(detalhe(s, i == 0) for i, s in enumerate(ordem))

    # Chat de contato: a lista de cursos do chat.js segue este catálogo; as tags levam o hash do arquivo.
    if chat_widget.atualizar_cursos([{"slug": s, "nome": cursos[s]["nome"]} for s in ordem]):
        print("atualizado site/chat/chat.js (lista de cursos)")
    chat_tags = chat_widget.tags()

    faq_pagina = "".join(f"<details><summary>{esc(p)}</summary><p>{esc(r)}</p></details>" for p, r in FAQ_PAGINA)

    # --- dados estruturados ------------------------------------------------------------
    provedor = {"@type": "EducationalOrganization", "@id": f"{ESCOLA}/#escola",
                "name": "Escola de Educação e Saúde CVB-RJ", "url": f"{ESCOLA}/",
                "parentOrganization": {"@id": f"{ORIGEM}/#organizacao"}}
    itens = []
    for i, s in enumerate(ordem, 1):
        c = cursos[s]
        curso = {"@type": "Course", "name": c["nome"], "description": c["descricao"] or (c["sobre"][0] if c["sobre"] else ""),
                 "url": f"{URL_PAGINA}?curso={s}", "provider": provedor, "courseMode": "Onsite",
                 "educationalCredentialAwarded": "Certificado da Cruz Vermelha Brasileira"}
        if (PASTA_IMG / f"{c['imagem']}-960.webp").exists():
            curso["image"] = f"{URL_PAGINA}img/{c['imagem']}-960.webp"
        ofertas = [{"@type": "Offer", "category": "Paid", "name": "Inscrição", "price": f"{inscricao / 100:.2f}",
                    "priceCurrency": "BRL", "url": f"{URL_PAGINA}?curso={s}", "availability": "https://schema.org/InStock"}]
        if c["valor_curso_centavos"]:
            ofertas.append({"@type": "Offer", "category": "Paid", "name": "Valor do curso (pago depois, na plataforma da escola)",
                            "price": f"{c['valor_curso_centavos'] / 100:.2f}", "priceCurrency": "BRL"})
        curso["offers"] = ofertas
        instancia = {"@type": "CourseInstance", "courseMode": "Onsite", "location": LOCAL}
        if horas_iso(c["carga_horaria"]):
            curso["timeRequired"] = horas_iso(c["carga_horaria"])
            instancia["courseWorkload"] = horas_iso(c["carga_horaria"])
        curso["hasCourseInstance"] = [instancia]
        itens.append({"@type": "ListItem", "position": i, "item": curso})
    ld = [
        {"@context": "https://schema.org", "@type": "BreadcrumbList", "itemListElement": [
            {"@type": "ListItem", "position": 1, "name": "Início", "item": f"{ORIGEM}/"},
            {"@type": "ListItem", "position": 2, "name": "Matrícula cursos presenciais", "item": URL_PAGINA}]},
        {"@context": "https://schema.org", "@type": "ItemList", "name": "Matrícula em cursos presenciais da Cruz Vermelha RJ",
         "url": URL_PAGINA, "itemListElement": itens},
        {"@context": "https://schema.org", "@type": "FAQPage", "mainEntity": [
            {"@type": "Question", "name": p, "acceptedAnswer": {"@type": "Answer", "text": r}} for p, r in FAQ_PAGINA]},
    ]
    ld_html = "".join(f'\n  <script type="application/ld+json">\n{json.dumps(d, ensure_ascii=False, indent=2)}\n  </script>' for d in ld)
    for d in ld:
        assert "</" not in json.dumps(d, ensure_ascii=False)

    css = """
  <style>
    .mr-hero { background: var(--soft); border-bottom: 1px solid var(--line); padding: 56px 0 40px; }
    .mr-hero h1 { color: var(--black); font-size: clamp(2rem, 4.6vw, 3.3rem); line-height: 1.04; letter-spacing: -.035em; margin: 10px 0 16px; }
    .mr-hero h1 { margin-bottom: 6px; }
    .mr-hero .mr-h1-sub { font-size: clamp(1rem, 2.3vw, 1.35rem); font-weight: 700; color: var(--muted); letter-spacing: -.01em; line-height: 1.3; margin: 0 0 14px; max-width: 60ch; }
    .mr-hero .lead { max-width: 72ch; }
    .mr-chips { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
    .mr-chip { display: inline-flex; align-items: center; gap: 8px; background: #fff; border: 1px solid var(--line); border-radius: 999px; padding: 8px 14px; font-size: .9rem; color: var(--text); }
    .mr-chip i { color: var(--red); }
    .mr-catalogo { padding: 48px 0 64px; }
    .mr-grid { display: grid; grid-template-columns: 320px minmax(0, 1fr); gap: 32px; align-items: start; }
    .mr-lista { position: sticky; top: 96px; display: grid; gap: 20px; }
    .mr-grupo h3 { font-size: .78rem; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); margin: 0 0 8px; }
    .mr-lista a { display: block; padding: 12px 14px; border: 1px solid var(--line); border-radius: 12px; background: #fff; color: var(--text); font-weight: 700; margin-bottom: 8px; transition: border-color .2s, box-shadow .2s; }
    .mr-lista a small { display: block; color: var(--muted); font-weight: 500; font-size: .85rem; margin-top: 2px; }
    .mr-lista a:hover { border-color: var(--red); }
    .mr-lista a.ativo { border-color: var(--red); box-shadow: inset 4px 0 0 var(--red); }
    .mr-detalhe { background: #fff; border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; margin-bottom: 28px; }
    .js .mr-detalhe { margin-bottom: 0; }
    .js .mr-detalhe:not(.ativo) { display: none; }
    .mr-detalhe .mr-foto { width: 100%; height: auto; aspect-ratio: 4 / 3; object-fit: cover; display: block; background: var(--soft); }
    .mr-corpo { padding: 32px; }
    .mr-corpo h2 { color: var(--black); font-size: clamp(1.6rem, 3vw, 2.2rem); letter-spacing: -.025em; margin: 0 0 10px; }
    .mr-corpo h3 { color: var(--black); font-size: 1.1rem; margin: 26px 0 10px; }
    .mr-corpo p { color: var(--text); }
    .mr-preco { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin: 24px 0 18px; padding: 20px; background: var(--soft); border: 1px solid var(--line); border-radius: 12px; }
    .mr-preco span { display: block; color: var(--muted); font-size: .85rem; }
    .mr-preco b { display: block; font-size: 1.7rem; color: var(--red); line-height: 1.1; margin: 4px 0; }
    .mr-preco b.mr-preco-curso { color: var(--black); }
    .mr-regra-curta { color: var(--muted); font-size: .92rem; margin: 14px 0 0; }
    .mr-link-escola { color: var(--muted); font-size: .92rem; margin: 26px 0 0; padding-top: 18px; border-top: 1px solid var(--line); }
    .mr-link-escola a { color: var(--red); font-weight: 700; text-decoration: underline; }
    .mr-nota { border-left: 4px solid var(--red); background: var(--soft); padding: 12px 16px; border-radius: 0 12px 12px 0; }
    .mr-nota i { color: var(--red); margin-right: 6px; }
    .mr-faq details, .mr-faq-pagina details { border-top: 1px solid var(--line); padding: 12px 0; }
    .mr-faq summary, .mr-faq-pagina summary { cursor: pointer; font-weight: 700; color: var(--black); }
    .mr-faq details p, .mr-faq-pagina details p { margin: 10px 0 0; }
    .mr-como { background: var(--soft); border-top: 1px solid var(--line); padding: 56px 0; }
    .mr-passos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 28px; }
    .mr-passo { background: #fff; border: 1px solid var(--line); border-radius: var(--radius); padding: 24px; }
    .mr-passo b { display: inline-flex; width: 36px; height: 36px; border-radius: 50%; background: var(--red); color: #fff; align-items: center; justify-content: center; font-weight: 900; margin-bottom: 12px; }
    .mr-passo h3 { color: var(--black); font-size: 1.1rem; margin: 0 0 8px; }
    .mr-passo p { color: var(--text); margin: 0; }
    .mr-regra { border-left: 4px solid var(--red); background: #fff; padding: 16px 20px; border-radius: 0 12px 12px 0; margin-top: 26px; color: var(--text); }
    .mr-faq-pagina { padding: 56px 0 64px; }
    .mr-faq-pagina .wrap { max-width: 820px; }
    @media (max-width: 920px) {
      .mr-grid { grid-template-columns: 1fr; }
      .mr-lista { position: static; }
      .mr-passos { grid-template-columns: 1fr; }
      .mr-preco { grid-template-columns: 1fr; }
      .mr-corpo { padding: 22px; }
      /* O CSS da home esconde qualquer <nav> abaixo de 920px (regra do menu antigo) e o menu sanfona
         abria sem os links. Nesta página os links voltam a aparecer com o menu aberto. */
      .main-header .header-collapse .nav-links { display: flex !important; }
    }
  </style>"""

    js = f"""
  <script>
    (function () {{
      var CHECKOUT_URL = {json.dumps(CHECKOUT_URL)};
      var lista = Array.prototype.slice.call(document.querySelectorAll('.mr-lista a[data-curso]'));
      var detalhes = Array.prototype.slice.call(document.querySelectorAll('.mr-detalhe'));
      if (!lista.length || !detalhes.length) return;

      function slugDaUrl() {{
        var q = new URLSearchParams(location.search).get('curso');
        if (q) return q;
        var h = location.hash.replace('#curso-', '');
        return h || null;
      }}
      // Meta: ViewContent ao abrir um curso (InitiateCheckout dispara na página do checkout).
      // GA4: view_item ao abrir um curso e select_item no botão, com o item para os relatórios de funil.
      function rastrear(nome, dados) {{
        var item = {{ item_id: dados.content_ids[0], item_name: dados.content_name, item_category: 'Cursos presenciais', price: {inscricao / 100:.2f}, quantity: 1 }};
        try {{ if (window.fbq && nome === 'ViewContent') fbq('track', nome, dados); }} catch (e) {{}}
        try {{ if (window.gtag) gtag('event', nome === 'ViewContent' ? 'view_item' : 'select_item', {{ currency: 'BRL', value: {inscricao / 100:.2f}, item_list_name: 'Matrícula cursos presenciais', items: [item] }}); }} catch (e) {{}}
      }}
      function ativar(slug, atualizarUrl) {{
        var alvo = document.getElementById('curso-' + slug);
        if (!alvo) return false;
        detalhes.forEach(function (d) {{ d.classList.toggle('ativo', d === alvo); }});
        lista.forEach(function (a) {{
          var on = a.getAttribute('data-curso') === slug;
          a.classList.toggle('ativo', on);
          if (on) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
        }});
        // A URL não muda ao trocar de curso: GA4 e Pixel contam cada mudança de histórico como
        // nova visualização de página. Chegar com ?curso= ou #curso- continua funcionando.
        return true;
      }}
      var inicial = slugDaUrl();
      if (!inicial || !ativar(inicial, false)) ativar(lista[0].getAttribute('data-curso'), false);

      lista.forEach(function (a) {{
        a.addEventListener('click', function (e) {{
          e.preventDefault();
          var slug = a.getAttribute('data-curso');
          ativar(slug, true);
          var alvo = document.getElementById('curso-' + slug);
          if (window.innerWidth < 920 && alvo) alvo.scrollIntoView({{ behavior: 'smooth', block: 'start' }});
          rastrear('ViewContent', {{ content_name: alvo.getAttribute('data-nome'), content_ids: [slug], content_category: 'matricula-cursos-presenciais' }});
        }});
      }});

      // Repassa UTMs/fbclid/gclid da URL atual para o destino do botão (o href já aponta para o checkout).
      var extras = new URLSearchParams();
      new URLSearchParams(location.search).forEach(function (v, k) {{ if (/^(utm_|fbclid$|gclid$)/.test(k)) extras.set(k, v); }});

      document.querySelectorAll('.mr-cta').forEach(function (b) {{
        var slug = b.getAttribute('data-curso');
        if (CHECKOUT_URL) {{
          var u = new URL(CHECKOUT_URL, location.origin); u.searchParams.set('curso', slug);
          extras.forEach(function (v, k) {{ u.searchParams.set(k, v); }});
          b.href = u.toString();
        }}
        b.addEventListener('click', function () {{
          rastrear('SelecionouCurso', {{ content_name: b.getAttribute('data-nome'), content_ids: [slug], content_category: 'matricula-cursos-presenciais' }});
        }});
      }});
    }})();
  </script>"""

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
  <meta property="og:image:width" content="{IMAGEM_OG_TAMANHO[0]}">
  <meta property="og:image:height" content="{IMAGEM_OG_TAMANHO[1]}">
  <meta property="og:image:alt" content="Cursos presenciais da Cruz Vermelha Brasileira no Rio de Janeiro">
  <meta name="twitter:card" content="summary_large_image">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"></noscript>
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
  <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
  <!-- Estilos copiados da home (site/index.html) para a página ficar idêntica ao padrão da filial. -->
{estilo}
{css}
{ga4}
{pixel}
{ld_html}
</head>
<body>
  <script>document.documentElement.classList.add('js');</script>
{header}

  <main id="matricula-cursos-presenciais">
    <section class="mr-hero" aria-labelledby="mr-titulo">
      <div class="wrap">
        <p class="eyebrow">Escola de Educação e Saúde CVB-RJ</p>
        <h1 id="mr-titulo">O certificado da Cruz Vermelha no seu currículo</h1>
        <p class="mr-h1-sub">Cursos presenciais no Centro do Rio. Garanta sua vaga hoje: a inscrição de {brl(inscricao)} reserva seu lugar.</p>
        <p class="lead">Primeiros socorros, bombeiro civil, cuidador de idosos, punção venosa e mais. Escolha o curso, pague por PIX ou cartão e a vaga é sua.</p>
        <div class="mr-chips">
          <span class="mr-chip"><i class="fa-solid fa-list-check"></i> {len(ordem)} cursos presenciais</span>
          <span class="mr-chip"><i class="fa-solid fa-location-dot"></i> Praça da Cruz Vermelha, 10 · Centro</span>
          <span class="mr-chip"><i class="fa-solid fa-certificate"></i> Certificado da Cruz Vermelha Brasileira</span>
        </div>
      </div>
    </section>

    <section class="mr-catalogo" id="cursos" aria-labelledby="mr-catalogo-titulo">
      <div class="wrap">
        <h2 id="mr-catalogo-titulo" class="sr-only" style="position:absolute;left:-9999px">Cursos disponíveis</h2>
        <div class="mr-grid">
          <!-- div, e não <nav>: o CSS da home esconde qualquer <nav> abaixo de 920px (menu antigo). -->
          <div class="mr-lista" role="navigation" aria-label="Cursos">
            {lista}
          </div>
          <div class="mr-detalhes">
            {detalhes}
          </div>
        </div>
      </div>
    </section>

    <section class="mr-como" aria-labelledby="mr-como-titulo">
      <div class="wrap">
        <p class="eyebrow">Como funciona</p>
        <h2 id="mr-como-titulo">Três passos, sem burocracia</h2>
        <div class="mr-passos">
          <div class="mr-passo"><b>1</b><h3>Escolha o curso</h3><p>Veja carga horária, escolaridade mínima e valor. Todos são presenciais, na sede da Praça da Cruz Vermelha.</p></div>
          <div class="mr-passo"><b>2</b><h3>Garanta a vaga com a inscrição de {brl(inscricao)}</h3><p>Por PIX ou cartão, à vista. Sem criar conta e sem escolher turma nesta etapa.</p></div>
          <div class="mr-passo"><b>3</b><h3>A secretaria confirma turma e horário</h3><p>Você recebe o contato por e-mail em até 2 dias úteis. O valor do curso é pago depois, na plataforma da escola.</p></div>
        </div>
        <p class="mr-regra">{esc(TEXTO_ESTORNO)}</p>
      </div>
    </section>

    <section class="mr-faq-pagina" aria-labelledby="mr-faq-titulo">
      <div class="wrap">
        <p class="eyebrow">Dúvidas frequentes</p>
        <h2 id="mr-faq-titulo">Perguntas sobre a matrícula</h2>
        {faq_pagina}
        <div class="cta-row" style="margin-top:28px">
          <a class="btn btn-red" href="#cursos">Fazer matrícula agora</a>
          <a class="btn btn-outline" href="{ESCOLA}/cursos" target="_blank" rel="noopener">Ver turmas na plataforma da escola</a>
        </div>
      </div>
    </section>
  </main>

{footer}

{menu_js}
{js}
{chat_tags}
</body>
</html>
"""
    pagina = icones.converter(pagina)  # ícones em SVG inline, sem Font Awesome
    SAIDA.write_text(pagina, encoding="utf-8")
    print(f"gravado {SAIDA.relative_to(RAIZ)} ({len(pagina.encode('utf-8'))} bytes, {len(ordem)} cursos)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
