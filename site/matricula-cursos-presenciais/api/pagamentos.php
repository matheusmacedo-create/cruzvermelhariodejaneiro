<?php
/**
 * POST: cria a cobrança da inscrição na Unicopag e devolve a visão pública da inscrição, com o token que as páginas
 * usam para acompanhar. Cobrança PIX pendente do mesmo CPF, curso, valor, plano, matrícula e turma (com os mesmos
 * opcionais) é reaproveitada em vez de gerar outro código.
 *
 * Pagar tudo (10/2026, spec 2 e 3.2): o plano ("taxa_e_matricula" ou "so_taxa"; sem o campo, página antiga = só a
 * taxa), as parcelas (só a opção 1 no cartão; PIX sempre 1x) e o total que a página mostrou, para conferir. Os valores
 * saem sempre do servidor (mcp_compra). Uma cobrança só, com os juros sobre o total (decisão 9), calculados pela
 * Unicopag. Ordem: plano → compra repetida (422 ja_pago) → valores → total do 1x → freios → parcelado: simulação nova
 * e validade de 48 h do total mostrado → colunas do banco (503) → PIX reaproveitado → cobrança. Os 422 de plano,
 * parcelas, total e ja_pago não cobram nada. Contrato: scratchpad/pagar-tudo-build/contrato-api.md.
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
// Recusas do checkout sem cobrança (422 de compra repetida, total, parcelas e plano): 20 por hora por IP. Sem o freio, o
// 422 "ja_pago" diria a qualquer um, sem limite, quem comprou cada curso (LGPD).
const MCP_LIMITE_RECUSAS = [20, 3600];
// Simulações na Unicopag pelo pagamentos.php: o mesmo balde e o mesmo limite do parcelas.php (40 a cada 10 min).
const MCP_LIMITE_SIMULACOES = [40, 600];

/** 429 antes de qualquer consulta quando o IP já somou recusas demais na última hora. */
function mcp_freio_recusas(): void
{
    [$maximo, $janela] = MCP_LIMITE_RECUSAS;
    if (mcp_contar_eventos_recentes('recusa_checkout', mcp_ip_balde(), $janela) >= $maximo) {
        mcp_falhar(429, 'Muitas tentativas seguidas. Aguarde alguns minutos e tente de novo.');
    }
}

/** Um 422 sem cobrança que conta no freio de recusas (o balde do IP fica em mcp_eventos, apagado em 2 dias). */
function mcp_recusar(string $mensagem, array $extra): never
{
    mcp_registrar(null, 'recusa_checkout', mcp_ip_balde());
    mcp_falhar(422, $mensagem, $extra);
}

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
    // Contribuição opcional para a divulgação (07/10/2026). A página manda o valor que mostrou; só se cobra se for o
    // valor de agora (nunca um total diferente do que a pessoa viu). Oferta: o valor mostrado, nulo se a opção não
    // apareceu (página antiga em cache), para a adesão do teste contar só quem viu a opção.
    $divulgacao = mcp_divulgacao_centavos();
    $vista = filter_var($b['divulgacao_centavos'] ?? null, FILTER_VALIDATE_INT);
    $mostrou = $divulgacao > 0 && $vista === $divulgacao;
    if (!empty($b['ajuda_divulgacao']) && !$mostrou) {
        mcp_falhar(422, $divulgacao > 0
            ? 'O valor da contribuição para a divulgação mudou para ' . mcp_brl($divulgacao) . '. Confira o novo total e pague de novo.'
            : 'A contribuição para a divulgação não está mais disponível. Confira o total e pague de novo.',
            ['campo' => 'divulgacao', 'divulgacao_centavos' => $divulgacao]);
    }
    // Pagar tudo: o plano sai só do input marcado; sem o campo (HTML antigo em cache), só a taxa, como antes (T9).
    $temPlano = array_key_exists('plano', $b);
    $plano = $temPlano ? $b['plano'] : 'so_taxa';
    $info = mcp_planos_info($curso, null, $cpf);
    if (!is_string($plano) || !in_array($plano, MCP_PLANOS, true)) {
        mcp_recusar('Escolha o plano de pagamento.', ['campo' => 'plano', 'planos' => $info['planos'], 'motivo' => $info['motivo']]);
    }
    if ($plano === 'taxa_e_matricula') {
        if (!in_array('taxa_e_matricula', $info['planos'], true)) {
            mcp_recusar(mcp_plano_mensagem($info['motivo']), ['campo' => 'plano', 'planos' => $info['planos'], 'motivo' => $info['motivo']]);
        }
        // A turma que a página mostrou: o checkout novo manda sempre o campo na opção 1 (vazio = a página mostrou o curso
        // sem turma). Se ela fechou, mudou ou apareceu uma turma, as condições mudaram e a página recarrega os planos.
        // Turma que fechou num curso da venda sem turma: a opção 1 continua, na fila da próxima turma ('prazo_fila').
        if (array_key_exists('turma_id', $b)) {
            $turmaVista = mcp_texto(is_string($b['turma_id']) ? $b['turma_id'] : '', 64);
            if ((string) ($info['turma']['id_escola'] ?? '') !== $turmaVista) {
                $motivo = $turmaVista !== '' && $info['sem_turma'] ? 'prazo_fila' : 'condicoes';
                mcp_recusar(mcp_plano_mensagem($motivo, $turmaVista !== '' ? mcp_oferta_turma($turmaVista) : null),
                    ['campo' => 'plano', 'planos' => $info['planos'], 'motivo' => $motivo]);
            }
        }
    }
    $completoCartao = $plano === 'taxa_e_matricula' && $metodo === 'cartao';
    $pedidas = $completoCartao ? filter_var($b['parcelas'] ?? 1, FILTER_VALIDATE_INT) : 1;
    $mostrado = filter_var($b['total_mostrado_centavos'] ?? null, FILTER_VALIDATE_INT);
    // Prova do aceite (F12): a versão do texto e o texto exato que a pessoa marcou. Na venda sem turma, obrigatórios; o
    // hash guardado é o do texto canônico do servidor (o mesmo que o checkout mostra), e um texto diferente fica registrado.
    $aceiteVersao = mcp_texto($b['aceite_versao'] ?? '', 20);
    $aceiteTexto = is_string($b['aceite_texto'] ?? null) ? mb_substr(trim($b['aceite_texto']), 0, 2000) : '';
    $semTurma = $plano === 'taxa_e_matricula' && $info['sem_turma'];
    if ($semTurma && ($aceiteTexto === '' || !preg_match('/^[A-Za-z0-9._-]{1,20}$/', $aceiteVersao))) {
        mcp_falhar(422, 'Confirme que leu os requisitos do curso.', ['campo' => 'requisitos']);
    }
    $aceiteCanonico = mcp_aceite_texto($curso, $plano, $semTurma);
    $aceiteDivergente = $aceiteTexto !== '' && $aceiteTexto !== $aceiteCanonico;
    $origem = is_array($b['origem'] ?? null) ? $b['origem'] : [];
    $utm = static fn(string $k, int $limite = 160): ?string => mcp_texto($origem[$k] ?? '', $limite) ?: null;
    return [
        'plano' => $plano,
        // A oferta, do servidor (T20): 'ambos' quando a opção 1 apareceu para esta pessoa; nula na página antiga.
        'plano_oferta' => $temPlano ? (in_array('taxa_e_matricula', $info['planos'], true) ? 'ambos' : 'so_taxa') : null,
        'parcelas_pedidas' => $pedidas === false ? 0 : (int) $pedidas,
        'total_mostrado' => $mostrado === false ? null : (int) $mostrado,
        'turma' => $info['turma'], 'sem_turma' => $semTurma,
        'aceite_versao' => preg_match('/^[A-Za-z0-9._-]{1,20}$/', $aceiteVersao) ? $aceiteVersao : null,
        'aceite_sha256' => $aceiteTexto !== '' ? hash('sha256', $aceiteCanonico) : null,
        'aceite_divergente' => $aceiteDivergente,
        'slug' => $slug, 'curso' => $curso, 'nome' => $nome, 'cpf' => $cpf, 'email' => $email,
        'telefone' => $telefone, 'metodo' => $metodo, 'cobre_taxa' => !empty($b['cobre_taxa']),
        'divulgacao' => $mostrou && !empty($b['ajuda_divulgacao']) ? $divulgacao : 0, 'divulgacao_oferta' => $mostrou ? $divulgacao : null,
        'utm_source' => $utm('utm_source', 120), 'utm_medium' => $utm('utm_medium', 120),
        'utm_campaign' => $utm('utm_campaign'), 'utm_content' => $utm('utm_content'), 'utm_term' => $utm('utm_term'),
        'fbclid' => $utm('fbclid', 255), 'gclid' => $utm('gclid', 255),
        // id do Lead do Pixel, para a API de Conversões mandar o mesmo evento (lib/meta.php)
        'meta_lead' => mcp_meta_id_valido($b['evento_id'] ?? null),
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

/**
 * PIX pendente recente do mesmo CPF, curso e valor: devolve o mesmo código, se ainda estiver aberto. Os opcionais
 * (custos e divulgação), o plano, a matrícula e a turma entram na comparação (T19): dois valores iguais por caminhos
 * diferentes não trocam o que se registra, e um PIX de R$ 279 nunca serve a um pedido de R$ 99.
 */
function mcp_pix_aberto(array $aluno, array $compra, ?string $turmaId, bool $colunas): ?array
{
    // O e-mail e o telefone entram na comparação: quem sabe só o CPF (dado comum) e o curso de alguém não recebe o token,
    // o e-mail nem, depois do pagamento, o link de criar senha da conta da escola dessa pessoa. Com outro e-mail ou outro
    // telefone, sai uma cobrança nova.
    $sql = "SELECT * FROM mcp_inscricoes WHERE cpf = ? AND email = ? AND telefone = ? AND curso_slug = ? AND metodo = 'pix' AND status = 'pendente'
        AND total_centavos = ? AND taxa_centavos = ? AND divulgacao_centavos = ? AND pix_copia_cola IS NOT NULL AND criado_em > ?";
    $params = [$aluno['cpf'], $aluno['email'], $aluno['telefone'], $aluno['slug'], $compra['amount'], $compra['custos'], $compra['divulgacao'], gmdate('Y-m-d H:i:s', time() - MCP_PIX_REAPROVEITA_SEGUNDOS)];
    if ($colunas) {
        $sql .= ' AND plano = ? AND matricula_centavos = ? AND turma_id <=> ?';
        array_push($params, $compra['plano'], $compra['matricula'], $turmaId);
    }
    $stmt = mcp_db()->prepare($sql . ' ORDER BY id DESC LIMIT 1');
    $stmt->execute($params);
    $aberta = $stmt->fetch();
    if (!$aberta) {
        return null;
    }
    // "Não" para marketing neste pedido: o "sim" guardado deixa de valer antes da reconsulta, que pode achar o
    // PIX pago e mandar o Purchase na hora (mcp_pos_pagamento relê a inscrição). Só retira: quem sabe o CPF de
    // outra pessoa não dá o "sim" por ela.
    mcp_meta_atualizar_escolha($aberta);
    $aberta = mcp_sincronizar($aberta);
    return ($aberta['status'] ?? '') === 'pendente' ? $aberta : null;
}

/**
 * Grava a inscrição a partir da resposta da Unicopag e devolve o id. Cartão aprovado entra como pendente e vira pago
 * em seguida, pela porta única. $extras: as colunas do pagar tudo (já calculadas pelo fluxo).
 */
function mcp_gravar_inscricao(array $aluno, string $token, array $compra, array $cobranca, ?string $ultimos4, array $extras): int
{
    $pix = $aluno['metodo'] === 'pix' && is_array($cobranca['pix'] ?? null) ? $cobranca['pix'] : [];
    $statusOrigem = mb_substr((string) ($cobranca['payment_status'] ?? ''), 0, 40);
    $status = mcp_traduzir_status($statusOrigem);
    $agora = mcp_agora();
    $limitar = static fn(mixed $v, int $n): ?string => mb_substr((string) ($v ?? ''), 0, $n) ?: null;
    $total = (int) $compra['amount'];
    $linha = [
        'token' => $token, 'curso_slug' => $aluno['slug'], 'curso_nome' => $aluno['curso']['nome'], 'nome' => $aluno['nome'],
        'cpf' => $aluno['cpf'], 'email' => $aluno['email'], 'telefone' => $aluno['telefone'], 'metodo' => $aluno['metodo'],
        'inscricao_centavos' => (int) $compra['inscricao'], 'taxa_centavos' => (int) $compra['custos'], 'divulgacao_centavos' => (int) $compra['divulgacao'],
        'total_centavos' => $total, 'cobre_taxa' => $aluno['cobre_taxa'] ? 1 : 0, 'divulgacao_oferta_centavos' => $aluno['divulgacao_oferta'],
        'status' => $status === 'pago' ? 'pendente' : $status,
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
    ] + $extras;
    $pdo = mcp_db();
    $inserir = static function (array $linha) use ($pdo): void {
        $colunas = implode(', ', array_keys($linha));
        $marcadores = implode(', ', array_fill(0, count($linha), '?'));
        $pdo->prepare("INSERT INTO mcp_inscricoes ($colunas) VALUES ($marcadores)")->execute(array_values($linha));
    };
    try {
        $inserir($linha);
    } catch (PDOException $e) {
        // Código novo com o esquema anterior (logo depois de um deploy, db.php antigo no opcache): a cobrança já existe
        // no provedor, então a inscrição é gravada sem as colunas novas (o total já inclui tudo; os itens ficam na
        // Unicopag). Só a taxa chega aqui: o plano completo responde 503 antes de cobrar (mcp_colunas_plano_ok).
        if (($e->errorInfo[0] ?? '') !== '42S22') {
            throw $e;
        }
        error_log('[matricula] inscrição gravada sem as colunas novas: ' . $e->getMessage());
        foreach (array_merge(['divulgacao_centavos', 'divulgacao_oferta_centavos'], array_keys($extras)) as $coluna) {
            unset($linha[$coluna]);
        }
        $inserir($linha);
    }
    $id = (int) $pdo->lastInsertId();
    mcp_registrar($id, 'cobranca_criada', "{$aluno['metodo']} · $statusOrigem · " . mcp_brl($total)
        . ($compra['plano'] === 'taxa_e_matricula' ? ' · taxa + matrícula' . ((int) $compra['parcelas'] > 1 ? " · {$compra['parcelas']}x" : '') : ''));
    // A escolha de marketing e os sinais para o Purchase (lib/meta.php) à parte, depois da inscrição gravada: a
    // cobrança já existe no provedor, e nada que venha do cookie ou de uma coluna nova pode impedir a inscrição.
    // Se não gravar, meta_marketing fica vazio e o Purchase não sai (falha fechada).
    try {
        $meta = mcp_meta_colunas_da_inscricao($aluno['fbclid']);
        if ($meta) {
            mcp_atualizar($id, $meta);
        }
    } catch (Throwable $e) {
        error_log('[matricula] escolha de marketing da inscrição não gravada: ' . get_class($e) . ': ' . $e->getMessage());
    }
    return $id;
}

/** 422 de parcelas ou de total, com as opções atuais (registradas como mostradas: a página vai exibi-las). */
function mcp_falhar_parcelas(string $campo, int $amount, array $opcoes, ?int $total = null): never
{
    mcp_parcelas_registrar_exibidas($amount, $opcoes);
    if ($campo === 'parcelas') {
        mcp_recusar('As parcelas mudaram. Confira o novo valor e pague de novo.', ['campo' => 'parcelas', 'opcoes' => $opcoes]);
    }
    mcp_recusar('O valor mudou para ' . mcp_brl((int) $total) . '. Confira o novo total e pague de novo.',
        ['campo' => 'total', 'total_centavos' => (int) $total, 'opcoes' => $opcoes]);
}

// ----------------------------------------------------------------------------- fluxo
$b = mcp_exigir_post_json();

// Campo armadilha: fica escondido na página; robô que preenche formulário inteiro cai aqui.
if (mcp_texto($b['site'] ?? '', 10) !== '') {
    mcp_registrar(null, 'armadilha', mcp_ip());
    mcp_falhar(422, 'Não foi possível processar o pagamento. Tente novamente.');
}

mcp_freio_recusas();
$aluno = mcp_validar_aluno($b);
$cartao = $aluno['metodo'] === 'cartao' ? mcp_validar_cartao(is_array($b['cartao'] ?? null) ? $b['cartao'] : []) : null;
unset($b);

// Compra repetida (E14): outra inscrição paga do mesmo curso pelo mesmo CPF nos últimos 180 dias.
// A resposta não diz qual plano foi pago (nem sai sem o freio de recusas): o 422 não vira consulta de quem comprou o quê.
if (mcp_compra_ja_paga($aluno['slug'], $aluno['cpf']) !== null) {
    mcp_recusar('Este CPF já tem uma compra paga deste curso. Não pague de novo: para pagar só a matrícula ou tirar dúvidas, fale com a secretaria pelo chat.',
        ['campo' => 'ja_pago']);
}

// Valores, sempre do servidor (com o CPF: o preço de teste vale só para a lista TESTE_CPFS).
$compra = mcp_compra($aluno['curso'], $aluno['plano'], $aluno['metodo'], $aluno['cobre_taxa'], $aluno['divulgacao'], max(1, $aluno['parcelas_pedidas']), $aluno['cpf']);
$completo = $compra['plano'] === 'taxa_e_matricula';
$parcelado = $completo && $aluno['metodo'] === 'cartao' && $aluno['parcelas_pedidas'] !== 1;

// À vista (PIX, cartão 1x, só a taxa): o total mostrado tem de ser o de agora. Obrigatório na opção 1 (T15); na
// página antiga, sem o campo, só a taxa segue sem conferir.
if (!$parcelado && ($completo || $aluno['total_mostrado'] !== null) && $aluno['total_mostrado'] !== $compra['amount']) {
    mcp_recusar('O valor mudou para ' . mcp_brl($compra['amount']) . '. Confira o novo total e pague de novo.',
        ['campo' => 'total', 'total_centavos' => $compra['amount'], 'opcoes' => [mcp_parcela_opcao($compra['amount'], 1, $compra['amount'])]]);
}

mcp_aplicar_limites($aluno);

// Parcelado (2.4): depois dos freios, uma simulação nova, sem cache, e a conferência do total mostrado. Total que o
// servidor mostrou nas últimas 48 h vale como oferta: se a Unicopag cobrar mais, a diferença volta por PIX (E15).
$opcao = null;
$via48h = false;
if ($parcelado) {
    $n = $aluno['parcelas_pedidas'];
    if ($n < 2 || $n > 12 || $n > mcp_parcelas_max()) {
        mcp_falhar_parcelas('parcelas', $compra['amount'], mcp_parcelas_opcoes($compra['amount']));
    }
    // Cada simulação nova vai à Unicopag: o mesmo freio do parcelas.php, para a chave do site não ser bloqueada.
    [$maximo, $janela] = MCP_LIMITE_SIMULACOES;
    if (mcp_contar_eventos_recentes('parcelas_consulta', mcp_ip_balde(), $janela) >= $maximo) {
        mcp_falhar(429, 'Muitas consultas seguidas. Aguarde alguns minutos e tente de novo.');
    }
    mcp_registrar(null, 'parcelas_consulta', mcp_ip_balde());
    $opcoes = mcp_parcelas_opcoes($compra['amount'], true);
    foreach ($opcoes as $o) {
        if ($o['n'] === $n) {
            $opcao = $o;
        }
    }
    if ($opcao === null) {
        mcp_falhar_parcelas('parcelas', $compra['amount'], $opcoes);
    }
    if ($aluno['total_mostrado'] === null) {
        mcp_falhar_parcelas('total', $compra['amount'], $opcoes, $opcao['total_centavos']);
    }
    if ($aluno['total_mostrado'] !== $opcao['total_centavos']) {
        if (!mcp_parcelas_foi_exibida($compra['amount'], $n, $aluno['total_mostrado'])) {
            mcp_falhar_parcelas('total', $compra['amount'], $opcoes, $opcao['total_centavos']);
        }
        $via48h = true;
    }
}

// Plano completo com o banco sem as colunas novas (logo depois de uma publicação): 503 antes de cobrar.
$colunas = mcp_colunas_plano_ok();
if ($completo && !$colunas) {
    mcp_falhar(503, 'O pagamento da matrícula junto está indisponível agora. Tente de novo em alguns minutos ou pague só a taxa de inscrição.', ['campo' => 'indisponivel']);
}

$turmaId = $aluno['turma']['id_escola'] ?? null;
if ($aluno['metodo'] === 'pix' && ($aberta = mcp_pix_aberto($aluno, $compra, $turmaId, $colunas))) {
    mcp_registrar((int) $aberta['id'], 'pix_reaproveitado');
    mcp_meta_cobranca_criada($aberta, $aluno, $aluno['meta_lead'], false);
    mcp_json(mcp_publico_completo($aberta) + ['reaproveitado' => true]);
}

$token = mcp_token_novo();
$payload = mcp_montar_cobranca($aluno, $token, $compra + ['turma_id' => $turmaId, 'total_mostrado' => $aluno['total_mostrado']]);
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

// As colunas do pagar tudo (3.2, 10.8). total_centavos continua o amount; o cobrado com juros vem da resposta (ou da
// reconsulta, mcp_sincronizar).
$extras = [];
if ($colunas) {
    $valores = mcp_valores_da_transacao($cobranca);
    $cobrado = $valores['total_cobrado_centavos'] ?? null;
    // Sem o cobrado na resposta, a diferença fica para a reconsulta (mcp_conferir_juros), que usa o valor de verdade.
    $diferenca = $via48h && $cobrado !== null ? max(0, $cobrado - (int) $aluno['total_mostrado']) : 0;
    $turmaInicio = mcp_iso_ts($aluno['turma']['inicio'] ?? null);
    $extras = [
        'plano' => $compra['plano'], 'plano_oferta' => $aluno['plano_oferta'],
        'matricula_centavos' => (int) $compra['matricula'],
        'matricula_preco_centavos' => (int) ($aluno['curso']['valor_curso_centavos'] ?? 0) ?: null,
        'parcelas' => $aluno['metodo'] === 'pix' ? 1 : (int) $compra['parcelas'],
        'total_mostrado_centavos' => $aluno['total_mostrado'],
        'turma_id' => $turmaId, 'turma_inicio' => $turmaInicio !== null ? gmdate('Y-m-d H:i:s', $turmaInicio) : null,
        'taxa_mes_pct' => $opcao !== null && $opcao['n'] > 1 ? $opcao['taxa_mes_pct'] : null,
        'cet_ano_pct' => $opcao !== null && $opcao['n'] > 1 ? $opcao['cet_ano_pct'] : null,
        'diferenca_devolver_centavos' => $diferenca,
        'aceite_versao' => $aluno['aceite_versao'], 'aceite_sha256' => $aluno['aceite_sha256'],
        'aceite_em' => $aluno['aceite_versao'] !== null || $aluno['aceite_sha256'] !== null ? mcp_agora() : null,
    ] + $valores;
    if ($aluno['sem_turma']) {
        // Venda sem turma (10.8): a data limite é a que o checkout mostrou (dia da compra + ESPERA_PRAZO_DIAS).
        $extras['espera_prazo'] = mcp_espera_prazo(time());
    }
}

$id = mcp_gravar_inscricao($aluno, $token, $compra, $cobranca, $cartao['ultimos4'] ?? null, $extras);
if ($aluno['aceite_divergente']) {
    mcp_registrar($id, 'aceite_divergente', (string) $aluno['aceite_versao']);
}
$inscricao = mcp_inscricao_por('id', (string) $id) ?? [];
$status = mcp_traduzir_status((string) ($cobranca['payment_status'] ?? ''));
$avisoDiferenca = [];
if ($colunas && $aluno['metodo'] === 'cartao') {
    // Cartão (1× ou parcelado): o cobrado acima do total mostrado vira diferença a devolver, com o URGENTE (2.4).
    if ((int) ($inscricao['diferenca_devolver_centavos'] ?? 0) > 0) {
        mcp_registrar($id, 'diferenca_devolver', 'mostrado ' . $aluno['total_mostrado'] . ' · cobrado ' . mcp_total_cobrado($inscricao) . ' (validade de 48 h)');
        mcp_aviso_diferenca($inscricao);
    } else {
        mcp_conferir_juros($id);
    }
    $inscricao = mcp_inscricao_por('id', (string) $id) ?? $inscricao;
    $diferenca = (int) ($inscricao['diferenca_devolver_centavos'] ?? 0);
    if ($diferenca > 0) {
        $avisoDiferenca = ['diferenca_devolver_centavos' => $diferenca,
            'aviso_diferenca' => 'Cobramos ' . mcp_brl($diferenca) . ' a mais que o valor mostrado. A diferença volta por PIX em até 2 dias úteis.'];
    }
}
// API de Conversões (só com "sim" para marketing): Lead e, se a cobrança foi aceita, AddPaymentInfo, como o Pixel.
mcp_meta_cobranca_criada($inscricao, $aluno, $aluno['meta_lead'], $status !== 'recusado');

if ($aluno['metodo'] === 'pix') {
    mcp_email_pix_aberto($inscricao);
    mcp_json(mcp_publico_completo($inscricao), 201);
}
if ($status === 'pago') {
    mcp_json(mcp_publico_completo(mcp_aplicar_status($id, 'pago')) + $avisoDiferenca, 201);
}
if ($status === 'recusado') {
    // ok = false de verdade (antes, o "+" não trocava o ok = true da visão pública): a página mostra a mensagem em #ck-erro.
    mcp_json(array_replace(mcp_publico_completo($inscricao), ['ok' => false, 'erro' => 'O pagamento não passou. O banco recusou a cobrança. Tente outro cartão.']), 402);
}
// Cartão em análise: a página acompanha pelo status.
mcp_json(mcp_publico_completo($inscricao) + $avisoDiferenca, 201);
