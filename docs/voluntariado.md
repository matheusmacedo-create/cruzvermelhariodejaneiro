# Cadastro de voluntários

Formulário de voluntariado no próprio site, em `/voluntariado/cadastro/`, no lugar do formulário externo
(Spotform) usado até 16/10/2026. A ficha vai para o banco da matrícula, a equipe recebe o aviso por
e-mail, a secretaria acompanha tudo no portal (ver "Portal da secretaria" no [README](../README.md)) e,
quando a pessoa é aceita, vira colaborador do ponto da sede com vínculo **voluntário**, onde o termo de
adesão é impresso e registrado. Uma cópia da ficha em PDF e uma linha de planilha vão para o Google Drive
da filial, se a ligação com o Drive estiver configurada ([docs/google-drive.md](google-drive.md)).

Código: `site/voluntariado/cadastro/index.html` e `cadastro.js` (página), `api/voluntarios.php`
(endpoint público), `api/lib/voluntarios.php` (validação, gravação, status, aceite, PDF, CSV, e-mails),
`api/lib/db.php` (tabelas), `api/lib/google_drive.php` e `api/lib/drive_espelho.php` (Drive),
`api/drive.php` (rotina do Drive, só por linha de comando), telas no portal (`api/painel.php`,
`?v=voluntario&id=N`). Tudo em `site/matricula-cursos-presenciais/api/`.

## O fluxo, de ponta a ponta

1. **Página** `/voluntariado/cadastro/`: formulário em português, com JavaScript (`cadastro.js`, envio em
   JSON) e sem JavaScript (envio `application/x-www-form-urlencoded`; a API responde com redirect 303
   para `/voluntariado/cadastro/?protocolo=VOL-...` ou `?erro=<campo>`). A pessoa aceita a Política de
   Privacidade e declara que as informações são verdadeiras. Os parâmetros de campanha (utm, fbclid,
   gclid) só vão junto com a permissão de estatística do aviso de cookies, como na matrícula e no chat,
   porque a política diz isso.
2. **API** `POST /matricula-cursos-presenciais/api/voluntarios.php`: `mcp_voluntario_validar` normaliza
   e valida (idade mínima 16, CPF opcional mas válido quando vem, telefone, motivação de 20 a 1500
   caracteres, listas fechadas de escolaridade, frentes, dias, períodos e "como conheceu"), aplica os
   freios (abaixo) e grava com `mcp_voluntario_gravar`. Respostas: 201 `{ok, protocolo, nome, email,
   prazo, menor}`, 422 `{erro, campo}`, 409 e 429 `{erro}`.
3. **Banco**: a ficha fica em `mcp_voluntarios` (status `nova`), com `consentimento_em` e `veracidade_em`
   em UTC e `politica_versao` = campo `revisao` de `site/politicas.json` (hoje `2026-10-16`). O protocolo
   é `VOL-aammdd-NNNN` (data de Brasília do envio + id com 4 dígitos), como o `CV-...` do chat. Se o
   Drive estiver ligado, a gravação enfileira `ficha_voluntario` em `mcp_drive_fila`.
4. **E-mails** (`mcp_email_voluntario_ficha`, pela Resend): aviso à equipe, para `EMAIL_VOLUNTARIADO`
   (ou, vazio, `voluntariado@cruzvermelhariodejaneiro.org`, a coordenação), com responder-para o e-mail da pessoa e o botão "Abrir no portal"; e
   confirmação à pessoa, com o protocolo, os próximos passos (ficha de entrevista, CBFI, que acontece
   4 vezes por ano, e termo de adesão) e, para quem tem menos de 18 anos, o aviso de que um responsável
   assina o termo. O resultado de cada envio fica em `email_equipe` e `email_confirmacao`.
5. **Portal**: a seção Voluntariado lista as fichas por status, com busca e paginação, e a ficha
   (`?v=voluntario&id=N`) mostra tudo, as notas internas (`mcp_voluntarios_notas`), o histórico de status
   (`mcp_eventos`, tipo `voluntario_status`), o PDF da ficha e a exportação CSV (sem CPF). O menu
   mostra quantas fichas estão `nova`.
6. **Aceite** (`mcp_voluntario_aceitar`): precisa de um CPF válido (o da ficha ou digitado na hora).
   Cria o colaborador em `mcp_colaboradores` com vínculo `voluntario`, função "Voluntário(a)" e ativo,
   ou reaproveita o que já existir com aquele CPF, usando as funções de `lib/ponto.php`; grava
   `colaborador_id` e muda o status para `aceita`.
7. **Termo de adesão no ponto**: a partir daí vale a rotina do ponto da sede. Na ficha do colaborador
   (`?v=colaborador&id=N`), "Imprimir o termo para assinar" gera o PDF; assinado, a secretaria registra a
   data. Sem o termo, a declaração de horas não sai e o voluntário aparece com "Termo pendente" (ver
   "Vínculo e termo de adesão" no README).
8. **Drive** (`api/drive.php`, pelo cron, quando `config-google.php` existe): `mcp_drive_processar_fila`
   gera o PDF com `mcp_voluntario_pdf`, envia para `Voluntariado/Fichas` com o nome `VOL-...-Nome.pdf` e
   acrescenta uma linha na aba "Fichas" da planilha `GOOGLE_SHEET_FICHAS_ID` (protocolo, data, nome,
   idade, e-mail, telefone, cidade/bairro, escolaridade, profissão, saúde, frentes, dias, períodos,
   motivação, como conheceu, status); grava `drive_pdf_id` e `drive_planilha_em` na ficha. Uma vez por
   dia, a rotina também exporta CSVs sem CPF (`Secretaria/Exportações/AAAA/`) e faz a cópia de segurança
   do banco (`Backups/banco/AAAA-MM-DD/`). O PDF mostra só os 3 primeiros dígitos do CPF.

## Status: o que cada um significa para a secretaria

| Status | Significa | O que fazer |
|---|---|---|
| `nova` | Acabou de chegar; ninguém olhou. Conta no menu do portal. | Ler a ficha e passar para `em_analise` (ou direto para `entrevista`, `recusada` ou `arquivada`). |
| `em_analise` | Alguém da equipe está cuidando. | Anotar o que foi conversado nas notas; decidir o próximo passo. |
| `entrevista` | A pessoa foi chamada para a entrevista (e o CBFI). | Marcar a conversa por e-mail; depois dela, `aceita` ou `recusada`. |
| `aceita` | Aprovada. Virou colaborador do ponto com vínculo voluntário. | Imprimir e registrar o termo de adesão na ficha do colaborador; cadastrar os lembretes do ponto, se a pessoa quiser. |
| `recusada` | Não vai seguir. | Avisar a pessoa por e-mail (a equipe escreve; não há e-mail automático). A ficha é apagada 12 meses depois do envio. |
| `arquivada` | Encerrada sem decisão: não respondeu, desistiu ou era repetida. | Nada. A ficha é apagada 12 meses depois do envio. |

Cada mudança de status registra quem mudou, quando (`status_em`, `status_por`) e uma nota opcional, no
histórico da ficha. Enquanto uma ficha do mesmo e-mail estiver `nova` ou `em_analise` (nos últimos 30
dias), um novo envio dessa pessoa é recusado com 409; `recusada`, `arquivada`, `entrevista` e `aceita`
não barram um envio novo.

## Limites antiabuso

Mesmo modelo do chat (`api/contato.php`):

- **5 envios por IP por hora** e **2 por e-mail por dia**: 429, com mensagem para tentar mais tarde;
- **ficha repetida**: mesmo e-mail com ficha `nova` ou `em_analise` nos últimos 30 dias = 409, com a
  mensagem "já recebemos a sua ficha em dd/mm; a equipe entra em contato";
- **armadilha**: o campo `site`, invisível para pessoas, preenchido = 422 genérico, e o envio entra no
  registro de robôs barrados (guardado por 30 dias, como diz a política);
- **idade**: menos de 16 anos = 422 no campo `data_nascimento`, pedindo que escreva ao e-mail de contato;
  de 16 a 17, aceita, com o aviso do responsável na confirmação.

## LGPD operacional

**O que se guarda e por quanto tempo** (a Política de Privacidade, seção "Voluntariado", diz o mesmo
ao titular):

- `mcp_voluntarios`: a ficha inteira, inclusive CPF (quando informado), IP, página de origem e
  parâmetros de campanha, data e versão do consentimento e da declaração de veracidade;
  `mcp_voluntarios_notas`: as notas da equipe; `mcp_eventos`: as mudanças de status. **Ficha não
  aceita, recusada, arquivada ou sem resposta: 12 meses depois do envio.** **Ficha aceita: enquanto
  durar o voluntariado e por até 5 anos depois do fim**, o mesmo prazo do ponto;
- no Drive: o PDF da ficha (`Voluntariado/Fichas`) e a linha da planilha, pelo mesmo prazo da ficha; as
  exportações diárias (sem CPF) e as cópias de segurança do banco (completas), em
  `Secretaria/Exportações/` e `Backups/banco/`;
- o colaborador criado no aceite (`mcp_colaboradores`, termo de adesão, horas do ponto) segue a regra
  do ponto: 5 anos depois do fim do voluntariado, para comprovar as horas doadas e a natureza
  voluntária do serviço (base legal própria: execução do termo e exercício regular de direitos);
- os e-mails de confirmação e de aviso saem pela Resend, com o nome e o protocolo no texto.

A limpeza dos 12 meses não tem rotina automática nesta primeira versão: até ela existir, a secretaria
apaga as fichas vencidas pelo portal (ou por SQL, `criado_em < NOW() - INTERVAL 12 MONTH` e status
diferente de `aceita`), junto com o PDF e a linha da planilha. Também não há prazo definido para as
exportações e as cópias de segurança no Drive: cabe à filial decidir a rotação delas.

**Pedido de exclusão (ou revogação do consentimento)**, feito ao e-mail de contato com o assunto
"Privacidade", em até 15 dias:

1. confirmar que o pedido é da pessoa (responder ao mesmo e-mail da ficha basta; se vier de outro
   endereço, pedir uma informação da ficha, como o protocolo ou a data de nascimento);
2. localizar a ficha pelo e-mail ou protocolo no portal; anotar o `id`, o `drive_pdf_id` e o protocolo;
3. apagar a ficha e as notas: `DELETE FROM mcp_voluntarios_notas WHERE ficha_id = N;` e
   `DELETE FROM mcp_voluntarios WHERE id = N;`. Se ainda houver linha `pendente` em `mcp_drive_fila`
   com `tipo = 'ficha_voluntario'` e `referencia = N`, apagá-la também, para o PDF não ser gerado depois;
4. no Drive: apagar o PDF (`Voluntariado/Fichas/VOL-...-Nome.pdf`, id em `drive_pdf_id`) e a linha da
   aba "Fichas" da planilha com aquele protocolo; conferir se a ficha aparece em alguma exportação
   diária de `Secretaria/Exportações/` e apagar a linha lá também. As cópias de segurança do banco
   (`Backups/banco/`) feitas antes do pedido ainda têm a ficha: apagar as cópias que a contenham ou
   registrar a data em que serão descartadas;
5. se a ficha estava `aceita`, o colaborador do ponto **não** é apagado pelo pedido: o termo de adesão
   e as horas registradas seguem a regra do ponto (5 anos depois do fim). Se a pessoa está deixando o
   voluntariado, desativar o colaborador no portal; a exclusão vem com o prazo. Explicar isso na resposta;
6. responder à pessoa dizendo o que foi apagado e o que fica guardado, e por quê. Os e-mails de
   confirmação já enviados não são recolhidos; o registro do aviso à equipe pode ser apagado da caixa de
   e-mail do voluntariado.

**Pedido de acesso**: confirmar a identidade da mesma forma, abrir a ficha no portal e enviar o PDF da
ficha (`mcp_voluntario_pdf`, o mesmo que vai ao Drive; o CPF sai mascarado) por e-mail à pessoa, com a
indicação do status atual e das datas. Se houver notas internas, informar que existem; o conteúdo das
notas é da equipe, mas o que for dado pessoal da pessoa (por exemplo, o que ela disse na entrevista)
também deve ser entregue se ela pedir. Correções (telefone, e-mail, endereço) são feitas pelo portal.

**Pedido de portabilidade**: o PDF e o CSV da ficha atendem.

## Selfie obrigatória (27/10/2026)

Pedido do Matheus: a ficha leva uma selfie (nasceu opcional e, no mesmo dia, passou a ser **obrigatória**), **só para a
equipe reconhecer a pessoa na entrevista e no CBFI** (é a finalidade escrita na Política de Privacidade; não serve para divulgação).

- **Página**: cartão "Sua selfie" antes dos consentimentos, campo obrigatório (`cadastro.js` barra o envio sem ela), campo `selfie` (`accept` JPG/PNG; no iPhone a
  foto HEIC já chega convertida em JPG). Com JavaScript, `cadastro.js` mostra a prévia, deixa remover e reduz a foto no
  navegador (até 800 px, JPG 0,82) antes de mandá-la no JSON como data URL; o envio espera a redução terminar. Sem
  JavaScript, o formulário é `multipart/form-data` e a foto vai como arquivo.
- **API** (`api/voluntarios.php` e `mcp_voluntario_selfie_*` em `api/lib/voluntarios.php`): o JSON deste endpoint aceita
  até 768 KB (`MCP_VOL_CORPO_MAX`; os outros seguem com 64 KB); o arquivo, até 10 MB. A foto é conferida pelos primeiros
  bytes, virada conforme o EXIF, reduzida a 800 px e **regravada em JPG pelo GD, o que tira os metadados** (EXIF, com a
  localização do celular). Sem selfie, ou com o que não é foto, volta 422 no campo `selfie` (sem JavaScript, `?erro=selfie`). Se o PHP
  estiver sem GD, a ficha é gravada **sem** a selfie (nunca a foto crua) e o aviso à equipe diz isso. A resposta 201
  ganhou `selfie` (true/false).
- **Banco**: `mcp_voluntarios_selfies` (uma por ficha, `ficha_id`, base64 em `conteudo`, porque a cópia do banco no
  Drive é JSON; ela vai em lotes de 20). Não entra nas exportações CSV, no PDF, na planilha nem nos e-mails (o aviso à
  equipe só diz se veio).
- **Portal**: cartão "Selfie" na ficha; a imagem sai por `painel.php?v=voluntario&id=N&selfie=1`, só com a sessão e
  `Cache-Control: no-store`. "Apagar a selfie" (ação `voluntario_foto_del`: o portal corta o nome da ação em 20
  caracteres) apaga só a foto, a pedido da pessoa.
- **Prazo**: a selfie segue a ficha; o expurgo de 12 meses apaga as duas.
- **Testes**: `testar_voluntarios.php` (rotação, redução, EXIF/GPS removidos, PNG, recusas, e-mails),
  `testar_voluntarios_integracao.php` (JSON, multipart, 422, 413, expurgo) e `testar_voluntarios_portal_integracao.php`
  (imagem com e sem sessão, apagar).

## Contato e aprovação (texto da confirmação, 27/10/2026)

Pedido do Matheus: quem chama a pessoa é **a coordenadora do voluntariado, pelo WhatsApp, para explicar como será a
entrevista** (o e-mail não diz mais que a entrevista é na sede ou por vídeo). A tela de sucesso e o e-mail de
confirmação dizem com destaque que **o cadastro ainda não é a aprovação**: a pessoa passa pela coordenação e, se for
aprovada, recebe um **código de aprovação**. O código é dado pela coordenação; o site não gera nem confere esse código.
Os passos ficaram: contato da coordenação → entrevista e aprovação → CBFI (só para aprovados) → termo de adesão.

## Configuração (nomes, só no servidor)

- `EMAIL_VOLUNTARIADO` (opcional, em `config.php`): destino do aviso de ficha nova e das mensagens do chat com o
  assunto "Voluntariado". Vazio ou ausente = `voluntariado@cruzvermelhariodejaneiro.org`, a coordenação de voluntariado
  (até 25/10/2026 era `EMAIL_CONTATO`, e tudo de voluntariado chegava em contato@). O contato não recebe cópia. O responder-para é sempre o e-mail da pessoa que se cadastrou. Modelo em
  `config.example.php`.
- `GOOGLE_SA`, `GOOGLE_DRIVE_RAIZ_ID`, `GOOGLE_SHEET_FICHAS_ID` (e, só para testes locais,
  `GOOGLE_API_URL` e `GOOGLE_TOKEN_URL`) ficam em `api/config-google.php`, fora do Git, carregado por
  `lib/config.php` como os outros `config-*.php` (só as chaves `GOOGLE_*` valem). **Sem esse arquivo,
  nada do Drive acontece:** `mcp_drive_ligado()` devolve falso, a gravação da ficha não enfileira nada e
  a rotina `api/drive.php` sai sem fazer nada. Passo a passo da conta de serviço, das pastas e do cron em
  [docs/google-drive.md](google-drive.md).
- O `.htaccess` da API passa a negar também `drive.php` e os arquivos que começam com ponto
  (`.google-token.json`); os `config-*.php` já eram negados. Nenhum segredo entra no Git.

## Política de Privacidade

A seção "Voluntariado" de `/privacidade/` (âncora `#voluntariado`; em inglês `/en/privacy/#volunteering`,
em espanhol `/es/privacidad/#voluntariado`) descreve os dados, a finalidade, a base legal
(consentimento, art. 7º, I, e procedimentos preliminares ao termo de adesão, art. 7º, V), os operadores
(Hostinger, Google Workspace, Resend), a transferência internacional, os prazos e os direitos, e diz que
o Spotform deixou de ser usado. A API grava em `politica_versao` a data de revisão da própria Política de
Privacidade (`paginas.privacidade.revisao` em `site/politicas.json`; até 27/10/2026 gravava, por engano, a data geral
`revisao`, que ficou para trás quando a privacidade passou a ter a sua). Desde 27/10/2026 a política fala da selfie
opcional. Mudou o que o formulário coleta, muda o
texto, a data e, com `python3 scripts/gerar_politicas.py`, as 12 páginas; o gerador hoje troca também o
cabeçalho e o menu das páginas em inglês e espanhol (rótulos em português), então, para elas, só o
`<main>`, a descrição e o JSON-LD gerados devem ser aproveitados (foi assim em 16/10/2026).

## Publicação

Os arquivos, na ordem de envio, estão em `scripts/publicacao-voluntarios.txt`, com os comandos da cópia
do que está no ar, do envio, do cache e de como desfazer. Pontos de atenção:

- **a migração do banco é automática**: na primeira requisição depois de publicar `lib/db.php` (com
  `MCP_DB_VERSAO` nova), `mcp_migrar_tudo` cria as 4 tabelas novas, `mcp_voluntarios`,
  `mcp_voluntarios_notas`, `mcp_drive_fila` e `mcp_drive_arquivos`, e só acrescenta; nada é apagado.
  Publicar `db.php` antes de `voluntarios.php` e das telas do portal, e `lib.php` depois dos módulos
  novos que ele carrega (`lib/voluntarios.php`, `lib/google_drive.php`, `lib/drive_espelho.php`);
- **o cron do Drive só faz algo quando `config-google.php` existir no servidor**. Sem ele, criar o cron
  (`php .../api/drive.php`, a cada 15 minutos, como o `comparecimentos.php`) não faz mal: a rotina sai
  na primeira linha. Com ele, `php drive.php --testar` confere o token, lista a pasta raiz e grava
  `Site/teste-ligacao.txt`. Detalhes em [docs/google-drive.md](google-drive.md);
- a lista das páginas com o link do Spotform trocado e das páginas de políticas que mudaram sai de
  `git status --short site` na hora de publicar (marcadores na lista);
- depois: limpar o cache da Hostinger, conferir por HTTP a página do formulário, as três políticas e um
  `POST` de teste na API (com a armadilha preenchida, que não grava nada), e rodar `gerar_sitemaps.py`.
