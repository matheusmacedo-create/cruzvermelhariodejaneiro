<?php
/**
 * Pagar a matrícula de uma inscrição que já pagou só a taxa (01/11/2026, lib/matricula_pendente.php). A tela é
 * pagar-matricula/ (static/matricula.js); quem chega é quem tocou no botão de uma mensagem da matrícula pendente
 * (WhatsApp ou e-mail), pelo link curto api/ir.php/<w|e>/<curso>/<mat1|mat2>/<token>, que põe o token num cookie só da
 * api/ (MCP_RW_COOKIE) e leva à tela sem ele na URL (nem GA4, nem Pixel, nem Referer).
 *
 *  GET                → a situação: primeiro nome, curso, turma, valor, se pode pagar (e por quê não) e, se houver, o PIX
 *                       ainda aberto desta matrícula (a tela recarregada mostra o mesmo QR). Sem token que confere: 404.
 *  POST {metodo, cartao?, origem?}
 *                     → cria a cobrança SÓ da matrícula (PIX ou cartão à vista) na Unicopag, com os dados do pagador da
 *                       própria inscrição (nada disso vem do navegador), ou devolve o PIX pendente da mesma matrícula.
 *  GET ?c=<token do pagamento>
 *                     → o status do pagamento, para a tela acompanhar (reconsulta a Unicopag no máximo a cada 6 s; o PIX
 *                       vence com 25 h).
 *
 * O token do link é o dos links da recuperação (mcp_rw_token) com a referência m<id da inscrição>: assinado, com
 * validade de 30 dias e sem dado pessoal. O cartão passa a caminho da Unicopag e é descartado (só bandeira e final ficam).
 * A resposta nunca traz CPF, telefone nem hash da transação; o e-mail vai mascarado.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/recuperacao_whatsapp.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$agora = time();

// ----------------------------------------------------------------------------- acompanhar um pagamento
if ($metodo === 'GET' && isset($_GET['c'])) {
    $token = mcp_texto($_GET['c'], MCP_TOKEN_TAMANHO);
    $pg = mcp_token_valido($token) ? mcp_mp_pagamento_por('token', $token) : null;
    $i = $pg ? mcp_inscricao_por('id', (string) $pg['inscricao_id']) : null;
    if (!$pg || !$i) {
        mcp_falhar(404, 'Pagamento não encontrado.');
    }
    if ($pg['status'] === 'pendente') {
        $ultima = (int) mcp_utc_ts($pg['consultado_em'] ?? null);
        if ($agora - $ultima >= MCP_MP_RECONSULTA_S) {
            $pg = mcp_mp_sincronizar($pg);
        }
        if (($pg['status'] ?? '') === 'pendente' && $agora - (int) mcp_utc_ts($pg['criado_em']) > MCP_MP_VENCIMENTO_S) {
            $pg = mcp_mp_aplicar_status((int) $pg['id'], 'expirado');
        }
    }
    mcp_json(mcp_mp_publico($i, mcp_mp_situacao($i, $agora), $pg));
}

// ----------------------------------------------------------------------------- a inscrição do link
// O token vem do cookie que o api/ir.php gravou (ou de ?t=, para quem abriu a tela por outro caminho com o link inteiro).
$corpo = $metodo === 'POST' ? mcp_exigir_post_json() : [];
$tokenLink = is_string($_GET['t'] ?? null) && $_GET['t'] !== '' ? $_GET['t'] : (is_string($corpo['t'] ?? null) && $corpo['t'] !== '' ? $corpo['t']
    : (is_string($_COOKIE[MCP_RW_COOKIE] ?? null) ? $_COOKIE[MCP_RW_COOKIE] : ''));
$i = $tokenLink !== '' ? mcp_mp_inscricao_do_token($tokenLink, $agora) : null;
if ($i === null) {
    mcp_falhar(404, 'Este link não vale mais. Abra de novo o botão da mensagem mais recente ou fale com a secretaria.', ['motivo' => 'link']);
}
$situacao = mcp_mp_situacao($i, $agora);

if ($metodo === 'GET') {
    // A tela recarregada mostra o PIX que já está aberto, em vez de gerar outro.
    $aberto = $situacao['pode'] ? mcp_mp_pix_aberto($i, $situacao['valor']) : null;
    mcp_json(mcp_mp_publico($i, $situacao, $aberto));
}
if ($metodo !== 'POST') {
    header('Allow: GET, POST');
    mcp_falhar(405, 'Método não permitido.');
}

// ----------------------------------------------------------------------------- cobrar
// Campo armadilha: escondido na página; robô que preenche tudo cai aqui.
if (mcp_texto($corpo['site'] ?? '', 10) !== '') {
    mcp_registrar(null, 'armadilha', mcp_ip());
    mcp_falhar(422, 'Não foi possível processar o pagamento. Tente novamente.');
}
[$maximo, $janela] = MCP_MP_LIMITE_IP;
if (mcp_contar_eventos_recentes('matricula_pedido', mcp_ip_balde(), $janela) >= $maximo) {
    mcp_falhar(429, 'Muitas tentativas seguidas. Aguarde alguns minutos e tente de novo.');
}
mcp_registrar(null, 'matricula_pedido', mcp_ip_balde());
if (!$situacao['pode']) {
    $mensagens = [
        'paga' => 'A matrícula deste curso já está paga. Não pague de novo.',
        'completa' => 'Esta inscrição já incluía a matrícula. Não pague de novo.',
        'turma_passou' => 'A turma desta inscrição já começou. Fale com a secretaria pelo chat para ver a próxima.',
    ];
    mcp_falhar(422, $mensagens[$situacao['motivo']] ?? 'A matrícula desta inscrição não pode ser paga por aqui. Fale com a secretaria pelo chat.', ['motivo' => $situacao['motivo']]);
}
$forma = ($corpo['metodo'] ?? 'pix') === 'cartao' ? 'cartao' : 'pix';
$cartao = $forma === 'cartao' ? mcp_validar_cartao(is_array($corpo['cartao'] ?? null) ? $corpo['cartao'] : []) : null;
$o = is_array($corpo['origem'] ?? null) ? $corpo['origem'] : [];
$origem = ['utm_source' => mcp_texto($o['utm_source'] ?? '', 120) ?: null, 'utm_campaign' => mcp_texto($o['utm_campaign'] ?? '', 160) ?: null];
unset($corpo);
try {
    [$pg, $http] = mcp_mp_cobrar($i, $situacao['valor'], $forma, $cartao, $origem);
} catch (McpUnicopagErro $e) {
    mcp_registrar((int) $i['id'], 'matricula_unicopag_recusou', $e->status . ' ' . mb_substr($e->getMessage(), 0, 120));
    mcp_falhar(in_array($e->status, [400, 402, 422], true) ? 422 : 502, $e->paraOAluno());
} finally {
    unset($cartao);
}
$publico = mcp_mp_publico($i, mcp_mp_situacao($i, $agora), $pg);
if (($pg['status'] ?? '') === 'recusado') {
    mcp_json(array_replace($publico, ['ok' => false, 'erro' => 'O pagamento não passou. O banco recusou a cobrança. Tente outro cartão ou pague por PIX.']), 402);
}
mcp_json($publico, $http);
