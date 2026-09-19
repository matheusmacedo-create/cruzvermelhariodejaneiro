# Rastreamento (GA4 e Meta Pixel), revisado em 19/09/2026

IDs em uso: GA4 `G-HDYZZ5JZHF` e Meta Pixel `2224500131617302`, os mesmos em todas as páginas
deste repositório. A verificação foi feita ao vivo, no navegador, capturando o que cada página envia
e interceptando as chamadas `gtag()` e `fbq()`; o fluxo do checkout foi testado com a resposta da
API simulada, sem criar transação.

## Cobertura por página

| Página | GA4 | Pixel | Observação |
| --- | --- | --- | --- |
| Home, Equipe, Matrícula, Checkout, Pendente, Parabéns | sim | sim | já estavam |
| Doação, Campanha do Agasalho | sim | **sim (adicionado em 19/09)** | não tinham o Pixel |
| 404 | **sim (19/09)** | **sim (19/09)** | não tinha nada; ajuda a achar links quebrados |
| Notícias, Termos, Privacidade (Redação) | sim | não | Pixel a adicionar no projeto da Redação |
| `doar.` (Vercel) | **não** | sim | GA4 a adicionar |
| `puncaovenosav1.` (Vercel) | **não** | sim | GA4 a adicionar |
| `projetocores.` | sim | sim | ok |
| Escola: home | **não** | sim | |
| Escola: `/cursos` | `G-3JVMYP3BBS` (outra propriedade) | sim | usar a mesma propriedade em todo o app |
| Redação (ferramenta interna) | não | não | correto |

## Funil da matrícula (eventos)

| Momento | Meta | GA4 (evento padrão de comércio) |
| --- | --- | --- |
| Abre um curso na página de matrícula | `ViewContent` | `view_item` (items, value 99, currency BRL) |
| Clica em "Fazer matrícula" na matrícula | (nada: o InitiateCheckout vem a seguir) | `select_item` |
| Carrega o checkout com curso escolhido | `InitiateCheckout` | `begin_checkout` |
| Envia o formulário | `Lead` | `generate_lead` (curso, método) |
| Provedor aceita PIX ou cartão | `AddPaymentInfo` (eventID `<token>-pagamento`) | `add_payment_info` (payment_type pix/cartao) |
| Tela Parabéns com status pago | `Purchase` (eventID = token) | `purchase` (transaction_id = token, items, value com custos) |

`Purchase`/`purchase` dispara uma vez por inscrição (marca no `localStorage`), e só quando a API
confirma `pago`. Os `eventID` permitem deduplicar com a API de Conversões do Meta, se ela for ligada.
Antes de 19/09 o GA4 recebia nomes próprios (`matricula_dados`, `matricula_pix_gerado`) que não
alimentam os relatórios de funil; `AddPaymentInfo` só disparava no PIX; `Purchase` não tinha
`transaction_id`; o `InitiateCheckout` disparava no clique da matrícula e não cobria quem entrava
no checkout pela home.

## O que a verificação mostrou e não é problema

- **Cada hit do GA4 sai duas vezes**, para `analytics.google.com` (`G-HDYZZ5JZHF`) e para
  `www.google-analytics.com` com **`G-Z5NWV4RBTT`**. Esse segundo ID não está no código: é um
  destino ligado à Google tag (Administrador → Fluxos de dados → Google tag → Gerenciar →
  Destinos). Ou é uma segunda propriedade sua de propósito, ou sobrou de alguma configuração;
  vale conferir. Dentro de cada propriedade não há contagem dupla.
- Eventos personalizados do GA4 saem em lote alguns segundos depois do `page_view`; é o
  comportamento normal do gtag.
- O Pixel não envia nada quando detecta navegador automatizado; em navegador comum envia
  `PageView` e os eventos normalmente (conferido com o sinal de automação desligado).

## Pendências

1. **GA4 Admin**: em Medição aprimorada → Visualizações de página, desligar "Mudanças de página
   com base em eventos do histórico do navegador". A página de matrícula muda a URL (`?curso=`)
   a cada curso aberto e isso gera um `page_view` extra por clique.
2. **Escola**: GA4 `G-HDYZZ5JZHF` em todas as páginas (hoje a home não tem GA4 e `/cursos` usa
   outra propriedade) e aceitar o parâmetro `_gl` do linker (configuração `linker` no gtag da
   escola com `cruzvermelhariodejaneiro.org`). O site principal já decora os links para a escola.
3. **Vercel**: GA4 em `doar.` e `puncaovenosav1.`; Pixel `Donate`/`Purchase` na confirmação da
   doação, com `value` e `currency`.
4. **Redação**: Pixel nas notícias, termos e privacidade.
5. **API de Conversões do Meta** para o `Purchase` do checkout (servidor → Meta, com o mesmo
   `eventID`), quando houver volume: recupera as compras que o navegador não reporta.
