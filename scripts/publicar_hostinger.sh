#!/usr/bin/env bash
# Publica arquivos de site/ no public_html do site principal (Hostinger) via TUS.
#
# Antes, gere as credenciais temporárias na API da Hostinger ("Generate upload URL" do
# website cruzvermelhariodejaneiro.org, conta u448697994; valem algumas horas) e exporte:
#   export HOSTINGER_TUS_URL="https://srvNNN-files.hstgr.io/rest/<id>/api/tus/public_html"
#   export HOSTINGER_TUS_AUTH="<auth_key>"
#   export HOSTINGER_TUS_AUTH_REST="<rest_auth_key>"
# Uso:
#   scripts/publicar_hostinger.sh site/index.html site/cursos.html site/sitemap-escola.xml
# O caminho dentro de public_html é o caminho relativo à pasta site/.
# Depois de publicar, limpe o cache do site (hPanel ou "clear website cache" na API).
set -euo pipefail
: "${HOSTINGER_TUS_URL:?defina HOSTINGER_TUS_URL}" "${HOSTINGER_TUS_AUTH:?defina HOSTINGER_TUS_AUTH}" "${HOSTINGER_TUS_AUTH_REST:?defina HOSTINGER_TUS_AUTH_REST}"
[ "$#" -gt 0 ] || { echo "informe ao menos um arquivo de site/"; exit 2; }
for arquivo in "$@"; do
  [ -f "$arquivo" ] || { echo "arquivo não encontrado: $arquivo"; exit 2; }
  destino="${arquivo#site/}"
  tamanho=$(stat -c%s "$arquivo")
  codigo=$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$HOSTINGER_TUS_URL/$destino?override=true" \
    -H "X-Auth: $HOSTINGER_TUS_AUTH" -H "X-Auth-Rest: $HOSTINGER_TUS_AUTH_REST" \
    -H "Tus-Resumable: 1.0.0" -H "Upload-Length: $tamanho" -H "Upload-Offset: 0")
  [ "$codigo" = "201" ] || { echo "falha ao iniciar envio de $destino (HTTP $codigo)"; exit 1; }
  codigo=$(curl -sS -o /dev/null -w '%{http_code}' -X PATCH "$HOSTINGER_TUS_URL/$destino?override=true" \
    -H "X-Auth: $HOSTINGER_TUS_AUTH" -H "X-Auth-Rest: $HOSTINGER_TUS_AUTH_REST" \
    -H "Tus-Resumable: 1.0.0" -H "Content-Type: application/offset+octet-stream" -H "Upload-Offset: 0" \
    --data-binary "@$arquivo")
  [ "$codigo" = "204" ] || { echo "falha ao enviar $destino (HTTP $codigo)"; exit 1; }
  echo "publicado: $destino ($tamanho bytes)"
done
