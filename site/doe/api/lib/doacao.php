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

/** Conta da Unicopag das doações. Sem chave própria, usa a do checkout (útil em teste). */
function mcp_doacao_chave(): string
{
    return (string) mcp_doacao_cfg('UNICO_API_KEY_DOACAO', mcp_doacao_cfg('UNICO_API_KEY', ''));
}

/**
 * A doação mensal é uma assinatura recorrente na Unicopag (base própria,
 * https://subscription.unicopag.com.br/api/v1, autenticação Bearer). A integração ainda não
 * foi escrita, então esta constante é a trava: enquanto for false, nem a configuração liga a
 * opção e a página nunca oferece o que o servidor não consegue cobrar.
 */
const MCP_DOACAO_MENSAL_IMPLEMENTADA = false;

function mcp_doacao_mensal_ativa(): bool
{
    return MCP_DOACAO_MENSAL_IMPLEMENTADA && (int) mcp_doacao_cfg('DOACAO_MENSAL', 0) === 1;
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
    if (!in_array($coluna, ['token', 'unicopag_hash', 'id'], true)) {
        throw new InvalidArgumentException("coluna de busca não permitida: $coluna");
    }
    $stmt = mcp_doacao_db()->prepare("SELECT * FROM mcp_doacoes WHERE $coluna = ? LIMIT 1");
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
    if (empty($doacao['unicopag_hash'])) {
        return $doacao;
    }
    $id = (int) $doacao['id'];
    try {
        $transacao = mcp_unicopag('GET', '/public/v1/transactions/' . rawurlencode((string) $doacao['unicopag_hash']), null, mcp_doacao_chave());
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
