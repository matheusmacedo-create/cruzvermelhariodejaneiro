<?php
/**
 * Comprovante de comparecimento (página comparecimento/, lib/presenca.php), pelo link pessoal.
 *   GET ?t=<token>         dados da aula e se o comprovante já está disponível;
 *   GET ?t=<token>&pdf=1   o PDF, depois que a aula termina.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

mcp_exigir_metodo('GET');
$token = mcp_texto($_GET['t'] ?? '', MCP_TOKEN_TAMANHO);
$presenca = mcp_token_valido($token) ? mcp_presenca_por('token', $token) : null;
if (!$presenca) {
    mcp_falhar(404, 'Comprovante não encontrado. Confira o link.');
}
if (!isset($_GET['pdf'])) {
    mcp_json(['ok' => true] + mcp_presenca_publico($presenca));
}
if ($presenca['status'] !== 'valida') {
    mcp_falhar(410, 'Esta presença foi cancelada pela secretaria.');
}
if (!mcp_presenca_disponivel($presenca)) {
    mcp_falhar(403, 'O comprovante fica disponível quando a aula termina, em ' . mcp_data_brt((string) $presenca['fim'], 'd/m \à\s H:i') . '.');
}
$pdf = mcp_presenca_pdf($presenca);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . mcp_presenca_arquivo($presenca) . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
