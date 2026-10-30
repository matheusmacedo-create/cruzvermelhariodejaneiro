<?php
/**
 * Webhook da Kapso para a recuperação por WhatsApp (lib/recuperacao_whatsapp.php): o número dedicado manda aqui a
 * entrega, a leitura e as respostas das mensagens automáticas (payload v2, avulso ou em lote).
 *
 *  - Assinatura: HMAC-SHA256 do corpo cru, em hex, no cabeçalho X-Webhook-Signature, com o segredo do site
 *    (mcp_segredo('kapso_webhook'), que o botão "Ligar o recebimento de respostas" do painel entrega à Kapso). Sem ela,
 *    ou errada, 401 antes de qualquer leitura do banco além do segredo. Qualquer método sem assinatura também é 401.
 *  - Entregue/lida/falhou: atualiza a linha pelo wamid. Resposta "Não tenho interesse" (o botão do rec_inscricao_3),
 *    SAIR, PARAR ou STOP: bloqueia o WhatsApp do número (mcp_avisos_bloqueios, origem 'recuperacao'). Qualquer outra
 *    resposta fica guardada para a secretaria ver no painel (api/recuperacao.php).
 *  - Responde 200 sempre que entendeu o pedido, para a Kapso não reenviar.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/recuperacao_whatsapp.php';

// Em lote, a Kapso junta vários eventos num pedido: o limite de 64 KB do site recusaria.
$corpo = mcp_corpo_bruto(1024 * 1024);
if (!mcp_rw_assinatura_ok($corpo, (string) ($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ''))) {
    mcp_falhar(401, 'Assinatura ausente ou inválida.');
}
mcp_exigir_metodo('POST');
$dados = json_decode($corpo, true);
if (!is_array($dados)) {
    mcp_falhar(400, 'Corpo inválido.');
}
$eventos = mcp_rw_ler_webhook($dados, mcp_texto($_SERVER['HTTP_X_WEBHOOK_EVENT'] ?? '', 60), mcp_rw_kapso_config()['numero_id']);
mcp_json(['ok' => true, 'tratados' => mcp_rw_webhook_tratar($eventos)]);
