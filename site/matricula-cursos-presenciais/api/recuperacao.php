<?php
/**
 * Recuperação por WhatsApp (30/10/2026): painel avulso da secretaria, fora do painel.php, só para ACOMPANHAR o disparo
 * automático das três mensagens a quem se cadastrou e não pagou. A regra e o envio estão em lib/recuperacao_whatsapp.php;
 * quem manda é a rotina de hora em hora (api/lembretes.php), nunca esta página.
 *
 *  - Acesso: a sessão do Portal da secretaria (mcp_painel_sessao; o cookie vale para api/). Sem sessão, só o link de
 *    entrada pelo Portal, nenhum dado (POST sem sessão: 403).
 *  - Só leitura, exceto o botão "Ligar o recebimento de respostas" (POST com o token do formulário), que cria na Kapso o
 *    webhook apontando para api/kapso-webhook.php com o segredo do site.
 *  - HTML gerado no servidor, sem JavaScript; nada se mexe sozinho (nem atualização automática nem animação);
 *    noindex e no-store. A chave da Kapso nunca aparece aqui: só "ok" ou o nome do que falta.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/recuperacao_whatsapp.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function rw_e(?string $s): string
{
    return mcp_escapar((string) $s);
}

function rw_pagina(string $corpo, ?string $usuario, int $status = 200): never
{
    http_response_code($status);
    $logo = rw_e(mcp_email_logo());
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>Recuperação por WhatsApp · Portal da secretaria</title>'
        . '<link rel="icon" type="image/png" href="/assets/favicon.png"><style>' . rw_css() . '</style></head><body><div class="faixa"></div>'
        . '<header class="topo"><div class="wrap linha"><a href="painel.php"><img src="' . $logo . '" width="120" height="36" alt="Cruz Vermelha Brasileira · Rio de Janeiro"></a>'
        . '<div><b>Recuperação por WhatsApp</b><small>Portal da secretaria</small></div>'
        . ($usuario !== null ? '<div class="usuario">' . rw_e($usuario) . ' · <a href="painel.php">Voltar ao Portal</a></div>' : '')
        . '</div></header><main class="wrap">' . $corpo . '</main></body></html>';
    exit;
}

function rw_css(): string
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
.grade{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start}
@media(max-width:820px){.grade{grid-template-columns:minmax(0,1fr)}}
.rolar{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.9rem}
th{text-align:left;font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;color:#4a5568;padding:10px 12px;border-bottom:1px solid var(--line);background:var(--soft);white-space:nowrap}
td{padding:11px 12px;border-bottom:1px solid var(--line);vertical-align:top}tr:last-child td{border-bottom:0}
td small{display:block;color:var(--muted);font-size:.82rem}td.n{text-align:right;font-variant-numeric:tabular-nums}
ul.situacao{list-style:none;margin:0;padding:0}ul.situacao li{padding:8px 0;border-bottom:1px solid var(--line)}ul.situacao li:last-child{border-bottom:0}
.selo{display:inline-block;border-radius:999px;padding:2px 10px;font-size:.74rem;font-weight:800;white-space:nowrap;margin-right:8px}
.selo.ok{background:#e9f7ef;color:#0f7b3e}.selo.nao{background:#fff0f2;color:#8a1c1c}.selo.talvez{background:#fff4e5;color:#8a5200}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border-radius:999px;padding:9px 16px;font:inherit;font-weight:800;font-size:.88rem;border:1.5px solid transparent;cursor:pointer;text-decoration:none;min-height:40px}
.btn:focus-visible{outline:3px solid var(--black);outline-offset:2px}
.btn-red{background:var(--red);color:#fff}.btn-red:hover{background:var(--red-dark)}
.aviso{border-radius:12px;padding:12px 16px;margin:16px 0 0;font-size:.92rem}
.aviso.ok{background:#e9f7ef;color:#0f5132;border:1px solid #b7e4c7}.aviso.erro{background:#fff0f2;color:#8a1c1c;border:1px solid #f5c2c7}
.nota{color:var(--muted);font-size:.85rem;margin:8px 0 0}
.paginacao{display:flex;gap:10px;justify-content:space-between;align-items:center;margin-top:14px;font-size:.9rem}
.vazio{padding:30px 10px;text-align:center;color:var(--muted)}
h3{font-size:1rem;margin:18px 0 6px;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.previa{margin:6px 0 10px;padding:12px 14px;border-left:4px solid var(--rule,#ddd);background:#f6f7f8;white-space:normal;font-size:.92rem;line-height:1.45;max-width:640px}
.entrar{max-width:520px;margin:48px auto 0;text-align:center}
CSS;
}

function rw_selo(string $tipo, string $texto): string
{
    return '<span class="selo ' . $tipo . '">' . rw_e($texto) . '</span>';
}

function rw_quando(int $ts): string
{
    return mcp_data_brt(gmdate('Y-m-d H:i:s', $ts), 'd/m \à\s H\hi');
}

// ----------------------------------------------------------------------------- sem sessão: só o caminho de entrada
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$sessao = mcp_painel_sessao();
if ($sessao === null) {
    rw_pagina('<div class="cartao entrar"><h1>Recuperação por WhatsApp</h1><p>Esta página é da secretaria. Entre pelo Portal com o link enviado ao e-mail da equipe e volte aqui.</p>'
        . '<p><a class="btn btn-red" href="painel.php">Entrar pelo Portal da secretaria</a></p></div>', null, $metodo === 'POST' ? 403 : 200);
}
$cfg = mcp_rw_kapso_config();

// ----------------------------------------------------------------------------- a única ação: ligar o webhook
if ($metodo === 'POST') {
    $token = mcp_texto($_POST['t'] ?? '', 40);
    if (($_POST['acao'] ?? '') !== 'ligar_webhook' || !hash_equals(mcp_painel_csrf($sessao, 'rw_webhook', 0), $token)) {
        rw_pagina('<div class="aviso erro">O formulário venceu ou não é desta sessão. <a href="recuperacao.php">Abra a página de novo</a> e tente outra vez.</div>', $sessao, 403);
    }
    $erro = $cfg['ok'] ? mcp_rw_webhook_ligar($cfg) : 'falta ' . implode(' e ', $cfg['falta']);
    mcp_registrar(null, 'recuperacao_webhook', $sessao . ' · ' . ($erro === null ? 'ligado' : 'erro: ' . $erro));
    header('Location: recuperacao.php?webhook=' . ($erro === null ? 'ok' : 'erro'), true, 303);
    exit;
}

// ----------------------------------------------------------------------------- tela
$agora = time();
try {
    $carga = mcp_rw_carregar($agora);
    $linhas = mcp_rw_linhas();
} catch (PDOException $e) {
    // Logo depois de publicar, a tabela nova pode ainda não existir (a migração do db.php é adiada por 60 s quando uma
    // transação longa segura o banco): a página diz isso, em vez de um erro 500.
    error_log('[matricula] recuperação por WhatsApp sem banco pronto: ' . $e->getMessage());
    rw_pagina('<div class="aviso erro" role="status">O banco ainda não está pronto para esta página (a tabela é criada na primeira visita depois da publicação). '
        . 'Tente de novo em 1 minuto; se continuar assim, avise o responsável pelo site.</div>', $sessao, 503);
}
$m = mcp_rw_metricas($carga['pessoas'], $linhas, $agora);

$corpo = '<h1>Recuperação por WhatsApp</h1><p class="nota">Três mensagens automáticas a quem se cadastrou no checkout do site ou na plataforma da escola nos últimos '
    . MCP_RW_JANELA_DIAS . ' dias e não pagou: 3 h, 24 h e 72 h depois do cadastro, das 8h às 20h, no máximo ' . MCP_RW_POR_RODADA . ' por hora e ' . MCP_RW_POR_DIA . ' em 24 h. '
    . 'Quem paga em qualquer um dos dois sistemas, responde "Não tenho interesse" ou não tem celular válido sai na hora.</p>';
$retorno = mcp_texto($_GET['webhook'] ?? '', 10);
if ($retorno === 'ok') {
    $corpo .= '<div class="aviso ok" role="status">Pronto: a Kapso vai mandar a entrega, a leitura e as respostas para o site.</div>';
} elseif ($retorno === 'erro') {
    $corpo .= '<div class="aviso erro" role="status">A Kapso não aceitou ligar o recebimento de respostas agora. Confira a configuração abaixo e tente de novo em alguns minutos.</div>';
}

// Situação da configuração.
$itens = [];
$itens[] = in_array('WHATSAPP_KAPSO_CHAVE', $cfg['falta'], true) ? rw_selo('nao', 'Falta') . 'Chave da Kapso (WHATSAPP_KAPSO_CHAVE)' : rw_selo('ok', 'Ok') . 'Chave da Kapso';
$itens[] = in_array('WHATSAPP_KAPSO_NUMERO_ID', $cfg['falta'], true) ? rw_selo('nao', 'Falta') . 'Número (WHATSAPP_KAPSO_NUMERO_ID)' : rw_selo('ok', 'Ok') . 'Número';
$webhook = 'desconhecido';
if ($cfg['ok']) {
    $modelos = mcp_rw_modelos($cfg, $agora);
    if (!$modelos['ok']) {
        $itens[] = rw_selo('talvez', 'Sem resposta') . 'Modelos: não deu para ler na Kapso agora (' . rw_e($modelos['erro']) . ')';
    } else {
        foreach ($modelos['modelos'] as $nome => $s) {
            $itens[] = ($s === 'APPROVED' ? rw_selo('ok', 'Aprovado') : rw_selo('nao', $s === 'ausente' ? 'Não existe' : $s)) . 'Modelo ' . rw_e($nome);
        }
    }
    $webhook = mcp_rw_webhook_situacao($cfg, $agora);
    $itens[] = match ($webhook) {
        'ligado' => rw_selo('ok', 'Ligado') . 'Recebimento de respostas (entrega, leitura e "Não tenho interesse")',
        'desligado' => rw_selo('nao', 'Desligado') . 'Recebimento de respostas: sem ele, o painel não sabe o que foi entregue nem quem respondeu',
        'diferente' => rw_selo('nao', 'Desacertado') . 'Recebimento de respostas: a Kapso tem um webhook para este endereço, mas com outro segredo ou sem todos '
            . 'os eventos; entregas, leituras e "Não tenho interesse" não chegam. O botão abaixo acerta',
        default => rw_selo('talvez', 'Sem resposta') . 'Recebimento de respostas: a Kapso não respondeu agora',
    };
} else {
    $itens[] = rw_selo('nao', 'Parado') . 'Nenhuma mensagem sai enquanto faltar ' . rw_e(implode(' e ', $cfg['falta'])) . ' (api/config-whatsapp.php, só no servidor).';
}
$itens[] = match ($carga['escola']['estado']) {
    'ok' => rw_selo('ok', 'Ok') . 'Plataforma da escola',
    'desligada' => rw_selo('talvez', 'Desligada') . 'Plataforma da escola: sem ESCOLA_API_URL e ESCOLA_API_TOKEN, só o checkout do site entra',
    default => rw_selo('nao', 'Indisponível') . 'Escola indisponível: nenhuma mensagem sai até ela responder, porque não dá para saber quem pagou lá (' . rw_e((string) $carga['escola']['erro']) . ')',
};
$corpo .= '<section class="cartao"><h2>Situação</h2><ul class="situacao"><li>' . implode('</li><li>', $itens) . '</li></ul>';
if ($cfg['ok'] && $webhook !== 'ligado') {
    $corpo .= '<form method="post" action="recuperacao.php" style="margin-top:14px"><input type="hidden" name="acao" value="ligar_webhook">'
        . '<input type="hidden" name="t" value="' . rw_e(mcp_painel_csrf($sessao, 'rw_webhook', 0)) . '">'
        . '<button class="btn btn-red" type="submit">Ligar o recebimento de respostas</button></form>';
}
$corpo .= '</section>';

// Números.
$taxa = $m['taxa'] === null ? '—' : number_format($m['taxa'] * 100, 1, ',', '.') . '%';
$enviadas = array_sum($m['etapas']);
$corpo .= '<section aria-label="Números" class="numeros">'
    . '<div class="numero"><b>' . $m['no_fluxo'] . '</b><span>No fluxo agora</span><small>com mensagem ainda por vir</small></div>'
    . '<div class="numero"><b>' . $enviadas . '</b><span>Mensagens enviadas</span><small>1ª: ' . $m['etapas'][1] . ' · 2ª: ' . $m['etapas'][2] . ' · 3ª: ' . $m['etapas'][3]
    . ($m['falhas'] > 0 ? ' · ' . $m['falhas'] . ' não saíram' : '') . '</small></div>'
    . '<div class="numero"><b>' . $m['entregues'] . '</b><span>Entregues</span><small>' . $m['lidas'] . ' lidas</small></div>'
    . '<div class="numero"><b>' . $m['responderam'] . '</b><span>Responderam</span><small>' . $m['nao_querem'] . ' não querem</small></div>'
    . '<div class="numero"><b>' . $m['recuperadas'] . '</b><span>Recuperadas</span><small>pagaram depois da 1ª mensagem · ' . rw_e($taxa) . ' de ' . $m['receberam'] . '</small></div>'
    . '</section>';

$porOrigem = '';
foreach ($m['origens'] as $origem => $o) {
    $porOrigem .= '<tr><td>' . ($origem === 'escola' ? 'Plataforma da escola' : 'Checkout do site') . '</td><td class="n">' . $o['mensagens'] . '</td><td class="n">' . $o['pessoas'] . '</td><td class="n">' . $o['recuperadas'] . '</td></tr>';
}
$porCurso = '';
foreach ($m['cursos'] as $nome => $c) {
    $porCurso .= '<tr><td>' . rw_e((string) $nome) . '</td><td class="n">' . $c['mensagens'] . '</td><td class="n">' . $c['pessoas'] . '</td><td class="n">' . $c['recuperadas'] . '</td></tr>';
}
$cabecalho = '<thead><tr><th scope="col">%s</th><th scope="col">Mensagens</th><th scope="col">Pessoas</th><th scope="col">Recuperadas</th></tr></thead>';
$corpo .= '<div class="grade"><section class="cartao"><h2>Por origem</h2><div class="rolar"><table>' . sprintf($cabecalho, 'Origem') . '<tbody>'
    . ($porOrigem ?: '<tr><td colspan="4" class="vazio">Nenhuma mensagem ainda.</td></tr>') . '</tbody></table></div></section>'
    . '<section class="cartao"><h2>Por curso</h2><div class="rolar"><table>' . sprintf($cabecalho, 'Curso') . '<tbody>'
    . ($porCurso ?: '<tr><td colspan="4" class="vazio">Nenhuma mensagem ainda.</td></tr>') . '</tbody></table></div></section></div>';

$linhasDia = '';
foreach (array_reverse($m['dias'], true) as $dia => $d) {
    $linhasDia .= '<tr><td>' . rw_e(MCP_DIAS_SEMANA[(int) date('w', (int) strtotime($dia . ' 12:00 UTC'))] . ', ' . substr($dia, 8, 2) . '/' . substr($dia, 5, 2)) . '</td>'
        . '<td class="n">' . $d['mensagens'] . '</td><td class="n">' . $d['recuperacoes'] . '</td></tr>';
}
$corpo .= '<section class="cartao"><h2>Últimos ' . MCP_RW_DIAS_METRICA . ' dias</h2><div class="rolar"><table><thead><tr><th scope="col">Dia</th><th scope="col">Mensagens</th><th scope="col">Recuperações</th></tr></thead><tbody>'
    . $linhasDia . '</tbody></table></div></section>';

// Próximo disparo: quem a próxima rodada vai pegar, por modelo, com a prévia do texto. É a mesma conta da rotina
// (mcp_rw_proxima com o ajuste de horário) e o mesmo teto por rodada; a rotina ainda reconfere cada pessoa antes de mandar.
$rodada = mcp_rw_proxima_rodada($agora);
$naRodada = array_values(array_filter($m['lista'], static fn(array $p): bool => $p['proxima'] <= $rodada));
$depois = array_slice($naRodada, MCP_RW_POR_RODADA);
$naRodada = array_slice($naRodada, 0, MCP_RW_POR_RODADA);
$travas = [];
if (!$cfg['ok']) {
    $travas[] = 'falta ' . implode(' e ', $cfg['falta']) . ' no servidor';
}
if ($carga['escola']['estado'] === 'indisponivel') {
    $travas[] = 'a plataforma da escola não respondeu (sem ela não se sabe quem pagou lá)';
}
$corpo .= '<section class="cartao"><h2>Próximo disparo</h2><p class="nota">Próxima rodada: <b>' . rw_e(rw_quando($rodada)) . '</b>. '
    . 'A rotina roda de hora em hora, no minuto ' . MCP_RW_MINUTO_DA_RODADA . ', das 8h às 20h, e manda no máximo ' . MCP_RW_POR_RODADA . ' mensagens por rodada.</p>';
if ($travas) {
    $corpo .= '<div class="aviso erro" role="status">Nada sai na próxima rodada enquanto ' . rw_e(implode('; ', $travas)) . '.</div>';
}
if (!$naRodada) {
    $seguinte = $m['lista'][0] ?? null;
    $corpo .= '<p class="vazio">Ninguém para a próxima rodada.'
        . ($seguinte ? ' A seguinte prevista: ' . rw_e(MCP_RW_MODELOS[$seguinte['proxima_etapa']]) . ' para ' . rw_e($seguinte['nome']) . ' em ' . rw_e(rw_quando($seguinte['proxima'])) . '.' : '')
        . '</p>';
} else {
    $porEtapa = [];
    foreach ($naRodada as $p) {
        $porEtapa[$p['proxima_etapa']][] = $p;
    }
    ksort($porEtapa);
    foreach ($porEtapa as $etapa => $pessoas) {
        $nomeModelo = MCP_RW_MODELOS[$etapa];
        $situacao = isset($modelos) && $modelos['ok'] ? ($modelos['modelos'][$nomeModelo] ?? 'ausente') : null;
        $selo = $situacao === null ? rw_selo('talvez', 'Situação do modelo sem resposta') : ($situacao === 'APPROVED' ? rw_selo('ok', 'Modelo aprovado') : rw_selo('nao', $situacao === 'ausente' ? 'Modelo não existe' : 'Modelo ' . $situacao));
        $primeiro = $pessoas[0];
        $linhasModelo = '';
        foreach ($pessoas as $p) {
            $linhasModelo .= '<tr><td><b>' . rw_e($p['nome']) . '</b><small>' . rw_e($p['celular']) . '</small></td><td>' . ($p['origem'] === 'escola' ? 'Escola' : 'Site') . '</td>'
                . '<td>' . rw_e($p['curso']) . '</td><td>' . rw_e(rw_quando($p['proxima'])) . '</td></tr>';
        }
        $corpo .= '<h3>' . $selo . $etapa . 'ª mensagem · modelo <code>' . rw_e($nomeModelo) . '</code> · ' . count($pessoas) . ' lead' . (count($pessoas) === 1 ? '' : 's') . '</h3>'
            . ($situacao !== null && $situacao !== 'APPROVED' ? '<p class="nota">Este modelo ainda não está aprovado na Meta: estes leads esperam a aprovação, sem perder a vez.</p>' : '')
            . '<p class="nota">Como ' . rw_e(mcp_rw_primeiro_nome($primeiro['nome'])) . ' vai ler:</p>'
            . '<blockquote class="previa">' . nl2br(rw_e(mcp_rw_previa($etapa, mcp_rw_primeiro_nome($primeiro['nome']), $primeiro['curso']))) . '</blockquote>'
            . '<div class="rolar"><table><thead><tr><th scope="col">Lead</th><th scope="col">Origem</th><th scope="col">Curso</th><th scope="col">Devida desde</th></tr></thead><tbody>'
            . $linhasModelo . '</tbody></table></div>';
    }
    if ($depois) {
        $corpo .= '<p class="nota">Mais ' . count($depois) . ' lead' . (count($depois) === 1 ? '' : 's') . ' já devido' . (count($depois) === 1 ? '' : 's')
            . ' passa' . (count($depois) === 1 ? '' : 'm') . ' do teto desta rodada e fica' . (count($depois) === 1 ? '' : 'm') . ' para a seguinte.</p>';
    }
}
$corpo .= '<p class="nota">Antes de cada envio a rotina confere de novo se a pessoa pagou (no site ou na escola) ou pediu para sair; quem pagou não recebe.</p></section>';

// Pessoas no fluxo.
$pagina = max(1, (int) ($_GET['p'] ?? 1));
$paginas = max(1, (int) ceil(count($m['lista']) / MCP_RW_POR_PAGINA));
$pagina = min($pagina, $paginas);
$nessa = array_slice($m['lista'], ($pagina - 1) * MCP_RW_POR_PAGINA, MCP_RW_POR_PAGINA);
$corpo .= '<section class="cartao"><h2>Pessoas no fluxo</h2>';
if (!$nessa) {
    rw_pagina($corpo . '<p class="vazio">Ninguém no fluxo agora.</p></section>', $sessao);
}
$linhasPessoa = '';
foreach ($nessa as $p) {
    $respostas = '';
    foreach ($p['respostas'] as $r) {
        $respostas .= '<small>"' . rw_e($r) . '"</small>';
    }
    $linhasPessoa .= '<tr><td><b>' . rw_e($p['nome']) . '</b><small>' . rw_e($p['celular']) . '</small></td>'
        . '<td>' . ($p['origem'] === 'escola' ? 'Escola' : 'Site') . '</td><td>' . rw_e($p['curso']) . '</td>'
        . '<td>' . ($p['etapa'] > 0 ? $p['etapa'] . 'ª enviada' : 'Nenhuma ainda') . '</td>'
        . '<td>' . $p['proxima_etapa'] . 'ª em ' . rw_e(rw_quando($p['proxima'])) . '</td>'
        . '<td>' . ($respostas !== '' ? $respostas : '<small>—</small>') . '</td></tr>';
}
$corpo .= '<div class="rolar"><table><thead><tr><th scope="col">Pessoa</th><th scope="col">Origem</th><th scope="col">Curso</th><th scope="col">Etapa</th>'
    . '<th scope="col">Próxima mensagem</th><th scope="col">Respostas</th></tr></thead><tbody>' . $linhasPessoa . '</tbody></table></div>';
$corpo .= '<nav class="paginacao" aria-label="Páginas"><span>' . count($m['lista']) . ' pessoa' . (count($m['lista']) === 1 ? '' : 's') . ' · página ' . $pagina . ' de ' . $paginas . '</span><span>'
    . ($pagina > 1 ? '<a href="recuperacao.php?p=' . ($pagina - 1) . '">← Anterior</a> ' : '')
    . ($pagina < $paginas ? '<a href="recuperacao.php?p=' . ($pagina + 1) . '">Próxima →</a>' : '')
    . '</span></nav><p class="nota">A hora da próxima mensagem é a prevista: antes de mandar, a rotina confere de novo se a pessoa pagou ou pediu para sair.</p></section>';
rw_pagina($corpo, $sessao);
