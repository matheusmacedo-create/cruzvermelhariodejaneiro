# escola/ — kit de SEO para a Escola de Educação e Saúde CVB-RJ

A escola (`https://escola.cursoscruzvermelha.org`, app Node/Express no Render) não tinha
`robots.txt` nem `sitemap.xml` (os dois respondiam 404), não declarava `canonical` e serve
o mesmo conteúdo em três hosts:

- `https://escola.cursoscruzvermelha.org` — **endereço oficial** (domínio independente,
  mantido separado por resiliência);
- `https://escola.cruzvermelhariodejaneiro.org` — apelido sob o domínio principal;
- `https://escola-cruz-vermelha-1.onrender.com` — endereço padrão do Render.

Sem canonical, o Google divide a indexação entre os três. Este kit resolve isso sem tirar
a resiliência: nada é redirecionado; apenas declaramos qual endereço é o oficial.

## Arquivos

| Arquivo | Para quê |
| --- | --- |
| `robots.txt` | Libera o site, bloqueia login/cadastro/minha-conta/inscrever/api e aponta o sitemap. |
| `sitemap.xml` | Home, catálogo, "Sobre" e as 7 páginas de curso publicadas em 16/09/2026. |
| `seo.js` | Router Express: `res.locals.canonicalUrl`, `X-Robots-Tag: noindex` no host do Render e nas rotas privadas, `/robots.txt` e `/sitemap.xml` dinâmicos (lê os cursos do banco, com fallback para a lista fixa). |
| `head-seo.html` | Trecho para o `<head>` do layout: canonical, meta description, Open Graph e JSON-LD `EducationalOrganization` ligado à instituição. |

## Caminho rápido (sem código)

Copie `robots.txt` e `sitemap.xml` para a pasta de arquivos públicos do app (a mesma que
serve `/style.css` e `/js/main.js`) e faça o deploy. Em minutos
`https://escola.cursoscruzvermelha.org/robots.txt` e `/sitemap.xml` passam a responder.
Quando entrarem cursos novos, rode `python3 scripts/gerar_sitemap_escola.py` na raiz do
repositório e copie o `sitemap.xml` de novo.

## Caminho completo (canonical + sitemap dinâmico)

1. Copie `seo.js` para o projeto da escola.
2. No `app.js`/`server.js`, **antes** das rotas do site:

   ```js
   const { criarRoteadorSeo } = require('./seo');
   app.use(criarRoteadorSeo({
     // opcional: cursos publicados a partir do banco; cada item com `id` (uuid) e `atualizadoEm` (Date)
     listarCursosPublicos: async () => cursosRepository.listarPublicados(),
   }));
   ```

3. No layout, dentro do `<head>`, cole o conteúdo de `head-seo.html` (ajuste a sintaxe do
   motor de templates para imprimir `canonicalUrl`).
4. Se quiser outro endereço oficial no futuro, mude só a variável de ambiente
   `ESCOLA_ORIGEM`.
5. Deploy no Render e teste:

   ```bash
   curl -I https://escola.cursoscruzvermelha.org/robots.txt      # 200 text/plain
   curl -s https://escola.cursoscruzvermelha.org/sitemap.xml | head
   curl -s https://escola.cruzvermelhariodejaneiro.org/cursos | grep canonical   # aponta para cursoscruzvermelha.org
   curl -I https://escola-cruz-vermelha-1.onrender.com/ | grep -i x-robots        # noindex
   ```

## Search Console

1. Adicione a propriedade de prefixo `https://escola.cursoscruzvermelha.org/`. A
   verificação mais simples é a meta tag `google-site-verification` no `<head>` do layout
   (pode ficar junto do `head-seo.html`).
2. Envie o sitemap `https://escola.cursoscruzvermelha.org/sitemap.xml`.
3. Enquanto o app não servir o sitemap, existe a cópia
   `https://cruzvermelhariodejaneiro.org/sitemap-escola.xml`, hospedada no domínio
   principal. O Google só a aceita para a escola se as duas propriedades estiverem
   verificadas na mesma conta.
