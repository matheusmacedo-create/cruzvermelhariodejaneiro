#!/usr/bin/env python3
"""Gera as páginas do checkout da matrícula: checkout/, pendente/, parabens/ e horarios/, e as do
ponto da sede: ponto/ (com o cartaz do QR code), comparecimento/ e conferir/.

Todas usam o cabeçalho, o rodapé, o CSS, o GA4 e o Meta Pixel da home (via
scripts/gerar_matricula_presencial.py) e conversam com o backend em
site/matricula-cursos-presenciais/api/ (PHP, Unicopag). As três levam noindex.

O CSS e o JS próprios do checkout ficam em site/matricula-cursos-presenciais/static/
(checkout.css e checkout.js): as páginas só os referenciam, com um hash do conteúdo na
query string para o navegador buscar a versão nova a cada mudança.

Pagar tudo (10/2026; spec 1.12, 1.13, 10.4 e 10.5): o checkout vende "Taxa de inscrição + matrícula" (marcada) ou
"Só a taxa de inscrição", com parcelas no cartão (só a opção 1), a caixa da oferta a prazo, o Resumo das condições e a
barra de ação. Os planos de cada curso vêm do servidor (api/info.php): a página só mostra o que ele devolve. A turma
sai do oferta.json (com a regra de prazo e lotação), nunca de uma lista no código. O CSS novo fica aqui, no <style> da
página (o checkout.css não muda).

PLANO_COMPLETO_HTML=1 (geração do passo 5 da publicação): a opção 1 já vem visível e marcada no HTML nos cursos com
turma. Sem ela, a opção 1 vai com hidden e o checkout.js a mostra quando o info.php a trouxer (T24).

Uso:  python3 scripts/gerar_checkout.py   (depois de gerar_matricula_presencial.py)
      Para publicar, use scratchpad/pagar-tudo-build/gerar.sh (gera com a home que está no ar).
"""
from __future__ import annotations

import hashlib
import json
import os
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path

import chat_widget
import icones
from gerar_matricula_presencial import DADOS, HOME, RAIZ, esc, partes_da_home

PASTA = RAIZ / "site" / "matricula-cursos-presenciais"
STATIC = PASTA / "static"
STATIC_URL = "/matricula-cursos-presenciais/static/"
OFERTA = PASTA / "oferta.json"
# Horário de Brasília (sem horário de verão desde 2019), o mesmo de api/lib/compra.php.
BRT = timezone(timedelta(hours=-3))
MESES = ["janeiro", "fevereiro", "março", "abril", "maio", "junho", "julho", "agosto", "setembro", "outubro", "novembro", "dezembro"]
DIAS_SEMANA = ["segunda", "terça", "quarta", "quinta", "sexta", "sábado", "domingo"]  # datetime.weekday(): 0 = segunda
# Rótulos das etapas do topo (spec 1.12): os da escola, com "Curso escolhido" no lugar de "Contrato". O checkout.js
# troca os rótulos junto com o plano (e "Data por e-mail" na venda sem turma, 10.4).
PASSOS_COMPLETO = ["Curso escolhido", "Pagamento", "Matrícula confirmada"]
PASSOS_TAXA = ["Curso escolhido", "Taxa de inscrição", "Matrícula"]
# Quem vende e recebe (decisão 6 do dono): o padrão de api/lib/config.php (RECEBEDOR_NOME/RECEBEDOR_CNPJ). O checkout.js
# troca pelo que o info.php devolve (o config do servidor pode mudar).
RECEBEDOR_NOME = "O-CVB Filial Rio de Janeiro Ensino Ltda"
RECEBEDOR_CNPJ = "67.733.551/0001-35"

PAGINA = """<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <title>@@TITULO@@</title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"></noscript>
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
  <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
@@QRCODE@@
  <!-- Estilos copiados da home (site/index.html): mesmo padrão visual da filial. -->
@@ESTILO@@
  <link rel="stylesheet" href="@@CSS_URL@@">
@@CSS_PAGAR_TUDO@@
  <script src="@@JS_URL@@" defer></script>
@@GA4@@
@@PIXEL@@
</head>
<body data-tela="@@ID@@">
  <script>document.documentElement.classList.add('js');</script>
@@HEADER@@

  <main id="@@ID@@">
    <div class="ck-teste" id="ck-teste" hidden>Modo de teste: o valor cobrado não é o preço real.</div>
    <section class="ck-hero">
      <div class="wrap">
        <p class="eyebrow">@@EYEBROW@@</p>
        <h1>@@H1@@</h1>
        <p class="lead">@@LEAD@@</p>
@@PASSOS@@
      </div>
    </section>
    <section class="ck-secao">
      <div class="wrap"@@WRAP_EXTRA@@>
        <noscript><p class="ck-noscript">@@NOSCRIPT@@ Ative o JavaScript ou escreva para contato@cruzvermelhariodejaneiro.org.</p></noscript>
@@CORPO@@
      </div>
    </section>
  </main>

@@FOOTER@@

@@MENU_JS@@
@@CHAT@@
</body>
</html>
"""

# CSS do pagar tudo (spec 1.12 e 1.13), no padrão visual do checkout da escola (cartões de plano, forma de pagamento só
# com ícone e rótulo, barra de ação com "Total"/"Agora", destaque "Você paga", etapas do PIX) com os tokens da home
# (--red, --black, --muted, --line, --soft, --text). Vai nas telas do checkout, da Pendente e da Parabéns.
CSS_PAGAR_TUDO = """  <style>/* Pagar tudo (10/2026): planos, parcelas, oferta a prazo, barra de ação e telas. O checkout.css não muda. */
    .ck-card .btn { text-align: center; }
    .ck-rot-secao { margin: 0 0 10px; font-size: .74rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); }
    .ck-planos { display: grid; gap: 10px; margin: 0 0 22px; }
    .ck-plano { position: relative; display: block; border: 1.5px solid #cbd5e1; border-radius: 14px; padding: 14px 16px 14px 48px; cursor: pointer; background: #fff; transition: border-color .15s, background .15s, box-shadow .15s; }
    .ck-plano:hover { border-color: #94a3b8; }
    .ck-plano input { position: absolute; left: 17px; top: 17px; margin: 0; width: 18px; height: 18px; accent-color: var(--red); }
    .ck-plano-rot { display: block; color: var(--black); font-weight: 800; font-size: 1rem; line-height: 1.35; }
    .ck-plano-rot b { white-space: nowrap; }
    .ck-plano small { display: block; color: var(--muted); font-size: .87rem; line-height: 1.45; margin-top: 4px; }
    .ck-plano.ativo { border-color: var(--red); background: #fff7f7; box-shadow: inset 0 0 0 1px var(--red); }
    .ck-plano:focus-within { outline: 2px solid rgba(204, 0, 0, .35); outline-offset: 2px; }
    .ck-metodo .ck-metodo-ico { display: inline-flex; margin-right: 8px; color: var(--muted); font-size: 1.05rem; vertical-align: -2px; }
    .ck-metodo.ativo .ck-metodo-ico { color: var(--red); }
    .ck-metodo b { display: inline; }
    .ck-aviso { display: flex; gap: 10px; align-items: flex-start; background: var(--soft); border: 1px solid var(--line); border-radius: 12px; padding: 12px 14px; margin: 0 0 18px; font-size: .9rem; line-height: 1.5; color: var(--text); }
    .ck-aviso > i { color: var(--red); margin-top: 3px; flex-shrink: 0; }
    .ck-aviso b { color: var(--black); }
    .ck-grid > .ck-card { min-width: 0; } /* o seletor de parcelas, com linhas longas, não alarga a grade no celular */
    .ck-parcelas-box { margin: -4px 0 18px; }
    .ck-parcelas-box select { max-width: 100%; text-overflow: ellipsis; }
    .ck-parcelas-box .ck-campo { margin-bottom: 10px; }
    .ck-parcelas-box > .ck-nota { margin: 0 0 10px; }
    .ck-conta { border: 1px solid var(--line); border-radius: 12px; overflow: hidden; margin: 0 0 12px; background: #fff; }
    .ck-conta-linha { display: flex; justify-content: space-between; gap: 12px; padding: 10px 14px; border-bottom: 1px solid var(--line); font-size: .92rem; color: var(--text); }
    .ck-conta-linha b { color: var(--black); white-space: nowrap; }
    .ck-conta-total { background: var(--soft); font-weight: 800; color: var(--black); }
    .ck-conta-total b { color: var(--red); font-size: 1.05rem; }
    .ck-conta-nota { margin: 0; padding: 9px 14px 11px; font-size: .84rem; color: var(--muted); line-height: 1.45; }
    .ck-oferta { margin: 0 0 12px; padding: 12px 14px; border: 1px dashed #cbd5e1; border-radius: 12px; background: #fff; font-size: .84rem; line-height: 1.55; color: var(--text); }
    .ck-oferta a { color: var(--red); text-decoration: underline; }
    .ck-vocepaga { display: grid; gap: 2px; text-align: center; border: 1px solid #cfe6d8; border-radius: 14px; background: #eefaf5; padding: 14px; }
    .ck-vocepaga > span { font-size: .72rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; color: #0d7a58; }
    .ck-vocepaga strong { font-size: 1.3rem; font-weight: 800; letter-spacing: -.02em; line-height: 1.25; color: #0a5c43; }
    .ck-vocepaga small { font-size: .82rem; color: var(--muted); }
    .ck-condicoes { border: 1px solid var(--line); border-radius: 12px; background: var(--soft); padding: 14px 16px; margin: 4px 0 14px; }
    .ck-condicoes h3 { margin: 0 0 6px; color: var(--black); font-size: .98rem; letter-spacing: -.01em; }
    .ck-condicoes p { margin: 0; font-size: .86rem; line-height: 1.55; color: var(--text); }
    .ck-acao { display: flex; align-items: center; gap: 14px; margin-top: 8px; padding-top: 14px; border-top: 1px solid var(--line); }
    .ck-acao-valor { flex: none; line-height: 1.15; }
    .ck-acao-valor em { display: block; font-style: normal; font-size: .72rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
    .ck-acao-valor strong { display: block; font-size: 1.35rem; font-weight: 800; letter-spacing: -.02em; color: var(--black); white-space: nowrap; }
    .ck-acao .ck-btn { flex: 1 1 auto; width: auto; min-width: 0; margin-top: 0; }
    .ck-erro .btn { margin-top: 10px; }
    .ck-rosa { margin: 10px 0 0; padding: 10px 12px; border: 1px solid #ffcfcf; border-radius: 12px; background: #fff3f3; color: #990000; font-size: .86rem; line-height: 1.5; }
    .ck-rosa b { color: #990000; }
    .ck-resumo .ck-linha span:last-child { white-space: nowrap; }
    .ck-pix-valor { display: inline-grid; gap: 2px; margin: 4px 0 0; }
    .ck-pix-valor span { font-size: .72rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); }
    .ck-pix-valor strong { font-size: 1.6rem; font-weight: 800; letter-spacing: -.02em; color: var(--black); }
    .ck-prazo { margin: 10px auto 0; max-width: 46ch; font-weight: 700; color: var(--black); }
    .ck-etapas { list-style: none; margin: 18px auto 0; padding: 0; max-width: 440px; display: grid; gap: 8px; text-align: left; }
    .ck-etapa { display: flex; align-items: center; gap: 12px; border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px; background: #fff; }
    .ck-etapa-n { flex: none; width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: .8rem; font-weight: 800; background: var(--soft); border: 2px solid var(--line); color: var(--muted); }
    .ck-etapa p { margin: 0; flex: 1; min-width: 0; font-size: .92rem; font-weight: 600; line-height: 1.3; color: var(--black); }
    .ck-etapa p small { display: block; font-size: .7rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
    .ck-etapa-badge { flex: none; font-size: .72rem; font-weight: 800; border-radius: 999px; padding: 3px 9px; background: var(--soft); color: var(--muted); }
    .ck-etapa.feito .ck-etapa-n { background: #0f7b3e; border-color: #0f7b3e; color: #fff; }
    .ck-etapa.feito .ck-etapa-badge { background: #e9f7ef; color: #0f7b3e; }
    .ck-etapa.atual { border-color: var(--red); }
    .ck-etapa.atual .ck-etapa-n { background: var(--red); border-color: var(--red); color: #fff; }
    .ck-etapa.atual .ck-etapa-badge { background: #fff3f3; color: var(--red); }
    .ck-selo-pago { display: inline-block; margin: 0 0 8px; padding: 4px 12px; border-radius: 999px; background: #e9f7ef; color: #0f7b3e; font-size: .78rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
    .ck-pilula { display: inline-flex; align-items: center; gap: 6px; margin: 0 0 10px; padding: 5px 12px; border-radius: 999px; background: #e9f7ef; color: #0f7b3e; font-size: .86rem; font-weight: 700; }
    .ck-pilula.breve { background: #fff7e6; color: #8a5300; }
    .ck-turma-linha { margin: 0 0 10px; font-weight: 700; color: var(--black); }
    .ck-falta { margin: 10px 0 12px; padding: 12px 14px; border: 1px solid #ffcfcf; border-radius: 12px; background: #fff3f3; color: #990000; line-height: 1.5; }
    .ck-falta b { display: block; margin: 0 0 2px; color: var(--red); font-size: 1.02rem; }
    @media (max-width: 920px) {
      .ck-resumo .ck-r-taxa-so:not([hidden]) { grid-column: 1 / -1; display: block; }
      .ck-resumo .ck-r-taxa-so .ck-nota { display: block; }
    }
    @media (max-width: 480px) {
      .ck-acao { flex-wrap: wrap; }
      .ck-acao .ck-btn { flex-basis: 100%; }
      .ck-plano { padding: 13px 14px 13px 44px; }
      .ck-plano input { left: 15px; top: 16px; }
    }
    @media (max-width: 374px) { .ck-passos { flex-wrap: nowrap; } .ck-passos li { min-width: 0; } }
    /* Textos longos (e-mail, caixa da oferta a prazo) quebram dentro da caixa a 320 px; o CNPJ não quebra no hífen. */
    .ck-oferta, .ck-legal { overflow-wrap: anywhere; }
    .ck-nowrap { white-space: nowrap; }
    /* Seletor de parcelas no celular: o rótulo curto (N× · total) e, abaixo, a linha completa das parcelas. */
    .ck-parcelas-linha { margin: -4px 0 10px; font-size: .86rem; line-height: 1.45; color: var(--text); }
    /* Resumo lateral preso (desktop): a foto encolhe e some em telas baixas; se ainda passar da altura da tela, o resumo
       solta (checkout.js, ajustarResumo) para o Total nunca ficar cortado nem embaixo do botão do chat. */
    @media (min-width: 921px) { .ck-resumo-foto { aspect-ratio: 16 / 9; } }
    @media (min-width: 921px) and (max-height: 900px) { .ck-resumo-foto { display: none; } }
    .ck-resumo.ck-resumo-solto { position: static; }
    /* Botão do chat no celular: um círculo de 56 px, como na página de cursos, para não cobrir o formulário. */
    @media (max-width: 920px) {
      html body .cv-chat .cv-chat-abrir { width: 56px; padding: 0; justify-content: center; }
      html body .cv-chat .cv-chat-abrir-rotulo { position: absolute; width: 1px; height: 1px; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); clip-path: inset(50%); white-space: nowrap; }
      .ck-grid { padding-bottom: 72px; }
    }
  </style>"""

CORPO_CHECKOUT = """        <div class="ck-grid">
          <div class="ck-card">
            <form id="ck-form" novalidate autocomplete="on" data-inscricao-centavos="@@INSCRICAO_CENTAVOS@@">
              <div class="ck-armadilha" aria-hidden="true"><label>Não preencha este campo<input id="ck-site" name="site" tabindex="-1" autocomplete="off"></label></div>
              <section class="ck-bloco-form" aria-labelledby="ck-t1">
                <h2 class="ck-bloco-titulo" id="ck-t1"><span class="ck-num">1</span> Curso</h2>
                <label class="ck-campo"><span>Curso presencial</span>
                  <select id="ck-curso" name="curso" required>
                    <option value="" disabled selected>Escolha o curso</option>@@OPCOES@@
                  </select>
                </label>
                <ul class="ck-curso-meta" id="ck-curso-meta" hidden>
                  <li><i class="fa-regular fa-clock" aria-hidden="true"></i> <span id="ck-m-carga"></span></li>
                  <li><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i> <span id="ck-m-escolaridade"></span></li>
                  <li><i class="fa-solid fa-location-dot" aria-hidden="true"></i> Praça da Cruz Vermelha, 10 · Centro</li>
                </ul>
              </section>
              <section class="ck-bloco-form" aria-labelledby="ck-t2">
                <h2 class="ck-bloco-titulo" id="ck-t2"><span class="ck-num">2</span> Seus dados <small>para a inscrição e a área do aluno</small></h2>
                <label class="ck-campo"><span>Nome completo</span><input id="ck-nome" name="nome" autocomplete="name" placeholder="Como está no seu documento" required></label>
                <div class="ck-2col">
                  <label class="ck-campo"><span>CPF</span><input id="ck-cpf" name="cpf" inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" required></label>
                  <label class="ck-campo"><span>Telefone (celular)</span><input id="ck-telefone" name="telefone" inputmode="tel" autocomplete="tel" placeholder="(21) 99999-9999" required></label>
                </div>
                <label class="ck-campo"><span>E-mail</span><input id="ck-email" type="email" name="email" autocomplete="email" placeholder="voce@exemplo.com" required><small class="ck-nota">A confirmação e o acesso à área do aluno chegam neste e-mail.</small></label>
              </section>
              <section class="ck-bloco-form" aria-labelledby="ck-t3">
                <h2 class="ck-bloco-titulo" id="ck-t3"><span class="ck-num">3</span> Pagamento</h2>
                <p class="ck-rot-secao" id="ck-planos-titulo">Plano de pagamento</p>
                <div class="ck-planos" id="ck-planos" role="radiogroup" aria-labelledby="ck-planos-titulo">
                  <label class="ck-plano@@P1_ATIVO@@" id="ck-plano-completo"@@P1_OCULTO@@><input type="radio" name="plano" value="taxa_e_matricula"@@P1_MARCADO@@><span class="ck-plano-rot">Taxa de inscrição + matrícula, à vista<span id="ck-plano-completo-valor"></span></span><small id="ck-plano-completo-legenda">um pagamento só. A entrada na aula é liberada com a matrícula paga.</small></label>
                  <label class="ck-plano@@P2_ATIVO@@" id="ck-plano-taxa"><input type="radio" name="plano" value="so_taxa"@@P2_MARCADO@@><span class="ck-plano-rot">Só a taxa de inscrição — <b id="ck-plano-taxa-valor">@@INSCRICAO@@</b></span><small id="ck-plano-taxa-legenda">Quem pagou apenas a inscrição não tem a entrada liberada.</small></label>
                </div>
                <p class="ck-rot-secao" id="ck-metodos-titulo">Forma de pagamento</p>
                <div class="ck-metodos" role="radiogroup" aria-labelledby="ck-metodos-titulo">
                  <label class="ck-metodo ativo"><input type="radio" name="metodo" value="pix" checked><span class="ck-metodo-ico"><i class="fa-brands fa-pix" aria-hidden="true"></i></span><b>PIX</b></label>
                  <label class="ck-metodo"><input type="radio" name="metodo" value="cartao"><span class="ck-metodo-ico"><i class="fa-regular fa-credit-card" aria-hidden="true"></i></span><b>Crédito</b></label>
                </div>
                <p class="ck-aviso" id="ck-pix-aviso"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>O <b>QR Code aparece nesta tela</b>, depois que você tocar em Gerar QR Code. Pague pelo app do banco e volte: a página avança sozinha quando o banco confirmar. <b>Não feche esta janela.</b></span></p>
                <div class="ck-cartao-box" id="ck-cartao" hidden>
                  <label class="ck-campo"><span>Número do cartão</span><input id="ck-cartao-numero" inputmode="numeric" autocomplete="cc-number" placeholder="0000 0000 0000 0000"></label>
                  <label class="ck-campo"><span>Nome impresso no cartão</span><input id="ck-cartao-nome" autocomplete="cc-name"></label>
                  <div class="ck-2col">
                    <label class="ck-campo"><span>Validade</span><input id="ck-cartao-validade" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/AA"></label>
                    <label class="ck-campo"><span>CVV</span><input id="ck-cartao-cvv" inputmode="numeric" autocomplete="cc-csc" placeholder="123"></label>
                  </div>
                  <p class="ck-nota"><i class="fa-solid fa-lock" aria-hidden="true"></i> Os dados vão direto para a operadora. Não guardamos o cartão.</p>
                </div>
                <div class="ck-parcelas-box" id="ck-parcelas-box" hidden>
                  <label class="ck-campo" id="ck-parcelas-campo" hidden><span>Parcelas</span><select id="ck-parcelas" name="parcelas"><option value="1">À vista</option></select></label>
                  <p class="ck-parcelas-linha" id="ck-parcelas-linha" hidden></p>
                  <p class="ck-nota" id="ck-parcelas-nota" role="status" aria-live="polite"></p>
                  <div id="ck-juros-conta" hidden>
                    <div class="ck-conta">
                      <div class="ck-conta-linha"><span id="ck-juros-rotulo">Juros do parcelamento</span><b id="ck-juros-valor"></b></div>
                      <div class="ck-conta-linha ck-conta-total"><span>Total</span><b id="ck-juros-total"></b></div>
                      <p class="ck-conta-nota" id="ck-juros-nota"></p>
                    </div>
                    <p class="ck-oferta" id="ck-oferta-prazo"></p>
                    <div class="ck-vocepaga"><span>Você paga</span><strong id="ck-vocepaga-valor"></strong><small>no cartão de crédito</small></div>
                  </div>
                </div>
                <label class="ck-check" id="ck-cobre-opcao"><input type="checkbox" id="ck-cobre" name="cobre_taxa"><span class="ck-check-texto">Quero cobrir os custos de processamento<small id="ck-cobre-legenda">@@COBRE_LEGENDA@@</small></span><span class="ck-check-valor" id="ck-taxa-valor">+ R$ 0,00</span></label>
                <label class="ck-check" id="ck-divulgacao-opcao"@@DIVULGACAO_OCULTA@@><input type="checkbox" id="ck-divulgacao" name="ajuda_divulgacao" data-centavos="@@DIVULGACAO_CENTAVOS@@"><span class="ck-check-texto">Quero ajudar a divulgar os cursos<small>Opcional. O valor vai para a divulgação dos cursos, para que cheguem a mais pessoas.</small></span><span class="ck-check-valor" id="ck-divulgacao-valor">+ @@DIVULGACAO@@</span></label>
                <label class="ck-check"><input type="checkbox" id="ck-requisitos" name="requisitos" required data-aceite-versao="@@ACEITE_VERSAO@@"><span class="ck-check-texto" id="ck-requisitos-texto">@@ACEITE@@</span></label>
                <span id="ck-escolaridade" hidden></span>
                <div class="ck-condicoes" id="ck-condicoes"@@P1_OCULTO@@><h3>Resumo das condições</h3><p id="ck-condicoes-texto"></p></div>
                <div class="ck-erro" id="ck-erro" role="alert" aria-live="assertive"></div>
                <div class="ck-acao">
                  <div class="ck-acao-valor"><em id="ck-acao-rotulo">@@ACAO_ROTULO@@</em><strong id="ck-total-btn">@@INSCRICAO@@</strong></div>
                  <button class="btn btn-red ck-btn" type="submit" id="ck-pagar"><span id="ck-pagar-texto">Gerar QR Code</span></button>
                </div>
                <ul class="ck-confianca">
                  <li><i class="fa-solid fa-certificate" aria-hidden="true"></i> Certificado da Cruz Vermelha Brasileira Rio de Janeiro</li>
                  <li><i class="fa-solid fa-location-dot" aria-hidden="true"></i> Aulas presenciais no Centro do Rio</li>
                  <li><i class="fa-solid fa-lock" aria-hidden="true"></i> <span id="ck-selo-pagamento">PIX ou cartão, à vista</span></li>
                </ul>
                <div id="ck-legal-hoje"@@LEGAL_HOJE_OCULTO@@>
                  <p class="ck-nota ck-legal" style="margin:14px 0 0">Ao pagar, você concorda com os <a href="/termos/">Termos de Uso</a> e com as regras de <a href="/reembolso/">cancelamento e reembolso</a>: dá para desistir em até 7 dias depois do pagamento e receber o valor de volta (art. 49 do Código de Defesa do Consumidor). Seus dados são usados para a matrícula, a cobrança e os avisos sobre as suas aulas, como explica a <a href="/privacidade/">Política de Privacidade</a>.</p>
                  <p class="ck-nota ck-legal" style="margin:8px 0 0">Cruz Vermelha Brasileira — Filial do Estado do Rio de Janeiro · CNPJ <span class="ck-nowrap">08.560.973/0001-97</span> · Praça da Cruz Vermelha, 10, Centro, Rio de Janeiro/RJ · contato@cruzvermelhariodejaneiro.org</p>
                </div>
                <div id="ck-legal-novo"@@LEGAL_NOVO_OCULTO@@>
                  <p class="ck-nota ck-legal" style="margin:14px 0 0">Ao pagar, você concorda com os <a href="/termos/">Termos de Uso</a> e com as regras de <a href="/reembolso/">cancelamento e reembolso</a>: dá para desistir em até 7 dias depois do pagamento e receber de volta tudo o que pagou, inclusive a matrícula e os juros do parcelamento (art. 49 do Código de Defesa do Consumidor). Seus dados são usados para a inscrição, o acesso à área do aluno, a cobrança e os avisos sobre as suas aulas, como explica a <a href="/privacidade/">Política de Privacidade</a>.</p>
                  <p class="ck-nota ck-legal" id="ck-fornecedor" style="margin:8px 0 0">Venda e recebimento: @@RECEBEDOR_NOME@@, CNPJ <span class="ck-nowrap">@@RECEBEDOR_CNPJ@@</span> · Praça da Cruz Vermelha, 10, Centro, Rio de Janeiro/RJ · contato@cruzvermelhariodejaneiro.org.</p>
                </div>
              </section>
            </form>
            <div id="ck-pix" hidden aria-live="polite"></div>
          </div>
          <aside class="ck-card ck-resumo" aria-label="Resumo da inscrição">
            <img class="ck-resumo-foto" id="ck-r-foto" src="/assets/hero-cursos-banner-1.jpg" alt="" width="480" height="360">
            <p class="ck-resumo-rotulo">Inscrição</p>
            <p class="ck-resumo-curso" id="ck-r-curso">Escolha o curso</p>
            <span class="ck-resumo-meta" id="ck-r-meta">7 cursos presenciais no Centro do Rio</span>
            <div class="ck-linha" id="ck-r-matricula-linha"@@P1_OCULTO@@><span>Valor da matrícula</span><span id="ck-r-matricula">—</span></div>
            <div class="ck-linha"><span id="ck-r-inscricao-rotulo">@@INSCRICAO_ROTULO@@</span><span id="ck-r-inscricao">@@INSCRICAO@@</span></div>
            <div class="ck-linha" id="ck-r-taxa-linha" hidden><span>Custos de processamento</span><span id="ck-r-taxa">R$ 0,00</span></div>
            <div class="ck-linha" id="ck-r-divulgacao-linha" hidden><span>Divulgação dos cursos</span><span id="ck-r-divulgacao">@@DIVULGACAO@@</span></div>
            <div class="ck-linha" id="ck-r-juros-linha" hidden><span id="ck-r-juros-rotulo">Juros do parcelamento</span><span id="ck-r-juros">R$ 0,00</span></div>
            <div class="ck-linha total"><span id="ck-r-total-rotulo">@@ACAO_ROTULO@@</span><span id="ck-r-total">@@INSCRICAO@@</span></div>
            <div class="ck-r-taxa-so" id="ck-r-so-taxa" hidden>
              <p class="ck-nota">Valor da matrícula, à vista <b id="ck-r-curso-valor">—</b> · Taxa de inscrição <b id="ck-r-inscricao-avista">@@INSCRICAO@@</b> · Total à vista, com a inscrição <b id="ck-r-total-curso">—</b></p>
              <p class="ck-rosa">A participação nas aulas é liberada só com a <b>matrícula paga</b>, além da taxa de inscrição. Quem pagou apenas a inscrição não tem a entrada liberada.</p>
            </div>
            <p class="ck-nota" id="ck-r-homologacao" hidden>A homologação não está incluída: é paga à parte, no fim do curso (valor a consultar).</p>
            <ul class="ck-depois" aria-label="O que acontece depois">
              <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Se você ainda não tem conta na área do aluno, ela é criada quando o pagamento é confirmado. O link para criar a senha chega no seu e-mail e vale 72 horas. Se já tem conta, use a mesma senha.</li>
              <li id="ck-depois-turma"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> <span>Antes da primeira aula, você recebe por e-mail e na sua conta as orientações da turma: data, horário, local e o que vestir. A entrada na aula é liberada com a matrícula paga.</span></li>
              <li id="ck-depois-prazo" hidden><i class="fa-solid fa-circle-check" aria-hidden="true"></i> <span></span></li>
            </ul>
          </aside>
        </div>
        <script type="application/json" id="ck-cursos">@@CURSOS_JSON@@</script>"""

CORPO_PENDENTE = """        <div class="ck-card" id="pd-card" aria-live="polite"><p>Carregando…</p></div>"""
CORPO_PARABENS = """        <div class="ck-card" id="pb-card" aria-live="polite"><p>Carregando…</p></div>"""
CORPO_HORARIOS = """        <div class="ck-card" id="hr-card" aria-live="polite"><p>Carregando…</p></div>"""
CORPO_COMPARECIMENTO = """        <div class="ck-card" id="cp-card" aria-live="polite"><p>Carregando…</p></div>"""
CORPO_CONFERIR = """        <div class="ck-card" id="cf-card" aria-live="polite"><p>Carregando…</p></div>"""

# Ponto da sede (29/09/2026): página própria, sem o cabeçalho do site, sem medição e sem chat. Fica
# aberta no aparelho da recepção e também no celular de quem lê o QR code do cartaz.
PAGINA_PONTO = """<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#cc0000">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <link rel="manifest" href="/matricula-cursos-presenciais/ponto/manifest.json">
  <link rel="apple-touch-icon" href="/matricula-cursos-presenciais/ponto/icone-192.png">
  <meta name="apple-mobile-web-app-title" content="Ponto CVB-RJ">
  <meta name="mobile-web-app-capable" content="yes">
  <title>Ponto da sede | Cruz Vermelha Brasileira Rio de Janeiro</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap">
  <link rel="stylesheet" href="@@CSS_URL@@">
  <script src="@@JS_URL@@" defer></script>
</head>
<body>
  <div class="pt-faixa"></div>
  <header class="pt-topo">
    <img src="/assets/otim/logo-cvb-rj-480.png" alt="Cruz Vermelha Brasileira · Rio de Janeiro" width="160" height="48">
    <div class="pt-titulo"><b>Ponto da sede</b><small id="pt-modo">Colaboradores e alunos</small></div>
    <div class="pt-relogio"><b id="pt-hora">--:--</b><small id="pt-data"></small></div>
  </header>
  <main class="pt-main">
    <section class="pt-card" id="pt-tela"><p>Carregando…</p></section>
    <noscript><p class="pt-card">O ponto precisa de JavaScript. Ative o JavaScript ou fale com a secretaria.</p></noscript>
  </main>
  <footer class="pt-rodape">
    <p>O registro serve para reconhecer as horas doadas pelos voluntários e para saber quem está na sede. Não é controle de jornada. No celular, a localização só confirma que você está na sede e não fica guardada. <a href="/privacidade/">Privacidade</a></p>
  </footer>
</body>
</html>
"""

# Páginas pessoais dos avisos do ponto (30/09/2026): lembretes, saída sem registro, opinião e "não quero
# mais receber". Mesmo visual do ponto; o conteúdo vem de static/avisos.js com o token do link.
PAGINA_AVISO = """<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="no-referrer">
  <meta name="theme-color" content="#cc0000">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <title>@@TITULO@@ | Cruz Vermelha Brasileira Rio de Janeiro</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap">
  <link rel="stylesheet" href="@@CSS_URL@@">
  <script src="@@JS_URL@@" defer></script>
</head>
<body data-pagina="@@PAGINA@@">
  <div class="pt-faixa"></div>
  <header class="pt-topo">
    <a href="/"><img src="/assets/otim/logo-cvb-rj-480.png" alt="Cruz Vermelha Brasileira · Rio de Janeiro" width="160" height="48"></a>
    <div class="pt-titulo"><b>@@CABECALHO@@</b><small>@@SUBTITULO@@</small></div>
  </header>
  <main class="pt-main">
    <section class="pt-card" id="av-tela"><p>Carregando…</p></section>
    <noscript><p class="pt-card">Esta página precisa de JavaScript. Ative o JavaScript ou fale com a secretaria.</p></noscript>
  </main>
  <footer class="pt-rodape">
    <p>@@RODAPE@@ <a href="/privacidade/">Privacidade</a></p>
  </footer>
</body>
</html>
"""
AVISOS_PAGINAS = {
    "lembretes": ("Seus lembretes", "Ponto da sede", "Seus lembretes",
                  "Os lembretes servem para você não esquecer de registrar a chegada e a saída. Você muda ou para quando quiser."),
    "saida": ("Saída sem registro", "Ponto da sede", "Saída sem registro",
              "A secretaria confere o horário informado antes de ele entrar nas suas horas doadas."),
    "opiniao": ("Sua opinião sobre o ponto", "Ponto da sede", "Sua opinião",
                "A secretaria vê as respostas sem o seu nome, a não ser que você autorize o contato."),
    "sair": ("Não receber mais avisos", "Escola de Educação e Saúde", "Avisos por e-mail e WhatsApp",
             "Os e-mails da matrícula e o comprovante de comparecimento continuam chegando."),
}
# Instalar o ponto no celular como um aplicativo (tela inicial). O ícone é o do site (cruz vermelha em fundo branco).
MANIFESTO_PONTO = {
    "name": "Ponto da sede · Cruz Vermelha Brasileira RJ",
    "short_name": "Ponto CVB-RJ",
    "description": "Registre a chegada e a saída na sede da Cruz Vermelha Brasileira Rio de Janeiro.",
    "start_url": "/matricula-cursos-presenciais/ponto/?origem=app",
    "scope": "/matricula-cursos-presenciais/ponto/",
    "display": "standalone",
    "background_color": "#f5f6f8",
    "theme_color": "#cc0000",
    "lang": "pt-BR",
    "icons": [
        {"src": "/matricula-cursos-presenciais/ponto/icone-192.png", "sizes": "192x192", "type": "image/png", "purpose": "any"},
        {"src": "/matricula-cursos-presenciais/ponto/icone-512.png", "sizes": "512x512", "type": "image/png", "purpose": "any"},
        {"src": "/matricula-cursos-presenciais/ponto/icone-512-mascara.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable"},
    ],
}


def gravar_icones_ponto(pasta: Path) -> None:
    """Ícones do ponto instalado no celular: a cruz do favicon.svg desenhada em PNG (192 e 512 px, e o de máscara com margem)."""
    from PIL import Image, ImageDraw
    for tamanho, nome, escala, arredondar in ((192, "icone-192.png", 1.0, True), (512, "icone-512.png", 1.0, True), (512, "icone-512-mascara.png", 0.72, False)):
        fator = 4  # desenha grande e reduz, para as bordas ficarem lisas
        t = tamanho * fator
        img = Image.new("RGBA", (t, t), (255, 255, 255, 0 if arredondar else 255))
        d = ImageDraw.Draw(img)
        if arredondar:
            d.rounded_rectangle([0, 0, t - 1, t - 1], radius=int(t * 12 / 64), fill=(255, 255, 255, 255))
        # Cruz do favicon.svg (viewBox 64): braços de 16 de largura, de 8 a 56.
        u = t / 64 * escala
        c = t / 2
        braco, meia = 16 * u, 24 * u
        d.rectangle([c - braco / 2, c - meia, c + braco / 2, c + meia], fill=(237, 27, 46, 255))
        d.rectangle([c - meia, c - braco / 2, c + meia, c + braco / 2], fill=(237, 27, 46, 255))
        img.resize((tamanho, tamanho), Image.LANCZOS).save(pasta / nome, optimize=True)


# Cartaz A4 para imprimir e colar na recepção: o QR code abre o ponto no celular.
PAGINA_CARTAZ = """<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <title>Cartaz do ponto da sede | Cruz Vermelha Brasileira Rio de Janeiro</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>
  <style>
    @page{size:A4;margin:0}
    *{box-sizing:border-box}
    body{margin:0;background:#e9ecf0;font-family:Inter,Arial,sans-serif;color:#1a202c;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .acoes{max-width:210mm;margin:16px auto;display:flex;gap:14px;align-items:center;flex-wrap:wrap;padding:0 12px;font-size:14px;color:#4a5568}
    .acoes button{font:inherit;font-weight:800;border:0;border-radius:999px;background:#cc0000;color:#fff;padding:12px 22px;cursor:pointer}
    .cartaz{width:210mm;height:297mm;overflow:hidden;margin:0 auto 24px;background:#fff;position:relative;padding:18mm 18mm 12mm;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,.12)}
    .cartaz:before{content:"";position:absolute;left:0;right:0;top:0;height:8mm;background:#cc0000}
    .logo{height:19mm;width:auto;display:block;margin:2mm auto 7mm}
    h1{margin:0;font-size:21mm;line-height:1;font-weight:900;letter-spacing:-.03em;color:#0f1318}
    .lead{margin:4mm 0 7mm;font-size:6.6mm;font-weight:700;color:#cc0000;line-height:1.2}
    #qr{width:84mm;height:84mm;margin:0 auto;padding:4.5mm;border:1.2mm solid #0f1318;border-radius:6mm;display:flex;align-items:center;justify-content:center}
    #qr img,#qr canvas{width:73mm!important;height:73mm!important}
    .url{margin:4mm 0 7mm;font-size:5.6mm;font-weight:800;color:#0f1318;letter-spacing:.02em}
    ol{list-style:none;counter-reset:passo;margin:0 auto;padding:0;max-width:152mm;text-align:left}
    ol li{counter-increment:passo;display:flex;gap:4.5mm;align-items:flex-start;margin:0 0 4mm;font-size:5mm;line-height:1.3}
    ol li:before{content:counter(passo);flex:0 0 9mm;height:9mm;border-radius:50%;background:#cc0000;color:#fff;font-weight:900;display:flex;align-items:center;justify-content:center;font-size:5mm}
    ol li span{padding-top:1.3mm}
    .duas{display:grid;grid-template-columns:1fr 1fr;gap:5mm;margin:6mm auto 0;max-width:174mm;text-align:left}
    .duas div{background:#f5f6f8;border-radius:5mm;padding:4mm 5mm}
    .duas b{display:block;font-size:4.6mm;color:#0f1318;margin:0 0 1.2mm}
    .duas p{margin:0;font-size:3.9mm;line-height:1.35;color:#4a5568}
    .rodape{margin:7mm 0 0;font-size:4.4mm;font-weight:700;color:#4a5568}
    @media print{body{background:#fff}.acoes{display:none}.cartaz{margin:0;box-shadow:none}}
  </style>
</head>
<body>
  <div class="acoes"><button type="button" onclick="window.print()">Imprimir cartaz</button><span>Imprima em A4, em cores, e cole perto da entrada ou da recepção.</span></div>
  <article class="cartaz">
    <img class="logo" src="/assets/otim/logo-cvb-rj-480.png" alt="Cruz Vermelha Brasileira · Rio de Janeiro">
    <h1>Ponto da sede</h1>
    <p class="lead">Registre a chegada e a saída pelo celular</p>
    <div id="qr" role="img" aria-label="QR code que abre o ponto da sede: @@URL@@"></div>
    <p class="url">@@URL_VISIVEL@@</p>
    <ol>
      <li><span>Aponte a câmera do celular para o código.</span></li>
      <li><span>Digite seu CPF.</span></li>
      <li><span>Permita a localização e toque em <b>Registrar entrada</b>, <b>Registrar saída</b> ou <b>Confirmar presença</b>.</span></li>
    </ol>
    <div class="duas">
      <div><b>Colaboradores</b><p>Voluntários somam as horas doadas a cada entrada e saída. Os demais registram a presença na sede.</p></div>
      <div><b>Alunos</b><p>Confirme a presença na aula. O comprovante de comparecimento chega por e-mail no fim da aula.</p></div>
    </div>
    <p class="rodape">Sem celular? Use o aparelho da recepção.</p>
  </article>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      if (window.QRCode) new QRCode(document.getElementById('qr'), { text: '@@URL@@', width: 600, height: 600, correctLevel: QRCode.CorrectLevel.M });
      else document.getElementById('qr').textContent = '@@URL_VISIVEL@@';
    });
  </script>
</body>
</html>
"""
URL_PONTO = "https://cruzvermelhariodejaneiro.org/ponto/"

QRCODE = '  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>'


def brl(centavos: int) -> str:
    return "R$ " + f"{centavos / 100:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")


def url_estatico(nome: str) -> str:
    """URL do arquivo em static/ com hash do conteúdo: muda o arquivo, muda a URL, o cache não segura versão velha."""
    conteudo = (STATIC / nome).read_bytes()
    return f"{STATIC_URL}{nome}?v={hashlib.sha256(conteudo).hexdigest()[:10]}"


# ----------------------------------------------------------------------------- oferta.json (turmas)
def _data_iso(valor) -> datetime | None:
    """Data ISO com fuso ("2026-10-21T09:00:00-03:00"); sem fuso, vale o horário de Brasília. Inválida: None."""
    if not isinstance(valor, str) or not valor:
        return None
    try:
        d = datetime.fromisoformat(valor.replace("Z", "+00:00"))
    except ValueError:
        return None
    return d if d.tzinfo else d.replace(tzinfo=BRT)


def ler_oferta() -> dict:
    """O oferta.json (spec 2.5), com as mesmas regras de api/lib/compra.php (mcp_oferta): turma sem curso, id_escola,
    início ou fim das inscrições é ignorada; arquivo ausente ou inválido = nenhuma turma. MCP_OFERTA_ARQUIVO troca o
    caminho (testes)."""
    caminho = Path(os.environ.get("MCP_OFERTA_ARQUIVO") or OFERTA)
    padrao = {"parcelado_no_ar": False, "turmas": [], "sem_turma": {}}
    try:
        dados = json.loads(caminho.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        print(f"aviso: {caminho} ausente ou inválido: nenhuma turma aberta no checkout", file=sys.stderr)
        return padrao
    if not isinstance(dados, dict):
        return padrao
    turmas = []
    for t in dados.get("turmas") or []:
        if not isinstance(t, dict) or not isinstance(t.get("curso"), str) or not isinstance(t.get("id_escola"), str) \
                or _data_iso(t.get("inicio")) is None or _data_iso(t.get("inscricoes_ate")) is None:
            print("aviso: oferta.json: turma ignorada (faltam curso, id_escola, inicio ou inscricoes_ate)", file=sys.stderr)
            continue
        turmas.append({"curso": t["curso"], "id_escola": t["id_escola"], "inicio": t["inicio"],
                       "fim": t["fim"] if _data_iso(t.get("fim")) else None,
                       "inscricoes_ate": t["inscricoes_ate"], "lotada": t.get("lotada") is True})
    sem_turma = dados.get("sem_turma") or {}
    return {"parcelado_no_ar": dados.get("parcelado_no_ar") is True, "turmas": turmas,
            "sem_turma": sem_turma if isinstance(sem_turma, (dict, list)) else {}}


def rotulos_turma(t: dict, agora: datetime) -> dict:
    """Os rótulos da 2.5, no horário de Brasília, iguais aos de mcp_turma_rotulos (api/lib/compra.php)."""
    ini = _data_iso(t["inicio"]).astimezone(BRT)
    fim = _data_iso(t.get("fim"))
    horario = ini.strftime("%H:%M") + (" - " + fim.astimezone(BRT).strftime("%H:%M") if fim else "")
    ate = _data_iso(t["inscricoes_ate"]).astimezone(BRT)
    dias = (ini.date() - agora.astimezone(BRT).date()).days
    return {
        "id_escola": t["id_escola"], "inicio": t["inicio"], "fim": t.get("fim"), "inscricoes_ate": t["inscricoes_ate"],
        "lotada": bool(t.get("lotada")), "horario": horario,
        "data_longa": f"{ini.day} de {MESES[ini.month - 1]} de {ini.year}",
        "data": ini.strftime("%d/%m/%Y"), "dia": ini.strftime("%d/%m"),
        "bloco_dia": ini.strftime("%d"), "bloco_mes": MESES[ini.month - 1],
        "lead": f"{horario} · início {ini.strftime('%d/%m/%Y')}",
        "prazo": f"{DIAS_SEMANA[ate.weekday()]}, {ate.strftime('%d/%m')}, às {ate.strftime('%Hh%M')}",
        "dias_para_inicio": max(0, dias),
    }


def turma_aberta(oferta: dict, slug: str, agora: datetime) -> dict | None:
    """A turma do curso com inscrições abertas (prazo no futuro, não lotada) que começa primeiro, com os rótulos; ou
    None. A mesma regra de mcp_turma_aberta: o checkout.js confere o prazo de novo no navegador."""
    abertas = [t for t in oferta["turmas"] if t["curso"] == slug and not t["lotada"] and _data_iso(t["inscricoes_ate"]) > agora]
    if not abertas:
        return None
    return rotulos_turma(min(abertas, key=lambda t: _data_iso(t["inicio"])), agora)


def passos(atual: int, etapas: list[str] | None = None) -> str:
    """Etapas da inscrição no topo (0 = sem etapas). Os rótulos são os da spec 1.12; o checkout.js troca pelo plano."""
    if atual == 0:
        return ""
    itens = []
    for i, nome in enumerate(etapas or PASSOS_TAXA, 1):
        classe = "feito" if i < atual else ("atual" if i == atual else "")
        marca = '<i class="fa-solid fa-check" aria-hidden="true"></i>' if i < atual else str(i)
        atual_attr = ' aria-current="step"' if i == atual else ""
        itens.append(f'<li class="{classe}"{atual_attr}><span class="ck-passo-n">{marca}</span><span>{nome}</span></li>')
    return '        <ol class="ck-passos" aria-label="Etapas da inscrição">' + "".join(itens) + "</ol>"


# O aviso sem JavaScript diz o que a página faria: gerar o pagamento, conferir o pagamento ou mostrar a inscrição.
NOSCRIPT = {
    "checkout": "Esta página precisa de JavaScript para gerar o pagamento.",
    "pendente": "Esta página precisa de JavaScript para conferir o pagamento.",
    "parabens": "Esta página precisa de JavaScript para mostrar os dados da sua inscrição.",
    "horarios": "Esta página precisa de JavaScript para mostrar o questionário de dias e horários.",
    "comparecimento": "Esta página precisa de JavaScript para mostrar o comprovante.",
    "conferir": "Esta página precisa de JavaScript para conferir o código.",
}
# Telas do pagar tudo: levam o CSS dos planos, das parcelas, do PIX e da Parabéns.
TELAS_PAGAR_TUDO = {"checkout", "pendente", "parabens"}


def montar(partes: dict, titulo: str, id_: str, h1: str, lead: str, corpo: str, qrcode: bool, wrap_extra: str = "", passo: int = 2,
           eyebrow: str = "Curso presencial", etapas: list[str] | None = None) -> str:
    return (PAGINA
            .replace("@@EYEBROW@@", esc(eyebrow))
            .replace("@@NOSCRIPT@@", NOSCRIPT[id_])
            .replace("@@TITULO@@", esc(titulo)).replace("@@QRCODE@@", QRCODE if qrcode else "")
            .replace("@@ESTILO@@", partes["estilo"])
            .replace("@@CSS_URL@@", url_estatico("checkout.css")).replace("@@JS_URL@@", url_estatico("checkout.js"))
            .replace("@@CSS_PAGAR_TUDO@@", CSS_PAGAR_TUDO if id_ in TELAS_PAGAR_TUDO else "  <style>.ck-card .btn { text-align: center; }</style>")
            .replace("@@GA4@@", partes["ga4"]).replace("@@PIXEL@@", partes["pixel"])
            .replace("@@HEADER@@", partes["header"]).replace("@@FOOTER@@", partes["footer"]).replace("@@MENU_JS@@", partes["menu_js"])
            .replace("@@CHAT@@", chat_widget.tags())
            .replace("@@ID@@", id_).replace("@@H1@@", h1).replace("@@LEAD@@", lead).replace("@@WRAP_EXTRA@@", wrap_extra)
            .replace("@@PASSOS@@", passos(passo, etapas)).replace("@@CORPO@@", corpo))


def vocabulario_checkout(partes: dict) -> dict:
    """Só nas telas do checkout (spec 1.1 e trava de vocabulário da 6.1): o botão "Plataforma" do cabeçalho da home vira
    "Área do aluno" (o rótulo da escola) e o link "Plataforma da escola" do rodapé vira "Área do aluno". A home, as
    políticas e as outras páginas que usam o mesmo cabeçalho não mudam aqui. Sem o texto esperado, avisa e segue."""
    header, footer = partes["header"], partes["footer"]
    if header.count("</i> Plataforma</a>") == 1:
        header = header.replace("</i> Plataforma</a>", "</i> Área do aluno</a>")
    elif "Área do aluno</a>" not in header:
        print("aviso: botão Plataforma não encontrado no cabeçalho da home; ficou como está", file=sys.stderr)
    if footer.count(">Plataforma da escola</a>") == 1:
        footer = footer.replace(">Plataforma da escola</a>", ">Área do aluno</a>")
    elif ">Área do aluno</a>" not in footer:
        print("aviso: link Plataforma da escola não encontrado no rodapé da home; ficou como está", file=sys.stderr)
    return dict(partes, header=header, footer=footer)


def medicao_com_plano(partes: dict) -> dict:
    """Repasse à API de Conversões com o plano marcado (spec 4.1, T20): no bloco de medição copiado da home, o
    cvrjMedicao.servidor(evento, id, curso) ganha um 4º parâmetro, plano, que vai no corpo para o medicao.php (que só
    aceita um plano da lista do servidor). Só nestas telas. Se o bloco da home mudou e os trechos não batem, fica como
    está: o servidor usa o plano padrão do curso, que é o marcado quando a página abre."""
    trocas = [
        ("function servidor(evento, id, curso) {", "function servidor(evento, id, curso, plano) {"),
        ("pendentes.push([evento, id, curso]);", "pendentes.push([evento, id, curso, plano]);"),
        ("if (curso) corpo.curso = String(curso);", "if (curso) corpo.curso = String(curso);\n        if (plano) corpo.plano = String(plano);"),
        ("servidor(p[0], p[1], p[2]);", "servidor(p[0], p[1], p[2], p[3]);"),
    ]
    for chave in ("ga4", "pixel", "header"):
        bloco = partes.get(chave, "")
        if trocas[0][0] not in bloco:
            continue
        if all(bloco.count(antigo) == 1 for antigo, _ in trocas):
            for antigo, novo in trocas:
                bloco = bloco.replace(antigo, novo)
            return dict(partes, **{chave: bloco})
        break
    print("aviso: cvrjMedicao.servidor não encontrado como esperado; o repasse do InitiateCheckout vai sem o plano", file=sys.stderr)
    return partes


def main() -> int:
    home = HOME.read_text(encoding="utf-8")
    dados = json.loads(DADOS.read_text(encoding="utf-8"))
    cursos = {c["slug"]: c for c in dados["cursos"]}
    partes = medicao_com_plano(vocabulario_checkout(partes_da_home(home)))
    inscricao_c = int(dados["inscricao_centavos"])
    inscricao = brl(inscricao_c)
    # Contribuição opcional para a divulgação (07/10/2026): o valor vem no HTML para a opção não "pular" na tela; o
    # checkout.js confirma pelo info.php (o config do servidor pode mudar o valor ou desligar a opção, com 0).
    divulgacao = int(dados.get("divulgacao_centavos") or 0)
    # Opção 1 visível e marcada no HTML só na geração do passo 5 (T24). Sem isso, o checkout.js a mostra quando o
    # info.php a trouxer em "planos".
    completo_html = os.environ.get("PLANO_COMPLETO_HTML") == "1"
    agora = datetime.now(timezone.utc)
    oferta = ler_oferta()

    opcoes = ""
    for g in dados["grupos"]:
        itens = "".join(f'\n                    <option value="{s}">{esc(cursos[s]["nome"])} · {esc(cursos[s]["carga_horaria"])}</option>' for s in g["cursos"] if s in cursos)
        opcoes += f'\n                  <optgroup label="{esc(g["titulo"])}">{itens}\n                  </optgroup>'

    # Dados que a página precisa antes da API responder: nome, foto, ficha, matrícula e turma de cada curso. O info.php
    # corrige depois (planos, turma, preço). planos_padrao só oferece a opção 1 com turma e com PLANO_COMPLETO_HTML=1.
    def curso_json(s: str, c: dict) -> dict:
        turma = turma_aberta(oferta, s, agora)
        matricula = int(c["valor_curso_centavos"]) if c.get("valor_curso_centavos") else 0
        item = {
            "nome": c["nome"], "carga_horaria": c.get("carga_horaria", ""), "escolaridade": c.get("escolaridade", ""),
            "valor_curso_centavos": matricula or None, "matricula_centavos": matricula,
            "imagem": f"/matricula-cursos-presenciais/img/{c['imagem']}-480.webp" if c.get("imagem") else None,
            "turma": turma,
            "planos_padrao": ["taxa_e_matricula", "so_taxa"] if completo_html and turma and matricula > 0 else ["so_taxa"],
        }
        # Requisito do curso no aceite (10.4, F14), só quando a escola confirmar: campo novo do cursos.json, no formato
        # ", e sou estudante ou profissional da área da saúde".
        if isinstance(c.get("requisito_aceite"), str) and c["requisito_aceite"].strip():
            item["requisito_aceite"] = c["requisito_aceite"].strip()
        return item

    cursos_json = json.dumps({s: curso_json(s, c) for s, c in cursos.items()}, ensure_ascii=False).replace("</", "<\\/")

    # A versão do texto do aceite (F12): o texto é montado pelo checkout.js, então a versão é o hash dele.
    aceite_versao = "ck-" + hashlib.sha256((STATIC / "checkout.js").read_bytes()).hexdigest()[:10]
    aceite = ("Tenho a escolaridade mínima do curso." if completo_html else
              "Tenho a escolaridade mínima do curso. Sei que a participação nas aulas é liberada só com a matrícula paga, além da taxa de inscrição.")
    oculto1 = "" if completo_html else " hidden"
    corpo_checkout = (CORPO_CHECKOUT
                      .replace("@@OPCOES@@", opcoes).replace("@@CURSOS_JSON@@", cursos_json)
                      .replace("@@INSCRICAO_CENTAVOS@@", str(inscricao_c)).replace("@@INSCRICAO@@", inscricao)
                      .replace("@@P1_ATIVO@@", " ativo" if completo_html else "").replace("@@P1_OCULTO@@", oculto1)
                      .replace("@@P1_MARCADO@@", " checked" if completo_html else "")
                      .replace("@@P2_ATIVO@@", "" if completo_html else " ativo").replace("@@P2_MARCADO@@", "" if completo_html else " checked")
                      .replace("@@ACAO_ROTULO@@", "Total" if completo_html else "Agora")
                      .replace("@@INSCRICAO_ROTULO@@", "Taxa de inscrição" if completo_html else "Taxa de inscrição (agora)")
                      .replace("@@COBRE_LEGENDA@@", "Opcional. Cobre os custos de processamento da taxa de inscrição." if completo_html
                               else "Opcional. Assim a Cruz Vermelha recebe a taxa de inscrição inteira.")
                      .replace("@@ACEITE_VERSAO@@", aceite_versao).replace("@@ACEITE@@", aceite)
                      .replace("@@RECEBEDOR_NOME@@", esc(RECEBEDOR_NOME)).replace("@@RECEBEDOR_CNPJ@@", RECEBEDOR_CNPJ)
                      # Nota legal e fornecedor (reg. 7): com o plano completo desligado (passos 3 e 4, e a volta atrás),
                      # os textos de hoje, iguais aos de /reembolso/ e /termos/ do ar; os novos só com a opção 1 (passo 5).
                      # O checkout.js troca pelo info.php (plano_completo).
                      .replace("@@LEGAL_HOJE_OCULTO@@", " hidden" if completo_html else "")
                      .replace("@@LEGAL_NOVO_OCULTO@@", "" if completo_html else " hidden")
                      .replace("@@DIVULGACAO_OCULTA@@", "" if divulgacao > 0 else " hidden")
                      .replace("@@DIVULGACAO_CENTAVOS@@", str(divulgacao)).replace("@@DIVULGACAO@@", brl(divulgacao)))
    etapas = PASSOS_COMPLETO if completo_html else PASSOS_TAXA

    paginas = {
        # Spec 1.12: o H1 vira o nome do curso e o lead, a turma ("09:00 - 17:00 · início 21/10/2026") ou "Ainda não há
        # turma aberta." quando o curso está escolhido (checkout.js). Sem curso, fica o da escola.
        "checkout": montar(partes, "Inscrição | Cruz Vermelha Brasileira Rio de Janeiro", "checkout",
                           "Inscrição", "Escolha o curso e veja as turmas abertas, os horários e os valores.",
                           corpo_checkout, qrcode=True, passo=2, etapas=etapas),
        # Spec 1.13: textos literais da tela de processamento da escola. O checkout.js troca o H1 nos casos de recusa,
        # PIX vencido e estorno.
        "pendente": montar(partes, "Processando… | Cruz Vermelha Brasileira Rio de Janeiro", "pendente",
                           "Processando…",
                           "Seu pagamento está sendo processado. Assim que o banco confirmar, sua inscrição é atualizada aqui.",
                           CORPO_PENDENTE, qrcode=True, wrap_extra=' style="max-width:820px"', passo=2, etapas=etapas),
        # O checkout.js troca o H1 pelo resultado (Matrícula confirmada, Taxa de inscrição confirmada…, 1.13 e 10.5).
        "parabens": montar(partes, "Inscrição confirmada | Cruz Vermelha Brasileira Rio de Janeiro", "parabens",
                           "Pagamento confirmado",
                           "Guarde este link: ele mostra sua inscrição e o próximo passo.",
                           CORPO_PARABENS, qrcode=False, wrap_extra=' style="max-width:820px"', passo=3, etapas=etapas),
        # Questionário de dias e horários (29/09/2026): link pessoal, abre só com a inscrição paga.
        "horarios": montar(partes, "Seus horários | Cruz Vermelha Brasileira Rio de Janeiro", "horarios",
                           "Quando você pode fazer as aulas?",
                           "Toque nos dias e horários em que você consegue vir. Leva 30 segundos e ajuda a secretaria a encaixar você na turma certa.",
                           CORPO_HORARIOS, qrcode=False, wrap_extra=' style="max-width:820px"', passo=3, eyebrow="Cursos presenciais"),
        # Ponto da sede (29/09/2026): comprovante de comparecimento pelo link pessoal e conferência do código.
        "comparecimento": montar(partes, "Comprovante de comparecimento | Cruz Vermelha Brasileira Rio de Janeiro", "comparecimento",
                                 "Comprovante de comparecimento",
                                 "O comprovante da sua aula presencial. Ele fica disponível quando a aula termina e também chega no seu e-mail.",
                                 CORPO_COMPARECIMENTO, qrcode=False, wrap_extra=' style="max-width:820px"', passo=0, eyebrow="Escola de Educação e Saúde"),
        "conferir": montar(partes, "Conferir documento | Cruz Vermelha Brasileira Rio de Janeiro", "conferir",
                           "Conferir um comprovante ou declaração",
                           "Digite o código de verificação impresso no documento para confirmar que ele foi emitido pela Cruz Vermelha Brasileira Rio de Janeiro.",
                           CORPO_CONFERIR, qrcode=False, wrap_extra=' style="max-width:820px"', passo=0, eyebrow="Conferência de documentos"),
    }
    # Ícones que o checkout.js desenha depois (sprite): o selo de pago, as etapas e os avisos.
    extras_js = {"circle-check", "check", "circle-info", "lock", "credit-card", "pix", "calendar-days", "location-dot", "hourglass-half", "triangle-exclamation"}
    for nome, html in paginas.items():
        destino = PASTA / nome / "index.html"
        destino.parent.mkdir(parents=True, exist_ok=True)
        html = icones.converter(html, extras=extras_js)  # SVG inline; os extras vêm do checkout.js
        destino.write_text(html, encoding="utf-8")
        print(f"gravado {destino.relative_to(RAIZ)} ({len(html.encode('utf-8'))} bytes)")

    # Ponto da sede e cartaz do QR code: páginas próprias, fora do layout do site.
    ponto = {
        PASTA / "ponto" / "index.html": PAGINA_PONTO.replace("@@CSS_URL@@", url_estatico("ponto.css")).replace("@@JS_URL@@", url_estatico("ponto.js")),
        PASTA / "ponto" / "cartaz" / "index.html": PAGINA_CARTAZ.replace("@@URL_VISIVEL@@", URL_PONTO.replace("https://", "").rstrip("/")).replace("@@URL@@", URL_PONTO),
    }
    for uso, (titulo, cabecalho, subtitulo, rodape) in AVISOS_PAGINAS.items():
        ponto[PASTA / "ponto" / uso / "index.html"] = (PAGINA_AVISO
            .replace("@@CSS_URL@@", url_estatico("ponto.css")).replace("@@JS_URL@@", url_estatico("avisos.js"))
            .replace("@@TITULO@@", esc(titulo)).replace("@@PAGINA@@", uso).replace("@@CABECALHO@@", esc(cabecalho))
            .replace("@@SUBTITULO@@", esc(subtitulo)).replace("@@RODAPE@@", esc(rodape)))
    ponto[PASTA / "ponto" / "manifest.json"] = json.dumps(MANIFESTO_PONTO, ensure_ascii=False, indent=2) + "\n"
    for destino, html in ponto.items():
        destino.parent.mkdir(parents=True, exist_ok=True)
        destino.write_text(html, encoding="utf-8")
        print(f"gravado {destino.relative_to(RAIZ)} ({len(html.encode('utf-8'))} bytes)")
    gravar_icones_ponto(PASTA / "ponto")
    print("gravados os ícones do ponto (192, 512 e 512 de máscara)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
