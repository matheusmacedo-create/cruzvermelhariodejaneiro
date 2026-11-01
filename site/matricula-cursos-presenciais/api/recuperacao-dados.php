<?php
/**
 * Pré-preenchimento do checkout para quem chega pelo link da recuperação (01/11/2026): o e-mail dizia "seus dados ficaram
 * registrados", mas o checkout abria vazio e a pessoa digitava tudo de novo.
 *
 * GET api/recuperacao-dados.php[?t=<token>] → {"nome", "email", "telefone"} (só as chaves com valor) ou {}. O token vem do
 * ?t= ou do cookie que o api/ir.php grava (MCP_RW_COOKIE); é o de mcp_rw_token: uma referência curta ao cadastro, a
 * validade (30 dias) e a assinatura. NUNCA devolve o CPF (o checkout pede o CPF à pessoa). Token ausente, adulterado ou
 * vencido, cadastro que não existe mais, escola fora do ar ou qualquer erro: {} com 200, para o checkout seguir com os
 * campos vazios sem mostrar erro nenhum. no-store e noindex: a resposta tem dado pessoal e não pode ficar em cache.
 */
declare(strict_types=1);

$dados = [];
try {
    require __DIR__ . '/lib.php';
    require_once __DIR__ . '/lib/recuperacao_whatsapp.php';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $token = is_string($_GET['t'] ?? null) && $_GET['t'] !== '' ? $_GET['t'] : (is_string($_COOKIE[MCP_RW_COOKIE] ?? null) ? $_COOKIE[MCP_RW_COOKIE] : '');
        $ref = $token !== '' && strlen($token) <= 120 ? mcp_rw_token_ref($token, time()) : null;
        $dados = $ref !== null ? mcp_rw_dados_da_ref($ref) : [];
    }
} catch (Throwable $e) {
    error_log('[matricula] pré-preenchimento da recuperação: ' . get_class($e));
    $dados = [];
}
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
echo json_encode((object) array_intersect_key($dados, array_flip(['nome', 'email', 'telefone'])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
