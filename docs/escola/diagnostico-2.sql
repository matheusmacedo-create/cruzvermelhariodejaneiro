-- Diagnóstico 2 (SÓ LEITURA, um único SELECT): como a escola grava valores, a taxa paga online,
-- a confirmação de e-mail e o primeiro acesso. Só contagens, valores em R$ e rótulos do sistema.
with
m as (select m.*, c.nome as curso, c."precoAvista", c."precoCheio" from "Matricula" m join "Turma" t on t.id = m."turmaId" join "Curso" c on c.id = t."cursoId"),
rotulos as (
  select origem, case
      when v is null then '(vazio)'
      when exists (select 1 from "Usuario" u where u.id = v) then '(id de usuário)'
      when v ~* '(unicopag|gateway|site|sistema|webhook|pix|cart|manual|pagamento|online|autom|inscri|taxa|curso|hash)'
        then regexp_replace(regexp_replace(v, '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}', '<uuid>', 'g'), '(?=[A-Za-z]*[0-9])[A-Za-z0-9]{8,}', '<codigo>', 'g')
      else '(texto livre, ' || length(v) || ' caracteres)' end as rotulo
  from (select 'taxaConfirmadaPor' origem, "taxaConfirmadaPor" v from "Matricula"
        union all select 'confirmadaPor', "confirmadaPor" from "Matricula") x
),
linhas(ordem, item, valor) as (
  select 1, 'matricula_modo', (select valor from "Configuracao" where chave = 'matricula_modo')
  union all select 10, 'valores gravados: ' || curso, 'preço atual à vista ' || min("precoAvista") || ', cheio ' || min("precoCheio") || ' | ' ||
      string_agg(combo, '; ' order by combo) from (
        select curso, "precoAvista", "precoCheio", plano::text || ' valorCurso ' || "valorCurso" || ' + taxa ' || "valorTaxaMatricula" || ' ×' || count(*) as combo
        from m group by curso, "precoAvista", "precoCheio", plano, "valorCurso", "valorTaxaMatricula") v group by curso
  union all select 20, 'pagamento CURSO pago × matrícula', (select string_agg(rel || '=' || n, '; ' order by n desc) from (
      select case when p.valor = m."valorCurso" then 'igual ao valorCurso'
                  when p.valor = m."valorCurso" - m."valorTaxaMatricula" then 'valorCurso menos a taxa'
                  when p.valor = m."valorCurso" + m."valorTaxaMatricula" then 'valorCurso mais a taxa'
                  else 'outro' end || case when m."taxaConfirmada" then ' (taxa já confirmada)' else ' (taxa não confirmada)' end rel, count(*) n
      from "Pagamento" p join m on m.id = p."matriculaId" where p.tipo = 'CURSO' and p.status = 'PAGO' group by 1) x)
  union all select 21, 'pagamento CURSO pendente/cancelado × matrícula', (select string_agg(rel || '=' || n, '; ' order by n desc) from (
      select p.status::text || ': ' || case when p.valor = m."valorCurso" then 'igual ao valorCurso'
                  when p.valor = m."valorCurso" - m."valorTaxaMatricula" then 'valorCurso menos a taxa'
                  when p.valor = m."valorCurso" + m."valorTaxaMatricula" then 'valorCurso mais a taxa'
                  else 'outro' end || case when m."taxaConfirmada" then ' (taxa já confirmada)' else ' (taxa não confirmada)' end rel, count(*) n
      from "Pagamento" p join m on m.id = p."matriculaId" where p.tipo = 'CURSO' and p.status <> 'PAGO' group by 1) x)
  union all select 30, 'matrículas com taxa paga online (TAXA unicopag PAGO)', (select string_agg(combo || '=' || n, '; ' order by n desc) from (
      select format('%s/%s/%s, taxaConfirmada %s, pagamento %s', m.plano, m.forma, m."statusPagamento", m."taxaConfirmada", p.metodo) combo, count(*) n
      from "Pagamento" p join m on m.id = p."matriculaId" where p.tipo = 'TAXA' and p.gateway = 'unicopag' and p.status = 'PAGO' group by 1) x)
  union all select 31, 'rótulos quando a taxa foi paga online', (select string_agg(r || '=' || n, '; ' order by n desc) from (
      select rotulo r, count(*) n from (
        select case when mm."taxaConfirmadaPor" is null then '(vazio)'
                    when exists (select 1 from "Usuario" u where u.id = mm."taxaConfirmadaPor") then '(id de usuário)'
                    when mm."taxaConfirmadaPor" ~* '(unicopag|gateway|site|sistema|webhook|pix|cart|manual|pagamento|online|autom|inscri|taxa|curso|hash)'
                      then regexp_replace(regexp_replace(mm."taxaConfirmadaPor", '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}', '<uuid>', 'g'), '(?=[A-Za-z]*[0-9])[A-Za-z0-9]{8,}', '<codigo>', 'g')
                    else '(texto livre, ' || length(mm."taxaConfirmadaPor") || ' caracteres)' end rotulo
        from "Matricula" mm where exists (select 1 from "Pagamento" p where p."matriculaId" = mm.id and p.tipo = 'TAXA' and p.gateway = 'unicopag' and p.status = 'PAGO')) y group by 1) x)
  union all select 40, 'rótulos: ' || origem, string_agg(rotulo || '=' || n, '; ' order by n desc) from (select origem, rotulo, count(*) n from rotulos group by 1, 2) r group by origem
  union all select 50, 'alunos: e-mail confirmado × já entrou', (select string_agg(combo || '=' || n, '; ' order by combo) from (
      select case when "emailVerificado" then 'confirmado' else 'não confirmado' end || ' / ' || case when "ultimoLogin" is not null then 'já entrou' else 'nunca entrou' end combo, count(*) n
      from "Usuario" where papel = 'ALUNO' group by 1) x)
  union all select 51, 'alunos que já entraram × troca de senha', (select format('já entraram %s; com SENHA_ALTERADA registrada %s; com RESET_SENHA usado %s; cadastrados nos últimos 30 dias %s (confirmados %s, já entraram %s)',
      count(*) filter (where u."ultimoLogin" is not null),
      count(*) filter (where u."ultimoLogin" is not null and exists (select 1 from "LogAuditoria" l where l.acao = 'SENHA_ALTERADA' and l."alvoId" = u.id)),
      count(*) filter (where u."ultimoLogin" is not null and exists (select 1 from "TokenAuth" k where k.tipo = 'RESET_SENHA' and k."usuarioId" = u.id and k."usadoEm" is not null)),
      count(*) filter (where u."criadoEm" > localtimestamp - interval '30 days'),
      count(*) filter (where u."criadoEm" > localtimestamp - interval '30 days' and u."emailVerificado"),
      count(*) filter (where u."criadoEm" > localtimestamp - interval '30 days' and u."ultimoLogin" is not null))
      from "Usuario" u where u.papel = 'ALUNO')
  union all select 52, 'quem registra SENHA_ALTERADA', (select string_agg(q || '=' || n, '; ' order by n desc) from (
      select case when l."atorId" = l."alvoId" then 'o próprio aluno' when l."atorId" in ('SISTEMA', 'ANONIMO') then l."atorId" else 'outra pessoa' end q, count(*) n
      from "LogAuditoria" l where l.acao = 'SENHA_ALTERADA' group by 1) x)
)
select item, valor from linhas order by ordem, item;
