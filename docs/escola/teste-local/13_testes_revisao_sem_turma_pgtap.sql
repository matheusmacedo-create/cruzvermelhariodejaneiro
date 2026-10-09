-- Testes (pgTAP) das correções das revisões financeira e técnica da venda sem turma (spec-pagar-tudo.md, 10.16).
-- Pega os casos da revisao-sem-turma/12_revisao_sem_turma_pgtap.sql, com as asserções "PROBLEMA" invertidas
-- conforme a correção, e acrescenta os campos novos (so_conta, inicio_ate) e os motivos novos.
-- Rodar depois de 00, 01, 02 e matricula_rapida_v2.sql. Tudo numa transação desfeita no fim. Dados fictícios.
\set ON_ERROR_STOP 1
\set QUIET 1
begin;
create extension if not exists pgtap;
select plan(46);

create function pg_temp.cpf(base text) returns text language plpgsql as $$
declare s int; d1 int; d2 int;
begin
  s := 0; for i in 1..9 loop s := s + substr(base, i, 1)::int * (11 - i); end loop; d1 := (s * 10) % 11 % 10;
  s := 0; for i in 1..9 loop s := s + substr(base, i, 1)::int * (12 - i); end loop; s := s + d1 * 2; d2 := (s * 10) % 11 % 10;
  return base || d1 || d2;
end $$;
create function pg_temp.dia(n int) returns timestamp language sql as $$
  select ((now() at time zone 'America/Sao_Paulo')::date + n)::timestamp + time '12:00' $$;
create function pg_temp.data(n int) returns text language sql as $$
  select to_char((now() at time zone 'America/Sao_Paulo')::date + n, 'YYYY-MM-DD') $$;
create function pg_temp.pago(n int) returns text language sql as $$
  select to_char((now() at time zone 'UTC') - make_interval(days => n), 'YYYY-MM-DD"T"HH24:MI:SS"Z"') $$;
-- "Pagou tudo" sem turma, com espera_turma e a data limite da compra (hoje + 120). Padrão: R$ 99 + R$ 150, PIX,
-- pago há 30 dias.
create function pg_temp.espera(base text, transacao text, ref text, curso text, extra jsonb default '{}') returns jsonb language sql as $$
  select jsonb_build_object('nome', 'Pessoa Correcao ' || base, 'cpf', pg_temp.cpf(base), 'email', 'cor' || base || '@exemplo.test',
    'celular', '21987654321', 'curso_id', curso, 'transacao', transacao, 'metodo', 'pix', 'valor_centavos', 9900,
    'matricula_centavos', 15000, 'parcelas', 1, 'total_centavos', 24900, 'pago_em', pg_temp.pago(30), 'referencia', ref,
    'espera_turma', true, 'inicio_ate', pg_temp.data(120),
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
create function pg_temp.aluno(base text) returns text language sql as $$
  select id from "Usuario" where email = 'cor' || base || '@exemplo.test' $$;
-- Turma como a escola cria (admin.js:1213-1215): com uma aula no dia indicado (null = sem aulas), criada há `idade`.
create function pg_temp.turma(id text, curso text, vagas int, inicio int, aula int, idade interval default interval '1 day',
                              st "StatusTurma" default 'ABERTA') returns void language sql as $$
  insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto", "criadoEm")
  values (id, curso, vagas, st, pg_temp.dia(inicio), (now() at time zone 'UTC') - idade);
  insert into "AulaData" select id || '-ad', id, pg_temp.dia(aula)::date, '18:00 - 22:00' where aula is not null;
$$;

insert into "Curso" (id, nome, "cargaHoraria", "precoAvista", "precoCheio", parcelas, "valorParcela", ativo) values
  ('abababab-0000-4000-8000-000000000001', 'Cor Fila', 8, 150, 150, 2, 77.5, true),
  ('abababab-0000-4000-8000-000000000002', 'Cor Turma Mexida', 8, 150, 150, 2, 77.5, true),
  ('abababab-0000-4000-8000-000000000003', 'Cor So Conta Pendente', 8, 150, 150, 2, 77.5, true),
  ('abababab-0000-4000-8000-000000000004', 'Cor Turma Concluida', 8, 150, 150, 2, 77.5, true),
  ('abababab-0000-4000-8000-000000000005', 'Cor So Taxa Antes', 8, 150, 150, 2, 77.5, true),
  ('abababab-0000-4000-8000-000000000006', 'Cor Pendente Antiga', 8, 150, 150, 2, 77.5, true),
  ('abababab-0000-4000-8000-000000000007', 'Cor Duas Compras', 8, 150, 150, 2, 77.5, true),
  ('abababab-0000-4000-8000-000000000008', 'Cor Aula Antes', 8, 150, 150, 2, 77.5, true),
  ('abababab-0000-4000-8000-000000000009', 'Cor Sem Espera', 8, 150, 150, 2, 77.5, true),
  ('abababab-0000-4000-8000-000000000010', 'Cor Sem Aulas', 8, 150, 150, 2, 77.5, true),
  ('abababab-0000-4000-8000-000000000011', 'Cor Distante', 8, 150, 150, 2, 77.5, true),
  ('abababab-0000-4000-8000-000000000012', 'Cor Em Andamento', 8, 150, 150, 2, 77.5, true),
  ('abababab-0000-4000-8000-000000000013', 'Cor Desempate', 8, 150, 150, 2, 77.5, true),
  ('abababab-0000-4000-8000-000000000014', 'Cor Confirmada', 8, 150, 150, 2, 77.5, true);

-- ------------------------------------------------------------------ 1. entradas e versão
select throws_ok($$ select pg_temp.rpc(pg_temp.espera('940000099', 'COR0000099', '999', 'abababab-0000-4000-8000-000000000001', '{"so_conta": "sim"}')) $$,
                 '22023', 'dados inválidos: so_conta', 'so_conta que não é booleano: recusado');
select throws_ok($$ select pg_temp.rpc(pg_temp.espera('940000099', 'COR0000099', '999', 'abababab-0000-4000-8000-000000000001', '{"so_conta": true}')
                                       - 'espera_turma') $$,
                 '22023', 'dados inválidos: so_conta (só com espera_turma)', 'so_conta sem espera_turma: recusado');
select throws_ok($$ select pg_temp.rpc(pg_temp.espera('940000099', 'COR0000099', '999', 'abababab-0000-4000-8000-000000000001') - 'inicio_ate') $$,
                 '22023', 'dados inválidos: inicio_ate (obrigatório com espera_turma)', 'espera_turma sem a data limite: recusado (falha fechada)');
select throws_ok($$ select pg_temp.rpc(pg_temp.espera('940000099', 'COR0000099', '999', 'abababab-0000-4000-8000-000000000001', '{"inicio_ate": "2026-13-40"}')) $$,
                 '22023', 'dados inválidos: inicio_ate (obrigatório com espera_turma)', 'data limite inválida: recusada');
select is((select public.matricula_rapida_versao()), 3, 'versão 3: o site só vende sem turma e só roda a rotina com ela');

-- ------------------------------------------------------------------ 2. a fila não fura (R1 corrigido): primeira chamada com so_conta
create temp table r1a as select pg_temp.rpc(pg_temp.espera('940000001', 'COR0000001', '901', 'abababab-0000-4000-8000-000000000001')) as r;
select pg_temp.turma('c1-t', 'abababab-0000-4000-8000-000000000001', 1, 15, 15);
-- B paga hoje: o site chama logo depois do pagamento com so_conta, fora da trava da rotina.
create temp table r1b as select pg_temp.rpc(pg_temp.espera('940000002', 'COR0000002', '902', 'abababab-0000-4000-8000-000000000001',
  jsonb_build_object('pago_em', pg_temp.pago(0), 'so_conta', true))) as r;
select is((select r->>'resultado' || '/' || (r->>'espera_motivo') || '/' || (r->>'matricula_paga') from r1b), 'sem_turma/na_fila/false',
          'so_conta: B, que pagou hoje, não pega a vaga (motivo na_fila)');
select is((select count(*)::int from "Matricula" where "alunoId" = pg_temp.aluno('940000002'))
          + (select count(*)::int from "Pagamento" where "gatewayHash" = 'COR0000002'), 0, 'so_conta: nenhuma matrícula e nenhum Pagamento');
select results_eq($$ select (detalhe->>'esperaMotivo'), (detalhe->>'pagouMatricula')::boolean, (detalhe->>'alunoNovo')::boolean
                       from "LogAuditoria" where acao = 'TAXA_PAGA_SEM_TURMA_PELO_SITE' and detalhe->>'transacao' = 'COR0000002' $$,
                  $$ values ('na_fila', true, true) $$, 'so_conta: a conta e o registro da espera, com o motivo na_fila');
select is((select (r->>'aluno_novo') || '/' || (r->>'link_acesso') from r1b), 'true/true', 'so_conta: conta nova com o link de criar a senha (para a Parabéns)');
create temp table r1b2 as select pg_temp.rpc(pg_temp.espera('940000002', 'COR0000002', '902', 'abababab-0000-4000-8000-000000000001',
  jsonb_build_object('pago_em', pg_temp.pago(0), 'so_conta', true))) as r;
select is((select (r->>'repetido') || '/' || (r->>'resultado') || '/' || (r->>'espera_motivo') from r1b2), 'true/sem_turma/na_fila',
          'so_conta repetido: repetição, nada gravado');
-- A rotina chama por ordem de pagamento: A (há 30 dias) e depois B.
create temp table r1c as select pg_temp.rpc(pg_temp.espera('940000001', 'COR0000001', '901', 'abababab-0000-4000-8000-000000000001')) as r;
create temp table r1d as select pg_temp.rpc(pg_temp.espera('940000002', 'COR0000002', '902', 'abababab-0000-4000-8000-000000000001',
  jsonb_build_object('pago_em', pg_temp.pago(0)))) as r;
select is((select r->>'resultado' || '/' || (r->'turma'->>'id') from r1c), 'matriculado/c1-t', 'fila: A, a primeira a pagar, fica com a única vaga');
select is((select r->>'resultado' || '/' || (r->>'espera_motivo') from r1d), 'sem_turma/turmas_lotadas', 'fila: B continua esperando (turmas_lotadas)');
select is((select count(*)::int from "Matricula" where "turmaId" = 'c1-t'), 1, 'fila: a turma de 1 vaga fica com 1');

-- ------------------------------------------------------------------ 3. so_conta não mexe na matrícula que a pessoa abriu na escola
select pg_temp.turma('c3-t', 'abababab-0000-4000-8000-000000000003', 30, 12, 12);
insert into "Usuario" (id, nome, email, "cpfCnpj", "senhaHash", papel, "atualizadoEm", "tipoDocumento") values
  ('u-c3', 'Pessoa Correcao 940000003', 'cor940000003@exemplo.test', pg_temp.cpf('940000003'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF');
insert into "Matricula" (id, "alunoId", "turmaId", plano, forma, "valorCurso", "valorTaxaMatricula", "statusPagamento", "taxaConfirmada", "atualizadoEm") values
  ('m-c3', 'u-c3', 'c3-t', 'A_VISTA', 'PIX', 249, 99, 'PENDENTE', false, now());
insert into "Pagamento" (id, "matriculaId", tipo, gateway, "gatewayRef", "gatewayHash", metodo, valor, status, "gatewayStatus", "atualizadoEm") values
  ('pg-c3-pix', 'm-c3', 'CURSO', 'unicopag', 'ESC-C3', 'ESC-C3', 'PIX', 249, 'PENDENTE', 'waiting_payment', now());
create temp table c3a as select pg_temp.rpc(pg_temp.espera('940000003', 'COR0000003', '903', 'abababab-0000-4000-8000-000000000003', '{"so_conta": true}')) as r;
select is((select (c.r->>'espera_motivo') || '/' || m."statusPagamento"::text || '/' || p.status::text from c3a c, "Matricula" m, "Pagamento" p
            where m.id = 'm-c3' and p.id = 'pg-c3-pix'), 'na_fila/PENDENTE/PENDENTE',
          'so_conta: a matrícula pendente da escola fica como estava (a rotina cuida dela)');
create temp table c3b as select pg_temp.rpc(pg_temp.espera('940000003', 'COR0000003', '903', 'abababab-0000-4000-8000-000000000003')) as r;
select is((select (r->>'matricula_id') || '/' || (r->>'matricula_status') || '/' || (r->'avisos' ? 'cobranca_escola_aberta')::text from c3b),
          'm-c3/PAGO/true', 'rotina: a matrícula pendente vira PAGA, e o PIX da escola é cancelado');

-- ------------------------------------------------------------------ 4. carência, aulas e o status na repetição (R2 corrigido)
create temp table c4a as select pg_temp.rpc(pg_temp.espera('940000004', 'COR0000004', '904', 'abababab-0000-4000-8000-000000000002')) as r;
select pg_temp.turma('c4-t', 'abababab-0000-4000-8000-000000000002', 30, 20, 20, interval '0 seconds');
create temp table c4b as select pg_temp.rpc(pg_temp.espera('940000004', 'COR0000004', '904', 'abababab-0000-4000-8000-000000000002')) as r;
select is((select r->>'resultado' || '/' || (r->>'espera_motivo') from c4b), 'sem_turma/turma_recente',
          'carência: turma criada agora ainda não recebe a fila (turma_recente)');
select is((select count(*)::int from "Matricula" where "turmaId" = 'c4-t'), 0, 'carência: ninguém matriculado');
create temp table c4s as select pg_temp.rpc(pg_temp.espera('940000005', 'COR0000005', '905', 'abababab-0000-4000-8000-000000000010')) as r;
select pg_temp.turma('c4-sem', 'abababab-0000-4000-8000-000000000010', 30, 20, null);
create temp table c4s2 as select pg_temp.rpc(pg_temp.espera('940000005', 'COR0000005', '905', 'abababab-0000-4000-8000-000000000010')) as r;
select is((select r->>'resultado' || '/' || (r->>'espera_motivo') from c4s2), 'sem_turma/turma_sem_aulas',
          'turma sem aulas cadastradas: não recebe a fila (turma_sem_aulas: a secretaria cadastra as datas)');
update "Turma" set "criadoEm" = (now() at time zone 'UTC') - interval '7 hours' where id = 'c4-t';
create temp table c4c as select pg_temp.rpc(pg_temp.espera('940000004', 'COR0000004', '904', 'abababab-0000-4000-8000-000000000002')) as r;
select is((select r->>'resultado' || '/' || (r->'turma'->>'id') || '/' || (r->'turma'->>'status') || '/' || (r->>'matricula_status') from c4c),
          'matriculado/c4-t/ABERTA/PAGO', 'depois de 6 horas: matriculado, com o status da turma e da matrícula');
-- A secretaria corrige a data (admin.js:1239-1246: apaga as aulas e grava de novo).
update "Turma" set "inicioPrevisto" = pg_temp.dia(40) where id = 'c4-t';
delete from "AulaData" where "turmaId" = 'c4-t';
insert into "AulaData" values ('c4-t-ad2', 'c4-t', pg_temp.dia(40)::date, '09:00 - 17:00');
create temp table c4d as select pg_temp.rpc(pg_temp.espera('940000004', 'COR0000004', '904', 'abababab-0000-4000-8000-000000000002')) as r;
select is((select (r->>'repetido') || '/' || (r->'turma'->>'inicio') || '/' || (r->'turma'->>'primeira_aula') || '/' || (r->'turma'->>'horario') from c4d),
          'true/' || pg_temp.data(40) || '/' || pg_temp.data(40) || '/09:00 - 17:00',
          'data mudada: a repetição devolve a data e o horário novos (a rotina pergunta uma vez por dia)');
update "Turma" set status = 'CANCELADA' where id = 'c4-t';
create temp table c4e as select pg_temp.rpc(pg_temp.espera('940000004', 'COR0000004', '904', 'abababab-0000-4000-8000-000000000002')) as r;
select is((select r->'turma'->>'status' from c4e), 'CANCELADA', 'turma cancelada: a repetição traz o status CANCELADA');
update "Matricula" set "statusPagamento" = 'CANCELADO' where id = (select r->>'matricula_id' from c4c);
create temp table c4f as select pg_temp.rpc(pg_temp.espera('940000004', 'COR0000004', '904', 'abababab-0000-4000-8000-000000000002')) as r;
select is((select r->>'matricula_status' from c4f), 'CANCELADO', 'matrícula cancelada na escola: a repetição traz matricula_status CANCELADO');
select is((select (r->>'repetido') || '/' || (r->'turma'->>'id') from c4f), 'true/c4-t',
          'repetição depois de matriculada: não muda de turma (o site decide pelo status)');

-- ------------------------------------------------------------------ 5. antecedência pela primeira aula (R9) e teto da data limite
select pg_temp.turma('c5-t', 'abababab-0000-4000-8000-000000000008', 30, 15, 5);
create temp table c5a as select pg_temp.rpc(pg_temp.espera('940000006', 'COR0000006', '906', 'abababab-0000-4000-8000-000000000008')) as r;
select is((select r->>'resultado' || '/' || (r->>'espera_motivo') from c5a), 'sem_turma/turma_muito_proxima',
          'antecedência: início em 15 dias, mas a primeira aula em 5: não entra (vale a primeira aula)');
select pg_temp.turma('c5-longe', 'abababab-0000-4000-8000-000000000011', 30, 300, 300);
create temp table c5b as select pg_temp.rpc(pg_temp.espera('940000007', 'COR0000007', '907', 'abababab-0000-4000-8000-000000000011')) as r;
select is((select r->>'resultado' || '/' || (r->>'espera_motivo') from c5b), 'sem_turma/turma_muito_distante',
          'teto: turma daqui a 300 dias, depois da data limite da compra (120): não entra (turma_muito_distante)');
select is((select count(*)::int from "Matricula" where "turmaId" = 'c5-longe'), 0, 'teto: ninguém matriculado na turma distante');
create temp table c5c as select pg_temp.rpc(pg_temp.espera('940000007', 'COR0000007', '907', 'abababab-0000-4000-8000-000000000011',
  jsonb_build_object('inicio_ate', pg_temp.data(400)))) as r;
select is((select r->>'resultado' || '/' || (r->'turma'->>'id') from c5c), 'matriculado/c5-longe',
          'teto: a data limite é a que o site manda (com 400 dias, a mesma turma entra)');
select pg_temp.turma('c5-dez', 'abababab-0000-4000-8000-000000000008', 30, 10, 10);
create temp table c5d as select pg_temp.rpc(pg_temp.espera('940000006', 'COR0000006', '906', 'abababab-0000-4000-8000-000000000008')) as r;
select is((select r->>'resultado' || '/' || (r->'turma'->>'id') from c5d), 'matriculado/c5-dez',
          'antecedência: primeira aula exatamente em 10 dias entra (a janela de 7 dias cabe antes da véspera)');

-- ------------------------------------------------------------------ 6. turma concluída não é "curso já pago" (R4 corrigido)
select pg_temp.turma('c6-velha', 'abababab-0000-4000-8000-000000000004', 30, -400, -400);
insert into "Usuario" (id, nome, email, "cpfCnpj", "senhaHash", papel, "atualizadoEm", "tipoDocumento") values
  ('u-c6', 'Pessoa Correcao 940000008', 'cor940000008@exemplo.test', pg_temp.cpf('940000008'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF');
insert into "Matricula" (id, "alunoId", "turmaId", plano, forma, "valorCurso", "valorTaxaMatricula", "statusPagamento", "taxaConfirmada", "atualizadoEm") values
  ('m-c6-velha', 'u-c6', 'c6-velha', 'A_VISTA', 'PIX', 249, 99, 'PAGO', true, now());
select pg_temp.turma('c6-nova', 'abababab-0000-4000-8000-000000000004', 30, 20, 20);
create temp table c6a as select pg_temp.rpc(pg_temp.espera('940000008', 'COR0000008', '908', 'abababab-0000-4000-8000-000000000004')) as r;
select is((select r->>'resultado' || '/' || (r->'turma'->>'id') || '/' || (r->>'matricula_paga') from c6a), 'matriculado/c6-nova/true',
          'refazer o curso: a matrícula paga numa turma já concluída não conta; entra paga na turma nova');
select is((select r->'avisos' from c6a), '[]'::jsonb, 'refazer o curso: sem curso_ja_pago (o site não devolve)');
-- Turma em andamento (começou há 2 dias, última aula daqui a 5): o curso pago nela continua valendo.
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto", "criadoEm") values
  ('c6-andamento', 'abababab-0000-4000-8000-000000000012', 30, 'CONFIRMADA', pg_temp.dia(-2), (now() at time zone 'UTC') - interval '30 days');
insert into "AulaData" values ('c6-and-1', 'c6-andamento', pg_temp.dia(-2)::date, '09:00 - 17:00'), ('c6-and-2', 'c6-andamento', pg_temp.dia(5)::date, '09:00 - 17:00');
insert into "Usuario" (id, nome, email, "cpfCnpj", "senhaHash", papel, "atualizadoEm", "tipoDocumento") values
  ('u-c6b', 'Pessoa Correcao 940000009', 'cor940000009@exemplo.test', pg_temp.cpf('940000009'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF');
insert into "Matricula" (id, "alunoId", "turmaId", plano, forma, "valorCurso", "valorTaxaMatricula", "statusPagamento", "taxaConfirmada", "atualizadoEm") values
  ('m-c6-and', 'u-c6b', 'c6-andamento', 'A_VISTA', 'PIX', 249, 99, 'PAGO', true, now());
create temp table c6b as select pg_temp.rpc(pg_temp.espera('940000009', 'COR0000009', '909', 'abababab-0000-4000-8000-000000000012')) as r;
select is((select r->>'aviso' || '/' || (r->>'matricula_id') from c6b), 'curso_ja_pago/m-c6-and',
          'turma em andamento: o curso pago nela continua sendo pagamento em dobro (curso_ja_pago)');

-- ------------------------------------------------------------------ 7. só a taxa antes e, depois, tudo (R5 corrigido): taxa_paga_antes
create temp table c7a as select pg_temp.rpc(pg_temp.espera('940000010', 'COR0000010', '910', 'abababab-0000-4000-8000-000000000005')
  - 'espera_turma' - 'inicio_ate' - 'matricula_centavos' - 'parcelas' || '{"total_centavos": 9900}') as r;
create temp table c7b as select pg_temp.rpc(pg_temp.espera('940000010', 'COR0000011', '911', 'abababab-0000-4000-8000-000000000005')) as r;
select is((select (a.r->>'resultado') || '/' || (b.r->>'resultado') || '/' || (b.r->'avisos')::text from c7a a, c7b b),
          'sem_turma/sem_turma/["matricula_paga_sem_turma"]', 'só a taxa e depois tudo, sem turma: o aviso espera a matrícula');
select pg_temp.turma('c7-t', 'abababab-0000-4000-8000-000000000005', 30, 15, 15);
create temp table c7c as select pg_temp.rpc(pg_temp.espera('940000010', 'COR0000011', '911', 'abababab-0000-4000-8000-000000000005')) as r;
select is((select r->>'resultado' || '/' || (r->'avisos')::text from c7c), 'matriculado/["taxa_paga_antes"]',
          'a turma abre: matriculado com o aviso taxa_paga_antes (o site devolve a taxa paga a mais)');
select results_eq($$ select valor, ("gatewayResponse"->>'taxa_paga_antes')::boolean from "Pagamento" where "gatewayHash" = 'COR0000011' $$,
                  $$ values (249.00::numeric(10,2), true) $$, 'taxa_paga_antes: o Pagamento desta compra leva taxa + matrícula, com a marca');

-- ------------------------------------------------------------------ 8. matrícula pendente antiga com a taxa paga (R6 corrigido): pendente_antiga
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto", "criadoEm") values
  ('c8-ontem', 'abababab-0000-4000-8000-000000000006', 30, 'ABERTA', pg_temp.dia(-1), (now() at time zone 'UTC') - interval '30 days');
insert into "Usuario" (id, nome, email, "cpfCnpj", "senhaHash", papel, "atualizadoEm", "tipoDocumento") values
  ('u-c8', 'Pessoa Correcao 940000012', 'cor940000012@exemplo.test', pg_temp.cpf('940000012'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF');
insert into "Matricula" (id, "alunoId", "turmaId", plano, forma, "valorCurso", "valorTaxaMatricula", "statusPagamento", "taxaConfirmada", "atualizadoEm") values
  ('m-c8', 'u-c8', 'c8-ontem', 'PARCELADO', 'CREDITO', 259, 99, 'PENDENTE', true, now());
insert into "Pagamento" (id, "matriculaId", tipo, gateway, "gatewayRef", "gatewayHash", metodo, valor, status, "gatewayStatus", "atualizadoEm") values
  ('pg-c8-taxa', 'm-c8', 'TAXA', 'unicopag', 'ESC-C8', 'ESC-C8', 'PIX', 99, 'PAGO', 'paid', now());
create temp table c8a as select pg_temp.rpc(pg_temp.espera('940000012', 'COR0000012', '912', 'abababab-0000-4000-8000-000000000006')) as r;
select pg_temp.turma('c8-nova', 'abababab-0000-4000-8000-000000000006', 30, 15, 15);
create temp table c8b as select pg_temp.rpc(pg_temp.espera('940000012', 'COR0000012', '912', 'abababab-0000-4000-8000-000000000006')) as r;
select is((select r->'turma'->>'id' || '/' || (r->'avisos')::text from c8b), 'c8-nova/["pendente_antiga"]',
          'a turma abre: matriculado na nova com o aviso pendente_antiga (a secretaria confere a antiga)');
select is((select detalhe->>'pendenteAntiga' from "LogAuditoria" where detalhe->>'transacao' = 'COR0000012' and "alvoTipo" = 'Matricula'), 'm-c8',
          'pendente_antiga: o LogAuditoria aponta a matrícula antiga');
select is((select "statusPagamento"::text from "Matricula" where id = 'm-c8'), 'PENDENTE', 'pendente_antiga: a matrícula antiga fica como estava');

-- ------------------------------------------------------------------ 9. desempate pelo id (ordem determinística, sem deadlock)
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto", "criadoEm") values
  ('c9-b', 'abababab-0000-4000-8000-000000000013', 30, 'ABERTA', pg_temp.dia(20), timestamp '2026-01-01 10:00'),
  ('c9-a', 'abababab-0000-4000-8000-000000000013', 30, 'ABERTA', pg_temp.dia(20), timestamp '2026-01-01 10:00');
insert into "AulaData" values ('c9-b-ad', 'c9-b', pg_temp.dia(20)::date, '09:00 - 17:00'), ('c9-a-ad', 'c9-a', pg_temp.dia(20)::date, '09:00 - 17:00');
create temp table c9 as select pg_temp.rpc(pg_temp.espera('940000013', 'COR0000013', '913', 'abababab-0000-4000-8000-000000000013')) as r;
select is((select r->'turma'->>'id' from c9), 'c9-a', 'empate de data e de criação: a turma de menor id');

-- ------------------------------------------------------------------ 10. turma CONFIRMADA futura e contratos que ficam
select pg_temp.turma('c10-conf', 'abababab-0000-4000-8000-000000000014', 30, 25, 25, interval '1 day', 'CONFIRMADA');
create temp table c10 as select pg_temp.rpc(pg_temp.espera('940000014', 'COR0000014', '914', 'abababab-0000-4000-8000-000000000014')) as r;
select is((select r->>'resultado' || '/' || (r->'turma'->>'status') from c10), 'matriculado/CONFIRMADA', 'turma CONFIRMADA futura: entra, com o status');
-- Sem espera_turma e sem turma_id, a compra cai na regra antiga (turma de amanhã, acima da vaga). Por isso o site
-- manda sempre espera_turma nas compras vendidas sem turma (10.8).
select pg_temp.turma('c10-amanha', 'abababab-0000-4000-8000-000000000009', 1, 1, 1);
insert into "Usuario" (id, nome, email, "cpfCnpj", "senhaHash", papel, "atualizadoEm", "tipoDocumento") values
  ('u-c10-ocupa', 'Aluna Ocupa C10', 'c10ocupa@exemplo.test', pg_temp.cpf('941000010'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF');
insert into "Matricula" (id, "alunoId", "turmaId", plano, forma, "valorCurso", "valorTaxaMatricula", "statusPagamento", "taxaConfirmada", "atualizadoEm") values
  ('m-c10-ocupa', 'u-c10-ocupa', 'c10-amanha', 'A_VISTA', 'PIX', 249, 99, 'PAGO', true, now());
create temp table c10b as select pg_temp.rpc(pg_temp.espera('940000015', 'COR0000015', '915', 'abababab-0000-4000-8000-000000000009',
  jsonb_build_object('pago_em', pg_temp.pago(0))) - 'espera_turma' - 'inicio_ate') as r;
select is((select r->>'resultado' || '/' || (r->>'aviso') from c10b), 'matriculado/turma_lotada',
          'contrato: sem espera_turma, regra antiga (o site nunca chama assim uma compra vendida sem turma)');
-- Curso desativado: ok = false. O site conta como falha da espera, sem apagar o acesso da pessoa (10.8).
update "Curso" set ativo = false where id = 'abababab-0000-4000-8000-000000000005';
create temp table c10c as select pg_temp.rpc(pg_temp.espera('940000016', 'COR0000016', '916', 'abababab-0000-4000-8000-000000000005')) as r;
select is((select (r->>'ok') || '/' || (r->>'erro') from c10c), 'false/curso_inativo', 'contrato: curso inativo devolve ok=false, curso_inativo');
-- Duas compras "tudo" do mesmo CPF no mesmo curso, as duas na fila: uma matrícula; a segunda vira curso_ja_pago.
create temp table c10d as select pg_temp.rpc(pg_temp.espera('940000017', 'COR0000017', '917', 'abababab-0000-4000-8000-000000000007',
  jsonb_build_object('pago_em', pg_temp.pago(20)))) as r;
create temp table c10e as select pg_temp.rpc(pg_temp.espera('940000017', 'COR0000018', '918', 'abababab-0000-4000-8000-000000000007',
  jsonb_build_object('pago_em', pg_temp.pago(10)))) as r;
select pg_temp.turma('c10-dup', 'abababab-0000-4000-8000-000000000007', 30, 15, 15);
create temp table c10f as select pg_temp.rpc(pg_temp.espera('940000017', 'COR0000017', '917', 'abababab-0000-4000-8000-000000000007',
  jsonb_build_object('pago_em', pg_temp.pago(20)))) as r;
create temp table c10g as select pg_temp.rpc(pg_temp.espera('940000017', 'COR0000018', '918', 'abababab-0000-4000-8000-000000000007',
  jsonb_build_object('pago_em', pg_temp.pago(10)))) as r;
select is((select (f.r->>'resultado') || '/' || (g.r->>'aviso') || '/' || ((g.r->>'matricula_id') = (f.r->>'matricula_id'))::text from c10f f, c10g g),
          'matriculado/curso_ja_pago/true', 'contrato: duas compras do mesmo CPF, uma matrícula; a segunda vira curso_ja_pago');
select is((select count(*)::int from "Matricula" where "alunoId" = pg_temp.aluno('940000017')), 1, 'contrato: uma matrícula só');
-- Pagamento.criadoEm fica no dia do pagamento (a data do caixa; o Financeiro por conta da escola usa confirmadaEm).
select ok((select p."criadoEm" < (now() at time zone 'UTC') - interval '29 days' and m."confirmadaEm" = p."criadoEm"
             from "Pagamento" p join "Matricula" m on m.id = p."matriculaId" where p."gatewayHash" = 'COR0000001'),
          'contrato: Pagamento.criadoEm e confirmadaEm = dia do pagamento (decisão registrada na 10.16)');
-- A conta de quem espera fica sem matrícula na escola: o aviso à secretaria diz para não usar o convite (10.6).
select is((select count(*)::int from "Usuario" u where u.email = 'cor940000002@exemplo.test'
            and not exists (select 1 from "Matricula" m where m."alunoId" = u.id)), 1,
          'contrato: quem espera tem conta sem matrícula (entra em "sem inscrição" na escola; o aviso cobre)');
select is((select count(*)::int from "LogAuditoria" where acao = 'TAXA_PAGA_SEM_TURMA_PELO_SITE' and detalhe->>'transacao' = 'COR0000004'), 1,
          'contrato: a procura repetida não grava nada (uma linha de sem turma por compra)');

select * from finish();
rollback;
