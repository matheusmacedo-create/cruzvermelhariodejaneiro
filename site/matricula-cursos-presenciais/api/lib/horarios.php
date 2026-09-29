<?php
/**
 * Dias e horários preferidos pelo aluno (29/09/2026).
 *
 * Depois de pagar a inscrição, o aluno marca numa grade (dia × período) os horários em que consegue
 * vir às aulas, diz a partir de quando pode começar e, se a escola já o colocou numa turma, se a data
 * serve. Responde pelo link pessoal (horarios/?t=<token>, o mesmo da tela Parabéns) e pode mudar quando
 * quiser. Cada horário é uma combinação "dia-período" ("seg-noite"): assim o mapa da secretaria é
 * exato (quem marca sábado de manhã e noites de semana não aparece em "sábado à noite").
 *
 * Quem não responde vê o alerta no topo da tela Parabéns e recebe até dois lembretes por e-mail
 * (api/lembretes.php, rodado pelo cron). A secretaria vê no painel (api/painel.php?v=horarios) o mapa
 * por curso, a lista com os contatos e baixa a planilha.
 *
 * Fase 2: a mesma pergunta dentro da área do aluno da escola, quando houver acesso ao código da
 * plataforma (contrato dos dados em docs/escola/README.md).
 */
declare(strict_types=1);

const MCP_HORARIOS_DIAS = ['seg' => 'Segunda', 'ter' => 'Terça', 'qua' => 'Quarta', 'qui' => 'Quinta', 'sex' => 'Sexta', 'sab' => 'Sábado'];
const MCP_HORARIOS_DIAS_CURTOS = ['seg' => 'Seg', 'ter' => 'Ter', 'qua' => 'Qua', 'qui' => 'Qui', 'sex' => 'Sex', 'sab' => 'Sáb'];
const MCP_HORARIOS_PERIODOS = ['manha' => 'Manhã', 'tarde' => 'Tarde', 'noite' => 'Noite'];
const MCP_HORARIOS_PERIODOS_HORAS = ['manha' => '8h às 12h', 'tarde' => '13h às 17h', 'noite' => '18h às 22h'];
const MCP_HORARIOS_PERIODOS_FRASE = ['manha' => 'de manhã', 'tarde' => 'à tarde', 'noite' => 'à noite'];
const MCP_HORARIOS_INICIO = ['proxima' => 'Já na próxima turma', '1mes' => 'Daqui a cerca de 1 mês', '2meses' => 'Daqui a 2 meses ou mais'];
const MCP_HORARIOS_TURMA = ['sim' => 'Sim, a data funciona', 'nao' => 'Não, preciso de outra data'];
const MCP_HORARIOS_OBS_MAX = 500;
/** Atalhos da tela: um toque marca (ou desmarca) um conjunto comum de horários. */
const MCP_HORARIOS_ATALHOS = [
    'noites' => ['Noites de semana', ['seg-noite', 'ter-noite', 'qua-noite', 'qui-noite', 'sex-noite']],
    'sabado' => ['Sábado', ['sab-manha', 'sab-tarde']],
    'manhas' => ['Manhãs de semana', ['seg-manha', 'ter-manha', 'qua-manha', 'qui-manha', 'sex-manha']],
    'tardes' => ['Tardes de semana', ['seg-tarde', 'ter-tarde', 'qua-tarde', 'qui-tarde', 'sex-tarde']],
];
/** Lembretes a quem não respondeu: horas depois do pagamento (no máximo um por item, nesta ordem). */
const MCP_HORARIOS_LEMBRETES_HORAS = [24, 72];
const MCP_HORARIOS_LEMBRETES_JANELA_DIAS = 14;

/** Todas as combinações dia-período, na ordem da semana e do dia. */
function mcp_horarios_slots(): array
{
    $slots = [];
    foreach (array_keys(MCP_HORARIOS_DIAS) as $d) {
        foreach (array_keys(MCP_HORARIOS_PERIODOS) as $p) {
            $slots[] = "$d-$p";
        }
    }
    return $slots;
}

/** Data (AAAA-MM-DD) da turma em que a escola já colocou o aluno, ou null. */
function mcp_horarios_turma(array $inscricao): ?string
{
    $acesso = mcp_escola_acesso($inscricao);
    return $acesso && ($acesso['resultado'] ?? '') === 'matriculado' && is_string($acesso['turma_inicio'] ?? null)
        ? $acesso['turma_inicio'] : null;
}

/** Só as combinações conhecidas, na ordem da semana, sem repetição. */
function mcp_horarios_filtrar(mixed $valores): array
{
    $lista = is_array($valores) ? array_filter($valores, 'is_string') : [];
    return array_values(array_filter(mcp_horarios_slots(), static fn(string $s): bool => in_array($s, $lista, true)));
}

/**
 * Confere o que veio da tela. Devolve ['ok' => true, 'dados' => [...]] ou ['ok' => false, 'campo', 'erro'].
 * Valores desconhecidos são descartados.
 */
function mcp_horarios_conferir(array $b, bool $temTurma): array
{
    $falha = static fn(string $campo, string $erro): array => ['ok' => false, 'campo' => $campo, 'erro' => $erro];
    $horarios = mcp_horarios_filtrar($b['horarios'] ?? null);
    if (!$horarios) {
        return $falha('horarios', 'Marque pelo menos um horário em que você consegue vir.');
    }
    $inicio = mcp_texto($b['inicio'] ?? '', 20);
    if (!isset(MCP_HORARIOS_INICIO[$inicio])) {
        return $falha('inicio', 'Diga a partir de quando você pode começar.');
    }
    $turma = null;
    if ($temTurma) {
        $turma = mcp_texto($b['turma_serve'] ?? '', 10);
        if (!isset(MCP_HORARIOS_TURMA[$turma])) {
            return $falha('turma_serve', 'Diga se a data da sua turma funciona para você.');
        }
    }
    $obs = mcp_texto_longo($b['observacao'] ?? '', MCP_HORARIOS_OBS_MAX + 1);
    if (mb_strlen($obs) > MCP_HORARIOS_OBS_MAX) {
        return $falha('observacao', 'O recado pode ter até ' . MCP_HORARIOS_OBS_MAX . ' caracteres.');
    }
    return ['ok' => true, 'dados' => ['horarios' => $horarios, 'inicio' => $inicio, 'turma_serve' => $turma, 'observacao' => $obs !== '' ? $obs : null]];
}

/**
 * Grava ou atualiza as respostas da inscrição. Devolve true quando é a primeira resposta. Quem diz é o
 * próprio INSERT ... ON DUPLICATE KEY UPDATE (1 linha afetada = nova, 2 = atualizada; vezes sempre muda),
 * e não uma consulta antes: dois envios simultâneos não viram duas "primeiras" nem dois avisos.
 */
function mcp_horarios_salvar(int $inscricaoId, string $cursoSlug, array $dados): bool
{
    $agora = mcp_agora();
    $stmt = mcp_db()->prepare('INSERT INTO mcp_preferencias (inscricao_id, curso_slug, horarios, inicio, turma_serve, observacao, vezes, criado_em, atualizado_em)
        VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)
        ON DUPLICATE KEY UPDATE curso_slug = VALUES(curso_slug), horarios = VALUES(horarios), inicio = VALUES(inicio),
            turma_serve = VALUES(turma_serve), observacao = VALUES(observacao), vezes = vezes + 1, atualizado_em = VALUES(atualizado_em)');
    $stmt->execute([$inscricaoId, $cursoSlug, implode(',', $dados['horarios']), $dados['inicio'], $dados['turma_serve'], $dados['observacao'], $agora, $agora]);
    return $stmt->rowCount() === 1;
}

function mcp_horarios_por_inscricao(int $inscricaoId): ?array
{
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_preferencias WHERE inscricao_id = ?');
    $stmt->execute([$inscricaoId]);
    $linha = $stmt->fetch();
    return $linha ? mcp_horarios_linha($linha) : null;
}

/** Linha do banco com os horários como lista (só valores conhecidos). */
function mcp_horarios_linha(array $l): array
{
    $l['horarios'] = mcp_horarios_filtrar(explode(',', (string) $l['horarios']));
    return $l;
}

/** "A, B e C". */
function mcp_horarios_lista(array $itens): string
{
    $itens = array_values($itens);
    return count($itens) > 1 ? implode(', ', array_slice($itens, 0, -1)) . ' e ' . end($itens) : (string) ($itens[0] ?? '');
}

/** Dias em texto curto, com sequências de 3 ou mais como faixa: "Seg a Sex", "Seg a Qua e Sex", "Ter e Qui". */
function mcp_horarios_dias_texto(array $dias): string
{
    $ordem = array_keys(MCP_HORARIOS_DIAS);
    $indices = array_values(array_filter(array_map(static fn(string $d) => array_search($d, $ordem, true), $dias), 'is_int'));
    sort($indices);
    $itens = [];
    for ($i = 0, $n = count($indices); $i < $n; $i = $j) {
        for ($j = $i + 1; $j < $n && $indices[$j] === $indices[$j - 1] + 1; $j++);
        $faixa = array_slice($indices, $i, $j - $i);
        if (count($faixa) >= 3) {
            $itens[] = MCP_HORARIOS_DIAS_CURTOS[$ordem[$faixa[0]]] . ' a ' . MCP_HORARIOS_DIAS_CURTOS[$ordem[end($faixa)]];
        } else {
            foreach ($faixa as $k) {
                $itens[] = MCP_HORARIOS_DIAS_CURTOS[$ordem[$k]];
            }
        }
    }
    return mcp_horarios_lista($itens);
}

/**
 * Horários em linguagem de gente: dias com os mesmos períodos ficam juntos.
 * Ex.: "Seg a Sex à noite; Sáb de manhã e à tarde". Os três períodos viram "o dia todo".
 */
function mcp_horarios_texto(array $slots): string
{
    $porDia = [];
    foreach (mcp_horarios_filtrar($slots) as $s) {
        [$d, $p] = explode('-', $s);
        $porDia[$d][] = $p;
    }
    $grupos = [];
    foreach ($porDia as $d => $periodos) {
        $grupos[implode(',', $periodos)][] = $d;
    }
    $partes = [];
    foreach ($grupos as $chave => $dias) {
        $periodos = explode(',', $chave);
        $frase = count($periodos) === count(MCP_HORARIOS_PERIODOS)
            ? 'o dia todo' : mcp_horarios_lista(array_map(static fn(string $p): string => MCP_HORARIOS_PERIODOS_FRASE[$p], $periodos));
        $partes[] = mcp_horarios_dias_texto($dias) . ' ' . $frase;
    }
    return implode('; ', $partes);
}

/** Resumo de uma linha: "Seg a Sex à noite; Sáb de manhã · Já na próxima turma". */
function mcp_horarios_resumo(array $p): string
{
    return mcp_horarios_texto($p['horarios']) . ' · ' . (MCP_HORARIOS_INICIO[$p['inicio']] ?? '');
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
    $atalhos = [];
    foreach (MCP_HORARIOS_ATALHOS as $chave => [$rotulo, $slots]) {
        $atalhos[] = ['chave' => $chave, 'rotulo' => $rotulo, 'horarios' => $slots];
    }
    return [
        'ok' => true,
        'nome' => mcp_primeiro_nome((string) $inscricao['nome']),
        'curso' => ['slug' => $inscricao['curso_slug'], 'nome' => $inscricao['curso_nome']],
        'turma_inicio' => mcp_horarios_turma($inscricao),
        'opcoes' => ['dias' => MCP_HORARIOS_DIAS, 'dias_curtos' => MCP_HORARIOS_DIAS_CURTOS, 'periodos' => MCP_HORARIOS_PERIODOS,
            'horas' => MCP_HORARIOS_PERIODOS_HORAS, 'atalhos' => $atalhos, 'inicio' => MCP_HORARIOS_INICIO,
            'turma' => MCP_HORARIOS_TURMA, 'observacao_max' => MCP_HORARIOS_OBS_MAX],
        'resposta' => $preferencia ? [
            'horarios' => $preferencia['horarios'], 'inicio' => $preferencia['inicio'],
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
 * Mapa de disponibilidade: para cada período e dia, quantos alunos marcaram aquele horário. Mais a
 * contagem de começo e de "a data serve".
 */
function mcp_horarios_mapa(array $linhas): array
{
    $grade = array_fill_keys(array_keys(MCP_HORARIOS_PERIODOS), array_fill_keys(array_keys(MCP_HORARIOS_DIAS), 0));
    $inicio = array_fill_keys(array_keys(MCP_HORARIOS_INICIO), 0);
    $turma = array_fill_keys(array_keys(MCP_HORARIOS_TURMA), 0);
    foreach ($linhas as $l) {
        foreach ($l['horarios'] as $s) {
            [$d, $p] = explode('-', $s);
            $grade[$p][$d]++;
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

/**
 * Planilha das respostas (CSV com ; e BOM, abre direto no Excel e no Google Planilhas). Além do resumo,
 * uma coluna por horário ("Seg manhã" ... "Sáb noite") com x: dá para filtrar quem pode em cada um.
 */
function mcp_horarios_csv(array $linhas): string
{
    $colunasSlots = array_map(static function (string $s): string {
        [$d, $p] = explode('-', $s);
        return MCP_HORARIOS_DIAS_CURTOS[$d] . ' ' . mb_strtolower(MCP_HORARIOS_PERIODOS[$p]);
    }, mcp_horarios_slots());
    $f = fopen('php://temp', 'w+');
    fputcsv($f, array_merge(['Respondido em (Brasília)', 'Nome', 'E-mail', 'Telefone', 'Curso', 'Turma', 'Horários', 'Começo', 'A data da turma serve?', 'Recado'], $colunasSlots), ';', '"', '');
    foreach ($linhas as $l) {
        $turma = mcp_escola_data(mcp_horarios_turma($l));
        $marcas = array_map(static fn(string $s): string => in_array($s, $l['horarios'], true) ? 'x' : '', mcp_horarios_slots());
        fputcsv($f, array_merge(array_map('mcp_horarios_celula', [
            mcp_data_brt((string) $l['atualizado_em'], 'd/m/Y H:i'),
            $l['nome'], $l['email'], mcp_telefone_bonito((string) $l['telefone']), $l['curso_nome'],
            $turma !== '' ? $turma : 'sem turma ainda',
            mcp_horarios_texto($l['horarios']),
            MCP_HORARIOS_INICIO[$l['inicio']] ?? $l['inicio'],
            $l['turma_serve'] ? (MCP_HORARIOS_TURMA[$l['turma_serve']] ?? $l['turma_serve']) : '',
            (string) $l['observacao'],
        ]), $marcas), ';', '"', '');
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
        'Pode vir' => mcp_horarios_texto($p['horarios']),
        'Começo' => MCP_HORARIOS_INICIO[$p['inicio']] ?? $p['inicio'],
    ];
    if ($p['turma_serve']) {
        $linhas['A data da turma serve?'] = MCP_HORARIOS_TURMA[$p['turma_serve']] ?? $p['turma_serve'];
    }
    if ($p['observacao']) {
        $linhas['Recado'] = $p['observacao'];
    }
    $linhas['E-mail'] = $inscricao['email'];
    $linhas['Telefone'] = mcp_telefone_bonito((string) $inscricao['telefone']);
    $texto = '';
    foreach ($linhas as $rotulo => $valor) {
        $texto .= "$rotulo: $valor\n";
    }
    $painel = mcp_painel_url() . '?v=horarios&curso=' . rawurlencode((string) $inscricao['curso_slug']);
    $corpo = mcp_p('Um aluno respondeu em quais dias e horários consegue vir às aulas. O mapa com todas as respostas deste curso está no painel.')
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

// ----------------------------------------------------------------------------- lembretes ao aluno
/**
 * Pagamentos a partir desta data (UTC) entram nos lembretes e na contagem de quem falta responder. Quem
 * pagou antes do questionário existir não recebeu o convite; incluí-los é decisão da equipe
 * (HORARIOS_LEMBRETES_DESDE na configuração).
 */
const MCP_HORARIOS_DESDE_PADRAO = '2026-09-30 00:00:00';

/**
 * Qual lembrete (1 ou 2) cabe agora a esta inscrição, ou 0. $enviados: quantos já saíram; $ultimo: quando
 * saiu o último (UTC). O segundo espera 72 h do pagamento e 48 h do primeiro.
 */
function mcp_horarios_lembrete_devido(string $pagoEm, int $enviados, ?string $ultimo, int $agora): int
{
    if ($enviados >= count(MCP_HORARIOS_LEMBRETES_HORAS)) {
        return 0;
    }
    $pago = (int) strtotime($pagoEm . ' UTC');
    if ($agora - $pago < MCP_HORARIOS_LEMBRETES_HORAS[$enviados] * 3600) {
        return 0;
    }
    if ($ultimo !== null && $agora - (int) strtotime($ultimo . ' UTC') < 48 * 3600) {
        return 0;
    }
    return $enviados + 1;
}

function mcp_montar_email_lembrete_horarios(array $inscricao, int $numero): array
{
    $nome = mcp_primeiro_nome((string) $inscricao['nome']);
    $curso = (string) $inscricao['curso_nome'];
    $url = mcp_url_pagina('horarios', (string) $inscricao['token']) . '&utm_source=email&utm_medium=transacional&utm_campaign=lembrete-horarios-' . $numero;
    $turma = mcp_escola_data(mcp_horarios_turma($inscricao));
    $abertura = $numero === 1
        ? 'Sua inscrição em <strong>' . mcp_escapar($curso) . '</strong> está paga. Falta só um passo: dizer em quais dias e horários você consegue vir às aulas.'
        : 'Ainda não sabemos em quais dias e horários você consegue fazer <strong>' . mcp_escapar($curso) . '</strong>. A secretaria está montando as turmas e quer contar com você.';
    $corpo = mcp_p('Oi, ' . mcp_escapar($nome) . '. ' . $abertura)
        . mcp_p('É só tocar nos horários possíveis. Leva 30 segundos, e quanto mais opções você marcar, mais fácil encaixar você numa turma.')
        . mcp_botao($url, 'Escolher meus horários')
        . ($turma !== '' ? mcp_p('Sua turma começa em <strong>' . mcp_escapar($turma) . '</strong>. Se essa data não servir, é por aí também que você avisa.') : '')
        . mcp_nota('O link é pessoal e não precisa de senha. Você pode mudar as respostas quando quiser.');
    $texto = "Oi, $nome. " . html_entity_decode(strip_tags($abertura), ENT_QUOTES, 'UTF-8') . "\n\nÉ só tocar nos horários possíveis. Leva 30 segundos.\nEscolher meus horários: $url\n\n"
        . ($turma !== '' ? "Sua turma começa em $turma. Se essa data não servir, é por lá também que você avisa.\n\n" : '')
        . 'Dúvidas? Responda este e-mail ou escreva para ' . mcp_email_contato_endereco() . '.';
    return [
        'assunto' => $numero === 1 ? "Falta 1 passo: quando você pode fazer as aulas de $curso?" : "Ainda dá tempo: seus horários para $curso",
        'html' => mcp_moldura($numero === 1 ? "Falta só um passo, $nome." : "Ainda dá tempo, $nome.", $corpo, [
            'eyebrow' => 'Matrícula cursos presenciais',
            'preheader' => 'Toque nos dias e horários em que você consegue vir. Leva 30 segundos.',
            'motivo' => 'Você recebeu este e-mail porque pagou uma inscrição em cruzvermelhariodejaneiro.org e ainda não disse seus horários. São no máximo dois lembretes, e eles param assim que você responde.',
        ]),
        'texto' => $texto,
    ];
}

/**
 * Manda os lembretes devidos (rodado pelo cron, api/lembretes.php). Um lembrete só conta quando sai;
 * se o envio falha, tenta de novo na próxima rodada. Devolve ['enviados' => n, 'falhas' => n, 'vistos' => n].
 */
function mcp_horarios_enviar_lembretes(int $limite = 50, ?int $agora = null): array
{
    $agora ??= time();
    $db = mcp_db();
    $desde = max((string) mcp_cfg('HORARIOS_LEMBRETES_DESDE', MCP_HORARIOS_DESDE_PADRAO),
        gmdate('Y-m-d H:i:s', $agora - MCP_HORARIOS_LEMBRETES_JANELA_DIAS * 86400));
    $stmt = $db->prepare("SELECT i.* FROM mcp_inscricoes i LEFT JOIN mcp_preferencias p ON p.inscricao_id = i.id
        WHERE i.status = 'pago' AND p.inscricao_id IS NULL AND i.pago_em IS NOT NULL AND i.pago_em >= ? AND i.pago_em <= ?
        ORDER BY i.pago_em LIMIT " . max(1, $limite));
    $stmt->execute([$desde, gmdate('Y-m-d H:i:s', $agora - MCP_HORARIOS_LEMBRETES_HORAS[0] * 3600)]);
    $contar = $db->prepare("SELECT COUNT(*) AS n, MAX(criado_em) AS ultimo FROM mcp_eventos WHERE inscricao_id = ? AND tipo = 'lembrete_horarios'");
    $r = ['enviados' => 0, 'falhas' => 0, 'vistos' => 0];
    foreach ($stmt->fetchAll() as $inscricao) {
        $r['vistos']++;
        $contar->execute([(int) $inscricao['id']]);
        $ja = $contar->fetch();
        $numero = mcp_horarios_lembrete_devido((string) $inscricao['pago_em'], (int) $ja['n'], $ja['ultimo'] ?: null, $agora);
        if ($numero === 0) {
            continue;
        }
        $m = mcp_montar_email_lembrete_horarios($inscricao, $numero);
        $envio = mcp_enviar_email((string) $inscricao['email'], $m['assunto'], $m['html'], $m['texto']);
        if ($envio === 'falhou') {
            $r['falhas']++;
            mcp_registrar((int) $inscricao['id'], 'lembrete_horarios_falhou', "#$numero");
            continue;
        }
        $r['enviados']++;
        mcp_registrar((int) $inscricao['id'], 'lembrete_horarios', "#$numero $envio");
    }
    return $r;
}
