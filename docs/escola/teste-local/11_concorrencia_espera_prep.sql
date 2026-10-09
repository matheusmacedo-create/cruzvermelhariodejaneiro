-- Concorrência na espera: duas pessoas pagaram tudo sem turma; a escola abre uma turma com UMA vaga, e a rotina do
-- site chama as duas ao mesmo tempo (11_chamada_espera.sql, em paralelo). Esperado: uma entra, a outra continua
-- esperando, e a turma fica com 1 de 1. Banco descartável (fica gravado: rodar por último). Dados fictícios.
create or replace function public.cpf_teste(base text) returns text language plpgsql as $$
declare s int; d1 int; d2 int;
begin
  s := 0; for i in 1..9 loop s := s + substr(base, i, 1)::int * (11 - i); end loop; d1 := (s * 10) % 11 % 10;
  s := 0; for i in 1..9 loop s := s + substr(base, i, 1)::int * (12 - i); end loop; s := s + d1 * 2; d2 := (s * 10) % 11 % 10;
  return base || d1 || d2;
end $$;
create or replace function public.espera_teste(n int) returns jsonb language sql as $$
  select jsonb_build_object('nome', 'Pessoa Concorre Espera ' || n, 'cpf', public.cpf_teste('92000000' || n), 'email', 'concesp' || n || '@exemplo.test',
    'curso_id', 'eeeeeeee-0000-4000-8000-000000000001', 'transacao', 'CESP00000' || n, 'metodo', 'pix', 'valor_centavos', 9900,
    'matricula_centavos', 15000, 'parcelas', 1, 'total_centavos', 24900, 'referencia', '95' || n, 'espera_turma', true,
    'inicio_ate', to_char((now() at time zone 'America/Sao_Paulo')::date + 120, 'YYYY-MM-DD'),
    'pago_em', to_char((now() at time zone 'UTC') - interval '20 days', 'YYYY-MM-DD"T"HH24:MI:SS"Z"'),
    'senha_hash', '$argon2id$v=19$m=65536,t=4,p=1$c2FsdHNhbHRzYWx0$aGFzaGhhc2hoYXNoaGFzaGhhc2hoYXNoaGFzaA', 'token_hash', repeat(n::text, 64)) $$;
insert into "Curso" (id, nome, "cargaHoraria", "precoAvista", "precoCheio", parcelas, "valorParcela", ativo)
  values ('eeeeeeee-0000-4000-8000-000000000001', 'Curso Concorrencia Espera', 8, 150, 150, 1, 150, true) on conflict do nothing;
set role service_role;
select 'primeira chamada ' || n || ': ' || (public.matricula_rapida(public.espera_teste(n))->>'resultado') from generate_series(1, 2) n;
reset role;
-- Criada há 1 dia (carência de 6 h) e com a aula cadastrada (revisão da venda sem turma).
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto", "criadoEm")
  values ('ce-turma', 'eeeeeeee-0000-4000-8000-000000000001', 1, 'ABERTA', ((now() at time zone 'America/Sao_Paulo')::date + 10)::timestamp + time '12:00',
          now() - interval '1 day')
  on conflict do nothing;
insert into "AulaData" values ('ce-turma-ad', 'ce-turma', (now() at time zone 'America/Sao_Paulo')::date + 10, '09:00 - 17:00') on conflict do nothing;
