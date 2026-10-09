<?php
/**
 * Pagar tudo (10/2026): o que cada curso oferece e quanto custa.
 *
 *  - oferta.json (turmas abertas, prazo, lotação, venda sem turma), com os rótulos no horário de Brasília;
 *  - planos de cada curso: "Taxa de inscrição + matrícula" (taxa_e_matricula) e "Só a taxa de inscrição" (so_taxa),
 *    com o motivo quando a opção 1 não aparece (spec 2.1 e 10.8);
 *  - valores, sempre do servidor (2.2): taxa, matrícula, opcionais e o amount, sem juros;
 *  - parcelas no cartão: a simulação da Unicopag (GET /public/v1/installments), o cache de 10 minutos, os filtros, a
 *    taxa ao mês (TIR das parcelas reais) e o CET, e o histórico do que foi mostrado (validade de 48 h, 2.4);
 *  - a cobrança (cart com taxa e matrícula separadas, uma cobrança só: decisão 9 do dono);
 *  - a visão pública da compra (o que status.php e pagamentos.php acrescentam a mcp_publico()).
 *
 * As chaves de liga e desliga começam desligadas e são lidas com mcp_cfg_ligada() (=== true). Desligado, nada aqui
 * chama a escola nem a Unicopag: os cursos oferecem só a taxa, como antes.
 */
declare(strict_types=1);

const MCP_PLANOS = ['taxa_e_matricula', 'so_taxa'];
const MCP_ESCOLA_VERSAO_CACHE = 600;        // resposta boa da escola: 10 minutos
const MCP_ESCOLA_VERSAO_CACHE_FALHA = 60;   // erro ou versão 0: 1 minuto (falha fechada, sem martelar a escola)
const MCP_PARCELAS_CACHE_SEGUNDOS = 600;
const MCP_PARCELAS_VALIDADE_SEGUNDOS = 48 * 3600;
const MCP_PARCELAS_TEMPO = 8;               // segundos de espera pela simulação da Unicopag
const MCP_COMPRA_REPETIDA_DIAS = 180;
const MCP_FILA_MAXIMA_PADRAO = 30;
const MCP_COMPRA_MESES = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
const MCP_COMPRA_DIAS_SEMANA = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];

function mcp_fuso_brt(): DateTimeZone
{
    static $fuso = null;
    return $fuso ??= new DateTimeZone('America/Sao_Paulo');
}

/** Instante (timestamp) de um texto ISO com fuso, ou null. */
function mcp_iso_ts(mixed $iso): ?int
{
    if (!is_string($iso) || $iso === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($iso))->getTimestamp();
    } catch (Throwable) {
        return null;
    }
}

/** Data/hora UTC do banco ("Y-m-d H:i:s") como timestamp, ou null. */
function mcp_utc_ts(mixed $utc): ?int
{
    if (!is_string($utc) || $utc === '') {
        return null;
    }
    $ts = strtotime($utc . ' UTC');
    return $ts === false ? null : $ts;
}

// ----------------------------------------------------------------------------- oferta.json
/**
 * Turmas e venda sem turma, mantidas à mão pela TI (D9): site/matricula-cursos-presenciais/oferta.json. Nos testes, o
 * caminho vem de MCP_OFERTA_ARQUIVO. Arquivo ausente ou inválido: nenhuma turma, nada sem turma (e error_log).
 */
function mcp_oferta(): array
{
    static $cache = [];
    $arquivo = getenv('MCP_OFERTA_ARQUIVO') ?: dirname(__DIR__, 2) . '/oferta.json';
    $marca = $arquivo . '|' . (is_file($arquivo) ? (string) filemtime($arquivo) : 'ausente');
    if (isset($cache[$marca])) {
        return $cache[$marca];
    }
    $padrao = ['parcelado_no_ar' => false, 'turmas' => [], 'sem_turma' => []];
    $dados = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;
    if (!is_array($dados)) {
        error_log('[matricula] oferta.json ausente ou inválido: nenhuma turma aberta');
        return $cache[$marca] = $padrao;
    }
    $turmas = [];
    foreach (is_array($dados['turmas'] ?? null) ? $dados['turmas'] : [] as $t) {
        if (!is_array($t) || !is_string($t['curso'] ?? null) || !is_string($t['id_escola'] ?? null)
            || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $t['id_escola'])
            || mcp_iso_ts($t['inicio'] ?? null) === null || mcp_iso_ts($t['inscricoes_ate'] ?? null) === null) {
            error_log('[matricula] oferta.json: turma ignorada (faltam curso, id_escola, inicio ou inscricoes_ate)');
            continue;
        }
        $turmas[] = [
            'curso' => $t['curso'], 'id_escola' => $t['id_escola'], 'inicio' => (string) $t['inicio'],
            'fim' => mcp_iso_ts($t['fim'] ?? null) !== null ? (string) $t['fim'] : null,
            'inscricoes_ate' => (string) $t['inscricoes_ate'], 'lotada' => ($t['lotada'] ?? false) === true,
        ];
    }
    $semTurma = [];
    $lista = $dados['sem_turma'] ?? [];
    foreach (is_array($lista) ? $lista : [] as $chave => $valor) {
        // {"slug": {"max_fila": 30}} ou ["slug", ...] (fila máxima padrão)
        $slug = is_string($chave) ? $chave : (is_string($valor) ? $valor : null);
        if ($slug === null || !preg_match('/^[a-z0-9-]{1,80}$/', $slug)) {
            continue;
        }
        $max = is_array($valor) && isset($valor['max_fila']) && is_numeric($valor['max_fila']) ? (int) $valor['max_fila'] : MCP_FILA_MAXIMA_PADRAO;
        $semTurma[$slug] = ['max_fila' => max(0, $max)];
    }
    return $cache[$marca] = ['parcelado_no_ar' => ($dados['parcelado_no_ar'] ?? false) === true, 'turmas' => $turmas, 'sem_turma' => $semTurma];
}

/** "terça, 20/10" de um instante, em Brasília. */
function mcp_dia_semana_rotulo(int $ts): string
{
    $d = (new DateTimeImmutable('@' . $ts))->setTimezone(mcp_fuso_brt());
    return MCP_COMPRA_DIAS_SEMANA[(int) $d->format('w')] . ', ' . $d->format('d/m');
}

/**
 * A turma com os rótulos da 2.5, no horário de Brasília: "09:00 - 17:00", "21 de outubro de 2026", "21/10/2026",
 * "21/10", "21" / "outubro", "09:00 - 17:00 · início 21/10/2026" e "terça, 20/10, às 23h59".
 */
function mcp_turma_rotulos(array $turma, ?int $agora = null): array
{
    $agora ??= time();
    $fuso = mcp_fuso_brt();
    $ini = (new DateTimeImmutable('@' . (int) mcp_iso_ts($turma['inicio'])))->setTimezone($fuso);
    $fimTs = mcp_iso_ts($turma['fim'] ?? null);
    $horario = $ini->format('H:i') . ($fimTs !== null ? ' - ' . (new DateTimeImmutable('@' . $fimTs))->setTimezone($fuso)->format('H:i') : '');
    $ateTs = (int) mcp_iso_ts($turma['inscricoes_ate']);
    $ate = (new DateTimeImmutable('@' . $ateTs))->setTimezone($fuso);
    $hoje = (new DateTimeImmutable('@' . $agora))->setTimezone($fuso)->setTime(0, 0);
    $dias = (int) $hoje->diff($ini->setTime(0, 0))->format('%r%a');
    return [
        'id_escola' => $turma['id_escola'], 'inicio' => $turma['inicio'], 'fim' => $turma['fim'] ?? null,
        'inscricoes_ate' => $turma['inscricoes_ate'], 'lotada' => (bool) ($turma['lotada'] ?? false),
        'horario' => $horario,
        'data_longa' => (int) $ini->format('j') . ' de ' . MCP_COMPRA_MESES[(int) $ini->format('n')] . ' de ' . $ini->format('Y'),
        'data' => $ini->format('d/m/Y'), 'dia' => $ini->format('d/m'),
        'bloco_dia' => $ini->format('d'), 'bloco_mes' => MCP_COMPRA_MESES[(int) $ini->format('n')],
        'lead' => $horario . ' · início ' . $ini->format('d/m/Y'),
        'prazo' => mcp_dia_semana_rotulo($ateTs) . ', às ' . $ate->format('H\hi'),
        'dias_para_inicio' => max(0, $dias),
    ];
}

/** Rótulos de uma data da escola ("AAAA-MM-DD") com o horário que ela mandou ("18:00 - 22:00"). */
function mcp_turma_rotulos_data(string $ymd, ?string $horario, ?string $id = null): ?array
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }
    $horario = is_string($horario) && $horario !== '' ? $horario : null;
    $data = "$m[3]/$m[2]/$m[1]";
    return [
        'id_escola' => $id, 'inicio' => $ymd, 'fim' => null, 'inscricoes_ate' => null, 'lotada' => false,
        'horario' => $horario,
        'data_longa' => (int) $m[3] . ' de ' . MCP_COMPRA_MESES[(int) $m[2]] . ' de ' . $m[1],
        'data' => $data, 'dia' => "$m[3]/$m[2]", 'bloco_dia' => $m[3], 'bloco_mes' => MCP_COMPRA_MESES[(int) $m[2]],
        'lead' => ($horario !== null ? "$horario · " : '') . 'início ' . $data,
        'prazo' => null,
    ];
}

/** A turma do curso aberta para inscrição (prazo no futuro, não lotada) que começa primeiro, com os rótulos, ou null. */
function mcp_turma_aberta(string $slug, ?int $agora = null): ?array
{
    $agora ??= time();
    $melhor = null;
    foreach (mcp_oferta()['turmas'] as $t) {
        if ($t['curso'] !== $slug || $t['lotada'] || (int) mcp_iso_ts($t['inscricoes_ate']) <= $agora) {
            continue;
        }
        if ($melhor === null || mcp_iso_ts($t['inicio']) < mcp_iso_ts($melhor['inicio'])) {
            $melhor = $t;
        }
    }
    return $melhor ? mcp_turma_rotulos($melhor, $agora) : null;
}

/** A turma do oferta.json pelo id da escola (aberta ou não), com os rótulos, ou null. */
function mcp_oferta_turma(?string $idEscola, ?int $agora = null): ?array
{
    if ($idEscola === null || $idEscola === '') {
        return null;
    }
    foreach (mcp_oferta()['turmas'] as $t) {
        if ($t['id_escola'] === $idEscola) {
            return mcp_turma_rotulos($t, $agora);
        }
    }
    return null;
}

/** A turma vendida ainda aceita inscrição (está no oferta.json, prazo no futuro, não lotada)? */
function mcp_turma_vendida_aberta(?string $idEscola, ?int $agora = null): bool
{
    $agora ??= time();
    foreach (mcp_oferta()['turmas'] as $t) {
        if ($t['id_escola'] === $idEscola) {
            return !$t['lotada'] && (int) mcp_iso_ts($t['inscricoes_ate']) > $agora;
        }
    }
    return false;
}

/** Sem turma aberta: a do oferta.json fechou ('prazo'), lotou ('lotada') ou não há nenhuma (null). */
function mcp_turma_estado(string $slug, ?int $agora = null): ?string
{
    $agora ??= time();
    $estado = null;
    foreach (mcp_oferta()['turmas'] as $t) {
        if ($t['curso'] !== $slug) {
            continue;
        }
        if ((int) mcp_iso_ts($t['inscricoes_ate']) > $agora) {
            if (!$t['lotada']) {
                return 'aberta';
            }
            $estado = 'lotada';
        } elseif ($estado === null) {
            $estado = 'prazo';
        }
    }
    return $estado;
}

/**
 * PIX pendente do plano completo cuja turma fechou, lotou ou saiu do oferta.json (T8): a tela Pendente troca o QR por
 * "As inscrições desta turma fecharam. Não pague este código." e o lembrete do PIX não sai.
 */
function mcp_pix_turma_fechada(array $inscricao, ?int $agora = null): bool
{
    return ($inscricao['status'] ?? '') === 'pendente' && ($inscricao['metodo'] ?? '') === 'pix'
        && ($inscricao['plano'] ?? 'so_taxa') === 'taxa_e_matricula' && !empty($inscricao['turma_id'])
        && !mcp_turma_vendida_aberta((string) $inscricao['turma_id'], $agora);
}

// ----------------------------------------------------------------------------- valores (2.2)
/**
 * Valor da matrícula: com o CPF em TESTE_CPFS, PRECO_TESTE_MATRICULA_CENTAVOS (0 = a opção 1 some para esse CPF:
 * nunca se cobra a matrícula real numa compra de teste). Para os demais, o valor_curso_centavos do cursos.json.
 */
function mcp_matricula_centavos(array $curso, ?string $cpf = null): int
{
    if (mcp_cpf_de_teste($cpf)) {
        return max(0, (int) mcp_cfg('PRECO_TESTE_MATRICULA_CENTAVOS', 0));
    }
    return max(0, (int) ($curso['valor_curso_centavos'] ?? 0));
}

/**
 * A compra, em centavos: taxa de inscrição, matrícula (só na opção 1), custos de processamento (5% só sobre a taxa,
 * E4), divulgação e o amount (a soma, sem juros). Parcelas: só na opção 1 no cartão; no PIX ou na opção 2, 1.
 */
function mcp_compra(array $curso, string $plano, string $metodo, bool $cobre, int $divulgacao, int $parcelas, ?string $cpf = null): array
{
    $plano = $plano === 'taxa_e_matricula' ? 'taxa_e_matricula' : 'so_taxa';
    $metodo = $metodo === 'cartao' ? 'cartao' : 'pix';
    $inscricao = mcp_inscricao_centavos($cpf);
    $matricula = $plano === 'taxa_e_matricula' ? mcp_matricula_centavos($curso, $cpf) : 0;
    $custos = $cobre ? mcp_taxa($metodo, $inscricao) : 0;
    $divulgacao = max(0, $divulgacao);
    return [
        'inscricao' => $inscricao, 'matricula' => $matricula, 'custos' => $custos, 'divulgacao' => $divulgacao,
        'amount' => $inscricao + $matricula + $custos + $divulgacao, 'plano' => $plano,
        'parcelas' => $plano === 'taxa_e_matricula' && $metodo === 'cartao' ? max(1, min(12, $parcelas)) : 1,
    ];
}

// ----------------------------------------------------------------------------- escola: versão da função (E12, T7)
/**
 * Versão da matricula_rapida no banco da escola: POST {base}/rpc/matricula_rapida_versao com a chave da escola,
 * tempo-limite de 5 s. Guardada em mcp_chaves (escola_versao) por 10 minutos (erro, por 1 minuto). Erro, tempo
 * esgotado ou outra resposta = 0: a opção 1 some antes de cobrar (falha fechada). Nos testes, a variável de ambiente
 * MCP_ESCOLA_VERSAO (só na linha de comando) substitui a consulta.
 */
function mcp_escola_versao(bool $recarregar = false): int
{
    static $memo = null;
    if ($memo !== null && !$recarregar) {
        return $memo;
    }
    $teste = getenv('MCP_ESCOLA_VERSAO');
    if (is_string($teste) && $teste !== '' && ctype_digit($teste) && in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
        return $memo = (int) $teste;
    }
    if (!mcp_escola_configurada()) {
        return $memo = 0;
    }
    if (!$recarregar) {
        $guardado = mcp_compra_chave_ler('escola_versao');
        if ($guardado !== null) {
            $versao = (int) $guardado['valor'];
            $idade = time() - (int) mcp_utc_ts($guardado['criado_em']);
            if ($idade >= 0 && $idade < ($versao > 0 ? MCP_ESCOLA_VERSAO_CACHE : MCP_ESCOLA_VERSAO_CACHE_FALHA)) {
                return $memo = $versao;
            }
        }
    }
    $versao = mcp_escola_versao_consultar();
    mcp_compra_chave_gravar('escola_versao', (string) $versao);
    return $memo = $versao;
}

function mcp_escola_versao_consultar(): int
{
    $url = (string) mcp_cfg('ESCOLA_API_URL', '');
    $versaoUrl = (string) preg_replace('#/matricula_rapida/?$#', '/matricula_rapida_versao', $url);
    if ($versaoUrl === $url) {
        error_log('[matricula] ESCOLA_API_URL não termina em /matricula_rapida: versão da escola = 0');
        return 0;
    }
    $r = mcp_escola_post($versaoUrl, '{}', 5);
    $d = $r['dados'];
    if ($r['status'] !== 200) {
        if ($r['status'] !== 404) {
            error_log('[matricula] versão da escola: HTTP ' . $r['status'] . ($r['erro'] !== '' ? ' · ' . $r['erro'] : ''));
        }
        return 0;
    }
    if (is_array($d) && isset($d[0]) && is_array($d[0])) {
        $d = reset($d[0]); // PostgREST antigo: [{"matricula_rapida_versao": 3}]
    }
    return is_int($d) || (is_string($d) && ctype_digit($d)) ? max(0, (int) $d) : 0;
}

/** mcp_chaves como guarda pequena (versão da escola, travas diárias da rotina). Falha do banco = sem guarda. */
function mcp_compra_chave_ler(string $nome): ?array
{
    try {
        $stmt = mcp_db()->prepare('SELECT valor, criado_em FROM mcp_chaves WHERE nome = ?');
        $stmt->execute([$nome]);
        $linha = $stmt->fetch();
        return $linha ?: null;
    } catch (Throwable) {
        return null;
    }
}

function mcp_compra_chave_gravar(string $nome, string $valor): void
{
    try {
        mcp_db()->prepare('INSERT INTO mcp_chaves (nome, valor, criado_em) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor), criado_em = VALUES(criado_em)')
            ->execute([$nome, mb_substr($valor, 0, 128), mcp_agora()]);
    } catch (Throwable $e) {
        error_log('[matricula] mcp_chaves (' . $nome . ') não gravada: ' . get_class($e));
    }
}

// ----------------------------------------------------------------------------- planos (2.1, 10.8)
/** Condições 1, 2, 3 e 6 da 2.1: a opção 1 com turma pode aparecer. */
function mcp_plano_completo_ligado(): bool
{
    return mcp_cfg_ligada('PLANO_COMPLETO') && mcp_cfg_ligada('ESCOLA_MATRICULA_PAGA') && mcp_escola_configurada()
        && mcp_escola_versao() >= 2;
}

/** A venda sem turma (10.8): o de cima, PLANO_COMPLETO_SEM_TURMA e a escola na versão 3. */
function mcp_plano_sem_turma_ligado(): bool
{
    return mcp_plano_completo_ligado() && mcp_cfg_ligada('PLANO_COMPLETO_SEM_TURMA') && mcp_escola_versao() >= 3;
}

/**
 * Lugares tomados na fila do curso: as pagas que esperam turma e os PIX da opção 1 sem turma gerados nas últimas 24 h
 * (ainda podem ser pagos), para a fila máxima não ser furada por vários PIX abertos ao mesmo tempo. Sem as colunas
 * novas: "cheia" (falha fechada).
 */
function mcp_espera_fila(string $slug, bool $comPendentes = true): int
{
    if (!mcp_colunas_plano_ok()) {
        return PHP_INT_MAX;
    }
    if (!$comPendentes) {
        // Só quem já pagou e espera (o que a escola recebe em fila_espera na chamada de "só a taxa").
        $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_inscricoes WHERE curso_slug = ? AND plano = 'taxa_e_matricula' AND status = 'pago' AND espera_status = 'aguardando'");
        $stmt->execute([$slug]);
        return (int) $stmt->fetchColumn();
    }
    $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_inscricoes WHERE curso_slug = ? AND plano = 'taxa_e_matricula'
        AND ((status = 'pago' AND espera_status = 'aguardando')
          OR (status = 'pendente' AND metodo = 'pix' AND turma_id IS NULL AND criado_em > ?))");
    $stmt->execute([$slug, gmdate('Y-m-d H:i:s', time() - 86400)]);
    return (int) $stmt->fetchColumn();
}

/**
 * Os planos do curso, de uma vez: {planos, motivo, turma, sem_turma}. A opção 1 vem primeiro (é a marcada). Motivos
 * (por que a opção 1 não aparece; escolhem a mensagem do 422): desligado, sem_preco, prazo, lotada,
 * sem_turma_desligado, sem_turma_fora_da_lista e fila_cheia. $cpf: só no pagamentos.php (preço de teste).
 */
function mcp_planos_info(array $curso, ?int $agora = null, ?string $cpf = null): array
{
    $slug = (string) ($curso['slug'] ?? '');
    $turma = mcp_turma_aberta($slug, $agora);
    $so = static fn(string $motivo): array => ['planos' => ['so_taxa'], 'motivo' => $motivo, 'turma' => $turma, 'sem_turma' => false];
    if (!mcp_cfg_ligada('PLANO_COMPLETO') || !mcp_cfg_ligada('ESCOLA_MATRICULA_PAGA') || !mcp_escola_configurada()) {
        return $so('desligado');
    }
    // Teste real (passos 4, 4b e 6): com PLANO_COMPLETO_SO_TESTE, só um CPF de TESTE_CPFS paga a opção 1 (o pagamentos.php
    // passa o CPF; a página e o info.php, sem CPF, continuam mostrando a opção).
    if ($cpf !== null && mcp_cfg_ligada('PLANO_COMPLETO_SO_TESTE') && !mcp_cpf_de_teste($cpf)) {
        return $so('desligado');
    }
    if (mcp_matricula_centavos($curso, $cpf) <= 0) {
        return $so('sem_preco');
    }
    if ($turma !== null) {
        return mcp_escola_versao() >= 2
            ? ['planos' => MCP_PLANOS, 'motivo' => null, 'turma' => $turma, 'sem_turma' => false]
            : $so('desligado');
    }
    // Sem turma aberta (nenhuma, prazo vencido ou lotada): a venda sem turma (10.8) cobre os cursos da lista.
    $estado = mcp_turma_estado($slug, $agora);
    if (!mcp_cfg_ligada('PLANO_COMPLETO_SEM_TURMA')) {
        return $so($estado ?? 'sem_turma_desligado');
    }
    $lista = mcp_oferta()['sem_turma'];
    if (!isset($lista[$slug])) {
        return $so($estado ?? 'sem_turma_fora_da_lista');
    }
    if (mcp_escola_versao() < 3) {
        return $so($estado ?? 'desligado');
    }
    if (mcp_espera_fila($slug) >= $lista[$slug]['max_fila']) {
        return $so('fila_cheia');
    }
    return ['planos' => MCP_PLANOS, 'motivo' => null, 'turma' => null, 'sem_turma' => true];
}

/** ['taxa_e_matricula', 'so_taxa'] ou ['so_taxa']; o motivo fica em mcp_plano_motivo(). */
function mcp_planos_do_curso(array $curso, ?int $agora = null, ?string $cpf = null): array
{
    $info = mcp_planos_info($curso, $agora, $cpf);
    mcp_plano_motivo($info['motivo']);
    return $info['planos'];
}

/** O motivo da última mcp_planos_do_curso() (null quando a opção 1 aparece). */
function mcp_plano_motivo(?string $novo = null): ?string
{
    static $motivo = null;
    if (func_num_args() > 0) {
        $motivo = $novo;
    }
    return $motivo;
}

/**
 * A mensagem do 422 de plano (1.12 e 10.4), pelo motivo. 'prazo_fila': a turma vendida fechou, mas o curso está na venda
 * sem turma, e a opção 1 continua (na fila da próxima turma); 'condicoes': a turma mudou (ou apareceu) com a página
 * aberta, e a página recarrega os planos. $turma: a turma que a página mostrou, para a data.
 */
function mcp_plano_mensagem(?string $motivo, ?array $turma = null): string
{
    if ($motivo === 'prazo_fila') {
        return 'As inscrições da turma' . (!empty($turma['data']) ? ' de ' . $turma['data'] : '') . ' fecharam. Você ainda pode pagar tudo agora e entrar na fila da próxima turma deste curso, com a data por e-mail, ou pagar só a taxa de inscrição. Confira as condições e pague de novo.';
    }
    if ($motivo === 'condicoes') {
        return 'As condições deste curso mudaram. Confira e pague de novo.';
    }
    return in_array($motivo, ['prazo', 'lotada'], true)
        ? 'As inscrições desta turma fecharam. Você ainda pode pagar só a taxa de inscrição e entrar na lista da próxima turma.'
        : 'No momento, a matrícula não pode ser paga junto. Você pode pagar só a taxa de inscrição.';
}

/**
 * O texto do aceite que o checkout mostra (1.12 e 10.4), o mesmo do checkout.js (aceiteTexto): o servidor guarda o hash
 * deste texto canônico como prova (F12), e não o do texto que o navegador mandou.
 */
function mcp_aceite_texto(array $curso, string $plano, bool $semTurma): string
{
    $escolaridade = trim((string) ($curso['escolaridade'] ?? ''));
    $base = 'Tenho a escolaridade mínima do curso' . ($escolaridade !== '' ? " ($escolaridade)" : '');
    if ($plano === 'taxa_e_matricula' && $semTurma) {
        $requisito = is_string($curso['requisito_aceite'] ?? null) ? trim($curso['requisito_aceite']) : '';
        return $base . $requisito . '. Sei que o curso ainda não tem turma: entro na primeira que tiver vaga, por ordem de pagamento, e recebo a data por e-mail.';
    }
    if ($plano === 'taxa_e_matricula') {
        return $base . '.';
    }
    return $base . '. Sei que a participação nas aulas é liberada só com a matrícula paga, além da taxa de inscrição.';
}

// ----------------------------------------------------------------------------- parcelas (2.4)
/**
 * Lê a simulação da Unicopag nos dois formatos: o real (data[]: installments, installment_amount, total_amount) e o da
 * doc (installments[]: installment, amount, total). Devolve [['n' => int, 'total' => centavos], ...] em ordem, ou null
 * (formato desconhecido). A parcela pode vir com fração; o total é arredondado ao centavo.
 */
function mcp_parcelas_ler(array $resposta): ?array
{
    if (isset($resposta['data']) && is_array($resposta['data'])) {
        [$lista, $chaveN, $chaveTotal, $chaveParcela] = [$resposta['data'], 'installments', 'total_amount', 'installment_amount'];
    } elseif (isset($resposta['installments']) && is_array($resposta['installments'])) {
        [$lista, $chaveN, $chaveTotal, $chaveParcela] = [$resposta['installments'], 'installment', 'total', 'amount'];
    } else {
        return null;
    }
    $saida = [];
    foreach ($lista as $item) {
        if (!is_array($item) || !is_numeric($item[$chaveN] ?? null) || (float) $item[$chaveN] != (int) $item[$chaveN]) {
            continue;
        }
        $n = (int) $item[$chaveN];
        if ($n < 1 || $n > 12) {
            continue;
        }
        if (is_numeric($item[$chaveTotal] ?? null)) {
            $total = (int) round((float) $item[$chaveTotal]);
        } elseif (is_numeric($item[$chaveParcela] ?? null)) {
            $total = (int) round((float) $item[$chaveParcela] * $n);
        } else {
            continue;
        }
        if ($total > 0) {
            $saida[$n] = ['n' => $n, 'total' => $total];
        }
    }
    if (!$saida) {
        return null;
    }
    ksort($saida);
    return array_values($saida);
}

/** Simulação da Unicopag com a chave do site, sem cache. null = falha ou formato desconhecido. */
function mcp_parcelas_simular(int $amount): ?array
{
    try {
        $resposta = mcp_unicopag('GET', '/public/v1/installments?amount=' . $amount, null, null, MCP_PARCELAS_TEMPO);
    } catch (McpUnicopagErro $e) {
        mcp_registrar(null, 'parcelas_falha', $e->status . ' ' . mb_substr($e->getMessage(), 0, 120));
        return null;
    }
    $lista = mcp_parcelas_ler($resposta);
    if ($lista === null) {
        mcp_registrar(null, 'parcelas_falha', 'formato desconhecido');
    }
    return $lista;
}

function mcp_parcelas_cache_ler(int $amount, ?int $agora = null): ?array
{
    try {
        $stmt = mcp_db()->prepare('SELECT opcoes, consultado_em FROM mcp_parcelas_cache WHERE amount_centavos = ?');
        $stmt->execute([$amount]);
        $linha = $stmt->fetch();
    } catch (Throwable) {
        return null;
    }
    if (!$linha || ($agora ?? time()) - (int) mcp_utc_ts($linha['consultado_em']) >= MCP_PARCELAS_CACHE_SEGUNDOS) {
        return null;
    }
    $lista = json_decode((string) $linha['opcoes'], true);
    return is_array($lista) && $lista ? $lista : null;
}

function mcp_parcelas_cache_gravar(int $amount, array $lista): void
{
    try {
        mcp_db()->prepare('INSERT INTO mcp_parcelas_cache (amount_centavos, opcoes, consultado_em) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE opcoes = VALUES(opcoes), consultado_em = VALUES(consultado_em)')
            ->execute([$amount, json_encode(array_values($lista)), mcp_agora()]);
    } catch (Throwable $e) {
        error_log('[matricula] cache de parcelas não gravado: ' . get_class($e));
    }
}

/**
 * Taxa ao mês (em %) que zera amount − p1/(1+i) − Σ p/(1+i)^k (k = 2..n): a TIR das parcelas reais, a 1ª em 30 dias,
 * por bisseção (200 passos entre 1e-9 e 1). Sem juros: 0.
 */
function mcp_taxa_mensal(int $avista, int $primeira, int $parcela, int $n): float
{
    if ($n <= 1 || $avista <= 0) {
        return 0.0;
    }
    $valor = static function (float $i) use ($avista, $primeira, $parcela, $n): float {
        $v = $avista - $primeira / (1 + $i);
        for ($k = 2; $k <= $n; $k++) {
            $v -= $parcela / ((1 + $i) ** $k);
        }
        return $v;
    };
    $baixo = 1e-9;
    $alto = 1.0;
    if ($valor($baixo) >= 0) {
        return 0.0;
    }
    if ($valor($alto) < 0) {
        return 100.0;
    }
    for ($passo = 0; $passo < 200; $passo++) {
        $meio = ($baixo + $alto) / 2;
        if ($valor($meio) < 0) {
            $baixo = $meio;
        } else {
            $alto = $meio;
        }
    }
    return ($baixo + $alto) / 2 * 100;
}

/** CET ao ano (em %): (1 + i)^12 − 1. Sem outro encargo além dos juros, é a própria taxa anual (CDC, art. 54-B, § 2º). */
function mcp_cet_ano(float $taxaMesPct): float
{
    return ((1 + $taxaMesPct / 100) ** 12 - 1) * 100;
}

/** "27%", "2,21%" ($inteiroSemCasas) ou "4,60%". */
function mcp_pct_rotulo(float $valor, bool $inteiroSemCasas = false): string
{
    $valor = round($valor, 2);
    if ($inteiroSemCasas && abs($valor - round($valor)) < 0.000001) {
        return number_format($valor, 0, ',', '.') . '%';
    }
    return number_format($valor, 2, ',', '.') . '%';
}

/** Uma opção do seletor, com as contas da 2.4. */
function mcp_parcela_opcao(int $amount, int $n, int $total, ?int $agora = null): array
{
    $agora ??= time();
    $n = max(1, $n);
    $parcela = intdiv($total, $n);
    $primeira = $total - ($n - 1) * $parcela;
    $juros = max(0, $total - $amount);
    $acrescimo = $amount > 0 ? round($juros / $amount * 100, 2) : 0.0;
    $taxa = $n > 1 && $juros > 0 ? mcp_taxa_mensal($amount, $primeira, $parcela, $n) : 0.0;
    $cet = $taxa > 0 ? round(mcp_cet_ano($taxa), 2) : 0.0;
    $taxa = round($taxa, 2);
    $valido = $agora + MCP_PARCELAS_VALIDADE_SEGUNDOS;
    return [
        'n' => $n, 'parcela_centavos' => $parcela, 'primeira_centavos' => $primeira, 'total_centavos' => $total,
        'juros_centavos' => $juros, 'acrescimo_pct' => $acrescimo, 'taxa_mes_pct' => $taxa, 'cet_ano_pct' => $cet,
        'acrescimo_rotulo' => mcp_pct_rotulo($acrescimo, true), 'taxa_mes_rotulo' => mcp_pct_rotulo($taxa),
        'cet_ano_rotulo' => mcp_pct_rotulo($cet),
        'valido_ate' => gmdate('Y-m-d\TH:i:s\Z', $valido),
        'valido_ate_rotulo' => mcp_data_brt(gmdate('Y-m-d H:i:s', $valido)),
    ];
}

/**
 * As opções de parcelamento para o amount: cache (só a lista crua, 10 min), simulação, leitura e os filtros da 2.4, a
 * cada leitura (baixar PARCELAS_MAX vale na requisição seguinte): 1 ≤ n ≤ PARCELAS_MAX; alguma opção n ≥ 2 com total ≤
 * amount = a conta absorve os juros, e o parcelado inteiro some (E2); parcela ≥ PARCELA_MINIMA_CENTAVOS. Falha: só o
 * 1×. Nunca "valor ÷ N". Sempre começa pelo 1×. $semCache: a cobrança (simulação nova, T11). O motivo de só haver o 1×
 * fica em mcp_parcelas_motivo().
 */
function mcp_parcelas_opcoes(int $amount, bool $semCache = false, ?int $agora = null): array
{
    $agora ??= time();
    if (mcp_parcelas_max() <= 1 || $amount <= 0) {
        return mcp_parcelas_filtrar($amount, null, $agora, 'desligado');
    }
    $lista = $semCache ? null : mcp_parcelas_cache_ler($amount, $agora);
    if ($lista === null) {
        $lista = mcp_parcelas_simular($amount);
        if ($lista !== null) {
            mcp_parcelas_cache_gravar($amount, $lista);
        }
    }
    return mcp_parcelas_filtrar($amount, $lista, $agora, 'falha');
}

/**
 * Os filtros da 2.4 sobre a lista crua (pura, sem banco nem rede). $lista null = só o 1×, com o $motivoSemLista.
 * O motivo de só haver o 1× fica em mcp_parcelas_motivo(): desligado, falha, sem_juros ou minimo.
 */
function mcp_parcelas_filtrar(int $amount, ?array $lista, ?int $agora = null, string $motivoSemLista = 'falha'): array
{
    $agora ??= time();
    $um = mcp_parcela_opcao($amount, 1, $amount, $agora);
    $max = mcp_parcelas_max();
    if ($lista === null || $max <= 1 || $amount <= 0) {
        mcp_parcelas_motivo($max <= 1 || $amount <= 0 ? 'desligado' : $motivoSemLista);
        return [$um];
    }
    $candidatas = array_values(array_filter($lista, static fn(mixed $o): bool => is_array($o) && (int) ($o['n'] ?? 0) >= 2 && (int) $o['n'] <= $max && (int) ($o['total'] ?? 0) > 0));
    foreach ($candidatas as $o) {
        if ((int) $o['total'] <= $amount) {
            // A conta está absorvendo os juros: o parcelado inteiro some (E2). Registra no máximo uma vez por hora.
            if (mcp_contar_eventos_do_tipo_seguro('parcelas_sem_juros', 3600) === 0) {
                mcp_registrar(null, 'parcelas_sem_juros', "amount $amount · {$o['n']}x total {$o['total']}");
            }
            mcp_parcelas_motivo('sem_juros');
            return [$um];
        }
    }
    $saida = [$um];
    $minimo = mcp_parcela_minima_centavos();
    usort($candidatas, static fn(array $a, array $b): int => (int) $a['n'] <=> (int) $b['n']);
    foreach ($candidatas as $o) {
        if (intdiv((int) $o['total'], (int) $o['n']) >= $minimo) {
            $saida[] = mcp_parcela_opcao($amount, (int) $o['n'], (int) $o['total'], $agora);
        }
    }
    mcp_parcelas_motivo(count($saida) > 1 ? null : 'minimo');
    return $saida;
}

function mcp_parcelas_motivo(?string $novo = null): ?string
{
    static $motivo = null;
    if (func_num_args() > 0) {
        $motivo = $novo;
    }
    return $motivo;
}

/** mcp_contar_eventos_do_tipo sem derrubar a conta das parcelas se o banco falhar. */
function mcp_contar_eventos_do_tipo_seguro(string $tipo, int $segundos): int
{
    try {
        return mcp_contar_eventos_do_tipo($tipo, $segundos);
    } catch (Throwable) {
        return 1;
    }
}

/** Grava o que o parcelas.php mostrou (a linha guarda a última vez; a validade de 48 h conta dali). */
function mcp_parcelas_registrar_exibidas(int $amount, array $opcoes, ?int $agora = null): void
{
    $quando = gmdate('Y-m-d H:i:s', $agora ?? time());
    $valores = [];
    $params = [];
    foreach ($opcoes as $o) {
        if ((int) ($o['n'] ?? 0) >= 2) {
            $valores[] = '(?, ?, ?, ?)';
            array_push($params, $amount, (int) $o['n'], (int) $o['total_centavos'], $quando);
        }
    }
    if (!$valores) {
        return;
    }
    try {
        mcp_db()->prepare('INSERT INTO mcp_parcelas_exibidas (amount_centavos, n, total_centavos, exibido_em) VALUES '
            . implode(', ', $valores) . ' ON DUPLICATE KEY UPDATE exibido_em = VALUES(exibido_em)')->execute($params);
    } catch (Throwable $e) {
        error_log('[matricula] parcelas mostradas não registradas: ' . get_class($e));
    }
}

/** O servidor mostrou esse total para esse amount e n nas últimas 48 h? */
function mcp_parcelas_foi_exibida(int $amount, int $n, int $total, ?int $agora = null): bool
{
    try {
        $stmt = mcp_db()->prepare('SELECT 1 FROM mcp_parcelas_exibidas WHERE amount_centavos = ? AND n = ? AND total_centavos = ? AND exibido_em > ? LIMIT 1');
        $stmt->execute([$amount, $n, $total, gmdate('Y-m-d H:i:s', ($agora ?? time()) - MCP_PARCELAS_VALIDADE_SEGUNDOS)]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

/** Faxina da rotina de 15 minutos: o histórico do que foi mostrado sai em 7 dias; o cache, em 1. */
function mcp_parcelas_faxina(?int $agora = null): int
{
    $agora ??= time();
    $pdo = mcp_db();
    $stmt = $pdo->prepare('DELETE FROM mcp_parcelas_exibidas WHERE exibido_em < ?');
    $stmt->execute([gmdate('Y-m-d H:i:s', $agora - 7 * 86400)]);
    $pdo->prepare('DELETE FROM mcp_parcelas_cache WHERE consultado_em < ?')->execute([gmdate('Y-m-d H:i:s', $agora - 86400)]);
    return $stmt->rowCount();
}

// ----------------------------------------------------------------------------- cobrança
/**
 * O corpo do POST /public/v1/payments: uma cobrança só (decisão 9: juros sobre o total, inclusive os opcionais), com a
 * taxa e a matrícula como itens separados. amount = soma dos price, sem juros; installments = parcelas (1 no PIX).
 * $compra: o de mcp_compra() + turma_id e total_mostrado (pagamentos.php).
 * Declarada só se ainda não existir: o pagamentos.php de antes do pagar tudo declara a sua, e o opcache pode juntar
 * esse arquivo antigo com o lib.php novo por alguns segundos na publicação (spec 3.5, passo 3; T10). Sem a guarda,
 * seria "Cannot redeclare" e o checkout pararia nessa janela. O pagamentos.php novo não declara: vale esta.
 */
if (!function_exists('mcp_montar_cobranca')) {
    function mcp_montar_cobranca(array $aluno, string $token, array $compra): array
    {
        $slug = (string) $aluno['slug'];
        $nome = (string) $aluno['curso']['nome'];
        $itens = [['inscricao-' . $slug, $nome . ' — Taxa de inscrição', (int) $compra['inscricao']]];
        if ($compra['plano'] === 'taxa_e_matricula') {
            $itens[] = ['matricula-' . $slug, $nome . ' — Matrícula', (int) $compra['matricula']];
        }
        if ((int) $compra['custos'] > 0) {
            $itens[] = ['custos-processamento', 'Custos de processamento (opcional)', (int) $compra['custos']];
        }
        if ((int) $compra['divulgacao'] > 0) {
            $itens[] = ['divulgacao', 'Contribuição para a divulgação dos cursos (opcional)', (int) $compra['divulgacao']];
        }
        $cart = array_map(static fn(array $i): array => ['hash' => $i[0], 'title' => $i[1], 'price' => $i[2], 'quantity' => 1, 'operation_type' => 1], $itens);
        $pix = $aluno['metodo'] === 'pix';
        return [
            'amount' => array_sum(array_column($cart, 'price')),
            'payment_method' => $pix ? 'pix' : 'credit_card',
            'installments' => $pix ? 1 : max(1, (int) $compra['parcelas']),
            'customer' => ['name' => $aluno['nome'], 'email' => $aluno['email'], 'phone_number' => $aluno['telefone'], 'document' => $aluno['cpf']],
            'cart' => $cart,
            'postback_url' => mcp_site_url() . '/matricula-cursos-presenciais/api/webhook.php',
            'expire_in_days' => 1,
            'origin' => 'matricula-cursos-presenciais',
            'metadata' => [
                'token' => $token, 'curso' => $slug, 'curso_nome' => $nome, 'aluno' => $aluno['nome'],
                'cobre_taxa' => $aluno['cobre_taxa'], 'ajuda_divulgacao' => (int) $compra['divulgacao'] > 0,
                'utm_source' => $aluno['utm_source'], 'utm_campaign' => $aluno['utm_campaign'],
                'plano' => $compra['plano'], 'parcelas' => $pix ? 1 : max(1, (int) $compra['parcelas']),
                'matricula_centavos' => (int) $compra['matricula'], 'turma_id' => $compra['turma_id'] ?? null,
                'total_mostrado_centavos' => $compra['total_mostrado'] ?? null, 'order_id' => mcp_meta_id_da_compra($token),
            ],
        ];
    }
}

/** Todas as colunas novas (3.3 e 10.8) existem? Uma consulta por requisição (T12). Falha do banco = não. */
function mcp_colunas_plano_ok(bool $recarregar = false): bool
{
    static $ok = null;
    if ($ok !== null && !$recarregar) {
        return $ok;
    }
    try {
        $existentes = array_column(mcp_db()->query('SHOW COLUMNS FROM mcp_inscricoes')->fetchAll(), 'Field');
    } catch (Throwable) {
        return $ok = false;
    }
    // Com o db.php antigo no opcache (logo depois de uma publicação), a lista não existe: confere três colunas-chave.
    $colunas = defined('MCP_DB_COLUNAS_PAGAR_TUDO') ? array_keys(MCP_DB_COLUNAS_PAGAR_TUDO) : ['plano', 'espera_status', 'aceite_em'];
    return $ok = !array_diff($colunas, $existentes);
}

/**
 * Outra compra paga do mesmo curso pelo mesmo CPF nos últimos 180 dias (E14): devolve o plano dela, ou null. Só o CPF:
 * é como a escola acha o aluno. A estornada não conta.
 */
function mcp_compra_ja_paga(string $slug, string $cpf, ?int $agora = null): ?string
{
    $desde = gmdate('Y-m-d H:i:s', ($agora ?? time()) - MCP_COMPRA_REPETIDA_DIAS * 86400);
    $plano = mcp_colunas_plano_ok() ? 'plano' : "'so_taxa' AS plano";
    $stmt = mcp_db()->prepare("SELECT $plano FROM mcp_inscricoes WHERE curso_slug = ? AND cpf = ? AND status = 'pago' AND pago_em > ? ORDER BY pago_em DESC LIMIT 1");
    $stmt->execute([$slug, $cpf, $desde]);
    $achado = $stmt->fetchColumn();
    return $achado === false ? null : (string) $achado;
}

// ----------------------------------------------------------------------------- venda sem turma: datas (10.3, 10.8)
/** Data limite (UTC "Y-m-d H:i:s"): 23h59min59s, em Brasília, do dia da compra + ESPERA_PRAZO_DIAS. */
function mcp_espera_prazo(int $criadoEm, ?int $dias = null): string
{
    $dias ??= mcp_espera_prazo_dias();
    $d = (new DateTimeImmutable('@' . $criadoEm))->setTimezone(mcp_fuso_brt())->setTime(0, 0)
        ->modify('+' . $dias . ' days')->setTime(23, 59, 59);
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** A data limite como a pessoa lê: "06/01/2027" (Brasília). */
function mcp_espera_data_limite(?string $prazoUtc, string $formato = 'd/m/Y'): string
{
    $ts = mcp_utc_ts($prazoUtc);
    return $ts === null ? '' : (new DateTimeImmutable('@' . $ts))->setTimezone(mcp_fuso_brt())->format($formato);
}

/** inicio_ate da v2 ("AAAA-MM-DD"): a data limite em Brasília. */
function mcp_espera_inicio_ate(?string $prazoUtc): string
{
    return mcp_espera_data_limite($prazoUtc, 'Y-m-d');
}

/** {marcar até}: a data limite − 10 dias (o último dia em que a turma ainda pode ser marcada). */
function mcp_espera_marcar_ate(?string $prazoUtc, string $formato = 'd/m/Y'): string
{
    $ts = mcp_utc_ts($prazoUtc);
    return $ts === null ? '' : (new DateTimeImmutable('@' . $ts))->setTimezone(mcp_fuso_brt())->modify('-10 days')->format($formato);
}

// ----------------------------------------------------------------------------- visão pública da compra
/** O total pago: o cobrado com juros (amount_total) quando a Unicopag já informou; senão, o amount. */
function mcp_total_cobrado(array $inscricao): int
{
    $cobrado = $inscricao['total_cobrado_centavos'] ?? null;
    return $cobrado !== null && (int) $cobrado > 0 ? (int) $cobrado : (int) $inscricao['total_centavos'];
}

/** Parcela e 1ª parcela da compra (total ÷ n truncado; a 1ª leva a diferença). */
function mcp_parcela_da_inscricao(array $inscricao): array
{
    $n = max(1, (int) ($inscricao['parcelas'] ?? 1));
    $total = mcp_total_cobrado($inscricao);
    $parcela = intdiv($total, $n);
    return ['parcela' => $parcela, 'primeira' => $total - ($n - 1) * $parcela];
}

/**
 * A turma desta compra, com os rótulos: a da resposta da escola quando houver (com o horário do oferta.json quando for
 * a mesma turma: F4, T2); antes dela, a do oferta.json pelo turma_id. 'origem' diz de onde veio.
 */
function mcp_compra_turma(array $inscricao): ?array
{
    $acesso = ($inscricao['status'] ?? '') === 'pago' ? mcp_escola_acesso($inscricao) : null;
    if ($acesso && ($acesso['resultado'] ?? '') === 'matriculado') {
        $id = is_string($acesso['turma_id'] ?? null) ? $acesso['turma_id'] : null;
        if ($id !== null && $id === ($inscricao['turma_id'] ?? null) && ($t = mcp_oferta_turma($id))) {
            return $t + ['origem' => 'escola'];
        }
        $data = $acesso['turma_primeira_aula'] ?? $acesso['turma_inicio'] ?? null;
        $t = is_string($data) ? mcp_turma_rotulos_data($data, is_string($acesso['turma_horario'] ?? null) ? $acesso['turma_horario'] : null, $id) : null;
        if ($t) {
            return $t + ['origem' => 'escola'];
        }
    }
    $t = mcp_oferta_turma(isset($inscricao['turma_id']) ? (string) $inscricao['turma_id'] : null);
    return $t ? $t + ['origem' => 'oferta'] : null;
}

/** A turma vendida (turma_id) com os rótulos: do oferta.json, ou só a data da coluna turma_inicio (UTC), ou null. */
function mcp_compra_turma_vendida(array $inscricao): ?array
{
    $id = isset($inscricao['turma_id']) && $inscricao['turma_id'] !== '' ? (string) $inscricao['turma_id'] : null;
    if ($id === null) {
        return null;
    }
    if ($t = mcp_oferta_turma($id)) {
        return $t;
    }
    $ts = mcp_utc_ts($inscricao['turma_inicio'] ?? null);
    return $ts !== null ? mcp_turma_rotulos_data((new DateTimeImmutable('@' . $ts))->setTimezone(mcp_fuso_brt())->format('Y-m-d'), null, $id) : null;
}

/**
 * Os campos do pagar tudo que status.php e pagamentos.php acrescentam a mcp_publico() (array_replace_recursive). Sem
 * CPF, hash, IP nem motivo interno. Inscrição antiga (sem as colunas): os padrões de só a taxa.
 */
function mcp_publico_compra(array $inscricao): array
{
    $pago = ($inscricao['status'] ?? '') === 'pago';
    $plano = ($inscricao['plano'] ?? 'so_taxa') === 'taxa_e_matricula' ? 'taxa_e_matricula' : 'so_taxa';
    $parcelas = max(1, (int) ($inscricao['parcelas'] ?? 1));
    $partes = mcp_parcela_da_inscricao($inscricao);
    $total = mcp_total_cobrado($inscricao);
    $amount = (int) $inscricao['total_centavos'];
    $acesso = $pago ? mcp_escola_acesso($inscricao) : null;
    $esperaStatus = $inscricao['espera_status'] ?? null;
    $semTurma = $plano === 'taxa_e_matricula' && ($esperaStatus !== null || empty($inscricao['turma_id']));
    $janela = mcp_utc_ts($inscricao['espera_janela_ate'] ?? null);
    $pixFechada = mcp_pix_turma_fechada($inscricao);
    $turmaEscola = null;
    if ($acesso && is_string($acesso['turma_id'] ?? null)) {
        $turmaEscola = [
            'id' => $acesso['turma_id'], 'inicio' => $acesso['turma_inicio'] ?? null, 'primeira_aula' => $acesso['turma_primeira_aula'] ?? null,
            'horario' => $acesso['turma_horario'] ?? null, 'status' => $acesso['turma_status'] ?? null,
        ];
    }
    return [
        'plano' => $plano,
        'matricula_centavos' => (int) ($inscricao['matricula_centavos'] ?? 0),
        'matricula_preco_centavos' => isset($inscricao['matricula_preco_centavos']) ? (int) $inscricao['matricula_preco_centavos'] : null,
        'parcelas' => $parcelas,
        'juros_centavos' => (int) ($inscricao['juros_centavos'] ?? 0),
        'total_cobrado_centavos' => $total,
        'parcela_centavos' => $partes['parcela'],
        'primeira_parcela_centavos' => $partes['primeira'],
        'acrescimo_pct' => $parcelas > 1 && $amount > 0 ? round(max(0, $total - $amount) / $amount * 100, 2) : null,
        'taxa_mes_pct' => isset($inscricao['taxa_mes_pct']) ? (float) $inscricao['taxa_mes_pct'] : null,
        'cet_ano_pct' => isset($inscricao['cet_ano_pct']) ? (float) $inscricao['cet_ano_pct'] : null,
        'diferenca_devolver_centavos' => (int) ($inscricao['diferenca_devolver_centavos'] ?? 0),
        'data_limite' => !empty($inscricao['espera_prazo']) ? mcp_espera_data_limite((string) $inscricao['espera_prazo']) : null,
        'sem_turma' => $semTurma,
        'pix_turma_fechada' => $pixFechada,
        // Na Pendente: com a turma fechada, o curso ainda vende tudo sem turma? ("Inscrever-se de novo", e não "só a taxa").
        'pix_fila_disponivel' => $pixFechada && mcp_compra_fila_disponivel((string) ($inscricao['curso_slug'] ?? '')),
        // PIX aberto de quem já pagou outra compra deste curso: a Pendente diz "Não pague este código".
        'pix_ja_pago' => mcp_pix_ja_pago($inscricao),
        'turma' => $plano === 'taxa_e_matricula' || !empty($inscricao['turma_id']) ? mcp_compra_turma($inscricao) : null,
        // A turma que a página vendeu (turma_id), com os rótulos: a Parabéns do aviso turma_diferente diz "A turma de
        // {data anunciada} lotou ou fechou…" (1.13). Do oferta.json; se ela já saiu de lá, da coluna turma_inicio.
        'turma_vendida' => $plano === 'taxa_e_matricula' ? mcp_compra_turma_vendida($inscricao) : null,
        'escola' => [
            'matricula_paga' => $acesso ? (array_key_exists('matricula_paga', $acesso) ? $acesso['matricula_paga'] : null) : null,
            'avisos' => $acesso ? mcp_escola_avisos($inscricao) : [],
            'turma' => $turmaEscola,
        ],
        'espera' => $esperaStatus === null ? null : [
            'status' => $esperaStatus,
            'data_limite' => mcp_espera_data_limite($inscricao['espera_prazo'] ?? null),
            'turma' => $esperaStatus === 'turma' ? mcp_compra_turma($inscricao) : null,
            'janela_ate' => $janela !== null ? mcp_dia_semana_rotulo($janela) : null,
        ],
    ];
}

/** O curso vende a opção 1 sem turma agora (venda sem turma ligada, curso na lista, fila com lugar)? Falha = não. */
function mcp_compra_fila_disponivel(string $slug): bool
{
    try {
        $curso = mcp_curso($slug);
        return $curso !== null && mcp_planos_info($curso)['sem_turma'];
    } catch (Throwable) {
        return false;
    }
}

/**
 * PIX pendente de um CPF que já tem outra compra paga do mesmo curso (troca de plano, ou dois PIX gerados): pagar este
 * levaria a uma devolução manual, com tarifa. Falha do banco = false (a tela fica como estava).
 */
function mcp_pix_ja_pago(array $inscricao): bool
{
    if (($inscricao['status'] ?? '') !== 'pendente' || ($inscricao['metodo'] ?? '') !== 'pix' || empty($inscricao['cpf']) || empty($inscricao['id'])) {
        return false;
    }
    try {
        $stmt = mcp_db()->prepare("SELECT 1 FROM mcp_inscricoes WHERE cpf = ? AND curso_slug = ? AND id <> ? AND status = 'pago' LIMIT 1");
        $stmt->execute([(string) $inscricao['cpf'], (string) $inscricao['curso_slug'], (int) $inscricao['id']]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

/** mcp_publico() com os campos do pagar tudo (status.php e pagamentos.php). */
function mcp_publico_completo(array $inscricao, ?array $preferencia = null): array
{
    return array_replace_recursive(mcp_publico($inscricao, $preferencia), mcp_publico_compra($inscricao));
}
