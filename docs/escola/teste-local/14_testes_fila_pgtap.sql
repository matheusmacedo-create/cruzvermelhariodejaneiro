-- Testes (pgTAP) da revisão de dinheiro de 09/10/2026 (pagar-tudo-build/revisao-dinheiro.md, achados 2 e 4), dados
-- fictícios. Rodar depois de 00, 01, 02 e matricula_rapida_v2.sql. Tudo numa transação desfeita no fim.
--   A. "Só a taxa" com fila de quem pagou tudo (fila_espera): não toma a vaga da turma nova antes da fila.
--   B. "Pagou tudo" com a turma vendida que não serve mais: fica sem turma (vai para a fila no site), sem furar a fila.
\set ON_ERROR_STOP 1
\set QUIET 1
begin;
create extension if not exists pgtap;
select plan(19);

create function pg_temp.cpf(base text) returns text language plpgsql as $$
declare s int; d1 int; d2 int;
begin
  s := 0; for i in 1..9 loop s := s + substr(base, i, 1)::int * (11 - i); end loop; d1 := (s * 10) % 11 % 10;
  s := 0; for i in 1..9 loop s := s + substr(base, i, 1)::int * (12 - i); end loop; s := s + d1 * 2; d2 := (s * 10) % 11 % 10;
  return base || d1 || d2;
end $$;
create function pg_temp.dia(n int) returns timestamp language sql as $$
  select ((now() at time zone 'America/Sao_Paulo')::date + n)::timestamp + time '12:00' $$;
-- "Só a taxa" (v1): R$ 99, PIX, pago agora.
create function pg_temp.taxa(base text, transacao text, curso text, extra jsonb default '{}') returns jsonb language sql as $$
  select jsonb_build_object('nome', 'Pessoa Fila ' || base, 'cpf', pg_temp.cpf(base), 'email', 'fila' || base || '@exemplo.test',
    'celular', '21987654321', 'curso_id', curso, 'transacao', transacao, 'metodo', 'pix', 'valor_centavos', 9900,
    'total_centavos', 9900, 'pago_em', to_char(now() at time zone 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"'),
    'senha_hash', '$argon2id$v=19$m=65536,t=4,p=1$c2FsdHNhbHRzYWx0$aGFzaGhhc2hoYXNoaGFzaGhhc2hoYXNoaGFzaA',
    'token_hash', encode(sha256(convert_to('link-' || base || transacao, 'UTF8')), 'hex')) || extra $$;
-- "Pagou tudo" com a turma vendida: R$ 99 + R$ 150, PIX.
create function pg_temp.tudo(base text, transacao text, ref text, curso text, extra jsonb default '{}') returns jsonb language sql as $$
  select pg_temp.taxa(base, transacao, curso) || jsonb_build_object('matricula_centavos', 15000, 'parcelas', 1, 'total_centavos', 24900,
    'referencia', ref) || extra $$;
create function pg_temp.rpc(dados jsonb) returns jsonb language plpgsql as $$
declare r jsonb;
begin
  set local role service_role;
  r := public.matricula_rapida(dados);
  reset role;
  return r;
end $$;
-- Turma como a escola cria: com uma aula no dia indicado (null = sem aulas), criada há `idade`.
create function pg_temp.turma(id text, curso text, vagas int, inicio int, aula int, idade interval default interval '1 day') returns void language sql as $$
  insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto", "criadoEm")
  values (id, curso, vagas, 'ABERTA', pg_temp.dia(inicio), (now() at time zone 'UTC') - idade);
  insert into "AulaData" select id || '-ad', id, pg_temp.dia(aula)::date, '18:00 - 22:00' where aula is not null;
$$;
create function pg_temp.na_turma(base text, turma text) returns int language sql as $$
  select count(*)::int from "Matricula" m join "Usuario" u on u.id = m."alunoId" where u.email = 'fila' || base || '@exemplo.test' and m."turmaId" = turma $$;

insert into "Curso" (id, nome, "cargaHoraria", "precoAvista", "precoCheio", parcelas, "valorParcela", ativo) values
  ('f1f1f1f1-0000-4000-8000-000000000001', 'Fila Turma Nova', 8, 150, 150, 2, 77.5, true),
  ('f1f1f1f1-0000-4000-8000-000000000002', 'Fila Turma Pronta', 8, 150, 150, 2, 77.5, true),
  ('f1f1f1f1-0000-4000-8000-000000000003', 'Fila Sem Aulas', 8, 150, 150, 2, 77.5, true),
  ('f1f1f1f1-0000-4000-8000-000000000004', 'Fila Sem Fila', 8, 150, 150, 2, 77.5, true),
  ('f1f1f1f1-0000-4000-8000-000000000005', 'Fila Pedida Fechou', 8, 150, 150, 2, 77.5, true);

-- ------------------------------------------------------------------ entradas
select throws_ok($$ select pg_temp.rpc(pg_temp.taxa('950000099', 'FIL0000099', 'f1f1f1f1-0000-4000-8000-000000000004', '{"fila_espera": "x"}')) $$,
                 '22023', 'dados inválidos: fila_espera', 'fila_espera que não é número: recusada');
select throws_ok($$ select pg_temp.rpc(pg_temp.taxa('950000099', 'FIL0000099', 'f1f1f1f1-0000-4000-8000-000000000004', '{"fila_espera": -1}')) $$,
                 '22023', 'dados inválidos: fila_espera', 'fila_espera negativa: recusada');

-- ------------------------------------------------------------------ A1. turma criada agora (1 vaga), 1 pessoa na fila (S1)
select pg_temp.turma('fn-1', 'f1f1f1f1-0000-4000-8000-000000000001', 1, 30, 30, interval '5 minutes');
create temp table a1 as select pg_temp.rpc(pg_temp.taxa('950000001', 'FIL0000001', 'f1f1f1f1-0000-4000-8000-000000000001', '{"fila_espera": 1}')) as r;
select is((select r->>'resultado' from a1), 'sem_turma', 'só a taxa com fila, turma de 5 min: não entra (fica na lista de interesse)');
select is(pg_temp.na_turma('950000001', 'fn-1'), 0, 'só a taxa com fila, turma de 5 min: nenhuma matrícula na turma nova');
-- A vaga continua para a fila: a espera (rotina) ainda entra depois da carência; aqui, a turma segue com 0 ocupadas.
select is((select count(*)::int from "Matricula" where "turmaId" = 'fn-1'), 0, 'turma nova: a vaga continua livre para quem pagou tudo');

-- ------------------------------------------------------------------ A2. turma pronta (7 h, com aulas)
select pg_temp.turma('fp-1', 'f1f1f1f1-0000-4000-8000-000000000002', 2, 30, 30, interval '7 hours');
create temp table a2 as select pg_temp.rpc(pg_temp.taxa('950000002', 'FIL0000002', 'f1f1f1f1-0000-4000-8000-000000000002', '{"fila_espera": 1}')) as r;
select is((select r->>'resultado' from a2), 'matriculado', 'só a taxa com fila de 1, turma pronta de 2 vagas: entra (sobra vaga além da fila)');
select is((select r->'turma'->>'id' from a2), 'fp-1', 'só a taxa com fila de 1: na turma pronta');
create temp table a2b as select pg_temp.rpc(pg_temp.taxa('950000003', 'FIL0000003', 'f1f1f1f1-0000-4000-8000-000000000002', '{"fila_espera": 1}')) as r;
select is((select r->>'resultado' from a2b), 'sem_turma', 'só a taxa com fila de 1, a última vaga: fica para a fila');
create temp table a2c as select pg_temp.rpc(pg_temp.taxa('950000004', 'FIL0000004', 'f1f1f1f1-0000-4000-8000-000000000002', '{"fila_espera": 0}')) as r;
select is((select r->>'resultado' from a2c), 'matriculado', 'só a taxa sem fila: a regra de antes (a última vaga é dela)');

-- ------------------------------------------------------------------ A3. turma sem aulas cadastradas
select pg_temp.turma('fs-1', 'f1f1f1f1-0000-4000-8000-000000000003', 10, 30, null, interval '2 days');
create temp table a3 as select pg_temp.rpc(pg_temp.taxa('950000005', 'FIL0000005', 'f1f1f1f1-0000-4000-8000-000000000003', '{"fila_espera": 2}')) as r;
select is((select r->>'resultado' from a3), 'sem_turma', 'só a taxa com fila, turma sem aulas: não entra');
create temp table a3b as select pg_temp.rpc(pg_temp.taxa('950000006', 'FIL0000006', 'f1f1f1f1-0000-4000-8000-000000000003')) as r;
select is((select r->>'resultado' from a3b), 'matriculado', 'só a taxa sem o campo (site antigo): a regra de antes, mesmo sem aulas');

-- ------------------------------------------------------------------ A4. sem fila e turma nova: a regra de antes
select pg_temp.turma('fz-1', 'f1f1f1f1-0000-4000-8000-000000000004', 1, 30, 30, interval '1 minute');
create temp table a4 as select pg_temp.rpc(pg_temp.taxa('950000007', 'FIL0000007', 'f1f1f1f1-0000-4000-8000-000000000004', '{"fila_espera": 0}')) as r;
select is((select r->>'resultado' from a4), 'matriculado', 'fila vazia: a só a taxa entra na turma nova, como antes');

-- ------------------------------------------------------------------ B. pagou tudo, turma vendida que já começou, outra com vaga (S2)
select pg_temp.turma('fv-passada', 'f1f1f1f1-0000-4000-8000-000000000005', 10, -1, -1, interval '20 days');
select pg_temp.turma('fv-proxima', 'f1f1f1f1-0000-4000-8000-000000000005', 1, 2, 2, interval '1 minute');
create temp table b1 as select pg_temp.rpc(pg_temp.tudo('950000008', 'FIL0000008', '958', 'f1f1f1f1-0000-4000-8000-000000000005',
  '{"turma_id": "fv-passada"}')) as r;
select is((select r->>'resultado' from b1), 'sem_turma', 'pagou tudo, turma vendida já começou: sem turma (o site o põe na fila)');
select ok((select r->'avisos' ? 'matricula_paga_sem_turma' from b1), 'pagou tudo, turma vendida já começou: aviso matricula_paga_sem_turma');
select ok((select not (r->'avisos' ? 'turma_diferente') from b1), 'pagou tudo, turma vendida já começou: sem turma_diferente');
select is(pg_temp.na_turma('950000008', 'fv-proxima'), 0, 'pagou tudo, turma vendida já começou: não entra na próxima turma (sem carência, na frente da fila)');
select is((select count(*)::int from "LogAuditoria" l join "Usuario" u on u.id = l."alvoId"
            where l.acao = 'TAXA_PAGA_SEM_TURMA_PELO_SITE' and u.email = 'fila950000008@exemplo.test' and (l.detalhe->>'pagouMatricula')::boolean),
          1, 'pagou tudo sem turma: o LogAuditoria TAXA_PAGA_SEM_TURMA_PELO_SITE registra (limpar_teste.sql acha por ele)');
-- No "pagou tudo", fila_espera é ignorada (quem pagou tudo é a própria fila).
select pg_temp.turma('fv-vendida', 'f1f1f1f1-0000-4000-8000-000000000005', 5, 20, 20, interval '1 day');
create temp table b2 as select pg_temp.rpc(pg_temp.tudo('950000009', 'FIL0000009', '959', 'f1f1f1f1-0000-4000-8000-000000000005',
  '{"turma_id": "fv-vendida", "fila_espera": 9}')) as r;
select is((select r->>'resultado' || '/' || (r->'turma'->>'id') from b2), 'matriculado/fv-vendida', 'pagou tudo na turma vendida: entra, e fila_espera não conta');
select is((select r->>'matricula_paga' from b2), 'true', 'pagou tudo na turma vendida: matrícula paga');

select * from finish();
rollback;
