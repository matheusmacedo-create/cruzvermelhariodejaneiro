<?php
/**
 * Pedido de foto às fichas sem selfie (06/11/2026, pedido do Matheus). As fichas de antes da noite de 27/10/2026 vieram
 * sem foto, porque o formulário ainda não pedia. A coordenadora, quando achar necessário, pede a foto pelo WhatsApp ou
 * por e-mail (api/coordenacao.php), com um link só daquela ficha: api/voluntario-foto.php?f=<id>&k=<assinatura>.
 * A assinatura é um HMAC do id com o segredo 'voluntario_foto' (mcp_chaves); o link vale enquanto a ficha não tem foto.
 * Carregado só por coordenacao.php e voluntario-foto.php (lib.php não carrega).
 */
declare(strict_types=1);

function mcp_vf_assinatura(int $fichaId): string
{
    return substr(hash_hmac('sha256', "foto|$fichaId", mcp_segredo('voluntario_foto')), 0, 32);
}

function mcp_vf_link(int $fichaId): string
{
    return mcp_site_url() . '/matricula-cursos-presenciais/api/voluntario-foto.php?f=' . $fichaId . '&k=' . mcp_vf_assinatura($fichaId);
}

function mcp_vf_valido(int $fichaId, string $k): bool
{
    return $fichaId > 0 && $k !== '' && hash_equals(mcp_vf_assinatura($fichaId), $k);
}

/** A mensagem do pedido, a mesma no WhatsApp e no e-mail. */
function mcp_vf_mensagem(array $f): string
{
    return 'Olá, ' . mcp_primeiro_nome((string) $f['nome']) . '! Aqui é a coordenação do voluntariado da Cruz Vermelha Brasileira Rio de Janeiro. '
        . 'Para completar a sua ficha de voluntário, precisamos de uma foto do seu rosto: uma selfie de frente, com boa luz e sem óculos escuros. '
        . 'Envie por este link, que é só seu: ' . mcp_vf_link((int) $f['id']) . ' . '
        . 'A foto serve só para a equipe reconhecer você na entrevista e no CBFI.';
}
