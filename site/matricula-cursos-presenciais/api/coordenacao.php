<?php
/**
 * Página da coordenação do voluntariado (06/11/2026, pedido do Matheus): um link só da coordenadora, sem senha e sem
 * login, para ver as fichas com a foto, ler tudo o que a pessoa preencheu e aprovar ou recusar. Feita para quem tem
 * pouca familiaridade com computador e celular: letra grande, botões grandes, poucas palavras, uma decisão por tela e
 * sempre uma confirmação antes de aprovar ou recusar.
 *
 * Acesso: o link leva a chave COORDENACAO_CHAVE (config-coordenacao.php, só no servidor, 32+ caracteres):
 *   https://cruzvermelhariodejaneiro.org/matricula-cursos-presenciais/api/coordenacao.php?k=<chave>
 * Sem a chave configurada, a página responde 404. Chave errada, 404 também, e cada IP tem até 20 erros por hora. Quem tem o
 * link entra: se ele vazar, troque a chave no config-coordenacao.php e mande o link novo (o antigo para na hora).
 * Opcional: COORDENACAO_NOME, o nome que fica registrado em quem aprovou ou recusou (padrão "Coordenação").
 *
 * Telas (todas com ?k=):
 *   (nada)            lista "Para decidir" (fichas nova, em análise e entrevista), da mais antiga para a mais nova;
 *   ?ver=aprovados    as aceitas; ?ver=recusados as recusadas e arquivadas (mais novas primeiro);
 *   ?id=N             a ficha inteira, com a foto, o WhatsApp da pessoa e os botões Aprovar e Recusar;
 *   ?id=N&passo=aprovar|recusar   a confirmação (com o CPF, se a ficha veio sem);
 *   ?foto=N           a selfie (JPEG, sem cache).
 * Ações (POST com a chave): aprovar (aceita a ficha, cria o colaborador do ponto e o código de aprovação, como na Redação),
 * recusar, desfazer a recusa (volta para "em análise") e mandar e-mail à pessoa (pela Resend, com responder-para a caixa
 * do voluntariado; o texto vem pronto e pode ser mudado; cada envio fica nas anotações da ficha; até 40 por hora).
 * Ficha sem foto: botões "Pedir a foto pelo WhatsApp" e "Pedir a foto por e-mail", com o link só daquela ficha para a
 * pessoa mandar a selfie (api/voluntario-foto.php, lib/voluntario_foto.php). Nada é enviado sem a coordenadora tocar. Quem decidiu fica em status_por como "<nome> · link da coordenação".
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/voluntario_foto.php';

const CO_FALHAS = [20, 3600];
const CO_CHAVE_MIN = 32;
const CO_LIMITE_LISTA = 500;
const CO_PARA_DECIDIR = ['nova', 'em_analise', 'entrevista'];
const CO_RECUSADOS = ['recusada', 'arquivada'];
const CO_EMAIL_LIMITE = [40, 3600];
const CO_EMAIL_MIN = 10;
const CO_EMAIL_MAX = 5000;

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

// ----------------------------------------------------------------------------- acesso
function co_chave_configurada(): string
{
    $chave = (string) mcp_cfg('COORDENACAO_CHAVE', '');
    return strlen($chave) >= CO_CHAVE_MIN ? $chave : '';
}

function co_quem(): string
{
    $nome = mcp_texto((string) mcp_cfg('COORDENACAO_NOME', ''), 80);
    return ($nome !== '' ? $nome : 'Coordenação') . ' · link da coordenação';
}

function co_nao_encontrado(): never
{
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>Página não encontrada</title></head><body style="font:20px/1.5 system-ui,sans-serif;padding:32px;color:#111">'
        . '<h1 style="font-size:28px">Página não encontrada</h1><p>Confira se o link está completo.</p></body></html>';
    exit;
}

$chave = co_chave_configurada();
if ($chave === '') {
    co_nao_encontrado();
}
[$maximo, $janela] = CO_FALHAS;
if (mcp_contar_eventos_recentes('coordenacao_negado', mcp_ip_balde(), $janela) >= $maximo) {
    http_response_code(429);
    echo 'Muitas tentativas. Tente de novo mais tarde.';
    exit;
}
$recebida = (string) ($_POST['k'] ?? $_GET['k'] ?? '');
if ($recebida === '' || !hash_equals($chave, $recebida)) {
    mcp_registrar(null, 'coordenacao_negado', mcp_ip_balde());
    co_nao_encontrado();
}

// ----------------------------------------------------------------------------- html
function co_e(?string $s): string
{
    return mcp_escapar((string) $s);
}

/** Endereço desta página com a chave e os parâmetros dados. */
function co_url(array $q = []): string
{
    global $chave;
    return 'coordenacao.php?' . http_build_query(['k' => $chave] + $q);
}

function co_data(?string $utc, string $formato = 'd/m/Y'): string
{
    return mcp_data_brt($utc, $formato);
}

function co_pagina(string $titulo, string $corpo): never
{
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">'
        . '<meta name="theme-color" content="#c8102e">'
        . '<title>' . co_e($titulo) . '</title><style>' . co_css() . '</style></head><body>'
        . '<header class="topo"><div class="faixa"><span class="cruz" aria-hidden="true"></span>'
        . '<span><b>Voluntariado</b><br><small>Cruz Vermelha Brasileira · Rio de Janeiro</small></span></div></header>'
        . '<main class="conteudo">' . $corpo . '</main>'
        . '<footer class="rodape">As fotos e os dados são só para escolher os voluntários. Não mande para outras pessoas.</footer>'
        . '</body></html>';
    exit;
}

function co_css(): string
{
    return <<<'CSS'
:root{--vermelho:#c8102e;--vermelho-escuro:#9e0b23;--verde:#1b7a3a;--verde-escuro:#13602d;--texto:#111;--suave:#444;--linha:#cfcfcf;--fundo:#f4f4f2;--branco:#fff;--amarelo:#fff4cc}
*{box-sizing:border-box}
html{font-size:20px;-webkit-text-size-adjust:100%}
body{margin:0;background:var(--fundo);color:var(--texto);font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;line-height:1.5}
a{color:var(--vermelho-escuro)}
.topo{background:var(--vermelho);color:#fff}
.faixa{max-width:1180px;margin:0 auto;padding:14px 16px;display:flex;gap:14px;align-items:center;font-size:1.1rem}
.faixa small{font-size:.8rem;opacity:.95;line-height:1.3;display:inline-block}
.cruz{width:40px;height:40px;background:#fff;border-radius:8px;position:relative;flex:none}
.cruz::before,.cruz::after{content:"";position:absolute;background:var(--vermelho)}
.cruz::before{left:15px;top:6px;width:10px;height:28px}
.cruz::after{left:6px;top:15px;width:28px;height:10px}
.conteudo{max-width:1180px;margin:0 auto;padding:20px 16px 40px}
h1{font-size:1.6rem;line-height:1.25;margin:6px 0 6px}
h2{font-size:1.25rem;margin:0 0 12px}
.dica{color:var(--suave);margin:0 0 18px}
.abas{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 22px}
.aba{display:inline-flex;align-items:center;gap:10px;min-height:60px;padding:10px 20px;border-radius:14px;border:3px solid var(--linha);background:#fff;color:var(--texto);text-decoration:none;font-weight:700;font-size:1.05rem}
.aba[aria-current="page"]{border-color:var(--vermelho);background:var(--vermelho);color:#fff}
.num{display:inline-block;min-width:2em;padding:2px 10px;border-radius:999px;background:var(--fundo);color:var(--texto);text-align:center;font-size:.95rem}
.aba[aria-current="page"] .num{background:#fff;color:var(--vermelho-escuro)}
.grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px}
.cartao{background:#fff;border:2px solid var(--linha);border-radius:18px;overflow:hidden;display:flex;flex-direction:column;color:var(--texto);text-decoration:none}
a.cartao:hover,a.cartao:focus-visible{border-color:var(--vermelho);outline:none;box-shadow:0 0 0 4px rgba(200,16,46,.25)}
.cartao .foto{aspect-ratio:1/1;background:#e7e7e7;display:flex;align-items:center;justify-content:center;color:var(--suave);font-size:.9rem;text-align:center}
.cartao .foto img{width:100%;height:100%;object-fit:cover;display:block}
.cartao .dados{padding:14px 16px 16px;display:flex;flex-direction:column;gap:4px;flex:1}
.cartao .nome{font-size:1.25rem;font-weight:800;line-height:1.25}
.cartao .linha{color:var(--suave);font-size:.95rem}
.cartao .abrir{margin-top:10px;display:block;text-align:center;background:var(--vermelho);color:#fff;font-weight:800;border-radius:12px;padding:14px}
.selo{display:inline-block;padding:3px 12px;border-radius:999px;font-size:.85rem;font-weight:800;border:2px solid transparent}
.selo.nova{background:#ffe1e5;color:#8a0a1f;border-color:#f2a5b1}
.selo.andamento{background:var(--amarelo);color:#5c4500;border-color:#e8cf7a}
.selo.ok{background:#dff3e5;color:#0f5426;border-color:#9fd3ae}
.selo.neutro{background:#ececec;color:#333;border-color:#c9c9c9}
.vazio{background:#fff;border:2px dashed var(--linha);border-radius:18px;padding:28px;text-align:center;font-size:1.1rem}
.voltar{display:inline-flex;align-items:center;gap:8px;min-height:56px;padding:10px 18px;border-radius:12px;background:#fff;border:2px solid var(--linha);color:var(--texto);text-decoration:none;font-weight:700;margin:0 0 16px}
.ficha{display:grid;grid-template-columns:minmax(260px,420px) 1fr;grid-template-areas:"foto cabeca" "foto corpo";gap:0 24px;align-items:start}
.ficha .retrato{grid-area:foto}.ficha .cabeca{grid-area:cabeca}.ficha .corpo{grid-area:corpo}
@media (max-width:820px){.ficha{grid-template-columns:1fr;grid-template-areas:"cabeca" "foto" "corpo"}.retrato{margin-bottom:18px}.retrato img{max-height:55vh;object-fit:cover}}
.retrato{background:#fff;border:2px solid var(--linha);border-radius:18px;padding:12px;position:sticky;top:12px;grid-row:span 2}
@media (max-width:820px){.retrato{position:static}}
.retrato img{width:100%;height:auto;border-radius:12px;display:block}
.retrato .semfoto{aspect-ratio:1/1;border-radius:12px;background:#e7e7e7;display:flex;align-items:center;justify-content:center;text-align:center;padding:20px;color:var(--suave)}
.retrato .ampliar{display:block;text-align:center;margin-top:8px;font-size:.9rem}
.bloco{background:#fff;border:2px solid var(--linha);border-radius:18px;padding:18px 20px;margin:0 0 18px}
.bloco dl{margin:0;display:grid;grid-template-columns:minmax(150px,240px) 1fr;gap:10px 18px}
@media (max-width:560px){.bloco dl{grid-template-columns:1fr;gap:2px}.bloco dd{margin-bottom:10px}}
.bloco dt{color:var(--suave);font-weight:600}
.bloco dd{margin:0;font-weight:700;overflow-wrap:anywhere}
.texto{white-space:pre-wrap;overflow-wrap:anywhere;margin:0 0 6px;font-size:1.02rem}
.rotulo{color:var(--suave);font-weight:700;margin:14px 0 4px}
.rotulo:first-of-type{margin-top:0}
.botoes{display:flex;flex-wrap:wrap;gap:12px;margin:12px 0 0}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;min-height:64px;padding:12px 26px;border-radius:14px;border:3px solid transparent;font:inherit;font-size:1.1rem;font-weight:800;text-decoration:none;cursor:pointer;text-align:center}
.btn.grande{min-height:76px;font-size:1.25rem;flex:1 1 240px}
.btn.verde{background:var(--verde);color:#fff}.btn.verde:hover{background:var(--verde-escuro)}
.btn.vermelho{background:#fff;color:var(--vermelho-escuro);border-color:var(--vermelho)}.btn.vermelho:hover{background:#ffeef0}
.btn.cheio{background:var(--vermelho);color:#fff}.btn.cheio:hover{background:var(--vermelho-escuro)}
.btn.claro{background:#fff;color:var(--texto);border-color:var(--linha)}
.btn.whats{background:#1f8f4e;color:#fff}
.btn:focus-visible,.aba:focus-visible,.voltar:focus-visible{outline:4px solid #1a5fb4;outline-offset:3px}
.decidir{border-color:var(--vermelho);border-width:3px}
.aviso{border-radius:16px;padding:16px 20px;margin:0 0 18px;font-size:1.05rem;font-weight:600}
.aviso.ok{background:#dff3e5;border:2px solid #9fd3ae;color:#0f4a22}
.aviso.erro{background:#ffe1e5;border:2px solid #f2a5b1;color:#7a0a1c}
.aviso.info{background:var(--amarelo);border:2px solid #e8cf7a;color:#4d3a00}
.confirmar{max-width:640px;margin:0 auto;text-align:center}
.confirmar img{width:220px;height:220px;object-fit:cover;border-radius:18px;border:2px solid var(--linha)}
.campo{display:block;text-align:left;margin:18px 0 0}
.campo span{display:block;font-weight:700;margin-bottom:6px}
.campo input{width:100%;min-height:64px;font:inherit;font-size:1.3rem;padding:10px 16px;border:3px solid var(--linha);border-radius:12px;letter-spacing:.04em}
.campo input:focus{border-color:#1a5fb4;outline:none}
.campo textarea{width:100%;min-height:220px;font:inherit;font-size:1.05rem;line-height:1.5;padding:12px 16px;border:3px solid var(--linha);border-radius:12px;resize:vertical}
.campo textarea:focus{border-color:#1a5fb4;outline:none}
.btn.azul{background:#1a5fb4;color:#fff}.btn.azul:hover{background:#144a8c}
.codigo{font-size:2.2rem;font-weight:900;letter-spacing:.08em;margin:6px 0}
.rodape{max-width:1180px;margin:0 auto;padding:0 16px 30px;color:var(--suave);font-size:.85rem}
CSS;
}

function co_selo(string $status): string
{
    $classe = match ($status) {
        'nova' => 'nova',
        'em_analise', 'entrevista' => 'andamento',
        'aceita' => 'ok',
        default => 'neutro',
    };
    $texto = match ($status) {
        'nova' => 'Nova',
        'em_analise' => 'Em análise',
        'entrevista' => 'Entrevista',
        'aceita' => 'Aprovada',
        'recusada' => 'Recusada',
        'arquivada' => 'Arquivada',
        default => $status,
    };
    return '<span class="selo ' . $classe . '">' . co_e($texto) . '</span>';
}

/** Ids das fichas que têm selfie (uma consulta para a lista inteira). */
function co_com_foto(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    try {
        $stmt = mcp_db()->prepare('SELECT ficha_id FROM mcp_voluntarios_selfies WHERE ficha_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    } catch (PDOException) {
        return [];
    }
}

/** Fichas de um grupo de situações, sem paginação (até CO_LIMITE_LISTA). */
function co_fichas(array $status, bool $antigasPrimeiro): array
{
    $marcas = implode(',', array_fill(0, count($status), '?'));
    $ordem = $antigasPrimeiro ? 'ASC' : 'DESC';
    $stmt = mcp_db()->prepare('SELECT id, protocolo, nome, data_nascimento, cidade, bairro, estado, frentes, status, status_em, criado_em'
        . " FROM mcp_voluntarios WHERE status IN ($marcas) ORDER BY id $ordem LIMIT " . CO_LIMITE_LISTA);
    $stmt->execute($status);
    return $stmt->fetchAll();
}

function co_contar(array $status): int
{
    $stmt = mcp_db()->prepare('SELECT COUNT(*) FROM mcp_voluntarios WHERE status IN (' . implode(',', array_fill(0, count($status), '?')) . ')');
    $stmt->execute($status);
    return (int) $stmt->fetchColumn();
}

function co_whatsapp(string $telefone, string $mensagem): string
{
    $d = mcp_digitos($telefone);
    return $d === '' ? '' : 'https://wa.me/55' . $d . '?text=' . rawurlencode($mensagem);
}

function co_aviso(string $texto, string $classe = 'ok'): string
{
    return $texto === '' ? '' : '<div class="aviso ' . co_e($classe) . '" role="status">' . $texto . '</div>';
}

// ----------------------------------------------------------------------------- e-mail à pessoa
/** O texto pronto do e-mail, conforme a situação da ficha (a coordenadora pode mudar antes de enviar). */
function co_email_modelo(array $f): string
{
    $ola = 'Olá, ' . mcp_primeiro_nome((string) $f['nome']) . "!\n\n";
    if ((string) $f['status'] === 'aceita') {
        $a = mcp_voluntario_aprovacao((int) $f['id']);
        if ($a && empty($a['cancelada_em'])) {
            return $ola . 'Sua ficha de voluntário foi aprovada. Seu código de aprovação é ' . mcp_codigo_formatado((string) $a['codigo']) . ".\n\n"
                . 'Qualquer pessoa pode conferir o código em ' . mcp_conferir_url((string) $a['codigo']) . ". Guarde este código: ele mostra que você foi aprovado(a) para seguir para o CBFI e o termo de adesão.\n\n"
                . 'Em breve entramos em contato com as próximas orientações.';
        }
    }
    return $ola . "Recebemos a sua ficha de voluntário na Cruz Vermelha Brasileira Rio de Janeiro e gostaríamos de conversar com você.\n\n"
        . 'Pode responder este e-mail dizendo o melhor dia e horário para falarmos? Se preferir, mande uma mensagem pelo WhatsApp.';
}

/** Monta o e-mail da coordenação para a pessoa da ficha. */
function co_email_montar(array $f, string $mensagem): array
{
    $protocolo = (string) ($f['protocolo'] ?? '');
    $assinatura = 'Coordenação do voluntariado · Cruz Vermelha Brasileira Rio de Janeiro';
    $corpo = '<div style="font-size:16px;line-height:1.6;color:#1a202c">' . nl2br(mcp_escapar($mensagem), false) . '</div>'
        . '<p style="margin:22px 0 0;font-size:15px;line-height:1.5;color:#1a202c"><strong>' . mcp_escapar($assinatura) . '</strong></p>'
        . mcp_nota('Para falar com a coordenação, é só responder este e-mail' . ($protocolo !== '' ? ' (protocolo da sua ficha: <strong>' . mcp_escapar($protocolo) . '</strong>)' : '') . '.');
    return [
        'assunto' => 'Voluntariado da Cruz Vermelha Brasileira Rio de Janeiro' . ($protocolo !== '' ? " · $protocolo" : ''),
        'html' => mcp_moldura('Mensagem da coordenação do voluntariado', $corpo, [
            'eyebrow' => 'Voluntariado',
            'preheader' => mb_substr(preg_replace('/\s+/u', ' ', $mensagem) ?? '', 0, 140),
            'motivo' => 'Você recebeu este e-mail porque preencheu a ficha de voluntariado em cruzvermelhariodejaneiro.org' . ($protocolo !== '' ? " (protocolo $protocolo)" : '') . '.',
        ]),
        'texto' => "$mensagem\n\n$assinatura\n\nPara falar com a coordenação, é só responder este e-mail" . ($protocolo !== '' ? " (protocolo $protocolo)" : '') . ".\n",
    ];
}

/** O bloco "Mandar e-mail" da ficha: o texto pronto numa caixa grande e o botão de enviar. */
function co_bloco_email(array $f, string $mensagem = ''): string
{
    $email = (string) $f['email'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }
    $mensagem = $mensagem !== '' ? $mensagem : co_email_modelo($f);
    return '<div class="bloco" id="email"><h2>Mandar e-mail</h2>'
        . '<p style="margin:0">Para <b>' . co_e($email) . '</b>. A mensagem já está escrita: se quiser, mude o texto. Depois toque em "Enviar e-mail". A resposta da pessoa chega na caixa do voluntariado.</p>'
        . '<form method="post" action="coordenacao.php"><input type="hidden" name="k" value="' . co_e($GLOBALS['chave']) . '">'
        . '<input type="hidden" name="acao" value="email"><input type="hidden" name="id" value="' . (int) $f['id'] . '">'
        . '<label class="campo"><span>Mensagem</span><textarea name="mensagem" maxlength="' . CO_EMAIL_MAX . '" required>' . co_e($mensagem) . '</textarea></label>'
        . '<div class="botoes"><button class="btn grande azul" type="submit">Enviar e-mail</button></div></form></div>';
}

/** Envia o e-mail e anota na ficha. Devolve null (enviado) ou a mensagem de erro para a tela. */
function co_email_enviar(array $f, string $mensagem): ?string
{
    if (!filter_var((string) $f['email'], FILTER_VALIDATE_EMAIL)) {
        return 'Esta ficha não tem um e-mail válido.';
    }
    if (mb_strlen($mensagem) < CO_EMAIL_MIN) {
        return 'A mensagem está vazia ou curta demais. Escreva um pouco mais e envie de novo.';
    }
    [$maximo, $janela] = CO_EMAIL_LIMITE;
    if (mcp_contar_eventos_recentes('coordenacao_email', 'enviado', $janela) >= $maximo) {
        return 'Muitos e-mails enviados na última hora. Espere um pouco e tente de novo.';
    }
    $m = co_email_montar($f, $mensagem);
    $envio = mcp_enviar_email((string) $f['email'], $m['assunto'], $m['html'], $m['texto'], mcp_voluntarios_email_destino());
    if ($envio === 'falhou') {
        mcp_registrar(null, 'coordenacao_email', 'falhou');
        return 'O e-mail não pôde ser enviado agora. Tente de novo daqui a pouco.';
    }
    mcp_registrar(null, 'coordenacao_email', 'enviado');
    mcp_voluntario_nota_registrar((int) $f['id'], co_quem(), "E-mail enviado à pessoa:\n\n$mensagem");
    return null;
}

// ----------------------------------------------------------------------------- telas
function co_lista(string $ver, string $aviso = ''): never
{
    $abas = [
        'decidir' => ['Para decidir', CO_PARA_DECIDIR, true],
        'aprovados' => ['Aprovados', ['aceita'], false],
        'recusados' => ['Recusados', CO_RECUSADOS, false],
    ];
    if (!isset($abas[$ver])) {
        $ver = 'decidir';
    }
    [$titulo, $status, $antigas] = $abas[$ver];
    $menu = '';
    foreach ($abas as $chaveAba => [$rotulo, $sts]) {
        $atual = $chaveAba === $ver ? ' aria-current="page"' : '';
        $menu .= '<a class="aba" href="' . co_e(co_url($chaveAba === 'decidir' ? [] : ['ver' => $chaveAba])) . '"' . $atual . '>'
            . co_e($rotulo) . ' <span class="num">' . co_contar($sts) . '</span></a>';
    }
    $fichas = co_fichas($status, $antigas);
    $fotos = co_com_foto(array_column($fichas, 'id'));
    $cartoes = '';
    foreach ($fichas as $f) {
        $id = (int) $f['id'];
        $idade = mcp_voluntario_idade_texto($f);
        $foto = isset($fotos[$id])
            ? '<img src="' . co_e(co_url(['foto' => $id])) . '" alt="Foto de ' . co_e((string) $f['nome']) . '" loading="lazy" decoding="async">'
            : '<span>Sem foto</span>';
        $cartoes .= '<a class="cartao" href="' . co_e(co_url(['id' => $id])) . '">'
            . '<div class="foto">' . $foto . '</div><div class="dados">'
            . '<div>' . co_selo((string) $f['status']) . '</div>'
            . '<div class="nome">' . co_e((string) $f['nome']) . '</div>'
            . '<div class="linha">' . co_e(trim($idade . ($idade !== '' ? ' · ' : '') . mcp_voluntario_local_texto($f))) . '</div>'
            . '<div class="linha">Chegou em ' . co_e(co_data((string) $f['criado_em'])) . '</div>'
            . '<span class="abrir">Ver a ficha completa</span></div></a>';
    }
    $dica = match ($ver) {
        'decidir' => 'Toque numa foto para ver a ficha completa e decidir. As que chegaram primeiro aparecem primeiro.',
        'aprovados' => 'Pessoas que você já aprovou. Toque para ver a ficha e mandar o código de aprovação pelo WhatsApp.',
        default => 'Fichas recusadas ou arquivadas. Se recusou por engano, abra a ficha e toque em "Desfazer a recusa".',
    };
    $vazio = match ($ver) {
        'decidir' => 'Nenhuma ficha esperando decisão. Tudo em dia!',
        'aprovados' => 'Ainda não há ninguém aprovado.',
        default => 'Nenhuma ficha recusada.',
    };
    co_pagina('Fichas de voluntários · ' . $titulo,
        $aviso . '<h1>Fichas de quem quer ser voluntário</h1><p class="dica">' . co_e($dica) . '</p>'
        . '<nav class="abas" aria-label="Grupos de fichas">' . $menu . '</nav>'
        . ($cartoes !== '' ? '<div class="grade">' . $cartoes . '</div>' : '<div class="vazio">' . co_e($vazio) . '</div>'));
}

function co_dl(array $linhas): string
{
    $html = '';
    foreach ($linhas as $rotulo => $valor) {
        $html .= '<dt>' . co_e((string) $rotulo) . '</dt><dd>' . $valor . '</dd>';
    }
    return '<dl>' . $html . '</dl>';
}

/** Próxima ficha "para decidir" depois desta (na ordem da lista), ou null. */
function co_proxima(int $depoisDe): ?int
{
    $marcas = implode(',', array_fill(0, count(CO_PARA_DECIDIR), '?'));
    $stmt = mcp_db()->prepare("SELECT id FROM mcp_voluntarios WHERE status IN ($marcas) AND id > ? ORDER BY id ASC LIMIT 1");
    $stmt->execute([...CO_PARA_DECIDIR, $depoisDe]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        $stmt = mcp_db()->prepare("SELECT id FROM mcp_voluntarios WHERE status IN ($marcas) AND id <> ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([...CO_PARA_DECIDIR, $depoisDe]);
        $id = $stmt->fetchColumn();
    }
    return $id === false ? null : (int) $id;
}

function co_retrato(array $f, bool $temFoto): string
{
    $id = (int) $f['id'];
    return $temFoto
        ? '<img src="' . co_e(co_url(['foto' => $id])) . '" alt="Foto de ' . co_e((string) $f['nome']) . '">'
            . '<a class="ampliar" href="' . co_e(co_url(['foto' => $id])) . '" target="_blank" rel="noopener">Abrir a foto em tamanho grande</a>'
        : '<div class="semfoto">Esta ficha não tem foto. As fichas de antes de 27/10/2026 não pediam foto.</div>' . co_pedir_foto($f);
}

/** Botões para pedir a foto (só nas fichas sem foto): WhatsApp com a mensagem pronta e e-mail com um toque. */
function co_pedir_foto(array $f): string
{
    $mensagem = mcp_vf_mensagem($f);
    $whats = co_whatsapp((string) $f['telefone'], $mensagem);
    $botoes = ($whats !== '' ? '<a class="btn whats" style="width:100%" href="' . co_e($whats) . '" target="_blank" rel="noopener">Pedir a foto pelo WhatsApp</a>' : '')
        . (filter_var((string) $f['email'], FILTER_VALIDATE_EMAIL)
            ? '<form method="post" action="coordenacao.php" style="width:100%"><input type="hidden" name="k" value="' . co_e($GLOBALS['chave']) . '">'
                . '<input type="hidden" name="acao" value="email"><input type="hidden" name="id" value="' . (int) $f['id'] . '">'
                . '<input type="hidden" name="mensagem" value="' . co_e($mensagem) . '">'
                . '<button class="btn azul" style="width:100%" type="submit">Pedir a foto por e-mail</button></form>'
            : '');
    return $botoes === '' ? '' : '<p style="margin:12px 0 0;font-size:.95rem">Se achar necessário, peça a foto. A pessoa recebe um link só dela para mandar a selfie, e a foto aparece aqui sozinha.</p>'
        . '<div class="botoes" style="flex-direction:column">' . $botoes . '</div>';
}

function co_bloco_aprovacao(array $f): string
{
    $a = mcp_voluntario_aprovacao((int) $f['id']);
    if (!$a || !empty($a['cancelada_em'])) {
        return '<div class="bloco"><h2>Aprovada</h2><p style="margin:0">Esta ficha foi aprovada em ' . co_e(co_data((string) $f['status_em'])) . '.</p></div>';
    }
    $codigo = (string) $a['codigo'];
    $whats = co_whatsapp((string) $f['telefone'], mcp_voluntario_aprovacao_mensagem($f, $codigo));
    return '<div class="bloco"><h2>Aprovada</h2>'
        . '<p style="margin:0">Código de aprovação:</p><p class="codigo">' . co_e(mcp_codigo_formatado($codigo)) . '</p>'
        . '<p style="margin:0 0 4px">Mande o código para a pessoa pelo WhatsApp. A mensagem já vai pronta: é só tocar em enviar.</p>'
        . '<div class="botoes">' . ($whats !== '' ? '<a class="btn grande whats" href="' . co_e($whats) . '" target="_blank" rel="noopener">Mandar o código pelo WhatsApp</a>' : '<p>Esta ficha não tem telefone.</p>')
        . (filter_var((string) $f['email'], FILTER_VALIDATE_EMAIL) ? '<a class="btn grande azul" href="#email">Mandar o código por e-mail</a>' : '') . '</div></div>';
}

function co_ficha(int $id, string $aviso = '', string $mensagemEmail = ''): never
{
    $f = mcp_voluntario_por_id($id);
    if (!$f) {
        co_lista('decidir', co_aviso('Essa ficha não foi encontrada.', 'erro'));
    }
    $status = (string) $f['status'];
    $temFoto = mcp_voluntario_selfie($id) !== null;
    $nome = (string) $f['nome'];
    $telefone = (string) $f['telefone'];
    $idade = mcp_voluntario_idade((string) $f['data_nascimento']);
    $saudacao = 'Olá, ' . mcp_primeiro_nome($nome) . '! Aqui é a coordenação do voluntariado da Cruz Vermelha Brasileira Rio de Janeiro. Recebemos a sua ficha de voluntário.';
    $whats = co_whatsapp($telefone, $saudacao);
    $contatoBotoes = ($whats !== '' ? '<a class="btn whats" href="' . co_e($whats) . '" target="_blank" rel="noopener">Conversar no WhatsApp</a>' : '')
        . ($telefone !== '' ? '<a class="btn claro" href="tel:+55' . co_e(mcp_digitos($telefone)) . '">Ligar</a>' : '')
        . (filter_var((string) $f['email'], FILTER_VALIDATE_EMAIL) ? '<a class="btn claro" href="#email">Mandar e-mail</a>' : '');

    $saude = (int) ($f['saude'] ?? 0) === 1 ? 'Sim' . (!empty($f['saude_qual']) ? ': ' . (string) $f['saude_qual'] : '') : 'Não';
    $quem = co_dl([
        'Nome' => co_e($nome),
        'Idade' => co_e(($idade !== null ? $idade . ' anos' : '') . ' (nasceu em ' . mcp_voluntario_nascimento_texto($f) . ')')
            . (mcp_voluntario_menor($f) ? ' <span class="selo andamento">menor de 18</span>' : ''),
        'Onde mora' => co_e(mcp_voluntario_local_texto($f)),
        'Profissão' => co_e((string) ($f['profissao'] ?: 'não informada')),
        'Escolaridade' => co_e(MCP_VOL_ESCOLARIDADES[$f['escolaridade']] ?? (string) $f['escolaridade']),
        'Formação na saúde' => co_e($saude),
        'CPF' => co_e(mcp_voluntario_cpf_parcial($f['cpf'] ?? null) ?: 'não informado'),
    ]);
    $contato = co_dl([
        'Telefone' => $telefone !== '' ? co_e(mcp_telefone_bonito($telefone)) : 'não informado',
        'E-mail' => '<a href="mailto:' . co_e((string) $f['email']) . '">' . co_e((string) $f['email']) . '</a>',
        'Aceita contato' => (int) ($f['aceita_contato'] ?? 0) === 1 ? 'Sim, por e-mail e telefone' : 'Não marcou',
    ]);
    $ajudar = co_dl([
        'Quer ajudar em' => co_e(mcp_voluntario_rotulos((string) $f['frentes'], MCP_VOL_FRENTES)),
        'Dias' => co_e(mcp_voluntario_rotulos((string) $f['dias'], MCP_VOL_DIAS)),
        'Horários' => co_e(mcp_voluntario_rotulos((string) $f['periodos'], MCP_VOL_PERIODOS)),
        'Como conheceu' => co_e(!empty($f['como_conheceu']) ? (MCP_VOL_COMO_CONHECEU[$f['como_conheceu']] ?? (string) $f['como_conheceu']) : 'não informado'),
    ]);
    $palavras = '<p class="rotulo">Por que quer ser voluntário</p><p class="texto">' . co_e((string) $f['motivacao']) . '</p>'
        . (!empty($f['experiencia']) ? '<p class="rotulo">Experiência com voluntariado</p><p class="texto">' . co_e((string) $f['experiencia']) . '</p>' : '')
        . (!empty($f['habilidades']) ? '<p class="rotulo">Habilidades e formação</p><p class="texto">' . co_e((string) $f['habilidades']) . '</p>' : '');
    $notas = '';
    foreach (mcp_voluntario_notas($id) as $n) {
        $notas .= '<p class="rotulo">' . co_e(co_data((string) $n['criado_em'], 'd/m/Y')) . ' · ' . co_e((string) $n['quem']) . '</p><p class="texto">' . co_e((string) $n['texto']) . '</p>';
    }

    if (in_array($status, CO_PARA_DECIDIR, true)) {
        $decisao = '<div class="bloco decidir"><h2>Sua decisão</h2>'
            . '<p style="margin:0">Depois de conversar com a pessoa, escolha uma opção. Antes de valer, a página pergunta se você tem certeza.</p>'
            . '<div class="botoes"><a class="btn grande verde" href="' . co_e(co_url(['id' => $id, 'passo' => 'aprovar'])) . '">Aprovar</a>'
            . '<a class="btn grande vermelho" href="' . co_e(co_url(['id' => $id, 'passo' => 'recusar'])) . '">Recusar</a></div></div>';
    } elseif ($status === 'aceita') {
        $decisao = co_bloco_aprovacao($f);
    } else {
        $decisao = '<div class="bloco"><h2>' . ($status === 'recusada' ? 'Recusada' : 'Arquivada') . '</h2>'
            . '<p style="margin:0">' . co_e('Em ' . co_data((string) $f['status_em']) . (!empty($f['status_por']) ? ', por ' . $f['status_por'] : '') . '.') . ' Se foi engano, a ficha volta para "Para decidir".</p>'
            . '<form method="post" action="coordenacao.php" class="botoes"><input type="hidden" name="k" value="' . co_e($GLOBALS['chave']) . '">'
            . '<input type="hidden" name="acao" value="desfazer"><input type="hidden" name="id" value="' . $id . '">'
            . '<button class="btn claro" type="submit">Desfazer a recusa</button></form></div>';
    }
    $voltar = match (true) {
        $status === 'aceita' => co_url(['ver' => 'aprovados']),
        in_array($status, CO_RECUSADOS, true) => co_url(['ver' => 'recusados']),
        default => co_url(),
    };
    co_pagina($nome . ' · ficha de voluntário',
        '<a class="voltar" href="' . co_e($voltar) . '">← Voltar para a lista</a>' . $aviso
        . '<div class="ficha"><div class="retrato">' . co_retrato($f, $temFoto) . '</div><div class="cabeca">'
        . '<p style="margin:0">' . co_selo($status) . ' <span class="dica">Chegou em ' . co_e(co_data((string) $f['criado_em'], 'd/m/Y \à\s H\hi')) . ' · ' . co_e((string) $f['protocolo']) . '</span></p>'
        . '<h1>' . co_e($nome) . '</h1>'
        . ($contatoBotoes !== '' ? '<div class="botoes" style="margin:6px 0 18px">' . $contatoBotoes . '</div>' : '')
        . '</div><div class="corpo">' . $decisao
        . '<div class="bloco"><h2>Quem é</h2>' . $quem . '</div>'
        . '<div class="bloco"><h2>Contato</h2>' . $contato . '</div>'
        . '<div class="bloco"><h2>Como quer ajudar</h2>' . $ajudar . '</div>'
        . '<div class="bloco"><h2>Nas palavras da pessoa</h2>' . $palavras . '</div>'
        . co_bloco_email($f, $mensagemEmail)
        . ($notas !== '' ? '<div class="bloco"><h2>Anotações da equipe</h2>' . $notas . '</div>' : '')
        . '</div></div>');
}

function co_confirmar(int $id, string $passo, string $erro = '', string $cpfDigitado = ''): never
{
    $f = mcp_voluntario_por_id($id);
    if (!$f) {
        co_lista('decidir', co_aviso('Essa ficha não foi encontrada.', 'erro'));
    }
    if (!in_array((string) $f['status'], CO_PARA_DECIDIR, true)) {
        co_ficha($id, co_aviso('Essa ficha já foi decidida.', 'info'));
    }
    $nome = (string) $f['nome'];
    $temFoto = mcp_voluntario_selfie($id) !== null;
    $foto = $temFoto ? '<img src="' . co_e(co_url(['foto' => $id])) . '" alt="Foto de ' . co_e($nome) . '">' : '';
    $aprovar = $passo === 'aprovar';
    $precisaCpf = $aprovar && !mcp_cpf_valido(mcp_digitos((string) ($f['cpf'] ?? '')));
    $campoCpf = $precisaCpf
        ? '<label class="campo"><span>Esta ficha veio sem CPF. Digite o CPF da pessoa (pergunte a ela pelo WhatsApp):</span>'
            . '<input name="cpf" inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" value="' . co_e($cpfDigitado) . '" required></label>'
        : '';
    $pergunta = $aprovar ? 'Aprovar ' . $nome . '?' : 'Recusar ' . $nome . '?';
    $explica = $aprovar
        ? 'Ao aprovar, a pessoa ganha um código de aprovação, e você poderá mandar esse código pelo WhatsApp.'
        : 'A pessoa não recebe nenhum aviso automático. Se precisar, você pode desfazer depois.';
    co_pagina($pergunta,
        '<div class="confirmar">' . ($erro !== '' ? co_aviso(co_e($erro), 'erro') : '') . $foto
        . '<h1 style="margin-top:14px">' . co_e($pergunta) . '</h1><p>' . co_e($explica) . '</p>'
        . '<form method="post" action="coordenacao.php"><input type="hidden" name="k" value="' . co_e($GLOBALS['chave']) . '">'
        . '<input type="hidden" name="acao" value="' . ($aprovar ? 'aprovar' : 'recusar') . '"><input type="hidden" name="id" value="' . $id . '">'
        . $campoCpf
        . '<div class="botoes" style="justify-content:center;margin-top:22px">'
        . '<button class="btn grande ' . ($aprovar ? 'verde' : 'cheio') . '" type="submit">' . ($aprovar ? 'Sim, aprovar' : 'Sim, recusar') . '</button>'
        . '<a class="btn grande claro" href="' . co_e(co_url(['id' => $id])) . '">Não, voltar</a></div></form></div>');
}

/** Depois de aprovar: o código grande, o WhatsApp com a mensagem pronta e a próxima ficha. */
function co_aprovada(array $f, string $codigo): never
{
    $id = (int) $f['id'];
    $whats = co_whatsapp((string) $f['telefone'], mcp_voluntario_aprovacao_mensagem($f, $codigo));
    $proxima = co_proxima($id);
    co_pagina('Ficha aprovada',
        '<div class="confirmar">' . co_aviso('Pronto! A ficha de ' . co_e((string) $f['nome']) . ' foi aprovada.', 'ok')
        . '<p style="margin:0">Código de aprovação:</p><p class="codigo">' . co_e(mcp_codigo_formatado($codigo)) . '</p>'
        . '<p>Agora mande o código para a pessoa. Toque no botão verde: o WhatsApp abre com a mensagem pronta, é só enviar.</p>'
        . '<div class="botoes" style="justify-content:center">'
        . ($whats !== '' ? '<a class="btn grande whats" href="' . co_e($whats) . '" target="_blank" rel="noopener">Mandar pelo WhatsApp</a>' : '<p>Esta ficha não tem telefone: entregue o código pessoalmente.</p>')
        . (filter_var((string) $f['email'], FILTER_VALIDATE_EMAIL)
            ? '<form method="post" action="coordenacao.php" style="display:contents"><input type="hidden" name="k" value="' . co_e($GLOBALS['chave']) . '">'
                . '<input type="hidden" name="acao" value="email"><input type="hidden" name="id" value="' . $id . '">'
                . '<input type="hidden" name="mensagem" value="' . co_e(co_email_modelo($f)) . '">'
                . '<button class="btn grande azul" type="submit">Mandar por e-mail</button></form>'
            : '')
        . '</div><div class="botoes" style="justify-content:center">'
        . ($proxima !== null ? '<a class="btn grande cheio" href="' . co_e(co_url(['id' => $proxima])) . '">Ver a próxima ficha</a>' : '')
        . '<a class="btn grande claro" href="' . co_e(co_url()) . '">Voltar para a lista</a></div></div>');
}

// ----------------------------------------------------------------------------- rotas
if (isset($_GET['foto'])) {
    $selfie = mcp_voluntario_selfie((int) $_GET['foto']);
    if ($selfie === null) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . strlen($selfie['jpeg']));
    header('Content-Disposition: inline; filename="foto.jpg"');
    echo $selfie['jpeg'];
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $acao = (string) ($_POST['acao'] ?? '');
    $quem = co_quem();
    $f = $id > 0 ? mcp_voluntario_por_id($id) : null;
    if (!$f) {
        co_lista('decidir', co_aviso('Essa ficha não foi encontrada.', 'erro'));
    }
    switch ($acao) {
        case 'aprovar':
            if ((string) $f['status'] !== 'aceita') {
                if (!in_array((string) $f['status'], CO_PARA_DECIDIR, true)) {
                    co_ficha($id, co_aviso('Essa ficha já foi decidida.', 'info'));
                }
                $cpf = mcp_texto($_POST['cpf'] ?? '', 20);
                if ($cpf !== '' && !mcp_cpf_valido(mcp_digitos($cpf))) {
                    co_confirmar($id, 'aprovar', 'Esse CPF não está certo. Confira os números e tente de novo.', $cpf);
                }
                $r = mcp_voluntario_aceitar($id, $quem, $cpf);
                if (!$r['ok']) {
                    co_confirmar($id, 'aprovar', (string) $r['erro'], $cpf);
                }
            }
            $c = mcp_voluntario_aprovacao_emitir($id, $quem);
            mcp_registrar(null, 'coordenacao_voluntario', "aprovar · #$id · " . ($c['ok'] ? 'ok' : 'erro'));
            if (!$c['ok']) {
                co_ficha($id, co_aviso('A ficha foi aprovada, mas o código não foi gerado agora. Avise o Matheus.', 'erro'));
            }
            co_aprovada(mcp_voluntario_por_id($id) ?? $f, (string) $c['codigo']);
        case 'recusar':
            if (!in_array((string) $f['status'], CO_PARA_DECIDIR, true)) {
                co_ficha($id, co_aviso('Essa ficha já foi decidida.', 'info'));
            }
            $erro = mcp_voluntario_mudar_status($id, 'recusada', $quem, 'Recusada pelo link da coordenação.');
            mcp_registrar(null, 'coordenacao_voluntario', "recusar · #$id · " . ($erro === null ? 'ok' : 'erro'));
            if ($erro !== null) {
                co_ficha($id, co_aviso(co_e($erro), 'erro'));
            }
            $proxima = co_proxima($id);
            if ($proxima !== null) {
                co_ficha($proxima, co_aviso('A ficha de ' . co_e((string) $f['nome']) . ' foi recusada. Esta é a próxima ficha para decidir.', 'ok'));
            }
            co_lista('decidir', co_aviso('A ficha de ' . co_e((string) $f['nome']) . ' foi recusada.', 'ok'));
        case 'email':
            $mensagem = mcp_texto_longo($_POST['mensagem'] ?? '', CO_EMAIL_MAX);
            $erro = co_email_enviar($f, $mensagem);
            co_ficha($id, $erro === null
                ? co_aviso('E-mail enviado para ' . co_e((string) $f['email']) . '. Ele ficou anotado no fim da ficha.', 'ok')
                : co_aviso(co_e($erro), 'erro'), $erro === null ? '' : $mensagem);
        case 'desfazer':
            if (!in_array((string) $f['status'], CO_RECUSADOS, true)) {
                co_ficha($id);
            }
            $erro = mcp_voluntario_mudar_status($id, 'em_analise', $quem, 'Recusa desfeita pelo link da coordenação.');
            mcp_registrar(null, 'coordenacao_voluntario', "desfazer · #$id · " . ($erro === null ? 'ok' : 'erro'));
            co_ficha($id, co_aviso($erro === null ? 'Pronto: a ficha voltou para "Para decidir".' : co_e($erro), $erro === null ? 'ok' : 'erro'));
        default:
            co_ficha($id);
    }
}

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $passo = (string) ($_GET['passo'] ?? '');
    if ($passo === 'aprovar' || $passo === 'recusar') {
        co_confirmar($id, $passo);
    }
    co_ficha($id);
}
co_lista((string) ($_GET['ver'] ?? 'decidir'));
