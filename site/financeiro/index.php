<?php
/**
 * Relatório das contas para o financeiro da CVB-RJ (09/11/2026, pedido do Matheus). Mesmo modelo do link da
 * coordenação do voluntariado: um link sem senha e sem login, que leva a chave no endereço.
 *
 *   https://cruzvermelhariodejaneiro.org/financeiro/?k=<chave>             o relatório mais recente
 *   https://cruzvermelhariodejaneiro.org/financeiro/?k=<chave>&mes=2026-11 um mês específico
 *
 * A chave (FINANCEIRO_CHAVE, 32+ caracteres) fica em config.php desta pasta, só no servidor. Sem chave configurada,
 * sem ?k= ou com a chave errada, a resposta é 404, igual a um endereço que não existe. Quem tem o link entra: se ele
 * vazar, troque a chave no config.php e mande o link novo.
 *
 * Os relatórios são páginas prontas em relatorios/AAAA-MM.html (só no servidor; o .htaccess de lá nega o acesso
 * direto). Mês novo: publique o arquivo do mês e o link passa a abrir nele. Detalhes em docs/financeiro.md.
 */
declare(strict_types=1);

const FIN_CHAVE_MIN = 32;

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

function fin_nao_encontrado(): never
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    $pagina = dirname(__DIR__) . '/404.html';
    if (is_file($pagina)) {
        readfile($pagina);
    } else {
        echo '<!doctype html><meta charset="utf-8"><title>Página não encontrada</title><p>Página não encontrada.</p>';
    }
    exit;
}

function fin_chave(): string
{
    $arquivo = __DIR__ . '/config.php';
    if (!is_file($arquivo)) {
        return '';
    }
    try {
        $config = require $arquivo;
    } catch (Throwable $e) {
        error_log('[financeiro] config.php ignorado: ' . get_class($e) . ' na linha ' . $e->getLine());
        return '';
    }
    $chave = is_array($config) ? (string) ($config['FINANCEIRO_CHAVE'] ?? '') : '';
    return strlen($chave) >= FIN_CHAVE_MIN ? $chave : '';
}

$chave = fin_chave();
$recebida = isset($_GET['k']) && is_string($_GET['k']) ? $_GET['k'] : '';
if ($chave === '' || $recebida === '' || !hash_equals($chave, $recebida)) {
    fin_nao_encontrado();
}

// Meses disponíveis: relatorios/AAAA-MM.html, do mais novo para o mais antigo.
$meses = [];
foreach (glob(__DIR__ . '/relatorios/*.html') ?: [] as $arquivo) {
    $nome = basename($arquivo, '.html');
    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $nome)) {
        $meses[] = $nome;
    }
}
rsort($meses);
if ($meses === []) {
    fin_nao_encontrado();
}
$pedido = isset($_GET['mes']) && is_string($_GET['mes']) ? $_GET['mes'] : '';
$mes = in_array($pedido, $meses, true) ? $pedido : $meses[0];
$html = (string) file_get_contents(__DIR__ . '/relatorios/' . $mes . '.html');

// Com mais de um mês publicado, uma faixa no topo leva aos outros (o link continua com a mesma chave).
if (count($meses) > 1) {
    $nomes = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $itens = [];
    foreach ($meses as $m) {
        [$ano, $num] = explode('-', $m);
        $rotulo = $nomes[(int) $num] . ' de ' . $ano;
        $itens[] = $m === $mes
            ? '<strong aria-current="page">' . $rotulo . '</strong>'
            : '<a href="?k=' . rawurlencode($recebida) . '&amp;mes=' . $m . '">' . $rotulo . '</a>';
    }
    $faixa = '<nav class="fin-meses" aria-label="Relatórios de outros meses" style="font:14px/1.4 system-ui,sans-serif;'
        . 'padding:10px max(16px,env(safe-area-inset-left,0px));background:#211b1b;color:#fff;display:flex;gap:6px 16px;'
        . 'flex-wrap:wrap"><span>Outros meses:</span>' . implode(' ', array_map(
            static fn(string $i): string => str_replace('<a ', '<a style="color:#fff" ', $i), $itens)) . '</nav>';
    $html = preg_replace('/<body([^>]*)>/', '<body$1>' . $faixa, $html, 1) ?? $html;
}

header('Content-Type: text/html; charset=utf-8');
echo $html;
