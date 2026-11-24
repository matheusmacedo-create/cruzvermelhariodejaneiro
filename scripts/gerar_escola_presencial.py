#!/usr/bin/env python3
"""Gera site/escola-presencial/lei-lucas/index.html: a página de venda do curso Primeiros Socorros Lei Lucas.

Pedido do Matheus (24/11/2026): uma página única, só deste curso, vendendo a turma aberta pelo preço do cupom
PRO12 (R$ 140,00 com tudo incluso), num endereço próprio: /escola-presencial/lei-lucas/.

Nada aqui é dado novo. Tudo vem do que o site já publica:

- curso, carga horária, escolaridade e preço de tabela: site/matricula-cursos-presenciais/cursos.json;
- turma (data, horário e fim das inscrições): site/matricula-cursos-presenciais/oferta.json;
- preço e prazo da promoção: a linha do cupom em api/lib/cupons.php (MCP_CUPONS);
- título, promessa, resposta sobre a Lei Lucas, fotos, certificado, "como a escola sustenta a filial" e a trava de
  vocabulário: scripts/gerar_matricula_presencial.py.

A página tem três estados, em <html data-oferta="…">:
  cupom      o cupom vale: preço da promoção, botão com &cupom=PRO12 (o checkout já abre com ele aplicado);
  tabela     o cupom acabou ou venceu: preço de tabela, botão sem cupom;
  encerrada  as inscrições da turma fecharam (ou a turma mudou): sem botão de compra, com o caminho para as próximas.
O relógio do navegador decide antes da primeira pintura (prazo do cupom e da turma). Depois o info.php corrige: é dele
o número de cupons que restam, contado no servidor. Nenhuma contagem é inventada: sem resposta, o número não aparece.

Quando a turma ou o cupom mudarem (oferta.json, cupons.php), rode de novo e publique a página.

Uso:  python3 scripts/gerar_escola_presencial.py
Depois: publicar com scripts/publicar_hostinger.sh (lista em scripts/publicacao-escola-presencial.txt) e limpar o cache.
"""
from __future__ import annotations

import html
import json
import re
from datetime import datetime, timezone
from pathlib import Path

import chat_widget
from gerar_matricula_presencial import (API_INFO, CERT_NOTA, CERT_PESO, CHECKOUT_URL, COPY_CURSO, DADOS, EMAIL_CONTATO, ENDERECO,
                                        ESCOLA, FOTOS_AULAS, HOME, LOCAL, LOGO, MAPA_ESTATICO, MAPA_ROTA, MARCADORES_PROIBIDOS,
                                        MESES, NACIONAL_SOZINHA, ORIGEM, PALAVRAS_PROIBIDAS, RAIZ, RECEBEDOR_CNPJ, RECEBEDOR_NOME,
                                        SUSTENTA_CURTO, SUSTENTA_URL, SVG, _svg, brl, brl_curto, esc, fontes_da_home, foto_real,
                                        horas_iso, ler_oferta, partes_da_home, turmas_abertas)

SLUG = "primeiros-socorros-lei-lucas"
CUPOM = "PRO12"
NOME_CURTO = "Primeiros Socorros Lei Lucas"
PASTA = RAIZ / "site" / "escola-presencial" / "lei-lucas"
SAIDA = PASTA / "index.html"
URL_PAGINA = f"{ORIGEM}/escola-presencial/lei-lucas/"
URL_MATRICULA = "/matricula-cursos-presenciais/"
URL_CURSO_MATRICULA = f"{URL_MATRICULA}?curso={SLUG}"
URL_TURMA_FECHADA = f"{URL_MATRICULA}?turma=1&amp;turma_curso={SLUG}&amp;alunos=15#turmas-sob-demanda"
URL_KIT_ESCOLAS = "/lei-lucas-para-escolas/"
CUPONS_PHP = RAIZ / "site" / "matricula-cursos-presenciais" / "api" / "lib" / "cupons.php"
IMG_MATRICULA = "/matricula-cursos-presenciais/img/"
# "via" do checkout: lp-lei-lucas-<local do botão>, para separar esta página da matrícula nos relatórios.
VIA = "lp-lei-lucas"
LISTA_GA4 = "Página Lei Lucas"

# Página de campanha (anúncio, WhatsApp, Instagram): fora da busca, como a /bio/. A vitrine que o Google conhece
# continua sendo /matricula-cursos-presenciais/. Para indexar, troque para True e inclua a página no sitemap.
INDEXAR = False

TITULO = "Primeiros Socorros Lei Lucas no Rio: curso presencial | Cruz Vermelha Brasileira Rio de Janeiro"
OG_TITULO = "Primeiros Socorros Lei Lucas: curso presencial no Centro do Rio"
IMAGEM_OG = f"{URL_PAGINA}og-lei-lucas.jpg"
# Foto que a escola publica no curso (a mesma do banner de 09/12), já no Git.
FOTO_TOPO = {"src": f"{IMG_MATRICULA}{SLUG}-960.webp", "srcset": f"{IMG_MATRICULA}{SLUG}-480.webp 480w, {IMG_MATRICULA}{SLUG}-960.webp 960w",
             "alt": "Prática de reanimação em manequim de bebê durante o curso"}

# O que o catálogo da escola lista no "Sobre o curso" (cursos.json), um item por linha, sem acrescentar conteúdo.
APRENDER = [
    "Avaliação da criança",
    "Reanimação cardiopulmonar (RCP)",
    "Engasgos",
    "Quedas e traumas",
    "Convulsões",
    "Queimaduras",
    "Hemorragias",
    "Medidas preventivas",
]
# Para quem é: os lugares que o catálogo cita (famílias, escolas, creches, projetos sociais, esporte) e as funções que
# o kit /lei-lucas-para-escolas/ lista.
PUBLICO = [
    ("predio", "Escolas e creches", "Professores, auxiliares, monitores e as equipes de portaria, cozinha, limpeza e transporte."),
    ("capelo", "Recreação infantil", "Monitores e recreadores de espaços de recreação infantil, que a Lei Lucas também alcança."),
    ("cruz", "Famílias e cuidadores", "Pais, avós, babás e quem cuida de crianças em casa. O curso é aberto a qualquer pessoa."),
    ("medalha", "Projetos sociais e esporte", "Educadores de projetos sociais e de atividades esportivas com crianças."),
]
# Resumo do que a lei pede: o mesmo do kit para escolas (guias/lei-lucas-para-escolas.json, bloco "aviso").
LEI_PEDE = [
    "Capacitação todo ano: curso e reciclagem.",
    "Gente capacitada na proporção da equipe.",
    "Kits de primeiros socorros.",
    "A comprovação, com o nome dos capacitados, afixada em lugar visível.",
]


def ler_cupom() -> dict:
    """A linha do cupom em MCP_CUPONS (cupons.php é a fonte: o servidor cobra por ela)."""
    php = CUPONS_PHP.read_text(encoding="utf-8")
    m = re.search(rf"^\s*'{CUPOM}'\s*=>\s*\[(.*)\],\s*$", php, re.M)
    if not m:
        raise SystemExit(f"cupom {CUPOM} não está em {CUPONS_PHP.relative_to(RAIZ)}: sem ele, esta página não tem promoção")
    linha = m.group(1)
    if SLUG not in linha:
        raise SystemExit(f"o cupom {CUPOM} não vale para {SLUG}")
    total = re.search(r"'total_centavos'\s*=>\s*(\d+)", linha)
    ate = re.search(r"'ate'\s*=>\s*(null|'([^']+)')", linha)
    if not total or not ate:
        raise SystemExit(f"não consegui ler total_centavos e ate do cupom {CUPOM}")
    if "'vitrine' => true" not in linha:
        raise SystemExit(f"o cupom {CUPOM} não está na vitrine: o info.php não diria quantos restam")
    if "'grupo'" in linha:
        raise SystemExit(f"o cupom {CUPOM} é de grupo: esta página vende uma inscrição por vez")
    return {"total": int(total.group(1)), "ate": datetime.fromisoformat(ate.group(2)) if ate.group(2) else None}


def hora_curta(d: datetime) -> str:
    """9h, 17h, 8h30."""
    return f"{d.hour}h" + (f"{d.minute:02d}" if d.minute else "")


def main() -> int:
    home = HOME.read_text(encoding="utf-8")
    partes = partes_da_home(home)
    dados = json.loads(DADOS.read_text(encoding="utf-8"))
    curso = next(c for c in dados["cursos"] if c["slug"] == SLUG)
    inscricao = int(dados["inscricao_centavos"])
    matricula = int(curso["valor_curso_centavos"])
    tabela = inscricao + matricula
    cupom = ler_cupom()
    economia = tabela - cupom["total"]
    if economia <= 0:
        raise SystemExit(f"o cupom {CUPOM} ({brl(cupom['total'])}) não é menor que a tabela ({brl(tabela)})")

    agora = datetime.now(timezone.utc)
    abertas = turmas_abertas(ler_oferta()["turmas"].get(SLUG, []), agora)
    if not abertas:
        raise SystemExit(f"{SLUG} não tem turma aberta no oferta.json: a página vende uma turma com data")
    turma = abertas[0]
    # O cupom nunca vale depois do fim das inscrições da turma.
    cupom_ate = min(cupom["ate"], turma.inscricoes_ate) if cupom["ate"] else turma.inscricoes_ate
    if cupom_ate < agora:
        raise SystemExit(f"o cupom {CUPOM} venceu em {cupom_ate:%d/%m/%Y}: atualize cupons.php antes de gerar a página da promoção")

    copy = COPY_CURSO[SLUG]
    dia = turma.leitor  # quarta-feira, 9 de dezembro de 2026
    dia_curto = f"{turma.dia_semana}{'' if turma.inicio.weekday() >= 5 else '-feira'}, {turma.inicio.day} de {MESES[turma.inicio.month - 1]}"
    horario = f"das {hora_curta(turma.inicio)} às {hora_curta(turma.fim)}"
    prazo_cupom = f"{cupom_ate.day:02d}/{cupom_ate.month:02d}"
    prazo_turma = f"{turma.inscricoes_ate.day:02d}/{turma.inscricoes_ate.month:02d}"
    carga = curso["carga_horaria"]
    v_cupom, v_tabela, v_economia = brl_curto(cupom["total"]), brl_curto(tabela), brl_curto(economia)
    descricao = (f"Curso presencial de {carga} para quem cuida de crianças: engasgos, quedas, convulsões, queimaduras, hemorragias e "
                 f"RCP, com prática e certificado. Turma em {turma.ddmm}, no Centro do Rio.")

    # O preço do cupom em texto corrido: se o cupons.php mudar sem a página ser regerada, o JS troca pelo valor do info.php.
    preco_vivo = f'<span data-ll-cupom>{v_cupom}</span>'

    def checkout(local: str) -> str:
        return f"{CHECKOUT_URL}?curso={SLUG}&amp;via={VIA}-{local}&amp;cupom={CUPOM}"

    def cta(local: str, classe: str = "ll-btn ll-btn-vermelho ll-btn-grande", curto: bool = False) -> str:
        """Botão de compra: o rótulo muda com o estado; o JS tira o cupom do endereço fora do estado "cupom"."""
        com = "Inscrever-se" if curto else f"Inscrever-se por {preco_vivo}"
        return (f'<a class="{classe} ll-cta ll-se-aberta" data-local="{local}" href="{checkout(local)}">'
                f'<span class="ll-se-cupom">{com}</span><span class="ll-se-tabela">Inscrever-se</span>{SVG["seta"]}</a>')

    def proximas(classe: str = "ll-btn ll-btn-vermelho ll-btn-grande") -> str:
        return f'<a class="{classe} ll-se-encerrada" href="{URL_CURSO_MATRICULA}" data-local="proximas">Ver as próximas turmas {SVG["seta"]}</a>'

    def chat(texto: str, local: str, classe: str = "ll-link") -> str:
        return f'<a class="{classe}" href="#chat" data-abrir-chat data-assunto="matricula" data-curso="{SLUG}" data-local="{local}">{texto}</a>'

    marca = (f'<img src="{LOGO["src"]}" width="{LOGO["largura"]}" height="{LOGO["altura"]}" alt="{esc(LOGO["alt"])}">'
             f'<span class="ll-marca-escola"><b>Escola de Educação</b><span>e Saúde CVB-RJ</span></span>')

    # --- preço (o mesmo bloco no topo e no Investimento) ---------------------------------------------------------
    selo_cupom = (f'<p class="ll-selo ll-se-cupom">{_svg(TAG_SVG)}<span>Cupom <b>{CUPOM}</b> já aplicado no botão'
                  f'<span data-ll-restam hidden></span> · vale até {prazo_cupom}, enquanto houver cupons</span></p>')
    preco_topo = f'''
          <div class="ll-preco ll-se-cupom">
            <p class="ll-preco-de">De <s data-ll-tabela>{v_tabela}</s> por</p>
            <p class="ll-preco-por"><b data-ll-cupom>{v_cupom}</b><span>com tudo incluso</span></p>
            <p class="ll-preco-nota">Taxa de inscrição + matrícula, à vista. PIX ou cartão.</p>
          </div>
          <div class="ll-preco ll-se-tabela">
            <p class="ll-preco-de">Total à vista</p>
            <p class="ll-preco-por"><b data-ll-tabela>{v_tabela}</b></p>
            <p class="ll-preco-nota">Matrícula de {brl_curto(matricula)} + taxa de inscrição de {brl_curto(inscricao)}. PIX ou cartão.</p>
          </div>
          <div class="ll-preco ll-se-encerrada">
            <p class="ll-preco-de">Turma de {turma.ddmm}</p>
            <p class="ll-preco-fim">Inscrições encerradas</p>
            <p class="ll-preco-nota">Veja na página da Escola quando abre a próxima turma deste curso.</p>
          </div>'''

    # --- topo ----------------------------------------------------------------------------------------------------
    cabecalho = f'''
  <header class="ll-cab">
    <div class="ll-wrap ll-cab-barra">
      <a class="ll-marca" href="{URL_MATRICULA}" data-saida-ll="marca">{marca}</a>
      <div class="ll-cab-acoes">
        {chat("Dúvidas?", "cabecalho", "ll-btn ll-btn-contorno ll-cab-duvidas")}
        {cta("cabecalho", "ll-btn ll-btn-vermelho ll-cab-cta", curto=True)}
      </div>
    </div>
  </header>'''
    chips = "".join(f"<li>{esc(t)}</li>" for t in [carga, "Presencial no Centro do Rio", curso["escolaridade"], "Certificado"])
    hero = f'''
    <section class="ll-hero" aria-labelledby="ll-titulo" data-secao="topo">
      <img class="ll-hero-foto" src="{FOTO_TOPO["src"]}" srcset="{FOTO_TOPO["srcset"]}" sizes="(min-width: 980px) 60vw, 100vw" width="960" height="720" alt="{esc(FOTO_TOPO["alt"])}" fetchpriority="high" decoding="async">
      <div class="ll-wrap ll-hero-grade">
        <div class="ll-hero-texto">
          <p class="ll-olho"><span>Curso presencial</span> Ambientes com crianças</p>
          <h1 id="ll-titulo">{esc(copy["titulo"])}</h1>
          <p class="ll-hero-lead">{esc(copy["promessa"])}</p>
          <ul class="ll-chips" aria-label="Resumo do curso">{chips}</ul>
        </div>
        <div class="ll-cartao" id="inscricao">
          <p class="ll-kicker">Próxima turma</p>
          <div class="ll-turma">
            <div class="ll-data" aria-hidden="true"><b>{turma.inicio.day}</b><span>{esc(MESES[turma.inicio.month - 1])}</span></div>
            <div>
              <p class="ll-turma-dia"><span class="ll-sr">{esc(dia)}. </span><span aria-hidden="true">{esc(dia_curto.capitalize())}</span></p>
              <p class="ll-turma-hora">{esc(horario)} · Centro do Rio</p>
              <p class="ll-dias ll-se-aberta" data-ll-dias hidden></p>
            </div>
          </div>{preco_topo}
          {selo_cupom}
          {cta("topo", "ll-btn ll-btn-vermelho ll-btn-grande ll-btn-largo")}
          {proximas("ll-btn ll-btn-vermelho ll-btn-grande ll-btn-largo")}
          <p class="ll-micro ll-se-aberta">Desistência em até 7 dias do pagamento, com o valor de volta.</p>
        </div>
      </div>
    </section>
    <section class="ll-confianca" aria-label="Sobre a Escola">
      <div class="ll-wrap">
        <ul>
          <li>{SVG["historia"]}<span>Escola ligada à Cruz Vermelha Brasileira Rio de Janeiro</span></li>
          <li>{SVG["predio"]}<span>Aula prática no Palácio da Cruz Vermelha</span></li>
          <li>{SVG["medalha"]}<span>Certificado com o seu nome</span></li>
        </ul>
      </div>
    </section>'''

    # --- para quem é / o que aprende -------------------------------------------------------------------------------
    publico = "".join(f'<li><span class="ll-ico">{SVG[i]}</span><h3>{esc(t)}</h3><p>{esc(d)}</p></li>' for i, t, d in PUBLICO)
    aprender = "".join(f'<li>{SVG["check"]}<span>{esc(a)}</span></li>' for a in APRENDER)
    fotos = "".join(f'<li><figure>{foto_real(f, "(min-width: 860px) 25vw, 70vw")}<figcaption>{esc(f["legenda"])}</figcaption></figure></li>'
                    for f in FOTOS_AULAS)
    para_quem = f'''
    <section class="ll-secao" id="para-quem" aria-labelledby="quem-titulo" data-secao="para_quem">
      <div class="ll-wrap">
        <p class="ll-kicker">Para quem é</p>
        <h2 id="quem-titulo">Para quem tem crianças por perto.</h2>
        <p class="ll-sub">Não precisa ser da área da saúde. A escolaridade mínima é o {esc(curso["escolaridade"])}.</p>
        <ul class="ll-publico">{publico}</ul>
      </div>
    </section>'''
    conteudo = f'''
    <section class="ll-secao ll-secao-clara" id="conteudo" aria-labelledby="conteudo-titulo" data-secao="conteudo">
      <div class="ll-wrap">
        <p class="ll-kicker">O que você vai aprender</p>
        <h2 id="conteudo-titulo">O que fazer até o socorro chegar.</h2>
        <p class="ll-sub">{carga.capitalize()} de teoria e prática, com protocolos atualizados de primeiros socorros voltados ao atendimento infantil.</p>
        <ul class="ll-aprender">{aprender}</ul>
        <h3 class="ll-h3">Aqui você aprende fazendo.</h3>
        <ul class="ll-fotos" aria-label="Fotos das aulas">{fotos}</ul>
        <div class="ll-centro">{cta("conteudo")}{proximas()}</div>
      </div>
    </section>'''

    # --- a lei ---------------------------------------------------------------------------------------------------
    lei_pede = "".join(f'<li>{SVG["check-circulo"]}<span>{esc(i)}</span></li>' for i in LEI_PEDE)
    lei = f'''
    <section class="ll-secao" id="lei-lucas" aria-labelledby="lei-titulo" data-secao="lei">
      <div class="ll-wrap ll-duas">
        <div>
          <p class="ll-kicker">Lei Federal 13.722/2018</p>
          <h2 id="lei-titulo">O que a Lei Lucas pede.</h2>
          <p class="ll-prosa">A Lei Lucas obriga escolas de educação básica, públicas e privadas, e espaços de recreação infantil a capacitar professores e funcionários em noções básicas de primeiros socorros. Para atender a essa exigência, a Escola de Educação e Saúde oferece este curso.</p>
          <ul class="ll-lista">{lei_pede}</ul>
        </div>
        <aside class="ll-caixa" aria-labelledby="escola-titulo">
          <h3 id="escola-titulo">É da direção ou da coordenação de uma escola?</h3>
          <p>Para uma ou duas pessoas, a inscrição é nesta turma. Para a equipe inteira, de 15 a 30 pessoas, a Escola abre uma turma só de vocês, com data combinada e o mesmo valor por pessoa dos cursos.</p>
          <p class="ll-caixa-acoes">
            <a class="ll-btn ll-btn-preto" href="{URL_TURMA_FECHADA}" data-local="turma_fechada" data-ll-turma>Pedir uma turma para a escola {SVG["seta"]}</a>
            <a class="ll-link" href="{URL_KIT_ESCOLAS}" data-local="kit_escolas">Baixar o kit gratuito da Lei Lucas para escolas</a>
          </p>
        </aside>
      </div>
    </section>'''

    # --- como funciona ---------------------------------------------------------------------------------------------
    como = f'''
    <section class="ll-secao ll-secao-clara" id="como-funciona" aria-labelledby="como-titulo" data-secao="como_funciona">
      <div class="ll-wrap">
        <p class="ll-kicker">Como funciona</p>
        <h2 id="como-titulo">Três passos até a aula.</h2>
        <ol class="ll-passos">
          <li><b>01</b><h3>Faça a inscrição.</h3><p>Preencha nome, CPF, e-mail e celular e pague por PIX ou cartão, à vista. Não precisa criar conta antes.</p></li>
          <li><b>02</b><h3>Receba as orientações.</h3><p>Antes da aula, você recebe por e-mail e na sua conta as orientações da turma: data, horário, local e o que vestir.</p></li>
          <li><b>03</b><h3>Venha para a aula.</h3><p>{esc(dia.capitalize())}, {esc(horario)}, na Praça da Cruz Vermelha, 10, no Centro do Rio.</p></li>
        </ol>
      </div>
    </section>'''

    # --- investimento ----------------------------------------------------------------------------------------------
    aviso_entrada = (f'<p class="ll-aviso ll-se-tabela">{SVG["exclamacao"]}<span>A participação nas aulas é liberada só com a <b>matrícula paga</b>, '
                     'além da taxa de inscrição. Quem pagou apenas a inscrição não tem a entrada liberada.</span></p>')
    investimento = f'''
    <section class="ll-secao" id="investimento" aria-labelledby="inv-titulo" data-secao="investimento">
      <div class="ll-wrap ll-duas ll-duas-inv">
        <div>
          <p class="ll-kicker">Investimento</p>
          <h2 id="inv-titulo"><span class="ll-se-cupom">{preco_vivo}, com tudo incluso.</span><span class="ll-se-tabela">Valor fechado, sem surpresa.</span><span class="ll-se-encerrada">Inscrições encerradas.</span></h2>
          <p class="ll-prosa ll-se-cupom">Nesta turma, o cupom {CUPOM} fecha a taxa de inscrição e a matrícula em <b data-ll-cupom-longo>{brl(cupom["total"])}</b>, à vista. Sem mensalidade, sem taxa de certificado e sem material à parte.</p>
          <p class="ll-prosa ll-se-tabela">O total aparece antes do pagamento: matrícula mais taxa de inscrição, à vista. Sem mensalidade, sem taxa de certificado e sem material à parte.</p>
          <p class="ll-prosa ll-se-encerrada">As inscrições da turma de {turma.ddmm} fecharam. A página da Escola mostra as próximas datas deste curso.</p>
          <p class="ll-sustenta">{esc(SUSTENTA_CURTO)} <a class="ll-link" href="{SUSTENTA_URL}">Veja como a filial se financia</a>.</p>
        </div>
        <aside class="ll-inv" aria-label="Valores">
          <dl class="ll-se-cupom">
            <div><dt>Matrícula + taxa de inscrição, na tabela</dt><dd><s data-ll-tabela-longo>{brl(tabela)}</s></dd></div>
            <div><dt>Desconto do cupom {CUPOM}</dt><dd class="ll-verde" data-ll-economia>− {brl(economia)}</dd></div>
            <div class="ll-inv-total"><dt>Total à vista</dt><dd data-ll-cupom-longo>{brl(cupom["total"])}</dd></div>
          </dl>
          <dl class="ll-se-tabela">
            <div><dt>Valor da matrícula, à vista</dt><dd>{brl(matricula)}</dd></div>
            <div><dt>Taxa de inscrição</dt><dd>{brl(inscricao)}</dd></div>
            <div class="ll-inv-total"><dt>Total à vista, com a inscrição</dt><dd data-ll-tabela-longo>{brl(tabela)}</dd></div>
          </dl>
          {selo_cupom}
          {cta("investimento", "ll-btn ll-btn-vermelho ll-btn-grande ll-btn-largo")}
          {proximas("ll-btn ll-btn-vermelho ll-btn-grande ll-btn-largo")}
          <p class="ll-micro ll-se-aberta">PIX ou cartão, à vista.</p>
          {aviso_entrada}
          <p class="ll-micro">{chat("Dúvidas? Fale com a secretaria", "investimento")}</p>
        </aside>
      </div>
    </section>'''

    # --- certificado e local -----------------------------------------------------------------------------------------
    cert = f'{IMG_MATRICULA}certificado-{SLUG}'
    if not (RAIZ / "site" / "matricula-cursos-presenciais" / "img" / f"certificado-{SLUG}-640.webp").exists():
        raise SystemExit("falta a imagem do modelo do certificado em matricula-cursos-presenciais/img/")
    certificado = f'''
    <section class="ll-secao ll-secao-clara" id="certificado" aria-labelledby="cert-titulo" data-secao="certificado">
      <div class="ll-wrap ll-duas">
        <div>
          <p class="ll-kicker">Certificado</p>
          <h2 id="cert-titulo">Certificado com o seu nome, o curso e as {esc(carga)}.</h2>
          <p class="ll-prosa">{esc(CERT_PESO)}</p>
          <p class="ll-prosa">É curso livre: prepara para o atendimento inicial até a chegada do socorro especializado e não substitui habilitação profissional regulamentada.</p>
        </div>
        <figure class="ll-cert">
          <img src="{cert}-640.webp" srcset="{cert}-640.webp 640w, {cert}-1200.webp 1200w" sizes="(min-width: 980px) 520px, 100vw" width="640" height="452" loading="lazy" decoding="async" alt="Modelo do certificado do curso de {esc(curso["nome"])} fornecido pela filial para ilustração, com o nome do aluno, o curso e a carga horária">
          <figcaption>{esc(CERT_NOTA)}</figcaption>
        </figure>
      </div>
    </section>'''
    chegar = f'''
    <section class="ll-secao" id="como-chegar" aria-labelledby="chegar-titulo" data-secao="como_chegar">
      <div class="ll-wrap ll-duas">
        <div>
          <p class="ll-kicker">Como chegar</p>
          <h2 id="chegar-titulo">Praça da Cruz Vermelha, 10.</h2>
          <p class="ll-prosa">Centro · Rio de Janeiro – RJ. A aula acontece aqui, na sede da Cruz Vermelha Brasileira Rio de Janeiro.</p>
          <p class="ll-acao"><a class="ll-btn ll-btn-contorno" href="{MAPA_ROTA}" target="_blank" rel="noopener" data-local="mapa" data-ll-mapa>{SVG["pino"]}Abrir no Google Maps</a></p>
        </div>
        <figure class="ll-mapa">
          <img src="{MAPA_ESTATICO["src"]}" srcset="{MAPA_ESTATICO["srcset"]}" sizes="(min-width: 980px) 560px, 100vw" width="960" height="720" loading="lazy" decoding="async" alt="{esc(MAPA_ESTATICO["alt"])}">
          <figcaption>{esc(MAPA_ESTATICO["credito"])}</figcaption>
        </figure>
      </div>
    </section>'''

    # --- dúvidas -----------------------------------------------------------------------------------------------------
    # Respostas tiradas da página de matrícula e da faq-home.json; as de preço mudam com o estado da oferta.
    # (classe do estado, pergunta já em HTML, resposta em HTML)
    lei_r = copy["objecao"][1].split(" A escola que quer treinar")[0]
    duvidas_itens = [
        ("", esc(copy["objecao"][0]), f"<p>{esc(lei_r)}</p>"),
        ("ll-se-cupom", f"O que está incluso nos {preco_vivo}?",
         f"<p>A taxa de inscrição e a matrícula, que é o valor do curso, à vista, por PIX ou cartão. Na tabela, as duas somam {v_tabela} "
         f"(matrícula de {brl_curto(matricula)} + taxa de inscrição de {brl_curto(inscricao)}). O cupom {CUPOM} já vem aplicado no botão desta página.</p>"
         "<p>No pagamento aparecem dois opcionais, para cobrir os custos de processamento e para ajudar na divulgação dos cursos. Só paga quem marcar.</p>"),
        ("ll-se-cupom", f"Até quando vale o preço de {preco_vivo}?",
         f"<p>Até {prazo_cupom}, às 23h59, ou até os cupons acabarem, o que vier primeiro. O número de cupons que restam é contado pelo sistema de "
         f"inscrição, na hora. Depois disso, o curso volta ao valor da tabela, {v_tabela}.</p>"),
        ("ll-se-tabela", "Quanto custa o curso?",
         f"<p>A matrícula, que é o valor do curso, custa {brl_curto(matricula)}, e a taxa de inscrição, {brl_curto(inscricao)}: {v_tabela} à vista, por PIX "
         "ou cartão. A entrada na aula é liberada com a matrícula paga.</p>"),
        ("", "Recebo certificado?",
         f"<p>Sim. O curso oferece certificado aos alunos que concluírem os requisitos previstos. {esc(CERT_PESO)}</p>"),
        ("", "Posso desistir depois de pagar?",
         "<p>Você pode desistir em até 7 dias corridos, contados do pagamento, sem precisar dizer o motivo (art. 49 do CDC), e o valor pago volta por "
         f"inteiro. Para pedir, use o {chat('chat “Fale com a gente”', 'duvida_reembolso')}, no assunto “Pagamento ou PIX”. As regras completas estão na "
         '<a class="ll-link" href="/reembolso/">página de cancelamento e reembolso</a>.</p>'),
        ("", "O que acontece se a turma não for formada?",
         "<p>Se a turma não for formada, tudo o que você pagou neste site volta por inteiro. As regras completas, com os prazos, estão na "
         '<a class="ll-link" href="/reembolso/">página de cancelamento e reembolso</a>.</p>'),
        ("", "Preciso criar conta para me inscrever?",
         "<p>Não. Você preenche nome, CPF, e-mail e telefone celular e paga. Se você ainda não tem conta na área do aluno, no site da Escola "
         "(escola.cursoscruzvermelha.org), ela é criada depois do pagamento, e o link para criar a senha chega no seu e-mail.</p>"),
        ("", "Minha escola quer treinar a equipe inteira. Como faz?",
         "<p>Com 15 a 30 pessoas, a turma é só do grupo, com data combinada e o mesmo valor por pessoa dos cursos, na sede; em outro local, depende "
         f'de aprovação. <a class="ll-link" href="{URL_TURMA_FECHADA}" data-local="duvida_turma" data-ll-turma>Peça uma turma para a sua escola</a>: nada é cobrado agora e a '
         "secretaria responde por e-mail em até 3 dias úteis.</p>"),
    ]
    duvidas_html = "".join(f'<details{f" class={chr(34)}{c}{chr(34)}" if c else ""}><summary><span>{p}</span></summary><div>{r}</div></details>'
                           for c, p, r in duvidas_itens)
    duvidas = f'''
    <section class="ll-secao ll-secao-clara" id="duvidas" aria-labelledby="duvidas-titulo" data-secao="duvidas">
      <div class="ll-wrap ll-estreito">
        <p class="ll-kicker">Perguntas frequentes</p>
        <h2 id="duvidas-titulo">Antes de se inscrever.</h2>
        <div class="ll-faq">{duvidas_html}</div>
        <p class="ll-sub">Não encontrou o que procura? {chat("Fale com a secretaria pelo chat", "duvidas")}.</p>
      </div>
    </section>'''
    final = f'''
    <section class="ll-final" id="final" aria-labelledby="final-titulo" data-secao="final">
      <div class="ll-wrap ll-estreito">
        <h2 id="final-titulo">Um dia de aula para saber o que fazer.</h2>
        <p class="ll-se-aberta">{esc(dia.capitalize())}, {esc(horario)}, no Palácio da Cruz Vermelha, no Centro do Rio.</p>
        <p class="ll-se-encerrada">As inscrições da turma de {turma.ddmm} fecharam. Veja quando abre a próxima.</p>
        <p class="ll-final-preco ll-se-cupom"><s data-ll-tabela>{v_tabela}</s> <b data-ll-cupom>{v_cupom}</b> com tudo incluso<span data-ll-restam hidden></span></p>
        <p class="ll-final-preco ll-se-tabela"><b data-ll-tabela>{v_tabela}</b> à vista, com a taxa de inscrição</p>
        {cta("final", "ll-btn ll-btn-branco ll-btn-grande")}
        {proximas("ll-btn ll-btn-branco ll-btn-grande")}
      </div>
    </section>'''
    rodape = f'''
  <footer class="ll-rodape">
    <div class="ll-wrap">
      <a class="ll-marca" href="{URL_MATRICULA}" data-saida-ll="rodape">{marca}</a>
      <ul class="ll-rodape-links">
        <li><a href="{URL_MATRICULA}">Todos os cursos da Escola</a></li>
        <li><a href="{URL_KIT_ESCOLAS}">Lei Lucas para escolas</a></li>
        <li><a href="{SUSTENTA_URL}">Como a escola sustenta a filial</a></li>
        <li><a href="/">Site da Cruz Vermelha RJ</a></li>
        <li><a href="mailto:{EMAIL_CONTATO}">{EMAIL_CONTATO}</a></li>
      </ul>
      <p>© {agora.year} Escola de Educação e Saúde, empresa ligada à Cruz Vermelha Brasileira – Filial do Estado do Rio de Janeiro · CNPJ da filial <span class="ll-junto">08.560.973/0001-97</span> · Praça da Cruz Vermelha, 10, Centro, Rio de Janeiro – RJ</p>
      <p>Quem vende os cursos: {RECEBEDOR_NOME} · CNPJ <span class="ll-junto">{RECEBEDOR_CNPJ}</span></p>
      <p class="ll-rodape-legal">
        <a href="/privacidade/">Política de Privacidade</a>
        <a href="/termos/">Termos de Uso</a>
        <a href="/cookies/">Cookies</a>
        <a href="/reembolso/">Cancelamento e reembolso</a>
        <a href="/cookies/#preferencias" data-cvrj-cookies>Preferências de cookies</a>
      </p>
    </div>
  </footer>'''
    barra = f'''
  <div class="ll-barra" id="ll-barra" role="region" aria-label="Inscrição no curso" hidden>
    <div class="ll-barra-texto">
      <span class="ll-barra-rotulo"><span class="ll-se-cupom">Com tudo incluso</span><span class="ll-se-tabela">Total à vista</span></span>
      <b class="ll-barra-valor"><span class="ll-se-cupom" data-ll-cupom>{v_cupom}</span><span class="ll-se-tabela" data-ll-tabela>{v_tabela}</span></b>
    </div>
    {cta("barra", "ll-btn ll-btn-vermelho", curto=True)}
  </div>'''

    # --- dados estruturados --------------------------------------------------------------------------------------
    provedor = {"@type": "EducationalOrganization", "@id": f"{ESCOLA}/#escola", "name": "Escola de Educação e Saúde CVB-RJ",
                "legalName": RECEBEDOR_NOME, "taxID": RECEBEDOR_CNPJ, "url": f"{ESCOLA}/", "address": ENDERECO}
    instancia = {"@type": "CourseInstance", "courseMode": "Onsite", "startDate": turma.inicio.isoformat(), "endDate": turma.fim.isoformat(),
                 "location": LOCAL}
    if horas_iso(carga):
        instancia["courseWorkload"] = horas_iso(carga)
    ld = [
        {"@context": "https://schema.org", "@type": "BreadcrumbList", "itemListElement": [
            {"@type": "ListItem", "position": 1, "name": "Início", "item": f"{ORIGEM}/"},
            {"@type": "ListItem", "position": 2, "name": "Cursos presenciais", "item": f"{ORIGEM}{URL_MATRICULA}"},
            {"@type": "ListItem", "position": 3, "name": NOME_CURTO, "item": URL_PAGINA}]},
        {"@context": "https://schema.org", "@type": "Course", "name": curso["nome"], "description": descricao, "url": URL_PAGINA,
         "provider": provedor, "educationalCredentialAwarded": "Certificado da Escola de Educação e Saúde",
         "image": f"{ORIGEM}{FOTO_TOPO['src']}", "timeRequired": horas_iso(carga),
         # O preço de tabela, que vale sempre; a promoção do cupom tem prazo e número de vagas, e fica só no texto.
         "offers": [{"@type": "Offer", "category": "Paid", "name": "Matrícula à vista + taxa de inscrição", "price": f"{tabela / 100:.2f}",
                     "priceCurrency": "BRL", "url": URL_PAGINA, "availability": "https://schema.org/InStock",
                     "validThrough": turma.inscricoes_ate.isoformat(), "seller": {"@id": provedor["@id"]}}],
         "hasCourseInstance": [instancia]},
    ]
    for d in ld:
        assert "</" not in json.dumps(d, ensure_ascii=False)
    ld_html = "".join(f'\n  <script type="application/ld+json">\n{json.dumps(d, ensure_ascii=False, indent=2)}\n  </script>' for d in ld)

    # --- estado antes da primeira pintura --------------------------------------------------------------------------
    estado_js = ("(function(){var d=document.documentElement;d.className+=(d.className?' ':'')+'js';try{"
                 f"var a=Date.now(),T={int(turma.inscricoes_ate.timestamp() * 1000)},C={int(cupom_ate.timestamp() * 1000)};"
                 "d.setAttribute('data-oferta',a>T?'encerrada':a>C?'tabela':'cupom');}catch(e){}})();")
    ga4 = partes["ga4"].replace("gtag('config', 'G-", "gtag('set', { content_group: 'escola' });\n    gtag('config', 'G-", 1)
    assert "content_group: 'escola'" in ga4, "não achei o gtag('config') da home para inserir o content_group"
    js = (JS.replace("__SLUG__", json.dumps(SLUG)).replace("__CUPOM__", json.dumps(CUPOM)).replace("__NOME__", json.dumps(curso["nome"], ensure_ascii=False))
          .replace("__API_INFO__", json.dumps(API_INFO)).replace("__LISTA__", json.dumps(LISTA_GA4, ensure_ascii=False))
          .replace("__INICIO__", json.dumps(turma.inicio.isoformat())).replace("__DIA__", json.dumps(turma.inicio.date().isoformat()))
          .replace("__TABELA__", str(tabela)).replace("__TOTAL_CUPOM__", str(cupom["total"])).replace("__MATRICULA__", str(matricula)))
    css = CSS.replace("@@FONTES@@", fontes_da_home(home))
    robots = "" if INDEXAR else '\n  <meta name="robots" content="noindex, follow">'

    pagina = f"""<!DOCTYPE html>
<html lang="pt-BR" data-oferta="cupom">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{esc(TITULO)}</title>
  <meta name="description" content="{esc(descricao)}">
  <link rel="canonical" href="{URL_PAGINA}">{robots}
  <link rel="icon" href="/favicon.ico">
  <meta name="theme-color" content="#d9081f">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Cruz Vermelha Brasileira Rio de Janeiro">
  <meta property="og:locale" content="pt_BR">
  <meta property="og:title" content="{esc(OG_TITULO)}">
  <meta property="og:description" content="{esc(descricao)}">
  <meta property="og:url" content="{URL_PAGINA}">
  <meta property="og:image" content="{IMAGEM_OG}">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="Primeiros Socorros Lei Lucas: curso presencial da Escola de Educação e Saúde, no Centro do Rio">
  <meta name="twitter:card" content="summary_large_image">
  <script>{estado_js}</script>
  <link rel="preload" as="image" href="{FOTO_TOPO["src"]}" imagesrcset="{FOTO_TOPO["srcset"]}" imagesizes="(min-width: 980px) 60vw, 100vw">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400..900&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400..900&display=swap"></noscript>
{css}
{ga4}
{partes["pixel"]}
{ld_html}
</head>
<body class="ll">
  <a class="ll-pular" href="#inscricao">Pular para turma e valores</a>
{cabecalho}
  <main id="conteudo-principal">
{hero}
{para_quem}
{conteudo}
{lei}
{como}
{investimento}
{certificado}
{chegar}
{duvidas}
{final}
  </main>
{rodape}
{barra}
{js}
{chat_widget.tags()}
</body>
</html>
"""

    # --- travas (as mesmas da página de matrícula) -----------------------------------------------------------------
    achados = [m for m in MARCADORES_PROIBIDOS if m in pagina]
    if achados:
        print(f"recusado: a página tem marcadores sem dado real ({', '.join(achados)})")
        return 1
    if pagina.count("<h1") != 1:
        print("recusado: a página precisa de exatamente um <h1>")
        return 1
    corpo = pagina[pagina.index("<body"):]
    so_texto = html.unescape(re.sub(r"<[^>]+>", " ", re.sub(r"<script.*?</script>", " ", corpo, flags=re.S)))
    proibidas = []
    for padrao in PALAVRAS_PROIBIDAS + [r"parcelad", r"vagas limitadas", r"certificado internacional", r"últimas vagas"]:
        m = re.search(padrao, so_texto, re.I)
        if m:
            proibidas.append(f"{padrao!r} em “…{so_texto[max(0, m.start() - 50):m.end() + 30].strip()}…”")
    if proibidas:
        print("recusado: a página tem palavras proibidas (PALAVRAS_PROIBIDAS):\n  " + "\n  ".join(proibidas))
        return 1
    sozinha = NACIONAL_SOZINHA.search(re.sub(r"\s+", " ", so_texto))
    if sozinha:
        trecho = re.sub(r"\s+", " ", so_texto)[max(0, sozinha.start() - 60):sozinha.end() + 40]
        print(f"recusado: “Cruz Vermelha Brasileira” sem “Rio de Janeiro” em “…{trecho}…”")
        return 1
    pagina = re.sub(r"R\$ (?=\d)", "R$ ", pagina)
    SAIDA.parent.mkdir(parents=True, exist_ok=True)
    SAIDA.write_text(pagina, encoding="utf-8")
    print(f"gravado {SAIDA.relative_to(RAIZ)} ({len(pagina.encode('utf-8'))} bytes): turma de {turma.ddmm}, {CUPOM} a {brl(cupom['total'])} "
          f"até {prazo_cupom}, tabela {brl(tabela)}, inscrições até {prazo_turma}")
    return 0


TAG_SVG = '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>'

# --- CSS: o mesmo desenho da página de matrícula (vermelho, preto, Inter), com o prefixo ll- ------------------------
CSS = """
  <style>
    @@FONTES@@
    .ll{
      --red:#d9081f; --red-2:#b80016; --ink:#141414; --titulo:#111111; --texto:#4a4a4a; --muted:#6b6b6b;
      --bg:#f7f6f3; --card:#ffffff; --line:#e4e1dc; --chip:#f4f2ef; --contorno:#d6d1c9;
      --green-bg:#ecf8f1; --green-ink:#1d6b48; --aviso-bg:#fff4f4; --aviso-borda:#f6c9cd; --aviso-ink:#7a1b24;
      --escuro:#111111;
      --fonte:"Inter","Inter Reserva","Inter Reserva Android",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
      --gut:16px; --wrap:calc(100% - 32px); --cab:68px; --barra:76px;
    }
    @media (min-width:651px){ .ll{ --gut:24px; --wrap:min(1160px, 100% - 48px); --cab:76px; } }
    *,*::before,*::after{ box-sizing:border-box; }
    html{ scroll-behavior:smooth; scroll-padding-top:calc(var(--cab, 76px) + 12px); -webkit-text-size-adjust:100%; }
    body.ll{ margin:0; background:var(--bg); color:var(--ink); font-family:var(--fonte); font-size:17px; line-height:1.55; -webkit-font-smoothing:antialiased; overflow-wrap:break-word; overflow-x:hidden; }
    /* :where() deixa a base sem peso: qualquer classe ll- adiante ganha dela. */
    :where(.ll) img{ max-width:100%; height:auto; display:block; }
    :where(.ll) :where(h1,h2,h3,p,ul,ol,dl,dd,figure){ margin:0; padding:0; }
    :where(.ll) :where(ul,ol){ list-style:none; }
    :where(.ll) svg{ width:20px; height:20px; flex:none; }
    .ll-wrap{ width:var(--wrap); margin:0 auto; }
    .ll-estreito{ max-width:820px; }
    .ll-sr{ position:absolute!important; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0 0 0 0); clip-path:inset(50%); white-space:nowrap; border:0; }
    .ll-junto{ white-space:nowrap; }
    .ll-pular{ position:absolute; left:16px; top:-80px; z-index:200; background:#fff; color:var(--ink); padding:12px 18px; border-radius:12px; font-weight:800; text-decoration:none; box-shadow:0 25px 70px rgba(0,0,0,.2); }
    .ll-pular:focus{ top:12px; }
    .ll :focus-visible{ outline:3px solid #1a73e8; outline-offset:3px; border-radius:6px; }

    /* estados da oferta: cupom, tabela, encerrada (data-oferta no <html>) */
    html[data-oferta="cupom"] .ll-se-tabela, html[data-oferta="cupom"] .ll-se-encerrada,
    html[data-oferta="tabela"] .ll-se-cupom, html[data-oferta="tabela"] .ll-se-encerrada,
    html[data-oferta="encerrada"] .ll-se-cupom, html[data-oferta="encerrada"] .ll-se-tabela,
    html[data-oferta="encerrada"] .ll-se-aberta{ display:none!important; }
    .ll [hidden]{ display:none!important; }

    /* botões */
    .ll-btn{ display:inline-flex; align-items:center; justify-content:center; gap:10px; min-height:48px; padding:14px 22px; border-radius:999px; font:800 16px/1.2 var(--fonte); text-decoration:none; border:1.5px solid transparent; cursor:pointer; text-align:center; background:none; color:var(--ink); }
    .ll-btn svg{ width:18px; height:18px; }
    .ll-btn-grande{ min-height:56px; padding:16px 28px; font-size:17px; }
    .ll-btn-largo{ width:100%; }
    .ll-btn-vermelho{ background:var(--red); border-color:var(--red); color:#fff; }
    .ll-btn-vermelho:hover{ background:var(--red-2); border-color:var(--red-2); }
    .ll-btn-branco{ background:#fff; border-color:#fff; color:var(--ink); }
    .ll-btn-branco:hover{ background:var(--bg); }
    .ll-btn-preto{ background:#151515; border-color:#151515; color:#fff; }
    .ll-btn-preto:hover{ background:#000; }
    .ll-btn-contorno{ background:#fff; border-color:var(--contorno); color:var(--ink); }
    .ll-btn-contorno:hover{ border-color:var(--ink); }
    @media (prefers-reduced-motion:no-preference){ .ll-btn{ transition:background-color .15s, border-color .15s, color .15s; } }
    .ll-link{ color:var(--red); font-weight:700; text-decoration:underline; text-underline-offset:3px; }
    .ll-link:hover{ color:var(--red-2); }
    .ll-kicker{ font-size:12px; font-weight:900; letter-spacing:.12em; text-transform:uppercase; color:var(--red); line-height:1.3; }

    /* cabeçalho */
    .ll-cab{ position:sticky; top:0; z-index:60; background:#fff; border-bottom:1px solid var(--line); }
    .ll-cab-barra{ display:flex; align-items:center; justify-content:space-between; gap:16px; min-height:var(--cab); }
    .ll-marca{ display:inline-flex; align-items:center; gap:14px; text-decoration:none; color:var(--ink); min-height:44px; }
    .ll-marca img{ width:auto; height:30px; }
    .ll-marca-escola{ display:flex; flex-direction:column; padding-left:14px; border-left:1px solid var(--line); line-height:1.15; font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
    .ll-marca-escola b{ font-weight:900; color:var(--titulo); }
    .ll-marca-escola span{ font-weight:800; color:var(--red); font-size:10.5px; letter-spacing:.08em; }
    .ll-cab-acoes{ display:flex; align-items:center; gap:10px; }
    .ll-cab .ll-btn{ min-height:44px; padding:10px 18px; font-size:15px; }
    @media (max-width:650px){ .ll-cab-cta{ display:none; } .ll-cab-duvidas{ padding:10px 14px; } .ll-marca img{ height:26px; } .ll-marca{ gap:10px; } .ll-marca-escola{ padding-left:10px; font-size:11px; } .ll-marca-escola span{ font-size:9.5px; } }
    @media (max-width:359px){ .ll-marca-escola{ display:none; } }

    /* topo */
    .ll-hero{ position:relative; background:var(--escuro); color:#fff; isolation:isolate; overflow:hidden; }
    .ll-hero-foto{ position:absolute; left:0; top:0; width:100%; height:460px; object-fit:cover; object-position:center 35%; z-index:-2; }
    .ll-hero::after{ content:""; position:absolute; inset:0; z-index:-1; background:linear-gradient(180deg, rgba(17,17,17,.42) 0, rgba(17,17,17,.74) 190px, #111 460px); }
    .ll-hero-grade{ display:grid; gap:24px; padding:28px 0 32px; }
    .ll-olho{ font-size:12px; font-weight:800; letter-spacing:.1em; text-transform:uppercase; color:rgba(255,255,255,.86); display:flex; flex-wrap:wrap; align-items:center; gap:8px 10px; }
    .ll-olho span{ background:var(--red); color:#fff; padding:5px 10px; border-radius:6px; letter-spacing:.14em; }
    .ll-hero h1{ font-size:clamp(30px, 8.4vw, 54px); line-height:1.06; letter-spacing:-.035em; font-weight:800; margin-top:14px; text-wrap:balance; }
    .ll-hero-lead{ margin-top:14px; font-size:17px; line-height:1.55; color:rgba(255,255,255,.9); max-width:56ch; }
    .ll-chips{ display:flex; flex-wrap:wrap; gap:8px; margin-top:18px; }
    @media (max-width:650px){ .ll-chips{ display:none; } }
    .ll-chips li{ font-size:13.5px; font-weight:700; padding:7px 12px; border-radius:999px; border:1px solid rgba(255,255,255,.34); background:rgba(255,255,255,.08); color:#fff; }
    .ll-cartao{ background:#fff; color:var(--ink); border-radius:24px; padding:22px 20px; box-shadow:0 25px 70px rgba(0,0,0,.28); display:grid; gap:14px; align-content:start; }
    .ll-turma{ display:flex; align-items:center; gap:14px; }
    .ll-data{ background:var(--red); color:#fff; border-radius:18px; padding:10px 12px 11px; min-width:84px; text-align:center; display:flex; flex-direction:column; align-items:center; line-height:1; flex:none; }
    .ll-data b{ font-size:30px; font-weight:800; margin:0 0 4px; letter-spacing:-.03em; }
    .ll-data span{ font-size:10.5px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; }
    .ll-turma-dia{ font-size:19px; font-weight:800; letter-spacing:-.02em; line-height:1.2; color:var(--titulo); }
    .ll-turma-hora{ font-size:15px; color:var(--texto); margin-top:3px; }
    .ll-dias{ display:inline-flex; margin-top:6px; background:var(--green-bg); color:var(--green-ink); font-size:13px; font-weight:800; padding:4px 10px; border-radius:999px; }
    .ll-preco{ border-top:1px solid var(--line); padding-top:14px; }
    .ll-preco-de{ font-size:15px; font-weight:700; color:var(--muted); }
    .ll-preco-de s{ color:var(--texto); text-decoration-thickness:2px; text-decoration-color:var(--red); }
    .ll-preco-por{ display:flex; flex-wrap:wrap; align-items:baseline; gap:4px 10px; margin-top:2px; }
    .ll-preco-por b{ font-size:46px; font-weight:800; letter-spacing:-.045em; line-height:1; color:var(--titulo); }
    .ll-preco-por span{ font-size:16px; font-weight:800; color:var(--green-ink); }
    .ll-preco-fim{ font-size:26px; font-weight:800; letter-spacing:-.03em; line-height:1.15; color:var(--titulo); margin-top:2px; }
    .ll-preco-nota{ font-size:14.5px; color:var(--texto); margin-top:6px; }
    .ll-selo{ display:flex; gap:10px; align-items:flex-start; border:1.5px dashed var(--red); background:var(--aviso-bg); color:var(--aviso-ink); border-radius:16px; padding:11px 14px; font-size:14.5px; line-height:1.45; font-weight:600; }
    .ll-selo svg{ color:var(--red); margin-top:1px; }
    .ll-selo b{ font-weight:900; letter-spacing:.02em; }
    .ll-micro{ font-size:14px; color:var(--muted); text-align:center; }
    .ll-micro > .ll-link:only-child{ display:inline-flex; align-items:center; min-height:44px; font-size:16px; }
    .ll-confianca{ background:var(--escuro); color:#fff; padding:0 0 28px; }
    .ll-confianca ul{ display:grid; gap:0; border:1px solid rgba(255,255,255,.16); border-radius:18px; padding:4px 16px; }
    .ll-confianca li{ display:flex; align-items:center; gap:12px; padding:13px 0; font-size:15.5px; font-weight:700; }
    .ll-confianca li + li{ border-top:1px solid rgba(255,255,255,.14); }
    .ll-confianca svg{ color:#ff5a6b; }

    /* seções */
    .ll-secao{ padding:52px 0; }
    .ll-secao-clara{ background:#fff; border-top:1px solid var(--line); border-bottom:1px solid var(--line); }
    .ll-secao h2,.ll-final h2{ font-size:clamp(27px, 6.6vw, 40px); line-height:1.1; letter-spacing:-.035em; font-weight:800; color:var(--titulo); margin-top:8px; text-wrap:balance; }
    .ll-sub{ margin-top:12px; color:var(--texto); max-width:60ch; }
    .ll-prosa{ margin-top:14px; color:var(--texto); max-width:62ch; }
    .ll-prosa b{ color:var(--titulo); }
    .ll-acao{ margin-top:20px; }
    .ll-mapa img{ aspect-ratio:4 / 3; object-fit:cover; }
    .ll-h3{ font-size:22px; letter-spacing:-.025em; font-weight:800; margin-top:36px; color:var(--titulo); }
    .ll-centro{ margin-top:28px; display:flex; justify-content:center; }
    .ll-duas{ display:grid; gap:28px; }
    .ll-publico{ display:grid; gap:12px; margin-top:24px; }
    .ll-publico li{ background:#fff; border:1px solid var(--line); border-radius:20px; padding:18px; display:grid; grid-template-columns:auto 1fr; column-gap:14px; row-gap:2px; align-items:center; }
    .ll-ico{ grid-row:span 2; align-self:start; width:46px; height:46px; border-radius:14px; background:var(--aviso-bg); color:var(--red); display:inline-flex; align-items:center; justify-content:center; }
    .ll-publico h3{ font-size:18px; font-weight:800; letter-spacing:-.02em; color:var(--titulo); }
    .ll-publico p{ grid-column:2; font-size:15.5px; color:var(--texto); line-height:1.5; }
    .ll-aprender{ display:grid; gap:10px; margin-top:24px; }
    .ll-aprender li{ display:flex; align-items:center; gap:12px; background:var(--bg); border:1px solid var(--line); border-radius:16px; padding:14px 16px; font-size:17px; font-weight:700; color:var(--titulo); }
    .ll-aprender svg{ color:var(--red); width:22px; height:22px; }
    .ll-fotos{ display:flex; gap:12px; margin:16px calc(var(--gut) * -1) 0; padding:0 var(--gut) 6px; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; scrollbar-width:none; }
    .ll-fotos::-webkit-scrollbar{ display:none; }
    .ll-fotos li{ flex:0 0 68%; max-width:300px; scroll-snap-align:center; }
    .ll-fotos img{ width:100%; aspect-ratio:3 / 4; object-fit:cover; border-radius:20px; background:var(--chip); }
    .ll-fotos figcaption,.ll-cert figcaption,.ll-mapa figcaption{ font-size:13.5px; color:var(--muted); margin-top:8px; line-height:1.45; }
    .ll-lista{ display:grid; gap:10px; margin-top:18px; }
    .ll-lista li{ display:flex; gap:12px; align-items:flex-start; color:var(--ink); font-weight:600; }
    .ll-lista svg{ color:var(--red); margin-top:3px; }
    .ll-caixa{ background:var(--escuro); color:#fff; border-radius:24px; padding:24px 22px; align-self:start; }
    .ll-caixa h3{ font-size:22px; line-height:1.2; letter-spacing:-.025em; font-weight:800; }
    .ll-caixa p{ margin-top:12px; color:rgba(255,255,255,.86); font-size:16px; }
    .ll-caixa-acoes{ display:grid; gap:14px; justify-items:start; margin-top:18px!important; }
    .ll-caixa .ll-btn-preto{ background:#fff; border-color:#fff; color:var(--ink); }
    .ll-caixa .ll-link{ color:#fff; }
    .ll-passos{ display:grid; gap:12px; margin-top:24px; counter-reset:passo; }
    .ll-passos li{ background:var(--bg); border:1px solid var(--line); border-radius:20px; padding:18px; }
    .ll-passos b{ font-size:13px; font-weight:900; letter-spacing:.1em; color:var(--red); }
    .ll-passos h3{ font-size:19px; font-weight:800; letter-spacing:-.02em; margin-top:4px; color:var(--titulo); }
    .ll-passos p{ margin-top:6px; color:var(--texto); font-size:16px; }
    .ll-sustenta{ margin-top:18px; font-size:14.5px; color:var(--muted); max-width:62ch; }
    .ll-inv{ background:#fff; border:1px solid var(--line); border-radius:24px; padding:22px 20px; box-shadow:0 16px 50px rgba(0,0,0,.05); display:grid; gap:14px; align-self:start; }
    .ll-inv dl{ display:grid; }
    .ll-inv dl > div{ display:flex; justify-content:space-between; align-items:baseline; gap:16px; padding:12px 0; border-bottom:1px solid var(--line); }
    .ll-inv dt{ color:var(--texto); font-size:15.5px; }
    .ll-inv dd{ font-weight:800; white-space:nowrap; color:var(--titulo); }
    .ll-inv dd s{ color:var(--muted); font-weight:700; }
    .ll-verde{ color:var(--green-ink)!important; }
    .ll-inv .ll-inv-total{ border-bottom:0; padding-bottom:0; }
    .ll-inv-total dt{ font-weight:800; color:var(--titulo); }
    .ll-inv-total dd{ font-size:30px; letter-spacing:-.04em; }
    .ll-aviso{ display:flex; gap:10px; align-items:flex-start; background:var(--aviso-bg); border:1px solid var(--aviso-borda); color:var(--aviso-ink); border-radius:16px; padding:12px 14px; font-size:14px; line-height:1.5; }
    .ll-aviso svg{ color:var(--red); margin-top:1px; }
    .ll-cert img,.ll-mapa img{ width:100%; border-radius:18px; border:1px solid var(--line); background:var(--chip); }
    .ll-faq{ margin-top:22px; display:grid; gap:10px; }
    .ll-faq details{ background:var(--bg); border:1px solid var(--line); border-radius:16px; }
    .ll-faq summary{ cursor:pointer; list-style:none; display:flex; justify-content:space-between; align-items:center; gap:14px; min-height:56px; padding:14px 18px; font-weight:800; font-size:17px; line-height:1.3; color:var(--titulo); }
    .ll-faq summary::-webkit-details-marker{ display:none; }
    .ll-faq summary::after{ content:"+"; flex:none; font-size:24px; font-weight:500; line-height:1; color:var(--red); }
    .ll-faq details[open] summary::after{ content:"–"; }
    .ll-faq details > div{ padding:0 18px 16px; color:var(--texto); display:grid; gap:10px; font-size:16.5px; }
    .ll-faq + .ll-sub{ margin-top:20px; }
    .ll-final{ background:var(--red); color:#fff; padding:56px 0; text-align:center; }
    .ll-final .ll-wrap{ display:grid; gap:14px; justify-items:center; }
    .ll-final h2{ color:#fff; margin-top:0; }
    .ll-final p{ color:rgba(255,255,255,.94); max-width:48ch; }
    .ll-final-preco{ font-size:19px; font-weight:700; }
    .ll-final-preco b{ font-size:30px; font-weight:800; letter-spacing:-.03em; color:#fff; }
    .ll-final-preco s{ opacity:.8; }
    .ll-rodape{ background:var(--escuro); color:rgba(255,255,255,.78); padding:36px 0 calc(36px + var(--barra)); font-size:14px; }
    .ll-rodape .ll-wrap{ display:grid; gap:14px; }
    .ll-rodape .ll-marca{ color:#fff; justify-self:start; background:#fff; padding:8px 14px; border-radius:14px; }
    .ll-rodape .ll-marca-escola{ display:flex; }
    .ll-rodape-links{ display:flex; flex-wrap:wrap; gap:4px 20px; }
    .ll-rodape-links a,.ll-rodape-legal a{ display:inline-flex; align-items:center; min-height:44px; color:#fff; font-weight:700; text-decoration:underline; text-underline-offset:3px; }
    .ll-rodape-legal{ display:flex; flex-wrap:wrap; gap:0 18px; border-top:1px solid rgba(255,255,255,.16); padding-top:8px; }
    .ll-rodape-legal a{ font-weight:600; color:rgba(255,255,255,.86); }

    /* barra fixa do celular */
    .ll-barra{ position:fixed; left:0; right:0; bottom:var(--cvrj-ck-altura, 0px); z-index:70; background:#fff; border-top:1px solid var(--line); box-shadow:0 -10px 30px rgba(0,0,0,.08); min-height:calc(var(--barra) + env(safe-area-inset-bottom)); padding:10px 16px calc(10px + env(safe-area-inset-bottom)); display:flex; align-items:center; justify-content:space-between; gap:12px; }
    .ll-barra-texto{ display:flex; flex-direction:column; min-width:0; line-height:1.2; }
    .ll-barra-rotulo{ font-size:11.5px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); }
    .ll-barra-valor{ font-size:24px; font-weight:800; letter-spacing:-.03em; color:var(--titulo); }
    .ll-barra .ll-btn{ flex:none; min-height:52px; padding:12px 22px; }
    @media (prefers-reduced-motion:no-preference){ .ll-barra{ animation:ll-sobe .25s ease-out; } @keyframes ll-sobe{ from{ transform:translateY(100%); } to{ transform:none; } } }

    @media (min-width:651px){
      .ll-secao{ padding:72px 0; }
      .ll-hero-grade{ padding:48px 0 56px; }
      .ll-confianca{ padding-bottom:40px; }
      .ll-confianca ul{ grid-template-columns:repeat(3, 1fr); padding:6px 20px; }
      .ll-confianca li + li{ border-top:0; border-left:1px solid rgba(255,255,255,.14); padding-left:20px; }
      .ll-publico{ grid-template-columns:repeat(2, 1fr); gap:16px; }
      .ll-aprender{ grid-template-columns:repeat(2, 1fr); }
      .ll-passos{ grid-template-columns:repeat(3, 1fr); gap:16px; }
      .ll-fotos li{ flex-basis:36%; }
      .ll-cartao,.ll-inv{ padding:28px 26px; }
    }
    @media (min-width:980px){
      .ll-barra{ display:none!important; }
      .ll-rodape{ padding-bottom:36px; }
      .ll-hero-foto{ height:100%; }
      .ll-hero::after{ background:linear-gradient(90deg, #111 0%, rgba(17,17,17,.9) 38%, rgba(17,17,17,.5) 100%); }
      .ll-hero-grade{ grid-template-columns:minmax(0, 1.15fr) minmax(360px, .85fr); gap:56px; align-items:center; padding:72px 0 80px; }
      .ll-hero-lead{ font-size:19px; }
      .ll-duas{ grid-template-columns:minmax(0, 1.1fr) minmax(0, .9fr); gap:56px; align-items:center; }
      .ll-duas-inv{ align-items:start; }
      .ll-publico{ grid-template-columns:repeat(4, 1fr); }
      .ll-publico li{ grid-template-columns:1fr; row-gap:8px; align-content:start; align-items:start; }
      .ll-publico p{ grid-column:1; }
      .ll-ico{ grid-row:auto; }
      .ll-aprender{ grid-template-columns:repeat(4, 1fr); }
      .ll-fotos{ margin:20px 0 0; padding:0; overflow:visible; display:grid; grid-template-columns:repeat(4, 1fr); gap:16px; }
      .ll-fotos li{ max-width:none; }
    }
  </style>"""

# --- JS: estado da oferta pelo info.php, cupons que restam, barra fixa e medição -------------------------------------
JS = """
  <script>
    (function () {
      var SLUG = __SLUG__, CUPOM = __CUPOM__, NOME = __NOME__, API_INFO = __API_INFO__, LISTA = __LISTA__;
      var INICIO = __INICIO__, DIA = __DIA__, TABELA = __TABELA__, TOTAL_CUPOM = __TOTAL_CUPOM__, MATRICULA = __MATRICULA__;
      var raiz = document.documentElement;
      function todos(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }
      function ga(evento, dados) { try { if (window.gtag) window.gtag('event', evento, dados || {}); } catch (e) {} }
      function estado() { return raiz.getAttribute('data-oferta') || 'cupom'; }
      function reais(centavos, longo) {
        var redondo = centavos % 100 === 0 && !longo;
        return 'R$\\u00a0' + (centavos / 100).toLocaleString('pt-BR', { minimumFractionDigits: redondo ? 0 : 2, maximumFractionDigits: redondo ? 0 : 2 });
      }
      function valor() { return (estado() === 'cupom' ? TOTAL_CUPOM : TABELA) / 100; }
      function item() {
        var i = { item_id: SLUG, item_name: NOME, item_category: 'Cursos presenciais', item_category2: estado() === 'encerrada' ? 'breve' : 'aberta', price: valor(), quantity: 1 };
        if (estado() === 'cupom') i.coupon = CUPOM;
        return i;
      }

      // "Começa em N dias", pela data de Brasília (como na página de matrícula).
      try {
        var hoje;
        try { hoje = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Sao_Paulo', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date()); }
        catch (e) { hoje = new Date(Date.now() - 108e5).toISOString().slice(0, 10); }
        var h = hoje.split('-'), i = DIA.split('-');
        var n = Math.round((Date.UTC(+i[0], i[1] - 1, +i[2]) - Date.UTC(+h[0], h[1] - 1, +h[2])) / 864e5);
        if (n >= 0) todos('[data-ll-dias]').forEach(function (el) { el.textContent = n === 0 ? 'Começa hoje' : n === 1 ? 'Começa em 1 dia' : 'Começa em ' + n + ' dias'; el.hidden = false; });
      } catch (e) {}

      // UTMs, fbclid, gclid, gbraid e wbraid com que a pessoa chegou seguem para o checkout (os links já funcionam sem JS).
      var extras = new URLSearchParams();
      new URLSearchParams(location.search).forEach(function (v, k) { if (/^(utm_|fbclid$|gclid$|gbraid$|wbraid$)/.test(k)) extras.set(k, v); });
      function arrumarLinks() {
        todos('a.ll-cta').forEach(function (a) {
          try {
            var u = new URL(a.getAttribute('href'), location.origin);
            extras.forEach(function (v, k) { u.searchParams.set(k, v); });
            if (estado() === 'cupom') u.searchParams.set('cupom', CUPOM); else u.searchParams.delete('cupom');
            a.setAttribute('href', u.pathname + u.search);
          } catch (e) {}
        });
      }
      arrumarLinks();

      // O info.php corrige o estado: turma aberta, cupom valendo, preço e cupons que restam (contados no servidor).
      var pronto = false, fila = [];
      function infoFim() { if (pronto) return; pronto = true; fila.forEach(function (f) { f(); }); fila = []; }
      function quandoInfo(f) { if (pronto) f(); else fila.push(f); }
      function aplicarInfo(d) {
        var c = null, o = null;
        (d.cursos || []).forEach(function (x) { if (x && x.slug === SLUG) c = x; });
        if (!c) return;
        var planos = Array.isArray(c.planos) ? c.planos : [];
        var turmaOk = !!c.turma && c.turma.inicio === INICIO && c.turma.lotada !== true && planos.indexOf('taxa_e_matricula') >= 0;
        if (!turmaOk) { raiz.setAttribute('data-oferta', 'encerrada'); return; }
        if (+c.total_centavos > 0) TABELA = +c.total_centavos;
        (Array.isArray(d.cupons_vitrine) ? d.cupons_vitrine : []).forEach(function (x) {
          if (!o && x && x.codigo === CUPOM && Array.isArray(x.cursos) && x.cursos.indexOf(SLUG) >= 0 && (x.limite === null || +x.restantes > 0) && +x.total_centavos > 0 && +x.total_centavos < TABELA) o = x;
        });
        todos('[data-ll-tabela]').forEach(function (el) { el.textContent = reais(TABELA); });
        todos('[data-ll-tabela-longo]').forEach(function (el) { el.textContent = reais(TABELA, true); });
        if (!o) { raiz.setAttribute('data-oferta', 'tabela'); return; }
        TOTAL_CUPOM = +o.total_centavos;
        raiz.setAttribute('data-oferta', 'cupom');
        todos('[data-ll-cupom]').forEach(function (el) { el.textContent = reais(TOTAL_CUPOM); });
        todos('[data-ll-cupom-longo]').forEach(function (el) { el.textContent = reais(TOTAL_CUPOM, true); });
        todos('[data-ll-economia]').forEach(function (el) { el.textContent = '\\u2212 ' + reais(TABELA - TOTAL_CUPOM, true); });
        if (o.limite !== null && +o.restantes > 0) {
          var texto = +o.restantes === 1 ? 'resta 1 cupom' : 'restam ' + (+o.restantes) + ' cupons';
          todos('[data-ll-restam]').forEach(function (el) { el.textContent = ' · ' + texto; el.hidden = false; });
        }
      }
      setTimeout(infoFim, 2500);
      try {
        fetch(API_INFO, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.ok ? r.json() : null; })['catch'](function () { return null; })
          .then(function (d) { try { if (d && d.ok && Array.isArray(d.cursos)) aplicarInfo(d); } catch (e) {} arrumarLinks(); infoFim(); avaliarBarra(); });
      } catch (e) { infoFim(); }

      // Barra fixa do celular: aparece depois do cartão do topo e some no convite final e no rodapé.
      var barra = document.getElementById('ll-barra'), cartao = document.getElementById('inscricao'), fim = document.getElementById('final');
      var passou = false, noFim = false;
      function avaliarBarra() {
        if (!barra) return;
        var mostrar = passou && !noFim && estado() !== 'encerrada';
        barra.hidden = !mostrar;
        // O botão do chat sobe para não ficar em cima da barra (variável do /chat/chat.css).
        raiz.style.setProperty('--cv-chat-acima', mostrar && barra.offsetHeight ? barra.offsetHeight + 'px' : '0px');
      }
      if (barra && cartao && 'IntersectionObserver' in window) {
        new IntersectionObserver(function (es) { es.forEach(function (e) { passou = !e.isIntersecting && e.boundingClientRect.top < 0; }); avaliarBarra(); }, { threshold: 0 }).observe(cartao);
        if (fim) new IntersectionObserver(function (es) { es.forEach(function (e) { noFim = e.isIntersecting || e.boundingClientRect.top < 0; }); avaliarBarra(); }, { threshold: 0 }).observe(fim);
      }

      // Medição: o curso visto (view_item / ViewContent, com o repasse à API de Conversões) e os cliques.
      quandoInfo(function () {
        var id = '', v = valor(), plano = 'taxa_e_matricula';
        try { if (window.cvrjMedicao && window.cvrjMedicao.novoId) id = window.cvrjMedicao.novoId('vc'); } catch (e) {}
        var dados = { content_name: NOME, content_ids: [SLUG], content_type: 'product', content_category: 'matricula-cursos-presenciais', value: v, currency: 'BRL',
                      estado_turma: item().item_category2, plano_padrao: plano, valor_matricula: MATRICULA / 100, total_a_vista: TABELA / 100 };
        try { if (window.fbq) window.fbq('track', 'ViewContent', dados, id ? { eventID: id } : undefined); } catch (e) {}
        try { if (id) window.cvrjMedicao.servidor('ViewContent', id, SLUG); } catch (e) {}
        ga('view_item', { currency: 'BRL', value: v, item_list_name: LISTA, origem: 'pagina_lei_lucas', oferta: estado(), estado_turma: item().item_category2, plano_padrao: plano, valor_matricula: MATRICULA / 100, total_a_vista: TABELA / 100, items: [item()] });
        ga('view_course_details', { curso: SLUG, origem: 'pagina_lei_lucas', modo: 'curso' });
      });
      document.addEventListener('click', function (e) {
        var alvo = e.target && e.target.closest ? e.target : null;
        if (!alvo) return;
        var cta = alvo.closest('a.ll-cta');
        if (cta) {
          var local = cta.getAttribute('data-local') || '';
          ga('select_item', { currency: 'BRL', value: valor(), item_list_name: LISTA, local: local, modo: 'curso', oferta: estado(), estado_turma: 'aberta', plano_padrao: 'taxa_e_matricula', total_a_vista: TABELA / 100, items: [item()] });
          ga('click_enroll', { curso: SLUG, local: local, modo: 'curso', oferta: estado(), estado_turma: 'aberta', plano_padrao: 'taxa_e_matricula', total_a_vista: TABELA / 100 });
        }
        var atalho = alvo.closest('[data-abrir-chat][data-local]');
        if (atalho) ga('chat_atalho', { local: atalho.getAttribute('data-local'), curso: SLUG });
        var turma = alvo.closest('[data-ll-turma]');
        if (turma) ga('contact_company_training', { local: turma.getAttribute('data-local') || '', curso: SLUG });
        var mapa = alvo.closest('[data-ll-mapa]');
        if (mapa) ga('como_chegar', { local: 'pagina_lei_lucas', curso: SLUG });
      });
      // Seções vistas (uma vez cada), para saber até onde as pessoas leem.
      if ('IntersectionObserver' in window) {
        var vistas = {};
        var obs = new IntersectionObserver(function (es) {
          es.forEach(function (e) {
            var s = e.target.getAttribute('data-secao');
            if (e.isIntersecting && s && !vistas[s]) { vistas[s] = 1; ga('secao_vista', { secao: s, pagina: 'lei_lucas' }); obs.unobserve(e.target); }
          });
        }, { threshold: 0.35 });
        todos('[data-secao]').forEach(function (el) { obs.observe(el); });
      }
    })();
  </script>"""


if __name__ == "__main__":
    raise SystemExit(main())
