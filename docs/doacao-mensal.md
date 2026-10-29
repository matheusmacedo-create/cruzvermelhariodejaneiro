# Doação mensal no cartão (Unicopag, API de assinaturas)

Status em 29/10/2026: **publicado e ligado** (cartão avulso e mensal). `DOACAO_MENSAL = 1` e o plano estão no `config.php`
do servidor; nosso webhook está registrado na API de assinaturas e o do absol.lol foi removido. **Falta o teste real com cartão** (passo 5).

**Quem cobra o quê (decisão de 29/10/2026):** o PIX fica na Donaty (`DOACAO_GATEWAY = 'donaty'`); o cartão, único
ou mensal, vai sempre pela ÚnicoPag (`mcp_doacao_gateway_do_metodo()`), com a chave `UNICO_API_KEY_DOACAO`. Sem
chave da ÚnicoPag, o cartão some. Políticas (PT/EN/ES, revisão 2026-10-29), termos, `/doe/`, páginas em inglês e
espanhol, FAQ da home, chat e `llms.txt` dizem isso (lista em `scripts/publicacao-doacao-cartao.txt`).

## Como funciona

- Quem escolhe **Mensal** em `/doe/` paga no cartão (o PIX some quando "Mensal" está marcado; o servidor recusa
  mensal com PIX). `doacoes.php` cria uma assinatura em `POST /subscriptions` da API de assinaturas
  (`https://subscription.unicopag.com.br/api/v1`, `Authorization: Bearer <api_token da conta das doações>`), com
  `charge: true` (a primeira cobrança sai na hora) e o valor da própria doação em `amount` (centavos).
- Um plano só para todas as doações: `DOACAO_MENSAL_PLANO_ID` = `01m62jcwztx693tyx1y0hccgha` (mensal, sem
  período de teste), da variante `cvrj-doacao-mensal-v1` do produto `cvrj-doacao-mensal`, criados em 29/10/2026.
  O valor do plano (R$ 5,00) é só referência; cada assinatura leva o seu.
- A linha da doação em `mcp_doacoes` guarda `assinatura_id` e o status da assinatura em `unicopag_status`. Ela vira
  `pago` só quando a resposta traz uma cobrança `paid` ("active" sozinho não basta; desconhecido = pendente).
- **Cada mês pago vira uma linha nova** (cópia da original, mesmo `assinatura_id`), com o comprovante e o aviso à
  equipe de sempre. `mcp_doacoes_cobrancas` (chave primária = id da cobrança, `INSERT IGNORE`) impede registrar o
  mesmo mês duas vezes quando postback e consulta chegam juntos.
- Os meses seguintes chegam pelo **postback** (`/doe/api/webhook.php`, o mesmo do PIX): com `subscription.id` no
  corpo, a assinatura é reconsultada e é a resposta da API que decide. A assinatura HMAC do webhook não é usada.
- **Cancelar, pausar ou mudar o valor:** o doador responde o e-mail; a equipe cancela no painel da Unicopag (ou
  `PATCH /subscriptions/{id}/cancel`). A mudança de status fica no log (`doacao_assinatura_<status>`).
- O cartão passa pelo servidor e vai direto à Unicopag, como no avulso: não é gravado, logado nem devolvido.

## Correção que vai junto: cartão avulso dava 500

`doacoes.php` chamava `mcp_validar_cartao()`, que só existia em `matricula-cursos-presenciais/api/pagamentos.php`
(a doação não carrega esse arquivo). A função foi para `lib/http.php`, com guarda `function_exists` para a janela de
publicação. **Publicar `lib/http.php` antes de `pagamentos.php`.**

## Para ligar

1. **Webhook alheio na conta.** A conta de assinaturas já tem um webhook ativo para
   `https://absol.lol/api/billing/v2/subscription-hook`, com todos os eventos. Não é da filial: decidir com a
   Unicopag (remover, ou confirmar que é deles) **antes** da primeira assinatura, senão os dados de quem doa vão para lá.
2. Publicar `scripts/publicacao-doacao-mensal.txt` (ordem do cabeçalho) e limpar o cache. Conferir por HTTP que
   os estáticos ficaram iguais ao Git e que `GET /doe/api/info.php` ainda diz `"mensal":false`.
3. Registrar o nosso webhook (uma vez):
   `POST /webhooks` com `{"url":"https://cruzvermelhariodejaneiro.org/doe/api/webhook.php","events":["subscription.activated","subscription.canceled","subscription.ended","subscription.renewed","payment.successful","payment.failed"]}`.
4. `/doe/api/config.php` no servidor (fora do Git): `'DOACAO_MENSAL' => 1`, `'DOACAO_MENSAL_PLANO_ID' =>
   '01m62jcwztx693tyx1y0hccgha'` e, se a chave da conta de doações ainda não estiver lá, `UNICO_API_KEY_DOACAO`.
   Com `'DOACAO_TESTE_CENTAVOS' => 500` para o teste.
5. **Teste real** com um cartão da filial: doação mensal de qualquer valor (cobra R$ 5,00 por causa do modo de
   teste). Conferir: a tela agradece, o e-mail chega, a linha fica `pago` com `assinatura_id`, e no painel da
   Unicopag a assinatura aparece **com R$ 5,00** (se aparecer R$ 500,00, a API lê reais e não centavos: cancelar,
   estornar e não seguir). Cancelar a assinatura de teste depois.
6. Tirar `DOACAO_TESTE_CENTAVOS`. O primeiro mês de verdade de alguém confirma também o caminho do postback
   (`doacao_postback_assinatura` e `doacao_mes_pago` no log, no mês seguinte).

## Voltar atrás

`'DOACAO_MENSAL' => 0` no `config.php`: a opção some da página na hora. Assinaturas já criadas continuam cobrando
na Unicopag e o postback continua registrando os meses; para parar uma, cancelar lá.

## Riscos conhecidos

- **Formato da resposta.** A documentação descreve `SubscriptionResource` (`id`, `status`, `lastCharge`,
  `charges`), mas nenhuma assinatura real foi criada ainda. Se a criação responder sem `id`, a doação falha com
  mensagem e o log registra `doacao_assinatura_sem_id`; se não trouxer cobrança `paid`, fica pendente e a página
  continua consultando (vence em 25 h).
- **Unidade do valor:** ver passo 5.
- **Postback:** o formato dos eventos vem da documentação; o primeiro mês seguinte de uma assinatura real é que prova.
