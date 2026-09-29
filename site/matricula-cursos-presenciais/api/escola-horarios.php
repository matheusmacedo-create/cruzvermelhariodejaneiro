<?php
/**
 * Respostas do questionário de dias e horários para o painel da secretaria da escola (aba "Horários").
 *   GET, com "Authorization: Bearer <SITE_HORARIOS_TOKEN>"  →  JSON de mcp_horarios_para_escola().
 * Servidor a servidor: sem a chave configurada responde 404; chave errada, 401, e cada IP tem até
 * 20 erros por hora. Nunca devolve CPF.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (!mcp_horarios_escola_configurado()) {
    mcp_falhar(404, 'Não encontrado.');
}
mcp_exigir_metodo('GET');
[$maximo, $janela] = MCP_HORARIOS_ESCOLA_FALHAS;
if (mcp_contar_eventos_recentes('escola_horarios_negado', mcp_ip(), $janela) >= $maximo) {
    mcp_falhar(429, 'Muitas tentativas.');
}
$cabecalhos = function_exists('getallheaders') ? array_change_key_case((array) getallheaders()) : [];
$autorizacao = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? $cabecalhos['authorization'] ?? '');
if (!mcp_horarios_escola_autorizado($autorizacao)) {
    mcp_registrar(null, 'escola_horarios_negado', mcp_ip());
    mcp_falhar(401, 'Não autorizado.');
}
mcp_json(mcp_horarios_para_escola(mcp_horarios_listar(null, 5000), mcp_horarios_sem_resposta(5000)));
