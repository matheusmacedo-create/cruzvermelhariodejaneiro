#!/usr/bin/env python3
"""Gera site/matricula-cursos-presenciais/index.html a partir de cursos.json e do padrão visual da home.

A página é montada com o MESMO <style>, cabeçalho, rodapé, GA4, Meta Pixel e script de menu
de site/index.html, para ficar indistinguível da home. O conteúdo dos cursos vem de
site/matricula-cursos-presenciais/cursos.json (gerado por scripts/sincronizar_catalogo.py a partir
do catálogo público da escola) e as fotos de img/ (geradas por scripts/gerar_imagens_matricula.py).

Redesenho de 04/10/2026, focado em conversão (especificação, diagnóstico e julgamento no README, seção
"Página de matrícula: redesenho para conversão"):
  - topo curto, sem botão-âncora: o primeiro botão da página já leva ao checkout de um curso;
  - catálogo em cartões, cada um com preço e botão "Fazer matrícula"; detalhes, sobre e dúvidas do curso
    num <details>; comparador dos três cursos de primeiros socorros;
  - modo curso: quem chega com ?curso=<slug> (anúncio, home, chat) vê a ficha daquele curso no topo,
    escolhida por um script no <head> antes da primeira pintura, e o ViewContent dispara ao carregar;
  - "O que acontece depois que você paga" e os 7 dias para desistir logo depois do catálogo;
  - turmas para grupos numa faixa compacta no fim, com o formulário recolhido;
  - barra fixa de matrícula no celular quando há um curso em foco e nenhum botão na tela;
  - dez dos treze links para a plataforma da escola saem; os três que ficam (cabeçalho, uma pergunta e o
    rodapé) levam UTM e disparam saida_escola/SaidaEscola.
Regras que continuam: cara institucional, sem urgência falsa, sem depoimento ou número inventado (o gerador
recusa marcadores [INSERIR …]), sem telefone nem WhatsApp da secretaria, prometer só o que o sistema cumpre.

Uso:  python3 scripts/sincronizar_catalogo.py && python3 scripts/gerar_imagens_matricula.py
      && python3 scripts/gerar_matricula_presencial.py
Depois: publicar site/matricula-cursos-presenciais/ (index.html + img/) com scripts/publicar_hostinger.sh.

Os botões levam ao checkout (CHECKOUT_URL) com ?curso=<slug>&via=<lugar do botão>; o script da página
acrescenta as UTMs/fbclid/gclid da URL atual. Sem JavaScript os links já funcionam.

Turmas sob demanda (04/10/2026): seção #turmas-sob-demanda para grupos de 15 a 30 alunos, qualquer curso
em inglês e primeiros socorros para jovens de 12 a 14 anos. O formulário (static/turmas.js) manda para
api/turmas.php; as regras estão em api/lib/turmas.php. Link de anúncio:
?turma=1&turma_curso=<slug>&idioma=en&alunos=15 abre o formulário preenchido.
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
TITULO = "Cursos e matrícula | Cruz Vermelha Brasileira Rio de Janeiro"
DESCRICAO = ("Cursos presenciais da Cruz Vermelha Brasileira Rio de Janeiro: primeiros socorros, bombeiro civil e "
             "cuidador de idosos, no Centro do Rio. Inscrição de R$ 99.")
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
                    "manequim e instrutor ao lado. Quem conclui recebe o certificado emitido pela Cruz Vermelha Brasileira Rio de "
                    "Janeiro. O curso prepara para o atendimento inicial até a chegada do serviço especializado e não habilita para o "
                    "exercício profissional."),
    },
    "suporte-basico-de-vida": {
        "titulo": "Suporte Básico de Vida (BLS) na Cruz Vermelha: RCP e DEA em 4 horas",
        "promessa": "Aprenda a reconhecer uma emergência, fazer RCP e usar o desfibrilador até a chegada do socorro especializado. Teoria e prática, com certificado da filial.",
        "aprende": ["Avaliar a vítima e fazer RCP (reanimação cardiopulmonar)", "Usar o desfibrilador externo automático (DEA)",
                    "Agir em engasgos, desmaios, hemorragias e traumas"],
        "para_quem": "Profissionais da saúde, educação, segurança e empresas, e qualquer pessoa que queira agir certo numa emergência, sem experiência prévia.",
        "objecao": ("Nunca fiz nada na área da saúde. Posso fazer este curso?",
                    "Pode. O curso é aberto tanto a profissionais quanto a pessoas sem experiência prévia, e pede apenas Ensino "
                    "Fundamental. As 4 horas são presenciais, na Praça da Cruz Vermelha, 10, no Centro do Rio, e combinam teoria e "
                    "treinamento prático para desenvolver segurança durante os atendimentos: RCP, desengasgo e controle de hemorragias "
                    "pedem prática com manequim e instrutor ao lado. Quem conclui recebe o certificado emitido pela Cruz Vermelha "
                    "Brasileira Rio de Janeiro. O curso prepara para o atendimento inicial até a chegada do socorro especializado e não "
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
                    "presenciais na Praça da Cruz Vermelha, 10, no Centro, com certificado da filial a quem conclui. É curso livre: "
                    "prepara para o atendimento inicial até a chegada do socorro especializado e não substitui habilitação profissional "
                    "regulamentada. A escola que quer treinar a equipe inteira pode pedir uma turma fechada, de 15 a 30 pessoas, em "
                    "“Turmas para empresas e grupos”, nesta página."),
    },
    "puncao-venosa": {
        "titulo": "Punção Venosa na Cruz Vermelha: 8 horas com prática supervisionada",
        "promessa": "Acesso venoso com segurança e precisão: anatomia, materiais, preparo do paciente e prevenção de complicações, com prática supervisionada e certificado.",
        "aprende": ["Anatomia do sistema venoso e técnicas de punção", "Escolha de dispositivos, materiais e preparo do paciente",
                    "Biossegurança e prevenção de complicações"],
        "para_quem": "Estudantes e profissionais da saúde que querem aperfeiçoar a técnica, conforme as normas da profissão. Pede Ensino Médio.",
        "objecao": ("Em 8 horas dá para praticar de verdade, ou é só teoria?",
                    "Grande parte do aprendizado acontece em atividades práticas supervisionadas, na sede da Praça da Cruz Vermelha, 10, "
                    "no Centro do Rio. Além da técnica de punção venosa, o curso aborda biossegurança, prevenção de complicações, escolha "
                    "de dispositivos e boas práticas assistenciais. Quem conclui recebe o certificado da Cruz Vermelha Brasileira Rio de "
                    "Janeiro, com o nome do curso e a carga horária. A vaga é garantida com a inscrição de {insc}; os {curso} do curso "
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
                    "plataforma da escola, à vista ou parcelado com juros. Quem conclui as 80 horas recebe o certificado de curso "
                    "livre emitido pela Cruz Vermelha Brasileira Rio de Janeiro, com o nome do curso e a carga horária."),

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
        "promessa": "Em 24 horas presenciais, você aprende a implantar pigmentos, corrigir assimetrias visuais e uniformizar a cor dos lábios, com biossegurança e certificado.",
        "aprende": ["Colorimetria e técnicas de implantação de pigmentos", "Correção de assimetrias visuais e uniformização da cor",
                    "Avaliação do cliente e cuidados pré e pós-procedimento"],
        "para_quem": "Para quem está começando na estética e para profissionais que querem ampliar seus serviços. Pede Ensino Médio, sem exigir experiência.",
        "objecao": ("Nunca trabalhei com estética. O curso serve para mim?",
                    "Serve. O curso atende tanto iniciantes quanto profissionais que desejam ampliar seus serviços, e não exige "
                    "experiência prévia: a escolaridade mínima é o Ensino Médio. São 24 horas presenciais na sede, na Praça da Cruz "
                    "Vermelha, 10, no Centro do Rio, com abordagem prática para desenvolver segurança e qualidade na execução da técnica. "
                    "Ao concluir todas as etapas, você recebe o certificado da Cruz Vermelha Brasileira Rio de Janeiro."),
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
    ("Quando começa minha aula?", RESPOSTA_QUANDO, None),
    ("Posso desistir depois de pagar?",
     GARANTIA_7_DIAS + (" " + GARANTIA_DEPOIS if GARANTIA_APOS_7_DIAS else "") + " Para pedir, use o chat “Fale com a gente”, "
     "no assunto “Pagamento ou PIX”. As regras completas estão na página de cancelamento e reembolso.",
     html.escape(GARANTIA_7_DIAS + (" " + GARANTIA_DEPOIS if GARANTIA_APOS_7_DIAS else "")) + " Para pedir, use o chat "
     "“Fale com a gente”, no assunto “Pagamento ou PIX”. As regras completas estão na "
     '<a href="/reembolso/">página de cancelamento e reembolso</a>.'),
    ("Posso parcelar?",
     "A inscrição de R$ 99 é paga à vista, por PIX ou cartão. O valor do curso é pago na plataforma da escola, à vista ou "
     "parcelado com juros, nas condições informadas lá.", None),
    ("Qual curso de primeiros socorros eu faço?", "__COMPARAR__", "__COMPARAR_HTML__"),
    ("O certificado é reconhecido?",
     "Sim. Quem conclui o curso recebe o certificado da Cruz Vermelha Brasileira Rio de Janeiro, com o seu nome, o curso e a "
     "carga horária. A Cruz Vermelha é reconhecida nacional e internacionalmente pela tradição em formação humanitária e em "
     "emergências, e a Cruz Vermelha Brasileira é a Sociedade Nacional, no Brasil, do Movimento Internacional da Cruz Vermelha "
     "e do Crescente Vermelho. Como todo curso livre, aqui ou em qualquer instituição, ele não passa pelo MEC, que regula a "
     "educação formal (ensino técnico, graduação e pós). No Bombeiro Civil, a homologação profissional é feita ao final do "
     "curso, à parte.",
     None),
    ("Os cursos são gratuitos?",
     "Não. Os sete cursos presenciais são pagos: a inscrição de R$ 99 garante a vaga, e o valor do curso, de R$ 150 a R$ 950, "
     "é pago depois. Quem quer aprender e servir pode ser voluntário, num caminho separado dos cursos.", None),
    ("Onde são as aulas?",
     "Na sede da filial, o Palácio da Cruz Vermelha, na Praça da Cruz Vermelha, 10, Centro do Rio de Janeiro. Todos os "
     "cursos são presenciais.", None),
    ("Preciso criar conta ou escolher turma agora?", RESPOSTA_CONTA, None),
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


# --- CSS e script da página (redesenho de 04/10/2026) ----------------------------------------------------
# Texto com contraste de 7:1 sobre o fundo claro: #4a5568 no lugar de --muted (3,8:1) nos textos de apoio.
# O chat e o aviso de cookies só mudam aqui, por CSS local com mais especificidade (html body …): chat.js,
# chat.css e consentimento.js são compartilhados pelo site inteiro e não mudam neste redesenho. Este CSS
# depende das classes .cv-chat / .cv-chat-abrir / .cv-chat-abrir-rotulo (chat.js) e .cvrj-ck (consentimento.js).
CSS_PAGINA = """
  <style>
    :root { --apoio: #4a5568; }
    /* modo curso (?curso=<slug>): o script do <head> marca <html data-curso>; a ficha certa aparece por CSS */
    .mr-so-curso { display: none; }
    html[data-curso] .mr-so-curso { display: inline; }
    html[data-curso] .mr-so-geral { display: none; }
    html[data-curso] .mr-hero-apoio { display: none; }
    html[data-curso] .mr-hero { padding: 14px 0 4px; border-bottom: 0; background: transparent; }
    html[data-curso] .mr-hero-grid { display: block; }
    html[data-curso] .mr-hero .eyebrow { display: none; }
    html[data-curso] .mr-hero h1 { font-size: .95rem; font-weight: 700; color: var(--apoio); letter-spacing: 0; line-height: 1.35; margin: 0; }
    .mr-ficha { display: none; }
    /*__FICHAS__*/

    /* topo */
    .mr-hero { background: var(--soft); border-bottom: 1px solid var(--line); padding: 30px 0 26px; }
    .mr-hero-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 40px; align-items: center; }
    .mr-hero .eyebrow { font-size: .78rem; }
    .mr-hero h1 { color: var(--black); font-size: clamp(1.8rem, 3.2vw, 2.4rem); line-height: 1.08; letter-spacing: -.03em; margin: 8px 0 12px; }
    .mr-hero-sub { font-size: 1.05rem; color: var(--apoio); margin: 0 0 14px; max-width: 62ch; }
    .mr-confianca-linha { list-style: none; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 6px 22px; color: var(--text); font-weight: 600; font-size: .95rem; }
    .mr-confianca-linha li { display: inline-flex; align-items: center; gap: 8px; }
    .mr-confianca-linha i { color: var(--red); }
    .mr-hero-passos { background: #fff; border: 1px solid var(--line); border-radius: var(--radius); padding: 22px; box-shadow: var(--shadow); }
    .mr-hero-passos-titulo { font-weight: 800; color: var(--black); margin: 0 0 8px; }
    .mr-hero-passos ol { margin: 0 0 14px; padding-left: 20px; color: var(--text); font-size: .95rem; }
    .mr-hero-passos li { margin: 6px 0; }
    .mr-selo { display: inline-flex; align-items: center; gap: 6px; font-size: .8rem; font-weight: 700; color: #0f5132; background: #e9f7ef; border-radius: 999px; padding: 5px 10px; margin: 0; }

    /* certificado (amostra) */
    .mr-cert-img { width: 100%; height: auto; display: block; border-radius: 10px; box-shadow: 0 10px 30px rgba(16, 24, 40, .16); background: #fff; }
    .mr-hero-cert { margin: 0; }
    .mr-hero-cert .mr-cert-img { transform: rotate(-1.5deg); }
    .mr-hero-cert figcaption { margin-top: 10px; font-size: .82rem; color: var(--apoio); text-align: center; }
    .mr-hero-cert figcaption b { color: var(--black); font-size: .9rem; }
    .mr-ficha-cert { margin: 0; }
    .mr-ficha-cert figcaption { margin-top: 10px; font-size: .85rem; color: var(--apoio); text-align: center; }
    .mr-cert-curto { display: flex; gap: 12px; align-items: center; margin: 14px 0 0; padding: 10px 12px; background: var(--soft); border: 1px solid var(--line); border-radius: 12px; }
    .mr-cert-curto .mr-cert-img { width: 96px; flex-shrink: 0; border-radius: 4px; box-shadow: 0 2px 8px rgba(16, 24, 40, .14); }
    .mr-cert-curto p { margin: 0; font-size: .88rem; color: var(--text); line-height: 1.4; }
    .mr-cert-curto b { color: var(--black); }
    .mr-ficha .mr-cert-curto { display: none; }
    .mr-curso-fecho .mr-cert-curto { margin: 0 0 14px; }
    .mr-cert { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.1fr); gap: 32px; align-items: center; background: #fff; border: 1px solid var(--line); border-radius: var(--radius); padding: 26px; margin-top: 22px; scroll-margin-top: 96px; }
    .mr-cert-figura { margin: 0; }
    .mr-cert-texto h3 { color: var(--black); font-size: 1.3rem; margin: 4px 0 12px; line-height: 1.2; }
    .mr-cert-texto ul { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
    .mr-cert-texto li { display: flex; gap: 10px; color: var(--text); font-size: .95rem; }
    .mr-cert-texto li i { color: #0f7b3e; flex-shrink: 0; margin-top: 2px; }
    .mr-cert-nota { color: var(--apoio); font-size: .82rem; margin: 12px 0 0; }

    /* ficha do curso (modo curso) */
    .mr-fichas { margin: 0 auto; }
    .mr-ficha { grid-template-columns: 44% minmax(0, 1fr); gap: 28px; align-items: start; background: #fff; border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow); padding: 24px; margin: 8px 0 28px; }
    .mr-ficha-img { width: 100%; height: auto; aspect-ratio: 4 / 3; object-fit: cover; border-radius: 12px; display: block; background: var(--soft); }
    .mr-ficha-chapeu { font-size: .76rem; letter-spacing: .1em; text-transform: uppercase; color: var(--red); font-weight: 800; margin: 0 0 6px; }
    .mr-ficha-titulo { font-size: clamp(1.4rem, 3vw, 2rem); font-weight: 900; color: var(--black); letter-spacing: -.02em; line-height: 1.12; margin: 0 0 10px; }
    .mr-ficha-meta { display: flex; flex-wrap: wrap; gap: 4px 16px; color: var(--text); font-size: .92rem; margin: 0 0 14px; }
    .mr-ficha-meta span { display: inline-flex; align-items: center; gap: 6px; }
    .mr-ficha-meta i { color: var(--red); }
    .mr-ficha .mr-cta { min-width: 280px; }
    .mr-ficha-promessa { font-size: 1.02rem; line-height: 1.5; color: var(--text); margin: 0 0 12px; }
    .mr-aprende { margin: 16px 0 0; padding: 14px 16px; background: var(--soft); border-radius: 12px; }
    .mr-aprende-titulo { font-weight: 800; color: var(--black); margin: 0 0 6px; }
    .mr-aprende ul { list-style: none; margin: 0; padding: 0; }
    .mr-aprende li { display: flex; gap: 8px; align-items: baseline; margin: 4px 0; font-size: .95rem; }
    .mr-aprende li i { color: #0f7b3e; flex: none; }
    .mr-para-quem { font-size: .92rem; color: var(--text); margin: 10px 0 0; }
    .mr-curso-detalhes > .mr-aprende { margin: 0 0 6px; grid-column: 1 / -1; }
    .mr-objecao { margin: 14px 0 0; border-left: 3px solid var(--red); padding: 2px 0 2px 14px; font-size: .93rem; color: var(--text); }
    .mr-objecao p { margin: 4px 0 0; line-height: 1.55; }
    .mr-objecao .mr-objecao-p { font-weight: 800; color: var(--black); margin: 0; }
    .mr-mini-passos { margin: 14px 0 0; padding-left: 20px; color: var(--apoio); font-size: .9rem; }
    .mr-mini-passos li { margin: 2px 0; }
    .mr-ficha-links { font-size: .9rem; margin: 14px 0 0; color: var(--apoio); }
    .mr-ficha-links a { color: var(--red); font-weight: 700; }

    /* preço e botão (ficha, cartão e detalhes) */
    .mr-preco { margin: 4px 0 16px; }
    .mr-preco-agora { display: flex; align-items: baseline; flex-wrap: wrap; gap: 2px 10px; margin: 0; }
    .mr-preco-agora span { color: var(--apoio); font-size: .88rem; }
    .mr-preco-agora b { font-size: 1.8rem; font-weight: 800; color: var(--black); line-height: 1.1; }
    .mr-preco-depois { color: var(--apoio); font-size: .95rem; margin: 4px 0 0; }
    .mr-preco-obs { font-size: .88rem; color: var(--apoio); border-left: 3px solid var(--line); padding-left: 10px; margin: 8px 0 0; }
    .mr-preco-total { font-weight: 700; color: var(--black); margin: 6px 0 0; }
    .mr-cta { min-height: 56px; font-size: 1rem; }
    .mr-micro { color: var(--apoio); font-size: .85rem; margin: 10px 0 0; line-height: 1.55; }
    .mr-micro i { color: #0f7b3e; }
    .mr-chat-atalho { font-size: .9rem; margin: 10px 0 0; color: var(--apoio); }
    .mr-chat-atalho a { color: var(--red); font-weight: 700; text-decoration: underline; }

    /* catálogo em cartões */
    .mr-catalogo { padding: 22px 0 48px; }
    html[data-curso] .mr-catalogo { padding-top: 4px; }
    .mr-catalogo-titulo { font-size: 1.15rem; color: var(--black); margin: 0 0 8px; }
    html:not([data-curso]) .mr-catalogo-titulo { position: absolute; width: 1px; height: 1px; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); clip-path: inset(50%); white-space: nowrap; }
    .mr-cartoes { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 20px; align-items: stretch; }
    .mr-grupo-rotulo { grid-column: 1 / -1; font-size: .78rem; letter-spacing: .12em; text-transform: uppercase; color: var(--apoio); font-weight: 800; margin: 16px 0 -6px; }
    .mr-grupo-rotulo:first-child { margin-top: 0; }
    .mr-curso { display: flex; flex-direction: column; background: #fff; border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; scroll-margin-top: 96px; }
    .mr-curso.ativo { border-color: var(--red); box-shadow: 0 0 0 1px var(--red); }
    .mr-curso.aberto, .mr-curso:has(> .mr-curso-mais[open]) { grid-column: 1 / -1; }
    /* Cartão compacto (miniatura + nome) em qualquer largura: a 1ª fileira de botões cabe na primeira tela de
       1280x800, e a foto grande fica nos detalhes. */
    .mr-curso-topo { display: flex; align-items: center; gap: 14px; padding: 16px 18px 0; }
    .mr-curso-mini { width: 88px; height: 66px; object-fit: cover; display: block; border-radius: 8px; flex-shrink: 0; background: var(--soft); }
    .mr-curso-topo > div { min-width: 0; }
    .mr-curso-nome { font-size: 1.1rem; color: var(--black); margin: 0 0 4px; line-height: 1.2; }
    .mr-curso-meta { color: var(--apoio); font-size: .88rem; margin: 0; }
    .mr-curso-beneficio { color: var(--text); font-size: .92rem; margin: 8px 18px 0; }
    .mr-curso-acao { margin-top: auto; padding: 14px 18px 16px; display: flex; flex-direction: column; gap: 10px; }
    .mr-preco-curto { margin: 0; display: flex; flex-direction: column; font-size: .88rem; color: var(--apoio); line-height: 1.35; }
    .mr-preco-curto strong { color: var(--black); font-size: 1.05rem; }
    .mr-curso-acao .mr-cta { width: 100%; }
    .mr-curso-mais { border-top: 1px solid var(--line); }
    .mr-curso-mais > summary { cursor: pointer; min-height: 44px; display: flex; align-items: center; padding: 0 18px; font-weight: 700; color: var(--red); list-style: none; }
    .mr-curso-mais > summary::-webkit-details-marker { display: none; }
    .mr-curso-mais > summary::after { content: "+"; margin-left: auto; font-size: 1.3rem; line-height: 1; }
    .mr-curso-mais[open] > summary::after { content: "–"; }
    .mr-curso-detalhes { padding: 4px 18px 22px; display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 4px 32px; }
    .mr-curso-foto-grande { margin: 8px 0 4px; }
    .mr-curso-foto-grande .mr-foto { width: 100%; max-width: 520px; height: auto; aspect-ratio: 4 / 3; object-fit: cover; border-radius: 12px; display: block; background: var(--soft); }
    .mr-curso-sobre h4, .mr-faq h4 { font-size: 1rem; color: var(--black); margin: 12px 0 6px; }
    .mr-curso-sobre p { margin: 0 0 10px; color: var(--text); font-size: .95rem; }
    .mr-curso-fecho { grid-column: 1 / -1; border-top: 1px solid var(--line); margin-top: 10px; padding-top: 16px; max-width: 620px; }
    .mr-faq details, .mr-faq-pagina details, .mr-demanda-faq details { border-top: 1px solid var(--line); padding: 10px 0; }
    .mr-faq summary, .mr-faq-pagina summary, .mr-demanda-faq summary { cursor: pointer; font-weight: 700; color: var(--black); min-height: 28px; }
    .mr-faq details p, .mr-faq-pagina details p, .mr-demanda-faq details p { margin: 8px 0 0; color: var(--text); }
    .mr-turma-linha { color: var(--apoio); font-size: .9rem; margin: 14px 0 0; }
    .mr-turma-linha a { color: var(--red); font-weight: 700; text-decoration: underline; }
    .mr-turma-linha-catalogo { margin-top: 24px; }

    /* comparador dos cursos de primeiros socorros */
    .mr-comparar { grid-column: 1 / -1; background: var(--soft); border: 1px solid var(--line); border-radius: 12px; padding: 0 18px; scroll-margin-top: 96px; }
    .mr-comparar > summary { cursor: pointer; min-height: 48px; display: flex; align-items: center; font-weight: 700; color: var(--black); }
    .mr-comparar-grade { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; padding: 4px 0 18px; }
    .mr-comparar-linha { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 14px; display: flex; flex-direction: column; gap: 6px; }
    .mr-comparar-linha p { margin: 0; font-size: .9rem; color: var(--text); }
    .mr-comparar-linha .mr-comparar-nome { font-weight: 800; color: var(--black); font-size: 1rem; }
    .mr-comparar-linha .mr-cta { margin-top: auto; min-height: 48px; }

    /* depois da inscrição e garantia */
    .mr-depois { background: var(--soft); border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); padding: 48px 0; }
    .mr-depois h2, .mr-confianca h2, .mr-faq-pagina h2, .mr-demanda h2 { color: var(--black); font-size: clamp(1.45rem, 3vw, 2rem); letter-spacing: -.02em; margin: 0 0 18px; line-height: 1.15; }
    .mr-passos { list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
    .mr-passos li { display: flex; gap: 14px; background: #fff; border: 1px solid var(--line); border-radius: var(--radius); padding: 20px; }
    .mr-passos b { flex-shrink: 0; width: 36px; height: 36px; border-radius: 50%; background: var(--red); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 900; }
    .mr-passos h3 { font-size: 1.02rem; color: var(--black); margin: 5px 0 6px; }
    .mr-passos p { margin: 0; color: var(--text); font-size: .95rem; }
    .mr-nota-curta { color: var(--apoio); font-size: .92rem; margin: 14px 0 0; }
    .mr-garantia { display: flex; gap: 16px; background: #fff; border-left: 4px solid var(--red); border-radius: 0 var(--radius) var(--radius) 0; padding: 20px 22px; margin-top: 22px; scroll-margin-top: 96px; }
    .mr-garantia > i { color: var(--red); font-size: 1.5rem; flex-shrink: 0; margin-top: 2px; }
    .mr-garantia h3 { margin: 8px 0 6px; color: var(--black); font-size: 1.1rem; }
    .mr-garantia p { margin: 0 0 8px; color: var(--text); }
    .mr-garantia-mais summary { cursor: pointer; font-weight: 700; color: var(--red); min-height: 44px; display: flex; align-items: center; }
    .mr-garantia-mais a { color: var(--red); font-weight: 700; }

    /* confiança */
    .mr-confianca { padding: 44px 0; }
    .mr-confianca-texto { max-width: 75ch; color: var(--text); margin: 0 0 18px; }
    .mr-confianca-itens { list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
    .mr-confianca-itens li { display: flex; gap: 10px; align-items: flex-start; font-size: .92rem; color: var(--text); background: var(--soft); border-radius: 12px; padding: 14px; }
    .mr-confianca-itens i { color: var(--red); flex-shrink: 0; margin-top: 2px; }
    .mr-confianca-link { margin: 16px 0 0; }
    .mr-confianca-link a { color: var(--red); font-weight: 700; }

    /* perguntas frequentes */
    .mr-faq-pagina { padding: 48px 0; border-top: 1px solid var(--line); }
    .mr-faq-pagina .wrap { max-width: 820px; }
    .mr-faq-pagina details p a { color: var(--red); font-weight: 600; text-decoration: underline; }
    .mr-faq-pagina details p a[data-saida] { color: inherit; font-weight: 400; }
    .mr-faq-chat { margin-top: 20px; }
    .mr-final { margin-top: 22px; }
    .mr-final-link { color: var(--red); font-weight: 700; text-decoration: underline; }

    /* turmas para grupos (faixa compacta; o formulário abre no botão) */
    .mr-demanda { padding: 44px 0 56px; background: var(--soft); border-top: 1px solid var(--line); }
    .mr-demanda [hidden] { display: none !important; }
    .mr-demanda-grade { display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr); gap: 36px; align-items: start; }
    .mr-demanda h2 { font-size: clamp(1.3rem, 2.6vw, 1.7rem); }
    .mr-demanda-abertura { color: var(--text); margin: 0 0 12px; }
    .mr-demanda-itens { list-style: none; padding: 0; margin: 0 0 16px; display: grid; gap: 8px; }
    .mr-demanda-itens li { display: flex; gap: 10px; color: var(--text); font-size: .95rem; }
    .mr-demanda-itens i { color: var(--red); flex-shrink: 0; margin-top: 3px; }
    .mr-demanda-botao { min-height: 52px; }
    .mr-demanda-form { margin-top: 24px; background: #fff; border: 1px solid var(--line); border-radius: var(--radius); padding: 28px; scroll-margin-top: 96px; }
    .mr-demanda-form h3 { color: var(--black); font-size: 1.3rem; margin: 0 0 4px; }
    .mr-tf-nota { color: var(--apoio); margin: 0 0 18px; }
    .mr-tf-grade { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px 20px; }
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
    .mr-tf-opcoes input, .mr-tf-check input { width: 18px; height: 18px; margin: 3px 0 0; flex-shrink: 0; accent-color: var(--red); }
    .mr-tf-dica { color: var(--apoio); font-size: .85rem; margin: 6px 0 0; }
    .mr-tf-dica a { color: var(--red); text-decoration: underline; }
    .mr-tf-erro { color: #b91c1c; font-size: .88rem; font-weight: 600; margin: 6px 0 0; }
    .mr-tf-erro.geral { background: #fff0f2; border: 1px solid #f5c2c7; border-radius: 12px; padding: 10px 14px; margin: 16px 0 0; }
    .mr-tf-situacao { margin: 18px 0; padding: 14px 18px; border-left: 4px solid var(--line); background: var(--soft); border-radius: 0 12px 12px 0; color: var(--text); }
    .mr-tf-situacao.fechada { border-left-color: #0f7b3e; }
    .mr-tf-situacao.lista { border-left-color: var(--red); }
    .mr-tf-situacao.aviso { border-left-color: #b7791f; background: #fffaf0; }
    #tf-dados { margin-top: 4px; }
    #tf-enviar { margin-top: 20px; min-height: 56px; font-size: 1rem; }
    #tf-enviar:disabled { background: #e2e8f0; color: var(--apoio); box-shadow: none; cursor: not-allowed; transform: none; }
    .mr-tf-armadilha { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
    .mr-tf-ok { text-align: center; padding: 18px 0; }
    .mr-tf-ok:focus { outline: 0; }
    .mr-tf-ok > i { color: #0f7b3e; font-size: 2.4rem; }
    .mr-tf-ok h3 { margin: 10px 0 8px; }
    .mr-tf-ok p { max-width: 60ch; margin: 0 auto 8px; color: var(--text); }

    /* barra fixa de matrícula (celular, só com curso em foco) */
    .mr-barra { position: fixed; left: 0; right: 0; bottom: 0; z-index: 1050; display: flex; align-items: center; gap: 12px; background: #fff; border-top: 1px solid var(--line); box-shadow: 0 -8px 24px rgba(16, 24, 40, .12); padding: 8px 16px calc(8px + env(safe-area-inset-bottom)); min-height: 64px; animation: mr-sobe .2s ease; }
    .mr-barra[hidden] { display: none; }
    @keyframes mr-sobe { from { transform: translateY(100%); } to { transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) { .mr-barra { animation: none; } }
    .mr-barra-texto { min-width: 0; flex: 1; display: flex; flex-direction: column; }
    .mr-barra-texto b { font-size: .95rem; color: var(--black); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mr-barra-texto span { font-size: .78rem; color: var(--apoio); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mr-barra .mr-cta { min-height: 48px; padding: 10px 16px; font-size: .95rem; white-space: nowrap; flex-shrink: 0; }
    html.mr-barra-on body { padding-bottom: calc(72px + env(safe-area-inset-bottom)); }

    /* chat (sem tocar em chat.css): botão branco, para o vermelho forte ficar só nos botões de matrícula */
    html body .cv-chat .cv-chat-abrir { background: #fff; color: var(--red); border: 1.5px solid var(--red); box-shadow: 0 8px 24px rgba(16, 24, 40, .14); transition: opacity .2s ease, transform .18s ease; }
    html body .cv-chat .cv-chat-abrir:hover { background: #fff7f7; }
    html.mr-chat-recolher body .cv-chat:not(.aberto) .cv-chat-abrir { opacity: 0; pointer-events: none; }
    html.mr-barra-on body .cv-chat { bottom: calc(84px + env(safe-area-inset-bottom)); }

    @media (max-width: 1099px) {
      .mr-cartoes { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .mr-confianca-itens { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 920px) {
      .mr-hero-grid { grid-template-columns: 1fr; }
      .mr-hero-cert { display: none; }
      .mr-demanda-grade { grid-template-columns: 1fr; gap: 12px; }
      .mr-passos { grid-template-columns: 1fr; }
      .mr-comparar-grade { grid-template-columns: 1fr; }
      /* O CSS da home esconde qualquer <nav> abaixo de 920px (regra do menu antigo) e o menu sanfona
         abria sem os links. Nesta página os links voltam a aparecer com o menu aberto. */
      .main-header .header-collapse .nav-links { display: flex !important; }
    }
    @media (max-width: 719px) {
      .mr-so-largo, .mr-so-largo-flex { display: none !important; }
      .mr-hero { padding: 20px 0 16px; }
      .mr-hero .eyebrow { font-size: .7rem; letter-spacing: .1em; }
      .mr-hero h1 { font-size: clamp(1.5rem, 6.4vw, 1.95rem); margin: 6px 0 10px; }
      .mr-hero-sub { font-size: .97rem; margin-bottom: 10px; }
      .mr-confianca-linha { font-size: .86rem; gap: 4px 14px; }
      .mr-ficha { grid-template-columns: 1fr; padding: 16px; margin: 6px 0 18px; gap: 0; }
      .mr-ficha-foto { display: none; }
      .mr-ficha .mr-cert-curto { display: flex; }
      .mr-cert { grid-template-columns: 1fr; gap: 16px; padding: 16px; }
      .mr-cert-texto h3 { font-size: 1.12rem; }
      .mr-cert-texto li { font-size: .9rem; }
      .mr-ficha-titulo { font-size: 1.4rem; }
      .mr-ficha-promessa { font-size: .93rem; line-height: 1.45; margin-bottom: 10px; }
      .mr-ficha .mr-cta { width: 100%; min-width: 0; }
      .mr-catalogo { padding: 14px 0 36px; }
      .mr-cartoes { grid-template-columns: 1fr; gap: 12px; }
      .mr-grupo-rotulo { margin: 10px 0 -2px; }
      .mr-curso-topo { gap: 12px; padding: 14px 14px 0; }
      .mr-curso-mini { width: 64px; height: 48px; }
      .mr-curso-nome { font-size: 1.05rem; }
      .mr-curso-meta { font-size: .85rem; }
      .mr-curso-beneficio { margin: 8px 14px 0; font-size: .9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
      .mr-curso-acao { flex-direction: row; align-items: center; justify-content: space-between; padding: 12px 14px 14px; }
      .mr-curso-acao .mr-cta { width: auto; min-height: 48px; padding: 10px 18px; font-size: .95rem; white-space: nowrap; }
      .mr-curso-mais > summary { padding: 0 14px; }
      .mr-curso-detalhes { grid-template-columns: 1fr; padding: 4px 14px 18px; }
      .mr-curso-fecho .mr-cta { width: 100%; }
      .mr-comparar { padding: 0 14px; }
      .mr-confianca-itens { grid-template-columns: 1fr; gap: 8px; }
      .mr-depois h2, .mr-confianca h2, .mr-faq-pagina h2, .mr-demanda h2 { font-size: 1.35rem; margin-bottom: 14px; }
      .mr-depois { padding: 28px 0; }
      .mr-passos { gap: 10px; }
      .mr-passos li { padding: 14px; gap: 12px; }
      .mr-passos b { width: 30px; height: 30px; font-size: .9rem; }
      .mr-passos h3 { font-size: .98rem; margin: 3px 0 4px; }
      .mr-passos p { font-size: .9rem; }
      .mr-garantia { padding: 16px; gap: 12px; margin-top: 16px; }
      .mr-garantia h3 { font-size: 1.02rem; }
      .mr-garantia p { font-size: .93rem; }
      .mr-confianca, .mr-faq-pagina { padding: 28px 0; }
      .mr-confianca-texto { font-size: .93rem; margin-bottom: 10px; }
      .mr-confianca-itens li { background: none; border-radius: 0; border-bottom: 1px solid var(--line); padding: 9px 0; font-size: .88rem; }
      .mr-confianca-link { margin-top: 10px; }
      .mr-demanda { padding: 28px 0 36px; }
      .mr-demanda-abertura { font-size: .93rem; }
      .mr-demanda-itens li { font-size: .9rem; }
      .mr-demanda-form { padding: 18px; }
      .mr-tf-grade { grid-template-columns: 1fr; }
      html body .cv-chat .cv-chat-abrir { width: 56px; padding: 0; justify-content: center; border-color: var(--line); }
      html body .cv-chat .cv-chat-abrir-rotulo { position: absolute; width: 1px; height: 1px; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); clip-path: inset(50%); white-space: nowrap; }
    }
    @media (min-width: 720px) { .mr-barra { display: none !important; } }
    /* aviso de cookies: só o espaço (texto e REVISAO intactos), para cobrir menos a primeira tela do celular */
    @media (max-width: 559px) {
      html body .cvrj-ck { padding: 14px; }
      html body .cvrj-ck .cvrj-ck-botoes { grid-template-columns: repeat(3, 1fr); }
      html body .cvrj-ck .cvrj-ck-botoes button { font-size: .85rem; padding: 8px 4px; }
    }
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
      Array.prototype.forEach.call(document.querySelectorAll('.mr-curso[data-curso]'), function (el) { cards[el.getAttribute('data-curso')] = el; });
      if (!Object.keys(cards).length) return;
      var modo = raiz.getAttribute('data-curso') ? 'curso' : 'geral';
      function nome(s) { return cards[s] ? cards[s].getAttribute('data-nome') : s; }
      function curto(s) { return cards[s] ? cards[s].getAttribute('data-curto') : s; }

      // UTMs, fbclid e gclid da URL atual vão junto para o checkout (os links já funcionam sem JavaScript).
      var extras = new URLSearchParams();
      new URLSearchParams(location.search).forEach(function (v, k) { if (/^(utm_|fbclid$|gclid$)/.test(k)) extras.set(k, v); });
      function comExtras(href) {
        try { var u = new URL(href, location.origin); extras.forEach(function (v, k) { u.searchParams.set(k, v); }); return u.pathname + u.search; } catch (e) { return href; }
      }
      function linkCheckout(s, local) { return comExtras(CHECKOUT_URL + '?curso=' + encodeURIComponent(s) + '&via=' + local); }
      Array.prototype.forEach.call(document.querySelectorAll('a.mr-cta[href*="/checkout/"]'), function (a) { a.setAttribute('href', comExtras(a.getAttribute('href'))); });

      function ga(evento, dados) { try { if (window.gtag) window.gtag('event', evento, dados || {}); } catch (e) {} }
      function item(s) { return { item_id: s, item_name: nome(s), item_category: 'Cursos presenciais', price: INSCRICAO, quantity: 1 }; }

      // ViewContent (Meta) e view_item (GA4): ao carregar com ?curso= ou #curso-, e ao abrir os detalhes de um curso;
      // uma vez por curso. Nunca por rolagem: o sinal da Meta tem de ser interesse real. O mesmo id vai ao servidor
      // (API de Conversões, só com "sim" para marketing).
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
      }

      // Curso em foco: o da URL, o do último "Ver detalhes" aberto ou o de #curso-. Alimenta a barra fixa, o botão
      // do fim da página e o chat (site/chat/chat.js lê o curso em foco por .mr-detalhe.ativo[data-curso]).
      var foco = null;
      var final = document.getElementById('mr-final');
      var barra = document.getElementById('mr-barra');
      var barraCta = document.getElementById('mr-barra-cta');
      function focar(s) {
        if (!cards[s] || foco === s) return;
        foco = s;
        Object.keys(cards).forEach(function (k) { cards[k].classList.toggle('ativo', k === s); });
        // O certificado da seção "No fim do curso" passa a ser o do curso em foco.
        Array.prototype.forEach.call(document.querySelectorAll('.mr-cert-figura img[data-cert-curso]'), function (img) {
          var atual = img.getAttribute('data-cert-curso');
          if (atual === s || !cards[s].hasAttribute('data-cert')) return;
          img.setAttribute('src', img.getAttribute('src').replace('certificado-' + atual + '-', 'certificado-' + s + '-'));
          img.setAttribute('srcset', img.getAttribute('srcset').split('certificado-' + atual + '-').join('certificado-' + s + '-'));
          img.setAttribute('alt', img.getAttribute('alt').replace(nome(atual), nome(s)));
          img.setAttribute('data-cert-curso', s);
        });
        if (barra) {
          document.getElementById('mr-barra-nome').textContent = curto(s);
          barraCta.setAttribute('href', linkCheckout(s, 'barra'));
          barraCta.setAttribute('data-curso', s);
        }
        if (final) {
          final.innerHTML = '';
          var a = document.createElement('a');
          a.className = 'btn btn-red mr-cta';
          a.setAttribute('data-local', 'faq_final');
          a.setAttribute('data-curso', s);
          a.href = linkCheckout(s, 'faq_final');
          a.textContent = 'Fazer matrícula em ' + curto(s) + ' · ' + INSC_CURTO;
          var m = document.createElement('p');
          m.className = 'mr-micro';
          m.textContent = 'PIX ou cartão, à vista · sem criar conta · 7 dias para desistir, com o valor de volta';
          final.appendChild(a);
          final.appendChild(m);
          observarCta(a);
        }
        avaliarBarra();
      }

      function abrirDetalhes(s, rolar) {
        var c = cards[s];
        if (!c) return;
        var d = c.querySelector('.mr-curso-mais');
        if (d && !d.open) d.open = true;
        if (rolar) c.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
      Array.prototype.forEach.call(document.querySelectorAll('.mr-curso-mais'), function (d) {
        d.addEventListener('toggle', function () {
          var s = d.getAttribute('data-curso');
          var c = cards[s];
          if (c) c.classList.toggle('aberto', d.open);
          if (!d.open) return;
          focar(s);
          verCurso(s, 'detalhes');
          if (c && c.getBoundingClientRect().top < 0) c.scrollIntoView({ block: 'start' });
        });
      });
      Array.prototype.forEach.call(document.querySelectorAll('[data-abrir-detalhes]'), function (a) {
        a.addEventListener('click', function (e) { e.preventDefault(); abrirDetalhes(a.getAttribute('data-abrir-detalhes'), true); });
      });
      Array.prototype.forEach.call(document.querySelectorAll('[data-abrir-comparar]'), function (a) {
        a.addEventListener('click', function (e) {
          var d = document.getElementById('comparar');
          if (!d) return;
          e.preventDefault();
          d.open = true;
          d.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      });
      // A garantia fica aberta no computador; no celular, recolhida.
      if (!celular.matches) Array.prototype.forEach.call(document.querySelectorAll('.mr-garantia-mais'), function (d) { d.open = true; });

      // Cliques: botão de matrícula (select_item com o lugar do botão), atalho do chat e saída para a escola.
      document.addEventListener('click', function (e) {
        var alvo = e.target && e.target.closest ? e.target : null;
        if (!alvo) return;
        var cta = alvo.closest('.mr-cta');
        if (cta && cta.getAttribute('data-curso')) {
          var s = cta.getAttribute('data-curso');
          ga('select_item', { currency: 'BRL', value: INSCRICAO, item_list_name: LISTA, local: cta.getAttribute('data-local') || '', modo: modo, items: [item(s)] });
        }
        var atalho = alvo.closest('[data-abrir-chat][data-local]');
        if (atalho) ga('chat_atalho', { local: atalho.getAttribute('data-local'), curso: atalho.getAttribute('data-curso') || '' });
        var escola = alvo.closest('a[href*="escola.cursoscruzvermelha.org"]');
        if (escola) {
          var local = escola.getAttribute('data-saida') || 'outro';
          var caminho = '';
          try { caminho = new URL(escola.href).pathname; } catch (err) {}
          var destino = /^\\/cursos\\/./.test(caminho) ? 'curso' : (/^\\/cursos/.test(caminho) ? 'catalogo' : 'home');
          ga('saida_escola', { local: local, curso: foco || '', destino: destino, tela: 'matricula', transport_type: 'beacon' });
          try { if (window.fbq) window.fbq('trackCustom', 'SaidaEscola', { content_ids: [foco || ''], content_category: local }); } catch (err) {}
        }
      }, true);

      // Perguntas abertas (uma vez por pergunta): mostram quais dúvidas seguram a matrícula.
      var faqVistas = {};
      document.addEventListener('toggle', function (e) {
        var d = e.target;
        if (!d || d.tagName !== 'DETAILS' || !d.open) return;
        if (d.classList.contains('mr-curso-mais') || d.classList.contains('mr-garantia-mais')) return;
        var bloco = d.id === 'comparar' ? 'comparar' : d.closest('.mr-curso') ? 'curso' : d.closest('.mr-demanda') ? 'turmas' : d.closest('.mr-faq-pagina') ? 'pagina' : '';
        if (!bloco) return;
        var sum = d.querySelector('summary');
        var pergunta = sum ? sum.textContent.replace(/\\s+/g, ' ').trim().slice(0, 100) : '';
        if (faqVistas[bloco + '|' + pergunta]) return;
        faqVistas[bloco + '|' + pergunta] = true;
        var c = d.closest('.mr-curso');
        ga('faq_aberta', { bloco: bloco, pergunta: pergunta, curso: c ? c.getAttribute('data-curso') : (foco || '') });
      }, true);

      // --- visibilidade: lista vista, botão de matrícula visto, seções vistas, barra fixa e chat ----------------
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

      var secoesVistas = {};
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
        var secaoObs = new IntersectionObserver(function (ents) {
          ents.forEach(function (en) {
            var s = en.target.getAttribute('data-secao');
            if (!secoesVistas[s] && fracao(en) >= .4) { secoesVistas[s] = true; secaoObs.unobserve(en.target); ga('secao_vista', { secao: s, modo: modo }); }
          });
        }, { threshold: degraus });
        Array.prototype.forEach.call(document.querySelectorAll('[data-secao]'), function (el) { secaoObs.observe(el); });
      }

      // Barra fixa (celular): curso em foco, nenhum botão de matrícula na tela, abaixo do primeiro botão, fora da
      // seção de turmas e do rodapé, e sem o aviso de cookies aberto.
      var fimObs = 0;
      var barraVista = false;
      if (temIO) {
        var fim = new IntersectionObserver(function (ents) {
          ents.forEach(function (en) { if (en.isIntersecting !== !!en.target.__mrFim) { en.target.__mrFim = en.isIntersecting; fimObs += en.isIntersecting ? 1 : -1; } });
          avaliarBarra();
        });
        ['turmas-sob-demanda'].forEach(function (id) { var el = document.getElementById(id); if (el) fim.observe(el); });
        var rodape = document.querySelector('footer');
        if (rodape) fim.observe(rodape);
      }
      function passouPrimeiro() {
        var lista = ctas();
        for (var i = 0; i < lista.length; i++) {
          if (lista[i].offsetParent !== null) return lista[i].getBoundingClientRect().bottom < 0;
        }
        return false;
      }
      function avaliarBarra() {
        if (!barra || !temIO) return;
        var aviso = document.querySelector('.cvrj-ck');
        var mostrar = !!foco && celular.matches && naTela === 0 && fimObs === 0 && passouPrimeiro() && !(aviso && aviso.offsetParent !== null);
        if (mostrar === !barra.hidden) return;
        barra.hidden = !mostrar;
        raiz.classList.toggle('mr-barra-on', mostrar);
        if (mostrar && !barraVista) { barraVista = true; ga('barra_fixa_vista', { curso: foco }); }
      }
      var agendado = false;
      window.addEventListener('scroll', function () {
        if (agendado) return;
        agendado = true;
        requestAnimationFrame(function () { agendado = false; avaliarBarra(); });
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
      ctas().forEach(function (el) { if (ctaObs) { el.__mrNaTela = false; ctaObs.observe(el); } });
      var redim = null;
      window.addEventListener('resize', function () { clearTimeout(redim); redim = setTimeout(function () { montarChatObs(); avaliarBarra(); }, 200); });

      // Chegada: ?curso= (marcado no <head>) ou #curso-<slug> (abre os detalhes daquele curso).
      var inicial = raiz.getAttribute('data-curso');
      if (inicial) { focar(inicial); verCurso(inicial, 'url'); }
      else {
        var h = location.hash.match(/^#curso-([a-z0-9-]+)$/);
        if (h && cards[h[1]]) { abrirDetalhes(h[1], false); focar(h[1]); verCurso(h[1], 'url'); }
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
        if s not in ORDEM_EXIBICAO or s not in BENEFICIO or s not in COPY_CURSO:
            print(f"aviso: o curso {s} não está em ORDEM_EXIBICAO/BENEFICIO/COPY_CURSO; entra no fim, com a descrição cortada e sem a copy da ficha")
    grupo_de = {s: g["titulo"] for g in dados["grupos"] for s in g["cursos"]}

    def curto(s: str) -> str:
        return NOME_CURTO.get(s, cursos[s]["nome"])

    def beneficio(s: str) -> str:
        return BENEFICIO.get(s) or nome_filial(cursos[s]["descricao"])[:60]

    def checkout(s: str, via: str) -> str:
        return f"{CHECKOUT_URL}?curso={s}&amp;via={via}"

    def foto(s: str, largura: int, classe: str, loading: str = "lazy", sizes: str = "") -> str:
        img = cursos[s]["imagem"]
        if not (PASTA_IMG / f"{img}-960.webp").exists():
            return ""
        srcset = f'srcset="img/{img}-480.webp 480w, img/{img}-960.webp 960w" sizes="{sizes}" ' if sizes else ""
        return (f'<img class="{classe}" src="img/{img}-{largura}.webp" {srcset}alt="{esc(cursos[s]["nome"])} na Cruz Vermelha '
                f'Brasileira Rio de Janeiro" loading="{loading}" width="{largura}" height="{largura * 3 // 4}">')

    def preco(s: str) -> str:
        c = cursos[s]
        obs = "".join(f'<p class="mr-preco-obs">{esc(nome_filial(o))}</p>' for o in c["observacoes"])
        total = (f'<p class="mr-preco-total">Total do curso com a inscrição: {brl_curto(inscricao + c["valor_curso_centavos"])}</p>'
                 if MOSTRAR_TOTAL and c.get("valor_curso_centavos") else "")
        depois = (f'Valor do curso: {brl_curto(c["valor_curso_centavos"])}, pago depois na plataforma da escola, à vista ou '
                  'parcelado com juros.' if c.get("valor_curso_centavos") else "Valor do curso: informado pela escola.")
        return (f'<div class="mr-preco"><p class="mr-preco-agora"><span>Inscrição agora</span><b>{insc}</b>'
                f'<span>garante sua vaga</span></p><p class="mr-preco-depois">{depois}</p>{obs}{total}</div>')

    def tem_cert(s: str) -> bool:
        return (PASTA_IMG / f"certificado-{s}-640.webp").exists()

    def cert_img(s: str, loading: str, sizes: str) -> str:
        if not tem_cert(s):
            return ""
        return (f'<img class="mr-cert-img" src="img/certificado-{s}-640.webp" srcset="img/certificado-{s}-640.webp 640w, '
                f'img/certificado-{s}-1200.webp 1200w" sizes="{sizes}" alt="Modelo do certificado do curso de {esc(cursos[s]["nome"])} '
                f'da Cruz Vermelha Brasileira Rio de Janeiro, com o nome do aluno, o curso e a carga horária" loading="{loading}" '
                f'width="640" height="452" data-cert-curso="{s}">')

    micro = ('<p class="mr-micro">PIX ou cartão, à vista · sem criar conta · confirmação no seu e-mail<br>'
             '<i class="fa-solid fa-rotate-left"></i> 7 dias para desistir, com o valor de volta</p>')
    mini_passos = "".join(f"<li>{esc(p)}</li>" for p in MINI_PASSOS)

    # --- topo, modo geral -----------------------------------------------------------------------------
    passos_topo = "".join(f"<li>{esc(p)}</li>" for p in [f"Escolha o curso e pague a inscrição de {insc}", PASSO_2_TOPO,
                                                         "Você marca os dias e horários em que pode vir"])
    hero = f'''
    <section class="mr-hero" aria-labelledby="mr-titulo" data-secao="topo">
      <div class="wrap mr-hero-grid">
        <div class="mr-hero-texto">
          <p class="eyebrow">Escola de Educação e Saúde<span class="mr-so-largo"> · Cruz Vermelha Brasileira Rio de Janeiro</span></p>
          <h1 id="mr-titulo">Cursos presenciais com certificado da Cruz Vermelha, no Centro do Rio</h1>
          <div class="mr-hero-apoio">
            <p class="mr-hero-sub">Aulas na sede da Praça da Cruz Vermelha, 10. Para se matricular, você paga agora só a inscrição de {insc}, por PIX ou cartão, sem criar conta. O valor do curso é pago depois.</p>
            <ul class="mr-confianca-linha">
              <li><i class="fa-solid fa-certificate"></i> Certificado da Cruz Vermelha, reconhecida no Brasil e no mundo</li>
              <li class="mr-so-largo-flex"><i class="fa-solid fa-rotate-left"></i> 7 dias para desistir, com o valor de volta</li>
            </ul>
          </div>
        </div>
        <figure class="mr-hero-cert mr-hero-apoio">
          {cert_img(CERT_DESTAQUE, "lazy", "360px")}
          <figcaption><b>O seu certificado da Cruz Vermelha</b> <span>Imagem de modelo</span></figcaption>
        </figure>
      </div>
    </section>'''

    # --- fichas do modo curso (?curso=<slug>): sem id, fora do JSON-LD, escolhidas por CSS ---------------
    def aprende_html(s: str, titulo: str) -> str:
        cc = COPY_CURSO.get(s) or {}
        if not cc.get("aprende"):
            return ""
        itens = "".join(f'<li><i class="fa-solid fa-check"></i> {esc(a)}</li>' for a in cc["aprende"])
        para = f'<p class="mr-para-quem"><b>Para quem:</b> {esc(cc["para_quem"])}</p>' if cc.get("para_quem") else ""
        return f'<div class="mr-aprende"><p class="mr-aprende-titulo">{titulo}</p><ul>{itens}</ul>{para}</div>'

    def objecao(s: str) -> tuple[str, str] | None:
        o = (COPY_CURSO.get(s) or {}).get("objecao")
        if not o:
            return None
        valor = brl_curto(cursos[s]["valor_curso_centavos"]) if cursos[s].get("valor_curso_centavos") else "valor do curso"
        return tuple(x.replace("{insc}", insc).replace("{curso}", valor) for x in o)

    def ficha(s: str) -> str:
        c = cursos[s]
        cc = COPY_CURSO.get(s) or {}
        ob = objecao(s)
        objecao_html = (f'<div class="mr-objecao"><p class="mr-objecao-p">{esc(ob[0])}</p><p>{esc(ob[1])}</p></div>' if ob else "")
        return f'''
        <div class="mr-ficha" data-ficha="{s}">
          <figure class="mr-ficha-foto mr-ficha-cert">{cert_img(s, "lazy", "(max-width: 920px) 100vw, 480px")}<figcaption>O seu certificado de {esc(curto(s))} ao concluir (modelo)</figcaption></figure>
          <div class="mr-ficha-corpo">
            <p class="mr-ficha-chapeu">Curso presencial<span class="mr-so-largo"> · Cruz Vermelha Brasileira Rio de Janeiro</span></p>
            <p class="mr-ficha-titulo">{esc(cc.get("titulo") or f"Curso de {curto(s)} na Cruz Vermelha, no Centro do Rio")}</p>
            {f'<p class="mr-ficha-promessa">{esc(cc["promessa"])}</p>' if cc.get("promessa") else ""}
            <p class="mr-ficha-meta"><span><i class="fa-regular fa-clock"></i> {esc(c["carga_horaria"])}</span><span><i class="fa-solid fa-graduation-cap"></i> {esc(c["escolaridade"])}</span><span><i class="fa-solid fa-location-dot"></i> Praça da Cruz Vermelha, 10</span></p>
            {preco(s)}
            <a class="btn btn-red mr-cta" data-local="ficha" data-curso="{s}" href="{checkout(s, "ficha")}">Fazer matrícula · {insc}</a>
            {micro}
            <div class="mr-cert-curto">{cert_img(s, "lazy", "120px")}<p><b>Certificado da Cruz Vermelha</b>, reconhecida no Brasil e no mundo, com o seu nome, o curso e a carga horária.</p></div>
            <p class="mr-chat-atalho"><a href="#chat" data-abrir-chat data-assunto="matricula" data-curso="{s}" data-local="ficha">Dúvida antes de pagar? Pergunte no chat.</a></p>
            {aprende_html(s, "O que você aprende")}
            {objecao_html}
            <ol class="mr-mini-passos">{mini_passos}</ol>
            <p class="mr-ficha-links"><a href="#curso-{s}" data-abrir-detalhes="{s}">Detalhes, conteúdo e dúvidas deste curso</a> · <a href="#cursos">Ver os outros cursos</a></p>
          </div>
        </div>'''

    fichas = f'<div class="mr-fichas wrap" id="mr-fichas">{"".join(ficha(s) for s in exibicao)}</div>'

    # --- FAQ da Lei Lucas: o cursos.json vem vazio; a pergunta sai da FAQ da home ---------------------
    faq_home = json.loads(FAQ_HOME.read_text(encoding="utf-8")) if FAQ_HOME.exists() else {"grupos": []}
    lei_lucas = [q for g in faq_home.get("grupos", []) for q in g.get("perguntas", [])
                 if q.get("pergunta", "").startswith("O que é a Lei Lucas")]

    def faq_curso(s: str) -> list[tuple[str, str]]:
        itens = [(q["pergunta"], q["resposta"]) for q in (cursos[s].get("faq") or []) if q.get("pergunta") and q.get("resposta")]
        if not itens and s == "primeiros-socorros-lei-lucas":
            itens = [(q["pergunta"], q["resposta"]) for q in lei_lucas]
        return itens

    # --- cartões do catálogo ---------------------------------------------------------------------------
    def cartao(s: str, posicao: int) -> str:
        c = cursos[s]
        sobre_html = "".join(f"<p>{esc(nome_filial(p))}</p>" for p in c["sobre"])
        perguntas = "".join(f"<details><summary>{esc(nome_filial(p))}</summary><p>{esc(nome_filial(r))}</p></details>"
                            for p, r in faq_curso(s))
        faq_html = f'<div class="mr-faq"><h4>Dúvidas sobre {esc(curto(s))}</h4>{perguntas}</div>' if perguntas else ""
        valor = f'+ {brl_curto(c["valor_curso_centavos"])} do curso, depois' if c.get("valor_curso_centavos") else "+ valor do curso, depois"
        return f'''
          <article class="mr-curso mr-detalhe" id="curso-{s}" data-curso="{s}" data-nome="{esc(c["nome"])}" data-curto="{esc(curto(s))}"{' data-cert' if tem_cert(s) else ''}>
            <div class="mr-curso-topo">{foto(s, 480, "mr-curso-mini", "eager" if posicao < 2 else "lazy")}
              <div><h3 class="mr-curso-nome">{esc(c["nome"])}</h3><p class="mr-curso-meta">{esc(c["carga_horaria"])} · {esc(c["escolaridade"])}</p></div>
            </div>
            <p class="mr-curso-beneficio">{esc(beneficio(s))}</p>
            <div class="mr-curso-acao">
              <p class="mr-preco-curto"><strong>Inscrição {insc}</strong><span>{valor}</span></p>
              <a class="btn btn-red mr-cta" data-local="cartao" data-curso="{s}" href="{checkout(s, "cartao")}">Fazer matrícula</a>
            </div>
            <details class="mr-curso-mais" data-curso="{s}">
              <summary>Ver detalhes e dúvidas</summary>
              <div class="mr-curso-detalhes">
                {aprende_html(s, "O que você aprende")}
                <div class="mr-curso-sobre"><div class="mr-curso-foto-grande">{foto(s, 960, "mr-foto", "lazy", "(max-width: 720px) 100vw, 560px")}</div><h4>Sobre o curso</h4>{sobre_html}</div>
                {faq_html}
                <div class="mr-curso-fecho">
                  <div class="mr-cert-curto">{cert_img(s, "lazy", "120px")}<p><b>Ao concluir, você recebe este certificado</b> da Cruz Vermelha, com o seu nome (modelo).</p></div>
                  {preco(s)}
                  <a class="btn btn-red mr-cta" data-local="detalhes" data-curso="{s}" href="{checkout(s, "detalhes")}">Fazer matrícula · {insc}</a>
                  {micro}
                  <p class="mr-chat-atalho"><a href="#chat" data-abrir-chat data-assunto="matricula" data-curso="{s}" data-local="detalhes">Dúvidas sobre este curso? O chat responde na hora as perguntas mais comuns.</a></p>
                  <p class="mr-turma-linha">Tem um grupo de {TURMA_MINIMO} a {TURMA_MAXIMO} pessoas ou quer este curso em inglês? <a href="#turmas-sob-demanda" data-turma-abrir="curso" data-curso="{s}">Peça uma turma</a>.</p>
                </div>
              </div>
            </details>
          </article>'''

    def comparador() -> str:
        linhas = []
        for s in COMPARAR:
            if s not in cursos:
                continue
            c = cursos[s]
            publico = PUBLICO_COMPARAR.get(s) or (nome_filial(c["observacoes"][0]) if c["observacoes"] else beneficio(s))
            linhas.append(f'''
              <div class="mr-comparar-linha">
                <p class="mr-comparar-nome">{esc(c["nome"])}</p>
                <p>{esc(c["carga_horaria"])} · {esc(c["escolaridade"])} · curso {brl_curto(c["valor_curso_centavos"])}</p>
                <p>{esc(publico)}</p>
                <a class="btn btn-red mr-cta" data-local="comparar" data-curso="{s}" href="{checkout(s, "comparar")}">Fazer matrícula</a>
              </div>''')
        return (f'<details class="mr-comparar" id="comparar"><summary>Em dúvida entre os cursos de primeiros socorros? Compare</summary>'
                f'<div class="mr-comparar-grade">{"".join(linhas)}</div></details>')

    def texto_comparar() -> str:
        partes = []
        for s in COMPARAR:
            if s in cursos:
                c = cursos[s]
                publico = PUBLICO_COMPARAR.get(s) or (nome_filial(c["observacoes"][0]) if c["observacoes"] else beneficio(s))
                partes.append(f'{c["nome"]}: {c["carga_horaria"]}, curso {brl_curto(c["valor_curso_centavos"])}. {publico}')
        return " ".join(partes) + " Os três pedem Ensino Fundamental."

    # O comparador fecha o grupo "Emergência e vida" (depois dos cartões): antes deles, empurrava o primeiro botão
    # de matrícula para fora da primeira tela.
    blocos_catalogo = []
    titulo_atual = None
    for i, s in enumerate(exibicao):
        g = grupo_de.get(s, "Outros cursos")
        if g != titulo_atual:
            if titulo_atual == "Emergência e vida":
                blocos_catalogo.append(comparador())
            blocos_catalogo.append(f'<p class="mr-grupo-rotulo">{esc(g)}</p>')
            titulo_atual = g
        blocos_catalogo.append(cartao(s, i))
    if titulo_atual == "Emergência e vida":
        blocos_catalogo.append(comparador())
    catalogo = f'''
    <section class="mr-catalogo" id="cursos" aria-labelledby="mr-catalogo-titulo" data-secao="catalogo">
      <div class="wrap">
        <h2 id="mr-catalogo-titulo" class="mr-catalogo-titulo"><span class="mr-so-geral">Cursos presenciais e valores</span><span class="mr-so-curso">Outros cursos da Cruz Vermelha Brasileira Rio de Janeiro</span></h2>
        <div class="mr-cartoes">{"".join(blocos_catalogo)}</div>
        <p class="mr-turma-linha mr-turma-linha-catalogo">Empresas, escolas e grupos de {TURMA_MINIMO} pessoas ou mais, ou cursos em inglês: <a href="#turmas-sob-demanda" data-turma-abrir="catalogo">veja as turmas para grupos</a>.</p>
      </div>
    </section>'''

    # Chat de contato: a lista de cursos do chat.js segue este catálogo; as tags levam o hash do arquivo.
    # Vai junto a ficha e a FAQ de cada curso: o chat responde na hora, com o texto da escola.
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

    # --- depois da inscrição + garantia ------------------------------------------------------------------
    passos_html = "".join(f'<li><b>{i}</b><div><h3>{esc(t)}</h3><p>{esc(d)}</p></div></li>' for i, (t, d) in enumerate(PASSOS_DEPOIS, 1))
    depois_7 = f"<p>{esc(GARANTIA_DEPOIS)} Com a turma confirmada, a inscrição não é devolvida, salvo se a própria filial cancelar ou adiar a turma e você não puder participar em outra data.</p>" if GARANTIA_APOS_7_DIAS else ""
    depois = f'''
    <section class="mr-depois" id="depois-da-inscricao" aria-labelledby="mr-depois-titulo" data-secao="depois">
      <div class="wrap">
        <p class="eyebrow">Depois da inscrição</p>
        <h2 id="mr-depois-titulo">O que acontece depois que você paga</h2>
        <ol class="mr-passos">{passos_html}</ol>
        <div class="mr-cert" id="certificado">
          <figure class="mr-cert-figura">{cert_img(CERT_DESTAQUE, "lazy", "(max-width: 720px) 100vw, 520px")}</figure>
          <div class="mr-cert-texto">
            <p class="eyebrow">No fim do curso</p>
            <h3>O certificado da Cruz Vermelha, com o seu nome</h3>
            <ul>{"".join(f"<li><i class='fa-solid fa-circle-check'></i> {esc(t)}</li>" for t in CERT_ITENS)}</ul>
            <p class="mr-cert-nota">{esc(CERT_NOTA)}</p>
          </div>
        </div>
        <p class="mr-nota-curta">O valor do curso é pago depois, na plataforma da escola, nas condições informadas lá.</p>
        <div class="mr-garantia" id="garantia">
          <i class="fa-solid fa-rotate-left"></i>
          <div>
            <p class="mr-selo">7 dias para desistir · art. 49 do Código de Defesa do Consumidor</p>
            <h3>7 dias para desistir, com o valor de volta</h3>
            <p>{esc(GARANTIA_7_DIAS)}</p>
            <details class="mr-garantia-mais"><summary>Como pedir e regras completas</summary>{depois_7}<p>{esc(GARANTIA_COMO)}</p><p><a href="/reembolso/">Regras completas de cancelamento e reembolso</a></p></details>
          </div>
        </div>
      </div>
    </section>'''

    # --- confiança (só fatos de /historia/ e da FAQ da home) ------------------------------------------
    prova = ""
    if PROVA.get("numero"):
        prova += f'<p class="mr-prova-numero">{esc(PROVA["numero"])}</p>'
    confianca = f'''
    <section class="mr-confianca" aria-labelledby="mr-confianca-titulo" data-secao="confianca">
      <div class="wrap">
        <h2 id="mr-confianca-titulo">Quem dá o curso</h2>
        <p class="mr-confianca-texto">A Escola de Educação e Saúde é a escola da Cruz Vermelha Brasileira Rio de Janeiro. A Cruz Vermelha forma pessoas no Rio desde 20 de outubro de 1914, quando começou o primeiro curso, de Enfermeiras Voluntárias. As aulas são na sede da filial, o Palácio da Cruz Vermelha, no Centro, tombado como patrimônio cultural federal.</p>
        {prova}
        <ul class="mr-confianca-itens">
          <li><i class="fa-solid fa-certificate"></i> Certificado emitido pela Cruz Vermelha Brasileira Rio de Janeiro, com a carga horária do curso</li>
          <li><i class="fa-solid fa-location-dot"></i> Aulas no Palácio da Cruz Vermelha, Praça da Cruz Vermelha, 10, Centro</li>
          <li><i class="fa-solid fa-house"></i> Palácio tombado como patrimônio cultural federal</li>
          <li><i class="fa-solid fa-scale-balanced"></i> Utilidade pública municipal (Lei 5.153/2010) e estadual (Lei 9.984/2023)</li>
        </ul>
        <p class="mr-confianca-link"><a href="/historia/">Conheça a história da Cruz Vermelha no Rio</a></p>
      </div>
    </section>'''

    # --- perguntas frequentes ----------------------------------------------------------------------------
    faq_lista = []
    for p, r, r_html in FAQ_PAGINA:
        if r == "__COMPARAR__":
            r = texto_comparar()
            r_html = esc(r) + ' <a href="#comparar" data-abrir-comparar>Compare os três</a>.'
        faq_lista.append((p, r, r_html or esc(r)))
    faq_html = "".join(f"<details><summary>{esc(p)}</summary><p>{rh}</p></details>" for p, _, rh in faq_lista)
    faq_sec = f'''
    <section class="mr-faq-pagina" aria-labelledby="mr-faq-titulo" data-secao="faq">
      <div class="wrap">
        <p class="eyebrow">Dúvidas frequentes</p>
        <h2 id="mr-faq-titulo">Perguntas sobre a matrícula</h2>
        {faq_html}
        <p class="mr-chat-atalho mr-faq-chat"><a href="#chat" data-abrir-chat data-assunto="matricula" data-local="faq">Não achou sua dúvida? O chat no canto da página responde na hora as perguntas mais comuns.</a> O que ficar de fora, a equipe responde por e-mail em até 3 dias úteis.</p>
        <div class="mr-final" id="mr-final"><a class="mr-final-link" href="#cursos">Ver os cursos e valores</a></div>
      </div>
    </section>'''

    # --- turmas para grupos (faixa compacta, formulário recolhido) --------------------------------------
    opcoes_turma = '<option value="">Escolha o curso</option>'
    for g in dados["grupos"]:
        itens = "".join(f'<option value="{s}" data-catalogo="1" data-nome="{esc(cursos[s]["nome"])}">{esc(cursos[s]["nome"])}</option>'
                        for s in g["cursos"] if s in cursos)
        opcoes_turma += f'<optgroup label="{esc(g["titulo"])}">{itens}</optgroup>'
    extras = "".join(f'<option value="{s}" data-catalogo="0" data-nome="{esc(n)}">{esc(n)}</option>' for s, n in TURMA_EXTRAS.items())
    opcoes_turma += f'<optgroup label="Só sob demanda">{extras}</optgroup>'
    faq_turmas = "".join(f"<details><summary>{esc(p)}</summary><p>{esc(r)}</p></details>" for p, r in FAQ_TURMAS)
    turmas = f'''
    <section class="mr-demanda" id="turmas-sob-demanda" aria-labelledby="mr-demanda-titulo" data-secao="turmas">
      <div class="wrap">
        <div class="mr-demanda-grade">
          <div>
            <p class="eyebrow">Turmas para grupos</p>
            <h2 id="mr-demanda-titulo">Tem um grupo de {TURMA_MINIMO} pessoas ou mais? Fechamos uma turma só para vocês</h2>
            <p class="mr-demanda-abertura">Os cursos do catálogo, em português, têm matrícula individual: é só usar o botão do curso. Esta parte é para quem quer uma turma só do seu grupo, um curso em inglês ou primeiros socorros para jovens de 12 a 14 anos.</p>
            <ul class="mr-demanda-itens">
              <li><i class="fa-solid fa-people-group"></i> Empresas, escolas, igrejas e condomínios: turma só do grupo, de {TURMA_MINIMO} a {TURMA_MAXIMO} alunos, com o mesmo valor por pessoa, na sede (em outro local, com aprovação).</li>
              <li><i class="fa-solid fa-flag"></i> Qualquer curso em inglês, com professor ou tradutor · <span lang="en">Courses in English</span>. A turma abre com {TURMA_MINIMO} alunos.</li>
              <li><i class="fa-solid fa-heart-pulse"></i> Primeiros socorros para jovens de 12 a 14 anos, numa turma só dessa idade, que abre com {TURMA_MINIMO} alunos.</li>
            </ul>
            <button class="btn btn-outline mr-demanda-botao" type="button" data-turma-abrir="faixa" aria-expanded="false" aria-controls="turma-form-bloco">Pedir turma para grupo</button>
            <p class="mr-tf-dica">Nada é cobrado agora. A secretaria responde em até 3 dias úteis.</p>
          </div>
          <div class="mr-demanda-faq">{faq_turmas}</div>
        </div>
        <noscript><style>#turma-form-bloco{{display:block!important}}</style></noscript>
        <div class="mr-demanda-form" id="turma-form-bloco" hidden>
          <form id="turma-form" novalidate>
            <h3>Peça sua turma ou entre na lista</h3>
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
                  <label><input type="radio" name="idioma" value="en"> Inglês <span lang="en">(English)</span></label>
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
        </div>
      </div>
    </section>'''

    barra = f'''
  <div class="mr-barra" id="mr-barra" hidden>
    <div class="mr-barra-texto"><b id="mr-barra-nome"></b><span>Inscrição {insc} · 7 dias para desistir</span></div>
    <a class="btn btn-red mr-cta" id="mr-barra-cta" data-local="barra" href="{CHECKOUT_URL}">Fazer matrícula</a>
  </div>'''

    # --- dados estruturados (ItemList e BreadcrumbList como antes; FAQPage = página + turmas) -------------
    provedor = {"@type": "EducationalOrganization", "@id": f"{ESCOLA}/#escola",
                "name": "Escola de Educação e Saúde CVB-RJ", "url": f"{ESCOLA}/",
                "parentOrganization": {"@id": f"{ORIGEM}/#organizacao"}}
    itens = []
    for i, s in enumerate(ordem, 1):
        c = cursos[s]
        curso = {"@type": "Course", "name": c["nome"], "description": nome_filial(c["descricao"] or (c["sobre"][0] if c["sobre"] else "")),
                 "url": f"{URL_PAGINA}#curso-{s}", "provider": provedor,
                 "educationalCredentialAwarded": "Certificado da Cruz Vermelha Brasileira Rio de Janeiro"}
        if (PASTA_IMG / f"{c['imagem']}-960.webp").exists():
            curso["image"] = f"{URL_PAGINA}img/{c['imagem']}-960.webp"
        ofertas = [{"@type": "Offer", "category": "Paid", "name": "Inscrição", "price": f"{inscricao / 100:.2f}",
                    "priceCurrency": "BRL", "url": f"{URL_PAGINA}#curso-{s}", "availability": "https://schema.org/InStock"}]
        if c["valor_curso_centavos"]:
            ofertas.append({"@type": "Offer", "category": "Paid", "name": "Valor do curso (pago depois, na plataforma da escola)",
                            "price": f"{c['valor_curso_centavos'] / 100:.2f}", "priceCurrency": "BRL"})
        curso["offers"] = ofertas
        # courseMode é propriedade de CourseInstance, não de Course: no Course o validador recusa.
        instancia = {"@type": "CourseInstance", "courseMode": "Onsite", "location": LOCAL}
        if horas_iso(c["carga_horaria"]):
            curso["timeRequired"] = horas_iso(c["carga_horaria"])
            instancia["courseWorkload"] = horas_iso(c["carga_horaria"])
        curso["hasCourseInstance"] = [instancia]
        itens.append({"@type": "ListItem", "position": i, "item": curso})
    perguntas_ld = [(p, r) for p, r, _ in faq_lista] + FAQ_TURMAS
    ld = [
        {"@context": "https://schema.org", "@type": "BreadcrumbList", "itemListElement": [
            {"@type": "ListItem", "position": 1, "name": "Início", "item": f"{ORIGEM}/"},
            {"@type": "ListItem", "position": 2, "name": "Matrícula cursos presenciais", "item": URL_PAGINA}]},
        {"@context": "https://schema.org", "@type": "ItemList", "name": "Matrícula em cursos presenciais da Cruz Vermelha Brasileira Rio de Janeiro",
                 "numberOfItems": len(dados["cursos"]),
         "url": URL_PAGINA, "itemListElement": itens},
        {"@context": "https://schema.org", "@type": "FAQPage", "mainEntity": [
            {"@type": "Question", "name": p, "acceptedAnswer": {"@type": "Answer", "text": r}} for p, r in perguntas_ld]},
    ]
    ld_html = "".join(f'\n  <script type="application/ld+json">\n{json.dumps(d, ensure_ascii=False, indent=2)}\n  </script>' for d in ld)
    for d in ld:
        assert "</" not in json.dumps(d, ensure_ascii=False)

    # --- CSS: modo curso por slug (antes da primeira pintura) + o da página --------------------------------
    css_fichas = "".join(f'html[data-curso="{s}"] .mr-ficha[data-ficha="{s}"]{{display:grid}}' for s in exibicao)
    modo_curso_js = ("(function(){try{var q=new URLSearchParams(location.search),c=q.get('curso');"
                     f"if(c&&!q.get('turma')&&{json.dumps(exibicao)}.indexOf(c)>=0)document.documentElement.setAttribute('data-curso',c);"
                     "}catch(e){}})();")
    css = (CSS_PAGINA.replace("/*__FICHAS__*/", css_fichas))
    js = (JS_PAGINA.replace("__CHECKOUT__", json.dumps(CHECKOUT_URL)).replace("__INSCRICAO__", f"{inscricao / 100:.2f}")
          .replace("__INSC_CURTO__", json.dumps(insc)).replace("__EXIBICAO__", json.dumps(exibicao)))

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
  <meta property="og:image:width" content="{IMAGEM_OG_TAMANHO[0]}">
  <meta property="og:image:height" content="{IMAGEM_OG_TAMANHO[1]}">
  <meta property="og:image:alt" content="Cursos presenciais da Cruz Vermelha Brasileira no Rio de Janeiro">
  <meta name="twitter:card" content="summary_large_image">
  <script>{modo_curso_js}</script>
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
{hero}
    {fichas}
{catalogo}
{depois}
{confianca}
{faq_sec}
{turmas}
  </main>
{barra}

{footer}

{menu_js}
{js}
  <script src="{url_estatico("turmas.js")}" defer></script>
{chat_tags}
</body>
</html>
"""
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
