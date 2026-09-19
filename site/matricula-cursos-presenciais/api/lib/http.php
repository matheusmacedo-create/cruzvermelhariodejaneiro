<?php
/** Respostas JSON, leitura e guarda das requisições, utilidades de texto. */
declare(strict_types=1);

const MCP_CORPO_MAX = 65536;
const MCP_TOKEN_TAMANHO = 40;

function mcp_json(array $dados, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mcp_falhar(int $status, string $mensagem, array $extra = []): never
{
    mcp_json(['ok' => false, 'erro' => $mensagem] + $extra, $status);
}

function mcp_exigir_metodo(string $metodo): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $metodo) {
        header('Allow: ' . $metodo);
        mcp_falhar(405, 'Método não permitido.');
    }
}

/** Origens que podem chamar os endpoints a partir do navegador: o próprio site (apex e www). */
function mcp_origens_permitidas(): array
{
    $site = mcp_site_url();
    $host = preg_replace('/^www\./', '', (string) parse_url($site, PHP_URL_HOST));
    return array_values(array_unique([$site, "https://$host", "https://www.$host"]));
}

/** Lê até MCP_CORPO_MAX bytes do corpo; acima disso, 413. */
function mcp_corpo_bruto(): string
{
    $declarado = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declarado > MCP_CORPO_MAX) {
        mcp_falhar(413, 'Requisição grande demais.');
    }
    $bruto = (string) file_get_contents('php://input', false, null, 0, MCP_CORPO_MAX + 1);
    if (strlen($bruto) > MCP_CORPO_MAX) {
        mcp_falhar(413, 'Requisição grande demais.');
    }
    return $bruto;
}

/**
 * POST JSON vindo das páginas do site. Além do método, exige Content-Type JSON e, quando o
 * navegador informa a origem (Origin / Sec-Fetch-Site), que ela seja o próprio site. Não é
 * autenticação (não há sessão a proteger), é só para nenhum outro site conseguir usar o
 * checkout como se fosse o nosso.
 */
function mcp_exigir_post_json(): array
{
    mcp_exigir_metodo('POST');
    $tipo = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
    if (!str_starts_with($tipo, 'application/json')) {
        mcp_falhar(415, 'Envie o corpo em JSON.');
    }
    $origem = rtrim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
    if ($origem !== '' && $origem !== 'null' && !in_array($origem, mcp_origens_permitidas(), true)) {
        mcp_falhar(403, 'Origem não permitida.');
    }
    $sitio = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($sitio !== '' && !in_array($sitio, ['same-origin', 'same-site', 'none'], true)) {
        mcp_falhar(403, 'Origem não permitida.');
    }
    $dados = json_decode(mcp_corpo_bruto() ?: '', true);
    if (!is_array($dados)) {
        mcp_falhar(400, 'Corpo da requisição inválido.');
    }
    return $dados;
}

/** Postback de servidor para servidor: JSON ou formulário, sem exigência de origem. */
function mcp_ler_postback(): array
{
    mcp_exigir_metodo('POST');
    $bruto = mcp_corpo_bruto();
    $dados = json_decode($bruto ?: '', true);
    if (is_array($dados)) {
        return $dados;
    }
    return is_array($_POST) && $_POST ? $_POST : [];
}

function mcp_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

// ----------------------------------------------------------------------------- texto e valores
function mcp_digitos(string $texto): string
{
    return preg_replace('/\D+/', '', $texto) ?? '';
}

/** Texto de formulário: uma linha, espaços normalizados, tamanho limitado. */
function mcp_texto(mixed $valor, int $limite): string
{
    $texto = is_string($valor) ? $valor : (is_scalar($valor) ? (string) $valor : '');
    $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? '');
    return mb_substr($texto, 0, $limite);
}

/**
 * Texto livre de várias linhas (mensagem do chat): quebras de linha normalizadas, sem caracteres de
 * controle, sem mais de uma linha em branco seguida, tamanho limitado.
 */
function mcp_texto_longo(mixed $valor, int $limite): string
{
    $texto = is_string($valor) ? $valor : (is_scalar($valor) ? (string) $valor : '');
    $texto = str_replace(["\r\n", "\r"], "\n", $texto);
    $texto = preg_replace('/[\t ]+/', ' ', $texto) ?? '';
    $texto = preg_replace('/(?!\n)\p{Cc}/u', '', $texto) ?? '';
    $texto = preg_replace('/ *\n */', "\n", $texto) ?? '';
    $texto = preg_replace('/\n{3,}/', "\n\n", $texto) ?? '';
    return mb_substr(trim($texto), 0, $limite);
}

function mcp_cpf_valido(string $cpf): bool
{
    $cpf = mcp_digitos($cpf);
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }
    for ($t = 9; $t < 11; $t++) {
        $soma = 0;
        for ($i = 0; $i < $t; $i++) {
            $soma += (int) $cpf[$i] * (($t + 1) - $i);
        }
        if ((int) $cpf[$t] !== ((10 * $soma) % 11) % 10) {
            return false;
        }
    }
    return true;
}

/** Telefone brasileiro com DDD: 10 ou 11 dígitos, com ou sem o 55 na frente. Devolve '' se inválido. */
function mcp_telefone(string $bruto): string
{
    $digitos = mcp_digitos($bruto);
    if (strlen($digitos) === 13 && str_starts_with($digitos, '55')) {
        $digitos = substr($digitos, 2);
    }
    return in_array(strlen($digitos), [10, 11], true) && $digitos[0] !== '0' ? $digitos : '';
}

/** Luhn: pega erro de digitação no número do cartão antes de ir ao gateway. */
function mcp_luhn_valido(string $digitos): bool
{
    if ($digitos === '' || !ctype_digit($digitos)) {
        return false;
    }
    $soma = 0;
    $dobrar = false;
    for ($i = strlen($digitos) - 1; $i >= 0; $i--) {
        $n = (int) $digitos[$i];
        if ($dobrar) {
            $n = $n * 2 > 9 ? $n * 2 - 9 : $n * 2;
        }
        $soma += $n;
        $dobrar = !$dobrar;
    }
    return $soma % 10 === 0;
}

function mcp_brl(int $centavos): string
{
    return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
}

function mcp_token_novo(): string
{
    return bin2hex(random_bytes(MCP_TOKEN_TAMANHO / 2));
}

function mcp_token_valido(string $token): bool
{
    return (bool) preg_match('/^[a-f0-9]{' . MCP_TOKEN_TAMANHO . '}$/', $token);
}

function mcp_agora(): string
{
    return gmdate('Y-m-d H:i:s');
}

function mcp_escapar(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mcp_primeiro_nome(string $nome): string
{
    $partes = preg_split('/\s+/', trim($nome)) ?: [];
    return $partes[0] ?? $nome;
}
