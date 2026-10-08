#!/usr/bin/env python3
"""Gera site/matricula-cursos-presenciais/index.html: os cursos presenciais no padrão da Escola de Educação e Saúde.

Fonte da copy e do design: a especificação final de 08/10/2026 (scratchpad/escola/spec-final.md, seções 1 a 4 e 7),
com PAGAMENTO = "A" e MATRICULA_PARCELADA = False. Pedido do dono: copy e design no padrão de
escola.cursoscruzvermelha.org, com a mesma estrutura, e o fim do "R$ 99" apresentado como preço do curso.

Um HTML estático só, com dois modos:
  - modo geral (sem parâmetro): faixa vermelha, cabeçalho da Escola, topo com foto e "Próxima turma em destaque",
    destaques, cursos em cards, como funciona, certificado, quem somos (sede, fotos, como chegar), dúvidas, turmas
    para grupos, chamada final e rodapé;
  - modo curso (?curso=<slug>, #det-<slug> ou #curso-<slug>, ou o clique num card, sem recarregar): topo do curso com
    o cartão "Próxima turma" (ou "Turmas em breve"), faixa de prova, Investimento, como funciona a inscrição, seu
    certificado, sobre o curso, turmas, dúvidas do curso, como chegar e "Outros cursos da Escola" (o próprio #cursos).
O script do <head> decide o modo antes da primeira pintura (html[data-curso]; não liga com ?turma=), marca as turmas
vencidas pelo relógio (html[data-breve], com as duas variantes no HTML) e calcula o "Começa em N dias" (só dias).
O HTML segue a ordem do celular; no computador a grade só leva o Investimento para a lateral fixa.

Os preços e as turmas vêm de site/matricula-cursos-presenciais/cursos.json (campo "turmas": a secretaria avisa e a TI
regera). O total à vista é calculado: valor_curso_centavos + inscricao_centavos. O gerador recusa a página se ela tiver
uma palavra de PALAVRAS_PROIBIDAS (preço, vaga, WhatsApp, voluntariado…), um marcador sem dado real, mais de um
<h1>, ou "reserv" numa variante "sem turma".

Continua igual: o bloco de medição e consentimento (GA4, Pixel, cvrjMedicao) copiado da home por partes_da_home(), o
chat "Fale com a gente" (chat_widget), o aviso de cookies, o dialog de turmas para grupos (ids tf-*/turma-ok*, ?turma=1,
static/turmas.js), o certificado de amostra, as fotos das aulas, o mapa estático com clique que carrega o iframe, os
links para o checkout com ?curso=<slug>&via=<lugar> (o script acrescenta as UTMs, fbclid e gclid) e os efeitos
colaterais: o seletor de cursos da home (só o bloco marcado) e a lista de cursos do chat.js. Outros geradores importam
daqui partes_da_home, esc, DADOS, HOME, RAIZ, COPY_CURSO e NOME_CURTO: os nomes continuam.

Uso:  python3 scripts/gerar_matricula_presencial.py
Depois: publicar site/matricula-cursos-presenciais/ (index.html + img/) com scripts/publicar_hostinger.sh.
Link de anúncio para turma de grupo: ?turma=1&turma_curso=<slug>&idioma=en&alunos=15 abre o formulário preenchido.
"""
from __future__ import annotations

import hashlib
import html
import json
import os
import re
import sys
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path

import chat_widget
import minificar_css

RAIZ = Path(__file__).resolve().parent.parent
HOME = RAIZ / "site" / "index.html"
DADOS = RAIZ / "site" / "matricula-cursos-presenciais" / "cursos.json"
FAQ_HOME = RAIZ / "site" / "faq-home.json"
SAIDA = RAIZ / "site" / "matricula-cursos-presenciais" / "index.html"
PASTA_IMG = RAIZ / "site" / "matricula-cursos-presenciais" / "img"

ORIGEM = "https://cruzvermelhariodejaneiro.org"
URL_PAGINA = f"{ORIGEM}/matricula-cursos-presenciais/"
ESCOLA = "https://escola.cursoscruzvermelha.org"
ESCOLA_LOGIN = f"{ESCOLA}/login"
CHECKOUT_URL = "/matricula-cursos-presenciais/checkout/"
STATIC = RAIZ / "site" / "matricula-cursos-presenciais" / "static"
STATIC_URL = "/matricula-cursos-presenciais/static/"
API_TURMAS = "/matricula-cursos-presenciais/api/turmas.php"

# Turmas sob demanda: as mesmas regras de api/lib/turmas.php (MCP_TURMA_MINIMO, MCP_TURMA_MAXIMO e
# MCP_TURMA_CURSOS_EXTRAS). Mudou lá, muda aqui; scripts/testar_checkout.php confere os dois.
TURMA_MINIMO = 15
TURMA_MAXIMO = 30
TURMA_EXTRAS = {"primeiros-socorros-jovens": "Primeiros Socorros para Jovens (12 a 14 anos)"}

# --- chaves da especificação (seção 2.4) ------------------------------------------------------------------------
# PAGAMENTO: "A" (só a taxa aqui; a matrícula na área do aluno), "B1", "B2" ou "C" (seção 5). Só a A está escrita:
# as outras dependem de mudanças no checkout e de decisões do dono (D11, D12), e o gerador recusa até lá.
PAGAMENTO = "A"
# Só vira True depois da compra de teste (4.7, item 12) e da D12, com parcelas, juros e total. Com False, nenhuma frase
# fala em parcelar a matrícula nem em PIX ou cartão para ela.
MATRICULA_PARCELADA = False

TITULO = "Cursos presenciais no Rio | Cruz Vermelha Brasileira RJ"
TITULO_CURSO = "{curso} | Cursos presenciais Cruz Vermelha RJ"
OG_TITULO = "Cursos presenciais da Cruz Vermelha no Rio de Janeiro"
# og-matricula.jpg conferida em 08/10/2026 (D13): foto de turma no salão, sem preço. Pode continuar.
IMAGEM_OG = f"{ORIGEM}/assets/otim/og-matricula.jpg"
IMAGEM_OG_TAMANHO = (1200, 630)
ENDERECO = {"@type": "PostalAddress", "streetAddress": "Praça da Cruz Vermelha, 10", "addressLocality": "Rio de Janeiro",
            "addressRegion": "RJ", "postalCode": "20230-130", "addressCountry": "BR"}
LOCAL = {"@type": "Place", "name": "Palácio da Cruz Vermelha", "address": ENDERECO}
CNPJ_FILIAL = "08.560.973/0001-97"
EMAIL_CONTATO = "contato@cruzvermelhariodejaneiro.org"
UTM_ESCOLA = "utm_source=cruzvermelhariodejaneiro&utm_medium=matricula&utm_content={local}"

# Texto literal de /reembolso/ (seção "Direito de arrependimento: 7 dias"). Fica como referência para quem importa;
# a página usa as frases curtas da especificação ("Desistiu em até 7 dias? Você recebe de volta tudo o que pagou.").
GARANTIA_7_DIAS = ("Você pode desistir da inscrição em até 7 dias corridos, contados do pagamento, sem precisar dizer o "
                   "motivo (art. 49 do CDC). O valor pago volta por inteiro, inclusive o custo de processamento, se você "
                   "tiver escolhido cobri-lo.")

# Ordem dos cards depois dos cursos com turma aberta (3.0). Curso novo fora da lista entra no fim, com aviso.
ORDEM_EXIBICAO = ["primeiros-socorros-basico", "suporte-basico-de-vida", "primeiros-socorros-lei-lucas",
                  "puncao-venosa", "bombeiro-civil", "cuidador-de-idosos", "micropigmentacao-labial"]
NOME_CURTO = {"primeiros-socorros-lei-lucas": "Primeiros Socorros Lei Lucas", "cuidador-de-idosos": "Cuidador de Idosos"}
# Linha de apoio de cada curso (tabela 3.0): só o que está na FAQ ou no "sobre" do cursos.json.
APOIO = {
    "primeiros-socorros-basico": "Para qualquer pessoa. Inclui o conteúdo da Lei Lucas.",
    "suporte-basico-de-vida": "Em 4 horas. Para profissionais e para qualquer pessoa.",
    "primeiros-socorros-lei-lucas": "Foco em crianças: engasgo, quedas e convulsões.",
    "puncao-venosa": "Para estudantes e profissionais da saúde.",
    "bombeiro-civil": "Homologação à parte, no fim do curso.",
    "cuidador-de-idosos": "Curso livre.",
    "micropigmentacao-labial": "Para iniciantes e para quem já trabalha com estética.",
}
# Descrição curta do card (uma linha por curso, só com o que está no cursos.json).
BENEFICIO = {
    "primeiros-socorros-basico": "Do engasgo à RCP: agir até o socorro chegar",
    "suporte-basico-de-vida": "RCP e desfibrilador em 4 horas, com prática",
    "primeiros-socorros-lei-lucas": "RCP, engasgo, quedas e convulsões em crianças",
    "puncao-venosa": "Acesso venoso seguro, com prática supervisionada",
    "bombeiro-civil": "Incêndio, primeiros socorros e evacuação em 80h",
    "cuidador-de-idosos": "Higiene, mobilização e prevenção de acidentes",
    "micropigmentacao-labial": "Colorimetria, biossegurança e prática da técnica",
}
# Cursos com homologação paga à parte (o total à vista vem "sem a homologação").
HOMOLOGACAO = {"bombeiro-civil"}
# Copy de cada curso (revisão de 04/10/2026, cada afirmação conferida contra o cursos.json e a faq-home.json). A
# "promessa" vai nos criativos (gerar_criativos_certificado.py); a "objecao" é a 4ª pergunta de "Sobre este curso."
# (3.3.8), sem frase de preço. No Bombeiro Civil a objeção é a pergunta da homologação, que a página já faz na 2ª.
COPY_CURSO = {
    "primeiros-socorros-basico": {
        "titulo": "Primeiros Socorros Básico na Cruz Vermelha: agir até o socorro chegar",
        "promessa": "Em 8 horas, com prática supervisionada, você aprende RCP, o uso do desfibrilador e o que fazer em engasgos, desmaios e hemorragias.",
        "objecao": ("Não sou da área da saúde e nunca fiz nada parecido. Posso fazer o curso?",
                    "Pode. O curso foi desenvolvido para qualquer pessoa interessada em aprender primeiros socorros, e a escolaridade "
                    "mínima é o Ensino Fundamental. As aulas são presenciais, na Praça da Cruz Vermelha, 10, no Centro do Rio, com "
                    "atividades demonstrativas e práticas supervisionadas: RCP, desengasgo e controle de hemorragias são praticados com "
                    "manequim e instrutor ao lado. O curso prepara para o atendimento inicial até a chegada do serviço especializado e não habilita para o "
                    "exercício profissional."),
    },
    "suporte-basico-de-vida": {
        "titulo": "Suporte Básico de Vida (BLS) na Cruz Vermelha: RCP e DEA em 4 horas",
        "promessa": "Aprenda a reconhecer uma emergência, fazer RCP e usar o desfibrilador até a chegada do socorro especializado, com teoria e prática.",
        "objecao": ("Nunca fiz nada na área da saúde. Posso fazer este curso?",
                    "Pode. O curso é aberto tanto a profissionais quanto a pessoas sem experiência prévia, e pede apenas Ensino "
                    "Fundamental. As 4 horas são presenciais, na Praça da Cruz Vermelha, 10, no Centro do Rio, e combinam teoria e "
                    "treinamento prático para desenvolver segurança durante os atendimentos: RCP, desengasgo e controle de hemorragias "
                    "pedem prática com manequim e instrutor ao lado. O curso prepara para o atendimento inicial até a chegada do socorro especializado e não "
                    "substitui habilitação profissional regulamentada."),
    },
    "primeiros-socorros-lei-lucas": {
        "titulo": "Primeiros Socorros Lei Lucas: o que fazer quando uma criança engasga",
        "promessa": "Em 8 horas presenciais, com teoria e prática, você aprende a agir em engasgos, quedas, convulsões, queimaduras e hemorragias em crianças, e a fazer RCP.",
        "objecao": ("O que é a Lei Lucas? Preciso ser da área da saúde para fazer o curso?",
                    "A Lei Lucas (Lei Federal 13.722/2018) obriga escolas de educação básica, públicas e privadas, e espaços de "
                    "recreação infantil a capacitar professores e funcionários em noções básicas de primeiros socorros. Para atender a "
                    "essa exigência, a Cruz Vermelha Brasileira Rio de Janeiro oferece este curso. Não é preciso ser da saúde: os cursos "
                    "de primeiros socorros são abertos a qualquer pessoa, e a escolaridade mínima é o Ensino Fundamental. São 8 horas "
                    "presenciais na Praça da Cruz Vermelha, 10, no Centro. É curso livre: "
                    "prepara para o atendimento inicial até a chegada do socorro especializado e não substitui habilitação profissional "
                    "regulamentada. A escola que quer treinar a equipe inteira pode pedir uma turma fechada, de 15 a 30 pessoas, em "
                    "“Solicitar uma turma”, nesta página."),
    },
    "puncao-venosa": {
        "titulo": "Punção Venosa na Cruz Vermelha: 8 horas com prática supervisionada",
        "promessa": "Acesso venoso com segurança e precisão: anatomia, materiais, preparo do paciente e prevenção de complicações.",
        "objecao": ("Em 8 horas dá para praticar de verdade, ou é só teoria?",
                    "Grande parte do aprendizado acontece em atividades práticas supervisionadas, na sede da Praça da Cruz Vermelha, 10, "
                    "no Centro do Rio. Além da técnica de punção venosa, o curso aborda biossegurança, prevenção de complicações, escolha "
                    "de dispositivos e boas práticas assistenciais."),
    },
    "bombeiro-civil": {
        "titulo": "Curso de Bombeiro Civil na Cruz Vermelha: 80 horas no Centro do Rio",
        "promessa": "Em 80 horas, aprenda a combater princípios de incêndio, prestar primeiros socorros e evacuar ambientes, com aulas práticas que simulam emergências reais.",
        "objecao": None,
    },
    "cuidador-de-idosos": {
        "titulo": "Cuidador de Idosos: aprenda a cuidar com segurança, na Cruz Vermelha",
        "promessa": "Em 160 horas presenciais, você aprende higiene, alimentação, mobilização, prevenção de acidentes e noções de primeiros socorros no cuidado da pessoa idosa.",
        "objecao": ("Que certificado eu recebo? Ele é reconhecido pelo MEC?",
                    "Quem conclui recebe o certificado de curso livre emitido pela Cruz Vermelha Brasileira Rio de Janeiro, com o nome do "
                    "curso e a carga horária de 160 horas. Ele não é reconhecido pelo MEC, e nenhum curso livre é: o MEC regula a "
                    "educação formal, como ensino técnico, graduação e pós. É um diferencial valorizado no currículo, mas não substitui "
                    "habilitação profissional regulamentada."),
    },
    "micropigmentacao-labial": {
        "titulo": "Aprenda micropigmentação labial na prática, na Cruz Vermelha",
        "promessa": "Em 24 horas presenciais, você aprende a implantar pigmentos, corrigir assimetrias visuais e uniformizar a cor dos lábios, com biossegurança.",
        "objecao": ("Nunca trabalhei com estética. O curso serve para mim?",
                    "Serve. O curso atende tanto iniciantes quanto profissionais que desejam ampliar seus serviços, e não exige "
                    "experiência prévia: a escolaridade mínima é o Ensino Médio. São 24 horas presenciais na sede, na Praça da Cruz "
                    "Vermelha, 10, no Centro do Rio, com abordagem prática para desenvolver segurança e qualidade na execução da técnica."),
    },
}
# Depoimentos (3.2.9): só entram com 2 ou mais, cada um com nome abreviado, curso, texto e autorização registrada (D8).
# Vazio = a seção não é impressa. Nunca inventar nem reaproveitar depoimento da escola sem autorização para o nosso site.
PROVA: list[dict] = []
MARCADORES_PROIBIDOS = ("[INSERIR", "[CONFIRMAR", "[DECIDIR")
# Trava de vocabulário (2.4), sem diferença de maiúsculas e minúsculas. Os padrões não pegam "Escola de Educação".
PALAVRAS_PROIBIDAS = [
    r"pag[oa] (depois, )?na escola", r"na escola,", r"pago depois", r"plataforma da escola",
    r"garant\w* (a |sua |minha )?vaga", r"vaga (fica |ficar )?garantida",
    r"Inscrição R\$", r"R\$\s?100\b", r"matricula você", r"matricular você", r"Fazer matrícula",
    r"o que vestir", r"Escolha sua turma", r"aprovação em segundos", r"sua área do aluno",
    r"WhatsApp", r"wa\.me", r"cruzvermelharj\.org\.br", r"seja voluntári", r"voluntariado",
] + ([] if MATRICULA_PARCELADA else [r"parcelad"])
# Frases do catálogo da escola que prometem emprego, renda ou mercado ficam fora da página e do chat.
EXCLUIR_CATALOGO = re.compile(r"emprego|oportunidade|mercado de trabalho|retorno financeiro|\brenda\b|iniciar seus atendimentos", re.I)
# Dúvidas do curso (3.3.8): a pergunta do catálogo que trata do mesmo tema da objeção de COPY_CURSO não entra (a
# objeção já responde; repetir fere o princípio 6). Ex.: "Não sou da área da saúde…" e "Preciso ser da área da saúde?".
TEMAS_FAQ = [re.compile(p, re.I) for p in (r"\bsaúde\b", r"Lei Lucas", r"pr[aá]tic", r"estética", r"certificad", r"\bMEC\b")]

# --- o certificado (modelo da filial, 04/10/2026; imagens de amostra de scripts/gerar_certificado_modelo.py) ------
CERT_PESO = ("O certificado leva o nome da Cruz Vermelha, reconhecida nacional e internacionalmente pela tradição em "
             "formação humanitária e em emergências.")
CERT_NOTA = "Imagem de modelo: o seu sai com o seu nome, o curso que você fez e a data de conclusão."
CERT_DESTAQUE = "primeiros-socorros-basico"  # o certificado de exemplo da seção do modo geral

# Fotos reais da sede e das aulas (servidas de /assets/otim, fora do Git; só o servidor as tem).
FOTO_TOPO = {"base": "aula-dea-sala", "larguras": [480, 591], "largura": 591, "altura": 1280, "pos": "center 47%",
             "alt": "Instrutor da Cruz Vermelha em sala de aula, com manequins e um DEA de treino sobre a mesa"}
# Acima de 650 px o topo usa a foto em paisagem do salão (1440 px): o retrato acima só tem 591 px e ficava ampliado de
# 2 a 3 vezes, borrado, no computador (revisão visual de 08/10). O alt do <picture> serve às duas fotos.
FOTO_TOPO_PC = {"base": "aula-salao-cruz", "larguras": [960, 1440], "largura": 1440, "altura": 810}
FOTO_TOPO_ALT = "Aula na sede da Cruz Vermelha Brasileira no Rio de Janeiro, com instrutor e alunos"
FOTOS_AULAS = [
    {"base": "aula-engasgo-bebe", "larguras": [480, 589], "largura": 480, "altura": 1043, "pos": "center 58%",
     "alt": "Alunos ajoelhados praticando a manobra de desengasgo em manequins de bebê durante a aula",
     "legenda": "Prática de desengasgo em bebê, com manequins"},
    {"base": "aula-dea-sala", "larguras": [480, 591], "largura": 480, "altura": 1040, "pos": "center 55%",
     "alt": "Instrutor da Cruz Vermelha em sala de aula, com manequins e um DEA de treino sobre a mesa",
     "legenda": "Aula com manequins e DEA de treino"},
    {"base": "aula-manobra-heimlich", "larguras": [480, 899], "largura": 480, "altura": 854, "pos": "center 45%",
     "alt": "Instrutor demonstrando a manobra de Heimlich em uma aluna durante a aula",
     "legenda": "Demonstração da manobra de Heimlich"},
    {"base": "aula-salao-cruz", "larguras": [480, 960, 1440], "largura": 960, "altura": 540, "pos": "center",
     "alt": "Turma sentada no salão da sede, com a cruz vermelha ao fundo e um manequim sobre a mesa",
     "legenda": "Turma no salão da sede"},
]
# Capa real de três cursos (as outras continuam com img/<slug>-*.webp, de gerar_imagens_matricula.py).
CAPA_CURSO = {
    "primeiros-socorros-basico": {"base": "aula-manobra-heimlich", "larguras": [480, 899], "largura": 480, "altura": 854,
                                  "pos": "center 24%", "alt": "Instrutor demonstrando a manobra de Heimlich em uma aluna durante a aula"},
    "primeiros-socorros-lei-lucas": {"base": "aula-engasgo-bebe", "larguras": [480, 589], "largura": 480, "altura": 1043,
                                     "pos": "center 60%", "alt": "Alunos praticando a manobra de desengasgo em manequins de bebê durante a aula"},
    "suporte-basico-de-vida": {"base": "aula-dea-sala", "larguras": [480, 591], "largura": 480, "altura": 1040,
                               "pos": "center 48%", "alt": "Instrutor da Cruz Vermelha em sala de aula, com manequins e um DEA de treino sobre a mesa"},
}
FOTO_FACHADA = {"src": "/assets/otim/historia-fachada-noturna-767.webp", "largura": 767, "altura": 516,
                "alt": "Fachada iluminada do Palácio da Cruz Vermelha, à noite"}
MAPA_ESTATICO = {"src": "/assets/otim/mapa-sede-960.webp",
                 "srcset": "/assets/otim/mapa-sede-480.webp 480w, /assets/otim/mapa-sede-960.webp 960w",
                 "alt": "Mapa do Centro do Rio com a sede da Cruz Vermelha marcada na Praça da Cruz Vermelha, 10, perto da Avenida Mem de Sá",
                 "credito": "Mapa © colaboradores do OpenStreetMap"}
MAPA_ROTA = "https://www.google.com/maps/dir/?api=1&amp;destination=Pra%C3%A7a+da+Cruz+Vermelha%2C+10%2C+Centro%2C+Rio+de+Janeiro+-+RJ%2C+20230-130"
MAPA_EMBED = "https://www.google.com/maps?q=Pra%C3%A7a+da+Cruz+Vermelha%2C+10%2C+Centro%2C+Rio+de+Janeiro+-+RJ&amp;output=embed"
LOGO = {"src": "/assets/otim/logo-cvb-rj-520.webp", "largura": 520, "altura": 156, "alt": "Cruz Vermelha Brasileira — Rio de Janeiro"}

DIAS = ["segunda", "terça", "quarta", "quinta", "sexta", "sábado", "domingo"]
MESES = ["janeiro", "fevereiro", "março", "abril", "maio", "junho", "julho", "agosto", "setembro", "outubro",
         "novembro", "dezembro"]


# --- CSS da página (seção 4) ---------------------------------------------------------------------------------------
# Tudo sob body.v, com o prefixo v- (o mesmo da escola). Os ganchos mr- ficam só como ganchos do JS, do chat.js e do
# turmas.js. Pontos de quebra da escola: 1360, 1060, 980, 860, 650 (celular) e 400. O css_modo, gerado por slug no
# fim do <style>, liga o modo curso, as turmas vencidas e a pílula de dias.
CSS_PAGINA = """
  <style>
    @@FONTES@@
    .v{
      --v-red:#d9081f; --v-red-2:#b80016;
      --v-ink:#141414; --v-titulo:#111111;
      --v-texto:#4a4a4a; --v-muted:#6b6b6b;
      --v-bg:#f7f6f3; --v-card:#ffffff; --v-line:#e4e1dc; --v-chip:#f4f2ef; --v-contorno:#d6d1c9;
      --v-green:#16a369; --v-green-bg:#ecf8f1; --v-green-ink:#1d6b48;
      --v-aviso-bg:#fff4f4; --v-aviso-borda:#f6c9cd; --v-aviso-ink:#7a1b24;
      --v-bege-bg:#f4f2ef;
      --v-escuro:#111111; --v-escuro-linha:#333333;
      --v-r-pilula:999px; --v-r-card:24px; --v-r-flutuante:26px; --v-r-data:18px; --v-r-caixa:16px;
      --v-r-campo:12px; --v-r-painel:28px;
      --v-sombra-hover:0 22px 50px rgba(0,0,0,.08);
      --v-sombra-flutuante:0 25px 70px rgba(0,0,0,.20);
      --v-sombra-investimento:0 16px 50px rgba(0,0,0,.05);
      --v-sombra-faixa:0 16px 55px rgba(0,0,0,.08);
      --v-sombra-barra:0 -10px 30px rgba(0,0,0,.08);
      --v-sombra-cabecalho:0 10px 28px rgba(16,24,40,.06);
      --v-fonte:"Inter","Inter Reserva","Inter Reserva Android",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
      --v-wrap:min(1240px, 100% - 48px);
      --v-cab:92px;
      /* o chat (site/chat/chat.css) lê estas, com reserva própria */
      --red:#d9081f; --red-dark:#b80016; --black:#141414; --muted:#6b6b6b; --line:#e4e1dc; --soft:#f7f6f3; --text:#141414;
    }
    @media (max-width:650px){ .v{ --v-wrap:calc(100% - 32px); --v-cab:76px; } }

    *, *::before, *::after { box-sizing: border-box; }
    html { -webkit-text-size-adjust: 100%; }
    body.v { overflow-wrap: break-word; margin: 0; background: var(--v-bg); color: var(--v-ink); font-family: var(--v-fonte); font-size: 16px; line-height: 1.5; -webkit-font-smoothing: antialiased; text-rendering: optimizeLegibility; overflow-x: hidden; }
    :where(.v) img { max-width: 100%; height: auto; display: block; }
    /* reset com especificidade zero: as classes v- mandam nas margens */
    :where(.v) :where(h1, h2, h3, p, figure, dl, dd, blockquote) { margin: 0; }
    :where(.v) :where(ul, ol) { margin: 0; padding: 0; list-style: none; }
    :where(.v) a { color: inherit; }
    .v button { font-family: inherit; }
    .v svg { flex: none; }
    .v-wrap { width: var(--v-wrap); margin: 0 auto; }
    .v-sr { position: absolute !important; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); clip-path: inset(50%); white-space: nowrap; border: 0; }
    .v *:focus-visible { outline: 3px solid var(--v-ink); outline-offset: 2px; }
    .v [tabindex="-1"]:focus { outline: none; }
    .v-escuro-fundo *:focus-visible { outline-color: #fff; }
    .v-escuro-fundo .v-flutuante *:focus-visible { outline-color: var(--v-ink); }
    .v section[id], .v aside[id], .v article[id] { scroll-margin-top: calc(var(--v-cab) + 16px); }

    /* modos: geral (sem data-curso) e curso */
    html[data-curso] .v-so-geral { display: none !important; }
    html:not([data-curso]) .v-so-curso { display: none !important; }
    .v-curso { display: none; }
    html:not(.js) .v-curso:target { display: block; }
    div.v-se-aberta, span.v-se-aberta, div.v-se-breve, span.v-se-breve { display: contents; }
    .v-data-caixa { flex: none; }
    .v-destaque { display: none; }
    html:not([data-destaque]) .v-destaque-padrao { display: block; }
    html[data-destaque="breve"] .v-destaque-breve { display: block; }
    .v-dias { display: none; }

    /* tipografia */
    .v-kicker { font-size: 12px; font-weight: 900; letter-spacing: .12em; text-transform: uppercase; color: var(--v-red); line-height: 1.3; }
    .v-olho { display: inline-flex; align-items: center; gap: 14px; font-size: 12px; font-weight: 800; letter-spacing: .13em; text-transform: uppercase; line-height: 1.3; }
    .v-olho::before { content: ""; width: 34px; height: 2px; background: var(--v-red); flex: none; }
    .v-h2 { font-size: clamp(40px, 5vw, 64px); font-weight: 800; line-height: .98; letter-spacing: -.055em; color: var(--v-titulo); margin-top: 12px; }
    .v-h2-bloco { font-size: clamp(32px, 3.4vw, 44px); font-weight: 800; line-height: 1.02; letter-spacing: -.045em; color: var(--v-titulo); margin-top: 10px; }
    .v-prosa { font-size: 16px; line-height: 28px; color: var(--v-texto); }
    .v-prosa p + p { margin-top: 14px; }
    .v-link { color: var(--v-red); font-weight: 700; text-decoration: underline; text-underline-offset: 3px; display: inline-flex; align-items: center; gap: 6px; min-height: 24px; }
    .v-link svg { width: 16px; height: 16px; }

    /* botões */
    .v-btn { display: inline-flex; align-items: center; justify-content: center; gap: 10px; min-height: 48px; padding: 14px 22px; border-radius: var(--v-r-pilula); font: 800 15px/1.2 var(--v-fonte); text-decoration: none; border: 1.5px solid transparent; cursor: pointer; text-align: center; background: none; color: var(--v-ink); }
    .v-btn svg { width: 18px; height: 18px; }
    .v-btn-grande { min-height: 52px; padding: 15px 26px; font-size: 16px; }
    .v-btn-vermelho { background: var(--v-red); border-color: var(--v-red); color: #fff; }
    .v-btn-vermelho:hover { background: var(--v-red-2); border-color: var(--v-red-2); }
    .v-btn-branco { background: #fff; border-color: #fff; color: var(--v-ink); }
    .v-btn-branco:hover { background: var(--v-bg); border-color: var(--v-bg); }
    .v-btn-preto { background: #151515; border-color: #151515; color: #fff; }
    .v-btn-preto:hover { background: #000; }
    .v-btn-contorno { background: #fff; border-color: var(--v-contorno); color: var(--v-ink); }
    .v-btn-contorno:hover { border-color: var(--v-ink); }
    .v-btn-contorno-branco { background: rgba(255,255,255,.04); border-color: rgba(255,255,255,.62); color: #fff; }
    .v-btn-contorno-branco:hover { background: rgba(255,255,255,.12); border-color: #fff; }
    .v-btn-largo { width: 100%; }
    @media (prefers-reduced-motion: no-preference) {
      .v-btn { transition: background-color .15s, border-color .15s, color .15s; }
    }

    /* pular para o conteúdo */
    .v-pular { position: absolute; left: 16px; top: -80px; z-index: 200; background: #fff; color: var(--v-ink); padding: 12px 18px; border-radius: 12px; font-weight: 800; text-decoration: none; box-shadow: var(--v-sombra-flutuante); }
    .v-pular:focus { top: 12px; }

    /* faixa vermelha do topo */
    .v-faixa-topo { background: var(--v-red); color: #fff; height: 34px; font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
    .v-faixa-topo .v-wrap { height: 100%; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
    .v-faixa-topo a { color: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; min-height: 24px; }
    .v-faixa-topo a:hover { text-decoration: underline; }
    .v-faixa-topo svg { width: 13px; height: 13px; }
    .v-faixa-curta, .v-faixa-mini { display: none; }
    .v-faixa-topo p { opacity: .96; }

    /* cabeçalho fixo */
    .v-cab { position: sticky; top: 0; z-index: 80; background: #fff; box-shadow: var(--v-sombra-cabecalho); }
    /* o menu nunca quebra linha: os itens têm nowrap e, quando falta largura, some primeiro o texto da marca (1279 px)
       e o botão do curso usa o rótulo curto (1439 px) */
    .v-cab-barra { height: var(--v-cab); display: flex; align-items: center; gap: 24px; }
    .v-marca { display: inline-flex; align-items: center; gap: 20px; text-decoration: none; color: var(--v-ink); flex: none; min-height: 48px; }
    .v-marca img { width: 124px; height: auto; }
    .v-marca-escola { border-left: 1px solid var(--v-line); padding-left: 18px; display: flex; flex-direction: column; line-height: 1.05; white-space: nowrap; }
    .v-marca-escola b { font-size: 17px; font-weight: 800; letter-spacing: -.02em; text-transform: uppercase; }
    .v-marca-escola span { font-size: 11px; font-weight: 800; letter-spacing: .08em; color: var(--v-red); text-transform: uppercase; margin-top: 4px; }
    .v-nav { margin-left: auto; margin-right: auto; min-width: 0; }
    .v-nav ul { display: flex; gap: 2px; }
    .v-nav a { display: inline-flex; align-items: center; min-height: 48px; padding: 0 9px; font-size: 14px; font-weight: 650; color: var(--v-ink); text-decoration: none; position: relative; white-space: nowrap; }
    .v-nav a::after { content: ""; position: absolute; left: 9px; right: 9px; bottom: 9px; height: 2px; background: var(--v-red); transform: scaleX(0); }
    .v-nav a:hover::after, .v-nav a.ativo::after { transform: scaleX(1); }
    .v-cab-acoes { display: flex; align-items: center; gap: 10px; flex: none; }
    .v-cab-acoes .v-btn { min-height: 48px; padding: 12px 20px; font-size: 14px; white-space: nowrap; }
    .v-cab-acoes .v-btn svg { width: 15px; height: 15px; }
    .v-cab-cta-curto { display: none; }
    .v-menu-botao { display: none; margin-left: auto; width: 48px; height: 48px; border: 0; background: none; color: var(--v-ink); cursor: pointer; align-items: center; justify-content: center; border-radius: 12px; }
    .v-menu-botao svg { width: 30px; height: 30px; }
    .v-menu-botao .v-ico-fechar { display: none; }
    .v-menu-botao[aria-expanded="true"] .v-ico-abrir { display: none; }
    .v-menu-botao[aria-expanded="true"] .v-ico-fechar { display: block; }
    .v-menu { position: fixed; left: 0; right: 0; bottom: 0; top: var(--v-cab); background: #fff; overflow-y: auto; overscroll-behavior: contain; padding: 18px 0 40px; border-top: 1px solid var(--v-line); }
    .v-menu[hidden] { display: none; }
    .v-menu ol { counter-reset: menu; }
    .v-menu ol a { display: flex; align-items: baseline; gap: 16px; padding: 12px 0; min-height: 56px; font-size: clamp(26px, 7.4vw, 32px); font-weight: 800; letter-spacing: -.04em; color: var(--v-ink); text-decoration: none; border-bottom: 1px solid var(--v-line); }
    .v-menu ol a small { font-size: 12px; font-weight: 900; letter-spacing: .1em; color: var(--v-red); min-width: 22px; }
    .v-menu-aluno { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 26px; font-weight: 700; }
    .v-menu-chat { display: flex; align-items: center; gap: 14px; width: 100%; margin-top: 18px; padding: 18px; border: 1px solid var(--v-line); border-radius: var(--v-r-caixa); background: var(--v-bg); color: var(--v-ink); text-align: left; font: 700 16px/1.35 var(--v-fonte); cursor: pointer; min-height: 56px; text-decoration: none; }
    .v-menu-chat svg { width: 26px; height: 26px; color: var(--v-red); }
    .v-menu-chat small { display: block; font-weight: 500; color: var(--v-texto); font-size: 14px; }
    .v-menu-endereco { margin-top: 18px; display: flex; gap: 10px; align-items: center; color: var(--v-texto); font-size: 15px; }
    .v-menu-endereco svg { width: 18px; height: 18px; color: var(--v-red); }
    html.v-menu-aberto { overflow: hidden; }

    /* topo do modo geral */
    .v-hero { position: relative; color: #fff; background: #111; min-height: clamp(560px, calc(100vh - 126px), 760px); min-height: clamp(560px, calc(100svh - 126px), 760px); display: flex; isolation: isolate; }
    /* direção de arte: no celular o retrato da aula (aula-dea-sala); acima de 650 px a foto em paisagem do salão, que tem
       1440 px e não fica ampliada nem borrada no computador */
    .v-hero-foto { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; z-index: -2; object-position: center 47%; }
    @media (min-width: 651px) { .v-hero-foto { object-position: 62% 58%; } }
    .v-hero::before { content: ""; position: absolute; inset: 0; z-index: -1; background: linear-gradient(90deg, rgba(11,11,11,.92) 0%, rgba(11,11,11,.74) 37%, rgba(11,11,11,.24) 67%, rgba(11,11,11,.08) 100%); }
    .v-hero::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 180px; z-index: -1; background: linear-gradient(180deg, rgba(247,246,243,0), var(--v-bg)); }
    /* H1 em até 3 linhas de 1024 a 1920 px e o cartão de destaque centrado, inteiro na primeira tela em 1366×768 */
    .v-hero-grade { display: grid; grid-template-columns: minmax(0, 1.22fr) minmax(0, .78fr); gap: 56px; align-items: center; padding: clamp(56px, 9vh, 96px) 0 clamp(104px, 15vh, 132px); width: var(--v-wrap); margin: 0 auto; }
    .v-hero-texto { max-width: 780px; }
    .v-hero-h1 { margin: 22px 0 0; font-size: clamp(48px, min(5.4vw, 9.6vh), 82px); font-weight: 800; line-height: .92; letter-spacing: -.062em; color: #fff; text-wrap: balance; }
    .v-hero-h1 em { font-style: normal; color: var(--v-red); }
    .v-hero-lead { margin-top: 28px; font-size: 19px; line-height: 1.62; color: #e6e6e6; max-width: 640px; }
    .v-hero-acoes { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 30px; }
    .v-selos { display: flex; flex-wrap: wrap; gap: 10px 22px; margin-top: 26px; font-size: 14px; color: #e6e6e6; }
    .v-selos li { display: inline-flex; align-items: center; gap: 9px; }
    .v-selos li span { width: 20px; height: 20px; border-radius: 50%; background: rgba(255,255,255,.16); display: grid; place-items: center; }
    .v-selos svg { width: 12px; height: 12px; color: #fff; }

    /* cartões flutuantes (destaque do topo e cartão da turma) */
    .v-flutuante { background: rgba(255,255,255,.97); color: var(--v-ink); border-radius: var(--v-r-flutuante); padding: 26px; box-shadow: var(--v-sombra-flutuante); }
    .v-destaque-link { display: block; text-decoration: none; color: var(--v-ink); }
    .v-destaque-link:hover .v-destaque-ver { text-decoration: underline; }
    .v-destaque-titulo { font-size: 29px; font-weight: 800; letter-spacing: -.045em; line-height: 1.08; margin-top: 10px; color: var(--v-titulo); }
    .v-destaque-meta { margin-top: 10px; font-size: 14px; color: var(--v-texto); }
    .v-destaque-linha { border: 0; border-top: 1px solid var(--v-line); margin: 20px 0; }
    .v-destaque-preco { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
    .v-destaque-ver { display: inline-flex; align-items: center; gap: 8px; margin-top: 18px; font-weight: 800; color: var(--v-red); font-size: 15px; min-height: 24px; }
    .v-destaque-ver svg { width: 16px; height: 16px; }
    .v-destaque-breve p + p { margin-top: 10px; }
    .v-destaque-breve .v-texto-breve { color: var(--v-texto); font-size: 15px; line-height: 1.55; }

    /* bloco de preço (rótulo, matrícula, taxa, total) */
    .v-preco-rotulo { font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--v-muted); }
    .v-preco-valor { font-size: 22px; font-weight: 800; letter-spacing: -.03em; line-height: 1.1; color: var(--v-titulo); margin-top: 2px; }
    .v-preco-taxa { font-size: 13px; color: var(--v-muted); margin-top: 2px; }
    .v-preco-total { font-size: 14px; font-weight: 700; color: var(--v-ink); margin-top: 4px; }
    .v-destaque .v-preco-valor { font-size: 25px; }
    .v-cartao .v-preco-valor { font-size: 30px; }

    /* bloco de data */
    .v-data { background: var(--v-red); color: #fff; border-radius: var(--v-r-data); padding: 10px 15px; min-width: 82px; text-align: center; display: flex; flex-direction: column; align-items: center; line-height: 1; flex: none; }
    .v-data span { font-size: 10px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: rgba(255,255,255,.92); }
    .v-data b { font-size: 28px; font-weight: 800; margin: 4px 0; letter-spacing: -.03em; }

    /* faixa de destaques (sobreposta ao topo) */
    .v-destaques { position: relative; z-index: 2; margin-top: -52px; }
    .v-destaques ul { background: #fff; border-radius: var(--v-r-card); box-shadow: var(--v-sombra-faixa); display: grid; grid-template-columns: repeat(4, 1fr); }
    .v-destaques li { padding: 26px 26px 28px; border-left: 1px solid var(--v-line); }
    .v-destaques li:first-child { border-left: 0; }
    .v-destaques h2 { display: flex; align-items: center; gap: 10px; font-size: 16px; font-weight: 800; letter-spacing: -.02em; color: var(--v-titulo); }
    .v-destaques h2 svg { width: 20px; height: 20px; color: var(--v-red); }
    .v-destaques p { margin-top: 8px; font-size: 14px; line-height: 1.55; color: var(--v-texto); }

    /* seções */
    .v-secao { padding: 90px 0; }
    .v-secao-branca { background: #fff; }
    .v-cab-secao { display: flex; justify-content: space-between; align-items: flex-end; gap: 40px; margin-bottom: 30px; }
    .v-cab-secao > div { max-width: 720px; }
    .v-cab-secao > p { max-width: 460px; font-size: 15px; line-height: 1.6; color: var(--v-texto); }

    /* cursos: grade de 12 colunas, cards da escola */
    .v-cursos { padding-top: 90px; }
    .v-grade { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 18px; }
    .v-card { grid-column: span var(--span, 4); display: flex; flex-direction: column; background: #fff; border: 1px solid var(--v-line); border-radius: var(--v-r-card); overflow: hidden; text-decoration: none; color: var(--v-ink); position: relative; }
    html[data-curso] .v-card { grid-column: span 4; }
    .v-card-foto { position: relative; height: 225px; background: #e9e6e0; overflow: hidden; }
    .v-card-largo .v-card-foto { height: 300px; }
    .v-card-foto img { width: 100%; height: 100%; object-fit: cover; }
    .v-card-foto::after { content: ""; position: absolute; inset: auto 0 0 0; height: 45%; background: linear-gradient(180deg, rgba(0,0,0,0), rgba(0,0,0,.35)); }
    .v-selo { position: absolute; top: 16px; left: 16px; z-index: 1; display: inline-flex; align-items: center; gap: 7px; background: rgba(255,255,255,.95); color: var(--v-ink); border-radius: var(--v-r-pilula); padding: 7px 12px; font-size: 11px; font-weight: 850; letter-spacing: .06em; text-transform: uppercase; line-height: 1; }
    .v-ponto { width: 7px; height: 7px; border-radius: 50%; background: var(--v-green); }
    .v-card-corpo { padding: 24px 24px 22px; display: flex; flex-direction: column; flex: 1; }
    .v-card-titulo { font-size: 25px; font-weight: 800; letter-spacing: -.045em; line-height: 1.08; color: var(--v-titulo); }
    .v-card-largo .v-card-titulo { font-size: 35px; }
    .v-card-apoio { margin-top: 6px; font-size: 14px; color: var(--v-texto); font-weight: 600; }
    .v-card-desc { margin-top: 8px; font-size: 14px; line-height: 1.55; color: var(--v-texto); display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
    .v-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 14px; }
    .v-chip { display: inline-flex; align-items: center; font-size: 11px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; background: var(--v-chip); color: #3d3d3d; border-radius: var(--v-r-pilula); padding: 6px 10px; line-height: 1.1; }
    .v-card-rodape { margin-top: auto; padding-top: 18px; border-top: 1px solid var(--v-line); display: flex; align-items: flex-end; justify-content: space-between; gap: 14px; }
    .v-card-corpo .v-chips { margin-bottom: 18px; }
    .v-card-seta { width: 44px; height: 44px; border-radius: 50%; background: var(--v-red); color: #fff; display: grid; place-items: center; flex: none; }
    .v-card-seta svg { width: 18px; height: 18px; transform: rotate(-45deg); }
    .v-preco-compacto, .v-card-estado { display: none; }
    @media (prefers-reduced-motion: no-preference) {
      .v-card { transition: transform .2s, box-shadow .2s; }
      .v-card:hover { transform: translateY(-5px); box-shadow: var(--v-sombra-hover); }
      .v-card-seta svg { transition: transform .2s; }
      .v-card:hover .v-card-seta svg { transform: rotate(0); }
    }
    .v-nota-preco { margin-top: 26px; font-size: 14px; line-height: 1.6; color: var(--v-texto); max-width: 900px; }
    .v-linha-grupos { margin-top: 14px; font-size: 15px; color: var(--v-ink); font-weight: 600; }

    /* como funciona (faixa vermelha) */
    .v-como { background: var(--v-red); color: #fff; position: relative; overflow: hidden; }
    .v-como-marca { position: absolute; right: -120px; top: 50%; transform: translateY(-50%); width: 670px; height: 670px; color: rgba(255,255,255,.055); pointer-events: none; }
    .v-como-grade { display: grid; grid-template-columns: .8fr 1.2fr; gap: 100px; position: relative; }
    .v-como .v-kicker { color: #fff; }
    .v-como-h2 { font-size: 60px; font-weight: 800; line-height: .98; letter-spacing: -.055em; margin-top: 12px; color: #fff; }
    .v-como-h2 span { display: block; }
    .v-como-acoes { margin-top: 30px; }
    .v-como-nota { margin-top: 22px; font-size: 13px; line-height: 1.6; color: #fff; max-width: 420px; }
    .v-como-nota a { color: #fff; font-weight: 800; }
    .v-passos li { display: grid; grid-template-columns: 80px 1fr; padding: 26px 0; border-top: 1px solid rgba(255,255,255,.28); }
    .v-passos li:last-child { border-bottom: 1px solid rgba(255,255,255,.28); }
    .v-passos-num { font-size: 13px; font-weight: 900; letter-spacing: .08em; color: #fff; padding-top: 6px; }
    .v-passos h3 { font-size: 22px; font-weight: 800; letter-spacing: -.03em; color: #fff; }
    .v-passos p { margin-top: 8px; font-size: 15px; line-height: 1.6; color: #fff; }

    /* certificado */
    .v-cert-grade { display: grid; grid-template-columns: 1fr 1fr; gap: 64px; align-items: center; }
    .v-cert-texto .v-prosa { margin-top: 18px; max-width: 540px; }
    .v-cert-figura img { width: 100%; border-radius: 18px; border: 1px solid var(--v-line); box-shadow: var(--v-sombra-investimento); background: #fff; }
    .v-cert-nota { margin-top: 12px; font-size: 13px; color: var(--v-muted); }

    /* quem somos, fotos e como chegar */
    .v-escola-grade { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
    .v-sede { position: relative; border-radius: var(--v-r-painel); overflow: hidden; min-height: 520px; background: #111; color: #fff; }
    .v-sede img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .v-sede::after { content: ""; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0,0,0,0) 45%, rgba(0,0,0,.78)); }
    .v-sede figcaption { position: absolute; left: 28px; right: 28px; bottom: 26px; z-index: 1; }
    .v-sede figcaption span { display: block; font-size: 12px; font-weight: 900; letter-spacing: .12em; text-transform: uppercase; }
    .v-sede figcaption b { display: block; margin-top: 8px; font-size: 28px; font-weight: 800; letter-spacing: -.04em; line-height: 1.1; }
    .v-painel { background: var(--v-escuro); color: #fff; border-radius: var(--v-r-painel); padding: 44px; }
    .v-painel .v-kicker { color: #ff6b77; }
    .v-painel h2 { font-size: clamp(34px, 3.6vw, 48px); font-weight: 800; letter-spacing: -.05em; line-height: 1; margin-top: 12px; color: #fff; }
    .v-painel-prosa { margin-top: 20px; font-size: 15px; line-height: 1.65; color: #e6e6e6; }
    .v-painel-prosa p + p { margin-top: 12px; }
    .v-painel-prosa a { color: #fff; font-weight: 800; }
    .v-painel dl { margin-top: 26px; }
    .v-painel dl div { display: grid; grid-template-columns: 130px 1fr; gap: 16px; padding: 14px 0; border-top: 1px solid var(--v-escuro-linha); font-size: 14px; }
    .v-painel dt { font-weight: 800; color: #fff; }
    .v-painel dd { color: #e6e6e6; text-align: right; }
    .v-fotos-titulo { font-size: clamp(28px, 3vw, 40px); font-weight: 800; letter-spacing: -.045em; line-height: 1.05; margin: 64px 0 22px; color: var(--v-titulo); }
    .v-fotos { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
    .v-fotos figure { background: #fff; border: 1px solid var(--v-line); border-radius: 18px; overflow: hidden; height: 100%; }
    .v-fotos img { width: 100%; aspect-ratio: 4 / 5; object-fit: cover; }
    .v-fotos figcaption { padding: 12px 14px 14px; font-size: 14px; line-height: 1.45; color: var(--v-texto); }
    .v-curso .v-fotos { grid-template-columns: repeat(4, 1fr); }
    .v-curso .v-fotos img { aspect-ratio: 1 / 1; }
    .v-chegar { position: relative; margin-top: 64px; border-radius: var(--v-r-painel); overflow: hidden; background: #e9e6e0; min-height: 440px; }
    .v-mapa { position: absolute; inset: 0; }
    .v-mapa-abrir { position: absolute; inset: 0; width: 100%; height: 100%; padding: 0; border: 0; background: none; cursor: pointer; display: block; }
    .v-mapa-abrir img { width: 100%; height: 100%; object-fit: cover; }
    .v-mapa iframe { width: 100%; height: 100%; border: 0; display: block; }
    .v-mapa figcaption { position: absolute; left: 12px; bottom: 12px; background: rgba(255,255,255,.95); border-radius: 10px; padding: 6px 10px; font-size: 12px; color: var(--v-texto); }
    .v-chegar-cartao { position: relative; z-index: 1; margin: 34px; max-width: 380px; }
    .v-chegar-cartao h2 { font-size: 30px; font-weight: 800; letter-spacing: -.045em; line-height: 1.05; margin-top: 10px; color: var(--v-titulo); }
    .v-chegar-cartao .v-chegar-end { margin-top: 8px; font-size: 14px; color: var(--v-texto); }
    .v-chegar-cartao .v-chegar-texto { margin-top: 12px; font-size: 15px; line-height: 1.55; color: var(--v-texto); }
    .v-chegar-acoes { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
    .v-chegar-acoes .v-btn { min-height: 48px; padding: 12px 18px; font-size: 14px; }
    .v-credito { position: absolute; right: 12px; bottom: 10px; z-index: 1; background: rgba(255,255,255,.95); border-radius: 8px; padding: 4px 8px; font-size: 12px; color: var(--v-texto); }

    /* depoimentos */
    .v-depo-grade { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
    .v-depo { background: #fff; border: 1px solid var(--v-line); border-radius: var(--v-r-card); padding: 26px; }
    .v-depo blockquote { margin: 0; font-size: 16px; line-height: 1.6; color: var(--v-ink); }
    .v-depo figcaption { margin-top: 16px; font-size: 14px; color: var(--v-texto); }

    /* perguntas frequentes */
    .v-faq-grade { display: grid; grid-template-columns: minmax(0, 375px) minmax(0, 1fr); gap: 80px; align-items: start; }
    .v-faq-cab .v-faq-texto { margin-top: 16px; font-size: 15px; line-height: 1.6; color: var(--v-texto); }
    .v-faq-cab .v-btn { margin-top: 20px; }
    .v-faq-lista details { border-top: 1px solid var(--v-line); }
    .v-faq-lista details:last-child { border-bottom: 1px solid var(--v-line); }
    .v-faq-lista summary { list-style: none; cursor: pointer; display: flex; justify-content: space-between; align-items: center; gap: 20px; padding: 22px 0; min-height: 48px; font-size: 16px; font-weight: 750; line-height: 1.4; color: var(--v-titulo); }
    .v-faq-lista summary::-webkit-details-marker { display: none; }
    .v-faq-lista summary::after { content: "+"; color: var(--v-red); font-size: 22px; font-weight: 500; line-height: 1; flex: none; }
    .v-faq-lista details[open] summary::after { content: "–"; }
    .v-faq-resp { padding: 0 40px 22px 0; font-size: 15px; line-height: 1.65; color: var(--v-texto); }
    .v-faq-resp p + p, .v-faq-resp ul { margin-top: 10px; }
    .v-faq-resp ul { list-style: disc; padding-left: 20px; }
    .v-faq-resp a { color: var(--v-red); font-weight: 700; }

    /* turmas para grupos */
    .v-empresas { background: #fff; padding: 64px 0; }
    .v-empresas-grade { display: grid; grid-template-columns: 1.4fr auto; gap: 48px; align-items: center; }
    .v-empresas h2 { font-size: clamp(30px, 3.4vw, 44px); font-weight: 800; letter-spacing: -.045em; line-height: 1.02; margin-top: 10px; color: var(--v-titulo); }
    .v-empresas-texto { margin-top: 14px; font-size: 15px; line-height: 1.65; color: var(--v-texto); max-width: 760px; }
    .v-empresas-acao { display: flex; flex-direction: column; align-items: flex-start; gap: 10px; }
    .v-empresas-nota { font-size: 13px; color: var(--v-muted); max-width: 300px; }

    /* chamada final */
    .v-final { background: var(--v-red); color: #fff; text-align: center; }
    .v-final h2 { font-size: clamp(42px, 5vw, 64px); font-weight: 800; letter-spacing: -.055em; line-height: .98; color: #fff; }
    .v-final p { margin: 18px auto 0; font-size: 17px; line-height: 1.6; max-width: 560px; color: #fff; }
    .v-final-acoes { display: flex; flex-wrap: wrap; justify-content: center; gap: 14px; margin-top: 30px; }

    /* rodapé */
    .v-rodape { background: #fff; border-top: 1px solid var(--v-line); }
    .v-rodape-grade { display: grid; grid-template-columns: 1.3fr 1fr 1fr; gap: 48px; padding: 64px 0 48px; }
    .v-rodape-marca p { margin-top: 18px; font-size: 14px; line-height: 1.6; color: var(--v-texto); max-width: 360px; }
    .v-selo-link { display: inline-flex; align-items: center; gap: 8px; margin-top: 18px; padding: 10px 16px; min-height: 44px; border: 1px solid var(--v-line); border-radius: var(--v-r-pilula); font-size: 13px; font-weight: 800; color: var(--v-ink); text-decoration: none; }
    .v-selo-link:hover { border-color: var(--v-ink); }
    .v-selo-link svg { width: 13px; height: 13px; }
    .v-selo-link .v-cruz { color: var(--v-red); width: 12px; height: 12px; }
    .v-rodape-titulo { font-size: 12px; font-weight: 900; letter-spacing: .12em; text-transform: uppercase; color: var(--v-red); margin-bottom: 14px; }
    .v-rodape-lista li + li { margin-top: 2px; }
    .v-rodape-lista a, .v-rodape-lista button { display: inline-flex; align-items: center; gap: 8px; min-height: 36px; font-size: 14px; color: var(--v-ink); text-decoration: none; background: none; border: 0; padding: 0; cursor: pointer; font-family: inherit; text-align: left; }
    .v-rodape-lista a:hover, .v-rodape-lista button:hover { text-decoration: underline; }
    .v-rodape-lista svg { width: 15px; height: 15px; color: var(--v-red); }
    .v-rodape-lista small { display: block; font-size: 12px; color: var(--v-muted); margin-top: -6px; }
    .v-rodape-contato li { display: flex; gap: 10px; align-items: flex-start; font-size: 14px; line-height: 1.5; color: var(--v-ink); padding: 6px 0; }
    .v-rodape-contato svg { width: 16px; height: 16px; color: var(--v-red); margin-top: 3px; }
    .v-rodape-faixa { background: var(--v-bg); border-top: 1px solid var(--v-line); padding: 18px 0; font-size: 13px; color: var(--v-texto); text-align: center; }
    .v-rodape-faixa .v-wrap { display: flex; flex-direction: column; align-items: center; gap: 4px; }
    .v-rodape-legal { display: flex; flex-wrap: wrap; justify-content: center; gap: 0 16px; }
    .v-rodape-faixa a { color: var(--v-ink); text-decoration: none; display: inline-flex; align-items: center; min-height: 32px; }
    .v-rodape-faixa a:hover { text-decoration: underline; }

    /* --- modo curso ---------------------------------------------------------------------------------------- */
    .v-curso-topo { position: relative; color: #fff; background: #111; isolation: isolate; }
    .v-curso-foto { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; z-index: -2; }
    .v-curso-topo::before { content: ""; position: absolute; inset: 0; z-index: -1; background: linear-gradient(90deg, rgba(11,11,11,.9) 0%, rgba(11,11,11,.72) 50%, rgba(11,11,11,.5) 100%); }
    .v-curso-topo-grade { width: var(--v-wrap); margin: 0 auto; min-height: 557px; padding: 48px 0; display: grid; grid-template-columns: minmax(0, 1fr) 400px; grid-template-areas: "texto cartao" "prova cartao"; gap: 0 60px; align-items: center; }
    .v-curso-texto { grid-area: texto; align-self: end; }
    .v-curso-topo .v-cartao { grid-area: cartao; }
    .v-prova { grid-area: prova; align-self: start; display: flex; flex-wrap: wrap; gap: 10px 26px; margin-top: 26px; }
    .v-prova li { display: inline-flex; align-items: center; gap: 9px; font-size: 14px; font-weight: 700; color: #fff; }
    .v-prova svg { width: 20px; height: 20px; color: #ff4d5c; }
    .v-voltar { display: inline-flex; align-items: center; gap: 8px; min-height: 40px; font-size: 14px; font-weight: 700; color: #fff; text-decoration: none; }
    .v-voltar:hover { text-decoration: underline; }
    .v-voltar svg { width: 16px; height: 16px; }
    .v-curso-texto .v-olho { margin-top: 26px; display: flex; }
    .v-curso-h1 { margin-top: 14px; font-size: clamp(44px, 5.4vw, 78px); font-weight: 800; line-height: .95; letter-spacing: -.06em; color: #fff; }
    .v-curso-apoio { margin-top: 16px; font-size: 19px; line-height: 1.5; color: #e6e6e6; max-width: 640px; }
    .v-chips-topo { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 22px; }
    .v-chips-topo li { display: inline-flex; align-items: center; gap: 7px; min-height: 34px; padding: 6px 14px; border: 1px solid rgba(255,255,255,.34); background: rgba(255,255,255,.1); border-radius: var(--v-r-pilula); font-size: 13px; font-weight: 700; color: #fff; }
    .v-chips-topo svg { width: 15px; height: 15px; }
    .v-chips-topo .v-chip-data { background: #fff; color: var(--v-ink); border-color: #fff; }
    .v-chips-topo .v-chip-data svg { color: var(--v-red); }

    .v-cartao-dataline { display: flex; gap: 16px; align-items: flex-start; margin-top: 14px; }
    .v-cartao-dia { font-size: 18px; font-weight: 800; letter-spacing: -.02em; line-height: 1.2; color: var(--v-ink); }
    .v-cartao-hora, .v-cartao-insc, .v-cartao-local { font-size: 14px; font-weight: 600; color: var(--v-texto); margin-top: 4px; line-height: 1.45; }
    .v-cartao-hora { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; }
    .v-dias { align-items: center; background: var(--v-green-bg); color: var(--v-green-ink); font-size: 13px; font-weight: 700; border-radius: var(--v-r-pilula); padding: 3px 10px; line-height: 1.4; }
    .v-cartao-breve-titulo { font-size: 20px; font-weight: 800; letter-spacing: -.02em; margin-top: 10px; color: var(--v-ink); }
    .v-cartao-breve-texto { font-size: 14px; color: var(--v-texto); margin-top: 6px; line-height: 1.5; }
    .v-cartao .v-destaque-linha { margin: 18px 0 16px; }
    .v-cartao .v-btn { width: 100%; margin-top: 16px; }
    .v-cartao .v-btn-grande, .v-inv .v-btn-grande { padding-left: 14px; padding-right: 14px; gap: 8px; }
    .v-inv .v-btn-grande { font-size: 15px; }
    .v-cartao .v-btn + .v-btn { margin-top: 10px; }
    .v-homolog { margin-top: 10px; font-size: 13px; line-height: 1.5; color: var(--v-texto); }
    .v-homolog a { color: var(--v-red); font-weight: 700; text-decoration: underline; display: inline-block; padding: 3px 0; line-height: 18px; }
    .v-nota-pagamento { margin-top: 12px; display: grid; gap: 4px; font-size: 13px; line-height: 1.5; color: var(--v-texto); }
    .v-nota-pagamento b { color: var(--v-ink); font-weight: 700; }
    .v-devolucao { display: inline-flex; align-items: center; gap: 7px; margin-top: 12px; min-height: 24px; font-size: 13px; font-weight: 600; color: var(--v-green-ink); text-decoration: none; line-height: 1.35; }
    .v-devolucao:hover { text-decoration: underline; }
    .v-devolucao svg { width: 16px; height: 16px; }
    .v-chat-link { display: inline-flex; align-items: center; gap: 7px; margin-top: 8px; min-height: 24px; font-size: 13px; font-weight: 700; color: var(--v-ink); text-decoration: underline; text-underline-offset: 3px; }
    .v-chat-link svg { width: 15px; height: 15px; color: var(--v-red); }
    .v-cartao-pes { display: flex; flex-direction: column; align-items: flex-start; }

    .v-curso-corpo-sec { padding: 90px 0; }
    .v-curso-corpo { display: grid; grid-template-columns: minmax(0, 1fr) 400px; gap: 0 56px; align-items: start; }
    .v-inv { grid-column: 2; grid-row: 1 / span 4; align-self: start; background: #fff; border: 1px solid var(--v-line); border-radius: var(--v-r-card); box-shadow: var(--v-sombra-investimento); padding: 20px 24px; }
    /* fixo na lateral só com altura para ele inteiro (1366×657, a tela útil de um notebook, fica sem o fixo) */
    @media (min-width: 981px) and (min-height: 720px) { .v-inv { position: sticky; top: calc(var(--v-cab) + 24px); } .v-inv.v-inv-solto { position: static; } }
    .v-bloco { grid-column: 1; padding: 44px 0; border-top: 1px solid var(--v-line); }
    .v-curso-corpo > .v-bloco:nth-child(2) { padding-top: 0; border-top: 0; }
    .v-inv dl { margin-top: 4px; }
    .v-inv dl div { display: grid; grid-template-columns: minmax(0, 1fr) auto; column-gap: 16px; align-items: baseline; padding: 10px 0; border-top: 1px solid var(--v-line); font-size: 14px; }
    .v-inv dl div:first-child { border-top: 0; }
    .v-inv dt { color: var(--v-ink); }
    .v-inv dd { font-weight: 700; white-space: nowrap; color: var(--v-ink); }
    .v-inv dd.v-inv-leg { grid-column: 1 / -1; margin-top: 2px; font-size: 12px; font-weight: 400; line-height: 1.4; white-space: normal; color: var(--v-muted); }
    .v-inv .v-inv-total { align-items: center; }
    .v-inv .v-inv-total dt { font-weight: 800; }
    .v-inv .v-inv-total dd { font-size: 22px; font-weight: 800; color: var(--v-red); letter-spacing: -.02em; }
    .v-inv-obs { font-size: 13px; color: var(--v-texto); margin-top: 4px; line-height: 1.5; }
    .v-aviso { display: flex; gap: 10px; align-items: flex-start; margin-top: 12px; background: var(--v-aviso-bg); border: 1px solid var(--v-aviso-borda); color: var(--v-aviso-ink); border-radius: var(--v-r-caixa); padding: 12px 14px; font-size: 13px; line-height: 1.5; }
    .v-aviso svg { width: 18px; height: 18px; color: var(--v-red); margin-top: 1px; }
    .v-aviso b { font-weight: 800; }
    .v-inv .v-btn { width: 100%; margin-top: 12px; }
    .v-inv .v-aviso { margin-top: 10px; }
    .v-inv-grupo { margin-top: 8px; font-size: 13px; color: var(--v-texto); line-height: 1.5; }
    .v-inv .v-devolucao { margin-top: 10px; }
    .v-inv .v-chat-link { margin-top: 6px; }
    .v-inv-grupo a { color: var(--v-ink); font-weight: 700; text-decoration: underline; }
    .v-inv-pes { display: flex; flex-direction: column; align-items: flex-start; }

    .v-passos-curso { counter-reset: passo; margin-top: 22px; display: grid; gap: 14px; }
    .v-passos-curso li { counter-increment: passo; display: grid; grid-template-columns: 44px minmax(0, 1fr); gap: 16px; background: #fff; border: 1px solid var(--v-line); border-radius: 20px; padding: 20px; }
    .v-passos-curso li::before { content: counter(passo); width: 44px; height: 44px; border-radius: 50%; background: var(--v-red); color: #fff; font-weight: 800; font-size: 18px; display: grid; place-items: center; }
    .v-passos-curso h3 { font-size: 18px; font-weight: 800; letter-spacing: -.02em; color: var(--v-titulo); line-height: 1.3; padding-top: 10px; }
    .v-passos-curso p { margin-top: 6px; font-size: 15px; line-height: 1.6; color: var(--v-texto); }
    .v-cert-curso { margin-top: 18px; max-width: 560px; }
    .v-cert-curso img { width: 100%; border-radius: 16px; border: 1px solid var(--v-line); background: #fff; }
    .v-cert-curso figcaption { margin-top: 12px; font-size: 13px; line-height: 1.5; color: var(--v-muted); }
    .v-obs { margin-top: 18px; background: var(--v-bege-bg); border-radius: var(--v-r-caixa); padding: 16px 18px; font-size: 15px; line-height: 1.55; color: var(--v-ink); }
    .v-fotos-curso-titulo { font-size: 22px; font-weight: 800; letter-spacing: -.03em; margin: 30px 0 14px; color: var(--v-titulo); }
    .v-turma-linha { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; gap: 20px; align-items: center; margin-top: 22px; background: #fff; border: 1px solid var(--v-line); border-radius: var(--v-r-card); padding: 18px; }
    .v-turma-linha .v-cartao-dia { font-size: 17px; }
    .v-turma-linha .v-btn { min-height: 48px; }
    .v-turma-depois { margin-top: 12px; font-size: 14px; color: var(--v-texto); }
    .v-turma-fina { margin-top: 14px; font-size: 13px; line-height: 1.55; color: var(--v-texto); }
    .v-turma-caixa { margin-top: 22px; border: 2px dashed var(--v-contorno); border-radius: var(--v-r-card); padding: 22px; font-size: 15px; line-height: 1.6; color: var(--v-ink); background: #fff; }
    .v-turma-botoes { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 18px; }

    .v-curso-faq { background: var(--v-bg); padding: 0 0 90px; }
    .v-chegar-curso { padding: 0 0 90px; }
    .v-chegar-curso-grade { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); background: #fff; border: 1px solid var(--v-line); border-radius: var(--v-r-card); overflow: hidden; }
    .v-chegar-curso-grade a.v-chegar-img { display: block; min-height: 260px; position: relative; }
    .v-chegar-curso-grade a.v-chegar-img img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .v-chegar-curso-texto { padding: 30px; }
    .v-chegar-curso-texto h2 { font-size: 30px; font-weight: 800; letter-spacing: -.045em; margin-top: 10px; line-height: 1.05; color: var(--v-titulo); }
    .v-chegar-curso-texto p:not(.v-kicker) { margin-top: 8px; font-size: 15px; color: var(--v-texto); line-height: 1.55; }
    .v-chegar-curso-texto small { display: block; margin-top: 14px; font-size: 12px; color: var(--v-muted); }

    /* barra fixa (celular, modo curso) */
    .v-barra { position: fixed; left: 0; right: 0; bottom: 0; z-index: 70; background: #fff; border-top: 1px solid var(--v-line); box-shadow: var(--v-sombra-barra); min-height: calc(76px + env(safe-area-inset-bottom)); padding: 10px 16px calc(10px + env(safe-area-inset-bottom)); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    .v-barra[hidden] { display: none; }
    .v-barra-texto { display: flex; flex-direction: column; min-width: 0; line-height: 1.2; }
    .v-barra-rotulo { font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--v-muted); }
    .v-barra-valor { font-size: 20px; font-weight: 800; letter-spacing: -.03em; color: var(--v-titulo); }
    .v-barra-agora { font-size: 13px; font-weight: 700; color: var(--v-ink); white-space: nowrap; }
    .v-barra .v-btn { flex: none; min-height: 48px; padding: 12px 20px; }
    .v-barra-curto { display: none; }
    html.mr-barra-on body { padding-bottom: calc(76px + env(safe-area-inset-bottom)); }
    html.mr-barra-on body .cv-chat { bottom: calc(88px + env(safe-area-inset-bottom)); }
    html.mr-chat-recolher body .cv-chat:not(.aberto) .cv-chat-abrir { opacity: 0; pointer-events: none; }
    html.v-menu-aberto body .cv-chat { display: none !important; }
    html body .cv-chat .cv-chat-abrir { transition: opacity .2s; }
    @media (prefers-reduced-motion: no-preference) {
      .v-barra { animation: v-sobe .25s ease-out; }
      @keyframes v-sobe { from { transform: translateY(100%); } to { transform: none; } }
    }

    /* dialogs: turmas para grupos (static/turmas.js) e "Prefiro ser avisado da data" */
    html.mr-modal-aberto { overflow: hidden; }
    .mr-modal { width: min(720px, calc(100vw - 32px)); max-height: calc(100vh - 48px); max-height: calc(100dvh - 48px); margin: auto; border: 0; border-radius: var(--v-r-card); padding: 28px; overflow: auto; overscroll-behavior: contain; box-shadow: var(--v-sombra-flutuante); background: #fff; color: var(--v-ink); font-family: var(--v-fonte); }
    .mr-modal::backdrop { background: rgba(17,17,17,.72); }
    .v-aviso-dialog { width: min(520px, calc(100vw - 32px)); }
    .mr-modal-fechar { position: sticky; top: 0; float: right; margin: -12px -12px 0 8px; width: 48px; height: 48px; border: 0; border-radius: 50%; background: #fff; color: var(--v-ink); font-size: 28px; line-height: 1; cursor: pointer; z-index: 1; display: grid; place-items: center; font-family: inherit; }
    .mr-modal-fechar:hover, .mr-modal-fechar:focus-visible { background: var(--v-bg); }
    .mr-demanda-form h3, .v-aviso-dialog h2 { font-size: 24px; font-weight: 800; letter-spacing: -.035em; line-height: 1.15; margin: 0 0 6px; color: var(--v-titulo); }
    .mr-tf-nota { color: var(--v-texto); margin: 0 0 18px; font-size: 15px; }
    .mr-tf-grade { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px 20px; }
    .mr-tf-largo { grid-column: 1 / -1; }
    .mr-tf-campo { margin: 0; padding: 0; border: 0; min-width: 0; }
    .mr-tf-campo label, .mr-tf-campo legend { display: block; font-weight: 700; color: var(--v-ink); margin: 0 0 6px; padding: 0; font-size: 15px; }
    .mr-tf-campo label small { color: var(--v-texto); font-weight: 500; }
    .mr-tf-campo input:not([type=radio]):not([type=checkbox]), .mr-tf-campo select, .mr-tf-campo textarea { width: 100%; min-height: 48px; padding: 10px 14px; border: 1.5px solid var(--v-contorno); border-radius: var(--v-r-campo); font: inherit; font-size: 16px; color: var(--v-ink); background: #faf9f7; }
    .mr-tf-campo textarea { min-height: 96px; resize: vertical; }
    .mr-tf-campo input:focus, .mr-tf-campo select:focus, .mr-tf-campo textarea:focus { outline: 0; border-color: var(--v-ink); box-shadow: 0 0 0 3px rgba(20,20,20,.16); background: #fff; }
    .mr-tf-campo.erro input, .mr-tf-campo.erro select, .mr-tf-campo.erro textarea { border-color: var(--v-red); background: #fff8f8; }
    .mr-tf-opcoes { display: flex; flex-wrap: wrap; gap: 8px 18px; }
    .mr-tf-opcoes label, .mr-tf-check { display: flex; align-items: flex-start; gap: 10px; font-weight: 600; color: var(--v-ink); margin: 0; cursor: pointer; min-height: 24px; }
    .mr-tf-opcoes label > span { min-width: 0; }
    .mr-tf-opcoes input, .mr-tf-check input { width: 20px; height: 20px; margin: 2px 0 0; flex-shrink: 0; accent-color: var(--v-red); }
    .mr-tf-campo .mr-tf-check { display: grid; grid-template-columns: 20px minmax(0, 1fr); gap: 10px; align-items: start; margin: 0; font-size: 14px; font-weight: 400; line-height: 1.5; color: var(--v-texto); }
    .mr-tf-dica { color: var(--v-texto); font-size: 14px; margin: 6px 0 0; }
    .mr-tf-dica a { color: var(--v-red); text-decoration: underline; }
    .mr-tf-erro { color: #b4141f; font-size: 14px; font-weight: 600; margin: 6px 0 0; }
    .mr-tf-erro.geral { background: var(--v-aviso-bg); border: 1px solid var(--v-aviso-borda); border-radius: 12px; padding: 10px 14px; margin: 16px 0 0; }
    .mr-tf-situacao { margin: 18px 0; padding: 14px 18px; border-left: 4px solid var(--v-contorno); background: var(--v-bg); border-radius: 0 12px 12px 0; color: var(--v-ink); }
    .mr-tf-situacao.fechada { border-left-color: var(--v-green); }
    .mr-tf-situacao.lista { border-left-color: var(--v-red); }
    .mr-tf-situacao.aviso { border-left-color: #b7791f; background: #fffaf0; }
    #tf-dados { margin-top: 4px; }
    #tf-enviar, #aviso-enviar { margin-top: 20px; min-height: 52px; min-width: 260px; }
    #tf-enviar:disabled { background: #e7e4df; border-color: #e7e4df; color: var(--v-texto); cursor: not-allowed; }
    .mr-tf-armadilha { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
    .mr-tf-ok { text-align: center; padding: 18px 0; }
    .mr-tf-ok:focus { outline: 0; }
    .mr-tf-ok svg { width: 40px; height: 40px; color: var(--v-green); margin: 0 auto; }
    .mr-tf-ok h3, .mr-tf-ok h2 { color: var(--v-titulo); margin: 10px 0 6px; font-size: 22px; }
    .mr-tf-ok .v-btn { margin-top: 16px; }

    /* --- responsivo ------------------------------------------------------------------------------------ */
    @media (max-width: 1439px) {
      .v-cab-cta-longo { display: none; }
      .v-cab-cta-curto { display: inline; }
    }
    @media (max-width: 1360px) {
      .v-nav a { padding: 0 7px; }
      .v-nav a::after { left: 7px; right: 7px; }
    }
    @media (max-width: 1279px) {
      .v-cab-barra { gap: 18px; }
      .v-marca-escola { display: none; }
    }
    @media (max-width: 1060px) {
      .v-hero-grade { gap: 40px; grid-template-columns: 1fr .9fr; }
      .v-faq-grade { gap: 48px; }
      .v-como-grade { gap: 56px; }
      .v-curso-topo-grade { grid-template-columns: minmax(0, 1fr) 380px; gap: 0 40px; }
      .v-curso-corpo { grid-template-columns: minmax(0, 1fr) 340px; gap: 0 36px; }
    }
    @media (max-width: 980px) {
      .v-nav, .v-cab-acoes { display: none; }
      .v-menu-botao { display: inline-flex; }
      .v-marca-escola { display: flex; }
      .v-hero-grade { grid-template-columns: 1fr; gap: 36px; padding: 72px 0 120px; }
      .v-hero::before { background: linear-gradient(180deg, rgba(11,11,11,.8) 0%, rgba(11,11,11,.72) 60%, rgba(11,11,11,.6) 100%); }
      .v-hero-cartao { max-width: 560px; }
      .v-curso-topo-grade { grid-template-columns: minmax(0, 1fr); grid-template-areas: "texto" "cartao" "prova"; min-height: 0; gap: 26px; padding: 40px 0; }
      .v-curso-topo .v-cartao { max-width: 560px; }
      .v-prova { margin-top: 0; }
      .v-curso-corpo { grid-template-columns: minmax(0, 1fr); }
      .v-inv { grid-column: 1; grid-row: auto; position: static; margin-bottom: 44px; max-width: 640px; }
      .v-curso-corpo > .v-bloco:nth-child(2) { padding-top: 44px; border-top: 1px solid var(--v-line); }
      .v-cert-grade, .v-escola-grade, .v-faq-grade { grid-template-columns: 1fr; }
      .v-sede { min-height: 380px; }
    }
    @media (max-width: 980px) {
      .v-faixa-topo p { display: none; }
      .v-faixa-topo .v-wrap { justify-content: center; }
    }
    @media (max-width: 860px) {
      .v-faixa-longa { display: none; }
      .v-faixa-curta { display: inline; }
      .v-grade { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .v-card, html[data-curso] .v-card { grid-column: span 1; }
      .v-card-largo { grid-column: span 2; }
      html[data-curso] .v-card-largo { grid-column: span 1; }
      .v-destaques ul { grid-template-columns: repeat(2, 1fr); }
      .v-destaques li:nth-child(3) { border-left: 0; }
      .v-destaques li:nth-child(n+3) { border-top: 1px solid var(--v-line); }
      .v-como-grade { grid-template-columns: 1fr; gap: 36px; }
      .v-cab-secao { flex-direction: column; align-items: flex-start; gap: 16px; }
      .v-fotos, .v-curso .v-fotos { grid-template-columns: repeat(2, 1fr); }
      .v-empresas-grade { grid-template-columns: 1fr; gap: 24px; }
      .v-rodape-grade { grid-template-columns: 1fr 1fr; }
      .v-rodape-marca { grid-column: 1 / -1; }
      .v-depo-grade { grid-template-columns: 1fr; }
      .v-chegar-curso-grade { grid-template-columns: 1fr; }
      .v-turma-linha { grid-template-columns: auto minmax(0, 1fr); }
      .v-turma-linha .v-btn { grid-column: 1 / -1; }
    }
    @media (max-width: 650px) {
      html[data-curso] .v-faixa-topo { display: none; }
      .v-marca { gap: 14px; }
      .v-marca img { width: 104px; }
      .v-marca-escola { padding-left: 12px; }
      .v-marca-escola b { font-size: 15px; }
      .v-marca-escola span { font-size: 10px; }
      .v-secao, .v-curso-corpo-sec { padding: 64px 0; }
      .v-cursos { padding-top: 64px; }
      .v-h2 { font-size: 34px; letter-spacing: -.05em; }
      .v-h2-bloco { font-size: 28px; }
      .v-btn { font-size: 16px; }
      .v-hero { min-height: 0; }
      .v-hero-grade { padding: 44px 0 104px; gap: 30px; }
      .v-hero-h1 { font-size: 38px; line-height: .98; letter-spacing: -.055em; margin-top: 16px; }
      .v-hero-lead { font-size: 17px; margin-top: 16px; }
      .v-hero-acoes { margin-top: 24px; }
      .v-hero-acoes .v-btn { width: 100%; }
      .v-selos { gap: 8px 16px; }
      .v-flutuante { border-radius: 21px; padding: 20px; }
      .v-destaque-titulo { font-size: 24px; }
      .v-destaques ul { grid-template-columns: 1fr; }
      .v-destaques li { border-left: 0; border-top: 1px solid var(--v-line); padding: 20px 22px; }
      .v-destaques li:first-child { border-top: 0; }
      .v-grade { grid-template-columns: 1fr; gap: 12px; }
      .v-card, .v-card-largo, html[data-curso] .v-card, html[data-curso] .v-card-largo { grid-column: 1 / -1; }
      .v-card-titulo { font-size: 21px; }
      .v-card-largo .v-card-titulo { font-size: 26px; }
      .v-card-largo .v-card-foto { height: 225px; }
      .v-card-compacto { display: grid; grid-template-columns: 96px minmax(0, 1fr); gap: 14px; padding: 14px; border-radius: 20px; }
      .v-card-compacto .v-card-foto { height: 72px; width: 96px; border-radius: 14px; }
      /* sem a seta no card compacto: em 360–390 px ela empurrava o preço para 2 linhas (o card inteiro é o link) */
      .v-card-compacto .v-card-foto::after, .v-card-compacto .v-selo, .v-card-compacto .v-card-apoio, .v-card-compacto .v-card-desc, .v-card-compacto .v-preco, .v-card-compacto .v-card-seta { display: none; }
      .v-card-compacto .v-card-corpo { padding: 0; }
      .v-card-compacto .v-card-estado { display: block; font-size: 11px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: var(--v-muted); line-height: 1.2; margin-bottom: 4px; }
      .v-card-compacto .v-card-titulo { font-size: 18px; }
      .v-card-compacto .v-chips { margin: 8px 0 10px; gap: 4px; }
      .v-card-compacto .v-chip { font-size: 11px; padding: 5px 6px; letter-spacing: .03em; }
      .v-card-compacto .v-chip:nth-child(3) { display: none; }  /* "Presencial": todos são; no card compacto só carga e escolaridade */
      .v-card-compacto .v-card-rodape { padding-top: 10px; gap: 10px; }
      /* preço do card compacto: a matrícula é o número maior e o total à vista tem linha própria, 14 px/700 (princípio 2) */
      .v-card-compacto .v-preco-compacto { display: block; line-height: 1.35; }
      .v-pc-mat { font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--v-muted); }
      .v-pc-mat b { margin-left: 4px; font-size: 18px; font-weight: 800; letter-spacing: -.03em; text-transform: none; color: var(--v-titulo); }
      .v-pc-taxa { font-size: 13px; color: var(--v-muted); }
      .v-pc-total { margin-top: 2px; font-size: 14px; font-weight: 700; color: var(--v-ink); }

      .v-como-h2 { font-size: 42px; }
      .v-passos li { grid-template-columns: 52px 1fr; padding: 22px 0; }
      .v-painel { padding: 30px 22px; }
      .v-painel dl div { grid-template-columns: 1fr; gap: 4px; }
      .v-painel dd { text-align: left; }
      .v-sede { min-height: 300px; }
      .v-fotos { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; scroll-padding-inline: 16px; gap: 12px; margin: 0 -16px; padding: 0 16px 6px; scrollbar-width: none; }
      .v-fotos::-webkit-scrollbar { display: none; }
      .v-fotos li { flex: 0 0 76%; scroll-snap-align: start; }
      .v-curso .v-fotos li { flex-basis: 64%; }
      .v-chegar { min-height: 0; display: flex; flex-direction: column; }
      .v-mapa { position: relative; height: 260px; }
      .v-chegar-cartao { margin: 0; max-width: none; border-radius: 0; box-shadow: none; }
      .v-credito { top: 222px; bottom: auto; }
      .v-faq-resp { padding-right: 0; }
      .v-faq-lista summary { padding: 18px 0; }
      .v-empresas { padding: 56px 0; }
      .v-empresas .v-btn { width: 100%; }
      .v-final-acoes .v-btn { width: 100%; }
      .v-rodape-grade { grid-template-columns: 1fr; gap: 32px; padding: 48px 0 32px; }

      /* modo curso no celular */
      .v-curso-topo::before { background: linear-gradient(180deg, rgba(11,11,11,.84) 0%, rgba(11,11,11,.7) 100%); }
      .v-curso-topo-grade { padding: 6px 0 24px; gap: 16px; }
      .v-voltar { min-height: 32px; }
      .v-curso-texto .v-olho { display: none; }
      .v-curso-h1 { font-size: 38px; line-height: 1; letter-spacing: -.055em; margin-top: 6px; }
      .v-curso-apoio { font-size: 16px; margin-top: 8px; line-height: 1.45; }
      .v-chips-topo { margin-top: 12px; gap: 6px; }
      .v-chips-topo li { min-height: 30px; padding: 4px 10px; font-size: 12px; gap: 6px; }
      .v-chips-topo svg { width: 13px; height: 13px; }
      .v-chips-topo li:not(.v-chip-data) svg { display: none; }  /* duas linhas de chips em 390 px */
      .v-cartao-dataline { margin-top: 10px; gap: 12px; }
      .v-data { min-width: 72px; padding: 8px 12px; }
      .v-data b { font-size: 24px; margin: 3px 0; }
      .v-cartao-dia { font-size: 17px; }
      .v-cartao-hora, .v-cartao-insc, .v-cartao-local { font-size: 13px; margin-top: 2px; }
      .v-cartao .v-destaque-linha { margin: 12px 0 10px; }
      .v-cartao .v-preco-valor { font-size: 28px; }
      .v-cartao .v-btn { margin-top: 12px; }
      .v-nota-pagamento { margin-top: 10px; }
      .v-devolucao { margin-top: 8px; }
      .v-prova { flex-direction: column; gap: 0; background: #fff; border-radius: var(--v-r-caixa); padding: 6px 16px; }
      .v-prova li { color: var(--v-ink); padding: 10px 0; border-top: 1px solid var(--v-line); }
      .v-prova li:first-child { border-top: 0; }
      .v-prova svg { color: var(--v-red); }
      .v-curso-corpo-sec { padding-top: 32px; }
      .v-inv { padding: 22px 20px; margin-bottom: 36px; }
      .v-bloco { padding: 36px 0; }
      .v-passos-curso li { grid-template-columns: 36px minmax(0, 1fr); gap: 12px; padding: 16px; }
      .v-passos-curso li::before { width: 36px; height: 36px; font-size: 16px; }
      .v-passos-curso h3 { padding-top: 6px; font-size: 17px; }
      .v-turma-linha { grid-template-columns: 1fr; }
      .v-turma-linha .v-data { justify-self: start; }
      .v-turma-botoes .v-btn { width: 100%; }
      .v-curso-faq { padding-bottom: 64px; }
      .v-chegar-curso { padding-bottom: 64px; }

      .mr-modal { width: 100vw; max-width: 100vw; height: 100vh; height: 100dvh; max-height: none; margin: 0; border-radius: 0; padding: 16px 16px 32px; }
      .mr-tf-grade { grid-template-columns: 1fr; }
      #tf-enviar, #aviso-enviar { width: 100%; min-width: 0; }
      html body .cv-chat .cv-chat-abrir { width: 56px; padding: 0; justify-content: center; }
      html body .cv-chat .cv-chat-abrir-rotulo { position: absolute; width: 1px; height: 1px; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); clip-path: inset(50%); white-space: nowrap; }
    }
    @media (min-width: 651px) {
      .v-barra { display: none !important; }
      html.mr-barra-on body { padding-bottom: 0; }
    }
    @media (max-width: 400px) {
      .v-marca img { width: 88px; }
      .v-marca-escola b { font-size: 13px; }
      .v-marca-escola span { font-size: 9.5px; letter-spacing: .06em; }
    }
    @media (max-width: 380px) {
      .v-faixa-curta { display: none; }
      .v-faixa-mini { display: inline; }
      .v-faixa-topo a { white-space: nowrap; }
    }
    @media (max-width: 360px) {
      .v-cab-barra { gap: 10px; }
      .v-marca { gap: 10px; }
      .v-marca img { width: 76px; }
      .v-marca-escola { padding-left: 10px; }
      .v-marca-escola b { font-size: 12px; }
      .v-marca-escola span { font-size: 9px; }
      /* barra em 320 px: rótulo e valor na mesma linha quando cabem; o botão encolhe (14 px) e nada passa por baixo dele */
      .v-barra { padding-left: 12px; padding-right: 12px; gap: 8px; }
      .v-barra-texto { flex: 1 1 auto; flex-direction: row; flex-wrap: wrap; align-items: baseline; column-gap: 6px; }
      .v-barra-agora { flex-basis: 100%; white-space: normal; }
      .v-barra-longo { display: none; }
      .v-barra-curto { display: inline; }
      .v-barra .v-btn { padding: 12px 12px; font-size: 14px; gap: 6px; }
      .v-barra .v-btn svg { width: 16px; height: 16px; }
      /* botões grandes em 320 px: uma linha só ("Reservar minha vaga →") */
      .v-btn-grande { padding-left: 16px; padding-right: 16px; font-size: 15px; gap: 8px; }
      .v-hero-h1, .v-curso-h1 { font-size: 34px; }
    }
    @@CSS_MODO@@
  </style>"""


# --- JS da página (modo curso, links, medição da seção 7.1, barra, menu, aviso da data) ------------------------------
JS_PAGINA = """
  <script>
    (function () {
      var CHECKOUT_URL = __CHECKOUT__;
      var INSCRICAO = __INSCRICAO__;
      var INSC_CURTO = __INSC_CURTO__;
      var CURSOS = __CURSOS__;
      var EXIBICAO = __EXIBICAO__;
      var API_TURMAS = __API_TURMAS__;
      var LISTA = 'Cursos presenciais';
      var raiz = document.documentElement;
      var TITULO_GERAL = document.title;
      var celular = window.matchMedia ? window.matchMedia('(max-width: 650px)') : { matches: false };
      // O clique num card troca a URL com pushState: sem isto, o Pixel mandaria um PageView a mais a cada curso aberto
      // (sem o id do repasse à API de Conversões). O curso aberto já sai como ViewContent.
      try { if (window.fbq) window.fbq.disablePushState = true; } catch (e) {}
      function $(id) { return document.getElementById(id); }
      // MediaQueryList.addEventListener não existe no Safari/iOS até a versão 13: lá, addListener.
      function aoMudar(mq, f) { if (!mq) return; if (mq.addEventListener) mq.addEventListener('change', f); else if (mq.addListener) mq.addListener(f); }
      function todos(sel, base) { return Array.prototype.slice.call((base || document).querySelectorAll(sel)); }
      function ga(evento, dados) { try { if (window.gtag) window.gtag('event', evento, dados || {}); } catch (e) {} }
      function modo() { return raiz.getAttribute('data-curso') || ''; }
      function breve(s) { return (' ' + (raiz.getAttribute('data-breve') || '') + ' ').indexOf(' ' + s + ' ') >= 0; }
      function estado(s) { return CURSOS[s] && CURSOS[s].turma && !breve(s) ? 'aberta' : 'breve'; }
      function item(s) { return { item_id: s, item_name: CURSOS[s].nome, item_category: 'Cursos presenciais', item_category2: estado(s), price: INSCRICAO, quantity: 1 }; }

      // UTMs, fbclid e gclid da URL atual vão junto para o checkout (os links já funcionam sem JavaScript).
      var extras = new URLSearchParams();
      new URLSearchParams(location.search).forEach(function (v, k) { if (/^(utm_|fbclid$|gclid$)/.test(k)) extras.set(k, v); });
      function comExtras(href) {
        try { var u = new URL(href, location.origin); extras.forEach(function (v, k) { u.searchParams.set(k, v); }); return u.pathname + u.search; } catch (e) { return href; }
      }
      function linkCheckout(s, local) { return comExtras(CHECKOUT_URL + '?curso=' + encodeURIComponent(s) + '&via=' + local); }
      todos('a.mr-cta[href*="/checkout/"]').forEach(function (a) { a.setAttribute('href', comExtras(a.getAttribute('href'))); });
      // Área do aluno: quem veio de um anúncio leva a campanha; as utm_* do anúncio substituem as nossas.
      var utmAnuncio = [];
      extras.forEach(function (v, k) { if (/^utm_/.test(k)) utmAnuncio.push([k, v]); });
      if (utmAnuncio.length) todos('a[href^="https://escola.cursoscruzvermelha.org"]').forEach(function (a) {
        try {
          var u = new URL(a.href);
          Array.from(u.searchParams.keys()).forEach(function (k) { if (/^utm_/.test(k)) u.searchParams.delete(k); });
          utmAnuncio.forEach(function (par) { u.searchParams.set(par[0], par[1]); });
          a.href = u.toString();
        } catch (e) { /* link fora do padrão: fica como está */ }
      });

      // A faixa do topo abre o site em nova aba só no computador, como na escola.
      var faixa = document.querySelector('.v-faixa-topo a');
      if (faixa && !celular.matches) { faixa.setAttribute('target', '_blank'); faixa.setAttribute('rel', 'noopener'); }
      // Turma vencida no relógio do navegador: os cards leem "turmas em breve".
      todos('[data-aria-breve]').forEach(function (el) { if (breve(el.getAttribute('data-curso'))) el.setAttribute('aria-label', el.getAttribute('data-aria-breve')); });
      // O dialog de turmas fica na seção #empresas para quem está sem JavaScript; com JavaScript vai para o fim do
      // <body>, porque a seção some no modo curso e o Investimento também abre o formulário.
      var turmaBloco = $('turma-form-bloco');
      if (turmaBloco) document.body.appendChild(turmaBloco);

      // --- modo curso -----------------------------------------------------------------------------------------
      function trocarTag(el, tag) {
        if (!el || el.tagName.toLowerCase() === tag) return el;
        var novo = document.createElement(tag);
        for (var i = 0; i < el.attributes.length; i++) novo.setAttribute(el.attributes[i].name, el.attributes[i].value);
        while (el.firstChild) novo.appendChild(el.firstChild);
        el.parentNode.replaceChild(novo, el);
        return novo;
      }
      var barra = $('mr-barra'), barraCta = $('mr-barra-cta'), barraNome = $('mr-barra-nome'), barraSub = $('mr-barra-sub');
      var cabCta = $('v-cab-cta');
      function aplicarModo(s) {
        // Um título principal só (h1): no modo curso, o do curso; no geral, o do topo.
        todos('.v-curso-h1').forEach(function (el) { var c = el.closest('.v-curso'); trocarTag(el, c && c.getAttribute('data-curso') === s ? 'h1' : 'h2'); });
        trocarTag(document.querySelector('.v-hero-h1'), s ? 'p' : 'h1');
        // Menu: no modo curso, só âncoras do curso ativo (nenhum link para seção escondida).
        todos('[data-alvo]').forEach(function (a) {
          var alvo = a.getAttribute('data-alvo').split('|');
          a.setAttribute('href', s ? (alvo[1] === 'cursos' ? '#cursos' : '#' + alvo[1] + '-' + s) : '#' + alvo[0]);
        });
        todos('.v-curso').forEach(function (el) { el.classList.toggle('ativo', el.getAttribute('data-curso') === s); });
        ajustarInv();
        document.title = s ? CURSOS[s].titulo : TITULO_GERAL;
        var pular = document.querySelector('.v-pular');
        if (pular) pular.setAttribute('href', s ? '#inv-' + s : '#cursos');
        if (s && cabCta) {
          cabCta.setAttribute('href', linkCheckout(s, 'cabecalho'));
          cabCta.setAttribute('data-curso', s);
          cabCta.querySelector('.v-cab-cta-longo').textContent = estado(s) === 'aberta' ? 'Reservar minha vaga' : 'Inscrever-se';
          cabCta.querySelector('.v-cab-cta-curto').textContent = estado(s) === 'aberta' ? 'Reservar vaga' : 'Inscrever-se';
        }
        if (s && barra) {
          var c = CURSOS[s], aberta = estado(s) === 'aberta';
          barraNome.textContent = '';
          var nomeSr = document.createElement('span'); nomeSr.className = 'v-sr'; nomeSr.textContent = c.nome + ': ';
          barraNome.appendChild(nomeSr); barraNome.appendChild(document.createTextNode(c.total_txt));
          barraSub.textContent = 'Agora: taxa de ' + INSC_CURTO;
          barraCta.setAttribute('href', linkCheckout(s, 'barra'));
          barraCta.setAttribute('data-curso', s);
          barraCta.querySelector('.v-barra-longo').textContent = aberta ? 'Reservar vaga' : 'Inscrever-se';
          barraCta.querySelector('.v-barra-curto').textContent = aberta ? 'Reservar' : 'Inscrever-se';
        }
        avaliarBarra();
      }
      // O Investimento fica fixo na lateral só se couber inteiro na tela (cabeçalho + card + folga); senão, rola com a
      // página, para "Grupo de 15 a 30" e "Dúvidas?" não ficarem para fora.
      function ajustarInv() {
        todos('.v-inv').forEach(function (inv) {
          inv.classList.remove('v-inv-solto');
          if (!inv.getClientRects().length) return;
          var cs = window.getComputedStyle(inv);
          if (cs.position === 'sticky' && inv.offsetHeight + (parseFloat(cs.top) || 0) + 16 > window.innerHeight) inv.classList.add('v-inv-solto');
        });
      }
      var vistos = {};
      function verCurso(s, origem) {
        if (!CURSOS[s] || vistos[s]) return;
        vistos[s] = true;
        var c = CURSOS[s], id = '';
        try { if (window.cvrjMedicao && window.cvrjMedicao.novoId) id = window.cvrjMedicao.novoId('vc'); } catch (e) {}
        var dados = { content_name: c.nome, content_ids: [s], content_type: 'product', content_category: 'matricula-cursos-presenciais', value: INSCRICAO, currency: 'BRL',
                      estado_turma: estado(s), valor_matricula: c.matricula, total_a_vista: c.total };
        try { if (window.fbq) window.fbq('track', 'ViewContent', dados, id ? { eventID: id } : undefined); } catch (e) {}
        try { if (id) window.cvrjMedicao.servidor('ViewContent', id, s); } catch (e) {}
        ga('view_item', { currency: 'BRL', value: INSCRICAO, item_list_name: LISTA, origem: origem, estado_turma: estado(s), valor_matricula: c.matricula, total_a_vista: c.total, items: [item(s)] });
        ga('view_course_details', { curso: s, origem: origem, modo: 'curso' });
      }
      function urlCom(s) {
        var q = new URLSearchParams(location.search);
        q.delete('turma'); q.delete('turma_curso');
        if (s) q.set('curso', s); else q.delete('curso');
        var t = q.toString();
        return location.pathname + (t ? '?' + t : '');
      }
      // Troca de modo por clique ou teclado: o foco vai para o título do que abriu (leitor de tela anuncia, e o Tab
      // seguinte continua dali). Na chegada pela URL, o foco não muda.
      function focar(el) {
        if (!el) return;
        if (!el.hasAttribute('tabindex') && !/^(A|BUTTON|INPUT|SELECT|TEXTAREA)$/.test(el.tagName)) el.setAttribute('tabindex', '-1');
        try { el.focus({ preventScroll: true }); } catch (e) { try { el.focus(); } catch (e2) {} }
      }
      function entrar(s, origem, empurrar) {
        if (!CURSOS[s]) return;
        raiz.setAttribute('data-curso', s);
        aplicarModo(s);
        if (empurrar) {
          try { history.pushState({ curso: s }, '', urlCom(s)); } catch (e) {}
          window.scrollTo(0, 0);
          focar(document.querySelector('.v-curso.ativo .v-curso-h1'));
        }
        verCurso(s, origem);
      }
      function sair(alvo, empurrar) {
        var estava = modo();
        raiz.removeAttribute('data-curso');
        aplicarModo('');
        var destino = urlCom('') + (alvo ? '#' + alvo : '');
        // O logo, já no modo geral, só rola ao topo: nada de empilhar a mesma entrada no histórico.
        if (empurrar && (estava || location.pathname + location.search + location.hash !== destino)) { try { history.pushState({}, '', destino); } catch (e) {} }
        var el = alvo ? $(alvo) : null;
        if (el) el.scrollIntoView(); else window.scrollTo(0, 0);
        if (empurrar) focar(el ? (el.querySelector('h1, h2') || el) : document.querySelector('.v-hero-h1'));
      }
      try { if ('scrollRestoration' in history) history.scrollRestoration = 'manual'; } catch (e) {}
      window.addEventListener('popstate', function () {
        var q = new URLSearchParams(location.search), s = q.get('curso');
        if (s && CURSOS[s] && !q.get('turma')) { if (modo() !== s) { raiz.setAttribute('data-curso', s); aplicarModo(s); window.scrollTo(0, 0); verCurso(s, 'url'); } }
        else if (modo()) { raiz.removeAttribute('data-curso'); aplicarModo(''); var c = $('cursos'); if (c) c.scrollIntoView(); }
      });
      document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target && e.target.closest ? e.target.closest('a') : null;
        if (!a) return;
        var s = a.getAttribute('data-abrir-curso');
        if (s && CURSOS[s]) {
          e.preventDefault();
          var local = a.getAttribute('data-local-curso') || (modo() ? 'outros_cursos' : 'card');
          ga('select_course', { curso: s, local: local, modo: modo() ? 'curso' : 'geral' });
          fecharMenu();
          entrar(s, local === 'destaque_topo' ? 'destaque' : 'card', true);
          return;
        }
        if (a.hasAttribute('data-voltar')) { e.preventDefault(); fecharMenu(); sair(a.getAttribute('data-voltar'), true); return; }
        // Link interno para uma seção do modo geral, de dentro do modo curso (rodapé): volta ao modo geral.
        var href = a.getAttribute('href') || '';
        if (modo() && href.charAt(0) === '#' && href.length > 1 && !a.hasAttribute('data-abrir-chat') && !a.hasAttribute('data-turma-abrir')) {
          var alvo = $(href.slice(1));
          if (alvo && alvo.closest('.v-so-geral')) { e.preventDefault(); fecharMenu(); sair(href.slice(1), true); }
        }
      });

      // --- cliques medidos: inscrição (select_item + click_enroll), devolução, chat, turma e saída para a escola --
      document.addEventListener('click', function (e) {
        var alvo = e.target && e.target.closest ? e.target : null;
        if (!alvo) return;
        var cta = alvo.closest('a.mr-cta[data-curso]');
        if (cta && cta.getAttribute('data-curso') && /\\/checkout\\//.test(cta.getAttribute('href') || '')) {
          var s = cta.getAttribute('data-curso'), local = cta.getAttribute('data-local') || '', c = CURSOS[s] || {};
          ga('select_item', { currency: 'BRL', value: INSCRICAO, item_list_name: LISTA, local: local, modo: modo() ? 'curso' : 'geral', estado_turma: CURSOS[s] ? estado(s) : '', total_a_vista: c.total, items: CURSOS[s] ? [item(s)] : [] });
          ga('click_enroll', { curso: s, local: local, modo: modo() ? 'curso' : 'geral', estado_turma: CURSOS[s] ? estado(s) : '', total_a_vista: c.total });
        }
        var dev = alvo.closest('[data-devolucao]');
        if (dev) ga('devolucao_clique', { local: dev.getAttribute('data-devolucao'), curso: modo() });
        var atalho = alvo.closest('[data-abrir-chat][data-local]');
        if (atalho) ga('chat_atalho', { local: atalho.getAttribute('data-local'), curso: atalho.getAttribute('data-curso') || modo() });
        var turma = alvo.closest('[data-turma-abrir]');
        if (turma) ga('contact_company_training', { local: turma.getAttribute('data-turma-abrir') || '', curso: turma.getAttribute('data-curso') || modo() });
        var escola = alvo.closest('a[href*="escola.cursoscruzvermelha.org"]');
        if (escola) {
          var lugar = escola.getAttribute('data-saida') || 'outro';
          var caminho = '';
          try { caminho = new URL(escola.href).pathname; } catch (err) {}
          var destino = /^\\/login/.test(caminho) ? 'login' : /^\\/cursos\\/./.test(caminho) ? 'curso' : /^\\/cursos/.test(caminho) ? 'catalogo' : 'home';
          ga('saida_escola', { local: lugar, origem: escola.getAttribute('data-origem') || '', curso: modo(), destino: destino, tela: 'matricula', transport_type: 'beacon' });
          try { if (window.fbq) window.fbq('trackCustom', 'SaidaEscola', { content_ids: [modo()], content_category: lugar }); } catch (err) {}
        }
      }, true);

      // Perguntas abertas pela pessoa (uma vez por pergunta). O clique no <summary> (Enter e Espaço também geram clique)
      // marca o <details>; o toggle que o navegador dispara para a 1ª pergunta, aberta no HTML, não conta.
      var faqVistas = {};
      document.addEventListener('click', function (e) {
        var sum = e.target && e.target.closest ? e.target.closest('summary') : null;
        if (sum && sum.parentNode && sum.parentNode.tagName === 'DETAILS') sum.parentNode.__vPessoa = true;
      }, true);
      document.addEventListener('toggle', function (e) {
        var d = e.target;
        if (!d || d.tagName !== 'DETAILS') return;
        var pelaPessoa = !!d.__vPessoa;
        d.__vPessoa = false;
        if (!d.open || !pelaPessoa) return;
        var curso = d.closest('.v-curso');
        var bloco = curso ? 'curso' : d.closest('#duvidas') ? 'pagina' : '';
        if (!bloco) return;
        var sum = d.querySelector('summary');
        var pergunta = sum ? sum.textContent.replace(/\\s+/g, ' ').trim().slice(0, 100) : '';
        var chave = bloco + '|' + (curso ? curso.getAttribute('data-curso') : '') + '|' + pergunta;
        if (faqVistas[chave]) return;
        faqVistas[chave] = true;
        ga('faq_aberta', { bloco: bloco, pergunta: pergunta, posicao: parseInt(d.getAttribute('data-pergunta') || '0', 10), curso: curso ? curso.getAttribute('data-curso') : '' });
      }, true);

      // Mapa: carrega só quando a pessoa pede (desempenho e cookies de terceiros).
      var mapaBotao = $('mr-mapa-carregar');
      function abrirMapa(origem) {
        var mapa = $('mr-mapa');
        if (!mapa || !mapaBotao || mapa.querySelector('iframe')) return;
        var f = document.createElement('iframe');
        f.src = mapaBotao.getAttribute('data-src');
        f.title = 'Mapa: Praça da Cruz Vermelha, 10, Centro, Rio de Janeiro';
        f.loading = 'lazy';
        f.referrerPolicy = 'no-referrer-when-downgrade';
        f.allowFullscreen = true;
        mapa.innerHTML = '';
        mapa.appendChild(f);
        var legenda = document.createElement('figcaption');
        legenda.textContent = 'Praça da Cruz Vermelha, 10 — Centro, Rio de Janeiro · mapa do Google';
        mapa.appendChild(legenda);
        if (origem === 'mapa') { try { f.focus(); } catch (e) {} }
        ga('mapa_aberto', { origem: origem });
      }
      if (mapaBotao) mapaBotao.addEventListener('click', function () { abrirMapa('botao'); });
      todos('[data-mapa-abrir]').forEach(function (b) { b.addEventListener('click', function () { abrirMapa('mapa'); }); });

      // --- menu do celular (tela cheia, foco preso, Esc fecha, inert no resto) -------------------------------
      var cab = document.querySelector('.v-cab'), botaoMenu = $('v-menu-botao'), menu = $('v-menu');
      function menuAberto() { return !!(menu && !menu.hidden); }
      function marcarMenu(sim) {
        if (!menu || !botaoMenu) return;
        menu.hidden = !sim;
        botaoMenu.setAttribute('aria-expanded', sim ? 'true' : 'false');
        botaoMenu.setAttribute('aria-label', sim ? 'Fechar menu' : 'Abrir menu');
        raiz.classList.toggle('v-menu-aberto', sim);
        todos('body > *').forEach(function (el) {
          if (el === cab || el.tagName === 'SCRIPT' || el.tagName === 'DIALOG') return;
          if (sim) el.setAttribute('inert', ''); else el.removeAttribute('inert');
        });
        avaliarBarra();
      }
      function fecharMenu() { if (menuAberto()) marcarMenu(false); }
      if (botaoMenu) botaoMenu.addEventListener('click', function () {
        var abrir = !menuAberto();
        marcarMenu(abrir);
        if (abrir) { var primeiro = menu.querySelector('a, button'); if (primeiro) primeiro.focus(); }
      });
      if (menu) menu.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a, [data-abrir-chat]') : null;
        if (!a || a.hasAttribute('data-abrir-curso') || a.hasAttribute('data-voltar')) return;
        // O chat.js abre o painel e põe o foco nele neste mesmo clique (ouvinte no document, depois deste): o resto da
        // página precisa sair do inert antes. Os outros links fecham o menu depois de seguir.
        if (a.hasAttribute('data-abrir-chat')) marcarMenu(false); else setTimeout(fecharMenu, 0);
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && menuAberto()) { marcarMenu(false); botaoMenu.focus(); }
        if (e.key === 'Tab' && menuAberto()) {
          var focaveis = [botaoMenu].concat(todos('a, button', menu));
          var i = focaveis.indexOf(document.activeElement);
          if (e.shiftKey && i <= 0) { e.preventDefault(); focaveis[focaveis.length - 1].focus(); }
          else if (!e.shiftKey && i === focaveis.length - 1) { e.preventDefault(); focaveis[0].focus(); }
        }
      });
      aoMudar(window.matchMedia ? window.matchMedia('(min-width: 981px)') : null, function (m) { if (m.matches) fecharMenu(); });

      // --- "Prefiro ser avisado da data" (api/turmas.php, lista de interesse sem telefone) -----------------------
      var aviso = $('aviso-data'), avisoForm = $('aviso-form'), avisoOk = $('aviso-ok'), avisoErro = $('aviso-erro');
      var avisoEnviando = false;
      function avisoCampoErro(nome, msg) {
        var p = $('aviso-erro-' + nome), campo = avisoForm.elements[nome];
        if (!p || !campo) { avisoErro.textContent = msg; avisoErro.hidden = false; avisoErro.focus(); return; }
        p.textContent = msg; p.hidden = false; p.closest('.mr-tf-campo').classList.add('erro');
        campo.setAttribute('aria-invalid', 'true'); campo.setAttribute('aria-describedby', p.id); campo.focus();
      }
      function avisoLimpar() {
        todos('.mr-tf-campo.erro', avisoForm).forEach(function (el) { el.classList.remove('erro'); });
        todos('[aria-invalid]', avisoForm).forEach(function (el) { el.removeAttribute('aria-invalid'); el.removeAttribute('aria-describedby'); });
        todos('.mr-tf-erro', avisoForm).forEach(function (el) { el.textContent = ''; el.hidden = true; });
      }
      function abrirAviso(s) {
        if (!aviso || !CURSOS[s]) return;
        avisoForm.reset(); avisoLimpar(); avisoForm.hidden = false; avisoOk.hidden = true;
        avisoForm.elements.curso.value = s;
        todos('[data-aviso-curso]', aviso).forEach(function (el) { el.textContent = CURSOS[s].nome; });
        if (typeof aviso.showModal === 'function') aviso.showModal(); else aviso.setAttribute('open', '');
        raiz.classList.add('mr-modal-aberto');
        ga('aviso_abrir', { curso: s });
      }
      function fecharAviso() { if (!aviso) return; if (typeof aviso.close === 'function') aviso.close(); else aviso.removeAttribute('open'); raiz.classList.remove('mr-modal-aberto'); }
      document.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('[data-aviso-abrir]') : null;
        if (!b) return;
        e.preventDefault();
        abrirAviso(b.getAttribute('data-curso'));
      });
      if (aviso) {
        aviso.addEventListener('close', function () { raiz.classList.remove('mr-modal-aberto'); });
        todos('[data-aviso-fechar]', aviso).forEach(function (b) { b.addEventListener('click', fecharAviso); });
        aviso.addEventListener('click', function (e) {
          if (e.target !== aviso) return;
          var r = aviso.getBoundingClientRect();
          if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) fecharAviso();
        });
        avisoForm.addEventListener('submit', function (e) {
          e.preventDefault();
          if (avisoEnviando) return;
          avisoLimpar(); avisoErro.hidden = true;
          var f = avisoForm.elements, s = f.curso.value;
          if (f.nome.value.trim().length < 2) return avisoCampoErro('nome', 'Digite seu nome.');
          if (!/^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/.test(f.email.value.trim())) return avisoCampoErro('email', 'Confira o e-mail: falta o @ ou o domínio.');
          if (!f.consentimento.checked) return avisoCampoErro('consentimento', 'Marque a autorização para receber o aviso por e-mail.');
          var origem = {};
          try { var c = window.cvrjMedicao && window.cvrjMedicao.ler(); if (c && c.estatistica) extras.forEach(function (v, k) { origem[k] = v.slice(0, 255); }); } catch (err) {}
          var corpo = { aviso: true, curso: s, idioma: 'pt', pessoas: 1, nome: f.nome.value, email: f.email.value, consentimento: true,
                        pagina: location.pathname + location.search, origem: origem, site: f.site.value };
          try { if (window.cvrjMedicao && window.cvrjMedicao.novoId) corpo.evento_id = window.cvrjMedicao.novoId('ld'); } catch (err) {}
          avisoEnviando = true;
          var botao = $('aviso-enviar'); botao.disabled = true; botao.textContent = 'Enviando…';
          fetch(API_TURMAS, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(corpo) })
            .then(function (r) { return r.json().catch(function () { return null; }).then(function (d) { return { http: r.status, d: d }; }); })
            .catch(function () { return { http: 0, d: null }; })
            .then(function (x) {
              avisoEnviando = false; botao.disabled = false; botao.textContent = 'Avise-me';
              var d = x.d && typeof x.d === 'object' ? x.d : { ok: false, erro: x.http === 0 ? 'Sem conexão. Verifique a internet e tente de novo.' : 'Não conseguimos enviar agora. Tente de novo em instantes.' };
              if (!d.ok) { if (d.campo && avisoForm.elements[d.campo]) avisoCampoErro(d.campo, d.erro); else { avisoErro.textContent = d.erro || 'Não conseguimos enviar agora.'; avisoErro.hidden = false; avisoErro.focus(); } return; }
              // Reenvio do mesmo aviso (o servidor devolve o protocolo que já existia, 'repetido'): não mede de novo.
              if (!d.repetido) {
                try { if (window.fbq) window.fbq('track', 'SubmitApplication', { content_name: CURSOS[s].nome, content_category: 'aviso-data', content_ids: [s] }, corpo.evento_id ? { eventID: corpo.evento_id } : undefined); } catch (err) {}
                ga('turma_pedido', { turma_tipo: 'aviso', curso: s, idioma: 'pt', alunos: 1 });
              }
              $('aviso-ok-texto').textContent = 'Pronto. Quando a data de ' + CURSOS[s].nome + ' sair, avisamos em ' + d.email + '. Guarde o protocolo ' + d.protocolo + '.';
              avisoForm.hidden = true; avisoOk.hidden = false; avisoOk.focus();
            });
        });
      }

      // --- visibilidade: seções, Investimento e aviso vistos, lista e cards, barra fixa e chat -------------------
      var temIO = 'IntersectionObserver' in window;
      function fracao(en) { return Math.max(en.intersectionRatio, en.intersectionRect.height / Math.max(1, window.innerHeight)); }
      var degraus = [0, .1, .2, .3, .4, .5, .6, .7, .8, .9, 1];
      if (temIO) {
        var secoesVistas = {};
        var secaoObs = new IntersectionObserver(function (ents) {
          ents.forEach(function (en) {
            if (!en.isIntersecting || fracao(en) < .4) return;
            var el = en.target, s = el.getAttribute('data-secao'), curso = modo();
            if (s === 'cursos' && curso) s = 'outros_cursos';
            var chave = s + '|' + (el.closest('.v-curso') || s === 'outros_cursos' ? curso : '');
            if (secoesVistas[chave]) return;
            secoesVistas[chave] = true;
            ga('secao_vista', { secao: s, modo: curso ? 'curso' : 'geral', curso: curso });
          });
        }, { threshold: degraus });
        todos('[data-secao]').forEach(function (el) { secaoObs.observe(el); });
        var porUmSegundo = function (sel, evento) {
          var feitos = {};
          var obs = new IntersectionObserver(function (ents) {
            ents.forEach(function (en) {
              var el = en.target, c = el.closest('.v-curso'), s = c ? c.getAttribute('data-curso') : '';
              if (en.isIntersecting && en.intersectionRatio >= .5 && !el.__vTimer && !feitos[s]) {
                el.__vTimer = setTimeout(function () { el.__vTimer = null; if (feitos[s]) return; feitos[s] = true; ga(evento, { curso: s, estado_turma: s ? estado(s) : '' }); }, 1000);
              } else if ((!en.isIntersecting || en.intersectionRatio < .5) && el.__vTimer) { clearTimeout(el.__vTimer); el.__vTimer = null; }
            });
          }, { threshold: [0, .5] });
          todos(sel).forEach(function (el) { obs.observe(el); });
        };
        porUmSegundo('.v-inv', 'investimento_visto');
        porUmSegundo('.v-aviso', 'aviso_matricula_visto');
        var catalogo = $('cursos');
        var listaObs = new IntersectionObserver(function (ents) {
          ents.forEach(function (en) {
            if (fracao(en) >= .3 && !modo()) {
              listaObs.disconnect();
              ga('view_item_list', { item_list_id: 'matricula', item_list_name: LISTA, items: EXIBICAO.map(item) });
            }
          });
        }, { threshold: degraus });
        if (catalogo) listaObs.observe(catalogo);
        var cardsVistos = {};
        var cardObs = new IntersectionObserver(function (ents) {
          ents.forEach(function (en) {
            var s = en.target.getAttribute('data-curso');
            if (!cardsVistos[s] && en.intersectionRatio >= .5) { cardsVistos[s] = true; ga('view_course', { curso: s, modo: modo() ? 'curso' : 'geral' }); }
          });
        }, { threshold: [.5] });
        todos('a.v-card[data-curso]').forEach(function (el) { cardObs.observe(el); });
      }

      // Barra fixa (celular, só no modo curso): some enquanto um botão de inscrição está na tela, no rodapé, com o
      // menu ou um formulário aberto e com o aviso de cookies na tela (as duas camadas não cabem juntas).
      var naTela = 0, fimObs = 0, barraVista = {};
      function avaliarBarra() {
        if (!barra || !temIO) return;
        var avisoCookies = document.querySelector('.cvrj-ck');
        var mostrar = !!modo() && celular.matches && naTela === 0 && fimObs === 0 && !menuAberto() && !raiz.classList.contains('mr-modal-aberto')
          && !(avisoCookies && avisoCookies.getClientRects().length > 0);
        if (mostrar === !barra.hidden) return;
        barra.hidden = !mostrar;
        raiz.classList.toggle('mr-barra-on', mostrar);
        setTimeout(montarChatObs, 0);   // o chat sobe com a barra: a faixa de baixo muda de altura
        if (mostrar && !barraVista[modo()]) { barraVista[modo()] = true; ga('barra_fixa_vista', { curso: modo(), estado_turma: estado(modo()) }); }
      }
      function ctas() { return todos('a.mr-cta').filter(function (el) { return el !== barraCta; }); }
      var ctaVistos = {};
      if (temIO) {
        var ctaObs = new IntersectionObserver(function (ents) {
          ents.forEach(function (en) {
            var el = en.target, d = en.isIntersecting;
            if (d !== !!el.__vNaTela) { el.__vNaTela = d; naTela += d ? 1 : -1; }
            // cta_matricula_visto (docs/rastreamento.md): o 1º botão de inscrição do curso 50% visível por 1 s, uma vez
            // por curso. O do cabeçalho fica sempre na tela e não conta.
            var s = el.getAttribute('data-curso') || '';
            if (el === cabCta || !s) return;
            if (d && en.intersectionRatio >= .5 && !el.__vCtaT && !ctaVistos[s]) {
              el.__vCtaT = setTimeout(function () {
                el.__vCtaT = null;
                if (ctaVistos[s] || modo() !== s) return;
                ctaVistos[s] = true;
                ga('cta_matricula_visto', { curso: s, local: el.getAttribute('data-local') || '', modo: 'curso', estado_turma: estado(s) });
              }, 1000);
            } else if ((!d || en.intersectionRatio < .5) && el.__vCtaT) { clearTimeout(el.__vCtaT); el.__vCtaT = null; }
          });
          avaliarBarra();
        }, { threshold: [0, .5], rootMargin: '-' + (cab ? cab.offsetHeight : 76) + 'px 0px 0px 0px' });
        ctas().forEach(function (el) { ctaObs.observe(el); });
        var fim = new IntersectionObserver(function (ents) {
          ents.forEach(function (en) { if (en.isIntersecting !== !!en.target.__vFim) { en.target.__vFim = en.isIntersecting; fimObs += en.isIntersecting ? 1 : -1; } });
          avaliarBarra();
        });
        var rodape = document.querySelector('.v-rodape');
        if (rodape) fim.observe(rodape);
      }
      // O aviso de cookies entra depois (script adiado) e sai com a escolha: a barra confere de novo nas duas horas.
      if ('MutationObserver' in window) new MutationObserver(function () { avaliarBarra(); }).observe(document.body, { childList: true });
      document.addEventListener('cvrj:consentimento', function () { setTimeout(avaliarBarra, 0); });
      aoMudar(celular, function () { avaliarBarra(); });

      // Chat: o botão some enquanto passa por baixo dele (a faixa de baixo da tela, da altura do botão) um botão de
      // inscrição, o cartão do curso (data, preço, nota, 7 dias, "Prefiro ser avisado"), o destaque do topo, o
      // Investimento ou a faixa de links legais do rodapé. Os escondidos (outro modo) nunca cruzam a faixa.
      var chatObs = null, naFaixa = 0;
      function montarChatObs() {
        if (!temIO) return;
        if (chatObs) chatObs.disconnect();
        naFaixa = 0;
        var botaoChat = document.querySelector('.cv-chat-abrir'), topoChat = botaoChat ? botaoChat.getBoundingClientRect().top : 0;
        if (!(topoChat > 0 && topoChat < window.innerHeight)) topoChat = window.innerHeight - 96;
        chatObs = new IntersectionObserver(function (ents) {
          ents.forEach(function (en) { if (en.isIntersecting !== !!en.target.__vFaixa) { en.target.__vFaixa = en.isIntersecting; naFaixa += en.isIntersecting ? 1 : -1; } });
          raiz.classList.toggle('mr-chat-recolher', naFaixa > 0);
        }, { rootMargin: '-' + Math.max(0, Math.round(topoChat) - 8) + 'px 0px 0px 0px' });
        ctas().concat(todos('.v-cartao, .v-hero-cartao, .v-inv, .v-rodape-faixa')).forEach(function (el) { el.__vFaixa = false; chatObs.observe(el); });
      }
      montarChatObs();
      // Depois das fontes e do chat.js (o botão do chat só existe depois deste script): mede de novo.
      window.addEventListener('load', function () { ajustarInv(); montarChatObs(); });

      // Item do menu da seção visível (traço vermelho).
      var navLinks = todos('.v-nav a[data-alvo]');
      function marcarNav() {
        var melhor = null, melhorY = -Infinity;
        navLinks.forEach(function (a) {
          var el = $((a.getAttribute('href') || '#').slice(1));
          if (!el || !el.getClientRects().length) return;
          if (el.classList.contains('v-inv') && window.getComputedStyle(el).position === 'sticky') return;
          var y = el.getBoundingClientRect().top;
          if (y <= 160 && y > melhorY) { melhorY = y; melhor = a; }
        });
        navLinks.forEach(function (a) { a.classList.toggle('ativo', a === melhor); });
      }
      var agendado = false;
      window.addEventListener('scroll', function () {
        if (agendado) return;
        agendado = true;
        requestAnimationFrame(function () { agendado = false; marcarNav(); avaliarBarra(); });
      }, { passive: true });
      var redim = null;
      window.addEventListener('resize', function () { clearTimeout(redim); redim = setTimeout(function () { ajustarInv(); montarChatObs(); avaliarBarra(); }, 200); });

      // Chegada: ?curso= ou #det-/#curso- (marcados no <head>, antes da primeira pintura).
      var inicial = modo();
      if (inicial && CURSOS[inicial]) {
        var porHash = !new URLSearchParams(location.search).get('curso');
        if (porHash) { try { history.replaceState(null, '', urlCom(inicial)); } catch (e) {} setTimeout(function () { window.scrollTo(0, 0); }, 0); }
        aplicarModo(inicial);
        verCurso(inicial, porHash ? 'hash' : 'url');
      } else {
        if (inicial) raiz.removeAttribute('data-curso');
        aplicarModo('');
      }
      marcarNav();
    })();
  </script>"""


def esc(s: str) -> str:
    return html.escape(s, quote=True)


# Ícones de traço em SVG inline (sem Font Awesome por CDN).
def _svg(caminho: str, traco: float = 2, cheio: bool = False, extra: str = "") -> str:
    atrs = 'fill="currentColor"' if cheio else f'fill="none" stroke="currentColor" stroke-width="{traco}" stroke-linecap="round" stroke-linejoin="round"'
    return f'<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" {atrs}{extra}>{caminho}</svg>'


SVG = {
    "seta": _svg('<path d="M5 12h14M13 6l6 6-6 6"/>', 2.2),
    "voltar": _svg('<path d="M19 12H5M11 6l-6 6 6 6"/>', 2.2),
    "externo": _svg('<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>', 2.2),
    "pino": _svg('<path d="M12 22s7-7.1 7-12a7 7 0 1 0-14 0c0 4.9 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/>'),
    "cruz": _svg('<path d="M9 3h6v6h6v6h-6v6H9v-6H3V9h6z"/>', cheio=True),
    "check": _svg('<path d="m5 12 5 5 9-10"/>', 2.6),
    "check-circulo": _svg('<circle cx="12" cy="12" r="10"/><path d="m8 12 3 3 5-6"/>', 2.2),
    "medalha": _svg('<circle cx="12" cy="8" r="6"/><path d="M15.5 13 17 22l-5-3-5 3 1.5-9"/>'),
    "relogio": _svg('<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>'),
    "capelo": _svg('<path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>'),
    "calendario": _svg('<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>'),
    "chat": _svg('<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>'),
    "predio": _svg('<path d="M3 21h18M5 21V9l7-5 7 5v12M9 21v-6h6v6M9 11h.01M15 11h.01"/>'),
    "exclamacao": _svg('<circle cx="12" cy="12" r="10"/><path d="M12 7v6M12 17h.01"/>', 2.4),
    "menu": _svg('<path d="M4 7h16M4 12h16M4 17h16"/>', 2.4),
    "fechar": _svg('<path d="M6 6l12 12M18 6 6 18"/>', 2.4),
    "email": _svg('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>'),
    "historia": _svg('<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>'),
}


def foto_real(f: dict, sizes: str, loading: str = "lazy", classe: str = "", extra: str = "") -> str:
    """<img> de uma foto de /assets/otim com srcset pelas larguras disponíveis; `pos` vira object-position."""
    srcset = ", ".join(f"/assets/otim/{f['base']}-{w}.webp {w}w" for w in f["larguras"])
    estilo = f' style="object-position:{f["pos"]}"' if f.get("pos") and f["pos"] != "center" else ""
    cls = f' class="{classe}"' if classe else ""
    return (f'<img{cls} src="/assets/otim/{f["base"]}-{f["largura"]}.webp" srcset="{srcset}" sizes="{sizes}" '
            f'width="{f["largura"]}" height="{f["altura"]}" alt="{esc(f["alt"])}" loading="{loading}" decoding="async"{estilo}{extra}>')


def srcset_otim(f: dict) -> str:
    return ", ".join(f"/assets/otim/{f['base']}-{w}.webp {w}w" for w in f["larguras"])


def foto_topo() -> str:
    """Foto do topo geral com direção de arte: paisagem do salão acima de 650 px, retrato da aula no celular. Fica lazy
    (o modo curso esconde o topo e não deve baixá-la); no modo geral, o script do <head> faz o preload da que vale."""
    pc, cel = FOTO_TOPO_PC, FOTO_TOPO
    return (f'<picture><source media="(min-width: 651px)" srcset="{srcset_otim(pc)}" sizes="100vw" width="{pc["largura"]}" height="{pc["altura"]}">'
            f'<img class="v-hero-foto" src="/assets/otim/{cel["base"]}-{cel["largura"]}.webp" srcset="{srcset_otim(cel)}" sizes="100vw" '
            f'width="{cel["largura"]}" height="{cel["altura"]}" alt="{esc(FOTO_TOPO_ALT)}" loading="lazy" decoding="async"></picture>')


# "Cruz Vermelha Brasileira" sozinha é a instituição nacional. Nos textos da filial (inclusive os que vêm
# do catálogo da escola) o nome é sempre o completo, para não confundir as duas (decisão de 19/09/2026).
NACIONAL_SOZINHA = re.compile(r"Cruz Vermelha Brasileira(?!\s*(?:[–\-—·,]|<br>)?\s*(?:Filial|Rio de Janeiro|do Rio|no Rio|RJ\b|Rio\b))")


def nome_filial(texto: str) -> str:
    return NACIONAL_SOZINHA.sub("Cruz Vermelha Brasileira Rio de Janeiro", texto or "")


def brl(centavos: int | None) -> str:
    """R$ 1.049,00 (com centavos): card Investimento e JSON."""
    if centavos is None:
        return "consulte a escola"
    reais = centavos / 100
    return "R$ " + f"{reais:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")


def brl_curto(centavos: int) -> str:
    """R$ 99 / R$ 1.049 (sem centavos quando redondo): cards, cartões e barra."""
    if centavos % 100:
        return brl(centavos)
    return "R$ " + f"{centavos // 100:,}".replace(",", ".")


def horas_iso(carga: str) -> str | None:
    m = re.search(r"(\d+)", carga or "")
    return f"PT{m.group(1)}H" if m else None


def url_estatico(nome: str) -> str:
    """URL do arquivo em static/ com hash do conteúdo: muda o arquivo, muda a URL, o cache não segura versão velha."""
    return f"{STATIC_URL}{nome}?v={hashlib.sha256((STATIC / nome).read_bytes()).hexdigest()[:10]}"


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
    """Cabeçalho, rodapé, CSS, GA4, Pixel e script do menu da home, já ajustados para /matricula-cursos-presenciais/.

    Esta página usa só o GA4, o Pixel (com o aviso de cookies e o cvrjMedicao) e as @font-face da Inter Reserva; o
    cabeçalho, o rodapé, o menu e o CSS continuam aqui porque o checkout, a bio, as políticas, o 404, a doação, o
    verificar, os idiomas e o Dia das Crianças os importam.
    """
    # --- pedaços da home -------------------------------------------------------------
    estilo = bloco(home, "  <style>", "  </style>")                       # primeiro <style>: todo o CSS da home
    # Os url() do CSS são relativos à raiz da home; nas páginas em subpastas viram /pasta/assets/... (404).
    estilo = estilo.replace('url("assets/', 'url("/assets/').replace("url('assets/", "url('/assets/")
    # Aqui o CSS é cópia, não fonte: pode ir minificado. A home guarda a versão legível.
    # SEM_MINIFICAR=1 gera o CSS legível, para comparar o layout das duas versões.
    if os.environ.get("SEM_MINIFICAR") != "1":
        estilo = minificar_css.minificar(estilo)
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


def fontes_da_home(home: str) -> str:
    """As @font-face do <style> da home (Inter Reserva: métricas da Arial/Roboto para a troca de fonte não pular)."""
    estilo = bloco(home, "  <style>", "  </style>")
    regras = re.findall(r"@font-face\s*\{[^}]*\}", estilo)
    if not regras:
        raise SystemExit("o <style> da home não tem as @font-face da Inter Reserva")
    return "\n    ".join(re.sub(r"\s+", " ", r) for r in regras)


def link_escola(local: str, caminho: str = "/login") -> str:
    """Link para a área do aluno, com UTM (medido: saida_escola, local=area_aluno)."""
    return f"{ESCOLA}{caminho}?{UTM_ESCOLA.format(local=local)}".replace("&", "&amp;")


# --- turmas (2.4) --------------------------------------------------------------------------------------------------
def _hora(dt: datetime) -> str:
    return f"{dt.hour}h" + (f"{dt.minute:02d}" if dt.minute else "")


@dataclass
class Turma:
    id: str
    inicio: datetime
    fim: datetime
    local: str
    inscricoes_ate: datetime

    @property
    def mesmo_dia(self) -> bool:
        return self.inicio.date() == self.fim.date()

    @property
    def dia(self) -> str:
        return DIAS[self.inicio.weekday()]

    @property
    def data_extenso(self) -> str:
        return f"{self.inicio.day} de {MESES[self.inicio.month - 1]}"

    @property
    def ddmm(self) -> str:
        return f"{self.inicio.day:02d}/{self.inicio.month:02d}"

    @property
    def horario(self) -> str:
        return f"Das {_hora(self.inicio)} às {_hora(self.fim)}"

    @property
    def linha_data(self) -> str:
        """Quarta, 21 de outubro / Início na quarta, 21 de outubro."""
        if self.mesmo_dia:
            return f"{self.dia.capitalize()}, {self.data_extenso}"
        artigo = "no" if self.inicio.weekday() >= 5 else "na"
        return f"Início {artigo} {self.dia}, {self.data_extenso}"

    @property
    def linha_horario(self) -> str:
        return self.horario + (" · aula única" if self.mesmo_dia else "")

    @property
    def inscricoes(self) -> str:
        a = self.inscricoes_ate
        return f"Inscrições até {DIAS[a.weekday()]}, {a.day:02d}/{a.month:02d}"

    @property
    def chip(self) -> str:
        return f"{self.dia[:3].capitalize()}, {self.ddmm} · {_hora(self.inicio)} às {_hora(self.fim)}"

    @property
    def leitor(self) -> str:
        sufixo = "" if self.inicio.weekday() >= 5 else "-feira"
        return f"{self.dia}{sufixo}, {self.data_extenso} de {self.inicio.year}"

    def bloco_data(self) -> str:
        return (f'<div class="v-data-caixa"><span class="v-sr">{esc(self.leitor)}</span><div class="v-data" aria-hidden="true">'
                f'<span>{esc(self.dia[:3])}</span><b>{self.inicio.day}</b><span>{esc(MESES[self.inicio.month - 1])}</span></div></div>')


def turmas_abertas(curso: dict, agora: datetime) -> list[Turma]:
    """As turmas do cursos.json com inscrições abertas na hora de gerar, da mais próxima para a mais distante."""
    lista = []
    for t in curso.get("turmas") or []:
        try:
            turma = Turma(str(t["id"]), datetime.fromisoformat(t["inicio"]), datetime.fromisoformat(t["fim"]),
                          str(t.get("local") or "Praça da Cruz Vermelha, 10 · Centro"), datetime.fromisoformat(t["inscricoes_ate"]))
        except (KeyError, ValueError, TypeError) as erro:
            raise SystemExit(f"turma inválida em {curso.get('slug')}: {t!r} ({erro})")
        if turma.inicio.tzinfo is None or turma.inscricoes_ate.tzinfo is None:
            raise SystemExit(f"turma sem fuso em {curso.get('slug')}: use -03:00 nas datas")
        if turma.inscricoes_ate >= agora:
            lista.append(turma)
    return sorted(lista, key=lambda x: x.inicio)


def local_junto(local: str) -> str:
    """O local escapado, com "10 · Centro" sem quebra: o "·" não fica sozinho no fim da linha."""
    antes, sep, depois = esc(local).rpartition(" · ")
    if not sep:
        return depois
    inicio, _, ultima = antes.rpartition(" ")
    return (inicio + " " if inicio else "") + f"{ultima}&nbsp;·&nbsp;{depois}"


def juntar(itens: list[str]) -> str:
    """"A", "A e B", "A, B e C"."""
    return itens[0] if len(itens) == 1 else ", ".join(itens[:-1]) + " e " + itens[-1]


def main() -> int:
    if PAGAMENTO != "A" or MATRICULA_PARCELADA:
        print("recusado: só a opção A com a matrícula à vista está escrita (PAGAMENTO = 'A', MATRICULA_PARCELADA = False). "
              "As outras precisam do checkout e das decisões D11/D12 (seção 5 da especificação).")
        return 1
    home = HOME.read_text(encoding="utf-8")
    dados = json.loads(DADOS.read_text(encoding="utf-8"))
    cursos = {c["slug"]: c for c in dados["cursos"]}
    inscricao = int(dados["inscricao_centavos"])
    taxa = brl_curto(inscricao)
    taxa_c = brl(inscricao)
    divulgacao = brl(int(dados.get("divulgacao_centavos") or 1490))
    agora = datetime.now(timezone.utc)

    # Seletor de curso da home: sempre com o mesmo catálogo desta página (só o bloco marcado muda).
    home_nova = atualizar_seletor_home(home, dados, cursos)
    if home_nova != home:
        HOME.write_text(home_nova, encoding="utf-8")
        print(f"atualizado {HOME.relative_to(RAIZ)} (seletor de cursos)")
        home = home_nova
    partes = partes_da_home(home)
    ga4, pixel = partes["ga4"], partes["pixel"]

    for s, c in cursos.items():
        if not c.get("valor_curso_centavos"):
            print(f"recusado: o curso {s} não tem valor_curso_centavos no cursos.json (a página mostra a matrícula de todo curso)")
            return 1
        if s not in ORDEM_EXIBICAO or s not in BENEFICIO or s not in APOIO or s not in COPY_CURSO:
            print(f"aviso: o curso {s} não está em ORDEM_EXIBICAO/BENEFICIO/APOIO/COPY_CURSO; entra no fim, com a descrição do catálogo")
    # Todo curso tem o campo "turmas" (lista vazia = turmas em breve). Campo ausente quer dizer que alguém regravou o
    # cursos.json sem ele (o sincronizar_catalogo.py monta o arquivo do zero): a turma marcada sumiria da página sem aviso.
    sem_campo = [s for s, c in cursos.items() if "turmas" not in c]
    if sem_campo:
        print("AVISO: sem o campo \"turmas\" no cursos.json: " + ", ".join(sem_campo) + ". A página sai com \"Turmas em breve\" "
              "nesses cursos. Se havia turma marcada, recoloque o campo (git diff do cursos.json) antes de publicar.")

    # Ordem do JSON-LD e do chat (grupos do cursos.json); cards: com turma aberta primeiro, depois ORDEM_EXIBICAO.
    ordem = [s for g in dados["grupos"] for s in g["cursos"] if s in cursos]
    turmas = {s: turmas_abertas(cursos[s], agora) for s in cursos}
    com_turma = [s for s in cursos if turmas[s]]
    base_ordem = [s for s in ORDEM_EXIBICAO if s in cursos] + [s for s in ordem if s not in ORDEM_EXIBICAO]
    exibicao = sorted(base_ordem, key=lambda s: (0 if s in com_turma else 1, base_ordem.index(s)))
    ordem_turmas = sorted(com_turma, key=lambda s: turmas[s][0].inicio)   # o destaque do topo: a turma mais próxima

    def curto(s: str) -> str:
        return NOME_CURTO.get(s, cursos[s]["nome"])

    def mat(s: str) -> int:
        return int(cursos[s]["valor_curso_centavos"])

    def total(s: str) -> int:
        return mat(s) + inscricao

    def esc_min(s: str) -> str:
        return re.sub(r"^Ensino\s+", "", cursos[s].get("escolaridade") or "").strip()

    def apoio(s: str) -> str:
        return APOIO.get(s) or nome_filial(cursos[s].get("descricao") or "")

    def beneficio(s: str) -> str:
        return BENEFICIO.get(s) or nome_filial(cursos[s].get("descricao") or "")[:80]

    def checkout(s: str, via: str) -> str:
        return f"{CHECKOUT_URL}?curso={s}&amp;via={via}"

    def total_sufixo(s: str) -> str:
        return " (sem a homologação)" if s in HOMOLOGACAO else ""

    def variantes(s: str, aberta: str, breve: str, tag: str = "div") -> str:
        """As duas variantes no HTML (o relógio do navegador escolhe); sem turma, só a "em breve"."""
        if s in com_turma:
            return (f'<{tag} class="v-se-aberta" data-turma-de="{s}">{aberta}</{tag}>'
                    f'<{tag} class="v-se-breve" data-turma-de="{s}">{breve}</{tag}>')
        return breve

    sem_turma_variantes: list[tuple[str, str]] = []   # conferidas contra "reserv" no fim

    def so_breve(nome: str, trecho: str) -> str:
        sem_turma_variantes.append((nome, trecho))
        return trecho

    def foto_curso(s: str, sizes: str, classe: str = "", loading: str = "lazy") -> str:
        """Capa do curso: a foto real de CAPA_CURSO, se houver; senão a imagem gerada em img/."""
        real = CAPA_CURSO.get(s)
        if real:
            return foto_real(real, sizes, loading, classe)
        img = cursos[s]["imagem"]
        if not (PASTA_IMG / f"{img}-960.webp").exists():
            return ""
        cls = f' class="{classe}"' if classe else ""
        return (f'<img{cls} src="img/{img}-960.webp" srcset="img/{img}-480.webp 480w, img/{img}-960.webp 960w" sizes="{sizes}" '
                f'alt="{esc(cursos[s]["nome"])} na Cruz Vermelha Brasileira Rio de Janeiro" loading="{loading}" decoding="async" width="960" height="720">')

    def tem_cert(s: str) -> bool:
        return (PASTA_IMG / f"certificado-{s}-640.webp").exists()

    def cert_img(s: str, sizes: str) -> str:
        if not tem_cert(s):
            return ""
        return (f'<img class="mr-cert-img" src="img/certificado-{s}-640.webp" srcset="img/certificado-{s}-640.webp 640w, '
                f'img/certificado-{s}-1200.webp 1200w" sizes="{sizes}" alt="Modelo do certificado do curso de {esc(cursos[s]["nome"])} '
                f'da Cruz Vermelha Brasileira Rio de Janeiro, com o nome do aluno, o curso e a carga horária" loading="lazy" decoding="async" '
                f'width="640" height="452" data-cert-curso="{s}">')

    def preco(s: str, sufixo: bool = False) -> str:
        """Bloco de preço (princípio 2). O "(sem a homologação)" só vai no cartão "Turmas em breve" (3.3.2): no card do
        catálogo (3.2.5) a homologação já está na linha de apoio e o parêntese quebrava a linha do total."""
        return (f'<div class="v-preco"><p class="v-preco-rotulo">Matrícula à vista</p>'
                f'<p class="v-preco-valor">{brl_curto(mat(s))}</p><p class="v-preco-taxa">+ {taxa} de taxa de inscrição</p>'
                f'<p class="v-preco-total">Total à vista: {brl_curto(total(s))}{total_sufixo(s) if sufixo else ""}</p></div>')

    def devolucao(local: str) -> str:
        return (f'<a class="v-devolucao" href="/reembolso/" data-devolucao="{local}">{SVG["check-circulo"]}'
                f'<span>Desistiu em até 7 dias? Você recebe de volta tudo o que pagou.</span></a>')

    def chat_link(texto: str, s: str, local: str, assunto: str = "curso") -> str:
        return (f'<a class="v-chat-link" href="#chat" data-abrir-chat data-assunto="{assunto}" data-curso="{s}" data-local="{local}">'
                f'{SVG["chat"]}<span>{esc(texto)}</span></a>')

    def homologacao_linha(s: str, nao_entra: bool = False) -> str:
        if s not in HOMOLOGACAO:
            return ""
        return (f'<p class="v-homolog">Homologação: à parte, paga pelo aluno no fim do curso.{" Não entra no total." if nao_entra else ""} '
                f'<a href="#chat" data-abrir-chat data-assunto="curso" data-curso="{s}" data-local="homologacao">Pergunte o valor no chat</a></p>')

    def botao_aviso(s: str, classe: str = "v-btn-contorno") -> str:
        return (f'<button class="v-btn {classe}" type="button" data-aviso-abrir data-curso="{s}" aria-haspopup="dialog">'
                f'Prefiro ser avisado da data</button>')

    # --- FAQ de cada curso (a Lei Lucas não tem no cursos.json: sai da FAQ da home, com o preço refeito) --------
    faq_home = json.loads(FAQ_HOME.read_text(encoding="utf-8")) if FAQ_HOME.exists() else {"grupos": []}
    lei_lucas = [q for g in faq_home.get("grupos", []) for q in g.get("perguntas", [])
                 if q.get("pergunta", "").startswith("O que é a Lei Lucas")]

    def faq_catalogo(s: str) -> list[tuple[str, str]]:
        itens = [(q["pergunta"], q["resposta"]) for q in (cursos[s].get("faq") or []) if q.get("pergunta") and q.get("resposta")
                 and not EXCLUIR_CATALOGO.search(q["pergunta"] + " " + q["resposta"])]
        if not itens and s == "primeiros-socorros-lei-lucas":
            for q in lei_lucas:
                antes = q["resposta"].split(" Escolaridade mínima:")[0]
                if antes != q["resposta"]:
                    itens.append((q["pergunta"], f"{antes} Escolaridade mínima: Ensino Fundamental. Matrícula à vista: "
                                                 f"{brl_curto(mat(s))}, mais {taxa} de taxa de inscrição (total à vista {brl_curto(total(s))})."))
        return itens

    # --- textos que se repetem (FAQ geral 3.4 e FAQ do curso) ----------------------------------------------
    exemplo = com_turma[0] if com_turma else exibicao[0]
    valores = sorted({mat(s) for s in cursos})
    faixa_mat = f"de {brl_curto(valores[0])} a {brl_curto(valores[-1])}"
    menor_total = brl_curto(valores[0] + inscricao)
    faq6_texto = ("Você não entra na aula. Se desistir em até 7 dias depois do pagamento, recebe de volta tudo o que pagou, sem "
                  "precisar explicar. Depois disso, valem as regras de cancelamento e reembolso.")
    faq6_html = ("Você não entra na aula. Se desistir em até 7 dias depois do pagamento, recebe de volta tudo o que pagou, sem "
                 'precisar explicar. Depois disso, valem as <a href="/reembolso/">regras de cancelamento e reembolso</a>.')
    grupos_valor: dict[int, list[str]] = {}
    for s in exibicao:
        grupos_valor.setdefault(mat(s), []).append(s)
    linhas_total = sorted(grupos_valor.items(), key=lambda kv: (0 if any(x in com_turma for x in kv[1]) else 1, kv[0]))
    lista_totais = [f"{juntar([curto(x) for x in ss])}: {taxa} + {brl_curto(v)} = {brl_curto(v + inscricao)}." for v, ss in linhas_total]
    homolog_frase = (" No Bombeiro Civil, a homologação é paga à parte, no fim do curso; pergunte o valor no chat." if HOMOLOGACAO & set(cursos) else "")
    extras_frase = ("No pagamento da taxa há dois extras opcionais, que vêm desmarcados: cobrir os custos de processamento (o valor "
                    f"aparece no pagamento, antes de você confirmar) e contribuir com {divulgacao} para a divulgação dos cursos. "
                    f"Se não marcar nenhum, você paga só {taxa} de taxa.")
    area = "área do aluno, no site da Escola (escola.cursoscruzvermelha.org)"
    FAQ_GERAL = [
        ("Qual a diferença entre taxa de inscrição e matrícula?",
         [f"Exemplo: em {cursos[exemplo]['nome']}, são {taxa} de taxa de inscrição + {brl_curto(mat(exemplo))} de matrícula = "
          f"{brl_curto(total(exemplo))} à vista. A taxa de inscrição custa {taxa}, é igual em todos os cursos e é paga neste site, por "
          f"PIX ou cartão, sem criar conta antes. Com turma aberta, ela reserva a sua vaga. A matrícula é o valor do curso, {faixa_mat}, "
          f"e é paga pela internet, na {area}. Para entrar na aula, a matrícula precisa estar paga."], None),
        ("Onde e quando eu pago a matrícula?",
         [f"Pela internet, na {area}, antes da aula. Se você ainda não tem conta lá, ela é criada assim que a taxa de inscrição é "
          "confirmada, e o link para criar a senha chega no seu e-mail. O link vale 72 horas; depois disso, use “Esqueci minha "
          "senha”, com o e-mail da inscrição. Já tem conta? Entre com a senha de sempre. Se o curso tem turma aberta, a matrícula "
          "aparece lá para você pagar. Se ainda não tem, ela aparece quando a secretaria colocar você na próxima turma. A área do "
          "aluno é o site oficial da Escola de Educação e Saúde da Cruz Vermelha Brasileira do Rio de Janeiro."], None),
        ("Quanto custa no total?",
         ["O total à vista é a taxa de inscrição mais a matrícula:", lista_totais, homolog_frase.strip() + " " + extras_frase], None),
        ("Posso parcelar?",
         ["A taxa de inscrição, não: ela é paga à vista, por PIX ou cartão. A matrícula aparece na área do aluno pelo valor à vista "
          "de cada curso. Quer saber se dá para pagar a matrícula de outro jeito? Pergunte no chat “Fale com a gente” antes de se "
          "inscrever."], None),
        ("Posso ir à aula só com a taxa de inscrição paga?",
         ["Não. Para entrar na aula, a matrícula precisa estar paga. Só com a taxa de inscrição, você tem a vaga reservada (ou, se o "
          "curso ainda não tem data, o lugar na lista da próxima turma), mas ainda não pode assistir à aula. A data, o horário e o "
          "local da turma estão no seu e-mail de confirmação e na área do aluno."], None),
        ("E se eu pagar a taxa e não pagar a matrícula?", [faq6_texto], faq6_html),
        ("E se o curso ainda não tiver turma aberta?",
         ["Você pode pagar a taxa de inscrição e entrar na lista da próxima turma. Quando a data sair, a secretaria coloca você na "
          "turma e avisa por e-mail. Depois do pagamento, você também marca os dias e horários em que pode vir. Até a turma ser "
          "confirmada, você pode desistir e pedir a taxa inteira de volta, pelo chat. Se preferir não pagar agora, toque em "
          "“Prefiro ser avisado da data” na página do curso: avisamos por e-mail quando a turma abrir."], None),
        ("Posso desistir depois de pagar?",
         ["Sim. Você tem 7 dias corridos depois de cada pagamento para desistir, sem dizer o motivo, e recebe de volta o valor pago "
          "(art. 49 do Código de Defesa do Consumidor). Peça pelo chat “Fale com a gente”, no assunto “Pagamento ou PIX”. A taxa de "
          "inscrição, a nossa equipe devolve. O pedido da matrícula, paga na área do aluno, a equipe passa para a secretaria da "
          "Escola. As regras completas estão em Cancelamento e reembolso."],
         "Sim. Você tem 7 dias corridos depois de cada pagamento para desistir, sem dizer o motivo, e recebe de volta o valor pago "
         "(art. 49 do Código de Defesa do Consumidor). Peça pelo chat “Fale com a gente”, no assunto “Pagamento ou PIX”. A taxa de "
         "inscrição, a nossa equipe devolve. O pedido da matrícula, paga na área do aluno, a equipe passa para a secretaria da "
         'Escola. As regras completas estão em <a href="/reembolso/">Cancelamento e reembolso</a>.'),
        ("Recebo certificado? Ele é reconhecido pelo MEC?",
         ["Sim, você recebe certificado. Quem conclui os requisitos do curso recebe o certificado da Cruz Vermelha Brasileira Rio de "
          "Janeiro, com o seu nome, o curso e a carga horária. Como todo curso livre, aqui ou em qualquer instituição, ele não passa "
          "pelo MEC, que regula a educação formal (ensino técnico, graduação e pós)." +
          (" No Bombeiro Civil, a homologação profissional é feita no fim do curso, à parte." if HOMOLOGACAO & set(cursos) else "")], None),
        ("Onde são as aulas?",
         ["Na sede da Cruz Vermelha Brasileira do Rio de Janeiro, o Palácio da Cruz Vermelha: Praça da Cruz Vermelha, 10, Centro. "
          "Todos os cursos são presenciais."], None),
    ]

    def faq_texto(partes_r: list) -> str:
        """Resposta em texto puro (FAQPage), sem frase que dependa da data de hoje."""
        saida = []
        for p in partes_r:
            saida.append(" ".join(p) if isinstance(p, list) else p)
        return " ".join(x for x in saida if x)

    def faq_html(partes_r: list, html_pronto: str | None) -> str:
        if html_pronto:
            return f"<p>{html_pronto}</p>"
        saida = []
        for p in partes_r:
            if isinstance(p, list):
                saida.append("<ul>" + "".join(f"<li>{esc(x)}</li>" for x in p) + "</ul>")
            elif p:
                saida.append(f"<p>{esc(p)}</p>")
        return "".join(saida)

    def detalhes(perguntas: list[tuple[str, str]], abrir_primeira: bool = True) -> str:
        return "".join(f'<details data-pergunta="{i}"{" open" if i == 1 and abrir_primeira else ""}><summary>{esc(p)}</summary>'
                       f'<div class="v-faq-resp">{r}</div></details>' for i, (p, r) in enumerate(perguntas, 1))

    # --- chat: a lista de cursos do chat.js segue este catálogo; as tags levam o hash do arquivo -----------------
    para_o_chat = []
    for s in ordem:
        c = cursos[s]
        valor = brl(c["valor_curso_centavos"]) if c.get("valor_curso_centavos") else ""
        para_o_chat.append({
            "slug": s, "nome": c["nome"], "carga": c.get("carga_horaria", ""),
            "escolaridade": c.get("escolaridade", ""), "valor": valor,
            "descricao": nome_filial(c.get("descricao", "")),
            "faq": [{"pergunta": q["pergunta"], "resposta": nome_filial(q["resposta"])}
                    for q in (c.get("faq") or []) if q.get("pergunta") and q.get("resposta")
                    and not EXCLUIR_CATALOGO.search(q["pergunta"] + " " + q["resposta"])],
        })
    if chat_widget.atualizar_cursos(para_o_chat):
        print("atualizado site/chat/chat.js (cursos, ficha e dúvidas)")
    chat_tags = chat_widget.tags()

    # ============================================================================================================
    # 1–2. faixa vermelha, cabeçalho fixo e menu do celular (3.2.1, 3.2.2)
    # ============================================================================================================
    marca = (f'<img src="{LOGO["src"]}" width="{LOGO["largura"]}" height="{LOGO["altura"]}" alt="{esc(LOGO["alt"])}">'
             f'<span class="v-marca-escola"><b>Escola de Educação</b><span>e Saúde CVB-RJ</span></span>')
    nav_itens = [("cursos|inv", "Cursos", "Turma e valores"), ("como-funciona|como", "Como funciona", "Como funciona"),
                 ("escola|duvidas", "Quem somos", "Dúvidas"), ("duvidas|cursos", "Dúvidas", "Outros cursos")]

    def rotulos(geral: str, curso_: str) -> str:
        if geral == curso_:
            return esc(geral)
        return f'<span class="v-so-geral">{esc(geral)}</span><span class="v-so-curso">{esc(curso_)}</span>'

    nav = "".join(f'<li><a href="#{a.split("|")[0]}" data-alvo="{a}">{rotulos(g, c)}</a></li>' for a, g, c in nav_itens)
    menu_itens = "".join(f'<li><a href="#{a.split("|")[0]}" data-alvo="{a}"><small>0{i}</small>{rotulos(g, c)}</a></li>'
                         for i, (a, g, c) in enumerate(nav_itens, 1))
    menu_itens += '<li><a href="/"><small>05</small>Site da Cruz Vermelha RJ</a></li>'
    faixa_topo = f'''
  <div class="v-faixa-topo">
    <div class="v-wrap">
      <a href="/"><span class="v-faixa-longa">Cruz Vermelha Brasileira · Filial do Estado do Rio de Janeiro</span><span class="v-faixa-curta">Cruz Vermelha Brasileira · Rio de Janeiro</span><span class="v-faixa-mini">Cruz Vermelha RJ</span>{SVG["externo"]}</a>
      <p>Praça da Cruz Vermelha, 10 · Centro · Rio de Janeiro</p>
    </div>
  </div>'''
    cabecalho = f'''
  <header class="v-cab">
    <div class="v-wrap v-cab-barra">
      <a class="v-marca" href="/matricula-cursos-presenciais/" data-voltar="">{marca}</a>
      <nav class="v-nav" aria-label="Principal"><ul>{nav}</ul></nav>
      <div class="v-cab-acoes">
        <a class="v-btn v-btn-contorno" href="{link_escola("cabecalho")}" target="_blank" rel="noopener" data-saida="area_aluno" data-origem="cabecalho">Já sou aluno {SVG["externo"]}</a>
        <a class="v-btn v-btn-vermelho v-so-geral" href="#cursos">Ver cursos</a>
        <a class="v-btn v-btn-vermelho mr-cta v-so-curso" id="v-cab-cta" data-local="cabecalho" data-curso="" href="{CHECKOUT_URL}?via=cabecalho"><span class="v-cab-cta-longo">Reservar minha vaga</span><span class="v-cab-cta-curto">Reservar vaga</span>{SVG["seta"]}</a>
      </div>
      <button class="v-menu-botao" id="v-menu-botao" type="button" aria-expanded="false" aria-controls="v-menu" aria-label="Abrir menu"><span class="v-ico-abrir">{SVG["menu"]}</span><span class="v-ico-fechar">{SVG["fechar"]}</span></button>
    </div>
    <div class="v-menu" id="v-menu" hidden>
      <div class="v-wrap">
        <nav aria-label="Menu do celular"><ol>{menu_itens}</ol></nav>
        <div class="v-menu-aluno"><span>Já sou aluno</span><a class="v-btn v-btn-contorno" href="{link_escola("menu")}" target="_blank" rel="noopener" data-saida="area_aluno" data-origem="menu">Entrar {SVG["externo"]}</a></div>
        <a class="v-menu-chat" href="#chat" data-abrir-chat data-assunto="matricula" data-local="menu">{SVG["chat"]}<span>Dúvidas? Fale com a gente<small>Abre o chat desta página</small></span></a>
        <p class="v-menu-endereco">{SVG["pino"]}<span>Praça da Cruz Vermelha, 10 · Centro</span></p>
      </div>
    </div>
  </header>'''

    # ============================================================================================================
    # 3–4. topo com foto, "Próxima turma em destaque" e faixa de destaques (3.2.3, 3.2.4)
    # ============================================================================================================
    destaques_topo = []
    for i, s in enumerate(ordem_turmas):
        t = turmas[s][0]
        rotulo_aria = (f"{cursos[s]['nome']}, {t.dia}, {t.data_extenso}: matrícula à vista {brl_curto(mat(s))}, mais {taxa} de taxa de "
                       f"inscrição, total à vista {brl_curto(total(s))}. Ver turma e valores")
        destaques_topo.append(f'''
          <div class="v-destaque{" v-destaque-padrao" if i == 0 else ""}" data-curso="{s}">
            <a class="v-destaque-link v-flutuante" href="?curso={s}#det-{s}" data-abrir-curso="{s}" data-local-curso="destaque_topo" aria-label="{esc(rotulo_aria)}">
              <p class="v-kicker">Próxima turma em destaque</p>
              <p class="v-destaque-titulo">{esc(cursos[s]["nome"])}</p>
              <p class="v-destaque-meta">{esc(cursos[s]["carga_horaria"])} · Escolaridade mínima: {esc(esc_min(s))} · Presencial no Centro do Rio</p>
              <hr class="v-destaque-linha">
              <div class="v-destaque-preco">{preco(s)}{t.bloco_data()}</div>
              <span class="v-destaque-ver">Ver turma e valores {SVG["seta"]}</span>
            </a>
          </div>''')
    destaques_topo.append(so_breve("destaque do topo", f'''
          <div class="v-destaque v-destaque-breve{"" if ordem_turmas else " v-destaque-padrao"}">
            <a class="v-destaque-link v-flutuante" href="#cursos">
              <p class="v-kicker">Turmas em breve</p>
              <p class="v-destaque-titulo">As próximas datas saem aqui</p>
              <p class="v-texto-breve">Os valores de cada curso já estão abaixo. Escolha o curso para entrar na lista da próxima turma ou pedir o aviso da data.</p>
              <span class="v-destaque-ver">Ver cursos {SVG["seta"]}</span>
            </a>
          </div>'''))
    hero = f'''
    <section class="v-hero v-escuro-fundo v-so-geral" aria-labelledby="v-titulo" data-secao="topo">
      {foto_topo()}
      <div class="v-hero-grade">
        <div class="v-hero-texto">
          <p class="v-olho">Formação presencial no Centro do Rio</p>
          <h1 class="v-hero-h1" id="v-titulo" tabindex="-1">Cursos presenciais que preparam para&nbsp;<em>agir.</em></h1>
          <p class="v-hero-lead">Cursos da Escola de Educação e Saúde da Cruz Vermelha Brasileira do Rio de Janeiro, na sede da Praça da Cruz Vermelha, 10. Aulas práticas, com certificado da Cruz Vermelha.</p>
          <div class="v-hero-acoes">
            <a class="v-btn v-btn-vermelho v-btn-grande" href="#cursos">Ver cursos e valores {SVG["seta"]}</a>
            <a class="v-btn v-btn-contorno-branco v-btn-grande" href="#como-funciona">Como funciona</a>
          </div>
          <ul class="v-selos" aria-label="Destaques">
            <li><span>{SVG["check"]}</span>Certificado</li>
            <li><span>{SVG["check"]}</span>Aulas presenciais</li>
            <li><span>{SVG["check"]}</span>Inscrição por PIX ou cartão</li>
          </ul>
        </div>
        <div class="v-hero-cartao">{"".join(destaques_topo)}
        </div>
      </div>
    </section>'''
    destaques = f'''
    <section class="v-destaques v-so-geral" aria-label="Destaques da Escola" data-secao="destaques">
      <div class="v-wrap">
        <ul>
          <li><h2>{SVG["predio"]}Formação presencial</h2><p>Aulas práticas na sede da Cruz Vermelha Brasileira do Rio de Janeiro.</p></li>
          <li><h2>{SVG["medalha"]}Certificação</h2><p>Certificado da Cruz Vermelha ao concluir os requisitos do curso.</p></li>
          <li><h2>{SVG["calendario"]}Turmas e datas</h2><p>Veja a próxima turma e o total à vista de cada curso antes de se inscrever.</p></li>
          <li><h2>{SVG["chat"]}Atendimento humano</h2><p>Dúvidas? Pergunte no chat “Fale com a gente”. O que o chat não responder na hora, a equipe responde por e-mail.</p></li>
        </ul>
      </div>
    </section>'''

    # ============================================================================================================
    # Modo curso (3.3): uma seção por curso, escondida até o modo curso ligar
    # ============================================================================================================
    fotos_aulas = "".join(f'<li><figure>{foto_real(f, "(min-width: 860px) 25vw, 76vw")}<figcaption>{esc(f["legenda"])}</figcaption></figure></li>'
                          for f in FOTOS_AULAS)

    def cartao_aberta(s: str) -> str:
        t = turmas[s][0]
        return f'''
            <p class="v-kicker">Próxima turma</p>
            <div class="v-cartao-dataline">
              {t.bloco_data()}
              <div>
                <p class="v-cartao-dia">{esc(t.linha_data)}</p>
                <p class="v-cartao-hora"><span>{esc(t.linha_horario)}</span><span class="v-dias" data-turma-de="{s}"></span></p>
                <p class="v-cartao-insc">{esc(t.inscricoes)}</p>
                <p class="v-cartao-local">{local_junto(t.local)}</p>
              </div>
            </div>
            <hr class="v-destaque-linha">
            {preco(s)}
            <a class="v-btn v-btn-vermelho v-btn-grande mr-cta" data-local="cartao_turma" data-curso="{s}" href="{checkout(s, "cartao_turma")}">Reservar minha vaga {SVG["seta"]}</a>
            <ul class="v-nota-pagamento">
              <li><b>Agora: {taxa}.</b> Só a taxa de inscrição, por PIX ou cartão.</li>
              <li><b>Antes da aula: {brl_curto(mat(s))}.</b> A matrícula, pela internet, na área do aluno. Sem ela, você não entra na aula.</li>
            </ul>
            <div class="v-cartao-pes">
              {devolucao("cartao_turma")}
              {chat_link("Dúvida antes de se inscrever? Pergunte no chat.", s, "cartao_turma")}
            </div>'''

    def cartao_breve(s: str) -> str:
        return so_breve(f"cartão {s}", f'''
            <p class="v-kicker">Turmas em breve</p>
            <p class="v-cartao-breve-titulo">Ainda não há data marcada</p>
            <p class="v-cartao-breve-texto">A data da próxima turma aparece aqui assim que abrir.</p>
            <hr class="v-destaque-linha">
            {preco(s, sufixo=True)}
            {homologacao_linha(s)}
            <a class="v-btn v-btn-vermelho v-btn-grande mr-cta" data-local="cartao_turma" data-curso="{s}" href="{checkout(s, "cartao_turma")}">Inscrever-se para a próxima turma {SVG["seta"]}</a>
            <ul class="v-nota-pagamento">
              <li><b>Agora: {taxa}.</b> Só a taxa de inscrição. Você entra na lista da próxima turma.</li>
              <li><b>Quando a turma abrir: {brl_curto(mat(s))}.</b> A matrícula, pela internet, na área do aluno.</li>
              <li><b>Até a turma ser confirmada, você pode desistir e pedir a taxa inteira de volta.</b></li>
            </ul>
            {botao_aviso(s)}
            <div class="v-cartao-pes">{chat_link("Dúvida antes de se inscrever? Pergunte no chat.", s, "cartao_turma")}</div>''')

    def investimento(s: str) -> str:
        legenda_aberta = "antes da aula, pela internet, na área do aluno"
        legenda_breve = "quando a turma abrir, pela internet, na área do aluno"
        legenda = variantes(s, esc(legenda_aberta), so_breve(f"legenda Investimento {s}", esc(legenda_breve)), "span")
        aviso_aberta = (f'<p class="v-aviso">{SVG["exclamacao"]}<span><b>Para entrar na aula, a matrícula precisa estar paga.</b> Só com a '
                        'taxa de inscrição, a sua vaga fica reservada, mas você ainda não pode assistir à aula.</span></p>')
        aviso_breve = so_breve(f"aviso Investimento {s}", f'<p class="v-aviso">{SVG["exclamacao"]}<span><b>Para entrar na aula, a matrícula precisa estar paga.</b> '
                               'Com a taxa de inscrição, você entra na lista da próxima turma. A matrícula aparece na área do aluno quando a '
                               'secretaria colocar você na turma.</span></p>')
        botao_aberta = f'<a class="v-btn v-btn-vermelho v-btn-grande mr-cta" data-local="investimento" data-curso="{s}" href="{checkout(s, "investimento")}">Reservar minha vaga {SVG["seta"]}</a>'
        botao_breve = so_breve(f"botão Investimento {s}", f'<a class="v-btn v-btn-vermelho v-btn-grande mr-cta" data-local="investimento" data-curso="{s}" href="{checkout(s, "investimento")}">Inscrever-se para a próxima turma {SVG["seta"]}</a>')
        return f'''
          <aside class="v-inv" id="inv-{s}" aria-labelledby="inv-titulo-{s}" data-secao="investimento">
            <p class="v-kicker" id="inv-titulo-{s}">Investimento</p>
            <dl>
              <div><dt>Taxa de inscrição</dt><dd>{taxa_c}</dd><dd class="v-inv-leg">agora, neste site, por PIX ou cartão</dd></div>
              <div><dt>Matrícula (o valor do curso), à vista</dt><dd>{brl(mat(s))}</dd><dd class="v-inv-leg">{legenda}</dd></div>
              <div class="v-inv-total"><dt>Total à vista</dt><dd>{brl(total(s))}</dd></div>
            </dl>
            {homologacao_linha(s, nao_entra=True)}
            <p class="v-inv-obs">Valores para pagamento à vista.</p>
            {variantes(s, aviso_aberta, aviso_breve)}
            {variantes(s, botao_aberta, botao_breve)}
            <div class="v-inv-pes">
              {devolucao("investimento")}
              <p class="v-inv-grupo">Grupo de {TURMA_MINIMO} a {TURMA_MAXIMO} pessoas? <a href="#empresas" data-turma-abrir="curso" data-curso="{s}" aria-controls="turma-form-bloco">Peça uma turma só de vocês</a></p>
              {chat_link("Dúvidas? Fale com a gente", s, "investimento")}
            </div>
          </aside>'''

    def turmas_bloco(s: str) -> str:
        breve = so_breve(f"turmas {s}", f'''
              <p class="v-kicker">Turmas</p>
              <h2 class="v-h2-bloco">Sem turma aberta no momento</h2>
              <p class="v-turma-caixa">Este curso ainda não tem data. Pagando agora a taxa de inscrição ({taxa}), você entra na lista da próxima turma. Quando a data sair, a secretaria coloca você na turma e avisa por e-mail. Depois do pagamento, você também marca os dias e horários em que pode vir. Até a turma ser confirmada, você pode desistir e pedir a taxa inteira de volta.</p>
              <div class="v-turma-botoes">
                <a class="v-btn v-btn-vermelho mr-cta" data-local="turma" data-curso="{s}" href="{checkout(s, "turma")}">Inscrever-se para a próxima turma {SVG["seta"]}</a>
                {botao_aviso(s)}
              </div>''')
        if s not in com_turma:
            return breve
        t = turmas[s][0]
        depois = turmas[s][1:]
        depois_html = f'<p class="v-turma-depois">Depois: {esc(", ".join(x.ddmm for x in depois))}</p>' if depois else ""
        aberta = f'''
              <p class="v-kicker">Turmas</p>
              <h2 class="v-h2-bloco">Próxima turma</h2>
              <div class="v-turma-linha">
                {t.bloco_data()}
                <div>
                  <p class="v-cartao-dia">{esc(t.linha_data)}</p>
                  <p class="v-cartao-hora">{esc(t.linha_horario)} · {local_junto(t.local)}</p>
                  <p class="v-cartao-insc">{esc(t.inscricoes)}</p>
                </div>
                <a class="v-btn v-btn-vermelho mr-cta" data-local="turma" data-curso="{s}" href="{checkout(s, "turma")}">Reservar minha vaga {SVG["seta"]}</a>
              </div>
              {depois_html}
              <p class="v-turma-fina">A inscrição feita aqui entra na próxima turma com vaga. As vagas são limitadas: se a turma lotar antes de o seu pagamento ser confirmado, você entra na lista da próxima e pode pedir a taxa de volta.</p>'''
        return variantes(s, aberta, breve)

    def faq_do_curso(s: str) -> str:
        c1_a = (f"Taxa de inscrição: {taxa}. Matrícula (o valor do curso), à vista: {brl_curto(mat(s))}. Total à vista: "
                f"{brl_curto(total(s))}. ")
        fim_aberta = (f"A taxa você paga agora, neste site, por PIX ou cartão, e ela reserva a sua vaga. A matrícula você paga antes "
                      f"da aula, pela internet, na {area}.")
        fim_breve = ("A taxa você paga agora, neste site, por PIX ou cartão, e você entra na lista da próxima turma. A matrícula você "
                     "paga quando a turma abrir, pela internet, na área do aluno; a secretaria avisa por e-mail.")
        perguntas = [("Quanto custa e onde pago cada parte?",
                      f"<p>{esc(c1_a)}{variantes(s, esc(fim_aberta), so_breve(f'FAQ 1 {s}', esc(fim_breve)), 'span')}</p>")]
        if s in HOMOLOGACAO:
            perguntas.append(("A homologação está incluída?",
                              f"<p>Não. A homologação é feita no fim do curso, à parte, e é paga pelo aluno. Ela não entra no total à vista "
                              f"de {brl_curto(total(s))}. Pergunte o valor no chat “Fale com a gente” antes de se inscrever.</p>"))
        perguntas.append(("E se eu pagar a taxa e não pagar a matrícula?", f"<p>{faq6_html}</p>"))
        ob = (COPY_CURSO.get(s) or {}).get("objecao")
        temas_ob = [t for t in TEMAS_FAQ if ob and t.search(ob[0])]
        if ob:
            perguntas.append((nome_filial(ob[0]), f"<p>{esc(nome_filial(ob[1]))}</p>"))
        for p, r in faq_catalogo(s):
            if len(perguntas) >= 7:
                break
            if any(p.strip().lower() == x[0].strip().lower() for x in perguntas) or any(t.search(p) for t in temas_ob):
                continue
            perguntas.append((nome_filial(p), f"<p>{esc(nome_filial(r))}</p>"))
        return detalhes(perguntas[:7])

    def secao_curso(s: str) -> str:
        c = cursos[s]
        chip_presencial = f'{SVG["pino"]}Presencial no Centro do Rio'
        if s in com_turma:
            # A data entra no lugar de "Presencial no Centro do Rio" (3.3.1); vencida, volta o presencial.
            antes = (f'<li class="v-chip-data v-se-aberta" data-turma-de="{s}">{SVG["calendario"]}{esc(turmas[s][0].chip)}</li>'
                     f'<li class="v-se-breve" data-turma-de="{s}">{chip_presencial}</li>')
            meio = ""
        else:
            antes, meio = "", f"<li>{chip_presencial}</li>"
        chips_html = (f'{antes}<li>{SVG["relogio"]}{esc(c["carga_horaria"])}</li>'
                      f'<li>{SVG["capelo"]}Escolaridade mínima: {esc(esc_min(s))}</li>{meio}<li>{SVG["medalha"]}Certificado</li>')
        sobre = "".join(f"<p>{esc(nome_filial(p))}</p>" for p in c.get("sobre") or [] if not EXCLUIR_CATALOGO.search(p))
        obs = "".join(f'<p class="v-obs">{esc(nome_filial(o))}</p>' for o in c.get("observacoes") or [])
        passo3_aberta = (f'<h3>Pague a matrícula antes da aula</h3><p>{brl_curto(mat(s))} à vista, pela internet, na área do aluno. '
                         'Com ela paga, você pode entrar na aula.</p>')
        passo3_breve = so_breve(f"passo 3 {s}", f'<h3>Pague a matrícula quando a turma abrir</h3><p>{brl_curto(mat(s))} à vista, na área do aluno. '
                                'A secretaria avisa a data por e-mail.</p>')
        cert = cert_img(s, "(min-width: 980px) 380px, 100vw")
        return f'''
    <article class="v-curso mr-detalhe" id="det-{s}" data-curso="{s}" aria-labelledby="titulo-{s}">
      <div class="v-curso-topo v-escuro-fundo" data-secao="curso_topo">
        {foto_curso(s, "100vw", "v-curso-foto")}
        <div class="v-curso-topo-grade">
          <div class="v-curso-texto">
            <a class="v-voltar" href="./#cursos" data-voltar="cursos">{SVG["voltar"]}Todos os cursos</a>
            <p class="v-olho">Curso presencial</p>
            <h2 class="v-curso-h1" id="titulo-{s}" tabindex="-1">{esc(c["nome"])}</h2>
            <p class="v-curso-apoio">{esc(apoio(s))}</p>
            <ul class="v-chips-topo" aria-label="Sobre o curso">{chips_html}</ul>
          </div>
          <div class="v-cartao v-flutuante">{variantes(s, cartao_aberta(s), cartao_breve(s)) if s in com_turma else cartao_breve(s)}
          </div>
          <ul class="v-prova" aria-label="Por que a Cruz Vermelha" data-secao="prova">
            <li>{SVG["historia"]}Forma pessoas no Rio desde 1914</li>
            <li>{SVG["predio"]}Aulas práticas no Palácio da Cruz Vermelha</li>
            <li>{SVG["medalha"]}Certificado com o seu nome</li>
          </ul>
        </div>
      </div>
      <div class="v-curso-corpo-sec">
        <div class="v-wrap v-curso-corpo">{investimento(s)}
          <section class="v-bloco" id="como-{s}" aria-labelledby="como-titulo-{s}" data-secao="como_inscrever">
            <p class="v-kicker">Inscrição</p>
            <h2 class="v-h2-bloco" id="como-titulo-{s}">Como funciona a inscrição</h2>
            <ol class="v-passos-curso">
              <li><div><h3>Pague a taxa de inscrição aqui</h3><p>{taxa}, por PIX ou cartão, sem criar conta antes.</p></div></li>
              <li><div><h3>Receba o acesso à área do aluno</h3><p>A área do aluno fica no site da Escola (escola.cursoscruzvermelha.org). Se você ainda não tem conta, ela é criada para você, com o e-mail da inscrição. O link para criar a senha chega assim que a taxa é confirmada e vale 72 horas. Se já tem conta, use a mesma senha.</p></div></li>
              <li><div>{variantes(s, passo3_aberta, passo3_breve)}</div></li>
            </ol>
          </section>
          <section class="v-bloco" aria-labelledby="cert-titulo-{s}" data-secao="certificado_curso">
            <p class="v-kicker">Certificado</p>
            <h2 class="v-h2-bloco" id="cert-titulo-{s}">Seu certificado</h2>
            <figure class="v-cert-curso">{cert}<figcaption>{esc(CERT_NOTA)}</figcaption></figure>
          </section>
          <section class="v-bloco" aria-labelledby="sobre-titulo-{s}" data-secao="sobre">
            <p class="v-kicker">Sobre o curso</p>
            <h2 class="v-h2-bloco" id="sobre-titulo-{s}">O que você vai aprender</h2>
            <div class="v-prosa" style="margin-top:18px">{sobre}</div>
            {obs}
            <h3 class="v-fotos-curso-titulo">Aqui você aprende fazendo.</h3>
            <ul class="v-fotos" tabindex="0" aria-label="Fotos das aulas">{fotos_aulas}</ul>
          </section>
          <section class="v-bloco" id="turmas-{s}" aria-label="Turmas" data-secao="turmas">{turmas_bloco(s)}
          </section>
        </div>
      </div>
      <section class="v-curso-faq" id="duvidas-{s}" aria-labelledby="duvidas-titulo-{s}" data-secao="faq_curso">
        <div class="v-wrap v-faq-grade">
          <div class="v-faq-cab">
            <p class="v-kicker">Dúvidas frequentes</p>
            <h2 class="v-h2" id="duvidas-titulo-{s}">Sobre este curso.</h2>
            <p class="v-faq-texto">Não encontrou o que procura? Pergunte no chat “Fale com a gente”.</p>
            <a class="v-btn v-btn-preto" href="#chat" data-abrir-chat data-assunto="curso" data-curso="{s}" data-local="faq">Abrir o chat {SVG["chat"]}</a>
          </div>
          <div class="v-faq-lista">{faq_do_curso(s)}</div>
        </div>
      </section>
    </article>'''

    secoes_curso = "".join(secao_curso(s) for s in exibicao)
    chegar_curso = f'''
    <section class="v-chegar-curso v-so-curso" aria-labelledby="chegar-curso-titulo">
      <div class="v-wrap">
        <div class="v-chegar-curso-grade">
          <a class="v-chegar-img" href="{MAPA_ROTA}" target="_blank" rel="noopener" aria-label="Abrir a rota no Google Maps (abre em nova aba)"><img src="{MAPA_ESTATICO["src"]}" srcset="{MAPA_ESTATICO["srcset"]}" sizes="(min-width: 860px) 50vw, 100vw" width="960" height="720" alt="{esc(MAPA_ESTATICO["alt"])}" loading="lazy" decoding="async"></a>
          <div class="v-chegar-curso-texto">
            <p class="v-kicker">Como chegar</p>
            <h2 id="chegar-curso-titulo">Praça da Cruz Vermelha, 10</h2>
            <p>Centro · Rio de Janeiro – RJ · CEP 20230-130</p>
            <p>As aulas acontecem aqui, na sede da Cruz Vermelha Brasileira.</p>
            <div class="v-chegar-acoes"><a class="v-btn v-btn-vermelho" href="{MAPA_ROTA}" target="_blank" rel="noopener">Abrir no Google Maps {SVG["externo"]}</a></div>
            <small>{esc(MAPA_ESTATICO["credito"])}</small>
          </div>
        </div>
      </div>
    </section>'''

    # ============================================================================================================
    # 5. cursos em cards (3.2.5)
    # ============================================================================================================
    spans: list[int] = []
    restantes = len(exibicao)
    if com_turma:
        spans += [8, 4]
        restantes -= 2
    while restantes > 0:
        if restantes >= 3 and restantes != 4:
            spans += [4, 4, 4]
            restantes -= 3
        elif restantes == 4 or restantes == 2:
            spans += [6, 6]
            restantes -= 2
        else:
            spans += [12]
            restantes -= 1

    def card(s: str, posicao: int) -> str:
        c = cursos[s]
        largo = s in com_turma and posicao == 0
        aria_breve = (f"{c['nome']}, turmas em breve: matrícula à vista {brl_curto(mat(s))}, mais {taxa} de taxa de inscrição, "
                      f"total à vista {brl_curto(total(s))}. Ver turma e valores")
        if s in com_turma:
            t = turmas[s][0]
            aria = (f"{c['nome']}, {t.dia}, {t.data_extenso}: matrícula à vista {brl_curto(mat(s))}, mais {taxa} de taxa de inscrição, "
                    f"total à vista {brl_curto(total(s))}. Ver turma e valores")
            selo = variantes(s, '<span class="v-selo"><i class="v-ponto" aria-hidden="true"></i>Inscrições abertas</span>',
                             '<span class="v-selo">Turmas em breve</span>', "span")
            chip_turma = variantes(s, f'<span class="v-chip">Turma {t.ddmm}</span>', "", "span")
            extra = f' data-aria-breve="{esc(aria_breve)}"'
        else:
            aria, selo, chip_turma, extra = aria_breve, '<span class="v-selo">Turmas em breve</span>', "", ""
        classes = "v-card" + (" v-card-largo" if largo else "") + ("" if s in com_turma else " v-card-compacto")
        return f'''
          <a class="{classes}" id="curso-{s}" data-curso="{s}" data-abrir-curso="{s}" href="?curso={s}#det-{s}" aria-label="{esc(aria)}"{extra} style="--span:{spans[posicao] if posicao < len(spans) else 4}">
            <div class="v-card-foto">{foto_curso(s, "(min-width: 980px) 40vw, (min-width: 651px) 50vw, 100vw", "", "lazy")}{selo}</div>
            <div class="v-card-corpo">
              {"" if s in com_turma else '<p class="v-card-estado">Turmas em breve</p>'}
              <h3 class="v-card-titulo">{esc(curto(s) if not largo else c["nome"])}</h3>
              <p class="v-card-apoio">{esc(apoio(s))}</p>
              <p class="v-card-desc">{esc(beneficio(s))}</p>
              <p class="v-chips"><span class="v-chip">{esc(c["carga_horaria"])}</span><span class="v-chip">Mín. {esc(esc_min(s))}</span><span class="v-chip">Presencial</span>{chip_turma}</p>
              <div class="v-card-rodape">
                {preco(s)}
                <div class="v-preco-compacto"><p class="v-pc-mat">Matrícula à vista <b>{brl_curto(mat(s))}</b></p><p class="v-pc-taxa">+ {taxa} de taxa de inscrição</p><p class="v-pc-total">Total à vista: {brl_curto(total(s))}</p></div>
                <span class="v-card-seta" aria-hidden="true">{SVG["seta"]}</span>
              </div>
            </div>
          </a>'''

    cursos_sec = f'''
    <section class="v-cursos v-secao" id="cursos" aria-labelledby="cursos-titulo" data-secao="cursos">
      <div class="v-wrap">
        <div class="v-cab-secao">
          <div>
            <p class="v-kicker"><span class="v-so-geral">Cursos presenciais</span><span class="v-so-curso">Veja também</span></p>
            <h2 class="v-h2" id="cursos-titulo" tabindex="-1"><span class="v-so-geral">Formação para cuidar, prevenir e responder.</span><span class="v-so-curso">Outros cursos da Escola.</span></h2>
          </div>
          <p class="v-so-geral">Turmas presenciais no Centro do Rio. Escolha o curso para ver a próxima turma, a carga horária, os valores e fazer sua inscrição.</p>
        </div>
        <div class="v-grade">{"".join(card(s, i) for i, s in enumerate(exibicao))}
        </div>
        <p class="v-nota-preco v-so-geral">Valores à vista. Você paga em duas partes: a taxa de inscrição, de {taxa}, igual em todos os cursos, neste site, na hora da inscrição; e a matrícula (o valor do curso), antes da aula, pela internet, na {area}.</p>
        <p class="v-linha-grupos v-so-geral">Empresa, escola ou grupo de {TURMA_MINIMO} a {TURMA_MAXIMO} pessoas? <a class="v-link" href="#empresas" data-turma-abrir="catalogo" aria-controls="turma-form-bloco">Peça uma turma só de vocês {SVG["seta"]}</a></p>
      </div>
    </section>'''

    # ============================================================================================================
    # 6–12. como funciona, certificado, quem somos, depoimentos, dúvidas, empresas e chamada final
    # ============================================================================================================
    passos = [
        ("Escolha seu curso.", "Veja a carga horária, a escolaridade mínima, o total à vista e a próxima turma de cada curso."),
        ("Pague a taxa de inscrição.", f"{taxa}, por PIX ou cartão, neste site, sem criar conta antes. Com turma aberta, a taxa reserva a "
                                       "sua vaga. Sem turma, você entra na lista da próxima."),
        ("Pague a matrícula e venha para a aula.", f"A matrícula (o valor do curso) é paga antes da aula, pela internet, na {area}. Se você "
                                                   "ainda não tem conta lá, ela é criada para você, e o link para criar a senha chega no "
                                                   "seu e-mail. Com a matrícula paga, você pode entrar na aula. A data, o horário e o "
                                                   "local estão no e-mail de confirmação e na área do aluno."),
    ]
    como = f'''
    <section class="v-como v-secao v-escuro-fundo v-so-geral" id="como-funciona" aria-labelledby="como-titulo" data-secao="como_funciona">
      <span class="v-como-marca" aria-hidden="true">{SVG["cruz"]}</span>
      <div class="v-wrap v-como-grade">
        <div>
          <p class="v-kicker">Como funciona</p>
          <h2 class="v-como-h2" id="como-titulo"><span>Escolha.</span> <span>Inscreva-se.</span> <span>Prepare-se.</span></h2>
          <div class="v-como-acoes"><a class="v-btn v-btn-branco v-btn-grande" href="#cursos">Escolher meu curso {SVG["seta"]}</a></div>
          <p class="v-como-nota">Desistiu? Em até 7 dias depois de cada pagamento, você recebe de volta o que pagou, sem precisar explicar. <a href="/reembolso/">Regras de cancelamento e reembolso</a></p>
        </div>
        <ol class="v-passos">{"".join(f'<li><span class="v-passos-num" aria-hidden="true">0{i}</span><div><h3>{esc(t)}</h3><p>{esc(d)}</p></div></li>' for i, (t, d) in enumerate(passos, 1))}</ol>
      </div>
    </section>'''
    certificado = f'''
    <section class="v-secao v-so-geral" id="certificado" aria-labelledby="cert-titulo" data-secao="certificado">
      <div class="v-wrap v-cert-grade">
        <div class="v-cert-texto">
          <p class="v-kicker">Certificado</p>
          <h2 class="v-h2" id="cert-titulo">Sua formação fica registrada.</h2>
          <div class="v-prosa"><p>Ao concluir os requisitos do curso, você recebe o certificado emitido pela Cruz Vermelha Brasileira Rio de Janeiro, com o seu nome, o curso e a carga horária. {esc(CERT_PESO)}</p></div>
        </div>
        <figure class="v-cert-figura">{cert_img(CERT_DESTAQUE, "(min-width: 980px) 50vw, 100vw")}<figcaption class="v-cert-nota">{esc(CERT_NOTA)}</figcaption></figure>
      </div>
    </section>'''
    quem_somos = f'''
    <section class="v-secao v-so-geral" id="escola" aria-labelledby="escola-titulo" data-secao="quem_somos" style="padding-top:0">
      <div class="v-wrap">
        <div class="v-escola-grade">
          <figure class="v-sede">
            <img src="{FOTO_FACHADA["src"]}" width="{FOTO_FACHADA["largura"]}" height="{FOTO_FACHADA["altura"]}" alt="{esc(FOTO_FACHADA["alt"])}" loading="lazy" decoding="async">
            <figcaption><span>Nossa sede</span><b>Praça da Cruz Vermelha, 10 · Centro do Rio.</b></figcaption>
          </figure>
          <div class="v-painel v-escuro-fundo">
            <p class="v-kicker">Quem somos</p>
            <h2 id="escola-titulo">Uma escola dentro de uma instituição humanitária.</h2>
            <div class="v-painel-prosa">
              <p>A Escola de Educação e Saúde é o centro de formação da Cruz Vermelha Brasileira – Filial do Estado do Rio de Janeiro.</p>
              <p>As turmas são presenciais e práticas. Ao concluir, o aluno recebe o certificado da Cruz Vermelha Brasileira.</p>
              <p>A Cruz Vermelha forma pessoas no Rio desde 20 de outubro de 1914, quando começou o primeiro curso, de Enfermeiras Voluntárias. As aulas são no Palácio da Cruz Vermelha, tombado como patrimônio cultural federal. <a href="/historia/">Conheça nossa história →</a></p>
            </div>
            <dl>
              <div><dt>Local</dt><dd>Praça da Cruz Vermelha, 10 · Centro</dd></div>
              <div><dt>Modalidade</dt><dd>Presencial</dd></div>
              <div><dt>Pagamento</dt><dd>Taxa de inscrição neste site, por PIX ou cartão. Matrícula antes da aula, pela internet, na área do aluno, pelo valor à vista.</dd></div>
              <div><dt>Atendimento</dt><dd>Chat “Fale com a gente” e {EMAIL_CONTATO}</dd></div>
            </dl>
          </div>
        </div>
        <h3 class="v-fotos-titulo">Aqui você aprende fazendo.</h3>
        <ul class="v-fotos" tabindex="0" aria-label="Fotos das aulas">{fotos_aulas}</ul>
        <div class="v-chegar">
          <figure class="v-mapa" id="mr-mapa">
            <button type="button" class="v-mapa-abrir" data-mapa-abrir aria-label="Abrir o mapa interativo da Praça da Cruz Vermelha, 10">
              <img src="{MAPA_ESTATICO["src"]}" srcset="{MAPA_ESTATICO["srcset"]}" sizes="100vw" width="960" height="720" alt="{esc(MAPA_ESTATICO["alt"])}" loading="lazy" decoding="async">
            </button>
          </figure>
          <div class="v-chegar-cartao v-flutuante">
            <p class="v-kicker">Como chegar</p>
            <h3>Praça da Cruz Vermelha, 10</h3>
            <p class="v-chegar-end">Centro · Rio de Janeiro – RJ · CEP 20230-130</p>
            <p class="v-chegar-texto">As aulas acontecem aqui, na sede da Cruz Vermelha Brasileira.</p>
            <div class="v-chegar-acoes">
              <a class="v-btn v-btn-vermelho" href="{MAPA_ROTA}" target="_blank" rel="noopener">Abrir no Google Maps {SVG["externo"]}</a>
              <button class="v-btn v-btn-contorno" type="button" id="mr-mapa-carregar" data-src="{MAPA_EMBED}">Ver mapa interativo</button>
            </div>
          </div>
          <p class="v-credito">{esc(MAPA_ESTATICO["credito"])}</p>
        </div>
      </div>
    </section>'''
    if len(PROVA) >= 2:
        for d in PROVA:
            if not all(d.get(k) for k in ("nome", "curso", "texto", "autorizacao")):
                print("recusado: depoimento sem nome, curso, texto ou autorização registrada (D8)")
                return 1
        depoimentos = f'''
    <section class="v-secao v-so-geral" id="depoimentos" aria-labelledby="depo-titulo" data-secao="depoimentos">
      <div class="v-wrap">
        <div class="v-cab-secao"><div><p class="v-kicker">Depoimentos</p><h2 class="v-h2" id="depo-titulo">O que dizem os alunos.</h2></div>
          <p>Comentários de quem fez os cursos, enviados na pesquisa de satisfação ao fim de cada turma.</p></div>
        <div class="v-depo-grade">{"".join(f'<figure class="v-depo"><blockquote>“{esc(d["texto"])}”</blockquote><figcaption><b>{esc(d["nome"])}</b> · {esc(d["curso"])}</figcaption></figure>' for d in PROVA)}</div>
      </div>
    </section>'''
    else:
        depoimentos = ""
    faq_lista = [(p, faq_texto(r), faq_html(r, h)) for p, r, h in FAQ_GERAL]
    faq_sec = f'''
    <section class="v-secao v-so-geral" id="duvidas" aria-labelledby="faq-titulo" data-secao="faq">
      <div class="v-wrap v-faq-grade">
        <div class="v-faq-cab">
          <p class="v-kicker">Perguntas frequentes</p>
          <h2 class="v-h2" id="faq-titulo">Antes de se inscrever.</h2>
          <p class="v-faq-texto">Não encontrou o que procura? Pergunte no chat “Fale com a gente”.</p>
          <a class="v-btn v-btn-preto" href="#chat" data-abrir-chat data-assunto="matricula" data-local="faq">Abrir o chat {SVG["chat"]}</a>
        </div>
        <div class="v-faq-lista">{detalhes([(p, h) for p, _, h in faq_lista])}</div>
      </div>
    </section>'''

    opcoes_turma = '<option value="">Escolha o curso</option>'
    for g in dados["grupos"]:
        itens = "".join(f'<option value="{s}" data-catalogo="1" data-nome="{esc(cursos[s]["nome"])}">{esc(cursos[s]["nome"])}</option>'
                        for s in g["cursos"] if s in cursos)
        opcoes_turma += f'<optgroup label="{esc(g["titulo"])}">{itens}</optgroup>'
    extras_turma = "".join(f'<option value="{s}" data-catalogo="0" data-nome="{esc(n)}">{esc(n)}</option>' for s, n in TURMA_EXTRAS.items())
    opcoes_turma += f'<optgroup label="Só sob demanda">{extras_turma}</optgroup>'
    empresas = f'''
    <section class="v-empresas v-so-geral" id="empresas" aria-labelledby="empresas-titulo" data-secao="empresas">
      <div class="v-wrap v-empresas-grade">
        <div>
          <p class="v-kicker">Turmas para grupos</p>
          <h2 id="empresas-titulo">Precisa capacitar uma equipe?</h2>
          <p class="v-empresas-texto">Empresas, escolas, condomínios e instituições podem pedir uma turma só do grupo, de {TURMA_MINIMO} a {TURMA_MAXIMO} pessoas, na sede. O valor por pessoa é o mesmo dos cursos do catálogo: matrícula + taxa de inscrição. Qualquer curso também em inglês. Há ainda primeiros socorros para jovens de 12 a 14 anos, numa turma só dessa idade. O valor dessa turma vem na proposta da secretaria.</p>
        </div>
        <div class="v-empresas-acao">
          <button class="v-btn v-btn-contorno v-btn-grande" type="button" data-turma-abrir="faixa" aria-expanded="false" aria-controls="turma-form-bloco">Solicitar uma turma</button>
          <p class="v-empresas-nota">Nada é cobrado agora. A secretaria responde por e-mail em até 3 dias úteis.</p>
        </div>
      </div>
      __TURMA_DIALOG__
    </section>'''
    final = f'''
    <section class="v-final v-secao v-escuro-fundo v-so-geral" aria-labelledby="final-titulo" data-secao="final">
      <div class="v-wrap">
        <h2 id="final-titulo">Pronto para começar?</h2>
        <p>Escolha o curso, veja a próxima turma e o total à vista, e faça sua inscrição.</p>
        <div class="v-final-acoes">
          <a class="v-btn v-btn-branco v-btn-grande" href="#cursos">Ver cursos e valores {SVG["seta"]}</a>
          <a class="v-btn v-btn-contorno-branco v-btn-grande" href="#chat" data-abrir-chat data-assunto="matricula" data-local="final">Tirar dúvida no chat</a>
        </div>
      </div>
    </section>'''

    # ============================================================================================================
    # 13. rodapé, barra fixa e dialogs
    # ============================================================================================================
    rodape = f'''
  <footer class="v-rodape">
    <div class="v-wrap v-rodape-grade">
      <div class="v-rodape-marca">
        <a class="v-marca" href="/matricula-cursos-presenciais/" data-voltar="">{marca}</a>
        <p>Capacitação presencial para quem quer cuidar, prevenir e agir com responsabilidade.</p>
        <a class="v-selo-link" href="/"><span class="v-cruz">{SVG["cruz"]}</span>Site da Cruz Vermelha RJ {SVG["externo"]}</a>
      </div>
      <nav aria-label="Rodapé">
        <p class="v-rodape-titulo">Navegação</p>
        <ul class="v-rodape-lista">
          <li><a href="#cursos">Cursos</a></li>
          <li><a href="#como-funciona">Como funciona</a></li>
          <li><a href="#escola">Quem somos</a></li>
          <li><a href="#duvidas">Dúvidas</a></li>
          <li><a href="{link_escola("rodape")}" target="_blank" rel="noopener" data-saida="area_aluno" data-origem="rodape">Área do aluno {SVG["externo"]}</a><small>para quem já se inscreveu</small></li>
        </ul>
      </nav>
      <div>
        <p class="v-rodape-titulo">Contato</p>
        <ul class="v-rodape-contato">
          <li>{SVG["pino"]}<span>Praça da Cruz Vermelha, 10<br>Centro · Rio de Janeiro</span></li>
          <li>{SVG["email"]}<a href="mailto:{EMAIL_CONTATO}">{EMAIL_CONTATO}</a></li>
          <li>{SVG["chat"]}<a href="#chat" data-abrir-chat data-assunto="matricula" data-local="rodape">Chat “Fale com a gente”</a></li>
        </ul>
      </div>
    </div>
    <div class="v-rodape-faixa">
      <div class="v-wrap">
        <p>© 2026 Cruz Vermelha Brasileira – Filial do Estado do Rio de Janeiro · CNPJ {CNPJ_FILIAL}</p>
        <p class="v-rodape-legal">
          <a href="/privacidade/">Política de Privacidade</a>
          <a href="/termos/">Termos de Uso</a>
          <a href="/cookies/">Cookies</a>
          <a href="/reembolso/">Cancelamento e reembolso</a>
          <a href="/cookies/#preferencias" data-cvrj-cookies>Preferências de cookies</a>
        </p>
      </div>
    </div>
  </footer>'''
    barra = f'''
  <div class="v-barra" id="mr-barra" role="region" aria-label="Inscrição no curso" hidden>
    <div class="v-barra-texto"><span class="v-barra-rotulo">Total à vista</span><b class="v-barra-valor" id="mr-barra-nome"></b><span class="v-barra-agora" id="mr-barra-sub">Agora: taxa de {taxa}</span></div>
    <a class="v-btn v-btn-vermelho mr-cta" id="mr-barra-cta" data-local="barra" data-curso="" href="{CHECKOUT_URL}?via=barra"><span class="v-barra-longo">Reservar vaga</span><span class="v-barra-curto">Reservar</span>{SVG["seta"]}</a>
  </div>'''
    aviso_dialog = f'''
  <dialog class="mr-modal v-aviso-dialog" id="aviso-data" aria-labelledby="aviso-titulo">
    <button class="mr-modal-fechar" type="button" data-aviso-fechar aria-label="Fechar">&times;</button>
    <form id="aviso-form" novalidate>
      <h2 id="aviso-titulo">Avisamos quando abrir a turma de <span data-aviso-curso>este curso</span></h2>
      <input type="hidden" name="curso" value="">
      <div class="mr-tf-grade" style="margin-top:18px">
        <div class="mr-tf-campo mr-tf-largo">
          <label for="aviso-nome">Seu nome</label>
          <input id="aviso-nome" name="nome" maxlength="120" autocomplete="name" required>
          <p class="mr-tf-erro" id="aviso-erro-nome" hidden></p>
        </div>
        <div class="mr-tf-campo mr-tf-largo">
          <label for="aviso-email">Seu e-mail</label>
          <input id="aviso-email" name="email" type="email" maxlength="190" autocomplete="email" required>
          <p class="mr-tf-erro" id="aviso-erro-email" hidden></p>
        </div>
        <div class="mr-tf-campo mr-tf-largo">
          <label class="mr-tf-check"><input type="checkbox" name="consentimento" required> Autorizo a Cruz Vermelha Brasileira Rio de Janeiro a me avisar por e-mail quando abrir a turma deste curso.</label>
          <p class="mr-tf-erro" id="aviso-erro-consentimento" hidden></p>
        </div>
      </div>
      <div class="mr-tf-armadilha" aria-hidden="true"><label for="aviso-site">Não preencha</label><input id="aviso-site" name="site" tabindex="-1" autocomplete="off"></div>
      <p class="mr-tf-erro geral" id="aviso-erro" tabindex="-1" role="alert" hidden></p>
      <button class="v-btn v-btn-vermelho" id="aviso-enviar" type="submit">Avise-me</button>
      <p class="mr-tf-dica">Nada é cobrado. Usamos o seu e-mail só para este aviso.</p>
    </form>
    <div class="mr-tf-ok" id="aviso-ok" tabindex="-1" hidden>
      {SVG["check-circulo"]}
      <h2>Aviso anotado</h2>
      <p id="aviso-ok-texto"></p>
      <button class="v-btn v-btn-contorno" type="button" data-aviso-fechar>Fechar</button>
    </div>
  </dialog>'''

    # --- dados estruturados (3.1) ------------------------------------------------------------------------------
    provedor = {"@type": "EducationalOrganization", "@id": f"{ESCOLA}/#escola",
                "name": "Escola de Educação e Saúde CVB-RJ", "url": f"{ESCOLA}/", "address": ENDERECO,
                "parentOrganization": {"@id": f"{ORIGEM}/#organizacao"}}
    itens_ld = []
    for i, s in enumerate(ordem, 1):
        c = cursos[s]
        descricao = nome_filial(c["descricao"] or (c["sobre"][0] if c["sobre"] else ""))
        if s in HOMOLOGACAO:
            descricao = descricao.rstrip() + " Homologação à parte, paga pelo aluno no fim do curso."
        oferta = {"@type": "Offer", "category": "Paid", "name": "Matrícula à vista + taxa de inscrição",
                  "price": f"{total(s) / 100:.2f}", "priceCurrency": "BRL",
                  "availability": "https://schema.org/InStock" if s in com_turma else "https://schema.org/PreOrder",
                  "url": f"{URL_PAGINA}?curso={s}",
                  "priceSpecification": {"@type": "CompoundPriceSpecification", "price": f"{total(s) / 100:.2f}", "priceCurrency": "BRL",
                                         "priceComponent": [
                                             {"@type": "UnitPriceSpecification", "name": "Matrícula (valor do curso), à vista",
                                              "price": f"{mat(s) / 100:.2f}", "priceCurrency": "BRL"},
                                             {"@type": "UnitPriceSpecification", "name": "Taxa de inscrição",
                                              "price": f"{inscricao / 100:.2f}", "priceCurrency": "BRL"}]}}
        instancia = {"@type": "CourseInstance", "courseMode": "Onsite"}
        if horas_iso(c["carga_horaria"]):
            instancia["courseWorkload"] = horas_iso(c["carga_horaria"])
        if s in com_turma:
            t = turmas[s][0]
            oferta["validThrough"] = t.inscricoes_ate.isoformat()
            instancia["startDate"] = t.inicio.isoformat()
            instancia["endDate"] = t.fim.isoformat()
        instancia["location"] = LOCAL
        curso_ld = {"@type": "Course", "name": c["nome"], "description": descricao, "url": f"{URL_PAGINA}?curso={s}",
                    "provider": {"@id": provedor["@id"]},
                    "educationalCredentialAwarded": "Certificado da Cruz Vermelha Brasileira Rio de Janeiro",
                    "offers": [oferta], "hasCourseInstance": [instancia]}
        if (PASTA_IMG / f"{c['imagem']}-960.webp").exists():
            curso_ld["image"] = f"{URL_PAGINA}img/{c['imagem']}-960.webp"
        if horas_iso(c["carga_horaria"]):
            curso_ld["timeRequired"] = horas_iso(c["carga_horaria"])
        itens_ld.append({"@type": "ListItem", "position": i, "item": curso_ld})
    ld = [
        {"@context": "https://schema.org", "@type": "BreadcrumbList", "itemListElement": [
            {"@type": "ListItem", "position": 1, "name": "Início", "item": f"{ORIGEM}/"},
            {"@type": "ListItem", "position": 2, "name": "Cursos presenciais", "item": URL_PAGINA}]},
        {"@context": "https://schema.org", **provedor},
        {"@context": "https://schema.org", "@type": "ItemList", "name": "Cursos presenciais da Cruz Vermelha Brasileira Rio de Janeiro",
         "numberOfItems": len(dados["cursos"]), "url": URL_PAGINA, "itemListElement": itens_ld},
        {"@context": "https://schema.org", "@type": "FAQPage", "mainEntity": [
            {"@type": "Question", "name": p, "acceptedAnswer": {"@type": "Answer", "text": r}} for p, r, _ in faq_lista]},
    ]
    for d in ld:
        assert "</" not in json.dumps(d, ensure_ascii=False)
    ld_html = "".join(f'\n  <script type="application/ld+json">\n{json.dumps(d, ensure_ascii=False, indent=2)}\n  </script>' for d in ld)

    # --- modo curso, turmas vencidas e pílula de dias antes da primeira pintura ---------------------------------
    css_modo = []
    for s in exibicao:
        css_modo.append(f'html[data-curso="{s}"] .v-curso[data-curso="{s}"]{{display:block}}')
        css_modo.append(f'html[data-curso="{s}"] .v-card[data-curso="{s}"]{{display:none}}')
    for s in com_turma:
        css_modo.append(f'html[data-breve~="{s}"] .v-se-aberta[data-turma-de="{s}"],'
                        f'html:not([data-breve~="{s}"]) .v-se-breve[data-turma-de="{s}"]{{display:none!important}}')
        css_modo.append(f'html[data-destaque="{s}"] .v-destaque[data-curso="{s}"]{{display:block}}')
        css_modo.append(f'html[data-dias~="{s}"] .v-dias[data-turma-de="{s}"]{{display:inline-flex}}')
        css_modo.append(f'.v-dias[data-turma-de="{s}"]::before{{content:var(--v-dias-{s})}}')
    turmas_js = {s: [int(turmas[s][0].inscricoes_ate.timestamp() * 1000), turmas[s][0].inicio.date().isoformat()] for s in com_turma}
    # Preload da foto do topo antes da primeira pintura: no modo geral, a do celular ou a do computador (media); no modo
    # curso, a capa do curso aberto (os anúncios abrem o modo curso).
    capas_js = {}
    for s_ in exibicao:
        real = CAPA_CURSO.get(s_)
        if real:
            capas_js[s_] = [f"/assets/otim/{real['base']}-{real['largura']}.webp", srcset_otim(real)]
        elif (PASTA_IMG / f"{cursos[s_]['imagem']}-960.webp").exists():
            im = cursos[s_]["imagem"]
            capas_js[s_] = [f"img/{im}-960.webp", f"img/{im}-480.webp 480w, img/{im}-960.webp 960w"]
    preload_fn = ("function pl(h,s,m){var l=document.createElement('link');l.rel='preload';l.as='image';l.href=h;"
                  "l.setAttribute('imagesrcset',s);l.setAttribute('imagesizes','100vw');if(m)l.media=m;document.head.appendChild(l);}")
    topo_cel = f"/assets/otim/{FOTO_TOPO['base']}-{FOTO_TOPO['largura']}.webp"
    topo_pc = f"/assets/otim/{FOTO_TOPO_PC['base']}-{FOTO_TOPO_PC['largura']}.webp"
    preload_geral = (f"pl({json.dumps(topo_cel)},{json.dumps(srcset_otim(FOTO_TOPO))},'(max-width: 650px)');"
                     f"pl({json.dumps(topo_pc)},{json.dumps(srcset_otim(FOTO_TOPO_PC))},'(min-width: 651px)');")
    modo_js = ("(function(){var d=document.documentElement;d.className+=(d.className?' ':'')+'js';try{"
               f"{preload_fn}var S={json.dumps(exibicao)},T={json.dumps(turmas_js)},O={json.dumps(ordem_turmas)},P={json.dumps(capas_js)},agora=Date.now(),breve=[],dias=[],dest='breve',hoje;"
               "try{hoje=new Intl.DateTimeFormat('en-CA',{timeZone:'America/Sao_Paulo',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date(agora));}"
               "catch(e){hoje=new Date(agora-108e5).toISOString().slice(0,10);}"
               "var h=hoje.split('-');"
               "for(var k in T){if(agora>T[k][0])breve.push(k);else{var i=T[k][1].split('-'),"
               "n=Math.round((Date.UTC(+i[0],i[1]-1,+i[2])-Date.UTC(+h[0],h[1]-1,+h[2]))/864e5);"
               "if(n>=1){dias.push(k);d.style.setProperty('--v-dias-'+k,'\"'+(n===1?'Começa amanhã':'Começa em '+n+' dias')+'\"');}}}"
               "for(var j=0;j<O.length;j++)if(breve.indexOf(O[j])<0){dest=O[j];break;}"
               "if(breve.length)d.setAttribute('data-breve',breve.join(' '));"
               "if(dias.length)d.setAttribute('data-dias',dias.join(' '));"
               "d.setAttribute('data-destaque',dest);"
               "var q=new URLSearchParams(location.search),c=q.get('curso');"
               "if(!c){var m=location.hash.match(/^#(?:det|curso)-([a-z0-9-]+)$/);if(m)c=m[1];}"
               "if(c&&!q.get('turma')&&S.indexOf(c)>=0){d.setAttribute('data-curso',c);if(P[c])pl(P[c][0],P[c][1],'');}"
               f"else{{{preload_geral}}}"
               "}catch(e){}})();")

    dados_js = {s: {"nome": cursos[s]["nome"], "curto": curto(s), "matricula": mat(s) / 100, "total": total(s) / 100,
                    "total_txt": brl_curto(total(s)), "turma": s in com_turma,
                    "titulo": TITULO_CURSO.format(curso=cursos[s]["nome"])} for s in exibicao}
    css = (CSS_PAGINA.replace("@@FONTES@@", fontes_da_home(home))
           .replace("@@CSS_MODO@@", "/* modo curso, turmas e dias (gerado por slug) */\n    " + "\n    ".join(css_modo)))
    js = (JS_PAGINA.replace("__CHECKOUT__", json.dumps(CHECKOUT_URL)).replace("__INSCRICAO__", f"{inscricao / 100:.2f}")
          .replace("__INSC_CURTO__", json.dumps(taxa)).replace("__CURSOS__", json.dumps(dados_js, ensure_ascii=False))
          .replace("__EXIBICAO__", json.dumps(exibicao)).replace("__API_TURMAS__", json.dumps(API_TURMAS)))
    descricao = (f"Primeiros socorros, bombeiro civil, cuidador de idosos e mais, com certificado da Cruz Vermelha. Total à vista a "
                 f"partir de {menor_total}, já com a taxa de inscrição.")

    pagina = f"""<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{esc(TITULO)}</title>
  <meta name="description" content="{esc(descricao)}">
  <link rel="canonical" href="{URL_PAGINA}">
  <link rel="icon" href="/favicon.ico">
  <meta name="theme-color" content="#d9081f">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Cruz Vermelha Brasileira Rio de Janeiro">
  <meta property="og:locale" content="pt_BR">
  <meta property="og:title" content="{esc(OG_TITULO)}">
  <meta property="og:description" content="{esc(descricao)}">
  <meta property="og:url" content="{URL_PAGINA}">
  <meta property="og:image" content="{IMAGEM_OG}">
  <meta property="og:image:width" content="{IMAGEM_OG_TAMANHO[0]}">
  <meta property="og:image:height" content="{IMAGEM_OG_TAMANHO[1]}">
  <meta property="og:image:alt" content="Turma da Cruz Vermelha Brasileira Rio de Janeiro no salão da sede">
  <meta name="twitter:card" content="summary_large_image">
  <script>{modo_js}</script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400..900&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400..900&display=swap"></noscript>
{css}
{ga4}
{pixel}
{ld_html}
</head>
<body class="v">
  <a class="v-pular" href="#cursos"><span class="v-so-geral">Pular para os cursos</span><span class="v-so-curso">Pular para turma e valores</span></a>
{faixa_topo}
{cabecalho}
  <main id="conteudo">
{hero}
{destaques}
{secoes_curso}
{chegar_curso}
{cursos_sec}
{como}
{certificado}
{quem_somos}
{depoimentos}
{faq_sec}
{empresas}
{final}
  </main>
{rodape}
{barra}
{aviso_dialog}
{js}
  <script src="{url_estatico("turmas.js")}" defer></script>
{chat_tags}
</body>
</html>
"""
    pagina = pagina.replace("__TURMA_DIALOG__", turma_dialog(opcoes_turma))

    # --- travas ---------------------------------------------------------------------------------------------------
    achados = [m for m in MARCADORES_PROIBIDOS if m in pagina]
    if achados:
        print(f"recusado: a página tem marcadores sem dado real ({', '.join(achados)}). Preencha ou tire antes de gerar.")
        return 1
    if pagina.count("<h1") != 1:
        onde = [pagina[m.start():m.start() + 80] for m in re.finditer("<h1", pagina)]
        print(f"recusado: a página precisa de exatamente um <h1> (achados: {onde})")
        return 1
    so_texto = html.unescape(re.sub(r"<[^>]+>", " ", pagina))
    proibidas = []
    for padrao in PALAVRAS_PROIBIDAS:
        for alvo in (pagina, so_texto):
            m = re.search(padrao, alvo, re.I)
            if m:
                proibidas.append(f"{padrao!r} em “…{alvo[max(0, m.start() - 50):m.end() + 30].strip()}…”")
                break
    if proibidas:
        print("recusado: a página tem palavras proibidas (PALAVRAS_PROIBIDAS):\n  " + "\n  ".join(proibidas))
        return 1
    reservas = [nome for nome, trecho in sem_turma_variantes if re.search(r"\breserv", html.unescape(re.sub(r"<[^>]+>", " ", trecho)), re.I)]
    if reservas:
        print(f"recusado: variante sem turma fala em reservar ({', '.join(reservas)})")
        return 1
    totais = {s: total(s) for s in cursos}
    for s in cursos:
        if f"Total à vista: {brl_curto(totais[s])}" not in pagina:
            print(f"recusado: o bloco de preço de {s} não mostra o total à vista")
            return 1
    # "R$" e o número na mesma linha (espaço que não quebra), na página, no JSON-LD e nos textos do JS.
    pagina = re.sub(r"R\$ (?=\d)", "R$\u00a0", pagina)
    SAIDA.write_text(pagina, encoding="utf-8")
    print(f"gravado {SAIDA.relative_to(RAIZ)} ({len(pagina.encode('utf-8'))} bytes, {len(ordem)} cursos, "
          f"{len(com_turma)} com turma aberta: {', '.join(com_turma) or 'nenhum'})")
    return 0


def turma_dialog(opcoes_turma: str) -> str:
    """O dialog do pedido de turma para grupos: static/turmas.js e api/turmas.php esperam estes ids (D22: sem WhatsApp)."""
    return f'''
      <noscript><style>#turma-form-bloco{{display:block!important;position:static;margin:24px auto 0}}</style></noscript>
      <dialog class="mr-demanda-form mr-modal" id="turma-form-bloco" aria-labelledby="turma-form-titulo">
        <button class="mr-modal-fechar" type="button" data-turma-fechar aria-label="Fechar">&times;</button>
        <form id="turma-form" novalidate>
          <h3 id="turma-form-titulo">Peça sua turma ou entre na lista</h3>
          <p class="mr-tf-nota">Nada é cobrado agora. A secretaria responde por e-mail em até 3 dias úteis.</p>
          <div class="mr-tf-grade">
            <div class="mr-tf-campo mr-tf-largo">
              <label for="tf-curso">Curso</label>
              <select id="tf-curso" name="curso" required>{opcoes_turma}</select>
              <p class="mr-tf-erro" id="tf-erro-curso" hidden></p>
            </div>
            <fieldset class="mr-tf-campo">
              <legend>Idioma das aulas</legend>
              <div class="mr-tf-opcoes">
                <label><input type="radio" name="idioma" value="pt" checked> Português</label>
                <label><input type="radio" name="idioma" value="en"> <span>Inglês <span lang="en">(English)</span></span></label>
              </div>
            </fieldset>
            <div class="mr-tf-campo">
              <label for="tf-pessoas">Quantos alunos?</label>
              <input id="tf-pessoas" name="pessoas" type="number" inputmode="numeric" min="1" max="300" step="1" required placeholder="Ex.: 20">
              <p class="mr-tf-dica">Conte todos os alunos, inclusive você, se também for fazer o curso.</p>
              <p class="mr-tf-erro" id="tf-erro-pessoas" hidden></p>
            </div>
          </div>
          <p class="mr-tf-situacao" id="tf-situacao" aria-live="polite">Escolha o curso e diga quantos alunos são para ver como fica a turma.</p>
          <a class="v-btn v-btn-vermelho" id="tf-matricula" href="{CHECKOUT_URL}" hidden>Continuar para a inscrição</a>
          <div id="tf-dados" hidden>
            <p class="mr-tf-nota" id="tf-nota-jovens" hidden>Para menores de idade, quem preenche é o responsável ou a instituição. Não pedimos dados dos jovens agora.</p>
            <div class="mr-tf-grade">
              <fieldset class="mr-tf-campo mr-tf-largo" id="tf-bloco-local" hidden>
                <legend>Onde seriam as aulas?</legend>
                <div class="mr-tf-opcoes">
                  <label><input type="radio" name="local" value="sede" checked> Na sede, Praça da Cruz Vermelha, 10</label>
                  <label><input type="radio" name="local" value="outro"> Em outro local (sujeito a aprovação)</label>
                </div>
              </fieldset>
              <div class="mr-tf-campo mr-tf-largo" id="tf-bloco-endereco" hidden>
                <label for="tf-endereco">Onde?</label>
                <input id="tf-endereco" name="local_endereco" maxlength="200" placeholder="Bairro e cidade, ou o endereço">
                <p class="mr-tf-erro" id="tf-erro-local_endereco" hidden></p>
              </div>
              <div class="mr-tf-campo">
                <label for="tf-organizacao">Empresa, escola ou instituição <small>(opcional)</small></label>
                <input id="tf-organizacao" name="organizacao" maxlength="160" autocomplete="organization">
              </div>
              <div class="mr-tf-campo">
                <label for="tf-periodo">Quando seria bom? <small>(opcional)</small></label>
                <input id="tf-periodo" name="periodo" maxlength="200" placeholder="Ex.: sábados de manhã, em novembro">
              </div>
              <div class="mr-tf-campo">
                <label for="tf-nome">Seu nome</label>
                <input id="tf-nome" name="nome" maxlength="120" autocomplete="name" required>
                <p class="mr-tf-erro" id="tf-erro-nome" hidden></p>
              </div>
              <div class="mr-tf-campo">
                <label for="tf-email">E-mail</label>
                <input id="tf-email" name="email" type="email" maxlength="190" autocomplete="email" required>
                <p class="mr-tf-erro" id="tf-erro-email" hidden></p>
              </div>
              <div class="mr-tf-campo">
                <label for="tf-telefone">Celular com DDD</label>
                <input id="tf-telefone" name="telefone" type="tel" maxlength="20" autocomplete="tel" inputmode="tel" required placeholder="(21) 99999-9999">
                <p class="mr-tf-erro" id="tf-erro-telefone" hidden></p>
              </div>
              <div class="mr-tf-campo mr-tf-largo">
                <label for="tf-observacoes">Observações <small>(opcional)</small></label>
                <textarea id="tf-observacoes" name="observacoes" maxlength="2000" rows="3" placeholder="Algo que a secretaria precisa saber sobre o grupo"></textarea>
                <p class="mr-tf-erro" id="tf-erro-observacoes" hidden></p>
              </div>
              <div class="mr-tf-campo mr-tf-largo">
                <label class="mr-tf-check"><input type="checkbox" name="consentimento" required> Autorizo a secretaria da Cruz Vermelha Brasileira Rio de Janeiro a falar comigo por e-mail e telefone sobre esta turma.</label>
                <p class="mr-tf-erro" id="tf-erro-consentimento" hidden></p>
              </div>
            </div>
            <div class="mr-tf-armadilha" aria-hidden="true"><label for="tf-site">Não preencha</label><input id="tf-site" name="site" tabindex="-1" autocomplete="off"></div>
            <p class="mr-tf-erro geral" id="tf-erro" tabindex="-1" role="alert" hidden></p>
            <button class="v-btn v-btn-vermelho" id="tf-enviar" type="submit" disabled>Pedir minha turma</button>
            <p class="mr-tf-dica">Seus dados servem só para falar sobre esta turma. Veja a <a href="/privacidade/">política de privacidade</a>.</p>
          </div>
          <noscript><p class="mr-tf-situacao aviso">Para pedir uma turma, escreva para {EMAIL_CONTATO} com o curso, o idioma e quantos alunos são.</p></noscript>
        </form>
        <div class="mr-tf-ok" id="turma-ok" tabindex="-1" hidden>
          {SVG["check-circulo"]}
          <h3 id="turma-ok-titulo">Pedido recebido!</h3>
          <p id="turma-ok-texto"></p>
          <p class="mr-tf-dica" id="turma-ok-copia"></p>
          <button class="v-btn v-btn-contorno" id="turma-ok-outro" type="button">Fazer outro pedido</button>
        </div>
      </dialog>'''


if __name__ == "__main__":
    raise SystemExit(main())
