-- Apaga da escola o que UMA transação de TESTE do site criou: pagamento, matrícula, conta e registros.
-- Só limpa o que a própria transação criou: se ela aplicou a taxa numa matrícula que já existia,
-- ou se a conta já tinha outras matrículas, para e avisa (aí a limpeza é à mão, com a secretaria).
-- Troque o hash abaixo pelo da transação de teste (aparece no aviso à secretaria: "Transação Unicopag").
do $$
declare
  v_transacao constant text := 'COLE-AQUI-O-HASH-DA-TRANSACAO';
  v_usuario text;
  v_matricula text;
begin
  select m.id, m."alunoId" into v_matricula, v_usuario
    from public."Pagamento" p join public."Matricula" m on m.id = p."matriculaId"
   where p.gateway in ('site', 'unicopag-2') and p."gatewayHash" = v_transacao;  -- unicopag-2: o "pagou tudo" da v2
  if v_usuario is null then
    select l."alvoId" into v_usuario from public."LogAuditoria" l
     where l.acao = 'TAXA_PAGA_SEM_TURMA_PELO_SITE' and l.detalhe->>'transacao' = v_transacao;
  end if;
  if v_usuario is null then
    raise exception 'Nada do site encontrado para a transação %', v_transacao;
  end if;
  if v_matricula is not null and not exists (
       select 1 from public."LogAuditoria" l where l.acao = 'MATRICULOU_PELO_SITE' and l."alvoId" = v_matricula and l.detalhe->>'transacao' = v_transacao) then
    raise exception 'A transação aplicou a taxa numa matrícula que já existia: limpe à mão.';
  end if;
  if not exists (select 1 from public."LogAuditoria" l where l.acao = 'CADASTROU_ALUNO_PELO_SITE' and l."alvoId" = v_usuario and l.detalhe->>'transacao' = v_transacao) then
    raise exception 'A conta já existia antes desta transação: limpe à mão.';
  end if;
  if exists (select 1 from public."Matricula" m where m."alunoId" = v_usuario and m.id is distinct from v_matricula) then
    raise exception 'A conta tem outras matrículas: limpe à mão.';
  end if;
  delete from public."Pagamento" where "matriculaId" = v_matricula;  -- Avaliacao sai junto (ON DELETE CASCADE)
  delete from public."Matricula" where id = v_matricula;
  delete from public."TokenAuth" where "usuarioId" = v_usuario;
  -- v2 (pagar tudo): a anotação matricularapida:<referencia>, achada pela referência do log, antes de apagar o log
  -- (sem converter todas as anotações para JSON: uma anotação com JSON inválido derrubaria a limpeza; T23).
  delete from public."Configuracao" where chave = 'matricularapida:' || (select l.detalhe->>'referencia' from public."LogAuditoria" l where l.detalhe->>'transacao' = v_transacao and l.detalhe ? 'referencia' limit 1);
  delete from public."LogAuditoria" where detalhe->>'transacao' = v_transacao;
  delete from public."Usuario" where id = v_usuario;
  raise notice 'Transação de teste % apagada da escola.', v_transacao;
end $$;
