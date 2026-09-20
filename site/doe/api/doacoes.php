<?php
/**
 * POST: cria a cobrança da doação na Unicopag (PIX ou cartão) e devolve a visão pública da
 * doação, com o token que a página usa para acompanhar. PIX pendente do mesmo CPF e valor é
 * reaproveitado em vez de gerar outro código.
 *
 * O dado do cartão entra por aqui, vai direto para a Unicopag e é descartado: não é gravado,
 * logado nem devolvido (só bandeira e os quatro últimos dígitos, que vêm da resposta).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Freios de abuso. Doar várias vezes é legítimo, então a folga é maior que a do checkout.
const MCP_DOACAO_LIMITE_IP = [15, 600];
const MCP_DOACAO_LIMITE_CPF = [10, 3600];
const MCP_DOACAO_LIMITE_EMAIL = [10, 3600];

/** Campos de quem doa, validados. Cada falha responde 422 com o nome do campo para a página marcar. */
function mcp_doacao_validar(array $b): array
{
    $frequencia = ($b['frequencia'] ?? 'unica') === 'mensal' ? 'mensal' : 'unica';
    if ($frequencia === 'mensal' && !mcp_doacao_mensal_ativa()) {
        mcp_falhar(422, 'A doação mensal ainda não está disponível por aqui. Faça uma doação única ou escreva para nós.', ['campo' => 'frequencia']);
    }
    $valor = (int) ($b['valor_centavos'] ?? 0);
    if ($valor < mcp_doacao_minimo() || $valor > mcp_doacao_maximo()) {
        mcp_falhar(422, 'Escolha um valor entre ' . mcp_brl(mcp_doacao_minimo()) . ' e ' . mcp_brl(mcp_doacao_maximo()) . '.', ['campo' => 'valor']);
    }
    $nome = mcp_texto($b['nome'] ?? '', 160);
    if (mb_strlen($nome) < 5 || !str_contains($nome, ' ')) {
        mcp_falhar(422, 'Informe seu nome completo.', ['campo' => 'nome']);
    }
    $email = mb_strtolower(mcp_texto($b['email'] ?? '', 190));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        mcp_falhar(422, 'E-mail inválido.', ['campo' => 'email']);
    }
    $cpf = mcp_digitos(mcp_texto($b['cpf'] ?? '', 20));
    if (!mcp_cpf_valido($cpf)) {
        mcp_falhar(422, 'CPF inválido. Confira os 11 números.', ['campo' => 'cpf']);
    }
    $telefone = mcp_telefone(mcp_texto($b['telefone'] ?? '', 30));
    if ($telefone === '') {
        mcp_falhar(422, 'Informe o telefone com DDD.', ['campo' => 'telefone']);
    }
    if (empty($b['aceite'])) {
        mcp_falhar(422, 'Confirme que leu o aviso de privacidade para concluir.', ['campo' => 'aceite']);
    }
    $metodo = ($b['metodo'] ?? 'pix') === 'cartao' ? 'cartao' : 'pix';
    $origem = is_array($b['origem'] ?? null) ? $b['origem'] : [];
    $utm = static fn(string $k, int $limite = 160): ?string => mcp_texto($origem[$k] ?? '', $limite) ?: null;
    return [
        'frequencia' => $frequencia, 'valor' => $valor, 'nome' => $nome, 'cpf' => $cpf, 'email' => $email,
        'telefone' => $telefone, 'metodo' => $metodo, 'cobre_taxa' => !empty($b['cobre_taxa']),
        'anonimo' => !empty($b['anonimo']),
        'utm_source' => $utm('utm_source', 120), 'utm_medium' => $utm('utm_medium', 120),
        'utm_campaign' => $utm('utm_campaign'), 'utm_content' => $utm('utm_content'), 'utm_term' => $utm('utm_term'),
        'fbclid' => $utm('fbclid', 255), 'gclid' => $utm('gclid', 255),
    ];
}

function mcp_doacao_aplicar_limites(array $doador): void
{
    foreach ([['ip', mcp_ip(), MCP_DOACAO_LIMITE_IP], ['cpf', $doador['cpf'], MCP_DOACAO_LIMITE_CPF], ['email', $doador['email'], MCP_DOACAO_LIMITE_EMAIL]] as [$coluna, $valor, [$maximo, $janela]]) {
        if ($valor !== '' && mcp_doacao_contar_recentes($coluna, $valor, $janela) >= $maximo) {
            mcp_doacao_registrar(null, 'limite', "$coluna · $maximo em {$janela}s");
            mcp_falhar(429, 'Muitas tentativas seguidas. Aguarde alguns minutos e tente de novo.');
        }
    }
}

/** PIX pendente recente do mesmo CPF e valor: devolve o mesmo código, se ainda estiver aberto. */
function mcp_doacao_pix_aberto(array $doador, int $total): ?array
{
    $stmt = mcp_doacao_db()->prepare("SELECT * FROM mcp_doacoes WHERE cpf = ? AND metodo = 'pix' AND status = 'pendente'
        AND total_centavos = ? AND pix_copia_cola IS NOT NULL AND criado_em > ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$doador['cpf'], $total, gmdate('Y-m-d H:i:s', time() - MCP_DOACAO_PIX_REAPROVEITA_SEGUNDOS)]);
    $aberta = $stmt->fetch();
    if (!$aberta) {
        return null;
    }
    $aberta = mcp_doacao_sincronizar($aberta);
    return ($aberta['status'] ?? '') === 'pendente' ? $aberta : null;
}

function mcp_doacao_montar_cobranca(array $doador, string $token, int $valor, int $taxa): array
{
    $cart = [['hash' => 'doacao', 'title' => 'Doação · Cruz Vermelha Brasileira Rio de Janeiro', 'price' => $valor, 'quantity' => 1]];
    if ($taxa > 0) {
        $cart[] = ['hash' => 'custos-processamento', 'title' => 'Custos de processamento (opcional)', 'price' => $taxa, 'quantity' => 1];
    }
    return [
        'amount' => $valor + $taxa,
        'payment_method' => $doador['metodo'] === 'pix' ? 'pix' : 'credit_card',
        'installments' => 1,
        'customer' => ['name' => $doador['nome'], 'email' => $doador['email'], 'phone_number' => $doador['telefone'], 'document' => $doador['cpf']],
        'cart' => $cart,
        'postback_url' => mcp_site_url() . '/doe/api/webhook.php',
        'expire_in_days' => 1,
        'origin' => MCP_DOACAO_ORIGEM,
        'metadata' => [
            'token' => $token, 'tipo' => 'doacao', 'frequencia' => $doador['frequencia'], 'doador' => $doador['nome'],
            'cobre_taxa' => $doador['cobre_taxa'], 'anonima' => $doador['anonimo'],
            'utm_source' => $doador['utm_source'], 'utm_campaign' => $doador['utm_campaign'],
        ],
    ];
}

/** Grava a doação a partir da resposta da Unicopag e devolve o id. */
function mcp_doacao_gravar(array $doador, string $token, int $valor, int $taxa, array $cobranca, ?string $ultimos4): int
{
    $pix = $doador['metodo'] === 'pix' && is_array($cobranca['pix'] ?? null) ? $cobranca['pix'] : [];
    $statusOrigem = mb_substr((string) ($cobranca['payment_status'] ?? ''), 0, 40);
    $status = mcp_traduzir_status($statusOrigem);
    $agora = mcp_agora();
    $limitar = static fn(mixed $v, int $n): ?string => mb_substr((string) ($v ?? ''), 0, $n) ?: null;
    $linha = [
        'token' => $token, 'frequencia' => $doador['frequencia'], 'nome' => $doador['nome'], 'cpf' => $doador['cpf'],
        'email' => $doador['email'], 'telefone' => $doador['telefone'], 'metodo' => $doador['metodo'],
        'valor_centavos' => $valor, 'taxa_centavos' => $taxa, 'total_centavos' => $valor + $taxa,
        'cobre_taxa' => $doador['cobre_taxa'] ? 1 : 0, 'anonimo' => $doador['anonimo'] ? 1 : 0,
        'status' => $status === 'pago' ? 'pendente' : $status,
        'unicopag_hash' => $limitar($cobranca['hash'] ?? null, 64), 'unicopag_status' => $statusOrigem ?: null,
        'pix_copia_cola' => $pix ? ($pix['pix_qr_code'] ?? null) : null,
        'pix_url' => $pix ? $limitar($pix['pix_url'] ?? null, 255) : null,
        'pix_imagem' => $pix ? $limitar($pix['pix_base64'] ?? null, 255) : null,
        'bandeira' => $doador['metodo'] === 'cartao' ? $limitar($cobranca['card_brand'] ?? null, 30) : null,
        'ultimos4' => $ultimos4,
        'utm_source' => $doador['utm_source'], 'utm_medium' => $doador['utm_medium'], 'utm_campaign' => $doador['utm_campaign'],
        'utm_content' => $doador['utm_content'], 'utm_term' => $doador['utm_term'], 'fbclid' => $doador['fbclid'], 'gclid' => $doador['gclid'],
        'ip' => mcp_ip(), 'criado_em' => $agora, 'atualizado_em' => $agora, 'consultado_em' => $agora,
    ];
    $colunas = implode(', ', array_keys($linha));
    $marcadores = implode(', ', array_fill(0, count($linha), '?'));
    $pdo = mcp_doacao_db();
    $pdo->prepare("INSERT INTO mcp_doacoes ($colunas) VALUES ($marcadores)")->execute(array_values($linha));
    $id = (int) $pdo->lastInsertId();
    mcp_doacao_registrar($id, 'cobranca_criada', "{$doador['metodo']} · $statusOrigem · " . mcp_brl($valor + $taxa));
    return $id;
}

// ----------------------------------------------------------------------------- fluxo
$b = mcp_exigir_post_json();

// Campo armadilha: fica escondido na página; robô que preenche formulário inteiro cai aqui.
if (mcp_texto($b['site'] ?? '', 10) !== '') {
    mcp_doacao_registrar(null, 'armadilha', mcp_ip());
    mcp_falhar(422, 'Não foi possível processar a doação. Tente novamente.');
}

$doador = mcp_doacao_validar($b);
$cartao = $doador['metodo'] === 'cartao' ? mcp_validar_cartao(is_array($b['cartao'] ?? null) ? $b['cartao'] : []) : null;
unset($b);

$teste = (int) mcp_doacao_cfg('DOACAO_TESTE_CENTAVOS', 0);
$valor = $teste > 0 ? $teste : $doador['valor'];
$taxa = $doador['cobre_taxa'] ? mcp_taxa($doador['metodo'], $valor) : 0;

mcp_doacao_aplicar_limites($doador);

if ($doador['metodo'] === 'pix' && ($aberta = mcp_doacao_pix_aberto($doador, $valor + $taxa))) {
    mcp_doacao_registrar((int) $aberta['id'], 'pix_reaproveitado');
    mcp_json(mcp_doacao_publico($aberta) + ['reaproveitado' => true]);
}

$token = mcp_token_novo();
$payload = mcp_doacao_montar_cobranca($doador, $token, $valor, $taxa);
if ($cartao) {
    $payload['card'] = $cartao['card'];
}
try {
    $cobranca = mcp_unicopag('POST', '/public/v1/payments', $payload, mcp_doacao_chave());
} catch (McpUnicopagErro $e) {
    mcp_doacao_registrar(null, 'unicopag_recusou', $e->status . ' ' . $e->getMessage());
    mcp_falhar(in_array($e->status, [400, 402, 422], true) ? 422 : 502, $e->paraOAluno());
} finally {
    unset($payload, $cartao['card']);
}

$id = mcp_doacao_gravar($doador, $token, $valor, $taxa, $cobranca, $cartao['ultimos4'] ?? null);
$doacao = mcp_doacao_por('id', (string) $id) ?? [];
$status = mcp_traduzir_status((string) ($cobranca['payment_status'] ?? ''));

if ($doador['metodo'] === 'pix') {
    mcp_doacao_email_pix($doacao);
    mcp_json(mcp_doacao_publico($doacao), 201);
}
if ($status === 'pago') {
    mcp_json(mcp_doacao_publico(mcp_doacao_aplicar_status($id, 'pago')), 201);
}
if ($status === 'recusado') {
    mcp_json(mcp_doacao_publico($doacao) + ['ok' => false, 'erro' => 'O cartão foi recusado pelo emissor. Confira os dados, tente outro cartão ou doe com PIX.'], 402);
}
// Cartão em análise: a página acompanha pelo status.
mcp_json(mcp_doacao_publico($doacao), 201);
