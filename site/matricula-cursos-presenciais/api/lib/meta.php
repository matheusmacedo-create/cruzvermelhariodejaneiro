<?php
/**
 * API de Conversões da Meta (Conversions API): os eventos do funil saem também pelo servidor do site, com o
 * mesmo event_id que o Pixel usa no navegador, e a Meta junta os dois num evento só.
 *
 * Por quê: bloqueador de anúncio, Safari e navegadores com rastreamento restrito derrubam parte dos eventos
 * que dependem do navegador. E o servidor tem nome, e-mail e telefone da inscrição para a Meta reconhecer a
 * pessoa (sempre em hash SHA-256), o que melhora a "qualidade da correspondência" das campanhas. O CPF nunca vai.
 *
 * Só com consentimento de marketing, o mesmo m=1 do cookie cvrj_consentimento que libera o Pixel, dado com o
 * texto atual do aviso (r=MCP_META_REVISAO no cookie, que só o consentimento.js atual grava):
 *  - evento que nasce de um pedido do próprio aluno (cobrança, chat, repasse de PageView, ViewContent e
 *    InitiateCheckout por api/medicao.php): vale o cookie deste pedido, e IP, navegador e _fbp/_fbc saem dele;
 *  - Purchase, que pode ser confirmado pelo postback da Unicopag (sem navegador nenhum): vale a escolha
 *    gravada na inscrição quando a cobrança foi criada (meta_marketing, meta_revisao). Depois disso, quem
 *    abre o link da inscrição só consegue retirar a permissão, nunca dá-la: o link pode estar com outra
 *    pessoa (mcp_meta_atualizar_escolha). Os sinais guardados com ela (IP, navegador, _fbp/_fbc) servem só
 *    para esse evento: saem do banco quando ele é enviado ou, no máximo, em 8 dias (mcp_meta_faxina).
 * O token da inscrição (que abre as páginas de acompanhamento) nunca vai à Meta: os ids de evento da
 * inscrição saem de um hash dele (mcp_meta_id_da_compra).
 * Sem token (META_CAPI_TOKEN, em config.php ou config-meta.php), nada acontece e nada é gravado.
 *
 * Nunca lança nem atrasa o aluno: os eventos entram numa fila e saem depois da resposta
 * (fastcgi_finish_request/litespeed_finish_request), com tempo curto. O resultado de cada envio ligado a uma
 * inscrição fica em mcp_eventos (meta_capi / meta_capi_falha); dos repasses anônimos, só as falhas.
 */
declare(strict_types=1);

const MCP_META_PIXEL_PADRAO = '2224500131617302';
// A Conversions API segue o calendário da Marketing API: v24 e anteriores já estão vencidas ou vencendo (out/2026).
const MCP_META_VERSAO_PADRAO = 'v25.0';
const MCP_META_CONEXAO_SEGUNDOS = 3;
const MCP_META_TOTAL_SEGUNDOS = 6;
/** event_id que o navegador pode mandar: o mesmo formato que window.cvrjMedicao.novoId() gera. */
const MCP_META_ID = '/^[A-Za-z0-9][A-Za-z0-9._:-]{7,79}\z/';
/**
 * Revisão do texto do aviso de cookies que vale para a API de Conversões: o consentimento.js atual grava r=2 no
 * cookie (REVISAO lá). Um "sim" sem ela não vale: foi dado no texto anterior, que dizia que nome, e-mail e
 * telefone nunca iam à Meta, ou por outro aviso que grava o mesmo cookie (o da Punção, um consentimento.js
 * antigo guardado no cache). O t do cookie não serve para isso: diz quando, não qual texto. Mudou o texto do
 * que vai à Meta, sobe aqui e em consentimento.js: o aviso pergunta de novo, e as inscrições com o "sim"
 * anterior deixam de mandar o Purchase.
 */
const MCP_META_REVISAO = 2;
/** Sinais guardados na inscrição só para o Purchase: depois disso, saem (minimização). */
const MCP_META_GUARDA_SEGUNDOS = 8 * 86400;
/** Parâmetros de endereço que podem ir à Meta. O resto sai, a começar pelo t= (token que abre a inscrição). */
const MCP_META_PARAMETROS_DA_URL = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid', 'curso'];

function mcp_meta_token(): string
{
    return trim((string) mcp_cfg('META_CAPI_TOKEN', ''));
}

function mcp_meta_pixel(): string
{
    $pixel = trim((string) mcp_cfg('META_PIXEL_ID', MCP_META_PIXEL_PADRAO));
    return preg_match('/^\d{6,20}$/', $pixel) ? $pixel : '';
}

function mcp_meta_configurada(): bool
{
    return mcp_meta_token() !== '' && mcp_meta_pixel() !== '';
}

/**
 * A escolha de marketing no cookie deste pedido: ['marketing' => bool, 'em' => unix, 'revisao' => int] ou null
 * (sem escolha válida). Um "sim" sem a revisão atual do texto (r) ou com data no futuro vale como sem escolha;
 * um "não" vale sempre.
 */
function mcp_meta_escolha_no_cookie(): ?array
{
    $bruto = $_COOKIE['cvrj_consentimento'] ?? null;
    if (!is_string($bruto) || $bruto === '' || strlen($bruto) > 200) {
        return null;
    }
    // O aviso grava encodeURIComponent('v=1&e=1&m=1&t=…'); o PHP já desfaz a codificação ao montar $_COOKIE.
    parse_str($bruto, $partes);
    if (($partes['v'] ?? null) !== '1' || !in_array($partes['m'] ?? null, ['0', '1'], true)) {
        return null;
    }
    $em = is_string($partes['t'] ?? null) && ctype_digit($partes['t']) && strlen($partes['t']) <= 12 ? (int) $partes['t'] : 0;
    $revisao = is_string($partes['r'] ?? null) && ctype_digit($partes['r']) && strlen($partes['r']) <= 3 ? (int) $partes['r'] : 0;
    if ($partes['m'] === '1' && ($revisao < MCP_META_REVISAO || $em <= 0 || $em > time() + 86400)) {
        return null;
    }
    return ['marketing' => $partes['m'] === '1', 'em' => $em, 'revisao' => $revisao];
}

/** true (sim, dado com o texto atual), false (não) ou null (sem escolha que valha). */
function mcp_meta_marketing_no_cookie(): ?bool
{
    $escolha = mcp_meta_escolha_no_cookie();
    return $escolha === null ? null : $escolha['marketing'];
}

/** Data da escolha para o banco (prova do consentimento); sem t válido, a hora do pedido. */
function mcp_meta_escolha_em(?array $escolha): ?string
{
    if ($escolha === null) {
        return null;
    }
    $em = $escolha['em'] > 0 && $escolha['em'] <= time() + 86400 ? $escolha['em'] : time();
    return gmdate('Y-m-d H:i:s', $em);
}

function mcp_meta_id_valido(mixed $id): ?string
{
    return is_string($id) && preg_match(MCP_META_ID, $id) ? $id : null;
}

/**
 * _fbp e _fbc do navegador (fb.<n>.<criado em ms>.<valor>), só se tiverem o formato da Meta. O _fbc leva o
 * fbclid inteiro, que passa de 200 caracteres nos anúncios de hoje (meta_fbc guarda até 600).
 */
function mcp_meta_cookie_fb(string $nome): ?string
{
    $valor = $_COOKIE[$nome] ?? null;
    if (!is_string($valor) || strlen($valor) > 600) {
        return null;
    }
    return preg_match('/^fb\.[0-9]\.[0-9]{10,16}\.[A-Za-z0-9_-]{1,560}\z/', $valor) ? $valor : null;
}

/**
 * fbc montado a partir do fbclid de um anúncio, quando o cookie _fbc ainda não existe. A Meta proíbe mexer
 * no fbclid: as páginas e o banco guardam até 255 caracteres, então um fbclid desse tamanho pode ter sido
 * cortado e é descartado.
 */
function mcp_meta_fbc_do_fbclid(?string $fbclid, ?int $quandoMs = null): ?string
{
    $fbclid = (string) $fbclid;
    if ($fbclid === '' || strlen($fbclid) >= 255 || !preg_match('/^[A-Za-z0-9_-]+\z/', $fbclid)) {
        return null;
    }
    return 'fb.1.' . ($quandoMs ?? (int) floor(microtime(true) * 1000)) . '.' . $fbclid;
}

/**
 * Endereço que vai à Meta: só do próprio site, só com os parâmetros de campanha. O t= das páginas de
 * acompanhamento abre os dados da inscrição e nunca sai daqui.
 */
function mcp_meta_url_limpa(?string $url, string $padrao): string
{
    if (is_string($url) && str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        $url = mcp_site_url() . $url; // caminho do próprio site (o chat guarda só caminho e consulta)
    }
    $partes = is_string($url) && strlen($url) <= 2000 ? parse_url($url) : false;
    $host = is_array($partes) ? strtolower((string) ($partes['host'] ?? '')) : '';
    $hostDoSite = strtolower((string) parse_url(mcp_site_url(), PHP_URL_HOST));
    if (!is_array($partes) || !in_array($host, [$hostDoSite, 'www.' . $hostDoSite], true) || !in_array($partes['scheme'] ?? '', ['https', 'http'], true)) {
        return $padrao;
    }
    $caminho = (string) ($partes['path'] ?? '/');
    if (!preg_match('#^/[A-Za-z0-9/._~%-]*$#', $caminho)) {
        $caminho = '/';
    }
    parse_str((string) ($partes['query'] ?? ''), $consulta);
    $mantidos = [];
    foreach (MCP_META_PARAMETROS_DA_URL as $chave) {
        if (isset($consulta[$chave]) && is_string($consulta[$chave]) && $consulta[$chave] !== '') {
            $mantidos[$chave] = mb_substr($consulta[$chave], 0, 255);
        }
    }
    return 'https://' . $host . $caminho . ($mantidos ? '?' . http_build_query($mantidos) : '');
}

/** SHA-256 do valor já normalizado (minúsculas, sem espaços nas pontas). Vazio não vai. */
function mcp_meta_hash(string $valor): ?string
{
    $valor = trim(mb_strtolower($valor, 'UTF-8'));
    return $valor === '' ? null : hash('sha256', $valor);
}

/** Telefone com o país na frente (55), só dígitos: o formato da Meta. DDD 55 (RS) não confunde: conta o tamanho. */
function mcp_meta_telefone(string $telefone): string
{
    $d = mcp_digitos($telefone);
    if (strlen($d) === 10 || strlen($d) === 11) {
        return '55' . $d;
    }
    return (strlen($d) === 12 || strlen($d) === 13) && str_starts_with($d, '55') ? $d : '';
}

/** Primeiro nome e o resto, como o app da Punção (mesmo conjunto de dados: o mesmo formato de hash). */
function mcp_meta_nome_e_sobrenome(string $nome): array
{
    $partes = preg_split('/\s+/u', trim($nome)) ?: [];
    $primeiro = (string) array_shift($partes);
    return [$primeiro, implode(' ', $partes)];
}

/**
 * Sinais do navegador deste pedido. Só chame depois de conferido o consentimento, e nunca num postback de
 * outro servidor (o IP e o navegador seriam os dele).
 */
function mcp_meta_contexto(?string $fbclid = null): array
{
    $fbc = mcp_meta_cookie_fb('_fbc') ?? mcp_meta_fbc_do_fbclid($fbclid);
    return array_filter([
        'client_ip_address' => mcp_meta_ip(mcp_ip()),
        'client_user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512) ?: null,
        'fbp' => mcp_meta_cookie_fb('_fbp'),
        'fbc' => $fbc,
    ], static fn($v) => $v !== null);
}

function mcp_meta_ip(?string $ip): ?string
{
    return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}

/**
 * Id dos eventos da inscrição na Meta (Purchase, AddPaymentInfo com "-pagamento"), no servidor e no Pixel
 * (mcp_publico o entrega às páginas como id_compra). Sai de um hash do token e não o revela: o token abre a
 * inscrição (e-mail, PIX, o link de criar senha na escola) e não pode aparecer no Gerenciador de Eventos.
 */
function mcp_meta_id_da_compra(string $token): string
{
    return 'c.' . substr(hash('sha256', 'cvrj-meta-compra|' . $token), 0, 32);
}

/**
 * user_data: dados da pessoa em hash + sinais do navegador (que a Meta pede sem hash). O CPF nunca vai: os
 * Termos das Ferramentas de Negócios da Meta proíbem números de documento. O external_id é o SHA-256 do token
 * da inscrição (liga Lead, AddPaymentInfo e Purchase da mesma inscrição; o hash não revela o token).
 */
function mcp_meta_user_data(array $pessoa, array $contexto): array
{
    [$primeiro, $sobrenome] = mcp_meta_nome_e_sobrenome((string) ($pessoa['nome'] ?? ''));
    $dados = [
        'em' => mcp_meta_hash((string) ($pessoa['email'] ?? '')),
        'ph' => mcp_meta_hash(mcp_meta_telefone((string) ($pessoa['telefone'] ?? ''))),
        'fn' => mcp_meta_hash($primeiro),
        'ln' => mcp_meta_hash($sobrenome),
        'external_id' => mcp_meta_hash((string) ($pessoa['external_id'] ?? '')),
        'country' => !empty($pessoa) ? mcp_meta_hash('br') : null,
    ] + $contexto;
    return array_filter($dados, static fn($v) => $v !== null && $v !== '');
}

function mcp_meta_evento(string $nome, string $id, array $userData, array $customData, string $url, ?int $quando = null): array
{
    $evento = [
        'event_name' => $nome,
        'event_time' => $quando ?? time(),
        'event_id' => $id,
        'action_source' => 'website',
        'event_source_url' => $url,
        'user_data' => $userData,
    ];
    if ($customData) {
        $evento['custom_data'] = $customData;
    }
    return $evento;
}

/** Dados do curso que acompanham os eventos, iguais aos que o Pixel manda no navegador. */
function mcp_meta_dados_do_curso(string $slug, string $nome, int $centavos, array $extra = []): array
{
    return array_merge(['content_name' => $nome, 'content_ids' => [$slug], 'content_type' => 'product', 'value' => round($centavos / 100, 2), 'currency' => 'BRL'], $extra);
}

// ----------------------------------------------------------------------------- fila e envio

/** @return array<int, array{0: array, 1: ?int}> */
function &mcp_meta_fila(): array
{
    static $fila = [];
    return $fila;
}

function mcp_meta_enfileirar(array $evento, ?int $inscricaoId = null): void
{
    static $agendado = false;
    // Evento de site sem client_user_agent é inválido, e um evento inválido faz a Meta recusar o lote inteiro.
    if (($evento['action_source'] ?? '') === 'website' && empty($evento['user_data']['client_user_agent'])) {
        if ($inscricaoId !== null) {
            mcp_registrar($inscricaoId, 'meta_capi_falha', $evento['event_name'] . ' · sem a identificação do navegador: não enviado');
        }
        return;
    }
    $fila = &mcp_meta_fila();
    $fila[] = [$evento, $inscricaoId];
    if (!$agendado) {
        $agendado = true;
        register_shutdown_function('mcp_meta_descarregar');
    }
}

/** Depois da resposta: fecha a conexão com o navegador e manda a fila. Nada aqui pode lançar. */
function mcp_meta_descarregar(): void
{
    $fila = &mcp_meta_fila();
    if (!$fila) {
        return;
    }
    $itens = $fila;
    $fila = [];
    try {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        $resultado = mcp_meta_enviar(array_column($itens, 0));
        foreach ($itens as [$evento, $inscricaoId]) {
            $resumo = $evento['event_name'] . ' · HTTP ' . $resultado['http'] . ($resultado['ok'] ? '' : ' · ' . $resultado['erro']);
            if ($inscricaoId !== null) {
                mcp_registrar($inscricaoId, $resultado['ok'] ? 'meta_capi' : 'meta_capi_falha', $resumo);
                if ($resultado['ok'] && $evento['event_name'] === 'Purchase') {
                    // O Purchase era o único motivo para guardar os sinais do navegador na inscrição.
                    mcp_atualizar($inscricaoId, ['meta_fbp' => null, 'meta_fbc' => null, 'meta_ua' => null, 'meta_ip' => null]);
                }
            } elseif (!$resultado['ok'] && mcp_contar_eventos_recentes('meta_capi_falha', 'repasse', 600) === 0) {
                // Repasse anônimo (PageView etc.): uma falha a cada 10 minutos basta para avisar (token vencido, Meta fora).
                mcp_registrar(null, 'meta_capi_falha', 'repasse');
                error_log('[matricula] API de Conversões: ' . $resumo);
            }
        }
    } catch (Throwable $e) {
        error_log('[matricula] API de Conversões: ' . get_class($e) . ': ' . $e->getMessage());
    }
}

/**
 * POST /{versão}/{pixel}/events. O token vai no corpo (nunca no endereço, que pode parar em log).
 * @return array{ok: bool, http: int, recebidos: int, erro: string}
 */
function mcp_meta_enviar(array $eventos): array
{
    if (!$eventos || !mcp_meta_configurada()) {
        return ['ok' => false, 'http' => 0, 'recebidos' => 0, 'erro' => 'não configurada'];
    }
    $corpo = ['data' => array_values($eventos), 'access_token' => mcp_meta_token()];
    $teste = trim((string) mcp_cfg('META_CAPI_TESTE', ''));
    if ($teste !== '') {
        $corpo['test_event_code'] = $teste; // aba "Testar eventos" do Gerenciador; tire depois de conferir
    }
    $versao = (string) mcp_cfg('META_CAPI_VERSAO', MCP_META_VERSAO_PADRAO);
    if (!preg_match('/^v\d{1,3}\.\d$/', $versao)) {
        $versao = MCP_META_VERSAO_PADRAO;
    }
    // META_CAPI_URL existe para os testes (Graph falso local); em produção, a Meta.
    $base = rtrim((string) mcp_cfg('META_CAPI_URL', 'https://graph.facebook.com'), '/');
    $local = (bool) preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $base);
    if (!$local && !str_starts_with($base, 'https://')) {
        return ['ok' => false, 'http' => 0, 'recebidos' => 0, 'erro' => 'endereço inválido'];
    }
    $avisoDeVersao = '';
    $ch = curl_init($base . '/' . $versao . '/' . mcp_meta_pixel() . '/events');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => MCP_META_CONEXAO_SEGUNDOS,
        CURLOPT_TIMEOUT => MCP_META_TOTAL_SEGUNDOS,
        CURLOPT_PROTOCOLS => $local ? CURLPROTO_HTTP : CURLPROTO_HTTPS,
        // A Meta avisa por cabeçalho quando a versão está vencida ou vencendo (x-ad-api-version-warning).
        CURLOPT_HEADERFUNCTION => static function ($ch, string $linha) use (&$avisoDeVersao): int {
            if (stripos($linha, 'x-ad-api-version-warning:') === 0) {
                $avisoDeVersao = trim(substr($linha, 25));
            }
            return strlen($linha);
        },
    ]);
    unset($corpo);
    $resposta = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);
    if ($resposta === false) {
        return ['ok' => false, 'http' => 0, 'recebidos' => 0, 'erro' => 'sem resposta: ' . mb_substr($erroCurl, 0, 120)];
    }
    if ($avisoDeVersao !== '' && mcp_contar_eventos_recentes('meta_capi_aviso', 'versao', 86400) === 0) {
        mcp_registrar(null, 'meta_capi_aviso', 'versao');
        error_log("[matricula] API de Conversões: a Meta avisa sobre a versão $versao: " . mb_substr($avisoDeVersao, 0, 200) . ' (troque MCP_META_VERSAO_PADRAO ou defina META_CAPI_VERSAO)');
    }
    $dados = json_decode((string) $resposta, true);
    $recebidos = is_array($dados) ? (int) ($dados['events_received'] ?? 0) : 0;
    if ($http === 200 && $recebidos > 0) {
        return ['ok' => true, 'http' => $http, 'recebidos' => $recebidos, 'erro' => ''];
    }
    $mensagem = is_array($dados) && is_array($dados['error'] ?? null)
        ? trim(($dados['error']['error_user_msg'] ?? '') ?: (string) ($dados['error']['message'] ?? ''))
        : '';
    // A mensagem da Meta não traz o token; mesmo assim, nada que pareça um token entra no registro.
    $mensagem = (string) preg_replace('/EA[A-Za-z0-9]{20,}/', '[token]', $mensagem);
    return ['ok' => false, 'http' => $http, 'recebidos' => $recebidos, 'erro' => mb_substr($mensagem !== '' ? $mensagem : 'resposta inesperada', 0, 200)];
}

// ----------------------------------------------------------------------------- eventos da matrícula

/**
 * Colunas da inscrição nova: a escolha de marketing deste pedido e, só com "sim", os sinais do navegador
 * que o Purchase vai precisar se for confirmado pelo postback. Sem token configurado, nada é guardado.
 */
function mcp_meta_colunas_da_inscricao(?string $fbclid): array
{
    if (!mcp_meta_configurada()) {
        return [];
    }
    $escolha = mcp_meta_escolha_no_cookie();
    if ($escolha === null || !$escolha['marketing']) {
        return ['meta_marketing' => $escolha === null ? null : 0, 'meta_marketing_em' => mcp_meta_escolha_em($escolha)];
    }
    $contexto = mcp_meta_contexto($fbclid);
    return [
        'meta_marketing' => 1,
        'meta_marketing_em' => mcp_meta_escolha_em($escolha),
        'meta_revisao' => $escolha['revisao'],
        'meta_fbp' => $contexto['fbp'] ?? null,
        'meta_fbc' => $contexto['fbc'] ?? null,
        'meta_ua' => $contexto['client_user_agent'] ?? null,
        'meta_ip' => $contexto['client_ip_address'] ?? null,
    ];
}

/**
 * Alguém abriu o link da inscrição pendente (status.php) ou refez o PIX com o mesmo CPF (pagamentos.php) com
 * "não" para marketing no cookie: o "sim" guardado deixa de valer e os sinais do navegador saem. Só retira,
 * nunca dá: o link pode estar com outra pessoa (a mãe que paga, a secretaria pelo painel), e o "sim" de quem
 * abriu não é o do aluno. Depois do pagamento, nada muda: a escolha guardada é a prova do consentimento sob o
 * qual o Purchase saiu (ou não). Cada retirada fica em mcp_eventos, com a escolha anterior.
 */
function mcp_meta_atualizar_escolha(array $inscricao): void
{
    if (!mcp_meta_configurada() || empty($inscricao['id']) || ($inscricao['status'] ?? '') !== 'pendente'
        || (string) ($inscricao['meta_marketing'] ?? '') !== '1') {
        return;
    }
    $escolha = mcp_meta_escolha_no_cookie();
    if ($escolha === null || $escolha['marketing']) {
        return;
    }
    $em = mcp_meta_escolha_em($escolha);
    try {
        mcp_atualizar((int) $inscricao['id'], ['meta_marketing' => 0, 'meta_marketing_em' => $em, 'meta_revisao' => null,
            'meta_fbp' => null, 'meta_fbc' => null, 'meta_ua' => null, 'meta_ip' => null]);
        mcp_registrar((int) $inscricao['id'], 'meta_escolha', 'retirada: sim de ' . ($inscricao['meta_marketing_em'] ?? '?') . ' → não de ' . $em);
    } catch (Throwable $e) {
        error_log('[matricula] escolha de marketing não gravada: ' . $e->getMessage());
    }
}

/**
 * Lead (com o id que o navegador mandou) e AddPaymentInfo (id <id da compra>-pagamento, como no Pixel), logo
 * depois de a cobrança ser criada. PIX reaproveitado manda só o Lead: o AddPaymentInfo daquele PIX já foi.
 */
function mcp_meta_cobranca_criada(array $inscricao, array $aluno, ?string $idLead, bool $cobrancaNova): void
{
    if (!mcp_meta_configurada() || mcp_meta_marketing_no_cookie() !== true || empty($inscricao['id'])) {
        return;
    }
    $id = (int) $inscricao['id'];
    $contexto = mcp_meta_contexto($aluno['fbclid'] ?? null);
    if (!empty($inscricao['meta_fbc'])) {
        $contexto['fbc'] = (string) $inscricao['meta_fbc']; // o mesmo fbc em todos os eventos da inscrição
    }
    $pessoa = ['nome' => $aluno['nome'] ?? '', 'email' => $aluno['email'] ?? '', 'telefone' => $aluno['telefone'] ?? '', 'external_id' => (string) $inscricao['token']];
    $userData = mcp_meta_user_data($pessoa, $contexto);
    $url = mcp_meta_url_limpa($_SERVER['HTTP_REFERER'] ?? null, mcp_url_pagina('checkout') . '?curso=' . rawurlencode((string) $aluno['slug']));
    $nomeCurso = (string) $inscricao['curso_nome'];
    if ($idLead !== null) {
        mcp_meta_enfileirar(mcp_meta_evento('Lead', $idLead, $userData,
            ['content_name' => $nomeCurso, 'content_ids' => [(string) $aluno['slug']], 'content_category' => 'matricula-cursos-presenciais',
             'value' => round((int) $inscricao['inscricao_centavos'] / 100, 2), 'currency' => 'BRL'], $url), $id);
    }
    if ($cobrancaNova) {
        mcp_meta_enfileirar(mcp_meta_evento('AddPaymentInfo', mcp_meta_id_da_compra((string) $inscricao['token']) . '-pagamento', $userData,
            mcp_meta_dados_do_curso((string) $aluno['slug'], $nomeCurso, (int) $inscricao['total_centavos']), $url), $id);
    }
}

/**
 * Purchase, na transição para pago (mcp_pos_pagamento), com o id da compra, o mesmo do Pixel na tela Parabéns.
 * Vale a escolha guardada na inscrição, se foi dada com o texto atual (meta_revisao); IP, navegador e
 * _fbp/_fbc também são os guardados com ela (o IP de segurança da inscrição, mcp_inscricoes.ip, não vai).
 */
function mcp_meta_compra(array $inscricao): void
{
    if (!mcp_meta_configurada() || (string) ($inscricao['meta_marketing'] ?? '') !== '1' || (int) ($inscricao['meta_revisao'] ?? 0) < MCP_META_REVISAO) {
        return;
    }
    $criadoMs = (int) strtotime(($inscricao['criado_em'] ?? 'now') . ' UTC') * 1000;
    $contexto = array_filter([
        'client_ip_address' => mcp_meta_ip($inscricao['meta_ip'] ?? null),
        'client_user_agent' => ($inscricao['meta_ua'] ?? '') ?: null,
        'fbp' => ($inscricao['meta_fbp'] ?? '') ?: null,
        'fbc' => ($inscricao['meta_fbc'] ?? null) ?: mcp_meta_fbc_do_fbclid($inscricao['fbclid'] ?? null, $criadoMs),
    ], static fn($v) => $v !== null);
    $pessoa = ['nome' => $inscricao['nome'], 'email' => $inscricao['email'], 'telefone' => $inscricao['telefone'], 'external_id' => (string) $inscricao['token']];
    $idCompra = mcp_meta_id_da_compra((string) $inscricao['token']);
    mcp_meta_enfileirar(mcp_meta_evento('Purchase', $idCompra, mcp_meta_user_data($pessoa, $contexto),
        mcp_meta_dados_do_curso((string) $inscricao['curso_slug'], (string) $inscricao['curso_nome'], (int) $inscricao['total_centavos'],
            ['order_id' => $idCompra]),
        mcp_url_pagina('parabens')), (int) $inscricao['id']);
}

/** Contact: mensagem do chat gravada. O id vem do chat, para juntar com o Contact do Pixel. */
function mcp_meta_contato(array $contato, ?string $id): void
{
    if ($id === null || !mcp_meta_configurada() || mcp_meta_marketing_no_cookie() !== true) {
        return;
    }
    $pessoa = ['nome' => $contato['nome'], 'email' => $contato['email'], 'telefone' => $contato['telefone'] ?? ''];
    $assunto = (string) ($contato['assunto'] ?? '');
    $custom = ['content_category' => $assunto, 'content_name' => (string) (($contato['curso_nome'] ?? '') ?: $assunto)];
    mcp_meta_enfileirar(mcp_meta_evento('Contact', $id, mcp_meta_user_data($pessoa, mcp_meta_contexto($contato['fbclid'] ?? null)), $custom,
        mcp_meta_url_limpa($contato['pagina'] ?? null, mcp_site_url() . '/')));
}

/** Faxina (rotina de 15 em 15 minutos): sinais do navegador guardados há mais de 8 dias saem. */
function mcp_meta_faxina(?int $agora = null): int
{
    $limite = gmdate('Y-m-d H:i:s', ($agora ?? time()) - MCP_META_GUARDA_SEGUNDOS);
    $stmt = mcp_db()->prepare('UPDATE mcp_inscricoes SET meta_fbp = NULL, meta_fbc = NULL, meta_ua = NULL, meta_ip = NULL
        WHERE criado_em < ? AND (meta_fbp IS NOT NULL OR meta_fbc IS NOT NULL OR meta_ua IS NOT NULL OR meta_ip IS NOT NULL)');
    $stmt->execute([$limite]);
    return $stmt->rowCount();
}
