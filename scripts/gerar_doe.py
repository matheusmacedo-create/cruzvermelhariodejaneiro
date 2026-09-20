#!/usr/bin/env python3
"""Gera a página de doação em site/doe/index.html.

Substitui o subdomínio doar.cruzvermelhariodejaneiro.org (app separado na Vercel): a doação passa
a acontecer dentro do domínio principal, em /doe/, no mesmo padrão visual da home (cabeçalho,
rodapé, CSS, GA4, Meta Pixel e chat) e com o pagamento pela Unicopag no backend /doe/api/.

Inspiração de fluxo: a página da Cruz Vermelha de São Paulo (paybox.doare.org) — um cartão só,
com valor, dados e pagamento em dois passos, sem sair da página.

O CSS e o JS próprios ficam em site/doe/static/ (doe.css e doe.js); a página os referencia com um
hash do conteúdo na query string, para o navegador buscar a versão nova a cada mudança.

Uso:  python3 scripts/gerar_doe.py     (depois, publicar site/index.html e site/doe/)
"""
from __future__ import annotations

import hashlib
import json
import re

import chat_widget
import icones
from gerar_matricula_presencial import HOME, RAIZ, esc, partes_da_home

PASTA = RAIZ / "site" / "doe"
STATIC = PASTA / "static"
STATIC_URL = "/doe/static/"
ORIGEM = "https://cruzvermelhariodejaneiro.org"
URL_PAGINA = f"{ORIGEM}/doe/"
WIKI = "https://pt.wikipedia.org/wiki/Cruz_Vermelha_Brasileira_-_Rio_de_Janeiro"

TITULO = "Doar para a Cruz Vermelha Brasileira Rio de Janeiro"
H1 = "Doe para a Cruz Vermelha Brasileira Rio de Janeiro"
DESCRICAO = ("Doe para a Cruz Vermelha Brasileira Rio de Janeiro por PIX ou cartão, em menos de um minuto. "
             "Sua doação mantém a formação de voluntários e as ações no estado.")

# Referências de impacto: valores sugeridos com o que cada um sustenta. Não são pacotes fechados,
# e a página diz isso — a doação entra no caixa da filial, não numa cesta específica.
IMPACTO = [
    ("R$ 30", "Material de primeiros socorros", "Insumos das aulas práticas: ataduras, luvas e material de treino que passam pelas mãos de cada turma."),
    ("R$ 60", "Educação preventiva", "Orientação à população em ações comunitárias: o que fazer antes de o socorro chegar."),
    ("R$ 100", "Voluntariado preparado", "Formação inicial e capacitação continuada de quem veste o colete na rua."),
    ("R$ 250", "Ação comunitária", "Campanhas como a do Agasalho e o Impacto das Cores, que chegam a quem mais precisa."),
]

DADOS = [
    ("Razão social", "Cruz Vermelha Brasileira<br>Filial do Estado do Rio de Janeiro", ""),
    ("CNPJ", "08.560.973/0001-97", "É o CNPJ do comprovante que enviamos por e-mail."),
    ("Utilidade pública", "Municipal e estadual", "Lei municipal 5.153/2010 e lei estadual 9.984/2023."),
    ("Sede", "Praça da Cruz Vermelha, 10", "Palácio da Cruz Vermelha, Centro do Rio de Janeiro."),
]

PASSOS = [
    ("Escolha quanto doar", "Um dos valores sugeridos ou o que você quiser, a partir de R$ 5."),
    ("Informe seus dados", "Nome, e-mail, CPF e telefone. O CPF é exigido pelo meio de pagamento."),
    ("Pague por PIX ou cartão", "A confirmação aparece na tela e o comprovante chega no seu e-mail."),
]

FAQ = [
    ("Para onde vai o dinheiro da minha doação?",
     "Sua doação fica na Cruz Vermelha Brasileira Rio de Janeiro, a filial do Estado do Rio de Janeiro, e sustenta a "
     "formação de voluntários, a capacitação em primeiros socorros, a educação preventiva, as campanhas de apoio "
     "comunitário e a estrutura que mantém tudo isso de pé, na sede do Centro do Rio. Os valores sugeridos na página "
     "são referências de impacto, não pacotes fechados: a filial aplica os recursos onde a necessidade é maior a cada mês.",
     []),
    ("A doação é segura? Quem processa o pagamento?",
     "Sim. A página fica no domínio oficial da filial, com conexão criptografada. O pagamento é processado pela "
     "Unicopag, instituição de pagamento autorizada, que recebe a doação e repassa o valor à Cruz Vermelha Brasileira "
     "Rio de Janeiro; por isso é o nome dela que aparece no PIX e na fatura do cartão, como acontece com as outras "
     "filiais da Cruz Vermelha e os meios de pagamento que usam. Seus dados de cartão passam direto para o processador "
     "e não são gravados no nosso servidor. Ficam conosco apenas nome, e-mail, CPF e telefone, para emitir a cobrança "
     "e enviar o comprovante.",
     []),
    ("Posso doar por PIX? E por cartão?",
     "Pode pelos dois. No PIX, a página gera o QR code e o código copia e cola na hora, e a confirmação aparece "
     "sozinha assim que o banco processa, sem você precisar avisar ninguém. No cartão de crédito, a doação é "
     "aprovada em segundos. Se fechar a página antes de pagar o PIX, o código também vai para o seu e-mail e vale "
     "por 24 horas.",
     []),
    ("Recebo algum comprovante da doação?",
     "Sim. Assim que o pagamento é confirmado, você recebe um e-mail com o valor, a forma de pagamento, a data, o "
     "número de protocolo e o CNPJ da filial. Guarde esse e-mail: ele é o comprovante da sua doação. Se não chegar "
     "em alguns minutos, confira a caixa de spam ou fale com a gente pelo chat do site.",
     [("chat do site", "/#chat")]),
    ("Posso doar todo mês?",
     "Em breve, sim, nesta mesma página: por PIX Automático ou por cartão, você autoriza uma vez e a cobrança se repete "
     "todo mês, com aviso antes de cada uma e cancelamento quando quiser. Enquanto a opção não aparece no formulário, "
     "dá para repetir a doação avulsa em menos de um minuto ou combinar a mensalidade com a equipe pelo chat do site.",
     [("chat do site", "/#chat")]),
    ("Quero doar roupas e cobertores. Como faço?",
     "Doações de roupas de frio, cobertores e calçados são recebidas na sede da Cruz Vermelha Brasileira Rio de "
     "Janeiro, na Praça da Cruz Vermelha, 10, Centro, pela Campanha do Agasalho, de segunda a sexta, das 10h às 17h. "
     "Leve as peças limpas e em bom estado. Para outros itens, confirme antes com a equipe pelo chat do site.",
     [("Campanha do Agasalho", "/campanha-agasalho.html"), ("chat do site", "/#chat")]),
    ("Posso doar sem que meu nome apareça?",
     "Pode. Marque a opção \"Quero doar anonimamente\" antes de concluir e seu nome não é usado em agradecimentos "
     "públicos, em redes sociais nem em listas de doadores. Nome, CPF, e-mail e telefone continuam sendo pedidos "
     "porque o meio de pagamento exige esses dados para emitir a cobrança e para enviar o seu comprovante, mas eles "
     "ficam restritos à equipe que cuida das doações.",
     []),
    ("Minha empresa quer apoiar a Cruz Vermelha Brasileira Rio de Janeiro.",
     "Empresas apoiam a filial com doações institucionais, patrocínio de campanhas e treinamentos corporativos de NR "
     "e de primeiros socorros pela Lei Lucas. Fale com a gente pelo chat do site ou pelo e-mail "
     "contato@cruzvermelhariodejaneiro.org para receber uma proposta.",
     [("chat do site", "/#chat")]),
]

PAGINA = """<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <title>@@TITULO@@</title>
  <meta name="description" content="@@DESCRICAO@@">
  <meta name="robots" content="index, follow, max-image-preview:large">
  <link rel="canonical" href="@@URL@@">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Cruz Vermelha Brasileira Rio de Janeiro">
  <meta property="og:locale" content="pt_BR">
  <meta property="og:url" content="@@URL@@">
  <meta property="og:title" content="@@TITULO@@">
  <meta property="og:description" content="@@DESCRICAO@@">
  <meta property="og:image" content="@@ORIGEM@@/assets/otim/og-home.jpg">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="@@TITULO@@">
  <meta name="twitter:description" content="@@DESCRICAO@@">
  <meta name="twitter:image" content="@@ORIGEM@@/assets/otim/og-home.jpg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"></noscript>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>
  <!-- Estilos copiados da home (site/index.html): mesmo padrão visual da filial. -->
@@ESTILO@@
  <link rel="stylesheet" href="@@CSS_URL@@">
  <script src="@@JS_URL@@" defer></script>
  <script type="application/ld+json">
@@LD@@
  </script>
@@GA4@@
@@PIXEL@@
</head>
<body class="doe">
  <script>document.documentElement.classList.add('js');</script>
@@HEADER@@

  <main id="doe">
    <section class="doe-hero">
      <div class="wrap doe-grid">
        <div>
          <p class="eyebrow">Doação</p>
          <h1>@@H1@@</h1>
          <p class="lead">@@LEAD@@</p>
          <ul class="doe-confianca">
@@CONFIANCA@@
          </ul>
        </div>
@@CARTAO@@
      </div>
    </section>

    <section>
      <div class="wrap">
        <p class="eyebrow">Para onde vai sua doação</p>
        <h2>Cada valor vira preparo, presença e resposta.</h2>
        <p class="lead">Os valores abaixo são referências de impacto, não pacotes fechados: a filial aplica os recursos onde a necessidade é maior a cada mês.</p>
        <div class="doe-impacto">
@@IMPACTO@@
        </div>
      </div>
    </section>

    <section style="background:var(--soft)">
      <div class="wrap">
        <p class="eyebrow">Transparência</p>
        <h2>Você sabe exatamente quem está apoiando.</h2>
        <p class="lead">A Cruz Vermelha Brasileira Rio de Janeiro é a filial do Estado do Rio de Janeiro da sociedade nacional da Cruz Vermelha, com sede própria no Centro do Rio. A história da filial e do edifício está no <a href="@@WIKI@@" target="_blank" rel="noopener">verbete da Wikipédia</a>.</p>
        <div class="doe-dados">
@@DADOS@@
        </div>
      </div>
    </section>

    <section>
      <div class="wrap">
        <p class="eyebrow">Como funciona</p>
        <h2>Três passos, menos de um minuto.</h2>
        <div class="doe-impacto">
@@PASSOS@@
        </div>
        <div class="cta-row" style="margin-top:30px">
          <a class="btn btn-red" href="#doe-card">Escolher o valor da doação</a>
          <a class="btn btn-outline" href="/campanha-agasalho.html">Doar roupas e cobertores</a>
        </div>
      </div>
    </section>

    <section class="faq-section" id="faq" style="background:var(--soft)">
      <div class="wrap">
        <p class="eyebrow">Perguntas frequentes</p>
        <h2>Antes de doar</h2>
        <div class="faq-list">
@@FAQ@@
        </div>
      </div>
    </section>
  </main>

@@FOOTER@@

@@MENU_JS@@
@@CHAT@@
</body>
</html>
"""

CARTAO = """        <aside class="doe-card" id="doe-card">
          <p class="doe-teste" id="doe-teste" hidden>Modo de teste: o valor cobrado não é o valor escolhido.</p>
          <div class="doe-passos" aria-hidden="true">
            <span class="doe-passo ativo"><i>1</i> Valor</span>
            <span class="doe-traco"></span>
            <span class="doe-passo"><i>2</i> Dados</span>
            <span class="doe-traco"></span>
            <span class="doe-passo"><i>3</i> Pagamento</span>
          </div>
          <form id="doe-form" novalidate>
            <div id="doe-valor">
              <div class="doe-bloco" id="doe-frequencia" hidden>
                <span class="doe-rotulo">Frequência</span>
                <div class="doe-opcoes">
                  <button class="doe-op doe-freq ativo" type="button" data-freq="unica">Doação única<small>Contribua uma vez</small></button>
                  <button class="doe-op doe-freq" type="button" data-freq="mensal">Mensal<small>Apoie todo mês</small></button>
                </div>
              </div>
              <div class="doe-bloco">
                <span class="doe-rotulo">Valor da doação</span>
                <div class="doe-valores">
@@VALORES@@
                </div>
                <label class="doe-outro">
                  <span>R$</span>
                  <input id="doe-outro" type="text" inputmode="numeric" autocomplete="off" placeholder="Outro valor" aria-label="Outro valor em reais">
                </label>
              </div>
              <button class="btn btn-red doe-acao" type="button" id="doe-continuar" style="margin-top:20px">Continuar</button>
              <p class="doe-aviso">Conexão segura. O comprovante chega no seu e-mail assim que o pagamento é confirmado.</p>
            </div>

            <div id="doe-dados" hidden>
              <div class="doe-bloco">
                <span class="doe-rotulo">Seus dados</span>
                <label class="doe-campo"><span>Nome completo</span><input id="doe-nome" type="text" autocomplete="name" enterkeyhint="next"></label>
                <label class="doe-campo"><span>E-mail</span><input id="doe-email" type="email" autocomplete="email" inputmode="email" enterkeyhint="next"></label>
                <div class="doe-dupla">
                  <label class="doe-campo"><span>CPF</span><input id="doe-cpf" type="text" inputmode="numeric" autocomplete="off" placeholder="000.000.000-00"></label>
                  <label class="doe-campo"><span>Telefone</span><input id="doe-telefone" type="tel" inputmode="tel" autocomplete="tel" placeholder="(21) 99999-9999"></label>
                </div>
              </div>
              <div class="doe-bloco">
                <span class="doe-rotulo">Forma de pagamento</span>
                <div class="doe-opcoes">
                  <button class="doe-op doe-metodo ativo" type="button" data-metodo="pix"><i class="fa-brands fa-pix" aria-hidden="true"></i> PIX<small>Confirmação na hora</small></button>
                  <button class="doe-op doe-metodo" type="button" data-metodo="cartao"><i class="fa-solid fa-credit-card" aria-hidden="true"></i> Cartão<small>Crédito, à vista</small></button>
                </div>
                <div class="doe-cartao-grid" id="doe-cartao" hidden style="margin-top:14px">
                  <label class="doe-campo largo"><span>Número do cartão</span><input id="doe-cartao_numero" type="text" inputmode="numeric" autocomplete="cc-number" placeholder="0000 0000 0000 0000" disabled></label>
                  <label class="doe-campo largo"><span>Nome como está no cartão</span><input id="doe-cartao_nome" type="text" autocomplete="cc-name" disabled></label>
                  <label class="doe-campo"><span>Validade</span><input id="doe-cartao_validade" type="text" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/AA" disabled></label>
                  <label class="doe-campo"><span>Código de segurança</span><input id="doe-cartao_cvv" type="text" inputmode="numeric" autocomplete="cc-csc" placeholder="000" disabled></label>
                </div>
                <label class="doe-caixa" id="doe-cobre-caixa">
                  <input type="checkbox" id="doe-cobre">
                  <span>Quero cobrir os <b>custos de processamento</b> (<span id="doe-taxa-valor">R$ 0,00</span>) para a filial receber o valor cheio.</span>
                </label>
                <label class="doe-caixa" id="doe-anonimo-caixa">
                  <input type="checkbox" id="doe-anonimo">
                  <span>Quero <b>doar anonimamente</b>: meu nome não aparece em agradecimentos públicos nem em listas de doadores.</span>
                </label>
                <label class="doe-caixa" id="doe-aceite-caixa">
                  <input type="checkbox" id="doe-aceite">
                  <span>Li e concordo com a <a href="/privacidade" target="_blank" rel="noopener">Política de Privacidade</a> e autorizo o uso dos meus dados para processar a doação.</span>
                </label>
              </div>
              <div class="doe-resumo"><span>Total da doação</span><b class="doe-total">R$ 0,00</b></div>
              <div class="doe-armadilha" aria-hidden="true"><label>Não preencha<input id="doe-site" type="text" tabindex="-1" autocomplete="off"></label></div>
              <button class="btn btn-red doe-acao" type="submit" id="doe-enviar">Doar</button>
              <button class="doe-voltar" type="button">Voltar e mudar o valor</button>
              <p class="doe-erro" id="doe-erro" role="alert"></p>
            </div>
          </form>
          <div id="doe-pix" hidden></div>
          <div id="doe-obrigado" hidden></div>
          <noscript><p class="doe-aviso">Esta página precisa de JavaScript para gerar o pagamento. Ative o JavaScript ou escreva para contato@cruzvermelhariodejaneiro.org.</p></noscript>
        </aside>"""

CONFIANCA = [
    ("scale-balanced", "<b>Utilidade pública municipal e estadual</b>: lei municipal 5.153/2010 e lei estadual 9.984/2023."),
    ("certificate", "<b>CNPJ 08.560.973/0001-97</b>, Cruz Vermelha Brasileira · Filial do Estado do Rio de Janeiro."),
    ("lock", "<b>Pagamento em ambiente seguro</b>, com comprovante por e-mail logo após a confirmação."),
    ("hand-holding-heart", "<b>Acesso futuro a cursos gravados gratuitos</b> para quem doa pelo site."),
]


def hash_arquivo(nome: str) -> str:
    return hashlib.sha256((STATIC / nome).read_bytes()).hexdigest()[:10]


def url_estatico(nome: str) -> str:
    return f"{STATIC_URL}{nome}?v={hash_arquivo(nome)}"


def menu_com_doe(home: str) -> str:
    """Acrescenta o link da doação ao menu da home (uma vez). Todas as páginas herdam o cabeçalho."""
    if 'href="/doe/"' in home:
        return home
    alvo = '          <a href="#contato">Contato</a>\n'
    if alvo not in home:
        raise SystemExit("não encontrei o item Contato no menu da home")
    return home.replace(alvo, alvo + '          <a href="/doe/">Doe</a>\n', 1)


def bloco_faq() -> tuple[str, list[dict]]:
    itens, entidades = [], []
    for i, (pergunta, resposta, links) in enumerate(FAQ):
        texto = esc(resposta)
        for rotulo, url in links:
            alvo = esc(rotulo)
            if alvo not in texto:
                raise SystemExit(f"trecho do link não está na resposta: {rotulo!r}")
            texto = texto.replace(alvo, f'<a href="{esc(url)}">{alvo}</a>', 1)
        aberta = " open" if i == 0 else ""
        itens.append(f'          <details class="faq-item"{aberta}>\n            <summary>{esc(pergunta)}</summary>\n'
                     f'            <div class="faq-answer">{texto}</div>\n          </details>')
        entidades.append({"@type": "Question", "name": pergunta,
                          "acceptedAnswer": {"@type": "Answer", "text": resposta}})
    return "\n".join(itens), entidades


OBRIGADO = """<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <title>Sua doação · Cruz Vermelha Brasileira Rio de Janeiro</title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"></noscript>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>
  <!-- Estilos copiados da home (site/index.html): mesmo padrão visual da filial. -->
@@ESTILO@@
  <link rel="stylesheet" href="@@CSS_URL@@">
  <script src="@@JS_URL@@" defer></script>
@@GA4@@
@@PIXEL@@
</head>
<body class="doe" data-tela="obrigado">
  <script>document.documentElement.classList.add('js');</script>
@@HEADER@@

  <main id="doe">
    <section class="doe-hero">
      <div class="wrap" style="max-width:640px">
        <aside class="doe-card" style="position:static">
          <div id="doe-valor" hidden></div>
          <div id="doe-dados" hidden></div>
          <div id="doe-pix"><p class="doe-aviso">Carregando sua doação…</p></div>
          <div id="doe-obrigado" hidden></div>
          <noscript><p class="doe-aviso">Esta página precisa de JavaScript para mostrar sua doação. Confira seu e-mail: o comprovante e o código PIX foram enviados para lá.</p></noscript>
        </aside>
        <p class="doe-aviso" style="margin-top:20px">Dúvidas sobre sua doação? Escreva para <a href="mailto:contato@cruzvermelhariodejaneiro.org">contato@cruzvermelhariodejaneiro.org</a> ou use o chat no canto da página.</p>
      </div>
    </section>
  </main>

@@FOOTER@@

@@MENU_JS@@
@@CHAT@@
</body>
</html>
"""


def main() -> int:
    home = HOME.read_text(encoding="utf-8")
    home_nova = menu_com_doe(home)
    if home_nova != home:
        HOME.write_text(home_nova, encoding="utf-8")
        print(f"atualizado {HOME.relative_to(RAIZ)} (link Doe no menu)")
        home = home_nova

    partes = partes_da_home(home)
    # A página de doação não é a de matrícula: o aria-current volta para o item certo.
    header = partes["header"].replace(' aria-current="page"', "")
    header = header.replace('<a href="/doe/">Doe</a>', '<a href="/doe/" aria-current="page">Doe</a>', 1)

    valores = "\n".join(
        f'                  <button class="doe-op doe-valor" type="button" data-valor="{centavos}">R$ {centavos // 100}</button>'
        for centavos in (3000, 6000, 10000, 15000, 25000, 50000))
    confianca = "\n".join(
        f'            <li><i class="fa-solid fa-{icone}" aria-hidden="true"></i><span>{texto}</span></li>'
        for icone, texto in CONFIANCA)
    impacto = "\n".join(
        f'          <article class="doe-item"><b>{esc(valor)}</b><h3>{esc(titulo)}</h3><p>{esc(texto)}</p></article>'
        for valor, titulo, texto in IMPACTO)
    dados = "\n".join(
        f'          <div class="doe-dado"><span>{esc(rotulo)}</span><b>{valor}</b>'
        + (f"<p>{esc(nota)}</p>" if nota else "") + "</div>"
        for rotulo, valor, nota in DADOS)
    passos = "\n".join(
        f'          <article class="doe-item"><b>{i}</b><h3>{esc(titulo)}</h3><p>{esc(texto)}</p></article>'
        for i, (titulo, texto) in enumerate(PASSOS, 1))
    faq_html, faq_entidades = bloco_faq()

    ld = {
        "@context": "https://schema.org",
        "@graph": [
            {"@type": "WebPage", "@id": f"{URL_PAGINA}#pagina", "url": URL_PAGINA, "name": TITULO,
             "description": DESCRICAO, "inLanguage": "pt-BR",
             "isPartOf": {"@id": f"{ORIGEM}/#site"}, "about": {"@id": f"{ORIGEM}/#organizacao"},
             "primaryImageOfPage": f"{ORIGEM}/assets/otim/og-home.jpg"},
            {"@type": "BreadcrumbList", "@id": f"{URL_PAGINA}#trilha", "itemListElement": [
                {"@type": "ListItem", "position": 1, "name": "Início", "item": f"{ORIGEM}/"},
                {"@type": "ListItem", "position": 2, "name": "Doar", "item": URL_PAGINA}]},
            {"@type": "DonateAction", "@id": f"{URL_PAGINA}#doar", "name": TITULO,
             "description": DESCRICAO, "recipient": {"@id": f"{ORIGEM}/#organizacao"},
             "target": {"@type": "EntryPoint", "urlTemplate": URL_PAGINA,
                        "actionPlatform": ["https://schema.org/DesktopWebPlatform", "https://schema.org/MobileWebPlatform"]}},
            {"@type": "FAQPage", "@id": f"{URL_PAGINA}#faq", "url": f"{URL_PAGINA}#faq",
             "name": "Perguntas sobre doação", "inLanguage": "pt-BR",
             "isPartOf": {"@id": f"{ORIGEM}/#site"}, "mainEntity": faq_entidades},
        ],
    }
    ld_json = json.dumps(ld, ensure_ascii=False, indent=2)
    assert "</" not in ld_json, "JSON-LD não pode conter </"

    html = (PAGINA
            .replace("@@TITULO@@", esc(TITULO)).replace("@@DESCRICAO@@", esc(DESCRICAO))
            .replace("@@URL@@", URL_PAGINA).replace("@@ORIGEM@@", ORIGEM).replace("@@WIKI@@", WIKI)
            .replace("@@ESTILO@@", partes["estilo"]).replace("@@LD@@", ld_json)
            .replace("@@CSS_URL@@", url_estatico("doe.css")).replace("@@JS_URL@@", url_estatico("doe.js"))
            .replace("@@GA4@@", partes["ga4"]).replace("@@PIXEL@@", partes["pixel"])
            .replace("@@HEADER@@", header).replace("@@FOOTER@@", partes["footer"]).replace("@@MENU_JS@@", partes["menu_js"])
            .replace("@@CHAT@@", chat_widget.tags())
            .replace("@@H1@@", esc(H1)).replace("@@LEAD@@", "Em menos de um minuto, por PIX ou cartão. Sua doação sustenta a formação de voluntários, a capacitação em primeiros socorros e as ações humanitárias no estado do Rio.")
            .replace("@@CARTAO@@", CARTAO.replace("@@VALORES@@", valores))
            .replace("@@CONFIANCA@@", confianca).replace("@@IMPACTO@@", impacto)
            .replace("@@DADOS@@", dados).replace("@@PASSOS@@", passos).replace("@@FAQ@@", faq_html))

    sobrou = re.findall(r"@@[A-Z_]+@@", html)
    if sobrou:
        raise SystemExit(f"marcadores não substituídos: {sorted(set(sobrou))}")
    html = icones.converter(html, extras={"circle-check"})  # circle-check vem do doe.js
    destino = PASTA / "index.html"
    destino.parent.mkdir(parents=True, exist_ok=True)
    destino.write_text(html, encoding="utf-8")
    print(f"gravado {destino.relative_to(RAIZ)} ({len(html.encode('utf-8'))} bytes, {len(FAQ)} perguntas)")

    # Tela de acompanhamento e agradecimento, com endereço próprio (/doe/obrigado/?t=<token>):
    # é para onde o formulário leva e para onde os e-mails apontam. Fora do índice do Google.
    obrigado = (OBRIGADO
                .replace("@@ESTILO@@", partes["estilo"])
                .replace("@@CSS_URL@@", url_estatico("doe.css")).replace("@@JS_URL@@", url_estatico("doe.js"))
                .replace("@@GA4@@", partes["ga4"]).replace("@@PIXEL@@", partes["pixel"])
                .replace("@@HEADER@@", header).replace("@@FOOTER@@", partes["footer"])
                .replace("@@MENU_JS@@", partes["menu_js"]).replace("@@CHAT@@", chat_widget.tags()))
    sobrou = re.findall(r"@@[A-Z_]+@@", obrigado)
    if sobrou:
        raise SystemExit(f"marcadores não substituídos na tela de agradecimento: {sorted(set(sobrou))}")
    obrigado = icones.converter(obrigado, extras={"circle-check"})
    destino = PASTA / "obrigado" / "index.html"
    destino.parent.mkdir(parents=True, exist_ok=True)
    destino.write_text(obrigado, encoding="utf-8")
    print(f"gravado {destino.relative_to(RAIZ)} ({len(obrigado.encode('utf-8'))} bytes)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
