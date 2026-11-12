# Padrão dos guias gratuitos

Criado em 11/11/2026, a pedido do Matheus. Um guia gratuito é sempre o mesmo conjunto de quatro peças. Guia novo
repete as quatro, sem inventar formato.

| Peça | O que é | Onde mora |
| --- | --- | --- |
| 1. Página | O guia completo, com fontes, fotos, perguntas e respostas. É o que o Google e os anúncios do Ad Grants encontram. | `site/institucional.json` → `scripts/gerar_institucional.py` |
| 2. PDF | Duas folhas A4 para imprimir e marcar e uma terceira com todas as fontes. É o resumo da página, não outra versão dela. | `guias/<slug>.json` → `scripts/gerar_guia_pdf.py` |
| 3. Entrega | A caixa que pede o e-mail, o aceite e a mensagem com o botão de baixar. | Redação, `lib/newsletter/materiais.ts` |
| 4. Medida | Dois eventos no GA4: `<id>_pedir` (deixou o e-mail) e `<id>_baixar` (tocou no botão). | `docs/rastreamento.md` |

## 1. A página

Endereço curto, com o termo que as pessoas buscam (`/mochila-de-emergencia/`). Seções, nesta ordem:

1. **O que é**, em um parágrafo que responde à busca.
2. **A lista**, numa tabela de três colunas (item, quanto, detalhe) ou num `<ul>`. É dela que o PDF sai.
3. **Como fazer**: montar, guardar, conferir.
4. **Para cada pessoa**: bebês, idosos, pessoas com deficiência, animais (quando couber).
5. **Quando usar**, com os telefones 192, 193 e 199.
6. **Aprenda**, com o convite do curso e a frase de que o curso é pago e vendido pela escola.
7. Perguntas e respostas, fontes e páginas relacionadas.

Toda afirmação de saúde ou de segurança tem fonte oficial com link, e passa por revisão de fatos antes de publicar.

## 2. O PDF

A ficha `guias/<slug>.json` diz o que vai em cada folha; o desenho fica só no gerador. Blocos disponíveis:

| Bloco | Para quê |
| --- | --- |
| `lista_detalhada` | A lista principal, com quadradinho, quantidade e motivo. Com `da_pagina`, lê a tabela da página. |
| `lista_curta` | Itens de uma linha, em duas colunas. Com `sem_fonte`, tira o “(St John)” do fim de cada item. |
| `passos` | O que fazer, em ordem numerada. |
| `aviso` | Um recado em destaque. |
| `familia_e_emergencia` | Telefones da família para preencher e os telefones de emergência. Aceita `titulo`, `ponto_de_encontro` e `dica`. |
| `datas` | As datas em que a família conferiu. |
| `curso` | O convite do curso, igual em todos. |
| `checklist` | O que fazer, com quadradinho: título e uma linha de detalhe, sem a coluna "quanto" (11/11/2026, Lei Lucas). |
| `ficha` | Tabela para preencher: `colunas` e `linhas` em branco, ou `rotulos` na 1ª coluna; `altura_mm` opcional. |
| `campos` | Rótulos com uma linha ao lado para escrever ("Escola:", "Ponto de encontro:"). |
| `perguntas` | Perguntas numeradas, com uma linha de explicação e `linhas` para a resposta (plano de contingência). |
| `emergencia` | O cartaz: `passos` à esquerda, `telefones` e um `campo` para preencher à direita, num quadro vermelho. |

Com `"capa"` na ficha, o gerador grava também a folha 1 em WebP de 480 × 679, que a página mostra ao lado do formulário.

Regras fixas:

- **Duas folhas de conteúdo e uma de fontes.** Não coube, encurta-se o texto, não a letra. O gerador sai com erro
  se passar.
- **Todas as fontes, no papel.** A última folha é montada sozinha com as fontes da página, nome e endereço por
  extenso. Antes de gerar, confira se cada endereço ainda responde; fonte que saiu do ar se troca na página.
- **Letra de 10 pt para cima** (o detalhe de cada item, 9,2 pt), alto contraste, nada que dependa de cor: o público
  tem mais de 50 anos.
- **A lista vem da página** (`da_pagina`), para o papel e a tela nunca dizerem coisas diferentes.
- **Sem texto de tela no papel** ("veja abaixo", "clique"). O gerador acusa; a troca vai em `no_papel`, na ficha.
- **Identidade, do Manual de Identidade Institucional, edição de 2022** (págs. 17 a 22): Montserrat nos títulos e
  Open Sans no texto; vermelho RGB 227/34/25 só em filete e detalhe, cinza-escuro `#1f2324` no texto, cinza
  `#a7afb2` nos fios e azul-marinho `#011e41` nos subtítulos; o emblema só dentro do logotipo, nunca solto.
  **O logotipo é sempre o da filial, com “Rio de Janeiro”** (decisão do Matheus, 11/11/2026), mesmo onde o manual
  pede o logotipo nacional sem o nome da filial. Caixa de
  primeiros socorros se marca com cruz branca em fundo verde.
- **Nome do arquivo:** `<slug>-cruz-vermelha-rj.pdf`, na pasta da página. Depois de publicado, o endereço não muda.
- **Rodapé:** de quem são as listas, onde estão as fontes, a data e "Pode copiar e distribuir, sem alterar".

Gerar e conferir: `python3 scripts/gerar_guia_pdf.py <slug>` e `pdffonts` no arquivo (todas as fontes TrueType).

## 3. A entrega e 4. a medida

Um registro novo em `lib/newsletter/materiais.ts`, na Redação, copiando o do kit de emergência: nome, arquivo,
texto do aceite, recados, e-mail e o evento `<id>_pedir`. A ordem de publicação é a de sempre: o PDF no ar, depois
a página que aponta para ele, depois o PR da Redação, por fim os sitemaps.

## Estado dos guias

| Guia | Página | PDF no padrão | Entrega por e-mail |
| --- | --- | --- | --- |
| Kit de emergência | no ar | PDF próprio, de antes do padrão (`gerar_pdf_kit.py`) | sim |
| Mochila de emergência | no ar; seção `#baixar` com o formulário gerada e não publicada (11/11/2026) | no padrão, com capa, gerado e não publicado | sim: formulário na página e caixa nas matérias da sirene e de deslizamento (não publicado) |
| Kit de primeiros socorros | no ar | **primeiro guia completo no padrão, gerado e não publicado** | sim: formulário na página e caixa nas matérias de primeiros socorros (não publicado) |
| Lei Lucas para escolas | `/lei-lucas-para-escolas/`, gerada e não publicada (11/11/2026) | no padrão, gerado e não publicado: checklist, ficha dos capacitados e cartaz de emergência | sim (não publicado) |
| Plano de contingência | `/plano-de-contingencia/`, gerada e não publicada (11/11/2026) | no padrão, gerado e não publicado: perguntas com linhas, ficha de quem faz o quê e níveis de alerta | sim (não publicado) |
| Quando ligar 192 | `/quando-ligar-192/`, gerada e não publicada (11/11/2026) | no padrão, gerado e não publicado: cartaz com o que dizer ao 192, teste SAMU, sinais de infarto e engasgo | sim: formulário na página e caixa nas matérias de AVC, infarto, engasgo e desmaio (não publicado) |
| Verão seguro no Rio | `/verao-seguro/`, gerada e não publicada (11/11/2026) | no padrão, gerado e não publicado | sim: formulário na página e caixa nas matérias de calor, insolação, afogamento e praia (não publicado) |
| Plano familiar de emergência | `/plano-familiar-de-emergencia/`, gerada e não publicada (11/11/2026) | no padrão, gerado e não publicado: ficha para a família preencher | sim: formulário na página e caixa nas matérias do plano, dos alertas e dos estágios (não publicado) |
| Guia do cuidador de idosos | `/cuidador-de-idosos/`, gerada e não publicada (12/11/2026) | no padrão, gerado e não publicado | sim: formulário na página e caixa em 5 matérias de cuidado com idosos (não publicado) |
| Acidentes em casa | `/acidentes-em-casa/`, gerada e não publicada (12/11/2026) | no padrão, gerado e não publicado: cartaz das picadas e do choque elétrico | sim: formulário na página e caixa nas matérias de cobra, escorpião e choque (não publicado) |
| A Cruz Vermelha em uma página | `/cartaz-da-cruz-vermelha/`, gerada e não publicada (12/11/2026) | no padrão, gerado e não publicado: princípios, emblemas, Movimento e linha do tempo, tirados das páginas institucionais | sim: formulário na página e caixa em 19 matérias de história, Movimento e direito humanitário (não publicado) |
| Checklist contra a dengue | `/checklist-dengue/`, gerada e não publicada (12/11/2026) | no padrão, gerado e não publicado | sim: formulário na página e caixa na matéria da dengue (não publicado) |

Os quatro últimos e a entrega da mochila saíram juntos em 11/11/2026 (pedido do Matheus: mais guias, mais matérias com
caixa de e-mail). A lista de publicação é `scripts/publicacao-guias-novembro.txt`. O conteúdo de saúde do cartaz
"Quando ligar 192" (as manobras de engasgo, sobretudo) e do guia do verão precisa do visto de um instrutor da escola
antes de ir ao ar: as pendências de cada um estão em `notas_internas`, em `site/institucional.json`.

A segunda leva (12/11/2026: cuidador de idosos, acidentes em casa, a Cruz Vermelha em uma página e a dengue) tem a
lista em `scripts/publicacao-guias-novembro-2.txt`. Com ela e com a caixa do kit Lei Lucas em três matérias, a Redação
passa a ter caixa de material em 58 matérias.
