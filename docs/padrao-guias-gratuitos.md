# Padrão dos guias gratuitos

Criado em 11/11/2026, a pedido do Matheus. Um guia gratuito é sempre o mesmo conjunto de quatro peças. Guia novo
repete as quatro, sem inventar formato.

| Peça | O que é | Onde mora |
| --- | --- | --- |
| 1. Página | O guia completo, com fontes, fotos, perguntas e respostas. É o que o Google e os anúncios do Ad Grants encontram. | `site/institucional.json` → `scripts/gerar_institucional.py` |
| 2. PDF | Duas folhas A4 para imprimir e marcar. É o resumo da página, não outra versão dela. | `guias/<slug>.json` → `scripts/gerar_guia_pdf.py` |
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
| `lista_curta` | Itens de uma linha, em duas colunas. |
| `passos` | O que fazer, em ordem numerada. |
| `aviso` | Um recado em destaque. |
| `familia_e_emergencia` | Telefones da família para preencher e os telefones de emergência. |
| `datas` | As datas em que a família conferiu. |
| `curso` | O convite do curso, igual em todos. |

Regras fixas:

- **Duas folhas.** Não coube, encurta-se o texto, não a letra. O gerador sai com erro se passar.
- **Letra de 10 pt para cima** (o detalhe de cada item, 9,2 pt), alto contraste, nada que dependa de cor: o público
  tem mais de 50 anos.
- **A lista vem da página** (`da_pagina`), para o papel e a tela nunca dizerem coisas diferentes.
- **Sem texto de tela no papel** ("veja abaixo", "clique"). O gerador acusa; a troca vai em `no_papel`, na ficha.
- **Identidade, do Manual de Identidade Institucional, edição de 2022** (págs. 17 a 22): Montserrat nos títulos e
  Open Sans no texto; vermelho RGB 227/34/25 só em filete e detalhe, cinza-escuro `#1f2324` no texto, cinza
  `#a7afb2` nos fios e azul-marinho `#011e41` nos subtítulos; o emblema só dentro do logotipo, nunca solto. Caixa de
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
| Mochila de emergência | no ar | **modelo do padrão, gerado e não publicado** | não |
| Kit de primeiros socorros | no ar | falta | não |
| Lei Lucas | só matéria em notícias | falta | não |
