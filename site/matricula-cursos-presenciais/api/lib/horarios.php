<?php
/**
 * Dias e horários preferidos pelo aluno (29/09/2026).
 *
 * Depois de pagar a inscrição, o aluno responde pelo link pessoal (horarios/?t=<token>, o mesmo token
 * da tela Parabéns) quais dias da semana e períodos pode, a partir de quando pode começar e, se a
 * escola já o colocou numa turma, se a data serve. Pode mudar as respostas quando quiser. A secretaria
 * vê no painel (api/painel.php?v=horarios) o mapa por curso — quantos alunos podem em cada dia e
 * período —, a lista com os contatos, e baixa a planilha.
 *
 * Fase 2: a mesma pergunta dentro da área do aluno da escola, quando houver acesso ao código da
 * plataforma (contrato dos dados em docs/escola/README.md).
 */
declare(strict_types=1);

const MCP_HORARIOS_DIAS = ['seg' => 'Segunda', 'ter' => 'Terça', 'qua' => 'Quarta', 'qui' => 'Quinta', 'sex' => 'Sexta', 'sab' => 'Sábado'];
const MCP_HORARIOS_DIAS_CURTOS = ['seg' => 'Seg', 'ter' => 'Ter', 'qua' => 'Qua', 'qui' => 'Qui', 'sex' => 'Sex', 'sab' => 'Sáb'];
const MCP_HORARIOS_PERIODOS = ['manha' => 'Manhã', 'tarde' => 'Tarde', 'noite' => 'Noite'];
const MCP_HORARIOS_PERIODOS_HORAS = ['manha' => '8h às 12h', 'tarde' => '13h às 17h', 'noite' => '18h às 22h'];
const MCP_HORARIOS_INICIO = ['proxima' => 'Já na próxima turma', '1mes' => 'Daqui a cerca de 1 mês', '2meses' => 'Daqui a 2 meses ou mais'];
const MCP_HORARIOS_TURMA = ['sim' => 'Sim, a data serve', 'nao' => 'Não, prefiro outra data'];
const MCP_HORARIOS_OBS_MAX = 500;

/** Data (AAAA-MM-DD) da turma em que a escola já colocou o aluno, ou null. */
function mcp_horarios_turma(array $inscricao): ?string
{
    $acesso = mcp_escola_acesso($inscricao);
    return $acesso && ($acesso['resultado'] ?? '') === 'matriculado' && is_string($acesso['turma_inicio'] ?? null)
        ? $acesso['turma_inicio'] : null;
}

/**
 * Confere o que veio da tela. Devolve ['ok' => true, 'dados' => [...]] ou ['ok' => false, 'campo', 'erro'].
 * Valores desconhecidos são descartados e as listas saem na ordem da semana, sem repetição.
 */
function mcp_horarios_conferir(array $b, bool $temTurma): array
{
    $escolher = static function (mixed $valores, array $permitidos): array {
        $lista = is_array($valores) ? array_filter($valores, 'is_string') : [];
        return array_values(array_filter(array_keys($permitidos), static fn(string $chave): bool => in_array($chave, $lista, true)));
    };
    $falha = static fn(string $campo, string $erro): array => ['ok' => false, 'campo' => $campo, 'erro' => $erro];

    $dias = $escolher($b['dias'] ?? null, MCP_HORARIOS_DIAS);
    if (!$dias) {
        return $falha('dias', 'Escolha pelo menos um dia da semana.');
    }
    $periodos = $escolher($b['periodos'] ?? null, MCP_HORARIOS_PERIODOS);
    if (!$periodos) {
        return $falha('periodos', 'Escolha pelo menos um período.');
    }
    $inicio = mcp_texto($b['inicio'] ?? '', 20);
    if (!isset(MCP_HORARIOS_INICIO[$inicio])) {
        return $falha('inicio', 'Diga a partir de quando você pode começar.');
    }
    $turma = null;
    if ($temTurma) {
        $turma = mcp_texto($b['turma_serve'] ?? '', 10);
        if (!isset(MCP_HORARIOS_TURMA[$turma])) {
            return $falha('turma_serve', 'Diga se a data da sua turma serve.');
        }
    }
    $obs = mcp_texto_longo($b['observacao'] ?? '', MCP_HORARIOS_OBS_MAX + 1);
    if (mb_strlen($obs) > MCP_HORARIOS_OBS_MAX) {
        return $falha('observacao', 'O comentário pode ter até ' . MCP_HORARIOS_OBS_MAX . ' caracteres.');
    }
    return ['ok' => true, 'dados' => ['dias' => $dias, 'periodos' => $periodos, 'inicio' => $inicio, 'turma_serve' => $turma, 'observacao' => $obs !== '' ? $obs : null]];
}

/**
 * Grava ou atualiza as respostas da inscrição. Devolve true quando é a primeira resposta. Quem diz é o
 * próprio INSERT ... ON DUPLICATE KEY UPDATE (1 linha afetada = nova, 2 = atualizada; vezes sempre muda),
 * e não uma consulta antes: dois envios simultâneos não viram duas "primeiras" nem dois avisos.
 */
function mcp_horarios_salvar(int $inscricaoId, string $cursoSlug, array $dados): bool
{
    $agora = mcp_agora();
    $stmt = mcp_db()->prepare('INSERT INTO mcp_preferencias (inscricao_id, curso_slug, dias, periodos, inicio, turma_serve, observacao, vezes, criado_em, atualizado_em)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
        ON DUPLICATE KEY UPDATE curso_slug = VALUES(curso_slug), dias = VALUES(dias), periodos = VALUES(periodos), inicio = VALUES(inicio),
            turma_serve = VALUES(turma_serve), observacao = VALUES(observacao), vezes = vezes + 1, atualizado_em = VALUES(atualizado_em)');
    $stmt->execute([$inscricaoId, $cursoSlug, implode(',', $dados['dias']), implode(',', $dados['periodos']), $dados['inicio'],
        $dados['turma_serve'], $dados['observacao'], $agora, $agora]);
    return $stmt->rowCount() === 1;
}

function mcp_horarios_por_inscricao(int $inscricaoId): ?array
{
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_preferencias WHERE inscricao_id = ?');
    $stmt->execute([$inscricaoId]);
    $linha = $stmt->fetch();
    return $linha ? mcp_horarios_linha($linha) : null;
}

/** Linha do banco com dias e períodos como listas (só valores conhecidos). */
function mcp_horarios_linha(array $l): array
{
    $l['dias'] = array_values(array_filter(explode(',', (string) $l['dias']), static fn(string $d): bool => isset(MCP_HORARIOS_DIAS[$d])));
    $l['periodos'] = array_values(array_filter(explode(',', (string) $l['periodos']), static fn(string $p): bool => isset(MCP_HORARIOS_PERIODOS[$p])));
    return $l;
}

/** "A, B e C". */
function mcp_horarios_lista(array $itens): string
{
    $itens = array_values($itens);
    return count($itens) > 1 ? implode(', ', array_slice($itens, 0, -1)) . ' e ' . end($itens) : (string) ($itens[0] ?? '');
}

/** Resumo de uma linha: "Seg, Qua e Sáb · Noite · Já na próxima turma". */
function mcp_horarios_resumo(array $p): string
{
    return mcp_horarios_lista(array_map(static fn(string $d): string => MCP_HORARIOS_DIAS_CURTOS[$d], $p['dias']))
        . ' · ' . mcp_horarios_lista(array_map(static fn(string $x): string => MCP_HORARIOS_PERIODOS[$x], $p['periodos']))
        . ' · ' . (MCP_HORARIOS_INICIO[$p['inicio']] ?? '');
}

/** O que a tela Parabéns precisa saber: onde responder e se já respondeu. Só com a inscrição paga. */
function mcp_horarios_publico(array $inscricao, ?array $preferencia): ?array
{
    if (($inscricao['status'] ?? '') !== 'pago') {
        return null;
    }
    return [
        'url' => mcp_url_pagina('horarios', (string) $inscricao['token']),
        'respondido' => $preferencia !== null,
        'resumo' => $preferencia ? mcp_horarios_resumo($preferencia) : null,
    ];
}

/** Dados da tela horarios/: curso, turma, as opções e o que já foi respondido. */
function mcp_horarios_para_tela(array $inscricao, ?array $preferencia): array
{
    return [
        'ok' => true,
        'nome' => mcp_primeiro_nome((string) $inscricao['nome']),
        'curso' => ['slug' => $inscricao['curso_slug'], 'nome' => $inscricao['curso_nome']],
        'turma_inicio' => mcp_horarios_turma($inscricao),
        'opcoes' => ['dias' => MCP_HORARIOS_DIAS, 'periodos' => MCP_HORARIOS_PERIODOS, 'horas' => MCP_HORARIOS_PERIODOS_HORAS,
            'inicio' => MCP_HORARIOS_INICIO, 'turma' => MCP_HORARIOS_TURMA, 'observacao_max' => MCP_HORARIOS_OBS_MAX],
        'resposta' => $preferencia ? [
            'dias' => $preferencia['dias'], 'periodos' => $preferencia['periodos'], 'inicio' => $preferencia['inicio'],
            'turma_serve' => $preferencia['turma_serve'], 'observacao' => $preferencia['observacao'],
            'atualizado_em' => $preferencia['atualizado_em'],
        ] : null,
        'resumo' => $preferencia ? mcp_horarios_resumo($preferencia) : null,
        'urls' => ['parabens' => mcp_url_pagina('parabens', (string) $inscricao['token'])],
    ];
}

// ----------------------------------------------------------------------------- painel da secretaria
/** Respostas com o aluno e o curso, mais recentes primeiro. $curso null = todos os cursos. */
function mcp_horarios_listar(?string $curso, int $limite = 1000): array
{
    $sql = 'SELECT p.*, i.nome, i.email, i.telefone, i.curso_nome, i.escola_acesso, i.pago_em
        FROM mcp_preferencias p JOIN mcp_inscricoes i ON i.id = p.inscricao_id';
    $params = [];
    if ($curso !== null) {
        $sql .= ' WHERE p.curso_slug = ?';
        $params[] = $curso;
    }
    $stmt = mcp_db()->prepare($sql . ' ORDER BY p.atualizado_em DESC LIMIT ' . max(1, $limite));
    $stmt->execute($params);
    return array_map('mcp_horarios_linha', $stmt->fetchAll());
}

/** Quantas respostas por curso, do curso com mais respostas para o com menos. */
function mcp_horarios_contar(): array
{
    $contagem = [];
    foreach (mcp_db()->query('SELECT p.curso_slug, MAX(i.curso_nome) AS curso_nome, COUNT(*) AS n
        FROM mcp_preferencias p JOIN mcp_inscricoes i ON i.id = p.inscricao_id GROUP BY p.curso_slug ORDER BY n DESC, curso_nome')->fetchAll() as $l) {
        $contagem[(string) $l['curso_slug']] = ['nome' => (string) $l['curso_nome'], 'n' => (int) $l['n']];
    }
    return $contagem;
}

/**
 * Mapa de disponibilidade: para cada período e dia, quantos alunos podem naquele horário (quem marcou
 * Seg e Qua à noite conta em Seg-noite e em Qua-noite). Mais a contagem de começo e de "a data serve".
 */
function mcp_horarios_mapa(array $linhas): array
{
    $grade = array_fill_keys(array_keys(MCP_HORARIOS_PERIODOS), array_fill_keys(array_keys(MCP_HORARIOS_DIAS), 0));
    $inicio = array_fill_keys(array_keys(MCP_HORARIOS_INICIO), 0);
    $turma = array_fill_keys(array_keys(MCP_HORARIOS_TURMA), 0);
    foreach ($linhas as $l) {
        foreach ($l['periodos'] as $p) {
            foreach ($l['dias'] as $d) {
                $grade[$p][$d]++;
            }
        }
        if (isset($inicio[$l['inicio']])) {
            $inicio[$l['inicio']]++;
        }
        if (isset($turma[(string) $l['turma_serve']])) {
            $turma[(string) $l['turma_serve']]++;
        }
    }
    $maximo = max(array_map('max', $grade));
    return ['grade' => $grade, 'maximo' => $maximo, 'inicio' => $inicio, 'turma' => $turma, 'total' => count($linhas)];
}

/** Célula de planilha sem fórmula: texto que começa com = + - @ vira texto puro. */
function mcp_horarios_celula(mixed $valor): string
{
    $texto = (string) $valor;
    return preg_match('/^[=+\-@\t\r]/', $texto) ? "'" . $texto : $texto;
}

/** Planilha das respostas (CSV com ; e BOM, abre direto no Excel e no Google Planilhas). */
function mcp_horarios_csv(array $linhas): string
{
    $f = fopen('php://temp', 'w+');
    fputcsv($f, ['Respondido em (Brasília)', 'Nome', 'E-mail', 'Telefone', 'Curso', 'Turma', 'Dias', 'Períodos', 'Começo', 'A data da turma serve?', 'Comentário'], ';', '"', '');
    foreach ($linhas as $l) {
        $turma = mcp_escola_data(mcp_horarios_turma($l));
        fputcsv($f, array_map('mcp_horarios_celula', [
            mcp_data_brt((string) $l['atualizado_em'], 'd/m/Y H:i'),
            $l['nome'], $l['email'], mcp_telefone_bonito((string) $l['telefone']), $l['curso_nome'],
            $turma !== '' ? $turma : 'sem turma ainda',
            implode(', ', array_map(static fn(string $d): string => MCP_HORARIOS_DIAS[$d], $l['dias'])),
            implode(', ', array_map(static fn(string $p): string => MCP_HORARIOS_PERIODOS[$p], $l['periodos'])),
            MCP_HORARIOS_INICIO[$l['inicio']] ?? $l['inicio'],
            $l['turma_serve'] ? (MCP_HORARIOS_TURMA[$l['turma_serve']] ?? $l['turma_serve']) : '',
            (string) $l['observacao'],
        ]), ';', '"', '');
    }
    rewind($f);
    $csv = (string) stream_get_contents($f);
    fclose($f);
    return "\xEF\xBB\xBF" . $csv;
}

// ----------------------------------------------------------------------------- aviso à secretaria
function mcp_montar_email_horarios(array $inscricao, array $p): array
{
    $turma = mcp_escola_data(mcp_horarios_turma($inscricao));
    $linhas = [
        'Aluno' => $inscricao['nome'],
        'Curso' => $inscricao['curso_nome'],
        'Turma' => $turma !== '' ? "começa em $turma" : 'sem turma ainda',
        'Dias' => implode(', ', array_map(static fn(string $d): string => MCP_HORARIOS_DIAS[$d], $p['dias'])),
        'Períodos' => implode(', ', array_map(static fn(string $x): string => MCP_HORARIOS_PERIODOS[$x] . ' (' . MCP_HORARIOS_PERIODOS_HORAS[$x] . ')', $p['periodos'])),
        'Começo' => MCP_HORARIOS_INICIO[$p['inicio']] ?? $p['inicio'],
    ];
    if ($p['turma_serve']) {
        $linhas['A data da turma serve?'] = MCP_HORARIOS_TURMA[$p['turma_serve']] ?? $p['turma_serve'];
    }
    if ($p['observacao']) {
        $linhas['Comentário'] = $p['observacao'];
    }
    $linhas['E-mail'] = $inscricao['email'];
    $linhas['Telefone'] = mcp_telefone_bonito((string) $inscricao['telefone']);
    $texto = '';
    foreach ($linhas as $rotulo => $valor) {
        $texto .= "$rotulo: $valor\n";
    }
    $painel = mcp_painel_url() . '?v=horarios&curso=' . rawurlencode((string) $inscricao['curso_slug']);
    $corpo = mcp_p('Um aluno respondeu quais dias e horários são melhores para fazer o curso. O mapa com todas as respostas deste curso está no painel.')
        . mcp_caixa($linhas)
        . mcp_botao($painel, 'Ver o mapa do curso no painel');
    return [
        'assunto' => 'Horários: ' . $inscricao['nome'] . ' — ' . $inscricao['curso_nome'],
        'html' => mcp_moldura('Preferência de dias e horários', $corpo, ['eyebrow' => 'Aviso interno · matrícula cursos presenciais', 'motivo' => 'Aviso automático do questionário de horários de cruzvermelhariodejaneiro.org para a secretaria.']),
        'texto' => $texto . "\nMapa do curso: $painel\n",
    ];
}

/** Na primeira resposta do aluno, se EMAIL_SECRETARIA estiver configurado. Responder-para é o aluno. */
function mcp_horarios_avisar_secretaria(array $inscricao, array $preferencia): void
{
    $para = (string) mcp_cfg('EMAIL_SECRETARIA', '');
    if ($para === '') {
        return;
    }
    $m = mcp_montar_email_horarios($inscricao, $preferencia);
    $r = mcp_enviar_email($para, $m['assunto'], $m['html'], $m['texto'], (string) $inscricao['email']);
    mcp_registrar((int) $inscricao['id'], 'email_horarios', $r);
}
