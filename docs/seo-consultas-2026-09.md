# Consultas do Google Search Console (últimos meses até 19/09/2026) e plano para ranquear

Fonte: relatório "Top consultas" enviado pelo Matheus em 19/09/2026 (cliques / impressões).

## O que os números dizem

| Grupo de consultas | Cliques | Impressões | Leitura |
| --- | --- | --- | --- |
| "cruz vermelha cursos", "curso cruz vermelha", "cursos cruz vermelha rj", "cruz vermelha rj cursos", "curso na cruz vermelha rj"… | ~40 | ~70 | É a intenção que já converte. A página de matrícula precisa ser a resposta direta (título com "Cursos da Cruz Vermelha RJ"). |
| "cruz vermelha" (7/114), "cruz vermelha rj" (5/68), "cruz vermelha brasileira" (0/21), "cruz vermelha rio de janeiro" (0/4) | 12 | ~207 | Muita impressão, pouco clique: a home aparece mas o título/descrição não pedem o clique; parte busca a nacional. |
| "curso de primeiros socorros cruz vermelha rj" e variações, "curso de socorrista", "curso bls", "cursos de primeiros socorros" | ~8 | ~20 | Intenção por curso específico. Hoje tudo cai na mesma URL da matrícula (`?curso=`), que não tem título próprio por curso. |
| "cruz vermelha rj cursos gratuitos", "cruz vermelha cursos gratuitos", "curso de enfermagem cruz vermelha gratuito" | 4 | 8 | A pessoa quer saber se há curso gratuito. O site não responde; a FAQ da home precisa responder com clareza (o que é pago, o que existe de gratuito). |
| "cruz vermelha centro" (0/19), "cruz vermelha centro rj", "endereço da cruz vermelha", "telefone cruz vermelha centro rj", "cruz vermelha whatsapp" | 0 | ~24 | Intenção local (endereço, horário, contato). Responder na FAQ e no bloco de contato: Praça da Cruz Vermelha, 10, Centro; chat e e-mail; WhatsApp só do voluntariado. |
| "hospital cruz vermelha rj", "hospital da cruz vermelha rio de janeiro", "cruz vermelha hospital" | 0 | ~15 | Confusão recorrente. Uma resposta clara na FAQ ("a filial não é hospital nem pronto-socorro; emergência é 192/193") captura a busca e evita ligações erradas. **Confirmar com o Matheus** se existe ou existiu hospital da Cruz Vermelha no Rio para redigir sem erro. |
| "cruz vermelha curso cuidador de idosos", "curso cuidador de idosos", "bombeiro civil", "curso de bombeiro civil rj" | 1 | 4 | Cursos que já existem; falta uma URL própria para cada um. |
| "cruz vermelha cursos técnicos", "curso de enfermagem cruz vermelha", "curso de babá", "necropsia", "tanatopraxia", "instrumentação cirúrgica", "coleta de sangue" | 1 | ~8 | Cursos que a filial não oferece. Uma pergunta na FAQ ("Quais cursos a Cruz Vermelha RJ oferece?") lista os sete e diz o que não existe, sem deixar a pessoa sair frustrada. |
| "cruz vermelha nova iguaçu cursos", "cruz vermelha cabo frio" | 0 | 4 | Buscas por outras cidades do estado. Responder onde os cursos acontecem (Centro do Rio) e como chegar. |
| "voluntário cruz vermelha", "vagas cruz vermelha" | 0 | 3 | Voluntariado: a FAQ e a bio já apontam formulário e WhatsApp do voluntariado. |
| "presidente da cruz vermelha" | 0 | 3 | Institucional: página da equipe. |

## Plano, em ordem de retorno

1. **Feito em 19/09**: título e descrição da página de matrícula passaram a "Cursos da Cruz Vermelha RJ:
   matrícula em cursos presenciais" e a citar primeiros socorros, bombeiro civil e cuidador de idosos no
   Centro do Rio. A home ganhou a FAQ reescrita com perguntas tiradas desta lista (cursos gratuitos,
   hospital, endereço no Centro, telefone/WhatsApp, o que não é oferecido, outras cidades) e `FAQPage`
   nos dados estruturados. A bio do Instagram (`/bio/`) entrou no domínio.
2. **Próximo passo com maior retorno: uma página por curso** (`/cursos/<slug>/`), gerada de
   `cursos.json` como a matrícula, com título próprio ("Curso de Primeiros Socorros no Rio de Janeiro |
   Cruz Vermelha RJ"), H1, descrição, carga horária, escolaridade, valor, FAQ do curso, JSON-LD `Course`
   e botão para o checkout. É o que faz "curso de primeiros socorros cruz vermelha rj", "curso bombeiro
   civil rj" e "curso cuidador de idosos" pararem numa URL dedicada em vez da página geral.
3. **Search Console**: enviar `sitemap-index.xml`, pedir indexação de `/bio/` e das novas páginas,
   acompanhar CTR de "cruz vermelha rj" e "cruz vermelha" depois da troca de títulos.
4. **Perfil da Empresa no Google** (Praça da Cruz Vermelha, 10): resolve "cruz vermelha centro",
   "telefone", "endereço" e "hospital" no mapa, com horário e link para o site.
5. **Notícias (Redação)**: um texto por tema informacional que aparece nas buscas ("o que é a Lei
   Lucas", "quem pode fazer o curso de bombeiro civil", "primeiros socorros: o que se aprende"),
   cada um linkando para a página do curso.
