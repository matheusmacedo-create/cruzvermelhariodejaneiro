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
            if ($p['saida'] !== null) {
                $segundos += max(0, (int) strtotime($p['saida'] . ' UTC') - (int) strtotime($p['entrada'] . ' UTC'));
                if ($p['origem_saida'] === 'informada') {
                    $r['informadas']++;
                }
            } elseif ($p['saida_informada'] !== null) {
                $r['informadas']++;
            } elseif ((int) strtotime($p['entrada'] . ' UTC') <= $agora - MCP_PONTO_ESQUECIDA_HORAS * 3600) {
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
 * "deram certo" (véspera: a pessoa registrou entrada no dia; saída: a saída foi informada ou corrigida;
 * aula: o aluno confirmou presença no dia).
 */
function mcp_metricas_avisos(string $deIso, string $ateIso): array
{
    [$de, $ate] = mcp_ponto_periodo_utc($deIso, $ateIso);
    $r = [];
    foreach (array_keys(MCP_AVISOS_TIPOS) as $tipo) {
        $r[$tipo] = ['preparados' => 0, 'enviados' => 0, 'falhas' => 0, 'fila' => 0, 'cancelados' => 0, 'clicados' => 0, 'email' => 0, 'whatsapp' => 0, 'convertidos' => 0, 'avaliados' => 0];
    }
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_avisos WHERE criado_em >= ? AND criado_em < ?');
    $stmt->execute([$de, $ate]);
    $entrouNoDia = mcp_db()->prepare('SELECT 1 FROM mcp_ponto WHERE colaborador_id = ? AND entrada >= ? AND entrada < ? LIMIT 1');
    $presencaNoDia = mcp_db()->prepare("SELECT 1 FROM mcp_presencas WHERE email = ? AND aula_data = ? AND status = 'valida' LIMIT 1");
    $vistos = [];
    foreach ($stmt->fetchAll() as $a) {
        $t = &$r[$a['tipo']];
        if (!isset($t)) {
            unset($t);
            continue;
        }
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
        $vistos[$chave] = true;
        $t['avaliados']++;
        $dados = json_decode((string) $a['dados'], true) ?: [];
        $ok = false;
        if ($a['tipo'] === 'vespera' && $a['colaborador_id'] && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $a['referencia'])) {
            [$d0, $d1] = mcp_ponto_periodo_utc((string) $a['referencia'], mcp_avisos_dia_mais((string) $a['referencia'], 1));
            $entrouNoDia->execute([(int) $a['colaborador_id'], $d0, $d1]);
            $ok = (bool) $entrouNoDia->fetchColumn();
        } elseif ($a['tipo'] === 'saida') {
            $reg = mcp_ponto_registro((int) ($dados['ponto'] ?? 0));
            $ok = $reg !== null && ($reg['saida'] !== null || $reg['saida_informada'] !== null);
        } elseif ($a['tipo'] === 'aula' && !empty($dados['email'])) {
            $presencaNoDia->execute([(string) $dados['email'], (string) $a['referencia']]);
            $ok = (bool) $presencaNoDia->fetchColumn();
        }
        if ($ok) {
            $t['convertidos']++;
        }
        unset($t);
    }
    return $r;
}

/**
 * Uma linha por colaborador ativo (e inativo com registro no período): dias, horas doadas, saídas
 * esquecidas, lembretes recebidos e clicados, e se respondeu a opinião (sem o conteúdo).
 */
function mcp_metricas_pessoas(string $deIso, string $ateIso, ?int $agora = null): array
{
    $agora ??= time();
    [$de, $ate] = mcp_ponto_periodo_utc($deIso, $ateIso);
    $por = [];
    foreach (mcp_ponto_registros($de, $ate) as $p) {
        $id = (int) $p['colaborador_id'];
        $por[$id] ??= ['dias' => [], 'segundos' => 0, 'esquecidas' => 0];
        $por[$id]['dias'][mcp_data_brt((string) $p['entrada'], 'Y-m-d')] = true;
        if ((int) $p['voluntario'] === 1) {
            if ($p['saida'] !== null) {
                $por[$id]['segundos'] += mcp_ponto_segundos($p);
            } elseif ($p['saida_informada'] === null && mcp_ponto_esquecido($p, $agora)) {
                $por[$id]['esquecidas']++;
            }
        }
    }
    $avisos = [];
    $stmt = mcp_db()->prepare("SELECT colaborador_id, COUNT(*) AS n, SUM(clicado_em IS NOT NULL) AS cliques FROM mcp_avisos
        WHERE colaborador_id IS NOT NULL AND status = 'enviado' AND tipo IN ('vespera', 'saida', 'campanha') AND criado_em >= ? AND criado_em < ? GROUP BY colaborador_id");
    $stmt->execute([$de, $ate]);
    foreach ($stmt->fetchAll() as $l) {
        $avisos[(int) $l['colaborador_id']] = [(int) $l['n'], (int) $l['cliques']];
    }
    $opinioes = [];
    foreach (mcp_db()->query("SELECT DISTINCT pessoa FROM mcp_opinioes WHERE pessoa LIKE 'c%'")->fetchAll(PDO::FETCH_COLUMN) as $pessoa) {
        $opinioes[(int) substr((string) $pessoa, 1)] = true;
    }
    $linhas = [];
    foreach (mcp_colaboradores_listar() as $c) {
        $id = (int) $c['id'];
        $x = $por[$id] ?? null;
        if (!(int) $c['ativo'] && $x === null) {
            continue;
        }
        $linhas[] = [
            'id' => $id, 'nome' => (string) $c['nome'], 'vinculo' => (string) $c['vinculo'], 'voluntario' => mcp_ponto_voluntario($c), 'ativo' => (bool) (int) $c['ativo'],
            'dias' => count($x['dias'] ?? []), 'minutos' => intdiv($x['segundos'] ?? 0, 60), 'esquecidas' => $x['esquecidas'] ?? 0,
            'avisos' => $avisos[$id][0] ?? 0, 'cliques' => $avisos[$id][1] ?? 0, 'opiniao' => isset($opinioes[$id]),
            'lembrete' => mcp_avisos_dias($c['aviso_dias'] ?? '') !== [], 'whatsapp' => (int) ($c['aviso_whatsapp'] ?? 0) === 1,
            'contato' => filter_var((string) $c['email'], FILTER_VALIDATE_EMAIL) !== false || mcp_whatsapp_numero((string) $c['telefone']) !== null,
        ];
    }
    usort($linhas, static fn(array $a, array $b): int => [$b['ativo'], $b['dias'], $b['minutos']] <=> [$a['ativo'], $a['dias'], $a['minutos']] ?: strcmp($a['nome'], $b['nome']));
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
        'avisos' => mcp_metricas_avisos($deIso, $ateIso),
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
    fputcsv($f, ['Colaborador', 'Vínculo', 'Situação', 'Dias com registro', 'Horas doadas', 'Saídas esquecidas', 'Avisos recebidos', 'Avisos clicados', 'Lembrete da véspera', 'WhatsApp autorizado', 'Respondeu a opinião'], ';', '"', '');
    foreach ($pessoas as $p) {
        fputcsv($f, array_map('mcp_horarios_celula', [
            $p['nome'], MCP_PONTO_VINCULOS[$p['vinculo']] ?? $p['vinculo'], $p['ativo'] ? 'Ativo' : 'Inativo', (string) $p['dias'],
            $p['voluntario'] ? mcp_ponto_horas_texto($p['minutos']) : 'só presença', (string) $p['esquecidas'], (string) $p['avisos'], (string) $p['cliques'],
            $p['lembrete'] ? 'sim' : 'não', $p['whatsapp'] ? 'sim' : 'não', $p['opiniao'] ? 'sim' : 'não',
        ]), ';', '"', '');
    }
    rewind($f);
    $csv = (string) stream_get_contents($f);
    fclose($f);
    return "\xEF\xBB\xBF" . $csv;
}
