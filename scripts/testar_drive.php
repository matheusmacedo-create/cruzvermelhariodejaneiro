#!/usr/bin/env php
<?php
/**
 * Teste da ligação com o Google Drive (api/lib/google_drive.php, api/lib/drive_espelho.php, api/drive.php) contra
 * um MariaDB LOCAL e um "Google" falso: um servidor embutido do PHP que responde a /token, /drive/v3/files,
 * /upload/drive/v3/files (multipart e retomável), /v4/spreadsheets (append, values.get, propriedades, batchUpdate) e
 * guarda tudo o que recebeu num JSON que o teste confere. Nenhuma chamada sai para o Google de verdade. Cobre:
 *   - token: JWT RS256 assinado com uma chave gerada aqui e conferido no servidor falso com a chave pública;
 *     cache em arquivo 0600, renovação 5 minutos antes de vencer;
 *   - pastas aninhadas com cache em mcp_chaves (a segunda chamada não bate na API, nem em outro processo);
 *   - envio multipart (boundary, parte JSON + conteúdo), troca de conteúdo (PATCH) e envio retomável em blocos;
 *   - linha na planilha; remoção de linha pelo protocolo (values.get + batchUpdate) e apagar arquivo;
 *   - fila: enfileirar, processar com sucesso, falha com nova tentativa e proxima_em futura, falha definitiva após 5;
 *   - ficha de voluntário (PDF + planilha + ids na ficha), exportação diária sem CPF, cópia do banco em JSON.gz;
 *   - espelho de assets numa pasta temporária (manifesto, segunda rodada não reenvia, alterado reenvia, limites);
 *   - api/drive.php em subprocesso: rodada normal, --testar e Drive desligado.
 *
 * Precisa de MCP_CONFIG_ARQUIVO com o banco local (DB_HOST 127.0.0.1 ou localhost). Tudo o que o teste cria é apagado
 * no fim. Nenhum e-mail sai. Os arquivos temporários (chaves, token, servidor falso) vão para MCP_TESTE_DIR ou para a
 * pasta temporária do sistema.
 *
 * Uso: MCP_CONFIG_ARQUIVO=/caminho/config-teste.php [MCP_TESTE_DIR=/caminho] php scripts/testar_drive.php
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$api = $raiz . '/site/matricula-cursos-presenciais/api';
$configOriginal = (string) getenv('MCP_CONFIG_ARQUIVO');
if ($configOriginal === '' || !is_file($configOriginal)) {
    fwrite(STDERR, "Falta MCP_CONFIG_ARQUIVO com o banco local (veja o cabeçalho deste arquivo).\n");
    exit(2);
}
$tmp = rtrim((string) (getenv('MCP_TESTE_DIR') ?: sys_get_temp_dir()), '/') . '/mcp-drive-' . bin2hex(random_bytes(3));
if (!mkdir($tmp, 0700, true)) {
    fwrite(STDERR, "Não consegui criar $tmp.\n");
    exit(2);
}
$porta = 18900 + random_int(0, 99);
$base = "http://127.0.0.1:$porta";

// Chave RSA só deste teste: a privada vai na "conta de serviço", a pública para o servidor falso conferir o JWT.
$par = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($par === false || !openssl_pkey_export($par, $privada)) {
    fwrite(STDERR, "openssl não gerou a chave RSA.\n");
    exit(2);
}
file_put_contents("$tmp/publica.pem", (string) openssl_pkey_get_details($par)['key']);

$config = "$tmp/config.php";
file_put_contents($config, '<?php return [\'SITE_URL\' => \'http://127.0.0.1:' . $porta . '\', \'EMAIL_SECRETARIA\' => \'\'] + (require ' . var_export($configOriginal, true) . ');');
$configGoogle = "$tmp/config-google.php";
file_put_contents($configGoogle, '<?php return ' . var_export([
    'GOOGLE_SA' => ['type' => 'service_account', 'project_id' => 'teste', 'client_email' => 'site-drive@teste.iam.gserviceaccount.com',
        'private_key' => $privada, 'token_uri' => "$base/token"],
    'GOOGLE_DRIVE_RAIZ_ID' => 'raiz',
    'GOOGLE_SHEET_FICHAS_ID' => 'planilha-teste',
    'GOOGLE_API_URL' => $base,
    'GOOGLE_TOKEN_URL' => "$base/token",
], true) . ';');
$vazio = "$tmp/vazio.php";
file_put_contents($vazio, '<?php return [];');
$tokenArquivo = "$tmp/google-token.json";
$ambiente = [
    'MCP_CONFIG_ARQUIVO' => $config, 'MCP_CONFIG_GOOGLE_ARQUIVO' => $configGoogle, 'MCP_CONFIG_ESCOLA_ARQUIVO' => $vazio,
    'MCP_CONFIG_WHATSAPP_ARQUIVO' => $vazio, 'MCP_CONFIG_META_ARQUIVO' => $vazio, 'MCP_CONFIG_PAGAR_TUDO_ARQUIVO' => $vazio,
    'MCP_GOOGLE_TOKEN_ARQUIVO' => $tokenArquivo, 'MCP_CATALOGO_ARQUIVO' => $raiz . '/site/matricula-cursos-presenciais/cursos.json',
    // Limiar do multipart em 64 KiB e bloco de 256 KiB: um arquivo de 600 KB sobe retomável, em 3 blocos.
    'MCP_GOOGLE_MULTIPART_MAX' => '65536', 'MCP_GOOGLE_BLOCO' => '262144', 'PATH' => (string) getenv('PATH'),
];
foreach ($ambiente as $nome => $valor) {
    putenv("$nome=$valor");
}
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $api . '/lib.php';
restore_exception_handler();
if (!function_exists('mcp_google_token')) {
    require_once $api . '/lib/google_drive.php';
}
if (!function_exists('mcp_drive_ligado')) {
    require_once $api . '/lib/drive_espelho.php';
}

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

/** Valor da chave, null incluído; 'ausente' só quando a chave não existe. */
function campo(mixed $lista, string $chave): mixed
{
    return is_array($lista) && array_key_exists($chave, $lista) ? $lista[$chave] : 'ausente';
}

// ----------------------------------------------------------------------------- o Google falso
$estadoArquivo = "$tmp/estado.json";
file_put_contents($estadoArquivo, '{}');
$roteador = "$tmp/google-falso.php";
file_put_contents($roteador, <<<'PHP'
<?php
/**
 * Google falso para scripts/testar_drive.php: /token (confere o JWT com a chave pública), Drive v3 (listar, criar
 * pasta, metadados, apagar, upload multipart/media/retomável) e Sheets v4 (append, values.get, propriedades,
 * batchUpdate). Tudo o que recebe fica em FALSO_ESTADO (JSON). /__controle {"falhar": N} faz as N próximas chamadas
 * autenticadas responderem 500.
 */
declare(strict_types=1);
const PASTA = 'application/vnd.google-apps.folder';
$estadoArquivo = (string) getenv('FALSO_ESTADO');
$base = (string) getenv('FALSO_BASE');
$tmp = (string) getenv('FALSO_TMP');
$estado = json_decode((string) file_get_contents($estadoArquivo), true);
$estado = (is_array($estado) ? $estado : []) + ['tokens' => [], 'jwts' => [], 'arquivos' => [], 'planilhas' => [], 'appends' => [],
    'batch_updates' => [], 'requisicoes' => [], 'sessoes' => [], 'falhar' => 0, 'seq' => 0];
$metodo = (string) $_SERVER['REQUEST_METHOD'];
$caminho = rawurldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));
parse_str((string) (parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?? ''), $query);
$corpo = (string) file_get_contents('php://input');
$cabecalhos = array_change_key_case(getallheaders(), CASE_LOWER);

function responder(int $status, mixed $json = null, array $extras = []): never
{
    global $estado, $estadoArquivo;
    file_put_contents($estadoArquivo, json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    http_response_code($status);
    foreach ($extras as $linha) {
        header($linha);
    }
    if ($json !== null) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($json, JSON_UNESCAPED_UNICODE);
    }
    exit;
}
function erro(int $status, string $mensagem): never
{
    responder($status, ['error' => ['code' => $status, 'message' => $mensagem]]);
}
function novoId(string $prefixo): string
{
    global $estado;
    $estado['seq']++;
    return $prefixo . '-' . $estado['seq'];
}
/** O registro de um arquivo: conteúdo guardado só até 128 KB (md5 e tamanho sempre). */
function registro(string $nome, string $mime, array $pais, string $conteudo, string $modo, array $extras = []): array
{
    return ['name' => $nome, 'mimeType' => $mime, 'parents' => $pais, 'tamanho' => strlen($conteudo), 'md5' => md5($conteudo),
        'conteudo' => strlen($conteudo) <= 131072 ? base64_encode($conteudo) : null, 'modo' => $modo, 'versao' => 1] + $extras;
}
function b64url(string $texto): string
{
    return (string) base64_decode(strtr($texto, '-_', '+/'));
}

$estado['requisicoes'][] = ['metodo' => $metodo, 'caminho' => $caminho, 'query' => $query, 'tamanho' => strlen($corpo),
    'content_type' => $cabecalhos['content-type'] ?? null, 'content_range' => $cabecalhos['content-range'] ?? null];

if ($caminho === '/__controle') {
    $comando = json_decode($corpo, true) ?: [];
    if (isset($comando['falhar'])) {
        $estado['falhar'] = (int) $comando['falhar'];
    }
    responder(200, ['ok' => true]);
}

if ($caminho === '/token') {
    parse_str($corpo, $form);
    $motivos = [];
    if (($form['grant_type'] ?? '') !== 'urn:ietf:params:oauth:grant-type:jwt-bearer') {
        $motivos[] = 'grant_type';
    }
    $partes = explode('.', (string) ($form['assertion'] ?? ''));
    $cabecalhoJwt = count($partes) === 3 ? json_decode(b64url($partes[0]), true) : null;
    $claims = count($partes) === 3 ? json_decode(b64url($partes[1]), true) : null;
    $assinaturaOk = count($partes) === 3 && openssl_verify($partes[0] . '.' . $partes[1], b64url($partes[2]),
        (string) file_get_contents((string) getenv('FALSO_PUBLICA')), OPENSSL_ALGO_SHA256) === 1;
    if (!$assinaturaOk) {
        $motivos[] = 'assinatura';
    }
    if (($cabecalhoJwt['alg'] ?? '') !== 'RS256' || ($cabecalhoJwt['typ'] ?? '') !== 'JWT') {
        $motivos[] = 'cabecalho';
    }
    if (!is_array($claims)) {
        $motivos[] = 'claims';
    } else {
        if (($claims['aud'] ?? '') !== $base . '/token') {
            $motivos[] = 'aud';
        }
        if (($claims['iss'] ?? '') === '') {
            $motivos[] = 'iss';
        }
        $escopos = explode(' ', (string) ($claims['scope'] ?? ''));
        if (!in_array('https://www.googleapis.com/auth/drive', $escopos, true) || !in_array('https://www.googleapis.com/auth/spreadsheets', $escopos, true)) {
            $motivos[] = 'scope';
        }
        if (!isset($claims['iat'], $claims['exp']) || $claims['exp'] - $claims['iat'] > 3600 || $claims['exp'] <= time() || $claims['iat'] > time() + 60) {
            $motivos[] = 'exp';
        }
    }
    $estado['jwts'][] = ['ok' => $motivos === [], 'motivos' => $motivos, 'claims' => $claims, 'cabecalho' => $cabecalhoJwt];
    if ($motivos !== []) {
        responder(400, ['error' => 'invalid_grant', 'error_description' => implode(', ', $motivos)]);
    }
    $token = 'tok-' . bin2hex(random_bytes(8));
    $estado['tokens'][] = $token;
    responder(200, ['access_token' => $token, 'expires_in' => 3600, 'token_type' => 'Bearer']);
}

// Daqui em diante, só com token válido.
if (!preg_match('/^Bearer (\S+)$/', (string) ($cabecalhos['authorization'] ?? ''), $m) || !in_array($m[1], $estado['tokens'], true)) {
    erro(401, 'Invalid Credentials');
}
if ($estado['falhar'] > 0) {
    $estado['falhar']--;
    erro(500, 'Falha simulada pelo teste');
}
if ((str_starts_with($caminho, '/drive/') || str_starts_with($caminho, '/upload/drive/')) && ($query['supportsAllDrives'] ?? '') !== 'true') {
    erro(400, 'sem supportsAllDrives=true');
}

// ---- Drive: listagem
if ($metodo === 'GET' && $caminho === '/drive/v3/files') {
    if (($query['includeItemsFromAllDrives'] ?? '') !== 'true') {
        erro(400, 'sem includeItemsFromAllDrives=true');
    }
    $q = (string) ($query['q'] ?? '');
    $filtroNome = preg_match("/name = '((?:[^'\\\\]|\\\\.)*)'/", $q, $m) ? stripslashes($m[1]) : null;
    $filtroPai = preg_match("/'((?:[^'\\\\]|\\\\.)+)' in parents/", $q, $m) ? stripslashes($m[1]) : null;
    $filtroMime = preg_match("/mimeType = '([^']+)'/", $q, $m) ? $m[1] : null;
    $lista = [];
    foreach ($estado['arquivos'] as $id => $a) {
        if (!empty($a['apagado']) || ($filtroNome !== null && $a['name'] !== $filtroNome) || ($filtroPai !== null && !in_array($filtroPai, $a['parents'], true))
            || ($filtroMime !== null && $a['mimeType'] !== $filtroMime)) {
            continue;
        }
        $lista[] = ['id' => $id, 'name' => $a['name'], 'mimeType' => $a['mimeType']];
    }
    responder(200, ['files' => $lista]);
}
// ---- Drive: metadados e apagar
if (preg_match('#^/drive/v3/files/([^/]+)$#', $caminho, $m)) {
    $id = $m[1];
    if ($id === 'raiz') {
        $item = ['id' => 'raiz', 'name' => 'Cruz Vermelha RJ – Site (teste)', 'mimeType' => PASTA, 'driveId' => 'drive-compartilhado'];
    } elseif (isset($estado['arquivos'][$id]) && empty($estado['arquivos'][$id]['apagado'])) {
        $item = ['id' => $id, 'name' => $estado['arquivos'][$id]['name'], 'mimeType' => $estado['arquivos'][$id]['mimeType']];
    } else {
        erro(404, 'File not found: ' . $id);
    }
    if ($metodo === 'GET') {
        responder(200, $item);
    }
    if ($metodo === 'DELETE') {
        $estado['arquivos'][$id]['apagado'] = true;
        responder(204);
    }
    erro(405, 'método');
}
// ---- Drive: criar pasta (só metadados)
if ($metodo === 'POST' && $caminho === '/drive/v3/files') {
    $meta = json_decode($corpo, true) ?: [];
    foreach ((array) ($meta['parents'] ?? []) as $pai) {
        if ($pai !== 'raiz' && (!isset($estado['arquivos'][$pai]) || !empty($estado['arquivos'][$pai]['apagado']))) {
            erro(404, 'File not found: ' . $pai);
        }
    }
    $id = novoId('pasta');
    $estado['arquivos'][$id] = ['name' => (string) ($meta['name'] ?? ''), 'mimeType' => (string) ($meta['mimeType'] ?? ''), 'parents' => (array) ($meta['parents'] ?? []), 'modo' => 'metadata'];
    responder(200, ['id' => $id]);
}
// ---- Drive: upload
if (str_starts_with($caminho, '/upload/drive/v3/files')) {
    $tipo = (string) ($query['uploadType'] ?? '');
    $idExistente = preg_match('#^/upload/drive/v3/files/([^/]+)$#', $caminho, $m) ? $m[1] : null;
    if ($idExistente !== null && (!isset($estado['arquivos'][$idExistente]) || !empty($estado['arquivos'][$idExistente]['apagado']))) {
        erro(404, 'File not found: ' . $idExistente);
    }
    if ($tipo === 'multipart') {
        if ($metodo !== 'POST' || $idExistente !== null) {
            erro(400, 'multipart só cria (POST sem id)');
        }
        if (!preg_match('#^multipart/related;\s*boundary=("?)([^";\s]+)\1#', (string) ($cabecalhos['content-type'] ?? ''), $m)) {
            erro(400, 'Content-Type sem boundary: ' . ($cabecalhos['content-type'] ?? ''));
        }
        $limite = $m[2];
        $partes = [];
        foreach (explode("\r\n--$limite", "\r\n" . $corpo) as $i => $pedaco) {
            if ($i === 0) {
                continue;
            }
            if (str_starts_with($pedaco, '--')) {
                break;
            }
            [$cab, $conteudoParte] = explode("\r\n\r\n", substr($pedaco, 2), 2) + [1 => ''];
            $partes[] = ['cabecalhos' => $cab, 'conteudo' => $conteudoParte];
        }
        if (count($partes) !== 2) {
            erro(400, 'multipart com ' . count($partes) . ' parte(s)');
        }
        $meta = json_decode($partes[0]['conteudo'], true);
        if (!is_array($meta) || !str_contains(strtolower($partes[0]['cabecalhos']), 'application/json')) {
            erro(400, 'primeira parte não é JSON');
        }
        foreach ((array) ($meta['parents'] ?? []) as $pai) {
            if ($pai !== 'raiz' && (!isset($estado['arquivos'][$pai]) || !empty($estado['arquivos'][$pai]['apagado']))) {
                erro(404, 'File not found: ' . $pai);
            }
        }
        $mime = preg_match('/content-type:\s*(.+)/i', $partes[1]['cabecalhos'], $m) ? trim($m[1]) : '';
        $id = novoId('arq');
        $estado['arquivos'][$id] = registro((string) ($meta['name'] ?? ''), $mime, (array) ($meta['parents'] ?? []), $partes[1]['conteudo'], 'multipart',
            ['boundary' => $limite, 'partes' => 2]);
        responder(200, ['id' => $id, 'name' => $meta['name'] ?? '']);
    }
    if ($tipo === 'media') {
        if ($metodo !== 'PATCH' || $idExistente === null) {
            erro(400, 'media só troca conteúdo (PATCH com id)');
        }
        $antigo = $estado['arquivos'][$idExistente];
        $estado['arquivos'][$idExistente] = registro($antigo['name'], (string) ($cabecalhos['content-type'] ?? ''), $antigo['parents'], $corpo, 'media')
            + ['versao' => ($antigo['versao'] ?? 1) + 1];
        $estado['arquivos'][$idExistente]['versao'] = ($antigo['versao'] ?? 1) + 1;
        responder(200, ['id' => $idExistente]);
    }
    if ($tipo === 'resumable') {
        if (isset($query['upload_id'])) {
            $uploadId = (string) $query['upload_id'];
            $sessao = $estado['sessoes'][$uploadId] ?? null;
            if ($sessao === null || $metodo !== 'PUT') {
                erro(404, 'sessão desconhecida');
            }
            $parcial = "$tmp/sessao-$uploadId.bin";
            $tem = is_file($parcial) ? (int) filesize($parcial) : 0;
            $range = (string) ($cabecalhos['content-range'] ?? '');
            if (preg_match('#^bytes \*/(\d+)$#', $range, $m)) {
                // Consulta de posição: quanto já chegou?
                if ($tem < (int) $m[1]) {
                    responder(308, null, $tem > 0 ? ['Range: bytes=0-' . ($tem - 1)] : []);
                }
            } else {
                if (preg_match('#^bytes (\d+)-(\d+)/(\d+)$#', $range, $m)) {
                    [$inicio, $fim, $totalDeclarado] = [(int) $m[1], (int) $m[2], (int) $m[3]];
                } else {
                    [$inicio, $fim, $totalDeclarado] = [0, strlen($corpo) - 1, strlen($corpo)];
                }
                if ($inicio !== $tem) {
                    erro(400, "bloco fora de ordem: esperava $tem, veio $inicio");
                }
                if (strlen($corpo) !== $fim - $inicio + 1) {
                    erro(400, 'tamanho do bloco não bate com Content-Range');
                }
                file_put_contents($parcial, $corpo, FILE_APPEND);
                $sessao['blocos'][] = [$inicio, $fim];
                $estado['sessoes'][$uploadId] = $sessao;
                if ($fim + 1 < $totalDeclarado) {
                    responder(308, null, ['Range: bytes=0-' . $fim]);
                }
            }
            $conteudo = (string) file_get_contents($parcial);
            unlink($parcial);
            if (strlen($conteudo) !== (int) $sessao['total']) {
                erro(400, 'total recebido não bate com X-Upload-Content-Length');
            }
            $id = $sessao['arquivo_id'] ?? novoId('arq');
            $antigo = $estado['arquivos'][$id] ?? null;
            $estado['arquivos'][$id] = registro((string) ($sessao['meta']['name'] ?? $antigo['name'] ?? ''), (string) $sessao['mime'],
                (array) ($sessao['meta']['parents'] ?? $antigo['parents'] ?? []), $conteudo, 'resumable', ['blocos' => $sessao['blocos']]);
            $estado['arquivos'][$id]['versao'] = ($antigo['versao'] ?? 0) + 1;
            unset($estado['sessoes'][$uploadId]);
            responder(200, ['id' => $id]);
        }
        if (!in_array($metodo, ['POST', 'PATCH'], true) || ($metodo === 'PATCH') !== ($idExistente !== null)) {
            erro(400, 'início do retomável: POST sem id ou PATCH com id');
        }
        $meta = json_decode($corpo, true) ?: [];
        foreach ((array) ($meta['parents'] ?? []) as $pai) {
            if ($pai !== 'raiz' && (!isset($estado['arquivos'][$pai]) || !empty($estado['arquivos'][$pai]['apagado']))) {
                erro(404, 'File not found: ' . $pai);
            }
        }
        $uploadId = 'sessao-' . bin2hex(random_bytes(6));
        $estado['sessoes'][$uploadId] = ['meta' => $meta, 'mime' => (string) ($cabecalhos['x-upload-content-type'] ?? ''),
            'total' => (int) ($cabecalhos['x-upload-content-length'] ?? 0), 'arquivo_id' => $idExistente, 'blocos' => []];
        responder(200, null, ['Location: ' . $base . '/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true&upload_id=' . $uploadId]);
    }
    erro(400, 'uploadType desconhecido: ' . $tipo);
}
// ---- Sheets
const ABAS = ['Fichas' => 777001, 'Outra' => 777002];
if ($metodo === 'GET' && preg_match('#^/v4/spreadsheets/([^/:]+)$#', $caminho, $m)) {
    $folhas = [];
    foreach (ABAS as $titulo => $sheetId) {
        $folhas[] = ['properties' => ['sheetId' => $sheetId, 'title' => $titulo, 'index' => count($folhas)]];
    }
    responder(200, ['spreadsheetId' => $m[1], 'sheets' => $folhas]);
}
if ($metodo === 'POST' && preg_match('#^/v4/spreadsheets/([^/:]+):batchUpdate$#', $caminho, $m)) {
    $planilha = $m[1];
    $pedido = json_decode($corpo, true) ?: [];
    $estado['batch_updates'][] = ['planilha' => $planilha, 'pedido' => $pedido];
    foreach ($pedido['requests'] ?? [] as $req) {
        $intervalo = $req['deleteDimension']['range'] ?? null;
        if ($intervalo === null) {
            erro(400, 'só deleteDimension é simulado');
        }
        $aba = array_search((int) ($intervalo['sheetId'] ?? -1), ABAS, true);
        if ($aba === false || ($intervalo['dimension'] ?? '') !== 'ROWS') {
            erro(400, 'sheetId desconhecido ou dimensão não é ROWS');
        }
        $linhas = $estado['planilhas'][$planilha][$aba] ?? [];
        $inicio = (int) ($intervalo['startIndex'] ?? 0);
        $fim = (int) ($intervalo['endIndex'] ?? 0);
        if ($inicio < 0 || $fim <= $inicio || $fim > count($linhas)) {
            erro(400, 'índices fora da aba');
        }
        array_splice($linhas, $inicio, $fim - $inicio);
        $estado['planilhas'][$planilha][$aba] = $linhas;
    }
    responder(200, ['spreadsheetId' => $planilha, 'replies' => array_fill(0, count($pedido['requests'] ?? []), [])]);
}
if (preg_match('#^/v4/spreadsheets/([^/]+)/values/(.+?)(:append)?$#', $caminho, $m)) {
    $planilha = $m[1];
    $intervalo = $m[2];
    $acrescentar = ($m[3] ?? '') === ':append';
    if (!preg_match("/^'((?:[^']|'')+)'!([A-Z]+\d*(?::[A-Z]+\d*)?)$/", $intervalo, $mm)) {
        erro(400, 'intervalo sem a aba entre aspas simples: ' . $intervalo);
    }
    $aba = str_replace("''", "'", $mm[1]);
    if ($acrescentar) {
        if ($metodo !== 'POST' || ($query['valueInputOption'] ?? '') !== 'USER_ENTERED') {
            erro(400, 'append exige POST com valueInputOption=USER_ENTERED');
        }
        $dados = json_decode($corpo, true) ?: [];
        foreach ($dados['values'] ?? [] as $linha) {
            $estado['planilhas'][$planilha][$aba][] = $linha;
        }
        $estado['appends'][] = ['planilha' => $planilha, 'intervalo' => $intervalo, 'aba' => $aba, 'query' => $query, 'corpo' => $dados];
        responder(200, ['spreadsheetId' => $planilha, 'updates' => ['updatedRows' => count($dados['values'] ?? [])]]);
    }
    if ($metodo === 'GET') {
        $soColunaA = preg_match('/^A\d*(?::A\d*)?$/', $mm[2]) === 1;
        $valores = [];
        foreach ($estado['planilhas'][$planilha][$aba] ?? [] as $linha) {
            $valores[] = $soColunaA ? [(string) ($linha[0] ?? '')] : $linha;
        }
        responder(200, ['range' => $intervalo, 'majorDimension' => 'ROWS', 'values' => $valores]);
    }
}
erro(404, 'rota desconhecida: ' . $metodo . ' ' . $caminho);
PHP);

$nulo = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', "$tmp/servidor.log", 'a']];
$servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$porta", $roteador], $nulo, $tubos, null,
    ['FALSO_ESTADO' => $estadoArquivo, 'FALSO_PUBLICA' => "$tmp/publica.pem", 'FALSO_BASE' => $base, 'FALSO_TMP' => $tmp, 'PATH' => (string) getenv('PATH')]);
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $porta) === false; $i++) {
    usleep(100000);
}

/** O que o Google falso guardou. */
function estado(): array
{
    global $estadoArquivo;
    return json_decode((string) file_get_contents($estadoArquivo), true) ?: [];
}
function controle(array $comando): void
{
    global $base;
    $ch = curl_init("$base/__controle");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($comando), CURLOPT_TIMEOUT => 10]);
    curl_exec($ch);
    curl_close($ch);
}
/** Quantas requisições com este método e este prefixo de caminho o servidor recebeu. */
function requisicoes(?string $metodo = null, ?string $prefixo = null): int
{
    $n = 0;
    foreach (estado()['requisicoes'] ?? [] as $r) {
        if (($metodo === null || $r['metodo'] === $metodo) && ($prefixo === null || str_starts_with($r['caminho'], $prefixo))) {
            $n++;
        }
    }
    return $n;
}
/** Id da pasta "Secretaria/Exportações/2026" no servidor falso, andando desde a raiz; null se algum nível não existe. */
function pasta_falsa(string $caminho): ?string
{
    $pai = 'raiz';
    foreach (explode('/', $caminho) as $nome) {
        $achou = null;
        foreach (estado()['arquivos'] as $id => $a) {
            if (empty($a['apagado']) && $a['mimeType'] === 'application/vnd.google-apps.folder' && $a['name'] === $nome && in_array($pai, $a['parents'], true)) {
                $achou = $id;
                break;
            }
        }
        if ($achou === null) {
            return null;
        }
        $pai = $achou;
    }
    return $pai;
}
/** O arquivo com este nome nesta pasta do servidor falso (com 'id' dentro), ou null. */
function arquivo_falso(string $pasta, string $nome): ?array
{
    $pastaId = pasta_falsa($pasta);
    foreach (estado()['arquivos'] as $id => $a) {
        if ($pastaId !== null && empty($a['apagado']) && $a['name'] === $nome && in_array($pastaId, $a['parents'], true)) {
            return $a + ['id' => $id];
        }
    }
    return null;
}
function conteudo_falso(?array $arquivo): string
{
    return is_array($arquivo) && is_string($arquivo['conteudo'] ?? null) ? (string) base64_decode($arquivo['conteudo']) : '';
}
/** O texto dos fluxos de conteúdo de um PDF do McpPdf (comprimidos com FlateDecode; os de imagem ficam de fora). */
function texto_pdf(string $pdf): string
{
    $texto = '';
    $posicao = 0;
    while (preg_match('#/Length (\d+) /Filter /FlateDecode >>\nstream\n#', $pdf, $m, PREG_OFFSET_CAPTURE, $posicao)) {
        $inicio = $m[0][1] + strlen($m[0][0]);
        $texto .= (string) @gzuncompress(substr($pdf, $inicio, (int) $m[1][0]));
        $posicao = $inicio + (int) $m[1][0];
    }
    return $texto;
}
/** Roda um trecho de PHP em outro processo, com o mesmo ambiente e o lib.php carregado. [saída, erros, código]. */
function subprocesso(string $codigo): array
{
    global $tmp, $api, $ambiente;
    $arquivo = $tmp . '/sub-' . bin2hex(random_bytes(3)) . '.php';
    file_put_contents($arquivo, "<?php\ndeclare(strict_types=1);\n\$_SERVER['REQUEST_METHOD'] = 'CLI';\nrequire " . var_export($api . '/lib.php', true) . ";\nrestore_exception_handler();\n"
        . "if (!function_exists('mcp_google_token')) { require_once " . var_export($api . '/lib/google_drive.php', true) . "; }\n"
        . "if (!function_exists('mcp_drive_ligado')) { require_once " . var_export($api . '/lib/drive_espelho.php', true) . "; }\n" . $codigo . "\n");
    return comando([PHP_BINARY, $arquivo]);
}
function comando(array $argv, array $ambienteExtra = []): array
{
    global $ambiente;
    $tubos = [];
    $p = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos, null, $ambienteExtra + $ambiente);
    $saida = (string) stream_get_contents($tubos[1]);
    $erros = (string) stream_get_contents($tubos[2]);
    fclose($tubos[1]);
    fclose($tubos[2]);
    return [$saida, $erros, proc_close($p)];
}

// ----------------------------------------------------------------------------- banco
$db = mcp_db();
// As tabelas do Drive e dos voluntários vêm do lib/db.php (mcp_migrar_tudo); se este banco ainda não as tem (db.php
// antigo), cria como na especificação, para o teste não depender da ordem de publicação dos módulos.
$db->exec("CREATE TABLE IF NOT EXISTS mcp_drive_fila (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tipo VARCHAR(30) NOT NULL, referencia INT UNSIGNED NULL,
    dados TEXT NULL, estado ENUM('pendente','feito','falhou') NOT NULL DEFAULT 'pendente', tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0, erro VARCHAR(255) NULL,
    proxima_em DATETIME NULL, criado_em DATETIME NOT NULL, atualizado_em DATETIME NOT NULL, KEY ix_estado (estado, proxima_em)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->exec("CREATE TABLE IF NOT EXISTS mcp_drive_arquivos (caminho VARCHAR(255) NOT NULL PRIMARY KEY, tamanho INT UNSIGNED NOT NULL, modificado_em DATETIME NOT NULL,
    drive_id VARCHAR(80) NOT NULL, enviado_em DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->exec("CREATE TABLE IF NOT EXISTS mcp_voluntarios (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, protocolo VARCHAR(24) NULL, nome VARCHAR(160) NOT NULL,
    data_nascimento DATE NOT NULL, email VARCHAR(190) NOT NULL, telefone VARCHAR(20) NOT NULL, cpf CHAR(11) NULL, cidade VARCHAR(120) NOT NULL, bairro VARCHAR(120) NULL,
    estado CHAR(2) NOT NULL DEFAULT 'RJ', escolaridade VARCHAR(30) NOT NULL, profissao VARCHAR(120) NULL, saude TINYINT(1) NOT NULL DEFAULT 0, saude_qual VARCHAR(160) NULL,
    experiencia TEXT NULL, habilidades TEXT NULL, frentes VARCHAR(255) NOT NULL, dias VARCHAR(40) NOT NULL, periodos VARCHAR(30) NOT NULL, motivacao TEXT NOT NULL,
    como_conheceu VARCHAR(30) NULL, aceita_contato TINYINT(1) NOT NULL DEFAULT 0, consentimento_em DATETIME NOT NULL, politica_versao VARCHAR(10) NOT NULL,
    veracidade_em DATETIME NOT NULL, pagina VARCHAR(255) NULL, utm_source VARCHAR(120) NULL, utm_medium VARCHAR(120) NULL, utm_campaign VARCHAR(160) NULL,
    utm_content VARCHAR(160) NULL, utm_term VARCHAR(160) NULL, fbclid VARCHAR(255) NULL, gclid VARCHAR(255) NULL, ip VARCHAR(45) NULL, email_equipe VARCHAR(20) NULL,
    email_confirmacao VARCHAR(20) NULL, status ENUM('nova','em_analise','entrevista','aceita','recusada','arquivada') NOT NULL DEFAULT 'nova', status_em DATETIME NULL,
    status_por VARCHAR(190) NULL, colaborador_id INT UNSIGNED NULL, drive_pdf_id VARCHAR(80) NULL, drive_planilha_em DATETIME NULL, criado_em DATETIME NOT NULL,
    atualizado_em DATETIME NOT NULL, KEY ix_status (status, criado_em), KEY ix_email (email, criado_em), KEY ix_criado (criado_em)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function limpar(PDO $db): void
{
    $db->exec('DELETE FROM mcp_drive_fila');
    $db->exec('DELETE FROM mcp_drive_arquivos');
    $db->exec("DELETE FROM mcp_chaves WHERE nome LIKE 'drive\\_%'");
    $db->exec("DELETE FROM mcp_voluntarios WHERE nome LIKE 'Teste Drive %'");
}
limpar($db);
$sufixo = bin2hex(random_bytes(2));
$agora = mcp_agora();
$hoje = mcp_drive_dia_brt(0);
$ontem = mcp_drive_dia_brt(-1);

/** Uma ficha de voluntário com os campos obrigatórios da especificação. Devolve o id. */
function ficha_nova(PDO $db, string $nome, string $agora, string $cpf = '52998224725'): int
{
    $db->prepare('INSERT INTO mcp_voluntarios (nome, data_nascimento, email, telefone, cpf, cidade, bairro, estado, escolaridade, profissao, saude, saude_qual, experiencia,
        habilidades, frentes, dias, periodos, motivacao, como_conheceu, aceita_contato, consentimento_em, politica_versao, veracidade_em, ip, criado_em, atualizado_em)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$nome, '1990-05-20', 'drive-teste@exemplo.org', '21999990000', $cpf, 'Rio de Janeiro', 'Centro', 'RJ', 'superior', 'Enfermeira', 1, 'Técnica em enfermagem',
            'Brigadista na empresa', 'Primeiros socorros, organização', 'formacao_primeiros_socorros,campanhas_acoes', 'seg,qua,sab', 'manha,noite',
            'Quero doar meu tempo às ações de saúde da filial.', 'instagram', 1, $agora, '2026-10', $agora, '127.0.0.1', $agora, $agora]);
    return (int) $db->lastInsertId();
}

try {
    // ------------------------------------------------------------------------- ligado e token
    verificar('Drive ligado com GOOGLE_SA e GOOGLE_DRIVE_RAIZ_ID', mcp_drive_ligado(), true);
    $token = mcp_google_token();
    verificar('token obtido do servidor falso', str_starts_with($token, 'tok-'), true);
    $jwt = estado()['jwts'][0] ?? null;
    verificar('JWT aceito: assinatura RS256 conferida com a chave pública, aud, scope e exp', campo($jwt, 'ok'), true);
    verificar('JWT: iss é o client_email', campo($jwt['claims'] ?? null, 'iss'), 'site-drive@teste.iam.gserviceaccount.com');
    verificar('JWT: alg RS256', campo($jwt['cabecalho'] ?? null, 'alg'), 'RS256');
    verificar('JWT: validade de 1 hora', ($jwt['claims']['exp'] ?? 0) - ($jwt['claims']['iat'] ?? 0), 3600);
    verificar('token em cache no arquivo indicado pelo ambiente (fora de api/)', is_file($tokenArquivo), true);
    verificar('arquivo do token com permissão 0600', substr(sprintf('%o', fileperms($tokenArquivo)), -4), '0600');
    verificar('arquivo do token não guarda a chave privada', str_contains((string) file_get_contents($tokenArquivo), 'PRIVATE KEY'), false);
    verificar('segunda chamada usa o cache (um só pedido de token)', [mcp_google_token() === $token, requisicoes('POST', '/token')], [true, 1]);
    // Renovação 5 minutos antes de vencer: outro processo lê o arquivo (a memória deste não entra).
    file_put_contents($tokenArquivo, json_encode(['access_token' => 'tok-velho', 'expira_em' => time() + 200]));
    [$saida] = subprocesso('echo mcp_google_token();');
    verificar('token a 200 s de vencer é renovado em outro processo', [str_starts_with($saida, 'tok-') && $saida !== 'tok-velho', requisicoes('POST', '/token')], [true, 2]);
    file_put_contents($tokenArquivo, json_encode(['access_token' => $token, 'expira_em' => time() + 400]));
    [$saida] = subprocesso('echo mcp_google_token();');
    verificar('token a 400 s de vencer ainda serve', [$saida, requisicoes('POST', '/token')], [$token, 2]);
    file_put_contents($tokenArquivo, json_encode(['access_token' => $token, 'expira_em' => time() + 3600]));

    // ------------------------------------------------------------------------- pastas
    $antes = requisicoes();
    $fichasId = mcp_drive_pasta_garantir('Voluntariado/Fichas');
    verificar('pasta Voluntariado/Fichas criada: dois níveis, Fichas dentro de Voluntariado dentro da raiz', pasta_falsa('Voluntariado/Fichas'), $fichasId);
    verificar('criar os dois níveis = 2 buscas + 2 criações', requisicoes() - $antes, 4);
    verificar('cache em mcp_chaves: uma chave por nível', (int) $db->query("SELECT COUNT(*) FROM mcp_chaves WHERE nome LIKE 'drive\\_pasta:%'")->fetchColumn(), 2);
    verificar('chave do cache cabe nos 40 caracteres da coluna', (int) $db->query("SELECT MAX(CHAR_LENGTH(nome)) FROM mcp_chaves WHERE nome LIKE 'drive\\_pasta:%'")->fetchColumn() <= 40, true);
    $antes = requisicoes();
    verificar('segunda chamada devolve o mesmo id sem bater na API', [mcp_drive_pasta_garantir('Voluntariado/Fichas'), requisicoes() - $antes], [$fichasId, 0]);
    [$saida] = subprocesso("echo mcp_drive_pasta_garantir('Voluntariado/Fichas');");
    verificar('outro processo acha a pasta pelo cache do banco, sem API', [$saida, requisicoes() - $antes], [$fichasId, 0]);
    verificar('caminho vazio é a raiz', mcp_drive_pasta_garantir(''), 'raiz');
    $antes = requisicoes();
    $exportId = mcp_drive_pasta_garantir('Secretaria/Exportações/' . substr($hoje, 0, 4));
    verificar('pasta com acento e três níveis', [pasta_falsa('Secretaria/Exportações/' . substr($hoje, 0, 4)), requisicoes() - $antes], [$exportId, 6]);
    // Pasta apagada no Drive: o cache é esquecido e a pasta recriada numa segunda tentativa.
    controle(['falhar' => 0]);
    $apagarId = mcp_drive_pasta_garantir('Temporaria');
    mcp_drive_apagar_arquivo($apagarId);
    $novoId = mcp_drive_com_pasta('Temporaria', static fn(string $pastaId): string => mcp_drive_enviar_arquivo($pastaId, 'x.txt', 'x', 'text/plain'));
    verificar('pasta apagada no Drive: 404 esquece o cache e recria a pasta', [$novoId !== '', pasta_falsa('Temporaria') !== null && pasta_falsa('Temporaria') !== $apagarId], [true, true]);

    // ------------------------------------------------------------------------- arquivos
    $conteudo = "Olá, Drive! Conteúdo com acento — e aspas \"duplas\".";
    $arquivoId = mcp_drive_enviar_arquivo($fichasId, "ola 'teste'.txt", $conteudo, 'text/plain');
    $enviado = arquivo_falso('Voluntariado/Fichas', "ola 'teste'.txt");
    verificar('multipart: id devolvido é o do arquivo criado', campo($enviado, 'id'), $arquivoId);
    verificar('multipart: duas partes (metadados JSON + conteúdo), boundary reconhecido', [campo($enviado, 'modo'), campo($enviado, 'partes'), str_starts_with((string) campo($enviado, 'boundary'), 'mcp_')], ['multipart', 2, true]);
    verificar('multipart: conteúdo íntegro e MIME da parte', [conteudo_falso($enviado), campo($enviado, 'mimeType')], [$conteudo, 'text/plain']);
    $mesmoId = mcp_drive_enviar_arquivo($fichasId, "ola 'teste'.txt", 'novo conteúdo', 'text/plain', $arquivoId);
    $trocado = arquivo_falso('Voluntariado/Fichas', "ola 'teste'.txt");
    verificar('substituir: PATCH media troca o conteúdo mantendo id e nome', [$mesmoId, campo($trocado, 'modo'), conteudo_falso($trocado), campo($trocado, 'versao')], [$arquivoId, 'media', 'novo conteúdo', 2]);
    verificar('substituir um id que não existe mais sobe como arquivo novo', mcp_drive_enviar_arquivo($fichasId, 'orfao.txt', 'abc', 'text/plain', 'arq-inexistente') !== '', true);
    verificar('buscar pelo nome escapa as aspas simples', campo(mcp_drive_buscar("ola 'teste'.txt", $fichasId), 'id'), $arquivoId);
    verificar('buscar nome inexistente devolve null', mcp_drive_buscar('nao-existe.txt', $fichasId), null);
    $grande = random_bytes(600000);
    $antes = requisicoes();
    $grandeId = mcp_drive_enviar_arquivo($fichasId, 'grande.bin', $grande, 'application/octet-stream');
    $recebido = arquivo_falso('Voluntariado/Fichas', 'grande.bin');
    verificar('retomável: acima do limiar vai em sessão com Location + PUTs', [campo($recebido, 'id'), campo($recebido, 'modo')], [$grandeId, 'resumable']);
    verificar('retomável: 3 blocos de 256 KiB com Content-Range e conteúdo íntegro', [campo($recebido, 'blocos'), campo($recebido, 'md5'), campo($recebido, 'tamanho')],
        [[[0, 262143], [262144, 524287], [524288, 599999]], md5($grande), 600000]);
    verificar('retomável: 1 início + 3 PUTs', requisicoes() - $antes, 4);
    $grande2 = random_bytes(300000);
    verificar('retomável: trocar conteúdo de arquivo existente (PATCH) mantém o id', [mcp_drive_enviar_arquivo($fichasId, 'grande.bin', $grande2, 'application/octet-stream', $grandeId),
        campo(arquivo_falso('Voluntariado/Fichas', 'grande.bin'), 'md5')], [$grandeId, md5($grande2)]);

    // ------------------------------------------------------------------------- planilha
    mcp_drive_planilha_acrescentar('planilha-teste', 'Fichas', ['VOL-000000-0000', 'linha de teste', 12]);
    $append = estado()['appends'][0] ?? null;
    verificar('planilha: aba entre aspas simples e USER_ENTERED', [campo($append, 'intervalo'), campo($append['query'] ?? null, 'valueInputOption')], ["'Fichas'!A1", 'USER_ENTERED']);
    verificar('planilha: a linha vai em values[0]', $append['corpo']['values'][0] ?? null, ['VOL-000000-0000', 'linha de teste', 12]);
    verificar('erro do Google vira McpDriveErro com status e corpo resumido', (static function (): array {
        try {
            mcp_drive_info('nao-existe');
            return ['sem exceção'];
        } catch (McpDriveErro $e) {
            return [$e->status, str_contains($e->getMessage(), 'File not found'), mb_strlen($e->getMessage()) <= 400];
        }
    })(), [404, true, true]);

    // ------------------------------------------------------------------------- fila: ficha de voluntário
    $fichaId = ficha_nova($db, "Teste Drive Ângela Conceição $sufixo", $agora);
    mcp_drive_enfileirar('ficha_voluntario', $fichaId);
    mcp_drive_enfileirar('ficha_voluntario', $fichaId);
    verificar('enfileirar: um trabalho pendente por ficha (repetido não entra)', (int) $db->query("SELECT COUNT(*) FROM mcp_drive_fila WHERE tipo = 'ficha_voluntario' AND referencia = $fichaId")->fetchColumn(), 1);
    verificar('processar a fila: 1 feito, 0 falhas', mcp_drive_processar_fila(), ['feitos' => 1, 'falhas' => 0]);
    $ficha = $db->query("SELECT * FROM mcp_voluntarios WHERE id = $fichaId")->fetch();
    $protocolo = function_exists('mcp_voluntario_protocolo') ? mcp_voluntario_protocolo($fichaId, $agora) : mcp_drive_ficha_protocolo($ficha);
    $nomePdf = $protocolo . '-Teste_Drive_Angela_Conceicao_' . $sufixo . '.pdf';
    $pdf = arquivo_falso('Voluntariado/Fichas', $nomePdf);
    verificar('ficha: PDF em Voluntariado/Fichas com nome VOL-...-Nome_em_ASCII.pdf', $pdf !== null, true);
    verificar('ficha: é um PDF de verdade', [str_starts_with(conteudo_falso($pdf), '%PDF-'), campo($pdf, 'mimeType')], [true, 'application/pdf']);
    verificar('ficha: drive_pdf_id e drive_planilha_em gravados na ficha', [campo($ficha, 'drive_pdf_id'), campo($ficha, 'drive_planilha_em') !== null], [campo($pdf, 'id'), true]);
    $linha = estado()['appends'][1]['corpo']['values'][0] ?? null;
    verificar('ficha: linha da planilha com 16 colunas, protocolo primeiro e sem CPF', [is_array($linha) ? count($linha) : 0, $linha[0] ?? null, is_array($linha) && !in_array('52998224725', array_map('strval', $linha), true)], [16, $protocolo, true]);
    verificar('ficha: nome, idade, cidade/bairro, saúde e status na linha', [$linha[2] ?? null, $linha[3] ?? null, $linha[6] ?? null, $linha[9] ?? null, $linha[15] ?? null],
        ["Teste Drive Ângela Conceição $sufixo", '36', 'Rio de Janeiro / Centro (RJ)', 'Sim: Técnica em enfermagem', 'Nova']);
    verificar('ficha: frentes, dias e períodos com rótulos', [str_contains((string) ($linha[10] ?? ''), 'rimeiros socorros'), $linha[11] ?? null, $linha[12] ?? null], [true, 'Seg, Qua, Sáb', 'Manhã, Noite']);
    $trabalho = $db->query("SELECT estado, tentativas, erro FROM mcp_drive_fila WHERE referencia = $fichaId")->fetch();
    verificar('ficha: trabalho feito na 1ª tentativa, sem erro', $trabalho, ['estado' => 'feito', 'tentativas' => 1, 'erro' => null]);
    $reserva = mcp_drive_ficha_pdf_simples($ficha, $protocolo);
    $textoReserva = texto_pdf($reserva);
    verificar('ficha: o PDF reserva (sem lib/voluntarios.php) também é um PDF e mostra o CPF só pelos 3 primeiros dígitos',
        [str_starts_with($reserva, '%PDF-'), str_contains($textoReserva, '529.***.***-**'), str_contains($textoReserva, '52998224725'), str_contains($textoReserva, $protocolo)], [true, true, false, true]);
    verificar('ficha: nome em ASCII para o arquivo', mcp_drive_nome_ascii("  Ângela  da Conceição-Júnior 3º "), 'Angela_da_Conceicao_Junior_3');
    verificar('ficha apagada antes de processar é descartada como feita', (static function () use ($db): array {
        $db->prepare("INSERT INTO mcp_drive_fila (tipo, referencia, estado, tentativas, criado_em, atualizado_em) VALUES ('ficha_voluntario', 999999999, 'pendente', 0, ?, ?)")->execute([mcp_agora(), mcp_agora()]);
        $r = mcp_drive_processar_fila();
        $t = $db->query('SELECT estado, erro FROM mcp_drive_fila WHERE referencia = 999999999')->fetch();
        return [$r, $t['estado'], str_starts_with((string) $t['erro'], 'descartado:')];
    })(), [['feitos' => 1, 'falhas' => 0], 'feito', true]);

    // ------------------------------------------------------------------------- fila: falhas e novas tentativas
    $fichaId2 = ficha_nova($db, "Teste Drive Bruno Falha $sufixo", $agora, '');
    mcp_drive_enfileirar('ficha_voluntario', $fichaId2);
    $esperas = [];
    for ($tentativa = 1; $tentativa <= 5; $tentativa++) {
        controle(['falhar' => 1]);
        $r = mcp_drive_processar_fila();
        $t = $db->query("SELECT estado, tentativas, erro, proxima_em FROM mcp_drive_fila WHERE referencia = $fichaId2")->fetch();
        $esperas[] = $t['proxima_em'] !== null ? (int) round((strtotime($t['proxima_em'] . ' UTC') - time()) / 60) : null;
        if ($tentativa === 1) {
            verificar('falha: 0 feitos, 1 falha; trabalho segue pendente com tentativas = 1', [$r, $t['estado'], (int) $t['tentativas']], [['feitos' => 0, 'falhas' => 1], 'pendente', 1]);
            verificar('falha: erro resumido com classe e status, sem token', [str_starts_with((string) $t['erro'], 'McpDriveErro 500'), str_contains((string) $t['erro'], 'tok-'), mb_strlen((string) $t['erro']) <= 255], [true, false, true]);
            verificar('falha: com proxima_em no futuro, a fila não repete agora', mcp_drive_processar_fila(), ['feitos' => 0, 'falhas' => 0]);
        }
        $db->exec("UPDATE mcp_drive_fila SET proxima_em = NULL WHERE referencia = $fichaId2 AND estado = 'pendente'");
    }
    verificar('falha: esperas de 5, 15, 60 e 240 minutos; na 5ª vira falhou (sem proxima_em)', $esperas, [5, 15, 60, 240, null]);
    verificar('falha definitiva: estado falhou com 5 tentativas', [$t['estado'], (int) $t['tentativas']], ['falhou', 5]);
    verificar('falha definitiva: não é mais processada', mcp_drive_processar_fila(), ['feitos' => 0, 'falhas' => 0]);
    verificar('a ficha que falhou não ganhou drive_pdf_id', $db->query("SELECT drive_pdf_id FROM mcp_voluntarios WHERE id = $fichaId2")->fetchColumn(), null);
    verificar('tipo desconhecido é descartado como feito', (static function () use ($db): array {
        mcp_drive_enfileirar('tipo_que_nao_existe', null, ['x' => 1]);
        $r = mcp_drive_processar_fila();
        return [$r, (string) $db->query("SELECT erro FROM mcp_drive_fila WHERE tipo = 'tipo_que_nao_existe'")->fetchColumn()];
    })(), [['feitos' => 1, 'falhas' => 0], 'descartado: tipo desconhecido: tipo_que_nao_existe']);

    // ------------------------------------------------------------------------- exportação diária e cópia do banco
    mcp_drive_enfileirar('exportacao_diaria', null, ['dia' => $hoje]);
    mcp_drive_enfileirar('backup_banco', null, ['dia' => $hoje]);
    verificar('exportação e cópia: 2 feitos', mcp_drive_processar_fila(), ['feitos' => 2, 'falhas' => 0]);
    $csv = arquivo_falso('Secretaria/Exportações/' . substr($hoje, 0, 4), "$hoje-mcp_voluntarios.csv");
    verificar('exportação: CSV do dia em Secretaria/Exportações/AAAA/', [$csv !== null, campo($csv, 'mimeType')], [true, 'text/csv']);
    $texto = conteudo_falso($csv);
    $linhas = explode("\n", trim($texto));
    verificar('exportação: BOM, ponto e vírgula e cabeçalho sem coluna de CPF', [str_starts_with($texto, "\xEF\xBB\xBF"), str_contains($linhas[0], ';nome;'), str_contains(strtolower($linhas[0]), 'cpf')], [true, true, false]);
    verificar('exportação: as fichas de hoje estão no CSV, sem o CPF', [str_contains($texto, "Teste Drive Ângela Conceição $sufixo"), str_contains($texto, '52998224725')], [true, false]);
    $obs = (string) $db->query("SELECT erro FROM mcp_drive_fila WHERE tipo = 'exportacao_diaria'")->fetchColumn();
    verificar('exportação: observação do trabalho cita o dia e as tabelas', [str_starts_with($obs, $hoje . ': '), str_contains($obs, 'mcp_voluntarios (')], [true, true]);
    $gz = arquivo_falso('Backups/banco/' . $hoje, 'mcp_voluntarios.json.gz');
    $json = json_decode((string) gzdecode(conteudo_falso($gz)), true);
    verificar('cópia do banco: JSON.gz por tabela em Backups/banco/AAAA-MM-DD/', [campo($gz, 'mimeType'), campo($json, 'tabela'), is_array($json['linhas'] ?? null)], ['application/gzip', 'mcp_voluntarios', true]);
    $nossa = array_values(array_filter($json['linhas'] ?? [], static fn(array $l): bool => (int) $l['id'] === $fichaId));
    verificar('cópia do banco: completa (com CPF) e com as nossas linhas', [count($nossa), $nossa[0]['cpf'] ?? null], [1, '52998224725']);
    verificar('cópia do banco: todas as tabelas mcp_*', [arquivo_falso('Backups/banco/' . $hoje, 'mcp_chaves.json.gz') !== null, arquivo_falso('Backups/banco/' . $hoje, 'mcp_drive_fila.json.gz') !== null,
        arquivo_falso('Backups/banco/' . $hoje, 'mcp_inscricoes.json.gz') !== null], [true, true, true]);
    $antes = count(estado()['arquivos']);
    mcp_drive_enfileirar('backup_banco', null, ['dia' => $hoje]);
    mcp_drive_processar_fila();
    verificar('cópia repetida no mesmo dia substitui os arquivos em vez de duplicar', [count(estado()['arquivos']) - $antes, campo(arquivo_falso('Backups/banco/' . $hoje, 'mcp_voluntarios.json.gz'), 'versao')], [0, 2]);
    verificar('cópia do banco em lotes de 500: tabela com 1200 linhas sai inteira', (static function () use ($db): array {
        $db->exec('CREATE TABLE IF NOT EXISTS mcp_teste_lotes (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, valor VARCHAR(20) NOT NULL) ENGINE=InnoDB');
        $db->exec('DELETE FROM mcp_teste_lotes');
        $db->exec('INSERT INTO mcp_teste_lotes (valor) VALUES ' . implode(', ', array_fill(0, 1200, "('x')")));
        $json = json_decode((string) gzdecode(mcp_drive_tabela_json_gz($db, 'mcp_teste_lotes')), true);
        $db->exec('DROP TABLE mcp_teste_lotes');
        return [count($json['linhas'] ?? []), $json['linhas'][1199]['id'] ?? null];
    })(), [1200, 1200]);

    // ------------------------------------------------------------------------- expurgo: apagar PDF e linha da planilha
    mcp_drive_planilha_acrescentar('planilha-teste', 'Fichas', ['VOL-999999-9999', 'outra ficha que fica']);
    $linhasAntes = estado()['planilhas']['planilha-teste']['Fichas'] ?? [];
    $indiceProtocolo = array_search($protocolo, array_map(static fn(array $l): string => (string) $l[0], $linhasAntes), true);
    mcp_drive_enfileirar('apagar_ficha_drive', $fichaId, ['protocolo' => $protocolo, 'drive_pdf_id' => campo($pdf, 'id')]);
    $antes = requisicoes('GET', '/v4/spreadsheets/planilha-teste');
    verificar('expurgo: 1 feito', mcp_drive_processar_fila(), ['feitos' => 1, 'falhas' => 0]);
    verificar('expurgo: PDF apagado no Drive (DELETE files/{id})', [arquivo_falso('Voluntariado/Fichas', $nomePdf), requisicoes('DELETE', '/drive/v3/files/' . campo($pdf, 'id'))], [null, 1]);
    $linhasDepois = estado()['planilhas']['planilha-teste']['Fichas'] ?? [];
    verificar('expurgo: só a linha do protocolo saiu da aba Fichas', [count($linhasAntes) - count($linhasDepois), in_array($protocolo, array_column($linhasDepois, 0), true), in_array('VOL-999999-9999', array_column($linhasDepois, 0), true)], [1, false, true]);
    $batch = estado()['batch_updates'][0]['pedido']['requests'][0]['deleteDimension']['range'] ?? null;
    verificar('expurgo: batchUpdate deleteDimension ROWS com o sheetId da aba e o índice da linha', $batch, ['sheetId' => 777001, 'dimension' => 'ROWS', 'startIndex' => $indiceProtocolo, 'endIndex' => $indiceProtocolo + 1]);
    verificar('expurgo: sheetId em cache em mcp_chaves (drive_aba:…, até 40 caracteres)', (int) $db->query("SELECT COUNT(*) FROM mcp_chaves WHERE nome LIKE 'drive\\_aba:%' AND CHAR_LENGTH(nome) <= 40 AND valor = '777001'")->fetchColumn(), 1);
    verificar('expurgo: observação do trabalho', (string) $db->query("SELECT erro FROM mcp_drive_fila WHERE tipo = 'apagar_ficha_drive'")->fetchColumn(), $protocolo . ': PDF apagado, 1 linha(s) da planilha removida(s)');
    $antesGet = requisicoes('GET', '/v4/spreadsheets/planilha-teste');
    mcp_drive_enfileirar('apagar_ficha_drive', null, ['protocolo' => $protocolo, 'drive_pdf_id' => campo($pdf, 'id')]);
    verificar('expurgo repetido: 404 do PDF e protocolo ausente contam como feito', [mcp_drive_processar_fila(), (string) $db->query("SELECT erro FROM mcp_drive_fila WHERE tipo = 'apagar_ficha_drive' ORDER BY id DESC LIMIT 1")->fetchColumn()],
        [['feitos' => 1, 'falhas' => 0], $protocolo . ': PDF apagado, protocolo não estava na planilha']);
    verificar('expurgo repetido: leu a coluna A, mas não pediu as propriedades da planilha de novo (cache do sheetId)',
        [requisicoes('GET', '/v4/spreadsheets/planilha-teste/values/') >= 2, requisicoes('GET', '/v4/spreadsheets/planilha-teste') - requisicoes('GET', '/v4/spreadsheets/planilha-teste/values/')], [true, 1]);
    verificar('remover linha inexistente devolve false', mcp_drive_planilha_remover_linha('planilha-teste', 'Fichas', 'VOL-000000-0001'), false);

    // ------------------------------------------------------------------------- espelho dos assets
    $assets = "$tmp/assets";
    mkdir("$assets/sub/deep", 0700, true);
    mkdir("$assets/.escondida", 0700, true);
    file_put_contents("$assets/a.txt", 'arquivo a');
    file_put_contents("$assets/sub/b.css", 'body{color:red}');
    file_put_contents("$assets/sub/deep/c.png", "\x89PNG falso");
    file_put_contents("$assets/.oculto", 'dotfile');
    file_put_contents("$assets/.escondida/d.txt", 'dentro de pasta oculta');
    $f = fopen("$assets/grande.bin", 'w');
    ftruncate($f, 51 * 1024 * 1024); // esparso: 51 MB de tamanho sem ocupar disco
    fclose($f);
    $r = mcp_drive_espelhar_assets(40, 50, $assets);
    verificar('assets: 3 vistos e enviados; ignora dotfile, pasta oculta e arquivo > 50 MB', $r, ['vistos' => 3, 'enviados' => 3, 'ignorados' => 3, 'falhas' => 0, 'restantes' => 0]);
    verificar('assets: Site/assets/sub/deep/c.png criado com as pastas', campo(arquivo_falso('Site/assets/sub/deep', 'c.png'), 'mimeType'), 'image/png');
    verificar('assets: manifesto com 3 caminhos relativos', $db->query('SELECT caminho FROM mcp_drive_arquivos ORDER BY caminho')->fetchAll(PDO::FETCH_COLUMN), ['a.txt', 'sub/b.css', 'sub/deep/c.png']);
    verificar('assets: segunda rodada não reenvia nada', mcp_drive_espelhar_assets(40, 50, $assets)['enviados'], 0);
    $idB = (string) $db->query("SELECT drive_id FROM mcp_drive_arquivos WHERE caminho = 'sub/b.css'")->fetchColumn();
    file_put_contents("$assets/sub/b.css", 'body{color:blue;margin:0}');
    touch("$assets/sub/b.css", time() + 5);
    $r = mcp_drive_espelhar_assets(40, 50, $assets);
    $b = arquivo_falso('Site/assets/sub', 'b.css');
    verificar('assets: arquivo alterado reenvia (1) no mesmo id do Drive, trocando o conteúdo', [$r['enviados'], campo($b, 'id'), campo($b, 'modo'), conteudo_falso($b)], [1, $idB, 'media', 'body{color:blue;margin:0}']);
    verificar('assets: manifesto atualizado com tamanho e data novos', $db->query("SELECT tamanho, modificado_em FROM mcp_drive_arquivos WHERE caminho = 'sub/b.css'")->fetch(), ['tamanho' => 25, 'modificado_em' => gmdate('Y-m-d H:i:s', (int) filemtime("$assets/sub/b.css"))]);
    file_put_contents("$assets/d.txt", 'd');
    file_put_contents("$assets/e.txt", 'e');
    verificar('assets: limite por quantidade deixa o resto para a próxima rodada', mcp_drive_espelhar_assets(1, 50, $assets), ['vistos' => 5, 'enviados' => 1, 'ignorados' => 3, 'falhas' => 0, 'restantes' => 1]);
    verificar('assets: pasta inexistente não faz nada', mcp_drive_espelhar_assets(40, 50, "$tmp/nao-existe")['vistos'], 0);

    // ------------------------------------------------------------------------- api/drive.php
    $db->exec("DELETE FROM mcp_chaves WHERE nome = 'drive_exportacao_em'");
    [$saida, $erros, $codigo] = comando([PHP_BINARY, $api . '/drive.php']);
    verificar('drive.php: rodada normal termina com código 0 e sem erros no stderr', [$codigo, $erros], [0, '']);
    verificar('drive.php: resumo numa linha com fila, diário e assets', [substr_count(trim($saida), "\n"), preg_match('/ drive: fila: \d+ feitos, \d+ falhas; diário de ' . $hoje . ': 2 feitos, 0 falhas; assets: \d+ vistos/', $saida)], [0, 1]);
    verificar('drive.php: registra o dia em mcp_chaves e enfileira a exportação de ontem e a cópia de hoje',
        [(string) $db->query("SELECT valor FROM mcp_chaves WHERE nome = 'drive_exportacao_em'")->fetchColumn(),
            $db->query("SELECT dados FROM mcp_drive_fila WHERE tipo = 'exportacao_diaria' ORDER BY id DESC LIMIT 1")->fetchColumn(),
            (int) $db->query("SELECT COUNT(*) FROM mcp_drive_fila WHERE tipo IN ('exportacao_diaria', 'backup_banco') AND estado = 'feito'")->fetchColumn()],
        [$hoje, json_encode(['dia' => $ontem]), 5]);
    [$saida2, $erros2, $codigo2] = comando([PHP_BINARY, $api . '/drive.php']);
    verificar('drive.php: segunda rodada no mesmo dia não repete o diário', [$codigo2, str_contains($saida2, 'diário')], [0, false]);
    [$saida, $erros, $codigo] = comando([PHP_BINARY, $api . '/drive.php', '--testar']);
    $testeTxt = arquivo_falso('Site', 'teste-ligacao.txt');
    verificar('drive.php --testar: código 0, passos 1 a 5 e "Tudo certo"', [$codigo, preg_match_all('/^\d\. /m', $saida), str_contains($saida, 'Tudo certo')], [0, 5, true]);
    verificar('drive.php --testar: lista a raiz e grava Site/teste-ligacao.txt com a data', [str_contains($saida, '- Voluntariado (pasta)'), str_starts_with(conteudo_falso($testeTxt), 'Ligação do site com o Drive testada em')], [true, true]);
    verificar('drive.php --testar: não imprime o token nem a chave', [str_contains($saida, 'tok-'), str_contains($saida, 'PRIVATE KEY')], [false, false]);
    [$saida, $erros, $codigo] = comando([PHP_BINARY, $api . '/drive.php'], ['MCP_CONFIG_GOOGLE_ARQUIVO' => $vazio]);
    verificar('drive.php: Drive desligado sai em silêncio com código 0', [$saida, $erros, $codigo], ['', '', 0]);
    [$saida, , $codigo] = comando([PHP_BINARY, $api . '/drive.php', '--testar'], ['MCP_CONFIG_GOOGLE_ARQUIVO' => $vazio]);
    verificar('drive.php --testar desligado explica o que falta e sai com 1', [str_contains($saida, 'GOOGLE_SA'), $codigo], [true, 1]);

    verificar('segurança: nenhuma requisição ao Google falso saiu sem supportsAllDrives (o servidor teria respondido 400)',
        count(array_filter(estado()['requisicoes'], static fn(array $r): bool => str_starts_with($r['caminho'], '/drive/') && ($r['query']['supportsAllDrives'] ?? '') !== 'true')), 0);
    verificar('servidor falso sem aviso nem erro do PHP', preg_match('/PHP (Warning|Notice|Deprecated|Fatal|Parse)/', (string) @file_get_contents("$tmp/servidor.log")), 0);
} finally {
    proc_terminate($servidor);
    proc_close($servidor);
    limpar($db);
    $db->exec('DROP TABLE IF EXISTS mcp_teste_lotes');
    $itens = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($itens as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($tmp);
}

printf("%d testes, %d falhas\n", $total, $falhas);
exit($falhas > 0 ? 1 : 0);
