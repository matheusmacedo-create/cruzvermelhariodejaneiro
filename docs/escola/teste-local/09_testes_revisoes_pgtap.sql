-- Testes (pgTAP) das mudanças da v2 final (revisões financeira e técnica de 08/10/2026), dados fictícios.
-- Rodar depois de 00, 01, 02 e matricula_rapida_v2.sql. Tudo dentro de uma transação desfeita no fim.
\set ON_ERROR_STOP 1
\set QUIET 1
begin;
create extension if not exists pgtap;
select plan(47);

create function pg_temp.cpf(base text) returns text language plpgsql as $$
declare s int; d1 int; d2 int;
begin
  s := 0; for i in 1..9 loop s := s + substr(base, i, 1)::int * (11 - i); end loop; d1 := (s * 10) % 11 % 10;
  s := 0; for i in 1..9 loop s := s + substr(base, i, 1)::int * (12 - i); end loop; s := s + d1 * 2; d2 := (s * 10) % 11 % 10;
  return base || d1 || d2;
end $$;
create function pg_temp.tudo(base text, transacao text, ref text, curso text, extra jsonb default '{}') returns jsonb language sql as $$
  select jsonb_build_object('nome', 'Pessoa Revisao ' || base, 'cpf', pg_temp.cpf(base), 'email', 'rev' || base || '@exemplo.test',
    'celular', '21987654321', 'curso_id', curso, 'transacao', transacao, 'metodo', 'pix', 'valor_centavos', 9900,
    'matricula_centavos', 18000, 'parcelas', 1, 'total_centavos', 27900, 'pago_em', to_char(now() at time zone 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"'),
    'referencia', ref,
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
create function pg_temp.dia(n int) returns timestamp language sql as $$
  select ((now() at time zone 'America/Sao_Paulo')::date + n)::timestamp + time '12:00' $$;

-- C1: turma vendida lotada e outra depois, com vaga. C2: turma mais cedo (fora do oferta.json), a vendida e uma
-- passada que ficou ABERTA. C3: turma que começa hoje.
insert into "Curso" (id, nome, "cargaHoraria", "precoAvista", "precoCheio", parcelas, "valorParcela", ativo) values
  ('cccccccc-0000-4000-8000-000000000001', 'Curso Revisao Lotada', 8, 180, 200, 2, 100, true),
  ('cccccccc-0000-4000-8000-000000000002', 'Curso Revisao Turmas', 8, 180, 200, 2, 100, true),
  ('cccccccc-0000-4000-8000-000000000003', 'Curso Revisao Hoje', 8, 180, 200, 2, 100, true);
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto") values
  ('r-lotada', 'cccccccc-0000-4000-8000-000000000001', 1, 'ABERTA', pg_temp.dia(5)),
  ('r-livre', 'cccccccc-0000-4000-8000-000000000001', 30, 'ABERTA', pg_temp.dia(20)),
  ('q-cedo', 'cccccccc-0000-4000-8000-000000000002', 30, 'ABERTA', pg_temp.dia(3)),
  ('q-vendida', 'cccccccc-0000-4000-8000-000000000002', 30, 'ABERTA', pg_temp.dia(12)),
  ('q-passada', 'cccccccc-0000-4000-8000-000000000002', 30, 'ABERTA', pg_temp.dia(-10)),
  ('h-hoje', 'cccccccc-0000-4000-8000-000000000003', 30, 'ABERTA', pg_temp.dia(0));
insert into "Usuario" (id, nome, email, "cpfCnpj", "senhaHash", papel, "atualizadoEm", "tipoDocumento") values
  ('ru-ocupa', 'Aluno Ocupa Vaga', 'rocupa@exemplo.test', pg_temp.cpf('810000001'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF'),
  ('ru-estornado', 'Aluna Estornada', 'rev610000003@exemplo.test', pg_temp.cpf('610000003'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF'),
  ('ru-passada', 'Aluno Turma Passada', 'rev610000005@exemplo.test', pg_temp.cpf('610000005'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF'),
  ('ru-pix-escola', 'Aluna Pix Da Escola', 'rev610000006@exemplo.test', pg_temp.cpf('610000006'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF'),
  ('ru-cedo', 'Aluno Turma Cedo', 'rev610000007@exemplo.test', pg_temp.cpf('610000007'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF'),
  ('ru-encaixe', 'Aluna Encaixada', 'rev610000008@exemplo.test', pg_temp.cpf('610000008'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF');
insert into "Matricula" (id, "alunoId", "turmaId", plano, forma, "valorCurso", "valorTaxaMatricula", "statusPagamento", "taxaConfirmada", "atualizadoEm") values
  ('rm-ocupa', 'ru-ocupa', 'r-lotada', 'A_VISTA', 'PIX', 279, 99, 'PAGO', true, now()),
  ('rm-estornado', 'ru-estornado', 'q-vendida', 'A_VISTA', 'CREDITO', 279, 99, 'ESTORNADO', true, now()),
  ('rm-passada', 'ru-passada', 'q-passada', 'A_VISTA', 'PIX', 279, 99, 'PENDENTE', false, now()),
  ('rm-pix-escola', 'ru-pix-escola', 'q-vendida', 'A_VISTA', 'PIX', 279, 99, 'PENDENTE', false, now()),
  ('rm-cedo', 'ru-cedo', 'q-cedo', 'A_VISTA', 'PIX', 279, 99, 'PENDENTE', false, now()),
  ('rm-encaixe', 'ru-encaixe', 'q-vendida', 'PARCELADO', 'CREDITO', 479, 279, 'PENDENTE', true, now());
insert into "Pagamento" (id, "matriculaId", tipo, gateway, "gatewayRef", "gatewayHash", metodo, valor, status, "gatewayStatus", "atualizadoEm") values
  ('rp-pix-escola', 'rm-pix-escola', 'CURSO', 'unicopag', 'ESC-PIX-9', 'ESC-PIX-9', 'PIX', 279, 'PENDENTE', 'waiting_payment', now()),
  ('rp-manual', 'rm-pix-escola', 'TAXA', 'manual', null, null, 'PIX', 99, 'PENDENTE', null, now()),
  -- O "Encaixar" da escola: Pagamento TAXA na conta da instituição com o hash da compra inteira (R$ 279).
  ('rp-encaixe', 'rm-encaixe', 'TAXA', 'unicopag-2', 'ENCX000001', 'ENCX000001', 'PIX', 279, 'PAGO', 'paid', now());

-- ------------------------------------------------------------------ 1. versão
select is((select public.matricula_rapida_versao()), 3, 'versão: 3 (a 2 com a espera da revisão: espera_turma, so_conta e inicio_ate)');
select ok(has_function_privilege('service_role', 'public.matricula_rapida_versao()', 'EXECUTE'), 'versão: service_role executa');
select ok(not has_function_privilege('anon', 'public.matricula_rapida_versao()', 'EXECUTE'), 'versão: anon não executa');
select ok(not has_function_privilege('authenticated', 'public.matricula_rapida_versao()', 'EXECUTE'), 'versão: authenticated não executa');

-- ------------------------------------------------------------------ 2. turma vendida lotada: fica nela
create temp table t1 as select pg_temp.rpc(pg_temp.tudo('610000001', 'REV0000001', '601', 'cccccccc-0000-4000-8000-000000000001',
  '{"turma_id": "r-lotada"}')) as r;
select is((select r->'turma'->>'id' from t1), 'r-lotada', 'vendida lotada: matriculado na turma vendida');
select ok((select r->'avisos' ? 'turma_lotada' from t1), 'vendida lotada: aviso turma_lotada');
select ok((select not (r->'avisos' ? 'turma_diferente') from t1), 'vendida lotada: sem turma_diferente');
select is((select r->>'matricula_paga' from t1), 'true', 'vendida lotada: matricula_paga');
-- Sem turma_id (site antigo), a regra antiga: a próxima com vaga.
create temp table t1b as select pg_temp.rpc(pg_temp.tudo('610000011', 'REV0000011', '611', 'cccccccc-0000-4000-8000-000000000001')) as r;
select is((select r->'turma'->>'id' from t1b), 'r-livre', 'sem turma_id: regra antiga, próxima com vaga');
select is((select r->'avisos' from t1b), '[]'::jsonb, 'sem turma_id: sem avisos');

-- ------------------------------------------------------------------ 3. turma pedida que não serve
create temp table t2 as select pg_temp.rpc(pg_temp.tudo('610000002', 'REV0000002', '602', 'cccccccc-0000-4000-8000-000000000001',
  '{"turma_id": "q-vendida"}')) as r;
-- 09/10 (revisão de dinheiro): não cai mais na regra antiga (que furava a fila da espera); fica sem turma, e o site o põe
-- na fila da próxima turma.
select is((select r->>'resultado' || '/' || (r->>'aviso') from t2), 'sem_turma/matricula_paga_sem_turma', 'turma de outro curso: sem turma (vai para a fila no site)');
select ok((select not (r->'avisos' ? 'turma_diferente') from t2), 'turma de outro curso: sem turma_diferente');
create temp table t2b as select pg_temp.rpc(pg_temp.tudo('610000012', 'REV0000012', '612', 'cccccccc-0000-4000-8000-000000000002',
  '{"turma_id": "q-passada"}')) as r;
select is((select r->>'resultado' || '/' || (r->>'aviso') from t2b), 'sem_turma/matricula_paga_sem_turma', 'turma que já começou: sem turma (vai para a fila no site)');
select ok((select not (r->'avisos' ? 'turma_diferente') from t2b), 'turma que já começou: sem turma_diferente');

-- ------------------------------------------------------------------ 4. turma mais cedo fora do oferta.json: fica a vendida
create temp table t4 as select pg_temp.rpc(pg_temp.tudo('610000004', 'REV0000004', '604', 'cccccccc-0000-4000-8000-000000000002',
  '{"turma_id": "q-vendida"}')) as r;
select is((select r->'turma'->>'id' from t4), 'q-vendida', 'turma mais cedo na escola: fica a vendida');
select is((select r->'avisos' from t4), '[]'::jsonb, 'turma mais cedo na escola: sem avisos');

-- ------------------------------------------------------------------ 5. matrícula ESTORNADO na turma vendida: reaproveitada
create temp table t3 as select pg_temp.rpc(pg_temp.tudo('610000003', 'REV0000003', '603', 'cccccccc-0000-4000-8000-000000000002',
  '{"turma_id": "q-vendida"}')) as r;
select is((select r->>'matricula_id' from t3), 'rm-estornado', 'estornada: mesma matrícula');
select is((select r->>'matricula_existente' from t3), 'true', 'estornada: matricula_existente');
select results_eq($$ select plano::text, "statusPagamento"::text, "valorCurso", "taxaConfirmada", "confirmadaPor" from "Matricula" where id = 'rm-estornado' $$,
                  $$ values ('A_VISTA', 'PAGO', 279.00::numeric(10,2), true, 'site cruzvermelhariodejaneiro.org') $$, 'estornada: volta à vista e PAGA');
select is((select count(*)::int from "Pagamento" where "matriculaId" = 'rm-estornado' and tipo = 'CURSO' and status = 'PAGO'), 1, 'estornada: Pagamento CURSO PAGO');
select is((select (detalhe->>'reaproveitada')::boolean from "LogAuditoria" where detalhe->>'transacao' = 'REV0000003' and "alvoTipo" = 'Matricula'), true,
          'estornada: LogAuditoria marca reaproveitada');
create temp table t3b as select pg_temp.rpc(pg_temp.tudo('610000003', 'REV0000003', '603', 'cccccccc-0000-4000-8000-000000000002',
  '{"turma_id": "q-vendida"}')) as r;
select is((select (r->>'repetido') || '/' || (r->>'matricula_paga') from t3b), 'true/true', 'estornada: repetição devolve o mesmo, paga');

-- ------------------------------------------------------------------ 6. matrícula PENDENTE de turma passada que ficou ABERTA
create temp table t5 as select pg_temp.rpc(pg_temp.tudo('610000005', 'REV0000005', '605', 'cccccccc-0000-4000-8000-000000000002',
  '{"turma_id": "q-vendida"}')) as r;
select is((select r->'turma'->>'id' from t5), 'q-vendida', 'turma passada: matrícula nova na turma vendida');
select isnt((select r->>'matricula_id' from t5), 'rm-passada', 'turma passada: não usa a matrícula antiga');
select is((select "statusPagamento"::text from "Matricula" where id = 'rm-passada'), 'PENDENTE', 'turma passada: a antiga fica como estava');

-- ------------------------------------------------------------------ 7. cobrança da escola em aberto: cancelada
create temp table t6 as select pg_temp.rpc(pg_temp.tudo('610000006', 'REV0000006', '606', 'cccccccc-0000-4000-8000-000000000002',
  '{"turma_id": "q-vendida"}')) as r;
select is((select r->>'matricula_id' from t6), 'rm-pix-escola', 'cobrança aberta: usa a matrícula da escola');
select is((select status::text || '/' || "gatewayStatus" from "Pagamento" where id = 'rp-pix-escola'), 'CANCELADO/cancelado:pago-pelo-site',
          'cobrança aberta: PIX da escola cancelado');
select is((select status::text from "Pagamento" where id = 'rp-manual'), 'CANCELADO', 'cobrança aberta: lançamento à mão pendente cancelado');
select ok((select r->'avisos' ? 'cobranca_escola_aberta' from t6), 'cobrança aberta: aviso cobranca_escola_aberta');
select ok((select not (r->'avisos' ? 'turma_diferente') from t6), 'cobrança aberta: na turma vendida, sem turma_diferente');
select is((select "statusPagamento"::text from "Matricula" where id = 'rm-pix-escola'), 'PAGO', 'cobrança aberta: matrícula PAGA');

-- ------------------------------------------------------------------ 8. matrícula aberta em outra turma do curso
create temp table t7 as select pg_temp.rpc(pg_temp.tudo('610000007', 'REV0000007', '607', 'cccccccc-0000-4000-8000-000000000002',
  '{"turma_id": "q-vendida"}')) as r;
select is((select r->>'matricula_id' from t7), 'rm-cedo', 'outra turma: usa a matrícula aberta da escola');
select ok((select r->'avisos' ? 'turma_diferente' from t7), 'outra turma: aviso turma_diferente');
select is((select r->'turma'->>'id' from t7), 'q-cedo', 'outra turma: a resposta traz a turma real');

-- ------------------------------------------------------------------ 9. repetição depois do "Encaixar" da escola
create temp table t8 as select pg_temp.rpc(pg_temp.tudo('610000008', 'ENCX000001', '608', 'cccccccc-0000-4000-8000-000000000002',
  '{"turma_id": "q-vendida"}')) as r;
select is((select r->>'repetido' from t8), 'true', 'após Encaixar: repetido (o hash já estava na escola)');
select is((select r->>'matricula_paga' from t8), 'false', 'após Encaixar: matrícula não paga por esta transação');
select ok((select r->'avisos' ? 'matricula_nao_marcada' from t8), 'após Encaixar: aviso matricula_nao_marcada (o site trata como urgente)');
select is((select count(*)::int from "Pagamento" where "gatewayHash" = 'ENCX000001'), 1, 'após Encaixar: nenhum Pagamento novo');

-- ------------------------------------------------------------------ 10. "hoje" é o dia do pagamento
-- Pagou ontem às 23h50 (Brasília); a chamada chega hoje: a turma que começa hoje ainda vale.
create temp table t9 as select pg_temp.rpc(pg_temp.tudo('610000009', 'REV0000009', '609', 'cccccccc-0000-4000-8000-000000000003',
  jsonb_build_object('pago_em', to_char(((((now() at time zone 'America/Sao_Paulo')::date - 1) + time '23:50') at time zone 'America/Sao_Paulo') at time zone 'UTC',
                                        'YYYY-MM-DD"T"HH24:MI:SS"Z"')))) as r;
select is((select r->'turma'->>'id' from t9), 'h-hoje', 'pago ontem à noite: entra na turma de hoje');
create temp table t9b as select pg_temp.rpc(pg_temp.tudo('610000010', 'REV0000010', '610', 'cccccccc-0000-4000-8000-000000000003')) as r;
select is((select r->>'resultado' || '/' || (r->>'aviso') from t9b), 'sem_turma/matricula_paga_sem_turma', 'pago hoje: a turma de hoje não vale mais');
-- Pagamento de três dias atrás (chamada atrasada): nunca antes de ontem.
create temp table t9c as select pg_temp.rpc(pg_temp.tudo('610000013', 'REV0000013', '613', 'cccccccc-0000-4000-8000-000000000003',
  jsonb_build_object('pago_em', to_char(now() at time zone 'UTC' - interval '3 days', 'YYYY-MM-DD"T"HH24:MI:SS"Z"')))) as r;
select is((select r->'turma'->>'id' from t9c), 'h-hoje', 'pago há 3 dias: vale "ontem", e a turma de hoje ainda entra');

-- ------------------------------------------------------------------ 11. mesma pessoa: só a taxa e depois tudo
create temp table t10 as select pg_temp.rpc(pg_temp.tudo('610000014', 'SEQ0000001', '614', 'cccccccc-0000-4000-8000-000000000002')
  - 'matricula_centavos' - 'parcelas' || '{"total_centavos": 9900}') as r;
create temp table t10b as select pg_temp.rpc(pg_temp.tudo('610000014', 'SEQ0000002', '615', 'cccccccc-0000-4000-8000-000000000002',
  '{"turma_id": "q-vendida"}')) as r;
select is((select r->>'matricula_id' from t10b), (select r->>'matricula_id' from t10), 'taxa e depois tudo: mesma matrícula');
select ok((select r->'avisos' ? 'taxa_em_dobro' from t10b), 'taxa e depois tudo: aviso taxa_em_dobro');
select ok((select r->'avisos' ? 'turma_diferente' from t10b), 'taxa e depois tudo: a taxa tinha ido para a turma mais cedo (turma_diferente)');
create temp table t10c as select pg_temp.rpc(pg_temp.tudo('610000014', 'SEQ0000003', '616', 'cccccccc-0000-4000-8000-000000000002')
  - 'matricula_centavos' - 'parcelas' || '{"total_centavos": 9900}') as r;
select is((select r->>'aviso' from t10c), 'taxa_ja_confirmada', 'tudo e depois só a taxa: taxa_ja_confirmada');

-- ------------------------------------------------------------------ 12. entradas
select throws_ok($$ select pg_temp.rpc(pg_temp.tudo('610000015', 'REV0000015', '617', 'cccccccc-0000-4000-8000-000000000002', '{"turma_id": "q vendida"}')) $$,
                 '22023', 'dados inválidos: turma_id', 'turma_id com espaço: recusado');
create temp table t12 as select pg_temp.rpc(pg_temp.tudo('610000016', 'REV0000016', '618', 'cccccccc-0000-4000-8000-000000000002', '{"turma_id": "q vendida"}')
  - 'matricula_centavos' - 'parcelas' || '{"total_centavos": 9900}') as r;
select is((select r->>'resultado' from t12), 'matriculado', 'só a taxa (v1): turma_id é ignorado, como o site antigo');

select * from finish();
rollback;
