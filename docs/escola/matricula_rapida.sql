-- Matrícula paga no site (cruzvermelhariodejaneiro.org/matricula-cursos-presenciais) entrando na
-- plataforma da escola (escola.cursoscruzvermelha.org), logo depois que a Unicopag confirma o
-- pagamento da inscrição de R$ 99.
--
-- Quem chama: só o servidor do site, com a chave secreta do Supabase (papel service_role), em
-- POST /rest/v1/rpc/matricula_rapida com o corpo {"dados": {...}}. A chave não ganha acesso a
-- nenhuma tabela: a única coisa que ela pode fazer é executar esta função.
--
-- O que a função faz, do mesmo jeito que a própria escola faz (diagnósticos de 28/09/2026):
--   1. acha o aluno pelo CPF; se não existe, cria a conta como o cadastro da escola, mas com uma
--      senha aleatória que ninguém conhece (o hash argon2id vem pronto do site) e um link único de
--      "criar senha" (TokenAuth RESET_SENHA, 72 horas), no formato dos links da própria escola:
--      o site guarda o link e grava aqui só o sha256 dele. Nada muda numa conta que já existe;
--   2. matricula na próxima turma aberta do curso com vaga (início a partir de amanhã), à vista,
--      com a taxa confirmada e o pagamento TAXA registrado: o saldo do curso na escola fica o preço
--      à vista, como o site promete. Se o aluno já tinha matrícula aberta no curso, usa essa;
--   3. sem turma aberta, só a conta: a secretaria matricula quando abrir turma (o site avisa);
--   4. registra tudo no LogAuditoria como SISTEMA.
-- Idempotente pelo hash da transação na Unicopag: a mesma confirmação chegando de novo devolve o
-- mesmo resultado, sem duplicar nada. Conflitos (e-mail de outra pessoa, CPF da equipe, aluno
-- bloqueado, curso inativo) não gravam nada e voltam com ok=false para a secretaria resolver.
--
-- Aplicar no SQL Editor do projeto da escola. Pode rodar de novo: recria a função e os privilégios.

create or replace function public.matricula_rapida(dados jsonb)
returns jsonb
language plpgsql
security definer
set search_path = ''
as $fn$
declare
  c_rotulo constant text := 'site cruzvermelhariodejaneiro.org';
  c_origem constant text := 'cruzvermelhariodejaneiro.org/matricula-cursos-presenciais';
  c_login constant text := 'https://escola.cursoscruzvermelha.org/login';
  v_agora timestamp(3) := now() at time zone 'UTC';
  v_hoje date := (now() at time zone 'America/Sao_Paulo')::date;
  v_nome text;
  v_cpf text;
  v_email text;
  v_celular text;
  v_curso_id text;
  v_transacao text;
  v_metodo text;
  v_referencia text;
  v_senha_hash text;
  v_token_hash text;
  v_link_expira timestamp(3);
  v_link_acesso boolean := false;
  v_valor numeric(10,2);
  v_total_centavos integer;
  v_pago_em timestamp(3);
  v_soma integer;
  v_dv integer;
  v_curso record;
  v_aluno_id text;
  v_aluno_email text;
  v_aluno_papel text;
  v_aluno_bloqueado boolean;
  v_aluno_celular text;
  v_aluno_novo boolean := false;
  v_matricula_id text;
  v_matricula_existente boolean := false;
  v_taxa_ja_confirmada boolean;
  v_turma_id text;
  v_turma_inicio timestamp(3);
  v_resultado text;
  v_aviso text;
  v_repetido boolean := false;
begin
  if dados is null or jsonb_typeof(dados) <> 'object' then
    raise exception using errcode = '22023', message = 'dados inválidos: esperado um objeto JSON';
  end if;

  -- Entrada, normalizada como a escola grava: CPF e celular só com dígitos, e-mail em minúsculas.
  v_nome := btrim(regexp_replace(coalesce(dados->>'nome', ''), '\s+', ' ', 'g'));
  v_cpf := regexp_replace(coalesce(dados->>'cpf', ''), '\D', '', 'g');
  v_email := lower(btrim(coalesce(dados->>'email', '')));
  v_celular := nullif(regexp_replace(coalesce(dados->>'celular', ''), '\D', '', 'g'), '');
  v_curso_id := lower(btrim(coalesce(dados->>'curso_id', '')));
  v_transacao := btrim(coalesce(dados->>'transacao', ''));
  v_metodo := lower(btrim(coalesce(dados->>'metodo', '')));
  v_referencia := nullif(btrim(coalesce(dados->>'referencia', '')), '');
  v_senha_hash := nullif(btrim(coalesce(dados->>'senha_hash', '')), '');
  v_token_hash := nullif(lower(btrim(coalesce(dados->>'token_hash', ''))), '');

  if char_length(v_nome) < 5 or char_length(v_nome) > 160 or position(' ' in v_nome) = 0 then
    raise exception using errcode = '22023', message = 'dados inválidos: nome';
  end if;
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
  if char_length(v_email) > 190 or v_email !~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$' then
    raise exception using errcode = '22023', message = 'dados inválidos: email';
  end if;
  -- Celular fora do padrão da escola (DDD + número, 10 ou 11 dígitos) não impede a matrícula.
  if v_celular is not null and (v_celular !~ '^[1-9][0-9]{9,10}$') then
    v_celular := null;
  end if;
  if v_curso_id !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' then
    raise exception using errcode = '22023', message = 'dados inválidos: curso_id';
  end if;
  if v_transacao !~ '^[A-Za-z0-9_-]{6,64}$' then
    raise exception using errcode = '22023', message = 'dados inválidos: transacao';
  end if;
  if v_metodo not in ('pix', 'cartao') then
    raise exception using errcode = '22023', message = 'dados inválidos: metodo';
  end if;
  if coalesce(dados->>'valor_centavos', '') !~ '^[0-9]{1,6}$' or (dados->>'valor_centavos')::integer not between 1 and 100000 then
    raise exception using errcode = '22023', message = 'dados inválidos: valor_centavos';
  end if;
  v_valor := round((dados->>'valor_centavos')::integer / 100.0, 2);
  if dados ? 'total_centavos' and dados->>'total_centavos' is not null then
    if dados->>'total_centavos' !~ '^[0-9]{1,6}$' or (dados->>'total_centavos')::integer < (dados->>'valor_centavos')::integer then
      raise exception using errcode = '22023', message = 'dados inválidos: total_centavos';
    end if;
    v_total_centavos := (dados->>'total_centavos')::integer;
  end if;
  begin
    v_pago_em := least(coalesce((dados->>'pago_em')::timestamptz at time zone 'UTC', v_agora), v_agora);
  exception when others then
    raise exception using errcode = '22023', message = 'dados inválidos: pago_em';
  end;
  if v_referencia is not null and char_length(v_referencia) > 40 then
    raise exception using errcode = '22023', message = 'dados inválidos: referencia';
  end if;
  if v_senha_hash is not null and (char_length(v_senha_hash) > 200
      or v_senha_hash !~ '^\$argon2id\$v=19\$m=[0-9]+,t=[0-9]+,p=[0-9]+\$[A-Za-z0-9+/]+\$[A-Za-z0-9+/]+$') then
    raise exception using errcode = '22023', message = 'dados inválidos: senha_hash';
  end if;
  if v_token_hash is not null and v_token_hash !~ '^[0-9a-f]{64}$' then
    raise exception using errcode = '22023', message = 'dados inválidos: token_hash';
  end if;

  -- Uma chamada por vez para a mesma transação, o mesmo CPF e o mesmo e-mail (sempre nesta ordem).
  perform pg_advisory_xact_lock(hashtextextended('matricula_rapida:transacao:' || v_transacao, 0));
  perform pg_advisory_xact_lock(hashtextextended('matricula_rapida:cpf:' || v_cpf, 0));
  perform pg_advisory_xact_lock(hashtextextended('matricula_rapida:email:' || v_email, 0));

  -- Já processada? Devolve o que foi feito da primeira vez.
  select m.id, m."turmaId", t."inicioPrevisto", u.id, u.email
    into v_matricula_id, v_turma_id, v_turma_inicio, v_aluno_id, v_aluno_email
    from public."Pagamento" p
    join public."Matricula" m on m.id = p."matriculaId"
    join public."Turma" t on t.id = m."turmaId"
    join public."Usuario" u on u.id = m."alunoId"
   where p.gateway = 'site' and p."gatewayHash" = v_transacao
   order by p."criadoEm"
   limit 1;
  if found then
    v_repetido := true;
    v_resultado := 'matriculado';
    select l.acao = 'CONFIRMOU_TAXA_PELO_SITE', coalesce((l.detalhe->>'alunoNovo')::boolean, false), l.detalhe->>'aviso'
      into v_matricula_existente, v_aluno_novo, v_aviso
      from public."LogAuditoria" l
     where l.acao in ('MATRICULOU_PELO_SITE', 'CONFIRMOU_TAXA_PELO_SITE') and l."alvoId" = v_matricula_id
       and l.detalhe->>'transacao' = v_transacao
     limit 1;
    v_matricula_existente := coalesce(v_matricula_existente, false);
    v_aluno_novo := coalesce(v_aluno_novo, false);
  else
    select l."alvoId", coalesce((l.detalhe->>'alunoNovo')::boolean, false)
      into v_aluno_id, v_aluno_novo
      from public."LogAuditoria" l
     where l.acao = 'TAXA_PAGA_SEM_TURMA_PELO_SITE' and l.detalhe->>'transacao' = v_transacao
     limit 1;
    if found then
      v_repetido := true;
      v_resultado := 'sem_turma';
      select u.email into v_aluno_email from public."Usuario" u where u.id = v_aluno_id;
    end if;
  end if;

  if not v_repetido then
    v_aluno_novo := false; -- os SELECT INTO acima, sem linha, deixaram nulo
    select c.id, c.nome, c."precoAvista", c.ativo into v_curso from public."Curso" c where c.id = v_curso_id;
    if not found then
      return jsonb_build_object('ok', false, 'erro', 'curso_inexistente', 'mensagem', 'Curso não encontrado na escola.');
    end if;
    if not v_curso.ativo then
      return jsonb_build_object('ok', false, 'erro', 'curso_inativo', 'mensagem', 'O curso está inativo na escola.');
    end if;

    -- Aluno: pelo CPF. Conta existente não é alterada (só ganha o celular, se não tinha).
    select u.id, u.email, u.papel::text, u."bloqueioTotal", u.celular
      into v_aluno_id, v_aluno_email, v_aluno_papel, v_aluno_bloqueado, v_aluno_celular
      from public."Usuario" u where u."cpfCnpj" = v_cpf;
    if found then
      if v_aluno_papel <> 'ALUNO' then
        return jsonb_build_object('ok', false, 'erro', 'documento_da_equipe', 'mensagem', 'O CPF pertence a uma conta da equipe da escola.');
      end if;
      if v_aluno_bloqueado then
        return jsonb_build_object('ok', false, 'erro', 'aluno_bloqueado', 'mensagem', 'A conta do aluno está bloqueada na escola.');
      end if;
      if v_aluno_celular is null and v_celular is not null then
        update public."Usuario" set celular = v_celular, "atualizadoEm" = v_agora where id = v_aluno_id;
      end if;
    else
      if exists (select 1 from public."Usuario" u where u.email = v_email) then
        return jsonb_build_object('ok', false, 'erro', 'email_em_uso', 'mensagem', 'O e-mail já está cadastrado na escola com outro CPF.');
      end if;
      if v_senha_hash is null or v_token_hash is null then
        raise exception using errcode = '22023', message = 'dados inválidos: senha_hash e token_hash (obrigatórios para conta nova)';
      end if;
      v_aluno_id := gen_random_uuid()::text;
      v_aluno_email := v_email;
      insert into public."Usuario" (id, nome, email, "cpfCnpj", "tipoDocumento", "senhaHash", papel, celular, "criadoEm", "atualizadoEm")
      values (v_aluno_id, v_nome, v_email, v_cpf, 'CPF', v_senha_hash, 'ALUNO', v_celular, v_agora, v_agora);
      v_aluno_novo := true;
      -- Link único para o aluno criar a senha: o mesmo mecanismo do "Esqueci minha senha" da escola.
      insert into public."TokenAuth" (id, "usuarioId", tipo, "tokenHash", "expiraEm", "criadoEm")
      values (gen_random_uuid()::text, v_aluno_id, 'RESET_SENHA', v_token_hash, v_agora + interval '72 hours', v_agora);
      insert into public."LogAuditoria" (id, "atorId", acao, "alvoTipo", "alvoId", detalhe)
      values (gen_random_uuid()::text, 'SISTEMA', 'CADASTROU_ALUNO_PELO_SITE', 'Usuario', v_aluno_id,
              jsonb_build_object('origem', c_origem, 'transacao', v_transacao, 'referencia', v_referencia));
    end if;

    -- Matrícula aberta no mesmo curso (feita na própria escola, por exemplo): a taxa vai para ela.
    select m.id, m."taxaConfirmada", m."turmaId", t."inicioPrevisto"
      into v_matricula_id, v_taxa_ja_confirmada, v_turma_id, v_turma_inicio
      from public."Matricula" m
      join public."Turma" t on t.id = m."turmaId"
     where m."alunoId" = v_aluno_id and t."cursoId" = v_curso.id
       and t.status in ('ABERTA', 'CONFIRMADA')
       and m."statusPagamento" not in ('CANCELADO', 'ESTORNADO')
     order by t."inicioPrevisto" desc
     limit 1
     for update of m;
    if found then
      v_matricula_existente := true;
      if v_taxa_ja_confirmada then
        v_aviso := 'taxa_ja_confirmada';
      else
        update public."Matricula"
           set "taxaConfirmada" = true, "taxaConfirmadaEm" = v_pago_em, "taxaConfirmadaPor" = c_rotulo,
               "valorCurso" = "valorCurso" - "valorTaxaMatricula" + v_valor, "valorTaxaMatricula" = v_valor,
               "atualizadoEm" = v_agora
         where id = v_matricula_id;
      end if;
    else
      -- Próxima turma aberta com vaga, começando a partir de amanhã (horário de Brasília).
      select t.id, t."inicioPrevisto" into v_turma_id, v_turma_inicio
        from public."Turma" t
       where t."cursoId" = v_curso.id
         and t.status in ('ABERTA', 'CONFIRMADA')
         and t."inicioPrevisto"::date > v_hoje
         and (select count(*) from public."Matricula" m
               where m."turmaId" = t.id and m."statusPagamento" not in ('CANCELADO', 'ESTORNADO')) < t.vagas
         and not exists (select 1 from public."Matricula" m where m."turmaId" = t.id and m."alunoId" = v_aluno_id)
       order by t."inicioPrevisto", t."criadoEm"
       limit 1
       for update of t;
      if not found then
        insert into public."LogAuditoria" (id, "atorId", acao, "alvoTipo", "alvoId", detalhe)
        values (gen_random_uuid()::text, 'SISTEMA', 'TAXA_PAGA_SEM_TURMA_PELO_SITE', 'Usuario', v_aluno_id,
                jsonb_build_object('origem', c_origem, 'transacao', v_transacao, 'referencia', v_referencia,
                                   'cursoId', v_curso.id, 'curso', v_curso.nome, 'valorTaxa', v_valor,
                                   'metodo', v_metodo, 'alunoNovo', v_aluno_novo));
        v_resultado := 'sem_turma';
      else
        v_matricula_id := gen_random_uuid()::text;
        insert into public."Matricula" (id, "alunoId", "turmaId", plano, forma, "valorCurso", "valorTaxaMatricula",
                                        "statusPagamento", "taxaConfirmada", "taxaConfirmadaEm", "taxaConfirmadaPor",
                                        "criadoEm", "atualizadoEm")
        values (v_matricula_id, v_aluno_id, v_turma_id, 'A_VISTA',
                (case when v_metodo = 'pix' then 'PIX' else 'CREDITO' end)::public."FormaPagamento",
                v_curso."precoAvista" + v_valor, v_valor, 'PENDENTE', true, v_pago_em, c_rotulo, v_agora, v_agora);
      end if;
    end if;

    if v_resultado is null then
      v_resultado := 'matriculado';
      insert into public."Pagamento" (id, "matriculaId", gateway, "gatewayRef", metodo, valor, status, "gatewayHash",
                                      "gatewayStatus", "gatewayResponse", tipo, "criadoEm", "atualizadoEm")
      values (gen_random_uuid()::text, v_matricula_id, 'site', 'site-' || coalesce(v_referencia, v_transacao),
              (case when v_metodo = 'pix' then 'PIX' else 'CREDITO' end)::public."FormaPagamento",
              v_valor, 'PAGO', v_transacao, 'paid',
              jsonb_build_object('origem', c_origem, 'processador', 'unicopag', 'hash', v_transacao, 'metodo', v_metodo,
                                 'valor_inscricao_centavos', (v_valor * 100)::integer, 'total_cobrado_centavos', v_total_centavos,
                                 'pago_em', to_char(v_pago_em, 'YYYY-MM-DD"T"HH24:MI:SS"Z"'), 'referencia', v_referencia),
              'TAXA', v_pago_em, v_agora);
      insert into public."LogAuditoria" (id, "atorId", acao, "alvoTipo", "alvoId", detalhe)
      values (gen_random_uuid()::text, 'SISTEMA',
              case when v_matricula_existente then 'CONFIRMOU_TAXA_PELO_SITE' else 'MATRICULOU_PELO_SITE' end,
              'Matricula', v_matricula_id,
              jsonb_build_object('origem', c_origem, 'transacao', v_transacao, 'referencia', v_referencia,
                                 'turmaId', v_turma_id, 'cursoId', v_curso.id, 'valorTaxa', v_valor, 'metodo', v_metodo,
                                 'alunoNovo', v_aluno_novo, 'aviso', v_aviso));
    end if;
  end if;

  -- O link de criar senha ainda vale? (conta criada por esta transação, link não usado nem vencido)
  if v_token_hash is not null and v_aluno_id is not null then
    select k."expiraEm" into v_link_expira
      from public."TokenAuth" k
     where k."usuarioId" = v_aluno_id and k.tipo = 'RESET_SENHA' and k."tokenHash" = v_token_hash
       and k."usadoEm" is null and k."expiraEm" > v_agora
     limit 1;
    v_link_acesso := found;
  end if;

  return jsonb_build_object(
    'ok', true,
    'resultado', v_resultado,
    'repetido', v_repetido,
    'aluno_novo', v_aluno_novo,
    'email_conta', left(split_part(v_aluno_email, '@', 1), 1) || '***@' || split_part(v_aluno_email, '@', 2),
    'email_confere', v_aluno_email = v_email,
    'matricula_id', v_matricula_id,
    'matricula_existente', v_matricula_existente,
    'turma', case when v_turma_id is null or v_resultado = 'sem_turma' then null
                  else jsonb_build_object('id', v_turma_id, 'inicio', to_char(v_turma_inicio, 'YYYY-MM-DD')) end,
    'aviso', v_aviso,
    'link_acesso', v_link_acesso,
    'link_expira_em', case when v_link_acesso then to_char(v_link_expira, 'YYYY-MM-DD"T"HH24:MI:SS"Z"') end,
    'url_login', c_login);
end;
$fn$;

comment on function public.matricula_rapida(jsonb) is
  'Matrícula paga no site cruzvermelhariodejaneiro.org: cria ou acha o aluno, matricula na próxima turma aberta com a taxa confirmada e registra o pagamento. Só o service_role (servidor do site) executa.';

-- Só o servidor do site (chave secreta = service_role) executa. Nenhuma tabela é liberada.
revoke all on function public.matricula_rapida(jsonb) from public;
revoke all on function public.matricula_rapida(jsonb) from anon, authenticated;
grant execute on function public.matricula_rapida(jsonb) to service_role;
grant usage on schema public to service_role;

-- A API (PostgREST) relê o schema para enxergar a função nova.
notify pgrst, 'reload schema';
