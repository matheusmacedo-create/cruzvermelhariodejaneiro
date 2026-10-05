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
O lembrete único do PIX (2 a 20 h depois, `api/lib/recuperacao.php`) usa `utm_campaign=pix-lembrete`, para separar
as duas recuperações no GA4.

## Página de matrícula (04/10/2026, redesenho)

O layout novo está descrito no README, seção "Página de matrícula: redesenho para conversão". Todos os eventos
passam pelo bloco de medição: sem "Aceitar", nada vai para a Meta.

| Momento | Meta | GA4 |
| --- | --- | --- |
| Chega com `?curso=<slug>` (anúncio) ou `#curso-<slug>`: uma vez | `ViewContent` + repasse à API de Conversões com o mesmo id | `view_item` (`origem=url`) |
| Abre "Ver detalhes" de um curso: uma vez por curso | `ViewContent` + repasse | `view_item` (`origem=detalhes`) |
| O catálogo ocupa 30% da tela: uma vez | (nada) | `view_item_list` (`item_list_id=matricula`, os cursos na ordem exibida) |
| O 1º botão de matrícula fica 50% visível por 1 s: uma vez | (nada) | `cta_matricula_visto` (`curso`, `local`, `modo`) |
| Clique em qualquer botão de matrícula | (nada: o `InitiateCheckout` sai no checkout) | `select_item` (`local` = ficha, cartao, detalhes, comparar, barra ou faq_final; `modo` = curso ou geral) |
| A barra fixa aparece pela 1ª vez (celular) | (nada) | `barra_fixa_vista` (`curso`) |
| Abre uma pergunta, uma vez por pergunta | (nada) | `faq_aberta` (`bloco` = pagina, curso, turmas ou comparar; `pergunta`; `curso`) |
| Uma seção ocupa 40% da tela: uma vez | (nada) | `secao_vista` (`secao`, `modo`) |
| Clique num atalho do chat | (nada) | `chat_atalho` (`local`, `curso`) |
| Clique num link para a plataforma da escola | `SaidaEscola` (`trackCustom`) | `saida_escola` (`local` = cabecalho, faq ou rodape; `curso`; `destino`) |

O endereço do checkout leva `via=<lugar do botão>` (fora de `utm_`, para não reiniciar a sessão). Ele aparece no
`page_location` do `begin_checkout`.

**Para configurar no GA4,** como dimensões personalizadas de evento: `curso`, `origem`, `local`, `modo`,
`secao`, `pergunta`, `bloco`, `destino` e `tela`. O registro não vale para trás.

**Para ler o antes e o depois:**

- compare as **taxas** (`select_item`/`page_view`, `begin_checkout`/`page_view`, `purchase`/`page_view`) das 2
  semanas antes e das 2 depois da publicação, por dispositivo e origem;
- `saida_escola`/`page_view` mostra quanto ainda sai para a escola.

**Na Meta:** o `ViewContent` passa a sair também ao chegar pelo anúncio com `?curso=`, então ele sobe. Anote a
data da publicação se alguma campanha otimizar por ele.

## Turmas sob demanda (04/10/2026)

| Momento | Meta | GA4 |
| --- | --- | --- |
| Pedido de turma fechada ou entrada na lista de interesse | `SubmitApplication` (content_category = `turma-fechada` ou `turma-lista`, content_name = curso) e o mesmo evento pela API de Conversões, com o mesmo id | `turma_pedido` (turma_tipo, curso, idioma, alunos) |
| Clique num botão ou link que abre o formulário | (nada) | `turma_abrir` (origem, curso) |

Não usa `Lead` de propósito: o `Lead` é do funil pago (envio do formulário do checkout).

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
| AddPaymentInfo | `api/pagamentos.php`, cobrança aceita | `<id da compra>-pagamento` | idem |
| Purchase | `mcp_pos_pagamento` (postback, consulta de status ou cartão aprovado) | `<id da compra>` (também o `order_id` e, no GA, o `transaction_id`) | idem, com os sinais guardados |

O **id da compra** (`c.` + 32 caracteres) é um hash do token da inscrição (`mcp_meta_id_da_compra`), e as
páginas o recebem de `status.php` como `id_compra`. O token abre a inscrição (e-mail, PIX e, depois do
pagamento, o link de criar senha na escola) e não pode aparecer no Gerenciador de Eventos nem no GA. Até a
revisão de 02/10 à tarde, o Pixel e o GA recebiam o próprio token.
| Contact | `api/contato.php` (chat) | `ct.…` (vai no corpo da mensagem) | nome, e-mail e telefone (se houver) em hash |

- **Consentimento:** só com marketing ligado (`m=1` no cookie `cvrj_consentimento`) **e** a revisão atual do
  texto do aviso no cookie (`r=2`; `MCP_META_REVISAO` em `api/lib/meta.php` e `REVISAO` em
  `site/consentimento/consentimento.js`). Só o aviso atual grava o `r`. Um "sim" sem ele não vale para o
  servidor: veio do texto anterior (que dizia que nome, e-mail e telefone nunca iam à Meta), do aviso da
  Punção (que grava o mesmo cookie em `.cruzvermelhariodejaneiro.org`) ou de um `consentimento.js` antigo
  guardado no cache. O aviso pergunta de novo a essa pessoa, e o painel "Personalizar" abre com o marketing
  desligado (nada pré-marcado). O Pixel no navegador segue como antes. Enquanto o aviso pergunta de novo, o
  bloco de medição segura os repasses dessa página (`pendentes`) e os manda depois do "sim", com os mesmos
  ids. Os outros sites leem só `v`, `e` e `m` e ignoram o `r`. Um `t` mais de um dia no futuro, ou um `r` com
  mais de dois dígitos, também não vale. Mudou o texto do que vai à Meta: sobe os três números juntos
  (`MCP_META_REVISAO`, `REVISAO` no aviso e `REVISAO` no bloco de medição da home). A primeira versão (14h50) usava o `t` do cookie e uma data de corte, e isso não dizia
  qual texto a pessoa viu.
- **O que nunca vai:** CPF (os Termos das Ferramentas de Negócios proíbem números de documento), mensagem do
  chat, dados do cartão e o token das páginas de acompanhamento (o `t=` sai do endereço; os ids são o id da
  compra). O `external_id` é o SHA-256 do token, que só liga os eventos da mesma inscrição e não o revela.
- **O que fica guardado na inscrição:** a escolha de marketing feita ao se inscrever, a data dela e a
  revisão do texto (`meta_marketing`, `meta_marketing_em`, `meta_revisao`, prova do consentimento) e, só com
  "sim", o IP, `_fbp`, `_fbc` e a identificação do navegador (`meta_ip`, `meta_fbp`, `meta_fbc`, `meta_ua`).
  Os quatro últimos servem só ao Purchase confirmado sem navegador: saem quando ele é aceito pela Meta ou, no
  máximo, em 8 dias (faxina na rotina de 15 minutos, antes das outras tarefas). O Purchase usa esse IP, não o
  IP de segurança da inscrição, e só sai com `meta_revisao` atual.
- **Depois da inscrição, a permissão só pode ser retirada, nunca dada:** quem abre o link da inscrição
  pendente (`status.php`) ou refaz o PIX com o mesmo CPF (`pagamentos.php`, antes da reconsulta) com "não"
  no cookie apaga o "sim" e os sinais, e a troca fica em `mcp_eventos` (`meta_escolha`, com a escolha
  anterior). Um "sim" de quem abre o link não vale, porque o link pode estar com outra pessoa: a mãe que
  paga, a secretaria. Depois do pagamento, nada muda. O link "como o aluno vê" do painel leva `painel=1` e
  não dispara o Purchase do Pixel.
- **Uma compra, um Purchase:** o servidor manda o Purchase na hora do pagamento, e a Meta só junta com o do
  Pixel em 48 h. A tela Parabéns não manda o do Pixel quando o pagamento tem mais de 24 h (outro navegador,
  o e-mail de confirmação, a página de horários) nem quando vem do painel.
- **Freios do repasse (`medicao.php`):** 120 por minuto no site inteiro, e por IP (o /64 no IPv6) 20 por
  minuto e 120 em 10 minutos. Acima disso, os eventos de página ficam só com o Pixel. Quando o teto do site é
  atingido, fica `meta_capi_aviso` `teto` e uma linha no log, no máximo a cada 10 minutos. As contagens usam
  o índice `(tipo, criado_em)` e leem no máximo algumas centenas de linhas. Corpo inválido (evento em lista, id com
  quebra de linha) responde 204 e não vai a lugar nenhum.
- **fbclid longo:** o `_fbc` do navegador vai inteiro (até 600 caracteres; os anúncios de hoje passam de
  200). O fbc montado a partir do fbclid do endereço só vai até 254 caracteres: as páginas e o banco guardam
  255, e um fbclid cortado não pode ir (a Meta proíbe mexer nele).
- **A inscrição nunca depende da Meta:** a escolha e os sinais (`meta_*`) são gravados num UPDATE à parte,
  depois do INSERT da inscrição, porque a cobrança já existe no provedor. Se esse UPDATE falhar (um valor
  estranho no cookie, uma coluna nova que ainda não chegou ao banco), a inscrição fica gravada, a escolha
  fica vazia e o Purchase não sai.
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
- **Publicado em 02/10/2026, por volta das 14h50 (Brasília):** os 75 arquivos de `scripts/publicacao-capi.txt`,
  depois de copiar os do ar (iguais à versão anterior do repositório). A migração rodou na primeira consulta
  (sem 503), `conferir_publicacao.sh` e `conferir_pixel.js` passaram no ar, e o aviso volta a perguntar só a
  quem tinha ligado o marketing antes de 14h53 (`REVISAO` = `MCP_META_CONSENTIMENTO_DESDE` = 1790963580).
  Sem o token no servidor, a parte do servidor está desligada: o repasse responde 204 e nada é guardado.
- **Revisão adversarial (02/10, à tarde)**, com quatro revisores (segurança, LGPD, especificação da Meta e
  regressões) e um verificador para cada achado. Confirmados e corrigidos: o token ia à Meta como
  `event_id`/`order_id`; quem tinha o link dava o "sim" no lugar do aluno; o "sim" do aviso da Punção ou de
  um aviso antigo valia como o do texto novo; "Personalizar" abria com o marketing pré-marcado; o painel
  sobrescrevia a prova do consentimento; a retirada no PIX refeito não valia; o Purchase do Pixel contava
  duas vezes depois de 48 h; o repasse não tinha teto global; havia o 500 com evento em lista e os padrões
  aceitavam `\n` no fim; o fbclid longo perdia o fbc; e `config-meta.php` não estava no `.gitignore`. Na
  segunda rodada (regressões): as colunas `meta_*` estavam no INSERT feito depois da cobrança (um `r=999` ou
  uma coluna nova ausente derrubava a inscrição com o PIX já gerado); quem era perguntado de novo perdia o
  repasse da página em que respondia; e o teto do site se esgotava sem registro.
  Os verificadores não confirmaram dois achados como defeito, mas eles também mudaram: o IP do Purchase
  passou a ser guardado à parte (`meta_ip`), e a faxina ganhou um try próprio.
- **Correções publicadas em 02/10/2026, às 15h37 (Brasília):** os 70 arquivos de
  `scripts/publicacao-capi-revisao.txt`, depois de copiar os do ar (os 70 eram iguais ao `355d3ae`, a
  publicação das 14h50). A migração rodou na primeira consulta (`status.php` respondeu 404, sem 503);
  `conferir_publicacao.sh` deu "Tudo certo", os PHP e os estáticos no servidor são iguais ao repositório,
  byte a byte, `conferir_pixel.js` passou nos 12 cenários no ar e o aviso, no ar, pergunta de novo a quem
  tem "sim" sem `r` e grava `r=2`. Quem aceitou entre 14h50 e 15h37 (o aviso ainda não gravava o `r`) é
  perguntado mais uma vez. A parte do servidor continua desligada até o token.
- **Testes:** `php scripts/testar_checkout.php` (normalização, hash, consentimento com `r`, id da compra,
  fila, URL limpa), `scripts/testar_meta_integracao.php` (36 cenários de ponta a ponta com MariaDB local e
  uma Meta, uma Unicopag e uma Resend falsas, incluindo o link aberto por outra pessoa, o PIX refeito, os
  freios, o `r=999` e a coluna ausente) e `scripts/conferir_pixel.js` (no navegador, o mesmo id no Pixel e
  no repasse, também para quem responde ao aviso de novo; nada sem permissão).

## Vendas de 03/10 sem Compra no Gerenciador (auditoria de 04/10/2026)

As duas vendas de 03/10 (20h21 e 20h23, cartão, "Taxa de inscrição — Primeiros Socorros Básico") caíram na
conta **CVB** da Únicopag, a da **plataforma da escola**. O checkout deste site cobra pela conta "Matricula
automatica", com o produto "<Curso> — Inscrição" (`api/pagamentos.php`), e não teve venda nesse dia. A página
de matrícula leva à escola pelo link "Veja este curso na plataforma da escola" de cada curso. O caminho exato
das duas pessoas não aparece: as transações chegam sem origem nem campanha.

Nenhum caminho avisava a Meta dessas compras:

- **O Pixel da escola está travado**, com o mesmo defeito corrigido aqui em 02/10: o bloco de medição da escola
  (`escola.cursoscruzvermelha.org`, todas as páginas conferidas no ar em 04/10) tem `fbq('consent', 'revoke');`
  antes do `fbq('init', '2224500131617302')`, e o `fbevents.js` só é baixado depois do "Aceitar". A fila para no
  `revoke` e nenhum evento sai, nem de quem aceitou. Correção, no repositório da escola: apagar só essa linha.
  O `aplicar()` do mesmo bloco já chama `fbq('consent', c.marketing ? 'grant' : 'revoke')` com o Pixel carregado.
- **O pagamento da escola acontece na página da Únicopag**, que o Pixel não vê. Quem avisa é a API de Conversões
  da Redação (ARQUITETURA §8.6), que lê as vendas da Únicopag. Em 04/10 ela ainda não estava ligada.
- **A API de Conversões deste site estava sem o token** (`config-meta.php`). Isso não afetou essas duas
  vendas. O token entrou no servidor em 04/10, às 17h26 (Pendências, item 4).

Conferência das páginas no ar (sitemaps e páginas do funil, 83 endereços): todas as deste domínio têm o bloco
corrigido, menos duas, ambas geradas pela Redação:

- `/acervo/` (publicada em 24/09) está **sem** medição nenhuma. Ela volta com "Atualizar as páginas do acervo", na área Acervo da Redação.
- `/transparencia/` (26/09) está com o **bloco antigo**, que baixa o Pixel e o GA4 sem perguntar. Ela volta
  com "Atualizar a página no site", na área Transparência da Redação.

A Redação não pode mandar as vendas da conta "Matricula automatica": o aviso deste site promete que nome, e-mail
e telefone só vão à Meta com o marketing ligado, e a compra seria contada duas vezes (o id do Pixel é o id da
compra, o da Redação é `unicopag:<hash>`). Por isso matheusmacedo-create/redacao-cruzvermelhariodejaneiro#296 dá à
Redação a escolha das contas (só a CVB marcada) e tira o CPF do envio. Migração aplicada, PR mesclado e publicado em
04/10; o envio foi ligado às 17h01, só para a conta CVB (página padrão `https://escola.cursoscruzvermelha.org/`).

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
3. **Redação**: Pixel nas notícias, termos e privacidade. Feito: em 04/10 as 19 matérias, o índice, termos e
   privacidade estavam com o bloco corrigido. Faltam `/acervo/` e `/transparencia/` (seção de 04/10).
4. **API de Conversões**: feita em 02/10/2026 (seção acima). Token no servidor desde 04/10/2026, 17h26
   (`config-meta.php`, criado pelo conector da Hostinger; responde 403 pelo navegador). É o token do conjunto de
   dados (permissão `read_ads_dataset_quality`): envia eventos, mas não lê o pixel (`GET /{pixel}` dá "Missing
   Permission"). Ele passou pela conversa em que foi configurado: trocar por um novo quando possível.
5. **Correspondência avançada no navegador** (e-mail e telefone em hash no `fbq('init')` de quem deu
   permissão): o servidor já manda esses dados na inscrição; no navegador, é opcional.
6. **Redação**: o modelo das notícias (`lib/site/analytics.ts`) tinha a mesma linha que travava o
   Pixel; corrigido em matheusmacedo-create/redacao-cruzvermelhariodejaneiro#287. Depois do merge,
   usar o "Regerar". Em 02/10, só o índice `/noticias/` tinha o bloco que trava; 17 das 19 matérias
   estavam sem Pixel, e uma matéria e `/transparencia/` tinham o bloco antigo, que baixa o Pixel
   sem perguntar.
7. **Escola**: feito em 04/10 (matheusnsp/ESCOLA_CRUZ_VERMELHA#77, publicado às ~18h20). O `revoke` saiu de
   antes do `init`, e a conferência no navegador mostrou o PageView depois do "Aceitar todos". A política da
   escola passou a citar as compras avisadas à Meta (legítimo interesse, com oposição). Antes da correção, o Pixel
   da escola não mandava nada. A política de privacidade da escola diz que à Meta vão só as páginas vistas, e só
   com permissão. Antes de ligar a API de Conversões da Redação para a conta CVB, ela precisa dizer também que
   a compra (nome, e-mail e telefone em código) vai à Meta, e com qual base legal.
