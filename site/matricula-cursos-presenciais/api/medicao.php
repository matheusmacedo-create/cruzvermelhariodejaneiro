<?php
/**
 * POST (JSON): repasse dos eventos de página para a API de Conversões da Meta (PageView, ViewContent,
 * InitiateCheckout e, nas páginas de evento, Schedule), com o mesmo id que o Pixel usou no navegador. Do navegador
 * vêm só o evento, o id, o curso e, no InitiateCheckout, o plano marcado (conferido contra a lista do servidor). Quem
 * chama é o bloco de medição das páginas (window.cvrjMedicao.servidor), só depois do "sim" para marketing.
 *
 * Responde sempre 204 e sem corpo: a página não espera nem lê a resposta. Sem token configurado, sem
 * consentimento de marketing no cookie, origem de fora do site, evento desconhecido ou freio estourado, nada
 * é enviado. O envio à Meta acontece depois da resposta (lib/meta.php).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Por IP (o /64 no IPv6), em 10 minutos: folga para quem navega, freio para quem usa o endereço como atalho.
const MCP_MEDICAO_LIMITE = [120, 600];
// Por IP, por minuto: um endereço só não ocupa o teto do site inteiro.
const MCP_MEDICAO_LIMITE_POR_MINUTO = 20;
// Teto do site inteiro por minuto, antes do freio por IP: quem troca de IP (proxies, um bloco IPv6) não passa
// dele, e as duas contagens leem no máximo umas centenas de linhas do índice (tipo, criado_em). Acima disso,
// os eventos de página ficam só com o Pixel.
const MCP_MEDICAO_TETO_POR_MINUTO = 120;
const MCP_MEDICAO_EVENTOS = ['PageView', 'ViewContent', 'InitiateCheckout', 'Schedule'];
// Páginas que não são de curso: a página manda só a chave ("conteudo"); o que vai à Meta sai daqui, como o
// catálogo faz com os cursos. Schedule é o "salvar na agenda" da página do evento.
const MCP_MEDICAO_CONTEUDOS = [
    'dia-das-criancas-2026' => [
        'eventos' => ['ViewContent', 'Schedule'],
        'dados' => ['content_name' => 'Dia das Crianças na Praça', 'content_category' => 'evento', 'content_ids' => ['dia-das-criancas-2026']],
    ],
];

function mcp_medicao_fim(): never
{
    http_response_code(204);
    header('Cache-Control: no-store');
    exit;
}

mcp_exigir_metodo('POST');
// O mais barato primeiro: sem token ou sem "sim" para marketing, nem lê o corpo nem abre o banco.
if (!mcp_meta_configurada() || mcp_meta_marketing_no_cookie() !== true || !mcp_origem_do_site(false)) {
    mcp_medicao_fim();
}
if (!str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    mcp_medicao_fim();
}
$b = json_decode(mcp_corpo_bruto(4096) ?: '', true);
$evento = is_array($b) && is_string($b['evento'] ?? null) ? $b['evento'] : '';
$id = is_array($b) ? mcp_meta_id_valido($b['id'] ?? null) : null;
if (!in_array($evento, MCP_MEDICAO_EVENTOS, true) || $id === null) {
    mcp_medicao_fim();
}
// Página de evento: a chave tem de existir e o evento tem de ser um dos dela. Schedule só existe nelas.
$conteudo = null;
if (array_key_exists('conteudo', $b)) {
    $conteudo = is_string($b['conteudo']) ? (MCP_MEDICAO_CONTEUDOS[$b['conteudo']] ?? null) : null;
    if ($conteudo === null || !in_array($evento, $conteudo['eventos'], true)) {
        mcp_medicao_fim();
    }
} elseif ($evento === 'Schedule') {
    mcp_medicao_fim();
}

[$maximo, $janela] = MCP_MEDICAO_LIMITE;
$balde = mcp_ip_balde();
if (mcp_contar_eventos_do_tipo('meta_repasse', 60) >= MCP_MEDICAO_TETO_POR_MINUTO) {
    // Pico de campanha ou abuso: o operador fica sabendo (uma linha a cada 10 minutos, no log e em mcp_eventos).
    if (mcp_contar_eventos_recentes('meta_capi_aviso', 'teto', 600) === 0) {
        mcp_registrar(null, 'meta_capi_aviso', 'teto');
        error_log('[matricula] API de Conversões: teto de ' . MCP_MEDICAO_TETO_POR_MINUTO . ' repasses por minuto atingido; os eventos de página ficam só com o Pixel');
    }
    mcp_medicao_fim();
}
if (mcp_contar_eventos_recentes('meta_repasse', $balde, 60) >= MCP_MEDICAO_LIMITE_POR_MINUTO
    || mcp_contar_eventos_recentes('meta_repasse', $balde, $janela) >= $maximo) {
    mcp_medicao_fim();
}
mcp_registrar(null, 'meta_repasse', $balde);

// Os dados do curso vêm do catálogo e dos planos que o servidor oferece, não da página (pagar tudo, spec 4.1, T20):
// ViewContent vale o plano padrão do curso (taxa + matrícula quando a opção 1 está à venda) e InitiateCheckout, o plano
// marcado, se ele estiver na lista do servidor (lib/meta.php, mcp_meta_dados_medicao).
$custom = [];
if ($conteudo !== null) {
    $custom = $conteudo['dados'];
} elseif ($evento !== 'PageView') {
    $curso = mcp_curso(mcp_texto($b['curso'] ?? '', 80));
    if (!$curso) {
        mcp_medicao_fim();
    }
    $custom = mcp_meta_dados_medicao($curso, $evento, is_string($b['plano'] ?? null) ? mcp_texto($b['plano'], 20) : null);
}

$url = mcp_meta_url_limpa(is_string($b['url'] ?? null) ? $b['url'] : ($_SERVER['HTTP_REFERER'] ?? null), mcp_site_url() . '/');
parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
$contexto = mcp_meta_contexto(is_string($consulta['fbclid'] ?? null) ? $consulta['fbclid'] : null);
mcp_meta_enfileirar(mcp_meta_evento($evento, $id, mcp_meta_user_data([], $contexto), $custom, $url));
mcp_medicao_fim();
