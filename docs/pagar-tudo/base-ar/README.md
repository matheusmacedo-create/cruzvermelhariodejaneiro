# Base do ar para a geração do pagar tudo

Cópias do que estava no ar em 08/10/2026 (baixadas com curl), usadas por `scripts/pagar_tudo/gerar.sh`:

| Arquivo | O que é |
|---|---|
| `home.html` | a home do ar: cabeçalho, rodapé, CSS e bloco de medição das páginas geradas (a `site/index.html` do repositório tem mudanças que não estão no ar) |
| `chat.js` | o `chat.js` do ar (`?v=aa00cac6d2`); o publicado no pagar tudo é este com só as três respostas trocadas |
| `faq-home.base.json` | a `site/faq-home.json` do repositório antes das três respostas |
| `politicas.base.json` | a `site/politicas.json` do repositório antes dos textos do pagar tudo (gera as 6 políticas iguais às do ar) |
| `politica-*.html` | /reembolso/, /termos/ e as traduções do ar, para a conferência linha a linha |

`SHA256SUMS` confere os arquivos (o `gerar.sh` para se não baterem). Antes de gerar para publicar, `gerar.sh conferir-ar`
baixa tudo de novo e para se o ar tiver mudado: nesse caso, atualizar estes arquivos e o `SHA256SUMS` com o que está no ar
e gerar de novo (senão a publicação desfaria a mudança).
