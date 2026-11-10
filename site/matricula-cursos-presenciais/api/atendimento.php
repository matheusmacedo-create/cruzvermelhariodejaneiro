<?php
/**
 * POST: o chat do site pergunta e o site responde na hora (site/chat/chat.js, lib/atendimento.php, docs/atendimento.md).
 *
 * Ações (campo "acao" do JSON):
 *  - perguntar: grava a pergunta em mcp_buscas e devolve a resposta (IA ancorada na base ou busca local), a categoria,
 *    os botões de página e, quando o caso precisa da equipe, os dados que o chat deve coletar antes de abrir o chamado;
 *  - avaliar: a pessoa disse se a resposta resolveu.
 * O chamado em si continua em contato.php, que recebe o identificador da conversa e anexa a transcrição.
 *
 * Nada aqui pede nome, e-mail ou telefone: quem só pergunta não se identifica.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (!function_exists('mcp_at_base')) {
    mcp_falhar(503, 'Atendimento indisponível.');
}

$b = mcp_exigir_post_json(8192);
$sessao = mcp_at_sessao_valida($b['sessao'] ?? null);
if ($sessao === '') {
    mcp_falhar(422, 'Conversa inválida. Feche o chat e abra de novo.');
}
$acao = mcp_texto($b['acao'] ?? 'perguntar', 12);

if ($acao === 'avaliar') {
    $ok = mcp_busca_avaliar((int) ($b['id'] ?? 0), $sessao, ($b['resolveu'] ?? null) === true ? 'resolveu' : 'nao_resolveu');
    mcp_json(['ok' => $ok]);
}
if ($acao !== 'perguntar') {
    mcp_falhar(400, 'Ação desconhecida.');
}

// Campo armadilha, como no contato.php: o chat manda vazio.
if (mcp_texto($b['site'] ?? '', 10) !== '') {
    mcp_falhar(422, 'Não foi possível enviar. Tente novamente.');
}
$pergunta = mcp_texto($b['pergunta'] ?? '', MCP_AT_PERGUNTA_MAX + 1);
if (mb_strlen($pergunta) < MCP_AT_PERGUNTA_MIN) {
    mcp_falhar(422, 'Escreva em poucas palavras o que você procura.');
}
if (mb_strlen($pergunta) > MCP_AT_PERGUNTA_MAX) {
    mcp_falhar(422, 'A pergunta pode ter até ' . MCP_AT_PERGUNTA_MAX . ' caracteres. Resuma em uma ou duas frases.');
}
[$maximo, $janela] = MCP_AT_LIMITE_IP;
if (mcp_busca_contar_recentes_ip($janela) >= $maximo) {
    mcp_falhar(429, 'Foram muitas perguntas em pouco tempo. Aguarde um pouco ou fale com a equipe.', ['equipe' => true]);
}
$canal = in_array($b['canal'] ?? '', ['texto', 'voz', 'botao'], true) ? (string) $b['canal'] : 'texto';
$pagina = mcp_texto($b['pagina'] ?? '', 255);
if ($pagina !== '' && (!str_starts_with($pagina, '/') || str_starts_with($pagina, '//'))) {
    $pagina = '';
}
$idioma = preg_match('/^[a-z]{2}(-[A-Za-z]{2})?$/', (string) ($b['idioma'] ?? '')) ? (string) $b['idioma'] : null;

// Botão "talvez seja isto": a resposta é a da base, procurada pelo rótulo (nunca o texto que o navegador mandou).
$r = $canal === 'botao' ? mcp_at_responder_sugestao($pergunta) : null;
if ($r === null && mcp_at_ia_configurada() && mcp_busca_contar_ia_hoje() < (int) mcp_cfg('IA_POR_DIA', MCP_AT_IA_POR_DIA)) {
    $historico = array_slice(mcp_busca_conversa($sessao), -MCP_AT_HISTORICO);
    $r = mcp_at_responder_ia($pergunta, $historico);
}
$r ??= mcp_at_responder_local($pergunta);

$id = mcp_busca_gravar($r + ['sessao' => $sessao, 'pergunta' => $pergunta, 'canal' => $canal, 'pagina' => $pagina ?: null, 'idioma' => $idioma]);

$categoria = $r['categoria'];
// No celular, mais de dois botões de página empurram o "Isso resolveu?" para fora da tela: a ação pronta primeiro.
$links = $r['links'];
usort($links, static fn(array $x, array $y): int => (int) !empty($y['destaque']) <=> (int) !empty($x['destaque']));
$links = array_slice($links, 0, 2);
$dados = mcp_at_categorias()[$categoria];
mcp_json([
    'ok' => true,
    'id' => $id,
    'sabe' => $r['sabe'],
    'precisa_equipe' => $r['precisa_equipe'],
    'resposta' => $r['resposta'],
    'links' => $links,
    'sugestoes' => $r['sugestoes'],
    'categoria' => $categoria,
    'assunto' => $dados['assunto'],
    'assunto_rotulo' => mcp_contato_assunto_rotulo($dados['assunto']),
    'coletar' => mcp_at_coletar($categoria),
]);
