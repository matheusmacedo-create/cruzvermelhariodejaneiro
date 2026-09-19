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
 * só o método, o caminho e o erro de transporte.
 */
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
        'waiting_payment' => 'pendente', 'pending' => 'pendente', 'processing' => 'pendente',
        'paid' => 'pago', 'pre_chargeback' => 'pago',
        'refused' => 'recusado', 'failed' => 'recusado',
        'cancelled' => 'expirado', 'canceled' => 'expirado', 'expired' => 'expirado',
        'refunded' => 'estornado', 'chargeback' => 'estornado',
    ];
    return $mapa[strtolower(trim((string) $origem))] ?? 'pendente';
}

/** Reconsulta a transação na Unicopag e aplica o resultado. Postback e polling passam por aqui. */
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
    mcp_atualizar($id, ['consultado_em' => mcp_agora(), 'unicopag_status' => mb_substr($statusOrigem, 0, 40)]);
    return mcp_aplicar_status($id, mcp_traduzir_status($statusOrigem));
}

/**
 * Aplica um status vindo do provedor respeitando as transições: pago é definitivo (só estorno
 * o altera); recusado e expirado só valem sobre pendente. O UPDATE condicional é atômico, então
 * postback e polling simultâneos disparam o pós-pagamento uma única vez.
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
            }
        }
    }
    return mcp_inscricao_por('id', (string) $id) ?? [];
}

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
