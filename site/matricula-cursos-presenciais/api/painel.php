<?php
/**
 * Portal da secretaria. Menu no cabeçalho com sete itens:
 *   - Início: o que pede ação e o que chegou por último;
 *   - Inscrições (?v=inscricoes): filtros, busca, planilha, e a ficha de cada uma (?v=inscricao&id=)
 *     com o histórico e o lembrete de horários à mão (lib/secretaria.php);
 *   - Horários dos alunos (?v=horarios): mapa por curso, lista e planilha (lib/horarios.php);
 *   - Mensagens do chat (?v=mensagens): lista, detalhe e resposta por e-mail no padrão da instituição;
 *   - Turmas sob demanda (?v=turmas): pedidos de turma fechada (15 a 30 alunos) e listas de interesse por
 *     curso e idioma, com a soma de alunos, a situação de cada pedido e a planilha (lib/turmas.php);
 *   - Ponto da sede (?v=ponto): horas doadas por voluntários e diretoria e presença dos outros vínculos,
 *     com a ficha de cada um (?v=colaborador&id=): termo de adesão, correções, declaração de horas e
 *     lembretes; saídas informadas pelas pessoas; importar a planilha (?v=importar); presença dos alunos
 *     nas aulas; aparelhos da recepção e o cartaz do QR code (lib/ponto.php e lib/presenca.php);
 *   - Comunicação (?v=comunicacao): lembretes, comunicados das três fases da implantação, fila do
 *     WhatsApp, envios e resultados (lib/painel_comunicacao.php, lib/avisos.php, lib/comunicacao.php);
 *   - Escola (plataforma da escola): link externo.
 *
 * Acesso (lib/painel.php): pelo link assinado que vai no aviso à equipe (abre só aquele contato) ou por
 * um link de entrada enviado ao e-mail da equipe (sessão de 12 h com a lista completa). A resposta sai
 * por mcp_email_resposta_contato() e fica registrada em mcp_contatos (status, resposta, respondido_por,
 * respondido_em). Tudo é HTML gerado no servidor, sem JavaScript obrigatório.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/lib/painel_comunicacao.php';

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
    'turmas' => '<circle cx="9" cy="8" r="3"/><path d="M3 20v-1a5 5 0 0 1 5-5h2a5 5 0 0 1 5 5v1"/><circle cx="17" cy="9" r="2.5"/><path d="M16 14h1a4 4 0 0 1 4 4v1"/>',
    'ponto' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'comunicacao' => '<path d="M4 10v4a1 1 0 0 0 1 1h2l5 4V5L7 9H5a1 1 0 0 0-1 1z"/><path d="M16 9a4 4 0 0 1 0 6M19 6a8 8 0 0 1 0 12"/>',
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
        'turmas' => ['painel.php?v=turmas', 'Turmas sob demanda', mcp_turma_contar()['acao'], 'pedidos de turma fechada sem resposta ou listas que chegaram a ' . MCP_TURMA_MINIMO],
        'ponto' => ['painel.php?v=ponto', 'Ponto da sede', mcp_ponto_esquecidas_contar() + mcp_ponto_termos_pendentes_contar() + mcp_ponto_saidas_informadas_contar(),
            'pendências (saídas esquecidas, saídas informadas para conferir e termos de adesão)'],
        'comunicacao' => ['painel.php?v=comunicacao', 'Comunicação', mcp_avisos_fila_manual_contar(), 'mensagens na fila do WhatsApp'],
    ];
    $html = '';
    foreach ($itens as $chave => [$href, $rotulo, $n, $explica]) {
        $html .= '<a href="' . pn_e($href) . '"' . ($aba === $chave ? ' aria-current="page"' : '') . '>' . pn_icone($chave) . '<span>' . pn_e($rotulo) . '</span>'
            . ($n > 0 ? '<span class="badge' . ($chave === 'horarios' ? ' suave' : '') . '" title="' . $n . ' ' . pn_e($explica) . '">' . $n . '<span class="sr"> ' . pn_e($explica) . '</span></span>' : '') . '</a>';
    }
    $escola = rtrim((string) mcp_cfg('ESCOLA_URL', 'https://escola.cursoscruzvermelha.org'), '/') . '/login';
    $html .= '<a class="externo" title="Plataforma da escola (abre em outra aba)" href="' . pn_e($escola) . '" target="_blank" rel="noopener">' . pn_icone('escola')
        . '<span>Escola</span><span class="sr">: plataforma da escola (abre em outra aba)</span></a>';
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
:root{--red:#cc0000;--red-dark:#a30000;--black:#0f1318;--text:#1a202c;--muted:#5f6b7a;--line:#e2e8f0;--soft:#f7f8fa}
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
th{text-align:left;font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:#4a5568;padding:12px 16px;border-bottom:1px solid var(--line);background:var(--soft);white-space:nowrap}
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
input:not([type=checkbox]):not([type=radio]):focus,textarea:focus{outline:0;border-color:var(--red);box-shadow:0 0 0 4px rgba(204,0,0,.25)}input[type=checkbox]:focus-visible,input[type=radio]:focus-visible{outline:3px solid var(--black);outline-offset:2px}
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
.menu a{position:relative;display:inline-flex;align-items:center;gap:7px;padding:12px 8px 11px;font-weight:700;font-size:.88rem;color:var(--muted);text-decoration:none;border-bottom:3px solid transparent;white-space:nowrap}
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
.progresso{height:8px;background:var(--soft);border-radius:999px;overflow:hidden;margin:6px 0 2px;box-shadow:inset 0 0 0 1px var(--line)}.progresso i{display:block;height:100%;background:var(--red)}
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
.navegar{display:inline-flex;align-items:center;gap:12px;margin:0 0 16px;font-size:.95rem}
.navegar a,.navegar span{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border:1px solid var(--line);border-radius:10px;background:#fff;text-decoration:none;color:var(--black);font-weight:700}
.navegar a:hover{border-color:var(--red);color:var(--red)}.navegar span{color:#cbd5e1}.navegar b{min-width:160px;text-align:center;color:var(--black)}
details.ajustar{margin-top:4px}details.ajustar summary{cursor:pointer;color:var(--red);font-weight:700;font-size:.85rem}
.mini{display:grid;gap:8px;margin:10px 0 6px;max-width:440px}.mini label{margin:0;font-size:.8rem}
.mini input{padding:8px 10px;font-size:.92rem;border-radius:10px}.mini .btn{min-height:38px;padding:8px 16px;justify-self:start}
.form-grade{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 16px}
@media(max-width:640px){.form-grade{grid-template-columns:minmax(0,1fr)}}
label.check{display:flex;gap:10px;align-items:center;font-weight:600}label.check input{width:18px;height:18px}
.na-sede{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:8px}
.na-sede li{background:#e9f7ef;color:#0f5132;border-radius:999px;padding:6px 12px;font-size:.88rem;font-weight:600}
.historico{max-width:440px;overflow-wrap:anywhere}td small.dia{white-space:nowrap}
.campo-erro input,.campo-erro select{border-color:var(--red);box-shadow:0 0 0 3px rgba(204,0,0,.1)}
select.campo{width:100%;font:inherit;font-size:.98rem;padding:11px 14px;border:1px solid #cbd5e1;border-radius:12px;background:#fff;color:var(--text)}
select.campo:focus{outline:0;border-color:var(--red);box-shadow:0 0 0 4px rgba(204,0,0,.12)}
h2 small{font-weight:600;font-size:.8rem;color:var(--muted);margin-left:6px}
.cartao.termo.pendente{border-left:4px solid #b45309}.cartao.termo.ok{border-left:4px solid #0f7b3e}
.pc-mini{min-height:36px;padding:6px 14px;font-size:.86rem}
.pc-check{align-items:flex-start;margin:0 0 12px}.pc-check input{margin-top:3px;flex-shrink:0}.pc-check span>b{display:block;font-weight:700}.pc-check span small{display:block;color:var(--muted);font-weight:500;font-size:.84rem}
.pc-canais{display:flex;flex-wrap:wrap;gap:6px 16px}.pc-canais .check{margin:6px 0}
.pc-fases{list-style:none;margin:0 0 14px;padding:0;counter-reset:fase}.pc-fase{position:relative;padding:0 0 12px 34px;counter-increment:fase}
.pc-fase:before{content:counter(fase);position:absolute;left:0;top:0;width:24px;height:24px;border-radius:50%;background:var(--red);color:#fff;font-weight:800;font-size:.8rem;display:flex;align-items:center;justify-content:center}
.pc-fase>b{display:block;color:var(--black);margin:1px 0 2px}
.pc-editor{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:18px;align-items:start;margin-top:18px}.pc-editor>div>.cartao+.cartao{margin-top:18px}
@media(max-width:960px){.pc-editor{grid-template-columns:minmax(0,1fr)}}
.pc-rotulo{margin:14px 0 6px;font-size:.78rem;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);font-weight:800}.pc-rotulo b{text-transform:none;letter-spacing:0;color:var(--black)}
.pc-previa{width:100%;height:560px;border:1px solid var(--line);border-radius:12px;background:#f7f8fa}
.pc-zap{background:#efeae2;border-radius:12px;padding:14px}.pc-balao{max-width:92%;background:#fff;border-radius:0 12px 12px 12px;padding:10px 12px;font-size:.92rem;line-height:1.45;box-shadow:0 1px 1px rgba(0,0,0,.08);overflow-wrap:anywhere}
.pc-ponto{margin:0;padding:12px 14px;border-radius:12px;background:#fff7e6;border:1px solid #f6d7a7;color:#6b4400;font-size:.92rem}
.pc-fila{list-style:none;margin:0;padding:0;display:grid;gap:12px}.pc-item{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 16px}
.pc-item-topo{display:flex;flex-wrap:wrap;justify-content:space-between;gap:10px;align-items:flex-start}.pc-item-topo small{display:block;color:var(--muted);font-size:.84rem}
.pc-item details summary{cursor:pointer;color:var(--red);font-weight:700;font-size:.85rem;margin-top:8px}.pc-item textarea{min-height:0;margin-top:8px;font-size:.88rem}
.pc-var{display:block;margin-top:6px;font-size:.78rem;font-weight:700;color:var(--muted)}.pc-var span{font-weight:500}.pc-var.bom{color:#0f7b3e}.pc-var.ruim{color:#b91c1c}
.pc-barras{list-style:none;margin:0;padding:0;display:grid;gap:8px}.pc-barras li{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr) auto;gap:10px;align-items:center;font-size:.88rem}
.pc-barras-rotulo{color:var(--text)}.pc-barras-trilho{height:12px;background:var(--soft);border-radius:4px;overflow:hidden}
.pc-barras-barra{display:block;height:100%;background:#2a78d6;border-radius:0 4px 4px 0}.pc-barras b{font-variant-numeric:tabular-nums;color:var(--black);min-width:2.5em;text-align:right}
.pc-colunas{display:flex;align-items:flex-end;gap:2px;height:170px;padding:18px 0 0;border-bottom:1px solid var(--line)}
.pc-coluna{flex:1 1 0;min-width:3px;height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;position:relative}
.pc-coluna-barra{display:block;width:100%;max-width:24px;background:#2a78d6;border-radius:4px 4px 0 0;min-height:0}
.pc-coluna-valor{font-size:.7rem;color:var(--muted);line-height:1.2;font-variant-numeric:tabular-nums}
.pc-coluna-dia{position:absolute;bottom:-20px;font-size:.68rem;color:var(--muted);white-space:nowrap}
.pc-colunas+details{margin-top:28px}
.pc-comentarios{list-style:none;margin:0;padding:0;display:grid;gap:12px}.pc-comentarios small{display:block;color:var(--muted);font-size:.8rem;margin-top:4px}
code{background:var(--soft);border-radius:6px;padding:1px 5px;font-size:.84em}
table.emergencia td.conferido{width:90px}table.emergencia td.conferido::after{content:"";display:inline-block;width:22px;height:22px;border:2px solid #4a5568;border-radius:4px}
@media print{.faixa,.menu,.acoes,.filtros,.voltar,details.ajustar,.topo .usuario{display:none!important}body{background:#fff}.cartao,.numero,.tabela{box-shadow:none;break-inside:avoid}.grade-inicio{grid-template-columns:1fr 1fr}}
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

/** Formulário de situação de um pedido (ou de todos os abertos de uma lista, com $id = 0). */
function pn_turma_form_status(string $usuario, int $id, string $atual, array $volta, string $rotulo = 'Salvar'): string
{
    $acao = $id > 0 ? 'turma_status' : 'turma_lista';
    $opcoes = '';
    foreach (MCP_TURMA_STATUS as $chave => $nome) {
        $opcoes .= '<option value="' . $chave . '"' . ($chave === $atual ? ' selected' : '') . '>' . pn_e($nome) . '</option>';
    }
    $ocultos = '';
    foreach ($volta as $k => $v) {
        $ocultos .= '<input type="hidden" name="' . pn_e($k) . '" value="' . pn_e($v) . '">';
    }
    return '<form class="busca" style="margin:8px 0 0" method="post" action="painel.php"><input type="hidden" name="acao" value="' . $acao . '"><input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="t" value="' . pn_e(mcp_painel_csrf($usuario, $acao, $id)) . '">' . $ocultos
        . '<label class="sr" for="st-' . $id . '">Situação</label><select id="st-' . $id . '" name="status" style="flex:1 1 140px">' . $opcoes . '</select>'
        . '<button class="btn btn-outline" type="submit">' . pn_e($rotulo) . '</button></form>';
}

/** Linhas da tabela de pedidos (turma fechada ou de uma lista), com a situação editável. */
function pn_turma_linhas(string $usuario, array $linhas, array $volta): string
{
    $tons = ['novo' => 'erro', 'em_contato' => 'alerta', 'turma_marcada' => 'ok', 'arquivado' => 'neutro'];
    $html = '';
    foreach ($linhas as $l) {
        $tel = (string) $l['telefone'];
        $local = $l['local'] === 'outro' ? '<small><span class="selo alerta">Fora da sede</span> ' . pn_e((string) $l['local_endereco']) . '</small>' : '<small>Na sede</small>';
        $obs = $l['observacoes'] ? '<details style="margin-top:6px"><summary style="cursor:pointer;font-size:.85rem">Observações</summary><p class="mensagem" style="font-size:.88rem;margin-top:6px">' . pn_e((string) $l['observacoes']) . '</p></details>' : '';
        $html .= '<tr><td data-rotulo="Recebido">' . pn_e(pn_data((string) $l['criado_em'])) . '<small>' . pn_e((string) $l['protocolo']) . '</small></td>'
            . '<td class="aluno"><b>' . pn_e((string) $l['nome']) . '</b>' . ($l['organizacao'] ? '<small>' . pn_e((string) $l['organizacao']) . '</small>' : '')
            . '<small><a href="mailto:' . pn_e((string) $l['email']) . '">' . pn_e((string) $l['email']) . '</a></small>'
            . ($tel !== '' ? '<small class="tel"><a href="https://wa.me/55' . pn_e(mcp_digitos($tel)) . '" target="_blank" rel="noopener">' . pn_e(mcp_telefone_bonito($tel)) . '</a></small>'
                : '<small>Só e-mail (aviso da data)</small>') . '</td>'
            . '<td data-rotulo="Turma"><b>' . pn_e(mcp_turma_alunos((int) $l['pessoas'])) . '</b><small>' . pn_e(mcp_turma_rotulo((string) $l['curso_nome'], (string) $l['idioma'])) . '</small>' . $local
            . ($l['periodo'] ? '<small>Prefere: ' . pn_e((string) $l['periodo']) . '</small>' : '') . $obs . '</td>'
            . '<td data-rotulo="Situação"><span class="selo ' . ($tons[$l['status']] ?? 'neutro') . '">' . pn_e(MCP_TURMA_STATUS[$l['status']] ?? (string) $l['status']) . '</span>'
            . ($l['status_em'] ? '<small>' . pn_e(pn_data((string) $l['status_em'])) . '</small>' : '')
            . pn_turma_form_status($usuario, (int) $l['id'], (string) $l['status'], $volta) . '</td></tr>';
    }
    return $html;
}

/**
 * Turmas sob demanda: pedidos de turma fechada (prioridade) e listas de interesse por curso e idioma. Com
 * ?curso=&idioma=, uma lista só, com quem está nela. ?f=todos inclui marcados e arquivados; ?csv=1 baixa.
 */
function pn_turmas(string $usuario, string $aviso, string $classe): never
{
    $cursos = mcp_turma_cursos();
    $curso = mcp_texto($_GET['curso'] ?? '', 80);
    $idioma = mcp_texto($_GET['idioma'] ?? '', 2);
    $naLista = mcp_turma_lista_valida($curso, $idioma);
    $todos = mcp_texto($_GET['f'] ?? '', 10) === 'todos';
    $params = array_filter(['v' => 'turmas', 'curso' => $naLista ? $curso : '', 'idioma' => $naLista ? $idioma : '', 'f' => $todos ? 'todos' : ''], static fn(string $x): bool => $x !== '');
    $url = static fn(array $extra): string => 'painel.php?' . http_build_query(array_merge($params, $extra));
    $volta = array_intersect_key($params, ['curso' => 1, 'idioma' => 1, 'f' => 1]);
    $filtro = $naLista ? ['tipo' => 'lista', 'curso' => $curso, 'idioma' => $idioma] : ['tipo' => 'fechada'];
    $filtro['status'] = $todos ? 'todos' : null;
    if (isset($_GET['csv'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="turmas-' . ($naLista ? preg_replace('/[^a-z0-9-]/', '', $curso) . '-' . $idioma : 'fechadas') . '-' . gmdate('Y-m-d') . '.csv"');
        mcp_registrar(null, 'painel_turmas_csv', $usuario . ' · ' . ($naLista ? "$curso · $idioma" : 'fechadas'));
        echo mcp_turma_csv(mcp_turma_listar($filtro, 5000));
        exit;
    }
    $linhas = mcp_turma_listar($filtro, 200);
    $pilulas = '<div class="filtros"><a class="pilula' . (!$todos ? ' ativo' : '') . '" href="' . pn_e($url(['f' => null])) . '">Em aberto</a>'
        . '<a class="pilula' . ($todos ? ' ativo' : '') . '" href="' . pn_e($url(['f' => 'todos'])) . '">Todos, inclusive marcados e arquivados</a></div>';
    $tabela = '<div class="tabela rolagem"><table class="respostas"><thead><tr><th>Recebido</th><th>Quem</th><th>Turma</th><th>Situação</th></tr></thead><tbody>'
        . (($html = pn_turma_linhas($usuario, $linhas, $volta)) !== '' ? $html : '<tr><td colspan="4" class="vazio">Nenhum pedido aqui.</td></tr>') . '</tbody></table></div>';
    $baixar = $linhas ? '<a class="btn btn-outline" href="' . pn_e($url(['csv' => 1])) . '">Baixar planilha</a>' : '';
    $avisoHtml = $aviso !== '' ? '<div class="aviso ' . pn_e($classe) . '">' . pn_e($aviso) . '</div>' : '';

    if ($naLista) {
        $demanda = mcp_turma_demanda($curso, $idioma)[0] ?? null;
        $rotulo = mcp_turma_rotulo((string) ($cursos[$curso]['nome'] ?? $demanda['curso_nome'] ?? $linhas[0]['curso_nome'] ?? $curso), $idioma);
        $soma = (int) ($demanda['pessoas'] ?? 0);
        $pr = mcp_turma_progresso($soma);
        $deAviso = mcp_turma_lista_de_aviso($curso, $idioma);
        $corpo = '<a class="voltar" href="painel.php?v=turmas">← Voltar para as turmas sob demanda</a>' . $avisoHtml
            . '<div class="cabeca"><div><p class="eyebrow">' . ($deAviso ? 'Avisos da data' : 'Lista de interesse') . '</p><h1>' . pn_e($rotulo) . '</h1>'
            . ($deAviso
                ? '<p class="nota">' . pn_e(mcp_turma_pediram($pr['soma'])) . ' o aviso da data na página do curso (só e-mail, sem telefone). '
                    . 'Quando a data da próxima turma sair, avise todos por e-mail (planilha) e mude a situação abaixo.</p></div>'
                : '<p class="nota">' . pn_e(mcp_turma_alunos($pr['soma'])) . ' em pedidos abertos. '
                    . ($pr['pronta'] ? 'Já dá para abrir a turma: avise todos e, quando a turma estiver marcada, mude a situação abaixo.' : 'Faltam ' . $pr['faltam'] . ' para ' . MCP_TURMA_MINIMO . '.') . '</p>'
                    . '<div class="progresso" style="max-width:420px"><i style="width:' . $pr['pct'] . '%"></i></div></div>')
            . $baixar . '</div>'
            . $pilulas . $tabela
            . ($pr['soma'] > 0 ? '<div class="cartao" style="margin-top:18px"><h2>Mudar todos os pedidos em aberto desta lista</h2>'
                . '<p class="nota" style="margin:0">Use quando a turma abrir: "Turma marcada" tira os pedidos da lista. Cada pessoa continua sendo avisada por você, pela planilha.</p>'
                . pn_turma_form_status($usuario, 0, 'turma_marcada', ['curso' => $curso, 'idioma' => $idioma,
                    'ate' => (string) max(array_map('intval', array_column($linhas, 'id')) ?: [0])], 'Mudar todos') . '</div>' : '');
        pn_pagina($rotulo, $corpo, $usuario, true, 'turmas');
    }

    $listas = '';
    foreach (mcp_turma_demanda() as $l) {
        $pr = mcp_turma_progresso($l['pessoas']);
        $link = '<a href="' . pn_e('painel.php?' . http_build_query(['v' => 'turmas', 'curso' => $l['curso_slug'], 'idioma' => $l['idioma']])) . '">'
            . pn_e(mcp_turma_rotulo((string) $l['curso_nome'], (string) $l['idioma'])) . '</a>';
        if (mcp_turma_lista_de_aviso((string) $l['curso_slug'], (string) $l['idioma'])) {
            // Avisos da data (página do curso): esperam a data da turma regular, não 15 pessoas.
            $listas .= '<li><div>' . $link . '<small>' . pn_e(mcp_turma_pediram($l['pedidos'])) . ' o aviso da data · último em ' . pn_e(pn_data((string) $l['ultimo'])) . '</small></div>'
                . '<span class="selo neutro">Aviso da data</span></li>';
            continue;
        }
        $listas .= '<li><div>' . $link
            . '<div class="progresso"><i style="width:' . $pr['pct'] . '%"></i></div>'
            . '<small>' . pn_e(mcp_turma_alunos($pr['soma'])) . ' de ' . MCP_TURMA_MINIMO . ' · ' . $l['pedidos'] . ($l['pedidos'] === 1 ? ' pedido' : ' pedidos') . ' · último em ' . pn_e(pn_data((string) $l['ultimo'])) . '</small></div>'
            . ($pr['pronta'] ? '<span class="selo ok">Pronta para abrir</span>' : '<span class="selo neutro">Faltam ' . $pr['faltam'] . '</span>') . '</li>';
    }
    $corpo = $avisoHtml
        . '<div class="cabeca"><div><p class="eyebrow">Matrícula cursos presenciais</p><h1>Turmas sob demanda</h1>'
        . '<p class="nota">Turmas de ' . MCP_TURMA_MINIMO . ' a ' . MCP_TURMA_MAXIMO . ' alunos, no mesmo valor por pessoa dos cursos. Quem já tem o grupo tem prioridade; '
        . 'quem não tem entra na lista de interesse do curso e do idioma e é avisado quando a soma chegar a ' . MCP_TURMA_MINIMO . '. Aulas fora da sede precisam de aprovação.</p></div></div>'
        . '<h2 style="margin-top:22px">Pedidos de turma fechada</h2>'
        . '<div class="cabeca" style="align-items:center">' . $pilulas . $baixar . '</div>' . $tabela
        . '<div class="cartao" style="margin-top:22px"><h2>Listas de interesse em aberto</h2>'
        . ($listas !== '' ? '<ul class="lista-curta">' . $listas . '</ul>' : '<p class="vazio" style="padding:20px">Ninguém na lista por enquanto.</p>')
        . '<p class="nota">Cada lista é um curso num idioma. Em português, nos cursos do catálogo, a lista junta quem pediu o aviso da data na página do curso: '
        . 'avise todos por e-mail quando a data da turma sair. A soma de ' . MCP_TURMA_MINIMO . ' vale para as turmas em inglês e para o curso dos jovens.</p></div>';
    pn_pagina('Turmas sob demanda', $corpo, $usuario, true, 'turmas');
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

    // Teste da contribuição para a divulgação: adesão de cada valor oferecido, entre as inscrições pagas.
    $divulgacao = '';
    foreach (mcp_secretaria_divulgacao() as $d) {
        $pagas = (int) $d['pagas'];
        $contribuiram = (int) $d['contribuiram'];
        $divulgacao .= '<li><span>' . pn_e(mcp_brl((int) $d['oferta'])) . ', desde ' . pn_e(mcp_data_brt((string) $d['desde'], 'd/m/Y')) . '</span><b>'
            . $contribuiram . ' de ' . $pagas . ' (' . ($pagas > 0 ? (int) round(100 * $contribuiram / $pagas) : 0) . '%) · '
            . pn_e(mcp_brl((int) $d['arrecadado'])) . '</b></li>';
    }

    $naSede = count(mcp_ponto_na_sede());
    $termos = mcp_ponto_termos_pendentes_contar();
    $informadas = mcp_ponto_saidas_informadas_contar();
    $fila = mcp_avisos_fila_manual_contar();
    $proximo = mcp_db()->query("SELECT id, nome, agendada_para FROM mcp_campanhas WHERE status = 'agendada' ORDER BY agendada_para LIMIT 1")->fetch() ?: null;
    $corpo = '<div class="cabeca"><div><p class="eyebrow">Portal da secretaria · ' . pn_e(mcp_data_brt(mcp_agora(), 'd/m/Y')) . '</p><h1>Início</h1>'
        . '<p class="nota">O que pede ação agora e o que chegou por último. Clique num número para ver a lista.</p></div></div>'
        . $numeros
        . ($naSede > 0 ? '<p class="faltam"><a href="painel.php?v=ponto#na-sede">' . $naSede . ($naSede > 1 ? ' colaboradores estão' : ' colaborador está') . ' na sede agora →</a></p>' : '')
        . ($termos > 0 ? '<p class="faltam"><a href="painel.php?v=ponto#voluntarios">' . $termos . ($termos > 1 ? ' voluntários ainda não têm' : ' voluntário ainda não tem') . ' o termo de adesão registrado →</a></p>' : '')
        . ($informadas > 0 ? '<p class="faltam"><a href="painel.php?v=ponto#saidas-informadas">' . $informadas . ($informadas > 1 ? ' saídas informadas esperam' : ' saída informada espera') . ' a sua conferência →</a></p>' : '')
        . ($fila > 0 ? '<p class="faltam"><a href="painel.php?v=comunicacao&amp;aba=fila">' . $fila . ($fila > 1 ? ' mensagens esperam' : ' mensagem espera') . ' na fila do WhatsApp →</a></p>' : '')
        . ($proximo ? '<p class="faltam"><a href="painel.php?v=comunicado&amp;id=' . (int) $proximo['id'] . '">Próximo comunicado: ' . pn_e((string) $proximo['nome']) . ', em ' . pn_e(pn_data((string) $proximo['agendada_para'])) . ' →</a></p>' : '')
        . '<div class="grade-inicio"><div class="cartao"><h2>Últimas inscrições pagas</h2>'
        . ($ultimas !== '' ? '<ul class="lista-curta">' . $ultimas . '</ul>' : '<p class="nota">Nenhuma inscrição paga ainda.</p>')
        . '<p class="nota"><a href="painel.php?v=inscricoes">Ver todas as inscrições →</a></p></div>'
        . '<div class="cartao"><h2>Mensagens recentes</h2>'
        . ($mensagens !== '' ? '<ul class="lista-curta">' . $mensagens . '</ul>' : '<p class="nota">Nenhuma mensagem ainda.</p>')
        . '<p class="nota"><a href="painel.php?v=mensagens">Ver todas as mensagens →</a></p></div></div>'
        . '<div class="cartao" style="margin-top:18px"><h2>Horários mais pedidos, em todos os cursos</h2>'
        . ($horarios !== '' ? '<ul class="lista-curta">' . $horarios . '</ul>' : '<p class="nota">Ainda não há respostas ao questionário de horários.</p>')
        . '<p class="nota"><a href="painel.php?v=horarios">Ver o mapa por curso →</a></p></div>'
        . '<div class="cartao" style="margin-top:18px"><h2>Contribuição para a divulgação</h2>'
        . ($divulgacao !== '' ? '<ul class="lista-curta">' . $divulgacao . '</ul>' : '<p class="nota">Nenhuma inscrição paga desde que a opção entrou no checkout.</p>')
        . '<p class="nota">Inscrições pagas que viram a opção no checkout e quantas escolheram contribuir, por valor oferecido. Estornos não entram.</p></div>';
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
        'Valor' => pn_e(mcp_brl((int) $i['total_centavos'])) . ((int) $i['taxa_centavos'] > 0 || (int) $i['divulgacao_centavos'] > 0
            ? ' <span style="color:var(--muted);font-weight:400">(inscrição ' . pn_e(mcp_brl((int) $i['inscricao_centavos']))
                . ((int) $i['taxa_centavos'] > 0 ? ' + custos ' . pn_e(mcp_brl((int) $i['taxa_centavos'])) : '')
                . ((int) $i['divulgacao_centavos'] > 0 ? ' + divulgação ' . pn_e(mcp_brl((int) $i['divulgacao_centavos'])) : '') . ')</span>'
            : ''),
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
    $horarios .= $pago ? '<p class="nota"><a href="' . pn_e(mcp_url_pagina('parabens', (string) $i['token']) . '&painel=1') . '" target="_blank" rel="noopener">Ver a página da inscrição, como o aluno vê ↗</a></p>' : '';

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

// ----------------------------------------------------------------------------- ponto da sede
const PN_PONTO_ABAS = ['colaboradores' => 'Colaboradores', 'alunos' => 'Alunos nas aulas', 'aparelhos' => 'Aparelhos e QR code', 'emergencia' => 'Lista de emergência'];

function pn_ponto_abas(string $aba): string
{
    $html = '';
    foreach (PN_PONTO_ABAS as $chave => $rotulo) {
        $html .= '<a class="pilula' . ($chave === $aba ? ' ativo' : '') . '" href="painel.php?v=ponto' . ($chave !== 'colaboradores' ? '&amp;aba=' . $chave : '') . '">' . pn_e($rotulo) . '</a>';
    }
    return '<div class="filtros">' . $html . '</div>';
}

/** Mês da URL (?mes=AAAA-MM) ou o atual; nunca depois do atual. */
function pn_mes(): string
{
    $mes = mcp_texto($_GET['mes'] ?? '', 7);
    $atual = mcp_ponto_mes_atual();
    return mcp_ponto_mes_valido($mes) && $mes <= $atual ? $mes : $atual;
}

function pn_navegar_mes(string $base, string $mes): string
{
    $anterior = mcp_ponto_mes_vizinho($mes, -1);
    $proximo = mcp_ponto_mes_vizinho($mes, 1);
    return '<div class="navegar"><a href="' . pn_e("$base&mes=$anterior") . '" aria-label="Mês anterior">←</a><b>' . pn_e(ucfirst(mcp_ponto_mes_nome($mes))) . '</b>'
        . ($proximo <= mcp_ponto_mes_atual() ? '<a href="' . pn_e("$base&mes=$proximo") . '" aria-label="Próximo mês">→</a>' : '<span aria-hidden="true">→</span>') . '</div>';
}

function pn_ponto_selo(array $linha): string
{
    if ($linha['aberto']) {
        $selo = '<span class="selo ok">Na sede desde ' . pn_e(mcp_data_brt((string) $linha['aberto']['entrada'], 'H:i')) . '</span>';
    } elseif ((int) $linha['esquecidas'] > 0) {
        $n = (int) $linha['esquecidas'];
        $selo = '<span class="selo erro">' . $n . ($n > 1 ? ' saídas esquecidas' : ' saída esquecida') . '</span>';
    } elseif (!(int) $linha['ativo']) {
        $selo = '<span class="selo neutro">Inativo</span>';
    } else {
        $selo = '';
    }
    if (!empty($linha['termo_pendente'])) {
        $selo .= ($selo !== '' ? ' ' : '') . '<span class="selo alerta">Termo pendente</span>';
    }
    return $selo !== '' ? $selo : '<span style="color:var(--muted)">—</span>';
}

/**
 * Ponto da sede: horas doadas por voluntários e diretoria no mês (aba padrão) e presença dos outros
 * vínculos, que não soma horas; alunos nas aulas; aparelhos.
 */
function pn_ponto(string $usuario, string $aviso = '', string $classe = 'ok'): never
{
    $aba = mcp_texto($_GET['aba'] ?? '', 20);
    if ($aba === 'alunos') {
        pn_ponto_alunos($usuario, $aviso, $classe);
    }
    if ($aba === 'aparelhos') {
        pn_ponto_aparelhos($usuario, $aviso, $classe);
    }
    if ($aba === 'emergencia') {
        pn_ponto_emergencia($usuario);
    }
    $mes = pn_mes();
    [$de, $ate] = mcp_ponto_mes_dias($mes);
    if (isset($_GET['csv'])) {
        [$deUtc, $ateUtc] = mcp_ponto_periodo_utc($de, $ate);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ponto-da-sede-' . $mes . '.csv"');
        mcp_registrar(null, 'painel_ponto_csv', "$usuario · $mes");
        // Só as horas doadas: a presença dos outros vínculos não vira planilha de horas.
        echo mcp_ponto_csv(mcp_ponto_registros($deUtc, $ateUtc, null, true));
        exit;
    }
    $linhas = mcp_ponto_relatorio($de, $ate);
    $voluntarios = array_values(array_filter($linhas, static fn(array $l): bool => $l['voluntario']));
    $presencas = array_values(array_filter($linhas, static fn(array $l): bool => !$l['voluntario']));
    $naSede = mcp_ponto_na_sede();
    $esquecidas = mcp_ponto_esquecidas_contar();
    $termos = mcp_ponto_termos_pendentes_contar();
    $total = array_sum(array_column($voluntarios, 'minutos'));
    $ativos = count(array_filter($voluntarios, static fn(array $l): bool => (bool) (int) $l['ativo']));
    $numeros = '<div class="numeros">'
        . '<a class="numero" href="#na-sede"><b>' . count($naSede) . '</b><span>Na sede agora</span><small>todos os vínculos</small></a>'
        . '<div class="numero"><b>' . pn_e(mcp_ponto_horas_texto($total)) . '</b><span>Horas doadas</span><small>em ' . pn_e(mcp_ponto_mes_nome($mes)) . ' · '
        . $ativos . ($ativos === 1 ? ' voluntário ativo' : ' voluntários ativos') . '</small></div>'
        . '<a class="numero' . ($termos > 0 ? ' alerta' : '') . '" href="#voluntarios"><b>' . $termos . '</b><span>Termos pendentes</span><small>voluntários sem o termo de adesão registrado</small></a>'
        . '<div class="numero' . ($esquecidas > 0 ? ' alerta' : '') . '"><b>' . $esquecidas . '</b><span>Saídas esquecidas</span><small>entrada sem saída há mais de ' . MCP_PONTO_ESQUECIDA_HORAS . ' h: corrija na ficha</small></div>'
        . '</div>';
    $lista = '';
    foreach ($naSede as $n) {
        $lista .= '<li>' . pn_e((string) $n['nome']) . ' · desde ' . pn_e(mcp_data_brt((string) $n['entrada'], 'H:i')) . '</li>';
    }
    $ficha = static fn(array $l): string => 'painel.php?v=colaborador&amp;id=' . (int) $l['id'] . '&amp;mes=' . $mes;
    $abrir = static fn(array $l): string => '<td class="abrir"><a class="btn btn-outline" style="min-height:36px;padding:6px 14px" href="' . $ficha($l) . '">Abrir ficha</a></td></tr>';
    $nome = static fn(array $l): string => '<td class="aluno"><a class="nome" href="' . $ficha($l) . '">' . pn_e((string) $l['nome']) . '</a><small>'
        . pn_e(mcp_ponto_vinculo_nome($l)) . ($l['funcao'] ? ' · ' . pn_e((string) $l['funcao']) : '') . '</small></td>';
    $ultima = static fn(array $l): string => '<td data-rotulo="Última presença">' . ($l['ultima'] ? pn_e(pn_data((string) $l['ultima'])) : '<span style="color:var(--muted)">nunca registrou</span>') . '</td>';
    $tabela = '';
    foreach ($voluntarios as $l) {
        $tabela .= '<tr>' . $nome($l)
            . '<td data-rotulo="Dias no mês">' . (int) $l['dias'] . '</td>'
            . '<td data-rotulo="Horas no mês"><b>' . pn_e(mcp_ponto_horas_texto((int) $l['minutos'])) . '</b></td>'
            . $ultima($l) . '<td data-rotulo="Situação">' . pn_ponto_selo($l) . '</td>' . $abrir($l);
    }
    $tabelaPresenca = '';
    foreach ($presencas as $l) {
        $tabelaPresenca .= '<tr>' . $nome($l) . $ultima($l) . '<td data-rotulo="Situação">' . pn_ponto_selo($l) . '</td>' . $abrir($l);
    }
    $corpo = '<div class="cabeca"><div><p class="eyebrow">Ponto da sede</p><h1>Colaboradores na sede</h1>'
        . '<p class="nota">Voluntários e diretoria registram entrada e saída e somam as horas doadas à instituição, com o termo de adesão da Lei 9.608/1998. '
        . 'Empregados, terceirizados e outros registram só a presença na sede.</p></div>'
        . '<div class="acoes" style="margin-top:0"><a class="btn btn-red" href="painel.php?v=colaborador&amp;novo=1">Cadastrar colaborador</a>'
        . '<a class="btn btn-outline" href="painel.php?v=importar">Importar planilha</a>'
        . ($voluntarios ? '<a class="btn btn-outline" href="' . pn_e("painel.php?v=ponto&mes=$mes&csv=1") . '">Baixar planilha das horas do mês</a>' : '') . '</div></div>'
        . pn_ponto_abas('colaboradores')
        . ($aviso !== '' ? '<div class="aviso ' . pn_e($classe) . '">' . pn_e($aviso) . '</div>' : '')
        . pc_saidas_informadas($usuario)
        . pn_navegar_mes('painel.php?v=ponto', $mes)
        . $numeros
        . '<div class="cartao" id="na-sede" style="margin-bottom:18px"><h2>Na sede agora <small><a href="painel.php?v=ponto&amp;aba=emergencia">Lista de emergência para imprimir</a></small></h2>'
        . ($lista !== '' ? '<ul class="na-sede">' . $lista . '</ul>' : '<p class="nota" style="margin:0">Ninguém com entrada aberta agora.</p>') . '</div>'
        . '<h2 id="voluntarios" style="margin:24px 0 10px">Voluntários e diretoria <small>horas doadas</small></h2>'
        . '<div class="tabela rolagem"><table class="respostas"><thead><tr><th>Colaborador</th><th>Dias</th><th>Horas</th><th>Última presença</th><th>Situação</th><th class="abrir"></th></tr></thead><tbody>'
        . ($tabela !== '' ? $tabela : '<tr><td colspan="6" class="vazio">Nenhum voluntário cadastrado. Comece por “Cadastrar colaborador”.</td></tr>') . '</tbody></table></div>'
        . ($tabelaPresenca !== ''
            ? '<h2 id="presenca" style="margin:28px 0 6px">Empregados, terceirizados e outros <small>só presença</small></h2>'
                . '<p class="nota" style="margin:0 0 10px">Presença na sede, por segurança: não soma horas, não tem declaração e não é o ponto oficial dos empregados. '
                . 'Os registros são apagados depois de ' . MCP_PONTO_PRESENCA_DIAS . ' dias.</p>'
                . '<div class="tabela rolagem"><table class="respostas"><thead><tr><th>Colaborador</th><th>Última presença</th><th>Situação</th><th class="abrir"></th></tr></thead><tbody>'
                . $tabelaPresenca . '</tbody></table></div>'
            : '');
    pn_pagina('Ponto da sede', $corpo, $usuario, true, 'ponto');
}

/** Presença dos alunos nas aulas, dia a dia, com o comprovante de cada um. */
function pn_ponto_alunos(string $usuario, string $aviso, string $classe): never
{
    $hoje = mcp_ponto_hoje();
    $data = mcp_texto($_GET['data'] ?? '', 10);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $data, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || $data > $hoje) {
        $data = $hoje;
    }
    $anterior = (new DateTimeImmutable($data))->modify('-1 day')->format('Y-m-d');
    $proximo = (new DateTimeImmutable($data))->modify('+1 day')->format('Y-m-d');
    $navegar = '<div class="navegar"><a href="painel.php?v=ponto&amp;aba=alunos&amp;data=' . $anterior . '" aria-label="Dia anterior">←</a><b>'
        . pn_e(mcp_escola_data($data) . ($data === $hoje ? ' (hoje)' : '')) . '</b>'
        . ($proximo <= $hoje ? '<a href="painel.php?v=ponto&amp;aba=alunos&amp;data=' . $proximo . '" aria-label="Próximo dia">→</a>' : '<span aria-hidden="true">→</span>') . '</div>';
    $tabela = '';
    foreach (mcp_presencas_listar($data, $data) as $p) {
        $id = (int) $p['id'];
        if ($p['status'] !== 'valida') {
            $selo = '<span class="selo erro">Cancelada</span><small>por ' . pn_e((string) $p['cancelada_por']) . '</small>';
        } elseif (!mcp_presenca_disponivel($p)) {
            $selo = '<span class="selo neutro">Sai às ' . pn_e(mcp_data_brt((string) $p['fim'], 'H:i')) . '</span>';
        } elseif ($p['email_em'] !== null) {
            $selo = '<span class="selo ok">Enviado às ' . pn_e(mcp_data_brt((string) $p['email_em'], 'H:i')) . '</span>';
        } elseif ($p['email'] === null) {
            $selo = '<span class="selo neutro">Sem e-mail</span><small>o aluno baixa pelo link</small>';
        } elseif ($p['email_status'] === 'falhou') {
            $selo = '<span class="selo erro">Envio falhou</span><small>' . (int) $p['email_tentativas'] . ' tentativa(s)</small>';
        } else {
            $selo = '<span class="selo alerta">Pronto</span><small>o e-mail sai na próxima rodada</small>';
        }
        $cancelar = $p['status'] === 'valida'
            ? '<form method="post" action="painel.php" onsubmit="return confirm(\'Cancelar esta presença? O comprovante deixa de valer.\')"><input type="hidden" name="acao" value="presenca_cancelar">'
              . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="t" value="' . pn_e(mcp_painel_csrf($usuario, 'presenca_cancelar', $id)) . '">'
              . '<button class="btn btn-outline" type="submit" style="min-height:34px;padding:5px 12px;font-size:.85rem">Cancelar</button></form>'
            : '';
        $tabela .= '<tr><td data-rotulo="Chegada">' . pn_e(mcp_data_brt((string) $p['chegada'], 'H:i')) . '<small>' . pn_e(MCP_PONTO_ORIGENS[$p['origem']] ?? (string) $p['origem']) . '</small></td>'
            . '<td class="aluno"><b>' . pn_e(mcp_nome_proprio((string) $p['nome'])) . '</b>' . ($p['email'] ? '<small>' . pn_e((string) $p['email']) . '</small>' : '') . '</td>'
            . '<td data-rotulo="Curso">' . pn_e((string) $p['curso_nome']) . '</td>'
            . '<td data-rotulo="Aula">' . pn_e(mcp_presenca_horario_texto($p)) . '</td>'
            . '<td data-rotulo="Comprovante">' . $selo . '</td>'
            . '<td data-rotulo="Ações"><div class="acoes" style="margin:0;gap:6px"><a class="btn btn-outline" style="min-height:34px;padding:5px 12px;font-size:.85rem" href="' . pn_e(mcp_presenca_link($p)) . '" target="_blank" rel="noopener">Ver</a>' . $cancelar . '</div></td></tr>';
    }
    $corpo = '<div class="cabeca"><div><p class="eyebrow">Ponto da sede</p><h1>Presença dos alunos</h1>'
        . '<p class="nota">O aluno confirma a presença no ponto com o CPF, e a aula vem da plataforma da escola. Quando a aula termina, o comprovante de comparecimento fica disponível e vai por e-mail. Cancele uma presença registrada por engano: o comprovante deixa de valer.</p></div></div>'
        . pn_ponto_abas('alunos')
        . ($aviso !== '' ? '<div class="aviso ' . pn_e($classe) . '">' . pn_e($aviso) . '</div>' : '')
        . $navegar
        . '<div class="tabela rolagem"><table class="respostas"><thead><tr><th>Chegada</th><th>Aluno</th><th>Curso</th><th>Aula</th><th>Comprovante</th><th></th></tr></thead><tbody>'
        . ($tabela !== '' ? $tabela : '<tr><td colspan="6" class="vazio">Nenhuma presença registrada neste dia.</td></tr>') . '</tbody></table></div>';
    pn_pagina('Presença dos alunos', $corpo, $usuario, true, 'ponto');
}

/** Aparelhos da recepção liberados como ponto e o cartaz do QR code para o celular. */
/** Lista de emergência: quem está na sede agora (colaboradores e alunos em aula), pronta para imprimir. */
function pn_ponto_emergencia(string $usuario): never
{
    $agora = time();
    $lista = mcp_ponto_emergencia($agora);
    mcp_registrar(null, 'painel_emergencia', "$usuario · " . count($lista['colaboradores']) . ' colaboradores · ' . count($lista['alunos']) . ' alunos');
    $linhas = '';
    foreach ($lista['colaboradores'] as $c) {
        $linhas .= '<tr><td class="aluno"><b>' . pn_e($c['nome']) . '</b><small>' . pn_e($c['vinculo']) . ($c['funcao'] !== '' ? ' · ' . pn_e($c['funcao']) : '') . '</small></td>'
            . '<td data-rotulo="Desde">' . pn_e(mcp_data_brt($c['desde'], 'd/m H:i'))
            . ($c['antiga'] ? '<small>entrada há mais de 12 h: provável saída não registrada</small>' : '') . '</td><td class="conferido" aria-label="Conferido"></td></tr>';
    }
    foreach ($lista['alunos'] as $a) {
        $linhas .= '<tr><td class="aluno"><b>' . pn_e(mcp_nome_proprio($a['nome'])) . '</b><small>Aluno · ' . pn_e($a['curso']) . ', ' . pn_e($a['horario']) . '</small></td>'
            . '<td data-rotulo="Chegada">' . pn_e(mcp_data_brt($a['chegada'], 'd/m H:i')) . '</td><td class="conferido" aria-label="Conferido"></td></tr>';
    }
    $total = count($lista['colaboradores']) + count($lista['alunos']);
    $corpo = '<div class="cabeca"><div><p class="eyebrow">Ponto da sede</p><h1>Lista de emergência</h1>'
        . '<p class="nota">Quem registrou a chegada e ainda não registrou a saída, e os alunos com presença numa aula que ainda não terminou. '
        . 'Gerada em ' . pn_e(mcp_data_brt(gmdate('Y-m-d H:i:s', $agora), 'd/m/Y \à\s H:i')) . '. Numa evacuação, imprima ou leve no celular e confira os nomes no ponto de encontro. '
        . 'Visitantes e quem não registrou não aparecem.</p></div>'
        . '<div class="acoes" style="margin-top:0"><button class="btn btn-red" type="button" onclick="window.print()">Imprimir</button>'
        . '<a class="btn btn-outline" href="painel.php?v=ponto&amp;aba=emergencia">Atualizar</a></div></div>'
        . pn_ponto_abas('emergencia')
        . '<div class="numeros"><div class="numero"><b>' . $total . '</b><span>Na sede agora</span><small>'
        . count($lista['colaboradores']) . (count($lista['colaboradores']) === 1 ? ' colaborador · ' : ' colaboradores · ')
        . count($lista['alunos']) . (count($lista['alunos']) === 1 ? ' aluno' : ' alunos') . '</small></div></div>'
        . '<div class="tabela rolagem"><table class="respostas emergencia"><thead><tr><th>Pessoa</th><th>Desde</th><th>Conferido</th></tr></thead><tbody>'
        . ($linhas !== '' ? $linhas : '<tr><td colspan="3" class="vazio">Ninguém com entrada aberta nem aluno em aula agora.</td></tr>') . '</tbody></table></div>';
    pn_pagina('Lista de emergência', $corpo, $usuario, true, 'ponto');
}

function pn_ponto_aparelhos(string $usuario, string $aviso, string $classe): never
{
    $atual = mcp_ponto_aparelho_atual();
    $linhas = '';
    foreach (mcp_ponto_aparelhos_listar() as $a) {
        $id = (int) $a['id'];
        $linhas .= '<tr><td class="aluno"><b>' . pn_e((string) $a['nome']) . '</b>' . ($atual && (int) $atual['id'] === $id ? '<small>este navegador</small>' : '') . '</td>'
            . '<td data-rotulo="Liberado">' . pn_e(pn_data((string) $a['criado_em'])) . '<small>' . pn_e((string) $a['criado_por']) . '</small></td>'
            . '<td data-rotulo="Último uso">' . ($a['usado_em'] ? pn_e(pn_data((string) $a['usado_em'])) : '<span style="color:var(--muted)">—</span>') . '</td>'
            . '<td data-rotulo="Situação">' . ((int) $a['ativo'] ? '<span class="selo ok">Ativo</span>' : '<span class="selo neutro">Desativado</span>') . '</td>'
            . '<td>' . ((int) $a['ativo']
                ? '<form method="post" action="painel.php" onsubmit="return confirm(\'Desativar este aparelho? Ele deixa de registrar o ponto.\')"><input type="hidden" name="acao" value="aparelho_desativar"><input type="hidden" name="id" value="' . $id . '">'
                  . '<input type="hidden" name="t" value="' . pn_e(mcp_painel_csrf($usuario, 'aparelho_desativar', $id)) . '"><button class="btn btn-outline" type="submit" style="min-height:34px;padding:5px 12px;font-size:.85rem">Desativar</button></form>'
                : '') . '</td></tr>';
    }
    $este = $atual
        ? '<p><span class="selo ok">Este navegador é o ponto da sede</span></p><p class="nota">Aparelho: <b>' . pn_e((string) $atual['nome']) . '</b>. Deixe a página do ponto aberta nele.</p>'
          . '<div class="acoes"><a class="btn btn-red" href="../ponto/">Abrir o ponto</a></div>'
        : '<p class="nota" style="margin-top:0">Faça isto <b>no tablet ou computador que vai ficar na recepção</b>, com o portal aberto nele. O aparelho fica liberado por ' . MCP_PONTO_APARELHO_DIAS . ' dias; se ele sair da sede, desative na lista abaixo.</p>'
          . '<form method="post" action="painel.php"><input type="hidden" name="acao" value="aparelho_ativar"><input type="hidden" name="id" value="0">'
          . '<input type="hidden" name="t" value="' . pn_e(mcp_painel_csrf($usuario, 'aparelho_ativar', 0)) . '">'
          . '<label for="nome-aparelho">Nome do aparelho</label><input id="nome-aparelho" name="nome" required maxlength="80" placeholder="Ex.: Tablet da recepção">'
          . '<div class="acoes"><button class="btn btn-red" type="submit">Liberar este aparelho como ponto</button></div></form>';
    $raio = mcp_ponto_sede()['raio'];
    $corpo = '<div class="cabeca"><div><p class="eyebrow">Ponto da sede</p><h1>Aparelhos e QR code</h1>'
        . '<p class="nota">Dois jeitos de registrar: no aparelho da recepção liberado aqui, ou pelo celular da pessoa, lendo o QR code do cartaz. Pelo celular, o registro só vale a até ' . $raio . ' m da sede.</p></div></div>'
        . pn_ponto_abas('aparelhos')
        . ($aviso !== '' ? '<div class="aviso ' . pn_e($classe) . '">' . pn_e($aviso) . '</div>' : '')
        . '<div class="ficha" style="margin-top:0"><div class="cartao"><h2>Este aparelho</h2>' . $este . '</div>'
        . '<div class="cartao"><h2>QR code para o celular</h2><p class="nota" style="margin-top:0">Imprima o cartaz em A4 e cole perto da entrada. O QR code abre <b>cruzvermelhariodejaneiro.org/ponto</b>.</p>'
        . '<div class="acoes"><a class="btn btn-outline" href="../ponto/cartaz/" target="_blank" rel="noopener">Abrir o cartaz para imprimir</a></div></div>'
        . pn_ponto_codigo_cartao($usuario) . '</div>'
        . '<div class="tabela rolagem" style="margin-top:18px"><table class="respostas"><thead><tr><th>Aparelho</th><th>Liberado</th><th>Último uso</th><th>Situação</th><th></th></tr></thead><tbody>'
        . ($linhas !== '' ? $linhas : '<tr><td colspan="5" class="vazio">Nenhum aparelho liberado ainda.</td></tr>') . '</tbody></table></div>';
    pn_pagina('Aparelhos do ponto', $corpo, $usuario, true, 'ponto');
}

/**
 * Código do dia para o celular: com ele ligado, o celular só registra com os 4 números que o tablet da
 * recepção mostra (e que mudam à meia-noite). Sem ir à sede, o CPF e uma localização inventada não bastam.
 */
function pn_ponto_codigo_cartao(string $usuario): string
{
    $ligado = mcp_ponto_codigo_ligado();
    return '<div class="cartao"><h2>Código do dia no celular</h2>'
        . '<p class="nota" style="margin-top:0">' . ($ligado
            ? '<span class="selo ok">Ligado</span> O celular pede o código que aparece na tela do tablet da recepção. Hoje: <b style="font-size:1.2rem;letter-spacing:.15em">' . pn_e(mcp_ponto_codigo_do_dia()) . '</b> (muda à meia-noite).'
            : '<span class="selo neutro">Desligado</span> Pelo celular, bastam o CPF e a localização, que o próprio celular informa: quem sabe o CPF de outra pessoa e finge estar perto registra por ela. Ligado, o celular pede também os 4 números que aparecem na tela do tablet da recepção.') . '</p>'
        . '<form method="post" action="painel.php"><input type="hidden" name="acao" value="ponto_codigo"><input type="hidden" name="id" value="0">'
        . '<input type="hidden" name="t" value="' . pn_e(mcp_painel_csrf($usuario, 'ponto_codigo', 0)) . '"><input type="hidden" name="ligar" value="' . ($ligado ? '0' : '1') . '">'
        . '<div class="acoes"><button class="btn ' . ($ligado ? 'btn-outline' : 'btn-red') . '" type="submit">' . ($ligado ? 'Desligar o código do dia' : 'Ligar o código do dia') . '</button></div></form></div>';
}

function pn_campo(string $nome, string $rotulo, string $valor, string $campoErro, string $extra = ''): string
{
    return '<div' . ($campoErro === $nome ? ' class="campo-erro"' : '') . '><label for="c-' . $nome . '">' . pn_e($rotulo) . '</label><input id="c-' . $nome . '" name="' . $nome . '" value="' . pn_e($valor) . '"' . $extra . '></div>';
}

/**
 * Ficha do colaborador ($id null = cadastro novo): dados com o vínculo; para voluntários e diretoria, o
 * termo de adesão, as horas do mês com correções e a declaração; para os outros vínculos, só a presença.
 */
function pn_colaborador(string $usuario, ?int $id, string $aviso = '', string $classe = 'ok', ?array $form = null, string $campoErro = ''): never
{
    $c = $id !== null ? mcp_colaborador_por_id($id) : null;
    if ($id !== null && !$c) {
        pn_redirecionar('v=ponto');
    }
    $mes = pn_mes();
    [$de, $ate] = mcp_ponto_mes_dias($mes);
    if ($c && isset($_GET['termo'])) {
        unset($_GET['termo']);
        if (!mcp_ponto_voluntario($c)) {
            pn_colaborador($usuario, $id, 'O termo de adesão é só para voluntários e diretoria.', 'erro');
        }
        mcp_registrar(null, 'painel_termo_pdf', '#' . $c['id'] . " · $usuario");
        $pdf = mcp_termo_pdf(mcp_ponto_termo_conteudo($c));
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . mcp_ponto_termo_arquivo($c) . '"');
        echo $pdf;
        exit;
    }
    if ($c && isset($_GET['declaracao'])) {
        unset($_GET['declaracao']);
        // Sem o termo de adesão registrado, a instituição não declara serviço voluntário.
        if (empty($c['termo_em'])) {
            pn_colaborador($usuario, $id, mcp_ponto_voluntario($c)
                ? 'Registre o termo de adesão assinado antes de emitir a declaração de horas.'
                : 'A declaração de horas é só para voluntários e diretoria.', 'erro');
        }
        // Mês fechado: o mês inteiro; mês atual: até hoje.
        $fim = min((new DateTimeImmutable($ate))->modify('-1 day')->format('Y-m-d'), mcp_ponto_hoje());
        $d = mcp_ponto_declaracao_emitir($c, $de, $fim, $usuario);
        mcp_registrar(null, 'painel_declaracao', '#' . $c['id'] . " · $usuario · $de a $fim · " . $d['codigo']);
        $pdf = mcp_declaracao_pdf(mcp_ponto_declaracao_conteudo($d));
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . mcp_ponto_declaracao_arquivo($d) . '"');
        echo $pdf;
        exit;
    }
    $v = $form ?? ($c ?? ['nome' => '', 'cpf' => '', 'email' => '', 'telefone' => '', 'funcao' => '', 'vinculo' => '', 'ativo' => 1]);
    $idForm = $c ? (int) $c['id'] : 0;
    $opcoes = '<option value="">Escolha…</option>';
    foreach (MCP_PONTO_VINCULOS as $chave => $rotulo) {
        $opcoes .= '<option value="' . $chave . '"' . (($v['vinculo'] ?? '') === $chave ? ' selected' : '') . '>' . pn_e($rotulo) . '</option>';
    }
    $dados = '<form method="post" action="painel.php"><input type="hidden" name="acao" value="colaborador_salvar"><input type="hidden" name="id" value="' . $idForm . '">'
        . '<input type="hidden" name="t" value="' . pn_e(mcp_painel_csrf($usuario, 'colaborador_salvar', $idForm)) . '">'
        . pn_campo('nome', 'Nome completo', (string) ($v['nome'] ?? ''), $campoErro, ' required maxlength="160" autocomplete="off"')
        . '<div class="form-grade">'
        . pn_campo('cpf', 'CPF (é com ele que a pessoa registra o ponto)', $form !== null ? (string) ($v['cpf'] ?? '') : mcp_cpf_formatado((string) ($v['cpf'] ?? '')), $campoErro, ' required inputmode="numeric" maxlength="14" autocomplete="off"')
        . '<div' . ($campoErro === 'vinculo' ? ' class="campo-erro"' : '') . '><label for="c-vinculo">Vínculo com a instituição</label><select class="campo" id="c-vinculo" name="vinculo" required>' . $opcoes . '</select></div>'
        . pn_campo('funcao', 'Função (opcional)', (string) ($v['funcao'] ?? ''), $campoErro, ' maxlength="120" placeholder="Ex.: Socorrista voluntário, Presidente"')
        . pn_campo('email', 'E-mail (opcional)', (string) ($v['email'] ?? ''), $campoErro, ' type="email" maxlength="190"')
        . pn_campo('telefone', 'Telefone (opcional)', $form !== null ? (string) ($v['telefone'] ?? '') : (!empty($v['telefone']) ? mcp_telefone_bonito((string) $v['telefone']) : ''), $campoErro, ' inputmode="tel" maxlength="20"')
        . '</div><p class="nota">Voluntários e diretoria somam horas doadas e precisam do termo de adesão assinado. Empregados, terceirizados e outros registram só a presença na sede: '
        . 'não soma horas, não tem declaração e os registros são apagados depois de ' . MCP_PONTO_PRESENCA_DIAS . ' dias. O ponto não substitui o ponto oficial dos empregados.</p>'
        . '<label class="check" style="margin-top:16px"><input type="checkbox" name="ativo" value="1"' . (!empty($v['ativo']) ? ' checked' : '') . '> Ativo (pode registrar o ponto)</label>'
        . '<div class="acoes"><button class="btn btn-red" type="submit">' . ($c ? 'Salvar alterações' : 'Cadastrar') . '</button></div></form>';
    $topo = '<a class="voltar" href="painel.php?v=ponto&amp;mes=' . $mes . '">← Voltar para o ponto da sede</a>'
        . ($aviso !== '' ? '<div class="aviso ' . pn_e($classe) . '">' . pn_e($aviso) . '</div>' : '');
    if (!$c) {
        $corpo = $topo . '<div class="cabeca"><div><p class="eyebrow">Ponto da sede</p><h1>Cadastrar colaborador</h1>'
            . '<p class="nota">Depois do cadastro, a pessoa já registra entrada e saída no ponto com o CPF. Para voluntários e diretoria, imprima em seguida o termo de adesão.</p></div></div>'
            . '<div class="cartao" style="margin-top:18px;max-width:720px">' . $dados . '</div>';
        pn_pagina('Cadastrar colaborador', $corpo, $usuario, true, 'ponto');
    }

    $voluntario = mcp_ponto_voluntario($c);
    [$deUtc, $ateUtc] = mcp_ponto_periodo_utc($de, $ate);
    $registros = mcp_ponto_registros($deUtc, $ateUtc, (int) $c['id']);
    $agora = time();
    $segundos = 0;
    $dias = [];
    $tabela = '';
    foreach ($registros as $r) {
        $rid = (int) $r['id'];
        $doado = (int) $r['voluntario'] === 1;
        if ($doado && $r['saida'] !== null) {
            $segundos += mcp_ponto_segundos($r);
            $dias[mcp_data_brt((string) $r['entrada'], 'Y-m-d')] = true;
        }
        $saida = $r['saida'] !== null
            ? pn_e(mcp_data_brt((string) $r['saida'], mcp_data_brt((string) $r['saida'], 'Y-m-d') !== mcp_data_brt((string) $r['entrada'], 'Y-m-d') ? 'd/m H:i' : 'H:i'))
            : ($r['saida_informada'] !== null ? '<a class="selo alerta" href="painel.php?v=ponto#saidas-informadas">Informada: ' . pn_e(mcp_data_brt((string) $r['saida_informada'], 'H:i')) . ' · a conferir</a>'
            : (!mcp_ponto_esquecido($r, $agora) ? '<span class="selo ok">Na sede</span>'
                : ($doado ? '<span class="selo erro">Saída esquecida</span>' : '<span style="color:var(--muted)">não registrada</span>')));
        $origem = pn_e(MCP_PONTO_ORIGENS[$r['origem_entrada']] ?? (string) $r['origem_entrada']) . ($r['origem_saida'] !== null && $r['origem_saida'] !== $r['origem_entrada']
            ? ' · ' . pn_e(MCP_PONTO_ORIGENS[$r['origem_saida']] ?? (string) $r['origem_saida']) : '');
        $dia = '<td data-rotulo="Dia">' . pn_e(mcp_data_brt((string) $r['entrada'], 'd/m/Y')) . '<small class="dia">' . pn_e(mcp_dia_semana(mcp_data_brt((string) $r['entrada'], 'Y-m-d'))) . '</small></td>';
        if (!$voluntario) {
            $tabela .= '<tr>' . $dia . '<td data-rotulo="Chegada">' . pn_e(mcp_data_brt((string) $r['entrada'], 'H:i')) . '</td>'
                . '<td data-rotulo="Saída">' . $saida . '</td><td data-rotulo="Registro">' . $origem . '</td></tr>';
            continue;
        }
        $ajustar = !$doado ? '<small style="color:var(--muted)">Só presença (vínculo da época)</small>' : '<details class="ajustar"><summary>Corrigir</summary>'
            . ($r['ajuste'] ? '<p class="nota historico">' . nl2br(pn_e((string) $r['ajuste']), false) . '</p>' : '')
            . '<form method="post" action="painel.php" class="mini"><input type="hidden" name="acao" value="ponto_corrigir"><input type="hidden" name="id" value="' . $rid . '">'
            . '<input type="hidden" name="t" value="' . pn_e(mcp_painel_csrf($usuario, 'ponto_corrigir', $rid)) . '">'
            . '<label>Entrada<input type="datetime-local" name="entrada" required value="' . pn_e(mcp_data_brt((string) $r['entrada'], 'Y-m-d\TH:i')) . '"></label>'
            . '<label>Saída<input type="datetime-local" name="saida" value="' . ($r['saida'] !== null ? pn_e(mcp_data_brt((string) $r['saida'], 'Y-m-d\TH:i')) : '') . '"></label>'
            . '<label>Motivo<input name="motivo" required maxlength="200" placeholder="Ex.: esqueceu de registrar a saída"></label>'
            . '<button class="btn btn-outline" type="submit">Salvar correção</button></form>'
            . '<form method="post" action="painel.php" class="mini" onsubmit="return confirm(\'Apagar este registro de ponto?\')"><input type="hidden" name="acao" value="ponto_apagar"><input type="hidden" name="id" value="' . $rid . '">'
            . '<input type="hidden" name="t" value="' . pn_e(mcp_painel_csrf($usuario, 'ponto_apagar', $rid)) . '">'
            . '<label>Motivo para apagar<input name="motivo" required maxlength="200" placeholder="Ex.: registro duplicado"></label>'
            . '<button class="btn btn-outline" type="submit">Apagar registro</button></form></details>';
        $tabela .= '<tr>' . $dia
            . '<td data-rotulo="Entrada">' . pn_e(mcp_data_brt((string) $r['entrada'], 'H:i')) . '</td>'
            . '<td data-rotulo="Saída">' . $saida . '</td>'
            . '<td data-rotulo="Horas"><b>' . ($doado && $r['saida'] !== null ? pn_e(mcp_ponto_horas_texto(intdiv(mcp_ponto_segundos($r), 60))) : '—') . '</b></td>'
            . '<td data-rotulo="Registro">' . $origem . ($r['ajuste'] ? '<small>Ajustado no portal</small>' : '') . '</td>'
            . '<td class="recado">' . $ajustar . '</td></tr>';
    }
    $minutos = intdiv($segundos, 60);
    $aberto = mcp_ponto_aberto((int) $c['id'], $agora);
    $selos = ($aberto ? '<span class="selo ok">Na sede desde ' . pn_e(mcp_data_brt((string) $aberto['entrada'], 'H:i')) . '</span> ' : '')
        . (!(int) $c['ativo'] ? '<span class="selo neutro">Inativo' . (!empty($c['desligado_em']) ? ' desde ' . pn_e(mcp_escola_data((string) $c['desligado_em'])) : '') . ': não registra o ponto</span> ' : '')
        . 'Cadastrado em ' . pn_e(pn_data((string) $c['criado_em']));

    // Termo de adesão: só para voluntários e diretoria (ou quem já o assinou antes de mudar de vínculo).
    $termo = '';
    if ($voluntario || !empty($c['termo_em'])) {
        $imprimir = $voluntario ? '<div class="acoes" style="margin-top:0"><a class="btn btn-outline" href="' . pn_e('painel.php?v=colaborador&id=' . $c['id'] . '&termo=1') . '">'
            . (empty($c['termo_em']) ? 'Imprimir o termo para assinar (PDF)' : 'Imprimir o termo de novo (PDF)') . '</a></div>' : '';
        $registrar = static fn(string $botao, string $valor): string => '<form method="post" action="painel.php" class="mini"><input type="hidden" name="acao" value="termo_registrar"><input type="hidden" name="id" value="' . (int) $c['id'] . '">'
            . '<input type="hidden" name="t" value="' . pn_e(mcp_painel_csrf($usuario, 'termo_registrar', (int) $c['id'])) . '">'
            . '<label>Data em que o termo foi assinado<input type="date" name="data" required max="' . mcp_ponto_hoje() . '" value="' . pn_e($valor) . '"></label>'
            . '<button class="btn btn-red" type="submit">' . pn_e($botao) . '</button></form>';
        if (empty($c['termo_em'])) {
            $termo = '<div class="cartao termo pendente" id="termo" style="margin-top:18px"><h2>Termo de adesão <small>Lei 9.608/1998</small></h2>'
                . '<p style="margin:0 0 10px"><span class="selo alerta">Pendente</span></p>'
                . '<p class="nota">A lei pede o termo de adesão assinado para o serviço voluntário. Imprima o termo, colha as assinaturas do voluntário e de quem representa a '
                . 'instituição (e do responsável legal, se o voluntário tiver menos de 18 anos), guarde a via assinada e registre aqui a data. Sem ele, a declaração de horas fica bloqueada.</p>'
                . $imprimir . ($voluntario ? $registrar('Registrar termo assinado', mcp_ponto_hoje()) : '') . '</div>';
        } else {
            $termo = '<div class="cartao termo ok" id="termo" style="margin-top:18px"><h2>Termo de adesão <small>Lei 9.608/1998</small></h2>'
                . '<p style="margin:0 0 10px"><span class="selo ok">Assinado em ' . pn_e(mcp_escola_data((string) $c['termo_em'])) . '</span></p>'
                . '<p class="nota">Modelo ' . pn_e((string) $c['termo_modelo']) . ' · registrado por ' . pn_e((string) $c['termo_registrado_por']) . ' em '
                . pn_e(pn_data((string) $c['termo_registrado_em'])) . '. A via assinada fica guardada na secretaria.</p>'
                . $imprimir
                . '<details class="ajustar"><summary>Corrigir a data ou remover o registro</summary>' . ($voluntario ? $registrar('Salvar a data', (string) $c['termo_em']) : '')
                . '<form method="post" action="painel.php" class="mini" onsubmit="return confirm(\'Remover o registro do termo? A declaração de horas fica bloqueada até registrar de novo.\')">'
                . '<input type="hidden" name="acao" value="termo_remover"><input type="hidden" name="id" value="' . (int) $c['id'] . '">'
                . '<input type="hidden" name="t" value="' . pn_e(mcp_painel_csrf($usuario, 'termo_remover', (int) $c['id'])) . '">'
                . '<button class="btn btn-outline" type="submit">Remover o registro do termo</button></form></details></div>';
        }
    }

    if (!$voluntario) {
        $corpo = $topo . '<div class="cabeca"><div><p class="eyebrow">' . pn_e(mcp_ponto_vinculo_nome($c)) . ($c['funcao'] ? ' · ' . pn_e((string) $c['funcao']) : '') . '</p><h1>' . pn_e((string) $c['nome']) . '</h1>'
            . '<p class="nota">' . $selos . '</p></div></div>'
            . '<div class="cartao" style="margin-top:18px"><h2>Presença na sede em ' . pn_e(mcp_ponto_mes_nome($mes)) . '</h2>'
            . pn_navegar_mes('painel.php?v=colaborador&id=' . $c['id'], $mes)
            . '<p class="nota" style="margin:0 0 12px">Registro de presença na sede, por segurança. Não soma horas e não é o ponto oficial nem controle de jornada. '
            . 'Os registros são apagados depois de ' . MCP_PONTO_PRESENCA_DIAS . ' dias.</p>'
            . '<div class="tabela rolagem"><table class="respostas"><thead><tr><th>Dia</th><th>Chegada</th><th>Saída</th><th>Registro</th></tr></thead><tbody>'
            . ($tabela !== '' ? $tabela : '<tr><td colspan="4" class="vazio">Nenhuma presença neste mês.</td></tr>') . '</tbody></table></div></div>'
            . $termo
            . pc_ficha_lembretes($usuario, $c)
            . '<div class="cartao" style="margin-top:18px;max-width:720px"><h2>Dados do colaborador</h2>' . $dados . '</div>';
        pn_pagina((string) $c['nome'], $corpo, $usuario, true, 'ponto');
    }

    $hoje = mcp_ponto_hoje();
    $lancar = '<details class="ajustar"><summary>Lançar horas à mão</summary>'
        . '<form method="post" action="painel.php" class="mini"><input type="hidden" name="acao" value="ponto_lancar"><input type="hidden" name="id" value="' . (int) $c['id'] . '">'
        . '<input type="hidden" name="t" value="' . pn_e(mcp_painel_csrf($usuario, 'ponto_lancar', (int) $c['id'])) . '">'
        . '<label>Dia<input type="date" name="data" required max="' . $hoje . '"></label>'
        . '<label>Entrada<input type="time" name="entrada" required></label><label>Saída<input type="time" name="saida" required></label>'
        . '<label>Motivo<input name="motivo" required maxlength="200" placeholder="Ex.: plantão no evento da praça, sem o ponto"></label>'
        . '<button class="btn btn-outline" type="submit">Lançar</button></form></details>';
    $declaracao = !empty($c['termo_em'])
        ? '<div class="acoes" style="margin-top:0"><a class="btn btn-red" href="' . pn_e('painel.php?v=colaborador&id=' . $c['id'] . "&mes=$mes&declaracao=1") . '">Declaração de horas (PDF)</a></div>'
        : '<div class="acoes" style="margin-top:0"><a class="btn btn-outline" href="#termo">Declaração: registre o termo antes</a></div>';
    $corpo = $topo . '<div class="cabeca"><div><p class="eyebrow">' . pn_e(mcp_ponto_vinculo_nome($c)) . ($c['funcao'] ? ' · ' . pn_e((string) $c['funcao']) : '') . '</p><h1>' . pn_e((string) $c['nome']) . '</h1>'
        . '<p class="nota">' . $selos . '</p></div>'
        . $declaracao . '</div>'
        . $termo
        . '<div class="cartao" style="margin-top:18px"><h2>Horas de ' . pn_e(mcp_ponto_mes_nome($mes)) . '</h2>'
        . pn_navegar_mes('painel.php?v=colaborador&id=' . $c['id'], $mes)
        . '<p style="margin:0 0 14px"><b style="font-size:1.4rem;color:var(--black)">' . pn_e(mcp_ponto_horas_texto($minutos)) . '</b> em ' . count($dias) . (count($dias) === 1 ? ' dia' : ' dias') . '</p>'
        . '<div class="tabela rolagem"><table class="respostas"><thead><tr><th>Dia</th><th>Entrada</th><th>Saída</th><th>Horas</th><th>Registro</th><th></th></tr></thead><tbody>'
        . ($tabela !== '' ? $tabela : '<tr><td colspan="6" class="vazio">Nenhum registro neste mês.</td></tr>') . '</tbody></table></div>'
        . $lancar
        . '<p class="nota">A declaração de horas sai para o mês escolhido (no mês atual, até hoje), cita o termo de adesão e leva um código de verificação que qualquer pessoa confere em cruzvermelhariodejaneiro.org/conferir. '
        . 'O registro serve para reconhecer as horas doadas, nunca para cobrar horário.</p></div>'
        . pc_ficha_lembretes($usuario, $c)
        . '<div class="cartao" style="margin-top:18px;max-width:720px"><h2>Dados do colaborador</h2>' . $dados . '</div>';
    pn_pagina((string) $c['nome'], $corpo, $usuario, true, 'ponto');
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
    'col_ok' => ['Colaborador salvo.', 'ok'],
    'pt_corr' => ['Registro corrigido.', 'ok'],
    'pt_lanc' => ['Horas lançadas.', 'ok'],
    'pt_apag' => ['Registro apagado.', 'ok'],
    'tm_ok' => ['Termo de adesão registrado. A declaração de horas já pode ser emitida.', 'ok'],
    'tm_rem' => ['Registro do termo removido. A declaração de horas fica bloqueada até registrar de novo.', 'ok'],
    'pr_canc' => ['Presença cancelada. O comprovante deixou de valer.', 'ok'],
    'ap_ok' => ['Pronto: este aparelho agora é o ponto da sede. Deixe a página do ponto aberta nele.', 'ok'],
    'ap_desat' => ['Aparelho desativado. Ele não registra mais o ponto.', 'ok'],
    'cod_on' => ['Código do dia ligado: o celular passa a pedir os 4 números que aparecem no tablet da recepção.', 'ok'],
    'cod_off' => ['Código do dia desligado: pelo celular, voltam a bastar o CPF e a localização.', 'ok'],
    'ts_ok' => ['Situação do pedido atualizada.', 'ok'],
    'ts_lista' => ['Situação atualizada em todos os pedidos em aberto da lista.', 'ok'],
] + PC_AVISOS;

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
        // Pedido vindo de outro site (um formulário escondido numa página aberta no computador da sede) não gasta
        // o limite de pedidos do IP, que é o de todo o prédio.
        if (!mcp_origem_do_site(false)) {
            pn_login('Abra o portal pelo endereço dele e peça o link de novo.');
        }
        $email = mb_strtolower(mcp_texto($_POST['email'] ?? '', 190));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            pn_login('Digite um e-mail válido.');
        }
        [$maximo, $janela] = MCP_PAINEL_LIMITE_LINKS;
        if (mcp_contar_eventos_recentes('painel_link', mcp_ip_balde(), $janela) >= $maximo) {
            pn_login('Muitos pedidos de link em pouco tempo. Aguarde alguns minutos.');
        }
        mcp_registrar(null, 'painel_link', mcp_ip_balde());
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

    // Turmas sob demanda (lib/turmas.php): situação de um pedido ou de todos os abertos de uma lista. Só com sessão.
    if ($acao === 'turma_status' || $acao === 'turma_lista') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($sessao === null || $id < 0 || !hash_equals(mcp_painel_csrf($sessao, $acao, $id), mcp_texto($_POST['t'] ?? '', 40))) {
            pn_login('Sua sessão venceu ou o formulário não é mais válido. Entre de novo.');
        }
        $status = mcp_texto($_POST['status'] ?? '', 20);
        $curso = mcp_texto($_POST['curso'] ?? '', 80);
        $idioma = mcp_texto($_POST['idioma'] ?? '', 2);
        $lista = mcp_turma_lista_valida($curso, $idioma);
        $volta = 'v=turmas' . ($lista ? '&curso=' . rawurlencode($curso) . '&idioma=' . $idioma : '') . (mcp_texto($_POST['f'] ?? '', 10) === 'todos' ? '&f=todos' : '');
        if ($acao === 'turma_lista') {
            $n = $lista ? mcp_turma_status_lista($curso, $idioma, $status, $sessao, (int) ($_POST['ate'] ?? 0)) : 0;
            mcp_registrar(null, 'painel_turma_lista', "$curso · $idioma · $status · $n · $sessao");
            pn_redirecionar($volta . '&ok=ts_lista');
        }
        if (mcp_turma_status($id, $status, $sessao)) {
            mcp_registrar(null, 'painel_turma_status', "#$id · $status · $sessao");
        }
        pn_redirecionar($volta . '&ok=ts_ok');
    }

    // Comunicação e acréscimos do ponto (lib/painel_comunicacao.php). Só com sessão e o token do formulário.
    if (in_array($acao, PC_ACOES, true)) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($sessao === null || $id < 0 || !hash_equals(mcp_painel_csrf($sessao, $acao, $id), mcp_texto($_POST['t'] ?? '', 40))) {
            pn_login('Sua sessão venceu ou o formulário não é mais válido. Entre de novo.');
        }
        pc_post($sessao, $acao, $id);
    }

    // Ponto da sede (lib/ponto.php e lib/presenca.php): cadastro, correções, presenças e aparelhos. Só com sessão.
    if (in_array($acao, ['colaborador_salvar', 'termo_registrar', 'termo_remover', 'ponto_corrigir', 'ponto_lancar', 'ponto_apagar', 'presenca_cancelar', 'aparelho_ativar', 'aparelho_desativar', 'ponto_codigo'], true)) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($sessao === null || $id < 0 || !hash_equals(mcp_painel_csrf($sessao, $acao, $id), mcp_texto($_POST['t'] ?? '', 40))) {
            pn_login('Sua sessão venceu ou o formulário não é mais válido. Entre de novo.');
        }
        if ($acao === 'colaborador_salvar') {
            $conferido = mcp_colaborador_conferir($_POST);
            if (!$conferido['ok']) {
                pn_colaborador($sessao, $id ?: null, $conferido['erro'], 'erro', $_POST, $conferido['campo']);
            }
            $salvo = mcp_colaborador_salvar($id ?: null, $conferido['dados'], $sessao);
            if ($salvo === null) {
                pn_colaborador($sessao, $id ?: null, 'Este CPF já está cadastrado para outro colaborador.', 'erro', $_POST, 'cpf');
            }
            mcp_registrar(null, 'painel_colaborador', "#$salvo · $sessao · " . ($id ? 'editou' : 'cadastrou') . ' · vínculo ' . $conferido['dados']['vinculo']);
            pn_redirecionar('v=colaborador&id=' . $salvo . '&ok=col_ok');
        }
        if ($acao === 'termo_registrar' || $acao === 'termo_remover') {
            $colaborador = mcp_colaborador_por_id($id);
            if (!$colaborador) {
                pn_redirecionar('v=ponto');
            }
            $data = $acao === 'termo_registrar' ? mcp_texto($_POST['data'] ?? '', 10) : null;
            $erro = mcp_ponto_termo_registrar($colaborador, $data, $sessao);
            if ($erro !== null) {
                pn_colaborador($sessao, $id, $erro, 'erro');
            }
            pn_redirecionar('v=colaborador&id=' . $id . '&ok=' . ($data !== null ? 'tm_ok' : 'tm_rem') . '#termo');
        }
        if ($acao === 'ponto_corrigir' || $acao === 'ponto_apagar') {
            $registro = mcp_ponto_registro($id);
            if (!$registro) {
                pn_redirecionar('v=ponto');
            }
            $colaboradorId = (int) $registro['colaborador_id'];
            $motivo = mcp_texto($_POST['motivo'] ?? '', 200);
            $_GET['mes'] = mcp_data_brt((string) $registro['entrada'], 'Y-m');
            // Presença de quem não é voluntário não soma horas: não tem correção nem se apaga à mão.
            if (!(int) $registro['voluntario']) {
                pn_colaborador($sessao, $colaboradorId, 'Registro de presença não tem correção: ele não soma horas e sai sozinho depois de ' . MCP_PONTO_PRESENCA_DIAS . ' dias.', 'erro');
            }
            if ($motivo === '') {
                pn_colaborador($sessao, $colaboradorId, 'Escreva o motivo.', 'erro');
            }
            if ($acao === 'ponto_apagar') {
                mcp_ponto_apagar($id, $sessao, $motivo);
                pn_redirecionar('v=colaborador&id=' . $colaboradorId . '&mes=' . $_GET['mes'] . '&ok=pt_apag');
            }
            $entrada = mcp_ponto_local_para_utc(mcp_texto($_POST['entrada'] ?? '', 20));
            $saidaTexto = mcp_texto($_POST['saida'] ?? '', 20);
            $saida = $saidaTexto !== '' ? mcp_ponto_local_para_utc($saidaTexto) : null;
            $erro = $entrada === null || ($saidaTexto !== '' && $saida === null) ? 'Data ou hora inválida.' : mcp_ponto_corrigir($id, $entrada, $saida, $sessao, $motivo);
            if ($erro !== null) {
                pn_colaborador($sessao, $colaboradorId, $erro, 'erro');
            }
            pn_redirecionar('v=colaborador&id=' . $colaboradorId . '&mes=' . mcp_data_brt((string) $entrada, 'Y-m') . '&ok=pt_corr');
        }
        if ($acao === 'ponto_lancar') {
            $colaborador = mcp_colaborador_por_id($id);
            if (!$colaborador) {
                pn_redirecionar('v=ponto');
            }
            if (!mcp_ponto_voluntario($colaborador)) {
                pn_colaborador($sessao, $id, 'Lançar horas é só para voluntários e diretoria.', 'erro');
            }
            $dia = mcp_texto($_POST['data'] ?? '', 10);
            $entrada = mcp_ponto_local_para_utc($dia . ' ' . mcp_texto($_POST['entrada'] ?? '', 5));
            $saida = mcp_ponto_local_para_utc($dia . ' ' . mcp_texto($_POST['saida'] ?? '', 5));
            $motivo = mcp_texto($_POST['motivo'] ?? '', 200);
            $resultado = $entrada === null || $saida === null ? 'Data ou hora inválida.' : ($motivo === '' ? 'Escreva o motivo.' : mcp_ponto_lancar($id, $entrada, $saida, $sessao, $motivo));
            if (is_string($resultado)) {
                if (preg_match('/^\d{4}-\d{2}/', $dia)) {
                    $_GET['mes'] = substr($dia, 0, 7);
                }
                pn_colaborador($sessao, $id, $resultado, 'erro');
            }
            pn_redirecionar('v=colaborador&id=' . $id . '&mes=' . substr($dia, 0, 7) . '&ok=pt_lanc');
        }
        if ($acao === 'presenca_cancelar') {
            $presenca = mcp_presenca_por('id', (string) $id);
            if ($presenca) {
                mcp_presenca_cancelar($id, $sessao);
            }
            pn_redirecionar('v=ponto&aba=alunos' . ($presenca ? '&data=' . $presenca['aula_data'] : '') . '&ok=pr_canc');
        }
        if ($acao === 'ponto_codigo') {
            $ligar = !empty($_POST['ligar']);
            mcp_ajuste_gravar('ponto_codigo_celular', $ligar ? '1' : '0', $sessao);
            mcp_registrar(null, 'painel_ajuste', "$sessao · ponto_codigo_celular = " . ($ligar ? '1' : '0'));
            pn_redirecionar('v=ponto&aba=aparelhos&ok=' . ($ligar ? 'cod_on' : 'cod_off'));
        }
        if ($acao === 'aparelho_ativar') {
            $nome = mcp_texto($_POST['nome'] ?? '', 80);
            $novo = mcp_ponto_aparelho_criar($nome !== '' ? $nome : 'Aparelho da recepção', $sessao);
            mcp_ponto_aparelho_liberar($novo);
            mcp_registrar(null, 'painel_aparelho', "#$novo · $sessao · liberou");
            pn_redirecionar('v=ponto&aba=aparelhos&ok=ap_ok');
        }
        mcp_ponto_aparelho_desativar($id);
        mcp_registrar(null, 'painel_aparelho', "#$id · $sessao · desativou");
        pn_redirecionar('v=ponto&aba=aparelhos&ok=ap_desat');
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
if ($secao === 'ponto') {
    pn_ponto($sessao, $aviso, $classe);
}
if ($secao === 'colaborador') {
    pn_colaborador($sessao, isset($_GET['novo']) ? null : (int) ($_GET['id'] ?? 0), $aviso, $classe);
}
if ($secao === 'importar') {
    pc_importar($sessao, $aviso, $classe);
}
if ($secao === 'comunicacao') {
    pc_comunicacao($sessao, $aviso, $classe);
}
if ($secao === 'turmas') {
    pn_turmas($sessao, $aviso, $classe);
}
if ($secao === 'comunicado') {
    pc_comunicado($sessao, isset($_GET['novo']) ? null : (int) ($_GET['id'] ?? 0), $aviso, $classe);
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
