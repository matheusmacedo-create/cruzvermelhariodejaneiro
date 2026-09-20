<?php
/**
 * GET: o que a página de doação precisa saber antes de qualquer escolha — valores sugeridos,
 * limites, custos de processamento por método e se a doação mensal está disponível.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

mcp_exigir_metodo('GET');

$padrao = mcp_doacao_valor_padrao();
mcp_json([
    'ok' => true,
    'versao' => MCP_VERSAO,
    'valores' => mcp_doacao_valores(),
    'valor_padrao' => $padrao,
    'minimo_centavos' => mcp_doacao_minimo(),
    'maximo_centavos' => mcp_doacao_maximo(),
    'mensal' => mcp_doacao_mensal_ativa(),
    'taxa' => [
        'pix' => ['pct' => (float) mcp_doacao_cfg('TAXA_PIX_PCT', 0), 'fixa' => (int) mcp_doacao_cfg('TAXA_PIX_FIXA', 0)],
        'cartao' => ['pct' => (float) mcp_doacao_cfg('TAXA_CARTAO_PCT', 0), 'fixa' => (int) mcp_doacao_cfg('TAXA_CARTAO_FIXA', 0)],
    ],
    'teste' => mcp_doacao_teste(),
]);
