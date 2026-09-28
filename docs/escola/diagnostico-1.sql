-- Diagnóstico do banco da escola para ligar a matrícula do site (R$ 99) à plataforma.
-- SÓ LEITURA: é um único SELECT, não altera nada. Devolve contagens e formatos;
-- não mostra nomes, CPFs, e-mails, telefones nem valores de configuração
-- (onde haveria dígitos aparece 9, só para ver o formato).
with
u as (select id, papel::text as papel from "Usuario"),
linhas(ordem, secao, item, valor) as (
  select 1, 'ambiente', 'gerado em', to_char(now() at time zone 'UTC', 'YYYY-MM-DD HH24:MI') || ' UTC'
  union all select 10, 'ambiente', 'postgres', split_part(version(), ' on ', 1)
  union all select 11, 'ambiente', 'fuso horário', current_setting('TimeZone')
  union all select 12, 'ambiente', 'extensões', (select string_agg(e.extname || ' ' || e.extversion || ' (' || n.nspname || ')', ', ' order by e.extname) from pg_extension e join pg_namespace n on n.oid = e.extnamespace)
  union all select 13, 'ambiente', 'dono das tabelas', (select string_agg(distinct pg_get_userbyid(c.relowner), ', ') from pg_class c where c.relnamespace = 'public'::regnamespace and c.relkind = 'r')
  union all select 14, 'ambiente', 'RLS por tabela', (select string_agg(c.relname || case when c.relrowsecurity then ' ligada' else ' desligada' end || case when c.relforcerowsecurity then ' (forçada)' else '' end, ', ' order by c.relname) from pg_class c where c.relnamespace = 'public'::regnamespace and c.relkind = 'r')
  union all select 15, 'ambiente', 'acesso da API ao schema public', (select string_agg(r.rolname || ': usage ' || has_schema_privilege(r.oid, 'public'::regnamespace, 'USAGE') || ', tabelas com algum privilégio ' || (select count(*) from pg_class c where c.relnamespace = 'public'::regnamespace and c.relkind = 'r' and has_table_privilege(r.oid, c.oid, 'SELECT,INSERT,UPDATE,DELETE')), '; ' order by r.rolname) from pg_roles r where r.rolname in ('anon', 'authenticated', 'service_role'))
  union all select 16, 'ambiente', 'schemas expostos na API', (select string_agg(c, ' ; ') from pg_roles r, unnest(r.rolconfig) c where r.rolname = 'authenticator' and c like 'pgrst.db%')
  union all select 17, 'ambiente', 'funções no public', (select string_agg(p.proname || '(' || pg_get_function_identity_arguments(p.oid) || ')', ', ' order by p.proname) from pg_proc p where p.pronamespace = 'public'::regnamespace)
  union all select 18, 'ambiente', 'gatilhos no public', (select string_agg(t.tgrelid::regclass::text || ': ' || t.tgname, ', ') from pg_trigger t join pg_class c on c.oid = t.tgrelid where c.relnamespace = 'public'::regnamespace and not t.tgisinternal)

  union all select 20, 'estrutura', 'enums', (select string_agg(t.typname || '(' || (select string_agg(e.enumlabel, ',' order by e.enumsortorder) from pg_enum e where e.enumtypid = t.oid) || ')', '; ' order by t.typname) from pg_type t where t.typnamespace = 'public'::regnamespace and t.typtype = 'e')
  union all select 21, 'estrutura', 'colunas ' || c.relname, string_agg(a.attname || ' ' || format_type(a.atttypid, a.atttypmod) || case when a.attnotnull then ' NN' else '' end || coalesce(' =' || pg_get_expr(d.adbin, d.adrelid), ''), '; ' order by a.attnum)
    from pg_class c join pg_attribute a on a.attrelid = c.oid and a.attnum > 0 and not a.attisdropped left join pg_attrdef d on d.adrelid = c.oid and d.adnum = a.attnum
    where c.relnamespace = 'public'::regnamespace and c.relname in ('Usuario', 'Matricula', 'Pagamento', 'TokenAuth', 'LogAuditoria') group by c.relname
  union all select 22, 'estrutura', 'índices ' || i.tablename, string_agg(i.indexname || ': ' || regexp_replace(i.indexdef, '^CREATE (UNIQUE )?INDEX \S+ ON \S+ USING \w+ ', '\1'), '; ' order by i.indexname)
    from pg_indexes i where i.schemaname = 'public' and i.tablename in ('Usuario', 'Matricula', 'Pagamento', 'TokenAuth', 'Turma', 'Curso', 'LogAuditoria', 'AulaData') group by i.tablename
  union all select 23, 'estrutura', 'restrições ' || cn.conrelid::regclass::text, string_agg(cn.conname || ': ' || pg_get_constraintdef(cn.oid), '; ' order by cn.conname)
    from pg_constraint cn where cn.connamespace = 'public'::regnamespace and cn.contype in ('f', 'c') group by cn.conrelid
  union all select 24, 'estrutura', 'formato dos ids', (select string_agg(tab || ' ' || fmt || '=' || n, '; ' order by tab, n desc) from (select tab, fmt, count(*) n from (
      select tab, case when id ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' then 'uuid' when id ~ '^c[a-z0-9]{20,30}$' then 'cuid' else 'outro(' || length(id) || ')' end fmt from (
        select 'Usuario' tab, id from "Usuario" union all select 'Matricula', id from "Matricula" union all select 'Pagamento', id from "Pagamento"
        union all select 'TokenAuth', id from "TokenAuth" union all select 'LogAuditoria', id from "LogAuditoria" union all select 'Turma', id from "Turma") ids) f group by 1, 2) g)

  union all select 30, 'usuários', 'papel e senha', (select string_agg(papel || ' ' || tipo || ': ' || n, '; ' order by papel, n desc) from (
      select papel::text, case when "senhaHash" is null then 'sem senha'
        when "senhaHash" like '$2%' then 'bcrypt ' || left("senhaHash", 7)
        when "senhaHash" like '$argon2%' then split_part("senhaHash", '$', 2) || ' ' || split_part("senhaHash", '$', 3)
        else 'outro formato ' || left(regexp_replace(regexp_replace("senhaHash", '[0-9a-f]{8,}', 'HEX', 'g'), '[A-Za-z0-9+/=_-]{8,}', 'B64', 'g'), 40) end tipo, count(*) n
      from "Usuario" group by 1, 2) x)
  union all select 31, 'usuários', 'e-mail', (select format('total %s; com maiúsculas %s; com espaço %s; repetidos ignorando maiúsculas %s; verificados %s', count(*), count(*) filter (where email <> lower(email)), count(*) filter (where email ~ '\s'), (select count(*) from (select 1 from "Usuario" group by lower(btrim(email)) having count(*) > 1) r), count(*) filter (where "emailVerificado")) from "Usuario")
  union all select 32, 'usuários', 'formato do documento', (select string_agg(tipo || ' ' || mascara || ': ' || n, '; ' order by n desc) || ' | repetidos (só dígitos) ' || (select count(*) from (select 1 from "Usuario" where "cpfCnpj" is not null group by regexp_replace("cpfCnpj", '\D', '', 'g') having count(*) > 1) r) from (
      select coalesce("tipoDocumento"::text, 'sem tipo') tipo, coalesce(regexp_replace(regexp_replace("cpfCnpj", '[0-9]', '9', 'g'), '[A-Za-z]', 'a', 'g'), '(vazio)') mascara, count(*) n
      from "Usuario" group by 1, 2 order by 3 desc limit 12) x)
  union all select 33, 'usuários', 'formato do celular (alunos)', (select string_agg(mascara || ': ' || n, '; ' order by n desc) from (
      select coalesce(regexp_replace(regexp_replace(celular, '[0-9]', '9', 'g'), '[A-Za-z]', 'a', 'g'), '(vazio)') mascara, count(*) n
      from "Usuario" where papel = 'ALUNO' group by 1 order by 2 desc limit 10) x)
  union all select 34, 'usuários', 'campos preenchidos (alunos)', (select format('alunos %s; escolaridade %s; situação escolar %s; gênero %s; CEP %s; endereço %s; cidade %s; UF %s; RG %s; passaporte %s; país %s; foto %s; consentimento LGPD %s; já fizeram login %s; bloqueados %s',
      count(*), count(escolaridade), count("escolaridadeSituacao"), count(genero), count(cep), count(logradouro), count(cidade), count(uf), count(rg), count(passaporte), count("paisOrigem"), count("avatarUrl"), count("consentimentoLgpdEm"), count("ultimoLogin"), count(*) filter (where "bloqueioTotal" or "bloqueadoAte" > localtimestamp))
      from "Usuario" where papel = 'ALUNO')
  union all select 35, 'usuários', 'valores usados (alunos)', (select string_agg(campo || ' → ' || valores, ' | ' order by campo) from (
      select campo, string_agg(coalesce(v, '(vazio)') || '=' || n, ', ' order by n desc) valores from (
        select campo, v, count(*) n, row_number() over (partition by campo order by count(*) desc) rn from (
          select 'escolaridade' campo, escolaridade v from "Usuario" where papel = 'ALUNO'
          union all select 'escolaridadeSituacao', "escolaridadeSituacao" from "Usuario" where papel = 'ALUNO'
          union all select 'tipoDocumento', "tipoDocumento"::text from "Usuario" where papel = 'ALUNO'
          union all select 'consentimentoVersao', "consentimentoVersao" from "Usuario" where papel = 'ALUNO') a group by 1, 2) b
      where rn <= 8 group by campo) c)

  union all select 40, 'tokens', coalesce(t.tipo, '(sem tipo)'), format('qtd %s; tamanho do tokenHash %s; hex %s; bcrypt %s; validade %s a %s min; usados %s; de usuários sem senha %s; de usuários inexistentes %s; último em %s',
      count(*), string_agg(distinct length(t."tokenHash")::text, '/'), count(*) filter (where t."tokenHash" ~ '^[0-9a-f]+$'), count(*) filter (where t."tokenHash" like '$2%'),
      round(min(extract(epoch from (t."expiraEm" - t."criadoEm")) / 60)), round(max(extract(epoch from (t."expiraEm" - t."criadoEm")) / 60)), count(t."usadoEm"),
      count(*) filter (where uu.id is not null and uu."senhaHash" is null), count(*) filter (where uu.id is null), max(t."criadoEm")::date)
    from "TokenAuth" t left join "Usuario" uu on uu.id = t."usuarioId" group by t.tipo

  union all select 50, 'matrículas', 'plano/forma/status/taxa', (select string_agg(format('%s/%s/%s/taxa %s: %s', plano, forma, st, tx, n), '; ' order by n desc) from (
      select plano::text, forma::text, "statusPagamento"::text st, case when "taxaConfirmada" then 'confirmada' else 'não' end tx, count(*) n from "Matricula" group by 1, 2, 3, 4) x)
  union all select 51, 'matrículas', 'valores por plano', (select string_agg(format('%s: %s matrículas; taxa gravada %s (igual à do curso %s); valorCurso = à vista %s, = cheio %s, outro %s', plano, n, taxas, taxa_curso, av, ch, ou), ' | ') from (
      select m.plano::text plano, count(*) n, string_agg(distinct m."valorTaxaMatricula"::text, '/') taxas, count(*) filter (where m."valorTaxaMatricula" = c."taxaMatricula") taxa_curso,
        count(*) filter (where m."valorCurso" = c."precoAvista") av, count(*) filter (where m."valorCurso" = c."precoCheio") ch,
        count(*) filter (where m."valorCurso" <> c."precoAvista" and m."valorCurso" <> c."precoCheio") ou
      from "Matricula" m join "Turma" t on t.id = m."turmaId" join "Curso" c on c.id = t."cursoId" group by 1) x)
  union all select 52, 'matrículas', 'prazos e lembretes', (select format('com prazo %s de %s; dias entre matrícula e prazo: %s; dias entre prazo e início da turma: %s; lembrete imediato %s; lembrete de véspera %s; taxa estornada %s; com diferença de transferência %s',
      count(m."prazoPagamentoCurso"), count(*),
      (select string_agg(d || 'd=' || n, ', ' order by n desc) from (select m2."prazoPagamentoCurso"::date - m2."criadoEm"::date d, count(*) n from "Matricula" m2 where m2."prazoPagamentoCurso" is not null group by 1 order by 2 desc limit 5) x),
      (select string_agg(d || 'd=' || n, ', ' order by n desc) from (select t2."inicioPrevisto"::date - m2."prazoPagamentoCurso"::date d, count(*) n from "Matricula" m2 join "Turma" t2 on t2.id = m2."turmaId" where m2."prazoPagamentoCurso" is not null group by 1 order by 2 desc limit 5) x),
      count(m."lembreteImediatoEm"), count(m."lembreteVesperaEm"), count(m."taxaEstornadaEm"), count(m."diferencaTransferencia")) from "Matricula" m)
  union all select 53, 'matrículas', 'taxa, alimento e situação', (select format('taxa confirmada %s (com pagamento TAXA %s, sem %s); com pagamento TAXA pago %s; alimento entregue %s; situação: %s',
      count(*) filter (where m."taxaConfirmada"), count(*) filter (where m."taxaConfirmada" and pt.tem_taxa), count(*) filter (where m."taxaConfirmada" and not pt.tem_taxa), count(*) filter (where pt.taxa_paga),
      count(*) filter (where m."alimentoEntregue"), (select string_agg(coalesce(s, '(sem)') || '=' || n, ', ') from (select situacao::text s, count(*) n from "Matricula" group by 1) x))
      from "Matricula" m cross join lateral (select coalesce(bool_or(p.tipo = 'TAXA'), false) tem_taxa, coalesce(bool_or(p.tipo = 'TAXA' and p.status = 'PAGO'), false) taxa_paga from "Pagamento" p where p."matriculaId" = m.id) pt)
  union all select 54, 'matrículas', 'por aluno', (select format('alunos com matrícula %s; com mais de uma %s; máximo por aluno %s; mesmo aluno e turma repetidos %s', count(*), count(*) filter (where n > 1), max(n), (select count(*) from (select 1 from "Matricula" group by "alunoId", "turmaId" having count(*) > 1) r)) from (select "alunoId", count(*) n from "Matricula" group by 1) x)
  union all select 55, 'quem registra', origem, string_agg(cls || '=' || n, '; ' order by n desc) from (
      select origem, cls, count(*) n from (
        select a.origem, case when a.v is null then '(vazio)' when uu.id is not null then 'usuário ' || uu.papel
          when a.v ~ '^[0-9 ().+/-]+$' then '(só números, ' || length(a.v) || ' caracteres)'
          when a.v ~ '^[a-z0-9_:.-]{1,40}$' or a.v ~ '^[A-Z0-9_:.-]{1,40}$' then 'rótulo "' || a.v || '"'
          else '(outro formato, ' || length(a.v) || ' caracteres)' end cls
        from (select 'Matricula.taxaConfirmadaPor' origem, "taxaConfirmadaPor" v from "Matricula"
          union all select 'Matricula.confirmadaPor', "confirmadaPor" from "Matricula"
          union all select 'Matricula.alimentoEntreguePor', "alimentoEntreguePor" from "Matricula"
          union all select 'Pagamento.estornadoPor', "estornadoPor" from "Pagamento"
          union all select 'LogAuditoria.atorId', "atorId" from "LogAuditoria") a left join u uu on uu.id = a.v) b
      group by 1, 2) c group by origem

  union all select 60, 'pagamentos', 'tipo/método/status/gateway', (select string_agg(format('%s/%s/%s/%s: %s (R$ %s a %s)', tipo, metodo, status, gw, n, mn, mx), '; ' order by n desc) from (
      select tipo::text, metodo::text, status::text, coalesce(gateway, '(sem gateway)') gw, count(*) n, min(valor) mn, max(valor) mx from "Pagamento" group by 1, 2, 3, 4) x)
  union all select 61, 'pagamentos', 'identificadores do gateway', (select string_agg(format('%s: hash %s de %s (tamanhos %s, distintos %s), ref %s (tamanhos %s), status do gateway %s, chaves da resposta {%s}', coalesce(gw, '(sem gateway)'), nh, n, th, uh, nr, tr, sts, chaves), ' | ') from (
      select p.gateway gw, count(*) n, count(p."gatewayHash") nh, string_agg(distinct length(p."gatewayHash")::text, '/') th, count(distinct p."gatewayHash") uh,
        count(p."gatewayRef") nr, string_agg(distinct length(p."gatewayRef")::text, '/') tr, string_agg(distinct p."gatewayStatus", '/') sts,
        (select string_agg(distinct k, ',') from "Pagamento" p2, jsonb_object_keys(case when jsonb_typeof(p2."gatewayResponse") = 'object' then p2."gatewayResponse" else '{}'::jsonb end) k where p2.gateway is not distinct from p.gateway) chaves
      from "Pagamento" p group by p.gateway) x)

  union all select 70, 'auditoria', 'ações (alvo): qtd', (select string_agg(format('%s (%s): %s', acao, alvo, n), '; ' order by n desc) from (
      select acao, coalesce("alvoTipo", '-') alvo, count(*) n from "LogAuditoria" group by 1, 2 order by 3 desc limit 40) x)
  union all select 71, 'auditoria', 'campos do detalhe por ação', (select string_agg(acao || ' {' || chaves || '}', '; ' order by acao) from (
      select l.acao, string_agg(distinct k, ',') chaves from "LogAuditoria" l, jsonb_object_keys(case when jsonb_typeof(l.detalhe) = 'object' then l.detalhe else '{}'::jsonb end) k group by l.acao order by 1 limit 40) x)

  union all select 80, 'cursos e turmas', c.nome, format('id %s; %s; %sh; à vista %s, cheio %s, %sx de %s; taxa de matrícula %s; escolaridade mínima %s | turmas: %s',
      c.id, case when c.ativo then 'ativo' else 'inativo' end, c."cargaHoraria", c."precoAvista", c."precoCheio", c.parcelas, c."valorParcela", coalesce(c."taxaMatricula"::text, '(vazia)'), coalesce(c."escolaridadeMinima", '-'),
      coalesce((select string_agg(format('[%s %s, início %s, vagas %s (mín. %s), matrículas %s: pagas %s, taxa ok %s, canceladas %s; aulas %s]',
          t.id, t.status, to_char(t."inicioPrevisto", 'YYYY-MM-DD HH24:MI'), t.vagas, t."minimoAlunos",
          (select count(*) from "Matricula" m where m."turmaId" = t.id),
          (select count(*) from "Matricula" m where m."turmaId" = t.id and m."statusPagamento" = 'PAGO'),
          (select count(*) from "Matricula" m where m."turmaId" = t.id and m."taxaConfirmada"),
          (select count(*) from "Matricula" m where m."turmaId" = t.id and m."statusPagamento" in ('CANCELADO', 'ESTORNADO')),
          coalesce((select min(ad.data) || ' a ' || max(ad.data) || ' (' || count(*) || ' dias, ' || string_agg(distinct replace(ad.horario, E'\n', ' '), ' / ') || ')' from "AulaData" ad where ad."turmaId" = t.id), 'sem datas')),
        ' ' order by t."inicioPrevisto" desc) from (select * from "Turma" t0 where t0."cursoId" = c.id order by t0."inicioPrevisto" desc limit 6) t), 'nenhuma'))
    from "Curso" c

  union all select 90, 'configuração', 'chaves', (select string_agg(chave || case when valor ~ '^(true|false|-?[0-9]{1,6}([.,][0-9]{1,2})?)$' and chave !~* '(senha|secret|segredo|token|key|chave|pass|pin|api|auth)' then ' = ' || valor else ' (' || length(valor) || ' caracteres)' end, '; ' order by chave) from "Configuracao")
  union all select 91, 'migrations', 'prisma', (select count(*) || ' aplicadas; última em ' || max(finished_at)::date || ': ' || string_agg(migration_name, ', ' order by started_at) from _prisma_migrations where rolled_back_at is null)
)
select secao, item, valor from linhas order by ordem, item;
