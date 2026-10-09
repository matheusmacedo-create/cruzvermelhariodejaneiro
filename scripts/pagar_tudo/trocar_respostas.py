#!/usr/bin/env python3
"""Troca as três respostas da spec 1.16/10.3 no chat.js DO AR e na site/faq-home.json do repositório.

O chat.js publicado = o do ar (docs/pagar-tudo/base-ar/chat.js, hash aa00cac6d2) + só estas três respostas, por
substituição de texto (nunca regerado a partir da faq-home.json do repositório, que tem outras mudanças não publicadas).

Uso: trocar_respostas.py <chat.js do ar> <faq-home.json base> <faq-home.json de saída> <chat.js de saída>
  base: docs/pagar-tudo/base-ar/faq-home.base.json (a do repositório antes das três respostas; funciona depois do commit).
Variáveis:
  VENDA_SEM_TURMA_HTML=1  passo 5b: a resposta 2 sai com a frase da 10.3, e a resposta 1 diz que, sem turma, quem paga só a
                          taxa entra na lista de interesse. Sem ela (padrão), a frase da 1.16 ("Com turma aberta…").
  PARCELADO_HTML=1        passo 6: na resposta 2, "à vista" vira "à vista ou parcelado no cartão, com juros" (1.16).
  ESPERA_PRAZO_DIAS       a data limite (padrão 90, o do config.example.php).
"""
from __future__ import annotations

import json
import os
import sys

PRAZO = int(os.environ.get("ESPERA_PRAZO_DIAS") or 90)
VENDA_SEM_TURMA = os.environ.get("VENDA_SEM_TURMA_HTML") == "1"
PARCELADO = os.environ.get("PARCELADO_HTML") == "1"

P1 = "Como me matricular em um curso da Cruz Vermelha Brasileira Rio de Janeiro?"
P2 = "Quanto custa fazer um curso na Cruz Vermelha Brasileira Rio de Janeiro?"
P3 = "A Cruz Vermelha Brasileira Rio de Janeiro tem cursos gratuitos?"

R1_SO_TAXA = ("só a taxa de inscrição (com turma aberta, ela reserva a vaga; sem turma, você entra na lista de interesse)"
              if VENDA_SEM_TURMA else "só a taxa de inscrição, que reserva a vaga")
R1 = ("Escolha o curso na página de cursos presenciais e clique em “Inscrever-se”. Você preenche nome, CPF, e-mail e "
      "telefone e escolhe o que pagar: a taxa de inscrição (R$ 99) junto com a matrícula, que é o valor do curso, ou "
      + R1_SO_TAXA + ". A entrada na aula é liberada com a matrícula paga. Não é preciso criar conta "
      "antes: a conta na área do aluno é criada depois do pagamento, e o link para criar a senha chega no seu e-mail. "
      "Dúvidas? Use o chat do site, que responde por e-mail.")

R2_INICIO = ("Cada curso tem a matrícula (o valor do curso) e a taxa de inscrição de R$ 99. À vista: Suporte Básico de Vida, "
             "Punção Venosa e Lei Lucas, R$ 150 + R$ 99 = R$ 249; Primeiros Socorros Básico, R$ 180 + R$ 99 = R$ 279; "
             "Micropigmentação Labial, R$ 400 + R$ 99 = R$ 499; Bombeiro Civil e Cuidador de Idosos, R$ 950 + R$ 99 = "
             "R$ 1.049, com a homologação do Bombeiro Civil à parte. ")
R2_COM_TURMA = ("Com turma aberta, você pode pagar tudo de uma vez na inscrição, por PIX ou cartão, à vista, ou só a taxa de "
                "inscrição, que reserva a vaga. ")
R2_SEM_TURMA = ("Você pode pagar tudo de uma vez na inscrição, por PIX ou cartão, à vista, ou só a taxa de inscrição. Nos "
                "cursos sem turma aberta, quem paga tudo entra na primeira turma que tiver vaga, por ordem de pagamento, e "
                "recebe a data por e-mail. Se a data ou o horário não servirem, é só avisar em até 7 dias depois desse "
                "e-mail para receber tudo de volta. E, se não houver turma marcada para começar em até "
                f"{PRAZO} dias da inscrição, devolvemos tudo sem você precisar pedir. ")
R2_FIM = "A entrada na aula é liberada com a matrícula paga. Confira na página de cursos presenciais."
R2 = R2_INICIO + (R2_SEM_TURMA if VENDA_SEM_TURMA else R2_COM_TURMA) + R2_FIM
if PARCELADO:
    R2 = R2.replace("por PIX ou cartão, à vista,", "por PIX ou cartão, à vista ou parcelado no cartão, com juros,")
    assert "parcelado no cartão, com juros" in R2

TRECHO3_ANTIGO = ("a inscrição de R$ 99 garante a vaga e o valor do curso, de R$ 150 a R$ 950, é pago depois na plataforma "
                  "da escola")
TRECHO3_NOVO = "cada um tem a matrícula, de R$ 150 a R$ 950, e a taxa de inscrição de R$ 99"


def respostas_antigas(faq: dict) -> dict[str, str]:
    achadas = {}
    for g in faq["grupos"]:
        for q in g["perguntas"]:
            if q["pergunta"] in (P1, P2, P3):
                achadas[q["pergunta"]] = q["resposta"]
    falta = {P1, P2, P3} - set(achadas)
    if falta:
        raise SystemExit(f"faq-home.json sem as perguntas: {sorted(falta)}")
    return achadas


def main() -> int:
    if len(sys.argv) != 5:
        print(__doc__)
        return 2
    vivo, faq_base, faq_saida, saida = sys.argv[1:]
    js = open(vivo, encoding="utf-8").read()
    faq_txt = open(faq_base, encoding="utf-8").read()
    antigas = respostas_antigas(json.loads(faq_txt))
    if TRECHO3_ANTIGO not in antigas[P3]:
        raise SystemExit("resposta 3 sem o trecho antigo (já trocada?)")
    novas = {P1: R1, P2: R2, P3: antigas[P3].replace(TRECHO3_ANTIGO, TRECHO3_NOVO)}

    j = lambda v: json.dumps(v, ensure_ascii=False)  # noqa: E731 — o formato que chat_widget.atualizar_respostas grava
    for p, nova in novas.items():
        velha = antigas[p]
        # chat.js do ar: r: "<resposta>" logo depois da pergunta.
        alvo_js = f"p: {j(p)}, r: {j(velha)}"
        if js.count(alvo_js) != 1:
            # Pode já estar trocada no repositório, mas o chat.js do ar tem de ter o texto antigo.
            raise SystemExit(f"chat.js do ar: esperava 1 ocorrência da resposta antiga de “{p}”, achei {js.count(alvo_js)}")
        js = js.replace(alvo_js, f"p: {j(p)}, r: {j(nova)}")
        alvo_faq = f'"resposta": {j(velha)}'
        if faq_txt.count(alvo_faq) != 1:
            raise SystemExit(f"faq-home.json: esperava 1 ocorrência da resposta antiga de “{p}”, achei {faq_txt.count(alvo_faq)}")
        faq_txt = faq_txt.replace(alvo_faq, f'"resposta": {j(nova)}')
    json.loads(faq_txt)  # continua JSON válido
    open(saida, "w", encoding="utf-8").write(js)
    open(faq_saida, "w", encoding="utf-8").write(faq_txt)
    print(f"chat.js: 3 respostas trocadas ({'10.3, venda sem turma' if VENDA_SEM_TURMA else '1.16, com turma'}"
          f"{', parcelado' if PARCELADO else ''}) -> {saida}")
    print(f"faq-home.json: 3 respostas trocadas -> {faq_saida}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
