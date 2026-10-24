#!/usr/bin/env php
<?php
/**
 * Testes da escolha de gateway das doações (Donaty × Unicopag): sem banco, sem rede, sem segredos.
 * A configuração é lida uma vez por processo, então cada gateway roda num processo próprio.
 *
 * Uso:  php scripts/testar_doacao_donaty.php
 * Sai com código 1 se algum teste falhar.
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);

if (($argv[1] ?? '') === '--filho') {
    $gateway = $argv[2] ?? '';
    $configGeral = tempnam(sys_get_temp_dir(), 'mcp-config-');
    file_put_contents($configGeral, "<?php return ['SITE_URL' => 'https://exemplo.org', 'UNICO_API_KEY' => 'chave-checkout'];");
    $configDoe = tempnam(sys_get_temp_dir(), 'doe-config-');
    $linhas = $gateway === 'ausente' ? '' : "'DOACAO_GATEWAY' => '$gateway',";
    file_put_contents($configDoe, "<?php return [$linhas 'DONATY_API_KEY' => 'dnt_sk_ficticia', 'UNICO_API_KEY_DOACAO' => 'chave-doacao'];");
    putenv("MCP_CONFIG_ARQUIVO=$configGeral");
    putenv("DOE_CONFIG_ARQUIVO=$configDoe");
    putenv('MCP_CATALOGO_ARQUIVO=' . $raiz . '/site/matricula-cursos-presenciais/cursos.json');
    $_SERVER['REQUEST_METHOD'] = 'CLI';
    require $raiz . '/site/doe/api/lib.php';
    restore_exception_handler();
    echo json_encode([
        'gateway' => mcp_doacao_gateway(), 'chave' => mcp_doacao_chave(),
        'opcoes' => mcp_doacao_gateway_opcoes(), 'metodos' => mcp_doacao_metodos(),
        // Uma doação antiga usa o gateway em que nasceu, não o atual.
        'chave_unicopag' => mcp_doacao_chave('unicopag'), 'chave_donaty' => mcp_doacao_chave('donaty'),
        'opcoes_unicopag' => mcp_doacao_gateway_opcoes('unicopag'), 'opcoes_donaty' => mcp_doacao_gateway_opcoes('donaty'),
    ]);
    exit(0);
}

$falhas = 0;
$total = 0;
function verificar(string $nome, mixed $obtido, mixed $esperado): void
{
    global $falhas, $total;
    $total++;
    if ($obtido === $esperado) {
        return;
    }
    $falhas++;
    fwrite(STDERR, sprintf("FALHOU %s\n  esperado: %s\n  obtido:   %s\n", $nome, var_export($esperado, true), var_export($obtido, true)));
}
function rodar(string $gateway): array
{
    $saida = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --filho ' . escapeshellarg($gateway));
    $dados = json_decode((string) $saida, true);
    return is_array($dados) ? $dados : ['erro' => (string) $saida];
}

// Sem DOACAO_GATEWAY: continua Unicopag (padrão seguro e caminho de volta).
$padrao = rodar('ausente');
verificar('padrão é a Unicopag', $padrao['gateway'] ?? null, 'unicopag');
verificar('padrão usa a chave da doação da Unicopag', $padrao['chave'] ?? null, 'chave-doacao');
verificar('padrão não troca base nem autenticação', $padrao['opcoes'] ?? null, []);
verificar('padrão oferece PIX e cartão', $padrao['metodos'] ?? null, ['pix', 'cartao']);

$unicopag = rodar('unicopag');
verificar('unicopag explícita', $unicopag['gateway'] ?? null, 'unicopag');
verificar('unicopag oferece PIX e cartão', $unicopag['metodos'] ?? null, ['pix', 'cartao']);

// Donaty: chave própria, base /api com Bearer, só PIX.
$donaty = rodar('donaty');
verificar('donaty ligada', $donaty['gateway'] ?? null, 'donaty');
verificar('donaty usa a chave DONATY_API_KEY', $donaty['chave'] ?? null, 'dnt_sk_ficticia');
verificar('donaty: base, prefixo e Bearer', $donaty['opcoes'] ?? null, [
    'base_url' => 'https://api.donaty.com.br', 'prefixo' => '/api', 'bearer' => true,
]);
verificar('donaty oferece só PIX', $donaty['metodos'] ?? null, ['pix']);

// Doação criada num gateway continua sendo consultada nele depois da troca (e na volta atrás).
foreach (['unicopag' => $unicopag, 'donaty' => $donaty] as $atual => $dados) {
    verificar("[$atual atual] doação da Unicopag usa a chave da Unicopag", $dados['chave_unicopag'] ?? null, 'chave-doacao');
    verificar("[$atual atual] doação da Donaty usa a chave da Donaty", $dados['chave_donaty'] ?? null, 'dnt_sk_ficticia');
    verificar("[$atual atual] doação da Unicopag usa o transporte padrão", $dados['opcoes_unicopag'] ?? null, []);
    verificar("[$atual atual] doação da Donaty usa Bearer em /api", $dados['opcoes_donaty']['bearer'] ?? null, true);
}

// Valor desconhecido não liga a Donaty por engano.
$estranho = rodar('qualquer-coisa');
verificar('gateway desconhecido cai na Unicopag', $estranho['gateway'] ?? null, 'unicopag');

fwrite(STDOUT, "$total testes, $falhas falhas\n");
exit($falhas > 0 ? 1 : 0);
