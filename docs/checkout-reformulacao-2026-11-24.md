# Reformulação do checkout para converter mais — 24/11/2026

Pedido do Matheus: "faça uma refatoração no checkout da página e entenda como pode melhorar para converter ainda mais".
Mudou só a tela (`scripts/gerar_checkout.py`, `static/checkout.js`, `static/checkout.css`). Nada mudou no servidor, no
pagamento, nos valores nem nos textos legais.

**ESTADO: no branch, NÃO publicado.** É o fluxo de pagamento: vai ao ar com o OK do responsável.

## O que os números mostraram (GA4, 27/10 a 24/11/2026)

| Etapa | Pessoas |
| --- | --- |
| Chegaram ao checkout com curso escolhido (`begin_checkout`) | cerca de 290 |
| Começaram a preencher os dados (`checkout_etapa` = dados) | 50 |
| Enviaram o formulário (`generate_lead`) | 33 |
| Pagaram (`purchase`) | 24 |

- A perda está antes do envio: quem envia, paga (24 de 33). De cada seis pessoas que chegam, só uma começa a preencher.
- 83% chegam pelo celular. Em 390 px, o primeiro campo ficava a 1.013 px do topo (mais de uma tela) e o botão de pagar,
  a 3.252 px (quase quatro telas). Antes do primeiro campo, o curso aparecia três vezes: no topo, no resumo e no bloco
  "1 Curso".
- 133 sessões entraram direto no checkout (81 vindas de rede social, nenhuma compra): gente que chega fria e encontra
  uma tela inteira sem campo nenhum.
- O erro mais comum era o aceite: 43 tentativas recusadas de 10 pessoas (mais de 4 por pessoa). A caixa ficava entre os
  dois opcionais, e a mensagem ("Confirme que leu os requisitos do curso") saía lá embaixo, longe dela e sem dizer qual
  caixa. Erro de campo (CPF: 9) também só aparecia na caixa de baixo.

## O que mudou

1. **Curso que veio no link vira uma linha** ("Curso escolhido · 8 horas · Ensino Fundamental · local", com "Trocar").
   Sem curso no link, o seletor aparece como sempre.
2. **Topo mais baixo no celular**: título menor, sem a fila de etapas; o resumo do alto fica só com a linha do total
   (o topo já diz o curso e a turma). No computador, o resumo lateral continua inteiro.
3. **Erro junto do campo**: a mensagem aparece embaixo do campo recusado, além da caixa de baixo.
4. **Aceite obrigatório colado ao botão**, sob "Para concluir", maior e separado dos opcionais. Se faltar, a caixa fica
   destacada, vem para o meio da tela e diz "Marque a caixa acima para continuar".
5. **Opcionais agrupados** ("Se quiser ajudar (opcional)"), em linhas mais baixas. Continuam desmarcados e valendo
   também com cupom.
6. **Cupom já aplicado**: some o campo e o "Aplicar"; fica a linha verde e "Usar outro cupom".
7. **Um plano só** (com cupom): vira uma linha de informação, sem a bolinha de escolha.
8. **PIX e Crédito lado a lado** no celular.
9. Mais um selo perto do botão: "Desistência em até 7 dias, com o valor de volta" (o texto legal logo abaixo já dizia).

| Em 390 px, chegando com o curso e o cupom no link | Antes | Depois |
| --- | --- | --- |
| Primeiro campo (nome) | 1.013 px | 495 px |
| Botão de pagar | 3.252 px | 2.507 px |
| Altura da página | 5.000 px | 4.280 px |

Prévia: `docs/previas/checkout-reformulado-antes-depois.jpg`.

Não mexi, de propósito: o cabeçalho com o menu, o aviso do QR Code, o "Resumo das condições" (continua aberto e antes do
aceite), a nota "Como esta escola sustenta" acima do botão (Ad Grants) e os textos legais.

## Conferido (24/11/2026, sem publicar)

- 29 verificações num Chromium com as páginas daqui e o `info.php` do ar: chegada com cupom, envio vazio, CPF errado,
  aceite faltando e marcado, envio completo (curso, plano, cupom PRO12, total 14000, prova do aceite), "Usar outro
  cupom", "Trocar" de curso, chegada sem curso, cupom de vitrine com o balão, dois planos, cartão, só a taxa; 320, 390,
  768 e 1280 px sem rolagem lateral nem erro de JavaScript. O pagamento foi respondido no teste: nada foi cobrado.
- `scripts/conferir_ga4_conversoes.js --repositorio`: 3 de 3. `scripts/conferir_pixel.js --repositorio`: 19 de 19.
- `php scripts/testar_checkout.php`: 891 testes, 0 falhas. `testar_pagar_tudo.php`: 304, 0 falhas.
- `scripts/conferir_ad_grants.py`: 0 falhas.
- **Não testado:** cupom de grupo (nenhum valendo), os testes de integração com banco (não há MariaDB aqui) e um
  pagamento de verdade.

## Para publicar

`static/checkout.css`, `static/checkout.js` e as telas que os referenciam (`checkout/`, `pendente/`, `parabens/`,
`horarios/`, `comparecimento/`, `conferir/`, `pagar-matricula/`), nessa ordem, e limpar o cache. O `checkout.js` do ar
é o de 17/11 (`53717e5267`); o do repositório já tinha a mais as 4 linhas do aviso 410 da tela de horários. Desfazer:
republicar a cópia do ar feita antes.

Medida de sucesso: a parcela de quem chega e começa a preencher (`checkout_etapa` = dados sobre `begin_checkout`),
hoje perto de 17%, e os `checkout_erro` de `requisitos`. Evento novo: `checkout_trocar_curso`.

## Parcelamento no cartão (pedido de 24/11/2026: até 12x, juros de 2,99% ao mês pagos pelo aluno) — NÃO feito

O checkout já tem o parcelamento pronto e desligado (passo 6 do "pagar tudo"): o servidor pede a simulação à ÚnicoPag,
mostra juros, taxa ao mês e CET, e confere o cobrado contra o mostrado. O que falta não é tela:

- **A taxa vem da ÚnicoPag**, não do site: é a tabela de parcelamento da conta que define os 2,99% ao mês. O site só
  mostra a taxa que a simulação devolve.
- **A chave fica no servidor** (`PARCELAS_MAX = 12` em `api/config-pagar-tudo.php`, fora do Git) e no `oferta.json`
  (`"parcelado_no_ar": true`), mais as páginas e políticas geradas com `PARCELADO_HTML=1`
  (`scripts/publicacao-pagar-tudo-passo6.txt`).
- **Com cupom, hoje é só à vista** (regra de 30/10/2026, no servidor e na tela). O Lei Lucas a R$ 140 é cupom: para
  parcelar, a regra muda em `api/lib/cupons.php`, `api/pagamentos.php`, `api/parcelas.php` e `checkout.js`.
- As travas que o próprio roteiro pede antes de ligar: compra de teste em 2x e o OK do jurídico.
