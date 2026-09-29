#!/usr/bin/env php
<?php
/**
 * Teste de ponta a ponta do questionário de dias e horários, contra um MariaDB LOCAL. Cobre:
 *   - o banco (mcp_preferencias);
 *   - a API (api/horarios.php e o campo horarios de api/status.php);
 *   - o painel da equipe (api/painel.php?v=horarios e a planilha);
 *   - os lembretes a quem não respondeu (api/lembretes.php, como o cron roda).
 * As chamadas passam pelo servidor embutido do PHP, como as de um navegador.
 *
 * Precisa de MCP_CONFIG_ARQUIVO com o banco local (DB_HOST 127.0.0.1 ou localhost). O teste:
 *   - cria inscrições fictícias, com CPF gerado só com o dígito verificador válido;
 *   - confere o resultado;
 *   - apaga tudo no fim.
 * Nenhum e-mail sai: o teste recusa RESEND_API_KEY, e o servidor roda com sendmail_path=/bin/true.
 *
 * Uso: MCP_CONFIG_ARQUIVO=/caminho/config-teste.php php scripts/testar_horarios_integracao.php
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$configOriginal = (string) getenv('MCP_CONFIG_ARQUIVO');
if ($configOriginal === '' || !is_file($configOriginal)) {
    fwrite(STDERR, "Falta MCP_CONFIG_ARQUIVO com o banco local (veja o cabeçalho deste arquivo).\n");
    exit(2);
}
// Mesma configuração, com a secretaria ligada (para conferir o aviso) e sem integração com a escola.
$config = tempnam(sys_get_temp_dir(), 'mcp-horarios-');
// Lembretes: só pagamentos dos últimos 4 dias entram (as inscrições do teste e nada antigo do banco local).
file_put_contents($config, '<?php return [\'EMAIL_SECRETARIA\' => \'secretaria@exemplo.org\', \'ESCOLA_API_URL\' => \'\', \'ESCOLA_API_TOKEN\' => \'\', '
    . '\'HORARIOS_LEMBRETES_DESDE\' => ' . var_export(gmdate('Y-m-d H:i:s', time() - 4 * 86400), true) . '] + (require '
    . var_export($configOriginal, true) . ');');
$semEscola = tempnam(sys_get_temp_dir(), 'mcp-horarios-escola-');
file_put_contents($semEscola, '<?php return [];');
putenv("MCP_CONFIG_ARQUIVO=$config");
putenv("MCP_CONFIG_ESCOLA_ARQUIVO=$semEscola");
putenv('MCP_CATALOGO_ARQUIVO=' . $raiz . '/site/matricula-cursos-presenciais/cursos.json');
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $raiz . '/site/matricula-cursos-presenciais/api/lib.php';
restore_exception_handler();

if (!in_array((string) mcp_cfg('DB_HOST', 'localhost'), ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "DB_HOST não é local. Este teste grava e apaga dados; só roda num banco local.\n");
    exit(2);
}
if ((string) mcp_cfg('RESEND_API_KEY', '') !== '') {
    fwrite(STDERR, "A configuração tem RESEND_API_KEY. Use uma configuração de teste sem chave de e-mail.\n");
    exit(2);
}

$falhas = 0;
$total = 0;
function verificar(string $nome, mixed $obtido, mixed $esperado): void
{
    global $falhas, $total;
    $total++;
    if ($obtido === $esperado) {
        echo "ok   $nome\n";
        return;
    }
    $falhas++;
    fwrite(STDERR, sprintf("FALHOU %s\n  esperado: %s\n  obtido:   %s\n", $nome, var_export($esperado, true), var_export($obtido, true)));
}

/** Valor da chave, null incluído; 'ausente' só quando a chave não existe (?? não distingue os dois). */
function campo(mixed $lista, string $chave): mixed
{
    return is_array($lista) && array_key_exists($chave, $lista) ? $lista[$chave] : 'ausente';
}

/** CPF com dígito verificador válido a partir de 9 dígitos aleatórios (só para este banco local). */
function cpf_de_teste(): string
{
    $d = array_map(static fn(): int => random_int(0, 9), range(1, 9));
    for ($n = 9; $n < 11; $n++) {
        $s = 0;
        foreach ($d as $i => $v) {
            $s += $v * ($n + 1 - $i);
        }
        $d[] = ($s * 10) % 11 % 10;
    }
    return implode('', $d);
}

// Saída cortada (| head) ou Ctrl+C não podem matar o teste antes da limpeza do finally: sem
// ignore_user_abort, o PHP trata a escrita num pipe fechado como cliente que saiu e aborta o script.
ignore_user_abort(true);
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGPIPE, SIG_IGN);
    foreach ([SIGINT, SIGTERM] as $sinal) {
        pcntl_signal($sinal, static function (): never {
            throw new RuntimeException('Teste interrompido.');
        });
    }
}

/** Apaga as inscrições do teste, as respostas, os registros e os downloads da planilha feitos pelo teste. */
function limpar(PDO $db, array $ids): void
{
    $lista = implode(', ', array_map('intval', $ids));
    if ($lista !== '') {
        $db->exec("DELETE FROM mcp_preferencias WHERE inscricao_id IN ($lista)");
        $db->exec("DELETE FROM mcp_eventos WHERE inscricao_id IN ($lista)");
        $db->exec("DELETE FROM mcp_inscricoes WHERE id IN ($lista)");
    }
    $db->exec("DELETE FROM mcp_eventos WHERE tipo = 'painel_horarios_csv' AND detalhe LIKE 'contato@exemplo.org%'");
}

// ----------------------------------------------------------------------------- inscrições fictícias
$db = mcp_db();
// Sobras de uma execução interrompida: as inscrições deste teste têm e-mail [a-e]-xxxxxx@exemplo.org.
$sobras = $db->prepare('SELECT id FROM mcp_inscricoes WHERE email REGEXP ? AND nome LIKE ?');
$sobras->execute(['^[a-e]-[0-9a-f]{6}@exemplo[.]org$', 'Alun_ Teste _ %']);
limpar($db, $sobras->fetchAll(PDO::FETCH_COLUMN));
$agora = mcp_agora();
$ids = [];
$criar = static function (array $campos) use ($db, $agora, &$ids): array {
    $linha = $campos + [
        'token' => mcp_token_novo(), 'cpf' => cpf_de_teste(), 'telefone' => '21999990000', 'metodo' => 'pix',
        'inscricao_centavos' => 9900, 'taxa_centavos' => 495, 'total_centavos' => 10395, 'status' => 'pago',
        'escola_status' => 'nao_aplicavel', 'escola_acesso' => null, 'criado_em' => $agora, 'atualizado_em' => $agora, 'pago_em' => $agora,
    ];
    $colunas = array_keys($linha);
    $db->prepare('INSERT INTO mcp_inscricoes (' . implode(', ', $colunas) . ') VALUES (' . implode(', ', array_fill(0, count($colunas), '?')) . ')')
        ->execute(array_values($linha));
    $linha['id'] = (int) $db->lastInsertId();
    $ids[] = $linha['id'];
    return $linha;
};
$sufixo = bin2hex(random_bytes(3));
// A: paga e já matriculada numa turma da escola. B: paga, outro curso, sem turma. C: pendente.
$a = $criar(['curso_slug' => 'bombeiro-civil', 'curso_nome' => 'Bombeiro Civil', 'nome' => "Aluna Teste A $sufixo", 'email' => "a-$sufixo@exemplo.org",
    'escola_status' => 'ok', 'escola_acesso' => json_encode(['resultado' => 'matriculado', 'aluno_novo' => false, 'email_confere' => true,
        'turma_inicio' => '2026-10-21', 'aviso' => null, 'url_login' => 'https://escola.cursoscruzvermelha.org/login'])]);
$b = $criar(['curso_slug' => 'puncao-venosa', 'curso_nome' => 'Punção Venosa', 'nome' => "Aluno Teste B $sufixo", 'email' => "b-$sufixo@exemplo.org"]);
$c = $criar(['curso_slug' => 'bombeiro-civil', 'curso_nome' => 'Bombeiro Civil', 'nome' => "Aluno Teste C $sufixo", 'email' => "c-$sufixo@exemplo.org",
    'status' => 'pendente', 'pago_em' => null]);
// D e E: pagas há 25 h e há 73 h, sem resposta (lembretes). E já recebeu o 1º lembrete há 49 h.
$horasAtras = static fn(int $h): string => gmdate('Y-m-d H:i:s', time() - $h * 3600);
$d = $criar(['curso_slug' => 'bombeiro-civil', 'curso_nome' => 'Bombeiro Civil', 'nome' => "Aluna Teste D $sufixo", 'email' => "d-$sufixo@exemplo.org", 'pago_em' => $horasAtras(25)]);
$e = $criar(['curso_slug' => 'bombeiro-civil', 'curso_nome' => 'Bombeiro Civil', 'nome' => "Aluno Teste E $sufixo", 'email' => "e-$sufixo@exemplo.org", 'pago_em' => $horasAtras(73)]);
$db->prepare("INSERT INTO mcp_eventos (inscricao_id, tipo, detalhe, criado_em) VALUES (?, 'lembrete_horarios', '#1 mail', ?)")->execute([$e['id'], $horasAtras(49)]);

// ----------------------------------------------------------------------------- servidor embutido
$porta = 18700 + random_int(0, 999);
$logErros = tempnam(sys_get_temp_dir(), 'mcp-horarios-php-');
$servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$porta", '-t', $raiz . '/site', '-d', 'sendmail_path=/bin/true',
    '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$logErros"],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $tubos,
    null, ['MCP_CONFIG_ARQUIVO' => $config, 'MCP_CONFIG_ESCOLA_ARQUIVO' => $semEscola, 'PATH' => (string) getenv('PATH')]);
$base = "http://127.0.0.1:$porta/matricula-cursos-presenciais/api/";
// Sessão do painel aberta antes de qualquer saída (setcookie reclama depois que o teste imprime).
mcp_painel_sessao_abrir('contato@exemplo.org');
$cookie = 'Cookie: ' . MCP_PAINEL_COOKIE . '=' . $_COOKIE[MCP_PAINEL_COOKIE];
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $porta) === false; $i++) {
    usleep(100000);
}

/** [status, cabeçalhos em minúsculas, corpo]. */
function http(string $url, string $metodo = 'GET', ?string $corpo = null, array $cabecalhos = []): array
{
    $recebidos = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $cabecalhos, CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $linha) use (&$recebidos): int {
            if (str_contains($linha, ':')) {
                [$nome, $valor] = explode(':', $linha, 2);
                $recebidos[strtolower(trim($nome))] = trim($valor);
            }
            return strlen($linha);
        },
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
    }
    $resposta = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $recebidos, $resposta];
}

function responder(string $base, array $dados, array $cabecalhos = ['Content-Type: application/json']): array
{
    [$status, , $corpo] = http($base . 'horarios.php', 'POST', json_encode($dados), $cabecalhos);
    return [$status, json_decode($corpo, true) ?? []];
}

function eventos(int $id, string $tipo): int
{
    $stmt = mcp_db()->prepare('SELECT COUNT(*) FROM mcp_eventos WHERE inscricao_id = ? AND tipo = ?');
    $stmt->execute([$id, $tipo]);
    return (int) $stmt->fetchColumn();
}

function preferencia(int $id): ?array
{
    $stmt = mcp_db()->prepare('SELECT horarios, inicio, turma_serve, observacao, vezes FROM mcp_preferencias WHERE inscricao_id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

try {
    // Leitura da tela.
    [$st, , $corpo] = http($base . 'horarios.php?t=' . $a['token']);
    $tela = json_decode($corpo, true) ?? [];
    verificar('GET: tela da inscrição paga', [$st, $tela['nome'] ?? null, $tela['curso']['nome'] ?? null, $tela['turma_inicio'] ?? null, campo($tela, 'resposta')],
        [200, 'Aluna', 'Bombeiro Civil', '2026-10-21', null]);
    verificar('GET: opções da tela', [array_keys($tela['opcoes']['dias'] ?? []), array_keys($tela['opcoes']['periodos'] ?? []), array_keys($tela['opcoes']['inicio'] ?? []),
        count($tela['opcoes']['atalhos'] ?? [])], [['seg', 'ter', 'qua', 'qui', 'sex', 'sab'], ['manha', 'tarde', 'noite'], ['proxima', '1mes', '2meses'], 4]);
    verificar('GET: sem cpf, e-mail nem telefone na tela', str_contains($corpo, $a['cpf']) || str_contains($corpo, $a['email']) || str_contains($corpo, $a['telefone']), false);
    [$st, , $corpo] = http($base . 'horarios.php?t=' . $c['token']);
    verificar('GET: pendente não abre o questionário', [$st, json_decode($corpo, true)['erro'] ?? null],
        [403, 'O questionário abre depois que o pagamento da inscrição é confirmado.']);
    verificar('GET: token desconhecido', http($base . 'horarios.php?t=' . str_repeat('0', 40))[0], 404);
    verificar('GET: token inválido', http($base . 'horarios.php?t=abc')[0], 404);
    [$st, , $corpo] = http($base . 'status.php?t=' . $a['token']);
    verificar('status: convite para o questionário', [$st, json_decode($corpo, true)['horarios'] ?? null],
        [200, ['url' => mcp_url_pagina('horarios', $a['token']), 'respondido' => false, 'resumo' => null]]);
    verificar('status: pendente sem questionário', array_key_exists('horarios', $p = json_decode(http($base . 'status.php?t=' . $c['token'])[2], true) ?? []) && $p['horarios'] === null, true);

    // Envio: proteções e conferência.
    $valido = ['t' => $a['token'], 'horarios' => ['sab-noite', 'seg-noite'], 'inicio' => 'proxima', 'turma_serve' => 'nao', 'observacao' => '  Só depois das 19h  '];
    verificar('POST: exige JSON', http($base . 'horarios.php', 'POST', json_encode($valido), ['Content-Type: text/plain'])[0], 415);
    verificar('POST: recusa outra origem', responder($base, $valido, ['Content-Type: application/json', 'Origin: https://golpe.exemplo.com'])[0], 403);
    verificar('POST: recusa pedido de outro site', responder($base, $valido, ['Content-Type: application/json', 'Sec-Fetch-Site: cross-site'])[0], 403);
    verificar('POST: pendente não grava', responder($base, ['t' => $c['token']] + $valido)[0], 403);
    [$st, $r] = responder($base, ['horarios' => []] + $valido);
    verificar('POST: sem horário volta 422 com o campo', [$st, $r['campo'] ?? null, $r['erro'] ?? null], [422, 'horarios', 'Marque pelo menos um horário em que você consegue vir.']);
    [$st, $r] = responder($base, array_diff_key($valido, ['turma_serve' => 1]));
    verificar('POST: com turma, pergunta se a data serve', [$st, $r['campo'] ?? null], [422, 'turma_serve']);
    verificar('POST: comentário longo demais', responder($base, ['observacao' => str_repeat('a', 501)] + $valido)[1]['campo'] ?? null, 'observacao');
    verificar('POST: nada gravado com erro', preferencia($a['id']), null);

    // Primeira resposta.
    [$st, $r] = responder($base, $valido);
    verificar('POST: primeira resposta', [$st, $r['salvo'] ?? null, $r['primeira'] ?? null, $r['resumo'] ?? null],
        [200, true, true, 'Seg e Sáb à noite · Já na próxima turma']);
    verificar('banco: horários em ordem, recado limpo, 1 vez', preferencia($a['id']),
        ['horarios' => 'seg-noite,sab-noite', 'inicio' => 'proxima', 'turma_serve' => 'nao', 'observacao' => 'Só depois das 19h', 'vezes' => 1]);
    verificar('aviso à secretaria na primeira resposta', eventos($a['id'], 'email_horarios'), 1);

    // Mudança de resposta: atualiza, conta a vez, não avisa de novo.
    [$st, $r] = responder($base, ['t' => $a['token'], 'horarios' => ['ter-tarde', 'ter-manha'], 'inicio' => '1mes', 'turma_serve' => 'sim']);
    verificar('POST: mudança não é primeira', [$st, $r['primeira'] ?? null, $r['resposta']['horarios'] ?? null, campo($r['resposta'] ?? null, 'observacao')], [200, false, ['ter-manha', 'ter-tarde'], null]);
    verificar('banco: resposta nova, 2 vezes', preferencia($a['id']),
        ['horarios' => 'ter-manha,ter-tarde', 'inicio' => '1mes', 'turma_serve' => 'sim', 'observacao' => null, 'vezes' => 2]);
    verificar('sem novo aviso na mudança', eventos($a['id'], 'email_horarios'), 1);
    verificar('registro de cada envio', eventos($a['id'], 'horarios'), 2);
    verificar('status: resumo do que respondeu', json_decode(http($base . 'status.php?t=' . $a['token'])[2], true)['horarios']['resumo'] ?? null,
        'Ter de manhã e à tarde · Daqui a cerca de 1 mês');
    verificar('GET: tela volta com a resposta', json_decode(http($base . 'horarios.php?t=' . $a['token'])[2], true)['resposta']['horarios'] ?? null, ['ter-manha', 'ter-tarde']);

    // Sem turma: "a data serve" não se aplica e não é gravado.
    [$st, $r] = responder($base, ['t' => $b['token'], 'horarios' => ['sab-manha'], 'inicio' => '2meses', 'turma_serve' => 'sim',
        'observacao' => '=HYPERLINK("http://golpe")']);
    verificar('POST: sem turma ignora "a data serve"', [$st, campo($r, 'turma_inicio'), campo(preferencia($b['id']), 'turma_serve')], [200, null, null]);

    // Limite por IP.
    $db->prepare('INSERT INTO mcp_eventos (inscricao_id, tipo, detalhe, criado_em) VALUES ' . implode(', ', array_fill(0, 60, '(?, ?, ?, ?)')))
        ->execute(array_merge(...array_fill(0, 60, [$b['id'], 'horarios', '127.0.0.1', mcp_agora()])));
    verificar('POST: limite por IP', responder($base, ['t' => $b['token'], 'horarios' => ['seg-manha'], 'inicio' => 'proxima'])[0], 429);
    $db->prepare('DELETE FROM mcp_eventos WHERE inscricao_id = ? AND tipo = ?')->execute([$b['id'], 'horarios']);

    // Painel da equipe.
    [$st, , $html] = http($base . 'painel.php?v=horarios');
    verificar('painel sem sessão pede para entrar', [$st, str_contains($html, '<h1>Entrar</h1>'), str_contains($html, $a['nome'])], [200, true, false]);
    [$st, $cab, $html] = http($base . 'painel.php?v=horarios&csv=1');
    verificar('planilha sem sessão não sai', [str_contains($cab['content-type'] ?? '', 'text/csv'), str_contains($html, $a['email'])], [false, false]);
    [$valorCookie] = explode('.', $_COOKIE[MCP_PAINEL_COOKIE], 2);
    verificar('painel com assinatura falsa pede para entrar', str_contains(http($base . 'painel.php?v=horarios', 'GET', null, ['Cookie: ' . MCP_PAINEL_COOKIE . '=' . $valorCookie . '.' . str_repeat('0', 40)])[2], '<h1>Entrar</h1>'), true);

    [$st, , $html] = http($base . 'painel.php?v=horarios', 'GET', null, [$cookie]);
    verificar('painel: página de horários com as duas respostas', [$st, str_contains($html, 'Dias e horários preferidos'), str_contains($html, mcp_escapar($a['nome'])), str_contains($html, mcp_escapar($b['nome'])),
        str_contains($html, mcp_escapar($c['nome']))], [200, true, true, true, false]);
    verificar('painel: aba ativa e link para as mensagens', str_contains($html, 'href="painel.php?v=horarios" aria-current="page">Dias e horários dos alunos</a>') && str_contains($html, 'href="painel.php">Mensagens do chat</a>'), true);
    verificar('painel: mapa conta Ter manhã e Sáb manhã', str_contains($html, 'title="Terça, manhã: 1 aluno(s)"') && str_contains($html, 'title="Sábado, manhã: 1 aluno(s)"')
        && str_contains($html, 'title="Segunda, noite: 0 aluno(s)"') && str_contains($html, 'title="Sábado, noite: 0 aluno(s)"'), true);
    verificar('painel: horários por extenso na lista', str_contains($html, 'Ter de manhã e à tarde') && str_contains($html, 'Sáb de manhã'), true);
    verificar('painel: turma, mudança e comentário escapado', str_contains($html, 'turma de 21/10/2026') && str_contains($html, 'alterado 1x')
        && str_contains($html, '=HYPERLINK(&quot;http://golpe&quot;)'), true);
    verificar('painel: contagem por curso nas pílulas', str_contains($html, 'Bombeiro Civil <span class="n">') && str_contains($html, 'Punção Venosa <span class="n">'), true);
    verificar('painel: botão da planilha', str_contains($html, 'href="painel.php?v=horarios&amp;csv=1"'), true);

    [$st, , $html] = http($base . 'painel.php?v=horarios&curso=puncao-venosa', 'GET', null, [$cookie]);
    verificar('painel: filtro por curso', [str_contains($html, mcp_escapar($b['nome'])), str_contains($html, mcp_escapar($a['nome'])),
        str_contains($html, 'href="painel.php?v=horarios&amp;curso=puncao-venosa&amp;csv=1"')], [true, false, true]);
    verificar('painel: curso desconhecido mostra todos', str_contains(http($base . 'painel.php?v=horarios&curso=%3Cx%3E', 'GET', null, [$cookie])[2], mcp_escapar($a['nome'])), true);

    [$st, $cab, $csv] = http($base . 'painel.php?v=horarios&curso=bombeiro-civil&csv=1', 'GET', null, [$cookie]);
    verificar('planilha: tipo e nome do arquivo', [$st, $cab['content-type'] ?? null, $cab['content-disposition'] ?? null, $cab['cache-control'] ?? null],
        [200, 'text/csv; charset=utf-8', 'attachment; filename="horarios-bombeiro-civil-' . gmdate('Y-m-d') . '.csv"', 'no-store']);
    $f = fopen('php://memory', 'w+');
    fwrite($f, substr($csv, 3));
    rewind($f);
    $linhasCsv = [];
    while (($l = fgetcsv($f, null, ';', '"', '')) !== false) {
        $linhasCsv[] = $l;
    }
    fclose($f);
    verificar('planilha: BOM, cabeçalho e só o curso filtrado', [str_starts_with($csv, "\xEF\xBB\xBF"), count($linhasCsv), $linhasCsv[1][1] ?? null, $linhasCsv[1][5] ?? null, $linhasCsv[1][6] ?? null],
        [true, 2, $a['nome'], '21/10/2026', 'Ter de manhã e à tarde']);
    verificar('planilha: coluna de cada horário', [count($linhasCsv[0]), $linhasCsv[0][13] ?? null, $linhasCsv[1][13] ?? null, $linhasCsv[1][14] ?? null, $linhasCsv[1][15] ?? null],
        [28, 'Ter manhã', 'x', 'x', '']);
    [, , $csvTodos] = http($base . 'painel.php?v=horarios&csv=1', 'GET', null, [$cookie]);
    verificar('planilha: fórmula vira texto', str_contains($csvTodos, "\"'=HYPERLINK(\"\"http://golpe\"\")\""), true);
    verificar('planilha: download registrado', (int) $db->query("SELECT COUNT(*) FROM mcp_eventos WHERE tipo = 'painel_horarios_csv' AND detalhe LIKE 'contato@exemplo.org%'")->fetchColumn() >= 2, true);

    // Quem falta responder aparece no painel.
    $faltam = mcp_horarios_faltam('bombeiro-civil');
    [, , $html] = http($base . 'painel.php?v=horarios&curso=bombeiro-civil', 'GET', null, [$cookie]);
    verificar('painel: quantos pagaram e não responderam', $faltam >= 2 && str_contains($html, '<b>' . $faltam . ' alunos pagaram e ainda não responderam.</b>'), true);

    // Lembretes, como o cron roda: D recebe o 1º, E o 2º; quem respondeu ou não pagou, nada. Rodar de novo não repete.
    $lembretes = static function () use ($raiz, $config, $semEscola): array {
        $saida = [];
        exec('MCP_CONFIG_ARQUIVO=' . escapeshellarg($config) . ' MCP_CONFIG_ESCOLA_ARQUIVO=' . escapeshellarg($semEscola) . ' ' . escapeshellarg(PHP_BINARY)
            . ' -d sendmail_path=/bin/true ' . escapeshellarg($raiz . '/site/matricula-cursos-presenciais/api/lembretes.php') . ' 2>&1', $saida, $codigo);
        return [$codigo, implode("\n", $saida)];
    };
    $detalhes = static function (int $id) use ($db): array {
        $stmt = $db->prepare("SELECT detalhe FROM mcp_eventos WHERE inscricao_id = ? AND tipo = 'lembrete_horarios' ORDER BY id");
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    };
    [$codigo, $saida] = $lembretes();
    verificar('lembretes: rodada sai sem erro', [$codigo, (bool) preg_match('/lembretes: \d+ enviados, 0 falhas/', $saida)], [0, true]);
    verificar('lembretes: D recebe o 1º e E o 2º', [$detalhes($d['id']), $detalhes($e['id'])], [['#1 mail'], ['#1 mail', '#2 mail']]);
    verificar('lembretes: quem respondeu ou não pagou não recebe', [$detalhes($a['id']), $detalhes($b['id']), $detalhes($c['id'])], [[], [], []]);
    $lembretes();
    verificar('lembretes: segunda rodada não repete', [count($detalhes($d['id'])), count($detalhes($e['id']))], [1, 2]);
    verificar('lembretes: por HTTP não roda', http($base . 'lembretes.php')[0], 404);

    $erros = array_values(array_filter(file($logErros) ?: [], static fn(string $l): bool => (bool) preg_match('/PHP (Warning|Notice|Deprecated|Fatal|Parse)/', $l)));
    verificar('sem aviso nem erro do PHP no servidor', $erros, []);
} finally {
    proc_terminate($servidor);
    proc_close($servidor);
    limpar($db, $ids);
    foreach ([$config, $semEscola, $logErros] as $arquivo) {
        @unlink($arquivo);
    }
}

printf("%d testes, %d falhas\n", $total, $falhas);
exit($falhas > 0 ? 1 : 0);
