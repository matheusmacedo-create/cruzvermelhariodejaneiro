#!/usr/bin/env php
<?php
/**
 * Teste da recuperação por E-MAIL (api/lib/recuperacao_email.php, o descadastro em api/avisos.php?rw=, o painel
 * api/recuperacao.php e a rodada em api/lembretes.php), contra um MariaDB LOCAL e um servidor falso local que faz de
 * Resend, de Kapso e de PostgREST da escola (anota tudo; nada sai de verdade). Cobre:
 *   - régua do e-mail: 1 h / 24 h / 72 h da âncora, intervalo mínimo de 21 h e 48 h, só das 8h às 20h de Brasília, nunca
 *     repete etapa; quem paga entre as etapas para; e-mail recusado pela Resend fica "falhou" e a sequência para;
 *   - canais independentes: sem celular → só e-mail; sem e-mail → só WhatsApp; com os dois → os dois;
 *   - grupo de controle: não recebe e-mail (só a linha etapa 0), e conta uma vez só nas métricas;
 *   - lembrete do PIX: quem já recebeu pula o 1º e-mail ('pulado_pix', na hora do lembrete, que conta para o intervalo
 *     da 2ª); quem ainda pode receber espera por ele, e recebe o 1º e-mail se o lembrete não vier;
 *   - tetos: 10 por rodada, 30 em 24 h, e nada sai se o resto do site já mandou 60 em 24 h; 429 e duas recusas seguidas
 *     da Resend não gastam a etapa;
 *   - corpo: assunto, botão com os caminhos do WhatsApp e utm_source=email, cupom NOV15 na 3ª de Primeiros Socorros com a
 *     data da turma (sem preço), "nossos cursos" sem curso, "Não quero mais receber", List-Unsubscribe de um clique e
 *     responder-para o contato;
 *   - descadastro (api/avisos.php?rw=): o GET só mostra a página; o POST de um clique bloqueia e-mail E WhatsApp (origem
 *     'recuperacao'), e a pessoa sai dos dois canais; "Não tenho interesse" no WhatsApp também para o e-mail;
 *   - sem RESEND_API_KEY nada sai (nem pelo mail()) e a rotina diz o motivo;
 *   - painel e métricas: e-mails da próxima rodada (assunto e prévia), enviados por etapa, pulados pelo PIX, "pelo link do
 *     e-mail", recuperadas depois da 1ª mensagem de qualquer canal, os dois canais na lista, e-mail sempre mascarado.
 *
 * As datas ficam 400 dias no futuro, às 10h de Brasília, como no teste do WhatsApp. Tudo o que o teste cria é apagado no fim.
 *
 * Uso: MCP_CONFIG_ARQUIVO=/caminho/config-teste.php php scripts/testar_recuperacao_email_integracao.php
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

$portaSite = 20100 + random_int(0, 249);
$portaFalso = $portaSite + 250;
$falso = sys_get_temp_dir() . '/mcp-rwe-falso-' . bin2hex(random_bytes(4));
mkdir($falso);
$quem = 'recuperacao-email@exemplo.org';
$base0 = [
    'PAINEL_EMAILS' => $quem, 'EMAIL_SECRETARIA' => '', 'SITE_URL' => "http://127.0.0.1:$portaSite", 'TESTE_CPFS' => [],
    'EMAIL_CONTATO' => 'contato@exemplo.org', 'EMAIL_REMETENTE' => 'Cruz Vermelha Brasileira Rio de Janeiro <matricula@info.exemplo.org>',
    'RESEND_API_URL' => "http://127.0.0.1:$portaFalso/emails",
    'ESCOLA_API_URL' => "http://127.0.0.1:$portaFalso/rest/v1/rpc/matricula_rapida", 'ESCOLA_API_TOKEN' => 'chave-escola-falsa',
    'WHATSAPP_KAPSO_BASE' => "http://127.0.0.1:$portaFalso",
];
function config_teste(array $extra): string
{
    global $configOriginal;
    $arq = tempnam(sys_get_temp_dir(), 'mcp-rwe-cfg-');
    file_put_contents($arq, '<?php return ' . var_export($extra, true) . ' + (require ' . var_export($configOriginal, true) . ');');
    return $arq;
}
$config = config_teste($base0 + ['RESEND_API_KEY' => 're_teste', 'WHATSAPP_KAPSO_CHAVE' => 'kapso-chave-de-teste-123', 'WHATSAPP_KAPSO_NUMERO_ID' => '1112223334']);
$configSem = config_teste($base0 + ['RESEND_API_KEY' => '']);
$vazio = tempnam(sys_get_temp_dir(), 'mcp-rwe-vazio-');
file_put_contents($vazio, '<?php return [];');
putenv("MCP_CONFIG_ARQUIVO=$config");
putenv("MCP_CONFIG_ESCOLA_ARQUIVO=$vazio");
putenv("MCP_CONFIG_WHATSAPP_ARQUIVO=$vazio");
putenv('MCP_CONFIG_RECUPERACAO_ARQUIVO=' . sys_get_temp_dir() . '/mcp-rwe-sem-importados.php');
putenv('MCP_CATALOGO_ARQUIVO=' . $raiz . '/site/matricula-cursos-presenciais/cursos.json');
// Uma turma de Primeiros Socorros Básico aberta durante todo o teste: é ela que libera o cupom do 3º e-mail.
$turmaInicio = (int) strtotime(gmdate('Y-m-d', time() + 1400 * 86400) . ' 12:00:00 UTC');
$turmaAte = $turmaInicio - 86400;
$ofertaTeste = tempnam(sys_get_temp_dir(), 'mcp-rwe-oferta-');
file_put_contents($ofertaTeste, json_encode(['parcelado_no_ar' => false, 'sem_turma' => [], 'turmas' => [[
    'curso' => 'primeiros-socorros-basico', 'id_escola' => 'turma-teste-rwe', 'inicio' => gmdate('Y-m-d\TH:i:s', $turmaInicio - 3 * 3600) . '-03:00',
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

// ----------------------------------------------------------------------------- servidor falso (Resend, Kapso e escola)
$roteador = "$falso/roteador.php";
file_put_contents($roteador, <<<'PHP'
<?php
// Servidor falso: anota cada pedido num .jsonl e responde como a Resend, a Kapso e o PostgREST da escola responderiam.
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
if ($caminho === '/emails' && $metodo === 'POST') {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer re_teste') { http_response_code(401); echo '{"message":"chave"}'; return true; }
    $d = json_decode($corpo, true) ?: [];
    $c = $controle('resend');
    if (!empty($c['limite'])) { http_response_code(429); echo '{"message":"Too many requests"}'; return true; }
    if (!empty($c['fora'])) { http_response_code(500); echo '{"message":"erro interno (teste)"}'; return true; }
    if (str_contains((string) ($d['to'][0] ?? ''), 'recusa')) { http_response_code(422); echo '{"message":"Invalid `to` field"}'; return true; }
    $n = $anotar('emails.jsonl', $d);
    echo json_encode(['id' => "re_teste_$n"]);
    return true;
}
if (str_starts_with($caminho, '/meta/') || str_starts_with($caminho, '/platform/')) {
    if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== 'kapso-chave-de-teste-123') { http_response_code(401); echo '{"error":"Invalid API key"}'; return true; }
}
if ($caminho === '/meta/whatsapp/v24.0/1112223334/messages' && $metodo === 'POST') {
    $d = json_decode($corpo, true) ?: [];
    $n = $anotar('kapso.jsonl', ['corpo' => $d]);
    echo json_encode(['messaging_product' => 'whatsapp', 'messages' => [['id' => "wamid.teste-$n"]]]);
    return true;
}
if ($caminho === '/platform/v1/whatsapp/phone_numbers/1112223334' && $metodo === 'GET') {
    echo '{"data":{"id":"1112223334","business_account_id":"998877665544"}}';
    return true;
}
if ($caminho === '/meta/whatsapp/v24.0/998877665544/message_templates') {
    $lista = [];
    foreach (['rec_inscricao_1_v2', 'rec_inscricao_2', 'rec_inscricao_3', 'ps_cupom_oferta'] as $nome) {
        $lista[] = ['name' => $nome, 'status' => 'APPROVED', 'language' => 'pt_BR'];
    }
    echo json_encode(['data' => $lista]);
    return true;
}
if (str_starts_with($caminho, '/platform/v1/whatsapp/phone_numbers/1112223334/webhooks')) {
    echo '{"data":[]}';
    return true;
}
if ($caminho === '/rest/v1/Usuario' && $metodo === 'GET') {
    if (($_SERVER['HTTP_APIKEY'] ?? '') !== 'chave-escola-falsa') { http_response_code(401); echo '{}'; return true; }
    $c = $controle('escola');
    if (!empty($c['fora'])) { http_response_code(403); echo '{}'; return true; }
    $usuarios = $c['usuarios'] ?? [];
    if (isset($_GET['criadoEm'])) {
        $desde = substr((string) $_GET['criadoEm'], 4);
        $usuarios = array_values(array_filter($usuarios, static fn(array $u): bool => ($u['papel'] ?? '') === 'ALUNO' && $u['criadoEm'] >= $desde));
    } elseif (isset($_GET['or']) && preg_match('/^\((cpfCnpj|email|celular)\.in\.\((.*)\)\)$/', (string) $_GET['or'], $m)) {
        $valores = str_getcsv($m[2], ',', '"', '\\');
        $usuarios = array_values(array_filter($usuarios, static fn(array $u): bool => in_array((string) ($u[$m[1]] ?? ''), $valores, true)));
    } else {
        http_response_code(400); echo '{"message":"filtro desconhecido"}'; return true;
    }
    echo json_encode(array_slice($usuarios, (int) ($_GET['offset'] ?? 0), (int) ($_GET['limit'] ?? 1000)), JSON_UNESCAPED_UNICODE);
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
$padraoEmail = '^rwe-[a-z0-9]+-[0-9a-f]{6}@exemplo[.]org$';
$telefones = [];
$emails = [];
// A base do teste: daqui a 400 dias, 13h UTC (10h de Brasília).
$A = (int) strtotime(gmdate('Y-m-d', time() + 400 * 86400) . ' 13:00:00 UTC');

function limpar(PDO $db, string $padraoEmail, array $telefones, array $emails): void
{
    $stmt = $db->prepare('SELECT id FROM mcp_inscricoes WHERE email REGEXP ?');
    $stmt->execute([$padraoEmail]);
    $ids = implode(', ', array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    if ($ids !== '') {
        $db->exec("DELETE FROM mcp_eventos WHERE inscricao_id IN ($ids)");
        $db->exec("DELETE FROM mcp_inscricoes WHERE id IN ($ids)");
    }
    // As linhas do teste ficam todas no futuro (a base é daqui a 400 dias).
    $linhas = $db->query('SELECT id FROM mcp_recuperacao_email WHERE criado_em > DATE_ADD(UTC_TIMESTAMP(), INTERVAL 300 DAY)')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($linhas as $id) {
        $db->prepare("DELETE FROM mcp_eventos WHERE tipo = 'recuperacao_descadastro' AND detalhe = ?")->execute(['e-mail #' . (int) $id]);
    }
    $db->exec('DELETE FROM mcp_recuperacao_email WHERE criado_em > DATE_ADD(UTC_TIMESTAMP(), INTERVAL 300 DAY)');
    $db->exec('DELETE FROM mcp_recuperacao_whatsapp WHERE criado_em > DATE_ADD(UTC_TIMESTAMP(), INTERVAL 300 DAY)');
    // A pessoa do painel tem o cadastro de agora (horas atrás): as linhas dela são apagadas pelos hashes.
    foreach ($telefones as $numero) {
        $db->prepare("DELETE FROM mcp_avisos_bloqueios WHERE canal = 'whatsapp' AND destino_hash = ?")->execute([mcp_avisos_hash('whatsapp', $numero)]);
    }
    foreach ($emails as $email) {
        $db->prepare("DELETE FROM mcp_avisos_bloqueios WHERE canal = 'email' AND destino_hash = ?")->execute([mcp_avisos_hash('email', $email)]);
        $db->prepare('DELETE FROM mcp_recuperacao_email WHERE email_hash = ?')->execute([mcp_avisos_hash('email', $email)]);
    }
    $db->exec("DELETE FROM mcp_chaves WHERE nome IN ('rw_cache_modelos', 'rw_cache_webhook')");
}
limpar($db, $padraoEmail, [], []);

/** Celular novo (11 dígitos), anotado para a limpeza. */
$celular = static function () use (&$telefones): string {
    $t = '2197' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
    $telefones[] = '55' . $t;
    return $t;
};
$quando = static fn(int $ts): string => gmdate('Y-m-d H:i:s', $ts);
$criar = static function (string $chave, int $criadoTs, array $campos = []) use ($db, $sufixo, $celular, $quando, &$emails): array {
    $linha = $campos + [
        'token' => mcp_token_novo(), 'cpf' => cpf_de_teste(), 'telefone' => $celular(), 'metodo' => 'pix',
        'curso_slug' => 'puncao-venosa', 'curso_nome' => 'Punção Venosa', 'nome' => "Pessoa Teste $chave",
        'email' => "rwe-$chave-$sufixo@exemplo.org", 'inscricao_centavos' => 9900, 'taxa_centavos' => 0, 'total_centavos' => 9900,
        'status' => 'pendente', 'escola_status' => 'nao_aplicavel', 'criado_em' => $quando($criadoTs), 'atualizado_em' => $quando($criadoTs), 'pago_em' => null,
    ];
    $emails[] = $linha['email'];
    $colunas = array_keys($linha);
    $db->prepare('INSERT INTO mcp_inscricoes (' . implode(', ', $colunas) . ') VALUES (' . implode(', ', array_fill(0, count($colunas), '?')) . ')')
        ->execute(array_values($linha));
    $linha['id'] = (int) $db->lastInsertId();
    return $linha;
};
$pagar = static function (array $i, int $ts, array $utm = []) use ($db, $quando): void {
    $db->prepare("UPDATE mcp_inscricoes SET status = 'pago', pago_em = ?, utm_source = ?, utm_campaign = ? WHERE id = ?")
        ->execute([$quando($ts), $utm[0] ?? null, $utm[1] ?? null, $i['id']]);
};
/** Os e-mails que o servidor falso recebeu para um endereço, na ordem. */
$emailsPara = static fn(string $email): array => array_values(array_filter(falso('emails'), static fn(array $x): bool => ($x['to'][0] ?? '') === $email));
$assuntosPara = static fn(string $email): array => array_map(static fn(array $x): string => (string) $x['subject'], $emailsPara($email));
$zapPara = static fn(string $tel): array => array_values(array_map(static fn(array $x): string => (string) $x['corpo']['template']['name'],
    array_filter(falso('kapso'), static fn(array $x): bool => ($x['corpo']['to'] ?? '') === '55' . $tel)));
$linhasDe = static function (string $email) use ($db): array {
    $stmt = $db->prepare('SELECT etapa, status, modelo FROM mcp_recuperacao_email WHERE email_hash = ? ORDER BY etapa');
    $stmt->execute([mcp_avisos_hash('email', $email)]);
    return array_map(static fn(array $l): string => $l['etapa'] . ':' . $l['status'] . ($l['modelo'] !== null ? ':' . $l['modelo'] : ''), $stmt->fetchAll());
};

$servidor = null;
$servidorFalso = null;
try {
    $nulo = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $servidorFalso = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaFalso", $roteador], $nulo, $tubos2, null, ['FALSO_DIR' => $falso, 'PATH' => (string) getenv('PATH')]);
    $logErros = tempnam(sys_get_temp_dir(), 'mcp-rwe-php-');
    $servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaSite", '-t', $raiz . '/site', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$logErros"], $nulo, $tubos, null, [
        'MCP_CONFIG_ARQUIVO' => $config, 'MCP_CONFIG_ESCOLA_ARQUIVO' => $vazio, 'MCP_CONFIG_WHATSAPP_ARQUIVO' => $vazio,
        'MCP_CONFIG_RECUPERACAO_ARQUIVO' => sys_get_temp_dir() . '/mcp-rwe-sem-importados.php',
        'MCP_CATALOGO_ARQUIVO' => getenv('MCP_CATALOGO_ARQUIVO'), 'MCP_OFERTA_ARQUIVO' => $ofertaTeste, 'PATH' => (string) getenv('PATH'),
    ]);
    foreach ([$portaSite, $portaFalso] as $porta) {
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $porta) === false; $i++) {
            usleep(100000);
        }
    }

    // ------------------------------------------------------------------------- regras puras
    verificar('régua do e-mail: 1º com 1 h; 2º com 24 h e 21 h depois do 1º; 3º com 72 h e 48 h depois do 2º', [
        mcp_rw_email_proxima(0, []),
        mcp_rw_email_proxima(0, [1 => ['status' => 'enviado', 'criado_ts' => 10 * 3600]]),
        mcp_rw_email_proxima(0, [1 => ['status' => 'enviado', 'criado_ts' => 3600], 2 => ['status' => 'enviado', 'criado_ts' => 30 * 3600]]),
    ], [['etapa' => 1, 'devida_em' => 3600], ['etapa' => 2, 'devida_em' => 31 * 3600], ['etapa' => 3, 'devida_em' => 78 * 3600]]);
    verificar('régua do e-mail: "pulado_pix" conta como o 1º (na hora do lembrete do PIX); "falhou" para; controle e depois do 3º, nada', [
        mcp_rw_email_proxima(0, [1 => ['status' => 'pulado_pix', 'criado_ts' => 5 * 3600]]),
        mcp_rw_email_proxima(0, [1 => ['status' => 'falhou', 'criado_ts' => 0]]),
        mcp_rw_email_proxima(0, [1 => ['status' => 'enviado', 'criado_ts' => 0], 2 => ['status' => 'enviado', 'criado_ts' => 0], 3 => ['status' => 'enviado', 'criado_ts' => 0]]),
    ], [['etapa' => 2, 'devida_em' => 26 * 3600], null, null]);
    $tent = static fn(string $ref, string $cpf, string $email, ?string $numero): array => ['origem' => 'site', 'ref' => $ref, 'cpf' => $cpf, 'email' => $email,
        'numero' => $numero, 'nome' => 'Ana Teste', 'curso_slug' => 'puncao-venosa', 'curso_nome' => 'Punção Venosa', 'quando' => $A - 3600, 'aberta' => true, 'pago' => false, 'pago_ts' => null];
    $nada = static fn(string $x): bool => false;
    $soEmail = mcp_rw_agrupar([$tent('s1', '52998224725', 'ana@exemplo.org', null)], $nada, $nada, $nada)[0];
    $soZap = mcp_rw_agrupar([$tent('s2', '52998224725', '', '5521911112222')], $nada, $nada, $nada)[0];
    verificar('canais: sem celular → só e-mail; sem e-mail → só WhatsApp', [$soEmail['no_fluxo'], $soEmail['motivo'], $soEmail['no_fluxo_email'], $soEmail['email'], $soEmail['refs_site'],
        $soZap['no_fluxo'], $soZap['no_fluxo_email'], $soZap['motivo_email']], [false, 'sem_celular', true, 'ana@exemplo.org', [1], true, false, 'sem_email']);
    $doisCanais = [$tent('s3', '52998224725', 'ana@exemplo.org', '5521911112222')];
    $bloqEmail = mcp_rw_agrupar($doisCanais, $nada, static fn(string $e): bool => $e === 'ana@exemplo.org', $nada)[0];
    $parouZap = mcp_rw_agrupar($doisCanais, static fn(string $n): bool => true, $nada, static fn(string $id): bool => $id === 't:5521911112222')[0];
    $parouEmail = mcp_rw_agrupar($doisCanais, $nada, static fn(string $e): bool => true, static fn(string $id): bool => $id === 'e:ana@exemplo.org')[0];
    verificar('bloqueios: e-mail bloqueado (avisos) tira só o e-mail; "Não tenho interesse" e descadastro da recuperação tiram os dois', [
        [$bloqEmail['motivo'], $bloqEmail['motivo_email']], [$parouZap['motivo'], $parouZap['motivo_email']], [$parouEmail['motivo'], $parouEmail['motivo_email']],
    ], [[null, 'bloqueou'], ['bloqueou', 'bloqueou'], ['bloqueou', 'bloqueou']]);
    $psPessoa = ['primeiro_nome' => 'Bia', 'curso_slug' => 'primeiros-socorros-basico', 'curso_nome' => 'Primeiros Socorros Básico'];
    $sair = "http://127.0.0.1:$portaSite/matricula-cursos-presenciais/api/avisos.php?rw=7.abcdef0123456789";
    $e1 = mcp_rw_email_montar(1, ['primeiro_nome' => 'Ana', 'curso_slug' => 'puncao-venosa', 'curso_nome' => 'Punção Venosa'], null, $sair);
    $e2 = mcp_rw_email_montar(2, ['primeiro_nome' => 'Ana', 'curso_slug' => 'puncao-venosa', 'curso_nome' => 'Punção Venosa'], null, $sair);
    $e3 = mcp_rw_email_montar(3, $psPessoa, ['codigo' => 'NOV15', 'data' => 'terça-feira, 17/11'], $sair);
    $e3sem = mcp_rw_email_montar(3, $psPessoa, null, $sair);
    $eg = mcp_rw_email_montar(1, ['primeiro_nome' => 'Rui', 'curso_slug' => '', 'curso_nome' => 'nossos cursos'], null, $sair);
    $site = "http://127.0.0.1:$portaSite/";
    verificar('assuntos: 1º "ainda não foi concluída", 2º lembrete, 3º último aviso, 3º com cupom; sem curso, "nossos cursos"', [$e1['assunto'], $e2['assunto'], $e3sem['assunto'], $e3['assunto'], $eg['assunto']], [
        'Sua inscrição em Punção Venosa ainda não foi concluída', 'Lembrete: sua inscrição em Punção Venosa continua em aberto',
        'Último aviso sobre sua inscrição em Primeiros Socorros Básico', 'Último aviso: cupom NOV15 em Primeiros Socorros Básico', 'Sua inscrição em nossos cursos ainda não foi concluída',
    ]);
    verificar('botão: os caminhos do WhatsApp com utm_source=email (rec1, rec3cupom com o cupom aplicado, página geral sem curso)', [$e1['link'], $e3['link'], $e3sem['link'], $eg['link']], [
        $site . 'matricula-cursos-presenciais/checkout/?curso=puncao-venosa&utm_source=email&utm_medium=recuperacao&utm_campaign=rec1',
        $site . 'matricula-cursos-presenciais/checkout/?curso=primeiros-socorros-basico&via=agenda&cupom=NOV15&utm_source=email&utm_medium=recuperacao&utm_campaign=rec3cupom',
        $site . 'matricula-cursos-presenciais/checkout/?curso=primeiros-socorros-basico&utm_source=email&utm_medium=recuperacao&utm_campaign=rec3',
        $site . 'matricula-cursos-presenciais/?utm_source=email&utm_medium=recuperacao&utm_campaign=rec1',
    ]);
    verificar('corpo: um botão só, "Concluir inscrição", descadastro no HTML e no texto, moldura do site', [substr_count($e1['html'], 'border-radius:999px;background:#cc0000'),
        str_contains($e1['html'], '>Concluir inscrição</a>'), str_contains($e1['html'], 'Não quero mais receber'), str_contains($e1['html'], mcp_escapar($sair)),
        str_contains($e1['texto'], "Não quero mais receber: $sair"), str_contains($e1['texto'], 'Concluir inscrição: ' . $e1['link']), str_contains($e1['html'], 'CNPJ'),
        str_contains($e1['html'], 'ainda não identificamos o pagamento'), str_contains($e1['html'], 'Seus dados ficaram registrados')],
        [1, true, true, true, true, true, true, true, true]);
    verificar('2º: "ficou alguma dúvida? responda este e-mail"', [str_contains($e2['html'], 'Ficou alguma dúvida'), str_contains($e2['html'], 'É só responder este e-mail')], [true, true]);
    verificar('3º com cupom: o código e a data da turma, sem preço nem escassez', [str_contains($e3['html'], '<strong>NOV15</strong>'), str_contains($e3['html'], 'terça-feira, 17/11'),
        str_contains($e3['html'] . $e3['texto'], 'R$'), (bool) preg_match('/vagas?|últim[oa]s \d|primeiros \d/u', $e3['html'] . $e3['texto']), $e3['modelo'], $e3sem['modelo']],
        [true, true, false, false, 'rec3cupom', 'rec3']);
    verificar('List-Unsubscribe de um clique (RFC 8058) com o código assinado da linha', mcp_rw_email_cabecalhos(42), [
        'List-Unsubscribe' => '<' . $site . 'matricula-cursos-presenciais/api/avisos.php?rw=42.' . mcp_rw_email_assinatura(42) . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);

    // ------------------------------------------------------------------------- dados
    $sA = $criar('ambos', $A - 4 * 3600, ['nome' => 'ANA SOUZA']);                                        // e-mail e WhatsApp
    $sCel = $criar('semcel', $A - 4 * 3600, ['telefone' => '2122223333']);                               // fixo: só e-mail
    $sCtl = $criar('controle', $A - 4 * 3600, ['cpf' => cpf_de_teste(true)]);                           // grupo de controle
    $sPix = $criar('pix', $A - 5 * 3600, ['pix_copia_cola' => '00020126-teste', 'unicopag_hash' => 'hash-teste-pix']); // já recebeu o lembrete do PIX
    $db->prepare("INSERT INTO mcp_eventos (inscricao_id, tipo, detalhe, criado_em) VALUES (?, 'email_pix_lembrete', 'resend', ?)")->execute([$sPix['id'], $quando($A - 3 * 3600)]);
    $sEsp = $criar('esperapix', $A - 90 * 60, ['pix_copia_cola' => '00020126-teste', 'unicopag_hash' => 'hash-teste-esp', 'telefone' => '2122223334']); // o lembrete do PIX ainda pode vir
    $sRec = $criar('recente', $A - 30 * 60);
    $sBlq = $criar('bloqemail', $A - 4 * 3600);
    mcp_avisos_bloquear_hash('email', mcp_avisos_hash('email', $sBlq['email']), 'descadastro');        // saiu dos avisos por e-mail
    $sPaga = $criar('paga', $A - 4 * 3600, ['telefone' => '2122223335']);
    $sRecusa = $criar('recusa', $A - 4 * 3600, ['telefone' => '2122223336']);                         // a Resend recusa o endereço
    $sPS = $criar('ps', $A - 4 * 3600, ['curso_slug' => 'primeiros-socorros-basico', 'curso_nome' => 'Primeiros Socorros Básico', 'nome' => 'BRUNO ALVES', 'telefone' => '2122223337']);
    $sNao = $criar('naoquer', $A - 4 * 3600);                                                           // dirá "Não tenho interesse" no WhatsApp
    $celSemEmail = $celular();
    $eSem = ['id' => "u-sememail-$sufixo", 'nome' => 'Aluno Sem Email', 'email' => '', 'cpfCnpj' => cpf_de_teste(), 'celular' => $celSemEmail, 'papel' => 'ALUNO',
        'criadoEm' => gmdate('Y-m-d\TH:i:s', $A - 4 * 3600) . '.000', 'matriculas' => []];
    controle('escola', ['usuarios' => [$eSem]]);

    // ------------------------------------------------------------------------- rodadas
    $r = mcp_rw_email_rodar($A - 4 * 3600);
    verificar('fora do horário (6h): nenhum e-mail', [$r['enviados'], $r['motivo'], count(falso('emails'))], [0, 'fora do horário (8h–20h de Brasília)', 0]);

    $r = mcp_rw_email_rodar($A);
    verificar('rodada das 10h: 1º e-mail para quem tem e-mail; o recusado falha; controle só ganha a linha; PIX pulado e esperando',
        [$r['enviados'], $r['falhas'], $r['controle'], $r['pulados_pix'], $r['esperando_pix'], $r['escola']], [5, 1, 1, 1, 1, 'ok']);
    $primeiro = 'Sua inscrição em Punção Venosa ainda não foi concluída';
    verificar('recebem o 1º: com celular, sem celular, quem vai pagar, Primeiros Socorros, quem vai dizer não', array_map(static fn(array $i): array => $assuntosPara($i['email']),
        [$sA, $sCel, $sPaga, $sPS, $sNao]), [[$primeiro], [$primeiro], [$primeiro], ['Sua inscrição em Primeiros Socorros Básico ainda não foi concluída'], [$primeiro]]);
    verificar('não recebem: controle, PIX já lembrado, PIX esperando o lembrete, recente demais, e-mail bloqueado', array_map('count', [
        $emailsPara($sCtl['email']), $emailsPara($sPix['email']), $emailsPara($sEsp['email']), $emailsPara($sRec['email']), $emailsPara($sBlq['email'])]), [0, 0, 0, 0, 0]);
    verificar('linhas: controle etapa 0, pulado_pix na etapa 1, falhou para o recusado', [$linhasDe($sCtl['email']), $linhasDe($sPix['email']), $linhasDe($sRecusa['email'])],
        [['0:controle'], ['1:pulado_pix'], ['1:falhou:rec1']]);
    $stmt = $db->prepare('SELECT criado_em FROM mcp_recuperacao_email WHERE email_hash = ? AND etapa = 1');
    $stmt->execute([mcp_avisos_hash('email', $sPix['email'])]);
    verificar('pulado_pix com a hora do lembrete do PIX', $stmt->fetchColumn(), $quando($A - 3 * 3600));
    $msgA = $emailsPara($sA['email'])[0] ?? [];
    $idA = (int) $db->query('SELECT MAX(id) FROM mcp_recuperacao_email')->fetchColumn();
    $stmt = $db->prepare('SELECT id, pessoa_chave, email_hash, whatsapp_hash, provedor_id, status, modelo, assunto FROM mcp_recuperacao_email WHERE email_hash = ? AND etapa = 1');
    $stmt->execute([mcp_avisos_hash('email', $sA['email'])]);
    $linhaA = $stmt->fetch() ?: [];
    $urlSairA = $site . 'matricula-cursos-presenciais/api/avisos.php?rw=' . ($linhaA['id'] ?? 0) . '.' . mcp_rw_email_assinatura((int) ($linhaA['id'] ?? 0));
    verificar('envio: remetente, responder-para o contato, assunto, List-Unsubscribe da linha, HTML e texto', [
        $msgA['from'] ?? '', $msgA['reply_to'] ?? '', $msgA['subject'] ?? '', $msgA['headers'] ?? [], str_contains((string) ($msgA['html'] ?? ''), 'utm_source=email&amp;utm_medium=recuperacao&amp;utm_campaign=rec1'),
        str_contains((string) ($msgA['text'] ?? ''), 'Não quero mais receber: ' . $urlSairA), str_contains((string) ($msgA['html'] ?? ''), 'Oi, Ana.'),
    ], ['Cruz Vermelha Brasileira Rio de Janeiro <matricula@info.exemplo.org>', 'contato@exemplo.org', 'Sua inscrição em Punção Venosa ainda não foi concluída',
        ['List-Unsubscribe' => '<' . $urlSairA . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'], true, true, true]);
    verificar('linha gravada: HMAC da pessoa, hashes dos bloqueios, id da Resend, modelo e assunto; nada em claro', [
        $linhaA['pessoa_chave'] ?? '', $linhaA['email_hash'] ?? '', $linhaA['whatsapp_hash'] ?? '', str_starts_with((string) ($linhaA['provedor_id'] ?? ''), 're_teste_'), $linhaA['status'] ?? '',
        $linhaA['modelo'] ?? '', $linhaA['assunto'] ?? '',
    ], [mcp_rw_hmac('c:' . $sA['cpf']), mcp_avisos_hash('email', $sA['email']), mcp_avisos_hash('whatsapp', '55' . $sA['telefone']), true, 'enviado', 'rec1',
        'Sua inscrição em Punção Venosa ainda não foi concluída']);
    $claro = $db->prepare('SELECT COUNT(*) FROM mcp_recuperacao_email WHERE pessoa_chave IN (?, ?) OR email_hash = ? OR chaves LIKE ? OR chaves LIKE ?');
    $claro->execute([$sA['cpf'], $sA['email'], $sA['email'], '%' . $sA['cpf'] . '%', '%exemplo.org%']);
    verificar('nem e-mail nem CPF em claro na tabela', (int) $claro->fetchColumn(), 0);
    $dup = null;
    try {
        $db->prepare("INSERT INTO mcp_recuperacao_email (pessoa_chave, origem, etapa, curso_nome, email_hash, status, criado_em, atualizado_em) VALUES (?, 'site', 1, 'x', ?, 'enviado', ?, ?)")
            ->execute([$linhaA['pessoa_chave'], $linhaA['email_hash'], $quando($A), $quando($A)]);
    } catch (PDOException $e) {
        $dup = $e->getCode();
    }
    verificar('o banco recusa o mesmo e-mail duas vezes (UNIQUE)', $dup, '23000');

    // O WhatsApp na mesma hora: os canais são independentes.
    $w = mcp_rw_rodar($A);
    verificar('WhatsApp na mesma hora: quem tem os dois recebe os dois; sem e-mail recebe só o WhatsApp; sem celular não recebe WhatsApp',
        [$zapPara($sA['telefone']), $zapPara($celSemEmail), $w['controle']], [['rec_inscricao_1_v2'], ['rec_inscricao_1_v2'], 1]);
    $stmt = $db->prepare('SELECT etapa, status FROM mcp_recuperacao_whatsapp WHERE telefone_hash = ?');
    $stmt->execute([mcp_rw_hmac('t:55' . $sCtl['telefone'])]);
    verificar('grupo de controle: nem WhatsApp nem e-mail (a linha etapa 0 do WhatsApp também, nunca uma mensagem)', [$zapPara($sCtl['telefone']), $stmt->fetchAll(), $emailsPara($sCtl['email'])],
        [[], [['etapa' => 0, 'status' => 'controle']], []]);

    $antes = count(falso('emails'));
    $r = mcp_rw_email_rodar($A + 3600);
    verificar('uma hora depois: nada repete; só o cadastro de 30 min atrás recebe o 1º; o PIX ainda espera o lembrete', [$r['enviados'], count(falso('emails')) - $antes,
        count($emailsPara($sRec['email'])), $r['esperando_pix']], [1, 1, 1, 1]);

    // "Não tenho interesse" no WhatsApp (pelo webhook): para o e-mail também.
    mcp_rw_webhook_tratar([['tipo' => 'resposta', 'de' => '55' . $sNao['telefone'], 'texto' => 'Não tenho interesse', 'citada' => null, 'ts' => null]], $A + 3600);
    // A pessoa que vai pagar paga pelo link do 1º e-mail.
    $pagar($sPaga, $A + 2 * 3600, ['email', 'rec1']);
    // Descadastro pelo link do e-mail de quem tem os dois canais: o GET só mostra a página; o POST de um clique bloqueia.
    [$st, $cab, $html] = http($urlSairA);
    verificar('descadastro: o GET mostra a página com o botão e não bloqueia nada', [$st, str_contains($html, 'Não quero mais receber</button>'), $cab['x-robots-tag'] ?? '',
        mcp_avisos_bloqueado('email', $sA['email'])], [200, true, 'noindex, nofollow', false]);
    [$st] = http($site . 'matricula-cursos-presenciais/api/avisos.php?rw=' . ($linhaA['id'] ?? 0) . '.0000000000000000', 'POST', 'List-Unsubscribe=One-Click');
    [$st2, , $html2] = http($urlSairA . 'x');
    verificar('descadastro: código com assinatura errada → 404 e nada muda', [$st, $st2, str_contains($html2, 'não vale mais'), mcp_avisos_bloqueado('email', $sA['email'])], [404, 404, true, false]);
    [$st, , $texto] = http($urlSairA, 'POST', 'List-Unsubscribe=One-Click', ['Content-Type: application/x-www-form-urlencoded']);
    $bloq = $db->prepare("SELECT canal, origem FROM mcp_avisos_bloqueios WHERE (canal = 'email' AND destino_hash = ?) OR (canal = 'whatsapp' AND destino_hash = ?) ORDER BY canal");
    $bloq->execute([mcp_avisos_hash('email', $sA['email']), mcp_avisos_hash('whatsapp', '55' . $sA['telefone'])]);
    $marca = $db->prepare('SELECT descadastro_em IS NOT NULL FROM mcp_recuperacao_email WHERE id = ?');
    $marca->execute([$linhaA['id'] ?? 0]);
    verificar('descadastro de um clique: 200, bloqueia e-mail E WhatsApp (origem recuperacao) e marca a linha', [$st, str_contains($texto, 'nem por e-mail nem pelo WhatsApp'), $bloq->fetchAll(),
        (int) $marca->fetchColumn()], [200, true, [['canal' => 'email', 'origem' => 'recuperacao'], ['canal' => 'whatsapp', 'origem' => 'recuperacao']], 1]);

    $antesZap = count(falso('kapso'));
    $r = mcp_rw_email_rodar($A + 24 * 3600);
    $w = mcp_rw_rodar($A + 24 * 3600);
    verificar('2º e-mail: sai para quem segue (sem celular, PIX pulado, Primeiros Socorros, o recente)', array_map(static fn(array $i): array => $assuntosPara($i['email']), [$sCel, $sPix, $sPS, $sRec]), [
        ['Sua inscrição em Punção Venosa ainda não foi concluída', 'Lembrete: sua inscrição em Punção Venosa continua em aberto'],
        ['Lembrete: sua inscrição em Punção Venosa continua em aberto'],
        ['Sua inscrição em Primeiros Socorros Básico ainda não foi concluída', 'Lembrete: sua inscrição em Primeiros Socorros Básico continua em aberto'],
        ['Sua inscrição em Punção Venosa ainda não foi concluída', 'Lembrete: sua inscrição em Punção Venosa continua em aberto'],
    ]);
    verificar('o PIX que não ganhou o lembrete (20 h passaram): recebe o 1º e-mail', $assuntosPara($sEsp['email']), ['Sua inscrição em Punção Venosa ainda não foi concluída']);
    verificar('param: pagou entre os e-mails, e-mail recusado, "Não tenho interesse" no WhatsApp, descadastro (nos dois canais)', [count($emailsPara($sPaga['email'])),
        count($emailsPara($sRecusa['email'])), count($emailsPara($sNao['email'])), count($emailsPara($sA['email'])), $zapPara($sA['telefone']), $zapPara($sNao['telefone'])],
        [1, 0, 1, 1, ['rec_inscricao_1_v2'], ['rec_inscricao_1_v2']]);
    verificar('o WhatsApp de quem não tem e-mail segue sozinho', $zapPara($celSemEmail), ['rec_inscricao_1_v2', 'rec_inscricao_2']);
    $stmt = $db->prepare('SELECT criado_em FROM mcp_recuperacao_email WHERE email_hash = ? AND etapa = 2');
    $stmt->execute([mcp_avisos_hash('email', $sPix['email'])]);
    verificar('2º de quem pulou pelo PIX: saiu na rodada das 24 h (19 h da âncora e 21 h depois do lembrete já tinham passado)', $stmt->fetchColumn(), $quando($A + 24 * 3600));

    // 3º e-mail: 72 h da âncora e 48 h depois do 2º (que saiu com 24 h).
    mcp_rw_email_rodar($A + 71 * 3600);
    verificar('71 h: o 2º saiu há 47 h, o 3º espera; o 2º de quem recebeu o 1º com 24 h sai (21 h depois dele)', [count($emailsPara($sCel['email'])), count($emailsPara($sPS['email'])),
        count($emailsPara($sEsp['email']))], [2, 2, 2]);
    mcp_rw_email_rodar($A + 72 * 3600);
    $tresCel = $assuntosPara($sCel['email']);
    $tresPS = $emailsPara($sPS['email']);
    verificar('3º e-mail: último aviso; em Primeiros Socorros, com o cupom', [$tresCel[2] ?? '', $tresPS[2]['subject'] ?? ''],
        ['Último aviso sobre sua inscrição em Punção Venosa', 'Último aviso: cupom NOV15 em Primeiros Socorros Básico']);
    $diasSemana = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
    $dataTurma = $diasSemana[(int) gmdate('w', $turmaInicio - 3 * 3600)] . ', ' . gmdate('d/m', $turmaInicio - 3 * 3600);
    $htmlPS = (string) ($tresPS[2]['html'] ?? '');
    verificar('3º com cupom: link com cupom=NOV15 e utm_source=email (rec3cupom), a data da turma, sem preço', [
        str_contains($htmlPS, 'checkout/?curso=primeiros-socorros-basico&amp;via=agenda&amp;cupom=NOV15&amp;utm_source=email&amp;utm_medium=recuperacao&amp;utm_campaign=rec3cupom'),
        str_contains($htmlPS, $dataTurma), str_contains($htmlPS . ($tresPS[2]['text'] ?? ''), 'R$'), $linhasDe($sPS['email'])], [true, true, false, ['1:enviado:rec1', '2:enviado:rec2', '3:enviado:rec3cupom']]);
    // ------------------------------------------------------------------------- métricas
    $agoraM = $A + 73 * 3600;
    $cargaM = mcp_rw_carregar($agoraM);
    $m = mcp_rw_metricas($cargaM['pessoas'], mcp_rw_linhas(), $agoraM, null, $cargaM['pelo_link'], mcp_rw_email_linhas(), $cargaM['pelo_link_email']);
    verificar('métricas do e-mail: enviados por etapa, pulados pelo PIX, recusados, descadastros', [$m['email']['etapas'], $m['email']['pulados_pix'], $m['email']['falhas'], $m['email']['descadastros']],
        [[1 => 7, 2 => 5, 3 => 4], 1, 1, 1]);
    verificar('métricas: pagou pelo link do e-mail (utm_source=email) não conta como WhatsApp', [$m['email']['pelo_link']['rec1'], $m['pelo_link']['rec1']], [1, 0]);
    verificar('métricas: quem só tem e-mail e pagou depois dele é recuperada; o crédito vai ao "e-mail rec1"', [$m['recuperadas'],
        $m['por_modelo']['e-mail rec1']['enviadas'] ?? 0, $m['por_modelo']['e-mail rec1']['recuperadas'] ?? 0, $m['por_mensagem'][1]], [1, 7, 1, 1]);
    verificar('métricas: o grupo de controle conta uma vez só (linha etapa 0 nas duas tabelas)', $m['controle']['pessoas'], 1);
    $naLista = [];
    foreach ($m['lista'] as $p) {
        $naLista[$p['nome']] = $p;
    }
    $esp = $naLista['Pessoa Teste esperapix'] ?? [];
    verificar('lista: os dois canais, com quantas de cada já foram, o próximo e-mail e o e-mail mascarado', [
        $esp['canal_email']['enviadas'] ?? null, $esp['canal_email']['proxima_etapa'] ?? null, $esp['canal_email']['assunto'] ?? null, $esp['whatsapp']['no_fluxo'] ?? null,
        $esp['whatsapp']['motivo'] ?? null, $esp['celular'] ?? null, $esp['email'] ?? null, isset($naLista['Pessoa Teste naoquer']), isset($naLista['Pessoa Teste ambos']),
    ], [2, 3, 'Último aviso sobre sua inscrição em Punção Venosa', false, 'sem_celular', '', 'r***@exemplo.org', false, false]);
    $semDado = (bool) array_filter($m['lista'], static fn(array $p): bool => preg_match('/\d{8,}|rwe-/', $p['celular'] . $p['email'] . $p['canal_email']['previa']) === 1);
    verificar('lista: nenhum e-mail ou celular inteiro', $semDado, false);

    $antes = count(falso('emails'));
    mcp_rw_email_rodar($A + 98 * 3600);
    mcp_rw_email_rodar($A + 120 * 3600);
    $porAssunto = [];
    foreach (falso('emails') as $x) {
        $k = ($x['to'][0] ?? '') . '|' . ($x['subject'] ?? '');
        $porAssunto[$k] = ($porAssunto[$k] ?? 0) + 1;
    }
    verificar('depois do 3º, nada mais; nenhum e-mail repetiu para ninguém', [count(array_filter(array_slice(falso('emails'), $antes), static fn(array $x): bool => in_array($x['to'][0] ?? '', [$sCel['email'], $sPS['email']], true))),
        max($porAssunto)], [0, 1]);

    // ------------------------------------------------------------------------- tetos
    $B = $A + 100 * 86400;
    $lote = [];
    for ($i = 0; $i < 12; $i++) {
        $lote[] = $criar("lote$i", $B - 4 * 3600, ['telefone' => '21222' . str_pad((string) $i, 5, '0', STR_PAD_LEFT)]);
    }
    $r = mcp_rw_email_rodar($B);
    $r2 = mcp_rw_email_rodar($B + 3600);
    verificar('teto: 10 e-mails na rodada, os 2 restantes na seguinte', [$r['enviados'], $r2['enviados']], [10, 2]);

    $C = $A + 200 * 86400;
    $cheio = $db->prepare("INSERT INTO mcp_recuperacao_email (pessoa_chave, origem, etapa, curso_nome, email_hash, status, criado_em, atualizado_em) VALUES (?, 'site', 1, 'x', ?, 'enviado', ?, ?)");
    for ($i = 0; $i < MCP_RW_EMAIL_POR_DIA; $i++) {
        $cheio->execute([hash('sha256', "cheio-$i-$sufixo"), substr(hash('sha256', "email-$i-$sufixo"), 0, 32), $quando($C - 1800), $quando($C - 1800)]);
    }
    $teto = $criar('tetodiario', $C - 4 * 3600, ['telefone' => '2122223338']);
    $r = mcp_rw_email_rodar($C);
    verificar('teto de 30 em 24 h: nada sai e diz o motivo', [$r['enviados'], $r['motivo'], count($emailsPara($teto['email']))], [0, 'teto de 30 e-mails em 24 h', 0]);
    mcp_rw_email_rodar($C + 23 * 3600);
    $antesTeto = count($emailsPara($teto['email']));
    mcp_rw_email_rodar($C + 24 * 3600);
    verificar('teto: libera quando os 30 passam de 24 h', [$antesTeto, count($emailsPara($teto['email']))], [0, 1]);

    $D = $A + 300 * 86400;
    $cheioSite = $criar('sitecheio', $D - 30 * 86400, ['status' => 'pago', 'pago_em' => $quando($D - 30 * 86400), 'telefone' => '2122223339']);
    $evento = $db->prepare("INSERT INTO mcp_eventos (inscricao_id, tipo, detalhe, criado_em) VALUES (?, 'email_aluno', 'resend', ?)");
    for ($i = 0; $i < MCP_RW_EMAIL_RESERVA_SITE; $i++) {
        $evento->execute([$cheioSite['id'], $quando($D - 3600)]);
    }
    $reserva = $criar('reserva', $D - 4 * 3600, ['telefone' => '2122223340']);
    $r = mcp_rw_email_rodar($D);
    verificar('o site já mandou 60 em 24 h: a recuperação espera (a cota fica para os recibos)', [$r['enviados'], str_contains((string) $r['motivo'], 'cota da Resend fica para os recibos'),
        mcp_rw_email_site_24h($D)], [0, true, 60]);
    mcp_rw_email_rodar($D + 24 * 3600);
    verificar('passadas as 24 h, o e-mail sai', count($emailsPara($reserva['email'])), 1);

    // ------------------------------------------------------------------------- erros da Resend
    $E = $A + 400 * 86400;
    controle('resend', ['limite' => true]);
    $l1 = $criar('limite1', $E - 4 * 3600, ['telefone' => '2122223341']);
    $l2 = $criar('limite2', $E - 4 * 3600, ['telefone' => '2122223342']);
    $r = mcp_rw_email_rodar($E);
    verificar('429 da Resend: a rodada para e a etapa não é gasta', [$r['enviados'], $r['falhas'], str_contains((string) $r['motivo'], '429'), $linhasDe($l1['email']), $linhasDe($l2['email'])],
        [0, 1, true, [], []]);
    controle('resend', []);
    mcp_rw_email_rodar($E + 3600);
    verificar('429 passado: os dois recebem na rodada seguinte', [count($emailsPara($l1['email'])), count($emailsPara($l2['email']))], [1, 1]);

    $F = $A + 500 * 86400;
    controle('resend', ['fora' => true]);
    $f1 = $criar('fora1', $F - 4 * 3600, ['telefone' => '2122223343']);
    $f2 = $criar('fora2', $F - 4 * 3600, ['telefone' => '2122223344']);
    $f3 = $criar('fora3', $F - 4 * 3600, ['telefone' => '2122223345']);
    $r = mcp_rw_email_rodar($F);
    verificar('duas recusas seguidas (Resend com erro): param a rodada e não gastam a etapa de ninguém', [$r['enviados'], $r['falhas'], str_contains((string) $r['motivo'], 'duas recusas'),
        $linhasDe($f1['email']), $linhasDe($f2['email']), $linhasDe($f3['email'])], [0, 2, true, [], [], []]);
    controle('resend', []);
    mcp_rw_email_rodar($F + 3600);
    verificar('Resend de volta: os três recebem', array_map(static fn(array $i): int => count($emailsPara($i['email'])), [$f1, $f2, $f3]), [1, 1, 1]);

    // ------------------------------------------------------------------------- escola fora (decisão de 31/10)
    // Com a escola respondendo erro, seguem o site e os importados: o cadastro do site recebe o 1º e-mail.
    $Fe = $F + 50 * 86400;
    controle('escola', ['fora' => true, 'usuarios' => []]);
    $ef = $criar('escolafora', $Fe - 4 * 3600, ['telefone' => '2122223347']);
    $r = mcp_rw_email_rodar($Fe);
    verificar('escola fora: segue só com o site e os importados, e o cadastro do site recebe o 1º e-mail',
        [$r['escola'], str_contains((string) $r['motivo'], 'seguem só o site e os importados'), count($emailsPara($ef['email']))], ['indisponivel', true, 1]);
    controle('escola', ['usuarios' => [$eSem]]);

    // ------------------------------------------------------------------------- sem RESEND_API_KEY
    $G = $A + 600 * 86400;
    $semChave = $criar('semchave', $G - 4 * 3600, ['telefone' => '2122223346']);
    $antes = count(falso('emails'));
    $saida = [];
    exec('MCP_CONFIG_ARQUIVO=' . escapeshellarg($configSem) . ' MCP_CONFIG_ESCOLA_ARQUIVO=' . escapeshellarg($vazio) . ' MCP_CONFIG_WHATSAPP_ARQUIVO=' . escapeshellarg($vazio)
        . ' MCP_CATALOGO_ARQUIVO=' . escapeshellarg((string) getenv('MCP_CATALOGO_ARQUIVO')) . ' ' . escapeshellarg(PHP_BINARY) . ' -r '
        . escapeshellarg('$_SERVER["REQUEST_METHOD"] = "CLI"; require ' . var_export($raiz . '/site/matricula-cursos-presenciais/api/lib.php', true) . '; require_once '
            . var_export($raiz . '/site/matricula-cursos-presenciais/api/lib/recuperacao_whatsapp.php', true) . '; restore_exception_handler(); echo json_encode(mcp_rw_email_rodar(' . $G . '));')
        . ' 2>/dev/null', $saida);
    $semR = json_decode(implode('', $saida), true) ?: [];
    verificar('sem RESEND_API_KEY: nada sai (nem pelo mail()) e diz o motivo', [$semR['enviados'] ?? null, $semR['motivo'] ?? null, count(falso('emails')) - $antes, $linhasDe($semChave['email'])],
        [0, 'sem configuração (RESEND_API_KEY)', 0, []]);
    $saida = [];
    exec('MCP_CONFIG_ARQUIVO=' . escapeshellarg($configSem) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($raiz . '/site/matricula-cursos-presenciais/api/lembretes.php') . ' 2>/dev/null', $saida);
    $linha = array_values(array_filter($saida, static fn(string $l): bool => str_contains($l, 'recuperação por e-mail')))[0] ?? '';
    verificar('lembretes.php sem a chave: a linha do e-mail diz o que falta, e nada vai à Resend', [str_contains($linha, 'sem configuração (RESEND_API_KEY)'), count(falso('emails')) - $antes], [true, 0]);

    // ------------------------------------------------------------------------- painel
    // Uma pessoa com cadastro de agora (2 h atrás): o 1º e-mail dela é da próxima rodada de verdade.
    $agoraPainel = $criar('painel', time() - 2 * 3600, ['nome' => 'Paula Painel']);
    $url = "http://127.0.0.1:$portaSite/matricula-cursos-presenciais/api/recuperacao.php";
    $cookie = 'Cookie: mcp_painel=' . $_COOKIE[MCP_PAINEL_COOKIE];
    [$st, , $html] = http($url);
    verificar('painel sem sessão: nenhum dado', [$st, str_contains($html, 'Entrar pelo Portal da secretaria'), str_contains($html, 'Pessoa Teste'), str_contains($html, 'exemplo.org')], [200, true, false, false]);
    [$st, , $html] = http($url, 'GET', null, [$cookie]);
    verificar('painel: situação da Resend e cota, e-mails da próxima rodada com assunto e prévia recolhida', [$st, str_contains($html, 'Resend configurada'),
        str_contains($html, 'Cota da Resend nas últimas 24 h'), str_contains($html, '<h3>E-mail</h3>'), str_contains($html, 'Sua inscrição em Punção Venosa ainda não foi concluída'),
        str_contains($html, 'Ver o e-mail como Paula vai receber'), str_contains($html, '<div class="carta">'), str_contains($html, 'r***@exemplo.org')],
        [200, true, true, true, true, true, true, true]);
    verificar('painel: funil do e-mail e os dois canais na lista', [str_contains($html, 'Por e-mail'), str_contains($html, 'Pelo link do e-mail'), str_contains($html, 'class="canal em'),
        str_contains($html, 'class="canal wa'), str_contains($html, 'WhatsApp ou e-mail'), str_contains($html, 'Recuperação por WhatsApp e e-mail')], [true, true, true, true, true, true]);
    verificar('painel: nenhum e-mail ou celular inteiro, nem a chave da Resend', [(bool) array_filter($emails, static fn(string $e): bool => str_contains($html, $e)),
        (bool) array_filter($telefones, static fn(string $t): bool => str_contains($html, substr($t, 2))), str_contains($html, 're_teste')], [false, false, false]);

    $erros = trim((string) file_get_contents($logErros));
    verificar('servidor sem erro de PHP no log', $erros, '');
} finally {
    foreach ([$servidor, $servidorFalso] as $s) {
        if (is_resource($s)) {
            proc_terminate($s);
        }
    }
    limpar($db, $padraoEmail, $telefones, $emails);
    foreach ([$config, $configSem, $vazio, $ofertaTeste, $logErros ?? ''] as $arq) {
        @unlink($arq);
    }
    array_map('unlink', glob("$falso/*") ?: []);
    @rmdir($falso);
}
echo "\n$total verificações, $falhas falha(s).\n";
exit($falhas > 0 ? 1 : 0);
