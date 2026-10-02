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

## Chat de contato por e-mail (19/09, à noite)

| Momento | Meta | GA4 |
| --- | --- | --- |
| Abre o chat (uma vez por sessão) | (nada) | `contato_aberto` (pagina) |
| Mensagem enviada com sucesso | `Contact` (content_category = assunto, content_name = curso ou assunto) | `contato_enviado` (assunto, curso, pagina) |

Os links que o chat oferece depois do envio ("Fazer matrícula em …", "Ver cursos e matrícula") são
navegação interna sem UTM, para não reiniciar a sessão do GA4. O e-mail de recuperação do PIX leva
`utm_source=email&utm_medium=transacional&utm_campaign=pix-aberto` no botão "Concluir pagamento":
quem volta por ele aparece no GA4 como tráfego desse e-mail.

## Carregamento adiado (19/09, à noite)

Os scripts `gtag.js` e `fbevents.js` passaram a carregar depois do `load` da página, em momento
ocioso (`requestIdleCallback`, no máximo 2,5 s). O trecho que cria as filas (`dataLayer` e o stub
`fbq`) continua no `<head>`, então `gtag('config')`, `fbq('init')`, `PageView` e qualquer evento
disparado antes ficam guardados e saem quando os scripts chegam. Conferido ao vivo depois da
mudança: PageView na home, matrícula, checkout, equipe e 404; InitiateCheckout, Lead,
AddPaymentInfo e Purchase no funil, com os mesmos parâmetros.

## Pixel travado de 27/09 a 02/10/2026 (corrigido)

Com o aviso de cookies (27/09), o bloco de medição passou a começar com `fbq('consent', 'revoke')`
antes do `fbq('init')`, e o `fbevents.js` só é baixado depois do "Aceitar todos". Nessa ordem a fila
do Pixel trava. Quando o script chega, a fila já tem `revoke`, `init`, `PageView` e, só no fim, o
`grant`. O Pixel processa o `revoke` e espera o consentimento para seguir com o `init`, mas o `grant`
está atrás na mesma fila e nunca é lido. O script baixava, e nenhum evento saía, nem para quem aceitou.

- **Como apareceu:** no Gerenciador de Eventos (conjunto `2224500131617302`), os PageView caíram de
  cerca de 200 por dia (18 a 25/09) para 1 a 3 por dia (30/09 e 01/10). Na matrícula, o Consultor de
  Dados de Anúncios (Pixel Helper) mostrava "instalado, mas não disparado". O app da Punção cria o
  `fbq` só depois do consentimento e não tinha o problema: a queda dele é a do próprio consentimento.
- **Correção (02/10):** a linha saiu do bloco da home e das 63 páginas que o copiam, e um comentário
  no lugar explica por quê. O `revoke` antes do `init` não faz falta, porque sem permissão o
  `fbevents.js` nem é baixado. Quem retira a permissão depois de dar continua passando por
  `fbq('consent', 'revoke')` (em `cvrjMedicao.aplicar`), e nessa hora o Pixel já está carregado e
  obedece.
- **Conferência:** `scripts/conferir_pixel.js` roda num Chromium, com os envios à Meta e ao GA4
  abortados. Ele exige PageView, ViewContent e InitiateCheckout depois de aceitar, e nada sem escolha,
  com "Rejeitar" ou depois de retirar a permissão. Antes da correção, 9 de 11 cenários falhavam no ar;
  com as páginas corrigidas (`--repositorio`), 11 de 11 passam. A conferência de 27/09 só olhava o
  download do script, e por isso o problema passou.
- **GA4:** não foi afetado. O gtag processa a fila em ordem, com o Consent Mode.
- **O que esperar:** só quem aceita marketing é medido (LGPD). Os números ficam abaixo dos de antes de
  27/09, quando o Pixel saía para todo mundo.

## O que a verificação mostrou e não é problema

- **Cada hit do GA4 sai duas vezes**, para `analytics.google.com` (`G-HDYZZ5JZHF`) e para
  `www.google-analytics.com` com **`G-Z5NWV4RBTT`**. Esse segundo ID não está no código: é um
  destino ligado à Google tag (Administrador → Fluxos de dados → Google tag → Gerenciar →
  Destinos). Ou é uma segunda propriedade sua de propósito, ou sobrou de alguma configuração;
  vale conferir. Dentro de cada propriedade não há contagem dupla.
- Eventos personalizados do GA4 saem em lote alguns segundos depois do `page_view`; é o
  comportamento normal do gtag.
- A página de matrícula e o checkout mudavam a URL (`?curso=`) a cada curso escolhido, e tanto o
  GA4 (medição aprimorada) quanto o Pixel contam mudança de histórico como nova visualização de
  página: gerava um `page_view`/`PageView` extra por clique. Removido em 19/09; a URL só muda por
  navegação real.
- O Pixel não envia nada quando detecta navegador automatizado; em navegador comum envia
  `PageView` e os eventos normalmente (conferido com o sinal de automação desligado).

## Pendências

1. **Escola**: GA4 `G-HDYZZ5JZHF` em todas as páginas (hoje a home não tem GA4 e `/cursos` usa
   outra propriedade) e aceitar o parâmetro `_gl` do linker (configuração `linker` no gtag da
   escola com `cruzvermelhariodejaneiro.org`). O site principal já decora os links para a escola.
2. **Vercel**: GA4 em `doar.` e `puncaovenosav1.`; Pixel `Donate`/`Purchase` na confirmação da
   doação, com `value` e `currency`.
3. **Redação**: Pixel nas notícias, termos e privacidade.
4. **API de Conversões do Meta** para o `Purchase` do checkout (servidor → Meta, com o mesmo
   `eventID`), quando houver volume: recupera as compras que o navegador não reporta. Só para quem deu
   permissão de marketing (o cookie `cvrj_consentimento` vai junto com a inscrição). O Gerenciador de
   Eventos estimava em 02/10 um custo por resultado 21,7% menor com mais eventos cobertos pela API.
5. **Correspondência avançada** (e-mail e telefone com hash, de quem deu permissão) no `Lead` e no
   `Purchase`: a qualidade da correspondência do PageView estava em 6,1/10 em 02/10.
6. **Redação**: o modelo das notícias (`lib/site/analytics.ts`) tem a mesma linha que travava o Pixel.
   Corrigir lá e usar o "Regerar" para atualizar as páginas publicadas.
