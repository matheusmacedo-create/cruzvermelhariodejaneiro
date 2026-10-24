#!/usr/bin/env python3
"""Gera site/pedido-de-documentos/: o pedido de documentos da filial (17/10/2026).

Regras do Matheus: tudo por e-mail; quem pede comprova quem é e diz para que quer o documento; a página
avisa quem pode ou não receber cada coisa. O envio vai para api/documentos.php (multipart), que manda os
comprovantes só por e-mail à equipe, sem gravá-los (motivos em api/lib/documentos.php).

Página que recebe documento de identidade: nada de terceiros. Sem GA4, sem Meta Pixel, sem Google Fonts
(a "Inter Reserva" do CSS da home segura o texto) e sem o chat, como em /verificar/. Referrer-Policy
same-origin na meta tag.

As listas de documentos e de perfis repetem as de api/lib/documentos.php: o servidor recusa chave que
não conhece, e o teste no fim deste arquivo confere que as duas listas batem.

Cabeçalho, rodapé, CSS e o script do menu vêm de site/index.html, como nos outros geradores.

Uso:  python3 scripts/gerar_pedido_documentos.py
"""
from __future__ import annotations

import re

import icones
from gerar_matricula_presencial import HOME, RAIZ, esc, partes_da_home

PASTA = RAIZ / "site" / "pedido-de-documentos"
SAIDA = PASTA / "index.html"
LIB_PHP = RAIZ / "site" / "matricula-cursos-presenciais" / "api" / "lib" / "documentos.php"
ORIGEM = "https://cruzvermelhariodejaneiro.org"
URL_PAGINA = f"{ORIGEM}/pedido-de-documentos/"
API = "/matricula-cursos-presenciais/api/documentos.php"
TITULO = "Pedido de documentos – Cruz Vermelha Brasileira RJ"
DESCRICAO = ("Como pedir a íntegra de um documento da Cruz Vermelha Brasileira Rio de Janeiro: quem pode receber, "
             "o que comprovar e como enviar o pedido. Resposta por e-mail.")
PRAZO = "20 dias"
ARQUIVOS_MAX = 4
ARQUIVO_MB = 8
TOTAL_MB = 16

DOCUMENTOS = [
    ("estatuto", "Estatuto da Cruz Vermelha Brasileira do Rio de Janeiro (24/10/2016)"),
    ("ata-notarial-2026-02-02", "Escritura pública de ata notarial (02/02/2026)"),
    ("outro", "Outro documento da filial"),
]
# chave, rótulo no formulário, o que comprovar, o que pode receber
PERFIS = [
    ("titular", "Sou a pessoa citada no documento (titular dos dados)", "Documento de identidade com foto.",
     "A íntegra do que diz respeito a você."),
    ("representante", "Represento a pessoa citada (advogado ou procurador)", "Documento de identidade com foto e procuração.",
     "A íntegra do que diz respeito a quem você representa, nos limites da procuração."),
    ("orgao", "Falo em nome de órgão público, de controle ou do Judiciário", "Ofício ou requisição do órgão e documento funcional.",
     "A íntegra, quando houver ofício ou requisição formal."),
    ("membro", "Sou membro ou associado da filial", "Documento de identidade com foto.",
     "O que o Estatuto garante aos membros. Dados pessoais de outras pessoas, só com base legal."),
    ("outro", "Outra pessoa ou instituição (imprensa, pesquisa, empresa...)", "Documento de identidade com foto e, se for o caso, o da instituição.",
     "A versão publicada no portal da transparência e a indicação de onde tirar certidão."),
]

CSS = """  <style>
    /* Bloco de ação no fim da página (19/10/2026, Ad Grants): doar, ser voluntário ou falar com a filial. */
    .fim-acao { padding: 8px 0 52px; }
    .fim-acao-caixa { max-width: 860px; margin: 0 auto; padding: 26px 28px; border: 1px solid var(--line); border-left: 5px solid var(--red); border-radius: 14px; background: var(--paper); }
    .fim-acao h2 { font-size: 1.35rem; letter-spacing: -.01em; margin: 0 0 8px; }
    .fim-acao p { color: var(--muted); margin: 0 0 18px; max-width: 64ch; }
    @media (max-width: 620px) { .fim-acao-caixa { padding: 22px 18px; } }
    .pd-topo { background: var(--soft); padding: 56px 0 44px; }
    .pd-topo h1 { color: var(--black); font-size: clamp(2rem, 4.4vw, 3rem); line-height: 1.1; margin: .2rem 0 1rem; }
    .pd-topo .lead { max-width: 62ch; }
    .pd-fatos { list-style: none; padding: 0; margin: 26px 0 0; display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
    .pd-fatos li { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 16px; display: flex; gap: 12px; align-items: flex-start; }
    .pd-fatos i { color: var(--red); font-size: 1.3rem; margin-top: 2px; flex-shrink: 0; }
    .pd-fatos b { display: block; font-size: .98rem; }
    .pd-fatos span { color: #5b6576; font-size: .9rem; line-height: 1.45; }
    .pd-secao { padding: 52px 0; }
    .pd-secao + .pd-secao { border-top: 1px solid var(--line); }
    .pd-secao h2 { font-size: clamp(1.5rem, 3vw, 2.1rem); margin: .2rem 0 .8rem; }
    .pd-caminhos { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-top: 22px; }
    .pd-caminho { border: 1px solid var(--line); border-radius: 12px; padding: 20px; background: #fff; }
    .pd-caminho h3 { margin: 0 0 .4rem; font-size: 1.08rem; }
    .pd-caminho p { margin: 0 0 .7rem; color: #4a5568; line-height: 1.55; }
    .pd-tabela-rolagem { overflow-x: auto; margin-top: 22px; border: 1px solid var(--line); border-radius: 12px; }
    .pd-tabela { width: 100%; border-collapse: collapse; min-width: 640px; font-size: .95rem; }
    .pd-tabela th, .pd-tabela td { text-align: left; padding: 14px 16px; vertical-align: top; border-bottom: 1px solid var(--line); line-height: 1.5; }
    .pd-tabela thead th { background: var(--soft); font-size: .85rem; text-transform: uppercase; letter-spacing: .04em; color: #4a5568; }
    .pd-tabela tbody tr:last-child td { border-bottom: 0; }
    .pd-tabela td:first-child { font-weight: 700; width: 30%; }
    .pd-aviso { display: flex; gap: 12px; background: #fff7f7; border: 1px solid #f5c2c7; border-radius: 12px; padding: 16px 18px; margin-top: 20px; line-height: 1.55; }
    .pd-aviso i { color: var(--red); font-size: 1.2rem; margin-top: 3px; flex-shrink: 0; }
    .pd-form-grade { display: grid; grid-template-columns: 1.25fr .75fr; gap: 36px; align-items: start; }
    .pd-form { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 18px; background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 26px; box-shadow: 0 10px 28px rgba(0,0,0,.06); }
    .pd-campo { display: grid; gap: 6px; }
    .pd-campo-largo { grid-column: 1 / -1; }
    .pd-campo[hidden], .pd-erro[hidden], .pd-ok[hidden], .pd-form[hidden] { display: none; }
    .pd-campo label, .pd-campo legend { font-weight: 700; font-size: .93rem; }
    .pd-campo small { font-weight: 400; color: #5b6576; }
    .pd-campo input, .pd-campo select, .pd-campo textarea { width: 100%; min-height: 46px; border: 1px solid #cbd5e0; border-radius: 10px; padding: 10px 12px; font: inherit; background: #fff; color: inherit; }
    .pd-campo textarea { min-height: 120px; resize: vertical; }
    .pd-campo input:focus, .pd-campo select:focus, .pd-campo textarea:focus { outline: 3px solid rgba(204,0,0,.25); border-color: var(--red); }
    .pd-campo [aria-invalid="true"] { border-color: var(--red); background: #fff7f7; }
    .pd-dica { color: #5b6576; font-size: .86rem; line-height: 1.45; margin: 0; }
    .pd-arquivos { position: relative; border: 2px dashed #cbd5e0; border-radius: 12px; padding: 22px 16px; text-align: center; background: var(--soft); transition: border-color .15s, background .15s; }
    .pd-arquivos.pd-arrastando { border-color: var(--red); background: #fff7f7; }
    .pd-arquivos input { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
    .pd-arquivos i { color: var(--red); font-size: 1.6rem; }
    .pd-arquivos p { margin: .4rem 0 0; }
    .pd-arquivos:focus-within { outline: 3px solid rgba(204,0,0,.25); }
    .pd-lista-arquivos { list-style: none; padding: 0; margin: 8px 0 0; display: grid; gap: 6px; }
    .pd-lista-arquivos li { display: flex; justify-content: space-between; gap: 10px; background: #fff; border: 1px solid var(--line); border-radius: 8px; padding: 8px 12px; font-size: .9rem; }
    .pd-lista-arquivos span:last-child { color: #5b6576; white-space: nowrap; }
    .pd-confirmar { display: flex; gap: 10px; align-items: flex-start; font-size: .93rem; line-height: 1.5; }
    .pd-confirmar input { width: 20px; height: 20px; min-height: 0; margin-top: 2px; flex-shrink: 0; accent-color: var(--red); }
    .pd-armadilha { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
    .pd-erro { grid-column: 1 / -1; margin: 0; color: #9b1c1c; background: #fff5f5; border: 1px solid #feb2b2; border-radius: 10px; padding: 12px 14px; }
    .pd-enviar { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
    .pd-enviar .btn[disabled] { opacity: .65; cursor: wait; }
    .pd-ok { background: #f0fff4; border: 1px solid #9ae6b4; border-radius: 14px; padding: 26px; }
    .pd-ok i { color: #2f855a; font-size: 2rem; }
    .pd-ok h3 { margin: .4rem 0 .5rem; }
    .pd-lado { display: grid; gap: 16px; }
    .pd-lado .pd-caminho h3 { display: flex; gap: 10px; align-items: center; }
    .pd-lado .pd-caminho i { color: var(--red); }
    @media (max-width: 920px) {
      .pd-fatos, .pd-caminhos { grid-template-columns: 1fr; }
      .pd-form-grade { grid-template-columns: 1fr; }
    }
    @media (max-width: 620px) {
      .pd-form { grid-template-columns: 1fr; padding: 18px; }
      .pd-enviar .btn { width: 100%; }
      .pd-tabela-rolagem { border: 0; overflow: visible; }
      .pd-tabela { min-width: 0; }
      .pd-tabela thead { display: none; }
      .pd-tabela tr { display: block; border: 1px solid var(--line); border-radius: 12px; margin-bottom: 12px; padding: 4px 0; }
      .pd-tabela td, .pd-tabela td:first-child { display: block; width: auto; border: 0; padding: 8px 14px; }
      .pd-tabela td[data-rotulo]::before { content: attr(data-rotulo); display: block; font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #5b6576; margin-bottom: 2px; }
    }
  </style>"""

JS = """  <script>
  (function () {
    var form = document.getElementById('pd-form');
    if (!form) return;
    var API = '__API__', MAX = __MAX__, MAX_MB = __MB__, TOTAL_MB = __TOTAL__;
    var perfil = form.elements.perfil, documento = form.elements.documento;
    var campoInst = document.getElementById('pd-campo-instituicao'), campoDesc = document.getElementById('pd-campo-descricao');
    var dicaComprovar = document.getElementById('pd-comprovar');
    var comprovar = __COMPROVAR__;
    var entrada = document.getElementById('pd-comprovantes'), lista = document.getElementById('pd-lista-arquivos');
    var zona = entrada.closest('.pd-arquivos'), erro = form.querySelector('.pd-erro'), botao = form.querySelector('button[type=submit]');
    var ok = document.querySelector('.pd-ok');

    function atualizar() {
      var p = perfil.value;
      campoInst.hidden = !(p === 'orgao' || p === 'representante' || p === 'outro');
      form.elements.instituicao.required = p === 'orgao';
      document.getElementById('pd-instituicao-rotulo').textContent = p === 'representante' ? 'Quem você representa' : (p === 'orgao' ? 'Órgão' : 'Instituição (se houver)');
      dicaComprovar.textContent = comprovar[p] ? 'Para o seu caso: ' + comprovar[p] : 'Escolha acima em nome de quem você pede para ver o que anexar.';
      campoDesc.hidden = documento.value !== 'outro';
      form.elements.descricao.required = documento.value === 'outro';
    }
    function tamanho(b) { return b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
    function mostrarArquivos() {
      lista.textContent = '';
      Array.prototype.forEach.call(entrada.files, function (f) {
        var li = document.createElement('li'), a = document.createElement('span'), b = document.createElement('span');
        a.textContent = f.name; b.textContent = tamanho(f.size); li.appendChild(a); li.appendChild(b); lista.appendChild(li);
      });
    }
    function conferirArquivos() {
      var fs = entrada.files, total = 0;
      if (!fs.length) return 'Anexe o seu documento de identidade com foto (e os outros comprovantes, se for o caso).';
      if (fs.length > MAX) return 'Envie no máximo ' + MAX + ' arquivos.';
      for (var i = 0; i < fs.length; i++) {
        if (!/\\.(pdf|jpe?g|png)$/i.test(fs[i].name)) return 'Envie os comprovantes em PDF, JPG ou PNG.';
        if (fs[i].size > MAX_MB * 1048576) return 'Cada arquivo pode ter até ' + MAX_MB + ' MB.';
        total += fs[i].size;
      }
      if (total > TOTAL_MB * 1048576) return 'Os arquivos somados podem ter até ' + TOTAL_MB + ' MB.';
      return '';
    }
    function falha(msg, campo) {
      erro.textContent = msg; erro.hidden = false;
      Array.prototype.forEach.call(form.querySelectorAll('[aria-invalid]'), function (el) { el.removeAttribute('aria-invalid'); });
      var el = campo && (campo === 'comprovantes' ? entrada : form.elements[campo]);
      if (el && el.setAttribute) { el.setAttribute('aria-invalid', 'true'); el.focus(); } else { erro.focus(); }
    }

    perfil.addEventListener('change', atualizar);
    documento.addEventListener('change', atualizar);
    entrada.addEventListener('change', mostrarArquivos);
    ['dragenter', 'dragover'].forEach(function (t) { zona.addEventListener(t, function () { zona.classList.add('pd-arrastando'); }); });
    ['dragleave', 'drop'].forEach(function (t) { zona.addEventListener(t, function () { zona.classList.remove('pd-arrastando'); }); });
    atualizar();

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      erro.hidden = true;
      if (!form.checkValidity()) {
        var invalido = form.querySelector(':invalid'), rotulo = form.querySelector('label[for="' + invalido.id + '"]');
        var nomeCampo = rotulo ? rotulo.firstChild.textContent.trim() : '';
        var msg = invalido.type === 'checkbox' ? 'Marque as duas confirmações antes de enviar.'
          : invalido.type === 'email' && invalido.value ? 'Esse e-mail não parece válido.'
          : invalido.validity.tooShort ? 'O campo "' + nomeCampo + '" precisa de pelo menos ' + invalido.minLength + ' caracteres.'
          : 'Preencha o campo "' + nomeCampo + '".';
        falha(msg, invalido.name === 'comprovantes[]' ? 'comprovantes' : invalido.name);
        return;
      }
      var problema = conferirArquivos();
      if (problema) { falha(problema, 'comprovantes'); return; }
      botao.disabled = true; botao.lastChild.textContent = 'Enviando...';
      fetch(API, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { ok: false, erro: 'Resposta inesperada do servidor. Tente de novo.' }; }); })
        .then(function (d) {
          if (!d.ok) { falha(d.erro || 'Não foi possível enviar. Tente de novo.', d.campo); return; }
          form.hidden = true; ok.hidden = false;
          ok.querySelector('[data-pd-protocolo]').textContent = d.protocolo;
          ok.querySelector('[data-pd-email]').textContent = d.email;
          ok.querySelector('[data-pd-prazo]').textContent = d.prazo;
          ok.focus();
        })
        .catch(function () { falha('Sem conexão com o servidor. Confira a internet e tente de novo.'); })
        .then(function () { botao.disabled = false; botao.lastChild.textContent = 'Enviar pedido'; });
    });
  })();
  </script>"""


def conferir_listas() -> None:
    """As chaves daqui têm de ser as mesmas de api/lib/documentos.php (o servidor recusa as outras)."""
    php = LIB_PHP.read_text(encoding="utf-8")
    bloco_docs = php[php.index("function mcp_doc_documentos"):php.index("function mcp_doc_perfis")]
    bloco_perfis = php[php.index("function mcp_doc_perfis"):php.index("function mcp_doc_orientacao")]
    docs_php = re.findall(r"'([a-z0-9-]+)' => '", bloco_docs)
    perfis_php = re.findall(r"^        '([a-z]+)' => \[", bloco_perfis, re.M)
    if docs_php != [k for k, _ in DOCUMENTOS]:
        raise SystemExit(f"documentos diferentes do PHP: {docs_php}")
    if perfis_php != [k for k, *_ in PERFIS]:
        raise SystemExit(f"perfis diferentes do PHP: {perfis_php}")
    for nome, valor in (("PRAZO", f"'{PRAZO}'"), ("ARQUIVOS_MAX", str(ARQUIVOS_MAX)),
                        ("ARQUIVO_BYTES", f"{ARQUIVO_MB} * 1024 * 1024"), ("TOTAL_BYTES", f"{TOTAL_MB} * 1024 * 1024")):
        if f"const MCP_DOC_{nome} = {valor};" not in php:
            raise SystemExit(f"MCP_DOC_{nome} no PHP não bate com o gerador ({valor})")


def main() -> int:
    conferir_listas()
    partes = partes_da_home(HOME.read_text(encoding="utf-8"))
    header = partes["header"].replace(' aria-current="page"', "")

    opcoes_perfil = "\n".join(f'              <option value="{k}">{esc(r)}</option>' for k, r, *_ in PERFIS)
    opcoes_doc = "\n".join(f'              <option value="{k}">{esc(r)}</option>' for k, r in DOCUMENTOS)
    linhas = "\n".join(
        f'            <tr><td>{esc(r.split(" (")[0])}</td><td data-rotulo="O que comprovar">{esc(c)}</td><td data-rotulo="O que pode receber">{esc(p)}</td></tr>' for _, r, c, p in PERFIS)
    import json
    comprovar = json.dumps({k: c for k, _, c, _ in PERFIS}, ensure_ascii=False)
    js = (JS.replace("__API__", API).replace("__MAX__", str(ARQUIVOS_MAX)).replace("__MB__", str(ARQUIVO_MB))
          .replace("__TOTAL__", str(TOTAL_MB)).replace("__COMPROVAR__", comprovar))

    pagina = f"""<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="referrer" content="same-origin">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <title>{esc(TITULO)}</title>
  <meta name="description" content="{esc(DESCRICAO)}">
  <link rel="canonical" href="{URL_PAGINA}">
  <meta name="robots" content="index, follow">
  <meta property="og:type" content="website">
  <meta property="og:locale" content="pt_BR">
  <meta property="og:site_name" content="Cruz Vermelha Brasileira Rio de Janeiro">
  <meta property="og:title" content="{esc(TITULO)}">
  <meta property="og:description" content="{esc(DESCRICAO)}">
  <meta property="og:url" content="{URL_PAGINA}">
  <meta property="og:image" content="{ORIGEM}/assets/otim/og-home.jpg">
  <!-- Estilos copiados da home (site/index.html), com a "Inter Reserva": sem Google Fonts nesta página. -->
{partes["estilo"]}
{CSS}
</head>
<body>
{header}

  <main id="pedido-de-documentos">
    <section class="pd-topo" aria-labelledby="pd-titulo">
      <div class="wrap">
        <p class="eyebrow">Transparência</p>
        <h1 id="pd-titulo">Pedido de documentos da filial</h1>
        <p class="lead">Os documentos públicos da Cruz Vermelha Brasileira Rio de Janeiro ficam no <a href="/transparencia/">portal da transparência</a>, para baixar e conferir. Esta página é para pedir a íntegra de um documento, com dados pessoais, ou um documento que não está publicado. A equipe analisa cada pedido e responde por e-mail.</p>
        <ul class="pd-fatos">
          <li><i class="fa-solid fa-envelope" aria-hidden="true"></i><div><b>Tudo por e-mail</b><span>Resposta em até {PRAZO}, com número de protocolo.</span></div></li>
          <li><i class="fa-solid fa-fingerprint" aria-hidden="true"></i><div><b>Comprove quem você é</b><span>Documento com foto e o motivo do pedido. Procuração ou ofício, se for o caso.</span></div></li>
          <li><i class="fa-solid fa-lock" aria-hidden="true"></i><div><b>Seus arquivos protegidos</b><span>Vão direto para a equipe e não ficam guardados no site.</span></div></li>
        </ul>
      </div>
    </section>

    <section class="pd-secao" aria-labelledby="pd-antes-titulo">
      <div class="wrap">
        <p class="eyebrow">Antes de pedir</p>
        <h2 id="pd-antes-titulo">Talvez você não precise fazer o pedido</h2>
        <div class="pd-caminhos">
          <article class="pd-caminho">
            <h3>Versão pública, na hora</h3>
            <p>O estatuto e a ata notarial estão no portal da transparência, com impressão digital (SHA-256) e código de verificação.</p>
            <a class="btn btn-outline" href="/transparencia/">Abrir o portal da transparência</a>
          </article>
          <article class="pd-caminho">
            <h3>Certidão oficial, no cartório</h3>
            <p>A ata notarial é uma escritura pública: a certidão pode ser pedida ao 12º Serviço Notarial do Rio de Janeiro (Rua do Rosário, 134, Centro). O estatuto registrado pode ser pedido ao cartório de registro de pessoas jurídicas onde está registrado. Nos dois casos, quem emite e cobra é o cartório.</p>
          </article>
        </div>
      </div>
    </section>

    <section class="pd-secao" aria-labelledby="pd-quem-titulo">
      <div class="wrap">
        <p class="eyebrow">Quem pode pedir o quê</p>
        <h2 id="pd-quem-titulo">A íntegra com dados pessoais vai só para quem tem direito a ela</h2>
        <p class="lead">Qualquer pessoa pode fazer o pedido. O que a filial pode enviar depende de quem pede e para quê, porque os documentos têm dados pessoais de dirigentes e de outras pessoas, protegidos pela Lei Geral de Proteção de Dados (Lei 13.709/2018).</p>
        <div class="pd-tabela-rolagem">
          <table class="pd-tabela">
            <thead><tr><th scope="col">Quem pede</th><th scope="col">O que comprovar</th><th scope="col">O que pode receber</th></tr></thead>
            <tbody>
{linhas}
            </tbody>
          </table>
        </div>
        <p class="pd-aviso"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Pedido sem comprovação de identidade, com documento ilegível ou sem motivo não é atendido até ser completado: nesse caso, pedimos por e-mail o que falta. Quando não pudermos enviar a íntegra, explicamos o motivo e indicamos a versão pública ou o cartório.</span></p>
      </div>
    </section>

    <section class="pd-secao" id="fazer-pedido" aria-labelledby="pd-form-titulo">
      <div class="wrap">
        <p class="eyebrow">Fazer o pedido</p>
        <h2 id="pd-form-titulo">Envie o seu pedido</h2>
        <div class="pd-form-grade">
          <div>
            <form class="pd-form" id="pd-form" novalidate enctype="multipart/form-data">
              <div class="pd-campo pd-campo-largo"><label for="pd-nome">Nome completo <small>(como está no documento)</small></label><input id="pd-nome" name="nome" autocomplete="name" required minlength="5" maxlength="120"></div>
              <div class="pd-campo"><label for="pd-email">E-mail <small>(a resposta vai para ele)</small></label><input id="pd-email" name="email" type="email" autocomplete="email" required maxlength="190"></div>
              <div class="pd-campo"><label for="pd-telefone">Telefone <small>(opcional)</small></label><input id="pd-telefone" name="telefone" type="tel" autocomplete="tel" inputmode="tel" maxlength="30"></div>
              <div class="pd-campo pd-campo-largo"><label for="pd-perfil">Em nome de quem você pede?</label>
                <select id="pd-perfil" name="perfil" required>
              <option value="" selected disabled>Escolha uma opção</option>
{opcoes_perfil}
                </select>
              </div>
              <div class="pd-campo pd-campo-largo" id="pd-campo-instituicao" hidden><label for="pd-instituicao" id="pd-instituicao-rotulo">Instituição</label><input id="pd-instituicao" name="instituicao" autocomplete="organization" maxlength="160"></div>
              <div class="pd-campo pd-campo-largo"><label for="pd-documento">Qual documento?</label>
                <select id="pd-documento" name="documento" required>
              <option value="" selected disabled>Escolha o documento</option>
{opcoes_doc}
                </select>
              </div>
              <div class="pd-campo pd-campo-largo" id="pd-campo-descricao" hidden><label for="pd-descricao">Descreva o documento <small>(nome, data ou assunto)</small></label><input id="pd-descricao" name="descricao" maxlength="300"></div>
              <div class="pd-campo pd-campo-largo"><label for="pd-finalidade">Para que você precisa do documento?</label><textarea id="pd-finalidade" name="finalidade" required minlength="20" maxlength="2000" placeholder="Ex.: processo judicial, auditoria, regularização junto a um órgão, pesquisa..."></textarea></div>
              <div class="pd-campo pd-campo-largo">
                <label for="pd-comprovantes">Comprovantes <small>(até {ARQUIVOS_MAX} arquivos em PDF, JPG ou PNG, até {ARQUIVO_MB} MB cada)</small></label>
                <div class="pd-arquivos">
                  <i class="fa-solid fa-file-arrow-up" aria-hidden="true"></i>
                  <p><b>Toque para escolher</b> ou arraste os arquivos para cá</p>
                  <input id="pd-comprovantes" name="comprovantes[]" type="file" multiple required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" aria-describedby="pd-comprovar">
                </div>
                <p class="pd-dica" id="pd-comprovar">Escolha acima em nome de quem você pede para ver o que anexar.</p>
                <ul class="pd-lista-arquivos" id="pd-lista-arquivos" aria-live="polite"></ul>
              </div>
              <label class="pd-confirmar pd-campo-largo"><input type="checkbox" name="declaracao" value="sim" required><span>Declaro que as informações e os documentos enviados são verdadeiros e que sou a pessoa (ou represento a instituição) indicada acima.</span></label>
              <label class="pd-confirmar pd-campo-largo"><input type="checkbox" name="privacidade" value="sim" required><span>Li como a filial usa os meus documentos: só para analisar este pedido, como explicado ao lado e na <a href="/privacidade/">Política de Privacidade</a>.</span></label>
              <div class="pd-armadilha" aria-hidden="true"><label for="pd-site">Não preencha este campo</label><input id="pd-site" name="site" tabindex="-1" autocomplete="off"></div>
              <p class="pd-erro" role="alert" tabindex="-1" hidden></p>
              <div class="pd-enviar"><button type="submit" class="btn btn-red"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i><span>Enviar pedido</span></button></div>
            </form>
            <div class="pd-ok" role="status" tabindex="-1" hidden>
              <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
              <h3>Pedido recebido</h3>
              <p>Seu protocolo é <b data-pd-protocolo></b>. Mandamos uma confirmação para <b data-pd-email></b>. A resposta chega por e-mail em até <span data-pd-prazo></span>. Se faltar algum comprovante, pedimos por e-mail.</p>
            </div>
          </div>
          <aside class="pd-lado" aria-label="Como cuidamos dos seus documentos">
            <div class="pd-caminho">
              <h3><i class="fa-solid fa-lock" aria-hidden="true"></i>Como cuidamos dos seus documentos</h3>
              <p>Os comprovantes são usados só para conferir quem você é e analisar este pedido. Eles vão direto para o e-mail da equipe que analisa e não ficam guardados no site nem no banco de dados do site, onde fica só o registro do pedido.</p>
              <p>A equipe guarda os comprovantes só pelo tempo necessário para analisar o pedido e registrar a resposta. Você pode pedir a exclusão a qualquer momento pelo e-mail <a href="mailto:contato@cruzvermelhariodejaneiro.org">contato@cruzvermelhariodejaneiro.org</a>.</p>
            </div>
            <div class="pd-caminho">
              <h3><i class="fa-solid fa-clock" aria-hidden="true"></i>Prazo e resposta</h3>
              <p>Respondemos por e-mail em até {PRAZO}, sempre com o número de protocolo. A resposta pode ser o documento, a versão pública dele ou a explicação de por que não podemos enviar.</p>
            </div>
          </aside>
        </div>
      </div>
    </section>
    <section class="fim-acao" aria-labelledby="fim-acao-titulo">
      <div class="wrap">
        <div class="fim-acao-caixa">
          <h2 id="fim-acao-titulo">Ajude a Cruz Vermelha Brasileira Rio de Janeiro</h2>
          <p>Doe para manter a formação de voluntários e as ações da filial, cadastre-se no voluntariado ou fale com a equipe, que responde pelo e-mail institucional.</p>
          <div class="cta-row">
            <a class="btn btn-red" href="/doe/">Doe agora</a>
            <a class="btn btn-outline" href="/voluntariado/cadastro/">Seja voluntário</a>
            <a class="btn btn-outline" href="/#contato">Fale com a filial</a>
          </div>
        </div>
      </div>
    </section>
  </main>

{partes["footer"]}

{partes["menu_js"]}
{js}
</body>
</html>
"""
    pagina = icones.converter(pagina)
    for proibido in ("googletagmanager", "gtag(", "fbq(", "connect.facebook.net", "fonts.googleapis", "/chat/chat.js"):
        if proibido in pagina:
            raise SystemExit(f"a página de pedido de documentos não pode carregar {proibido!r}")
    PASTA.mkdir(parents=True, exist_ok=True)
    SAIDA.write_text(pagina, encoding="utf-8")
    print(f"gravado {SAIDA.relative_to(RAIZ)} ({len(pagina.encode('utf-8'))} bytes)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
