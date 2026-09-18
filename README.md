# Cruz Vermelha Brasileira – Rio de Janeiro · site institucional e ligação com a escola

Este repositório guarda a parte **institucional (social)** da presença digital da
CVB-RJ: as páginas estáticas do site principal `cruzvermelhariodejaneiro.org`, o kit de
SEO da escola e os scripts para publicar. A Redação, a intranet e os demais sistemas são
projetos separados e têm seus próprios repositórios.

## Mapa do ecossistema (levantado em 16/09/2026)

| Endereço | O que é | Onde roda | Observações |
| --- | --- | --- | --- |
| `cruzvermelhariodejaneiro.org` (e `www`) | Site institucional: home, cursos, doação, equipe, campanha do agasalho, notícias, termos, privacidade | Hostinger, hospedagem compartilhada (conta `u448697994`, plano Business, LiteSpeed, SSL ativo com redirecionamento HTTPS) | HTML estático. As páginas mantidas à mão estão em `site/`. `noticias/`, `termos/`, `privacidade/`, `sitemap.xml` e `robots.txt` são gerados pela **Redação** via FTP. |
| `cruzvermelhariodejaneiro.org/matricula-cursos-presenciais/` | **Matrícula cursos presenciais**: catálogo dos 7 cursos publicados na escola, inscrição de R$ 99, cabeçalho e rodapé da home | Hostinger, mesma pasta do site (`public_html/matricula-cursos-presenciais/`) | Página gerada por `scripts/gerar_matricula_presencial.py` a partir de `cursos.json` (sincronizado do catálogo da escola por `scripts/sincronizar_catalogo.py`). Definição do produto em `docs/briefing-matricula-cursos-presenciais.md`. |
| `escola.cursoscruzvermelha.org` | **Escola de Educação e Saúde CVB-RJ**: catálogo de cursos, turmas, matrícula, login de alunos | Render (`escola-cruz-vermelha-1.onrender.com`), atrás da Cloudflare | App Node/Express (cookie `escola.sid`). Domínio **separado de propósito**, por resiliência após uma queda do domínio principal. Também responde em `escola.cruzvermelhariodejaneiro.org` (CNAME já existente). |
| `cursoscruzvermelha.org` (raiz e `www`) | Domínio da escola | DNS na Hostinger, mas em **outra conta** (não aparece nesta) | A raiz mostra a página "Parked Domain" da Hostinger com `robots.txt` bloqueando tudo. Só o subdomínio `escola.` está em uso. |
| `redacao.cruzvermelhariodejaneiro.org` | Redação: central de comunicação, publica notícias no site principal | Vercel (Next.js) + Supabase | Projeto separado (`redacao-cruzvermelhariodejaneiro`). Regenera `sitemap.xml` e `robots.txt` do site principal a cada notícia publicada. |
| `doar.cruzvermelhariodejaneiro.org` | Página de doação | Vercel | |
| `projetocores.cruzvermelhariodejaneiro.org` | Landing page "Impacto das Cores" | Hostinger (`public_html/projetocores/`) | Aponta para a página de doação. |
| `puncaovenosav1.cruzvermelhariodejaneiro.org` | Landing page do curso de Punção Venosa | Vercel | Projeto `puncaovenosa-fullautomatic`. |
| `links.` e `link.cruzvermelhariodejaneiro.org` | "CVB Links", central de links em PHP/MySQL | Hostinger (`public_html/links/` e `/link/`) | `links.` está redirecionando para `install.php` (instalador exposto, ver "Pontos de atenção"). `link.` mostra a página padrão. |
| `cruzvermelhariodejaneiro.com` / `.online` / `cruzvermelhaitaguai.org` | Domínios registrados na mesma conta | Hostinger | `.online` estacionado; `.com` e `cruzvermelhaitaguai.org` com pasta vazia. |
| E-mail `@cruzvermelhariodejaneiro.org` | Caixas na Hostinger Mail; envios transacionais via Resend (`@`, `noticias.`, `info.`, `parceria.`) | Hostinger + Resend | DMARC em `p=none` (só monitoramento). |

Search Console: o domínio `cruzvermelhariodejaneiro.org` já está verificado por registro TXT
(`google-site-verification`), o que cobre todos os seus subdomínios. A escola
(`escola.cursoscruzvermelha.org`) precisa de verificação própria.

## Como o site principal se liga à escola

Já no ar (16/09/2026):

1. **Atalho curto**: `https://cruzvermelhariodejaneiro.org/escola` responde **301** para
   `https://escola.cursoscruzvermelha.org/` (redirecionamento criado na Hostinger). Serve
   para posts, bio das redes, impressos e WhatsApp.
2. **Apelido sob o domínio principal**: `https://escola.cruzvermelhariodejaneiro.org/` já
   servia a escola (CNAME para o Render, com HTTPS). Fica como caminho alternativo; o
   endereço oficial continua sendo `escola.cursoscruzvermelha.org` (ver `escola/`).

Publicados na Hostinger em 16/09/2026, com a fonte em `site/` (ver `site/README.md`):

3. **Links diretos por curso** em `cursos.html`: cada card ganha o botão "Ver turmas e
   inscrever-se" apontando para a página do curso na escola, além do link "Plataforma da
   Escola" no menu e no rodapé e do aviso de que datas e valores atualizados ficam na
   plataforma.
4. **Home** (`index.html`): os três destaques de curso e os três cards menores passam a
   apontar para a página exata do curso na escola (antes todos iam para a home da
   plataforma); "Ver todos os cursos" vai para o catálogo; o rodapé ganha "Escola de
   Educação e Saúde".
5. **Dados estruturados (JSON-LD)**: a home declara a instituição (`NGO`) com a escola como
   `subOrganization`, e `cursos.html` declara a lista de cursos (`Course`) com `url` na
   escola e `provider` ligado à instituição. Isso diz ao Google que os dois domínios são a
   mesma organização. O par simétrico para a escola está em `escola/head-seo.html`.
6. **Canonical** nas duas páginas editadas (`www` e apex servem o mesmo conteúdo).

## Sitemaps e indexação

- **Site principal**: `https://cruzvermelhariodejaneiro.org/sitemap.xml` já existe, é
  mantido pela Redação e está completo (8 páginas fixas + todas as notícias, com data).
  `robots.txt` aberto e apontando para ele. Não editar esses dois arquivos aqui: seriam
  sobrescritos na próxima publicação de notícia.
- **Escola**: não tinha `robots.txt` nem `sitemap.xml` (ambos 404) nem `canonical`, e o
  mesmo conteúdo aparece em três hosts. O kit em `escola/` resolve isso; leia
  `escola/README.md`. Enquanto o kit não for para o Render, existe uma cópia do sitemap da escola
  no domínio principal: `https://cruzvermelhariodejaneiro.org/sitemap-escola.xml`
  (10 URLs, fonte em `site/sitemap-escola.xml`). O Search Console só a aceita para a
  escola depois que as duas propriedades estiverem verificadas na **mesma conta**.
- Para regenerar o sitemap da escola quando entrarem cursos novos:
  `python3 scripts/gerar_sitemap_escola.py` (lê o catálogo público e grava
  `escola/sitemap.xml` e `site/sitemap-escola.xml`).

## Próximos passos

1. No app da escola (Render): copiar `escola/robots.txt` e `escola/sitemap.xml` para a
   pasta pública, ou instalar `escola/seo.js` (canonical + robots + sitemap dinâmico).
2. Search Console: adicionar a propriedade `https://escola.cursoscruzvermelha.org/`
   (verificação por meta tag no layout do app) e enviar o sitemap. Na propriedade do
   domínio principal, o `sitemap.xml` da Redação já pode ser enviado, se ainda não foi.
3. Publicar no Instagram/Facebook e na bio o atalho `cruzvermelhariodejaneiro.org/escola`.

## Matrícula cursos presenciais (página `/matricula-cursos-presenciais/`)

- **O que é**: o atalho de matrícula definido em `docs/briefing-matricula-cursos-presenciais.md`.
  O lead escolhe um dos cursos publicados na escola, vê carga horária, escolaridade, valor do
  curso e a inscrição de R$ 99, e clica em "Fazer matrícula". Sem data de turma, sem conta.
- **Só os cursos da escola**: `scripts/sincronizar_catalogo.py` lê
  `escola.cursoscruzvermelha.org/cursos` e cada página de curso e grava
  `site/matricula-cursos-presenciais/cursos.json`. Curso que a escola tirar do ar sai daqui na
  próxima sincronização. Nada é digitado à mão.
- **Página**: `scripts/gerar_matricula_presencial.py` monta `index.html` com o `<style>`,
  cabeçalho, rodapé, GA4, Meta Pixel e botão de WhatsApp copiados de `site/index.html`, mais
  dados estruturados (BreadcrumbList, ItemList de Course, FAQPage). Não edite o `index.html`
  gerado à mão: mude o gerador ou o `cursos.json` e gere de novo.
- **Imagens**: `img/*.webp` em 480 e 960 px, geradas a partir das fotos já publicadas em
  `assets/` (fallback JPG). Cada foto caiu de ~1 MB para 40–100 KB.
- **Botão "Fazer matrícula"**: enquanto o checkout de R$ 99 não existe, abre o WhatsApp da
  secretaria com a mensagem pronta com o nome do curso. Quando o checkout entrar, preencha
  `CHECKOUT_URL` no gerador e gere de novo; o botão passa a levar ao checkout com
  `?curso=<slug>` e as UTMs da visita.
- **Menu**: "Matrícula cursos presenciais" entrou na barra superior das cinco páginas mantidas
  à mão e no rodapé da home. `sitemap-paginas.xml` complementa o `sitemap.xml` da Redação;
  envie os dois no Search Console.
- **Publicar**: `scripts/publicar_hostinger.sh site/matricula-cursos-presenciais/index.html
  site/matricula-cursos-presenciais/img/*.webp site/index.html site/cursos.html
  site/doacao.html site/equipe.html site/campanha-agasalho.html site/sitemap-paginas.xml`
  e limpar o cache do site.
- **Publicada na Hostinger em 18/09/2026**: `index.html` e `img/*.webp` em
  `public_html/matricula-cursos-presenciais/`, as cinco páginas com o link no menu e
  `sitemap-paginas.xml`, com o cache do site limpo em seguida. No ar em
  `https://cruzvermelhariodejaneiro.org/matricula-cursos-presenciais/`.

## Pontos de atenção encontrados

- **Menu mobile sem links** na home, em `equipe.html` e, por herdar o CSS da home, na página
  de matrícula: abaixo de 920 px a regra `nav { display: none !important; }` do menu antigo
  esconde o `nav.nav-links` mesmo com o menu aberto, e só "Plataforma" aparece (conferido em
  18/09/2026 com emulação de iPhone). Corrigir na home, por exemplo com
  `.header-collapse .nav-links { display: flex !important; }` dentro do mesmo
  `@media (max-width: 920px)`, replicar em `equipe.html` e gerar a página de matrícula de novo.
- `links.cruzvermelhariodejaneiro.org` redireciona para `install.php`: instalador do CVB
  Links exposto ao público. Concluir a instalação ou remover/proteger o arquivo.
- Ícones de LinkedIn e TikTok no rodapé da home apontam para `linkedin.com` e `tiktok.com`
  genéricos (não há perfil configurado). Trocar pelas URLs reais ou remover.
- `cursos.html` fala em "cursos com início em julho" e traz preços diferentes dos da
  escola. Hoje a página avisa que os dados atualizados estão na plataforma; o ideal é
  revisar o texto ou passar a apontar só para o catálogo.
- `www.cruzvermelhariodejaneiro.org` serve o site sem redirecionar para o apex. A tag
  canonical resolve para o Google, mas um redirecionamento `www → apex` seria mais limpo.
- DMARC em `p=none`. Depois de conferir os relatórios, evoluir para `quarantine`.

## Estrutura do repositório

```
site/                                   páginas estáticas mantidas à mão (espelho do public_html)
  matricula-cursos-presenciais/         página gerada (index.html), cursos.json e img/*.webp
  sitemap-escola.xml                    cópia do sitemap da escola hospedada no domínio principal
  sitemap-paginas.xml                   sitemap complementar das páginas mantidas à mão
escola/                                 kit de SEO para o app da escola (robots, sitemap, canonical, JSON-LD)
docs/briefing-matricula-cursos-presenciais.md   definição do produto (fonte da verdade)
docs/plano-matricula-express.md         plano de implementação do backend (checkout, secretaria)
scripts/sincronizar_catalogo.py         cursos.json a partir do catálogo público da escola
scripts/gerar_matricula_presencial.py   gera a página a partir de cursos.json e da home
scripts/gerar_sitemap_escola.py         regenera os sitemaps da escola a partir do catálogo público
scripts/publicar_hostinger.sh           envia arquivos de site/ para a Hostinger (TUS)
```
