<?php
/**
 * Portal da secretaria: "O que procuram" (painel.php?v=procuras) e o que o chamado traz do atendimento inteligente.
 *
 * A tela lê mcp_buscas (lib/atendimento.php): cada pergunta feita ao chat do site, o que foi respondido e o desfecho.
 * Serve para duas decisões: o que escrever na FAQ (as perguntas sem resposta) e o que está escrito mas não basta
 * (respostas dadas que mesmo assim viraram chamado). Exporta CSV para quem quiser olhar numa planilha.
 */
declare(strict_types=1);

const PA_PERIODOS = [7 => '7 dias', 30 => '30 dias', 90 => '90 dias', 365 => '12 meses'];

function pa_periodo(): int
{
    $dias = (int) ($_GET['d'] ?? 30);
    return isset(PA_PERIODOS[$dias]) ? $dias : 30;
}

function pa_desfecho(array $l): string
{
    if ($l['contato_id']) {
        return '<a class="status novo" href="painel.php?id=' . (int) $l['contato_id'] . '">virou chamado</a>';
    }
    if (!(int) $l['sabe']) {
        return '<span class="status novo">sem resposta</span>';
    }
    return match ((string) $l['desfecho']) {
        'resolveu' => '<span class="selo ok">resolveu</span>',
        'nao_resolveu' => '<span class="status novo">não resolveu</span>',
        default => '<span class="status">respondida</span>',
    };
}

/** No detalhe do chamado: os dados coletados e a conversa com o assistente. Vazio se o chamado não veio de uma conversa. */
function pa_contato_extras(array $c): string
{
    $html = '';
    $conversa = mcp_contato_conversa($c);
    if ($conversa) {
        $itens = '';
        foreach ($conversa as $t) {
            $itens .= '<li><div><b>' . pn_e($t['p']) . '</b>' . ($t['voz'] ? ' <small style="display:inline">(por voz)</small>' : '')
                . '<small>' . ($t['sabe'] ? '' : 'Sem resposta no site. ') . pn_e($t['r'] !== '' ? $t['r'] : '') . '</small></div></li>';
        }
        $html .= '<div class="cartao" style="margin-top:18px"><h2>O que a pessoa perguntou ao assistente do site</h2>'
            . '<p class="nota" style="margin:0 0 8px">A pessoa leu estas respostas antes de escrever. Não é preciso repetir o que já foi dito.</p>'
            . '<ul class="lista-curta">' . $itens . '</ul></div>';
    }
    return $html;
}

/** Linhas a mais na ficha do chamado: categoria e dados coletados. [rótulo => html]. */
function pa_contato_dados(array $c): array
{
    $dados = [];
    if (!empty($c['categoria']) && function_exists('mcp_at_categorias') && isset(mcp_at_categorias()[$c['categoria']])) {
        $dados['Procurava'] = pn_e(mcp_at_categorias()[$c['categoria']]['rotulo']);
    }
    foreach (mcp_contato_detalhes($c) as $rotulo => $valor) {
        $dados[$rotulo] = pn_e($valor);
    }
    return $dados;
}

function pa_csv(int $dias, string $filtro, string $categoria): never
{
    $categorias = mcp_at_categorias();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="o-que-procuram-' . gmdate('Y-m-d') . '.csv"');
    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF");
    fputcsv($saida, ['Data (Brasília)', 'Pergunta', 'Categoria', 'Canal', 'Respondida', 'Desfecho', 'Resposta dada', 'Resposta da base usada', 'Página', 'Chamado'], ';');
    foreach (mcp_buscas_listar($filtro, $categoria, $dias, 5000) as $l) {
        // Planilha não executa o que a pessoa escreveu: texto que começa com sinal de fórmula ganha um apóstrofo.
        $seguro = static fn(?string $t): string => preg_match('/^[=+\-@\t\r]/', (string) $t) ? "'" . $t : (string) $t;
        fputcsv($saida, [
            mcp_data_brt((string) $l['criado_em'], 'd/m/Y H:i'), $seguro($l['pergunta']), $categorias[$l['categoria']]['rotulo'] ?? $l['categoria'], $l['canal'],
            (int) $l['sabe'] ? 'sim' : 'não', $l['contato_id'] ? 'virou chamado' : ((string) $l['desfecho'] ?: ''), $seguro($l['resposta']), $seguro($l['fonte']),
            $seguro($l['pagina']), $l['contato_id'] ? 'chamado nº ' . (int) $l['contato_id'] : '',
        ], ';');
    }
    exit;
}

function pa_procuras(string $usuario): never
{
    if (!function_exists('mcp_buscas_resumo')) {
        pn_pagina('O que procuram', '<div class="cartao"><p class="vazio">O atendimento inteligente ainda não foi publicado neste servidor.</p></div>', $usuario, true, 'procuras');
    }
    $dias = pa_periodo();
    $filtros = mcp_busca_filtros();
    $f = mcp_texto($_GET['f'] ?? '', 20);
    $f = isset($filtros[$f]) ? $f : 'todas';
    $categorias = mcp_at_categorias();
    $cat = mcp_texto($_GET['cat'] ?? '', 30);
    $cat = isset($categorias[$cat]) ? $cat : '';
    if (isset($_GET['csv'])) {
        pa_csv($dias, $f, $cat);
    }
    $r = mcp_buscas_resumo($dias);
    $t = $r['totais'] + ['perguntas' => 0, 'conversas' => 0, 'respondidas' => 0, 'sem_resposta' => 0, 'resolveu' => 0, 'nao_resolveu' => 0, 'voz' => 0, 'ia' => 0, 'chamados' => 0, 'conversas_com_chamado' => 0];
    $pct = static fn(int $parte, int $todo): string => $todo > 0 ? round(100 * $parte / $todo) . '%' : '–';
    $base = 'painel.php?v=procuras&amp;d=' . $dias;

    $periodos = '';
    foreach (PA_PERIODOS as $n => $rotulo) {
        $periodos .= '<a class="pilula' . ($n === $dias ? ' ativo' : '') . '" href="painel.php?v=procuras&amp;d=' . $n . '">' . pn_e($rotulo) . '</a>';
    }
    $semChamado = $t['conversas'] - $t['conversas_com_chamado'];
    $numeros = '<div class="numeros">'
        . '<div class="numero"><b>' . $t['perguntas'] . '</b><span>perguntas</span><small>em ' . $t['conversas'] . ' conversas · ' . $t['voz'] . ' por voz</small></div>'
        . '<div class="numero"><b>' . $pct($semChamado, $t['conversas']) . '</b><span>resolvidas no site</span><small>' . $semChamado . ' conversas terminaram sem chamado</small></div>'
        . '<a class="numero' . ($t['sem_resposta'] > 0 ? ' alerta' : '') . '" href="' . $base . '&amp;f=sem_resposta#lista"><b>' . $t['sem_resposta'] . '</b><span>sem resposta</span><small>o site não tinha a informação</small></a>'
        . '<a class="numero" href="' . $base . '&amp;f=chamado#lista"><b>' . $t['chamados'] . '</b><span>chamados</span><small>foram para a equipe, com os dados</small></a>'
        . '</div>';

    $linhasCat = '';
    foreach ($r['categorias'] as $c) {
        $chave = (string) $c['categoria'];
        $linhasCat .= '<tr><td><a class="nome" href="' . $base . '&amp;cat=' . pn_e($chave) . '#lista">' . pn_e($categorias[$chave]['rotulo'] ?? $chave) . '</a></td>'
            . '<td>' . (int) $c['perguntas'] . '</td><td>' . (int) $c['sem_resposta'] . '</td><td>' . (int) $c['chamados'] . '</td></tr>';
    }
    $semResposta = '';
    foreach (array_slice($r['sem_resposta'], 0, 12) as $l) {
        $semResposta .= '<li><div><b>' . pn_e((string) $l['pergunta']) . '</b><small>' . pn_e(pn_data((string) $l['criado_em'])) . ' · ' . pn_e($categorias[$l['categoria']]['rotulo'] ?? (string) $l['categoria']) . '</small></div></li>';
    }
    $fontes = '';
    foreach ($r['fontes'] as $x) {
        $fontes .= '<li><div><b>' . pn_e((string) $x['fonte']) . '</b><small>' . (int) $x['vezes'] . ((int) $x['vezes'] === 1 ? ' vez' : ' vezes')
            . ((int) $x['nao_bastou'] > 0 ? ' · em ' . (int) $x['nao_bastou'] . ' não bastou (a pessoa disse que não resolveu ou abriu chamado)' : '') . '</small></div></li>';
    }

    $pilulas = '';
    foreach ($filtros as $chave => [$rotulo]) {
        $pilulas .= '<a class="pilula' . ($chave === $f ? ' ativo' : '') . '" href="' . $base . '&amp;f=' . $chave . ($cat !== '' ? '&amp;cat=' . pn_e($cat) : '') . '#lista">' . pn_e($rotulo) . '</a>';
    }
    if ($cat !== '') {
        $pilulas .= '<a class="pilula ativo" href="' . $base . '&amp;f=' . $f . '#lista" title="Tirar o filtro de categoria">' . pn_e($categorias[$cat]['rotulo']) . ' ✕</a>';
    }
    $pagina = min(max(1, (int) ($_GET['p'] ?? 1)), 10000);
    $linhas = mcp_buscas_listar($f, $cat, $dias, MCP_PAINEL_POR_PAGINA, ($pagina - 1) * MCP_PAINEL_POR_PAGINA);
    $tabela = '';
    foreach ($linhas as $l) {
        $tabela .= '<tr><td>' . pn_e(pn_data((string) $l['criado_em'])) . '<small>' . ($l['canal'] === 'voz' ? 'por voz' : ($l['canal'] === 'botao' ? 'botão' : 'escrita')) . '</small></td>'
            . '<td><b>' . pn_e((string) $l['pergunta']) . '</b>' . ($l['resposta'] ? '<small>' . pn_e(mb_substr((string) $l['resposta'], 0, 220)) . (mb_strlen((string) $l['resposta']) > 220 ? '…' : '') . '</small>' : '') . '</td>'
            . '<td>' . pn_e($categorias[$l['categoria']]['rotulo'] ?? (string) $l['categoria']) . ($l['pagina'] ? '<small>' . pn_e((string) $l['pagina']) . '</small>' : '') . '</td>'
            . '<td>' . pa_desfecho($l) . '</td></tr>';
    }
    $query = 'v=procuras&amp;d=' . $dias . '&amp;f=' . $f . ($cat !== '' ? '&amp;cat=' . pn_e($cat) : '');
    $paginacao = ($pagina > 1 ? '<a href="painel.php?' . $query . '&amp;p=' . ($pagina - 1) . '#lista">Mais recentes</a>' : '')
        . (count($linhas) === MCP_PAINEL_POR_PAGINA ? '<a href="painel.php?' . $query . '&amp;p=' . ($pagina + 1) . '#lista">Mais antigas</a>' : '');

    $modo = mcp_at_ia_configurada()
        ? 'O assistente responde com inteligência artificial (' . pn_e(mcp_at_ia_modelo()) . '), só com o que está publicado no site. ' . $t['ia'] . ' das ' . $t['perguntas'] . ' perguntas do período passaram por ela.'
        : 'O assistente está no modo de busca por palavras (sem inteligência artificial): falta a chave em api/config-ia.php. As perguntas já são registradas do mesmo jeito.';
    $corpo = '<div class="cabeca"><div><p class="eyebrow">Chat do site</p><h1>O que as pessoas procuram</h1>'
        . '<p class="nota">Cada pergunta feita no chat do site, o que o site respondeu e como terminou. Quem só pergunta não se identifica: nome e e-mail só existem nos chamados. ' . $modo . '</p></div>'
        . '<a class="btn btn-outline" href="painel.php?' . $query . '&amp;csv=1">Baixar planilha (CSV)</a></div>'
        . '<div class="filtros">' . $periodos . '</div>'
        . $numeros
        . '<div class="grade-inicio">'
        . '<div class="cartao"><h2>Perguntas sem resposta</h2><p class="nota" style="margin:0 0 8px">O que procuraram e o site não tinha. Cada uma que se repete é uma resposta a escrever na FAQ (site/faq-home.json): escrita lá, o chat passa a responder.</p>'
        . ($semResposta !== '' ? '<ul class="lista-curta">' . $semResposta . '</ul><p class="nota"><a href="' . $base . '&amp;f=sem_resposta#lista">Ver todas</a></p>' : '<p class="vazio">Nenhuma no período.</p>') . '</div>'
        . '<div class="cartao"><h2>Respostas mais usadas</h2><p class="nota" style="margin:0 0 8px">O que o site mais respondeu. Resposta muito usada que “não bastou” precisa ficar mais clara.</p>'
        . ($fontes !== '' ? '<ul class="lista-curta">' . $fontes . '</ul>' : '<p class="vazio">Nenhuma no período.</p>') . '</div>'
        . '</div>'
        . '<div class="tabela" style="margin-top:18px"><table><thead><tr><th>Assunto procurado</th><th>Perguntas</th><th>Sem resposta</th><th>Chamados</th></tr></thead><tbody>'
        . ($linhasCat !== '' ? $linhasCat : '<tr><td colspan="4" class="vazio">Nenhuma pergunta no período.</td></tr>') . '</tbody></table></div>'
        . '<h2 id="lista" style="margin:28px 0 0">Perguntas</h2><div class="filtros">' . $pilulas . '</div>'
        . '<div class="tabela"><table><thead><tr><th>Quando</th><th>Pergunta e resposta</th><th>Assunto</th><th>Desfecho</th></tr></thead><tbody>'
        . ($tabela !== '' ? $tabela : '<tr><td colspan="4" class="vazio">Nenhuma pergunta aqui.</td></tr>') . '</tbody></table></div>'
        . ($paginacao !== '' ? '<div class="paginacao">' . $paginacao . '</div>' : '');
    pn_pagina('O que procuram', $corpo, $usuario, true, 'procuras');
}
