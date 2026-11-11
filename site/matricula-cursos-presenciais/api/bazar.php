<?php
/**
 * POST: registro de doação para o bazar solidário, vindo do formulário da página /bazar/ (11/11/2026).
 * Grava em mcp_bazar_doacoes, manda o e-mail à equipe e tenta o aviso por WhatsApp à responsável do bazar, que só sai
 * com o modelo aprovado na Meta (lib/bazar.php); senão o aviso fica pendente para a rodada de hora em hora.
 * Devolve o protocolo. O banco é a fonte da verdade: e-mail ou WhatsApp que falham não desfazem o registro.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (!function_exists('mcp_bazar_validar')) {
    mcp_falhar(503, 'O registro de doações não está disponível agora. Leve a doação à sede no horário de atendimento.');
}
$b = mcp_exigir_post_json();

// Campo armadilha: o formulário sempre manda vazio; robô que preenche tudo cai aqui.
if (mcp_texto($b['site'] ?? '', 10) !== '') {
    mcp_registrar(null, 'armadilha', 'bazar · ' . mcp_ip());
    mcp_falhar(422, 'Não foi possível enviar. Tente novamente.');
}
if (($b['aceite'] ?? null) !== true) {
    mcp_falhar(422, 'Marque que você concorda com o uso dos seus dados para combinarmos a entrega.', ['campo' => 'aceite']);
}
$v = mcp_bazar_validar($b);
unset($b);
if (!$v['ok']) {
    mcp_falhar(422, $v['erro'], ['campo' => $v['campo']]);
}
$dados = $v['dados'];

$ip = mcp_ip_balde();
foreach ([['ip', $ip, MCP_BAZAR_LIMITE_IP], ['telefone', $dados['telefone'], MCP_BAZAR_LIMITE_TELEFONE]] as [$coluna, $valor, [$maximo, $janela]]) {
    if ($valor !== '' && mcp_bazar_contar_recentes($coluna, $valor, $janela) >= $maximo) {
        mcp_registrar(null, 'limite', "bazar · $coluna · $maximo em {$janela}s");
        mcp_falhar(429, 'Você já enviou vários registros em pouco tempo. Aguarde um pouco e tente de novo.');
    }
}

$id = mcp_bazar_gravar($dados, $ip);
$protocolo = mcp_bazar_protocolo($id);
mcp_bazar_atualizar($id, ['protocolo' => $protocolo]);
$registro = mcp_bazar_por_id($id) ?? ($dados + ['id' => $id, 'protocolo' => $protocolo, 'criado_em' => mcp_agora()]);

$email = mcp_email_bazar_equipe($registro);
mcp_bazar_atualizar($id, ['email_equipe' => $email]);
$aviso = mcp_bazar_avisar_agora($id);
// O evento não leva nome nem telefone: só o número do registro e o que aconteceu com os avisos.
mcp_registrar(null, 'bazar', "#$id · e-mail $email · WhatsApp $aviso");

mcp_json(['ok' => true, 'protocolo' => $protocolo, 'nome' => mcp_primeiro_nome($dados['nome'])], 201);
