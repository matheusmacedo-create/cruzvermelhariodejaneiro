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

- A chave secreta do projeto (papel `service_role`) só executa esta função. Ela continua sem ler
  nem gravar nenhuma tabela: o teste confere `permission denied for table Usuario`.
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
- `api/config-escola.php` está no servidor, e o acesso pelo navegador responde 403.

Falta o primeiro teste com pagamento real.

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
         teste-local/02_dados_ficticios.sql matricula_rapida.sql; do psql -d escola_teste -f $f; done
psql -d escola_teste -f teste-local/03_testes_pgtap.sql   # 84 testes (pgTAP)
php ../../scripts/testar_checkout.php                     # 195 testes do PHP, sem banco nem rede
```

O teste de ponta a ponta (`scripts/testar_escola_integracao.php`, 19 testes) usa o código do site
chamando a função por HTTP. Ele precisa de três coisas:

- um PostgREST local na frente de `escola_teste`, com o papel `service_role` num JWT;
- um MariaDB vazio para o site;
- as variáveis `MCP_CONFIG_ARQUIVO`, `MCP_CONFIG_ESCOLA_ARQUIVO`, `ESCOLA_PG_DSN`,
  `ESCOLA_PG_USUARIO` e `ESCOLA_PG_SENHA`.

O script recusa rodar se a URL da escola não for local.
