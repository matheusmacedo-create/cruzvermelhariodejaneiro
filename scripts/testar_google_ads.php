#!/usr/bin/env php
<?php
/**
 * Teste das matrículas pagas para o Google Ads (api/lib/google_ads.php), sem banco e sem rede: as funções que
 * decidem o que entra no arquivo e como ele sai são puras. Cobre:
 *   - quem entra: pago, com "sim" para marketing e gclid válido; quem recusou, quem não tem gclid, a pendente,
 *     a estornada e a de valor zero ficam de fora;
 *   - cupom de grupo: só a inscrição que guarda a cobrança, com o valor de todas as pessoas;
 *   - pagamento da matrícula feito depois: entra pela escolha e pelo gclid da inscrição, com id próprio;
 *   - o arquivo: linha do fuso, cabeçalho do modelo do Google Ads, valor com ponto, id que não revela o token,
 *     nenhum dado pessoal, nome da conversão com vírgula entre aspas;
 *   - a senha: usuário e senha certos, errados, cabeçalho torto, senha curta (desligado);
 *   - o endereço sem a senha configurada responde 404 sem abrir o banco.
 *
 * Uso: php scripts/testar_google_ads.php
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$api = $raiz . '/site/matricula-cursos-presenciais/api';
$tmp = rtrim(sys_get_temp_dir(), '/') . '/mcp-gads-' . bin2hex(random_bytes(3));
if (!mkdir($tmp, 0700, true)) {
    fwrite(STDERR, "Não consegui criar $tmp.\n");
    exit(2);
}
$senha = bin2hex(random_bytes(16));
$config = $tmp . '/config.php';
file_put_contents($config, '<?php return ' . var_export([
    'GOOGLE_ADS_IMPORT_SENHA' => $senha, 'GOOGLE_ADS_IMPORT_USUARIO' => 'google-ads', 'GOOGLE_ADS_IMPORT_CONVERSAO' => 'Matricula paga (servidor)',
], true) . ';');
putenv('MCP_CONFIG_ARQUIVO=' . $config);
foreach (['ESCOLA', 'WHATSAPP', 'META', 'PAGAR_TUDO', 'GOOGLE', 'REDACAO', 'COORDENACAO', 'IA'] as $extra) {
    putenv("MCP_CONFIG_{$extra}_ARQUIVO=$tmp/nao-existe.php");
}
date_default_timezone_set('UTC');
foreach (['config', 'http', 'meta', 'google_ads'] as $modulo) {
    require $api . '/lib/' . $modulo . '.php';
}

$falhas = 0;
$total = 0;
function confere(string $nome, bool $ok, string $detalhe = ''): void
{
    global $falhas, $total;
    $total++;
    if (!$ok) {
        $falhas++;
    }
    echo ($ok ? '  ok   ' : '  FALHA ') . $nome . (!$ok && $detalhe !== '' ? "\n         $detalhe" : '') . "\n";
}

$gclid = static fn(string $s): string => 'Cj0KCQiA' . str_pad($s, 60, 'x') . '_wcB';
$token = static fn(string $s): string => substr(hash('sha256', $s), 0, 40);
$base = ['status' => 'pago', 'meta_marketing' => 1, 'pago_em' => '2026-11-20 14:03:09', 'total_centavos' => 15000, 'grupo_id' => null, 'grupo_qtd' => null,
    'nome' => 'Maria Teste', 'cpf' => '12345678909', 'email' => 'maria@example.org', 'telefone' => '21999990000'];
$inscricoes = [
    ['id' => 1, 'token' => $token('a'), 'gclid' => $gclid('a')] + $base,
    ['id' => 2, 'token' => $token('b'), 'gclid' => $gclid('b'), 'meta_marketing' => 0] + $base,       // recusou o marketing
    ['id' => 3, 'token' => $token('c'), 'gclid' => $gclid('c'), 'meta_marketing' => null] + $base,    // não escolheu
    ['id' => 4, 'token' => $token('d'), 'gclid' => null] + $base,                                     // sem clique de anúncio
    ['id' => 5, 'token' => $token('e'), 'gclid' => $gclid('e'), 'status' => 'pendente', 'pago_em' => null] + $base,
    ['id' => 6, 'token' => $token('f'), 'gclid' => $gclid('f'), 'status' => 'estornado'] + $base,
    ['id' => 7, 'token' => $token('g'), 'gclid' => '=cmd|calc,"x"'] + $base,                          // gclid que não é gclid
    ['id' => 8, 'token' => $token('h'), 'gclid' => $gclid('h'), 'total_centavos' => 0] + $base,
    // Cupom de grupo de 3 pessoas: a 9 guarda a cobrança; a 10 e a 11 seguem.
    ['id' => 9, 'token' => $token('i'), 'gclid' => $gclid('i'), 'grupo_id' => 9, 'grupo_qtd' => 3, 'total_centavos' => 12000, 'pago_em' => '2026-11-20 16:00:00'] + $base,
    ['id' => 10, 'token' => $token('j'), 'gclid' => $gclid('i'), 'grupo_id' => 9, 'total_centavos' => 12000, 'pago_em' => '2026-11-20 16:00:00'] + $base,
    ['id' => 11, 'token' => $token('k'), 'gclid' => $gclid('i'), 'grupo_id' => 9, 'total_centavos' => 12000, 'pago_em' => '2026-11-20 16:00:01'] + $base,
    ['id' => 12, 'token' => $token('l'), 'gclid' => $gclid('l'), 'pago_em' => '2026-02-30 10:00:00'] + $base,   // data que não existe
];
$pagamentos = [
    ['token' => $token('pa'), 'valor_centavos' => 25050, 'status' => 'pago', 'pago_em' => '2026-11-21 09:30:00', 'gclid' => $gclid('a'), 'meta_marketing' => 1],
    ['token' => $token('pb'), 'valor_centavos' => 25050, 'status' => 'pago', 'pago_em' => '2026-11-21 09:31:00', 'gclid' => $gclid('b'), 'meta_marketing' => 0],
    ['token' => $token('pc'), 'valor_centavos' => 25050, 'status' => 'pendente', 'pago_em' => null, 'gclid' => $gclid('a'), 'meta_marketing' => 1],
];

echo "Quem entra no arquivo\n";
$linhas = mcp_gads_linhas($inscricoes, $pagamentos, 'Matricula paga (servidor)');
$porGclid = [];
foreach ($linhas as $l) {
    $porGclid[$l[0]][] = $l;
}
confere('3 linhas: a inscrição paga, a principal do grupo e a matrícula paga depois', count($linhas) === 3, 'vieram ' . count($linhas));
confere('a inscrição paga com permissão entra com R$ 150,00', ($porGclid[$gclid('a')][0][3] ?? '') === '150.00' && ($porGclid[$gclid('a')][0][2] ?? '') === '2026-11-20 14:03:09');
confere('quem recusou o marketing não entra (inscrição e matrícula)', !isset($porGclid[$gclid('b')]));
confere('quem não escolheu não entra', !isset($porGclid[$gclid('c')]));
confere('pendente, estornada e valor zero não entram', !isset($porGclid[$gclid('e')]) && !isset($porGclid[$gclid('f')]) && !isset($porGclid[$gclid('h')]));
confere('data que não existe não entra', !isset($porGclid[$gclid('l')]));
confere('grupo: uma linha só, com o valor das 3 pessoas (R$ 360,00)', count($porGclid[$gclid('i')] ?? []) === 1 && $porGclid[$gclid('i')][0][3] === '360.00');
confere('matrícula paga depois: R$ 250,50, na hora dela, pelo gclid da inscrição', ($porGclid[$gclid('a')][1][3] ?? '') === '250.50' && ($porGclid[$gclid('a')][1][2] ?? '') === '2026-11-21 09:30:00');
confere('id da inscrição é o id_compra do GA4 (hash do token)', ($porGclid[$gclid('a')][0][5] ?? '') === mcp_meta_id_da_compra($token('a')));
confere('id da matrícula tem prefixo próprio', str_starts_with($porGclid[$gclid('a')][1][5] ?? '', 'm.'));
confere('linhas em ordem de pagamento', array_column($linhas, 2) === ['2026-11-20 14:03:09', '2026-11-20 16:00:00', '2026-11-21 09:30:00']);
confere('moeda BRL e nome da conversão em todas', count(array_filter($linhas, static fn($l) => $l[4] === 'BRL' && $l[1] === 'Matricula paga (servidor)')) === 3);

echo "O arquivo\n";
$csv = mcp_gads_csv($linhas);
$partes = explode("\n", rtrim($csv, "\n"));
confere('primeira linha é o fuso em UTC', $partes[0] === 'Parameters:TimeZone=+0000');
confere('cabeçalho do modelo do Google Ads', $partes[1] === 'Google Click ID,Conversion Name,Conversion Time,Conversion Value,Conversion Currency,Order ID');
confere('uma linha por pagamento, 6 campos cada', count($partes) === 5 && count(str_getcsv($partes[2])) === 6);
$pessoais = ['Maria', '12345678909', 'example.org', '21999990000', $token('a'), $token('pa')];
confere('nenhum dado pessoal nem token no arquivo', count(array_filter($pessoais, static fn($p) => str_contains($csv, $p))) === 0);
confere('sem pagamento, sai só o fuso e o cabeçalho', mcp_gads_csv([]) === "Parameters:TimeZone=+0000\n" . implode(',', MCP_GADS_COLUNAS) . "\n");
$comVirgula = mcp_gads_csv(mcp_gads_linhas([$inscricoes[0]], [], 'Matrícula, "paga"'));
confere('nome com vírgula e aspas vai entre aspas e volta inteiro', (str_getcsv(explode("\n", $comVirgula)[2])[1] ?? '') === 'Matrícula, "paga"');
confere('gclid com caractere estranho é recusado', mcp_gads_gclid('=1+1') === null && mcp_gads_gclid("abc\n" . str_repeat('x', 30)) === null && mcp_gads_gclid(str_repeat('x', 19)) === null);
confere('gclid válido passa como veio', mcp_gads_gclid(' ' . $gclid('z') . ' ') === $gclid('z'));

echo "A senha\n";
$basic = static fn(string $u, string $s): string => 'Basic ' . base64_encode("$u:$s");
confere('configurado com senha de 24 ou mais', mcp_gads_configurado());
confere('usuário e senha certos', mcp_gads_autorizado($basic('google-ads', $senha)));
confere('senha errada', !mcp_gads_autorizado($basic('google-ads', $senha . 'x')));
confere('usuário errado', !mcp_gads_autorizado($basic('outro', $senha)));
confere('sem cabeçalho, Bearer e base64 torto', !mcp_gads_autorizado('') && !mcp_gads_autorizado('Bearer ' . $senha) && !mcp_gads_autorizado('Basic @@@'));
confere('senha com dois-pontos no meio é lida inteira', !mcp_gads_autorizado($basic('google-ads', 'a:b')));
confere('janela de dias dentro dos limites', mcp_gads_dias() === MCP_GADS_DIAS_PADRAO);

echo "O endereço sem a senha\n";
$semSenha = $tmp . '/config-sem.php';
file_put_contents($semSenha, '<?php return ' . var_export(['GOOGLE_ADS_IMPORT_SENHA' => 'curta'], true) . ';');
$saida = [];
exec('MCP_CONFIG_ARQUIVO=' . escapeshellarg($semSenha) . ' REQUEST_METHOD=GET php ' . escapeshellarg($api . '/google-ads-conversoes.php') . ' 2>&1', $saida, $codigo);
$resposta = json_decode(implode("\n", $saida), true);
confere('senha curta: responde "Não encontrado" sem abrir o banco', is_array($resposta) && ($resposta['ok'] ?? null) === false && ($resposta['erro'] ?? '') === 'Não encontrado.', implode(' ', $saida));

array_map('unlink', glob($tmp . '/*') ?: []);
rmdir($tmp);
echo "\n" . ($falhas === 0 ? "Tudo certo: $total de $total." : "$falhas de $total falharam.") . "\n";
exit($falhas === 0 ? 0 : 1);
