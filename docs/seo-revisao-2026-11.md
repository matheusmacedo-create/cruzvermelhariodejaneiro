# Revisão de SEO do ecossistema (16/11/2026)

Segunda revisão, dois meses depois da de [19/09/2026](seo-revisao-2026-09.md). Pedido do Matheus: rever
tudo e corrigir o que estiver errado. Método: `scripts/auditar_seo.py` nas 189 URLs dos sitemaps, um
rastreamento próprio (cada link, imagem e recurso das 189 páginas: 1.480 endereços conferidos), o Search
Console (28 dias, 17/10 a 13/11) e o PageSpeed.

**Estado: publicado em 15/11/2026, às 21h40** (49 páginas, conferidas no ar byte a byte; detalhes e pendências
em `scripts/publicacao-seo-novembro.txt`). Na Redação, as correções estão no PR #410 e só valem no site depois
do merge e de "Regerar as páginas das notícias".

## 1. Como o site está

| Item | Resultado |
| --- | --- |
| Páginas dos sitemaps | 189, todas respondem 200, todas com canonical para si mesmas, nenhuma com `noindex` |
| Títulos e descrições repetidos | nenhum |
| Página sem H1 ou com mais de um | nenhuma |
| `http://`, `www.`, `/index.html`, `/noticias` sem barra | todos respondem 301 para o endereço oficial |
| Sitemap no Search Console | `sitemap-index.xml` lido em 15/11, 0 erro e 0 aviso (196 páginas, 732 imagens) |
| Home no celular (PageSpeed) | desempenho 91, SEO 100, LCP 2,7 s, CLS 0 (era 68 e 3,9 s em setembro) |
| Visitantes reais no celular (28 dias) | LCP 1,3 s, INP 145 ms, CLS 0: os três na faixa "bom" |
| Busca orgânica em 28 dias | matrícula 604 cliques (posição 5,7), home 481 (posição 4,1) |
| Redação (`redacao.` e `palacio.`) | fora da busca, como deve: `robots.txt` com `Disallow: /` e `X-Robots-Tag: noindex` |

Do checklist de setembro, estava tudo feito no domínio principal (robots com o índice, `cursos.html` fora do
mapa, Open Graph em notícias e políticas, Redação fora da busca). A escola continua sendo o ponto fraco
(seção 3).

## 2. O que estava errado e foi corrigido

### Neste repositório

| Problema | Onde | Correção |
| --- | --- | --- |
| **Dez guias sem nenhum link interno**: só o sitemap os citava (`/febre-em-crianca/`, `/queimaduras/`, `/rcp-e-manobra-de-heimlich/`, `/intoxicacao-alimentar/`, `/pressao-alta-e-pressao-baixa/`, `/onde-buscar-ajuda/`, `/guia-do-bombeiro-civil/`, `/guia-dos-cursos/`, `/cartaz-da-cruz-vermelha/`, `/manual-de-identidade/`). Página órfã o Google rastreia pouco e classifica mal | `gerar_institucional.py` | Cada guia ganhou, no fim, o bloco "Mais guias gratuitos" com os outros 20 (lista `GUIAS`). Conferido em 390 px: botões de 44 px, sem rolagem lateral |
| Título acima de 60 caracteres | 12 páginas institucionais, 2 de política (EN e ES), `/en/history/`, DIH e CICV em inglês | Encurtados sem perder a busca que cada página mira |
| Descrição acima de 160 caracteres | 10 páginas institucionais, `/en/history/`, DIH e "who founded" em inglês (242 e 208) | Reescritas; nas duas em inglês a descrição da busca virou campo próprio (`descricao_seo`), e a linha fina da página não mudou |
| hreflang só de um lado: as versões em inglês e em espanhol do DIH apontavam para `/direito-internacional-humanitario/`, que não apontava de volta (o Google ignora o par) | `gerar_institucional.py` | Mapa `TRADUCOES`: a página em português declara pt-BR, en, es e x-default |
| `dateModified` anterior a `datePublished` nas notícias em inglês publicadas depois de 26/09 | `gerar_ingles.py` | A modificação nunca fica antes da publicação |
| Dois links para o Wikimedia Commons cortados no parêntese (404) | `traducoes/en/dih.md` | Parênteses codificados (`%28`, `%29`) |
| Link do SAMU 192 no gov.br fora do ar (404) | 2 notícias em inglês | Trocado pelo endereço que o Ministério da Saúde usa hoje |
| `/verificar/` sem título nem imagem ao compartilhar | `gerar_verificar.py` | Open Graph e Twitter Card (só `<meta>`; a CSP não muda) |
| `/pedido-de-documentos/` sem Twitter Card e sem dados estruturados | `gerar_pedido_documentos.py` | `WebPage` + `BreadcrumbList` |
| Fonte desatualizada: `institucional.json` dizia "por PIX" onde a página no ar diz "por PIX ou cartão" | `site/institucional.json` | Fonte alinhada ao que está no ar (regerar a página não desfaz mais o texto) |

Regerar as 32 páginas institucionais também trouxe o que já estava no gerador e não tinha sido aplicado em
todas: o endereço atual do `chat.js`, o link "Como produzimos nosso conteúdo" no rodapé e duas regras de CSS
dos botões.

### Na Redação (PR do mesmo branch)

| Problema | Correção |
| --- | --- |
| Título da notícia do Setembro Amarelo saía como "Setembro Amarelo…" (17 caracteres): o corte evitava partir o nome da filial e ia sempre para antes dele | `tituloDaAba` corta depois do nome quando ele cabe inteiro: "Setembro Amarelo: a Cruz Vermelha Brasileira Rio de Janeiro…" (60) |
| Duas notícias com tradução em inglês sem o hreflang de volta (Convenções de Genebra e CICV) | `traducoes-en.json` copiado de novo do site (18 → 20 pares) |
| `/transparencia/` e `/canais-oficiais/` sem dados estruturados | `WebPage` + `BreadcrumbList` |

As três só aparecem no site depois de **"Regerar as páginas das notícias"** e de republicar o portal.

## 3. O que ficou para decidir (não mexi)

1. **A escola aparece duplicada no Google.** `escola.cruzvermelhariodejaneiro.org` responde 200 com
   canonical para si mesma, e o Search Console já mostra esse apelido com impressões (posição média entre 23 e 59)
   competindo com `escola.cursoscruzvermelha.org`. O certo é o apelido responder 301 para o endereço oficial,
   ou o canonical apontar sempre para ele. É no app da escola (Render), fora destes repositórios.
2. **Links quebrados dentro do texto das matérias** (o texto mora no banco da Redação, e escrever no banco
   pede autorização à parte):
   - `/noticias/sobrecarga-do-cuidador-sinais-e-como-pedir-apoio/` (404), citado em "Lesão por pressão em
     idoso acamado". O endereço certo é `/noticias/sobrecarga-do-cuidador-quem-cuida-tambem-precisa-de-cuidado/`.
   - `gov.br/saude/pt-br/assuntos/saude-de-a-a-z/s/samu-192` (404) em 16 matérias; o atual é
     `gov.br/saude/pt-br/composicao/saes/samu-192`.
   - `gov.br/mdr/.../informacoes-uteis/saiba-como-agir` (404) em 3 matérias.
   - 11 links de leis em `www2.camara.leg.br` responderam 404 daqui, em matérias e também em páginas deste
     repositório (`/base-legal/`, `/emblema/`, `/kit-de-primeiros-socorros/`, `/onde-buscar-ajuda/`,
     `/guia-do-bombeiro-civil/`, `/historia/`). Pode ser bloqueio por país: conferir no navegador antes de
     trocar pelo Planalto.
   - 18 matérias ligam para a escola pelo endereço antigo com código (`/cursos/05f2c1fa-…`), que responde 301
     para o endereço com nome. Funciona, mas o link direto é melhor.
3. **A página em espanhol do DIH está no ar, mas a fonte está só no PR #174**, ainda aberto. Enquanto não
   for mesclado, uma publicação feita da `main` não a conhece. Ela tem os mesmos três defeitos corrigidos
   aqui na versão em inglês (título de 74, descrição de 243 e o link do Commons cortado no parêntese).
4. **Título cortado com reticências nas notícias.** Seis matérias perdem o fim da manchete para caber em 60
   caracteres, às vezes por dois caracteres ("…o que fazer e o que evitar", "…no Rio"). O Google lê o título
   inteiro mesmo quando mostra só o começo; o corte tira palavras de busca. Sugestão: deixar passar inteiro
   até uns 65 e cortar só acima disso. É regra da casa, por isso não mudei.
5. **Deslocamento de layout nas notícias** (CLS 0,20 no laboratório; nos visitantes reais está em 0). Vale
   olhar o que empurra o texto na abertura da matéria.
6. `sitemap-index.xml` diz que o `sitemap.xml` mudou em 11/11, e ele mudou em 14/11: a data é a da última
   vez que `gerar_sitemaps.py` rodou. Rodar de novo depois desta publicação resolve.

## 4. Onde há tráfego para ganhar (Search Console, 28 dias)

| Página | Impressões | Cliques | Posição | O que fazer |
| --- | --- | --- | --- | --- |
| `/en/news/basic-first-aid-course-…` | 4.870 | 1 | 7,1 | Aparece para quem busca curso em inglês, fora do Rio; o título promete um curso presencial. Vale um título de guia ("First aid basics: what to do in the first minutes") |
| `/en/news/henry-dunant-…` e a versão em português | 4.078 e 1.235 | 7 e 10 | 7,2 e 4,1 | "henry dunant" tem 1.394 impressões na posição 4,8 e CTR de 0,4%: o resultado do Google já responde. Pouco a ganhar |
| `/noticias/o-que-fazer-quando-alguem-desmaia/` | 1.778 | 6 | 9,0 | Fim da primeira página: reforçar com links internos e ligar ao guia `/quando-ligar-192/` |
| `/noticias/lei-do-voluntariado-o-que-diz-a-lei-9608/` | 1.342 | 9 | 7,0 | Idem, a partir de `/voluntariado/` |
| `/noticias/curso-de-suporte-basico-de-vida-…` e `…bombeiro-civil-…` | 1.329 e 1.144 | 19 e 11 | 7,8 e 7,6 | Buscas de curso com intenção de matrícula: uma página por curso no domínio (item 5 da revisão de setembro) segue sendo a mudança de maior retorno |
| `/emblema/` | 1.199 | 5 | 6,8 | Título mais direto para a busca ("Emblema da Cruz Vermelha: significado e quem pode usar") |
| `/voluntariado/` | 551 | 49 | 12,7 | Segunda página com CTR de 8,9%: quem vê, clica. Subir uma página aqui rende |

Continuam valendo, da revisão de setembro: Perfil da Empresa no Google na Praça da Cruz Vermelha e a
verificação da escola no Search Console.
