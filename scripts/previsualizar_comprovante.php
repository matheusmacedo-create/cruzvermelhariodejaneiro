#!/usr/bin/env php
<?php
/**
 * Gera comprovantes de inscrição em PDF com dados de exemplo, para conferir o visual sem banco, sem
 * rede e sem segredos (configuração descartável via MCP_CONFIG_ARQUIVO, como previsualizar_emails.php).
 *
 * Uso:  php scripts/previsualizar_comprovante.php [pasta de saída]   (padrão: <tmp>/mcp-comprovantes)
 *
 * Três casos: o do modelo (cartão, mesmo minuto), PIX pago horas depois com custos de processamento
 * e nome em caixa alta, e o pior caso de layout (nome e curso longos, que precisam quebrar linha).
 * Os CPFs são os de exemplo que scripts/testar_checkout.php já usa — nunca dado de aluno real.
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$pasta = $argv[1] ?? sys_get_temp_dir() . '/mcp-comprovantes';
if (!is_dir($pasta) && !mkdir($pasta, 0777, true)) {
    fwrite(STDERR, "não consegui criar $pasta\n");
    exit(1);
}
$configTeste = tempnam(sys_get_temp_dir(), 'mcp-config-');
file_put_contents($configTeste, "<?php return [
    'SITE_URL' => 'https://cruzvermelhariodejaneiro.org', 'EMAIL_CONTATO' => 'contato@cruzvermelhariodejaneiro.org',
    'ESCOLA_API_URL' => '', 'ESCOLA_URL' => 'https://escola.cursoscruzvermelha.org',
];");
putenv("MCP_CONFIG_ARQUIVO=$configTeste");
putenv('MCP_CATALOGO_ARQUIVO=' . $raiz . '/site/matricula-cursos-presenciais/cursos.json');
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $raiz . '/site/matricula-cursos-presenciais/api/lib.php';
restore_exception_handler();

$base = [
    'token' => str_repeat('a', 40), 'email' => 'aluno@exemplo.org', 'telefone' => '21999990000', 'status' => 'pago',
    'inscricao_centavos' => 9900, 'taxa_centavos' => 0, 'total_centavos' => 9900, 'bandeira' => null, 'ultimos4' => null,
];
$casos = [
    'modelo-cartao' => $base + [
        'nome' => 'Ana paula de souza', 'cpf' => '52998224725', 'curso_nome' => 'Punção Venosa', 'metodo' => 'cartao',
        'criado_em' => '2026-09-22 15:10:10', 'pago_em' => '2026-09-22 15:10:41', 'unicopag_hash' => 'exemplo001',
    ],
    'pix-com-custos' => [
        'nome' => 'MARIA DAS GRAÇAS DOS SANTOS', 'cpf' => '11144477735', 'curso_nome' => 'Bombeiro Civil', 'metodo' => 'pix',
        'inscricao_centavos' => 9900, 'taxa_centavos' => 495, 'total_centavos' => 10395,
        'criado_em' => '2026-09-22 12:05:00', 'pago_em' => '2026-09-22 19:48:00', 'unicopag_hash' => 'k7p2x9qwe4',
    ] + $base,
    'nome-e-curso-longos' => [
        'nome' => 'Maria Eduarda Albuquerque de Vasconcelos Cavalcanti Sampaio dos Santos Pereira', 'cpf' => '52998224725',
        'curso_nome' => 'Primeiros Socorros Lei Lucas - Ambientes com Crianças', 'metodo' => 'cartao',
        'bandeira' => 'mastercard', 'ultimos4' => '4242',
        'criado_em' => '2026-09-23 14:00:00', 'pago_em' => '2026-09-23 14:00:20', 'unicopag_hash' => 'q1w2e3r4t5',
    ] + $base,
    // Tudo que empurra o layout para baixo ao mesmo tempo: é o caso que tem de caber no A4.
    'pior-caso' => [
        'nome' => 'Maria Eduarda Albuquerque de Vasconcelos Cavalcanti Sampaio dos Santos Pereira Guimarães de Oliveira Montenegro',
        'cpf' => '11144477735', 'curso_nome' => 'Primeiros Socorros Lei Lucas - Ambientes com Crianças', 'metodo' => 'pix',
        'inscricao_centavos' => 9900, 'taxa_centavos' => 495, 'total_centavos' => 10395,
        'criado_em' => '2026-09-22 12:05:00', 'pago_em' => '2026-09-23 09:48:00', 'unicopag_hash' => 'z9y8x7w6v5',
    ] + $base,
];
foreach ($casos as $nome => $inscricao) {
    $bytes = mcp_comprovante_pdf($inscricao, '2026-09-23 17:00:00');
    file_put_contents("$pasta/$nome.pdf", $bytes);
    // Onde o rodapé termina e com que fator de espaçamento coube (1.00 = o do modelo).
    $conteudo = mcp_comprovante_conteudo($inscricao, '2026-09-23 17:00:00');
    foreach ([1.0, 0.92, 0.85, 0.78, 0.72] as $fator) {
        [, $fimY] = mcp_comprovante_desenhar($conteudo, $fator);
        if ($fimY <= McpPdf::ALTURA - 28) {
            break;
        }
    }
    printf("%-20s %6d bytes  rodapé termina em %5.1f pt de %.0f (fator %.2f)\n  anexo: %s\n",
        $nome, strlen($bytes), $fimY, McpPdf::ALTURA, $fator, mcp_comprovante_arquivo($inscricao));
}
