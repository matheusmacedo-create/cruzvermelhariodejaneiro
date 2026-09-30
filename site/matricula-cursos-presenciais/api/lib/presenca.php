<?php
/**
 * Presença nas aulas presenciais e comprovante de comparecimento (29/09/2026).
 *
 * O aluno registra a chegada no ponto da sede (lib/ponto.php) com o CPF. O site pergunta à escola
 * quais aulas ele tem hoje (public.aulas_do_aluno, em docs/escola/aulas_do_aluno.sql). O dia e o
 * horário vêm da "AulaData" da turma, e o site guarda a presença com uma cópia desses dados.
 *
 * Quando a aula termina, o comprovante em PDF fica disponível no link pessoal (comparecimento/?t=), e
 * a rotina api/comparecimentos.php manda o e-mail com o PDF anexo. Só quem registrou presença recebe:
 * o documento só declara o que aconteceu.
 *
 * A secretaria pode cancelar no portal uma presença registrada por engano. A partir daí, o código de
 * verificação (conferir/) diz que o documento foi cancelado.
 */
declare(strict_types=1);

/** Dá para registrar a chegada a partir de 3 horas antes do início da aula. */
const MCP_PRESENCA_ANTES_MINUTOS = 180;
/** A rotina manda comprovantes de aulas dos últimos 30 dias (se ficou parada, recupera). */
const MCP_PRESENCA_ENVIO_DIAS = 30;
const MCP_PRESENCA_ENVIO_TENTATIVAS = 5;
const MCP_PRESENCA_LOCAL = 'Praça da Cruz Vermelha, 10, Centro, Rio de Janeiro/RJ';

// ----------------------------------------------------------------------------- escola
/** URL de outra função do banco da escola, no mesmo endereço da matricula_rapida. */
function mcp_escola_url_funcao(string $funcao): string
{
    $base = (string) mcp_cfg('ESCOLA_API_URL', '');
    return preg_match('~^(https?://.+/rpc/)[A-Za-z0-9_]+/?$~', $base, $m) ? $m[1] . $funcao : '';
}

/**
 * Aulas do aluno num dia, pela escola.
 * @return array{ok: bool, aluno?: ?array{nome: string, email: string}, aulas?: list<array>, erro?: string}
 */
function mcp_escola_aulas(string $cpf, string $dataIso): array
{
    $url = (string) mcp_cfg('ESCOLA_API_AULAS_URL', '') ?: mcp_escola_url_funcao('aulas_do_aluno');
    if ($url === '') {
        return ['ok' => false, 'erro' => 'sem integração com a escola'];
    }
    $chave = (string) mcp_cfg('ESCOLA_API_TOKEN', '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['dados' => ['cpf' => $cpf, 'data' => $dataIso]]),
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'apikey: ' . $chave, 'Authorization: Bearer ' . $chave],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        // HTTPS sempre; HTTP só para o teste local contra um servidor em 127.0.0.1.
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true) ? CURLPROTO_HTTP : 0),
    ]);
    $resposta = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);
    $dados = is_string($resposta) ? json_decode($resposta, true) : null;
    if ($status !== 200 || !is_array($dados) || ($dados['ok'] ?? null) !== true || !is_array($dados['aulas'] ?? null)) {
        // Só o status e o código de erro: nem CPF nem chave vão para o log.
        error_log('[matricula] aulas_do_aluno: HTTP ' . $status . ($erroCurl !== '' ? " · $erroCurl" : '') . (is_array($dados) && isset($dados['code']) ? ' · ' . $dados['code'] : ''));
        return ['ok' => false, 'erro' => $status > 0 ? "HTTP $status" : 'sem resposta'];
    }
    $aulas = [];
    foreach ($dados['aulas'] as $a) {
        if (!is_array($a) || !is_string($a['aula_id'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($a['data'] ?? ''))) {
            continue;
        }
        $aulas[] = [
            'id' => mb_substr($a['aula_id'], 0, 64), 'data' => (string) $a['data'], 'horario' => mcp_texto($a['horario'] ?? '', 60),
            'turma' => mb_substr((string) ($a['turma_id'] ?? ''), 0, 64), 'curso_id' => mb_substr((string) ($a['curso_id'] ?? ''), 0, 64),
            'curso' => mcp_texto($a['curso_nome'] ?? '', 160) ?: 'Curso presencial',
        ];
    }
    $aluno = is_array($dados['aluno'] ?? null) && $aulas
        ? ['nome' => mcp_texto($dados['aluno']['nome'] ?? '', 160), 'email' => mb_strtolower(mcp_texto($dados['aluno']['email'] ?? '', 190))]
        : null;
    return ['ok' => true, 'aluno' => $aluno, 'aulas' => $aluno ? $aulas : []];
}

// ----------------------------------------------------------------------------- horário da aula
/**
 * Início e fim da aula em UTC, a partir do horário escrito pela escola: "18:00 - 22:00",
 * "9h às 12h" ou "18h30-22h". Sem dois horários legíveis, a aula vale o dia inteiro: fica sem início
 * e termina às 23:59.
 * @return array{inicio: ?string, fim: string, texto: string, hora_inicio: ?string, hora_fim: ?string}
 */
function mcp_presenca_horario(string $horario, string $dataIso): array
{
    preg_match_all('/(?<![\d:])([01]?\d|2[0-3])\s*(?::\s*([0-5]\d)|h\s*([0-5]\d)?)/iu', $horario, $m, PREG_SET_ORDER);
    if (count($m) >= 2) {
        $hora = static fn(array $x): string => str_pad($x[1], 2, '0', STR_PAD_LEFT) . ':' . (($x[2] ?? '') !== '' ? $x[2] : (($x[3] ?? '') !== '' ? $x[3] : '00'));
        [$ini, $fim] = [$hora($m[0]), $hora($m[1])];
        $inicioUtc = mcp_ponto_local_para_utc("$dataIso $ini");
        $fimUtc = mcp_ponto_local_para_utc("$dataIso $fim");
        if ($inicioUtc !== null && $fimUtc !== null && $fimUtc > $inicioUtc) {
            return ['inicio' => $inicioUtc, 'fim' => $fimUtc, 'texto' => "das $ini às $fim", 'hora_inicio' => $ini, 'hora_fim' => $fim];
        }
    }
    return ['inicio' => null, 'fim' => (string) mcp_ponto_local_para_utc("$dataIso 23:59:59"), 'texto' => trim($horario) !== '' ? trim($horario) : 'horário não informado',
        'hora_inicio' => null, 'hora_fim' => null];
}

/**
 * Se dá para registrar a presença nesta aula agora.
 * @return array{pode: bool, motivo: ?string}
 */
function mcp_presenca_janela(array $aula, int $agora): array
{
    $h = mcp_presenca_horario((string) $aula['horario'], (string) $aula['data']);
    if ($agora > (int) strtotime($h['fim'] . ' UTC')) {
        return ['pode' => false, 'motivo' => $h['hora_fim'] !== null
            ? "Esta aula terminou às {$h['hora_fim']}. Se você veio, fale com a secretaria."
            : 'Esta aula já terminou. Se você veio, fale com a secretaria.'];
    }
    if ($h['inicio'] !== null && $agora < (int) strtotime($h['inicio'] . ' UTC') - MCP_PRESENCA_ANTES_MINUTOS * 60) {
        return ['pode' => false, 'motivo' => "Sua aula começa às {$h['hora_inicio']}. Registre a chegada quando vier para a aula."];
    }
    return ['pode' => true, 'motivo' => null];
}

// ----------------------------------------------------------------------------- presenças
function mcp_presenca_por(string $coluna, string $valor): ?array
{
    if (!in_array($coluna, ['id', 'token', 'codigo'], true)) {
        throw new InvalidArgumentException("coluna de busca não permitida: $coluna");
    }
    $stmt = mcp_db()->prepare("SELECT * FROM mcp_presencas WHERE $coluna = ?");
    $stmt->execute([$valor]);
    return $stmt->fetch() ?: null;
}

function mcp_presenca_da_aula(string $cpf, string $aulaId): ?array
{
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_presencas WHERE cpf = ? AND aula_id = ?');
    $stmt->execute([$cpf, $aulaId]);
    return $stmt->fetch() ?: null;
}

/**
 * Grava a presença: uma por aluno e aula, com a cópia dos dados da escola.
 * @return array{presenca: array, nova: bool}
 */
function mcp_presenca_registrar(array $aluno, array $aula, string $cpf, string $origem, ?int $aparelhoId, ?int $distancia, ?int $agora = null): array
{
    if ($existente = mcp_presenca_da_aula($cpf, (string) $aula['id'])) {
        return ['presenca' => $existente, 'nova' => false];
    }
    $agora ??= time();
    $h = mcp_presenca_horario((string) $aula['horario'], (string) $aula['data']);
    $quando = gmdate('Y-m-d H:i:s', $agora);
    // INSERT IGNORE: dois toques ao mesmo tempo gravam uma presença só (chave cpf + aula).
    mcp_db()->prepare('INSERT IGNORE INTO mcp_presencas (token, codigo, cpf, nome, email, aula_id, turma_id, curso_id, curso_nome, aula_data, horario,
        inicio, fim, chegada, origem, aparelho_id, distancia, status, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([mcp_token_novo(), mcp_codigo_novo(), $cpf, $aluno['nome'], $aluno['email'] !== '' ? $aluno['email'] : null, $aula['id'],
            $aula['turma'] ?: null, $aula['curso_id'] ?: null, $aula['curso'], $aula['data'], $aula['horario'], $h['inicio'], $h['fim'],
            $quando, $origem, $aparelhoId, $distancia, 'valida', $quando]);
    return ['presenca' => (array) mcp_presenca_da_aula($cpf, (string) $aula['id']), 'nova' => true];
}

/** O comprovante sai quando a aula termina, e só de presença válida. */
function mcp_presenca_disponivel(array $p, ?int $agora = null): bool
{
    return $p['status'] === 'valida' && ($agora ?? time()) >= (int) strtotime($p['fim'] . ' UTC');
}

/** Horário da aula para mostrar ("das 18:00 às 22:00", ou o texto da escola). */
function mcp_presenca_horario_texto(array $p): string
{
    return mcp_presenca_horario((string) $p['horario'], (string) $p['aula_data'])['texto'];
}

/** O que a página do comprovante mostra (link pessoal, sem CPF). */
function mcp_presenca_publico(array $p, ?int $agora = null): array
{
    $disponivel = mcp_presenca_disponivel($p, $agora);
    return [
        'nome' => mcp_nome_proprio((string) $p['nome']),
        'curso' => (string) $p['curso_nome'],
        'data' => mcp_escola_data((string) $p['aula_data']),
        'dia_semana' => mcp_dia_semana((string) $p['aula_data']),
        'horario' => mcp_presenca_horario_texto($p),
        'chegada' => mcp_data_brt((string) $p['chegada'], 'H:i'),
        'status' => (string) $p['status'],
        'disponivel' => $disponivel,
        'disponivel_em' => mcp_data_brt((string) $p['fim'], 'd/m \à\s H:i'),
        'pdf' => $disponivel ? mcp_site_url() . '/matricula-cursos-presenciais/api/comparecimento.php?t=' . rawurlencode((string) $p['token']) . '&pdf=1' : null,
        'codigo' => $disponivel ? mcp_codigo_formatado((string) $p['codigo']) : null,
        'conferir' => $disponivel ? mcp_conferir_url((string) $p['codigo']) : null,
        'email' => mcp_email_mascarado((string) $p['email']),
        'enviado' => $p['email_em'] !== null,
    ];
}

function mcp_presencas_listar(string $deIso, string $ateIso): array
{
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_presencas WHERE aula_data >= ? AND aula_data <= ? ORDER BY aula_data DESC, chegada DESC, id DESC');
    $stmt->execute([$deIso, $ateIso]);
    return $stmt->fetchAll();
}

function mcp_presenca_cancelar(int $id, string $quem): bool
{
    $stmt = mcp_db()->prepare("UPDATE mcp_presencas SET status = 'cancelada', cancelada_por = ?, cancelada_em = ? WHERE id = ? AND status = 'valida'");
    $stmt->execute([$quem, mcp_agora(), $id]);
    if ($stmt->rowCount() > 0) {
        mcp_registrar(null, 'presenca_cancelada', "#$id · $quem");
        return true;
    }
    return false;
}

// ----------------------------------------------------------------------------- comprovante
/** Conteúdo do comprovante de comparecimento (sem desenho): é o que os testes conferem. */
function mcp_presenca_conteudo(array $p, ?string $agoraUtc = null): array
{
    $nome = mcp_nome_proprio((string) $p['nome']);
    $cpf = mcp_cpf_formatado((string) $p['cpf']);
    $curso = (string) $p['curso_nome'];
    $dataIso = (string) $p['aula_data'];
    $data = mcp_data_extenso($dataIso) . ' (' . mcp_dia_semana($dataIso) . ')';
    $horario = mcp_presenca_horario_texto($p);
    $codigo = mcp_codigo_formatado((string) $p['codigo']);
    $emitido = mcp_data_brt($agoraUtc ?? gmdate('Y-m-d H:i:s'), 'd/m/Y \à\s H\hi');
    $registro = mcp_data_brt((string) $p['chegada'], 'H:i') . ($p['origem'] === 'celular' ? ', pelo celular, na sede' : ', no ponto da sede');
    return [
        'titulo' => 'COMPROVANTE DE COMPARECIMENTO',
        'subtitulo' => "$curso — aula presencial",
        'assunto' => "Comprovante de comparecimento de $nome à aula de $curso",
        'rotulo_pessoa' => 'ALUNO',
        'nome' => $nome,
        'cpf' => "CPF: $cpf",
        'texto' => "Declaramos, para os devidos fins, que $nome, CPF $cpf, compareceu à aula presencial do curso $curso, da "
            . "Escola de Educação e Saúde da " . MCP_NOME_FILIAL . ", no dia $data, $horario, na sede da instituição, "
            . 'na ' . MCP_PRESENCA_LOCAL . '.',
        'secao' => 'DADOS DA AULA',
        'linhas' => [
            ['Curso', $curso],
            ['Data da aula', mcp_escola_data($dataIso) . ' (' . mcp_dia_semana($dataIso) . ')'],
            ['Horário da aula', $horario],
            ['Chegada registrada', $registro],
            ['Local', MCP_PRESENCA_LOCAL],
        ],
        'local_data' => 'Rio de Janeiro, ' . mcp_data_extenso($dataIso) . '.',
        'assinatura' => ['Secretaria da Escola de Educação e Saúde', MCP_NOME_FILIAL],
        'codigo' => $codigo,
        'conferir' => mcp_conferir_url((string) $p['codigo']),
        'rodape' => "Comprovante emitido eletronicamente em $emitido (horário de Brasília), a partir do registro de chegada no ponto da sede. "
            . 'Confira a autenticidade em ' . mcp_conferir_url_curta() . " com o código $codigo. Dúvidas: " . mcp_email_contato_endereco() . '.',
    ];
}

function mcp_presenca_pdf(array $p, ?string $agoraUtc = null): string
{
    return mcp_declaracao_pdf(mcp_presenca_conteudo($p, $agoraUtc));
}

/** "Comprovante_de_Comparecimento_Bombeiro_Civil_2026-10-22.pdf" */
function mcp_presenca_arquivo(array $p): string
{
    return mcp_nome_arquivo('Comprovante de Comparecimento ' . $p['curso_nome'] . ' ' . $p['aula_data']);
}

function mcp_presenca_link(array $p): string
{
    return mcp_url_pagina('comparecimento', (string) $p['token']);
}

/** E-mail do comprovante: sai depois do fim da aula, com o PDF anexo e o link para baixar de novo. */
function mcp_montar_email_comparecimento(array $p): array
{
    $primeiro = mcp_escapar(mcp_primeiro_nome(mcp_nome_proprio((string) $p['nome'])));
    $curso = (string) $p['curso_nome'];
    $data = mcp_escola_data((string) $p['aula_data']);
    $horario = mcp_presenca_horario_texto($p);
    $link = mcp_presenca_link($p);
    $codigo = mcp_codigo_formatado((string) $p['codigo']);
    $conferir = mcp_conferir_url((string) $p['codigo']);
    $corpo = mcp_p("Olá, $primeiro! Obrigado por vir à aula de <strong>" . mcp_escapar($curso) . '</strong> no dia ' . mcp_escapar("$data, $horario") . '.')
        . mcp_p('Seu comprovante de comparecimento está anexado a este e-mail, em PDF. Se precisar, baixe de novo por aqui:')
        . mcp_botao($link, 'Baixar meu comprovante')
        . mcp_p('Quem receber o comprovante pode conferir se ele é verdadeiro em <a href="' . mcp_escapar($conferir) . '">' . mcp_escapar(mcp_conferir_url_curta())
            . '</a>, com o código <strong>' . mcp_escapar($codigo) . '</strong>.');
    $texto = "Olá! Obrigado por vir à aula de $curso no dia $data, $horario.\n\n"
        . "Seu comprovante de comparecimento está anexado a este e-mail, em PDF. Para baixar de novo: $link\n\n"
        . 'Quem receber o comprovante pode conferir se ele é verdadeiro em ' . mcp_conferir_url_curta() . ", com o código $codigo.";
    return [
        'assunto' => "Seu comprovante de comparecimento: $curso, $data",
        'html' => mcp_moldura('Seu comprovante de comparecimento', $corpo, [
            'eyebrow' => 'Escola de Educação e Saúde',
            'preheader' => "O comprovante da aula de $curso ($data) está anexado.",
            'motivo' => 'Você recebeu este e-mail porque registrou presença numa aula presencial na sede da ' . MCP_NOME_FILIAL . '.',
        ]),
        'texto' => $texto,
    ];
}

/** Manda o e-mail do comprovante e anota o resultado. Devolve resend, mail ou falhou. */
function mcp_presenca_enviar(array $p): string
{
    $m = mcp_montar_email_comparecimento($p);
    $anexos = [];
    try {
        $anexos[] = ['nome' => mcp_presenca_arquivo($p), 'conteudo' => mcp_presenca_pdf($p), 'tipo' => 'application/pdf'];
    } catch (Throwable $e) {
        error_log('[matricula] comprovante de comparecimento sem PDF: ' . $e->getMessage());
    }
    $r = mcp_enviar_email((string) $p['email'], $m['assunto'], $m['html'], $m['texto'], null, null, $anexos);
    if ($r === 'falhou') {
        mcp_db()->prepare("UPDATE mcp_presencas SET email_status = 'falhou', email_tentativas = email_tentativas + 1 WHERE id = ?")->execute([(int) $p['id']]);
    } else {
        mcp_db()->prepare('UPDATE mcp_presencas SET email_status = ?, email_tentativas = email_tentativas + 1, email_em = ? WHERE id = ?')->execute([$r, mcp_agora(), (int) $p['id']]);
    }
    return $r;
}

/**
 * Rotina (api/comparecimentos.php): manda o comprovante das aulas que já terminaram e ainda não foram
 * enviadas. Cada presença tenta até 5 vezes.
 * @return array{enviados: int, falhas: int, vistos: int}
 */
function mcp_presencas_enviar_pendentes(?int $agora = null): array
{
    $agora ??= time();
    $stmt = mcp_db()->prepare("SELECT * FROM mcp_presencas WHERE status = 'valida' AND email_em IS NULL AND email IS NOT NULL
        AND email_tentativas < ? AND fim <= ? AND fim >= ? ORDER BY fim, id LIMIT 200");
    $stmt->execute([MCP_PRESENCA_ENVIO_TENTATIVAS, gmdate('Y-m-d H:i:s', $agora), gmdate('Y-m-d H:i:s', $agora - MCP_PRESENCA_ENVIO_DIAS * 86400)]);
    $r = ['enviados' => 0, 'falhas' => 0, 'vistos' => 0];
    foreach ($stmt->fetchAll() as $p) {
        $r['vistos']++;
        mcp_presenca_enviar($p) === 'falhou' ? $r['falhas']++ : $r['enviados']++;
    }
    return $r;
}

// ----------------------------------------------------------------------------- conferência (conferir/)
/**
 * O que a página de conferência mostra para um código: o documento, a pessoa (CPF mascarado) e se
 * ainda vale. null se o código não existe (ou o comprovante ainda não foi emitido).
 */
function mcp_conferir(string $codigo, ?int $agora = null): ?array
{
    $p = mcp_presenca_por('codigo', $codigo);
    if ($p && ($p['status'] !== 'valida' || mcp_presenca_disponivel($p, $agora))) {
        return [
            'tipo' => 'Comprovante de comparecimento',
            'valido' => $p['status'] === 'valida',
            'situacao' => $p['status'] === 'valida' ? 'Documento válido' : 'Documento cancelado pela secretaria em ' . mcp_data_brt((string) $p['cancelada_em'], 'd/m/Y'),
            'nome' => mcp_nome_proprio((string) $p['nome']),
            'cpf' => mcp_cpf_mascarado((string) $p['cpf']),
            'codigo' => mcp_codigo_formatado($codigo),
            'linhas' => [
                ['Curso', (string) $p['curso_nome']],
                ['Aula', mcp_escola_data((string) $p['aula_data']) . ', ' . mcp_presenca_horario_texto($p)],
                ['Chegada registrada', mcp_data_brt((string) $p['chegada'], 'd/m/Y \à\s H:i')],
                ['Local', 'Sede da ' . MCP_NOME_FILIAL],
            ],
        ];
    }
    $d = mcp_ponto_declaracao_por_codigo($codigo);
    if ($d) {
        return [
            'tipo' => 'Declaração de horas voluntárias',
            'valido' => true,
            'situacao' => 'Documento válido',
            'nome' => mcp_nome_proprio((string) $d['nome']),
            'cpf' => mcp_cpf_mascarado((string) $d['cpf']),
            'codigo' => mcp_codigo_formatado($codigo),
            'linhas' => array_values(array_filter([
                ['Período', mcp_escola_data((string) $d['de']) . ($d['de'] !== $d['ate'] ? ' a ' . mcp_escola_data((string) $d['ate']) : '')],
                trim((string) $d['funcao']) !== '' ? ['Função', (string) $d['funcao']] : null,
                ['Total de horas', mcp_ponto_horas_texto((int) $d['minutos']) . ' em ' . (int) $d['dias'] . ((int) $d['dias'] === 1 ? ' dia' : ' dias')],
                !empty($d['termo_em']) ? ['Termo de adesão', 'assinado em ' . mcp_escola_data((string) $d['termo_em']) . ' (Lei nº 9.608/1998)'] : null,
                ['Emitida em', mcp_data_brt((string) $d['emitida_em'], 'd/m/Y')],
            ])),
        ];
    }
    return null;
}
