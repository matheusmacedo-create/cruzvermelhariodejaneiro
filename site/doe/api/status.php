<?php
/**
 * GET ?t=<token>: estado da doação, para a página confirmar o PIX sozinha. Enquanto pendente,
 * reconsulta a Unicopag (no máximo a cada 6 s) e vence a cobrança depois de 25 h.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

const MCP_DOACAO_RECONSULTA_SEGUNDOS = 6;
const MCP_DOACAO_VENCIMENTO_SEGUNDOS = 25 * 3600;

mcp_exigir_metodo('GET');

$token = mcp_texto($_GET['t'] ?? '', MCP_TOKEN_TAMANHO);
$doacao = mcp_token_valido($token) ? mcp_doacao_por('token', $token) : null;
if (!$doacao) {
    mcp_falhar(404, 'Doação não encontrada.');
}
if ($doacao['status'] === 'pendente') {
    $ultima = $doacao['consultado_em'] ? (int) strtotime($doacao['consultado_em'] . ' UTC') : 0;
    if (time() - $ultima >= MCP_DOACAO_RECONSULTA_SEGUNDOS) {
        $doacao = mcp_doacao_sincronizar($doacao);
    }
    if ($doacao['status'] === 'pendente' && time() - (int) strtotime($doacao['criado_em'] . ' UTC') > MCP_DOACAO_VENCIMENTO_SEGUNDOS) {
        $doacao = mcp_doacao_aplicar_status((int) $doacao['id'], 'expirado');
    }
}
mcp_json(mcp_doacao_publico($doacao));
