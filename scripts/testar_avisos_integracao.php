#!/usr/bin/env php
<?php
/**
 * Teste de ponta a ponta da comunicação do ponto (lib/avisos.php, lib/comunicacao.php, lib/metricas.php,
 * api/avisos.php, api/whatsapp.php e a seção Comunicação do portal), contra um MariaDB LOCAL. Cobre:
 *   - tokens dos links pessoais, dias, número do WhatsApp, janela das 8h às 20h, texto dos comunicados;
 *   - lembrete da véspera: preparo, chave única, horário, consentimento conferido na hora, vencimento;
 *   - saída não registrada: aviso, clique rastreado, saída informada pelo link e pela tela do ponto,
 *     aceite e recusa no portal;
 *   - comunicados das três fases: preparo, validação, agendamento, disparo, público, texto por vínculo,
 *     HTML escapado, cancelamento; opinião (e aviso na tela do ponto);
 *   - WhatsApp nos três modos: manual (fila com wa.me), API oficial (modelo, parâmetros, botão, webhook de
 *     situação e PARAR, assinatura) e Make (assinatura do webhook);
 *   - lembrete da aula (escola falsa com aulas_do_dia), "não quero mais receber";
 *   - portal: telas, token dos formulários, ajustes, importar planilha, lembretes na ficha, teste do
 *     comunicado, fila; métricas; faxina; rotina da linha de comando.
 * Nada sai de verdade: e-mail, WhatsApp, Make e escola são um servidor falso local, que anota tudo.
 *
 * Precisa de MCP_CONFIG_ARQUIVO com o banco local (DB_HOST 127.0.0.1 ou localhost) sem colaboradores
 * ativos de outros testes. CPFs gerados só com o dígito verificador válido; tudo o que o teste cria é
 * apagado no fim, e os ajustes do portal voltam como estavam.
 *
 * Uso: MCP_CONFIG_ARQUIVO=/caminho/config-teste.php php scripts/testar_avisos_integracao.php
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$configOriginal = (string) getenv('MCP_CONFIG_ARQUIVO');
if ($configOriginal === '' || !is_file($configOriginal)) {
    fwrite(STDERR, "Falta MCP_CONFIG_ARQUIVO com o banco local (veja o cabeçalho deste arquivo).\n");
    exit(2);
}
$portaSite = 19100 + random_int(0, 399);
$portaFalso = $portaSite + 400;
$falso = sys_get_temp_dir() . '/mcp-avisos-falso-' . bin2hex(random_bytes(4));
mkdir($falso);
$base0 = [
    'EMAIL_SECRETARIA' => '', 'PAINEL_EMAILS' => 'avisos@exemplo.org', 'SITE_URL' => "http://127.0.0.1:$portaSite", 'RESEND_API_KEY' => 're_teste', 'RESEND_API_URL' => "http://127.0.0.1:$portaFalso/emails",
    'EMAIL_REMETENTE' => 'Cruz Vermelha Brasileira Rio de Janeiro <ponto@info.exemplo.org>', 'EMAIL_CONTATO' => 'contato@exemplo.org',
    'ESCOLA_API_URL' => "http://127.0.0.1:$portaFalso/rest/v1/rpc/matricula_rapida", 'ESCOLA_API_TOKEN' => 'chave-falsa',
    // O teste usa as datas de verdade (hoje, amanhã): sem os feriados embutidos, o resultado não depende do dia.
    'AVISOS_FERIADOS' => '0',
];
/** Grava um arquivo de configuração de teste (o banco vem da configuração original). */
function config_teste(array $extra): string
{
    global $configOriginal;
    $arq = tempnam(sys_get_temp_dir(), 'mcp-avisos-cfg-');
    file_put_contents($arq, '<?php return ' . var_export($extra, true) . ' + (require ' . var_export($configOriginal, true) . ');');
    return $arq;
}
$config = config_teste($base0);
$configCloud = config_teste($base0 + ['WHATSAPP_CLOUD_TOKEN' => 'tok_teste', 'WHATSAPP_CLOUD_NUMERO_ID' => '1234567890', 'WHATSAPP_CLOUD_BASE' => "http://127.0.0.1:$portaFalso",
    'WHATSAPP_CLOUD_APP_SEGREDO' => 'segredo-do-app-de-teste', 'WHATSAPP_CLOUD_VERIFICACAO' => 'verifica-teste']);
$configMake = config_teste($base0 + ['WHATSAPP_WEBHOOK_URL' => "http://127.0.0.1:$portaFalso/make", 'WHATSAPP_WEBHOOK_SEGREDO' => 'segredo-do-make-com-mais-de-24-letras']);
$configMakeCurto = config_teste($base0 + ['WHATSAPP_WEBHOOK_URL' => "http://127.0.0.1:$portaFalso/make", 'WHATSAPP_WEBHOOK_SEGREDO' => 'curto']);
$evolution0 = ['WHATSAPP_EVOLUTION_URL' => "http://127.0.0.1:$portaFalso/", 'WHATSAPP_EVOLUTION_INSTANCIA' => 'palacio', 'WHATSAPP_EVOLUTION_CHAVE' => 'token-da-instancia',
    'WHATSAPP_EVOLUTION_PAUSA_S' => '0', 'WHATSAPP_EVOLUTION_TEMPO_S' => '1'];
$configEvolution = config_teste($base0 + $evolution0);
$configEvolutionChave = config_teste($base0 + ['WHATSAPP_EVOLUTION_CHAVE' => 'chave-errada-12345'] + $evolution0);
// As chaves do WhatsApp também podem vir de config-whatsapp.php; de lá, só as WHATSAPP_* valem.
$arquivoWhatsapp = tempnam(sys_get_temp_dir(), 'mcp-avisos-whatsapp-');
file_put_contents($arquivoWhatsapp, '<?php return ' . var_export($evolution0 + ['SITE_URL' => 'https://nao-pode-valer.example', 'RESEND_API_KEY' => 're_nao_pode'], true) . ';');
$semEscola = tempnam(sys_get_temp_dir(), 'mcp-avisos-escola-');
file_put_contents($semEscola, '<?php return [];');
putenv("MCP_CONFIG_ARQUIVO=$config");
putenv("MCP_CONFIG_ESCOLA_ARQUIVO=$semEscola");
putenv("MCP_CONFIG_WHATSAPP_ARQUIVO=$semEscola");
putenv('MCP_CATALOGO_ARQUIVO=' . $raiz . '/site/matricula-cursos-presenciais/cursos.json');
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $raiz . '/site/matricula-cursos-presenciais/api/lib.php';
restore_exception_handler();

if (!in_array((string) mcp_cfg('DB_HOST', 'localhost'), ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "DB_HOST não é local. Este teste grava e apaga dados; só roda num banco local.\n");
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

function cpf_de_teste(): string
{
    $d = array_map(static fn(): int => random_int(0, 9), range(1, 9));
    for ($n = 9; $n < 11; $n++) {
        $s = 0;
        foreach ($d as $i => $v) {
            $s += $v * ($n + 1 - $i);
        }
        $d[] = (10 * $s) % 11 % 10;
    }
    return implode('', $d);
}

$db = mcp_db();
$sufixo = bin2hex(random_bytes(3));
$marca = "Teste Avisos $sufixo";
$quem = 'avisos@exemplo.org';

function limpar(PDO $db): void
{
    $ids = $db->query("SELECT id FROM mcp_colaboradores WHERE nome LIKE '%Teste Avisos %'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $lista = implode(', ', array_map('intval', $ids));
        $db->exec("DELETE FROM mcp_ponto WHERE colaborador_id IN ($lista)");
        $db->exec("DELETE FROM mcp_avisos WHERE colaborador_id IN ($lista)");
        $db->exec("DELETE FROM mcp_colaboradores WHERE id IN ($lista)");
    }
    $campanhas = $db->query("SELECT id FROM mcp_campanhas WHERE criado_por = 'avisos@exemplo.org'")->fetchAll(PDO::FETCH_COLUMN);
    if ($campanhas) {
        $lista = implode(', ', array_map('intval', $campanhas));
        $db->exec("DELETE FROM mcp_avisos WHERE campanha_id IN ($lista)");
        $db->exec("DELETE FROM mcp_opinioes WHERE campanha_id IN ($lista)");
        $db->exec("DELETE FROM mcp_campanhas WHERE id IN ($lista)");
    }
    $db->exec("DELETE FROM mcp_avisos WHERE nome LIKE '%Teste Avisos %' OR destino LIKE '%avisos-teste.example%'");
    $db->exec("DELETE FROM mcp_avisos_bloqueios WHERE origem IN ('pagina', 'respondeu PARAR', 'secretaria', 'descadastro')");
    $db->exec("DELETE FROM mcp_ponto_aparelhos WHERE nome LIKE '%Teste Avisos %'");
    $db->exec("DELETE FROM mcp_presencas WHERE nome LIKE '%Teste Avisos %'");
    $db->exec("DELETE FROM mcp_eventos WHERE detalhe LIKE '%avisos@exemplo.org%' OR (tipo = 'aviso_pagina' AND detalhe = '127.0.0.1') OR tipo IN ('aviso_preferencias', 'aviso_telefone', 'opiniao', 'aviso_sair', 'ponto_saida_informada', 'aviso_whatsapp_parou',
        'aviso_whatsapp_numero_trocado', 'painel_aviso_parar', 'painel_links_novos', 'painel_aviso_repetir', 'aviso_descadastro') AND criado_em > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)");
}
limpar($db);
$outros = (int) $db->query("SELECT COUNT(*) FROM mcp_colaboradores WHERE ativo = 1")->fetchColumn();
if ($outros > 0) {
    fwrite(STDERR, "O banco tem $outros colaboradores ativos de fora deste teste: os comunicados iriam para eles também. Use um banco de teste limpo.\n");
    exit(2);
}
$ajustesAntes = mcp_ajustes_todos(true);
$db->exec("DELETE FROM mcp_ajustes");
mcp_ajustes_todos(true);

// ----------------------------------------------------------------------------- servidor falso (e-mail, WhatsApp, Make, escola)
$roteador = "$falso/roteador.php";
file_put_contents($roteador, <<<'PHP'
<?php
// Servidor falso: anota cada pedido num .jsonl e responde como o serviço de verdade responderia.
$dir = getenv('FALSO_DIR');
$caminho = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$corpo = (string) file_get_contents('php://input');
$anotar = static function (string $arquivo, array $dados) use ($dir): int {
    file_put_contents("$dir/$arquivo", json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    return count(file("$dir/$arquivo"));
};
header('Content-Type: application/json');
if ($caminho === '/emails') {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer re_teste') { http_response_code(401); echo '{}'; return true; }
    $n = $anotar('emails.jsonl', json_decode($corpo, true) ?: []);
    echo json_encode(['id' => "re_$n"]);
    return true;
}
if (preg_match('~^/v[0-9.]+/1234567890/messages$~', $caminho)) {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer tok_teste') { http_response_code(401); echo '{"error":{"message":"token"}}'; return true; }
    $d = json_decode($corpo, true) ?: [];
    $n = $anotar('graph.jsonl', $d);
    if (str_ends_with((string) ($d['to'] ?? ''), '0000')) { http_response_code(400); echo '{"error":{"message":"número inválido (teste)"}}'; return true; }
    echo json_encode(['messaging_product' => 'whatsapp', 'messages' => [['id' => "wamid.teste-$n"]]]);
    return true;
}
if (preg_match('~^/message/sendText/([^/]+)$~', $caminho, $m)) {
    // Evolution API v2: cabeçalho apikey (token da instância), corpo {number, text, linkPreview}.
    if ($m[1] !== 'palacio') { http_response_code(404); echo json_encode(['status' => 404, 'error' => 'Not Found', 'response' => ['message' => ['The "' . $m[1] . '" instance does not exist']]]); return true; }
    if (($_SERVER['HTTP_APIKEY'] ?? '') !== 'token-da-instancia') { http_response_code(401); echo '{"status":401,"error":"Unauthorized","response":{"message":"Unauthorized"}}'; return true; }
    $d = json_decode($corpo, true) ?: [];
    $numero = (string) ($d['number'] ?? '');
    if (str_ends_with($numero, '0000')) { http_response_code(400); echo json_encode(['status' => 400, 'error' => 'Bad Request', 'response' => ['message' => [['jid' => "$numero@s.whatsapp.net", 'exists' => false, 'number' => $numero]]]]); return true; }
    if (str_ends_with($numero, '0500')) { http_response_code(500); echo '{"status":500,"error":"Internal Server Error","response":{"message":"Connection Closed"}}'; return true; }
    if (str_ends_with($numero, '0777')) { sleep(3); }
    $n = $anotar('evolution.jsonl', $d);
    http_response_code(201);
    echo json_encode(['key' => ['remoteJid' => "$numero@s.whatsapp.net", 'fromMe' => true, 'id' => "EVO-$n"], 'status' => 'PENDING', 'messageType' => 'conversation']);
    return true;
}
if ($caminho === '/make') {
    $esperado = 'sha256=' . hash_hmac('sha256', $corpo, 'segredo-do-make-com-mais-de-24-letras');
    if (!hash_equals($esperado, (string) ($_SERVER['HTTP_X_CVB_ASSINATURA'] ?? ''))) { http_response_code(401); echo '{}'; return true; }
    $n = $anotar('make.jsonl', json_decode($corpo, true) ?: []);
    echo json_encode(['id' => "make-$n"]);
    return true;
}
if ($caminho === '/rest/v1/rpc/aulas_do_aluno') {
    echo '{"ok":true,"aluno":null,"aulas":[]}';
    return true;
}
if ($caminho === '/rest/v1/rpc/aulas_do_dia') {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer chave-falsa') { http_response_code(401); echo '{}'; return true; }
    $pedido = json_decode($corpo, true) ?: [];
    $anotar('escola.jsonl', $pedido);
    $controle = json_decode((string) @file_get_contents("$dir/escola.json"), true) ?: ['falha' => false, 'alunos' => []];
    if (!empty($controle['falha'])) { http_response_code(500); echo '{"code":"XX000"}'; return true; }
    echo json_encode(['ok' => true, 'data' => $pedido['dados']['data'] ?? null, 'alunos' => $controle['alunos']], JSON_UNESCAPED_UNICODE);
    return true;
}
http_response_code(404);
echo '{}';
return true;
PHP);
/** Linhas anotadas pelo servidor falso num arquivo (emails, graph, make, escola). */
function falso(string $nome): array
{
    global $falso;
    $arq = "$falso/$nome.jsonl";
    return is_file($arq) ? array_map(static fn(string $l): array => json_decode($l, true), file($arq, FILE_IGNORE_NEW_LINES)) : [];
}

$logErros = tempnam(sys_get_temp_dir(), 'mcp-avisos-php-');
$nulo = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
$servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaSite", '-t', $raiz . '/site', '-d', 'sendmail_path=/bin/true',
    '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$logErros"], $nulo, $tubos,
    null, ['MCP_CONFIG_ARQUIVO' => $config, 'MCP_CONFIG_ESCOLA_ARQUIVO' => $semEscola, 'MCP_CONFIG_WHATSAPP_ARQUIVO' => $semEscola, 'MCP_CATALOGO_ARQUIVO' => getenv('MCP_CATALOGO_ARQUIVO'), 'PATH' => (string) getenv('PATH')]);
$servidorCloud = proc_open([PHP_BINARY, '-S', "127.0.0.1:" . ($portaSite + 1), '-t', $raiz . '/site', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$logErros"], $nulo, $tubos3,
    null, ['MCP_CONFIG_ARQUIVO' => $configCloud, 'MCP_CONFIG_ESCOLA_ARQUIVO' => $semEscola, 'MCP_CONFIG_WHATSAPP_ARQUIVO' => $semEscola, 'MCP_CATALOGO_ARQUIVO' => getenv('MCP_CATALOGO_ARQUIVO'), 'PATH' => (string) getenv('PATH')]);
$servidorFalso = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaFalso", $roteador], $nulo, $tubos2, null, ['FALSO_DIR' => $falso, 'PATH' => (string) getenv('PATH')]);
foreach ([$portaSite, $portaSite + 1, $portaFalso] as $porta) {
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $porta) === false; $i++) {
        usleep(100000);
    }
}
$base = "http://127.0.0.1:$portaSite/matricula-cursos-presenciais/api/";
$baseCloud = 'http://127.0.0.1:' . ($portaSite + 1) . '/matricula-cursos-presenciais/api/';
mcp_painel_sessao_abrir($quem);
$sessaoPortal = 'mcp_painel=' . $_COOKIE[MCP_PAINEL_COOKIE];
$csrf = static fn(string $acao, int $id): string => mcp_painel_csrf($quem, $acao, $id);

/** [status, cabeçalhos (minúsculos), corpo]. Não segue redirecionamento. */
function http(string $url, string $metodo = 'GET', ?string $corpo = null, array $cabecalhos = []): array
{
    $recebidos = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $cabecalhos, CURLOPT_TIMEOUT => 60,
        // Como um celular de verdade: sem User-Agent, o clique conta como robô (prévia de link).
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 14; teste) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
        CURLOPT_NOBODY => $metodo === 'HEAD',
        CURLOPT_HEADERFUNCTION => static function ($ch, string $linha) use (&$recebidos): int {
            if (str_contains($linha, ':')) {
                [$nome, $valor] = explode(':', $linha, 2);
                $recebidos[strtolower(trim($nome))] = trim($valor);
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

function api_avisos(string $base, array $corpo): array
{
    [$st, , $r] = http($base . 'avisos.php', 'POST', json_encode($corpo), ['Content-Type: application/json']);
    return [$st, json_decode($r, true) ?? []];
}

function portal(string $base, string $sessao, array $campos): array
{
    return http($base . 'painel.php', 'POST', http_build_query($campos), ['Content-Type: application/x-www-form-urlencoded', "Cookie: $sessao"]);
}

/** Roda um trecho de PHP noutro processo, com outra configuração (outro modo do WhatsApp). Devolve o JSON que ele imprimir. */
function sub(string $configArq, string $codigo, ?string $whatsappArq = null): mixed
{
    global $raiz, $semEscola;
    $arq = tempnam(sys_get_temp_dir(), 'mcp-avisos-sub-') . '.php';
    file_put_contents($arq, "<?php\ndeclare(strict_types=1);\n\$_SERVER['REQUEST_METHOD'] = 'CLI';\nrequire " . var_export($raiz . '/site/matricula-cursos-presenciais/api/lib.php', true)
        . ";\nrestore_exception_handler();\n" . $codigo);
    $saida = [];
    exec('MCP_CONFIG_ARQUIVO=' . escapeshellarg($configArq) . ' MCP_CONFIG_ESCOLA_ARQUIVO=' . escapeshellarg($semEscola) . ' MCP_CONFIG_WHATSAPP_ARQUIVO=' . escapeshellarg($whatsappArq ?? $semEscola)
        . ' MCP_CATALOGO_ARQUIVO=' . escapeshellarg((string) getenv('MCP_CATALOGO_ARQUIVO'))
        . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($arq) . ' 2>&1', $saida);
    unlink($arq);
    $texto = implode("\n", $saida);
    $json = json_decode($texto, true);
    return $json ?? ['_saida' => $texto];
}

/** Horário de Brasília (AAAA-MM-DD, HH:MM) em timestamp. */
function em(string $iso, string $hora): int
{
    return (int) strtotime(mcp_ponto_local_para_utc("$iso $hora:00") . ' UTC');
}

/** Cria um colaborador de teste e devolve a linha. */
function colaborador(string $nome, string $vinculo, ?string $email, ?string $telefone): array
{
    global $quem;
    $id = mcp_colaborador_salvar(null, ['nome' => $nome, 'cpf' => cpf_de_teste(), 'email' => $email, 'telefone' => $telefone, 'funcao' => null, 'vinculo' => $vinculo, 'ativo' => 1], $quem);
    return mcp_colaborador_por_id($id);
}

function linha_aviso(PDO $db, string $chave): ?array
{
    $stmt = $db->prepare('SELECT * FROM mcp_avisos WHERE chave = ?');
    $stmt->execute([$chave]);
    return $stmt->fetch() ?: null;
}

/** O token de um link pessoal: vai no fragmento (#t=); os links antigos usavam ?t=. */
function token_do_link(string $link): string
{
    return preg_match('/[#?&]t=([A-Za-z0-9_.-]+)/', $link, $m) ? $m[1] : '';
}

$hoje = mcp_ponto_hoje();
$amanha = mcp_avisos_dia_mais($hoje, 1);
$ontem = mcp_avisos_dia_mais($hoje, -1);
$diaAmanha = mcp_avisos_dia_chave($amanha);
// A equipe contratada recebe o lembrete de terça a sexta (a véspera também precisa ser dia útil).
$amanhaUtil = in_array($diaAmanha, MCP_AVISOS_DIAS_EQUIPE, true);
// Um dia assim a partir de depois de amanhã: a véspera dele (às 20h) ainda não passou, seja qual for a hora
// em que o teste roda.
$util = mcp_avisos_dia_mais($hoje, 2);
while (!in_array(mcp_avisos_dia_chave($util), MCP_AVISOS_DIAS_EQUIPE, true)) {
    $util = mcp_avisos_dia_mais($util, 1);
}

try {
    // ------------------------------------------------------------------------- funções puras
    verificar('dias: só os válidos, na ordem da semana', mcp_avisos_dias('qua, seg,xyz,DOM'), ['dom', 'seg', 'qua']);
    verificar('dias por extenso', [mcp_avisos_dias_texto(['seg', 'qua']), mcp_avisos_dias_texto(['sab']), mcp_avisos_dias_texto(['seg', 'qua', 'sex'])], ['segundas e quartas', 'sábados', 'segundas, quartas e sextas']);
    verificar('WhatsApp: celular com DDD vira 55…; fixo e inválido não', [mcp_whatsapp_numero('(21) 99876-5432'), mcp_whatsapp_numero('+55 21 99876-5432'), mcp_whatsapp_numero('(21) 3876-5432'), mcp_whatsapp_numero('123')],
        ['5521998765432', '5521998765432', null, null]);
    verificar('WhatsApp mascarado', mcp_whatsapp_mascarado('5521998765432'), '(21) 9****-5432');
    verificar('janela das 8h às 20h (Brasília)', [mcp_avisos_na_janela(em($hoje, '07:59')), mcp_avisos_na_janela(em($hoje, '08:00')), mcp_avisos_na_janela(em($hoje, '19:59')), mcp_avisos_na_janela(em($hoje, '20:00'))],
        [false, true, true, false]);
    verificar('comunicado: parágrafos, lista, passos e negrito escapados', mcp_comunicado_html("Oi *Ana*\n\n- um\n- dois <b>\n\n1. primeiro\n2. segundo"),
        mcp_p('Oi <strong>Ana</strong>') . mcp_lista(['um', 'dois &lt;b&gt;']) . mcp_comunicado_numerada(['primeiro', 'segundo']));
    verificar('comunicado em texto puro, sem os asteriscos', mcp_comunicado_texto("Oi *Ana*, tudo *bem*?"), 'Oi Ana, tudo bem?');
    $ruim = mcp_campanha_conferir(['nome' => 'X teste', 'fase' => 'livre', 'publico' => 'colaboradores', 'canais' => ['email'], 'assunto' => 'Assunto', 'titulo' => 'Título',
        'mensagem' => 'Olá {primeiro_nom}, tudo bem por aí?', 'botao' => 'ponto']);
    verificar('comunicado: campo que não existe é recusado', [$ruim['ok'], $ruim['campo'] ?? null, str_contains($ruim['erro'] ?? '', '{primeiro_nom}')], [false, 'mensagem', true]);
    $ruim = mcp_campanha_conferir(['nome' => 'X teste', 'fase' => 'livre', 'publico' => 'colaboradores', 'canais' => ['email'], 'assunto' => 'Assunto', 'titulo' => 'Título',
        'mensagem' => 'Veja em {link}, está tudo lá.', 'botao' => 'ponto']);
    verificar('comunicado: {link} no e-mail é recusado', [$ruim['ok'], $ruim['campo'] ?? null], [false, 'mensagem']);
    $ruim = mcp_campanha_conferir(['nome' => 'X teste', 'fase' => 'livre', 'publico' => 'alunos', 'canais' => ['email', 'whatsapp'], 'assunto' => 'Assunto', 'titulo' => 'Título',
        'mensagem' => 'Mensagem para os alunos.', 'whatsapp' => 'Mensagem curta do WhatsApp', 'botao' => 'ponto']);
    verificar('comunicado: WhatsApp para alunos é recusado', [$ruim['ok'], $ruim['campo'] ?? null], [false, 'canais']);

    // ------------------------------------------------------------------------- colaboradores e links pessoais
    mcp_ajuste_gravar('whatsapp_ativo', '1', $quem);
    $v1 = colaborador("Vera Voluntária $marca", 'voluntario', "vera-$sufixo@avisos-teste.example", '21998760001');
    mcp_avisos_preferencias_salvar($v1, ['email' => true, 'whatsapp' => true, 'dias' => [$diaAmanha], 'saida' => true, 'comunicados' => true], $quem, false);
    $v1 = mcp_colaborador_por_id((int) $v1['id']);
    $v2 = colaborador("Vitor Voluntário $marca", 'voluntario', "vitor-$sufixo@avisos-teste.example", null);
    $e1 = colaborador("Elias Empregado $marca", 'empregado', null, '21998760003');
    mcp_avisos_preferencias_salvar($e1, ['email' => true, 'whatsapp' => true, 'dias' => [$diaAmanha], 'saida' => true, 'comunicados' => true], $quem, false);
    $e1 = mcp_colaborador_por_id((int) $e1['id']);
    $x1 = colaborador("Xavier Sem Contato $marca", 'voluntario', null, null);
    verificar('consentimento do WhatsApp registrado com data e autor', [(int) $v1['aviso_whatsapp'], $v1['aviso_whatsapp_por'], $v1['aviso_whatsapp_em'] !== null], [1, $quem, true]);
    verificar('canais: no modo manual, o lembrete vai por e-mail e WhatsApp', array_keys(mcp_avisos_canais($v1, 'vespera')), ['email', 'whatsapp']);
    verificar('canais: sem e-mail, só WhatsApp; sem contato, nada', [array_keys(mcp_avisos_canais($e1, 'vespera')), mcp_avisos_canais($x1, 'vespera')], [['whatsapp'], []]);

    $link = mcp_avisos_link('lembretes', 'c' . $v1['id']);
    verificar('link pessoal: o token vai no fragmento (#t=), que não chega ao servidor nem ao log', str_starts_with($link, mcp_avisos_pagina('lembretes') . '#t='), true);
    $token = token_do_link($link);
    $lido = mcp_avisos_token_ler('lembretes', $token);
    verificar('token: lê a pessoa', [$lido['ok'], $lido['pessoa'] ?? null], [true, 'c' . $v1['id']]);
    verificar('token: de outro uso não vale', mcp_avisos_token_ler('saida', $token)['ok'], false);
    $adulterado = substr($token, 0, -1) . (substr($token, -1) === 'a' ? 'b' : 'a');
    verificar('token: adulterado não vale', mcp_avisos_token_ler('lembretes', $adulterado)['ok'], false);
    $vencido = token_do_link(mcp_avisos_link('lembretes', 'c' . $v1['id'], '', time() - 61 * 86400));
    verificar('token: vencido avisa que venceu', mcp_avisos_token_ler('lembretes', $vencido), ['ok' => false, 'vencido' => true]);

    // Página de lembretes (api/avisos.php).
    [$st, $r] = api_avisos($base, ['acao' => 'lembretes_ler', 't' => $token]);
    verificar('página de lembretes: lê as escolhas (e-mail e WhatsApp mascarados)', [$st, $r['nome'] ?? null, $r['email'] ?? null, $r['whatsapp'] ?? null, $r['prefs']['dias'] ?? null, $r['voluntario'] ?? null],
        [200, 'Vera', 'v***@avisos-teste.example', '(21) 9****-0001', [$diaAmanha], true]);
    [$st, $r] = api_avisos($base, ['acao' => 'lembretes_ler', 't' => $adulterado]);
    verificar('página de lembretes: link adulterado é recusado', [$st, $r['motivo'] ?? null], [404, 'invalido']);
    [$st, $r] = api_avisos($base, ['acao' => 'lembretes_ler', 't' => $vencido]);
    verificar('página de lembretes: link vencido', [$st, $r['motivo'] ?? null], [410, 'vencido']);
    [$st, $r] = api_avisos($base, ['acao' => 'lembretes_salvar', 't' => $token, 'dias' => [$diaAmanha, 'xyz'], 'email' => true, 'whatsapp' => true, 'telefone' => '(21) 3333-4444', 'saida' => true, 'comunicados' => true]);
    verificar('página de lembretes: telefone fixo para o WhatsApp é recusado', [$st, $r['campo'] ?? null], [422, 'telefone']);
    [$st, $r] = api_avisos($base, ['acao' => 'lembretes_salvar', 't' => $token, 'dias' => [$diaAmanha], 'email' => true, 'whatsapp' => true, 'telefone' => '(21) 99876-9999', 'saida' => true, 'comunicados' => true]);
    verificar('página de lembretes: trocar o número já cadastrado, só pela secretaria (link encaminhado não desvia as mensagens)', [$st, $r['campo'] ?? null, $r['erro'] ?? null, mcp_colaborador_por_id((int) $v1['id'])['telefone']],
        [422, 'telefone', 'Para trocar o número do WhatsApp, fale com a secretaria.', '21998760001']);
    [$st, $r] = api_avisos($base, ['acao' => 'lembretes_salvar', 't' => $token, 'dias' => [$diaAmanha, 'xyz'], 'email' => true, 'whatsapp' => true, 'saida' => true, 'comunicados' => true]);
    $v1 = mcp_colaborador_por_id((int) $v1['id']);
    verificar('página de lembretes: salva e resume o que ficou', [$st, str_starts_with($r['mensagem'] ?? '', 'Pronto! Você recebe o lembrete na véspera das'), $v1['aviso_dias'], $v1['aviso_whatsapp_por']],
        [200, true, $diaAmanha, $quem]);
    $tokenAntigo = $token;
    mcp_avisos_chave_colaborador($v1, true);
    $token = token_do_link(mcp_avisos_link('lembretes', 'c' . $v1['id']));
    verificar('chave nova: os links já mandados deixam de valer; os novos valem', [mcp_avisos_token_ler('lembretes', $tokenAntigo)['ok'], mcp_avisos_token_ler('lembretes', $token)['ok']], [false, true]);

    // ------------------------------------------------------------------------- lembrete da véspera
    mcp_ajuste_gravar('lembrete_vespera', '1', $quem);
    verificar('véspera: antes das 8h não prepara nada', mcp_avisos_preparar_vespera(em($hoje, '07:50')), 0);
    verificar('feriados: com AVISOS_FERIADOS = 0, o Natal não fecha a sede', mcp_avisos_dia_fechado(substr($hoje, 0, 4) . '-12-25'), null);
    mcp_ajuste_gravar('dias_fechados', $amanha, $quem);
    verificar('véspera: amanhã sem expediente (marcado no portal), nada é preparado', [mcp_avisos_dia_fechado($amanha), mcp_avisos_preparar_vespera(em($hoje, '08:02'))], ['dia sem expediente na sede', 0]);
    mcp_ajuste_gravar('dias_fechados', '', $quem);
    $preparados = mcp_avisos_preparar_vespera(em($hoje, '08:05'));
    verificar('véspera: às 8h prepara a da voluntária (e-mail e WhatsApp); o empregado com os dias marcados pela secretaria fica de fora',
        [$preparados, linha_aviso($db, "vespera|{$e1['id']}|$amanha|whatsapp")], [2, null]);
    verificar('véspera: rodar de novo não duplica', mcp_avisos_preparar_vespera(em($hoje, '09:20')), 0);
    // Empregado: o lembrete só sai se foi ele quem escolheu os dias, e só em dia útil (senão pareceria controle de jornada).
    mcp_avisos_preferencias_salvar($e1, ['email' => true, 'whatsapp' => true, 'dias' => [$diaAmanha], 'saida' => true, 'comunicados' => true], 'a própria pessoa', true);
    $e1 = mcp_colaborador_por_id((int) $e1['id']);
    verificar('véspera: o empregado que escolheu os dias recebe, só com o dia e a véspera úteis (' . ($amanhaUtil ? 'amanhã vale' : 'amanhã não vale') . '; sábado e segunda, não)',
        [$e1['aviso_dias_por'], mcp_avisos_preparar_vespera(em($hoje, '09:30')), mcp_avisos_recebe_vespera($e1, $util), mcp_avisos_recebe_vespera($e1, '2026-10-03'), mcp_avisos_recebe_vespera($e1, '2026-10-05')],
        ['a própria pessoa', $amanhaUtil ? 1 : 0, true, false, false]);
    // Véspera em dia sem expediente (quarta fechada): o lembrete de quinta não sai para a equipe; o voluntário recebe.
    $diasFechadosAntes = mcp_ajuste('dias_fechados');
    mcp_ajuste_gravar('dias_fechados', '2026-10-07', $quem);
    verificar('véspera: com a véspera fechada, a equipe não recebe; o voluntário sim', [mcp_avisos_recebe_vespera($e1, '2026-10-08'), mcp_avisos_recebe_vespera($v1, '2026-10-08')], [false, true]);
    mcp_ajuste_gravar('dias_fechados', $diasFechadosAntes, $quem);
    $av = linha_aviso($db, "vespera|{$v1['id']}|$amanha|email");
    verificar('véspera: sai às 18h e vale até as 20h (depois, "amanhã" já estaria errado)', [$av['status'], mcp_data_brt($av['agendado_para'], 'Y-m-d H:i'), mcp_data_brt($av['expira_em'], 'Y-m-d H:i')],
        ['pendente', "$hoje 18:00", "$hoje 20:00"]);
    verificar('véspera: WhatsApp no modo manual vai para a fila', linha_aviso($db, "vespera|{$v1['id']}|$amanha|whatsapp")['status'], 'manual');
    verificar('envio: ao meio-dia ainda não sai (é para as 18h)', mcp_avisos_enviar_pendentes(em($hoje, '12:00')), [0, 0]);
    $antesEmails = count(falso('emails'));
    verificar('envio: às 18h sai o e-mail', mcp_avisos_enviar_pendentes(em($hoje, '18:00')), [1, 0]);
    $email = falso('emails')[$antesEmails] ?? [];
    verificar('e-mail da véspera: destinatário, assunto, remetente e responder-para', [$email['to'] ?? null, str_starts_with($email['subject'] ?? '', 'Se vier amanhã ('), $email['from'] ?? null, $email['reply_to'] ?? null],
        [["vera-$sufixo@avisos-teste.example"], true, 'Cruz Vermelha Brasileira Rio de Janeiro <ponto@info.exemplo.org>', 'contato@exemplo.org']);
    verificar('e-mail da véspera: descadastro de um clique (List-Unsubscribe) apontando para o site', [
        (bool) preg_match('~^<http://127\.0\.0\.1:\d+/matricula-cursos-presenciais/api/avisos\.php\?u=\d+\.[a-f0-9]{16}>$~', (string) ($email['headers']['List-Unsubscribe'] ?? '')),
        $email['headers']['List-Unsubscribe-Post'] ?? null], [true, 'List-Unsubscribe=One-Click']);
    verificar('e-mail da véspera: botão do ponto e link para mudar os dias', [str_contains($email['html'] ?? '', 'Abrir o ponto'), (bool) preg_match('~avisos\.php\?r=\d+\.p\.[a-f0-9]{16}~', $email['html'] ?? ''),
        (bool) preg_match('~avisos\.php\?r=\d+\.l\.[a-f0-9]{16}~', $email['html'] ?? ''), str_contains($email['html'] ?? '', 'Se não puder vir, tudo bem')], [true, true, true, true]);
    $av = linha_aviso($db, "vespera|{$v1['id']}|$amanha|email");
    verificar('envio: registra provedor, assunto e texto', [$av['status'], $av['provedor'], $av['assunto'] === $email['subject'], str_contains((string) $av['texto'], 'Ao chegar')], ['enviado', 'resend', true, true]);
    $fila = mcp_avisos_fila_manual(em($hoje, '18:00'));
    $daVera = array_values(array_filter($fila, static fn(array $f): bool => (int) $f['colaborador_id'] === (int) $v1['id']))[0] ?? null;
    $doElias = array_values(array_filter($fila, static fn(array $f): bool => (int) $f['colaborador_id'] === (int) $e1['id']))[0] ?? null;
    verificar('fila do WhatsApp: link wa.me com o número e o texto pronto (e o texto da equipe, sem falar em horas)', [count($fila), str_starts_with($daVera['link_whatsapp'] ?? '', 'https://wa.me/5521998760001?text='),
        str_contains(rawurldecode($daVera['link_whatsapp'] ?? ''), 'registre a *chegada* e a *saída*'), $amanhaUtil ? str_contains((string) ($doElias['texto_pronto'] ?? ''), 'não é o ponto oficial') : true],
        [$amanhaUtil ? 2 : 1, true, true, true]);
    verificar('fila do WhatsApp: marcar como enviada', [mcp_avisos_fila_marcar((int) $daVera['id'], true, $quem), linha_aviso($db, "vespera|{$v1['id']}|$amanha|whatsapp")['status'],
        linha_aviso($db, "vespera|{$v1['id']}|$amanha|whatsapp")['enviado_por']], [true, 'enviado', $quem]);
    // Modo manual: o WhatsApp que a secretaria já mandou dispensa o e-mail do mesmo lembrete (sem aviso em dobro).
    $proximaSemana = mcp_avisos_dia_mais($amanha, 7);
    $idEmailDobro = (int) mcp_aviso_criar(['chave' => "vespera|{$v1['id']}|$proximaSemana|email", 'tipo' => 'vespera', 'canal' => 'email', 'colaborador_id' => (int) $v1['id'],
        'pessoa' => 'c' . $v1['id'], 'nome' => "Vera $marca", 'destino' => "vera-$sufixo@avisos-teste.example", 'referencia' => $proximaSemana]);
    $idWhatsDobro = (int) mcp_aviso_criar(['chave' => "vespera|{$v1['id']}|$proximaSemana|whatsapp", 'tipo' => 'vespera', 'canal' => 'whatsapp', 'colaborador_id' => (int) $v1['id'],
        'pessoa' => 'c' . $v1['id'], 'nome' => "Vera $marca", 'destino' => '5521998760001', 'referencia' => $proximaSemana]);
    $antesEmails = count(falso('emails'));
    mcp_avisos_fila_marcar($idWhatsDobro, true, $quem);
    verificar('modo manual: WhatsApp já mandado pela fila dispensa o e-mail do mesmo lembrete', [mcp_aviso_enviar(mcp_aviso_por_id($idEmailDobro), time()),
        mcp_aviso_por_id($idEmailDobro)['erro'], count(falso('emails')) - $antesEmails], ['cancelado', 'já foi pelo WhatsApp', 0]);
    // Quem desliga depois do preparo não recebe.
    $v3 = colaborador("Vânia Voluntária $marca", 'voluntario', "vania-$sufixo@avisos-teste.example", null);
    mcp_avisos_preferencias_salvar($v3, ['email' => true, 'whatsapp' => false, 'dias' => [$diaAmanha], 'saida' => true, 'comunicados' => true], $quem, false);
    mcp_avisos_preparar_vespera(em($hoje, '10:00'));
    mcp_avisos_preferencias_salvar(mcp_colaborador_por_id((int) $v3['id']), ['email' => true, 'whatsapp' => false, 'dias' => [], 'saida' => true, 'comunicados' => true], $quem, false);
    mcp_avisos_enviar_pendentes(em($hoje, '18:01'));
    $av = linha_aviso($db, "vespera|{$v3['id']}|$amanha|email");
    verificar('véspera: quem tirou o dia depois do preparo não recebe', [$av['status'], $av['erro']], ['cancelado', 'tirou este dia dos lembretes']);
    // Fora da janela e vencimento.
    $v4 = colaborador("Valter Voluntário $marca", 'voluntario', "valter-$sufixo@avisos-teste.example", null);
    mcp_avisos_preferencias_salvar($v4, ['email' => true, 'whatsapp' => false, 'dias' => [$diaAmanha], 'saida' => true, 'comunicados' => true], $quem, false);
    mcp_avisos_preparar_vespera(em($hoje, '19:00'));
    verificar('envio: às 20h30 não sai nada', mcp_avisos_enviar_pendentes(em($hoje, '20:30')), [0, 0]);
    verificar('envio: às 7h30 de amanhã também não', mcp_avisos_enviar_pendentes(em($amanha, '07:30')), [0, 0]);
    mcp_avisos_expirar(em($hoje, '20:01'));
    verificar('véspera: o que não saiu até as 20h vence', linha_aviso($db, "vespera|{$v4['id']}|$amanha|email")['status'], 'expirado');

    // ------------------------------------------------------------------------- saída não registrada
    mcp_ajuste_gravar('lembrete_saida', '1', $quem);
    $db->prepare("INSERT INTO mcp_ponto (colaborador_id, voluntario, entrada, origem_entrada, criado_em, atualizado_em) VALUES (?, 1, ?, 'aparelho', ?, ?)")
        ->execute([(int) $v1['id'], mcp_ponto_local_para_utc("$ontem 09:00:00"), mcp_agora(), mcp_agora()]);
    $pontoVera = (int) $db->lastInsertId();
    verificar('saída: antes das 9h não avisa', mcp_avisos_preparar_saida(em($hoje, '08:30')), 0);
    verificar('saída: às 9h avisa a voluntária (e-mail e WhatsApp)', mcp_avisos_preparar_saida(em($hoje, '09:05')), 2);
    $antesEmails = count(falso('emails'));
    mcp_avisos_enviar_pendentes(em($hoje, '09:06'));
    $email = falso('emails')[$antesEmails] ?? [];
    verificar('e-mail da saída: assunto e link para informar', [str_starts_with($email['subject'] ?? '', 'Suas horas de ' . mcp_avisos_dia_texto($ontem) . ': quer informar a saída?'), (bool) preg_match('~avisos\.php\?r=(\d+\.s\.[a-f0-9]{16})~', $email['html'] ?? '', $m)],
        [true, true]);
    $r = $m[1] ?? '';
    [$st, $cab] = http($base . 'avisos.php?r=' . $r, 'HEAD');
    $avId = (int) explode('.', $r)[0];
    verificar('clique: a prévia (HEAD) não conta', [(int) mcp_aviso_por_id($avId)['cliques']], [0]);
    [$st, $cab] = http($base . 'avisos.php?r=' . $r);
    $destino = $cab['location'] ?? '';
    verificar('clique: conta e leva à página da saída com um link novo', [$st, str_contains($destino, '/matricula-cursos-presenciais/ponto/saida/#t='), (int) mcp_aviso_por_id($avId)['cliques'], mcp_aviso_por_id($avId)['clicado_em'] !== null],
        [302, true, 1, true]);
    [$st, $cab] = http($base . 'avisos.php?r=' . $avId . '.s.0000000000000000');
    verificar('clique: assinatura errada vai para o ponto e não conta', [$st, $cab['location'] ?? '', (int) mcp_aviso_por_id($avId)['cliques']], [302, "http://127.0.0.1:$portaSite/ponto/", 1]);
    $tokenSaida = token_do_link($destino);
    [$st, $r2] = api_avisos($base, ['acao' => 'saida_ler', 't' => $tokenSaida]);
    verificar('página da saída: mostra o dia e a entrada', [$st, $r2['quando'] ?? null, $r2['entrada'] ?? null, $r2['pode'] ?? null], [200, 'ontem (' . mcp_avisos_dia_texto($ontem) . ')', '09:00', true]);
    [$st, $r2] = api_avisos($base, ['acao' => 'saida_informar', 't' => $tokenSaida, 'hora' => '08:30']);
    verificar('página da saída: hora antes da entrada é recusada', [$st, $r2['erro'] ?? null], [422, 'A saída precisa ser depois da entrada, às 09:00.']);
    [$st, $r2] = api_avisos($base, ['acao' => 'saida_informar', 't' => $tokenSaida, 'hora' => '17:30']);
    $reg = mcp_ponto_registro($pontoVera);
    verificar('página da saída: grava a saída informada (a conferir)', [$st, $r2['informada'] ?? null, mcp_data_brt($reg['saida_informada'], 'H:i'), $reg['saida']], [200, '17:30', '17:30', null]);
    verificar('saída informada: não conta como "na sede" nem como esquecida', [mcp_ponto_aberto((int) $v1['id']) === null, mcp_ponto_saidas_informadas_contar() >= 1], [true, true]);
    $avWhats = linha_aviso($db, "saida|$pontoVera|whatsapp");
    verificar('saída informada: a mensagem que estava na fila deixa de valer', [mcp_avisos_fila_manual() === [] || !in_array((int) $avWhats['id'], array_map('intval', array_column(mcp_avisos_fila_manual(), 'id')), true),
        mcp_aviso_por_id((int) $avWhats['id'])['status']], [true, 'cancelado']);
    $vistoVera = (string) mcp_ponto_registro($pontoVera)['saida_informada'];
    [$st, , $html] = portal($base, $sessaoPortal, ['acao' => 'saida_aceitar', 'id' => $pontoVera, 't' => $csrf('saida_aceitar', $pontoVera), 'visto' => '2026-01-01 10:00:00']);
    verificar('portal: se o horário mudou depois de abrir a lista, o aceite pede para conferir de novo', [$st, str_contains($html, 'O horário informado mudou'), mcp_ponto_registro($pontoVera)['saida']], [200, true, null]);
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'saida_aceitar', 'id' => $pontoVera, 't' => $csrf('saida_aceitar', $pontoVera), 'visto' => $vistoVera]);
    $reg = mcp_ponto_registro($pontoVera);
    verificar('portal: aceitar a saída informada', [$st, str_ends_with($cab['location'] ?? '', 'ok=sd_ok'), mcp_data_brt($reg['saida'], 'H:i'), $reg['origem_saida'], $reg['saida_informada'],
        str_contains((string) $reg['ajuste'], 'aceitou a saída informada pela pessoa')], [303, true, '17:30', 'informada', null, true]);
    [$st, $r2] = api_avisos($base, ['acao' => 'saida_informar', 't' => $tokenSaida, 'hora' => '18:00']);
    verificar('página da saída: depois de aceita, não muda mais', [$st, $r2['erro'] ?? null], [422, 'A saída deste dia já está registrada.']);

    // Pela tela do ponto, com recusa no portal. Só no aparelho da sede: no celular, quem digita um CPF
    // (que não é segredo) não pode ver nem mexer nas horas de outra pessoa.
    $db->prepare("INSERT INTO mcp_ponto (colaborador_id, voluntario, entrada, origem_entrada, criado_em, atualizado_em) VALUES (?, 1, ?, 'celular', ?, ?)")
        ->execute([(int) $v2['id'], mcp_ponto_local_para_utc("$ontem 14:00:00"), mcp_agora(), mcp_agora()]);
    $pontoVitor = (int) $db->lastInsertId();
    $perto = ['lat' => -22.9115, 'lng' => -43.1880, 'precisao' => 20];
    [$st, , $corpo] = http($base . 'ponto.php', 'POST', json_encode(['acao' => 'identificar', 'cpf' => $v2['cpf'], 'posicao' => $perto]), ['Content-Type: application/json']);
    $id = json_decode($corpo, true) ?? [];
    [$stInf] = http($base . 'ponto.php', 'POST', json_encode(['acao' => 'informar_saida', 'sessao' => $id['sessao'] ?? '', 'registro' => $pontoVitor, 'hora' => '19:10']), ['Content-Type: application/json']);
    verificar('ponto no celular: não mostra a saída pendente nem deixa informar', [$st, $id['pendencias'] ?? null, $stInf, mcp_ponto_registro($pontoVitor)['saida_informada']], [200, [], 403, null]);
    $aparelhoId = mcp_ponto_aparelho_criar("Tablet $marca", $quem);
    $cAparelho = 'Cookie: ' . MCP_PONTO_COOKIE_APARELHO . '=' . mcp_ponto_aparelho_cookie_valor($aparelhoId);
    [$st, , $corpo] = http($base . 'ponto.php', 'POST', json_encode(['acao' => 'identificar', 'cpf' => $v2['cpf']]), ['Content-Type: application/json', $cAparelho]);
    $id = json_decode($corpo, true) ?? [];
    verificar('ponto no aparelho da sede: mostra a saída sem registro de ontem', [$st, count($id['pendencias'] ?? []), $id['pendencias'][0]['entrada'] ?? null, $id['colaborador']['na_sede'] ?? null], [200, 1, '14:00', false]);
    [$st, , $corpo] = http($base . 'ponto.php', 'POST', json_encode(['acao' => 'informar_saida', 'sessao' => $id['sessao'] ?? '', 'registro' => $pontoVitor, 'hora' => '19:10']), ['Content-Type: application/json', $cAparelho]);
    $inf = json_decode($corpo, true) ?? [];
    verificar('ponto no aparelho da sede: informa a hora da saída ali mesmo', [$st, $inf['registrado'] ?? null, $inf['pendencias'][0]['informada'] ?? null], [200, 'saida_informada', '19:10']);
    [$st, , $corpo] = http($base . 'ponto.php', 'POST', json_encode(['acao' => 'informar_saida', 'sessao' => $id['sessao'] ?? '', 'registro' => $pontoVera, 'hora' => '19:10']), ['Content-Type: application/json', $cAparelho]);
    verificar('ponto no aparelho da sede: não informa a saída de outra pessoa', $st, 404);
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'saida_recusar', 'id' => $pontoVitor, 't' => $csrf('saida_recusar', $pontoVitor), 'motivo' => 'saiu mais cedo']);
    $reg = mcp_ponto_registro($pontoVitor);
    verificar('portal: recusar a saída informada', [$st, str_ends_with($cab['location'] ?? '', 'ok=sd_rec'), $reg['saida'], $reg['saida_informada'], str_contains((string) $reg['ajuste'], 'recusou a saída informada (')],
        [303, true, null, null, true]);

    // ------------------------------------------------------------------------- comunicados das três fases
    $lancamento = mcp_avisos_dia_mais($hoje, 6);
    verificar('implantação: prepara os quatro comunicados como rascunho', mcp_campanhas_preparar_implantacao($lancamento, $quem), ['criados' => 4, 'recalculados' => 0, 'agendados' => 0]);
    $camp = [];
    foreach (mcp_campanhas_listar() as $c) {
        $camp[$c['fase'] . '|' . $c['publico']] = $c;
    }
    verificar('implantação: datas a partir do lançamento', [mcp_data_brt($camp['antes|colaboradores']['agendada_para'], 'Y-m-d H:i'), mcp_data_brt($camp['durante|colaboradores']['agendada_para'], 'Y-m-d H:i'),
        mcp_data_brt($camp['depois|colaboradores']['agendada_para'], 'Y-m-d H:i'), $camp['antes|colaboradores']['status']],
        [mcp_avisos_dia_mais($lancamento, -5) . ' 10:00', "$lancamento 08:30", mcp_avisos_dia_mais($lancamento, 14) . ' 10:00', 'rascunho']);
    verificar('implantação: rodar de novo não duplica', mcp_campanhas_preparar_implantacao($lancamento, $quem), ['criados' => 0, 'recalculados' => 0, 'agendados' => 0]);
    verificar('implantação: outra data recalcula os rascunhos', [mcp_campanhas_preparar_implantacao(mcp_avisos_dia_mais($lancamento, 1), $quem), mcp_ajuste('lancamento')],
        [['criados' => 0, 'recalculados' => 4, 'agendados' => 0], mcp_avisos_dia_mais($lancamento, 1)]);
    mcp_campanhas_preparar_implantacao($lancamento, $quem);
    $antes = $camp['antes|colaboradores'];
    verificar('agendar no passado é recusado', mcp_campanha_agendar((int) $antes['id'], gmdate('Y-m-d H:i:s', time() - 3600), $quem), 'A data do agendamento já passou. Escolha outra ou use "Enviar agora".');
    $quando = em(mcp_avisos_dia_mais($lancamento, -5), '10:00');
    verificar('agendar para a data sugerida', mcp_campanha_agendar((int) $antes['id'], gmdate('Y-m-d H:i:s', $quando), $quem, $quando - 86400), null);
    $contagem = mcp_campanha_contagem(mcp_campanha((int) $antes['id']));
    verificar('público: quem recebe e por qual canal', [$contagem['pessoas'], $contagem['email'], $contagem['whatsapp'], $contagem['sem_contato']], [6, 4, 2, 1]);
    verificar('disparo: antes da hora não sai', mcp_campanhas_disparar($quando - 60), 0);
    $criadas = mcp_campanhas_disparar($quando + 60);
    verificar('disparo: na hora, uma mensagem por pessoa e canal', [$criadas, mcp_campanha((int) $antes['id'])['status']], [6, 'enviando']);
    verificar('disparo: não dispara duas vezes', mcp_campanhas_disparar($quando + 120), 0);
    $antesEmails = count(falso('emails'));
    mcp_avisos_enviar_pendentes($quando + 180);
    mcp_campanhas_concluir($quando + 180);
    $emails = array_slice(falso('emails'), $antesEmails);
    $paraVera = array_values(array_filter($emails, static fn(array $e): bool => ($e['to'][0] ?? '') === "vera-$sufixo@avisos-teste.example"))[0] ?? [];
    verificar('comunicado "antes": e-mails saíram e o comunicado fica enviado (a fila manual não segura)', [count($emails), mcp_campanha((int) $antes['id'])['status']], [4, 'enviada']);
    verificar('comunicado "antes": data do lançamento, frase do voluntário e botão dos lembretes', [str_contains($paraVera['subject'] ?? '', mcp_comunicado_data($lancamento, true)),
        str_contains($paraVera['html'] ?? '', 'Para você, que é voluntário'), str_contains($paraVera['html'] ?? '', 'Escolher meus lembretes'), (bool) preg_match('~avisos\.php\?r=\d+\.l\.~', $paraVera['html'] ?? '')],
        [true, true, true, true]);
    $avElias = linha_aviso($db, "campanha|{$antes['id']}|c{$e1['id']}|whatsapp");
    $msgElias = mcp_aviso_mensagem($avElias, mcp_aviso_destinatario($avElias), time());
    verificar('comunicado "antes": WhatsApp do empregado com a frase da presença', [$avElias['status'], str_contains($msgElias['whatsapp'], 'Para a equipe contratada, é só a presença na sede, por segurança')], ['manual', true]);
    $num = mcp_campanha_numeros((int) $antes['id']);
    verificar('números do comunicado', [$num['total'], $num['enviado'], $num['manual'], $num['falhou']], [6, 4, 2, 0]);
    // Nome com HTML não vira HTML no e-mail.
    $xss = colaborador("<b>Negrito</b> Ana $marca", 'voluntario', "ana-$sufixo@avisos-teste.example", null);
    $msg = mcp_campanha_mensagem(mcp_campanha((int) $antes['id']), ['publico' => 'colaborador', 'nome' => $xss['nome'], 'colaborador' => $xss, 'pessoa' => 'c' . $xss['id'], 'email' => $xss['email']], 0, time());
    verificar('comunicado: nome com HTML sai escapado', [str_contains($msg['html'], '<b>Negrito</b>'), str_contains($msg['html'], '&lt;b&gt;')], [false, true]);
    $db->prepare('UPDATE mcp_colaboradores SET ativo = 0 WHERE id = ?')->execute([(int) $xss['id']]);
    // Desagendar, apagar.
    $durante = $camp['durante|colaboradores'];
    mcp_campanha_agendar((int) $durante['id'], gmdate('Y-m-d H:i:s', time() + 86400), $quem);
    verificar('desagendar volta a rascunho', [mcp_campanha_cancelar((int) $durante['id'], $quem), mcp_campanha((int) $durante['id'])['status']], [true, 'rascunho']);
    verificar('apagar um rascunho', [mcp_campanha_apagar((int) $camp['depois|alunos']['id'], $quem), mcp_campanha((int) $camp['depois|alunos']['id'])], [true, null]);
    verificar('não apaga o que já saiu', mcp_campanha_apagar((int) $antes['id'], $quem), false);

    // Opinião (comunicado "depois").
    $depois = $camp['depois|colaboradores'];
    $quandoDepois = em(mcp_avisos_dia_mais($lancamento, 14), '10:00');
    mcp_campanha_agendar((int) $depois['id'], gmdate('Y-m-d H:i:s', $quandoDepois), $quem, $quandoDepois - 60);
    mcp_campanhas_disparar($quandoDepois + 30);
    $telaCelular = mcp_comunicacao_avisos_ponto(mcp_colaborador_por_id((int) $v2['id']), null, true, $quandoDepois + 3600);
    $telaAparelho = mcp_comunicacao_avisos_ponto(mcp_colaborador_por_id((int) $v2['id']), null, false, $quandoDepois + 3600);
    verificar('aviso na tela do ponto: o comunicado da opinião aparece sem link pessoal (a dica aponta o e-mail ou o WhatsApp)',
        [count($telaCelular), array_key_exists('link', $telaCelular[0] ?? []) ? $telaCelular[0]['link'] : 'sem aviso', $telaCelular[0]['dica'] ?? null, isset($telaAparelho[0]) && $telaAparelho[0]['link'] === null ? 'sem link' : 'com link ou sem aviso'],
        [1, null, 'O link para responder está no e-mail ou no WhatsApp que você recebeu.', 'sem link']);
    $tokOpiniao = token_do_link(mcp_avisos_link('opiniao', 'c' . $v2['id'], (string) $depois['id']));
    [$st, $r] = api_avisos($base, ['acao' => 'opiniao_ler', 't' => $tokOpiniao]);
    verificar('opinião: abre com as perguntas', [$st, $r['nome'] ?? null, $r['publico'] ?? null, array_keys($r['opcoes']['lembretes'] ?? [])], [200, 'Vitor', 'colaborador', array_keys(MCP_OPINIAO_LEMBRETES)]);
    [$st, $r] = api_avisos($base, ['acao' => 'opiniao_salvar', 't' => $tokOpiniao, 'facilidade' => 0]);
    verificar('opinião: sem a nota é recusada', [$st, $r['campo'] ?? null], [422, 'facilidade']);
    [$st, $r] = api_avisos($base, ['acao' => 'opiniao_salvar', 't' => $tokOpiniao, 'facilidade' => 4, 'como' => 'celular', 'problemas' => ['localizacao', 'inventado'], 'lembretes' => 'ajudam',
        'comentario' => "A localização demora.\nMas funciona.", 'contato_ok' => false]);
    [$st2, $r2] = api_avisos($base, ['acao' => 'opiniao_salvar', 't' => $tokOpiniao, 'facilidade' => 5, 'como' => 'celular', 'problemas' => [], 'lembretes' => 'ajudam', 'comentario' => 'Agora ficou ótimo.', 'contato_ok' => true]);
    $res = mcp_opinioes_resultado((int) $depois['id']);
    verificar('opinião: grava e deixa mudar (uma resposta por pessoa)', [$st, $st2, $res['respostas'], $res['media'], $res['comentarios'][0]['nome'] ?? null], [200, 200, 1, 5.0, "Vitor Voluntário $marca"]);
    verificar('aviso na tela do ponto: some depois de responder', mcp_comunicacao_avisos_ponto(mcp_colaborador_por_id((int) $v2['id']), null, true, $quandoDepois + 3600), []);
    $tokAluno = token_do_link(mcp_avisos_link('opiniao', 'a' . mcp_avisos_hash('email', "aluno-$sufixo@avisos-teste.example"), (string) $depois['id']));
    [$st, $r] = api_avisos($base, ['acao' => 'opiniao_salvar', 't' => $tokAluno, 'facilidade' => 3, 'como' => 'aparelho', 'contato_ok' => true]);
    verificar('opinião de aluno: contato pedido sem nome é recusado', [$st, $r['campo'] ?? null], [422, 'contato']);
    [$st, $r] = api_avisos($base, ['acao' => 'opiniao_salvar', 't' => $tokAluno, 'facilidade' => 3, 'como' => 'aparelho', 'contato_ok' => false, 'comentario' => 'O tablet estava desligado.']);
    $res = mcp_opinioes_resultado((int) $depois['id']);
    $doAluno = array_values(array_filter($res['comentarios'], static fn(array $c): bool => $c['publico'] === 'aluno'))[0] ?? ['nome' => 'sem comentário'];
    verificar('opinião de aluno: sem nome no resultado', [$st, $res['respostas'], $res['por_publico']['aluno'], $doAluno['nome']], [200, 2, 1, null]);

    // ------------------------------------------------------------------------- WhatsApp pela API oficial (outro processo, outra configuração)
    $db->prepare("UPDATE mcp_colaboradores SET aviso_dias = 'dom,seg,ter,qua,qui,sex,sab' WHERE id = ?")->execute([(int) $v1['id']]);
    $depoisAmanha = mcp_avisos_dia_mais($hoje, 2);
    $r = sub($configCloud, '$n = mcp_avisos_preparar_vespera(' . em($amanha, '08:05') . '); [$e, $f] = mcp_avisos_enviar_pendentes(' . em($amanha, '18:00') . ', 50);'
        . ' echo json_encode(["preparados" => $n, "enviados" => $e, "falhas" => $f, "modo" => mcp_whatsapp_modo(), "fila" => mcp_avisos_fila_manual_contar(' . em($amanha, '18:00') . ')]);');
    verificar('API oficial: prepara o lembrete só pelo WhatsApp', [$r['modo'] ?? null, $r['preparados'] ?? null, $r['falhas'] ?? null], ['cloud', 1, 0]);
    $graph = falso('graph');
    $daVeraCloud = array_values(array_filter($graph, static fn(array $g): bool => ($g['to'] ?? '') === '5521998760001' && ($g['template']['name'] ?? '') === 'cvb_ponto_vespera'))[0] ?? [];
    // Trocar o modo com mensagens na fila manual: o que ainda vale sai sozinho, com o modelo aprovado da fase.
    $avAntesElias = linha_aviso($db, "campanha|{$antes['id']}|c{$e1['id']}|whatsapp");
    verificar('API oficial: o que esperava na fila manual sai sozinho, com o modelo da fase', [$r['enviados'] ?? null, $r['fila'] ?? null, $avAntesElias['status'], $avAntesElias['provedor'],
        count(array_filter($graph, static fn(array $g): bool => ($g['template']['name'] ?? '') === 'cvb_ponto_novidade'))], [3, 0, 'enviado', 'cloud', 2]);
    verificar('API oficial: modelo, idioma, parâmetros e botão', [$daVeraCloud['template']['name'] ?? null, $daVeraCloud['template']['language']['code'] ?? null,
        $daVeraCloud['template']['components'][0]['parameters'][0]['text'] ?? null, $daVeraCloud['template']['components'][0]['parameters'][1]['text'] ?? null,
        (bool) preg_match('~^\d+\.l\.[a-f0-9]{16}$~', (string) ($daVeraCloud['template']['components'][1]['parameters'][0]['text'] ?? ''))],
        ['cvb_ponto_vespera', 'pt_BR', 'Vera', mcp_avisos_dia_texto($depoisAmanha), true]);
    $avCloud = linha_aviso($db, "vespera|{$v1['id']}|$depoisAmanha|whatsapp");
    verificar('API oficial: guarda o id da mensagem', [$avCloud['status'], $avCloud['provedor'], str_starts_with((string) $avCloud['provedor_id'], 'wamid.teste-')], ['enviado', 'cloud', true]);
    verificar('API oficial: com o WhatsApp automático, o lembrete não vai também por e-mail', linha_aviso($db, "vespera|{$v1['id']}|$depoisAmanha|email"), null);
    $evento = static fn(array $statuses = [], array $messages = []): string => (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => array_filter(['statuses' => $statuses, 'messages' => $messages])]]]]]);
    $assinar = static fn(string $corpo): string => 'X-Hub-Signature-256: sha256=' . hash_hmac('sha256', $corpo, 'segredo-do-app-de-teste');
    $corpo = $evento([['id' => $avCloud['provedor_id'], 'status' => 'delivered'], ['id' => $avCloud['provedor_id'], 'status' => 'read']]);
    [$st, , $resp] = http($baseCloud . 'whatsapp.php', 'POST', $corpo, ['Content-Type: application/json', $assinar($corpo)]);
    verificar('webhook: situação entregue e lida', [$st, linha_aviso($db, "vespera|{$v1['id']}|$depoisAmanha|whatsapp")['entrega']], [200, 'read']);
    $corpo = $evento([['id' => $avCloud['provedor_id'], 'status' => 'delivered']]);
    http($baseCloud . 'whatsapp.php', 'POST', $corpo, ['Content-Type: application/json', $assinar($corpo)]);
    verificar('webhook: evento fora de ordem não faz a situação voltar', linha_aviso($db, "vespera|{$v1['id']}|$depoisAmanha|whatsapp")['entrega'], 'read');
    [$st] = http($baseCloud . 'whatsapp.php', 'POST', $corpo, ['Content-Type: application/json', 'X-Hub-Signature-256: sha256=' . str_repeat('0', 64)]);
    verificar('webhook: assinatura errada é recusada', $st, 401);
    // A Meta avisa depois que não entregou: o lembrete vai por e-mail (se ainda der tempo), com o código do erro no registro.
    $semanaQueVem = mcp_avisos_dia_mais($util, 7);
    $idFalha = (int) mcp_aviso_criar(['chave' => "vespera|{$v1['id']}|$semanaQueVem|whatsapp", 'tipo' => 'vespera', 'canal' => 'whatsapp', 'colaborador_id' => (int) $v1['id'], 'pessoa' => 'c' . $v1['id'],
        'nome' => "Vera $marca", 'destino' => '5521998760001', 'referencia' => $semanaQueVem, 'expira_em' => mcp_aviso_validade(['tipo' => 'vespera', 'referencia' => $semanaQueVem], time())]);
    $db->prepare("UPDATE mcp_avisos SET status = 'enviado', provedor = 'cloud', provedor_id = 'wamid.falha-teste', enviado_em = ? WHERE id = ?")->execute([mcp_agora(), $idFalha]);
    $corpo = $evento([['id' => 'wamid.falha-teste', 'status' => 'failed', 'errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]]);
    [$st] = http($baseCloud . 'whatsapp.php', 'POST', $corpo, ['Content-Type: application/json', $assinar($corpo)]);
    $reserva = linha_aviso($db, "vespera|{$v1['id']}|$semanaQueVem|email");
    verificar('webhook: WhatsApp que falhou depois vira e-mail, com o código do erro', [$st, mcp_aviso_por_id($idFalha)['status'], mcp_aviso_por_id($idFalha)['erro'], $reserva['status'] ?? null, $reserva['destino'] ?? null],
        [200, 'falhou', '#131026 Message undeliverable', 'pendente', "vera-$sufixo@avisos-teste.example"]);
    $db->prepare("UPDATE mcp_avisos SET status = 'cancelado' WHERE id = ?")->execute([(int) ($reserva['id'] ?? 0)]);
    [$st, , $resp] = http($baseCloud . 'whatsapp.php?hub.mode=subscribe&hub.verify_token=verifica-teste&hub.challenge=12345');
    [$st2] = http($baseCloud . 'whatsapp.php?hub.mode=subscribe&hub.verify_token=errado&hub.challenge=12345');
    verificar('webhook: verificação do endereço no painel da Meta', [$st, $resp, $st2], [200, '12345', 403]);
    // PARAR: desliga o WhatsApp da pessoa e cancela o que estava na fila.
    sub($configCloud, 'echo json_encode(mcp_aviso_criar(["chave" => "teste-parar|' . $sufixo . '", "tipo" => "vespera", "canal" => "whatsapp", "colaborador_id" => ' . (int) $v1['id']
        . ', "pessoa" => "c' . (int) $v1['id'] . '", "nome" => "x", "destino" => "5521998760001", "referencia" => "' . $depoisAmanha . '", "agendado_para" => gmdate("Y-m-d H:i:s", time() + 86400)]));');
    $corpo = $evento([], [['from' => '5521998760001', 'type' => 'text', 'text' => ['body' => 'Parar!']]]);
    http($baseCloud . 'whatsapp.php', 'POST', $corpo, ['Content-Type: application/json', $assinar($corpo)]);
    verificar('webhook: quem responde PARAR deixa de receber pelo WhatsApp', [(int) mcp_colaborador_por_id((int) $v1['id'])['aviso_whatsapp'], linha_aviso($db, "teste-parar|$sufixo")['status']], [0, 'cancelado']);
    // Erro da API: tenta de novo e, na terceira, desiste. (Elias, da equipe: os dias são dele, e o aviso é de um dia útil.)
    $db->prepare("UPDATE mcp_colaboradores SET telefone = '21998760000', aviso_whatsapp = 1, aviso_dias = 'dom,seg,ter,qua,qui,sex,sab', aviso_dias_por = 'a própria pessoa' WHERE id = ?")
        ->execute([(int) $e1['id']]);
    $r = sub($configCloud, '$id = mcp_aviso_criar(["chave" => "teste-erro|' . $sufixo . '", "tipo" => "vespera", "canal" => "whatsapp", "colaborador_id" => ' . (int) $e1['id'] . ', "pessoa" => "c' . (int) $e1['id']
        . '", "nome" => "x", "destino" => "5521998760000", "referencia" => "' . $util . '", "agendado_para" => gmdate("Y-m-d H:i:s", ' . em($hoje, '09:00') . ')]);'
        . ' $s = []; foreach ([' . em($hoje, '09:01') . ', ' . em($hoje, '09:20') . ', ' . em($hoje, '09:50') . '] as $t) { mcp_avisos_enviar_pendentes($t, 50); $s[] = mcp_aviso_por_id($id)["status"]; }'
        . ' echo json_encode(["s" => $s, "erro" => mcp_aviso_por_id($id)["erro"], "t" => (int) mcp_aviso_por_id($id)["tentativas"]]);');
    verificar('API oficial: erro tenta de novo e desiste na terceira', [$r['s'] ?? null, $r['erro'] ?? null, $r['t'] ?? null], [['pendente', 'pendente', 'falhou'], 'número inválido (teste)', 3]);

    // ------------------------------------------------------------------------- WhatsApp pelo Make
    $db->prepare("UPDATE mcp_colaboradores SET telefone = '21998760003' WHERE id = ?")->execute([(int) $e1['id']]);
    $r = sub($configMake, '$id = mcp_aviso_criar(["chave" => "teste-make|' . $sufixo . '", "tipo" => "vespera", "canal" => "whatsapp", "colaborador_id" => ' . (int) $e1['id'] . ', "pessoa" => "c' . (int) $e1['id']
        . '", "nome" => "x", "destino" => "5521998760003", "referencia" => "' . $util . '", "agendado_para" => gmdate("Y-m-d H:i:s", ' . em($hoje, '09:00') . ')]);'
        . ' mcp_avisos_enviar_pendentes(' . em($hoje, '09:01') . ', 50); $a = mcp_aviso_por_id($id); echo json_encode(["aviso" => $id, "status" => $a["status"], "provedor" => $a["provedor"], "id" => $a["provedor_id"], "modo" => mcp_whatsapp_modo()]);');
    $make = array_values(array_filter(falso('make'), static fn(array $x): bool => ($x['aviso'] ?? 0) === ($r['aviso'] ?? -1)))[0] ?? [];
    verificar('Make: webhook assinado, com o texto e o modelo da equipe', [$r['modo'] ?? null, $r['status'] ?? null, $r['provedor'] ?? null, str_starts_with((string) ($r['id'] ?? ''), 'make-'), $make['telefone'] ?? null,
        str_contains((string) ($make['texto'] ?? ''), 'registre a presença ao chegar e ao sair'), $make['modelo']['nome'] ?? null], ['webhook', 'enviado', 'webhook', true, '5521998760003', true, 'cvb_ponto_vespera_equipe']);
    $r = sub($configMakeCurto, '$id = mcp_aviso_criar(["chave" => "teste-make-curto|' . $sufixo . '", "tipo" => "vespera", "canal" => "whatsapp", "colaborador_id" => ' . (int) $e1['id'] . ', "pessoa" => "c' . (int) $e1['id']
        . '", "nome" => "x", "destino" => "5521998760003", "referencia" => "' . $util . '", "agendado_para" => gmdate("Y-m-d H:i:s", ' . em($hoje, '09:00') . ')]);'
        . ' mcp_avisos_enviar_pendentes(' . em($hoje, '09:01') . ', 50); echo json_encode(["erro" => mcp_aviso_por_id($id)["erro"]]);');
    verificar('Make: segredo curto não manda', str_starts_with((string) ($r['erro'] ?? ''), 'WHATSAPP_WEBHOOK_SEGREDO ausente ou curto'), true);

    // ------------------------------------------------------------------------- WhatsApp pelo número do Palácio (Evolution)
    $r = sub($configEvolution, 'echo json_encode(["modo" => mcp_whatsapp_modo()]);');
    verificar('Evolution: configurada mas sem o risco aceito no portal, o modo continua manual', $r['modo'] ?? null, 'manual');
    mcp_ajuste_gravar('evolution_riscos', '1', $quem);
    $w1 = colaborador("Wagner WhatsApp $marca", 'voluntario', null, '21998760005');
    $db->prepare("UPDATE mcp_colaboradores SET aviso_whatsapp = 1, aviso_whatsapp_em = ?, aviso_whatsapp_por = 'teste', aviso_dias = 'dom,seg,ter,qua,qui,sex,sab' WHERE id = ?")
        ->execute([mcp_agora(), (int) $w1['id']]);
    $avisoEvo = static fn(string $chave, int $colId, string $numero, string $hora): string => '$id = mcp_aviso_criar(["chave" => "' . $chave . '|' . $sufixo . '", "tipo" => "vespera", "canal" => "whatsapp", "colaborador_id" => ' . $colId
        . ', "pessoa" => "c' . $colId . '", "nome" => "x", "destino" => "' . $numero . '", "referencia" => "' . $util . '", "agendado_para" => gmdate("Y-m-d H:i:s", ' . em($hoje, $hora) . ')]);';
    $r = sub($configEvolution, $avisoEvo('teste-evo', (int) $w1['id'], '5521998760005', '09:00') . ' mcp_avisos_enviar_pendentes(' . em($hoje, '09:01') . ', 50); $a = mcp_aviso_por_id($id);'
        . ' echo json_encode(["status" => $a["status"], "provedor" => $a["provedor"], "id" => $a["provedor_id"], "modo" => mcp_whatsapp_modo(), "nome" => mcp_whatsapp_modo_nome(mcp_whatsapp_modo())]);');
    $evo = array_values(array_filter(falso('evolution'), static fn(array $x): bool => ($x['number'] ?? '') === '5521998760005'))[0] ?? [];
    verificar('Evolution: manda pelo número do Palácio, com o texto pronto, o caminho para parar e sem prévia de link', [$r['modo'] ?? null, $r['nome'] ?? null, $r['status'] ?? null, $r['provedor'] ?? null, str_starts_with((string) ($r['id'] ?? ''), 'EVO-'),
        str_contains((string) ($evo['text'] ?? ''), 'registre a *chegada* e a *saída*'), str_contains((string) ($evo['text'] ?? ''), 'Mudar os dias ou parar: '),
        str_ends_with((string) ($evo['text'] ?? ''), "\n\n_Mensagem automática da secretaria. Para não receber mais, use o link acima._"), $evo['linkPreview'] ?? null],
        ['evolution', 'automático, pelo WhatsApp do Palácio Virtual (Evolution)', 'enviado', 'evolution', true, true, true, true, false]);
    verificar('Evolution: com o WhatsApp automático, o lembrete não vai também por e-mail', linha_aviso($db, "vespera|{$w1['id']}|$util|email"), null);
    $db->prepare("UPDATE mcp_colaboradores SET telefone = '21998760000' WHERE id = ?")->execute([(int) $w1['id']]);
    $r = sub($configEvolution, $avisoEvo('teste-evo-sem', (int) $w1['id'], '5521998760000', '09:00') . ' mcp_avisos_enviar_pendentes(' . em($hoje, '09:01') . ', 50); $a = mcp_aviso_por_id($id);'
        . ' echo json_encode(["status" => $a["status"], "erro" => $a["erro"], "t" => (int) $a["tentativas"]]);');
    verificar('Evolution: número sem WhatsApp falha na hora, sem tentar de novo', [$r['status'] ?? null, $r['erro'] ?? null, $r['t'] ?? null], ['falhou', 'esse número não tem WhatsApp', 1]);
    // Servidor da Evolution com erro: a mensagem volta para a fila e as outras da rodada nem tentam.
    $db->prepare("UPDATE mcp_colaboradores SET telefone = '21998760500' WHERE id = ?")->execute([(int) $e1['id']]);
    $db->prepare("UPDATE mcp_colaboradores SET telefone = '21998760005' WHERE id = ?")->execute([(int) $w1['id']]);
    $r = sub($configEvolution, $avisoEvo('teste-evo-cai', (int) $e1['id'], '5521998760500', '09:00') . ' $a1 = $id; ' . $avisoEvo('teste-evo-espera', (int) $w1['id'], '5521998760005', '09:00') . ' $a2 = $id;'
        . ' mcp_avisos_enviar_pendentes(' . em($hoje, '09:01') . ', 50); echo json_encode(["s1" => mcp_aviso_por_id($a1)["status"], "e1" => mcp_aviso_por_id($a1)["erro"],'
        . ' "s2" => mcp_aviso_por_id($a2)["status"], "t2" => (int) mcp_aviso_por_id($a2)["tentativas"], "fora" => mcp_whatsapp_fora() !== null]);');
    $r2 = sub($configEvolution, 'mcp_avisos_enviar_pendentes(' . em($hoje, '09:20') . ', 50); echo json_encode(["s2" => mcp_aviso_por_id(' . (int) (linha_aviso($db, "teste-evo-espera|$sufixo")['id'] ?? 0) . ')["status"]]);');
    verificar('Evolution: fora do ar, a fila espera a próxima rodada sem gastar tentativa', [$r['s1'] ?? null, $r['e1'] ?? null, $r['s2'] ?? null, $r['t2'] ?? null, $r['fora'] ?? null, $r2['s2'] ?? null],
        ['pendente', 'Connection Closed', 'pendente', 0, true, 'enviado']);
    $r = sub($configEvolutionChave, $avisoEvo('teste-evo-chave', (int) $w1['id'], '5521998760005', '09:00') . ' mcp_avisos_enviar_pendentes(' . em($hoje, '09:01') . ', 50); $a = mcp_aviso_por_id($id);'
        . ' echo json_encode(["status" => $a["status"], "erro" => $a["erro"]]);');
    verificar('Evolution: chave recusada volta para a fila, e a chave não aparece no erro', [$r['status'] ?? null, $r['erro'] ?? null, str_contains(json_encode($r), 'chave-errada')],
        ['pendente', 'a Evolution recusou a chave (confira WHATSAPP_EVOLUTION_CHAVE)', false]);
    $db->prepare("UPDATE mcp_colaboradores SET telefone = '21998760777' WHERE id = ?")->execute([(int) $w1['id']]);
    $r = sub($configEvolution, $avisoEvo('teste-evo-demora', (int) $w1['id'], '5521998760777', '09:00') . ' mcp_avisos_enviar_pendentes(' . em($hoje, '09:01') . ', 50); $a = mcp_aviso_por_id($id);'
        . ' echo json_encode(["status" => $a["status"], "erro" => $a["erro"], "t" => (int) $a["tentativas"]]);');
    verificar('Evolution: sem confirmação a tempo, conta como enviada e não vai de novo', [$r['status'] ?? null, str_contains((string) ($r['erro'] ?? ''), 'não confirmou a tempo'), $r['t'] ?? null], ['enviado', true, 1]);
    $r = sub($config, 'echo json_encode(["modo" => mcp_whatsapp_modo(), "site" => mcp_site_url(), "resend" => mcp_cfg("RESEND_API_KEY")]);', $arquivoWhatsapp);
    verificar('config-whatsapp.php: liga a Evolution, e só as chaves WHATSAPP_* valem', [$r['modo'] ?? null, $r['site'] ?? null, $r['resend'] ?? null], ['evolution', "http://127.0.0.1:$portaSite", 're_teste']);

    // ------------------------------------------------------------------------- lembrete da aula (escola falsa)
    mcp_ajuste_gravar('lembrete_aula', '1', $quem);
    file_put_contents("$falso/escola.json", json_encode(['falha' => true, 'alunos' => []]));
    verificar('aula: escola fora do ar não marca o dia (tenta de novo)', [mcp_avisos_preparar_aulas(em($hoje, '08:05')), mcp_ajuste('aula_preparada')], [0, '']);
    file_put_contents("$falso/escola.json", json_encode(['falha' => false, 'alunos' => [
        ['aluno_id' => "al-$sufixo", 'nome' => "Rita Aluna $marca", 'email' => "rita-$sufixo@avisos-teste.example", 'celular' => '21998769999',
            'aulas' => [['aula_id' => 'a1', 'horario' => '18:00 - 22:00', 'turma_id' => 't1', 'curso_id' => 'c1', 'curso_nome' => 'Bombeiro Civil']]],
    ]]));
    $chamadas = count(falso('escola'));
    verificar('aula: prepara o lembrete por e-mail (WhatsApp dos alunos desligado)', [mcp_avisos_preparar_aulas(em($hoje, '08:10')), mcp_ajuste('aula_preparada'), linha_aviso($db, "aula|al-$sufixo|$amanha|whatsapp")],
        [1, $amanha, null]);
    verificar('aula: pergunta à escola uma vez por dia', [mcp_avisos_preparar_aulas(em($hoje, '09:10')), count(falso('escola')) - $chamadas, falso('escola')[$chamadas]['dados']['data'] ?? null], [0, 1, $amanha]);
    $antesEmails = count(falso('emails'));
    mcp_avisos_enviar_pendentes(em($hoje, '18:00'));
    $email = falso('emails')[$antesEmails] ?? [];
    verificar('e-mail da aula: assunto, curso, horário e link para não receber', [$email['subject'] ?? null, str_contains($email['html'] ?? '', 'das 18:00 às 22:00'), (bool) preg_match('~avisos\.php\?r=(\d+\.x\.[a-f0-9]{16})~', $email['html'] ?? '', $m)],
        ['Amanhã tem aula: Bombeiro Civil', true, true]);
    $cliques = static fn(): int => (int) (linha_aviso($db, "aula|al-$sufixo|$amanha|email")['cliques'] ?? -1);
    $antesCliques = $cliques();
    [$stRobo, $cabRobo] = http($base . 'avisos.php?r=' . ($m[1] ?? ''), 'GET', null, ['User-Agent: WhatsApp/2.23.20.0 A']);
    verificar('clique: prévia do WhatsApp vai ao ponto, sem link pessoal e sem contar', [$stRobo, $cabRobo['location'] ?? null, $cliques() - $antesCliques],
        [302, "http://127.0.0.1:$portaSite/ponto/", 0]);
    [$st, $cab] = http($base . 'avisos.php?r=' . ($m[1] ?? ''));
    verificar('clique: pelo navegador conta', $cliques() - $antesCliques, 1);
    $tokSair = token_do_link($cab['location'] ?? '');
    [$st, $r] = api_avisos($base, ['acao' => 'sair_ler', 't' => $tokSair]);
    [$st2, $r2] = api_avisos($base, ['acao' => 'sair_confirmar', 't' => $tokSair]);
    verificar('não quero mais receber: bloqueia o e-mail do aluno', [$st, $r['bloqueado'] ?? null, $st2, $r2['bloqueado'] ?? null, mcp_avisos_bloqueado('email', "rita-$sufixo@avisos-teste.example")], [200, false, 200, true, true]);
    mcp_ajuste_gravar('aula_preparada', '', $quem);
    verificar('aula: quem pediu para não receber fica de fora', mcp_avisos_preparar_aulas(em($amanha, '08:10')), 0);
    // WhatsApp do aluno: só com o WhatsApp da aula ligado no portal e a autorização registrada na escola (true, e só true).
    mcp_ajuste_gravar('aula_whatsapp', '1', $quem);
    mcp_ajuste_gravar('aula_preparada', '', $quem);
    $aulaNoite = [['aula_id' => 'a2', 'horario' => '18:00 - 22:00', 'turma_id' => 't1', 'curso_id' => 'c1', 'curso_nome' => 'Bombeiro Civil']];
    file_put_contents("$falso/escola.json", json_encode(['falha' => false, 'alunos' => [
        ['aluno_id' => "al2-$sufixo", 'nome' => "Sara Aluna $marca", 'email' => "sara-$sufixo@avisos-teste.example", 'celular' => '21998768888', 'whatsapp_autorizado' => true, 'aulas' => $aulaNoite],
        ['aluno_id' => "al3-$sufixo", 'nome' => "Tiago Aluno $marca", 'email' => "tiago-$sufixo@avisos-teste.example", 'celular' => '21998767777', 'whatsapp_autorizado' => 'sim', 'aulas' => $aulaNoite],
    ]]));
    $depoisDeAmanha = mcp_avisos_dia_mais($hoje, 2);
    verificar('aula: WhatsApp só para o aluno com a autorização registrada na escola', [mcp_avisos_preparar_aulas(em($amanha, '08:12')),
        linha_aviso($db, "aula|al2-$sufixo|$depoisDeAmanha|whatsapp")['status'] ?? null, linha_aviso($db, "aula|al3-$sufixo|$depoisDeAmanha|whatsapp"),
        linha_aviso($db, "aula|al3-$sufixo|$depoisDeAmanha|email")['status'] ?? null], [3, 'manual', null, 'pendente']);
    mcp_ajuste_gravar('aula_whatsapp', '0', $quem);
    // Descadastro de um clique: o POST do programa de e-mail descadastra; abrir o link mostra uma página com o
    // botão, sem link pessoal (quem tem o cabeçalho não ganha acesso às escolhas da pessoa).
    $avSara = linha_aviso($db, "aula|al2-$sufixo|$depoisDeAmanha|email");
    $urlSair = trim(mcp_avisos_cabecalhos_descadastro($avSara)['List-Unsubscribe'], '<>');
    [$stGet, $cabGet, $htmlGet] = http($urlSair);
    [$stRoboU, , $htmlRoboU] = http($urlSair, 'GET', null, ['User-Agent: Mozilla/5.0 (compatible; Googlebot/2.1)']);
    verificar('descadastro de um clique: abrir o link mostra a página com o botão, sem link pessoal, e não descadastra (nem para robô)', [$stGet, isset($cabGet['location']),
        str_contains($htmlGet, 'name="confirmar"'), str_contains($htmlGet . $htmlRoboU, '#t='), $stRoboU, mcp_avisos_bloqueado('email', "sara-$sufixo@avisos-teste.example")],
        [200, false, true, false, 200, false]);
    [$stFalso] = http((string) preg_replace('/\.[a-f0-9]{16}$/', '.0000000000000000', $urlSair), 'POST', 'List-Unsubscribe=One-Click', ['Content-Type: application/x-www-form-urlencoded']);
    [$stPost] = http($urlSair, 'POST', 'List-Unsubscribe=One-Click', ['Content-Type: application/x-www-form-urlencoded']);
    verificar('descadastro de um clique: o POST bloqueia o e-mail do aluno e cancela o que estava na fila; assinatura errada não vale',
        [$stFalso, $stPost, mcp_avisos_bloqueado('email', "sara-$sufixo@avisos-teste.example"), mcp_aviso_por_id((int) $avSara['id'])['status']], [404, 200, true, 'cancelado']);
    $avVania = linha_aviso($db, "vespera|{$v3['id']}|$amanha|email");
    $formulario = ['Content-Type: application/x-www-form-urlencoded'];
    // "Invalidar os links já enviados" (a chave da pessoa muda) derruba também o descadastro das mensagens que já saíram.
    $urlVania = trim(mcp_avisos_cabecalhos_descadastro($avVania)['List-Unsubscribe'], '<>');
    mcp_avisos_chave_colaborador(mcp_colaborador_por_id((int) $v3['id']), true);
    [$stVelho, , $htmlVelho] = http($urlVania);
    [$stVelhoPost] = http($urlVania, 'POST', 'List-Unsubscribe=One-Click', $formulario);
    verificar('descadastro de um clique: depois de invalidar os links, o da mensagem antiga não vale', [$stVelho, str_contains($htmlVelho, 'Este link não vale mais'), $stVelhoPost,
        (int) mcp_colaborador_por_id((int) $v3['id'])['aviso_email']], [404, true, 404, 1]);
    // Mensagem que foi para um e-mail que não é mais o do cadastro (estava errado): quem recebeu não desliga o certo.
    $idErrado = (int) mcp_aviso_criar(['chave' => "teste-descadastro|$sufixo", 'tipo' => 'vespera', 'canal' => 'email', 'colaborador_id' => (int) $v3['id'], 'pessoa' => 'c' . $v3['id'],
        'nome' => "Vânia $marca", 'destino' => "outra-pessoa-$sufixo@avisos-teste.example", 'referencia' => $amanha]);
    [$stErrado] = http(trim(mcp_avisos_cabecalhos_descadastro(mcp_aviso_por_id($idErrado))['List-Unsubscribe'], '<>'), 'POST', 'List-Unsubscribe=One-Click', $formulario);
    verificar('descadastro de um clique: e-mail que não é mais o do cadastro não desliga o da pessoa', [$stErrado, (int) mcp_colaborador_por_id((int) $v3['id'])['aviso_email']], [404, 1]);
    [$st] = http(trim(mcp_avisos_cabecalhos_descadastro($avVania)['List-Unsubscribe'], '<>'), 'POST', 'List-Unsubscribe=One-Click', $formulario);
    verificar('descadastro de um clique: o colaborador deixa de receber por e-mail', [$st, (int) mcp_colaborador_por_id((int) $v3['id'])['aviso_email']], [200, 0]);
    $db->prepare('UPDATE mcp_colaboradores SET aviso_email = 1 WHERE id = ?')->execute([(int) $v3['id']]);
    [$st, , $html] = http(trim(mcp_avisos_cabecalhos_descadastro($avVania)['List-Unsubscribe'], '<>'), 'POST', 'confirmar=1', $formulario);
    verificar('descadastro pela página: o botão desliga o e-mail e confirma', [$st, str_contains($html, '<h1>Pronto</h1>'), (int) mcp_colaborador_por_id((int) $v3['id'])['aviso_email']], [200, true, 0]);

    // ------------------------------------------------------------------------- portal
    $db->prepare("UPDATE mcp_colaboradores SET telefone = '21998760005', aviso_whatsapp = 1 WHERE id = ?")->execute([(int) $w1['id']]);
    $idFila = (int) mcp_aviso_criar(['chave' => "teste-fila|$sufixo", 'tipo' => 'vespera', 'canal' => 'whatsapp', 'colaborador_id' => (int) $w1['id'], 'pessoa' => 'c' . $w1['id'], 'nome' => "Wagner $marca",
        'destino' => '5521998760005', 'referencia' => $amanha]);
    foreach (['v=comunicacao' => 'Lembretes automáticos', 'v=comunicacao&aba=comunicados' => 'Antes: anúncio do ponto', 'v=comunicacao&aba=fila' => 'Abrir no WhatsApp',
        'v=comunicacao&aba=envios' => 'Lembrete da véspera', 'v=comunicacao&aba=resultados' => 'Adesão', 'v=comunicado&id=' . $antes['id'] => 'Quem recebe',
        'v=comunicado&novo=1' => 'Criar o rascunho', 'v=importar' => 'Importar colaboradores', 'v=colaborador&id=' . $v1['id'] => 'Lembretes e contato', 'v=ponto' => 'Importar planilha'] as $q => $texto) {
        [$st, , $html] = http($base . "painel.php?$q", 'GET', null, ["Cookie: $sessaoPortal"]);
        verificar("portal: $q", [$st, str_contains($html, $texto)], [200, true]);
    }
    [$st, , $html] = http($base . 'painel.php?v=comunicacao&aba=resultados&csv=1', 'GET', null, ["Cookie: $sessaoPortal"]);
    verificar('portal: planilha dos resultados por voluntário, sem CPF e sem a equipe contratada', [$st, str_starts_with($html, "\xEF\xBB\xBFVoluntário;"), str_contains($html, "Vera Voluntária $marca"), str_contains($html, $v1['cpf']),
        str_contains($html, "Elias Empregado $marca")], [200, true, true, false, false]);
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'fila_pular', 'id' => $idFila, 't' => $csrf('fila_pular', $idFila)]);
    verificar('fila: pular uma mensagem', [$st, mcp_aviso_por_id($idFila)['status'], mcp_aviso_por_id($idFila)['erro']], [303, 'cancelado', 'a secretaria pulou']);
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'aviso_parar', 'id' => $idFila, 't' => $csrf('aviso_parar', $idFila)]);
    verificar('envios: "pediu para parar" desliga o WhatsApp da pessoa e bloqueia o número', [str_ends_with($cab['location'] ?? '', 'ok=av_parou'), (int) mcp_colaborador_por_id((int) $w1['id'])['aviso_whatsapp'],
        mcp_avisos_bloqueado('whatsapp', '5521998760005')], [true, 0, true]);
    [$st, , $html] = portal($base, $sessaoPortal, ['acao' => 'ajustes_salvar', 'id' => 0, 't' => 'errado', 'lembrete_vespera' => '1']);
    verificar('portal: sem o token do formulário, volta para a entrada', str_contains($html, '<h1>Entrar</h1>'), true);
    $cookieReal = $_COOKIE[MCP_PAINEL_COOKIE];
    $_COOKIE[MCP_PAINEL_COOKIE] = 'sessao-que-ja-acabou';
    $tokenVelho = mcp_painel_csrf($quem, 'ajustes_salvar', 0);
    $_COOKIE[MCP_PAINEL_COOKIE] = $cookieReal;
    [$st, , $html] = portal($base, $sessaoPortal, ['acao' => 'ajustes_salvar', 'id' => 0, 't' => $tokenVelho, 'lembrete_vespera' => '1']);
    verificar('portal: token de formulário de outra sessão não vale', [str_contains($html, '<h1>Entrar</h1>'), $tokenVelho !== $csrf('ajustes_salvar', 0)], [true, true]);
    // Sessão de um e-mail que saiu de PAINEL_EMAILS cai na hora (não espera as 12 horas).
    $valorFora = rtrim(strtr(base64_encode((string) json_encode(['u' => 'ex-secretaria@exemplo.org', 'e' => time() + 3600])), '+/', '-_'), '=');
    $sessaoFora = 'mcp_painel=' . $valorFora . '.' . mcp_painel_assinar("k|$valorFora");
    [, , $htmlFora] = http($base . 'painel.php?v=comunicacao', 'GET', null, ["Cookie: $sessaoFora"]);
    // Pedido de link vindo de outro site (formulário escondido numa página qualquer) não gasta o limite do IP.
    $pedidosAntes = mcp_contar_eventos_recentes('painel_link', '127.0.0.1', 3600);
    [, , $htmlOutroSite] = http($base . 'painel.php', 'POST', http_build_query(['acao' => 'entrar', 'email' => $quem]),
        ['Content-Type: application/x-www-form-urlencoded', 'Sec-Fetch-Site: cross-site', 'Origin: https://site-qualquer.example']);
    verificar('portal: e-mail fora da lista perde a sessão; pedido de link vindo de outro site é recusado sem gastar o limite',
        [str_contains($htmlFora, '<h1>Entrar</h1>'), str_contains($htmlOutroSite, 'Abra o portal pelo endereço dele'), mcp_contar_eventos_recentes('painel_link', '127.0.0.1', 3600) - $pedidosAntes],
        [true, true, 0]);
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'ajustes_salvar', 'id' => 0, 't' => $csrf('ajustes_salvar', 0), 'lembrete_saida' => '1', 'whatsapp_ativo' => '1']);
    mcp_ajustes_todos(true);
    verificar('portal: ajustes (liga e desliga)', [$st, str_ends_with($cab['location'] ?? '', 'ok=aj_ok'), mcp_ajuste('lembrete_vespera'), mcp_ajuste('lembrete_saida'), mcp_ajuste('whatsapp_ativo')], [303, true, '0', '1', '1']);
    $cpfNovo = cpf_de_teste();
    $planilha = "Nome\tCPF\tVínculo\tFunção\tE-mail\tTelefone\tDias\tWhatsApp\nNina Nova $marca\t" . mcp_cpf_formatado($cpfNovo) . "\tVoluntária\tApoio\tnina-$sufixo@avisos-teste.example\t(21) 99876-1111\tsegunda e quarta\tsim\n"
        . "Otto Errado $marca\t123\tvoluntário\t\t\t\t\t\nPaula Chefe $marca\t" . mcp_cpf_formatado(cpf_de_teste()) . "\tchefe\t\t\t\t\t";
    [$st, , $html] = portal($base, $sessaoPortal, ['acao' => 'col_importar', 'id' => 0, 't' => $csrf('col_importar', 0), 'texto' => $planilha, 'etapa' => 'conferir']);
    verificar('importar: confere linha a linha', [$st, substr_count($html, 'Com erro'), str_contains($html, 'Vínculo não reconhecido'), str_contains($html, 'Importar 1 linha')], [200, 2, true, true]);
    [$st, , $html] = portal($base, $sessaoPortal, ['acao' => 'col_importar', 'id' => 0, 't' => $csrf('col_importar', 0), 'texto' => $planilha, 'etapa' => 'importar']);
    verificar('importar: WhatsApp "sim" pede a confirmação do consentimento', [str_contains($html, 'Confirme que as pessoas marcadas com WhatsApp'), mcp_colaborador_por_cpf($cpfNovo)], [true, null]);
    [$st, , $html] = portal($base, $sessaoPortal, ['acao' => 'col_importar', 'id' => 0, 't' => $csrf('col_importar', 0), 'texto' => $planilha, 'etapa' => 'importar', 'consentimento' => '1']);
    $nina = mcp_colaborador_por_cpf($cpfNovo);
    verificar('importar: cria com vínculo, dias e WhatsApp autorizado', [str_contains($html, 'Pronto: 1 cadastrados'), $nina['vinculo'] ?? null, $nina['aviso_dias'] ?? null, (int) ($nina['aviso_whatsapp'] ?? 0),
        $nina['aviso_whatsapp_por'] ?? null], [true, 'voluntario', 'seg,qua', 1, "$quem (importação)"]);
    $nid = (int) $nina['id'];
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'col_avisos', 'id' => $nid, 't' => $csrf('col_avisos', $nid), 'dias' => ['sab'], 'email' => '1', 'comunicados' => '1']);
    $nina = mcp_colaborador_por_id($nid);
    verificar('ficha: lembretes e contato (tirar o WhatsApp apaga o consentimento)', [$st, $nina['aviso_dias'], (int) $nina['aviso_whatsapp'], $nina['aviso_whatsapp_em'], (int) $nina['aviso_saida']], [303, 'sab', 0, null, 0]);
    [$st, , $html] = portal($base, $sessaoPortal, ['acao' => 'col_avisos', 'id' => $nid, 't' => $csrf('col_avisos', $nid), 'dias' => ['sab'], 'email' => '1', 'whatsapp' => '1', 'comunicados' => '1']);
    verificar('ficha: marcar o WhatsApp pede como a pessoa autorizou (a prova do consentimento)', [$st, str_contains($html, 'Diga como a pessoa autorizou o WhatsApp'), (int) mcp_colaborador_por_id($nid)['aviso_whatsapp']], [200, true, 0]);
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'col_avisos', 'id' => $nid, 't' => $csrf('col_avisos', $nid), 'dias' => ['sab'], 'email' => '1', 'whatsapp' => '1', 'comunicados' => '1',
        'como' => 'disse na recepção, em 30/09']);
    $nina = mcp_colaborador_por_id($nid);
    verificar('ficha: WhatsApp autorizado, com quem registrou e como', [(int) $nina['aviso_whatsapp'], $nina['aviso_whatsapp_por'], $nina['aviso_whatsapp_como']], [1, $quem, 'disse na recepção, em 30/09']);
    $chaveNina = (string) mcp_avisos_chave_colaborador($nina);
    // Um link de clique de uma mensagem que já saiu: antes de invalidar, leva à página de lembretes; depois, ao ponto.
    $idNina = (int) mcp_aviso_criar(['chave' => "teste-links|$sufixo", 'tipo' => 'link', 'canal' => 'email', 'colaborador_id' => $nid, 'pessoa' => "c$nid", 'nome' => (string) $nina['nome'],
        'destino' => (string) $nina['email']]);
    $linkNina = mcp_avisos_link_clique($idNina, 'lembretes');
    [, $cabAntes] = http($linkNina);
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'col_links_novos', 'id' => $nid, 't' => $csrf('col_links_novos', $nid)]);
    [, $cabDepois] = http($linkNina);
    verificar('ficha: invalidar os links já enviados troca a chave e derruba também os links das mensagens que já saíram', [str_contains($cab['location'] ?? '', 'ok=cl_novos'),
        mcp_colaborador_por_id($nid)['aviso_chave'] !== $chaveNina, str_contains($cabAntes['location'] ?? '', '/ponto/lembretes/#t='), $cabDepois['location'] ?? null],
        [true, true, true, "http://127.0.0.1:$portaSite/ponto/"]);
    mcp_colaborador_salvar($nid, ['nome' => $nina['nome'], 'cpf' => $nina['cpf'], 'email' => $nina['email'], 'telefone' => '21998762222', 'funcao' => $nina['funcao'], 'vinculo' => $nina['vinculo'], 'ativo' => 1], $quem);
    $nina = mcp_colaborador_por_id($nid);
    verificar('ficha: trocar o celular derruba a autorização do WhatsApp e os links (valiam para o número antigo)', [(int) $nina['aviso_whatsapp'], $nina['aviso_whatsapp_por'], $nina['aviso_chave']], [0, null, null]);
    $antesEmails = count(falso('emails'));
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'campanha_teste', 'id' => (int) $depois['id'], 't' => $csrf('campanha_teste', (int) $depois['id'])]);
    $email = falso('emails')[$antesEmails] ?? [];
    verificar('comunicado: teste vai para quem está no portal, com dados de exemplo', [$st, str_ends_with($cab['location'] ?? '', 'ok=cp_teste'), $email['to'] ?? null, str_starts_with($email['subject'] ?? '', '[Teste] '),
        str_contains($email['html'] ?? '', 'Maria'), str_contains($email['html'] ?? '', '23h40')], [303, true, [$quem], true, true, true]);
    [$st, , $html] = portal($base, $sessaoPortal, ['acao' => 'campanha_salvar', 'id' => 0, 't' => $csrf('campanha_salvar', 0), 'nome' => "Avulso $marca", 'fase' => 'livre', 'publico' => 'colaboradores',
        'canais' => ['email'], 'assunto' => 'Oi, pessoal', 'titulo' => 'Olá a todos', 'mensagem' => 'Texto com {campo_errado} aqui.', 'botao' => 'ponto']);
    verificar('comunicado: erro volta no formulário com o que foi digitado', [$st, str_contains($html, 'O campo {campo_errado} não existe'), str_contains($html, "Avulso $marca")], [200, true, true]);
    $emJanela = mcp_avisos_na_janela(time());
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'col_link', 'id' => $nid, 't' => $csrf('col_link', $nid)]);
    verificar('ficha: mandar o link das preferências (' . ($emJanela ? 'na janela: sai na hora' : 'fora da janela: fica para as 8h') . ')',
        str_ends_with($cab['location'] ?? '', $emJanela ? 'ok=cl_ok#lembretes' : 'ok=cl_fila#lembretes'), true);
    // Falha: tentar de novo pelo portal.
    $idErro = (int) linha_aviso($db, "teste-erro|$sufixo")['id'];
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'aviso_repetir', 'id' => $idErro, 't' => $csrf('aviso_repetir', $idErro)]);
    verificar('envios: tentar de novo uma falha (com o prazo da véspera)', [str_ends_with($cab['location'] ?? '', 'ok=av_rep'), mcp_aviso_por_id($idErro)['status'], (int) mcp_aviso_por_id($idErro)['tentativas'],
        mcp_data_brt((string) mcp_aviso_por_id($idErro)['expira_em'], 'Y-m-d H:i')], [true, 'pendente', 0, mcp_avisos_dia_mais($util, -1) . ' 20:00']);
    $db->prepare("UPDATE mcp_avisos SET status = 'falhou', referencia = ? WHERE id = ?")->execute([$hoje, $idErro]);
    [$st, $cab] = portal($base, $sessaoPortal, ['acao' => 'aviso_repetir', 'id' => $idErro, 't' => $csrf('aviso_repetir', $idErro)]);
    verificar('envios: lembrete cuja véspera já passou não sai de novo', [str_ends_with($cab['location'] ?? '', 'ok=av_tarde'), mcp_aviso_por_id($idErro)['status']], [true, 'falhou']);

    // ------------------------------------------------------------------------- métricas
    $m = mcp_metricas(mcp_avisos_dia_mais($hoje, -3), mcp_avisos_dia_mais($hoje, 1));
    verificar('métricas: entradas, horas e saídas informadas do período', [$m['atual']['entradas'], $m['atual']['horas_minutos'], $m['atual']['informadas']], [2, 510, 1]);
    verificar('métricas: adesão pelos ativos', [$m['atual']['com_registro'], $m['atual']['ativos'] >= 6], [2, true]);
    $saida = $m['avisos']['saida'];
    verificar('métricas: aviso de saída que deu certo', [$saida['enviados'] >= 1, $saida['convertidos'], $saida['avaliados']], [true, 1, 1]);
    $pessoas = array_column($m['pessoas'], null, 'id');
    verificar('métricas por pessoa: dias e horas dos voluntários; a equipe contratada fica fora', [$pessoas[(int) $v1['id']]['dias'] ?? null, $pessoas[(int) $v1['id']]['minutos'] ?? null,
        isset($pessoas[(int) $e1['id']]), array_key_exists('opiniao', $pessoas[(int) $v2['id']] ?? [])], [1, 510, false, false]);

    // ------------------------------------------------------------------------- cota diária dos e-mails dos avisos
    // A conta da Resend é dividida com a matrícula: acabou a cota dos avisos, o resto espera o dia seguinte.
    $jaHoje = mcp_avisos_emails_hoje(em($hoje, '11:00'));
    $configCota = config_teste(['AVISOS_EMAILS_POR_DIA' => (string) ($jaHoje + 1)] + $base0);
    $avisoCota = static fn(string $n): string => '$' . $n . ' = mcp_aviso_criar(["chave" => "teste-cota-' . $n . '|' . $sufixo . '", "tipo" => "link", "canal" => "email", "colaborador_id" => '
        . (int) $v1['id'] . ', "pessoa" => "c' . (int) $v1['id'] . '", "nome" => "Vera", "destino" => "vera-' . $sufixo . '@avisos-teste.example", "agendado_para" => gmdate("Y-m-d H:i:s", ' . em($hoje, '10:59') . ')]);';
    $r = sub($configCota, $avisoCota('a') . $avisoCota('b') . ' [$e] = mcp_avisos_enviar_pendentes(' . em($hoje, '11:00') . ', 50);'
        . ' echo json_encode(["e" => $e, "s" => [mcp_aviso_por_id($a)["status"], mcp_aviso_por_id($b)["status"]], "cota" => mcp_avisos_emails_cota()]);');
    @unlink($configCota);
    verificar('cota diária dos e-mails: acabou a cota, o resto espera o dia seguinte', [$r['e'] ?? null, in_array('pendente', $r['s'] ?? [], true), $r['cota'] ?? null],
        [1, true, $jaHoje + 1]);

    // ------------------------------------------------------------------------- revisão final: cota, reserva, troca de canal, fila, ajustes
    // Cota: o comunicado deixa a parte dos lembretes do dia (os já preparados) e a folga. Os outros e-mails na fila saem da conta.
    $db->exec("UPDATE mcp_avisos SET status = 'cancelado', erro = 'teste da cota' WHERE status = 'pendente' AND canal = 'email'");
    $criarCota = static function (string $n, string $tipo, string $hora) use ($v1, $sufixo, $hoje, $depois): int {
        return (int) mcp_aviso_criar(['chave' => "teste-cota2-$n|$sufixo", 'tipo' => $tipo, 'canal' => 'email', 'campanha_id' => $tipo === 'campanha' ? (int) $depois['id'] : null,
            'colaborador_id' => (int) $v1['id'], 'pessoa' => 'c' . $v1['id'], 'nome' => 'Vera', 'destino' => "vera-$sufixo@avisos-teste.example",
            'agendado_para' => mcp_ponto_local_para_utc("$hoje $hora:00"), 'expira_em' => mcp_ponto_local_para_utc("$hoje 20:00:00")]);
    };
    $idsLembrete = array_map(static fn(int $i): int => $criarCota("l$i", 'link', '18:00'), [1, 2, 3]);
    $idsComunicado = array_map(static fn(int $i): int => $criarCota("c$i", 'campanha', '10:00'), [1, 2, 3, 4, 5]);
    $jaHoje = mcp_avisos_emails_hoje(em($hoje, '11:00'));
    $lembretesHoje = mcp_avisos_emails_lembretes_hoje(em($hoje, '11:00'));
    // A cota em que sobram exatamente 2 e-mails para o comunicado às 11h.
    for ($cota = 1; $cota - (int) ceil($cota * MCP_AVISOS_EMAILS_FOLGA) - $jaHoje - $lembretesHoje !== 2; $cota++) {
    }
    $configCota = config_teste(['AVISOS_EMAILS_POR_DIA' => (string) $cota] + $base0);
    $situacoes = static fn(array $ids): string => 'array_map(static fn(int $i): string => mcp_aviso_por_id($i)["status"], ' . var_export($ids, true) . ')';
    $r = sub($configCota, 'mcp_avisos_enviar_pendentes(' . em($hoje, '11:00') . '); $c11 = ' . $situacoes($idsComunicado) . '; $l11 = ' . $situacoes($idsLembrete) . ';'
        . ' mcp_avisos_enviar_pendentes(' . em($hoje, '18:00') . '); echo json_encode(["c11" => $c11, "l11" => $l11, "c18" => ' . $situacoes($idsComunicado)
        . ', "l18" => ' . $situacoes($idsLembrete) . ', "resta" => mcp_avisos_cota_comunicados(' . em($hoje, '18:01') . ')]);');
    @unlink($configCota);
    $quantos = static fn(array $l, string $st): int => count(array_filter($l, static fn($x): bool => $x === $st));
    verificar('cota: às 11h o comunicado usa só a parte dele (2 de 5); às 18h os 3 lembretes saem, e o resto do comunicado espera o dia seguinte',
        [$quantos($r['c11'] ?? [], 'enviado'), $quantos($r['l11'] ?? [], 'pendente'), $quantos($r['l18'] ?? [], 'enviado'), $quantos($r['c18'] ?? [], 'pendente'), $r['resta'] ?? null], [2, 3, 3, 3, 0]);

    // Reserva: o e-mail do mesmo lembrete, cancelado porque ia pelo WhatsApp, volta para a fila quando o WhatsApp falha de vez.
    $refReserva = mcp_avisos_dia_mais($hoje, 23);
    $baseReserva = ['tipo' => 'vespera', 'colaborador_id' => (int) $v1['id'], 'pessoa' => 'c' . $v1['id'], 'nome' => 'Vera', 'referencia' => $refReserva,
        'agendado_para' => gmdate('Y-m-d H:i:s', time() - 60), 'expira_em' => gmdate('Y-m-d H:i:s', time() + 3600)];
    $idEmailGemeo = (int) mcp_aviso_criar(['chave' => "vespera|{$v1['id']}|$refReserva|email", 'canal' => 'email', 'destino' => "vera-$sufixo@avisos-teste.example"] + $baseReserva);
    mcp_aviso_atualizar($idEmailGemeo, ['status' => 'cancelado', 'erro' => 'vai pelo WhatsApp']);
    $idWhatsGemeo = (int) mcp_aviso_criar(['chave' => "vespera|{$v1['id']}|$refReserva|whatsapp", 'canal' => 'whatsapp', 'destino' => '5521998760001'] + $baseReserva);
    mcp_aviso_atualizar($idWhatsGemeo, ['status' => 'falhou', 'erro' => '#131026 teste']);
    verificar('reserva: o e-mail cancelado porque ia pelo WhatsApp volta para a fila quando o WhatsApp falha de vez',
        [mcp_aviso_reserva_email(mcp_aviso_por_id($idWhatsGemeo), time()), mcp_aviso_por_id($idEmailGemeo)['status'], mcp_aviso_por_id($idEmailGemeo)['erro']], [$idEmailGemeo, 'pendente', null]);

    // Perto do prazo, o lembrete que ainda espera o WhatsApp (desligado, fora do ar) vai por e-mail, e sai na mesma rodada.
    $ajustesAntesTroca = ['whatsapp_ativo' => mcp_ajuste('whatsapp_ativo', '0'), 'lembrete_aula' => mcp_ajuste('lembrete_aula', '0'), 'aula_whatsapp' => mcp_ajuste('aula_whatsapp', '0')];
    mcp_ajuste_gravar('whatsapp_ativo', '0', $quem);
    mcp_ajuste_gravar('lembrete_aula', '1', $quem);
    $dadosTroca = ['data' => $amanha, 'aulas' => [['curso' => 'Bombeiro Civil', 'horario' => '18:00 - 22:00']], 'email' => "ulisses-$sufixo@avisos-teste.example", 'whatsapp_hash' => ''];
    $chaveTroca = "aula|al-troca-$sufixo|$amanha";
    $idTroca = (int) mcp_aviso_criar(['chave' => "$chaveTroca|whatsapp", 'tipo' => 'aula', 'canal' => 'whatsapp', 'pessoa' => 'a' . mcp_avisos_hash('email', $dadosTroca['email']),
        'nome' => "Ulisses Aluno $marca", 'destino' => '5521998765432', 'referencia' => $amanha, 'dados' => $dadosTroca,
        'agendado_para' => mcp_ponto_local_para_utc("$hoje 18:00:00"), 'expira_em' => mcp_ponto_local_para_utc("$hoje 20:00:00")]);
    $configTroca = config_teste(['AVISOS_EMAILS_POR_DIA' => '0'] + $base0 + ['WHATSAPP_CLOUD_TOKEN' => 'tok_teste', 'WHATSAPP_CLOUD_NUMERO_ID' => '1234567890',
        'WHATSAPP_CLOUD_BASE' => "http://127.0.0.1:$portaFalso"]);
    $antesEmails = count(falso('emails'));
    $r = sub($configTroca, 'mcp_avisos_enviar_pendentes(' . em($hoje, '18:30') . '); $s1 = mcp_aviso_por_id(' . $idTroca . ')["status"];'
        . ' mcp_avisos_enviar_pendentes(' . em($hoje, '19:05') . '); $w = mcp_aviso_por_id(' . $idTroca . '); $e = mcp_aviso_por_chave(' . var_export("$chaveTroca|email", true) . ');'
        . ' echo json_encode(["s1" => $s1, "s2" => $w["status"], "erro" => $w["erro"], "e" => $e["status"] ?? null]);');
    @unlink($configTroca);
    foreach ($ajustesAntesTroca as $nome => $valor) {
        mcp_ajuste_gravar($nome, $valor, $quem);
    }
    verificar('troca de canal: às 18h30 o WhatsApp desligado espera; às 19h o lembrete vai por e-mail na mesma rodada, e o WhatsApp não sai mais',
        [$r['s1'] ?? null, $r['s2'] ?? null, $r['erro'] ?? null, $r['e'] ?? null,
        count(array_filter(array_slice(falso('emails'), $antesEmails), static fn(array $m): bool => ($m['to'] ?? []) === ["ulisses-$sufixo@avisos-teste.example"]))],
        ['pendente', 'cancelado', 'o WhatsApp não saiu a tempo: foi por e-mail', 'enviado', 1]);

    // Aula: recesso marcado depois do preparo barra o lembrete; quem disse "não quero mais receber" na página não recebe o WhatsApp.
    $ajustesAntesAula = ['whatsapp_ativo' => mcp_ajuste('whatsapp_ativo', '0'), 'lembrete_aula' => mcp_ajuste('lembrete_aula', '0'), 'aula_whatsapp' => mcp_ajuste('aula_whatsapp', '0'),
        'dias_fechados' => mcp_ajuste('dias_fechados')];
    foreach (['whatsapp_ativo', 'lembrete_aula', 'aula_whatsapp'] as $nome) {
        mcp_ajuste_gravar($nome, '1', $quem);
    }
    $dataFechada = mcp_avisos_dia_mais($hoje, 12);
    mcp_ajuste_gravar('dias_fechados', $dataFechada, $quem);
    $emailSaiu = "yara-$sufixo@avisos-teste.example";
    $avisoAula = static fn(string $canal, string $ref): array => ['tipo' => 'aula', 'canal' => $canal, 'referencia' => $ref, 'colaborador_id' => null, 'chave' => "x|$canal",
        'destino' => $canal === 'email' ? $emailSaiu : '5521998761212', 'nome' => 'Yara', 'dados' => json_encode(['email' => $emailSaiu])];
    $whatsAntesDeSair = mcp_aviso_destinatario($avisoAula('whatsapp', $amanha))['ok'];
    mcp_avisos_bloquear_hash('email', mcp_avisos_hash('email', $emailSaiu), 'descadastro');
    $whatsDepoisDoDescadastro = mcp_aviso_destinatario($avisoAula('whatsapp', $amanha))['ok'];
    $db->prepare("UPDATE mcp_avisos_bloqueios SET origem = 'pagina' WHERE canal = 'email' AND destino_hash = ?")->execute([mcp_avisos_hash('email', $emailSaiu)]);
    verificar('aula: recesso marcado depois barra o lembrete; "não quero mais receber" na página vale para o WhatsApp, o descadastro só do e-mail não',
        [mcp_aviso_destinatario($avisoAula('email', $dataFechada))['motivo'] ?? null, $whatsAntesDeSair, $whatsDepoisDoDescadastro, mcp_aviso_destinatario($avisoAula('whatsapp', $amanha))['motivo'] ?? null],
        ['sede fechada nesse dia', true, true, 'pediu para não receber']);
    foreach ($ajustesAntesAula as $nome => $valor) {
        mcp_ajuste_gravar($nome, $valor, $quem);
    }

    // Fila: "Enviei" logo depois das 20h (a página estava aberta) ainda vale; horas depois, ou "pular" num vencido, não, e o portal diz.
    $vencido = static function (string $n, int $haSegundos) use ($db, $w1, $marca, $amanha, $sufixo): int {
        $id = (int) mcp_aviso_criar(['chave' => "teste-vencido-$n|$sufixo", 'tipo' => 'vespera', 'canal' => 'whatsapp', 'colaborador_id' => (int) $w1['id'], 'pessoa' => 'c' . $w1['id'],
            'nome' => "Wagner $marca", 'destino' => '5521998760005', 'referencia' => $amanha]);
        $db->prepare("UPDATE mcp_avisos SET status = 'expirado', expira_em = ? WHERE id = ?")->execute([gmdate('Y-m-d H:i:s', time() - $haSegundos), $id]);
        return $id;
    };
    $idVencido = $vencido('a', 1800);
    $idVelho = $vencido('b', 3 * 3600);
    [, $cabVelho] = portal($base, $sessaoPortal, ['acao' => 'fila_enviada', 'id' => $idVelho, 't' => $csrf('fila_enviada', $idVelho)]);
    verificar('fila: "Enviei" logo depois das 20h vale; horas depois, ou "pular" num vencido, não (e o portal avisa)',
        [mcp_avisos_fila_marcar($idVencido, true, $quem), mcp_aviso_por_id($idVencido)['status'], mcp_avisos_fila_marcar($idVelho, false, $quem), mcp_aviso_por_id($idVelho)['status'],
        str_ends_with($cabVelho['location'] ?? '', 'ok=fl_ja')], [true, 'enviado', false, 'expirado', true]);

    // Ajustes: desligar um lembrete tira da fila; religar no mesmo dia devolve o que ainda está no prazo.
    $idToggle = (int) mcp_aviso_criar(['chave' => "teste-religar|$sufixo", 'tipo' => 'saida', 'canal' => 'email', 'colaborador_id' => (int) $v1['id'], 'pessoa' => 'c' . $v1['id'], 'nome' => 'Vera',
        'destino' => "vera-$sufixo@avisos-teste.example", 'referencia' => '0', 'agendado_para' => gmdate('Y-m-d H:i:s', time() + 3600), 'expira_em' => gmdate('Y-m-d H:i:s', time() + 86400)]);
    $formAjustes = static function (bool $saida): array {
        mcp_ajustes_todos(true);
        $campos = ['dias_fechados' => implode(', ', array_map('mcp_escola_data', mcp_avisos_dias_fechados()))];
        foreach (['lembrete_vespera', 'lembrete_aula', 'aula_whatsapp', 'whatsapp_ativo'] as $nome) {
            if (mcp_ajuste_ligado($nome)) {
                $campos[$nome] = '1';
            }
        }
        return $campos + ($saida ? ['lembrete_saida' => '1'] : []);
    };
    portal($base, $sessaoPortal, ['acao' => 'ajustes_salvar', 'id' => 0, 't' => $csrf('ajustes_salvar', 0)] + $formAjustes(false));
    $situacaoDesligado = mcp_aviso_por_id($idToggle)['status'];
    portal($base, $sessaoPortal, ['acao' => 'ajustes_salvar', 'id' => 0, 't' => $csrf('ajustes_salvar', 0)] + $formAjustes(true));
    mcp_ajustes_todos(true);
    verificar('ajustes: desligar um lembrete tira da fila; religar no mesmo dia devolve o que ainda está no prazo', [$situacaoDesligado, mcp_aviso_por_id($idToggle)['status'], mcp_ajuste('lembrete_saida')],
        ['cancelado', 'pendente', '1']);
    mcp_aviso_atualizar($idToggle, ['status' => 'cancelado', 'erro' => 'fim do teste']);
    // Dias sem expediente: data passada sai, "02/01" em dezembro é do ano seguinte, lista grande demais não é cortada em silêncio.
    $anoQueVem = (int) substr($hoje, 0, 4) + 1;
    $ontemBr = mcp_escola_data(mcp_avisos_dia_mais($hoje, -1));
    $diaPassadoSemAno = substr(mcp_escola_data(mcp_avisos_dia_mais($hoje, -1)), 0, 5);
    portal($base, $sessaoPortal, ['acao' => 'ajustes_salvar', 'id' => 0, 't' => $csrf('ajustes_salvar', 0), 'dias_fechados' => "$ontemBr, $diaPassadoSemAno"] + array_diff_key($formAjustes(true), ['dias_fechados' => 1]));
    mcp_ajustes_todos(true);
    $datas = array_map(static fn(int $i): string => mcp_escola_data(mcp_avisos_dia_mais($hoje, 20 + $i)), range(1, 30));
    [, $cabMuitos] = portal($base, $sessaoPortal, ['acao' => 'ajustes_salvar', 'id' => 0, 't' => $csrf('ajustes_salvar', 0), 'dias_fechados' => implode(', ', $datas)] + array_diff_key($formAjustes(true), ['dias_fechados' => 1]));
    mcp_ajustes_todos(true);
    verificar('dias sem expediente: data passada sai; sem o ano e já passada, é a do ano que vem; mais de 23 datas não é cortado em silêncio',
        [mcp_avisos_dias_fechados(), str_ends_with($cabMuitos['location'] ?? '', 'ok=aj_muitos')], [[$anoQueVem . '-' . substr(mcp_avisos_dia_mais($hoje, -1), 5)], true]);
    mcp_ajuste_gravar('dias_fechados', '', $quem);

    // Comunicado: um teste preso na fila não o deixa "enviando" para sempre.
    $db->prepare("INSERT INTO mcp_campanhas (fase, nome, publico, canais, assunto, titulo, mensagem, status, iniciada_em, criado_por, criado_em, atualizado_em)
        VALUES ('livre', ?, 'colaboradores', 'email', 'x', 'x', 'x', 'enviando', ?, 'avisos@exemplo.org', ?, ?)")->execute(["Concluir $marca", mcp_agora(), mcp_agora(), mcp_agora()]);
    $idConcluir = (int) $db->lastInsertId();
    mcp_aviso_criar(['chave' => "teste-concluir|$sufixo", 'tipo' => 'teste', 'canal' => 'whatsapp', 'campanha_id' => $idConcluir, 'nome' => "Teste $marca", 'destino' => '5521998760009']);
    mcp_campanhas_concluir();
    verificar('comunicado: o teste preso na fila não segura o comunicado em "enviando"', mcp_campanha($idConcluir)['status'], 'enviada');

    // Sem a chave da Resend, o aviso por e-mail não sai pelo servidor do site: falha com o motivo.
    $configSemResend = config_teste(['RESEND_API_KEY' => ''] + $base0);
    $idSemResend = (int) mcp_aviso_criar(['chave' => "teste-sem-resend|$sufixo", 'tipo' => 'link', 'canal' => 'email', 'colaborador_id' => (int) $v1['id'], 'pessoa' => 'c' . $v1['id'],
        'nome' => 'Vera', 'destino' => "vera-$sufixo@avisos-teste.example"]);
    $r = sub($configSemResend, '$s = mcp_aviso_enviar(mcp_aviso_por_id(' . $idSemResend . ')); echo json_encode(["s" => $s, "e" => mcp_aviso_por_id(' . $idSemResend . ')["erro"]]);');
    @unlink($configSemResend);
    verificar('sem a chave da Resend, o aviso por e-mail não sai pelo servidor do site (falha, com o motivo)', [$r['s'] ?? null, str_starts_with((string) ($r['e'] ?? ''), 'falta a chave da Resend')], ['falhou', true]);

    // Métrica "saídas não registradas na hora": turno lançado inteiro à mão e ajuste de uma saída registrada na hora não contam.
    $diaMetrica = mcp_avisos_dia_mais($hoje, -25);
    $colMetrica = colaborador("Mário Métrica $marca", 'voluntario', null, null);
    mcp_ponto_lancar((int) $colMetrica['id'], (string) mcp_ponto_local_para_utc("$diaMetrica 08:00:00"), (string) mcp_ponto_local_para_utc("$diaMetrica 09:00:00"), $quem, 'teste');
    $turnoMetrica = static function (string $entrada, ?string $saida) use ($db, $colMetrica, $diaMetrica): int {
        $db->prepare("INSERT INTO mcp_ponto (colaborador_id, voluntario, entrada, saida, origem_entrada, origem_saida, criado_em, atualizado_em) VALUES (?, 1, ?, ?, 'aparelho', ?, ?, ?)")
            ->execute([(int) $colMetrica['id'], mcp_ponto_local_para_utc("$diaMetrica $entrada:00"), $saida ? mcp_ponto_local_para_utc("$diaMetrica $saida:00") : null, $saida ? 'aparelho' : null, mcp_agora(), mcp_agora()]);
        return (int) $db->lastInsertId();
    };
    $idAjuste = $turnoMetrica('10:00', '11:00');
    mcp_ponto_corrigir($idAjuste, (string) mcp_ponto_local_para_utc("$diaMetrica 10:00:00"), mcp_ponto_local_para_utc("$diaMetrica 11:30:00"), $quem, 'teste');
    $idSemSaida = $turnoMetrica('13:00', null);
    mcp_ponto_corrigir($idSemSaida, (string) mcp_ponto_local_para_utc("$diaMetrica 13:00:00"), mcp_ponto_local_para_utc("$diaMetrica 14:00:00"), $quem, 'teste');
    $nucleo = mcp_metricas_nucleo($diaMetrica, mcp_avisos_dia_mais($diaMetrica, 1));
    verificar('métricas: só a saída lançada num registro que estava sem saída conta como não registrada na hora', [$nucleo['entradas'], $nucleo['esquecidas']], [3, 1]);

    // Cadastro: o mesmo celular escrito sem o 9 (formato antigo) não é troca: a autorização e os links continuam.
    $colTelefone = colaborador("Telma Telefone $marca", 'voluntario', "telma-$sufixo@avisos-teste.example", '21998761234');
    $db->prepare('UPDATE mcp_colaboradores SET aviso_whatsapp = 1, aviso_whatsapp_em = ?, aviso_whatsapp_por = ? WHERE id = ?')->execute([mcp_agora(), $quem, (int) $colTelefone['id']]);
    $chaveTelma = mcp_avisos_chave_colaborador(mcp_colaborador_por_id((int) $colTelefone['id']));
    $telma = mcp_colaborador_por_id((int) $colTelefone['id']);
    mcp_colaborador_salvar((int) $telma['id'], ['nome' => $telma['nome'], 'cpf' => $telma['cpf'], 'email' => $telma['email'], 'telefone' => '2198761234', 'funcao' => $telma['funcao'],
        'vinculo' => $telma['vinculo'], 'ativo' => 1], $quem);
    $telma = mcp_colaborador_por_id((int) $colTelefone['id']);
    verificar('cadastro: o mesmo celular sem o 9 não é troca (a autorização do WhatsApp e os links continuam)', [(int) $telma['aviso_whatsapp'], $telma['aviso_chave']], [1, $chaveTelma]);

    // ------------------------------------------------------------------------- faxina e rotina
    $idPreso = mcp_aviso_criar(['chave' => "teste-preso|$sufixo", 'tipo' => 'vespera', 'canal' => 'email', 'colaborador_id' => (int) $v2['id'], 'pessoa' => 'c' . $v2['id'], 'nome' => "x $marca", 'destino' => "vitor-$sufixo@avisos-teste.example", 'referencia' => $amanha]);
    $db->prepare("UPDATE mcp_avisos SET status = 'enviando', atualizado_em = ? WHERE id = ?")->execute([gmdate('Y-m-d H:i:s', time() - 1200), $idPreso]);
    mcp_avisos_expirar();
    verificar('faxina: envio interrompido vira falha (sem mandar de novo)', [mcp_aviso_por_id($idPreso)['status'], mcp_aviso_por_id($idPreso)['erro']], ['falhou', 'envio interrompido; confira antes de mandar de novo']);
    $db->prepare('UPDATE mcp_avisos SET criado_em = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 400 * 86400), $idPreso]);
    $db->prepare('UPDATE mcp_opinioes SET atualizado_em = ? WHERE campanha_id = ? AND pessoa = ?')->execute([gmdate('Y-m-d H:i:s', time() - 400 * 86400), (int) $depois['id'], 'c' . $v2['id']]);
    $db->prepare("INSERT INTO mcp_eventos (inscricao_id, tipo, detalhe, criado_em) VALUES (NULL, 'aviso_telefone', ?, ?), (NULL, 'aviso_telefone', ?, ?)")
        ->execute(["velho $marca", gmdate('Y-m-d H:i:s', time() - 400 * 86400), "novo $marca", mcp_agora()]);
    verificar('faxina: registro com mais de 1 ano é apagado', [mcp_avisos_apagar_antigos() >= 2, mcp_aviso_por_id($idPreso)], [true, null]);
    $eventosTelefone = $db->prepare("SELECT detalhe FROM mcp_eventos WHERE tipo = 'aviso_telefone' AND detalhe IN (?, ?)");
    $eventosTelefone->execute(["velho $marca", "novo $marca"]);
    verificar('faxina: o registro das escolhas (troca de número etc.) com mais de 1 ano também sai', $eventosTelefone->fetchAll(PDO::FETCH_COLUMN), ["novo $marca"]);
    verificar('faxina: a opinião com mais de 1 ano também (e só ela)', [mcp_opinioes_resultado((int) $depois['id'])['respostas'], mcp_opinioes_resultado((int) $depois['id'])['por_publico']], [1, ['colaborador' => 0, 'aluno' => 1]]);
    // A opinião é anônima: o portal não mostra quem clicou no comunicado da pesquisa, e os comentários vêm sem data.
    [, , $htmlDepois] = http($base . 'painel.php?v=comunicado&id=' . (int) $depois['id'], 'GET', null, ["Cookie: $sessaoPortal"]);
    $comentarioAluno = mcp_opinioes_resultado((int) $depois['id'])['comentarios'][0] ?? [];
    verificar('opinião anônima: o portal mostra quantos clicaram, e não quem; o comentário vem sem data',
        [str_contains($htmlDepois, 'o portal mostra quantos clicaram, e não quem'), str_contains($htmlDepois, 'não mostrado'), array_key_exists('quando', $comentarioAluno), isset($comentarioAluno['texto'])],
        [true, true, false, true]);
    $idCliqueDepois = (int) $db->query('SELECT id FROM mcp_avisos WHERE campanha_id = ' . (int) $depois['id'] . " AND tipo = 'campanha' ORDER BY id LIMIT 1")->fetchColumn();
    $db->prepare('UPDATE mcp_avisos SET clicado_em = ? WHERE id = ?')->execute([gmdate('Y-m-d 13:47:12', time() - 35 * 86400), $idCliqueDepois]);
    $db->prepare('UPDATE mcp_campanhas SET iniciada_em = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 40 * 86400), (int) $depois['id']]);
    mcp_avisos_apagar_antigos();
    $iniciadaDepois = (string) mcp_campanha((int) $depois['id'])['iniciada_em'];
    $datasOpiniao = $db->prepare('SELECT DISTINCT criado_em, atualizado_em FROM mcp_opinioes WHERE campanha_id = ?');
    $datasOpiniao->execute([(int) $depois['id']]);
    [$stFechada] = api_avisos($base, ['acao' => 'opiniao_ler', 't' => $tokOpiniao]);
    verificar('faxina: ao fim da pesquisa, a resposta fica com a data do comunicado, o clique só com o dia, e a pesquisa fecha',
        [$datasOpiniao->fetchAll(), mcp_aviso_por_id($idCliqueDepois)['clicado_em'], $stFechada],
        [[['criado_em' => $iniciadaDepois, 'atualizado_em' => $iniciadaDepois]], gmdate('Y-m-d 15:00:00', time() - 35 * 86400), 404]);
    $quemRespondeu = $db->prepare('SELECT pessoa FROM mcp_opinioes WHERE campanha_id = ?');
    $quemRespondeu->execute([(int) $depois['id']]);
    verificar('faxina: um mês depois do comunicado, a opinião deixa de ficar ligada a quem respondeu (e continua contando)',
        [array_values(array_unique(array_map(static fn(string $p): string => $p[0], $quemRespondeu->fetchAll(PDO::FETCH_COLUMN)))), mcp_opinioes_resultado((int) $depois['id'])['respostas']], [['x'], 1]);
    $saida = [];
    exec('MCP_CONFIG_ARQUIVO=' . escapeshellarg($config) . ' MCP_CONFIG_ESCOLA_ARQUIVO=' . escapeshellarg($semEscola) . ' ' . escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg($raiz . '/site/matricula-cursos-presenciais/api/comparecimentos.php') . ' 2>&1', $saida, $codigo);
    verificar('rotina: a linha de comando roda os avisos junto com os comprovantes', (bool) preg_match('~; avisos: \d+ preparados, \d+ enviados, \d+ falhas, \d+ vencidos$~', implode("\n", $saida)), true);

    $erros = trim((string) file_get_contents($logErros));
    verificar('sem aviso nem erro do PHP nos servidores', $erros, '');
} finally {
    limpar($db);
    $db->exec("DELETE FROM mcp_ajustes");
    foreach ($ajustesAntes as $nome => $valor) {
        mcp_ajuste_gravar((string) $nome, (string) $valor, 'restaurado pelo teste');
    }
    foreach ([$servidor, $servidorCloud, $servidorFalso] as $p) {
        proc_terminate($p);
    }
    array_map('unlink', glob("$falso/*") ?: []);
    @rmdir($falso);
    foreach ([$config, $configCloud, $configMake, $configMakeCurto, $configEvolution, $configEvolutionChave, $arquivoWhatsapp, $semEscola, $logErros] as $arq) {
        @unlink($arq);
    }
}
echo "\n$total testes, $falhas falhas\n";
exit($falhas > 0 ? 1 : 0);
