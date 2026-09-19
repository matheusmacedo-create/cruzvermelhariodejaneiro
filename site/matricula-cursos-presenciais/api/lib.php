<?php
/**
 * Bootstrap do backend do checkout da matrícula em cursos presenciais (PHP 8.3, Hostinger).
 *
 * Endpoints públicos: info.php, pagamentos.php, status.php, webhook.php e contato.php (chat do site).
 * Tudo o mais fica em lib/ (negado por .htaccess) e em config.php (segredos, só no servidor; modelo
 * em config.example.php).
 *
 * Regras que atravessam o código inteiro:
 *  - erro nunca vai para a tela: display_errors desligado e tratador global que responde JSON 500;
 *  - o número do cartão passa em claro a caminho da Unicopag e nunca é gravado, logado ou devolvido;
 *  - o corpo do postback não é fonte da verdade: só o hash é usado, para reconsultar a API;
 *  - toda mudança de status passa por mcp_aplicar_status(), com transições guardadas no SQL.
 */
declare(strict_types=1);

const MCP_VERSAO = '2026-09-19.1';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('UTC');

set_error_handler(static function (int $severidade, string $mensagem, string $arquivo, int $linha): bool {
    if (!(error_reporting() & $severidade)) {
        return false; // silenciado com @, como o mail() de fallback
    }
    throw new ErrorException($mensagem, 0, $severidade, $arquivo, $linha);
});

set_exception_handler(static function (Throwable $e): void {
    $bancoFora = $e instanceof McpBancoIndisponivel;
    if (!$bancoFora) {
        error_log(sprintf('[matricula] erro não tratado: %s: %s em %s:%d', get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine()));
    }
    if (!headers_sent()) {
        http_response_code($bancoFora ? 503 : 500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode(['ok' => false, 'erro' => $bancoFora
        ? 'Banco de dados indisponível. Tente novamente em instantes.'
        : 'Erro interno. Tente novamente em instantes.'], JSON_UNESCAPED_UNICODE);
    exit;
});

foreach (['config', 'http', 'db', 'unicopag', 'escola', 'email', 'publico'] as $modulo) {
    require __DIR__ . '/lib/' . $modulo . '.php';
}
