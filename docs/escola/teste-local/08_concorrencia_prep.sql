create or replace function public.cpf_teste(base text) returns text language plpgsql as $$
declare s int; d1 int; d2 int;
begin
  s := 0; for i in 1..9 loop s := s + substr(base, i, 1)::int * (11 - i); end loop; d1 := (s * 10) % 11 % 10;
  s := 0; for i in 1..9 loop s := s + substr(base, i, 1)::int * (12 - i); end loop; s := s + d1 * 2; d2 := (s * 10) % 11 % 10;
  return base || d1 || d2;
end $$;
insert into "Curso" (id, nome, "cargaHoraria", "precoAvista", "precoCheio", parcelas, "valorParcela", ativo)
  values ('cccccccc-0000-4000-8000-000000000001', 'Curso Concorrencia', 8, 180, 180, 1, 180, true) on conflict do nothing;
insert into "Turma" (id, "cursoId", vagas, status, "inicioPrevisto")
  values ('cc-turma', 'cccccccc-0000-4000-8000-000000000001', 30, 'ABERTA', ((now() at time zone 'America/Sao_Paulo')::date + 7)::timestamp + time '12:00') on conflict do nothing;
