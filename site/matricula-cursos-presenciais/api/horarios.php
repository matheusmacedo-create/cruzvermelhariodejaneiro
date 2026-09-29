<?php
/**
 * Dias e horários preferidos (tela horarios/, lib/horarios.php). Só com a inscrição paga.
 *   GET  ?t=<token>  curso, turma, opções e o que o aluno já respondeu;
 *   POST {t, dias[], periodos[], inicio, turma_serve?, observacao?}  grava (pode mudar depois).
 * Na primeira resposta, avisa a secretaria (EMAIL_SECRETARIA), se configurado.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

const MCP_HORARIOS_LIMITE_IP = [60, 3600];

$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$corpo = $post ? mcp_exigir_post_json() : [];
if (!$post) {
    mcp_exigir_metodo('GET');
}
$token = mcp_texto($post ? ($corpo['t'] ?? '') : ($_GET['t'] ?? ''), MCP_TOKEN_TAMANHO);
$inscricao = mcp_token_valido($token) ? mcp_inscricao_por('token', $token) : null;
if (!$inscricao) {
    mcp_falhar(404, 'Inscrição não encontrada.');
}
if ($inscricao['status'] !== 'pago') {
    mcp_falhar(403, 'O questionário abre depois que o pagamento da inscrição é confirmado.');
}
$id = (int) $inscricao['id'];
if (!$post) {
    mcp_json(mcp_horarios_para_tela($inscricao, mcp_horarios_por_inscricao($id)));
}

[$maximo, $janela] = MCP_HORARIOS_LIMITE_IP;
if (mcp_contar_eventos_recentes('horarios', mcp_ip(), $janela) >= $maximo) {
    mcp_falhar(429, 'Muitas tentativas em pouco tempo. Aguarde alguns minutos e tente de novo.');
}
$conferido = mcp_horarios_conferir($corpo, mcp_horarios_turma($inscricao) !== null);
if (!$conferido['ok']) {
    mcp_falhar(422, $conferido['erro'], ['campo' => $conferido['campo']]);
}
$primeira = mcp_horarios_salvar($id, (string) $inscricao['curso_slug'], $conferido['dados']);
mcp_registrar($id, 'horarios', mcp_ip());
$preferencia = mcp_horarios_por_inscricao($id);
if ($primeira && $preferencia) {
    mcp_horarios_avisar_secretaria($inscricao, $preferencia);
}
mcp_json(['salvo' => true, 'primeira' => $primeira] + mcp_horarios_para_tela($inscricao, $preferencia));
