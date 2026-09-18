<?php
/**
 * Biblioteca do checkout da matrícula em cursos presenciais (PHP 8, Hostinger).
 *
 * Endpoints públicos: info.php, pagamentos.php, status.php, webhook.php.
 * Gateway: Unicopag (api_token na query string, valores em centavos, PIX e cartão).
 *
 * PCI: o número do cartão passa por aqui em claro a caminho da Unicopag. Ele nunca é gravado,
 * logado, devolvido nem incluído em mensagem de erro. Do cartão fica só bandeira e últimos 4.
 * O corpo do postback da Unicopag não é fonte da verdade: só o hash é usado, para reconsultar.
 */
declare(strict_types=1);

const MCP_VERSAO = '2026-09-18';
const MCP_TOKEN_TAMANHO = 40;

// ----------------------------------------------------------------------------- config
function mcp_config(): array
{
    static $config = null;
    if ($config === null) {
        $arquivo = __DIR__ . '/config.php';
        if (!is_file($arquivo)) {
            mcp_falhar(500, 'Checkout não configurado (config.php ausente).');
        }
        $config = require $arquivo;
    }
    return $config;
}

function mcp_cfg(string $chave, mixed $padrao = null): mixed
{
    $config = mcp_config();
    if (!array_key_exists($chave, $config) || $config[$chave] === '' || $config[$chave] === null) {
        return $padrao;
    }
    return $config[$chave];
}

function mcp_site_url(): string
{
    return rtrim((string) mcp_cfg('SITE_URL', 'https://cruzvermelhariodejaneiro.org'), '/');
}

function mcp_url_pagina(string $pagina, string $token = ''): string
{
    $url = mcp_site_url() . '/matricula-cursos-presenciais/' . $pagina . '/';
    return $token !== '' ? $url . '?t=' . rawurlencode($token) : $url;
}

// ----------------------------------------------------------------------------- http
function mcp_json(array $dados, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mcp_falhar(int $status, string $mensagem, array $extra = []): never
{
    mcp_json(['ok' => false, 'erro' => $mensagem] + $extra, $status);
}

function mcp_corpo_json(): array
{
    $bruto = file_get_contents('php://input');
    $dados = json_decode($bruto ?: '', true);
    return is_array($dados) ? $dados : [];
}

function mcp_exigir_metodo(string $metodo): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $metodo) {
        header('Allow: ' . $metodo);
        mcp_falhar(405, 'Método não permitido.');
    }
}

function mcp_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

// ----------------------------------------------------------------------------- utilidades
function mcp_digitos(string $texto): string
{
    return preg_replace('/\D+/', '', $texto) ?? '';
}

function mcp_texto(mixed $valor, int $limite): string
{
    $texto = is_string($valor) ? $valor : '';
    $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? '');
    return mb_substr($texto, 0, $limite);
}

function mcp_cpf_valido(string $cpf): bool
{
    $cpf = mcp_digitos($cpf);
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }
    for ($t = 9; $t < 11; $t++) {
        $soma = 0;
        for ($i = 0; $i < $t; $i++) {
            $soma += (int) $cpf[$i] * (($t + 1) - $i);
        }
        $digito = ((10 * $soma) % 11) % 10;
        if ((int) $cpf[$t] !== $digito) {
            return false;
        }
    }
    return true;
}

function mcp_brl(int $centavos): string
{
    return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
}

function mcp_token_novo(): string
{
    return bin2hex(random_bytes(MCP_TOKEN_TAMANHO / 2));
}

function mcp_agora(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** Catálogo publicado (mesmo arquivo que gera a página). */
function mcp_catalogo(): array
{
    static $catalogo = null;
    if ($catalogo === null) {
        $arquivo = dirname(__DIR__) . '/cursos.json';
        $dados = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;
        $catalogo = is_array($dados) ? $dados : ['cursos' => [], 'inscricao_centavos' => 9900, 'grupos' => []];
    }
    return $catalogo;
}

function mcp_curso(string $slug): ?array
{
    foreach (mcp_catalogo()['cursos'] as $curso) {
        if (($curso['slug'] ?? '') === $slug) {
            return $curso;
        }
    }
    return null;
}

function mcp_inscricao_centavos(): int
{
    $teste = (int) mcp_cfg('PRECO_TESTE_CENTAVOS', 0);
    if ($teste > 0) {
        return $teste;
    }
    $config = (int) mcp_cfg('INSCRICAO_CENTAVOS', 0);
    return $config > 0 ? $config : (int) (mcp_catalogo()['inscricao_centavos'] ?? 9900);
}

function mcp_modo_teste(): bool
{
    return (int) mcp_cfg('PRECO_TESTE_CENTAVOS', 0) > 0;
}

/** Custos de processamento que o aluno pode cobrir, por método. */
function mcp_taxa(string $metodo, int $base): int
{
    $pct = (float) mcp_cfg($metodo === 'pix' ? 'TAXA_PIX_PCT' : 'TAXA_CARTAO_PCT', 0);
    $fixa = (int) mcp_cfg($metodo === 'pix' ? 'TAXA_PIX_FIXA' : 'TAXA_CARTAO_FIXA', 0);
    return max(0, (int) round($base * $pct / 100) + $fixa);
}

// ----------------------------------------------------------------------------- banco
function mcp_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        mcp_cfg('DB_HOST', 'localhost'), (int) mcp_cfg('DB_PORT', 3306), mcp_cfg('DB_NAME', ''));
    try {
        $pdo = new PDO($dsn, (string) mcp_cfg('DB_USER', ''), (string) mcp_cfg('DB_SENHA', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        error_log('[matricula] banco indisponível: ' . $e->getMessage());
        mcp_falhar(503, 'Banco de dados indisponível. Tente novamente em instantes.');
    }
    mcp_migrar($pdo);
    return $pdo;
}

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
        KEY ix_email (email),
        KEY ix_status (status),
        KEY ix_ip (ip, criado_em)
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
        return null;
    }
    $stmt = mcp_db()->prepare("SELECT * FROM mcp_inscricoes WHERE $coluna = ? LIMIT 1");
    $stmt->execute([$valor]);
    $linha = $stmt->fetch();
    return $linha ?: null;
}

function mcp_atualizar(int $id, array $campos): void
{
    $campos['atualizado_em'] = mcp_agora();
    $sets = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($campos)));
    $campos['id'] = $id;
    mcp_db()->prepare("UPDATE mcp_inscricoes SET $sets WHERE id = :id")->execute($campos);
}

// ----------------------------------------------------------------------------- unicopag
class McpUnicopagErro extends RuntimeException
{
    public function __construct(public int $status, string $mensagem, public ?array $campos = null)
    {
        parent::__construct($mensagem);
    }

    /** Mensagem que faz sentido mostrar ao aluno (a primeira de campo, ou a geral). */
    public function paraOAluno(): string
    {
        if ($this->campos) {
            foreach ($this->campos as $mensagens) {
                if (is_array($mensagens) && isset($mensagens[0]) && is_string($mensagens[0])) {
                    return $mensagens[0];
                }
                if (is_string($mensagens)) {
                    return $mensagens;
                }
            }
        }
        if (in_array($this->status, [400, 402, 422], true) && $this->getMessage() !== '') {
            return $this->getMessage();
        }
        return 'Não foi possível processar o pagamento. Tente novamente.';
    }
}

function mcp_unicopag(string $metodo, string $caminho, ?array $corpo = null): array
{
    $chave = (string) mcp_cfg('UNICO_API_KEY', '');
    if ($chave === '') {
        throw new McpUnicopagErro(500, 'Gateway de pagamento não configurado.');
    }
    $url = rtrim((string) mcp_cfg('UNICO_BASE_URL', 'https://api.cloud.unicopag.com.br'), '/') . $caminho
        . (str_contains($caminho, '?') ? '&' : '?') . 'api_token=' . rawurlencode($chave);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 55,
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE));
    }
    $resposta = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);
    if ($resposta === false) {
        // O corpo nunca entra no log: pode carregar o cartão.
        error_log("[matricula] Unicopag inacessível ($metodo $caminho): $erroCurl");
        throw new McpUnicopagErro(502, 'Gateway de pagamento indisponível. Tente novamente.');
    }
    $dados = json_decode($resposta, true);
    if ($status >= 400 || !is_array($dados)) {
        $mensagem = is_array($dados) && !empty($dados['message']) ? (string) $dados['message'] : "Unicopag respondeu $status.";
        $campos = is_array($dados) && isset($dados['errors']) && is_array($dados['errors']) ? $dados['errors'] : null;
        throw new McpUnicopagErro($status, $mensagem, $campos);
    }
    return $dados;
}

/** Tradução dos status da Unicopag. Desconhecido = pendente (o único palpite que não causa dano). */
function mcp_traduzir_status(?string $origem): string
{
    $mapa = [
        'waiting_payment' => 'pendente', 'pending' => 'pendente', 'processing' => 'pendente',
        'paid' => 'pago', 'pre_chargeback' => 'pago',
        'refused' => 'recusado', 'failed' => 'recusado',
        'cancelled' => 'expirado', 'canceled' => 'expirado', 'expired' => 'expirado',
        'refunded' => 'estornado', 'chargeback' => 'estornado',
    ];
    return $mapa[strtolower(trim((string) $origem))] ?? 'pendente';
}

/**
 * Reconsulta a transação na Unicopag e aplica o resultado. Devolve a inscrição atualizada.
 * É o único caminho que muda status: postback e polling passam por aqui.
 */
function mcp_sincronizar(array $inscricao): array
{
    if (empty($inscricao['unicopag_hash'])) {
        return $inscricao;
    }
    try {
        $transacao = mcp_unicopag('GET', '/public/v1/transactions/' . rawurlencode((string) $inscricao['unicopag_hash']));
    } catch (McpUnicopagErro $e) {
        mcp_registrar((int) $inscricao['id'], 'consulta_falhou', $e->getMessage());
        mcp_atualizar((int) $inscricao['id'], ['consultado_em' => mcp_agora()]);
        return $inscricao;
    }
    $statusOrigem = (string) ($transacao['payment_status'] ?? '');
    $novo = mcp_traduzir_status($statusOrigem);
    mcp_atualizar((int) $inscricao['id'], ['consultado_em' => mcp_agora(), 'unicopag_status' => mb_substr($statusOrigem, 0, 40)]);
    return mcp_aplicar_status((int) $inscricao['id'], $novo);
}

/** Aplica um status vindo do provedor, respeitando as transições permitidas. */
function mcp_aplicar_status(int $id, string $novo): array
{
    $pdo = mcp_db();
    if ($novo === 'pago') {
        $stmt = $pdo->prepare("UPDATE mcp_inscricoes SET status = 'pago', pago_em = ?, atualizado_em = ? WHERE id = ? AND status <> 'pago'");
        $stmt->execute([mcp_agora(), mcp_agora(), $id]);
        if ($stmt->rowCount() > 0) {
            mcp_registrar($id, 'pago');
            mcp_pos_pagamento($id);
        }
    } elseif (in_array($novo, ['recusado', 'expirado'], true)) {
        // Desfecho sem sucesso só vale enquanto a cobrança está pendente.
        $stmt = $pdo->prepare("UPDATE mcp_inscricoes SET status = ?, atualizado_em = ? WHERE id = ? AND status = 'pendente'");
        $stmt->execute([$novo, mcp_agora(), $id]);
        if ($stmt->rowCount() > 0) {
            mcp_registrar($id, $novo);
        }
    } elseif ($novo === 'estornado') {
        $stmt = $pdo->prepare("UPDATE mcp_inscricoes SET status = 'estornado', atualizado_em = ? WHERE id = ? AND status IN ('pago','pendente')");
        $stmt->execute([mcp_agora(), $id]);
        if ($stmt->rowCount() > 0) {
            mcp_registrar($id, 'estornado');
        }
    }
    return mcp_inscricao_por('id', (string) $id) ?? [];
}

// ----------------------------------------------------------------------------- pós-pagamento
/** Roda uma vez, na transição para pago: escola (se houver API), e-mail do aluno, aviso à secretaria. */
function mcp_pos_pagamento(int $id): void
{
    $inscricao = mcp_inscricao_por('id', (string) $id);
    if (!$inscricao) {
        return;
    }
    if (mcp_escola_configurada()) {
        mcp_atualizar($id, ['escola_status' => 'pendente']);
        mcp_escola_tentar($id);
        $inscricao = mcp_inscricao_por('id', (string) $id) ?? $inscricao;
    }
    mcp_email_aluno_pago($inscricao);
    mcp_email_secretaria($inscricao);
}

/** Retentativa da escola, chamada pelo status.php enquanto houver erro (no máximo 5 tentativas, 1 por minuto). */
function mcp_escola_retentar_se_preciso(array $inscricao): array
{
    if ($inscricao['status'] !== 'pago' || $inscricao['escola_status'] !== 'erro' || (int) $inscricao['escola_tentativas'] >= 5) {
        return $inscricao;
    }
    $ultima = $inscricao['escola_tentativa_em'] ? strtotime($inscricao['escola_tentativa_em'] . ' UTC') : 0;
    if (time() - $ultima < 60) {
        return $inscricao;
    }
    mcp_escola_tentar((int) $inscricao['id']);
    $atual = mcp_inscricao_por('id', (string) $inscricao['id']) ?? $inscricao;
    if ($atual['escola_status'] === 'ok' && $atual['email_aluno'] !== 'acesso') {
        mcp_email_aluno_pago($atual);
    }
    return $atual;
}

// ----------------------------------------------------------------------------- escola
function mcp_escola_configurada(): bool
{
    return (string) mcp_cfg('ESCOLA_API_URL', '') !== '';
}

function mcp_escola_tentar(int $id): void
{
    $inscricao = mcp_inscricao_por('id', (string) $id);
    if (!$inscricao) {
        return;
    }
    $tentativas = (int) $inscricao['escola_tentativas'] + 1;
    $payload = [
        'curso_id' => $inscricao['curso_slug'],
        'transacao_unicopag' => $inscricao['unicopag_hash'],
        'valor_inscricao_centavos' => (int) $inscricao['inscricao_centavos'],
        'aluno' => [
            'nome' => $inscricao['nome'],
            'cpf' => $inscricao['cpf'],
            'email' => $inscricao['email'],
            'whatsapp' => $inscricao['telefone'],
        ],
    ];
    $ch = curl_init((string) mcp_cfg('ESCOLA_API_URL'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json', 'Content-Type: application/json',
            'Authorization: Bearer ' . (string) mcp_cfg('ESCOLA_API_TOKEN', ''),
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 40,
    ]);
    $resposta = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);
    $dados = is_string($resposta) ? json_decode($resposta, true) : null;
    if ($resposta !== false && $status < 400 && is_array($dados) && !empty($dados['ok'])) {
        mcp_atualizar($id, [
            'escola_status' => 'ok', 'escola_tentativas' => $tentativas, 'escola_tentativa_em' => mcp_agora(),
            'escola_acesso' => json_encode([
                'usuario' => $dados['usuario'] ?? null,
                'acesso' => $dados['acesso'] ?? null,
                'url_ambiente' => $dados['url_ambiente'] ?? null,
                'matricula_id' => $dados['matricula_id'] ?? null,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        mcp_registrar($id, 'escola_ok', 'tentativa ' . $tentativas);
        return;
    }
    mcp_atualizar($id, ['escola_status' => 'erro', 'escola_tentativas' => $tentativas, 'escola_tentativa_em' => mcp_agora()]);
    mcp_registrar($id, 'escola_erro', "tentativa $tentativas · HTTP $status · $erroCurl · " . mb_substr((string) $resposta, 0, 500));
}

/** Acesso devolvido pela escola, já pronto para a tela e o e-mail (nunca vai para log). */
function mcp_escola_acesso(array $inscricao): ?array
{
    if (empty($inscricao['escola_acesso'])) {
        return null;
    }
    $dados = json_decode((string) $inscricao['escola_acesso'], true);
    return is_array($dados) ? $dados : null;
}

// ----------------------------------------------------------------------------- e-mail
function mcp_escapar(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mcp_primeiro_nome(string $nome): string
{
    $partes = preg_split('/\s+/', trim($nome)) ?: [];
    return $partes[0] ?? $nome;
}

function mcp_moldura(string $titulo, string $corpo): string
{
    $t = mcp_escapar($titulo);
    $whats = (string) mcp_cfg('WHATSAPP_SECRETARIA', '');
    $rodape = $whats !== '' ? 'Dúvidas? Fale com a secretaria no WhatsApp +' . mcp_escapar($whats) . '.' : '';
    return "<!doctype html><html lang=\"pt-BR\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width\"><title>$t</title></head>"
        . "<body style=\"margin:0;padding:24px 12px;background:#f7f8fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:#1a202c;line-height:1.5\">"
        . "<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0\">"
        . "<tr><td style=\"height:6px;background:#cc0000;font-size:0;line-height:0\">&nbsp;</td></tr>"
        . "<tr><td style=\"padding:28px 28px 8px\"><p style=\"margin:0 0 4px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#cc0000;font-weight:700\">Cruz Vermelha Brasileira · Rio de Janeiro</p>"
        . "<h1 style=\"margin:0;font-size:22px;line-height:1.2\">$t</h1></td></tr>"
        . "<tr><td style=\"padding:8px 28px 28px;font-size:15px\">$corpo</td></tr>"
        . "<tr><td style=\"padding:18px 28px;border-top:1px solid #e2e8f0;font-size:12px;color:#718096\"><p style=\"margin:0 0 6px\">$rodape</p><p style=\"margin:0\">Praça da Cruz Vermelha, 10 · Centro · Rio de Janeiro</p></td></tr>"
        . "</table></body></html>";
}

function mcp_botao(string $url, string $rotulo): string
{
    return '<p style="margin:22px 0"><a href="' . mcp_escapar($url) . '" style="display:inline-block;background:#cc0000;color:#ffffff;text-decoration:none;font-weight:700;padding:14px 22px;border-radius:999px">' . mcp_escapar($rotulo) . '</a></p>';
}

function mcp_p(string $html): string
{
    return '<p style="margin:0 0 12px">' . $html . '</p>';
}

/** Envia por Resend (se configurado) ou pelo mail() da Hostinger. Devolve 'resend', 'mail' ou 'falhou'. */
function mcp_enviar_email(string $para, string $assunto, string $html, string $texto): string
{
    $remetente = (string) mcp_cfg('EMAIL_REMETENTE', 'matricula@cruzvermelhariodejaneiro.org');
    $resposta = (string) mcp_cfg('EMAIL_RESPOSTA', '');
    $chave = (string) mcp_cfg('RESEND_API_KEY', '');
    if ($chave !== '') {
        $ch = curl_init('https://api.resend.com/emails');
        $corpo = ['from' => $remetente, 'to' => [$para], 'subject' => $assunto, 'html' => $html, 'text' => $texto];
        if ($resposta !== '') {
            $corpo['reply_to'] = $resposta;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($corpo, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $chave, 'Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
        ]);
        $r = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($r !== false && $status < 300) {
            return 'resend';
        }
        error_log("[matricula] Resend falhou ($status): " . mb_substr((string) $r, 0, 300));
    }
    $enderecoRemetente = preg_match('/<([^>]+)>/', $remetente, $m) ? $m[1] : $remetente;
    $cabecalhos = "From: $remetente\r\n" . ($resposta !== '' ? "Reply-To: $resposta\r\n" : '')
        . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
    $assuntoCodificado = '=?UTF-8?B?' . base64_encode($assunto) . '?=';
    $ok = @mail($para, $assuntoCodificado, $html, $cabecalhos, '-f' . $enderecoRemetente);
    return $ok ? 'mail' : 'falhou';
}

function mcp_email_pix_aberto(array $inscricao): void
{
    if (empty($inscricao['pix_copia_cola'])) {
        return;
    }
    $nome = mcp_primeiro_nome((string) $inscricao['nome']);
    $curso = (string) $inscricao['curso_nome'];
    $total = mcp_brl((int) $inscricao['total_centavos']);
    $link = mcp_url_pagina('pendente', (string) $inscricao['token']);
    $corpo = mcp_p('Oi, ' . mcp_escapar($nome) . '. Sua inscrição no curso <strong>' . mcp_escapar($curso) . '</strong> está aberta e o código PIX abaixo confirma sua vaga.')
        . mcp_p('Valor: <strong>' . $total . '</strong>.')
        . '<p style="margin:0 0 6px;font-size:13px;color:#718096">PIX copia e cola:</p>'
        . '<p style="margin:0 0 12px;padding:12px;background:#f7f8fa;border:1px solid #e2e8f0;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;word-break:break-all">' . mcp_escapar((string) $inscricao['pix_copia_cola']) . '</p>'
        . mcp_p('Abra o aplicativo do seu banco, escolha PIX Copia e Cola e conclua o pagamento. O código vale por 24 horas.')
        . mcp_botao($link, 'Voltar para o pagamento');
    $texto = "$nome, sua inscrição no curso $curso está aberta. Valor: $total.\n\nPIX copia e cola:\n{$inscricao['pix_copia_cola']}\n\nVoltar para o pagamento: $link";
    $r = mcp_enviar_email((string) $inscricao['email'], "Seu PIX da inscrição — $curso", mcp_moldura('Sua inscrição está aberta', $corpo), $texto);
    mcp_registrar((int) $inscricao['id'], 'email_pix', $r);
}

function mcp_email_aluno_pago(array $inscricao): void
{
    $nome = mcp_primeiro_nome((string) $inscricao['nome']);
    $curso = (string) $inscricao['curso_nome'];
    $total = mcp_brl((int) $inscricao['total_centavos']);
    $escolaUrl = (string) mcp_cfg('ESCOLA_URL', 'https://escola.cursoscruzvermelha.org');
    $acesso = mcp_escola_acesso($inscricao);
    $linkParabens = mcp_url_pagina('parabens', (string) $inscricao['token']);
    $estorno = 'A inscrição reserva sua vaga. Se não houver horário compatível ou você desistir antes da confirmação da aula, o valor é estornado. O prazo para aparecer na conta depende de PIX ou cartão.';

    if ($acesso) {
        $assunto = "Acesso à secretaria — $curso";
        $tipo = 'acesso';
        $usuario = (string) ($acesso['usuario'] ?? $inscricao['email']);
        $url = (string) ($acesso['acesso']['url'] ?? $acesso['url_ambiente'] ?? $escolaUrl);
        $senha = isset($acesso['acesso']['senha']) ? (string) $acesso['acesso']['senha'] : '';
        $corpo = mcp_p('Parabéns, ' . mcp_escapar($nome) . '. Sua inscrição no curso <strong>' . mcp_escapar($curso) . '</strong> está paga (' . $total . ') e aqui está seu acesso à secretaria da escola.')
            . mcp_p('Usuário: <strong>' . mcp_escapar($usuario) . '</strong>' . ($senha !== '' ? '<br>Senha temporária: <strong>' . mcp_escapar($senha) . '</strong> (troque no primeiro acesso)' : ''))
            . mcp_botao($url, 'Acessar ambiente da secretaria')
            . mcp_p('Horário e turma você escolhe lá. ' . mcp_escapar($estorno));
        $texto = "Parabéns, $nome. Sua inscrição no curso $curso está paga ($total).\nUsuário: $usuario" . ($senha !== '' ? "\nSenha temporária: $senha" : '') . "\nAcesso: $url\n\n$estorno";
        $titulo = 'Inscrição paga: aqui está seu acesso';
    } else {
        $assunto = "Inscrição confirmada — $curso";
        $tipo = 'confirmacao';
        $corpo = mcp_p('Parabéns, ' . mcp_escapar($nome) . '. Sua inscrição no curso <strong>' . mcp_escapar($curso) . '</strong> está paga (' . $total . ').')
            . mcp_p('<strong>A secretaria da Escola entra em contato pelo WhatsApp em até 2 dias úteis</strong> para fechar turma e horário. Você não precisa se inscrever de novo na plataforma.')
            . mcp_p('O valor do curso é pago depois, na plataforma da escola. ' . mcp_escapar($estorno))
            . mcp_botao($linkParabens, 'Ver minha inscrição')
            . mcp_p('<a href="' . mcp_escapar($escolaUrl) . '" style="color:#cc0000">Plataforma da escola</a>');
        $texto = "Parabéns, $nome. Sua inscrição no curso $curso está paga ($total).\nA secretaria da Escola entra em contato pelo WhatsApp em até 2 dias úteis para fechar turma e horário. Você não precisa se inscrever de novo na plataforma.\n\n$estorno\n\nMinha inscrição: $linkParabens";
        $titulo = 'Inscrição confirmada';
    }
    $r = mcp_enviar_email((string) $inscricao['email'], $assunto, mcp_moldura($titulo, $corpo), $texto);
    mcp_atualizar((int) $inscricao['id'], ['email_aluno' => $r === 'falhou' ? 'falhou' : $tipo]);
    mcp_registrar((int) $inscricao['id'], 'email_aluno', "$tipo · $r");
}

function mcp_email_secretaria(array $inscricao): void
{
    $para = (string) mcp_cfg('EMAIL_SECRETARIA', '');
    if ($para === '') {
        return;
    }
    $linhas = [
        'Curso' => $inscricao['curso_nome'],
        'Aluno' => $inscricao['nome'],
        'CPF' => $inscricao['cpf'],
        'E-mail' => $inscricao['email'],
        'WhatsApp' => $inscricao['telefone'],
        'Pago' => mcp_brl((int) $inscricao['total_centavos']) . ' (inscrição ' . mcp_brl((int) $inscricao['inscricao_centavos']) . ((int) $inscricao['taxa_centavos'] > 0 ? ' + custos ' . mcp_brl((int) $inscricao['taxa_centavos']) : '') . ')',
        'Método' => $inscricao['metodo'] === 'pix' ? 'PIX' : 'Cartão ' . ($inscricao['bandeira'] ?? '') . ' final ' . ($inscricao['ultimos4'] ?? ''),
        'Transação Unicopag' => $inscricao['unicopag_hash'],
        'Origem' => trim(($inscricao['utm_source'] ?? '') . ' ' . ($inscricao['utm_campaign'] ?? '')) ?: 'direto',
        'Escola' => mcp_escola_configurada() ? $inscricao['escola_status'] : 'sem API: criar a matrícula e aplicar os R$ 99 da inscrição',
    ];
    $tabela = '';
    $texto = '';
    foreach ($linhas as $rotulo => $valor) {
        $tabela .= '<tr><td style="padding:6px 10px 6px 0;color:#718096;white-space:nowrap">' . mcp_escapar((string) $rotulo) . '</td><td style="padding:6px 0">' . mcp_escapar((string) $valor) . '</td></tr>';
        $texto .= "$rotulo: $valor\n";
    }
    $corpo = mcp_p('Nova inscrição paga pela página de matrícula. Entrar em contato com o aluno pelo WhatsApp em até 2 dias úteis para fechar turma e horário.')
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px">' . $tabela . '</table>';
    $r = mcp_enviar_email($para, 'Inscrição paga: ' . $inscricao['nome'] . ' — ' . $inscricao['curso_nome'], mcp_moldura('Nova inscrição paga', $corpo), $texto);
    mcp_atualizar((int) $inscricao['id'], ['email_secretaria' => $r]);
    mcp_registrar((int) $inscricao['id'], 'email_secretaria', $r);
}

// ----------------------------------------------------------------------------- resposta pública
/** O que as páginas podem ver de uma inscrição. Nunca CPF completo, nunca dado de cartão. */
function mcp_publico(array $inscricao): array
{
    $acesso = $inscricao['status'] === 'pago' ? mcp_escola_acesso($inscricao) : null;
    return [
        'ok' => true,
        'token' => $inscricao['token'],
        'status' => $inscricao['status'],
        'metodo' => $inscricao['metodo'],
        'curso' => ['slug' => $inscricao['curso_slug'], 'nome' => $inscricao['curso_nome']],
        'nome' => mcp_primeiro_nome((string) $inscricao['nome']),
        'email' => $inscricao['email'],
        'inscricao_centavos' => (int) $inscricao['inscricao_centavos'],
        'taxa_centavos' => (int) $inscricao['taxa_centavos'],
        'total_centavos' => (int) $inscricao['total_centavos'],
        'pix' => $inscricao['metodo'] === 'pix' ? [
            'copia_cola' => $inscricao['pix_copia_cola'],
            'url' => $inscricao['pix_url'],
            'imagem' => $inscricao['pix_imagem'],
        ] : null,
        'cartao' => $inscricao['metodo'] === 'cartao' ? ['bandeira' => $inscricao['bandeira'], 'ultimos4' => $inscricao['ultimos4']] : null,
        'criado_em' => $inscricao['criado_em'],
        'pago_em' => $inscricao['pago_em'],
        'escola' => [
            'configurada' => mcp_escola_configurada(),
            'status' => $inscricao['escola_status'],
            'usuario' => $acesso['usuario'] ?? null,
            'url' => $acesso['acesso']['url'] ?? $acesso['url_ambiente'] ?? null,
            'senha' => $acesso['acesso']['senha'] ?? null,
            'tipo' => $acesso['acesso']['tipo'] ?? null,
        ],
        'escola_url' => (string) mcp_cfg('ESCOLA_URL', 'https://escola.cursoscruzvermelha.org'),
        'urls' => [
            'pendente' => mcp_url_pagina('pendente', (string) $inscricao['token']),
            'parabens' => mcp_url_pagina('parabens', (string) $inscricao['token']),
        ],
        'teste' => mcp_modo_teste(),
    ];
}
