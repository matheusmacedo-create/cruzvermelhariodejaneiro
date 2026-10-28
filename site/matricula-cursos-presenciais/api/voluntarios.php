<?php
/**
 * POST: ficha de cadastro de voluntário(a) da página /voluntariado/cadastro/. Grava em mcp_voluntarios, manda o aviso
 * à equipe de voluntariado (EMAIL_VOLUNTARIADO ou, vazio, voluntariado@, com responder-para de quem preencheu) e a
 * confirmação à pessoa, enfileira o PDF e a planilha no Drive (quando o módulo existe) e devolve o protocolo.
 *
 * Dois jeitos de chamar, com os mesmos nomes de campo (contrato em lib/voluntarios.php):
 *  - JSON (a página com JavaScript): 201 {ok, protocolo, nome, email, prazo, aviso, menor, selfie}; 422 {erro, campo};
 *    409 {erro} (ficha repetida); 429 {erro} (freios). A selfie, obrigatória, vem no campo "selfie" como data URL (até
 *    MCP_VOL_CORPO_MAX no corpo inteiro);
 *  - formulário (multipart/form-data, sem JavaScript, com a selfie no arquivo "selfie"; listas como frentes[], dias[], periodos[]):
 *    redirect 303 para /voluntariado/cadastro/?protocolo=VOL-... ou ?erro=<campo> (duplicada, limite e envio para os
 *    erros que não são de um campo).
 *
 * O banco é a fonte da verdade: se o e-mail falhar, a ficha continua gravada (email_* = 'falhou') e aparece no portal.
 * O registro em mcp_eventos leva só id e protocolo, nunca CPF nem outro dado da pessoa.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

const MCP_VOL_PAGINA_CADASTRO = '/voluntariado/cadastro/';

/** Sem JavaScript: volta para a página do formulário com o protocolo ou o campo do erro na query. */
function mcp_voluntario_redirecionar(string $query): never
{
    http_response_code(303);
    header('Location: ' . MCP_VOL_PAGINA_CADASTRO . '?' . $query);
    header('Cache-Control: no-store');
    exit;
}

// ----------------------------------------------------------------------------- entrada
mcp_exigir_metodo('POST');
$tipo = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
$formulario = str_starts_with($tipo, 'application/x-www-form-urlencoded') || str_starts_with($tipo, 'multipart/form-data');
if ($formulario) {
    // Mesma guarda de origem do POST JSON: outro site não usa o formulário como se fosse o nosso.
    if (!mcp_origem_do_site()) {
        mcp_voluntario_redirecionar('erro=envio');
    }
    $b = is_array($_POST) ? $_POST : [];
} else {
    $b = mcp_exigir_post_json(MCP_VOL_CORPO_MAX);
}
$metaId = mcp_meta_id_valido($b['evento_id'] ?? null); // id do evento do Pixel, como no contato (lib/meta.php)

/**
 * Responde o erro do jeito certo para quem chamou: JSON com o status (e o campo, só no 422 de um campo da ficha), ou
 * redirect com ?erro=<campo> (duplicada, limite e envio para os erros que não são de um campo).
 */
$falhar = static function (int $status, string $mensagem, string $campo, bool $campoNoJson = true) use ($formulario): never {
    if ($formulario) {
        mcp_voluntario_redirecionar('erro=' . rawurlencode($campo));
    }
    mcp_falhar($status, $mensagem, $status === 422 && $campoNoJson ? ['campo' => $campo] : []);
};

// Campo armadilha: a página sempre manda vazio; robô que preenche tudo cai aqui.
if (mcp_texto($b['site'] ?? '', 10) !== '') {
    mcp_registrar(null, 'armadilha', 'voluntarios · ' . mcp_ip());
    $falhar(422, 'Não foi possível enviar. Tente novamente.', 'envio', false); // 422 genérico, sem campo (como no contato)
}

$conferido = mcp_voluntario_validar($b);
$selfieEntrada = mcp_voluntario_selfie_entrada($formulario ? null : ($b['selfie'] ?? null), $formulario && isset($_FILES['selfie']) && is_array($_FILES['selfie']) ? $_FILES['selfie'] : null);
unset($b);
if (!$conferido['ok']) {
    $falhar(422, $conferido['erro'], $conferido['campo']);
}
$ficha = $conferido['dados'];

// Selfie obrigatória: sem ela, ou com foto que não abre, volta como erro do campo; servidor sem GD guarda a ficha sem ela
// (nunca a foto crua, que levaria a localização do celular) e avisa a equipe.
$selfie = null;
$selfieSituacao = null;
if ($selfieEntrada === null) {
    $falhar(422, 'Envie uma selfie: uma foto do seu rosto, em JPG ou PNG.', 'selfie');
}
if ($selfieEntrada !== null) {
    if (isset($selfieEntrada['erro'])) {
        $falhar(422, $selfieEntrada['erro'], 'selfie');
    }
    $preparada = mcp_voluntario_selfie_preparar($selfieEntrada['bruto']);
    unset($selfieEntrada);
    if ($preparada['ok']) {
        $selfie = $preparada;
    } elseif ($preparada['motivo'] === 'gd') {
        error_log('[matricula] voluntarios: selfie recebida, mas o PHP está sem GD; a ficha segue sem ela');
        $selfieSituacao = 'nao_tratada';
    } else {
        $falhar(422, $preparada['erro'], 'selfie');
    }
}

// Freios: 5 fichas por IP na hora e 2 por e-mail no dia. Folga para uma família, freio para robô.
foreach ([['ip', mcp_ip(), MCP_VOL_LIMITE_IP], ['email', $ficha['email'], MCP_VOL_LIMITE_EMAIL]] as [$coluna, $valor, [$maximo, $janela]]) {
    if ($valor !== '' && mcp_voluntarios_contar_recentes($coluna, $valor, $janela) >= $maximo) {
        mcp_registrar(null, 'limite', "voluntarios · $coluna · $maximo em {$janela}s");
        $falhar(429, 'Você já enviou várias fichas em pouco tempo. Aguarde um pouco ou escreva para ' . mcp_email_contato_endereco() . '.', 'limite');
    }
}

// A mesma pessoa com ficha ainda em aberto nos últimos 30 dias: não abre outra, só lembra que já chegou.
$anterior = mcp_voluntario_duplicada($ficha['email']);
if ($anterior !== null) {
    mcp_registrar(null, 'voluntario_repetida', '#' . $anterior['id'] . ' · ' . (string) $anterior['protocolo']);
    $falhar(409, 'Já recebemos a sua ficha em ' . mcp_data_brt((string) $anterior['criado_em'], 'd/m') . ' (protocolo ' . (string) $anterior['protocolo']
        . '); a equipe entra em contato pelo WhatsApp ' . mcp_vol_prazo() . (mcp_vol_cbfi_aberto() ? '' : ', quando abrem as novas entradas') . '. Se quiser corrigir algo, escreva para ' . mcp_email_contato_endereco() . '.', 'duplicada');
}

// ----------------------------------------------------------------------------- grava, avisa, confirma
$id = mcp_voluntario_gravar($ficha);
$protocolo = mcp_voluntario_protocolo($id);
mcp_voluntario_atualizar($id, ['protocolo' => $protocolo]);
if ($selfie !== null) {
    try {
        mcp_voluntario_selfie_gravar($id, $selfie);
        $selfieSituacao = 'recebida';
    } catch (Throwable $e) {
        error_log("[matricula] voluntarios: selfie da ficha #$id não gravada: " . $e->getMessage());
        $selfieSituacao = 'nao_tratada';
    }
    unset($selfie);
}
mcp_voluntario_drive_enfileirar($id);
$registro = mcp_voluntario_por_id($id) ?? ($ficha + ['id' => $id, 'protocolo' => $protocolo, 'criado_em' => mcp_agora(),
    'consentimento_em' => mcp_agora(), 'veracidade_em' => mcp_agora(), 'politica_versao' => mcp_voluntario_politica_versao(), 'status' => 'nova']);

$registro['selfie'] = $selfieSituacao;
$envios = mcp_email_voluntario_ficha($registro);
mcp_voluntario_atualizar($id, ['email_equipe' => $envios['equipe'], 'email_confirmacao' => $envios['confirmacao']]);
mcp_registrar(null, 'voluntario', "#$id · $protocolo · equipe {$envios['equipe']} · confirmação {$envios['confirmacao']}" . ($selfieSituacao !== null ? " · selfie $selfieSituacao" : ''));
// A Redação (Voluntários → Inscrições pendentes) busca a ficha na hora, depois da resposta (lib/voluntarios.php).
mcp_redacao_avisar_ficha_nova();
// API de Conversões (só com "sim" para marketing): CompleteRegistration com o mesmo id que a página manda ao Pixel.
mcp_meta_voluntario(['nome' => $ficha['nome'], 'email' => $ficha['email'], 'telefone' => $ficha['telefone'],
    'fbclid' => $ficha['fbclid'], 'pagina' => $ficha['pagina']], $metaId);

if ($formulario) {
    mcp_voluntario_redirecionar('protocolo=' . rawurlencode($protocolo));
}
mcp_json([
    'ok' => true,
    'protocolo' => $protocolo,
    'nome' => mcp_primeiro_nome($ficha['nome']),
    'email' => $ficha['email'],
    'prazo' => MCP_VOL_PROXIMAS_ENTRADAS,
    'aviso' => mcp_vol_aviso_prazo(),
    'menor' => $ficha['menor'],
    'selfie' => $selfieSituacao === 'recebida',
], 201);
