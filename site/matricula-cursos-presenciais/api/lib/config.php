<?php
/** Configuração, catálogo e preços. */
declare(strict_types=1);

function mcp_config(): array
{
    static $config = null;
    if ($config === null) {
        $arquivo = getenv('MCP_CONFIG_ARQUIVO') ?: dirname(__DIR__) . '/config.php';
        if (!is_file($arquivo)) {
            mcp_falhar(500, 'Checkout não configurado.');
        }
        $lido = require $arquivo;
        $config = is_array($lido) ? $lido : [];
        // A chave da escola, as do WhatsApp e o token da API de Conversões da Meta ficam em arquivos à parte (só
        // no servidor, fora do Git), para não mexer no config.php. De config-escola.php só valem as chaves
        // ESCOLA_* e SITE_HORARIOS_TOKEN; de config-whatsapp.php, só as WHATSAPP_*; de config-meta.php, só as
        // META_*. O resto continua no config.php.
        $extras = [
            [getenv('MCP_CONFIG_ESCOLA_ARQUIVO') ?: dirname(__DIR__) . '/config-escola.php', static fn(string $c): bool => str_starts_with($c, 'ESCOLA_') || $c === 'SITE_HORARIOS_TOKEN'],
            [getenv('MCP_CONFIG_WHATSAPP_ARQUIVO') ?: dirname(__DIR__) . '/config-whatsapp.php', static fn(string $c): bool => str_starts_with($c, 'WHATSAPP_')],
            [getenv('MCP_CONFIG_META_ARQUIVO') ?: dirname(__DIR__) . '/config-meta.php', static fn(string $c): bool => str_starts_with($c, 'META_')],
        ];
        foreach ($extras as [$arquivoExtra, $vale]) {
            if (is_file($arquivoExtra)) {
                // Um arquivo extra escrito com erro (vírgula, aspas) desliga só o que ele configura; o checkout segue.
                try {
                    $extra = require $arquivoExtra;
                } catch (Throwable $e) {
                    error_log('[matricula] ' . basename($arquivoExtra) . ' ignorado: ' . get_class($e) . ' na linha ' . $e->getLine());
                    continue;
                }
                foreach (is_array($extra) ? $extra : [] as $chave => $valor) {
                    if (is_string($chave) && $vale($chave)) {
                        $config[$chave] = $valor;
                    }
                }
            }
        }
    }
    return $config;
}

function mcp_cfg(string $chave, mixed $padrao = null): mixed
{
    $config = mcp_config();
    if (!array_key_exists($chave, $config) || $config[$chave] === '' || $config[$chave] === null) {
        return $padrao;
    }
    return $config[$chave];
}

function mcp_site_url(): string
{
    return rtrim((string) mcp_cfg('SITE_URL', 'https://cruzvermelhariodejaneiro.org'), '/');
}

function mcp_url_pagina(string $pagina, string $token = ''): string
{
    $url = mcp_site_url() . '/matricula-cursos-presenciais/' . $pagina . '/';
    return $token !== '' ? $url . '?t=' . rawurlencode($token) : $url;
}

/** Catálogo publicado: o mesmo cursos.json que gera a página de matrícula. */
function mcp_catalogo(): array
{
    static $catalogo = null;
    if ($catalogo === null) {
        $arquivo = getenv('MCP_CATALOGO_ARQUIVO') ?: dirname(__DIR__, 2) . '/cursos.json';
        $dados = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;
        $catalogo = is_array($dados) ? $dados : ['cursos' => [], 'inscricao_centavos' => 9900, 'grupos' => []];
    }
    return $catalogo;
}

/**
 * As perguntas que o chat pode responder sozinho, para conferir o que a pessoa diz ter lido.
 *
 * O navegador manda só o texto da pergunta; aqui ele é confrontado com o que existe de verdade
 * (a FAQ do curso no catálogo e a FAQ da home marcada com "chat"). Texto que não bate é
 * descartado: nada vindo do navegador entra num e-mail sem passar por esta lista.
 */
function mcp_perguntas_conhecidas(): array
{
    static $lista = null;
    if ($lista !== null) {
        return $lista;
    }
    $lista = [];
    foreach (mcp_catalogo()['cursos'] as $curso) {
        foreach ($curso['faq'] ?? [] as $q) {
            if (!empty($q['pergunta'])) {
                $lista[(string) $q['pergunta']] = (string) ($q['resposta'] ?? '');
            }
        }
    }
    $faq = dirname(__DIR__, 3) . '/faq-home.json';
    $dados = is_readable($faq) ? json_decode((string) file_get_contents($faq), true) : null;
    foreach ($dados['grupos'] ?? [] as $grupo) {
        foreach ($grupo['perguntas'] ?? [] as $q) {
            if (!empty($q['chat']) && !empty($q['pergunta'])) {
                $lista[(string) $q['pergunta']] = (string) ($q['resposta'] ?? '');
            }
        }
    }
    return $lista;
}

function mcp_curso(string $slug): ?array
{
    foreach (mcp_catalogo()['cursos'] as $curso) {
        if (($curso['slug'] ?? '') === $slug) {
            return $curso;
        }
    }
    return null;
}

function mcp_modo_teste(): bool
{
    return (int) mcp_cfg('PRECO_TESTE_CENTAVOS', 0) > 0;
}

/** Valor da inscrição em centavos: preço de teste > config > catálogo. */
function mcp_inscricao_centavos(): int
{
    $teste = (int) mcp_cfg('PRECO_TESTE_CENTAVOS', 0);
    if ($teste > 0) {
        return $teste;
    }
    $config = (int) mcp_cfg('INSCRICAO_CENTAVOS', 0);
    return $config > 0 ? $config : (int) (mcp_catalogo()['inscricao_centavos'] ?? 9900);
}

/** Custos de processamento que o aluno pode escolher cobrir: percentual + parcela fixa, por método. */
function mcp_taxa(string $metodo, int $base): int
{
    $pct = (float) mcp_cfg($metodo === 'pix' ? 'TAXA_PIX_PCT' : 'TAXA_CARTAO_PCT', 0);
    $fixa = (int) mcp_cfg($metodo === 'pix' ? 'TAXA_PIX_FIXA' : 'TAXA_CARTAO_FIXA', 0);
    return max(0, (int) round($base * $pct / 100) + $fixa);
}

/**
 * Contribuição opcional para a divulgação dos cursos (07/10/2026, teste com R$ 14,90): config > catálogo.
 * DIVULGACAO_CENTAVOS = 0 no config tira a opção do checkout; vazio, vale o divulgacao_centavos de cursos.json.
 */
function mcp_divulgacao_centavos(): int
{
    $valor = mcp_cfg('DIVULGACAO_CENTAVOS');
    return max(0, (int) ($valor ?? mcp_catalogo()['divulgacao_centavos'] ?? 0));
}

function mcp_escola_configurada(): bool
{
    return (string) mcp_cfg('ESCOLA_API_URL', '') !== '';
}
