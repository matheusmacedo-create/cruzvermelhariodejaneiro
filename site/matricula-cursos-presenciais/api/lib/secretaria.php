<?php
/**
 * Portal da secretaria (api/painel.php), 29/09/2026: as inscrições do site para a secretaria acompanhar.
 * Para cada inscrição, mostra quem pagou, o que a escola fez com a matrícula e se o aluno já disse os
 * horários. Tem busca, filtros, planilha, a ficha de cada inscrição com o histórico e o lembrete de
 * horários mandado à mão.
 *
 * A planilha não leva CPF: o arquivo costuma circular. O CPF aparece só na ficha, dentro do portal, como
 * já aparece no aviso de inscrição paga que a secretaria recebe por e-mail.
 */
declare(strict_types=1);

const MCP_SECRETARIA_FILTROS = [
    'pagas' => 'Pagas',
    'atencao' => 'Precisam de atenção',
    'sem_horarios' => 'Sem horários',
    'pendentes' => 'Aguardando pagamento',
    'todas' => 'Todas',
];
const MCP_SECRETARIA_STATUS = ['pendente' => 'Aguardando pagamento', 'pago' => 'Paga', 'recusado' => 'Recusada', 'expirado' => 'Expirada', 'estornado' => 'Estornada'];
/** Intervalo mínimo entre dois lembretes de horários ao mesmo aluno, automáticos ou à mão. */
const MCP_SECRETARIA_LEMBRETE_INTERVALO = 24 * 3600;
const MCP_SECRETARIA_ESCOLA_MOTIVOS = [
    'email_em_uso' => 'o e-mail já está em outra conta da escola, com outro CPF',
    'documento_da_equipe' => 'o CPF é de uma conta da equipe da escola',
    'aluno_bloqueado' => 'a conta do aluno está bloqueada na escola',
    'curso_inativo' => 'o curso está inativo na escola',
    'curso_inexistente' => 'o curso não foi encontrado na escola',
];

/**
 * Situação da matrícula na escola, em poucas palavras: ['rotulo', 'tom'] com tom ok, alerta, erro ou
 * neutro. Alerta e erro pedem ação da secretaria (filtro "Precisam de atenção").
 */
function mcp_secretaria_escola(array $i): array
{
    if (($i['status'] ?? '') !== 'pago') {
        return ['rotulo' => '—', 'tom' => 'neutro'];
    }
    $acesso = mcp_escola_acesso($i);
    if ($acesso) {
        if (($acesso['resultado'] ?? '') === 'sem_turma') {
            return ['rotulo' => 'Sem turma aberta', 'tom' => 'alerta'];
        }
        if (($acesso['aviso'] ?? '') === 'taxa_ja_confirmada') {
            return ['rotulo' => 'Taxa já estava paga na escola', 'tom' => 'alerta'];
        }
        $data = mcp_escola_data($acesso['turma_inicio'] ?? null);
        return ['rotulo' => 'Matriculado' . ($data !== '' ? " · turma de $data" : ''), 'tom' => 'ok'];
    }
    if (mcp_escola_erro($i) !== null) {
        return ['rotulo' => 'Não matriculado', 'tom' => 'erro'];
    }
    return match ((string) ($i['escola_status'] ?? '')) {
        'erro' => ['rotulo' => 'Falha na integração', 'tom' => 'erro'],
        'pendente' => ['rotulo' => 'Matrícula em andamento', 'tom' => 'neutro'],
        default => ['rotulo' => 'Sem integração na época', 'tom' => 'neutro'],
    };
}

/** O que a secretaria precisa fazer na escola, em uma frase (ficha da inscrição). */
function mcp_secretaria_escola_orientacao(array $i): string
{
    if (($i['status'] ?? '') !== 'pago') {
        return 'A matrícula na escola é feita sozinha quando o pagamento é confirmado.';
    }
    $acesso = mcp_escola_acesso($i);
    if ($acesso) {
        return match (true) {
            ($acesso['resultado'] ?? '') === 'sem_turma' => 'A conta do aluno está pronta na escola, mas o curso não tinha turma aberta. Matricule o aluno quando abrir turma e aplique a inscrição paga.',
            ($acesso['aviso'] ?? '') === 'taxa_ja_confirmada' => 'A taxa de inscrição já estava confirmada na escola antes deste pagamento. Avalie o estorno de uma das duas.',
            default => 'Nada a fazer: o aluno está matriculado, com a taxa confirmada.',
        };
    }
    $erro = mcp_escola_erro($i);
    if ($erro !== null) {
        return 'A escola recusou a matrícula: ' . (MCP_SECRETARIA_ESCOLA_MOTIVOS[$erro] ?? $erro) . '. Crie a matrícula à mão e aplique o valor da inscrição.';
    }
    return match ((string) ($i['escola_status'] ?? '')) {
        'erro' => 'A integração falhou por um problema técnico. Confira na escola antes de matricular à mão.',
        'pendente' => 'A matrícula na escola está sendo feita.',
        default => 'Este pagamento é de antes da integração com a escola (28/09/2026). Confira se a matrícula foi criada.',
    };
}

/** A conta do aluno na escola, quando a matrícula passou pela integração: criada pelo site ou já existente (e com qual e-mail). */
function mcp_secretaria_escola_conta(array $i): ?string
{
    $acesso = mcp_escola_acesso($i);
    if (!$acesso) {
        return null;
    }
    if (!empty($acesso['aluno_novo'])) {
        return 'Conta criada pelo site, com o e-mail da inscrição';
    }
    return 'O aluno já tinha conta na escola' . (empty($acesso['email_confere']) && !empty($acesso['email_conta'])
        ? ', com outro e-mail: ' . $acesso['email_conta'] : ', com o mesmo e-mail');
}

function mcp_secretaria_precisa_atencao(array $i): bool
{
    return ($i['status'] ?? '') === 'pago' && in_array(mcp_secretaria_escola($i)['tom'], ['alerta', 'erro'], true);
}

/** Condição SQL de cada filtro (i = mcp_inscricoes, p = mcp_preferencias). Espelha mcp_secretaria_precisa_atencao. */
function mcp_secretaria_condicao(string $filtro): string
{
    $atencao = "(i.escola_status = 'erro' OR i.escola_acesso LIKE '%\"resultado\":\"sem_turma\"%' OR i.escola_acesso LIKE '%\"aviso\":\"taxa_ja_confirmada\"%')";
    return match ($filtro) {
        'pagas' => "i.status = 'pago'",
        'atencao' => "i.status = 'pago' AND $atencao",
        'sem_horarios' => "i.status = 'pago' AND p.inscricao_id IS NULL",
        'pendentes' => "i.status = 'pendente'",
        default => '1 = 1',
    };
}

/**
 * WHERE e parâmetros para filtro, curso e busca. A busca procura por nome ou e-mail, por telefone (4 dígitos
 * ou mais) e por CPF (11 dígitos exatos).
 */
function mcp_secretaria_where(string $filtro, ?string $curso, string $busca): array
{
    $partes = [mcp_secretaria_condicao($filtro)];
    $params = [];
    if ($curso !== null && $curso !== '') {
        $partes[] = 'i.curso_slug = ?';
        $params[] = $curso;
    }
    $busca = trim($busca);
    if ($busca !== '') {
        $like = '%' . addcslashes($busca, '%_\\') . '%';
        $ou = ['i.nome LIKE ?', 'i.email LIKE ?'];
        array_push($params, $like, $like);
        $digitos = mcp_digitos($busca);
        if (strlen($digitos) >= 4) {
            $ou[] = 'i.telefone LIKE ?';
            $params[] = '%' . $digitos . '%';
        }
        if (strlen($digitos) === 11) {
            $ou[] = 'i.cpf = ?';
            $params[] = $digitos;
        }
        $partes[] = '(' . implode(' OR ', $ou) . ')';
    }
    return [implode(' AND ', $partes), $params];
}

const MCP_SECRETARIA_SELECT = "SELECT i.*, p.horarios, p.inicio, p.turma_serve, p.observacao, p.vezes, p.atualizado_em AS horarios_em,
    (SELECT COUNT(*) FROM mcp_eventos e WHERE e.inscricao_id = i.id AND e.tipo = 'lembrete_horarios') AS lembretes,
    (SELECT MAX(e.criado_em) FROM mcp_eventos e WHERE e.inscricao_id = i.id AND e.tipo = 'lembrete_horarios') AS lembrete_em
    FROM mcp_inscricoes i LEFT JOIN mcp_preferencias p ON p.inscricao_id = i.id";

/** Linha com as respostas de horários em 'preferencia' (ou null, se o aluno não respondeu). */
function mcp_secretaria_linha(array $l): array
{
    $l['preferencia'] = $l['horarios'] !== null ? mcp_horarios_linha([
        'horarios' => $l['horarios'], 'inicio' => $l['inicio'], 'turma_serve' => $l['turma_serve'], 'observacao' => $l['observacao'],
        'vezes' => $l['vezes'], 'atualizado_em' => $l['horarios_em'],
    ]) : null;
    return $l;
}

function mcp_secretaria_listar(string $filtro, ?string $curso = null, string $busca = '', int $limite = 50, int $deslocamento = 0): array
{
    [$where, $params] = mcp_secretaria_where($filtro, $curso, $busca);
    $stmt = mcp_db()->prepare(MCP_SECRETARIA_SELECT . " WHERE $where ORDER BY COALESCE(i.pago_em, i.criado_em) DESC, i.id DESC LIMIT "
        . max(1, $limite) . ' OFFSET ' . max(0, $deslocamento));
    $stmt->execute($params);
    return array_map('mcp_secretaria_linha', $stmt->fetchAll());
}

/** Quantas inscrições em cada filtro, com o mesmo curso e a mesma busca. */
function mcp_secretaria_contar(?string $curso = null, string $busca = ''): array
{
    $n = [];
    foreach (array_keys(MCP_SECRETARIA_FILTROS) as $filtro) {
        [$where, $params] = mcp_secretaria_where($filtro, $curso, $busca);
        $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_inscricoes i LEFT JOIN mcp_preferencias p ON p.inscricao_id = i.id WHERE $where");
        $stmt->execute($params);
        $n[$filtro] = (int) $stmt->fetchColumn();
    }
    return $n;
}

/** Pagas nos últimos $dias dias. */
function mcp_secretaria_pagas_recentes(int $dias = 7): int
{
    $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_inscricoes WHERE status = 'pago' AND pago_em >= ?");
    $stmt->execute([gmdate('Y-m-d H:i:s', time() - $dias * 86400)]);
    return (int) $stmt->fetchColumn();
}

/** Cursos que têm inscrição, para o filtro: slug => nome. */
function mcp_secretaria_cursos(): array
{
    $cursos = [];
    foreach (mcp_db()->query('SELECT curso_slug, MAX(curso_nome) AS curso_nome FROM mcp_inscricoes GROUP BY curso_slug ORDER BY curso_nome')->fetchAll() as $l) {
        $cursos[(string) $l['curso_slug']] = (string) $l['curso_nome'];
    }
    return $cursos;
}

function mcp_secretaria_inscricao(int $id): ?array
{
    $stmt = mcp_db()->prepare(MCP_SECRETARIA_SELECT . ' WHERE i.id = ?');
    $stmt->execute([$id]);
    $linha = $stmt->fetch();
    return $linha ? mcp_secretaria_linha($linha) : null;
}

function mcp_secretaria_eventos(int $inscricaoId): array
{
    $stmt = mcp_db()->prepare('SELECT tipo, detalhe, criado_em FROM mcp_eventos WHERE inscricao_id = ? ORDER BY criado_em, id');
    $stmt->execute([$inscricaoId]);
    return $stmt->fetchAll();
}

/**
 * O evento em linguagem da secretaria, para o histórico da ficha. Devolve null para o que não interessa
 * a ela; o detalhe técnico só aparece quando ajuda, e nunca IP nem dado de cartão.
 */
function mcp_secretaria_evento(string $tipo, ?string $detalhe): ?string
{
    $d = (string) $detalhe;
    $partes = array_map('trim', explode('·', $d));
    $falhou = $d === 'falhou' || str_contains($d, '· falhou') || str_contains($d, ' falhou ·');
    $envio = static fn(string $texto): string => $texto . ($falhou ? ' (o envio falhou)' : '');
    switch ($tipo) {
        case 'cobranca_criada':
            $metodo = ($partes[0] ?? '') === 'pix' ? 'PIX' : 'cartão';
            return 'Cobrança criada: ' . $metodo . (isset($partes[2]) ? ', ' . $partes[2] : '');
        case 'pix_reaproveitado':
            return 'O aluno voltou e recebeu o mesmo PIX, ainda válido';
        case 'email_pix':
            return $envio('E-mail com o código PIX enviado ao aluno');
        case 'pago':
            return 'Pagamento confirmado';
        case 'recusado':
            return 'Pagamento recusado';
        case 'expirado':
            return 'A cobrança venceu sem pagamento';
        case 'estornado':
            return 'Pagamento estornado';
        case 'postback':
            return 'Aviso de pagamento recebido da Unicopag';
        case 'consulta_falhou':
            return 'A consulta à Unicopag falhou (o site tenta de novo)';
        case 'escola_ok':
            $resultado = $partes[1] ?? '';
            return ($resultado === 'sem_turma' ? 'Escola: conta pronta, mas sem turma aberta' : 'Escola: matrícula feita')
                . (in_array('taxa_ja_confirmada', $partes, true) ? ' (a taxa já estava confirmada)' : '')
                . (in_array('repetido', $partes, true) ? ' (confirmação repetida)' : '');
        case 'escola_erro':
            $motivo = '';
            foreach ($partes as $p) {
                if (isset(MCP_SECRETARIA_ESCOLA_MOTIVOS[$p])) {
                    $motivo = MCP_SECRETARIA_ESCOLA_MOTIVOS[$p];
                }
            }
            return 'Escola: a matrícula não foi feita' . ($motivo !== '' ? " ($motivo)" : ' (' . implode(' · ', array_slice($partes, 1)) . ')');
        case 'email_aluno':
            return $envio('E-mail de inscrição paga enviado ao aluno' . (str_contains($d, 'com comprovante') ? ', com o comprovante em PDF' : ''));
        case 'email_secretaria':
            return $envio('Aviso de inscrição paga enviado à secretaria');
        case 'horarios':
            return 'O aluno salvou os horários';
        case 'email_horarios':
            return $envio('Aviso dos horários enviado à secretaria');
        case 'lembrete_horarios':
            return 'Lembrete de horários enviado ao aluno' . (preg_match('/manual (\S+)/', $d, $m) ? ', à mão, por ' . $m[1] : '');
        case 'lembrete_horarios_falhou':
            return 'Um lembrete de horários não foi enviado (falha no envio)';
        default:
            return null;
    }
}

/**
 * Lembrete de horários mandado à mão pela secretaria. Devolve 'enviado', 'respondido', 'nao_pago',
 * 'recente' (houve lembrete há menos de 24 h) ou 'falhou'. Conta junto com os automáticos: o cron vê
 * este lembrete e espera antes do próximo. Usa a mesma trava do cron (api/lembretes.php): um clique
 * duplo ou a rodada da hora não mandam dois e-mails ao mesmo aluno.
 */
function mcp_secretaria_lembrete(array $i, string $quem, ?int $agora = null): string
{
    $agora ??= time();
    if (($i['status'] ?? '') !== 'pago') {
        return 'nao_pago';
    }
    $db = mcp_db();
    if ((int) $db->query("SELECT GET_LOCK('mcp_lembretes_horarios', 10)")->fetchColumn() !== 1) {
        return 'falhou';
    }
    try {
        if (mcp_horarios_por_inscricao((int) $i['id']) !== null) {
            return 'respondido';
        }
        $stmt = $db->prepare("SELECT COUNT(*) AS n, MAX(criado_em) AS ultimo FROM mcp_eventos WHERE inscricao_id = ? AND tipo = 'lembrete_horarios'");
        $stmt->execute([(int) $i['id']]);
        $ja = $stmt->fetch();
        if (!empty($ja['ultimo']) && $agora - (int) strtotime($ja['ultimo'] . ' UTC') < MCP_SECRETARIA_LEMBRETE_INTERVALO) {
            return 'recente';
        }
        $numero = min((int) $ja['n'] + 1, count(MCP_HORARIOS_LEMBRETES_HORAS));
        $m = mcp_montar_email_lembrete_horarios($i, $numero);
        $envio = mcp_enviar_email((string) $i['email'], $m['assunto'], $m['html'], $m['texto']);
        if ($envio === 'falhou') {
            mcp_registrar((int) $i['id'], 'lembrete_horarios_falhou', "#$numero manual $quem");
            return 'falhou';
        }
        mcp_registrar((int) $i['id'], 'lembrete_horarios', "#$numero manual $quem · $envio");
        return 'enviado';
    } finally {
        $db->query("SELECT RELEASE_LOCK('mcp_lembretes_horarios')");
    }
}

/** Planilha das inscrições (CSV com ; e BOM). Sem CPF, de propósito. */
function mcp_secretaria_csv(array $linhas): string
{
    $f = fopen('php://temp', 'w+');
    fputcsv($f, ['Data (Brasília)', 'Situação', 'Nome', 'E-mail', 'Telefone', 'Curso', 'Valor', 'Método', 'Escola', 'Horários', 'Começo', 'Recado'], ';', '"', '');
    foreach ($linhas as $l) {
        $p = $l['preferencia'] ?? null;
        fputcsv($f, array_map('mcp_horarios_celula', [
            mcp_data_brt((string) ($l['pago_em'] ?: $l['criado_em']), 'd/m/Y H:i'),
            MCP_SECRETARIA_STATUS[$l['status']] ?? $l['status'],
            $l['nome'], $l['email'], mcp_telefone_bonito((string) $l['telefone']), $l['curso_nome'],
            mcp_brl((int) $l['total_centavos']), $l['metodo'] === 'pix' ? 'PIX' : 'Cartão',
            mcp_secretaria_escola($l)['rotulo'],
            $p ? mcp_horarios_texto($p['horarios']) : ($l['status'] === 'pago' ? 'não respondeu' : ''),
            $p ? (MCP_HORARIOS_INICIO[$p['inicio']] ?? $p['inicio']) : '',
            $p ? (string) $p['observacao'] : '',
        ]), ';', '"', '');
    }
    rewind($f);
    $csv = (string) stream_get_contents($f);
    fclose($f);
    return "\xEF\xBB\xBF" . $csv;
}
