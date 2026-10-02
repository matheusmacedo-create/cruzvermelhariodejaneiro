#!/usr/bin/env bash
# Confere no ar a publicação da matrícula (sem credencial nenhuma, só HTTP):
#  - cada estático da lista responde 200 e é igual ao do repositório (byte a byte);
#  - os endereços da API respondem o que respondem quando estão bons (um 503 seria o banco ou a migração);
#  - configuração, módulos e rotinas continuam bloqueados (403);
#  - o ponto pode pedir a localização (Permissions-Policy com geolocation=(self)) e o resto da matrícula não.
# Uso: scripts/conferir_publicacao.sh scripts/publicacao-comunicacao.txt [https://cruzvermelhariodejaneiro.org]
# Rode depois de limpar o cache. Sai com 1 se algo falhar.
set -uo pipefail
lista="${1:?informe a lista de arquivos publicados}"
base="${2:-https://cruzvermelhariodejaneiro.org}"
m="$base/matricula-cursos-presenciais"
tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT
falhas=0
ok() { printf 'ok     %s\n' "$*"; }
falha() { printf 'FALHA  %s\n' "$*"; falhas=$((falhas + 1)); }
pedir() { curl -sS -o "$tmp" -D "$tmp.cab" -w '%{http_code}' --max-time 30 "$1" 2>/dev/null || echo 000; }

echo "== Estáticos iguais ao repositório"
marca="conferencia=$(date +%s)"
for arquivo in $(grep -vE '^(#|$)' "$lista"); do
  destino="${arquivo#site/}"
  case "$destino" in *.php | *.htaccess) continue ;; esac
  codigo=$(pedir "$base/${destino%index.html}?$marca")
  if [ "$codigo" = 200 ] && cmp -s "$tmp" "$arquivo"; then ok "$destino"; else falha "$destino (HTTP $codigo, conteúdo $(cmp -s "$tmp" "$arquivo" && echo igual || echo diferente))"; fi
done

echo "== API"
# endereço | status esperado | trecho que a resposta precisa ter (vazio = qualquer)
while IFS='|' read -r caminho esperado trecho; do
  codigo=$(pedir "$m/$caminho")
  if [ "$codigo" = "$esperado" ] && { [ -z "$trecho" ] || grep -qF -- "$trecho" "$tmp"; }; then ok "$caminho → $codigo"; else falha "$caminho → $codigo (esperado $esperado${trecho:+ com: $trecho})"; fi
done <<'LISTA'
api/ponto.php|200|"pede_codigo"
api/painel.php|200|
api/avisos.php?u=1.abc|404|Este link não vale mais
api/whatsapp.php|403|
api/conferir.php|422|
api/escola-horarios.php|401|
api/info.php|200|"ok":true
api/status.php|404|
api/horarios.php|404|
api/contato.php|405|
api/pagamentos.php|405|
api/webhook.php|405|
api/comparecimento.php|404|
api/medicao.php|405|
LISTA

echo "== Bloqueados"
for caminho in api/config.php api/config-escola.php api/config.example.php api/lib.php \
  api/lib/avisos.php api/lib/db.php api/lib/config.php api/comparecimentos.php api/lembretes.php; do
  codigo=$(pedir "$m/$caminho")
  if [ "$codigo" = 403 ]; then ok "$caminho → 403"; else falha "$caminho → $codigo (esperado 403)"; fi
done
# Estes podem não existir no servidor; aí o LiteSpeed responde 404 antes de aplicar o bloqueio.
for caminho in api/config-whatsapp.php api/config-meta.php api/config.php.bak; do
  codigo=$(pedir "$m/$caminho")
  case "$codigo" in 403 | 404) ok "$caminho → $codigo" ;; *) falha "$caminho → $codigo (esperado 403 ou 404)" ;; esac
done

echo "== Localização"
for caminho in ponto/ ponto/lembretes/; do
  codigo=$(pedir "$m/$caminho")
  politica=$(grep -i '^permissions-policy:' "$tmp.cab" | tr -d '\r')
  if [ "$codigo" = 200 ] && [[ "$politica" == *"geolocation=(self)"* ]]; then ok "$caminho pode pedir a localização"; else falha "$caminho → $codigo, ${politica:-sem Permissions-Policy}"; fi
done
codigo=$(pedir "$m/checkout/")
politica=$(grep -i '^permissions-policy:' "$tmp.cab" | tr -d '\r')
if [ "$codigo" = 200 ] && [[ "$politica" == *"geolocation=()"* ]]; then ok "checkout/ continua sem localização"; else falha "checkout/ → $codigo, ${politica:-sem Permissions-Policy}"; fi
rm -f "$tmp.cab"

echo
if [ "$falhas" -eq 0 ]; then echo "Tudo certo."; else echo "$falhas falha(s)."; exit 1; fi
