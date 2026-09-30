<?php
/**
 * Resultados do ponto e da comunicação (30/09/2026), para a aba Resultados do portal: adesão por
 * vínculo, registros por dia e por jeito de registrar, horas doadas, saídas esquecidas, presença dos
 * alunos, o efeito dos lembretes (quem registrou depois de recebê-los) e a opinião das pessoas.
 * Tudo em datas de Brasília. O período anterior, do mesmo tamanho, serve de comparação.
 */
declare(strict_types=1);

/** Maior período que o portal mostra de uma vez (em dias). */
const MCP_METRICAS_MAX_DIAS = 120;

/**
 * Período pedido na URL: 14 (padrão), 30, 60 ou "lancamento" (desde a data do lançamento). Devolve
 * [de, ateExclusivo, rótulo], com o fim sempre amanhã (hoje entra).
 */
function mcp_metricas_periodo(string $pedido, ?int $agora = null): array
{
    $hoje = mcp_ponto_hoje($agora);
    $amanha = mcp_avisos_dia_mais($hoje, 1);
    $lancamento = mcp_ajuste('lancamento');
    if ($pedido === 'lancamento' && $lancamento !== '' && $lancamento <= $hoje) {
        $de = max($lancamento, mcp_avisos_dia_mais($amanha, -MCP_METRICAS_MAX_DIAS));
        return [$de, $amanha, 'desde o lançamento (' . mcp_escola_data($lancamento) . ')'];
    }
    $dias = in_array($pedido, ['30', '60'], true) ? (int) $pedido : 14;
    return [mcp_avisos_dia_mais($amanha, -$dias), $amanha, "últimos $dias dias"];
}

/** Dias entre duas datas AAAA-MM-DD ([de, ate)). */
function mcp_metricas_dias(string $de, string $ate): int
{
    return max(1, (int) (new DateTimeImmutable($de))->diff(new DateTimeImmutable($ate))->days);
}

/**
 * Números centrais de um período (usados no atual e no anterior, para comparar).
 * @return array{ativos: int, ativos_voluntarios: int, com_registro: int, com_registro_voluntarios: int, entradas: int, entradas_voluntario: int,
 *               horas_minutos: int, esquecidas: int, informadas: int, por_origem: array, por_dia: array, presencas_alunos: int}
 */
function mcp_metricas_nucleo(string $deIso, string $ateIso, ?int $agora = null): array
{
    $agora ??= time();
    [$de, $ate] = mcp_ponto_periodo_utc($deIso, $ateIso);
    $r = [
        'ativos' => 0, 'ativos_voluntarios' => 0, 'com_registro' => 0, 'com_registro_voluntarios' => 0, 'entradas' => 0, 'entradas_voluntario' => 0,
        'horas_minutos' => 0, 'esquecidas' => 0, 'informadas' => 0, 'por_origem' => array_fill_keys(array_keys(MCP_PONTO_ORIGENS), 0), 'por_dia' => [], 'presencas_alunos' => 0,
    ];
    $voluntarios = [];
    foreach (mcp_colaboradores_listar() as $c) {
        if ((int) $c['ativo']) {
            $r['ativos']++;
            if (mcp_ponto_voluntario($c)) {
                $r['ativos_voluntarios']++;
            }
        }
        $voluntarios[(int) $c['id']] = mcp_ponto_voluntario($c);
    }
    $stmt = mcp_db()->prepare('SELECT p.colaborador_id, p.voluntario, p.entrada, p.saida, p.saida_informada, p.origem_entrada, p.origem_saida, c.ativo
        FROM mcp_ponto p JOIN mcp_colaboradores c ON c.id = p.colaborador_id WHERE p.entrada >= ? AND p.entrada < ?');
    $stmt->execute([$de, $ate]);
    $pessoas = [];
    $pessoasVol = [];
    $segundos = 0;
    foreach ($stmt->fetchAll() as $p) {
        $r['entradas']++;
        $dia = mcp_data_brt((string) $p['entrada'], 'Y-m-d');
        $r['por_dia'][$dia] = ($r['por_dia'][$dia] ?? 0) + 1;
        $origem = (string) $p['origem_entrada'];
        $r['por_origem'][$origem] = ($r['por_origem'][$origem] ?? 0) + 1;
        if ((int) $p['ativo']) {
            $pessoas[(int) $p['colaborador_id']] = true;
            if ($voluntarios[(int) $p['colaborador_id']] ?? false) {
                $pessoasVol[(int) $p['colaborador_id']] = true;
            }
        }
        if ((int) $p['voluntario'] === 1) {
            $r['entradas_voluntario']++;
            // Esquecida: a saída não foi registrada na hora, esteja ou não resolvida (informada pela pessoa ou
            // lançada pela secretaria depois). Assim o período atual e o anterior se comparam do mesmo jeito.
            $informada = $p['origem_saida'] === 'informada' || ($p['saida'] === null && $p['saida_informada'] !== null);
            if ($p['saida'] !== null) {
                $segundos += max(0, (int) strtotime($p['saida'] . ' UTC') - (int) strtotime($p['entrada'] . ' UTC'));
            }
            if ($informada) {
                $r['informadas']++;
            }
            if ($informada || $p['origem_saida'] === 'portal'
                || ($p['saida'] === null && (int) strtotime($p['entrada'] . ' UTC') <= $agora - MCP_PONTO_ESQUECIDA_HORAS * 3600)) {
                $r['esquecidas']++;
            }
        }
    }
    $r['com_registro'] = count($pessoas);
    $r['com_registro_voluntarios'] = count($pessoasVol);
    $r['horas_minutos'] = intdiv($segundos, 60);
    ksort($r['por_dia']);
    $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_presencas WHERE status = 'valida' AND chegada >= ? AND chegada < ?");
    $stmt->execute([$de, $ate]);
    $r['presencas_alunos'] = (int) $stmt->fetchColumn();
    return $r;
}

/**
 * Efeito dos avisos criados no período, por tipo: quantos saíram, falharam, foram clicados e quantos
 * foram seguidos de registro (véspera: a pessoa registrou entrada no dia; saída: a saída foi informada ou
 * corrigida; aula: o aluno confirmou presença no dia). Só entram na conta os que já podiam dar resultado:
 * véspera e aula cujo dia já passou; saída informada, ou com o prazo do link vencido. Poucas consultas
 * (em conjunto), não uma por aviso.
 */
function mcp_metricas_avisos(string $deIso, string $ateIso, ?int $agora = null): array
{
    $agora ??= time();
    $hoje = mcp_ponto_hoje($agora);
    [$de, $ate] = mcp_ponto_periodo_utc($deIso, $ateIso);
    $r = [];
    foreach (array_keys(MCP_AVISOS_TIPOS) as $tipo) {
        $r[$tipo] = ['preparados' => 0, 'enviados' => 0, 'falhas' => 0, 'fila' => 0, 'cancelados' => 0, 'clicados' => 0, 'email' => 0, 'whatsapp' => 0, 'convertidos' => 0, 'avaliados' => 0];
    }
    $stmt = mcp_db()->prepare('SELECT tipo, canal, status, clicado_em, pessoa, destino, referencia, colaborador_id, dados, enviado_em FROM mcp_avisos WHERE criado_em >= ? AND criado_em < ?');
    $stmt->execute([$de, $ate]);
    $avisos = $stmt->fetchAll();
    // O que é preciso conferir, de uma vez: entradas por pessoa e dia, registros de saída e presenças por e-mail e dia.
    $datas = [];
    $pontos = [];
    foreach ($avisos as $a) {
        if (in_array($a['tipo'], ['vespera', 'aula'], true) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $a['referencia'])) {
            $datas[] = (string) $a['referencia'];
        } elseif ($a['tipo'] === 'saida') {
            $pontos[] = (int) ((json_decode((string) $a['dados'], true) ?: [])['ponto'] ?? 0);
        }
    }
    $entrou = [];
    $presente = [];
    if ($datas) {
        [$d0, $d1] = mcp_ponto_periodo_utc(min($datas), mcp_avisos_dia_mais(max($datas), 1));
        $st = mcp_db()->prepare('SELECT colaborador_id, entrada FROM mcp_ponto WHERE entrada >= ? AND entrada < ?');
        $st->execute([$d0, $d1]);
        foreach ($st->fetchAll() as $p) {
            $entrou[$p['colaborador_id'] . '|' . mcp_data_brt((string) $p['entrada'], 'Y-m-d')] = true;
        }
        $st = mcp_db()->prepare("SELECT email, aula_data FROM mcp_presencas WHERE status = 'valida' AND aula_data >= ? AND aula_data <= ?");
        $st->execute([min($datas), max($datas)]);
        foreach ($st->fetchAll() as $p) {
            $presente[mb_strtolower((string) $p['email']) . '|' . $p['aula_data']] = true;
        }
    }
    $saidas = [];
    $pontos = array_values(array_unique(array_filter($pontos)));
    if ($pontos) {
        $st = mcp_db()->prepare('SELECT id, saida, saida_informada FROM mcp_ponto WHERE id IN (' . implode(',', array_fill(0, count($pontos), '?')) . ')');
        $st->execute($pontos);
        foreach ($st->fetchAll() as $p) {
            $saidas[(int) $p['id']] = $p['saida'] !== null || $p['saida_informada'] !== null;
        }
    }
    $vistos = [];
    foreach ($avisos as $a) {
        if (!isset($r[$a['tipo']])) {
            continue;
        }
        $t = &$r[$a['tipo']];
        $t['preparados']++;
        $t[$a['canal']] = ($t[$a['canal']] ?? 0) + 1;
        match ($a['status']) {
            'enviado' => $t['enviados']++,
            'falhou' => $t['falhas']++,
            'pendente', 'enviando', 'manual' => $t['fila']++,
            default => $t['cancelados']++,
        };
        if ($a['clicado_em'] !== null) {
            $t['clicados']++;
        }
        // Conversão: uma vez por pessoa e referência (quem recebeu por e-mail e WhatsApp conta uma vez).
        $chave = $a['tipo'] . '|' . ($a['pessoa'] ?? $a['destino']) . '|' . $a['referencia'];
        if ($a['status'] !== 'enviado' || isset($vistos[$chave]) || !in_array($a['tipo'], ['vespera', 'saida', 'aula'], true)) {
            unset($t);
            continue;
        }
        $dados = json_decode((string) $a['dados'], true) ?: [];
        $ok = null; // null: ainda não dá para saber
        if ($a['tipo'] === 'vespera' && $a['colaborador_id'] && (string) $a['referencia'] < $hoje) {
            $ok = isset($entrou[$a['colaborador_id'] . '|' . $a['referencia']]);
        } elseif ($a['tipo'] === 'saida') {
            $feito = $saidas[(int) ($dados['ponto'] ?? 0)] ?? false;
            $prazo = (int) strtotime((string) $a['enviado_em'] . ' UTC') + MCP_AVISOS_LINK_DIAS['saida'] * 86400 < $agora;
            $ok = $feito ? true : ($prazo ? false : null);
        } elseif ($a['tipo'] === 'aula' && !empty($dados['email']) && (string) $a['referencia'] < $hoje) {
            $ok = isset($presente[mb_strtolower((string) $dados['email']) . '|' . $a['referencia']]);
        }
        if ($ok !== null) {
            $vistos[$chave] = true;
            $t['avaliados']++;
            $t['convertidos'] += $ok ? 1 : 0;
        }
        unset($t);
    }
    return $r;
}

/**
 * Uma linha por voluntário ou membro da diretoria ativo (e inativo com registro no período): dias, horas
 * doadas, saídas a completar e se tem lembrete e contato. Os outros vínculos ficam fora: presença da equipe
 * contratada por pessoa pareceria controle de jornada (e a planilha baixada não se apaga em 90 dias).
 * Sem cliques nem "respondeu a opinião": isso só no total.
 */
function mcp_metricas_pessoas(string $deIso, string $ateIso, ?int $agora = null): array
{
    $agora ??= time();
    [$de, $ate] = mcp_ponto_periodo_utc($deIso, $ateIso);
    $por = [];
    foreach (mcp_ponto_registros($de, $ate) as $p) {
        if ((int) $p['voluntario'] !== 1) {
            continue;
        }
        $id = (int) $p['colaborador_id'];
        $por[$id] ??= ['dias' => [], 'segundos' => 0, 'esquecidas' => 0];
        $por[$id]['dias'][mcp_data_brt((string) $p['entrada'], 'Y-m-d')] = true;
        if ($p['saida'] !== null) {
            $por[$id]['segundos'] += mcp_ponto_segundos($p);
        } elseif ($p['saida_informada'] === null && mcp_ponto_esquecido($p, $agora)) {
            $por[$id]['esquecidas']++;
        }
    }
    $linhas = [];
    foreach (mcp_colaboradores_listar() as $c) {
        $id = (int) $c['id'];
        $x = $por[$id] ?? null;
        if (!mcp_ponto_voluntario($c) || (!(int) $c['ativo'] && $x === null)) {
            continue;
        }
        $linhas[] = [
            'id' => $id, 'nome' => (string) $c['nome'], 'vinculo' => (string) $c['vinculo'], 'voluntario' => true, 'ativo' => (bool) (int) $c['ativo'],
            'dias' => count($x['dias'] ?? []), 'minutos' => intdiv($x['segundos'] ?? 0, 60), 'esquecidas' => $x['esquecidas'] ?? 0,
            'lembrete' => mcp_avisos_dias($c['aviso_dias'] ?? '') !== [], 'whatsapp' => (int) ($c['aviso_whatsapp'] ?? 0) === 1,
            'contato' => filter_var((string) $c['email'], FILTER_VALIDATE_EMAIL) !== false || mcp_whatsapp_numero((string) $c['telefone']) !== null,
        ];
    }
    // Por nome (sem ranking de horas: o voluntariado não é competição).
    usort($linhas, static fn(array $a, array $b): int => [$b['ativo'], mcp_sem_acento(mb_strtolower($a['nome']))] <=> [$a['ativo'], mcp_sem_acento(mb_strtolower($b['nome']))]);
    return $linhas;
}

/** Tudo o que a aba Resultados mostra, com o período anterior do mesmo tamanho para comparar. */
function mcp_metricas(string $deIso, string $ateIso, ?int $agora = null): array
{
    $agora ??= time();
    $dias = mcp_metricas_dias($deIso, $ateIso);
    $antesDe = mcp_avisos_dia_mais($deIso, -$dias);
    return [
        'de' => $deIso, 'ate' => $ateIso, 'dias' => $dias,
        'atual' => mcp_metricas_nucleo($deIso, $ateIso, $agora),
        'anterior' => mcp_metricas_nucleo($antesDe, $deIso, $agora),
        'avisos' => mcp_metricas_avisos($deIso, $ateIso, $agora),
        'pessoas' => mcp_metricas_pessoas($deIso, $ateIso, $agora),
        'opiniao' => mcp_opinioes_resultado(),
    ];
}

/** Percentual inteiro (0–100), ou null sem base. */
function mcp_metricas_pct(int $parte, int $total): ?int
{
    return $total > 0 ? (int) round(100 * $parte / $total) : null;
}

/** Planilha dos resultados por pessoa (CSV com ; e BOM). Sem CPF, como as outras planilhas do portal. */
function mcp_metricas_csv(array $pessoas): string
{
    $f = fopen('php://temp', 'w+');
    fputcsv($f, ['Voluntário', 'Vínculo', 'Situação', 'Dias com registro', 'Horas doadas', 'Saídas a completar', 'Lembrete da véspera', 'WhatsApp autorizado', 'Tem contato'], ';', '"', '');
    foreach ($pessoas as $p) {
        fputcsv($f, array_map('mcp_horarios_celula', [
            $p['nome'], MCP_PONTO_VINCULOS[$p['vinculo']] ?? $p['vinculo'], $p['ativo'] ? 'Ativo' : 'Inativo', (string) $p['dias'],
            mcp_ponto_horas_texto($p['minutos']), (string) $p['esquecidas'], $p['lembrete'] ? 'sim' : 'não', $p['whatsapp'] ? 'sim' : 'não', $p['contato'] ? 'sim' : 'não',
        ]), ';', '"', '');
    }
    rewind($f);
    $csv = (string) stream_get_contents($f);
    fclose($f);
    return "\xEF\xBB\xBF" . $csv;
}
