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
api/recuperacao.php|200|Entrar pelo Portal da secretaria
api/avisos.php?u=1.abc|404|Este link não vale mais
api/avisos.php?rw=1.abc|404|Este link não vale mais
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
api/turmas.php|405|
LISTA

# Recuperação por WhatsApp (30/10/2026): sem sessão, só o link de entrada; nenhum número nem pessoa na página.
codigo=$(pedir "$m/api/recuperacao.php")
if [ "$codigo" = 200 ] && ! grep -qE 'No fluxo agora|Mensagens enviadas|Recuperadas|Pessoas no fluxo|Ligar o recebimento' "$tmp" \
  && grep -qi '^x-robots-tag:.*noindex' "$tmp.cab"; then ok "api/recuperacao.php sem sessão não mostra dados (noindex)"; else falha "api/recuperacao.php sem sessão → $codigo, mostrou dados ou faltou noindex"; fi
# O webhook da Kapso sem assinatura: 401 (com 503 seria o banco; com 404, o arquivo não subiu).
codigo=$(pedir "$m/api/kapso-webhook.php")
if [ "$codigo" = 401 ]; then ok "api/kapso-webhook.php sem assinatura → 401"; else falha "api/kapso-webhook.php sem assinatura → $codigo (esperado 401)"; fi

# Links curtos da recuperação (01/11/2026): api/ir.php/<w|e>/<curso>/<campanha> → 302 para o checkout com a query de sempre;
# inválido → 302 para a página de matrícula. Se der 404, o servidor não repassa o caminho depois do .php (PATH_INFO) e os
# botões novos não funcionam: avise antes da rodada das 8h07.
redireciona() { # caminho | Location esperado
  codigo=$(pedir "$m/$1")
  local destino
  destino=$(grep -i '^location:' "$tmp.cab" | head -1 | cut -d' ' -f2- | tr -d '\r')
  if [ "$codigo" = 302 ] && [ "$destino" = "$2" ]; then ok "$1 → 302 $destino"; else falha "$1 → $codigo ${destino:-sem Location} (esperado 302 $2)"; fi
}
redireciona 'api/ir.php/w/puncao-venosa/rec1' '/matricula-cursos-presenciais/checkout/?curso=puncao-venosa&utm_source=whatsapp&utm_medium=recuperacao&utm_campaign=rec1'
redireciona 'api/ir.php/e/primeiros-socorros-basico/rec3cupom' '/matricula-cursos-presenciais/checkout/?curso=primeiros-socorros-basico&via=agenda&cupom=NOV15&utm_source=email&utm_medium=recuperacao&utm_campaign=rec3cupom'
redireciona 'api/ir.php/w/cursos/rec2' '/matricula-cursos-presenciais/?utm_source=whatsapp&utm_medium=recuperacao&utm_campaign=rec2'
redireciona 'api/ir.php/w/curso-que-nao-existe/rec1/s1.abc.0123456789abcdef' '/matricula-cursos-presenciais/'
redireciona 'api/ir.php' '/matricula-cursos-presenciais/'
# Pré-preenchimento sem token (ou com token que não vale): {} com 200 e sem cache.
codigo=$(pedir "$m/api/recuperacao-dados.php?t=s1.abc.0123456789abcdef")
if [ "$codigo" = 200 ] && [ "$(cat "$tmp")" = '{}' ] && grep -qi '^cache-control:.*no-store' "$tmp.cab"; then ok "api/recuperacao-dados.php com token inválido → {} (no-store)"; else falha "api/recuperacao-dados.php → $codigo $(head -c 80 "$tmp")"; fi
# Link antigo com a query codificada: o 404 da raiz precisa trazer o script que leva ao checkout certo.
codigo=$(pedir "$m/checkout/%3Fcurso%3Dpuncao-venosa%26utm_campaign%3Drec1")
if grep -q "decodeURIComponent(p)" "$tmp"; then ok "checkout/%3Fcurso… → $codigo com o 404 que leva ao checkout"; else falha "checkout/%3Fcurso… → $codigo sem o script do 404.html novo"; fi

echo "== Bloqueados"
for caminho in api/config.php api/config-escola.php api/config.example.php api/lib.php \
  api/lib/avisos.php api/lib/db.php api/lib/config.php api/lib/turmas.php api/lib/recuperacao_whatsapp.php api/lib/recuperacao_email.php api/comparecimentos.php api/lembretes.php; do
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
