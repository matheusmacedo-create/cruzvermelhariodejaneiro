-- Testes (pgTAP) da public.aulas_do_dia numa cópia local do banco da escola, com dados fictícios.
-- Tudo roda numa transação desfeita no fim: nada fica na cópia.
\set ON_ERROR_STOP 1
\set QUIET 1
begin;
create extension if not exists pgtap;
select plan(26);

-- ------------------------------------------------------------------ apoio
create function pg_temp.rpc(dados jsonb) returns jsonb language plpgsql as $$
declare r jsonb;
begin
  set local role service_role;
  r := public.aulas_do_dia(dados);
  reset role;
  return r;
end $$;
create function pg_temp.hoje() returns date language sql as $$ select (now() at time zone 'America/Sao_Paulo')::date $$;
create function pg_temp.dia(n int) returns jsonb language sql as $$ select jsonb_build_object('data', to_char(pg_temp.hoje() + n, 'YYYY-MM-DD')) $$;

-- Cursos, turmas e aulas com datas relativas a hoje (Brasília). Amanhã: três alunos com aula.
insert into "Curso" (id, nome, "cargaHoraria", "precoAvista", "precoCheio", parcelas, "valorParcela", ativo) values
  ('dddddddd-0000-4000-8000-00000000d01', 'Curso Dia Noite', 80, 950, 1100, 6, 183.33, true),
  ('dddddddd-0000-4000-8000-00000000d02', 'Curso Dia Manhã', 8, 180, 200, 2, 100, true);
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto") values
  ('dt-noite', 'dddddddd-0000-4000-8000-00000000d01', 30, 'CONFIRMADA', pg_temp.hoje()::timestamp),
  ('dt-manha', 'dddddddd-0000-4000-8000-00000000d02', 30, 'ABERTA', pg_temp.hoje()::timestamp),
  ('dt-cancelada', 'dddddddd-0000-4000-8000-00000000d02', 30, 'CANCELADA', pg_temp.hoje()::timestamp),
  ('dt-longe', 'dddddddd-0000-4000-8000-00000000d01', 30, 'ABERTA', pg_temp.hoje()::timestamp);
insert into "AulaData" (id, "turmaId", data, horario) values
  ('da-1', 'dt-noite', pg_temp.hoje() + 1, '18:00 - 22:00'),
  ('da-2', 'dt-manha', pg_temp.hoje() + 1, '09:00 - 12:00'),
  ('da-3', 'dt-cancelada', pg_temp.hoje() + 1, '13:00 - 17:00'),
  ('da-4', 'dt-noite', pg_temp.hoje() + 2, '18:00 - 22:00'),
  ('da-5', 'dt-longe', pg_temp.hoje() + 7, '18:00 - 22:00'),
  ('da-6', 'dt-longe', pg_temp.hoje() - 1, '18:00 - 22:00');
insert into "Usuario" (id, nome, email, "cpfCnpj", celular, papel, "bloqueioTotal", "atualizadoEm") values
  ('du-1', 'Beatriz Aluna Dois Cursos', 'beatriz.dois@exemplo.test', '90000000101', '21999990001', 'ALUNO', false, now()),
  ('du-2', 'Alberto Aluno Noite', 'alberto.noite@exemplo.test', '90000000102', null, 'ALUNO', false, now()),
  ('du-3', 'Carlos Aluno Cancelado', 'carlos.cancelado@exemplo.test', '90000000103', null, 'ALUNO', false, now()),
  ('du-4', 'Diana Aluna Estornada', 'diana.estornada@exemplo.test', '90000000104', null, 'ALUNO', false, now()),
  ('du-5', 'Eduardo Aluno Turma Cancelada', 'eduardo.turma@exemplo.test', '90000000105', null, 'ALUNO', false, now()),
  ('du-6', 'Fátima Secretária Matriculada', 'fatima.secretaria@exemplo.test', '90000000106', null, 'SECRETARIA', false, now()),
  ('du-7', 'Gustavo Aluno Bloqueado', 'gustavo.bloqueado@exemplo.test', '90000000107', null, 'ALUNO', true, now()),
  ('du-8', 'Helena Aluna Pendente', 'helena.pendente@exemplo.test', '90000000108', '21999990008', 'ALUNO', false, now());
insert into "Matricula" (id, "alunoId", "turmaId", plano, forma, "valorCurso", "statusPagamento", "atualizadoEm") values
  ('dm-1a', 'du-1', 'dt-noite', 'A_VISTA', 'PIX', 1049, 'PAGO', now()),
  ('dm-1b', 'du-1', 'dt-manha', 'A_VISTA', 'PIX', 280, 'PARCELADO', now()),
  ('dm-2', 'du-2', 'dt-noite', 'A_VISTA', 'PIX', 1049, 'PAGO', now()),
  ('dm-3', 'du-3', 'dt-noite', 'A_VISTA', 'PIX', 1049, 'CANCELADO', now()),
  ('dm-4', 'du-4', 'dt-noite', 'A_VISTA', 'PIX', 1049, 'ESTORNADO', now()),
  ('dm-5', 'du-5', 'dt-cancelada', 'A_VISTA', 'PIX', 280, 'PAGO', now()),
  ('dm-6', 'du-6', 'dt-noite', 'A_VISTA', 'PIX', 1049, 'PAGO', now()),
  ('dm-7', 'du-7', 'dt-noite', 'A_VISTA', 'PIX', 1049, 'PAGO', now()),
  ('dm-8', 'du-8', 'dt-manha', 'A_VISTA', 'PIX', 280, 'PENDENTE', now()),
  ('dm-9', 'du-2', 'dt-longe', 'A_VISTA', 'PIX', 1049, 'PAGO', now());

-- ------------------------------------------------------------------ segurança e privilégios
select ok((select prosecdef from pg_proc where oid = 'public.aulas_do_dia(jsonb)'::regprocedure), 'security definer');
select is((select proconfig from pg_proc where oid = 'public.aulas_do_dia(jsonb)'::regprocedure), array['search_path=""'], 'search_path vazio');
select ok(not has_function_privilege('anon', 'public.aulas_do_dia(jsonb)', 'execute'), 'anon não executa');
select ok(not has_function_privilege('authenticated', 'public.aulas_do_dia(jsonb)', 'execute'), 'authenticated não executa');
select ok(has_function_privilege('service_role', 'public.aulas_do_dia(jsonb)', 'execute'), 'service_role executa');
select throws_ok($$ set local role service_role; select count(*) from "Usuario" $$, '42501', null, 'service_role continua sem ler Usuario direto');
reset role;

-- ------------------------------------------------------------------ contrato
create temp table r as select pg_temp.rpc(pg_temp.dia(1)) as j;
select is((select j->>'ok' from r), 'true', 'ok verdadeiro');
select is((select j->>'data' from r), to_char(pg_temp.hoje() + 1, 'YYYY-MM-DD'), 'devolve a data pedida');
select is((select jsonb_array_length(j->'alunos') from r), 3, 'amanhã: três alunos (Alberto, Beatriz, Helena)');
select is((select array_agg(a->>'nome' order by ord) from r, jsonb_array_elements(j->'alunos') with ordinality as t(a, ord)),
  array['Alberto Aluno Noite', 'Beatriz Aluna Dois Cursos', 'Helena Aluna Pendente'], 'alunos em ordem de nome');
select is((select (j->'alunos'->1) - 'aulas' from r),
  jsonb_build_object('aluno_id', 'du-1', 'nome', 'Beatriz Aluna Dois Cursos', 'email', 'beatriz.dois@exemplo.test', 'celular', '21999990001'),
  'campos do aluno: id, nome, e-mail e celular (sem CPF)');
select is((select jsonb_array_length(j->'alunos'->1->'aulas') from r), 2, 'aluna com dois cursos no dia: as duas aulas no mesmo item');
select is((select j->'alunos'->1->'aulas'->0 from r),
  jsonb_build_object('aula_id', 'da-2', 'horario', '09:00 - 12:00', 'turma_id', 'dt-manha', 'curso_id', 'dddddddd-0000-4000-8000-00000000d02', 'curso_nome', 'Curso Dia Manhã'),
  'aulas em ordem de horário, com turma e curso');
select is((select j->'alunos'->0->>'celular' from r), null, 'celular vazio vem nulo');
select ok((select not (j::text like '%cpf%' or j::text like '%90000000%') from r), 'não devolve CPF');

-- ------------------------------------------------------------------ exclusões
select ok((select not (j::text like '%Carlos%') from r), 'matrícula cancelada fica de fora');
select ok((select not (j::text like '%Diana%') from r), 'matrícula estornada fica de fora');
select ok((select not (j::text like '%Eduardo%') from r), 'turma cancelada fica de fora');
select ok((select not (j::text like '%Fátima%') from r), 'conta que não é de aluno fica de fora');
select ok((select not (j::text like '%Gustavo%') from r), 'conta com bloqueio total fica de fora');
select is((select pg_temp.rpc(pg_temp.dia(3))), jsonb_build_object('ok', true, 'data', to_char(pg_temp.hoje() + 3, 'YYYY-MM-DD'), 'alunos', '[]'::jsonb), 'dia sem aula: lista vazia');

-- ------------------------------------------------------------------ janela de datas e dados inválidos
select is((select jsonb_array_length(pg_temp.rpc(pg_temp.dia(7))->'alunos')), 1, 'daqui a 7 dias: ainda vale');
select is((select jsonb_array_length(pg_temp.rpc(pg_temp.dia(-1))->'alunos')), 1, 'ontem: ainda vale');
select throws_ok($$ select pg_temp.rpc(pg_temp.dia(8)) $$, '22023', 'dados inválidos: data fora da janela (de ontem a daqui a 7 dias)', 'daqui a 8 dias: fora da janela');
select throws_ok($$ select pg_temp.rpc(pg_temp.dia(-2)) $$, '22023', 'dados inválidos: data fora da janela (de ontem a daqui a 7 dias)', 'anteontem: fora da janela');
select throws_like($$ select pg_temp.rpc('{"data": "2026-02-30"}') $$, 'dados inválidos: data%', 'data inexistente');

select * from finish();
rollback;
