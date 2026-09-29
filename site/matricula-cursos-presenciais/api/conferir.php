<?php
/**
 * Conferência de documentos do ponto da sede (página conferir/, lib/presenca.php): comprovante de
 * comparecimento e declaração de horas voluntárias.
 *   GET ?c=<código>   o que o documento declara, com o CPF mascarado, e se ainda vale.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

const MCP_CONFERIR_LIMITE_IP = [30, 600];

mcp_exigir_metodo('GET');
[$maximo, $janela] = MCP_CONFERIR_LIMITE_IP;
if (mcp_contar_eventos_recentes('conferir', mcp_ip(), $janela) >= $maximo) {
    mcp_falhar(429, 'Muitas consultas em pouco tempo. Aguarde alguns minutos e tente de novo.');
}
$codigo = mcp_codigo_normalizar($_GET['c'] ?? '');
if ($codigo === '') {
    mcp_falhar(422, 'O código tem 8 letras e números, como K7QM-4XPA.');
}
mcp_registrar(null, 'conferir', mcp_ip());
$documento = mcp_conferir($codigo);
if (!$documento) {
    mcp_falhar(404, 'Não encontramos nenhum documento com este código. Confira as letras e os números.');
}
mcp_json(['ok' => true] + $documento);
