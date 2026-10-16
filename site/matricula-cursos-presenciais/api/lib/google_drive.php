<?php
/**
 * Cliente mínimo do Google Drive e do Google Sheets com conta de serviço, sem biblioteca (a Hostinger não tem
 * Composer neste projeto). Só o que a ligação com o Drive precisa:
 *  - token de acesso: JWT RS256 assinado com a chave privada da conta de serviço (openssl_sign) e trocado no
 *    endpoint de token do Google; fica em cache num arquivo com permissão 0600 e é renovado 5 minutos antes de vencer;
 *  - pastas: criar, procurar pelo nome dentro de um pai, listar;
 *  - arquivos: envio multipart (até 5 MB) ou retomável em blocos (maiores), e troca do conteúdo de um arquivo que já
 *    existe (PATCH do upload);
 *  - planilha: acrescentar uma linha a uma aba (values:append).
 *
 * Toda chamada leva supportsAllDrives=true (a pasta raiz mora num Drive compartilhado) e as listagens também
 * includeItemsFromAllDrives=true. Erro de HTTP vira McpDriveErro com o status e um resumo do corpo (300 caracteres).
 *
 * O token e a chave privada NUNCA vão para o log nem para a mensagem de erro: as mensagens citam só método, caminho
 * e status. GOOGLE_API_URL e GOOGLE_TOKEN_URL existem para apontar a um servidor falso nos testes locais.
 */
declare(strict_types=1);

const MCP_GOOGLE_ESCOPOS = 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/spreadsheets';
const MCP_GOOGLE_TOKEN_URL_PADRAO = 'https://oauth2.googleapis.com/token';
const MCP_GOOGLE_API_URL_PADRAO = 'https://www.googleapis.com';
/** Acima disto o envio é retomável (em blocos); até aqui, multipart numa chamada só. */
const MCP_GOOGLE_MULTIPART_MAX = 5 * 1024 * 1024;
/** Tamanho de cada bloco do envio retomável: múltiplo de 256 KiB, como o Google exige. */
const MCP_GOOGLE_BLOCO = 8 * 1024 * 1024;
const MCP_GOOGLE_TIMEOUT = 20;
const MCP_GOOGLE_TIMEOUT_CONEXAO = 10;
/** Um bloco de 8 MB numa hospedagem compartilhada pode levar mais que 20 s; só os blocos ganham este prazo. */
const MCP_GOOGLE_TIMEOUT_BLOCO = 120;
const MCP_GOOGLE_RENOVAR_ANTES = 300;
const MCP_GOOGLE_ERRO_MAX = 300;
const MCP_DRIVE_MIME_PASTA = 'application/vnd.google-apps.folder';

/** Falha de HTTP ou de configuração nas chamadas ao Google. $corpo é o resumo da resposta (sem segredo). */
final class McpDriveErro extends RuntimeException
{
    public function __construct(string $mensagem, public readonly int $status = 0, public readonly string $corpo = '')
    {
        parent::__construct($mensagem . ($corpo !== '' ? ' — ' . $corpo : ''), $status);
    }
}

// ----------------------------------------------------------------------------- configuração
/** A conta de serviço (GOOGLE_SA): o array do JSON baixado do Google Cloud, ou o JSON em texto. */
function mcp_google_sa(): array
{
    $sa = mcp_cfg('GOOGLE_SA');
    if (is_string($sa)) {
        $sa = json_decode($sa, true);
    }
    if (!is_array($sa) || (string) ($sa['client_email'] ?? '') === '' || (string) ($sa['private_key'] ?? '') === '') {
        throw new McpDriveErro('GOOGLE_SA sem client_email ou private_key (config-google.php).');
    }
    return $sa;
}

function mcp_google_api_url(): string
{
    return rtrim((string) mcp_cfg('GOOGLE_API_URL', MCP_GOOGLE_API_URL_PADRAO), '/');
}

function mcp_google_token_url(array $sa): string
{
    return (string) mcp_cfg('GOOGLE_TOKEN_URL', (string) ($sa['token_uri'] ?? '') ?: MCP_GOOGLE_TOKEN_URL_PADRAO);
}

/** Onde o token fica em cache. MCP_GOOGLE_TOKEN_ARQUIVO (ambiente) muda o lugar nos testes. */
function mcp_google_token_arquivo(): string
{
    return getenv('MCP_GOOGLE_TOKEN_ARQUIVO') ?: dirname(__DIR__) . '/.google-token.json';
}

/** Limiar do multipart e tamanho do bloco: os testes baixam os dois pelo ambiente para exercitar o envio retomável. */
function mcp_google_limiar_multipart(): int
{
    $env = (int) getenv('MCP_GOOGLE_MULTIPART_MAX');
    return $env > 0 ? $env : MCP_GOOGLE_MULTIPART_MAX;
}

function mcp_google_bloco(): int
{
    $env = (int) getenv('MCP_GOOGLE_BLOCO');
    return $env >= 262144 ? $env - ($env % 262144) : MCP_GOOGLE_BLOCO;
}

// ----------------------------------------------------------------------------- token
function mcp_google_base64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/** JWT RS256 da conta de serviço: iss = client_email, escopos do Drive e do Sheets, aud = endpoint de token, 1 hora. */
function mcp_google_jwt(array $sa, string $tokenUrl, ?int $agora = null): string
{
    $agora ??= time();
    $cabecalho = mcp_google_base64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $corpo = mcp_google_base64url((string) json_encode([
        'iss' => (string) $sa['client_email'],
        'scope' => MCP_GOOGLE_ESCOPOS,
        'aud' => $tokenUrl,
        'iat' => $agora,
        'exp' => $agora + 3600,
    ]));
    $chave = openssl_pkey_get_private((string) $sa['private_key']);
    if ($chave === false) {
        // A mensagem não repete a chave: só diz que ela não serviu.
        throw new McpDriveErro('A private_key de GOOGLE_SA não pôde ser lida (copie o JSON inteiro da conta de serviço).');
    }
    $assinatura = '';
    if (!openssl_sign($cabecalho . '.' . $corpo, $assinatura, $chave, OPENSSL_ALGO_SHA256)) {
        throw new McpDriveErro('Não foi possível assinar o JWT com a chave da conta de serviço.');
    }
    return $cabecalho . '.' . $corpo . '.' . mcp_google_base64url($assinatura);
}

/**
 * Token de acesso válido: o do cache, se ainda tem mais de 5 minutos; senão um novo, trocado pelo JWT. O arquivo
 * do cache nasce com 0600 (só o usuário do PHP lê). $renovar força um token novo (depois de um 401).
 */
function mcp_google_token(bool $renovar = false): string
{
    static $memoria = null;
    $agora = time();
    if (!$renovar && is_array($memoria) && $memoria['expira_em'] - MCP_GOOGLE_RENOVAR_ANTES > $agora) {
        return $memoria['token'];
    }
    $arquivo = mcp_google_token_arquivo();
    if (!$renovar && is_file($arquivo)) {
        $cache = json_decode((string) @file_get_contents($arquivo), true);
        if (is_array($cache) && is_string($cache['access_token'] ?? null) && (int) ($cache['expira_em'] ?? 0) - MCP_GOOGLE_RENOVAR_ANTES > $agora) {
            $memoria = ['token' => $cache['access_token'], 'expira_em' => (int) $cache['expira_em']];
            return $memoria['token'];
        }
    }
    $sa = mcp_google_sa();
    $tokenUrl = mcp_google_token_url($sa);
    $resposta = mcp_google_http('POST', $tokenUrl, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => mcp_google_jwt($sa, $tokenUrl, $agora),
    ]));
    $dados = json_decode($resposta['corpo'], true);
    if ($resposta['status'] !== 200 || !is_array($dados) || (string) ($dados['access_token'] ?? '') === '') {
        throw new McpDriveErro('Token do Google recusado (POST ' . (string) parse_url($tokenUrl, PHP_URL_PATH) . ', HTTP ' . $resposta['status'] . ')',
            $resposta['status'], mcp_google_resumir($resposta['corpo']));
    }
    $expiraEm = $agora + max(60, (int) ($dados['expires_in'] ?? 3600));
    $memoria = ['token' => (string) $dados['access_token'], 'expira_em' => $expiraEm];
    // Escreve num arquivo ao lado e renomeia: ninguém lê um cache pela metade. A permissão vem antes do conteúdo.
    $temporario = $arquivo . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($temporario, json_encode(['access_token' => $memoria['token'], 'expira_em' => $expiraEm]), LOCK_EX) !== false) {
        @chmod($temporario, 0600);
        if (!@rename($temporario, $arquivo)) {
            @unlink($temporario);
        }
    }
    @chmod($arquivo, 0600);
    return $memoria['token'];
}

/** Resumo de um corpo de resposta para a mensagem de erro: uma linha, sem controle, até 300 caracteres. */
function mcp_google_resumir(string $corpo): string
{
    $texto = trim(preg_replace('/\s+/', ' ', preg_replace('/\p{Cc}+/u', ' ', $corpo) ?? $corpo) ?? $corpo);
    return mb_strlen($texto) > MCP_GOOGLE_ERRO_MAX ? mb_substr($texto, 0, MCP_GOOGLE_ERRO_MAX - 1) . '…' : $texto;
}

// ----------------------------------------------------------------------------- HTTP
/**
 * Uma requisição com curl: conexão em 10 s, resposta em $timeout (20 s). Devolve status, corpo e cabeçalhos (nomes em
 * minúsculas). Falha de rede vira McpDriveErro com status 0. "Expect:" vazio evita a espera de 1 s do 100-continue.
 */
function mcp_google_http(string $metodo, string $url, array $cabecalhos = [], ?string $corpo = null, int $timeout = MCP_GOOGLE_TIMEOUT): array
{
    $recebidos = [];
    $ch = curl_init($url);
    if ($ch === false) {
        throw new McpDriveErro('curl indisponível.');
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => MCP_GOOGLE_TIMEOUT_CONEXAO,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => array_merge($cabecalhos, ['Expect:']),
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
    $resposta = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erro = curl_error($ch);
    curl_close($ch);
    if ($resposta === false) {
        throw new McpDriveErro(sprintf('%s %s sem resposta', $metodo, (string) parse_url($url, PHP_URL_PATH)), 0, mcp_google_resumir($erro));
    }
    return ['status' => $status, 'corpo' => (string) $resposta, 'cabecalhos' => $recebidos];
}

/**
 * Chamada autenticada ao Google. Um 401 renova o token e tenta uma vez mais; status >= 400 vira McpDriveErro.
 * Devolve status, corpo, cabeçalhos e 'json' (o corpo decodificado, ou null).
 */
function mcp_google_chamar(string $metodo, string $url, array $cabecalhos = [], ?string $corpo = null, int $timeout = MCP_GOOGLE_TIMEOUT): array
{
    $resposta = null;
    foreach ([false, true] as $renovar) {
        $token = mcp_google_token($renovar);
        $resposta = mcp_google_http($metodo, $url, array_merge(['Authorization: Bearer ' . $token], $cabecalhos), $corpo, $timeout);
        if ($resposta['status'] !== 401) {
            break;
        }
    }
    if ($resposta['status'] >= 400) {
        throw new McpDriveErro(sprintf('%s %s respondeu HTTP %d', $metodo, (string) parse_url($url, PHP_URL_PATH), $resposta['status']),
            $resposta['status'], mcp_google_resumir($resposta['corpo']));
    }
    $resposta['json'] = json_decode($resposta['corpo'], true);
    return $resposta;
}

/** URL da API do Drive com supportsAllDrives=true sempre (a raiz pode estar num Drive compartilhado). */
function mcp_drive_url(string $caminho, array $query = []): string
{
    return mcp_google_api_url() . $caminho . '?' . http_build_query(['supportsAllDrives' => 'true'] + $query, '', '&', PHP_QUERY_RFC3986);
}

/** Valor para a cláusula q do Drive: barra e aspas simples escapadas. */
function mcp_drive_escapar_q(string $valor): string
{
    return str_replace(['\\', "'"], ['\\\\', "\\'"], $valor);
}

// ----------------------------------------------------------------------------- pastas e listagens
/**
 * Procura, dentro da pasta $paiId, um item com este nome (e este MIME, se dado), fora da lixeira.
 * @return array{id: string, name: string, mimeType: string}|null
 */
function mcp_drive_buscar(string $nome, string $paiId, ?string $mime = null): ?array
{
    $q = sprintf("name = '%s' and '%s' in parents and trashed = false", mcp_drive_escapar_q($nome), mcp_drive_escapar_q($paiId));
    if ($mime !== null) {
        $q .= sprintf(" and mimeType = '%s'", mcp_drive_escapar_q($mime));
    }
    $resposta = mcp_google_chamar('GET', mcp_drive_url('/drive/v3/files', [
        'q' => $q, 'fields' => 'files(id,name,mimeType)', 'pageSize' => 10, 'includeItemsFromAllDrives' => 'true',
    ]));
    foreach ($resposta['json']['files'] ?? [] as $item) {
        if (is_array($item) && (string) ($item['id'] ?? '') !== '') {
            return ['id' => (string) $item['id'], 'name' => (string) ($item['name'] ?? ''), 'mimeType' => (string) ($item['mimeType'] ?? '')];
        }
    }
    return null;
}

function mcp_drive_pasta_buscar(string $nome, string $paiId): ?string
{
    return mcp_drive_buscar($nome, $paiId, MCP_DRIVE_MIME_PASTA)['id'] ?? null;
}

function mcp_drive_pasta_criar(string $nome, string $paiId): string
{
    $resposta = mcp_google_chamar('POST', mcp_drive_url('/drive/v3/files', ['fields' => 'id']), ['Content-Type: application/json; charset=UTF-8'],
        (string) json_encode(['name' => $nome, 'mimeType' => MCP_DRIVE_MIME_PASTA, 'parents' => [$paiId]], JSON_UNESCAPED_UNICODE));
    $id = (string) ($resposta['json']['id'] ?? '');
    if ($id === '') {
        throw new McpDriveErro('A pasta foi criada sem id na resposta.', $resposta['status'], mcp_google_resumir($resposta['corpo']));
    }
    return $id;
}

/**
 * Itens de uma pasta (pastas primeiro, por nome), fora da lixeira.
 * @return list<array{id: string, name: string, mimeType: string}>
 */
function mcp_drive_listar(string $paiId, int $maximo = 100): array
{
    $resposta = mcp_google_chamar('GET', mcp_drive_url('/drive/v3/files', [
        'q' => sprintf("'%s' in parents and trashed = false", mcp_drive_escapar_q($paiId)),
        'fields' => 'files(id,name,mimeType)', 'orderBy' => 'folder,name', 'pageSize' => max(1, min(1000, $maximo)),
        'includeItemsFromAllDrives' => 'true',
    ]));
    $itens = [];
    foreach ($resposta['json']['files'] ?? [] as $item) {
        if (is_array($item)) {
            $itens[] = ['id' => (string) ($item['id'] ?? ''), 'name' => (string) ($item['name'] ?? ''), 'mimeType' => (string) ($item['mimeType'] ?? '')];
        }
    }
    return $itens;
}

/** Nome e tipo de um item pelo id (a pasta raiz, no --testar). */
function mcp_drive_info(string $id): array
{
    $resposta = mcp_google_chamar('GET', mcp_drive_url('/drive/v3/files/' . rawurlencode($id), ['fields' => 'id,name,mimeType,driveId']));
    return is_array($resposta['json']) ? $resposta['json'] : [];
}

// ----------------------------------------------------------------------------- envio de arquivos
/**
 * Envia um arquivo para a pasta (ou troca o conteúdo do arquivo $substituirId, mantendo id e nome). Até o limiar,
 * multipart (metadados + conteúdo numa chamada); acima, envio retomável em blocos. Devolve o id do arquivo.
 */
function mcp_drive_upload(string $pastaId, string $nome, string $conteudo, string $mime, ?string $substituirId = null): string
{
    if (strlen($conteudo) > mcp_google_limiar_multipart()) {
        return mcp_drive_upload_retomavel($pastaId, $nome, $conteudo, $mime, $substituirId);
    }
    return $substituirId !== null && $substituirId !== ''
        ? mcp_drive_upload_conteudo($substituirId, $conteudo, $mime)
        : mcp_drive_upload_multipart($pastaId, $nome, $conteudo, $mime);
}

/** Arquivo novo numa chamada só: multipart/related com a parte JSON dos metadados e a parte do conteúdo. */
function mcp_drive_upload_multipart(string $pastaId, string $nome, string $conteudo, string $mime): string
{
    $limite = 'mcp_' . bin2hex(random_bytes(12));
    $metadados = (string) json_encode(['name' => $nome, 'parents' => [$pastaId]], JSON_UNESCAPED_UNICODE);
    $corpo = "--$limite\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$metadados\r\n"
        . "--$limite\r\nContent-Type: $mime\r\n\r\n$conteudo\r\n--$limite--";
    $resposta = mcp_google_chamar('POST', mcp_drive_url('/upload/drive/v3/files', ['uploadType' => 'multipart', 'fields' => 'id']),
        ['Content-Type: multipart/related; boundary=' . $limite, 'Content-Length: ' . strlen($corpo)], $corpo, MCP_GOOGLE_TIMEOUT_BLOCO);
    return mcp_drive_id_da_resposta($resposta);
}

/** Troca só o conteúdo de um arquivo existente (PATCH do upload, uploadType=media): o id e o nome ficam. */
function mcp_drive_upload_conteudo(string $arquivoId, string $conteudo, string $mime): string
{
    $resposta = mcp_google_chamar('PATCH', mcp_drive_url('/upload/drive/v3/files/' . rawurlencode($arquivoId), ['uploadType' => 'media', 'fields' => 'id']),
        ['Content-Type: ' . $mime, 'Content-Length: ' . strlen($conteudo)], $conteudo, MCP_GOOGLE_TIMEOUT_BLOCO);
    return mcp_drive_id_da_resposta($resposta) ?: $arquivoId;
}

/**
 * Envio retomável: a primeira chamada manda os metadados e recebe em Location a URL da sessão; depois cada bloco vai
 * num PUT com Content-Range, o Google responde 308 enquanto falta e 200/201 com o arquivo no fim. Um bloco que falha
 * por rede é tentado de novo uma vez a partir do que o Google diz ter recebido (cabeçalho Range).
 */
function mcp_drive_upload_retomavel(string $pastaId, string $nome, string $conteudo, string $mime, ?string $substituirId = null): string
{
    $total = strlen($conteudo);
    $trocar = $substituirId !== null && $substituirId !== '';
    $url = mcp_drive_url('/upload/drive/v3/files' . ($trocar ? '/' . rawurlencode($substituirId) : ''), ['uploadType' => 'resumable', 'fields' => 'id']);
    $metadados = $trocar ? [] : ['name' => $nome, 'parents' => [$pastaId]];
    $inicio = mcp_google_chamar($trocar ? 'PATCH' : 'POST', $url, [
        'Content-Type: application/json; charset=UTF-8',
        'X-Upload-Content-Type: ' . $mime,
        'X-Upload-Content-Length: ' . $total,
    ], (string) json_encode($metadados, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT));
    $sessao = (string) ($inicio['cabecalhos']['location'] ?? '');
    if ($sessao === '') {
        throw new McpDriveErro('O envio retomável começou sem a URL da sessão (Location).', $inicio['status'], mcp_google_resumir($inicio['corpo']));
    }
    $bloco = mcp_google_bloco();
    $posicao = 0;
    $tentativasRede = 0;
    while (true) {
        $tamanho = min($bloco, $total - $posicao);
        $fim = $posicao + $tamanho - 1;
        try {
            // A sessão já está autenticada pela URL; o token vai mesmo assim, como o Google recomenda.
            $resposta = mcp_google_http('PUT', $sessao, [
                'Authorization: Bearer ' . mcp_google_token(),
                'Content-Type: ' . $mime,
                'Content-Length: ' . $tamanho,
                sprintf('Content-Range: bytes %d-%d/%d', $posicao, $fim, $total),
            ], substr($conteudo, $posicao, $tamanho), MCP_GOOGLE_TIMEOUT_BLOCO);
        } catch (McpDriveErro $e) {
            if (++$tentativasRede > 1) {
                throw $e;
            }
            $posicao = mcp_drive_retomar_posicao($sessao, $total);
            continue;
        }
        if ($resposta['status'] === 308) {
            // "Range: bytes=0-N" diz até onde chegou; sem o cabeçalho, nada do bloco ficou.
            $posicao = preg_match('/bytes=0-(\d+)/', (string) ($resposta['cabecalhos']['range'] ?? ''), $m) ? (int) $m[1] + 1 : $posicao;
            if ($posicao >= $total) {
                throw new McpDriveErro('O Google pediu mais bytes do que o arquivo tem.', 308);
            }
            continue;
        }
        if ($resposta['status'] >= 400) {
            throw new McpDriveErro(sprintf('PUT %s (bloco %d-%d) respondeu HTTP %d', (string) parse_url($sessao, PHP_URL_PATH), $posicao, $fim, $resposta['status']),
                $resposta['status'], mcp_google_resumir($resposta['corpo']));
        }
        $resposta['json'] = json_decode($resposta['corpo'], true);
        return mcp_drive_id_da_resposta($resposta) ?: (string) $substituirId;
    }
}

/** Pergunta à sessão retomável quantos bytes já chegaram (PUT vazio com Content-Range: bytes * / total). */
function mcp_drive_retomar_posicao(string $sessao, int $total): int
{
    $resposta = mcp_google_http('PUT', $sessao, [
        'Authorization: Bearer ' . mcp_google_token(), 'Content-Length: 0', 'Content-Range: bytes */' . $total,
    ], '');
    if ($resposta['status'] === 308 && preg_match('/bytes=0-(\d+)/', (string) ($resposta['cabecalhos']['range'] ?? ''), $m)) {
        return (int) $m[1] + 1;
    }
    return 0;
}

/** Apaga um arquivo (ou pasta) pelo id. Um 404 conta como apagado: o que se queria é que ele não exista mais. */
function mcp_drive_apagar_arquivo(string $id): void
{
    try {
        mcp_google_chamar('DELETE', mcp_drive_url('/drive/v3/files/' . rawurlencode($id)));
    } catch (McpDriveErro $e) {
        if ($e->status !== 404) {
            throw $e;
        }
    }
}

function mcp_drive_id_da_resposta(array $resposta): string
{
    $id = (string) ($resposta['json']['id'] ?? '');
    if ($id === '' && ($resposta['status'] ?? 0) !== 200 && ($resposta['status'] ?? 0) !== 201) {
        throw new McpDriveErro('O envio terminou sem o id do arquivo.', (int) ($resposta['status'] ?? 0), mcp_google_resumir((string) ($resposta['corpo'] ?? '')));
    }
    return $id;
}

// ----------------------------------------------------------------------------- planilha
/**
 * Acrescenta uma linha ao fim da aba (values:append, USER_ENTERED: datas e números entram como numa digitação). O nome
 * da aba vai entre aspas simples (aspas internas dobradas), como o Sheets exige para nomes com espaço ou acento.
 */
function mcp_sheets_acrescentar(string $planilhaId, string $aba, array $linha): void
{
    $intervalo = "'" . str_replace("'", "''", $aba) . "'!A1";
    $url = mcp_google_api_url() . '/v4/spreadsheets/' . rawurlencode($planilhaId) . '/values/' . rawurlencode($intervalo) . ':append?'
        . http_build_query(['valueInputOption' => 'USER_ENTERED', 'insertDataOption' => 'INSERT_ROWS'], '', '&', PHP_QUERY_RFC3986);
    $valores = array_map(static fn(mixed $v): string|int|float => is_int($v) || is_float($v) ? $v : (string) $v, array_values($linha));
    mcp_google_chamar('POST', $url, ['Content-Type: application/json; charset=UTF-8'],
        (string) json_encode(['majorDimension' => 'ROWS', 'values' => [$valores]], JSON_UNESCAPED_UNICODE));
}

/** O nome da aba como o Sheets quer num intervalo: entre aspas simples, aspas internas dobradas. */
function mcp_sheets_intervalo(string $aba, string $celulas): string
{
    return "'" . str_replace("'", "''", $aba) . "'!" . $celulas;
}

function mcp_sheets_url(string $planilhaId, string $resto, array $query = []): string
{
    return mcp_google_api_url() . '/v4/spreadsheets/' . rawurlencode($planilhaId) . $resto
        . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
}

/**
 * sheetId numérico de uma aba (o batchUpdate pede o id, não o nome). Vem de GET spreadsheets/{id}?fields=sheets.properties
 * e fica em mcp_chaves ('drive_aba:' + 30 dígitos do md5 de "planilha|aba", para caber nos 40 caracteres da coluna)
 * quando lib/drive_espelho.php está carregado. $renovar ignora o cache (depois de um 400 por aba recriada).
 */
function mcp_sheets_aba_id(string $planilhaId, string $aba, bool $renovar = false): int
{
    $chave = 'drive_aba:' . substr(md5($planilhaId . '|' . $aba), 0, 30);
    $cache = function_exists('mcp_drive_chave_ler');
    if (!$renovar && $cache) {
        $guardado = mcp_drive_chave_ler($chave);
        if ($guardado !== null && $guardado !== '' && ctype_digit($guardado)) {
            return (int) $guardado;
        }
    }
    $resposta = mcp_google_chamar('GET', mcp_sheets_url($planilhaId, '', ['fields' => 'sheets.properties']));
    foreach ($resposta['json']['sheets'] ?? [] as $folha) {
        $propriedades = is_array($folha) ? ($folha['properties'] ?? []) : [];
        if ((string) ($propriedades['title'] ?? '') === $aba && isset($propriedades['sheetId'])) {
            if ($cache) {
                mcp_drive_chave_gravar($chave, (string) (int) $propriedades['sheetId']);
            }
            return (int) $propriedades['sheetId'];
        }
    }
    throw new McpDriveErro(sprintf('A planilha não tem a aba "%s".', $aba), 404);
}

/**
 * Remove a primeira linha da aba cuja coluna A é igual a $valorColunaA (values.get de 'Aba'!A:A e batchUpdate com
 * deleteDimension ROWS). Devolve false se nenhuma linha tem esse valor. Comparação exata, sem espaços nas pontas.
 */
function mcp_drive_planilha_remover_linha(string $planilhaId, string $aba, string $valorColunaA): bool
{
    $procurado = trim($valorColunaA);
    if ($procurado === '') {
        return false;
    }
    $resposta = mcp_google_chamar('GET', mcp_sheets_url($planilhaId, '/values/' . rawurlencode(mcp_sheets_intervalo($aba, 'A:A')), ['majorDimension' => 'ROWS']));
    $indice = null;
    foreach ($resposta['json']['values'] ?? [] as $i => $linha) {
        if (is_array($linha) && trim((string) ($linha[0] ?? '')) === $procurado) {
            $indice = (int) $i;
            break;
        }
    }
    if ($indice === null) {
        return false;
    }
    foreach ([false, true] as $renovar) {
        $pedido = ['requests' => [['deleteDimension' => ['range' => [
            'sheetId' => mcp_sheets_aba_id($planilhaId, $aba, $renovar), 'dimension' => 'ROWS', 'startIndex' => $indice, 'endIndex' => $indice + 1,
        ]]]]];
        try {
            mcp_google_chamar('POST', mcp_sheets_url($planilhaId, ':batchUpdate'), ['Content-Type: application/json; charset=UTF-8'], (string) json_encode($pedido));
            return true;
        } catch (McpDriveErro $e) {
            // 400 com o sheetId do cache: a aba foi apagada e recriada; busca o id de novo e tenta uma vez mais.
            if ($renovar || $e->status !== 400) {
                throw $e;
            }
        }
    }
    return true;
}
