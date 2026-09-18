<?php
/** Dados públicos para as páginas: preços, custos por método, catálogo e modo de teste. */
declare(strict_types=1);
require __DIR__ . '/lib.php';
mcp_exigir_metodo('GET');

$catalogo = mcp_catalogo();
$inscricao = mcp_inscricao_centavos();
$cursos = [];
foreach ($catalogo['cursos'] as $curso) {
    $cursos[] = [
        'slug' => $curso['slug'], 'nome' => $curso['nome'],
        'carga_horaria' => $curso['carga_horaria'] ?? '', 'escolaridade' => $curso['escolaridade'] ?? '',
        'valor_curso_centavos' => $curso['valor_curso_centavos'] ?? null, 'url_escola' => $curso['url_escola'] ?? null,
    ];
}
mcp_json([
    'ok' => true,
    'versao' => MCP_VERSAO,
    'inscricao_centavos' => $inscricao,
    'taxa' => ['pix' => mcp_taxa('pix', $inscricao), 'cartao' => mcp_taxa('cartao', $inscricao)],
    'teste' => mcp_modo_teste(),
    'grupos' => $catalogo['grupos'] ?? [],
    'cursos' => $cursos,
    'escola_url' => (string) mcp_cfg('ESCOLA_URL', 'https://escola.cursoscruzvermelha.org'),
]);
