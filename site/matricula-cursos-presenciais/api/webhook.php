<?php
/**
 * Postback da Unicopag. O corpo é só gatilho: o hash é usado para reconsultar a transação na API,
 * e é essa resposta que decide o status. Responde 200 sempre que a notificação foi compreendida,
 * inclusive para transação desconhecida (o provedor não precisa reenviar). A cobrança da matrícula paga depois da taxa
 * (api/matricula.php) usa o mesmo postback: o hash que não é de uma inscrição é procurado em mcp_matricula_pagamentos.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$b = mcp_ler_postback();
$hash = mcp_texto($b['hash'] ?? $b['id'] ?? $b['transaction'] ?? '', 64);
if ($hash === '') {
    mcp_falhar(400, 'Postback sem hash da transação.');
}
$inscricao = mcp_inscricao_por('unicopag_hash', $hash);
if (!$inscricao) {
    // A matrícula paga depois da taxa (01/11/2026, api/matricula.php) tem a cobrança em mcp_matricula_pagamentos, com o
    // mesmo postback: a mesma regra (o corpo é só gatilho; vale a reconsulta). Sem o módulo ou a tabela, ignora como antes.
    $pg = null;
    if (is_file(__DIR__ . '/lib/recuperacao_whatsapp.php')) {
        require_once __DIR__ . '/lib/recuperacao_whatsapp.php';
        try {
            $pg = mcp_mp_pagamento_por('unicopag_hash', $hash);
        } catch (PDOException) {
            $pg = null;
        }
    }
    if (!$pg) {
        mcp_json(['ok' => true, 'ignorado' => 'transação desconhecida']);
    }
    mcp_registrar((int) $pg['inscricao_id'], 'matricula_postback', mcp_texto($b['payment_status'] ?? '', 40));
    $pg = mcp_mp_sincronizar($pg);
    mcp_json(['ok' => true, 'status' => $pg['status'] ?? null]);
}
$resumo = ['event' => mcp_texto($b['event'] ?? '', 40), 'payment_status' => mcp_texto($b['payment_status'] ?? '', 40)];
mcp_registrar((int) $inscricao['id'], 'postback', (string) json_encode($resumo, JSON_UNESCAPED_UNICODE));
$inscricao = mcp_sincronizar($inscricao);
mcp_json(['ok' => true, 'status' => $inscricao['status'] ?? null]);
