<?php
/**
 * Matrículas pagas para o Google Ads (lib/google_ads.php): o CSV que a programação de envios do Google Ads busca
 * uma vez por dia.
 *   GET, com "Authorization: Basic <usuário:senha>"  →  text/csv no modelo "Conversões de cliques".
 * Servidor a servidor: sem a senha configurada (GOOGLE_ADS_IMPORT_SENHA em config-google.php) responde 404;
 * sem credencial ou com credencial errada, 401, e cada IP tem até 20 erros por hora. Só sai o gclid, a hora, o
 * valor e um id que é hash do token: nenhum dado pessoal.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/google_ads.php';

if (!mcp_gads_configurado()) {
    mcp_falhar(404, 'Não encontrado.');
}
mcp_exigir_metodo('GET');
[$maximo, $janela] = MCP_GADS_FALHAS;
if (mcp_contar_eventos_recentes('gads_negado', mcp_ip_balde(), $janela) >= $maximo) {
    mcp_falhar(429, 'Muitas tentativas.');
}
$cabecalhos = function_exists('getallheaders') ? array_change_key_case((array) getallheaders()) : [];
$autorizacao = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? $cabecalhos['authorization'] ?? '');
// Onde o PHP já separou o usuário e a senha (mod_php), o cabeçalho não chega inteiro: é remontado.
if ($autorizacao === '' && isset($_SERVER['PHP_AUTH_USER'])) {
    $autorizacao = 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . (string) ($_SERVER['PHP_AUTH_PW'] ?? ''));
}
if (!mcp_gads_autorizado($autorizacao)) {
    // Sem credencial é só o primeiro pedido de quem espera o desafio: não conta como tentativa.
    if ($autorizacao !== '') {
        mcp_registrar(null, 'gads_negado', mcp_ip_balde());
    }
    header('WWW-Authenticate: Basic realm="Google Ads", charset="UTF-8"');
    mcp_falhar(401, 'Não autorizado.');
}
$linhas = mcp_gads_buscar(mcp_gads_dias());
mcp_registrar(null, 'gads_exportado', count($linhas) . ' linhas');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: inline; filename="google-ads-conversoes.csv"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo mcp_gads_csv($linhas);
