#!/usr/bin/env php
<?php
/**
 * Gera os e-mails transacionais em HTML e texto com dados de exemplo, para conferir o visual e a copy
 * sem banco, sem rede e sem segredos (usa uma configuração descartável via MCP_CONFIG_ARQUIVO).
 *
 * Uso:  php scripts/previsualizar_emails.php [pasta de saída]   (padrão: <tmp>/mcp-emails)
 * Abra os .html no navegador ou renderize com o Playwright para tirar prints.
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$pasta = $argv[1] ?? sys_get_temp_dir() . '/mcp-emails';
if (!is_dir($pasta) && !mkdir($pasta, 0777, true)) {
    fwrite(STDERR, "não consegui criar $pasta\n");
    exit(1);
}
$configTeste = tempnam(sys_get_temp_dir(), 'mcp-config-');
file_put_contents($configTeste, "<?php return [
    'SITE_URL' => 'https://cruzvermelhariodejaneiro.org', 'INSCRICAO_CENTAVOS' => '', 'PRECO_TESTE_CENTAVOS' => '',
    'TAXA_PIX_PCT' => 5.0, 'TAXA_PIX_FIXA' => 0, 'TAXA_CARTAO_PCT' => 5.0, 'TAXA_CARTAO_FIXA' => 0,
    'ESCOLA_API_URL' => '', 'ESCOLA_URL' => 'https://escola.cursoscruzvermelha.org', 'EMAIL_CONTATO' => 'contato@cruzvermelhariodejaneiro.org',
];");
putenv("MCP_CONFIG_ARQUIVO=$configTeste");
putenv('MCP_CATALOGO_ARQUIVO=' . $raiz . '/site/matricula-cursos-presenciais/cursos.json');
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $raiz . '/site/matricula-cursos-presenciais/api/lib.php';
restore_exception_handler();

$curso = mcp_curso('puncao-venosa') ?? mcp_catalogo()['cursos'][0];
$token = str_repeat('ab12', 10);
$base = [
    'id' => 42, 'token' => $token, 'status' => 'pendente', 'metodo' => 'pix', 'curso_slug' => $curso['slug'], 'curso_nome' => $curso['nome'],
    'nome' => 'Matheus Macedo', 'cpf' => '52998224725', 'email' => 'aluno@exemplo.org', 'telefone' => '21999998888',
    'inscricao_centavos' => 9900, 'taxa_centavos' => 495, 'total_centavos' => 10395, 'unicopag_hash' => 'tx_9f8e7d6c', 'ip' => '10.0.0.1',
    'pix_copia_cola' => '00020126580014br.gov.bcb.pix0136c1d2e3f4-0000-4a5b-8c9d-1e2f3a4b5c6d5204000053039865406103.955802BR5925CRUZ VERMELHA BRASILEIRA RJ6014RIO DE JANEIRO62290525MCP' . strtoupper(substr($token, 0, 20)) . '6304ABCD',
    'pix_url' => null, 'pix_imagem' => null, 'bandeira' => null, 'ultimos4' => null,
    'utm_source' => 'instagram', 'utm_campaign' => 'bio', 'criado_em' => gmdate('Y-m-d H:i:s'), 'pago_em' => null,
    'escola_status' => 'nao_aplicavel', 'escola_acesso' => null,
];
$pagaB = ['status' => 'pago', 'pago_em' => gmdate('Y-m-d H:i:s')] + $base;
$pagaCartao = ['metodo' => 'cartao', 'bandeira' => 'visa', 'ultimos4' => '4242', 'pix_copia_cola' => null, 'taxa_centavos' => 0, 'total_centavos' => 9900] + $pagaB;
$pagaA = ['escola_status' => 'ok', 'escola_acesso' => json_encode(['usuario' => 'aluno@exemplo.org', 'acesso' => ['senha' => 'Tempor4ria!', 'url' => 'https://escola.cursoscruzvermelha.org/login']])] + $pagaB;
$contato = [
    'id' => 7, 'protocolo' => mcp_contato_protocolo(7), 'nome' => 'Ana Beatriz Ferreira', 'email' => 'ana@exemplo.org', 'telefone' => '21988887777',
    'assunto' => 'curso', 'curso_slug' => $curso['slug'], 'curso_nome' => $curso['nome'],
    'mensagem' => "Oi! Vi o curso no Instagram e queria saber se tem turma à noite ou aos sábados.\nTrabalho durante a semana em horário comercial.\n\nObrigada!",
    'pagina' => '/matricula-cursos-presenciais/?curso=' . $curso['slug'], 'utm_source' => 'instagram', 'utm_medium' => 'social', 'utm_campaign' => 'bio',
    'utm_content' => null, 'utm_term' => null, 'fbclid' => null, 'gclid' => null, 'criado_em' => gmdate('Y-m-d H:i:s'),
];

$emails = [
    '1-pix-aberto' => mcp_montar_email_pix_aberto($base),
    '2-pago-confirmacao' => mcp_montar_email_aluno_pago($pagaB),
    '3-pago-cartao' => mcp_montar_email_aluno_pago($pagaCartao),
    '4-pago-acesso-escola' => mcp_montar_email_aluno_pago($pagaA),
    '5-secretaria' => mcp_montar_email_secretaria($pagaB),
    '6-contato-equipe' => mcp_montar_email_contato_equipe($contato),
    '7-contato-confirmacao' => mcp_montar_email_contato_confirmacao($contato),
];
foreach ($emails as $nome => $m) {
    file_put_contents("$pasta/$nome.html", $m['html']);
    file_put_contents("$pasta/$nome.txt", "Assunto: {$m['assunto']}\n\n{$m['texto']}\n");
    printf("%-24s %s\n", $nome, $m['assunto']);
}
unlink($configTeste);
echo "gravado em $pasta\n";
