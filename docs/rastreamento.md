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
- **Publicado em 02/10/2026, por volta das 8h55:** as 61 páginas de `scripts/publicacao-pixel.txt`,
  depois de copiar as do ar. Conferido em seguida: as 61 estão iguais ao repositório, byte a byte, e
  `scripts/conferir_pixel.js` passou nos 11 cenários no ar.
- **GA4:** não foi afetado. O gtag processa a fila em ordem, com o Consent Mode.
- **O que esperar:** só quem aceita marketing é medido (LGPD). Os números ficam abaixo dos de antes de
  27/09, quando o Pixel saía para todo mundo.

## API de Conversões da Meta (02/10/2026)

O servidor do site manda à Meta os mesmos eventos do Pixel, com o mesmo id (`event_id` = `eventID`). A Meta
junta os dois num só (deduplicação, até 48 h). Quem tem bloqueador de anúncio continua medido, e os eventos
da inscrição chegam com nome, e-mail e telefone em hash, o que melhora a qualidade da correspondência.

| Evento | De onde sai no servidor | id (igual ao do Pixel) | Dados da pessoa |
| --- | --- | --- | --- |
| PageView | `api/medicao.php` (repasse do bloco de medição) | `window.cvrjIdPageView` | não (IP, navegador, _fbp/_fbc) |
| ViewContent | `api/medicao.php` (página de matrícula) | `vc.…` | não |
| InitiateCheckout | `api/medicao.php` (checkout) | `ic.…` | não |
| Lead | `api/pagamentos.php`, com a inscrição criada | `lead.…` (vai no corpo da inscrição) | nome, e-mail, telefone em hash |
| AddPaymentInfo | `api/pagamentos.php`, cobrança aceita | `<token>-pagamento` | idem |
| Purchase | `mcp_pos_pagamento` (postback, consulta de status ou cartão aprovado) | `<token>` | idem, com os sinais guardados |
| Contact | `api/contato.php` (chat) | `ct.…` (vai no corpo da mensagem) | nome, e-mail e telefone (se houver) em hash |

- **Consentimento:** só com marketing ligado (`m=1` no cookie `cvrj_consentimento`) e escolhido depois de
  `MCP_META_CONSENTIMENTO_DESDE` (em `api/lib/meta.php`). Até esta revisão, o aviso e a política diziam que
  nome, e-mail e telefone nunca iam à Meta. Por isso, quem tinha ligado o marketing antes é perguntado de
  novo: é o `REVISAO` de `site/consentimento/consentimento.js`, o mesmo instante. Até a pessoa escolher, o
  Pixel segue como antes e o servidor não manda nada. **Na publicação, os dois valores vão para a hora da
  publicação.**
- **O que nunca vai:** CPF (os Termos das Ferramentas de Negócios proíbem números de documento), mensagem do
  chat, dados do cartão e o token das páginas de acompanhamento (o `t=` sai do endereço). O `external_id` é
  o token da inscrição em hash, que só liga os eventos da mesma inscrição.
- **O que fica guardado na inscrição:** a escolha de marketing e a data dela (`meta_marketing`,
  `meta_marketing_em`, prova do consentimento) e, só com "sim", `_fbp`, `_fbc` e a identificação do
  navegador (`meta_fbp`, `meta_fbc`, `meta_ua`). Os três últimos servem só ao Purchase confirmado sem
  navegador: saem quando ele é aceito pela Meta ou, no máximo, em 8 dias (faxina na rotina de 15 minutos).
  A consulta de status feita pelo próprio aluno atualiza a escolha, então retirar a permissão vale para o
  Purchase.
- **Nunca atrapalha o aluno:** os eventos vão para uma fila e saem depois da resposta
  (`fastcgi_finish_request`/`litespeed_finish_request`), com tempo curto. Um evento de site sem a
  identificação do navegador não entra na fila, porque a Meta recusaria o lote inteiro.
- **Acompanhamento:** cada envio de uma inscrição fica em `mcp_eventos` (`meta_capi` com o HTTP, ou
  `meta_capi_falha` com a mensagem da Meta, como token inválido). Dos repasses anônimos, só as falhas, uma a
  cada 10 minutos. Quando a Meta avisa que a versão da API está vencendo (`x-ad-api-version-warning`), fica
  `meta_capi_aviso` e uma linha no log de erros, uma vez por dia.
- **Versão:** `v25.0`, a atual da Marketing API. A Conversions API segue esse calendário, e a v24 vence em
  06/10/2026. Para trocar sem publicar código, use `META_CAPI_VERSAO`.
- **Para ligar:** crie `api/config-meta.php` no servidor (veja o README) com `META_CAPI_TOKEN`. Para
  conferir na aba "Testar eventos" do Gerenciador, use também `META_CAPI_TESTE` com o código da aba, e tire
  depois. Um `config-meta.php` com erro de digitação desliga só a API de Conversões; o checkout segue.
- **Testes:** `php scripts/testar_checkout.php` (normalização, hash, consentimento, fila, URL limpa),
  `scripts/testar_meta_integracao.php` (25 cenários de ponta a ponta com MariaDB local e uma Meta, uma
  Unicopag e uma Resend falsas) e `scripts/conferir_pixel.js` (no navegador, o mesmo id no Pixel e no
  repasse; nada sem permissão).

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
4. **API de Conversões**: feita em 02/10/2026 (seção acima). Falta o token no servidor (`config-meta.php`).
5. **Correspondência avançada no navegador** (e-mail e telefone em hash no `fbq('init')` de quem deu
   permissão): o servidor já manda esses dados na inscrição; no navegador, é opcional.
6. **Redação**: o modelo das notícias (`lib/site/analytics.ts`) tinha a mesma linha que travava o
   Pixel; corrigido em matheusmacedo-create/redacao-cruzvermelhariodejaneiro#287. Depois do merge,
   usar o "Regerar". Em 02/10, só o índice `/noticias/` tinha o bloco que trava; 17 das 19 matérias
   estavam sem Pixel, e uma matéria e `/transparencia/` tinham o bloco antigo, que baixa o Pixel
   sem perguntar.
