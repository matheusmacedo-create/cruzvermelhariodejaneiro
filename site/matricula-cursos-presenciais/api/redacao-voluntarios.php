<?php
/**
 * Ligação com a Redação (27/10/2026): as fichas de voluntariado do site aparecem em Voluntários → Inscrições
 * pendentes da Redação, e a aprovação ou a recusa feita lá volta para cá (lib/voluntarios.php, "ligação com a Redação").
 *   GET  ?desde=<id>&limite=<1..50>          →  {fichas: [...], ultimo, mais}   (com CPF e a selfie em base64)
 *   POST {"acao":"aprovar","protocolo","quem","cpf"?}  →  aceita a ficha e gera o código de aprovação
 *   POST {"acao":"recusar","protocolo","quem"}         →  ficha recusada
 * Servidor a servidor, com "Authorization: Bearer <REDACAO_TOKEN>" (config-redacao.php): sem a chave configurada responde
 * 404; chave errada, 401, e cada IP tem até 20 erros por hora. Não é chamada por navegador (sem CORS).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (!mcp_redacao_configurado()) {
    mcp_falhar(404, 'Não encontrado.');
}
[$maximo, $janela] = MCP_REDACAO_FALHAS;
if (mcp_contar_eventos_recentes('redacao_negado', mcp_ip_balde(), $janela) >= $maximo) {
    mcp_falhar(429, 'Muitas tentativas.');
}
$cabecalhos = function_exists('getallheaders') ? array_change_key_case((array) getallheaders()) : [];
$autorizacao = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? $cabecalhos['authorization'] ?? '');
if (!mcp_redacao_autorizado($autorizacao)) {
    mcp_registrar(null, 'redacao_negado', mcp_ip_balde());
    mcp_falhar(401, 'Não autorizado.');
}
header('Cache-Control: no-store');

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($metodo === 'GET') {
    mcp_json(mcp_redacao_fichas((int) ($_GET['desde'] ?? 0), (int) ($_GET['limite'] ?? MCP_REDACAO_LOTE_MAX)));
}
if ($metodo !== 'POST') {
    mcp_falhar(405, 'Método não permitido.');
}
$tipo = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
if (!str_starts_with($tipo, 'application/json')) {
    mcp_falhar(415, 'Envie o corpo em JSON.');
}
$b = json_decode(mcp_corpo_bruto() ?: '', true);
if (!is_array($b)) {
    mcp_falhar(400, 'Corpo da requisição inválido.');
}
$protocolo = mcp_texto($b['protocolo'] ?? '', 24);
$quem = mcp_texto($b['quem'] ?? '', 150);
$r = match ((string) ($b['acao'] ?? '')) {
    'aprovar' => mcp_redacao_aprovar($protocolo, $quem, mcp_texto($b['cpf'] ?? '', 20)),
    'recusar' => mcp_redacao_recusar($protocolo, $quem),
    default => ['ok' => false, 'erro' => 'Ação desconhecida.'],
};
mcp_registrar(null, 'redacao_voluntario', ((string) ($b['acao'] ?? '?')) . ' · ' . $protocolo . ' · ' . ($r['ok'] ? 'ok' : 'erro'));
if (!$r['ok']) {
    mcp_falhar(422, (string) $r['erro']);
}
mcp_json($r);
