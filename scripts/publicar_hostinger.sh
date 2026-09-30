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
# O caminho dentro de public_html é o caminho relativo à pasta site/ (ou a RAIZ, se definida).
# Depois de publicar, limpe o cache do site (hPanel ou "clear website cache" na API).
#
# Antes de sobrescrever, guarde o que está no ar (arquivos que ainda não existem vão para novos.txt):
#   scripts/publicar_hostinger.sh --copiar-do-ar /tmp/no-ar site/index.html site/cursos.html
# Para desfazer: republique a cópia e apague os que eram novos.
#   RAIZ=/tmp/no-ar scripts/publicar_hostinger.sh /tmp/no-ar/index.html /tmp/no-ar/cursos.html
#   scripts/publicar_hostinger.sh --apagar $(cat /tmp/no-ar/novos.txt)
# A mesma credencial abre a API de arquivos: .../api/raw/<caminho> lê um arquivo publicado (inclusive
# PHP) e DELETE em .../api/resources/<caminho> apaga (204 = apagado).
# Os config.php do servidor (senhas, fora do Git) nunca são enviados nem apagados por aqui.
set -euo pipefail
: "${HOSTINGER_TUS_URL:?defina HOSTINGER_TUS_URL}" "${HOSTINGER_TUS_AUTH:?defina HOSTINGER_TUS_AUTH}" "${HOSTINGER_TUS_AUTH_REST:?defina HOSTINGER_TUS_AUTH_REST}"
RAIZ="${RAIZ:-site}"
RAIZ="${RAIZ%/}"
modo=publicar
case "${1:-}" in
  --copiar-do-ar) modo=copiar; copia="${2:?informe a pasta da cópia}"; shift 2 ;;
  --apagar) modo=apagar; shift ;;
esac
[ "$#" -gt 0 ] || { echo "informe ao menos um arquivo de $RAIZ/"; exit 2; }
autorizacao=(-H "X-Auth: $HOSTINGER_TUS_AUTH" -H "X-Auth-Rest: $HOSTINGER_TUS_AUTH_REST")
for arquivo in "$@"; do
  destino="${arquivo#"$RAIZ"/}"
  [ "$destino" != "$arquivo" ] || { echo "fora de $RAIZ/: $arquivo"; exit 2; }
  if [[ "$destino" =~ (^|/)api/config(-[a-z]+)?\.php$ ]]; then
    echo "recusado: $destino guarda as senhas do servidor e nunca sai daqui"; exit 2
  fi
  case "$modo" in
    copiar)
      mkdir -p "$copia/$(dirname "$destino")"
      codigo=$(curl -sS -o "$copia/$destino" -w '%{http_code}' "${HOSTINGER_TUS_URL/\/api\/tus\//\/api\/raw\/}/$destino" "${autorizacao[@]}")
      case "$codigo" in
        200) echo "copiado do ar: $destino" ;;
        404) rm -f "$copia/$destino"; echo "$RAIZ/$destino" >> "$copia/novos.txt"; echo "ainda não existe no ar: $destino" ;;
        *) rm -f "$copia/$destino"; echo "falha ao copiar $destino (HTTP $codigo)"; exit 1 ;;
      esac
      ;;
    apagar)
      codigo=$(curl -sS -o /dev/null -w '%{http_code}' -X DELETE "${HOSTINGER_TUS_URL/\/api\/tus\//\/api\/resources\/}/$destino" "${autorizacao[@]}")
      case "$codigo" in
        204|200) echo "apagado: $destino" ;;
        404) echo "já não existia: $destino" ;;
        *) echo "falha ao apagar $destino (HTTP $codigo)"; exit 1 ;;
      esac
      ;;
    publicar)
      [ -f "$arquivo" ] || { echo "arquivo não encontrado: $arquivo"; exit 2; }
      tamanho=$(stat -c%s "$arquivo")
      codigo=$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$HOSTINGER_TUS_URL/$destino?override=true" \
        "${autorizacao[@]}" -H "Tus-Resumable: 1.0.0" -H "Upload-Length: $tamanho" -H "Upload-Offset: 0")
      [ "$codigo" = "201" ] || { echo "falha ao iniciar envio de $destino (HTTP $codigo)"; exit 1; }
      codigo=$(curl -sS -o /dev/null -w '%{http_code}' -X PATCH "$HOSTINGER_TUS_URL/$destino?override=true" \
        "${autorizacao[@]}" -H "Tus-Resumable: 1.0.0" -H "Content-Type: application/offset+octet-stream" \
        -H "Upload-Offset: 0" --data-binary "@$arquivo")
      [ "$codigo" = "204" ] || { echo "falha ao enviar $destino (HTTP $codigo)"; exit 1; }
      echo "publicado: $destino ($tamanho bytes)"
      ;;
  esac
done
