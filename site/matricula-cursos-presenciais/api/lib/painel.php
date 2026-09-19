<?php
/**
 * Painel de contatos (api/painel.php): segredo guardado no banco, links assinados e sessão por cookie.
 *
 * Dois jeitos de entrar, sem senha para configurar:
 *  - link direto de UM contato, que vai no aviso à equipe (?c=<id>&e=<validade>&k=<assinatura>), vale
 *    90 dias e abre só a página de resposta daquele contato;
 *  - link de entrada enviado ao e-mail da equipe (EMAIL_CONTATO ou PAINEL_EMAILS), vale 20 minutos e
 *    abre uma sessão de 12 horas (cookie assinado, HttpOnly, Secure) com a lista completa.
 * Quem lê a caixa da equipe é quem pode responder: a caixa é a credencial.
 */
declare(strict_types=1);

const MCP_PAINEL_LINK_CONTATO_DIAS = 90;
const MCP_PAINEL_LINK_ENTRADA_MINUTOS = 20;
const MCP_PAINEL_SESSAO_HORAS = 12;
const MCP_PAINEL_COOKIE = 'mcp_painel';
const MCP_PAINEL_COOKIE_ASSINATURA = 'mcp_painel_assinatura';
const MCP_PAINEL_CAMINHO_COOKIE = '/matricula-cursos-presenciais/api/';

function mcp_painel_url(): string
{
    return mcp_site_url() . '/matricula-cursos-presenciais/api/painel.php';
}

function mcp_painel_assinar(string $dados): string
{
    return substr(hash_hmac('sha256', $dados, mcp_segredo('painel')), 0, 40);
}

function mcp_painel_conferir(string $dados, string $assinatura): bool
{
    return $assinatura !== '' && hash_equals(mcp_painel_assinar($dados), $assinatura);
}

/** Link direto para responder um contato (vai no e-mail à equipe). */
function mcp_painel_link_contato(int $id, ?int $agora = null): string
{
    $exp = ($agora ?? time()) + MCP_PAINEL_LINK_CONTATO_DIAS * 86400;
    return mcp_painel_url() . '?c=' . $id . '&e=' . $exp . '&k=' . mcp_painel_assinar("c|$id|$exp");
}

/** Id do contato autorizado pela query (?c&e&k), ou null. */
function mcp_painel_contato_autorizado(array $q): ?int
{
    $id = (int) ($q['c'] ?? 0);
    $exp = (int) ($q['e'] ?? 0);
    $k = mcp_texto($q['k'] ?? '', 40);
    if ($id <= 0 || $exp < time() || !mcp_painel_conferir("c|$id|$exp", $k)) {
        return null;
    }
    return $id;
}

/** Query string que mantém o acesso por link direto ao redirecionar (GET depois do POST). */
function mcp_painel_query_contato(int $id, array $q): string
{
    return 'c=' . $id . '&e=' . (int) ($q['e'] ?? 0) . '&k=' . rawurlencode(mcp_texto($q['k'] ?? '', 40));
}

/** Link de entrada (vale 20 minutos) enviado ao e-mail da equipe. */
function mcp_painel_link_entrada(string $email, ?int $agora = null): string
{
    $exp = ($agora ?? time()) + MCP_PAINEL_LINK_ENTRADA_MINUTOS * 60;
    return mcp_painel_url() . '?entrar=' . rawurlencode($email) . '&e=' . $exp . '&k=' . mcp_painel_assinar("s|$email|$exp");
}

function mcp_painel_entrada_valida(string $email, int $exp, string $k): bool
{
    return $email !== '' && $exp >= time() && mcp_painel_conferir("s|$email|$exp", $k);
}

/** E-mails que podem pedir o link de entrada. */
function mcp_painel_emails_permitidos(): array
{
    $lista = (string) mcp_cfg('PAINEL_EMAILS', '');
    $emails = $lista !== '' ? (preg_split('/[\s,;]+/', $lista) ?: []) : [mcp_email_contato_endereco(), (string) mcp_cfg('EMAIL_SECRETARIA', '')];
    return array_values(array_unique(array_filter(array_map(static fn($e) => mb_strtolower(trim((string) $e)), $emails))));
}

function mcp_painel_cookie_opcoes(int $expira): array
{
    // Secure sempre que a página veio por HTTPS (produção); fora disso só em teste local por HTTP.
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ['expires' => $expira, 'path' => MCP_PAINEL_CAMINHO_COOKIE, 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax'];
}

/** Abre a sessão: cookie assinado com o e-mail e a validade. */
function mcp_painel_sessao_abrir(string $email): void
{
    $exp = time() + MCP_PAINEL_SESSAO_HORAS * 3600;
    $valor = rtrim(strtr(base64_encode((string) json_encode(['u' => $email, 'e' => $exp])), '+/', '-_'), '=');
    $cookie = $valor . '.' . mcp_painel_assinar("k|$valor");
    setcookie(MCP_PAINEL_COOKIE, $cookie, mcp_painel_cookie_opcoes($exp));
    $_COOKIE[MCP_PAINEL_COOKIE] = $cookie;
}

/** E-mail de quem está logado, ou null. */
function mcp_painel_sessao(): ?string
{
    $cookie = (string) ($_COOKIE[MCP_PAINEL_COOKIE] ?? '');
    if ($cookie === '' || !str_contains($cookie, '.')) {
        return null;
    }
    [$valor, $k] = explode('.', $cookie, 2);
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $valor) || !mcp_painel_conferir("k|$valor", $k)) {
        return null;
    }
    $dados = json_decode((string) base64_decode(strtr($valor, '-_', '+/'), true), true);
    if (!is_array($dados) || (int) ($dados['e'] ?? 0) < time() || empty($dados['u']) || !is_string($dados['u'])) {
        return null;
    }
    return $dados['u'];
}

function mcp_painel_sessao_fechar(): void
{
    setcookie(MCP_PAINEL_COOKIE, '', mcp_painel_cookie_opcoes(1));
    unset($_COOKIE[MCP_PAINEL_COOKIE]);
}

/** Token dos formulários: amarrado a quem age (sessão ou link do contato), à ação e ao contato. */
function mcp_painel_csrf(string $quem, string $acao, int $id): string
{
    return mcp_painel_assinar("f|$quem|$acao|$id");
}
