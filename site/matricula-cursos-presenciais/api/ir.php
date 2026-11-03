<?php
/**
 * Link curto dos botões da recuperação (WhatsApp e e-mail), 01/11/2026: /matricula-cursos-presenciais/api/ir.php/<w|e>/
 * <slug ou cursos>/<campanha>[/<token>] responde 302 para o checkout do curso com a query de sempre (curso, utm_source
 * whatsapp|email, utm_medium=recuperacao, utm_campaign; na rec3cupom também via=agenda&cupom=<o cupom do curso em MCP_RW_OFERTAS>), ou para a página de
 * matrícula sem curso. A regra está em mcp_rw_link_destino (lib/recuperacao_whatsapp.php).
 *
 * Por quê: o modelo aprovado na Meta tem o botão https://cruzvermelhariodejaneiro.org/{{1}}, e o {{1}} levava o caminho do
 * checkout com "?" e "&". Se a Meta ou o aplicativo codificam a variável (%3F, %26), a URL vira /checkout/%3Fcurso%3D… e
 * dá 404. O link curto só tem letras, números, "-", "." e "/".
 *
 *  - Nunca dá erro: fonte, curso ou campanha que não conferem, banco fora ou qualquer exceção levam à página de matrícula.
 *  - O token (4º pedaço) é o do pré-preenchimento: se confere e não venceu, vai num cookie HttpOnly só da api/
 *    (MCP_RW_COOKIE, poucas horas), que o api/recuperacao-dados.php lê quando o checkout pede. Ele não entra na URL do
 *    checkout, para não ir parar no GA4, no Pixel nem no Referer. Token inválido: redireciona igual, sem cookie.
 *  - Cada abertura fica em mcp_eventos ('recuperacao_link', só fonte/curso/campanha; nenhum dado pessoal): o painel
 *    mostra quantas vezes os links foram abertos. Leitores de e-mail que abrem links sozinhos também contam.
 *  - Direto ao pagamento (01/11/2026): com o PIX da inscrição ainda aberto, o link do carrinho abre a tela desse PIX
 *    (pendente/?t=…), como o lembrete do PIX por e-mail; as campanhas mat1 e mat2 (matrícula pendente) levam à tela de
 *    pagar a matrícula (pagar-matricula/), com o token no mesmo cookie.
 */
declare(strict_types=1);

$destino = '/matricula-cursos-presenciais/';
try {
    require __DIR__ . '/lib.php';
    require_once __DIR__ . '/lib/recuperacao_whatsapp.php';
    // O caminho depois de ir.php: o PATH_INFO; sem ele (servidor que não repassa), o pedaço do REQUEST_URI.
    $caminho = (string) ($_SERVER['PATH_INFO'] ?? $_SERVER['ORIG_PATH_INFO'] ?? '');
    if ($caminho === '' && preg_match('~/ir\.php(/[^?#]*)~', (string) ($_SERVER['REQUEST_URI'] ?? ''), $m)) {
        $caminho = rawurldecode($m[1]);
    }
    $link = mcp_rw_link_destino($caminho);
    $destino = $link['destino'];
    if ($link['valido']) {
        $partes = explode('/', trim($caminho, '/'));
        mcp_registrar(null, 'recuperacao_link', implode('/', array_slice($partes, 0, 3)));
    }
    $ref = $link['token'] !== '' ? mcp_rw_token_ref($link['token'], time()) : null;
    // Com o PIX ainda aberto, direto à tela dele (o QR), em vez do checkout (01/11/2026, mcp_rw_link_pix_direto).
    if ($ref !== null && $link['valido']) {
        [$fonte, , $campanha] = explode('/', trim($caminho, '/'));
        $utm = 'utm_source=' . MCP_RW_LINK_FONTES[$fonte] . '&utm_medium=recuperacao&utm_campaign=' . $campanha;
        $destino = mcp_rw_link_pix_direto($ref, $campanha, $utm, time()) ?? $destino;
    }
    if ($ref !== null) {
        setcookie(MCP_RW_COOKIE, $link['token'], [
            'expires' => time() + MCP_RW_COOKIE_S, 'path' => '/matricula-cursos-presenciais/api/',
            'secure' => str_starts_with(mcp_site_url(), 'https://'), 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
} catch (Throwable $e) {
    // O destino já calculado vale (banco fora só tira o cookie e a contagem); sem ele, a página de matrícula.
    error_log('[matricula] link da recuperação: ' . get_class($e) . ': ' . $e->getMessage());
}
http_response_code(302);
header('Location: ' . $destino);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
