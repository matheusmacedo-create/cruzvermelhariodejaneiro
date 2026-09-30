-- Alunos com aula num dia, para o lembrete da véspera do site (cruzvermelhariodejaneiro.org): às 8h, o
-- site pergunta quem tem aula amanhã e, às 18h, manda a cada aluno "amanhã tem aula, confirme a presença
-- no ponto da recepção" (lib/avisos.php, lembrete "Aula de amanhã", que a secretaria liga no portal).
--
-- Quem chama: só o servidor do site, com a chave secreta do Supabase (papel service_role), em
-- POST /rest/v1/rpc/aulas_do_dia com o corpo {"dados": {"data": "AAAA-MM-DD"}}.
-- A função só lê. A chave continua sem acesso a nenhuma tabela.
--
-- Devolve {"ok": true, "data": "AAAA-MM-DD", "alunos": [...]}, um item por aluno, em ordem de nome:
--   {"aluno_id", "nome", "email", "celular", "aulas": [{"aula_id", "horario", "turma_id", "curso_id", "curso_nome"}]}.
-- Entram as mesmas matrículas do ponto (aulas_do_aluno.sql): não canceladas nem estornadas, em turmas
-- não canceladas, com aula ("AulaData") no dia. Só contas de aluno (papel ALUNO) e sem bloqueio total.
-- Não devolve CPF nem nada além do que o lembrete precisa.
--
-- Para limitar o estrago se a chave vazar, a data precisa estar entre ontem e daqui a 7 dias (horário
-- de Brasília): não dá para baixar de uma vez a lista de todos os alunos de todas as turmas.
--
-- Aplicar no SQL Editor do projeto da escola. Pode rodar de novo: recria a função e os privilégios.
-- Para desfazer: drop function public.aulas_do_dia(jsonb);

create or replace function public.aulas_do_dia(dados jsonb)
returns jsonb
language plpgsql
stable
security definer
set search_path = ''
as $fn$
declare
  v_data date;
  v_hoje date := (now() at time zone 'America/Sao_Paulo')::date;
  v_alunos jsonb;
begin
  if jsonb_typeof(dados) is distinct from 'object' then
    raise exception using errcode = '22023', message = 'dados inválidos: esperado um objeto JSON';
  end if;

  if coalesce(dados->>'data', '') !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' then
    raise exception using errcode = '22023', message = 'dados inválidos: data';
  end if;
  begin
    v_data := (dados->>'data')::date;
  exception when others then
    raise exception using errcode = '22023', message = 'dados inválidos: data';
  end;
  if v_data < v_hoje - 1 or v_data > v_hoje + 7 then
    raise exception using errcode = '22023', message = 'dados inválidos: data fora da janela (de ontem a daqui a 7 dias)';
  end if;

  select coalesce(jsonb_agg(x.aluno order by x.nome, x.aluno_id), '[]'::jsonb)
    into v_alunos
    from (
      select u.id as aluno_id,
             u.nome,
             jsonb_build_object(
               'aluno_id', u.id,
               'nome', u.nome,
               'email', u.email,
               'celular', u.celular,
               'aulas', jsonb_agg(jsonb_build_object(
                          'aula_id', a.id,
                          'horario', a.horario,
                          'turma_id', t.id,
                          'curso_id', c.id,
                          'curso_nome', c.nome)
                        order by a.horario, c.nome, a.id)) as aluno
        from public."Usuario" u
        join public."Matricula" m on m."alunoId" = u.id
        join public."Turma" t on t.id = m."turmaId"
        join public."Curso" c on c.id = t."cursoId"
        join public."AulaData" a on a."turmaId" = t.id
       where a.data = v_data
         and m."statusPagamento" not in ('CANCELADO', 'ESTORNADO')
         and t.status <> 'CANCELADA'
         and u.papel = 'ALUNO'
         and not u."bloqueioTotal"
       group by u.id, u.nome, u.email, u.celular
    ) x;

  return jsonb_build_object('ok', true, 'data', to_char(v_data, 'YYYY-MM-DD'), 'alunos', v_alunos);
end
$fn$;

comment on function public.aulas_do_dia(jsonb) is
  'Lembrete da véspera do site cruzvermelhariodejaneiro.org: alunos (nome, e-mail, celular) com aula (AulaData) num dia, de ontem a daqui a 7 dias. Só lê. Só o service_role (servidor do site) executa.';

-- Só o servidor do site (chave secreta = service_role) executa. Nenhuma tabela é liberada.
revoke all on function public.aulas_do_dia(jsonb) from public;
revoke all on function public.aulas_do_dia(jsonb) from anon, authenticated;
grant execute on function public.aulas_do_dia(jsonb) to service_role;
