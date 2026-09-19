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
@@PASSOS@@
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
                <h2 class="ck-bloco-titulo" id="ck-t2"><span class="ck-num">2</span> Seus dados <small>só o necessário para a matrícula</small></h2>
                <label class="ck-campo"><span>Nome completo</span><input id="ck-nome" name="nome" autocomplete="name" placeholder="Como está no seu documento" required></label>
                <div class="ck-2col">
                  <label class="ck-campo"><span>CPF</span><input id="ck-cpf" name="cpf" inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" required></label>
                  <label class="ck-campo"><span>WhatsApp</span><input id="ck-telefone" name="telefone" inputmode="tel" autocomplete="tel" placeholder="(21) 99999-9999" required></label>
                </div>
                <label class="ck-campo"><span>E-mail</span><input id="ck-email" type="email" name="email" autocomplete="email" placeholder="voce@exemplo.com" required><small class="ck-nota">A confirmação da inscrição chega neste e-mail.</small></label>
              </section>
              <section class="ck-bloco-form" aria-labelledby="ck-t3">
                <h2 class="ck-bloco-titulo" id="ck-t3"><span class="ck-num">3</span> Pagamento</h2>
                <div class="ck-metodos" role="radiogroup" aria-label="Forma de pagamento">
                  <label class="ck-metodo ativo"><input type="radio" name="metodo" value="pix" checked><i class="fa-brands fa-pix ck-metodo-icone" aria-hidden="true"></i><span class="ck-metodo-tag">Na hora</span><b>PIX</b><small>QR code ou copia e cola</small></label>
                  <label class="ck-metodo"><input type="radio" name="metodo" value="cartao"><i class="fa-regular fa-credit-card ck-metodo-icone" aria-hidden="true"></i><b>Cartão de crédito</b><small>À vista, aprovação em segundos</small></label>
                </div>
                <div class="ck-cartao-box" id="ck-cartao" hidden>
                  <label class="ck-campo"><span>Número do cartão</span><input id="ck-cartao-numero" inputmode="numeric" autocomplete="cc-number" placeholder="0000 0000 0000 0000"></label>
                  <label class="ck-campo"><span>Nome como está no cartão</span><input id="ck-cartao-nome" autocomplete="cc-name"></label>
                  <div class="ck-2col">
                    <label class="ck-campo"><span>Validade</span><input id="ck-cartao-validade" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/AA"></label>
                    <label class="ck-campo"><span>CVV</span><input id="ck-cartao-cvv" inputmode="numeric" autocomplete="cc-csc" placeholder="123"></label>
                  </div>
                  <p class="ck-nota"><i class="fa-solid fa-lock" aria-hidden="true"></i> Os dados do cartão vão direto para o processador de pagamento e não ficam guardados no site.</p>
                </div>
                <label class="ck-check" id="ck-cobre-opcao"><input type="checkbox" id="ck-cobre" name="cobre_taxa"><span class="ck-check-texto">Quero cobrir os custos de processamento<small>Opcional. Assim a Cruz Vermelha recebe o valor integral da inscrição.</small></span><span class="ck-check-valor" id="ck-taxa-valor">+ R$ 0,00</span></label>
                <label class="ck-check"><input type="checkbox" id="ck-requisitos" name="requisitos" required><span class="ck-check-texto">Li os requisitos do curso (<span id="ck-escolaridade">escolaridade mínima</span>) e confirmo que os atendo.</span></label>
                <div class="ck-erro" id="ck-erro" role="alert" aria-live="assertive"></div>
                <button class="btn btn-red ck-btn" type="submit" id="ck-pagar">Pagar inscrição · <span id="ck-total-btn">R$ 99,00</span></button>
                <ul class="ck-confianca">
                  <li><i class="fa-solid fa-lock" aria-hidden="true"></i> Pagamento seguro pela Unicopag</li>
                  <li><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Estorno se não houver turma compatível</li>
                  <li><i class="fa-solid fa-certificate" aria-hidden="true"></i> Certificado da Cruz Vermelha Brasileira</li>
                </ul>
                <p class="ck-nota" style="margin:14px 0 0">Seus dados são usados só para a matrícula e a cobrança. <a href="/privacidade/">Política de privacidade</a>.</p>
              </section>
            </form>
            <div id="ck-pix" hidden aria-live="polite"></div>
          </div>
          <aside class="ck-card ck-resumo" aria-label="Resumo da inscrição">
            <img class="ck-resumo-foto" id="ck-r-foto" src="/assets/hero-cursos-banner-1.jpg" alt="" width="480" height="360">
            <p class="ck-resumo-rotulo">Sua inscrição</p>
            <p class="ck-resumo-curso" id="ck-r-curso">Escolha o curso</p>
            <span class="ck-resumo-meta" id="ck-r-meta">7 cursos presenciais no Centro do Rio</span>
            <div class="ck-linha"><span>Inscrição</span><span id="ck-r-inscricao">R$ 99,00</span></div>
            <div class="ck-linha" id="ck-r-taxa-linha" hidden><span>Custos de processamento</span><span id="ck-r-taxa">R$ 0,00</span></div>
            <div class="ck-linha total"><span>Total agora</span><span id="ck-r-total">R$ 99,00</span></div>
            <p class="ck-nota">O valor do curso (<span id="ck-r-curso-valor">—</span>) é pago depois, na plataforma da escola.</p>
            <ul class="ck-depois" aria-label="O que você garante">
              <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Vaga reservada na hora, com confirmação por e-mail</li>
              <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Sem criar conta e sem escolher turma agora</li>
              <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Estorno se não houver horário compatível ou se você desistir antes da confirmação da aula</li>
            </ul>
          </aside>
        </div>
        <script type="application/json" id="ck-cursos">@@CURSOS_JSON@@</script>"""

CORPO_PENDENTE = """        <div class="ck-card" id="pd-card" aria-live="polite"><p>Carregando…</p></div>"""
CORPO_PARABENS = """        <div class="ck-card" id="pb-card" aria-live="polite"><p>Carregando…</p></div>"""

QRCODE = '  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>'


def brl(centavos: int) -> str:
    return "R$ " + f"{centavos / 100:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")


def url_estatico(nome: str) -> str:
    """URL do arquivo em static/ com hash do conteúdo: muda o arquivo, muda a URL, o cache não segura versão velha."""
    conteudo = (STATIC / nome).read_bytes()
    return f"{STATIC_URL}{nome}?v={hashlib.sha256(conteudo).hexdigest()[:10]}"


def passos(atual: int) -> str:
    """Etapas da matrícula no topo: 1 curso escolhido, 2 pagamento, 3 confirmação da turma."""
    etapas = ["Curso escolhido", "Pagamento da inscrição", "Confirmação da turma"]
    itens = []
    for i, nome in enumerate(etapas, 1):
        classe = "feito" if i < atual else ("atual" if i == atual else "")
        marca = '<i class="fa-solid fa-check" aria-hidden="true"></i>' if i < atual else str(i)
        atual_attr = ' aria-current="step"' if i == atual else ""
        itens.append(f'<li class="{classe}"{atual_attr}><span class="ck-passo-n">{marca}</span><span>{nome}</span></li>')
    return '        <ol class="ck-passos" aria-label="Etapas da matrícula">' + "".join(itens) + "</ol>"


def montar(partes: dict, titulo: str, id_: str, h1: str, lead: str, corpo: str, qrcode: bool, wrap_extra: str = "", passo: int = 2) -> str:
    return (PAGINA
            .replace("@@TITULO@@", esc(titulo)).replace("@@QRCODE@@", QRCODE if qrcode else "")
            .replace("@@ESTILO@@", partes["estilo"])
            .replace("@@CSS_URL@@", url_estatico("checkout.css")).replace("@@JS_URL@@", url_estatico("checkout.js"))
            .replace("@@GA4@@", partes["ga4"]).replace("@@PIXEL@@", partes["pixel"])
            .replace("@@HEADER@@", partes["header"]).replace("@@FOOTER@@", partes["footer"]).replace("@@MENU_JS@@", partes["menu_js"])
            .replace("@@ID@@", id_).replace("@@H1@@", h1).replace("@@LEAD@@", lead).replace("@@WRAP_EXTRA@@", wrap_extra)
            .replace("@@PASSOS@@", passos(passo)).replace("@@CORPO@@", corpo))


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

    # Dados que a página precisa antes da API responder: nome, foto e ficha de cada curso.
    cursos_json = json.dumps({s: {
        "nome": c["nome"], "carga_horaria": c.get("carga_horaria", ""), "escolaridade": c.get("escolaridade", ""),
        "valor_curso_centavos": int(c["valor_curso_centavos"]) if c.get("valor_curso_centavos") else None,
        "imagem": f"/matricula-cursos-presenciais/img/{c['imagem']}-480.webp" if c.get("imagem") else None,
    } for s, c in cursos.items()}, ensure_ascii=False).replace("</", "<\\/")

    paginas = {
        "checkout": montar(partes, "Pagar a inscrição | Cruz Vermelha Brasileira RJ", "checkout",
                           "Pagar a inscrição e garantir a vaga",
                           f"Inscrição de {inscricao}, por PIX ou cartão. Sem criar conta e sem escolher turma agora.",
                           CORPO_CHECKOUT.replace("@@OPCOES@@", opcoes).replace("@@CURSOS_JSON@@", cursos_json), qrcode=True, passo=2),
        "pendente": montar(partes, "Pagamento ainda não confirmado | Cruz Vermelha Brasileira RJ", "pendente",
                           "Pagamento ainda não confirmado",
                           "Esta tela não cria login. Quando o pagamento for aprovado, a matrícula é aberta e os dados aparecem aqui e no seu e-mail.",
                           CORPO_PENDENTE, qrcode=True, wrap_extra=' style="max-width:820px"', passo=2),
        "parabens": montar(partes, "Inscrição paga | Cruz Vermelha Brasileira RJ", "parabens",
                           "Parabéns, sua inscrição está paga.",
                           "Guarde este link: ele mostra sua inscrição e o próximo passo.",
                           CORPO_PARABENS, qrcode=False, wrap_extra=' style="max-width:820px"', passo=3),
    }
    for nome, html in paginas.items():
        destino = PASTA / nome / "index.html"
        destino.parent.mkdir(parents=True, exist_ok=True)
        destino.write_text(html, encoding="utf-8")
        print(f"gravado {destino.relative_to(RAIZ)} ({len(html.encode('utf-8'))} bytes)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
