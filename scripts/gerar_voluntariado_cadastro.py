#!/usr/bin/env python3
"""Gera site/voluntariado/cadastro/index.html: o formulário público de cadastro de voluntários.

Substitui o formulário externo (Spotform) que o site usava até outubro de 2026. A ficha vai para a API própria
(api/voluntarios.php): fica no banco da filial, aparece no portal da secretaria, vai por e-mail à equipe do
voluntariado e a pessoa recebe a confirmação com o protocolo. O caminho do voluntário é: cadastro → ficha de
entrevista → CBFI (Curso Básico de Formação Institucional, quatro vezes por ano) → termo de adesão.

Progressivo: sem JavaScript o <form> faz POST comum para a API, que redireciona de volta para esta página com
?protocolo= ou ?erro=<campo>; com JavaScript, cadastro.js (nesta mesma pasta) valida no navegador, envia JSON e
mostra a tela de sucesso. O cadastro.js entra com um hash do conteúdo na query string (como o chat), para o cache
não segurar versão velha.

Regras de texto desta página: sem número nem link de WhatsApp (o contato da equipe é por ele, desde 27/10/2026), sem pedido de doação, sem o domínio antigo; o e-mail de contato só
aparece no rodapé da home (que a página herda). Idade mínima: 18 anos (desde 25/10/2026; antes, 16).
Valores dos campos (escolaridade, frentes, dias, períodos, como_conheceu): os mesmos que a API aceita.

Cabeçalho, rodapé, CSS, GA4, Pixel (com o aviso de cookies) e o script do menu vêm de site/index.html, como
nos outros geradores; o chat de contato entra no fim.

Uso:  python3 scripts/gerar_voluntariado_cadastro.py
"""
from __future__ import annotations

import hashlib
import json

import cbfi
import chat_widget
import icones
from gerar_matricula_presencial import HOME, RAIZ, esc, partes_da_home

PASTA = RAIZ / "site" / "voluntariado" / "cadastro"
SAIDA = PASTA / "index.html"
JS_ARQUIVO = PASTA / "cadastro.js"
ORIGEM = "https://cruzvermelhariodejaneiro.org"
URL_PAGINA = f"{ORIGEM}/voluntariado/cadastro/"
API = "/matricula-cursos-presenciais/api/voluntarios.php"
# A mesma imagem de compartilhamento das páginas institucionais (gerar_institucional.py).
IMAGEM_OG = f"{ORIGEM}/assets/otim/og-home.jpg"

TITULO = "Cadastro de voluntário – Cruz Vermelha RJ"
H1 = "Quero ser voluntário da Cruz Vermelha no Rio"
DESCRICAO = ("Cadastre-se como voluntário da Cruz Vermelha Brasileira Rio de Janeiro: "
             "entrevista, curso básico de formação (gratuito) e termo de adesão. A partir de 18 anos.")

# --- valores dos campos (os mesmos da API) --------------------------------------------------------------------------
ESCOLARIDADES = [
    ("fundamental", "Ensino fundamental"),
    ("medio", "Ensino médio"),
    ("tecnico", "Ensino técnico"),
    ("superior_incompleto", "Superior incompleto ou em curso"),
    ("superior", "Superior completo"),
    ("pos", "Pós-graduação"),
]
FRENTES = [
    ("formacao_primeiros_socorros", "Formação e primeiros socorros", "Apoiar cursos, treinamentos e simulações."),
    ("campanhas_acoes", "Campanhas e ações", "Distribuição de donativos, mutirões e eventos."),
    ("juventude", "Juventude", "Programa de jovens voluntários e atividades com crianças e adolescentes."),
    ("comunicacao", "Comunicação", "Redes sociais, fotos, vídeos e textos."),
    ("gestao_riscos", "Gestão de riscos e desastres", "Prevenção, preparação e resposta a emergências."),
    ("psicologia_servico_social", "Psicologia e serviço social", "Acolhimento e apoio psicossocial."),
    ("esportes", "Esportes e eventos", "Apoio em corridas, jogos e grandes eventos."),
    ("administrativo", "Administrativo", "Secretaria, organização e apoio à gestão."),
    ("outra", "Outra área", "Conte nas habilidades o que você gostaria de fazer."),
]
DIAS = [("seg", "Segunda"), ("ter", "Terça"), ("qua", "Quarta"), ("qui", "Quinta"), ("sex", "Sexta"), ("sab", "Sábado"), ("dom", "Domingo")]
PERIODOS = [("manha", "Manhã"), ("tarde", "Tarde"), ("noite", "Noite")]
COMO_CONHECEU = [
    ("instagram", "Instagram"),
    ("site", "Pesquisando na internet"),
    ("amigos", "Amigos ou família"),
    ("escola", "Escola ou faculdade"),
    ("campanha", "Uma campanha ou ação da Cruz Vermelha"),
    ("imprensa", "Jornal, TV ou rádio"),
    ("outro", "Outro"),
]
UFS = ["AC", "AL", "AP", "AM", "BA", "CE", "DF", "ES", "GO", "MA", "MT", "MS", "MG", "PA", "PB", "PR", "PE", "PI",
       "RJ", "RN", "RS", "RO", "RR", "SC", "SP", "SE", "TO"]
ORIGEM_CHAVES = ["utm_source", "utm_medium", "utm_campaign", "utm_content", "utm_term", "fbclid", "gclid"]

# Novas entradas no voluntariado (decisão do Matheus, 18/10/2026): o mesmo texto de MCP_VOL_PROXIMAS_ENTRADAS (api/lib/voluntarios.php).
# Até 28/11/2026 vale a turma do CBFI aberta a quem se cadastra agora (scripts/cbfi.py); depois, volta sozinho para janeiro.
PROXIMAS_ENTRADAS = cbfi.PROXIMAS_ENTRADAS
CBFI_LINK = f'<a href="{cbfi.URL}">Saiba mais sobre o CBFI</a>'

# (título, HTML do detalhe)
ETAPAS = [
    ("Cadastro", "Este formulário. Leva uns dez minutos."),
    ("Ficha de entrevista", cbfi.alternar(
        f"A equipe do voluntariado entra em contato pelo WhatsApp com os cadastrados, antes do CBFI de {cbfi.DATA_CURTA}.",
        f"A equipe do voluntariado entra em contato pelo WhatsApp em {PROXIMAS_ENTRADAS}, quando abrem as novas entradas.")),
    ("CBFI", cbfi.alternar(
        f"Curso Básico de Formação Institucional: próxima turma no {cbfi.DIA_DA_SEMANA}, {cbfi.DATA_CURTA}, na sede. Gratuito e só para quem entra no voluntariado.",
        "Curso Básico de Formação Institucional: quatro vezes por ano, na sede, gratuito e só para quem entra no voluntariado.")),
    ("Termo de adesão", "Formaliza o vínculo e você começa a atuar."),
]


# --- pedaços de formulário ------------------------------------------------------------------------------------------
def _rotulo(texto: str, obrigatorio: bool) -> str:
    if obrigatorio:
        return f'{esc(texto)} <span class="vc-obrig" aria-hidden="true">*</span>'
    return f'{esc(texto)} <small>(opcional)</small>'


def _erro(nome: str) -> str:
    return f'<p class="vc-erro" id="vc-{nome}-erro" hidden></p>'


def _descricao(nome: str, dica: str) -> tuple[str, str]:
    """(HTML da dica, atributo aria-describedby)."""
    if not dica:
        return "", ""
    return f'<p class="vc-dica" id="vc-{nome}-dica">{esc(dica)}</p>', f' aria-describedby="vc-{nome}-dica"'


def entrada(nome: str, rotulo: str, *, tipo: str = "text", obrigatorio: bool = False, dica: str = "", largo: bool = False,
            atributos: str = "", caixa_id: str = "", oculta: bool = False) -> str:
    dica_html, describedby = _descricao(nome, dica)
    req = ' required aria-required="true"' if obrigatorio else ""
    classes = "vc-campo" + (" vc-campo-largo" if largo else "")
    extra_caixa = (f' id="{caixa_id}"' if caixa_id else "") + (" hidden" if oculta else "")
    return (f'<div class="{classes}" data-campo="{nome}"{extra_caixa}>'
            f'<label for="vc-{nome}">{_rotulo(rotulo, obrigatorio)}</label>'
            f'<input type="{tipo}" id="vc-{nome}" name="{nome}"{req}{describedby}{(" " + atributos) if atributos else ""}>'
            f'{dica_html}{_erro(nome)}</div>')


def area(nome: str, rotulo: str, *, obrigatorio: bool = False, dica: str = "", atributos: str = "", contador: bool = False) -> str:
    dica_html, describedby = _descricao(nome, dica)
    req = ' required aria-required="true"' if obrigatorio else ""
    cont = ""
    if contador:
        cont = (f'<p class="vc-contador" id="vc-{nome}-contador" aria-hidden="true"></p>'
                f'<p class="vc-sr" id="vc-{nome}-contador-sr" aria-live="polite" aria-atomic="true"></p>')
    return (f'<div class="vc-campo vc-campo-largo" data-campo="{nome}">'
            f'<label for="vc-{nome}">{_rotulo(rotulo, obrigatorio)}</label>'
            f'<textarea id="vc-{nome}" name="{nome}" rows="5"{req}{describedby}{(" " + atributos) if atributos else ""}></textarea>'
            f'{cont}{dica_html}{_erro(nome)}</div>')


def selecao(nome: str, rotulo: str, opcoes: list[tuple[str, str]], *, obrigatorio: bool = False, dica: str = "",
            selecionado: str = "", vazio: str = "Selecione", atributos: str = "") -> str:
    dica_html, describedby = _descricao(nome, dica)
    req = ' required aria-required="true"' if obrigatorio else ""
    itens = "" if selecionado else f'<option value="">{esc(vazio)}</option>'
    itens += "".join(f'<option value="{esc(v)}"{" selected" if v == selecionado else ""}>{esc(r)}</option>' for v, r in opcoes)
    return (f'<div class="vc-campo" data-campo="{nome}">'
            f'<label for="vc-{nome}">{_rotulo(rotulo, obrigatorio)}</label>'
            f'<select id="vc-{nome}" name="{nome}"{req}{describedby}{(" " + atributos) if atributos else ""}>{itens}</select>'
            f'{dica_html}{_erro(nome)}</div>')


def grupo(nome: str, legenda: str, opcoes: list[tuple], *, tipo: str = "checkbox", dica: str = "", classe: str = "vc-opcoes") -> str:
    """Grupo de caixas (ou botões de opção) num fieldset próprio, com erro e dica ligados ao fieldset."""
    nome_html = nome if tipo == "radio" else f"{nome}[]"
    dica_html, describedby = _descricao(nome, dica)
    itens = []
    for i, op in enumerate(opcoes):
        valor, rotulo = op[0], op[1]
        descricao = f"<small>{esc(op[2])}</small>" if len(op) > 2 and op[2] else ""
        itens.append(f'<label class="vc-opcao"><input type="{tipo}" name="{nome_html}" value="{esc(valor)}" id="vc-{nome}-{i}">'
                     f'<span><b>{esc(rotulo)}</b>{descricao}</span></label>')
    return (f'<fieldset class="vc-grupo" data-campo="{nome}"{describedby}>'
            f'<legend>{esc(legenda)} <span class="vc-obrig" aria-hidden="true">*</span></legend>'
            f'{dica_html}<div class="{classe}">{"".join(itens)}</div>{_erro(nome)}</fieldset>')


def caixa_unica(nome: str, rotulo_html: str, *, obrigatorio: bool) -> str:
    req = ' required aria-required="true"' if obrigatorio else ""
    marca = ' <span class="vc-obrig" aria-hidden="true">*</span>' if obrigatorio else ""
    return (f'<div class="vc-campo vc-campo-largo vc-consentimento" data-campo="{nome}">'
            f'<label class="vc-opcao" for="vc-{nome}"><input type="checkbox" id="vc-{nome}" name="{nome}" value="1"{req}>'
            f'<span>{rotulo_html}{marca}</span></label>{_erro(nome)}</div>')


def cartao(id_: str, legenda: str, intro: str, conteudo: str) -> str:
    intro_html = f'<p class="vc-intro">{esc(intro)}</p>' if intro else ""
    return (f'      <fieldset class="vc-cartao" id="{id_}">\n        <legend>{esc(legenda)}</legend>\n        {intro_html}\n'
            f'        {conteudo}\n      </fieldset>')


def json_ld() -> str:
    grafo = [
        {"@type": "WebPage", "@id": f"{URL_PAGINA}#pagina", "url": URL_PAGINA, "name": H1, "description": DESCRICAO,
         "inLanguage": "pt-BR", "isPartOf": {"@id": f"{ORIGEM}/#site"}, "publisher": {"@id": f"{ORIGEM}/#organizacao"},
         "breadcrumb": {"@id": f"{URL_PAGINA}#trilha"}, "about": {"@id": f"{ORIGEM}/#organizacao"},
         "primaryImageOfPage": {"@type": "ImageObject", "url": IMAGEM_OG, "width": 1200, "height": 630}},
        {"@type": "BreadcrumbList", "@id": f"{URL_PAGINA}#trilha", "itemListElement": [
            {"@type": "ListItem", "position": 1, "name": "Início", "item": f"{ORIGEM}/"},
            {"@type": "ListItem", "position": 2, "name": "Voluntariado", "item": f"{ORIGEM}/voluntariado/"},
            {"@type": "ListItem", "position": 3, "name": "Cadastro", "item": URL_PAGINA}]},
    ]
    texto = json.dumps({"@context": "https://schema.org", "@graph": grafo}, ensure_ascii=False, indent=1).replace("</", "<\\/")
    return f'  <script type="application/ld+json">\n{texto}\n  </script>'


CSS = """
  <style>
    /* Cinza de apoio com contraste AA no branco (o --muted da home fica em 4,0:1). */
    main.vc-pagina { --muted: #5f6b7a; --vc-borda: #8a94a6; /* 3:1 no branco: contorno de campo (WCAG 1.4.11) */ }
    main.vc-pagina section { padding: 0; }  /* a home dá 86 px a todo <section> */
    .vc-topo { background: var(--soft); border-bottom: 1px solid var(--line); padding: 40px 0 36px; }
    .vc-topo .wrap, .vc-corpo .wrap { max-width: 860px; }
    .vc-trilha { display: block !important; font-size: .82rem; color: var(--muted); margin: 0 0 18px; }  /* a home esconde todo <nav> no celular */
    .vc-trilha ol { list-style: none; display: flex; flex-wrap: wrap; gap: 6px; margin: 0; padding: 0; }
    .vc-trilha li + li::before { content: "›"; margin-right: 6px; color: var(--muted); }
    .vc-trilha a { color: var(--muted); }
    .vc-trilha a:hover { color: var(--red); }
    .vc-topo h1 { color: var(--black); font-size: clamp(2rem, 4.4vw, 3rem); line-height: 1.08; letter-spacing: -.03em; margin: 6px 0 14px; max-width: 22ch; }
    .vc-topo .lead { max-width: 66ch; margin-bottom: 0; }
    .vc-entradas { display: flex; gap: 12px; align-items: flex-start; max-width: 66ch; margin: 18px 0 0; background: #fff7f7; border: 1px solid #f5c2c7; border-left: 4px solid var(--red); border-radius: 12px; padding: 14px 16px; color: var(--text); line-height: 1.55; }
    .vc-entradas i { color: var(--red); font-size: 1.25rem; margin-top: 2px; flex-shrink: 0; }
    .vc-etapas { list-style: none; counter-reset: etapa; display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 26px 0 0; padding: 0; }
    .vc-etapas li { counter-increment: etapa; background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 14px 14px 14px 54px; position: relative; font-size: .92rem; line-height: 1.4; color: var(--text); }
    .vc-etapas li::before { content: counter(etapa); position: absolute; left: 14px; top: 14px; width: 28px; height: 28px; border-radius: 50%; background: var(--red); color: #fff; font-weight: 800; display: flex; align-items: center; justify-content: center; font-size: .9rem; }
    .vc-etapas b { display: block; color: var(--black); margin-bottom: 2px; }
    .vc-corpo { padding: 36px 0 12px; }
    .vc-intro-form { margin: 0 0 22px; color: var(--muted); }
    .vc-noscript { margin: 0 0 22px; padding: 12px 14px; border-radius: 12px; background: #fff; border: 1px solid var(--line); color: var(--text); font-size: .95rem; }
    .vc-form { display: grid; gap: 22px; position: relative; }
    .vc-form[hidden], .vc-sucesso[hidden], .vc-aviso[hidden], .vc-erro[hidden], .vc-campo[hidden] { display: none; }
    .vc-cartao { background: #fff; border: 1px solid var(--line); border-radius: var(--radius); padding: 24px 24px 26px; margin: 0; min-width: 0; }
    /* O legend sai da borda e vira o título do cartão. */
    .vc-cartao > legend { float: left; width: 100%; padding: 0; margin: 0 0 6px; font-size: 1.25rem; font-weight: 800; color: var(--black); letter-spacing: -.02em; }
    .vc-cartao > legend + * { clear: both; }
    .vc-intro { clear: both; margin: 0 0 18px; color: var(--muted); font-size: .95rem; }
    .vc-grade { clear: both; display: grid; grid-template-columns: 1fr 1fr; gap: 16px 18px; }
    .vc-campo { display: grid; gap: 6px; align-content: start; min-width: 0; }
    .vc-campo-largo { grid-column: 1 / -1; }
    .vc-campo > label, .vc-grupo > legend { font-weight: 700; font-size: .95rem; color: var(--black); }
    .vc-campo > label small, .vc-grupo > legend small { font-weight: 500; color: var(--muted); }
    .vc-obrig { color: var(--red); font-weight: 800; }
    .vc-campo input:not([type="checkbox"]):not([type="radio"]), .vc-campo select, .vc-campo textarea {
      width: 100%; box-sizing: border-box; font: inherit; font-size: 1rem; color: var(--text); background: #fff;
      border: 1px solid var(--vc-borda); border-radius: 12px; padding: 0 14px; height: 46px; }
    .vc-campo select { appearance: none; -webkit-appearance: none; padding-right: 40px;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 16 16'%3E%3Cpath fill='none' stroke='%231a202c' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round' d='M4 6l4 4 4-4'/%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right 14px center; }
    .vc-campo textarea { height: auto; min-height: 128px; padding: 12px 14px; resize: vertical; line-height: 1.5; }
    .vc-campo input:focus, .vc-campo select:focus, .vc-campo textarea:focus { outline: 3px solid rgba(204, 0, 0, .22); outline-offset: 1px; border-color: var(--red); }
    .vc-campo [aria-invalid="true"], .vc-grupo[aria-invalid="true"] .vc-opcao { border-color: var(--red); background: #fff7f7; }
    .vc-dica { margin: 0; font-size: .85rem; color: var(--muted); line-height: 1.4; }
    .vc-contador { margin: 0; font-size: .85rem; color: var(--muted); text-align: right; }
    .vc-erro { margin: 0; font-size: .88rem; color: #a30000; font-weight: 600; line-height: 1.4; }
    .vc-grupo { border: 0; padding: 0; margin: 0; min-width: 0; display: grid; gap: 8px; grid-column: 1 / -1; }
    .vc-grupo > legend { padding: 0; margin-bottom: 2px; }
    .vc-opcoes { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .vc-opcoes-linha { display: flex; flex-wrap: wrap; gap: 10px; }
    .vc-opcao { display: flex; gap: 12px; align-items: flex-start; min-height: 46px; padding: 11px 14px; border: 1px solid var(--line); border-radius: 12px; background: #fff; cursor: pointer; line-height: 1.35; color: var(--text); font-size: .95rem; font-weight: 500; }
    .vc-opcoes-linha .vc-opcao { align-items: center; padding: 0 16px 0 12px; }
    .vc-opcao input { width: 22px; height: 22px; margin: 0; flex-shrink: 0; accent-color: var(--red); cursor: pointer; }
    .vc-opcoes .vc-opcao input { margin-top: 1px; }
    .vc-opcao b { display: block; color: var(--black); font-weight: 700; }
    .vc-opcao small { display: block; color: var(--muted); font-size: .85rem; margin-top: 2px; }
    .vc-opcoes-linha .vc-opcao b { display: inline; font-weight: 600; }
    .vc-opcao:hover { border-color: var(--vc-borda); }
    .vc-opcao:has(:checked) { border-color: var(--red); background: #fff7f7; }
    .vc-opcao:has(:focus-visible) { outline: 3px solid rgba(204, 0, 0, .22); outline-offset: 1px; }
    .vc-consentimento .vc-opcao { font-weight: 500; }
    .vc-consentimento .vc-opcao a { color: var(--red); text-decoration: underline; }
    .vc-aviso { padding: 14px 16px; border-radius: 12px; background: #fff7f7; border: 1px solid #f5c2c7; color: #a30000; font-size: .95rem; }
    .vc-aviso p { margin: 0; }
    .vc-aviso ul { margin: 8px 0 0; padding-left: 20px; }
    .vc-aviso a { color: #a30000; font-weight: 700; text-decoration: underline; }
    .vc-aviso:focus { outline: 3px solid rgba(204, 0, 0, .22); outline-offset: 2px; }
    .vc-envio { display: flex; flex-wrap: wrap; gap: 14px 18px; align-items: center; }
    .vc-envio .btn { min-height: 50px; padding: .95rem 1.7rem; font-size: 1.02rem; }
    .vc-envio .btn i { margin-right: 2px; }
    .vc-envio .btn[disabled] { opacity: .7; cursor: progress; transform: none; }
    .vc-envio p { margin: 0; font-size: .88rem; color: var(--muted); }
    .vc-armadilha { position: absolute; left: -9999px; height: 0; overflow: hidden; }
    .vc-sr { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
    .vc-sucesso { background: #fff; border: 1px solid #b7e4c7; border-radius: var(--radius); padding: 28px 26px 30px; scroll-margin-top: 90px; }
    .vc-sucesso:focus { outline: none; }
    .vc-sucesso-topo { display: flex; gap: 14px; align-items: flex-start; }
    .vc-sucesso-topo > i { color: #1b7f3b; font-size: 1.9rem; flex-shrink: 0; margin-top: 2px; }
    .vc-sucesso h2 { margin: 0 0 6px; color: var(--black); font-size: clamp(1.4rem, 3vw, 1.8rem); letter-spacing: -.03em; }
    .vc-sucesso p { color: var(--text); }
    .vc-protocolo-rotulo { margin: 18px 0 4px; font-size: .78rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); }
    .vc-protocolo { margin: 0 0 16px; font-size: clamp(1.6rem, 4vw, 2.2rem); font-weight: 900; letter-spacing: .02em; color: var(--black); font-variant-numeric: tabular-nums; }
    .vc-aprovacao { margin: 14px 0 0; padding: 12px 14px; border-radius: 12px; background: #fff7f7; border: 1px solid #f5c2c7; border-left: 4px solid var(--red); line-height: 1.55; }
    .vc-menor { padding: 12px 14px; border-radius: 12px; background: var(--soft); border: 1px solid var(--line); }
    .vc-sucesso h3 { margin: 22px 0 10px; color: var(--black); font-size: 1.1rem; }
    .vc-passos { margin: 0; padding-left: 22px; display: grid; gap: 8px; }
    .vc-passos b { color: var(--black); }
    .vc-sucesso-acoes { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 22px; }
    .vc-rodape-pagina { padding: 26px 0 56px; }
    .vc-rodape-pagina .wrap { max-width: 860px; }
    .vc-rodape-pagina p { margin: 0; font-size: .95rem; color: var(--muted); }
    .vc-rodape-pagina a { color: var(--red); font-weight: 700; text-decoration: underline; }
    @media (max-width: 920px) {
      /* O CSS da home esconde qualquer <nav> abaixo de 920px; aqui os links do menu voltam com o menu aberto. */
      .main-header .header-collapse .nav-links { display: flex !important; }
      .vc-etapas { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 720px) {
      .vc-topo { padding: 28px 0 28px; }
      .vc-corpo { padding: 26px 0 8px; }
      .vc-grade, .vc-opcoes { grid-template-columns: 1fr; }
      .vc-cartao { padding: 20px 16px 22px; }
      .vc-envio .btn { width: 100%; }
      .vc-sucesso { padding: 22px 18px 24px; }
      .vc-sucesso-acoes .btn { width: 100%; }
    }
    @media (max-width: 480px) {
      .vc-etapas { grid-template-columns: 1fr; }
    }
    .vc-selfie-previa { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; margin-top: 10px; }
    .vc-selfie-previa[hidden] { display: none; }
    .vc-selfie-previa img { display: block; width: 140px; height: 140px; object-fit: cover; border-radius: 12px; border: 1px solid #e2e8f0; }
  </style>"""


def formulario() -> str:
    ocultos = '<input type="hidden" name="pagina" value="/voluntariado/cadastro/">' + "".join(
        f'<input type="hidden" name="origem[{k}]" value="">' for k in ORIGEM_CHAVES)
    dados = cartao("vc-dados", "Seus dados", "", '<div class="vc-grade">' + "".join([
        entrada("nome", "Nome completo", obrigatorio=True, largo=True, atributos='autocomplete="name" maxlength="160" autocapitalize="words"'),
        entrada("data_nascimento", "Data de nascimento", tipo="date", obrigatorio=True,
                dica="A partir de 18 anos.",
                atributos='autocomplete="bday" min="1900-01-01" placeholder="dd/mm/aaaa"'),
        entrada("cpf", "CPF", dica="Só é obrigatório se você for aceito, para o termo de adesão. Pode deixar em branco agora.",
                atributos='inputmode="numeric" autocomplete="off" maxlength="14" placeholder="000.000.000-00"'),
    ]) + "</div>")
    contato = cartao("vc-contato", "Contato", "A confirmação chega por e-mail; a equipe do voluntariado chama pelo WhatsApp.", '<div class="vc-grade">' + "".join([
        entrada("email", "E-mail", tipo="email", obrigatorio=True, atributos='autocomplete="email" maxlength="190" inputmode="email" spellcheck="false"'),
        entrada("telefone", "Telefone com DDD", tipo="tel", obrigatorio=True, dica="De preferência um celular com WhatsApp: é por ele que a equipe do voluntariado entra em contato.", atributos='autocomplete="tel" inputmode="tel" maxlength="16" placeholder="(21) 99999-9999"'),
        entrada("cidade", "Cidade", obrigatorio=True, atributos='autocomplete="address-level2" maxlength="120"'),
        entrada("bairro", "Bairro", atributos='autocomplete="address-level3" maxlength="120"'),
        selecao("estado", "Estado", [(uf, uf) for uf in UFS], obrigatorio=True, selecionado="RJ", atributos='autocomplete="address-level1"'),
    ]) + "</div>")
    perfil = cartao("vc-perfil", "Perfil", "", '<div class="vc-grade">' + "".join([
        selecao("escolaridade", "Escolaridade", ESCOLARIDADES, obrigatorio=True),
        entrada("profissao", "Profissão ou ocupação", atributos='autocomplete="organization-title" maxlength="120"'),
        grupo("saude", "Você tem formação na área da saúde?", [("sim", "Sim"), ("nao", "Não")], tipo="radio", classe="vc-opcoes-linha",
              dica="Não é preciso: a Cruz Vermelha forma os próprios voluntários."),
        entrada("saude_qual", "Qual formação?", largo=True, caixa_id="vc-saude-qual-caixa", oculta=True,
                atributos='maxlength="160" placeholder="Ex.: técnica em enfermagem, estudante de medicina"'),
    ]) + "</div>")
    ajuda = cartao("vc-ajuda", "Como você quer ajudar", "Marque tudo o que fizer sentido. A equipe apresenta as frentes com mais detalhe na entrevista.",
                   '<div class="vc-grade">' + "".join([
                       grupo("frentes", "Frentes de atuação", FRENTES),
                       grupo("dias", "Dias da semana em que você pode atuar", DIAS, classe="vc-opcoes-linha"),
                       grupo("periodos", "Períodos", PERIODOS, classe="vc-opcoes-linha"),
                   ]) + "</div>")
    sobre = cartao("vc-sobre", "Sobre você", "", '<div class="vc-grade">' + "".join([
        area("motivacao", "Por que você quer ser voluntário da Cruz Vermelha?", obrigatorio=True, contador=True,
             atributos='minlength="20" maxlength="1500"'),
        area("experiencia", "Experiência com voluntariado ou trabalho social", atributos='maxlength="1000"',
             dica="Onde você já atuou, por quanto tempo e o que fazia."),
        area("habilidades", "Habilidades que você quer colocar à disposição", atributos='maxlength="1000"',
             dica="Idiomas, fotografia, informática, direção, organização de eventos, o que vier à cabeça."),
        selecao("como_conheceu", "Como você conheceu a Cruz Vermelha RJ?", COMO_CONHECEU),
    ]) + "</div>")
    # Selfie obrigatória (27/10/2026; nasceu opcional no mesmo dia): só para a equipe reconhecer a pessoa na entrevista e no CBFI. Sem JavaScript vai como
    # arquivo (o formulário é multipart); com ele, cadastro.js reduz a foto no navegador e manda junto do JSON.
    selfie = cartao("vc-selfie-cartao", "Sua selfie", "Mande uma foto do seu rosto. Ela é obrigatória para enviar o cadastro.",
                    '<div class="vc-campo vc-campo-largo" data-campo="selfie">'
                    '<label for="vc-selfie">Selfie <span class="vc-obrig" aria-hidden="true">*</span></label>'
                    '<input type="file" id="vc-selfie" name="selfie" accept="image/jpeg,image/png" required aria-required="true" aria-describedby="vc-selfie-dica">'
                    '<p class="vc-dica" id="vc-selfie-dica">Uma foto de frente, em JPG ou PNG, de até 10 MB. Serve só para a equipe '
                    'reconhecer você na entrevista e no CBFI: fica no portal da equipe, não é publicada e é apagada junto com a ficha.</p>'
                    '<div class="vc-selfie-previa" id="vc-selfie-previa" hidden><img id="vc-selfie-img" alt="Prévia da sua selfie">'
                    '<button type="button" class="btn btn-outline" id="vc-selfie-remover">Remover a selfie</button></div>'
                    + _erro("selfie") + '</div>')
    consentimentos = cartao("vc-consentimentos", "Consentimentos", "", '<div class="vc-grade">' + "".join([
        caixa_unica("consentimento",
                    'Li e aceito a <a href="/privacidade/#voluntariado" target="_blank" rel="noopener">Política de Privacidade<span class="vc-sr"> (abre em nova aba)</span></a>, '
                    "que explica como a Cruz Vermelha RJ usa os dados deste cadastro.", obrigatorio=True),
        caixa_unica("aceita_contato", "Aceito receber contato por e-mail e telefone sobre o meu cadastro.", obrigatorio=False),
        caixa_unica("veracidade", "Declaro que as informações são verdadeiras.", obrigatorio=True),
    ]) + "</div>")
    armadilha = ('<div class="vc-armadilha" aria-hidden="true"><label for="vc-site">Não preencha este campo</label>'
                 '<input type="text" id="vc-site" name="site" tabindex="-1" autocomplete="off"></div>')
    envio = ('<div class="vc-envio"><button type="submit" class="btn btn-red"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i>Enviar meu cadastro</button>'
             '<p>Depois de enviar, você recebe a confirmação com o protocolo por e-mail.</p></div>')
    return "\n".join([
        f'    <form class="vc-form" id="ficha" method="post" action="{API}" enctype="multipart/form-data" novalidate aria-labelledby="vc-titulo">',
        f"      {ocultos}",
        dados, contato, perfil, ajuda, sobre, selfie, consentimentos,
        f"      {armadilha}",
        f"      {envio}",
        "    </form>",
    ])


def sucesso() -> str:
    return """    <section class="vc-sucesso" id="vc-sucesso" tabindex="-1" hidden aria-labelledby="vc-sucesso-titulo">
      <div class="vc-sucesso-topo">
        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
        <div>
          <p class="eyebrow">Cadastro recebido</p>
          <h2 id="vc-sucesso-titulo" data-vc="saudacao">Obrigado! Recebemos o seu cadastro.</h2>
          <p class="vc-protocolo-rotulo">Seu protocolo</p>
          <p class="vc-protocolo" data-vc="protocolo"></p>
          <p><span data-vc="email-frase">Enviamos a confirmação para o e-mail que você informou.</span> Guarde o protocolo: ele identifica a sua ficha nas conversas com a equipe. <span data-vc="prazo-frase"></span></p>
          <p class="vc-aprovacao" role="note"><b>Seu cadastro ainda não é a aprovação.</b> Para ser aprovado(a), você passa pela coordenação do voluntariado. Se for aprovado(a), você recebe um <b>código de aprovação</b>; sem ele, a sua entrada no voluntariado ainda não está confirmada.</p>
          <p class="vc-menor" data-vc="menor" hidden><b>Você tem menos de 18 anos:</b> um responsável assina o termo de adesão com você. A equipe explica como fazer isso na entrevista.</p>
          <h3>Próximos passos</h3>
          <ol class="vc-passos">
            <li><b>Contato da coordenação.</b> """ + cbfi.alternar(
                f"Antes do CBFI de {cbfi.DATA_CURTA}, a coordenadora do voluntariado chama você pelo WhatsApp para explicar como será a entrevista.",
                f"Em {PROXIMAS_ENTRADAS}, quando abrem as novas entradas, a coordenadora do voluntariado chama você pelo WhatsApp para explicar como será a entrevista.") + """</li>
            <li><b>Entrevista e aprovação.</b> Na entrevista, a coordenação conhece você, a sua disponibilidade e as frentes de interesse. Se você for aprovado(a), recebe o código de aprovação.</li>
            <li><b>CBFI, o Curso Básico de Formação Institucional.</b> Só para quem foi aprovado(a). """ + cbfi.alternar(
                f"A próxima turma é no {cbfi.DIA_DA_SEMANA}, {cbfi.DATA_TEXTO}, na sede, no Centro do Rio. É gratuito e só para quem entra no voluntariado. {CBFI_LINK}.",
                "Acontece quatro vezes por ano, na sede, no Centro do Rio. É gratuito e só para quem entra no voluntariado.") + """</li>
            <li><b>Termo de adesão.</b> Depois da formação, você assina o termo que formaliza o vínculo e começa a atuar na sua frente.</li>
          </ol>
          <div class="vc-sucesso-acoes">
            <a class="btn btn-outline" href="/voluntariado/"><i class="fa-solid fa-people-group" aria-hidden="true"></i>Conheça o programa de voluntariado</a>
            <a class="btn btn-outline" href="/cursos-gratuitos/"><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i>Como é a formação</a>
          </div>
        </div>
      </div>
    </section>"""


def main() -> int:
    home = HOME.read_text(encoding="utf-8")
    partes = partes_da_home(home)
    header = partes["header"].replace(' aria-current="page"', "")  # a matrícula não é a página atual aqui
    ga4 = partes["ga4"].replace("gtag('config', 'G-", "gtag('set', { content_group: 'voluntariado' });\n    gtag('config', 'G-", 1)
    assert "content_group: 'voluntariado'" in ga4, "não achei o gtag('config') da home para inserir o content_group"
    versao_js = hashlib.sha256(JS_ARQUIVO.read_bytes()).hexdigest()[:10]
    etapas = "\n          ".join(f"<li><b>{esc(t)}</b>{d}</li>" for t, d in ETAPAS)

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
  <meta property="og:site_name" content="Cruz Vermelha Brasileira Rio de Janeiro">
  <meta property="og:title" content="{esc(TITULO)}">
  <meta property="og:description" content="{esc(DESCRICAO)}">
  <meta property="og:url" content="{URL_PAGINA}">
  <meta property="og:image" content="{IMAGEM_OG}">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="{esc(TITULO)}">
  <meta name="twitter:description" content="{esc(DESCRICAO)}">
  <meta name="twitter:image" content="{IMAGEM_OG}">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"></noscript>
  <!-- Estilos copiados da home (site/index.html): mesmo padrão visual da filial. -->
{partes["estilo"]}
{CSS}
  <!-- Sem JavaScript, o campo "Qual formação?" fica sempre visível (com JavaScript ele aparece só com "Sim"). -->
  <noscript><style>#vc-saude-qual-caixa[hidden] {{ display: grid; }}</style></noscript>
{ga4}
{partes["pixel"]}
{json_ld()}
</head>
<body>
{header}

  <main id="conteudo" class="vc-pagina">
    <section class="vc-topo" aria-labelledby="vc-titulo">
      <div class="wrap">
        <nav class="vc-trilha" aria-label="Você está em"><ol><li><a href="/">Início</a></li><li><a href="/voluntariado/">Voluntariado</a></li><li aria-current="page">Cadastro</li></ol></nav>
        <p class="eyebrow">Voluntariado</p>
        <h1 id="vc-titulo">{esc(H1)}</h1>
        <p class="lead">Preencha o cadastro abaixo. Depois dele vêm a ficha de entrevista, o CBFI (Curso Básico de Formação Institucional, oferecido quatro vezes por ano) e o termo de adesão. A formação é gratuita e só para quem entra no voluntariado. A idade mínima é 18 anos.</p>
        <p class="vc-entradas" role="note"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i><span>{cbfi.alternar(
            f"<b>Próxima turma do CBFI: {cbfi.DIA_DA_SEMANA}, {cbfi.DATA_TEXTO}.</b> Ela está aberta a quem se cadastrar agora: a equipe do voluntariado entra em contato pelo WhatsApp com os cadastrados, para a ficha de entrevista e as orientações da turma. {CBFI_LINK}.",
            f"<b>Novas entradas em {esc(PROXIMAS_ENTRADAS)}.</b> Você já pode se cadastrar: a ficha fica guardada, e a equipe do voluntariado entra em contato com todos os cadastrados nessa época. Até lá, não é preciso esperar resposta nem enviar a ficha de novo.")}</span></p>
        <ol class="vc-etapas" aria-label="As quatro etapas para ser voluntário">
          {etapas}
        </ol>
      </div>
    </section>

    <div class="vc-corpo">
      <div class="wrap">
    <p class="vc-intro-form" id="vc-intro-form">Campos com <span class="vc-obrig" aria-hidden="true">*</span><span class="vc-sr">asterisco</span> são obrigatórios. Os dados são usados só para o seu cadastro no voluntariado da Cruz Vermelha Brasileira Rio de Janeiro.</p>
    <noscript><p class="vc-noscript">Sem JavaScript o formulário funciona do mesmo jeito: ao enviar, você volta para esta página e a confirmação com o protocolo chega no e-mail informado. Se algum campo não for aceito, confira os campos e envie de novo.</p></noscript>
    <div class="vc-aviso" id="vc-aviso" role="alert" tabindex="-1" hidden></div>
{formulario()}
{sucesso()}
      </div>
    </div>

    <section class="vc-rodape-pagina" aria-label="Mais sobre o voluntariado">
      <div class="wrap">
        <p>Ainda em dúvida? <a href="/voluntariado/">Conheça o programa de voluntariado</a> e veja como a Cruz Vermelha RJ trata os seus dados na <a href="/privacidade/">Política de Privacidade</a>.</p>
      </div>
    </section>
  </main>

{partes["footer"]}

{partes["menu_js"]}
  <script src="/voluntariado/cadastro/cadastro.js?v={versao_js}" defer></script>
{chat_widget.tags()}
</body>
</html>
"""
    pagina = cbfi.resolver(icones.converter(pagina))
    baixo = pagina.lower()
    # O contato da equipe é pelo WhatsApp (27/10/2026), mas a página não leva o número nem link: o canal de entrada é o formulário.
    assert "970360264" not in baixo and "97036-0264" not in baixo and "api.whatsapp" not in baixo and "wa.me" not in baixo, "a página de cadastro não leva número nem link de WhatsApp"
    assert "cruzvermelharj.org.br" not in baixo, "domínio antigo na página de cadastro"
    assert "spotform" not in baixo, "formulário antigo na página de cadastro"
    assert baixo.count("contato@cruzvermelhariodejaneiro.org") == 1, "o e-mail de contato só aparece no rodapé"
    PASTA.mkdir(parents=True, exist_ok=True)
    SAIDA.write_text(pagina, encoding="utf-8")
    print(f"gravado {SAIDA.relative_to(RAIZ)} ({len(pagina.encode('utf-8'))} bytes; cadastro.js?v={versao_js})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
