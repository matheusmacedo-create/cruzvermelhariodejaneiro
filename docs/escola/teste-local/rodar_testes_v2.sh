#!/bin/bash
# Roda as suítes da matricula_rapida (v1 contra a v2, v2, revisões, venda sem turma e revisões da venda sem turma) num Postgres 16 descartável e local.
# Uso: rodar_testes_v2.sh <arquivo_v2.sql> <pasta_dos_testes>
set -uo pipefail
V2=$1; T=$2; DB=${DB:-escola_v2_final}; P="-h ${PGHOST:-/tmp} -p ${PGPORT:-55441}"  # porta e soquete do Postgres local
su postgres -c "dropdb $P --if-exists $DB" >/dev/null 2>&1
su postgres -c "createdb $P $DB"
for f in 00_papeis_supabase.sql 01_estrutura_escola.sql 02_dados_ficticios.sql; do
  su postgres -c "psql $P -q -v ON_ERROR_STOP=1 -d $DB" < $T/$f >/dev/null || { echo "FALHOU $f"; exit 1; }
done
su postgres -c "psql $P -q -v ON_ERROR_STOP=1 -d $DB" < $V2 | tail -3
for s in 03_testes_pgtap.sql 06_testes_pagar_tudo_pgtap.sql 09_testes_revisoes_pgtap.sql 10_testes_sem_turma_pgtap.sql 13_testes_revisao_sem_turma_pgtap.sql; do
  out=$(su postgres -c "psql $P -q -X -t -A -d $DB" < $T/$s 2>&1)
  echo "$s: $(echo "$out" | grep -c '^ok ') ok, $(echo "$out" | grep -c '^not ok') falhas"
  echo "$out" | grep -E '^not ok|#|ERRO|ERROR' | head -40
done
