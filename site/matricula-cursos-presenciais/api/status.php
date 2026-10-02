<?php
/**
 * GET ?t=<token>: estado da inscrição para as telas de acompanhamento. Enquanto pendente,
 * reconsulta a Unicopag (no máximo a cada 6 s, por inscrição) e vence a cobrança após 25 h.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

const MCP_RECONSULTA_SEGUNDOS = 6;
const MCP_VENCIMENTO_SEGUNDOS = 25 * 3600;

mcp_exigir_metodo('GET');

$token = mcp_texto($_GET['t'] ?? '', MCP_TOKEN_TAMANHO);
$inscricao = mcp_token_valido($token) ? mcp_inscricao_por('token', $token) : null;
if (!$inscricao) {
    mcp_falhar(404, 'Inscrição não encontrada.');
}
// Quem abriu o link tem "não" para marketing: o "sim" guardado na inscrição pendente deixa de valer para o
// Purchase. Só retira, nunca dá (o link pode estar com outra pessoa; lib/meta.php).
mcp_meta_atualizar_escolha($inscricao);
$inscricao = mcp_inscricao_por('id', (string) $inscricao['id']) ?? $inscricao;
if ($inscricao['status'] === 'pendente') {
    $ultima = $inscricao['consultado_em'] ? (int) strtotime($inscricao['consultado_em'] . ' UTC') : 0;
    if (time() - $ultima >= MCP_RECONSULTA_SEGUNDOS) {
        $inscricao = mcp_sincronizar($inscricao);
    }
    if ($inscricao['status'] === 'pendente' && time() - (int) strtotime($inscricao['criado_em'] . ' UTC') > MCP_VENCIMENTO_SEGUNDOS) {
        $inscricao = mcp_aplicar_status((int) $inscricao['id'], 'expirado');
    }
}
$inscricao = mcp_escola_retentar_se_preciso($inscricao);
mcp_json(mcp_publico($inscricao, $inscricao['status'] === 'pago' ? mcp_horarios_por_inscricao((int) $inscricao['id']) : null));
