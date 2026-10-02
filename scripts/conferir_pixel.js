#!/usr/bin/env node
// Confere, num Chromium de verdade, se o Pixel da Meta envia os eventos depois do "Aceitar todos" e
// se nada sai sem consentimento. Os envios à Meta (facebook.com/tr) e ao GA4 (/g/collect) são
// registrados e abortados: o teste não suja o conjunto de dados nem o GA4. O repasse à API de
// Conversões (api/medicao.php) também é registrado e respondido aqui, sem chegar ao servidor, e
// precisa levar o mesmo id do Pixel. Nenhuma outra chamada à API do site passa (só a leitura de
// info.php). Não envia formulário.
//
// Uso:
//   NODE_PATH=$(npm root -g) node scripts/conferir_pixel.js                  # o site no ar
//   NODE_PATH=$(npm root -g) node scripts/conferir_pixel.js --repositorio    # as páginas de site/ no lugar
//                                                                            # das do ar (antes de publicar)
// Sai com 1 se algum cenário falhar. Precisa do Playwright (já instalado no ambiente de nuvem).
//
// Por que existe: de 27/09 a 02/10/2026 o "Aceitar todos" baixava o fbevents.js, mas nenhum evento
// saía (um fbq('consent', 'revoke') na fila travava o Pixel). A conferência da época só olhava o
// download do script; esta exige o PageView, o ViewContent e o InitiateCheckout na Meta.
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const RAIZ = path.resolve(__dirname, '..', 'site');
const BASE = 'https://cruzvermelhariodejaneiro.org';
const DO_REPOSITORIO = process.argv.includes('--repositorio');

function arquivoLocal(u) {
  const x = new URL(u);
  if (x.host !== 'cruzvermelhariodejaneiro.org') return null;
  let p = decodeURIComponent(x.pathname);
  if (p.endsWith('/')) p += 'index.html';
  const f = path.join(RAIZ, p);
  return f.startsWith(RAIZ) && fs.existsSync(f) && fs.statSync(f).isFile() ? f : null;
}

function tipo(u) {
  if (/facebook\.com\/tr/.test(u)) { const x = new URL(u); return 'meta:' + (x.searchParams.get('ev') || 'automático') + (x.searchParams.get('eid') ? '#' + x.searchParams.get('eid') : ''); }
  if (/connect\.facebook\.net\/signals\/config/.test(u)) return 'meta:config';
  if (/connect\.facebook\.net\/.*fbevents\.js/.test(u)) return 'meta:fbevents.js';
  return null;
}

let falhas = 0;
async function cenario(nome, url, passos, esperado) {
  const navegador = await chromium.launch({ args: ['--disable-blink-features=AutomationControlled'] });
  // O Pixel não envia nada quando percebe navegador automatizado: o teste se apresenta como um Chrome comum.
  const ctx = await navegador.newContext({ locale: 'pt-BR', viewport: { width: 1280, height: 900 },
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' });
  await ctx.addInitScript(() => { Object.defineProperty(navigator, 'webdriver', { get: () => undefined }); });
  if (esperado.jaAceitou) {
    await ctx.addCookies([{ name: 'cvrj_consentimento', value: encodeURIComponent(`v=1&e=1&m=1&t=${Math.floor(Date.now() / 1000)}`),
      domain: '.cruzvermelhariodejaneiro.org', path: '/', secure: true, sameSite: 'Lax' }]);
  }
  if (DO_REPOSITORIO) {
    await ctx.route(/^https:\/\/cruzvermelhariodejaneiro\.org\//, async (r) => {
      // Páginas e os scripts delas (checkout.js, chat.js, aviso de cookies) vêm do repositório.
      const documento = r.request().resourceType() === 'document';
      if (!documento && !/\.(js|css)$/.test(new URL(r.request().url()).pathname)) return r.continue();
      const f = arquivoLocal(r.request().url()) || (documento && esperado.pagina404 ? path.join(RAIZ, '404.html') : null);
      if (!f) return r.continue();
      const tipoDoArquivo = f.endsWith('.js') ? 'application/javascript' : f.endsWith('.css') ? 'text/css' : 'text/html; charset=utf-8';
      await r.fulfill({ status: documento && esperado.pagina404 ? 404 : 200, contentType: tipoDoArquivo, body: fs.readFileSync(f) });
    });
  }
  // No Playwright vale a rota registrada por último: a trava da API vem depois das páginas, e o repasse depois da trava.
  const repasses = [];
  let fase = 'chegada';
  await ctx.route(/\/matricula-cursos-presenciais\/api\//, (r) => (r.request().method() === 'GET' && /\/api\/info\.php$/.test(new URL(r.request().url()).pathname) ? r.continue() : r.abort()));
  await ctx.route(/\/api\/medicao\.php/, (r) => { try { repasses.push(Object.assign(JSON.parse(r.request().postData() || '{}'), { fase })); } catch (e) { /* corpo inválido */ } return r.fulfill({ status: 204, body: '' }); });
  await ctx.route(/facebook\.com\/tr|google-analytics\.com\/g\/collect/, (r) => r.abort());
  const p = await ctx.newPage();
  const vistos = [];
  const erros = [];
  p.on('request', (r) => { const t = tipo(r.url()); if (t) vistos.push({ fase, t }); });
  p.on('pageerror', (e) => erros.push(e.message.slice(0, 160)));
  await p.goto(BASE + url + (url.includes('?') ? '&' : '?') + 'conferir_pixel=1', { waitUntil: 'load' });
  await p.waitForTimeout(esperado.espera || 6000);
  for (const passo of passos) {
    fase = passo.nome;
    await passo.fazer(p);
    await p.waitForTimeout(passo.espera || 7000);
  }
  const comId = vistos.filter((v) => /^meta:[A-Z]/.test(v.t)).map((v) => v.t.slice(5).split('#'));
  const eventos = new Set(comId.map(([ev]) => ev));
  const problemas = [];
  // API de Conversões: os eventos de página que saíram no Pixel também vão ao repasse, com o mesmo id.
  for (const [ev, eid] of comId) {
    if (['PageView', 'ViewContent', 'InitiateCheckout'].includes(ev) && !repasses.some((x) => x.evento === ev && x.id === eid)) problemas.push(`${ev} sem repasse com o mesmo id`);
  }
  if (esperado.nadaDaMeta && repasses.length) problemas.push('repasse sem consentimento');
  for (const ev of esperado.eventos || []) if (!eventos.has(ev)) problemas.push(`faltou ${ev}`);
  if (esperado.nadaDaMeta && vistos.length) problemas.push('falou com a Meta sem consentimento');
  if (esperado.semEventosEm) {
    const depois = vistos.filter((v) => v.fase === esperado.semEventosEm && /^meta:[A-Z]/.test(v.t));
    if (depois.length) problemas.push(`evento depois de retirar o consentimento: ${depois.map((v) => v.t).join(', ')}`);
    if (repasses.some((x) => x.fase === esperado.semEventosEm)) problemas.push('repasse depois de retirar o consentimento');
  }
  if (erros.length) problemas.push('erro na página: ' + erros.join(' / '));
  falhas += problemas.length ? 1 : 0;
  console.log(`${problemas.length ? 'FALHA' : 'ok   '} ${nome}: ${[...eventos].join(', ') || 'nenhum evento'}${repasses.length ? ` · repasse: ${repasses.map((x) => x.evento).join(', ')}` : ''}${problemas.length ? ' (' + problemas.join('; ') + ')' : ''}`);
  await navegador.close();
}

const aceitar = { nome: 'aceitar', fazer: (p) => p.getByRole('button', { name: /^(Aceitar todos|Accept all|Aceptar todas)$/ }).click() };
const rejeitar = { nome: 'rejeitar', fazer: (p) => p.getByRole('button', { name: /^(Rejeitar|Reject|Rechazar)$/ }).click() };
const curso = (n) => ({ nome: `curso ${n + 1}`, fazer: (p) => p.locator('.mr-lista a[data-curso]').nth(n).click() });
const retirar = { nome: 'retirar', espera: 1500, fazer: async (p) => {
  await p.locator('[data-cvrj-cookies]').first().click();
  await p.waitForTimeout(400);
  if (await p.locator('#cvrj-ck-marketing').isChecked()) await p.locator('label[for="cvrj-ck-marketing"]').click();
  await p.getByRole('button', { name: 'Salvar escolhas' }).click();
} };

(async () => {
  console.log(`Pixel ${DO_REPOSITORIO ? 'com as páginas do repositório' : 'no ar'} (envios à Meta e ao GA4 abortados)`);
  await cenario('home, aceitar', '/', [aceitar], { eventos: ['PageView'] });
  await cenario('matrícula, aceitar e abrir um curso', '/matricula-cursos-presenciais/', [aceitar, curso(2)], { eventos: ['PageView', 'ViewContent'] });
  await cenario('matrícula, quem já tinha aceitado', '/matricula-cursos-presenciais/', [], { jaAceitou: true, espera: 9000, eventos: ['PageView'] });
  await cenario('checkout com curso, aceitar', '/matricula-cursos-presenciais/checkout/?curso=puncao-venosa', [aceitar], { eventos: ['PageView', 'InitiateCheckout'] });
  await cenario('matrícula, rejeitar', '/matricula-cursos-presenciais/', [rejeitar, curso(1)], { nadaDaMeta: true });
  await cenario('matrícula, sem escolher', '/matricula-cursos-presenciais/', [curso(1)], { nadaDaMeta: true });
  await cenario('matrícula, aceitar e retirar', '/matricula-cursos-presenciais/', [aceitar, curso(1), retirar, curso(3)], { eventos: ['PageView', 'ViewContent'], semEventosEm: 'curso 4' });
  await cenario('bio, aceitar', '/bio/', [aceitar], { eventos: ['PageView'] });
  await cenario('inglês, aceitar', '/en/', [aceitar], { eventos: ['PageView'] });
  await cenario('espanhol (cursos), aceitar', '/es/cursos/', [aceitar], { eventos: ['PageView'] });
  await cenario('404, aceitar', '/pagina-que-nao-existe-conferir-pixel/', [aceitar], { pagina404: true, eventos: ['PageView'] });
  console.log(falhas ? `${falhas} cenário(s) com falha` : 'Tudo certo.');
  process.exit(falhas ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
