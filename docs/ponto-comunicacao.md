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
| Lembrete da véspera | 18h do dia anterior (preparado a partir das 8h) | voluntário e diretoria, nos dias que escolheram; equipe contratada, de terça a sexta com a véspera também em dia útil (a mensagem nunca sai em fim de semana nem em feriado) e só se a própria pessoa escolheu os dias pelo link | WhatsApp automático, se autorizou; senão e-mail. No modo manual, os dois |
| Saída não registrada | a partir das 9h | voluntário ou diretoria com entrada de até 3 dias atrás, sem saída há mais de 16 horas | igual ao da véspera |
| Aula de amanhã | 18h do dia anterior | aluno com aula no dia seguinte (função `aulas_do_dia` da escola) | e-mail; WhatsApp só com o ajuste próprio ligado **e** a autorização registrada na escola |
| Comunicado | na data agendada | o público escolhido (todos, voluntários, os outros vínculos, alunos que confirmaram presença pelo ponto) | e-mail e WhatsApp (alunos: só e-mail) e aviso na tela do ponto |
| Link das preferências | quando a secretaria manda | colaborador | e-mail |

Regras que valem para tudo:
- nada sai fora da janela das **8h às 20h** (Brasília), e a janela é conferida a cada mensagem. O que
  não sai até o prazo vence sozinho: o lembrete da véspera e o da aula às 20h da véspera (depois disso,
  "amanhã" já estaria errado), o da saída em 3 dias, o comunicado "durante" às 20h do próprio dia e os
  outros comunicados em 7 dias;
- **feriados e dias sem expediente não têm lembrete** da véspera nem da aula: os nacionais, os do estado
  do Rio (São Jorge, Carnaval) e o da cidade (São Sebastião), mais Sexta-feira Santa e Corpus Christi, e
  os dias que a secretaria marcar na Visão geral (recessos). Recesso marcado depois do preparo (às 8h)
  também barra o lembrete da aula na hora de mandar. Na lista, data que já passou sai sozinha, "02/01"
  escrito em dezembro vale para o ano seguinte, e cabem até 23 datas futuras (mais que isso, o portal
  avisa em vez de cortar). Se a sede abrir nos feriados, `AVISOS_FERIADOS = '0'` desliga os embutidos;
- **WhatsApp só com consentimento registrado**, com data, autor e como foi dado: pela própria pessoa, na
  página de lembretes, ou pela secretaria, na ficha (com "como a pessoa autorizou", obrigatório) ou na
  importação da planilha (com a confirmação obrigatória). Trocar o celular no cadastro apaga a
  autorização (valia para o número antigo). Quem responde PARAR no WhatsApp oficial deixa de receber; os
  pedidos que chegam por outro caminho, a secretaria registra em **Envios → Pediu para parar**;
- a preferência é conferida de novo na hora de mandar: quem desligou depois do preparo não recebe.
  Desligar o WhatsApp no portal é uma pausa: religado, sai o que ainda estiver no prazo. Desligar um
  lembrete tira da fila o que era dele; religado no mesmo dia, o que ainda está no prazo volta;
- cada mensagem tem uma chave única (tipo, pessoa, data, canal): a mesma coisa nunca sai duas vezes,
  mesmo com a rotina e o botão "Mandar agora" rodando juntos;
- os e-mails de lembrete e comunicado levam o **descadastro de um clique** (`List-Unsubscribe`, RFC 8058): o
  Gmail e outros mostram "cancelar inscrição" ao lado do remetente, em vez de a pessoa marcar como spam (o
  que prejudicaria também os e-mails da matrícula, que saem do mesmo domínio). O programa de e-mail manda
  um POST a `api/avisos.php?u=`: o colaborador deixa de receber avisos por e-mail; o aluno entra na lista
  de bloqueio. Abrir o link no navegador mostra uma página com o botão "Não quero mais receber",
  sem link pessoal. No colaborador, só vale se o e-mail da mensagem ainda é o do cadastro;
- **cota diária de e-mails**: a conta da Resend é a mesma da matrícula (recibos, PIX, comprovantes) e, em
  30/09/2026, estava no plano grátis, com 100 e-mails por dia e 3.000 por mês. Os avisos usam no máximo 60
  por dia (`AVISOS_EMAILS_POR_DIA`; `'0'` = sem cota, num plano pago). O comunicado usa só o que sobra
  depois dos lembretes do dia já preparados (a véspera e as aulas nascem às 8h e saem às 18h) e de uma folga
  de 15% para os que nascem depois (e-mail de reserva, quem ligou os lembretes à tarde): um comunicado às
  10h não tira o e-mail dos lembretes das 18h. O que não coube espera o dia seguinte, dentro do prazo de
  cada um; a Visão geral mostra quantos saíram no dia, e o "Mandar agora" avisa quando o e-mail do
  comunicado fica para amanhã. Com o plano grátis, um comunicado para mais de uns 40 colaboradores leva
  mais de um dia por e-mail. A cota é aproximada: a rotina e o "Mandar agora" juntos podem passar dela em
  até 15 e-mails;
- **lembretes antes de comunicados**: um comunicado grande não atrasa os lembretes das 18h. Se a Resend
  recusar por limite ou falhar três vezes seguidas, o resto do e-mail espera a próxima rodada (os avisos
  não usam o `mail()` da hospedagem, que cai no spam, nem sem a chave da Resend: sem ela, o aviso por
  e-mail falha com o motivo). Lembrete que não sai pelo WhatsApp de vez (número sem WhatsApp, recusado
  pela Meta, três falhas, ou a Meta avisando depois, pelo webhook, que não entregou) vai por e-mail, se
  ainda der tempo, mesmo que o e-mail dele tenha sido cancelado antes porque ia pelo WhatsApp;
- **perto do prazo, troca de canal**: a partir das 19h, com o WhatsApp fora do ar ou desligado no portal,
  e na última rodada (19h45) em qualquer caso, o lembrete que ainda espera o WhatsApp vai por e-mail (quem
  tem e-mail), já na mesma rodada, e o WhatsApp dele não sai mais;
- **trocar o modo do WhatsApp não perde nada**: indo para o manual, o que esperava o envio automático vai
  para a Fila do WhatsApp; indo para um modo automático, o que estava na fila e ainda vale sai sozinho
  (confira antes a fila: o que já foi mandado à mão e não foi marcado sairia de novo);
- voluntário recebe o lembrete como gentileza, nunca cobrança (Lei 9.608/1998): "se não puder vir, tudo
  bem". A equipe contratada recebe só o lembrete de registrar a presença, por segurança, de terça a sexta
  (a mensagem sai na véspera, que também precisa ser dia útil sem feriado: nada no domingo) e só se pediu;
  lembrete mandado pela instituição a empregado pareceria controle de jornada;
- o registro dos envios, as respostas da pesquisa e o registro das escolhas (preferências, troca de
  número, pedidos para parar, saídas informadas) são apagados depois de 1 ano.

## As três fases da implantação

Em **Comunicação → Visão geral → Implantação em três fases**, a secretaria escolhe a data do lançamento
e toca em **Preparar os comunicados**. Nascem quatro rascunhos com o texto sugerido:

| Fase | Data sugerida | Público | O que diz | Botão |
|---|---|---|---|---|
| Antes | 5 dias antes, 10h | todos os colaboradores | o que muda a partir da data, por que (horas doadas ou segurança, conforme o vínculo) e como funciona em 3 passos | Escolher meus lembretes |
| Durante | no dia, 8h30 | todos os colaboradores | "começou hoje", dicas (localização, "lembrar de mim", tela inicial) e aviso na tela do ponto por 14 dias | Abrir o ponto |
| Depois | 14 dias depois, 10h | todos os colaboradores | o resumo de cada voluntário (dias e horas doadas nas 2 semanas; a equipe contratada não recebe resumo) e 5 perguntas; aviso na tela do ponto por 10 dias | Responder em 1 minuto |
| Depois (alunos) | 14 dias depois, 10h30 | alunos que confirmaram presença pelo ponto nos últimos 60 dias | como foi confirmar a presença pelo ponto; 4 perguntas | Responder em 1 minuto |

Em cada comunicado: editar os textos (campos como `{primeiro_nome}`, `{data_lancamento}`,
`{vinculo_frase}`, `{resumo}`; `*negrito*`, listas com "- " e passos com "1. "), ver a prévia do e-mail,
do WhatsApp e do aviso no ponto (com uma pessoa de exemplo, voluntária ou empregada), ver quem recebe e
quem está sem contato, **mandar um teste para si**, agendar, mandar agora ou desagendar. Depois de sair:
quem recebeu, por qual canal, quem clicou e as opiniões. Mudar a data do lançamento recalcula as datas
dos rascunhos; os já agendados ficam como estão, e o portal avisa para conferir. Um comunicado em que
nada saiu fica como **Não saiu**. Pela API oficial, o comunicado só é aceito com o modelo aprovado da fase
e o botão certo.

## WhatsApp: quatro modos

O modo sai da configuração (`api/config.php` ou `api/config-whatsapp.php`, só no servidor); o botão
**Usar o WhatsApp**, na Visão geral, liga e desliga em qualquer modo. Se houver mais de um configurado,
vale o primeiro desta ordem: API oficial, Evolution, Make, manual.

### Manual (padrão, sem configurar nada)

Cada mensagem vai para **Comunicação → Fila do WhatsApp**. A secretaria toca em **Abrir no WhatsApp**
(abre a conversa com o texto pronto, pelo link `wa.me`, no WhatsApp do aparelho — use o da
instituição), manda e marca **Enviei**. No computador, o WhatsApp Web abre sempre na mesma aba, em vez
de uma aba por mensagem. A fila mostra desde as 8h o que sai no dia. Nesse modo, os lembretes também vão
por e-mail, para ninguém ficar sem aviso se a fila atrasar; se a secretaria já marcou o WhatsApp como
enviado, o e-mail do mesmo lembrete não sai (sem aviso em dobro e sem gastar a cota).

### WhatsApp do Palácio Virtual, pela Evolution (o que já está conectado)

É "o WhatsApp que já temos conectado": desde 29/09/2026, o Palácio Virtual (repositório
`redacao-cruzvermelhariodejaneiro`) manda os avisos da equipe e responde no robô por uma instância da
**Evolution API** ligada a um número de WhatsApp. O ponto pode usar a mesma instância: texto livre (sem
modelo para aprovar na Meta), sozinho e sem custo por mensagem. **Está pronto, mas desligado: ligar é uma
decisão da instituição, por causa dos riscos abaixo.** Mesmo configurada, a Evolution só vale depois que
alguém da secretaria marca, na Visão geral, "A instituição decidiu usar o WhatsApp do Palácio sabendo dos
riscos" (fica registrado quem marcou e quando); até lá, o modo continua o manual.

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
3. No portal, registrar o aceite do risco na Visão geral, mandar um teste pelo comunicado ("Testar
   também no WhatsApp") e ligar **Usar o WhatsApp**.

Como se comporta:
- **Freio fixo:** uma mensagem a cada 8 segundos, até 30 a cada rodada de 15 minutos (até 3 num clique do
  portal), para não disputar o número com o Palácio (que manda até 12 por minuto) nem travar a rotina.
  Com 60 voluntários, os lembretes das 18h terminam por volta das 18h20.
- **Número sem WhatsApp** falha na hora, sem tentar de novo. **Servidor fora do ar, chave recusada ou
  instância que sumiu**: a mensagem volta para a fila e as outras da rodada esperam a próxima (sem gastar
  tentativas). **Sem confirmação em 40 s** (acontece logo depois de conectar pelo QR code): conta como
  enviada, com a ressalva no registro, para não mandar duas vezes.
- Cada mensagem termina com "_Mensagem automática da secretaria. Para não receber mais, use o link
  acima._" e toda mensagem tem esse link (lembretes: "Mudar os dias ou parar"; comunicados em texto livre:
  "Escolher o que recebo"). Quem responde recebe a apresentação do robô do Palácio (no máximo uma por
  dia); o pedido de parar que chegar lá, a secretaria registra em **Envios → Pediu para parar**.
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
2. Cadastrar os sete modelos abaixo (idioma português do Brasil) e esperar a aprovação.
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

Custo: a Meta cobra por mensagem de modelo entregue, e *utility* custa bem menos que *marketing*; confira
a tabela vigente antes de ligar. Lembretes são *utility*. A Meta pode reclassificar os comunicados e a
pesquisa como *marketing* (mais caros e com regras de opt-in mais estritas). Os erros 131026 (número que
não recebe) e 131049 (limite da Meta por pessoa) não são tentados de novo.

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

Textos da revisão jurídica de 30/09/2026: convite, nunca cobrança; nada de "faltou" ou "esqueceu"; para a
equipe contratada, só presença por segurança. Rodapé sugerido em todos (até 60 caracteres): "Para não
receber mais, responda PARAR." (no modo oficial, o webhook trata a resposta).

| Modelo | Categoria sugerida | Corpo | Botão |
|---|---|---|---|
| `cvb_ponto_vespera` | Utilidade | Olá, {{1}}! Lembrete da secretaria da Cruz Vermelha Brasileira RJ: se vier à sede amanhã, {{2}}, registre a chegada e a saída para suas horas voluntárias contarem (CPF no tablet da recepção ou QR code no celular). Se não puder vir, tudo bem. | URL dinâmica: "Mudar lembretes" |
| `cvb_ponto_vespera_equipe` | Utilidade | Olá, {{1}}! Lembrete que você pediu: se vier à sede amanhã, {{2}}, registre a presença ao chegar e ao sair (CPF no tablet da recepção ou QR code no celular). É só por segurança, não é o ponto oficial. | URL dinâmica: "Mudar lembretes" |
| `cvb_ponto_saida` | Utilidade | Olá, {{1}}! Em {{2}} você registrou a chegada na sede às {{3}}, e a saída ficou em aberto. Se quiser que essas horas entrem no seu histórico de horas voluntárias, informe o horário. Se preferir, é só ignorar esta mensagem. | URL dinâmica: "Informar horário" |
| `cvb_aula_amanha` | Utilidade | Olá, {{1}}! Lembrete da Escola de Educação e Saúde da Cruz Vermelha Brasileira RJ: amanhã, {{2}}, tem aula de {{3}}, {{4}}, na Praça da Cruz Vermelha, 10 (Centro). Ao chegar, confirme a presença no ponto da recepção com o seu CPF. | URL dinâmica: "Não quero receber" |
| `cvb_ponto_novidade` | Utilidade (a Meta pode pôr em marketing) | Olá, {{1}}! Aqui é a secretaria da Cruz Vermelha Brasileira RJ. A partir de {{2}}, a sede terá um registro de chegada e saída: ao chegar e ao ir embora, digite seu CPF no tablet da recepção ou leia o QR code com o celular. Leva 10 segundos. Quer lembrete na véspera dos dias em que vem? É opcional. | URL dinâmica: "Escolher lembretes" |
| `cvb_ponto_comecou` | Utilidade (a Meta pode pôr em marketing) | Olá, {{1}}! O ponto da sede da Cruz Vermelha Brasileira RJ começa hoje. Ao chegar, registre a entrada; ao ir embora, a saída (CPF no tablet da recepção ou QR code no celular). | URL fixa: "Abrir o ponto" → `https://cruzvermelhariodejaneiro.org/ponto/` |
| `cvb_ponto_opiniao` | Utilidade (a Meta pode pôr em marketing) | Olá, {{1}}! Faz duas semanas que o ponto da sede da Cruz Vermelha Brasileira RJ começou. {{2}} Conte como está sendo para você: são 5 perguntas, leva 1 minuto. | URL dinâmica: "Responder" |

Exemplos para a Meta: {{1}} = "Ana"; {{2}} (véspera) = "quinta, 1º/10"; {{2}} (saída) = "1º/10 (quinta)",
{{3}} = "09:12"; aula: "quinta, 1º/10", "Primeiros Socorros", "das 18:00 às 22:00"; novidade:
"segunda-feira, 5 de outubro"; opinião: "Nestas duas semanas, você registrou 6 dias na sede e doou 23h40.
Obrigado!" (para quem não tem resumo: "Obrigado por fazer parte da Cruz Vermelha."). Mudou um modelo na
Meta, mude o texto aqui e em `lib/comunicacao.php`.

## Páginas pessoais

Links assinados, com validade, que chegam nos avisos (e na ficha, para a secretaria mandar):

| Página | Para quê | Validade |
|---|---|---|
| `/matricula-cursos-presenciais/ponto/lembretes/` | dias da véspera, e-mail, WhatsApp (e o número), aviso de saída, comunicados | 60 dias |
| `/matricula-cursos-presenciais/ponto/saida/` | informar a hora da saída esquecida (a secretaria confere) | 7 dias |
| `/matricula-cursos-presenciais/ponto/opiniao/` | 5 perguntas (4 para alunos); o nome só aparece para a secretaria se a pessoa autorizar | 30 dias |
| `/matricula-cursos-presenciais/ponto/sair/` | aluno que não quer mais receber os avisos | 365 dias |

O link que vai na mensagem é o de clique (`api/avisos.php?r=`), que conta o clique e gera na hora o
link pessoal. A prévia de link dos aplicativos (HEAD, WhatsApp, Meta, verificadores de e-mail e outros
robôs) não conta e não ganha link pessoal. O token do link pessoal vai depois do `#` (`#t=`): essa parte
não é mandada ao servidor, então não fica no log da hospedagem (a página ainda aceita o `?t=` dos links
antigos). Desativar o colaborador, trocar o e-mail ou o celular dele, ou tocar em **Invalidar os links já
enviados**, na ficha, derruba os links já mandados: o de clique (`?r=`) e o de descadastro (`?u=`) são
assinados com a chave da pessoa, que muda nesses casos, então uma mensagem encaminhada deixa de abrir a
página de escolhas. Pela página de lembretes, a pessoa não troca um número de WhatsApp que já está no
cadastro (um link encaminhado não pode desviar as mensagens): isso é com a secretaria. A equipe
contratada só vê de terça a sexta. A pesquisa de opinião fecha 30 dias depois de o comunicado começar a
sair.

## A tela do ponto

- depois do CPF, os avisos dos comunicados no ar, só com o texto e uma dica ("o link está no e-mail ou
  no WhatsApp que você recebeu"): no celular, a única prova de quem é a pessoa é o CPF, que não é
  segredo, então a tela nunca mostra link pessoal;
- **só no aparelho da recepção**: saídas sem registro dos últimos 7 dias (a pessoa informa a hora ali
  mesmo, e a secretaria confere em **Ponto da sede → Saídas informadas para conferir**, aceitando ou
  recusando com o motivo; se a pessoa mudar o horário enquanto isso, o aceite pede para conferir de novo)
  e, na entrada aberta de outro dia, "Saí ontem: informar o horário". No celular, "Estou saindo agora"
  numa entrada de outro dia vira saída informada (a secretaria confere): de longe, com o CPF de outra
  pessoa, não se lançam horas;
- **no celular, só o que o botão precisa**: se a pessoa está na sede e se a entrada é de outro dia. O
  horário de entrada, as horas do mês e o termo pendente ficam para o tablet; o comprovante do aluno vai
  por e-mail (a tela não mostra o link, que leva ao nome completo e ao PDF com o CPF). A localização é
  indício, não prova: quem sabe o CPF de alguém e finge estar perto ainda registra por ela no mesmo dia.
  Para fechar isso de vez, falta um código do dia na tela do tablet ou no cartaz, pedido só no celular
  (decisão pendente; cada registro guarda a origem e a distância, para a secretaria conferir);
- "Lembrar de mim neste celular" vem desmarcado; no tablet, a tela volta ao começo depois de um tempo sem
  uso (qualquer toque reinicia a contagem);
- limites: no celular, 120 consultas a cada 10 minutos por IP e 10 CPFs não encontrados param aquele IP
  por 10 minutos; na rede da sede (o mesmo IP de onde o tablet fala, nas últimas 24 horas), 4 vezes isso,
  porque todos os celulares no Wi-Fi saem por um IP só; no aparelho da sede, 240 a cada 10 minutos. Para
  colaborador, a escola tem 4 segundos para responder se há aula (e não é consultada por 2 minutos
  depois de uma falha): a escola fora do ar não segura o tablet;
- no celular, a dica de pôr o ponto na tela inicial (até 3 vezes), com o manifesto e os ícones do app.

## Portal: dia a dia da secretaria

1. **Antes do lançamento:** importar a planilha de colaboradores (**Ponto da sede → Importar
   planilha**: colar do Excel ou do Google Planilhas; conferir; importar), conferir quem está sem
   contato, preparar os comunicados, revisar os textos e mandar um teste para si.
2. **Ligar os lembretes** na Visão geral: véspera e saída (e o da aula, depois da função `aulas_do_dia`
   aplicada na escola). Marcar ali os dias sem expediente (recessos) além dos feriados. Desligar um
   lembrete tira da fila o que era dele; religado no mesmo dia, o que ainda está no prazo volta.
3. **No modo manual**, abrir a Fila do WhatsApp de manhã e no fim da tarde.
4. **Todo dia:** conferir as saídas informadas (o número aparece no menu, em Ponto da sede).
   Numa emergência (evacuação), **Ponto da sede → Lista de emergência** mostra quem está na sede agora
   (colaboradores com entrada aberta e alunos em aula), pronta para imprimir e conferir no ponto de
   encontro.
5. **Quando alguém pedir para parar** fora do link (resposta no WhatsApp do Palácio, e-mail, pessoalmente):
   em **Envios**, no aviso da pessoa, **Pediu para parar**.
6. **Depois de 2 semanas:** ver **Resultados** (adesão, entradas por dia, horas, saídas não registradas
   na hora, efeito dos lembretes, opinião) e baixar a planilha dos voluntários ou imprimir o relatório.

A Visão geral avisa se a rotina (`api/comparecimentos.php`, a cada 15 minutos) parou de rodar: sem ela,
nada sai.

## Resultados: definições

- **Adesão:** colaboradores ativos com pelo menos uma entrada no período ÷ colaboradores ativos.
- **Saídas não registradas na hora:** entradas de voluntário cuja saída não foi registrada no ponto
  (sem saída há mais de 16 horas, informada depois pela pessoa ou lançada pela secretaria) ÷ entradas de
  voluntário. As resolvidas continuam contando, para o período atual e o anterior se compararem do mesmo
  jeito; **Registraram depois** mostra as informadas pela pessoa.
- **Clicaram:** mensagens enviadas com pelo menos um clique ÷ enviadas.
- **Deram certo:** véspera → a pessoa registrou entrada no dia; saída → a saída foi informada ou
  corrigida; aula → o aluno confirmou presença no dia. Conta uma vez por pessoa, mesmo com e-mail e
  WhatsApp, e só entra o que já podia dar resultado: véspera e aula cujo dia já passou; saída informada,
  ou com o prazo do link (7 dias) vencido.
- **Por pessoa:** só voluntários e diretoria, em ordem de nome (sem ranking de horas), sem cliques e sem
  "respondeu a opinião". A equipe contratada fica fora: presença por pessoa pareceria controle de jornada.
- Tudo comparado com o período anterior, do mesmo tamanho.

## Dados e privacidade

- Tabelas novas: `mcp_ajustes`, `mcp_campanhas`, `mcp_avisos` (fila e registro; apagado em 1 ano),
  `mcp_avisos_bloqueios` (só o hash, com segredo, do e-mail ou do celular de quem pediu para sair),
  `mcp_opinioes`; colunas `aviso_*` em `mcp_colaboradores` (inclusive quem autorizou o WhatsApp, quando
  e como, e quem escolheu os dias) e `saida_informada*` em `mcp_ponto`. Criadas sozinhas: a migração roda
  quando `MCP_DB_VERSAO` (em `lib/db.php`) muda. Ela é o md5 do próprio arquivo sem essa linha:
  `scripts/testar_checkout.php` confere e diz o valor novo quando o arquivo muda. A versão vem do código
  que está rodando, e não do arquivo em disco, para uma requisição servida com o `db.php` antigo (opcache),
  logo depois de um deploy, não gravar a versão nova sem criar o que é novo. A versão gravada fica em
  `mcp_chaves`, linha `versao_banco`; apague a linha para forçar.
- A opinião é anônima: o portal não mostra quem clicou no comunicado da pesquisa (só quantos), os
  comentários aparecem sem data e fora da ordem das respostas, e não há registro à parte da hora de cada
  resposta. Um mês depois do comunicado, a resposta deixa de ficar ligada a quem respondeu: sai a pessoa,
  as datas viram as do comunicado e o clique de cada um fica só com o dia. Continua contando no resultado
  e é apagada depois de 1 ano.
- Os freios por IP guardam o IP (no IPv6, só o prefixo /64; o IPv4 escrito como IPv6 vale como IPv4) por
  2 dias; a marca da rede da sede (o IP do tablet), também.
- Portal: tirar um e-mail de `PAINEL_EMAILS` derruba na hora a sessão aberta dele; o pedido de link de
  entrada vindo de outro site é recusado sem gastar o limite do IP.
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
`ESCOLA_API_AULAS_DIA_URL` (vazio = a URL da escola com `aulas_do_dia`), `AVISOS_FERIADOS` (`'0'` = a
sede abre nos feriados; valem só os dias marcados no portal), `AVISOS_EMAILS_POR_DIA` (vazio = 60; `'0'` =
sem cota). As `WHATSAPP_*` podem ficar em
`api/config-whatsapp.php`, e as `ESCOLA_*` em `api/config-escola.php`. Nenhum cron novo: a rotina do
ponto (`api/comparecimentos.php`, a cada 15 minutos) roda os avisos.

## Testes

`scripts/testar_avisos_integracao.php` (183 testes, MariaDB local, servidores falsos de e-mail,
WhatsApp oficial, Evolution, Make e escola: nada sai de verdade; roda em qualquer dia, sem depender de
feriado ou fim de semana), `scripts/testar_ponto_integracao.php` (105), `scripts/testar_checkout.php`
(303, sem banco: calendário, Páscoa, regra da equipe, prazos, leitor de dias da planilha, versão do banco) e
`docs/escola/teste-local/05_testes_aulas_do_dia_pgtap.sql` (26 testes da função da escola).
