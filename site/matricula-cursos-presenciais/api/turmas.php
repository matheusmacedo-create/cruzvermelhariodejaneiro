<?php
/**
 * POST: pedido da seção "Turmas sob demanda" da página de matrícula (static/turmas.js). Com 15 alunos ou
 * mais, é pedido de turma fechada; com menos, entra na lista de interesse do curso naquele idioma (regras em
 * lib/turmas.php). Grava em mcp_turmas_pedidos, avisa a secretaria, confirma à pessoa e devolve o protocolo.
 *
 * O banco é a fonte da verdade: se o e-mail falhar, o pedido continua gravado (email_equipe = 'falhou') e
 * aparece no portal (?v=turmas).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$b = mcp_exigir_post_json();
$metaId = mcp_meta_id_valido($b['evento_id'] ?? null); // id do Lead do Pixel (lib/meta.php)

// Campo armadilha: a página sempre manda vazio; robô que preenche tudo cai aqui.
if (mcp_texto($b['site'] ?? '', 10) !== '') {
    mcp_registrar(null, 'armadilha', 'turmas · ' . mcp_ip());
    mcp_falhar(422, 'Não foi possível enviar. Tente novamente.');
}

$conferido = mcp_turma_conferir($b);
unset($b);
if (!$conferido['ok']) {
    mcp_falhar(422, $conferido['erro'], array_intersect_key($conferido, ['campo' => 1, 'matricula' => 1]));
}
$pedido = $conferido['dados'];

// Aviso da data reenviado (a pessoa fechou e abriu o formulário de novo): o mesmo protocolo, sem outra linha, sem
// outros e-mails e sem outro SubmitApplication. A página não mede de novo quando vem 'repetido'.
if (!empty($pedido['aviso']) && ($existente = mcp_turma_aviso_aberto($pedido['curso_slug'], $pedido['email'])) !== null) {
    mcp_registrar(null, 'turma_aviso_repetido', '#' . $existente['id'] . ' · ' . $pedido['curso_slug']);
    mcp_json(['ok' => true, 'repetido' => true, 'protocolo' => (string) $existente['protocolo'], 'tipo' => 'lista', 'nome' => mcp_primeiro_nome($pedido['nome']),
        'email' => $pedido['email'], 'curso' => mcp_turma_rotulo($pedido['curso_nome'], 'pt'), 'prazo' => MCP_EMAIL_PRAZO, 'copia_enviada' => true]);
}

foreach ([['ip', mcp_ip(), MCP_TURMA_LIMITE_IP], ['email', $pedido['email'], MCP_TURMA_LIMITE_EMAIL]] as [$coluna, $valor, [$maximo, $janela]]) {
    if (mcp_turma_contar_recentes($coluna, $valor, $janela) >= $maximo) {
        mcp_registrar(null, 'limite', "turmas · $coluna · $maximo em {$janela}s");
        mcp_falhar(429, 'Você já enviou vários pedidos em pouco tempo. Aguarde um pouco ou escreva para ' . mcp_email_contato_endereco() . '.');
    }
}

$id = mcp_turma_gravar($pedido);
$protocolo = mcp_turma_protocolo($id);
mcp_turma_atualizar($id, ['protocolo' => $protocolo]);
$registro = mcp_turma_por_id($id) ?? ($pedido + ['id' => $id, 'protocolo' => $protocolo, 'criado_em' => mcp_agora()]);

// Na lista de interesse, a soma do curso naquele idioma (já com este pedido) vai no aviso à secretaria.
$progresso = null;
if ($pedido['tipo'] === 'lista' && empty($pedido['aviso'])) {
    $lista = mcp_turma_demanda($pedido['curso_slug'], $pedido['idioma'])[0] ?? null;
    $progresso = mcp_turma_progresso((int) ($lista['pessoas'] ?? $pedido['pessoas']));
}

$envios = mcp_email_turma($registro, $progresso);
mcp_turma_atualizar($id, ['email_equipe' => $envios['equipe'], 'email_confirmacao' => $envios['confirmacao']]);
mcp_registrar(null, 'turma_pedido', "#$id · {$pedido['tipo']} · {$pedido['curso_slug']} · {$pedido['idioma']} · {$pedido['pessoas']} · equipe {$envios['equipe']} · confirmação {$envios['confirmacao']}");
mcp_meta_turma($pedido, $metaId);

mcp_json([
    'ok' => true,
    'protocolo' => $protocolo,
    'tipo' => $pedido['tipo'],
    'nome' => mcp_primeiro_nome($pedido['nome']),
    'email' => $pedido['email'],
    'curso' => mcp_turma_rotulo($pedido['curso_nome'], $pedido['idioma']),
    'prazo' => MCP_EMAIL_PRAZO,
    'copia_enviada' => $envios['confirmacao'] !== 'falhou',
], 201);
