#!/usr/bin/env python3
"""Gera site/verificar/index.html: a página de verificação de documentos, publicada escondida.

A página confere documentos e publicações que a filial registrou na trilha pública de auditoria
(registro encadeado por hash, lote diário com carimbo de tempo RFC 3161, âncora no Bitcoin pelo
OpenTimestamps e manifesto assinado). Quem consulta chega de três jeitos: digitando o código,
abrindo o link do QR code (?c=<código>, que já consulta sozinho) ou soltando o arquivo que recebeu
(o navegador calcula o SHA-256 e só essa impressão digital é enviada; o arquivo não sai do aparelho).

A consulta vai para a API da Redação (API, abaixo), que responde pelo contrato de
POST /api/publico/verificar: {"codigo": "..."} ou {"hash": "<64 hex>"}; 200 com "encontrado"
true/false, 400 entrada_invalida, 429 limite (Retry-After), 5xx indisponível.

Lançamento escondido (24/09/2026). O gerador trava se a página quebrar as regras que dependem dele
(noindex, cabeçalho da pasta, nada de terceiros, API fixa); link vindo de outra página quem acusa é
scripts/conferir_links.py (e scripts/rastrear_site.py, no site no ar):
  - noindex, nofollow, noarchive na página e no cabeçalho X-Robots-Tag da pasta inteira
    (site/verificar/.htaccess), que cobre também o que a Redação gravar em lotes/ por FTP;
  - nenhum link para /verificar/ em página, menu, rodapé, sitemap, hreflang, llms.txt ou robots.txt;
  - nada de terceiros: sem GA4, sem Meta Pixel, sem Google Fonts (a "Inter Reserva" do CSS da home
    segura o texto com a fonte do aparelho), sem o chat (ele manda o endereço da página, com o
    código, no aviso à equipe). O código na URL é uma senha de acesso ao registro: não pode vazar.
    Referrer no-referrer e CSP com o hash de cada script inline completam a proteção;
  - o endereço da API é fixo. ?api= só troca o servidor quando a página roda em localhost ou
    127.0.0.1 (teste local); no domínio de verdade, um link com ?api= mostraria resultado falso
    na página oficial.

Cabeçalho, rodapé, CSS (com a "Inter Reserva") e o script do menu vêm de site/index.html, como nos
outros geradores. Também grava site/verificar/404.html: o 404 geral do site carrega GA4 e Pixel, e
um endereço errado dentro da pasta, com o código na URL, não pode cair nele.

Uso:  python3 scripts/gerar_verificar.py
Teste local: README, seção "Verificação de documentos em /verificar/".
"""
from __future__ import annotations

import base64
import hashlib
import json
import re

import icones
from gerar_matricula_presencial import HOME, RAIZ, esc, partes_da_home

PASTA = RAIZ / "site" / "verificar"
SAIDA = PASTA / "index.html"
SAIDA_404 = PASTA / "404.html"
HTACCESS = PASTA / ".htaccess"

# API de verificação da Redação. Fixa: a página não aceita outra fora do teste local.
API = "https://redacao.cruzvermelhariodejaneiro.org"

TITULO = "Verificação | Cruz Vermelha Brasileira Rio de Janeiro"
TITULO_404 = "Não encontrado | Cruz Vermelha Brasileira Rio de Janeiro"
DESCRICAO = ("Confira se um documento, certificado ou publicação foi registrado pela Cruz Vermelha Brasileira "
             "Rio de Janeiro e se continua valendo.")
DESCRICAO_404 = "Arquivo de prova não encontrado na verificação de documentos da Cruz Vermelha Brasileira Rio de Janeiro."

# Nada disto pode aparecer na página: rastreamento, fontes e scripts de terceiros, o chat.
PROIBIDO = ["googletagmanager", "gtag(", "fbq(", "connect.facebook.net", "facebook.com/tr", "fonts.googleapis",
            "fonts.gstatic", "cdnjs.", "/chat/", 'rel="preconnect"', 'rel="preload"', "@import"]
# O JS monta tudo com textContent; HTML vindo da API nunca vira marcação.
PROIBIDO_JS = ["innerHTML", "outerHTML", "insertAdjacentHTML", "document.write", "eval(", "new Function", "</", "<!--"]

HEAD = """<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="Content-Security-Policy" content="@@CSP@@">
  <meta name="referrer" content="no-referrer">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <!-- Página escondida: fora da busca, sem link de nenhuma página e sem nada de terceiros (scripts/gerar_verificar.py). -->
  <meta name="robots" content="noindex, nofollow, noarchive">
  <meta name="format-detection" content="telephone=no">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <title>@@TITULO@@</title>
  <meta name="description" content="@@DESCRICAO@@">
  <!-- Estilos copiados da home (site/index.html), com a "Inter Reserva": sem Google Fonts, o texto usa a fonte do aparelho nas medidas da Inter. -->
@@ESTILO@@
@@CSS@@
</head>
"""

PAGINA = HEAD + """<body class="vf">
@@HEADER@@
  <p class="vf-teste" id="vf-teste" hidden></p>

  <main id="verificar">
    <section class="vf-topo" aria-labelledby="vf-titulo">
      <div class="vf-coluna">
        <p class="eyebrow">Verificação de documentos</p>
        <h1 id="vf-titulo">Confira se um documento é autêntico</h1>
        <p class="lead">Digite o código impresso no documento, no certificado ou na publicação, ou escolha o arquivo que você recebeu. A consulta mostra se ele foi registrado pela Cruz Vermelha Brasileira Rio de Janeiro e se continua valendo.</p>

        <div class="vf-cartao">
          <form id="vf-form" novalidate>
            <label class="vf-rotulo" for="vf-codigo">Código de verificação</label>
            <p class="vf-dica" id="vf-codigo-dica">O código de 26 caracteres (ex.: NH1YB0F84MAHSKZ060MJATTG28), o de 32 caracteres de um ofício ou o <span class="vf-inteiro">XXXX-XXXX</span> de um certificado. Maiúsculas, espaços e hífens não fazem diferença. Também vale a impressão digital <span class="vf-inteiro">SHA-256</span>, de 64 caracteres.</p>
            <div class="vf-entrada">
              <input id="vf-codigo" name="c" type="text" autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false" maxlength="300" enterkeyhint="search" aria-describedby="vf-codigo-dica vf-codigo-erro">
              <button class="btn btn-red" type="submit" id="vf-botao">Verificar</button>
            </div>
            <p class="vf-erro" id="vf-codigo-erro" role="alert"></p>
          </form>

          <p class="vf-ou" aria-hidden="true">ou</p>

          <div class="vf-arquivo">
            <input class="vf-arquivo-input" id="vf-arquivo" type="file" aria-describedby="vf-arquivo-dica vf-arquivo-erro">
            <label class="vf-soltar" id="vf-soltar" for="vf-arquivo">
              <i class="fa-solid fa-file-arrow-up" aria-hidden="true"></i>
              <b>Escolha o arquivo ou arraste para cá</b>
              <small>PDF ou outro arquivo, até 100 MB</small>
            </label>
            <p class="vf-dica" id="vf-arquivo-dica"><i class="fa-solid fa-lock" aria-hidden="true"></i> O arquivo não sai do seu aparelho: o navegador calcula a impressão digital dele (SHA-256) e só essa sequência de 64 caracteres vai para a consulta.</p>
            <p class="vf-erro" id="vf-arquivo-erro" role="alert"></p>
          </div>

          <p class="vf-status" id="vf-status" role="status" aria-live="polite"></p>
          <noscript><p class="vf-erro">Esta página precisa de JavaScript para consultar o registro. Sem ele, dá para conferir o documento pelos passos de “Como conferir sem depender da Cruz Vermelha”, mais abaixo.</p></noscript>
        </div>
      </div>
    </section>

    <section class="vf-resultado" id="vf-resultado" aria-labelledby="vf-resultado-titulo" hidden>
      <div class="vf-coluna" id="vf-resultado-corpo"></div>
    </section>
    <p class="vf-sr" id="vf-aviso" role="status" aria-live="polite"></p>

    <section class="vf-sobre" aria-labelledby="vf-sobre-titulo">
      <div class="vf-coluna">
        <p class="eyebrow">Sobre a verificação</p>
        <h2 id="vf-sobre-titulo">O que é esta página</h2>
        <p>Esta página confere documentos e publicações que a Cruz Vermelha Brasileira Rio de Janeiro registrou numa trilha pública de auditoria: ofícios, certificados de curso, comunicados à imprensa, matérias do site, documentos do portal de transparência, parcerias e a página de canais oficiais.</p>
        <p>Cada registro guarda a impressão digital (SHA-256) do conteúdo e entra num registro encadeado por hash: cada item carrega a impressão digital do anterior, então alterar ou apagar qualquer um quebra a sequência. Uma vez por dia, os registros do dia fecham um lote, que recebe um carimbo de tempo RFC 3161 de uma autoridade independente, é ancorado no Bitcoin pelo OpenTimestamps e entra num manifesto assinado pela filial.</p>
        <p>A consulta mostra que aquele conteúdo, exatamente como está, foi registrado pela filial naquela data, e qual é a situação dele hoje: vigente, substituído, revogado ou retirado do ar. Ela prova registro e integridade; não substitui a assinatura de quem emitiu o documento nem lhe acrescenta validade jurídica.</p>
        <p>Esta página não usa ferramentas de análise nem carrega nada de terceiros. O código digitado vai só para o servidor de registro da filial, e o arquivo escolhido não sai do seu aparelho.</p>
      </div>
    </section>

    <section class="vf-conferir" id="vf-conferir" aria-labelledby="vf-conferir-titulo">
      <div class="vf-coluna">
        <p class="eyebrow">Conferência independente</p>
        <h2 id="vf-conferir-titulo">Como conferir sem depender da Cruz Vermelha</h2>
        <p>Tudo o que a consulta mostra pode ser refeito com programas livres, sem confiar no servidor da filial. Os arquivos de cada dia ficam em <code>lotes/AAAA-MM-DD/</code>, a lista dos dias está em <a href="lotes/indice.json">lotes/indice.json</a>, e os links de cada registro aparecem no resultado da consulta.</p>
        <details class="faq-item">
          <summary>1. O arquivo é o mesmo que foi registrado</summary>
          <div class="faq-answer">
            <p>A impressão digital SHA-256 muda por inteiro se um único bit do arquivo mudar; por isso reimprimir, escanear, comprimir ou converter o arquivo gera outra. Calcule a do arquivo que você recebeu e compare com a que aparece no resultado da consulta:</p>
            <pre class="vf-cmd"><code>sha256sum arquivo</code></pre>
            <p>No Mac: <code>shasum -a 256 arquivo</code>. No Windows (PowerShell): <code>Get-FileHash arquivo -Algorithm SHA256</code>.</p>
          </div>
        </details>
        <details class="faq-item">
          <summary>2. O registro já existia naquela data (Bitcoin)</summary>
          <div class="faq-answer">
            <p>A prova do OpenTimestamps (arquivo <code>.ots</code>) mostra que aquela impressão digital já existia quando um bloco do Bitcoin foi gravado, e qualquer pessoa confere isso contra o próprio Bitcoin. Baixe a prova no resultado da consulta e rode o programa <code>ots</code> (opentimestamps-client) na pasta em que estão a prova e o que ela cobre:</p>
            <ul>
              <li><strong>Matérias, comunicados, documentos e parcerias do portal, canais oficiais:</strong> a prova cobre o texto registrado, que se baixa no mesmo resultado (<code>CODIGO.json</code>). No portal de transparência, esse texto traz a impressão digital do PDF, que se confere pelo passo 1.</li>
              <li><strong>Ofícios:</strong> a prova cobre o manifesto de assinaturas, que se baixa na página do próprio ofício.</li>
              <li><strong>Certificados:</strong> a prova cobre a impressão digital do registro, mostrada no resultado da consulta.</li>
            </ul>
            <pre class="vf-cmd"><code>ots verify CODIGO.json.ots
ots verify -f manifesto-do-oficio.json CODIGO.ots
ots verify -d IMPRESSAO-DIGITAL CODIGO.ots</code></pre>
            <p>Sem instalar nada, dá para soltar o arquivo e a prova em <a href="https://opentimestamps.org/" target="_blank" rel="noopener noreferrer">opentimestamps.org</a>. Nas primeiras horas a prova fica pendente, até a transação entrar num bloco; depois disso, a conferência mostra o bloco e a data.</p>
          </div>
        </details>
        <details class="faq-item">
          <summary>3. O manifesto do dia recebeu o carimbo de tempo (RFC 3161)</summary>
          <div class="faq-answer">
            <p>O manifesto (<code>manifesto.json</code>) resume o dia: a raiz dos registros, as cabeças da cadeia e o compromisso, preso ao do dia anterior. Ele não lista registro nenhum. O carimbo de tempo (<code>manifesto.json.tsr</code>) foi emitido pela FreeTSA, uma autoridade de carimbo de tempo independente, e prova que o manifesto já existia naquele horário. Baixe os certificados da FreeTSA (<code>cacert.pem</code> e <code>tsa.crt</code>) em <a href="https://freetsa.org/" target="_blank" rel="noopener noreferrer">freetsa.org</a> e rode:</p>
            <pre class="vf-cmd"><code>openssl ts -verify -data manifesto.json -in manifesto.json.tsr -CAfile cacert.pem -untrusted tsa.crt</code></pre>
            <p>A resposta esperada é <code>Verification: OK</code>.</p>
          </div>
        </details>
        <details class="faq-item">
          <summary>4. O manifesto foi assinado pela filial</summary>
          <div class="faq-answer">
            <p>A assinatura (<code>manifesto.json.sig</code>) é Ed25519 e se confere com a chave pública da filial, publicada em <a href="chave-publica.pem">chave-publica.pem</a>. Precisa do OpenSSL 3 ou mais novo:</p>
            <pre class="vf-cmd"><code>openssl pkeyutl -verify -pubin -inkey chave-publica.pem -rawin -in manifesto.json -sigfile manifesto.json.sig</code></pre>
            <p>A resposta esperada é <code>Signature Verified Successfully</code>.</p>
          </div>
        </details>
        <details class="faq-item">
          <summary>5. O compromisso do lote bate com o manifesto</summary>
          <div class="faq-answer">
            <p>O <code>compromisso.bin</code> junta, em 96 bytes, a raiz dos registros do dia, as cabeças da cadeia e o compromisso do dia anterior: é o que prende cada dia ao anterior. A impressão digital dele tem de ser igual ao campo <code>compromisso</code> do manifesto e ao compromisso mostrado no resultado da consulta:</p>
            <pre class="vf-cmd"><code>sha256sum compromisso.bin</code></pre>
            <p>É esse arquivo que vai para o Bitcoin. A prova dele confere do mesmo jeito do passo 2, com o <code>compromisso.bin</code> na mesma pasta: <code>ots verify compromisso.bin.ots</code>.</p>
          </div>
        </details>
      </div>
    </section>
  </main>

@@FOOTER@@

@@MENU_JS@@
  <script>
@@JS@@
  </script>
</body>
</html>
"""

PAGINA_404 = HEAD + """<body class="vf">
@@HEADER@@

  <main id="verificar">
    <section class="vf-topo" aria-labelledby="vf-titulo">
      <div class="vf-coluna">
        <p class="eyebrow">Verificação de documentos</p>
        <h1 id="vf-titulo">Arquivo não encontrado</h1>
        <p class="lead">Os arquivos de prova de um dia só aparecem aqui depois que o lote daquele dia é fechado. Se você chegou por um link, confira o endereço, ou consulte o código do documento na página de verificação.</p>
        <p class="vf-acoes"><a class="btn btn-red" href="/verificar/">Ir para a verificação</a></p>
      </div>
    </section>
  </main>

@@FOOTER@@

@@MENU_JS@@
</body>
</html>
"""

CSS = """  <style>
    /* Verificação de documentos (scripts/gerar_verificar.py). O CSS da home vem antes, igual. */
    .vf button { font-family: inherit; }
    .vf-coluna { width: min(860px, calc(100% - 40px)); margin: 0 auto; }
    .vf h1 { color: var(--black); font-size: clamp(2rem, 4.4vw, 3rem); line-height: 1.06; letter-spacing: -.035em; margin: 8px 0 14px; }
    .vf h2 { font-size: clamp(1.5rem, 3vw, 2.1rem); line-height: 1.12; letter-spacing: -.03em; }
    .vf .lead { color: #4a5568; }
    .vf .vf-topo { background: var(--soft); border-bottom: 1px solid var(--line); padding: 56px 0 48px; }
    .vf-teste { margin: 0; padding: 10px 16px; background: #fff4dc; color: #6b4100; border-bottom: 1px solid #c98a12; text-align: center; font-weight: 700; font-size: .92rem; overflow-wrap: anywhere; }

    /* Cartão da consulta: código ou arquivo */
    .vf-cartao { margin-top: 28px; background: #fff; border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow); padding: 28px; }
    .vf-rotulo { display: block; font-weight: 800; color: var(--black); font-size: 1.05rem; }
    .vf-dica { color: #4a5568; font-size: .92rem; margin: 6px 0 12px; }
    .vf-dica i { color: var(--red); }
    .vf-inteiro { white-space: nowrap; }
    .vf-entrada { display: flex; flex-wrap: wrap; gap: 10px; }
    .vf-entrada input { flex: 1 1 260px; min-width: 0; min-height: 50px; padding: 12px 14px; border: 1.5px solid #8a94a6; border-radius: 12px; background: #fff; color: var(--black); font: 600 1.02rem/1.3 ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; letter-spacing: .04em; text-transform: uppercase; }
    .vf-entrada input[aria-invalid="true"] { border-color: #a00018; }
    .vf-entrada .btn { flex: 0 0 auto; min-height: 50px; padding-inline: 1.7rem; font-size: 1rem; }
    .vf-erro { margin: 8px 0 0; color: #a00018; font-weight: 700; font-size: .95rem; }
    .vf-erro:empty { margin: 0; }
    .vf-ou { display: flex; align-items: center; gap: 12px; margin: 22px 0; color: #4a5568; font-weight: 800; font-size: .8rem; text-transform: uppercase; letter-spacing: .12em; }
    .vf-ou::before, .vf-ou::after { content: ""; flex: 1; height: 1px; background: var(--line); }
    .vf-arquivo { position: relative; }
    .vf-arquivo-input { position: absolute; top: 0; left: 0; width: 1px; height: 1px; opacity: 0; overflow: hidden; }
    .vf-soltar { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 24px 16px; border: 2px dashed #8a94a6; border-radius: 14px; background: var(--soft); text-align: center; cursor: pointer; transition: border-color .15s, background .15s; }
    .vf-soltar > i { font-size: 1.9rem; color: var(--red); margin-bottom: 4px; }
    .vf-soltar b { color: var(--black); font-size: 1rem; }
    .vf-soltar small { color: #4a5568; font-size: .88rem; }
    .vf-soltar:hover, .vf-soltar.vf-arrastando { border-color: var(--red); background: #fff5f5; }
    .vf-status { margin: 18px 0 0; color: #2d3748; font-weight: 700; }
    .vf-status:empty { margin: 0; }

    /* Resultado */
    .vf .vf-resultado { padding: 36px 0 60px; scroll-margin-top: 100px; }
    .vf-resultado h3 { font-size: 1.15rem; margin: 30px 0 10px; }
    .vf-consulta { margin: 0 0 16px; display: grid; gap: 2px; font-size: .95rem; color: #4a5568; }
    .vf-consulta div { display: flex; flex-wrap: wrap; gap: 0 6px; min-width: 0; }
    .vf-consulta dt { font-weight: 700; color: var(--black); }
    .vf-consulta dt::after { content: ":"; }
    .vf-consulta dd { margin: 0; min-width: 0; overflow-wrap: anywhere; }
    .vf-veredito { display: flex; gap: 16px; align-items: flex-start; padding: 20px 22px; border: 2px solid; border-left-width: 8px; border-radius: 16px; }
    .vf-veredito-icone { flex: none; font-size: 2rem; line-height: 1; margin-top: 2px; }
    .vf-veredito h2 { color: inherit; font-size: clamp(1.35rem, 3.2vw, 1.9rem); margin: 0 0 4px; }
    .vf-veredito p { margin: 6px 0 0; color: #1a202c; }
    .vf-v-ok { background: #ecf8f0; border-color: #1e874b; color: #0b5d2e; }
    .vf-v-pendente { background: #eef4ff; border-color: #3566cf; color: #1e3a8a; }
    .vf-v-aviso { background: #fff6e0; border-color: #c98a12; color: #6b4100; }
    .vf-v-perigo { background: #fdeeee; border-color: #cc0000; color: #8f0000; }
    .vf-v-neutro { background: #f2f4f7; border-color: #8a94a6; color: #1f2937; }
    .vf-alerta { display: flex; gap: 12px; align-items: flex-start; margin-top: 16px; padding: 14px 16px; border: 2px solid #c98a12; border-radius: 12px; background: #fff6e0; color: #6b4100; font-weight: 600; }
    .vf-alerta p { margin: 0; }
    .vf-alerta a { color: inherit; text-decoration: underline; }
    .vf-nova { margin-top: 16px; padding: 16px 18px 18px; border: 1px solid var(--line); border-radius: 14px; background: #fff; }
    .vf-resultado .vf-nova h3 { margin-top: 0; }
    .vf-nova .btn { margin-top: 14px; }
    .vf-dados { margin: 0; border-top: 1px solid var(--line); }
    .vf-item { display: grid; grid-template-columns: minmax(140px, 200px) minmax(0, 1fr); gap: 4px 18px; padding: 11px 0; border-bottom: 1px solid var(--line); }
    .vf-item dt { font-weight: 700; color: #4a5568; }
    .vf-item dd { margin: 0; min-width: 0; color: var(--black); overflow-wrap: anywhere; }
    .vf-nota { color: #4a5568; font-size: .92rem; margin: 0 0 10px; }
    .vf-copiavel { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 8px 10px; }
    .vf-hash { flex: 1 1 260px; min-width: 0; padding: 6px 9px; border: 1px solid var(--line); border-radius: 8px; background: var(--soft); font: 500 .84rem/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; word-break: break-all; overflow-wrap: anywhere; }
    .vf-copiar { display: inline-flex; align-items: center; gap: 6px; min-height: 36px; padding: 6px 12px; border: 1.5px solid var(--line); border-radius: 999px; background: #fff; color: var(--black); font-size: .85rem; font-weight: 700; cursor: pointer; }
    .vf-copiar:hover { border-color: var(--red); color: var(--red); }
    .vf-igual { display: flex; align-items: center; gap: 6px; margin-top: 6px; color: #0b5d2e; font-weight: 700; font-size: .92rem; }
    .vf-provas { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
    .vf-provas li { display: flex; gap: 10px; align-items: flex-start; }
    .vf-provas li > i { flex: none; width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: .8rem; margin-top: 1px; }
    .vf-provas li > span { min-width: 0; flex: 1; overflow-wrap: anywhere; }
    .vf-provas .vf-copiavel { margin-top: 6px; }
    .vf-p-ok > i { background: #ecf8f0; color: #0b5d2e; }
    .vf-p-pendente > i { background: #eef4ff; color: #1e3a8a; }
    .vf-p-nao > i { background: #fdeeee; color: #8f0000; }
    .vf-p-info > i { background: #f2f4f7; color: #1f2937; }
    .vf-link { color: var(--red); font-weight: 700; text-decoration: underline; overflow-wrap: anywhere; }
    .vf-arquivos { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 10px; }
    .vf-arquivos .vf-link { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border: 1.5px solid var(--line); border-radius: 999px; background: #fff; color: var(--black); text-decoration: none; }
    .vf-arquivos .vf-link:hover { border-color: var(--red); color: var(--red); }
    .vf-arquivos .vf-link i { color: var(--red); }
    .vf-acoes { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 30px; }
    .vf-so-impressao { display: none; }
    .vf-sr { position: absolute !important; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); clip-path: inset(50%); white-space: nowrap; border: 0; }

    /* O que é, e como conferir sem nós */
    .vf .vf-sobre { border-top: 1px solid var(--line); padding: 56px 0 24px; }
    .vf-sobre p { color: #2d3748; max-width: 72ch; }
    .vf .vf-conferir { padding: 24px 0 72px; }
    .vf-conferir > .vf-coluna > p { color: #2d3748; max-width: 72ch; }
    .vf-conferir a, .vf-sobre a { color: var(--red); font-weight: 700; text-decoration: underline; }
    .vf-conferir .faq-answer { color: #2d3748; font-size: .95rem; }
    .vf-conferir .faq-answer p { margin: 0 0 10px; }
    .vf-sobre code, .vf-conferir p code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; font-size: .92em; background: rgba(15, 19, 24, .06); padding: 1px 5px; border-radius: 5px; overflow-wrap: anywhere; }
    .vf-cmd { margin: 10px 0 12px; padding: 12px 14px; border-radius: 10px; background: #0f1318; color: #f7f8fa; font: 500 .86rem/1.55 ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; white-space: pre-wrap; overflow-wrap: anywhere; }

    /* Foco sempre visível no teclado */
    .vf a:focus-visible, .vf button:focus-visible, .vf summary:focus-visible, .vf input:focus-visible { outline: 3px solid var(--black); outline-offset: 2px; }
    .vf-arquivo-input:focus-visible + .vf-soltar { outline: 3px solid var(--black); outline-offset: 2px; border-color: var(--black); }
    @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }

    @media (max-width: 620px) {
      .vf-coluna { width: calc(100% - 32px); }
      .vf .vf-topo { padding: 36px 0 32px; }
      .vf-cartao { margin-top: 22px; padding: 20px 16px; }
      .vf-entrada .btn { flex: 1 1 100%; }
      .vf-veredito { gap: 12px; padding: 16px; }
      .vf-veredito-icone { font-size: 1.6rem; }
      .vf-item { grid-template-columns: minmax(0, 1fr); gap: 2px; }
      .vf-acoes .btn { flex: 1 1 100%; }
      .vf .vf-sobre { padding: 44px 0 16px; }
      .vf-conferir .faq-item summary { padding: 16px 18px; }
      .vf-conferir .faq-item .faq-answer { padding: 0 18px 18px; }
    }

    /* Relatório impresso: sem menu, rodapé e botões; com resultado na tela, só ele vai para o papel. */
    @media print {
      @page { margin: 14mm 12mm; }
      .vf .main-header, .vf footer, .vf-teste, .vf-cartao, .vf-copiar, .vf-nao-imprimir, #vf-aviso { display: none !important; }
      .vf-com-resultado .vf-topo, .vf-com-resultado .vf-sobre, .vf-com-resultado .vf-conferir { display: none !important; }
      .vf-so-impressao { display: block !important; }
      .vf-coluna { width: 100%; }
      .vf .vf-resultado { padding: 0; }
      .vf-relatorio { margin-bottom: 16px; padding-bottom: 10px; border-bottom: 2px solid #cc0000; }
      .vf-relatorio-marca { margin: 0; color: #cc0000; font-size: .8rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
      .vf-relatorio-titulo { margin: 4px 0 0; color: #0f1318; font-size: 1.7rem; font-weight: 900; }
      .vf-veredito, .vf-item, .vf-provas li, .vf-alerta, .vf-nova { break-inside: avoid; }
      .vf-resultado h3 { break-after: avoid; }
      .vf-veredito, .vf-alerta, .vf-provas li > i { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .vf-item { grid-template-columns: 150px minmax(0, 1fr); padding: 7px 0; }
      .vf-hash { border: 0; background: none; padding: 0; font-size: 8.5pt; }
      .vf-arquivos { display: block; }
      .vf-arquivos .vf-link { display: inline; padding: 0; border: 0; }
      .vf-arquivos .vf-link i { display: none; }
      .vf-resultado a[href^="http"]:not(.vf-link-url)::after { content: " (" attr(href) ")"; color: #333; font-weight: 400; overflow-wrap: anywhere; }
    }
  </style>"""

JS = r"""(function () {
  'use strict';

  // Endereço da API de verificação (Redação), fixo. No domínio de verdade ele não muda pela URL: se
  // mudasse, qualquer um montaria um link para esta página oficial mostrando "Confere" vindo do servidor
  // dele. Só no teste local (localhost ou 127.0.0.1) o ?api= aponta para outro servidor, com aviso na tela.
  var API_OFICIAL = @@API@@;
  var CAMINHO = '/api/publico/verificar';
  var LIMITE_ARQUIVO = 100 * 1024 * 1024;   // acima disso, sha256sum no computador (texto na página)
  var ESPERA_MAXIMA = 20000;                // ms sem resposta da API = indisponível
  var FUSO = 'America/Sao_Paulo';
  var FILIAL = 'Cruz Vermelha Brasileira Rio de Janeiro';
  var CONTATO = 'contato@cruzvermelhariodejaneiro.org';
  var SVG = 'http://www.w3.org/2000/svg';
  var NAO_PROVA_FALSO = 'Isso não prova que o documento seja falso. Confira se o código foi digitado corretamente ou se o arquivo é exatamente o recebido, sem reimpressão, compressão ou conversão.';

  var parametros = new URLSearchParams(location.search);
  var api = API_OFICIAL;
  if (location.hostname === 'localhost' || location.hostname === '127.0.0.1') {
    try {
      var apiTeste = parametros.get('api') ? new URL(parametros.get('api')) : null;
      if (apiTeste && (apiTeste.protocol === 'http:' || apiTeste.protocol === 'https:')) api = apiTeste.origin;
    } catch (e) { /* ?api= malformado: fica a oficial */ }
  }

  function $(id) { return document.getElementById(id); }
  var form = $('vf-form'), campo = $('vf-codigo'), erroCodigo = $('vf-codigo-erro');
  var seletor = $('vf-arquivo'), zona = $('vf-soltar'), erroArquivo = $('vf-arquivo-erro');
  var linhaStatus = $('vf-status'), aviso = $('vf-aviso'), secao = $('vf-resultado'), corpo = $('vf-resultado-corpo');
  // vez: número da consulta mais recente. Resposta de consulta anterior chega e é descartada.
  var vez = 0, ultima = null;

  if (api !== API_OFICIAL) {
    var faixa = $('vf-teste');
    faixa.textContent = 'Modo de teste: as consultas vão para ' + api + ', não para o registro oficial.';
    faixa.hidden = false;
  }

  // ---------------------------------------------------------------- montagem segura (só textContent)
  function el(tag, classe, texto) {
    var n = document.createElement(tag);
    if (classe) n.className = classe;
    if (texto !== undefined && texto !== null) n.textContent = String(texto);
    return n;
  }
  function juntar(pai) {
    for (var i = 1; i < arguments.length; i++) {
      var f = arguments[i];
      if (f === null || f === undefined || f === false || f === '') continue;
      pai.appendChild(typeof f === 'string' ? document.createTextNode(f) : f);
    }
    return pai;
  }
  function icone(nome) {
    var i = document.createElement('i');
    i.className = 'fa-solid fa-' + nome;
    i.setAttribute('aria-hidden', 'true');
    var s = document.createElementNS(SVG, 'svg');
    s.setAttribute('class', 'ico ico-' + nome);
    s.setAttribute('aria-hidden', 'true');
    s.setAttribute('focusable', 'false');
    var u = document.createElementNS(SVG, 'use');
    u.setAttribute('href', '#i-' + nome);
    s.appendChild(u);
    i.appendChild(s);
    return i;
  }
  function texto(v) { return (typeof v === 'string' || typeof v === 'number') ? String(v) : ''; }
  function objeto(v) { return v && typeof v === 'object' ? v : null; }
  function hex64(v) { return typeof v === 'string' && /^[0-9a-f]{64}$/i.test(v) ? v.toLowerCase() : null; }
  // Link só para https:// (nunca javascript:, data: ou http://), em outra aba e sem passar o endereço desta página.
  function https(v) {
    if (typeof v !== 'string' || !/^https:\/\//i.test(v)) return null;
    try { var u = new URL(v); return u.protocol === 'https:' ? u.href : null; } catch (e) { return null; }
  }
  function linkExterno(url, rotulo, nomeIcone, classe) {
    var href = https(url);
    if (!href) return null;
    var a = el('a', 'vf-link' + (classe ? ' ' + classe : ''));
    a.href = href;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    if (nomeIcone) a.appendChild(icone(nomeIcone));
    a.appendChild(el('span', null, rotulo || href));
    return a;
  }
  function botao(rotulo, nomeIcone, classe) {
    var b = el('button', classe);
    b.type = 'button';
    if (nomeIcone) b.appendChild(icone(nomeIcone));
    b.appendChild(el('span', null, rotulo));
    return b;
  }
  function avisar(msg) { aviso.textContent = ''; setTimeout(function () { aviso.textContent = msg; }, 50); }
  function limpar() {
    while (corpo.firstChild) corpo.removeChild(corpo.firstChild);
    secao.hidden = true;
    document.body.classList.remove('vf-com-resultado');
  }

  // ---------------------------------------------------------------- datas (fuso de Brasília)
  var SO_DATA = /^(\d{4})-(\d{2})-(\d{2})$/;
  var formatos = {};
  function formato(chave, opcoes) {
    if (!formatos[chave]) { opcoes.timeZone = FUSO; formatos[chave] = new Intl.DateTimeFormat('pt-BR', opcoes); }
    return formatos[chave];
  }
  // 'AAAA-MM-DD' é data de calendário (ofícios, certificados): sai como veio, sem passar por fuso,
  // senão 2026-09-01 viraria 31/08 no horário de Brasília. Instante ISO sai no horário de Brasília
  // (semFuso: sem o aviso "(horário de Brasília)", para quando a data já vai entre parênteses).
  function quando(v, segundos, semFuso) {
    if (typeof v !== 'string' || !v) return '';
    var m = SO_DATA.exec(v);
    if (m) return m[3] + '/' + m[2] + '/' + m[1];
    var t = Date.parse(v);
    if (isNaN(t)) return v;
    var o = { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' };
    if (segundos) o.second = '2-digit';
    return formato(segundos ? 's' : 'm', o).format(new Date(t)) + (semFuso ? '' : ' (horário de Brasília)');
  }
  function dia(v) {
    if (typeof v !== 'string' || !v) return '';
    var m = SO_DATA.exec(v);
    if (m) return m[3] + '/' + m[2] + '/' + m[1];
    var t = Date.parse(v);
    return isNaN(t) ? v : formato('d', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date(t));
  }
  function hojeEmBrasilia(iso) {
    var t = Date.parse(iso), p = {};
    new Intl.DateTimeFormat('en-CA', { timeZone: FUSO, year: 'numeric', month: '2-digit', day: '2-digit' })
      .formatToParts(new Date(isNaN(t) ? Date.now() : t)).forEach(function (x) { p[x.type] = x.value; });
    return p.year + '-' + p.month + '-' + p.day;
  }
  function tamanho(n) {
    if (n < 1024) return n + ' bytes';
    var kb = n / 1024;
    if (kb < 1024) return kb.toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' KB';
    return (kb / 1024).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' MB';
  }

  // ---------------------------------------------------------------- tipos, em palavras
  var TIPOS = {
    materia: ['matéria publicada no site', 'matéria', 'a'],
    comunicado: ['comunicado à imprensa', 'comunicado', 'o'],
    oficio: ['ofício', 'ofício', 'o'],
    certificado: ['certificado de curso', 'certificado', 'o'],
    documento: ['documento do portal de transparência', 'documento', 'o'],
    parceria: ['parceria (MROSC)', 'documento de parceria', 'o'],
    canais: ['página de canais oficiais', 'página', 'a']
  };
  function tipoDe(d) {
    var t = TIPOS[d.tipo];
    return t ? { nome: t[0], curto: t[1], g: t[2] } : { nome: texto(d.tipo) || 'registro', curto: 'registro', g: 'o' };
  }

  // ---------------------------------------------------------------- o código digitado
  function analisar(bruto) {
    var valor = String(bruto || '').trim();
    // Link inteiro colado no campo (do WhatsApp, por exemplo): vale o ?c= dele.
    if (/^https?:\/\//i.test(valor)) {
      try { var c = new URL(valor).searchParams.get('c'); if (c) valor = c.trim(); } catch (e) {}
    }
    var limpo = valor.replace(/[\s-]+/g, '');
    if (!limpo) return { erro: 'Digite o código impresso no documento.' };
    if (!/^[0-9A-Za-z]+$/.test(limpo)) return { erro: 'O código só tem letras e números; espaços e hífens podem ficar, outros sinais não.' };
    if (limpo.length === 64 && /^[0-9a-f]+$/i.test(limpo)) {
      return { corpo: { hash: limpo.toLowerCase() }, consulta: { tipo: 'hash', hash: limpo.toLowerCase() } };
    }
    if ([8, 26, 32].indexOf(limpo.length) < 0) {
      return { erro: 'Confira o código: ele tem 26 caracteres (32 no caso de ofício e 8, no formato XXXX-XXXX, no de certificado). O digitado tem ' + limpo.length + '.' };
    }
    // Vai como foi digitado (sem as bordas): O, I e L o servidor normaliza.
    return { corpo: { codigo: valor }, consulta: { tipo: 'codigo', valor: valor } };
  }
  function erroNoCampo(msg) {
    erroCodigo.textContent = msg || '';
    if (msg) campo.setAttribute('aria-invalid', 'true'); else campo.removeAttribute('aria-invalid');
  }
  // doLink: consulta que veio do ?c= ao abrir a página; com erro, não puxa o foco (nem o teclado do celular).
  function enviarCodigo(focarResultado, doLink) {
    var a = analisar(campo.value);
    erroArquivo.textContent = '';
    if (a.erro) { erroNoCampo(a.erro); if (!doLink) campo.focus(); return; }
    erroNoCampo('');
    consultar(a.corpo, a.consulta, ++vez, focarResultado);
  }
  form.addEventListener('submit', function (e) { e.preventDefault(); enviarCodigo(false); });

  // ---------------------------------------------------------------- o arquivo (SHA-256 no navegador)
  function lerBytes(f) {
    if (f.arrayBuffer) return f.arrayBuffer();
    return new Promise(function (ok, falha) {
      var r = new FileReader();
      r.onload = function () { ok(r.result); };
      r.onerror = function () { falha(r.error); };
      r.readAsArrayBuffer(f);
    });
  }
  function paraHex(buf) {
    return Array.prototype.map.call(new Uint8Array(buf), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }
  function conferirArquivo(f) {
    erroNoCampo('');
    erroArquivo.textContent = '';
    if (!f) return;
    if (!f.size) { erroArquivo.textContent = 'O arquivo está vazio (0 bytes).'; return; }
    if (f.size > LIMITE_ARQUIVO) {
      erroArquivo.textContent = 'Este arquivo tem ' + tamanho(f.size) + '. O limite para conferir no navegador é 100 MB: calcule a impressão digital no computador (veja “Como conferir sem depender da Cruz Vermelha”, abaixo) e cole os 64 caracteres no campo do código.';
      return;
    }
    if (!window.crypto || !crypto.subtle || !crypto.subtle.digest) {
      erroArquivo.textContent = 'Este navegador não calcula a impressão digital do arquivo. Calcule no computador (veja “Como conferir sem depender da Cruz Vermelha”, abaixo) e cole os 64 caracteres no campo do código.';
      return;
    }
    var minha = ++vez;
    limpar();
    linhaStatus.textContent = 'Calculando a impressão digital do arquivo…';
    lerBytes(f).then(function (buf) { return crypto.subtle.digest('SHA-256', buf); }).then(function (resumo) {
      if (minha !== vez) return;
      var hash = paraHex(resumo);
      consultar({ hash: hash }, { tipo: 'arquivo', nome: f.name, tamanho: f.size, hash: hash }, minha, false);
    }, function () {
      if (minha !== vez) return;
      linhaStatus.textContent = '';
      erroArquivo.textContent = 'Não foi possível ler o arquivo. Tente de novo ou escolha outro.';
    });
  }
  seletor.addEventListener('change', function () {
    var f = seletor.files && seletor.files[0];
    seletor.value = '';
    if (f) conferirArquivo(f);
  });
  // Soltar o arquivo em qualquer ponto da página vale; fora dela, o navegador não abre o arquivo no lugar da página.
  var principal = document.querySelector('main'), arrastes = 0;
  function temArquivo(e) { var t = e.dataTransfer && e.dataTransfer.types; return !!t && Array.prototype.indexOf.call(t, 'Files') >= 0; }
  window.addEventListener('dragover', function (e) { if (temArquivo(e)) e.preventDefault(); });
  window.addEventListener('drop', function (e) { if (temArquivo(e)) e.preventDefault(); });
  principal.addEventListener('dragenter', function (e) { if (temArquivo(e)) { arrastes++; zona.classList.add('vf-arrastando'); } });
  principal.addEventListener('dragleave', function () { if (--arrastes <= 0) { arrastes = 0; zona.classList.remove('vf-arrastando'); } });
  principal.addEventListener('drop', function (e) {
    if (!temArquivo(e)) return;
    e.preventDefault();
    arrastes = 0;
    zona.classList.remove('vf-arrastando');
    var f = e.dataTransfer.files && e.dataTransfer.files[0];
    if (f) conferirArquivo(f);
  });

  // ---------------------------------------------------------------- a consulta
  function esperaDe(v) {
    if (!v) return null;
    if (/^\d+$/.test(v)) return parseInt(v, 10);
    var t = Date.parse(v);
    return isNaN(t) ? null : Math.max(1, Math.round((t - Date.now()) / 1000));
  }
  function consultar(corpoPedido, consulta, minha, focarResultado) {
    ultima = { corpo: corpoPedido, consulta: consulta };
    limpar();
    linhaStatus.textContent = 'Consultando o registro…';
    var controle = window.AbortController ? new AbortController() : null;
    var relogio = setTimeout(function () { if (controle) controle.abort(); }, ESPERA_MAXIMA);
    fetch(api + CAMINHO, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(corpoPedido),
      credentials: 'omit',
      cache: 'no-store',
      referrerPolicy: 'no-referrer',
      signal: controle ? controle.signal : undefined
    }).then(function (r) {
      if (r.status === 429) return { tipo: 'limite', espera: esperaDe(r.headers.get('Retry-After')) };
      if (r.status === 400) return { tipo: 'invalida' };
      if (!r.ok) return { tipo: 'indisponivel' };
      return r.json().then(function (d) {
        if (d && d.encontrado === true && typeof d.codigo === 'string') return { tipo: 'encontrado', dados: d };
        if (d && d.encontrado === false) return { tipo: 'nao-encontrado', dados: d };
        return { tipo: 'indisponivel' };
      });
    }).catch(function () {
      return { tipo: 'indisponivel' };
    }).then(function (res) {
      clearTimeout(relogio);
      if (minha !== vez) return;               // chegou depois de outra consulta: descarta
      mostrar(res, consulta, focarResultado);
    });
  }

  // ---------------------------------------------------------------- o veredito
  function espera(n) {
    if (!n) return 'Aguarde alguns minutos e tente de novo.';
    if (n <= 90) return 'Aguarde cerca de ' + n + (n === 1 ? ' segundo' : ' segundos') + ' e tente de novo.';
    var min = Math.ceil(n / 60);
    return 'Aguarde cerca de ' + min + ' minutos e tente de novo.';
  }
  function veredito(res, consulta) {
    if (res.tipo === 'limite') {
      return { classe: 'aviso', icone: 'hourglass-half', titulo: 'Limite de consultas', textos: ['Foram muitas consultas em pouco tempo. ' + espera(res.espera)] };
    }
    if (res.tipo === 'indisponivel') {
      return { classe: 'neutro', icone: 'triangle-exclamation', titulo: 'Serviço indisponível', textos: ['Não foi possível consultar o registro agora. Tente de novo mais tarde.', 'Se for urgente, escreva para ' + CONTATO + '.'] };
    }
    if (res.tipo === 'invalida') {
      return { classe: 'aviso', icone: 'circle-exclamation', titulo: 'Código não reconhecido', textos: ['O registro não reconheceu este código. Confira se ele foi digitado por inteiro: são 26 caracteres, 32 no caso de ofício ou 8 (no formato XXXX-XXXX) no caso de certificado.'] };
    }
    if (res.tipo === 'nao-encontrado') {
      var nada = consulta.tipo === 'arquivo' ? 'Não há registro deste arquivo.' : consulta.tipo === 'hash' ? 'Não há registro com esta impressão digital.' : 'Não há registro com este código.';
      return { classe: 'neutro', icone: 'magnifying-glass', titulo: 'Não encontrado', textos: [nada, NAO_PROVA_FALSO] };
    }
    var d = res.dados, t = tipoDe(d), g = t.g;
    var sujeito = (g === 'a' ? 'Esta ' : 'Este ') + t.curto + ' foi registrad' + g + ' pela ' + FILIAL + (d.registrado_em ? ' em ' + dia(d.registrado_em) : '');
    var em = d.estado_em ? ' em ' + dia(d.estado_em) : '';
    var lote = objeto(d.lote);
    switch (d.estado) {
      case 'vigente':
        if (lote) {
          return { classe: 'ok', icone: 'circle-check', titulo: 'Confere: ' + t.curto + ' autêntic' + g,
            textos: [sujeito + ' e continua vigente.', 'A prova do registro já foi fechada no lote do dia' + (lote.dia ? ' ' + dia(lote.dia) : '') + '; as conferências estão logo abaixo.'] };
        }
        return { classe: 'pendente', icone: 'clock', titulo: 'Registrad' + g + ' — prova em confirmação',
          textos: [sujeito + ' e continua vigente.', 'A prova independente (o lote do dia, com carimbo de tempo e âncora no Bitcoin) ainda está sendo fechada. Consulte de novo a partir de amanhã para baixá-la.'] };
      case 'substituido':
        return { classe: 'aviso', icone: 'arrow-right-arrow-left', titulo: 'Substituíd' + g + ' por versão mais nova',
          textos: [sujeito + ', mas foi substituíd' + g + em + '. Confira a versão mais nova, logo abaixo, antes de usá-l' + g + '.'] };
      case 'revogado':
        return { classe: 'perigo', icone: 'ban', titulo: 'Revogad' + g, textos: [sujeito + ', mas foi revogad' + g + em + ' e deixou de valer.'] };
      case 'retirado':
        return { classe: 'neutro', icone: 'eye-slash', titulo: 'Retirad' + g + ' do ar',
          textos: [sujeito + ' e foi retirad' + g + ' do ar' + em + '. O registro continua servindo para conferir cópias do que foi publicado.'] };
      default:
        return { classe: 'neutro', icone: 'circle-info', titulo: 'Registro encontrado', textos: [sujeito + '.', 'Situação informada pelo registro: ' + (texto(d.estado) || 'não informada') + '.'] };
    }
  }

  // ---------------------------------------------------------------- blocos do resultado
  function item(dl, rotulo, valor) {
    if (valor === null || valor === undefined || valor === '') return null;
    var linha = el('div', 'vf-item'), dd = el('dd');
    linha.appendChild(el('dt', null, rotulo));
    juntar(dd, valor);
    linha.appendChild(dd);
    dl.appendChild(linha);
    return dd;
  }
  function copiavel(valor, nome) {
    var caixa = el('span', 'vf-copiavel'), codigo = el('code', 'vf-hash', valor);
    var b = botao('Copiar', 'copy', 'vf-copiar');
    b.setAttribute('aria-label', 'Copiar ' + nome);
    b.addEventListener('click', function () { copiar(valor, codigo, b); });
    return juntar(caixa, codigo, b);
  }
  function copiar(valor, alvo, b) {
    var rotulo = b.querySelector('span');
    function pronto(msg, falado) {
      rotulo.textContent = msg;
      avisar(falado);
      setTimeout(function () { rotulo.textContent = 'Copiar'; }, 2500);
    }
    function selecionar() {
      try { var r = document.createRange(), s = window.getSelection(); r.selectNodeContents(alvo); s.removeAllRanges(); s.addRange(r); } catch (e) {}
      pronto('Selecionado', 'Texto selecionado: use Ctrl+C para copiar.');
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(valor).then(function () { pronto('Copiado', 'Copiado para a área de transferência.'); }, selecionar);
    } else selecionar();
  }
  function situacao(d, t) {
    var em = d.estado_em ? ' em ' + quando(d.estado_em) : '';
    if (d.estado === 'vigente') return 'Vigente';
    if (d.estado === 'substituido') return 'Substituíd' + t.g + em;
    if (d.estado === 'revogado') return 'Revogad' + t.g + em;
    if (d.estado === 'retirado') return 'Retirad' + t.g + ' do ar' + em;
    return texto(d.estado);
  }
  function horas(v) {
    if (typeof v === 'number' && isFinite(v)) return v.toLocaleString('pt-BR') + (v === 1 ? ' hora' : ' horas');
    return texto(v);
  }
  function validade(v, consultadoEm) {
    if (v === null || v === undefined || v === '') return 'Sem data de validade';
    if (typeof v === 'string' && SO_DATA.test(v) && v < hojeEmBrasilia(consultadoEm)) return quando(v) + ' (prazo encerrado)';
    return quando(texto(v));
  }
  function descreverConsulta(consulta) {
    if (consulta.tipo === 'arquivo') return 'arquivo ' + consulta.nome + ' (' + tamanho(consulta.tamanho) + ')';
    if (consulta.tipo === 'hash') return 'impressão digital ' + consulta.hash;
    return 'código ' + consulta.valor;
  }

  function detalhes(d, consulta) {
    var t = tipoDe(d), lote = objeto(d.lote), cadeia = objeto(d.cadeia), links = objeto(d.links) || {};

    if (cadeia && cadeia.integra === false) {
      var alerta = juntar(el('div', 'vf-alerta'), icone('triangle-exclamation'));
      var p = el('p', null, 'Atenção: a última verificação automática da cadeia de registros' +
        (cadeia.verificada_em ? ' (' + quando(cadeia.verificada_em, false, true) + ')' : '') +
        ' encontrou uma inconsistência. Antes de confiar neste resultado, faça as conferências de ');
      var ancora = el('a', null, 'Como conferir sem depender da Cruz Vermelha');
      ancora.href = '#vf-conferir';
      juntar(p, ancora, '.');
      corpo.appendChild(juntar(alerta, p));
    }

    var sub = objeto(d.substituido_por);
    if (d.estado === 'substituido' && sub) {
      var nova = el('div', 'vf-nova'), dn = el('dl', 'vf-dados');
      nova.appendChild(el('h3', null, 'Versão mais nova'));
      item(dn, 'Código', texto(sub.codigo) ? copiavel(texto(sub.codigo), 'o código da versão mais nova') : null);
      item(dn, 'Versão', texto(sub.versao));
      item(dn, 'Endereço', linkExterno(sub.url, null, null, 'vf-link-url'));
      nova.appendChild(dn);
      if (texto(sub.codigo)) {
        var ir = botao('Verificar a versão mais nova', 'magnifying-glass', 'btn btn-outline vf-nao-imprimir');
        ir.addEventListener('click', function () { campo.value = texto(sub.codigo); enviarCodigo(true); });
        nova.appendChild(ir);
      }
      corpo.appendChild(nova);
    }

    corpo.appendChild(el('h3', null, 'Dados do registro'));
    var dados = el('dl', 'vf-dados');
    item(dados, 'Tipo', t.nome);
    if (d.classe === 'P') {
      item(dados, 'Título', texto(d.titulo));
      item(dados, 'Endereço', linkExterno(d.url, null, null, 'vf-link-url') || texto(d.url));
    }
    item(dados, 'Código', copiavel(texto(d.codigo), 'o código do registro'));
    item(dados, 'Registrado em', quando(d.registrado_em));
    if (d.primeiro_registro_em) item(dados, 'Primeiro registro deste conteúdo', quando(d.primeiro_registro_em));
    item(dados, 'Versão', texto(d.versao));
    item(dados, 'Situação', situacao(d, t));
    corpo.appendChild(dados);

    var cert = objeto(d.certificado);
    if (d.classe === 'C' && cert) {
      corpo.appendChild(el('h3', null, 'Certificado'));
      var dc = el('dl', 'vf-dados');
      item(dc, 'Nome', texto(cert.nome));
      item(dc, 'Curso', texto(cert.curso));
      item(dc, 'Carga horária', horas(cert.carga_horaria));
      item(dc, 'Emitido em', quando(cert.emitido_em));
      item(dc, 'Válido até', validade(cert.valido_ate, d.consultado_em));
      corpo.appendChild(dc);
    }

    corpo.appendChild(el('h3', null, 'Impressões digitais (SHA-256)'));
    corpo.appendChild(el('p', 'vf-nota', 'A impressão digital identifica o conteúdo exato: qualquer mudança, por menor que seja, gera outra.'));
    var dh = el('dl', 'vf-dados');
    var hConteudo = hex64(d.hash), hArquivo = hex64(d.hash_arquivo);
    item(dh, 'Do conteúdo registrado', hConteudo ? copiavel(hConteudo, 'a impressão digital do conteúdo registrado') : texto(d.hash));
    if (d.hash_arquivo) item(dh, 'Do arquivo registrado', hArquivo ? copiavel(hArquivo, 'a impressão digital do arquivo registrado') : texto(d.hash_arquivo));
    if (consulta.tipo === 'arquivo') {
      var seu = item(dh, 'Do arquivo que você escolheu', copiavel(consulta.hash, 'a impressão digital do arquivo escolhido'));
      var igual = consulta.hash === hArquivo ? 'Igual à do arquivo registrado' : consulta.hash === hConteudo ? 'Igual à do conteúdo registrado' : '';
      if (igual) seu.appendChild(juntar(el('span', 'vf-igual'), icone('check'), igual));
    }
    corpo.appendChild(dh);

    corpo.appendChild(el('h3', null, 'Provas do registro'));
    var provas = el('ul', 'vf-provas');
    function prova(situ, nomeIcone, rotulo, valor) {
      var li = el('li', 'vf-p-' + situ), span = el('span');
      li.appendChild(icone(nomeIcone));
      span.appendChild(el('b', null, rotulo + ': '));
      juntar(span, valor);
      li.appendChild(span);
      provas.appendChild(li);
    }
    if (lote) {
      prova('info', 'circle-info', 'Lote do dia', dia(texto(lote.dia)) || 'sem data');
      var btc = objeto(lote.bitcoin) || {};
      if (btc.confirmado === true) prova('ok', 'check', 'Carimbo no Bitcoin', 'confirmado' + (texto(btc.bloco) ? ' no bloco ' + texto(btc.bloco) : ''));
      else prova('pendente', 'clock', 'Carimbo no Bitcoin', 'aguardando confirmação');
      prova(lote.tsa ? 'ok' : 'nao', lote.tsa ? 'check' : 'xmark', 'Carimbo de tempo RFC 3161', lote.tsa ? 'sim' : 'não');
      prova(lote.assinado ? 'ok' : 'nao', lote.assinado ? 'check' : 'xmark', 'Manifesto assinado',
        lote.assinado ? 'sim' + (texto(lote.chave_id) ? ' (chave ' + texto(lote.chave_id) + ')' : '') : 'não');
      var comp = hex64(lote.compromisso);
      if (comp) prova('info', 'fingerprint', 'Compromisso do lote', copiavel(comp, 'o compromisso do lote'));
    } else {
      prova('pendente', 'clock', 'Lote do dia', 'ainda não fechado');
      prova('pendente', 'clock', 'Carimbo no Bitcoin', 'aguardando o fechamento do lote');
      prova('pendente', 'clock', 'Carimbo de tempo RFC 3161', 'aguardando o fechamento do lote');
      prova('pendente', 'clock', 'Manifesto assinado', 'aguardando o fechamento do lote');
    }
    if (cadeia && typeof cadeia.integra === 'boolean') {
      prova(cadeia.integra ? 'ok' : 'nao', cadeia.integra ? 'check' : 'xmark',
        'Cadeia íntegra na última verificação' + (cadeia.verificada_em ? ' (' + quando(cadeia.verificada_em, false, true) + ')' : ''), cadeia.integra ? 'sim' : 'não');
    } else {
      prova('info', 'circle-info', 'Cadeia íntegra na última verificação', 'sem verificação registrada');
    }
    corpo.appendChild(provas);

    var lista = el('ul', 'vf-arquivos');
    [[links.prova, 'Prova OpenTimestamps do registro (.ots)', 'download'],
     [links.conteudo, 'Conteúdo registrado (JSON)', 'download'],
     [links.manifesto, 'Manifesto do lote' + (lote && SO_DATA.test(texto(lote.dia)) ? ' de ' + dia(lote.dia) : ''), 'download'],
     [links.pagina_propria, 'Página d' + t.g + ' ' + t.curto, 'arrow-up-right-from-square']].forEach(function (x) {
      var a = linkExterno(x[0], x[1], x[2]);
      if (a) lista.appendChild(juntar(el('li'), a));
    });
    if (lista.firstChild) {
      corpo.appendChild(el('h3', null, 'Arquivos para conferir'));
      corpo.appendChild(lista);
    } else if (!lote) {
      corpo.appendChild(el('p', 'vf-nota', 'Os arquivos de prova ficam disponíveis depois do fechamento do lote do dia.'));
    }
  }

  function mostrar(res, consulta, focarResultado) {
    limpar();
    var d = objeto(res.dados) || {}, v = veredito(res, consulta), respondeu = res.tipo === 'encontrado' || res.tipo === 'nao-encontrado';

    corpo.appendChild(juntar(el('div', 'vf-so-impressao vf-relatorio'),
      el('p', 'vf-relatorio-marca', FILIAL), el('p', 'vf-relatorio-titulo', 'Relatório de verificação')));

    var feita = el('dl', 'vf-consulta'), linha = el('div');
    juntar(linha, el('dt', null, 'Consulta'), el('dd', null, descreverConsulta(consulta)));
    feita.appendChild(linha);
    if (respondeu && d.consultado_em) {
      var quandoFoi = el('div');
      juntar(quandoFoi, el('dt', null, 'Consultado em'), el('dd', null, quando(texto(d.consultado_em), true)));
      feita.appendChild(quandoFoi);
    }
    corpo.appendChild(feita);

    var caixa = el('div', 'vf-veredito vf-v-' + v.classe), txt = el('div');
    caixa.appendChild(juntar(el('span', 'vf-veredito-icone'), icone(v.icone)));
    var titulo = el('h2', null, v.titulo);
    titulo.id = 'vf-resultado-titulo';
    titulo.tabIndex = -1;
    txt.appendChild(titulo);
    v.textos.forEach(function (x) { txt.appendChild(el('p', null, x)); });
    caixa.appendChild(txt);
    corpo.appendChild(caixa);

    if (res.tipo === 'encontrado') detalhes(d, consulta);
    if (res.tipo === 'nao-encontrado' && consulta.tipo === 'arquivo') {
      var dh = el('dl', 'vf-dados');
      dh.style.marginTop = '20px';
      item(dh, 'Impressão digital do arquivo (SHA-256)', copiavel(consulta.hash, 'a impressão digital do arquivo escolhido'));
      corpo.appendChild(dh);
    }

    if (respondeu) {
      var nota = el('p', 'vf-so-impressao vf-nota', 'Este relatório reproduz a resposta do registro da ' + FILIAL + ' no momento da consulta. ' +
        'Carimbo de tempo, âncora no Bitcoin e assinatura do manifesto podem ser conferidos sem depender da filial, com os arquivos de prova. ' +
        'Página de verificação: ' + location.origin + location.pathname);
      nota.style.marginTop = '24px';
      corpo.appendChild(nota);
    }

    var acoes = el('div', 'vf-acoes vf-nao-imprimir');
    if (respondeu) {
      var imprimir = botao('Imprimir relatório', 'print', 'btn btn-red');
      imprimir.addEventListener('click', function () { window.print(); });
      acoes.appendChild(imprimir);
    } else if (res.tipo !== 'invalida') {
      var denovo = botao('Tentar de novo', 'rotate-left', 'btn btn-red');
      denovo.addEventListener('click', function () { if (ultima) consultar(ultima.corpo, ultima.consulta, ++vez, true); });
      acoes.appendChild(denovo);
    }
    var outra = botao('Nova consulta', 'magnifying-glass', 'btn btn-outline');
    outra.addEventListener('click', function () {
      campo.focus();
      campo.select();
      if (campo.scrollIntoView) campo.scrollIntoView({ block: 'center' });
    });
    acoes.appendChild(outra);
    corpo.appendChild(acoes);

    document.body.classList.toggle('vf-com-resultado', respondeu);
    secao.hidden = false;
    linhaStatus.textContent = 'Resultado: ' + v.titulo + '.';
    // O botão que pediu a consulta (Tentar de novo, Verificar a versão mais nova) sumiu com o resultado
    // anterior: o foco vai para o título do novo, em vez de cair no começo da página.
    if (focarResultado) titulo.focus();
    var r = caixa.getBoundingClientRect();
    if (r.top < 0 || r.bottom > window.innerHeight) secao.scrollIntoView({ block: 'start' });
  }

  // ---------------------------------------------------------------- link do QR code: ?c= consulta sozinho
  var codigoDoLink = parametros.get('c');
  if (codigoDoLink && codigoDoLink.trim()) {
    campo.value = codigoDoLink.trim();
    enviarCodigo(false, true);
  }
})();"""


def sem_referer(trecho: str) -> str:
    """Link para fora do site leva rel="noopener noreferrer". A meta referrer já corta o endereço
    (com o código); esta é a segunda trava, para o cabeçalho e o rodapé que vêm da home."""
    def ajustar(m: re.Match) -> str:
        tag = m.group(0)
        if ' rel="' in tag:
            return re.sub(r' rel="[^"]*"', ' rel="noopener noreferrer"', tag)
        return tag[:-1] + ' rel="noopener noreferrer">'
    return re.sub(r'<a\s[^>]*href="https?://[^"]*"[^>]*>', ajustar, trecho)


def bloco_script(js: str) -> str:
    return "\n".join(("    " + linha) if linha else "" for linha in js.split("\n"))


def politica(html: str) -> str:
    """CSP da página: só os scripts inline dela (pelo hash), fetch só para a API, nada de fora.

    Vai na meta tag porque o hash muda a cada edição do JS; o .htaccess da pasta completa com
    frame-ancestors, que meta tag não aceita.
    """
    scripts = re.findall(r"<script>(.*?)</script>", html, re.S)
    if html.count("<script") != len(scripts):
        raise SystemExit("script com atributo (src, type...) na página de verificação: aqui só entra script inline")
    hashes = " ".join("'sha256-" + base64.b64encode(hashlib.sha256(s.encode("utf-8")).digest()).decode() + "'" for s in scripts)
    return "; ".join([
        "default-src 'none'",
        f"script-src {hashes}",
        "style-src 'unsafe-inline'",
        "img-src 'self' data:",
        # localhost e 127.0.0.1 servem só ao teste local (?api=), que a página só aceita rodando em localhost.
        f"connect-src {API} http://localhost:* http://127.0.0.1:*",
        "base-uri 'none'",
        "form-action 'self'",
    ])


def conferir(html: str, nome: str) -> None:
    """As travas do lançamento escondido. Qualquer falha interrompe o gerador."""
    obrigatorio = ['<meta name="robots" content="noindex, nofollow, noarchive">', '<meta name="referrer" content="no-referrer">',
                   '"Inter Reserva"', '<meta http-equiv="Content-Security-Policy"']
    for trecho in obrigatorio:
        if trecho not in html:
            raise SystemExit(f"{nome}: falta {trecho}")
    for trecho in PROIBIDO:
        if trecho in html:
            raise SystemExit(f"{nome}: {trecho!r} não pode aparecer na página de verificação")
    sobrou = re.findall(r"@@[A-Z_0-9]+@@", html)
    if sobrou:
        raise SystemExit(f"{nome}: marcadores não substituídos: {sorted(set(sobrou))}")
    # Todo recurso carregado é do próprio site (caminho absoluto) ou data:. Links para fora são só links.
    for src in re.findall(r'\ssrc="([^"]*)"', html):
        if not src.startswith("/") or src.startswith("//"):
            raise SystemExit(f"{nome}: recurso de fora do site: {src}")
    for link in re.findall(r"<link\b[^>]*>", html):
        if 'rel="icon"' not in link or 'href="/' not in link:
            raise SystemExit(f"{nome}: <link> inesperado: {link}")
    for url in re.findall(r"url\(\s*['\"]?([^'\")]+)", html):
        if not (url.startswith("data:") or url.startswith("/")):
            raise SystemExit(f"{nome}: url() de fora do site no CSS: {url[:60]}")


def montar(modelo: str, partes: dict, header: str, js: str | None, titulo: str, descricao: str, nome: str) -> str:
    html = (modelo.replace("@@TITULO@@", esc(titulo)).replace("@@DESCRICAO@@", esc(descricao))
            .replace("@@ESTILO@@", partes["estilo"]).replace("@@CSS@@", CSS)
            .replace("@@HEADER@@", header).replace("@@FOOTER@@", partes["footer"]).replace("@@MENU_JS@@", partes["menu_js"]))
    extras: set[str] = set()
    if js is not None:
        html = html.replace("@@JS@@", bloco_script(js))
        # O JS desenha ícones na hora (icone('nome')), então o sprite leva todo nome de ícone citado nele.
        extras = set(re.findall(r"'([a-z0-9-]+)'", js)) & set(icones.ICONES)
        citados = {a or b for a, b in re.findall(r"icone\('([a-z0-9-]+)'\)|icone: '([a-z0-9-]+)'", js)}
        faltando = citados - set(icones.ICONES)
        if faltando:
            raise SystemExit(f"{nome}: ícones sem desenho em scripts/icones.json: {sorted(faltando)}")
    html = icones.converter(html, extras=extras)
    html = html.replace("@@CSP@@", politica(html))
    conferir(html, nome)
    return html


def main() -> int:
    if not HTACCESS.exists() or 'X-Robots-Tag "noindex, nofollow, noarchive"' not in HTACCESS.read_text(encoding="utf-8"):
        raise SystemExit("site/verificar/.htaccess sem o X-Robots-Tag: a pasta não pode ir ao ar sem ele")
    if not API.startswith("https://"):
        raise SystemExit("a API de verificação tem de ser https")
    for trecho in PROIBIDO_JS:
        if trecho in JS:
            raise SystemExit(f"o JS da verificação não pode usar {trecho!r}")

    partes = partes_da_home(HOME.read_text(encoding="utf-8"))
    # Nenhum item do menu é a página atual aqui (partes_da_home marca a matrícula).
    header = sem_referer(partes["header"].replace(' aria-current="page"', ""))
    partes = dict(partes, footer=sem_referer(partes["footer"]))

    js = JS.replace("@@API@@", json.dumps(API))
    if js.count(API) != 1:
        raise SystemExit("o endereço da API deve aparecer uma vez só no JS")
    pagina = montar(PAGINA, partes, header, js, TITULO, DESCRICAO, "index.html")
    pagina_404 = montar(PAGINA_404, partes, header, None, TITULO_404, DESCRICAO_404, "404.html")

    PASTA.mkdir(parents=True, exist_ok=True)
    for destino, html in ((SAIDA, pagina), (SAIDA_404, pagina_404)):
        destino.write_text(html, encoding="utf-8")
        print(f"gravado {destino.relative_to(RAIZ)} ({len(html.encode('utf-8'))} bytes)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
