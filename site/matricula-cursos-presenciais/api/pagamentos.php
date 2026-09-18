<?php
/**
 * POST: cria a cobrança da inscrição na Unicopag (PIX ou cartão à vista) e devolve o token da
 * sessão de pagamento. Cobrança PIX pendente do mesmo CPF/curso/valor é reaproveitada.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
mcp_exigir_metodo('POST');

$b = mcp_corpo_json();

$slug = mcp_texto($b['curso'] ?? '', 80);
$curso = mcp_curso($slug);
if (!$curso) {
    mcp_falhar(422, 'Escolha um curso válido.', ['campo' => 'curso']);
}
$nome = mcp_texto($b['nome'] ?? '', 160);
if (mb_strlen($nome) < 5 || !str_contains($nome, ' ')) {
    mcp_falhar(422, 'Informe seu nome completo.', ['campo' => 'nome']);
}
$cpf = mcp_digitos((string) ($b['cpf'] ?? ''));
if (!mcp_cpf_valido($cpf)) {
    mcp_falhar(422, 'CPF inválido. Confira os 11 números.', ['campo' => 'cpf']);
}
$email = mb_strtolower(mcp_texto($b['email'] ?? '', 190));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    mcp_falhar(422, 'E-mail inválido.', ['campo' => 'email']);
}
$telefone = mcp_digitos((string) ($b['telefone'] ?? ''));
if (strlen($telefone) === 13 && str_starts_with($telefone, '55')) {
    $telefone = substr($telefone, 2);
}
if (strlen($telefone) < 10 || strlen($telefone) > 11) {
    mcp_falhar(422, 'Informe o WhatsApp com DDD.', ['campo' => 'telefone']);
}
if (empty($b['requisitos'])) {
    mcp_falhar(422, 'Confirme que leu os requisitos do curso.', ['campo' => 'requisitos']);
}
$metodo = ($b['metodo'] ?? 'pix') === 'cartao' ? 'cartao' : 'pix';
$cobreTaxa = !empty($b['cobre_taxa']);
$inscricaoCentavos = mcp_inscricao_centavos();
$taxa = $cobreTaxa ? mcp_taxa($metodo, $inscricaoCentavos) : 0;
$total = $inscricaoCentavos + $taxa;
$origem = is_array($b['origem'] ?? null) ? $b['origem'] : [];
$utm = fn(string $k) => mcp_texto($origem[$k] ?? '', 160) ?: null;

$pdo = mcp_db();

// Freio por IP: 12 cobranças em 10 minutos é muito para uma pessoa e pouco para um ataque.
$stmt = $pdo->prepare('SELECT COUNT(*) FROM mcp_inscricoes WHERE ip = ? AND criado_em > ?');
$stmt->execute([mcp_ip(), gmdate('Y-m-d H:i:s', time() - 600)]);
if ((int) $stmt->fetchColumn() >= 12) {
    mcp_falhar(429, 'Muitas tentativas seguidas. Aguarde alguns minutos e tente de novo.');
}

// PIX pendente recente do mesmo CPF, curso e valor: devolve o mesmo código, não cria outro.
if ($metodo === 'pix') {
    $stmt = $pdo->prepare("SELECT * FROM mcp_inscricoes WHERE cpf = ? AND curso_slug = ? AND metodo = 'pix' AND status = 'pendente'
        AND total_centavos = ? AND pix_copia_cola IS NOT NULL AND criado_em > ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$cpf, $slug, $total, gmdate('Y-m-d H:i:s', time() - 20 * 3600)]);
    $aberta = $stmt->fetch();
    if ($aberta) {
        $aberta = mcp_sincronizar($aberta);
        if (($aberta['status'] ?? '') === 'pendente') {
            mcp_registrar((int) $aberta['id'], 'pix_reaproveitado');
            mcp_json(mcp_publico($aberta) + ['reaproveitado' => true]);
        }
    }
}

// Cartão: validado aqui e repassado; nunca gravado, logado nem devolvido.
$cartao = null;
if ($metodo === 'cartao') {
    $c = is_array($b['cartao'] ?? null) ? $b['cartao'] : [];
    $numero = mcp_digitos((string) ($c['numero'] ?? ''));
    $titular = mcp_texto($c['nome'] ?? '', 80);
    $validade = mcp_digitos((string) ($c['validade'] ?? ''));
    $cvv = mcp_digitos((string) ($c['cvv'] ?? ''));
    if (strlen($numero) < 13 || strlen($numero) > 19) {
        mcp_falhar(422, 'Número do cartão inválido.', ['campo' => 'cartao_numero']);
    }
    if (mb_strlen($titular) < 3) {
        mcp_falhar(422, 'Informe o nome como está no cartão.', ['campo' => 'cartao_nome']);
    }
    if (!in_array(strlen($validade), [4, 6], true)) {
        mcp_falhar(422, 'Validade inválida. Use MM/AA.', ['campo' => 'cartao_validade']);
    }
    $mes = substr($validade, 0, 2);
    $ano = strlen($validade) === 4 ? '20' . substr($validade, 2, 2) : substr($validade, 2, 4);
    if ((int) $mes < 1 || (int) $mes > 12 || (int) $ano < (int) gmdate('Y')) {
        mcp_falhar(422, 'Validade inválida. Use MM/AA.', ['campo' => 'cartao_validade']);
    }
    if (strlen($cvv) < 3 || strlen($cvv) > 4) {
        mcp_falhar(422, 'Código de segurança inválido.', ['campo' => 'cartao_cvv']);
    }
    $cartao = ['number' => $numero, 'holdername' => $titular, 'exp_month' => $mes, 'exp_year' => $ano, 'cvv' => $cvv];
    $ultimos4 = substr($numero, -4);
    unset($numero, $cvv, $c, $b['cartao']);
}

$token = mcp_token_novo();
$cart = [['hash' => 'inscricao-' . $slug, 'title' => $curso['nome'] . ' — Inscrição', 'price' => $inscricaoCentavos, 'quantity' => 1]];
if ($taxa > 0) {
    $cart[] = ['hash' => 'custos-processamento', 'title' => 'Custos de processamento (opcional)', 'price' => $taxa, 'quantity' => 1];
}
$payload = [
    'amount' => $total,
    'payment_method' => $metodo === 'pix' ? 'pix' : 'credit_card',
    'installments' => 1,
    'customer' => ['name' => $nome, 'email' => $email, 'phone_number' => $telefone, 'document' => $cpf],
    'cart' => $cart,
    'postback_url' => mcp_site_url() . '/matricula-cursos-presenciais/api/webhook.php',
    'expire_in_days' => 1,
    'origin' => 'matricula-cursos-presenciais',
    'metadata' => [
        'token' => $token, 'curso' => $slug, 'curso_nome' => $curso['nome'], 'aluno' => $nome, 'cpf' => $cpf,
        'email' => $email, 'whatsapp' => $telefone, 'cobre_taxa' => $cobreTaxa,
        'utm_source' => $utm('utm_source'), 'utm_campaign' => $utm('utm_campaign'),
    ],
];
if ($cartao) {
    $payload['card'] = $cartao;
}

try {
    $cobranca = mcp_unicopag('POST', '/public/v1/payments', $payload);
} catch (McpUnicopagErro $e) {
    mcp_registrar(null, 'unicopag_recusou', $e->status . ' ' . $e->getMessage());
    mcp_falhar(in_array($e->status, [400, 402, 422], true) ? 422 : 502, $e->paraOAluno());
} finally {
    unset($payload, $cartao);
}

$statusOrigem = (string) ($cobranca['payment_status'] ?? '');
$status = mcp_traduzir_status($statusOrigem);
$pix = is_array($cobranca['pix'] ?? null) ? $cobranca['pix'] : [];
$agora = mcp_agora();
$pdo->prepare('INSERT INTO mcp_inscricoes (token, curso_slug, curso_nome, nome, cpf, email, telefone, metodo, inscricao_centavos, taxa_centavos,
    total_centavos, cobre_taxa, status, unicopag_hash, unicopag_status, pix_copia_cola, pix_url, pix_imagem, bandeira, ultimos4,
    utm_source, utm_medium, utm_campaign, utm_content, utm_term, fbclid, gclid, ip, escola_status, criado_em, atualizado_em, consultado_em)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
    $token, $slug, $curso['nome'], $nome, $cpf, $email, $telefone, $metodo, $inscricaoCentavos, $taxa,
    $total, $cobreTaxa ? 1 : 0, $status === 'pago' ? 'pendente' : $status, mb_substr((string) ($cobranca['hash'] ?? ''), 0, 64), mb_substr($statusOrigem, 0, 40),
    $metodo === 'pix' ? ($pix['pix_qr_code'] ?? null) : null, $metodo === 'pix' ? mb_substr((string) ($pix['pix_url'] ?? ''), 0, 255) ?: null : null,
    $metodo === 'pix' ? mb_substr((string) ($pix['pix_base64'] ?? ''), 0, 255) ?: null : null,
    $metodo === 'cartao' ? mb_substr((string) ($cobranca['card_brand'] ?? ''), 0, 30) ?: null : null, $metodo === 'cartao' ? ($ultimos4 ?? null) : null,
    $utm('utm_source'), $utm('utm_medium'), $utm('utm_campaign'), $utm('utm_content'), $utm('utm_term'),
    mcp_texto($origem['fbclid'] ?? '', 255) ?: null, mcp_texto($origem['gclid'] ?? '', 255) ?: null, mcp_ip(),
    mcp_escola_configurada() ? 'pendente' : 'nao_aplicavel', $agora, $agora, $agora,
]);
$id = (int) $pdo->lastInsertId();
mcp_registrar($id, 'cobranca_criada', "$metodo · $statusOrigem · " . mcp_brl($total));

$inscricao = mcp_inscricao_por('id', (string) $id) ?? [];
if ($metodo === 'pix') {
    mcp_email_pix_aberto($inscricao);
    mcp_json(mcp_publico($inscricao), 201);
}
if ($status === 'pago') {
    $inscricao = mcp_aplicar_status($id, 'pago');
    mcp_json(mcp_publico($inscricao), 201);
}
if ($status === 'recusado') {
    mcp_json(mcp_publico($inscricao) + ['ok' => false, 'erro' => 'O cartão foi recusado pelo emissor. Confira os dados ou tente outro cartão, ou pague com PIX.'], 402);
}
// Cartão em análise: a página acompanha pelo status.
mcp_json(mcp_publico($inscricao), 201);
