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

Desde 19/09/2026 o domínio tem um **índice de sitemaps**:
`https://cruzvermelhariodejaneiro.org/sitemap-index.xml`. É o único endereço a enviar no Google
Search Console (propriedade de domínio `cruzvermelhariodejaneiro.org`, verificada por DNS, que cobre
todos os subdomínios). Ele aponta para quatro arquivos:

| Arquivo | Conteúdo | Fonte |
| --- | --- | --- |
| `sitemap-paginas.xml` | 8 páginas fixas do domínio principal, com `lastmod` real (`Last-Modified` do servidor), `changefreq`, `priority` e as imagens de cada página (37, com título tirado do `alt`) | `scripts/gerar_sitemaps.py` |
| `sitemap-noticias.xml` | as notícias da Redação (12 em 19/09), com `article:modified_time` e as imagens de cada artigo (15) | idem, lendo o `sitemap.xml` da Redação |
| `sitemap-subdominios.xml` | landing pages `doar.`, `projetocores.` e `puncaovenosav1.` (11 imagens) | idem |
| `sitemap.xml` | páginas fixas + notícias, sempre atual (a Redação regenera a cada notícia) | Redação; não editar aqui |

Regras do gerador: confere ao vivo cada URL e cada imagem (só entra o que responde 200); deixa de
fora página com `noindex` ou com canonical apontando para outro endereço; nunca inventa `lastmod`
(sem fonte confiável, omite); ignora logotipos, logos de parceiros e pixels de rastreio. Os quatro
arquivos validam contra os XSD oficiais (sitemaps.org e extensão de imagem do Google). Para
regenerar e publicar:

```
python3 scripts/gerar_sitemaps.py
scripts/publicar_hostinger.sh site/sitemap-index.xml site/sitemap-paginas.xml site/sitemap-noticias.xml site/sitemap-subdominios.xml
```

e limpar o cache. Vale rodar de novo quando uma página fixa mudar ou quando entrarem notícias (o
`sitemap.xml` da Redação já cobre as novas; o `sitemap-noticias.xml` só acrescenta data e imagens).

Ficam fora do índice, de propósito: `www.` (canonical no apex); `escola.cruzvermelhariodejaneiro.org`
(apelido da escola, cujo canonical é `escola.cursoscruzvermelha.org`); `redacao.` (ferramenta
interna); `links.` e `link.` (instalador exposto e página padrão); `cursos.html` e `/escola` (301);
as três telas do checkout (`noindex`) e a API; e `/verificar/`, escondida de propósito (ver
"Verificação de documentos em `/verificar/`").

- **Escola**: fica em outro domínio (`cursoscruzvermelha.org`), então não pode entrar no índice
  até a escola estar verificada na **mesma conta** do Search Console. Não tinha `robots.txt` nem
  `sitemap.xml` (ambos 404 ainda em 19/09) nem `canonical`, e o mesmo conteúdo aparece em três
  hosts. O kit em `escola/` resolve isso; leia `escola/README.md`. Enquanto o kit não for para o
  Render, existe a cópia `https://cruzvermelhariodejaneiro.org/sitemap-escola.xml` (10 URLs, fonte
  em `site/sitemap-escola.xml`), para enviar à parte depois da verificação. Regenerar quando
  entrarem cursos novos: `python3 scripts/gerar_sitemap_escola.py`.
- **`robots.txt`** do domínio é regravado pela Redação a cada notícia e aponta para os dois mapas
  (`sitemap-index.xml` e `sitemap.xml`). `site/robots.txt` tem o mesmo conteúdo: mudou um, mudar o
  outro (em `lib/site/sitemap.ts`, na Redação), senão a próxima notícia desfaz a mudança.

## Próximos passos

1. No app da escola (Render): copiar `escola/robots.txt` e `escola/sitemap.xml` para a
   pasta pública, ou instalar `escola/seo.js` (canonical + robots + sitemap dinâmico).
2. Search Console: na propriedade do domínio principal, enviar
   `https://cruzvermelhariodejaneiro.org/sitemap-index.xml`. Depois, adicionar a propriedade
   `https://escola.cursoscruzvermelha.org/` (verificação por meta tag no layout do app) e enviar
   o sitemap da escola.
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
- **Hero (19/09)**: o H1 passou a liderar com o benefício e a marca ("O certificado da Cruz
  Vermelha no seu currículo"), a urgência foi para a linha de apoio ("Garanta sua vaga hoje: a
  inscrição de R$ 99 reserva seu lugar") e o parágrafo lista os cursos e o gesto (escolher, pagar
  por PIX ou cartão, vaga garantida). O H1 anterior era o próprio chamado ("Faça sua matrícula
  agora e garanta sua vaga"), que o Matheus achou fraco como título.
- **Decisões de 18/09 (depois da primeira publicação)**: o topo e o bloco de cada curso focam em
  "faça sua matrícula agora e garanta sua vaga"; a regra "a secretaria confirma horário depois"
  fica só em "Como funciona" e no FAQ; a página não mostra telefone nem WhatsApp da secretaria
  (nem o botão flutuante da home), porque desviavam da matrícula; o link para o curso na
  plataforma da escola fica discreto, no fim do detalhe.
- **Revisão de copy e SEO (18/09)**: título "Matrícula em cursos presenciais no RJ | Cruz Vermelha
  Brasileira"; description corrigida ("garanta"); H1 com linha de apoio com as palavras-chave
  (cursos presenciais, Cruz Vermelha Brasileira, Rio de Janeiro); breadcrumb sem `cursos.html`
  (um botão "Escolher meu curso" no topo foi testado e removido no mesmo dia); `Course` com `image`, `offers` (inscrição e valor do curso) e
  `hasCourseInstance` (presencial, endereço, carga horária); FAQ com formas de pagamento; taglines
  curtas demais da escola trocadas pela primeira frase de "Sobre o curso"; menu sanfona do celular
  corrigido nesta página e, em seguida, na home e em `equipe.html`.
- **Menu**: "Matrícula cursos presenciais" é o item de cursos da barra superior das páginas
  mantidas à mão e do rodapé da home. Desde 18/09 o item "Cursos" (que ia para a plataforma)
  saiu dos menus: a plataforma da escola fica no botão "Plataforma" e nos links dos rodapés.
  `sitemap-paginas.xml` complementa o `sitemap.xml` da Redação; envie os dois no Search Console.
- **Topo e rodapé da home** (18/09): além do item de menu, a home ganhou uma faixa vermelha fina
  acima do cabeçalho (`.faixa-matricula`, texto branco e link em pílula branca: "Cursos presenciais da Cruz Vermelha: inscrição de
  R$ 99 e vaga garantida. Fazer matrícula agora"), visível também no celular sem abrir o menu, e
  a coluna "Sobre" do rodapé ganhou os links "Matrícula cursos presenciais" e "Plataforma da
  escola"; a linha inferior do rodapé já tinha o link. Um botão vermelho na barra superior foi
  testado e descartado no mesmo dia. `equipe.html` tem o mesmo rodapé; a página de matrícula
  herda cabeçalho e rodapé do gerador (sem a faixa).
- **Onde o valor do curso é pago**: na plataforma da escola, não "na escola". A página, o FAQ, o
  passo 3, os dados estruturados e o bloco da home dizem "pago depois, na plataforma da escola".
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

## Página de matrícula no modelo B: vermelho, fotos reais de aula (05/10/2026)

O Matheus não gostou do visual da página reconstruída ("pelos modelos que me mandou eu ainda não gostei do layout").
Antes de aplicar qualquer coisa, foram feitos **três mockups completos** (A editorial em serifa, B vermelho com foto em
destaque, C saúde clara) e, depois da escolha do B, **seis variantes do banner principal** (foto de fundo com véu,
cartão branco sobre a foto, vermelho com foto emoldurada, panorâmica com painel de preço, e as duas últimas com as
fotos de prática). Ficou o B com o **banner 6**: fundo branco, título, dois botões, endereço, painel de preço ("Inscrição
R$ 99, paga agora, garante a sua vaga" + quatro itens: curso pago depois na escola, PIX ou cartão sem criar conta antes
de pagar, devolução integral em 7 dias, certificado) e, à direita, a foto do instrutor orientando a prática de
desengasgo em bebê. Os mockups e as variantes não estão no repositório (ficaram na sessão).

**O que mudou no gerador** (`scripts/gerar_matricula_presencial.py`): só a camada visual. `CSS_PAGINA` reescrito
(fonte Manrope do Google Fonts, como a home já faz com a Inter; paleta `--vermelho:#cc0000`, `--escuro`, `--cinza`),
bloco `/* Hero */ … /* /Hero */` isolado com `hero_html()`, faixa de quatro marcas abaixo do banner, cartões com foto
de capa + etiqueta de categoria + título sobre véu escuro + carga e requisito + preço em duas linhas + dois botões,
cartão "Turma para empresas e grupos" escuro com foto (ocupa duas colunas no computador), faixa vermelha "Como
funciona", ficha de detalhes com capa e rodapé fixo. `JS_PAGINA`, `turma_dialog()`, textos, FAQ, passos, fatos, JSON-LD,
metadados, eventos e seletores usados pelos testes **não mudaram**. Mudanças de rótulo: eyebrows das seções saíram
(o B só tem no banner), "Requisito:" e "Presencial · Centro do Rio" saíram do cartão (o local segue na ficha), a FAQ
tem "Dúvidas frequentes" como título. Pontos de quebra: 640 (2 colunas), 720 (computador), 768 (grade do banner),
1024 (4 passos), 1200 (3 colunas).

**Fotos reais das aulas (10/2026).** A escola mandou 20 fotos e 7 vídeos pelo WhatsApp. Aproveitadas 9 fotos (as
nítidas e que mostram a aula; vídeos descartados: verticais, 478×850, tremidos). Originais sem EXIF em `site/assets/`
e versões WebP em `site/assets/otim/` (as duas pastas fora do Git; `scripts/otimizar_imagens.py` ganhou as 9
entradas `aula-*`). Na página: banner `aula-engasgo-bebe-instrutor`; capas de Primeiros Socorros Básico
(`aula-manobra-heimlich`), Lei Lucas (`aula-engasgo-bebe`) e Suporte Básico de Vida (`aula-dea-sala`); galeria
(`aula-engasgo-bebe`, `aula-dea-sala`, `aula-manobra-heimlich`, `aula-salao-cruz`). Punção Venosa, Bombeiro Civil,
Cuidador de Idosos e Micropigmentação continuam com as imagens geradas de `img/` até haver foto. Os alunos aparecem
com rosto visível: **confirmar com a escola a autorização de uso de imagem antes de publicar.** Publicação com a
lista `scripts/publicacao-modelo-b.txt` (index + originais + WebP; nada de foto vai pelo Git). **Publicado em 05/10/2026**, com autorização de imagem confirmada pelo Matheus: `conferir_publicacao.sh` (21 arquivos iguais, API e bloqueios ok), página no ar com Manrope, 30 imagens carregando e sem erro de JS em 390 e 1280, `conferir_pixel.js` no ar 15/15. **Ajuste de 05/10, à tarde:** o cartão "Turma para empresas e grupos" e a chamada final "Pronto para começar?" passaram do preto (`--escuro`) para o vermelho da marca, a pedido do Matheus (prévia em `docs/previas/matricula-computador-empresas.png`). Na mesma tarde: **mapa estático da sede** na seção "Onde acontecem as aulas?" (imagem gerada com Leaflet + OpenStreetMap, zoom 17, pino vermelho, salva em `site/assets/mapa-sede.png` e otimizada por `otimizar_imagens.py`; crédito "© colaboradores do OpenStreetMap" na legenda), com a foto da fachada em miniatura; o clique no mapa ou em "Ver no mapa" troca pelo iframe do Google (`mapa_aberto` com `origem`), como antes nada do Google carrega sem clique. E a correção das margens: `.mr p { margin: 0 }` vencia as classes de uma só classe (`.mr-como-nota`, `.mr-endereco`, `.mr-nota-preco`, `.mr-local-nota`…) e colava a nota dos 7 dias nos passos; agora é `:where(.mr) p`, e a nota tem linha separadora. Prévia em `docs/previas/matricula-computador-local.png`; publicação com `scripts/publicacao-vermelho-mapa.txt`.

**Conferido (05/10):** gerador (7 cursos, 1 H1); `conferir_pixel.js --repositorio` 15/15; Playwright em 375, 390,
430, 768, 1024, 1280 e 1440 sem rolagem horizontal e sem erro de JS (filtro, janela dos detalhes, turma, barra fixa,
modo curso, chegada por `#det-`, sem JS); `validar_jsonld.py` 0 erros; `testar_checkout.php` 350/350;
`testar_turmas_integracao.php` 24/24. Primeiro botão de matrícula em 444 px no celular e 530 px no computador.
Altura: 10,7 mil px em 390 (12,7 telas; o banner escolhido e o cabeçalho e rodapé da home somam 2,1 mil) e 7,7 mil em
1280. Não testado: site no ar, Safari e aparelhos reais, leitor de tela. Prévias em `docs/previas/`.

## Página de matrícula reconstruída do zero (05/10/2026)

O Matheus pediu uma versão nova e completa, "mais curta, mais clara, mais institucional, muito melhor no celular,
focada em levar o visitante até a matrícula", com a plataforma da escola (escola.cursoscruzvermelha.org) como
referência, e **sem "próxima turma" nem datas** (não há fonte de dados; o checkout e a escola é que sabem a turma).
O gerador (`scripts/gerar_matricula_presencial.py`) foi reescrito; o checkout, o formulário de turma, o chat, o
rastreamento e os links não mudaram.

**Ordem da página:** topo compacto (eyebrow, h1, frase, quatro marcas ✓, "Ver cursos", endereço e uma foto real do
auditório) → filtro por categoria (Todos, Emergência, Saúde, Formação profissional, Estética) → cursos (7 cartões + o
cartão "Turma para empresas e grupos") → "Aqui você aprende fazendo" (quatro fotos reais da sede, legendas do site) →
"Como funciona" (faixa vermelha, quatro passos, com a regra dos 7 dias) → certificado (uma seção só) → "Mais de um
século de história" (fatos de /historia/, foto de 1917) → local (endereço, "Como chegar", mapa carregado só ao pedir) →
dúvidas (12 perguntas em acordeão, só com respostas confirmadas; idade mínima: "informação em breve") → empresas e
grupos ("Solicitar uma turma" abre a janela do pedido) → chamada final.

**Cartão e ficha:** o cartão traz foto, categoria, nome, frase, carga, requisito, o preço em duas linhas ("Inscrição
R$ 99, paga agora" + "Curso R$ X, pago depois, na escola", PIX ou cartão), "Garantir vaga" (checkout) e "Ver
detalhes". A ficha completa (sobre, o que aprende, para quem, carga, pré-requisito, materiais = "Informação em
breve", local, certificação, dúvidas do curso, botão) fica escondida na página (`.mr-det`, indexável) e é movida para
a janela `#mr-janela` (dialog) ao abrir; volta ao fechar. Sem JavaScript, `#det-<slug>` a mostra por `:target`. Com
`?curso=<slug>` ela abre no topo antes da primeira pintura (modo curso dos anúncios), e o ViewContent sai ao carregar.

**Celular:** cartão compacto (miniatura ao lado do título, preço numa linha, dois botões lado a lado), barra fixa
("Ver cursos"; com um curso em foco, "Garantir minha vaga"), chips roláveis, janelas em tela inteira. **Computador:**
grade de 4 colunas (3 em 1024, 2 no tablet), navegação fixa da página abaixo do cabeçalho (Cursos, Como funciona,
Certificado, Localização, Dúvidas, "Ver cursos") que aparece depois do topo. Primeiro botão de matrícula em 484 px no
celular e 584 px no computador. Altura: 9,5 mil px no celular (11 telas), 6,8 mil no computador.

**Dados num só lugar:** `cursos.json` + `COPY_CURSO`, `BENEFICIO`, `CATEGORIA_DE`; `STATUS_TURMA` (selo "Vagas
abertas", "Últimas vagas", "Turma em formação", "Esgotado", "Nova turma em breve") e `MATERIAIS` ficam vazios até
haver fonte, e nada é impresso. Fotos reais: `FOTO_TOPO`, `FOTOS_AULAS`, `FOTO_HISTORIA`, `FOTO_FACHADA`
(`/assets/otim`, fora do Git). JSON-LD: BreadcrumbList, EducationalOrganization, ItemList de Course e FAQPage.

**Rastreamento:** continuam PageView/ViewContent/SelectContent (Meta, com repasse) e view_item_list, view_item,
select_item, cta_matricula_visto, secao_vista, faq_aberta, chat_atalho, saida_escola, barra_fixa_vista (GA4). Entram
`view_course` (cartão 50% visível, uma vez), `select_course` ("Ver detalhes"), `view_course_details` (ficha aberta),
`click_enroll` (botão de matrícula), `select_category` (chip), `contact_company_training` (abriu o pedido de turma) e
`mapa_aberto`. Não há WhatsApp na página (decisão pendente D5), então `click_whatsapp` não existe. Detalhes em
`docs/rastreamento.md`.

**Revisão adversarial (três revisores + verificação, 05/10):** corrigidos chat, "Veja o certificado" e "Peça uma turma" de
dentro da janela dos detalhes (fechavam atrás do dialog ou soltavam a trava de rolagem); chegada por `#det-<slug>` deixava a
ficha impressa solta ao fechar (`:target`, agora só sem JS, e o hash é limpo); a janela não abre por cima do aviso de
cookies; navegação fixa fora do fluxo (deixava 57 px em branco) e com `scroll-margin-top` nas seções; botão da navegação
com texto branco; barra fixa com nome em duas linhas e subtítulo curto; moldura da ficha no modo curso; copy alinhada à
regra pública da escola ("a entrada na aula é liberada com a matrícula paga"), "sem criar conta **antes de pagar**" e a
pergunta "Preciso criar conta?"; FAQ da turma não formada sem prometer além dos 7 dias; frases do catálogo que prometem
emprego ou renda ficam fora da página e do chat (`EXCLUIR_CATALOGO`); legendas das fotos com o que elas mostram
(formação de voluntários, equipe), não "turma" nem "instrutor". **Achados fora desta página, para tratar à parte:**
`scripts/sincronizar_catalogo.py` não lê mais a escola (zera preço, carga e escolaridade se rodado); `faq-home.json`
descreve o fluxo antigo (secretaria confirma em 3 dias úteis) e cita R$ 100 de matrícula da escola (a escola cobra R$ 99
nos cartões e diz R$ 100 no passo 02); o aviso de cookies cobre 58% da primeira tela em 375×667; o cinza `--muted` do
cabeçalho e rodapé da home tem contraste abaixo de 4,5:1 em 12–13 px.

**Conferido:** Playwright em 375, 390, 430, 768, 1024, 1280 e 1440 (sem rolagem horizontal, sem erro de JS; filtro,
janela dos detalhes com Esc/X/fora e foco de volta, janela da turma, barra fixa, navegação, modo curso),
`conferir_pixel.js --repositorio` (15/15, com o passo "abrir um curso" trocado para a janela), `validar_jsonld.py`,
`testar_checkout.php` (350) e `testar_turmas_integracao.php` (24).

## Certificado de amostra, copy por curso e recuperação do lead (04/10/2026)

O Matheus mandou o modelo do certificado (Punção Venosa, em PDF, com nome e CPF de uma pessoa) e pediu: revisar a
copy de cada curso para a conversão e usar o certificado na página e na recuperação do lead, reforçando o peso da
Cruz Vermelha. **O PDF não entra no repositório nem no site.**

- **Certificado de amostra** (`scripts/gerar_certificado_modelo.py`): a frente do modelo, com a área do aluno
  apagada (`scripts/certificado/fundo-certificado.png`, sem dado pessoal), recebe "Seu nome aqui", CPF
  000.000.000-00, o curso e a carga horária do `cursos.json`, só o cargo na assinatura e a marca "MODELO". Saída:
  `img/certificado-<slug>-640.webp`, `-1200.webp` (página) e `-email.jpg` (e-mail não abre WebP).
- **Na página:** o certificado no topo (computador), na ficha de cada curso (modo `?curso=`, no lugar da foto), nos
  detalhes do cartão e na seção "Depois da inscrição", com as frases com fonte: "reconhecida nacional e
  internacionalmente pela tradição em formação humanitária" (faq-home, FAQ do Bombeiro Civil) e "Sociedade Nacional,
  no Brasil, do Movimento Internacional da Cruz Vermelha e do Crescente Vermelho" (/historia/). **"Ampla aceitação no
  mercado" ficou de fora:** não há dado que sustente. Vale o mesmo para MEC, emprego e renda.
- **Copy por curso** (`COPY_CURSO` e `BENEFICIO` no gerador): título e promessa da ficha antes do botão; "O que você
  aprende" (3 itens), "Para quem" e a objeção principal depois dele; "aprende" e "para quem" abrem também os
  detalhes do cartão. Cada frase foi conferida contra o `cursos.json` e a `faq-home.json` por uma segunda leitura
  adversarial: saíram "vale em todo o país" (Bombeiro Civil), a lista de locais de trabalho (Cuidador de Idosos) e
  "Vou conseguir aprender? Sim" (sem base). O primeiro botão do modo curso no celular ficou em 666 px no pior caso
  (Bombeiro Civil, que tem a observação da homologação).
- **E-mail do PIX aberto** (sai quando o código é gerado): o passo 2 agora segue a configuração da escola (com a
  matrícula automática, "Sua matrícula entra na turma"; sem ela, "A secretaria confirma turma e horário"), entra o
  passo "Você conclui e recebe o certificado" e o bloco do certificado do curso.
- **Lembrete único do PIX** (`api/lib/recuperacao.php`, rodado pelo `api/lembretes.php` de hora em hora): a quem
  gerou o PIX há 2 a 20 horas e não pagou. A pessoa é o e-mail ou o CPF (quem corrige o e-mail digitado errado e
  paga não recebe "falta o PIX" no endereço velho). Só a inscrição pendente mais recente (um cartão recusado depois
  não cancela o lembrete do PIX), nunca se a pessoa já pagou o curso, um lembrete por pessoa e curso (o registro
  `email_pix_lembrete` ou `email_pix_lembrete_falhou` trava o próximo), no máximo 20 por rodada. Antes de mandar,
  consulta a Unicopag: se a consulta falha ou vem um status desconhecido, não manda (tenta na hora seguinte); se está
  pago, aplica o pagamento. O texto de desistência é só o dos 7 dias, depois de pagar: antes do pagamento a vaga não
  está reservada. Assunto "Sua inscrição em
  <curso> continua aberta: falta só o PIX", com o certificado antes do botão e `utm_campaign=pix-lembrete`.
  Teste: `scripts/testar_recuperacao_integracao.php` (banco local).
- **Tela do PIX** (checkout e pendente, `static/checkout.js`): o certificado do curso abaixo do código.
- **Criativos de remarketing** (`scripts/gerar_criativos_certificado.py` → `docs/criativos/`): feed 1080×1080 e
  stories 1080×1920 de Punção Venosa e Primeiros Socorros Básico, "Coloque o seu nome neste certificado.", com
  "Imagem de modelo" e o preço inteiro (inscrição agora + curso depois, nunca só os R$ 99). Público sugerido: quem viu a página de matrícula ou iniciou o checkout nos últimos 14 dias e
  não comprou.

**Revisão adversarial (três revisores, cada achado conferido):** além do que está acima, entraram "Inclui Lei Lucas."
de volta no comparador, a caixa "O que você aprende" em linha cheia nos detalhes (no computador ela empurrava as
dúvidas para baixo), o certificado do topo com `loading="lazy"` (ele fica escondido no celular e no modo curso), a
troca de certificado no JS só para curso com imagem (`data-cert`), a pergunta da objeção do Lei Lucas sem perguntar se
o curso "serve para a Lei Lucas da minha escola", a do Bombeiro Civil sem fechar o custo total (com o parcelamento), o
passo 3 do e-mail do PIX sem "quando a turma estiver confirmada" com a matrícula automática, e a ordem de publicação
(recuperacao.php e lib.php antes do email.php). Saiu da seção do certificado a linha "assinado pela Coordenação, com o
conteúdo no verso": só foi vista no modelo de Punção Venosa.

**Publicado na Hostinger em 04/10/2026, 21h08** (com o redesenho da página, que ainda não estava no ar), pela lista
`scripts/publicacao-certificado.txt`, com o cache limpo em seguida. Conferido no ar: `conferir_publicacao.sh` (62
itens: estáticos iguais ao repositório, API respondendo, configuração e módulos bloqueados), `conferir_pixel.js` no
site real e o navegador no celular e no computador (primeiro botão em 592 px no celular e 756 px no computador,
certificados carregando, nenhum erro de JS). Desfazer: `scripts/desfazer_publicacao.sh f47323c
scripts/publicacao-certificado.txt`.

**Ajustes de layout no celular (publicados em 04/10/2026, 23h00):** o Matheus achou a página confusa no celular. Entraram o
formulário de turma numa janela (dialog: tela inteira no celular, centrada no computador; no link de anúncio abre depois
do aviso de cookies), os itens das turmas e o "Inglês (English)" sem quebrar em colunas, o certificado citado uma vez por
bloco (fora das promessas, das objeções, dos detalhes do cartão e da faixa "Quem dá o curso"), os ícones da seção do
certificado (não apareciam), o benefício do cartão sem reticências, dúvidas e comparador com +/−, passos em lista única,
garantia sem o selo repetido e um botão "Escolher meu curso" no fim das dúvidas. Na mesma publicação, as utm_* do anúncio
nos links da escola (PR #56, portado para o gerador novo). Lista: `scripts/publicacao-layout-celular.txt`.

**Catálogo em grade única (05/10/2026):** os cursos saíam em grupos de 4, 2 e 1 numa grade de 3 colunas, com
cartões soltos e buracos no computador. Agora é uma grade só (4 colunas no computador, 2 no tablet, lista no celular),
com foto de capa e o grupo como etiqueta, o comparador entre as duas linhas e, no 8º lugar, o cartão "Turma para
empresas e grupos", que abre a janela da turma. Aberto, o cartão ocupa a linha com preço e botão lado a lado. O topo do
computador ficou mais baixo para o primeiro botão caber em 1366×768 (759 px).

**Para o responsável decidir:** a observação "Inclui Lei Lucas." do Primeiros Socorros Básico (no `cursos.json`) é
ambígua ao lado do curso Lei Lucas; o verso do certificado ("registro em livro", "válido por 2 anos") não foi usado
até alguém confirmar; e se o mesmo modelo vale para todos os cursos.

## Página de matrícula: redesenho para conversão (04/10/2026)

O Matheus pediu a página "focada em conversão". O diagnóstico no navegador mostrou o problema na ordem, não no
peso da página:

- **Botão longe:** o primeiro botão de matrícula ficava 2 telas abaixo no celular.
- **Comprimento:** a página tinha 12,1 telas.
- **Chegada pelo anúncio:** quem chegava de um anúncio com `?curso=` via o topo genérico, e nenhum `ViewContent`
  saía ao carregar.
- **Vazamento para a escola:** 11 links levavam à plataforma da escola, sem medição. As vendas de 03/10 caíram lá
  (`docs/rastreamento.md`).
- **Turmas:** a seção ocupava 29% da página, com o formulário sempre aberto.

A especificação completa (diagnóstico, três propostas, julgamento e decisões) está em
[`docs/redesenho-matricula-2026-10.md`](docs/redesenho-matricula-2026-10.md), com o que foi implementado diferente
dela no topo.

**O que mudou, de cima para baixo:**

- **Topo curto, sem botão-âncora:** o primeiro botão da página leva ao checkout de um curso. O botão "Escolher meu
  curso" do topo, que foi testado e removido em 18/09, não volta.
- **Modo curso (`?curso=<slug>`):** um script no `<head>` marca `<html data-curso>` antes da primeira pintura, e a
  ficha daquele curso aparece por CSS. São 7 fichas pré-geradas, sem `id` e fora do JSON-LD. O `ViewContent` e o
  `view_item` saem ao carregar, uma vez, e só com consentimento. `#curso-<slug>` abre os detalhes do cartão.
- **Catálogo em cartões:** cada cartão tem miniatura, carga horária e escolaridade, uma linha de benefício tirada
  do `cursos.json`, "Inscrição R$ 99 + R$ X do curso, depois" e o botão "Fazer matrícula".
  - "Ver detalhes e dúvidas" abre a foto, o "Sobre", a FAQ do curso, o preço completo, o botão, o atalho do chat e
    "Peça uma turma".
  - O comparador dos três cursos de primeiros socorros fecha o grupo "Emergência e vida".
- **"O que acontece depois que você paga":** vem logo depois do catálogo, com a garantia dos 7 dias no texto
  literal de `/reembolso/`.
- **"Quem dá o curso":** só fatos de `/historia/` e da FAQ da home.
- **FAQ reescrita:** "Os R$ 99 são o valor do curso?" vem primeiro, e o único link para a escola no corpo da
  página fica nela. Embaixo, o botão final, que muda para o curso em foco.
- **Turmas para grupos:** viraram uma faixa compacta no fim, com o formulário recolhido. Ele abre no botão "Pedir
  turma para grupo", nos links dos cursos e com `?turma=1`. Os dados pessoais só aparecem quando o pedido é
  possível.
- **Barra fixa no celular:** aparece com um curso em foco, nenhum botão de matrícula na tela e fora da seção de
  turmas e do rodapé.
- **Chat:** o botão ficou branco, para o vermelho forte ficar só nos botões de matrícula. No celular vira um
  círculo, e ele some enquanto um botão de matrícula passa por baixo dele. É CSS local: `chat.js` e `chat.css` não
  mudam.
- **Aviso de cookies:** muda só o espaçamento no celular, também por CSS local. O texto e a REVISAO não mudam.

**Links para a escola:** ficam 4 de 11: "Plataforma" no cabeçalho, 1 na FAQ e 2 no rodapé. Todos levam
`utm_source=cruzvermelhariodejaneiro&utm_medium=matricula&utm_content=<lugar>` e disparam `saida_escola` (GA4) e
`SaidaEscola` (Pixel, `trackCustom`).

**Medição:** a tabela está em `docs/rastreamento.md`, seção "Página de matrícula (04/10/2026)". Os eventos são:

- `view_item_list`;
- `cta_matricula_visto`;
- `select_item`, com `local` e `modo`;
- `barra_fixa_vista`;
- `faq_aberta`;
- `secao_vista`;
- `chat_atalho`;
- `saida_escola`/`SaidaEscola`;
- `turma_abrir`, com a `origem`;
- o `ViewContent`, no modo curso e ao abrir detalhes.

Os botões levam `via=<lugar>` no endereço do checkout, que aparece no `page_location` do `begin_checkout`.

**Medidas:** feitas localmente, com o logo e as fotos do ar.

| | Antes | Depois |
| --- | --- | --- |
| 1º botão de checkout, celular 390×844 | 2.535 px (2 telas abaixo) | 571 px |
| 1º botão, modo curso (`?curso=`), celular | 1,84 a 2,1 telas | 570 px |
| 1º botão, computador 1280×800 | cerca de 1.660 px | 774 px |
| Comprimento, celular | 12,1 telas | 7,5 com o rodapé (6,5 sem) |
| Comprimento, computador | 6,9 telas | 6,3 |

**Travas no gerador:**

- recusa gerar com `[INSERIR`, `[CONFIRMAR` ou `[DECIDIR` na página, ou com mais de um `<h1>`;
- `MOSTRAR_TOTAL` e `GARANTIA_APOS_7_DIAS` ficam desligados até as decisões D1 e D3;
- `ESCOLA_MATRICULA_AUTOMATICA` está ligada, porque `config-escola.php` existe no servidor (responde 403, e não 404).

**Prova social:** nenhuma foi inventada. O dicionário `PROVA` está vazio até haver dado real e autorizado.

**Decisões do dono** (seção 8 da especificação):

- D1: os R$ 99 quitam a inscrição de R$ 100 da escola? Se sim, liga o total.
- D3: a matrícula automática numa turma com data já conta como "turma confirmada" para o reembolso?
- D5: WhatsApp no formulário de turmas.
- D2: o desenho dos links para a escola. Reavaliar com 4 semanas de `saida_escola`.

**Testes:**

- `scripts/conferir_pixel.js --repositorio`: 15 cenários certos. Os 3 novos são do modo curso: aceitou (`ViewContent`
  com o mesmo id no repasse), sem escolher e rejeitou (nada vai para a Meta).
- `php scripts/testar_checkout.php`: 350 testes.
- O JSON-LD valida sem erro.
- O formulário de turmas foi testado no Chromium, no celular: abre fechado, o link do curso já preenche, a
  matrícula na hora aparece sem os dados pessoais, e a lista em inglês foi enviada ao banco local.
- Capturas no computador e no celular.

**Publicar** (depois do OK do Matheus): `scripts/publicar_hostinger.sh site/matricula-cursos-presenciais/static/turmas.js
site/matricula-cursos-presenciais/index.html`, limpar o cache e rodar `scripts/conferir_publicacao.sh` e
`NODE_PATH=$(npm root -g) node scripts/conferir_pixel.js`. Desfazer: republicar as duas versões anteriores do Git.

## Checkout da inscrição (`/matricula-cursos-presenciais/checkout/`)

Construído e publicado em 18/09/2026. É a alternativa da seção 17 do briefing: backend em **PHP 8.3 + MySQL na própria Hostinger**, no
mesmo domínio (sem CORS, sem Vercel, sem Supabase), reaproveitando o contrato da Unicopag
validado no projeto da Punção Venosa.

- **Páginas** (geradas por `scripts/gerar_checkout.py`, com cabeçalho, rodapé, CSS, GA4 e Pixel da
  home; todas `noindex`): `checkout/` (dados do aluno + PIX ou cartão + opção de cobrir os custos
  de processamento), `pendente/` (PIX de novo, acompanhamento), `parabens/` (pago + acesso).
  O botão "Fazer matrícula agora" da página de matrícula leva a `checkout/?curso=<slug>` com as UTMs.
- **Backend** em `site/matricula-cursos-presenciais/api/` (reorganizado em 18/09, à noite):
  `info.php` (preços e custos por método), `pagamentos.php` (cria a cobrança na Unicopag; PIX
  pendente do mesmo CPF/curso/valor é reaproveitado; cartão passa em claro e nunca é gravado),
  `status.php` (estado por token; enquanto pendente reconsulta a Unicopag a cada 6 s),
  `webhook.php` (postback: só o hash é usado, o status vem da reconsulta). `lib.php` é só o
  bootstrap (erros nunca vão para a tela; tratador global responde JSON 500/503) e carrega os
  módulos de `api/lib/`: `config.php` (configuração, catálogo, preços), `http.php` (respostas,
  guardas da requisição, validações), `db.php` (PDO, tabelas `mcp_inscricoes` e `mcp_eventos`
  criadas sozinhas, freios), `unicopag.php` (cliente, tradução de status e a porta única de
  mudança de status, com transições atômicas no SQL), `escola.php` (API da escola),
  `email.php` (Resend com fallback em `mail()`), `publico.php` (o que as páginas podem ver).
- **Design (19/09)**: topo com as três etapas da matrícula (curso escolhido, pagamento,
  confirmação da turma; a tela Parabéns marca a terceira), formulário em três blocos numerados
  (Curso, Seus dados, Pagamento) com a ficha do curso em etiquetas (carga horária, escolaridade,
  endereço), formas de pagamento em cartões com ícone, opção de custos em cartão com o valor à
  direita, faixa de confiança sob o botão (pagamento seguro, estorno, certificado) e resumo com a
  foto do curso, total em destaque e o que a inscrição garante. No celular o resumo vira uma faixa
  compacta acima do formulário. Nome, foto e ficha de cada curso vão embutidos na página (JSON
  gerado do `cursos.json`), então o resumo aparece antes de a API responder.
- **Front-end**: CSS e JS do checkout ficam em `site/matricula-cursos-presenciais/static/`
  (`checkout.css`, `checkout.js`, um arquivo para as três telas, escolhidas por
  `<body data-tela>`); as páginas os referenciam com hash do conteúdo na URL (`?v=`), e a pasta
  manda `Cache-Control: immutable`. Validação local espelha a do servidor (CPF, Luhn do cartão,
  telefone), com mensagens ligadas ao campo (`aria-live`, foco no campo errado).
- **Testes automatizados**: `php scripts/testar_checkout.php` roda 59 verificações das funções
  puras (CPF, Luhn, telefone, preços e custos, tradução de status, tokens, mensagens da Unicopag,
  visão pública sem CPF/hash/IP) sem banco, rede ou segredos.
- **Dados mínimos**: nome, CPF, e-mail e WhatsApp. A Unicopag exige `customer.document` (testado:
  sem ele a API responde 422), mas **não exige que seja um CPF** — ver "O que a Unicopag valida no
  documento", abaixo. A trava de CPF é nossa.
- **Custos de processamento**: checkbox opcional; valor por método em `config.php`. Decisão de
  18/09: **5% em todos os métodos** (média de PIX, cartão e checkout), sem parcela fixa: R$ 4,95
  sobre R$ 99. Para referência, o PIX medido pela API em 18/09 custou 1,00% + R$ 1,48.
- **Pós-pagamento**: e-mail ao aluno pelo Resend, remetente
  `matricula@info.cruzvermelhariodejaneiro.org` (domínio verificado; sem `RESEND_API_KEY` cairia
  no `mail()` da Hostinger) e aviso à secretaria (`EMAIL_SECRETARIA`). Com `ESCOLA_API_URL` configurada, chama a
  API da escola (contrato da seção 8.2 do briefing), guarda o acesso devolvido e mostra usuário,
  link ou senha temporária na tela Parabéns e no e-mail (versão A); sem API, tela e e-mail dizem
  que a secretaria fecha turma e horário pelo WhatsApp em até 2 dias úteis (versão B). Retentativa
  automática da escola (até 5, 1 por minuto) enquanto o aluno estiver na tela.
- **Segredos**: `api/config.php` só existe no servidor (está no `.gitignore`); modelo em
  `api/config.example.php`. `.htaccess` nega acesso direto a `config.php`, `lib.php`, ao modelo e
  à pasta `api/lib/` inteira (conferido ao vivo: 403). Banco: `u448697994_matricula` (criado pela
  API da Hostinger em 18/09).
- **Segurança (revisão de 18/09)**: `display_errors` da Hostinger vem ligado por padrão e o
  bootstrap desliga (nenhum aviso do PHP vaza caminho ou SQL); `pagamentos.php` só aceita POST
  com `Content-Type: application/json` vindo do próprio site (`Origin`/`Sec-Fetch-Site`; outro
  site recebe 403), corpo até 64 KB (413 acima), campo armadilha escondido contra robôs
  (preenchido = 422 sem chamar a Unicopag) e freios por IP (12 em 10 min), CPF e e-mail (6 por
  hora cada); tokens de 160 bits aleatórios; cartão validado (Luhn, validade) e descartado após
  a chamada; a resposta pública nunca leva CPF, hash, IP, telefone nem número de cartão; SQL só
  com prepared statements e nomes de coluna fixados no código; chamadas externas só em HTTPS;
  `.htaccess` da pasta manda `X-Frame-Options`, `Referrer-Policy`, `X-Content-Type-Options`,
  `Permissions-Policy` e uma CSP mínima (`frame-ancestors`, `base-uri`, `object-src`). **Não
  feito de propósito**: CSP completa (GA4 e Meta Pixel injetam scripts e conexões que uma
  política estrita quebraria em silêncio) e criptografia do CPF no banco (a secretaria precisa
  lê-lo; o banco só é alcançável de dentro da Hostinger).
- **Publicar** (na ordem): `scripts/publicar_hostinger.sh site/matricula-cursos-presenciais/cursos.json
  site/matricula-cursos-presenciais/api/lib/.htaccess site/matricula-cursos-presenciais/api/lib/*.php
  site/matricula-cursos-presenciais/api/.htaccess site/matricula-cursos-presenciais/api/*.php
  site/matricula-cursos-presenciais/.htaccess site/matricula-cursos-presenciais/static/.htaccess
  site/matricula-cursos-presenciais/static/checkout.css site/matricula-cursos-presenciais/static/checkout.js
  site/matricula-cursos-presenciais/checkout/index.html site/matricula-cursos-presenciais/pendente/index.html
  site/matricula-cursos-presenciais/parabens/index.html` (o `cursos.json` precisa estar no servidor:
  é o catálogo que a API valida; os módulos de `lib/` vão antes de `lib.php` e dos endpoints),
  subir `config.php` preenchido para `public_html/matricula-cursos-presenciais/api/`, testar com
  `PRECO_TESTE_CENTAVOS` (ex.: 100), remover o teste e só então publicar
  `site/matricula-cursos-presenciais/index.html` (botões apontando para o checkout) e limpar o cache.
- **Testes feitos em 18/09**: chave válida (`/public/v1/balance`); PIX de R$ 1 e R$ 99 criados direto
  na API (não pagos, expiram em 24 h); no servidor, com `PRECO_TESTE_CENTAVOS=100`: `info.php`,
  criação de PIX pelo `pagamentos.php` (R$ 1 + R$ 1,49 de custos), `status.php`, reaproveitamento
  do PIX pendente, validações de CPF e cartão, postback com hash desconhecido e sem hash; no
  navegador real: formulário, QR code, copia-e-cola, tela pendente, redirecionamento da tela
  Parabéns sem pagamento e checkout no celular. O preço de teste foi removido em seguida e o
  botão "Fazer matrícula agora" da página de matrícula passou a levar ao checkout. Depois da
  reorganização (18/09, à noite), repetido ao vivo com `PRECO_TESTE_CENTAVOS=100`: arquivos
  internos negados (403), cabeçalhos de segurança presentes, origem estranha (403), armadilha
  (422), token inválido (404), postback desconhecido (200 ignorado), PIX de R$ 1,05 criado,
  status e reaproveitamento pelo mesmo token, e no navegador: formulário com a linha de custos
  aparecendo só quando marcada (corrigido o `hidden` que o CSS ignorava), QR code, copiar
  código, tela pendente e celular. Preço de teste removido em seguida. **Não testado**:
  pagamento confirmado de verdade (PIX pago ou cartão aprovado), e-mails e postback real, que só
  acontecem com um pagamento real; primeiro pagamento merece acompanhamento na tabela `mcp_eventos`.

## Matrícula paga entra na plataforma da escola (28/09/2026)

Assim que a Unicopag confirma a inscrição de R$ 99, o site chama a função
`public.matricula_rapida` no banco da escola (Supabase `wrckokgdtiwvxapqzkki`), com a chave
secreta. A função:

1. acha o aluno pelo CPF ou cria a conta;
2. matricula na próxima turma aberta do curso, com a taxa confirmada e o pagamento `TAXA` registrado;
3. devolve o resultado.

O aluno vê na tela Parabéns e no e-mail que está matriculado e em qual turma. Se a conta é nova,
recebe um botão "Criar minha senha": é um link único da própria escola, válido por 72 horas, e a
senha nunca é os 4 últimos dígitos do CPF. Se ele já tinha conta, é orientado a entrar com a senha
de sempre. Sem turma aberta, só a conta é criada, e a secretaria matricula depois.

O aviso à secretaria ganhou uma linha "Escola" que diz o que aconteceu: matriculado em tal turma,
sem turma aberta, ou "NÃO MATRICULADO" com o motivo.

- **Onde está o código:** `api/lib/escola.php`, `publico.php`, `email.php` e `static/checkout.js`.
- **A chave:** fica em `api/config-escola.php`, só no servidor e fora do Git; o `config.php` não
  muda. Sem esse arquivo, o site continua na versão B (a secretaria escreve para o aluno).
- **SQL, diagnósticos, testes, limpeza e como desfazer:** [`docs/escola/README.md`](docs/escola/README.md).
- **Testes:**
  - 84 testes pgTAP da função numa cópia local do banco da escola;
  - os testes de `scripts/testar_checkout.php` (291 em 30/09/2026);
  - 21 testes de ponta a ponta em `scripts/testar_escola_integracao.php` (site → PostgREST local
    → cópia da escola), com a consulta das aulas do ponto da sede.

## Portal da secretaria (29/09/2026)

O antigo painel da equipe (`api/painel.php`) virou o portal da secretaria. O menu no cabeçalho tem
estas seções:

- **Início:** quatro números que pedem ação, cada um levando à lista dele:
  - inscrições pagas, com as dos últimos 7 dias;
  - as que precisam de atenção;
  - quem pagou e ainda não disse os horários;
  - mensagens novas do chat.

  Abaixo ficam as últimas inscrições pagas, com a situação na escola, as mensagens recentes e os
  horários mais pedidos em todos os cursos.
- **Inscrições** (`?v=inscricoes`):
  - filtros com a contagem de cada um: Pagas, Precisam de atenção, Sem horários, Aguardando
    pagamento e Todas. "Precisam de atenção" são as pagas em que a matrícula na escola não foi
    feita, falhou, ficou sem turma aberta ou encontrou a taxa já paga;
  - busca por nome, e-mail, telefone (4 dígitos ou mais) ou CPF (11 dígitos), e filtro por curso;
  - "Baixar planilha": um CSV do que está na tela, **sem CPF**, porque o arquivo costuma circular.
- **Ficha da inscrição** (`?v=inscricao&id=N`), com estes quadros:
  - o aluno, com CPF;
  - o pagamento;
  - a matrícula na escola: a situação, o que fazer e se a conta foi criada pelo site ou já existia;
  - os horários;
  - o histórico, em palavras da secretaria, sem IP nem detalhe técnico.

  Quem pagou e ainda não disse os horários tem o botão **"Mandar lembrete agora"**:
  - manda o mesmo e-mail dos lembretes automáticos;
  - sai no máximo um lembrete a cada 24 horas, somando os automáticos;
  - o histórico registra quem mandou;
  - usa a mesma trava do cron, então um clique duplo ou a rodada da hora não mandam dois e-mails.
- **Horários dos alunos** (`?v=horarios`): o mapa e a lista do questionário, como antes. O nome do
  aluno abre a ficha, e o aviso de quem falta leva à lista "Sem horários".
- **Mensagens do chat** (`?v=mensagens`): a lista e as respostas de antes. Os endereços antigos
  (`painel.php?f=…` e `?id=…`) continuam valendo.
- **Ponto da sede** (`?v=ponto`): horas doadas por voluntários e diretoria, presença dos outros
  vínculos, presenças dos alunos nas aulas e aparelhos da recepção. O número no menu soma as saídas
  esquecidas e os termos de adesão pendentes. Veja a seção
  [Ponto da sede e comprovante de comparecimento](#ponto-da-sede-e-comprovante-de-comparecimento-29092026).
- **Plataforma da escola:** link para `ESCOLA_URL/login`, em outra aba.

Os números no menu são vermelhos quando pedem ação (inscrições com atenção, mensagens novas) e
cinza para quem ainda não disse os horários. No celular:
- o menu rola de lado, com sombra na borda quando há mais itens;
- o menu abre já na seção atual;
- as tabelas viram cartões.

- **Acesso:** como antes. O link de entrada vai por e-mail para os endereços de `PAINEL_EMAILS` (sem
  ele, `EMAIL_CONTATO` e `EMAIL_SECRETARIA`), e a sessão dura 12 h.
- **Código:**
  - `api/painel.php`: as telas;
  - `api/lib/secretaria.php`: consultas, situação na escola, histórico, lembrete à mão e planilha.
- **Testes:**
  - 18 dos 291 testes de `scripts/testar_checkout.php`;
  - 29 dos 88 testes de `scripts/testar_horarios_integracao.php`: início, menu, filtros, busca,
    planilha, ficha e o lembrete à mão com as travas.
- **Para publicar:** `api/painel.php`, `api/lib.php`, `api/lib/secretaria.php` (novo),
  `api/lib/horarios.php`, `api/lib/email.php` e `api/lib/painel.php`. Vai junto com o ponto da sede,
  na ordem da lista daquela seção.
- **Situação:** pronto e testado localmente, com capturas no computador e no celular. Publicar só
  com o OK do Matheus.

## Turmas sob demanda: grupos, inglês e jovens (04/10/2026)

Alguns cursos só abrem quando juntamos alunos suficientes. A página de matrícula ganhou a seção
**Turmas sob demanda** (`#turmas-sob-demanda`, logo depois do catálogo) para três públicos:

- **grupos fechados**: empresas, escolas, igrejas, condomínios ou amigos com 15 a 30 alunos, em qualquer curso;
- **cursos em inglês**: qualquer curso, com professor ou tradutor;
- **primeiros socorros para jovens de 12 a 14 anos**: curso que existe só sob demanda, fora do catálogo da escola.

Regras combinadas com o Matheus em 04/10:

- turma de **15 a 30 alunos**, no **mesmo valor por pessoa** dos cursos do catálogo;
- aulas **na sede**; em outro local, só com aprovação;
- **quem já tem o grupo tem prioridade**. Quem não tem entra na lista de interesse e é avisado quando a turma fechar.

**Um formulário só**, e o número de alunos decide o que acontece:

| Alunos | Curso e idioma | O que acontece |
| --- | --- | --- |
| 15 ou mais | qualquer | **Pedido de turma fechada**. A secretaria responde em até 3 dias úteis com as datas. |
| menos de 15 | em inglês, ou o curso dos jovens | **Lista de interesse** daquele curso naquele idioma. A turma abre quando a soma chega a 15. |
| menos de 15 | curso do catálogo, em português | Não entra em lista: o curso já tem turma aberta, e o botão vira "Fazer matrícula agora". |

Mais de 30 alunos vale, e a secretaria divide em mais de uma turma (limite de 300 no formulário).
Nada é cobrado nesta etapa. Para o curso dos jovens, quem preenche é o responsável ou a
instituição: nenhum dado do jovem é pedido.

Onde mais aparece na página:

- uma linha no topo ("Empresas, grupos de 15 pessoas ou mais e turmas em inglês: feche sua turma");
- um link em cada curso do catálogo ("Monte uma turma"), que abre o formulário com o curso escolhido;
- três perguntas novas no FAQ, que também entram no FAQPage dos dados estruturados.

**Link para anúncio:**
`/matricula-cursos-presenciais/?turma=1&turma_curso=suporte-basico-de-vida&idioma=en&alunos=15` abre a
seção com o formulário já preenchido.

**Portal da secretaria** (`?v=turmas`, item "Turmas sob demanda" no menu). O número no menu soma os
pedidos de turma fechada ainda sem resposta e as listas que já chegaram a 15. A tela tem:

- **pedidos de turma fechada**, com instituição, WhatsApp clicável, local (com o selo "Fora da sede")
  e período preferido. A situação de cada pedido muda ali mesmo: Novo, Em contato, Turma marcada ou Arquivado;
- **listas de interesse** por curso e idioma, com a barra de progresso até 15 e o selo "Pronta para abrir";
- a **lista de um curso** (`&curso=…&idioma=…`), com quem está nela, a planilha para avisar todos e o
  botão "Mudar todos", que marca todos os pedidos em aberto da lista de uma vez quando a turma abre.

A planilha não leva IP e neutraliza fórmulas.

**E-mails.** O aviso vai para `EMAIL_SECRETARIA` (sem ele, para `EMAIL_CONTATO`), com responder-para de
quem pediu. O assunto começa com `[Turma fechada]`, `[Lista de interesse]` ou, quando a soma chega a 15,
`[Lista completa]`. A pessoa recebe a confirmação com o protocolo `TS-aammdd-NNNN`, o que acontece agora e
o valor por aluno.

**Medição.** O envio dispara `SubmitApplication` no Pixel e `turma_pedido` no GA4 (com `turma_tipo`, `curso`,
`idioma` e `alunos`). A API de Conversões manda o mesmo `SubmitApplication`, com o mesmo id e só com "sim" para
marketing, como o `Contact` do chat. Não é `Lead`: o `Lead` e o `generate_lead` são do funil pago do checkout,
e misturar pedidos de turma neles atrapalharia a otimização dos anúncios de matrícula (corrigido em 04/10,
depois da publicação; até então o formulário mandava `Lead`).

**Soma da lista:** cada e-mail conta uma vez, pelo maior pedido em aberto. Quem reenvia o formulário não
infla a lista nem dispara um falso "lista completa". O "Mudar todos" só mexe nos pedidos que estavam na tela
(id até o maior mostrado): quem entrou na lista depois continua nela. Uma lista de curso que saiu do catálogo
continua abrindo no portal. As três correções vieram da revisão adversarial de 04/10 e foram **publicadas em
04/10, às 18h37 (Brasília)**, com o OK do Matheus, junto com o evento novo (`SubmitApplication`/`turma_pedido`).

- **Lista:** 5 arquivos, em `scripts/publicacao-turmas-correcoes.txt`.
- **Antes:** os 5 foram copiados do ar e eram idênticos aos da `main` (6855c15).
- **Conferência:** 33 de 33.
  - O `turmas.js` no ar tem o hash novo.
  - O formulário funciona no Chromium, no computador e no celular.
  - O portal abre (`?v=turmas`).
- **Desfazer:** `scripts/desfazer_publicacao.sh 6855c15 scripts/publicacao-turmas-correcoes.txt`.

**Proteções:** as mesmas do chat de contato. Só aceita POST JSON vindo do próprio site, tem campo
armadilha e freios de 6 pedidos por IP e 4 por e-mail, por hora.

**Código:**

- `api/lib/turmas.php`: regras, conferência, banco, e-mails e planilha;
- `api/turmas.php`: o endpoint;
- `pn_turmas` em `api/painel.php`: a tela do portal;
- `mcp_meta_turma` em `api/lib/meta.php`: o evento da API de Conversões;
- `static/turmas.js`: o formulário;
- a seção da página: `scripts/gerar_matricula_presencial.py`.

O mínimo, o máximo e o curso dos jovens estão em `lib/turmas.php` e repetidos no gerador.
`scripts/testar_checkout.php` confere que os dois batem.

**Tabela:** `mcp_turmas_pedidos`, criada sozinha na primeira requisição depois do deploy (`MCP_DB_VERSAO`
mudou).

**Testes:**

- 17 dos 350 testes de `scripts/testar_checkout.php`: regras, conferência, e-mails, planilha e a página
  conferida contra o servidor;
- `scripts/testar_turmas_integracao.php`, 22 testes contra MariaDB local: API, banco, e-mails e portal,
  inclusive a mudança de situação e o "Mudar todos";
- os testes de integração que já existiam (horários, ponto, avisos, meta e doação) passam com o menu novo;
- formulário testado no navegador (Chromium) no computador e no celular: turma fechada, matrícula na hora,
  lista em inglês, envio e o link "Monte uma turma" de um curso.

**Não testado:** e-mails de verdade pelo Resend (no teste, o envio cai num `sendmail` falso) e o evento na
Meta.

**Para publicar** (a lista está em `scripts/publicacao-turmas.txt`; nesta ordem: os módulos de `lib/` antes
do `lib.php`, que passa a exigir `lib/turmas.php`, e a página por último):

```
scripts/publicar_hostinger.sh site/matricula-cursos-presenciais/api/lib/turmas.php \
  site/matricula-cursos-presenciais/api/lib/meta.php site/matricula-cursos-presenciais/api/lib/db.php \
  site/matricula-cursos-presenciais/api/lib.php site/matricula-cursos-presenciais/api/turmas.php \
  site/matricula-cursos-presenciais/api/painel.php site/matricula-cursos-presenciais/static/turmas.js \
  site/matricula-cursos-presenciais/index.html
```

Depois, limpar o cache e conferir: o portal abre em `?v=turmas`, e um pedido de teste chega à secretaria.
Arquive o pedido de teste no portal.

**Situação: publicado em 04/10/2026, às 18h22 (Brasília),** com o OK do Matheus, a partir da `main` depois do
PR #53, na ordem de `scripts/publicacao-turmas.txt`.

- **Antes:** os 5 arquivos que já existiam foram copiados do ar e eram idênticos aos da `main`; os outros 3
  eram novos.
- **Envio:** os 8 arquivos subiram em 11 segundos, e o cache foi limpo.
- **Conferência no ar:** `scripts/conferir_publicacao.sh scripts/publicacao-turmas.txt` deu 33 de 33.
  - A página e o `turmas.js` estão iguais aos do repositório.
  - O `api/turmas.php` responde 405 a GET.
  - O `api/lib/turmas.php` está bloqueado (403).
  - O ponto continua respondendo 200, o que prova que a migração da tabela nova rodou.
  - Pedidos que param na validação foram conferidos em produção, sem gravar nada: matrícula na hora em
    português, curso inexistente, endereço faltando e outro site (403).
  - No navegador (Chromium, computador e celular), o link de anúncio preenche o formulário, o catálogo
    continua abrindo no primeiro curso e não há erro de JavaScript.
- **Não feito:** nenhum pedido de verdade foi enviado, para não mandar e-mail falso à secretaria. O primeiro
  pedido real confirma o envio dos e-mails e a gravação.
- **Desfazer:** `scripts/desfazer_publicacao.sh 9a9049f scripts/publicacao-turmas.txt`. A tabela fica no
  banco, e o código anterior não a usa.

**Próximos passos possíveis:**

- botão "Avisar todos que a turma abriu", que manda a data e o link do checkout a quem está na lista;
- página em `/en/` para os cursos em inglês;
- pagamento da turma fechada por um link único do responsável.

## Ponto da sede e comprovante de comparecimento (29/09/2026)

Quem chega à sede registra a chegada em `cruzvermelhariodejaneiro.org/ponto/`, que leva a
`/matricula-cursos-presenciais/ponto/`:
- **colaboradores** registram entrada e saída, e as horas doadas à instituição somam no portal da
  secretaria;
- **alunos** registram a chegada à aula presencial do dia e, quando a aula termina, recebem o
  comprovante de comparecimento para baixar e por e-mail.

A pessoa se identifica pelo CPF. Quem é colaborador e aluno vê as duas coisas.

- **Dois jeitos de registrar,** na mesma página:
  - **aparelho da recepção:** um tablet ou computador que a secretaria liberou no portal (Ponto da
    sede → Aparelhos e QR code → "Liberar este aparelho"). Ele mostra um teclado numérico grande e
    volta sozinho para o início depois de cada registro. A liberação é um cookie assinado de 400 dias
    e pode ser desativada no portal a qualquer hora;
  - **celular da pessoa:** pelo QR code do cartaz para imprimir em A4
    (`/matricula-cursos-presenciais/ponto/cartaz/`). Só vale com a localização do celular a até
    150 m da sede, com uma folga de até 100 m pela imprecisão do GPS. O site guarda só a distância,
    nunca a localização. A pessoa pode marcar "Lembrar de mim neste celular": o CPF fica cifrado
    num cookie de 180 dias.
- **Colaboradores:**
  - a secretaria cadastra cada um no portal, com nome, CPF, **vínculo**, função e contatos (veja
    [Vínculo e termo de adesão](#vínculo-e-termo-de-adesão-30092026));
  - no ponto, o colaborador vê se está na sede e desde quando e o botão "Registrar entrada" ou
    "Registrar saída". Voluntários e diretoria veem também as horas doadas de hoje e do mês;
  - contam só os pares entrada–saída, com pelo menos 1 minuto entre as duas;
  - uma entrada de voluntário sem saída há mais de 16 horas é uma **saída esquecida**. Ela não conta, a
    pessoa pode registrar outra entrada, e o número aparece no menu do portal até a secretaria corrigir.
- **Alunos:**
  - o site pergunta à escola, pela função `aulas_do_aluno` (só leitura), as aulas daquele CPF no dia:
    curso, data e horário vêm da agenda da turma na escola (`AulaData`). Matrícula cancelada ou
    estornada e turma cancelada não entram;
  - dá para registrar a chegada de 3 horas antes do início até o fim da aula, uma vez por aula;
  - o horário é lido do texto da escola ("18:00 - 22:00", "9h às 12h", "18h30-22h"). Se não der
    para ler, a aula vale o dia todo e termina às 23:59;
  - só quem registrou presença recebe comprovante, e só depois que a aula termina.
- **O comprovante de comparecimento:**
  - é um PDF no desenho do comprovante de inscrição, com o aluno e o CPF, o curso, o dia e o horário
    da aula, a hora da chegada, o local e um código de conferência;
  - a página pessoal `/matricula-cursos-presenciais/comparecimento/?t=…` libera o botão "Baixar
    comprovante (PDF)" quando a aula termina, sem recarregar. O link vai por e-mail; a tela do ponto não
    o mostra, nem no celular (desde 30/09/2026: quem soubesse o CPF de outra pessoa chegaria ao nome
    completo dela e ao PDF) nem no aparelho da recepção;
  - o e-mail com o PDF anexado vai para o e-mail que a escola tem do aluno. Quem manda é
    `api/comparecimentos.php`, rodado pelo cron da Hostinger a cada 15 minutos. São até 5 tentativas
    por presença, para aulas dos últimos 30 dias. Só linha de comando; por HTTP responde 404 e o
    `.htaccess` nega.
- **Conferência:** em `cruzvermelhariodejaneiro.org/conferir/`, que leva a
  `/matricula-cursos-presenciais/conferir/`, quem recebe um comprovante ou uma declaração de horas
  digita o código de 8 caracteres (ex.: `K7QM-3XPD`). A página mostra se o documento é verdadeiro,
  com o nome, o CPF mascarado (`***.456.789-**`) e os dados. Presença cancelada aparece como
  cancelada. Cada IP pode consultar 30 vezes a cada 10 minutos. O `/verificar` continua sendo da
  auditoria.
- **No portal da secretaria,** a seção **Ponto da sede** (`api/painel.php?v=ponto`) tem três abas:
  - **Colaboradores:**
    - o mês, com setas para os anteriores;
    - quem está na sede agora, de todos os vínculos;
    - horas e dias de cada voluntário e da diretoria, com as saídas esquecidas e os termos pendentes;
    - em lista à parte, a presença de empregados, terceirizados e outros, sem horas;
    - a planilha CSV das horas do mês, sem CPF e só com voluntários e diretoria.

    A ficha de voluntário ou diretoria (`?v=colaborador&id=N`) mostra o termo de adesão e edita o
    cadastro. Ela lista os registros do mês com "Corrigir" e "Apagar", lança horas à mão e emite a
    **declaração de horas voluntárias** em PDF, com código de conferência, depois que o termo está
    registrado. Correções e lançamentos pedem motivo, valem até 16 horas por turno e não podem
    sobrepor outro registro. O histórico do ajuste fica no registro. A ficha dos outros vínculos
    mostra só a presença;
  - **Alunos nas aulas:** as presenças de cada dia e a situação do comprovante: sai às HH:MM, pronto,
    enviado, envio falhou, sem e-mail ou cancelada. Cada presença tem "Ver" e "Cancelar". Cancelada,
    o comprovante deixa de baixar e de valer;
  - **Aparelhos e QR code:** liberar o aparelho em uso, abrir o cartaz para imprimir e desativar
    aparelhos.
- **Segurança:**
  - depois de conferir o CPF e o local, o servidor devolve uma sessão de 3 minutos cifrada
    (AES-256-GCM). Quem mexe no aparelho da recepção não lê o CPF nem o e-mail de quem usou antes, e
    ninguém consegue alterar a sessão;
  - consultas de CPF: até 15 a cada 10 minutos por IP no celular, 240 por aparelho da sede;
  - para um CPF sem aula no dia, a escola não devolve o nome, então a consulta não revela quem é aluno;
  - no portal, tudo passa pela sessão e pelo CSRF de sempre e fica no histórico (`mcp_eventos`);
  - a página do ponto não carrega Analytics, Pixel nem o chat.
- **Configuração:** nada obrigatório. `PONTO_SEDE_LAT`, `PONTO_SEDE_LNG` e `PONTO_RAIO_METROS`, no
  `api/config.php` do servidor, trocam o ponto da sede e o raio. O padrão é a Praça da Cruz Vermelha,
  10 (-22.91132, -43.18779, pelo OpenStreetMap) e 150 m. A consulta às aulas usa a chave da escola
  que já está em `api/config-escola.php`, com a URL de `ESCOLA_API_URL` trocando `matricula_rapida`
  por `aulas_do_aluno`.
- **Código:**
  - `api/lib/ponto.php`: regras do ponto, aparelhos, sessão, relatórios, correções e declaração de horas;
  - `api/lib/presenca.php`: consulta à escola, horário da aula, presenças, comprovante, e-mail e conferência;
  - `api/lib/declaracao.php`: códigos de conferência e o PDF das declarações;
  - `api/ponto.php`, `api/comparecimento.php` e `api/conferir.php`: as APIs;
  - `api/comparecimentos.php`: o cron dos e-mails;
  - `api/painel.php`: a seção Ponto da sede;
  - `static/ponto.js` e `ponto.css`: a página do ponto;
  - `static/checkout.js` e `checkout.css`: as páginas do comprovante e da conferência;
  - `scripts/gerar_checkout.py`: gera `ponto/`, `ponto/cartaz/`, `comparecimento/` e `conferir/`;
  - tabelas `mcp_colaboradores`, `mcp_ponto`, `mcp_ponto_aparelhos`, `mcp_presencas` e
    `mcp_declaracoes_horas`, criadas sozinhas na primeira chamada;
  - na escola: `docs/escola/aulas_do_aluno.sql` ([`docs/escola/README.md`](docs/escola/README.md#aulas-do-aluno-para-o-ponto-da-sede)).
- **Testes:**
  - 29 dos 291 testes de `scripts/testar_checkout.php`: horário da aula, janela, distância, códigos
    e PDFs;
  - 100 testes de ponta a ponta em `scripts/testar_ponto_integracao.php`, com uma escola falsa:
    - aparelho e celular;
    - entrada, saída e saída esquecida;
    - presença, comprovante e e-mail;
    - conferência;
    - portal, com correções, lançamentos, declaração e cancelamento.

    Para rodar: `MCP_CONFIG_ARQUIVO=/caminho/config-teste.php php scripts/testar_ponto_integracao.php`.
    O teste recusa banco que não seja local e apaga no fim o que criou;
  - 24 testes pgTAP de `aulas_do_aluno` e o passo 7 de `scripts/testar_escola_integracao.php`, com a
    chamada de verdade pelo PostgREST local.
- **Para publicar,** junto com o portal da secretaria e nesta ordem:
  1. antes de tudo, aplicar `docs/escola/aulas_do_aluno.sql` no banco da escola. Sem a função, o
     ponto dos colaboradores funciona, e o aluno vê "Não conseguimos consultar as aulas na escola agora";
  2. `api/lib/secretaria.php`, `declaracao.php`, `ponto.php` e `presenca.php` (novos), e
     `api/lib/db.php`, `email.php` e `painel.php`;
  3. `api/lib.php`;
  4. `api/painel.php` e `api/lib/horarios.php`;
  5. `api/ponto.php`, `comparecimento.php`, `conferir.php` e `comparecimentos.php` (novos) e `api/.htaccess`;
  6. `static/ponto.css` e `ponto.js` (novos), `checkout.css` e `checkout.js`;
  7. as páginas `checkout/`, `pendente/`, `parabens/` e `horarios/`, e as novas `ponto/`,
     `ponto/cartaz/`, `comparecimento/` e `conferir/`;
  8. o `.htaccess` da raiz do site (atalhos `/ponto/` e `/conferir/`), e limpar o cache;
  9. criar o cron a cada 15 minutos:
     `/opt/alt/php83/usr/bin/php /home/u448697994/domains/cruzvermelhariodejaneiro.org/public_html/matricula-cursos-presenciais/api/comparecimentos.php`.
- **Na sede:** imprimir o cartaz, liberar o tablet da recepção no portal e cadastrar os colaboradores.
- **Situação:** pronto e testado localmente, com capturas no tablet, no celular, nos PDFs e no portal.
  Publicado em 30/09/2026 com o vínculo e as políticas (versão `a237b7b`).

### Vínculo e termo de adesão (30/09/2026)

Do presidente ao pessoal da limpeza, todo mundo registra no ponto quando chega e quando vai embora.
Cada vínculo tem uma regra, para a instituição ficar coberta.

| Vínculo | O que o ponto faz | O que não faz |
|---|---|---|
| **Voluntário** e **Diretoria (voluntária)** | soma as horas doadas, emite a declaração de horas e pede o termo de adesão (Lei 9.608/1998) | nunca serve para cobrar horário nem punir |
| **Empregado (CLT)**, **Terceirizado** e **Outro** (estagiário, prestador de serviço) | registra só a presença na sede, por segurança | não soma horas, não tem correção, lançamento nem declaração; não é o ponto oficial dos empregados |

- **Por que assim:**
  - voluntariado sem termo de adesão e com cobrança de horário é o que costuma virar pedido de vínculo
    de emprego na Justiça do Trabalho;
  - o ponto oficial de empregado tem regras próprias (Portaria MTP 671/2021): sistema registrado,
    comprovante a cada marcação, nenhuma marcação apagada ou alterada. O nosso deixa a secretaria
    corrigir, de propósito, porque foi feito para voluntários. Por isso não soma horas de empregado:
    não vira um segundo registro de jornada;
  - as horas dos voluntários e da diretoria dão ao contador o número para reconhecer o trabalho
    voluntário no balanço (ITG 2002).
- **Termo de adesão ao serviço voluntário:**
  - na ficha do voluntário, "Imprimir o termo para assinar" gera o PDF preenchido, em duas páginas.
    Ele traz a entidade (com CNPJ), o voluntário (com CPF e contatos) e 11 cláusulas:
    - objeto, com a função;
    - natureza gratuita e sem vínculo empregatício (art. 1º);
    - dias e horários combinados;
    - registro das horas só para reconhecer, declarar e prestar contas;
    - ressarcimento de despesas autorizadas (art. 3º);
    - compromissos das duas partes e dados pessoais (LGPD);
    - vigência e desligamento, voluntário com menos de 18 anos e foro;
  - a data e as assinaturas ficam em branco na via impressa: voluntário, quem representa a
    instituição e, se for menor de idade, o responsável legal. Há espaço para rubricas e numeração de
    páginas;
  - assinado o termo, a secretaria registra a data no portal. Ficam gravados o modelo do texto
    (`2026-09`) e quem registrou, e o registro entra no histórico. A data pode ser corrigida ou o
    registro removido;
  - **sem o termo registrado, a declaração de horas não sai.** Com ele, a declaração diz que o serviço
    foi prestado "nos termos da Lei nº 9.608/1998 e do termo de adesão assinado em …, sem vínculo
    empregatício". A conferência pública (`/conferir/`) também mostra a data do termo;
  - voluntário sem termo aparece com o selo "Termo pendente" na lista. O número de pendentes aparece
    no quadro, no menu (junto com as saídas esquecidas) e no Início. No ponto, a pessoa vê "Seu termo
    de adesão ao voluntariado ainda não foi registrado. Fale com a secretaria.";
  - **o modelo do termo deve passar pela revisão do jurídico da instituição antes do primeiro uso.** O
    texto está em `mcp_ponto_termo_conteudo()` (`api/lib/ponto.php`). Mudou o texto, muda
    `MCP_PONTO_TERMO_MODELO`.
- **Presença de empregados, terceirizados e outros:**
  - aparece numa lista à parte ("Empregados, terceirizados e outros · só presença");
  - na ficha deles, só a presença do mês;
  - fica fora da planilha de horas e é apagada depois de 90 dias pela rotina `api/comparecimentos.php`
    (a mesma dos comprovantes);
  - a natureza de cada registro fica gravada na entrada (`mcp_ponto.voluntario`). Mudar o vínculo
    depois não transforma presença em horas nem apaga horas antigas.
- **Desligamento:** ao desmarcar "Ativo", a data do desligamento fica registrada (`desligado_em`) e
  some ao reativar.
- **Políticas:** a Política de Privacidade (pt, en e es) ganhou duas atividades:
  - "Ponto da sede: voluntários, diretoria e equipe";
  - "Presença nas aulas presenciais".

  A seção de compartilhamento cita a consulta das aulas à escola. Na guarda:
  - horas de voluntários, até 5 anos depois do fim do voluntariado;
  - presença dos outros vínculos, 90 dias;
  - presença nas aulas, o prazo da matrícula.

  A Política de Cookies lista `mcp_ponto_aparelho` e `mcp_ponto_pessoa`. Tudo é gerado de
  `site/politicas.json` por `scripts/gerar_politicas.py`, com a revisão em 30/09/2026 nas 12 páginas.
- **Código:**
  - `api/lib/ponto.php`: vínculos, termo e retenção;
  - `api/lib/declaracao.php`: PDF do termo, em várias páginas;
  - `api/lib/presenca.php`: a data do termo na conferência pública da declaração;
  - `api/lib/pdf.php`: `novaPagina()`;
  - `api/painel.php`: lista por vínculo, ficha, termo e travas;
  - `api/ponto.php` e `static/ponto.js`: sem horas para quem registra só presença;
  - `api/comparecimentos.php`: a limpeza de 90 dias.
- **Testes:**
  - 10 dos 291 testes de `scripts/testar_checkout.php`: vínculo, termo, PDF de duas páginas e
    declaração citando o termo;
  - 24 dos 100 testes de `scripts/testar_ponto_integracao.php`:
    - termo em PDF, registro com as travas e declaração bloqueada e depois liberada;
    - empregado sem horas, correção, lançamento, declaração nem termo;
    - lista separada e planilha sem ele;
    - limpeza de 90 dias guardando as horas dos voluntários;
    - data de desligamento.
- **Para publicar,** além da lista da seção acima:
  - `api/lib/pdf.php`;
  - as 12 páginas de políticas (`privacidade/`, `cookies/`, `termos/`, `reembolso/` e as versões
    `en/` e `es/`);
  - `ponto/` e `ponto/cartaz/` regeneradas.

  As tabelas do ponto ainda não existem no banco de produção. Por isso, as colunas novas já nascem
  com elas, sem migração.

### Lembretes, comunicados e opinião (30/09/2026)

Guia completo, com os modelos do WhatsApp para a Meta e o passo a passo da secretaria:
[`docs/ponto-comunicacao.md`](docs/ponto-comunicacao.md). **Publicado em 30/09/2026, por volta das 15h53
(horário de Brasília), e nada ligado:** tudo começa desligado no portal, em **Comunicação**.

- **Lembretes automáticos**, cada um com liga e desliga:
  - véspera, às 18h: voluntário e diretoria, nos dias que escolheram ("se vier amanhã, registre a
    chegada e a saída; se não puder vir, tudo bem"); equipe contratada, de terça a sexta (a véspera também
    precisa ser dia útil: nada sai no fim de semana nem em feriado) e só se a própria pessoa pediu (só
    presença, por segurança: lembrete da instituição a empregado pareceria controle de jornada). Nada em
    feriado do Rio nem nos dias sem expediente marcados no portal;
  - saída não registrada, a partir das 9h, para o voluntário com a saída em aberto, com o link para
    informar a hora (a secretaria confere no portal);
  - aula de amanhã, às 18h, para os alunos, pela função `aulas_do_dia` da escola
    (`docs/escola/aulas_do_dia.sql`, ainda não aplicada no banco da escola); WhatsApp só com a
    autorização registrada na escola.
- **Canais:** e-mail (Resend) e WhatsApp em quatro modos: manual (fila no portal, com o botão que abre o
  WhatsApp com o texto pronto), WhatsApp do Palácio Virtual pela Evolution (o que já está conectado; só
  depois do aceite formal do risco no portal), API oficial da Meta (sete modelos; webhook
  `api/whatsapp.php` com a situação das mensagens e o PARAR) e Make (webhook assinado). Janela das 8h às
  20h conferida a cada mensagem, consentimento do WhatsApp com data, autor e como foi dado, preferência
  conferida de novo na hora de mandar, chave única por mensagem, lembretes antes dos comunicados, e-mail
  de reserva quando o WhatsApp falha de vez (e troca para o e-mail perto do prazo, a partir das 19h, com
  o WhatsApp fora do ar), descadastro de um clique (`List-Unsubscribe`) nos e-mails e cota diária de
  e-mails dos avisos (60 por dia; a Resend está no plano grátis, com 100 por dia divididos com a
  matrícula), em que o comunicado usa só o que sobra dos lembretes do dia.
- **Implantação em três fases:** a partir da data do lançamento, o portal prepara quatro comunicados
  (antes, no dia, depois de 2 semanas para colaboradores e para alunos), com prévia do e-mail, do WhatsApp
  e do aviso na tela do ponto, teste para a própria secretaria e agendamento. O "depois" leva o resumo de
  cada voluntário (dias e horas doadas) e a pesquisa de opinião.
- **Páginas pessoais** com link assinado (token depois do `#`, fora do log do servidor; os links das
  mensagens são amarrados à chave da pessoa, e "Invalidar" derruba também os que já saíram): lembretes
  (dias, canais), saída sem registro, opinião (anônima: sem nome para a secretaria, a não ser que a
  pessoa autorize, sem quem clicou e sem data nos comentários; desligada de quem respondeu um mês depois,
  quando a pesquisa fecha) e "não quero mais receber" (alunos).
- **Na tela do ponto:** os avisos dos comunicados (sem link pessoal) e, só no aparelho da recepção, as
  saídas sem registro dos últimos 7 dias para informar ali mesmo e o "saí ontem"; no celular, só o que o
  botão precisa (sem horário de entrada, horas do mês, termo pendente nem link do comprovante), a saída de
  uma entrada de outro dia vira saída informada, o código do dia (desligado de início, liga no portal:
  o celular pede os 4 números da tela do tablet) e a dica de pôr o ponto na tela inicial.
- **Portal:** seção Comunicação (visão geral com o alerta de rotina parada e os dias sem expediente,
  comunicados, fila do WhatsApp, envios com "tentar de novo" e "pediu para parar", resultados com adesão,
  entradas por dia, horas, saídas não registradas na hora, efeito dos lembretes, opinião, planilha dos
  voluntários e relatório para imprimir); em Ponto da sede, as saídas informadas para conferir, a
  importação da planilha de colaboradores e a **lista de emergência** (quem está na sede agora, com os
  alunos em aula, para imprimir numa evacuação); na ficha, "Lembretes e contato" e "Invalidar os links já
  enviados".
- **Sem cron novo:** a rotina do ponto (`api/comparecimentos.php`, a cada 15 minutos) roda os avisos;
  erro nos avisos não derruba os comprovantes.
- **Testes:** 185 testes em `scripts/testar_avisos_integracao.php` (servidores falsos de e-mail,
  WhatsApp oficial, Evolution, Make e escola; nada sai de verdade), 111 no ponto, 303 unitários e 26
  testes pgTAP da `aulas_do_dia`, conferida também pelo PostgREST local.
- **Para ligar em produção** (decisões do Matheus): publicar os arquivos; escolher o WhatsApp (o manual
  funciona já; a Evolution do Palácio exige o aceite do risco de bloqueio do número; a API oficial precisa
  de um número da própria instituição e dos modelos aprovados; a única conexão de WhatsApp no Make,
  "goatlumiar", é de outra empresa e não deve ser usada); aplicar `aulas_do_dia.sql` na escola, se for
  ligar o lembrete das aulas; e, no portal, importar os colaboradores e preparar os comunicados.
- **Para o futuro:** a lista do que ficou combinado para depois está no guia, seção "Para o futuro", a
  começar pelo QR que muda a cada 30 segundos no tablet da recepção (ler o QR e estar na sede confirmam o
  registro pelo celular, sem digitar o código do dia).
- **Publicar** (feito em 30/09/2026, por volta das 15h53, numa sessão aberta pelo Matheus com o
  conector da Hostinger: 25 arquivos copiados do ar antes, 41 enviados, cache limpo; a conferência no ar
  deu as 46 verificações certas e o ponto no celular voltou a poder pedir a localização): são 41 arquivos,
  na ordem de `scripts/publicacao-comunicacao.txt` (os módulos novos antes do `lib.php`, que os carrega;
  `site/politicas.json` fica de fora, é só a fonte das políticas). Com as credenciais TUS exportadas
  (conector da Hostinger, `hosting_files_generate-upload-url`, conta u448697994), sem ecoá-las:
  1. guardar o que está no ar: `scripts/publicar_hostinger.sh --copiar-do-ar /tmp/no-ar $(grep -vE '^(#|$)' scripts/publicacao-comunicacao.txt)`
     (esperado: 25 copiados e 16 em `novos.txt`);
  2. publicar fora da hora das rotinas (a do ponto roda a cada 15 minutos, a dos horários aos 7 de cada
     hora): `scripts/publicar_hostinger.sh $(grep -vE '^(#|$)' scripts/publicacao-comunicacao.txt)`;
  3. limpar o cache (`hosting_cache_clear-website`) e rodar
     `scripts/conferir_publicacao.sh scripts/publicacao-comunicacao.txt` até dar "Tudo certo." (estáticos
     iguais ao repositório, API respondendo, configuração bloqueada, localização liberada só no ponto);
  4. conferir com um Android na sede que o ponto pede a localização e registra, e no portal que a
     Comunicação abre e a Visão geral não mostra "A rotina parou" depois de 15 minutos;
  5. para desfazer: `scripts/desfazer_publicacao.sh a237b7b scripts/publicacao-comunicacao.txt` (cada
     arquivo volta à versão de `a237b7b`, na ordem inversa, e os 16 novos saem, menos o `ponto/.htaccess`:
     nenhum `.htaccess` é apagado por script). A cópia do passo 1 serve para conferir. As tabelas e colunas
     novas podem ficar: o código antigo não as usa e nenhum INSERT dele deixa de listar as colunas.

  A migração do banco é automática na primeira visita e só acrescenta (5 tabelas, 13 colunas e 3 índices
  comuns em tabelas que já existem). Ensaiada em 30/09/2026: o esquema de `a237b7b` com dados de
  exemplo, atualizado pela versão nova, ficou idêntico ao criado do zero (306 colunas, índices e
  tabelas), com os dados preservados e a rotina rodando sem erro e sem enviar nada. Nenhuma função mudou de arquivo e as 270 que já existiam
  mantêm assinaturas compatíveis, então o código no ar convive com as bibliotecas novas durante o envio.
  Na primeira rotina, os registros de freio com mais de 2 dias e os de robôs com mais de 30 são apagados,
  como diz a Política de Privacidade publicada junto.

## Questionário de dias e horários (29/09/2026)

Depois de pagar a inscrição, o aluno diz quais dias e horários são melhores para ele. A secretaria
usa as respostas para montar as turmas e, se a data da turma do aluno não servir, combinar outra.

- **Onde o aluno responde:** em `/matricula-cursos-presenciais/horarios/?t=<token>`, com o mesmo link
  pessoal da tela Parabéns, sem login.
- **As perguntas:**
  1. "Quando você consegue vir?": uma grade de segunda a sábado × manhã (8h às 12h), tarde (13h às
     17h) e noite (18h às 22h). O aluno toca em cada horário possível, ou usa os atalhos "Noites de
     semana", "Sábado", "Manhãs de semana" e "Tardes de semana"; tocar no nome do dia ou do período
     marca a linha ou a coluna. Um contador mostra quantos horários estão marcados;
  2. a partir de quando pode começar: na próxima turma, em cerca de 1 mês, ou em 2 meses ou mais;
  3. se a escola já o colocou numa turma: se a data funciona;
  4. recado opcional para a secretaria, de até 500 caracteres (recolhido, abre com um toque).

  Cada horário é gravado como dia-período ("seg-noite"), então o mapa é exato: quem marca sábado de
  manhã e noites de semana não aparece em "sábado à noite".
- **Avisos a quem ainda não respondeu:**
  - no topo da tela Parabéns (a página da inscrição do aluno), o alerta "Falta 1 passo: seus horários";
    depois de responder, vira o resumo com "Mudar meus horários";
  - no e-mail de inscrição paga, um quadro "Falta 1 passo" logo depois da ação principal (versões A e B);
  - até dois lembretes por e-mail, 24 h e 72 h depois do pagamento, que param assim que ele responde.
    Quem manda é `api/lembretes.php`, rodado pelo cron da Hostinger de hora em hora (só linha de
    comando; por HTTP responde 404 e o `.htaccess` nega). Só entram pagamentos a partir de
    `HORARIOS_LEMBRETES_DESDE` (padrão 30/09/2026) e dos últimos 14 dias.

  O aluno pode mudar as respostas quando quiser. Vale a última, e o portal mostra quantas vezes ele mudou.
- **Onde a secretaria vê:** no portal da secretaria, seção "Horários dos alunos"
  (`api/painel.php?v=horarios`). O login é o link por e-mail do portal. Para cada curso, a seção
  mostra:
  - o mapa de quantos alunos podem em cada dia e período, com os horários mais pedidos;
  - quantos podem começar em cada prazo e para quantos a data da turma serve;
  - quantos pagaram e ainda não responderam, com o link para essa lista em Inscrições (de onde sai o
    lembrete à mão);
  - a lista das respostas, com os contatos;
  - o botão "Baixar planilha": um CSV que abre no Excel e no Google Planilhas, com uma coluna por
    horário ("Seg manhã" … "Sáb noite", marcada com x) para filtrar quem pode em cada um.
- **Também no painel da escola:** a secretaria vê o mesmo mapa na aba **Horários** do painel da
  plataforma da escola (`escola.cursoscruzvermelha.org`). O mapa sai por curso e fica ao lado das
  próximas turmas abertas daquele curso. A aba também mostra quem falta responder e a lista com os
  contatos, e baixa a planilha. A escola lê daqui, servidor a servidor, por `api/escola-horarios.php`:
  - chave `SITE_HORARIOS_TOKEN`, com o mesmo nome e o mesmo valor em `api/config-escola.php` e na escola (Render);
  - sem a chave o endereço responde 404, e chave errada responde 401 (até 20 erros por hora por IP);
  - nunca vai CPF.

  Nada é gravado no banco da escola. Detalhes em [docs/escola/README.md](docs/escola/README.md#fase-2-o-questionário-dentro-da-escola).
- **Aviso por e-mail:** na primeira resposta de cada aluno, a secretaria (`EMAIL_SECRETARIA`) recebe
  o resumo e um botão para o mapa do curso. Mudanças de resposta não geram outro e-mail.
- **Regras:**
  - o questionário só abre com a inscrição paga; antes disso, a API responde 403;
  - cada IP pode enviar até 60 vezes por hora;
  - valores fora da lista são descartados;
  - na planilha, texto que começa com `=`, `+`, `-` ou `@` não vira fórmula;
  - a tela e a API nunca devolvem CPF, e-mail ou telefone.
- **Código:**
  - `api/lib/horarios.php`: regras, mapa, planilha e aviso;
  - `api/horarios.php`: a API;
  - `api/lembretes.php`: os lembretes (cron);
  - `api/painel.php`: a seção "Horários dos alunos" do portal;
  - `static/checkout.js` e `checkout.css`: a tela;
  - `scripts/gerar_checkout.py`: gera a página;
  - tabela `mcp_preferencias`, com uma linha por inscrição, criada sozinha na primeira chamada.
- **Testes:**
  - 49 dos 291 testes de `scripts/testar_checkout.php`;
  - 88 testes de ponta a ponta em `scripts/testar_horarios_integracao.php`: API, tela Parabéns,
    portal da secretaria, planilhas, lembretes e a leitura pelo painel da escola, pelo servidor embutido do PHP contra um MariaDB local. Para rodar:
    `MCP_CONFIG_ARQUIVO=/caminho/config-teste.php php scripts/testar_horarios_integracao.php`. O
    teste recusa banco que não seja local e apaga no fim o que criou.
- **Para publicar:**
  - `api/horarios.php`, `api/lembretes.php`, `api/lib/horarios.php`, `api/lib.php` e `api/.htaccess`;
  - `api/lib/db.php`, `email.php`, `painel.php` e `publico.php`;
  - `api/painel.php` e `api/status.php`;
  - `static/checkout.css` e `checkout.js`;
  - as páginas `checkout/`, `pendente/`, `parabens/` e `horarios/`;
  - depois, criar o cron de hora em hora com `php .../public_html/matricula-cursos-presenciais/api/lembretes.php`.
- **No ar desde 29/09/2026:**
  - os 17 arquivos acima foram publicados, o cache foi limpo e a página, a API e os bloqueios (403 em
    `lembretes.php`, `lib/` e `config.php`) foram conferidos ao vivo;
  - o cron roda de hora em hora, no minuto 7:
    `/opt/alt/php83/usr/bin/php /home/u448697994/domains/cruzvermelhariodejaneiro.org/public_html/matricula-cursos-presenciais/api/lembretes.php`
    (PHP 8.3, o mesmo do site). A saída da última rodada fica no hPanel, em Cron Jobs. A primeira saída
    foi `lembretes: 0 enviados, 0 falhas, 0 inscrições vistas`;
  - os e-mails novos foram enviados em teste para o Matheus e entregues, com os links apontando para a
    inscrição de teste dele.
- **Pendente:** `EMAIL_SECRETARIA` está vazio no servidor (no teste de 28/09 não saiu o aviso de
  inscrição paga). Sem ele, a secretaria não recebe os avisos de inscrição paga nem os de horários; o
  portal mostra tudo mesmo assim. Configurar no `api/config.php` do servidor.
- **Dentro da escola (fase 2):** a mesma pergunta na área do aluno da plataforma da escola, quando
  houver acesso ao código dela. O plano e o contrato dos dados estão em
  [`docs/escola/README.md`](docs/escola/README.md#fase-2-o-questionário-dentro-da-escola).

## Home: seção de contato e botão do WhatsApp (19/09/2026)

- A seção `#contato` da home foi refeita para converter: e-mail institucional em destaque
  (`contato@cruzvermelhariodejaneiro.org`, cartão clicável com `mailto:` e botão "Copiar
  e-mail"), sede com link para o Google Maps, Instagram e Facebook como cartões, CNPJ em nota. Ao
  lado, um cartão no estilo Instagram (`@cruzvermelhabrasileirarj`, botão Seguir) com um mosaico
  de 9 fotos reais dos posts da filial que já estavam em `assets/` (várias trazem a marca
  @cruzvermelhabrasileirarj); a cada 3,5 s uma foto é trocada por outra do conjunto (JSON no
  `#insta-fotos`), respeitando `prefers-reduced-motion`. O Instagram bloqueia leitura anônima do
  perfil (HTTP 429), então as fotos são as do servidor, não um feed ao vivo: para trocar, edite a
  lista no `index.html` e suba a foto em `public_html/assets/`.
- **Bloco "Já escolheu seu curso?"**: o `<select>` nativo (a lista do sistema destoava da página)
  virou pílulas: um `radio` escondido por curso, agrupado como em `cursos.json`, com carga horária
  em cada pílula; o envio vai direto para `checkout/?curso=<slug>`, e sem escolha a página avisa
  em vez de abrir o balão nativo. As pílulas continuam entre os marcadores `matricula:cursos`,
  reescritos por `scripts/gerar_matricula_presencial.py` a cada sincronização do catálogo.
- **Bloco "Educação salva vidas"**: saiu o post com texto (cortado e sem chamada) e entrou a foto
  real da aula de Primeiros Socorros, os três passos com ícone e, no fim, o botão "Fazer o curso
  de Primeiros Socorros" (checkout com o curso escolhido) e o link para todos os cursos. A seção
  passou a converter em vez de só informar.
- O botão flutuante do WhatsApp (`.wpp-float`) saiu da home e de `equipe.html`. Em 19/09, à noite,
  entrou no lugar o chat de contato por e-mail (seção abaixo) e os botões "Chamar no WhatsApp" dos
  cartões de curso viraram "Tirar dúvidas" (abrem o chat com o curso já escolhido).
- `site/assets/` não é versionada (fica só no servidor); há uma cópia local ignorada pelo Git
  só para renderizar a home em testes.

## Chat de contato por e-mail e fim do WhatsApp nas páginas (19/09/2026)

Motivo: tudo o que o site oferecia como contato caía no WhatsApp da secretaria (botões "Chamar no
WhatsApp" da home, o fallback dos botões de matrícula, a copy "a secretaria chama no WhatsApp" nas
telas e nos e-mails), misturando lead do site com o atendimento da escola e gerando confusão. Agora o
canal é **e-mail**, com um chat no site para a pessoa deixar a mensagem.

- **Chat (`site/chat/chat.js` + `chat.css`)**: botão flutuante "Fale com a gente" em todas as páginas
  (home, matrícula, checkout, pendente, parabéns, equipe, doação, agasalho, 404), no lugar do antigo
  `.wpp-float`. Conversa guiada: assunto (chips), curso (quando o assunto é matrícula/curso/pagamento;
  o curso aberto na página vem primeiro), nome, e-mail, telefone opcional, mensagem, revisão e envio.
  Sem dependências; estado na `sessionStorage` (sobrevive à navegação); `Esc` fecha; leitor de tela
  recebe só a fala nova. Abre também por `#chat` na URL (usado no rodapé dos e-mails) e por qualquer
  elemento com `data-abrir-chat` (opcionalmente `data-assunto` e `data-curso`): é o que os botões
  "Tirar dúvidas" dos cartões de curso da home fazem. As tags das páginas levam hash do conteúdo
  (`/chat/chat.js?v=…`), geradas por `scripts/chat_widget.py`; a lista de cursos dentro do `chat.js`
  é reescrita por `gerar_matricula_presencial.py` a partir de `cursos.json`.
- **Identidade do chat (19/09 à noite)**: o Matheus apontou que a caixa estava "sem nossa identidade
  visual e com nosso nome incompleto". Agora o cabeçalho é branco com faixa vermelha no topo, logo da
  filial (`/bio/img/avatar-256.webp`), nome completo e status "Atendimento por e-mail · resposta em
  até 2 dias úteis"; os balões da equipe têm filete vermelho e o botão de fechar fica vermelho no
  hover. Fonte: `NOME`/`LOGO` no início de `chat.js` e `.cv-chat-topo`/`.cv-chat-avatar` em
  `chat.css`. A saudação e os e-mails também usam o nome completo.
- **API (`api/contato.php`)**: mesmas guardas do checkout (POST JSON da própria origem, corpo até
  64 KB, campo armadilha, limites por IP 8/h e por e-mail 4/h), validação com o nome do campo para o
  chat voltar à pergunta certa, tabela `mcp_contatos` (criada sozinha, como as outras), protocolo
  `CV-aammdd-NNNN`, aviso à equipe em `EMAIL_CONTATO` com **responder-para = quem escreveu** e
  confirmação à pessoa (com o botão de matrícula do curso, quando o assunto é curso). O banco é a fonte
  da verdade: se o e-mail falhar, a mensagem fica em `mcp_contatos` com `email_equipe = 'falhou'`.
- **Destino**: `EMAIL_CONTATO` no `config.php` (padrão `contato@cruzvermelhariodejaneiro.org`).
  O aviso de teste de 19/09 chegou na caixa do Matheus, então `contato@` recebe (o MX aponta para
  a Hostinger; a API de e-mail da Hostinger não lista o serviço nesta conta, provavelmente por
  estar em outra conta ou escopo). Se o e-mail do domínio for para o Google Workspace, os passos
  são: TXT de verificação do Admin, MX `smtp.google.com` prioridade 1 no lugar dos MX da Hostinger,
  SPF com `include:_spf.google.com` e DKIM do Gmail.
- **Painel de respostas (`api/painel.php`, 19/09 à noite)**: a equipe responde cada contato por
  e-mail no padrão visual do site e a resposta fica registrada (`status`, `resposta`,
  `respondido_por`, `respondido_em` em `mcp_contatos`). Entrada sem senha para configurar: o aviso
  de cada mensagem traz o botão **"Responder no painel"**, um link assinado (HMAC com um segredo
  gerado no servidor e guardado em `mcp_chaves`) que abre só aquele contato por 90 dias; a lista
  completa exige um link de entrada enviado ao e-mail da equipe (`EMAIL_CONTATO` ou
  `PAINEL_EMAILS`), válido por 20 minutos, que abre uma sessão de 12 h em cookie assinado
  (HttpOnly, Secure, SameSite=Lax). Quem lê a caixa da equipe é quem pode responder. Formulários com
  token anti-CSRF por ação e contato; limite de 5 pedidos de link por hora por IP; página `noindex`
  e `no-store`. A resposta sai de `EMAIL_REMETENTE_CONTATO` (ou do remetente geral) com
  responder-para `EMAIL_CONTATO`, assunto "Resposta da Cruz Vermelha Brasileira Rio de Janeiro · protocolo", assinatura de
  quem respondeu e a mensagem original citada; arquivar sem responder e reabrir também existem.
  Responder direto pelo cliente de e-mail continua funcionando (responder-para = a pessoa), só não
  registra no painel.
- **Cliente sempre avisado de que a resposta vem por e-mail**: a tela final do chat diz para qual
  endereço a resposta vai, em quanto tempo, que o protocolo vem no assunto, para olhar o spam e
  salvar `contato@` nos contatos (com botão para copiar o endereço); a confirmação por e-mail repete
  isso em três passos ("Como funciona a resposta"), com o remetente que vai aparecer; a resposta do
  painel termina com "responda este e-mail para continuar".
- **Páginas e telas sem WhatsApp**: botões de matrícula levam direto ao checkout (`?curso=`) mesmo sem
  JavaScript; "Como funciona", FAQ (com a pergunta nova "Como tiro dúvidas antes de me matricular?"),
  checkout (campo "Telefone (celular)", noscript), tela Parabéns B ("a secretaria escreve para você",
  com link para o chat) e mensagens da API falam em e-mail. O telefone continua obrigatório no
  checkout porque a Unicopag exige `phone_number`. O número da secretaria permanece só no rodapé e no
  JSON-LD da home (dado institucional, não é botão).
- **E-mails no padrão da instituição (`api/lib/email.php`)**: moldura única com faixa vermelha, logo
  (`assets/otim/logo-cvb-rj-480.png`, PNG de 8 KB gerado do `logo-cvb-rj.png` porque WebP não abre no
  Outlook), Inter com reserva de sistema, chapéu, título, rodapé com "Dúvidas? Responda este e-mail",
  CNPJ, endereço e a linha do motivo; componentes reutilizáveis (botão em tabela, caixa de valores com
  total em destaque, passos numerados, bloco do PIX, citação). Cada mensagem tem uma função pura
  `mcp_montar_email_*()` (assunto, html, texto) coberta por `scripts/testar_checkout.php` e renderizada
  por `scripts/previsualizar_emails.php [pasta]` para conferência visual (nove modelos, incluindo a
  resposta do painel e o link de entrada).
- **E-mail de recuperação do PIX** (sai quando o código é gerado): assunto "Falta só o PIX para
  garantir sua vaga em <curso>", prévia com valor e validade, título "Falta só o PIX, <nome>.", caixa
  Curso / Inscrição / custos opcionais / Total, botão único "Concluir pagamento" (tela pendente, com
  `utm_source=email&utm_medium=transacional&utm_campaign=pix-aberto` para medir a recuperação no GA4),
  código copia e cola com o passo a passo, validade em horário de Brasília (criado + 24 h), "O que
  acontece depois" em três passos e a regra de estorno. Antes: título "Sua inscrição está aberta",
  valor, código e "Voltar para o pagamento", com o WhatsApp da secretaria no rodapé.
- **Demais e-mails**: inscrição paga B ("Vaga garantida, <nome>!", comprovante com método e data,
  próximos passos por e-mail), A (acesso da escola na mesma moldura), aviso à secretaria (coluna
  Telefone, responder-para = aluno) e os dois do chat.
- **Rastreamento**: GA4 `contato_aberto` (uma vez por sessão) e `contato_enviado` (assunto, curso,
  página); Meta `Contact` no envio. Detalhes em `docs/rastreamento.md`.
- **Pendências**: decidir se o e-mail do domínio vai para o Google Workspace (acima); no painel,
  filtro por assunto e exportação CSV; um lembrete automático para contatos que ficarem 2 dias
  úteis sem resposta.

## O chat responde sozinho as dúvidas de curso (21/09/2026)

Antes, toda dúvida virava chamado e esperava até 2 dias úteis. Agora o chat responde na hora o que
já está escrito e revisado, e só abre chamado para o que sobra.

### Como funciona

Depois de escolher assunto e curso — **antes** de pedir nome e e-mail — o chat mostra a ficha do
curso (carga horária, escolaridade, valor, inscrição, certificado) e oferece as dúvidas **como
botões**. Tocou, respondeu, e a resposta fica na conversa.

**Botão em vez de adivinhação, de propósito.** Um casador de texto erra, e resposta errada sobre
preço ou certificado numa instituição como a Cruz Vermelha custa caro. Com botão, a resposta é
sempre a que a escola ou a FAQ escreveu — não há como o chat interpretar mal.

De onde vem cada resposta:

| Situação | Fonte |
|---|---|
| Curso escolhido, com FAQ no catálogo | `cursos.json`, campo `faq` de cada curso (5 perguntas) |
| Curso sem FAQ própria, ou assunto sem curso | `site/faq-home.json`, entradas marcadas com `"chat"` |

A marca fica no próprio `faq-home.json`: `"chat": ["voluntariado"]` diz em que assuntos do chat
aquela resposta se oferece, e `"chatRotulo"` dá o texto curto do botão (a pergunta da página
carrega o nome completo da filial, que num botão de celular vira três linhas). Quem edita a FAQ vê
as duas coisas na mesma linha. `scripts/chat_widget.py` gera os dois blocos do `chat.js`
(`chat:cursos` e `chat:respostas`); no máximo 5 botões por tela.

### Quem abre chamado mesmo assim

- **O e-mail de confirmação** passou a levar a ficha do curso, montada no servidor a partir do
  catálogo. Antes só dizia "retornamos em até 2 dias úteis".
- **O aviso à equipe** lista o que a pessoa já leu no chat, sob "Já respondido no chat, antes de
  escrever". A equipe não repete, e saber o que a pessoa leu e mesmo assim não resolveu costuma ser
  a parte mais útil da mensagem.

**Nada vindo do navegador entra num e-mail sem conferência.** O chat manda só o texto das perguntas
lidas; `mcp_perguntas_conhecidas()` (em `api/lib/config.php`) confronta cada uma com as 44 que
existem de verdade — a FAQ dos cursos mais a FAQ da home marcada com `chat` — e descarta o resto.
Testado com texto inventado e com `<script>`: os dois são descartados. A lista não vai para a
tabela (é do atendimento, não do contato): `contato.php` a reanexa depois de reler o registro.

Para isso o `faq-home.json` precisa estar publicado na raiz do site — o PHP o lê de
`public_html/faq-home.json`. É o mesmo conteúdo que já está na home.

### O que medir

Eventos no GA4: `chat_duvida_respondida` (com o curso e a pergunta), `chat_duvida_seguiu` (com
quantas leu antes de abrir chamado) e `chat_duvida_matricula`. A conta que interessa é quantas
conversas terminam sem virar e-mail.

### Falta

O curso **Primeiros Socorros Lei Lucas** é o único sem FAQ própria no catálogo da escola, então cai
na FAQ geral. Vale pedir à escola as cinco perguntas dele — é o curso que as escolas procuram.

## Inglês e espanhol (21/09/2026)

Quatro páginas novas, estáticas, geradas pelo mesmo caminho de sempre:

| Endereço | Página |
|---|---|
| `/en/` · `/es/` | institucional: quem é a filial, o que faz, a sede, os sete princípios, contato |
| `/en/donate/` · `/es/donar/` | doação: como funciona, o idioma da tela de pagamento, transparência, parceria |

O conteúdo fica em **`site/idiomas.json`** e `scripts/gerar_idiomas.py` monta o HTML com o mesmo
esqueleto da home (cabeçalho, rodapé, CSS minificado) e os rótulos traduzidos. Editar texto é
editar o JSON.

### Por que estático, e não tradução em tempo real

Só endereço próprio por idioma faz o Google mostrar o site em busca feita em outro idioma. Widget
de tradução e troca por JavaScript **não são indexados** — o robô vê português e a versão traduzida
não existe para a busca. Servir idiomas diferentes na mesma URL, pelo `Accept-Language`, é pior
ainda: uma URL, uma versão indexada.

E é mais leve: cada visitante baixa uma página só, como hoje. São três arquivos no servidor em vez
de um, e disco não é problema.

### Como o Google entende as versões

- `hreflang` recíproco em **toda** página das três versões, com `x-default` no português — declarado
  na página e também no `sitemap-paginas.xml` (`xhtml:link`), que é o recomendado.
- `canonical` próprio por versão; `lang` no `<html>`; `og:locale`; `inLanguage` no `WebPage`.
- Seletor de idioma **em toda página, nas três versões**: `PT · EN · ES` no cabeçalho, ao lado do
  botão da Plataforma, com a sigla atual destacada. Na primeira rodada ele só existia nas páginas
  traduzidas — quem estava em português não tinha como chegar lá, só o Google enxergava.
- **Sigla em texto, não bandeira.** Bandeira é país, não idioma: espanhol não é a Espanha (são mais
  de vinte países), inglês não é o Reino Unido, e a filial pertence a uma instituição cujo princípio
  é a neutralidade. Para a busca também é melhor: cada sigla é um link rastreável com `hreflang` e
  `lang`, e imagem de bandeira não carrega sinal nenhum. A acessibilidade vem do `aria-label` com o
  nome do idioma por extenso.
- No `/doe/` o seletor aponta para `/en/donate/` e `/es/donar/`, não para a institucional
  (`seletor_da_doacao()` em `gerar_doe.py`): mandar quem está doando para outra página é perder a
  doação. Nas páginas sem tradução própria, ele leva à institucional daquele idioma, que é a porta
  de entrada certa.
- **Nunca** redirecionamento por `Accept-Language`: o Googlebot vem "em inglês", dos EUA, e ficaria
  preso numa versão.

### Decisões que valem registrar

- **O português fica na raiz**, não em `/pt-br/`. Mover significaria redirecionar todas as URLs já
  indexadas — a home, `/doe/`, `/matricula-cursos-presenciais/` — logo depois de zerarmos os
  redirecionamentos internos, e mexer também na Redação, que escreve `/noticias/` e o `sitemap.xml`.
  Idioma padrão na raiz com `x-default` é padrão suportado pelo Google.
- **Sem o widget de chat nessas páginas.** A interface dele é toda em português; abrir um chat em
  português para quem lê em inglês é pior do que não ter chat. O e-mail fica em evidência.
- **A página de doação em inglês e espanhol explica e encaminha para `/doe/`**, que é a engrenagem
  que recebe o dinheiro. Traduzir aquele fluxo é mexer em página que recebe pagamento, com texto
  espalhado por `doe.js`, e não entrou aqui. As páginas dizem, com todas as letras, que a tela de
  pagamento é em português, e traduzem os botões que a pessoa vai encontrar (`Doar`, `Cartão`,
  `PIX`, `Doar anonimamente`, `Copiar código PIX`).
- **O cenário de fora do Brasil ganhou seção própria nas duas páginas de doação**, logo depois do
  botão, que é onde o doador estrangeiro bateria na parede: "Donating from outside Brazil: card
  only" / "Donar desde fuera de Brasil: solo con tarjeta". Ela separa as duas travas, que são de
  naturezas diferentes: **o PIX é impossível de fora por construção** (é sistema do Banco Central,
  move dinheiro entre contas de bancos brasileiros, não existe PIX saindo de banco estrangeiro),
  enquanto **o cartão atravessa fronteira sem problema** e só esbarra no CPF que o nosso formulário
  pede. Dizer as duas coisas juntas ("não dá de fora") esconderia que a segunda é removível.
- **Quem doa pelo site precisa de CPF e telefone brasileiro — nos dois meios de pagamento.** A
  primeira versão destas páginas dizia "tax ID or passport" e "card works from anywhere". Está
  errado: `site/doe/static/doe.js` roda `cpfValido()` (11 dígitos com dígito verificador) e exige
  telefone de 10 a 11 dígitos **antes** de abrir cartão *ou* PIX. Quem está fora do Brasil sem CPF
  não passa do formulário. As duas páginas agora abrem por "Who can donate online"/"Quién puede
  donar en línea", e a seção final ("Donating from outside Brazil") manda escrever para a filial,
  que combina a transferência por fora. Mexer na validação de `doe.js` é mexer em página que recebe
  pagamento; a saída honesta custou uma seção de texto, não um refactor.
- **O nome da instituição** ficou "Brazilian Red Cross — Rio de Janeiro Branch" e "Cruz Roja
  Brasileña — Filial Río de Janeiro". Trocar é editar `instituicao` no `idiomas.json` e regerar.

### Conferência de 21/09

Depois de publicar, passei sitemap, ortografia e copy das quatro páginas:

- **Sitemap: limpo.** Os 5 sitemaps em 200, `robots.txt` declarando `sitemap-index.xml` e
  `sitemap.xml`, o índice listando os 4, `sitemap-paginas.xml` com 13 URLs e 24 `xhtml:link`.
  Cruzamento sitemap × página ao vivo: 0 problema em 13 URLs — `canonical` igual ao `loc` e
  `hreflang` idêntico nos dois lugares. Rastreio: 0 redirecionamento interno, 0 4xx/5xx, 0 órfã,
  0 fora do sitemap, 0 âncora fraca.
- **Ortografia: nada.** `pyspellchecker` acusou 10 palavras em inglês (CPF, CVB-RJ, centre,
  organisation, recognised, first-aid…) e 114 em espanhol — todas legítimas: grafia britânica,
  compostos hifenizados e dicionário pobre. Correções de língua que saíram: "thematic
  coordinations"→"coordination teams", "volunteers the branch trains itself"→"volunteers trained by
  the branch itself", "first-aid capability"→"first-aid training", "inscrita con el CNPJ"→
  "registrada", "Campaña del Abrigo"→"Campaña de Ropa de Abrigo", "Primeros Auxilios Básico,"→
  "Básicos,", vírgula em "Praça da Cruz Vermelha, 10".
- **Copy: um erro de fato**, o do CPF acima — o único achado sério da conferência.
- **Descrições fora do limite.** As quatro estavam entre 178 e 224 caracteres, enquanto as em
  português respeitam ~160 e o Google corta por volta disso. Reescritas para 148–158.
- **`og:locale`** saiu do código para o `idiomas.json` e o espanhol virou `es_LA`, não `es_ES`: a
  copy é de espanhol latino-americano e a Espanha não é o público.

### As quatro camadas (21/09/2026)

A primeira versão em inglês e espanhol era duas páginas de 588 palavras contra 4.324 da home em
português, 17 links internos contra 119, nenhum `h3`. O Matheus apontou: para **posicionamento**
isso é pior do que para busca — Sociedade Nacional irmã ou parceiro internacional que cai no `/en/`
vê uma brochura enquanto o site em português é uma operação inteira. Ele estava certo, e a ideia de
construir em camadas é dele.

Só que espelhar 1:1 a home em português seria errado por outro motivo: ela é 60% catálogo de cursos
presenciais, em português, no Rio, com checkout que pede CPF. Ranquear em inglês para "first aid
course Rio de Janeiro" e entregar um beco sem saída é pior do que não ranquear. **A regra que ficou
é espelhar por intenção, não por página**: para cada página em português, "o leitor de fora
consegue agir com isso?".

| | antes | depois |
|---|---|---|
| páginas por idioma | 2 | 5 |
| palavras na home | 588 | 1.044 |
| texto/HTML na home | 11,5% | 16,7% |
| `h3` na home | 0 | 9 |
| links internos na home | 17 | 24 |

1. **FAQ** (`/en/faq/`, `/es/preguntas-frecuentes/`) — 20 perguntas, ~1.900 palavras, `FAQPage`
   JSON-LD. Das 26 em português ficaram 15: saíram as de cauda longa doméstica (curso gratuito,
   babá, Nova Iguaçu, MEC) e entraram 5 que só o leitor de fora faz — se somos a Cruz Vermelha
   nacional, se o curso é em inglês, se dá para doar de fora, se estrangeiro pode ser voluntário,
   e com quem falam Sociedade Nacional irmã e empresa. É a camada de melhor retorno: pergunta e
   resposta é o que os motores de resposta citam, e eles respondem no idioma de quem pergunta.
2. **Cursos** (`/en/courses/`, `/es/cursos/`) — a ficha honesta, não a página de vendas traduzida.
   A restrição vem antes da tabela de preços. Carga horária, escolaridade e valor saem de
   `cursos.json`, a mesma fonte da página em português.
3. **Equipe** (`/en/our-team/`, `/es/nuestro-equipo/`) — diretoria, as onze coordenações e o
   Palácio. Rende pouco em busca e muito em posicionamento. `ler_equipe()` extrai as pessoas do
   próprio `equipe.html` na hora de gerar e aborta se a página mudar de forma; só os rótulos são
   traduzidos, porque duas listas de nomes divergiriam um dia.
4. **Home adensada** — o Movimento (as três partes, e por que o emblema não é marca), as cinco
   frentes da filial e quatro "portas de entrada" que linkam para as camadas novas.

**hreflang, três casos diferentes.** A FAQ e os cursos formam par `en`+`es`, porque em português a
FAQ é seção da home (e âncora não serve de hreflang) e a página de cursos é a de matrícula, com
checkout — outro tipo de página. A equipe forma cluster de três, e para isso `equipe.html` ganhou
as quatro linhas de hreflang e o seletor dele passou a apontar para as traduções em vez da home:
reciprocidade de verdade, não declaração unilateral do sitemap. Sitemap e páginas declaram
exatamente a mesma coisa nas 19 URLs — há verificação que cruza os dois.

**Defeito antigo corrigido de passagem**: os três blocos da home recebiam os ids
`sobre`/`principios`/`contato`, então `principios` ficava duplicado (o outro é a seção dos sete
princípios) e o `#contato` do menu caía na seção da sede. Agora são `sobre`/`atuacao`/`sede`, e a
seção de contato tem o id que o menu aponta.

### Rastreamento das páginas novas (21/09/2026)

Revisão pedida para não desperdiçar verba de tráfego pago. A **copy passou limpa**: cruzei 16 fatos
(inscrição de R$ 99, os cinco preços de curso, CNPJ, as duas leis de utilidade pública, 192/193,
CEP, e-mail, WhatsApp do voluntariado, prazo de 2 dias úteis, horário, Lei Lucas) nas 14 páginas
dos três idiomas — nenhum aparece com valor diferente, nenhum valor velho, nenhum nome curto
proibido, nenhum e-mail no domínio morto da nacional.

O rastreamento não passou. **As 10 páginas em inglês e espanhol subiram com GA4 mas sem o Meta
Pixel**: `gerar_idiomas.py` injetava `partes['ga4']` e nunca `partes['pixel']`. Corrigido — o
`<noscript>` de fallback vem junto, e o bloco é byte a byte igual ao da home. Enquanto esteve
assim, anúncio do Meta que caísse numa dessas páginas não construía público, não atribuía
conversão e não alimentava a otimização de entrega.

**Links internos continuam sem UTM**, que é o certo: UTM em link interno reinicia a sessão no GA4 e
apaga a origem real da doação. Confirmado nas páginas novas.

### O que ficou em aberto no rastreamento

- **`/noticias/` e as matérias não têm Pixel** — só GA4. É da Redação, e lá não existe suporte a
  Pixel nenhum (`esqueleto.ts` só tem o link do perfil no rodapé). É a área que mais recebe
  tráfego de conteúdo.
- **Não existe CAPI (Conversions API) em lugar nenhum.** O `Purchase` do `doe.js` já sai com
  `eventID: token + '-doacao'`, ou seja, a deduplicação foi preparada e a metade servidor nunca
  foi escrita. Sem ela, pixel de navegador perde as conversões de quem usa bloqueador, iOS ou
  Safari, e o Meta otimiza com dado incompleto.
- **PIX é subcontado.** O `Purchase` dispara quando o navegador chega na tela de obrigado. O PIX
  confirma por webhook, de forma assíncrona: quem paga no app do banco e fecha a aba nunca dispara
  o evento. O `webhook.php` já reconsulta a API e decide o status — é exatamente o ponto onde o
  CAPI resolveria as duas coisas de uma vez.

### O que ainda não está traduzido, de propósito

O checkout, o chat, os e-mails transacionais e as notícias (da Redação). As páginas em inglês e
espanhol dizem isso em vez de fingir. Ampliar é acrescentar texto ao `idiomas.json` — a base
técnica já está de pé.

**Decisão em aberto, e vale tomar antes de ter cinquenta páginas**: o motor de conteúdo em camadas
é `/noticias/`, que **não mora neste repositório** — é da Redação (Next.js na Vercel). Enquanto
não for decidido, o português cresce toda semana e as outras línguas ficam congeladas no número de
páginas que tiverem. As opções são `/en/news/` servido daqui ou notícia multilíngue dentro da
própria Redação (minha recomendação), e **não traduzir tudo**: escolher o que tem alcance
internacional de verdade — o apelo da IFRC para o Chocó, por exemplo — em vez de traduzir
interdição de ciclovia. Isso muda estrutura de URL, então é decisão de agora.

### Revisão

Escrevi os textos, não são tradução automática, mas **pedem revisão humana** antes de virarem a voz
oficial da filial em outro idioma — em especial os nomes próprios e o enquadramento institucional.

## Inglês: notícias e as páginas que faltavam (26/09/2026)

Pedido do Daniel (Comunicação): levar o site institucional e as notícias para o inglês. A escola
ficou de fora, porque os cursos são dados em português.

| Endereço | O que é | Fonte do texto |
|---|---|---|
| `/en/news/` | índice das notícias em inglês, em dois grupos (filial e cidade; guias de curso) | `traducoes/en/paginas.json` |
| `/en/news/<slug>/` | as 19 matérias da Redação no ar em 26/09, traduzidas | `traducoes/en/noticias/<slug>.md` |
| `/en/winter-clothing-drive/` | Campanha do Agasalho | `traducoes/en/paginas.json` |
| `/en/privacy/` · `/en/terms/` | tradução de cortesia; vale o português, e a página diz isso | `traducoes/en/paginas/*.md` |
| `/en/404.html` | página de erro de tudo que está sob `/en/` (`site/en/.htaccess`) | `traducoes/en/paginas.json` |

`scripts/gerar_ingles.py` monta essas páginas com a moldura de `gerar_idiomas.py` (mesmo cabeçalho,
rodapé e CSS). Rodar **depois** de `gerar_idiomas.py`. Ele recusa campo acima do limite da Redação
(título 120, linha fina 200, endereço 80), marcador ⟦ ⟧ esquecido e link interno para página em
inglês que não existe, e grava `traducoes/en/mapa-hreflang.json` (original → tradução), que
`gerar_sitemaps.py` lê.

- **Cada .md é uma matéria pronta para a Redação**: o cabeçalho traz os campos (título, linha fina,
  capa, legenda, crédito) e o nome do arquivo é o endereço. Se um dia a Redação tiver versão em
  inglês, é importar daqui.
- **A matéria em inglês aponta para a original**: hreflang pt-BR/en com x-default no português, a
  frase "First published in Portuguese on …" com o link e `translationOfWork` no JSON-LD. As
  imagens são as da original (`/noticias/<slug-pt>/…`), sem cópia.
- **Guias de curso**: dizem que o curso é em português, trocam o botão de matrícula pela página
  `/en/courses/` e pela página do curso na escola, e o WhatsApp por e-mail.
- **Menu em inglês**: "News" entrou no lugar de "Our principles" (seção da home, que também está
  no rodapé). Com um item a mais, o menu quebrava linha entre 1321 e 1440 px; assim ele fica mais
  curto que antes e deixou de quebrar a partir de 1441 px. Rodapé: "News", e Privacidade e Termos
  apontam para as versões em inglês. O espanhol não muda (`gerar_idiomas.py` só age com as chaves
  novas do `idiomas.json`, que o espanhol não tem).
- **Revisão do inglês existente** (`idiomas.json`, `faq-idiomas.json`): grafia britânica uniforme
  (organised, licence), "Five fronts" → "Five areas of work", "Ways in / Four doors" → "Get
  involved / Four ways to take part", tradução do nome da escola na primeira menção, a quadra do
  Palácio descrita pelas quatro ruas, e a porta "Donate" da home, que dizia "From outside Brazil,
  card only" — o formulário pede CPF nos dois meios, como a página de doação já explica.
  "Recognised as being of municipal public utility" virou "officially recognised as a
  public-interest organisation": em inglês, *public utility* é empresa de água e luz.
- **Nomes de curso só no inglês** (aprovados pelo Daniel em 26/09): Punção Venosa virou
  "Peripheral IV Cannulation" (em inglês, *venipuncture* é sobretudo a coleta de sangue; o curso
  ensina o cateter que fica na veia) e "curso livre" virou "non-accredited course" (antes "open
  course", que sugere curso aberto ou gratuito). O português não muda: são chaves do bloco `en`
  de `idiomas.json` e `faq-idiomas.json`, que só `gerar_idiomas.py` lê.

**Falta, na Redação**: as matérias em português, `/noticias/`, `/privacidade/` e `/termos/` ainda
não declaram hreflang para o inglês. `esqueleto.ts` já aceita `alternativas`; o par de cada matéria
está em `traducoes/en/mapa-hreflang.json`. Até lá, o sitemap declara só o lado em inglês. Notícia
nova entra em português primeiro; a tradução segue este mesmo caminho (um .md, gerar, publicar).

## O que a Unicopag valida no documento (21/09/2026)

Sondado direto na API de produção em 21/09, porque a resposta muda quem consegue doar e quem
consegue se matricular. `POST /public/v1/payments` com `customer.document`:

| documento | resposta |
|---|---|
| ausente | 422 `customer.document` obrigatório |
| `X1234567` (alfanumérico) | 422 **"O documento do cliente deve ser numérico"** |
| `123456789` (passaporte de 9 dígitos) | **passa** — nenhum erro em `customer.document` |

**A Unicopag exige que o documento seja numérico e nada além disso.** Ela não confere dígito
verificador, não exige 11 dígitos, não exige que seja um CPF. Quem valida CPF somos nós:
`cpfValido()` em `site/doe/static/doe.js:79` e `mcp_cpf_valido()` em `site/doe/api/doacoes.php:38`
(mesma dupla no checkout da matrícula). Isso derruba a premissa registrada no briefing de que "V1 =
CPF porque a Unicopag exige o documento" (`docs/briefing-matricula-cursos-presenciais.md:507`): a
exigência é de *um documento numérico*, e o passaporte numérico de um doador estrangeiro serve.

Consequência prática: dá para aceitar doador e aluno de fora do Brasil **com o documento verdadeiro
deles**, sem inventar CPF. Gerar CPF aleatório para "destravar" é o caminho errado e está descartado:
um CPF válido sorteado tem chance na ordem de 1 em 4 de pertencer a uma pessoa real (~250 milhões
emitidos num espaço de 1 bilhão), a doação ficaria registrada na Unicopag, na adquirente e no livro
da filial no CPF de um estranho, e documento fabricado em série é motivo de encerramento da conta —
o que pararia todas as doações, não só as de fora.

**Técnica da sonda sem cobrança**: omitir `postback_url` de propósito. A API valida o payload inteiro
e devolve todos os erros de uma vez, então, se `customer.document` não aparece na lista, aquele
documento passou — e nenhuma transação é criada (422 não abre cobrança). Script em
`scratchpad/sondar_limites.php`.

### Ainda não sondado

Faltou rodar a matriz de limites (o classificador de ações barrou a segunda rodada). Em aberto:
faixa de tamanho aceita no documento numérico; se `phone_number` aceita telefone internacional com
código de país ou só o formato brasileiro; e o que fazer com passaporte que tem letra
(Portugal `N123456`, Alemanha `C01X00T47`), que a API recusa — cortar as letras do documento de
alguém não é opção.

## Por que a resposta da equipe é bloqueada pelo Gmail (21/09/2026)

Sintoma: a equipe responde o contato pela caixa do Google e volta um `Mail Delivery Subsystem`
dizendo "Mensagem bloqueada". **Não é e-mail inválido do destinatário** — o endereço está certo e o
chat já valida com `FILTER_VALIDATE_EMAIL`. O erro literal é:

```
550 5.7.26 Your email has been blocked because the sender is unauthenticated.
Gmail requires all senders to authenticate with either SPF or DKIM.
DKIM = did not pass
SPF [cruzvermelhariodejaneiro.org] with ip: [209.85.220.41] = did not pass
```

Consultado na zona em 21/09:

| registro | estado |
|---|---|
| `MX` do domínio raiz | `smtp.google.com` — a caixa é Google Workspace |
| **SPF do domínio raiz** | **não existe** (o único TXT na raiz é um `prtoolkit-verification`) |
| **DKIM do Google** (`google._domainkey`) | **não existe** |
| `_dmarc` | existe: `p=none`, `adkim=s`, `aspf=s` |
| `send.` (SPF + MX) e `resend._domainkey` | existem — são da Resend |

Ou seja: a raiz não autoriza ninguém a enviar por ela. O Google manda do IP dele (209.85.220.41),
o Gmail do destinatário confere e não acha nem SPF nem DKIM que cubram aquele IP, e recusa. Vale
para qualquer destinatário no Gmail — que é a maioria de quem escreve pelo chat.

**A correção são dois registros, nenhum deles toca a Resend:**

1. `TXT` em `@` (raiz): `v=spf1 include:_spf.google.com ~all` — sozinho já destrava, porque o
   Gmail exige SPF **ou** DKIM.
2. `TXT` em `google._domainkey`: a chave gerada no Admin do Google (Apps → Google Workspace →
   Gmail → Autenticar e-mail → gerar registro de 2048 bits, seletor `google`), e depois "Iniciar
   autenticação" no próprio Admin. Só o Admin gera essa chave.

**Não mexer** em `send.cruzvermelhariodejaneiro.org` (SPF e MX) nem em `resend._domainkey`: são da
Resend e derrubam todo o e-mail automático do site. O SPF novo vai na **raiz**, que hoje não tem
SPF nenhum, e não afeta a Resend porque o envelope dela é o subdomínio `send.`, que tem SPF próprio
— SPF se confere contra o envelope, não contra o `From:` visível.

`~all` (softfail) e não `-all`: com o DMARC em `p=none`, softfail não derruba nada que hoje
funcione, e ainda assim satisfaz a exigência do Gmail.

## Comprovante de inscrição em PDF (23/09/2026)

Todo aluno que paga a inscrição recebe, anexado ao e-mail de confirmação, um comprovante em PDF no
estilo do modelo que a filial enviou (o da Punção Venosa de 22/09): faixa vermelha, logo, título,
caixa do participante com borda vermelha, dados da inscrição, pagamento, empresa recebedora e rodapé.
Cores, posições e tamanhos foram lidos do próprio PDF modelo.

- **Onde**: `mcp_email_aluno_pago()` em `api/lib/email.php` gera o PDF e anexa. Vale para as duas
  versões do e-mail (com e sem acesso da escola) e para o reenvio de quando a escola devolve o acesso.
- **Nunca trava a confirmação**: a geração fica num `try`. Se falhar, o e-mail sai do mesmo jeito,
  sem anexo, e o texto volta a dizer "este e-mail é o seu comprovante" em vez de prometer um anexo
  que não está lá. O registro da inscrição anota "com/sem comprovante PDF".
- **Sem biblioteca**: `api/lib/pdf.php` escreve o PDF à mão (a Hostinger não tem Composer aqui e o
  FPDF não é baixável deste ambiente). Helvetica padrão do PDF, texto em cp1252, métricas oficiais
  para quebrar linha, logo como PNG de paleta lido direto dos blocos IDAT — sem GD no servidor.
  Cabe numa página A4 em qualquer caso: se nome, curso, custos e data de pagamento empurrarem o
  rodapé para fora, o espaço entre linhas aperta (nunca a fonte).
- **Logo**: `api/lib/comprovante-logo.png`, 1325 px (300 dpi no tamanho impresso), 32 cores, 31 KB,
  gerado do original de 1730x520 por `scripts/gerar_logo_comprovante.py`.
- **Empresa recebedora**: `O-CVB FILIAL RIO DE JANEIRO ENSINO LTDA - EPP`, CNPJ
  `67.733.551/0001-35` — a empresa de ensino da filial, que recebe a matrícula; **não** é o CNPJ da
  filial (08.560.973/0001-97, o das doações). Veio do comprovante UnicoPag de uma compra real pelo
  checkout e foi confirmado pelo Matheus em 23/09. `RECEBEDOR_NOME` e `RECEBEDOR_CNPJ` no
  `config.php` sobrepõem.
- **Código da compra** é o `unicopag_hash`, o mesmo que o comprovante da UnicoPag mostra.
- **Quatro ajustes em relação ao modelo**: "CPF:" em vez de "CPF/CNPJ:" (o checkout só aceita pessoa
  física); rótulos em caixa de frase, como no resto do site (o modelo misturava "Data do Pedido" com
  "Código da compra"); o rodapé diz de onde vêm os dados em vez de "comprovante fornecido"; e avisa
  "Este comprovante não substitui nota fiscal." (pedido do Matheus, 23/09). O nome
  do aluno sai com as iniciais maiúsculas e as partículas minúsculas ("joana maria dos santos" →
  "Joana Maria dos Santos"), sem baixar letra de ninguém ("McDonald" fica).
- **Conferir o visual**: `php scripts/previsualizar_comprovante.php` gera quatro casos (o do modelo,
  PIX com custos pago horas depois, nome e curso longos, e o pior caso) sem banco nem segredos.
- **Ordem de publicação**: `lib/pdf.php`, `lib/comprovante.php`, `lib/comprovante-logo.png` e
  `lib/email.php` **antes** de `lib.php`. O `lib.php` passa a exigir os dois arquivos novos: subir ele
  primeiro derruba o checkout inteiro até os outros chegarem.
- **No ar desde 23/09/2026**, conferido no próprio servidor (PHP 8.3.33): um diagnóstico temporário,
  já apagado, gerou o comprovante com dados fictícios e o PDF saiu idêntico byte a byte ao gerado
  aqui, fora a data de criação nos metadados. Os PHP publicados foram comparados com os do
  repositório pela API de arquivos.
- **Dados de exemplo são fictícios**: o comprovante real que serviu de modelo não deixa nome, CPF,
  código da compra nem horário no repositório (testes, pré-visualização e esta seção).

## Prazo de resposta: 3 dias úteis, por enquanto (23/09/2026)

Pedido do Matheus enquanto o fluxo da secretaria normaliza: todo lugar que prometia **2** dias úteis
passou a prometer **3** — contato da secretaria depois da inscrição e resposta ao chat. Para voltar,
são estas fontes (35 ocorrências), e depois regerar tudo:

`site/matricula-cursos-presenciais/api/lib/email.php` (`MCP_EMAIL_PRAZO`, vale para todos os
e-mails) · `site/chat/chat.js` (`PRAZO` do cabeçalho do chat) ·
`site/matricula-cursos-presenciais/static/checkout.js` · `site/faq-home.json` ·
`site/faq-idiomas.json` e `site/idiomas.json` ("three business days" / "tres días hábiles") ·
`scripts/gerar_matricula_presencial.py` · `scripts/gerar_bio.py` · `site/equipe.html` ·
`scripts/testar_checkout.php` (dois testes conferem o texto).

Regerar nesta ordem: `gerar_faq_home` → `gerar_matricula_presencial` → `gerar_checkout` →
`gerar_bio` → `gerar_doe` → `gerar_404` → `gerar_idiomas` → `chat_widget` (o `chat.js` muda de hash
e toda página que carrega o chat precisa da tag nova).

## Convenção de nome (19/09/2026)

"Cruz Vermelha Brasileira" sozinha é a instituição nacional. Em todo texto da filial o nome é o
completo, **Cruz Vermelha Brasileira Rio de Janeiro** (ou "Filial Rio de Janeiro"/"Filial do Estado
do Rio de Janeiro" onde já estava assim). A forma curta "Cruz Vermelha RJ" **não vale em texto
visível**: saiu do chat, dos e-mails, dos títulos, das descrições, dos `alt` e dos JSON-LD em 19/09 à
noite, a pedido do Matheus (a caixa do chat mostrava "Cruz Vermelha RJ"). Ela sobrevive só como
`alternateName` no JSON-LD da home, porque é o termo que as pessoas digitam no Google. Títulos seguem
"Assunto | Cruz Vermelha Brasileira Rio de Janeiro", até cerca de 60 caracteres (a home usa
"Cruz Vermelha Brasileira Rio de Janeiro | Site oficial"; a matrícula, "Cursos e matrícula | …").
Nos e-mails, `mcp_email_nome_oficial()` (`api/lib/email.php`) troca um nome curto vindo do
`config.php` (`EMAIL_REMETENTE`, `EMAIL_REMETENTE_CONTATO`) pelo nome completo, mantendo o endereço;
um nome próprio (ex.: "Secretaria de Cursos") é respeitado. `scripts/gerar_faq_home.py` recusa o nome
incompleto na FAQ. Os textos do catálogo da escola (`cursos.json`) são normalizados na geração da
página de matrícula por `nome_filial()` em `scripts/gerar_matricula_presencial.py`, então não
precisam ser editados à mão.

**Domínio antigo da filial: nenhuma ligação** (23/09/2026, a pedido do Matheus). O domínio que a
filial usava antes deste está em disputa judicial: não citar, não linkar, não redirecionar para cá,
não usar em e-mail nem em dados estruturados, em nenhum projeto. Todas as menções foram retiradas do
código e das páginas nessa data; as notícias da Redação no ar também foram conferidas.

## E-mail do domínio: Google Workspace e Resend (auditado em 20/09/2026)

Dois caminhos, que não se misturam: **as caixas da equipe** ficam no Google Workspace, no domínio
raiz; **os e-mails automáticos** (matrícula, doação, chat, newsletter) saem pela Resend, de
subdomínios próprios. Auditoria feita com consultas DNS reais e conferindo mensagens recebidas.

| O que | Estado | Onde |
| --- | --- | --- |
| MX do domínio | ✅ `1 smtp.google.com` | Workspace recebe tudo do domínio raiz |
| Verificação do domínio | ✅ `google-site-verification=OzGrLD5…` | TXT no `@` |
| SPF | ✅ `v=spf1 include:_spf.google.com ~all` | TXT no `@` |
| **DKIM do Google** | ❌ **não existe** | falta `google._domainkey` (e nenhum outro seletor responde) |
| DMARC | ⚠️ `p=none`, alinhamento estrito | só monitora; não age sobre falsificação |
| Caixa `contato@` | ✅ ativa | o próprio Google manda os avisos de onboarding para ela |
| Resend, subdomínio `info.` | ✅ DKIM + `send`/`rsend` | remetente de matrícula e doação |
| Resend, subdomínios `noticias.`, `parceria.` | ✅ | newsletter e parcerias |
| Serviço de e-mail da Hostinger | ❌ não existe nesta conta | `mail_listOrdersV1` devolve zero |

**O que falta fazer, e só o administrador do Workspace consegue**: gerar o DKIM em
_Admin console → Apps → Google Workspace → Gmail → Autenticar e-mail_, escolher o domínio, **Gerar
novo registro** (2048 bits, prefixo `google`) e depois **Iniciar autenticação**. O console devolve um
TXT; com ele em mãos, é um registro a acrescentar na zona (nome `google._domainkey`, TTL 3600). Sem
DKIM, mensagens enviadas pelo Gmail do domínio dependem só do SPF e ficam mais sujeitas a spam e a
falsificação.

**Resíduos do e-mail antigo da Hostinger**, para apagar no hPanel (DNS da zona). Três caminhos pela
API foram tentados em 20/09 e nenhum funciona, então não insista: `DNS_deleteDNSRecordsV1` responde
**422** sem filtro e o conector não repassa o filtro (nem como `filters`, nem como `zone`);
`DNS_updateDNSRecordsV1` com `is_disabled: true` responde "Request accepted" mas **ignora o campo** —
os registros voltam com `is_disabled: false`. A zona não foi danificada em nenhuma tentativa (os 422
são recusados antes de gravar). Pelo painel é seguro e leva um minuto:

- `autodiscover` CNAME → `autodiscover.mail.hostinger.com.`
- `autoconfig` CNAME → `autoconfig.mail.hostinger.com.`
- `hostingermail-a._domainkey`, `hostingermail-b._domainkey`, `hostingermail-c._domainkey` (CNAME)

Os dois primeiros são os piores: fazem Outlook e Thunderbird tentarem configurar uma conta na
Hostinger, que não existe mais. Os três DKIM são inertes. Nada disso derruba e-mail: o MX é do Google
e a entrega não passa por esses registros; é limpeza, não urgência.

Caminho no painel: **hPanel → Domínios → cruzvermelhariodejaneiro.org → DNS / Nameservers → Gerenciar
registros DNS**, localizar cada um dos cinco nomes e clicar em excluir. A Hostinger tira um snapshot
da zona antes de cada alteração, então dá para voltar atrás pelo próprio painel.

**Depois do DKIM**, vale endurecer o DMARC para `p=quarantine` e, mais adiante, `p=reject`. O
alinhamento estrito já em uso passa pelo DKIM da Resend (`d=` é o domínio) e pelo SPF do Gmail.

**Corrigido em 20/09**: os e-mails de doação saíam como `matricula@info.cruzvermelhariodejaneiro.org`,
porque `EMAIL_REMETENTE_DOACAO` estava vazio e caía no remetente da matrícula. Agora saem como
`doacao@info.cruzvermelhariodejaneiro.org`, com resposta para `contato@` (Workspace).

## Doação em `/doe/` (20/09/2026)

> **Fora do ar desde 25/09/2026, a pedido do Matheus.** `/doe/`, `/en/donate/` e `/es/donar/`
> respondem **503** com o aviso `site/doacao-indisponivel.html` (gerado por
> `scripts/gerar_doacao_indisponivel.py`, com o cabeçalho e o rodapé da home). 503 diz à busca que a
> saída é temporária. As regras ficam num `.htaccess` em cada uma das três pastas, e não na raiz: um
> `ErrorDocument 503` na raiz trocaria pelo aviso o 503 que a API da matrícula devolve quando o
> banco cai. A API não cria cobrança nova (`doacoes.php` responde 503), mas `webhook.php` e
> `status.php` seguem de pé para os pagamentos já iniciados, e `/doe/obrigado/` continua abrindo.
> Os links para `/doe/` (menu "Doe", FAQ, chat, campanha, bio, 404) continuam e levam ao aviso.
> **Para voltar:** apagar `site/doe/.htaccess`, `site/en/donate/.htaccess` e
> `site/es/donar/.htaccess`, aqui e no servidor (pela API do gerenciador de arquivos, como está em
> `scripts/publicar_hostinger.sh`), e limpar o cache da Hostinger.

A doação saiu do subdomínio `doar.cruzvermelhariodejaneiro.org` (app separado na Vercel) e passou a
acontecer **dentro do domínio principal**, em `https://cruzvermelhariodejaneiro.org/doe/`, com o
pagamento pela Unicopag. Motivo: endereço melhor para busca orgânica, um só padrão visual e o mesmo
backend que já cuida das matrículas. A inspiração de fluxo é a página da Cruz Vermelha de São Paulo
(`paybox.doare.org`): um cartão só, valor → dados → pagamento, sem sair da página.

- **Página** (`scripts/gerar_doe.py` → `site/doe/index.html`): cabeçalho, rodapé, CSS, GA4, Meta Pixel
  e chat vêm da home. Hero com o cartão de doação, "para onde vai sua doação", transparência
  (CNPJ, utilidade pública, verbete da Wikipédia), como funciona e 7 perguntas frequentes com
  `FAQPage`. JSON-LD: `WebPage`, `BreadcrumbList`, `DonateAction` e `FAQPage`.
- **Tela de agradecimento** (`site/doe/obrigado/index.html`, `noindex`): endereço próprio
  `/doe/obrigado/?t=<token>`, como o `/thankyou/<id>` de São Paulo. Mostra o PIX a pagar (QR, copia e
  cola) ou o comprovante, confirma sozinha pelo `status.php` e é para onde os e-mails apontam.
- **Front-end** (`site/doe/static/doe.js` + `doe.css`): valores sugeridos, valor livre, PIX ou cartão,
  opção de cobrir os custos de processamento, máscaras e as mesmas validações do servidor. Eventos
  GA4 (`begin_checkout`, `add_payment_info`, `purchase`) e Meta (`InitiateCheckout`, `AddPaymentInfo`,
  `Purchase`), com UTMs guardadas na `sessionStorage`.
- **Backend** (`site/doe/api/`): `info.php`, `doacoes.php`, `status.php`, `webhook.php`. Reaproveita o
  bootstrap do checkout (`site/matricula-cursos-presenciais/api/lib.php`): mesmo banco, mesmo cliente
  da Unicopag, mesma moldura de e-mail. O que é próprio da doação fica em `lib/doacao.php` (tabela
  `mcp_doacoes`, criada sozinha) e `lib/email_doacao.php` (PIX em aberto, comprovante de doação
  confirmada e aviso à equipe). `mcp_unicopag()` ganhou um parâmetro de chave, porque a **conta da
  Unicopag das doações é outra**, configurada em `site/doe/api/config.php` (só no servidor, fora do
  repositório; modelo em `config.example.php`). O que não estiver lá cai para a configuração geral do
  checkout: banco, Resend, `SITE_URL`, taxas e e-mails.
- **Quem processa e para onde vai**: a Unicopag recebe a doação e repassa o valor à filial, do mesmo
  jeito que a Cruz Vermelha de São Paulo usa o Doare (no PIX dela aparece "Doare Servicos
  Financeiro"). Isso é dito **uma vez só**, na pergunta "A doação é segura? Quem processa o
  pagamento?", para o nome no extrato não surpreender quem doou sem roubar o foco da doação. O selo
  de confiança e a transparência falam da filial, não do meio de pagamento.
- **Doação mensal**: a Unicopag tem API de assinaturas em base própria
  (`https://subscription.unicopag.com.br/api/v1`, autenticação `Authorization: Bearer`), com webhooks
  (`subscription.activated`, `subscription.renewed`, `charge.paid`…) e cobrança recorrente por cartão,
  PIX ou boleto. Os endpoints de **criação** da assinatura não estão na documentação pública, então a
  integração ainda não foi escrita: a constante `MCP_DOACAO_MENSAL_IMPLEMENTADA` (`lib/doacao.php`)
  é a trava — enquanto for `false`, nem a configuração `DOACAO_MENSAL` liga a opção e a página nunca
  oferece o que o servidor não consegue cobrar. Quando os endpoints estiverem em mãos: implementar,
  virar a constante, ligar `DOACAO_MENSAL` e o seletor "Mensal" aparece sozinho no cartão.
- **E-mail de agradecimento** (`mcp_doacao_montar_email_confirmada`): personalizado, não genérico.
  O assunto leva o primeiro nome e o valor ("Obrigado, Maria! Sua doação de R$ 105,00 foi
  confirmada"); o corpo abre com o nome no título, mostra um selo com o valor e a data, e um bloco
  **"o que esse valor sustenta"** que muda conforme a faixa doada (`mcp_doacao_impacto()`: até R$ 60
  material de primeiros socorros, até R$ 100 educação preventiva, até R$ 250 voluntariado, acima
  disso ação comunitária — as mesmas referências de `IMPACTO` em `scripts/gerar_doe.py`, que precisam
  ser mudadas nos dois lugares). Depois vêm os parágrafos que só aparecem quando cabem: cobriu os
  custos, doação anônima, doação mensal. Fecha com o comprovante (protocolo e CNPJ), o convite para
  acompanhar as ações e para se cadastrar como voluntário, e a assinatura da equipe. A versão em
  texto puro acompanha as mesmas variações.
- **Doação anônima**: caixa "Quero doar anonimamente" antes do aceite. Grava `anonimo` em
  `mcp_doacoes` (coluna criada sozinha por `mcp_garantir_colunas`), aparece como "Divulgação: doação
  anônima" no comprovante e no aviso à equipe, e a tela de agradecimento confirma. Nome, CPF, e-mail
  e telefone continuam obrigatórios porque a Unicopag exige, mas a filial não usa o nome em
  agradecimento público. Mesma ideia do `discloseDonorCheckbox` da página de São Paulo.
- **Marcação de origem**: os botões da Campanha do Agasalho levam
  `?utm_source=site&utm_medium=agasalho&utm_campaign=campanha-agasalho` e o da home
  `utm_medium=home`; o `doe.js` guarda as UTMs e elas entram na doação e no aviso à equipe. O link do
  menu fica sem marcação, porque é navegação.
- **Endereços antigos**: `/doacao.html` responde **301** para `/doe/` (regra em `site/.htaccess`); o
  arquivo continua no repositório, mas a regra vem antes. O subdomínio
  **`doar.cruzvermelhariodejaneiro.org` saiu da Vercel em 20/09/2026**: virou subdomínio da Hostinger
  (`hosting_createWebsiteSubdomainV1` trocou o CNAME da Vercel por um ALIAS do CDN da Hostinger) com a
  pasta `site/doar/`, cujo `.htaccess` responde 301 para `/doe/`. Certificado emitido e ativo. O
  projeto antigo na Vercel continua existindo, sem tráfego: pode ser apagado lá quando quiser.
- **Testes**: `php scripts/testar_doacao.php` (59 testes, sem banco e sem rede) cobre configuração,
  valores, protocolo, visão pública (sem CPF, hash do provedor, IP ou telefone) e os três e-mails.

## Verificação de documentos em `/verificar/` (24/09/2026, escondida)

Página que confere documentos e publicações que a filial registrou na trilha pública de auditoria:
ofícios, certificados de curso, comunicados à imprensa, matérias, documentos do portal de
transparência, parcerias (MROSC) e a página de canais oficiais. O registro é encadeado por hash e fecha
um lote por dia, com carimbo de tempo RFC 3161 (FreeTSA), âncora no Bitcoin (OpenTimestamps) e manifesto
assinado (Ed25519). A página só consulta: quem registra, fecha os lotes e grava as provas é a Redação
(`POST https://redacao.cruzvermelhariodejaneiro.org/api/publico/verificar`, construída em paralelo).

**Escondida, de propósito.** O lançamento é discreto:

- `noindex, nofollow, noarchive` na meta tag e no cabeçalho `X-Robots-Tag` de `site/verificar/.htaccess`,
  que vale para a pasta inteira, inclusive para o que a Redação gravar por FTP em `lotes/`.
- Nenhum link para `/verificar/` em página, menu, rodapé, sitemap, hreflang, `llms.txt` ou `robots.txt`
  (pôr no `robots.txt` seria anunciar o caminho). `conferir_links.py` e `validar_jsonld.py` deixam a pasta
  de fora; `conferir_links.py` e `rastrear_site.py` acusam como falha qualquer link de página pública para
  ela; `gerar_sitemaps.py` tem lista fechada e ainda descarta página com `noindex`.
- **O código na URL funciona como senha do registro** (`?c=` vem do QR code), então nada de terceiros:
  sem GA4, sem Meta Pixel, sem Google Fonts (a "Inter Reserva" segura o texto na fonte do aparelho, nas
  medidas da Inter) e sem o chat, que manda o endereço da página, com o código, no aviso à equipe. Soma-se
  `Referrer-Policy: no-referrer` (meta e cabeçalho), uma CSP com o hash de cada script inline
  (`default-src 'none'`; `connect-src` só para a API e, para o teste local, localhost),
  `frame-ancestors 'none'` e um 404 próprio da pasta: o 404 geral do site carrega GA4 e Pixel.
- O endereço da API é fixo no código. `?api=` troca o servidor só com a página em `localhost` ou
  `127.0.0.1`, com a faixa "Modo de teste" na tela. No domínio de verdade, um link com `?api=` mostraria
  "Confere" vindo do servidor de qualquer um.

**Três jeitos de consultar.** Digitando o código (26 caracteres; 32 no ofício; `XXXX-XXXX` no
certificado; também vale a impressão digital SHA-256, de 64, e o link inteiro colado), pelo link do QR
code (`/verificar/?c=<código>` consulta sozinho) ou soltando o arquivo: o navegador calcula o SHA-256
(`crypto.subtle`) e só ele vai para a API, com limite de 100 MB. O arquivo não sai do aparelho. A
validação no navegador é leve (tamanho e caracteres) e vai o que a pessoa digitou, sem as bordas; quem
decide é o servidor.

| Resposta da API | O que a página mostra |
| --- | --- |
| 200, `vigente`, com lote | **Confere** (verde, ícone de confirmação): "Confere: ofício autêntico" |
| 200, `vigente`, lote nulo | **Registrado — prova em confirmação** (azul, relógio): registrado, lote do dia ainda aberto |
| 200, `substituido` | **Substituído** (âmbar), com a data e a versão nova (código, link e botão para verificá-la) |
| 200, `revogado` / `retirado` | **Revogado** (vermelho) / **Retirado do ar** (cinza), com a data |
| 200, `encontrado: false` | **Não encontrado**, com "Isso não prova que o documento seja falso…" |
| 400 / 429 | **Código não reconhecido** / **Limite de consultas** (com o tempo do `Retry-After`) |
| 5xx, erro de rede, 20 s sem resposta | **Serviço indisponível**, tente mais tarde |

Cada resultado traz o tipo em palavras, as datas no horário de Brasília (as de classe V e C, que chegam
só com o dia, saem como vieram, sem passar por fuso, senão 01/09 viraria 31/08), versão, título e link
(só classe P), o bloco do certificado (classe C), as impressões digitais com botão de copiar, o estado das
provas (Bitcoin, RFC 3161, manifesto assinado, cadeia íntegra) e os arquivos para baixar. Tudo o que vem
da API entra como texto (`textContent`, nunca `innerHTML`); só vira link o que começa com `https://`,
sempre com `rel="noopener noreferrer"`. "Imprimir relatório" gera o "Relatório de verificação" com a
consulta, o `consultado_em` e o resultado inteiro, sem menu, rodapé e botões. Abaixo do formulário ficam
"O que é esta página" e "Como conferir sem depender da Cruz Vermelha" (`sha256sum`, `ots verify`,
`openssl ts -verify` com os certificados da FreeTSA, `openssl pkeyutl -verify -rawin` e o
`sha256sum compromisso.bin`).

**Arquivos de prova**, gravados pela Redação por FTP (podem ainda não existir; a página não depende deles
para funcionar): `/verificar/chave-publica.pem` (e uma cópia permanente de cada chave em
`/verificar/chaves/<chave_id>.pem`, para conferir lotes antigos depois de uma troca de chave),
`/verificar/lotes/indice.json` e, por dia,
`/verificar/lotes/AAAA-MM-DD/` com `manifesto.json`, `manifesto.json.sig`, `manifesto.json.tsr`,
`compromisso.bin` e `compromisso.bin.ots`. O `.htaccess` da pasta dá o tipo certo a `.ots`, `.sig`,
`.bin`, `.tsr` e `.pem` e manda revalidar `.json` e `.ots` (a prova do Bitcoin é completada depois da
publicação). Sem regra de rewrite, para não mexer nesses arquivos.

**Regenerar**: `python3 scripts/gerar_verificar.py` grava `site/verificar/index.html` e
`site/verificar/404.html`. Rodar de novo quando mudarem o cabeçalho, o rodapé ou o CSS da home; não
editar o HTML gerado. O gerador trava se faltar o `X-Robots-Tag` no `.htaccess`, se aparecer script,
fonte ou pixel de terceiros, se o JS usar `innerHTML` ou se sobrar marcador. Ícones novos da página
(estados, imprimir, baixar) entraram em `scripts/icones.json`.

**Testar localmente**:

```
python3 scripts/gerar_verificar.py
python3 -m http.server 8767 --bind 127.0.0.1 --directory site
```

e abrir `http://127.0.0.1:8767/verificar/?api=http://127.0.0.1:8799&c=<código>`, com uma API falsa em
`127.0.0.1:8799` que responda `POST /api/publico/verificar` pelo contrato e o `OPTIONS` do CORS
(`Access-Control-Allow-Origin: http://127.0.0.1:8767`, `Access-Control-Allow-Headers: Content-Type`,
`Access-Control-Expose-Headers: Retry-After`). A API de verdade só aceita a origem do site e não serve
para teste local; sem API nenhuma, a página mostra "Serviço indisponível", o que basta para conferir o
layout. Para renderizar igual ao ar, o `site/assets/` local precisa de `otim/logo-cvb-rj-520.webp`,
`favicon.svg` e `favicon.png`. Testado assim em 24/09/2026, no Chromium (Playwright), com uma API falsa
com um cenário por estado: 190 conferências, entre elas o SHA-256 do navegador igual ao `sha256sum`
(seletor e arrastar), dados hostis da API (marcação, `javascript:`, `http:`, `data:`) virando texto, as
mesmas datas com o aparelho em Honolulu e Tóquio, `?api=` ignorado fora de localhost, impressão,
teclado, 360 px sem rolagem lateral e nenhum pedido de rede além do site e da API.

### Checklist de abertura

Antes de publicar (a página vai ao ar, ainda escondida):

1. API da Redação no ar, com CORS para `https://cruzvermelhariodejaneiro.org` (preflight `OPTIONS`
   incluído) e `Access-Control-Expose-Headers: Retry-After`. Sem esse cabeçalho, o navegador não deixa a
   página ler o tempo de espera, e o 429 diz "aguarde alguns minutos".
2. `python3 scripts/gerar_verificar.py` sem erro.
3. Publicar nesta ordem, para a página nunca ir ao ar sem o cabeçalho `noindex`:
   `scripts/publicar_hostinger.sh site/verificar/.htaccess site/verificar/404.html site/verificar/index.html`,
   e limpar o cache.
4. Conferir ao vivo: `curl -sI https://cruzvermelhariodejaneiro.org/verificar/ | grep -i x-robots-tag`, o
   mesmo numa URL inexistente dentro da pasta (tem de cair no 404 próprio) e, quando houver, num arquivo
   de `lotes/`; abrir com um código real e com um inventado; no DevTools, aba Rede, só pedidos ao próprio
   site e à API.
5. Nenhuma referência ao caminho fora da pasta: `grep -rnE 'verificar(/|"|\?|#)' site --exclude-dir=verificar
   --exclude=config.php` vazio e `python3 scripts/conferir_links.py` sem "link para página escondida".
6. A Redação publica `chave-publica.pem` em `/verificar/` e fecha o primeiro lote em `/verificar/lotes/`;
   rodar uma vez, à mão, os comandos de "Como conferir" com esses arquivos.

Para abrir ao público, quando for decidido:

1. Tirar o `noindex` da página (meta tag no gerador) e passar o `X-Robots-Tag` do `.htaccess` da pasta para
   um `.htaccess` em `lotes/`: as provas continuam fora da busca.
2. Canonical, descrição e Open Graph próprios no gerador.
3. Link no rodapé (na home, que os geradores copiam), entrada em `PAGINAS` do `gerar_sitemaps.py` e no
   `llms.txt`.
4. Tirar `verificar` de `ESCONDIDAS` em `conferir_links.py`, `validar_jsonld.py` e `rastrear_site.py`.
5. Continuar sem GA4, Pixel, fontes de terceiros e chat, com `no-referrer`: o código segue indo na URL.

## Acervo em `/acervo/`: a parte pública (24/09/2026)

`cruzvermelhariodejaneiro.org/acervo/` é a parte pública do acervo da filial. A parte privada é a
tela **Acervo** da Redação (`redacao.cruzvermelhariodejaneiro.org/acervo`, com login): lá a equipe
manda os arquivos para o R2, cataloga cada item e decide o que vai para o site. O que não for
publicado fica só no R2, fora do site. `/acervo/equipe/` é a porta da equipe no domínio principal:
um 302 para essa tela, fora da busca (`Disallow` no `robots.txt` e `rel="nofollow"` no link).

Quem é dono de quê (nada deste repositório escreve dentro de `site/acervo/`: a pasta é da Redação
e seria sobrescrita na publicação seguinte):

| O quê | Quem gera | Como chega ao ar |
| --- | --- | --- |
| `/acervo/` (apresentação), `/acervo/<coleção>/` e `pagina/N/`, `/acervo/<coleção>/<item>/`, `/acervo/arquivos/` (WebP e PDF) e `/acervo/.htaccess` | Redação (`lib/acervo/paginas.ts` e `publicacao.ts`) | FTP, a cada item publicado; o botão "Atualizar páginas" refaz tudo |
| as coleções e os itens no `sitemap.xml`, com a imagem de cada item | Redação (`lib/site/sitemap.ts`) | idem |
| `/en/archive/` e `/es/acervo/` | `scripts/gerar_idiomas.py` (textos em `site/idiomas.json`) | `scripts/publicar_hostinger.sh` |
| link "Acervo" no rodapé | as páginas daqui; a Redação põe o mesmo link nas dela | idem |
| `robots.txt` | os dois repositórios gravam o mesmo conteúdo (a Redação regrava a cada notícia) | idem |
| `site/assets/otim/og-acervo.jpg` (1200×630, imagem de compartilhamento) | `scripts/gerar_og_acervo.py` | idem (`site/assets/` não é versionada) |
| `/acervo/`, `/en/archive/` e `/es/acervo/` no `sitemap-paginas.xml`, com os três hreflang | `scripts/gerar_sitemaps.py` | idem; o gerador só inclui o que já responde 200 |

O que as páginas levam para a busca:

- **Endereço permanente.** Depois da primeira publicação, coleção e endereço do item não mudam
  (trava no banco da Redação). Retirar um item do site apaga a página e os arquivos dele.
- **Dados estruturados.** `CollectionPage` + `Collection` na apresentação, `CollectionPage` +
  `ItemList` em cada coleção (24 itens por página, com `rel=prev/next` na navegação), `ItemPage`
  em cada item com `ImageObject` (licença, crédito, autoria e aviso de direitos, que o Google
  Imagens mostra como "Detalhes da licença"), `VideoObject` (miniatura do YouTube ou do Vimeo) ou
  `DigitalDocument` (PDF), sempre com `BreadcrumbList`.
- **Imagens.** Três larguras em WebP (480, 960 e 1600), sem EXIF (a localização de uma foto não
  vaza), com `srcset`, texto alternativo obrigatório e `max-image-preview:large`.
- **Compartilhamento.** `og:image` e cartão grande do X por item (a própria foto; PDF e vídeo sem
  imagem usam `og-acervo.jpg`).
- **PDF.** Servido pelo próprio domínio, com cabeçalho `Link: rel="canonical"` apontando para a
  página do item: a busca junta os dois em vez de indexar o PDF solto.
- **Idiomas.** O catálogo é em português (a língua do material). `/en/archive/` e `/es/acervo/`
  apresentam o acervo e levam ao português; os três se declaram por `hreflang` (e `x-default` no
  português), no HTML e no sitemap.

Primeira publicação (cada passo depende de aprovação):

1. Redação: aplicar a migração `20260926003000_cvrj_acervo.sql`, pôr `R2_BUCKET_ACERVO=cvrj-acervo`
   (e as outras `R2_*`) na Vercel e publicar.
2. Aqui: `python3 scripts/gerar_og_acervo.py` e publicar `/en/archive/`, `/es/acervo/`, as páginas
   com o rodapé novo, `robots.txt`, `llms.txt` e `site/assets/otim/og-acervo.jpg`. A apresentação
   `/acervo/` e o `.htaccess` dela saem do código da Redação: publicados junto na primeira vez, e a
   Redação passa a regravá-los.
3. Conferir: `/acervo/` 200, `/acervo/equipe/` 302 para a Redação, `/acervo/nao-existe/` 404.
4. `python3 scripts/gerar_sitemaps.py`, publicar os sitemaps e limpar o cache.
5. Search Console: inspecionar `/acervo/` e pedir a indexação.

**Publicado em 24/09/2026** (passos 2 a 4): `/acervo/` (apresentação sem itens, gerada com o código
da Redação) e o `.htaccess` dela, `/en/archive/`, `/es/acervo/`, o rodapé novo em 20 páginas,
`robots.txt`, `llms.txt`, `og-acervo.jpg` e os sitemaps. Conferido ao vivo: os arquivos iguais aos
do repositório, `/acervo/` 200, `/acervo/equipe/` 302 para a Redação, `/acervo` 301 para a barra,
página inexistente 404, `/verificar/` continua fora (404) e os 90 links internos respondem 200.
Falta o passo 1 (Redação no ar): até lá, `/acervo/equipe/` leva a uma tela que ainda não existe na
Redação publicada, e os itens só entram quando a tela Acervo estiver no ar.

## Acervo no Cloudflare R2: cópia do site (24/09/2026)

O acervo da filial fica no bucket privado `cvrj-acervo` do Cloudflare R2 (buckets, travas e tokens
em `docs/armazenamento-r2.md`, no repositório da Redação). `scripts/copiar_site_para_o_acervo.sh`
copia o site como está no ar para `site/AAAA-MM-DD/`, com um `MANIFESTO.sha256` (conferir com
`sha256sum -c`) e um `SOBRE.txt`:

```bash
R2_ACCOUNT_ID=… R2_ACCESS_KEY_ID=… R2_SECRET_ACCESS_KEY=… scripts/copiar_site_para_o_acervo.sh
```

- Segue os links a partir da home com `wget`: entra o que o público vê (as notícias da Redação
  incluídas). O que não tem link, como `/verificar/`, e o que o servidor não entrega
  (`config.php`, `api/`) ficam de fora por construção.
- A pasta `site/` do bucket tem trava de 30 dias: uma cópia enviada não se apaga nem se troca, e
  rodar duas vezes no mesmo dia é recusado.
- Usar um token do R2 só do bucket do acervo (Object Read & Write), nunca o de administrador.
- A primeira cópia, de 24/09/2026, tem 145 arquivos (21,7 MB) e foi conferida arquivo por arquivo
  pelo manifesto, baixada de volta do R2.

## Revisão de erros, informações e SEO depois das atualizações de 24/09 (25/09/2026)

Pedido do Matheus: uma revisão de todo o site depois de um dia de muitas atualizações, com atenção
ao SEO e às informações. Três frentes: as auditorias automáticas (`rastrear_site.py`,
`conferir_links.py`, `validar_jsonld.py`, `auditar_seo.py`), a conferência de cada informação
contra a fonte (catálogo da escola ao vivo, `/doe/api/info.php`, Wikipédia, o próprio código) e a
auditoria das páginas que a Redação gera (`/noticias/`, `/privacidade/`, `/termos/`, `/acervo/`).

### Site estático (publicado em 25/09, conferido ao vivo)

| Estava assim | Ficou | Onde |
|---|---|---|
| FAQ (PT, EN, ES e chat) prometia escolher "única ou mensal" na doação | a doação mensal ainda não existe (`"mensal": false`): por enquanto é avulsa | `faq-home.json`, `faq-idiomas.json` |
| "Últimas vagas" no Cuidador de Idosos e "próxima turma em breve" na Punção | a escola não tem turma aberta de Cuidador; agora escolaridade mínima e "datas na plataforma da escola" | `index.html` |
| FAQ mandava ver datas e escolher turma na página de matrícula | a página não mostra datas nem turma: a secretaria confirma por e-mail | `faq-home.json` |
| "10h às 17h" como horário dos cursos | é o horário de receber donativos; curso segue o horário da turma (Bombeiro Civil é à noite) | FAQ PT/EN/ES |
| R$ 99 de inscrição sem explicar os R$ 100 da escola; EN/ES diziam "sem parcelas" | R$ 99 pelo site, R$ 100 direto na escola; a escola parcela com juros | FAQ, matrícula, `idiomas.json`, `llms.txt` |
| EN/ES: filial "servindo desde 1908" | 1908 é a nacional; a pessoa jurídica da filial é de 2006 | `idiomas.json` |
| LinkedIn e TikTok no rodapé apontando para a página inicial das redes | só Facebook e Instagram (como já dizia o briefing) | home, equipe e as 22 páginas geradas |
| Menu por cima do seletor PT/EN/ES entre 1181 e ~1300 px e entre 1367 e ~1420 px | sanfona até 1320 px e menu compacto até 1440 px; folga mínima de 22 px com a Inter | CSS da home e da equipe |
| `llms.txt` com resposta em 2 dias úteis | 3 dias úteis | `llms.txt` |
| "tombado pelo IPHAN" | tombamento provisório federal, comunicado em 2021 | `equipe.html` |
| Bio citava campanha de alimentos | não existe; só a Campanha do Agasalho | `gerar_bio.py` |
| `/index.html` e `/en/index.html` respondiam 200 (cópias) | 301 para o endereço com barra | `.htaccess` |
| Sitemap de notícias sem as 7 matérias de 24/09 | 20 matérias | `gerar_sitemaps.py` |

Também: `og:site_name` e o `WebSite` do JSON-LD como "Cruz Vermelha Brasileira Rio de Janeiro"; o
nó da organização das homes EN/ES com o mesmo nome e a mesma URL da home em português (o `@id` é
o mesmo, então os dados não podem se contradizer); `availableLanguage` pt-BR, en, es; "8 hours" na
página de cursos em inglês; ÚnicoPag com a grafia da empresa; aviso sem JavaScript certo em cada
página do checkout; Lei Lucas sem "Stop the Bleed" (a escola tirou do curso).

**Ordem dos geradores quando o `chat.js` muda** (a FAQ muda o `chat.js`): `gerar_faq_home` →
`chat_widget` → geradores das páginas (`gerar_matricula_presencial`, `gerar_checkout`, `gerar_bio`,
`gerar_doe`, `gerar_404`, `gerar_idiomas`, `gerar_verificar`) → `chat_widget` de novo →
`aplicar_imagens_otimizadas`. Rodar o `chat_widget` só no fim deixa as páginas geradas com o hash
velho do chat, e rodar antes do `gerar_404` apaga as tags do chat do 404.

### Páginas da Redação (PR #201 da Redação, em produção)

Títulos de 96 a 104 caracteres, JSON-LD incompleto, datas que zeravam a cada republicação, PNG de
1,5 a 2 MB sem medidas, cabeçalho e rodapé diferentes da home, WhatsApp e Markdown cru com o
endereço `/api/private-blob` à mostra: tudo corrigido no gerador. O detalhe está no PR e em
`ARQUITETURA.md` §7.6 da Redação. **"Regerar as páginas das notícias"** (Configurações, só admin)
refaz as matérias no ar com o molde novo; precisa de login de admin, então é um clique do Matheus
depois do deploy.

### Conteúdo das matérias (banco da Redação, aprovado pelo Matheus em 25/09)

- Lei Lucas e SBV: WhatsApp da secretaria trocado pelo chat do site (o atendimento é por e-mail).
- SBV e Primeiros Socorros Básico: link do SAMU atualizado (o antigo redirecionava); a notícia de
  2024 do Ministério passou a pedir login e saiu das fontes.
- "Cruz Vermelha RJ" pelo nome completo em quatro textos e no título do Setembro Amarelo; título da
  ciclovia fora da caixa alta.
- `/noticias/7-de-setembro/` fica (subtítulo que estava cortado, legenda que descreve o cartaz e
  "cadastro encerrado" no lugar do botão sem link). As duas duplicatas do chamado saíram do ar, com
  301 para ela (`REDIRECIONAMENTOS_DAS_NOTICIAS` na Redação; o `.htaccess` de `/noticias/` já foi
  enviado e as pastas velhas foram apagadas do servidor).
- A versão anterior de cada matéria ficou em `content_versions` (ponto de restauração) e cada
  correção tem registro em `activity_log`. `updated_at` não mudou: a correção é pontual, e assim o
  "Regerar" não pula essas matérias.

### Ficou de fora, de propósito

- **Telefone (21) 99992-2864 no rodapé e no JSON-LD**: é o WhatsApp da secretaria, e a FAQ diz que
  o atendimento é por e-mail. Mantido por coerência de nome, endereço e telefone com o Google; se o
  número não deve atender, sai do rodapé e do `telephone`.
- **Trilha de auditoria**: as migrações ainda não estão em produção; a republicação só registra a
  versão na trilha quando a RPC existir.

## Visitas de pessoas × robôs no Google Analytics (27/09/2026)

Pergunta do Matheus: quantas das visitas de outros países no GA4 são de gente? O GA4 descarta
sozinho só os robôs que se declaram (lista da IAB). Navegadores automatizados que rodam o
JavaScript a partir de data centers (raspadores, ferramentas de SEO e de velocidade, robôs de IA,
as ondas vindas da China e de Singapura) entram como visitas, quase sempre de outro país, sem
engajamento e numa página só.

- **Desde 27/09, o aviso de cookies funciona como filtro:** o GA4 só carrega depois de um clique
  em "Aceitar", e robô não clica. O preço é não contar quem recusa. Comparar o antes e o depois
  por país mostra o tamanho do problema: país cuja audiência "some" depois do aviso era robô.
- `scripts/analisar_visitantes_ga4.py` lê o GA4 pela Data API e classifica cada combinação de
  país, cidade, navegador, sistema, resolução, idioma, origem e página de entrada em robô, provável
  robô, pessoa ou indefinido. Por país, mostra a faixa de pessoas reais (mínimo: só sessões
  engajadas; máximo: tudo que não parece robô), antes e depois do aviso, por onde as pessoas de
  fora chegam e as maiores fontes de robô. `--teste` confere a classificação sem rede.
- **A Data API não aceita chave de API**, só conta de serviço. Para rodar: criar uma conta de
  serviço no projeto do Google Cloud (sem papel nenhum no projeto), gerar a chave JSON, adicionar o
  e-mail dela como **Leitor** na propriedade do GA4 e passar `GA4_CHAVE_JSON` (caminho da chave,
  fora do repositório) e `GA4_PROPRIEDADE` (ID numérico). A chave só lê o Analytics; apagar
  depois do uso.

## Aviso de cookies, políticas obrigatórias e fim dos convites para doar (27/09/2026)

Pedido do Matheus: as páginas de política que todo site precisa ter, o aviso de cookies, nas três
línguas, em todos os sites feitos para a filial; e tirar do ar tudo o que convida a doar dinheiro
(a doação online está suspensa desde 25/09). Decisão dele sobre o encarregado: **sem nome por
enquanto**, com `contato@cruzvermelhariodejaneiro.org` como canal do titular (art. 11 da Resolução
CD/ANPD nº 2/2022, agente de pequeno porte).

### Aviso de cookies (LGPD; Guia de Cookies da ANPD, 2022)

- `site/consentimento/consentimento.js` (cache de um ano; a URL leva o hash do conteúdo): aviso
  com **Rejeitar, Personalizar e Aceitar todos**, do mesmo tamanho; painel com Necessários (sempre
  ligados), Estatística e Marketing (desligados até a pessoa ligar); textos pela língua da página.
  Qualquer elemento com `data-cvrj-cookies` reabre o painel ("Preferências de cookies" em todo
  rodapé). Esc fecha e devolve o foco; o painel prende o Tab.
- A escolha fica no cookie `cvrj_consentimento` (`v=1&e=0|1&m=0|1&t=<unix>`, 12 meses,
  `Domain=.cruzvermelhariodejaneiro.org`): quem escolheu no site principal não é perguntado de novo
  nos subdomínios (Impacto das Cores, Punção Venosa, Redação).
- O bloco de medição da home (entre `<!-- Google tag (gtag.js) -->` e `<!-- End Meta Pixel Code -->`)
  começa com o Consent Mode v2 negado e **só baixa o gtag.js e o fbevents.js com permissão**
  (`window.cvrjMedicao.aplicar`, que também chama `fbq('consent', 'grant')`). Revogar liga
  `ga-disable-G-HDYZZ5JZHF`, chama `fbq('consent', 'revoke')` e apaga `_ga*`, `_gid`, `_fbp` e
  `_fbc`. O pixel em `<noscript>` saiu (sem JavaScript não há escolha). **Não há
  `fbq('consent', 'revoke')` antes do `init`:** de 27/09 a 02/10 essa linha travou o Pixel, que
  baixava e não enviava nada, nem para quem aceitou (detalhes em `docs/rastreamento.md`).
- `scripts/consentimento.py` carimba o hash do aviso na home e copia o bloco para as páginas
  mantidas à mão (equipe, campanha, `doacao.html` e o Impacto das Cores, este com o endereço
  completo do aviso). Rode **antes** dos geradores, que copiam o bloco da home.
- A origem da visita (utm, fbclid, gclid) só vai com a inscrição, com a mensagem do chat ou para a
  escola (bio) se a pessoa permitiu estatística.
- Conferido no Chromium, página por página (33 páginas): nenhum pedido ao Google ou à Meta antes da
  escolha; "Aceitar todos" baixa os dois scripts; revogar apaga os cookies; a Política de Cookies
  mostra a escolha atual.
- Baixar o script não basta: `scripts/conferir_pixel.js` exige que os eventos cheguem à Meta
  (PageView, ViewContent, InitiateCheckout) depois de aceitar, e nada sem permissão. Os envios
  são abortados no teste. Com `--repositorio`, usa as páginas de `site/` no lugar das do ar, para
  conferir antes de publicar.

### API de Conversões da Meta (02/10/2026)

Os eventos do funil saem também pelo servidor, com o mesmo id que o Pixel usa no navegador, e a Meta junta
os dois. Só para quem aceitou marketing no texto atual do aviso, marcado no cookie com `r=2` (quem tinha
aceitado antes, ou no aviso da Punção, é perguntado de novo). O token da inscrição nunca vai: os ids da
compra são um hash dele (`id_compra`). Depois da inscrição, quem abre o link só consegue retirar a
permissão. Detalhes, tabela de eventos e o que fica guardado em `docs/rastreamento.md`.

- `api/lib/meta.php`: monta e envia os eventos, depois da resposta ao aluno; nunca derruba o pagamento.
  Lead e AddPaymentInfo saem de `pagamentos.php`; Purchase, de `mcp_pos_pagamento` (inclusive pelo postback);
  Contact, de `contato.php`. Nome, e-mail e telefone vão em hash SHA-256; o CPF nunca vai.
- `api/medicao.php`: repasse dos eventos de página (PageView, ViewContent, InitiateCheckout) que o bloco de
  medição manda (`window.cvrjMedicao.servidor`), com teto de 120 por minuto no site e freio por IP (20 por
  minuto, 120 em 10 minutos).
- **Para ligar:** o token vem do Gerenciador de Eventos (conjunto de dados `2224500131617302` >
  Configurações > API de Conversões > Gerar token de acesso). No hPanel, em
  `public_html/matricula-cursos-presenciais/api/`, crie `config-meta.php` com
  `<?php return ['META_CAPI_TOKEN' => 'o token'];`. Para conferir, acrescente `'META_CAPI_TESTE' =>
  'o código da aba Testar eventos'` e tire depois. Sem o token, nada é enviado nem guardado.
- Mudou o texto do aviso sobre o que vai à Meta: suba juntos `MCP_META_REVISAO` (`api/lib/meta.php`),
  `REVISAO` (`site/consentimento/consentimento.js`) e `REVISAO` do bloco de medição de `site/index.html`, rode
  `scripts/consentimento.py` e os geradores e copie o bloco para `lib/site/analytics.ts` da Redação.
- Testes: `php scripts/testar_checkout.php` (funções), `scripts/testar_meta_integracao.php` (ponta a ponta,
  com banco local e uma Meta falsa) e `scripts/conferir_pixel.js` (navegador: o mesmo id no Pixel e no repasse).

### Políticas em português, inglês e espanhol

`scripts/gerar_politicas.py` gera 12 páginas a partir de `site/politicas.json` (o texto, com as
fontes no código anotadas); o português usa o cabeçalho e o rodapé da home, o inglês e o espanhol a
`moldura()` de `gerar_idiomas.py`, com hreflang recíproco e seletor de idioma para a mesma política.

| Política | Português | Inglês | Espanhol |
|---|---|---|---|
| Privacidade (LGPD) | `/privacidade/` | `/en/privacy/` | `/es/privacidad/` |
| Cookies | `/cookies/` | `/en/cookies/` | `/es/cookies/` |
| Termos de Uso | `/termos/` | `/en/terms/` | `/es/terminos/` |
| Cancelamento e reembolso | `/reembolso/` | `/en/refunds/` | `/es/reembolsos/` |

- Privacidade: controladora e canal do titular; cada tratamento com dados, finalidade e **base
  legal** (art. 7º); compartilhamento (ÚnicoPag, plataforma da escola, Hostinger, Resend, Vercel e
  Supabase, Google e Meta só com permissão, Spotform); transferência internacional (art. 33); guarda
  por critério (o código não tem rotina de exclusão automática, então não se prometeu prazo que não
  existe, salvo a guarda legal de registros de pagamento); direitos do art. 18 e prazo do art. 19.
- Reembolso: **7 dias de arrependimento** (CDC, art. 49) com devolução integral, as regras que já
  valiam (estorno sem horário compatível ou antes da confirmação da turma), como pedir (chat, com
  confirmação imediata por protocolo, ou e-mail) e quem vende (Decreto 7.962/2013). Checkout,
  página de matrícula e e-mails ao aluno citam os 7 dias; o checkout mostra CNPJ e endereço e
  avisa que pagar é aceitar os termos e as regras de reembolso.
- Termos: foro do domicílio do consumidor nas relações de consumo (CDC, art. 101, I).
- `/privacidade/` e `/termos/` eram da Redação: saíram de lá (`publicarPaginasJuridicas` e as
  pastas na lista do FTP) e entraram aqui (`.gitignore` atualizado).

### Doação em dinheiro fora do site

- Sem "Doe" no menu (home, equipe, Redação, inglês e espanhol) e sem "Fazer uma doação" na home
  (virou "Seja voluntário"); FAQ e chat com "Como ajudar" (voluntariado, Campanha do Agasalho,
  parcerias); assunto do chat "Campanha do Agasalho e parcerias".
- Campanha do Agasalho: só entrega de roupas na sede e voluntariado (saíram o checkout, a faixa
  "Doação online", o agradecimento com cursos gravados e o botão fixo de doar).
- Impacto das Cores (`site/projetocores/index.html`, antes só no servidor): convida para o
  voluntariado, com canonical, `og:image` absoluta, aviso de cookies e políticas no rodapé.
- Bio, 404, `llms.txt` e sitemaps sem doação. `/en/donate/` e `/es/donar/` deixam de ser geradas.
- Para a doação voltar: `DOACAO_NO_AR = True` em `gerar_idiomas.py` e `gerar_doe.py`, rodar os
  geradores, tirar as regras 503 de `site/doe/.htaccess`, `site/en/donate/.htaccess` e
  `site/es/donar/.htaccess` e rever a FAQ, a campanha e as políticas.
- As 5 matérias da Redação que chamavam para a página de doação (Chocó, Helicóptero, Ciclovia,
  Setembro Amarelo, "Recebemos") passam a chamar para o voluntariado, no padrão da revisão de 25/09
  (versões guardadas, `updated_at` intacto, `conteudo_corrigido_na_revisao`), depois do "Regerar".

### Ordem de geração

`consentimento.py` → `gerar_faq_home.py` → `chat_widget.py` → `gerar_matricula_presencial.py` →
`gerar_checkout.py` → `gerar_bio.py` → `gerar_dia_das_criancas.py` → `gerar_404.py` → `gerar_idiomas.py` → `gerar_verificar.py` →
`gerar_doacao_indisponivel.py` → `gerar_doe.py` → `gerar_politicas.py` → `chat_widget.py`, e
`gerar_ingles.py` depois de `gerar_idiomas.py` (notícias e 404 em inglês).
Depois de publicar, `gerar_sitemaps.py` (confere tudo ao vivo).

### Pendências para a filial

- Nomear o encarregado quando houver (trocar o parágrafo "Canal de privacidade" em `politicas.json`).
- Se o limite de agente de pequeno porte deixar de valer (faturamento, tratamento de alto risco), o
  encarregado passa a ser obrigatório (Resolução CD/ANPD nº 18/2024).
- Confirmar com os fornecedores (Google, Meta, Resend, Vercel) as cláusulas-padrão da ANPD.
- Resposta automática em `contato@` ajudaria a confirmar na hora pedidos de reembolso feitos por
  e-mail (pelo chat, a confirmação já é imediata).

## Revisão de SEO e gargalos de alcance orgânico (23/09/2026)

Pedido do Matheus: rever todo o SEO e o que trava o alcance orgânico. Rastreio ao vivo, auditoria
on-page, Lighthouse no celular simulado, e duas frentes paralelas (Semrush; notícias, subdomínios e
escola). **Conclusão: o site estático está tecnicamente limpo; o alcance trava em autoridade, em
arquitetura de conteúdo e nas propriedades que não são deste repositório.**

### O que está bem (e não precisa de trabalho)

- Rastreio a partir da home (`scripts/rastrear_site.py`): zero link interno que redireciona, zero
  4xx/5xx, zero órfã, zero página fora do sitemap, profundidade máxima 3.
- On-page nas 19 páginas do sitemap (`scripts/auditar_seo.py`): títulos e descrições no tamanho,
  canonical, Open Graph, Twitter Card, JSON-LD, imagens com `alt` e dimensões, brotli. Única
  observação: título da Campanha do Agasalho com 62 caracteres (decisão registrada em 20/09).
- Links e recursos internos (`scripts/conferir_links.py`): 87 URLs, todas 200. JSON-LD
  (`scripts/validar_jsonld.py`): 0 erros (os avisos são as referências de propósito a `#organizacao`).
- Lighthouse: **SEO 100 em todas as páginas medidas**. Servidor em São Paulo (IP da Hostinger em
  AS47583), página inexistente responde 404 de verdade.

### Velocidade: o que foi corrigido e publicado

Lighthouse, celular simulado (a nota oscila de uma medição para outra; LCP e CLS são o que conta):

| Página | Antes | Depois | O que era |
| --- | --- | --- | --- |
| Home | 62 · LCP 4,2 s | 73–79 · LCP 2,2–2,4 s | o LCP no celular é o hero (fundo em CSS) e baixava com prioridade normal, enquanto a miniatura do "Impacto das Cores", lá embaixo, tinha `fetchpriority=high` sem lazy |
| `/doe/` | CLS 0,208 | CLS 0,002 | a Inter chegava depois da primeira pintura, o texto quebrava de outro jeito e empurrava o cartão de doação |
| Equipe | 72 · LCP 4,1 s | 75 · LCP 3,3–3,5 s | o celular baixava a foto de 1440 px (129 KB) no topo |
| `/en/` | 83 · LCP 1,9 s | 85 · LCP 1,3 s | Google Fonts bloqueando a renderização nas páginas em inglês e espanhol |

- **Reserva da Inter com as mesmas medidas** (`@font-face` "Inter Reserva" e "Inter Reserva
  Android" no primeiro `<style>` da home, copiado por todos os geradores; `equipe.html` e
  `campanha-agasalho.html` à mão). `size-adjust` e `ascent/descent-override` calculados com
  fontTools sobre o texto do próprio site, para Arial e gêmeas métricas (Arimo, Liberation) e para
  Roboto, regular e negrito. No navegador, com a Inter bloqueada, a altura das páginas ficou igual
  à da Inter carregada (0 a 30 px de diferença, contra 77 a 681 px sem a reserva). **Fonte nova ou
  pesos novos: recalcular** com `python3 scripts/calcular_reserva_fonte.py` (precisa de `fonttools`
  e `brotli`) e conferir no navegador antes de publicar.
- `scripts/aplicar_imagens_otimizadas.py` dava a prioridade alta a **toda** ocorrência da imagem
  principal; agora só à primeira, e as repetições levam lazy.
- Inglês e espanhol: imagem de compartilhamento `og-home.jpg` (1200x630) no lugar do logo de 520 px
  em WebP, que o LinkedIn não exibe e que ficava miúdo no WhatsApp.

O que sobra e é decisão, não defeito: **Pixel da Meta e Google Tag** respondem pela maior parte do
bloqueio da thread principal (≈ 900 ms na home, depois do `load`). Carregar só na primeira
interação tiraria esse custo, mas deixaria de contar quem abre e sai (visualizações da página de
destino dos anúncios). Mantido como está, como em 19/09.

### Gargalos que travam o alcance, por impacto

| # | Gargalo | Evidência | Onde se resolve |
| --- | --- | --- | --- |
| 1 | **Nenhuma página própria por curso** no domínio principal | Os 7 cursos dividem `/matricula-cursos-presenciais/`; as variações `?curso=` se canonicalizam para ela. Quem aparece em "curso de bombeiro civil rio de janeiro" ou "cuidador de idosos rio de janeiro" (escolas de bombeiro, Senac, UERJ, Fiocruz) tem uma URL por curso. O catálogo já tem, por curso, de 130 a 190 palavras e 5 perguntas (menos Lei Lucas, sem perguntas) | Este repositório: `/cursos/<slug>/` gerado de `cursos.json`, com `Course`, FAQ e botão para o checkout (plano de 19/09, item 2) |
| 2 | **A escola não tem SEO básico e é lenta** | `escola.cursoscruzvermelha.org`: sem `robots.txt`, sitemap, canonical, description, Open Graph e JSON-LD; o mesmo HTML em 4 hosts (dois `.onrender.com` e o apelido no domínio principal); títulos sem "Rio de Janeiro"; TTFB de 2 a 5 s estável (processamento, não cold start); `/login` e `/cadastro` indexáveis | App da escola (Render). O kit em `escola/` vale, com três ajustes: description por página (não fixa), `noindex` em `/login` e `/cadastro` em vez de bloquear no robots, e `Course` por curso |
| 3 | **Notícias lentas e com falhas no gerador** | LCP de 15 s (capas PNG de 1,3 a 2,6 MB, sem dimensões nem `srcset`); título da matéria ainda com a assinatura longa (`tituloDaAba()` só foi ligado às páginas fixas, não a `artigo-html.ts`); Markdown dentro de negrito sai cru (`lib/content-blocks.ts`); sem `author`, `publisher` sem `@id`, sem `BreadcrumbList`; data de publicação zerada ao republicar; cabeçalho e rodapé sem `/doe/` e sem a matrícula; índice, termos e privacidade sem `og:image`; alts "aaa" e nome de arquivo. E o banco ainda guarda os links antigos (`/cursos.html` em 8 corpos, `/doacao.html` em 5): republicar uma matéria antiga desfaz as correções feitas no servidor | Repositório da Redação (código e corpos no banco) |
| 4 | **Autoridade e entidade fora do site** | A página da filial no site nacional, no top 10 de "cruz vermelha", não linka `cruzvermelhariodejaneiro.org` e publica um e-mail que não entrega; Perfil da Empresa no Google não conferido; sem item no Wikidata; ícones de LinkedIn e TikTok no rodapé apontam para as home pages genéricas das redes | Matheus e parceiros (pedido à nacional, Perfil da Empresa, Wikidata); rodapé: trocar pelos perfis certos ou tirar os ícones |
| 5 | **Cópias indexáveis e exposição** | `puncao-3312.vercel.app` e `puncao-five.vercel.app` com canonical para si mesmas, `pulcaovenosav0.vercel.app` com canonical errado; em `puncaovenosav1.` sete rotas com canonical da raiz (`alternates` no `app/layout.tsx`) e rotas internas (`/secretaria`, `/minha-inscricao`, `/validar/*`) indexáveis; `redacao.` indexável; **o Cérebro está público, sem login e indexável, em três endereços `.vercel.app`, expondo o radar editorial interno** | Painel da Vercel (redirecionar ou proteger os aliases; proteger o Cérebro) e `puncaovenosa-fullautomatic` (canonical por página, `noindex` nas rotas internas, `robots`/`sitemap`) |
| 6 | **Nada responde perguntas informacionais** | Plano de 20/09: símbolo, direito internacional humanitário, voluntariado e certificado (~2.400 buscas/mês, dificuldade baixa); mais explicações de curso ("o que é a Lei Lucas", "quem pode ser bombeiro civil"), cada uma linkando para a página do curso | Redação (pauta) + páginas por curso (item 1) |
| 7 | **Scripts de terceiros** | Pixel da Meta e Google Tag: ~900 ms de bloqueio na home | Decisão de negócio (ver acima) |

Menores, anotados pelo agente: `projetocores.` sem canonical e com `og:image` relativa (os CTAs vão
para `doar.`, que redireciona); `http://www` chega ao apex em dois saltos; `/noticias/index.html` e
afins respondem 200 (o canonical resolve); sem sitemap do Google News nem RSS; "Leia também" das
matérias congelado na publicação.

### Correções feitas nas páginas da Redação (direto no servidor)

- `/noticias/curso-de-primeiros-socorros-domine-com-poucas-aulas/`: a única matéria sobre os
  cursos mandava o leitor para a **home com `?curso=`** (duas vezes) e exibia **Markdown cru** em
  dois itens em negrito (`[Primeiros Socorros Lei Lucas…](https://…)`). Os cinco links viraram
  âncoras para `/matricula-cursos-presenciais/?curso=<slug>`. A causa está no gerador da Redação
  (ver os gargalos); uma nova publicação dessa matéria desfaz a correção até a fonte ser consertada.

### Dados que não vieram

- **Semrush**: a conta tem assinatura, mas **sem unidades de API** para o acesso por MCP (todas as
  chamadas voltaram `no_api_units`; o Traffic Overview nem está no plano). Sem posições, volumes
  ou backlinks novos. Unidades em https://www.semrush.com/mcp-access.
- **PageSpeed Insights** sem cota no dia (a API pública anônima), então sem dados de campo do
  Chrome (CrUX). As medições acima são do Lighthouse 12 rodado localmente, celular simulado.

## Leitura de tráfego e lacunas de medição (20/09/2026)

Não há conector de Google Analytics, Search Console ou Ads nesta sessão — só Gmail e Drive. A
leitura abaixo vem do rastro de e-mails do Search Console, da lista de consultas que o Matheus
colou (`docs/seo-consultas-2026-09.md`) e de testes feitos direto no site.

**A medição é nova, então quase não há histórico.** Pelos e-mails: a propriedade de domínio foi
verificada por volta de 14–15/09; em 17/09 o Google avisou que "as impressões começaram a ser
coletadas"; em 19/09 o Search Console foi associado à propriedade do Analytics "Cruz Vermelha Rio de
Janeiro" e `contato@` virou proprietário. Ou seja: falar de "últimas semanas" é falar de poucos dias
de dados confiáveis. A lista de consultas some ~66 cliques e ~370 impressões acumulados, o que dá
cerca de dois cliques por dia vindos da busca.

**Avisos de indexação recebidos** (sc-noreply@google.com): em 16/09, "Cópia sem página canônica
selecionada"; em 20/09, "Página alternativa com tag canônica adequada" e **"Não encontrado (404)"**,
com uma validação de correção concluída no mesmo dia. O primeiro e o segundo são esperados
(`www` → apex, `doacao.html` → `/doe/`). O 404 era real: foram encontrados e corrigidos em 20/09.

**404 que viraram 301** (`site/.htaccess`): `sos-venezuela.html` (a campanha que a página de links
antiga do Instagram apontava) → `/doe/`; `install.php` (o instalador que o antigo `/links/` servia)
→ `/bio/`; e as variações sem extensão que gente e robô tentam: `/cursos`, `/matricula`,
`/inscricao`, `/equipe`, `/campanha-agasalho`, `/doacao`, `/doar`, `/voluntario`, `/voluntariado`,
`/contato`.

**Lacunas de medição encontradas**, em ordem de importância:

| Onde | Situação | Por que importa |
| --- | --- | --- |
| `escola.cursoscruzvermelha.org` | **sem GA4** (só Meta Pixel) | é onde a matrícula termina; no Analytics a jornada some quando a pessoa sai do nosso site |
| `puncaovenosav1.` | sem GA4 | landing de curso invisível no Analytics |
| `/noticias/` e notícias individuais | **sem Meta Pixel** (GA4 ok) | não dá para remarketing de quem lê conteúdo |
| entre domínios | sem medição entre domínios configurada | mesmo pondo GA4 na escola, sem isso ela aparece como "referral" do próprio site e a origem real se perde |
| `projetocores.` | GA4 e Pixel ok | — |
| `/termos/`, `/privacidade/` | GA4 ok | — |

## Revisão de SEO de 20/09/2026

Rodada de `python3 scripts/auditar_seo.py` (páginas ao vivo) mais uma varredura de links e atalhos.

- **Sem problemas**: home, `/matricula-cursos-presenciais/`, `/doe/`, `equipe.html`. Títulos dentro de
  60 caracteres, descrições até 160, canonical, Open Graph, Twitter Card, JSON-LD e imagens com
  dimensões.
- **Atalhos consertados**: `/links/` e `/link/` respondiam 302 para `install.php` (404), o instalador
  do "CVB Links" que nunca foi usado; agora respondem **301 para `/bio/`**, e os subdomínios `links.`
  e `link.`, servidos dessas pastas, vão junto. `site/links/.htaccess` e `site/link/.htaccess`.
- **`robots.txt`**: passou a declarar `sitemap-index.xml` além do `sitemap.xml` da Redação (o índice
  já contém os dois). Atenção: quem publica a Redação também escreve esse arquivo; se ele voltar ao
  conteúdo antigo, é só republicar `site/robots.txt`.
- **Links internos**: as 38 URLs internas das páginas principais respondem 200 ou 301; nenhuma
  quebrada.
- **Redirecionamentos ativos**: `/doacao.html` → `/doe/`, `/cursos.html` →
  `/matricula-cursos-presenciais/`, `/escola` → plataforma da escola, `/doe` → `/doe/`, `/bio` →
  `/bio/`, `/links/` e `/link/` → `/bio/`, `doar.` → `/doe/`.
- **Fica em aberto, fora do nosso alcance**: `/noticias/`, `/termos/` e `/privacidade/` (Redação, na
  Vercel) estão sem `og:image` e sem `twitter:card`, e as notícias têm títulos longos; a escola
  (`escola.cursoscruzvermelha.org`, outra conta) está sem descrição, canonical, Open Graph e JSON-LD.
- **Título da Campanha do Agasalho** ficou em 62 caracteres: encurtar exigiria tirar "Campanha do
  Agasalho" (que é o termo buscado) ou abreviar o nome da filial, que a convenção não permite.

## Relatórios do Semrush: busca por IA e palavras-chave (20/09/2026)

Quatro relatórios de auditoria e visibilidade em IA, mais o construtor de estratégia de
palavras-chave. O que saiu de cada um:

### Dados estruturados (56 dos erros da auditoria)

Eram 7 erros repetidos nas 8 URLs da página de matrícula: `Course.url` e `Offer.url` apontavam
para `?curso=<slug>`, que se canonicaliza para a página sem parâmetro. O Semrush lia isso como
oferta sem URL própria. Agora apontam para as âncoras `#curso-<slug>`, que existem na página, e o
`ItemList` declara `numberOfItems`. Os links de navegação continuam usando `?curso=`, que
pré-seleciona o curso no formulário — isso é função, não SEO.

### Visibilidade em IA

Já somos citados em "cursos de primeiros socorros no RJ" e em "cruz vermelha no rio de janeiro".
Os prompts com **zero menção à marca** viraram FAQ (19 → 26 perguntas) e blocos do `llms.txt`:
duração dos cursos, presencial × online, MEC, comparação de preços, curso gratuito de cuidador de
idosos e que formação precisa quem trabalha com crianças.

Um erro em circulação: o Google AI responde "Primeiros Socorros Básicos (4h)". O básico tem **8
horas**; o de 4 horas é o Suporte Básico de Vida. O dado está certo no catálogo, na home e na
página de matrícula — a citação veio da `cursos.html` antiga, hoje 301, e vai envelhecer.

Regra que seguimos: os maiores volumes da lista (enfermagem, necropsia, cuidador infantil,
cartão do idoso) são de cursos que **não oferecemos**. Não se persegue esse volume; o `llms.txt`
diz explicitamente o que não temos, para a IA parar de errar a nosso respeito.

### Palavras-chave institucionais: não aparecemos em nada

O relatório de páginas traz 20 termos, **15.550 buscas/mês**, todos informacionais e quase todos
com AI Overview. `cruzvermelhariodejaneiro.org` **não aparece em nenhum**. Quem ranqueia falando
de nós é a nacional.

| Página proposta | Buscas/mês | Dificuldade |
|---|---|---|
| cruz vermelha (o termo da marca) | 12.900 | KD 52 — São Paulo é o #1 |
| o que é trabalho voluntário | 760 | KD 23 |
| direito internacional humanitário | 670 | KD 14–28 |
| símbolo da cruz vermelha | 490 | KD 16–35 |
| história da cruz vermelha | 280 | KD 28–32 |
| certificado de primeiros socorros | 250 | KD 19–27 |
| voluntária social / portal de voluntários | 200 | KD 36–45 |

O site é todo transacional (cursos, doação) e não responde nenhuma pergunta institucional.
Recorte recomendado: **símbolo, direito internacional humanitário, voluntariado e certificado** —
~2.400 buscas/mês, dificuldade baixa e autoridade legítima nossa. O termo "cruz vermelha" puro
fica de fora: é KD 52 e disputa interna do Movimento.

### O achado mais caro, e não é técnico

`cruzvermelha.org.br/pb/filiais/rio-de-janeiro/` está no **top 10 do Google para "cruz vermelha"**
(12.100 buscas/mês) e é a página mais visível que existe sobre a filial. Ela publica:

- um e-mail de contato que **não entrega mensagem nenhuma**;
- telefones antigos;
- **nenhum link** para `cruzvermelhariodejaneiro.org`.

Pedir à nacional que corrija o contato e inclua o link vale mais que qualquer página nova. A
Wikipédia, essa sim, já linka para a home — é por isso que aparecemos nas respostas de IA sobre a
sede e o endereço.

## Rodada de erros e advertências do Semrush (20/09/2026, noite)

Segunda passada na auditoria, depois que os 56 erros de dados estruturados e os 19 arquivos não
minificados zeraram.

### O que era nosso, e foi resolvido

- **`sitemap.xml` listava `cursos.html` e `doacao.html`**, que respondem 301. Esse arquivo é escrito
  pela **Redação**, por FTP, a cada publicação — corrigi na fonte
  (`lib/site/sitemap.ts` no repositório `redacao-cruzvermelhariodejaneiro`, branch
  `claude/blissful-newton-7jpef4`) e também no arquivo que já estava no servidor, para não esperar a
  próxima matéria. Enquanto aquele PR não for para produção, uma publicação da Redação desfaz a
  correção do servidor. `site/sitemap.xml` entrou no `.gitignore`: a fonte é lá, não aqui.
- **O `robots.txt` da Redação apagava o nosso `sitemap-index.xml`.** Ele é escrito por cima do que
  está no servidor e declarava só o `sitemap.xml`, então cada matéria publicada derrubava a linha do
  índice. Agora `gerarRobots()` declara os dois. Era a causa do "se ele voltar ao conteúdo antigo, é
  só republicar" anotado na seção anterior.
- **32 links internos passavam por 301.** Dois culpados: `/privacidade` sem barra no rodapé de dez
  páginas e `/doacao.html` em três respostas da FAQ. Corrigidos na fonte (a home, o
  `site/faq-home.json` e o `scripts/gerar_doe.py`).
- **`equipe.html` com pouco texto.** Passou de 164 para 666 palavras, com o que faltava e é nosso por
  direito: a relação da filial com o Movimento, os sete princípios fundamentais, o que cada
  coordenação responde, o Palácio da Cruz Vermelha e como entrar na equipe. Os textos das onze
  coordenações foram escritos a partir do nome de cada área — **valem uma revisão de quem conhece
  cada uma**.
- **UTM em link interno.** A campanha do agasalho e a home apontavam para
  `/doe/?utm_source=site&…`. O GA4 lê qualquer `utm_source` como campanha nova: abre outra sessão e
  apaga a origem real, então uma doação vinda do Google orgânico era registrada como "site". Os links
  internos passaram a usar `?de=<pagina>`, que o GA4 ignora; `doe.js` traduz isso para a nossa própria
  tabela, que continua sabendo de onde veio a doação. UTM agora é só para campanha externa.

### Dois scripts novos, para não repetir o erro

| Script | O que faz |
|---|---|
| `scripts/validar_jsonld.py` | Confere o JSON-LD contra o vocabulário oficial do Schema.org: propriedade inexistente, propriedade fora do domínio do tipo, tipo inexistente, `@id` solto. Foi o que achou o `courseMode` em `Course`. |
| `scripts/conferir_links.py` | Testa todo link e recurso interno ao vivo. **Redirecionamento conta como falha**: link interno deve apontar para o destino final. `--local` testa contra um servidor em 127.0.0.1:8767. |

### O que sobrou, e por quê

- **Da Redação** (repositório e deploy separados): 1 erro 4xx e 1 link interno quebrado, ambos o
  `COLE_AQUI_O_LINK_DO_FORMULARIO` publicado em `/noticias/7-de-setembro/`; 19 links externos
  quebrados; 10 títulos longos; e `/termos/` com pouco texto.
- **Correto como está**: os 7 checkouts aparecem como "bloqueados para rastreio" porque são
  `noindex` de propósito; `/sitemap-subdominios.xml` é "sitemap órfão" porque lista subdomínios, que
  nenhuma página do site linka.
- **Texto/HTML abaixo de 10% em `/bio/` e `equipe.html`**: metade do peso dessas páginas é o CSS
  embutido (30 KB), copiado em toda página. A saída real é um CSS externo, servido uma vez e
  guardado em cache — mudança de arquitetura que toca todos os geradores e não entrou nesta rodada.
  `/bio/` é uma página de links: encher de texto para cruzar um limiar seria escrever para o robô.

## Rastreabilidade e Links internos: de 93% e 89% para o limite (20/09/2026, tarde da noite)

A auditoria dá duas pontuações temáticas que não fecham sozinhas com a lista de problemas.
`scripts/rastrear_site.py` (novo) percorre o site ao vivo a partir da home, como um robô de busca,
e reporta o que essas pontuações medem — status, profundidade, links de entrada e de saída,
noindex, canonical, órfãs, âncoras e redirecionamentos.

**Armadilha do ambiente**: este contêiner sai por um proxy, e a primeira linha de cabeçalho é o
aperto de mão dele (`HTTP/1.1 200 Connection Established`). Ler esse bloco fazia todo 301 parecer
200 — o rastreador descarta o bloco do proxy. Quem escrever outra ferramenta que lê cabeçalho
precisa fazer o mesmo.

### O que o rastreio achou, e onde estava

| Achado | Onde nascia |
|---|---|
| 3 links internos que respondiam 301 (`/cursos.html`, `/doacao.html`, `/privacidade`) | lista fixa de links que a IA sugere, em `app/actions/ia.ts` na Redação, e matérias antigas |
| 1 erro 404 | `COLE_AQUI_O_LINK_DO_FORMULARIO`, lugar-comum publicado em `/noticias/7-de-setembro/` |
| 10 títulos acima de 60 | a assinatura ` — Cruz Vermelha Brasileira — Rio de Janeiro` tem 43 caracteres: qualquer manchete acima de 17 estourava |
| `/index.html` alcançável | `equipe.html`, `doacao.html` e `campanha-agasalho.html` linkavam `index.html#secao` em vez de `/#secao` |
| `/bio/` com um único link de entrada | só duas respostas da FAQ apontavam para lá |
| `/doe/?de=agasalho` como endereço próprio | o parâmetro de origem criava uma URL duplicada para rastrear |

### O que foi feito

- **Na Redação** (branch `claude/blissful-newton-7jpef4` daquele repositório): a lista de links da
  IA passou a apontar para `/doe/` e `/matricula-cursos-presenciais/`; `tituloDaAba()` limita o
  `<title>` a 60 caracteres cortando na última pausa, sem terminar em preposição e **sem a forma
  curta "Cruz Vermelha RJ"**, que a convenção de nome não permite em texto visível — quando a
  assinatura não cabe, fica só a manchete; e `atalho-noticias.ts` aceita `/privacidade` e
  `/privacidade/`, porque a home mudou para a forma com barra e o enxerto recusaria por uma barra.
- **Nas páginas já publicadas** (que o conserto na fonte só alcança na próxima publicação): as doze
  matérias, a privacidade e os termos foram corrigidas direto no servidor — só o conteúdo da tag
  `<title>` e três URLs mortas. O 404 do lugar-comum virou texto em negrito, sem link.
- **No site**: `index.html#secao` → `/#secao`; "Links oficiais" para `/bio/` no rodapé; e a origem
  da doação passou a vir do **referenciador interno** em vez de um parâmetro na URL — `doe.js` lê
  `document.referrer`, então nenhum endereço duplicado é criado e a tabela continua registrando de
  onde veio cada doação.

### Estado final do rastreio

Zero em: links internos que redirecionam, 4xx/5xx, páginas órfãs, páginas fora do sitemap, links
sem texto âncora, nofollow interno e profundidade maior que 3. O rastreio caiu de 42 para 35 URLs —
sete endereços duplicados deixaram de existir.

Sobram, e são corretos assim:

- **7 checkouts como "bloqueados para rastreio"**: são `noindex` de propósito. Página de pagamento
  não vai para o índice.
- **4 matérias com um único link de entrada**: são linkadas pelo índice de notícias, que é o normal
  de um site de notícias.
- **19 links externos quebrados**: testei as 32 URLs externas do site com agente de robô e de
  navegador. Só uma falha, e é o **nosso próprio Instagram devolvendo 429** — limite de requisições
  para robôs, não link quebrado. O Facebook responde 200 (a página existe). Dois links sociais do
  rodapé apontam para `linkedin.com` e `tiktok.com` na raiz: respondem 200, mas **são
  lugares-comuns** — ou apontam para os perfis da filial, ou os ícones saem.
- **Título de 62 caracteres na Campanha do Agasalho**: encurtar exigiria tirar "Campanha do
  Agasalho", que é o termo buscado, ou abreviar o nome da filial, que a convenção não permite.
- **Texto/HTML abaixo de 10% em `/bio/`, `equipe.html` e `/termos/`**: metade do peso dessas
  páginas é o CSS embutido (30 KB), repetido em cada uma das doze. A saída é um CSS externo, baixado
  uma vez e guardado em cache — vale para quem navega, não só para a métrica, e é mudança de
  arquitetura que toca todos os geradores e a home, que é a fonte do CSS. Não entrou nesta rodada.

## Referência da Wikipédia (20/09/2026)

O verbete **[Cruz Vermelha Brasileira - Rio de Janeiro](https://pt.wikipedia.org/wiki/Cruz_Vermelha_Brasileira_-_Rio_de_Janeiro)**
(pt.wikipedia.org) entrou no site como referência da filial, a pedido do Matheus:

- `sameAs` do JSON-LD `NGO` da home (`site/index.html`), ao lado do Instagram e do Facebook: é o
  sinal que liga a entidade do site ao verbete para o Google.
- Parágrafo na seção Institucional da home ("utilidade pública municipal, Lei 5.153/2010, e estadual,
  Lei 9.984/2023", fatos do verbete) com o link para o verbete.
- Link "Verbete na Wikipédia" no rodapé de todas as páginas (o rodapé da home é copiado pelos
  geradores; `equipe.html`, `doacao.html` e `campanha-agasalho.html` têm rodapé próprio e foram
  editadas à mão).
- Pergunta "O que é a Cruz Vermelha Brasileira Rio de Janeiro?" abrindo o grupo "Instituição, doações,
  sede e contato" da FAQ, com o link; `scripts/gerar_faq_home.py` passou a aceitar essa URL em
  `PERMITIDOS`.

O verbete ainda não tem item no Wikidata; quando tiver, vale acrescentar o `Q…` ao `sameAs`.

## Dia das Crianças na Praça (`/dia-das-criancas/`, 05/10/2026)

Página da ação de **terça-feira, 13/10/2026, à tarde, das 13h às 16h**, na praça em frente ao Palácio da Cruz
Vermelha, aberta e gratuita (sem inscrição), com os setores do quadro da sede para o dia: **Juventude,
Primeiros Socorros e Educação e Saúde**. Gerada por `scripts/gerar_dia_das_criancas.py`, com o cabeçalho,
o rodapé, o CSS, o GA4 (grupo de conteúdo `eventos`), o Pixel e o chat da home.

- **Fonte única do evento:** o dicionário `EVENTO` do gerador alimenta a página, o JSON-LD `Event` (o que
  o Google usa para mostrar o evento na busca: data, local, gratuito), o link do Google Agenda e o
  `dia-das-criancas.ics` (iPhone e Outlook: horários em UTC, linhas dobradas em 75 bytes, CRLF). Mudou a
  data, o horário ou os setores: editar `EVENTO`/`SETORES` e regerar; o card da home é editado à mão.
- **Card na home**, o primeiro de "Campanhas ativas" (`#campanhas`), com a foto da Juventude (recorte com
  `object-position: 50% 70%`, para os rostos caberem no 16:9).
- **Cartão na bio do Instagram** (`/bio/`), o primeiro da lista. A foto do cartão fica em `/assets/otim/`
  (`dia-criancas-cartao-700/420.webp`, de `CARTOES_BIO` em `otimizar_imagens.py`), não em `site/bio/img/`,
  que vai para o Git: o bloco usa a chave `"pasta"` de `gerar_bio.py`.
- **Fotos:** cinco fotos de ações anteriores com crianças, com autorização de uso de imagem dos
  responsáveis (confirmada pelo Matheus em 05/10/2026). Ficam fora do Git, como o resto de
  `site/assets/`, e sem EXIF/GPS; as versões otimizadas saem de `scripts/otimizar_imagens.py` (a do topo
  também em AVIF, com preload, de 480 a 1080 px).
- **No celular,** a foto com o selo "13/10 · 13h às 16h" vem logo depois do título (o topo é uma grade só;
  no computador a foto ocupa a coluna da direita) e os quatro fatos viram uma lista com divisórias.
- **Doação de brinquedos (05/10, à noite):** aviso no topo ("Saiba como doar"), seção "Doe brinquedos para
  a criançada" (o que doar e onde entregar: na sede, de segunda a sexta, das 10h às 17h, o horário de
  recebimento da Campanha do Agasalho) e linha "Doações" nas informações. Doação em dinheiro segue suspensa.
- **Formulário de doação maior ou específica:** nome, e-mail, telefone (opcional), empresa ou grupo
  (opcional) e o que quer doar. Vai para `api/contato.php` com o assunto `brinquedos` ("Doação de brinquedos
  (Dia das Crianças)"), o mesmo atendimento do chat: grava em `mcp_contatos`, aparece no painel da
  secretaria, manda o aviso à equipe e a confirmação com protocolo. A empresa entra no começo da mensagem.
  Os e-mails dizem que veio do formulário, não do chat (`mcp_contato_canal`). Sem campo novo no banco.
- **No fim da página**, a chamada para as **notícias** (`/noticias/`) no lugar do cadastro de voluntário
  (inscrições fechadas em 05/10/2026).
- **Medição completa** (detalhe em `docs/rastreamento.md`): GA4 com o grupo `eventos`, `dia_criancas_click`
  (acao), `dia_criancas_secao` (cada seção vista uma vez), `share` (WhatsApp e copiar o link),
  `form_start` e `generate_lead`; Pixel com `ViewContent` ao abrir, `Schedule` ao salvar na agenda (uma vez
  por visita), `Contact` no envio do formulário e `DiaCriancasClick` (acao); API de Conversões com os mesmos
  ids: ViewContent e Schedule pelo `medicao.php` (chave `conteudo` = `dia-das-criancas-2026`, aceita só se
  estiver em `MCP_MEDICAO_CONTEUDOS`) e Contact pelo `contato.php`. Tudo com o consentimento do aviso de
  cookies; o formulário funciona igual para quem recusa. O link compartilhado leva `utm_source=whatsapp` ou
  `utm_source=link`, com `utm_campaign=dia-das-criancas`.
- **Testes:** `scripts/testar_meta_integracao.php` cobre o repasse da página (ViewContent e Schedule; sem a
  chave, com chave desconhecida ou com "não", nada) e o formulário (assunto, Contact e os dois e-mails).
- **Contraste:** os textos de apoio usam `#5b6576` (5,9:1 no branco) no lugar do `--muted` da home
  (`#718096`, 4,0:1, abaixo do AA), e o verde do botão do WhatsApp é `#0e7266` (5,8:1). O que o
  Lighthouse ainda aponta é o rodapé da home, igual em todas as páginas.
- **Lighthouse (local, 05/10):** celular 98 (LCP 2,2 s, CLS 0), computador 100; acessibilidade 96.
- **`.ics` como `text/calendar`** (`site/.htaccess`), para o iPhone abrir "Adicionar à agenda".
- **Publicar:** `scripts/publicar_hostinger.sh $(grep -vE '^(#|$)' scripts/publicacao-dia-das-criancas.txt)`
  (com `--copiar-do-ar` antes), limpar o cache e, com a página no ar, rodar `gerar_sitemaps.py` (a página
  já está em `PAGINAS`). A atualização das doações e da medição está em
  `scripts/publicacao-dia-das-criancas-doacoes.txt` (o PHP antes da página).
- **Publicado em 05/10/2026:** a primeira versão por volta das 17h35 (fotos, página, `.ics`, `.htaccess`,
  home e bio) e a das doações e da medição por volta das 18h05 (`lib/email.php`, `medicao.php`, página,
  home e bio), com cópia do que estava no ar antes de cada envio e o cache limpo depois. Na segunda, o
  `lib/email.php` no ar já tinha o certificado de amostra que outra sessão publicou da `main`; a branch foi
  rebaseada sobre a `main` e foi publicado o arquivo da `main` com as mudanças daqui (conferido linha a
  linha contra o que estava no ar). Conferido em seguida: os arquivos no ar iguais ao repositório, o PHP
  respondendo, a página sem erro de JavaScript e sem medição antes do aceite dos cookies. Sitemaps
  regerados e publicados às 18h07, com a página nova (53 entradas em `sitemap-paginas.xml`).
- **Horário mudou (05/10, à noite):** das 9h às 16h para a parte da tarde, **das 13h às 16h**. Mudou em `EVENTO` (página, JSON-LD, Google Agenda, `.ics` com `SEQUENCE:1`, WhatsApp), no card da home, no cartão da bio e no `llms.txt`.
- **Observação:** o `chat.js` no ar ainda é o anterior; a `main` tem uma versão nova (sem algumas perguntas
  das fichas dos cursos), e a página de matrícula publicada já aponta para ela pelo `?v=`. O número só serve
  para o navegador não usar cópia velha, então nada quebra, mas o arquivo novo não foi enviado.
- **Depois do evento (a partir de 14/10):** tirar o card da home e o cartão da bio, e trocar a página por um "como foi" (com
  fotos do dia autorizadas) ou tirá-la do sitemap. O Google para de mostrar evento que já passou, mas a
  página continuaria convidando para uma data vencida.

## Slider de campanhas da home (02/10/2026)

O topo da home é um slider (`#campaignSlider`): troca sozinho a cada 6 s, e a altura dele é a do banner
visível. Regras:

- **Todo banner em 1920x901.** Um banner com outra proporção muda a altura a cada troca automática, e a
  página inteira pula (CLS). O CSS (`aspect-ratio: 1920 / 901` com `object-fit: cover`) segura a altura
  mesmo assim, mas cortaria as bordas da arte: ajuste a arte antes.
- **Só o primeiro slide entra na carga da página:** preload no `<head>` com `fetchpriority="high"` (no
  computador ele é o maior elemento da página, o LCP). Os outros têm `loading="lazy"`. 1,5 s antes da
  troca, o script baixa a imagem do próximo slide, mas só com a página carregada, a aba visível, o slider
  na tela e sem a economia de dados ligada: quem rolou para baixo ou saiu não paga por ela.
- **O CSS do slider fica no segundo `<style>` da home.** O primeiro é copiado pelos geradores para todas as
  páginas, e só a home tem o slider.
- Pontos e setas ficam sobre um fundo escuro translúcido, porque os brancos puros sumiam num banner de
  fundo claro.

**Banner da Força-Tarefa Humanitária – Ações El Niño** (primeiro slide desde 02/10/2026). Só
informativo, sem link, a pedido do Matheus: o slide leva a classe `sem-link` (cursor normal, sem a mão de
link do slider), e o `alt` traz o texto da arte.

- A arte veio em 1916x821 (`site/assets/forca-tarefa-el-nino-arte.webp`, no servidor).
  `forca-tarefa-el-nino-banner.png` é a mesma arte com 1920 de largura e 78 linhas brancas a mais na
  faixa branca de baixo: 42 acima e 36 abaixo da linha do rodapé, que continua centralizada. Nada da arte
  foi cortado.
- `scripts/otimizar_imagens.py` gera 640, 960, 1200, 1440 e 1920 px em AVIF (qualidade 60) e em WebP
  (78): de 23 a 95 KB em AVIF e de 32 a 122 KB em WebP. O `<picture>` serve o AVIF a quem aceita, e o
  preload é `type="image/avif"` (quem não aceita ignora o preload e pega o WebP do próprio `<img>`).
- Lighthouse, mediana de 3, servidor local com as fotos do ar, home de antes e de depois:

  | | Nota | LCP | CLS | Página |
  | --- | --- | --- | --- | --- |
  | Celular, antes → depois | 81 → 88 | 3,31 → 3,00 s | 0,001 → 0,001 | 493 → 420 KB |
  | Computador, antes → depois | 98 → 98 | 1,10 → 1,03 s | 0 → 0 | 717 → 568 KB |

  O banner baixado na carga caiu de 115 para 39 KB no celular e de 218 para 66 KB no computador.
- **Trocar o primeiro slide:** arte em 1920x901 em `site/assets/`, entrada em `FOTOS` (e em `AVIF`) de
  `scripts/otimizar_imagens.py`, rodar o script e, na home, o `<picture>` do slide e o preload do `<head>`
  com os mesmos `srcset` e `sizes` (se diferirem, o navegador baixa duas vezes). O slide que sai da frente
  passa a ter `loading="lazy"` no lugar de `fetchpriority="high"`.
- Publicação: `scripts/publicacao-banner-el-nino.txt` (as imagens antes da página que as pede).
- **No ar desde 02/10/2026, 17h51 (Brasília)**, depois de copiar a home do ar (igual à última publicação).
  O LiteSpeed da Hostinger mandava `.avif` como `text/plain`: o `.htaccess` da raiz ganhou
  `AddType image/avif .avif` (publicado com cópia do anterior; home, páginas, redirecionamentos, 404 e
  HSTS conferidos logo depois). Conferido no ar: AVIF escolhido na largura certa, só o primeiro slide na
  carga, próximo slide pronto na troca, alturas iguais, CLS ≤ 0,0002, sem erro de JS, e
  `conferir_pixel.js` nos 12 cenários.

## FAQ da home (19/09/2026, à noite)

A FAQ da home tinha cinco respostas de uma frase. Agora é uma seção de conteúdo pensada para busca
orgânica: **20 perguntas em 4 grupos** (Cursos e matrícula; Primeiros socorros, Lei Lucas e formação
profissional; Voluntariado; Instituição, doações, sede e contato), cada resposta com 60 a 110 palavras, o nome
completo da filial, fatos tirados do repositório (`cursos.json`, página de matrícula, doação, Campanha
do Agasalho, bio) e links para páginas do próprio domínio. As perguntas saíram das consultas do Search
Console (`docs/seo-consultas-2026-09.md`): "cursos gratuitos", hospital/emergência, endereço e
horário, telefone/WhatsApp, cursos técnicos/enfermagem (não há), Nova Iguaçu/Cabo Frio, Lei Lucas,
bombeiro civil, cuidador de idosos, BLS.

- **Fonte**: `site/faq-home.json` (`titulo`, `subtitulo`, `grupos[].perguntas[]` com `pergunta`,
  `resposta` e `links[{texto,url}]`). Para mudar uma resposta, edite o JSON, rode
  `python3 scripts/gerar_faq_home.py` (reescreve o trecho entre `<!-- faq:inicio -->` e
  `<!-- faq:fim -->` de `site/index.html` e o bloco `FAQPage`, `<script id="faq-ld">`) e publique
  `site/index.html`.
- **Regras do gerador**: recusa "Cruz Vermelha Brasileira" sem "Rio de Janeiro"; só aceita links
  internos, da escola ou do formulário do voluntariado; o texto do link precisa estar literalmente na
  resposta; avisa se a resposta sai de 40 a 120 palavras. A primeira pergunta abre por padrão. Os
  links "chat do site" apontam para `/#chat`, que abre o chat em qualquer página.
- **O que a FAQ afirma e convém a filial confirmar** (o que não tinha fonte ficou de fora ou foi
  suavizado): a homologação do Bombeiro Civil é paga à parte, valor a consultar; a chave PIX do CNPJ
  **foi desativada em 20/09/2026** e saiu da Campanha do Agasalho (caixa "Pix CNPJ", cartão "Pix
  direto", faixa de informações e o script que copiava a chave); quem quer doar por PIX passa por
  `/doe/`, que gera o código na hora, e o CNPJ segue no site só como identificação da filial; o horário "segunda a sexta, 10h às 17h" é o da entrega de donativos da campanha,
  não um horário geral da sede; não afirmamos que a formação de voluntários é gratuita, só que
  voluntariado e cursos são caminhos separados; a resposta sobre emergências não diz se a filial tem
  ou não hospital, só que este site não agenda consultas e que emergência é 192/193; o resumo da Lei
  Lucas (Lei 13.722/2018) é conhecimento geral, não do repositório. Se a filial quiser afirmar mais
  (curso gratuito, boleto, atendimento de saúde), basta editar o JSON e gerar de novo.

## Página de links da bio do Instagram (`/bio/`, 19/09/2026)

A bio do Instagram apontava para `smartpa.ge/rWPY`, uma página de links fora do domínio. Agora
ela mora em **https://cruzvermelhariodejaneiro.org/bio/**, gerada por `scripts/gerar_bio.py` no
padrão da home (cabeçalho, rodapé, CSS, GA4, Meta Pixel e chat), para o tráfego do Instagram entrar
no domínio e ser medido.

- **Conteúdo**: avatar e chamada da página original ("Maior rede de ajuda humanitária do 🌎 / Doe
  e nos ajude a salvar vidas!") e, por decisão do Matheus, **só três destinos, com os links
  exatamente como estavam**: cursos na plataforma da escola
  (`https://escola.cruzvermelhariodejaneiro.org`), formulário do voluntariado
  (`https://form.spotform.com.br/voluntariocruzvermelharj`) e o WhatsApp do voluntariado
  (`api.whatsapp.com/send?phone=+5521970360264…`, número do voluntariado, não o da secretaria).
  Saíram: desfile de 7 de Setembro (evento passado), SOS Venezuela (campanha encerrada; o link
  original apontava para `/sos-venezuela.html`, que não existe), e-mail do RFL, endereço e bloco do
  Instagram. Os blocos ficam na lista `BLOCOS` do gerador; para mudar, edite e gere de novo.
- **Imagens** em `site/bio/img/` (versionadas): avatar 256/512, cartões de cursos e voluntário em
  700 e 420 px (WebP das artes originais) e `og-bio.jpg` 1200x630 para compartilhamento.
- **SEO**: título e descrição próprios, canonical `/bio/`, `index, follow`, Open Graph, JSON-LD
  (`WebPage` ligada à `WebSite` e à `Organization` da home, `BreadcrumbList`), entrada no
  `sitemap-paginas.xml`.
- **Revisão de 19/09 à noite**: título "Cruz Vermelha Brasileira Rio de Janeiro | Links oficiais",
  descrição com 145 caracteres e nome completo em todo o texto; seta dos botões como ícone SVG,
  `alt=""` nas artes dos cartões (o texto do link já descreve), cinza dos textos secundários com
  contraste AA, preload do avatar com `imagesrcset`; nesta página o `gtag.js` carrega imediato (para
  não perder o clique de quem entra e sai em segundos) e o Pixel continua depois do `load`.
- **Rastreio**: cada clique dispara GA4 `bio_click` (`link_id`, `link_url` com host e caminho até 100
  caracteres, `link_text`) e Meta
  `BioClick`. Endereço para colar na bio:
  `https://cruzvermelhariodejaneiro.org/bio/?utm_source=ig&utm_medium=social&utm_content=link_in_bio`.
- **Aviso**: `/links/` e `links.cruzvermelhariodejaneiro.org` apontam para a pasta
  `public_html/links/`, um projeto "CVB Links" em PHP que nunca foi instalado (responde com o
  instalador). Não foi tocado; se quiser, `/links/` pode virar um redirecionamento para `/bio/`.

## Revisão de SEO (19/09/2026)

Relatório completo em `docs/seo-revisao-2026-09.md` (antes/depois, pendências por projeto,
Search Console, Perfil da Empresa no Google, conteúdo). Resumo do que mudou aqui:

- **Imagens**: `scripts/otimizar_imagens.py` gera versões WebP em larguras fixas e as imagens de
  compartilhamento (1200x630) em `site/assets/otim/`; `scripts/aplicar_imagens_otimizadas.py`
  reescreve as tags `<img>` das páginas à mão com `srcset`, `sizes` (medidos ao vivo), `width`/`height`,
  `loading="lazy"` fora da primeira dobra e `fetchpriority="high"` na principal. Home: 11,7 MB → 2,5 MB.
  Os originais continuam no servidor; `site/assets/otim/` precisa ser publicada junto.
- **Cabeçalho das páginas**: canonical, Open Graph, Twitter Card, títulos até 60 e descrições até
  160 caracteres, JSON-LD (`WebSite`, `WebPage`, `BreadcrumbList`, `DonateAction`) e H1 limpo na matrícula.
- **`site/.htaccess` (raiz do public_html)**: `www` → apex em 301 e `ErrorDocument 404 /404.html`
  (`scripts/gerar_404.py` gera a página com o cabeçalho e o rodapé da home).
- **`scripts/auditar_seo.py`**: auditoria on-page de qualquer lista de URLs ao vivo.
- **Velocidade** (Lighthouse celular: home 50→68, matrícula 39→81, doação 55→79, equipe 54→75,
  agasalho 59→78): GA4 e Pixel carregam depois do `load` (filas preservam os eventos), Font Awesome
  substituído por SVG inline (`scripts/icones.py` + `scripts/icones.json`; os geradores chamam
  `icones.converter()` e as páginas à mão passam por `python3 scripts/icones.py site/index.html
  site/equipe.html`), Google Fonts sem bloquear, QR code com `defer`, preload da imagem principal,
  cache de 30 dias em `assets/otim/` e `matricula-cursos-presenciais/img/`. Ícone novo: acrescentar
  o desenho em `scripts/icones.json` (viewBox e path do SVG) e usar `<i class="fa-solid fa-nome"></i>`.

## Rastreamento (19/09/2026)

Verificado ao vivo e documentado em `docs/rastreamento.md`: cobertura de GA4 e Pixel por página,
funil da matrícula com eventos padrão de comércio (`view_item`, `select_item`, `begin_checkout`,
`generate_lead`, `add_payment_info`, `purchase` com `transaction_id`; no Meta `ViewContent`,
`InitiateCheckout`, `Lead`, `AddPaymentInfo`, `Purchase` com `eventID`), Pixel acrescentado na
doação, no agasalho e na 404, linker do GA4 para o domínio da escola, e a lista do que depende da
escola, da Vercel e da Redação. O segundo ID do GA4 (`G-Z5NWV4RBTT`) vem da configuração da Google
tag, não do código.

## Pontos de atenção encontrados

- **Checkout, pendências para fechar**: (1) aviso de inscrição paga à secretaria **desligado**
  (`EMAIL_SECRETARIA` vazio, decisão de 18/09): a caixa `contato@cruzvermelhariodejaneiro.org` não
  existe (teste pelo Resend voltou com "554 5.7.1 Relay access denied" do MX da Hostinger; o
  contato hoje é um Gmail) e o site ainda a exibe no rodapé e na seção de contato. Quando a caixa
  do domínio existir, preencher `EMAIL_SECRETARIA` e testar; até lá, a secretaria acompanha pela
  tabela `mcp_inscricoes` (status `pago`) ou pelo painel da Unicopag; (2) acesso à escola (versão A)
  depende da API da escola (`ESCOLA_API_URL`), que ainda não existe; (3) as transações de teste
  (R$ 1 e R$ 99, "Teste Integracao", não pagas) aparecem no painel da Unicopag até expirarem.
  Resend configurado em 18/09 com remetente `matricula@info.cruzvermelhariodejaneiro.org`.
- ~~Menu mobile sem links na home e em `equipe.html`~~: corrigido em 18/09/2026 (regra
  `.main-header .header-collapse .nav-links { display: flex !important; }` dentro do
  `@media (max-width: 920px)` das duas páginas; a matrícula herda pelo CSS copiado da home).
- **Subdomínios (visto ao montar o sitemap, 19/09)**: em `puncaovenosav1.` as páginas
  `/inscricao`, `/politica-de-privacidade` e `/politica-de-reembolso` declaram o canonical da raiz
  (o Google as trata como cópias da home; ficaram fora do sitemap). Corrigir no projeto
  `puncaovenosa-fullautomatic` com canonical por página. `redacao.` é ferramenta interna e está
  indexável (sem `noindex`): acrescentar `noindex` ou `robots.txt` no projeto da Redação. `doar.`
  não declara canonical.
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
site/                                   páginas estáticas mantidas à mão (espelho do public_html; .htaccess e 404.html incluídos)
  assets/otim/                          imagens otimizadas geradas (fora do Git, como o resto de assets/; publicar junto)
  matricula-cursos-presenciais/         página gerada (index.html), cursos.json e img/*.webp
  dia-das-criancas/                     página gerada da ação de 13/10/2026 (index.html) e o evento para a agenda (.ics)
  sitemap-index.xml                     índice de sitemaps (o endereço a enviar no Search Console)
  sitemap-paginas.xml                   páginas fixas com data real e imagens (gerado)
  sitemap-noticias.xml                  notícias da Redação com data e imagens (gerado)
  sitemap-subdominios.xml               landing pages doar., projetocores. e puncaovenosav1. (gerado)
  sitemap-escola.xml                    cópia do sitemap da escola (outro domínio; enviar à parte)
escola/                                 kit de SEO para o app da escola (robots, sitemap, canonical, JSON-LD)
docs/briefing-matricula-cursos-presenciais.md   definição do produto (fonte da verdade)
docs/plano-matricula-express.md         plano de implementação do backend (checkout, secretaria)
scripts/sincronizar_catalogo.py         cursos.json a partir do catálogo público da escola
scripts/gerar_imagens_matricula.py      fotos dos cursos (img/*.webp, 4:3) a partir das imagens da escola
scripts/gerar_matricula_presencial.py   gera a página a partir de cursos.json e da home
scripts/gerar_checkout.py               gera checkout/, pendente/ e parabens/ (mesmo cabeçalho e rodapé)
scripts/testar_checkout.php             testes das funções puras do backend (php scripts/testar_checkout.php)
site/matricula-cursos-presenciais/api/  backend PHP do checkout: endpoints, lib.php (bootstrap) e lib/ (módulos)
site/matricula-cursos-presenciais/static/  checkout.css e checkout.js das três telas do checkout
scripts/gerar_sitemap_escola.py         regenera os sitemaps da escola a partir do catálogo público
scripts/gerar_sitemaps.py               gera sitemap-index, -paginas, -noticias e -subdominios conferindo tudo ao vivo
scripts/auditar_seo.py                  auditoria de SEO on-page das páginas ao vivo
scripts/otimizar_imagens.py             versões WebP e imagens de compartilhamento em site/assets/otim/
scripts/aplicar_imagens_otimizadas.py   reescreve as <img> das páginas à mão com srcset, sizes, dimensões e lazy
scripts/calcular_reserva_fonte.py      medidas da "Inter Reserva" (fonte do aparelho do tamanho da Inter, sem CLS)
scripts/gerar_404.py                    gera site/404.html com o cabeçalho e o rodapé da home
scripts/gerar_dia_das_criancas.py       gera site/dia-das-criancas/ (página, JSON-LD Event e .ics) com o cabeçalho e o rodapé da home
scripts/gerar_verificar.py              gera site/verificar/ (verificação de documentos, escondida: noindex, nada de terceiros)
site/verificar/                         página de verificação e 404 próprio (gerados) e .htaccess (X-Robots-Tag da pasta)
scripts/icones.py + icones.json         ícones em SVG inline no lugar do Font Awesome (sprite por página)
docs/rastreamento.md                    cobertura de GA4 e Pixel por página e eventos do funil da matrícula
docs/seo-revisao-2026-09.md             relatório da revisão de SEO e velocidade (antes/depois e pendências por projeto)
scripts/publicar_hostinger.sh           envia arquivos de site/ para a Hostinger (TUS); --copiar-do-ar guarda o que está no ar, --apagar desfaz os novos
scripts/publicacao-comunicacao.txt      os 41 arquivos da publicação dos lembretes e comunicados, na ordem de envio
scripts/publicacao-dia-das-criancas.txt os arquivos da página do Dia das Crianças (fotos, .htaccess, página, .ics, home), na ordem de envio
scripts/conferir_publicacao.sh          confere uma publicação no ar (estáticos, API, bloqueios, localização do ponto)
scripts/desfazer_publicacao.sh          desfaz uma publicação: volta à versão do commit anterior (Git), na ordem inversa
```
