'use strict';
/**
 * Kit de SEO da Escola de Educação e Saúde CVB-RJ (app Express no Render).
 *
 * O app hoje responde em três hosts com o MESMO conteúdo:
 *   - https://escola.cursoscruzvermelha.org        (canônico, domínio independente)
 *   - https://escola.cruzvermelhariodejaneiro.org  (apelido sob o domínio principal)
 *   - https://escola-cruz-vermelha-1.onrender.com  (endereço padrão do Render)
 *
 * Por decisão da equipe, a escola fica em domínio separado por resiliência. Por isso
 * este kit NÃO redireciona o apelido para o canônico (se o domínio principal cair, o
 * apelido continua servindo). Ele apenas diz ao Google qual é o endereço oficial:
 *   1. <link rel="canonical"> em todas as páginas (res.locals.canonicalUrl);
 *   2. X-Robots-Tag: noindex no host *.onrender.com e nas rotas privadas;
 *   3. /robots.txt e /sitemap.xml gerados aqui (o app não tinha nenhum dos dois).
 *
 * Como ligar (server.js / app.js), ANTES das rotas do site:
 *
 *   const { criarRoteadorSeo } = require('./seo');
 *   app.use(criarRoteadorSeo({
 *     // opcional: função async que devolve os cursos publicados a partir do banco.
 *     // Cada item precisa ter `id` (uuid) e, se existir, `atualizadoEm` (Date).
 *     listarCursosPublicos: async () => db.cursos.findMany({ where: { publicado: true } }),
 *   }));
 *
 * E no <head> das views:  <link rel="canonical" href="<%= canonicalUrl %>">
 * (troque a sintaxe conforme o motor de templates: EJS, Pug, Handlebars...)
 */
const express = require('express');

const ORIGEM_CANONICA = (process.env.ESCOLA_ORIGEM || 'https://escola.cursoscruzvermelha.org').replace(/\/+$/, '');
const PAGINAS_FIXAS = ['/', '/cursos', '/sobre'];
const ROTAS_PRIVADAS = ['/login', '/cadastro', '/minha-conta', '/inscrever', '/api', '/admin'];

// Cursos publicados em 16/09/2026 - usados só se `listarCursosPublicos` não for informada ou falhar.
const CURSOS_FALLBACK = [
  '5bd737ee-00a6-48dc-b5ab-08cde9b12897', // Bombeiro Civil
  '2bf8d91d-ad41-4232-903f-ca4895b611f3', // Cuidador de Idosos (Curso Livre)
  'b05365c2-c066-4c73-bb4a-f32475a338a2', // Micropigmentação Labial
  '05f2c1fa-3aef-40c6-a5df-66ea30c32a4b', // Primeiros Socorros Básico
  'f8373fef-2523-42e4-ab3f-cc1059b10379', // Primeiros Socorros Lei Lucas - Ambientes com Crianças
  'ab2035b1-2c11-476f-a275-d29a4111ecf0', // Punção Venosa
  '4f84e97b-1908-4e1e-8ea7-97d4124caff4', // Suporte Básico de Vida
];

const escaparXml = (s) => String(s).replace(/[<>&'"]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', "'": '&apos;', '"': '&quot;' }[c]));
const dataDoMapa = (d) => (d instanceof Date && !Number.isNaN(d.getTime()) ? d.toISOString().slice(0, 10) : null);

function ehRotaPrivada(caminho) {
  return ROTAS_PRIVADAS.some((p) => caminho === p || caminho.startsWith(p + '/'));
}

function gerarRobots() {
  return [
    'User-agent: *',
    'Allow: /',
    ...ROTAS_PRIVADAS.filter((p) => p !== '/api' && p !== '/admin').map((p) => `Disallow: ${p === '/inscrever' ? '/inscrever/' : p}`),
    'Disallow: /api/',
    '',
    `Sitemap: ${ORIGEM_CANONICA}/sitemap.xml`,
    '',
  ].join('\n');
}

function gerarSitemap(cursos) {
  const vistos = new Set();
  const entradas = [
    ...PAGINAS_FIXAS.map((p) => ({ url: ORIGEM_CANONICA + p })),
    ...cursos.map((c) => ({ url: `${ORIGEM_CANONICA}/cursos/${c.id}`, lastmod: dataDoMapa(c.atualizadoEm) })),
  ].filter((e) => (vistos.has(e.url) ? false : (vistos.add(e.url), true)));
  return [
    '<?xml version="1.0" encoding="UTF-8"?>',
    '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
    ...entradas.map((e) => ['  <url>', `    <loc>${escaparXml(e.url)}</loc>`, ...(e.lastmod ? [`    <lastmod>${e.lastmod}</lastmod>`] : []), '  </url>'].join('\n')),
    '</urlset>',
    '',
  ].join('\n');
}

function criarRoteadorSeo({ listarCursosPublicos } = {}) {
  const roteador = express.Router();

  // 1 e 2: canonical para as views + noindex onde não deve indexar.
  roteador.use((req, res, next) => {
    const caminho = req.path === '/' ? '/' : req.path.replace(/\/+$/, '');
    res.locals.canonicalUrl = ORIGEM_CANONICA + caminho;
    const host = String(req.hostname || '').toLowerCase();
    if (host.endsWith('.onrender.com') || ehRotaPrivada(caminho)) {
      res.set('X-Robots-Tag', 'noindex, nofollow');
    }
    next();
  });

  // 3: robots.txt
  roteador.get('/robots.txt', (_req, res) => {
    res.type('text/plain').set('Cache-Control', 'public, max-age=3600').send(gerarRobots());
  });

  // 3: sitemap.xml (dinâmico, com fallback para a lista fixa)
  roteador.get('/sitemap.xml', async (_req, res) => {
    let cursos = CURSOS_FALLBACK.map((id) => ({ id }));
    if (typeof listarCursosPublicos === 'function') {
      try {
        const lista = await listarCursosPublicos();
        if (Array.isArray(lista) && lista.length) cursos = lista.filter((c) => c && c.id);
      } catch (erro) {
        console.error('[seo] falha ao listar cursos para o sitemap; usando lista fixa:', erro && erro.message);
      }
    }
    res.type('application/xml').set('Cache-Control', 'public, max-age=3600').send(gerarSitemap(cursos));
  });

  return roteador;
}

module.exports = { criarRoteadorSeo, gerarRobots, gerarSitemap, ORIGEM_CANONICA, CURSOS_FALLBACK };
