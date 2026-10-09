<?php
/** Cliente da Unicopag, tradução de status e a única porta de mudança de status. */
declare(strict_types=1);

class McpUnicopagErro extends RuntimeException
{
    public function __construct(public int $status, string $mensagem, public ?array $campos = null)
    {
        parent::__construct($mensagem);
    }

    /** Mensagem que faz sentido mostrar ao aluno: a primeira de campo, ou a geral de validação. */
    public function paraOAluno(): string
    {
        foreach ($this->campos ?? [] as $mensagens) {
            if (is_array($mensagens) && isset($mensagens[0]) && is_string($mensagens[0])) {
                return $mensagens[0];
            }
            if (is_string($mensagens)) {
                return $mensagens;
            }
        }
        if (in_array($this->status, [400, 402, 422], true) && $this->getMessage() !== '') {
            return $this->getMessage();
        }
        return 'Não foi possível processar o pagamento. Tente novamente.';
    }
}

/**
 * Chamada à API. `$corpo` pode conter o cartão: por isso nada do corpo entra em log ou exceção,
 * só o método, o caminho e o erro de transporte. `$chave` permite usar outra conta da Unicopag
 * (as doações têm conta própria); sem ela vale a UNICO_API_KEY do checkout. `$tempo`: segundos de espera
 * (a simulação de parcelas usa menos que a cobrança).
 */
function mcp_unicopag(string $metodo, string $caminho, ?array $corpo = null, ?string $chave = null, ?int $tempo = null): array
{
    $chave = (string) ($chave ?? mcp_cfg('UNICO_API_KEY', ''));
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
        CURLOPT_CONNECTTIMEOUT => min(10, $tempo ?? 10),
        CURLOPT_TIMEOUT => $tempo ?? 55,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE));
    }
    $resposta = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);
    if ($resposta === false) {
        error_log("[matricula] Unicopag inacessível ($metodo $caminho): $erroCurl");
        throw new McpUnicopagErro(502, 'Gateway de pagamento indisponível. Tente novamente.');
    }
    $dados = json_decode((string) $resposta, true);
    if ($status >= 400 || !is_array($dados)) {
        $mensagem = is_array($dados) && !empty($dados['message']) ? (string) $dados['message'] : "Unicopag respondeu $status.";
        $campos = is_array($dados) && isset($dados['errors']) && is_array($dados['errors']) ? $dados['errors'] : null;
        throw new McpUnicopagErro($status, $mensagem, $campos);
    }
    return $dados;
}

/** Status da Unicopag nos cinco que o banco conhece. Desconhecido = pendente: o único palpite que não causa dano. */
function mcp_traduzir_status(?string $origem): string
{
    static $mapa = [
        'waiting_payment' => 'pendente', 'pending' => 'pendente', 'processing' => 'pendente', 'antifraud' => 'pendente',
        'paid' => 'pago', 'pre_chargeback' => 'pago',
        'refused' => 'recusado', 'failed' => 'recusado',
        'cancelled' => 'expirado', 'canceled' => 'expirado', 'expired' => 'expirado',
        'refunded' => 'estornado', 'chargeback' => 'estornado',
    ];
    return $mapa[strtolower(trim((string) $origem))] ?? 'pendente';
}

/**
 * Reconsulta a transação na Unicopag e aplica o resultado. Postback e polling passam por aqui. Pagar tudo (3.2): grava
 * o cobrado com juros (amount_total) e os juros (amount_interest, ou amount_total − amount), confere com o total
 * mostrado no parcelado e trata o pré-chargeback uma vez só. Nunca casa inscrição pelo amount_total.
 */
function mcp_sincronizar(array $inscricao): array
{
    if (empty($inscricao['unicopag_hash'])) {
        return $inscricao;
    }
    $id = (int) $inscricao['id'];
    try {
        $transacao = mcp_unicopag('GET', '/public/v1/transactions/' . rawurlencode((string) $inscricao['unicopag_hash']));
    } catch (McpUnicopagErro $e) {
        mcp_registrar($id, 'consulta_falhou', $e->status . ' ' . $e->getMessage());
        mcp_atualizar($id, ['consultado_em' => mcp_agora()]);
        return $inscricao;
    }
    $statusOrigem = (string) ($transacao['payment_status'] ?? '');
    $campos = ['consultado_em' => mcp_agora(), 'unicopag_status' => mb_substr($statusOrigem, 0, 40)];
    // function_exists: na publicação, o opcache pode servir este arquivo com o lib.php antigo (sem o compra.php) por alguns
    // segundos; a reconsulta do status.php e do webhook não pode quebrar nessa janela (T10).
    $colunas = function_exists('mcp_colunas_plano_ok') && mcp_colunas_plano_ok();
    if ($colunas) {
        $campos += mcp_valores_da_transacao($transacao, $inscricao);
    }
    mcp_atualizar($id, $campos);
    if ($colunas && isset($campos['total_cobrado_centavos'])) {
        mcp_conferir_juros($id);
    }
    if ($colunas && strtolower(trim($statusOrigem)) === 'pre_chargeback') {
        mcp_pre_chargeback($id);
    }
    return mcp_aplicar_status($id, mcp_traduzir_status($statusOrigem));
}

/**
 * Da resposta da Unicopag (cobrança ou reconsulta): total_cobrado_centavos ← amount_total e juros_centavos ←
 * amount_interest (ou amount_total − amount, nunca negativo). Na primeira vez, amount ≠ total_centavos registra
 * valor_divergente.
 */
function mcp_valores_da_transacao(array $transacao, ?array $inscricao = null): array
{
    $numero = static fn(mixed $v): ?int => is_numeric($v) ? (int) round((float) $v) : null;
    $total = $numero($transacao['amount_total'] ?? null);
    if ($total === null || $total <= 0) {
        return [];
    }
    $amount = $numero($transacao['amount'] ?? null);
    $juros = $numero($transacao['amount_interest'] ?? null) ?? ($amount !== null ? $total - $amount : 0);
    if ($inscricao !== null && ($inscricao['total_cobrado_centavos'] ?? null) === null && $amount !== null && $amount !== (int) $inscricao['total_centavos']) {
        mcp_registrar((int) $inscricao['id'], 'valor_divergente', "amount $amount · total_centavos {$inscricao['total_centavos']}");
    }
    return ['total_cobrado_centavos' => $total, 'juros_centavos' => max(0, $juros)];
}

/**
 * Cartão (2.4, passo 4): cobrou mais que o total mostrado, além da folga de n centavos do arredondamento (no 1×, 1
 * centavo). Vale para o parcelado, para o 1× da opção 1 e para só a taxa: nunca cobrar diferente do que a pessoa viu.
 * Sem o total mostrado (página antiga), o total sem juros. Grava a diferença (trava no UPDATE; uma diferença maior que a
 * gravada, quando o cobrado de verdade chega depois de uma estimativa, substitui a anterior), registra juros_divergente
 * e manda o URGENTE à secretaria com o valor certo.
 */
function mcp_conferir_juros(int $id): void
{
    $i = mcp_inscricao_por('id', (string) $id);
    if (!$i || ($i['metodo'] ?? '') !== 'cartao' || ($i['total_cobrado_centavos'] ?? null) === null) {
        return;
    }
    $n = max(1, (int) ($i['parcelas'] ?? 1));
    $mostrado = (int) ($i['total_mostrado_centavos'] ?? $i['total_centavos']);
    $diferenca = (int) $i['total_cobrado_centavos'] - $mostrado;
    if ($diferenca <= $n) {
        return;
    }
    $stmt = mcp_db()->prepare('UPDATE mcp_inscricoes SET diferenca_devolver_centavos = ?, atualizado_em = ? WHERE id = ? AND diferenca_devolver_centavos < ?');
    $stmt->execute([$diferenca, mcp_agora(), $id, $diferenca]);
    if ($stmt->rowCount() !== 1) {
        return;
    }
    mcp_registrar($id, 'juros_divergente', 'cobrado ' . $i['total_cobrado_centavos'] . ' · mostrado ' . $mostrado . " · {$n}x");
    error_log("[matricula] juros divergentes na inscrição $id: o cartão cobrou mais que o total mostrado (baixar PARCELAS_MAX para 1 até entender)");
    mcp_aviso_diferenca(mcp_inscricao_por('id', (string) $id) ?? $i);
}

/** URGENTE à secretaria: diferença a devolver (2.4). */
function mcp_aviso_diferenca(array $i): void
{
    $diferenca = (int) ($i['diferenca_devolver_centavos'] ?? 0);
    if ($diferenca <= 0) {
        return;
    }
    $n = (int) ($i['parcelas'] ?? 1);
    mcp_aviso_secretaria(
        'URGENTE: diferença a devolver — ' . $i['nome'] . ' — ' . $i['curso_nome'],
        'Diferença a devolver',
        'A Unicopag cobrou ' . mcp_brl(mcp_total_cobrado($i)) . " no cartão em {$n}× e o checkout mostrou "
            . mcp_brl((int) $i['total_mostrado_centavos']) . '. <strong>Devolver por PIX a diferença de ' . mcp_brl($diferenca)
            . ', para uma conta no nome do aluno, em até 2 dias úteis</strong> (ou estornar a compra inteira, com aviso ao aluno). A TI baixa PARCELAS_MAX para 1 até entender o caso.',
        mcp_aviso_linhas($i),
        (string) $i['email'],
        (int) $i['id']
    );
    // E o aluno recebe "Cobramos {diferença} a mais que o valor mostrado. A diferença volta por PIX em até 2 dias úteis."
    // (o modelo é de email.php; sem ele, só a tela mostra o aviso).
    mcp_espera_email_pessoa('diferenca_aluno', $i);
}

/** Pré-chargeback (T26): um aviso só por inscrição, com trava no UPDATE (postback e consulta da tela em paralelo). */
function mcp_pre_chargeback(int $id): void
{
    $stmt = mcp_db()->prepare('UPDATE mcp_inscricoes SET pre_chargeback_avisado_em = ? WHERE id = ? AND pre_chargeback_avisado_em IS NULL');
    $stmt->execute([mcp_agora(), $id]);
    if ($stmt->rowCount() !== 1) {
        return;
    }
    $i = mcp_inscricao_por('id', (string) $id);
    if (!$i) {
        return;
    }
    mcp_registrar($id, 'pre_chargeback');
    $espera = in_array($i['espera_status'] ?? null, ['aguardando', 'turma', 'devolver'], true);
    mcp_aviso_secretaria(
        'URGENTE: pré-chargeback de ' . $i['nome'] . ' — ' . $i['curso_nome'],
        'Pré-chargeback',
        'A Unicopag avisou um pré-chargeback desta compra (o titular do cartão abriu uma contestação). Fale com o aluno e, se for o caso, estorne no painel da Unicopag antes da disputa.'
            . ($espera ? ' <strong>Compra sem turma: devolver hoje evita a disputa.</strong>' : ''),
        mcp_aviso_linhas($i),
        (string) $i['email'],
        $id
    );
}

/**
 * Aplica um status vindo do provedor respeitando as transições: pago é definitivo (só estorno
 * o altera); recusado e expirado só valem sobre pendente. O UPDATE condicional é atômico, então
 * postback e polling simultâneos disparam o pós-pagamento (e o pós-estorno) uma única vez.
 */
function mcp_aplicar_status(int $id, string $novo): array
{
    $pdo = mcp_db();
    $agora = mcp_agora();
    $transicoes = [
        'pago' => ["UPDATE mcp_inscricoes SET status = 'pago', pago_em = ?, atualizado_em = ? WHERE id = ? AND status <> 'pago' AND status <> 'estornado'", [$agora, $agora, $id]],
        'recusado' => ["UPDATE mcp_inscricoes SET status = 'recusado', atualizado_em = ? WHERE id = ? AND status = 'pendente'", [$agora, $id]],
        'expirado' => ["UPDATE mcp_inscricoes SET status = 'expirado', atualizado_em = ? WHERE id = ? AND status = 'pendente'", [$agora, $id]],
        'estornado' => ["UPDATE mcp_inscricoes SET status = 'estornado', atualizado_em = ? WHERE id = ? AND status IN ('pago','pendente')", [$agora, $id]],
    ];
    if (isset($transicoes[$novo])) {
        [$sql, $params] = $transicoes[$novo];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() > 0) {
            mcp_registrar($id, $novo);
            if ($novo === 'pago') {
                mcp_pos_pagamento($id);
            } elseif ($novo === 'estornado') {
                mcp_pos_estorno($id);
            }
        }
    }
    return mcp_inscricao_por('id', (string) $id) ?? [];
}

/**
 * Roda uma vez, na transição para pago: escola (se houver API), e-mail do aluno, aviso à secretaria, Purchase à Meta.
 * Pagar tudo: no parcelado sem o cobrado gravado, uma reconsulta busca os juros antes da escola (se falhar, segue com
 * o amount e juros 0). Opção 1 vendida sem turma (10.8): entra na fila da próxima turma e a escola recebe só a
 * chamada so_conta (cria a conta; quem procura turma é a rotina, por ordem de pagamento).
 */
function mcp_pos_pagamento(int $id): void
{
    $inscricao = mcp_inscricao_por('id', (string) $id);
    if (!$inscricao) {
        return;
    }
    $completo = ($inscricao['plano'] ?? 'so_taxa') === 'taxa_e_matricula';
    $semTurma = $completo && empty($inscricao['turma_id']);
    // A compra sem turma entra na fila antes de qualquer reconsulta: se o pós-pagamento parar daqui em diante, nenhuma
    // retentativa (E17, status.php) a manda à escola sem espera_turma (10.8).
    if ($semTurma) {
        mcp_espera_entrar($id);
    }
    if ($completo && (int) ($inscricao['parcelas'] ?? 1) > 1 && ($inscricao['total_cobrado_centavos'] ?? null) === null) {
        $inscricao = mcp_sincronizar($inscricao) ?: $inscricao;
        // A reconsulta achou a compra estornada (ou outra mudança): o pós-estorno já cuidou dela; nada de escola nem e-mail.
        if (($inscricao['status'] ?? '') !== 'pago') {
            return;
        }
    }
    if ($semTurma) {
        if (mcp_escola_configurada()) {
            mcp_atualizar($id, ['escola_status' => 'pendente']);
            mcp_espera_chamar($id, true);
        }
        $inscricao = mcp_inscricao_por('id', (string) $id) ?? $inscricao;
    } elseif (mcp_escola_configurada()) {
        mcp_atualizar($id, ['escola_status' => 'pendente']);
        mcp_escola_tentar($id);
        $inscricao = mcp_inscricao_por('id', (string) $id) ?? $inscricao;
    }
    mcp_email_aluno_pago($inscricao);
    mcp_email_secretaria($inscricao);
    mcp_meta_compra($inscricao);
}

/**
 * Na transição para estornado (uma vez só): o aviso de estorno à secretaria (textos a, b, c e d da 1.14 e da 10.6) e,
 * na compra que esperava turma, o e-mail "Devolução feita" à pessoa. O espera_status fica como estava: o status
 * 'estornado' encerra a espera.
 */
function mcp_pos_estorno(int $id): void
{
    $i = mcp_inscricao_por('id', (string) $id);
    if (!$i) {
        return;
    }
    mcp_registrar($id, 'aviso_estorno', ($i['plano'] ?? 'so_taxa') . (empty($i['espera_status']) ? '' : ' · espera ' . $i['espera_status']));
    // Chargeback (disputa perdida no cartão) não é estorno pedido: o dinheiro saiu por decisão do banco. A secretaria recebe
    // o URGENTE próprio; a pessoa não recebe "Devolução feita" e ninguém devolve nada por PIX.
    if (strtolower(trim((string) ($i['unicopag_status'] ?? ''))) === 'chargeback') {
        mcp_registrar($id, 'chargeback');
        mcp_aviso_secretaria('URGENTE: chargeback (disputa perdida) — ' . $i['nome'] . ' — ' . $i['curso_nome'], 'Chargeback',
            'O banco do titular do cartão estornou esta compra numa disputa (chargeback): o valor já saiu do saldo da Unicopag. <strong>NÃO devolva nada por PIX e não estorne de novo.</strong> '
                . 'Confira na escola: se a matrícula continuar paga, cancele-a (Admin → aluno → matrícula → Cancelar inscrição) e marque "Já resolvi na escola" na ficha. Se a compra foi legítima, fale com a Unicopag sobre a contestação.',
            mcp_aviso_linhas($i), (string) $i['email'], $id);
        return;
    }
    $para = (string) mcp_cfg('EMAIL_SECRETARIA', '');
    if ($para !== '') {
        $m = function_exists('mcp_montar_email_estorno_secretaria') ? mcp_montar_email_estorno_secretaria($i) : mcp_estorno_secretaria_padrao($i);
        $r = mcp_enviar_email($para, $m['assunto'], $m['html'], $m['texto'], (string) $i['email']);
        mcp_registrar($id, 'email_estorno_secretaria', $r);
    }
    if (!empty($i['espera_status']) && ($i['plano'] ?? '') === 'taxa_e_matricula') {
        mcp_espera_email_pessoa('espera_devolucao', $i, ['feita']);
    }
}

/**
 * Reserva do aviso de estorno à secretaria, com os textos da 1.14 e da 10.6, enquanto email.php não tiver
 * mcp_montar_email_estorno_secretaria().
 */
function mcp_estorno_secretaria_padrao(array $i): array
{
    $total = mcp_brl(mcp_total_cobrado($i));
    $avisos = mcp_escola_avisos($i);
    $acesso = mcp_escola_acesso($i);
    $completo = ($i['plano'] ?? 'so_taxa') === 'taxa_e_matricula';
    $matriculado = ($acesso['resultado'] ?? '') === 'matriculado' || !empty($i['espera_matricula_escola']);
    if (in_array('curso_ja_pago', $avisos, true) || (!$completo && mcp_compra_completa_paga_do_cpf($i))) {
        $texto = 'Estorno do pagamento em dobro. NÃO cancele a matrícula na escola: ela continua paga.';
    } elseif ($completo && !empty($i['espera_status']) && !$matriculado) {
        $texto = "A Unicopag confirmou o estorno desta compra sem turma ($total). Não há matrícula na escola: nada a fazer lá.";
    } elseif ($completo) {
        $texto = "A Unicopag confirmou o estorno desta compra ($total). Confira na escola: se a matrícula não estiver como Estornada, cancele-a (Admin → aluno → matrícula → Cancelar inscrição). O aluno não deve ficar com a matrícula paga na escola. Depois, marque \"Já resolvi na escola\" na ficha da inscrição.";
    } else {
        $texto = "A Unicopag confirmou o estorno desta inscrição ($total). Confira se a taxa foi desfeita na escola.";
    }
    return mcp_aviso_montar('Estorno confirmado: ' . $i['nome'] . ' — ' . $i['curso_nome'], 'Estorno confirmado', mcp_escapar($texto), mcp_aviso_linhas($i));
}

/** Outra inscrição paga taxa_e_matricula do mesmo CPF e curso (texto a do estorno: a desta é o dobro). */
function mcp_compra_completa_paga_do_cpf(array $i): bool
{
    if (!function_exists('mcp_colunas_plano_ok') || !mcp_colunas_plano_ok()) {
        return false;
    }
    $stmt = mcp_db()->prepare("SELECT 1 FROM mcp_inscricoes WHERE cpf = ? AND curso_slug = ? AND id <> ? AND status = 'pago' AND plano = 'taxa_e_matricula' LIMIT 1");
    $stmt->execute([$i['cpf'], $i['curso_slug'], (int) $i['id']]);
    return (bool) $stmt->fetchColumn();
}

/** As linhas da caixa dos avisos internos (as do aviso de pagamento, 1.14). */
function mcp_aviso_linhas(array $i): array
{
    $completo = ($i['plano'] ?? 'so_taxa') === 'taxa_e_matricula';
    $n = max(1, (int) ($i['parcelas'] ?? 1));
    $partes = mcp_parcela_da_inscricao($i);
    $decomposicao = 'taxa ' . mcp_brl((int) $i['inscricao_centavos'])
        . ($completo ? ' + matrícula ' . mcp_brl((int) ($i['matricula_centavos'] ?? 0)) : '')
        . ((int) $i['taxa_centavos'] > 0 ? ' + custos ' . mcp_brl((int) $i['taxa_centavos']) : '')
        . ((int) ($i['divulgacao_centavos'] ?? 0) > 0 ? ' + divulgação ' . mcp_brl((int) $i['divulgacao_centavos']) : '')
        . ((int) ($i['juros_centavos'] ?? 0) > 0 ? ' + juros ' . mcp_brl((int) $i['juros_centavos']) : '');
    $linhas = [
        'Curso' => $i['curso_nome'],
        'Aluno' => $i['nome'],
        'CPF' => $i['cpf'],
        'E-mail' => $i['email'],
        'Telefone' => $i['telefone'],
        'Pago' => mcp_brl(mcp_total_cobrado($i)) . " ($decomposicao)",
        'Plano' => $completo ? 'Taxa de inscrição + matrícula' : 'Só a taxa de inscrição',
        'Parcelas' => $n > 1 ? "{$n}× de " . mcp_brl($partes['parcela']) . ' (juros ' . mcp_brl((int) ($i['juros_centavos'] ?? 0)) . ')' : 'à vista',
        'Método' => $i['metodo'] === 'pix' ? 'PIX' : 'Cartão ' . ($i['bandeira'] ?? '') . ' final ' . ($i['ultimos4'] ?? ''),
        'Transação Unicopag' => $i['unicopag_hash'],
    ];
    if (!empty($i['espera_prazo'])) {
        $linhas['Data limite'] = mcp_espera_data_limite((string) $i['espera_prazo']);
    }
    $linhas['Escola'] = mcp_escola_resumo($i);
    return $linhas;
}

/** Aviso interno à secretaria pronto (moldura do aviso de pagamento). */
function mcp_aviso_montar(string $assunto, string $titulo, string $paragrafoHtml, array $linhas = []): array
{
    $texto = strip_tags(str_replace(['<br>', '</p>'], "\n", $paragrafoHtml)) . "\n\n";
    foreach ($linhas as $rotulo => $valor) {
        $texto .= "$rotulo: $valor\n";
    }
    return [
        'assunto' => $assunto,
        'html' => mcp_moldura($titulo, mcp_p($paragrafoHtml) . ($linhas ? mcp_caixa($linhas) : ''),
            ['eyebrow' => 'Aviso interno · matrícula cursos presenciais', 'motivo' => 'Aviso automático do checkout de cruzvermelhariodejaneiro.org para a secretaria.']),
        'texto' => $texto,
    ];
}

/**
 * Manda um aviso interno à EMAIL_SECRETARIA (desligado enquanto ela estiver vazia). Devolve o resultado do envio ('' =
 * sem destinatário). $paragrafoHtml já vem escapado/montado por quem chama, só com dados do banco.
 */
function mcp_aviso_secretaria(string $assunto, string $titulo, string $paragrafoHtml, array $linhas = [], ?string $responderPara = null, ?int $id = null): string
{
    $para = (string) mcp_cfg('EMAIL_SECRETARIA', '');
    if ($para === '') {
        return '';
    }
    $m = mcp_aviso_montar($assunto, $titulo, $paragrafoHtml, $linhas);
    $responder = $responderPara !== null && filter_var($responderPara, FILTER_VALIDATE_EMAIL) ? $responderPara : null;
    $r = mcp_enviar_email($para, $m['assunto'], $m['html'], $m['texto'], $responder);
    mcp_registrar($id, 'aviso_secretaria', mb_substr($assunto, 0, 60) . " · $r");
    return $r;
}

/**
 * Varredura do pós-pagamento (E17, T4), no cron de 15 minutos (api/comparecimentos.php). Retoma o que um pós-pagamento
 * interrompido deixou para trás:
 *  (a) pagas com a escola 'pendente' ou 'erro', menos de 5 tentativas e pagas há mais de 3 min: nova tentativa (as que
 *      esperam turma têm a rotina própria); e o e-mail do aluno que ficou sem sair;
 *  (b) pagas sem o aviso à secretaria (só com EMAIL_SECRETARIA);
 *  (c) cartão pendente há mais de 5 min: reconsulta (cobre o postback que chegou antes do INSERT).
 * Plano completo que esgota as 5 tentativas: o aviso à secretaria de novo, agora URGENTE (uma vez só).
 */
function mcp_pos_pagamento_varrer(?int $agora = null): array
{
    $agora ??= time();
    $r = ['escola' => 0, 'emails' => 0, 'secretaria' => 0, 'cartoes' => 0, 'esgotadas' => 0];
    $pdo = mcp_db();
    $tresMin = gmdate('Y-m-d H:i:s', $agora - 180);
    $semana = gmdate('Y-m-d H:i:s', $agora - 7 * 86400);
    $espera = function_exists('mcp_colunas_plano_ok') && mcp_colunas_plano_ok() ? 'AND espera_status IS NULL' : '';
    if (mcp_escola_configurada()) {
        $stmt = $pdo->prepare("SELECT id FROM mcp_inscricoes WHERE status = 'pago' AND escola_status IN ('pendente','erro') AND escola_tentativas < ?
            AND pago_em < ? AND pago_em > ? $espera ORDER BY pago_em LIMIT 20");
        $stmt->execute([MCP_ESCOLA_MAX_TENTATIVAS, $tresMin, $semana]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = (int) $id;
            mcp_escola_tentar($id);
            $r['escola']++;
            $i = mcp_inscricao_por('id', (string) $id);
            if (!$i) {
                continue;
            }
            if ($i['email_aluno'] === null || ($i['escola_status'] === 'ok' && $i['email_aluno'] !== 'acesso')) {
                mcp_email_aluno_pago($i);
                $r['emails']++;
            }
            if (($i['plano'] ?? 'so_taxa') === 'taxa_e_matricula' && $i['escola_status'] === 'erro'
                && (int) $i['escola_tentativas'] >= MCP_ESCOLA_MAX_TENTATIVAS
                && mcp_contar_eventos_recentes('escola_esgotada', (string) $id, 365 * 86400) === 0) {
                mcp_registrar($id, 'escola_esgotada', (string) $id);
                mcp_email_secretaria($i);
                $r['esgotadas']++;
            }
        }
    }
    // E-mail do aluno que não saiu (exceção entre o UPDATE 'pago' e o envio), fora dos casos acima.
    $stmt = $pdo->prepare("SELECT id FROM mcp_inscricoes WHERE status = 'pago' AND email_aluno IS NULL AND pago_em < ? AND pago_em > ?
        AND escola_status NOT IN ('pendente') ORDER BY pago_em LIMIT 20");
    $stmt->execute([$tresMin, $semana]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $i = mcp_inscricao_por('id', (string) $id);
        if ($i && $i['email_aluno'] === null) {
            mcp_email_aluno_pago($i);
            $r['emails']++;
        }
    }
    if ((string) mcp_cfg('EMAIL_SECRETARIA', '') !== '') {
        $stmt = $pdo->prepare("SELECT id FROM mcp_inscricoes WHERE status = 'pago' AND email_secretaria IS NULL AND pago_em < ? AND pago_em > ? ORDER BY pago_em LIMIT 20");
        $stmt->execute([$tresMin, $semana]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $i = mcp_inscricao_por('id', (string) $id);
            if ($i && $i['email_secretaria'] === null) {
                mcp_email_secretaria($i);
                $r['secretaria']++;
            }
        }
    }
    $stmt = $pdo->prepare("SELECT * FROM mcp_inscricoes WHERE status = 'pendente' AND metodo = 'cartao' AND unicopag_hash IS NOT NULL
        AND criado_em < ? AND criado_em > ? ORDER BY criado_em LIMIT 20");
    $stmt->execute([gmdate('Y-m-d H:i:s', $agora - 300), gmdate('Y-m-d H:i:s', $agora - 25 * 3600)]);
    foreach ($stmt->fetchAll() as $i) {
        mcp_sincronizar($i);
        $r['cartoes']++;
    }
    return $r;
}

/**
 * Estorno total pela API (POST /public/v1/payments/{hash}/refund, sem valor): devolve tudo, com a taxa, a matrícula,
 * os opcionais e os juros do parcelamento (decisão 7 do dono). Assíncrono: o "refunded" chega pelo postback, que faz
 * o resto (mcp_pos_estorno). NÃO está ligado a nenhum botão: o "Estornar" do painel fica fora desta construção (E10).
 * Na compra que espera turma, a regra da casa vale: "Devolver" antes de estornar (10.8).
 */
function mcp_unicopag_estornar(array $inscricao, string $quem): array
{
    $id = (int) $inscricao['id'];
    if (($inscricao['status'] ?? '') !== 'pago' || empty($inscricao['unicopag_hash'])) {
        return ['ok' => false, 'mensagem' => 'Só uma inscrição paga pode ser estornada.'];
    }
    if (in_array($inscricao['espera_status'] ?? null, ['aguardando', 'turma'], true)) {
        return ['ok' => false, 'mensagem' => 'Marque "Devolver" na ficha antes de estornar uma compra que espera turma.'];
    }
    try {
        $r = mcp_unicopag('POST', '/public/v1/payments/' . rawurlencode((string) $inscricao['unicopag_hash']) . '/refund');
    } catch (McpUnicopagErro $e) {
        mcp_registrar($id, 'estorno_recusado', $e->status . ' ' . mb_substr($e->getMessage(), 0, 120) . ' · ' . mb_substr($quem, 0, 120));
        return ['ok' => false, 'mensagem' => 'A Unicopag não aceitou o estorno: ' . $e->getMessage()];
    }
    mcp_registrar($id, 'estorno_pedido', mb_substr($quem, 0, 120) . ' · ' . mb_substr((string) ($r['payment_status'] ?? ''), 0, 40));
    return ['ok' => true, 'mensagem' => 'Estorno pedido. A Unicopag confirma pelo aviso de pagamento; o status muda para Estornada quando ela confirmar.'];
}
