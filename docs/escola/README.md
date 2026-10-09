# Matrícula do site entrando na plataforma da escola

Quando a Unicopag confirma a inscrição de R$ 99 paga em
`cruzvermelhariodejaneiro.org/matricula-cursos-presenciais`, o servidor do site chama a função
`public.matricula_rapida` do banco da escola (Supabase, projeto `wrckokgdtiwvxapqzkki`, o mesmo de
`escola.cursoscruzvermelha.org`). O aluno sai do pagamento já com a conta e a matrícula na escola.

## O que a função faz

1. **Aluno:** procura pelo CPF. Se não existe, cria a conta como o cadastro da escola, com duas
   diferenças: a senha é aleatória (ninguém a conhece) e o aluno recebe um **link único de "criar
   senha"** (`/redefinir-senha?token=…`, 72 horas). É o mesmo mecanismo do "Esqueci minha senha" da
   escola: a escola guarda o sha256 do link (`TokenAuth`, tipo `RESET_SENHA`), o site guarda o link.
   A senha nunca é os 4 últimos dígitos do CPF (briefing, seção 8.2). Uma conta que já existe não é
   alterada; só ganha o celular, se não tinha.
2. **Matrícula:** na próxima turma `ABERTA` ou `CONFIRMADA` do curso, com vaga, que comece a partir
   de amanhã (horário de Brasília). Os campos da matrícula ficam assim:
   - plano à vista, forma PIX ou crédito (a mesma usada na taxa);
   - `valorCurso` = preço à vista + taxa paga, que é como a escola grava o valor;
   - taxa confirmada por `site cruzvermelhariodejaneiro.org`;
   - um pagamento `TAXA` de R$ 99, com gateway `site` e o hash da Unicopag.

   O saldo a pagar fica o preço à vista, como o site promete. Se o aluno já tinha matrícula aberta
   no curso (feita na própria escola), a taxa vai para ela.
3. **Sem turma aberta:** cria só a conta. A secretaria matricula quando abrir turma; o aviso que
   ela recebe por e-mail diz isso.
4. **Registro:** tudo entra no `LogAuditoria` com ator `SISTEMA`:

   | Ação | Quando |
   |---|---|
   | `CADASTROU_ALUNO_PELO_SITE` | conta criada pelo site |
   | `MATRICULOU_PELO_SITE` | matrícula nova |
   | `CONFIRMOU_TAXA_PELO_SITE` | taxa aplicada numa matrícula que já existia |
   | `TAXA_PAGA_SEM_TURMA_PELO_SITE` | curso sem turma aberta |

A mesma confirmação pode chegar mais de uma vez. Pelo hash da transação, a repetição devolve o
mesmo resultado e não duplica nada. Isso foi testado com 6 chamadas simultâneas.

Alguns conflitos voltam `ok=false` e não gravam nada. Nesses casos a secretaria resolve com o
aviso por e-mail, e o aluno vê a versão B (a secretaria escreve):

| Código | Situação |
|---|---|
| `email_em_uso` | o e-mail já está numa conta com outro CPF |
| `documento_da_equipe` | o CPF é de uma conta da equipe |
| `aluno_bloqueado` | a conta do aluno está bloqueada |
| `curso_inativo` | o curso está inativo na escola |
| `curso_inexistente` | o curso não existe na escola |

### Por que assim

Tudo vem das consultas de 28/09/2026 (`diagnostico-1.sql` e `diagnostico-2.sql`, só leitura):

| O que o banco mostrou | O que a função faz |
|---|---|
| CPF e celular só com dígitos, e-mail em minúsculas, ids UUID | grava igual |
| Senhas em argon2id | hash argon2id feito pelo site; testado no `argon2` e no `@node-rs/argon2` do Node |
| Links de senha: sha256 em hexadecimal, `RESET_SENHA` valendo 60 min | mesmo formato, 72 h para dar tempo de abrir o e-mail |
| `valorCurso` = preço + taxa (ex.: 180 + 100 = 280); com a taxa confirmada, o curso cobra `valorCurso − taxa` | `valorCurso` = preço à vista + 99, taxa 99, saldo = preço à vista |
| A própria escola cobra a taxa de R$ 100 pela Unicopag e grava pagamento `TAXA` | pagamento `TAXA` de R$ 99 com gateway `site` (o dinheiro está na conta do site; estorno pelo site) |
| Ações do sistema no log com ator `SISTEMA` | idem |
| Aluno com e-mail não confirmado consegue entrar (27 casos) | a conta nova nasce com `emailVerificado = false`, como no cadastro da escola |

## Segurança

- A chave secreta do projeto (papel `service_role`) só executa as funções do site: esta e, para o
  ponto da sede, [`aulas_do_aluno`](#aulas-do-aluno-para-o-ponto-da-sede), que só lê. Ela continua sem
  ler nem gravar nenhuma tabela: o teste confere `permission denied for table Usuario`.
- A função é `SECURITY DEFINER` com `search_path` vazio. O `EXECUTE` foi retirado de `PUBLIC`,
  `anon` e `authenticated`.
- O link de criar senha é uma credencial. Ele só fica:
  - no banco do site (`mcp_inscricoes.escola_token`);
  - na tela Parabéns;
  - no e-mail do aluno.

  Nunca vai para o log, e a escola guarda só o sha256.
- A chave vai em `site/matricula-cursos-presenciais/api/config-escola.php`, que fica só no
  servidor e está no `.gitignore`. Desse arquivo o site aceita só as chaves `ESCOLA_*`; o
  `config.php` não é tocado.

## Situação

Em produção desde 28/09/2026:
- a função foi aplicada no banco da escola e testada lá com chamadas que não gravam nada;
- o site foi publicado;
- `api/config-escola.php` está no servidor, e o acesso pelo navegador responde 403;
- o primeiro teste com pagamento real funcionou de ponta a ponta.

Os dados desse teste podem ser apagados com `limpar_teste.sql`, colando nele o hash da transação.

## Fase 2: o questionário dentro da escola

Desde 29/09/2026 o questionário de dias e horários funciona no site. Depois de pagar a inscrição, o
aluno responde em `/matricula-cursos-presenciais/horarios/`, e a secretaria vê as respostas no portal
da secretaria do site (`api/painel.php?v=horarios`). Veja a seção "Questionário de dias e horários" no
[README principal](../../README.md).

A fase 2 leva a mesma pergunta para dentro da plataforma da escola: na área do aluno e no painel da
secretaria, com as respostas guardadas no banco da escola.

### Primeiro passo, no ar a partir de 29/09/2026: a secretaria vê os horários no painel da escola

Com acesso ao código da escola ([matheusnsp/ESCOLA_CRUZ_VERMELHA](https://github.com/matheusnsp/ESCOLA_CRUZ_VERMELHA)),
o painel da secretaria ganhou a aba **Horários** (`/horarios`). Ela mostra, por curso:

- o mapa;
- os horários mais pedidos, o começo e se a data da turma serve;
- as próximas turmas abertas ou confirmadas do curso, com os dias e horários das aulas (`AulaData`);
- quem pagou e ainda não respondeu;
- a lista das respostas;
- a planilha.

O nome do aluno abre a ficha dele na escola, quando o e-mail bate com uma conta de aluno.

As respostas continuam guardadas só no site. A escola lê por `api/escola-horarios.php`, servidor a servidor:

| | Site | Escola |
|---|---|---|
| Chave | `SITE_HORARIOS_TOKEN` em `api/config-escola.php` | `SITE_HORARIOS_TOKEN` (variável no Render), com o mesmo valor |
| Endereço | `api/escola-horarios.php` | `SITE_HORARIOS_URL` (opcional; o padrão é o endereço do site) |

- Sem a chave, o endereço responde 404. Com a chave errada, 401, e cada IP pode errar até 20 vezes por hora.
- O que sai: as respostas e quem falta, com nome, e-mail, telefone, curso (com o `uuid` da escola),
  a turma e a matrícula da escola, se houver. **Nunca sai CPF.**
- A escola guarda o que leu por 1 minuto, e o botão "Atualizar" lê de novo.
- Nenhuma tabela nova, nenhuma migração e nenhuma permissão nova no banco da escola.

**Para ligar:**
1. gerar a chave: `openssl rand -hex 24`;
2. pôr a chave no `api/config-escola.php` do site: `'SITE_HORARIOS_TOKEN' => '…'` (o nome antigo `ESCOLA_HORARIOS_TOKEN` ainda vale);
3. publicar `api/escola-horarios.php`, `api/lib/horarios.php` e `api/.htaccess`;
4. no Render, pôr a mesma chave em `SITE_HORARIOS_TOKEN` no serviço da escola e publicar.

O que continua para depois: o questionário dentro da área do aluno da escola e as respostas guardadas
no banco dela. É o resto desta seção.

### Por que o resto ainda não

A plataforma da escola é um app Express, EJS e Prisma no Render. O código já está acessível, mas guardar
as respostas no banco da escola pede uma tabela nova. As migrações do Prisma da escola param em
julho de 2026, e o banco já tem tabelas e colunas criadas fora delas (`AulaData`, `Avaliacao`, `lembreteImediatoEm`…).
Uma migração nova precisa antes alinhar esse histórico, senão o Prisma vê a diferença ("drift"). Isso
pede combinar com quem mantém a escola. A chave que o site usa no banco só executa `matricula_rapida` e
`aulas_do_aluno`, de propósito, e continua assim.

### O que é preciso

1. Acesso ao repositório do código da escola e ao serviço no Render (deploy e variáveis).
2. Um ambiente de teste, ou o app rodando localmente, com uma conta de aluno e uma de secretaria de teste.
3. Combinar com quem mantém a escola. Mudanças no banco entram pelas migrações do Prisma da escola.

### Onde entra

1. **Área do aluno:** um cartão "Dias e horários" no painel do aluno, que aparece depois da
   matrícula ou da taxa paga. São as mesmas perguntas e regras da lista abaixo.
2. **Painel da secretaria:** por curso e por turma, o mesmo mapa, a lista com contatos e a
   planilha. Ao abrir ou remarcar uma turma, a secretaria vê quantos alunos podem em cada dia e
   período. A escola já guarda a data e o horário de cada aula em `AulaData` ("turmaId", data, horario).
3. **Respostas da fase 1:** entram uma vez só no banco da escola. Depois disso, há duas opções: o
   link do site passa a abrir a tela da escola, ou o site continua gravando também lá, pela função
   do fim desta seção.

### Contrato dos dados

As regras são as mesmas no site e na escola, para as respostas se somarem no mesmo mapa:

| Campo | Valores | Regra |
|---|---|---|
| horarios | combinações `dia-periodo`: dia `seg` … `sab` (sem domingo) e período `manha` (8h às 12h), `tarde` (13h às 17h) ou `noite` (18h às 22h), ex. `seg-noite` | pelo menos uma; 18 possíveis |
| inicio | `proxima`, `1mes`, `2meses` | obrigatório |
| turma_serve | `sim`, `nao` | só quando o aluno já tem turma |
| observacao | texto | opcional, até 500 caracteres |

Outras regras:
- vale a última resposta de cada aluno em cada curso, e `vezes` conta as mudanças;
- a secretaria recebe aviso só na primeira resposta.

Modelo sugerido, no padrão do banco da escola (ids em texto, colunas em camelCase, `criadoEm` e
`atualizadoEm`):

```prisma
model PreferenciaHorario {
  id           String   @id @default(uuid())
  alunoId      String
  cursoId      String
  horarios     String[] // "seg-noite", "sab-manha" …
  inicio       String   // proxima | 1mes | 2meses
  turmaServe   Boolean? // só com turma
  observacao   String?
  origem       String   @default("escola") // escola | site
  vezes        Int      @default(1)
  criadoEm     DateTime @default(now())
  atualizadoEm DateTime @updatedAt
  aluno        Usuario  @relation(fields: [alunoId], references: [id])
  curso        Curso    @relation(fields: [cursoId], references: [id])

  @@unique([alunoId, cursoId])
}
```

A chave é aluno + curso, e não a matrícula, porque quem pagou só a taxa pode ainda não ter turma. A
tabela entra pelo `schema.prisma` da escola, nunca criada à mão: uma tabela criada por fora aparece
para o Prisma como diferença ("drift"), e um `prisma migrate dev` propõe apagar o banco.

**Como uma resposta do site chega à escola:** cada resposta vira um objeto como este.

```json
{
  "cpf": "<CPF, só dígitos>",
  "curso_id": "5bd737ee-00a6-48dc-b5ab-08cde9b12897",
  "horarios": ["seg-noite", "qua-noite", "sab-manha"],
  "inicio": "proxima",
  "turma_serve": "nao",
  "observacao": "Trabalho até as 18h.",
  "vezes": 2,
  "respondido_em": "2026-09-29T15:30:00Z",
  "origem": "site"
}
```

De onde vem cada campo:
- `curso_id` é o `uuid` do curso no catálogo do site (`cursos.json`), o mesmo que `matricula_rapida` recebe;
- a escola acha o aluno pelo CPF (`"Usuario"."cpfCnpj"`), como `matricula_rapida` já faz.

O CPF só trafega de servidor para servidor. Ele não vai para planilha nem para arquivo, e o CSV do
painel não o inclui.

**A função:** se o site continuar gravando também na escola, o caminho é uma função
`public.registrar_preferencia(dados jsonb)` no mesmo molde de `matricula_rapida`:
- `SECURITY DEFINER` com `search_path` vazio;
- `EXECUTE` só para `service_role`;
- idempotente;
- registro no `LogAuditoria` com ator `SISTEMA`.

Ela entra depois da tabela, com os mesmos testes pgTAP numa cópia local, e só com aprovação.

## Aulas do aluno para o ponto da sede

Desde 29/09/2026 o site tem o ponto da sede (veja a seção "Ponto da sede e comprovante de
comparecimento" no [README principal](../../README.md)). Quando um aluno registra a chegada na sede,
o site pergunta à escola quais aulas ele tem naquele dia e guarda a presença. Quando a aula termina,
o site libera o comprovante de comparecimento. A pergunta é a função `public.aulas_do_aluno`
(`aulas_do_aluno.sql`).

- **Chamada:** `POST /rest/v1/rpc/aulas_do_aluno` com a mesma chave secreta e o corpo
  `{"dados": {"cpf": "<11 dígitos>", "data": "AAAA-MM-DD"}}`.
- **Resposta:** `{"ok": true, "aluno": {"nome", "email"}, "aulas": [...]}`, com uma aula por linha de
  `AulaData` da data pedida, em ordem de horário. Cada aula traz `aula_id`, `data`, `horario`,
  `turma_id`, `curso_id`, `curso_nome` e `carga_horaria`.
- **O que entra:**
  - matrículas do CPF que não foram canceladas nem estornadas (`statusPagamento`);
  - em turmas que não foram canceladas.

  Matrícula com pagamento pendente entra: o aluno está na turma e veio à aula.
- **Sem aula no dia:** `{"ok": true, "aluno": null, "aulas": []}`. Nome e e-mail só vêm quando há
  aula, então a função não serve para descobrir o nome de um CPF qualquer.
- **Dados inválidos:** erro `22023` com `dados inválidos: cpf` ou `dados inválidos: data`. O CPF precisa
  ter o dígito verificador válido.

### Segurança da consulta

- A função só lê (`STABLE`) e não grava nada, nem no `LogAuditoria`.
- Tem o mesmo molde de `matricula_rapida`:
  - `SECURITY DEFINER` com `search_path` vazio;
  - `EXECUTE` só para `service_role`, retirado de `PUBLIC`, `anon` e `authenticated`.

  A chave continua sem ler nenhuma tabela.
- No site, a resposta é limpa, com tamanho máximo em cada texto. O log registra só o código HTTP,
  nunca CPF ou nome. O e-mail do aluno serve só para mandar o comprovante.

### Como ligar a consulta

1. No SQL Editor do projeto da escola (o endereço do painel termina em
   `/project/wrckokgdtiwvxapqzkki`), rodar o arquivo `aulas_do_aluno.sql` inteiro. O script pode ser
   rodado de novo.
   - Em outro projeto, ele para logo no começo com "Este não é o projeto da escola" e não cria nada.
   - No fim, avisa a API para reler as funções (`notify pgrst, 'reload schema'`) e mostra uma linha:
     `aulas_do_aluno | dados jsonb | true | true | false`.
2. No servidor, nada muda. O site usa a chave que já está em `api/config-escola.php` e acha a URL
   trocando `matricula_rapida` por `aulas_do_aluno` em `ESCOLA_API_URL`. Para outra URL, pôr
   `ESCOLA_API_AULAS_URL` no mesmo arquivo.
3. Conferir no SQL Editor que a função existe com os privilégios certos, sem gravar nada:
   ```sql
   select p.prosecdef as security_definer, p.proconfig as config, p.proacl as privilegios
     from pg_proc p join pg_namespace n on n.oid = p.pronamespace
    where n.nspname = 'public' and p.proname = 'aulas_do_aluno';
   ```
   O esperado:
   - `security_definer` verdadeiro;
   - `search_path` vazio em `config`;
   - em `privilegios`, só `postgres=X/postgres` e `service_role=X/postgres`.

   Depois do site publicado, o teste de verdade é um aluno com aula no dia digitar o CPF no ponto.

**Conferência de fora (sem acesso ao painel):** `GET /rest/v1/` com a chave secreta deve listar
`/rpc/aulas_do_aluno` (e `/rpc/aulas_do_dia`); o `POST` com um CPF fictício válido deve voltar 200 com
`{"ok": true, "aluno": null, "aulas": []}`.

**Se a API responder `PGRST202`** ("Could not find the function") depois de aplicar: no Supabase, um
`CREATE FUNCTION` confirmado já faz a API reler as funções sozinha (gatilho `pgrst_ddl_watch`). Então
`PGRST202` quer dizer que o script não foi confirmado nesse projeto. O SQL Editor roda o texto inteiro
numa transação só, então um erro desfaz tudo e só aparece a mensagem em vermelho. Também pode ter rodado
só o trecho selecionado ou em outro projeto. Rodar o arquivo inteiro de novo, sem nada selecionado, e
conferir a linha do fim. Enquanto isso, o ponto mostra ao aluno "Não conseguimos consultar as aulas na
escola agora". Foi o que aconteceu em 01/10/2026: a primeira aplicação não chegou ao banco da escola.

**Desfazer:** `drop function public.aulas_do_aluno(jsonb);`. O ponto dos colaboradores continua
funcionando, e o aluno vê "Não conseguimos consultar as aulas na escola agora".

**Testes:** 24 testes pgTAP em `teste-local/04_testes_aulas_pgtap.sql` e o passo 7 de
`scripts/testar_escola_integracao.php`, em que o site chama a função pelo PostgREST local. Os testes
pgTAP cobrem:
- segurança e privilégios;
- aluno com dois cursos no dia;
- outro dia e pagamento pendente;
- matrícula cancelada ou estornada, turma cancelada e CPF desconhecido;
- dados inválidos.

A trava de projeto e o aviso à API foram conferidos em 01/10/2026 num PostgREST local, mandando o arquivo
como uma consulta só, do jeito do SQL Editor:
- num banco sem `AulaData`, a trava para tudo e nada é criado;
- o aviso no fim faz a API responder na hora.

O PostgREST local não tem o gatilho que o Supabase instala. Por isso, nele, a versão anterior (sem o
aviso) deixava a API em `PGRST202`. No Supabase o aviso é só uma garantia a mais.

Para repetir a trava: `psql -d <banco sem a escola> -c "$(cat aulas_do_aluno.sql)"`.

## Alunos com aula num dia, para o lembrete da véspera

Desde 30/09/2026, o site pode mandar na véspera, às 18h, o lembrete "amanhã tem aula, confirme a
presença no ponto da recepção" (seção Comunicação do portal da secretaria; o lembrete começa
desligado). Às 8h, o site pergunta à escola quem tem aula no dia seguinte. A pergunta é a função
`public.aulas_do_dia` (`aulas_do_dia.sql`).

- **Chamada:** `POST /rest/v1/rpc/aulas_do_dia` com a mesma chave secreta e o corpo
  `{"dados": {"data": "AAAA-MM-DD"}}`.
- **Resposta:** `{"ok": true, "data": "AAAA-MM-DD", "alunos": [...]}`, um item por aluno, em ordem de
  nome, com `aluno_id`, `nome`, `email`, `celular` e `aulas` (`aula_id`, `horario`, `turma_id`,
  `curso_id`, `curso_nome`, em ordem de horário). Não traz CPF.
- **O que entra:** as mesmas matrículas do ponto (não canceladas nem estornadas, em turmas não
  canceladas), só de contas de aluno (`papel = ALUNO`) e sem `bloqueioTotal`.
- **Janela:** a data precisa estar entre ontem e daqui a 7 dias (Brasília). Fora disso, erro `22023`
  com `dados inválidos: data fora da janela`. Assim, se a chave vazar, não dá para baixar a lista de
  todos os alunos de uma vez.
- **Sem aula no dia:** `{"ok": true, "data": ..., "alunos": []}`.

**Segurança:** o mesmo molde das outras funções: só lê, `SECURITY DEFINER` com `search_path` vazio,
`EXECUTE` só para `service_role`. No site, o log registra só o código HTTP. O e-mail e o celular servem
só para o lembrete; o WhatsApp dos alunos tem um ajuste próprio no portal, desligado por padrão, para
ligar só se os alunos autorizaram contato por WhatsApp na matrícula. Cada lembrete tem o link "não quero
receber".

**Atenção à escola:** a tabela `Matricula` tem as colunas `lembreteImediatoEm` e `lembreteVesperaEm`,
sinal de que a própria escola já manda algum lembrete. Antes de ligar o do site, confirmar com quem
mantém a escola o que esse lembrete faz, para o aluno não receber dois avisos parecidos.

**Como ligar:** rodar o arquivo `aulas_do_dia.sql` inteiro no SQL Editor do projeto da escola (pode
rodar de novo). Ele tem a mesma trava de projeto e o mesmo aviso à API de `aulas_do_aluno.sql`. No fim,
mostra a linha `aulas_do_dia | dados jsonb | true | true | false`. No servidor, nada muda: o
site acha a URL trocando `matricula_rapida` por `aulas_do_dia` em `ESCOLA_API_URL` (ou usa
`ESCOLA_API_AULAS_DIA_URL`, se existir). Depois, ligar "Aula de amanhã" em Comunicação, no portal.

**Desfazer:** `drop function public.aulas_do_dia(jsonb);`. O lembrete das aulas para de sair (a rotina
tenta de novo a cada 15 minutos e registra só o código do erro).

**Testes:** 26 testes pgTAP em `teste-local/05_testes_aulas_do_dia_pgtap.sql` (segurança e
privilégios, contrato, aluna com dois cursos, exclusões, janela de datas e dados inválidos). Em
30/09/2026, conferido também de ponta a ponta: o código do site chamou a função pelo PostgREST local e
recebeu a aluna de teste; a data fora da janela voltou com o erro `22023`.

## Como ligar em produção

1. No SQL Editor do projeto da escola, rodar `matricula_rapida.sql`. O script pode ser rodado de
   novo sem problema.
2. No servidor, criar `api/config-escola.php` com a chave secreta:
   ```php
   <?php return [
       'ESCOLA_API_URL' => 'https://wrckokgdtiwvxapqzkki.supabase.co/rest/v1/rpc/matricula_rapida',
       'ESCOLA_API_TOKEN' => 'sb_secret_…',
   ];
   ```
3. Publicar os arquivos do site: `api/lib/*.php`, `static/checkout.js` e as páginas `checkout/`,
   `pendente/` e `parabens/`.
4. Fazer o teste. Numa matrícula de teste com uma conta nova, dois pontos a conferir:
   - o link de "criar senha" abre o formulário da escola (e não "Link de redefinição inválido");
   - a matrícula aparece no painel da secretaria.

   Depois, apagar o teste com `limpar_teste.sql`, colando nele o hash da transação.

**Desfazer:** apagar `api/config-escola.php`, e o site volta sozinho para a versão B. Se quiser
retirar também a função, rodar `desfazer.sql` na escola.

## Testes locais

Os testes rodam numa cópia da estrutura do banco da escola, com dados fictícios, no Postgres 16
local. Os CPFs dos testes são gerados na hora, só com o dígito verificador válido.

```bash
createdb escola_teste
for f in teste-local/00_papeis_supabase.sql teste-local/01_estrutura_escola.sql \
         teste-local/02_dados_ficticios.sql matricula_rapida.sql aulas_do_aluno.sql; do psql -d escola_teste -f $f; done
psql -d escola_teste -f teste-local/03_testes_pgtap.sql        # 84 testes de matricula_rapida (pgTAP)
psql -d escola_teste -f teste-local/04_testes_aulas_pgtap.sql  # 24 testes de aulas_do_aluno (pgTAP)
psql -d escola_teste -f aulas_do_dia.sql
psql -d escola_teste -f teste-local/05_testes_aulas_do_dia_pgtap.sql  # 26 testes de aulas_do_dia (pgTAP)
php ../../scripts/testar_checkout.php                          # 291 testes do PHP, sem banco nem rede
```

O teste de ponta a ponta (`scripts/testar_escola_integracao.php`, 21 testes) usa o código do site
chamando as duas funções por HTTP. Ele precisa de três coisas:

- um PostgREST local na frente de `escola_teste`, com o papel `service_role` num JWT;
- um MariaDB vazio para o site;
- as variáveis `MCP_CONFIG_ARQUIVO`, `MCP_CONFIG_ESCOLA_ARQUIVO`, `ESCOLA_PG_DSN`,
  `ESCOLA_PG_USUARIO` e `ESCOLA_PG_SENHA`.

O script recusa rodar se a URL da escola não for local.

## v2: pagar tudo (taxa de inscrição + matrícula pagas no site)

**Situação (09/10/2026): proposta, NÃO aplicada na escola.** O arquivo é `matricula_rapida_v2.sql` (versão final,
com a venda sem turma e as correções da revisão de dinheiro de 09/10; `matricula_rapida_versao()` responde **3**):
957 linhas, sha256 `7d7a32e25098917e5964bfd5503e25c285c40b258165f32aecbb1f33f82054c7`. Antes de aplicar, conferir o
sha256:

```bash
sha256sum docs/escola/matricula_rapida_v2.sql
```

Decisões do dono (08/10/2026) que a v2 atende: o site vende "Taxa de inscrição + matrícula" já marcada, também nos
cursos sem turma; o dinheiro do curso cai na mesma conta Unicopag da taxa; a escola registra a matrícula como paga;
no cartão, até 12x com os juros por conta do aluno, numa cobrança só (juros sobre o total); PIX sempre à vista.

### O que muda em relação à v1

- **Sem os campos novos, a v2 faz exatamente o que a v1 faz** (só a taxa: matrícula `A_VISTA` `PENDENTE` com a taxa
  confirmada e um `Pagamento` `TAXA`, gateway `site`). A resposta ganha `matricula_paga: false` e `avisos`, que o
  site de hoje ignora. Prova: a suíte da v1 (`03_testes_pgtap.sql`) passa inteira contra a v2 (84/84). Por isso a v2
  pode ir ao ar antes do site novo.
- **"Pagou tudo"** (`matricula_centavos > 0`): matrícula `A_VISTA`, `PAGO`, `valorCurso` = taxa + matrícula (sem
  juros), um `Pagamento` `CURSO` `PAGO` no gateway `unicopag-2` (a conta da instituição) com ref = hash, a anotação
  `matricularapida:<referencia>` (assim o "Encaixar" e o batimento da aba Matrícula rápida não cobram de novo) e um
  `LogAuditoria`. As cobranças `PENDENTE` que a matrícula tinha na escola viram `CANCELADO`.
- **Turma vendida** (`turma_id`): a v2 matricula nela enquanto for do curso, estiver `ABERTA`/`CONFIRMADA` e começar
  depois do dia do pagamento, mesmo lotada (aviso `turma_lotada`; o site trata como matrícula confirmada e avisa a
  secretaria para ajustar as vagas). Se a turma vendida não servir mais (fechou, começou, é de outro curso), **não** cai
  na próxima turma (isso furava a fila): fica sem turma, com o aviso `matricula_paga_sem_turma`, e o site põe a compra
  na fila da próxima turma, por ordem de pagamento (correção de 09/10).
- **Fila antes de "só a taxa"** (`fila_espera`, correção de 09/10): na chamada de "só a taxa", o site manda quantas
  pessoas pagaram tudo e esperam turma do curso. Com fila, a "só a taxa" só entra numa turma criada há 6 h ou mais,
  com aulas e com vaga além da fila; senão, fica sem turma (lista de interesse). Sem o campo, a regra de antes.
- **Venda sem turma** (`espera_turma`, `inicio_ate`, `so_conta`): a primeira chamada (`so_conta`) cria só a conta. A
  rotina do site chama de novo, por ordem de pagamento, até achar uma turma `ABERTA`/`CONFIRMADA` do curso, com
  vaga (nunca acima dela), com aulas cadastradas, criada há 6 h ou mais e com a primeira aula entre hoje + 10 dias e
  `inicio_ate`. Aí grava a matrícula paga, como no "pagou tudo" com turma.

### Contrato (campos opcionais no `{"dados": {...}}`)

| Campo | Regra |
|---|---|
| `valor_centavos` | Continua só a taxa (1 a 100000) |
| `matricula_centavos` | 0 a 500000. Maior que 0 liga o "pagou tudo" |
| `parcelas` | 1 a 12 (padrão 1). PIX só 1 |
| `juros_centavos` | 0 até (total − taxa − matrícula); 0 no 1x |
| `total_centavos` | No "pagou tudo", obrigatório: de taxa + matrícula até 2 × (taxa + matrícula) + 10000 |
| `referencia` | No "pagou tudo", obrigatória e numérica (o id da inscrição no site) |
| `turma_id` | Só no "pagou tudo": a turma vendida (`[A-Za-z0-9_-]{1,64}`); nunca com `espera_turma` |
| `espera_turma` | Booleano, só no "pagou tudo" e sem `turma_id` |
| `inicio_ate` | "AAAA-MM-DD", obrigatório com `espera_turma`: a data limite da compra |
| `so_conta` | Booleano, só com `espera_turma`: cria a conta, não procura turma |
| `fila_espera` | 0 a 9999, só na "só a taxa" (ignorado no "pagou tudo"): quem pagou tudo e espera turma do curso |

Resposta: `matricula_paga` (bool), `avisos` (lista; `aviso` continua com o primeiro), `matricula_status`,
`espera_motivo` (no `sem_turma` da espera: `turmas_lotadas`, `turma_sem_aulas`, `turma_recente`,
`turma_muito_distante`, `turma_muito_proxima`, `nenhuma_turma` ou `na_fila`) e, em `turma`, `primeira_aula`, `horario`
e `status`. Avisos do "pagou tudo": `curso_ja_pago`, `taxa_em_dobro`, `turma_lotada`, `turma_diferente`,
`matricula_paga_sem_turma`, `preco_divergente`, `cobranca_escola_aberta`, `matricula_nao_marcada`, `taxa_paga_antes` e
`pendente_antiga`.

`public.matricula_rapida_versao()` (só o `service_role`): o site só oferece "taxa + matrícula" com a resposta 2 ou
mais, e só vende sem turma e roda a rotina da espera com 3. Sem a função, ou com erro, o site oferece só a taxa
(falha fechada).

### Passo a passo (cada passo só com o anterior conferido; os de produção, com o OK do dono)

1. **Testar localmente** (Postgres 16 descartável, dados fictícios; nunca o banco da escola):
   ```bash
   docs/escola/teste-local/rodar_testes_v2.sh docs/escola/matricula_rapida_v2.sql docs/escola/teste-local
   # Esperado: duas linhas de conferência; 03: 84 ok; 06: 74 ok; 09: 47 ok; 10: 70 ok; 13: 46 ok; 0 falhas.
   docs/escola/teste-local/rodar_testes_sem_turma.sh docs/escola/matricula_rapida_v2.sql docs/escola/teste-local
   # Tudo: as seis suítes (com a 14, fila antes de "só a taxa": 19 ok), o batimento (07), a concorrência da mesma
   # transação (08, 6 em paralelo: 1|1|1|1), a concorrência da última vaga na espera (11, 2 em paralelo) e a trava de
   # projeto (0 funções num banco vazio). Rodado em 09/10/2026: 03 84/84, 06 74/74, 09 47/47, 10 70/70, 13 46/46, 14 19/19.
   ```
2. **Aplicar na escola** (com o OK do dono): no SQL Editor do projeto da escola, conferir o sha256 e rodar
   `matricula_rapida_v2.sql`. A conferência no fim tem de dar duas linhas:
   `matricula_rapida | dados jsonb | true | true | false` e `matricula_rapida_versao | | false | true | false`; e
   `select public.matricula_rapida_versao();` tem de dar 3. Uma inscrição real só da taxa no dia seguinte continua
   matriculando como antes.
3. **Site com o código novo, desligado**: `PLANO_COMPLETO = false`, `PLANO_COMPLETO_SEM_TURMA = false`,
   `PARCELAS_MAX = 1` e `ESCOLA_MATRICULA_PAGA = false` (é o padrão: sem o `api/config-pagar-tudo.php` no servidor, tudo
   fica desligado). As chaves do pagar tudo vão só nesse arquivo, nunca no `config.php` nem no `config-escola.php`.
   Publicado em 09/10/2026, 16h45.
4. **Teste real** (R$ 2,00, só para o CPF de quem testa: `TESTE_CPFS`, `PRECO_TESTE_CENTAVOS = 100`,
   `PRECO_TESTE_MATRICULA_CENTAVOS = 100`, `ESCOLA_MATRICULA_PAGA = true`, todas em `api/config-pagar-tudo.php`,
   `PLANO_COMPLETO = true` e `PLANO_COMPLETO_SO_TESTE = true`, para nenhum visitante comprar a opção 1 pelo preço real
   durante o teste). Na escola: matrícula `PAGO` na turma do `turma_id`, sem a faixa "Falta pagar a matrícula",
   Pagamento `CURSO` `unicopag-2`. Depois: estornar pelo painel da Unicopag, limpar a escola com `limpar_teste.sql`
   (já cobre o gateway `unicopag-2` e a anotação) e voltar as chaves de teste.
5. **Ligar** só com as pendências do dono e o jurídico (spec `pagar-tudo`, seções 3.5, 9 e 10.14).

### Volta atrás

- Qualquer problema: `PLANO_COMPLETO = false` (e `PLANO_COMPLETO_SEM_TURMA = false`). Em segundos, o checkout
  oferece só a taxa. A v2 sem `matricula_centavos` é a v1: voltar a função quase nunca é necessário.
- Voltar para a v1 **só** com `PLANO_COMPLETO = false` e esta consulta dando 0 no banco do site:
  ```sql
  SELECT COUNT(*) FROM mcp_inscricoes WHERE plano = 'taxa_e_matricula' AND (
    (status = 'pendente' AND criado_em > UTC_TIMESTAMP() - INTERVAL 25 HOUR)
    OR (status = 'pago' AND escola_status IN ('pendente','erro') AND escola_tentativas < 5)
    OR (status = 'pago' AND espera_status IN ('aguardando','turma')));
  ```
  Depois, `ESCOLA_MATRICULA_PAGA = false`, rodar de novo `matricula_rapida.sql` (a v1) e
  `drop function if exists public.matricula_rapida_versao();`. **Nunca** o `desfazer.sql` para isso (ele tira o
  `USAGE` do `service_role` e derruba `aulas_do_aluno` e `aulas_do_dia`).

### Pedido a quem mantém a escola (recomendado, não bloqueia)

Em `encaixar()` e em `ligarComEscola()`, recusar com "Esta inscrição pagou taxa + matrícula: matricule como À vista
PAGO" quando o valor pago passar de 1,2 × a taxa, ou quando o feed trouxer `plano = 'taxa_e_matricula'`; e reconhecer
o `LogAuditoria` `TAXA_PAGA_SEM_TURMA_PELO_SITE` com `pagouMatricula: true` (tirar essas contas da lista Alunos >
"sem inscrição" e do convite de prospecção). Enquanto isso, o site não manda ao feed da aba Matrícula rápida as
compras "taxa + matrícula" que a escola não confirmou como pagas.
