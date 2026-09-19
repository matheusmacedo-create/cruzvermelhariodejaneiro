<?php
/** MySQL (PDO): conexão, tabelas, leitura e escrita das inscrições, registro de eventos. */
declare(strict_types=1);

/** Banco fora do ar. O tratador global em lib.php responde 503 com mensagem amigável. */
class McpBancoIndisponivel extends RuntimeException
{
}

function mcp_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        mcp_cfg('DB_HOST', 'localhost'), (int) mcp_cfg('DB_PORT', 3306), mcp_cfg('DB_NAME', ''));
    try {
        $conexao = new PDO($dsn, (string) mcp_cfg('DB_USER', ''), (string) mcp_cfg('DB_SENHA', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        mcp_migrar($conexao);
    } catch (PDOException $e) {
        error_log('[matricula] banco indisponível: ' . $e->getMessage());
        throw new McpBancoIndisponivel('Banco de dados indisponível.', 0, $e);
    }
    $pdo = $conexao;
    return $pdo;
}

/** Cria as tabelas na primeira chamada. CREATE IF NOT EXISTS é barato e evita passo manual no deploy. */
function mcp_migrar(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_inscricoes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        token CHAR(40) NOT NULL,
        curso_slug VARCHAR(80) NOT NULL,
        curso_nome VARCHAR(160) NOT NULL,
        nome VARCHAR(160) NOT NULL,
        cpf CHAR(11) NOT NULL,
        email VARCHAR(190) NOT NULL,
        telefone VARCHAR(20) NOT NULL,
        metodo ENUM('pix','cartao') NOT NULL,
        inscricao_centavos INT UNSIGNED NOT NULL,
        taxa_centavos INT UNSIGNED NOT NULL DEFAULT 0,
        total_centavos INT UNSIGNED NOT NULL,
        cobre_taxa TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('pendente','pago','recusado','expirado','estornado') NOT NULL DEFAULT 'pendente',
        unicopag_hash VARCHAR(64) NULL,
        unicopag_status VARCHAR(40) NULL,
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
        escola_status ENUM('nao_aplicavel','pendente','ok','erro') NOT NULL DEFAULT 'nao_aplicavel',
        escola_tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
        escola_tentativa_em DATETIME NULL,
        escola_acesso TEXT NULL,
        email_aluno VARCHAR(20) NULL,
        email_secretaria VARCHAR(20) NULL,
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
    // Mensagens do chat de contato do site (api/contato.php). Fonte da verdade: o e-mail à equipe é cópia.
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_contatos (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        protocolo VARCHAR(24) NULL,
        nome VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL,
        telefone VARCHAR(20) NULL,
        assunto VARCHAR(30) NOT NULL,
        curso_slug VARCHAR(80) NULL,
        curso_nome VARCHAR(160) NULL,
        mensagem TEXT NOT NULL,
        pagina VARCHAR(255) NULL,
        utm_source VARCHAR(120) NULL,
        utm_medium VARCHAR(120) NULL,
        utm_campaign VARCHAR(160) NULL,
        utm_content VARCHAR(160) NULL,
        utm_term VARCHAR(160) NULL,
        fbclid VARCHAR(255) NULL,
        gclid VARCHAR(255) NULL,
        ip VARCHAR(45) NULL,
        email_equipe VARCHAR(20) NULL,
        email_confirmacao VARCHAR(20) NULL,
        respondido_em DATETIME NULL,
        criado_em DATETIME NOT NULL,
        KEY ix_email (email, criado_em),
        KEY ix_ip (ip, criado_em),
        KEY ix_criado (criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_eventos (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        inscricao_id INT UNSIGNED NULL,
        tipo VARCHAR(40) NOT NULL,
        detalhe TEXT NULL,
        criado_em DATETIME NOT NULL,
        KEY ix_inscricao (inscricao_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Trilha de auditoria por inscrição. Nunca recebe dado de cartão nem senha. Falha aqui não derruba a requisição. */
function mcp_registrar(?int $inscricaoId, string $tipo, string $detalhe = ''): void
{
    try {
        mcp_db()->prepare('INSERT INTO mcp_eventos (inscricao_id, tipo, detalhe, criado_em) VALUES (?, ?, ?, ?)')
            ->execute([$inscricaoId, $tipo, mb_substr($detalhe, 0, 4000), mcp_agora()]);
    } catch (Throwable $e) {
        error_log('[matricula] registro falhou: ' . $e->getMessage());
    }
}

function mcp_inscricao_por(string $coluna, string $valor): ?array
{
    if (!in_array($coluna, ['token', 'unicopag_hash', 'id'], true)) {
        throw new InvalidArgumentException("coluna de busca não permitida: $coluna");
    }
    $stmt = mcp_db()->prepare("SELECT * FROM mcp_inscricoes WHERE $coluna = ? LIMIT 1");
    $stmt->execute([$valor]);
    $linha = $stmt->fetch();
    return $linha ?: null;
}

/** Atualiza colunas conhecidas de uma inscrição (as chaves vêm sempre do código, nunca do cliente). */
function mcp_atualizar(int $id, array $campos): void
{
    foreach (array_keys($campos) as $coluna) {
        if (!preg_match('/^[a-z_]+$/', (string) $coluna)) {
            throw new InvalidArgumentException("coluna inválida: $coluna");
        }
    }
    $campos['atualizado_em'] = mcp_agora();
    $sets = implode(', ', array_map(static fn($c) => "$c = :$c", array_keys($campos)));
    $campos['id'] = $id;
    mcp_db()->prepare("UPDATE mcp_inscricoes SET $sets WHERE id = :id")->execute($campos);
}

/** Quantas inscrições uma chave (ip, cpf ou email) abriu nos últimos N segundos. Base dos freios. */
function mcp_contar_recentes(string $coluna, string $valor, int $segundos): int
{
    if (!in_array($coluna, ['ip', 'cpf', 'email'], true)) {
        throw new InvalidArgumentException("coluna não permitida: $coluna");
    }
    $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_inscricoes WHERE $coluna = ? AND criado_em > ?");
    $stmt->execute([$valor, gmdate('Y-m-d H:i:s', time() - $segundos)]);
    return (int) $stmt->fetchColumn();
}

// ----------------------------------------------------------------------------- contatos (chat do site)
/** Grava a mensagem do chat e devolve o id. As chaves vêm do código (contato.php), nunca do cliente. */
function mcp_contato_gravar(array $c): int
{
    $agora = mcp_agora();
    $linha = [
        'nome' => $c['nome'], 'email' => $c['email'], 'telefone' => $c['telefone'] ?: null, 'assunto' => $c['assunto'],
        'curso_slug' => $c['curso_slug'], 'curso_nome' => $c['curso_nome'], 'mensagem' => $c['mensagem'], 'pagina' => $c['pagina'],
        'utm_source' => $c['utm_source'], 'utm_medium' => $c['utm_medium'], 'utm_campaign' => $c['utm_campaign'],
        'utm_content' => $c['utm_content'], 'utm_term' => $c['utm_term'], 'fbclid' => $c['fbclid'], 'gclid' => $c['gclid'],
        'ip' => mcp_ip(), 'criado_em' => $agora,
    ];
    $colunas = implode(', ', array_keys($linha));
    $marcadores = implode(', ', array_fill(0, count($linha), '?'));
    $pdo = mcp_db();
    $pdo->prepare("INSERT INTO mcp_contatos ($colunas) VALUES ($marcadores)")->execute(array_values($linha));
    return (int) $pdo->lastInsertId();
}

function mcp_contato_por_id(int $id): ?array
{
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_contatos WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $linha = $stmt->fetch();
    return $linha ?: null;
}

function mcp_contato_atualizar(int $id, array $campos): void
{
    foreach (array_keys($campos) as $coluna) {
        if (!preg_match('/^[a-z_]+$/', (string) $coluna)) {
            throw new InvalidArgumentException("coluna inválida: $coluna");
        }
    }
    $sets = implode(', ', array_map(static fn($c) => "$c = :$c", array_keys($campos)));
    $campos['id'] = $id;
    mcp_db()->prepare("UPDATE mcp_contatos SET $sets WHERE id = :id")->execute($campos);
}

/** Quantas mensagens uma chave (ip ou email) enviou nos últimos N segundos. Base dos freios do chat. */
function mcp_contar_contatos_recentes(string $coluna, string $valor, int $segundos): int
{
    if (!in_array($coluna, ['ip', 'email'], true)) {
        throw new InvalidArgumentException("coluna não permitida: $coluna");
    }
    $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_contatos WHERE $coluna = ? AND criado_em > ?");
    $stmt->execute([$valor, gmdate('Y-m-d H:i:s', time() - $segundos)]);
    return (int) $stmt->fetchColumn();
}

/** Protocolo legível para a pessoa citar na resposta: CV-aammdd-NNNN (data de Brasília, id da mensagem). */
function mcp_contato_protocolo(int $id, ?string $agoraUtc = null): string
{
    $data = (new DateTimeImmutable($agoraUtc ?? mcp_agora(), new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Sao_Paulo'));
    return sprintf('CV-%s-%04d', $data->format('ymd'), $id);
}
