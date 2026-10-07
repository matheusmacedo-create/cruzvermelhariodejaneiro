<?php
/** A visão pública de uma inscrição: o que as páginas podem receber. */
declare(strict_types=1);

/**
 * Nunca devolve CPF, hash da transação, IP nem dado de cartão além de bandeira e final. O acesso
 * à escola e o questionário de horários só aparecem quando a inscrição está paga. $preferencia: as
 * respostas de horários da inscrição (status.php lê do banco; os testes passam direto).
 */
function mcp_publico(array $inscricao, ?array $preferencia = null): array
{
    $token = (string) $inscricao['token'];
    $acesso = $inscricao['status'] === 'pago' ? mcp_escola_acesso($inscricao) : null;
    return [
        'ok' => true,
        'token' => $token,
        // Id dos eventos da compra no Pixel e no GA (Purchase, AddPaymentInfo): um hash do token, que não o revela.
        'id_compra' => mcp_meta_id_da_compra($token),
        'status' => $inscricao['status'],
        'metodo' => $inscricao['metodo'],
        'curso' => ['slug' => $inscricao['curso_slug'], 'nome' => $inscricao['curso_nome']],
        'nome' => mcp_primeiro_nome((string) $inscricao['nome']),
        'email' => $inscricao['email'],
        'inscricao_centavos' => (int) $inscricao['inscricao_centavos'],
        'taxa_centavos' => (int) $inscricao['taxa_centavos'],
        'divulgacao_centavos' => (int) ($inscricao['divulgacao_centavos'] ?? 0),
        'total_centavos' => (int) $inscricao['total_centavos'],
        'pix' => $inscricao['metodo'] === 'pix' ? [
            'copia_cola' => $inscricao['pix_copia_cola'],
            'url' => $inscricao['pix_url'],
            'imagem' => $inscricao['pix_imagem'],
        ] : null,
        'cartao' => $inscricao['metodo'] === 'cartao' ? ['bandeira' => $inscricao['bandeira'], 'ultimos4' => $inscricao['ultimos4']] : null,
        'criado_em' => $inscricao['criado_em'],
        'pago_em' => $inscricao['pago_em'],
        // Link de criar senha só com a inscrição paga e enquanto vale (quem tem este link é o aluno).
        'escola' => [
            'configurada' => mcp_escola_configurada(),
            'status' => $inscricao['escola_status'],
            'esgotado' => (int) ($inscricao['escola_tentativas'] ?? 0) >= MCP_ESCOLA_MAX_TENTATIVAS,
            'resultado' => $acesso['resultado'] ?? null,
            'aluno_novo' => $acesso['aluno_novo'] ?? null,
            'email_conta' => $acesso['email_conta'] ?? null,
            'email_confere' => $acesso['email_confere'] ?? null,
            'turma_inicio' => $acesso['turma_inicio'] ?? null,
            'link' => $acesso ? mcp_escola_link($inscricao) : null,
            'link_validade' => $acesso ? mcp_escola_link_validade($inscricao) : null,
            'url' => $acesso['url_login'] ?? null,
        ],
        'horarios' => mcp_horarios_publico($inscricao, $preferencia),
        'escola_url' => (string) mcp_cfg('ESCOLA_URL', 'https://escola.cursoscruzvermelha.org'),
        'urls' => [
            'pendente' => mcp_url_pagina('pendente', $token),
            'parabens' => mcp_url_pagina('parabens', $token),
        ],
        'teste' => mcp_modo_teste(),
    ];
}
