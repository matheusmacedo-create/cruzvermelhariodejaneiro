#!/usr/bin/env php
<?php
/**
 * Teste de ponta a ponta do questionário de dias e horários, contra um MariaDB LOCAL. Cobre:
 *   - o banco (mcp_preferencias);
 *   - a API (api/horarios.php e o campo horarios de api/status.php);
 *   - o portal da secretaria (api/painel.php): início, inscrições com filtros, busca e planilha, ficha com o
 *     lembrete à mão, horários dos alunos com o mapa e a planilha;
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

/** Apaga as inscrições do teste, as respostas, os registros e o que o teste fez no portal (planilhas, lembretes). */
function limpar(PDO $db, array $ids): void
{
    $lista = implode(', ', array_map('intval', $ids));
    if ($lista !== '') {
        $db->exec("DELETE FROM mcp_preferencias WHERE inscricao_id IN ($lista)");
        $db->exec("DELETE FROM mcp_eventos WHERE inscricao_id IN ($lista)");
        $db->exec("DELETE FROM mcp_inscricoes WHERE id IN ($lista)");
    }
    $db->exec("DELETE FROM mcp_eventos WHERE tipo IN ('painel_horarios_csv', 'painel_inscricoes_csv', 'painel_lembrete') AND detalhe LIKE '%contato@exemplo.org%'");
}

// ----------------------------------------------------------------------------- inscrições fictícias
$db = mcp_db();
// Sobras de uma execução interrompida: as inscrições deste teste têm e-mail [a-g]-xxxxxx@exemplo.org.
$sobras = $db->prepare('SELECT id FROM mcp_inscricoes WHERE email REGEXP ? AND nome LIKE ?');
$sobras->execute(['^[a-g]-[0-9a-f]{6}@exemplo[.]org$', 'Alun_ Teste _ %']);
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
// Portal: F foi recusada pela escola (e-mail de outra conta), G ficou sem turma aberta, tem telefone próprio e HTML no nome.
$recusada = $criar(['curso_slug' => 'bombeiro-civil', 'curso_nome' => 'Bombeiro Civil', 'nome' => "Aluna Teste F $sufixo", 'email' => "f-$sufixo@exemplo.org",
    'escola_status' => 'erro', 'escola_acesso' => json_encode(['erro' => 'email_em_uso'])]);
$semTurma = $criar(['curso_slug' => 'puncao-venosa', 'curso_nome' => 'Punção Venosa', 'nome' => "Aluno Teste G $sufixo <i>x</i>", 'email' => "g-$sufixo@exemplo.org",
    'telefone' => '21977776543', 'escola_status' => 'ok', 'escola_acesso' => json_encode(['resultado' => 'sem_turma', 'aluno_novo' => true, 'email_confere' => true,
        'turma_inicio' => null, 'aviso' => null, 'url_login' => 'https://escola.cursoscruzvermelha.org/login'])]);
$db->prepare("INSERT INTO mcp_eventos (inscricao_id, tipo, detalhe, criado_em) VALUES (?, 'pago', '', ?), (?, 'escola_erro', 'tentativa 1 · HTTP 200 · email_em_uso', ?)")
    ->execute([$recusada['id'], $agora, $recusada['id'], $agora]);

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
    verificar('painel: aba ativa no menu e link para as mensagens', str_contains($html, 'href="painel.php?v=horarios" aria-current="page"><svg') && str_contains($html, '<span>Horários dos alunos</span>')
        && str_contains($html, 'href="painel.php?v=mensagens"><svg') && str_contains($html, '<span>Mensagens do chat</span>'), true);
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

    // Quem falta responder aparece no painel, com o caminho para a lista e o lembrete.
    $faltam = mcp_secretaria_contar('bombeiro-civil')['sem_horarios'];
    [, , $html] = http($base . 'painel.php?v=horarios&curso=bombeiro-civil', 'GET', null, [$cookie]);
    verificar('painel: quantos pagaram e não responderam', $faltam >= 3 && str_contains($html, '<b>' . $faltam . ' alunos pagaram e ainda não disseram os horários.</b>')
        && str_contains($html, 'href="painel.php?v=inscricoes&amp;f=sem_horarios&amp;curso=bombeiro-civil">Ver quem falta e mandar lembrete →</a>'), true);

    // Portal da secretaria: início, menu, inscrições, ficha, lembrete à mão e planilha.
    $detalhes = static function (int $id) use ($db): array {
        $stmt = $db->prepare("SELECT detalhe FROM mcp_eventos WHERE inscricao_id = ? AND tipo = 'lembrete_horarios' ORDER BY id");
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    };
    $contas = mcp_secretaria_contar();
    [$st, , $html] = http($base . 'painel.php', 'GET', null, [$cookie]);
    verificar('portal: início com os números', [$st, str_contains($html, '<h1>Início</h1>'), str_contains($html, 'href="painel.php" aria-current="page"><svg'),
        str_contains($html, '<b>' . $contas['atencao'] . '</b><span>Precisam de atenção</span>'), str_contains($html, '<b>' . $contas['sem_horarios'] . '</b><span>Sem horários</span>'),
        str_contains($html, 'href="painel.php?v=inscricoes&amp;f=atencao"')], [200, true, true, true, true, true]);
    verificar('portal: menu com os números de cada seção e a escola', [
        str_contains($html, '<span>Inscrições</span><span class="badge" title="' . $contas['atencao'] . ' precisam de atenção">'),
        str_contains($html, '<span>Horários dos alunos</span><span class="badge suave" title="' . $contas['sem_horarios'] . ' ainda sem horários">'),
        str_contains($html, 'href="' . mcp_escapar(rtrim((string) mcp_cfg('ESCOLA_URL', 'https://escola.cursoscruzvermelha.org'), '/') . '/login') . '" target="_blank" rel="noopener">'),
    ], [true, true, true]);
    verificar('portal: últimas pagas no início, com a situação na escola', [str_contains($html, 'href="painel.php?v=inscricao&amp;id=' . $recusada['id'] . '">'),
        str_contains($html, '<span class="selo erro">Não matriculado</span>'), str_contains($html, mcp_escapar($semTurma['nome'])), str_contains($html, '<i>x</i>')], [true, true, true, false]);

    $lista = static fn(string $query): string => http($base . 'painel.php?v=inscricoes' . $query, 'GET', null, [$cookie])[2];
    $todos = [$a, $b, $c, $d, $e, $recusada, $semTurma];
    $quem = static fn(string $html): array => array_map(static fn(array $p): bool => str_contains($html, mcp_escapar($p['nome'])), $todos);
    $html = $lista('&q=' . $sufixo);
    verificar('inscrições: padrão são as pagas', [str_contains($html, '<h1>Inscrições</h1>'), str_contains($html, 'href="painel.php?v=inscricoes" aria-current="page"><svg'), $quem($html)],
        [true, true, [true, true, false, true, true, true, true]]);
    verificar('inscrições: contagem de cada filtro com a busca', [str_contains($html, 'Pagas <span class="n">6</span>'), str_contains($html, 'Precisam de atenção <span class="n">2</span>'),
        str_contains($html, 'Sem horários <span class="n">4</span>'), str_contains($html, 'Aguardando pagamento <span class="n">1</span>'), str_contains($html, 'Todas <span class="n">7</span>')],
        [true, true, true, true, true]);
    verificar('inscrições: horários e lembretes na lista', [str_contains($html, 'Ter de manhã e à tarde'), str_contains($html, '<span class="selo neutro">Não respondeu</span><small>1 lembrete enviado</small>')], [true, true]);
    $atencao = $lista('&f=atencao&q=' . $sufixo);
    verificar('inscrições: filtros', [$quem($atencao), $quem($lista('&f=sem_horarios&q=' . $sufixo)), $quem($lista('&f=pendentes&q=' . $sufixo)),
        $quem($lista('&f=todas&curso=puncao-venosa&q=' . $sufixo)), $quem($lista('&f=%3Cx%3E&curso=%3Cx%3E&q=' . $sufixo))], [
        [false, false, false, false, false, true, true],
        [false, false, false, true, true, true, true],
        [false, false, true, false, false, false, false],
        [false, true, false, false, false, false, true],
        [true, true, false, true, true, true, true],
    ]);
    verificar('inscrições: situação na escola e nome escapado', [str_contains($atencao, '<span class="selo erro">Não matriculado</span>'), str_contains($atencao, '<span class="selo alerta">Sem turma aberta</span>'),
        str_contains($atencao, '&lt;i&gt;x&lt;/i&gt;'), str_contains($atencao, '<i>x</i>')], [true, true, true, false]);
    verificar('inscrições: busca por e-mail, CPF e telefone', [$quem($lista('&f=todas&q=' . rawurlencode("d-$sufixo@"))), $quem($lista('&f=todas&q=' . rawurlencode(mcp_cpf_formatado($b['cpf'])))),
        $quem($lista('&f=todas&q=' . rawurlencode('(21) 97777-6543')))], [
        [false, false, false, true, false, false, false],
        [false, true, false, false, false, false, false],
        [false, false, false, false, false, false, true],
    ]);
    verificar('inscrições: % na busca não é curinga', str_contains($lista('&f=todas&q=%25'), 'Nenhuma inscrição aqui.'), true);

    [$st, $cab, $csv] = http($base . 'painel.php?v=inscricoes&f=todas&q=' . $sufixo . '&csv=1', 'GET', null, [$cookie]);
    verificar('planilha de inscrições: tipo e nome do arquivo', [$st, $cab['content-type'] ?? null, $cab['content-disposition'] ?? null, $cab['cache-control'] ?? null],
        [200, 'text/csv; charset=utf-8', 'attachment; filename="inscricoes-todas-' . gmdate('Y-m-d') . '.csv"', 'no-store']);
    $fh = fopen('php://memory', 'w+');
    fwrite($fh, substr($csv, 3));
    rewind($fh);
    $linhasInscricoes = [];
    while (($l = fgetcsv($fh, null, ';', '"', '')) !== false) {
        $linhasInscricoes[] = $l;
    }
    fclose($fh);
    $porNome = array_column(array_slice($linhasInscricoes, 1), null, 2);
    verificar('planilha de inscrições: 7 linhas, 12 colunas, sem CPF', [str_starts_with($csv, "\xEF\xBB\xBF"), count($linhasInscricoes), count($linhasInscricoes[0]),
        array_values(array_filter($todos, static fn(array $p): bool => str_contains($csv, $p['cpf'])))], [true, 8, 12, []]);
    verificar('planilha de inscrições: situação, escola, horários e fórmula', [$porNome[$a['nome']][1] ?? null, $porNome[$a['nome']][9] ?? null, $porNome[$c['nome']][1] ?? null,
        $porNome[$d['nome']][9] ?? null, $porNome[$recusada['nome']][8] ?? null, $porNome[$semTurma['nome']][8] ?? null, $porNome[$b['nome']][11] ?? null],
        ['Paga', 'Ter de manhã e à tarde', 'Aguardando pagamento', 'não respondeu', 'Não matriculado', 'Sem turma aberta', "'=HYPERLINK(\"http://golpe\")"]);
    verificar('planilha de inscrições: download registrado', (int) $db->query("SELECT COUNT(*) FROM mcp_eventos WHERE tipo = 'painel_inscricoes_csv' AND detalhe = 'contato@exemplo.org · todas · com busca'")->fetchColumn(), 1);

    $ficha = static fn(int $id, string $extra = ''): array => http($base . 'painel.php?v=inscricao&id=' . $id . $extra, 'GET', null, [$cookie]);
    [$st, , $html] = $ficha($recusada['id']);
    verificar('ficha: aluno, CPF, escola e o que fazer', [$st, str_contains($html, '<h1>' . mcp_escapar($recusada['nome']) . '</h1>'), str_contains($html, mcp_cpf_formatado($recusada['cpf'])),
        str_contains($html, '<span class="selo erro">Não matriculado</span>'), str_contains($html, 'o e-mail já está em outra conta da escola, com outro CPF'),
        str_contains($html, 'href="painel.php?v=inscricoes" aria-current="page"><svg')], [200, true, true, true, true, true]);
    verificar('ficha: histórico', [str_contains($html, 'Pagamento confirmado'), str_contains($html, 'Escola: a matrícula não foi feita (o e-mail já está em outra conta da escola, com outro CPF)')], [true, true]);
    $tokenLembrete = mcp_painel_csrf('contato@exemplo.org', 'lembrete_horarios', $recusada['id']);
    verificar('ficha: sem resposta, oferece o lembrete à mão', [str_contains($html, 'Ainda não respondeu'), str_contains($html, 'Nenhum lembrete enviado ainda.'),
        str_contains($html, '<input type="hidden" name="t" value="' . $tokenLembrete . '">'), str_contains($html, 'Mandar lembrete agora')], [true, true, true, true]);

    $lembrar = static fn(int $id, string $token, array $cab = []): array => http($base . 'painel.php', 'POST', http_build_query(['acao' => 'lembrete_horarios', 'id' => $id, 't' => $token]),
        array_merge(['Content-Type: application/x-www-form-urlencoded'], $cab));
    [$st, $cab] = $lembrar($recusada['id'], $tokenLembrete, [$cookie]);
    verificar('lembrete à mão: envia e volta para a ficha', [$st, $cab['location'] ?? null, $detalhes($recusada['id'])],
        [303, 'painel.php?v=inscricao&id=' . $recusada['id'] . '&ok=lb_ok', ['#1 manual contato@exemplo.org · mail']]);
    [, , $html] = $ficha($recusada['id'], '&ok=lb_ok');
    verificar('ficha: aviso, histórico e espera de 24 h depois do lembrete', [str_contains($html, 'Lembrete de horários enviado ao aluno por e-mail.'),
        str_contains($html, 'Lembrete de horários enviado ao aluno, à mão, por contato@exemplo.org'), str_contains($html, '1 lembrete enviado, o último em'),
        str_contains($html, 'Mandar lembrete agora'), str_contains($html, 'Um novo lembrete pode sair a partir de')], [true, true, true, false, true]);
    verificar('lembrete à mão: outro em menos de 24 h não sai', [$lembrar($recusada['id'], $tokenLembrete, [$cookie])[1]['location'] ?? null, count($detalhes($recusada['id']))],
        ['painel.php?v=inscricao&id=' . $recusada['id'] . '&ok=lb_recente', 1]);
    verificar('lembrete à mão: quem respondeu ou não pagou não recebe', [
        $lembrar($a['id'], mcp_painel_csrf('contato@exemplo.org', 'lembrete_horarios', $a['id']), [$cookie])[1]['location'] ?? null,
        $lembrar($c['id'], mcp_painel_csrf('contato@exemplo.org', 'lembrete_horarios', $c['id']), [$cookie])[1]['location'] ?? null, $detalhes($a['id']), $detalhes($c['id'])],
        ['painel.php?v=inscricao&id=' . $a['id'] . '&ok=lb_resp', 'painel.php?v=inscricao&id=' . $c['id'] . '&ok=lb_naopago', [], []]);
    verificar('lembrete à mão: token de outra inscrição ou sem sessão não sai', [str_contains($lembrar($d['id'], $tokenLembrete, [$cookie])[2], '<h1>Entrar</h1>'),
        str_contains($lembrar($d['id'], mcp_painel_csrf('contato@exemplo.org', 'lembrete_horarios', $d['id']))[2], '<h1>Entrar</h1>'), $detalhes($d['id'])], [true, true, []]);
    verificar('lembrete à mão: cada pedido registrado no portal', (int) $db->query("SELECT COUNT(*) FROM mcp_eventos WHERE tipo = 'painel_lembrete' AND detalhe LIKE '%contato@exemplo.org%'")->fetchColumn(), 4);

    [, , $html] = $ficha($a['id']);
    $tempo = preg_match('#<ul class="tempo">(.*?)</ul>#s', $html, $m) ? $m[1] : '';
    verificar('ficha: resposta dos horários e histórico sem IP', [str_contains($html, 'Ter de manhã e à tarde'), str_contains($html, 'Daqui a cerca de 1 mês'), str_contains($html, '(mudou 1x)'),
        str_contains($html, 'Mandar lembrete agora'), substr_count($tempo, 'O aluno salvou os horários'), str_contains($tempo, '127.0.0.1')], [true, true, true, false, 2, false]);
    [, , $html] = $ficha($semTurma['id']);
    verificar('ficha: nome escapado e sem turma aberta', [str_contains($html, '&lt;i&gt;x&lt;/i&gt;'), str_contains($html, '<i>x</i>'), str_contains($html, '<span class="selo alerta">Sem turma aberta</span>'),
        str_contains($html, 'quando abrir turma')], [true, false, true, true]);
    [$st, $cab] = $ficha(999999999);
    verificar('ficha: inscrição que não existe volta para a lista', [$st, $cab['location'] ?? null], [303, 'painel.php?v=inscricoes']);
    verificar('ficha e inscrições sem sessão pedem para entrar', [str_contains(http($base . 'painel.php?v=inscricao&id=' . $a['id'])[2], '<h1>Entrar</h1>'),
        str_contains(http($base . 'painel.php?v=inscricoes')[2], '<h1>Entrar</h1>'), str_contains(http($base . 'painel.php?v=inscricoes&csv=1')[1]['content-type'] ?? '', 'text/csv')], [true, true, false]);

    [$st, , $html] = http($base . 'painel.php?v=mensagens', 'GET', null, [$cookie]);
    verificar('portal: mensagens do chat', [$st, str_contains($html, '<h1>Mensagens recebidas</h1>'), str_contains($html, 'href="painel.php?v=mensagens" aria-current="page"><svg')], [200, true, true]);
    verificar('portal: endereço antigo das mensagens continua valendo', str_contains(http($base . 'painel.php?f=novo', 'GET', null, [$cookie])[2], '<h1>Mensagens recebidas</h1>'), true);

    // Leitura pelo painel da escola (api/escola-horarios.php). A configuração é lida a cada pedido:
    // sem a chave no config-escola.php o endereço não existe; com ela, só a chave certa lê.
    $escolaUrl = $base . 'escola-horarios.php';
    verificar('escola: sem chave configurada responde 404', http($escolaUrl)[0], 404);
    $chaveEscola = bin2hex(random_bytes(24));
    file_put_contents($semEscola, '<?php return [\'SITE_HORARIOS_TOKEN\' => ' . var_export($chaveEscola, true) . '];');
    verificar('escola: sem cabeçalho, 401', http($escolaUrl)[0], 401);
    verificar('escola: chave errada, 401', http($escolaUrl, 'GET', null, ['Authorization: Bearer ' . str_repeat('x', 48)])[0], 401);
    verificar('escola: POST recusado', http($escolaUrl, 'POST', '{}', ['Authorization: Bearer ' . $chaveEscola, 'Content-Type: application/json'])[0], 405);
    [$st, , $corpo] = http($escolaUrl, 'GET', null, ['Authorization: Bearer ' . $chaveEscola]);
    $lido = json_decode($corpo, true);
    $porInscricao = static fn(string $lista): array => array_column($lido[$lista] ?? [], null, 'inscricao_id');
    $respostas = $porInscricao('respostas');
    $faltam = $porInscricao('sem_resposta');
    verificar('escola: chave certa lê', [$st, $lido['ok'] ?? null], [200, true]);
    verificar('escola: resposta de A com turma e curso da escola', [campo($respostas[$a['id']] ?? null, 'horarios'), campo($respostas[$a['id']] ?? null, 'inicio'),
        campo($respostas[$a['id']] ?? null, 'turma_serve'), campo($respostas[$a['id']] ?? null, 'turma_inicio'), campo($respostas[$a['id']] ?? null, 'curso_id')],
        [['ter-manha', 'ter-tarde'], '1mes', 'sim', '2026-10-21', mcp_curso('bombeiro-civil')['uuid']]);
    verificar('escola: B respondeu, D e E faltam, C (pendente) fora', [isset($respostas[$b['id']]), isset($faltam[$d['id']]), isset($faltam[$e['id']]),
        isset($respostas[$c['id']]), isset($faltam[$c['id']])], [true, true, true, false, false]);
    verificar('escola: nunca CPF nem token', [str_contains($corpo, $a['cpf']), str_contains($corpo, $d['cpf']), str_contains($corpo, $a['token'])], [false, false, false]);
    $db->exec("DELETE FROM mcp_eventos WHERE tipo = 'escola_horarios_negado' AND detalhe = '127.0.0.1'");
    file_put_contents($semEscola, '<?php return [];');

    // Lembretes, como o cron roda: D recebe o 1º, E o 2º; quem respondeu ou não pagou, nada. Rodar de novo não repete.
    $lembretes = static function () use ($raiz, $config, $semEscola): array {
        $saida = [];
        exec('MCP_CONFIG_ARQUIVO=' . escapeshellarg($config) . ' MCP_CONFIG_ESCOLA_ARQUIVO=' . escapeshellarg($semEscola) . ' ' . escapeshellarg(PHP_BINARY)
            . ' -d sendmail_path=/bin/true ' . escapeshellarg($raiz . '/site/matricula-cursos-presenciais/api/lembretes.php') . ' 2>&1', $saida, $codigo);
        return [$codigo, implode("\n", $saida)];
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
