#!/usr/bin/env node
// Confere, num Chromium de verdade, as duas conversões que o Google Ads importa do GA4: generate_lead (envio do
// checkout) e purchase (curso pago e doação paga). Lê o que o gtag.js manda ao GA4 (/g/collect, nos três endereços do Google) e responde 204 no
// lugar do Google: o teste não suja a propriedade. (Abortar o envio fazia o gtag.js repetir o evento por outro
// caminho, e o teste via compras em dobro que não existem.) As APIs de pagamento e de status são respondidas aqui, com dados inventados: nenhuma
// inscrição, cobrança ou doação é criada. Só a leitura de info.php (catálogo) vai ao servidor.
//
// Uso:
//   NODE_PATH=$(npm root -g) node scripts/conferir_ga4_conversoes.js                 # o site no ar
//   NODE_PATH=$(npm root -g) node scripts/conferir_ga4_conversoes.js --repositorio   # as páginas de site/ (antes de publicar)
// Sai com 1 se algum cenário falhar.
//
// Por que existe (24/10/2026): antes das campanhas do Google Ads, conferir que as conversões saem com valor, moeda e
// id; que o clique do anúncio (gclid, ou gbraid no iPhone) chega ao GA4 mesmo quando a pessoa só aceita o aviso de cookies no checkout; e
// que o token da doação (que abre o nome e o e-mail de quem doou) não vai ao GA nem à Meta.
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const RAIZ = path.resolve(__dirname, '..', 'site');
const BASE = 'https://cruzvermelhariodejaneiro.org';
const DO_REPOSITORIO = process.argv.includes('--repositorio');
const GCLID = 'conferir-ga4-gclid';
const TOKEN = 'c0ffee'.padEnd(40, '0');

function arquivoLocal(u) {
  const x = new URL(u);
  if (x.host !== 'cruzvermelhariodejaneiro.org') return null;
  let p = decodeURIComponent(x.pathname);
  if (p.endsWith('/')) p += 'index.html';
  const f = path.join(RAIZ, p);
  return f.startsWith(RAIZ) && fs.existsSync(f) && fs.statSync(f).isFile() ? f : null;
}

// O GA4 manda para google-analytics.com, para analytics.google.com (os lotes) e uma cópia de publicidade para
// stats.g.doubleclick.net: as três ficam aqui. Um POST do GA4 pode levar vários eventos: os parâmetros comuns na URL e
// um evento por linha do corpo (com \r\n no fim).
function eventosGa(req) {
  const u = new URL(req.url());
  const comuns = Object.fromEntries(u.searchParams);
  const linhas = (req.postData() || '').split(/\r?\n/).filter(Boolean);
  return (linhas.length ? linhas : ['']).map((l) => Object.assign({}, comuns, Object.fromEntries(new URLSearchParams(l))));
}

const agoraUtc = () => new Date().toISOString().slice(0, 19).replace('T', ' ');
const statusCurso = () => ({
  ok: true, status: 'pago', token: TOKEN, id_compra: 'conferir-id-compra', metodo: 'pix', plano: 'so_taxa', parcelas: 1,
  curso: { slug: 'puncao-venosa', nome: 'Punção Venosa' }, inscricao_centavos: 9900, matricula_centavos: 0,
  taxa_centavos: 0, divulgacao_centavos: 0, total_centavos: 9900, total_cobrado_centavos: 9900, diferenca_devolver_centavos: 0,
  email: 'conferir@example.com', pago_em: agoraUtc(), espera: null, sem_turma: true, horarios: null, escola: null, teste: false,
  urls: { parabens: `/matricula-cursos-presenciais/parabens/?t=${TOKEN}`, pendente: `/matricula-cursos-presenciais/pendente/?t=${TOKEN}` },
});
const statusDoacao = () => ({
  ok: true, status: 'pago', token: TOKEN, metodo: 'pix', frequencia: 'unica', anonima: false, nome: 'Conferência',
  email: 'conferir@example.com', protocolo: 'CVD-261024-0001', valor_centavos: 5000, taxa_centavos: 0, total_centavos: 5000,
  pix: null, cartao: null, criado_em: agoraUtc(), pago_em: agoraUtc(), url: `/doe/obrigado/?t=${TOKEN}`, teste: false,
});

let falhas = 0;
async function cenario(nome, fazer) {
  const navegador = await chromium.launch({ args: ['--disable-blink-features=AutomationControlled'] });
  const ctx = await navegador.newContext({ locale: 'pt-BR', viewport: { width: 1280, height: 900 },
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' });
  await ctx.addInitScript(() => { Object.defineProperty(navigator, 'webdriver', { get: () => undefined }); });
  if (DO_REPOSITORIO) {
    await ctx.route(/^https:\/\/cruzvermelhariodejaneiro\.org\//, async (r) => {
      const documento = r.request().resourceType() === 'document';
      if (!documento && !/\.(js|css)$/.test(new URL(r.request().url()).pathname)) return r.continue();
      const f = arquivoLocal(r.request().url());
      if (!f) return r.continue();
      const tipo = f.endsWith('.js') ? 'application/javascript' : f.endsWith('.css') ? 'text/css' : 'text/html; charset=utf-8';
      await r.fulfill({ status: 200, contentType: tipo, body: fs.readFileSync(f) });
    });
  }
  // Vale a rota registrada por último: a trava de toda a API vem antes das respostas inventadas.
  const json = (r, corpo, status) => r.fulfill({ status: status || 200, contentType: 'application/json', body: JSON.stringify(corpo) });
  await ctx.route(/\/(matricula-cursos-presenciais|doe)\/api\//, (r) => (r.request().method() === 'GET' && /\/api\/info\.php$/.test(new URL(r.request().url()).pathname) ? r.continue() : r.abort()));
  await ctx.route(/\/api\/medicao\.php/, (r) => r.fulfill({ status: 204, body: '' }));
  await ctx.route(/\/matricula-cursos-presenciais\/api\/pagamentos\.php/, (r) => json(r, { ok: false, erro: 'Conferência: nada foi cobrado.' }, 503));
  await ctx.route(/\/matricula-cursos-presenciais\/api\/status\.php/, (r) => json(r, statusCurso()));
  await ctx.route(/\/doe\/api\/status\.php/, (r) => json(r, statusDoacao()));
  const ga = [];
  const meta = [];
  // O registro é pelo evento 'request' da página, e não pela rota: o gtag.js manda os eventos em lote com fetch keepalive, que a
  // rota do Playwright não intercepta (o generate_lead do envio sai assim, junto com o checkout_erro). Uma tentativa
  // repetida do mesmo lote (mesma URL e mesmo corpo) conta uma vez.
  const vistos = new Set();
  const registrar = (req) => {
    const u = req.url();
    if (!/(google-analytics\.com|analytics\.google\.com|g\.doubleclick\.net)\/g\/collect|facebook\.com\/tr/.test(u)) return;
    const chave = u + '\n' + (req.postData() || '');
    if (vistos.has(chave)) return;
    vistos.add(chave);
    if (/facebook\.com\/tr/.test(u)) meta.push(u + ' ' + (req.postData() || ''));
    else if (!/doubleclick/.test(u)) ga.push(...eventosGa(req));
  };
  await ctx.route(/(google-analytics\.com|analytics\.google\.com|g\.doubleclick\.net)\/g\/collect|facebook\.com\/tr/, (r) => r.fulfill({ status: 204, body: '' }));
  const p = await ctx.newPage();
  p.on('request', registrar);
  const erros = [];
  p.on('pageerror', (e) => erros.push(e.message.slice(0, 160)));
  const problemas = [];
  const t = { p, ctx, ga, meta, problemas, aceitarCookie: () => ctx.addCookies([{ name: 'cvrj_consentimento',
    value: encodeURIComponent(`v=1&e=1&m=1&t=${Math.floor(Date.now() / 1000)}&r=2`), domain: '.cruzvermelhariodejaneiro.org', path: '/', secure: true, sameSite: 'Lax' }]) };
  try { await fazer(t); } catch (e) { problemas.push('erro no roteiro: ' + e.message.split('\n')[0]); }
  if (erros.length) problemas.push('erro na página: ' + erros.join(' / '));
  falhas += problemas.length ? 1 : 0;
  console.log(`${problemas.length ? 'FALHA' : 'ok   '} ${nome}: GA4 = ${[...new Set(ga.map((e) => e.en))].join(', ') || 'nada'}${problemas.length ? '\n        ' + problemas.join('\n        ') : ''}`);
  await navegador.close();
}

const aceitar = (p) => p.getByRole('button', { name: /^Aceitar todos$/ }).click();
const exigir = (t, cond, texto) => { if (!cond) t.problemas.push(texto); };
// A tag do Google G-HDYZZ5JZHF também entrega a G-Z5NWV4RBTT (configuração da tag no painel, não do código: ver
// docs/rastreamento.md). Cada evento sai uma vez para cada uma; a contagem é na propriedade do código.
const ID_GA4 = 'G-HDYZZ5JZHF';
const evento = (t, en) => t.ga.filter((e) => e.en === en && e.tid === ID_GA4);
// O token nos ids e parâmetros dos eventos (transaction_id, eventID da Meta). O endereço da página (dl/dr), que também o
// leva nas telas com ?t=, é conferido à parte, em tokenNoEndereco.
// O Pixel manda o endereço na URL (dl=…) ou num corpo multipart (name="dl").
const semEndereco = (x) => (typeof x === 'string'
  ? x.replace(/([?&](dl|rl|dr)=)[^&\s]*/g, '$1').replace(/(name="(dl|rl)"\r\n\r\n)[^\r]*/g, '$1')
  : Object.fromEntries(Object.entries(x).filter(([k]) => !['dl', 'dr'].includes(k))));
const tokenNosEventos = (t) => {
  const achados = t.ga.concat(t.meta).filter((x) => JSON.stringify(semEndereco(x)).includes(TOKEN));
  for (const x of achados.slice(0, 2)) console.log('        token em: ' + JSON.stringify(semEndereco(x)).replace(/[^,]*(?=c0ffee)/g, '').slice(0, 300));
  return achados.length > 0;
};
const tokenNoEndereco = (t) => t.ga.concat(t.meta).some((x) => JSON.stringify(x).includes(TOKEN));

(async () => {
  console.log(`Conversões do GA4 ${DO_REPOSITORIO ? 'com as páginas do repositório' : 'no ar'} (envios interceptados, APIs de pagamento simuladas)`);

  await cenario('anúncio → inscrição, aceitando o aviso só no checkout → generate_lead', async (t) => {
    const { p } = t;
    await p.goto(`${BASE}/matricula-cursos-presenciais/?curso=puncao-venosa&gclid=${GCLID}&gbraid=conferir-gbraid&utm_source=google&utm_medium=cpc&utm_campaign=conferir`, { waitUntil: 'load' });
    await p.waitForTimeout(1500);
    await p.keyboard.press('Escape');
    await Promise.all([p.waitForURL(/\/checkout\//), p.locator('a.mr-cta[data-curso="puncao-venosa"]:visible').first().click()]);
    const url = new URL(p.url());
    console.log(`        checkout aberto em: ${url.pathname}${url.search}`);
    exigir(t, url.searchParams.get('gclid') === GCLID, `o link do checkout perdeu o gclid (${url.search})`);
    exigir(t, url.searchParams.get('gbraid') === 'conferir-gbraid', 'o link do checkout perdeu o gbraid (clique de anúncio em iPhone)');
    exigir(t, url.searchParams.get('utm_campaign') === 'conferir', 'o link do checkout perdeu as utm_*');
    await p.waitForTimeout(1500);
    await aceitar(p);
    await p.waitForTimeout(6000);
    const pv = evento(t, 'page_view').find((e) => /\/checkout\//.test(e.dl || ''));
    exigir(t, pv, 'o checkout não mandou page_view');
    exigir(t, pv && (pv.dl || '').includes(`gclid=${GCLID}`), 'o page_view do checkout chegou sem o gclid: a conversão não volta ao Google Ads');
    await p.locator('#ck-nome').fill('Conferência GA Quatro');
    await p.locator('#ck-cpf').fill('529.982.247-25');
    await p.locator('#ck-telefone').fill('(21) 99999-0000');
    await p.locator('#ck-email').fill('conferir@example.com');
    await p.locator('#ck-requisitos').check();
    await p.locator('#ck-pagar').click();
    await p.waitForTimeout(15000); // o gtag.js junta os eventos e manda o lote alguns segundos depois
    for (const e of evento(t, 'checkout_erro')) console.log(`        checkout_erro: tipo=${e['ep.tipo']} campo=${e['ep.campo']} http=${e['epn.http'] || e['ep.http'] || ''}`);
    const lead = evento(t, 'generate_lead')[0];
    exigir(t, lead, 'o envio do formulário não mandou generate_lead');
    if (lead) {
      exigir(t, lead.cu === 'BRL', `generate_lead sem moeda BRL (cu=${lead.cu})`);
      exigir(t, Number(lead['epn.value']) > 0, `generate_lead sem valor (value=${lead['epn.value']})`);
      exigir(t, lead['ep.curso'] === 'puncao-venosa', `generate_lead sem o curso (${lead['ep.curso']})`);
      console.log(`        generate_lead: value=${lead['epn.value']} ${lead.cu}, curso=${lead['ep.curso']}, metodo=${lead['ep.metodo']}, plano=${lead['ep.plano']}`);
    }
  });

  await cenario('curso pago (tela Parabéns) → purchase, uma vez só', async (t) => {
    const { p } = t;
    await t.aceitarCookie();
    const url = `${BASE}/matricula-cursos-presenciais/parabens/?t=${TOKEN}`;
    await p.goto(url, { waitUntil: 'load' });
    await p.waitForTimeout(7000);
    const compras = evento(t, 'purchase');
    exigir(t, compras.length === 1, `purchase saiu ${compras.length} vez(es)`);
    const c = compras[0];
    if (c) {
      exigir(t, c['ep.transaction_id'] === 'conferir-id-compra', `transaction_id errado (${c['ep.transaction_id']})`);
      exigir(t, c.cu === 'BRL' && Number(c['epn.value']) === 99, `valor/moeda errados (${c['epn.value']} ${c.cu})`);
      exigir(t, !!c.pr1, 'purchase sem items');
      console.log(`        purchase: transaction_id=${c['ep.transaction_id']}, value=${c['epn.value']} ${c.cu}, item=${decodeURIComponent(c.pr1 || '').slice(0, 60)}`);
    }
    await p.reload({ waitUntil: 'load' });
    await p.waitForTimeout(6000);
    exigir(t, evento(t, 'purchase').length === 1, 'recarregar a tela mandou outra compra');
    exigir(t, !tokenNosEventos(t), 'o token da inscrição foi no id de um evento do GA ou da Meta');
    if (tokenNoEndereco(t)) console.log('        aviso: o endereço da página (?t=<token>) vai no page_view do GA4 e no PageView da Meta');
  });

  await cenario('doação paga (obrigado) → purchase com o protocolo, sem o token', async (t) => {
    const { p } = t;
    await t.aceitarCookie();
    await p.goto(`${BASE}/doe/obrigado/?t=${TOKEN}`, { waitUntil: 'load' });
    await p.waitForTimeout(7000);
    const compras = evento(t, 'purchase');
    exigir(t, compras.length === 1, `purchase saiu ${compras.length} vez(es)`);
    const c = compras[0];
    if (c) {
      exigir(t, c['ep.transaction_id'] === 'CVD-261024-0001', `transaction_id não é o protocolo (${c['ep.transaction_id']})`);
      exigir(t, c.cu === 'BRL' && Number(c['epn.value']) === 50, `valor/moeda errados (${c['epn.value']} ${c.cu})`);
      console.log(`        purchase: transaction_id=${c['ep.transaction_id']}, value=${c['epn.value']} ${c.cu}`);
    }
    await p.reload({ waitUntil: 'load' });
    await p.waitForTimeout(6000);
    exigir(t, evento(t, 'purchase').length === 1, 'recarregar o obrigado mandou outra doação');
    exigir(t, !tokenNosEventos(t), 'o token da doação (abre nome e e-mail) foi no id da compra no GA ou na Meta');
    if (tokenNoEndereco(t)) console.log('        aviso: o endereço da página (?t=<token>) vai no page_view do GA4 e no PageView da Meta');
  });

  console.log(falhas ? `${falhas} cenário(s) com falha` : 'Tudo certo.');
  process.exit(falhas ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
