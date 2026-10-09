<?php
/**
 * GET ?curso={slug}&cobre=0|1&divulgacao=0|1: as parcelas no cartão da opção "Taxa de inscrição + matrícula" (pagar
 * tudo, spec 2.4). O amount sai do servidor (mcp_compra, preço real, cartão); as opções, da simulação da Unicopag
 * (cache de 10 min, filtros a cada leitura). Cada opção devolvida fica registrada como mostrada: é o que vale para a
 * validade de 48 h na hora de cobrar. Freio: 40 consultas por IP a cada 10 minutos. Contrato: contrato-api.md, seção 2.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
mcp_exigir_metodo('GET');

const MCP_PARCELAS_LIMITE = [40, 600];

$curso = mcp_curso(mcp_texto($_GET['curso'] ?? '', 80));
if (!$curso) {
    mcp_falhar(422, 'Escolha um curso válido.', ['campo' => 'curso']);
}
$info = mcp_planos_info($curso);
if (!in_array('taxa_e_matricula', $info['planos'], true)) {
    mcp_falhar(422, mcp_plano_mensagem($info['motivo']), ['campo' => 'plano', 'planos' => $info['planos'], 'motivo' => $info['motivo']]);
}
[$maximo, $janela] = MCP_PARCELAS_LIMITE;
$balde = mcp_ip_balde();
if (mcp_contar_eventos_recentes('parcelas_consulta', $balde, $janela) >= $maximo) {
    mcp_falhar(429, 'Muitas consultas seguidas. Aguarde alguns minutos e tente de novo.');
}
mcp_registrar(null, 'parcelas_consulta', $balde);

$divulgacao = ($_GET['divulgacao'] ?? '0') === '1' ? mcp_divulgacao_centavos() : 0;
$compra = mcp_compra($curso, 'taxa_e_matricula', 'cartao', ($_GET['cobre'] ?? '0') === '1', $divulgacao, 1);
$opcoes = mcp_parcelas_opcoes($compra['amount']);
mcp_parcelas_registrar_exibidas($compra['amount'], $opcoes);
mcp_json([
    'ok' => true,
    'amount_centavos' => $compra['amount'],
    'parcelado' => count($opcoes) > 1,
    'motivo' => count($opcoes) > 1 ? null : (mcp_parcelas_motivo() ?? 'desligado'),
    'opcoes' => $opcoes,
]);
