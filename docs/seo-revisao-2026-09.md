# Revisão de SEO do ecossistema (19/09/2026)

Auditoria feita com `scripts/auditar_seo.py` (páginas ao vivo) e medição de peso no navegador
(Playwright), antes e depois das correções. Escopo: `cruzvermelhariodejaneiro.org` e subdomínios,
Redação, escola (`escola.cursoscruzvermelha.org`) e as landing pages na Vercel.

## 1. O que foi encontrado e corrigido neste repositório

| Problema | Antes | Depois |
| --- | --- | --- |
| Peso da home | 11,7 MB (9,8 MB de imagens: seis fotos de curso com ~1 MB cada) | 2,5 MB (576 KB de imagens) |
| Maior imagem carregada na home | 1,17 MB (JPEG 768x1024 mostrado a 351 px) | 218 KB (WebP 1920 px do banner principal) |
| Tempo de carga da home (load, rede rápida) | 3,2 s | 1,9 s |
| Imagens sem `width`/`height` (layout pulando, CLS) | 34 na home, 6 na doação, 6 no agasalho | 0 |
| Imagens abaixo da dobra sem `loading="lazy"` | 26 na home | 0 (só as 5 da primeira dobra carregam na hora) |
| Open Graph e Twitter Card | home, doação, agasalho e equipe sem nenhum | todas com título, descrição e imagem 1200x630 própria |
| Canonical | faltava em doação, agasalho e equipe | em todas |
| Dados estruturados | home só com `NGO`; doação, agasalho e equipe sem nada | home: `NGO` (com endereço completo, contato e imagem) + `WebSite`; doação: `WebPage` + `BreadcrumbList` + `DonateAction`; agasalho e equipe: `WebPage` + `BreadcrumbList` |
| Títulos e descrições | home genérica ("Cruz Vermelha Brasileira - Rio de Janeiro"); matrícula com título de 64 e descrição de 236 caracteres | títulos com o que a pessoa procura (cursos, doação, voluntariado) até 60 caracteres; descrições até 160 |
| H1 da matrícula | frase de apoio dentro do H1 | H1 limpo; apoio em parágrafo |
| `www.` | servia o site inteiro em duplicata | 301 para o apex (`.htaccess` da raiz) |
| Página 404 | padrão da Hostinger, sem menu | `404.html` própria, com atalhos, `noindex` |
| Sitemaps | só o `sitemap.xml` da Redação (páginas fixas sem data) | índice com páginas (data real e 48 imagens), notícias e subdomínios; enviado no Search Console |
| Logo do cabeçalho | PNG de 166 KB em 1730 px, mostrado a 173 px | WebP de 10 KB em 520 px |

Ferramentas novas: `scripts/otimizar_imagens.py` (gera as versões WebP e as imagens de
compartilhamento em `site/assets/otim/`), `scripts/aplicar_imagens_otimizadas.py` (troca as tags
`<img>` com `srcset`, `sizes`, dimensões e lazy), `scripts/gerar_404.py`, `scripts/auditar_seo.py`.

## 2. Velocidade (Lighthouse, celular simulado, 19/09 à noite)

| Página | Antes | Depois | LCP antes → depois |
| --- | --- | --- | --- |
| Home | 50 | 68 | 9,4 s → 3,9 s |
| Matrícula | 39 | 81 | 9,7 s → 1,2 s |
| Checkout | 80 | 80 | 2,2 s → 2,2 s |
| Doação | 55 | 79 | 7,8 s → 2,7 s |
| Equipe | 54 | 75 | 8,7 s → 3,4 s |
| Campanha do Agasalho | 59 | 78 | 6,7 s → 3,2 s |
| Parabéns | 78 | 80 | 2,3 s → 2,0 s |

O que mudou além das imagens (seção 1):

- **GA4 e Pixel carregam depois do `load`** (mais um instante ocioso). As chamadas `gtag()` e
  `fbq()` continuam no lugar e ficam na fila até os scripts chegarem, então nenhum evento se
  perde; conferido ao vivo (PageView, InitiateCheckout, Lead, AddPaymentInfo, Purchase).
- **Font Awesome saiu.** Os 39 ícones usados viraram SVG inline por um sprite em cada página
  (`scripts/icones.py`, desenhos em `scripts/icones.json`, Font Awesome Free, CC BY 4.0). Menos
  ~450 KB e 4 requisições por página; o `<i>` continua na marcação, então o CSS não mudou.
- **Google Fonts sem bloquear** a primeira pintura (preload + troca para stylesheet), QR code do
  checkout com `defer`, segundo banner do carrossel só carrega quando aparece, imagem principal
  de cada página com `preload`/`fetchpriority`, cache de 30 dias nas pastas de imagens geradas.

O que ainda pesa e é decisão de negócio: **Google Tag + Pixel** somam 1,4 MB e ~750 ms de
processamento em celular fraco, em toda página. É o teto do que dá para ganhar sem mexer neles. A
única saída seria carregá-los só no primeiro toque ou rolagem, o que deixaria de contar quem abre
e sai sem interagir (afeta "visualizações da página de destino" dos anúncios). Não foi feito.

Fora deste repositório: as páginas de notícia da Redação têm LCP de ~9 s por imagens PNG de 1 MB;
converter para WebP com largura limitada resolve.

## 3. O que depende de outros projetos (checklist)

**Redação** (`redacao-cruzvermelhariodejaneiro`, gera `sitemap.xml`, `robots.txt`, notícias,
termos e privacidade):
1. `robots.txt`: acrescentar `Sitemap: https://cruzvermelhariodejaneiro.org/sitemap-index.xml`.
2. `sitemap.xml`: tirar `cursos.html` (é 301) e pôr `lastmod` nas páginas fixas.
3. Título das notícias: o sufixo "— Cruz Vermelha Brasileira — Rio de Janeiro" leva o `<title>` a
   100+ caracteres; usar "| Cruz Vermelha RJ".
4. `/noticias/`: sem `og:image` e sem `twitter:card`; `/termos/` e `/privacidade/` idem e sem JSON-LD
   (`WebPage` + `BreadcrumbList` bastam).
5. `redacao.cruzvermelhariodejaneiro.org` é ferramenta interna e está indexável: `noindex` no
   layout ou `robots.txt` com `Disallow: /`.

**Escola** (`escola.cursoscruzvermelha.org`, Render): é o ponto mais fraco do ecossistema.
Sem `meta description`, sem canonical, sem Open Graph, sem JSON-LD, sem `robots.txt` nem
`sitemap.xml` (404 em 19/09), e o mesmo conteúdo responde em três hosts. O kit em `escola/` deste
repositório resolve tudo isso (`escola/README.md`): `robots.txt`, `sitemap.xml`, canonical,
`head-seo.html` com descrição, OG e `Course` em JSON-LD por página de curso. Depois de publicado,
verificar a propriedade no Search Console e enviar `sitemap-escola.xml`.

**Landing pages na Vercel**: `doar.` sem canonical, OG, JSON-LD (`DonateAction`) e sem `alt` na
única imagem; `puncaovenosav1.` com título de 67 caracteres e subpáginas (`/inscricao`,
`/politica-de-privacidade`, `/politica-de-reembolso`) apontando o canonical para a raiz (corrigir
para canonical por página). **Hostinger**: `projetocores.` sem canonical e sem favicon;
`links.` redireciona para o instalador `install.php` (remover ou proteger).

## 4. Search Console e presença local

1. Enviar `https://cruzvermelhariodejaneiro.org/sitemap-index.xml` na propriedade de domínio.
2. Em "Melhorias", acompanhar Core Web Vitals (a queda de peso deve aparecer em 28 dias) e os
   relatórios de dados estruturados (NGO, BreadcrumbList, FAQPage, ItemList).
3. Pedir indexação manual da home e da matrícula depois desta publicação (Inspeção de URL).
4. **Perfil da Empresa no Google** (Google Business Profile): criar ou reivindicar a ficha
   "Cruz Vermelha Brasileira - Filial do Rio de Janeiro" na Praça da Cruz Vermelha, 10, com
   categoria "Organização sem fins lucrativos", site, telefone, horário, fotos reais e o link da
   matrícula. É o que aparece no mapa quando alguém busca "curso de primeiros socorros rio de
   janeiro"; hoje o site não tem essa presença conectada.
5. Bing Webmaster Tools aceita importar a propriedade do Search Console em um clique.

## 5. Conteúdo: o que faria a diferença na busca orgânica

- **Uma página por curso no domínio principal** (`/cursos/primeiros-socorros-basico/` etc.), com
  programa, carga horária, público, certificado, FAQ e `Course` + `FAQPage` em JSON-LD. Hoje quem
  busca "curso de bombeiro civil no rio" encontra só a página da escola (sem SEO) ou a lista da
  matrícula. É a mudança de maior retorno que resta.
- Notícias com foco em buscas reais ("o que fazer em caso de afogamento", "como doar agasalho no
  Rio"): a Redação já tem `NewsArticle`; falta o ritmo e o gancho de busca.
- Página de voluntariado própria (como entrar, requisitos, formação), hoje diluída na home.

## 6. Como manter

- `python3 scripts/auditar_seo.py` a cada publicação relevante: aponta título, descrição,
  canonical, OG, imagens sem alt/dimensão e JSON-LD de cada página ao vivo.
- Foto nova nas páginas à mão: colocar o original em `site/assets/` (cópia do servidor), incluir
  em `scripts/otimizar_imagens.py`, rodar `otimizar_imagens.py` e `aplicar_imagens_otimizadas.py`,
  publicar `site/assets/otim/` e a página.
- Sitemaps: `python3 scripts/gerar_sitemaps.py` e publicar os quatro arquivos.
