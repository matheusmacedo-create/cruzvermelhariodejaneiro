-- Aulas de um aluno num dia, para o ponto da sede do site (cruzvermelhariodejaneiro.org): quando o
-- aluno registra a chegada na sede, o site pergunta à escola quais aulas ele tem naquele dia e guarda
-- a presença; quando a aula termina, o site libera o comprovante de comparecimento.
--
-- Quem chama: só o servidor do site, com a chave secreta do Supabase (papel service_role), em
-- POST /rest/v1/rpc/aulas_do_aluno com o corpo {"dados": {"cpf": "...", "data": "AAAA-MM-DD"}}.
-- A função só lê. A chave continua sem acesso a nenhuma tabela.
--
-- Devolve {"ok": true, "aluno": {"nome", "email"} ou null, "aulas": [...]}, com uma aula por linha
-- de "AulaData" da data pedida. Entram só as matrículas que não foram canceladas nem estornadas, em
-- turmas que não foram canceladas. Nome e e-mail só vêm quando o aluno tem aula nesse dia: a função
-- não serve para descobrir o nome de um CPF qualquer.
--
-- Aplicar no SQL Editor do projeto da escola (wrckokgdtiwvxapqzkki), o arquivo inteiro. Pode rodar de
-- novo: recria a função e os privilégios. Em outro projeto, o script para no começo e não cria nada. No
-- fim, avisa a API do Supabase para reler as funções e mostra a função criada (uma linha).
-- Para desfazer: drop function public.aulas_do_aluno(jsonb);

-- Trava: só segue no projeto da escola. Sem a tabela "AulaData", o script para aqui e nada é criado.
do $trava$
begin
  if to_regclass('public."AulaData"') is null then
    raise exception using
      message = 'Este não é o projeto da escola: falta a tabela "AulaData". Nada foi criado.',
      hint = 'Abra o projeto wrckokgdtiwvxapqzkki no Supabase e rode o script de novo.';
  end if;
end
$trava$;

create or replace function public.aulas_do_aluno(dados jsonb)
returns jsonb
language plpgsql
stable
security definer
set search_path = ''
as $fn$
declare
  v_cpf text;
  v_data date;
  v_soma integer;
  v_dv integer;
  v_aulas jsonb;
  v_aluno jsonb;
begin
  if jsonb_typeof(dados) is distinct from 'object' then
    raise exception using errcode = '22023', message = 'dados inválidos: esperado um objeto JSON';
  end if;

  v_cpf := coalesce(dados->>'cpf', '');
  if v_cpf !~ '^[0-9]{11}$' or v_cpf = repeat(left(v_cpf, 1), 11) then
    raise exception using errcode = '22023', message = 'dados inválidos: cpf';
  end if;
  v_soma := 0;
  for i in 1..9 loop
    v_soma := v_soma + substr(v_cpf, i, 1)::integer * (11 - i);
  end loop;
  v_dv := (v_soma * 10) % 11 % 10;
  if v_dv <> substr(v_cpf, 10, 1)::integer then
    raise exception using errcode = '22023', message = 'dados inválidos: cpf';
  end if;
  v_soma := 0;
  for i in 1..10 loop
    v_soma := v_soma + substr(v_cpf, i, 1)::integer * (12 - i);
  end loop;
  v_dv := (v_soma * 10) % 11 % 10;
  if v_dv <> substr(v_cpf, 11, 1)::integer then
    raise exception using errcode = '22023', message = 'dados inválidos: cpf';
  end if;

  if coalesce(dados->>'data', '') !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' then
    raise exception using errcode = '22023', message = 'dados inválidos: data';
  end if;
  begin
    v_data := (dados->>'data')::date;
  exception when others then
    raise exception using errcode = '22023', message = 'dados inválidos: data';
  end;

  select jsonb_agg(jsonb_build_object(
           'aula_id', a.id,
           'data', to_char(a.data, 'YYYY-MM-DD'),
           'horario', a.horario,
           'turma_id', t.id,
           'curso_id', c.id,
           'curso_nome', c.nome,
           'carga_horaria', c."cargaHoraria")
         order by a.horario, c.nome, a.id)
    into v_aulas
    from public."Usuario" u
    join public."Matricula" m on m."alunoId" = u.id
    join public."Turma" t on t.id = m."turmaId"
    join public."Curso" c on c.id = t."cursoId"
    join public."AulaData" a on a."turmaId" = t.id
   where u."cpfCnpj" = v_cpf
     and a.data = v_data
     and m."statusPagamento" not in ('CANCELADO', 'ESTORNADO')
     and t.status <> 'CANCELADA';

  if v_aulas is null then
    return jsonb_build_object('ok', true, 'aluno', null, 'aulas', '[]'::jsonb);
  end if;
  select jsonb_build_object('nome', u.nome, 'email', u.email)
    into v_aluno
    from public."Usuario" u
   where u."cpfCnpj" = v_cpf;
  return jsonb_build_object('ok', true, 'aluno', v_aluno, 'aulas', v_aulas);
end
$fn$;

comment on function public.aulas_do_aluno(jsonb) is
  'Ponto da sede do site cruzvermelhariodejaneiro.org: aulas (AulaData) de um aluno num dia, pelo CPF, para registrar a presença e emitir o comprovante de comparecimento. Só lê. Só o service_role (servidor do site) executa.';

-- Só o servidor do site (chave secreta = service_role) executa. Nenhuma tabela é liberada.
revoke all on function public.aulas_do_aluno(jsonb) from public;
revoke all on function public.aulas_do_aluno(jsonb) from anon, authenticated;
grant execute on function public.aulas_do_aluno(jsonb) to service_role;

-- Avisa a API do Supabase (PostgREST) para reler as funções. No Supabase isso já acontece sozinho quando o
-- script é confirmado; o aviso fica como garantia. Se a API seguir em PGRST202, o script não foi confirmado.
notify pgrst, 'reload schema';

-- Conferência: o resultado deve ser uma linha, aulas_do_aluno | dados jsonb | true | true | false.
select p.proname as funcao,
       pg_get_function_identity_arguments(p.oid) as argumentos,
       p.prosecdef as security_definer,
       has_function_privilege('service_role', p.oid, 'execute') as service_role_executa,
       has_function_privilege('anon', p.oid, 'execute') as anon_executa
  from pg_proc p
  join pg_namespace n on n.oid = p.pronamespace
 where n.nspname = 'public' and p.proname = 'aulas_do_aluno';
