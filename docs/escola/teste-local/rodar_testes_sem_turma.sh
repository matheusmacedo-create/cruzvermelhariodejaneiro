#!/bin/bash
# Tudo o que a v2 precisa passar, num Postgres 16 descartável e local (nunca o banco da escola), dados fictícios:
# as suítes 03 (v1 contra a v2), 06 (pagar tudo), 09 (revisões), 10 (venda sem turma), 13 (revisões da venda sem
# turma) e 14 (revisão de dinheiro de 09/10: fila antes de só a taxa), o batimento (07), a
# concorrência da mesma transação (08, 6 em paralelo), a concorrência da última vaga na espera (11, 2 em paralelo)
# e a trava de projeto (a v2 num banco vazio). Uso: rodar_testes_sem_turma.sh <arquivo_v2.sql> <pasta_dos_testes> [v2_anterior.sql]
# Com o terceiro argumento, roda também o 10 e o 13 contra a v2 anterior: o 10 passa nas duas, o 13 enxerga a mudança.
set -uo pipefail
V2=$1; T=$2; DB=${DB:-escola_sem_turma}; P="-h ${PGHOST:-/tmp} -p ${PGPORT:-55441}"
psqlq() { su postgres -c "psql $P -q -X -t -A -d $1 ${2:-}"; }
echo "# v2 com a venda sem turma, $(date -u +%Y-%m-%dT%H:%M:%SZ), $(su postgres -c "psql $P -X -t -A -d postgres -c 'show server_version'" | cut -d' ' -f1) local descartável, dados fictícios"
echo "sha256 $(sha256sum < $V2 | cut -d' ' -f1)  $(wc -l < $V2) linhas  $(basename $V2)"
su postgres -c "dropdb $P --if-exists $DB" >/dev/null 2>&1
su postgres -c "createdb $P $DB"
for f in 00_papeis_supabase.sql 01_estrutura_escola.sql 02_dados_ficticios.sql; do
  su postgres -c "psql $P -q -v ON_ERROR_STOP=1 -d $DB" < $T/$f >/dev/null 2>&1 || { echo "FALHOU $f"; exit 1; }
done
echo "conferência da v2 (função | argumentos | security definer | service_role executa | anon executa):"
su postgres -c "psql $P -q -X -t -A -v ON_ERROR_STOP=1 -d $DB" < $V2 2>/dev/null | grep '|'
for s in 03_testes_pgtap.sql 06_testes_pagar_tudo_pgtap.sql 09_testes_revisoes_pgtap.sql 10_testes_sem_turma_pgtap.sql 13_testes_revisao_sem_turma_pgtap.sql 14_testes_fila_pgtap.sql; do
  out=$(psqlq $DB < $T/$s 2>&1)
  echo "$s: $(echo "$out" | grep -c '^ok ') ok, $(echo "$out" | grep -c '^not ok') falhas ($(echo "$out" | grep -E '^1\.\.' ))"
  echo "$out" | grep -E '^not ok|ERRO|ERROR' | head -40
done
echo "10, um por um:"
psqlq $DB < $T/10_testes_sem_turma_pgtap.sql 2>&1 | grep -E '^(not )?ok ' | sed 's/^/  /'
echo "13, um por um:"
psqlq $DB < $T/13_testes_revisao_sem_turma_pgtap.sql 2>&1 | grep -E '^(not )?ok ' | sed 's/^/  /'
echo "14 (revisão de dinheiro de 09/10: a fila antes de só a taxa; pagou tudo com a turma vendida fechada), um por um:"
psqlq $DB < $T/14_testes_fila_pgtap.sql 2>&1 | grep -E '^(not )?ok ' | sed 's/^/  /'
echo "07 (v1 depois do batimento: primeira | segunda, aviso | pagamentos com o hash):"
psqlq $DB < $T/07_v1_batimento.sql 2>&1 | grep -v '^$'
echo "08 (6 chamadas da mesma transação em paralelo; usuário|matrícula|pagamento|anotação):"
psqlq $DB < $T/08_concorrencia_prep.sql >/dev/null 2>&1
for i in 1 2 3 4 5 6; do (psqlq $DB < $T/08_chamada.sql >/dev/null 2>&1) & done; wait
psqlq $DB "-c \"select (select count(*) from \\\"Usuario\\\" where email = 'concorrente@exemplo.test') || '|' || (select count(*) from \\\"Matricula\\\" m join \\\"Usuario\\\" u on u.id = m.\\\"alunoId\\\" where u.email = 'concorrente@exemplo.test') || '|' || (select count(*) from \\\"Pagamento\\\" where \\\"gatewayHash\\\" = 'CONC000001') || '|' || (select count(*) from \\\"Configuracao\\\" where chave = 'matricularapida:901')\""
echo "11 (espera: duas pessoas esperando, turma nova com 1 vaga, 2 chamadas da rotina em paralelo):"
psqlq $DB < $T/11_concorrencia_espera_prep.sql 2>&1 | grep -v '^$' | sed 's/^/  /'
TMPD=$(mktemp -d); for n in 1 2; do (psqlq $DB "-v n=$n" < $T/11_chamada_espera.sql > $TMPD/r$n 2>&1) & done; wait
echo "  resultados das duas chamadas: $(cat $TMPD/r1 $TMPD/r2 | grep -v '^$' | sort | tr '\n' ' ')"; rm -rf $TMPD
echo "  matrículas na turma de 1 vaga: $(psqlq $DB "-c \"select count(*) from \\\"Matricula\\\" where \\\"turmaId\\\" = 'ce-turma'\"")"
echo "trava de projeto (a v2 num banco vazio):"
su postgres -c "dropdb $P --if-exists ${DB}_vazio; createdb $P ${DB}_vazio" >/dev/null 2>&1
msg=$(su postgres -c "psql $P -q -X -v ON_ERROR_STOP=1 -d ${DB}_vazio" < $V2 2>&1 | grep -o 'Este não é o projeto da escola' | head -1)
echo "  parou com \"$msg\", funções criadas: $(psqlq ${DB}_vazio "-c \"select count(*) from pg_proc where proname like 'matricula_rapida%'\"")"
if [ -n "${3:-}" ]; then
  su postgres -c "dropdb $P --if-exists ${DB}_ant; createdb $P ${DB}_ant" >/dev/null 2>&1
  for f in 00_papeis_supabase.sql 01_estrutura_escola.sql 02_dados_ficticios.sql; do su postgres -c "psql $P -q -d ${DB}_ant" < $T/$f >/dev/null 2>&1; done
  su postgres -c "psql $P -q -d ${DB}_ant" < $3 >/dev/null 2>&1
  for s in 10_testes_sem_turma_pgtap.sql 13_testes_revisao_sem_turma_pgtap.sql; do
    out=$(psqlq ${DB}_ant < $T/$s 2>&1)
    echo "$s contra a v2 anterior ($(basename $3), sha256 $(sha256sum < $3 | cut -c1-12)…): $(echo "$out" | grep -c '^ok ') ok, $(echo "$out" | grep -c '^not ok') falhas$(echo "$out" | grep -q 'ERRO\|ERROR' && echo ', a suíte para num erro')"
  done
  echo "  (o 10, ajustado, vale para as duas versões: é a regressão do comportamento de antes; o 13 enxerga a mudança)"
  su postgres -c "dropdb $P ${DB}_ant"
fi
su postgres -c "dropdb $P ${DB}_vazio; dropdb $P $DB"
echo "bancos de teste apagados"
