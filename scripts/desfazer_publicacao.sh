#!/usr/bin/env bash
# Desfaz uma publicação: cada arquivo da lista volta à versão do commit que estava no ar antes, na ordem
# inversa da publicação (páginas, endereços da API, módulos, lib.php e só então os módulos novos saem), e os
# que não existiam naquele commit são apagados, menos .htaccess (publicar_hostinger.sh nunca os apaga).
# Não depende da cópia tirada do ar: a versão anterior vem do Git.
# Uso (com as credenciais TUS exportadas, como em publicar_hostinger.sh):
#   scripts/desfazer_publicacao.sh a237b7b scripts/publicacao-comunicacao.txt
# As tabelas e colunas novas do banco ficam: o código anterior não as usa.
set -euo pipefail
anterior="${1:?informe o commit que estava no ar antes da publicação}"
lista="${2:?informe a lista publicada}"
git cat-file -e "$anterior^{commit}" 2>/dev/null || { echo "commit desconhecido: $anterior"; exit 2; }
aqui="$(cd "$(dirname "$0")" && pwd)"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
for arquivo in $(grep -vE '^(#|$)' "$lista" | tac); do
  if git cat-file -e "$anterior:$arquivo" 2>/dev/null; then
    mkdir -p "$tmp/$(dirname "$arquivo")"
    git show "$anterior:$arquivo" > "$tmp/$arquivo"
    RAIZ="$tmp/site" "$aqui/publicar_hostinger.sh" "$tmp/$arquivo"
  else
    "$aqui/publicar_hostinger.sh" --apagar "$arquivo"
  fi
done
