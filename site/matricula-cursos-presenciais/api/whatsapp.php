<?php
/**
 * Webhook do WhatsApp (API oficial da Meta), usado só no modo "cloud" (lib/avisos.php):
 *   GET  ?hub.mode=subscribe&hub.verify_token=…&hub.challenge=…   verificação do endereço no painel da Meta;
 *   POST (assinado com X-Hub-Signature-256)                        situação das mensagens (entregue, lida,
 *        falhou) e respostas: quem responde PARAR (ou SAIR, STOP…) deixa de receber pelo WhatsApp.
 * Configuração em config.php: WHATSAPP_CLOUD_VERIFICACAO (o token que se escreve no painel da Meta) e
 * WHATSAPP_CLOUD_APP_SEGREDO (a chave secreta do app, que assina cada evento). Sem elas, responde 403/401.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$metodo = $_SERVER['REQUEST_METHOD'] ?? '';
if ($metodo === 'GET') {
    // O PHP troca o ponto dos nomes por sublinhado: hub.mode chega como hub_mode.
    $esperado = (string) mcp_cfg('WHATSAPP_CLOUD_VERIFICACAO', '');
    $desafio = (string) ($_GET['hub_challenge'] ?? '');
    if ($esperado !== '' && ($_GET['hub_mode'] ?? '') === 'subscribe' && hash_equals($esperado, (string) ($_GET['hub_verify_token'] ?? ''))
        && preg_match('/^[A-Za-z0-9_-]{1,100}$/', $desafio)) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $desafio;
        exit;
    }
    http_response_code(403);
    exit;
}
mcp_exigir_metodo('POST');
$corpo = mcp_corpo_bruto();
if (!mcp_whatsapp_webhook_assinatura_ok($corpo, (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''))) {
    http_response_code(401);
    exit;
}
$evento = json_decode($corpo, true);
mcp_json(['ok' => true, 'tratados' => is_array($evento) ? mcp_whatsapp_webhook_tratar($evento) : 0]);
