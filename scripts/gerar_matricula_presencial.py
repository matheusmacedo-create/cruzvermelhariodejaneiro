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

Porte visual do modelo B (05/10/2026): Manrope, paleta --vermelho/--escuro, cartões com capa e véu, faixa vermelha
dos passos, chamada final escura, ficha com rodapé fixo. Só a camada visual mudou (CSS_PAGINA, hero_html() e o HTML
montado em main()); textos, JS_PAGINA, ganchos do DOM, checkout, JSON-LD, consentimento e chat são os mesmos. O banner
é o banner-6-branco, escolhido pelo dono; a troca fica restrita a hero_html() e ao bloco /* Hero */ do CSS.

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
                    "regulamentada. A escola que quer treinar a equipe inteira pode pedir uma turma fechada, de 15 a 30 pessoas, em "
                    "“Solicitar uma turma”, nesta página."),
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
     "criar conta antes de pagar. " + RESPOSTA_QUANDO, None),
    ("Preciso criar conta?", RESPOSTA_CONTA, None),
    ("Posso pagar com cartão? Posso parcelar?",
     "A inscrição de R$ 99 é paga à vista, por PIX ou cartão. O valor do curso é pago na plataforma da escola, à vista ou "
     "parcelado com juros, nas condições informadas lá.", None),
    ("Quando pago o restante do curso?",
     "Depois da inscrição e antes da primeira aula, na plataforma da escola, à vista ou parcelado com juros: a entrada na aula "
     "é liberada com a matrícula paga. Os valores de cada curso estão nos cartões desta página.", None),
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
     "Se não houver turma com horário compatível para você, a inscrição é devolvida por inteiro. As regras completas, com os "
     "prazos, estão na página de cancelamento e reembolso.",
     'Se não houver turma com horário compatível para você, a inscrição é devolvida por inteiro. As regras completas, com os '
     'prazos, estão na <a href="/reembolso/">página de cancelamento e reembolso</a>.'),
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
     "valor por pessoa dos cursos. As aulas são na sede; em outro local, dependem de aprovação. Peça em “Solicitar uma "
     "turma”: a secretaria responde em até 3 dias úteis."),
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
# Frases do catálogo da escola que prometem emprego, renda ou mercado ficam fora da página e do chat (regra da página:
# prometer só o que o sistema cumpre). A escola foi avisada para rever o texto na origem.
EXCLUIR_CATALOGO = re.compile(r"emprego|oportunidade|mercado de trabalho|retorno financeiro|renda|iniciar seus atendimentos", re.I)
# Materiais necessários por curso: a escola ainda não informou. "Informação em breve" até lá.
MATERIAIS: dict[str, str] = {}
# Fotos reais da sede e das aulas (servidas de /assets/otim, fora do Git; só o servidor as tem). As legendas dizem o que
# a foto mostra, sem citar curso quando a imagem não o mostra. Arquivos novos de 05/10 (aula-*.webp) ainda não estavam no
# servidor quando o porte foi feito: publicar junto com a página.
FOTO_TOPO = {"base": "aula-engasgo-bebe-instrutor", "larguras": [480, 589], "largura": 589, "altura": 1280,
             "alt": "Instrutor da Cruz Vermelha orientando alunos na prática de desengasgo em bebê, com manequins",
             "legenda": "<b>Aula prática</b> · desengasgo em bebê"}
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
# Capa real de três cursos (as outras continuam com img/<slug>-*.webp, de gerar_imagens_matricula.py). O JSON-LD
# segue apontando para img/: metadados não mudam com o porte visual.
CAPA_CURSO = {
    "primeiros-socorros-basico": {"base": "aula-manobra-heimlich", "larguras": [480, 899], "largura": 480, "altura": 854,
                                  "pos": "center 35%", "alt": "Instrutor demonstrando a manobra de Heimlich em uma aluna durante a aula"},
    "primeiros-socorros-lei-lucas": {"base": "aula-engasgo-bebe", "larguras": [480, 589], "largura": 480, "altura": 1043,
                                     "pos": "center 60%", "alt": "Alunos praticando a manobra de desengasgo em manequins de bebê durante a aula"},
    "suporte-basico-de-vida": {"base": "aula-dea-sala", "larguras": [480, 591], "largura": 480, "altura": 1040,
                               "pos": "center 48%", "alt": "Instrutor da Cruz Vermelha em sala de aula, com manequins e um DEA de treino sobre a mesa"},
}
# Foto do cartão "Turma para empresas e grupos" (recorte alto: height 150%, topo).
FOTO_GRUPOS = {"src": "/assets/otim/hero-equipe-grupo-960.webp", "srcset": "/assets/otim/hero-equipe-grupo-960.webp 960w",
               "alt": "Equipe de voluntários reunida no auditório da sede", "largura": 960, "altura": 723}
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
    ("Garanta sua vaga", "Pague a inscrição de R$ 99 por PIX ou cartão, sem criar conta antes de pagar."),
    ("Complete sua matrícula", "Com o pagamento confirmado, você recebe o comprovante por e-mail e, se já houver turma aberta, a "
                               "data de início. Horários, local e as orientações da turma chegam pela plataforma da escola e "
                               "por e-mail antes da primeira aula. O valor do curso é pago lá."),
    ("Venha para a aula", "Com o valor do curso pago, compareça na data da sua turma, na Praça da Cruz Vermelha, 10: a entrada "
                          "na aula é liberada com a matrícula paga."),
]

# --- CSS da página (porte do modelo B, 05/10/2026) -------------------------------------------------------
# A página copia o CSS da home (Inter, botões redondos, .wrap de 1100 px) para o cabeçalho e o rodapé ficarem iguais
# ao resto do site. Tudo o que é desta página fica sob a classe .mr (o <main>, a navegação fixa, a janela dos
# detalhes e a barra do celular): Manrope, botões de canto 6 px, .wrap de 1240 px, paleta --vermelho/--escuro.
# Celular primeiro: as regras base são as do celular; tablet em 640 px, computador em 720 px (o JS da barra fixa
# usa 719 px), passos em 1024 px e a grade de 3 colunas em 1200 px.
# O banner principal fica entre /* Hero */ e /* /Hero */ (inclusive as regras responsivas), e o HTML dele em
# hero_html(): trocar o banner é mexer só nesses dois lugares.
CSS_PAGINA = """
  <style>
    :root { --vermelho: #cc0000; --vermelho-escuro: #a30000; --cinza: #f5f6f8; --texto: #0f1318; --texto-2: #2d3748; --borda: #e1e5ea; --borda-forte: #c6cdd5; --escuro: #0f1318; --verde: #0f7b3e; --fonte-mr: Manrope, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
    .mr { font-family: var(--fonte-mr); color: var(--texto); line-height: 1.5; font-size: 16px; }
    .mr .wrap { width: min(1176px, calc(100% - 32px)); margin: 0 auto; }
    .mr section { padding: 36px 0; }
    .mr h1, .mr h2, .mr h3 { margin: 0; font-weight: 800; line-height: 1.15; letter-spacing: -.015em; color: var(--texto); }
    .mr h2 { font-size: clamp(26px, 3.4vw, 40px); }
    .mr p { margin: 0; }
    .mr ul, .mr ol { margin: 0; padding: 0; }
    .mr img { max-width: 100%; height: auto; display: block; }
    .mr a { color: var(--vermelho); }
    .mr :focus-visible { outline: 3px solid var(--texto); outline-offset: 3px; }
    .mr-hero :focus-visible, .mr-como :focus-visible, .mr-card-turma :focus-visible, .mr-final :focus-visible { outline-color: #fff; }
    .mr-cinza { background: var(--cinza); }
    .mr-cab { max-width: 760px; margin-bottom: 18px; }
    .mr-cab p { margin-top: 10px; font-size: 16px; color: var(--texto-2); }
    .mr-escondido { position: absolute; width: 1px; height: 1px; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); clip-path: inset(50%); white-space: nowrap; }
    .mr-micro { font-size: 14px; color: var(--texto-2); line-height: 1.5; }
    .mr-micro svg { width: 18px; height: 18px; vertical-align: -4px; color: var(--vermelho); margin-right: 4px; }
    .mr .eyebrow { display: inline-block; font-size: 12px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: #fff; background: var(--vermelho); padding: 7px 11px; border-radius: 4px; }

    /* botões: os mesmos nomes da home (.btn-red, .btn-outline), com a forma do modelo B */
    .mr .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 48px; padding: 12px 22px; border-radius: 6px; font-weight: 800; font-size: 16px; text-decoration: none; border: 2px solid transparent; cursor: pointer; font-family: inherit; line-height: 1.2; text-align: center; box-shadow: none; transition: background-color .15s, border-color .15s, color .15s; }
    .mr .btn:hover { transform: none; }
    .mr .btn svg { width: 18px; height: 18px; flex: none; }
    .mr .btn-red { background: var(--vermelho); color: #fff; border-color: var(--vermelho); box-shadow: none; }
    .mr .btn-red:hover { background: var(--vermelho-escuro); border-color: var(--vermelho-escuro); }
    .mr .btn-branco { background: #fff; color: var(--vermelho); border-color: #fff; }
    .mr .btn-branco:hover { background: var(--cinza); border-color: var(--cinza); color: var(--vermelho); }
    .mr .btn-outline { background: #fff; color: var(--texto); border-color: var(--borda-forte); }
    .mr .btn-outline:hover { border-color: var(--texto); color: var(--texto); }
    .mr .btn-contorno-branco { background: transparent; color: #fff; border-color: rgba(255, 255, 255, .75); }
    .mr .btn-contorno-branco:hover { border-color: #fff; background: rgba(255, 255, 255, .08); color: #fff; }

    /* navegação da página: aparece depois do topo, só no computador */
    .mr-nav { position: fixed; left: 0; right: 0; top: var(--mr-cabecalho, 84px); z-index: 40; background: #fff; border-bottom: 1px solid var(--borda); transform: translateY(-110%); visibility: hidden; transition: transform .25s, visibility 0s .25s; display: none; }
    .mr-nav.visivel { transform: none; visibility: visible; transition: transform .25s; }
    .mr section[id], .mr-card, .mr-det { scroll-margin-top: calc(var(--mr-cabecalho, 84px) + 16px); }
    .mr-nav .wrap { display: flex; align-items: center; gap: 28px; min-height: 60px; }
    .mr-nav ul { list-style: none; display: flex; gap: 28px; }
    .mr-nav ul a { color: var(--texto); text-decoration: none; font-weight: 700; font-size: 15px; display: inline-flex; align-items: center; min-height: 44px; padding: 0 2px; border-bottom: 2px solid transparent; }
    .mr-nav ul a.ativo, .mr-nav ul a:hover { border-bottom-color: var(--vermelho); }
    .mr-nav .btn { margin-left: auto; min-height: 44px; padding: 10px 18px; font-size: 15px; }

    /* Hero */
    /* Banner escolhido pelo dono (banner-6-branco): fundo branco, foto vertical ao lado, painel de preço cinza com
       borda vermelha. Tudo do topo, inclusive o modo curso e as regras responsivas, fica neste bloco. */
    .mr-hero { background: #fff; color: var(--texto); padding: 0 !important; }
    .mr-hero .wrap { padding-top: 28px; padding-bottom: 36px; display: grid; gap: 22px; }
    .mr-hero h1 { font-size: clamp(32px, 5vw, 54px); color: var(--texto); margin: 14px 0 12px; letter-spacing: -.02em; line-height: 1.1; }
    .mr-hero-frase { font-size: clamp(17px, 1.5vw, 20px); color: var(--texto-2); line-height: 1.45; max-width: 640px; }
    .mr-hero-acoes { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 22px; }
    .mr-hero-endereco { display: flex; gap: 8px; align-items: flex-start; margin: 18px 0 0; font-size: 15px; color: var(--texto-2); font-weight: 600; }
    .mr-hero-endereco svg { width: 20px; height: 20px; flex: none; margin-top: 2px; color: var(--vermelho); }
    .mr-hero-painel { background: var(--cinza); border: 1px solid var(--borda); border-top: 4px solid var(--vermelho); border-radius: 12px; padding: 20px; }
    .mr-hero-painel strong { display: block; font-size: 26px; letter-spacing: -.01em; color: var(--texto); line-height: 1.15; }
    .mr-hero-painel strong small { display: block; font-size: 14px; font-weight: 700; color: var(--texto-2); margin-top: 4px; }
    .mr-hero-painel ul { list-style: none; display: grid; gap: 10px; margin-top: 14px; }
    .mr-hero-painel li { display: flex; gap: 10px; align-items: flex-start; font-size: 15px; color: var(--texto); font-weight: 600; line-height: 1.4; }
    .mr-hero-painel li svg { width: 20px; height: 20px; flex: none; color: var(--vermelho); margin-top: 1px; }
    .mr-hero-painel .btn { width: 100%; margin-top: 16px; }
    .mr-hero-midia { position: relative; margin: 0; }
    .mr-hero-foto { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; object-position: center 58%; display: block; border-radius: 14px; box-shadow: 0 18px 44px rgba(15, 19, 24, .16); background: var(--cinza); }
    .mr-hero-legenda { position: absolute; left: 16px; bottom: 16px; display: inline-flex; align-items: center; gap: 8px; background: rgba(15, 19, 24, .82); color: #fff; font-size: 13px; font-weight: 700; padding: 8px 12px; border-radius: 6px; max-width: calc(100% - 32px); margin: 0; }
    .mr-hero-legenda svg { width: 16px; height: 16px; flex: none; color: var(--vermelho); }
    .mr-hero-legenda b { color: #fff; font-weight: 800; }
    /* modo curso (?curso=): o topo encolhe a uma linha e a ficha do curso aparece logo abaixo */
    html[data-curso] .mr-hero .wrap { display: block; padding: 12px 0 8px; }
    html[data-curso] .mr-hero .eyebrow, html[data-curso] .mr-hero-frase, html[data-curso] .mr-hero-acoes, html[data-curso] .mr-hero-endereco,
    html[data-curso] .mr-hero-painel, html[data-curso] .mr-hero-midia { display: none; }
    html[data-curso] .mr-hero h1 { font-size: 15px; font-weight: 700; color: var(--texto-2); letter-spacing: 0; line-height: 1.35; margin: 0; }
    @media (min-width: 720px) {
      html[data-curso] .mr-hero .wrap { padding: 16px 0 6px; }
    }
    @media (min-width: 768px) {
      .mr-hero .wrap { padding-top: 44px; padding-bottom: 52px; grid-template-columns: 1fr 1fr; align-items: center; gap: 32px; }
      .mr-hero-conteudo { grid-column: 1; grid-row: 1; }
      .mr-hero-painel { grid-column: 1; grid-row: 2; padding: 24px 28px; }
      .mr-hero-midia { grid-row: 1 / 3; grid-column: 2; }
      .mr-hero-foto { aspect-ratio: 4 / 5; }
    }
    @media (min-width: 1024px) {
      .mr-hero .wrap { grid-template-columns: 1.1fr .9fr; gap: 56px; padding-top: 56px; padding-bottom: 64px; }
    }
    /* /Hero */

    /* faixa de marcas (logo abaixo do topo) */
    .mr-marcas { background: #fff; border-top: 1px solid var(--borda); border-bottom: 1px solid var(--borda); }
    .mr-marcas ul { list-style: none; padding: 16px 0; display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
    .mr-marcas li { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 15px; color: var(--texto); }
    .mr-marcas svg { width: 30px; height: 30px; color: var(--vermelho); flex: none; }
    html[data-curso] .mr-marcas { display: none; }

    /* ficha / detalhes de cada curso: escondida na página; aparece na janela, no modo curso ou por #det-<slug> */
    .mr-dets { padding: 0 !important; }
    .mr-det { display: none; padding: 0 !important; }
    html:not(.js) .mr-det:target { display: block; }
    .mr-janela .mr-det { display: block; }
    html[data-curso] .mr-dets { padding: 0 0 8px !important; }
    html[data-curso] .mr-det-foco { background: #fff; border: 1px solid var(--borda); border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(15, 19, 24, .08); }
    .mr-det-capa { position: relative; aspect-ratio: 16 / 9; max-height: 360px; background: var(--escuro); overflow: hidden; margin: 0; }
    .mr-det-capa img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .mr-det-veu { position: absolute; left: 0; right: 0; bottom: 0; padding: 16px 20px; background: rgba(15, 19, 24, .8); color: #fff; }
    .mr-det-veu .mr-capa-tag { position: static; display: inline-block; margin-bottom: 8px; }
    .mr-det-veu h3 { color: #fff; font-size: clamp(20px, 2.6vw, 28px); }
    .mr-det-corpo { padding: 20px 20px 24px; display: grid; gap: 22px; }
    .mr-det-promessa { font-size: 18px; font-weight: 700; line-height: 1.45; }
    .mr-det-oferta { border: 1px solid var(--borda); border-radius: 8px; padding: 14px 16px; display: grid; gap: 12px; }
    .mr-det-oferta .mr-preco { border-top: 0; padding-top: 0; }
    .mr-det-oferta .btn { width: 100%; }
    .mr-det-grade { list-style: none; display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .mr-det-grade li { display: flex; flex-direction: column; gap: 2px; background: var(--cinza); border-radius: 8px; padding: 12px 14px; font-size: 15px; }
    .mr-det-grade small { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color: var(--texto-2); font-weight: 800; }
    .mr-det-grade b { font-weight: 700; color: var(--texto); }
    .mr-det-corpo h4 { font-size: 17px; margin: 0 0 8px; color: var(--texto); font-weight: 800; }
    .mr-det-bloco p { color: var(--texto-2); line-height: 1.6; }
    .mr-det-bloco p + p { margin-top: 10px; }
    .mr-det-lista { list-style: none; display: grid; gap: 8px; }
    .mr-det-lista li { display: flex; gap: 10px; align-items: flex-start; color: var(--texto-2); line-height: 1.5; }
    .mr-det-lista li i { flex: none; width: 18px; height: 18px; border-radius: 50%; background: var(--vermelho); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; margin-top: 3px; }
    .mr-det-cert { display: grid; gap: 14px; }
    .mr-det-cert img { border: 1px solid var(--borda); border-radius: 8px; width: 100%; max-width: 360px; }
    .mr-det-cert a { color: var(--vermelho); font-weight: 700; }
    .mr-det-faq details { border-bottom: 1px solid var(--borda); }
    .mr-det-faq details:first-of-type { border-top: 1px solid var(--borda); }
    .mr-det-fim { border-top: 1px solid var(--borda); padding-top: 18px; display: grid; gap: 8px; font-size: 15px; color: var(--texto-2); }
    .mr-chat-atalho a, .mr-turma-linha a, .mr-faq-chat a { color: var(--vermelho); font-weight: 700; }
    .mr-det-rodape { position: sticky; bottom: 0; background: #fff; border-top: 1px solid var(--borda); padding: 12px 20px calc(12px + env(safe-area-inset-bottom)); display: flex; justify-content: space-between; align-items: center; gap: 12px; }
    .mr-det-rodape .btn { white-space: nowrap; flex: none; }
    .mr-det-rodape-preco { display: flex; flex-direction: column; line-height: 1.25; min-width: 0; }
    .mr-det-rodape-preco strong { color: var(--vermelho); font-size: 16px; }
    .mr-det-rodape-preco span { font-size: 12px; color: var(--texto-2); font-weight: 700; }
    html[data-curso] .mr-det-rodape { position: static; }
    /* janela dos detalhes (dialog) */
    .mr-janela { border: 0; padding: 0; width: min(100%, 760px); max-width: 100%; height: 100vh; height: 100dvh; max-height: 100dvh; margin: 0; border-radius: 0; background: #fff; color: var(--texto); overflow: auto; overscroll-behavior: contain; }
    .mr-janela::backdrop { background: rgba(15, 19, 24, .72); }
    .mr-janela-fechar { position: sticky; top: 12px; float: right; margin: 12px 12px 0 0; width: 44px; height: 44px; border-radius: 50%; background: #fff; border: 0; color: var(--texto); font-size: 26px; line-height: 1; cursor: pointer; display: grid; place-items: center; font-family: inherit; z-index: 2; box-shadow: 0 2px 8px rgba(15, 19, 24, .25); }
    .mr-janela-fechar:hover, .mr-janela-fechar:focus-visible { background: var(--cinza); }
    html.mr-modal-aberto { overflow: hidden; }

    /* categorias e cursos (um bloco cinza só) */
    .mr-filtro { padding: 36px 0 0 !important; }
    .mr-cursos { padding: 0 0 40px !important; }
    .mr-chips { display: flex; gap: 8px; overflow-x: auto; padding: 4px 16px 14px; margin: 0 -16px; scrollbar-width: none; -webkit-overflow-scrolling: touch; }
    .mr-chips::-webkit-scrollbar { display: none; }
    .mr-chip { flex: none; white-space: nowrap; min-height: 44px; padding: 10px 18px; border-radius: 999px; border: 2px solid var(--borda-forte); background: #fff; font-weight: 700; font-size: 15px; color: var(--texto-2); cursor: pointer; font-family: inherit; transition: background-color .15s, border-color .15s, color .15s; }
    .mr-chip:hover { border-color: var(--texto); }
    .mr-chip[aria-pressed="true"] { background: var(--vermelho); color: #fff; border-color: var(--vermelho); }
    .mr-grade { display: grid; gap: 16px; grid-template-columns: 1fr; margin-top: 6px; }
    .mr-card, .mr-card-turma { background: #fff; border: 1px solid var(--borda); border-radius: 10px; overflow: hidden; display: flex; flex-direction: column; transition: box-shadow .2s; }
    .mr-card.ativo { border-color: var(--vermelho); box-shadow: 0 0 0 2px var(--vermelho); }
    .mr-card.oculto, .mr-card-turma.oculto { display: none; }
    .mr-capa { position: relative; aspect-ratio: 16 / 9; max-height: 160px; background: var(--escuro); overflow: hidden; margin: 0; }
    .mr-capa img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .mr-capa-tag { position: absolute; top: 12px; left: 12px; background: var(--vermelho); color: #fff; font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; padding: 5px 9px; border-radius: 4px; line-height: 1.2; }
    .mr-badge { position: absolute; right: 12px; top: 12px; background: #fff; color: #0f5132; font-size: 12px; font-weight: 800; padding: 5px 9px; border-radius: 4px; }
    .mr-badge.esgotado { color: #9b1c1c; }
    .mr-badge.breve, .mr-badge.formacao { color: #7c4a03; }
    .mr-veu { position: absolute; left: 0; right: 0; bottom: 0; padding: 12px 16px; background: rgba(15, 19, 24, .78); color: #fff; }
    .mr-veu h3 { font-size: 20px; color: #fff; line-height: 1.2; }
    .mr-veu p { margin-top: 4px; font-size: 14px; color: #fff; font-weight: 600; }
    .mr-card-corpo { padding: 12px; display: flex; flex-direction: column; gap: 8px; flex: 1; }
    .mr-meta { display: flex; flex-wrap: wrap; gap: 6px 16px; list-style: none; font-size: 14px; color: var(--texto-2); font-weight: 700; }
    .mr-meta li { display: inline-flex; align-items: center; gap: 6px; }
    .mr-meta svg { width: 17px; height: 17px; color: var(--vermelho); flex: none; }
    .mr-preco { display: grid; gap: 6px; border-top: 1px solid var(--borda); padding-top: 10px; }
    .mr-preco-l { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; }
    .mr-preco-l strong { font-size: 17px; font-weight: 800; white-space: nowrap; color: var(--texto); }
    .mr-preco-l span { font-size: 13px; color: var(--texto-2); font-weight: 700; text-align: right; }
    .mr-preco-l.agora strong { color: var(--vermelho); }
    .mr-card-acoes { display: grid; grid-template-columns: 1fr auto; gap: 10px; margin-top: auto; }
    .mr-card-acoes .btn { padding-left: 16px; padding-right: 16px; }
    .mr-card-turma { background: var(--escuro); color: #fff; border-color: var(--escuro); }
    .mr-card-turma .mr-capa img { height: 150%; object-position: center top; }
    .mr-card-turma .mr-card-corpo { padding: 18px 20px 22px; gap: 10px; justify-content: center; }
    .mr-card-turma h3 { font-size: 22px; color: #fff; line-height: 1.2; }
    .mr-card-turma p { color: rgba(255, 255, 255, .92); font-size: 15px; line-height: 1.5; }
    .mr-card-turma ul { display: flex; flex-wrap: wrap; gap: 6px; list-style: none; }
    .mr-card-turma li { font-size: 13px; font-weight: 700; padding: 5px 10px; border: 1px solid rgba(255, 255, 255, .3); border-radius: 999px; color: #fff; }
    .mr-card-turma .btn { margin-top: 8px; align-self: flex-start; }
    .mr-nota-preco { margin-top: 16px; font-size: 14px; color: var(--texto-2); display: flex; gap: 8px; align-items: flex-start; }
    .mr-nota-preco svg { width: 18px; height: 18px; flex: none; color: var(--vermelho); margin-top: 2px; }
    .mr-vazio { color: var(--texto-2); text-align: center; padding: 24px 0; }

    /* fotos: rolagem lateral no celular, grade de 4 no computador */
    .mr-fotos { display: flex; gap: 12px; list-style: none; overflow-x: auto; scroll-snap-type: x mandatory; margin: 0 -16px; padding: 0 16px 4px; scrollbar-width: none; -webkit-overflow-scrolling: touch; }
    .mr-fotos::-webkit-scrollbar { display: none; }
    .mr-fotos li { flex: 0 0 70%; scroll-snap-align: start; }
    .mr-fotos figure { margin: 0; }
    .mr-fotos img { aspect-ratio: 3 / 2; object-fit: cover; width: 100%; border-radius: 8px; background: var(--cinza); }
    .mr-fotos figcaption { font-size: 14px; color: var(--texto-2); margin-top: 6px; font-weight: 600; line-height: 1.35; }

    /* como funciona (faixa vermelha) */
    .mr-como { background: var(--vermelho); color: #fff; }
    .mr-como h2, .mr-como p { color: #fff; }
    .mr-passos { list-style: none; display: grid; gap: 14px; margin-top: 4px; }
    .mr-passo { display: flex; gap: 16px; align-items: flex-start; }
    .mr-num { flex: none; width: 48px; height: 48px; border-radius: 50%; background: #fff; color: var(--vermelho); font-weight: 800; font-size: 20px; display: grid; place-items: center; }
    .mr-passo h3 { font-size: 19px; color: #fff; padding-top: 10px; }
    .mr-passo p { margin-top: 6px; font-size: 15px; line-height: 1.5; }
    .mr-como-nota { margin-top: 26px; font-size: 14px; color: rgba(255, 255, 255, .92); max-width: 860px; }
    .mr-como-nota a { color: #fff; font-weight: 700; }

    /* certificado */
    .mr-cert-grade { display: grid; gap: 20px; }
    .mr-cert-figura { margin: 0; }
    .mr-cert-img { border: 1px solid var(--borda); border-radius: 8px; background: #fff; box-shadow: 0 1px 3px rgba(15, 19, 24, .08); width: 100%; }
    .mr-destaques { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; list-style: none; margin: 22px 0; }
    .mr-destaques li { background: #fff; border: 1px solid var(--borda); border-radius: 8px; padding: 12px 10px; font-weight: 800; font-size: 14px; display: flex; flex-direction: column; gap: 8px; line-height: 1.25; }
    .mr-destaques svg { width: 22px; height: 22px; color: var(--vermelho); }
    .mr-cert-texto p { color: var(--texto-2); }
    .mr-cert-texto p + p { margin-top: 12px; }
    .mr-cert-nota { font-size: 14px; }

    /* história */
    .mr-historia-grade { display: grid; gap: 28px; }
    .mr-historia-grade > div > p { color: var(--texto-2); }
    .mr-fatos { list-style: none; margin: 18px 0; display: grid; grid-template-columns: 1fr; gap: 10px; }
    .mr-fatos li { display: grid; grid-template-columns: 64px 1fr; gap: 2px 12px; align-items: baseline; padding: 10px 12px; border-left: 3px solid var(--vermelho); background: var(--cinza); border-radius: 0 6px 6px 0; }
    .mr-fatos b { font-weight: 800; color: var(--vermelho); font-size: 22px; letter-spacing: -.01em; }
    .mr-fatos span { font-weight: 600; color: var(--texto-2); font-size: 13px; line-height: 1.3; }
    .mr-historia figure { margin: 0; }
    .mr-historia img { width: 100%; aspect-ratio: 2 / 1; object-fit: cover; border-radius: 8px; background: var(--cinza); }
    .mr-historia figcaption { font-size: 14px; color: var(--texto-2); margin-top: 6px; }
    .mr-link-seta { display: inline-flex; align-items: center; gap: 8px; font-weight: 800; color: var(--vermelho); text-decoration: none; min-height: 44px; margin-top: 10px; border-bottom: 2px solid transparent; }
    .mr-link-seta:hover { border-bottom-color: var(--vermelho); }
    .mr-link-seta svg { width: 18px; height: 18px; }

    /* local */
    .mr-local-grade { display: grid; gap: 24px; }
    .mr-endereco { display: flex; gap: 10px; align-items: flex-start; font-size: 17px; font-weight: 700; margin: 14px 0 12px; color: var(--texto); }
    .mr-endereco svg { width: 22px; height: 22px; color: var(--vermelho); flex: none; margin-top: 2px; }
    .mr-endereco b { display: block; }
    .mr-local-nota { color: var(--texto-2); font-size: 15px; margin-bottom: 18px; }
    .mr-local-acoes { display: flex; flex-wrap: wrap; gap: 10px; }
    .mr-mapa { margin: 0; }
    .mr-mapa img, .mr-mapa iframe { width: 100%; aspect-ratio: 16 / 9; object-fit: cover; border-radius: 8px; background: var(--escuro); border: 0; display: block; }
    .mr-mapa figcaption { font-size: 14px; color: var(--texto-2); margin-top: 8px; }

    /* dúvidas (sanfona) */
    .mr-faq-lista { max-width: 860px; }
    .mr-faq details { border-bottom: 1px solid var(--borda); }
    .mr-faq details:first-of-type { border-top: 1px solid var(--borda); }
    .mr-faq summary, .mr-det-faq summary { list-style: none; cursor: pointer; display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 11px 0; font-weight: 800; font-size: 16px; min-height: 50px; line-height: 1.3; color: var(--texto); }
    .mr-faq summary::-webkit-details-marker, .mr-det-faq summary::-webkit-details-marker { display: none; }
    .mr-faq summary::after, .mr-det-faq summary::after { content: "+"; flex: none; width: 32px; height: 32px; border-radius: 50%; background: var(--cinza); color: var(--vermelho); font-size: 24px; font-weight: 700; display: grid; place-items: center; line-height: 1; }
    .mr-faq details[open] > summary::after, .mr-det-faq details[open] > summary::after { content: "−"; }
    .mr-faq details p, .mr-det-faq details p { margin: 0 0 16px; color: var(--texto-2); line-height: 1.6; padding-right: 48px; }
    .mr-faq details p a { color: var(--vermelho); font-weight: 700; }
    .mr-faq details p a[data-saida] { color: inherit; font-weight: 400; text-decoration: underline; }
    .mr-faq-chat { margin-top: 18px; font-size: 15px; color: var(--texto-2); }

    /* empresas e grupos */
    .mr-grupos [hidden] { display: none !important; }
    .mr-equipe { display: grid; gap: 16px; background: #fff; border: 1px solid var(--borda); border-left: 6px solid var(--vermelho); border-radius: 10px; padding: 24px 18px; }
    .mr-equipe p { color: var(--texto-2); }
    .mr-publicos { display: flex; flex-wrap: wrap; gap: 6px; list-style: none; margin: 14px 0 16px; }
    .mr-publicos li { background: var(--cinza); border: 1px solid var(--borda); border-radius: 999px; padding: 6px 12px; font-weight: 700; font-size: 13px; color: var(--texto-2); }
    .mr-equipe-nota { font-size: 14px; margin-top: 10px; }
    .mr-equipe .btn { width: 100%; }

    /* chamada final (fundo escuro) */
    .mr-final { background: var(--escuro); color: #fff; text-align: center; }
    .mr-final h2, .mr-final p { color: #fff; }
    .mr-final-conteudo { max-width: 720px; margin: 0 auto; }
    .mr-final-conteudo > p { margin-top: 10px; font-size: 16px; }
    .mr-final-acoes { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin-top: 22px; }
    .mr-final .btn-red { background: #fff; color: var(--vermelho); border-color: #fff; }
    .mr-final .btn-red:hover { background: var(--cinza); border-color: var(--cinza); }
    .mr-final .btn-outline { background: transparent; color: #fff; border-color: rgba(255, 255, 255, .75); }
    .mr-final .btn-outline:hover { border-color: #fff; background: rgba(255, 255, 255, .08); color: #fff; }
    .mr-final .mr-micro { margin-top: 18px; color: rgba(255, 255, 255, .85); }

    /* barra fixa (celular) */
    .mr-barra { position: fixed; left: 0; right: 0; bottom: 0; z-index: 60; background: #fff; border-top: 1px solid var(--borda); box-shadow: 0 -8px 24px rgba(15, 19, 24, .08); padding: 10px 16px calc(10px + env(safe-area-inset-bottom)); display: flex; align-items: center; gap: 12px; animation: mr-sobe .25s ease-out; }
    .mr-barra[hidden] { display: none; }
    @keyframes mr-sobe { from { transform: translateY(100%); } to { transform: none; } }
    @media (prefers-reduced-motion: reduce) { .mr-barra { animation: none; } .mr-card, .mr-nav, .mr .btn, .mr-chip { transition: none; } }
    .mr-barra-texto { min-width: 0; flex: 1; display: flex; flex-direction: column; line-height: 1.25; }
    .mr-barra-texto b { font-size: 15px; color: var(--texto); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .mr-barra-texto span { font-size: 12px; color: var(--texto-2); font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mr-barra .btn { min-height: 48px; padding: 10px 18px; white-space: nowrap; flex: none; }
    html.mr-barra-on body .cv-chat { bottom: calc(84px + env(safe-area-inset-bottom)); }
    html.mr-chat-recolher body .cv-chat:not(.aberto) .cv-chat-abrir { opacity: 0; pointer-events: none; }
    html body .cv-chat .cv-chat-abrir { transition: opacity .2s; }

    /* formulário da turma (dialog), como antes, com a paleta do modelo B */
    .mr-demanda-form { background: #fff; border: 1px solid var(--borda); border-radius: 10px; padding: 18px; font-family: var(--fonte-mr); color: var(--texto); }
    .mr-modal { width: 100vw; max-width: 100vw; height: 100vh; height: 100dvh; max-height: none; margin: 0; border: 0; border-radius: 0; padding: 12px 16px 32px; overflow: auto; overscroll-behavior: contain; box-shadow: 0 24px 64px rgba(15, 19, 24, .35); }
    .mr-modal::backdrop { background: rgba(15, 19, 24, .72); }
    .mr-modal-fechar { position: sticky; top: 0; float: right; margin: -4px -6px 0 8px; width: 44px; height: 44px; border: 0; border-radius: 50%; background: #fff; color: var(--texto); font-size: 26px; line-height: 1; cursor: pointer; z-index: 1; display: grid; place-items: center; font-family: inherit; }
    .mr-modal-fechar:hover, .mr-modal-fechar:focus-visible { background: var(--cinza); }
    .mr-demanda-form h3 { color: var(--texto); font-size: 22px; margin: 0 0 4px; }
    .mr-tf-nota { color: var(--texto-2); margin: 0 0 18px; }
    .mr-tf-grade { display: grid; grid-template-columns: 1fr; gap: 16px 20px; }
    .mr-tf-largo { grid-column: 1 / -1; }
    .mr-tf-campo { margin: 0; padding: 0; border: 0; min-width: 0; }
    .mr-tf-campo label, .mr-tf-campo legend { display: block; font-weight: 700; color: var(--texto); margin: 0 0 6px; padding: 0; font-size: 15px; }
    .mr-tf-campo label small { color: var(--texto-2); font-weight: 500; }
    .mr-tf-campo input:not([type=radio]):not([type=checkbox]), .mr-tf-campo select, .mr-tf-campo textarea { width: 100%; min-height: 48px; padding: 10px 14px; border: 2px solid var(--borda-forte); border-radius: 6px; font: inherit; font-size: 16px; color: var(--texto); background: #fff; }
    .mr-tf-campo textarea { min-height: 96px; resize: vertical; }
    .mr-tf-campo input:focus, .mr-tf-campo select:focus, .mr-tf-campo textarea:focus { outline: 0; border-color: var(--vermelho); box-shadow: 0 0 0 4px rgba(204, 0, 0, .14); }
    .mr-tf-campo.erro input, .mr-tf-campo.erro select, .mr-tf-campo.erro textarea { border-color: var(--vermelho); background: #fff8f8; }
    .mr-tf-opcoes { display: flex; flex-wrap: wrap; gap: 8px 18px; }
    .mr-tf-opcoes label, .mr-tf-check { display: flex; align-items: flex-start; gap: 8px; font-weight: 600; color: var(--texto); margin: 0; cursor: pointer; }
    .mr-tf-opcoes label > span { min-width: 0; }
    .mr-tf-opcoes input, .mr-tf-check input { width: 18px; height: 18px; margin: 3px 0 0; flex-shrink: 0; accent-color: var(--vermelho); }
    .mr-tf-dica { color: var(--texto-2); font-size: 14px; margin: 6px 0 0; }
    .mr-tf-dica a { color: var(--vermelho); text-decoration: underline; }
    .mr-tf-erro { color: #b91c1c; font-size: 14px; font-weight: 600; margin: 6px 0 0; }
    .mr-tf-erro.geral { background: #fff0f2; border: 1px solid #f5c2c7; border-radius: 6px; padding: 10px 14px; margin: 16px 0 0; }
    .mr-tf-situacao { margin: 18px 0; padding: 14px 18px; border-left: 4px solid var(--borda-forte); background: var(--cinza); border-radius: 0 6px 6px 0; color: var(--texto); }
    .mr-tf-situacao.fechada { border-left-color: var(--verde); }
    .mr-tf-situacao.lista { border-left-color: var(--vermelho); }
    .mr-tf-situacao.aviso { border-left-color: #b7791f; background: #fffaf0; }
    #tf-dados { margin-top: 4px; }
    #tf-enviar { margin-top: 20px; min-height: 56px; width: 100%; }
    #tf-enviar:disabled { background: #e2e8f0; border-color: #e2e8f0; color: var(--texto-2); cursor: not-allowed; }
    .mr-tf-armadilha { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
    .mr-tf-ok { text-align: center; padding: 18px 0; }
    .mr-tf-ok:focus { outline: 0; }
    .mr-tf-ok i { color: var(--verde); font-size: 36px; }
    .mr-tf-ok h3 { color: var(--texto); margin: 10px 0 6px; }

    /* celular */
    @media (max-width: 719px) {
      html body .cv-chat .cv-chat-abrir { width: 56px; padding: 0; justify-content: center; border-color: var(--borda); }
      html body .cv-chat .cv-chat-abrir-rotulo { position: absolute; width: 1px; height: 1px; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); clip-path: inset(50%); white-space: nowrap; }
      /* modo curso: a ficha abre no topo; sem a foto, o primeiro botão fica mais perto da primeira tela */
      html[data-curso] .mr-det-capa { aspect-ratio: auto; max-height: none; }
      html[data-curso] .mr-det-capa img { display: none; }
      html[data-curso] .mr-det-veu { position: static; }
    }
    @media (max-width: 559px) {
      html body .cvrj-ck { padding: 14px; }
      html body .cvrj-ck .cvrj-ck-botoes { grid-template-columns: repeat(3, 1fr); }
      html body .cvrj-ck .cvrj-ck-botoes button { font-size: .85rem; padding: 8px 4px; }
    }

    /* tablet */
    @media (min-width: 640px) {
      .mr-grade { grid-template-columns: repeat(2, 1fr); }
      .mr-fatos { grid-template-columns: repeat(3, 1fr); }
      .mr-fatos li { grid-template-columns: 1fr; gap: 4px; align-content: start; }
      .mr-cert-grade { grid-template-columns: 1fr 1fr; align-items: center; }
      .mr-equipe { grid-template-columns: 1.4fr 1fr; align-items: center; padding: 32px 28px; }
    }
    /* computador */
    @media (min-width: 720px) {
      .mr .wrap { width: min(1176px, calc(100% - 64px)); }
      .mr-nav { display: block; }
      .mr-barra { display: none !important; }
      .mr section { padding: 72px 0; }
      .mr-filtro { padding: 72px 0 0 !important; }
      .mr-cursos { padding: 0 0 72px !important; }
      .mr section[id], .mr-card, .mr-det { scroll-margin-top: calc(var(--mr-cabecalho, 84px) + 76px); }
      .mr-cab { margin-bottom: 28px; }
      .mr-cab p { font-size: 17px; }
      .mr-marcas ul { grid-template-columns: repeat(4, 1fr); padding: 22px 0; }
      .mr-marcas li { font-size: 16px; }
      .mr-chips { margin: 0; padding: 4px 0 14px; flex-wrap: wrap; overflow: visible; }
      .mr-capa { max-height: none; }
      .mr-card-corpo { padding: 16px; gap: 14px; }
      .mr-preco { gap: 8px; padding-top: 14px; }
      .mr-grade { gap: 20px; }
      .mr-fotos { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; overflow: visible; margin: 0; padding: 0; }
      .mr-fotos li { flex: none; }
      .mr-fotos img { aspect-ratio: 4 / 3; }
      .mr-passos { grid-template-columns: repeat(2, 1fr); gap: 28px 32px; margin-top: 8px; }
      .mr-historia-grade { grid-template-columns: 1fr 1fr; align-items: center; }
      .mr-historia img, .mr-mapa img, .mr-mapa iframe { aspect-ratio: 4 / 3; }
      .mr-fatos { gap: 12px; }
      .mr-fatos span { font-size: 14px; }
      .mr-local-grade { grid-template-columns: 1fr 1fr; align-items: center; }
      .mr-faq summary, .mr-det-faq summary { min-height: 56px; padding: 14px 0; font-size: 17px; }
      .mr-det-cert { grid-template-columns: 1fr 300px; align-items: center; }
      .mr-det-oferta { grid-template-columns: 1fr auto; align-items: center; }
      .mr-det-oferta .btn { width: auto; min-width: 240px; }
      .mr-det-oferta .mr-micro { grid-column: 1 / -1; }
      .mr-janela { height: auto; max-height: calc(100vh - 48px); max-height: calc(100dvh - 48px); margin: auto; border-radius: 12px; }
      .mr-modal { width: min(720px, calc(100vw - 32px)); height: auto; max-height: calc(100vh - 48px); max-height: calc(100dvh - 48px); margin: auto; border-radius: 12px; padding: 28px; }
      .mr-modal-fechar { margin: -12px -12px 0 8px; }
      .mr-tf-grade { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      #tf-enviar { width: auto; min-width: 260px; }
    }
    @media (min-width: 1024px) {
      .mr-passos { grid-template-columns: repeat(4, 1fr); }
      .mr-passo { flex-direction: column; gap: 14px; }
      .mr-passo h3 { padding-top: 0; }
    }
    @media (min-width: 1200px) {
      .mr-grade { grid-template-columns: repeat(3, 1fr); gap: 24px; }
      .mr-card-turma { grid-column: span 2; flex-direction: row; }
      .mr-card-turma .mr-capa { flex: 0 0 44%; aspect-ratio: auto; max-height: none; }
      .mr-card-turma .mr-card-corpo { padding: 28px 32px; }
      .mr-card-turma p { max-width: 560px; }
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
          barraSub.textContent = 'Inscrição ' + INSC_CURTO + ' agora · curso depois';
          barraCta.textContent = 'Garantir vaga';
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
      // A janela da turma por cima da dos detalhes: ao fechar, a trava de rolagem continua enquanto a de detalhes está aberta.
      var turmaBloco = document.getElementById('turma-form-bloco');
      if (turmaBloco) turmaBloco.addEventListener('close', function () { if (janela && janela.open) raiz.classList.add('mr-modal-aberto'); });
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
        // De dentro da janela dos detalhes, o chat, a turma e os links de âncora (#certificado) só funcionam com a
        // janela fechada: o dialog deixa o resto da página inerte.
        if (janela && janela.open && janela.contains(alvo)) {
          if (alvo.closest('[data-abrir-chat], [data-turma-abrir]')) fecharDetalhes();
          var anc = alvo.closest('a[href^="#"]:not([data-abrir-chat]):not([data-turma-abrir]):not([data-detalhes])');
          if (anc) {
            var alvoId = document.getElementById(anc.getAttribute('href').slice(1));
            fecharDetalhes();
            if (alvoId) { e.preventDefault(); alvoId.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
          }
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
        var mostrar = celular.matches && naTela === 0 && fimObs === 0 && passouTopo() && !janelaAberta && !(aviso && aviso.getClientRects().length > 0);
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
      if (inicial) {
        var dIni = document.getElementById('det-' + inicial);
        if (dIni) dIni.classList.add('mr-det-foco');
        focar(inicial); verCurso(inicial, 'url');
      } else {
        var h = location.hash.match(/^#(?:det|curso)-([a-z0-9-]+)$/);
        if (h && cards[h[1]]) {
          // Sem o hash, a ficha não fica impressa solta na página (:target) quando a janela fechar.
          try { history.replaceState(null, '', location.pathname + location.search); } catch (err) {}
          // Com o aviso de cookies na tela, a janela abriria por cima dele e ninguém conseguiria responder: abre depois
          // da escolha. O aviso é montado por um script adiado, por isso a conferência espera um instante.
          // (consentimento.js é adiado e monta o aviso no DOMContentLoaded; este script é inline, então espera esse
          // momento e mais um instante.)
          var slugHash = h[1];
          var conferirAviso = function () {
            if (document.querySelector('section.cvrj-ck')) {
              document.addEventListener('cvrj:consentimento', function abrirDepois() { document.removeEventListener('cvrj:consentimento', abrirDepois); setTimeout(function () { abrirDetalhes(slugHash, 'url'); }, 0); });
            } else {
              abrirDetalhes(slugHash, 'url');
            }
          };
          if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(conferirAviso, 0); });
          else setTimeout(conferirAviso, 0);
        }
      }
    })();
  </script>"""


def esc(s: str) -> str:
    return html.escape(s, quote=True)

# Ícones de traço do modelo B, em SVG inline (os de Font Awesome, via scripts/icones.py, são sólidos e continuam
# servindo onde já estavam: formulário da turma, lista "o que você aprende").
def _svg(caminho: str, traco: float = 2, cheio: bool = False) -> str:
    atrs = 'fill="currentColor"' if cheio else f'fill="none" stroke="currentColor" stroke-width="{traco}" stroke-linecap="round" stroke-linejoin="round"'
    return f'<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" {atrs}>{caminho}</svg>'


SVG = {
    "seta": _svg('<path d="M5 12h14M13 6l6 6-6 6"/>', 2.2),
    "pino": _svg('<path d="M12 22s7-7.1 7-12a7 7 0 1 0-14 0c0 4.9 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/>'),
    "cruz": _svg('<path d="M9 3h6v6h6v6h-6v6H9v-6H3V9h6z"/>', cheio=True),
    "check": _svg('<path d="m5 12 5 5 9-10"/>', 2.4),
    "check-circulo": _svg('<circle cx="12" cy="12" r="10"/><path d="m8 12 3 3 5-6"/>', 2.2),
    "pessoas": _svg('<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'),
    "medalha": _svg('<circle cx="12" cy="8" r="6"/><path d="M15.5 13 17 22l-5-3-5 3 1.5-9"/>'),
    "cartao": _svg('<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>'),
    "pulso": _svg('<path d="M3 12h4l2-5 4 10 2-5h6"/>'),
    "relogio": _svg('<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>'),
    "capelo": _svg('<path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>'),
}
MARCAS = [("pessoas", "Aulas presenciais"), ("medalha", "Certificado"), ("cartao", "PIX ou cartão"), ("pulso", "Formação prática")]


def foto_real(f: dict, sizes: str, loading: str = "lazy", classe: str = "", extra: str = "") -> str:
    """<img> de uma foto de /assets/otim com srcset pelas larguras disponíveis; `pos` vira object-position."""
    srcset = ", ".join(f"/assets/otim/{f['base']}-{w}.webp {w}w" for w in f["larguras"])
    estilo = f' style="object-position:{f["pos"]}"' if f.get("pos") and f["pos"] != "center" else ""
    cls = f' class="{classe}"' if classe else ""
    return (f'<img{cls} src="/assets/otim/{f["base"]}-{f["largura"]}.webp" srcset="{srcset}" sizes="{sizes}" '
            f'width="{f["largura"]}" height="{f["altura"]}" alt="{esc(f["alt"])}" loading="{loading}"{estilo}{extra}>')


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


def hero_html(insc: str) -> str:
    """O banner principal (banner-6-branco, escolhido pelo dono em 05/10/2026). Trocar o banner é mexer aqui e no bloco
    /* Hero */ do CSS_PAGINA. Ganchos que o resto da página espera: section.mr-hero (JS da navegação e da barra fixa),
    h1#mr-titulo, o primeiro botão com .mr-cta-topo (primeira tela) e data-secao="topo"."""
    itens = [
        "O valor do curso é pago depois, na escola",
        "PIX ou cartão, sem criar conta antes de pagar",
        "Devolução integral se desistir em até 7 dias",  # GARANTIA_7_DIAS: 7 dias corridos do pagamento, valor de volta por inteiro
        "Certificado da Cruz Vermelha ao concluir",
    ]
    lista = "".join(f'<li>{SVG["check"]}<span>{esc(t)}</span></li>' for t in itens)
    return f'''
    <section class="mr-hero" aria-labelledby="mr-titulo" data-secao="topo">
      <div class="wrap">
        <div class="mr-hero-conteudo">
          <p class="eyebrow">Escola de Educação e Saúde</p>
          <h1 id="mr-titulo">Cursos presenciais da Cruz Vermelha no Rio de Janeiro</h1>
          <p class="mr-hero-frase">Aprenda na prática, na sede da Cruz Vermelha Brasileira Rio de Janeiro, no Centro. Escolha seu curso, veja os detalhes e garanta sua vaga.</p>
          <div class="mr-hero-acoes">
            <a class="btn btn-red mr-cta-topo" href="#cursos">Ver cursos {SVG["seta"]}</a>
            <a class="btn btn-outline" href="#como-funciona">Como funciona</a>
          </div>
          <p class="mr-hero-endereco">{SVG["pino"]}<span>Praça da Cruz Vermelha, 10 — Centro, Rio de Janeiro, RJ</span></p>
        </div>
        <aside class="mr-hero-painel" aria-label="Como funciona o valor">
          <strong>Inscrição {insc}<small>paga agora, garante a sua vaga</small></strong>
          <ul>{lista}</ul>
          <a class="btn btn-red" href="#cursos">Escolher meu curso</a>
        </aside>
        <figure class="mr-hero-midia">
          {foto_real(FOTO_TOPO, "(min-width: 768px) 46vw, 100vw", "eager", "mr-hero-foto", ' fetchpriority="high"')}
          <figcaption class="mr-hero-legenda">{SVG["cruz"]}<span>{FOTO_TOPO["legenda"]}</span></figcaption>
        </figure>
      </div>
    </section>'''


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

    def foto(s: str, largura: int, loading: str = "lazy", sizes: str = "") -> str:
        """Capa do curso: a foto real de CAPA_CURSO, se houver; senão a imagem gerada em img/."""
        real = CAPA_CURSO.get(s)
        if real:
            f = dict(real, largura=real["larguras"][-1] if largura > 480 else real["larguras"][0])
            return foto_real(f, sizes, loading)
        img = cursos[s]["imagem"]
        if not (PASTA_IMG / f"{img}-960.webp").exists():
            return ""
        srcset = f'srcset="img/{img}-480.webp 480w, img/{img}-960.webp 960w" sizes="{sizes}" ' if sizes else ""
        return (f'<img src="img/{img}-{largura}.webp" {srcset}alt="{esc(cursos[s]["nome"])} na Cruz Vermelha '
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
        """Preço em duas linhas (modelo B): inscrição agora, curso depois."""
        return (f'<div class="mr-preco"><div class="mr-preco-l agora"><strong>Inscrição {insc}</strong><span>paga agora</span></div>'
                f'<div class="mr-preco-l"><strong>Curso {esc(valor_curso(s))}</strong><span>pago depois, na escola</span></div></div>')

    def meta(s: str) -> str:
        c = cursos[s]
        return (f'<ul class="mr-meta"><li>{SVG["relogio"]}{esc(c["carga_horaria"])}</li>'
                f'<li>{SVG["capelo"]}{esc(c["escolaridade"])}</li></ul>')

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
        itens = [(q["pergunta"], q["resposta"]) for q in (cursos[s].get("faq") or []) if q.get("pergunta") and q.get("resposta")
                 and not EXCLUIR_CATALOGO.search(q["pergunta"] + " " + q["resposta"])]
        if not itens and s == "primeiros-socorros-lei-lucas":
            itens = [(q["pergunta"], q["resposta"]) for q in lei_lucas]
        return itens

    def objecao(s: str) -> tuple[str, str] | None:
        o = (COPY_CURSO.get(s) or {}).get("objecao")
        if not o:
            return None
        return tuple(x.replace("{insc}", insc).replace("{curso}", valor_curso(s)) for x in o)

    # --- cartão de curso (modelo B: capa com etiqueta e véu com título, meta, preço em duas linhas, dois botões) ----
    SIZES_CARTAO = "(min-width: 1200px) 380px, (min-width: 640px) 50vw, 100vw"

    def cartao(s: str, posicao: int) -> str:
        c = cursos[s]
        cid, cnome = categoria(s)
        return f'''
          <article class="mr-card mr-detalhe" id="curso-{s}" data-curso="{s}" data-cat="{cid}" data-nome="{esc(c["nome"])}" data-curto="{esc(curto(s))}"{' data-cert' if tem_cert(s) else ''}>
            <figure class="mr-capa">{foto(s, 480, "eager" if posicao < 2 else "lazy", SIZES_CARTAO)}<span class="mr-capa-tag">{esc(cnome)}</span>{badge(s)}<figcaption class="mr-veu"><h3>{esc(curto(s))}</h3><p>{esc(beneficio(s))}</p></figcaption></figure>
            <div class="mr-card-corpo">
              {meta(s)}
              {preco(s)}
              <div class="mr-card-acoes">
                <a class="btn btn-red mr-cta" data-local="cartao" data-curso="{s}" href="{checkout(s, "cartao")}">Garantir vaga</a>
                <a class="btn btn-outline" href="#det-{s}" data-detalhes="{s}" data-local="cartao">Ver detalhes</a>
              </div>
            </div>
          </article>'''

    # --- ficha / detalhes do curso (escondida; vai para a janela, ou abre no topo no modo curso) ----------------
    # Ordem: capa com título → promessa → preço e botão → carga, pré-requisito, local, materiais → sobre → aprende →
    # para quem → certificação → dúvidas do curso → chat e turma → rodapé fixo com preço e botão.
    def detalhes(s: str) -> str:
        c = cursos[s]
        cc = COPY_CURSO.get(s) or {}
        cid, cnome = categoria(s)
        sobre = "".join(f"<p>{esc(nome_filial(p))}</p>" for p in c["sobre"] if not EXCLUIR_CATALOGO.search(p))
        aprende = "".join(f'<li><i class="fa-solid fa-check"></i><span>{esc(a)}</span></li>' for a in cc.get("aprende", []))
        aprende_html = f'<div class="mr-det-bloco"><h4>O que você vai aprender</h4><ul class="mr-det-lista">{aprende}</ul></div>' if aprende else ""
        para_quem = f'<div class="mr-det-bloco"><h4>Para quem é indicado</h4><p>{esc(cc["para_quem"])}</p></div>' if cc.get("para_quem") else ""
        ob = objecao(s)
        perguntas = ([(ob[0], ob[1])] if ob else []) + faq_curso(s)
        faq = "".join(f"<details><summary>{esc(nome_filial(p))}</summary><p>{esc(nome_filial(r))}</p></details>" for p, r in perguntas)
        faq_html = f'<div class="mr-det-faq"><h4>Dúvidas sobre {esc(curto(s))}</h4>{faq}</div>' if faq else ""
        obs = "".join(f"<p>{esc(nome_filial(o))}</p>" for o in c["observacoes"])
        materiais = esc(MATERIAIS.get(s, "Informação em breve"))
        return f'''
        <section class="mr-det" id="det-{s}" data-curso="{s}" aria-labelledby="det-titulo-{s}">
          <figure class="mr-det-capa">{foto(s, 960, "lazy", "(min-width: 760px) 760px, 100vw")}<figcaption class="mr-det-veu"><span class="mr-capa-tag">{esc(cnome)}</span><h3 id="det-titulo-{s}">{esc(cc.get("titulo") or curto(s))}</h3></figcaption></figure>
          <div class="mr-det-corpo">
            <p class="mr-det-promessa">{esc(cc.get("promessa") or beneficio(s))}</p>
            <div class="mr-det-oferta">
              {preco(s)}
              <a class="btn btn-red mr-cta" data-local="detalhes" data-curso="{s}" href="{checkout(s, "detalhes")}">Garantir minha vaga · {insc}</a>
              <p class="mr-micro">{SVG["check-circulo"]}7 dias para desistir, com o valor de volta</p>
            </div>
            <ul class="mr-det-grade">
              <li><small>Carga horária</small><b>{esc(c["carga_horaria"])}</b></li>
              <li><small>Pré-requisito</small><b>{esc(c["escolaridade"])}</b></li>
              <li><small>Local</small><b>Praça da Cruz Vermelha, 10 · Centro</b></li>
              <li><small>Materiais necessários</small><b>{materiais}</b></li>
            </ul>
            <div class="mr-det-bloco"><h4>Sobre o curso</h4>{sobre}{obs}</div>
            {aprende_html}
            {para_quem}
            <div class="mr-det-bloco mr-det-cert"><div><h4>Certificação</h4><p>Ao concluir os requisitos do curso, você recebe o certificado da Cruz Vermelha Brasileira Rio de Janeiro, com o seu nome, o curso e a carga horária. <a href="#certificado">Veja o certificado</a>.</p></div>{cert_img(s, "lazy", "(min-width: 720px) 300px, 100vw")}</div>
            {faq_html}
            <div class="mr-det-fim">
              <p class="mr-micro">Inscrição de {insc} agora, por PIX ou cartão · curso {esc(valor_curso(s))} pago depois, na escola, antes da aula · sem criar conta antes de pagar</p>
              <p class="mr-chat-atalho"><a href="#chat" data-abrir-chat data-assunto="matricula" data-curso="{s}" data-local="detalhes">Dúvida antes de pagar? Pergunte no chat.</a></p>
              <p class="mr-turma-linha">Tem um grupo de {TURMA_MINIMO} a {TURMA_MAXIMO} pessoas ou quer este curso em inglês? <a href="#empresas" data-turma-abrir="curso" data-curso="{s}" aria-controls="turma-form-bloco">Peça uma turma</a>.</p>
            </div>
          </div>
          <div class="mr-det-rodape"><div class="mr-det-rodape-preco"><strong>Inscrição {insc}</strong><span>Curso {esc(valor_curso(s))} pago depois, na escola</span></div><a class="btn btn-red mr-cta" data-local="detalhes_fim" data-curso="{s}" href="{checkout(s, "detalhes_fim")}">Garantir vaga</a></div>
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
                    for q in (c.get("faq") or []) if q.get("pergunta") and q.get("resposta")
                    and not EXCLUIR_CATALOGO.search(q["pergunta"] + " " + q["resposta"])],
        })
    if chat_widget.atualizar_cursos(para_o_chat):
        print("atualizado site/chat/chat.js (cursos, ficha e dúvidas)")
    chat_tags = chat_widget.tags()

    # --- 1. navegação da página (computador) ------------------------------------------------------------------
    nav = '''
  <nav class="mr-nav mr" id="mr-nav" aria-label="Seções desta página">
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

    # --- 2. topo (hero_html) e faixa de marcas ------------------------------------------------------------------
    hero = hero_html(insc)
    marcas = f'''
    <div class="mr-marcas">
      <div class="wrap">
        <ul aria-label="O que você encontra na escola">{"".join(f'<li>{SVG[i]}<span>{esc(t)}</span></li>' for i, t in MARCAS)}</ul>
      </div>
    </div>'''

    # --- fichas (escondidas) --------------------------------------------------------------------------------
    dets = f'''
    <div class="mr-dets wrap" id="mr-dets">{"".join(detalhes(s) for s in exibicao)}</div>'''

    # --- 3. categorias e 4. cursos (um bloco cinza só; duas seções para o secao_vista continuar igual) ----------
    chips = "".join(f'<button class="mr-chip" type="button" data-cat="{cid}" aria-pressed="{"true" if cid == "todos" else "false"}">{esc(n)}</button>'
                    for cid, n in CATEGORIAS)
    tipos = ["Empresas", "Escolas", "Condomínios", "Instituições", "Grupos organizados"]
    cartao_turma = f'''
          <article class="mr-card-turma" id="mr-card-turma">
            <figure class="mr-capa"><img src="{FOTO_GRUPOS["src"]}" srcset="{FOTO_GRUPOS["srcset"]}" sizes="(min-width: 1200px) 520px, (min-width: 640px) 50vw, 100vw" width="{FOTO_GRUPOS["largura"]}" height="{FOTO_GRUPOS["altura"]}" alt="{esc(FOTO_GRUPOS["alt"])}" loading="lazy"><span class="mr-capa-tag">Empresas e grupos</span></figure>
            <div class="mr-card-corpo">
              <h3>Turma para empresas e grupos</h3>
              <p>De {TURMA_MINIMO} a {TURMA_MAXIMO} alunos, com o mesmo valor por pessoa. Qualquer curso também em inglês.</p>
              <ul aria-label="Para quem">{"".join(f"<li>{esc(t)}</li>" for t in tipos[:4])}</ul>
              <p>Nada é cobrado agora. A secretaria responde em até 3 dias úteis.</p>
              <button class="btn btn-branco" type="button" data-turma-abrir="catalogo" aria-controls="turma-form-bloco">Solicitar uma turma</button>
            </div>
          </article>'''
    cursos_sec = f'''
    <section class="mr-filtro mr-cinza" aria-labelledby="mr-filtro-titulo" data-secao="categorias">
      <div class="wrap">
        <div class="mr-cab"><h2 id="mr-filtro-titulo">Qual formação você procura?</h2></div>
        <div class="mr-chips" role="group" aria-label="Filtrar cursos por categoria">{chips}</div>
      </div>
    </section>
    <section class="mr-cursos mr-cinza" id="cursos" aria-labelledby="mr-cursos-titulo" data-secao="cursos">
      <div class="wrap">
        <h2 id="mr-cursos-titulo" class="mr-escondido">Cursos presenciais e valores</h2>
        <div class="mr-grade">{"".join(cartao(s, i) for i, s in enumerate(exibicao))}{cartao_turma}</div>
        <p class="mr-vazio" id="mr-vazio" hidden>Nenhum curso nesta categoria.</p>
        <p class="mr-nota-preco">{SVG["check-circulo"]}<span>Inscrição de {insc} por PIX ou cartão · valor do curso pago depois, na escola · 7 dias para desistir, com o valor de volta</span></p>
      </div>
    </section>'''

    # --- 6. fotos --------------------------------------------------------------------------------------------
    fotos = "".join(f'<li><figure>{foto_real(f, "(min-width: 720px) 25vw, 76vw")}<figcaption>{esc(f["legenda"])}</figcaption></figure></li>' for f in FOTOS_AULAS)
    galeria = f'''
    <section class="mr-fotos-sec" aria-labelledby="mr-fotos-titulo" data-secao="fotos">
      <div class="wrap">
        <div class="mr-cab">
          <h2 id="mr-fotos-titulo">Aqui você aprende fazendo.</h2>
          <p>Conhecimento para entender. Prática para saber como agir.</p>
        </div>
        <ul class="mr-fotos">{fotos}</ul>
      </div>
    </section>'''

    # --- 7. como funciona -------------------------------------------------------------------------------------
    passos = "".join(f'<li class="mr-passo"><span class="mr-num" aria-hidden="true">{i}</span><div><h3>{esc(t)}</h3><p>{esc(d)}</p></div></li>' for i, (t, d) in enumerate(PASSOS_COMO, 1))
    como = f'''
    <section class="mr-como" id="como-funciona" aria-labelledby="mr-como-titulo" data-secao="como_funciona">
      <div class="wrap">
        <div class="mr-cab">
          <h2 id="mr-como-titulo">Como funciona</h2>
          <p>Quatro passos, sem criar conta antes de pagar.</p>
        </div>
        <ol class="mr-passos">{passos}</ol>
        <p class="mr-como-nota">{esc(GARANTIA_7_DIAS)} <a href="/reembolso/">Regras de cancelamento e reembolso</a>.</p>
      </div>
    </section>'''

    # --- 8. certificado ---------------------------------------------------------------------------------------
    destaques = [("pessoas", "Nome do aluno"), ("medalha", "Nome do curso"), ("relogio", "Carga horária")]
    certificado = f'''
    <section class="mr-cert mr-cinza" id="certificado" aria-labelledby="mr-cert-titulo" data-secao="certificado">
      <div class="wrap mr-cert-grade">
        <div class="mr-cert-texto">
          <h2 id="mr-cert-titulo">Sua formação também fica registrada.</h2>
          <p>Após concluir os requisitos do curso, o aluno recebe seu certificado emitido pela Cruz Vermelha Brasileira Rio de Janeiro.</p>
          <ul class="mr-destaques" aria-label="O que consta no certificado">{"".join(f'<li>{SVG[i]}{esc(t)}</li>' for i, t in destaques)}</ul>
          <p>{esc(CERT_PESO)}</p>
          <p class="mr-cert-nota">{esc(CERT_NOTA)}</p>
        </div>
        <figure class="mr-cert-figura">{cert_img(CERT_DESTAQUE, "lazy", "(min-width: 640px) 50vw, 100vw")}</figure>
      </div>
    </section>'''

    # --- 9. história -----------------------------------------------------------------------------------------
    fatos = "".join(f'<li><b>{esc(n)}</b><span>{esc(t)}</span></li>' for n, t in FATOS_HISTORIA)
    historia = f'''
    <section class="mr-historia" id="historia" aria-labelledby="mr-historia-titulo" data-secao="historia">
      <div class="wrap mr-historia-grade">
        <div>
          <h2 id="mr-historia-titulo">Mais de um século de história no Rio de Janeiro.</h2>
          <ul class="mr-fatos">{fatos}</ul>
          <p>{esc(TEXTO_HISTORIA)}</p>
          <a class="mr-link-seta" href="/historia/">Conheça nossa história {SVG["seta"]}</a>
        </div>
        <figure>
          <img src="{FOTO_HISTORIA["src"]}" srcset="{FOTO_HISTORIA["srcset"]}" sizes="(min-width: 720px) 50vw, 100vw" width="960" height="720" alt="{esc(FOTO_HISTORIA["alt"])}" loading="lazy">
          <figcaption>{esc(FOTO_HISTORIA["legenda"])}</figcaption>
        </figure>
      </div>
    </section>'''

    # --- 10. local -------------------------------------------------------------------------------------------
    local = f'''
    <section class="mr-local mr-cinza" id="local" aria-labelledby="mr-local-titulo" data-secao="local">
      <div class="wrap mr-local-grade">
        <div>
          <h2 id="mr-local-titulo">Onde acontecem as aulas?</h2>
          <p class="mr-endereco">{SVG["pino"]}<span><b>Cruz Vermelha Brasileira Rio de Janeiro</b>Praça da Cruz Vermelha, 10 — Centro, Rio de Janeiro, RJ · CEP 20230-130</span></p>
          <p class="mr-local-nota">Todos os cursos são presenciais, no Palácio da Cruz Vermelha, sede da filial. Os dias e horários são os de cada turma.</p>
          <div class="mr-local-acoes">
            <a class="btn btn-red" href="{MAPA_ROTA}" target="_blank" rel="noopener">Como chegar {SVG["seta"]}</a>
            <button class="btn btn-outline" type="button" id="mr-mapa-carregar" data-src="{MAPA_EMBED}">Ver no mapa</button>
          </div>
        </div>
        <figure class="mr-mapa" id="mr-mapa">
          <img src="{FOTO_FACHADA["src"]}" width="767" height="516" alt="{esc(FOTO_FACHADA["alt"])}" loading="lazy">
          <figcaption>{esc(FOTO_FACHADA["legenda"])}</figcaption>
        </figure>
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
                    partes_cmp.append(f'{c["nome"]}: {c["carga_horaria"]}, curso {valor_curso(s)}. {publico}')
            r = " ".join(partes_cmp) + " Os três pedem Ensino Fundamental."
            r_html = None
        faq_lista.append((p, r, r_html or esc(r)))
    faq_html = "".join(f"<details><summary>{esc(p)}</summary><p>{rh}</p></details>" for p, _, rh in faq_lista)
    faq_sec = f'''
    <section class="mr-faq" id="duvidas" aria-labelledby="mr-faq-titulo" data-secao="faq">
      <div class="wrap">
        <div class="mr-cab"><h2 id="mr-faq-titulo">Dúvidas frequentes</h2></div>
        <div class="mr-faq-lista">{faq_html}</div>
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
    grupos = f'''
    <section class="mr-grupos mr-cinza" id="empresas" aria-labelledby="mr-grupos-titulo" data-secao="empresas">
      <div class="wrap">
        <div class="mr-equipe">
          <div>
            <h2 id="mr-grupos-titulo">Precisa capacitar uma equipe?</h2>
            <ul class="mr-publicos" aria-label="Para quem">{"".join(f"<li>{esc(t)}</li>" for t in tipos)}</ul>
            <p>Também organizamos turmas para empresas, escolas, condomínios, instituições e grupos: de {TURMA_MINIMO} a {TURMA_MAXIMO} alunos, com o mesmo valor por pessoa, na sede. Qualquer curso também em inglês, e primeiros socorros para jovens de 12 a 14 anos.</p>
            <p class="mr-equipe-nota">Nada é cobrado agora. A secretaria responde em até 3 dias úteis.</p>
          </div>
          <div><button class="btn btn-red" type="button" data-turma-abrir="faixa" aria-expanded="false" aria-controls="turma-form-bloco">Solicitar uma turma</button></div>
        </div>
        __TURMA_DIALOG__
      </div>
    </section>'''

    # --- 13. chamada final (fundo escuro) -----------------------------------------------------------------------
    final = f'''
    <section class="mr-final" aria-labelledby="mr-final-titulo" data-secao="final">
      <div class="wrap">
        <div class="mr-final-conteudo">
          <h2 id="mr-final-titulo">Pronto para começar?</h2>
          <p>Escolha sua formação, veja os detalhes e garanta sua vaga.</p>
          <div class="mr-final-acoes" id="mr-final-acoes">
            <a class="btn btn-red" href="#cursos" data-final-primario>Ver cursos disponíveis</a>
            <a class="btn btn-outline" href="#chat" data-abrir-chat data-assunto="matricula" data-local="final">Falar com a escola</a>
          </div>
          <p class="mr-micro">Inscrição de {insc} por PIX ou cartão · valor do curso pago depois, na escola · 7 dias para desistir, com o valor de volta</p>
        </div>
      </div>
    </section>'''

    # --- janela dos detalhes e barra fixa ---------------------------------------------------------------------
    janela = '''
  <dialog class="mr-janela mr" id="mr-janela" aria-label="Detalhes do curso">
    <button class="mr-janela-fechar" type="button" data-janela-fechar aria-label="Fechar">&times;</button>
    <div id="mr-janela-corpo"></div>
  </dialog>'''
    barra = f'''
  <div class="mr-barra mr" id="mr-barra" hidden>
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
  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&display=swap"></noscript>
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
  <main id="matricula-cursos-presenciais" class="mr">
{hero}
{marcas}
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
