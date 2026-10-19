/* Aviso de cookies do site (LGPD; Guia de Cookies da ANPD, 2022).
 *
 * Primeiro nível: uma faixa baixa, com uma frase curta, o link da Política de Cookies e três botões do
 * mesmo tamanho e estilo, lado a lado (Rejeitar, Personalizar, Aceitar todos). Não cobre a missão nem os
 * botões do topo da home: no celular fica em até 25% da tela; no computador, uma faixa de largura total. Fica
 * colada embaixo, ou logo abaixo do cabeçalho na página que pede (data-cvrj-ck-topo, a home).
 * Segundo nível ("Personalizar"): as categorias, com estatística e marketing desligados até a pessoa ligar,
 * e o detalhe de cada uma (o que vai à Meta pelo servidor está na categoria Marketing). Camadas, como admite
 * o Guia de Cookies da ANPD; o texto completo também está na Política de Cookies.
 * A escolha fica no cookie cvrj_consentimento (necessário), em todo *.cruzvermelhariodejaneiro.org,
 * por 12 meses: quem escolheu no site principal não é perguntado de novo nos subdomínios.
 *
 * Quem baixa o Google Analytics e o Pixel da Meta é o bloco de medição do <head>
 * (window.cvrjMedicao, copiado da home para todas as páginas): sem escolha, ou com "não", nada é
 * baixado. Este arquivo só pergunta, grava e avisa o bloco.
 *
 * "Preferências de cookies" (rodapé e Política de Cookies): qualquer elemento com data-cvrj-cookies
 * reabre as categorias. Textos em português, inglês e espanhol, pela língua da página (<html lang>).
 */
(function () {
  'use strict';
  if (window.cvrjConsentimento) return;

  var NOME = 'cvrj_consentimento';
  var VERSAO = '1';
  // Revisão do texto, gravada no cookie (r=). A partir da 2, o aviso informa a cópia dos eventos pelo servidor,
  // com nome, e-mail e telefone em código (desde 19/10/2026, na categoria Marketing do segundo nível e na
  // Política de Cookies; o primeiro nível nomeia a Meta e leva a esse detalhe). Quem ligou o marketing sem ela (no texto anterior, no aviso da
  // Punção ou num consentimento.js antigo guardado no cache) é perguntado de novo, e até lá o servidor não
  // manda nada à Meta (MCP_META_REVISAO, em api/lib/meta.php, é o mesmo número). A VERSAO do cookie não muda,
  // porque os outros sites (Punção, Redação, Escola) leem v=1 e ignoram o r.
  var REVISAO = 2;
  var VALIDADE_S = 365 * 24 * 60 * 60;
  var DOMINIO = /(^|\.)cruzvermelhariodejaneiro\.org$/i.test(location.hostname) ? '.cruzvermelhariodejaneiro.org' : '';
  // As políticas moram no site principal: num subdomínio (Impacto das Cores), o link leva o endereço completo.
  var ORIGEM = /^(www\.)?cruzvermelhariodejaneiro\.org$/i.test(location.hostname) || !DOMINIO ? '' : 'https://cruzvermelhariodejaneiro.org';

  var TEXTOS = {
    pt: {
      titulo: 'Sua privacidade',
      texto: 'Usamos cookies necessários e, com a sua permissão, de estatística (Google Analytics) e de marketing (Meta). Detalhes em “Personalizar” e na',
      politica: 'Política de Cookies', url: '/cookies/',
      rejeitar: 'Rejeitar', personalizar: 'Personalizar', aceitar: 'Aceitar todos',
      painel: 'Preferências de cookies',
      intro: 'Escolha quais cookies podemos usar. Os necessários ficam sempre ligados, porque sem eles o site não funciona. Você pode mudar a escolha quando quiser em “Preferências de cookies”, no rodapé.',
      categorias: {
        necessarios: ['Necessários', 'Fazem o site funcionar: guardam a sua escolha sobre cookies e a conversa do chat enquanto você navega.'],
        estatistica: ['Estatística', 'Google Analytics: conta as visitas e mostra quais páginas são lidas, sem identificar você pelo nome.'],
        marketing: ['Marketing', 'Pixel da Meta (Facebook e Instagram): mede o alcance e o resultado das nossas campanhas. O nosso servidor também envia esses eventos à Meta e, no chat e na inscrição, nome, e-mail e telefone em código (hash).']
      },
      sempre: 'Sempre ligados',
      rejeitarTudo: 'Rejeitar não necessários', salvar: 'Salvar escolhas', fechar: 'Fechar'
    },
    en: {
      titulo: 'Your privacy',
      texto: 'We use necessary cookies and, with your permission, statistics (Google Analytics) and marketing (Meta) cookies. Details under “Customize” and in our',
      politica: 'Cookie Policy', url: '/en/cookies/',
      rejeitar: 'Reject', personalizar: 'Customize', aceitar: 'Accept all',
      painel: 'Cookie preferences',
      intro: 'Choose which cookies we may use. Necessary cookies are always on, because the site does not work without them. You can change your choice at any time under “Cookie preferences” in the footer.',
      categorias: {
        necessarios: ['Necessary', 'Keep the site working: they store your cookie choice and the chat conversation while you browse.'],
        estatistica: ['Statistics', 'Google Analytics: counts visits and shows which pages are read, without identifying you by name.'],
        marketing: ['Marketing', 'Meta Pixel (Facebook and Instagram): measures the reach and results of our campaigns. Our server also sends these events to Meta and, in the chat and enrolment, your name, e-mail and phone number in coded form (hash).']
      },
      sempre: 'Always on',
      rejeitarTudo: 'Reject non-essential', salvar: 'Save choices', fechar: 'Close'
    },
    es: {
      titulo: 'Su privacidad',
      texto: 'Usamos cookies necesarias y, con su permiso, de estadística (Google Analytics) y de marketing (Meta). Detalles en “Personalizar” y en la',
      politica: 'Política de Cookies', url: '/es/cookies/',
      rejeitar: 'Rechazar', personalizar: 'Personalizar', aceitar: 'Aceptar todas',
      painel: 'Preferencias de cookies',
      intro: 'Elija qué cookies podemos usar. Las necesarias siempre están activas, porque sin ellas el sitio no funciona. Puede cambiar su elección cuando quiera en “Preferencias de cookies”, en el pie de página.',
      categorias: {
        necessarios: ['Necesarias', 'Hacen que el sitio funcione: guardan su elección sobre cookies y la conversación del chat mientras navega.'],
        estatistica: ['Estadística', 'Google Analytics: cuenta las visitas y muestra qué páginas se leen, sin identificarle por su nombre.'],
        marketing: ['Marketing', 'Píxel de Meta (Facebook e Instagram): mide el alcance y el resultado de nuestras campañas. Nuestro servidor también envía estos eventos a Meta y, en el chat y la inscripción, nombre, correo y teléfono en código (hash).']
      },
      sempre: 'Siempre activas',
      rejeitarTudo: 'Rechazar no necesarias', salvar: 'Guardar elección', fechar: 'Cerrar'
    }
  };

  var lang = (document.documentElement.getAttribute('lang') || 'pt').toLowerCase();
  var T = TEXTOS[lang.indexOf('en') === 0 ? 'en' : lang.indexOf('es') === 0 ? 'es' : 'pt'];
  var URL_POLITICA = ORIGEM + T.url;

  function ler() {
    var m = document.cookie.match(/(?:^|;\s*)cvrj_consentimento=([^;]+)/);
    if (!m) return null;
    var p = {};
    try {
      decodeURIComponent(m[1]).split('&').forEach(function (par) {
        var i = par.indexOf('=');
        if (i > 0) p[par.slice(0, i)] = par.slice(i + 1);
      });
    } catch (e) { return null; }
    if (p.v !== VERSAO) return null;
    return { estatistica: p.e === '1', marketing: p.m === '1', em: parseInt(p.t, 10) || 0, revisao: parseInt(p.r, 10) || 0 };
  }

  // Sem escolha, ou com marketing ligado num texto anterior: o aviso aparece de novo.
  function perguntar(c) { return !c || (c.marketing && c.revisao < REVISAO); }

  function gravar(c) {
    var valor = 'v=' + VERSAO + '&e=' + (c.estatistica ? 1 : 0) + '&m=' + (c.marketing ? 1 : 0) + '&t=' + Math.floor(Date.now() / 1000) + '&r=' + REVISAO;
    document.cookie = NOME + '=' + encodeURIComponent(valor) + '; Max-Age=' + VALIDADE_S + '; Path=/; SameSite=Lax' +
      (location.protocol === 'https:' ? '; Secure' : '') + (DOMINIO ? '; Domain=' + DOMINIO : '');
  }

  // Quem tira a permissão depois de dar: os cookies das ferramentas saem do navegador (os de
  // primeira parte, que o próprio site consegue apagar).
  function apagarCookiesDeMedicao(estatistica, marketing) {
    var nomes = document.cookie.split(';').map(function (c) { return c.split('=')[0].trim(); });
    nomes.forEach(function (n) {
      var ehEstatistica = n === '_ga' || n.indexOf('_ga_') === 0 || n === '_gid' || n.indexOf('_gat') === 0;
      var ehMarketing = n === '_fbp' || n === '_fbc';
      if ((ehEstatistica && !estatistica) || (ehMarketing && !marketing)) {
        ['', location.hostname, DOMINIO].forEach(function (d) {
          document.cookie = n + '=; Max-Age=0; Path=/' + (d ? '; Domain=' + d : '');
        });
      }
    });
  }

  function escolher(c) {
    gravar(c);
    apagarCookiesDeMedicao(c.estatistica, c.marketing);
    if (window.cvrjMedicao && typeof window.cvrjMedicao.aplicar === 'function') window.cvrjMedicao.aplicar(c);
    fecharTudo();
    try { document.dispatchEvent(new CustomEvent('cvrj:consentimento', { detail: c })); } catch (e) { /* navegador antigo */ }
  }

  var CSS = '' +
    '.cvrj-ck,.cvrj-ck-painel{font-family:inherit;color:#111;box-sizing:border-box}' +
    '.cvrj-ck *,.cvrj-ck-painel *{box-sizing:border-box}' +
    // Primeiro nível: faixa de largura total, colada embaixo, com a frase e os três botões numa linha (no
    // computador, cerca de 60 px de altura, abaixo dos botões do topo da home em 1280x720 e 1366x768).
    '.cvrj-ck{position:fixed;z-index:2147483000;left:0;right:0;bottom:0;display:flex;align-items:center;justify-content:center;gap:8px 20px;background:#fff;border-top:1px solid #dcdfe4;box-shadow:0 -8px 24px rgba(0,0,0,.12);padding:10px 16px calc(10px + env(safe-area-inset-bottom, 0px))}' +
    // No alto (data-cvrj-ck-topo): abaixo do cabeçalho, que continua por cima (o menu do celular abre sobre a faixa).
    '.cvrj-ck.cvrj-ck-alto{z-index:999;bottom:auto;border-top:0;border-bottom:1px solid #dcdfe4;box-shadow:0 8px 24px rgba(0,0,0,.12);padding-bottom:10px}' +
    '.cvrj-ck p{flex:0 1 auto;max-width:760px;margin:0;font-size:.86rem;line-height:1.45;color:#333}' +
    '.cvrj-ck .cvrj-ck-botoes{flex:0 0 auto;grid-template-columns:repeat(3,132px);margin:0}' +
    '.cvrj-ck .cvrj-ck-botoes button{min-height:40px;padding:8px 10px;font-size:.86rem}' +
    '.cvrj-ck-painel h2{margin:0 0 8px;font-size:1.05rem;line-height:1.3;font-weight:800;color:#111}' +
    '.cvrj-ck-painel p{margin:0 0 12px;font-size:.92rem;line-height:1.55;color:#333}' +
    '.cvrj-ck a,.cvrj-ck-painel a{color:#b00000;font-weight:700}' +
    '.cvrj-ck-botoes{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:4px}' +
    '.cvrj-ck-botoes button{min-height:44px;padding:10px 8px;border:1.5px solid #111;border-radius:10px;background:#fff;color:#111;font:inherit;font-size:.9rem;font-weight:700;line-height:1.2;cursor:pointer}' +
    '.cvrj-ck-botoes button:hover{background:#f3f4f6}' +
    '.cvrj-ck-botoes button:focus-visible,.cvrj-ck-painel input:focus-visible+span,.cvrj-ck-fechar:focus-visible{outline:3px solid #b00000;outline-offset:2px}' +
    '.cvrj-ck-fundo{position:fixed;inset:0;z-index:2147483001;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;padding:16px}' +
    '.cvrj-ck-painel{position:relative;background:#fff;border-radius:16px;max-width:560px;width:100%;max-height:calc(100vh - 32px);overflow:auto;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.3)}' +
    '.cvrj-ck-fechar{position:absolute;top:10px;right:10px;width:40px;height:40px;border:0;background:transparent;font-size:1.5rem;line-height:1;cursor:pointer;color:#111;border-radius:8px}' +
    '.cvrj-ck-cat{border:1px solid #e3e5e8;border-radius:12px;padding:14px 16px;margin:0 0 10px}' +
    '.cvrj-ck-cat label{display:flex;align-items:center;justify-content:space-between;gap:12px;font-weight:800;font-size:.95rem;cursor:pointer}' +
    '.cvrj-ck-cat p{margin:6px 0 0;font-size:.86rem}' +
    '.cvrj-ck-cat input{position:absolute;opacity:0;width:1px;height:1px}' +
    '.cvrj-ck-cat span.cvrj-ck-chave{flex:0 0 auto;width:44px;height:26px;border-radius:26px;background:#c4c8ce;position:relative;transition:background .15s}' +
    '.cvrj-ck-cat span.cvrj-ck-chave:after{content:"";position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;transition:transform .15s}' +
    '.cvrj-ck-cat input:checked+span.cvrj-ck-chave{background:#b00000}' +
    '.cvrj-ck-cat input:checked+span.cvrj-ck-chave:after{transform:translateX(18px)}' +
    '.cvrj-ck-cat input:disabled+span.cvrj-ck-chave{opacity:.55}' +
    '.cvrj-ck-sempre{font-size:.78rem;font-weight:700;color:#555;margin-left:auto;margin-right:8px}' +
    // Celular: a frase em cima e os três botões embaixo, ainda lado a lado e do mesmo tamanho (em 360x740, cerca
    // de 18% da tela). Só o painel do segundo nível empilha os botões, que lá têm rótulos longos.
    '@media (max-width:760px){.cvrj-ck{flex-direction:column;align-items:stretch;padding:10px 12px calc(10px + env(safe-area-inset-bottom, 0px))}.cvrj-ck p{max-width:none}.cvrj-ck .cvrj-ck-botoes{grid-template-columns:repeat(3,1fr);gap:6px}.cvrj-ck .cvrj-ck-botoes button{min-height:44px;padding:6px 4px;font-size:.84rem}}' +
    '@media (max-width:560px){.cvrj-ck-painel .cvrj-ck-botoes{grid-template-columns:1fr}.cvrj-ck-painel{padding:20px 16px}}' +
    '@media print{.cvrj-ck,.cvrj-ck-fundo{display:none!important}}';

  function el(tag, attrs, filhos) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'texto') n.textContent = attrs[k];
      else n.setAttribute(k, attrs[k]);
    });
    (filhos || []).forEach(function (f) { if (f) n.appendChild(typeof f === 'string' ? document.createTextNode(f) : f); });
    return n;
  }

  function botao(texto, acao) {
    var b = el('button', { type: 'button', texto: texto });
    b.addEventListener('click', acao);
    return b;
  }

  function estilos() {
    if (document.getElementById('cvrj-ck-css')) return;
    var s = el('style', { id: 'cvrj-ck-css' });
    s.textContent = CSS;
    document.head.appendChild(s);
  }

  var aviso = null, fundo = null, voltarFoco = null;

  // Altura da faixa em --cvrj-ck-altura no <html>: o chat (site/chat/chat.css) sobe acima dela no computador,
  // em vez de ficar meio escondido atrás. Sai junto com a faixa.
  // Página que marca um elemento com data-cvrj-ck-topo (a home, no topo): a faixa fica logo abaixo do cabeçalho
  // fixo, sobre o respiro acima do título, e não sobre a missão e os botões "Doe agora" e "Seja voluntário",
  // que ficam na base da primeira tela (em 1280x720, a 632 px do topo). Nas outras páginas, embaixo.
  function noAlto() { return !!document.querySelector('[data-cvrj-ck-topo]'); }
  function marcarAltura() {
    var raiz = document.documentElement;
    if (aviso && noAlto()) {
      var cab = document.querySelector('.main-header');
      aviso.style.top = (cab ? Math.max(0, cab.getBoundingClientRect().bottom) : 0) + 'px';
      raiz.style.removeProperty('--cvrj-ck-altura');
    } else if (aviso) raiz.style.setProperty('--cvrj-ck-altura', aviso.offsetHeight + 'px');
    else raiz.style.removeProperty('--cvrj-ck-altura');
  }
  window.addEventListener('resize', function () { if (aviso) marcarAltura(); });
  window.addEventListener('scroll', function () { if (aviso && noAlto()) marcarAltura(); }, { passive: true });

  function tirarAviso() {
    if (aviso) { aviso.remove(); aviso = null; }
    marcarAltura();
  }

  function fecharTudo() {
    tirarAviso();
    if (fundo) { fundo.remove(); fundo = null; document.removeEventListener('keydown', teclado, true); }
    if (voltarFoco && document.contains(voltarFoco)) { try { voltarFoco.focus(); } catch (e) { /* segue */ } }
    voltarFoco = null;
  }

  function mostrarAviso() {
    if (aviso || fundo) return;
    estilos();
    // Sem título visível (a faixa precisa caber em uma ou duas linhas): o nome da região vai no aria-label.
    aviso = el('section', { class: 'cvrj-ck' + (noAlto() ? ' cvrj-ck-alto' : ''), role: 'region', 'aria-label': T.titulo }, [
      el('p', {}, [T.texto + ' ', el('a', { href: URL_POLITICA, texto: T.politica }), '.']),
      el('div', { class: 'cvrj-ck-botoes' }, [
        botao(T.rejeitar, function () { escolher({ estatistica: false, marketing: false }); }),
        botao(T.personalizar, function () { abrirPainel(); }),
        botao(T.aceitar, function () { escolher({ estatistica: true, marketing: true }); })
      ])
    ]);
    document.body.appendChild(aviso);
    marcarAltura();
  }

  function categoria(chave, ligada, fixa) {
    var c = T.categorias[chave];
    var entrada = el('input', { type: 'checkbox', role: 'switch', id: 'cvrj-ck-' + chave });
    entrada.checked = !!ligada;
    if (fixa) entrada.disabled = true;
    var rotulo = el('label', { for: 'cvrj-ck-' + chave }, [
      el('span', { texto: c[0] }),
      fixa ? el('span', { class: 'cvrj-ck-sempre', texto: T.sempre }) : null,
      entrada,
      el('span', { class: 'cvrj-ck-chave', 'aria-hidden': 'true' })
    ]);
    return el('div', { class: 'cvrj-ck-cat' }, [rotulo, el('p', { texto: c[1] })]);
  }

  // Fechar o painel sem escolher: o foco volta para onde estava, e quem ainda não escolheu (ou precisa
  // escolher de novo) volta a ver o aviso.
  function fecharPainel() {
    if (!fundo) return;
    fundo.remove(); fundo = null;
    document.removeEventListener('keydown', teclado, true);
    var foco = voltarFoco;
    voltarFoco = null;
    if (perguntar(ler())) mostrarAviso();
    else if (foco && document.contains(foco)) { try { foco.focus(); } catch (e) { /* segue */ } }
  }

  function teclado(e) {
    if (!fundo) return;
    if (e.key === 'Escape') {
      e.preventDefault();
      fecharPainel();
      return;
    }
    if (e.key !== 'Tab') return;
    var focaveis = fundo.querySelectorAll('button, a[href], input:not([disabled])');
    if (!focaveis.length) return;
    var primeiro = focaveis[0], ultimo = focaveis[focaveis.length - 1];
    if (e.shiftKey && document.activeElement === primeiro) { e.preventDefault(); ultimo.focus(); }
    else if (!e.shiftKey && document.activeElement === ultimo) { e.preventDefault(); primeiro.focus(); }
  }

  function abrirPainel() {
    estilos();
    tirarAviso();
    if (fundo) return;
    voltarFoco = document.activeElement;
    var atual = ler() || { estatistica: false, marketing: false };
    // Marketing ligado num texto anterior não vale para este: a chave começa desligada (nada pré-marcado).
    if (perguntar(atual)) atual = { estatistica: atual.estatistica, marketing: false };
    var catEstatistica = categoria('estatistica', atual.estatistica);
    var catMarketing = categoria('marketing', atual.marketing);
    var painel = el('div', { class: 'cvrj-ck-painel', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'cvrj-ck-t2' }, [
      botao('×', fecharPainel),
      el('h2', { id: 'cvrj-ck-t2', texto: T.painel }),
      el('p', {}, [T.intro + ' ', el('a', { href: URL_POLITICA, texto: T.politica }), '.']),
      categoria('necessarios', true, true),
      catEstatistica,
      catMarketing,
      el('div', { class: 'cvrj-ck-botoes' }, [
        botao(T.rejeitarTudo, function () { escolher({ estatistica: false, marketing: false }); }),
        botao(T.salvar, function () {
          escolher({
            estatistica: catEstatistica.querySelector('input').checked,
            marketing: catMarketing.querySelector('input').checked
          });
        }),
        botao(T.aceitar, function () { escolher({ estatistica: true, marketing: true }); })
      ])
    ]);
    var fechar = painel.firstChild;
    fechar.className = 'cvrj-ck-fechar';
    fechar.setAttribute('aria-label', T.fechar);
    fundo = el('div', { class: 'cvrj-ck-fundo' }, [painel]);
    fundo.addEventListener('click', function (e) { if (e.target === fundo) fecharPainel(); });
    document.body.appendChild(fundo);
    document.addEventListener('keydown', teclado, true);
    var primeiraChave = painel.querySelector('input:not([disabled])');
    if (primeiraChave) primeiraChave.focus();
  }

  // "Preferências de cookies": qualquer elemento com data-cvrj-cookies abre as categorias.
  document.addEventListener('click', function (e) {
    var alvo = e.target && e.target.closest ? e.target.closest('[data-cvrj-cookies]') : null;
    if (!alvo) return;
    e.preventDefault();
    abrirPainel();
  });

  window.cvrjConsentimento = { ler: ler, abrir: abrirPainel };

  function iniciar() {
    if (perguntar(ler())) mostrarAviso();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();
})();
