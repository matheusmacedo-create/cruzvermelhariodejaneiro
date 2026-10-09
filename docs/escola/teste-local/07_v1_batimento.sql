\set QUIET 1
begin;
create function pg_temp.cpf(base text) returns text language plpgsql as $$
declare s int; d1 int; d2 int;
begin
  s := 0; for i in 1..9 loop s := s + substr(base, i, 1)::int * (11 - i); end loop; d1 := (s * 10) % 11 % 10;
  s := 0; for i in 1..9 loop s := s + substr(base, i, 1)::int * (12 - i); end loop; s := s + d1 * 2; d2 := (s * 10) % 11 % 10;
  return base || d1 || d2;
end $$;
insert into "Curso" (id, nome, "cargaHoraria", "precoAvista", "precoCheio", parcelas, "valorParcela", ativo)
  values ('bbbbbbbb-0000-4000-8000-000000000001', 'Curso Pagar Tudo', 8, 180, 200, 2, 100, true);
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto")
  values ('pt-turma-a', 'bbbbbbbb-0000-4000-8000-000000000001', 30, 'ABERTA', ((now() at time zone 'America/Sao_Paulo')::date + 7)::timestamp + time '12:00');
create temp table p as select jsonb_build_object('nome', 'Pessoa De Teste', 'cpf', pg_temp.cpf('700000013'), 'email', 'v1@exemplo.test',
  'curso_id', 'bbbbbbbb-0000-4000-8000-000000000001', 'transacao', 'TAXA000013', 'metodo', 'pix', 'valor_centavos', 9900, 'total_centavos', 10395,
  'referencia', '513', 'senha_hash', '$argon2id$v=19$m=65536,t=4,p=1$c2FsdHNhbHRzYWx0$aGFzaGhhc2hoYXNoaGFzaGhhc2hoYXNoaGFzaA',
  'token_hash', repeat('a', 64)) as d;
select public.matricula_rapida(d)->>'repetido' as primeira from p;
update "Pagamento" set gateway = 'unicopag-2', "gatewayRef" = 'TAXA000013' where "gatewayHash" = 'TAXA000013';
select public.matricula_rapida(d)->>'repetido' as segunda, public.matricula_rapida(d)->>'aviso' as aviso from p;
select count(*) as pagamentos_com_o_hash from "Pagamento" where "gatewayHash" = 'TAXA000013';
rollback;
