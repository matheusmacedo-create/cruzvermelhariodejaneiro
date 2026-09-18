# Cruz Vermelha Brasileira – Rio de Janeiro · site institucional e ligação com a escola

Este repositório guarda a parte **institucional (social)** da presença digital da
CVB-RJ: as páginas estáticas do site principal `cruzvermelhariodejaneiro.org`, o kit de
SEO da escola e os scripts para publicar. A Redação, a intranet e os demais sistemas são
projetos separados e têm seus próprios repositórios.

## Mapa do ecossistema (levantado em 16/09/2026)

| Endereço | O que é | Onde roda | Observações |
| --- | --- | --- | --- |
| `cruzvermelhariodejaneiro.org` (e `www`) | Site institucional: home, doação, equipe, campanha do agasalho, notícias, termos, privacidade (`cursos.html` saiu do ar em 18/09/2026 e responde 301 para a matrícula) | Hostinger, hospedagem compartilhada (conta `u448697994`, plano Business, LiteSpeed, SSL ativo com redirecionamento HTTPS) | HTML estático. As páginas mantidas à mão estão em `site/`. `noticias/`, `termos/`, `privacidade/`, `sitemap.xml` e `robots.txt` são gerados pela **Redação** via FTP. |
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

> **18/09/2026:** `cursos.html` saiu do ar (estava desatualizada e confundia quem ia se matricular).
> Responde **301** para `/matricula-cursos-presenciais/`, que passou a ser a vitrine de cursos do
> domínio e carrega a lista de cursos em JSON-LD (`Course`). Os itens 3 e 5 acima ficam como
> histórico; os menus de `doacao.html` e `campanha-agasalho.html` passaram a apontar "Cursos"
> para a plataforma da escola, como a home.

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
  cabeçalho, rodapé, GA4 e Meta Pixel copiados de `site/index.html`, mais dados estruturados
  (BreadcrumbList, ItemList de Course, FAQPage). Não edite o `index.html` gerado à mão: mude o
  gerador ou o `cursos.json` e gere de novo.
- **Imagens**: `scripts/gerar_imagens_matricula.py` baixa a foto de cada curso da escola (campo
  `imagem_url` do `cursos.json`), recorta ao centro em 4:3, como a escola exibe, e grava
  `img/<slug>-480.webp` e `img/<slug>-960.webp` (requer Pillow: `pip install pillow`). As fotos
  verticais de `assets/` não são mais usadas aqui: cortadas em faixa, perdiam o enquadramento.
- **Botão "Fazer matrícula agora"** (único por curso): enquanto o checkout de R$ 99 não existe,
  abre o WhatsApp da secretaria com a mensagem pronta com o nome do curso. Quando o checkout
  entrar, preencha `CHECKOUT_URL` no gerador e gere de novo; o botão passa a levar ao checkout
  com `?curso=<slug>` e as UTMs da visita.
- **Decisões de 18/09 (depois da primeira publicação)**: o topo e o bloco de cada curso focam em
  "faça sua matrícula agora e garanta sua vaga"; a regra "a secretaria confirma horário depois"
  fica só em "Como funciona" e no FAQ; a página não mostra telefone nem WhatsApp da secretaria
  (nem o botão flutuante da home), porque desviavam da matrícula; o link para o curso na
  plataforma da escola fica discreto, no fim do detalhe.
- **Revisão de copy e SEO (18/09)**: título "Matrícula em cursos presenciais no RJ | Cruz Vermelha
  Brasileira"; description corrigida ("garanta"); H1 com linha de apoio com as palavras-chave
  (cursos presenciais, Cruz Vermelha Brasileira, Rio de Janeiro); botão "Escolher meu curso" no
  topo; breadcrumb sem `cursos.html`; `Course` com `image`, `offers` (inscrição e valor do curso) e
  `hasCourseInstance` (presencial, endereço, carga horária); FAQ com formas de pagamento; taglines
  curtas demais da escola trocadas pela primeira frase de "Sobre o curso"; menu sanfona do celular
  corrigido nesta página e, em seguida, na home e em `equipe.html`.
- **Menu**: "Matrícula cursos presenciais" é o item de cursos da barra superior das páginas
  mantidas à mão e do rodapé da home. Desde 18/09 o item "Cursos" (que ia para a plataforma)
  saiu dos menus: a plataforma da escola fica no botão "Plataforma" e nos links dos rodapés.
  `sitemap-paginas.xml` complementa o `sitemap.xml` da Redação; envie os dois no Search Console.
- **Topo e rodapé da home** (18/09): além do item de menu, a barra superior ganhou o botão
  vermelho "Fazer matrícula" ao lado de "Plataforma" (largura total dentro do menu sanfona), e a
  coluna "Sobre" do rodapé ganhou os links "Matrícula cursos presenciais" e "Plataforma da
  escola"; a linha inferior do rodapé já tinha o link. `equipe.html` recebeu o mesmo cabeçalho
  e rodapé; a página de matrícula herda os dois do gerador (o botão lá leva ao catálogo).
- **Bloco na home** ("Já escolheu seu curso?", seção `#matricula`, logo após os cursos): um
  `<select>` com os cursos e o botão "Fazer matrícula agora", que leva a
  `/matricula-cursos-presenciais/?curso=<slug>` sem JavaScript (formulário GET). As opções ficam
  entre os marcadores `<!-- matricula:cursos -->` e são reescritas pelo gerador a partir de
  `cursos.json`, então a home acompanha o catálogo.
- **Publicar**: `scripts/publicar_hostinger.sh site/matricula-cursos-presenciais/index.html
  site/matricula-cursos-presenciais/img/*.webp site/index.html site/doacao.html
  site/equipe.html site/campanha-agasalho.html site/sitemap-paginas.xml` e limpar o cache do site.
- **Publicada na Hostinger em 18/09/2026**: `index.html` e `img/*.webp` em
  `public_html/matricula-cursos-presenciais/`, as cinco páginas com o link no menu e
  `sitemap-paginas.xml`, com o cache do site limpo em seguida. No ar em
  `https://cruzvermelhariodejaneiro.org/matricula-cursos-presenciais/`. No mesmo dia saiu a
  segunda versão (copy focada na matrícula, sem telefone da secretaria, fotos da escola em 4:3);
  as 14 fotos antigas (`img/curso-*.webp`) ficaram órfãs no servidor e podem ser apagadas pelo
  hPanel (o script de publicação só envia arquivos).

## Pontos de atenção encontrados

- ~~Menu mobile sem links na home e em `equipe.html`~~: corrigido em 18/09/2026 (regra
  `.main-header .header-collapse .nav-links { display: flex !important; }` dentro do
  `@media (max-width: 920px)` das duas páginas; a matrícula herda pelo CSS copiado da home).
- `links.cruzvermelhariodejaneiro.org` redireciona para `install.php`: instalador do CVB
  Links exposto ao público. Concluir a instalação ou remover/proteger o arquivo.
- Ícones de LinkedIn e TikTok no rodapé da home apontam para `linkedin.com` e `tiktok.com`
  genéricos (não há perfil configurado). Trocar pelas URLs reais ou remover.
- `cursos.html` saiu do ar em 18/09/2026 (301 para a matrícula), mas o `sitemap.xml` da Redação
  ainda a lista entre as páginas fixas: retirar da lista no projeto da Redação, para o sitemap
  parar de apontar para um redirecionamento.
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
scripts/gerar_imagens_matricula.py      fotos dos cursos (img/*.webp, 4:3) a partir das imagens da escola
scripts/gerar_matricula_presencial.py   gera a página a partir de cursos.json e da home
scripts/gerar_sitemap_escola.py         regenera os sitemaps da escola a partir do catálogo público
scripts/publicar_hostinger.sh           envia arquivos de site/ para a Hostinger (TUS)
```
