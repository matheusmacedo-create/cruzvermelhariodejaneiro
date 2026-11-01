#!/usr/bin/env php
<?php
/**
 * Teste da matrícula pendente (api/lib/matricula_pendente.php, api/matricula.php, a página pagar-matricula/ pelo
 * api/ir.php, o descadastro ?mp= de api/avisos.php, o postback em api/webhook.php, a entrega em api/kapso-webhook.php e
 * a seção do painel em api/recuperacao.php) e do reforço da recuperação de 01/11/2026 que precisa da Unicopag (a
 * consulta do PIX antes da 1ª mensagem). Contra um MariaDB LOCAL e um servidor falso local que faz de Kapso, PostgREST
 * da escola, Resend e Unicopag (anota tudo; nada sai de verdade). Cobre:
 *   - regras puras: véspera, régua (perto, longe, taxa paga na véspera, 2ª já chegou, turma passada, 1ª que não
 *     chegou), a data do {{3}} ("amanhã, terça-feira, 17/11, às 9h"), a mensagem, o link curto mat1/mat2 e o e-mail;
 *   - público: só a taxa paga (o plano completo fica de fora), matrícula paga no site, na escola (PostgREST) e marcada
 *     no painel, sem turma, turma passada, CPF de teste, WhatsApp bloqueado (só e-mail);
 *   - rodadas: fora do horário, 1ª com 1 h, véspera (só a 2ª, com "amanhã"), 48 h antes com a turma longe, pagou entre as
 *     mensagens, descadastro do e-mail para os dois canais, "já pagou na escola" do painel, escola fora do ar (segue com
 *     o site), teto em 24 h somado ao da recuperação, modelo sem aprovação (o e-mail segue) e a família (mat_pendente_1_v2);
 *   - pagamento de ponta a ponta: o link curto (302 e cookie), a situação (sem CPF nem telefone), o PIX (reaproveitado no
 *     segundo pedido), o postback que confirma, o comprovante ao aluno e o aviso à secretaria, a recusa de pagar de novo,
 *     o cartão aprovado (sem o número no banco) e o recusado, e os links que não valem;
 *   - painel: a seção "Matrícula pendente" (números, lista, botão "Já pagou na escola" com token), sem número inteiro;
 *   - recuperação (B): quem tem PIX pendente pago na Unicopag sem o aviso ter chegado não recebe a 1ª (30 min).
 *
 * As datas ficam 400 dias no futuro, às 10h de Brasília (o que outros testes deixaram fica fora da janela). Tudo o que o
 * teste cria é apagado no fim.
 *
 * Uso: MCP_CONFIG_ARQUIVO=/caminho/config-teste.php php scripts/testar_matricula_pendente_integracao.php
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$configOriginal = (string) getenv('MCP_CONFIG_ARQUIVO');
if ($configOriginal === '' || !is_file($configOriginal)) {
    fwrite(STDERR, "Falta MCP_CONFIG_ARQUIVO com o banco local (veja o cabeçalho deste arquivo).\n");
    exit(2);
}

/** CPF com dígito verificador válido; depois de carregar o módulo, só FORA do grupo de controle da recuperação. */
function cpf_de_teste(): string
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
    } while (function_exists('mcp_rw_chave_no_controle') && mcp_rw_chave_no_controle('c:' . $cpf));
    return $cpf;
}

$portaSite = 20600 + random_int(0, 249);
$portaFalso = $portaSite + 250;
$falso = sys_get_temp_dir() . '/mcp-mp-falso-' . bin2hex(random_bytes(4));
mkdir($falso);
$quem = 'matricula-pendente@exemplo.org';
$cpfTeste = cpf_de_teste();
$base0 = [
    'PAINEL_EMAILS' => $quem, 'EMAIL_SECRETARIA' => 'secretaria-teste@exemplo.org', 'SITE_URL' => "http://127.0.0.1:$portaSite", 'TESTE_CPFS' => [$cpfTeste],
    'EMAIL_CONTATO' => 'contato@exemplo.org', 'EMAIL_REMETENTE' => 'Cruz Vermelha Brasileira Rio de Janeiro <matricula@info.exemplo.org>',
    'RESEND_API_URL' => "http://127.0.0.1:$portaFalso/emails", 'UNICO_BASE_URL' => "http://127.0.0.1:$portaFalso",
    'ESCOLA_API_URL' => "http://127.0.0.1:$portaFalso/rest/v1/rpc/matricula_rapida", 'ESCOLA_API_TOKEN' => 'chave-escola-falsa',
    'WHATSAPP_KAPSO_BASE' => "http://127.0.0.1:$portaFalso",
];
function config_teste(array $extra): string
{
    global $configOriginal;
    $arq = tempnam(sys_get_temp_dir(), 'mcp-mp-cfg-');
    file_put_contents($arq, '<?php return ' . var_export($extra, true) . ' + (require ' . var_export($configOriginal, true) . ');');
    return $arq;
}
$config = config_teste($base0 + ['RESEND_API_KEY' => 're_teste', 'UNICO_API_KEY' => 'unico-teste', 'WHATSAPP_KAPSO_CHAVE' => 'kapso-chave-de-teste-123', 'WHATSAPP_KAPSO_NUMERO_ID' => '1112223334']);
$configSem = config_teste($base0 + ['RESEND_API_KEY' => '', 'UNICO_API_KEY' => '']);
$vazio = tempnam(sys_get_temp_dir(), 'mcp-mp-vazio-');
file_put_contents($vazio, '<?php return [];');
putenv("MCP_CONFIG_ARQUIVO=$config");
putenv("MCP_CONFIG_ESCOLA_ARQUIVO=$vazio");
putenv("MCP_CONFIG_WHATSAPP_ARQUIVO=$vazio");
putenv('MCP_CONFIG_RECUPERACAO_ARQUIVO=' . sys_get_temp_dir() . '/mcp-mp-sem-importados.php');
putenv('MCP_CATALOGO_ARQUIVO=' . $raiz . '/site/matricula-cursos-presenciais/cursos.json');

// A base do teste: daqui a 400 dias, 13h UTC (10h de Brasília). As turmas começam às 9h de Brasília (12h UTC).
$A = (int) strtotime(gmdate('Y-m-d', time() + 400 * 86400) . ' 13:00:00 UTC');
$turmas = ['mp-perto' => $A + 2 * 86400 - 3600, 'mp-longe' => $A + 12 * 86400 - 3600, 'mp-vespera' => $A + 86400 - 3600, 'mp-passou' => $A - 86400 - 3600,
    'mp-longe2' => $A + 40 * 86400 - 3600];
$iso = static fn(int $ts): string => gmdate('Y-m-d\TH:i:s', $ts - 3 * 3600) . '-03:00';
$ofertaTeste = tempnam(sys_get_temp_dir(), 'mcp-mp-oferta-');
file_put_contents($ofertaTeste, json_encode(['parcelado_no_ar' => false, 'sem_turma' => [], 'turmas' => array_map(static fn(string $id, int $ts): array => [
    'curso' => 'puncao-venosa', 'id_escola' => $id, 'inicio' => $iso($ts), 'fim' => $iso($ts + 8 * 3600), 'inscricoes_ate' => $iso($ts - 86400), 'lotada' => false,
], array_keys($turmas), $turmas)]));
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

// ----------------------------------------------------------------------------- servidor falso (Kapso, escola, Resend e Unicopag)
$roteador = "$falso/roteador.php";
file_put_contents($roteador, <<<'PHP'
<?php
// Servidor falso: anota cada pedido num .jsonl e responde como a Kapso, a escola, a Resend e a Unicopag responderiam.
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
    $n = $anotar('emails.jsonl', json_decode($corpo, true) ?: []);
    echo json_encode(['id' => "re_teste_$n"]);
    return true;
}
if (str_starts_with($caminho, '/public/v1/')) {
    if (($_GET['api_token'] ?? '') !== 'unico-teste') { http_response_code(401); echo '{"message":"token"}'; return true; }
    if ($caminho === '/public/v1/payments' && $metodo === 'POST') {
        $d = json_decode($corpo, true) ?: [];
        // O cartão nunca é anotado (como em produção, ninguém guarda o número): só o fim.
        $fim = isset($d['card']['number']) ? substr((string) $d['card']['number'], -4) : null;
        unset($d['card']);
        $n = $anotar('unico.jsonl', $d + ['cartao_fim' => $fim]);
        $hash = "mp-hash-$n";
        if (($d['payment_method'] ?? '') === 'credit_card') {
            echo json_encode(['hash' => $hash, 'payment_status' => $fim === '0002' ? 'refused' : 'paid', 'card_brand' => 'visa', 'amount' => $d['amount'] ?? 0, 'amount_total' => $d['amount'] ?? 0]);
            return true;
        }
        echo json_encode(['hash' => $hash, 'payment_status' => 'waiting_payment', 'amount' => $d['amount'] ?? 0,
            'pix' => ['pix_qr_code' => "00020126pix-matricula-$n", 'pix_url' => "https://pix.exemplo.org/$n", 'pix_base64' => null]]);
        return true;
    }
    if (preg_match('~^/public/v1/transactions/([A-Za-z0-9-]+)$~', $caminho, $m)) {
        $status = $controle('unico')[$m[1]] ?? 'waiting_payment';
        $anotar('unico_consultas.jsonl', ['hash' => $m[1]]);
        echo json_encode(['hash' => $m[1], 'payment_status' => $status]);
        return true;
    }
}
if (str_starts_with($caminho, '/meta/') || str_starts_with($caminho, '/platform/')) {
    if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== 'kapso-chave-de-teste-123') { http_response_code(401); echo '{"error":"Invalid API key"}'; return true; }
}
if ($caminho === '/meta/whatsapp/v24.0/1112223334/messages' && $metodo === 'POST') {
    $d = json_decode($corpo, true) ?: [];
    $n = $anotar('kapso.jsonl', ['corpo' => $d]);
    echo json_encode(['messaging_product' => 'whatsapp', 'messages' => [['id' => "wamid.mp-$n"]]]);
    return true;
}
if ($caminho === '/platform/v1/whatsapp/phone_numbers/1112223334' && $metodo === 'GET') {
    echo '{"data":{"id":"1112223334","business_account_id":"998877665544"}}';
    return true;
}
if ($caminho === '/meta/whatsapp/v24.0/998877665544/message_templates') {
    $pendentes = (array) ($controle('modelos')['pendente'] ?? []);
    $sit = static fn(string $n): string => in_array($n, $pendentes, true) ? 'PENDING' : 'APPROVED';
    $lista = [];
    foreach (['rec_inscricao_1_v2' => 'UTILITY', 'rec_inscricao_2' => 'MARKETING', 'rec_inscricao_3' => 'MARKETING', 'ps_cupom_oferta' => 'MARKETING',
        'mat_pendente_1' => 'UTILITY', 'mat_pendente_2' => 'UTILITY'] as $nome => $cat) {
        $lista[] = ['name' => $nome, 'status' => $sit($nome), 'language' => 'pt_BR', 'category' => $cat];
    }
    echo json_encode(['data' => array_merge($lista, array_values((array) ($controle('modelos')['extras'] ?? [])))]);
    return true;
}
if ($caminho === '/rest/v1/Usuario' && $metodo === 'GET') {
    if (($_SERVER['HTTP_APIKEY'] ?? '') !== 'chave-escola-falsa') { http_response_code(401); echo '{}'; return true; }
    $c = $controle('escola');
    if (!empty($c['fora'])) { http_response_code(403); echo '{"message":"permission denied (teste)"}'; return true; }
    $usuarios = $c['usuarios'] ?? [];
    if (isset($_GET['criadoEm'])) {
        $desde = substr((string) $_GET['criadoEm'], 4);
        $usuarios = array_values(array_filter($usuarios, static fn(array $u): bool => $u['criadoEm'] >= $desde));
    } elseif (isset($_GET['or']) && preg_match('/^\((cpfCnpj|email|celular)\.in\.\((.*)\)\)$/', (string) $_GET['or'], $m)) {
        $valores = str_getcsv($m[2], ',', '"', '\\');
        $usuarios = array_values(array_filter($usuarios, static fn(array $u): bool => in_array((string) ($u[$m[1]] ?? ''), $valores, true)));
    } elseif (!isset($_GET['id'])) {
        http_response_code(400); echo '{"message":"filtro desconhecido"}'; return true;
    }
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
$padraoEmail = '^mp-[a-z0-9]+-[0-9a-f]{6}@exemplo[.]org$';
$telefones = [];
$inicioTeste = gmdate('Y-m-d H:i:s', time() - 5);

function limpar(PDO $db, string $padraoEmail, array $telefones, string $inicio): void
{
    $stmt = $db->prepare('SELECT id FROM mcp_inscricoes WHERE email REGEXP ?');
    $stmt->execute([$padraoEmail]);
    $ids = implode(', ', array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    if ($ids !== '') {
        $db->exec("DELETE FROM mcp_eventos WHERE inscricao_id IN ($ids)");
        $db->exec("DELETE FROM mcp_matricula_pendente WHERE inscricao_id IN ($ids)");
        $db->exec("DELETE FROM mcp_matricula_pagamentos WHERE inscricao_id IN ($ids)");
        $db->exec("DELETE FROM mcp_preferencias WHERE inscricao_id IN ($ids)");
        $db->exec("DELETE FROM mcp_inscricoes WHERE id IN ($ids)");
    }
    $db->exec('DELETE FROM mcp_recuperacao_whatsapp WHERE criado_em > DATE_ADD(UTC_TIMESTAMP(), INTERVAL 300 DAY)');
    $db->exec('DELETE FROM mcp_recuperacao_email WHERE criado_em > DATE_ADD(UTC_TIMESTAMP(), INTERVAL 300 DAY)');
    foreach ($telefones as $numero) {
        $db->prepare("DELETE FROM mcp_avisos_bloqueios WHERE canal = 'whatsapp' AND destino_hash = ?")->execute([mcp_avisos_hash('whatsapp', $numero)]);
    }
    $db->exec("DELETE FROM mcp_chaves WHERE nome IN ('rw_cache_modelos', 'rw_cache_webhook')");
    $db->prepare("DELETE FROM mcp_eventos WHERE inscricao_id IS NULL AND criado_em >= ? AND tipo IN ('matricula_rodada', 'matricula_pedido', 'recuperacao_rodada',
        'recuperacao_rodada_email', 'recuperacao_link', 'rw_cache_modelos', 'aviso_secretaria', 'armadilha')")->execute([$inicio]);
}
limpar($db, $padraoEmail, [], $inicioTeste);

$celular = static function (string $fim = '') use (&$telefones): string {
    $t = '2197' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
    $telefones[] = '55' . $t;
    return $t;
};
$quando = static fn(int $ts): string => gmdate('Y-m-d H:i:s', $ts);
/** Uma inscrição paga só com a taxa (o plano padrão), com a turma vendida $turma (id do oferta.json), paga em $pagoTs. */
$taxaPaga = static function (string $chave, int $pagoTs, ?string $turma, array $campos = []) use ($db, $sufixo, $celular, $quando, $turmas): array {
    $linha = $campos + [
        'token' => mcp_token_novo(), 'cpf' => cpf_de_teste(), 'telefone' => $celular(), 'metodo' => 'pix', 'curso_slug' => 'puncao-venosa', 'curso_nome' => 'Punção Venosa',
        'nome' => 'Aluna Teste ' . ucfirst($chave), 'email' => "mp-$chave-$sufixo@exemplo.org", 'inscricao_centavos' => 9900, 'taxa_centavos' => 0, 'total_centavos' => 9900,
        'status' => 'pago', 'escola_status' => 'nao_aplicavel', 'plano' => 'so_taxa', 'turma_id' => $turma, 'turma_inicio' => $turma !== null ? $quando($turmas[$turma]) : null,
        'criado_em' => $quando($pagoTs - 120), 'atualizado_em' => $quando($pagoTs), 'pago_em' => $quando($pagoTs), 'unicopag_hash' => 'taxa-' . bin2hex(random_bytes(4)),
    ];
    $colunas = array_keys($linha);
    $db->prepare('INSERT INTO mcp_inscricoes (' . implode(', ', $colunas) . ') VALUES (' . implode(', ', array_fill(0, count($colunas), '?')) . ')')->execute(array_values($linha));
    $linha['id'] = (int) $db->lastInsertId();
    return $linha;
};
$wa = static fn(string $tel): array => array_values(array_filter(falso('kapso'), static fn(array $x): bool => ($x['corpo']['to'] ?? '') === '55' . $tel));
$waNomes = static fn(string $tel): array => array_map(static fn(array $x): string => (string) $x['corpo']['template']['name'], $wa($tel));
$emails = static fn(string $para): array => array_values(array_filter(falso('emails'), static fn(array $x): bool => ($x['to'][0] ?? '') === $para));
$linhasMp = static function (int $id) use ($db): array {
    $stmt = $db->prepare('SELECT canal, etapa, modelo, status FROM mcp_matricula_pendente WHERE inscricao_id = ? ORDER BY CAST(canal AS CHAR), etapa');
    $stmt->execute([$id]);
    return array_map(static fn(array $l): string => $l['canal'] . $l['etapa'] . ':' . $l['status'] . ($l['modelo'] ? ':' . $l['modelo'] : ''), $stmt->fetchAll());
};
$dia = static fn(int $ts): string => MCP_DIAS_SEMANA[(int) gmdate('w', $ts - 3 * 3600)] . ', ' . gmdate('d/m', $ts - 3 * 3600);
$uuidPv = (string) mcp_curso('puncao-venosa')['uuid'];
$valorPv = (int) mcp_curso('puncao-venosa')['valor_curso_centavos'];

$servidor = null;
$servidorFalso = null;
try {
    $nulo = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $servidorFalso = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaFalso", $roteador], $nulo, $tubos2, null, ['FALSO_DIR' => $falso, 'PATH' => (string) getenv('PATH')]);
    $logErros = tempnam(sys_get_temp_dir(), 'mcp-mp-php-');
    $servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaSite", '-t', $raiz . '/site', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$logErros"], $nulo, $tubos, null, [
        'MCP_CONFIG_ARQUIVO' => $config, 'MCP_CONFIG_ESCOLA_ARQUIVO' => $vazio, 'MCP_CONFIG_WHATSAPP_ARQUIVO' => $vazio,
        'MCP_CONFIG_RECUPERACAO_ARQUIVO' => sys_get_temp_dir() . '/mcp-mp-sem-importados.php',
        'MCP_CATALOGO_ARQUIVO' => getenv('MCP_CATALOGO_ARQUIVO'), 'MCP_OFERTA_ARQUIVO' => $ofertaTeste, 'PATH' => (string) getenv('PATH'),
    ]);
    foreach ([$portaSite, $portaFalso] as $porta) {
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $porta) === false; $i++) {
            usleep(100000);
        }
    }

    // ------------------------------------------------------------------------- regras puras
    $perto = $turmas['mp-perto'];
    $longe = $turmas['mp-longe'];
    $vespera = $turmas['mp-vespera'];
    verificar('véspera: o dia anterior às 10h de Brasília', mcp_mp_vespera($perto) - $perto, -86400 + 3600);
    verificar('régua (turma perto): a 1ª com 1 h da taxa; depois dela, a 2ª na véspera às 10h', [
        mcp_mp_proxima($A - 3 * 3600, $perto, [], $A - 3 * 3600),
        mcp_mp_proxima($A - 3 * 3600, $perto, [1 => ['status' => 'enviado', 'entrega' => null, 'erro' => null, 'criado_ts' => $A]], $A),
    ], [['etapa' => 1, 'devida_em' => $A - 2 * 3600, 'limite' => $perto - 3600], ['etapa' => 2, 'devida_em' => $A + 86400, 'limite' => $perto - 3600]]);
    verificar('régua (turma longe, mais de 7 dias): a 2ª 48 h antes do início', mcp_mp_proxima($A - 2 * 3600, $longe, [1 => ['status' => 'enviado', 'entrega' => null, 'erro' => null, 'criado_ts' => $A]], $A),
        ['etapa' => 2, 'devida_em' => $longe - 48 * 3600, 'limite' => $longe - 3600]);
    verificar('régua (taxa paga na véspera): vai só a 2ª; e a 1ª segurada até a véspera vira a 2ª', [
        mcp_mp_proxima($A - 3 * 3600, $vespera, [], $A),
        mcp_mp_proxima($A - 3 * 86400, $perto, [], $A + 86400 + 60),
    ], [['etapa' => 2, 'devida_em' => $A, 'limite' => $vespera - 3600], ['etapa' => 2, 'devida_em' => $A + 86400, 'limite' => $perto - 3600]]);
    verificar('régua: 6 h entre a 1ª e a 2ª; turma que começa em menos de 1 h ou já começou, nada; 1ª que não chegou para o canal; depois da 2ª, nada', [
        mcp_mp_proxima($A - 30 * 3600, $perto, [1 => ['status' => 'enviado', 'entrega' => null, 'erro' => null, 'criado_ts' => $A + 86400 - 3600]], $A + 86400)['devida_em'] ?? null,
        mcp_mp_proxima($perto - 2 * 3600, $perto, [], $perto - 3600),
        mcp_mp_proxima($A - 3 * 86400, $turmas['mp-passou'], [], $A),
        mcp_mp_proxima($A - 3 * 3600, $perto, [1 => ['status' => 'falhou', 'entrega' => null, 'erro' => '#131026', 'criado_ts' => $A]], $A),
        mcp_mp_proxima($A - 3 * 3600, $perto, [1 => ['status' => 'enviado', 'entrega' => 'falhou', 'erro' => null, 'criado_ts' => $A]], $A),
        mcp_mp_proxima($A - 3 * 3600, $perto, [2 => ['status' => 'enviado', 'entrega' => null, 'erro' => null, 'criado_ts' => $A]], $A),
    ], [$A + 86400 + 5 * 3600, null, null, null, null, null]);
    verificar('data do {{3}}: 1ª sem hora; 2ª com "amanhã"/"hoje" e a hora; 48 h antes, sem prefixo; 9h30', [
        mcp_mp_data_texto($perto, true, 1, $A), mcp_mp_data_texto($perto, true, 2, $A + 86400), mcp_mp_data_texto($perto, true, 2, $perto - 2 * 3600),
        mcp_mp_data_texto($longe, true, 2, $longe - 48 * 3600), mcp_mp_data_texto($perto + 1800, true, 2, $A + 86400), mcp_mp_data_texto($perto, false, 2, $A + 86400),
    ], [$dia($perto), 'amanhã, ' . $dia($perto) . ', às 9h', 'hoje, ' . $dia($perto) . ', às 9h', $dia($longe) . ', às 9h', 'amanhã, ' . $dia($perto) . ', às 9h30', 'amanhã, ' . $dia($perto)]);
    $pPura = ['nome' => 'PAULA SOUZA', 'curso_nome' => 'Punção Venosa', 'curso_slug' => 'puncao-venosa', 'turma_ts' => $perto, 'turma_hora' => true, 'valor' => 15000];
    $tokPuro = mcp_rw_token('m123', $A);
    $m1 = mcp_mp_modelo(1, $pPura, $A, $tokPuro);
    $m2 = mcp_mp_modelo(2, ['nome' => ''] + $pPura, $A + 86400, $tokPuro);
    verificar('mensagem: mat_pendente_1 e _2 com nome, curso e data; botão com o link curto mat1/mat2; sem nome, "aluno(a)"', [$m1['nome'], $m1['parametros'], $m1['caminho'], $m2['nome'], $m2['parametros'],
        str_contains($m1['previa'], 'Olá, Paula.') && !str_contains($m1['previa'] . $m2['previa'], '{{')],
        ['mat_pendente_1', ['Paula', 'Punção Venosa', $dia($perto)], "matricula-cursos-presenciais/api/ir.php/w/puncao-venosa/mat1/$tokPuro", 'mat_pendente_2',
            ['aluno(a)', 'Punção Venosa', 'amanhã, ' . $dia($perto) . ', às 9h'], true]);
    verificar('link curto mat1/mat2: a tela de pagar a matrícula com as UTMs; sem curso ou curso inválido → página de matrícula; o token m<id> confere', [
        mcp_rw_link_destino("/w/puncao-venosa/mat1/$tokPuro"), mcp_rw_link_destino('/e/puncao-venosa/mat2')['destino'], mcp_rw_link_destino('/w/cursos/mat1')['destino'],
        mcp_rw_link_destino('/w/curso-que-nao-existe/mat2')['destino'], mcp_rw_token_ref($tokPuro, $A), mcp_rw_token_ref(str_replace('m123.', 's123.', $tokPuro), $A),
    ], [['destino' => '/matricula-cursos-presenciais/pagar-matricula/?curso=puncao-venosa&utm_source=whatsapp&utm_medium=recuperacao&utm_campaign=mat1', 'token' => $tokPuro, 'valido' => true],
        '/matricula-cursos-presenciais/pagar-matricula/?curso=puncao-venosa&utm_source=email&utm_medium=recuperacao&utm_campaign=mat2', '/matricula-cursos-presenciais/', '/matricula-cursos-presenciais/', 'm123', null]);
    $e1 = mcp_mp_email_montar(1, $pPura, $A, 'https://exemplo.org/x', 'https://exemplo.org/sair');
    $e2 = mcp_mp_email_montar(2, $pPura, $A + 86400, 'https://exemplo.org/x', 'https://exemplo.org/sair');
    verificar('e-mails: assunto, valor, data, botão, alternativa da secretaria e o "Não quero mais receber"', [$e1['modelo'], $e1['assunto'], str_contains($e1['html'], 'R$ 150,00'),
        str_contains($e1['html'], $dia($perto)), str_contains($e1['html'], 'https://exemplo.org/x'), str_contains($e1['texto'], 'secretaria da Escola'), str_contains($e1['html'], 'https://exemplo.org/sair'),
        $e2['assunto'], str_contains($e2['html'], 'amanhã, ' . $dia($perto) . ', às 9h')],
        ['mat1', 'Sua vaga em Punção Venosa está garantida: falta a matrícula para entrar na aula', true, true, true, true, true,
            'Lembrete: sua turma de Punção Venosa começa amanhã, ' . $dia($perto) . ', às 9h e a matrícula está em aberto', true]);

    // ------------------------------------------------------------------------- dados
    $p1 = $taxaPaga('paula', $A - 3 * 3600, 'mp-perto', ['nome' => 'PAULA SOUZA']);     // paga a matrícula pelo PIX, entre as mensagens
    $p2 = $taxaPaga('longe', $A - 2 * 3600, 'mp-longe');                                // turma longe: 2ª 48 h antes
    $p3 = $taxaPaga('pagousite', $A - 3 * 3600, 'mp-perto');                            // matrícula já paga no site
    $db->prepare("INSERT INTO mcp_matricula_pagamentos (inscricao_id, token, metodo, valor_centavos, status, criado_em, atualizado_em, pago_em) VALUES (?, ?, 'pix', ?, 'pago', ?, ?, ?)")
        ->execute([$p3['id'], mcp_token_novo(), $valorPv, $quando($A - 2 * 3600), $quando($A - 2 * 3600), $quando($A - 2 * 3600)]);
    $p4 = $taxaPaga('pagouescola', $A - 3 * 3600, 'mp-perto');                          // matrícula paga na escola
    $p5 = $taxaPaga('bloqueado', $A - 3 * 3600, 'mp-perto');                            // WhatsApp bloqueado: só e-mail
    mcp_avisos_bloquear_hash('whatsapp', mcp_avisos_hash('whatsapp', '55' . $p5['telefone']), 'respondeu PARAR');
    $p6 = $taxaPaga('semturma', $A - 3 * 3600, null);
    $p7 = $taxaPaga('passou', $A - 5 * 86400, 'mp-passou');
    $p8 = $taxaPaga('completa', $A - 3 * 3600, 'mp-perto', ['plano' => 'taxa_e_matricula', 'matricula_centavos' => $valorPv, 'total_centavos' => 9900 + $valorPv]);
    $p9 = $taxaPaga('recente', $A - 1800, 'mp-perto');                                   // 30 min: a 1ª ainda não
    $p10 = $taxaPaga('vespera', $A - 3 * 3600, 'mp-vespera');                           // turma amanhã: só a 2ª, com "amanhã"
    $p11 = $taxaPaga('cpfteste', $A - 3 * 3600, 'mp-perto', ['cpf' => $cpfTeste]);
    $p12 = $taxaPaga('painel', $A - 3 * 3600, 'mp-perto');                              // marcada no painel depois da 1ª
    $p13 = $taxaPaga('cartao', $A - 3 * 3600, 'mp-perto');                              // paga a matrícula no cartão
    controle('escola', ['usuarios' => [['id' => "u-pe-$sufixo", 'nome' => 'Aluna Teste Pagouescola', 'email' => $p4['email'], 'cpfCnpj' => $p4['cpf'], 'celular' => $p4['telefone'],
        'papel' => 'ALUNO', 'criadoEm' => gmdate('Y-m-d\TH:i:s', $A - 10 * 86400) . '.000', 'matriculas' => [['id' => 'm-pe', 'criadoEm' => gmdate('Y-m-d\TH:i:s', $A - 3 * 3600) . '.000',
        'atualizadoEm' => gmdate('Y-m-d\TH:i:s', $A - 3600) . '.000', 'taxaConfirmada' => true, 'statusPagamento' => 'PAGO', 'turma' => ['cursoId' => $uuidPv, 'curso' => ['id' => $uuidPv, 'nome' => 'Punção Venosa']]]]]]]);

    // ------------------------------------------------------------------------- público
    $carga = mcp_mp_carregar($A);
    $de = static fn(array $i): ?array => array_values(array_filter($carga['pessoas'], static fn(array $p): bool => $p['inscricao_id'] === $i['id']))[0] ?? null;
    // O valor de um campo (null de verdade é null; "ausente" se a pessoa ou o campo não existe).
    $campo = static fn(?array $x, string $k): mixed => is_array($x) && array_key_exists($k, $x) ? $x[$k] : 'ausente';
    verificar('público: só a taxa paga (o plano completo e o CPF de teste ficam de fora); escola lida', [$de($p8), $de($p11), $carga['escola']], [null, null, ['estado' => 'ok', 'erro' => null]]);
    verificar('público: pendente, paga no site, paga na escola, sem turma, turma passada', [$campo($de($p1), 'motivo'), [$de($p3)['motivo'] ?? '', $de($p3)['paga_como'] ?? ''],
        [$de($p4)['motivo'] ?? '', $de($p4)['paga_como'] ?? ''], $de($p6)['motivo'] ?? '', $de($p7)['motivo'] ?? ''], [null, ['paga', 'site'], ['paga', 'escola'], 'sem_turma', 'turma_passou']);
    verificar('público: a turma vem do oferta.json (com a hora), o valor da matrícula do cursos.json, e o WhatsApp bloqueado só tira o WhatsApp',
        [$de($p1)['turma_ts'] ?? 0, $de($p1)['turma_hora'] ?? false, $de($p1)['valor'] ?? 0, $de($p5)['motivo_wa'] ?? '', $campo($de($p5), 'motivo_email')], [$perto, true, $valorPv, 'bloqueou', null]);

    // ------------------------------------------------------------------------- rodadas
    $r = mcp_mp_rodar($A - 4 * 3600);
    verificar('fora do horário (6h): nada sai', [$r['motivo'], count(falso('kapso')), count(falso('emails'))], ['fora do horário (8h–20h de Brasília)', 0, 0]);
    $r = mcp_mp_rodar($A);
    verificar('rodada das 10h: as devidas saem nos dois canais', [$r['whatsapp']['enviados'], $r['email']['enviados'], $r['escola']], [5, 6, 'ok']);
    $msg = $wa($p1['telefone'])[0]['corpo'] ?? [];
    verificar('WhatsApp: corpo exato do mat_pendente_1 (nome, curso, data e o botão com o link curto mat1 e o token m<id>)', $msg['template'] ?? [], [
        'name' => 'mat_pendente_1', 'language' => ['code' => 'pt_BR'], 'components' => [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Paula'], ['type' => 'text', 'text' => 'Punção Venosa'], ['type' => 'text', 'text' => $dia($perto)]]],
            ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => 'matricula-cursos-presenciais/api/ir.php/w/puncao-venosa/mat1/' . mcp_rw_token('m' . $p1['id'], $A)]]],
        ]]);
    $msg10 = $wa($p10['telefone'])[0]['corpo']['template'] ?? [];
    verificar('véspera: vai só o mat_pendente_2, com "amanhã" e a hora, e o link mat2', [$waNomes($p10['telefone']), $msg10['components'][0]['parameters'][2]['text'] ?? '',
        str_contains($msg10['components'][1]['parameters'][0]['text'] ?? '', '/mat2/')], [['mat_pendente_2'], 'amanhã, ' . $dia($vespera) . ', às 9h', true]);
    verificar('não recebem: pagou no site, pagou na escola, sem turma, turma passada, plano completo, CPF de teste, 30 min atrás; bloqueado só por e-mail', [
        array_map('count', [$wa($p3['telefone']), $wa($p4['telefone']), $wa($p6['telefone']), $wa($p7['telefone']), $wa($p8['telefone']), $wa($p11['telefone']), $wa($p9['telefone']), $wa($p5['telefone'])]),
        array_map('count', [$emails($p3['email']), $emails($p4['email']), $emails($p6['email']), $emails($p9['email']), $emails($p5['email'])]),
    ], [[0, 0, 0, 0, 0, 0, 0, 0], [0, 0, 0, 0, 1]]);
    $em1 = $emails($p1['email'])[0] ?? [];
    $linhaE1 = $db->prepare("SELECT id FROM mcp_matricula_pendente WHERE inscricao_id = ? AND canal = 'email' AND etapa = 1");
    $linhaE1->execute([$p1['id']]);
    $idE1 = (int) $linhaE1->fetchColumn();
    verificar('e-mail: assunto, link curto do e-mail (mat1 com o token), valor, e o descadastro de um clique (?mp=)', [$em1['subject'] ?? '',
        str_contains((string) ($em1['html'] ?? ''), 'matricula-cursos-presenciais/api/ir.php/e/puncao-venosa/mat1/' . mcp_rw_token('m' . $p1['id'], $A)),
        str_contains((string) ($em1['html'] ?? ''), mcp_brl($valorPv)), $em1['headers']['List-Unsubscribe'] ?? '', $em1['headers']['List-Unsubscribe-Post'] ?? ''],
        ['Sua vaga em Punção Venosa está garantida: falta a matrícula para entrar na aula', true, true, '<' . mcp_mp_descadastro_url($idE1) . '>', 'List-Unsubscribe=One-Click']);
    verificar('linhas gravadas: canal, etapa, status e modelo; nada repete (UNIQUE)', [$linhasMp($p1['id']), $linhasMp($p10['id'])],
        [['email1:enviado:mat1', 'whatsapp1:enviado:mat_pendente_1'], ['email2:enviado:mat2', 'whatsapp2:enviado:mat_pendente_2']]);
    verificar('tetos em 24 h: as mensagens e os e-mails da matrícula contam no teto da recuperação (o mesmo número e a mesma Resend)',
        [mcp_rw_enviadas_24h($A + 60), mcp_rw_email_enviados_24h($A + 60)], [5, 6]);
    $antes = [count(falso('kapso')), count(falso('emails'))];
    $r = mcp_mp_rodar($A + 1200);
    verificar('20 min depois: nada repete (o de 30 min atrás ainda não deu 1 h)', [count(falso('kapso')) - $antes[0], count(falso('emails')) - $antes[1]], [0, 0]);

    // Escola fora do ar (403): segue com o que o site sabe. Quem pagou só na escola recebe (o risco aceito, avisado no painel).
    controle('escola', ['fora' => true]);
    $r = mcp_mp_rodar($A + 3600);
    verificar('escola fora (403): não derruba; segue com o site; quem pagou só na escola recebe a 1ª (risco aceito); o de 30 min recebe a 1ª',
        [$r['escola'], str_contains((string) $r['motivo'], 'segue com o que o site sabe'), $waNomes($p4['telefone']), $waNomes($p9['telefone'])],
        ['indisponivel', true, ['mat_pendente_1'], ['mat_pendente_1']]);
    controle('escola', ['usuarios' => [['id' => "u-pe-$sufixo", 'nome' => 'Aluna Teste Pagouescola', 'email' => $p4['email'], 'cpfCnpj' => $p4['cpf'], 'celular' => $p4['telefone'],
        'papel' => 'ALUNO', 'criadoEm' => gmdate('Y-m-d\TH:i:s', $A - 10 * 86400) . '.000', 'matriculas' => [['id' => 'm-pe', 'criadoEm' => gmdate('Y-m-d\TH:i:s', $A - 3 * 3600) . '.000',
        'atualizadoEm' => gmdate('Y-m-d\TH:i:s', $A - 3600) . '.000', 'taxaConfirmada' => true, 'statusPagamento' => 'PAGO', 'turma' => ['cursoId' => $uuidPv, 'curso' => ['id' => $uuidPv, 'nome' => 'Punção Venosa']]]]]]]);

    // Entrega de uma mensagem da matrícula pelo webhook da Kapso (o mesmo número da recuperação).
    $urlKapso = "http://127.0.0.1:$portaSite/matricula-cursos-presenciais/api/kapso-webhook.php";
    $idWamid = 'wamid.mp-' . (array_search($wa($p1['telefone'])[0], falso('kapso'), true) + 1);
    $entregue = (string) json_encode(['message' => ['id' => $idWamid, 'kapso' => ['direction' => 'outbound', 'status' => 'delivered']], 'phone_number_id' => '1112223334']);
    [$st] = http($urlKapso, 'POST', $entregue, ['Content-Type: application/json', 'X-Webhook-Event: whatsapp.message.delivered',
        'X-Webhook-Signature: ' . hash_hmac('sha256', $entregue, mcp_segredo('kapso_webhook'))]);
    $stmt = $db->prepare('SELECT entrega FROM mcp_matricula_pendente WHERE provedor_id = ?');
    $stmt->execute([$idWamid]);
    verificar('webhook da Kapso: a entrega da mensagem da matrícula fica gravada na linha dela', [$st, $stmt->fetchColumn()], [200, 'entregue']);

    // ------------------------------------------------------------------------- pagamento da matrícula de ponta a ponta (HTTP)
    $site = "http://127.0.0.1:$portaSite/matricula-cursos-presenciais/api";
    $tokP1 = mcp_rw_token('m' . $p1['id'], time());
    [$st, $cab] = http("$site/ir.php/w/puncao-venosa/mat1/$tokP1");
    verificar('link curto mat1: 302 para a tela de pagar a matrícula (UTMs, sem o token na URL) e o token num cookie HttpOnly da api/', [$st, $cab['location'] ?? '',
        str_starts_with((string) ($cab['set-cookie'] ?? ''), MCP_RW_COOKIE . '=' . $tokP1 . ';'), stripos((string) ($cab['set-cookie'] ?? ''), 'HttpOnly') !== false],
        [302, '/matricula-cursos-presenciais/pagar-matricula/?curso=puncao-venosa&utm_source=whatsapp&utm_medium=recuperacao&utm_campaign=mat1', true, true]);
    $cookieP1 = 'Cookie: ' . MCP_RW_COOKIE . '=' . $tokP1;
    $json = ['Content-Type: application/json', $cookieP1];
    [$st, $cab, $corpo] = http("$site/matricula.php", 'GET', null, [$cookieP1]);
    $d = json_decode($corpo, true) ?: [];
    verificar('situação: primeiro nome, curso, turma, valor e "pode pagar"; nunca o CPF, o telefone nem o e-mail inteiro; no-store', [$st, $d['nome'] ?? '', $d['curso']['slug'] ?? '',
        $d['turma']['rotulo'] ?? '', $d['turma']['hora'] ?? '', $d['valor_centavos'] ?? 0, $d['pode_pagar'] ?? null, $campo($d, 'pagamento'),
        str_contains($corpo, $p1['cpf']) || str_contains($corpo, $p1['telefone']) || str_contains($corpo, $p1['email']), $cab['cache-control'] ?? ''],
        [200, 'Paula', 'puncao-venosa', $dia($perto), '09h00', $valorPv, true, null, false, 'no-store']);
    $invalidos = [];
    foreach (['', mcp_rw_token('s' . $p1['id'], time()), str_replace('m' . $p1['id'] . '.', 'm' . $p2['id'] . '.', $tokP1), mcp_rw_token('m' . $p1['id'], time() - 31 * 86400), 'lixo'] as $t) {
        $invalidos[] = http("$site/matricula.php", 'GET', null, $t !== '' ? ['Cookie: ' . MCP_RW_COOKIE . '=' . $t] : [])[0];
    }
    verificar('links que não valem (sem token, token do carrinho, adulterado, vencido, lixo): 404', $invalidos, [404, 404, 404, 404, 404]);
    [$st] = http("$site/matricula.php", 'POST', '{"metodo":"pix"}', array_merge($json, ['Origin: https://golpe.exemplo.com']));
    verificar('cobrança vinda de outro site: 403', $st, 403);
    [$st, , $corpo] = http("$site/matricula.php", 'POST', (string) json_encode(['metodo' => 'pix', 'origem' => ['utm_source' => 'whatsapp', 'utm_campaign' => 'mat1']]), $json);
    $pix = json_decode($corpo, true) ?: [];
    $cobranca = array_slice(falso('unico'), -1)[0] ?? [];
    verificar('PIX: 201 com o código; a cobrança é SÓ a matrícula, com os dados do pagador da própria inscrição e o postback de sempre', [$st, $pix['pagamento']['status'] ?? '',
        $pix['pagamento']['metodo'] ?? '', str_starts_with((string) ($pix['pagamento']['pix']['copia_cola'] ?? ''), '00020126pix-matricula-'),
        [$cobranca['amount'] ?? 0, $cobranca['payment_method'] ?? '', $cobranca['installments'] ?? 0, $cobranca['customer'] ?? [], count($cobranca['cart'] ?? []), $cobranca['cart'][0]['title'] ?? '',
            str_ends_with((string) ($cobranca['postback_url'] ?? ''), '/api/webhook.php'), $cobranca['metadata']['tipo'] ?? '']],
        [201, 'pendente', 'pix', true, [$valorPv, 'pix', 1, ['name' => $p1['nome'], 'email' => $p1['email'], 'phone_number' => $p1['telefone'], 'document' => $p1['cpf']], 1,
            'Punção Venosa — Matrícula', true, 'matricula']]);
    [$st, , $corpo] = http("$site/matricula.php", 'POST', '{"metodo":"pix"}', $json);
    verificar('segundo pedido de PIX: o mesmo código (reaproveitado), sem cobrança nova', [$st, json_decode($corpo, true)['pagamento']['token'] ?? '', count(falso('unico'))],
        [200, $pix['pagamento']['token'] ?? '-', 1]);
    [, , $corpo] = http("$site/matricula.php", 'GET', null, [$cookieP1]);
    verificar('a tela recarregada mostra o mesmo PIX aberto', json_decode($corpo, true)['pagamento']['token'] ?? '', $pix['pagamento']['token'] ?? '-');
    [, , $corpo] = http("$site/matricula.php?c=" . ($pix['pagamento']['token'] ?? ''));
    verificar('acompanhar: pendente enquanto a Unicopag não confirma', json_decode($corpo, true)['pagamento']['status'] ?? '', 'pendente');
    $pgP1 = mcp_mp_pagamento_por('token', (string) ($pix['pagamento']['token'] ?? ''));
    controle('unico', [$pgP1['unicopag_hash'] => 'paid']);
    $antesEmails = count(falso('emails'));
    [$st, , $corpo] = http("$site/webhook.php", 'POST', (string) json_encode(['hash' => $pgP1['unicopag_hash'], 'payment_status' => 'paid']), ['Content-Type: application/json']);
    verificar('postback da Unicopag: o hash da matrícula é achado, reconsultado e fica pago', [$st, json_decode($corpo, true)['status'] ?? ''], [200, 'pago']);
    $novos = array_slice(falso('emails'), $antesEmails);
    $paraAluno = array_values(array_filter($novos, static fn(array $x): bool => ($x['to'][0] ?? '') === $p1['email']))[0] ?? [];
    $paraSecretaria = array_values(array_filter($novos, static fn(array $x): bool => ($x['to'][0] ?? '') === 'secretaria-teste@exemplo.org'))[0] ?? [];
    verificar('pós-pagamento: o comprovante ao aluno e o aviso à secretaria para marcar a matrícula como paga na escola (uma vez só)', [$paraAluno['subject'] ?? '',
        str_contains((string) ($paraAluno['html'] ?? ''), mcp_brl($valorPv)), $paraSecretaria['subject'] ?? '', str_contains((string) ($paraSecretaria['html'] ?? ''), 'gateway unicopag-2'),
        count($novos)], ['Matrícula paga: Punção Venosa', true, 'Matrícula paga pelo site: ' . $p1['nome'] . ' — Punção Venosa', true, 2]);
    http("$site/webhook.php", 'POST', (string) json_encode(['hash' => $pgP1['unicopag_hash']]), ['Content-Type: application/json']);
    [, , $corpo] = http("$site/matricula.php?c=" . ($pix['pagamento']['token'] ?? ''));
    $dPago = json_decode($corpo, true) ?: [];
    verificar('postback repetido não manda de novo; acompanhar diz pago, e a situação diz "já paga"', [count(falso('emails')) - $antesEmails, $dPago['pagamento']['status'] ?? '', $dPago['motivo'] ?? ''],
        [2, 'pago', 'paga']);
    [$st, , $corpo] = http("$site/matricula.php", 'POST', '{"metodo":"pix"}', $json);
    verificar('pagar de novo: 422 "já está paga", sem cobrança nova', [$st, json_decode($corpo, true)['motivo'] ?? '', count(falso('unico'))], [422, 'paga', 1]);
    // Cartão: aprovado na hora (o número nunca fica no banco) e recusado.
    $cookieP13 = 'Cookie: ' . MCP_RW_COOKIE . '=' . mcp_rw_token('m' . $p13['id'], time());
    [$st, , $corpo] = http("$site/matricula.php", 'POST', (string) json_encode(['metodo' => 'cartao', 'cartao' => ['numero' => '4111111111111111', 'nome' => 'ALUNA TESTE', 'validade' => '12/' . substr((string) ((int) gmdate('Y') + 2), 2), 'cvv' => '123']]),
        ['Content-Type: application/json', $cookieP13]);
    $dCartao = json_decode($corpo, true) ?: [];
    $claro = $db->prepare("SELECT COUNT(*) FROM mcp_matricula_pagamentos WHERE inscricao_id = ? AND (pix_copia_cola LIKE '%4111111111111111%' OR ultimos4 <> '1111')");
    $claro->execute([$p13['id']]);
    $eventosCartao = $db->prepare("SELECT COUNT(*) FROM mcp_eventos WHERE detalhe LIKE '%4111111111111111%'");
    $eventosCartao->execute();
    verificar('cartão aprovado: 201 pago, à vista, bandeira e final; o número nunca no banco', [$st, $dCartao['pagamento']['status'] ?? '', $dCartao['pagamento']['cartao'] ?? [],
        (array_slice(falso('unico'), -1)[0]['installments'] ?? 0), (int) $claro->fetchColumn(), (int) $eventosCartao->fetchColumn()],
        [201, 'pago', ['bandeira' => 'visa', 'ultimos4' => '1111'], 1, 0, 0]);
    $cookieP2 = 'Cookie: ' . MCP_RW_COOKIE . '=' . mcp_rw_token('m' . $p2['id'], time());
    [$st, , $corpo] = http("$site/matricula.php", 'POST', (string) json_encode(['metodo' => 'cartao', 'cartao' => ['numero' => '4000000000000002', 'nome' => 'ALUNA TESTE', 'validade' => '12/' . substr((string) ((int) gmdate('Y') + 2), 2), 'cvv' => '123']]),
        ['Content-Type: application/json', $cookieP2]);
    verificar('cartão recusado: 402 com a mensagem, e a matrícula continua em aberto', [$st, json_decode($corpo, true)['ok'] ?? null, mcp_mp_situacao(mcp_inscricao_por('id', (string) $p2['id']), time())['pode']],
        [402, false, true]);
    [$st, , $corpo] = http("$site/matricula.php", 'GET', null, ['Cookie: ' . MCP_RW_COOKIE . '=' . mcp_rw_token('m' . $p8['id'], time())]);
    verificar('a compra que já incluía a matrícula: a situação diz "completa" e não deixa pagar', [$st, json_decode($corpo, true)['pode_pagar'] ?? null, json_decode($corpo, true)['motivo'] ?? ''],
        [200, false, 'completa']);

    // Os pagamentos acima foram feitos agora (hora de verdade), e as mensagens têm a data do teste (400 dias à frente): a
    // linha do tempo é acertada para as métricas ("pagou depois da 1ª mensagem").
    $db->prepare("UPDATE mcp_matricula_pagamentos SET pago_em = ? WHERE inscricao_id IN (?, ?) AND status = 'pago'")->execute([$quando($A + 2 * 3600), $p1['id'], $p13['id']]);

    // ------------------------------------------------------------------------- descadastro e "já pagou na escola"
    $linhaE9 = $db->prepare("SELECT id FROM mcp_matricula_pendente WHERE inscricao_id = ? AND canal = 'email' AND etapa = 1");
    $linhaE9->execute([$p9['id']]);
    $idE9 = (int) $linhaE9->fetchColumn();
    [$stGet, , $htmlGet] = http(mcp_mp_descadastro_url($idE9));
    [$stPost, , $txt] = http(mcp_mp_descadastro_url($idE9), 'POST', 'List-Unsubscribe=One-Click', ['Content-Type: application/x-www-form-urlencoded']);
    [$stRuim] = http(mcp_site_url() . '/matricula-cursos-presenciais/api/avisos.php?mp=' . $idE9 . '.0000000000000000', 'POST', 'List-Unsubscribe=One-Click', ['Content-Type: application/x-www-form-urlencoded']);
    verificar('descadastro: o GET só pergunta; o POST de um clique para os lembretes da matrícula; assinatura errada 404; os avisos das aulas não são bloqueados', [$stGet,
        str_contains($htmlGet, 'Parar os lembretes da matrícula'), $stPost, str_contains($txt, 'lembretes da matrícula'), $stRuim, mcp_avisos_bloqueado('email', $p9['email'])],
        [200, true, 200, true, 404, false]);
    [$st] = http("http://127.0.0.1:$portaSite/matricula-cursos-presenciais/api/recuperacao.php", 'POST', ['acao' => 'mp_pagou_escola', 'id' => $p12['id'], 't' => 'errado'],
        ['Cookie: mcp_painel=' . $_COOKIE[MCP_PAINEL_COOKIE]]);
    [$st2, $cab2] = http("http://127.0.0.1:$portaSite/matricula-cursos-presenciais/api/recuperacao.php", 'POST', ['acao' => 'mp_pagou_escola', 'id' => $p12['id'],
        't' => mcp_painel_csrf($quem, 'mp_pagou', $p12['id'])], ['Cookie: mcp_painel=' . $_COOKIE[MCP_PAINEL_COOKIE]]);
    $db->prepare("UPDATE mcp_matricula_pendente SET criado_em = ? WHERE inscricao_id = ? AND canal = 'painel'")->execute([$quando($A + 3 * 3600), $p12['id']]);
    verificar('painel: "Já pagou na escola" sem o token → 403; com o token → marcado (303)', [$st, $st2, $cab2['location'] ?? '', in_array('painel0:pago_escola', $linhasMp($p12['id']), true)],
        [403, 303, 'recuperacao.php?mp=ok#matricula', true]);

    // A véspera da turma perto (amanhã, 10h): só quem continua com a matrícula em aberto recebe a 2ª.
    $antes = [count(falso('kapso')), count(falso('emails'))];
    $r = mcp_mp_rodar($A + 86400);
    verificar('véspera: pagou entre as mensagens (PIX e cartão), marcada no painel, descadastrada e paga na escola não recebem a 2ª; o bloqueado recebe o 2º e-mail',
        [$waNomes($p1['telefone']), $waNomes($p13['telefone']), $waNomes($p12['telefone']), $waNomes($p9['telefone']), count($emails($p9['email'])), $waNomes($p4['telefone']),
            array_map(static fn(array $x): string => (string) $x['subject'], $emails($p5['email']))],
        [['mat_pendente_1'], ['mat_pendente_1'], ['mat_pendente_1'], ['mat_pendente_1'], 1, ['mat_pendente_1'],
            ['Sua vaga em Punção Venosa está garantida: falta a matrícula para entrar na aula', 'Lembrete: sua turma de Punção Venosa começa amanhã, ' . $dia($perto) . ', às 9h e a matrícula está em aberto']]);
    verificar('véspera: nada mais saiu além do e-mail do bloqueado', [count(falso('kapso')) - $antes[0], count(falso('emails')) - $antes[1]], [0, 1]);
    $r = mcp_mp_rodar($perto - 1800);
    verificar('turma começando (menos de 1 h): ninguém dessa turma recebe nada (só a turma longe continua em aberto)', [$r['whatsapp']['enviados'], $r['email']['enviados'], $r['vistos']], [0, 0, 1]);
    // A turma longe: a 2ª 48 h antes, sem "amanhã".
    mcp_mp_rodar($longe - 48 * 3600 + 3600);
    $msg2 = $wa($p2['telefone'])[1]['corpo']['template'] ?? [];
    verificar('turma longe: a 2ª 48 h antes, com a data e a hora, sem "amanhã"', [$waNomes($p2['telefone']), $msg2['components'][0]['parameters'][2]['text'] ?? ''],
        [['mat_pendente_1', 'mat_pendente_2'], $dia($longe) . ', às 9h']);

    // ------------------------------------------------------------------------- teto em 24 h, modelos e família
    $T3 = $A + 20 * 86400;
    $p15 = $taxaPaga('teto', $T3 - 3 * 3600, 'mp-longe2');
    $ins = $db->prepare("INSERT INTO mcp_recuperacao_whatsapp (pessoa_chave, origem, etapa, modelo, curso_nome, telefone_hash, status, criado_em, atualizado_em) VALUES (?, 'site', 1, 'rec_inscricao_1_v2', 'x', ?, 'enviado', ?, ?)");
    for ($i = 0; $i < MCP_RW_POR_DIA; $i++) {
        $ins->execute([hash('sha256', "teto-mp-$i-$sufixo"), hash('sha256', "tel-mp-$i-$sufixo"), $quando($T3 - 3600), $quando($T3 - 3600)]);
    }
    $r = mcp_mp_rodar($T3);
    verificar('teto de 24 h somado ao da recuperação: o WhatsApp espera ("T"), o e-mail sai', [$r['whatsapp']['enviados'], $r['pessoas']['w' . $p15['id']] ?? null, count($emails($p15['email']))],
        [0, 'T', 1]);
    $db->prepare('DELETE FROM mcp_recuperacao_whatsapp WHERE curso_nome = ? AND criado_em = ?')->execute(['x', $quando($T3 - 3600)]);
    controle('modelos', ['extras' => [['name' => 'mat_pendente_1_v2', 'status' => 'APPROVED', 'language' => 'pt_BR', 'category' => 'UTILITY']]]);
    $db->exec("DELETE FROM mcp_chaves WHERE nome = 'rw_cache_modelos'");
    mcp_mp_rodar($T3 + 3600);
    verificar('família: a mat_pendente_1_v2 aprovada como utilidade sai no lugar da de hoje, e fica gravada', [$waNomes($p15['telefone']), $linhasMp($p15['id'])],
        [['mat_pendente_1_v2'], ['email1:enviado:mat1', 'whatsapp1:enviado:mat_pendente_1_v2']]);
    $T4 = $T3 + 86400;
    controle('modelos', ['pendente' => ['mat_pendente_1']]);
    $db->exec("DELETE FROM mcp_chaves WHERE nome = 'rw_cache_modelos'");
    $p16 = $taxaPaga('modelo', $T4 - 3 * 3600, 'mp-longe2');
    $r = mcp_mp_rodar($T4);
    verificar('modelo sem aprovação: o WhatsApp espera ("m", sem gastar a etapa) e diz qual; o e-mail sai', [$waNomes($p16['telefone']), $r['pessoas']['w' . $p16['id']] ?? null,
        str_contains((string) $r['whatsapp']['motivo'], 'mat_pendente_1'), count($emails($p16['email']))], [[], 'm', true, 1]);
    controle('modelos', []);
    $db->exec("DELETE FROM mcp_chaves WHERE nome = 'rw_cache_modelos'");
    mcp_mp_rodar($T4 + 3600);
    verificar('modelo aprovado: o WhatsApp sai na rodada seguinte', $waNomes($p16['telefone']), ['mat_pendente_1']);

    // ------------------------------------------------------------------------- recuperação (B): o PIX conferido antes da 1ª (30 min)
    $T5 = $A + 30 * 86400;
    $cria = static function (string $chave, int $ts, array $campos) use ($db, $sufixo, $celular, $quando): array {
        $l = $campos + ['token' => mcp_token_novo(), 'cpf' => cpf_de_teste(), 'telefone' => $celular(), 'metodo' => 'pix', 'curso_slug' => 'puncao-venosa', 'curso_nome' => 'Punção Venosa',
            'nome' => 'Lead Teste ' . ucfirst($chave), 'email' => "mp-$chave-$sufixo@exemplo.org", 'inscricao_centavos' => 9900, 'taxa_centavos' => 0, 'total_centavos' => 9900,
            'status' => 'pendente', 'escola_status' => 'nao_aplicavel', 'pix_copia_cola' => '00020126pixlead', 'criado_em' => $quando($ts), 'atualizado_em' => $quando($ts)];
        $db->prepare('INSERT INTO mcp_inscricoes (' . implode(', ', array_keys($l)) . ') VALUES (' . implode(', ', array_fill(0, count($l), '?')) . ')')->execute(array_values($l));
        return $l + ['id' => (int) $db->lastInsertId()];
    };
    $pagoSemAviso = $cria('pixpago', $T5 - 40 * 60, ['unicopag_hash' => "lead-pago-$sufixo"]);
    $aberto = $cria('pixaberto', $T5 - 40 * 60, ['unicopag_hash' => "lead-aberto-$sufixo"]);
    controle('unico', ["lead-pago-$sufixo" => 'paid']);
    controle('escola', ['usuarios' => []]);
    mcp_rw_rodar($T5);
    verificar('recuperação: PIX pago na Unicopag sem o aviso ter chegado → consultado antes da 1ª, não recebe e fica pago; o PIX aberto recebe a 1ª com 30 min',
        [$waNomes($pagoSemAviso['telefone']), mcp_inscricao_por('id', (string) $pagoSemAviso['id'])['status'] ?? '', $waNomes($aberto['telefone']),
            in_array("lead-pago-$sufixo", array_column(falso('unico_consultas'), 'hash'), true)],
        [[], 'pago', ['rec_inscricao_1_v2'], true]);

    // ------------------------------------------------------------------------- painel
    $url = "http://127.0.0.1:$portaSite/matricula-cursos-presenciais/api/recuperacao.php";
    [$st, , $html] = http($url, 'GET', null, ['Cookie: mcp_painel=' . $_COOKIE[MCP_PAINEL_COOKIE]]);
    $mp = mcp_mp_metricas(mcp_mp_carregar(time()), time(), mcp_mp_pelo_link(time() - 400 * 86400));
    verificar('painel: a seção "Matrícula pendente" com os números, a lista e o botão "Já pagou na escola"', [$st, str_contains($html, 'id="matricula"'), str_contains($html, 'Pagaram depois'),
        str_contains($html, 'Já pagou na escola</button>'), str_contains($html, 'Aluna Teste Longe'), str_contains($html, 'mat_pendente_1 (matrícula pendente, 1ª)'),
        (bool) preg_match('/\b55?21\d{9}\b/', strip_tags($html))], [200, true, true, true, true, true, false]);
    verificar('métricas: em aberto, enviadas por canal e etapa, pagaram depois da 1ª (site e painel) e pelo link (whatsapp mat1)', [$mp['pendentes'] >= 3,
        $mp['enviadas']['whatsapp'][1] >= 7, $mp['enviadas']['whatsapp'][2] >= 2, $mp['depois_como']['site'], $mp['depois_como']['painel'], $mp['pelo_link']['whatsapp']['mat1']],
        [true, true, true, 2, 1, 1]);

    // ------------------------------------------------------------------------- rotina sem configuração
    $saida = [];
    exec('MCP_CONFIG_ARQUIVO=' . escapeshellarg($configSem) . ' MCP_CONFIG_WHATSAPP_ARQUIVO=' . escapeshellarg($vazio) . ' MCP_CONFIG_ESCOLA_ARQUIVO=' . escapeshellarg($vazio)
        . ' MCP_OFERTA_ARQUIVO=' . escapeshellarg($ofertaTeste) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($raiz . '/site/matricula-cursos-presenciais/api/lembretes.php') . ' 2>/dev/null', $saida);
    $linha = array_values(array_filter($saida, static fn(string $l): bool => str_contains($l, 'matrícula pendente:')))[0] ?? '';
    verificar('lembretes.php: a rodada da matrícula pendente roda antes da recuperação e diz o que falta (sem Kapso nem Resend, ou fora do horário)',
        [$linha !== '', strpos(implode("\n", $saida), 'matrícula pendente:') < strpos(implode("\n", $saida), 'recuperação por WhatsApp')], [true, true]);

    $erros = trim((string) file_get_contents($logErros));
    verificar('servidor sem erro de PHP no log', $erros, '');
} finally {
    foreach ([$servidor, $servidorFalso] as $s) {
        if (is_resource($s)) {
            proc_terminate($s);
        }
    }
    limpar($db, $padraoEmail, $telefones, $inicioTeste);
    foreach ([$config, $configSem, $vazio, $ofertaTeste] as $arq) {
        @unlink($arq);
    }
    array_map('unlink', glob("$falso/*") ?: []);
    @rmdir($falso);
}
echo "\n$total verificações, $falhas falha(s).\n";
exit($falhas > 0 ? 1 : 0);
