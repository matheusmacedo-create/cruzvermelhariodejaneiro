# Doações na Donaty: textos públicos da virada (rascunho para revisão)

Status: **rascunho, nada publicado.** Estes textos entram junto com a virada de `DOACAO_GATEWAY` para `donaty`
(ver "Doações na Donaty" no README). Publicados antes, ficariam errados; depois, ficariam desatualizados.

Regra de redação: **só afirmo o que o repositório prova.** O que depende de fato da Donaty que não conheço está
marcado `[CONFIRMAR Fn]` e listado na seção 1. As versões abaixo estão escritas para poderem ser publicadas **sem**
nenhuma dessas frases; os acréscimos opcionais entram só se o fato for confirmado por escrito.

---

## 1. Fatos a confirmar com a Donaty antes de publicar

| Id | O que confirmar | Por que importa |
|---|---|---|
| F1 | Razão social e CNPJ da Donaty | A política de privacidade deve identificar o operador (LGPD art. 9º, III e IV) |
| F2 | É instituição de pagamento autorizada pelo Banco Central, ou opera sobre uma que seja? | Hoje a FAQ afirma isso da ÚnicoPag; não repetir sem prova |
| F3 | Como o dinheiro chega à filial: crédito direto na conta da filial ou repasse? Prazo? Quem arca com as taxas? | A FAQ e os termos dizem "repassa o valor à filial" |
| F4 | Que nome aparece no extrato do PIX do doador | A FAQ afirma que é o nome do processador |
| F5 | Onde a Donaty guarda e processa os dados: Brasil ou exterior? | Exterior exige informar a transferência internacional (LGPD art. 33) |
| F6 | Contrato ou termo de operador de dados (LGPD art. 39) e contato do encarregado da Donaty | A filial responde pelo operador que escolhe |
| F7 | Prazo em que a Donaty guarda os dados da transação | Seção "Por quanto tempo guardamos" |
| F8 | A Donaty envia e-mail próprio ao doador (comprovante, aviso)? | Hoje só o nosso e-mail de comprovante está descrito |
| F9 | A Donaty aceita cartão por API? A documentação recebida só descreve PIX | Os textos abaixo dizem "só por PIX" |

O que **sabemos** (do código, `doacoes.php`): a Donaty recebe nome, e-mail, telefone, CPF, o valor e a descrição da doação,
a URL de aviso (postback) e, em `metadata`, um código interno da doação, o nome do doador, se a doação é anônima, se o doador
cobriu os custos de processamento e a origem da visita (`utm_source` e `utm_campaign`). **Não enviamos o endereço IP nem
dados de cartão** (o IP fica só no nosso banco). A página de doação, com a Donaty, não coleta cartão.

Também: as **doações feitas antes da virada** foram processadas pela ÚnicoPag, inclusive as de cartão. Os textos abaixo
dizem isso com `DD/MM/AAAA` (preencher com a data da virada).

---

## 2. Onde cada texto mora

| # | Texto | Arquivo(s) | Idiomas |
|---|---|---|---|
| T1 | FAQ "A doação é segura? Quem processa o pagamento?" | `scripts/gerar_doe.py` (≈ linha 72) | PT; EN e ES vêm de `gerar_idiomas.py`/`idiomas.json` |
| T2 | Política de privacidade, "Doações" (dados coletados) | `site/politicas.json` (≈ linha 188) | PT, EN, ES |
| T3 | Política de privacidade, quem processa o pagamento | `site/politicas.json` (≈ linhas 219, 482, 745) | PT, EN, ES |
| T4 | Política de privacidade, "Com quem compartilhamos" | `site/politicas.json` (≈ linhas 255, 518, 781); EN também em `traducoes/en/paginas/privacy.md` | PT, EN, ES |
| T5 | Termos de uso, "Doações" | `site/politicas.json` (≈ linhas 1489, 1596, 1703) | PT, EN, ES |
| T6 | Frase da transparência na página de doação | `site/idiomas.json` (≈ linhas 335 e 742) | EN, ES |
| T7 | Data de "última atualização" da política | `site/politicas.json` | PT, EN, ES |

Os textos de reembolso das **inscrições** (linhas ≈ 1826, 1931, 2036) continuam citando a ÚnicoPag: a matrícula não muda.

---

## 3. Textos

### T1. FAQ da página de doação

**Antes (PT):** "Sim. A página fica no domínio oficial da filial, com conexão criptografada. O pagamento é processado pela ÚnicoPag, instituição de pagamento autorizada, que recebe a doação e repassa o valor à Cruz Vermelha Brasileira Rio de Janeiro; por isso é o nome dela que aparece no PIX e na fatura do cartão, […] Seus dados de cartão passam direto para o processador e não são gravados no nosso servidor. Ficam conosco apenas nome, e-mail, CPF e telefone, para emitir a cobrança e enviar o comprovante."

**Depois (PT):**
> Sim. A página fica no domínio oficial da filial, com conexão criptografada. A doação é feita por PIX e processada pela Donaty, plataforma de pagamentos que gera o código PIX e confirma o pagamento para a filial. Para emitir a cobrança, a Donaty recebe seu nome, CPF, e-mail e telefone; esses dados também ficam conosco, para enviar o comprovante e cumprir obrigações contábeis. Esta página não coleta dados de cartão.

Acréscimos opcionais: `[CONFIRMAR F4]` "O nome que aparece no seu PIX é o da Donaty." · `[CONFIRMAR F2]` trocar "plataforma de pagamentos" por "instituição de pagamento autorizada pelo Banco Central" · `[CONFIRMAR F3]` "O valor é creditado à Cruz Vermelha Brasileira Rio de Janeiro [diretamente / em até N dias]."

**After (EN):**
> Yes. The page is on the branch's official domain, with an encrypted connection. Donations are made by PIX and processed by Donaty, a payments platform that generates the PIX code and confirms the payment to the branch. To issue the charge, Donaty receives your name, CPF, e-mail and phone number; we also keep that data to send the receipt and meet accounting obligations. This page does not collect card details.

**Después (ES):**
> Sí. La página está en el dominio oficial de la filial, con conexión cifrada. La donación se hace por PIX y la procesa Donaty, una plataforma de pagos que genera el código PIX y confirma el pago a la filial. Para emitir el cobro, Donaty recibe su nombre, CPF, correo electrónico y teléfono; también conservamos esos datos para enviar el comprobante y cumplir obligaciones contables. Esta página no recopila datos de tarjeta.

### T2. Política de privacidade, "Doações" (dados coletados)

**Antes (PT):** "Nome, CPF, e-mail, telefone, valor, forma de pagamento (no cartão, só a bandeira e os quatro últimos dígitos), se a doação é anônima, a origem da visita (parâmetros de campanha da URL) e o endereço IP."

**Depois (PT):**
> Nome, CPF, e-mail, telefone, valor, forma de pagamento (PIX), se a doação é anônima, se o doador cobriu os custos de processamento, a origem da visita (parâmetros de campanha da URL) e o endereço IP. Nas doações por cartão feitas até DD/MM/AAAA, guardamos só a bandeira e os quatro últimos dígitos.

**After (EN):**
> Name, CPF, e-mail, phone number, amount, payment method (PIX), whether the donation is anonymous, whether the donor covered the processing costs, the origin of the visit (campaign parameters in the URL) and the IP address. For card donations made up to DD/MM/YYYY, we kept only the card brand and the last four digits.

**Después (ES):**
> Nombre, CPF, correo electrónico, teléfono, monto, forma de pago (PIX), si la donación es anónima, si el donante cubrió los costos de procesamiento, el origen de la visita (parámetros de campaña de la URL) y la dirección IP. En las donaciones con tarjeta hechas hasta el DD/MM/AAAA, guardamos solo la marca y los cuatro últimos dígitos.

### T3. Política de privacidade, quem processa o pagamento

**Antes (PT):** "O pagamento da inscrição e o da doação são processados pela ÚnicoPag. Os dados do cartão passam pelo nosso servidor só a caminho dela, no momento do pagamento, e não são gravados."

**Depois (PT):**
> O pagamento da inscrição é processado pela ÚnicoPag, e o da doação, pela Donaty. Os dados do cartão da inscrição passam pelo nosso servidor só a caminho da ÚnicoPag, no momento do pagamento, e não são gravados. A doação é só por PIX: não coletamos dados de cartão nela. As doações feitas até DD/MM/AAAA foram processadas pela ÚnicoPag.

**After (EN):**
> Enrolment payments are processed by ÚnicoPag, and donation payments by Donaty. Card details for enrolments only pass through our server on their way to ÚnicoPag, at the moment of payment, and are not stored. Donations are by PIX only: we do not collect card details for them. Donations made up to DD/MM/YYYY were processed by ÚnicoPag.

**Después (ES):**
> El pago de la inscripción lo procesa ÚnicoPag, y el de la donación, Donaty. Los datos de la tarjeta de la inscripción pasan por nuestro servidor solo camino a ÚnicoPag, en el momento del pago, y no se guardan. La donación es solo por PIX: no recopilamos datos de tarjeta en ella. Las donaciones hechas hasta el DD/MM/AAAA fueron procesadas por ÚnicoPag.

### T4. Política de privacidade, "Com quem compartilhamos"

O item único da ÚnicoPag vira dois.

**Antes (PT):** "**ÚnicoPag**, que processa os pagamentos: nome, CPF, e-mail e telefone e, no cartão, os dados do cartão."

**Depois (PT):**
> - **ÚnicoPag**, que processa os pagamentos das inscrições: nome, CPF, e-mail e telefone e, no cartão, os dados do cartão.
> - **Donaty**, que processa os pagamentos das doações: nome, CPF, e-mail e telefone, o valor, se a doação é anônima, se o doador cobriu os custos de processamento e a origem da visita (campanha). Não enviamos a ela o endereço IP nem dados de cartão.

**After (EN):**
> - **ÚnicoPag**, which processes enrolment payments: name, CPF, e-mail and phone number and, for card payments, the card details.
> - **Donaty**, which processes donation payments: name, CPF, e-mail and phone number, the amount, whether the donation is anonymous, whether the donor covered the processing costs and the origin of the visit (campaign). We do not send it the IP address or card details.

**Después (ES):**
> - **ÚnicoPag**, que procesa los pagos de las inscripciones: nombre, CPF, correo electrónico y teléfono y, en el pago con tarjeta, los datos de la tarjeta.
> - **Donaty**, que procesa los pagos de las donaciones: nombre, CPF, correo electrónico y teléfono, el monto, si la donación es anónima, si el donante cubrió los costos de procesamiento y el origen de la visita (campaña). No le enviamos la dirección IP ni datos de tarjeta.

Acréscimo se `[CONFIRMAR F5]` mostrar tratamento fora do Brasil: "A Donaty trata esses dados em [país]; a transferência se apoia em [base do art. 33 da LGPD]." Sem isso, **não** afirmar que os dados ficam no Brasil.

Acréscimo se `[CONFIRMAR F1]`: depois de "Donaty", "([razão social], CNPJ [número])".

Observação importante: como a Donaty recebe o nome do doador **mesmo na doação anônima** (o sinal de anonimato vai junto), a frase "Se você marcar a doação como anônima, seu nome não aparece em agradecimentos públicos…" continua verdadeira, mas não diz que o processador não vê o nome. Os textos acima já deixam isso explícito.

### T5. Termos de uso, "Doações"

**Antes (PT):** "A doação em dinheiro pelo site é feita só na [página de doação]({doar}), por PIX ou cartão, processada pela ÚnicoPag, que repassa o valor à filial; o comprovante chega por e-mail, com o CNPJ da filial. A doação é voluntária e não dá direito a produto ou serviço. Desconfie de qualquer cobrança em nome da Cruz Vermelha feita fora dos canais oficiais, […]"

**Depois (PT):**
> A doação em dinheiro pelo site é feita só na [página de doação]({doar}), por PIX, processada pela Donaty; o comprovante chega por e-mail, com o CNPJ da filial. A doação é voluntária e não dá direito a produto ou serviço. Desconfie de qualquer cobrança em nome da Cruz Vermelha feita fora dos canais oficiais, […]

Acréscimo se `[CONFIRMAR F3]`: depois de "Donaty", ", que repassa o valor à filial".

**After (EN):**
> Money donations through this site are made only on the [donation page]({doar}), by PIX, processed by Donaty; the receipt arrives by e-mail, with the branch's CNPJ. Donating is voluntary and does not entitle you to any product or service. Be wary of any charge in the name of the Red Cross made outside the official channels, […]

**Después (ES):**
> La donación de dinero por este sitio se hace solo en la [página de donación]({doar}), por PIX, procesada por Donaty; el comprobante llega por correo electrónico, con el CNPJ de la filial. La donación es voluntaria y no da derecho a ningún producto o servicio. Desconfíe de cualquier cobro en nombre de la Cruz Roja hecho fuera de los canales oficiales, […]

### T6. Frase da transparência na página de doação (EN e ES)

**Antes (EN):** "… Payments are processed by ÚnicoPag, which transfers the funds to the branch."
**Depois (EN):** "… Donations are made by PIX and processed by Donaty."

**Antes (ES):** "… Los pagos son procesados por ÚnicoPag, que transfiere los fondos a la filial."
**Depois (ES):** "… Las donaciones se hacen por PIX y las procesa Donaty."

Acréscimo se `[CONFIRMAR F3]`: EN ", which transfers the funds to the branch." · ES ", que transfiere los fondos a la filial."

### T7. Atualização da política

Trocar a data de "última atualização" nos três idiomas pela data da publicação e acrescentar, no início da política, uma
linha do que mudou: "Em DD/MM/AAAA, as doações passaram a ser processadas pela Donaty, só por PIX." (EN: "On DD/MM/YYYY,
donations began to be processed by Donaty, by PIX only." · ES: "El DD/MM/AAAA, las donaciones pasaron a ser procesadas por
Donaty, solo por PIX.")

---

## 4. Como aplicar (quando os fatos estiverem confirmados)

1. Preencher `DD/MM/AAAA` com a data da virada e decidir cada acréscimo opcional (F1 a F5).
2. Editar `gerar_doe.py` (T1), `politicas.json` (T2 a T5 e T7), `idiomas.json` (T6) e `traducoes/en/paginas/privacy.md`.
3. Rodar a ordem de geração do README (`gerar_politicas.py`, `gerar_idiomas.py`, `gerar_doe.py`) e conferir que nenhuma página
   de **doação** cita a ÚnicoPag e que as de **matrícula** continuam citando.
4. Publicar as páginas, depois pôr `DOACAO_GATEWAY = donaty` e `DONATY_API_KEY` no `config.php` do servidor, e só então fazer a
   cobrança de teste real. Para voltar atrás: `DOACAO_GATEWAY = unicopag` (os textos novos ficariam adiantados; reverter o Git).
5. Avisar a equipe e, se houver aviso de mudança de política aos doadores recorrentes, enviá-lo (hoje não há doação mensal).

Este documento não é parecer jurídico. A revisão final dos textos de privacidade e termos é de quem responde juridicamente pela
filial (e, se houver, do encarregado de dados).
