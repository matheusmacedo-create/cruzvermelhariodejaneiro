#!/usr/bin/env python3
"""Gera site/matricula-cursos-presenciais/index.html a partir de cursos.json e do padrão visual da home.

A página é montada com o MESMO <style>, cabeçalho, rodapé, GA4, Meta Pixel e script de menu de site/index.html.
O conteúdo dos cursos vem de site/matricula-cursos-presenciais/cursos.json (scripts/sincronizar_catalogo.py, a partir
do catálogo público da escola) e as fotos de img/ (scripts/gerar_imagens_matricula.py); o certificado de amostra, de
scripts/gerar_certificado_modelo.py; as fotos reais da sede, de /assets/otim (fora do Git, só no servidor).

Reconstrução de 05/10/2026 (pedido do Matheus: página curta, clara, institucional, celular primeiro, focada na matrícula;
referência: escola.cursoscruzvermelha.org). Ordem: topo → categorias → cursos → fotos → como funciona → certificado →
história → local → dúvidas → empresas e grupos → chamada final. Sem "próxima turma" nem datas (não há fonte de dados;
decisão do Matheus). Cada curso tem um cartão (foto, categoria, frase, carga, requisito, preço em duas linhas, "Garantir
vaga" e "Ver detalhes") e uma ficha (.mr-det) escondida: "Ver detalhes" a leva para a janela #mr-janela (dialog) e a devolve
ao fechar; sem JavaScript, #det-<slug> a mostra por :target; com ?curso=<slug> (anúncio, home, chat) ela abre no topo, antes
da primeira pintura, e o ViewContent sai ao carregar. Barra fixa no celular ("Ver cursos"; com curso em foco, "Garantir
minha vaga"), navegação fixa da página no computador. O formulário de turma (static/turmas.js, api/turmas.php) é a janela
#turma-form-bloco, aberta pelos botões data-turma-abrir. Regras que continuam: cara institucional, sem urgência falsa, sem
depoimento ou número inventado (o gerador recusa marcadores [INSERIR …]), sem telefone nem WhatsApp da secretaria, só
afirmações com fonte ("Informação em breve" onde não há dado), prometer só o que o sistema cumpre.

Uso:  python3 scripts/sincronizar_catalogo.py && python3 scripts/gerar_imagens_matricula.py
      && python3 scripts/gerar_matricula_presencial.py
Depois: publicar site/matricula-cursos-presenciais/ (index.html + img/) com scripts/publicar_hostinger.sh.

Os botões levam ao checkout (CHECKOUT_URL) com ?curso=<slug>&via=<lugar do botão>; o script da página acrescenta as
UTMs/fbclid/gclid da URL atual, e passa as utm_* do anúncio aos links da escola. Sem JavaScript os links já funcionam.
Link de anúncio para turma: ?turma=1&turma_curso=<slug>&idioma=en&alunos=15 abre o formulário preenchido.
"""
from __future__ import annotations

import hashlib
import html
import json
import os
import re
import sys
from pathlib import Path

import chat_widget
import icones
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
CHECKOUT_URL = "/matricula-cursos-presenciais/checkout/"
STATIC = RAIZ / "site" / "matricula-cursos-presenciais" / "static"
STATIC_URL = "/matricula-cursos-presenciais/static/"

# Turmas sob demanda: as mesmas regras de api/lib/turmas.php (MCP_TURMA_MINIMO, MCP_TURMA_MAXIMO e
# MCP_TURMA_CURSOS_EXTRAS). Mudou lá, muda aqui; scripts/testar_checkout.php confere os dois.
TURMA_MINIMO = 15
TURMA_MAXIMO = 30
TURMA_EXTRAS = {"primeiros-socorros-jovens": "Primeiros Socorros para Jovens (12 a 14 anos)"}

# Título e descrição seguem as consultas do Search Console (docs/seo-consultas-2026-09.md): "cruz vermelha cursos",
# "cursos cruz vermelha rj", "curso de primeiros socorros cruz vermelha rj".
TITULO = "Cursos presenciais | Cruz Vermelha Brasileira Rio de Janeiro"
DESCRICAO = ("Cursos presenciais da Cruz Vermelha Brasileira Rio de Janeiro, no Centro do Rio: primeiros socorros, bombeiro "
             "civil, cuidador de idosos e mais. Carga horária, valores e matrícula com inscrição de R$ 99.")
IMAGEM_OG = f"{ORIGEM}/assets/otim/og-matricula.jpg"
IMAGEM_OG_TAMANHO = (1200, 630)
ENDERECO = {"@type": "PostalAddress", "streetAddress": "Praça da Cruz Vermelha, 10", "addressLocality": "Rio de Janeiro",
            "addressRegion": "RJ", "postalCode": "20230-130", "addressCountry": "BR"}
LOCAL = {"@type": "Place", "name": "Cruz Vermelha Brasileira – Filial do Estado do Rio de Janeiro", "address": ENDERECO}

# O mesmo texto vai nos e-mails ao aluno (MCP_TEXTO_ESTORNO, api/lib/email.php). Regras completas: /reembolso/.
# Desde o redesenho de 04/10 ele não é mais impresso nesta página (a garantia usa o texto literal de /reembolso/).
TEXTO_ESTORNO = ("A inscrição reserva sua vaga. Você pode desistir em até 7 dias depois do pagamento e recebe o valor "
                 "de volta. Depois disso, o valor também é estornado se não houver horário compatível ou se você "
                 "desistir antes da confirmação da aula. O prazo para aparecer na conta depende de PIX ou cartão.")

# --- chaves do redesenho (04/10/2026) ---------------------------------------------------------------
# A matrícula paga entra sozinha na turma aberta da escola (README, "Matrícula paga entra na plataforma da escola",
# 28/09; api/config-escola.php existe no servidor). False volta aos textos da versão B ("a secretaria confirma").
ESCOLA_MATRICULA_AUTOMATICA = True
# "Total do curso com a inscrição": só depois de a filial confirmar por escrito que os R$ 99 quitam a inscrição de
# R$ 100 da escola (pergunta em aberto no briefing). Até lá, "inscrição agora" e "valor do curso depois".
MOSTRAR_TOTAL = False
# Cláusula de /reembolso/ "depois dos 7 dias": só depois de a filial dizer se a matrícula automática numa turma com
# data já conta como "turma confirmada". Até lá, a página cita só os 7 dias e leva às regras completas.
GARANTIA_APOS_7_DIAS = False
# Ordem dos cartões (só exibição: o ItemList do JSON-LD segue os grupos do cursos.json). Curso novo fora da lista
# entra no fim, com aviso no terminal.
ORDEM_EXIBICAO = ["primeiros-socorros-basico", "suporte-basico-de-vida", "primeiros-socorros-lei-lucas",
                  "puncao-venosa", "bombeiro-civil", "cuidador-de-idosos", "micropigmentacao-labial"]
NOME_CURTO = {"primeiros-socorros-lei-lucas": "Primeiros Socorros Lei Lucas", "cuidador-de-idosos": "Cuidador de Idosos"}
# Uma linha por curso, só com o que está no cursos.json (sobre, descrição ou FAQ do curso).
BENEFICIO = {
    "primeiros-socorros-basico": "Do engasgo à RCP: agir até o socorro chegar",
    "suporte-basico-de-vida": "RCP e desfibrilador em 4 horas, com prática",
    "primeiros-socorros-lei-lucas": "RCP, engasgo, quedas e convulsões em crianças",
    "puncao-venosa": "Acesso venoso seguro, com prática supervisionada",
    "bombeiro-civil": "Incêndio, primeiros socorros e evacuação em 80h",
    "cuidador-de-idosos": "Higiene, mobilização e prevenção de acidentes",
    "micropigmentacao-labial": "Colorimetria, biossegurança e prática da técnica",
}
# Copy de cada curso (revisão de 04/10/2026, cada afirmação conferida contra o cursos.json e a faq-home.json).
# Ficha (modo curso): título, promessa antes do botão; "aprende", "para quem" e a objeção principal depois dele.
# Cartão: "aprende" e "para quem" abrem os detalhes. Limites: nada de promessa de emprego ou renda, de habilitação
# profissional ou de "cumpre a Lei Lucas". Na objeção, {insc} e {curso} viram a inscrição e o valor do curso.
COPY_CURSO = {
    "primeiros-socorros-basico": {
        "titulo": "Primeiros Socorros Básico na Cruz Vermelha: agir até o socorro chegar",
        "promessa": "Em 8 horas, com prática supervisionada, você aprende RCP, o uso do desfibrilador e o que fazer em engasgos, desmaios e hemorragias.",
        "aprende": ["RCP e uso do desfibrilador externo automático (DEA)", "O que fazer em engasgos, desmaios e traumas",
                    "Avaliar a vítima e controlar hemorragias"],
        "para_quem": "Para qualquer pessoa, mesmo fora da área da saúde, que quer saber agir em casa, na escola, no trabalho ou no esporte.",
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
        "aprende": ["Avaliar a vítima e fazer RCP (reanimação cardiopulmonar)", "Usar o desfibrilador externo automático (DEA)",
                    "Agir em engasgos, desmaios, hemorragias e traumas"],
        "para_quem": "Profissionais da saúde, educação, segurança e empresas, e qualquer pessoa que queira agir certo numa emergência, sem experiência prévia.",
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
        "aprende": ["Avaliar a criança e fazer RCP (reanimação cardiopulmonar)", "Agir em engasgo, queda, convulsão, queimadura e hemorragia",
                    "Medidas de prevenção e protocolos atualizados para crianças"],
        "para_quem": "Professores e funcionários de escolas e espaços de recreação infantil (Lei 13.722/2018), creches e famílias que cuidam de crianças.",
        "objecao": ("O que é a Lei Lucas? Preciso ser da área da saúde para fazer o curso?",
                    "A Lei Lucas (Lei Federal 13.722/2018) obriga escolas de educação básica, públicas e privadas, e espaços de "
                    "recreação infantil a capacitar professores e funcionários em noções básicas de primeiros socorros. Para atender a "
                    "essa exigência, a Cruz Vermelha Brasileira Rio de Janeiro oferece este curso. Não é preciso ser da saúde: os cursos "
                    "de primeiros socorros são abertos a qualquer pessoa, e a escolaridade mínima é o Ensino Fundamental. São 8 horas "
                    "presenciais na Praça da Cruz Vermelha, 10, no Centro. É curso livre: "
                    "prepara para o atendimento inicial até a chegada do socorro especializado e não substitui habilitação profissional "
                    "regulamentada. A escola que quer treinar a equipe inteira pode pedir uma turma fechada, de 15 a 30 pessoas, no "
                    "botão “Pedir turma para grupo”, nesta página."),
    },
    "puncao-venosa": {
        "titulo": "Punção Venosa na Cruz Vermelha: 8 horas com prática supervisionada",
        "promessa": "Acesso venoso com segurança e precisão: anatomia, materiais, preparo do paciente e prevenção de complicações.",
        "aprende": ["Anatomia do sistema venoso e técnicas de punção", "Escolha de dispositivos, materiais e preparo do paciente",
                    "Biossegurança e prevenção de complicações"],
        "para_quem": "Estudantes e profissionais da saúde que querem aperfeiçoar a técnica, conforme as normas da profissão. Pede Ensino Médio.",
        "objecao": ("Em 8 horas dá para praticar de verdade, ou é só teoria?",
                    "Grande parte do aprendizado acontece em atividades práticas supervisionadas, na sede da Praça da Cruz Vermelha, 10, "
                    "no Centro do Rio. Além da técnica de punção venosa, o curso aborda biossegurança, prevenção de complicações, escolha "
                    "de dispositivos e boas práticas assistenciais. A vaga é garantida com a inscrição de {insc}; os {curso} do curso "
                    "são pagos depois, na plataforma da escola."),
    },
    "bombeiro-civil": {
        "titulo": "Curso de Bombeiro Civil na Cruz Vermelha: 80 horas no Centro do Rio",
        "promessa": "Em 80 horas, aprenda a combater princípios de incêndio, prestar primeiros socorros e evacuar ambientes, com aulas práticas que simulam emergências reais.",
        "aprende": ["Prevenção e combate a princípios de incêndio", "Atendimento pré-hospitalar básico e primeiros socorros",
                    "Evacuação, uso de equipamentos e gerenciamento de riscos"],
        "para_quem": "Para quem tem Ensino Médio e quer atuar na prevenção e resposta a emergências, mesmo sem experiência na área.",
        "objecao": ("A homologação está incluída no valor do curso?",
                    "Não. A homologação é feita somente ao final do curso, à parte, com valor a consultar e paga pelo aluno. "
                    "Agora você paga só a inscrição de {insc}, que garante a vaga; o valor do curso, {curso}, é pago depois na "
                    "plataforma da escola, à vista ou parcelado com juros."),

    },
    "cuidador-de-idosos": {
        "titulo": "Cuidador de Idosos: aprenda a cuidar com segurança, na Cruz Vermelha",
        "promessa": "Em 160 horas presenciais, você aprende higiene, alimentação, mobilização, prevenção de acidentes e noções de primeiros socorros no cuidado da pessoa idosa.",
        "aprende": ["Higiene, alimentação e a rotina de cuidados do idoso", "Mobilização, prevenção de acidentes e primeiros socorros",
                    "Os aspectos físicos, emocionais e sociais do envelhecimento"],
        "para_quem": "Para quem quer trabalhar como cuidador de idosos ou cuidar de alguém da família. Pede Ensino Fundamental.",
        "objecao": ("Que certificado eu recebo? Ele é reconhecido pelo MEC?",
                    "Quem conclui recebe o certificado de curso livre emitido pela Cruz Vermelha Brasileira Rio de Janeiro, com o nome do "
                    "curso e a carga horária de 160 horas. Ele não é reconhecido pelo MEC, e nenhum curso livre é: o MEC regula a "
                    "educação formal, como ensino técnico, graduação e pós. É um diferencial valorizado no currículo, mas não substitui "
                    "habilitação profissional regulamentada."),
    },
    "micropigmentacao-labial": {
        "titulo": "Aprenda micropigmentação labial na prática, na Cruz Vermelha",
        "promessa": "Em 24 horas presenciais, você aprende a implantar pigmentos, corrigir assimetrias visuais e uniformizar a cor dos lábios, com biossegurança.",
        "aprende": ["Colorimetria e técnicas de implantação de pigmentos", "Correção de assimetrias visuais e uniformização da cor",
                    "Avaliação do cliente e cuidados pré e pós-procedimento"],
        "para_quem": "Para quem está começando na estética e para profissionais que querem ampliar seus serviços. Pede Ensino Médio, sem exigir experiência.",
        "objecao": ("Nunca trabalhei com estética. O curso serve para mim?",
                    "Serve. O curso atende tanto iniciantes quanto profissionais que desejam ampliar seus serviços, e não exige "
                    "experiência prévia: a escolaridade mínima é o Ensino Médio. São 24 horas presenciais na sede, na Praça da Cruz "
                    "Vermelha, 10, no Centro do Rio, com abordagem prática para desenvolver segurança e qualidade na execução da técnica."),
    },
}
# Comparador dos três cursos de primeiros socorros: só carga, escolaridade, valor e público.
COMPARAR = ["primeiros-socorros-basico", "suporte-basico-de-vida", "primeiros-socorros-lei-lucas"]
PUBLICO_COMPARAR = {
    "primeiros-socorros-basico": ("Inclui Lei Lucas. Para qualquer pessoa, mesmo fora da área da saúde: em casa, na escola, no "
                                  "trabalho ou no esporte."),
    "suporte-basico-de-vida": "O mais curto: profissionais da saúde, educação, segurança e empresas, e qualquer pessoa, sem experiência prévia.",
    "primeiros-socorros-lei-lucas": ("Para quem trabalha com crianças em escolas de educação básica e espaços de recreação "
                                     "infantil (Lei 13.722/2018)."),
}
UTM_ESCOLA = "utm_source=cruzvermelhariodejaneiro&utm_medium=matricula&utm_content={local}"
# Prova social: só entra com dado real e autorização. Vazio = o bloco não é impresso.
PROVA: dict[str, str] = {}
MARCADORES_PROIBIDOS = ("[INSERIR", "[CONFIRMAR", "[DECIDIR")

# Texto literal de /reembolso/ (seção "Direito de arrependimento: 7 dias").
GARANTIA_7_DIAS = ("Você pode desistir da inscrição em até 7 dias corridos, contados do pagamento, sem precisar dizer o "
                   "motivo (art. 49 do CDC). O valor pago volta por inteiro, inclusive o custo de processamento, se você "
                   "tiver escolhido cobri-lo.")
GARANTIA_DEPOIS = ("Passado esse prazo, a inscrição ainda é devolvida por inteiro se não houver turma com horário compatível "
                   "para você, ou se você desistir antes de a secretaria confirmar a sua turma (data e horário da aula).")
GARANTIA_COMO = ("Peça pelo chat “Fale com a gente”, no canto da página, no assunto “Pagamento ou PIX”. A confirmação de que "
                 "o pedido chegou vai para o seu e-mail, com número de protocolo. No PIX, o valor volta para a conta de onde "
                 "saiu o pagamento; no cartão, a cobrança é cancelada ou o estorno aparece em uma das faturas seguintes.")

if ESCOLA_MATRICULA_AUTOMATICA:
    PASSOS_DEPOIS = [
        ("Confirmação no seu e-mail", "Assim que o pagamento é confirmado, o comprovante da inscrição chega no seu e-mail."),
        ("Sua matrícula na escola", "Se o curso já tem turma aberta, sua matrícula entra nela, e a data de início aparece na "
                                    "tela de confirmação e no e-mail. Se ainda não tem, a secretaria coloca você na próxima "
                                    "turma e avisa por e-mail."),
        ("Você diz quando pode vir", "Você marca os dias e os períodos em que pode vir. A secretaria usa suas respostas para "
                                     "encaixar você na turma."),
    ]
    MINI_PASSOS = ["Comprovante no seu e-mail", "Se há turma aberta, você entra nela e vê a data",
                   "Você marca os dias e horários em que pode vir"]
    PASSO_2_TOPO = "Se o curso tem turma aberta, você entra nela e vê a data na confirmação"
    RESPOSTA_QUANDO = ("Se o curso já tem turma aberta, sua matrícula entra nela assim que o pagamento é confirmado, e a data "
                       "de início aparece na tela de confirmação e no e-mail. Se ainda não tem, a secretaria coloca você na "
                       "próxima turma e avisa por e-mail. Depois do pagamento, você também marca os dias e os períodos em que "
                       "pode vir.")
    RESPOSTA_CONTA = ("Não. Você informa nome, CPF, e-mail e telefone celular e paga a inscrição. A conta na escola é criada depois do "
                      "pagamento, com um link para você criar a senha.")
else:
    PASSOS_DEPOIS = [
        ("Confirmação no seu e-mail", "Assim que o pagamento é confirmado, o comprovante da inscrição chega no seu e-mail."),
        ("A secretaria confirma turma e horário", "Você recebe o contato por e-mail em até 3 dias úteis."),
        ("Você diz quando pode vir", "Você marca os dias e os períodos em que pode vir."),
    ]
    MINI_PASSOS = ["Comprovante no seu e-mail", "A secretaria confirma turma e horário por e-mail",
                   "Você marca os dias e horários em que pode vir"]
    PASSO_2_TOPO = "A secretaria confirma turma e horário por e-mail"
    RESPOSTA_QUANDO = "A secretaria confirma turma e horário por e-mail em até 3 dias úteis depois do pagamento."
    RESPOSTA_CONTA = "Não. Você escolhe o curso e paga a inscrição. A secretaria entra em contato por e-mail para confirmar turma e horário."
if GARANTIA_APOS_7_DIAS:
    RESPOSTA_QUANDO += " Se não houver turma com horário compatível para você, a inscrição é devolvida."

# Perguntas da página: (pergunta, resposta em texto para o FAQPage, resposta em HTML ou None = a de texto escapada).
# A 1ª tem o único link para a escola no corpo da página (medido: saida_escola, local=faq).
LINK_ESCOLA_FAQ = (f'<a href="{ESCOLA}/cursos?{UTM_ESCOLA.format(local="faq").replace("&", "&amp;")}" data-saida="faq" '
                   f'target="_blank" rel="noopener">plataforma da escola</a>')
FAQ_PAGINA = [
    ("Os R$ 99 são o valor do curso?",
     "Não. Os R$ 99 são a inscrição: garantem sua vaga no curso escolhido, na Escola de Educação e Saúde, a escola da Cruz "
     "Vermelha Brasileira Rio de Janeiro. O valor de cada curso, de R$ 150 a R$ 950, aparece no cartão dele e é pago depois, "
     "na plataforma da escola, à vista ou parcelado com juros.",
     "Não. Os R$ 99 são a inscrição: garantem sua vaga no curso escolhido, na Escola de Educação e Saúde, a escola da Cruz "
     "Vermelha Brasileira Rio de Janeiro. O valor de cada curso, de R$ 150 a R$ 950, aparece no cartão dele e é pago depois, "
     f"na {LINK_ESCOLA_FAQ}, à vista ou parcelado com juros."),
    ("Como funciona a inscrição?",
     "Você escolhe o curso, informa nome, CPF, e-mail e telefone celular e paga a inscrição de R$ 99, por PIX ou cartão, sem "
     "criar conta. " + RESPOSTA_QUANDO, None),
    ("Posso pagar com cartão? Posso parcelar?",
     "A inscrição de R$ 99 é paga à vista, por PIX ou cartão. O valor do curso é pago na plataforma da escola, à vista ou "
     "parcelado com juros, nas condições informadas lá.", None),
    ("Quando pago o restante do curso?",
     "Depois da inscrição, na plataforma da escola, à vista ou parcelado com juros. Os valores de cada curso estão nos cartões "
     "desta página.", None),
    ("Onde acontecem as aulas?",
     "Na sede da filial, o Palácio da Cruz Vermelha, na Praça da Cruz Vermelha, 10, Centro do Rio de Janeiro. Todos os "
     "cursos são presenciais.", None),
    ("Como recebo as informações da minha turma?", RESPOSTA_QUANDO + " Tudo chega no e-mail informado na inscrição.", None),
    ("Qual curso de primeiros socorros eu faço?", "__COMPARAR__", None),
    ("Recebo certificado? Ele é reconhecido?",
     "Sim. Quem conclui os requisitos do curso recebe o certificado da Cruz Vermelha Brasileira Rio de Janeiro, com o seu nome, "
     "o curso e a carga horária. A Cruz Vermelha é reconhecida nacional e internacionalmente pela tradição em formação "
     "humanitária e em emergências. Como todo curso livre, aqui ou em qualquer instituição, ele não passa pelo MEC, que regula "
     "a educação formal (ensino técnico, graduação e pós). No Bombeiro Civil, a homologação profissional é feita ao final do "
     "curso, à parte.", None),
    ("Posso fazer o curso sendo menor de idade?",
     "Cada curso pede uma escolaridade mínima, informada no cartão dele (Ensino Fundamental ou Ensino Médio). Sobre idade "
     "mínima: informação em breve. Em caso de dúvida, pergunte no chat da página antes de pagar. Para jovens de 12 a 14 anos "
     "há uma turma própria de primeiros socorros, pedida pelo responsável ou pela escola em “Solicitar uma turma”.", None),
    ("Posso cancelar minha inscrição?",
     GARANTIA_7_DIAS + " Para pedir, use o chat “Fale com a gente”, no assunto “Pagamento ou PIX”. As regras completas estão "
     "na página de cancelamento e reembolso.",
     html.escape(GARANTIA_7_DIAS) + " Para pedir, use o chat “Fale com a gente”, no assunto “Pagamento ou PIX”. As regras "
     'completas estão na <a href="/reembolso/">página de cancelamento e reembolso</a>.'),
    ("O que acontece se a turma não for formada?",
     "Se não houver turma com horário compatível para você, a inscrição é devolvida por inteiro, mesmo depois dos 7 dias. "
     "As regras completas estão na página de cancelamento e reembolso.",
     'Se não houver turma com horário compatível para você, a inscrição é devolvida por inteiro, mesmo depois dos 7 dias. '
     'As regras completas estão na <a href="/reembolso/">página de cancelamento e reembolso</a>.'),
    ("Vocês oferecem cursos para empresas e grupos?",
     f"Sim. Com {TURMA_MINIMO} a {TURMA_MAXIMO} alunos, a turma é só do grupo (empresa, escola, condomínio, instituição), com "
     "data combinada com a secretaria e o mesmo valor por pessoa dos cursos, na sede; em outro local, depende de aprovação. "
     "Qualquer curso pode ser dado em inglês, com professor ou tradutor. Peça em “Solicitar uma turma”: nada é cobrado agora e "
     "a secretaria responde em até 3 dias úteis.", None),
]
# --- o certificado (modelo da filial, 04/10/2026; imagens de amostra de scripts/gerar_certificado_modelo.py) ------
# Só afirmações com fonte: "reconhecida nacional e internacionalmente pela tradição em formação humanitária" (cursos.json,
# FAQ do Bombeiro Civil, e faq-home.json); "Sociedade Nacional do Movimento Internacional…" (/historia/). O certificado
# de curso livre não é reconhecido pelo MEC (faq-home.json): quem tem o reconhecimento é a instituição. Registro em
# livro e validade (verso do modelo) ficam de fora até a filial confirmar.
CERT_PESO = ("O certificado leva o nome da Cruz Vermelha, reconhecida nacional e internacionalmente pela tradição em "
             "formação humanitária e em emergências.")
CERT_MOVIMENTO = ("A Cruz Vermelha Brasileira é a Sociedade Nacional, no Brasil, do Movimento Internacional da Cruz Vermelha "
                  "e do Crescente Vermelho.")
CERT_ITENS = [
    "Com o seu nome, o curso e a carga horária, emitido pela Cruz Vermelha Brasileira – Filial Rio de Janeiro",
    CERT_PESO,
    CERT_MOVIMENTO,
]
# Na seção do certificado, sem repetir o título ("O certificado da Cruz Vermelha, com o seu nome").
CERT_LISTA = ["A Cruz Vermelha é reconhecida nacional e internacionalmente pela tradição em formação humanitária e em "
              "emergências.", CERT_MOVIMENTO]
CERT_NOTA = "Imagem de modelo: o seu sai com o seu nome, o curso que você fez e a data de conclusão."
CERT_DESTAQUE = "primeiros-socorros-basico"  # o certificado de exemplo do topo e da seção, fora do modo curso

FAQ_TURMAS = [
    ("Vocês fecham turma para empresas, escolas e grupos?",
     f"Sim. Com {TURMA_MINIMO} a {TURMA_MAXIMO} alunos, a turma é só do grupo, com data combinada com a secretaria e o mesmo "
     "valor por pessoa dos cursos. As aulas são na sede; em outro local, dependem de aprovação. Peça pelo botão Pedir turma "
     "para grupo: a secretaria responde em até 3 dias úteis."),
    ("Tem curso de primeiros socorros em inglês?",
     f"Sim. Todos os cursos podem ser dados em inglês, com professor ou tradutor, quando a turma tiver {TURMA_MINIMO} alunos. "
     "Quem já tem o grupo fecha a turma com prioridade; quem não tem entra na lista de interesse e é avisado quando a "
     "turma fechar."),
    ("Tem primeiros socorros para adolescentes?",
     f"Sim, para jovens de 12 a 14 anos, numa turma só para essa idade, que abre com {TURMA_MINIMO} alunos. Escolas e "
     "projetos com a turma pronta têm prioridade; famílias entram na lista de interesse. Quem preenche o pedido é o "
     "responsável ou a instituição."),
]


# --- reconstrução de 05/10/2026 ---------------------------------------------------------------------------------
# Categorias do filtro (o id vai no data-cat dos cartões). Curso fora de CATEGORIA_DE cai em "formacao" com aviso.
CATEGORIAS = [("todos", "Todos"), ("emergencia", "Emergência"), ("saude", "Saúde"), ("formacao", "Formação profissional"),
              ("estetica", "Estética")]
CATEGORIA_DE = {"primeiros-socorros-basico": "emergencia", "suporte-basico-de-vida": "emergencia",
                "primeiros-socorros-lei-lucas": "emergencia", "puncao-venosa": "saude", "bombeiro-civil": "formacao",
                "cuidador-de-idosos": "formacao", "micropigmentacao-labial": "estetica"}
# Situação da turma por curso (selo no cartão). Sem fonte de dados hoje: fica vazio e nada é impresso. Para usar,
# preencha {"slug": "vagas_abertas"} com uma das chaves de STATUS_ROTULO. Nunca inventar.
STATUS_TURMA: dict[str, str] = {}
STATUS_ROTULO = {"vagas_abertas": "Vagas abertas", "ultimas_vagas": "Últimas vagas", "formacao_turma": "Turma em formação",
                 "esgotado": "Esgotado", "breve_turma": "Nova turma em breve"}
# Materiais necessários por curso: a escola ainda não informou. "Informação em breve" até lá.
MATERIAIS: dict[str, str] = {}
# Fotos reais da sede (servidas de /assets/otim, fora do Git; legendas de site/index.html e site/historia/index.html).
FOTO_TOPO = {"src": "/assets/otim/auditorio-voluntarios-960.webp",
             "srcset": "/assets/otim/auditorio-voluntarios-480.webp 480w, /assets/otim/auditorio-voluntarios-960.webp 960w",
             "alt": "Turma em formação no auditório da sede da Cruz Vermelha Brasileira Rio de Janeiro",
             "legenda": "Turma em formação no auditório da sede"}
FOTOS_AULAS = [
    {"src": "/assets/otim/auditorio-voluntarios-480.webp", "srcset": "/assets/otim/auditorio-voluntarios-480.webp 480w, /assets/otim/auditorio-voluntarios-960.webp 960w",
     "alt": "Turma de voluntários em formação no auditório da sede", "legenda": "Turma em formação no auditório da sede"},
    {"src": "/assets/otim/voluntario-microfone-480.webp", "srcset": "/assets/otim/voluntario-microfone-480.webp 480w, /assets/otim/voluntario-microfone-960.webp 960w",
     "alt": "Instrutor falando ao microfone durante capacitação na sede", "legenda": "Instrutor durante capacitação na sede"},
    {"src": "/assets/otim/equipe-corredor-480.webp", "srcset": "/assets/otim/equipe-corredor-480.webp 480w, /assets/otim/equipe-corredor-960.webp 960w",
     "alt": "Equipe da Cruz Vermelha Brasileira Rio de Janeiro no Palácio da Cruz Vermelha", "legenda": "Equipe no Palácio da Cruz Vermelha"},
    {"src": "/assets/otim/hero-equipe-grupo-960.webp", "srcset": "/assets/otim/hero-equipe-grupo-960.webp 960w",
     "alt": "Turma reunida no auditório da sede", "legenda": "Turma reunida no auditório da sede"},
]
FOTO_HISTORIA = {"src": "/assets/otim/historia-varanda-escola-1917-480.webp",
                 "srcset": "/assets/otim/historia-varanda-escola-1917-480.webp 480w, /assets/otim/historia-varanda-escola-1917-960.webp 960w",
                 "alt": "Enfermeiras de uniforme branco e véu enfileiradas na varanda da Escola de Enfermeiras, em 1917",
                 "legenda": "Enfermeiras voluntárias e profissionais na varanda da Escola de Enfermeiras, em 1917."}
FOTO_FACHADA = {"src": "/assets/otim/historia-fachada-noturna-767.webp",
                "alt": "Fachada iluminada do Palácio da Cruz Vermelha, à noite", "legenda": "Palácio da Cruz Vermelha, Praça da Cruz Vermelha, 10"}
# Só fatos de /historia/ (literais: "20 de outubro de 1914, começa o primeiro curso de Enfermeiras Voluntárias";
# "construído entre 1919 e 1923"; "tombado como patrimônio cultural federal"; fundação em 5 de dezembro de 1908).
TEXTO_HISTORIA = ("A Escola de Educação e Saúde é a escola da Cruz Vermelha Brasileira Rio de Janeiro. A Cruz Vermelha forma pessoas "
                  "no Rio desde 1914, quando começou o primeiro curso, de Enfermeiras Voluntárias. As aulas acontecem na sede da "
                  "filial, o Palácio da Cruz Vermelha, construído entre 1919 e 1923 e tombado como patrimônio cultural federal.")
FATOS_HISTORIA = [("1908", "Fundação da Cruz Vermelha Brasileira, no Rio"), ("1914", "Primeiro curso, de Enfermeiras Voluntárias"),
                  ("1923", "Inauguração do Palácio da Cruz Vermelha")]
MAPA_ROTA = "https://www.google.com/maps/dir/?api=1&amp;destination=Pra%C3%A7a+da+Cruz+Vermelha%2C+10%2C+Centro%2C+Rio+de+Janeiro+-+RJ%2C+20230-130"
MAPA_EMBED = "https://www.google.com/maps?q=Pra%C3%A7a+da+Cruz+Vermelha%2C+10%2C+Centro%2C+Rio+de+Janeiro+-+RJ&amp;output=embed"
# Como funciona: o fluxo real do checkout (sem conta antes de pagar; a conta e a turma vêm depois, por e-mail).
PASSOS_COMO = [
    ("Escolha seu curso", "Veja os detalhes, a carga horária e o investimento de cada curso."),
    ("Garanta sua vaga", "Pague a inscrição de R$ 99 por PIX ou cartão, sem criar conta."),
    ("Complete sua matrícula", "Com o pagamento confirmado, você recebe por e-mail o comprovante e as orientações da Escola: "
                               "turma, horário e o valor do curso, pago na plataforma da escola."),
    ("Venha para a aula", "Compareça na data da sua turma, na Praça da Cruz Vermelha, 10, e participe da formação presencial."),
]

# --- CSS e script da página (reconstrução de 05/10/2026) --------------------------------------------------
# Texto com contraste de 7:1 sobre o fundo claro: #4a5568 no lugar de --muted (3,8:1) nos textos de apoio.
# Celular primeiro: as regras base são as do celular; as de computador ficam em @media (min-width: 720px) e 1024px.
CSS_PAGINA = """
  <style>
    :root { --apoio: #4a5568; --verde: #0f7b3e; --verde-claro: #e9f7ef; --sombra-leve: 0 10px 24px rgba(16, 24, 40, .08); }
    #matricula-cursos-presenciais { color: var(--text); }
    #matricula-cursos-presenciais .wrap { max-width: 1180px; }
    #matricula-cursos-presenciais section { padding: 32px 0; }
    #matricula-cursos-presenciais .eyebrow { font-size: .72rem; letter-spacing: .12em; }
    #matricula-cursos-presenciais h2 { color: var(--black); font-size: clamp(1.5rem, 5.2vw, 2.1rem); letter-spacing: -.025em; line-height: 1.12; margin: 6px 0 10px; }
    .mr-sub { color: var(--apoio); font-size: 1.02rem; margin: 0 0 22px; max-width: 62ch; }
    .mr-cta { min-height: 54px; font-size: 1rem; }
    .mr-micro { color: var(--apoio); font-size: .85rem; margin: 10px 0 0; line-height: 1.55; }
    .mr-micro i, .mr-ok i { color: var(--verde); }
    .mr-check { list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: 1fr 1fr; gap: 8px 14px; }
    .mr-check li { display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: .95rem; color: var(--text); }
    .mr-check i { color: var(--verde); flex-shrink: 0; }
    .mr-escondido { position: absolute; width: 1px; height: 1px; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); clip-path: inset(50%); white-space: nowrap; }

    /* navegação da página: aparece depois do topo, só no computador */
    .mr-nav { position: sticky; top: var(--mr-cabecalho, 84px); z-index: 40; background: rgba(255, 255, 255, .96); backdrop-filter: blur(8px); border-bottom: 1px solid var(--line); transform: translateY(-110%); transition: transform .25s; display: none; }
    .mr-nav.visivel { transform: none; }
    .mr-nav .wrap { display: flex; align-items: center; gap: 24px; min-height: 56px; }
    .mr-nav ul { list-style: none; display: flex; gap: 22px; margin: 0; padding: 0; }
    .mr-nav a { color: var(--black); font-weight: 600; font-size: .92rem; padding: 6px 0; border-bottom: 2px solid transparent; }
    .mr-nav a.ativo, .mr-nav a:hover { border-bottom-color: var(--red); }
    .mr-nav .btn { margin-left: auto; min-height: 42px; padding: 8px 18px; font-size: .9rem; }

    /* topo */
    .mr-hero { background: var(--soft); border-bottom: 1px solid var(--line); padding: 26px 0 28px !important; }
    .mr-hero-grid { display: grid; gap: 22px; align-items: center; }
    .mr-hero h1 { color: var(--black); font-size: clamp(1.65rem, 6.6vw, 2.6rem); line-height: 1.06; letter-spacing: -.03em; margin: 8px 0 10px; }
    .mr-hero-sub { font-size: 1.05rem; color: var(--text); margin: 0 0 6px; max-width: 54ch; }
    .mr-hero-ajuda { font-size: .97rem; color: var(--apoio); margin: 0 0 16px; }
    .mr-hero .mr-check { margin: 0 0 20px; }
    .mr-hero .mr-cta { width: 100%; }
    .mr-hero-local { display: flex; align-items: flex-start; gap: 8px; color: var(--apoio); font-size: .92rem; margin: 14px 0 0; }
    .mr-hero-local i { color: var(--red); flex-shrink: 0; margin-top: 3px; }
    .mr-hero-foto { margin: 0; position: relative; }
    .mr-hero-foto img { width: 100%; height: auto; aspect-ratio: 16 / 9; object-fit: cover; border-radius: var(--radius); display: block; background: #ddd; box-shadow: var(--sombra-leve); }
    .mr-hero-foto figcaption { position: absolute; left: 12px; bottom: 12px; background: rgba(255, 255, 255, .94); color: var(--black); font-size: .78rem; font-weight: 600; padding: 6px 10px; border-radius: 8px; }
    /* modo curso (?curso=): o topo encolhe e a ficha do curso aparece logo abaixo */
    html[data-curso] .mr-hero { padding: 12px 0 8px !important; border-bottom: 0; background: transparent; }
    html[data-curso] .mr-hero-grid { display: block; }
    html[data-curso] .mr-hero .eyebrow, html[data-curso] .mr-hero-sub, html[data-curso] .mr-hero-ajuda, html[data-curso] .mr-hero .mr-check,
    html[data-curso] .mr-hero .mr-cta, html[data-curso] .mr-hero-local, html[data-curso] .mr-hero-foto { display: none; }
    html[data-curso] .mr-hero h1 { font-size: .95rem; font-weight: 700; color: var(--apoio); letter-spacing: 0; line-height: 1.35; margin: 0; }

    /* ficha / detalhes de cada curso: escondida na página; aparece na janela, no modo curso ou por #det-<slug> */
    .mr-dets { padding: 0 !important; }
    .mr-det { display: none; }
    .mr-det:target, html[data-curso] .mr-det.mr-det-foco { display: block; }
    html[data-curso] .mr-dets { padding: 0 0 8px !important; }
    html[data-curso] .mr-det.mr-det-foco { background: #fff; border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--sombra-leve); padding: 18px 16px 20px; }
    .mr-det-cab { display: grid; gap: 14px; }
    .mr-det-foto { margin: 0; }
    .mr-det-foto img { width: 100%; height: auto; aspect-ratio: 16 / 9; object-fit: cover; border-radius: 12px; display: block; background: var(--soft); }
    .mr-det-tag { display: inline-block; font-size: .7rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--red); margin: 0 0 6px; }
    .mr-det h3 { color: var(--black); font-size: 1.45rem; line-height: 1.15; letter-spacing: -.02em; margin: 0 0 8px; }
    .mr-det-frase { font-size: 1rem; color: var(--text); margin: 0 0 12px; }
    .mr-meta { list-style: none; padding: 0; margin: 0 0 14px; display: flex; flex-wrap: wrap; gap: 6px 16px; color: var(--text); font-size: .9rem; }
    .mr-meta li { display: inline-flex; align-items: center; gap: 6px; }
    .mr-meta i { color: var(--red); }
    .mr-preco { background: var(--soft); border: 1px solid var(--line); border-radius: 12px; padding: 12px 14px; margin: 0 0 14px; display: grid; grid-template-columns: 1fr auto 1fr; gap: 10px; align-items: center; }
    .mr-preco > div { min-width: 0; }
    .mr-preco small { display: block; color: var(--apoio); font-size: .76rem; letter-spacing: .04em; text-transform: uppercase; font-weight: 700; }
    .mr-preco b { display: block; color: var(--black); font-size: 1.35rem; line-height: 1.15; }
    .mr-preco span { display: block; color: var(--apoio); font-size: .82rem; }
    .mr-preco .mr-mais { color: var(--apoio); font-weight: 800; font-size: 1.2rem; }
    .mr-preco-pag { grid-column: 1 / -1; margin: 0; font-size: .82rem; color: var(--apoio); display: flex; gap: 6px; align-items: center; }
    .mr-preco-pag i { color: var(--red); }
    .mr-det-acoes { display: grid; gap: 8px; margin: 0 0 6px; }
    .mr-det-acoes .mr-cta { width: 100%; }
    .mr-det-corpo h4 { color: var(--black); font-size: 1rem; margin: 18px 0 6px; }
    .mr-det-corpo p, .mr-det-corpo li { color: var(--text); font-size: .95rem; line-height: 1.6; }
    .mr-det-corpo p { margin: 0 0 8px; }
    .mr-det-corpo ul { margin: 0; padding: 0; list-style: none; display: grid; gap: 6px; }
    .mr-det-corpo ul li { display: flex; gap: 8px; align-items: baseline; }
    .mr-det-corpo ul li i { color: var(--verde); flex: none; }
    .mr-det-grade { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 14px 0 0; }
    .mr-det-grade div { background: var(--soft); border-radius: 10px; padding: 10px 12px; }
    .mr-det-grade small { display: block; color: var(--apoio); font-size: .74rem; letter-spacing: .04em; text-transform: uppercase; font-weight: 700; margin-bottom: 2px; }
    .mr-det-grade b { color: var(--black); font-size: .95rem; font-weight: 600; }
    .mr-det-cert { display: flex; gap: 12px; align-items: center; background: var(--soft); border-radius: 12px; padding: 10px 12px; margin: 14px 0 0; }
    .mr-det-cert img { width: 84px; height: auto; border-radius: 4px; flex-shrink: 0; box-shadow: 0 2px 8px rgba(16, 24, 40, .14); }
    .mr-det-cert p { margin: 0; font-size: .88rem; }
    .mr-det-cert a { color: var(--red); font-weight: 700; }
    .mr-det-faq details { border-top: 1px solid var(--line); }
    .mr-det-fim { border-top: 1px solid var(--line); margin-top: 18px; padding-top: 16px; }
    .mr-det-fim .mr-cta { width: 100%; }
    .mr-turma-linha { color: var(--apoio); font-size: .9rem; margin: 12px 0 0; }
    .mr-turma-linha a, .mr-chat-atalho a { color: var(--red); font-weight: 700; text-decoration: underline; }
    .mr-chat-atalho { font-size: .9rem; margin: 10px 0 0; color: var(--apoio); }
    /* janela dos detalhes (dialog) */
    .mr-janela { width: 100vw; max-width: 100vw; height: 100vh; height: 100dvh; max-height: none; margin: 0; border: 0; border-radius: 0; padding: 10px 16px 32px; color: var(--text); overflow: auto; overscroll-behavior: contain; }
    .mr-janela::backdrop { background: rgba(15, 19, 24, .55); }
    .mr-janela .mr-det { display: block; }
    .mr-janela-fechar { position: sticky; top: 0; float: right; margin: -2px -6px 0 8px; width: 44px; height: 44px; border: 0; border-radius: 50%; background: #fff; color: var(--black); font-size: 1.9rem; line-height: 1; cursor: pointer; z-index: 1; }
    .mr-janela-fechar:hover, .mr-janela-fechar:focus-visible { background: var(--soft); outline: 2px solid var(--red); outline-offset: 2px; }
    html.mr-modal-aberto { overflow: hidden; }

    /* filtro por categoria */
    .mr-filtro { padding: 22px 0 0 !important; overflow: hidden; }
    .mr-filtro h2 { font-size: 1.15rem; margin: 0 0 10px; }
    .mr-chips { display: flex; gap: 8px; overflow-x: auto; padding: 2px 0 8px; margin: 0 -16px; padding-left: 16px; padding-right: 16px; scrollbar-width: none; -webkit-overflow-scrolling: touch; }
    .mr-chips::-webkit-scrollbar { display: none; }
    .mr-chip { flex-shrink: 0; min-height: 40px; padding: 8px 16px; border-radius: 999px; border: 1px solid var(--line); background: #fff; color: var(--black); font: inherit; font-weight: 600; font-size: .92rem; cursor: pointer; transition: background .15s, border-color .15s; }
    .mr-chip[aria-pressed="true"] { background: var(--red); border-color: var(--red); color: #fff; }
    .mr-chip:focus-visible { outline: 2px solid var(--red); outline-offset: 2px; }

    /* cursos */
    .mr-cursos { padding: 16px 0 36px !important; }
    .mr-grade { display: grid; gap: 14px; }
    .mr-card { display: flex; flex-direction: column; background: #fff; border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; scroll-margin-top: 80px; transition: box-shadow .2s, transform .2s; }
    .mr-card.ativo { border-color: var(--red); box-shadow: 0 0 0 1px var(--red); }
    .mr-card.oculto { display: none; }
    .mr-card-capa { position: relative; margin: 0; }
    .mr-card-capa img { width: 100%; height: auto; aspect-ratio: 2 / 1; object-fit: cover; display: block; background: var(--soft); }
    .mr-card-tag { position: absolute; left: 12px; bottom: 10px; background: rgba(255, 255, 255, .94); color: var(--black); font-size: .68rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; padding: 4px 9px; border-radius: 999px; }
    .mr-badge { position: absolute; right: 12px; top: 10px; background: var(--verde-claro); color: #0f5132; font-size: .7rem; font-weight: 800; padding: 4px 9px; border-radius: 999px; }
    .mr-badge.esgotado { background: #fde8e8; color: #9b1c1c; }
    .mr-badge.breve, .mr-badge.formacao { background: #fff4e0; color: #7c4a03; }
    .mr-card-corpo { padding: 14px 16px 16px; display: flex; flex-direction: column; flex: 1; }
    .mr-card h3 { color: var(--black); font-size: 1.12rem; line-height: 1.22; margin: 0 0 6px; }
    .mr-card-frase { color: var(--text); font-size: .93rem; margin: 0 0 10px; }
    .mr-card .mr-meta { margin-bottom: 12px; font-size: .86rem; gap: 4px 12px; }
    .mr-card .mr-preco { margin-top: auto; }
    .mr-card-acoes { display: grid; grid-template-columns: 1fr; gap: 8px; }
    .mr-card-acoes .mr-cta { min-height: 50px; font-size: .97rem; box-shadow: none; }
    .mr-card-acoes .btn-outline { min-height: 46px; font-size: .95rem; background: #fff; }
    .mr-card-turma { display: flex; flex-direction: column; gap: 8px; justify-content: center; border: 1px dashed #cbd5e1; border-radius: var(--radius); padding: 20px 16px; background: var(--soft); }
    .mr-card-turma > i { color: var(--red); font-size: 1.6rem; }
    .mr-card-turma h3 { margin: 0; font-size: 1.1rem; color: var(--black); line-height: 1.25; }
    .mr-card-turma p { margin: 0; color: var(--text); font-size: .92rem; line-height: 1.5; }
    .mr-card-turma .btn { margin-top: 8px; min-height: 46px; font-size: .95rem; background: #fff; }
    .mr-vazio { color: var(--apoio); text-align: center; padding: 24px 0; }

    /* fotos */
    .mr-fotos { background: var(--soft); border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
    .mr-fotos-grade { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .mr-fotos figure { margin: 0; }
    .mr-fotos img { width: 100%; height: auto; aspect-ratio: 4 / 3; object-fit: cover; border-radius: 12px; display: block; background: #ddd; }
    .mr-fotos figcaption { font-size: .8rem; color: var(--apoio); margin: 6px 2px 0; line-height: 1.35; }

    /* como funciona (faixa vermelha) */
    .mr-como { background: var(--red); color: #fff; }
    .mr-como .eyebrow, .mr-como h2, .mr-como .mr-sub { color: #fff; }
    .mr-como .eyebrow { opacity: .9; }
    .mr-como .mr-sub { opacity: .92; }
    .mr-passos { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
    .mr-passos li { display: flex; gap: 14px; background: rgba(255, 255, 255, .1); border: 1px solid rgba(255, 255, 255, .22); border-radius: 14px; padding: 14px; }
    .mr-passos b { flex-shrink: 0; width: 36px; height: 36px; border-radius: 50%; background: #fff; color: var(--red); display: inline-flex; align-items: center; justify-content: center; font-weight: 900; font-size: .9rem; }
    .mr-passos h3 { color: #fff; font-size: 1rem; margin: 6px 0 4px; }
    .mr-passos p { color: rgba(255, 255, 255, .92); margin: 0; font-size: .92rem; line-height: 1.5; }
    .mr-como-nota { margin: 16px 0 0; font-size: .88rem; color: rgba(255, 255, 255, .9); }
    .mr-como-nota a { color: #fff; text-decoration: underline; font-weight: 700; }

    /* certificado */
    .mr-cert-grade { display: grid; gap: 18px; align-items: center; }
    .mr-cert-figura { margin: 0; max-width: 420px; }
    .mr-cert-img { width: 100%; height: auto; display: block; border-radius: 8px; box-shadow: 0 16px 40px rgba(16, 24, 40, .16); transform: rotate(-1.5deg); }
    .mr-cert-itens { list-style: none; padding: 0; margin: 0 0 14px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
    .mr-cert-itens li { background: var(--soft); border-radius: 10px; padding: 10px; text-align: center; }
    .mr-cert-itens i { color: var(--verde); display: block; margin: 0 auto 4px; font-size: 1.1rem; }
    .mr-cert-itens span { display: block; font-size: .82rem; font-weight: 700; color: var(--black); line-height: 1.25; }
    .mr-cert-texto p { color: var(--text); font-size: .95rem; margin: 0 0 8px; }
    .mr-cert-nota { color: var(--apoio); font-size: .82rem; }

    /* história */
    .mr-historia { background: var(--soft); border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
    .mr-historia-grade { display: grid; gap: 18px; align-items: center; }
    .mr-historia figure { margin: 0; }
    .mr-historia img { width: 100%; height: auto; aspect-ratio: 4 / 3; object-fit: cover; border-radius: var(--radius); display: block; background: #ddd; }
    .mr-historia figcaption { font-size: .8rem; color: var(--apoio); margin: 6px 2px 0; }
    .mr-historia p { color: var(--text); font-size: .97rem; }
    .mr-historia-link, .mr-local-link { color: var(--red); font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
    .mr-fatos { list-style: none; padding: 0; margin: 14px 0; display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
    .mr-fatos li { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 10px; }
    .mr-fatos b { display: block; color: var(--red); font-size: 1.25rem; line-height: 1.1; letter-spacing: -.02em; }
    .mr-fatos span { display: block; color: var(--apoio); font-size: .78rem; line-height: 1.3; margin-top: 2px; }

    /* local */
    .mr-local-grade { display: grid; gap: 16px; }
    .mr-local-cartao { background: #fff; border: 1px solid var(--line); border-radius: var(--radius); padding: 18px 16px; }
    .mr-endereco { display: flex; gap: 10px; align-items: flex-start; margin: 0 0 14px; font-size: 1rem; color: var(--text); }
    .mr-endereco i { color: var(--red); margin-top: 4px; }
    .mr-endereco b { display: block; color: var(--black); }
    .mr-local-acoes { display: grid; gap: 8px; }
    .mr-local-acoes .btn { min-height: 48px; }
    .mr-mapa { position: relative; border-radius: var(--radius); overflow: hidden; background: #ddd; aspect-ratio: 4 / 3; }
    .mr-mapa img, .mr-mapa iframe { width: 100%; height: 100%; display: block; border: 0; object-fit: cover; }
    .mr-mapa-botao { position: absolute; left: 50%; bottom: 14px; transform: translateX(-50%); white-space: nowrap; min-height: 44px; box-shadow: var(--sombra-leve); }
    .mr-mapa figcaption { position: absolute; left: 12px; top: 12px; background: rgba(255, 255, 255, .94); font-size: .78rem; font-weight: 600; padding: 5px 9px; border-radius: 8px; color: var(--black); }

    /* dúvidas */
    .mr-faq .wrap { max-width: 820px; }
    .mr-faq details, .mr-det-faq details { border-top: 1px solid var(--line); padding: 4px 0; }
    .mr-faq details:last-of-type { border-bottom: 1px solid var(--line); }
    .mr-faq summary, .mr-det-faq summary { cursor: pointer; font-weight: 700; color: var(--black); min-height: 44px; list-style: none; display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 8px 0; line-height: 1.35; font-size: .97rem; }
    .mr-faq summary::-webkit-details-marker, .mr-det-faq summary::-webkit-details-marker { display: none; }
    .mr-faq summary::after, .mr-det-faq summary::after { content: "+"; flex-shrink: 0; width: 26px; height: 26px; border-radius: 50%; background: var(--soft); color: var(--red); display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.1rem; }
    .mr-faq details[open] > summary::after, .mr-det-faq details[open] > summary::after { content: "−"; }
    .mr-faq details p, .mr-det-faq details p { margin: 2px 40px 12px 0; color: var(--text); line-height: 1.6; }
    .mr-faq details p a { color: var(--red); font-weight: 600; text-decoration: underline; }
    .mr-faq details p a[data-saida] { color: inherit; font-weight: 400; }
    .mr-faq-chat { margin-top: 18px; }

    /* empresas e grupos */
    .mr-grupos { background: var(--soft); border-top: 1px solid var(--line); }
    .mr-grupos [hidden] { display: none !important; }
    .mr-grupos-tipos { list-style: none; padding: 0; margin: 0 0 18px; display: flex; flex-wrap: wrap; gap: 8px; }
    .mr-grupos-tipos li { background: #fff; border: 1px solid var(--line); border-radius: 999px; padding: 7px 14px; font-size: .9rem; font-weight: 600; color: var(--black); }
    .mr-grupos .btn { min-height: 52px; }
    .mr-grupos-nota { color: var(--apoio); font-size: .88rem; margin: 10px 0 0; }

    /* chamada final */
    .mr-final { text-align: center; }
    .mr-final h2 { margin-bottom: 8px; }
    .mr-final .mr-sub { margin: 0 auto 22px; }
    .mr-final-acoes { display: grid; gap: 10px; max-width: 420px; margin: 0 auto; }
    .mr-final-acoes .btn { min-height: 54px; }
    .mr-final .mr-micro { margin-top: 14px; }

    /* barra fixa (celular) */
    .mr-barra { position: fixed; left: 0; right: 0; bottom: 0; z-index: 60; background: #fff; border-top: 1px solid var(--line); box-shadow: 0 -8px 24px rgba(16, 24, 40, .12); padding: 10px 16px calc(10px + env(safe-area-inset-bottom)); display: flex; align-items: center; gap: 12px; animation: mr-sobe .25s ease-out; }
    .mr-barra[hidden] { display: none; }
    @keyframes mr-sobe { from { transform: translateY(100%); } to { transform: none; } }
    @media (prefers-reduced-motion: reduce) { .mr-barra { animation: none; } .mr-card, .mr-nav { transition: none; } }
    .mr-barra-texto { min-width: 0; flex: 1; display: flex; flex-direction: column; }
    .mr-barra-texto b { font-size: .95rem; color: var(--black); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mr-barra-texto span { font-size: .78rem; color: var(--apoio); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mr-barra .btn { min-height: 48px; padding: 10px 16px; font-size: .95rem; white-space: nowrap; flex-shrink: 0; }
    html.mr-barra-on body .cv-chat { bottom: calc(84px + env(safe-area-inset-bottom)); }
    html.mr-chat-recolher body .cv-chat:not(.aberto) .cv-chat-abrir { opacity: 0; pointer-events: none; }
    html body .cv-chat .cv-chat-abrir { transition: opacity .2s; }

    /* formulário da turma (dialog), como antes */
    .mr-demanda-form { background: #fff; border: 1px solid var(--line); border-radius: var(--radius); padding: 18px; }
    .mr-modal { width: 100vw; max-width: 100vw; height: 100vh; height: 100dvh; max-height: none; margin: 0; border: 0; border-radius: 0; padding: 12px 16px 32px; overflow: auto; overscroll-behavior: contain; box-shadow: 0 24px 64px rgba(15, 19, 24, .35); color: var(--text); }
    .mr-modal::backdrop { background: rgba(15, 19, 24, .55); }
    .mr-modal-fechar { position: sticky; top: 0; float: right; margin: -4px -6px 0 8px; width: 44px; height: 44px; border: 0; border-radius: 50%; background: #fff; color: var(--black); font-size: 1.9rem; line-height: 1; cursor: pointer; z-index: 1; }
    .mr-modal-fechar:hover, .mr-modal-fechar:focus-visible { background: var(--soft); outline: 2px solid var(--red); outline-offset: 2px; }
    .mr-demanda-form h3 { color: var(--black); font-size: 1.3rem; margin: 0 0 4px; }
    .mr-tf-nota { color: var(--apoio); margin: 0 0 18px; }
    .mr-tf-grade { display: grid; grid-template-columns: 1fr; gap: 16px 20px; }
    .mr-tf-largo { grid-column: 1 / -1; }
    .mr-tf-campo { margin: 0; padding: 0; border: 0; min-width: 0; }
    .mr-tf-campo label, .mr-tf-campo legend { display: block; font-weight: 700; color: var(--black); margin: 0 0 6px; padding: 0; font-size: .95rem; }
    .mr-tf-campo label small { color: var(--apoio); font-weight: 500; }
    .mr-tf-campo input:not([type=radio]):not([type=checkbox]), .mr-tf-campo select, .mr-tf-campo textarea { width: 100%; min-height: 48px; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 12px; font: inherit; font-size: 1rem; color: var(--text); background: #fff; }
    .mr-tf-campo textarea { min-height: 96px; resize: vertical; }
    .mr-tf-campo input:focus, .mr-tf-campo select:focus, .mr-tf-campo textarea:focus { outline: 0; border-color: var(--red); box-shadow: 0 0 0 4px rgba(204, 0, 0, .14); }
    .mr-tf-campo.erro input, .mr-tf-campo.erro select, .mr-tf-campo.erro textarea { border-color: var(--red); background: #fff8f8; }
    .mr-tf-opcoes { display: flex; flex-wrap: wrap; gap: 8px 18px; }
    .mr-tf-opcoes label, .mr-tf-check { display: flex; align-items: flex-start; gap: 8px; font-weight: 600; color: var(--text); margin: 0; cursor: pointer; }
    .mr-tf-opcoes label > span { min-width: 0; }
    .mr-tf-opcoes input, .mr-tf-check input { width: 18px; height: 18px; margin: 3px 0 0; flex-shrink: 0; accent-color: var(--red); }
    .mr-tf-dica { color: var(--apoio); font-size: .85rem; margin: 6px 0 0; }
    .mr-tf-dica a { color: var(--red); text-decoration: underline; }
    .mr-tf-erro { color: #b91c1c; font-size: .88rem; font-weight: 600; margin: 6px 0 0; }
    .mr-tf-erro.geral { background: #fff0f2; border: 1px solid #f5c2c7; border-radius: 12px; padding: 10px 14px; margin: 16px 0 0; }
    .mr-tf-situacao { margin: 18px 0; padding: 14px 18px; border-left: 4px solid var(--line); background: var(--soft); border-radius: 0 12px 12px 0; color: var(--text); }
    .mr-tf-situacao.fechada { border-left-color: var(--verde); }
    .mr-tf-situacao.lista { border-left-color: var(--red); }
    .mr-tf-situacao.aviso { border-left-color: #b7791f; background: #fffaf0; }
    #tf-dados { margin-top: 4px; }
    #tf-enviar { margin-top: 20px; min-height: 56px; font-size: 1rem; }
    #tf-enviar:disabled { background: #e2e8f0; color: var(--apoio); box-shadow: none; cursor: not-allowed; transform: none; }
    .mr-tf-armadilha { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
    .mr-tf-ok { text-align: center; padding: 18px 0; }
    .mr-tf-ok:focus { outline: 0; }
    .mr-tf-ok i { color: var(--verde); font-size: 2.2rem; }
    .mr-tf-ok h3 { color: var(--black); margin: 10px 0 6px; }

    /* celular: cartão compacto (miniatura ao lado do título, preço numa linha, dois botões lado a lado) */
    @media (max-width: 719px) {
      .mr-card { display: grid; grid-template-columns: 84px minmax(0, 1fr); gap: 0 12px; padding: 14px 14px 14px; align-items: start; }
      .mr-card-capa { grid-column: 1; grid-row: 1 / span 3; }
      .mr-card-capa img { aspect-ratio: 1; border-radius: 10px; }
      .mr-card-tag { display: none; }
      .mr-badge { position: static; display: inline-block; margin-top: 6px; font-size: .64rem; }
      .mr-card-corpo { display: contents; }
      .mr-card h3 { grid-column: 2; font-size: 1.05rem; margin: 0 0 4px; }
      .mr-card-frase { grid-column: 2; margin: 0 0 6px; font-size: .9rem; }
      .mr-card .mr-meta { grid-column: 2; margin: 0 0 12px; font-size: .84rem; gap: 2px 12px; }
      .mr-meta li.mr-meta-local { display: none; }
      .mr-card .mr-preco { grid-column: 1 / -1; margin: 0 0 10px; padding: 8px 12px; gap: 6px; }
      .mr-card .mr-preco small { font-size: .68rem; }
      .mr-card .mr-preco b { font-size: 1.1rem; display: inline; margin-right: 4px; }
      .mr-card .mr-preco span { display: inline; font-size: .78rem; }
      .mr-card .mr-preco .mr-preco-pag { display: none; }
      .mr-cert-figura { max-width: 300px; margin: 0 auto; }
      .mr-historia img, .mr-mapa { aspect-ratio: 16 / 9; }
      .mr-fatos b { font-size: 1.1rem; }
      .mr-card-acoes { grid-column: 1 / -1; grid-template-columns: 1fr 1fr; }
      .mr-card-acoes .mr-cta { min-height: 48px; font-size: .95rem; padding: 10px 12px; }
      .mr-card-acoes .btn-outline { min-height: 48px; font-size: .92rem; padding: 10px 12px; }
      .mr-det-foto { display: none; }
      .mr-hero-foto img { aspect-ratio: 2 / 1; }
      html body .cv-chat .cv-chat-abrir { width: 56px; padding: 0; justify-content: center; border-color: var(--line); }
      html body .cv-chat .cv-chat-abrir-rotulo { position: absolute; width: 1px; height: 1px; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); clip-path: inset(50%); white-space: nowrap; }
      .mr-passos b { width: 30px; height: 30px; }
    }
    @media (max-width: 559px) {
      html body .cvrj-ck { padding: 14px; }
      html body .cvrj-ck .cvrj-ck-botoes { grid-template-columns: repeat(3, 1fr); }
      html body .cvrj-ck .cvrj-ck-botoes button { font-size: .85rem; padding: 8px 4px; }
    }

    /* tablet e computador */
    @media (min-width: 720px) {
      #matricula-cursos-presenciais section { padding: 56px 0; }
      .mr-nav { display: block; }
      .mr-barra { display: none !important; }
      .mr-hero { padding: 36px 0 40px !important; }
      .mr-hero-grid { grid-template-columns: minmax(0, 1.1fr) minmax(0, .9fr); gap: 40px; }
      .mr-hero h1 { font-size: clamp(2.1rem, 3.6vw, 2.9rem); }
      .mr-hero .mr-cta { width: auto; min-width: 300px; }
      .mr-check { grid-template-columns: repeat(4, auto); justify-content: start; gap: 8px 22px; }
      .mr-hero .mr-check { grid-template-columns: 1fr 1fr; max-width: 460px; }
      .mr-filtro { padding-top: 36px !important; }
      .mr-chips { margin: 0; padding: 2px 0 8px; overflow: visible; flex-wrap: wrap; }
      .mr-grade { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
      .mr-card-acoes { grid-template-columns: 1fr 1fr; }
      .mr-fotos-grade { grid-template-columns: repeat(4, 1fr); gap: 14px; }
      .mr-passos { grid-template-columns: repeat(2, 1fr); gap: 14px; }
      .mr-passos li { flex-direction: column; gap: 10px; padding: 20px; }
      .mr-passos h3 { margin-top: 0; font-size: 1.05rem; }
      .mr-cert-grade { grid-template-columns: minmax(0, 1fr) minmax(0, 1.1fr); gap: 36px; }
      .mr-historia-grade { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 36px; }
      .mr-local-grade { grid-template-columns: minmax(0, .9fr) minmax(0, 1.1fr); gap: 24px; align-items: stretch; }
      .mr-mapa { aspect-ratio: auto; min-height: 320px; }
      .mr-local-acoes { grid-template-columns: 1fr 1fr; }
      .mr-final-acoes { grid-template-columns: 1fr 1fr; max-width: 560px; }
      .mr-det-cab { grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr); gap: 24px; align-items: start; }
      .mr-det-acoes { grid-template-columns: 1fr auto; align-items: center; }
      .mr-det-acoes .mr-cta { width: auto; min-width: 260px; }
      .mr-det-fim .mr-cta { width: auto; min-width: 300px; }
      .mr-janela { width: min(860px, calc(100vw - 32px)); height: auto; max-height: calc(100vh - 48px); max-height: calc(100dvh - 48px); margin: auto; border-radius: var(--radius); padding: 22px 28px 30px; box-shadow: 0 24px 64px rgba(15, 19, 24, .35); }
      .mr-janela-fechar { margin: -8px -12px 0 8px; }
      .mr-modal { width: min(720px, calc(100vw - 32px)); height: auto; max-height: calc(100vh - 48px); max-height: calc(100dvh - 48px); margin: auto; border-radius: var(--radius); padding: 28px; }
      .mr-modal-fechar { margin: -12px -12px 0 8px; }
      .mr-tf-grade { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      html[data-curso] .mr-det.mr-det-foco { padding: 26px 28px 28px; }
      html[data-curso] .mr-hero { padding: 16px 0 6px !important; }
    }
    @media (min-width: 1024px) {
      .mr-grade { grid-template-columns: repeat(4, minmax(0, 1fr)); }
      .mr-card-acoes { grid-template-columns: 1fr; }
      .mr-passos { grid-template-columns: repeat(4, 1fr); }
      .mr-hero .mr-check { grid-template-columns: repeat(4, auto); max-width: none; }
    }
    @media (min-width: 1024px) and (max-width: 1279px) { .mr-grade { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
  </style>"""

JS_PAGINA = """
  <script>
    (function () {
      var CHECKOUT_URL = __CHECKOUT__;
      var INSCRICAO = __INSCRICAO__;
      var INSC_CURTO = __INSC_CURTO__;
      var EXIBICAO = __EXIBICAO__;
      var LISTA = 'Matrícula cursos presenciais';
      var raiz = document.documentElement;
      var celular = window.matchMedia ? window.matchMedia('(max-width: 719px)') : { matches: false };
      var cards = {};
      Array.prototype.forEach.call(document.querySelectorAll('.mr-card[data-curso]'), function (el) { cards[el.getAttribute('data-curso')] = el; });
      if (!Object.keys(cards).length) return;
      var modo = raiz.getAttribute('data-curso') ? 'curso' : 'geral';
      function nome(s) { return cards[s] ? cards[s].getAttribute('data-nome') : s; }
      function curto(s) { return cards[s] ? cards[s].getAttribute('data-curto') : s; }
      function ga(evento, dados) { try { if (window.gtag) window.gtag('event', evento, dados || {}); } catch (e) {} }
      function item(s) { return { item_id: s, item_name: nome(s), item_category: 'Cursos presenciais', price: INSCRICAO, quantity: 1 }; }

      // UTMs, fbclid e gclid da URL atual vão junto para o checkout (os links já funcionam sem JavaScript).
      var extras = new URLSearchParams();
      new URLSearchParams(location.search).forEach(function (v, k) { if (/^(utm_|fbclid$|gclid$)/.test(k)) extras.set(k, v); });
      function comExtras(href) {
        try { var u = new URL(href, location.origin); extras.forEach(function (v, k) { u.searchParams.set(k, v); }); return u.pathname + u.search; } catch (e) { return href; }
      }
      function linkCheckout(s, local) { return comExtras(CHECKOUT_URL + '?curso=' + encodeURIComponent(s) + '&via=' + local); }
      Array.prototype.forEach.call(document.querySelectorAll('a.mr-cta[href*="/checkout/"]'), function (a) { a.setAttribute('href', comExtras(a.getAttribute('href'))); });
      // Links da plataforma da escola (PR #56): quem veio de um anúncio leva a campanha até a cobrança na Únicopag. As
      // utm_* do anúncio substituem as nossas; o lugar do link continua medido pelo saida_escola (data-saida).
      var utmAnuncio = [];
      extras.forEach(function (v, k) { if (/^utm_/.test(k)) utmAnuncio.push([k, v]); });
      if (utmAnuncio.length) Array.prototype.forEach.call(document.querySelectorAll('a[href^="https://escola.cursoscruzvermelha.org"]'), function (a) {
        try {
          var u = new URL(a.href);
          Array.from(u.searchParams.keys()).forEach(function (k) { if (/^utm_/.test(k)) u.searchParams.delete(k); });
          utmAnuncio.forEach(function (par) { u.searchParams.set(par[0], par[1]); });
          a.href = u.toString();
        } catch (e) { /* link fora do padrão: fica como está */ }
      });

      // ViewContent (Meta) e view_item + view_course_details (GA4): ao chegar com ?curso= ou #det-/#curso-, e ao abrir os
      // detalhes de um curso; uma vez por curso. Nunca por rolagem: o sinal da Meta tem de ser interesse real.
      var vistos = {};
      function verCurso(s, origem) {
        if (!cards[s] || vistos[s]) return;
        vistos[s] = true;
        var id = '';
        try { if (window.cvrjMedicao && window.cvrjMedicao.novoId) id = window.cvrjMedicao.novoId('vc'); } catch (e) {}
        var dados = { content_name: nome(s), content_ids: [s], content_type: 'product', content_category: 'matricula-cursos-presenciais', value: INSCRICAO, currency: 'BRL' };
        try { if (window.fbq) window.fbq('track', 'ViewContent', dados, id ? { eventID: id } : undefined); } catch (e) {}
        try { if (id) window.cvrjMedicao.servidor('ViewContent', id, s); } catch (e) {}
        ga('view_item', { currency: 'BRL', value: INSCRICAO, item_list_name: LISTA, origem: origem, items: [item(s)] });
        ga('view_course_details', { curso: s, origem: origem, modo: modo });
      }

      // --- curso em foco: alimenta a barra fixa, a chamada final, o certificado de amostra e o chat ----------------
      // (site/chat/chat.js lê o curso em foco por .mr-detalhe.ativo[data-curso]).
      var foco = null;
      var final = document.getElementById('mr-final-acoes');
      var barra = document.getElementById('mr-barra');
      var barraCta = document.getElementById('mr-barra-cta');
      var barraNome = document.getElementById('mr-barra-nome');
      var barraSub = document.getElementById('mr-barra-sub');
      function focar(s) {
        if (!cards[s] || foco === s) return;
        foco = s;
        Object.keys(cards).forEach(function (k) { cards[k].classList.toggle('ativo', k === s); });
        Array.prototype.forEach.call(document.querySelectorAll('.mr-cert-img[data-cert-curso]'), function (img) {
          var atual = img.getAttribute('data-cert-curso');
          if (atual === s || !cards[s].hasAttribute('data-cert')) return;
          img.setAttribute('src', img.getAttribute('src').replace('certificado-' + atual + '-', 'certificado-' + s + '-'));
          if (img.getAttribute('srcset')) img.setAttribute('srcset', img.getAttribute('srcset').split('certificado-' + atual + '-').join('certificado-' + s + '-'));
          img.setAttribute('alt', img.getAttribute('alt').replace(nome(atual), nome(s)));
          img.setAttribute('data-cert-curso', s);
        });
        if (barra) {
          barraNome.textContent = curto(s);
          barraSub.textContent = 'Inscrição ' + INSC_CURTO + ' · 7 dias para desistir';
          barraCta.textContent = 'Garantir minha vaga';
          barraCta.setAttribute('href', linkCheckout(s, 'barra'));
          barraCta.setAttribute('data-curso', s);
          barraCta.setAttribute('data-local', 'barra');
          barraCta.classList.add('mr-cta');
        }
        if (final) {
          var a = final.querySelector('[data-final-primario]');
          if (a) {
            a.className = 'btn btn-red mr-cta';
            a.setAttribute('data-local', 'final');
            a.setAttribute('data-curso', s);
            a.href = linkCheckout(s, 'final');
            a.textContent = 'Garantir minha vaga em ' + curto(s) + ' · ' + INSC_CURTO;
            observarCta(a);
          }
        }
        avaliarBarra();
      }

      // --- detalhes: a ficha de cada curso (seção .mr-det, escondida) vai para a janela; volta ao fechar ----------
      var janela = document.getElementById('mr-janela');
      var janelaCorpo = document.getElementById('mr-janela-corpo');
      var detAberto = null, detOrigem = null;
      function abrirDetalhes(s, origem) {
        var det = document.getElementById('det-' + s);
        if (!det || !janela) return;
        if (raiz.getAttribute('data-curso') === s) {
          // No modo curso a ficha já está aberta no topo: só rola até ela.
          det.scrollIntoView({ behavior: 'smooth', block: 'start' });
          focar(s); verCurso(s, origem);
          return;
        }
        fecharDetalhes();
        detOrigem = document.createElement('span');
        det.parentNode.insertBefore(detOrigem, det);
        janelaCorpo.appendChild(det);
        detAberto = det;
        janela.setAttribute('aria-labelledby', 'det-titulo-' + s);
        if (typeof janela.showModal === 'function') janela.showModal(); else janela.setAttribute('open', '');
        janela.scrollTop = 0;
        raiz.classList.add('mr-modal-aberto');
        focar(s);
        verCurso(s, origem);
      }
      function fecharDetalhes() {
        if (detAberto && detOrigem) { detOrigem.parentNode.insertBefore(detAberto, detOrigem); detOrigem.remove(); }
        detAberto = null; detOrigem = null;
        if (janela && janela.open) { if (typeof janela.close === 'function') janela.close(); else janela.removeAttribute('open'); }
        raiz.classList.remove('mr-modal-aberto');
      }
      if (janela) {
        janela.addEventListener('close', function () { if (detAberto) { var d = detAberto; fecharDetalhes(); void d; } raiz.classList.remove('mr-modal-aberto'); });
        janela.addEventListener('click', function (e) {
          if (e.target !== janela) return;
          var r = janela.getBoundingClientRect();
          if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) fecharDetalhes();
        });
        Array.prototype.forEach.call(janela.querySelectorAll('[data-janela-fechar]'), function (b) { b.addEventListener('click', fecharDetalhes); });
      }
      document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('[data-detalhes]') : null;
        if (!a) return;
        e.preventDefault();
        var s = a.getAttribute('data-detalhes');
        ga('select_course', { curso: s, local: a.getAttribute('data-local') || 'cartao', modo: modo });
        abrirDetalhes(s, a.getAttribute('data-local') || 'cartao');
      });

      // --- filtro por categoria -------------------------------------------------------------------------------
      var chips = document.querySelectorAll('.mr-chip[data-cat]');
      var vazio = document.getElementById('mr-vazio');
      function filtrar(cat) {
        var n = 0;
        Object.keys(cards).forEach(function (s) {
          var mostra = cat === 'todos' || cards[s].getAttribute('data-cat') === cat;
          cards[s].classList.toggle('oculto', !mostra);
          if (mostra) n++;
        });
        Array.prototype.forEach.call(chips, function (c) { c.setAttribute('aria-pressed', c.getAttribute('data-cat') === cat ? 'true' : 'false'); });
        if (vazio) vazio.hidden = n > 0;
        var turma = document.getElementById('mr-card-turma');
        if (turma) turma.classList.toggle('oculto', cat !== 'todos' && cat !== 'formacao');
      }
      Array.prototype.forEach.call(chips, function (c) {
        c.addEventListener('click', function () {
          var cat = c.getAttribute('data-cat');
          filtrar(cat);
          ga('select_category', { categoria: cat, modo: modo });
        });
      });

      // --- cliques: matrícula (select_item + click_enroll), chat, saída para a escola, turma para grupos -----------
      document.addEventListener('click', function (e) {
        var alvo = e.target && e.target.closest ? e.target : null;
        if (!alvo) return;
        var cta = alvo.closest('.mr-cta');
        if (cta && cta.getAttribute('data-curso')) {
          var s = cta.getAttribute('data-curso'), local = cta.getAttribute('data-local') || '';
          ga('select_item', { currency: 'BRL', value: INSCRICAO, item_list_name: LISTA, local: local, modo: modo, items: [item(s)] });
          ga('click_enroll', { curso: s, local: local, modo: modo });
        }
        var atalho = alvo.closest('[data-abrir-chat][data-local]');
        if (atalho) ga('chat_atalho', { local: atalho.getAttribute('data-local'), curso: atalho.getAttribute('data-curso') || (foco || '') });
        var turma = alvo.closest('[data-turma-abrir]');
        if (turma) ga('contact_company_training', { local: turma.getAttribute('data-turma-abrir') || '', curso: turma.getAttribute('data-curso') || (foco || '') });
        var escola = alvo.closest('a[href*="escola.cursoscruzvermelha.org"]');
        if (escola) {
          var lugar = escola.getAttribute('data-saida') || 'outro';
          var caminho = '';
          try { caminho = new URL(escola.href).pathname; } catch (err) {}
          var destino = /^\\/cursos\\/./.test(caminho) ? 'curso' : (/^\\/cursos/.test(caminho) ? 'catalogo' : 'home');
          ga('saida_escola', { local: lugar, curso: foco || '', destino: destino, tela: 'matricula', transport_type: 'beacon' });
          try { if (window.fbq) window.fbq('trackCustom', 'SaidaEscola', { content_ids: [foco || ''], content_category: lugar }); } catch (err) {}
        }
      }, true);

      // Perguntas abertas (uma vez por pergunta): mostram quais dúvidas seguram a matrícula.
      var faqVistas = {};
      document.addEventListener('toggle', function (e) {
        var d = e.target;
        if (!d || d.tagName !== 'DETAILS' || !d.open) return;
        var bloco = d.closest('.mr-det') ? 'curso' : d.closest('.mr-faq') ? 'pagina' : '';
        if (!bloco) return;
        var sum = d.querySelector('summary');
        var pergunta = sum ? sum.textContent.replace(/\\s+/g, ' ').trim().slice(0, 100) : '';
        if (faqVistas[bloco + '|' + pergunta]) return;
        faqVistas[bloco + '|' + pergunta] = true;
        var det = d.closest('.mr-det');
        ga('faq_aberta', { bloco: bloco, pergunta: pergunta, curso: det ? det.getAttribute('data-curso') : (foco || '') });
      }, true);

      // Mapa: carrega só quando a pessoa pede (desempenho e cookies de terceiros).
      var mapaBotao = document.getElementById('mr-mapa-carregar');
      if (mapaBotao) mapaBotao.addEventListener('click', function () {
        var mapa = document.getElementById('mr-mapa');
        var f = document.createElement('iframe');
        f.src = mapaBotao.getAttribute('data-src');
        f.title = 'Mapa: Praça da Cruz Vermelha, 10, Centro, Rio de Janeiro';
        f.loading = 'lazy';
        f.referrerPolicy = 'no-referrer-when-downgrade';
        f.allowFullscreen = true;
        mapa.innerHTML = '';
        mapa.appendChild(f);
        ga('mapa_aberto', {});
      });

      // --- visibilidade: cursos vistos, lista vista, botão visto, seções vistas, barra fixa, chat e navegação ------
      var temIO = 'IntersectionObserver' in window;
      function ctas() { return Array.prototype.filter.call(document.querySelectorAll('.mr-cta'), function (el) { return el !== barraCta; }); }
      function fracao(en) { return Math.max(en.intersectionRatio, en.intersectionRect.height / Math.max(1, window.innerHeight)); }
      var degraus = [0, .1, .2, .3, .4, .5, .6, .7, .8, .9, 1];
      var naTela = 0;
      var ctaVisto = false;
      var ctaObs = temIO ? new IntersectionObserver(function (ents) {
        ents.forEach(function (en) {
          var el = en.target, dentro = en.isIntersecting;
          if (dentro !== !!el.__mrNaTela) { el.__mrNaTela = dentro; naTela += dentro ? 1 : -1; }
          if (!ctaVisto && en.intersectionRatio >= .5 && !el.__mrTimer) {
            el.__mrTimer = setTimeout(function () {
              if (ctaVisto) return;
              ctaVisto = true;
              ga('cta_matricula_visto', { curso: el.getAttribute('data-curso') || '', local: el.getAttribute('data-local') || '', modo: modo });
            }, 1000);
          } else if (en.intersectionRatio < .5 && el.__mrTimer) { clearTimeout(el.__mrTimer); el.__mrTimer = null; }
        });
        avaliarBarra();
      }, { threshold: [0, .5] }) : null;
      function observarCta(el) { if (ctaObs) { el.__mrNaTela = false; ctaObs.observe(el); } if (chatObs) chatObs.observe(el); }

      var secoesVistas = {}, cursosVistos = {};
      if (temIO) {
        var catalogo = document.getElementById('cursos');
        var listaObs = new IntersectionObserver(function (ents) {
          ents.forEach(function (en) {
            if (fracao(en) >= .3) {
              listaObs.disconnect();
              ga('view_item_list', { item_list_id: 'matricula', item_list_name: LISTA, items: EXIBICAO.filter(function (s) { return cards[s]; }).map(item) });
            }
          });
        }, { threshold: degraus });
        if (catalogo) listaObs.observe(catalogo);
        var cardObs = new IntersectionObserver(function (ents) {
          ents.forEach(function (en) {
            var s = en.target.getAttribute('data-curso');
            if (!cursosVistos[s] && en.intersectionRatio >= .5) { cursosVistos[s] = true; cardObs.unobserve(en.target); ga('view_course', { curso: s, modo: modo }); }
          });
        }, { threshold: [.5] });
        Object.keys(cards).forEach(function (s) { cardObs.observe(cards[s]); });
        var secaoObs = new IntersectionObserver(function (ents) {
          ents.forEach(function (en) {
            var s = en.target.getAttribute('data-secao');
            if (!secoesVistas[s] && fracao(en) >= .4) { secoesVistas[s] = true; secaoObs.unobserve(en.target); ga('secao_vista', { secao: s, modo: modo }); }
          });
        }, { threshold: degraus });
        Array.prototype.forEach.call(document.querySelectorAll('[data-secao]'), function (el) { secaoObs.observe(el); });
      }

      // Navegação da página (computador): aparece depois do topo e marca a seção atual.
      var nav = document.getElementById('mr-nav');
      var hero = document.querySelector('.mr-hero');
      var navLinks = nav ? nav.querySelectorAll('a[href^="#"]') : [];
      var alvosNav = Array.prototype.map.call(navLinks, function (a) { return document.getElementById(a.getAttribute('href').slice(1)); }).filter(Boolean);
      function atualizarNav() {
        if (!nav || !hero) return;
        var cab = document.querySelector('.main-header');
        if (cab) raiz.style.setProperty('--mr-cabecalho', Math.round(cab.getBoundingClientRect().height) + 'px');
        nav.classList.toggle('visivel', hero.getBoundingClientRect().bottom < 0);
        var melhor = null, melhorY = -Infinity;
        alvosNav.forEach(function (el) { var y = el.getBoundingClientRect().top; if (y <= 120 && y > melhorY) { melhorY = y; melhor = el; } });
        Array.prototype.forEach.call(navLinks, function (a) { a.classList.toggle('ativo', !!melhor && a.getAttribute('href') === '#' + melhor.id); });
      }

      // Barra fixa (celular): depois do topo, sem botão de matrícula na tela, fora do rodapé e sem o aviso de cookies.
      // Sem curso em foco leva aos cursos; com curso em foco, "Garantir minha vaga".
      var fimObs = 0;
      var barraVista = false;
      if (temIO) {
        var fim = new IntersectionObserver(function (ents) {
          ents.forEach(function (en) { if (en.isIntersecting !== !!en.target.__mrFim) { en.target.__mrFim = en.isIntersecting; fimObs += en.isIntersecting ? 1 : -1; } });
          avaliarBarra();
        });
        var rodape = document.querySelector('footer');
        if (rodape) fim.observe(rodape);
        var grupos = document.getElementById('empresas');
        if (grupos) fim.observe(grupos);
      }
      function passouTopo() {
        if (!hero) return true;
        return hero.getBoundingClientRect().bottom < 0;
      }
      function avaliarBarra() {
        if (!barra || !temIO) return;
        var aviso = document.querySelector('.cvrj-ck');
        var janelaAberta = (janela && janela.open) || raiz.classList.contains('mr-modal-aberto');
        var mostrar = celular.matches && naTela === 0 && fimObs === 0 && passouTopo() && !janelaAberta && !(aviso && aviso.offsetParent !== null);
        if (mostrar === !barra.hidden) return;
        barra.hidden = !mostrar;
        raiz.classList.toggle('mr-barra-on', mostrar);
        if (mostrar && !barraVista) { barraVista = true; ga('barra_fixa_vista', { curso: foco || '' }); }
      }
      var agendado = false;
      window.addEventListener('scroll', function () {
        if (agendado) return;
        agendado = true;
        requestAnimationFrame(function () { agendado = false; avaliarBarra(); atualizarNav(); });
      }, { passive: true });

      // Chat: o botão some enquanto um botão de matrícula passa pela faixa de baixo da tela, onde ele fica.
      var chatObs = null, naFaixa = 0;
      function montarChatObs() {
        if (!temIO) return;
        if (chatObs) chatObs.disconnect();
        naFaixa = 0;
        chatObs = new IntersectionObserver(function (ents) {
          ents.forEach(function (en) { if (en.isIntersecting !== !!en.target.__mrFaixa) { en.target.__mrFaixa = en.isIntersecting; naFaixa += en.isIntersecting ? 1 : -1; } });
          raiz.classList.toggle('mr-chat-recolher', naFaixa > 0);
        }, { rootMargin: '-' + Math.max(0, window.innerHeight - 96) + 'px 0px 0px 0px' });
        ctas().forEach(function (el) { el.__mrFaixa = false; chatObs.observe(el); });
      }
      montarChatObs();
      atualizarNav();
      ctas().forEach(function (el) { if (ctaObs) { el.__mrNaTela = false; ctaObs.observe(el); } });
      var redim = null;
      window.addEventListener('resize', function () { clearTimeout(redim); redim = setTimeout(function () { montarChatObs(); avaliarBarra(); }, 200); });

      // Chegada: ?curso= (marcado no <head>: a ficha já está aberta no topo) ou #det-<slug> / #curso-<slug>.
      var inicial = raiz.getAttribute('data-curso');
      if (inicial) { focar(inicial); verCurso(inicial, 'url'); }
      else {
        var h = location.hash.match(/^#(?:det|curso)-([a-z0-9-]+)$/);
        if (h && cards[h[1]]) { abrirDetalhes(h[1], 'url'); }
      }
    })();
  </script>"""


def esc(s: str) -> str:
    return html.escape(s, quote=True)


# "Cruz Vermelha Brasileira" sozinha é a instituição nacional. Nos textos da filial (inclusive os que vêm
# do catálogo da escola) o nome é sempre o completo, para não confundir as duas (decisão de 19/09/2026).
NACIONAL_SOZINHA = re.compile(r"Cruz Vermelha Brasileira(?!\s*(?:[–\-—·,]|<br>)?\s*(?:Filial|Rio de Janeiro|do Rio|no Rio|RJ\b|Rio\b))")


def nome_filial(texto: str) -> str:
    return NACIONAL_SOZINHA.sub("Cruz Vermelha Brasileira Rio de Janeiro", texto or "")


def brl(centavos: int | None) -> str:
    if centavos is None:
        return "consulte a escola"
    reais = centavos / 100
    return "R$ " + f"{reais:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")


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
    """Cabeçalho, rodapé, CSS, GA4, Pixel e script do menu da home, já ajustados para /matricula-cursos-presenciais/."""
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


def brl_curto(centavos: int) -> str:
    """R$ 99 / R$ 1.049 (sem centavos quando redondo), para os cartões e a barra."""
    if centavos % 100:
        return brl(centavos)
    return "R$ " + f"{centavos // 100:,}".replace(",", ".")


def links_escola_medidos(trecho: str, local: str) -> str:
    """UTM e data-saida nos links do cabeçalho/rodapé para a plataforma da escola (medidos: saida_escola)."""
    def troca(m: re.Match) -> str:
        tag, href = m.group(0), m.group(1)
        if "data-saida=" in tag:
            return tag
        novo = href + ("&amp;" if "?" in href else "?") + UTM_ESCOLA.format(local=local).replace("&", "&amp;")
        return tag.replace(f'href="{href}"', f'href="{novo}" data-saida="{local}"', 1)
    return re.sub(r'<a [^>]*href="(https://escola\.cursoscruzvermelha\.org[^"]*)"[^>]*>', troca, trecho)


def turma_dialog(opcoes_turma: str) -> str:
    """A janela (dialog) do pedido de turma para grupos: static/turmas.js e api/turmas.php esperam estes ids."""
    return f'''
        <noscript><style>#turma-form-bloco{{display:block!important;position:static}}</style></noscript>
        <dialog class="mr-demanda-form mr-modal" id="turma-form-bloco" aria-labelledby="turma-form-titulo">
          <button class="mr-modal-fechar" type="button" data-turma-fechar aria-label="Fechar">&times;</button>
          <form id="turma-form" novalidate>
            <h3 id="turma-form-titulo">Peça sua turma ou entre na lista</h3>
            <p class="mr-tf-nota">Nada é cobrado agora. A secretaria responde por e-mail ou WhatsApp.</p>
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
            <a class="btn btn-red" id="tf-matricula" href="{CHECKOUT_URL}" hidden>Fazer matrícula agora</a>
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
                  <label for="tf-telefone">WhatsApp com DDD</label>
                  <input id="tf-telefone" name="telefone" type="tel" maxlength="20" autocomplete="tel" inputmode="tel" required placeholder="(21) 99999-9999">
                  <p class="mr-tf-erro" id="tf-erro-telefone" hidden></p>
                </div>
                <div class="mr-tf-campo mr-tf-largo">
                  <label for="tf-observacoes">Observações <small>(opcional)</small></label>
                  <textarea id="tf-observacoes" name="observacoes" maxlength="2000" rows="3" placeholder="Algo que a secretaria precisa saber sobre o grupo"></textarea>
                  <p class="mr-tf-erro" id="tf-erro-observacoes" hidden></p>
                </div>
                <div class="mr-tf-campo mr-tf-largo">
                  <label class="mr-tf-check"><input type="checkbox" name="consentimento" required> Autorizo a secretaria da Cruz Vermelha Brasileira Rio de Janeiro a falar comigo por e-mail e WhatsApp sobre esta turma.</label>
                  <p class="mr-tf-erro" id="tf-erro-consentimento" hidden></p>
                </div>
              </div>
              <div class="mr-tf-armadilha" aria-hidden="true"><label for="tf-site">Não preencha</label><input id="tf-site" name="site" tabindex="-1" autocomplete="off"></div>
              <p class="mr-tf-erro geral" id="tf-erro" tabindex="-1" role="alert" hidden></p>
              <button class="btn btn-red" id="tf-enviar" type="submit" disabled>Pedir minha turma</button>
              <p class="mr-tf-dica">Seus dados servem só para falar sobre esta turma. Veja a <a href="/privacidade/">política de privacidade</a>.</p>
            </div>
            <noscript><p class="mr-tf-situacao aviso">Para pedir uma turma, escreva para contato@cruzvermelhariodejaneiro.org com o curso, o idioma e quantos alunos são.</p></noscript>
          </form>
          <div class="mr-tf-ok" id="turma-ok" tabindex="-1" hidden>
            <i class="fa-solid fa-circle-check"></i>
            <h3 id="turma-ok-titulo">Pedido recebido!</h3>
            <p id="turma-ok-texto"></p>
            <p class="mr-tf-dica" id="turma-ok-copia"></p>
            <button class="btn btn-outline" id="turma-ok-outro" type="button">Fazer outro pedido</button>
          </div>
        </dialog>'''


def main() -> int:
    home = HOME.read_text(encoding="utf-8")
    dados = json.loads(DADOS.read_text(encoding="utf-8"))
    cursos = {c["slug"]: c for c in dados["cursos"]}
    inscricao = dados["inscricao_centavos"]
    insc = brl_curto(inscricao)

    # Seletor de curso da home: sempre com o mesmo catálogo desta página.
    home_nova = atualizar_seletor_home(home, dados, cursos)
    if home_nova != home:
        HOME.write_text(home_nova, encoding="utf-8")
        print(f"atualizado {HOME.relative_to(RAIZ)} (seletor de cursos)")
        home = home_nova

    partes = partes_da_home(home)
    estilo, header, footer, menu_js, ga4, pixel = (partes[k] for k in ("estilo", "header", "footer", "menu_js", "ga4", "pixel"))
    header = links_escola_medidos(header, "cabecalho")
    footer = links_escola_medidos(footer, "rodape")

    # Ordem do JSON-LD e do chat (grupos do cursos.json) e ordem de exibição dos cartões.
    ordem = [s for g in dados["grupos"] for s in g["cursos"] if s in cursos]
    exibicao = [s for s in ORDEM_EXIBICAO if s in cursos] + [s for s in ordem if s not in ORDEM_EXIBICAO]
    for s in cursos:
        if s not in ORDEM_EXIBICAO or s not in BENEFICIO or s not in COPY_CURSO or s not in CATEGORIA_DE:
            print(f"aviso: o curso {s} não está em ORDEM_EXIBICAO/BENEFICIO/COPY_CURSO/CATEGORIA_DE; entra no fim, com a descrição cortada")

    def curto(s: str) -> str:
        return NOME_CURTO.get(s, cursos[s]["nome"])

    def beneficio(s: str) -> str:
        return BENEFICIO.get(s) or nome_filial(cursos[s]["descricao"])[:60]

    def checkout(s: str, via: str) -> str:
        return f"{CHECKOUT_URL}?curso={s}&amp;via={via}"

    def categoria(s: str) -> tuple[str, str]:
        cid = CATEGORIA_DE.get(s, "formacao")
        return cid, dict(CATEGORIAS)[cid]

    def foto(s: str, largura: int, classe: str = "", loading: str = "lazy", sizes: str = "") -> str:
        img = cursos[s]["imagem"]
        if not (PASTA_IMG / f"{img}-960.webp").exists():
            return ""
        srcset = f'srcset="img/{img}-480.webp 480w, img/{img}-960.webp 960w" sizes="{sizes}" ' if sizes else ""
        cls = f'class="{classe}" ' if classe else ""
        return (f'<img {cls}src="img/{img}-{largura}.webp" {srcset}alt="{esc(cursos[s]["nome"])} na Cruz Vermelha '
                f'Brasileira Rio de Janeiro" loading="{loading}" width="{largura}" height="{largura // 2}">')

    def tem_cert(s: str) -> bool:
        return (PASTA_IMG / f"certificado-{s}-640.webp").exists()

    def cert_img(s: str, loading: str, sizes: str) -> str:
        if not tem_cert(s):
            return ""
        return (f'<img class="mr-cert-img" src="img/certificado-{s}-640.webp" srcset="img/certificado-{s}-640.webp 640w, '
                f'img/certificado-{s}-1200.webp 1200w" sizes="{sizes}" alt="Modelo do certificado do curso de {esc(cursos[s]["nome"])} '
                f'da Cruz Vermelha Brasileira Rio de Janeiro, com o nome do aluno, o curso e a carga horária" loading="{loading}" '
                f'width="640" height="452" data-cert-curso="{s}">')

    def valor_curso(s: str) -> str:
        v = cursos[s].get("valor_curso_centavos")
        return brl_curto(v) if v else "Informação em breve"

    def preco(s: str) -> str:
        return (f'<div class="mr-preco"><div><small>Inscrição</small><b>{insc}</b><span>paga agora</span></div>'
                f'<span class="mr-mais" aria-hidden="true">+</span>'
                f'<div><small>Curso</small><b>{esc(valor_curso(s))}</b><span>pago depois, na escola</span></div>'
                f'<p class="mr-preco-pag"><i class="fa-solid fa-credit-card"></i> PIX ou cartão</p></div>')

    def meta(s: str, com_local: bool = True) -> str:
        c = cursos[s]
        itens = [f'<li><i class="fa-regular fa-clock"></i> {esc(c["carga_horaria"])}</li>']
        if com_local:
            itens.append('<li class="mr-meta-local"><i class="fa-solid fa-location-dot"></i> Presencial · Centro do Rio</li>')
        itens.append(f'<li><i class="fa-solid fa-graduation-cap"></i> Requisito: {esc(c["escolaridade"])}</li>')
        return f'<ul class="mr-meta">{"".join(itens)}</ul>'

    def badge(s: str) -> str:
        st = STATUS_TURMA.get(s)
        if not st or st not in STATUS_ROTULO:
            return ""
        return f'<span class="mr-badge {st.split("_")[0] if st != "vagas_abertas" else "aberto"}">{esc(STATUS_ROTULO[st])}</span>'

    # --- FAQ de cada curso (a Lei Lucas não tem no cursos.json: sai da FAQ da home) ---------------------------
    faq_home = json.loads(FAQ_HOME.read_text(encoding="utf-8")) if FAQ_HOME.exists() else {"grupos": []}
    lei_lucas = [q for g in faq_home.get("grupos", []) for q in g.get("perguntas", [])
                 if q.get("pergunta", "").startswith("O que é a Lei Lucas")]

    def faq_curso(s: str) -> list[tuple[str, str]]:
        itens = [(q["pergunta"], q["resposta"]) for q in (cursos[s].get("faq") or []) if q.get("pergunta") and q.get("resposta")]
        if not itens and s == "primeiros-socorros-lei-lucas":
            itens = [(q["pergunta"], q["resposta"]) for q in lei_lucas]
        return itens

    def objecao(s: str) -> tuple[str, str] | None:
        o = (COPY_CURSO.get(s) or {}).get("objecao")
        if not o:
            return None
        return tuple(x.replace("{insc}", insc).replace("{curso}", valor_curso(s)) for x in o)

    # --- cartão de curso -------------------------------------------------------------------------------------
    def cartao(s: str, posicao: int) -> str:
        c = cursos[s]
        cid, cnome = categoria(s)
        return f'''
          <article class="mr-card mr-detalhe" id="curso-{s}" data-curso="{s}" data-cat="{cid}" data-nome="{esc(c["nome"])}" data-curto="{esc(curto(s))}"{' data-cert' if tem_cert(s) else ''}>
            <figure class="mr-card-capa">{foto(s, 480, "", "eager" if posicao < 2 else "lazy", "(max-width: 719px) 100vw, (max-width: 1023px) 50vw, 300px")}<span class="mr-card-tag">{esc(cnome)}</span>{badge(s)}</figure>
            <div class="mr-card-corpo">
              <h3>{esc(curto(s))}</h3>
              <p class="mr-card-frase">{esc(beneficio(s))}</p>
              {meta(s)}
              {preco(s)}
              <div class="mr-card-acoes">
                <a class="btn btn-red mr-cta" data-local="cartao" data-curso="{s}" href="{checkout(s, "cartao")}">Garantir vaga</a>
                <a class="btn btn-outline" href="#det-{s}" data-detalhes="{s}" data-local="cartao">Ver detalhes</a>
              </div>
            </div>
          </article>'''

    # --- ficha / detalhes do curso (escondida; vai para a janela, ou abre no topo no modo curso) ----------------
    def detalhes(s: str) -> str:
        c = cursos[s]
        cc = COPY_CURSO.get(s) or {}
        cid, cnome = categoria(s)
        sobre = "".join(f"<p>{esc(nome_filial(p))}</p>" for p in c["sobre"])
        aprende = "".join(f'<li><i class="fa-solid fa-check"></i> <span>{esc(a)}</span></li>' for a in cc.get("aprende", []))
        aprende_html = f'<h4>O que você vai aprender</h4><ul>{aprende}</ul>' if aprende else ""
        para_quem = f'<h4>Para quem é indicado</h4><p>{esc(cc["para_quem"])}</p>' if cc.get("para_quem") else ""
        ob = objecao(s)
        perguntas = ([(ob[0], ob[1])] if ob else []) + faq_curso(s)
        faq = "".join(f"<details><summary>{esc(nome_filial(p))}</summary><p>{esc(nome_filial(r))}</p></details>" for p, r in perguntas)
        faq_html = f'<div class="mr-det-faq"><h4>Dúvidas sobre {esc(curto(s))}</h4>{faq}</div>' if faq else ""
        obs = "".join(f"<p>{esc(nome_filial(o))}</p>" for o in c["observacoes"])
        materiais = esc(MATERIAIS.get(s, "Informação em breve"))
        return f'''
        <section class="mr-det" id="det-{s}" data-curso="{s}" aria-labelledby="det-titulo-{s}">
          <div class="mr-det-cab">
            <figure class="mr-det-foto">{foto(s, 960, "", "lazy", "(max-width: 719px) 100vw, 380px")}</figure>
            <div>
              <p class="mr-det-tag">{esc(cnome)} · Curso presencial</p>
              <h3 id="det-titulo-{s}">{esc(cc.get("titulo") or curto(s))}</h3>
              <p class="mr-det-frase">{esc(cc.get("promessa") or beneficio(s))}</p>
              {meta(s)}
              {preco(s)}
              <div class="mr-det-acoes">
                <a class="btn btn-red mr-cta" data-local="detalhes" data-curso="{s}" href="{checkout(s, "detalhes")}">Garantir minha vaga · {insc}</a>
                <p class="mr-micro"><i class="fa-solid fa-rotate-left"></i> 7 dias para desistir, com o valor de volta</p>
              </div>
            </div>
          </div>
          <div class="mr-det-corpo">
            <h4>Sobre o curso</h4>{sobre}{obs}
            {aprende_html}
            {para_quem}
            <div class="mr-det-grade">
              <div><small>Carga horária</small><b>{esc(c["carga_horaria"])}</b></div>
              <div><small>Pré-requisito</small><b>{esc(c["escolaridade"])}</b></div>
              <div><small>Materiais necessários</small><b>{materiais}</b></div>
              <div><small>Local</small><b>Praça da Cruz Vermelha, 10 · Centro</b></div>
            </div>
            <div class="mr-det-cert">{cert_img(s, "lazy", "84px")}<p><b>Certificação:</b> ao concluir os requisitos do curso, você recebe o certificado da Cruz Vermelha Brasileira Rio de Janeiro, com o seu nome, o curso e a carga horária. <a href="#certificado">Veja o certificado</a>.</p></div>
            {faq_html}
            <div class="mr-det-fim">
              <a class="btn btn-red mr-cta" data-local="detalhes_fim" data-curso="{s}" href="{checkout(s, "detalhes_fim")}">Garantir minha vaga · {insc}</a>
              <p class="mr-micro">Inscrição de {insc} agora, por PIX ou cartão · curso {esc(valor_curso(s))} pago depois, na escola · sem criar conta</p>
              <p class="mr-chat-atalho"><a href="#chat" data-abrir-chat data-assunto="matricula" data-curso="{s}" data-local="detalhes">Dúvida antes de pagar? Pergunte no chat.</a></p>
              <p class="mr-turma-linha">Tem um grupo de {TURMA_MINIMO} a {TURMA_MAXIMO} pessoas ou quer este curso em inglês? <a href="#empresas" data-turma-abrir="curso" data-curso="{s}" aria-controls="turma-form-bloco">Peça uma turma</a>.</p>
            </div>
          </div>
        </section>'''

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
                    for q in (c.get("faq") or []) if q.get("pergunta") and q.get("resposta")],
        })
    if chat_widget.atualizar_cursos(para_o_chat):
        print("atualizado site/chat/chat.js (cursos, ficha e dúvidas)")
    chat_tags = chat_widget.tags()

    # --- 1. navegação da página (computador) ------------------------------------------------------------------
    nav = '''
  <nav class="mr-nav" id="mr-nav" aria-label="Seções desta página">
    <div class="wrap">
      <ul>
        <li><a href="#cursos">Cursos</a></li>
        <li><a href="#como-funciona">Como funciona</a></li>
        <li><a href="#certificado">Certificado</a></li>
        <li><a href="#local">Localização</a></li>
        <li><a href="#duvidas">Dúvidas</a></li>
      </ul>
      <a class="btn btn-red" href="#cursos">Ver cursos</a>
    </div>
  </nav>'''

    # --- 2. topo ---------------------------------------------------------------------------------------------
    hero = f'''
    <section class="mr-hero" aria-labelledby="mr-titulo" data-secao="topo">
      <div class="wrap mr-hero-grid">
        <div>
          <p class="eyebrow">Escola de Educação e Saúde</p>
          <h1 id="mr-titulo">Cursos presenciais da Cruz Vermelha no Rio de Janeiro</h1>
          <p class="mr-hero-sub">Aprenda na prática, na sede da Cruz Vermelha Brasileira Rio de Janeiro, no Centro.</p>
          <p class="mr-hero-ajuda">Escolha seu curso, veja os detalhes e garanta sua vaga.</p>
          <ul class="mr-check">
            <li><i class="fa-solid fa-circle-check"></i> Aulas presenciais</li>
            <li><i class="fa-solid fa-circle-check"></i> Certificado</li>
            <li><i class="fa-solid fa-circle-check"></i> PIX ou cartão</li>
            <li><i class="fa-solid fa-circle-check"></i> Formação prática</li>
          </ul>
          <a class="btn btn-red mr-cta-topo" href="#cursos">Ver cursos</a>
          <p class="mr-hero-local"><i class="fa-solid fa-location-dot"></i> <span>Praça da Cruz Vermelha, 10 — Centro, Rio de Janeiro</span></p>
        </div>
        <figure class="mr-hero-foto">
          <img src="{FOTO_TOPO["src"]}" srcset="{FOTO_TOPO["srcset"]}" sizes="(max-width: 719px) 100vw, 520px" width="960" height="727" alt="{esc(FOTO_TOPO["alt"])}" fetchpriority="high">
          <figcaption>{esc(FOTO_TOPO["legenda"])}</figcaption>
        </figure>
      </div>
    </section>'''

    # --- fichas (escondidas) --------------------------------------------------------------------------------
    dets = f'''
    <div class="mr-dets wrap" id="mr-dets">{"".join(detalhes(s) for s in exibicao)}</div>'''

    # --- 3. categorias e 4. cursos ----------------------------------------------------------------------------
    chips = "".join(f'<button class="mr-chip" type="button" data-cat="{cid}" aria-pressed="{"true" if cid == "todos" else "false"}">{esc(n)}</button>'
                    for cid, n in CATEGORIAS)
    cartao_turma = f'''
          <div class="mr-card-turma" id="mr-card-turma">
            <i class="fa-solid fa-people-group"></i>
            <h3>Turma para empresas e grupos</h3>
            <p>De {TURMA_MINIMO} a {TURMA_MAXIMO} alunos, com o mesmo valor por pessoa. Qualquer curso também em inglês.</p>
            <button class="btn btn-outline" type="button" data-turma-abrir="catalogo" aria-controls="turma-form-bloco">Solicitar uma turma</button>
          </div>'''
    cursos_sec = f'''
    <section class="mr-filtro" aria-labelledby="mr-filtro-titulo" data-secao="categorias">
      <div class="wrap">
        <h2 id="mr-filtro-titulo">Qual formação você procura?</h2>
        <div class="mr-chips" role="group" aria-label="Filtrar cursos por categoria">{chips}</div>
      </div>
    </section>
    <section class="mr-cursos" id="cursos" aria-labelledby="mr-cursos-titulo" data-secao="cursos">
      <div class="wrap">
        <h2 id="mr-cursos-titulo" class="mr-escondido">Cursos presenciais e valores</h2>
        <div class="mr-grade">{"".join(cartao(s, i) for i, s in enumerate(exibicao))}{cartao_turma}</div>
        <p class="mr-vazio" id="mr-vazio" hidden>Nenhum curso nesta categoria.</p>
      </div>
    </section>'''

    # --- 6. fotos --------------------------------------------------------------------------------------------
    fotos = "".join(f'<figure><img src="{f["src"]}" srcset="{f["srcset"]}" sizes="(max-width: 719px) 50vw, 280px" width="480" height="360" '
                    f'alt="{esc(f["alt"])}" loading="lazy"><figcaption>{esc(f["legenda"])}</figcaption></figure>' for f in FOTOS_AULAS)
    galeria = f'''
    <section class="mr-fotos" aria-labelledby="mr-fotos-titulo" data-secao="fotos">
      <div class="wrap">
        <p class="eyebrow">Na sede</p>
        <h2 id="mr-fotos-titulo">Aqui você aprende fazendo.</h2>
        <p class="mr-sub">Conhecimento para entender. Prática para saber como agir.</p>
        <div class="mr-fotos-grade">{fotos}</div>
      </div>
    </section>'''

    # --- 7. como funciona -------------------------------------------------------------------------------------
    passos = "".join(f'<li><b>0{i}</b><div><h3>{esc(t)}</h3><p>{esc(d)}</p></div></li>' for i, (t, d) in enumerate(PASSOS_COMO, 1))
    como = f'''
    <section class="mr-como" id="como-funciona" aria-labelledby="mr-como-titulo" data-secao="como_funciona">
      <div class="wrap">
        <p class="eyebrow">Matrícula</p>
        <h2 id="mr-como-titulo">Como funciona</h2>
        <p class="mr-sub">Quatro passos, sem criar conta antes de pagar.</p>
        <ol class="mr-passos">{passos}</ol>
        <p class="mr-como-nota">{esc(GARANTIA_7_DIAS)} <a href="/reembolso/">Regras de cancelamento e reembolso</a>.</p>
      </div>
    </section>'''

    # --- 8. certificado ---------------------------------------------------------------------------------------
    certificado = f'''
    <section class="mr-cert" id="certificado" aria-labelledby="mr-cert-titulo" data-secao="certificado">
      <div class="wrap mr-cert-grade">
        <figure class="mr-cert-figura">{cert_img(CERT_DESTAQUE, "lazy", "(max-width: 719px) 100vw, 420px")}</figure>
        <div class="mr-cert-texto">
          <p class="eyebrow">Certificado</p>
          <h2 id="mr-cert-titulo">Sua formação também fica registrada.</h2>
          <p class="mr-sub">Após concluir os requisitos do curso, o aluno recebe seu certificado emitido pela Cruz Vermelha Brasileira Rio de Janeiro.</p>
          <ul class="mr-cert-itens">
            <li><i class="fa-solid fa-circle-check"></i><span>Nome do aluno</span></li>
            <li><i class="fa-solid fa-circle-check"></i><span>Nome do curso</span></li>
            <li><i class="fa-solid fa-circle-check"></i><span>Carga horária</span></li>
          </ul>
          <p>{esc(CERT_PESO)}</p>
          <p class="mr-cert-nota">{esc(CERT_NOTA)}</p>
        </div>
      </div>
    </section>'''

    # --- 9. história -----------------------------------------------------------------------------------------
    fatos = "".join(f'<li><b>{esc(n)}</b><span>{esc(t)}</span></li>' for n, t in FATOS_HISTORIA)
    historia = f'''
    <section class="mr-historia" id="historia" aria-labelledby="mr-historia-titulo" data-secao="historia">
      <div class="wrap mr-historia-grade">
        <div>
          <p class="eyebrow">Quem dá o curso</p>
          <h2 id="mr-historia-titulo">Mais de um século de história no Rio de Janeiro.</h2>
          <p>{esc(TEXTO_HISTORIA)}</p>
          <ul class="mr-fatos">{fatos}</ul>
          <a class="mr-historia-link" href="/historia/">Conheça nossa história <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <figure>
          <img src="{FOTO_HISTORIA["src"]}" srcset="{FOTO_HISTORIA["srcset"]}" sizes="(max-width: 719px) 100vw, 560px" width="960" height="720" alt="{esc(FOTO_HISTORIA["alt"])}" loading="lazy">
          <figcaption>{esc(FOTO_HISTORIA["legenda"])}</figcaption>
        </figure>
      </div>
    </section>'''

    # --- 10. local -------------------------------------------------------------------------------------------
    local = f'''
    <section class="mr-local" id="local" aria-labelledby="mr-local-titulo" data-secao="local">
      <div class="wrap">
        <p class="eyebrow">Localização</p>
        <h2 id="mr-local-titulo">Onde acontecem as aulas?</h2>
        <div class="mr-local-grade">
          <div class="mr-local-cartao">
            <p class="mr-endereco"><i class="fa-solid fa-location-dot"></i><span><b>Cruz Vermelha Brasileira Rio de Janeiro</b>Praça da Cruz Vermelha, 10<br>Centro — Rio de Janeiro, RJ · CEP 20230-130</span></p>
            <p class="mr-micro" style="margin:0 0 14px">Todos os cursos são presenciais, no Palácio da Cruz Vermelha, sede da filial. Os dias e horários são os de cada turma.</p>
            <div class="mr-local-acoes">
              <a class="btn btn-red" href="{MAPA_ROTA}" target="_blank" rel="noopener">Como chegar</a>
              <button class="btn btn-outline" type="button" id="mr-mapa-carregar" data-src="{MAPA_EMBED}">Ver no mapa</button>
            </div>
          </div>
          <figure class="mr-mapa" id="mr-mapa">
            <img src="{FOTO_FACHADA["src"]}" width="767" height="516" alt="{esc(FOTO_FACHADA["alt"])}" loading="lazy">
            <figcaption>{esc(FOTO_FACHADA["legenda"])}</figcaption>
          </figure>
        </div>
      </div>
    </section>'''

    # --- 11. dúvidas -------------------------------------------------------------------------------------------
    faq_lista = []
    for p, r, r_html in FAQ_PAGINA:
        if r == "__COMPARAR__":
            partes_cmp = []
            for s in COMPARAR:
                if s in cursos:
                    c = cursos[s]
                    publico = PUBLICO_COMPARAR.get(s) or beneficio(s)
                    partes_cmp.append(f'{c["nome"]}: {c["carga_horaria"]}, curso {brl_curto(c["valor_curso_centavos"])}. {publico}')
            r = " ".join(partes_cmp) + " Os três pedem Ensino Fundamental."
            r_html = None
        faq_lista.append((p, r, r_html or esc(r)))
    faq_html = "".join(f"<details><summary>{esc(p)}</summary><p>{rh}</p></details>" for p, _, rh in faq_lista)
    faq_sec = f'''
    <section class="mr-faq" id="duvidas" aria-labelledby="mr-faq-titulo" data-secao="faq">
      <div class="wrap">
        <p class="eyebrow">Dúvidas frequentes</p>
        <h2 id="mr-faq-titulo">Antes de se inscrever</h2>
        {faq_html}
        <p class="mr-chat-atalho mr-faq-chat"><a href="#chat" data-abrir-chat data-assunto="matricula" data-local="faq">Não achou sua dúvida? Pergunte no chat da página.</a> O que ficar de fora, a equipe responde por e-mail em até 3 dias úteis.</p>
      </div>
    </section>'''

    # --- 12. empresas e grupos (o formulário é a janela #turma-form-bloco, de static/turmas.js) ---------------------
    opcoes_turma = '<option value="">Escolha o curso</option>'
    for g in dados["grupos"]:
        itens = "".join(f'<option value="{s}" data-catalogo="1" data-nome="{esc(cursos[s]["nome"])}">{esc(cursos[s]["nome"])}</option>'
                        for s in g["cursos"] if s in cursos)
        opcoes_turma += f'<optgroup label="{esc(g["titulo"])}">{itens}</optgroup>'
    extras = "".join(f'<option value="{s}" data-catalogo="0" data-nome="{esc(n)}">{esc(n)}</option>' for s, n in TURMA_EXTRAS.items())
    opcoes_turma += f'<optgroup label="Só sob demanda">{extras}</optgroup>'
    tipos = "".join(f"<li>{esc(t)}</li>" for t in ["Empresas", "Escolas", "Condomínios", "Instituições", "Grupos organizados"])
    grupos = f'''
    <section class="mr-grupos" id="empresas" aria-labelledby="mr-grupos-titulo" data-secao="empresas">
      <div class="wrap">
        <p class="eyebrow">Para empresas e grupos</p>
        <h2 id="mr-grupos-titulo">Precisa capacitar uma equipe?</h2>
        <p class="mr-sub">Também organizamos turmas para empresas, escolas, condomínios, instituições e grupos: de {TURMA_MINIMO} a {TURMA_MAXIMO} alunos, com o mesmo valor por pessoa, na sede. Qualquer curso também em inglês, e primeiros socorros para jovens de 12 a 14 anos.</p>
        <ul class="mr-grupos-tipos">{tipos}</ul>
        <button class="btn btn-red" type="button" data-turma-abrir="faixa" aria-expanded="false" aria-controls="turma-form-bloco">Solicitar uma turma</button>
        <p class="mr-grupos-nota">Nada é cobrado agora. A secretaria responde em até 3 dias úteis.</p>
        __TURMA_DIALOG__
      </div>
    </section>'''

    # --- 13. chamada final ------------------------------------------------------------------------------------
    final = f'''
    <section class="mr-final" aria-labelledby="mr-final-titulo" data-secao="final">
      <div class="wrap">
        <p class="eyebrow">Matrícula</p>
        <h2 id="mr-final-titulo">Pronto para começar?</h2>
        <p class="mr-sub">Escolha sua formação, veja os detalhes e garanta sua vaga.</p>
        <div class="mr-final-acoes" id="mr-final-acoes">
          <a class="btn btn-red" href="#cursos" data-final-primario>Ver cursos disponíveis</a>
          <a class="btn btn-outline" href="#chat" data-abrir-chat data-assunto="matricula" data-local="final">Falar com a escola</a>
        </div>
        <p class="mr-micro">Inscrição de {insc} por PIX ou cartão · valor do curso pago depois, na escola · 7 dias para desistir, com o valor de volta</p>
      </div>
    </section>'''

    # --- janela dos detalhes e barra fixa ---------------------------------------------------------------------
    janela = '''
  <dialog class="mr-janela" id="mr-janela" aria-label="Detalhes do curso">
    <button class="mr-janela-fechar" type="button" data-janela-fechar aria-label="Fechar">&times;</button>
    <div id="mr-janela-corpo"></div>
  </dialog>'''
    barra = f'''
  <div class="mr-barra" id="mr-barra" hidden>
    <div class="mr-barra-texto"><b id="mr-barra-nome">Cursos presenciais</b><span id="mr-barra-sub">Inscrição {insc} · PIX ou cartão</span></div>
    <a class="btn btn-red" id="mr-barra-cta" data-local="barra" href="#cursos">Ver cursos</a>
  </div>'''

    # --- dados estruturados ------------------------------------------------------------------------------------
    provedor = {"@type": "EducationalOrganization", "@id": f"{ESCOLA}/#escola",
                "name": "Escola de Educação e Saúde CVB-RJ", "url": f"{ESCOLA}/", "address": ENDERECO,
                "parentOrganization": {"@id": f"{ORIGEM}/#organizacao"}}
    itens = []
    for i, s in enumerate(ordem, 1):
        c = cursos[s]
        curso = {"@type": "Course", "name": c["nome"], "description": nome_filial(c["descricao"] or (c["sobre"][0] if c["sobre"] else "")),
                 "url": f"{URL_PAGINA}#det-{s}", "provider": provedor,
                 "educationalCredentialAwarded": "Certificado da Cruz Vermelha Brasileira Rio de Janeiro"}
        if (PASTA_IMG / f"{c['imagem']}-960.webp").exists():
            curso["image"] = f"{URL_PAGINA}img/{c['imagem']}-960.webp"
        ofertas = [{"@type": "Offer", "category": "Paid", "name": "Inscrição", "price": f"{inscricao / 100:.2f}",
                    "priceCurrency": "BRL", "url": f"{URL_PAGINA}#det-{s}", "availability": "https://schema.org/InStock"}]
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
            {"@type": "ListItem", "position": 2, "name": "Cursos presenciais", "item": URL_PAGINA}]},
        {"@context": "https://schema.org", **provedor},
        {"@context": "https://schema.org", "@type": "ItemList", "name": "Cursos presenciais da Cruz Vermelha Brasileira Rio de Janeiro",
         "numberOfItems": len(dados["cursos"]), "url": URL_PAGINA, "itemListElement": itens},
        {"@context": "https://schema.org", "@type": "FAQPage", "mainEntity": [
            {"@type": "Question", "name": p, "acceptedAnswer": {"@type": "Answer", "text": r}} for p, r, _ in faq_lista]},
    ]
    ld_html = "".join(f'\n  <script type="application/ld+json">\n{json.dumps(d, ensure_ascii=False, indent=2)}\n  </script>' for d in ld)
    for d in ld:
        assert "</" not in json.dumps(d, ensure_ascii=False)

    # --- CSS do modo curso (antes da primeira pintura) e scripts ------------------------------------------------
    css_foco = "".join(f'html[data-curso="{s}"] #det-{s}{{display:block}}' for s in exibicao)
    modo_curso_js = ("(function(){try{var q=new URLSearchParams(location.search),c=q.get('curso');"
                     f"if(c&&!q.get('turma')&&{json.dumps(exibicao)}.indexOf(c)>=0)document.documentElement.setAttribute('data-curso',c);"
                     "}catch(e){}})();")
    css = CSS_PAGINA.replace("  </style>", f"    {css_foco}\n  </style>")
    js = (JS_PAGINA.replace("__CHECKOUT__", json.dumps(CHECKOUT_URL)).replace("__INSCRICAO__", f"{inscricao / 100:.2f}")
          .replace("__INSC_CURTO__", json.dumps(insc)).replace("__EXIBICAO__", json.dumps(exibicao)))

    pagina = f"""<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{esc(TITULO)}</title>
  <meta name="description" content="{esc(DESCRICAO)}">
  <link rel="canonical" href="{URL_PAGINA}">
  <link rel="icon" href="/favicon.ico">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Cruz Vermelha Brasileira Rio de Janeiro">
  <meta property="og:locale" content="pt_BR">
  <meta property="og:title" content="{esc(TITULO)}">
  <meta property="og:description" content="{esc(DESCRICAO)}">
  <meta property="og:url" content="{URL_PAGINA}">
  <meta property="og:image" content="{IMAGEM_OG}">
  <meta property="og:image:width" content="{IMAGEM_OG_TAMANHO[0]}">
  <meta property="og:image:height" content="{IMAGEM_OG_TAMANHO[1]}">
  <meta property="og:image:alt" content="Cursos presenciais da Cruz Vermelha Brasileira no Rio de Janeiro">
  <meta name="twitter:card" content="summary_large_image">
  <script>{modo_curso_js}</script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"></noscript>
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
{nav}
  <main id="matricula-cursos-presenciais">
{hero}
{dets}
{cursos_sec}
{galeria}
{como}
{certificado}
{historia}
{local}
{faq_sec}
{grupos}
{final}
  </main>
{janela}
{barra}

{footer}

{menu_js}
{js}
  <script src="{url_estatico("turmas.js")}" defer></script>
{chat_tags}
</body>
</html>
"""
    pagina = pagina.replace("__TURMA_DIALOG__", turma_dialog(opcoes_turma))
    pagina = icones.converter(pagina)  # ícones em SVG inline, sem Font Awesome
    achados = [m for m in MARCADORES_PROIBIDOS if m in pagina]
    if achados:
        print(f"recusado: a página tem marcadores sem dado real ({', '.join(achados)}). Preencha ou tire antes de gerar.")
        return 1
    if pagina.count("<h1") != 1:
        print("recusado: a página precisa de exatamente um <h1>")
        return 1
    SAIDA.write_text(pagina, encoding="utf-8")
    print(f"gravado {SAIDA.relative_to(RAIZ)} ({len(pagina.encode('utf-8'))} bytes, {len(ordem)} cursos)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
