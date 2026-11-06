<?php
/**
 * Envio da foto pela pessoa da ficha (06/11/2026): o link que a coordenadora manda às fichas sem selfie
 * (lib/voluntario_foto.php). ?f=<id>&k=<assinatura>. Mostra o primeiro nome, um campo de foto e o botão "Enviar a foto";
 * a foto passa pelo mesmo tratamento do cadastro (mcp_voluntario_selfie_entrada e _preparar: confere o formato, gira,
 * reduz a 800 px e regrava em JPG sem metadados) e vai para mcp_voluntarios_selfies. O link só vale enquanto a ficha não
 * tem foto; depois responde "já recebemos". Link inválido: 404. Até 10 envios por hora por IP. Sem JavaScript.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/voluntario_foto.php';

const VF_LIMITE = [10, 3600];

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

function vf_pagina(string $titulo, string $corpo, int $status = 200): never
{
    http_response_code($status);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><meta name="theme-color" content="#c8102e">'
        . '<title>' . mcp_escapar($titulo) . '</title><style>'
        . ':root{--vermelho:#c8102e;--texto:#111;--suave:#444;--linha:#cfcfcf}*{box-sizing:border-box}html{font-size:19px}'
        . 'body{margin:0;background:#f4f4f2;color:var(--texto);font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;line-height:1.5}'
        . '.topo{background:var(--vermelho);color:#fff;padding:14px 16px;font-weight:700}.topo small{display:block;font-weight:400;font-size:.8rem}'
        . 'main{max-width:560px;margin:0 auto;padding:22px 16px 40px}h1{font-size:1.5rem;line-height:1.25;margin:0 0 10px}'
        . '.caixa{background:#fff;border:2px solid var(--linha);border-radius:18px;padding:20px;margin:16px 0}'
        . 'label.foto{display:block;border:3px dashed var(--linha);border-radius:16px;padding:22px;text-align:center;font-weight:700;cursor:pointer}'
        . 'input[type=file]{display:block;width:100%;margin-top:12px;font:inherit}'
        . 'button{width:100%;min-height:68px;margin-top:18px;border:0;border-radius:14px;background:var(--vermelho);color:#fff;font:inherit;font-size:1.2rem;font-weight:800;cursor:pointer}'
        . '.aviso{border-radius:14px;padding:14px 18px;font-weight:600}.ok{background:#dff3e5;border:2px solid #9fd3ae;color:#0f4a22}.erro{background:#ffe1e5;border:2px solid #f2a5b1;color:#7a0a1c}'
        . 'ul{padding-left:20px;margin:8px 0 0}.nota{color:var(--suave);font-size:.85rem}'
        . '</style></head><body><div class="topo">Cruz Vermelha Brasileira<small>Rio de Janeiro · Voluntariado</small></div><main>' . $corpo . '</main></body></html>';
    exit;
}

function vf_nao_encontrado(): never
{
    vf_pagina('Link não encontrado', '<h1>Link não encontrado</h1><p>Confira se o link está completo. Se continuar assim, responda à mensagem da coordenação.</p>', 404);
}

$id = (int) ($_POST['f'] ?? $_GET['f'] ?? 0);
$k = mcp_texto($_POST['k'] ?? $_GET['k'] ?? '', 64);
if (!mcp_vf_valido($id, $k)) {
    vf_nao_encontrado();
}
$f = mcp_voluntario_por_id($id);
if (!$f) {
    vf_nao_encontrado();
}
$primeiro = mcp_primeiro_nome((string) $f['nome']);
$recebida = '<h1>Já recebemos a sua foto, ' . mcp_escapar($primeiro) . '!</h1><p>Ela está na sua ficha de voluntário. Não precisa mandar de novo. Obrigado!</p>';
if (mcp_voluntario_selfie($id) !== null) {
    vf_pagina('Foto recebida', $recebida);
}

$formulario = static function (string $erro = '') use ($id, $k, $primeiro): never {
    vf_pagina('Envie a sua foto',
        ($erro !== '' ? '<div class="aviso erro" role="alert">' . mcp_escapar($erro) . '</div>' : '')
        . '<h1>Olá, ' . mcp_escapar($primeiro) . '! Envie a sua foto</h1>'
        . '<p>Para completar a sua ficha de voluntário, precisamos de uma foto do seu rosto.</p>'
        . '<div class="caixa"><b>Como tirar a foto:</b><ul><li>de frente, mostrando o rosto inteiro;</li><li>com boa luz;</li><li>sem óculos escuros, boné ou chapéu.</li></ul></div>'
        . '<form method="post" action="voluntario-foto.php" enctype="multipart/form-data">'
        . '<input type="hidden" name="f" value="' . $id . '"><input type="hidden" name="k" value="' . mcp_escapar($k) . '">'
        . '<label class="foto">Toque aqui para tirar a selfie ou escolher uma foto'
        . '<input type="file" name="selfie" accept="image/jpeg,image/png" capture="user" required></label>'
        . '<button type="submit">Enviar a foto</button></form>'
        . '<p class="nota">A foto serve só para a equipe reconhecer você na entrevista e no CBFI. Ela não é publicada nem usada em divulgação. '
        . 'Veja a <a href="/privacidade/">Política de Privacidade</a>.</p>');
};

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    $formulario();
}
[$maximo, $janela] = VF_LIMITE;
if (mcp_contar_eventos_recentes('voluntario_foto_envio', mcp_ip_balde(), $janela) >= $maximo) {
    $formulario('Muitas tentativas. Espere um pouco e tente de novo.');
}
mcp_registrar(null, 'voluntario_foto_envio', mcp_ip_balde());
$entrada = mcp_voluntario_selfie_entrada(null, isset($_FILES['selfie']) && is_array($_FILES['selfie']) ? $_FILES['selfie'] : null);
if ($entrada === null) {
    $formulario('Escolha uma foto antes de tocar em "Enviar a foto".');
}
if (isset($entrada['erro'])) {
    $formulario((string) $entrada['erro']);
}
$foto = mcp_voluntario_selfie_preparar((string) $entrada['bruto']);
unset($entrada);
if (!$foto['ok']) {
    $formulario($foto['motivo'] === 'gd' ? 'Não conseguimos receber a foto agora. Tente de novo mais tarde.' : (string) $foto['erro']);
}
mcp_voluntario_selfie_gravar($id, $foto);
mcp_voluntario_nota_registrar($id, 'A própria pessoa', 'Mandou a foto pelo link do pedido de foto.');
mcp_registrar(null, 'voluntario_foto_recebida', "#$id");
vf_pagina('Foto recebida', '<div class="aviso ok" role="status">Foto recebida!</div>' . $recebida);
