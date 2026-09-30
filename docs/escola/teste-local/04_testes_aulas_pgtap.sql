-- Testes (pgTAP) da public.aulas_do_aluno numa cópia local do banco da escola, com dados fictícios.
-- Os CPFs são gerados aqui mesmo (só dígito verificador válido), nunca saem desta cópia.
\set ON_ERROR_STOP 1
\set QUIET 1
begin;
create extension if not exists pgtap;
select plan(24);

-- ------------------------------------------------------------------ apoio
create function pg_temp.cpf(base text) returns text language plpgsql as $$
declare s int; d1 int; d2 int;
begin
  s := 0; for i in 1..9 loop s := s + substr(base, i, 1)::int * (11 - i); end loop; d1 := (s * 10) % 11 % 10;
  s := 0; for i in 1..9 loop s := s + substr(base, i, 1)::int * (12 - i); end loop; s := s + d1 * 2; d2 := (s * 10) % 11 % 10;
  return base || d1 || d2;
end $$;
create function pg_temp.rpc(dados jsonb) returns jsonb language plpgsql as $$
declare r jsonb;
begin
  set local role service_role;
  r := public.aulas_do_aluno(dados);
  reset role;
  return r;
end $$;
create function pg_temp.hoje() returns date language sql as $$ select (now() at time zone 'America/Sao_Paulo')::date $$;
create function pg_temp.pedido(base text, dia date default null) returns jsonb language sql as $$
  select jsonb_build_object('cpf', pg_temp.cpf(base), 'data', to_char(coalesce(dia, pg_temp.hoje()), 'YYYY-MM-DD')) $$;

-- Cursos, turmas e aulas com datas relativas a hoje (Brasília).
insert into "Curso" (id, nome, "cargaHoraria", "precoAvista", "precoCheio", parcelas, "valorParcela", ativo) values
  ('dddddddd-0000-4000-8000-00000000a01', 'Curso de Teste da Noite', 80, 950, 1100, 6, 183.33, true),
  ('dddddddd-0000-4000-8000-00000000a02', 'Curso de Teste da Manhã', 8, 180, 200, 2, 100, true);
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto") values
  ('pt-noite', 'dddddddd-0000-4000-8000-00000000a01', 30, 'CONFIRMADA', pg_temp.hoje()::timestamp + time '21:00'),
  ('pt-manha', 'dddddddd-0000-4000-8000-00000000a02', 30, 'ABERTA', pg_temp.hoje()::timestamp + time '12:00'),
  ('pt-cancelada', 'dddddddd-0000-4000-8000-00000000a02', 30, 'CANCELADA', pg_temp.hoje()::timestamp + time '12:00');
insert into "AulaData" (id, "turmaId", data, horario) values
  ('pt-a1', 'pt-noite', pg_temp.hoje(), '18:00 - 22:00'),
  ('pt-a2', 'pt-noite', pg_temp.hoje() + 1, '18:00 - 22:00'),
  ('pt-a3', 'pt-manha', pg_temp.hoje(), '09:00 - 12:00'),
  ('pt-a4', 'pt-cancelada', pg_temp.hoje(), '13:00 - 17:00');
insert into "Usuario" (id, nome, email, "cpfCnpj", "atualizadoEm") values
  ('pu-1', 'Aluna Teste Dois Cursos', 'dois.cursos@exemplo.test', pg_temp.cpf('200000001'), now()),
  ('pu-2', 'Aluno Teste Cancelado', 'cancelado@exemplo.test', pg_temp.cpf('200000002'), now()),
  ('pu-3', 'Aluna Teste Estornada', 'estornada@exemplo.test', pg_temp.cpf('200000003'), now()),
  ('pu-4', 'Aluno Teste Turma Cancelada', 'turma.cancelada@exemplo.test', pg_temp.cpf('200000004'), now()),
  ('pu-5', 'Aluna Teste Só Amanhã', 'amanha@exemplo.test', pg_temp.cpf('200000005'), now()),
  ('pu-6', 'Aluno Teste Pendente', 'pendente@exemplo.test', pg_temp.cpf('200000006'), now()),
  ('pu-7', 'Pessoa Teste Sem Matrícula', 'sem.matricula@exemplo.test', pg_temp.cpf('200000007'), now());
insert into "Matricula" (id, "alunoId", "turmaId", plano, forma, "valorCurso", "statusPagamento", "atualizadoEm") values
  ('pm-1a', 'pu-1', 'pt-noite', 'A_VISTA', 'PIX', 1049, 'PAGO', now()),
  ('pm-1b', 'pu-1', 'pt-manha', 'A_VISTA', 'PIX', 280, 'PARCELADO', now()),
  ('pm-2', 'pu-2', 'pt-noite', 'A_VISTA', 'PIX', 1049, 'CANCELADO', now()),
  ('pm-3', 'pu-3', 'pt-noite', 'A_VISTA', 'PIX', 1049, 'ESTORNADO', now()),
  ('pm-4', 'pu-4', 'pt-cancelada', 'A_VISTA', 'PIX', 280, 'PAGO', now()),
  ('pm-6', 'pu-6', 'pt-manha', 'A_VISTA', 'PIX', 280, 'PENDENTE', now());
-- A aluna 5 tem aula só amanhã: matrícula numa turma só com a aula de amanhã.
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto") values
  ('pt-amanha', 'dddddddd-0000-4000-8000-00000000a01', 30, 'ABERTA', (pg_temp.hoje() + 1)::timestamp + time '21:00');
insert into "AulaData" (id, "turmaId", data, horario) values ('pt-a5', 'pt-amanha', pg_temp.hoje() + 1, '18:00 - 22:00');
insert into "Matricula" (id, "alunoId", "turmaId", plano, forma, "valorCurso", "statusPagamento", "atualizadoEm") values
  ('pm-5', 'pu-5', 'pt-amanha', 'A_VISTA', 'PIX', 1049, 'PAGO', now());

-- ------------------------------------------------------------------ segurança
select is((select prosecdef from pg_proc where oid = 'public.aulas_do_aluno(jsonb)'::regprocedure), true, 'security definer');
select is((select proconfig from pg_proc where oid = 'public.aulas_do_aluno(jsonb)'::regprocedure), array['search_path=""'], 'search_path vazio');
select is((select provolatile from pg_proc where oid = 'public.aulas_do_aluno(jsonb)'::regprocedure), 's'::"char", 'stable: só lê');
select ok(has_function_privilege('service_role', 'public.aulas_do_aluno(jsonb)', 'EXECUTE'), 'service_role executa');
select ok(not has_function_privilege('anon', 'public.aulas_do_aluno(jsonb)', 'EXECUTE'), 'anon não executa');
select ok(not has_function_privilege('authenticated', 'public.aulas_do_aluno(jsonb)', 'EXECUTE'), 'authenticated não executa');
select throws_ok($$ set local role service_role; select count(*) from "Usuario" $$, '42501', null, 'a chave continua sem ler tabelas');
reset role;

-- ------------------------------------------------------------------ aulas do dia
create temp table r1 as select pg_temp.rpc(pg_temp.pedido('200000001')) as r;
select is((select r->'aluno' from r1), '{"nome": "Aluna Teste Dois Cursos", "email": "dois.cursos@exemplo.test"}'::jsonb, 'aluno com aula hoje: nome e e-mail');
select is((select jsonb_array_length(r->'aulas') from r1), 2, 'dois cursos hoje: duas aulas');
select is((select r->'aulas'->0 from r1), jsonb_build_object('aula_id', 'pt-a3', 'data', to_char(pg_temp.hoje(), 'YYYY-MM-DD'), 'horario', '09:00 - 12:00',
    'turma_id', 'pt-manha', 'curso_id', 'dddddddd-0000-4000-8000-00000000a02', 'curso_nome', 'Curso de Teste da Manhã', 'carga_horaria', 8),
  'primeira aula: a da manhã, com todos os campos');
select is((select r->'aulas'->1->>'aula_id' from r1), 'pt-a1', 'segunda aula: a da noite (só a de hoje, não a de amanhã)');
select is((select r->>'ok' from r1), 'true', 'ok');
select is((pg_temp.rpc(pg_temp.pedido('200000001', pg_temp.hoje() + 1)))->'aulas'->0->>'aula_id', 'pt-a2', 'outro dia: as aulas daquele dia');
select is((pg_temp.rpc(pg_temp.pedido('200000006')))->'aulas'->0->>'aula_id', 'pt-a3', 'pagamento do curso pendente: a aula conta (presença não depende do pagamento)');

-- ------------------------------------------------------------------ sem aula: nem nome nem e-mail
select is(pg_temp.rpc(pg_temp.pedido('200000002')), '{"ok": true, "aluno": null, "aulas": []}'::jsonb, 'matrícula cancelada: nada');
select is(pg_temp.rpc(pg_temp.pedido('200000003')), '{"ok": true, "aluno": null, "aulas": []}'::jsonb, 'matrícula estornada: nada');
select is(pg_temp.rpc(pg_temp.pedido('200000004')), '{"ok": true, "aluno": null, "aulas": []}'::jsonb, 'turma cancelada: nada');
select is(pg_temp.rpc(pg_temp.pedido('200000005')), '{"ok": true, "aluno": null, "aulas": []}'::jsonb, 'aula só amanhã: hoje nada, nem o nome');
select is(pg_temp.rpc(pg_temp.pedido('200000007')), '{"ok": true, "aluno": null, "aulas": []}'::jsonb, 'conta sem matrícula: nada');
select is(pg_temp.rpc(pg_temp.pedido('299999999')), '{"ok": true, "aluno": null, "aulas": []}'::jsonb, 'CPF que não existe: nada');

-- ------------------------------------------------------------------ dados inválidos (erro 22023)
select throws_ok($$ select pg_temp.rpc('[]'::jsonb) $$, '22023', 'dados inválidos: esperado um objeto JSON', 'inválido: não é objeto');
select throws_ok($$ select pg_temp.rpc(jsonb_build_object('cpf', '12345678900', 'data', '2026-10-22')) $$, '22023', 'dados inválidos: cpf', 'inválido: dígito do CPF');
select throws_ok($$ select pg_temp.rpc(jsonb_build_object('cpf', pg_temp.cpf('200000001'), 'data', '22/10/2026')) $$, '22023', 'dados inválidos: data', 'inválido: data fora do formato');
select throws_ok($$ select pg_temp.rpc(jsonb_build_object('cpf', pg_temp.cpf('200000001'), 'data', '2026-02-30')) $$, '22023', 'dados inválidos: data', 'inválido: data que não existe');

select * from finish();
rollback;
