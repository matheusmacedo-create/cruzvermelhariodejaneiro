-- Testes (pgTAP) da matricula_rapida v2 ("pagar tudo") numa cópia local do banco da escola, dados fictícios.
-- Rodar depois de 00, 01, 02 e matricula_rapida_v2.sql. Tudo dentro de uma transação desfeita no fim.
\set ON_ERROR_STOP 1
\set QUIET 1
begin;
create extension if not exists pgtap;
select plan(74);

-- ------------------------------------------------------------------ apoio (o mesmo da suíte da v1)
create function pg_temp.cpf(base text) returns text language plpgsql as $$
declare s int; d1 int; d2 int;
begin
  s := 0; for i in 1..9 loop s := s + substr(base, i, 1)::int * (11 - i); end loop; d1 := (s * 10) % 11 % 10;
  s := 0; for i in 1..9 loop s := s + substr(base, i, 1)::int * (12 - i); end loop; s := s + d1 * 2; d2 := (s * 10) % 11 % 10;
  return base || d1 || d2;
end $$;
-- "Pagou tudo" à vista no PIX: taxa 99 + matrícula 180 = 279, sem opcionais.
create function pg_temp.tudo(base text, email text, transacao text, ref text, curso text default 'bbbbbbbb-0000-4000-8000-000000000001',
                             extra jsonb default '{}') returns jsonb language sql as $$
  select jsonb_build_object('nome', 'Pessoa de Teste ' || base, 'cpf', pg_temp.cpf(base), 'email', email, 'celular', '21987654321',
    'curso_id', curso, 'transacao', transacao, 'metodo', 'pix', 'valor_centavos', 9900, 'matricula_centavos', 18000,
    'parcelas', 1, 'total_centavos', 27900, 'pago_em', '2026-10-08T15:00:00Z', 'referencia', ref,
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

-- Cursos e turmas de teste com datas relativas a hoje (Brasília).
insert into "Curso" (id, nome, "cargaHoraria", "precoAvista", "precoCheio", parcelas, "valorParcela", ativo) values
  ('bbbbbbbb-0000-4000-8000-000000000001', 'Curso Pagar Tudo', 8, 180, 200, 2, 100, true),
  ('bbbbbbbb-0000-4000-8000-000000000002', 'Curso Sem Turma Aberta', 8, 180, 180, 1, 180, true),
  ('bbbbbbbb-0000-4000-8000-000000000003', 'Curso Lotado', 8, 180, 180, 1, 180, true);
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto") values
  ('pt-turma-a', 'bbbbbbbb-0000-4000-8000-000000000001', 30, 'ABERTA', ((now() at time zone 'America/Sao_Paulo')::date + 7)::timestamp + time '12:00'),
  ('pt-turma-b', 'bbbbbbbb-0000-4000-8000-000000000001', 30, 'CONFIRMADA', ((now() at time zone 'America/Sao_Paulo')::date + 30)::timestamp + time '12:00'),
  ('pt-encerrada', 'bbbbbbbb-0000-4000-8000-000000000002', 30, 'ENCERRADA', ((now() at time zone 'America/Sao_Paulo')::date - 30)::timestamp + time '12:00'),
  ('pt-lotada', 'bbbbbbbb-0000-4000-8000-000000000003', 1, 'ABERTA', ((now() at time zone 'America/Sao_Paulo')::date + 10)::timestamp + time '12:00');
insert into "Usuario" (id, nome, email, "cpfCnpj", "senhaHash", papel, "atualizadoEm", "tipoDocumento") values
  ('u-ocupa', 'Aluno Que Ocupa A Vaga', 'ocupa@exemplo.test', pg_temp.cpf('800000001'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF'),
  ('u-pago-escola', 'Aluna Ja Pagou Na Escola', 'pagou@exemplo.test', pg_temp.cpf('800000002'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF'),
  ('u-taxa-escola', 'Aluno Pagou So A Taxa', 'taxa@exemplo.test', pg_temp.cpf('800000003'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF'),
  ('u-fantasma', 'Aluna Nunca Pagou', 'fantasma@exemplo.test', pg_temp.cpf('800000004'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF');
insert into "Matricula" (id, "alunoId", "turmaId", plano, forma, "valorCurso", "valorTaxaMatricula", "statusPagamento", "taxaConfirmada",
                         "confirmadaEm", "confirmadaPor", "atualizadoEm") values
  ('m-ocupa', 'u-ocupa', 'pt-lotada', 'A_VISTA', 'PIX', 279, 99, 'PAGO', true, now(), 'unicopag', now()),
  ('m-pago-escola', 'u-pago-escola', 'pt-turma-b', 'A_VISTA', 'CREDITO', 279, 99, 'PAGO', true, now(), 'unicopag', now()),
  ('m-taxa-escola', 'u-taxa-escola', 'pt-turma-b', 'PARCELADO', 'CREDITO', 299, 99, 'PENDENTE', true, null, null, now()),
  ('m-fantasma', 'u-fantasma', 'pt-turma-b', 'A_VISTA', 'PIX', 279, 99, 'PENDENTE', false, null, null, now());
insert into "Pagamento" (id, "matriculaId", tipo, gateway, "gatewayRef", "gatewayHash", metodo, valor, status, "gatewayStatus", "atualizadoEm") values
  ('pg-pago-escola', 'm-pago-escola', 'CURSO', 'unicopag', 'ESC-HASH-1', 'ESC-HASH-1', 'CREDITO', 279, 'PAGO', 'paid', now()),
  ('pg-taxa-escola', 'm-taxa-escola', 'TAXA', 'unicopag', 'ESC-HASH-2', 'ESC-HASH-2', 'PIX', 99, 'PAGO', 'paid', now());

-- ------------------------------------------------------------------ privilégios (iguais aos da v1)
select ok(has_function_privilege('service_role', 'public.matricula_rapida(jsonb)', 'EXECUTE'), 'v2: service_role executa');
select ok(not has_function_privilege('anon', 'public.matricula_rapida(jsonb)', 'EXECUTE'), 'v2: anon não executa');
select ok(exists (select 1 from pg_proc p, unnest(p.proconfig) c where p.proname = 'matricula_rapida' and c = 'search_path=""'), 'v2: search_path vazio');
select ok(not exists (select 1 from pg_class c where c.relnamespace = 'public'::regnamespace and c.relkind = 'r'
                      and has_table_privilege('service_role', c.oid, 'SELECT,INSERT,UPDATE,DELETE')), 'v2: service_role continua sem nenhuma tabela');

-- ------------------------------------------------------------------ 1. aluno novo, pagou tudo no PIX
create temp table t1 as select pg_temp.rpc(pg_temp.tudo('700000001', 'tudo.um@exemplo.test', 'TUDO000001', '501')) as r;
select is((select r->>'resultado' from t1), 'matriculado', 'pix: matriculado');
select is((select r->>'matricula_paga' from t1), 'true', 'pix: matricula_paga');
select is((select r->'turma'->>'id' from t1), 'pt-turma-a', 'pix: próxima turma com vaga');
select is((select r->'avisos' from t1), '[]'::jsonb, 'pix: sem avisos');
select is((select r->>'aviso' from t1), null, 'pix: aviso nulo');
select results_eq($$ select m.plano::text, m.forma::text, m."valorCurso", m."valorTaxaMatricula", m."statusPagamento"::text, m."taxaConfirmada",
                            m."taxaConfirmadaPor", m."confirmadaPor", m."confirmadaEm", m."prazoPagamentoCurso"
                       from "Matricula" m join t1 on m.id = t1.r->>'matricula_id' $$,
                  $$ values ('A_VISTA', 'PIX', 279.00::numeric(10,2), 99.00::numeric(10,2), 'PAGO', true, 'site cruzvermelhariodejaneiro.org',
                             'site cruzvermelhariodejaneiro.org', '2026-10-08 15:00:00'::timestamp(3), null::timestamp(3)) $$,
                  'pix: matrícula à vista PAGA, valorCurso = taxa + matrícula, confirmadaEm = pago_em');
select results_eq($$ select p.tipo::text, p.status::text, p.gateway, p."gatewayRef", p."gatewayHash", p.valor, p.metodo::text, p."gatewayStatus",
                            (p."gatewayResponse"->>'parcelas')::int, (p."gatewayResponse"->>'valor_matricula_centavos')::int,
                            (p."gatewayResponse"->>'total_cobrado_centavos')::int
                       from "Pagamento" p join t1 on p."matriculaId" = t1.r->>'matricula_id' $$,
                  $$ values ('CURSO', 'PAGO', 'unicopag-2', 'TUDO000001', 'TUDO000001', 279.00::numeric(10,2), 'PIX', 'paid', 1, 18000, 27900) $$,
                  'pix: UM Pagamento CURSO de 279 na conta da instituição (unicopag-2), com o hash');
select is((select (valor::jsonb)->>'matriculaId' from "Configuracao" where chave = 'matricularapida:501'), (select r->>'matricula_id' from t1),
          'pix: anotação matricularapida:501 aponta para a matrícula');
select is((select (valor::jsonb)->>'totalComTaxa' from "Configuracao" where chave = 'matricularapida:501'), 'true',
          'pix: anotação com totalComTaxa (corrigirTotais da escola pula)');
select is((select (valor::jsonb) ? 'naEscola' from "Configuracao" where chave = 'matricularapida:501'), false,
          'pix: anotação sem naEscola (o Painel da escola não conta o dinheiro duas vezes)');
select results_eq($$ select acao, (detalhe->>'pagouMatricula')::boolean, (detalhe->>'matriculaPaga')::boolean from "LogAuditoria"
                      where detalhe->>'transacao' = 'TUDO000001' and "alvoTipo" = 'Matricula' $$,
                  $$ values ('MATRICULOU_PELO_SITE', true, true) $$, 'pix: LogAuditoria MATRICULOU_PELO_SITE com pagouMatricula');
-- O que a escola olha para cobrar (as mesmas condições do código da escola, em SQL):
select is((select count(*)::int from "Matricula" m join t1 on m.id = t1.r->>'matricula_id'
            where m."statusPagamento" = 'PENDENTE' and m."taxaConfirmada" and m.plano = 'PARCELADO'), 0,
          'pix: não entra na faixa "Falta pagar a matrícula" (server.js) nem no lembrete (lembretes.js filtroBase)');
select is((select count(*)::int from "Matricula" m join t1 on m.id = t1.r->>'matricula_id'
            where m."statusPagamento" in ('PAGO', 'PARCELADO') and m."taxaConfirmada"), 1,
          'pix: entra em boas-vindas e pesquisa (taxaConfirmada + PAGO/PARCELADO)');

-- ------------------------------------------------------------------ 2. repetição (mesma transação)
create temp table t2 as select pg_temp.rpc(pg_temp.tudo('700000001', 'tudo.um@exemplo.test', 'TUDO000001', '501')) as r;
select is((select r->>'repetido' from t2), 'true', 'repetição: repetido');
select is((select r->>'matricula_id' from t2), (select r->>'matricula_id' from t1), 'repetição: mesma matrícula');
select is((select r->>'matricula_paga' from t2), 'true', 'repetição: matricula_paga');
select is((select count(*)::int from "Pagamento" where "gatewayHash" = 'TUDO000001'), 1, 'repetição: um Pagamento só');
select is((select count(*)::int from "Matricula" m join "Usuario" u on u.id = m."alunoId" where u.email = 'tudo.um@exemplo.test'), 1, 'repetição: uma matrícula só');
select is((select count(*)::int from "Configuracao" where chave like 'matricularapida:%'), 1, 'repetição: uma anotação só');

-- ------------------------------------------------------------------ 3. cartão em 12x, juros do aluno
create temp table t3 as select pg_temp.rpc(pg_temp.tudo('700000003', 'doze@exemplo.test', 'TUDO000003', '503',
  extra => '{"metodo": "cartao", "parcelas": 12, "juros_centavos": 6138, "total_centavos": 34038}')) as r;
select is((select r->>'matricula_paga' from t3), 'true', '12x: matricula_paga');
select results_eq($$ select m.forma::text, m.plano::text, m."statusPagamento"::text, m."valorCurso" from "Matricula" m join t3 on m.id = t3.r->>'matricula_id' $$,
                  $$ values ('CREDITO', 'A_VISTA', 'PAGO', 279.00::numeric(10,2)) $$, '12x: PAGO, valorCurso sem juros (279)');
select results_eq($$ select p.valor, (p."gatewayResponse"->>'parcelas')::int, (p."gatewayResponse"->>'juros_centavos')::int,
                            (p."gatewayResponse"->>'total_cobrado_centavos')::int
                       from "Pagamento" p join t3 on p."matriculaId" = t3.r->>'matricula_id' $$,
                  $$ values (279.00::numeric(10,2), 12, 6138, 34038) $$, '12x: Pagamento base 279; parcelas, juros e total em gatewayResponse');

-- ------------------------------------------------------------------ 4. dados inválidos (nada gravado)
select throws_ok($$ select pg_temp.rpc(pg_temp.tudo('700000010', 'inv@exemplo.test', 'TUDO000010', '510', extra => '{"matricula_centavos": "180,00"}')) $$,
                 '22023', 'dados inválidos: matricula_centavos', 'inválido: matricula_centavos com vírgula');
select throws_ok($$ select pg_temp.rpc(pg_temp.tudo('700000010', 'inv@exemplo.test', 'TUDO000010', '510', extra => '{"matricula_centavos": 500001, "total_centavos": 600000}')) $$,
                 '22023', 'dados inválidos: matricula_centavos', 'inválido: matricula_centavos acima de R$ 5.000');
select throws_ok($$ select pg_temp.rpc(pg_temp.tudo('700000010', 'inv@exemplo.test', 'TUDO000010', '510', extra => '{"metodo": "cartao", "parcelas": 13}')) $$,
                 '22023', 'dados inválidos: parcelas', 'inválido: 13 parcelas');
select throws_ok($$ select pg_temp.rpc(pg_temp.tudo('700000010', 'inv@exemplo.test', 'TUDO000010', '510', extra => '{"metodo": "cartao", "parcelas": 0}')) $$,
                 '22023', 'dados inválidos: parcelas', 'inválido: 0 parcelas');
select throws_ok($$ select pg_temp.rpc(pg_temp.tudo('700000010', 'inv@exemplo.test', 'TUDO000010', '510', extra => '{"parcelas": 3, "total_centavos": 30000}')) $$,
                 '22023', 'dados inválidos: parcelas (PIX é à vista)', 'inválido: PIX parcelado');
select throws_ok($$ select pg_temp.rpc(pg_temp.tudo('700000010', 'inv@exemplo.test', 'TUDO000010', '510') - 'total_centavos') $$,
                 '22023', 'dados inválidos: total_centavos (obrigatório com matricula_centavos)', 'inválido: sem total_centavos');
select throws_ok($$ select pg_temp.rpc(pg_temp.tudo('700000010', 'inv@exemplo.test', 'TUDO000010', '510', extra => '{"total_centavos": 27899}')) $$,
                 '22023', 'dados inválidos: total_centavos', 'inválido: total menor que taxa + matrícula');
select throws_ok($$ select pg_temp.rpc(pg_temp.tudo('700000010', 'inv@exemplo.test', 'TUDO000010', '510', extra => '{"total_centavos": 65801}')) $$,
                 '22023', 'dados inválidos: total_centavos (obrigatório com matricula_centavos)', 'inválido: total acima do dobro + R$ 100');
select throws_ok($$ select pg_temp.rpc(pg_temp.tudo('700000010', 'inv@exemplo.test', 'TUDO000010', '510', extra => '{"metodo": "cartao", "parcelas": 2, "juros_centavos": 500, "total_centavos": 28000}')) $$,
                 '22023', 'dados inválidos: juros_centavos', 'inválido: juros maiores que o total menos a base');
select throws_ok($$ select pg_temp.rpc(pg_temp.tudo('700000010', 'inv@exemplo.test', 'TUDO000010', '510', extra => '{"juros_centavos": 100, "total_centavos": 28000}')) $$,
                 '22023', 'dados inválidos: juros_centavos', 'inválido: juros à vista');
select throws_ok($$ select pg_temp.rpc(pg_temp.tudo('700000010', 'inv@exemplo.test', 'TUDO000010', '510') - 'referencia') $$,
                 '22023', 'dados inválidos: referencia (obrigatória com matricula_centavos)', 'inválido: sem referência');
select throws_ok($$ select pg_temp.rpc(pg_temp.tudo('700000010', 'inv@exemplo.test', 'TUDO000010', 'abc')) $$,
                 '22023', 'dados inválidos: referencia (obrigatória com matricula_centavos)', 'inválido: referência não numérica');
select throws_ok($$ select pg_temp.rpc(pg_temp.tudo('700000010', 'inv@exemplo.test', 'TUDO000010', '510', extra => '{"valor_centavos": 100001}')) $$,
                 '22023', 'dados inválidos: valor_centavos', 'inválido: taxa acima de R$ 1.000 (limite da v1 mantido)');
select is((select count(*)::int from "Usuario" where email = 'inv@exemplo.test'), 0, 'inválidos: nada gravado');
-- O limite de R$ 1.000 vale só para a taxa: curso caro (R$ 1.049) passa em matricula_centavos.
create temp table t4 as select pg_temp.rpc(pg_temp.tudo('700000011', 'caro@exemplo.test', 'TUDO000011', '511',
  extra => '{"matricula_centavos": 104900, "total_centavos": 114800}')) as r;
select is((select r->>'matricula_paga' from t4), 'true', 'curso de R$ 1.049: passa (taxa e matrícula em campos separados)');
select ok((select r->'avisos' ? 'preco_divergente' from t4), 'curso de R$ 1.049 num curso de R$ 180: aviso preco_divergente');
select is((select m."valorCurso" from "Matricula" m join t4 on m.id = t4.r->>'matricula_id'), 1148.00::numeric(10,2),
          'preço divergente: vale o que o aluno pagou (99 + 1.049)');

-- ------------------------------------------------------------------ 5. curso já pago na escola (pagamento em dobro)
create temp table t5 as select pg_temp.rpc(pg_temp.tudo('800000002', 'pagou@exemplo.test', 'TUDO000005', '505')) as r;
select is((select r->>'resultado' from t5), 'matriculado', 'dobro: matriculado (já estava)');
select is((select r->>'matricula_id' from t5), 'm-pago-escola', 'dobro: aponta para a matrícula da escola');
select is((select r->>'matricula_paga' from t5), 'false', 'dobro: esta transação não pagou nada na escola');
select is((select r->>'aviso' from t5), 'curso_ja_pago', 'dobro: aviso curso_ja_pago');
select results_eq($$ select "statusPagamento"::text, "valorCurso", "confirmadaPor" from "Matricula" where id = 'm-pago-escola' $$,
                  $$ values ('PAGO', 279.00::numeric(10,2), 'unicopag') $$, 'dobro: matrícula da escola intacta');
select is((select count(*)::int from "Pagamento" where "matriculaId" = 'm-pago-escola'), 1, 'dobro: nenhum Pagamento novo');
select is((select count(*)::int from "LogAuditoria" where acao = 'PAGOU_EM_DOBRO_PELO_SITE' and detalhe->>'transacao' = 'TUDO000005'), 1, 'dobro: LogAuditoria PAGOU_EM_DOBRO_PELO_SITE');
select is((select (valor::jsonb)->>'dobro' from "Configuracao" where chave = 'matricularapida:505'), 'true', 'dobro: anotação com dobro (a aba da escola avisa e não religa)');
create temp table t5b as select pg_temp.rpc(pg_temp.tudo('800000002', 'pagou@exemplo.test', 'TUDO000005', '505')) as r;
select is((select r->>'repetido' from t5b), 'true', 'dobro repetido: repetido');
select is((select r->>'aviso' from t5b), 'curso_ja_pago', 'dobro repetido: mesmo aviso');
select is((select count(*)::int from "LogAuditoria" where acao = 'PAGOU_EM_DOBRO_PELO_SITE' and detalhe->>'transacao' = 'TUDO000005'), 1, 'dobro repetido: sem log novo');

-- ------------------------------------------------------------------ 6. taxa já paga na escola, falta o curso
create temp table t6 as select pg_temp.rpc(pg_temp.tudo('800000003', 'taxa@exemplo.test', 'TUDO000006', '506')) as r;
select is((select r->>'matricula_id' from t6), 'm-taxa-escola', 'taxa paga: usa a matrícula da escola');
select is((select r->>'aviso' from t6), 'taxa_em_dobro', 'taxa paga: aviso taxa_em_dobro');
select results_eq($$ select plano::text, "statusPagamento"::text, "valorCurso", "valorTaxaMatricula", "confirmadaPor" from "Matricula" where id = 'm-taxa-escola' $$,
                  $$ values ('PARCELADO', 'PAGO', 279.00::numeric(10,2), 99.00::numeric(10,2), 'site cruzvermelhariodejaneiro.org') $$,
                  'taxa paga: vira PAGO, valorCurso = taxa já paga + matrícula, plano mantido');
select results_eq($$ select tipo::text, valor, gateway, ("gatewayResponse"->>'taxa_em_dobro_centavos')::int from "Pagamento"
                      where "matriculaId" = 'm-taxa-escola' and "gatewayHash" = 'TUDO000006' $$,
                  $$ values ('CURSO', 180.00::numeric(10,2), 'unicopag-2', 9900) $$, 'taxa paga: Pagamento CURSO só da matrícula (180)');
select is((select acao from "LogAuditoria" where "alvoId" = 'm-taxa-escola' and detalhe->>'transacao' = 'TUDO000006'), 'CONFIRMOU_PAGAMENTO_PELO_SITE',
          'taxa paga: LogAuditoria CONFIRMOU_PAGAMENTO_PELO_SITE');

-- ------------------------------------------------------------------ 7. matrícula da escola nunca paga
create temp table t7 as select pg_temp.rpc(pg_temp.tudo('800000004', 'fantasma@exemplo.test', 'TUDO000007', '507', extra => '{"metodo": "cartao"}')) as r;
select is((select r->>'matricula_id' from t7), 'm-fantasma', 'nunca paga: usa a matrícula da escola');
select results_eq($$ select plano::text, forma::text, "statusPagamento"::text, "valorCurso", "taxaConfirmada" from "Matricula" where id = 'm-fantasma' $$,
                  $$ values ('A_VISTA', 'CREDITO', 'PAGO', 279.00::numeric(10,2), true) $$, 'nunca paga: vira à vista PAGA');
select is((select valor from "Pagamento" where "matriculaId" = 'm-fantasma'), 279.00::numeric(10,2), 'nunca paga: Pagamento CURSO de 279');

-- ------------------------------------------------------------------ 8. turma lotada
create temp table t8 as select pg_temp.rpc(pg_temp.tudo('700000008', 'lotada@exemplo.test', 'TUDO000008', '508',
  curso => 'bbbbbbbb-0000-4000-8000-000000000003')) as r;
select is((select r->>'resultado' from t8), 'matriculado', 'lotada: matriculado mesmo assim (pagou o curso inteiro)');
select is((select r->'turma'->>'id' from t8), 'pt-lotada', 'lotada: na próxima turma aberta');
select is((select r->>'aviso' from t8), 'turma_lotada', 'lotada: aviso turma_lotada');
-- Só a taxa (v1) com a turma lotada continua sem turma, como antes.
create temp table t8b as select pg_temp.rpc(pg_temp.tudo('700000009', 'lotada2@exemplo.test', 'TUDO000009', '509',
  curso => 'bbbbbbbb-0000-4000-8000-000000000003') - 'matricula_centavos' - 'parcelas' || '{"total_centavos": 9900}') as r;
select is((select r->>'resultado' from t8b), 'sem_turma', 'lotada, só a taxa (v1): sem_turma, como antes');

-- ------------------------------------------------------------------ 9. curso sem turma aberta
create temp table t9 as select pg_temp.rpc(pg_temp.tudo('700000012', 'semturma@exemplo.test', 'TUDO000012', '512',
  curso => 'bbbbbbbb-0000-4000-8000-000000000002')) as r;
select is((select r->>'resultado' from t9), 'sem_turma', 'sem turma: só a conta');
select is((select r->>'aviso' from t9), 'matricula_paga_sem_turma', 'sem turma: aviso matricula_paga_sem_turma');
select is((select r->>'matricula_paga' from t9), 'false', 'sem turma: nada pago na escola');
select results_eq($$ select (detalhe->>'pagouMatricula')::boolean, (detalhe->>'valorMatricula')::numeric from "LogAuditoria"
                      where acao = 'TAXA_PAGA_SEM_TURMA_PELO_SITE' and detalhe->>'transacao' = 'TUDO000012' $$,
                  $$ values (true, 180.00::numeric) $$, 'sem turma: LogAuditoria diz que a matrícula também foi paga');
select is((select count(*)::int from "Configuracao" where chave = 'matricularapida:512'), 0, 'sem turma: sem anotação (sem matrícula)');
create temp table t9b as select pg_temp.rpc(pg_temp.tudo('700000012', 'semturma@exemplo.test', 'TUDO000012', '512',
  curso => 'bbbbbbbb-0000-4000-8000-000000000002')) as r;
select is((select r->>'repetido' || '/' || (r->>'resultado') || '/' || (r->>'aviso') from t9b), 'true/sem_turma/matricula_paga_sem_turma', 'sem turma repetido: mesmo resultado e aviso');

-- ------------------------------------------------------------------ 10. idempotência da v1 depois do batimento da escola
-- Só a taxa (v1); depois a escola troca o gateway da taxa para 'unicopag-2' e o gatewayRef para o hash
-- (lib/matricula-rapida.js:299-301,325). A mesma confirmação chegando de novo não pode duplicar.
create temp table t10 as select pg_temp.rpc(pg_temp.tudo('700000013', 'v1@exemplo.test', 'TAXA000013', '513') - 'matricula_centavos' - 'parcelas' || '{"total_centavos": 10395}') as r;
update "Pagamento" set gateway = 'unicopag-2', "gatewayRef" = 'TAXA000013' where "gatewayHash" = 'TAXA000013';
create temp table t10b as select pg_temp.rpc(pg_temp.tudo('700000013', 'v1@exemplo.test', 'TAXA000013', '513') - 'matricula_centavos' - 'parcelas' || '{"total_centavos": 10395}') as r;
select is((select r->>'repetido' from t10b), 'true', 'v1 após batimento: repetido');
select is((select count(*)::int from "Pagamento" where "gatewayHash" = 'TAXA000013'), 1, 'v1 após batimento: um Pagamento só');

select * from finish();
rollback;
