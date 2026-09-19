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
as três telas do checkout (`noindex`) e a API.

- **Escola**: fica em outro domínio (`cursoscruzvermelha.org`), então não pode entrar no índice
  até a escola estar verificada na **mesma conta** do Search Console. Não tinha `robots.txt` nem
  `sitemap.xml` (ambos 404 ainda em 19/09) nem `canonical`, e o mesmo conteúdo aparece em três
  hosts. O kit em `escola/` resolve isso; leia `escola/README.md`. Enquanto o kit não for para o
  Render, existe a cópia `https://cruzvermelhariodejaneiro.org/sitemap-escola.xml` (10 URLs, fonte
  em `site/sitemap-escola.xml`), para enviar à parte depois da verificação. Regenerar quando
  entrarem cursos novos: `python3 scripts/gerar_sitemap_escola.py`.
- **`robots.txt`** do domínio é gerado pela Redação e só aponta para `sitemap.xml`. Para o índice
  aparecer nele, acrescentar `Sitemap: https://cruzvermelhariodejaneiro.org/sitemap-index.xml` no
  projeto da Redação (não editar aqui: seria sobrescrito). O Search Console não depende disso.

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
- **Dados mínimos**: nome, CPF, e-mail e WhatsApp. O CPF é obrigatório porque a Unicopag exige
  `customer.document` (testado: sem ele a API responde 422).
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
- O botão flutuante do WhatsApp (`.wpp-float`) saiu da home e de `equipe.html`: a intenção é
  substituir por um Intercom para toda a estrutura. O número continua no rodapé e nos cartões de
  curso da home ("Chamar no WhatsApp").
- `site/assets/` não é versionada (fica só no servidor); há uma cópia local ignorada pelo Git
  só para renderizar a home em testes.

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
scripts/gerar_404.py                    gera site/404.html com o cabeçalho e o rodapé da home
docs/seo-revisao-2026-09.md             relatório da revisão de SEO (antes/depois e pendências por projeto)
docs/rastreamento.md                    cobertura de GA4 e Pixel por página e eventos do funil da matrícula
scripts/publicar_hostinger.sh           envia arquivos de site/ para a Hostinger (TUS)
```
