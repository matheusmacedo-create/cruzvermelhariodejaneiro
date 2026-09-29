<?php
/**
 * Portal da secretaria. Menu no cabeçalho com cinco itens:
 *   - Início: o que pede ação e o que chegou por último;
 *   - Inscrições (?v=inscricoes): filtros, busca, planilha, e a ficha de cada uma (?v=inscricao&id=)
 *     com o histórico e o lembrete de horários à mão (lib/secretaria.php);
 *   - Horários dos alunos (?v=horarios): mapa por curso, lista e planilha (lib/horarios.php);
 *   - Mensagens do chat (?v=mensagens): lista, detalhe e resposta por e-mail no padrão da instituição;
 *   - Plataforma da escola: link externo.
 *
 * Acesso (lib/painel.php): pelo link assinado que vai no aviso à equipe (abre só aquele contato) ou por
 * um link de entrada enviado ao e-mail da equipe (sessão de 12 h com a lista completa). A resposta sai
 * por mcp_email_resposta_contato() e fica registrada em mcp_contatos (status, resposta, respondido_por,
 * respondido_em). Tudo é HTML gerado no servidor, sem JavaScript obrigatório.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

const MCP_PAINEL_LIMITE_LINKS = [5, 3600];
const MCP_PAINEL_RESPOSTA_MIN = 5;
const MCP_PAINEL_RESPOSTA_MAX = 6000;
const MCP_PAINEL_POR_PAGINA = 50;
const MCP_PAINEL_HORARIOS_LIMITE = 2000;
/** Ícones do menu (traço de 24 px, herdam a cor do texto). */
const PN_ICONES = [
    'inicio' => '<path d="M3 11.5 12 4l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
    'inscricoes' => '<path d="M7 3h10a2 2 0 0 1 2 2v16l-3-2-2 2-2-2-2 2-2-2-3 2V5a2 2 0 0 1 2-2z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
    'horarios' => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M8 3v4M16 3v4"/>',
    'mensagens' => '<path d="M5 5h14a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H9l-5 4V6a1 1 0 0 1 1-1z"/>',
    'escola' => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
];

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

// ----------------------------------------------------------------------------- html
function pn_e(?string $s): string
{
    return mcp_escapar((string) $s);
}

function pn_data(?string $utc): string
{
    return mcp_data_brt($utc, 'd/m/Y \à\s H\hi');
}

function pn_status(string $status): string
{
    $rotulos = ['novo' => 'Novo', 'respondido' => 'Respondido', 'arquivado' => 'Arquivado'];
    return '<span class="status ' . pn_e($status) . '">' . pn_e($rotulos[$status] ?? $status) . '</span>';
}

function pn_redirecionar(string $query): never
{
    header('Location: painel.php' . ($query !== '' ? '?' . $query : ''), true, 303);
    exit;
}

function pn_icone(string $nome): string
{
    return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . PN_ICONES[$nome] . '</svg>';
}

/** Menu do portal, no cabeçalho: cada seção com o número do que pede ação. */
function pn_menu(string $aba): string
{
    $contas = mcp_secretaria_contar();
    $itens = [
        'inicio' => ['painel.php', 'Início', 0, ''],
        'inscricoes' => ['painel.php?v=inscricoes', 'Inscrições', $contas['atencao'], 'precisam de atenção'],
        'horarios' => ['painel.php?v=horarios', 'Horários dos alunos', $contas['sem_horarios'], 'ainda sem horários'],
        'mensagens' => ['painel.php?v=mensagens', 'Mensagens do chat', mcp_contatos_contar()['novo'], 'sem resposta'],
    ];
    $html = '';
    foreach ($itens as $chave => [$href, $rotulo, $n, $explica]) {
        $html .= '<a href="' . pn_e($href) . '"' . ($aba === $chave ? ' aria-current="page"' : '') . '>' . pn_icone($chave) . '<span>' . pn_e($rotulo) . '</span>'
            . ($n > 0 ? '<span class="badge' . ($chave === 'horarios' ? ' suave' : '') . '" title="' . $n . ' ' . pn_e($explica) . '">' . $n . '<span class="sr"> ' . pn_e($explica) . '</span></span>' : '') . '</a>';
    }
    $escola = rtrim((string) mcp_cfg('ESCOLA_URL', 'https://escola.cursoscruzvermelha.org'), '/') . '/login';
    $html .= '<a class="externo" href="' . pn_e($escola) . '" target="_blank" rel="noopener">' . pn_icone('escola') . '<span>Plataforma da escola</span><span class="sr"> (abre em outra aba)</span></a>';
    return '<nav class="menu" aria-label="Portal da secretaria"><div class="wrap">' . $html . '</div></nav>';
}

function pn_pagina(string $titulo, string $corpo, ?string $usuario, bool $comLista, string $aba = 'inicio'): never
{
    $logo = pn_e(mcp_email_logo());
    $topoDireita = $usuario !== null
        ? '<div class="usuario">' . pn_e($usuario) . ' · <a href="painel.php?sair=1">Sair</a></div>'
        : ($comLista ? '' : '<div class="usuario">Acesso por link · só este contato</div>');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . pn_e($titulo) . ' · Portal da secretaria</title>'
        . '<link rel="icon" type="image/png" href="/assets/favicon.png">'
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">'
        . '<style>' . pn_css() . '</style></head><body><div class="faixa"></div>'
        . '<header class="topo"><div class="wrap linha"><a href="' . ($usuario !== null ? 'painel.php' : pn_e(mcp_site_url()) . '/') . '"><img src="' . $logo . '" width="120" height="36" alt="Cruz Vermelha Brasileira · Rio de Janeiro"></a>'
        . '<div><b>Portal da secretaria</b><small>Matrícula cursos presenciais</small></div>' . $topoDireita . '</div>'
        . ($usuario !== null ? pn_menu($aba) : '') . '</header>'
        . '<main class="wrap">' . $corpo . '</main>'
        . ($usuario !== null ? '<script>(function(){var a=document.querySelector(".menu a[aria-current]"),m=a&&a.parentNode;if(m&&a.offsetLeft+a.offsetWidth>m.clientWidth)m.scrollLeft=a.offsetLeft-24})();</script>' : '')
        . '</body></html>';
    exit;
}

function pn_css(): string
{
    return <<<'CSS'
:root{--red:#cc0000;--red-dark:#a30000;--black:#0f1318;--text:#1a202c;--muted:#718096;--line:#e2e8f0;--soft:#f7f8fa}
*{box-sizing:border-box}body{margin:0;font-family:Inter,Arial,sans-serif;color:var(--text);background:var(--soft);line-height:1.5}
a{color:var(--red)}img{display:block}
.faixa{height:5px;background:var(--red)}
.topo{background:#fff;border-bottom:1px solid var(--line);box-shadow:0 6px 18px rgba(16,24,40,.06)}
.topo .linha{display:flex;align-items:center;gap:16px;padding:12px 20px}
.topo img{height:36px;width:auto}
.topo b{font-size:1rem;color:var(--black);display:block;line-height:1.2}
.topo small{display:block;color:var(--muted);font-size:.8rem}
.topo .usuario{margin-left:auto;font-size:.85rem;color:var(--muted);text-align:right}
.wrap{max-width:1040px;margin:0 auto;padding:0 20px}
main.wrap{padding:26px 20px 70px}
h1{font-size:1.55rem;letter-spacing:-.02em;color:var(--black);margin:0 0 6px;line-height:1.15}
h2{font-size:1.02rem;color:var(--black);margin:0 0 12px}
.eyebrow{color:var(--red);font-size:.74rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;margin:0 0 6px}
.cartao{background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px;box-shadow:0 6px 22px rgba(16,24,40,.05)}
.cartao+.cartao{margin-top:18px}
.grade{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.35fr);gap:18px;align-items:start;margin-top:18px}
.grade .cartao+.cartao{margin-top:0}
@media(max-width:820px){.grade{grid-template-columns:1fr}.grade .cartao+.cartao{margin-top:18px}}
.cabeca{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.filtros{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0 18px}
.pilula{display:inline-flex;align-items:center;gap:8px;border:1.5px solid var(--line);border-radius:999px;padding:7px 14px;font-weight:700;font-size:.88rem;color:var(--muted);text-decoration:none;background:#fff}
.pilula.ativo,.pilula:hover{border-color:var(--red);color:var(--red)}
.pilula .n{background:var(--soft);border-radius:999px;padding:1px 8px;font-size:.78rem}
.tabela{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}
table{width:100%;border-collapse:collapse;font-size:.92rem}
th{text-align:left;font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);padding:12px 16px;border-bottom:1px solid var(--line);background:var(--soft);white-space:nowrap}
td{padding:13px 16px;border-bottom:1px solid var(--line);vertical-align:top}
tr:last-child td{border-bottom:0}
tbody tr:hover td{background:#fffafa}
td small{display:block;color:var(--muted);font-size:.82rem}
td a.nome{font-weight:700;color:var(--black);text-decoration:none}
td a.nome:hover{color:var(--red)}
.status{display:inline-block;border-radius:999px;padding:3px 10px;font-size:.72rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;white-space:nowrap}
.status.novo{background:#fff0f2;color:#b91c1c}.status.respondido{background:#e9f7ef;color:#0f7b3e}.status.arquivado{background:var(--soft);color:var(--muted)}
dl{display:grid;grid-template-columns:auto minmax(0,1fr);gap:9px 14px;margin:0;font-size:.92rem}dt{color:var(--muted)}dd{margin:0;color:var(--black);font-weight:600;overflow-wrap:anywhere}
dd a{color:var(--red);text-decoration:none}dd a:hover{text-decoration:underline}
.mensagem{white-space:pre-wrap;overflow-wrap:anywhere;background:var(--soft);border-left:4px solid var(--red);border-radius:0 12px 12px 0;padding:14px 16px;margin:0;font-size:.98rem}
.resposta-anterior{background:#e9f7ef;border:1px solid #b7e4c7;border-radius:12px;padding:14px 16px;white-space:pre-wrap;overflow-wrap:anywhere;margin:12px 0 0;font-size:.95rem}
label{display:block;font-weight:700;font-size:.86rem;color:var(--black);margin:14px 0 6px}
input,textarea{width:100%;font:inherit;font-size:.98rem;padding:11px 14px;border:1px solid #cbd5e1;border-radius:12px;background:#fff;color:var(--text)}
textarea{min-height:240px;resize:vertical;line-height:1.55}
input:focus,textarea:focus{outline:0;border-color:var(--red);box-shadow:0 0 0 4px rgba(204,0,0,.12)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:999px;padding:12px 22px;font:inherit;font-weight:800;border:1.5px solid transparent;cursor:pointer;text-decoration:none;min-height:46px}
.btn-red{background:var(--red);color:#fff}.btn-red:hover{background:var(--red-dark)}
.btn-outline{background:#fff;border-color:var(--line);color:var(--black)}.btn-outline:hover{border-color:var(--red);color:var(--red)}
.acoes{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:16px}
.acoes form{margin:0}
.aviso{border-radius:12px;padding:12px 16px;margin:0 0 16px;font-size:.92rem}
.aviso.ok{background:#e9f7ef;color:#0f5132;border:1px solid #b7e4c7}.aviso.erro{background:#fff0f2;color:#8a1c1c;border:1px solid #f5c2c7}
.nota{color:var(--muted);font-size:.85rem;margin:8px 0 0}
.vazio{padding:44px 20px;text-align:center;color:var(--muted)}
.login{max-width:480px;margin:48px auto 0}
.voltar{display:inline-block;margin:0 0 14px;font-size:.9rem;color:var(--muted);text-decoration:none}
.voltar:hover{color:var(--red)}
.paginacao{display:flex;gap:10px;justify-content:flex-end;margin-top:14px;font-size:.9rem}
.menu{border-top:1px solid var(--line)}
.menu .wrap{position:relative;display:flex;align-items:stretch;gap:2px;padding:0 12px;overflow-x:auto;scrollbar-width:none;
background:linear-gradient(90deg,#fff 30%,rgba(255,255,255,0)) 0 0/28px 100% no-repeat local,linear-gradient(270deg,#fff 30%,rgba(255,255,255,0)) 100% 0/28px 100% no-repeat local,
radial-gradient(farthest-side at 0 50%,rgba(16,24,40,.18),rgba(16,24,40,0)) 0 0/12px 100% no-repeat scroll,radial-gradient(farthest-side at 100% 50%,rgba(16,24,40,.18),rgba(16,24,40,0)) 100% 0/12px 100% no-repeat scroll #fff}
.menu .wrap::-webkit-scrollbar{display:none}
.menu a{position:relative;display:inline-flex;align-items:center;gap:8px;padding:12px 12px 11px;font-weight:700;font-size:.9rem;color:var(--muted);text-decoration:none;border-bottom:3px solid transparent;white-space:nowrap}
.menu a:hover{color:var(--black)}.menu a[aria-current]{color:var(--red);border-bottom-color:var(--red)}
.menu .ico{width:18px;height:18px;flex-shrink:0}.menu .externo{margin-left:auto}
.badge{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:var(--red);color:#fff;font-size:.72rem;font-weight:800;line-height:1}
.badge.suave{background:var(--soft);color:var(--text);box-shadow:inset 0 0 0 1px var(--line)}
.sr{position:absolute!important;width:1px;height:1px;margin:-1px;padding:0;border:0;overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap}
.numeros{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:18px 0}
@media(max-width:900px){.numeros{grid-template-columns:repeat(2,minmax(0,1fr))}}
.numero{display:block;background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px;text-decoration:none;color:var(--text);box-shadow:0 6px 22px rgba(16,24,40,.05)}
.numero:hover{border-color:var(--red)}
.numero b{display:block;font-size:2rem;line-height:1.1;color:var(--black);letter-spacing:-.02em}
.numero span{display:block;font-weight:700;color:var(--black);margin-top:4px}
.numero small{display:block;color:var(--muted);font-size:.82rem;margin-top:2px}
.numero.alerta{border-color:#f5c2c7;background:#fff7f7}.numero.alerta b{color:var(--red)}
.grade-inicio{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;align-items:start}
.grade-inicio .cartao+.cartao{margin-top:0}
@media(max-width:820px){.grade-inicio{grid-template-columns:minmax(0,1fr)}}
.lista-curta{list-style:none;margin:0;padding:0}
.lista-curta li{display:flex;flex-wrap:wrap;justify-content:space-between;gap:4px 12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--line);font-size:.92rem}
.lista-curta li:last-child{border-bottom:0}.lista-curta li>div{flex:1 1 180px;min-width:0}
.lista-curta a{font-weight:700;color:var(--black);text-decoration:none}.lista-curta a:hover{color:var(--red)}
.lista-curta small{display:block;color:var(--muted);font-size:.82rem}
.selo{display:inline-block;border-radius:999px;padding:3px 10px;font-size:.74rem;font-weight:800;white-space:nowrap}
.selo.ok{background:#e9f7ef;color:#0f7b3e}.selo.alerta{background:#fff4e5;color:#8a5200}.selo.erro{background:#fff0f2;color:#b91c1c}.selo.neutro{background:var(--soft);color:#4a5568}
.busca{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}
.busca input,.busca select{flex:1 1 220px;width:auto;font:inherit;font-size:.95rem;padding:10px 14px;border:1px solid #cbd5e1;border-radius:12px;background:#fff;color:var(--text)}
.busca select{flex:0 1 240px}.busca .btn{min-height:44px;padding:10px 18px}
.ficha{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:18px;align-items:start}
.ficha .cartao+.cartao{margin-top:0}
@media(max-width:820px){.ficha{grid-template-columns:minmax(0,1fr)}}
.orientacao{background:var(--soft);border-radius:12px;padding:12px 14px;margin:12px 0 0;font-size:.92rem}
.tempo{list-style:none;margin:0;padding:0;font-size:.92rem}
.tempo li{display:grid;grid-template-columns:150px minmax(0,1fr);gap:12px;padding:8px 0;border-bottom:1px solid var(--line)}
.tempo li:last-child{border-bottom:0}.tempo time{color:var(--muted);white-space:nowrap}
@media(max-width:640px){.tempo li{grid-template-columns:minmax(0,1fr);gap:2px}}
.cabeca>div{flex:1 1 420px;min-width:0}
.resumo-grade{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(0,1fr);gap:18px;align-items:start;margin-top:4px}
.resumo-grade .cartao+.cartao{margin-top:0}
@media(max-width:860px){.resumo-grade{grid-template-columns:minmax(0,1fr)}}
.rolagem{overflow-x:auto}
table.mapa{width:100%;min-width:320px;border-collapse:separate;border-spacing:4px;font-size:.9rem}
.mapa th{background:none;border:0;text-align:center;padding:4px;color:var(--muted)}
.mapa tbody th{text-align:left;color:var(--black);font-size:.85rem;letter-spacing:0;text-transform:none;white-space:nowrap}
.mapa th small{display:block;font-weight:500;color:var(--muted);font-size:.74rem}
.mapa td.celula{text-align:center;font-weight:800;font-size:1.05rem;border:0;border-radius:10px;padding:12px 4px;color:var(--black)}
.contas{list-style:none;margin:0;padding:0;font-size:.92rem}.contas li{display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:1px solid var(--line)}
.contas li:last-child{border-bottom:0}.contas b{color:var(--black)}
.destaques{margin:12px 0 0;font-size:.9rem;color:var(--text)}
table.respostas{min-width:860px}
td.comeco,td small.tel{white-space:nowrap}td.horarios{min-width:170px}
table.respostas .selo{white-space:normal;border-radius:10px}table.respostas .abrir{display:none}
@media(max-width:640px){table.respostas{min-width:0}table.respostas thead{display:none}table.respostas tbody{display:block}
table.respostas tr{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px 14px;padding:16px;border-bottom:1px solid var(--line)}
table.respostas tr:last-child{border-bottom:0}table.respostas td{display:block;padding:0;border:0;min-width:0;background:none!important}
table.respostas td[data-rotulo]::before{content:attr(data-rotulo);display:block;margin-bottom:2px;font-size:.68rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
table.respostas td.aluno{grid-column:1/-1;order:-1}table.respostas td.horarios,table.respostas td.recado,table.respostas td.abrir,table.respostas td.vazio{grid-column:1/-1;min-width:0}
table.respostas td.vazio{padding:28px 0}table.respostas td.comeco{white-space:normal}table.respostas td.abrir{display:block}}
.faltam{margin:-6px 0 16px;font-size:.9rem;color:var(--text)}.faltam b{color:var(--red)}
@media(max-width:640px){.topo .linha{flex-wrap:wrap;row-gap:4px}.topo .linha small{display:none}
.topo .usuario{flex-basis:100%;margin-left:0;text-align:left}.cartao{padding:18px 16px}
.mapa td.celula{padding:10px 2px;font-size:.95rem}.mapa th small{font-size:.66rem}}
CSS;
}

// ----------------------------------------------------------------------------- páginas
function pn_login(string $aviso = '', string $classe = 'erro'): never
{
    $corpo = '<div class="login"><div class="cartao">'
        . '<p class="eyebrow">Portal da secretaria</p><h1>Entrar</h1>'
        . '<p>Digite o e-mail da equipe. Enviamos um link de acesso que vale ' . MCP_PAINEL_LINK_ENTRADA_MINUTOS . ' minutos.</p>'
        . ($aviso !== '' ? '<div class="aviso ' . pn_e($classe) . '">' . pn_e($aviso) . '</div>' : '')
        . '<form method="post" action="painel.php"><input type="hidden" name="acao" value="entrar">'
        . '<label for="email">E-mail da equipe</label><input id="email" type="email" name="email" required autocomplete="email" placeholder="contato@cruzvermelhariodejaneiro.org">'
        . '<div class="acoes"><button class="btn btn-red" type="submit">Receber link de acesso</button></div></form>'
        . '<p class="nota">O aviso de cada mensagem nova já traz o botão "Responder no painel", que abre o contato direto, sem esta tela.</p>'
        . '</div></div>';
    pn_pagina('Entrar', $corpo, null, true);
}

function pn_lista(string $usuario): never
{
    $totais = mcp_contatos_contar();
    $filtros = ['novo' => 'Novos', 'respondido' => 'Respondidos', 'arquivado' => 'Arquivados', 'todos' => 'Todos'];
    $f = mcp_texto($_GET['f'] ?? '', 12);
    if (!isset($filtros[$f])) {
        $f = $totais['novo'] > 0 ? 'novo' : 'todos';
    }
    $pagina = min(max(1, (int) ($_GET['p'] ?? 1)), 10000);
    $linhas = mcp_contatos_listar($f === 'todos' ? null : $f, MCP_PAINEL_POR_PAGINA, ($pagina - 1) * MCP_PAINEL_POR_PAGINA);

    $pilulas = '';
    foreach ($filtros as $chave => $rotulo) {
        $n = $chave === 'todos' ? array_sum($totais) : $totais[$chave];
        $pilulas .= '<a class="pilula' . ($chave === $f ? ' ativo' : '') . '" href="painel.php?v=mensagens&amp;f=' . $chave . '">' . pn_e($rotulo) . ' <span class="n">' . $n . '</span></a>';
    }
    $assuntos = mcp_contato_assuntos();
    $tabela = '';
    foreach ($linhas as $l) {
        $tabela .= '<tr><td>' . pn_e(pn_data($l['criado_em'])) . '<small>' . pn_e((string) $l['protocolo']) . '</small></td>'
            . '<td><a class="nome" href="painel.php?id=' . (int) $l['id'] . '">' . pn_e((string) $l['nome']) . '</a><small>' . pn_e((string) $l['email']) . ($l['telefone'] ? ' · ' . pn_e(mcp_telefone_bonito((string) $l['telefone'])) : '') . '</small></td>'
            . '<td>' . pn_e($assuntos[$l['assunto']] ?? (string) $l['assunto']) . ($l['curso_nome'] ? '<small>' . pn_e((string) $l['curso_nome']) . '</small>' : '') . '</td>'
            . '<td>' . pn_status((string) $l['status']) . ($l['respondido_em'] ? '<small>' . pn_e(pn_data($l['respondido_em'])) . '</small>' : '') . '</td>'
            . '<td><a class="btn btn-outline" style="min-height:36px;padding:6px 14px" href="painel.php?id=' . (int) $l['id'] . '">' . ($l['status'] === 'novo' ? 'Responder' : 'Abrir') . '</a></td></tr>';
    }
    $corpo = '<div class="cabeca"><div><p class="eyebrow">Chat do site</p><h1>Mensagens recebidas</h1>'
        . '<p class="nota">Cada resposta enviada por aqui sai por e-mail no padrão do site e fica registrada. A pessoa pode responder direto para ' . pn_e(mcp_email_contato_endereco()) . '.</p></div></div>'
        . '<div class="filtros">' . $pilulas . '</div>'
        . '<div class="tabela"><table><thead><tr><th>Recebido</th><th>Quem</th><th>Assunto</th><th>Status</th><th></th></tr></thead><tbody>'
        . ($tabela !== '' ? $tabela : '<tr><td colspan="5" class="vazio">Nenhuma mensagem aqui.</td></tr>') . '</tbody></table></div>';
    $paginacao = '';
    if ($pagina > 1) {
        $paginacao .= '<a href="painel.php?v=mensagens&amp;f=' . $f . '&amp;p=' . ($pagina - 1) . '">Mais recentes</a>';
    }
    if (count($linhas) === MCP_PAINEL_POR_PAGINA) {
        $paginacao .= '<a href="painel.php?v=mensagens&amp;f=' . $f . '&amp;p=' . ($pagina + 1) . '">Mais antigas</a>';
    }
    if ($paginacao !== '') {
        $corpo .= '<div class="paginacao">' . $paginacao . '</div>';
    }
    pn_pagina('Mensagens', $corpo, $usuario, true, 'mensagens');
}

/** Dias e horários preferidos: mapa por curso, destaques, contagens, lista e planilha (?csv=1). */
function pn_horarios(string $usuario): never
{
    $contagem = mcp_horarios_contar();
    $curso = mcp_texto($_GET['curso'] ?? '', 80);
    if (!isset($contagem[$curso])) {
        $curso = '';
    }
    $linhas = mcp_horarios_listar($curso !== '' ? $curso : null, MCP_PAINEL_HORARIOS_LIMITE);
    if (isset($_GET['csv'])) {
        header('Content-Type: text/csv; charset=utf-8');
        $arquivo = 'horarios-' . ($curso !== '' ? preg_replace('/[^a-z0-9-]/', '', $curso) : 'todos-os-cursos') . '-' . gmdate('Y-m-d') . '.csv';
        header('Content-Disposition: attachment; filename="' . $arquivo . '"');
        mcp_registrar(null, 'painel_horarios_csv', $usuario . ' · ' . ($curso !== '' ? $curso : 'todos'));
        echo mcp_horarios_csv($linhas);
        exit;
    }
    $mapa = mcp_horarios_mapa($linhas);
    $base = 'painel.php?v=horarios' . ($curso !== '' ? '&curso=' . rawurlencode($curso) : '');

    $pilulas = '<a class="pilula' . ($curso === '' ? ' ativo' : '') . '" href="painel.php?v=horarios">Todos os cursos <span class="n">' . array_sum(array_column($contagem, 'n')) . '</span></a>';
    foreach ($contagem as $slug => $c) {
        $pilulas .= '<a class="pilula' . ($slug === $curso ? ' ativo' : '') . '" href="painel.php?v=horarios&amp;curso=' . pn_e(rawurlencode($slug)) . '">' . pn_e($c['nome']) . ' <span class="n">' . $c['n'] . '</span></a>';
    }

    // Grade período × dia: quanto mais alunos podem naquele horário, mais forte o vermelho.
    $grade = '<thead><tr><th></th>';
    foreach (MCP_HORARIOS_DIAS_CURTOS as $rotulo) {
        $grade .= '<th scope="col">' . pn_e($rotulo) . '</th>';
    }
    $grade .= '</tr></thead><tbody>';
    $slots = [];
    foreach (MCP_HORARIOS_PERIODOS as $p => $rotuloPeriodo) {
        $grade .= '<tr><th scope="row">' . pn_e($rotuloPeriodo) . '<small>' . pn_e(MCP_HORARIOS_PERIODOS_HORAS[$p]) . '</small></th>';
        foreach (MCP_HORARIOS_DIAS as $d => $rotuloDia) {
            $n = $mapa['grade'][$p][$d];
            $alfa = round(0.08 + 0.92 * ($mapa['maximo'] > 0 ? $n / $mapa['maximo'] : 0), 2);
            $estilo = $n > 0 ? 'background:rgba(204,0,0,' . $alfa . ')' . ($alfa >= 0.78 ? ';color:#fff' : '') : 'background:var(--soft);color:#cbd5e1';
            $grade .= '<td class="celula" style="' . $estilo . '" title="' . pn_e("$rotuloDia, " . mb_strtolower($rotuloPeriodo) . ": $n aluno(s)") . '">' . ($n > 0 ? $n : '·') . '</td>';
            if ($n > 0) {
                $slots[] = [$n, MCP_HORARIOS_DIAS_CURTOS[$d] . ' ' . mb_strtolower($rotuloPeriodo)];
            }
        }
        $grade .= '</tr>';
    }
    $grade .= '</tbody>';
    usort($slots, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
    $destaques = implode(' · ', array_map(static fn(array $s): string => pn_e($s[1]) . ' (' . $s[0] . ')', array_slice($slots, 0, 3)));

    $contas = '';
    foreach (MCP_HORARIOS_INICIO as $chave => $rotulo) {
        $contas .= '<li><span>' . pn_e($rotulo) . '</span><b>' . $mapa['inicio'][$chave] . '</b></li>';
    }
    $turma = '';
    if (array_sum($mapa['turma']) > 0) {
        foreach (MCP_HORARIOS_TURMA as $chave => $rotulo) {
            $turma .= '<li><span>' . pn_e($rotulo) . '</span><b>' . $mapa['turma'][$chave] . '</b></li>';
        }
    }

    $tabela = '';
    foreach ($linhas as $l) {
        $dataTurma = mcp_escola_data(mcp_horarios_turma($l));
        $tabela .= '<tr><td data-rotulo="Respondido">' . pn_e(pn_data((string) $l['atualizado_em'])) . ((int) $l['vezes'] > 1 ? '<small>alterado ' . ((int) $l['vezes'] - 1) . 'x</small>' : '') . '</td>'
            . '<td class="aluno"><a class="nome" href="painel.php?v=inscricao&amp;id=' . (int) $l['inscricao_id'] . '">' . pn_e((string) $l['nome']) . '</a><small><a href="mailto:' . pn_e((string) $l['email']) . '">' . pn_e((string) $l['email']) . '</a></small>'
            . ($l['telefone'] ? '<small class="tel">' . pn_e(mcp_telefone_bonito((string) $l['telefone'])) . '</small>' : '') . '</td>'
            . '<td data-rotulo="Curso">' . pn_e((string) $l['curso_nome']) . '<small>' . ($dataTurma !== '' ? 'turma de ' . pn_e($dataTurma) : 'sem turma ainda') . '</small></td>'
            . '<td class="horarios" data-rotulo="Pode vir">' . pn_e(mcp_horarios_texto($l['horarios'])) . '</td>'
            . '<td class="comeco" data-rotulo="Começo">' . pn_e(MCP_HORARIOS_INICIO[$l['inicio']] ?? (string) $l['inicio'])
            . ($l['turma_serve'] ? '<small>' . pn_e(MCP_HORARIOS_TURMA[$l['turma_serve']] ?? '') . '</small>' : '') . '</td>'
            . '<td class="recado" data-rotulo="Recado">' . ($l['observacao'] ? pn_e((string) $l['observacao']) : '<span style="color:var(--muted)">—</span>') . '</td></tr>';
    }

    $titulo = $curso !== '' ? $contagem[$curso]['nome'] : 'Todos os cursos';
    // Quem pagou e ainda não disse os horários: a lista (com o lembrete à mão) fica em Inscrições.
    $faltam = mcp_secretaria_contar($curso !== '' ? $curso : null)['sem_horarios'];
    $avisoFaltam = $faltam > 0 ? '<p class="faltam"><b>' . $faltam . ' aluno' . ($faltam > 1 ? 's pagaram e ainda não disseram os horários.' : ' pagou e ainda não disse os horários.')
        . '</b> <a href="' . pn_e('painel.php?v=inscricoes&f=sem_horarios' . ($curso !== '' ? '&curso=' . rawurlencode($curso) : '')) . '">Ver quem falta e mandar lembrete →</a></p>' : '';
    $corpo = '<div class="cabeca"><div><p class="eyebrow">Questionário do site</p><h1>Dias e horários preferidos</h1>'
        . '<p class="nota">Cada aluno responde depois de pagar a inscrição e pode mudar as respostas quando quiser. Use o mapa para escolher os dias das próximas turmas.</p></div>'
        . ($linhas ? '<a class="btn btn-outline" href="' . pn_e($base . '&csv=1') . '">Baixar planilha</a>' : '') . '</div>'
        . '<div class="filtros">' . $pilulas . '</div>'
        . $avisoFaltam
        . ($linhas
            ? '<div class="resumo-grade"><div class="cartao"><h2>' . pn_e($titulo) . ' · quantos alunos podem em cada horário</h2><div class="rolagem"><table class="mapa">' . $grade . '</table></div>'
              . ($destaques !== '' ? '<p class="destaques"><b>Mais pedidos:</b> ' . $destaques . '</p>' : '')
              . '<p class="nota">Cada número é quantos alunos marcaram aquele dia e período. Total: ' . $mapa['total'] . ' aluno(s)'
              . (count($linhas) >= MCP_PAINEL_HORARIOS_LIMITE ? ', contando só as ' . MCP_PAINEL_HORARIOS_LIMITE . ' respostas mais recentes' : '') . '.</p></div>'
              . '<div class="cartao"><h2>A partir de quando podem começar</h2><ul class="contas">' . $contas . '</ul>'
              . ($turma !== '' ? '<h2 style="margin-top:18px">A data da turma serve?</h2><ul class="contas">' . $turma . '</ul>' : '') . '</div></div>'
              . '<h2 style="margin:26px 0 12px">Respostas (' . count($linhas) . ')</h2>'
              . '<div class="tabela rolagem"><table class="respostas"><thead><tr><th>Respondido</th><th>Aluno</th><th>Curso</th><th>Pode vir</th><th>Começo</th><th>Recado</th></tr></thead><tbody>' . $tabela . '</tbody></table></div>'
            : '<div class="cartao vazio">Ainda não há respostas. O convite para responder aparece na tela de inscrição paga e no e-mail de confirmação.</div>');
    pn_pagina('Dias e horários', $corpo, $usuario, true, 'horarios');
}

/** Início: o que pede ação agora e o que chegou por último. Cada número leva à lista correspondente. */
function pn_inicio(string $usuario): never
{
    $contas = mcp_secretaria_contar();
    $novas = mcp_contatos_contar()['novo'];
    $numero = static fn(string $href, int $n, string $rotulo, string $explica, bool $alerta = false): string =>
        '<a class="numero' . ($alerta && $n > 0 ? ' alerta' : '') . '" href="' . pn_e($href) . '"><b>' . $n . '</b><span>' . pn_e($rotulo) . '</span><small>' . pn_e($explica) . '</small></a>';
    $numeros = '<div class="numeros">'
        . $numero('painel.php?v=inscricoes&f=pagas', $contas['pagas'], 'Inscrições pagas', mcp_secretaria_pagas_recentes(7) . ' nos últimos 7 dias')
        . $numero('painel.php?v=inscricoes&f=atencao', $contas['atencao'], 'Precisam de atenção', 'matrícula na escola com pendência', true)
        . $numero('painel.php?v=inscricoes&f=sem_horarios', $contas['sem_horarios'], 'Sem horários', 'pagaram e ainda não disseram os horários')
        . $numero('painel.php?v=mensagens&f=novo', $novas, 'Mensagens novas', 'do chat do site, sem resposta', true)
        . '</div>';

    $ultimas = '';
    foreach (mcp_secretaria_listar('pagas', null, '', 6) as $l) {
        $esc = mcp_secretaria_escola($l);
        $ultimas .= '<li><div><a href="painel.php?v=inscricao&amp;id=' . (int) $l['id'] . '">' . pn_e((string) $l['nome']) . '</a><small>' . pn_e((string) $l['curso_nome'])
            . ' · ' . pn_e(pn_data((string) $l['pago_em'])) . '</small></div><span class="selo ' . $esc['tom'] . '">' . pn_e($esc['rotulo']) . '</span></li>';
    }
    $assuntos = mcp_contato_assuntos();
    $mensagens = '';
    foreach (mcp_contatos_listar(null, 6) as $c) {
        $mensagens .= '<li><div><a href="painel.php?id=' . (int) $c['id'] . '">' . pn_e((string) $c['nome']) . '</a><small>' . pn_e($assuntos[$c['assunto']] ?? (string) $c['assunto'])
            . ' · ' . pn_e(pn_data((string) $c['criado_em'])) . '</small></div>' . pn_status((string) $c['status']) . '</li>';
    }
    $mapa = mcp_horarios_mapa(mcp_horarios_listar(null, MCP_PAINEL_HORARIOS_LIMITE));
    $pedidos = [];
    foreach ($mapa['grade'] as $periodo => $dias) {
        foreach ($dias as $dia => $n) {
            if ($n > 0) {
                $pedidos[] = [$n, MCP_HORARIOS_DIAS[$dia] . ' ' . MCP_HORARIOS_PERIODOS_FRASE[$periodo]];
            }
        }
    }
    usort($pedidos, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
    $horarios = '';
    foreach (array_slice($pedidos, 0, 5) as [$n, $rotulo]) {
        $horarios .= '<li><span>' . pn_e($rotulo) . '</span><b>' . $n . ' aluno' . ($n > 1 ? 's' : '') . '</b></li>';
    }

    $corpo = '<div class="cabeca"><div><p class="eyebrow">Portal da secretaria · ' . pn_e(mcp_data_brt(mcp_agora(), 'd/m/Y')) . '</p><h1>Início</h1>'
        . '<p class="nota">O que pede ação agora e o que chegou por último. Clique num número para ver a lista.</p></div></div>'
        . $numeros
        . '<div class="grade-inicio"><div class="cartao"><h2>Últimas inscrições pagas</h2>'
        . ($ultimas !== '' ? '<ul class="lista-curta">' . $ultimas . '</ul>' : '<p class="nota">Nenhuma inscrição paga ainda.</p>')
        . '<p class="nota"><a href="painel.php?v=inscricoes">Ver todas as inscrições →</a></p></div>'
        . '<div class="cartao"><h2>Mensagens recentes</h2>'
        . ($mensagens !== '' ? '<ul class="lista-curta">' . $mensagens . '</ul>' : '<p class="nota">Nenhuma mensagem ainda.</p>')
        . '<p class="nota"><a href="painel.php?v=mensagens">Ver todas as mensagens →</a></p></div></div>'
        . '<div class="cartao" style="margin-top:18px"><h2>Horários mais pedidos, em todos os cursos</h2>'
        . ($horarios !== '' ? '<ul class="lista-curta">' . $horarios . '</ul>' : '<p class="nota">Ainda não há respostas ao questionário de horários.</p>')
        . '<p class="nota"><a href="painel.php?v=horarios">Ver o mapa por curso →</a></p></div>';
    pn_pagina('Início', $corpo, $usuario, true, 'inicio');
}

/** Inscrições: filtros, busca por nome, e-mail, telefone ou CPF, curso, planilha (?csv=1) e paginação. */
function pn_inscricoes(string $usuario): never
{
    $f = mcp_texto($_GET['f'] ?? '', 20);
    if (!isset(MCP_SECRETARIA_FILTROS[$f])) {
        $f = 'pagas';
    }
    $cursos = mcp_secretaria_cursos();
    $curso = mcp_texto($_GET['curso'] ?? '', 80);
    if (!isset($cursos[$curso])) {
        $curso = '';
    }
    $q = mcp_texto($_GET['q'] ?? '', 80);
    $pagina = min(max(1, (int) ($_GET['p'] ?? 1)), 10000);
    $params = array_filter(['v' => 'inscricoes', 'f' => $f, 'curso' => $curso, 'q' => $q], static fn(string $x): bool => $x !== '');
    $url = static fn(array $extra): string => 'painel.php?' . http_build_query(array_merge($params, $extra));
    if (isset($_GET['csv'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="inscricoes-' . $f . ($curso !== '' ? '-' . preg_replace('/[^a-z0-9-]/', '', $curso) : '') . '-' . gmdate('Y-m-d') . '.csv"');
        mcp_registrar(null, 'painel_inscricoes_csv', $usuario . ' · ' . $f . ($curso !== '' ? " · $curso" : '') . ($q !== '' ? ' · com busca' : ''));
        echo mcp_secretaria_csv(mcp_secretaria_listar($f, $curso ?: null, $q, 5000));
        exit;
    }
    $contas = mcp_secretaria_contar($curso ?: null, $q);
    $linhas = mcp_secretaria_listar($f, $curso ?: null, $q, MCP_PAINEL_POR_PAGINA, ($pagina - 1) * MCP_PAINEL_POR_PAGINA);

    $pilulas = '';
    foreach (MCP_SECRETARIA_FILTROS as $chave => $rotulo) {
        $pilulas .= '<a class="pilula' . ($chave === $f ? ' ativo' : '') . '" href="' . pn_e($url(['f' => $chave, 'p' => null])) . '">' . pn_e($rotulo) . ' <span class="n">' . $contas[$chave] . '</span></a>';
    }
    $opcoes = '<option value="">Todos os cursos</option>';
    foreach ($cursos as $slug => $nome) {
        $opcoes .= '<option value="' . pn_e($slug) . '"' . ($slug === $curso ? ' selected' : '') . '>' . pn_e($nome) . '</option>';
    }
    $busca = '<form class="busca" method="get" action="painel.php" role="search"><input type="hidden" name="v" value="inscricoes"><input type="hidden" name="f" value="' . pn_e($f) . '">'
        . '<label class="sr" for="q">Buscar</label><input id="q" name="q" value="' . pn_e($q) . '" placeholder="Nome, e-mail, telefone ou CPF">'
        . '<label class="sr" for="curso-filtro">Curso</label><select id="curso-filtro" name="curso">' . $opcoes . '</select>'
        . '<button class="btn btn-outline" type="submit">Buscar</button>'
        . ($q !== '' || $curso !== '' ? '<a class="btn btn-outline" href="' . pn_e($url(['q' => null, 'curso' => null, 'p' => null])) . '">Limpar</a>' : '') . '</form>';

    $tabela = '';
    foreach ($linhas as $l) {
        $esc = mcp_secretaria_escola($l);
        $pref = $l['preferencia'];
        $lembretes = (int) $l['lembretes'];
        $horas = $l['status'] !== 'pago' ? '<span style="color:var(--muted)">—</span>'
            : ($pref ? pn_e(mcp_horarios_texto($pref['horarios']))
                : '<span class="selo neutro">Não respondeu</span>' . ($lembretes > 0 ? '<small>' . $lembretes . ' lembrete' . ($lembretes > 1 ? 's' : '') . ' enviado' . ($lembretes > 1 ? 's' : '') . '</small>' : ''));
        $ficha = 'painel.php?v=inscricao&amp;id=' . (int) $l['id'];
        $tabela .= '<tr><td data-rotulo="Data">' . pn_e(pn_data((string) ($l['pago_em'] ?: $l['criado_em']))) . '</td>'
            . '<td class="aluno"><a class="nome" href="' . $ficha . '">' . pn_e((string) $l['nome']) . '</a><small>' . pn_e((string) $l['email']) . '</small>'
            . ($l['telefone'] ? '<small class="tel">' . pn_e(mcp_telefone_bonito((string) $l['telefone'])) . '</small>' : '') . '</td>'
            . '<td data-rotulo="Curso">' . pn_e((string) $l['curso_nome']) . '</td>'
            . '<td class="comeco" data-rotulo="Pagamento">' . pn_e(mcp_brl((int) $l['total_centavos'])) . '<small>' . ($l['metodo'] === 'pix' ? 'PIX' : 'Cartão') . ' · ' . pn_e(MCP_SECRETARIA_STATUS[$l['status']] ?? (string) $l['status']) . '</small></td>'
            . '<td data-rotulo="Escola"><span class="selo ' . $esc['tom'] . '">' . pn_e($esc['rotulo']) . '</span></td>'
            . '<td class="horarios" data-rotulo="Horários">' . $horas . '</td>'
            . '<td class="abrir"><a class="btn btn-outline" style="min-height:36px;padding:6px 14px" href="' . $ficha . '">Abrir ficha</a></td></tr>';
    }
    $explica = [
        'pagas' => 'Inscrições com o pagamento confirmado.',
        'atencao' => 'Pagas, mas a matrícula na escola tem pendência: não foi feita, falhou, ficou sem turma ou a taxa já estava paga. Abra a ficha para ver o que fazer.',
        'sem_horarios' => 'Pagas, e o aluno ainda não disse em quais dias e horários consegue vir. Na ficha, dá para mandar um lembrete por e-mail.',
        'pendentes' => 'Começaram a inscrição e ainda não pagaram (PIX em aberto ou cartão não concluído).',
        'todas' => 'Todas as inscrições, inclusive recusadas, expiradas e estornadas.',
    ][$f];
    $corpo = '<div class="cabeca"><div><p class="eyebrow">Matrícula cursos presenciais</p><h1>Inscrições</h1><p class="nota">' . pn_e($explica) . '</p></div>'
        . ($linhas ? '<a class="btn btn-outline" href="' . pn_e($url(['csv' => 1, 'p' => null])) . '">Baixar planilha</a>' : '') . '</div>'
        . '<div class="filtros">' . $pilulas . '</div>' . $busca
        . '<div class="tabela rolagem"><table class="respostas"><thead><tr><th>Data</th><th>Aluno</th><th>Curso</th><th>Pagamento</th><th>Escola</th><th>Horários</th><th class="abrir"></th></tr></thead><tbody>'
        . ($tabela !== '' ? $tabela : '<tr><td colspan="7" class="vazio">Nenhuma inscrição aqui.</td></tr>') . '</tbody></table></div>';
    $paginacao = ($pagina > 1 ? '<a href="' . pn_e($url(['p' => $pagina - 1])) . '">Mais recentes</a>' : '')
        . (count($linhas) === MCP_PAINEL_POR_PAGINA ? '<a href="' . pn_e($url(['p' => $pagina + 1])) . '">Mais antigas</a>' : '');
    if ($paginacao !== '') {
        $corpo .= '<div class="paginacao">' . $paginacao . '</div>';
    }
    pn_pagina('Inscrições', $corpo, $usuario, true, 'inscricoes');
}

/** Ficha da inscrição: aluno, pagamento, escola, horários (com o lembrete à mão) e histórico. */
function pn_inscricao(string $usuario, int $id, string $aviso = '', string $classe = 'ok'): never
{
    $i = mcp_secretaria_inscricao($id);
    if (!$i) {
        pn_redirecionar('v=inscricoes');
    }
    $pago = $i['status'] === 'pago';
    $esc = mcp_secretaria_escola($i);
    $pref = $i['preferencia'];
    $dl = static function (array $linhas): string {
        $html = '';
        foreach ($linhas as $rotulo => $valor) {
            $html .= '<dt>' . pn_e($rotulo) . '</dt><dd>' . $valor . '</dd>';
        }
        return '<dl>' . $html . '</dl>';
    };
    $aluno = $dl([
        'Nome' => pn_e((string) $i['nome']),
        'CPF' => pn_e(mcp_cpf_formatado((string) $i['cpf'])),
        'E-mail' => '<a href="mailto:' . pn_e((string) $i['email']) . '">' . pn_e((string) $i['email']) . '</a>',
        'Telefone' => $i['telefone'] ? '<a href="tel:+55' . pn_e(mcp_digitos((string) $i['telefone'])) . '">' . pn_e(mcp_telefone_bonito((string) $i['telefone'])) . '</a>' : '—',
        'Curso' => pn_e((string) $i['curso_nome']),
    ]);
    $pagamento = $dl([
        'Situação' => '<span class="selo ' . ($pago ? 'ok' : ($i['status'] === 'pendente' ? 'neutro' : 'erro')) . '">' . pn_e(MCP_SECRETARIA_STATUS[$i['status']] ?? (string) $i['status']) . '</span>',
        'Valor' => pn_e(mcp_brl((int) $i['total_centavos'])) . ((int) $i['taxa_centavos'] > 0 ? ' <span style="color:var(--muted);font-weight:400">(inscrição ' . pn_e(mcp_brl((int) $i['inscricao_centavos'])) . ' + custos ' . pn_e(mcp_brl((int) $i['taxa_centavos'])) . ')</span>' : ''),
        'Método' => $i['metodo'] === 'pix' ? 'PIX' : pn_e(trim('Cartão ' . mb_convert_case((string) ($i['bandeira'] ?? ''), MB_CASE_TITLE) . ($i['ultimos4'] ? ' final ' . $i['ultimos4'] : ''))),
        'Iniciada em' => pn_e(pn_data((string) $i['criado_em'])),
        'Paga em' => $i['pago_em'] ? pn_e(pn_data((string) $i['pago_em'])) : '—',
        'Transação Unicopag' => $i['unicopag_hash'] ? '<code>' . pn_e((string) $i['unicopag_hash']) . '</code>' : '—',
        'Origem' => pn_e(implode(' · ', array_filter([(string) ($i['utm_source'] ?? ''), (string) ($i['utm_campaign'] ?? '')], 'strlen')) ?: 'direto'),
    ]);
    $escolaUrl = rtrim((string) mcp_cfg('ESCOLA_URL', 'https://escola.cursoscruzvermelha.org'), '/') . '/login';
    $escola = '<p><span class="selo ' . $esc['tom'] . '">' . pn_e($esc['rotulo']) . '</span></p>'
        . '<p class="orientacao">' . pn_e(mcp_secretaria_escola_orientacao($i)) . '</p>'
        . (($conta = mcp_secretaria_escola_conta($i)) !== null ? '<p class="nota">' . pn_e($conta) . '.</p>' : '')
        . '<p class="nota"><a href="' . pn_e($escolaUrl) . '" target="_blank" rel="noopener">Abrir a plataforma da escola ↗</a></p>';

    if (!$pago) {
        $horarios = '<p class="nota" style="margin:0">O questionário abre para o aluno depois que o pagamento é confirmado.</p>';
    } elseif ($pref) {
        $horarios = $dl(array_filter([
            'Pode vir' => pn_e(mcp_horarios_texto($pref['horarios'])),
            'Começo' => pn_e(MCP_HORARIOS_INICIO[$pref['inicio']] ?? (string) $pref['inicio']),
            'A data da turma serve?' => $pref['turma_serve'] ? pn_e(MCP_HORARIOS_TURMA[$pref['turma_serve']] ?? (string) $pref['turma_serve']) : null,
            'Recado' => $pref['observacao'] ? pn_e((string) $pref['observacao']) : null,
            'Respondido em' => pn_e(pn_data((string) $pref['atualizado_em'])) . ((int) $pref['vezes'] > 1 ? ' (mudou ' . ((int) $pref['vezes'] - 1) . 'x)' : ''),
        ], static fn(?string $v): bool => $v !== null));
    } else {
        $lembretes = (int) $i['lembretes'];
        $ultimo = $i['lembrete_em'] ? (int) strtotime($i['lembrete_em'] . ' UTC') : 0;
        $podeMandar = $ultimo === 0 || time() - $ultimo >= MCP_SECRETARIA_LEMBRETE_INTERVALO;
        $horarios = '<p style="margin:0 0 8px"><span class="selo neutro">Ainda não respondeu</span></p>'
            . '<p class="nota">' . ($lembretes > 0
                ? $lembretes . ' lembrete' . ($lembretes > 1 ? 's' : '') . ' enviado' . ($lembretes > 1 ? 's' : '') . ', o último em ' . pn_e(pn_data((string) $i['lembrete_em'])) . '.'
                : 'Nenhum lembrete enviado ainda.') . ' O aluno também vê um alerta na página da inscrição.</p>'
            . ($podeMandar
                ? '<form method="post" action="painel.php" class="acoes"><input type="hidden" name="acao" value="lembrete_horarios"><input type="hidden" name="id" value="' . (int) $i['id'] . '">'
                  . '<input type="hidden" name="t" value="' . pn_e(mcp_painel_csrf($usuario, 'lembrete_horarios', (int) $i['id'])) . '">'
                  . '<button class="btn btn-red" type="submit">Mandar lembrete agora</button></form>'
                  . '<p class="nota">O aluno recebe por e-mail o link pessoal para escolher os horários, no mesmo modelo dos lembretes automáticos.</p>'
                : '<p class="nota">Um novo lembrete pode sair a partir de ' . pn_e(pn_data(gmdate('Y-m-d H:i:s', $ultimo + MCP_SECRETARIA_LEMBRETE_INTERVALO))) . ' (no máximo um a cada 24 horas).</p>');
    }
    $horarios .= $pago ? '<p class="nota"><a href="' . pn_e(mcp_url_pagina('parabens', (string) $i['token'])) . '" target="_blank" rel="noopener">Ver a página da inscrição, como o aluno vê ↗</a></p>' : '';

    $tempo = '';
    foreach (mcp_secretaria_eventos((int) $i['id']) as $e) {
        $rotulo = mcp_secretaria_evento((string) $e['tipo'], $e['detalhe']);
        if ($rotulo !== null) {
            $tempo .= '<li><time>' . pn_e(pn_data((string) $e['criado_em'])) . '</time><span>' . pn_e($rotulo) . '</span></li>';
        }
    }

    $corpo = '<a class="voltar" href="painel.php?v=inscricoes">← Voltar para as inscrições</a>'
        . ($aviso !== '' ? '<div class="aviso ' . pn_e($classe) . '">' . pn_e($aviso) . '</div>' : '')
        . '<div class="cabeca"><div><p class="eyebrow">Inscrição #' . (int) $i['id'] . ' · ' . pn_e((string) $i['curso_nome']) . '</p><h1>' . pn_e((string) $i['nome']) . '</h1>'
        . '<p class="nota">' . pn_e(MCP_SECRETARIA_STATUS[$i['status']] ?? (string) $i['status']) . ($i['pago_em'] ? ' em ' . pn_e(pn_data((string) $i['pago_em'])) : '') . '</p></div></div>'
        . '<div class="ficha"><div class="cartao"><h2>Aluno</h2>' . $aluno . '</div><div class="cartao"><h2>Pagamento</h2>' . $pagamento . '</div>'
        . '<div class="cartao"><h2>Matrícula na escola</h2>' . $escola . '</div><div class="cartao"><h2>Horários</h2>' . $horarios . '</div></div>'
        . '<div class="cartao" style="margin-top:18px"><h2>Histórico</h2>' . ($tempo !== '' ? '<ul class="tempo">' . $tempo . '</ul>' : '<p class="nota" style="margin:0">Sem registros.</p>') . '</div>';
    pn_pagina((string) $i['nome'], $corpo, $usuario, true, 'inscricoes');
}

/**
 * Detalhe de um contato com o formulário de resposta. $quem = e-mail da sessão ou "c<id>" (link direto);
 * $baseQuery mantém o acesso nos links e formulários; $form devolve o que foi digitado quando algo falha.
 */
function pn_detalhe(array $c, string $quem, string $baseQuery, bool $comLista, ?string $usuario, string $aviso = '', string $classe = 'ok', array $form = []): never
{
    $id = (int) $c['id'];
    $assunto = mcp_contato_assunto_rotulo((string) $c['assunto']);
    $ocultos = '<input type="hidden" name="id" value="' . $id . '">';
    parse_str($baseQuery, $params);
    foreach (['c', 'e', 'k'] as $chave) {
        if (isset($params[$chave])) {
            $ocultos .= '<input type="hidden" name="' . $chave . '" value="' . pn_e((string) $params[$chave]) . '">';
        }
    }
    $assinatura = (string) ($form['assinatura'] ?? ($_COOKIE[MCP_PAINEL_COOKIE_ASSINATURA] ?? ''));
    $resposta = (string) ($form['resposta'] ?? ("Oi, " . mcp_primeiro_nome((string) $c['nome']) . "!\n\n\n\nQualquer outra dúvida, é só responder este e-mail."));

    $dados = [
        'Protocolo' => pn_e((string) $c['protocolo']),
        'Status' => pn_status((string) $c['status']),
        'Nome' => pn_e((string) $c['nome']),
        'E-mail' => '<a href="mailto:' . pn_e((string) $c['email']) . '">' . pn_e((string) $c['email']) . '</a>',
        'Telefone' => $c['telefone'] ? '<a href="tel:+55' . pn_e(mcp_digitos((string) $c['telefone'])) . '">' . pn_e(mcp_telefone_bonito((string) $c['telefone'])) . '</a>' : '<span style="color:var(--muted);font-weight:400">não informado</span>',
        'Assunto' => pn_e($assunto),
    ];
    if ($c['curso_nome']) {
        $dados['Curso'] = pn_e((string) $c['curso_nome']);
    }
    if ($c['pagina']) {
        $dados['Página'] = '<a href="' . pn_e(mcp_site_url() . (string) $c['pagina']) . '" target="_blank" rel="noopener">' . pn_e((string) $c['pagina']) . '</a>';
    }
    $dados['Origem'] = pn_e(trim(($c['utm_source'] ?? '') . ' ' . ($c['utm_campaign'] ?? '')) ?: 'direto');
    $dados['Recebido em'] = pn_e(pn_data((string) $c['criado_em'])) . ' (Brasília)';
    if ($c['respondido_em']) {
        $dados['Respondido em'] = pn_e(pn_data((string) $c['respondido_em'])) . ($c['respondido_por'] ? ' por ' . pn_e((string) $c['respondido_por']) : '');
    }
    $dl = '';
    foreach ($dados as $rotulo => $html) {
        $dl .= '<dt>' . pn_e($rotulo) . '</dt><dd>' . $html . '</dd>';
    }

    $statusAcao = $c['status'] === 'arquivado' ? 'reabrir' : 'arquivar';
    $corpo = ($comLista ? '<a class="voltar" href="painel.php?v=mensagens">← Voltar para as mensagens</a>' : '')
        . ($aviso !== '' ? '<div class="aviso ' . pn_e($classe) . '">' . pn_e($aviso) . '</div>' : '')
        . '<div class="cabeca"><div><p class="eyebrow">Chat do site · ' . pn_e((string) $c['protocolo']) . '</p><h1>' . pn_e((string) $c['nome']) . '</h1>'
        . '<p class="nota">' . pn_e($assunto) . ($c['curso_nome'] ? ' · ' . pn_e((string) $c['curso_nome']) : '') . ' · recebido em ' . pn_e(pn_data((string) $c['criado_em'])) . '</p></div></div>'
        . '<div class="grade"><div class="cartao"><h2>Contato</h2><dl>' . $dl . '</dl></div>'
        . '<div class="cartao"><h2>Mensagem</h2><p class="mensagem">' . pn_e((string) $c['mensagem']) . '</p>'
        . ($c['resposta'] ? '<h2 style="margin-top:18px">Resposta enviada' . ($c['respondido_em'] ? ' em ' . pn_e(pn_data((string) $c['respondido_em'])) : '') . '</h2><p class="resposta-anterior">' . pn_e((string) $c['resposta']) . '</p>' : '')
        . '</div></div>'
        . '<div class="cartao" style="margin-top:18px"><h2>' . ($c['resposta'] ? 'Enviar outra resposta por e-mail' : 'Responder por e-mail') . '</h2>'
        . '<p class="nota" style="margin:0 0 6px">A pessoa recebe a resposta no padrão visual do site, com o protocolo no assunto, vinda de ' . pn_e(mcp_email_endereco(mcp_email_remetente_contato())) . '. Se ela responder, cai em ' . pn_e(mcp_email_contato_endereco()) . '.</p>'
        . '<form method="post" action="painel.php">' . $ocultos . '<input type="hidden" name="acao" value="responder"><input type="hidden" name="t" value="' . pn_e(mcp_painel_csrf($quem, 'responder', $id)) . '">'
        . '<label for="assinatura">Seu nome (assina a resposta)</label><input id="assinatura" name="assinatura" required maxlength="80" value="' . pn_e($assinatura) . '" placeholder="Ex.: Ana, da equipe de cursos">'
        . '<label for="resposta">Resposta</label><textarea id="resposta" name="resposta" required maxlength="' . MCP_PAINEL_RESPOSTA_MAX . '">' . pn_e($resposta) . '</textarea>'
        . '<div class="acoes"><button class="btn btn-red" type="submit">Enviar resposta por e-mail</button></div></form>'
        . '<div class="acoes"><form method="post" action="painel.php">' . $ocultos . '<input type="hidden" name="acao" value="' . $statusAcao . '"><input type="hidden" name="t" value="' . pn_e(mcp_painel_csrf($quem, $statusAcao, $id)) . '">'
        . '<button class="btn btn-outline" type="submit">' . ($statusAcao === 'arquivar' ? 'Arquivar sem responder' : 'Reabrir contato') . '</button></form></div>'
        . '</div>';
    pn_pagina((string) $c['nome'], $corpo, $usuario, $comLista, 'mensagens');
}

// ----------------------------------------------------------------------------- fluxo
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$sessao = mcp_painel_sessao();
$avisos = [
    'respondido' => ['Resposta enviada por e-mail. O contato ficou como respondido.', 'ok'],
    'arquivar' => ['Contato arquivado.', 'ok'],
    'reabrir' => ['Contato reaberto.', 'ok'],
    'lb_ok' => ['Lembrete de horários enviado ao aluno por e-mail.', 'ok'],
    'lb_recente' => ['Já saiu um lembrete nas últimas 24 horas. Espere um pouco antes de mandar outro.', 'erro'],
    'lb_resp' => ['O aluno já respondeu os horários; nenhum lembrete foi enviado.', 'ok'],
    'lb_falhou' => ['O e-mail não pôde ser enviado agora. Tente de novo em instantes.', 'erro'],
    'lb_naopago' => ['Esta inscrição não está paga; o questionário ainda não abriu para o aluno.', 'erro'],
];

if ($metodo === 'GET' && isset($_GET['sair'])) {
    mcp_painel_sessao_fechar();
    pn_redirecionar('');
}

if ($metodo === 'GET' && isset($_GET['entrar'])) {
    $email = mb_strtolower(mcp_texto($_GET['entrar'] ?? '', 190));
    if (!mcp_painel_entrada_valida($email, (int) ($_GET['e'] ?? 0), mcp_texto($_GET['k'] ?? '', 40)) || !in_array($email, mcp_painel_emails_permitidos(), true)) {
        pn_login('Este link de acesso venceu ou não é válido. Peça outro.');
    }
    mcp_painel_sessao_abrir($email);
    mcp_registrar(null, 'painel_entrada', $email);
    pn_redirecionar('');
}

if ($metodo === 'POST') {
    $acao = mcp_texto($_POST['acao'] ?? '', 20);
    if ($acao === 'entrar') {
        $email = mb_strtolower(mcp_texto($_POST['email'] ?? '', 190));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            pn_login('Digite um e-mail válido.');
        }
        [$maximo, $janela] = MCP_PAINEL_LIMITE_LINKS;
        if (mcp_contar_eventos_recentes('painel_link', mcp_ip(), $janela) >= $maximo) {
            pn_login('Muitos pedidos de link em pouco tempo. Aguarde alguns minutos.');
        }
        mcp_registrar(null, 'painel_link', mcp_ip());
        // Não revela quem tem acesso: a mesma mensagem para e-mail permitido e não permitido.
        $mensagem = 'Se este e-mail tiver acesso, o link chega em instantes. Confira a caixa de entrada e o spam; o link vale ' . MCP_PAINEL_LINK_ENTRADA_MINUTOS . ' minutos.';
        if (in_array($email, mcp_painel_emails_permitidos(), true) && mcp_email_painel_link($email) === 'falhou') {
            pn_login('Não foi possível enviar o link agora. Tente de novo em instantes.');
        }
        pn_login($mensagem, 'ok');
    }

    // Lembrete de horários à mão, pela ficha da inscrição: só com sessão.
    if ($acao === 'lembrete_horarios') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($sessao === null || $id <= 0 || !hash_equals(mcp_painel_csrf($sessao, 'lembrete_horarios', $id), mcp_texto($_POST['t'] ?? '', 40))) {
            pn_login('Sua sessão venceu ou o formulário não é mais válido. Entre de novo.');
        }
        $inscricao = mcp_inscricao_por('id', (string) $id);
        if (!$inscricao) {
            pn_redirecionar('v=inscricoes');
        }
        $resultado = mcp_secretaria_lembrete($inscricao, $sessao);
        mcp_registrar(null, 'painel_lembrete', "#$id · $sessao · $resultado");
        pn_redirecionar('v=inscricao&id=' . $id . '&ok=' . ['enviado' => 'lb_ok', 'recente' => 'lb_recente', 'respondido' => 'lb_resp', 'falhou' => 'lb_falhou', 'nao_pago' => 'lb_naopago'][$resultado]);
    }

    $id = (int) ($_POST['id'] ?? 0);
    $viaLink = mcp_painel_contato_autorizado($_POST);
    $quem = $sessao ?? ($viaLink !== null && $viaLink === $id ? "c$id" : null);
    if ($quem === null || $id <= 0) {
        pn_login('Sua sessão venceu ou o link não é mais válido. Entre de novo.');
    }
    if (!in_array($acao, ['responder', 'arquivar', 'reabrir'], true) || !hash_equals(mcp_painel_csrf($quem, $acao, $id), mcp_texto($_POST['t'] ?? '', 40))) {
        pn_login('Formulário inválido. Abra o contato de novo.');
    }
    $c = mcp_contato_por_id($id);
    if (!$c) {
        pn_login('Contato não encontrado.');
    }
    $baseQuery = $sessao !== null ? 'id=' . $id : mcp_painel_query_contato($id, $_POST);
    $comLista = $sessao !== null;

    if ($acao === 'arquivar' || $acao === 'reabrir') {
        mcp_contato_atualizar($id, ['status' => $acao === 'arquivar' ? 'arquivado' : 'novo']);
        mcp_registrar(null, 'painel_' . $acao, "#$id · $quem");
        pn_redirecionar($baseQuery . '&ok=' . $acao);
    }

    $assinatura = mcp_texto($_POST['assinatura'] ?? '', 80);
    $resposta = mcp_texto_longo($_POST['resposta'] ?? '', MCP_PAINEL_RESPOSTA_MAX + 1);
    $form = ['assinatura' => $assinatura, 'resposta' => $resposta];
    if (mb_strlen($assinatura) < 2) {
        pn_detalhe($c, $quem, $baseQuery, $comLista, $sessao, 'Informe seu nome para assinar a resposta.', 'erro', $form);
    }
    if (mb_strlen($resposta) < MCP_PAINEL_RESPOSTA_MIN || mb_strlen($resposta) > MCP_PAINEL_RESPOSTA_MAX) {
        pn_detalhe($c, $quem, $baseQuery, $comLista, $sessao, 'A resposta precisa ter entre ' . MCP_PAINEL_RESPOSTA_MIN . ' e ' . number_format(MCP_PAINEL_RESPOSTA_MAX, 0, ',', '.') . ' caracteres.', 'erro', $form);
    }
    setcookie(MCP_PAINEL_COOKIE_ASSINATURA, $assinatura, mcp_painel_cookie_opcoes(time() + 365 * 86400));
    $envio = mcp_email_resposta_contato($c, $resposta, $assinatura);
    if ($envio === 'falhou') {
        mcp_registrar(null, 'painel_resposta_falhou', "#$id · $quem");
        pn_detalhe($c, $quem, $baseQuery, $comLista, $sessao, 'O e-mail não pôde ser enviado agora; nada foi registrado. Tente de novo em instantes.', 'erro', $form);
    }
    mcp_contato_atualizar($id, [
        'status' => 'respondido', 'resposta' => $resposta, 'respondido_em' => mcp_agora(), 'email_resposta' => $envio,
        'respondido_por' => mb_substr($assinatura . ($sessao !== null ? " <$sessao>" : ''), 0, 120),
    ]);
    mcp_registrar(null, 'painel_resposta', "#$id · $quem · $envio");
    pn_redirecionar($baseQuery . '&ok=respondido');
}

// GET: link direto de um contato (sem sessão), lista ou detalhe da sessão, ou tela de entrada.
$ok = mcp_texto($_GET['ok'] ?? '', 12);
[$aviso, $classe] = $avisos[$ok] ?? ['', 'ok'];
$viaLink = mcp_painel_contato_autorizado($_GET);
if ($sessao === null && $viaLink !== null) {
    $c = mcp_contato_por_id($viaLink);
    if (!$c) {
        pn_login('Contato não encontrado.');
    }
    pn_detalhe($c, "c$viaLink", mcp_painel_query_contato($viaLink, $_GET), false, null, $aviso, $classe);
}
if ($sessao === null) {
    pn_login();
}
$secao = mcp_texto($_GET['v'] ?? '', 20);
if ($secao === 'horarios') {
    pn_horarios($sessao);
}
if ($secao === 'inscricoes') {
    pn_inscricoes($sessao);
}
if ($secao === 'inscricao') {
    pn_inscricao($sessao, (int) ($_GET['id'] ?? 0), $aviso, $classe);
}
if (isset($_GET['id'])) {
    $c = mcp_contato_por_id((int) $_GET['id']);
    if (!$c) {
        pn_lista($sessao);
    }
    pn_detalhe($c, $sessao, 'id=' . (int) $c['id'], true, $sessao, $aviso, $classe);
}
if ($secao === 'mensagens' || isset($_GET['f'])) {
    pn_lista($sessao);
}
pn_inicio($sessao);
