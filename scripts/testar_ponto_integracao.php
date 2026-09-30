#!/usr/bin/env php
<?php
/**
 * Teste de ponta a ponta do ponto da sede, contra um MariaDB LOCAL. Cobre:
 *   - colaboradores: cadastro no portal, entrada e saída no aparelho da sede e no celular (com a
 *     localização), horas, saída esquecida, correção, lançamento, apagar, planilha e declaração;
 *   - vínculo: termo de adesão do voluntário (PDF, registro, declaração só com o termo) e empregado
 *     só com presença (sem horas, correção, declaração nem termo; presença apagada depois de 90 dias);
 *   - alunos: presença na aula (dados de uma escola falsa, local), comprovante antes e depois do fim
 *     da aula, PDF, conferência do código, rotina de e-mail (api/comparecimentos.php) e cancelamento;
 *   - aparelhos: liberar no portal, usar e desativar;
 *   - proteções: sessão cifrada, localização, limite de consultas, CSRF.
 * As chamadas passam pelo servidor embutido do PHP, como as de um navegador. A "escola" é um segundo
 * servidor embutido que responde a rpc/aulas_do_aluno com os dados deste teste e confere a chave.
 * A função SQL de verdade tem os testes dela em docs/escola/teste-local/04_testes_aulas_pgtap.sql.
 *
 * Precisa de MCP_CONFIG_ARQUIVO com o banco local (DB_HOST 127.0.0.1 ou localhost). CPFs gerados só
 * com o dígito verificador válido; tudo o que o teste cria é apagado no fim. Nenhum e-mail sai.
 *
 * Uso: MCP_CONFIG_ARQUIVO=/caminho/config-teste.php php scripts/testar_ponto_integracao.php
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$configOriginal = (string) getenv('MCP_CONFIG_ARQUIVO');
if ($configOriginal === '' || !is_file($configOriginal)) {
    fwrite(STDERR, "Falta MCP_CONFIG_ARQUIVO com o banco local (veja o cabeçalho deste arquivo).\n");
    exit(2);
}
$portaSite = 18400 + random_int(0, 499);
$portaEscola = $portaSite + 500;
$config = tempnam(sys_get_temp_dir(), 'mcp-ponto-');
file_put_contents($config, '<?php return [\'EMAIL_SECRETARIA\' => \'\', \'PAINEL_EMAILS\' => \'ponto@exemplo.org\', \'SITE_URL\' => \'http://127.0.0.1:' . $portaSite . '\', '
    . '\'ESCOLA_API_URL\' => \'http://127.0.0.1:' . $portaEscola . '/rest/v1/rpc/matricula_rapida\', \'ESCOLA_API_TOKEN\' => \'chave-falsa\'] + (require '
    . var_export($configOriginal, true) . ');');
$semEscola = tempnam(sys_get_temp_dir(), 'mcp-ponto-escola-');
file_put_contents($semEscola, '<?php return [];');
putenv("MCP_CONFIG_ARQUIVO=$config");
putenv("MCP_CONFIG_ESCOLA_ARQUIVO=$semEscola");
putenv('MCP_CATALOGO_ARQUIVO=' . $raiz . '/site/matricula-cursos-presenciais/cursos.json');
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $raiz . '/site/matricula-cursos-presenciais/api/lib.php';
restore_exception_handler();

if (!in_array((string) mcp_cfg('DB_HOST', 'localhost'), ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "DB_HOST não é local. Este teste grava e apaga dados; só roda num banco local.\n");
    exit(2);
}
if ((string) mcp_cfg('RESEND_API_KEY', '') !== '') {
    fwrite(STDERR, "A configuração tem RESEND_API_KEY. Use uma configuração de teste sem chave de e-mail.\n");
    exit(2);
}

$falhas = 0;
$total = 0;
function verificar(string $nome, mixed $obtido, mixed $esperado): void
{
    global $falhas, $total;
    $total++;
    if ($obtido === $esperado) {
        echo "ok   $nome\n";
        return;
    }
    $falhas++;
    fwrite(STDERR, sprintf("FALHOU %s\n  esperado: %s\n  obtido:   %s\n", $nome, var_export($esperado, true), var_export($obtido, true)));
}

/** Valor da chave, null incluído; 'ausente' só quando a chave não existe (?? não distingue os dois). */
function campo(mixed $lista, string $chave): mixed
{
    return is_array($lista) && array_key_exists($chave, $lista) ? $lista[$chave] : 'ausente';
}

function cpf_de_teste(): string
{
    $d = array_map(static fn(): int => random_int(0, 9), range(1, 9));
    for ($n = 9; $n < 11; $n++) {
        $s = 0;
        foreach ($d as $i => $v) {
            $s += $v * ($n + 1 - $i);
        }
        $d[] = ($s * 10) % 11 % 10;
    }
    return implode('', $d);
}

ignore_user_abort(true);
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGPIPE, SIG_IGN);
    foreach ([SIGINT, SIGTERM] as $sinal) {
        pcntl_signal($sinal, static function (): never {
            throw new RuntimeException('Teste interrompido.');
        });
    }
}

$db = mcp_db();
$sufixo = bin2hex(random_bytes(3));
$marca = "Teste Ponto $sufixo";

/** Apaga o que este teste (ou uma execução interrompida) criou: nomes com "Teste Ponto". */
function limpar(PDO $db): void
{
    $ids = $db->query("SELECT id FROM mcp_colaboradores WHERE nome LIKE '%Teste Ponto %'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $lista = implode(', ', array_map('intval', $ids));
        $db->exec("DELETE FROM mcp_ponto WHERE colaborador_id IN ($lista)");
        $db->exec("DELETE FROM mcp_declaracoes_horas WHERE colaborador_id IN ($lista)");
        $db->exec("DELETE FROM mcp_colaboradores WHERE id IN ($lista)");
    }
    $db->exec("DELETE FROM mcp_presencas WHERE nome LIKE '%Teste Ponto %'");
    $db->exec("DELETE FROM mcp_ponto_aparelhos WHERE nome LIKE '%Teste Ponto %'");
    $db->exec("DELETE FROM mcp_eventos WHERE (tipo IN ('ponto_consulta', 'ponto_consulta_falha', 'conferir', 'ponto_rede_sede') AND (detalhe = '127.0.0.1' OR detalhe LIKE 'aparelho %'))
        OR tipo = 'escola_fora' OR (tipo LIKE 'painel_%' AND detalhe LIKE '%ponto@exemplo.org%') OR tipo IN ('ponto_corrigido', 'ponto_lancado', 'ponto_apagado', 'presenca_cancelada', 'ponto_termo')
        AND detalhe LIKE '%ponto@exemplo.org%'");
}
limpar($db);

// ----------------------------------------------------------------------------- dados da escola falsa
$hoje = mcp_ponto_hoje();
$cpfColaborador = cpf_de_teste();
$cpfEmpregado = cpf_de_teste();
$cpfAluna = cpf_de_teste();
$cpfAlunoCelular = cpf_de_teste();
$cpfEscolaFora = cpf_de_teste();
$aula = static fn(string $id, string $horario, string $curso): array => ['aula_id' => "$id-$sufixo", 'data' => $hoje, 'horario' => $horario,
    'turma_id' => 'turma-teste', 'curso_id' => 'curso-teste', 'curso_nome' => $curso, 'carga_horaria' => 80];
$escolaDados = [
    'alunos' => [
        // A aula do dia inteiro sempre está aberta; a de 00:00 a 00:01 já terminou (salvo se o teste rodar nesse minuto).
        $cpfAluna => ['aluno' => ['nome' => "Aluna $marca", 'email' => "aluna-$sufixo@exemplo.org"],
            'aulas' => [$aula('dia', '00:00 - 23:59', 'Bombeiro Civil'), $aula('cedo', '00:00 - 00:01', 'Primeiros Socorros')]],
        $cpfAlunoCelular => ['aluno' => ['nome' => "Aluno Celular $marca", 'email' => "celular-$sufixo@exemplo.org"],
            'aulas' => [$aula('cel', '00:00 - 23:59', 'Punção Venosa')]],
    ],
    'falha' => [$cpfEscolaFora => true],
];
$arquivoDados = tempnam(sys_get_temp_dir(), 'mcp-escola-falsa-');
file_put_contents($arquivoDados, json_encode($escolaDados, JSON_UNESCAPED_UNICODE));
$logEscola = tempnam(sys_get_temp_dir(), 'mcp-escola-falsa-log-');
$roteador = tempnam(sys_get_temp_dir(), 'mcp-escola-falsa-roteador-') . '.php';
file_put_contents($roteador, <<<'PHP'
<?php
// Escola falsa: só rpc/aulas_do_aluno, só com a chave certa. Anota o CPF e a data de cada consulta.
header('Content-Type: application/json');
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/rest/v1/rpc/aulas_do_aluno' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    echo '{"message":"função não existe"}';
    return true;
}
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer chave-falsa' || ($_SERVER['HTTP_APIKEY'] ?? '') !== 'chave-falsa') {
    http_response_code(401);
    echo '{"message":"sem chave"}';
    return true;
}
$dados = json_decode((string) file_get_contents(getenv('ESCOLA_FALSA_DADOS')), true);
$pedido = json_decode((string) file_get_contents('php://input'), true);
$cpf = (string) ($pedido['dados']['cpf'] ?? '');
file_put_contents(getenv('ESCOLA_FALSA_LOG'), json_encode(['cpf' => $cpf, 'data' => $pedido['dados']['data'] ?? null]) . "\n", FILE_APPEND);
if (isset($dados['falha'][$cpf])) {
    http_response_code(500);
    echo '{"code":"XX000","message":"falha simulada"}';
    return true;
}
$aluno = $dados['alunos'][$cpf] ?? null;
echo json_encode($aluno ? ['ok' => true, 'aluno' => $aluno['aluno'], 'aulas' => $aluno['aulas']] : ['ok' => true, 'aluno' => null, 'aulas' => []], JSON_UNESCAPED_UNICODE);
return true;
PHP);

// ----------------------------------------------------------------------------- servidores
$logErros = tempnam(sys_get_temp_dir(), 'mcp-ponto-php-');
$nulo = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
$servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaSite", '-t', $raiz . '/site', '-d', 'sendmail_path=/bin/true',
    '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$logErros"], $nulo, $tubos,
    null, ['MCP_CONFIG_ARQUIVO' => $config, 'MCP_CONFIG_ESCOLA_ARQUIVO' => $semEscola, 'PATH' => (string) getenv('PATH')]);
$escola = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaEscola", $roteador], $nulo, $tubos2,
    null, ['ESCOLA_FALSA_DADOS' => $arquivoDados, 'ESCOLA_FALSA_LOG' => $logEscola, 'PATH' => (string) getenv('PATH')]);
$base = "http://127.0.0.1:$portaSite/matricula-cursos-presenciais/api/";
mcp_painel_sessao_abrir('ponto@exemplo.org');
$sessaoPortal = 'mcp_painel=' . $_COOKIE[MCP_PAINEL_COOKIE];
foreach ([$portaSite, $portaEscola] as $porta) {
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $porta) === false; $i++) {
        usleep(100000);
    }
}

/** [status, cabeçalhos (minúsculos; set-cookie em lista), corpo]. */
function http(string $url, string $metodo = 'GET', ?string $corpo = null, array $cabecalhos = []): array
{
    $recebidos = ['set-cookie' => []];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $cabecalhos, CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $linha) use (&$recebidos): int {
            if (str_contains($linha, ':')) {
                [$nome, $valor] = explode(':', $linha, 2);
                $nome = strtolower(trim($nome));
                if ($nome === 'set-cookie') {
                    $recebidos['set-cookie'][] = trim($valor);
                } else {
                    $recebidos[$nome] = trim($valor);
                }
            }
            return strlen($linha);
        },
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
    }
    $resposta = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $recebidos, $resposta];
}

/** Valor de um cookie devolvido em Set-Cookie ('' se apagado, null se não veio). */
function cookie_de(array $cabecalhos, string $nome): ?string
{
    foreach ($cabecalhos['set-cookie'] as $linha) {
        if (str_starts_with($linha, "$nome=")) {
            return urldecode(explode(';', substr($linha, strlen($nome) + 1), 2)[0]);
        }
    }
    return null;
}

/** POST JSON em api/ponto.php com os cookies dados. [status, dados, cabeçalhos]. */
function ponto(string $base, array $corpo, array $cookies = []): array
{
    $cab = ['Content-Type: application/json'];
    if ($cookies) {
        $cab[] = 'Cookie: ' . implode('; ', $cookies);
    }
    [$st, $h, $r] = http($base . 'ponto.php', 'POST', json_encode($corpo), $cab);
    return [$st, json_decode($r, true) ?? [], $h];
}

/** POST de formulário no portal. [status, cabeçalhos, corpo]. */
function portal(string $base, string $sessao, array $campos): array
{
    return http($base . 'painel.php', 'POST', http_build_query($campos), ['Content-Type: application/x-www-form-urlencoded', "Cookie: $sessao"]);
}

$pertoDaSede = ['lat' => -22.9115, 'lng' => -43.1880, 'precisao' => 20];
try {
    // ------------------------------------------------------------------------- cadastro no portal
    [$st, , $html] = http($base . 'painel.php?v=ponto', 'GET', null, ["Cookie: $sessaoPortal"]);
    verificar('portal: seção ponto com o menu ativo e as abas', [$st, str_contains($html, 'href="painel.php?v=ponto" aria-current="page"><svg'), str_contains($html, '<h1>Colaboradores na sede</h1>'),
        str_contains($html, 'Alunos nas aulas'), str_contains($html, 'Aparelhos e QR code')], [200, true, true, true, true]);
    $csrf = static fn(string $acao, int $id): string => mcp_painel_csrf('ponto@exemplo.org', $acao, $id);
    [$st, , $html] = portal($base, $sessaoPortal, ['acao' => 'colaborador_salvar', 'id' => 0, 't' => $csrf('colaborador_salvar', 0), 'nome' => "Colaborador $marca", 'cpf' => '123.456.789-00', 'ativo' => 1]);
    verificar('cadastro: CPF inválido volta com o erro e o que foi digitado', [$st, str_contains($html, 'CPF inválido. Confira os números.'), str_contains($html, 'value="Colaborador ' . $marca . '"')], [200, true, true]);
    [$st, , $html] = portal($base, $sessaoPortal, ['acao' => 'colaborador_salvar', 'id' => 0, 't' => $csrf('colaborador_salvar', 0), 'nome' => "Colaborador $marca",
        'cpf' => mcp_cpf_formatado($cpfColaborador), 'vinculo' => 'chefe', 'ativo' => 1]);
    verificar('cadastro: sem vínculo válido volta com o erro', [$st, str_contains($html, 'Escolha o vínculo com a instituição.'), str_contains($html, '<div class="campo-erro"><label for="c-vinculo">')], [200, true, true]);
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'colaborador_salvar', 'id' => 0, 't' => $csrf('colaborador_salvar', 0), 'nome' => "Colaborador $marca",
        'cpf' => mcp_cpf_formatado($cpfColaborador), 'email' => "COLAB-$sufixo@Exemplo.org", 'telefone' => '(21) 98888-7777', 'funcao' => 'Socorrista voluntário', 'vinculo' => 'voluntario', 'ativo' => 1]);
    $colaborador = mcp_colaborador_por_cpf($cpfColaborador);
    verificar('cadastro: grava e vai para a ficha', [$st, (bool) preg_match('~^painel\.php\?v=colaborador&id=\d+&ok=col_ok$~', $cab['location'] ?? ''), $colaborador['email'] ?? null, $colaborador['telefone'] ?? null, (int) ($colaborador['ativo'] ?? 0),
        $colaborador['vinculo'] ?? null, campo($colaborador, 'termo_em')], [303, true, "colab-$sufixo@exemplo.org", '21988887777', 1, 'voluntario', null]);
    $colId = (int) $colaborador['id'];
    [$st, , $html] = portal($base, $sessaoPortal, ['acao' => 'colaborador_salvar', 'id' => 0, 't' => $csrf('colaborador_salvar', 0), 'nome' => "Outro $marca", 'cpf' => $cpfColaborador, 'vinculo' => 'voluntario', 'ativo' => 1]);
    verificar('cadastro: CPF de outro colaborador é recusado', str_contains($html, 'Este CPF já está cadastrado para outro colaborador.'), true);
    [$st, , $html] = portal($base, $sessaoPortal, ['acao' => 'colaborador_salvar', 'id' => 0, 't' => 'falso', 'nome' => "Colaborador $marca", 'cpf' => cpf_de_teste(), 'ativo' => 1]);
    verificar('cadastro: sem o token do formulário pede para entrar', str_contains($html, '<h1>Entrar</h1>'), true);

    // ------------------------------------------------------------------------- aparelho da sede
    [$st, , $corpo] = http($base . 'ponto.php');
    verificar('ponto: sem aparelho liberado é modo celular', array_intersect_key(json_decode($corpo, true) ?? [], ['ok' => 1, 'modo' => 1, 'aparelho' => 1, 'lembrado' => 1]),
        ['ok' => true, 'modo' => 'celular', 'aparelho' => null, 'lembrado' => null]);
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'aparelho_ativar', 'id' => 0, 't' => $csrf('aparelho_ativar', 0), 'nome' => "Tablet $marca"]);
    $valorAparelho = cookie_de($cab, 'mcp_ponto_aparelho');
    $aparelho = $db->query("SELECT * FROM mcp_ponto_aparelhos WHERE nome = " . $db->quote("Tablet $marca"))->fetch();
    verificar('aparelho: liberado no portal grava o cookie assinado', [$st, $cab['location'] ?? null, $valorAparelho === $aparelho['id'] . '.' . mcp_ponto_assinar('a|' . $aparelho['id']),
        str_contains(implode(' ', $cab['set-cookie']), 'HttpOnly')], [303, 'painel.php?v=ponto&aba=aparelhos&ok=ap_ok', true, true]);
    $cAparelho = "mcp_ponto_aparelho=$valorAparelho";
    [$st, , $corpo] = http($base . 'ponto.php', 'GET', null, ["Cookie: $cAparelho"]);
    verificar('ponto: com o cookie é modo aparelho, com o nome', array_intersect_key(json_decode($corpo, true) ?? [], ['modo' => 1, 'aparelho' => 1]), ['modo' => 'aparelho', 'aparelho' => "Tablet $marca"]);
    [, , $corpo] = http($base . 'ponto.php', 'GET', null, ['Cookie: mcp_ponto_aparelho=' . $aparelho['id'] . '.' . str_repeat('0', 40)]);
    verificar('ponto: cookie de aparelho falsificado vale como celular', json_decode($corpo, true)['modo'] ?? null, 'celular');

    // ------------------------------------------------------------------------- entrada e saída
    [$st, $d] = ponto($base, ['acao' => 'identificar', 'cpf' => '111.111.111-11'], [$cAparelho]);
    verificar('identificar: CPF inválido', [$st, $d['campo'] ?? null], [422, 'cpf']);
    [$st, $d] = ponto($base, ['acao' => 'identificar', 'cpf' => mcp_cpf_formatado($cpfColaborador)], [$cAparelho]);
    verificar('identificar: colaborador no aparelho, sem aula', [$st, $d['nome'] ?? null, $d['colaborador']['na_sede'] ?? null, $d['aulas'] ?? null, $d['escola_indisponivel'] ?? null],
        [200, 'Colaborador', false, [], false]);
    verificar('identificar: a escola recebeu o CPF e a data de hoje em Brasília', json_decode((string) array_reverse(file($logEscola) ?: [''])[0], true), ['cpf' => $cpfColaborador, 'data' => $hoje]);
    verificar('identificar: a sessão vai cifrada (sem CPF legível)', [str_contains((string) ($d['sessao'] ?? ''), $cpfColaborador), str_contains((string) base64_decode(strtr((string) ($d['sessao'] ?? ''), '-_', '+/')), $cpfColaborador)], [false, false]);
    $sessao = (string) $d['sessao'];
    [$st, $d] = ponto($base, ['acao' => 'entrada', 'sessao' => $sessao]);
    $registro = $db->query("SELECT * FROM mcp_ponto WHERE colaborador_id = $colId ORDER BY id DESC LIMIT 1")->fetch();
    verificar('entrada: registrada pelo aparelho', [$st, $d['registrado'] ?? null, $registro['origem_entrada'] ?? null, (int) ($registro['aparelho_entrada'] ?? 0), campo($registro, 'saida')],
        [200, 'entrada', 'aparelho', (int) $aparelho['id'], null]);
    [$st, $d] = ponto($base, ['acao' => 'entrada', 'sessao' => $sessao]);
    verificar('entrada: de novo com a entrada aberta é recusada', [$st, $d['codigo'] ?? null], [409, 'ja_na_sede']);
    [$st, $d] = ponto($base, ['acao' => 'saida', 'sessao' => $sessao]);
    verificar('saída: logo depois da entrada espera um minuto', [$st, $d['codigo'] ?? null], [409, 'cedo']);
    $db->prepare('UPDATE mcp_ponto SET entrada = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 7200), (int) $registro['id']]);
    [$st, $d] = ponto($base, ['acao' => 'saida', 'sessao' => $sessao]);
    verificar('saída: voluntário vê o agradecimento pelas horas doadas', [str_ends_with((string) ($d['mensagem'] ?? ''), 'Obrigado pelas horas doadas!'), $d['colaborador']['horas'] ?? null, $d['colaborador']['termo_pendente'] ?? null], [true, true, true]);
    verificar('saída: registrada, com as horas desta vez e do mês', [$st, $d['registrado'] ?? null, $d['duracao'] ?? null, $d['colaborador']['na_sede'] ?? null],
        [200, 'saida', '2h00', false]);
    [$st, $d] = ponto($base, ['acao' => 'saida', 'sessao' => substr($sessao, 0, -3) . 'AAA']);
    verificar('sessão adulterada não registra', [$st, $d['motivo'] ?? null], [401, 'sessao']);

    // ------------------------------------------------------------------------- celular
    [$st, $d] = ponto($base, ['acao' => 'identificar', 'cpf' => $cpfColaborador]);
    verificar('celular: sem localização é recusado', [$st, $d['motivo'] ?? null], [403, 'localizacao']);
    [$st, $d] = ponto($base, ['acao' => 'identificar', 'cpf' => $cpfColaborador, 'posicao' => ['lat' => -22.9068, 'lng' => -43.1729, 'precisao' => 15]]);
    verificar('celular: longe da sede é recusado com a distância', [$st, str_starts_with((string) ($d['erro'] ?? ''), 'Você está a 1,6 km da sede.')], [403, true]);
    [$st, $d] = ponto($base, ['acao' => 'identificar', 'cpf' => $cpfColaborador, 'posicao' => ['lat' => -22.9115, 'lng' => -43.1880, 'precisao' => 5000]]);
    verificar('celular: localização imprecisa é recusada', [$st, str_contains((string) ($d['erro'] ?? ''), 'imprecisa')], [403, true]);
    [$st, $d, $cab] = ponto($base, ['acao' => 'identificar', 'cpf' => $cpfColaborador, 'posicao' => $pertoDaSede, 'lembrar' => true]);
    $valorPessoa = cookie_de($cab, 'mcp_ponto_pessoa');
    // No celular, a única prova é o CPF: a tela não mostra horas do mês, horário de entrada nem termo pendente.
    verificar('celular: na sede identifica e lembra o CPF cifrado, sem horas, horário nem termo na resposta', [$st, $d['colaborador']['hoje'] ?? null, $d['colaborador']['mes'] ?? null,
        $d['colaborador']['desde'] ?? null, $d['colaborador']['termo_pendente'] ?? null, $d['colaborador']['horas'] ?? null, $valorPessoa !== null && $valorPessoa !== '' && !str_contains($valorPessoa, $cpfColaborador)],
        [200, null, null, null, false, true, true]);
    [$st, $d] = ponto($base, ['acao' => 'entrada', 'sessao' => (string) $d['sessao']]);
    $registro = $db->query("SELECT * FROM mcp_ponto WHERE colaborador_id = $colId ORDER BY id DESC LIMIT 1")->fetch();
    verificar('celular: entrada com a distância e sem aparelho', [$st, $registro['origem_entrada'], $registro['aparelho_entrada'], (int) $registro['distancia_entrada'] < 60], [200, 'celular', null, true]);
    $cPessoa = "mcp_ponto_pessoa=" . rawurlencode((string) $valorPessoa);
    [, , $corpo] = http($base . 'ponto.php', 'GET', null, ["Cookie: $cPessoa"]);
    verificar('celular: lembra da pessoa com o CPF mascarado', json_decode($corpo, true)['lembrado'] ?? null, mcp_cpf_mascarado($cpfColaborador));
    [$st, $d] = ponto($base, ['acao' => 'identificar', 'posicao' => $pertoDaSede], [$cPessoa]);
    verificar('celular: pessoa lembrada entra sem digitar o CPF, e já está na sede', [$st, $d['colaborador']['na_sede'] ?? null], [200, true]);
    // "Estou saindo agora" pelo celular numa entrada de outro dia: vira saída informada, que a secretaria confere
    // (de longe, com o CPF de outra pessoa, não se lançam horas). Entrada de hoje não entra nessa regra.
    $colNoite = mcp_colaborador_salvar(null, ['nome' => "Noite Voluntária $marca", 'cpf' => cpf_de_teste(), 'email' => null, 'telefone' => null, 'funcao' => null, 'vinculo' => 'voluntario', 'ativo' => 1], 'teste');
    $ontemNoite = mcp_avisos_dia_mais($hoje, -1);
    $db->prepare("INSERT INTO mcp_ponto (colaborador_id, voluntario, entrada, origem_entrada, criado_em, atualizado_em) VALUES (?, 1, ?, 'celular', ?, ?)")
        ->execute([$colNoite, mcp_ponto_local_para_utc("$ontemNoite 22:00:00"), mcp_agora(), mcp_agora()]);
    $idNoite = (int) $db->lastInsertId();
    $agoraNoite = (int) strtotime(mcp_ponto_local_para_utc("$hoje 06:30:00") . ' UTC');
    $saidaNoite = mcp_ponto_saida_outro_dia(mcp_colaborador_por_id($colNoite), $agoraNoite);
    $registroNoite = mcp_ponto_registro($idNoite);
    verificar('celular: saída de uma entrada de outro dia vira saída informada (a secretaria confere); entrada de hoje não',
        [$saidaNoite, $registroNoite['saida'], mcp_data_brt((string) $registroNoite['saida_informada'], 'Y-m-d H:i'), mcp_ponto_saida_outro_dia(mcp_colaborador_por_id($colId), time())],
        [['hora' => '06:30'], null, "$hoje 06:30", null]);
    [$st, , $cab] = ponto($base, ['acao' => 'esquecer'], [$cPessoa]);
    // O PHP apaga o cookie mandando o valor "deleted" com validade no passado.
    verificar('celular: esquecer apaga o cookie', [$st, cookie_de($cab, 'mcp_ponto_pessoa'), str_contains(implode(' ', $cab['set-cookie']), 'expires=Thu, 01 Jan 1970')], [200, 'deleted', true]);
    [$st, $d] = ponto($base, ['acao' => 'identificar', 'posicao' => $pertoDaSede]);
    verificar('celular: sem CPF e sem pessoa lembrada', [$st, $d['campo'] ?? null], [422, 'cpf']);

    // ------------------------------------------------------------------------- presença do aluno
    [$st, $d] = ponto($base, ['acao' => 'identificar', 'cpf' => $cpfAluna], [$cAparelho]);
    $aulasPorId = array_column($d['aulas'] ?? [], null, 'id');
    verificar('aluna: aulas de hoje vindas da escola', [$st, $d['nome'] ?? null, campo($d, 'colaborador'), count($d['aulas'] ?? []),
        $aulasPorId["dia-$sufixo"]['pode'] ?? null, $aulasPorId["dia-$sufixo"]['horario'] ?? null, $aulasPorId["dia-$sufixo"]['disponivel_em'] ?? null],
        [200, 'Aluna', null, 2, true, 'das 00:00 às 23:59', '23:59']);
    verificar('aluna: aula que já terminou não abre', [$aulasPorId["cedo-$sufixo"]['pode'] ?? null, $aulasPorId["cedo-$sufixo"]['motivo'] ?? null],
        [false, 'Esta aula terminou às 00:01. Se você veio, fale com a secretaria.']);
    $sessaoAluna = (string) $d['sessao'];
    [$st, $d] = ponto($base, ['acao' => 'presenca', 'sessao' => $sessaoAluna, 'aula' => "dia-$sufixo"]);
    verificar('presença: registrada no aparelho, sem link pessoal na tela', [$st, $d['nova'] ?? null, $d['curso'] ?? null, campo($d, 'link'), $d['email'] ?? null, str_starts_with((string) ($d['mensagem'] ?? ''), 'Presença registrada às ')],
        [200, true, 'Bombeiro Civil', 'ausente', 'a***@exemplo.org', true]);
    [$st, $d] = ponto($base, ['acao' => 'presenca', 'sessao' => $sessaoAluna, 'aula' => "dia-$sufixo"]);
    verificar('presença: de novo não duplica', [$st, $d['nova'] ?? null, (int) $db->query('SELECT COUNT(*) FROM mcp_presencas WHERE cpf = ' . $db->quote($cpfAluna))->fetchColumn()], [200, false, 1]);
    [$st, $d] = ponto($base, ['acao' => 'presenca', 'sessao' => $sessaoAluna, 'aula' => "cedo-$sufixo"]);
    verificar('presença: aula que terminou é recusada', [$st, $d['erro'] ?? null], [409, 'Esta aula terminou às 00:01. Se você veio, fale com a secretaria.']);
    [$st, $d] = ponto($base, ['acao' => 'presenca', 'sessao' => $sessaoAluna, 'aula' => 'aula-de-outra-pessoa']);
    verificar('presença: aula fora da sessão é recusada', $st, 404);
    $presenca = mcp_presenca_por('token', (string) $db->query('SELECT token FROM mcp_presencas WHERE cpf = ' . $db->quote($cpfAluna))->fetchColumn());
    verificar('presença: cópia dos dados da escola e o fim da aula', [$presenca['nome'], $presenca['email'], $presenca['curso_nome'], $presenca['aula_data'], $presenca['horario'], $presenca['origem'], $presenca['status'],
        $presenca['fim'] === mcp_ponto_local_para_utc("$hoje 23:59:00")], ["Aluna $marca", "aluna-$sufixo@exemplo.org", 'Bombeiro Civil', $hoje, '00:00 - 23:59', 'aparelho', 'valida', true]);

    // Comprovante antes do fim da aula: só quando fica pronto.
    $t = (string) $presenca['token'];
    [$st, , $corpo] = http($base . 'comparecimento.php?t=' . $t);
    $pub = json_decode($corpo, true) ?? [];
    verificar('comprovante antes do fim: ainda não disponível', [$st, $pub['disponivel'] ?? null, campo($pub, 'pdf'), campo($pub, 'codigo'), $pub['nome'] ?? null], [200, false, null, null, mcp_nome_proprio("Aluna $marca")]);
    verificar('comprovante antes do fim: sem PDF', http($base . 'comparecimento.php?t=' . $t . '&pdf=1')[0], 403);
    verificar('comprovante: código ainda não confere', http($base . 'conferir.php?c=' . $presenca['codigo'])[0], 404);
    // A aula "termina": o fim vai para um minuto atrás.
    $db->prepare('UPDATE mcp_presencas SET fim = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 60), (int) $presenca['id']]);
    [$st, , $corpo] = http($base . 'comparecimento.php?t=' . $t);
    $pub = json_decode($corpo, true) ?? [];
    verificar('comprovante depois do fim: PDF e código', [$st, $pub['disponivel'] ?? null, str_contains((string) ($pub['pdf'] ?? ''), '&pdf=1'), $pub['codigo'] ?? null],
        [200, true, true, mcp_codigo_formatado((string) $presenca['codigo'])]);
    [$st, $cab, $pdf] = http($base . 'comparecimento.php?t=' . $t . '&pdf=1');
    verificar('comprovante: baixa o PDF com nome de arquivo', [$st, $cab['content-type'] ?? null, str_starts_with($pdf, '%PDF-'),
        $cab['content-disposition'] ?? null], [200, 'application/pdf', true, 'attachment; filename="Comprovante_de_Comparecimento_Bombeiro_Civil_' . $hoje . '.pdf"']);
    verificar('comprovante: link errado', [http($base . 'comparecimento.php?t=' . str_repeat('0', 40))[0], http($base . 'comparecimento.php?t=abc')[0]], [404, 404]);
    [$st, , $corpo] = http($base . 'conferir.php?c=' . strtolower(mcp_codigo_formatado((string) $presenca['codigo'])));
    $conf = json_decode($corpo, true) ?? [];
    verificar('conferir: código em minúsculas e com hífen', [$st, $conf['tipo'] ?? null, $conf['valido'] ?? null, $conf['cpf'] ?? null, $conf['linhas'][0][1] ?? null],
        [200, 'Comprovante de comparecimento', true, mcp_cpf_mascarado($cpfAluna), 'Bombeiro Civil']);
    verificar('conferir: sem CPF completo na resposta', str_contains($corpo, $cpfAluna), false);
    verificar('conferir: código que não existe e código mal escrito', [http($base . 'conferir.php?c=ZZZZ2222')[0], http($base . 'conferir.php?c=123')[0]], [404, 422]);

    // Rotina do cron: manda o comprovante uma vez só.
    $rotina = static function () use ($raiz, $config, $semEscola): array {
        $saida = [];
        exec('MCP_CONFIG_ARQUIVO=' . escapeshellarg($config) . ' MCP_CONFIG_ESCOLA_ARQUIVO=' . escapeshellarg($semEscola) . ' ' . escapeshellarg(PHP_BINARY)
            . ' -d sendmail_path=/bin/true ' . escapeshellarg($raiz . '/site/matricula-cursos-presenciais/api/comparecimentos.php') . ' 2>&1', $saida, $codigo);
        return [$codigo, implode("\n", $saida)];
    };
    [$codigo, $saida] = $rotina();
    $presenca = mcp_presenca_por('id', (string) $presenca['id']);
    verificar('rotina: manda o comprovante da aula que terminou', [$codigo, (bool) preg_match('/comparecimentos: [1-9]\d* enviados, 0 falhas/', $saida), $presenca['email_status'], $presenca['email_em'] !== null, (int) $presenca['email_tentativas']],
        [0, true, 'mail', true, 1]);
    $rotina();
    verificar('rotina: não manda de novo', (int) mcp_presenca_por('id', (string) $presenca['id'])['email_tentativas'], 1);
    verificar('rotina: por HTTP não roda', http($base . 'comparecimentos.php')[0], 404);
    $email = mcp_montar_email_comparecimento($presenca);
    verificar('e-mail do comprovante: assunto, link e código', [$email['assunto'], str_contains($email['html'], mcp_presenca_link($presenca)), str_contains($email['texto'], mcp_codigo_formatado((string) $presenca['codigo']))],
        ['Seu comprovante de comparecimento: Bombeiro Civil, ' . mcp_escola_data($hoje), true, true]);

    // Presença pelo celular: o comprovante vai por e-mail; a tela não mostra o link pessoal (quem sabe o CPF de
    // outra pessoa não chega ao nome completo dela nem ao PDF).
    [$st, $d] = ponto($base, ['acao' => 'identificar', 'cpf' => $cpfAlunoCelular, 'posicao' => $pertoDaSede]);
    [$st, $d] = ponto($base, ['acao' => 'presenca', 'sessao' => (string) $d['sessao'], 'aula' => "cel-$sufixo"]);
    verificar('presença pelo celular: sem o link do comprovante na tela (vai por e-mail)', [$st, campo($d, 'link'), str_starts_with((string) ($d['mensagem'] ?? ''), 'Presença registrada às ')],
        [200, 'ausente', true]);
    verificar('presença pelo celular: origem e distância', $db->query('SELECT origem, distancia < 60 AS perto FROM mcp_presencas WHERE cpf = ' . $db->quote($cpfAlunoCelular))->fetch(),
        ['origem' => 'celular', 'perto' => 1]);

    // Escola fora do ar e CPF desconhecido.
    [$st, $d] = ponto($base, ['acao' => 'identificar', 'cpf' => $cpfEscolaFora], [$cAparelho]);
    verificar('escola fora do ar: avisa, sem inventar', [$st, $d['erro'] ?? null], [503, 'Não conseguimos consultar as aulas na escola agora. Tente de novo em instantes ou fale com a secretaria.']);
    // Disjuntor: com a escola falhando há menos de 2 minutos, o colaborador entra sem esperar por ela.
    $consultasAntes = count(file($logEscola));
    [$st, $d] = ponto($base, ['acao' => 'identificar', 'cpf' => $cpfColaborador], [$cAparelho]);
    verificar('escola fora do ar há pouco: o colaborador entra sem esperar por ela', [$st, $d['escola_indisponivel'] ?? null, count(file($logEscola)) - $consultasAntes], [200, true, 0]);
    $db->exec("DELETE FROM mcp_eventos WHERE tipo = 'escola_fora'");
    [$st, $d] = ponto($base, ['acao' => 'identificar', 'cpf' => cpf_de_teste()], [$cAparelho]);
    verificar('CPF sem cadastro e sem aula', [$st, str_starts_with((string) ($d['erro'] ?? ''), 'Não encontramos este CPF')], [404, true]);

    // ------------------------------------------------------------------------- portal: alunos, cancelamento
    [$st, , $html] = http($base . 'painel.php?v=ponto&aba=alunos', 'GET', null, ["Cookie: $sessaoPortal"]);
    // O portal mostra o nome como nome próprio ("... Ad056a"), igual ao comprovante.
    verificar('portal: presenças do dia com a situação do comprovante', [$st, str_contains($html, mcp_nome_proprio("Aluna $marca")), str_contains($html, '<span class="selo ok">Enviado às'),
        str_contains($html, mcp_nome_proprio("Aluno Celular $marca")), str_contains($html, 'Sai às 23:59')], [200, true, true, true, true]);
    $pid = (int) $presenca['id'];
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'presenca_cancelar', 'id' => $pid, 't' => $csrf('presenca_cancelar', $pid)]);
    verificar('portal: cancela a presença', [$st, $cab['location'] ?? null, mcp_presenca_por('id', (string) $pid)['status']], [303, "painel.php?v=ponto&aba=alunos&data=$hoje&ok=pr_canc", 'cancelada']);
    [$st, , $corpo] = http($base . 'conferir.php?c=' . $presenca['codigo']);
    $conf = json_decode($corpo, true) ?? [];
    verificar('conferir: presença cancelada aparece como cancelada', [$st, $conf['valido'] ?? null, str_starts_with((string) ($conf['situacao'] ?? ''), 'Documento cancelado pela secretaria em ')], [200, false, true]);
    verificar('comprovante cancelado: sem PDF', http($base . 'comparecimento.php?t=' . $t . '&pdf=1')[0], 410);
    [$st, $d] = ponto($base, ['acao' => 'presenca', 'sessao' => $sessaoAluna, 'aula' => "dia-$sufixo"]);
    verificar('presença cancelada: o aluno não reativa sozinho', [$st, $d['erro'] ?? null], [409, 'A presença nesta aula foi cancelada pela secretaria. Fale com ela.']);

    // ------------------------------------------------------------------------- portal: horas, correções, declaração
    [$st, , $html] = http($base . 'painel.php?v=ponto', 'GET', null, ["Cookie: $sessaoPortal"]);
    verificar('portal: colaborador na sede e horas do mês', [str_contains($html, "Colaborador $marca"), str_contains($html, '<span class="selo ok">Na sede desde'),
        str_contains($html, '<b>' . mcp_ponto_resumo(mcp_colaborador_por_id($colId))['mes'] . '</b>')], [true, true, true]);
    // Lista de emergência: quem está na sede agora (colaboradores com entrada aberta e alunos em aula), sem CPF.
    [$st, , $html] = http($base . 'painel.php?v=ponto&aba=emergencia', 'GET', null, ["Cookie: $sessaoPortal"]);
    $emergencia = mcp_ponto_emergencia();
    verificar('lista de emergência: colaborador na sede e aluno em aula, sem CPF, pronta para imprimir', [$st, str_contains($html, '<h1>Lista de emergência</h1>'),
        str_contains($html, mcp_escapar("Colaborador $marca")), str_contains($html, mcp_escapar(mcp_nome_proprio("Aluno Celular $marca"))), str_contains($html, 'window.print()'),
        str_contains($html, $cpfColaborador) || str_contains($html, $cpfAlunoCelular), in_array("Colaborador $marca", array_column($emergencia['colaboradores'], 'nome'), true),
        in_array(mcp_nome_proprio("Aluna $marca"), array_map('mcp_nome_proprio', array_column($emergencia['alunos'], 'nome')), true)],
        [200, true, true, true, true, false, true, false]);
    [$st, , $html] = http($base . 'painel.php', 'GET', null, ["Cookie: $sessaoPortal"]);
    verificar('início: quem está na sede', (bool) preg_match('~\d+ colaborador(es estão| está) na sede agora~', $html), true);
    [$st, $cab, $csv] = http($base . 'painel.php?v=ponto&csv=1', 'GET', null, ["Cookie: $sessaoPortal"]);
    verificar('planilha do ponto: tipo, linhas do colaborador e sem CPF', [$st, $cab['content-type'] ?? null, str_starts_with($csv, "\xEF\xBB\xBF"), substr_count($csv, "Colaborador $marca"), str_contains($csv, $cpfColaborador)],
        [200, 'text/csv; charset=utf-8', true, 2, false]);

    $aberto = mcp_ponto_aberto($colId);
    $rid = (int) $aberto['id'];
    $entradaLocal = mcp_data_brt((string) $aberto['entrada'], 'Y-m-d\TH:i');
    [$st, , $html] = portal($base, $sessaoPortal, ['acao' => 'ponto_corrigir', 'id' => $rid, 't' => $csrf('ponto_corrigir', $rid), 'entrada' => $entradaLocal,
        'saida' => mcp_data_brt(gmdate('Y-m-d H:i:s', strtotime((string) $aberto['entrada'] . ' UTC') - 600), 'Y-m-d\TH:i'), 'motivo' => 'teste']);
    verificar('corrigir: saída antes da entrada é recusada', str_contains($html, 'A saída precisa ser depois da entrada.'), true);
    [$st, , $html] = portal($base, $sessaoPortal, ['acao' => 'ponto_corrigir', 'id' => $rid, 't' => $csrf('ponto_corrigir', $rid), 'entrada' => $entradaLocal, 'saida' => '', 'motivo' => '']);
    verificar('corrigir: sem motivo é recusado', str_contains($html, 'Escreva o motivo.'), true);
    // A entrada vai para 3 horas atrás e a saída para 1 hora atrás. Antes, o primeiro registro (de 2 h atrás até agora)
    // vai para 50 horas atrás: não cruza a correção nem o turno de ontem lançado logo depois.
    $db->prepare('UPDATE mcp_ponto SET entrada = ?, saida = ? WHERE colaborador_id = ? AND id <> ?')->execute([gmdate('Y-m-d H:i:s', time() - 50 * 3600), gmdate('Y-m-d H:i:s', time() - 49 * 3600), $colId, $rid]);
    $novaEntrada = mcp_data_brt(gmdate('Y-m-d H:i:s', time() - 3 * 3600), 'Y-m-d\TH:i');
    $novaSaida = mcp_data_brt(gmdate('Y-m-d H:i:s', time() - 3600), 'Y-m-d\TH:i');
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'ponto_corrigir', 'id' => $rid, 't' => $csrf('ponto_corrigir', $rid), 'entrada' => $novaEntrada, 'saida' => $novaSaida, 'motivo' => 'esqueceu a saída']);
    $corrigido = mcp_ponto_registro($rid);
    verificar('corrigir: grava, anota quem e por quê, e marca a origem', [$st, str_ends_with($cab['location'] ?? '', '&ok=pt_corr'), mcp_data_brt((string) $corrigido['saida'], 'Y-m-d\TH:i'),
        $corrigido['origem_entrada'], $corrigido['origem_saida'], str_contains((string) $corrigido['ajuste'], 'ponto@exemplo.org · corrigiu'), str_contains((string) $corrigido['ajuste'], 'esqueceu a saída')],
        [303, true, $novaSaida, 'portal', 'portal', true, true]);
    $ontem = (new DateTimeImmutable($hoje))->modify('-1 day')->format('Y-m-d');
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'ponto_lancar', 'id' => $colId, 't' => $csrf('ponto_lancar', $colId), 'data' => $ontem, 'entrada' => '09:00', 'saida' => '12:30', 'motivo' => 'evento externo']);
    $lancado = $db->query("SELECT * FROM mcp_ponto WHERE colaborador_id = $colId AND origem_entrada = 'portal' AND ajuste LIKE '%lançou à mão%'")->fetch();
    verificar('lançar: turno de ontem à mão', [$st, str_ends_with($cab['location'] ?? '', '&ok=pt_lanc'), mcp_data_brt((string) $lancado['entrada'], 'Y-m-d H:i'), mcp_data_brt((string) $lancado['saida'], 'H:i')],
        [303, true, "$ontem 09:00", '12:30']);
    [$st, , $html] = portal($base, $sessaoPortal, ['acao' => 'ponto_lancar', 'id' => $colId, 't' => $csrf('ponto_lancar', $colId), 'data' => $ontem, 'entrada' => '10:00', 'saida' => '11:00', 'motivo' => 'duplicado']);
    verificar('lançar: horário que cruza outro registro é recusado', str_contains($html, 'Esse horário cruza outro registro desta pessoa.'), true);
    [$st, , $html] = portal($base, $sessaoPortal, ['acao' => 'ponto_lancar', 'id' => $colId, 't' => $csrf('ponto_lancar', $colId), 'data' => $ontem, 'entrada' => '14:00', 'saida' => '13:00', 'motivo' => 'invertido']);
    verificar('lançar: saída antes da entrada é recusada', str_contains($html, 'A saída precisa ser depois da entrada.'), true);
    $lid = (int) $lancado['id'];
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'ponto_apagar', 'id' => $lid, 't' => $csrf('ponto_apagar', $lid), 'motivo' => 'lançado por engano']);
    verificar('apagar: some da lista e fica no registro de eventos', [$st, str_ends_with($cab['location'] ?? '', '&ok=pt_apag'), mcp_ponto_registro($lid),
        (int) $db->query("SELECT COUNT(*) FROM mcp_eventos WHERE tipo = 'ponto_apagado' AND detalhe LIKE '#$lid · %lançado por engano'")->fetchColumn()], [303, true, null, 1]);

    // Saída esquecida: entrada aberta há 20 horas (e nenhuma outra aberta).
    $db->prepare("INSERT INTO mcp_ponto (colaborador_id, entrada, origem_entrada, criado_em, atualizado_em) VALUES (?, ?, 'aparelho', ?, ?)")
        ->execute([$colId, gmdate('Y-m-d H:i:s', time() - 20 * 3600), mcp_agora(), mcp_agora()]);
    [, , $html] = http($base . 'painel.php?v=ponto', 'GET', null, ["Cookie: $sessaoPortal"]);
    verificar('saída esquecida: número no menu e selo na lista', [(bool) preg_match('~<span>Ponto da sede</span><span class="badge" title="[1-9]\d* pendências \(saídas esquecidas, saídas informadas para conferir e termos de adesão\)">~', $html), str_contains($html, 'saída esquecida')], [true, true]);
    verificar('termo pendente: número no quadro e selo na lista', [(bool) preg_match('~<b>[1-9]\d*</b><span>Termos pendentes</span>~', $html), str_contains($html, '<span class="selo alerta">Termo pendente</span>')], [true, true]);
    [$st, $d] = ponto($base, ['acao' => 'identificar', 'cpf' => $cpfColaborador], [$cAparelho]);
    verificar('saída esquecida: a pessoa não conta como na sede e pode entrar de novo', [$d['colaborador']['na_sede'] ?? null, ponto($base, ['acao' => 'entrada', 'sessao' => (string) $d['sessao']])[0]], [false, 200]);

    // Ficha, termo de adesão e declaração de horas.
    [$st, , $html] = http($base . 'painel.php?v=colaborador&id=' . $colId, 'GET', null, ["Cookie: $sessaoPortal"]);
    verificar('ficha: horas, registros, correção e o termo pendente', [$st, str_contains($html, '<h1>Colaborador ' . $marca . '</h1>'), str_contains($html, '<summary>Corrigir</summary>'),
        str_contains($html, '<summary>Lançar horas à mão</summary>'), str_contains($html, 'Declaração: registre o termo antes'), str_contains($html, '<span class="selo alerta">Pendente</span>'),
        str_contains($html, 'Imprimir o termo para assinar (PDF)')], [200, true, true, true, true, true, true]);
    [$st, , $html] = http($base . 'painel.php?v=colaborador&id=' . $colId . '&declaracao=1', 'GET', null, ["Cookie: $sessaoPortal"]);
    verificar('declaração: sem o termo registrado não sai', [$st, str_contains($html, 'Registre o termo de adesão assinado antes de emitir a declaração de horas.'),
        (int) $db->query("SELECT COUNT(*) FROM mcp_declaracoes_horas WHERE colaborador_id = $colId")->fetchColumn()], [200, true, 0]);
    [$st, $cab, $pdf] = http($base . 'painel.php?v=colaborador&id=' . $colId . '&termo=1', 'GET', null, ["Cookie: $sessaoPortal"]);
    verificar('termo: PDF de duas páginas para imprimir, com o nome no arquivo', [$st, $cab['content-type'] ?? null, str_starts_with($pdf, '%PDF-'), substr_count($pdf, '/Type /Page '),
        str_contains($cab['content-disposition'] ?? '', 'Termo_de_Adesao_Voluntario_Colaborador_Teste_Ponto_')], [200, 'application/pdf', true, 2, true]);
    [$st, , $html] = portal($base, $sessaoPortal, ['acao' => 'termo_registrar', 'id' => $colId, 't' => $csrf('termo_registrar', $colId), 'data' => (new DateTimeImmutable($hoje))->modify('+1 day')->format('Y-m-d')]);
    verificar('termo: data no futuro é recusada', [$st, str_contains($html, 'A data da assinatura não pode ser no futuro.'), campo(mcp_colaborador_por_id($colId), 'termo_em')], [200, true, null]);
    [$st, , $html] = portal($base, $sessaoPortal, ['acao' => 'termo_registrar', 'id' => $colId, 't' => 'falso', 'data' => $hoje]);
    verificar('termo: sem o token do formulário não registra', [str_contains($html, '<h1>Entrar</h1>'), campo(mcp_colaborador_por_id($colId), 'termo_em')], [true, null]);
    $diaTermo = (new DateTimeImmutable($hoje))->modify('-3 days')->format('Y-m-d');
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'termo_registrar', 'id' => $colId, 't' => $csrf('termo_registrar', $colId), 'data' => $diaTermo]);
    $colaborador = mcp_colaborador_por_id($colId);
    verificar('termo: registrado com a data, o modelo e quem registrou', [$st, $cab['location'] ?? null, $colaborador['termo_em'], $colaborador['termo_modelo'], $colaborador['termo_registrado_por'],
        (int) $db->query("SELECT COUNT(*) FROM mcp_eventos WHERE tipo = 'ponto_termo' AND detalhe LIKE '#$colId · ponto@exemplo.org · sem termo → $diaTermo'")->fetchColumn()],
        [303, "painel.php?v=colaborador&id=$colId&ok=tm_ok#termo", $diaTermo, MCP_PONTO_TERMO_MODELO, 'ponto@exemplo.org', 1]);
    [$st, , $html] = http($base . 'painel.php?v=colaborador&id=' . $colId . '&ok=tm_ok', 'GET', null, ["Cookie: $sessaoPortal"]);
    verificar('ficha: termo assinado e declaração liberada', [str_contains($html, 'Termo de adesão registrado.'), str_contains($html, '<span class="selo ok">Assinado em ' . mcp_escola_data($diaTermo) . '</span>'),
        str_contains($html, 'Declaração de horas (PDF)'), str_contains($html, 'Termo pendente')], [true, true, true, false]);
    [$st, $cab, $pdf] = http($base . 'painel.php?v=colaborador&id=' . $colId . '&declaracao=1', 'GET', null, ["Cookie: $sessaoPortal"]);
    $declaracao = $db->query("SELECT * FROM mcp_declaracoes_horas WHERE colaborador_id = $colId ORDER BY id DESC LIMIT 1")->fetch();
    verificar('declaração: PDF e registro com código', [$st, $cab['content-type'] ?? null, str_starts_with($pdf, '%PDF-'), (bool) $declaracao, strlen((string) ($declaracao['codigo'] ?? ''))],
        [200, 'application/pdf', true, true, 8]);
    verificar('declaração: cita a lei e o termo de adesão', [$declaracao['termo_em'] ?? null, str_contains(mcp_ponto_declaracao_conteudo($declaracao)['texto'],
        'nos termos da Lei nº 9.608/1998 e do termo de adesão assinado em ' . mcp_escola_data($diaTermo) . ', sem vínculo empregatício.')], [$diaTermo, true]);
    http($base . 'painel.php?v=colaborador&id=' . $colId . '&declaracao=1', 'GET', null, ["Cookie: $sessaoPortal"]);
    verificar('declaração: baixar de novo sem mudança reaproveita o código', (int) $db->query("SELECT COUNT(*) FROM mcp_declaracoes_horas WHERE colaborador_id = $colId")->fetchColumn(), 1);
    [$st, , $corpo] = http($base . 'conferir.php?c=' . $declaracao['codigo']);
    $conf = json_decode($corpo, true) ?? [];
    verificar('conferir: declaração de horas, com o termo de adesão', [$st, $conf['tipo'] ?? null, $conf['valido'] ?? null, $conf['nome'] ?? null, in_array(['Termo de adesão', 'assinado em ' . mcp_escola_data($diaTermo) . ' (Lei nº 9.608/1998)'], $conf['linhas'] ?? [], true)],
        [200, 'Declaração de horas voluntárias', true, mcp_nome_proprio("Colaborador $marca"), true]);

    // ------------------------------------------------------------------------- empregado: só presença
    [$st] = portal($base, $sessaoPortal, ['acao' => 'colaborador_salvar', 'id' => 0, 't' => $csrf('colaborador_salvar', 0), 'nome' => "Empregado $marca",
        'cpf' => $cpfEmpregado, 'funcao' => 'Limpeza', 'vinculo' => 'empregado', 'ativo' => 1]);
    $empregado = mcp_colaborador_por_cpf($cpfEmpregado);
    $empId = (int) $empregado['id'];
    [$st, $d] = ponto($base, ['acao' => 'identificar', 'cpf' => $cpfEmpregado], [$cAparelho]);
    verificar('empregado: identificado sem horas nem termo', [$st, $d['colaborador']['horas'] ?? null, campo($d['colaborador'] ?? null, 'hoje'), campo($d['colaborador'] ?? null, 'mes'), $d['colaborador']['termo_pendente'] ?? null],
        [200, false, null, null, false]);
    [$st, $d] = ponto($base, ['acao' => 'entrada', 'sessao' => (string) $d['sessao']]);
    $presenca = mcp_ponto_aberto($empId);
    verificar('empregado: a entrada fica gravada como presença', [$st, (int) ($presenca['voluntario'] ?? 9)], [200, 0]);
    $db->prepare('UPDATE mcp_ponto SET entrada = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 2 * 3600), (int) $presenca['id']]);
    [$st, $d] = ponto($base, ['acao' => 'identificar', 'cpf' => $cpfEmpregado], [$cAparelho]);
    [$st, $d] = ponto($base, ['acao' => 'saida', 'sessao' => (string) $d['sessao']]);
    verificar('empregado: saída sem horas e sem "horas doadas"', [$st, str_ends_with((string) ($d['mensagem'] ?? ''), 'Até a próxima!'), campo($d, 'duracao'), $d['colaborador']['horas'] ?? null],
        [200, true, null, false]);
    verificar('empregado: presença não soma horas doadas', mcp_ponto_minutos($empId, gmdate('Y-m-d H:i:s', time() - 86400), gmdate('Y-m-d H:i:s', time() + 60)), 0);
    [$st, , $html] = http($base . 'painel.php?v=ponto', 'GET', null, ["Cookie: $sessaoPortal"]);
    $posPresenca = strpos($html, 'Empregados, terceirizados e outros');
    verificar('portal: empregado na lista de presença, fora das horas doadas', [$posPresenca !== false, $posPresenca !== false && strpos($html, "Empregado $marca") > $posPresenca,
        str_contains($html, 'não é o ponto oficial dos empregados')], [true, true, true]);
    [, , $csv] = http($base . 'painel.php?v=ponto&csv=1', 'GET', null, ["Cookie: $sessaoPortal"]);
    verificar('planilha das horas: sem o empregado e com o vínculo', [str_contains($csv, "Empregado $marca"), str_contains($csv, 'Vínculo'), str_contains($csv, "\"Colaborador $marca\";Voluntário;")], [false, true, true]);
    [$st, , $html] = http($base . 'painel.php?v=colaborador&id=' . $empId, 'GET', null, ["Cookie: $sessaoPortal"]);
    verificar('ficha do empregado: só presença, sem horas, correção, declaração nem termo', [$st, str_contains($html, '<h2>Presença na sede em '), str_contains($html, '<summary>Corrigir</summary>'),
        str_contains($html, 'Lançar horas à mão'), str_contains($html, 'Declaração de horas'), str_contains($html, 'Termo de adesão <small>'), str_contains($html, 'Empregado (CLT) · Limpeza')],
        [200, true, false, false, false, false, true]);
    [, , $html] = http($base . 'painel.php?v=colaborador&id=' . $empId . '&declaracao=1', 'GET', null, ["Cookie: $sessaoPortal"]);
    verificar('empregado: declaração de horas recusada', str_contains($html, 'A declaração de horas é só para voluntários e diretoria.'), true);
    [, , $html] = http($base . 'painel.php?v=colaborador&id=' . $empId . '&termo=1', 'GET', null, ["Cookie: $sessaoPortal"]);
    verificar('empregado: termo de adesão recusado', str_contains($html, 'O termo de adesão é só para voluntários e diretoria.'), true);
    [, , $html] = portal($base, $sessaoPortal, ['acao' => 'termo_registrar', 'id' => $empId, 't' => $csrf('termo_registrar', $empId), 'data' => $hoje]);
    verificar('empregado: registrar termo recusado', [str_contains($html, 'O termo de adesão é só para voluntários e diretoria.'), campo(mcp_colaborador_por_id($empId), 'termo_em')], [true, null]);
    $pid = (int) $presenca['id'];
    [, , $html] = portal($base, $sessaoPortal, ['acao' => 'ponto_corrigir', 'id' => $pid, 't' => $csrf('ponto_corrigir', $pid), 'entrada' => mcp_data_brt(mcp_agora(), 'Y-m-d\TH:i'), 'saida' => '', 'motivo' => 'teste']);
    verificar('empregado: presença não tem correção', str_contains($html, 'Registro de presença não tem correção'), true);
    [, , $html] = portal($base, $sessaoPortal, ['acao' => 'ponto_lancar', 'id' => $empId, 't' => $csrf('ponto_lancar', $empId), 'data' => $ontem, 'entrada' => '09:00', 'saida' => '10:00', 'motivo' => 'teste']);
    verificar('empregado: lançar horas recusado', str_contains($html, 'Lançar horas é só para voluntários e diretoria.'), true);
    // Guarda de 90 dias: a presença antiga do empregado sai; as horas antigas do voluntário ficam.
    $velho = gmdate('Y-m-d H:i:s', time() - (MCP_PONTO_PRESENCA_DIAS + 10) * 86400);
    $velhoSaida = gmdate('Y-m-d H:i:s', time() - (MCP_PONTO_PRESENCA_DIAS + 10) * 86400 + 3600);
    $inserir = $db->prepare("INSERT INTO mcp_ponto (colaborador_id, voluntario, entrada, saida, origem_entrada, origem_saida, criado_em, atualizado_em) VALUES (?, ?, ?, ?, 'aparelho', 'aparelho', ?, ?)");
    $inserir->execute([$empId, 0, $velho, $velhoSaida, $velho, $velho]);
    $velhaPresenca = (int) $db->lastInsertId();
    $inserir->execute([$colId, 1, $velho, $velhoSaida, $velho, $velho]);
    $velhaHora = (int) $db->lastInsertId();
    [$codigo, $saida] = $rotina();
    verificar('rotina: apaga a presença com mais de 90 dias e guarda as horas doadas', [$codigo, (bool) preg_match('/ponto: [1-9]\d* presenças antigas apagadas/', $saida), mcp_ponto_registro($velhaPresenca),
        mcp_ponto_registro($velhaHora) !== null, mcp_ponto_registro($pid) !== null], [0, true, null, true, true]);

    // Colaborador inativo não registra e fica com a data do desligamento; aparelho desativado vira celular.
    [$st] = portal($base, $sessaoPortal, ['acao' => 'colaborador_salvar', 'id' => $colId, 't' => $csrf('colaborador_salvar', $colId), 'nome' => "Colaborador $marca", 'cpf' => $cpfColaborador, 'vinculo' => 'voluntario']);
    [$st, $d] = ponto($base, ['acao' => 'identificar', 'cpf' => $cpfColaborador], [$cAparelho]);
    verificar('colaborador inativo não é encontrado', $st, 404);
    verificar('colaborador inativo: data do desligamento', [mcp_colaborador_por_id($colId)['desligado_em'], mcp_colaborador_por_id($colId)['termo_em']], [$hoje, $diaTermo]);
    $aid = (int) $aparelho['id'];
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'aparelho_desativar', 'id' => $aid, 't' => $csrf('aparelho_desativar', $aid)]);
    verificar('aparelho desativado passa a valer como celular', [$st, json_decode(http($base . 'ponto.php', 'GET', null, ["Cookie: $cAparelho"])[2], true)['modo'] ?? null], [303, 'celular']);

    // Código do dia (desligado por padrão): ligado no portal, o tablet mostra e o celular só registra com ele.
    // Cinco errados para o mesmo CPF no dia travam o celular para ele; o tablet continua valendo.
    $db->exec("DELETE FROM mcp_eventos WHERE tipo IN ('ponto_consulta_falha', 'ponto_codigo_errado')");
    $apCodigo = mcp_ponto_aparelho_criar("Tablet do código $marca", 'teste');
    $cApCodigo = 'mcp_ponto_aparelho=' . mcp_ponto_aparelho_cookie_valor($apCodigo);
    $semCodigo = json_decode(http($base . 'ponto.php', 'GET', null, ["Cookie: $cApCodigo"])[2], true);
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'ponto_codigo', 'id' => 0, 't' => $csrf('ponto_codigo', 0), 'ligar' => '1']);
    [, , $htmlCodigo] = http($base . 'painel.php?v=ponto&aba=aparelhos', 'GET', null, ["Cookie: $sessaoPortal"]);
    $codigoHoje = mcp_ponto_codigo_do_dia();
    $codigoErrado = $codigoHoje === '0000' ? '0001' : '0000';
    verificar('código do dia: começa desligado; o portal liga e mostra o de hoje', [campo($semCodigo, 'codigo'), str_ends_with($cab['location'] ?? '', 'ok=cod_on'),
        str_contains($htmlCodigo, '<span class="selo ok">Ligado</span>'), str_contains($htmlCodigo, $codigoHoje)], [null, true, true, true]);
    $getTablet = json_decode(http($base . 'ponto.php', 'GET', null, ["Cookie: $cApCodigo"])[2], true);
    $getCelular = json_decode(http($base . 'ponto.php')[2], true);
    [$stSem, $rSem] = ponto($base, ['acao' => 'identificar', 'cpf' => $cpfAluna, 'posicao' => $pertoDaSede]);
    [$stErrado, $rErrado] = ponto($base, ['acao' => 'identificar', 'cpf' => $cpfAluna, 'posicao' => $pertoDaSede, 'codigo' => $codigoErrado]);
    [$stCerto] = ponto($base, ['acao' => 'identificar', 'cpf' => $cpfAluna, 'posicao' => $pertoDaSede, 'codigo' => $codigoHoje]);
    verificar('código do dia: o tablet mostra e o celular pede; sem ele ou errado não registra, certo registra',
        [$getTablet['codigo'] ?? null, campo($getCelular, 'codigo'), $getCelular['pede_codigo'] ?? null, $stSem, $rSem['motivo'] ?? null, $stErrado, $rErrado['motivo'] ?? null, $stCerto],
        [$codigoHoje, null, true, 403, 'codigo', 403, 'codigo', 200]);
    for ($i = 0; $i < 3; $i++) {
        ponto($base, ['acao' => 'identificar', 'cpf' => $cpfAluna, 'posicao' => $pertoDaSede, 'codigo' => $codigoErrado]);
    }
    [$stTravado, $rTravado] = ponto($base, ['acao' => 'identificar', 'cpf' => $cpfAluna, 'posicao' => $pertoDaSede, 'codigo' => $codigoHoje]);
    [$stTablet] = ponto($base, ['acao' => 'identificar', 'cpf' => $cpfAluna], [$cApCodigo]);
    $eventosCodigo = implode(' ', $db->query("SELECT detalhe FROM mcp_eventos WHERE tipo = 'ponto_codigo_errado'")->fetchAll(PDO::FETCH_COLUMN));
    verificar('código do dia: 5 errados para o mesmo CPF travam o celular para ele no dia; o tablet continua valendo; o registro não guarda o CPF',
        [$stTravado, $rTravado['motivo'] ?? null, $stTablet, str_contains($eventosCodigo, $cpfAluna)], [403, 'codigo_bloqueado', 200, false]);
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'ponto_codigo', 'id' => 0, 't' => $csrf('ponto_codigo', 0), 'ligar' => '0']);
    [$stDesligado] = ponto($base, ['acao' => 'identificar', 'cpf' => $cpfAluna, 'posicao' => $pertoDaSede]);
    verificar('código do dia: desligado no portal, o celular volta a registrar só com o CPF e a localização', [str_ends_with($cab['location'] ?? '', 'ok=cod_off'), $stDesligado], [true, 200]);
    $db->exec("DELETE FROM mcp_eventos WHERE tipo IN ('ponto_consulta_falha', 'ponto_codigo_errado')");

    // Limite de consultas pelo celular (por IP): quem acerta tem folga, porque todos no Wi-Fi da sede saem pelo
    // mesmo IP; quem erra CPF (o jeito de descobrir quem está cadastrado) para depois de 10.
    $db->prepare('INSERT INTO mcp_eventos (inscricao_id, tipo, detalhe, criado_em) VALUES ' . implode(', ', array_fill(0, 15, "(NULL, 'ponto_consulta', '127.0.0.1', ?)")))
        ->execute(array_fill(0, 15, mcp_agora()));
    verificar('Wi-Fi da sede: a 16ª consulta certa do mesmo IP passa', ponto($base, ['acao' => 'identificar', 'cpf' => $cpfAluna, 'posicao' => $pertoDaSede])[0], 200);
    $cpfNinguem = cpf_de_teste();
    // Os CPFs errados dos testes de antes também contam: começa do zero. E longe da rede da sede: o tablet dos
    // testes fala do mesmo IP (127.0.0.1), que por isso ficou marcado como a rede da sede.
    $db->exec("DELETE FROM mcp_eventos WHERE tipo IN ('ponto_consulta_falha', 'ponto_rede_sede') AND detalhe = '127.0.0.1'");
    for ($i = 0; $i < 9; $i++) {
        ponto($base, ['acao' => 'identificar', 'cpf' => $cpfNinguem, 'posicao' => $pertoDaSede]);
    }
    [$st404] = ponto($base, ['acao' => 'identificar', 'cpf' => $cpfNinguem, 'posicao' => $pertoDaSede]);
    [$st429, $r429] = ponto($base, ['acao' => 'identificar', 'cpf' => $cpfAluna, 'posicao' => $pertoDaSede]);
    verificar('limite de consultas pelo celular: 10 CPFs não encontrados param o IP', [$st404, $st429, str_contains($r429['erro'] ?? '', 'sem encontrar o CPF')], [404, 429, true]);
    // Na rede da sede (o IP de onde o tablet fala), o limite é maior: uma turma chegando não trava todos os celulares.
    mcp_registrar(null, 'ponto_rede_sede', '127.0.0.1');
    verificar('rede da sede: com o IP do tablet, o 11º CPF não encontrado ainda é respondido (limite 4 vezes maior)',
        [ponto($base, ['acao' => 'identificar', 'cpf' => $cpfNinguem, 'posicao' => $pertoDaSede])[0], mcp_ponto_rede_da_sede()], [404, false]);

    $erros = array_values(array_filter(file($logErros) ?: [], static fn(string $l): bool => (bool) preg_match('/PHP (Warning|Notice|Deprecated|Fatal|Parse)/', $l)));
    verificar('sem aviso nem erro do PHP no servidor', $erros, []);
} finally {
    foreach ([$servidor, $escola] as $processo) {
        proc_terminate($processo);
        proc_close($processo);
    }
    limpar($db);
    foreach ([$config, $semEscola, $logErros, $arquivoDados, $logEscola, $roteador] as $arquivo) {
        @unlink($arquivo);
    }
}

printf("%d testes, %d falhas\n", $total, $falhas);
exit($falhas > 0 ? 1 : 0);
