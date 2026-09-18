#!/usr/bin/env python3
"""Gera as páginas do checkout da matrícula: checkout/, pendente/ e parabens/.

Todas usam o cabeçalho, o rodapé, o CSS, o GA4 e o Meta Pixel da home (via
scripts/gerar_matricula_presencial.py) e conversam com o backend em
site/matricula-cursos-presenciais/api/ (PHP, Unicopag). As três levam noindex.

O CSS e o JS próprios do checkout ficam em site/matricula-cursos-presenciais/static/
(checkout.css e checkout.js): as páginas só os referenciam, com um hash do conteúdo na
query string para o navegador buscar a versão nova a cada mudança.

Uso:  python3 scripts/gerar_checkout.py   (depois de gerar_matricula_presencial.py)
"""
from __future__ import annotations

import hashlib
import json

from gerar_matricula_presencial import DADOS, HOME, RAIZ, esc, partes_da_home

PASTA = RAIZ / "site" / "matricula-cursos-presenciais"
STATIC = PASTA / "static"
STATIC_URL = "/matricula-cursos-presenciais/static/"






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
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
@@QRCODE@@
  <!-- Estilos copiados da home (site/index.html): mesmo padrão visual da filial. -->
@@ESTILO@@
  <link rel="stylesheet" href="@@CSS_URL@@">
  <script src="@@JS_URL@@" defer></script>
@@GA4@@
@@PIXEL@@
</head>
<body data-tela="@@ID@@">
  <script>document.documentElement.classList.add('js');</script>
@@HEADER@@

  <main id="@@ID@@">
    <div class="ck-teste" id="ck-teste" hidden>Modo de teste: o valor cobrado não é o preço da inscrição.</div>
    <section class="ck-hero">
      <div class="wrap">
        <p class="eyebrow">Matrícula cursos presenciais</p>
        <h1>@@H1@@</h1>
        <p class="lead">@@LEAD@@</p>
      </div>
    </section>
    <section class="ck-secao">
      <div class="wrap"@@WRAP_EXTRA@@>
        <noscript><p class="ck-noscript">Esta página precisa de JavaScript para gerar o pagamento. Ative o JavaScript ou fale com a secretaria pelo WhatsApp.</p></noscript>
@@CORPO@@
      </div>
    </section>
  </main>

@@FOOTER@@

@@MENU_JS@@
</body>
</html>
"""

CORPO_CHECKOUT = """        <div class="ck-grid">
          <div class="ck-card">
            <form id="ck-form" novalidate autocomplete="on">
              <div class="ck-armadilha" aria-hidden="true"><label>Não preencha este campo<input id="ck-site" name="site" tabindex="-1" autocomplete="off"></label></div>
              <label class="ck-campo"><span>Curso</span>
                <select id="ck-curso" name="curso" required>
                  <option value="" disabled selected>Escolha o curso</option>@@OPCOES@@
                </select>
              </label>
              <label class="ck-campo"><span>Nome completo</span><input id="ck-nome" name="nome" autocomplete="name" required></label>
              <div class="ck-2col">
                <label class="ck-campo"><span>CPF</span><input id="ck-cpf" name="cpf" inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" required></label>
                <label class="ck-campo"><span>WhatsApp</span><input id="ck-telefone" name="telefone" inputmode="tel" autocomplete="tel" placeholder="(21) 99999-9999" required></label>
              </div>
              <label class="ck-campo"><span>E-mail</span><input id="ck-email" type="email" name="email" autocomplete="email" required><small class="ck-nota">A confirmação e o acesso chegam neste e-mail.</small></label>
              <p class="ck-campo" style="margin-bottom:6px"><span>Forma de pagamento</span></p>
              <div class="ck-metodos">
                <label class="ck-metodo ativo"><input type="radio" name="metodo" value="pix" checked><span><b>PIX</b><small>Confirmação em segundos</small></span></label>
                <label class="ck-metodo"><input type="radio" name="metodo" value="cartao"><span><b>Cartão de crédito</b><small>À vista</small></span></label>
              </div>
              <div id="ck-cartao" hidden>
                <label class="ck-campo"><span>Número do cartão</span><input id="ck-cartao-numero" inputmode="numeric" autocomplete="cc-number" placeholder="0000 0000 0000 0000"></label>
                <label class="ck-campo"><span>Nome como está no cartão</span><input id="ck-cartao-nome" autocomplete="cc-name"></label>
                <div class="ck-2col">
                  <label class="ck-campo"><span>Validade</span><input id="ck-cartao-validade" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/AA"></label>
                  <label class="ck-campo"><span>CVV</span><input id="ck-cartao-cvv" inputmode="numeric" autocomplete="cc-csc" placeholder="123"></label>
                </div>
                <p class="ck-nota">Os dados do cartão vão direto para o processador de pagamento e não ficam guardados no site.</p>
              </div>
              <label class="ck-check"><input type="checkbox" id="ck-cobre" name="cobre_taxa"><span>Quero cobrir os custos de processamento (<b id="ck-taxa-valor">+ R$ 0,00</b>)<small>Opcional. Assim a Cruz Vermelha recebe o valor integral da inscrição.</small></span></label>
              <label class="ck-check"><input type="checkbox" id="ck-requisitos" name="requisitos" required><span>Li os requisitos do curso (<span id="ck-escolaridade">escolaridade mínima</span>) e confirmo que os atendo.</span></label>
              <div class="ck-erro" id="ck-erro" role="alert" aria-live="assertive"></div>
              <button class="btn btn-red ck-btn" type="submit" id="ck-pagar">Pagar inscrição · <span id="ck-total-btn">R$ 99,00</span></button>
              <p class="ck-nota" style="margin:12px 0 0">Seus dados são usados só para a matrícula e a cobrança. <a href="/privacidade">Política de privacidade</a>.</p>
            </form>
            <div id="ck-pix" hidden aria-live="polite"></div>
          </div>
          <aside class="ck-card ck-resumo">
            <h2>Resumo</h2>
            <div class="ck-linha"><span>Curso</span><b id="ck-r-curso">—</b></div>
            <div class="ck-linha"><span>Inscrição</span><span id="ck-r-inscricao">R$ 99,00</span></div>
            <div class="ck-linha" id="ck-r-taxa-linha" hidden><span>Custos de processamento</span><span id="ck-r-taxa">R$ 0,00</span></div>
            <div class="ck-linha total"><span>Total agora</span><span id="ck-r-total">R$ 99,00</span></div>
            <p class="ck-nota">O valor do curso (<span id="ck-r-curso-valor">—</span>) é pago depois, na plataforma da escola.</p>
            <p class="ck-nota">A inscrição reserva sua vaga. Se não houver horário compatível ou você desistir antes da confirmação da aula, o valor é estornado.</p>
          </aside>
        </div>"""

CORPO_PENDENTE = """        <div class="ck-card" id="pd-card" aria-live="polite"><p>Carregando…</p></div>"""
CORPO_PARABENS = """        <div class="ck-card" id="pb-card" aria-live="polite"><p>Carregando…</p></div>"""

QRCODE = '  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>'


def brl(centavos: int) -> str:
    return "R$ " + f"{centavos / 100:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")


def url_estatico(nome: str) -> str:
    """URL do arquivo em static/ com hash do conteúdo: muda o arquivo, muda a URL, o cache não segura versão velha."""
    conteudo = (STATIC / nome).read_bytes()
    return f"{STATIC_URL}{nome}?v={hashlib.sha256(conteudo).hexdigest()[:10]}"


def montar(partes: dict, titulo: str, id_: str, h1: str, lead: str, corpo: str, qrcode: bool, wrap_extra: str = "") -> str:
    return (PAGINA
            .replace("@@TITULO@@", esc(titulo)).replace("@@QRCODE@@", QRCODE if qrcode else "")
            .replace("@@ESTILO@@", partes["estilo"])
            .replace("@@CSS_URL@@", url_estatico("checkout.css")).replace("@@JS_URL@@", url_estatico("checkout.js"))
            .replace("@@GA4@@", partes["ga4"]).replace("@@PIXEL@@", partes["pixel"])
            .replace("@@HEADER@@", partes["header"]).replace("@@FOOTER@@", partes["footer"]).replace("@@MENU_JS@@", partes["menu_js"])
            .replace("@@ID@@", id_).replace("@@H1@@", h1).replace("@@LEAD@@", lead).replace("@@WRAP_EXTRA@@", wrap_extra)
            .replace("@@CORPO@@", corpo))


def main() -> int:
    home = HOME.read_text(encoding="utf-8")
    dados = json.loads(DADOS.read_text(encoding="utf-8"))
    cursos = {c["slug"]: c for c in dados["cursos"]}
    partes = partes_da_home(home)
    inscricao = brl(dados["inscricao_centavos"])

    opcoes = ""
    for g in dados["grupos"]:
        itens = "".join(f'\n                    <option value="{s}">{esc(cursos[s]["nome"])} · {esc(cursos[s]["carga_horaria"])}</option>' for s in g["cursos"] if s in cursos)
        opcoes += f'\n                  <optgroup label="{esc(g["titulo"])}">{itens}\n                  </optgroup>'

    paginas = {
        "checkout": montar(partes, "Pagar a inscrição | Cruz Vermelha Brasileira RJ", "checkout",
                           "Pagar a inscrição e garantir a vaga",
                           f"Inscrição de {inscricao}, por PIX ou cartão. Sem criar conta e sem escolher turma agora.",
                           CORPO_CHECKOUT.replace("@@OPCOES@@", opcoes), qrcode=True),
        "pendente": montar(partes, "Pagamento ainda não confirmado | Cruz Vermelha Brasileira RJ", "pendente",
                           "Pagamento ainda não confirmado",
                           "Esta tela não cria login. Quando o pagamento for aprovado, a matrícula é aberta e os dados aparecem aqui e no seu e-mail.",
                           CORPO_PENDENTE, qrcode=True, wrap_extra=' style="max-width:820px"'),
        "parabens": montar(partes, "Inscrição paga | Cruz Vermelha Brasileira RJ", "parabens",
                           "Parabéns, sua inscrição está paga.",
                           "Guarde este link: ele mostra sua inscrição e o próximo passo.",
                           CORPO_PARABENS, qrcode=False, wrap_extra=' style="max-width:820px"'),
    }
    for nome, html in paginas.items():
        destino = PASTA / nome / "index.html"
        destino.parent.mkdir(parents=True, exist_ok=True)
        destino.write_text(html, encoding="utf-8")
        print(f"gravado {destino.relative_to(RAIZ)} ({len(html.encode('utf-8'))} bytes)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
