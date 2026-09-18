<?php
/**
 * Postback da Unicopag. O corpo é só gatilho: o hash é usado para reconsultar a transação na API,
 * e é essa resposta que decide. Responde 200 sempre que a notificação foi compreendida.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
mcp_exigir_metodo('POST');

$b = mcp_corpo_json();
if (!$b && !empty($_POST)) {
    $b = $_POST;
}
$hash = mcp_texto($b['hash'] ?? $b['id'] ?? $b['transaction'] ?? '', 64);
if ($hash === '') {
    mcp_falhar(400, 'Postback sem hash da transação.');
}
$inscricao = mcp_inscricao_por('unicopag_hash', $hash);
if (!$inscricao) {
    mcp_json(['ok' => true, 'ignorado' => 'transação desconhecida']);
}
mcp_registrar((int) $inscricao['id'], 'postback', mb_substr((string) json_encode(['event' => $b['event'] ?? null, 'payment_status' => $b['payment_status'] ?? null]), 0, 300));
$inscricao = mcp_sincronizar($inscricao);
mcp_json(['ok' => true, 'status' => $inscricao['status'] ?? null]);
