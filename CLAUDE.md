# Site da Cruz Vermelha Brasileira Rio de Janeiro — entrada para agentes

Lido automaticamente pelo Claude Code em toda sessão. É um resumo com ponteiros:
a fonte é o [`README.md`](./README.md) (um diário cronológico de 394 KB; **não
leia inteiro**, procure pelo título da seção com `grep -n '^## ' README.md`) e
os documentos de [`docs/`](./docs). Se este arquivo e o README discordarem, vale
o README mais recente, e este arquivo deve ser corrigido no mesmo PR.

Escrito em 23/11/2026, a partir do commit `d15bdc0`.

## O que é

O site `cruzvermelhariodejaneiro.org`: HTML estático, PHP 8.3 e MySQL (tabelas
`mcp_*`) na hospedagem compartilhada da Hostinger. Sem framework, sem build de
JavaScript, sem CI. `site/` é o espelho do `public_html`.

## Regras que mais custam caro

1. **A `main` não é o que está no ar.** Merge não publica. Publica-se com
   `scripts/publicar_hostinger.sh`, arquivo a arquivo, com credencial temporária
   da Hostinger e com o OK do responsável. O roteiro: guardar o que está no ar
   (`--copiar-do-ar`), enviar na ordem da lista `scripts/publicacao-*.txt`,
   limpar o cache do site, rodar `scripts/conferir_publicacao.sh`. Para
   desfazer: `scripts/desfazer_publicacao.sh <commit> <lista>`. O cabeçalho do
   próprio script explica cada passo.
2. **O cabeçalho da lista `scripts/publicacao-*.txt` é a fonte mais atual** do
   que foi publicado. Títulos do README ficam velhos ("NÃO publicado" pode já
   estar no ar). Antes de afirmar o estado de uma página, confira no ar.
3. **Credenciais: só nomes, nunca valores.** Os `config*.php` vivem só no
   servidor e estão no `.gitignore`; o script de publicação recusa enviá-los.
   `site/institucional.json` tem notas internas e nunca vai ao ar.
4. **Não edite HTML gerado.** Matrícula, checkout, `/doe/`, políticas, páginas
   institucionais e guias, versões em inglês e espanhol, 404, bio, verificar e
   sitemaps saem de `scripts/gerar_*.py` a partir de JSON (`cursos.json`,
   `oferta.json`, `politicas.json`, `institucional.json`, `idiomas.json`). Mude
   o JSON ou o gerador e gere de novo, na ordem da seção "Ordem de geração" do
   README (`chat_widget.py` por último).
5. **A home (`site/index.html`) é a matriz**: CSS, cabeçalho, rodapé e bloco de
   medição são copiados dela pelos geradores. Feitos à mão, além dela: os
   `.htaccess`, `site/chat/chat.js` e todo o PHP. Um `.htaccess` errado derruba
   o site, e o script de publicação nunca apaga `.htaccess`.
6. **Pastas que não são deste repositório:** `/noticias/`, `/acoes/`,
   `/acervo/`, `/transparencia/`, `/canais-oficiais/`, os arquivos da trilha em
   `/verificar/` e o `sitemap.xml` no ar são gravados pelo Palácio Virtual por
   FTP. Não escreva neles daqui. O Palácio também regrava o `robots.txt`:
   o `site/robots.txt` daqui tem de ser igual ao que `lib/site/sitemap.ts` de
   lá gera, senão a próxima notícia publicada desfaz a sua mudança.

## Onde fica o código de servidor

Tudo em `site/matricula-cursos-presenciais/api/` (`lib.php` e `lib/*.php`;
`lib/db.php` migra o banco sozinho quando a `MCP_DB_VERSAO` muda):

- **Matrícula e checkout**: pagamento pela ÚnicoPag (o postback é só gatilho; o
  status vem da reconsulta). Depois de pago, `lib/escola.php` matricula na
  plataforma da escola pela RPC `matricula_rapida`.
- **Doação**: `site/doe/api/` reaproveita o mesmo bootstrap.
- **Portal da secretaria** (`painel.php`), **chat e atendimento**
  (`contato.php`, `atendimento.php`), **ponto da sede** (`ponto.php`),
  **cadastro de voluntários**.

Cada sistema tem o seu documento em `docs/` (`turmas.md`, `doacao-mensal.md`,
`virada-donaty.md`, `atendimento.md`, `voluntariado.md`, `ponto-comunicacao.md`,
`rastreamento.md`, `financeiro.md`, `google-drive.md`, `docs/escola/`).

## Ligações com o Palácio Virtual

O Palácio é o repositório `redacao-cruzvermelhariodejaneiro`
(`palacio.cruzvermelhariodejaneiro.org`; o endereço antigo `redacao.` continua
respondendo em `/api`). O panorama completo, com os outros projetos da Cruz
Vermelha, está em `docs/ECOSSISTEMA.md` daquele repositório. Daqui, o que não
pode quebrar:

| O quê | Contrato |
| --- | --- |
| Newsletter e pedido de material dos guias | Os formulários mandam para `/api/newsletter/inscrever` do Palácio. O texto do aceite tem de ser idêntico ao que o Palácio confere |
| `/verificar/` | Só a tela é daqui; consulta `POST /api/publico/verificar` do Palácio |
| Fichas de voluntariado | O Palácio lê e decide por `api/redacao-voluntarios.php`, com Bearer `REDACAO_TOKEN` (em `api/config-redacao.php`, só no servidor). O site avisa ficha nova em `REDACAO_AVISO_URL` |
| Bloco de medição e `traducoes/en/mapa-hreflang.json` | O Palácio guarda cópia dos dois. Mudou aqui, avise para mudar lá |

Mudança em qualquer linha dessa tabela pede os dois lados combinados, porque
cada um é publicado por um caminho e num momento diferente.

## Regras de conteúdo

- O nome é sempre **Cruz Vermelha Brasileira Rio de Janeiro**. "Cruz Vermelha
  RJ" não vale em texto visível (README, "Convenção de nome").
- O domínio antigo da filial não é citado, linkado nem redirecionado (README,
  mesma seção).
- Os cursos pagos são da escola, e isso é dito às claras. Prometa só o que o
  sistema cumpre.
- Toda afirmação de saúde ou de segurança tem fonte oficial com link
  (`docs/padrao-guias-gratuitos.md`).
- Medição só com consentimento (LGPD). Mudou o que um formulário coleta, muda a
  política de privacidade em português, inglês e espanhol.
- Textos, comentários e commits em português. Confira toda página em 390 px de
  largura, no celular.

## Antes de publicar

Conforme o que mudou: `php scripts/testar_checkout.php`,
`php scripts/testar_doacao.php`, `php scripts/testar_voluntarios.php`,
`scripts/validar_jsonld.py`, `scripts/conferir_links.py --local`,
`scripts/auditar_seo.py`, `scripts/conferir_ad_grants.py`. Depois de publicar,
`scripts/gerar_sitemaps.py`. Relate o que passou, o que falhou e o que não foi
testado.
