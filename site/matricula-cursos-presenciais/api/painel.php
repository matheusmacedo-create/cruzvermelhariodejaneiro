<?php
/**
 * Painel de contatos do chat: lista, detalhe e resposta por e-mail no padrão visual da instituição.
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

function pn_pagina(string $titulo, string $corpo, ?string $usuario, bool $comLista): never
{
    $logo = pn_e(mcp_email_logo());
    $topoDireita = $usuario !== null
        ? '<div class="usuario">' . pn_e($usuario) . ' · <a href="painel.php?sair=1">Sair</a></div>'
        : ($comLista ? '' : '<div class="usuario">Acesso por link · só este contato</div>');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . pn_e($titulo) . ' · Painel de contatos</title>'
        . '<link rel="icon" type="image/png" href="/assets/favicon.png">'
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">'
        . '<style>' . pn_css() . '</style></head><body><div class="faixa"></div>'
        . '<header class="topo"><div class="wrap"><a href="' . ($usuario !== null ? 'painel.php' : pn_e(mcp_site_url()) . '/') . '"><img src="' . $logo . '" width="120" height="36" alt="Cruz Vermelha Brasileira · Rio de Janeiro"></a>'
        . '<div><b>Painel de contatos</b><small>Chat do site · respostas por e-mail</small></div>' . $topoDireita . '</div></header>'
        . '<main class="wrap">' . $corpo . '</main></body></html>';
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
.topo .wrap{display:flex;align-items:center;gap:16px;padding:12px 20px}
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
CSS;
}

// ----------------------------------------------------------------------------- páginas
function pn_login(string $aviso = '', string $classe = 'erro'): never
{
    $corpo = '<div class="login"><div class="cartao">'
        . '<p class="eyebrow">Painel de contatos</p><h1>Entrar</h1>'
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
    $pagina = max(1, (int) ($_GET['p'] ?? 1));
    $linhas = mcp_contatos_listar($f === 'todos' ? null : $f, MCP_PAINEL_POR_PAGINA, ($pagina - 1) * MCP_PAINEL_POR_PAGINA);

    $pilulas = '';
    foreach ($filtros as $chave => $rotulo) {
        $n = $chave === 'todos' ? array_sum($totais) : $totais[$chave];
        $pilulas .= '<a class="pilula' . ($chave === $f ? ' ativo' : '') . '" href="painel.php?f=' . $chave . '">' . pn_e($rotulo) . ' <span class="n">' . $n . '</span></a>';
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
        $paginacao .= '<a href="painel.php?f=' . $f . '&p=' . ($pagina - 1) . '">Mais recentes</a>';
    }
    if (count($linhas) === MCP_PAINEL_POR_PAGINA) {
        $paginacao .= '<a href="painel.php?f=' . $f . '&p=' . ($pagina + 1) . '">Mais antigas</a>';
    }
    if ($paginacao !== '') {
        $corpo .= '<div class="paginacao">' . $paginacao . '</div>';
    }
    pn_pagina('Mensagens', $corpo, $usuario, true);
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
    $corpo = ($comLista ? '<a class="voltar" href="painel.php">← Voltar para a lista</a>' : '')
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
    pn_pagina((string) $c['nome'], $corpo, $usuario, $comLista);
}

// ----------------------------------------------------------------------------- fluxo
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$sessao = mcp_painel_sessao();
$avisos = [
    'respondido' => ['Resposta enviada por e-mail. O contato ficou como respondido.', 'ok'],
    'arquivar' => ['Contato arquivado.', 'ok'],
    'reabrir' => ['Contato reaberto.', 'ok'],
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
if (isset($_GET['id'])) {
    $c = mcp_contato_por_id((int) $_GET['id']);
    if (!$c) {
        pn_lista($sessao);
    }
    pn_detalhe($c, $sessao, 'id=' . (int) $c['id'], true, $sessao, $aviso, $classe);
}
pn_lista($sessao);
