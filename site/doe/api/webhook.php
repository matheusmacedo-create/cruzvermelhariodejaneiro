<?php
/**
 * Postback da Unicopag (conta das doações). O corpo é só gatilho: o hash é usado para reconsultar
 * a transação na API, e é essa resposta que decide o status. Responde 200 sempre que a notificação
 * foi compreendida, inclusive para transação desconhecida (o provedor não precisa reenviar).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$b = mcp_ler_postback();
$hash = mcp_texto($b['hash'] ?? $b['id'] ?? $b['transaction'] ?? '', 64);
if ($hash === '') {
    mcp_falhar(400, 'Postback sem hash da transação.');
}
$doacao = mcp_doacao_por('unicopag_hash', $hash);
if (!$doacao) {
    mcp_json(['ok' => true, 'ignorado' => 'transação desconhecida']);
}
$resumo = ['event' => mcp_texto($b['event'] ?? '', 40), 'payment_status' => mcp_texto($b['payment_status'] ?? '', 40)];
mcp_doacao_registrar((int) $doacao['id'], 'postback', (string) json_encode($resumo, JSON_UNESCAPED_UNICODE));
$doacao = mcp_doacao_sincronizar($doacao);
mcp_json(['ok' => true, 'status' => $doacao['status'] ?? null]);
