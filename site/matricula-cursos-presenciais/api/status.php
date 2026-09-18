<?php
/** GET ?t=<token>: estado da inscrição. Enquanto pendente, reconsulta a Unicopag (no máximo a cada 6 s). */
declare(strict_types=1);
require __DIR__ . '/lib.php';
mcp_exigir_metodo('GET');

$token = mcp_texto($_GET['t'] ?? '', 40);
if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
    mcp_falhar(404, 'Inscrição não encontrada.');
}
$inscricao = mcp_inscricao_por('token', $token);
if (!$inscricao) {
    mcp_falhar(404, 'Inscrição não encontrada.');
}
if ($inscricao['status'] === 'pendente') {
    $ultima = $inscricao['consultado_em'] ? strtotime($inscricao['consultado_em'] . ' UTC') : 0;
    if (time() - $ultima >= 6) {
        $inscricao = mcp_sincronizar($inscricao);
    }
    // Cobrança que passou de 25 h sem desfecho do provedor: vencida para a página.
    if ($inscricao['status'] === 'pendente' && time() - strtotime($inscricao['criado_em'] . ' UTC') > 25 * 3600) {
        $inscricao = mcp_aplicar_status((int) $inscricao['id'], 'expirado');
    }
}
$inscricao = mcp_escola_retentar_se_preciso($inscricao);
mcp_json(mcp_publico($inscricao));
