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

function mcp_escola_configurada(): bool
{
    return (string) mcp_cfg('ESCOLA_API_URL', '') !== '';
}
