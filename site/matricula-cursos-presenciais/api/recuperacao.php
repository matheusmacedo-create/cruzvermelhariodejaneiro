<?php
/**
 * Recuperação do funil (30/10/2026): página avulsa da secretaria, fora do painel.php, com quem deixou dados no checkout
 * e nunca pagou nada. A regra de quem entra e as métricas estão em lib/recuperacao_funil.php.
 *
 *  - Acesso: a sessão do Portal da secretaria (mcp_painel_sessao; o cookie vale para api/). Sem sessão, só o link de
 *    entrada pelo Portal, nenhum dado.
 *  - Por pessoa: "Chamar no WhatsApp" registra o contato (mcp_recuperacao_contatos) e redireciona (303) para o wa.me com
 *    a mensagem pronta; nada sai sozinho, quem envia é a secretaria, do WhatsApp dela. "Não quer contato" bloqueia o
 *    e-mail e o WhatsApp da pessoa (mcp_avisos_bloqueios, origem 'recuperacao') e ela sai da lista.
 *  - "Exportar planilha" baixa o público filtrado para o disparo pelos modelos aprovados na Kapso; cada pessoa
 *    exportada conta como contatada (canal 'exportacao'), e a exportação fica em mcp_eventos com quem exportou.
 *  - Todo POST leva o token do formulário (mcp_painel_csrf). HTML gerado no servidor, sem JavaScript, e nada se mexe
 *    sozinho na tela: nem atualização automática nem animação.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/recuperacao_funil.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function rf_e(?string $s): string
{
    return mcp_escapar((string) $s);
}

function rf_pagina(string $corpo, ?string $usuario, int $status = 200): never
{
    http_response_code($status);
    $logo = rf_e(mcp_email_logo());
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>Recuperação do funil · Portal da secretaria</title>'
        . '<link rel="icon" type="image/png" href="/assets/favicon.png"><style>' . rf_css() . '</style></head><body><div class="faixa"></div>'
        . '<header class="topo"><div class="wrap linha"><a href="painel.php"><img src="' . $logo . '" width="120" height="36" alt="Cruz Vermelha Brasileira · Rio de Janeiro"></a>'
        . '<div><b>Recuperação do funil</b><small>Portal da secretaria</small></div>'
        . ($usuario !== null ? '<div class="usuario">' . rf_e($usuario) . ' · <a href="painel.php">Voltar ao Portal</a></div>' : '')
        . '</div></header><main class="wrap">' . $corpo . '</main></body></html>';
    exit;
}

function rf_css(): string
{
    return <<<'CSS'
:root{--red:#cc0000;--red-dark:#a30000;--black:#0f1318;--text:#1a202c;--muted:#5f6b7a;--line:#e2e8f0;--soft:#f7f8fa}
*{box-sizing:border-box}body{margin:0;font-family:Inter,Arial,sans-serif;color:var(--text);background:var(--soft);line-height:1.5}
a{color:var(--red)}img{display:block}
.faixa{height:5px;background:var(--red)}
.topo{background:#fff;border-bottom:1px solid var(--line)}
.topo .linha{display:flex;align-items:center;gap:16px;padding:12px 20px;flex-wrap:wrap}
.topo img{height:36px;width:auto}.topo b{font-size:1rem;color:var(--black);display:block;line-height:1.2}.topo small{display:block;color:var(--muted);font-size:.8rem}
.topo .usuario{margin-left:auto;font-size:.85rem;color:var(--muted)}
.wrap{max-width:1100px;margin:0 auto;padding:0 20px}main.wrap{padding:26px 20px 70px}
h1{font-size:1.5rem;color:var(--black);margin:0 0 6px;line-height:1.15}h2{font-size:1.02rem;color:var(--black);margin:0 0 12px}
.cartao{background:#fff;border:1px solid var(--line);border-radius:14px;padding:20px;margin-top:18px}
.numeros{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin:18px 0 0}
@media(max-width:900px){.numeros{grid-template-columns:repeat(2,minmax(0,1fr))}}
.numero{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px}
.numero b{display:block;font-size:1.8rem;line-height:1.1;color:var(--black)}.numero span{display:block;font-weight:700;color:var(--black);margin-top:4px}
.numero small{display:block;color:var(--muted);font-size:.82rem}
.grade{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:18px;align-items:start}
@media(max-width:820px){.grade{grid-template-columns:minmax(0,1fr)}}
.rolar{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.9rem}
th{text-align:left;font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;color:#4a5568;padding:10px 12px;border-bottom:1px solid var(--line);background:var(--soft);white-space:nowrap}
td{padding:11px 12px;border-bottom:1px solid var(--line);vertical-align:top}tr:last-child td{border-bottom:0}
td small{display:block;color:var(--muted);font-size:.82rem}td.n{text-align:right;font-variant-numeric:tabular-nums}
.selo{display:inline-block;border-radius:999px;padding:3px 10px;font-size:.74rem;font-weight:800;white-space:nowrap}
.selo.novo{background:#eef4ff;color:#1d4ed8}.selo.contatado{background:#fff4e5;color:#8a5200}.selo.recuperado{background:#e9f7ef;color:#0f7b3e}.selo.nao_quer{background:var(--soft);color:#4a5568}
form.filtros{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin:0}
label{display:block;font-weight:700;font-size:.84rem;color:var(--black);margin:0 0 4px}
select{font:inherit;font-size:.95rem;padding:9px 12px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:var(--text);min-width:200px}
select:focus{outline:3px solid var(--black);outline-offset:2px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border-radius:999px;padding:9px 16px;font:inherit;font-weight:800;font-size:.88rem;border:1.5px solid transparent;cursor:pointer;text-decoration:none;min-height:40px}
.btn:focus-visible{outline:3px solid var(--black);outline-offset:2px}
.btn-red{background:var(--red);color:#fff}.btn-red:hover{background:var(--red-dark)}
.btn-outline{background:#fff;border-color:var(--line);color:var(--black)}.btn-outline:hover{border-color:var(--red);color:var(--red)}
.btn[disabled]{background:var(--soft);color:var(--muted);border-color:var(--line);cursor:not-allowed}
.acoes{display:flex;gap:8px;flex-wrap:wrap}.acoes form{margin:0}
.aviso{border-radius:12px;padding:12px 16px;margin:16px 0 0;font-size:.92rem}
.aviso.ok{background:#e9f7ef;color:#0f5132;border:1px solid #b7e4c7}.aviso.erro{background:#fff0f2;color:#8a1c1c;border:1px solid #f5c2c7}.aviso.info{background:#eef4ff;color:#1e3a8a;border:1px solid #c7d7fe}
.nota{color:var(--muted);font-size:.85rem;margin:8px 0 0}
details{margin-top:6px;font-size:.85rem}summary{cursor:pointer;color:var(--muted)}details p{white-space:pre-wrap;background:var(--soft);border-radius:8px;padding:8px 10px;margin:6px 0 0}
.paginacao{display:flex;gap:10px;justify-content:space-between;align-items:center;margin-top:14px;font-size:.9rem}
.vazio{padding:30px 10px;text-align:center;color:var(--muted)}
.entrar{max-width:520px;margin:48px auto 0;text-align:center}
.sr{position:absolute!important;width:1px;height:1px;margin:-1px;padding:0;border:0;overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap}
CSS;
}

/** Query dos filtros (curso, situação, página), para os links e os redirecionamentos. */
function rf_query(string $curso, string $situacao, int $pagina = 1, array $extra = []): string
{
    return http_build_query(array_filter(['curso' => $curso, 'situacao' => $situacao, 'p' => $pagina > 1 ? $pagina : null] + $extra,
        static fn($v): bool => $v !== null && $v !== ''));
}

function rf_voltar(string $query): never
{
    header('Location: recuperacao.php' . ($query !== '' ? '?' . $query : ''), true, 303);
    exit;
}

function rf_curso_valido(string $curso): string
{
    return preg_match('/^[a-z0-9-]{1,80}$/', $curso) ? $curso : '';
}

function rf_situacao_valida(string $situacao): string
{
    return isset(MCP_RF_SITUACOES[$situacao]) ? $situacao : '';
}

// ----------------------------------------------------------------------------- sem sessão: só o caminho de entrada
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$sessao = mcp_painel_sessao();
if ($sessao === null) {
    rf_pagina('<div class="cartao entrar"><h1>Recuperação do funil</h1><p>Esta página é da secretaria. Entre pelo Portal com o link enviado ao e-mail da equipe e volte aqui.</p>'
        . '<p><a class="btn btn-red" href="painel.php">Entrar pelo Portal da secretaria</a></p></div>', null, $metodo === 'POST' ? 403 : 200);
}

$curso = rf_curso_valido(mcp_texto($_POST['curso'] ?? $_GET['curso'] ?? '', 80));
$situacao = rf_situacao_valida(mcp_texto($_POST['situacao'] ?? $_GET['situacao'] ?? '', 20));
$pagina = max(1, (int) ($_GET['p'] ?? 1));
$agora = time();

// ----------------------------------------------------------------------------- ações
if ($metodo === 'POST') {
    $acao = mcp_texto($_POST['acao'] ?? '', 20);
    $id = (int) ($_POST['id'] ?? 0);
    $token = mcp_texto($_POST['t'] ?? '', 40);
    if (!in_array($acao, ['whatsapp', 'nao_quer', 'exportar'], true) || $id < 0 || !hash_equals(mcp_painel_csrf($sessao, 'rf_' . $acao, $id), $token)) {
        rf_pagina('<div class="aviso erro">O formulário venceu ou não é desta sessão. <a href="recuperacao.php">Abra a página de novo</a> e tente outra vez.</div>', $sessao, 403);
    }
    $pessoas = mcp_rf_carregar($agora);
    if ($acao === 'exportar') {
        // Só quem ainda está no trabalho (novos e contatados): recuperados e "não quer" nunca vão para o disparo.
        $lista = array_values(array_filter(mcp_rf_filtrar($pessoas, $curso, $situacao), static fn(array $p): bool => in_array($p['situacao'], ['novo', 'contatado'], true)));
        $turma = mcp_rf_turma($agora);
        mcp_rf_registrar_contatos($lista, 'exportacao', $turma ? mcp_rf_campanha($turma) : 'kapso', $sessao);
        mcp_registrar(null, 'recuperacao_exportacao', $sessao . ' · ' . count($lista) . ' pessoas · curso ' . ($curso ?: 'todos') . ' · situação ' . ($situacao ?: 'novos e contatados'));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="recuperacao-' . ($curso ?: 'todos') . '-' . gmdate('Y-m-d') . '.csv"');
        echo mcp_rf_csv($lista);
        exit;
    }
    $pessoa = $id > 0 ? mcp_rf_pessoa_por_inscricao($pessoas, $id) : null;
    if ($pessoa === null || !in_array($pessoa['situacao'], ['novo', 'contatado'], true)) {
        rf_voltar(rf_query($curso, $situacao, 1, ['erro' => 'saiu']));
    }
    if ($acao === 'nao_quer') {
        mcp_rf_nao_quer($pessoa, $sessao);
        rf_voltar(rf_query($curso, $situacao, 1, ['ok' => 'nao_quer']));
    }
    $turma = mcp_rf_turma($agora);
    if ($turma === null || $pessoa['whatsapp'] === null) {
        rf_voltar(rf_query($curso, $situacao, 1, ['erro' => $turma === null ? 'sem_turma' : 'sem_celular']));
    }
    mcp_rf_registrar_contatos([$pessoa], 'whatsapp', mcp_rf_campanha($turma), $sessao);
    header('Location: ' . mcp_rf_link_whatsapp((string) $pessoa['whatsapp'], mcp_rf_mensagem($pessoa, $turma)), true, 303);
    exit;
}

// ----------------------------------------------------------------------------- tela
$pessoas = mcp_rf_carregar($agora);
$m = mcp_rf_metricas($pessoas, mcp_rf_contatos_recentes($agora), mcp_rf_pix_recuperadas($agora), $agora);
$turma = mcp_rf_turma($agora);
$filtradas = mcp_rf_filtrar($pessoas, $curso, $situacao);
$paginas = max(1, (int) ceil(count($filtradas) / MCP_RF_POR_PAGINA));
$pagina = min($pagina, $paginas);
$nessa = array_slice($filtradas, ($pagina - 1) * MCP_RF_POR_PAGINA, MCP_RF_POR_PAGINA);

$avisos = [
    'ok' => ['nao_quer' => 'Pronto: essa pessoa não recebe mais mensagens nossas (e-mail e WhatsApp) e saiu da lista.'],
    'erro' => [
        'saiu' => 'Essa pessoa não está mais na lista (pagou, pediu para não receber ou a página estava desatualizada).',
        'sem_turma' => 'Não há turma de Primeiros Socorros Básico aberta para inscrição: a mensagem não foi aberta.',
        'sem_celular' => 'Essa pessoa não deixou um celular válido para o WhatsApp.',
    ],
];
$corpo = '<h1>Recuperação do funil</h1><p class="nota">Quem deixou os dados no checkout nos últimos ' . MCP_RF_JANELA_DIAS . ' dias e nunca pagou nenhum curso. '
    . 'Uma pessoa junta as tentativas com o mesmo CPF, e-mail ou telefone. Fora da lista: quem já pagou, quem pediu para não receber e as inscrições de teste.</p>';
foreach (['ok', 'erro'] as $tipo) {
    $chave = mcp_texto($_GET[$tipo] ?? '', 20);
    if (isset($avisos[$tipo][$chave])) {
        $corpo .= '<div class="aviso ' . $tipo . '" role="status">' . rf_e($avisos[$tipo][$chave]) . '</div>';
    }
}
$corpo .= $turma !== null
    ? '<div class="aviso info">A mensagem convida para a turma de Primeiros Socorros Básico de <b>' . rf_e(mcp_rf_data_curta($turma)) . '</b> (inscrições até ' . rf_e((string) $turma['prazo']) . '). Campanha: <b>' . rf_e(mcp_rf_campanha($turma)) . '</b>.</div>'
    : '<div class="aviso erro">Nenhuma turma de Primeiros Socorros Básico aberta para inscrição no momento: o botão do WhatsApp fica desligado até a próxima turma entrar no oferta.json.</div>';

// Números do topo (só contagens).
$taxa = $m['taxa'] === null ? '—' : number_format($m['taxa'] * 100, 1, ',', '.') . '%';
$corpo .= '<section aria-label="Números" class="numeros">'
    . '<div class="numero"><b>' . $m['publico'] . '</b><span>No público</span><small>' . $m['novas'] . ' ainda sem contato</small></div>'
    . '<div class="numero"><b>' . $m['contatadas'] . '</b><span>Contatadas</span><small>com 1 contato ou mais</small></div>'
    . '<div class="numero"><b>' . $m['recuperadas'] . '</b><span>Recuperadas</span><small>pagaram depois do contato · ' . rf_e($taxa) . '</small></div>'
    . '<div class="numero"><b>' . $m['pix'] . '</b><span>Pelo lembrete do PIX</span><small>pagaram o mesmo curso depois do e-mail</small></div>'
    . '<div class="numero"><b>' . $m['nao_quer'] . '</b><span>Não querem contato</span><small>marcadas nesta página</small></div>'
    . '</section>';

$linhasCurso = '';
foreach ($m['cursos'] as $c) {
    $linhasCurso .= '<tr><td>' . rf_e($c['nome']) . '</td><td class="n">' . $c['pessoas'] . '</td><td class="n">' . $c['contatadas'] . '</td><td class="n">' . $c['recuperadas'] . '</td></tr>';
}
$linhasDia = '';
foreach (array_reverse($m['dias'], true) as $dia => $d) {
    $linhasDia .= '<tr><td>' . rf_e(MCP_DIAS_SEMANA[(int) date('w', (int) strtotime($dia . ' 12:00 UTC'))] . ', ' . substr($dia, 8, 2) . '/' . substr($dia, 5, 2)) . '</td>'
        . '<td class="n">' . $d['whatsapp'] . '</td><td class="n">' . $d['exportacao'] . '</td><td class="n">' . $d['recuperacoes'] . '</td></tr>';
}
$corpo .= '<div class="grade"><section class="cartao"><h2>Por curso de interesse</h2><div class="rolar"><table><thead><tr><th scope="col">Curso</th><th scope="col">No público</th><th scope="col">Contatadas</th><th scope="col">Recuperadas</th></tr></thead><tbody>'
    . ($linhasCurso ?: '<tr><td colspan="4" class="vazio">Ninguém no público.</td></tr>') . '</tbody></table></div></section>'
    . '<section class="cartao"><h2>Últimos ' . MCP_RF_DIAS_METRICA . ' dias</h2><div class="rolar"><table><thead><tr><th scope="col">Dia</th><th scope="col">WhatsApp</th><th scope="col">Exportadas</th><th scope="col">Recuperações</th></tr></thead><tbody>'
    . $linhasDia . '</tbody></table></div></section></div>';

// Filtros (GET) e exportação (POST).
$opcoesCurso = '<option value="">Todos os cursos</option>';
foreach ($m['cursos'] as $slug => $c) {
    $opcoesCurso .= '<option value="' . rf_e((string) $slug) . '"' . ($curso === (string) $slug ? ' selected' : '') . '>' . rf_e($c['nome']) . '</option>';
}
$opcoesSituacao = '<option value="">Novos e contatados</option>';
foreach (MCP_RF_SITUACOES as $valor => $rotulo) {
    $opcoesSituacao .= '<option value="' . $valor . '"' . ($situacao === $valor ? ' selected' : '') . '>' . rf_e($rotulo) . '</option>';
}
$exportaveis = count(array_filter($filtradas, static fn(array $p): bool => in_array($p['situacao'], ['novo', 'contatado'], true)));
$corpo .= '<section class="cartao"><div class="acoes" style="justify-content:space-between;align-items:flex-end">'
    . '<form class="filtros" method="get" action="recuperacao.php"><div><label for="f-curso">Curso de interesse</label><select id="f-curso" name="curso">' . $opcoesCurso . '</select></div>'
    . '<div><label for="f-situacao">Situação</label><select id="f-situacao" name="situacao">' . $opcoesSituacao . '</select></div>'
    . '<button class="btn btn-outline" type="submit">Filtrar</button></form>'
    . '<form method="post" action="recuperacao.php"><input type="hidden" name="acao" value="exportar"><input type="hidden" name="id" value="0">'
    . '<input type="hidden" name="curso" value="' . rf_e($curso) . '"><input type="hidden" name="situacao" value="' . rf_e($situacao) . '">'
    . '<input type="hidden" name="t" value="' . rf_e(mcp_painel_csrf($sessao, 'rf_exportar', 0)) . '">'
    . '<button class="btn btn-outline" type="submit"' . ($exportaveis ? '' : ' disabled') . '>Exportar planilha (' . $exportaveis . ')</button></form></div>'
    . '<p class="nota">A planilha (nome, primeiro_nome, telefone_e164, email, curso_interesse, ultima_tentativa) é para o disparo com os modelos aprovados na Kapso. '
    . 'Só leva novos e contatados, e cada pessoa exportada passa a contar como contatada. Quem exportou fica registrado.</p>';

// Lista de trabalho.
if (!$nessa) {
    $corpo .= '<p class="vazio">Ninguém nesta lista.</p></section>';
    rf_pagina($corpo, $sessao);
}
$linhas = '';
foreach ($nessa as $p) {
    $cursos = implode(', ', array_map('rf_e', array_values($p['cursos'])));
    $contato = $p['contatos'] > 0 ? '<small>' . $p['contatos'] . ' contato' . ($p['contatos'] > 1 ? 's' : '') . ' · último em ' . rf_e(mcp_data_brt($p['ultimo_contato'], 'd/m H\hi')) . '</small>' : '';
    $acoes = '';
    if (in_array($p['situacao'], ['novo', 'contatado'], true)) {
        $podeWhatsapp = $turma !== null && $p['whatsapp'] !== null;
        $motivo = $turma === null ? 'Sem turma aberta' : ($p['whatsapp'] === null ? 'Sem celular válido' : '');
        $acoes = '<div class="acoes"><form method="post" action="recuperacao.php?' . rf_e(rf_query($curso, $situacao)) . '" target="_blank" rel="noopener noreferrer">'
            . '<input type="hidden" name="acao" value="whatsapp"><input type="hidden" name="id" value="' . $p['id'] . '">'
            . '<input type="hidden" name="t" value="' . rf_e(mcp_painel_csrf($sessao, 'rf_whatsapp', $p['id'])) . '">'
            . '<button class="btn btn-red" type="submit"' . ($podeWhatsapp ? '' : ' disabled') . '>Chamar no WhatsApp<span class="sr"> ' . rf_e($p['primeiro_nome']) . '</span></button></form>'
            . '<form method="post" action="recuperacao.php?' . rf_e(rf_query($curso, $situacao)) . '">'
            . '<input type="hidden" name="acao" value="nao_quer"><input type="hidden" name="id" value="' . $p['id'] . '">'
            . '<input type="hidden" name="t" value="' . rf_e(mcp_painel_csrf($sessao, 'rf_nao_quer', $p['id'])) . '">'
            . '<button class="btn btn-outline" type="submit">Não quer contato</button></form></div>'
            . ($motivo !== '' ? '<small>' . rf_e($motivo) . '</small>' : '')
            . ($podeWhatsapp ? '<details><summary>Ver a mensagem</summary><p>' . rf_e(mcp_rf_mensagem($p, $turma)) . '</p></details>' : '');
    }
    $linhas .= '<tr><td><b>' . rf_e($p['nome']) . '</b><small>' . rf_e($p['email']) . '</small><small>' . rf_e(mcp_rf_telefone_mascarado($p['telefone']) ?: 'sem telefone válido')
        . ' · CPF ' . rf_e($p['cpf_mascarado']) . '</small></td>'
        . '<td>' . $cursos . '</td>'
        . '<td>' . rf_e(mcp_data_brt($p['ultima_tentativa'], 'd/m/Y')) . '<small>' . $p['tentativas'] . ' tentativa' . ($p['tentativas'] > 1 ? 's' : '') . '</small></td>'
        . '<td><span class="selo ' . rf_e($p['situacao']) . '">' . rf_e(MCP_RF_SITUACOES[$p['situacao']]) . '</span>' . $contato . '</td>'
        . '<td>' . $acoes . '</td></tr>';
}
$corpo .= '<div class="rolar" style="margin-top:16px"><table><thead><tr><th scope="col">Pessoa</th><th scope="col">Cursos de interesse</th><th scope="col">Última tentativa</th><th scope="col">Situação</th><th scope="col">Ações</th></tr></thead><tbody>'
    . $linhas . '</tbody></table></div>';
$corpo .= '<nav class="paginacao" aria-label="Páginas"><span>' . count($filtradas) . ' pessoa' . (count($filtradas) === 1 ? '' : 's') . ' · página ' . $pagina . ' de ' . $paginas . '</span><span>'
    . ($pagina > 1 ? '<a href="recuperacao.php?' . rf_e(rf_query($curso, $situacao, $pagina - 1)) . '">← Anterior</a> ' : '')
    . ($pagina < $paginas ? '<a href="recuperacao.php?' . rf_e(rf_query($curso, $situacao, $pagina + 1)) . '">Próxima →</a>' : '')
    . '</span></nav><p class="nota">"Chamar no WhatsApp" abre o WhatsApp numa aba nova com a mensagem pronta e registra o contato; recarregue esta página para ver a situação atualizada.</p></section>';
rf_pagina($corpo, $sessao);
