<?php
/**
 * Turmas sob demanda (04/10/2026): grupo fechado de 15 a 30 alunos, qualquer curso em inglês e primeiros
 * socorros para jovens de 12 a 14 anos. Um formulário só (api/turmas.php, seção "Turmas sob demanda" da
 * página de matrícula); o número de alunos decide o que acontece:
 *   - 15 ou mais: pedido de turma fechada, com prioridade. A secretaria responde com datas;
 *   - menos de 15: lista de interesse do curso naquele idioma. A turma abre quando a soma chega a 15, e a
 *     secretaria avisa quem está na lista.
 * Em português, os cursos do catálogo têm turma aberta: quem não tem grupo faz a matrícula na hora, sem lista.
 * Regras da escola: mesmo valor por pessoa dos cursos do catálogo; aulas na sede, e outro local só com
 * aprovação; todo curso pode ser dado em inglês (professor ou tradutor). Nada é cobrado aqui.
 *
 * Para menores (curso dos jovens), quem preenche é o responsável ou a instituição: nenhum dado do jovem é
 * pedido. A secretaria acompanha tudo no portal (?v=turmas).
 */
declare(strict_types=1);

const MCP_TURMA_MINIMO = 15;
const MCP_TURMA_MAXIMO = 30;
/** Acima de 30 alunos o pedido vale, e a secretaria divide em mais de uma turma. */
const MCP_TURMA_PESSOAS_MAX = 300;
const MCP_TURMA_LIMITE_IP = [6, 3600];
const MCP_TURMA_LIMITE_EMAIL = [4, 3600];
const MCP_TURMA_OBS_MAX = 2000;
const MCP_TURMA_STATUS = ['novo' => 'Novo', 'em_contato' => 'Em contato', 'turma_marcada' => 'Turma marcada', 'arquivado' => 'Arquivado'];
/** Situações em que o pedido ainda conta para a lista de interesse. */
const MCP_TURMA_STATUS_ABERTOS = ['novo', 'em_contato'];
const MCP_TURMA_IDIOMAS = ['pt' => 'Português', 'en' => 'Inglês'];

/** Cursos que só existem sob demanda (fora do catálogo da escola). */
const MCP_TURMA_CURSOS_EXTRAS = [
    'primeiros-socorros-jovens' => 'Primeiros Socorros para Jovens (12 a 14 anos)',
];

/** Cursos que aceitam turma sob demanda: os do catálogo e os extras. slug => ['nome' => …, 'catalogo' => bool]. */
function mcp_turma_cursos(): array
{
    $cursos = [];
    foreach (mcp_catalogo()['cursos'] as $c) {
        if (!empty($c['slug']) && !empty($c['nome'])) {
            $cursos[(string) $c['slug']] = ['nome' => (string) $c['nome'], 'catalogo' => true];
        }
    }
    foreach (MCP_TURMA_CURSOS_EXTRAS as $slug => $nome) {
        $cursos[$slug] = ['nome' => $nome, 'catalogo' => false];
    }
    return $cursos;
}

/**
 * Curso e idioma de uma lista no portal. Vale também curso que saiu do catálogo depois que a lista começou:
 * a lista continua aparecendo e precisa abrir. Só o formato é conferido; o nome vem do banco.
 */
function mcp_turma_lista_valida(string $curso, string $idioma): bool
{
    return (bool) preg_match('/^[a-z0-9-]{1,80}$/', $curso) && isset(MCP_TURMA_IDIOMAS[$idioma]);
}

/** 'fechada' a partir de 15 alunos; abaixo disso, 'lista' (lista de interesse). */
function mcp_turma_tipo(int $pessoas): string
{
    return $pessoas >= MCP_TURMA_MINIMO ? 'fechada' : 'lista';
}

/** Quantas turmas de até 30 cabem no pedido. */
function mcp_turma_quantas(int $pessoas): int
{
    return max(1, (int) ceil($pessoas / MCP_TURMA_MAXIMO));
}

/** Situação de uma lista de interesse: soma de alunos, quantos faltam para 15 e se já dá para abrir a turma. */
function mcp_turma_progresso(int $soma): array
{
    return [
        'soma' => $soma,
        'faltam' => max(0, MCP_TURMA_MINIMO - $soma),
        'pronta' => $soma >= MCP_TURMA_MINIMO,
        'pct' => min(100, (int) floor($soma * 100 / MCP_TURMA_MINIMO)),
    ];
}

/** "Primeiros Socorros Básico, em inglês" (o português não aparece: é o padrão). */
function mcp_turma_rotulo(string $cursoNome, string $idioma): string
{
    return $cursoNome . ($idioma === 'en' ? ', em inglês' : '');
}

/** "20 alunos" / "1 aluno". */
function mcp_turma_alunos(int $n): string
{
    return $n . ($n === 1 ? ' aluno' : ' alunos');
}

/**
 * Confere o formulário. Devolve ['ok' => true, 'dados' => […]] ou ['ok' => false, 'erro' => …, 'campo' => …]
 * (e 'matricula' => caminho do checkout, quando a resposta certa é fazer a matrícula na hora).
 * Função pura: não grava, não manda e-mail, não lê o banco.
 */
function mcp_turma_conferir(array $b): array
{
    $falha = static fn(string $campo, string $erro, array $extra = []): array => ['ok' => false, 'campo' => $campo, 'erro' => $erro] + $extra;
    $cursos = mcp_turma_cursos();
    $slug = mcp_texto($b['curso'] ?? '', 80);
    if (!isset($cursos[$slug])) {
        return $falha('curso', 'Escolha o curso.');
    }
    $idioma = mcp_texto($b['idioma'] ?? 'pt', 2);
    if (!isset(MCP_TURMA_IDIOMAS[$idioma])) {
        return $falha('idioma', 'Escolha o idioma das aulas.');
    }
    $pessoasBruto = is_int($b['pessoas'] ?? null) ? (string) $b['pessoas'] : mcp_texto($b['pessoas'] ?? '', 6);
    if (!preg_match('/^\d{1,4}$/', $pessoasBruto) || (int) $pessoasBruto < 1) {
        return $falha('pessoas', 'Diga quantos alunos vão fazer o curso.');
    }
    $pessoas = (int) $pessoasBruto;
    if ($pessoas > MCP_TURMA_PESSOAS_MAX) {
        return $falha('pessoas', 'Para mais de ' . MCP_TURMA_PESSOAS_MAX . ' alunos, escreva pelo chat: a secretaria monta um plano com você.');
    }
    $tipo = mcp_turma_tipo($pessoas);
    $curso = $cursos[$slug];
    // Em português, curso do catálogo tem turma aberta: sem grupo de 15, a matrícula é na hora, não numa lista.
    if ($tipo === 'lista' && $idioma === 'pt' && $curso['catalogo']) {
        return $falha('pessoas', 'Em português, ' . $curso['nome'] . ' já tem turma aberta: cada pessoa faz a matrícula na hora. '
            . 'Turma exclusiva é a partir de ' . MCP_TURMA_MINIMO . ' alunos.', ['matricula' => '/matricula-cursos-presenciais/checkout/?curso=' . rawurlencode($slug)]);
    }
    // Lista de interesse é sempre na sede; outro local só para grupo fechado, e depende de aprovação.
    $local = $tipo === 'fechada' && mcp_texto($b['local'] ?? '', 10) === 'outro' ? 'outro' : 'sede';
    $endereco = $local === 'outro' ? mcp_texto($b['local_endereco'] ?? '', 200) : '';
    if ($local === 'outro' && mb_strlen($endereco) < 5) {
        return $falha('local_endereco', 'Diga onde seriam as aulas (bairro e cidade, ou o endereço).');
    }
    $nome = mcp_texto($b['nome'] ?? '', 120);
    if (mb_strlen($nome) < 2) {
        return $falha('nome', 'Digite seu nome.');
    }
    $email = mb_strtolower(mcp_texto($b['email'] ?? '', 190));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $falha('email', 'Esse e-mail não parece válido.');
    }
    $telefone = mcp_telefone(mcp_texto($b['telefone'] ?? '', 30));
    if ($telefone === '') {
        return $falha('telefone', 'Informe o WhatsApp com DDD: é por ele que avisamos quando a turma fechar.');
    }
    if (($b['consentimento'] ?? false) !== true) {
        return $falha('consentimento', 'Marque a autorização para a secretaria falar com você sobre esta turma.');
    }
    $observacoes = mcp_texto_longo($b['observacoes'] ?? '', MCP_TURMA_OBS_MAX + 1);
    if (mb_strlen($observacoes) > MCP_TURMA_OBS_MAX) {
        return $falha('observacoes', 'As observações podem ter até ' . number_format(MCP_TURMA_OBS_MAX, 0, ',', '.') . ' caracteres.');
    }
    $pagina = mcp_texto($b['pagina'] ?? '', 255);
    if ($pagina !== '' && (!str_starts_with($pagina, '/') || str_starts_with($pagina, '//'))) {
        $pagina = '';
    }
    $origem = is_array($b['origem'] ?? null) ? $b['origem'] : [];
    $utm = static fn(string $k, int $limite = 160): ?string => mcp_texto($origem[$k] ?? '', $limite) ?: null;
    return ['ok' => true, 'dados' => [
        'tipo' => $tipo, 'curso_slug' => $slug, 'curso_nome' => $curso['nome'], 'idioma' => $idioma, 'pessoas' => $pessoas,
        'local' => $local, 'local_endereco' => $endereco ?: null,
        'organizacao' => mcp_texto($b['organizacao'] ?? '', 160) ?: null,
        'periodo' => mcp_texto($b['periodo'] ?? '', 200) ?: null,
        'nome' => $nome, 'email' => $email, 'telefone' => $telefone, 'observacoes' => $observacoes ?: null,
        'pagina' => $pagina ?: null,
        'utm_source' => $utm('utm_source', 120), 'utm_medium' => $utm('utm_medium', 120),
        'utm_campaign' => $utm('utm_campaign'), 'utm_content' => $utm('utm_content'), 'utm_term' => $utm('utm_term'),
        'fbclid' => $utm('fbclid', 255), 'gclid' => $utm('gclid', 255),
    ]];
}

/** Protocolo legível: TS-aammdd-NNNN (data de Brasília, id do pedido). */
function mcp_turma_protocolo(int $id, ?string $agoraUtc = null): string
{
    $data = (new DateTimeImmutable($agoraUtc ?? mcp_agora(), new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Sao_Paulo'));
    return sprintf('TS-%s-%04d', $data->format('ymd'), $id);
}

// ----------------------------------------------------------------------------- banco
/** Grava o pedido conferido e devolve o id. As chaves vêm de mcp_turma_conferir(), nunca do cliente. */
function mcp_turma_gravar(array $d): int
{
    $linha = [
        'tipo' => $d['tipo'], 'curso_slug' => $d['curso_slug'], 'curso_nome' => $d['curso_nome'], 'idioma' => $d['idioma'],
        'pessoas' => $d['pessoas'], 'local' => $d['local'], 'local_endereco' => $d['local_endereco'], 'organizacao' => $d['organizacao'],
        'periodo' => $d['periodo'], 'nome' => $d['nome'], 'email' => $d['email'], 'telefone' => $d['telefone'],
        'observacoes' => $d['observacoes'], 'pagina' => $d['pagina'],
        'utm_source' => $d['utm_source'], 'utm_medium' => $d['utm_medium'], 'utm_campaign' => $d['utm_campaign'],
        'utm_content' => $d['utm_content'], 'utm_term' => $d['utm_term'], 'fbclid' => $d['fbclid'], 'gclid' => $d['gclid'],
        'ip' => mcp_ip(), 'criado_em' => mcp_agora(),
    ];
    $colunas = implode(', ', array_map(static fn(string $c): string => "`$c`", array_keys($linha)));
    $marcadores = implode(', ', array_fill(0, count($linha), '?'));
    $pdo = mcp_db();
    $pdo->prepare("INSERT INTO mcp_turmas_pedidos ($colunas) VALUES ($marcadores)")->execute(array_values($linha));
    return (int) $pdo->lastInsertId();
}

function mcp_turma_por_id(int $id): ?array
{
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_turmas_pedidos WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $linha = $stmt->fetch();
    return $linha ?: null;
}

function mcp_turma_atualizar(int $id, array $campos): void
{
    foreach (array_keys($campos) as $coluna) {
        if (!preg_match('/^[a-z_]+$/', (string) $coluna)) {
            throw new InvalidArgumentException("coluna inválida: $coluna");
        }
    }
    $sets = implode(', ', array_map(static fn($c) => "`$c` = :$c", array_keys($campos)));
    $campos['id'] = $id;
    mcp_db()->prepare("UPDATE mcp_turmas_pedidos SET $sets WHERE id = :id")->execute($campos);
}

/** Quantos pedidos uma chave (ip ou email) enviou nos últimos N segundos. Base dos freios. */
function mcp_turma_contar_recentes(string $coluna, string $valor, int $segundos): int
{
    if (!in_array($coluna, ['ip', 'email'], true)) {
        throw new InvalidArgumentException("coluna não permitida: $coluna");
    }
    $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_turmas_pedidos WHERE $coluna = ? AND criado_em > ?");
    $stmt->execute([$valor, gmdate('Y-m-d H:i:s', time() - $segundos)]);
    return (int) $stmt->fetchColumn();
}

/**
 * Listas de interesse em aberto, por curso e idioma: soma de alunos, número de pessoas na lista (e-mails) e o
 * último pedido. Cada e-mail conta uma vez, pelo maior pedido em aberto: quem reenvia o formulário (a
 * confirmação foi para o spam, mudou o número de alunos) não infla a lista nem dispara um falso "lista
 * completa". Com $curso e $idioma, só aquela lista. As mais cheias primeiro.
 */
function mcp_turma_demanda(?string $curso = null, ?string $idioma = null): array
{
    $sql = "SELECT curso_slug, MAX(curso_nome) AS curso_nome, idioma, SUM(pessoas) AS pessoas, COUNT(*) AS pedidos, MAX(ultimo) AS ultimo
        FROM (SELECT curso_slug, MAX(curso_nome) AS curso_nome, idioma, email, MAX(pessoas) AS pessoas, MAX(criado_em) AS ultimo
              FROM mcp_turmas_pedidos WHERE tipo = 'lista' AND status IN ('novo', 'em_contato')";
    $params = [];
    if ($curso !== null && $idioma !== null) {
        $sql .= ' AND curso_slug = ? AND idioma = ?';
        $params = [$curso, $idioma];
    }
    $stmt = mcp_db()->prepare($sql . ' GROUP BY curso_slug, idioma, email) por_pessoa GROUP BY curso_slug, idioma ORDER BY pessoas DESC, ultimo DESC');
    $stmt->execute($params);
    return array_map(static fn(array $l): array => ['pessoas' => (int) $l['pessoas'], 'pedidos' => (int) $l['pedidos']] + $l, $stmt->fetchAll());
}

/**
 * Pedidos para o portal, mais recentes primeiro. Filtros: tipo ('fechada' | 'lista'), status (null = abertos,
 * 'todos' = qualquer um, ou um de MCP_TURMA_STATUS), curso e idioma (uma lista de interesse).
 */
function mcp_turma_listar(array $filtro, int $limite = 50, int $deslocamento = 0): array
{
    $onde = [];
    $params = [];
    if (in_array($filtro['tipo'] ?? null, ['fechada', 'lista'], true)) {
        $onde[] = 'tipo = ?';
        $params[] = $filtro['tipo'];
    }
    $status = $filtro['status'] ?? null;
    if ($status === null) {
        $onde[] = "status IN ('novo', 'em_contato')";
    } elseif (isset(MCP_TURMA_STATUS[$status])) {
        $onde[] = 'status = ?';
        $params[] = $status;
    }
    if (!empty($filtro['curso']) && !empty($filtro['idioma'])) {
        $onde[] = 'curso_slug = ? AND idioma = ?';
        array_push($params, $filtro['curso'], $filtro['idioma']);
    }
    $sql = 'SELECT * FROM mcp_turmas_pedidos' . ($onde ? ' WHERE ' . implode(' AND ', $onde) : '')
        . ' ORDER BY id DESC LIMIT ' . max(1, min(5000, $limite)) . ' OFFSET ' . max(0, $deslocamento);
    $stmt = mcp_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** O que pede ação no portal: pedidos de turma fechada sem resposta e listas que já chegaram a 15. */
function mcp_turma_contar(): array
{
    $novas = (int) mcp_db()->query("SELECT COUNT(*) FROM mcp_turmas_pedidos WHERE tipo = 'fechada' AND status = 'novo'")->fetchColumn();
    $prontas = count(array_filter(mcp_turma_demanda(), static fn(array $l): bool => $l['pessoas'] >= MCP_TURMA_MINIMO));
    return ['fechadas_novas' => $novas, 'listas_prontas' => $prontas, 'acao' => $novas + $prontas];
}

/** Muda a situação de um pedido (portal). Devolve false se o pedido não existe ou a situação é inválida. */
function mcp_turma_status(int $id, string $status, string $por): bool
{
    if (!isset(MCP_TURMA_STATUS[$status])) {
        return false;
    }
    $stmt = mcp_db()->prepare('UPDATE mcp_turmas_pedidos SET status = ?, status_por = ?, status_em = ? WHERE id = ?');
    $stmt->execute([$status, mb_substr($por, 0, 190), mcp_agora(), $id]);
    return $stmt->rowCount() > 0 || mcp_turma_por_id($id) !== null;
}

/**
 * Muda de uma vez os pedidos em aberto de uma lista de interesse (curso + idioma), por exemplo quando a turma
 * abre. Só os que a secretaria tinha na tela: id até $ateId. Quem entrou na lista depois continua nela, para
 * ser avisado da próxima turma. Devolve quantos mudaram.
 */
function mcp_turma_status_lista(string $curso, string $idioma, string $status, string $por, int $ateId): int
{
    if (!isset(MCP_TURMA_STATUS[$status]) || $ateId <= 0) {
        return 0;
    }
    $stmt = mcp_db()->prepare("UPDATE mcp_turmas_pedidos SET status = ?, status_por = ?, status_em = ?
        WHERE tipo = 'lista' AND curso_slug = ? AND idioma = ? AND status IN ('novo', 'em_contato') AND status <> ? AND id <= ?");
    $stmt->execute([$status, mb_substr($por, 0, 190), mcp_agora(), $curso, $idioma, $status, $ateId]);
    return $stmt->rowCount();
}

/** Planilha de uma lista ou dos pedidos de turma fechada (para avisar todos de uma vez). Sem IP. */
function mcp_turma_csv(array $linhas): string
{
    $f = fopen('php://temp', 'w+');
    fputcsv($f, ['Recebido em (Brasília)', 'Protocolo', 'Tipo', 'Situação', 'Curso', 'Idioma', 'Alunos', 'Local', 'Instituição', 'Nome', 'E-mail', 'WhatsApp', 'Período', 'Observações'], ';', '"', '');
    foreach ($linhas as $l) {
        fputcsv($f, array_map('mcp_horarios_celula', [
            mcp_data_brt((string) $l['criado_em'], 'd/m/Y H:i'), (string) $l['protocolo'],
            $l['tipo'] === 'fechada' ? 'Turma fechada' : 'Lista de interesse', MCP_TURMA_STATUS[$l['status']] ?? (string) $l['status'],
            (string) $l['curso_nome'], MCP_TURMA_IDIOMAS[$l['idioma']] ?? (string) $l['idioma'], (string) $l['pessoas'],
            $l['local'] === 'outro' ? 'Outro local: ' . $l['local_endereco'] : 'Sede', (string) $l['organizacao'],
            (string) $l['nome'], (string) $l['email'], mcp_telefone_bonito((string) $l['telefone']), (string) $l['periodo'], (string) $l['observacoes'],
        ]), ';', '"', '');
    }
    rewind($f);
    $csv = "\xEF\xBB\xBF" . stream_get_contents($f);
    fclose($f);
    return $csv;
}

// ----------------------------------------------------------------------------- e-mails
/** Quem recebe os pedidos: a secretaria (EMAIL_SECRETARIA) ou, sem ela, o contato do site. */
function mcp_turma_email_destino(): string
{
    return (string) (mcp_cfg('EMAIL_SECRETARIA', '') ?: mcp_email_contato_endereco());
}

/** Linhas da ficha do pedido, comuns aos dois e-mails. */
function mcp_turma_linhas(array $p): array
{
    $linhas = [
        'Curso' => (string) $p['curso_nome'],
        'Idioma das aulas' => MCP_TURMA_IDIOMAS[$p['idioma']] ?? (string) $p['idioma'],
        'Alunos' => mcp_turma_alunos((int) $p['pessoas']) . ((int) $p['pessoas'] > MCP_TURMA_MAXIMO ? ' (' . mcp_turma_quantas((int) $p['pessoas']) . ' turmas de até ' . MCP_TURMA_MAXIMO . ')' : ''),
        'Local' => $p['local'] === 'outro' ? 'Outro local, sujeito a aprovação: ' . $p['local_endereco'] : 'Sede, Praça da Cruz Vermelha, 10, Centro',
    ];
    if (!empty($p['organizacao'])) {
        $linhas['Instituição'] = (string) $p['organizacao'];
    }
    if (!empty($p['periodo'])) {
        $linhas['Período preferido'] = (string) $p['periodo'];
    }
    $linhas['Protocolo'] = (string) $p['protocolo'];
    return $linhas;
}

/** Valor por aluno, como na página: o mesmo dos cursos do catálogo; o curso dos jovens, sob consulta. */
function mcp_turma_valor_texto(array $p): string
{
    $curso = mcp_curso((string) $p['curso_slug']);
    $inscricao = mcp_brl(mcp_inscricao_centavos());
    if ($curso && !empty($curso['valor_curso_centavos'])) {
        return "O valor por aluno é o mesmo do curso: inscrição de $inscricao e " . mcp_brl((int) $curso['valor_curso_centavos']) . ' do curso.';
    }
    return 'O valor por aluno vem na proposta da secretaria.';
}

/** Aviso à secretaria, com a prioridade do pedido no topo e, na lista de interesse, quantos já somam. */
function mcp_montar_email_turma_equipe(array $p, ?array $progresso = null): array
{
    $fechada = $p['tipo'] === 'fechada';
    $rotulo = mcp_turma_rotulo((string) $p['curso_nome'], (string) $p['idioma']);
    $nome = (string) $p['nome'];
    $email = (string) $p['email'];
    $telefone = (string) $p['telefone'];
    $linhas = ['Nome' => $nome,
        'E-mail' => ['html' => '<a href="mailto:' . mcp_escapar($email) . '" style="color:#cc0000;text-decoration:none">' . mcp_escapar($email) . '</a>'],
        'WhatsApp' => ['html' => '<a href="https://wa.me/55' . mcp_escapar(mcp_digitos($telefone)) . '" style="color:#1a202c;text-decoration:none">' . mcp_escapar(mcp_telefone_bonito($telefone)) . '</a>'],
    ] + mcp_turma_linhas($p);
    $linhas['Origem'] = trim(($p['utm_source'] ?? '') . ' ' . ($p['utm_campaign'] ?? '')) ?: 'direto';
    $portal = mcp_site_url() . '/matricula-cursos-presenciais/api/painel.php?v=turmas'
        . ($fechada ? '' : '&curso=' . rawurlencode((string) $p['curso_slug']) . '&idioma=' . rawurlencode((string) $p['idioma']));

    if ($fechada) {
        $abertura = '<strong>' . mcp_escapar($nome) . '</strong>' . (!empty($p['organizacao']) ? ' (' . mcp_escapar((string) $p['organizacao']) . ')' : '')
            . ' quer fechar uma turma de <strong>' . mcp_escapar($rotulo) . '</strong> com <strong>' . mcp_escapar(mcp_turma_alunos((int) $p['pessoas'])) . '</strong>.'
            . ' Grupo fechado tem prioridade: a pessoa foi avisada de que a resposta chega em até ' . MCP_EMAIL_PRAZO . '.';
        $alertas = ($p['local'] === 'outro' ? mcp_nota('<strong>Fora da sede:</strong> as aulas em outro local precisam de aprovação antes de confirmar a data.') : '')
            . ((int) $p['pessoas'] > MCP_TURMA_MAXIMO ? mcp_nota('<strong>Mais de ' . MCP_TURMA_MAXIMO . ' alunos:</strong> são ' . mcp_turma_quantas((int) $p['pessoas']) . ' turmas.') : '');
    } else {
        $abertura = '<strong>' . mcp_escapar($nome) . '</strong> entrou na lista de interesse de <strong>' . mcp_escapar($rotulo) . '</strong> com '
            . mcp_escapar(mcp_turma_alunos((int) $p['pessoas'])) . '.';
        $alertas = '';
        if ($progresso !== null) {
            $alertas = $progresso['pronta']
                ? mcp_nota('<strong>A lista chegou a ' . mcp_escapar(mcp_turma_alunos($progresso['soma'])) . ':</strong> já dá para abrir a turma e avisar todos.')
                : mcp_nota('A lista soma <strong>' . mcp_escapar(mcp_turma_alunos($progresso['soma'])) . '</strong>. Faltam ' . $progresso['faltam'] . ' para ' . MCP_TURMA_MINIMO . '.');
        }
    }
    $corpo = mcp_p($abertura) . $alertas
        . (!empty($p['observacoes']) ? mcp_subtitulo('Observações') . mcp_citacao((string) $p['observacoes']) : '')
        . mcp_botao($portal, $fechada ? 'Abrir os pedidos de turma no portal' : 'Ver a lista no portal')
        . mcp_nota('Responder este e-mail fala direto com ' . mcp_escapar(mcp_primeiro_nome($nome)) . '.')
        . mcp_subtitulo('Dados do pedido') . mcp_caixa($linhas);

    $texto = html_entity_decode(strip_tags($abertura), ENT_QUOTES, 'UTF-8') . "\n\n";
    foreach ($linhas as $r => $v) {
        $texto .= "$r: " . (is_array($v) ? ($r === 'E-mail' ? $email : mcp_telefone_bonito($telefone)) : $v) . "\n";
    }
    if (!empty($p['observacoes'])) {
        $texto .= "\nObservações:\n{$p['observacoes']}\n";
    }
    if (!$fechada && $progresso !== null) {
        $texto .= "\nA lista soma " . mcp_turma_alunos($progresso['soma']) . ($progresso['pronta'] ? ': já dá para abrir a turma.' : '; faltam ' . $progresso['faltam'] . '.') . "\n";
    }
    $texto .= "\nPortal: $portal\n";
    $prefixo = $fechada ? '[Turma fechada]' : ($progresso !== null && $progresso['pronta'] ? '[Lista completa]' : '[Lista de interesse]');
    return [
        'assunto' => "$prefixo $rotulo · " . mcp_turma_alunos((int) $p['pessoas']) . (!empty($p['organizacao']) ? ' · ' . $p['organizacao'] : ' · ' . $nome) . ' · ' . $p['protocolo'],
        'html' => mcp_moldura($fechada ? 'Pedido de turma fechada' : 'Nova pessoa na lista de interesse', $corpo, [
            'eyebrow' => 'Turmas sob demanda · ' . $p['protocolo'],
            'preheader' => $rotulo . ' · ' . mcp_turma_alunos((int) $p['pessoas']),
            'motivo' => 'Aviso automático da página de matrícula de cruzvermelhariodejaneiro.org.',
        ]),
        'texto' => $texto,
    ];
}

/** Confirmação a quem pediu: o que acontece agora, o valor por aluno e o protocolo. */
function mcp_montar_email_turma_confirmacao(array $p): array
{
    $fechada = $p['tipo'] === 'fechada';
    $nome = mcp_primeiro_nome((string) $p['nome']);
    $rotulo = mcp_turma_rotulo((string) $p['curso_nome'], (string) $p['idioma']);
    $protocolo = (string) $p['protocolo'];
    $remetente = mcp_email_endereco(mcp_email_remetente_contato());
    $valor = mcp_turma_valor_texto($p);
    $whats = mcp_telefone_bonito((string) $p['telefone']);
    if ($fechada) {
        $passos = [
            ['A secretaria responde em até ' . MCP_EMAIL_PRAZO, 'Por e-mail ou pelo WhatsApp ' . mcp_escapar($whats) . ', com as datas possíveis para a turma. Grupo fechado tem prioridade.'],
            ['Vocês combinam a data', 'Turmas de ' . MCP_TURMA_MINIMO . ' a ' . MCP_TURMA_MAXIMO . ' alunos, na sede da Praça da Cruz Vermelha'
                . ($p['local'] === 'outro' ? '. Aulas em outro local dependem de aprovação, e a secretaria confirma se dá' : '') . '.'],
            ['Cada aluno garante a vaga', mcp_escapar($valor) . ' Nada é cobrado agora.'],
        ];
        $abertura = 'Oi, ' . mcp_escapar($nome) . '. Recebemos o pedido de uma turma de <strong>' . mcp_escapar($rotulo) . '</strong> para <strong>'
            . mcp_escapar(mcp_turma_alunos((int) $p['pessoas'])) . '</strong>.';
    } else {
        $passos = [
            ['Você está na lista de interesse', 'A turma de ' . mcp_escapar($rotulo) . ' abre quando juntarmos ' . MCP_TURMA_MINIMO . ' alunos. Nada é cobrado agora.'],
            ['Avisamos quando a turma fechar', 'Por e-mail e pelo WhatsApp ' . mcp_escapar($whats) . ', com a data e o link para garantir a vaga.'],
            ['Tem um grupo? Ele tem prioridade', 'Se você juntar ' . MCP_TURMA_MINIMO . ' pessoas (colegas, escola, empresa), a turma é de vocês e a data é combinada direto com a secretaria. Responda este e-mail contando quantos são.'],
        ];
        $abertura = 'Oi, ' . mcp_escapar($nome) . '. Você entrou na lista de interesse de <strong>' . mcp_escapar($rotulo) . '</strong>'
            . ((int) $p['pessoas'] > 1 ? ' com <strong>' . mcp_escapar(mcp_turma_alunos((int) $p['pessoas'])) . '</strong>' : '') . '.';
    }
    $corpo = mcp_p($abertura . ' Guarde o protocolo <strong>' . mcp_escapar($protocolo) . '</strong>.')
        . mcp_subtitulo('O que acontece agora') . mcp_passos($passos)
        . mcp_caixa(mcp_turma_linhas($p))
        . ($fechada ? '' : mcp_p(mcp_escapar($valor)))
        . mcp_nota('O remetente é ' . mcp_escapar($remetente) . '. Para mudar algo no pedido, responda este e-mail. Não foi você? Ignore esta mensagem.');
    $texto = html_entity_decode(strip_tags($abertura), ENT_QUOTES, 'UTF-8') . " Protocolo: $protocolo.\n\nO que acontece agora:\n";
    foreach ($passos as $i => [$titulo, $detalhe]) {
        $texto .= ($i + 1) . ") $titulo. " . html_entity_decode(strip_tags($detalhe), ENT_QUOTES, 'UTF-8') . "\n";
    }
    $texto .= "\n";
    foreach (mcp_turma_linhas($p) as $r => $v) {
        $texto .= "$r: $v\n";
    }
    if (!$fechada) {
        $texto .= "\n$valor\n";
    }
    $texto .= "\nPara mudar algo no pedido, responda este e-mail.\n";
    return [
        'assunto' => ($fechada ? 'Recebemos o pedido da sua turma' : 'Você está na lista de interesse') . " · $protocolo",
        'html' => mcp_moldura($fechada ? "Pedido de turma recebido, $nome." : "Você está na lista, $nome.", $corpo, [
            'eyebrow' => 'Turmas sob demanda',
            'preheader' => $fechada ? 'A secretaria responde em até ' . MCP_EMAIL_PRAZO . ' com as datas.' : 'Avisamos quando juntarmos ' . MCP_TURMA_MINIMO . ' alunos.',
            'motivo' => 'Você recebeu este e-mail porque pediu uma turma na página de matrícula de cruzvermelhariodejaneiro.org.',
        ]),
        'texto' => $texto,
    ];
}

/** Envia o aviso à secretaria (responder-para = quem pediu) e a confirmação. Devolve o resultado dos dois. */
function mcp_email_turma(array $p, ?array $progresso): array
{
    $equipe = mcp_montar_email_turma_equipe($p, $progresso);
    $r1 = mcp_enviar_email(mcp_turma_email_destino(), $equipe['assunto'], $equipe['html'], $equipe['texto'], (string) $p['email']);
    $confirmacao = mcp_montar_email_turma_confirmacao($p);
    $r2 = mcp_enviar_email((string) $p['email'], $confirmacao['assunto'], $confirmacao['html'], $confirmacao['texto'], mcp_turma_email_destino());
    return ['equipe' => $r1, 'confirmacao' => $r2];
}
