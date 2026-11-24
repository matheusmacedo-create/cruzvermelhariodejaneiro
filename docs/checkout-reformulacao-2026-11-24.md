# Reformulação do checkout para converter mais — 24/11/2026

Pedido do Matheus: "faça uma refatoração no checkout da página e entenda como pode melhorar para converter ainda mais".
Mudou só a tela (`scripts/gerar_checkout.py`, `static/checkout.js`, `static/checkout.css`). Nada mudou no servidor, no
pagamento, nos valores nem nos textos legais.

**ESTADO: PUBLICADO em 24/11/2026**, com o OK do Matheus ("sim, pode publicar o checkout reformulado"), na ordem de
`scripts/publicacao-checkout-reformulado.txt` (os dois estáticos antes das sete telas). Antes, cópia do que estava no ar:
as telas eram as do repositório, só com o `checkout.js` de 17/11. Cache limpo. No ar: `scripts/conferir_publicacao.sh`
"Tudo certo" (os 9 arquivos idênticos ao Git em `7395e10`, API respondendo, configurações bloqueadas); as 29
verificações de tela, com o pagamento respondido no teste; `conferir_ga4_conversoes.js` 3 de 3 e `conferir_pixel.js`
19 de 19. Nenhum pagamento de verdade foi feito no teste.

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

## Parcelamento no cartão em até 12x, também com cupom (24/11/2026)

Pedido do Matheus: "no cartão de crédito precisa dar opção de parcelamento em até 12x pelo aluno", "taxa de 2,99 ao
mês"; depois, "a ÚnicoPag já está com 2,99, pode ligar o parcelamento".

**Como funciona (já existia, desligado: passo 6 do "pagar tudo").** O servidor pede a simulação à ÚnicoPag
(`/installments`), mostra cada opção com o valor da parcela, o total, os juros, a taxa ao mês e o CET, e na cobrança
confere o total mostrado contra o cobrado. **A taxa é a da tabela da conta na ÚnicoPag**: o site não a define, só mostra.

**O que mudou no código.**

- **Cupom parcelado**: até 24/11 o cupom era só à vista (regra de 30/10). Agora o cupom de uma pessoa pode ser pago em
  até `PARCELAS_MAX` vezes no cartão, com os juros por cima do valor do cupom. `api/lib/cupons.php`
  (`mcp_compra_com_cupom` mantém as parcelas da compra), `api/pagamentos.php` (lê as parcelas pedidas também com cupom)
  e `api/parcelas.php` (aceita `&cupom=`: a simulação é a do valor do cupom). O **cupom de grupo** e a compra do CPF
  de teste continuam só à vista.
- **Tela** (`checkout.js`): pede a simulação com o cupom; a linha verde do cupom e o balão dizem "à vista ou em até 12x
  no cartão, com juros" quando o servidor oferece parcelas.
- **Páginas**: `oferta.json` com `"parcelado_no_ar": true`; a matrícula regerada (os textos do parcelado que já
  estavam prontos); a página do curso Lei Lucas ganha "ou em até 12x no cartão, com juros", que só aparece quando o
  `info.php` diz que o parcelado vale; `/reembolso/` em PT, EN e ES com os trechos do parcelado que o roteiro já trazia
  (estorno do valor total com os juros; quitação antecipada, art. 52, § 2º, do CDC), revisão de 24/11/2026.
- Ficou para depois: a resposta "Quanto custa…" da FAQ da home e do chat ainda diz "à vista" (mudar o `chat.js` mexe
  na referência dele em todas as páginas do site).

**A chave que liga fica no servidor**: `PARCELAS_MAX = 12` em `api/config-pagar-tudo.php` (fora do Git; o
`publicar_hostinger.sh` nunca envia arquivo de configuração). Com ela em 1, tudo isto fica no ar sem efeito: o
`info.php` responde `parcelado_no_ar: false` e o cartão continua à vista. Volta rápida: `PARCELAS_MAX = 1`.

**Conferido (sem publicar).**

- Unitários: `testar_pagar_tudo.php` 308 (4 novos: cupom em 6x e 12x mantém as parcelas e o valor do cupom; PIX e
  grupo ficam em 1), `testar_checkout.php` 895, `testar_pagar_tudo_emails.php` 186, sem falha.
- Tela, com o servidor simulado a 2,99% ao mês (opções geradas pelas funções do próprio servidor): R$ 140,00 com cupom
  = 12 × R$ 14,06, total R$ 168,72, "Juros de 2,99% ao mês", CET 42,48% ao ano; o envio leva `parcelas: 12`,
  `cupom: PRO12` e `total_mostrado_centavos: 16872`; marcar os custos pede outra simulação; sem cupom, 12 opções; com a
  chave desligada (como o servidor está hoje), nada de parcelas. 17 verificações, mais as 29 do checkout.
- `conferir_ga4_conversoes.js` e `conferir_pixel.js` com `--repositorio`, `conferir_ad_grants.py`: sem falha.
- **Não testado**: a cobrança parcelada de verdade na ÚnicoPag. O teste de integração com banco
  (`testar_meta_integracao.php`) não serve hoje: falha igual com e sem esta mudança (20 de 60), porque espera os preços
  antigos do catálogo (R$ 279). **A primeira compra parcelada precisa ser acompanhada**: o roteiro do projeto pedia uma
  compra de teste em 2x antes de abrir, e o OK do jurídico para os textos.

**Depois de ligar a chave**: abrir `api/parcelas.php?curso=primeiros-socorros-lei-lucas&cupom=PRO12` e conferir a
`taxa_mes_rotulo` (tem de dar 2,99%, ou perto disso pelo arredondamento dos centavos).

Lista de publicação: `scripts/publicacao-parcelado-cupom.txt`.

**Publicado em 24/11/2026**, com o OK do Matheus ("pode ligar o parcelamento"), o servidor antes das telas. Antes, cópia
do que estava no ar: os três arquivos do servidor eram iguais aos do Git. Cache limpo. No ar:
`scripts/conferir_publicacao.sh` "Tudo certo"; `parcelas.php?…&cupom=PRO12` responde com o valor do cupom
(`amount_centavos: 14000`) e `motivo: desligado`; as 29 verificações do checkout sem falha; matrícula, página do curso e
as três páginas de reembolso abrem sem erro, e nenhuma mostra parcelas ao comprador enquanto a chave está desligada.

**Estado em 24/11/2026, fim do dia: a chave do servidor continua em 1** (`info.php`: `parcelas_max: 1`,
`parcelado_no_ar: false`). O parcelamento passa a valer quando o responsável puser `'PARCELAS_MAX' => 12` em
`api/config-pagar-tudo.php`.
