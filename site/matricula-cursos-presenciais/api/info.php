<?php
/**
 * Dados públicos para as páginas: preços, custos por método, contribuição para a divulgação, catálogo e modo de teste.
 * Pagar tudo (10/2026): os planos de cada curso (a página e o checkout só mostram o que vem aqui), a turma aberta com
 * os rótulos, a matrícula, o teto de parcelas, a data limite da venda sem turma e quem vende (decisão 6). Preço sempre
 * o real: o de teste vale só para os CPFs de TESTE_CPFS, no pagamentos.php (E16). Contrato: contrato-api.md, seção 1.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
mcp_exigir_metodo('GET');

$catalogo = mcp_catalogo();
$inscricao = mcp_inscricao_centavos();
$completo = mcp_plano_completo_ligado();
$parcelasMax = $completo ? mcp_parcelas_max() : 1;
$oferta = mcp_oferta();
$cursos = [];
foreach ($catalogo['cursos'] as $curso) {
    $info = mcp_planos_info($curso);
    $matricula = mcp_matricula_centavos($curso);
    $cursos[] = [
        'slug' => $curso['slug'], 'nome' => $curso['nome'],
        'carga_horaria' => $curso['carga_horaria'] ?? '', 'escolaridade' => $curso['escolaridade'] ?? '',
        'valor_curso_centavos' => $curso['valor_curso_centavos'] ?? null, 'url_escola' => $curso['url_escola'] ?? null,
        'matricula_centavos' => $matricula,
        'total_centavos' => $inscricao + $matricula,
        'turma' => $info['turma'],
        'planos' => $info['planos'],
        'plano_padrao' => $info['planos'][0],
        'plano_motivo' => $info['motivo'],
        'sem_turma' => $info['sem_turma'],
        'na_lista_sem_turma' => isset($oferta['sem_turma'][$curso['slug']]),
    ];
}
mcp_json([
    'ok' => true,
    'versao' => MCP_VERSAO,
    'inscricao_centavos' => $inscricao,
    'taxa' => ['pix' => mcp_taxa('pix', $inscricao), 'cartao' => mcp_taxa('cartao', $inscricao)],
    'divulgacao_centavos' => mcp_divulgacao_centavos(),
    'teste' => mcp_modo_teste(),
    'grupos' => $catalogo['grupos'] ?? [],
    'cursos' => $cursos,
    'escola_url' => (string) mcp_cfg('ESCOLA_URL', 'https://escola.cursoscruzvermelha.org'),
    'plano_completo' => $completo,
    'plano_completo_sem_turma' => $completo && mcp_plano_sem_turma_ligado(),
    'parcelas_max' => $parcelasMax,
    'parcela_minima_centavos' => mcp_parcela_minima_centavos(),
    'parcelado_no_ar' => $oferta['parcelado_no_ar'] && $parcelasMax > 1,
    'espera_prazo_dias' => mcp_espera_prazo_dias(),
    'espera_data_limite' => mcp_espera_data_limite(mcp_espera_prazo(time())),
    'recebedor' => mcp_recebedor(),
    'agente_financiador' => mcp_agente_financiador(),
]);
