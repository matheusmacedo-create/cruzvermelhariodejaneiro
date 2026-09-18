<?php
/** A visão pública de uma inscrição: o que as páginas podem receber. */
declare(strict_types=1);

/**
 * Nunca devolve CPF, hash da transação, IP nem dado de cartão além de bandeira e final. O acesso
 * à escola só aparece quando a inscrição está paga.
 */
function mcp_publico(array $inscricao): array
{
    $token = (string) $inscricao['token'];
    $acesso = $inscricao['status'] === 'pago' ? mcp_escola_acesso($inscricao) : null;
    return [
        'ok' => true,
        'token' => $token,
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
            'pendente' => mcp_url_pagina('pendente', $token),
            'parabens' => mcp_url_pagina('parabens', $token),
        ],
        'teste' => mcp_modo_teste(),
    ];
}
