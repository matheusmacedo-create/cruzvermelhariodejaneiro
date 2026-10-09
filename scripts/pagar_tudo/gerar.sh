#!/usr/bin/env bash
# Pagar tudo: geração das páginas com a home e o chat.js QUE ESTÃO NO AR (docs/pagar-tudo/base-ar/).
#
# Por quê: a site/index.html e a site/faq-home.json do repositório têm mudanças que não estão no ar (a Transparência, por
# exemplo). O cabeçalho, o rodapé, o CSS e o bloco de medição das páginas geradas saem da home; o chat.js publicado tem
# de ser o do ar com só as três respostas do pagar tudo; /reembolso/ e /termos/ têm de ser os do ar com só os nossos
# trechos. Os arquivos-base ficam no repositório (com o sha256 em SHA256SUMS), então a geração funciona igual antes e
# depois do commit. A site/index.html do repositório volta ao Git no fim, mesmo se um gerador falhar.
#
# Uso: scripts/pagar_tudo/gerar.sh [conferir-ar] [chat] [pagina] [checkout] [politicas] [passo3]
#      (sem alvo: chat pagina checkout)
#   conferir-ar  baixa do ar (curl) a home, o chat.js e as 6 políticas e para se algum não for o da base: nesse caso, o ar
#                mudou depois da base, e gerar daqui desfaria a mudança. Rodar antes de cada geração de publicação.
#   chat         site/chat/chat.js = o chat.js do ar + as três respostas (trocar_respostas.py); a site/faq-home.json do
#                repositório com as mesmas três. Nunca regera o chat.js a partir da faq-home.json do repositório.
#   pagina       scripts/gerar_matricula_presencial.py (CHAT_JS_FIXO=1: não reescreve a lista de cursos do chat.js).
#   checkout     scripts/gerar_checkout.py (checkout, pendente, parabens, horarios, comparecimento, conferir, ponto).
#   politicas    /reembolso/ e /termos/ (PT, EN, ES) = a politicas.json base + os textos do pagar tudo (aplicar_textos.py),
#                gerados com a home do ar; privacidade e cookies voltam ao Git. Exige POLITICAS_DATA=AAAA-MM-DD.
#   passo3       as telas do checkout do passo 3 (opção 1 escondida, nota legal de hoje, chat.js?v= do ar) e o oferta.json
#                com "sem_turma": {} em $PASSO3_DIR (padrão /tmp/pagar-tudo-passo3). A worktree volta ao estado final.
#
# Variáveis: PLANO_COMPLETO_HTML=1 (passo 5: opção 1 visível e marcada no HTML, nota legal nova), VENDA_SEM_TURMA_HTML=1
# (passo 5b: ficha, FAQ 7, chat e políticas da venda sem turma), PARCELADO_HTML=1 (passo 6: chat e políticas do
# parcelado; a página segue o "parcelado_no_ar" do oferta.json), POLITICAS_DATA (data da publicação), PASSO3_DIR.
#
# Passo 5:  scripts/pagar_tudo/gerar.sh conferir-ar && PLANO_COMPLETO_HTML=1 POLITICAS_DATA=AAAA-MM-DD scripts/pagar_tudo/gerar.sh chat pagina checkout politicas
# Passo 5b: ... PLANO_COMPLETO_HTML=1 VENDA_SEM_TURMA_HTML=1 POLITICAS_DATA=AAAA-MM-DD scripts/pagar_tudo/gerar.sh chat pagina checkout politicas
# Passo 6:  ... PLANO_COMPLETO_HTML=1 [VENDA_SEM_TURMA_HTML=1] PARCELADO_HTML=1 POLITICAS_DATA=AAAA-MM-DD scripts/pagar_tudo/gerar.sh chat pagina checkout politicas
#
# A referência chat.js?v= de cada página é o hash do site/chat/chat.js no momento da geração: rode "chat" antes (ou
# junto) para as páginas apontarem para o chat.js que vai ao ar. No fim, o script confere e mostra o hash.
set -euo pipefail

R=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
FERR=$R/scripts/pagar_tudo
BASE=$R/docs/pagar-tudo/base-ar
HOME_AR=$BASE/home.html
CHAT_AR=$BASE/chat.js
SITE_AR=${SITE_AR:-https://cruzvermelhariodejaneiro.org}
PASSO3_DIR=${PASSO3_DIR:-/tmp/pagar-tudo-passo3}
POLITICAS=(reembolso termos en/refunds en/terms es/reembolsos es/terminos)
M=site/matricula-cursos-presenciais

cd "$R"
(cd "$BASE" && sha256sum -c --quiet SHA256SUMS) || { echo "ERRO: os arquivos-base de $BASE não batem com o SHA256SUMS" >&2; exit 1; }
alvos=("$@")
[ ${#alvos[@]} -gt 0 ] || alvos=(chat pagina checkout)

restaurar_home() { git checkout -- site/index.html 2>/dev/null || true; }
CHAT_GUARDADO=""
restaurar() { restaurar_home; if [ -n "$CHAT_GUARDADO" ] && [ -f "$CHAT_GUARDADO" ]; then cp "$CHAT_GUARDADO" site/chat/chat.js; rm -f "$CHAT_GUARDADO"; fi; }
trap restaurar EXIT

for alvo in "${alvos[@]}"; do
  case "$alvo" in
    conferir-ar)
      tmp=$(mktemp -d)
      ok=1
      conferir() {  # $1 = caminho no ar, $2 = arquivo-base
        if ! curl -sS --fail -o "$tmp/x" "$SITE_AR/$1"; then echo "conferir-ar: não baixou $SITE_AR/$1" >&2; ok=0; return; fi
        if cmp -s "$tmp/x" "$2"; then echo "conferir-ar: $1 = base"; else echo "conferir-ar: $1 MUDOU no ar (sha $(sha256sum < "$tmp/x" | cut -c1-10); base $(sha256sum < "$2" | cut -c1-10))" >&2; ok=0; fi
      }
      conferir "" "$HOME_AR"
      conferir "chat/chat.js?v=$(sha256sum < "$CHAT_AR" | cut -c1-10)" "$CHAT_AR"
      for pg in "${POLITICAS[@]}"; do conferir "$pg/" "$BASE/politica-$(echo "$pg" | tr '/' '_').html"; done
      rm -rf "$tmp"
      [ "$ok" = 1 ] || { echo "conferir-ar: o ar mudou depois da base. Atualize docs/pagar-tudo/base-ar (e o SHA256SUMS) com o que está no ar e gere de novo." >&2; exit 1; }
      ;;
    chat)
      python3 -I "$FERR/trocar_respostas.py" "$CHAT_AR" "$BASE/faq-home.base.json" site/faq-home.json site/chat/chat.js
      echo "chat: site/chat/chat.js = o do ar + as três respostas ($(sha256sum site/chat/chat.js | cut -c1-10))"
      ;;
    pagina|checkout)
      cp "$HOME_AR" site/index.html
      if [ "$alvo" = pagina ]; then
        CHAT_JS_FIXO=1 python3 scripts/gerar_matricula_presencial.py
      else
        python3 scripts/gerar_checkout.py
      fi
      restaurar_home
      ;;
    politicas)
      : "${POLITICAS_DATA:?defina POLITICAS_DATA=AAAA-MM-DD (a data da publicação: \"Atualizada em\" de /reembolso/ e /termos/)}"
      python3 -I "$FERR/aplicar_textos.py" "$BASE/politicas.base.json" site/politicas.json
      cp "$HOME_AR" site/index.html
      python3 scripts/gerar_politicas.py > /dev/null
      restaurar_home
      git checkout -- site/privacidade site/cookies site/en/privacy site/en/cookies site/es/privacidad site/es/cookies
      H=$(sha256sum site/chat/chat.js | cut -c1-10)
      for pg in "${POLITICAS[@]}"; do
        sed -i -E "s/chat\.js\?v=[0-9a-f]{10}/chat.js?v=$H/g" "site/$pg/index.html"
        base_ar="$BASE/politica-$(echo "$pg" | tr '/' '_').html"
        n=$(diff <(sed 's/></>\n</g' "$base_ar") <(sed 's/></>\n</g' "site/$pg/index.html") | grep -c '^[<>]' || true)
        printf 'politicas: %-14s chat.js?v=%s  %s linhas diferentes do ar\n' "$pg" "$H" "$n"
      done
      ;;
    passo3)
      CHAT_GUARDADO=$(mktemp)
      cp site/chat/chat.js "$CHAT_GUARDADO"
      cp "$CHAT_AR" site/chat/chat.js
      cp "$HOME_AR" site/index.html
      env -u PLANO_COMPLETO_HTML python3 scripts/gerar_checkout.py > /dev/null
      restaurar_home
      rm -rf "$PASSO3_DIR"
      for pg in checkout pendente parabens horarios comparecimento conferir; do
        mkdir -p "$PASSO3_DIR/$M/$pg"; cp "$M/$pg/index.html" "$PASSO3_DIR/$M/$pg/index.html"
      done
      python3 -I -c 'import json,sys; o=json.load(open(sys.argv[1])); o["sem_turma"]={}; o["parcelado_no_ar"]=False; open(sys.argv[2],"w").write(json.dumps(o, ensure_ascii=False, indent=2)+"\n")' "$M/oferta.json" "$PASSO3_DIR/$M/oferta.json"
      # a worktree volta ao estado final: o chat.js novo e as telas geradas com ele (e com o PLANO_COMPLETO_HTML da chamada)
      cp "$CHAT_GUARDADO" site/chat/chat.js; rm -f "$CHAT_GUARDADO"; CHAT_GUARDADO=""
      cp "$HOME_AR" site/index.html
      python3 scripts/gerar_checkout.py > /dev/null
      restaurar_home
      echo "passo3: $PASSO3_DIR ($(find "$PASSO3_DIR" -type f | wc -l) arquivos; chat.js?v=$(grep -o 'chat\.js?v=[0-9a-f]*' "$PASSO3_DIR/$M/checkout/index.html" | head -1 | cut -d= -f2), opção 1 $(grep -q 'id="ck-plano-completo" hidden' "$PASSO3_DIR/$M/checkout/index.html" && echo escondida || echo VISÍVEL), nota legal $(grep -q 'id="ck-legal-novo" hidden' "$PASSO3_DIR/$M/checkout/index.html" && echo 'de hoje' || echo NOVA))"
      ;;
    *) echo "alvo desconhecido: $alvo" >&2; exit 1 ;;
  esac
done

# Conferências: a home do repositório voltou e o chat.js?v= das páginas geradas é o do site/chat/chat.js.
git diff --quiet -- site/index.html || { echo "ERRO: site/index.html ficou diferente do repositório" >&2; exit 1; }
hash_chat=$(sha256sum site/chat/chat.js | cut -c1-10)
for p in $M/index.html $M/{checkout,pendente,parabens,horarios,comparecimento,conferir}/index.html; do
  [ -f "$p" ] || continue
  ref=$(grep -o 'chat\.js?v=[0-9a-f]*' "$p" | head -1 | cut -d= -f2)
  [ "$ref" = "$hash_chat" ] && est=ok || est="DIFERENTE (chat.js atual: $hash_chat)"
  echo "$p: chat.js?v=$ref $est"
done
[ "$hash_chat" = "$(sha256sum < "$CHAT_AR" | cut -c1-10)" ] && echo "aviso: o site/chat/chat.js é o do ar sem as três respostas; rode o alvo chat" >&2 || true
