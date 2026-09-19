<?php
/** API da escola (versão A da tela Parabéns): cria a matrícula e devolve o acesso. Contrato: briefing, seção 8.2. */
declare(strict_types=1);

const MCP_ESCOLA_MAX_TENTATIVAS = 5;
const MCP_ESCOLA_INTERVALO = 60;

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
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
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
        mcp_registrar($id, 'escola_ok', "tentativa $tentativas");
        return;
    }
    mcp_atualizar($id, ['escola_status' => 'erro', 'escola_tentativas' => $tentativas, 'escola_tentativa_em' => mcp_agora()]);
    // Só o status e o erro de transporte: a resposta poderia carregar credenciais.
    mcp_registrar($id, 'escola_erro', "tentativa $tentativas · HTTP $status · $erroCurl");
}

/** Retentativa enquanto o aluno acompanha a tela: no máximo 5 tentativas, 1 por minuto. */
function mcp_escola_retentar_se_preciso(array $inscricao): array
{
    if (($inscricao['status'] ?? '') !== 'pago' || ($inscricao['escola_status'] ?? '') !== 'erro'
        || (int) $inscricao['escola_tentativas'] >= MCP_ESCOLA_MAX_TENTATIVAS) {
        return $inscricao;
    }
    $ultima = $inscricao['escola_tentativa_em'] ? (int) strtotime($inscricao['escola_tentativa_em'] . ' UTC') : 0;
    if (time() - $ultima < MCP_ESCOLA_INTERVALO) {
        return $inscricao;
    }
    mcp_escola_tentar((int) $inscricao['id']);
    $atual = mcp_inscricao_por('id', (string) $inscricao['id']) ?? $inscricao;
    if ($atual['escola_status'] === 'ok' && $atual['email_aluno'] !== 'acesso') {
        mcp_email_aluno_pago($atual);
    }
    return $atual;
}

/** Acesso devolvido pela escola, pronto para a tela e o e-mail. Nunca vai para log. */
function mcp_escola_acesso(array $inscricao): ?array
{
    if (empty($inscricao['escola_acesso'])) {
        return null;
    }
    $dados = json_decode((string) $inscricao['escola_acesso'], true);
    return is_array($dados) ? $dados : null;
}
