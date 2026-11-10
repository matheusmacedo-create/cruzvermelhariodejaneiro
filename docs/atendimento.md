# Atendimento inteligente do chat (10/11/2026)

O chat "Fale com a gente" deixa de ser um formulário em forma de conversa e passa a **responder primeiro**.
A pessoa escreve (ou dita) o que procura, o site responde na hora com o que já está publicado, registra a
pergunta e só leva o caso à equipe, por e-mail, quando a resposta não resolve ou o pedido precisa de gente.
Nesse caso o chamado chega com mais dados do que antes: a categoria, os dados próprios daquele tipo de pedido
e a conversa inteira.

Estado: **publicado em 10/11/2026**, com autorização do responsável. Os 49 arquivos foram conferidos no servidor
contra o Git, o cache foi limpo e o chat respondeu ao vivo (busca por palavras). A IA está **desligada** até existir
o `api/config-ia.php` no servidor (veja "Ligar a IA"). As duas primeiras linhas de `mcp_buscas` ("onde fica a sede e
qual o horário?" e "tem curso gratuito?") são da conferência da publicação. A pendência de privacidade continua aberta.

## O problema

A maior parte dos e-mails que a equipe recebe pergunta o que a FAQ já responde. O chat anterior mostrava
perguntas prontas em botões, mas só depois de a pessoa escolher o assunto certo, e só cinco por assunto. Quem
não achava a própria dúvida entre os botões escrevia para a equipe e esperava até 3 dias úteis. E não havia
registro do que as pessoas procuravam: só das mensagens que viravam e-mail.

## Pesquisa de mercado (10/11/2026) e o que ela decidiu

A pesquisa foi feita na web em 10/11/2026. Quase tudo o que existe sobre o tema é material de fornecedor;
os números abaixo são citados como eles os publicam, sem verificação independente.

| O que o mercado faz | Fonte | O que foi decidido aqui |
| --- | --- | --- |
| Responder no ponto de envio: a base de conhecimento aparece enquanto a pessoa escreve o chamado, antes de ele existir. | [open.cx](https://www.open.cx/blog/zendesk-ai-ticket-deflection), [eesel](https://www.eesel.ai/blog/how-do-i-deflect-support-tickets-with-ai) | A conversa começa pela pergunta em texto livre; o formulário só aparece depois da resposta. |
| Não esconder o contato humano: formulário escondido infla o número e derruba a satisfação; a passagem ruim do robô para a pessoa é a causa mais comum de nota baixa. | [eesel](https://www.eesel.ai/blog/how-to-deflect-tickets-with-ai), [Kustomer](https://www.kustomer.com/resources/blog/ai-powered-ticket-deflection/) | "Falar com a equipe" aparece depois de **toda** resposta, e a equipe recebe a conversa: a pessoa não repete nada. O e-mail de contato continua publicado. |
| Medir resolução e recontato, não só "desvio". | [eesel](https://www.eesel.ai/blog/how-do-i-deflect-support-tickets-with-ai) | O banco guarda o desfecho de cada pergunta (resolveu, não resolveu, virou chamado), e o painel mostra conversas que terminaram sem chamado. |
| O robô tem de admitir o que não sabe: quem chuta esconde as lacunas que deveria mostrar. | [biel.ai](https://biel.ai/glossary/content-gap), [Adobe](https://guides.adobe.com/knowledge-ai/analytics/unanswered-questions.html) | A resposta sai só da base publicada. Sem resposta, o chat diz que não achou, e a pergunta entra na lista "sem resposta". |
| Perguntas sem resposta que se repetem são a pauta da FAQ; revisão semanal. | [SiteGPT](https://sitegpt.ai/docs/guides/monitor/analytics.md), [Wonderchat](https://wonderchat.io/feature/gap-analysis) | Tela "O que procuram" no portal, com as perguntas sem resposta, as respostas mais usadas e as que "não bastaram". |
| Começar por poucos assuntos de alto volume. | [Darwin](https://blog.getdarwin.ai/en/ai-ticket-deflection-2026-b2b-playbook) | A base é a que já existe e já foi revisada (FAQ da home e catálogo de cursos). Nada novo foi escrito para o robô. |
| Voz: ajuda quem tem dificuldade de digitar, mas a usabilidade cai nos mais velhos quando a voz é o único caminho; a recomendação recorrente é oferecer os dois. | [estudo no arXiv](https://arxiv.org/abs/2603.11303), [testparty](https://testparty.ai/blog/accessible-voice-chatbots-multimodal-interfaces) | Voz como **opção** dentro do mesmo chat: microfone para ditar e botão "Ouvir" em cada resposta. Nada de widget só de voz. |

Sobre ONGs: não foi encontrado caso publicado com números de um chat assim numa sociedade da Cruz Vermelha;
os exemplos de ONGs que aparecem são de fornecedores e sem medição.

Referências de resultado, todas de fornecedor: 30% a 45% dos contatos resolvidos sem chamado nas categorias
escolhidas, depois de 90 dias ([Darwin](https://blog.getdarwin.ai/en/ai-ticket-deflection-2026-b2b-playbook)).
Sirvam de ordem de grandeza, não de meta: o número daqui vai sair do próprio painel.

### Por que não um produto pronto

Intercom, Zendesk e semelhantes resolveriam a resposta, mas cobram por resolução ou por atendente, guardam
as conversas fora e exigiriam recadastrar a base. Aqui a base já existe em dois arquivos do site, o chat e o
portal da secretaria já existem, e o que faltava era a ligação entre eles. Custo de terceiros: só as chamadas
à IA (abaixo), e o chat funciona sem ela.

### Por que não a assistente de voz da ElevenLabs no site todo

Ela já atende a coordenação do voluntariado (`api/coordenacao.php`) e é boa para aquilo: um público pequeno,
uma página, um tutorial. No site aberto ela cobraria por minuto de qualquer visitante, guardaria as conversas
na ElevenLabs (e não no banco do site, que é o que se pediu) e não coleta dados em campos. O ditado e a leitura
do próprio aparelho dão a voz sem custo e deixam tudo no mesmo registro. Se o painel mostrar muito uso de voz
(o filtro "Feitas por voz" existe para isso), vale reavaliar.

## Como funciona

```
pessoa escreve ou dita ──> api/atendimento.php ──> grava em mcp_buscas
                               │
                 IA configurada e respondeu?
                    sim │            │ não (sem chave, erro, demora, teto do dia)
                        ▼            ▼
           Claude redige a partir   busca por palavras devolve a
           da base e classifica     resposta da FAQ como foi escrita
                        └─────┬──────┘
                              ▼
        resposta + botões de página (matrícula, cadastro, doação) + "Ouvir"
                              │
         "Resolveu"  /  outra pergunta  /  "Falar com a equipe"
                                                   │
                     dados daquele tipo de pedido, um de cada vez,
                     depois nome, e-mail, telefone e o que faltar dizer
                                                   ▼
                     api/contato.php ──> mcp_contatos + e-mail à equipe
                     (categoria, dados coletados e a conversa, lida do banco)
```

- **Base de respostas** (`mcp_at_base`, em `api/lib/atendimento.php`): a FAQ da home inteira
  (`site/faq-home.json`, e não só as perguntas marcadas com `"chat"`), a ficha de cada curso (carga horária,
  escolaridade, valores, observações) e a FAQ de cada curso (`cursos.json`). Em 10/11/2026 são 68 respostas.
  É lida dos mesmos arquivos que geram as páginas: **mudou a FAQ ou o catálogo, mudou o chat**, sem passo a mais.
- **Com IA** (`mcp_at_responder_ia`): uma chamada à API da Anthropic com as instruções e a base inteira (cerca
  de 14 mil tokens, em cache) e a pergunta. A resposta vem em JSON conferido: texto, se a base respondeu, se
  precisa da equipe, categoria e quais respostas da base foram usadas (delas saem os botões de página). O
  modelo é instruído a responder só com a base, a mandar ligar 192 ou 193 em emergência, a não dar orientação
  médica e a não pedir dados pessoais. A conversa anterior (até 4 perguntas) vai junto, lida do banco.
- **Sem IA** (`mcp_at_responder_local`): busca por palavras, com sinônimos ("quanto custa", "valor" e "preço"
  são a mesma coisa). Só responde com nota alta; com nota média, oferece até três perguntas parecidas como
  botões; abaixo disso, diz que não achou. Resposta que é de um curso só sai se a pergunta citou o curso.
- **A IA nunca derruba o chat**: sem chave, com erro, depois de 20 s, com recusa do modelo, com resposta fora do
  formato ou acima do teto diário, a busca por palavras responde no lugar. Se o próprio `atendimento.php`
  estiver fora, o chat cai no roteiro de botões de antes.
- **Voz**: o microfone aparece onde o navegador tem ditado (Chrome, Edge, Safari) e só nos campos de texto
  livre. O texto ditado cai na caixa para a pessoa conferir antes de enviar. "Ouvir" lê a resposta com a voz do
  aparelho. Nas páginas de `/matricula-cursos-presenciais/` o microfone não aparece: o `.htaccess` de lá proíbe
  o microfone, de propósito, e isso não foi mexido.
- **Celular**: testado em 390 px. Botões da conversa com 44 px de altura; depois de uma resposta o teclado não
  sobe sozinho por cima dela.

## O banco: o que as pessoas procuram

Tabela `mcp_buscas` (criada sozinha pela migração do `db.php`), uma linha por pergunta:

| Coluna | O que guarda |
| --- | --- |
| `sessao` | identificador da conversa, sorteado no navegador (junta as perguntas de uma visita) |
| `pergunta`, `canal` | o texto, e se foi escrita, por voz ou por botão de sugestão |
| `modo`, `categoria` | `ia` ou `local`; uma das 14 categorias de `mcp_at_categorias()` |
| `sabe`, `precisa_equipe` | o site tinha a resposta? o caso precisava de gente? |
| `resposta`, `fonte`, `nota`, `tokens` | o que foi respondido, a pergunta da base que serviu de fonte, a nota da busca local, o consumo da IA |
| `desfecho`, `contato_id` | `resolveu`, `nao_resolveu` ou `chamado`, e o chamado que nasceu da conversa |
| `pagina`, `idioma`, `ip`, `criado_em` | de onde perguntou; o IP serve ao freio de 30 perguntas por hora |

Não tem nome, e-mail nem telefone: quem só pergunta não se identifica. A ligação com uma pessoa só existe
quando ela abre chamado (`contato_id`).

`mcp_contatos` ganhou três colunas: `categoria`, `detalhes` (os dados coletados) e `conversa`.

### Dados coletados por tipo de pedido

Definidos em `mcp_at_categorias()`; o servidor confere cada um contra essa lista.

| Categoria | O que o chat pergunta a mais |
| --- | --- |
| Pagamento, PIX e reembolso | curso, o que aconteceu (4 opções), e-mail usado na inscrição |
| Certificado e documentos | curso feito, quando fez |
| Treinamento para empresa, escola ou grupo | organização, treinamento (5 opções), quantas pessoas (4 faixas), local |
| Doações | o que quer doar (6 opções) |
| Voluntariado | cidade ou bairro |
| Parcerias | organização |
| Imprensa | veículo, prazo |
| Vagas de trabalho | área |
| Cursos e matrícula | curso |

Para mudar o que se coleta, edite a lista no PHP: o chat lê as perguntas do servidor.

## Portal da secretaria: "O que procuram"

`painel.php?v=procuras`, com o mesmo acesso do resto do portal. Mostra, para 7, 30, 90 dias ou 12 meses:
perguntas e conversas, quantas conversas terminaram sem chamado, perguntas sem resposta, chamados; a lista de
perguntas sem resposta; as respostas mais usadas, com quantas vezes "não bastaram"; os assuntos; e a lista
completa, com filtros e planilha (CSV). A ficha de cada chamado passou a mostrar os dados coletados e a
conversa, e o e-mail à equipe também.

Rotina sugerida, uma vez por semana: abrir "Perguntas sem resposta", escrever na `site/faq-home.json` as que
se repetem e publicar. A pergunta deixa de virar e-mail no mesmo dia.

## Ligar a IA

Sem fazer nada, o chat já funciona pela busca por palavras. Para ligar o Claude:

1. Criar uma chave em console.anthropic.com, só para o site, com limite de gasto mensal.
2. No gerenciador de arquivos da Hostinger, criar `public_html/matricula-cursos-presenciais/api/config-ia.php`:
   `<?php return ['IA_ANTHROPIC_CHAVE' => '...'];` (modelo completo em `config.example.php`). O `.htaccess` da
   pasta já nega acesso a `config-*.php`. A chave não vai em chat, e-mail nem Git.
3. Abrir "O que procuram": o texto do topo diz se a IA está ligada e qual modelo responde.

Desligar: `'IA_DESLIGADA' => true` no mesmo arquivo, ou apagar o arquivo.

### Custo (estimativa, preços de tabela de 11/2026)

A primeira pergunta de uma conversa grava a base no cache; as seguintes, em até 5 minutos, pagam só a leitura.

| Modelo (`IA_MODELO`) | Primeira pergunta | Perguntas seguintes | 500 conversas de 2 perguntas por mês |
| --- | --- | --- | --- |
| `claude-haiku-5-5` (padrão) | cerca de US$ 0,002 | menos de US$ 0,001 | cerca de US$ 1 |
| `claude-sonnet-5-5` | cerca de US$ 0,04 | cerca de US$ 0,005 | cerca de US$ 23 |
| `claude-opus-5-5` | cerca de US$ 0,08 | cerca de US$ 0,01 | cerca de US$ 45 |

O padrão é o Haiku 5.5, por decisão do responsável (10/11/2026): um modelo simples, barato e que resolva. A tarefa
cabe nele: responder a partir de uma base curta e classificar o assunto. Se o painel mostrar respostas ruins
(muitas "não bastou" ou casos de gente que não foram reconhecidos), subir para o Sonnet 5.5 é uma linha no
`config-ia.php`. Teto de segurança: 2.000 perguntas pela IA a cada 24 h (`IA_POR_DIA`); acima disso, busca por
palavras.

## Privacidade (pendência em aberto)

- As perguntas são gravadas sem identificação, e o rodapé do chat passou a dizer isso.
- **Com a IA ligada, o texto da pergunta vai à Anthropic** para gerar a resposta. Nome, e-mail e telefone não
  vão: o chat só os pede depois, e eles seguem direto para o `contato.php`.
- O ditado usa o serviço de voz do navegador (no Chrome, o do Google; no Safari, o da Apple).
- A política de privacidade (`/privacidade/`, gerada pela Redação) precisa citar o registro das perguntas e,
  se a IA for ligada, a Anthropic como operadora. **Isso não foi alterado nesta entrega**: é texto jurídico, e
  a decisão é do responsável.
- Não há rotina de descarte das perguntas antigas. Definir o prazo (sugestão: 12 meses) é decisão a tomar.

## Testes

```bash
php scripts/testar_atendimento.php
```

Sem banco nem rede: base, busca, categorias, conferência dos dados, e-mail da equipe (87 verificações).
Com uma pergunta entre aspas, mostra o que a busca por palavras responderia.

```bash
MCP_CONFIG_ARQUIVO=/caminho/config-teste.php php scripts/testar_atendimento_integracao.php
```

Contra um MariaDB local: API, IA por um servidor falso (formato do pedido, cache, saída estruturada, quedas),
chamado com a conversa, portal e freio (47 verificações).

Conferido também no navegador, em 390 px: pergunta, resposta, "resolveu", caso que precisa da equipe, coleta,
revisão, envio, conversa nova, roteiro de botões e queda do servidor de atendimento.

**Não testado:** uma chamada real à API da Anthropic (não havia chave no ambiente de desenvolvimento; o pedido
segue o formato documentado e foi conferido contra um servidor falso); o ditado e a leitura em aparelhos de
verdade (o teste automático confere que o botão aparece e que o texto ditado seria enviado como "voz", mas não
fala nem ouve). Os dois pedem um teste manual depois de publicar.

## Publicar (feito em 10/11/2026; fica como roteiro)

A lista de arquivos e a ordem estão em `scripts/publicacao-atendimento.txt`. Resumo:

1. Cópia do que está no ar e publicação pelo `scripts/publicar_hostinger.sh` (bibliotecas antes dos endpoints).
2. Limpar o cache do site. A tabela e as colunas novas são criadas na primeira requisição.
3. Conferir: abrir o chat no celular, perguntar "onde fica a sede?", marcar "Resolveu", e ver a pergunta em
   "O que procuram".
4. (Opcional) Criar o `config-ia.php` e repetir o teste; testar o microfone num celular.
5. Na Redação, regerar as páginas das notícias (Configurações → Site): elas copiam o endereço do chat da home
   na hora de publicar, e até lá seguem com o chat anterior, que continua funcionando.

Desfazer: republicar a cópia do ar. As colunas e a tabela novas podem ficar: o código anterior as ignora.

## Arquivos

- `site/chat/chat.js`, `chat.css`, `chat.min.css`: a conversa.
- `site/matricula-cursos-presenciais/api/atendimento.php`: endpoint (perguntar, avaliar).
- `api/lib/atendimento.php`: base, busca, IA, categorias, banco das perguntas.
- `api/lib/painel_atendimento.php` e `api/painel.php`: "O que procuram" e a conversa na ficha do chamado.
- `api/contato.php`, `api/lib/email.php`, `api/lib/db.php`, `api/lib/config.php`, `api/lib.php`: o chamado com
  categoria, dados e conversa; tabela e colunas; `config-ia.php`.
- `scripts/testar_atendimento.php`, `scripts/testar_atendimento_integracao.php`.
