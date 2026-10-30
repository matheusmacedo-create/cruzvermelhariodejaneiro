#!/usr/bin/env php
<?php
/**
 * Teste da recuperação por WhatsApp (api/lib/recuperacao_whatsapp.php, api/kapso-webhook.php, api/recuperacao.php e a
 * rodada em api/lembretes.php), contra um MariaDB LOCAL e um servidor falso local que faz de Kapso E de PostgREST da
 * escola (anota tudo; nada sai de verdade). Cobre:
 *   - público: checkout do site e escola juntos por CPF, e-mail (sem diferença de maiúsculas) e celular; quem pagou no
 *     OUTRO sistema sai; sem celular, bloqueado, CPF e curso de teste, cadastro velho e matrícula paga na escola saem;
 *   - fluxo: 1 h / 24 h / 72 h da âncora, intervalo mínimo de 21 h e 48 h entre as etapas, só das 8h às 20h de Brasília,
 *     nunca repete etapa (e a UNIQUE do banco), quem paga entre as etapas para, teto de 40 por rodada e de 200 em 24 h;
 *     bloqueio em qualquer celular da pessoa vale para todos;
 *   - modelo por pessoa: 1ª rec_inscricao_1_v2; 3ª ps_cupom_oferta (3 parâmetros, botão com o cupom) só em Primeiros
 *     Socorros Básico com o cupom NOV15 valendo, com vaga e turma aberta (um oferta.json de teste); outro curso, cupom
 *     esgotado ou sem turma → rec_inscricao_3; o modelo que saiu fica gravado na linha;
 *   - grupo de controle: a pessoa sorteada nunca chama a Kapso, ganha só a linha etapa 0 'controle' e aparece nas
 *     métricas (controle × quem recebeu);
 *   - envio: o corpo exato (modelo, idioma, parâmetros, botão de URL com o caminho do checkout ou da página geral) e o
 *     cabeçalho X-API-Key; Kapso fora do ar, limite da Meta (130429) e modelo sem aprovação não gastam a etapa; 502 do
 *     gateway conta como enviada (pode ter saído) e para a rodada; número sem WhatsApp fica "falhou" e não segue;
 *   - escola fora do ar não derruba a rodada, mas segura os envios até ela voltar; sem a configuração da Kapso nada sai;
 *   - webhook: assinatura válida e inválida, entregue/lida (só avança), "Não tenho interesse" bloqueia (mesmo reentregue
 *     dias depois), outra resposta fica guardada;
 *   - painel: sem sessão não mostra dado nenhum; com sessão mostra a situação (modelos, webhook, escola), os números e a
 *     lista; "Ligar o recebimento de respostas" (POST com token) cria o webhook na Kapso com o segredo do site; um
 *     webhook com outro segredo ou sem todos os eventos aparece como desacertado, com o botão;
 *   - métricas: recuperadas (pagaram depois da 1ª mensagem), por mensagem (a última antes do pagamento), pelo link da
 *     mensagem (utm_campaign), mediana de horas até pagar, controle × quem recebeu, por origem e os 14 dias.
 *
 * As datas do teste ficam 400 dias no futuro, às 10h de Brasília: o que outros testes deixaram no banco local fica fora
 * da janela. CPFs gerados com dígito verificador válido; tudo o que o teste cria é apagado no fim. O grupo de controle
 * sai do HMAC do CPF (com o segredo do banco local): cpf_de_teste() só devolve CPFs FORA do controle, e
 * cpf_de_teste(true) só de DENTRO, para a divisão ser sempre a mesma em todos os casos.
 *
 * Uso: MCP_CONFIG_ARQUIVO=/caminho/config-teste.php php scripts/testar_recuperacao_whatsapp_integracao.php
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$configOriginal = (string) getenv('MCP_CONFIG_ARQUIVO');
if ($configOriginal === '' || !is_file($configOriginal)) {
    fwrite(STDERR, "Falta MCP_CONFIG_ARQUIVO com o banco local (veja o cabeçalho deste arquivo).\n");
    exit(2);
}

/** CPF com dígito verificador válido. Depois de carregar o módulo, só do grupo pedido ($controle: dentro ou fora). */
function cpf_de_teste(bool $controle = false): string
{
    do {
        $d = array_map(static fn(): int => random_int(0, 9), range(1, 9));
        for ($n = 9; $n < 11; $n++) {
            $s = 0;
            foreach ($d as $i => $v) {
                $s += $v * ($n + 1 - $i);
            }
            $d[] = (10 * $s) % 11 % 10;
        }
        $cpf = implode('', $d);
    } while (function_exists('mcp_rw_chave_no_controle') && mcp_rw_chave_no_controle('c:' . $cpf) !== $controle);
    return $cpf;
}

$portaSite = 19700 + random_int(0, 249);
$portaFalso = $portaSite + 250;
$falso = sys_get_temp_dir() . '/mcp-rw-falso-' . bin2hex(random_bytes(4));
mkdir($falso);
$quem = 'recuperacao-whatsapp@exemplo.org';
$cpfTeste = cpf_de_teste();
$chaveKapso = 'kapso-chave-de-teste-123';
$numeroId = '1112223334';
$base0 = [
    'PAINEL_EMAILS' => $quem, 'EMAIL_SECRETARIA' => '', 'SITE_URL' => "http://127.0.0.1:$portaSite", 'RESEND_API_KEY' => '', 'TESTE_CPFS' => [$cpfTeste],
    'ESCOLA_API_URL' => "http://127.0.0.1:$portaFalso/rest/v1/rpc/matricula_rapida", 'ESCOLA_API_TOKEN' => 'chave-escola-falsa',
];
function config_teste(array $extra): string
{
    global $configOriginal;
    $arq = tempnam(sys_get_temp_dir(), 'mcp-rw-cfg-');
    file_put_contents($arq, '<?php return ' . var_export($extra, true) . ' + (require ' . var_export($configOriginal, true) . ');');
    return $arq;
}
$config = config_teste($base0 + ['WHATSAPP_KAPSO_CHAVE' => $chaveKapso, 'WHATSAPP_KAPSO_NUMERO_ID' => $numeroId, 'WHATSAPP_KAPSO_BASE' => "http://127.0.0.1:$portaFalso"]);
$configSem = config_teste($base0 + ['WHATSAPP_KAPSO_BASE' => "http://127.0.0.1:$portaFalso"]);
$vazio = tempnam(sys_get_temp_dir(), 'mcp-rw-vazio-');
file_put_contents($vazio, '<?php return [];');
putenv("MCP_CONFIG_ARQUIVO=$config");
putenv("MCP_CONFIG_ESCOLA_ARQUIVO=$vazio");
putenv("MCP_CONFIG_WHATSAPP_ARQUIVO=$vazio");
putenv('MCP_CATALOGO_ARQUIVO=' . $raiz . '/site/matricula-cursos-presenciais/cursos.json');
// Uma turma de Primeiros Socorros Básico aberta durante todo o teste (as datas ficam 400 dias no futuro): é ela que
// libera o cupom da 3ª mensagem. O começo é 1400 dias à frente (depois da última seção, que fica a 400 + 700 dias); as inscrições fecham na véspera.
$turmaInicio = (int) strtotime(gmdate('Y-m-d', time() + 1400 * 86400) . ' 12:00:00 UTC'); // 9h de Brasília
$turmaAte = $turmaInicio - 86400;
$ofertaTeste = tempnam(sys_get_temp_dir(), 'mcp-rw-oferta-');
file_put_contents($ofertaTeste, json_encode(['parcelado_no_ar' => false, 'sem_turma' => [], 'turmas' => [[
    'curso' => 'primeiros-socorros-basico', 'id_escola' => 'turma-teste-rw', 'inicio' => gmdate('Y-m-d\TH:i:s', $turmaInicio - 3 * 3600) . '-03:00',
    'fim' => gmdate('Y-m-d\TH:i:s', $turmaInicio + 5 * 3600) . '-03:00', 'inscricoes_ate' => gmdate('Y-m-d\TH:i:s', $turmaAte - 3 * 3600) . '-03:00', 'lotada' => false,
]]]));
putenv("MCP_OFERTA_ARQUIVO=$ofertaTeste");
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $raiz . '/site/matricula-cursos-presenciais/api/lib.php';
require_once $raiz . '/site/matricula-cursos-presenciais/api/lib/recuperacao_whatsapp.php';
restore_exception_handler();

if (!in_array((string) mcp_cfg('DB_HOST', 'localhost'), ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "DB_HOST não é local. Este teste grava e apaga dados; só roda num banco local.\n");
    exit(2);
}
mcp_painel_sessao_abrir($quem);
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

/** [status, cabeçalhos (minúsculos), corpo]. Não segue redirecionamento. $corpo: array = formulário, string = cru. */
function http(string $url, string $metodo = 'GET', array|string|null $corpo = null, array $cabecalhos = []): array
{
    $recebidos = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $cabecalhos, CURLOPT_TIMEOUT => 60,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $linha) use (&$recebidos): int {
            if (str_contains($linha, ':')) {
                [$nome, $valor] = explode(':', $linha, 2);
                $recebidos[strtolower(trim($nome))] = trim($valor);
            }
            return strlen($linha);
        },
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($corpo) ? http_build_query($corpo) : $corpo);
    }
    $resposta = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $recebidos, $resposta];
}

// ----------------------------------------------------------------------------- servidor falso (Kapso e PostgREST da escola)
$roteador = "$falso/roteador.php";
file_put_contents($roteador, <<<'PHP'
<?php
// Servidor falso: anota cada pedido num .jsonl e responde como a Kapso e o PostgREST da escola responderiam.
$dir = getenv('FALSO_DIR');
$caminho = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$metodo = $_SERVER['REQUEST_METHOD'];
$corpo = (string) file_get_contents('php://input');
$anotar = static function (string $arquivo, array $dados) use ($dir): int {
    file_put_contents("$dir/$arquivo", json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    return count(file("$dir/$arquivo"));
};
$controle = static fn(string $nome): array => json_decode((string) @file_get_contents("$dir/$nome.json"), true) ?: [];
header('Content-Type: application/json');
if (str_starts_with($caminho, '/meta/') || str_starts_with($caminho, '/platform/')) {
    if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== 'kapso-chave-de-teste-123') { http_response_code(401); echo '{"error":"Invalid API key"}'; return true; }
    if (!empty($controle('kapso')['fora'])) { http_response_code(503); echo '{"error":"Service Unavailable"}'; return true; }
}
if ($caminho === '/meta/whatsapp/v24.0/1112223334/messages' && $metodo === 'POST') {
    $d = json_decode($corpo, true) ?: [];
    $n = $anotar('kapso.jsonl', ['chave' => $_SERVER['HTTP_X_API_KEY'] ?? '', 'corpo' => $d]);
    if (str_ends_with((string) ($d['to'] ?? ''), '0000')) { http_response_code(400); echo '{"error":{"message":"Message undeliverable","code":131026}}'; return true; }
    if (str_ends_with((string) ($d['to'] ?? ''), '1304')) { http_response_code(400); echo '{"error":{"message":"(#130429) Rate limit hit","code":130429}}'; return true; }
    if (str_ends_with((string) ($d['to'] ?? ''), '5020')) { http_response_code(502); echo '{"error":"Bad Gateway"}'; return true; }
    echo json_encode(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => $d['to'] ?? '']], 'messages' => [['id' => "wamid.teste-$n"]]]);
    return true;
}
if ($caminho === '/platform/v1/whatsapp/phone_numbers/1112223334' && $metodo === 'GET') {
    echo '{"data":{"id":"1112223334","business_account_id":"998877665544","status":"connected"}}';
    return true;
}
if ($caminho === '/meta/whatsapp/v24.0/998877665544/message_templates') {
    $anotar('modelos.jsonl', ['query' => $_SERVER['QUERY_STRING'] ?? '']);
    $pendente = (string) ($controle('modelos')['pendente'] ?? '');
    $situacao = static fn(string $nome): string => $pendente === $nome ? 'PENDING' : 'APPROVED';
    echo json_encode(['data' => [
        // O rec_inscricao_1 antigo continua na conta (reclassificado como Marketing), mas não é mais usado.
        ['name' => 'rec_inscricao_1', 'status' => 'APPROVED', 'language' => 'pt_BR', 'category' => 'MARKETING'],
        ['name' => 'rec_inscricao_1_v2', 'status' => $situacao('rec_inscricao_1_v2'), 'language' => 'pt_BR', 'category' => 'UTILITY'],
        ['name' => 'rec_inscricao_2', 'status' => $situacao('rec_inscricao_2'), 'language' => 'pt_BR', 'category' => 'MARKETING'],
        ['name' => 'rec_inscricao_3', 'status' => $situacao('rec_inscricao_3'), 'language' => 'pt_BR', 'category' => 'MARKETING'],
        ['name' => 'ps_cupom_oferta', 'status' => $situacao('ps_cupom_oferta'), 'language' => 'pt_BR', 'category' => 'MARKETING'],
        ['name' => 'outro_modelo', 'status' => 'REJECTED', 'language' => 'pt_BR', 'category' => 'UTILITY'],
    ]]);
    return true;
}
if (preg_match('~^/platform/v1/whatsapp/phone_numbers/1112223334/webhooks(?:/([^/]+))?$~', $caminho, $m)) {
    $lista = $controle('webhooks')['data'] ?? [];
    if ($metodo === 'GET') { echo json_encode(['data' => $lista]); return true; }
    $d = json_decode($corpo, true) ?: [];
    $anotar('webhooks.jsonl', ['metodo' => $metodo, 'corpo' => $d]);
    $w = ($d['whatsapp_webhook'] ?? []) + ['id' => $m[1] ?? 'wh-1'];
    $w['id'] = $m[1] ?? 'wh-1';
    file_put_contents("$dir/webhooks.json", json_encode(['data' => [$w]]));
    http_response_code($metodo === 'POST' ? 201 : 200);
    echo json_encode(['data' => $w]);
    return true;
}
if ($caminho === '/rest/v1/Usuario' && $metodo === 'GET') {
    if (($_SERVER['HTTP_APIKEY'] ?? '') !== 'chave-escola-falsa' || ($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer chave-escola-falsa') { http_response_code(401); echo '{}'; return true; }
    $anotar('escola.jsonl', ['query' => $_GET]);
    $c = $controle('escola');
    if (!empty($c['fora'])) { http_response_code(503); echo '{"message":"fora do ar (teste)"}'; return true; }
    $usuarios = $c['usuarios'] ?? [];
    if (isset($_GET['criadoEm'])) {
        $desde = substr((string) $_GET['criadoEm'], 4);
        $usuarios = array_values(array_filter($usuarios, static fn(array $u): bool => ($u['papel'] ?? '') === 'ALUNO' && $u['criadoEm'] >= $desde));
        usort($usuarios, static fn(array $a, array $b): int => [$a['criadoEm'], $a['id']] <=> [$b['criadoEm'], $b['id']]);
    } elseif (isset($_GET['or']) && preg_match('/^\((cpfCnpj|email|celular)\.in\.\((.*)\)\)$/', (string) $_GET['or'], $m)) {
        $valores = str_getcsv($m[2], ',', '"', '\\');
        $usuarios = array_values(array_filter($usuarios, static fn(array $u): bool => in_array((string) ($u[$m[1]] ?? ''), $valores, true)));
    } else {
        http_response_code(400); echo '{"message":"filtro desconhecido"}'; return true;
    }
    $usuarios = array_slice($usuarios, (int) ($_GET['offset'] ?? 0), (int) ($_GET['limit'] ?? 1000));
    echo json_encode($usuarios, JSON_UNESCAPED_UNICODE);
    return true;
}
http_response_code(404);
echo '{}';
return true;
PHP);
function falso(string $nome): array
{
    global $falso;
    $arq = "$falso/$nome.jsonl";
    return is_file($arq) ? array_map(static fn(string $l): array => json_decode($l, true), file($arq, FILE_IGNORE_NEW_LINES)) : [];
}
function controle(string $nome, array $dados): void
{
    global $falso;
    file_put_contents("$falso/$nome.json", json_encode($dados, JSON_UNESCAPED_UNICODE));
}

ignore_user_abort(true);
$db = mcp_db();
$sufixo = bin2hex(random_bytes(3));
$padraoEmail = '^rw-[a-z0-9]+-[0-9a-f]{6}@exemplo[.]org$';
$telefones = [];
// A base do teste: daqui a 400 dias, 13h UTC (10h de Brasília).
$A = (int) strtotime(gmdate('Y-m-d', time() + 400 * 86400) . ' 13:00:00 UTC');

function limpar(PDO $db, string $padraoEmail, string $quem, array $telefones): void
{
    $stmt = $db->prepare('SELECT id FROM mcp_inscricoes WHERE email REGEXP ?');
    $stmt->execute([$padraoEmail]);
    $ids = implode(', ', array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    if ($ids !== '') {
        $db->exec("DELETE FROM mcp_eventos WHERE inscricao_id IN ($ids)");
        $db->exec("DELETE FROM mcp_inscricoes WHERE id IN ($ids)");
    }
    // As linhas do teste ficam todas no futuro (a base é daqui a 400 dias).
    $db->exec('DELETE FROM mcp_recuperacao_whatsapp WHERE criado_em > DATE_ADD(UTC_TIMESTAMP(), INTERVAL 300 DAY)');
    foreach ($telefones as $numero) {
        $db->prepare("DELETE FROM mcp_avisos_bloqueios WHERE canal = 'whatsapp' AND destino_hash = ?")->execute([mcp_avisos_hash('whatsapp', $numero)]);
    }
    $db->exec("DELETE FROM mcp_chaves WHERE nome IN ('rw_cache_modelos', 'rw_cache_webhook')");
    $db->prepare("DELETE FROM mcp_eventos WHERE tipo = 'recuperacao_webhook' AND detalhe LIKE ?")->execute([$quem . '%']);
}
limpar($db, $padraoEmail, $quem, []);

/** Celular novo (11 dígitos), anotado para a limpeza. $fim: os 4 últimos dígitos (0000 = a Kapso recusa). */
$celular = static function (string $fim = '') use (&$telefones): string {
    $t = '2197' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
    if ($fim !== '') {
        $t = substr($t, 0, 7) . $fim;
    }
    $telefones[] = '55' . $t;
    return $t;
};
$quando = static fn(int $ts): string => gmdate('Y-m-d H:i:s', $ts);
$criar = static function (string $chave, int $criadoTs, array $campos = []) use ($db, $sufixo, $celular, $quando): array {
    $linha = $campos + [
        'token' => mcp_token_novo(), 'cpf' => cpf_de_teste(), 'telefone' => $celular(), 'metodo' => 'pix',
        'curso_slug' => 'puncao-venosa', 'curso_nome' => 'Punção Venosa', 'nome' => "Pessoa Teste $chave",
        'email' => "rw-$chave-$sufixo@exemplo.org", 'inscricao_centavos' => 9900, 'taxa_centavos' => 0, 'total_centavos' => 9900,
        'status' => 'pendente', 'escola_status' => 'nao_aplicavel', 'criado_em' => $quando($criadoTs), 'atualizado_em' => $quando($criadoTs), 'pago_em' => null,
    ];
    $colunas = array_keys($linha);
    $db->prepare('INSERT INTO mcp_inscricoes (' . implode(', ', $colunas) . ') VALUES (' . implode(', ', array_fill(0, count($colunas), '?')) . ')')
        ->execute(array_values($linha));
    $linha['id'] = (int) $db->lastInsertId();
    return $linha;
};
$iso = static fn(int $ts): string => gmdate('Y-m-d\TH:i:s', $ts) . '.000';
$usuario = static function (string $id, int $criadoTs, array $campos = [], array $matriculas = []) use ($iso, $celular, $sufixo): array {
    return $campos + [
        'id' => "u-$id-$sufixo", 'nome' => "Aluno Escola $id", 'email' => "rw-escola$id-$sufixo@exemplo.org", 'cpfCnpj' => cpf_de_teste(),
        'celular' => $celular(), 'papel' => 'ALUNO', 'criadoEm' => $iso($criadoTs), 'matriculas' => $matriculas,
    ];
};
$matricula = static function (string $id, int $criadoTs, string $cursoUuid, string $cursoNome, bool $paga = false) use ($iso): array {
    return ['id' => "m-$id", 'criadoEm' => $iso($criadoTs), 'atualizadoEm' => $iso($criadoTs), 'taxaConfirmada' => $paga, 'taxaConfirmadaEm' => $paga ? $iso($criadoTs + 600) : null,
        'statusPagamento' => 'PENDENTE', 'turma' => ['cursoId' => $cursoUuid, 'curso' => ['id' => $cursoUuid, 'nome' => $cursoNome]]];
};
/** Mensagens que o servidor falso recebeu para um celular (11 dígitos), na ordem. */
$mensagensPara = static function (string $tel): array {
    return array_values(array_filter(falso('kapso'), static fn(array $x): bool => ($x['corpo']['to'] ?? '') === '55' . $tel));
};
$etapasPara = static fn(string $tel): array => array_map(static fn(array $x): string => (string) ($x['corpo']['template']['name'] ?? ''), $mensagensPara($tel));
$uuidBombeiro = (string) mcp_curso('bombeiro-civil')['uuid'];

$servidor = null;
$servidorFalso = null;
try {
    $nulo = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $servidorFalso = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaFalso", $roteador], $nulo, $tubos2, null, ['FALSO_DIR' => $falso, 'PATH' => (string) getenv('PATH')]);
    $logErros = tempnam(sys_get_temp_dir(), 'mcp-rw-php-');
    $servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaSite", '-t', $raiz . '/site', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$logErros"], $nulo, $tubos, null, [
        'MCP_CONFIG_ARQUIVO' => $config, 'MCP_CONFIG_ESCOLA_ARQUIVO' => $vazio, 'MCP_CONFIG_WHATSAPP_ARQUIVO' => $vazio,
        'MCP_CATALOGO_ARQUIVO' => getenv('MCP_CATALOGO_ARQUIVO'), 'MCP_OFERTA_ARQUIVO' => $ofertaTeste, 'PATH' => (string) getenv('PATH'),
    ]);
    foreach ([$portaSite, $portaFalso] as $porta) {
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $porta) === false; $i++) {
            usleep(100000);
        }
    }

    // ------------------------------------------------------------------------- regras puras
    verificar('horário: 10h de Brasília dentro, 7h59 e 20h fora', [mcp_rw_no_horario($A), mcp_rw_no_horario($A - 7260), mcp_rw_no_horario($A + 10 * 3600), mcp_rw_no_horario($A + 10 * 3600 - 1)],
        [true, false, false, true]);
    verificar('horário: 21h vai para as 8h do dia seguinte; 6h vai para as 8h do mesmo dia', [mcp_rw_ajustar_horario($A + 11 * 3600) - $A, mcp_rw_ajustar_horario($A - 4 * 3600) - $A],
        [22 * 3600, -2 * 3600]);
    verificar('régua: etapa 1 com 1 h da âncora', mcp_rw_proxima(1000, []), ['etapa' => 1, 'devida_em' => 1000 + 3600]);
    verificar('régua: a 1ª com 1 h saiu na hora; a 2ª ainda com 24 h da âncora (21 h depois da 1ª não basta)',
        mcp_rw_proxima(0, [1 => ['status' => 'enviado', 'entrega' => null, 'criado_ts' => 3600]]), ['etapa' => 2, 'devida_em' => 24 * 3600]);
    verificar('régua: etapa 2 com 24 h da âncora e 21 h depois da 1ª', mcp_rw_proxima(0, [1 => ['status' => 'enviado', 'entrega' => null, 'criado_ts' => 10 * 3600]]),
        ['etapa' => 2, 'devida_em' => 31 * 3600]);
    verificar('régua: etapa 3 com 72 h da âncora', mcp_rw_proxima(0, [1 => ['status' => 'enviado', 'entrega' => 'lida', 'criado_ts' => 3600], 2 => ['status' => 'enviado', 'entrega' => null, 'criado_ts' => 24 * 3600]]),
        ['etapa' => 3, 'devida_em' => 72 * 3600]);
    verificar('régua: a 3ª com 48 h depois da 2ª, quando a 2ª atrasou', mcp_rw_proxima(0, [1 => ['status' => 'enviado', 'entrega' => null, 'criado_ts' => 3600],
        2 => ['status' => 'enviado', 'entrega' => null, 'criado_ts' => 30 * 3600]]), ['etapa' => 3, 'devida_em' => 78 * 3600]);
    verificar('régua: sem a anterior enviada (falhou ou não entregue), não segue; depois da 3ª, acabou', [
        mcp_rw_proxima(0, [1 => ['status' => 'falhou', 'entrega' => null, 'criado_ts' => 0]]),
        mcp_rw_proxima(0, [1 => ['status' => 'enviado', 'entrega' => 'falhou', 'criado_ts' => 0]]),
        mcp_rw_proxima(0, [1 => ['status' => 'enviado', 'entrega' => null, 'criado_ts' => 0], 2 => ['status' => 'enviado', 'entrega' => null, 'criado_ts' => 0], 3 => ['status' => 'enviado', 'entrega' => null, 'criado_ts' => 0]]),
    ], [null, null, null]);
    $tent = static fn(string $origem, string $ref, string $cpf, string $numero, int $quando): array => ['origem' => $origem, 'ref' => $ref, 'cpf' => $cpf, 'email' => '',
        'numero' => $numero, 'nome' => 'Ana Teste', 'curso_slug' => '', 'curso_nome' => 'Punção Venosa', 'quando' => $quando, 'aberta' => true, 'pago' => false, 'pago_ts' => null];
    $ana = mcp_rw_agrupar([$tent('site', 's1', '52998224725', '5521911112222', $A - 5 * 86400), $tent('escola', 'e1', '52998224725', '5521933334444', $A - 86400)],
        static fn(string $n): bool => $n === '5521911112222');
    verificar('bloqueio no celular antigo vale para o novo da mesma pessoa', [count($ana), $ana[0]['numero'] ?? '', $ana[0]['no_fluxo'] ?? null, $ana[0]['motivo'] ?? null],
        [1, '5521933334444', false, 'bloqueou']);
    verificar('resposta que para: botão, SAIR, Parar., stop; outra não', array_map('mcp_rw_quer_parar', ['Não tenho interesse', 'SAIR', 'Parar.', ' stop ', 'quero saber mais', 'não, obrigado']),
        [true, true, true, true, false, false]);
    verificar('caminho do botão: curso do catálogo vai ao checkout; sem curso, à página geral', [mcp_rw_caminho_botao('bombeiro-civil', 2), mcp_rw_caminho_botao('', 1), mcp_rw_caminho_botao('curso-que-nao-existe', 3)], [
        'matricula-cursos-presenciais/checkout/?curso=bombeiro-civil&utm_source=whatsapp&utm_medium=recuperacao&utm_campaign=rec2',
        'matricula-cursos-presenciais/?utm_source=whatsapp&utm_medium=recuperacao&utm_campaign=rec1',
        'matricula-cursos-presenciais/?utm_source=whatsapp&utm_medium=recuperacao&utm_campaign=rec3',
    ]);
    $psPessoa = ['primeiro_nome' => 'Bia', 'curso_slug' => 'primeiros-socorros-basico', 'curso_nome' => 'Primeiros Socorros Básico'];
    $ofertaPura = ['codigo' => 'NOV15', 'data' => 'terça-feira, 17/11'];
    $cupomPuro = mcp_rw_modelo(3, $psPessoa, $ofertaPura);
    verificar('modelo da 3ª: Primeiros Socorros com o cupom valendo → ps_cupom_oferta, 3 parâmetros e o botão com o cupom', [$cupomPuro['nome'], $cupomPuro['parametros'], $cupomPuro['caminho']],
        ['ps_cupom_oferta', ['Bia', 'NOV15', 'terça-feira, 17/11'], 'matricula-cursos-presenciais/checkout/?curso=primeiros-socorros-basico&via=agenda&cupom=NOV15&utm_source=whatsapp&utm_medium=recuperacao&utm_campaign=rec3cupom']);
    verificar('modelo: outro curso, sem cupom valendo, ou outra etapa → os de sempre', [
        mcp_rw_modelo(3, ['curso_slug' => 'bombeiro-civil', 'curso_nome' => 'Bombeiro Civil'] + $psPessoa, $ofertaPura)['nome'], mcp_rw_modelo(3, $psPessoa, null)['nome'],
        mcp_rw_modelo(1, $psPessoa, $ofertaPura)['nome'], mcp_rw_modelo(2, $psPessoa, $ofertaPura)['nome'],
    ], ['rec_inscricao_3', 'rec_inscricao_3', 'rec_inscricao_1_v2', 'rec_inscricao_2']);
    verificar('prévia do ps_cupom_oferta: nome, cupom em negrito e a data da turma, sem {{n}} sobrando', [str_starts_with($cupomPuro['previa'], 'Olá, Bia! Separamos'),
        str_contains($cupomPuro['previa'], '*NOV15*'), str_contains($cupomPuro['previa'], 'turma de terça-feira, 17/11.'), str_contains($cupomPuro['previa'], '{{')], [true, true, true, false]);
    verificar('grupo de controle: o sorteio pela chave é fixo, e o que está gravado manda', [
        mcp_rw_chave_no_controle('c:' . cpf_de_teste(true)), mcp_rw_chave_no_controle('c:' . cpf_de_teste()),
        mcp_rw_controle(['principal' => 'c:' . cpf_de_teste()], [0 => ['status' => 'controle']]), mcp_rw_controle(['principal' => 'c:' . cpf_de_teste(true)], [1 => ['status' => 'enviado']]),
    ], [true, false, true, false]);
    $sorteados = 0;
    for ($i = 0; $i < 2000; $i++) {
        $sorteados += mcp_rw_chave_no_controle("e:sorteio$i@exemplo.org") ? 1 : 0;
    }
    verificar('grupo de controle: perto de 10% (entre 7% e 13% de 2000 chaves)', $sorteados >= 140 && $sorteados <= 260, true);

    // ------------------------------------------------------------------------- dados
    $s1 = $criar('maria', $A - 4 * 3600, ['nome' => 'MARIA SOUZA']);                       // site, 4 h atrás
    $e1 = $usuario('joao', $A - 5 * 3600, ['nome' => 'joão lima'], [$matricula('joao', $A - 5 * 3600 + 60, $uuidBombeiro, 'Bombeiro Civil')]);
    $s3 = $criar('uniao', $A - 6 * 3600);                                                    // site e escola pelo e-mail
    $e3 = $usuario('uniao', $A - 5 * 3600, ['email' => strtoupper($s3['email']), 'nome' => 'Ana Paula']);
    $s4 = $criar('pagouescola', $A - 5 * 3600);                                              // pagou na escola (conta antiga)
    $e4 = $usuario('pagouescola', $A - 100 * 86400, ['cpfCnpj' => $s4['cpf']], [$matricula('pagou4', $A - 99 * 86400, $uuidBombeiro, 'Bombeiro Civil', true)]);
    $e5 = $usuario('pagousite', $A - 5 * 3600);                                              // pagou no site (pelo celular, com 55)
    $s5 = $criar('pagousite', $A - 200 * 86400, ['telefone' => '+55 (' . substr($e5['celular'], 0, 2) . ') ' . substr($e5['celular'], 2), 'status' => 'pago', 'pago_em' => $quando($A - 199 * 86400)]);
    $e6 = $usuario('pagaescola', $A - 5 * 3600, [], [$matricula('paga6', $A - 5 * 3600, $uuidBombeiro, 'Bombeiro Civil', true)]);
    $s7 = $criar('fixo', $A - 5 * 3600, ['telefone' => '2122223333']);
    $s8 = $criar('bloqueado', $A - 5 * 3600);
    mcp_avisos_bloquear_hash('whatsapp', mcp_avisos_hash('whatsapp', '55' . $s8['telefone']), 'respondeu PARAR');
    $s9 = $criar('cpfteste', $A - 5 * 3600, ['cpf' => $cpfTeste]);
    $s10 = $criar('cursoteste', $A - 5 * 3600, ['curso_slug' => 'curso-teste', 'curso_nome' => 'Curso Teste']);
    $s11 = $criar('recente', $A - 1800);                                                     // 30 min: a 1ª ainda não
    $s15 = $criar('controle', $A - 4 * 3600, ['cpf' => cpf_de_teste(true)]);                // grupo de controle
    $s12 = $criar('velho', $A - 91 * 86400);
    $s13 = $criar('semzap', $A - 5 * 3600, ['telefone' => $celular('0000')]);              // a Kapso recusa (131026)
    $e7 = $usuario('sematricula', $A - 4 * 3600);                                            // só criou a conta
    $e8 = $usuario('staff', $A - 4 * 3600, ['papel' => 'SECRETARIA']);
    controle('escola', ['fora' => false, 'usuarios' => [$e1, $e3, $e4, $e5, $e6, $e7, $e8]]);

    // ------------------------------------------------------------------------- público
    $carga = mcp_rw_carregar($A);
    $porNumero = [];
    foreach ($carga['pessoas'] as $p) {
        foreach ($p['numeros'] as $n) {
            $porNumero[$n] = $p;
        }
    }
    $de = static fn(string $tel): ?array => $porNumero['55' . $tel] ?? null;
    verificar('escola lida (alunos da janela e busca pelos dados do site)', $carga['escola'], ['estado' => 'ok', 'erro' => null]);
    verificar('site e escola com o mesmo e-mail (maiúsculas) viram uma pessoa', [$de($s3['telefone']) !== null && $de($s3['telefone']) === $de($e3['celular']), $de($s3['telefone'])['origens'] ?? []],
        [true, ['escola', 'site']]);
    verificar('na união, vale a tentativa mais recente (a conta da escola): nome, curso e celular dela',
        [$de($s3['telefone'])['primeiro_nome'] ?? '', $de($s3['telefone'])['curso_nome'] ?? '', $de($s3['telefone'])['numero'] ?? ''], ['Ana', 'nossos cursos', '55' . $e3['celular']]);
    verificar('sai quem pagou na escola (conta antiga, achada pelo CPF)', [$de($s4['telefone'])['no_fluxo'] ?? null, $de($s4['telefone'])['motivo'] ?? null], [false, 'pagou']);
    verificar('sai quem pagou no site (pelo celular formatado com +55)', [$de($e5['celular'])['no_fluxo'] ?? null, $de($e5['celular'])['motivo'] ?? null], [false, 'pagou']);
    verificar('sai quem tem matrícula paga na escola', $de($e6['celular'])['motivo'] ?? null, 'pagou');
    $fixo = array_values(array_filter($carga['pessoas'], static fn(array $p): bool => in_array($s7['email'], $p['emails'], true)))[0] ?? [];
    verificar('sai quem não tem celular válido', [$fixo['no_fluxo'] ?? null, $fixo['motivo'] ?? null], [false, 'sem_celular']);
    verificar('sai quem bloqueou o WhatsApp', $de($s8['telefone'])['motivo'] ?? null, 'bloqueou');
    verificar('saem CPF de teste e curso de teste', [$de($s9['telefone']), $de($s10['telefone'])], [null, null]);
    verificar('sai cadastro de mais de 90 dias', $de($s12['telefone'])['motivo'] ?? null, 'fora_da_janela');
    verificar('conta da escola que não é de aluno não entra', $de($e8['celular']), null);
    verificar('escola: quem só criou a conta entra, com "nossos cursos"', [$de($e7['celular'])['no_fluxo'] ?? null, $de($e7['celular'])['curso_nome'] ?? null, $de($e7['celular'])['curso_slug'] ?? null],
        [true, 'nossos cursos', '']);
    verificar('escola: quem se matriculou sem pagar entra, com o curso (slug pelo uuid do cursos.json)',
        [$de($e1['celular'])['no_fluxo'] ?? null, $de($e1['celular'])['curso_slug'] ?? null, $de($e1['celular'])['origem'] ?? null], [true, 'bombeiro-civil', 'escola']);
    $buscas = array_values(array_filter(falso('escola'), static fn(array $x): bool => isset($x['query']['or'])));
    verificar('escola: busca por CPF, e-mail e celular de quem veio do site, com a chave secreta', [count($buscas) >= 3,
        (bool) array_filter($buscas, static fn(array $x): bool => str_contains((string) $x['query']['or'], $s4['cpf']))], [true, true]);

    // ------------------------------------------------------------------------- rodadas
    $antes = count(falso('kapso'));
    $r = mcp_rw_rodar($A - 4 * 3600); // 6h de Brasília
    verificar('fora do horário (6h): nada sai', [$r['enviados'], $r['motivo'], count(falso('kapso')) - $antes], [0, 'fora do horário (8h–20h de Brasília)', 0]);

    $r = mcp_rw_rodar($A);
    verificar('rodada das 10h: as etapas 1 devidas saem; a pessoa do grupo de controle só ganha a linha', [$r['escola'], $r['falhas'], $r['controle']], ['ok', 1, 1]);
    $linhasControle = $db->prepare('SELECT etapa, status, modelo, provedor_id FROM mcp_recuperacao_whatsapp WHERE telefone_hash = ?');
    $linhasControle->execute([mcp_rw_hmac('t:55' . $s15['telefone'])]);
    verificar('grupo de controle: etapa 0 "controle", sem modelo, sem wamid, e a Kapso não foi chamada', [$linhasControle->fetchAll(), count($mensagensPara($s15['telefone']))],
        [[['etapa' => 0, 'status' => 'controle', 'modelo' => null, 'provedor_id' => null]], 0]);
    $mA = mcp_rw_metricas(mcp_rw_carregar($A)['pessoas'], mcp_rw_linhas(), $A);
    $naLista = array_values(array_filter($mA['lista'], static fn(array $p): bool => $p['controle']));
    verificar('grupo de controle na lista: marcado, sem próxima mensagem nem modelo, e fora de "no fluxo"', [count($naLista), array_intersect_key($naLista[0] ?? [], ['proxima' => 1, 'modelo' => 1]), $mA['no_controle']],
        [1, ['proxima' => null, 'modelo' => null], 1]);
    // A pessoa do controle paga sozinha, 1 h depois de quando a 1ª sairia.
    $db->prepare("UPDATE mcp_inscricoes SET status = 'pago', pago_em = ? WHERE id = ?")->execute([$quando($A + 3600), $s15['id']]);
    $msg = $mensagensPara($s1['telefone'])[0] ?? [];
    verificar('envio: cabeçalho X-API-Key com a chave', $msg['chave'] ?? '', $chaveKapso);
    verificar('envio: corpo exato (modelo, idioma, nome, curso e botão de URL)', $msg['corpo'] ?? [], [
        'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => '55' . $s1['telefone'], 'type' => 'template',
        'template' => ['name' => 'rec_inscricao_1_v2', 'language' => ['code' => 'pt_BR'], 'components' => [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Maria'], ['type' => 'text', 'text' => 'Punção Venosa']]],
            ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => 'matricula-cursos-presenciais/checkout/?curso=puncao-venosa&utm_source=whatsapp&utm_medium=recuperacao&utm_campaign=rec1']]],
        ]],
    ]);
    $msgE1 = $mensagensPara($e1['celular'])[0]['corpo']['template'] ?? [];
    verificar('envio da escola: nome capitalizado, curso da matrícula e checkout do curso', [$msgE1['components'][0]['parameters'][0]['text'] ?? '', $msgE1['components'][0]['parameters'][1]['text'] ?? '',
        $msgE1['components'][1]['parameters'][0]['text'] ?? ''], ['João', 'Bombeiro Civil', 'matricula-cursos-presenciais/checkout/?curso=bombeiro-civil&utm_source=whatsapp&utm_medium=recuperacao&utm_campaign=rec1']);
    $msgE7 = $mensagensPara($e7['celular'])[0]['corpo']['template'] ?? [];
    verificar('envio sem matrícula: "nossos cursos" e a página geral', [$msgE7['components'][0]['parameters'][1]['text'] ?? '', $msgE7['components'][1]['parameters'][0]['text'] ?? ''],
        ['nossos cursos', 'matricula-cursos-presenciais/?utm_source=whatsapp&utm_medium=recuperacao&utm_campaign=rec1']);
    verificar('a pessoa da união recebe UMA mensagem (no celular da tentativa mais recente)', [count($mensagensPara($e3['celular'])), count($mensagensPara($s3['telefone']))], [1, 0]);
    verificar('não recebem: pagou em outro sistema, sem celular, bloqueado, teste, recente demais, velho', array_map('count', [
        $mensagensPara($s4['telefone']), $mensagensPara($e5['celular']), $mensagensPara($e6['celular']), $mensagensPara($s8['telefone']), $mensagensPara($s9['telefone']),
        $mensagensPara($s10['telefone']), $mensagensPara($s11['telefone']), $mensagensPara($s12['telefone']), $mensagensPara($e8['celular']),
    ]), [0, 0, 0, 0, 0, 0, 0, 0, 0]);
    $linhaS13 = $db->prepare('SELECT status, erro FROM mcp_recuperacao_whatsapp WHERE telefone_hash = ?');
    $linhaS13->execute([mcp_rw_hmac('t:55' . $s13['telefone'])]);
    verificar('número sem WhatsApp: a etapa fica "falhou" com o erro da Meta', $linhaS13->fetch() ?: [], ['status' => 'falhou', 'erro' => '#131026 Message undeliverable']);
    $linhaS1 = $db->prepare('SELECT pessoa_chave, origem, etapa, modelo, curso_slug, curso_nome, telefone_hash, status, provedor_id FROM mcp_recuperacao_whatsapp WHERE telefone_hash = ? ORDER BY etapa');
    $linhaS1->execute([mcp_rw_hmac('t:55' . $s1['telefone'])]);
    $l1 = $linhaS1->fetchAll();
    verificar('linha gravada: chave e telefone em HMAC (64), etapa 1, modelo, wamid', [count($l1), $l1[0]['pessoa_chave'] ?? '', strlen((string) ($l1[0]['telefone_hash'] ?? '')), $l1[0]['etapa'] ?? 0,
        $l1[0]['modelo'] ?? '', $l1[0]['status'] ?? '', str_starts_with((string) ($l1[0]['provedor_id'] ?? ''), 'wamid.teste-'), $l1[0]['origem'] ?? ''],
        [1, mcp_rw_hmac('c:' . $s1['cpf']), 64, 1, 'rec_inscricao_1_v2', 'enviado', true, 'site']);
    $claro = $db->prepare('SELECT COUNT(*) FROM mcp_recuperacao_whatsapp WHERE pessoa_chave IN (?, ?) OR telefone_hash IN (?, ?) OR resposta LIKE ?');
    $claro->execute([$s1['cpf'], $s1['telefone'], $s1['cpf'], '55' . $s1['telefone'], '%' . $s1['telefone'] . '%']);
    verificar('nem CPF nem telefone em claro na tabela', (int) $claro->fetchColumn(), 0);
    $dup = null;
    try {
        $db->prepare("INSERT INTO mcp_recuperacao_whatsapp (pessoa_chave, origem, etapa, curso_nome, telefone_hash, status, criado_em, atualizado_em) VALUES (?, 'site', 1, 'x', ?, 'enviado', ?, ?)")
            ->execute([$l1[0]['pessoa_chave'], $l1[0]['telefone_hash'], $quando($A), $quando($A)]);
    } catch (PDOException $e) {
        $dup = $e->getCode();
    }
    verificar('o banco recusa a mesma etapa duas vezes (UNIQUE)', $dup, '23000');

    $antes = count(falso('kapso'));
    $r = mcp_rw_rodar($A + 3600);
    verificar('uma hora depois: nada repete; só o cadastro de 30 min atrás recebe a 1ª (1 h depois dele)', [$r['enviados'], count(falso('kapso')) - $antes, $etapasPara($s11['telefone']), $r['controle']],
        [1, 1, ['rec_inscricao_1_v2'], 0]);
    $r = mcp_rw_rodar($A + 20 * 3600); // 6h do dia seguinte
    verificar('24 h da âncora às 6h: fora do horário, espera', $r['enviados'], 0);

    // Maria paga entre a 1ª e a 2ª (no site).
    $db->prepare("UPDATE mcp_inscricoes SET status = 'pago', pago_em = ?, utm_campaign = 'rec1' WHERE id = ?")->execute([$quando($A + 2 * 3600), $s1['id']]);
    // Escola fora do ar; um cadastro novo no site.
    controle('escola', ['fora' => true, 'usuarios' => []]);
    $s14 = $criar('escolafora', $A + 18 * 3600);
    $antes = count(falso('kapso'));
    $r = mcp_rw_rodar($A + 24 * 3600);
    verificar('escola fora do ar: não derruba, e nada sai até ela voltar (não dá para saber quem pagou lá)',
        [$r['escola'], str_starts_with((string) $r['motivo'], 'escola indisponível'), count(falso('kapso')) - $antes], ['indisponivel', true, 0]);
    verificar('Maria pagou entre as etapas: não recebe a 2ª', $etapasPara($s1['telefone']), ['rec_inscricao_1_v2']);
    // Com a escola fora, a pessoa da união aparece só pelo lado do site (outro CPF e outro celular): as etapas dela são
    // achadas pelo e-mail (coluna chaves), e a 1ª não vai de novo para o celular do site.
    verificar('escola fora: a pessoa da união não recebe a 1ª de novo pelo celular do site', in_array('rec_inscricao_1_v2', $etapasPara($s3['telefone']), true), false);
    controle('escola', ['fora' => false, 'usuarios' => [$e1, $e3, $e4, $e5, $e6, $e7, $e8]]);

    // Kapso fora do ar: a etapa não é gasta.
    controle('kapso', ['fora' => true]);
    $r = mcp_rw_rodar($A + 25 * 3600);
    $linhasE1 = $db->prepare('SELECT etapa FROM mcp_recuperacao_whatsapp WHERE telefone_hash = ? ORDER BY etapa');
    $linhasE1->execute([mcp_rw_hmac('t:55' . $e1['celular'])]);
    verificar('Kapso fora: a rodada para, sem gastar a etapa', [$r['enviados'], $r['falhas'], str_starts_with((string) $r['motivo'], 'Kapso:'), array_map('intval', $linhasE1->fetchAll(PDO::FETCH_COLUMN))],
        [0, 1, true, [1]]);
    controle('kapso', ['fora' => false]);
    $r = mcp_rw_rodar($A + 26 * 3600);
    verificar('Kapso de volta: a 2ª sai (escola de volta também)', [$r['escola'], $etapasPara($e1['celular']), $etapasPara($e7['celular'])],
        ['ok', ['rec_inscricao_1_v2', 'rec_inscricao_2'], ['rec_inscricao_1_v2', 'rec_inscricao_2']]);
    verificar('escola de volta: o cadastro feito com ela fora recebe a 1ª', $etapasPara($s14['telefone']), ['rec_inscricao_1_v2']);
    verificar('Maria continua sem a 2ª', $etapasPara($s1['telefone']), ['rec_inscricao_1_v2']);
    $msg2 = $mensagensPara($e1['celular'])[1]['corpo']['template']['components'][1]['parameters'][0]['text'] ?? '';
    verificar('2ª mensagem: campanha rec2 no botão', str_ends_with($msg2, 'utm_campaign=rec2'), true);
    verificar('número sem WhatsApp não recebe a 2ª', count($mensagensPara($s13['telefone'])), 1);

    // ------------------------------------------------------------------------- webhook
    $urlWebhook = "http://127.0.0.1:$portaSite/matricula-cursos-presenciais/api/kapso-webhook.php";
    $assinar = static fn(string $corpo): string => hash_hmac('sha256', $corpo, mcp_segredo('kapso_webhook'));
    $wamid = $mensagensPara($e1['celular']);
    $idE1 = 'wamid.teste-' . (array_search($wamid[1], falso('kapso'), true) + 1);
    $entregue = (string) json_encode(['message' => ['id' => $idE1, 'kapso' => ['direction' => 'outbound', 'status' => 'delivered']], 'phone_number_id' => $numeroId]);
    [$st] = http($urlWebhook, 'POST', $entregue, ['Content-Type: application/json', 'X-Webhook-Event: whatsapp.message.delivered']);
    [$st2] = http($urlWebhook, 'POST', $entregue, ['Content-Type: application/json', 'X-Webhook-Event: whatsapp.message.delivered', 'X-Webhook-Signature: ' . str_repeat('0', 64)]);
    [$st3] = http($urlWebhook);
    $entrega = static function (string $id) use ($db): ?string {
        $stmt = $db->prepare('SELECT entrega FROM mcp_recuperacao_whatsapp WHERE provedor_id = ?');
        $stmt->execute([$id]);
        return $stmt->fetchColumn() ?: null;
    };
    verificar('webhook: sem assinatura, assinatura errada e GET → 401, nada muda', [$st, $st2, $st3, $entrega($idE1)], [401, 401, 401, null]);
    [$st, , $corpoResp] = http($urlWebhook, 'POST', $entregue, ['Content-Type: application/json', 'X-Webhook-Event: whatsapp.message.delivered', 'X-Webhook-Signature: ' . $assinar($entregue)]);
    verificar('webhook: entregue', [$st, json_decode($corpoResp, true)['tratados'] ?? null, $entrega($idE1)], [200, 1, 'entregue']);
    $lida = (string) json_encode(['batch' => true, 'data' => [['message' => ['id' => $idE1, 'kapso' => ['direction' => 'outbound', 'status' => 'read']], 'phone_number_id' => $numeroId]]]);
    http($urlWebhook, 'POST', $lida, ['X-Webhook-Event: whatsapp.message.read', 'X-Webhook-Signature: sha256=' . $assinar($lida)]);
    http($urlWebhook, 'POST', $entregue, ['X-Webhook-Event: whatsapp.message.delivered', 'X-Webhook-Signature: ' . $assinar($entregue)]);
    verificar('webhook: lida (em lote, com "sha256="), e "entregue" atrasado não volta atrás', $entrega($idE1), 'lida');
    $outro = (string) json_encode(['message' => ['id' => $idE1, 'kapso' => ['status' => 'read']], 'phone_number_id' => '999999999']);
    http($urlWebhook, 'POST', $outro, ['X-Webhook-Event: whatsapp.message.failed', 'X-Webhook-Signature: ' . $assinar($outro)]);
    verificar('webhook: evento de outro número do projeto é ignorado', $entrega($idE1), 'lida');

    $pergunta = (string) json_encode(['message' => ['id' => 'wamid.in-1', 'from' => '55' . $e7['celular'], 'type' => 'text', 'text' => ['body' => 'Qual é o horário das aulas?'],
        'kapso' => ['direction' => 'inbound']], 'conversation' => ['phone_number' => '+55' . $e7['celular']], 'phone_number_id' => $numeroId]);
    http($urlWebhook, 'POST', $pergunta, ['X-Webhook-Event: whatsapp.message.received', 'X-Webhook-Signature: ' . $assinar($pergunta)]);
    $respostaDe = static function (string $tel) use ($db): array {
        $stmt = $db->prepare('SELECT resposta FROM mcp_recuperacao_whatsapp WHERE telefone_hash = ? AND resposta IS NOT NULL');
        $stmt->execute([mcp_rw_hmac('t:55' . $tel)]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    };
    verificar('resposta comum: guardada para a secretaria, sem bloqueio', [$respostaDe($e7['celular']), mcp_avisos_bloqueado('whatsapp', '55' . $e7['celular'])], [['Qual é o horário das aulas?'], false]);
    $naoQuero = (string) json_encode(['message' => ['id' => 'wamid.in-2', 'from' => '55' . $e1['celular'], 'type' => 'button', 'button' => ['text' => 'Não tenho interesse', 'payload' => 'Não tenho interesse'],
        'context' => ['id' => $idE1], 'kapso' => ['direction' => 'inbound']], 'phone_number_id' => $numeroId]);
    http($urlWebhook, 'POST', $naoQuero, ['X-Webhook-Event: whatsapp.message.received', 'X-Webhook-Signature: ' . $assinar($naoQuero)]);
    $bloq = $db->prepare("SELECT origem FROM mcp_avisos_bloqueios WHERE canal = 'whatsapp' AND destino_hash = ?");
    $bloq->execute([mcp_avisos_hash('whatsapp', '55' . $e1['celular'])]);
    verificar('"Não tenho interesse": bloqueia (origem recuperacao) e guarda a resposta', [$bloq->fetchColumn(), $respostaDe($e1['celular'])], ['recuperacao', ['Não tenho interesse']]);
    $tardio = (string) json_encode(['message' => ['id' => 'wamid.in-3', 'from' => '55' . $s12['telefone'], 'type' => 'text', 'text' => ['body' => 'PARAR'], 'timestamp' => (string) (time() - 3 * 86400),
        'kapso' => ['direction' => 'inbound']], 'phone_number_id' => $numeroId]);
    http($urlWebhook, 'POST', $tardio, ['X-Webhook-Event: whatsapp.message.received', 'X-Webhook-Signature: ' . $assinar($tardio)]);
    verificar('"PARAR" reentregue 3 dias depois ainda bloqueia', mcp_avisos_bloqueado('whatsapp', '55' . $s12['telefone']), true);

    // O cadastro feito com a escola fora (18 h depois da base) recebeu a 1ª só 8 h depois do cadastro (a escola e depois a
    // Kapso fora seguraram as rodadas): a 2ª vem 21 h depois dela, e não com 24 h do cadastro.
    mcp_rw_rodar($A + 46 * 3600); // 8h de Brasília: 28 h do cadastro, mas só 20 h depois da 1ª
    $s14antes = $etapasPara($s14['telefone']);
    mcp_rw_rodar($A + 47 * 3600);
    verificar('a 2ª respeita 21 h depois da 1ª (1ª atrasada pela escola e pela Kapso fora)', [$s14antes, $etapasPara($s14['telefone'])],
        [['rec_inscricao_1_v2'], ['rec_inscricao_1_v2', 'rec_inscricao_2']]);

    // ------------------------------------------------------------------------- etapa 3
    $r = mcp_rw_rodar($A + 72 * 3600);
    verificar('76 h da âncora, mas só 46 h depois da 2ª: espera', $etapasPara($e7['celular']), ['rec_inscricao_1_v2', 'rec_inscricao_2']);
    $r = mcp_rw_rodar($A + 74 * 3600);
    verificar('3ª mensagem sai; quem disse "Não tenho interesse" não recebe', [$etapasPara($e7['celular']), $etapasPara($e1['celular'])],
        [['rec_inscricao_1_v2', 'rec_inscricao_2', 'rec_inscricao_3'], ['rec_inscricao_1_v2', 'rec_inscricao_2']]);
    verificar('3ª mensagem: só o botão de URL nos componentes (a resposta rápida não leva parâmetro)',
        array_map(static fn(array $c): string => $c['type'] . ':' . ($c['sub_type'] ?? '') . ':' . ($c['index'] ?? ''), $mensagensPara($e7['celular'])[2]['corpo']['template']['components'] ?? []),
        ['body::', 'button:url:0']);
    $antes = count(falso('kapso'));
    mcp_rw_rodar($A + 98 * 3600);
    mcp_rw_rodar($A + 120 * 3600);
    $porEtapa = [];
    foreach (falso('kapso') as $x) {
        $k = ($x['corpo']['to'] ?? '') . '|' . ($x['corpo']['template']['name'] ?? '');
        $porEtapa[$k] = ($porEtapa[$k] ?? 0) + 1;
    }
    $depois = array_slice(falso('kapso'), $antes);
    verificar('depois da 3ª, nada mais para quem completou; nenhuma etapa repetiu para ninguém',
        [count(array_filter($depois, static fn(array $x): bool => ($x['corpo']['to'] ?? '') === '55' . $e7['celular'])), max($porEtapa), $etapasPara($s11['telefone'])],
        [0, 1, ['rec_inscricao_1_v2', 'rec_inscricao_2', 'rec_inscricao_3']]);
    $uniao = array_merge($etapasPara($e3['celular']), $etapasPara($s3['telefone']));
    sort($uniao);
    verificar('a pessoa da união recebeu cada etapa uma vez só, somando os dois celulares', $uniao, ['rec_inscricao_1_v2', 'rec_inscricao_2', 'rec_inscricao_3']);
    verificar('grupo de controle: depois de todas as rodadas, nenhuma mensagem e só a linha etapa 0', [count($mensagensPara($s15['telefone'])), (function () use ($db, $s15): array {
        $stmt = $db->prepare('SELECT etapa FROM mcp_recuperacao_whatsapp WHERE telefone_hash = ?');
        $stmt->execute([mcp_rw_hmac('t:55' . $s15['telefone'])]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    })()], [0, [0]]);

    // ------------------------------------------------------------------------- métricas
    $agoraM = $A + 74 * 3600;
    $cargaM = mcp_rw_carregar($agoraM);
    $m = mcp_rw_metricas($cargaM['pessoas'], mcp_rw_linhas(), $agoraM, null, $cargaM['pelo_link']);
    verificar('métricas: Maria pagou depois da 1ª → recuperada (site); taxa', [$m['recuperadas'] >= 1, $m['origens']['site']['recuperadas'] ?? 0, $m['taxa'] !== null], [true, 1, true]);
    verificar('métricas: respondeu e não quer', [$m['responderam'], $m['nao_querem']], [2, 1]);
    verificar('métricas: entregues e lidas', [$m['entregues'], $m['lidas']], [1, 1]);
    $diaMaria = gmdate('Y-m-d', $A + 2 * 3600 - 3 * 3600);
    verificar('métricas: 14 dias, com a recuperação no dia do pagamento', [count($m['dias']), $m['dias'][$diaMaria]['recuperacoes'] ?? null], [14, 1]);
    verificar('métricas: recuperação por mensagem (Maria pagou depois da 1ª) e por modelo', [$m['por_mensagem'], $m['por_modelo']['rec_inscricao_1_v2']['recuperadas'] ?? null],
        [[1 => 1, 2 => 0, 3 => 0], 1]);
    verificar('métricas: pelo link da mensagem (utm_campaign rec1 no pagamento do site)', $m['pelo_link'], ['rec1' => 1, 'rec2' => 0, 'rec3' => 0, 'rec3cupom' => 0]);
    verificar('métricas: mediana de 2 h entre a 1ª mensagem e o pagamento', $m['mediana_horas'], 2.0);
    verificar('métricas: grupo de controle (1 pessoa, pagou sozinha) × quem recebeu, e o efeito em pontos percentuais',
        [$m['controle'], $m['tratamento']['pagaram'] === $m['recuperadas'], $m['tratamento']['pessoas'] === $m['receberam'] && $m['receberam'] > 1,
            $m['efeito_pp'] !== null && abs($m['efeito_pp'] - ($m['tratamento']['taxa'] - 1.0) * 100) < 1e-9],
        [['pessoas' => 1, 'pagaram' => 1, 'taxa' => 1.0], true, true, true]);
    verificar('métricas: a linha do controle não conta como mensagem nem como falha', [array_sum($m['etapas']), $m['falhas']],
        [count(array_filter(mcp_rw_linhas(), static fn(array $l): bool => $l['status'] === 'enviado')), count(array_filter(mcp_rw_linhas(), static fn(array $l): bool => $l['status'] === 'falhou'))]);
    verificar('métricas: ninguém da lista tem CPF ou celular inteiro', (bool) array_filter($m['lista'], static fn(array $p): bool => preg_match('/\d{8,}/', $p['celular'] . $p['nome']) === 1), false);

    // ------------------------------------------------------------------------- teto por rodada
    $B = $A + 100 * 86400;
    $novos = [];
    for ($i = 0; $i < 41; $i++) {
        $novos[] = $criar("lote$i", $B - 4 * 3600);
    }
    $r = mcp_rw_rodar($B);
    $r2 = mcp_rw_rodar($B + 3600);
    verificar('teto: 40 na rodada, o 41º na seguinte', [$r['enviados'], $r2['enviados']], [40, 1]);

    // ------------------------------------------------------------------------- erros que não são de quem recebe
    $C = $A + 200 * 86400;
    $limite = $criar('limite', $C - 5 * 3600, ['telefone' => $celular('1304')]);
    $depoisLimite = $criar('depoislimite', $C - 4 * 3600);
    $r = mcp_rw_rodar($C);
    $linhasDe = static function (string $tel) use ($db): array {
        $stmt = $db->prepare('SELECT etapa, status, erro FROM mcp_recuperacao_whatsapp WHERE telefone_hash = ? ORDER BY etapa');
        $stmt->execute([mcp_rw_hmac('t:55' . $tel)]);
        return $stmt->fetchAll();
    };
    verificar('limite da Meta (400/130429): a rodada para e a etapa não é gasta', [$r['enviados'], $r['falhas'], str_contains((string) $r['motivo'], '130429'),
        $linhasDe($limite['telefone']), count($mensagensPara($depoisLimite['telefone']))], [0, 1, true, [], 0]);
    $db->prepare("UPDATE mcp_inscricoes SET status = 'pago', pago_em = ? WHERE id = ?")->execute([$quando($C), $limite['id']]);
    mcp_rw_rodar($C + 3600);
    verificar('limite passado: a próxima rodada manda', $etapasPara($depoisLimite['telefone']), ['rec_inscricao_1_v2']);

    $D = $A + 300 * 86400;
    controle('modelos', ['pendente' => 'rec_inscricao_1_v2']);
    $pendente = $criar('modelopendente', $D - 4 * 3600);
    $antes = count(falso('kapso'));
    $r = mcp_rw_rodar($D);
    verificar('modelo sem aprovação: não tenta, não gasta a etapa e diz qual', [$r['enviados'], count(falso('kapso')) - $antes, str_contains((string) $r['motivo'], 'rec_inscricao_1_v2'),
        $linhasDe($pendente['telefone'])], [0, 0, true, []]);
    controle('modelos', []);
    mcp_rw_rodar($D + 3600);
    verificar('modelo aprovado: sai na rodada seguinte', $etapasPara($pendente['telefone']), ['rec_inscricao_1_v2']);

    $E = $A + 400 * 86400;
    $gateway = $criar('gateway', $E - 5 * 3600, ['telefone' => $celular('5020')]);
    $depoisGateway = $criar('depoisgateway', $E - 4 * 3600);
    $r = mcp_rw_rodar($E);
    $lg = $linhasDe($gateway['telefone']);
    verificar('502 do gateway: conta como enviada (pode ter saído), não repete e a rodada para', [$r['enviados'], $lg[0]['status'] ?? '', str_contains((string) ($lg[0]['erro'] ?? ''), 'pode ter saído'),
        count($mensagensPara($depoisGateway['telefone']))], [1, 'enviado', true, 0]);
    mcp_rw_rodar($E + 3600);
    verificar('depois do 502: o próximo recebe, e o do 502 não recebe a 1ª de novo', [$etapasPara($depoisGateway['telefone']), count($mensagensPara($gateway['telefone']))],
        [['rec_inscricao_1_v2'], 1]);

    $F = $A + 500 * 86400;
    $cheio = $db->prepare("INSERT INTO mcp_recuperacao_whatsapp (pessoa_chave, origem, etapa, curso_nome, telefone_hash, status, criado_em, atualizado_em) VALUES (?, 'site', 1, 'x', ?, 'enviado', ?, ?)");
    for ($i = 0; $i < MCP_RW_POR_DIA; $i++) {
        $cheio->execute([hash('sha256', "cheio-$i-$sufixo"), hash('sha256', "tel-$i-$sufixo"), $quando($F - 1800), $quando($F - 1800)]);
    }
    $teto = $criar('tetodiario', $F - 4 * 3600);
    $r = mcp_rw_rodar($F);
    verificar('teto de 200 em 24 h: nada sai e diz o motivo', [$r['enviados'], $r['motivo'], count($mensagensPara($teto['telefone']))], [0, 'teto de 200 mensagens em 24 h', 0]);
    $r = mcp_rw_rodar($F + 23 * 3600); // 9h do dia seguinte: as 200 saíram há 23h30, ainda dentro das 24 h
    $r2 = mcp_rw_rodar($F + 24 * 3600);
    verificar('teto: libera quando as 200 passam de 24 h', [$r['enviados'], $etapasPara($teto['telefone'])], [0, ['rec_inscricao_1_v2']]);

    // ------------------------------------------------------------------------- cupom na 3ª (Primeiros Socorros Básico)
    $G = $A + 600 * 86400;
    $ps = ['curso_slug' => 'primeiros-socorros-basico', 'curso_nome' => 'Primeiros Socorros Básico'];
    $cupomG = mcp_cupom('NOV15', 'primeiros-socorros-basico', $G);
    $vagas = isset($cupomG['erro']) ? 0 : mcp_cupom_restantes($cupomG, $G);
    $diasSemana = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
    $dataTurma = $diasSemana[(int) gmdate('w', $turmaInicio - 3 * 3600)] . ', ' . gmdate('d/m', $turmaInicio - 3 * 3600);
    verificar('cupom: NOV15 vale com vaga e turma aberta (data da turma por extenso curta); depois do prazo da turma, não',
        [$vagas > 0, mcp_rw_oferta_cupom($G), mcp_rw_oferta_cupom($turmaAte + 3600)], [true, ['codigo' => 'NOV15', 'data' => $dataTurma], null]);
    $psA = $criar('pscupom', $G - 2 * 3600, $ps + ['nome' => 'BRUNO ALVES']);
    $outroG = $criar('outrocurso', $G - 2 * 3600);
    mcp_rw_rodar($G);
    mcp_rw_rodar($G + 22 * 3600); // 2ª: 24 h do cadastro
    mcp_rw_rodar($G + 70 * 3600); // 3ª: 72 h do cadastro e 48 h depois da 2ª
    verificar('cupom: a 3ª de Primeiros Socorros é o ps_cupom_oferta; outro curso continua no rec_inscricao_3', [$etapasPara($psA['telefone']), $etapasPara($outroG['telefone'])],
        [['rec_inscricao_1_v2', 'rec_inscricao_2', 'ps_cupom_oferta'], ['rec_inscricao_1_v2', 'rec_inscricao_2', 'rec_inscricao_3']]);
    verificar('cupom: corpo exato do ps_cupom_oferta (nome, cupom, data da turma e o botão de URL com o cupom)', $mensagensPara($psA['telefone'])[2]['corpo']['template'] ?? [], [
        'name' => 'ps_cupom_oferta', 'language' => ['code' => 'pt_BR'], 'components' => [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Bruno'], ['type' => 'text', 'text' => 'NOV15'], ['type' => 'text', 'text' => $dataTurma]]],
            ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text',
                'text' => 'matricula-cursos-presenciais/checkout/?curso=primeiros-socorros-basico&via=agenda&cupom=NOV15&utm_source=whatsapp&utm_medium=recuperacao&utm_campaign=rec3cupom']]],
        ],
    ]);
    $modelosDe = static function (string $tel) use ($db): array {
        $stmt = $db->prepare('SELECT modelo FROM mcp_recuperacao_whatsapp WHERE telefone_hash = ? ORDER BY etapa');
        $stmt->execute([mcp_rw_hmac('t:55' . $tel)]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    };
    verificar('cupom: o modelo que saiu fica gravado em cada linha', $modelosDe($psA['telefone']), ['rec_inscricao_1_v2', 'rec_inscricao_2', 'ps_cupom_oferta']);

    $H = $G + 100 * 86400;
    $psB = $criar('psesgotado', $H - 2 * 3600, $ps);
    mcp_rw_rodar($H);
    mcp_rw_rodar($H + 22 * 3600);
    // As vagas do cupom acabam antes da 3ª: inscrições pagas com o NOV15 (contam em qualquer data).
    $vagas = mcp_cupom_restantes(mcp_cupom('NOV15', 'primeiros-socorros-basico', $H), $H);
    for ($i = 0; $i < $vagas; $i++) {
        $criar("cupompago$i", $A - 300 * 86400, $ps + ['status' => 'pago', 'pago_em' => $quando($A - 300 * 86400), 'cupom' => 'NOV15']);
    }
    verificar('cupom esgotado: a oferta sai do ar', [mcp_cupom_restantes(mcp_cupom('NOV15', 'primeiros-socorros-basico', $H), $H), mcp_rw_oferta_cupom($H + 70 * 3600)], [0, null]);
    mcp_rw_rodar($H + 70 * 3600);
    verificar('cupom esgotado: a 3ª de Primeiros Socorros volta a ser o rec_inscricao_3', [$etapasPara($psB['telefone']), $modelosDe($psB['telefone'])],
        [['rec_inscricao_1_v2', 'rec_inscricao_2', 'rec_inscricao_3'], ['rec_inscricao_1_v2', 'rec_inscricao_2', 'rec_inscricao_3']]);
    $mH = mcp_rw_metricas(mcp_rw_carregar($H + 70 * 3600)['pessoas'], mcp_rw_linhas(), $H + 70 * 3600);
    verificar('métricas por modelo: o ps_cupom_oferta aparece com as suas enviadas', $mH['por_modelo']['ps_cupom_oferta']['enviadas'] ?? null, 1);

    // ------------------------------------------------------------------------- painel
    $url = "http://127.0.0.1:$portaSite/matricula-cursos-presenciais/api/recuperacao.php";
    $cookie = 'Cookie: mcp_painel=' . $_COOKIE[MCP_PAINEL_COOKIE];
    [$st, $cab, $html] = http($url);
    verificar('painel sem sessão: 200 só com o link de entrada', [$st, str_contains($html, 'Entrar pelo Portal da secretaria'), str_contains($html, 'No fluxo agora'), str_contains($html, 'Pessoa Teste')],
        [200, true, false, false]);
    verificar('painel: noindex e no-store', [$cab['x-robots-tag'] ?? '', $cab['cache-control'] ?? ''], ['noindex, nofollow', 'no-store']);
    [$st] = http($url, 'POST', ['acao' => 'ligar_webhook', 't' => mcp_painel_csrf($quem, 'rw_webhook', 0)]);
    verificar('painel sem sessão: POST 403 e nada vai à Kapso', [$st, count(falso('webhooks'))], [403, 0]);
    [$st, , $html] = http($url, 'GET', null, [$cookie]);
    verificar('painel com sessão: situação (4 modelos aprovados, escola ok, webhook desligado) e números',
        [$st, substr_count($html, '<td class="n">Aprovado</td>'), str_contains($html, 'Plataforma da escola respondendo'), str_contains($html, 'Ligar o recebimento de respostas'), str_contains($html, 'esperam a próxima mensagem') || str_contains($html, 'espera a próxima mensagem'), str_contains($html, 'Taxa de recuperação')],
        [200, 4, true, true, true, true]);
    verificar('painel: os 4 modelos usados, o cupom e as métricas novas (por mensagem, tempo até pagar, controle × quem recebeu)', [
        str_contains($html, 'rec_inscricao_1_v2 (1ª mensagem)'), str_contains($html, 'rec_inscricao_2 (2ª mensagem)'), str_contains($html, 'rec_inscricao_3 (3ª mensagem)'), str_contains($html, 'ps_cupom_oferta (3ª de Primeiros Socorros'),
        str_contains($html, 'rec_inscricao_1 ('), str_contains($html, 'Cupom NOV15'), str_contains($html, 'Qual mensagem trouxe o pagamento'), str_contains($html, 'Pelo link do botão'),
        str_contains($html, 'Tempo até pagar'), str_contains($html, 'Efeito da mensagem'), str_contains($html, 'menos o grupo de controle'), str_contains($html, 'dezenas em cada grupo'),
        str_contains($html, '1 h, 24 h e 72 h'),
    ], [true, true, true, true, false, true, true, true, true, true, true, true, true]);
    verificar('painel: a chave da Kapso nunca aparece', str_contains($html, $chaveKapso), false);
    verificar('painel: seção "Próximo disparo" com a hora da rodada e a prévia do texto', [str_contains($html, 'Próximo disparo'), str_contains($html, 'class="hora"'),
        str_contains($html, 'Ninguém para esta rodada') || (str_contains($html, 'class="previa"') && str_contains($html, 'rec_inscricao_'))], [true, true, true]);
    // A hora da próxima rodada: minuto 7, dentro das 8h–20h de Brasília (UTC−3).
    verificar('próxima rodada: 10h30 → 11h07; 19h30 → 8h07 do dia seguinte; 8h03 → 8h07', [
        gmdate('Y-m-d H:i', mcp_rw_proxima_rodada(gmmktime(13, 30, 0, 10, 30, 2026))),
        gmdate('Y-m-d H:i', mcp_rw_proxima_rodada(gmmktime(22, 30, 0, 10, 30, 2026))),
        gmdate('Y-m-d H:i', mcp_rw_proxima_rodada(gmmktime(11, 3, 0, 10, 30, 2026))),
    ], ['2026-10-30 14:07', '2026-10-31 11:07', '2026-10-30 11:07']);
    $previa = mcp_rw_previa('rec_inscricao_3', ['Ana', 'Bombeiro Civil']);
    verificar('prévia: nome, curso e os botões do modelo 3', [str_starts_with($previa, 'Olá, Ana.'), str_contains($previa, 'curso Bombeiro Civil'),
        str_contains($previa, '[Não tenho interesse]'), str_contains($previa, '{')], [true, true, true, false]);
    $primeiro = mcp_rw_metricas(mcp_rw_carregar()['pessoas'], mcp_rw_linhas(), time())['lista'][0]['celular'] ?? '?';
    verificar('painel: lista com o celular mascarado, nenhum número inteiro na página', [str_contains($html, $primeiro),
        (bool) array_filter($telefones, static fn(string $t): bool => str_contains($html, substr($t, 2)))], [true, false]);
    [$st] = http($url, 'POST', ['acao' => 'ligar_webhook'], [$cookie]);
    verificar('painel: botão sem o token → 403', [$st, count(falso('webhooks'))], [403, 0]);
    [$st, $cab] = http($url, 'POST', ['acao' => 'ligar_webhook', 't' => mcp_painel_csrf($quem, 'rw_webhook', 0)], [$cookie]);
    $wh = falso('webhooks')[0] ?? [];
    verificar('painel: "Ligar o recebimento de respostas" cria o webhook na Kapso com o segredo do site', [$st, $cab['location'] ?? '', $wh['metodo'] ?? '',
        $wh['corpo']['whatsapp_webhook']['url'] ?? '', $wh['corpo']['whatsapp_webhook']['secret_key'] ?? '', $wh['corpo']['whatsapp_webhook']['kind'] ?? '',
        in_array('whatsapp.message.received', $wh['corpo']['whatsapp_webhook']['events'] ?? [], true)],
        [303, 'recuperacao.php?webhook=ok', 'POST', $urlWebhook, mcp_segredo('kapso_webhook'), 'kapso', true]);
    [, , $html] = http($url . '?webhook=ok', 'GET', null, [$cookie]);
    verificar('painel: depois de ligar, "Ligado" e sem o botão', [str_contains($html, 'Recebimento de respostas ligado'), str_contains($html, 'Ligar o recebimento de respostas</button>')], [true, false]);
    $webhookCom = static fn(array $mudar): array => ['data' => [$mudar + ['id' => 'wh-9', 'url' => $urlWebhook, 'active' => true, 'events' => MCP_RW_EVENTOS_WEBHOOK]]];
    foreach (['outro segredo' => ['secret_key' => 'segredo-de-outra-base'], 'sem a entrega e a leitura' => ['events' => ['whatsapp.message.received']]] as $caso => $mudar) {
        controle('webhooks', $webhookCom($mudar));
        $db->exec("DELETE FROM mcp_chaves WHERE nome = 'rw_cache_webhook'");
        [, , $html] = http($url, 'GET', null, [$cookie]);
        verificar("painel: webhook com $caso aparece desacertado, com o botão, sem mostrar segredo", [str_contains($html, 'desacertado na Kapso'), str_contains($html, 'Ligar o recebimento de respostas</button>'),
            str_contains($html, 'segredo-de-outra-base'), str_contains($html, mcp_segredo('kapso_webhook'))], [true, true, false, false]);
    }
    http($url, 'POST', ['acao' => 'ligar_webhook', 't' => mcp_painel_csrf($quem, 'rw_webhook', 0)], [$cookie]);
    $wh = array_slice(falso('webhooks'), -1)[0] ?? [];
    [, , $html] = http($url, 'GET', null, [$cookie]);
    verificar('painel: o botão acerta o webhook existente (PATCH com o segredo e os eventos) e volta a "Ligado"', [$wh['metodo'] ?? '', $wh['corpo']['whatsapp_webhook']['secret_key'] ?? '',
        $wh['corpo']['whatsapp_webhook']['events'] ?? [], str_contains($html, 'Recebimento de respostas ligado')], ['PATCH', mcp_segredo('kapso_webhook'), MCP_RW_EVENTOS_WEBHOOK, true]);

    // ------------------------------------------------------------------------- sem configuração
    // Só a configuração SEM a Kapso roda pela linha de comando: com ela, a rodada usaria a hora de agora.
    $antes = count(falso('kapso'));
    $saida = [];
    exec('MCP_CONFIG_ARQUIVO=' . escapeshellarg($configSem) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($raiz . '/site/matricula-cursos-presenciais/api/lembretes.php') . ' 2>/dev/null', $saida);
    $linha = array_values(array_filter($saida, static fn(string $l): bool => str_contains($l, 'recuperação por WhatsApp')))[0] ?? '';
    verificar('sem a configuração da Kapso: lembretes.php não manda nada e diz o que falta',
        [str_contains($linha, 'sem configuração (WHATSAPP_KAPSO_CHAVE, WHATSAPP_KAPSO_NUMERO_ID)'), count(falso('kapso')) - $antes], [true, 0]);

    $erros = trim((string) file_get_contents($logErros));
    verificar('servidor sem erro de PHP no log', $erros, '');
} finally {
    foreach ([$servidor, $servidorFalso] as $s) {
        if (is_resource($s)) {
            proc_terminate($s);
        }
    }
    limpar($db, $padraoEmail, $quem, $telefones);
    foreach ([$config, $configSem, $vazio, $ofertaTeste] as $arq) {
        @unlink($arq);
    }
    array_map('unlink', glob("$falso/*") ?: []);
    @rmdir($falso);
}
echo "\n$total verificações, $falhas falha(s).\n";
exit($falhas > 0 ? 1 : 0);
