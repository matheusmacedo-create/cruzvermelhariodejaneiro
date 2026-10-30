<?php
/**
 * Recuperação por WhatsApp (30/10/2026): painel avulso da secretaria, fora do painel.php, só para ACOMPANHAR o disparo
 * automático das três mensagens a quem se cadastrou e não pagou, e medir o que elas recuperam (por mensagem, tempo até
 * pagar e o grupo de controle, que não recebe nada). A regra e o envio estão em lib/recuperacao_whatsapp.php;
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
:root{--red:#cc0000;--red-dark:#a30000;--black:#0f1318;--text:#1a202c;--muted:#5f6b7a;--line:#e5e9ef;--soft:#f5f6f8;--ok:#0f7b3e;--okbg:#e8f6ee;--warn:#8a5200;--warnbg:#fff4e2;--bad:#8a1c1c;--badbg:#fff0f1;--wa:#dcf8c6}
*{box-sizing:border-box}body{margin:0;font-family:Inter,system-ui,-apple-system,"Segoe UI",Arial,sans-serif;color:var(--text);background:var(--soft);line-height:1.5;font-size:15px}
a{color:var(--red)}img{display:block}
.faixa{height:4px;background:var(--red)}
.topo{background:#fff;border-bottom:1px solid var(--line)}
.topo .linha{display:flex;align-items:center;gap:14px;padding:10px 20px;flex-wrap:wrap}
.topo img{height:34px;width:auto}.topo b{font-size:.95rem;color:var(--black);display:block;line-height:1.2}.topo small{display:block;color:var(--muted);font-size:.78rem}
.topo .usuario{margin-left:auto;font-size:.82rem;color:var(--muted)}
.wrap{max-width:1040px;margin:0 auto;padding:0 20px}main.wrap{padding:24px 20px 64px}
h1{font-size:1.45rem;color:var(--black);margin:0;line-height:1.2}
h2{font-size:1rem;color:var(--black);margin:0 0 14px;display:flex;align-items:center;gap:8px}h2 small{font-size:.8rem;color:var(--muted);font-weight:600;background:var(--soft);border-radius:999px;padding:1px 9px}
h3{font-size:.85rem;color:var(--black);margin:16px 0 8px}
.titulo{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.sub{color:var(--muted);margin:4px 0 0;font-size:.9rem}
.estado{border-radius:999px;padding:7px 14px;font-weight:800;font-size:.85rem;display:inline-flex;align-items:center;gap:8px;white-space:nowrap}
.estado::before{content:"";width:9px;height:9px;border-radius:50%;background:currentColor}
.estado.ok{background:var(--okbg);color:var(--ok)}.estado.talvez{background:var(--warnbg);color:var(--warn)}.estado.nao{background:var(--badbg);color:var(--bad)}
.cartao{background:#fff;border:1px solid var(--line);border-radius:14px;padding:20px;margin-top:16px}
.destaque{border-color:#d9dee6;box-shadow:0 1px 2px rgba(15,19,24,.05)}
.destaque .cab{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;border-bottom:1px solid var(--line);padding-bottom:14px;margin-bottom:6px}
.rotulo{display:block;font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--red);font-weight:800}
.hora{display:block;font-size:1.6rem;color:var(--black);line-height:1.15;margin-top:2px}
.total{text-align:right}.total b{display:block;font-size:1.6rem;line-height:1.1;color:var(--black)}.total span{font-size:.82rem;color:var(--muted)}
.grupo{padding:14px 0;border-bottom:1px solid var(--line)}.grupo:last-of-type{border-bottom:0}
.grupo-cab{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.grupo-cab>div{display:flex;flex-direction:column;line-height:1.25}.grupo-cab small{color:var(--muted);font-size:.78rem;font-family:ui-monospace,Menlo,monospace}
.grupo-cab .qtd{margin-left:auto;font-weight:700;color:var(--black);font-size:.9rem}
.etapa{width:34px;height:34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:.85rem;color:#fff;background:#4a5568;flex:0 0 auto}
.etapa.e1{background:#2f6fde}.etapa.e2{background:#7a4fd6}.etapa.e3{background:var(--red)}
.leads{list-style:none;margin:10px 0 0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px}
.leads li{background:var(--soft);border-radius:10px;padding:8px 12px;line-height:1.3}.leads b{display:block;font-size:.9rem;color:var(--black)}.leads span{font-size:.78rem;color:var(--muted)}
details.previa{margin-top:10px}
details>summary{cursor:pointer;font-weight:700;font-size:.88rem;color:var(--black);padding:6px 0;list-style:none}
details>summary::-webkit-details-marker{display:none}details>summary::before{content:"▸";display:inline-block;width:16px;color:var(--muted)}details[open]>summary::before{content:"▾"}
details>summary:focus-visible{outline:3px solid var(--black);outline-offset:2px;border-radius:4px}
.balao{background:var(--wa);border-radius:4px 14px 14px 14px;padding:12px 14px;max-width:520px;font-size:.9rem;line-height:1.45;margin:6px 0 4px 16px;box-shadow:0 1px 1px rgba(0,0,0,.08)}
.funil{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}
.funil li{background:var(--soft);border-radius:12px;padding:12px 14px;position:relative;display:flex;flex-direction:column;gap:2px}
.funil li span{font-size:.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
.funil li b{font-size:1.6rem;color:var(--black);line-height:1.1;font-variant-numeric:tabular-nums}
.funil li em{font-style:normal;font-weight:800;font-size:.82rem;color:#2f6fde}
.funil li small{font-size:.76rem;color:var(--muted);line-height:1.3}
.funil li.fim{background:var(--okbg)}.funil li.fim b,.funil li.fim em{color:var(--ok)}
@media(max-width:820px){.funil{grid-template-columns:repeat(2,minmax(0,1fr))}.funil li.fim{grid-column:1/-1}}
.tres{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}@media(max-width:820px){.tres{grid-template-columns:minmax(0,1fr)}}
.kpi{border:1px solid var(--line);border-radius:12px;padding:14px 16px}.kpi span{display:block;font-size:.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
.kpi b{display:block;font-size:1.7rem;color:var(--black);line-height:1.15;margin:4px 0}.kpi small{display:block;color:var(--muted);font-size:.8rem;line-height:1.35}
.rolar{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.88rem}
th{text-align:left;font-size:.7rem;letter-spacing:.06em;text-transform:uppercase;color:#4a5568;padding:9px 10px;border-bottom:1px solid var(--line);white-space:nowrap}
td{padding:10px;border-bottom:1px solid var(--line);vertical-align:top}tr:last-child td{border-bottom:0}
td small{display:block;color:var(--muted);font-size:.78rem}th.n,td.n{text-align:right;font-variant-numeric:tabular-nums}
.pessoas td:first-child b{color:var(--black)}
.pontos{display:inline-flex;gap:4px;padding-top:6px}.pontos i{width:10px;height:10px;border-radius:50%;background:#dfe3e8;display:block}.pontos i.feito{background:var(--ok)}
q{display:block;font-size:.82rem;color:var(--text);background:var(--wa);border-radius:4px 10px 10px 10px;padding:4px 8px;margin-bottom:4px;quotes:none}
.mudo{color:#b0b7c2}
.selo{display:inline-block;border-radius:999px;padding:2px 10px;font-size:.72rem;font-weight:800;white-space:nowrap;margin-right:6px}
.selo.ok{background:var(--okbg);color:var(--ok)}.selo.nao{background:var(--badbg);color:var(--bad)}.selo.talvez{background:var(--warnbg);color:var(--warn)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border-radius:999px;padding:9px 16px;font:inherit;font-weight:800;font-size:.88rem;border:1.5px solid transparent;cursor:pointer;text-decoration:none;min-height:40px}
.btn:focus-visible{outline:3px solid var(--black);outline-offset:2px}
.btn-red{background:var(--red);color:#fff}.btn-red:hover{background:var(--red-dark)}
.aviso{border-radius:12px;padding:12px 16px;margin:16px 0 0;font-size:.9rem}.aviso ul{margin:0;padding-left:18px}.aviso li+li{margin-top:4px}.aviso form{margin-top:10px}
.aviso.ok{background:var(--okbg);color:#0f5132;border:1px solid #b7e4c7}.aviso.erro{background:var(--badbg);color:var(--bad);border:1px solid #f5c2c7}
.aviso.atencao{background:var(--warnbg);color:#5c3a00;border:1px solid #f3d9a8}
.nota{color:var(--muted);font-size:.84rem;margin:12px 0 0}.nota.forte{color:var(--bad);font-weight:700}
.paginacao{display:flex;gap:10px;justify-content:space-between;align-items:center;margin-top:14px;font-size:.88rem}
.vazio{padding:22px 10px;text-align:center;color:var(--muted)}
.cartao details{border-top:1px solid var(--line);padding:8px 0}.cartao details:first-of-type{border-top:0}.cartao details[open]{padding-bottom:16px}
.cartao details p{font-size:.88rem;margin:6px 0 0 16px;max-width:760px}
.duas{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px}@media(max-width:820px){.duas{grid-template-columns:minmax(0,1fr)}}
.checks{list-style:none;margin:6px 0 12px;padding:0}.checks li{padding:6px 0;font-size:.88rem}
.barras{list-style:none;margin:10px 0 0;padding:0;display:flex;align-items:flex-end;gap:6px;height:130px;border-bottom:1px solid var(--line)}
.barras li{flex:1;display:flex;align-items:flex-end;justify-content:center;gap:2px;height:100%;position:relative;padding-bottom:18px}
.barras li small{position:absolute;bottom:0;font-size:.62rem;color:var(--muted);white-space:nowrap}
.b{display:inline-block;width:8px;min-height:0;border-radius:3px 3px 0 0}.b.m{background:#2f6fde}.b.r{background:var(--ok)}
.legenda{font-size:.78rem;color:var(--muted);margin:8px 0 0;display:flex;gap:6px;align-items:center}.legenda .b{height:10px;width:10px;border-radius:2px}
@media(max-width:600px){.barras li:nth-child(odd) small{display:none}.hora,.total b{font-size:1.35rem}}
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
// O cupom da 3ª (Primeiros Socorros Básico) vale agora? Decide o modelo da próxima mensagem de cada pessoa na lista.
$oferta = mcp_rw_oferta_cupom($agora);
$m = mcp_rw_metricas($carga['pessoas'], $linhas, $agora, $oferta, $carga['pelo_link']);

$pct = static fn(?float $t): string => $t === null ? '—' : number_format($t * 100, 1, ',', '.') . '%';
$plural = static fn(int $n, string $um, string $varios): string => $n . ' ' . ($n === 1 ? $um : $varios);
$origemNome = static fn(string $o): string => $o === 'escola' ? 'Escola' : 'Site';

// ----------------------------------------------------------------------------- situação (o que impede o envio)
// Primeiro a pergunta "está rodando?": os problemas aparecem no topo, um por linha; o que está ok fica no detalhe.
$problemas = [];
$okItens = [];
if (!$cfg['ok']) {
    $problemas[] = 'Falta ' . implode(' e ', $cfg['falta']) . ' no servidor (api/config-whatsapp.php). Nenhuma mensagem sai.';
} else {
    $okItens[] = 'Chave da Kapso e número da escola configurados';
}
$webhook = 'desconhecido';
$modelosLinhas = [];
$naoAprovados = [];
if ($cfg['ok']) {
    $modelos = mcp_rw_modelos($cfg, $agora);
    $usos = ['rec_inscricao_1_v2' => '1ª mensagem', 'rec_inscricao_2' => '2ª mensagem', 'rec_inscricao_3' => '3ª mensagem',
        'ps_cupom_oferta' => '3ª de Primeiros Socorros, com cupom'];
    if (!$modelos['ok']) {
        $problemas[] = 'A Kapso não respondeu a lista de modelos agora (' . $modelos['erro'] . '). Tente de novo em alguns minutos.';
    } else {
        foreach ($modelos['modelos'] as $nome => $sit) {
            $modelosLinhas[] = [$nome, $usos[$nome] ?? '', $sit];
            if ($sit !== 'APPROVED') {
                $naoAprovados[] = ($usos[$nome] ?? $nome) . ($sit === 'ausente' ? ' (modelo não existe)' : '');
            }
        }
    }
    $webhook = mcp_rw_webhook_situacao($cfg, $agora);
    if ($webhook === 'ligado') {
        $okItens[] = 'Recebimento de respostas ligado (entrega, leitura e "Não tenho interesse")';
    } elseif ($webhook === 'desconhecido') {
        $okItens[] = 'Recebimento de respostas: a Kapso não respondeu agora';
    } else {
        $problemas[] = $webhook === 'diferente'
            ? 'O recebimento de respostas está desacertado na Kapso: entregas, leituras e "Não tenho interesse" não chegam.'
            : 'O recebimento de respostas está desligado: o painel não sabe o que foi entregue nem quem respondeu.';
    }
}
if ($carga['escola']['estado'] === 'indisponivel') {
    $problemas[] = 'A plataforma da escola não respondeu (' . $carga['escola']['erro'] . '). Nada sai até ela voltar, porque não dá para saber quem pagou lá.';
} elseif ($carga['escola']['estado'] === 'ok') {
    $okItens[] = 'Plataforma da escola respondendo';
} else {
    $okItens[] = 'Plataforma da escola desligada: só os cadastros do checkout do site entram';
}
$okItens[] = $oferta !== null
    ? 'Cupom ' . $oferta['codigo'] . ' valendo na 3ª mensagem de Primeiros Socorros (turma de ' . $oferta['data'] . ')'
    : 'Cupom ' . MCP_RW_CUPOM . ' fora (encerrado, esgotado ou sem turma): a 3ª de Primeiros Socorros vai sem cupom';

if ($problemas) {
    $estado = ['nao', 'Parado'];
} elseif ($naoAprovados && count($naoAprovados) === count($modelosLinhas)) {
    $estado = ['talvez', 'Esperando a Meta aprovar os modelos'];
} elseif ($naoAprovados) {
    $estado = ['talvez', 'Rodando em parte'];
} else {
    $estado = ['ok', 'Rodando'];
}

// ----------------------------------------------------------------------------- cabeçalho
$corpo = '<div class="titulo"><div><h1>Recuperação por WhatsApp</h1><p class="sub">3 mensagens automáticas para quem se cadastrou e não pagou · '
    . '1 h, 24 h e 72 h depois · das 8h às 20h</p></div><span class="estado ' . $estado[0] . '">' . rw_e($estado[1]) . '</span></div>';
$retorno = mcp_texto($_GET['webhook'] ?? '', 10);
if ($retorno === 'ok') {
    $corpo .= '<div class="aviso ok" role="status">Pronto: a Kapso vai mandar a entrega, a leitura e as respostas para o site.</div>';
} elseif ($retorno === 'erro') {
    $corpo .= '<div class="aviso erro" role="status">A Kapso não aceitou ligar o recebimento de respostas agora. Tente de novo em alguns minutos.</div>';
}
if ($problemas || $naoAprovados) {
    $corpo .= '<div class="aviso ' . ($problemas ? 'erro' : 'atencao') . '" role="status"><ul>';
    foreach ($problemas as $x) {
        $corpo .= '<li>' . rw_e($x) . '</li>';
    }
    if ($naoAprovados) {
        $corpo .= '<li>Esperando a Meta aprovar: ' . rw_e(implode(', ', $naoAprovados)) . '. Quem deveria receber espera, sem perder a vez.</li>';
    }
    $corpo .= '</ul>';
    if ($cfg['ok'] && $webhook !== 'ligado' && $webhook !== 'desconhecido') {
        $corpo .= '<form method="post" action="recuperacao.php"><input type="hidden" name="acao" value="ligar_webhook">'
            . '<input type="hidden" name="t" value="' . rw_e(mcp_painel_csrf($sessao, 'rw_webhook', 0)) . '">'
            . '<button class="btn btn-red" type="submit">Ligar o recebimento de respostas</button></form>';
    }
    $corpo .= '</div>';
}

// ----------------------------------------------------------------------------- próximo disparo
// A mesma conta da rotina (mcp_rw_proxima com o ajuste de horário) e o mesmo teto por rodada; o grupo de controle nunca
// recebe. A rotina ainda reconfere cada pessoa antes de mandar.
$rodada = mcp_rw_proxima_rodada($agora);
$aReceber = array_values(array_filter($m['lista'], static fn(array $p): bool => !$p['controle']));
$naRodada = array_values(array_filter($aReceber, static fn(array $p): bool => $p['proxima'] <= $rodada));
$depois = array_slice($naRodada, MCP_RW_POR_RODADA);
$naRodada = array_slice($naRodada, 0, MCP_RW_POR_RODADA);
$diaRodada = gmdate('Y-m-d', $rodada - 3 * 3600) === gmdate('Y-m-d', $agora - 3 * 3600) ? 'Hoje' : (gmdate('Y-m-d', $rodada - 3 * 3600) === gmdate('Y-m-d', $agora + 21 * 3600) ? 'Amanhã' : mcp_data_brt(gmdate('Y-m-d H:i:s', $rodada), 'd/m'));
$corpo .= '<section class="cartao destaque"><div class="cab"><div><span class="rotulo">Próximo disparo</span><b class="hora">' . rw_e($diaRodada . ' às ' . mcp_data_brt(gmdate('Y-m-d H:i:s', $rodada), 'H\hi')) . '</b></div>'
    . '<div class="total"><b>' . count($naRodada) . '</b><span>' . (count($naRodada) === 1 ? 'mensagem' : 'mensagens') . '</span></div></div>';
if ($problemas) {
    $corpo .= '<p class="nota forte">Nada sai enquanto o problema acima não for resolvido.</p>';
}
if (!$naRodada) {
    $seguinte = $aReceber[0] ?? null;
    $corpo .= '<p class="vazio">Ninguém para esta rodada.' . ($seguinte ? ' A próxima prevista é a ' . $seguinte['proxima_etapa'] . 'ª mensagem para '
        . rw_e(mcp_rw_primeiro_nome($seguinte['nome'])) . ', ' . rw_e(mb_strtolower(rw_quando($seguinte['proxima']))) . '.' : '') . '</p>';
} else {
    $porModelo = [];
    foreach ($naRodada as $p) {
        $porModelo[$p['modelo']][] = $p;
    }
    uasort($porModelo, static fn(array $a, array $b): int => [$a[0]['proxima_etapa'], $a[0]['modelo']] <=> [$b[0]['proxima_etapa'], $b[0]['modelo']]);
    foreach ($porModelo as $nomeModelo => $pessoas) {
        $nomeModelo = (string) $nomeModelo;
        $etapa = $pessoas[0]['proxima_etapa'];
        $sit = isset($modelos) && $modelos['ok'] ? ($modelos['modelos'][$nomeModelo] ?? 'ausente') : null;
        $selo = $sit === 'APPROVED' ? rw_selo('ok', 'Aprovado') : ($sit === null ? rw_selo('talvez', 'Sem resposta') : rw_selo('talvez', 'Esperando a Meta'));
        $cupom = $nomeModelo === 'ps_cupom_oferta' ? ' com cupom' : '';
        $nomes = '';
        foreach ($pessoas as $p) {
            $nomes .= '<li><b>' . rw_e($p['nome']) . '</b><span>' . rw_e($p['curso']) . ' · ' . $origemNome($p['origem']) . ' · ' . rw_e($p['celular']) . '</span></li>';
        }
        $corpo .= '<div class="grupo"><div class="grupo-cab"><span class="etapa e' . $etapa . '">' . $etapa . 'ª</span><div><b>' . $etapa . 'ª mensagem' . $cupom . '</b>'
            . '<small>' . rw_e($nomeModelo) . '</small></div>' . $selo . '<span class="qtd">' . $plural(count($pessoas), 'lead', 'leads') . '</span></div>'
            . '<ul class="leads">' . $nomes . '</ul>'
            . '<details class="previa"><summary>Ver a mensagem como ' . rw_e(mcp_rw_primeiro_nome($pessoas[0]['nome'])) . ' vai receber</summary><div class="balao">'
            . nl2br(rw_e((string) $pessoas[0]['previa'])) . '</div></details></div>';
    }
    if ($depois) {
        $corpo .= '<p class="nota">Mais ' . $plural(count($depois), 'lead', 'leads') . ' fica para a rodada seguinte (o teto é ' . MCP_RW_POR_RODADA . ' por rodada).</p>';
    }
}
$corpo .= '</section>';

// ----------------------------------------------------------------------------- funil
$enviadas = array_sum($m['etapas']);
// Cada passo com a sua base: entrega e leitura contam MENSAGENS (sobre as enviadas e as entregues); resposta e pagamento
// contam PESSOAS (sobre quem recebeu ao menos uma). "No fluxo agora" fica fora do funil: é quem ainda tem mensagem por vir.
$taxaDe = static fn(int $a, int $b): ?string => $b > 0 ? $pct($a / $b) : null;
$passos = [
    ['Receberam', $m['receberam'], 'pessoas com ao menos 1 mensagem', null],
    ['Entregues', $m['entregues'], 'de ' . $plural($enviadas, 'mensagem enviada', 'mensagens enviadas'), $taxaDe($m['entregues'], $enviadas)],
    ['Lidas', $m['lidas'], 'das entregues', $taxaDe($m['lidas'], $m['entregues'])],
    ['Responderam', $m['responderam'], $m['nao_querem'] > 0 ? $m['nao_querem'] . ' não querem' : 'pessoas', $taxaDe($m['responderam'], $m['receberam'])],
    ['Pagaram', $m['recuperadas'], 'pessoas, depois da mensagem', $taxaDe($m['recuperadas'], $m['receberam'])],
];
$corpo .= '<section class="cartao"><h2>Funil</h2><ol class="funil">';
foreach ($passos as $i => [$rotulo, $valor, $nota, $taxaPasso]) {
    $corpo .= '<li' . ($i === 4 ? ' class="fim"' : '') . '><span>' . rw_e($rotulo) . '</span><b>' . $valor . '</b>'
        . ($taxaPasso !== null ? '<em>' . rw_e($taxaPasso) . '</em>' : '') . '<small>' . rw_e($nota) . '</small></li>';
}
$corpo .= '</ol><p class="nota">Agora: ' . $plural($m['no_fluxo'], 'pessoa espera', 'pessoas esperam') . ' a próxima mensagem'
    . ($m['no_controle'] > 0 ? ' e ' . $m['no_controle'] . ' ' . ($m['no_controle'] === 1 ? 'está' : 'estão') . ' no grupo de controle (não recebem)' : '')
    . '. Enviadas: 1ª ' . $m['etapas'][1] . ' · 2ª ' . $m['etapas'][2] . ' · 3ª ' . $m['etapas'][3] . ($m['falhas'] > 0 ? ' · ' . $plural($m['falhas'], 'não saiu', 'não saíram') : '') . '.</p></section>';

// ----------------------------------------------------------------------------- está funcionando?
$mediana = $m['mediana_horas'] === null ? '—' : number_format($m['mediana_horas'], 1, ',', '.') . ' h';
$efeito = $m['efeito_pp'] === null ? '—' : ($m['efeito_pp'] > 0 ? '+' : '') . number_format($m['efeito_pp'], 1, ',', '.') . ' p.p.';
$amostra = min($m['tratamento']['pessoas'], $m['controle']['pessoas']);
$corpo .= '<section class="cartao"><h2>Está funcionando?</h2><div class="tres">'
    . '<div class="kpi"><span>Taxa de recuperação</span><b>' . rw_e($pct($m['taxa'])) . '</b><small>' . $m['recuperadas'] . ' de ' . $m['receberam'] . ' pagaram depois da 1ª mensagem</small></div>'
    . '<div class="kpi"><span>Tempo até pagar</span><b>' . rw_e($mediana) . '</b><small>mediana entre a 1ª mensagem e o pagamento</small></div>'
    . '<div class="kpi"><span>Efeito da mensagem</span><b>' . rw_e($efeito) . '</b><small>quem recebeu (' . rw_e($pct($m['tratamento']['taxa'])) . ') menos o grupo de controle ('
    . rw_e($pct($m['controle']['taxa'])) . ')</small></div></div>'
    . '<p class="nota">' . ($amostra < 30 ? 'Ainda poucas pessoas (' . $m['tratamento']['pessoas'] . ' receberam, ' . $m['controle']['pessoas'] . ' no controle): o efeito só é confiável com dezenas em cada grupo.'
        : 'Comparação com ' . $m['tratamento']['pessoas'] . ' pessoas que receberam e ' . $m['controle']['pessoas'] . ' no grupo de controle.') . '</p></section>';

// ----------------------------------------------------------------------------- pessoas
$pagina = max(1, (int) ($_GET['p'] ?? 1));
$paginas = max(1, (int) ceil(count($m['lista']) / MCP_RW_POR_PAGINA));
$pagina = min($pagina, $paginas);
$nessa = array_slice($m['lista'], ($pagina - 1) * MCP_RW_POR_PAGINA, MCP_RW_POR_PAGINA);
$corpo .= '<section class="cartao"><h2>Pessoas no fluxo <small>' . count($m['lista']) . '</small></h2>';
if (!$nessa) {
    $corpo .= '<p class="vazio">Ninguém no fluxo agora.</p></section>';
} else {
    $linhasPessoa = '';
    foreach ($nessa as $p) {
        $pontos = '';
        for ($e = 1; $e <= 3; $e++) {
            $pontos .= '<i class="' . ($p['etapa'] >= $e ? 'feito' : '') . '" title="' . $e . 'ª mensagem"></i>';
        }
        $respostas = '';
        foreach ($p['respostas'] as $r) {
            $respostas .= '<q>' . rw_e($r) . '</q>';
        }
        $proxima = $p['controle'] ? rw_selo('talvez', 'Grupo de controle') . '<small>não recebe</small>'
            : rw_e($p['proxima_etapa'] . 'ª · ' . rw_quando($p['proxima'])) . ($p['modelo'] === 'ps_cupom_oferta' ? '<small>com cupom</small>' : '');
        $linhasPessoa .= '<tr><td><b>' . rw_e($p['nome']) . '</b><small>' . rw_e($p['celular']) . ' · ' . $origemNome($p['origem']) . '</small></td>'
            . '<td>' . rw_e($p['curso']) . '</td><td><span class="pontos" aria-label="' . $p['etapa'] . ' de 3 mensagens enviadas">' . $pontos . '</span></td>'
            . '<td>' . $proxima . '</td><td>' . ($respostas !== '' ? $respostas : '<span class="mudo">—</span>') . '</td></tr>';
    }
    $corpo .= '<div class="rolar"><table class="pessoas"><thead><tr><th scope="col">Pessoa</th><th scope="col">Curso</th><th scope="col">Enviadas</th>'
        . '<th scope="col">Próxima</th><th scope="col">Respostas</th></tr></thead><tbody>' . $linhasPessoa . '</tbody></table></div>';
    if ($paginas > 1) {
        $corpo .= '<nav class="paginacao" aria-label="Páginas"><span>Página ' . $pagina . ' de ' . $paginas . '</span><span>'
            . ($pagina > 1 ? '<a href="recuperacao.php?p=' . ($pagina - 1) . '">← Anterior</a> ' : '')
            . ($pagina < $paginas ? '<a href="recuperacao.php?p=' . ($pagina + 1) . '">Próxima →</a>' : '') . '</span></nav>';
    }
    $corpo .= '</section>';
}

// ----------------------------------------------------------------------------- detalhes (recolhidos)
$barras = '';
$maxDia = max([1, ...array_values(array_map(static fn(array $d): int => max($d['mensagens'], $d['recuperacoes']), $m['dias']))]);
foreach ($m['dias'] as $dia => $d) {
    $rot = substr($dia, 8, 2) . '/' . substr($dia, 5, 2);
    $barras .= '<li title="' . rw_e($rot . ': ' . $d['mensagens'] . ' mensagens, ' . $d['recuperacoes'] . ' recuperações') . '"><span class="b m" style="height:' . (int) round(100 * $d['mensagens'] / $maxDia) . '%"></span>'
        . '<span class="b r" style="height:' . (int) round(100 * $d['recuperacoes'] / $maxDia) . '%"></span><small>' . rw_e($rot) . '</small></li>';
}
$tabela = static function (array $cab, array $linhas): string {
    $h = '<div class="rolar"><table><thead><tr>';
    foreach ($cab as $i => $c) {
        $h .= '<th scope="col"' . ($i > 0 ? ' class="n"' : '') . '>' . rw_e($c) . '</th>';
    }
    $h .= '</tr></thead><tbody>';
    foreach ($linhas as $l) {
        $h .= '<tr>';
        foreach ($l as $i => $v) {
            $h .= '<td' . ($i > 0 ? ' class="n"' : '') . '>' . rw_e((string) $v) . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . ($linhas ? '' : '<tr><td colspan="' . count($cab) . '" class="vazio">Nada ainda.</td></tr>') . '</tbody></table></div>';
};
$porMensagem = [];
foreach ($m['por_mensagem'] as $etapa => $n) {
    $porMensagem[] = [$etapa . 'ª mensagem', $n];
}
$porModeloT = [];
foreach ($m['por_modelo'] as $nome => $x) {
    $porModeloT[] = [(string) $nome, $x['enviadas'], $x['recuperadas']];
}
$peloLink = [];
foreach ($m['pelo_link'] as $campanha => $n) {
    $peloLink[] = [(string) $campanha, $n];
}
$porOrigemT = [];
foreach ($m['origens'] as $origem => $o) {
    $porOrigemT[] = [$origem === 'escola' ? 'Plataforma da escola' : 'Checkout do site', $o['pessoas'], $o['mensagens'], $o['recuperadas']];
}
$porCursoT = [];
foreach ($m['cursos'] as $nome => $c) {
    $porCursoT[] = [(string) $nome, $c['pessoas'], $c['mensagens'], $c['recuperadas']];
}
$modelosT = [];
foreach ($modelosLinhas as [$nome, $uso, $sit]) {
    $modelosT[] = [$nome . ($uso !== '' ? ' (' . $uso . ')' : ''), $sit === 'APPROVED' ? 'Aprovado' : ($sit === 'ausente' ? 'Não existe' : 'Esperando (' . $sit . ')')];
}
$corpo .= '<section class="cartao"><h2>Detalhes</h2>'
    . '<details><summary>Mensagens e pagamentos por dia (últimos ' . MCP_RW_DIAS_METRICA . ' dias)</summary><ul class="barras">' . $barras . '</ul>'
    . '<p class="legenda"><span class="b m"></span> mensagens <span class="b r"></span> pagamentos</p></details>'
    . '<details><summary>Qual mensagem trouxe o pagamento</summary><div class="duas">'
    . '<div><h3>Última mensagem antes de pagar</h3>' . $tabela(['Mensagem', 'Pessoas'], $porMensagem) . '</div>'
    . '<div><h3>Pelo link do botão</h3>' . $tabela(['Campanha', 'Pagamentos'], $peloLink) . '<p class="nota">Só o checkout do site guarda a campanha; a escola não.</p></div></div>'
    . '<h3>Por modelo</h3>' . $tabela(['Modelo', 'Enviadas', 'Recuperadas'], $porModeloT) . '</details>'
    . '<details><summary>Por origem e por curso</summary><div class="duas"><div>' . $tabela(['Origem', 'Pessoas', 'Mensagens', 'Pagaram'], $porOrigemT) . '</div>'
    . '<div>' . $tabela(['Curso', 'Pessoas', 'Mensagens', 'Pagaram'], $porCursoT) . '</div></div></details>'
    . '<details><summary>Configuração e modelos na Meta</summary><ul class="checks">';
foreach ($okItens as $x) {
    $corpo .= '<li>' . rw_selo('ok', 'Ok') . rw_e($x) . '</li>';
}
$corpo .= '</ul>' . $tabela(['Modelo', 'Situação na Meta'], $modelosT) . '</details>'
    . '<details><summary>Como funciona</summary><p>Quem se cadastrou no checkout do site ou na plataforma da escola nos últimos ' . MCP_RW_JANELA_DIAS . ' dias e não pagou recebe até 3 mensagens: '
    . '1 h, 24 h e 72 h depois do cadastro (com pelo menos 21 h e 48 h entre uma e a seguinte), só das 8h às 20h, no máximo ' . MCP_RW_POR_RODADA . ' por rodada e ' . MCP_RW_POR_DIA . ' em 24 h. '
    . 'Em Primeiros Socorros Básico, enquanto o cupom ' . MCP_RW_CUPOM . ' vale e há turma aberta, a 3ª leva o cupom. Quem paga em qualquer um dos dois sistemas, responde "Não tenho interesse" ou não tem celular válido sai na hora; '
    . 'antes de cada envio a rotina confere tudo de novo.</p><p>' . MCP_RW_CONTROLE_PCT . '% das pessoas, sorteadas, ficam no grupo de controle e não recebem nada: é a comparação com elas que mostra quanto as mensagens recuperam de verdade.</p></details>'
    . '</section>';
rw_pagina($corpo, $sessao);
