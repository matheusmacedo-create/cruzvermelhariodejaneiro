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

1. No SQL Editor do projeto da escola, rodar `aulas_do_aluno.sql`. O script pode ser rodado de novo.
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
php ../../scripts/testar_checkout.php                          # 291 testes do PHP, sem banco nem rede
```

O teste de ponta a ponta (`scripts/testar_escola_integracao.php`, 21 testes) usa o código do site
chamando as duas funções por HTTP. Ele precisa de três coisas:

- um PostgREST local na frente de `escola_teste`, com o papel `service_role` num JWT;
- um MariaDB vazio para o site;
- as variáveis `MCP_CONFIG_ARQUIVO`, `MCP_CONFIG_ESCOLA_ARQUIVO`, `ESCOLA_PG_DSN`,
  `ESCOLA_PG_USUARIO` e `ESCOLA_PG_SENHA`.

O script recusa rodar se a URL da escola não for local.
