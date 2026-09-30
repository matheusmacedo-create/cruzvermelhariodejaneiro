<?php
/**
 * Ponto da sede (página /ponto/, lib/ponto.php e lib/presenca.php).
 *   GET                                               modo (aparelho da sede ou celular) e pessoa lembrada;
 *   POST {acao: identificar, cpf?, posicao?, lembrar?}  confere o CPF (no celular, também a localização) e
 *        devolve o colaborador e as aulas de hoje, com a sessão cifrada de 3 minutos;
 *   POST {acao: entrada|saida, sessao}                registra o ponto do colaborador (horas doadas para
 *                                                     voluntários e diretoria; só presença para os outros vínculos);
 *   POST {acao: presenca, sessao, aula}               registra a chegada do aluno à aula;
 *   POST {acao: informar_saida, sessao, registro, hora, dia_seguinte?}  o voluntário informa a que horas saiu num
 *                                                     dia em que esqueceu a saída (a secretaria confere no portal);
 *   POST {acao: esquecer}                             tira do celular a pessoa lembrada.
 * Depois do CPF, a tela mostra também os avisos dos comunicados no ar (lib/comunicacao.php) e, no tablet
 * da recepção, as saídas sem registro dos últimos 7 dias, para a pessoa informar o horário ali mesmo. No
 * celular, a única prova de identidade é o CPF (que não é segredo): lá não aparecem pendências, links pessoais,
 * horário de entrada nem horas do mês; a saída de uma entrada de outro dia vira saída informada (a secretaria
 * confere), e a saída esquecida se informa pelo link do aviso, que chega ao e-mail ou ao WhatsApp da pessoa.
 * Sem o aparelho liberado pela secretaria, cada consulta precisa da localização a até
 * PONTO_RAIO_METROS da sede e, se ligado no portal, do código do dia que o tablet mostra.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$corpo = $post ? mcp_exigir_post_json() : [];
if (!$post) {
    mcp_exigir_metodo('GET');
}
$agora = time();
$aparelho = mcp_ponto_aparelho_atual();
$modo = $aparelho ? 'aparelho' : 'celular';

if (!$post) {
    $lembrado = $aparelho ? null : mcp_ponto_pessoa_lembrada();
    mcp_json([
        'ok' => true,
        'modo' => $modo,
        'aparelho' => $aparelho ? (string) $aparelho['nome'] : null,
        'lembrado' => $lembrado !== null ? mcp_cpf_mascarado($lembrado) : null,
        'raio' => mcp_ponto_sede()['raio'],
        // Código do dia: o tablet mostra; o celular pede (quando ligado no portal).
        'codigo' => $aparelho && mcp_ponto_codigo_ligado() ? mcp_ponto_codigo_do_dia($agora) : null,
        'pede_codigo' => !$aparelho && mcp_ponto_codigo_ligado(),
    ]);
}

$acao = mcp_texto($corpo['acao'] ?? '', 20);
if ($acao === 'esquecer') {
    mcp_ponto_pessoa_esquecer();
    mcp_json(['ok' => true, 'esquecido' => true]);
}

/** Aula de hoje como a tela mostra: horário, se já tem presença e se dá para registrar agora. */
function pt_aula(array $aula, string $cpf, int $agora): array
{
    $h = mcp_presenca_horario((string) $aula['horario'], (string) $aula['data']);
    $presenca = mcp_presenca_da_aula($cpf, (string) $aula['id']);
    $janela = mcp_presenca_janela($aula, $agora);
    return [
        'id' => (string) $aula['id'],
        'curso' => (string) $aula['curso'],
        'horario' => $h['texto'],
        'registrada' => $presenca && $presenca['status'] === 'valida' ? mcp_data_brt((string) $presenca['chegada'], 'H:i') : null,
        'cancelada' => $presenca && $presenca['status'] !== 'valida',
        'pode' => $presenca === null && $janela['pode'],
        'motivo' => $presenca === null ? $janela['motivo'] : null,
        'disponivel_em' => mcp_data_brt($h['fim'], 'H:i'),
    ];
}

/**
 * O que a tela mostra do colaborador. No celular, a única prova é o CPF (que não é segredo): só o que o botão
 * precisa (se está na sede e se a entrada é de outro dia). Horário de entrada, horas do mês e termo pendente
 * ficam para o tablet da recepção.
 */
function pt_resumo(array $colaborador, int $agora, bool $celular): array
{
    $r = mcp_ponto_resumo($colaborador, $agora);
    if (!$celular) {
        return $r;
    }
    return ['na_sede' => $r['na_sede'], 'horas' => $r['horas'], 'desde_dia' => $r['desde_dia'], 'desde' => null, 'agora' => null, 'hoje' => null, 'mes' => null, 'mes_nome' => null,
        'termo_pendente' => false, 'aberto_id' => null];
}

/** Saídas sem registro de dias anteriores (menos a entrada ainda aberta, que a tela trata à parte). */
function pt_pendencias(array $colaborador, int $agora): array
{
    if (!mcp_ponto_voluntario($colaborador)) {
        return [];
    }
    $aberto = mcp_ponto_aberto((int) $colaborador['id'], $agora);
    $lista = [];
    foreach (mcp_ponto_saidas_sem_registro((int) $colaborador['id'], $agora) as $r) {
        if ($aberto && (int) $aberto['id'] === (int) $r['id']) {
            continue;
        }
        $lista[] = [
            'id' => (int) $r['id'], 'quando' => mcp_avisos_quando(mcp_data_brt((string) $r['entrada'], 'Y-m-d'), $agora), 'entrada' => mcp_data_brt((string) $r['entrada'], 'H:i'),
            'informada' => $r['saida_informada'] !== null ? mcp_data_brt((string) $r['saida_informada'], 'H:i') : null,
        ];
    }
    return $lista;
}

if ($acao === 'identificar') {
    [$maximo, $janela] = MCP_PONTO_LIMITE[$modo];
    $chaveLimite = $aparelho ? 'aparelho ' . $aparelho['id'] : mcp_ip_balde();
    // Na rede da sede (o mesmo IP do tablet), todos os celulares do prédio dividem o limite: ele é maior ali.
    $fator = !$aparelho && mcp_ponto_rede_da_sede() ? MCP_PONTO_FATOR_REDE_SEDE : 1;
    if (mcp_contar_eventos_recentes('ponto_consulta', $chaveLimite, $janela) >= $maximo * $fator) {
        mcp_falhar(429, 'Muitas consultas em pouco tempo. Aguarde alguns minutos e tente de novo.');
    }
    // No celular, o freio de verdade é para quem erra: CPF inválido ou que não está cadastrado é o jeito de
    // descobrir quem está. Quem acerta tem um limite largo, porque todos no Wi-Fi da sede saem pelo mesmo IP.
    if (!$aparelho && mcp_contar_eventos_recentes('ponto_consulta_falha', $chaveLimite, $janela) >= MCP_PONTO_LIMITE_FALHAS * $fator) {
        mcp_falhar(429, 'Muitas tentativas sem encontrar o CPF. Aguarde alguns minutos ou use o tablet da recepção.');
    }
    mcp_registrar(null, 'ponto_consulta', $chaveLimite);
    $falhou = static function () use ($aparelho, $chaveLimite): void {
        if (!$aparelho) {
            mcp_registrar(null, 'ponto_consulta_falha', $chaveLimite);
        }
    };
    $distancia = null;
    if (!$aparelho) {
        $local = mcp_ponto_conferir_localizacao($corpo['posicao'] ?? null);
        if (!$local['ok']) {
            mcp_falhar(403, (string) $local['erro'], ['motivo' => 'localizacao']);
        }
        $distancia = $local['distancia'];
    }
    $digitado = mcp_digitos(mcp_texto($corpo['cpf'] ?? '', 20));
    $cpf = $digitado !== '' ? $digitado : (string) ($aparelho ? '' : mcp_ponto_pessoa_lembrada());
    if (!mcp_cpf_valido($cpf)) {
        $falhou();
        mcp_falhar(422, 'CPF inválido. Confira os números.', ['campo' => 'cpf']);
    }
    // Código do dia (ligado no portal): no celular, só registra quem está vendo o tablet da recepção.
    if (!$aparelho && mcp_ponto_codigo_ligado() && ($erroCodigo = mcp_ponto_codigo_conferir($cpf, mcp_digitos(mcp_texto($corpo['codigo'] ?? '', 10)), $agora)) !== null) {
        $falhou();
        mcp_falhar(403, $erroCodigo[1], ['motivo' => $erroCodigo[0], 'campo' => 'codigo']);
    }

    $colaborador = mcp_colaborador_por_cpf($cpf);
    if ($colaborador && !(int) $colaborador['ativo']) {
        $colaborador = null;
    }
    // Colaborador quase nunca é aluno do dia: a escola lenta ou fora do ar não pode segurar o tablet. Para
    // ele, espera pouco e, se a escola falhou há menos de 2 minutos, nem pergunta. Aluno espera o normal.
    $escola = $colaborador && mcp_contar_eventos_recentes('escola_fora', 'aulas_do_aluno', 120) > 0
        ? ['ok' => false, 'erro' => 'a escola falhou há pouco']
        : mcp_escola_aulas($cpf, mcp_ponto_hoje($agora), $colaborador ? 4 : 12);
    $aluno = $escola['ok'] ? $escola['aluno'] : null;
    $aulas = $escola['ok'] ? $escola['aulas'] : [];
    $escolaFora = !$escola['ok'] && $escola['erro'] !== 'sem integração com a escola';
    if (!$colaborador && !$aluno) {
        if ($escolaFora) {
            mcp_falhar(503, 'Não conseguimos consultar as aulas na escola agora. Tente de novo em instantes ou fale com a secretaria.');
        }
        $falhou();
        mcp_falhar(404, 'Não encontramos este CPF entre os colaboradores nem aula sua hoje. Colaborador: peça à secretaria para cadastrar seu CPF. Aluno: confira com a secretaria o dia da sua aula.');
    }
    // No celular, a pessoa escolhe se o aparelho lembra dela (o CPF vai cifrado no cookie).
    if (!$aparelho && array_key_exists('lembrar', $corpo)) {
        if ($corpo['lembrar']) {
            mcp_ponto_pessoa_lembrar($cpf);
        } else {
            mcp_ponto_pessoa_esquecer();
        }
    }
    if ($aparelho) {
        mcp_ponto_aparelho_usado((int) $aparelho['id']);
    }
    $nome = $colaborador ? (string) $colaborador['nome'] : (string) $aluno['nome'];
    mcp_json([
        'ok' => true,
        'sessao' => mcp_ponto_sessao_criar([
            'c' => $cpf, 'm' => $modo, 'ap' => $aparelho ? (int) $aparelho['id'] : null, 'd' => $distancia,
            'col' => $colaborador ? (int) $colaborador['id'] : null, 'al' => $aluno, 'au' => $aulas,
        ], $agora),
        'nome' => mcp_primeiro_nome(mcp_nome_proprio($nome)),
        'colaborador' => $colaborador ? pt_resumo($colaborador, $agora, !$aparelho) : null,
        'aulas' => array_map(static fn(array $a): array => pt_aula($a, $cpf, $agora), $aulas),
        'escola_indisponivel' => $escolaFora,
        'pendencias' => $colaborador && $aparelho ? pt_pendencias($colaborador, $agora) : [],
        'avisos' => mcp_comunicacao_avisos_ponto($colaborador, $aluno, !$aparelho, $agora),
    ]);
}

$sessao = mcp_ponto_sessao_ler($corpo['sessao'] ?? null, $agora);
if (!$sessao) {
    mcp_falhar(401, 'A identificação venceu. Digite o CPF de novo.', ['motivo' => 'sessao']);
}

if ($acao === 'entrada' || $acao === 'saida') {
    $colaborador = !empty($sessao['col']) ? mcp_colaborador_por_id((int) $sessao['col']) : null;
    if (!$colaborador || !(int) $colaborador['ativo']) {
        mcp_falhar(403, 'Este CPF não está cadastrado como colaborador. Fale com a secretaria.');
    }
    $celular = ($sessao['m'] ?? '') === 'celular';
    // No celular, a saída de uma entrada de outro dia vai para a secretaria conferir (mcp_ponto_saida_outro_dia).
    if ($acao === 'saida' && $celular && ($outroDia = mcp_ponto_saida_outro_dia($colaborador, $agora)) !== null) {
        if (isset($outroDia['erro'])) {
            mcp_falhar(409, $outroDia['erro']);
        }
        mcp_json([
            'ok' => true, 'registrado' => 'saida_informada', 'hora' => $outroDia['hora'],
            'mensagem' => 'Saída informada às ' . $outroDia['hora'] . '.',
            'detalhe' => 'A entrada era de outro dia: a secretaria confere, e as horas desse turno entram no seu histórico.',
            'colaborador' => pt_resumo($colaborador, $agora, true),
        ]);
    }
    $r = mcp_ponto_registrar($colaborador, $acao, (string) $sessao['m'], $sessao['ap'] ?? null, $sessao['d'] ?? null, $agora);
    if (!$r['ok']) {
        // No celular, sem o horário da entrada (quem digitou o CPF de outra pessoa não fica sabendo dele).
        $erro = $celular && $r['codigo'] === 'ja_na_sede' ? 'Você já está com a entrada registrada. Para ir embora, registre a saída.' : $r['erro'];
        mcp_falhar(409, $erro, ['codigo' => $r['codigo']]);
    }
    $hora = mcp_data_brt((string) $r['registro'][$acao], 'H:i');
    // Horas só para voluntários e diretoria; para os outros vínculos, o ponto registra só a presença.
    $horas = (int) $r['registro']['voluntario'] === 1;
    mcp_json([
        'ok' => true,
        'registrado' => $acao,
        'hora' => $hora,
        'mensagem' => $acao === 'entrada' ? "Entrada registrada às $hora."
            : ($horas ? "Saída registrada às $hora. Obrigado pelas horas doadas!" : "Saída registrada às $hora. Até a próxima!"),
        // A duração entrega a hora da entrada: só no tablet da recepção.
        'duracao' => $acao === 'saida' && $horas && !$celular ? mcp_ponto_horas_texto(intdiv(mcp_ponto_segundos($r['registro']), 60)) : null,
        'colaborador' => pt_resumo($colaborador, $agora, $celular),
    ]);
}

if ($acao === 'informar_saida') {
    if (($sessao['m'] ?? '') !== 'aparelho') {
        mcp_falhar(403, 'Pelo celular, informe a saída pelo link do aviso que chega ao seu e-mail ou WhatsApp, ou use o tablet da recepção.');
    }
    $colaborador = !empty($sessao['col']) ? mcp_colaborador_por_id((int) $sessao['col']) : null;
    if (!$colaborador || !(int) $colaborador['ativo']) {
        mcp_falhar(403, 'Este CPF não está cadastrado como colaborador. Fale com a secretaria.');
    }
    $registro = mcp_ponto_registro((int) ($corpo['registro'] ?? 0));
    if (!$registro || (int) $registro['colaborador_id'] !== (int) $colaborador['id']) {
        mcp_falhar(404, 'Registro não encontrado. Digite o CPF de novo.');
    }
    $erro = mcp_ponto_saida_informar($registro, mcp_texto($corpo['hora'] ?? '', 5), !empty($corpo['dia_seguinte']), 'tela do ponto (' . $sessao['m'] . ')', $agora);
    if ($erro !== null) {
        mcp_falhar(422, $erro, ['campo' => 'hora']);
    }
    mcp_json([
        'ok' => true,
        'registrado' => 'saida_informada',
        'mensagem' => 'Obrigado! Saída informada às ' . mcp_texto($corpo['hora'] ?? '', 5) . '.',
        'colaborador' => mcp_ponto_resumo($colaborador, $agora),
        'pendencias' => pt_pendencias($colaborador, $agora),
    ]);
}

if ($acao === 'presenca') {
    $aulaId = mcp_texto($corpo['aula'] ?? '', 64);
    $aula = null;
    foreach ((array) ($sessao['au'] ?? []) as $a) {
        if (is_array($a) && ($a['id'] ?? null) === $aulaId) {
            $aula = $a;
        }
    }
    if ($aula === null || !is_array($sessao['al'] ?? null)) {
        mcp_falhar(404, 'Aula não encontrada. Digite o CPF de novo.');
    }
    $existente = mcp_presenca_da_aula((string) $sessao['c'], $aulaId);
    if ($existente && $existente['status'] !== 'valida') {
        mcp_falhar(409, 'A presença nesta aula foi cancelada pela secretaria. Fale com ela.');
    }
    $janelaAula = mcp_presenca_janela($aula, $agora);
    if (!$existente && !$janelaAula['pode']) {
        mcp_falhar(409, (string) $janelaAula['motivo']);
    }
    $r = mcp_presenca_registrar($sessao['al'], $aula, (string) $sessao['c'], (string) $sessao['m'], $sessao['ap'] ?? null, $sessao['d'] ?? null, $agora);
    $p = $r['presenca'];
    $pub = mcp_presenca_publico($p, $agora);
    mcp_json([
        'ok' => true,
        'registrado' => 'presenca',
        'nova' => $r['nova'],
        'mensagem' => ($r['nova'] ? 'Presença registrada às ' : 'Sua presença nesta aula já estava registrada às ') . $pub['chegada'] . '.',
        'curso' => $pub['curso'],
        'horario' => $pub['horario'],
        'disponivel_em' => $pub['disponivel_em'],
        'disponivel_hora' => mcp_data_brt((string) $p['fim'], 'H:i'),
        // O comprovante vai por e-mail; a tela não mostra o link pessoal (no celular, quem sabe o CPF de outra
        // pessoa não chega ao nome completo dela nem ao PDF).
        'email' => $pub['email'],
    ]);
}

mcp_falhar(400, 'Ação desconhecida.');
