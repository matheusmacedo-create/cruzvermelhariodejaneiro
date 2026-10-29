<?php
/**
 * Postback da Unicopag (conta das doações): cobranças avulsas e, com subscription.id, a doação mensal.
 * O corpo é só gatilho: o hash é usado para reconsultar a transação na API, e é essa resposta que decide o status. Responde 200 sempre que a notificação
 * foi compreendida, inclusive para transação desconhecida (o provedor não precisa reenviar).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$b = mcp_ler_postback();

// API de assinaturas (doação mensal): o envelope traz subscription.id. Como no PIX, o corpo é só gatilho; a
// assinatura é reconsultada, e é a resposta da API que confirma o primeiro mês ou registra os seguintes.
if (is_array($b['subscription'] ?? null) && is_string($b['subscription']['id'] ?? null) && $b['subscription']['id'] !== '') {
    $assinaturaId = mcp_texto($b['subscription']['id'], 64);
    $doacao = mcp_doacao_por('assinatura_id', $assinaturaId);
    if (!$doacao) {
        mcp_json(['ok' => true, 'ignorado' => 'assinatura desconhecida']);
    }
    mcp_doacao_registrar((int) $doacao['id'], 'postback_assinatura', mcp_texto($b['event'] ?? '', 40));
    $doacao = mcp_doacao_sincronizar_assinatura($doacao);
    mcp_json(['ok' => true, 'status' => $doacao['status'] ?? null]);
}
// Unicopag manda o hash no nível de cima; a Donaty (evento transaction.updated) pode aninhá-lo em data/transaction.
$candidatos = [$b['hash'] ?? null, $b['id'] ?? null, $b['transaction'] ?? null];
foreach (['data', 'transaction', 'payload'] as $envelope) {
    if (is_array($b[$envelope] ?? null)) {
        $candidatos[] = $b[$envelope]['hash'] ?? null;
        $candidatos[] = $b[$envelope]['id'] ?? null;
        $candidatos[] = is_array($b[$envelope]['transaction'] ?? null) ? ($b[$envelope]['transaction']['hash'] ?? null) : null;
    }
}
$hash = '';
foreach ($candidatos as $candidato) {
    if (is_string($candidato) && $candidato !== '') {
        $hash = mcp_texto($candidato, 64);
        break;
    }
}
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
