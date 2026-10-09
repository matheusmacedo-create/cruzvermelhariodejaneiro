#!/usr/bin/env python3
"""Aplica à site/politicas.json os textos do pagar tudo em /reembolso/ e /termos/ (spec 5.2 e 10.10; decisões 6, 7, 10 e 13),
em PT, EN e ES. Parte sempre da politicas.json base (docs/pagar-tudo/base-ar/politicas.base.json, igual à publicada: as 6
páginas geradas dela batem com as do ar), então pode rodar de novo, antes ou depois do commit.

Uso: POLITICAS_DATA=AAAA-MM-DD aplicar_textos.py <politicas.json base> <politicas.json de saída>

Variáveis (as mesmas do gerar.sh):
  POLITICAS_DATA        obrigatória: a data da publicação ("Atualizada em" e dateModified; spec 5.2). Sem padrão.
  VENDA_SEM_TURMA_HTML  "1" no passo 5b: a seção "Cursos sem turma aberta", a frase do "Depois dos 7 dias" e o parágrafo
                        dos termos (10.10; jurídico, item 6). Sem ela, nada da venda sem turma.
  PARCELADO_HTML        "1" no passo 6: "à vista ou parcelada no cartão", "No cartão, a compra pode ser parcelada…" e os
                        parágrafos do estorno do parcelado e da quitação antecipada (jurídico, item 3). Sem ela, a variante
                        "à vista" (R5)."""
import json
import os
import re
import sys

RECEBEDOR = "O-CVB Filial Rio de Janeiro Ensino Ltda"
CNPJ = "67.733.551/0001-35"
PRAZO = int(os.environ.get("ESPERA_PRAZO_DIAS") or 90)   # ESPERA_PRAZO_DIAS (P6, decisão 13)
VENDA_SEM_TURMA = os.environ.get("VENDA_SEM_TURMA_HTML") == "1"
PARCELADO = os.environ.get("PARCELADO_HTML") == "1"
REVISAO = os.environ.get("POLITICAS_DATA", "")
if not re.fullmatch(r"\d{4}-\d{2}-\d{2}", REVISAO):
    raise SystemExit("defina POLITICAS_DATA=AAAA-MM-DD (a data da publicação: \"Atualizada em\" de /reembolso/ e /termos/)")


def se(cond: bool, *blocos):
    """Os blocos só quando a condição vale (venda sem turma, parcelado)."""
    return list(blocos) if cond else []


def p(t): return {"p": t}
def lista(*itens): return {"lista": list(itens)}


def secoes_por_id(pagina):
    return {s["id"]: s for s in pagina["secoes"]}


def reembolso_pt(pg):
    s = secoes_por_id(pg)
    pg["intro"] = ["Estas regras valem para o que você paga na [página de cursos presenciais]({matricula}): a taxa de inscrição de {inscricao} e, se você escolher pagar junto, a matrícula (o valor do curso), " + ("à vista ou parcelada no cartão" if PARCELADO else "à vista") + ". Elas seguem o Código de Defesa do Consumidor (Lei nº 8.078/1990) e o Decreto nº 7.962/2013, que trata das compras pela internet."]
    s["arrependimento"]["blocos"] = [p("Você pode desistir em até 7 dias corridos, contados do pagamento, sem precisar dizer o motivo (art. 49 do CDC). O valor pago volta por inteiro: a taxa de inscrição, a matrícula, os juros do parcelamento e, se você tiver escolhido, os custos de processamento e a contribuição para a divulgação.")]
    s["depois"]["blocos"] = [
        p("Passado o prazo de arrependimento, tudo o que você pagou ainda volta por inteiro:"),
        lista("se não houver turma com horário compatível para você;",
              "se a filial cancelar ou adiar a turma e você não puder participar em outra data; ou",
              "se você desistir antes de a secretaria confirmar a sua turma (data e horário da aula)."),
        *se(VENDA_SEM_TURMA, p("Nos cursos comprados sem turma aberta, valem também as regras da seção [“Cursos sem turma aberta”](#sem-turma).")),
        p(f"Com a turma confirmada, se você desistir antes da primeira aula, a matrícula paga neste site é devolvida, com os juros do parcelamento que correspondem a ela, e a taxa de inscrição fica com {RECEBEDOR}."),
    ]
    confirmada = {"id": "turma-confirmada", "titulo": "Turma confirmada", "blocos": [
        p("A turma é confirmada quando a secretaria envia o e-mail “Turma confirmada”, com data, horário e local, depois de atingido o número mínimo de alunos. Matrícula confirmada (pagamento recebido) não é o mesmo que turma confirmada.")]}
    sem_turma = {"id": "sem-turma", "titulo": "Cursos sem turma aberta", "blocos": [
        p("Nos cursos que ainda não têm turma aberta, você pode pagar a taxa de inscrição e a matrícula antes de a data sair. Nesse caso:"),
        lista("quando a Escola marcar uma turma do curso, você entra na primeira que tiver vaga, por ordem de pagamento, e recebe a data, o horário e o local por e-mail, pelo menos 10 dias antes da primeira aula;",
              "se a data ou o horário não servirem, avise em até 7 dias depois desse e-mail, e sempre antes da primeira aula, e tudo o que você pagou volta por inteiro;",
              "se você desistir antes de a turma ser confirmada (você recebe o e-mail “Turma confirmada”), tudo volta por inteiro;",
              f"se não houver turma marcada para começar em até {PRAZO} dias depois da compra, devolvemos tudo, sem você precisar pedir;",
              "se a turma for cancelada ou mudar de data, você escolhe outra turma ou recebe tudo de volta, e, se não responder em 7 dias, devolvemos tudo;",
              "se a Escola não puder aceitar a sua matrícula por um requisito que não estava na página do curso, devolvemos tudo."),
        p(f"A data limite aparece no checkout, no e-mail de confirmação e no comprovante. Você pode pedir uma vez, por escrito, para continuar esperando por mais {PRAZO} dias."),
        p("“Tudo” é a taxa de inscrição, a matrícula, os juros do parcelamento e, se você escolheu, os custos de processamento e a contribuição para a divulgação. No PIX, a devolução feita mais de 90 dias depois do pagamento é uma transferência para uma conta no seu nome."),
        p("Quem paga só a taxa de inscrição entra na lista de interesse: quando a turma for marcada, o lugar fica com quem pagar a matrícula enquanto houver vaga, e quem já pagou tudo entra primeiro."),
    ]}
    dev = s["devolucao"]["blocos"]
    assert dev[0]["p"].startswith("A devolução é sempre do valor inteiro") and "lista" in dev[2] and dev[3]["p"].startswith("Avisamos")
    s["devolucao"]["blocos"] = [
        p("Quando a devolução é por inteiro, ela inclui tudo o que você pagou: a taxa de inscrição, a matrícula, os juros do parcelamento e, se você tiver escolhido, os custos de processamento e a contribuição para a divulgação dos cursos."),
        dev[1], dev[2],
        *se(PARCELADO, p("No cartão parcelado, o estorno é do valor total da compra, com os juros; como ele aparece na fatura (crédito de uma vez ou fim das parcelas que faltam) depende do banco emissor.")),
        p("Quando só uma parte do valor volta (por exemplo, só a matrícula), a devolução é feita por PIX, para uma conta no seu nome, com os juros do parcelamento que correspondem a essa parte, porque o processador de pagamento só estorna a compra inteira."),
        *se(PARCELADO, p("Quem quiser quitar antes as parcelas que faltam pede pelo chat e recebe de volta os juros das parcelas antecipadas (art. 52, § 2º, do CDC).")),
        dev[3],
    ]
    s["curso"]["titulo"] = "A matrícula"
    s["curso"]["blocos"] = [p("A matrícula paga junto com a inscrição, neste site, segue estas regras e é devolvida por aqui. Quem paga só a taxa de inscrição paga a matrícula depois, antes da aula, como a secretaria da Escola orientar por e-mail; essa compra segue as condições informadas no momento do pagamento, e o direito de arrependimento de 7 dias vale também para ela.")]
    s["quem-vende"]["blocos"] = [
        p(f"A taxa de inscrição e a matrícula pagas neste site são vendidas por **{RECEBEDOR}**, CNPJ {CNPJ}, que responde por estas regras e emite a nota fiscal."),
        p("Contato: chat “Fale com a gente”, na [página de matrícula]({matricula}), ou [{email}](mailto:{email})."),
    ]
    ordem = ["arrependimento", "depois", "turma-confirmada"] + (["sem-turma"] if VENDA_SEM_TURMA else []) + ["como-pedir", "devolucao", "curso", "quem-vende"]
    s.update({"turma-confirmada": confirmada, "sem-turma": sem_turma})
    pg["secoes"] = [s[i] for i in ordem]


def reembolso_en(pg):
    s = secoes_por_id(pg)
    pg["intro"] = ["These rules apply to what you pay on the [in-person courses page]({matricula}): the {inscricao} enrolment fee and, if you choose to pay it at the same time, the course fee, " + ("in a single payment or in card instalments" if PARCELADO else "in a single payment") + ". They follow the Brazilian Consumer Protection Code (Law No. 8,078/1990) and Decree No. 7,962/2013, on online purchases."]
    s["withdrawal"]["blocos"] = [p("You may withdraw within 7 calendar days of payment, without giving a reason (Art. 49 of the Consumer Protection Code). The full amount paid is refunded: the enrolment fee, the course fee, the instalment interest and, if you chose them, the processing cost and the contribution to promoting the courses.")]
    s["after"]["blocos"] = [
        p("After the withdrawal period, everything you paid is still refunded in full:"),
        lista("if there is no class at a time that suits you;",
              "if the branch cancels or postpones the class and you cannot attend on another date; or",
              "if you withdraw before the school office confirms your class (date and time)."),
        *se(VENDA_SEM_TURMA, p("For courses bought with no open class, the rules in the section [“Courses with no open class”](#no-class) also apply.")),
        p(f"Once the class is confirmed, if you withdraw before the first lesson, the course fee paid on this site is refunded, with the instalment interest that corresponds to it, and the enrolment fee is kept by {RECEBEDOR}."),
    ]
    confirmada = {"id": "class-confirmed", "titulo": "Confirmed class", "blocos": [
        p("A class is confirmed when the school office sends the “Turma confirmada” (class confirmed) e-mail, with the date, time and place, once the minimum number of students has been reached. A confirmed enrolment (payment received) is not the same as a confirmed class.")]}
    sem_turma = {"id": "no-class", "titulo": "Courses with no open class", "blocos": [
        p("For courses that do not yet have an open class, you can pay the enrolment fee and the course fee before the date is set. In that case:"),
        lista("when the School schedules a class for the course, you join the first one with a place, in order of payment, and receive the date, time and place by e-mail at least 10 days before the first lesson;",
              "if the date or time does not suit you, let us know within 7 days of that e-mail, and always before the first lesson, and everything you paid is refunded in full;",
              "if you withdraw before the class is confirmed (you receive the “Turma confirmada” e-mail), everything is refunded in full;",
              f"if no class is scheduled to start within {PRAZO} days of the purchase, we refund everything without you having to ask;",
              "if the class is cancelled or its date changes, you choose another class or get everything back, and if you do not reply within 7 days, we refund everything;",
              "if the School cannot accept your enrolment because of a requirement that was not on the course page, we refund everything."),
        p(f"The deadline appears at checkout, in the confirmation e-mail and on the receipt. You may ask once, in writing, to keep waiting for another {PRAZO} days."),
        p("“Everything” means the enrolment fee, the course fee, the instalment interest and, if you chose them, the processing cost and the contribution to promoting the courses. For PIX, a refund made more than 90 days after payment is a transfer to an account in your name."),
        p("Anyone who pays only the enrolment fee joins the interest list: when the class is scheduled, the places go to those who pay the course fee while places last, and those who have already paid everything come first."),
    ]}
    dev = s["refund"]["blocos"]
    assert dev[0]["p"].startswith("The refund is always the full amount") and "lista" in dev[2] and dev[3]["p"].startswith("We let you know")
    s["refund"]["blocos"] = [
        p("When the refund is in full, it covers everything you paid: the enrolment fee, the course fee, the instalment interest and, if you chose them, the processing cost and the contribution to promoting the courses."),
        dev[1], dev[2],
        *se(PARCELADO, p("For card instalments, the refund is for the full amount of the purchase, including interest; how it shows on your statement (a single credit or the end of the remaining instalments) depends on the card issuer.")),
        p("When only part of the amount is refunded (for example, only the course fee), the refund is made by PIX, to an account in your name, with the instalment interest that corresponds to that part, because the payment processor only refunds whole purchases."),
        *se(PARCELADO, p("If you want to pay off the remaining instalments early, ask through the chat and you will get back the interest on the instalments paid in advance (Art. 52, § 2, of the Consumer Protection Code).")),
        dev[3],
    ]
    s["course-fee"]["titulo"] = "The course fee"
    s["course-fee"]["blocos"] = [p("A course fee paid together with the enrolment fee, on this site, follows these rules and is refunded here. Anyone who pays only the enrolment fee pays the course fee later, before the class, as the School office explains by e-mail; that purchase follows the conditions stated at the time of payment, and the 7-day right of withdrawal also applies to it.")]
    s["seller"]["blocos"] = [
        p(f"The enrolment fee and the course fee paid on this site are sold by **{RECEBEDOR}**, CNPJ (Brazilian company number) {CNPJ}, which is responsible for these rules and issues the invoice."),
        p("Contact: the “Fale com a gente” chat on the [enrolment page]({matricula}), or [{email}](mailto:{email})."),
    ]
    ordem = ["withdrawal", "after", "class-confirmed"] + (["no-class"] if VENDA_SEM_TURMA else []) + ["how-to-ask", "refund", "course-fee", "seller"]
    s.update({"class-confirmed": confirmada, "no-class": sem_turma})
    pg["secoes"] = [s[i] for i in ordem]


def reembolso_es(pg):
    s = secoes_por_id(pg)
    pg["intro"] = ["Estas reglas se aplican a lo que usted paga en la [página de cursos presenciales]({matricula}): la tasa de inscripción de {inscricao} y, si elige pagarla junto, la matrícula (el valor del curso), " + ("al contado o en cuotas con tarjeta" if PARCELADO else "al contado") + ". Siguen el Código de Defensa del Consumidor de Brasil (Ley n.º 8.078/1990) y el Decreto n.º 7.962/2013, sobre las compras por internet."]
    s["arrepentimiento"]["blocos"] = [p("Puede desistir en hasta 7 días corridos contados desde el pago, sin tener que dar el motivo (art. 49 del Código de Defensa del Consumidor). Se devuelve el importe íntegro: la tasa de inscripción, la matrícula, los intereses de las cuotas y, si los eligió, el costo de procesamiento y la contribución para la difusión de los cursos.")]
    s["despues"]["blocos"] = [
        p("Pasado el plazo de arrepentimiento, todo lo que pagó todavía se devuelve íntegramente:"),
        lista("si no hay un grupo con un horario compatible para usted;",
              "si la filial cancela o aplaza el grupo y usted no puede participar en otra fecha; o",
              "si desiste antes de que la secretaría confirme su grupo (fecha y horario de la clase)."),
        *se(VENDA_SEM_TURMA, p("En los cursos comprados sin grupo abierto, también se aplican las reglas de la sección [“Cursos sin grupo abierto”](#sin-grupo).")),
        p(f"Con el grupo confirmado, si desiste antes de la primera clase, se devuelve la matrícula pagada en este sitio, con los intereses de las cuotas que le corresponden, y la tasa de inscripción queda para {RECEBEDOR}."),
    ]
    confirmada = {"id": "grupo-confirmado", "titulo": "Grupo confirmado", "blocos": [
        p("El grupo se confirma cuando la secretaría envía el correo “Turma confirmada” (grupo confirmado), con la fecha, el horario y el lugar, después de alcanzar el número mínimo de alumnos. Matrícula confirmada (pago recibido) no es lo mismo que grupo confirmado.")]}
    sem_turma = {"id": "sin-grupo", "titulo": "Cursos sin grupo abierto", "blocos": [
        p("En los cursos que todavía no tienen un grupo abierto, puede pagar la tasa de inscripción y la matrícula antes de que salga la fecha. En ese caso:"),
        lista("cuando la Escuela programe un grupo del curso, usted entra en el primero que tenga plaza, por orden de pago, y recibe la fecha, el horario y el lugar por correo, al menos 10 días antes de la primera clase;",
              "si la fecha o el horario no le sirven, avise en hasta 7 días después de ese correo, y siempre antes de la primera clase, y todo lo que pagó se devuelve íntegramente;",
              "si desiste antes de que el grupo sea confirmado (usted recibe el correo “Turma confirmada”), todo se devuelve íntegramente;",
              f"si no hay un grupo programado para empezar en hasta {PRAZO} días después de la compra, le devolvemos todo, sin que tenga que pedirlo;",
              "si el grupo se cancela o cambia de fecha, usted elige otro grupo o recibe todo de vuelta, y, si no responde en 7 días, le devolvemos todo;",
              "si la Escuela no puede aceptar su matrícula por un requisito que no estaba en la página del curso, le devolvemos todo."),
        p(f"La fecha límite aparece en el checkout, en el correo de confirmación y en el comprobante. Puede pedir una vez, por escrito, seguir esperando {PRAZO} días más."),
        p("“Todo” es la tasa de inscripción, la matrícula, los intereses de las cuotas y, si los eligió, el costo de procesamiento y la contribución para la difusión de los cursos. Con PIX, la devolución hecha más de 90 días después del pago es una transferencia a una cuenta a su nombre."),
        p("Quien paga solo la tasa de inscripción entra en la lista de interés: cuando se programe el grupo, las plazas quedan para quien pague la matrícula mientras haya plazas, y quien ya pagó todo entra primero."),
    ]}
    dev = s["devolucion"]["blocos"]
    assert dev[0]["p"].startswith("La devolución es siempre del importe íntegro") and "lista" in dev[2] and dev[3]["p"].startswith("Le avisamos")
    s["devolucion"]["blocos"] = [
        p("Cuando la devolución es íntegra, incluye todo lo que pagó: la tasa de inscripción, la matrícula, los intereses de las cuotas y, si los eligió, el costo de procesamiento y la contribución para la difusión de los cursos."),
        dev[1], dev[2],
        *se(PARCELADO, p("Con tarjeta en cuotas, el reembolso es del valor total de la compra, con los intereses; cómo aparece en el resumen (un crédito de una vez o el fin de las cuotas que faltan) depende del banco emisor.")),
        p("Cuando solo se devuelve una parte del importe (por ejemplo, solo la matrícula), la devolución se hace por PIX, a una cuenta a su nombre, con los intereses de las cuotas que corresponden a esa parte, porque el procesador de pagos solo reembolsa la compra entera."),
        *se(PARCELADO, p("Quien quiera cancelar antes las cuotas que faltan lo pide por el chat y recibe de vuelta los intereses de las cuotas adelantadas (art. 52, § 2.º, del Código de Defensa del Consumidor).")),
        dev[3],
    ]
    s["valor-del-curso"]["titulo"] = "La matrícula"
    s["valor-del-curso"]["blocos"] = [p("La matrícula pagada junto con la inscripción, en este sitio, sigue estas reglas y se devuelve por aquí. Quien paga solo la tasa de inscripción paga la matrícula después, antes de la clase, como la secretaría de la Escuela le indique por correo; esa compra sigue las condiciones informadas en el momento del pago, y el derecho de arrepentimiento de 7 días también se aplica a ella.")]
    s["quien-vende"]["blocos"] = [
        p(f"La tasa de inscripción y la matrícula pagadas en este sitio las vende **{RECEBEDOR}**, CNPJ (registro de personas jurídicas de Brasil) {CNPJ}, responsable de estas reglas y emisora de la factura."),
        p("Contacto: el chat “Fale com a gente”, en la [página de matrícula]({matricula}), o [{email}](mailto:{email})."),
    ]
    ordem = ["arrepentimiento", "despues", "grupo-confirmado"] + (["sin-grupo"] if VENDA_SEM_TURMA else []) + ["como-pedir", "devolucion", "valor-del-curso", "quien-vende"]
    s.update({"grupo-confirmado": confirmada, "sin-grupo": sem_turma})
    pg["secoes"] = [s[i] for i in ordem]


# Termos, seção "Cursos e matrícula" (5.2 e 10.10), com quem vende (decisão 6: nome único também aqui).
TERMOS = {
    "pt": ("cursos", [
        "A matrícula em cursos presenciais é feita na [página de matrícula]({matricula}): você paga a taxa de inscrição de {inscricao} e, se escolher, a matrícula (o valor do curso) junto, por PIX ou cartão"
        + (". No cartão, a compra pode ser parcelada, com os juros informados antes do pagamento." if PARCELADO else ", à vista.")
        + f" Quem paga só a taxa de inscrição paga a matrícula depois, antes da aula. A entrada na aula é liberada com a matrícula paga. A taxa de inscrição e a matrícula são vendidas por {RECEBEDOR}, CNPJ {CNPJ}, que emite a nota fiscal.",
        f"Nos cursos sem turma aberta, você pode pagar a inscrição e a matrícula antes de a data sair: você entra na primeira turma que tiver vaga, por ordem de pagamento, recebe a data por e-mail e pode pedir tudo de volta se a data ou o horário não servirem. Se não houver turma marcada para começar em até {PRAZO} dias, devolvemos tudo."],
        "As regras para desistir e receber o valor de volta"),
    "en": ("courses", [
        "Enrolment in in-person courses is done on the [enrolment page]({matricula}), in Portuguese: you pay the {inscricao} enrolment fee and, if you choose, the course fee at the same time, by PIX or card"
        + (". By card, the purchase can be paid in instalments, with the interest shown before payment." if PARCELADO else ", in a single payment.")
        + f" Anyone who pays only the enrolment fee pays the course fee later, before the class. Entry to the class is allowed once the course fee is paid. The enrolment fee and the course fee are sold by {RECEBEDOR}, CNPJ (Brazilian company number) {CNPJ}, which issues the invoice.",
        f"For courses with no open class, you can pay the enrolment fee and the course fee before the date is set: you join the first class with a place, in order of payment, receive the date by e-mail and can ask for everything back if the date or time does not suit you. If no class is scheduled to start within {PRAZO} days, we refund everything."],
        "The rules for withdrawing and getting your money back"),
    "es": ("cursos", [
        "La matrícula en los cursos presenciales se hace en la [página de matrícula]({matricula}), en portugués: usted paga la tasa de inscripción de {inscricao} y, si lo elige, la matrícula (el valor del curso) junto, con PIX o tarjeta"
        + (". Con tarjeta, la compra puede pagarse en cuotas, con los intereses informados antes del pago." if PARCELADO else ", al contado.")
        + f" Quien paga solo la tasa de inscripción paga la matrícula después, antes de la clase. La entrada a la clase se habilita con la matrícula pagada. La tasa de inscripción y la matrícula las vende {RECEBEDOR}, CNPJ (registro de personas jurídicas de Brasil) {CNPJ}, que emite la factura.",
        f"En los cursos sin grupo abierto, puede pagar la inscripción y la matrícula antes de que salga la fecha: entra en el primer grupo que tenga plaza, por orden de pago, recibe la fecha por correo y puede pedir que le devuelvan todo si la fecha o el horario no le sirven. Si no hay un grupo programado para empezar en hasta {PRAZO} días, le devolvemos todo."],
        "Las reglas para desistir y recuperar el dinero"),
}


def main():
    base, saida = sys.argv[1:3]
    d = json.load(open(base, encoding="utf-8"))
    r = d["paginas"]["reembolso"]
    reembolso_pt(r["pt"]); reembolso_en(r["en"]); reembolso_es(r["es"])
    r["revisao"] = REVISAO
    t = d["paginas"]["termos"]
    for lingua, (sid, novos, fim) in TERMOS.items():
        sec = next(x for x in t[lingua]["secoes"] if x["id"] == sid)
        ultimo = sec["blocos"][-1]
        assert ultimo["p"].startswith(fim), (lingua, ultimo)
        # O 2º parágrafo (venda sem turma, 10.10) só no passo 5b.
        sec["blocos"] = [p(x) for x in (novos if VENDA_SEM_TURMA else novos[:1])] + [ultimo]
    # termos ganha data própria (as outras políticas ficam com a revisão geral)
    d["paginas"]["termos"] = {"revisao": REVISAO, **t}
    open(saida, "w", encoding="utf-8").write(json.dumps(d, ensure_ascii=False, indent=2) + "\n")
    print(f"politicas.json: /reembolso/ e /termos/ (PT, EN, ES) com os textos do pagar tudo (data {REVISAO}; venda sem turma: "
          f"{'sim' if VENDA_SEM_TURMA else 'não'}; parcelado: {'sim' if PARCELADO else 'não'}) -> {saida}")


if __name__ == "__main__":
    main()
