<?php
/**
 * POST: cria a cobrança da inscrição na Unicopag (PIX ou cartão à vista) e devolve a visão pública
 * da inscrição, com o token que as páginas usam para acompanhar. Cobrança PIX pendente do mesmo
 * CPF, curso e valor é reaproveitada em vez de gerar outro código.
 *
 * O dado do cartão entra por aqui, vai direto para a Unicopag e é descartado: não é gravado,
 * logado nem devolvido (só bandeira e os quatro últimos dígitos, que vêm da resposta).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Limites de abuso: por IP (10 min), por CPF e por e-mail (1 h). Folga para uma pessoa, freio para robô.
const MCP_LIMITE_IP = [12, 600];
const MCP_LIMITE_CPF = [6, 3600];
const MCP_LIMITE_EMAIL = [6, 3600];
const MCP_PIX_REAPROVEITA_SEGUNDOS = 20 * 3600;

/** Campos do aluno validados. Cada falha responde 422 com o nome do campo para a página marcar. */
function mcp_validar_aluno(array $b): array
{
    $slug = mcp_texto($b['curso'] ?? '', 80);
    $curso = mcp_curso($slug);
    if (!$curso) {
        mcp_falhar(422, 'Escolha um curso válido.', ['campo' => 'curso']);
    }
    $nome = mcp_texto($b['nome'] ?? '', 160);
    if (mb_strlen($nome) < 5 || !str_contains($nome, ' ')) {
        mcp_falhar(422, 'Informe seu nome completo.', ['campo' => 'nome']);
    }
    $cpf = mcp_digitos(mcp_texto($b['cpf'] ?? '', 20));
    if (!mcp_cpf_valido($cpf)) {
        mcp_falhar(422, 'CPF inválido. Confira os 11 números.', ['campo' => 'cpf']);
    }
    $email = mb_strtolower(mcp_texto($b['email'] ?? '', 190));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        mcp_falhar(422, 'E-mail inválido.', ['campo' => 'email']);
    }
    $telefone = mcp_telefone(mcp_texto($b['telefone'] ?? '', 30));
    if ($telefone === '') {
        mcp_falhar(422, 'Informe o telefone com DDD.', ['campo' => 'telefone']);
    }
    if (empty($b['requisitos'])) {
        mcp_falhar(422, 'Confirme que leu os requisitos do curso.', ['campo' => 'requisitos']);
    }
    $metodo = ($b['metodo'] ?? 'pix') === 'cartao' ? 'cartao' : 'pix';
    $origem = is_array($b['origem'] ?? null) ? $b['origem'] : [];
    $utm = static fn(string $k, int $limite = 160): ?string => mcp_texto($origem[$k] ?? '', $limite) ?: null;
    return [
        'slug' => $slug, 'curso' => $curso, 'nome' => $nome, 'cpf' => $cpf, 'email' => $email,
        'telefone' => $telefone, 'metodo' => $metodo, 'cobre_taxa' => !empty($b['cobre_taxa']),
        'utm_source' => $utm('utm_source', 120), 'utm_medium' => $utm('utm_medium', 120),
        'utm_campaign' => $utm('utm_campaign'), 'utm_content' => $utm('utm_content'), 'utm_term' => $utm('utm_term'),
        'fbclid' => $utm('fbclid', 255), 'gclid' => $utm('gclid', 255),
    ];
}

/** Cartão validado no formato da Unicopag. Devolve também o final para guardar. */
function mcp_validar_cartao(array $c): array
{
    $numero = mcp_digitos(mcp_texto($c['numero'] ?? '', 30));
    $titular = mcp_texto($c['nome'] ?? '', 80);
    $validade = mcp_digitos(mcp_texto($c['validade'] ?? '', 10));
    $cvv = mcp_digitos(mcp_texto($c['cvv'] ?? '', 6));
    if (strlen($numero) < 13 || strlen($numero) > 19 || !mcp_luhn_valido($numero)) {
        mcp_falhar(422, 'Número do cartão inválido.', ['campo' => 'cartao_numero']);
    }
    if (mb_strlen($titular) < 3) {
        mcp_falhar(422, 'Informe o nome como está no cartão.', ['campo' => 'cartao_nome']);
    }
    if (!in_array(strlen($validade), [4, 6], true)) {
        mcp_falhar(422, 'Validade inválida. Use MM/AA.', ['campo' => 'cartao_validade']);
    }
    $mes = (int) substr($validade, 0, 2);
    $ano = (int) (strlen($validade) === 4 ? '20' . substr($validade, 2, 2) : substr($validade, 2, 4));
    $vencido = $ano < (int) gmdate('Y') || ($ano === (int) gmdate('Y') && $mes < (int) gmdate('n'));
    if ($mes < 1 || $mes > 12 || $vencido) {
        mcp_falhar(422, 'Validade inválida. Use MM/AA.', ['campo' => 'cartao_validade']);
    }
    if (strlen($cvv) < 3 || strlen($cvv) > 4) {
        mcp_falhar(422, 'Código de segurança inválido.', ['campo' => 'cartao_cvv']);
    }
    return [
        'card' => ['number' => $numero, 'holdername' => $titular, 'exp_month' => sprintf('%02d', $mes), 'exp_year' => (string) $ano, 'cvv' => $cvv],
        'ultimos4' => substr($numero, -4),
    ];
}

function mcp_aplicar_limites(array $aluno): void
{
    foreach ([['ip', mcp_ip(), MCP_LIMITE_IP], ['cpf', $aluno['cpf'], MCP_LIMITE_CPF], ['email', $aluno['email'], MCP_LIMITE_EMAIL]] as [$coluna, $valor, [$maximo, $janela]]) {
        if ($valor !== '' && mcp_contar_recentes($coluna, $valor, $janela) >= $maximo) {
            mcp_registrar(null, 'limite', "$coluna · $maximo em {$janela}s");
            mcp_falhar(429, 'Muitas tentativas seguidas. Aguarde alguns minutos e tente de novo.');
        }
    }
}

/** PIX pendente recente do mesmo CPF, curso e valor: devolve o mesmo código, se ainda estiver aberto. */
function mcp_pix_aberto(array $aluno, int $total): ?array
{
    $stmt = mcp_db()->prepare("SELECT * FROM mcp_inscricoes WHERE cpf = ? AND curso_slug = ? AND metodo = 'pix' AND status = 'pendente'
        AND total_centavos = ? AND pix_copia_cola IS NOT NULL AND criado_em > ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$aluno['cpf'], $aluno['slug'], $total, gmdate('Y-m-d H:i:s', time() - MCP_PIX_REAPROVEITA_SEGUNDOS)]);
    $aberta = $stmt->fetch();
    if (!$aberta) {
        return null;
    }
    $aberta = mcp_sincronizar($aberta);
    return ($aberta['status'] ?? '') === 'pendente' ? $aberta : null;
}

function mcp_montar_cobranca(array $aluno, string $token, int $inscricaoCentavos, int $taxa): array
{
    $cart = [['hash' => 'inscricao-' . $aluno['slug'], 'title' => $aluno['curso']['nome'] . ' — Inscrição', 'price' => $inscricaoCentavos, 'quantity' => 1]];
    if ($taxa > 0) {
        $cart[] = ['hash' => 'custos-processamento', 'title' => 'Custos de processamento (opcional)', 'price' => $taxa, 'quantity' => 1];
    }
    return [
        'amount' => $inscricaoCentavos + $taxa,
        'payment_method' => $aluno['metodo'] === 'pix' ? 'pix' : 'credit_card',
        'installments' => 1,
        'customer' => ['name' => $aluno['nome'], 'email' => $aluno['email'], 'phone_number' => $aluno['telefone'], 'document' => $aluno['cpf']],
        'cart' => $cart,
        'postback_url' => mcp_site_url() . '/matricula-cursos-presenciais/api/webhook.php',
        'expire_in_days' => 1,
        'origin' => 'matricula-cursos-presenciais',
        'metadata' => [
            'token' => $token, 'curso' => $aluno['slug'], 'curso_nome' => $aluno['curso']['nome'], 'aluno' => $aluno['nome'],
            'cobre_taxa' => $aluno['cobre_taxa'], 'utm_source' => $aluno['utm_source'], 'utm_campaign' => $aluno['utm_campaign'],
        ],
    ];
}

/** Grava a inscrição a partir da resposta da Unicopag e devolve o id. Cartão aprovado entra como pendente e vira pago em seguida, pela porta única. */
function mcp_gravar_inscricao(array $aluno, string $token, int $inscricaoCentavos, int $taxa, array $cobranca, ?string $ultimos4): int
{
    $pix = $aluno['metodo'] === 'pix' && is_array($cobranca['pix'] ?? null) ? $cobranca['pix'] : [];
    $statusOrigem = mb_substr((string) ($cobranca['payment_status'] ?? ''), 0, 40);
    $status = mcp_traduzir_status($statusOrigem);
    $agora = mcp_agora();
    $limitar = static fn(mixed $v, int $n): ?string => mb_substr((string) ($v ?? ''), 0, $n) ?: null;
    $linha = [
        'token' => $token, 'curso_slug' => $aluno['slug'], 'curso_nome' => $aluno['curso']['nome'], 'nome' => $aluno['nome'],
        'cpf' => $aluno['cpf'], 'email' => $aluno['email'], 'telefone' => $aluno['telefone'], 'metodo' => $aluno['metodo'],
        'inscricao_centavos' => $inscricaoCentavos, 'taxa_centavos' => $taxa, 'total_centavos' => $inscricaoCentavos + $taxa,
        'cobre_taxa' => $aluno['cobre_taxa'] ? 1 : 0, 'status' => $status === 'pago' ? 'pendente' : $status,
        'unicopag_hash' => $limitar($cobranca['hash'] ?? null, 64), 'unicopag_status' => $statusOrigem ?: null,
        'pix_copia_cola' => $pix ? ($pix['pix_qr_code'] ?? null) : null,
        'pix_url' => $pix ? $limitar($pix['pix_url'] ?? null, 255) : null,
        'pix_imagem' => $pix ? $limitar($pix['pix_base64'] ?? null, 255) : null,
        'bandeira' => $aluno['metodo'] === 'cartao' ? $limitar($cobranca['card_brand'] ?? null, 30) : null,
        'ultimos4' => $ultimos4,
        'utm_source' => $aluno['utm_source'], 'utm_medium' => $aluno['utm_medium'], 'utm_campaign' => $aluno['utm_campaign'],
        'utm_content' => $aluno['utm_content'], 'utm_term' => $aluno['utm_term'], 'fbclid' => $aluno['fbclid'], 'gclid' => $aluno['gclid'],
        'ip' => mcp_ip(), 'escola_status' => mcp_escola_configurada() ? 'pendente' : 'nao_aplicavel',
        'criado_em' => $agora, 'atualizado_em' => $agora, 'consultado_em' => $agora,
    ];
    $colunas = implode(', ', array_keys($linha));
    $marcadores = implode(', ', array_fill(0, count($linha), '?'));
    $pdo = mcp_db();
    $pdo->prepare("INSERT INTO mcp_inscricoes ($colunas) VALUES ($marcadores)")->execute(array_values($linha));
    $id = (int) $pdo->lastInsertId();
    mcp_registrar($id, 'cobranca_criada', "{$aluno['metodo']} · $statusOrigem · " . mcp_brl($inscricaoCentavos + $taxa));
    return $id;
}

// ----------------------------------------------------------------------------- fluxo
$b = mcp_exigir_post_json();

// Campo armadilha: fica escondido na página; robô que preenche formulário inteiro cai aqui.
if (mcp_texto($b['site'] ?? '', 10) !== '') {
    mcp_registrar(null, 'armadilha', mcp_ip());
    mcp_falhar(422, 'Não foi possível processar o pagamento. Tente novamente.');
}

$aluno = mcp_validar_aluno($b);
$cartao = $aluno['metodo'] === 'cartao' ? mcp_validar_cartao(is_array($b['cartao'] ?? null) ? $b['cartao'] : []) : null;
unset($b);

$inscricaoCentavos = mcp_inscricao_centavos();
$taxa = $aluno['cobre_taxa'] ? mcp_taxa($aluno['metodo'], $inscricaoCentavos) : 0;
$total = $inscricaoCentavos + $taxa;

mcp_aplicar_limites($aluno);

if ($aluno['metodo'] === 'pix' && ($aberta = mcp_pix_aberto($aluno, $total))) {
    mcp_registrar((int) $aberta['id'], 'pix_reaproveitado');
    mcp_json(mcp_publico($aberta) + ['reaproveitado' => true]);
}

$token = mcp_token_novo();
$payload = mcp_montar_cobranca($aluno, $token, $inscricaoCentavos, $taxa);
if ($cartao) {
    $payload['card'] = $cartao['card'];
}
try {
    $cobranca = mcp_unicopag('POST', '/public/v1/payments', $payload);
} catch (McpUnicopagErro $e) {
    mcp_registrar(null, 'unicopag_recusou', $e->status . ' ' . $e->getMessage());
    mcp_falhar(in_array($e->status, [400, 402, 422], true) ? 422 : 502, $e->paraOAluno());
} finally {
    unset($payload, $cartao['card']);
}

$id = mcp_gravar_inscricao($aluno, $token, $inscricaoCentavos, $taxa, $cobranca, $cartao['ultimos4'] ?? null);
$inscricao = mcp_inscricao_por('id', (string) $id) ?? [];
$status = mcp_traduzir_status((string) ($cobranca['payment_status'] ?? ''));

if ($aluno['metodo'] === 'pix') {
    mcp_email_pix_aberto($inscricao);
    mcp_json(mcp_publico($inscricao), 201);
}
if ($status === 'pago') {
    mcp_json(mcp_publico(mcp_aplicar_status($id, 'pago')), 201);
}
if ($status === 'recusado') {
    mcp_json(mcp_publico($inscricao) + ['ok' => false, 'erro' => 'O cartão foi recusado pelo emissor. Confira os dados ou tente outro cartão, ou pague com PIX.'], 402);
}
// Cartão em análise: a página acompanha pelo status.
mcp_json(mcp_publico($inscricao), 201);
