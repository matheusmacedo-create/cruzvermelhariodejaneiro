#!/usr/bin/env bash
# Copia o site ao vivo para o acervo da filial no Cloudflare R2 (bucket cvrj-acervo, pasta site/).
#
# Cada rodada vira uma pasta site/AAAA-MM-DD/ com as páginas e os arquivos exatamente como estavam
# no ar (as notícias publicadas pela Redação incluídas), mais um MANIFESTO.sha256 para conferir com
# `sha256sum -c` e um SOBRE.txt. A pasta site/ do bucket tem trava: nada se apaga nem se troca por
# 30 dias depois do envio. Só entra o que o público vê: o que não tem link (como /verificar/) e o
# que o servidor não entrega (config.php, api/) fica de fora por construção.
#
# Uso: R2_ACCOUNT_ID=… R2_ACCESS_KEY_ID=… R2_SECRET_ACCESS_KEY=… scripts/copiar_site_para_o_acervo.sh
# Opcionais: R2_BUCKET_ACERVO (cvrj-acervo), SITE (https://cruzvermelhariodejaneiro.org/).
set -euo pipefail

SITE=${SITE:-https://cruzvermelhariodejaneiro.org/}
BUCKET=${R2_BUCKET_ACERVO:-cvrj-acervo}
R2_ENDPOINT=${R2_ENDPOINT:-${R2_ACCOUNT_ID:+https://$R2_ACCOUNT_ID.r2.cloudflarestorage.com}}
R2_ENDPOINT=${R2_ENDPOINT%/}
for v in R2_ENDPOINT R2_ACCESS_KEY_ID R2_SECRET_ACCESS_KEY; do
  [ -n "${!v:-}" ] || { echo "Falta a variável $v." >&2; exit 1; }
done
dominio=$(printf '%s' "$SITE" | sed -E 's#^https?://([^/]+).*#\1#')
dia=$(TZ=America/Sao_Paulo date +%Y-%m-%d)
pasta="site/$dia"

umask 077
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
printf 'user = "%s:%s"\n' "$R2_ACCESS_KEY_ID" "$R2_SECRET_ACCESS_KEY" > "$tmp/r2.cfg"
codificar() { python3 -c 'import sys, urllib.parse; print(urllib.parse.quote(sys.argv[1], safe="/"))' "$1"; }
r2() { curl -sS --retry 3 --retry-all-errors --connect-timeout 20 --max-time 300 -K "$tmp/r2.cfg" --aws-sigv4 "aws:amz:auto:s3" "$@" < /dev/null; }
enviar() { # arquivo chave tipo
  local sha codigo
  sha=$(sha256sum "$1" | cut -d' ' -f1)
  codigo=$(r2 -o "$tmp/resposta.xml" -w '%{http_code}' -H "x-amz-content-sha256: $sha" -H "content-type: $3" \
    --upload-file "$1" "$R2_ENDPOINT/$BUCKET/$(codificar "$2")")
  [ "$codigo" = 200 ] || { echo "O R2 recusou $2 (HTTP $codigo $(grep -o '<Code>[^<]*' "$tmp/resposta.xml" | head -1 | cut -c7-))." >&2; return 1; }
}
tipo() {
  case "${1%%\?*}" in
    *.html|*/) echo 'text/html; charset=utf-8' ;; *.css) echo 'text/css; charset=utf-8' ;; *.js) echo 'text/javascript; charset=utf-8' ;;
    *.json) echo 'application/json' ;; *.xml) echo 'application/xml' ;; *.txt) echo 'text/plain; charset=utf-8' ;;
    *.webp) echo 'image/webp' ;; *.png) echo 'image/png' ;; *.jpg|*.jpeg) echo 'image/jpeg' ;; *.gif) echo 'image/gif' ;;
    *.svg) echo 'image/svg+xml' ;; *.ico) echo 'image/x-icon' ;; *.pdf) echo 'application/pdf' ;;
    *.woff2) echo 'font/woff2' ;; *.woff) echo 'font/woff' ;; *.mp4) echo 'video/mp4' ;; *) echo 'application/octet-stream' ;;
  esac
}

if [ "$(r2 -o /dev/null -I -w '%{http_code}' "$R2_ENDPOINT/$BUCKET/$pasta/MANIFESTO.sha256")" = 200 ]; then
  echo "Já existe uma cópia de hoje em $BUCKET/$pasta/ (e ela não pode ser trocada)." >&2
  exit 1
fi

echo "Copiando $SITE…"
( cd "$tmp" && wget --mirror --page-requisites --no-parent --restrict-file-names=nocontrol --no-verbose \
    --wait=0.2 --random-wait --user-agent='AcervoCVB-RJ/1.0 (copia do proprio site)' \
    --domains="$dominio,www.$dominio" -e robots=off "$SITE" 2> "$tmp/wget.log" ) || {
  # wget sai com 8 quando algum link dá erro no servidor; a cópia segue com o que respondeu.
  [ $? -eq 8 ] || { tail -5 "$tmp/wget.log" >&2; exit 1; }
}
raiz="$tmp/$dominio"
[ -f "$raiz/index.html" ] || { echo "A página inicial não veio; nada foi enviado." >&2; exit 1; }
( cd "$raiz" && find . -type f -printf '%P\n' | LC_ALL=C sort > "$tmp/lista.txt" )
( cd "$raiz" && while IFS= read -r f; do sha256sum -- "$f"; done < "$tmp/lista.txt" > "$tmp/MANIFESTO.sha256" )
quantos=$(wc -l < "$tmp/lista.txt")
bytes=$(cd "$raiz" && du -sb . | cut -f1)

echo "Enviando $quantos arquivo(s) ($bytes bytes) para $BUCKET/$pasta/…"
while IFS= read -r f; do
  enviar "$raiz/$f" "$pasta/$f" "$(tipo "$f")"
done < "$tmp/lista.txt"
cat > "$tmp/SOBRE.txt" <<EOF
Cópia do site $SITE feita em $(TZ=America/Sao_Paulo date '+%d/%m/%Y às %H:%M') (horário de Brasília).

$quantos arquivo(s), $bytes bytes, copiados do ar com wget, seguindo os links a partir da página
inicial: é o que o público via naquele dia. Páginas sem link e arquivos que o servidor não
entrega ficam de fora por construção.

Para conferir que nada mudou desde então: baixe a pasta e rode, dentro dela,
  sha256sum -c MANIFESTO.sha256
Nomes com "?" guardam o endereço completo da página (por exemplo, index.html?curso=...).

Gerado por scripts/copiar_site_para_o_acervo.sh (repositório do site).
EOF
enviar "$tmp/SOBRE.txt" "$pasta/SOBRE.txt" 'text/plain; charset=utf-8'
enviar "$tmp/MANIFESTO.sha256" "$pasta/MANIFESTO.sha256" 'text/plain; charset=utf-8'
echo "Pronto: $BUCKET/$pasta/ ($quantos arquivo(s))."
