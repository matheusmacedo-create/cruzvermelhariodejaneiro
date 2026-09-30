# Comunicação do ponto da sede

Lembretes de entrada e saída, comunicados da implantação e a opinião de todos depois de duas
semanas. Construído em 30/09/2026, em cima do ponto da sede (ver "Ponto da sede" no
[README](../README.md)). **Tudo começa desligado:** nada sai sem a secretaria ligar um lembrete ou
agendar um comunicado no portal, em **Comunicação**.

Código: `site/matricula-cursos-presenciais/api/lib/avisos.php` (fila, envio, canais, lembretes),
`lib/comunicacao.php` (textos, comunicados, opinião, saída informada, importação),
`lib/metricas.php` (resultados), `lib/painel_comunicacao.php` (telas do portal), `api/avisos.php`
(páginas pessoais e cliques), `api/whatsapp.php` (webhook da API oficial), `static/avisos.js`.

## Quem recebe o quê

| Aviso | Quando | Para quem | Canal |
|---|---|---|---|
| Lembrete da véspera | 18h do dia anterior (preparado às 8h) | colaborador que escolheu os dias em que vem | WhatsApp automático, se autorizou; senão e-mail. No modo manual, os dois |
| Saída não registrada | 9h do dia seguinte | voluntário ou diretoria que entrou ontem e não registrou a saída | igual ao da véspera |
| Aula de amanhã | 18h do dia anterior | aluno com aula no dia seguinte (função `aulas_do_dia` da escola) | e-mail; WhatsApp só com o ajuste próprio ligado |
| Comunicado | na data agendada | o público escolhido (todos, voluntários, os outros vínculos, alunos) | e-mail e WhatsApp (alunos: só e-mail) e aviso na tela do ponto |
| Link das preferências | quando a secretaria manda | colaborador | e-mail |

Regras que valem para tudo:
- nada sai fora da janela das **8h às 20h** (Brasília). O que não sai até o prazo vence sozinho: o
  lembrete da véspera às 10h do dia, o da aula ao meio-dia, o da saída em 3 dias, o comunicado em 7;
- **WhatsApp só com consentimento registrado**, com data e autor: pela própria pessoa, na página de
  lembretes, ou pela secretaria, na ficha ("a pessoa autorizou…") ou na importação da planilha (com a
  confirmação obrigatória). Quem responde PARAR no WhatsApp oficial deixa de receber;
- a preferência é conferida de novo na hora de mandar: quem desligou depois do preparo não recebe;
- cada mensagem tem uma chave única (tipo, pessoa, data, canal): a mesma coisa nunca sai duas vezes,
  mesmo com a rotina e o botão "Mandar agora" rodando juntos;
- voluntário recebe o lembrete como gentileza, nunca cobrança (Lei 9.608/1998): "se não puder vir, tudo
  bem". Empregado recebe só o lembrete de registrar a presença, e só se escolheu os dias;
- o registro dos envios é apagado depois de 1 ano.

## As três fases da implantação

Em **Comunicação → Visão geral → Implantação em três fases**, a secretaria escolhe a data do lançamento
e toca em **Preparar os comunicados**. Nascem quatro rascunhos com o texto sugerido:

| Fase | Data sugerida | Público | O que diz | Botão |
|---|---|---|---|---|
| Antes | 5 dias antes, 10h | todos os colaboradores | o que muda a partir da data, por que (horas doadas ou segurança, conforme o vínculo) e como funciona em 3 passos | Escolher meus lembretes |
| Durante | no dia, 8h30 | todos os colaboradores | "começou hoje", dicas (localização, "lembrar de mim", tela inicial) e aviso na tela do ponto por 14 dias | Abrir o ponto |
| Depois | 14 dias depois, 10h | todos os colaboradores | o resumo de cada um (dias e horas doadas nas 2 semanas) e 5 perguntas; aviso na tela do ponto por 10 dias | Responder em 1 minuto |
| Depois (alunos) | 14 dias depois, 10h30 | alunos com inscrição paga (180 dias) ou presença (60 dias) | como foi confirmar a presença pelo ponto; 4 perguntas | Responder em 1 minuto |

Em cada comunicado: editar os textos (campos como `{primeiro_nome}`, `{data_lancamento}`,
`{vinculo_frase}`, `{resumo}`; `*negrito*`, listas com "- " e passos com "1. "), ver a prévia do e-mail,
do WhatsApp e do aviso no ponto (com uma pessoa de exemplo, voluntária ou empregada), ver quem recebe e
quem está sem contato, **mandar um teste para si**, agendar, mandar agora ou desagendar. Depois de sair:
quem recebeu, por qual canal, quem clicou e as opiniões. Mudar a data do lançamento recalcula as datas
dos rascunhos.

## WhatsApp: quatro modos

O modo sai da configuração (`api/config.php` ou `api/config-whatsapp.php`, só no servidor); o botão
**Usar o WhatsApp**, na Visão geral, liga e desliga em qualquer modo. Se houver mais de um configurado,
vale o primeiro desta ordem: API oficial, Evolution, Make, manual.

### Manual (padrão, sem configurar nada)

Cada mensagem vai para **Comunicação → Fila do WhatsApp**. A secretaria toca em **Abrir no WhatsApp**
(abre a conversa com o texto pronto, pelo link `wa.me`, no WhatsApp do aparelho — use o da
instituição), manda e marca **Enviei**. A fila mostra desde as 8h o que sai no dia. Nesse modo, os
lembretes também vão por e-mail, para ninguém ficar sem aviso se a fila atrasar.

### WhatsApp do Palácio Virtual, pela Evolution (o que já está conectado)

É "o WhatsApp que já temos conectado": desde 29/09/2026, o Palácio Virtual (repositório
`redacao-cruzvermelhariodejaneiro`) manda os avisos da equipe e responde no robô por uma instância da
**Evolution API** ligada a um número de WhatsApp. O ponto pode usar a mesma instância: texto livre (sem
modelo para aprovar na Meta), sozinho e sem custo por mensagem. **Está pronto, mas desligado: ligar é uma
decisão da instituição, por causa dos riscos abaixo.**

**Riscos (revisão jurídica de 30/09/2026):**
- A Evolution usa o WhatsApp Web, conectado por QR code. **Não é a API oficial**, e os Termos do WhatsApp
  não permitem mensagens automáticas por meios não autorizados. Se o número for bloqueado (o gatilho mais
  comum é gente denunciando mensagens que não esperava), **caem junto os avisos e o robô do Palácio**.
- Quem responde cai no robô do Palácio, não aqui. Um "PARAR" respondido ali é um pedido válido de
  saída (política do WhatsApp e LGPD) e precisa ser registrado pela secretaria em **Envios → Pediu para
  parar**, porque o site não recebe essa resposta.
- As mensagens e os contatos passam pelo servidor da Evolution (no Palácio, um computador da filial atrás
  de um túnel). É preciso saber quem hospeda e quem acessa, e que o número está em nome da Cruz Vermelha
  Brasileira RJ.

**Condições para ligar:** número em nome da instituição; só para quem autorizou o WhatsApp; a secretaria
registra os pedidos de parar que chegarem ao robô; a Política de Privacidade cita o servidor do Palácio;
aceite formal do risco de bloqueio. **Recomendação:** para os lembretes em volume, a API oficial (abaixo)
com número próprio; a Evolution, no máximo, para um grupo pequeno enquanto a API oficial não sai.

Como ligar:
1. No Palácio, em **Configurações → Integrações** (serviço Evolution API), copiar o endereço do
   servidor, o nome da instância e o **token da instância** (melhor que a chave global: só mexe naquela
   instância).
2. Criar `api/config-whatsapp.php` no servidor (fora do Git; o `.htaccess` nega o acesso a ele):
   ```php
   <?php return [
       'WHATSAPP_EVOLUTION_URL' => 'https://…',        // o mesmo endereço do Palácio (https)
       'WHATSAPP_EVOLUTION_INSTANCIA' => '…',
       'WHATSAPP_EVOLUTION_CHAVE' => '…',              // token da instância
   ];
   ```
3. No portal, mandar um teste pelo comunicado ("Testar também no WhatsApp") e ligar **Usar o WhatsApp**.

Como se comporta:
- **Freio fixo:** uma mensagem a cada 8 segundos, até 30 a cada rodada de 15 minutos (até 3 num clique do
  portal), para não disputar o número com o Palácio (que manda até 12 por minuto) nem travar a rotina.
  Com 60 voluntários, os lembretes das 18h terminam por volta das 18h20.
- **Número sem WhatsApp** falha na hora, sem tentar de novo. **Servidor fora do ar, chave recusada ou
  instância que sumiu**: a mensagem volta para a fila e as outras da rodada esperam a próxima (sem gastar
  tentativas). **Sem confirmação em 40 s** (acontece logo depois de conectar pelo QR code): conta como
  enviada, com a ressalva no registro, para não mandar duas vezes.
- Cada mensagem termina com "_Mensagem automática: não precisa responder._" (quem responde recebe a
  apresentação do robô do Palácio, no máximo uma por dia). Parar é pelo link do fim da mensagem ou pela
  secretaria.
- **Janela:** o ponto manda das 8h às 20h; o Palácio guarda silêncio das 22h às 7h. Não se cruzam.
- **Se o WhatsApp do Palácio desconectar**, o Palácio avisa a administração; a fila do ponto espera.

Mais adiante, se a Evolution continuar, o melhor é centralizar: o Palácio recebe o pedido do site (o mesmo
POST assinado do modo Make), põe na fila dele (com o freio de volume e o alerta de queda que já tem) e
devolve ao site os pedidos de parar que chegarem ao robô.

### API oficial do WhatsApp (Meta)

Manda sozinho, com os modelos aprovados. Precisa de uma conta do WhatsApp Business Platform **da Cruz
Vermelha Brasileira Rio de Janeiro**, com um número próprio. Nunca use o número de outra empresa: em
30/09/2026, a única conexão de WhatsApp no Make da conta era "goatlumiar", de outro negócio.

1. No Meta Business da instituição: criar o app, ligar o WhatsApp, registrar o número e criar um
   usuário do sistema com token permanente (permissão `whatsapp_business_messaging`).
2. Cadastrar os seis modelos abaixo (idioma português do Brasil) e esperar a aprovação.
3. Em `api/config.php`:
   ```php
   'WHATSAPP_CLOUD_TOKEN' => '…',          // token permanente do usuário do sistema
   'WHATSAPP_CLOUD_NUMERO_ID' => '…',      // Phone number ID (não é o número)
   'WHATSAPP_CLOUD_APP_SEGREDO' => '…',    // App secret: confere a assinatura do webhook
   'WHATSAPP_CLOUD_VERIFICACAO' => '…',    // texto qualquer, igual ao do painel da Meta
   // 'WHATSAPP_CLOUD_VERSAO' => 'v23.0', 'WHATSAPP_CLOUD_IDIOMA' => 'pt_BR' (padrões)
   ```
4. No painel do app, em Webhooks: URL `https://cruzvermelhariodejaneiro.org/matricula-cursos-presenciais/api/whatsapp.php`,
   o mesmo texto de verificação, e assinar o campo `messages`. O webhook recebe a situação de cada
   mensagem (entregue, lida, falhou) e as respostas: PARAR, SAIR, STOP e parecidas desligam o WhatsApp
   daquele número.
5. Mandar um teste pelo comunicado ("Testar também no WhatsApp") e ligar **Usar o WhatsApp**.

Custo: a Meta cobra por modelo entregue; lembretes são categoria *utility*. A Meta pode reclassificar os
comunicados e a pesquisa como *marketing* (mais caros e com regras de opt-in mais estritas).

### Make (webhook)

Para usar um cenário do Make com um módulo de WhatsApp já conectado **da instituição**.

1. Em `api/config.php`: `'WHATSAPP_WEBHOOK_URL' => 'https://hook.…make.com/…'` e
   `'WHATSAPP_WEBHOOK_SEGREDO' => '…'` (24 caracteres ou mais; `openssl rand -hex 24`).
2. No Make: módulo **Webhooks → Custom webhook** com *JSON pass-through* e *Get request headers*
   ligados; um filtro que confere o cabeçalho `X-CVB-Assinatura` igual a `sha256=` + HMAC-SHA256 do corpo
   com o segredo (função `sha256(corpo; hex; segredo)`); o módulo de WhatsApp (modelo `modelo.nome`, com os
   parâmetros `modelo.parametros` e o sufixo do botão `modelo.botao`, ou o texto livre `texto`, se a
   conversa estiver aberta); e **Webhook response** com status 200 e `{"id": "<id da mensagem>"}`.
3. O site considera enviado quando o Make responde 2xx.

O corpo que o site manda: `{"evento": "whatsapp", "aviso": 123, "telefone": "5521…", "texto": "…",
"modelo": {"nome": "cvb_ponto_vespera", "parametros": ["Ana", "quinta, 1º/10"], "botao": "123.l.…"},
"enviado_em": "…"}`.

### Modelos para cadastrar na Meta

Português do Brasil. O botão de URL dinâmica tem a base
`https://cruzvermelhariodejaneiro.org/matricula-cursos-presenciais/api/avisos.php?r={{1}}` (o site
completa com o código de cada mensagem, que registra o clique e leva à página certa).

| Modelo | Categoria sugerida | Corpo | Botão |
|---|---|---|---|
| `cvb_ponto_vespera` | Utilidade | Olá, {{1}}! Lembrete da secretaria da Cruz Vermelha Brasileira RJ: se você vier à sede amanhã, {{2}}, registre a entrada ao chegar e a saída ao ir embora (CPF no tablet da recepção ou QR code no celular). | URL dinâmica: "Mudar lembretes" |
| `cvb_ponto_saida` | Utilidade | Olá, {{1}}! Você registrou a entrada na sede em {{2}}, às {{3}}, mas a saída ficou sem registro. Informe a que horas saiu para as horas entrarem na sua conta de horas doadas. | URL dinâmica: "Informar horário" |
| `cvb_aula_amanha` | Utilidade | Olá, {{1}}! Lembrete da Escola de Educação e Saúde da Cruz Vermelha Brasileira RJ: amanhã, {{2}}, você tem aula de {{3}}, {{4}}, na Praça da Cruz Vermelha, 10, Centro. Ao chegar, confirme a presença no ponto da recepção com o seu CPF. | URL dinâmica: "Não quero receber" |
| `cvb_ponto_novidade` | Utilidade | Olá, {{1}}! A partir de {{2}}, a sede da Cruz Vermelha Brasileira RJ terá um ponto de chegada e saída para todos que trabalham com a gente. Ao chegar e ao ir embora, digite seu CPF no tablet da recepção ou leia o QR code do cartaz com o celular. Leva 10 segundos. | URL dinâmica: "Escolher lembretes" |
| `cvb_ponto_comecou` | Utilidade | Bom dia, {{1}}! O ponto da sede da Cruz Vermelha Brasileira RJ começa hoje. Ao chegar, registre a entrada; ao ir embora, a saída (CPF no tablet da recepção ou QR code no celular). | URL fixa: "Abrir o ponto" → `https://cruzvermelhariodejaneiro.org/ponto/` |
| `cvb_ponto_opiniao` | Utilidade (a Meta pode pôr em marketing) | Olá, {{1}}! Faz duas semanas que o ponto da sede da Cruz Vermelha Brasileira RJ começou. {{2}} Conte como está sendo para você: são 5 perguntas, leva 1 minuto. | URL dinâmica: "Responder" |

Exemplos para a Meta: {{1}} = "Ana"; {{2}} (véspera) = "quinta, 1º/10"; {{2}} (saída) = "1º/10 (quinta)",
{{3}} = "09:12"; aula: "quinta, 1º/10", "Primeiros Socorros", "das 18:00 às 22:00"; novidade:
"segunda-feira, 5 de outubro"; opinião: "Nestas duas semanas, você registrou 6 dias na sede e 23h40 de
horas doadas. Obrigado!". Mudou um modelo na Meta, mude o texto aqui e em `lib/comunicacao.php`.

## Páginas pessoais

Links assinados, com validade, que chegam nos avisos (e na ficha, para a secretaria mandar):

| Página | Para quê | Validade |
|---|---|---|
| `/matricula-cursos-presenciais/ponto/lembretes/` | dias da véspera, e-mail, WhatsApp (e o número), aviso de saída, comunicados | 60 dias |
| `/matricula-cursos-presenciais/ponto/saida/` | informar a hora da saída esquecida (a secretaria confere) | 7 dias |
| `/matricula-cursos-presenciais/ponto/opiniao/` | 5 perguntas (4 para alunos); o nome só aparece para a secretaria se a pessoa autorizar | 30 dias |
| `/matricula-cursos-presenciais/ponto/sair/` | aluno que não quer mais receber os avisos | 365 dias |

O link que vai na mensagem é o de clique (`api/avisos.php?r=`), que conta o clique e gera na hora o
link pessoal. A prévia de link dos aplicativos (HEAD) não conta. Desativar o colaborador invalida os
links dele.

## A tela do ponto

- depois do CPF, os avisos dos comunicados no ar (no celular, com o link; no aparelho da recepção, só o
  texto);
- saídas sem registro dos últimos 7 dias: a pessoa informa a hora ali mesmo, e a secretaria confere em
  **Ponto da sede → Saídas informadas para conferir** (aceitar ou recusar, com o motivo);
- entrada aberta de outro dia (plantão que virou a noite ou saída esquecida): "Estou saindo agora" ou
  "Saí ontem: informar o horário";
- no celular, a dica de pôr o ponto na tela inicial (até 3 vezes), com o manifesto e os ícones do app.

## Portal: dia a dia da secretaria

1. **Antes do lançamento:** importar a planilha de colaboradores (**Ponto da sede → Importar
   planilha**: colar do Excel ou do Google Planilhas; conferir; importar), conferir quem está sem
   contato, preparar os comunicados, revisar os textos e mandar um teste para si.
2. **Ligar os lembretes** na Visão geral: véspera e saída (e o da aula, depois da função `aulas_do_dia`
   aplicada na escola).
3. **No modo manual**, abrir a Fila do WhatsApp de manhã e no fim da tarde.
4. **Todo dia:** conferir as saídas informadas (o número aparece no menu, em Ponto da sede).
5. **Depois de 2 semanas:** ver **Resultados** (adesão, entradas por dia, horas, saídas esquecidas,
   efeito dos lembretes, opinião) e baixar a planilha por pessoa ou imprimir o relatório.

## Resultados: definições

- **Adesão:** colaboradores ativos com pelo menos uma entrada no período ÷ colaboradores ativos.
- **Saídas esquecidas:** entradas de voluntário sem saída há mais de 16 horas ÷ entradas de voluntário
  (as informadas pela pessoa aparecem à parte).
- **Clicaram:** mensagens enviadas com pelo menos um clique ÷ enviadas.
- **Deram certo:** véspera → a pessoa registrou entrada no dia; saída → a saída foi informada ou
  corrigida; aula → o aluno confirmou presença no dia. Conta uma vez por pessoa, mesmo com e-mail e
  WhatsApp.
- Tudo comparado com o período anterior, do mesmo tamanho.

## Dados e privacidade

- Tabelas novas: `mcp_ajustes`, `mcp_campanhas`, `mcp_avisos` (fila e registro; apagado em 1 ano),
  `mcp_avisos_bloqueios` (só o hash do e-mail ou do celular de quem pediu para sair),
  `mcp_opinioes`; colunas `aviso_*` em `mcp_colaboradores` e `saida_informada*` em `mcp_ponto`. Criadas
  sozinhas na primeira conexão.
- Operadores: Resend (e-mail); Meta, dona do WhatsApp (em qualquer modo); o servidor da Evolution do
  Palácio (no modo Evolution); Make (se o webhook for ligado). No modo manual, a mensagem sai do
  aparelho da secretaria.
- A página do ponto guarda no navegador do celular só a marca `pt_dica_app` (quantas vezes a dica de
  instalar apareceu).

## Configuração (nomes, só no servidor)

`WHATSAPP_EVOLUTION_URL`, `WHATSAPP_EVOLUTION_INSTANCIA`, `WHATSAPP_EVOLUTION_CHAVE` (e, só para teste,
`WHATSAPP_EVOLUTION_PAUSA_S` e `WHATSAPP_EVOLUTION_TEMPO_S`), `WHATSAPP_CLOUD_TOKEN`, `WHATSAPP_CLOUD_NUMERO_ID`, `WHATSAPP_CLOUD_APP_SEGREDO`,
`WHATSAPP_CLOUD_VERIFICACAO`, `WHATSAPP_CLOUD_VERSAO`, `WHATSAPP_CLOUD_IDIOMA`, `WHATSAPP_WEBHOOK_URL`,
`WHATSAPP_WEBHOOK_SEGREDO`, `EMAIL_REMETENTE_PONTO` (remetente dos avisos; vazio = `EMAIL_REMETENTE`),
`ESCOLA_API_AULAS_DIA_URL` (vazio = a URL da escola com `aulas_do_dia`). As `WHATSAPP_*` podem ficar em
`api/config-whatsapp.php`, e as `ESCOLA_*` em `api/config-escola.php`. Nenhum cron novo: a rotina do
ponto (`api/comparecimentos.php`, a cada 15 minutos) roda os avisos.

## Testes

`scripts/testar_avisos_integracao.php` (126 testes, MariaDB local, servidores falsos de e-mail,
WhatsApp, Make e escola: nada sai de verdade) e `docs/escola/teste-local/05_testes_aulas_do_dia_pgtap.sql`
(26 testes da função da escola).
