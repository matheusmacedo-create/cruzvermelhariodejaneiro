-- Testes (pgTAP) da public.matricula_rapida numa cópia local do banco da escola, com dados fictícios.
-- Os CPFs são gerados aqui mesmo (só dígito verificador válido), nunca saem desta cópia.
\set ON_ERROR_STOP 1
\set QUIET 1
begin;
create extension if not exists pgtap;
select plan(84);

-- ------------------------------------------------------------------ apoio
create function pg_temp.cpf(base text) returns text language plpgsql as $$
declare s int; d1 int; d2 int;
begin
  s := 0; for i in 1..9 loop s := s + substr(base, i, 1)::int * (11 - i); end loop; d1 := (s * 10) % 11 % 10;
  s := 0; for i in 1..9 loop s := s + substr(base, i, 1)::int * (12 - i); end loop; s := s + d1 * 2; d2 := (s * 10) % 11 % 10;
  return base || d1 || d2;
end $$;
create function pg_temp.payload(base text, email text, transacao text, curso text default 'aaaaaaaa-0000-4000-8000-000000000001',
                                extra jsonb default '{}') returns jsonb language sql as $$
  select jsonb_build_object('nome', 'Pessoa de Teste ' || base, 'cpf', pg_temp.cpf(base), 'email', email, 'celular', '21987654321',
    'curso_id', curso, 'transacao', transacao, 'metodo', 'pix', 'valor_centavos', 9900, 'total_centavos', 10395,
    'pago_em', '2026-09-28T19:00:00Z', 'referencia', '101',
    'senha_hash', '$argon2id$v=19$m=65536,t=4,p=1$c2FsdHNhbHRzYWx0$aGFzaGhhc2hoYXNoaGFzaGhhc2hoYXNoaGFzaA',
    'token_hash', encode(sha256(convert_to('link-' || base || transacao, 'UTF8')), 'hex')) || extra $$;
create function pg_temp.rpc(dados jsonb) returns jsonb language plpgsql as $$
declare r jsonb;
begin
  set local role service_role;
  r := public.matricula_rapida(dados);
  reset role;
  return r;
end $$;

-- Cursos e turmas de teste com datas relativas a hoje (Brasília), para não depender do calendário.
insert into "Curso" (id, nome, "cargaHoraria", "precoAvista", "precoCheio", parcelas, "valorParcela", ativo) values
  ('aaaaaaaa-0000-4000-8000-000000000001', 'Curso de Teste com Turmas', 8, 180, 200, 2, 100, true),
  ('aaaaaaaa-0000-4000-8000-000000000002', 'Curso de Teste sem Turma', 8, 150, 150, 1, 150, true);
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto") values
  ('turma-hoje', 'aaaaaaaa-0000-4000-8000-000000000001', 30, 'ABERTA', ((now() at time zone 'America/Sao_Paulo')::date)::timestamp + time '12:00'),
  ('turma-cancelada', 'aaaaaaaa-0000-4000-8000-000000000001', 30, 'CANCELADA', ((now() at time zone 'America/Sao_Paulo')::date + 2)::timestamp + time '12:00'),
  ('turma-1', 'aaaaaaaa-0000-4000-8000-000000000001', 3, 'ABERTA', ((now() at time zone 'America/Sao_Paulo')::date + 5)::timestamp + time '12:00'),
  ('turma-2', 'aaaaaaaa-0000-4000-8000-000000000001', 30, 'CONFIRMADA', ((now() at time zone 'America/Sao_Paulo')::date + 20)::timestamp + time '12:00'),
  ('turma-sem-vaga-curso2-encerrada', 'aaaaaaaa-0000-4000-8000-000000000002', 30, 'ENCERRADA', ((now() at time zone 'America/Sao_Paulo')::date + 9)::timestamp + time '12:00');
update "Usuario" set "cpfCnpj" = pg_temp.cpf('900000001') where papel = 'SECRETARIA';
insert into "Usuario" (id, nome, email, "cpfCnpj", "senhaHash", papel, "atualizadoEm", "bloqueioTotal", "tipoDocumento") values
  ('u-bloqueado', 'Aluno Bloqueado', 'bloqueado@exemplo.test', pg_temp.cpf('900000002'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), true, 'CPF'),
  ('u-existente', 'Aluno Que Ja Existe', 'existente@exemplo.test', pg_temp.cpf('900000003'), '$argon2id$v=19$m=1,t=1,p=1$ZXhpc3Rl$c2VuaGE', 'ALUNO', now(), false, 'CPF');

-- ------------------------------------------------------------------ privilégios
select ok(exists (select 1 from pg_proc where proname = 'matricula_rapida' and prosecdef), 'função existe e é SECURITY DEFINER');
select ok(exists (select 1 from pg_proc p, unnest(p.proconfig) c where p.proname = 'matricula_rapida' and c = 'search_path=""'), 'search_path vazio na função');
select ok(has_function_privilege('service_role', 'public.matricula_rapida(jsonb)', 'EXECUTE'), 'service_role executa');
select ok(not has_function_privilege('anon', 'public.matricula_rapida(jsonb)', 'EXECUTE'), 'anon não executa');
select ok(not has_function_privilege('authenticated', 'public.matricula_rapida(jsonb)', 'EXECUTE'), 'authenticated não executa');
select ok(not exists (select 1 from pg_proc p, aclexplode(p.proacl) a where p.proname = 'matricula_rapida' and a.grantee = 0), 'PUBLIC não executa');
select ok(has_schema_privilege('service_role', 'public', 'USAGE'), 'service_role enxerga o schema public');
select ok(not has_schema_privilege('anon', 'public', 'USAGE'), 'anon continua sem o schema public');
select ok(not exists (select 1 from pg_class c where c.relnamespace = 'public'::regnamespace and c.relkind = 'r'
                      and has_table_privilege('service_role', c.oid, 'SELECT,INSERT,UPDATE,DELETE')), 'service_role continua sem nenhuma tabela');
select throws_ok($$ set local role service_role; select count(*) from "Usuario" $$, '42501', null, 'service_role não lê Usuario direto');
select throws_ok($$ set local role anon; select public.matricula_rapida('{}'::jsonb) $$, '42501', null, 'anon não chama a função');

-- ------------------------------------------------------------------ aluno novo, turma aberta
create temp table r1 as select pg_temp.rpc(pg_temp.payload('100000001', 'Aluno.Um@Exemplo.test ', 'HASH000001')) as r;
select is((select r->>'ok' from r1), 'true', 'aluno novo: ok');
select is((select r->>'resultado' from r1), 'matriculado', 'aluno novo: matriculado');
select is((select r->>'aluno_novo' from r1), 'true', 'aluno novo: aluno_novo');
select is((select r->'turma'->>'id' from r1), 'turma-1', 'aluno novo: próxima turma com vaga, pulando a de hoje e a cancelada');
select is((select r->>'email_conta' from r1), 'a***@exemplo.test', 'aluno novo: e-mail mascarado');
select is((select r->>'email_confere' from r1), 'true', 'aluno novo: e-mail confere (normalizado)');
select is((select r->>'repetido' from r1), 'false', 'aluno novo: não repetido');
select is((select r->>'url_login' from r1), 'https://escola.cursoscruzvermelha.org/login', 'aluno novo: link do login');
select results_eq($$ select email, "tipoDocumento"::text, papel::text, "emailVerificado", celular, "senhaHash" like '$argon2id$%'
                     from "Usuario" where "cpfCnpj" = pg_temp.cpf('100000001') $$,
                  $$ values ('aluno.um@exemplo.test', 'CPF', 'ALUNO', false, '21987654321', true) $$, 'aluno novo: conta como o cadastro da escola');
select results_eq($$ select m."turmaId", m.plano::text, m.forma::text, m."valorCurso", m."valorTaxaMatricula", m."statusPagamento"::text,
                            m."taxaConfirmada", m."taxaConfirmadaPor", m."taxaConfirmadaEm"
                     from "Matricula" m join r1 on m.id = r1.r->>'matricula_id' $$,
                  $$ values ('turma-1', 'A_VISTA', 'PIX', 279.00::numeric(10,2), 99.00::numeric(10,2), 'PENDENTE', true,
                            'site cruzvermelhariodejaneiro.org', '2026-09-28 19:00:00'::timestamp(3)) $$,
                  'aluno novo: matrícula à vista, taxa de R$ 99 confirmada, saldo = preço à vista');
select results_eq($$ select p.tipo::text, p.status::text, p.gateway, p."gatewayHash", p."gatewayRef", p.valor, p.metodo::text, p."gatewayStatus",
                            (p."gatewayResponse"->>'total_cobrado_centavos')::int
                     from "Pagamento" p join r1 on p."matriculaId" = r1.r->>'matricula_id' $$,
                  $$ values ('TAXA', 'PAGO', 'site', 'HASH000001', 'site-101', 99.00::numeric(10,2), 'PIX', 'paid', 10395) $$,
                  'aluno novo: pagamento TAXA registrado');
select results_eq($$ select k.tipo, k."tokenHash" = encode(sha256(convert_to('link-100000001HASH000001', 'UTF8')), 'hex'), k."usadoEm" is null,
                            k."expiraEm" - (now() at time zone 'UTC') between interval '71 hours 59 minutes' and interval '72 hours 1 minute'
                     from "TokenAuth" k join "Usuario" u on u.id = k."usuarioId" where u."cpfCnpj" = pg_temp.cpf('100000001') $$,
                  $$ values ('RESET_SENHA', true, true, true) $$, 'aluno novo: link único de criar senha (sha256, 72 horas)');
select results_eq($$ select r->>'link_acesso', (r->>'link_expira_em') is not null from r1 $$, $$ values ('true', true) $$, 'aluno novo: resposta avisa do link');
select results_eq($$ select acao, "atorId" from "LogAuditoria" where detalhe->>'transacao' = 'HASH000001' order by acao $$,
                  $$ values ('CADASTROU_ALUNO_PELO_SITE', 'SISTEMA'), ('MATRICULOU_PELO_SITE', 'SISTEMA') $$, 'aluno novo: log como SISTEMA');

-- ------------------------------------------------------------------ a mesma confirmação de novo
create temp table r1b as select pg_temp.rpc(pg_temp.payload('100000001', 'aluno.um@exemplo.test', 'HASH000001')) as r;
select is((select r->>'repetido' from r1b), 'true', 'repetida: marcada como repetida');
select is((select r->>'matricula_id' from r1b), (select r->>'matricula_id' from r1), 'repetida: mesma matrícula');
select is((select r->>'aluno_novo' from r1b), 'true', 'repetida: lembra que a conta foi criada pelo site');
select is((select r->>'link_acesso' from r1b), 'true', 'repetida: o mesmo link continua valendo');
select is((select count(*)::int from "TokenAuth" k join "Usuario" u on u.id = k."usuarioId" where u."cpfCnpj" = pg_temp.cpf('100000001')), 1, 'repetida: um link só');
select is((select count(*)::int from "Usuario" where "cpfCnpj" = pg_temp.cpf('100000001')), 1, 'repetida: uma conta só');
select is((select count(*)::int from "Pagamento" where "gatewayHash" = 'HASH000001'), 1, 'repetida: um pagamento só');
select is((select count(*)::int from "LogAuditoria" where detalhe->>'transacao' = 'HASH000001'), 2, 'repetida: nenhum log a mais');

-- ------------------------------------------------------------------ formatos de entrada
create temp table r2 as select pg_temp.rpc(pg_temp.payload('100000002', '  DOIS@EXEMPLO.TEST', 'HASH000002',
  extra => jsonb_build_object('cpf', regexp_replace(pg_temp.cpf('100000002'), '(\d{3})(\d{3})(\d{3})(\d{2})', '\1.\2.\3-\4'),
                              'celular', '+55 (21) 3333-4444', 'metodo', 'cartao', 'nome', '  Pessoa   Com   Espacos  '))) as r;
select results_eq($$ select nome, email, celular from "Usuario" where "cpfCnpj" = pg_temp.cpf('100000002') $$,
                  $$ values ('Pessoa Com Espacos', 'dois@exemplo.test', null::text) $$, 'formatos: nome, e-mail e CPF normalizados; celular fora do padrão fica vazio');
select is((select forma::text from "Matricula" where id = (select r->>'matricula_id' from r2)), 'CREDITO', 'formatos: cartão vira CREDITO');

-- ------------------------------------------------------------------ turma cheia vai para a próxima
select is((pg_temp.rpc(pg_temp.payload('100000003', 'tres@exemplo.test', 'HASH000003')))->'turma'->>'id', 'turma-1', 'terceiro aluno ocupa a última vaga da turma-1');
create temp table r4 as select pg_temp.rpc(pg_temp.payload('100000004', 'quatro@exemplo.test', 'HASH000004')) as r;
select is((select r->'turma'->>'id' from r4), 'turma-2', 'turma-1 com 3 vagas lotada: vai para a turma-2 (CONFIRMADA)');

-- ------------------------------------------------------------------ aluno que já existe
create temp table r5 as select pg_temp.rpc(pg_temp.payload('900000003', 'outro.email@exemplo.test', 'HASH000005')) as r;
select is((select r->>'aluno_novo' from r5), 'false', 'existente: não cria conta');
select is((select r->>'email_confere' from r5), 'false', 'existente: avisa que o e-mail da conta é outro');
select is((select r->>'email_conta' from r5), 'e***@exemplo.test', 'existente: e-mail da conta mascarado');
select results_eq($$ select nome, email, "senhaHash", celular from "Usuario" where id = 'u-existente' $$,
                  $$ values ('Aluno Que Ja Existe', 'existente@exemplo.test', '$argon2id$v=19$m=1,t=1,p=1$ZXhpc3Rl$c2VuaGE', '21987654321') $$,
                  'existente: nome, e-mail e senha intactos; só ganha o celular que faltava');
select is((select "alunoId" from "Matricula" where id = (select r->>'matricula_id' from r5)), 'u-existente', 'existente: matrícula na conta dele');
select results_eq($$ select r->>'link_acesso', (select count(*)::int from "TokenAuth" where "usuarioId" = 'u-existente') from r5 $$,
                  $$ values ('false', 0) $$, 'existente: nenhum link de senha (ele já tem a dele)');

-- ------------------------------------------------------------------ conflitos não gravam nada
create temp table antes as select (select count(*) from "Usuario") u, (select count(*) from "Matricula") m,
                                  (select count(*) from "Pagamento") p, (select count(*) from "LogAuditoria") l;
select is((pg_temp.rpc(pg_temp.payload('100000006', 'existente@exemplo.test', 'HASH000006')))->>'erro', 'email_em_uso', 'conflito: e-mail de outra conta');
select is((pg_temp.rpc(pg_temp.payload('900000001', 'x1@exemplo.test', 'HASH000007')))->>'erro', 'documento_da_equipe', 'conflito: CPF da equipe');
select is((pg_temp.rpc(pg_temp.payload('900000002', 'x2@exemplo.test', 'HASH000008')))->>'erro', 'aluno_bloqueado', 'conflito: aluno bloqueado');
select is((pg_temp.rpc(pg_temp.payload('100000009', 'x3@exemplo.test', 'HASH000009', curso => '77962b07-9e01-42f3-8b3c-72d78ed47e86')))->>'erro', 'curso_inativo', 'conflito: curso inativo');
select is((pg_temp.rpc(pg_temp.payload('100000010', 'x4@exemplo.test', 'HASH000010', curso => 'bbbbbbbb-0000-4000-8000-000000000009')))->>'erro', 'curso_inexistente', 'conflito: curso inexistente');
select is((pg_temp.rpc(pg_temp.payload('100000006', 'existente@exemplo.test', 'HASH000006')))->>'ok', 'false', 'conflito: ok=false');
select ok((select (select count(*) from "Usuario") = u and (select count(*) from "Matricula") = m and (select count(*) from "Pagamento") = p
                  and (select count(*) from "LogAuditoria") = l from antes), 'conflitos: nada gravado');

-- ------------------------------------------------------------------ curso sem turma aberta
create temp table r11 as select pg_temp.rpc(pg_temp.payload('100000011', 'onze@exemplo.test', 'HASH000011', curso => 'aaaaaaaa-0000-4000-8000-000000000002')) as r;
select is((select r->>'resultado' from r11), 'sem_turma', 'sem turma: resultado');
select is((select r->>'aluno_novo' from r11), 'true', 'sem turma: conta criada');
select is((select r->>'link_acesso' from r11), 'true', 'sem turma: link de criar senha');
select ok((select r->'turma' = 'null'::jsonb and r->'matricula_id' = 'null'::jsonb from r11), 'sem turma: sem turma nem matrícula na resposta');
select is((select count(*)::int from "Matricula" m join "Usuario" u on u.id = m."alunoId" where u."cpfCnpj" = pg_temp.cpf('100000011')), 0, 'sem turma: nenhuma matrícula');
select is((select count(*)::int from "LogAuditoria" where acao = 'TAXA_PAGA_SEM_TURMA_PELO_SITE' and detalhe->>'transacao' = 'HASH000011'), 1, 'sem turma: log para a secretaria');
create temp table r11b as select pg_temp.rpc(pg_temp.payload('100000011', 'onze@exemplo.test', 'HASH000011', curso => 'aaaaaaaa-0000-4000-8000-000000000002')) as r;
select results_eq($$ select r->>'resultado', r->>'repetido', r->>'aluno_novo' from r11b $$, $$ values ('sem_turma', 'true', 'true') $$, 'sem turma repetida: mesmo resultado');
select is((select count(*)::int from "LogAuditoria" where detalhe->>'transacao' = 'HASH000011'), 2, 'sem turma repetida: nenhum log a mais (cadastro + sem turma)');

-- ------------------------------------------------------------------ matrícula aberta feita na própria escola
insert into "Usuario" (id, nome, email, "cpfCnpj", "senhaHash", papel, "atualizadoEm", "tipoDocumento") values
  ('u-escola', 'Aluno Da Escola', 'daescola@exemplo.test', pg_temp.cpf('100000012'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF'),
  ('u-escola2', 'Aluno Da Escola Dois', 'daescola2@exemplo.test', pg_temp.cpf('100000013'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF'),
  ('u-cancelou', 'Aluno Que Cancelou', 'cancelou@exemplo.test', pg_temp.cpf('100000014'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF');
insert into "Matricula" (id, "alunoId", "turmaId", plano, forma, "valorCurso", "valorTaxaMatricula", "atualizadoEm", "taxaConfirmada") values
  ('m-escola', 'u-escola', 'turma-2', 'A_VISTA', 'PIX', 280, 100, now(), false),
  ('m-escola2', 'u-escola2', 'turma-2', 'PARCELADO', 'CREDITO', 300, 100, now(), true),
  ('m-cancelou', 'u-cancelou', 'turma-1', 'A_VISTA', 'PIX', 280, 100, now(), false);
update "Matricula" set "statusPagamento" = 'CANCELADO' where id = 'm-cancelou';
update "Turma" set vagas = 30 where id = 'turma-1';
create temp table r12 as select pg_temp.rpc(pg_temp.payload('100000012', 'daescola@exemplo.test', 'HASH000012')) as r;
select results_eq($$ select r->>'matricula_id', r->>'matricula_existente', r->'turma'->>'id' from r12 $$,
                  $$ values ('m-escola', 'true', 'turma-2') $$, 'matrícula da escola: a taxa vai para ela');
select results_eq($$ select "taxaConfirmada", "taxaConfirmadaPor", "valorCurso", "valorTaxaMatricula", plano::text from "Matricula" where id = 'm-escola' $$,
                  $$ values (true, 'site cruzvermelhariodejaneiro.org', 279.00::numeric(10,2), 99.00::numeric(10,2), 'A_VISTA') $$,
                  'matrícula da escola: taxa confirmada e saldo mantido (preço sem a taxa)');
select is((select count(*)::int from "LogAuditoria" where acao = 'CONFIRMOU_TAXA_PELO_SITE' and "alvoId" = 'm-escola'), 1, 'matrícula da escola: log CONFIRMOU_TAXA_PELO_SITE');
create temp table r13 as select pg_temp.rpc(pg_temp.payload('100000013', 'daescola2@exemplo.test', 'HASH000013')) as r;
select results_eq($$ select r->>'matricula_id', r->>'aviso' from r13 $$, $$ values ('m-escola2', 'taxa_ja_confirmada') $$,
                  'taxa já confirmada na escola: aviso para a secretaria');
select results_eq($$ select "valorCurso", "valorTaxaMatricula", plano::text from "Matricula" where id = 'm-escola2' $$,
                  $$ values (300.00::numeric(10,2), 100.00::numeric(10,2), 'PARCELADO') $$, 'taxa já confirmada: matrícula intacta');
select is((select count(*)::int from "Pagamento" where "matriculaId" = 'm-escola2' and "gatewayHash" = 'HASH000013'), 1, 'taxa já confirmada: pagamento registrado para estorno');
create temp table r14 as select pg_temp.rpc(pg_temp.payload('100000014', 'cancelou@exemplo.test', 'HASH000014')) as r;
select is((select r->'turma'->>'id' from r14), 'turma-2', 'cancelou na turma-1: vai para a turma-2 (sem violar a chave aluno+turma)');
create temp table r15 as select pg_temp.rpc(pg_temp.payload('100000015', 'quinze@exemplo.test', 'HASH000015', extra => '{"pago_em": "2099-01-01T00:00:00Z"}')) as r;
select ok((select "taxaConfirmadaEm" between (now() at time zone 'UTC') - interval '1 minute' and (now() at time zone 'UTC') + interval '1 second'
            from "Matricula" where id = (select r->>'matricula_id' from r15)), 'pago_em no futuro vira agora');
select is((select "taxaConfirmadaEm" from "Matricula" where id = (select r->>'matricula_id' from r1)), '2026-09-28 19:00:00'::timestamp(3), 'pago_em do site vira taxaConfirmadaEm');

update "TokenAuth" set "usadoEm" = now() at time zone 'UTC' where "tokenHash" = encode(sha256(convert_to('link-100000001HASH000001', 'UTF8')), 'hex');
select is((pg_temp.rpc(pg_temp.payload('100000001', 'aluno.um@exemplo.test', 'HASH000001')))->>'link_acesso', 'false', 'link já usado: não é oferecido de novo');

-- ------------------------------------------------------------------ dados inválidos (erro 22023, nada gravado)
select throws_ok($$ select pg_temp.rpc('[]'::jsonb) $$, '22023', null, 'inválido: não é objeto');
select throws_ok($$ select pg_temp.rpc(pg_temp.payload('100000020', 'v1@exemplo.test', 'HASH000020', extra => '{"cpf": "12345678900"}')) $$, '22023', 'dados inválidos: cpf', 'inválido: dígito do CPF');
select throws_ok($$ select pg_temp.rpc(pg_temp.payload('100000020', 'v1@exemplo.test', 'HASH000020', extra => '{"cpf": "11111111111"}')) $$, '22023', 'dados inválidos: cpf', 'inválido: CPF repetido');
select throws_ok($$ select pg_temp.rpc(pg_temp.payload('100000020', 'sem-arroba', 'HASH000020')) $$, '22023', 'dados inválidos: email', 'inválido: e-mail');
select throws_ok($$ select pg_temp.rpc(pg_temp.payload('100000020', 'v1@exemplo.test', 'HASH000020', curso => 'bombeiro-civil')) $$, '22023', 'dados inválidos: curso_id', 'inválido: curso_id');
select throws_ok($$ select pg_temp.rpc(pg_temp.payload('100000020', 'v1@exemplo.test', 'X1')) $$, '22023', 'dados inválidos: transacao', 'inválido: transação curta');
select throws_ok($$ select pg_temp.rpc(pg_temp.payload('100000020', 'v1@exemplo.test', 'HASH000020', extra => '{"metodo": "boleto"}')) $$, '22023', 'dados inválidos: metodo', 'inválido: método');
select throws_ok($$ select pg_temp.rpc(pg_temp.payload('100000020', 'v1@exemplo.test', 'HASH000020', extra => '{"valor_centavos": 0}')) $$, '22023', 'dados inválidos: valor_centavos', 'inválido: valor zero');
select throws_ok($$ select pg_temp.rpc(pg_temp.payload('100000020', 'v1@exemplo.test', 'HASH000020', extra => '{"total_centavos": 100}')) $$, '22023', 'dados inválidos: total_centavos', 'inválido: total menor que a inscrição');
select throws_ok($$ select pg_temp.rpc(pg_temp.payload('100000020', 'v1@exemplo.test', 'HASH000020', extra => '{"pago_em": "ontem"}')) $$, '22023', 'dados inválidos: pago_em', 'inválido: data');
select throws_ok($$ select pg_temp.rpc(pg_temp.payload('100000020', 'v1@exemplo.test', 'HASH000020', extra => '{"senha_hash": "$2b$10$abc"}')) $$, '22023', 'dados inválidos: senha_hash', 'inválido: hash que não é argon2id');
select throws_ok($$ select pg_temp.rpc(pg_temp.payload('100000020', 'v1@exemplo.test', 'HASH000020', extra => '{"senha_hash": null}')) $$, '22023', 'dados inválidos: senha_hash e token_hash (obrigatórios para conta nova)', 'inválido: conta nova sem senha');
select throws_ok($$ select pg_temp.rpc(pg_temp.payload('100000020', 'v1@exemplo.test', 'HASH000020', extra => '{"token_hash": null}')) $$, '22023', 'dados inválidos: senha_hash e token_hash (obrigatórios para conta nova)', 'inválido: conta nova sem link');
select throws_ok($$ select pg_temp.rpc(pg_temp.payload('100000020', 'v1@exemplo.test', 'HASH000020', extra => '{"token_hash": "curto"}')) $$, '22023', 'dados inválidos: token_hash', 'inválido: token_hash fora do formato');
select throws_ok($$ select pg_temp.rpc(pg_temp.payload('100000020', 'v1@exemplo.test', 'HASH000020', extra => '{"nome": "Ana"}')) $$, '22023', 'dados inválidos: nome', 'inválido: nome sem sobrenome');
select is((select count(*)::int from "Usuario" where email = 'v1@exemplo.test'), 0, 'inválidos: nada gravado');

select * from finish();
rollback;
