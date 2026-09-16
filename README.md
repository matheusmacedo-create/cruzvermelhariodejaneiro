# Cruz Vermelha Brasileira – Rio de Janeiro · site institucional e ligação com a escola

Este repositório guarda a parte **institucional (social)** da presença digital da
CVB-RJ: as páginas estáticas do site principal `cruzvermelhariodejaneiro.org`, o kit de
SEO da escola e os scripts para publicar. A Redação, a intranet e os demais sistemas são
projetos separados e têm seus próprios repositórios.

## Mapa do ecossistema (levantado em 16/09/2026)

| Endereço | O que é | Onde roda | Observações |
| --- | --- | --- | --- |
| `cruzvermelhariodejaneiro.org` (e `www`) | Site institucional: home, cursos, doação, equipe, campanha do agasalho, notícias, termos, privacidade | Hostinger, hospedagem compartilhada (conta `u448697994`, plano Business, LiteSpeed, SSL ativo com redirecionamento HTTPS) | HTML estático. As páginas mantidas à mão estão em `site/`. `noticias/`, `termos/`, `privacidade/`, `sitemap.xml` e `robots.txt` são gerados pela **Redação** via FTP. |
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

Prontos em `site/`, aguardando publicação na Hostinger (ver `site/README.md`):

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
  `escola/README.md`. Enquanto o kit não for para o Render, `site/sitemap-escola.xml` (10 URLs) é
  uma cópia do sitemap da escola para hospedar no domínio principal, em
  `https://cruzvermelhariodejaneiro.org/sitemap-escola.xml` (publicar junto com as
  páginas). O Search Console só a aceita para a escola depois que as duas propriedades
  estiverem verificadas na **mesma conta**.
- Para regenerar o sitemap da escola quando entrarem cursos novos:
  `python3 scripts/gerar_sitemap_escola.py` (lê o catálogo público e grava
  `escola/sitemap.xml` e `site/sitemap-escola.xml`).

## Próximos passos

0. Publicar `site/index.html`, `site/cursos.html` e `site/sitemap-escola.xml` na Hostinger
   (`scripts/publicar_hostinger.sh`) e limpar o cache do site.
1. No app da escola (Render): copiar `escola/robots.txt` e `escola/sitemap.xml` para a
   pasta pública, ou instalar `escola/seo.js` (canonical + robots + sitemap dinâmico).
2. Search Console: adicionar a propriedade `https://escola.cursoscruzvermelha.org/`
   (verificação por meta tag no layout do app) e enviar o sitemap. Na propriedade do
   domínio principal, o `sitemap.xml` da Redação já pode ser enviado, se ainda não foi.
3. Publicar no Instagram/Facebook e na bio o atalho `cruzvermelhariodejaneiro.org/escola`.

## Pontos de atenção encontrados

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
site/                      páginas estáticas mantidas à mão (espelho do public_html)
  sitemap-escola.xml       cópia do sitemap da escola hospedada no domínio principal
escola/                    kit de SEO para o app da escola (robots, sitemap, canonical, JSON-LD)
scripts/gerar_sitemap_escola.py   regenera os sitemaps da escola a partir do catálogo público
scripts/publicar_hostinger.sh     envia arquivos de site/ para a Hostinger (TUS)
```
