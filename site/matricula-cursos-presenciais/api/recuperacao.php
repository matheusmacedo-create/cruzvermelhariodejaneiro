<?php
/**
 * Recuperação por WhatsApp e e-mail (30/10/2026): painel avulso da secretaria, fora do painel.php, só para ACOMPANHAR o
 * disparo automático das três mensagens (WhatsApp) e dos três e-mails a quem se cadastrou e não pagou, e medir o que eles
 * recuperam (por mensagem, tempo até pagar e o grupo de controle, que não recebe nada). A regra e o envio estão em
 * lib/recuperacao_whatsapp.php e lib/recuperacao_email.php; quem manda é a rotina de hora em hora (api/lembretes.php),
 * nunca esta página.
 *
 *  - Acesso: a sessão do Portal da secretaria (mcp_painel_sessao; o cookie vale para api/). Sem sessão, só o link de
 *    entrada pelo Portal, nenhum dado (POST sem sessão: 403).
 *  - Só leitura, exceto o botão "Ligar o recebimento de respostas" (POST com o token do formulário), que cria na Kapso o
 *    webhook apontando para api/kapso-webhook.php com o segredo do site.
 *  - HTML gerado no servidor, sem JavaScript; nada se mexe sozinho (nem atualização automática nem animação);
 *    noindex e no-store. As chaves da Kapso e da Resend nunca aparecem aqui: só "ok" ou o nome do que falta. Os e-mails
 *    aparecem só mascarados (m***@dominio), e os números agregados não têm dado pessoal.
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
        . '<meta name="robots" content="noindex, nofollow"><title>Recuperação por WhatsApp e e-mail · Portal da secretaria</title>'
        . '<link rel="icon" type="image/png" href="/assets/favicon.png"><style>' . rw_css() . '</style></head><body><div class="faixa"></div>'
        . '<header class="topo"><div class="wrap linha"><a href="painel.php"><img src="' . $logo . '" width="120" height="36" alt="Cruz Vermelha Brasileira · Rio de Janeiro"></a>'
        . '<div><b>Recuperação por WhatsApp e e-mail</b><small>Portal da secretaria</small></div>'
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
.etapa.em{background:#0f1318}
.carta{background:#fff;border:1px solid var(--line);border-left:4px solid var(--red);border-radius:4px 12px 12px 4px;padding:12px 14px;max-width:560px;font-size:.88rem;line-height:1.45;margin:6px 0 4px 16px;white-space:pre-line}
.carta b{display:block;margin-bottom:6px;color:var(--black)}
.canais{display:flex;flex-direction:column;gap:4px;align-items:flex-start}
.canal{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:2px 10px 2px 4px;font-size:.76rem;font-weight:700;white-space:nowrap;background:var(--soft);color:var(--text)}
.canal i{font-style:normal;font-weight:800;font-size:.68rem;border-radius:999px;padding:1px 6px;color:#fff}
.canal.wa i{background:#128c4a}.canal.em i{background:#0f1318}.canal.fora{color:#9aa3ae}.canal.fora i{background:#b0b7c2}
.subtotal{display:block;font-size:.82rem;color:var(--muted);margin-top:2px}
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
    rw_pagina('<div class="cartao entrar"><h1>Recuperação por WhatsApp e e-mail</h1><p>Esta página é da secretaria. Entre pelo Portal com o link enviado ao e-mail da equipe e volte aqui.</p>'
        . '<p><a class="btn btn-red" href="painel.php">Entrar pelo Portal da secretaria</a></p></div>', null, $metodo === 'POST' ? 403 : 200);
}
$cfg = mcp_rw_kapso_config();
$emailCfg = mcp_rw_email_config();

// ----------------------------------------------------------------------------- as ações: ligar o webhook e "já pagou na escola"
if ($metodo === 'POST' && ($_POST['acao'] ?? '') === 'mp_pagou_escola') {
    // Matrícula pendente: a secretaria marca que a pessoa pagou a matrícula na escola (com a plataforma da escola fora do
    // ar, o site não tem como saber). A inscrição sai da régua e conta como paga. Token do formulário por inscrição.
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0 || !hash_equals(mcp_painel_csrf($sessao, 'mp_pagou', $id), mcp_texto($_POST['t'] ?? '', 40))) {
        rw_pagina('<div class="aviso erro">O formulário venceu ou não é desta sessão. <a href="recuperacao.php">Abra a página de novo</a> e tente outra vez.</div>', $sessao, 403);
    }
    mcp_mp_marcar_pago_escola($id, $sessao);
    header('Location: recuperacao.php?mp=ok#matricula', true, 303);
    exit;
}
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
    $linhasEmail = mcp_rw_email_linhas();
    $emailsRecuperacao = mcp_rw_email_enviados_24h($agora);
    $emailsSite = mcp_rw_email_site_24h($agora);
    // A última rodada de cada canal (o resumo que a rotina grava em mcp_eventos): o que saiu e, por pessoa, por que quem
    // estava devido não recebeu. E quantas vezes os links curtos foram abertos em 24 h (api/ir.php), por canal.
    $resumoWa = mcp_rw_resumo_ultimo('recuperacao_rodada');
    $resumoEm = mcp_rw_resumo_ultimo('recuperacao_rodada_email');
    $stmtLinks = mcp_db()->prepare("SELECT LEFT(detalhe, 1) AS fonte, COUNT(*) AS n FROM mcp_eventos WHERE tipo = 'recuperacao_link' AND criado_em > ? GROUP BY LEFT(detalhe, 1)");
    $stmtLinks->execute([gmdate('Y-m-d H:i:s', $agora - 86400)]);
    $aberturas = array_map('intval', array_column($stmtLinks->fetchAll(), 'n', 'fonte'));
    // Matrícula pendente (lib/matricula_pendente.php): o público, os números e a última rodada.
    $cargaMp = mcp_mp_carregar($agora);
    $mp = mcp_mp_metricas($cargaMp, $agora, mcp_mp_pelo_link($agora - MCP_MP_JANELA_DIAS * 86400));
    $stmtMp = mcp_db()->prepare("SELECT detalhe FROM mcp_eventos WHERE tipo = 'matricula_rodada' ORDER BY id DESC LIMIT 1");
    $stmtMp->execute();
    $resumoMp = json_decode((string) $stmtMp->fetchColumn(), true);
    $resumoMp = is_array($resumoMp) && isset($resumoMp['em']) ? $resumoMp : null;
} catch (PDOException $e) {
    // Logo depois de publicar, a tabela nova pode ainda não existir (a migração do db.php é adiada por 60 s quando uma
    // transação longa segura o banco): a página diz isso, em vez de um erro 500.
    error_log('[matricula] recuperação por WhatsApp sem banco pronto: ' . $e->getMessage());
    rw_pagina('<div class="aviso erro" role="status">O banco ainda não está pronto para esta página (a tabela é criada na primeira visita depois da publicação). '
        . 'Tente de novo em 1 minuto; se continuar assim, avise o responsável pelo site.</div>', $sessao, 503);
}
// Os cupons da 3ª (MCP_RW_OFERTAS: Primeiros Socorros Básico, Lei Lucas) valem agora? Decidem o modelo da próxima
// mensagem de cada pessoa na lista.
$oferta = mcp_rw_ofertas_cupom(array_keys(MCP_RW_OFERTAS), $agora);
// A lista de modelos da Meta (cache de 10 min): com ela, a lista de pessoas mostra o modelo da família que vai sair.
$modelos = $cfg['ok'] ? mcp_rw_modelos($cfg, $agora) : null;
$m = mcp_rw_metricas($carga['pessoas'], $linhas, $agora, $oferta, $carga['pelo_link'], $linhasEmail, $carga['pelo_link_email'], $modelos);

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
$naoAprovadosMp = [];
if ($cfg['ok'] && $modelos !== null) {
    $usos = ['rec_inscricao_1' => '1ª mensagem', 'rec_inscricao_2' => '2ª mensagem', 'rec_inscricao_3' => '3ª mensagem',
        'ps_cupom_oferta' => '3ª de Primeiros Socorros, com cupom', 'll_cupom_oferta' => '3ª da Lei Lucas, com cupom', 'cur_cupom_oferta' => '3ª do Curativos, com cupom', 'mat_pendente_1' => 'matrícula pendente, 1ª', 'mat_pendente_2' => 'matrícula pendente, 2ª'];
    if (!$modelos['ok']) {
        $problemas[] = 'A Kapso não respondeu a lista de modelos agora (' . $modelos['erro'] . '). Tente de novo em alguns minutos.';
    } else {
        // Por família (01/11/2026): a versão que vai sair (a de maior versão aprovada como utilidade; senão a aprovada de
        // qualquer categoria) e as outras que a Meta tem. Uma família sem nenhuma aprovada segura só as suas mensagens.
        foreach (MCP_RW_FAMILIAS as $familia => $padrao) {
            $escolhido = mcp_rw_escolher_modelo(['nome' => $padrao, 'parametros' => []], ['estrutura' => []] + $modelos)['nome'];
            $sit = $modelos['modelos'][$escolhido] ?? 'ausente';
            $outros = array_values(array_filter(array_keys($modelos['modelos']), static fn(string $n): bool => mcp_rw_familia($n) === $familia && $n !== $escolhido));
            $modelosLinhas[] = [$escolhido, $usos[$familia] ?? '', $sit, $modelos['categorias'][$escolhido] ?? '',
                implode(', ', array_map(static fn(string $n): string => $n . ' (' . strtolower($modelos['modelos'][$n]) . ($modelos['categorias'][$n] ?? '' ? ', ' . strtolower($modelos['categorias'][$n]) : '') . ')', $outros))];
            // Um modelo de cupom sem aprovação não segura ninguém (a 3ª vai sem cupom): o aviso é a linha do cupom, acima.
            if ($sit !== 'APPROVED' && !in_array($familia, array_column(MCP_RW_OFERTAS, 'modelo'), true)) {
                $texto = ($usos[$familia] ?? $padrao) . ($sit === 'ausente' ? ' (modelo não existe)' : '');
                str_starts_with($familia, 'mat_') ? $naoAprovadosMp[] = $texto : $naoAprovados[] = $texto;
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
$avisosEmail = [];
if ($carga['escola']['estado'] === 'indisponivel') {
    $avisosEmail[] = 'A plataforma da escola não respondeu (' . $carga['escola']['erro'] . '): seguem só os cadastros do checkout do site e os importados. Quem pagou só na escola pode receber um lembrete.';
} elseif ($carga['escola']['estado'] === 'ok') {
    $okItens[] = 'Plataforma da escola respondendo';
} else {
    $okItens[] = 'Plataforma da escola desligada: só os cadastros do checkout do site entram';
}
// E-mail: só a RESEND_API_KEY (a mesma dos outros e-mails do site). Sem ela nenhum e-mail da recuperação sai, mas o
// WhatsApp segue; por isso é um aviso à parte, e não um "Parado".
$cotaHoje = $emailsSite + $emailsRecuperacao;
if (!$emailCfg['ok']) {
    $avisosEmail[] = 'Falta RESEND_API_KEY no servidor: nenhum e-mail da recuperação sai (o WhatsApp segue).';
} else {
    $okItens[] = 'Resend configurada: e-mails da recuperação ligados';
    if ($emailsRecuperacao >= MCP_RW_EMAIL_POR_DIA) {
        $avisosEmail[] = 'Teto de ' . MCP_RW_EMAIL_POR_DIA . ' e-mails da recuperação em 24 h atingido: os próximos esperam a janela andar.';
    }
    if ($emailsSite >= MCP_RW_EMAIL_RESERVA_SITE) {
        $avisosEmail[] = "O site já mandou $emailsSite e-mails nas últimas 24 h: a recuperação por e-mail espera, para a cota da Resend sobrar para os recibos.";
    }
}
foreach (MCP_RW_OFERTAS as $slugOferta => $defOferta) {
    $cursoOferta = (string) (mcp_curso($slugOferta)['nome'] ?? $slugOferta);
    $okItens[] = isset($oferta[$slugOferta])
        ? 'Cupom ' . $oferta[$slugOferta]['codigo'] . ' valendo na 3ª mensagem de ' . $cursoOferta . ' (turma de ' . $oferta[$slugOferta]['data'] . ')'
            . ($modelos !== null && $modelos['ok'] && ($modelos['modelos'][$defOferta['modelo']] ?? '') !== 'APPROVED'
                ? '; no WhatsApp, o ' . $defOferta['modelo'] . ' espera a Meta e a 3ª vai sem cupom (o e-mail já leva)' : '')
        : 'Cupom ' . $defOferta['cupom'] . ' fora (encerrado, esgotado ou sem turma): a 3ª de ' . $cursoOferta . ' vai sem cupom';
}

// O estado junta os dois canais: o e-mail não depende da Kapso nem da Meta, então um problema só do WhatsApp com o e-mail
// ligado é "só o e-mail rodando", e não "Parado". A escola fora não para nada: seguem o site e os importados (aviso acima).
$todosEsperando = $naoAprovados && count($naoAprovados) === count($modelosLinhas) - 2 - count(MCP_RW_OFERTAS);
if ($problemas && !$emailCfg['ok']) {
    $estado = ['nao', 'Parado'];
} elseif ($problemas) {
    $estado = ['talvez', 'Só o e-mail rodando'];
} elseif ($todosEsperando) {
    $estado = ['talvez', $emailCfg['ok'] ? 'E-mail rodando; WhatsApp esperando a Meta aprovar os modelos' : 'Esperando a Meta aprovar os modelos'];
} elseif ($naoAprovados || !$emailCfg['ok'] || $carga['escola']['estado'] === 'indisponivel') {
    $estado = ['talvez', 'Rodando em parte'];
} else {
    $estado = ['ok', 'Rodando'];
}

// ----------------------------------------------------------------------------- cabeçalho
$corpo = '<div class="titulo"><div><h1>Recuperação por WhatsApp e e-mail</h1><p class="sub">3 mensagens e 3 e-mails automáticos para quem se cadastrou e não pagou · '
    . '1 h (30 min no WhatsApp de quem gerou PIX), 24 h e 72 h depois · das 8h às 20h · e a matrícula pendente de quem pagou só a taxa</p></div><span class="estado ' . $estado[0] . '">' . rw_e($estado[1]) . '</span></div>';
$retorno = mcp_texto($_GET['webhook'] ?? '', 10);
if ($retorno === 'ok') {
    $corpo .= '<div class="aviso ok" role="status">Pronto: a Kapso vai mandar a entrega, a leitura e as respostas para o site.</div>';
} elseif ($retorno === 'erro') {
    $corpo .= '<div class="aviso erro" role="status">A Kapso não aceitou ligar o recebimento de respostas agora. Tente de novo em alguns minutos.</div>';
}
if ($problemas || $naoAprovados || $avisosEmail) {
    $corpo .= '<div class="aviso ' . ($problemas ? 'erro' : 'atencao') . '" role="status"><ul>';
    foreach (array_merge($problemas, $avisosEmail) as $x) {
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

// ----------------------------------------------------------------------------- matrícula pendente
// Quem pagou só a taxa e ainda não pagou a matrícula (lib/matricula_pendente.php): 2 mensagens e 2 e-mails, sem grupo de
// controle. Primeiro na página: é o fluxo mais importante agora, e tem data de aula.
$wa = $mp['enviadas']['whatsapp'];
$ema = $mp['enviadas']['email'];
$corpo .= '<section class="cartao destaque" id="matricula"><div class="cab"><div><span class="rotulo">Pagou a inscrição, falta a matrícula</span>'
    . '<b class="hora">Matrícula pendente</b></div><div class="total"><b>' . $mp['pendentes'] . '</b><span>' . ($mp['pendentes'] === 1 ? 'aluno com a matrícula em aberto' : 'alunos com a matrícula em aberto')
    . '</span></div></div>';
if (mcp_texto($_GET['mp'] ?? '', 4) === 'ok') {
    $corpo .= '<div class="aviso ok" role="status">Pronto: marcado como pago na escola. Essa pessoa não recebe mais os lembretes da matrícula.</div>';
}
$avisosMp = [];
if ($cargaMp['escola']['estado'] === 'indisponivel') {
    $avisosMp[] = 'A plataforma da escola não respondeu (' . $cargaMp['escola']['erro'] . '): o site não sabe quem pagou a matrícula na escola. Quem pagou lá pode receber um lembrete; use "Já pagou na escola" na lista para parar.';
} elseif ($cargaMp['escola']['estado'] === 'desligada') {
    $avisosMp[] = 'Plataforma da escola desligada: o site só sabe das matrículas pagas pelo site. Quem pagou na escola: use "Já pagou na escola" na lista.';
}
if ($naoAprovadosMp) {
    $avisosMp[] = 'Esperando a Meta aprovar: ' . implode(', ', $naoAprovadosMp) . '. Os e-mails seguem; o WhatsApp espera, sem perder a vez.';
}
if (!$cfg['ok']) {
    $avisosMp[] = 'Sem a Kapso configurada: só os e-mails saem.';
}
if (!$emailCfg['ok']) {
    $avisosMp[] = 'Sem a RESEND_API_KEY: os e-mails da matrícula não saem.';
}
if ($avisosMp) {
    $corpo .= '<div class="aviso atencao" role="status"><ul>' . implode('', array_map(static fn(string $x): string => '<li>' . rw_e($x) . '</li>', $avisosMp)) . '</ul></div>';
}
$corpo .= '<ol class="funil" style="margin-top:14px">'
    . '<li><span>Em aberto agora</span><b>' . $mp['pendentes'] . '</b><small>taxa paga, matrícula não, turma marcada' . ($mp['sem_turma'] > 0 ? ' · ' . $mp['sem_turma'] . ' sem data de turma' : '') . '</small></li>'
    . '<li><span>1ª mensagem</span><b>' . ($wa[1] + $ema[1]) . '</b><small>' . $wa[1] . ' WhatsApp · ' . $ema[1] . ' e-mail</small></li>'
    . '<li><span>2ª mensagem</span><b>' . ($wa[2] + $ema[2]) . '</b><small>' . $wa[2] . ' WhatsApp · ' . $ema[2] . ' e-mail (véspera ou 48 h antes)</small></li>'
    . '<li><span>Receberam</span><b>' . $mp['receberam'] . '</b><small>alunos com ao menos 1 mensagem</small></li>'
    . '<li class="fim"><span>Pagaram depois</span><b>' . $mp['pagaram_depois'] . '</b>' . ($mp['receberam'] > 0 ? '<em>' . rw_e($pct($mp['pagaram_depois'] / $mp['receberam'])) . '</em>' : '')
    . '<small>a matrícula, depois da 1ª mensagem</small></li></ol>';
$peloLinkMp = array_sum($mp['pelo_link']['whatsapp']) + array_sum($mp['pelo_link']['email']);
$corpo .= '<p class="nota">Pagaram depois da mensagem: ' . $mp['depois_como']['site'] . ' pelo site (' . $peloLinkMp . ' pelo botão de uma mensagem: '
    . ($mp['pelo_link']['whatsapp']['mat1'] + $mp['pelo_link']['whatsapp']['mat2']) . ' WhatsApp, ' . ($mp['pelo_link']['email']['mat1'] + $mp['pelo_link']['email']['mat2']) . ' e-mail), '
    . $mp['depois_como']['escola'] . ' na escola (pela plataforma), ' . $mp['depois_como']['painel'] . ' marcados aqui como "já pagou na escola"'
    . ($mp['depois_como']['completa'] > 0 ? ', ' . $mp['depois_como']['completa'] . ' comprando de novo com a matrícula' : '')
    . ($mp['mediana_horas'] !== null ? '. Mediana: ' . number_format($mp['mediana_horas'], 1, ',', '.') . ' h entre a 1ª mensagem e o pagamento' : '')
    . '. Sem grupo de controle: a pessoa já é aluna e precisa pagar para entrar na aula.'
    . ($mp['turma_passou'] > 0 ? ' ' . $plural($mp['turma_passou'], 'turma já começou', 'turmas já começaram') . ' sem a matrícula paga (não recebem mais).' : '') . '</p>';
if ($resumoMp !== null) {
    $contagem = [];
    foreach ((array) ($resumoMp['p'] ?? []) as $codigo) {
        $base = explode(':', (string) $codigo, 2)[0];
        $contagem[$base] = ($contagem[$base] ?? 0) + 1;
    }
    $partes = [];
    foreach ($contagem as $base => $n) {
        $partes[] = $n . ' — ' . (MCP_MP_MOTIVOS_RODADA[$base] ?? $base);
    }
    $corpo .= '<p class="nota">Última rodada (' . rw_e(mb_strtolower(rw_quando((int) $resumoMp['em']))) . '): ' . (int) ($resumoMp['w']['enviados'] ?? 0) . ' WhatsApp e '
        . (int) ($resumoMp['e']['enviados'] ?? 0) . ' e-mail' . ((string) ($resumoMp['motivo'] ?? '') !== '' ? '. ' . rw_e((string) $resumoMp['motivo']) : '')
        . ($partes ? '. Devidos que não receberam: ' . rw_e(implode('; ', $partes)) : '') . '.</p>';
}
if (!$mp['lista']) {
    $corpo .= '<p class="vazio">Ninguém com a matrícula em aberto agora.</p>';
} else {
    $motivoMp = static fn(?string $x, string $sem): string => match ($x) {
        'sem_celular', 'sem_email' => $sem, 'bloqueou' => 'bloqueado', 'descadastrou' => 'pediu para parar', default => 'encerrado',
    };
    $selo = static function (string $classe, string $rotulo, array $c, string $sem) use ($motivoMp): string {
        $fora = $c['motivo'] !== null || ($c['proxima'] === null && $c['enviadas'] < 2);
        $texto = $c['motivo'] !== null ? ($c['enviadas'] > 0 ? $c['enviadas'] . ' de 2 · ' : '') . $motivoMp($c['motivo'], $sem) : $c['enviadas'] . ' de 2';
        return '<span class="canal ' . $classe . ($fora ? ' fora' : '') . '"><i>' . $rotulo . '</i>' . rw_e($texto) . '</span>';
    };
    $linhasMp = '';
    foreach (array_slice($mp['lista'], 0, MCP_RW_POR_PAGINA) as $p) {
        $proximaMp = '';
        foreach (['whatsapp' => 'WhatsApp', 'canal_email' => 'E-mail'] as $k => $rotulo) {
            $px = $p[$k]['proxima'];
            $proximaMp .= $px !== null ? '<div>' . $rotulo . ': ' . rw_e($px['etapa'] . 'ª · ' . rw_quando($px['quando'])) . '</div>' : '';
        }
        $respostasMp = implode('', array_map(static fn(string $r): string => '<q>' . rw_e($r) . '</q>', $p['respostas']));
        $linhasMp .= '<tr><td><b>' . rw_e($p['nome']) . '</b><small>' . implode(' · ', array_filter([rw_e($p['celular']), rw_e($p['email'])])) . '</small>' . $respostasMp . '</td>'
            . '<td>' . rw_e($p['curso']) . '<small>turma de ' . rw_e($p['turma']) . '</small></td>'
            . '<td><span class="canais">' . $selo('wa', 'WhatsApp', $p['whatsapp'], 'sem celular') . $selo('em', 'E-mail', $p['canal_email'], 'sem e-mail') . '</span></td>'
            . '<td>' . ($proximaMp !== '' ? $proximaMp : '<span class="mudo">—</span>') . '</td>'
            . '<td><form method="post" action="recuperacao.php"><input type="hidden" name="acao" value="mp_pagou_escola"><input type="hidden" name="id" value="' . (int) $p['inscricao_id'] . '">'
            . '<input type="hidden" name="t" value="' . rw_e(mcp_painel_csrf($sessao, 'mp_pagou', (int) $p['inscricao_id'])) . '"><button class="btn" type="submit" style="border-color:var(--line)">Já pagou na escola</button></form></td></tr>';
    }
    $corpo .= '<div class="rolar"><table class="pessoas"><thead><tr><th scope="col">Aluno</th><th scope="col">Curso</th><th scope="col">Enviadas</th><th scope="col">Próxima</th><th scope="col">Na escola</th></tr></thead><tbody>'
        . $linhasMp . '</tbody></table></div>'
        . (count($mp['lista']) > MCP_RW_POR_PAGINA ? '<p class="nota">Mostrando as ' . MCP_RW_POR_PAGINA . ' turmas mais próximas de ' . count($mp['lista']) . '.</p>' : '');
}
$corpo .= '<details><summary>Como funciona</summary><p>Quem pagou só a taxa de inscrição (o plano padrão do checkout) tem a vaga, mas a entrada na aula depende da matrícula. '
    . 'A 1ª mensagem (WhatsApp mat_pendente_1 e um e-mail) sai 1 h depois da confirmação da taxa; a 2ª (mat_pendente_2 e outro e-mail), na véspera da turma, às 10h, ou 48 h antes, se a turma está a mais de '
    . MCP_MP_LONGE_DIAS . ' dias da taxa. Se a 2ª já chegou, vai só ela; nada sai a menos de 1 h do início, nem depois. Só das 8h às 20h.</p>'
    . '<p>O botão leva à página de pagar a matrícula, que cobra só a matrícula da inscrição (PIX ou cartão à vista) com os dados que a pessoa já deu. '
    . 'Paga, o aluno recebe o comprovante e a secretaria recebe um aviso para marcar a matrícula como paga na plataforma da escola. Quem já pagou (no site, na escola pela plataforma ou marcado aqui), bloqueou o canal ou pediu para parar não recebe. '
    . 'As mensagens contam no mesmo teto diário da recuperação (WhatsApp ' . MCP_RW_POR_DIA . ' e e-mail ' . MCP_RW_EMAIL_POR_DIA . ' em 24 h) e saem antes dela.</p></details></section>';

// ----------------------------------------------------------------------------- oferta a ex-alunos
// Disparo único do cupom de um curso a quem já estudou na escola (lib/oferta_alunos.php). Só aparece com a lista no
// servidor ou com alguma mensagem já enviada; nenhum nome nem número, só contagens.
$oa = mcp_oa_metricas($agora);
if ($oa !== null && ($oa['na_lista'] > 0 || $oa['enviadas'] + $oa['falharam'] > 0)) {
    $cursoOa = (string) (mcp_curso(MCP_OA_CURSO)['nome'] ?? MCP_OA_CURSO);
    $modeloOa = (string) (MCP_RW_OFERTAS[MCP_OA_CURSO]['modelo'] ?? MCP_RW_MODELO_CUPOM_GERAL);
    $situacaoOa = $modelos !== null && ($modelos['ok'] ?? false) ? (string) ($modelos['modelos'][$modeloOa] ?? 'ausente') : null;
    // Com o modelo geral aprovado, a oferta sai por ele enquanto o do curso espera: não há o que avisar.
    if ($situacaoOa !== null && $situacaoOa !== 'APPROVED' && (($modelos['modelos'][MCP_RW_MODELO_CUPOM_GERAL] ?? '') === 'APPROVED'
        || ($modelos['modelos'][MCP_OA_MODELO_TURMA] ?? '') === 'APPROVED')) {
        $situacaoOa = 'APPROVED';
    }
    $avisosOa = [];
    if (!$oa['ligada']) {
        $avisosOa[] = 'Desligada: a lista está no servidor, mas OFERTA_ALUNOS_LIGADA não é true. Nada sai.';
    }
    if ($oa['oferta'] === null) {
        $avisosOa[] = 'A oferta não vale agora (cupom encerrado ou sem vaga, ou o curso sem turma aberta). Nada sai.';
    }
    if ($situacaoOa !== null && $situacaoOa !== 'APPROVED') {
        $avisosOa[] = 'Esperando a Meta aprovar o modelo ' . $modeloOa . ' ou o geral, ' . MCP_RW_MODELO_CUPOM_GERAL . ' (situação: ' . mb_strtolower($situacaoOa) . '). Ninguém perde a vez: sai na primeira rodada depois da aprovação.';
    }
    if (!$cfg['ok']) {
        $avisosOa[] = 'Sem a Kapso configurada: nada sai.';
    }
    $corpo .= '<section class="cartao" id="oferta-alunos"><div class="cab"><div><span class="rotulo">Oferta a quem já foi aluno · uma mensagem só</span>'
        . '<b class="hora">' . rw_e($cursoOa) . ($oa['oferta'] !== null ? ' com o cupom ' . rw_e($oa['oferta']['codigo']) : '') . '</b></div><div class="total"><b>' . $oa['faltam'] . '</b><span>'
        . ($oa['faltam'] === 1 ? 'ainda vai receber' : 'ainda vão receber') . '</span></div></div>';
    if ($avisosOa) {
        $corpo .= '<div class="aviso atencao" role="status"><ul>' . implode('', array_map(static fn(string $x): string => '<li>' . rw_e($x) . '</li>', $avisosOa)) . '</ul></div>';
    }
    $corpo .= '<ol class="funil" style="margin-top:14px">'
        . '<li><span>Na lista</span><b>' . $oa['na_lista'] . '</b><small>celulares válidos' . ($oa['invalidos'] > 0 ? ' · ' . $oa['invalidos'] . ' com telefone inválido' : '') . ($oa['bloqueados'] > 0 ? ' · ' . $oa['bloqueados'] . ' pediram para não receber' : '') . '</small></li>'
        . '<li><span>Enviadas</span><b>' . $oa['enviadas'] . '</b><small>' . ($oa['falharam'] > 0 ? $oa['falharam'] . ' não saíram (número sem WhatsApp ou recusa da Meta)' : 'nenhuma falha') . '</small></li>'
        . '<li><span>Entregues</span><b>' . $oa['entregues'] . '</b><small>' . $oa['lidas'] . ' lidas</small></li>'
        . '<li><span>Responderam</span><b>' . $oa['responderam'] . '</b><small>' . $oa['nao_querem'] . ' "Não tenho interesse"</small></li>'
        . '<li class="fim"><span>Compraram pelo link</span><b>' . $oa['compras'] . '</b>' . ($oa['enviadas'] > 0 ? '<em>' . rw_e($pct($oa['compras'] / $oa['enviadas'])) . '</em>' : '')
        . '<small>compras pagas do curso vindas do botão</small></li></ol>';
    $rodadaOa = $oa['rodada'];
    $corpo .= '<p class="nota">' . ($rodadaOa !== null && isset($rodadaOa['em'])
        ? 'Última rodada com movimento, ' . rw_e(rw_quando((int) $rodadaOa['em'])) . ': ' . (int) ($rodadaOa['enviados'] ?? 0) . ' enviadas, ' . (int) ($rodadaOa['falhas'] ?? 0) . ' falhas, '
            . (int) ($rodadaOa['pulados'] ?? 0) . ' puladas' . (!empty($rodadaOa['motivo']) ? ' (' . rw_e((string) $rodadaOa['motivo']) . ')' : '') . '. '
        : 'Ainda não rodou. ')
        . 'Sai de hora em hora, das 8h às 20h, depois dos lembretes da matrícula e da recuperação, no máximo ' . MCP_OA_POR_RODADA . ' por rodada e dentro do mesmo teto de '
        . MCP_RW_POR_DIA . ' mensagens em 24 h. Quem pediu para não receber e quem já comprou o curso ficam de fora.</p></section>';
}

// ----------------------------------------------------------------------------- próximo disparo
// A mesma conta da rotina (mcp_rw_proxima com o ajuste de horário) e o mesmo teto por rodada; o grupo de controle nunca
// recebe. A rotina ainda reconfere cada pessoa antes de mandar. Os e-mails vêm à parte, com o teto deles (por rodada e o
// que ainda cabe nas 24 h).
$rodada = mcp_rw_proxima_rodada($agora);
$aReceber = array_values(array_filter($m['lista'], static fn(array $p): bool => !$p['controle'] && $p['proxima'] !== null));
$naRodada = array_values(array_filter($aReceber, static fn(array $p): bool => $p['proxima'] <= $rodada));
$depois = array_slice($naRodada, MCP_RW_POR_RODADA);
$naRodada = array_slice($naRodada, 0, MCP_RW_POR_RODADA);
$aReceberEmail = array_values(array_filter($m['lista'], static fn(array $p): bool => !$p['controle'] && $p['canal_email']['proxima'] !== null));
usort($aReceberEmail, static fn(array $a, array $b): int => [$a['canal_email']['proxima'], $a['nome']] <=> [$b['canal_email']['proxima'], $b['nome']]);
$emailsNaRodada = array_values(array_filter($aReceberEmail, static fn(array $p): bool => $p['canal_email']['proxima'] <= $rodada));
$cabemEmail = $emailCfg['ok'] ? max(0, min(MCP_RW_EMAIL_POR_RODADA, MCP_RW_EMAIL_POR_DIA - $emailsRecuperacao)) : 0;
$emailsDepois = array_slice($emailsNaRodada, $cabemEmail);
$emailsNaRodada = array_slice($emailsNaRodada, 0, $cabemEmail);
$diaRodada = gmdate('Y-m-d', $rodada - 3 * 3600) === gmdate('Y-m-d', $agora - 3 * 3600) ? 'Hoje' : (gmdate('Y-m-d', $rodada - 3 * 3600) === gmdate('Y-m-d', $agora + 21 * 3600) ? 'Amanhã' : mcp_data_brt(gmdate('Y-m-d H:i:s', $rodada), 'd/m'));
$corpo .= '<section class="cartao destaque"><div class="cab"><div><span class="rotulo">Próximo disparo</span><b class="hora">' . rw_e($diaRodada . ' às ' . mcp_data_brt(gmdate('Y-m-d H:i:s', $rodada), 'H\hi')) . '</b></div>'
    . '<div class="total"><b>' . count($naRodada) . '</b><span>' . (count($naRodada) === 1 ? 'mensagem' : 'mensagens') . '</span>'
    . '<span class="subtotal">e ' . $plural(count($emailsNaRodada), 'e-mail', 'e-mails') . '</span></div></div>';
if ($problemas) {
    $corpo .= '<p class="nota forte">Nenhuma mensagem de WhatsApp sai enquanto o problema acima não for resolvido.</p>';
}
$corpo .= '<h3>WhatsApp</h3>';
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
        $cupom = in_array(mcp_rw_familia($nomeModelo), array_column(MCP_RW_OFERTAS, 'modelo'), true) ? ' com cupom' : '';
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
// E-mails da mesma rodada: por etapa (e o 3º com cupom à parte), com o assunto e o texto recolhido de um exemplo.
$corpo .= '<h3>E-mail</h3>';
if (!$emailCfg['ok']) {
    $corpo .= '<p class="vazio">Nenhum e-mail sai: falta RESEND_API_KEY no servidor.</p>';
} elseif (!$emailsNaRodada) {
    $seguinte = $aReceberEmail[0] ?? null;
    $corpo .= '<p class="vazio">Nenhum e-mail para esta rodada.' . ($cabemEmail === 0 ? ' O teto de ' . MCP_RW_EMAIL_POR_DIA . ' em 24 h já foi atingido.'
        : ($seguinte ? ' O próximo previsto é o ' . $seguinte['canal_email']['proxima_etapa'] . 'º e-mail para ' . rw_e(mcp_rw_primeiro_nome($seguinte['nome'])) . ', '
        . rw_e(mb_strtolower(rw_quando($seguinte['canal_email']['proxima']))) . '.' : '')) . '</p>';
} else {
    $porEmail = [];
    foreach ($emailsNaRodada as $p) {
        $porEmail[$p['canal_email']['modelo']][] = $p;
    }
    ksort($porEmail);
    foreach ($porEmail as $campanha => $pessoas) {
        $e = $pessoas[0]['canal_email'];
        $nomes = '';
        foreach ($pessoas as $p) {
            $nomes .= '<li><b>' . rw_e($p['nome']) . '</b><span>' . rw_e($p['curso']) . ' · ' . $origemNome($p['origem']) . ' · ' . rw_e($p['email']) . '</span></li>';
        }
        $corpo .= '<div class="grupo"><div class="grupo-cab"><span class="etapa em">' . $e['proxima_etapa'] . 'º</span><div><b>' . rw_e(MCP_RW_EMAIL_NOMES[(string) $campanha] ?? (string) $campanha) . '</b>'
            . '<small>' . rw_e((string) $e['assunto']) . '</small></div><span class="qtd">' . $plural(count($pessoas), 'lead', 'leads') . '</span></div>'
            . '<ul class="leads">' . $nomes . '</ul>'
            . '<details class="previa"><summary>Ver o e-mail como ' . rw_e(mcp_rw_primeiro_nome($pessoas[0]['nome'])) . ' vai receber</summary><div class="carta"><b>'
            . rw_e((string) $e['assunto']) . '</b>' . rw_e((string) $e['previa']) . '</div></details></div>';
    }
    $corpo .= '<p class="nota">Quem já recebeu o lembrete do PIX pula o 1º e-mail; quem tem um PIX que ainda vai ganhar o lembrete espera por ele.</p>';
}
if ($emailsDepois) {
    $corpo .= '<p class="nota">Mais ' . $plural(count($emailsDepois), 'e-mail fica', 'e-mails ficam') . ' para depois (o teto é ' . MCP_RW_EMAIL_POR_RODADA . ' por rodada e '
        . MCP_RW_EMAIL_POR_DIA . ' em 24 h).</p>';
}
$corpo .= '</section>';

// ----------------------------------------------------------------------------- última rodada
// O que a rotina fez na última vez que olhou as pessoas (antes só aparecia na saída do cron, que a secretaria não lê):
// quantas saíram, por que parou, e quantas pessoas devidas ficaram sem receber, por motivo. Por pessoa, o motivo aparece
// na lista abaixo ("Última rodada").
$motivoRodada = static function (string $codigo): string {
    [$base, $erro] = array_pad(explode(':', $codigo, 2), 2, '');
    return (MCP_RW_MOTIVOS_RODADA[$base] ?? $base) . ($erro !== '' && $erro !== '0' ? ' (erro #' . $erro . ')' : '');
};
$linhaResumo = static function (string $canal, ?array $res, string $um, string $varios) use ($plural): string {
    if ($res === null) {
        return '<li><b>' . rw_e($canal) . ':</b> nenhuma rodada registrada ainda (o registro começou em 01/11/2026).</li>';
    }
    $contagem = [];
    foreach ((array) ($res['p'] ?? []) as $codigo) {
        $base = explode(':', (string) $codigo, 2)[0];
        $contagem[$base] = ($contagem[$base] ?? 0) + 1;
    }
    $partes = [];
    foreach ($contagem as $base => $n) {
        $partes[] = $n . ' — ' . (MCP_RW_MOTIVOS_RODADA[$base] ?? $base);
    }
    return '<li><b>' . rw_e($canal) . ', ' . rw_e(mb_strtolower(rw_quando((int) $res['em']))) . ':</b> ' . rw_e($plural((int) ($res['enviados'] ?? 0), $um, $varios))
        . ((int) ($res['falhas'] ?? 0) > 0 ? ', ' . (int) $res['falhas'] . ' sem sair' : '') . ((int) ($res['controle'] ?? 0) > 0 ? ', ' . (int) $res['controle'] . ' no grupo de controle (só registro)' : '')
        . ((string) ($res['motivo'] ?? '') !== '' ? '. ' . rw_e((string) $res['motivo']) : '')
        . ($partes ? '<br><small>Devidos que não receberam: ' . rw_e(implode('; ', $partes)) . '.</small>' : '') . '</li>';
};
$corpo .= '<section class="cartao"><h2>Última rodada</h2><ul class="checks">'
    . $linhaResumo('WhatsApp', $resumoWa, 'mensagem enviada', 'mensagens enviadas')
    . $linhaResumo('E-mail', $resumoEm, 'e-mail enviado', 'e-mails enviados')
    . '</ul><p class="nota">Links das mensagens abertos nas últimas 24 h: ' . ($aberturas['w'] ?? 0) . ' pelo WhatsApp e ' . ($aberturas['e'] ?? 0)
    . ' pelo e-mail (programas de e-mail que conferem links sozinhos também contam).</p></section>';

// ----------------------------------------------------------------------------- funil
$enviadas = array_sum($m['etapas']);
// Cada passo com a sua base: entrega e leitura contam MENSAGENS (sobre as enviadas e as entregues); resposta e pagamento
// contam PESSOAS (sobre quem recebeu ao menos uma). "No fluxo agora" fica fora do funil: é quem ainda tem mensagem por vir.
$taxaDe = static fn(int $a, int $b): ?string => $b > 0 ? $pct($a / $b) : null;
$passos = [
    ['Receberam', $m['receberam'], 'pessoas com ao menos 1 mensagem (WhatsApp ou e-mail)', null],
    ['Entregues', $m['entregues'], 'de ' . $plural($enviadas, 'mensagem enviada', 'mensagens enviadas') . ' no WhatsApp', $taxaDe($m['entregues'], $enviadas)],
    ['Lidas', $m['lidas'], 'das entregues', $taxaDe($m['lidas'], $m['entregues'])],
    ['Responderam', $m['responderam'], $m['nao_querem'] > 0 ? $m['nao_querem'] . ' não querem' : 'pessoas', $taxaDe($m['responderam'], $m['receberam'])],
    ['Pagaram', $m['recuperadas'], 'pessoas, depois da mensagem', $taxaDe($m['recuperadas'], $m['receberam'])],
];
$corpo .= '<section class="cartao"><h2>Funil</h2><ol class="funil">';
foreach ($passos as $i => [$rotulo, $valor, $nota, $taxaPasso]) {
    $corpo .= '<li' . ($i === 4 ? ' class="fim"' : '') . '><span>' . rw_e($rotulo) . '</span><b>' . $valor . '</b>'
        . ($taxaPasso !== null ? '<em>' . rw_e($taxaPasso) . '</em>' : '') . '<small>' . rw_e($nota) . '</small></li>';
}
$noFluxoWa = count(array_filter($m['lista'], static fn(array $p): bool => !$p['controle'] && $p['proxima'] !== null));
$corpo .= '</ol><p class="nota">Agora: ' . $plural($m['no_fluxo'], 'pessoa espera', 'pessoas esperam') . ' a próxima mensagem de algum canal (' . $noFluxoWa . ' no WhatsApp, '
    . $m['email']['no_fluxo'] . ' no e-mail)'
    . ($m['no_controle'] > 0 ? ' e ' . $m['no_controle'] . ' ' . ($m['no_controle'] === 1 ? 'está' : 'estão') . ' no grupo de controle (não recebem)' : '')
    . '. Enviadas: 1ª ' . $m['etapas'][1] . ' · 2ª ' . $m['etapas'][2] . ' · 3ª ' . $m['etapas'][3] . ($m['falhas'] > 0 ? ' · ' . $plural($m['falhas'], 'não saiu', 'não saíram') : '') . '.</p>';
// O e-mail, com os mesmos cortes: enviados por etapa, os 1º dados como cumpridos pelo lembrete do PIX e quem pagou pelo
// botão de um e-mail (utm_source=email no checkout do site). Só contagens.
$em = $m['email'];
$peloEmail = array_sum($em['pelo_link']);
$corpo .= '<h3>Por e-mail</h3><ol class="funil">'
    . '<li><span>1º e-mail</span><b>' . $em['etapas'][1] . '</b><small>' . ($em['pulados_pix'] > 0 ? $plural($em['pulados_pix'], 'pulado', 'pulados') . ': já tinham o lembrete do PIX' : 'enviados') . '</small></li>'
    . '<li><span>2º e-mail</span><b>' . $em['etapas'][2] . '</b><small>enviados</small></li>'
    . '<li><span>3º e-mail</span><b>' . $em['etapas'][3] . '</b><small>enviados</small></li>'
    . '<li><span>Receberam</span><b>' . $em['receberam'] . '</b><small>pessoas com ao menos 1 e-mail' . ($em['descadastros'] > 0 ? ' · ' . $em['descadastros'] . ' pediram para parar' : '') . '</small></li>'
    . '<li class="fim"><span>Pelo link do e-mail</span><b>' . $peloEmail . '</b><small>pagaram pelo botão de um e-mail</small></li></ol>'
    . '<p class="nota">Agora: ' . $plural($em['no_fluxo'], 'pessoa espera', 'pessoas esperam') . ' o próximo e-mail. Cota da Resend nas últimas 24 h: '
    . $emailsRecuperacao . ' de ' . MCP_RW_EMAIL_POR_DIA . ' da recuperação, e cerca de ' . $cotaHoje . ' de ' . MCP_RW_RESEND_COTA_DIA . ' do site inteiro (o que fica registrado: recibos, PIX, avisos e a recuperação)'
    . ($em['falhas'] > 0 ? '. ' . $plural($em['falhas'], 'e-mail não saiu', 'e-mails não saíram') : '') . '.</p></section>';

// ----------------------------------------------------------------------------- está funcionando?
$mediana = $m['mediana_horas'] === null ? '—' : number_format($m['mediana_horas'], 1, ',', '.') . ' h';
$efeito = $m['efeito_pp'] === null ? '—' : ($m['efeito_pp'] > 0 ? '+' : '') . number_format($m['efeito_pp'], 1, ',', '.') . ' p.p.';
$amostra = min($m['tratamento']['pessoas'], $m['controle']['pessoas']);
$corpo .= '<section class="cartao"><h2>Está funcionando?</h2><div class="tres">'
    . '<div class="kpi"><span>Taxa de recuperação</span><b>' . rw_e($pct($m['taxa'])) . '</b><small>' . $m['recuperadas'] . ' de ' . $m['receberam'] . ' pagaram depois da 1ª mensagem (WhatsApp ou e-mail)</small></div>'
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
    // Os dois canais de cada pessoa, lado a lado: quantas já foram em cada um e a próxima de cada um. Fora do canal (sem
    // celular, sem e-mail, parou, ou a sequência acabou) o selo fica apagado, com o motivo.
    $motivoCanal = static fn(?string $motivo, string $sem): string => match ($motivo) {
        'sem_celular', 'sem_email' => $sem, 'bloqueou' => 'pediu para parar', 'falhou' => 'não chegou', 'completo' => 'recebeu as 3', default => 'encerrado',
    };
    // O motivo de a pessoa não ter recebido na última rodada de cada canal, se ela estava devida e ficou sem.
    $naUltima = static function (?array $res, string $chave, string $canal) use ($motivoRodada): string {
        $codigo = $res['p'][$chave] ?? null;
        return is_string($codigo) ? '<small>Última rodada (' . rw_e(rw_quando((int) $res['em'])) . '), ' . $canal . ': ' . rw_e($motivoRodada($codigo)) . '</small>' : '';
    };
    $seloCanal = static function (string $classe, string $rotulo, array $c, string $sem, string $extra = '') use ($motivoCanal): string {
        $texto = $c['no_fluxo'] || $c['enviadas'] > 0 ? $c['enviadas'] . ' de 3' . ($c['no_fluxo'] ? '' : ' · ' . $motivoCanal($c['motivo'], $sem)) : $motivoCanal($c['motivo'], $sem);
        return '<span class="canal ' . $classe . ($c['no_fluxo'] ? '' : ' fora') . '"><i>' . $rotulo . '</i>' . rw_e($texto . $extra) . '</span>';
    };
    foreach ($nessa as $p) {
        $respostas = '';
        foreach ($p['respostas'] as $r) {
            $respostas .= '<q>' . rw_e($r) . '</q>';
        }
        $em = $p['canal_email'];
        $canais = '<span class="canais" aria-label="' . $p['whatsapp']['enviadas'] . ' de 3 mensagens e ' . $em['enviadas'] . ' de 3 e-mails enviados">'
            . $seloCanal('wa', 'WhatsApp', $p['whatsapp'], 'sem celular') . $seloCanal('em', 'E-mail', $em, 'sem e-mail', $em['pulado_pix'] ? ' · 1º pelo PIX' : '') . '</span>';
        $ultima = $naUltima($resumoWa, $p['chave'], 'WhatsApp') . $naUltima($resumoEm, $p['chave'], 'e-mail');
        if ($p['controle']) {
            $proxima = rw_selo('talvez', 'Grupo de controle') . '<small>não recebe</small>';
        } else {
            $proxima = ($p['proxima'] !== null ? '<div>WhatsApp: ' . rw_e($p['proxima_etapa'] . 'ª · ' . rw_quando($p['proxima'])) . (in_array(mcp_rw_familia((string) $p['modelo']), array_column(MCP_RW_OFERTAS, 'modelo'), true) ? ' <small>com cupom</small>' : '') . '</div>' : '')
                . ($em['proxima'] !== null ? '<div>E-mail: ' . rw_e($em['proxima_etapa'] . 'º · ' . rw_quando($em['proxima'])) . ($em['modelo'] === 'rec3cupom' ? ' <small>com cupom</small>' : '') . '</div>' : '');
        }
        $contatos = implode(' · ', array_filter([rw_e($p['celular']), rw_e($p['email']), $origemNome($p['origem'])]));
        $linhasPessoa .= '<tr><td><b>' . rw_e($p['nome']) . '</b><small>' . $contatos . '</small></td>'
            . '<td>' . rw_e($p['curso']) . '</td><td>' . $canais . '</td>'
            . '<td>' . $proxima . ($p['controle'] ? '' : $ultima) . ($p['whatsapp']['erro'] !== null && !$p['whatsapp']['no_fluxo'] ? '<small>WhatsApp: ' . rw_e((string) $p['whatsapp']['erro']) . '</small>' : '')
            . '</td><td>' . ($respostas !== '' ? $respostas : '<span class="mudo">—</span>') . '</td></tr>';
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
    $peloLink[] = [(string) $campanha, $n, $m['email']['pelo_link'][$campanha] ?? 0];
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
foreach ($modelosLinhas as [$nome, $uso, $sit, $categoria, $outros]) {
    $modelosT[] = [$nome . ($uso !== '' ? ' (' . $uso . ')' : ''), ($sit === 'APPROVED' ? 'Aprovado' : ($sit === 'ausente' ? 'Não existe' : 'Esperando (' . $sit . ')'))
        . ($categoria !== '' ? ' · ' . mb_strtolower($categoria) : ''), $outros !== '' ? $outros : '—'];
}
$corpo .= '<section class="cartao"><h2>Detalhes</h2>'
    . '<details><summary>Mensagens e pagamentos por dia (últimos ' . MCP_RW_DIAS_METRICA . ' dias)</summary><ul class="barras">' . $barras . '</ul>'
    . '<p class="legenda"><span class="b m"></span> mensagens <span class="b r"></span> pagamentos</p></details>'
    . '<details><summary>Qual mensagem trouxe o pagamento</summary><div class="duas">'
    . '<div><h3>Última mensagem antes de pagar</h3>' . $tabela(['Mensagem', 'Pessoas'], $porMensagem) . '</div>'
    . '<div><h3>Pelo link do botão</h3>' . $tabela(['Campanha', 'WhatsApp', 'E-mail'], $peloLink) . '<p class="nota">Só o checkout do site guarda a campanha; a escola não.</p></div></div>'
    . '<h3>Por modelo</h3>' . $tabela(['Modelo', 'Enviadas', 'Recuperadas'], $porModeloT) . '</details>'
    . '<details><summary>Por origem e por curso</summary><div class="duas"><div>' . $tabela(['Origem', 'Pessoas', 'Mensagens', 'Pagaram'], $porOrigemT) . '</div>'
    . '<div>' . $tabela(['Curso', 'Pessoas', 'Mensagens', 'Pagaram'], $porCursoT) . '</div></div></details>'
    . '<details><summary>Configuração, modelos na Meta e e-mail</summary><ul class="checks">';
foreach ($okItens as $x) {
    $corpo .= '<li>' . rw_selo('ok', 'Ok') . rw_e($x) . '</li>';
}
$corpo .= '</ul>' . $tabela(['Modelo que sai (por família)', 'Situação na Meta', 'Outras versões na Meta'], $modelosT)
    . '<p class="nota">Cada mensagem usa a versão da família de maior número aprovada como utilidade (UTILITY); sem nenhuma, a aprovada de qualquer categoria. Uma versão nova aprovada (ex.: rec_inscricao_2_v2) entra sozinha na rodada seguinte.</p></details>'
    . '<details><summary>Por que alguém no fluxo ainda não recebeu</summary><ul class="checks">'
    . '<li>Grupo de controle (' . MCP_RW_CONTROLE_PCT . '% das pessoas, sorteadas): não recebe nada, de propósito.</li>'
    . '<li>Ainda não deu 1 h do cadastro, ou da tentativa mais recente (quem tenta pagar de novo recomeça a contar); no WhatsApp de quem gerou PIX, 30 min.'
    . ' Antes da 1ª de quem tem PIX pendente, a Unicopag é consultada (quem já pagou não recebe). A 2ª e a 3ª esperam 24 h e 72 h do cadastro e pelo menos 21 h e 48 h depois da anterior.</li>'
    . '<li>Horário: só das 8h às 20h; a última rodada do dia é a das 19h07 e a primeira, a das 8h07.</li>'
    . '<li>Sem celular válido (fixo, incompleto, sem o 9): recebe só os e-mails. Sem e-mail válido: só o WhatsApp.</li>'
    . '<li>Modelo da mensagem esperando a Meta aprovar, ou diferente do que a mensagem manda: a pessoa espera, sem perder a vez.</li>'
    . '<li>Teto: ' . MCP_RW_POR_RODADA . ' mensagens por rodada e ' . MCP_RW_POR_DIA . ' em 24 h (e-mail: ' . MCP_RW_EMAIL_POR_RODADA . ' e ' . MCP_RW_EMAIL_POR_DIA . ').</li>'
    . '<li>Na conferência logo antes do envio: pagou (em qualquer sistema) ou pediu para parar.</li>'
    . '<li>A Kapso ou a Meta recusaram: um erro de todos (chave, modelo, limite) para a rodada, e o resto vai na seguinte; um erro só daquela mensagem deixa a pessoa para a rodada seguinte e a fila anda.</li>'
    . '<li>Número sem WhatsApp: a 1ª fica "não chegou" e a sequência dessa pessoa para (os e-mails continuam).</li>'
    . '<li>E-mail: quem tem um PIX que ainda vai ganhar o lembrete espera por ele; e nada sai se o site já mandou muitos e-mails em 24 h.</li>'
    . '</ul><p>Na lista de pessoas, "Última rodada" diz o motivo de cada uma que estava devida e ficou sem receber; o cartão "Última rodada" junta os números.</p></details>'
    . '<details><summary>Como funciona</summary><p>Quem se cadastrou no checkout do site ou na plataforma da escola nos últimos ' . MCP_RW_JANELA_DIAS . ' dias e não pagou recebe até 3 mensagens: '
    . '1 h (30 min no WhatsApp de quem gerou um PIX), 24 h e 72 h depois do cadastro (com pelo menos 21 h e 48 h entre uma e a seguinte), só das 8h às 20h, no máximo ' . MCP_RW_POR_RODADA . ' por rodada e ' . MCP_RW_POR_DIA . ' em 24 h. '
    . 'Nos cursos com cupom (' . implode(', ', array_map(static fn(string $s, array $d): string => rw_e((string) (mcp_curso($s)['nome'] ?? $s)) . ' com o ' . $d['cupom'], array_keys(MCP_RW_OFERTAS), MCP_RW_OFERTAS)) . '), enquanto o cupom vale, tem vaga e há turma aberta, a 3ª leva o cupom (no WhatsApp, só com o modelo do curso aprovado na Meta). Quem paga em qualquer um dos dois sistemas, responde "Não tenho interesse" ou não tem celular válido sai na hora; '
    . 'antes de cada envio a rotina confere tudo de novo. O botão leva direto ao pagamento: com o PIX ainda aberto, à tela desse PIX (o QR); sem ele, ao checkout do curso já preenchido.</p><p>' . MCP_RW_CONTROLE_PCT . '% das pessoas, sorteadas, ficam no grupo de controle e não recebem nada (nem WhatsApp nem e-mail): é a comparação com elas que mostra quanto as mensagens recuperam de verdade.</p>'
    . '<p>O mesmo público recebe também 3 e-mails, na mesma régua e no mesmo horário, independentes do WhatsApp: quem não tem celular válido recebe só os e-mails, quem não tem e-mail só o WhatsApp. '
    . 'Quem já recebeu o lembrete do PIX não recebe o 1º e-mail (e quem ainda vai receber esse lembrete espera por ele). No máximo ' . MCP_RW_EMAIL_POR_RODADA . ' e-mails por rodada e '
    . MCP_RW_EMAIL_POR_DIA . ' em 24 h, porque a conta da Resend (' . MCP_RW_RESEND_COTA_DIA . ' por dia) é a mesma dos recibos, do PIX e dos avisos; e nada sai se o resto do site já mandou '
    . MCP_RW_EMAIL_RESERVA_SITE . ' em 24 h. Todo e-mail tem o "Não quero mais receber" (e o descadastro de um clique do programa de e-mail): quem usa sai dos dois canais, como quem responde "Não tenho interesse" no WhatsApp.</p></details>'
    . '</section>';
rw_pagina($corpo, $sessao);
