<?php
/**
 * Doações: configuração própria, tabela, validação, cobrança na Unicopag e visão pública.
 *
 * Mora ao lado do checkout de cursos e reaproveita o que já existe lá (banco, cliente da API,
 * tradução de status, moldura de e-mail). O que muda: a conta da Unicopag é outra, a tabela é a
 * mcp_doacoes e o valor é escolhido por quem doa, não por catálogo.
 */
declare(strict_types=1);

const MCP_DOACAO_ORIGEM = 'doacao-site';
const MCP_DOACAO_MIN_PADRAO = 500;          // R$ 5,00
const MCP_DOACAO_MAX_PADRAO = 5000000;      // R$ 50.000,00
const MCP_DOACAO_VALORES_PADRAO = [3000, 6000, 10000, 15000, 25000, 50000];
const MCP_DOACAO_PIX_REAPROVEITA_SEGUNDOS = 20 * 3600;

// ----------------------------------------------------------------------------- configuração
/** Configuração de /doe/api/config.php (só no servidor). Ausente: só a configuração geral vale. */
function mcp_doacao_config(): array
{
    static $config = null;
    if ($config === null) {
        $arquivo = getenv('DOE_CONFIG_ARQUIVO') ?: dirname(__DIR__) . '/config.php';
        $lido = is_file($arquivo) ? require $arquivo : [];
        $config = is_array($lido) ? $lido : [];
    }
    return $config;
}

/** Chave da doação; sem ela, cai para a configuração geral do checkout (banco, Resend, SITE_URL…). */
function mcp_doacao_cfg(string $chave, mixed $padrao = null): mixed
{
    $config = mcp_doacao_config();
    if (array_key_exists($chave, $config) && $config[$chave] !== '' && $config[$chave] !== null) {
        return $config[$chave];
    }
    return mcp_cfg($chave, $padrao);
}

/**
 * Gateway das doações: 'unicopag' (padrão) ou 'donaty' (DOACAO_GATEWAY em /doe/api/config.php).
 * Só as doações trocam; a matrícula dos cursos continua na Unicopag. Voltar atrás é trocar a
 * configuração, sem deploy.
 */
function mcp_doacao_gateway(): string
{
    return mb_strtolower((string) mcp_doacao_cfg('DOACAO_GATEWAY', 'unicopag')) === 'donaty' ? 'donaty' : 'unicopag';
}

/** Conta do gateway das doações (o atual, ou o `$gateway` de uma doação antiga). Sem chave própria, a Unicopag usa a do checkout. */
function mcp_doacao_chave(?string $gateway = null): string
{
    if (($gateway ?? mcp_doacao_gateway()) === 'donaty') {
        return (string) mcp_doacao_cfg('DONATY_API_KEY', '');
    }
    return (string) mcp_doacao_cfg('UNICO_API_KEY_DOACAO', mcp_doacao_cfg('UNICO_API_KEY', ''));
}

/**
 * Opções de transporte do gateway para mcp_unicopag(). A Donaty serve em /api/public/v1 com o
 * token em Authorization: Bearer; a Unicopag usa o padrão (api_token na URL, sem prefixo).
 */
function mcp_doacao_gateway_opcoes(?string $gateway = null): array
{
    if (($gateway ?? mcp_doacao_gateway()) !== 'donaty') {
        return [];
    }
    return [
        'base_url' => (string) mcp_doacao_cfg('DONATY_BASE_URL', 'https://api.donaty.com.br'),
        'prefixo' => '/api',
        'bearer' => true,
    ];
}

/** Chamada à API do gateway das doações (a mesma assinatura de mcp_unicopag, sem a chave). */
function mcp_doacao_api(string $metodo, string $caminho, ?array $corpo = null, ?int $tempo = null, ?string $gateway = null): array
{
    $gateway = $gateway === 'donaty' ? 'donaty' : ($gateway === 'unicopag' ? 'unicopag' : mcp_doacao_gateway());
    $resposta = mcp_unicopag($metodo, $caminho, $corpo, mcp_doacao_chave($gateway), $tempo, mcp_doacao_gateway_opcoes($gateway));
    // Respostas embrulhadas em "data" (padrão de API Laravel) são desembrulhadas: o resto do código lê hash,
    // payment_status e pix.pix_qr_code no nível de cima, como a Unicopag devolve.
    if (!isset($resposta['hash']) && !isset($resposta['payment_status']) && is_array($resposta['data'] ?? null)) {
        $resposta = $resposta['data'] + $resposta;
    }
    return $resposta;
}

/**
 * Formas de pagamento oferecidas. O PIX vai pelo gateway configurado; o cartão (doação única ou mensal) vai
 * sempre pela Unicopag, inclusive com DOACAO_GATEWAY = 'donaty' (a Donaty só documenta PIX). Sem chave da
 * Unicopag, nada de cartão.
 */
function mcp_doacao_metodos(): array
{
    return mcp_doacao_chave('unicopag') !== '' ? ['pix', 'cartao'] : ['pix'];
}

/** Gateway que cobra cada forma de pagamento: cartão na Unicopag, PIX no configurado. */
function mcp_doacao_gateway_do_metodo(string $metodo): string
{
    return $metodo === 'cartao' ? 'unicopag' : mcp_doacao_gateway();
}

/**
 * A doação mensal é uma assinatura recorrente na Unicopag, no cartão (API de assinaturas, base própria,
 * https://subscription.unicopag.com.br/api/v1, autenticação Bearer; código em "doação mensal", no fim deste
 * arquivo). A constante é a trava do código; a opção só aparece com DOACAO_MENSAL = 1 e o plano mensal
 * (DOACAO_MENSAL_PLANO_ID, criado em 29/10/2026, docs/doacao-mensal.md) no config.php do servidor.
 * Vale também com o PIX na Donaty: o cartão é sempre da Unicopag.
 */
const MCP_DOACAO_MENSAL_IMPLEMENTADA = true;

function mcp_doacao_mensal_ativa(): bool
{
    return MCP_DOACAO_MENSAL_IMPLEMENTADA && (int) mcp_doacao_cfg('DOACAO_MENSAL', 0) === 1
        && mcp_doacao_plano_mensal() !== '' && in_array('cartao', mcp_doacao_metodos(), true);
}

function mcp_doacao_teste(): bool
{
    return (int) mcp_doacao_cfg('DOACAO_TESTE_CENTAVOS', 0) > 0;
}

function mcp_doacao_minimo(): int
{
    return max(100, (int) mcp_doacao_cfg('DOACAO_MINIMO_CENTAVOS', MCP_DOACAO_MIN_PADRAO));
}

function mcp_doacao_maximo(): int
{
    return max(mcp_doacao_minimo(), (int) mcp_doacao_cfg('DOACAO_MAXIMO_CENTAVOS', MCP_DOACAO_MAX_PADRAO));
}

/** Valores sugeridos: da configuração, filtrados pelos limites, sempre em ordem. */
function mcp_doacao_valores(): array
{
    $brutos = mcp_doacao_cfg('DOACAO_VALORES', MCP_DOACAO_VALORES_PADRAO);
    $valores = [];
    foreach (is_array($brutos) ? $brutos : [] as $v) {
        $centavos = (int) $v;
        if ($centavos >= mcp_doacao_minimo() && $centavos <= mcp_doacao_maximo()) {
            $valores[$centavos] = $centavos;
        }
    }
    $valores = array_values($valores);
    sort($valores);
    return $valores ?: MCP_DOACAO_VALORES_PADRAO;
}

function mcp_doacao_valor_padrao(): int
{
    $valores = mcp_doacao_valores();
    $pedido = (int) mcp_doacao_cfg('DOACAO_VALOR_PADRAO', 0);
    return in_array($pedido, $valores, true) ? $pedido : $valores[(int) floor(count($valores) / 3)];
}

/** Endereço da página de doação. */
function mcp_doacao_url(string $token = ''): string
{
    $url = mcp_site_url() . '/doe/';
    return $token !== '' ? $url . '?t=' . rawurlencode($token) : $url;
}

/** Tela de acompanhamento e agradecimento, com endereço próprio: é para onde os e-mails levam. */
function mcp_doacao_url_obrigado(string $token): string
{
    return mcp_site_url() . '/doe/obrigado/?t=' . rawurlencode($token);
}

// ----------------------------------------------------------------------------- banco
/** Conexão já com a tabela das doações garantida (migração idempotente, como a do checkout). */
function mcp_doacao_db(): PDO
{
    static $pronto = false;
    $pdo = mcp_db();
    if ($pronto) {
        return $pdo;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_doacoes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        token CHAR(40) NOT NULL,
        frequencia ENUM('unica','mensal') NOT NULL DEFAULT 'unica',
        nome VARCHAR(160) NOT NULL,
        cpf CHAR(11) NOT NULL,
        email VARCHAR(190) NOT NULL,
        telefone VARCHAR(20) NOT NULL,
        metodo ENUM('pix','cartao') NOT NULL,
        valor_centavos INT UNSIGNED NOT NULL,
        taxa_centavos INT UNSIGNED NOT NULL DEFAULT 0,
        total_centavos INT UNSIGNED NOT NULL,
        cobre_taxa TINYINT(1) NOT NULL DEFAULT 0,
        anonimo TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('pendente','pago','recusado','expirado','estornado') NOT NULL DEFAULT 'pendente',
        unicopag_hash VARCHAR(64) NULL,
        unicopag_status VARCHAR(40) NULL,
        assinatura_id VARCHAR(64) NULL,
        pix_copia_cola TEXT NULL,
        pix_url VARCHAR(255) NULL,
        pix_imagem VARCHAR(255) NULL,
        bandeira VARCHAR(30) NULL,
        ultimos4 CHAR(4) NULL,
        utm_source VARCHAR(120) NULL,
        utm_medium VARCHAR(120) NULL,
        utm_campaign VARCHAR(160) NULL,
        utm_content VARCHAR(160) NULL,
        utm_term VARCHAR(160) NULL,
        fbclid VARCHAR(255) NULL,
        gclid VARCHAR(255) NULL,
        ip VARCHAR(45) NULL,
        email_doador VARCHAR(20) NULL,
        email_equipe VARCHAR(20) NULL,
        criado_em DATETIME NOT NULL,
        atualizado_em DATETIME NOT NULL,
        pago_em DATETIME NULL,
        consultado_em DATETIME NULL,
        UNIQUE KEY uq_token (token),
        KEY ix_hash (unicopag_hash),
        KEY ix_email (email, criado_em),
        KEY ix_cpf (cpf, criado_em),
        KEY ix_status (status),
        KEY ix_ip (ip, criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Colunas acrescentadas depois da primeira versão da tabela.
    // gateway: com qual provedor a cobrança nasceu. A consulta de status e o postback usam o da própria doação,
    // então uma doação pendente da Unicopag continua confirmando depois da virada para a Donaty (e vice-versa).
    mcp_garantir_colunas($pdo, 'mcp_doacoes', ['anonimo' => 'TINYINT(1) NOT NULL DEFAULT 0', 'gateway' => "VARCHAR(12) NOT NULL DEFAULT 'unicopag'"]);
    // Cada cobrança paga de uma assinatura vira uma linha de mcp_doacoes (comprovante e aviso por mês). A chave
    // primária é a trava: postback e consulta simultâneos não registram o mesmo mês duas vezes.
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_doacoes_cobrancas (
        cobranca_id VARCHAR(64) NOT NULL PRIMARY KEY,
        assinatura_id VARCHAR(64) NOT NULL,
        doacao_id INT UNSIGNED NULL,
        criado_em DATETIME NOT NULL,
        KEY ix_assinatura (assinatura_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pronto = true;
    return $pdo;
}

/** Trilha de auditoria da doação; usa a mesma tabela de eventos, com o id no detalhe. */
function mcp_doacao_registrar(?int $id, string $tipo, string $detalhe = ''): void
{
    mcp_registrar(null, mb_substr('doacao_' . $tipo, 0, 40), trim(($id ? "#$id" : '') . ($detalhe !== '' ? " · $detalhe" : '')));
}

function mcp_doacao_por(string $coluna, string $valor): ?array
{
    if (!in_array($coluna, ['token', 'unicopag_hash', 'id', 'assinatura_id'], true)) {
        throw new InvalidArgumentException("coluna de busca não permitida: $coluna");
    }
    // ORDER BY id: por assinatura_id há uma linha por mês, e a primeira é a que criou a assinatura.
    $stmt = mcp_doacao_db()->prepare("SELECT * FROM mcp_doacoes WHERE $coluna = ? ORDER BY id LIMIT 1");
    $stmt->execute([$valor]);
    return $stmt->fetch() ?: null;
}

function mcp_doacao_atualizar(int $id, array $campos): void
{
    foreach (array_keys($campos) as $coluna) {
        if (!preg_match('/^[a-z_0-9]+$/', (string) $coluna)) {
            throw new InvalidArgumentException("coluna inválida: $coluna");
        }
    }
    $campos['atualizado_em'] = mcp_agora();
    $sets = implode(', ', array_map(static fn($c) => "$c = :$c", array_keys($campos)));
    $campos['id'] = $id;
    mcp_doacao_db()->prepare("UPDATE mcp_doacoes SET $sets WHERE id = :id")->execute($campos);
}

/** Quantas doações uma chave (ip, cpf ou email) abriu nos últimos N segundos. Base dos freios. */
function mcp_doacao_contar_recentes(string $coluna, string $valor, int $segundos): int
{
    if (!in_array($coluna, ['ip', 'cpf', 'email'], true)) {
        throw new InvalidArgumentException("coluna não permitida: $coluna");
    }
    $stmt = mcp_doacao_db()->prepare("SELECT COUNT(*) FROM mcp_doacoes WHERE $coluna = ? AND criado_em > ?");
    $stmt->execute([$valor, gmdate('Y-m-d H:i:s', time() - $segundos)]);
    return (int) $stmt->fetchColumn();
}

/** Identificação curta que aparece no e-mail e no extrato da equipe: CVD-aammdd-NNNN. */
function mcp_doacao_protocolo(int $id, ?string $criadoEm = null): string
{
    $data = $criadoEm ? strtotime($criadoEm . ' UTC') : time();
    return sprintf('CVD-%s-%04d', gmdate('ymd', $data ?: time()), $id % 10000);
}

// ----------------------------------------------------------------------------- status
/** Reconsulta a transação na conta de doações e aplica o resultado. Postback e polling passam por aqui. */
function mcp_doacao_sincronizar(array $doacao): array
{
    if (!empty($doacao['assinatura_id']) && empty($doacao['unicopag_hash'])) {
        return mcp_doacao_sincronizar_assinatura($doacao);
    }
    if (empty($doacao['unicopag_hash'])) {
        return $doacao;
    }
    $id = (int) $doacao['id'];
    try {
        $transacao = mcp_doacao_api('GET', '/public/v1/transactions/' . rawurlencode((string) $doacao['unicopag_hash']), null, null, (string) ($doacao['gateway'] ?? 'unicopag'));
    } catch (McpUnicopagErro $e) {
        mcp_doacao_registrar($id, 'consulta_falhou', $e->status . ' ' . $e->getMessage());
        mcp_doacao_atualizar($id, ['consultado_em' => mcp_agora()]);
        return $doacao;
    }
    $statusOrigem = (string) ($transacao['payment_status'] ?? $transacao['status'] ?? '');
    mcp_doacao_atualizar($id, ['consultado_em' => mcp_agora(), 'unicopag_status' => mb_substr($statusOrigem, 0, 40)]);
    return mcp_doacao_aplicar_status($id, mcp_traduzir_status($statusOrigem));
}

/**
 * Mesma regra do checkout: pago é definitivo (só estorno muda), recusado e expirado só valem
 * sobre pendente. O UPDATE condicional é atômico, então postback e polling simultâneos disparam
 * os e-mails de agradecimento uma única vez.
 */
function mcp_doacao_aplicar_status(int $id, string $novo): array
{
    $pdo = mcp_doacao_db();
    $agora = mcp_agora();
    $transicoes = [
        'pago' => ["UPDATE mcp_doacoes SET status = 'pago', pago_em = ?, atualizado_em = ? WHERE id = ? AND status <> 'pago' AND status <> 'estornado'", [$agora, $agora, $id]],
        'recusado' => ["UPDATE mcp_doacoes SET status = 'recusado', atualizado_em = ? WHERE id = ? AND status = 'pendente'", [$agora, $id]],
        'expirado' => ["UPDATE mcp_doacoes SET status = 'expirado', atualizado_em = ? WHERE id = ? AND status = 'pendente'", [$agora, $id]],
        'estornado' => ["UPDATE mcp_doacoes SET status = 'estornado', atualizado_em = ? WHERE id = ? AND status IN ('pago','pendente')", [$agora, $id]],
    ];
    if (isset($transicoes[$novo])) {
        [$sql, $params] = $transicoes[$novo];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() > 0) {
            mcp_doacao_registrar($id, $novo);
            if ($novo === 'pago') {
                $doacao = mcp_doacao_por('id', (string) $id);
                if ($doacao) {
                    mcp_doacao_email_confirmada($doacao);
                    mcp_doacao_email_equipe($doacao);
                }
            }
        }
    }
    return mcp_doacao_por('id', (string) $id) ?? [];
}

// ----------------------------------------------------------------------------- visão pública
/** O que a página pode receber: nunca CPF, hash da transação, IP ou número de cartão. */
function mcp_doacao_publico(array $d): array
{
    return [
        'ok' => true,
        'token' => (string) $d['token'],
        'status' => $d['status'],
        'metodo' => $d['metodo'],
        'frequencia' => $d['frequencia'],
        'anonima' => (int) ($d['anonimo'] ?? 0) === 1,
        'nome' => mcp_primeiro_nome((string) $d['nome']),
        'email' => $d['email'],
        'protocolo' => mcp_doacao_protocolo((int) $d['id'], (string) $d['criado_em']),
        'valor_centavos' => (int) $d['valor_centavos'],
        'taxa_centavos' => (int) $d['taxa_centavos'],
        'total_centavos' => (int) $d['total_centavos'],
        'pix' => $d['metodo'] === 'pix' ? [
            'copia_cola' => $d['pix_copia_cola'],
            'url' => $d['pix_url'],
            'imagem' => $d['pix_imagem'],
        ] : null,
        'cartao' => $d['metodo'] === 'cartao' ? ['bandeira' => $d['bandeira'], 'ultimos4' => $d['ultimos4']] : null,
        'criado_em' => $d['criado_em'],
        'pago_em' => $d['pago_em'],
        'url' => mcp_doacao_url_obrigado((string) $d['token']),
        'teste' => mcp_doacao_teste(),
    ];
}

// ----------------------------------------------------------------------------- doação mensal (assinaturas)
/** Id do plano mensal na API de assinaturas (um só; o valor de cada doação vai na própria assinatura). */
function mcp_doacao_plano_mensal(): string
{
    return trim((string) mcp_doacao_cfg('DOACAO_MENSAL_PLANO_ID', ''));
}

/** Chamada à API de assinaturas. O token é o api_token da mesma conta das doações, salvo outro em UNICO_ASSINATURA_TOKEN. */
function mcp_doacao_assinatura_api(string $metodo, string $caminho, ?array $corpo = null): array
{
    $token = (string) mcp_doacao_cfg('UNICO_ASSINATURA_TOKEN', '') ?: mcp_doacao_chave('unicopag');
    $resposta = mcp_unicopag($metodo, $caminho, $corpo, $token, null, [
        'base_url' => (string) mcp_doacao_cfg('UNICO_ASSINATURA_BASE_URL', 'https://subscription.unicopag.com.br/api/v1'),
        'bearer' => true,
    ]);
    if (!isset($resposta['id']) && is_array($resposta['data'] ?? null)) {
        $resposta = $resposta['data'] + $resposta;
    }
    return $resposta;
}

/** Corpo do POST /subscriptions: cliente, cartão (no formato da API de assinaturas) e o valor mensal em centavos. */
function mcp_doacao_montar_assinatura(array $doador, int $total, array $card): array
{
    return [
        'planId' => mcp_doacao_plano_mensal(),
        'paymentMethod' => 'creditCard',
        'amount' => $total,
        'amountFirstCharge' => $total,
        'charge' => true,
        'customer' => [
            'name' => $doador['nome'], 'email' => $doador['email'], 'phoneNumber' => $doador['telefone'],
            'documentNumber' => $doador['cpf'], 'documentType' => 'cpf',
        ],
        'card' => [
            'number' => $card['number'], 'holderName' => $card['holdername'],
            'expirationMonth' => $card['exp_month'], 'expirationYear' => $card['exp_year'], 'cvv' => $card['cvv'],
        ],
    ];
}

/** Cobranças pagas da assinatura, da mais antiga para a mais nova (lastCharge entra se charges não vier). */
function mcp_doacao_cobrancas_pagas(array $assinatura): array
{
    $todas = is_array($assinatura['charges'] ?? null) ? $assinatura['charges'] : [];
    if (is_array($assinatura['lastCharge'] ?? null)) {
        $todas[] = $assinatura['lastCharge'];
    }
    $pagas = [];
    foreach ($todas as $c) {
        if (is_array($c) && !empty($c['id']) && strtolower((string) ($c['status'] ?? '')) === 'paid') {
            $pagas[(string) $c['id']] = $c;
        }
    }
    uasort($pagas, static fn(array $a, array $b): int => strcmp((string) ($a['paidAt'] ?? $a['created_at'] ?? ''), (string) ($b['paidAt'] ?? $b['created_at'] ?? '')));
    return array_values($pagas);
}

/**
 * Situação da primeira cobrança: pago só com uma cobrança paga na resposta (status "active" sozinho não basta);
 * recusado com a assinatura sem pagamento, cancelada ou com a última cobrança recusada; o resto, pendente.
 */
function mcp_doacao_status_assinatura(array $assinatura): string
{
    if (mcp_doacao_cobrancas_pagas($assinatura)) {
        return 'pago';
    }
    $status = strtolower((string) ($assinatura['status'] ?? ''));
    $ultima = is_array($assinatura['lastCharge'] ?? null) ? strtolower((string) ($assinatura['lastCharge']['status'] ?? '')) : '';
    if (in_array($status, ['unpaid', 'canceled', 'cancelled', 'ended'], true) || in_array($ultima, ['failed', 'refused', 'cancelled', 'canceled'], true)) {
        return 'recusado';
    }
    return 'pendente';
}

/** Reconsulta a assinatura (postback, página de acompanhamento) e aplica o resultado. */
function mcp_doacao_sincronizar_assinatura(array $doacao): array
{
    $id = (int) $doacao['id'];
    try {
        $assinatura = mcp_doacao_assinatura_api('GET', '/subscriptions/' . rawurlencode((string) $doacao['assinatura_id']));
    } catch (McpUnicopagErro $e) {
        mcp_doacao_registrar($id, 'assinatura_consulta_falhou', $e->status . ' ' . $e->getMessage());
        mcp_doacao_atualizar($id, ['consultado_em' => mcp_agora()]);
        return $doacao;
    }
    return mcp_doacao_aplicar_assinatura($doacao, $assinatura);
}

/**
 * Aplica a resposta da API de assinaturas à doação que criou a assinatura (a linha mais antiga daquele
 * assinatura_id). A primeira cobrança paga confirma essa linha; cada cobrança paga depois dela é um mês novo:
 * vira outra linha, com o próprio comprovante e o aviso à equipe.
 */
function mcp_doacao_aplicar_assinatura(array $doacao, array $assinatura): array
{
    $id = (int) $doacao['id'];
    $statusOrigem = mb_substr((string) ($assinatura['status'] ?? ''), 0, 40);
    if ($statusOrigem !== '' && $statusOrigem !== (string) ($doacao['unicopag_status'] ?? '')) {
        mcp_doacao_registrar($id, 'assinatura_' . $statusOrigem);
    }
    mcp_doacao_atualizar($id, ['consultado_em' => mcp_agora(), 'unicopag_status' => $statusOrigem ?: null]);
    $pagas = mcp_doacao_cobrancas_pagas($assinatura);
    if ($doacao['status'] !== 'pago') {
        if ($pagas) {
            mcp_doacao_reservar_cobranca((string) $pagas[0]['id'], (string) $doacao['assinatura_id'], $id);
            return mcp_doacao_aplicar_status($id, 'pago');
        }
        return mcp_doacao_aplicar_status($id, mcp_doacao_status_assinatura($assinatura));
    }
    foreach ($pagas as $cobranca) {
        mcp_doacao_registrar_mes($doacao, $cobranca);
    }
    return mcp_doacao_por('id', (string) $id) ?? $doacao;
}

/** Marca a cobrança como registrada. true só para quem marcou primeiro (INSERT IGNORE na chave primária). */
function mcp_doacao_reservar_cobranca(string $cobrancaId, string $assinaturaId, ?int $doacaoId): bool
{
    $stmt = mcp_doacao_db()->prepare('INSERT IGNORE INTO mcp_doacoes_cobrancas (cobranca_id, assinatura_id, doacao_id, criado_em) VALUES (?, ?, ?, ?)');
    $stmt->execute([mb_substr($cobrancaId, 0, 64), mb_substr($assinaturaId, 0, 64), $doacaoId, mcp_agora()]);
    return $stmt->rowCount() === 1;
}

/** Um mês pago da doação mensal: linha nova copiada da original, confirmada (comprovante e aviso saem em aplicar_status). */
function mcp_doacao_registrar_mes(array $original, array $cobranca): void
{
    $assinaturaId = (string) $original['assinatura_id'];
    if (!mcp_doacao_reservar_cobranca((string) $cobranca['id'], $assinaturaId, null)) {
        return;
    }
    $total = is_numeric($cobranca['amount'] ?? null) ? (int) $cobranca['amount'] : (int) $original['total_centavos'];
    $taxa = min((int) $original['taxa_centavos'], $total);
    $agora = mcp_agora();
    $linha = [
        'token' => mcp_token_novo(), 'frequencia' => 'mensal', 'nome' => $original['nome'], 'cpf' => $original['cpf'],
        'email' => $original['email'], 'telefone' => $original['telefone'], 'metodo' => 'cartao',
        'valor_centavos' => $total - $taxa, 'taxa_centavos' => $taxa, 'total_centavos' => $total,
        'cobre_taxa' => $original['cobre_taxa'], 'anonimo' => $original['anonimo'] ?? 0, 'status' => 'pendente',
        'assinatura_id' => $assinaturaId, 'unicopag_status' => 'paid',
        'bandeira' => $original['bandeira'], 'ultimos4' => $original['ultimos4'],
        'utm_source' => $original['utm_source'], 'utm_medium' => $original['utm_medium'], 'utm_campaign' => $original['utm_campaign'],
        'gateway' => 'unicopag', 'criado_em' => $agora, 'atualizado_em' => $agora, 'consultado_em' => $agora,
    ];
    $pdo = mcp_doacao_db();
    $pdo->prepare('INSERT INTO mcp_doacoes (' . implode(', ', array_keys($linha)) . ') VALUES (' . implode(', ', array_fill(0, count($linha), '?')) . ')')
        ->execute(array_values($linha));
    $novo = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE mcp_doacoes_cobrancas SET doacao_id = ? WHERE cobranca_id = ?')->execute([$novo, mb_substr((string) $cobranca['id'], 0, 64)]);
    mcp_doacao_registrar($novo, 'mes_pago', 'assinatura de #' . $original['id'] . ' · ' . mcp_brl($total));
    mcp_doacao_aplicar_status($novo, 'pago');
}
