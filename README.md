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

1. Redação: aplicar a migração `20260925233100_cvrj_acervo.sql`, pôr `R2_BUCKET_ACERVO=cvrj-acervo`
   (e as outras `R2_*`) na Vercel e publicar.
2. Aqui: `python3 scripts/gerar_og_acervo.py` e publicar `/en/archive/`, `/es/acervo/`, as páginas
   com o rodapé novo, `robots.txt`, `llms.txt` e `site/assets/otim/og-acervo.jpg`. A apresentação
   `/acervo/` e o `.htaccess` dela saem do código da Redação: publicados junto na primeira vez, e a
   Redação passa a regravá-los.
3. Conferir: `/acervo/` 200, `/acervo/equipe/` 302 para a Redação, `/acervo/nao-existe/` 404.
4. `python3 scripts/gerar_sitemaps.py`, publicar os sitemaps e limpar o cache.
5. Search Console: inspecionar `/acervo/` e pedir a indexação.

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
scripts/gerar_verificar.py              gera site/verificar/ (verificação de documentos, escondida: noindex, nada de terceiros)
site/verificar/                         página de verificação e 404 próprio (gerados) e .htaccess (X-Robots-Tag da pasta)
scripts/icones.py + icones.json         ícones em SVG inline no lugar do Font Awesome (sprite por página)
docs/rastreamento.md                    cobertura de GA4 e Pixel por página e eventos do funil da matrícula
docs/seo-revisao-2026-09.md             relatório da revisão de SEO e velocidade (antes/depois e pendências por projeto)
scripts/publicar_hostinger.sh           envia arquivos de site/ para a Hostinger (TUS)
```
