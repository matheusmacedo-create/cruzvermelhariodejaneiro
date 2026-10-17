<?php
/**
 * POST multipart/form-data: pedido de documento da filial (página /pedido-de-documentos/). Grava o pedido em
 * mcp_contatos (assunto 'documentos'), manda o aviso à equipe com os comprovantes anexados e a confirmação a
 * quem pediu, e devolve o protocolo. Regras e motivos em lib/documentos.php.
 *
 * Os comprovantes não são gravados: ficam no temporário do PHP durante a requisição e saem só por e-mail.
 * Se o aviso à equipe falhar, o pedido fica registrado como falho e a pessoa é orientada a enviar de novo
 * (os anexos se perderiam de outro jeito).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/lib/documentos.php';

mcp_exigir_metodo('POST');
if (!mcp_origem_do_site()) {
    mcp_falhar(403, 'Origem não permitida.');
}
$tipo = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
if (!str_starts_with($tipo, 'multipart/form-data')) {
    mcp_falhar(415, 'Envie o pedido pelo formulário da página.');
}
// Corpo acima do post_max_size do PHP chega com $_POST e $_FILES vazios: avisa em vez de dizer "faltou o nome".
$declarado = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($declarado > MCP_DOC_TOTAL_BYTES + 262144 || ($declarado > 0 && !$_POST && !$_FILES)) {
    mcp_falhar(413, 'Os arquivos somados passaram do limite de ' . (MCP_DOC_TOTAL_BYTES >> 20) . ' MB. Diminua as fotos ou os PDFs e envie de novo.', ['campo' => 'comprovantes']);
}

// Campo armadilha: a página sempre manda vazio.
if (mcp_texto($_POST['site'] ?? '', 10) !== '') {
    mcp_registrar(null, 'armadilha', 'documentos · ' . mcp_ip());
    mcp_falhar(422, 'Não foi possível enviar. Tente novamente.');
}

$dados = mcp_doc_validar($_POST);

foreach ([['ip', mcp_ip(), MCP_DOC_LIMITE_IP], ['email', $dados['email'], MCP_DOC_LIMITE_EMAIL]] as [$coluna, $valor, [$maximo, $janela]]) {
    if ($valor !== '' && mcp_contar_contatos_recentes($coluna, $valor, $janela) >= $maximo) {
        mcp_registrar(null, 'limite', "documentos · $coluna · $maximo em {$janela}s");
        mcp_falhar(429, 'Você já enviou vários pedidos em pouco tempo. Aguarde um pouco ou escreva para ' . mcp_email_contato_endereco() . '.');
    }
}

$arquivos = mcp_doc_ler_arquivos($_FILES);

$id = mcp_contato_gravar([
    'nome' => $dados['nome'], 'email' => $dados['email'], 'telefone' => $dados['telefone'], 'assunto' => 'documentos',
    'curso_slug' => null, 'curso_nome' => null, 'mensagem' => mcp_doc_resumo($dados, $arquivos), 'pagina' => '/pedido-de-documentos/',
    'utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_content' => null, 'utm_term' => null,
    'fbclid' => null, 'gclid' => null,
]);
$protocolo = mcp_contato_protocolo($id);
// Os anexos levam o protocolo no nome (o resumo gravado acima usou o nome provisório).
foreach ($arquivos as $n => $a) {
    $arquivos[$n]['nome'] = str_replace('pedido-comprovante-', "$protocolo-comprovante-", $a['nome']);
}
mcp_contato_atualizar($id, ['protocolo' => $protocolo, 'mensagem' => mcp_doc_resumo($dados, $arquivos)]);
$registro = mcp_contato_por_id($id) ?? ($dados + ['id' => $id, 'protocolo' => $protocolo, 'criado_em' => mcp_agora()]);
try {
    $registro['link_painel'] = mcp_painel_link_contato($id);
} catch (Throwable $e) {
    error_log('[documentos] link do painel falhou: ' . $e->getMessage());
}

$envios = mcp_doc_enviar_emails($registro, $dados, $arquivos);
unset($arquivos);
mcp_contato_atualizar($id, ['email_equipe' => $envios['equipe'], 'email_confirmacao' => $envios['confirmacao']]);
mcp_registrar(null, 'documentos', "#$id · {$dados['perfil']} · {$dados['documento']} · equipe {$envios['equipe']} · confirmação {$envios['confirmacao']}");

if ($envios['equipe'] === 'falhou') {
    // Sem o aviso, a equipe não recebe os comprovantes: o pedido não pode ser dado como entregue.
    mcp_contato_atualizar($id, ['status' => 'arquivado']);
    mcp_falhar(502, 'Não conseguimos entregar o seu pedido agora. Tente de novo em alguns minutos ou escreva para ' . mcp_email_contato_endereco() . '.');
}

mcp_json([
    'ok' => true,
    'protocolo' => $protocolo,
    'nome' => mcp_primeiro_nome($dados['nome']),
    'email' => $dados['email'],
    'prazo' => MCP_DOC_PRAZO,
    'copia_enviada' => $envios['confirmacao'] !== 'falhou',
], 201);
