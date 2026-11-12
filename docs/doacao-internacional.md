# Doação internacional no cartão (página em inglês, sem CPF)

Status em 12/11/2026: **código, textos e testes prontos no branch; nada publicado; `DOACAO_INTERNACIONAL` desligada.**
Falta o teste real com um cartão emitido fora do Brasil (passo 4), que é o que prova as duas dúvidas abertas.

## O que é

`/en/donate/` deixou de ser uma página que explica e manda para `/doe/` e passou a ser a página de conversão para quem
doa de fora do Brasil: o formulário fica nela, em inglês, e cobra no **cartão de crédito**, **doação única**, **sem CPF**.

- **No lugar do CPF:** país e o número de um documento de identificação **só com números** (passaporte ou identidade), de
  5 a 20 dígitos. É o que a Unicopag exige em `customer.document` (sondado em 21/09/2026: numérico e nada além disso;
  README, "O que a Unicopag valida no documento"). Documento com letra **não é cortado**: a página pede outro documento
  só com números ou manda escrever para a filial. Nunca se inventa CPF.
- **Telefone:** com código do país, só dígitos (8 a 15).
- **Moeda:** a cobrança é em reais. A página mostra o valor aproximado em dólar, euro e libra pelo câmbio PTAX de venda do
  Banco Central (`mcp_doacao_cambio()`, guardado 12 h em arquivo temporário; sem resposta do BC, a aproximação some e o
  resto funciona). Quem converte de verdade é o emissor do cartão, e a página diz isso.
- **Comprovante:** e-mail em inglês (`mcp_doacao_montar_email_confirmada_en()`), com rodapé em inglês
  (`mcp_moldura(..., ['idioma' => 'en'])`). O link do e-mail e o acompanhamento são `/en/donate/?t=<token>`.
- **Banco:** `mcp_doacoes` ganha `documento`, `pais` e `idioma` (aditivo, automático no primeiro acesso, como `gateway`).
  Na doação internacional `cpf` fica vazio. O aviso à equipe traz o país e o telefone com `+`; o documento não vai em e-mail.
- **Trava:** `DOACAO_INTERNACIONAL = 1` em `/doe/api/config.php` (só no servidor). Com 0, o servidor recusa
  `internacional: true` e a página troca o formulário por "Write to us to donate from abroad" (e-mail da filial).
- **Fora do escopo:** doação mensal de fora (a API de assinaturas pede `documentType: cpf`; não foi sondada) e PIX
  (impossível de banco estrangeiro). A página diz as duas coisas.

Junto, em `/doe/` (português): no celular o cartão de doação sobe para logo depois do título (antes ficava depois de quatro
itens de confiança); atalho "Está fora do Brasil ou não tem CPF? Donate in English"; pergunta nova no FAQ; link da política
de privacidade visível na caixa de aceite. Correção: cartão recusado respondia `ok: true` (ordem da união de arrays em
`doacoes.php`) e a página ia para a tela de acompanhamento; agora o erro aparece no próprio formulário, com os dados mantidos.

## O que ainda não foi provado (só o teste real responde)

1. **Telefone internacional em `customer.phone_number`.** A sondagem de setembro não chegou a esse campo. Se a Unicopag
   recusar, a doação falha com 422 e o log (`mcp_eventos`) registra `doacao_unicopag_recusou` com o texto dela.
2. **Cartão emitido fora do Brasil.** Se a conta da Unicopag das doações não aceitar cartão internacional, a cobrança volta
   recusada. É configuração da conta na Unicopag, não do site.

## Para ligar

1. **Backend** (não muda nada para quem doa hoje): publicar o bloco "backend" de `scripts/publicacao-doacao-internacional.txt`
   e limpar o cache. Conferir `GET /doe/api/info.php`: `"internacional":false` e o resto igual.
2. **Config no servidor:** `'DOACAO_INTERNACIONAL' => 1` em `/doe/api/config.php`. Conferir `info.php?cambio=1`:
   `"internacional":true` e `"cambio":{"usd":…,"eur":…}`.
3. **Privacidade e página em inglês:** publicar o bloco "formulário" da lista e limpar o cache.
4. **Teste real**, logo em seguida, com um cartão de crédito emitido fora do Brasil: doar **R$ 5** ("Other amount"), com
   país, telefone e documento verdadeiros de quem testa. Conferir: a tela agradece em inglês; o comprovante chega em inglês;
   a linha em `mcp_doacoes` fica `pago`, com `cpf` vazio, `documento`, `pais` e `idioma = en`; o aviso chega na caixa das
   doações. Se falhar: `'DOACAO_INTERNACIONAL' => 0` (a página passa a mostrar "write to us" na hora) e olhar o log.
5. **Resto dos textos:** publicar o bloco "textos" (página em português, FAQ, home em inglês e espanhol, `llms.txt`).
6. Depois de publicar, `python3 scripts/gerar_sitemaps.py`.

## Voltar atrás

`'DOACAO_INTERNACIONAL' => 0`, sem deploy: o formulário em inglês some e fica o e-mail da filial. As doações já feitas
continuam consultáveis. Os textos que citam o formulário em inglês (FAQ, `/doe/`, espanhol) precisam ser revertidos à mão se a
suspensão for longa.

## Testes

- `php scripts/testar_doacao.php` (104): documento, telefone, país, visão pública sem documento, comprovante em inglês,
  aviso à equipe, câmbio guardado e vencido.
- Ponta a ponta local em 12/11/2026 (PHP de verdade, MariaDB descartável e gateway falso em 127.0.0.1): doação
  internacional paga e recusada, recarga da página com `?t=`, doação brasileira com CPF sem mudança. Não ficou script no
  repositório; o e-mail não foi enviado (sem chave da Resend no teste), só montado.
- Página conferida em 390 px e 1280 px, sem rolagem lateral e sem alvo de toque menor que 44 px no formulário.

## Para revisar por quem responde juridicamente

- `politicas.json` (privacidade, PT/EN/ES, revisão 12/11/2026): os dados da doação e o que vai à ÚnicoPag passam a citar o
  país e o documento de identificação de quem doa de fora.
- A resposta sobre imposto no FAQ em inglês diz que o comprovante é emitido no Brasil e que a filial não confirma dedução
  em outro país.
