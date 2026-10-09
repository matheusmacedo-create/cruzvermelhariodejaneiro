-- Testes (pgTAP) da venda de taxa + matrícula nos cursos SEM turma (mudança de 08/10/2026, spec seção 10).
-- O site recebe tudo, a v2 cria só a conta e, chamada de novo com espera_turma, matricula PAGO quando a turma abre.
-- Rodar depois de 00, 01, 02 e matricula_rapida_v2.sql. Tudo dentro de uma transação desfeita no fim. Dados fictícios.
-- Ajustada na revisão da venda sem turma (spec 10.16): o pedido leva inicio_ate (a data limite da compra), as turmas
-- de teste nascem há 1 dia (carência de 6 h) e com aulas cadastradas, e a antecedência mínima é de 10 dias.
\set ON_ERROR_STOP 1
\set QUIET 1
begin;
create extension if not exists pgtap;
select plan(70);

create function pg_temp.cpf(base text) returns text language plpgsql as $$
declare s int; d1 int; d2 int;
begin
  s := 0; for i in 1..9 loop s := s + substr(base, i, 1)::int * (11 - i); end loop; d1 := (s * 10) % 11 % 10;
  s := 0; for i in 1..9 loop s := s + substr(base, i, 1)::int * (12 - i); end loop; s := s + d1 * 2; d2 := (s * 10) % 11 % 10;
  return base || d1 || d2;
end $$;
-- Dia n (relativo a hoje em Brasília), ao meio-dia; e o instante de n dias atrás, como o site manda em pago_em.
create function pg_temp.dia(n int) returns timestamp language sql as $$
  select ((now() at time zone 'America/Sao_Paulo')::date + n)::timestamp + time '12:00' $$;
create function pg_temp.pago(n int) returns text language sql as $$
  select to_char((now() at time zone 'UTC') - make_interval(days => n), 'YYYY-MM-DD"T"HH24:MI:SS"Z"') $$;
-- "Pagou tudo" num curso sem turma, com espera_turma. Padrão: curso de R$ 150, PIX, pago há 30 dias.
create function pg_temp.espera(base text, transacao text, ref text, curso text, extra jsonb default '{}') returns jsonb language sql as $$
  select jsonb_build_object('nome', 'Pessoa Espera ' || base, 'cpf', pg_temp.cpf(base), 'email', 'esp' || base || '@exemplo.test',
    'celular', '21987654321', 'curso_id', curso, 'transacao', transacao, 'metodo', 'pix', 'valor_centavos', 9900,
    'matricula_centavos', 15000, 'parcelas', 1, 'total_centavos', 24900, 'pago_em', pg_temp.pago(30), 'referencia', ref,
    'espera_turma', true, 'inicio_ate', to_char((now() at time zone 'America/Sao_Paulo')::date + 120, 'YYYY-MM-DD'),
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
  select id from "Usuario" where email = 'esp' || base || '@exemplo.test' $$;

-- As turmas de teste nascem há 1 dia: a carência de 6 h da revisão vale só para o teste que a cria agora (13).
alter table "Turma" alter column "criadoEm" set default (now() - interval '1 day');
-- Cursos de teste, todos sem turma aberta no começo (as turmas entram ao longo do teste, como a escola faria).
insert into "Curso" (id, nome, "cargaHoraria", "precoAvista", "precoCheio", parcelas, "valorParcela", ativo) values
  ('dddddddd-0000-4000-8000-000000000001', 'Bombeiro Civil Teste', 80, 950, 950, 5, 195, true),
  ('dddddddd-0000-4000-8000-000000000002', 'Curso Fila', 8, 150, 150, 2, 77.5, true),
  ('dddddddd-0000-4000-8000-000000000003', 'Curso Espera Pendente', 8, 150, 150, 2, 77.5, true),
  ('dddddddd-0000-4000-8000-000000000004', 'Curso Espera Dobro', 8, 150, 150, 2, 77.5, true),
  ('dddddddd-0000-4000-8000-000000000006', 'Curso Espera Imediata', 8, 150, 150, 2, 77.5, true),
  ('dddddddd-0000-4000-8000-000000000007', 'Curso Espera Preco', 8, 150, 150, 2, 77.5, true),
  ('dddddddd-0000-4000-8000-000000000008', 'Curso PIX Atrasado', 8, 180, 180, 2, 90, true),
  ('dddddddd-0000-4000-8000-000000000009', 'Curso Espera Reaproveita', 8, 150, 150, 2, 77.5, true);

-- ------------------------------------------------------------------ 1. entradas
select throws_ok($$ select pg_temp.rpc(pg_temp.espera('900000099', 'ESPX000001', '799', 'dddddddd-0000-4000-8000-000000000001', '{"espera_turma": "sim"}')) $$,
                 '22023', 'dados inválidos: espera_turma', 'espera_turma que não é booleano: recusado');
select throws_ok($$ select pg_temp.rpc(pg_temp.espera('900000099', 'ESPX000001', '799', 'dddddddd-0000-4000-8000-000000000001')
                                       - 'matricula_centavos' - 'parcelas' || '{"total_centavos": 9900}') $$,
                 '22023', 'dados inválidos: espera_turma (só com matricula_centavos)', 'espera_turma só com a taxa (v1): recusado');
select throws_ok($$ select pg_temp.rpc(pg_temp.espera('900000099', 'ESPX000001', '799', 'dddddddd-0000-4000-8000-000000000001', '{"turma_id": "bt-nova"}')) $$,
                 '22023', 'dados inválidos: espera_turma não combina com turma_id', 'espera_turma com turma_id: recusado');
select is((select count(*)::int from "Usuario" where email = 'esp900000099@exemplo.test'), 0, 'entradas recusadas: nada gravado');

-- ------------------------------------------------------------------ 2. Bombeiro Civil sem turma, PIX, R$ 1.049 (R$ 99 + R$ 950)
create temp table a1 as select pg_temp.rpc(pg_temp.espera('900000001', 'ESP0000001', '701', 'dddddddd-0000-4000-8000-000000000001',
  '{"matricula_centavos": 95000, "total_centavos": 104900}')) as r;
select is((select r->>'resultado' from a1), 'sem_turma', 'sem turma: resultado sem_turma (passa com R$ 1.049; o limite de R$ 1.000 é só da taxa)');
select is((select r->>'aviso' from a1), 'matricula_paga_sem_turma', 'sem turma: aviso matricula_paga_sem_turma');
select is((select r->'avisos' from a1), '["matricula_paga_sem_turma"]'::jsonb, 'sem turma: só esse aviso (preço igual ao da escola)');
select is((select r->>'matricula_paga' from a1), 'false', 'sem turma: matricula_paga false (nada pago na escola ainda)');
select is((select r->>'espera_motivo' from a1), 'nenhuma_turma', 'sem turma: espera_motivo nenhuma_turma');
select ok((select r->'turma' = 'null'::jsonb and r->'matricula_id' = 'null'::jsonb from a1), 'sem turma: sem turma nem matrícula na resposta');
select is((select (r->>'aluno_novo') || '/' || (r->>'link_acesso') from a1), 'true/true', 'sem turma: conta nova, com o link de criar a senha');
select is((select count(*)::int from "Matricula" where "alunoId" = pg_temp.aluno('900000001')), 0,
          'sem turma: nenhuma matrícula na escola (nada que a escola cobre: sem faixa, botão nem lembrete)');
select is((select count(*)::int from "Pagamento" where "gatewayHash" = 'ESP0000001'), 0, 'sem turma: nenhum Pagamento');
select is((select count(*)::int from "Configuracao" where chave = 'matricularapida:701'), 0, 'sem turma: sem anotação da aba Matrícula rápida');
select results_eq($$ select (detalhe->>'pagouMatricula')::boolean, (detalhe->>'valorMatricula')::numeric, (detalhe->>'esperaTurma')::boolean,
                            (detalhe->>'totalCobradoCentavos')::int, detalhe->>'esperaMotivo'
                       from "LogAuditoria" where acao = 'TAXA_PAGA_SEM_TURMA_PELO_SITE' and detalhe->>'transacao' = 'ESP0000001' $$,
                  $$ values (true, 950.00::numeric, true, 104900, 'nenhuma_turma') $$,
                  'sem turma: LogAuditoria com a matrícula paga, a espera e o total');
select is((select count(*)::int from "Usuario" where email = 'esp900000001@exemplo.test'), 1, 'sem turma: a conta existe');

-- ------------------------------------------------------------------ 3. Bombeiro Civil em 12x, com os opcionais (amount R$ 1.068,85; juros do aluno)
create temp table b1 as select pg_temp.rpc(pg_temp.espera('900000002', 'ESP0000002', '702', 'dddddddd-0000-4000-8000-000000000001',
  '{"metodo": "cartao", "parcelas": 12, "matricula_centavos": 95000, "juros_centavos": 35272, "total_centavos": 142157}')) as r;
select is((select r->>'resultado' || '/' || (r->>'matricula_paga') from b1), 'sem_turma/false', '12x sem turma: sem_turma, nada pago na escola');
select results_eq($$ select (detalhe->>'parcelas')::int, (detalhe->>'jurosCentavos')::int, (detalhe->>'totalCobradoCentavos')::int
                       from "LogAuditoria" where acao = 'TAXA_PAGA_SEM_TURMA_PELO_SITE' and detalhe->>'transacao' = 'ESP0000002' $$,
                  $$ values (12, 35272, 142157) $$, '12x sem turma: parcelas, juros e total no LogAuditoria');

-- ------------------------------------------------------------------ 4. a rotina do site chama de novo e a turma não abriu
create temp table a2 as select pg_temp.rpc(pg_temp.espera('900000001', 'ESP0000001', '701', 'dddddddd-0000-4000-8000-000000000001',
  '{"matricula_centavos": 95000, "total_centavos": 104900}')) as r;
select is((select (r->>'repetido') || '/' || (r->>'resultado') || '/' || (r->>'aviso') from a2), 'true/sem_turma/matricula_paga_sem_turma',
          'nova chamada sem turma: o mesmo resultado, como repetição');
select is((select r->>'espera_motivo' from a2), 'nenhuma_turma', 'nova chamada sem turma: espera_motivo');
select is((select r->>'aluno_novo' from a2), 'true', 'nova chamada sem turma: aluno_novo da primeira chamada');
select is((select count(*)::int from "LogAuditoria" where detalhe->>'transacao' = 'ESP0000001'), 2,
          'nova chamada sem turma: nada gravado (só os dois registros da primeira: conta e sem turma)');
select is((select count(*)::int from "Usuario" where email = 'esp900000001@exemplo.test'), 1, 'nova chamada sem turma: nenhuma conta nova');
-- Sem espera_turma (o site de antes, ou a retentativa comum), a repetição não procura turma: o "sem_turma" de antes.
create temp table a2b as select pg_temp.rpc(pg_temp.espera('900000001', 'ESP0000001', '701', 'dddddddd-0000-4000-8000-000000000001',
  '{"matricula_centavos": 95000, "total_centavos": 104900}') - 'espera_turma') as r;
select is((select (r->>'repetido') || '/' || (r->>'resultado') || '/' || coalesce(r->>'espera_motivo', 'sem motivo') from a2b),
          'true/sem_turma/sem motivo', 'sem espera_turma: repetição de sempre, sem procurar turma');

-- ------------------------------------------------------------------ 5. a escola abre uma turma para amanhã: cedo demais para quem espera
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto") values
  ('bt-amanha', 'dddddddd-0000-4000-8000-000000000001', 30, 'ABERTA', pg_temp.dia(1));
create temp table a3 as select pg_temp.rpc(pg_temp.espera('900000001', 'ESP0000001', '701', 'dddddddd-0000-4000-8000-000000000001',
  '{"matricula_centavos": 95000, "total_centavos": 104900}')) as r;
select is((select r->>'resultado' || '/' || (r->>'espera_motivo') from a3), 'sem_turma/turma_muito_proxima',
          'turma amanhã: continua esperando (antecedência mínima de 10 dias), motivo turma_muito_proxima');
select is((select count(*)::int from "Matricula" where "turmaId" = 'bt-amanha'), 0, 'turma amanhã: ninguém matriculado nela');

-- ------------------------------------------------------------------ 6. a turma abre: matrícula PAGA na primeira chamada seguinte
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto") values
  ('bt-nova', 'dddddddd-0000-4000-8000-000000000001', 30, 'ABERTA', pg_temp.dia(20));
insert into "AulaData" values ('bt-ad2', 'bt-nova', pg_temp.dia(21)::date, '18:00 - 22:00'),
                              ('bt-ad1', 'bt-nova', pg_temp.dia(20)::date, '18:00 - 22:00');
create temp table a4 as select pg_temp.rpc(pg_temp.espera('900000001', 'ESP0000001', '701', 'dddddddd-0000-4000-8000-000000000001',
  '{"matricula_centavos": 95000, "total_centavos": 104900}')) as r;
select is((select r->>'resultado' from a4), 'matriculado', 'turma aberta: matriculado');
select is((select r->>'repetido' from a4), 'false', 'turma aberta: não é repetição (é a primeira matrícula desta compra)');
select is((select r->>'matricula_paga' from a4), 'true', 'turma aberta: matricula_paga');
select is((select r->'turma'->>'id' from a4), 'bt-nova', 'turma aberta: a primeira com início pelo menos 10 dias depois de hoje (pula a de amanhã)');
select is((select (r->'turma'->>'primeira_aula') || ' ' || (r->'turma'->>'horario') from a4),
          to_char(pg_temp.dia(20), 'YYYY-MM-DD') || ' 18:00 - 22:00', 'turma aberta: primeira aula e horário na resposta, para o e-mail');
select is((select r->'avisos' from a4), '[]'::jsonb, 'turma aberta: sem avisos');
select is((select r->>'aluno_novo' from a4), 'true', 'turma aberta: aluno_novo da primeira chamada');
select results_eq($$ select m.plano::text, m.forma::text, m."valorCurso", m."valorTaxaMatricula", m."statusPagamento"::text, m."taxaConfirmada",
                            m."confirmadaPor", m."confirmadaEm" = (pg_temp.pago(30)::timestamptz at time zone 'UTC')::timestamp(3), m."prazoPagamentoCurso"
                       from "Matricula" m join a4 on m.id = a4.r->>'matricula_id' $$,
                  $$ values ('A_VISTA', 'PIX', 1049.00::numeric(10,2), 99.00::numeric(10,2), 'PAGO', true, 'site cruzvermelhariodejaneiro.org',
                             true, null::timestamp(3)) $$,
                  'turma aberta: à vista PAGA, valorCurso = taxa + matrícula (R$ 1.049), confirmadaEm = dia do pagamento');
select results_eq($$ select p.tipo::text, p.status::text, p.gateway, p."gatewayHash", p.valor, (p."gatewayResponse"->>'espera_turma')::boolean,
                            (p."gatewayResponse"->>'esperou_turma')::boolean
                       from "Pagamento" p join a4 on p."matriculaId" = a4.r->>'matricula_id' $$,
                  $$ values ('CURSO', 'PAGO', 'unicopag-2', 'ESP0000001', 1049.00::numeric(10,2), true, true) $$,
                  'turma aberta: UM Pagamento CURSO de R$ 1.049 na conta da instituição, com o hash e a marca da espera');
select results_eq($$ select (valor::jsonb)->>'matriculaId', (valor::jsonb)->>'pagoTudo', (valor::jsonb)->>'esperouTurma'
                       from "Configuracao" where chave = 'matricularapida:701' $$,
                  $$ select r->>'matricula_id', 'true', 'true' from a4 $$,
                  'turma aberta: anotação matricularapida:701 (o batimento e o "Encaixar" deixam em paz)');
select results_eq($$ select acao, (detalhe->>'matriculaPaga')::boolean, (detalhe->>'esperouTurma')::boolean from "LogAuditoria"
                      where detalhe->>'transacao' = 'ESP0000001' and "alvoTipo" = 'Matricula' $$,
                  $$ values ('MATRICULOU_PELO_SITE', true, true) $$, 'turma aberta: LogAuditoria MATRICULOU_PELO_SITE com esperouTurma');
select is((select count(*)::int from "Matricula" m join a4 on m.id = a4.r->>'matricula_id'
            where m."statusPagamento" = 'PENDENTE' and m."taxaConfirmada" and m.plano = 'PARCELADO'), 0,
          'turma aberta: fora da faixa "Falta pagar a matrícula" e do lembrete da escola');
select is((select count(*)::int from "Matricula" m join a4 on m.id = a4.r->>'matricula_id'
            where m."statusPagamento" in ('PAGO', 'PARCELADO') and m."taxaConfirmada"), 1, 'turma aberta: entra nas boas-vindas da turma');
-- A do cartão em 12x também entra, com os juros só no gatewayResponse.
create temp table b2 as select pg_temp.rpc(pg_temp.espera('900000002', 'ESP0000002', '702', 'dddddddd-0000-4000-8000-000000000001',
  '{"metodo": "cartao", "parcelas": 12, "matricula_centavos": 95000, "juros_centavos": 35272, "total_centavos": 142157}')) as r;
select is((select r->'turma'->>'id' from b2), 'bt-nova', '12x: matriculado na turma nova');
select results_eq($$ select m.forma::text, m."valorCurso", p.valor, (p."gatewayResponse"->>'parcelas')::int, (p."gatewayResponse"->>'juros_centavos')::int,
                            (p."gatewayResponse"->>'total_cobrado_centavos')::int
                       from "Matricula" m join "Pagamento" p on p."matriculaId" = m.id join b2 on m.id = b2.r->>'matricula_id' $$,
                  $$ values ('CREDITO', 1049.00::numeric(10,2), 1049.00::numeric(10,2), 12, 35272, 142157) $$,
                  '12x: valorCurso e Pagamento sem juros (R$ 1.049); parcelas, juros e total em gatewayResponse');

-- ------------------------------------------------------------------ 7. repetição depois de matriculado
create temp table a5 as select pg_temp.rpc(pg_temp.espera('900000001', 'ESP0000001', '701', 'dddddddd-0000-4000-8000-000000000001',
  '{"matricula_centavos": 95000, "total_centavos": 104900}')) as r;
select is((select (r->>'repetido') || '/' || (r->>'resultado') || '/' || (r->>'matricula_paga') from a5), 'true/matriculado/true',
          'depois de matriculado: repetição devolve a matrícula paga');
select is((select count(*)::int from "Pagamento" where "gatewayHash" = 'ESP0000001') * 100
          + (select count(*)::int from "Matricula" where "alunoId" = pg_temp.aluno('900000001')) * 10
          + (select count(*)::int from "Configuracao" where chave = 'matricularapida:701'), 111,
          'depois de matriculado: um Pagamento, uma matrícula e uma anotação');

-- ------------------------------------------------------------------ 8. fila por ordem de pagamento, sem passar da vaga
create temp table c1 as select pg_temp.rpc(pg_temp.espera('900000003', 'ESP0000003', '703', 'dddddddd-0000-4000-8000-000000000002', jsonb_build_object('pago_em', pg_temp.pago(40)))) as r;
create temp table c2 as select pg_temp.rpc(pg_temp.espera('900000004', 'ESP0000004', '704', 'dddddddd-0000-4000-8000-000000000002', jsonb_build_object('pago_em', pg_temp.pago(39)))) as r;
create temp table c3 as select pg_temp.rpc(pg_temp.espera('900000005', 'ESP0000005', '705', 'dddddddd-0000-4000-8000-000000000002', jsonb_build_object('pago_em', pg_temp.pago(38)))) as r;
-- A escola abre a turma com 3 vagas: uma já paga por outra pessoa; outra matrícula nunca paga não ocupa lugar.
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto") values
  ('fila-1', 'dddddddd-0000-4000-8000-000000000002', 3, 'ABERTA', pg_temp.dia(15));
insert into "AulaData" values ('fila-1-ad', 'fila-1', pg_temp.dia(15)::date, '09:00 - 17:00');
insert into "Usuario" (id, nome, email, "cpfCnpj", "senhaHash", papel, "atualizadoEm", "tipoDocumento") values
  ('u-fila-ocupa', 'Aluna Ocupa Vaga', 'filaocupa@exemplo.test', pg_temp.cpf('910000001'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF'),
  ('u-fila-pend', 'Aluno Nunca Pagou', 'filapend@exemplo.test', pg_temp.cpf('910000002'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF');
insert into "Matricula" (id, "alunoId", "turmaId", plano, forma, "valorCurso", "valorTaxaMatricula", "statusPagamento", "taxaConfirmada", "atualizadoEm") values
  ('m-fila-ocupa', 'u-fila-ocupa', 'fila-1', 'A_VISTA', 'PIX', 249, 99, 'PAGO', true, now()),
  ('m-fila-pend', 'u-fila-pend', 'fila-1', 'A_VISTA', 'PIX', 249, 99, 'PENDENTE', false, now());
-- A rotina chama por ordem de pagamento (pago_em): quem pagou primeiro entra primeiro.
create temp table c1b as select pg_temp.rpc(pg_temp.espera('900000003', 'ESP0000003', '703', 'dddddddd-0000-4000-8000-000000000002', jsonb_build_object('pago_em', pg_temp.pago(40)))) as r;
create temp table c2b as select pg_temp.rpc(pg_temp.espera('900000004', 'ESP0000004', '704', 'dddddddd-0000-4000-8000-000000000002', jsonb_build_object('pago_em', pg_temp.pago(39)))) as r;
create temp table c3b as select pg_temp.rpc(pg_temp.espera('900000005', 'ESP0000005', '705', 'dddddddd-0000-4000-8000-000000000002', jsonb_build_object('pago_em', pg_temp.pago(38)))) as r;
select is((select r->'turma'->>'id' from c1b), 'fila-1', 'fila: a primeira a pagar entra');
select is((select r->'turma'->>'id' from c2b), 'fila-1', 'fila: a segunda entra (a matrícula nunca paga não ocupa lugar)');
select is((select r->>'resultado' || '/' || (r->>'espera_motivo') from c3b), 'sem_turma/turmas_lotadas', 'fila: a terceira continua esperando, motivo turmas_lotadas');
select is((select count(*)::int from "Matricula" where "turmaId" = 'fila-1' and "taxaConfirmada" and "statusPagamento" not in ('CANCELADO', 'ESTORNADO')), 3,
          'fila: a turma fica com 3 de 3 (nunca acima da vaga para quem espera)');
select is((select count(*)::int from "Matricula" where "alunoId" = pg_temp.aluno('900000005')), 0, 'fila: a terceira sem matrícula');
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto") values
  ('fila-2', 'dddddddd-0000-4000-8000-000000000002', 30, 'ABERTA', pg_temp.dia(35));
insert into "AulaData" values ('fila-2-ad', 'fila-2', pg_temp.dia(35)::date, '09:00 - 17:00');
create temp table c3c as select pg_temp.rpc(pg_temp.espera('900000005', 'ESP0000005', '705', 'dddddddd-0000-4000-8000-000000000002', jsonb_build_object('pago_em', pg_temp.pago(38)))) as r;
select is((select r->'turma'->>'id' || '/' || (r->>'matricula_paga') from c3c), 'fila-2/true', 'fila: abriu outra turma, a terceira entra nela, paga');

-- ------------------------------------------------------------------ 9. a pessoa se matricula na escola durante a espera
create temp table d1 as select pg_temp.rpc(pg_temp.espera('900000010', 'ESP0000010', '710', 'dddddddd-0000-4000-8000-000000000003')) as r;
create temp table e1 as select pg_temp.rpc(pg_temp.espera('900000011', 'ESP0000011', '711', 'dddddddd-0000-4000-8000-000000000003')) as r;
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto") values
  ('pend-1', 'dddddddd-0000-4000-8000-000000000003', 30, 'ABERTA', pg_temp.dia(10));
-- D criou a matrícula no site da escola e gerou um PIX lá, sem pagar. E pagou só a taxa na escola.
insert into "Matricula" (id, "alunoId", "turmaId", plano, forma, "valorCurso", "valorTaxaMatricula", "statusPagamento", "taxaConfirmada", "atualizadoEm") values
  ('m-d-escola', pg_temp.aluno('900000010'), 'pend-1', 'A_VISTA', 'PIX', 249, 99, 'PENDENTE', false, now()),
  ('m-e-escola', pg_temp.aluno('900000011'), 'pend-1', 'PARCELADO', 'CREDITO', 259, 99, 'PENDENTE', true, now());
insert into "Pagamento" (id, "matriculaId", tipo, gateway, "gatewayRef", "gatewayHash", metodo, valor, status, "gatewayStatus", "atualizadoEm") values
  ('pg-d-pix', 'm-d-escola', 'CURSO', 'unicopag', 'ESC-D-PIX', 'ESC-D-PIX', 'PIX', 249, 'PENDENTE', 'waiting_payment', now()),
  ('pg-e-taxa', 'm-e-escola', 'TAXA', 'unicopag', 'ESC-E-TAXA', 'ESC-E-TAXA', 'PIX', 99, 'PAGO', 'paid', now());
create temp table d2 as select pg_temp.rpc(pg_temp.espera('900000010', 'ESP0000010', '710', 'dddddddd-0000-4000-8000-000000000003')) as r;
select is((select r->>'matricula_id' || '/' || (r->>'matricula_existente') from d2), 'm-d-escola/true', 'pendente na escola: o pagamento vai para a matrícula dela');
select results_eq($$ select plano::text, "statusPagamento"::text, "valorCurso", "taxaConfirmada" from "Matricula" where id = 'm-d-escola' $$,
                  $$ values ('A_VISTA', 'PAGO', 249.00::numeric(10,2), true) $$, 'pendente na escola: vira à vista PAGA');
select is((select status::text from "Pagamento" where id = 'pg-d-pix'), 'CANCELADO', 'pendente na escola: o PIX gerado lá é cancelado (não é pago de novo)');
select ok((select r->'avisos' ? 'cobranca_escola_aberta' and not (r->'avisos' ? 'turma_diferente') from d2),
          'pendente na escola: aviso cobranca_escola_aberta, sem turma_diferente');
create temp table e2 as select pg_temp.rpc(pg_temp.espera('900000011', 'ESP0000011', '711', 'dddddddd-0000-4000-8000-000000000003')) as r;
select is((select r->>'matricula_id' || '/' || (r->>'aviso') from e2), 'm-e-escola/taxa_em_dobro', 'taxa paga na escola durante a espera: taxa_em_dobro');
select results_eq($$ select m."statusPagamento"::text, m."valorCurso", p.valor from "Matricula" m
                       join "Pagamento" p on p."matriculaId" = m.id and p."gatewayHash" = 'ESP0000011' where m.id = 'm-e-escola' $$,
                  $$ values ('PAGO', 249.00::numeric(10,2), 150.00::numeric(10,2)) $$,
                  'taxa paga na escola: matrícula PAGA, Pagamento CURSO só da matrícula (o site devolve a taxa)');

-- ------------------------------------------------------------------ 10. curso pago na escola durante a espera (pagamento em dobro)
create temp table f1 as select pg_temp.rpc(pg_temp.espera('900000012', 'ESP0000012', '712', 'dddddddd-0000-4000-8000-000000000004')) as r;
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto") values
  ('dobro-1', 'dddddddd-0000-4000-8000-000000000004', 30, 'ABERTA', pg_temp.dia(12));
insert into "Matricula" (id, "alunoId", "turmaId", plano, forma, "valorCurso", "valorTaxaMatricula", "statusPagamento", "taxaConfirmada", "confirmadaPor", "atualizadoEm") values
  ('m-f-escola', pg_temp.aluno('900000012'), 'dobro-1', 'A_VISTA', 'PIX', 249, 99, 'PAGO', true, 'unicopag', now());
insert into "Pagamento" (id, "matriculaId", tipo, gateway, "gatewayRef", "gatewayHash", metodo, valor, status, "gatewayStatus", "atualizadoEm") values
  ('pg-f-curso', 'm-f-escola', 'CURSO', 'unicopag', 'ESC-F', 'ESC-F', 'PIX', 249, 'PAGO', 'paid', now());
create temp table f2 as select pg_temp.rpc(pg_temp.espera('900000012', 'ESP0000012', '712', 'dddddddd-0000-4000-8000-000000000004')) as r;
select is((select r->>'aviso' || '/' || (r->>'matricula_paga') || '/' || (r->>'matricula_id') from f2), 'curso_ja_pago/false/m-f-escola',
          'pago na escola durante a espera: curso_ja_pago (o site devolve tudo)');
select results_eq($$ select "statusPagamento"::text, "valorCurso", "confirmadaPor" from "Matricula" where id = 'm-f-escola' $$,
                  $$ values ('PAGO', 249.00::numeric(10,2), 'unicopag') $$, 'pago na escola: a matrícula da escola fica intacta');
select is((select (valor::jsonb)->>'dobro' from "Configuracao" where chave = 'matricularapida:712'), 'true', 'pago na escola: anotação com dobro');
create temp table f3 as select pg_temp.rpc(pg_temp.espera('900000012', 'ESP0000012', '712', 'dddddddd-0000-4000-8000-000000000004')) as r;
select is((select (r->>'repetido') || '/' || (r->>'aviso') from f3), 'true/curso_ja_pago', 'pago na escola: a repetição devolve o mesmo aviso');
select is((select count(*)::int from "LogAuditoria" where acao = 'PAGOU_EM_DOBRO_PELO_SITE' and detalhe->>'transacao' = 'ESP0000012'), 1,
          'pago na escola: um registro de pagamento em dobro só');

-- ------------------------------------------------------------------ 11. a escola já tinha turma (fora do oferta.json do site): entra na hora
-- (numa chamada com espera_turma e sem so_conta: a da rotina. Depois do pagamento, o site manda so_conta: suíte 13.)
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto") values
  ('imed-1', 'dddddddd-0000-4000-8000-000000000006', 30, 'ABERTA', pg_temp.dia(12));
insert into "AulaData" values ('imed-ad1', 'imed-1', pg_temp.dia(12)::date, '09:00 - 17:00');
create temp table g1 as select pg_temp.rpc(pg_temp.espera('900000014', 'ESP0000014', '714', 'dddddddd-0000-4000-8000-000000000006',
  jsonb_build_object('pago_em', pg_temp.pago(0)))) as r;
select is((select r->>'resultado' || '/' || (r->>'repetido') || '/' || (r->>'matricula_paga') from g1), 'matriculado/false/true',
          'turma já aberta na escola: matrícula paga na primeira chamada');
select is((select r->'turma'->>'id' || ' ' || (r->'turma'->>'horario') from g1), 'imed-1 09:00 - 17:00', 'turma já aberta: a turma e o horário na resposta');
select is((select count(*)::int from "LogAuditoria" where acao = 'TAXA_PAGA_SEM_TURMA_PELO_SITE' and detalhe->>'transacao' = 'ESP0000014'), 0,
          'turma já aberta: não passa pela espera');

-- ------------------------------------------------------------------ 12. o preço da escola muda durante a espera: vale o que a pessoa pagou
create temp table h1 as select pg_temp.rpc(pg_temp.espera('900000015', 'ESP0000015', '715', 'dddddddd-0000-4000-8000-000000000007')) as r;
update "Curso" set "precoAvista" = 170 where id = 'dddddddd-0000-4000-8000-000000000007';
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto") values
  ('preco-1', 'dddddddd-0000-4000-8000-000000000007', 30, 'ABERTA', pg_temp.dia(10));
insert into "AulaData" values ('preco-1-ad', 'preco-1', pg_temp.dia(10)::date, '09:00 - 17:00');
create temp table h2 as select pg_temp.rpc(pg_temp.espera('900000015', 'ESP0000015', '715', 'dddddddd-0000-4000-8000-000000000007')) as r;
select is((select m."valorCurso" from "Matricula" m join h2 on m.id = h2.r->>'matricula_id'), 249.00::numeric(10,2),
          'preço novo na escola: a matrícula fica pelo que a pessoa pagou (R$ 99 + R$ 150)');
select ok((select r->'avisos' ? 'preco_divergente' from h2), 'preço novo na escola: aviso preco_divergente (a secretaria confere)');

-- ------------------------------------------------------------------ 13. matrícula ESTORNADO da pessoa numa turma que reabre: reaproveitada
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto") values
  ('reap-1', 'dddddddd-0000-4000-8000-000000000009', 30, 'CANCELADA', pg_temp.dia(14));
insert into "AulaData" values ('reap-1-ad', 'reap-1', pg_temp.dia(14)::date, '09:00 - 17:00');
insert into "Usuario" (id, nome, email, "cpfCnpj", "senhaHash", papel, "atualizadoEm", "tipoDocumento") values
  ('u-reap', 'Aluna Estornada Antes', 'esp900000016@exemplo.test', pg_temp.cpf('900000016'), '$argon2id$v=19$m=1,t=1,p=1$eA$eQ', 'ALUNO', now(), 'CPF');
insert into "Matricula" (id, "alunoId", "turmaId", plano, forma, "valorCurso", "valorTaxaMatricula", "statusPagamento", "taxaConfirmada", "atualizadoEm") values
  ('m-reap', 'u-reap', 'reap-1', 'A_VISTA', 'PIX', 249, 99, 'ESTORNADO', true, now());
create temp table i1 as select pg_temp.rpc(pg_temp.espera('900000016', 'ESP0000016', '716', 'dddddddd-0000-4000-8000-000000000009')) as r;
update "Turma" set status = 'ABERTA' where id = 'reap-1';
create temp table i2 as select pg_temp.rpc(pg_temp.espera('900000016', 'ESP0000016', '716', 'dddddddd-0000-4000-8000-000000000009')) as r;
select is((select (i1.r->>'resultado') || '/' || (i2.r->>'matricula_id') from i1, i2), 'sem_turma/m-reap',
          'turma reaberta: esperou e depois reaproveitou a matrícula estornada (uma por aluno e turma)');
select is((select "statusPagamento"::text || '/' || "valorCurso"::text from "Matricula" where id = 'm-reap'), 'PAGO/249.00', 'turma reaberta: a matrícula volta PAGA');

-- ------------------------------------------------------------------ 14. PIX pago depois do fim das inscrições (turma vendida já começou)
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto") values
  ('atr-velha', 'dddddddd-0000-4000-8000-000000000008', 30, 'ABERTA', pg_temp.dia(-1));
create temp table j1 as select pg_temp.rpc(pg_temp.espera('900000017', 'ESP0000017', '717', 'dddddddd-0000-4000-8000-000000000008')
  - 'espera_turma' || jsonb_build_object('turma_id', 'atr-velha', 'matricula_centavos', 18000, 'total_centavos', 27900, 'pago_em', pg_temp.pago(0))) as r;
select is((select r->>'resultado' || '/' || (r->>'aviso') from j1), 'sem_turma/matricula_paga_sem_turma',
          'PIX atrasado: a turma vendida já começou e não há outra, sem_turma');
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto") values
  ('atr-nova', 'dddddddd-0000-4000-8000-000000000008', 30, 'ABERTA', pg_temp.dia(30));
insert into "AulaData" values ('atr-nova-ad', 'atr-nova', pg_temp.dia(30)::date, '09:00 - 17:00');
create temp table j2 as select pg_temp.rpc(pg_temp.espera('900000017', 'ESP0000017', '717', 'dddddddd-0000-4000-8000-000000000008',
  jsonb_build_object('matricula_centavos', 18000, 'total_centavos', 27900, 'pago_em', pg_temp.pago(0)))) as r;
select is((select r->'turma'->>'id' || '/' || (r->>'matricula_paga') from j2), 'atr-nova/true', 'PIX atrasado: a rotina do site acha a turma nova, paga');
select is((select r->'avisos' from j2), '[]'::jsonb, 'PIX atrasado: sem turma_diferente (quem espera não tem turma pedida)');

select * from finish();
rollback;
