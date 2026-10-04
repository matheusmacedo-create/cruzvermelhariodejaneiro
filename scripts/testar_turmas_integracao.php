#!/usr/bin/env php
<?php
/**
 * Teste de ponta a ponta das turmas sob demanda, contra um MariaDB LOCAL. Cobre:
 *   - a API (api/turmas.php): turma fechada, lista de interesse, matrícula na hora em português, erros por campo,
 *     armadilha e origem de outro site;
 *   - o banco (mcp_turmas_pedidos): protocolo, tipo, local fora da sede, soma das listas;
 *   - o portal da secretaria (api/painel.php?v=turmas): número no menu, pedidos de turma fechada, listas com a
 *     soma, a lista de um curso, a planilha, a mudança de situação de um pedido e de uma lista inteira.
 * As chamadas passam pelo servidor embutido do PHP, como as de um navegador. Nenhum e-mail sai: o teste recusa
 * RESEND_API_KEY, e o servidor roda com sendmail_path=/bin/true.
 *
 * Uso: MCP_CONFIG_ARQUIVO=/caminho/config-teste.php php scripts/testar_turmas_integracao.php
 * (o mesmo arquivo de configuração de scripts/testar_horarios_integracao.php, com DB_HOST local).
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$configOriginal = (string) getenv('MCP_CONFIG_ARQUIVO');
if ($configOriginal === '' || !is_file($configOriginal)) {
    fwrite(STDERR, "Falta MCP_CONFIG_ARQUIVO com o banco local (veja o cabeçalho deste arquivo).\n");
    exit(2);
}
$config = tempnam(sys_get_temp_dir(), 'mcp-turmas-');
file_put_contents($config, '<?php return [\'EMAIL_CONTATO\' => \'contato@exemplo.org\', \'EMAIL_SECRETARIA\' => \'secretaria@exemplo.org\', \'PAINEL_EMAILS\' => \'contato@exemplo.org\'] + (require '
    . var_export($configOriginal, true) . ');');
putenv("MCP_CONFIG_ARQUIVO=$config");
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

ignore_user_abort(true);

/** Os pedidos deste teste têm e-mail t-xxxxxx-N@exemplo.org; sai tudo, inclusive sobras de uma execução interrompida. */
function limpar(PDO $db): void
{
    $db->exec("DELETE FROM mcp_turmas_pedidos WHERE email REGEXP '^t-[0-9a-f]{6}-[0-9]+@exemplo[.]org$'");
    $db->exec("DELETE FROM mcp_eventos WHERE tipo IN ('turma_pedido', 'painel_turma_status', 'painel_turma_lista', 'painel_turmas_csv', 'armadilha') AND (detalhe LIKE '%contato@exemplo.org%' OR detalhe LIKE 'turmas · %' OR detalhe REGEXP '^#[0-9]+ · (fechada|lista) ')");
}

$db = mcp_db();
limpar($db);
$sufixo = bin2hex(random_bytes(3));

$porta = 18700 + random_int(0, 999);
$logErros = tempnam(sys_get_temp_dir(), 'mcp-turmas-php-');
$servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$porta", '-t', $raiz . '/site', '-d', 'sendmail_path=/bin/true',
    '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$logErros"],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $tubos,
    null, ['MCP_CONFIG_ARQUIVO' => $config, 'MCP_CATALOGO_ARQUIVO' => $raiz . '/site/matricula-cursos-presenciais/cursos.json', 'PATH' => (string) getenv('PATH')]);
$base = "http://127.0.0.1:$porta/matricula-cursos-presenciais/api/";
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

$n = 0;
/** Pedido completo e válido, com e-mail novo a cada chamada; $troca muda ou tira (null) campos. */
$pedir = static function (array $troca = [], array $cabecalhos = ['Content-Type: application/json']) use ($base, $sufixo, &$n): array {
    $n++;
    $corpo = array_filter($troca + [
        'curso' => 'primeiros-socorros-basico', 'idioma' => 'en', 'pessoas' => 3, 'local' => 'sede', 'local_endereco' => '',
        'organizacao' => '', 'periodo' => '', 'nome' => "Pessoa Teste $n", 'email' => "t-$sufixo-$n@exemplo.org", 'telefone' => '(21) 99999-0000',
        'observacoes' => '', 'consentimento' => true, 'pagina' => '/matricula-cursos-presenciais/', 'origem' => [], 'site' => '',
    ], static fn($v): bool => $v !== null);
    [$status, , $resposta] = http($base . 'turmas.php', 'POST', json_encode($corpo), $cabecalhos);
    return [$status, json_decode($resposta, true) ?? []];
};
$pedido = static function (string $protocolo) use ($db): array {
    $stmt = $db->prepare('SELECT * FROM mcp_turmas_pedidos WHERE protocolo = ?');
    $stmt->execute([$protocolo]);
    return $stmt->fetch() ?: [];
};

try {
    // ------------------------------------------------------------------ API
    [$st, $d] = $pedir(['pessoas' => 22, 'local' => 'outro', 'local_endereco' => 'Barra da Tijuca, Rio de Janeiro', 'organizacao' => 'Empresa Exemplo']);
    $fechada = $pedido((string) ($d['protocolo'] ?? ''));
    verificar('api: 22 alunos é turma fechada, com protocolo TS-', [$st, $d['tipo'] ?? null, (bool) preg_match('/^TS-\d{6}-\d{4,}$/', (string) ($d['protocolo'] ?? '')), $d['curso'] ?? null],
        [201, 'fechada', true, 'Primeiros Socorros Básico, em inglês']);
    verificar('banco: turma fechada fora da sede, com instituição e os e-mails tentados', [$fechada['tipo'] ?? null, $fechada['local'] ?? null, $fechada['local_endereco'] ?? null,
        $fechada['organizacao'] ?? null, (int) ($fechada['pessoas'] ?? 0), $fechada['email_equipe'] !== null, $fechada['status'] ?? null],
        ['fechada', 'outro', 'Barra da Tijuca, Rio de Janeiro', 'Empresa Exemplo', 22, true, 'novo']);

    [$st, $d] = $pedir(['pessoas' => 4, 'local' => 'outro', 'local_endereco' => 'Niterói']);
    $lista = $pedido((string) ($d['protocolo'] ?? ''));
    verificar('api: 4 alunos em inglês entram na lista; lista é sempre na sede', [$st, $d['tipo'] ?? null, $lista['local'] ?? null, $lista['local_endereco'] ?? null],
        [201, 'lista', 'sede', null]);

    [$st, $d] = $pedir(['idioma' => 'pt', 'pessoas' => 2]);
    verificar('api: curso do catálogo em português com 2 alunos leva à matrícula na hora', [$st, $d['campo'] ?? null, $d['matricula'] ?? null],
        [422, 'pessoas', '/matricula-cursos-presenciais/checkout/?curso=primeiros-socorros-basico']);
    [$st, $d] = $pedir(['curso' => 'primeiros-socorros-jovens', 'idioma' => 'pt', 'pessoas' => 6, 'organizacao' => 'Escola Exemplo']);
    verificar('api: jovens em português entram na lista (o curso só existe sob demanda)', [$st, $d['tipo'] ?? null, $d['curso'] ?? null],
        [201, 'lista', 'Primeiros Socorros para Jovens (12 a 14 anos)']);

    $erros = [];
    foreach ([['curso' => 'nao-existe'], ['pessoas' => 0], ['pessoas' => '12a'], ['pessoas' => 301], ['nome' => 'A'], ['email' => 'sem-arroba'],
        ['telefone' => '123'], ['consentimento' => null], ['consentimento' => 'sim'], ['pessoas' => 20, 'local' => 'outro', 'local_endereco' => 'RJ'],
        ['observacoes' => str_repeat('x', 2001)], ['idioma' => 'es']] as $troca) {
        [$st, $d] = $pedir($troca);
        $erros[] = [$st, $d['campo'] ?? null];
    }
    verificar('api: cada erro volta com o campo certo', $erros, [[422, 'curso'], [422, 'pessoas'], [422, 'pessoas'], [422, 'pessoas'], [422, 'nome'], [422, 'email'],
        [422, 'telefone'], [422, 'consentimento'], [422, 'consentimento'], [422, 'local_endereco'], [422, 'observacoes'], [422, 'idioma']]);
    verificar('api: armadilha preenchida não grava', [$pedir(['site' => 'http://spam'])[0], (int) $db->query("SELECT COUNT(*) FROM mcp_turmas_pedidos WHERE email LIKE 't-$sufixo-%'")->fetchColumn()], [422, 3]);
    verificar('api: outro site não envia', $pedir([], ['Content-Type: application/json', 'Origin: https://outro-site.exemplo'])[0], 403);
    verificar('api: só POST JSON', [http($base . 'turmas.php')[0], $pedir([], ['Content-Type: text/plain'])[0]], [405, 415]);

    // Reenvio do mesmo e-mail (a confirmação foi para o spam, mudou o número de alunos) não infla a lista: conta o maior.
    $emailRepetido = "t-$sufixo-99@exemplo.org";
    $pedir(['curso' => 'primeiros-socorros-basico', 'idioma' => 'en', 'pessoas' => 2, 'email' => $emailRepetido]);
    $pedir(['curso' => 'primeiros-socorros-basico', 'idioma' => 'en', 'pessoas' => 5, 'email' => $emailRepetido]);
    $basico = mcp_turma_demanda('primeiros-socorros-basico', 'en')[0] ?? [];
    verificar('banco: o mesmo e-mail conta uma vez na lista, pelo maior pedido', [$basico['pessoas'] ?? null, $basico['pedidos'] ?? null], [4 + 5, 2]);

    // A lista dos jovens chega a 15 com outro pedido de 9: o aviso à secretaria vira "lista completa".
    [$st, $d] = $pedir(['curso' => 'primeiros-socorros-jovens', 'idioma' => 'pt', 'pessoas' => 9]);
    $demanda = mcp_turma_demanda('primeiros-socorros-jovens', 'pt')[0] ?? [];
    verificar('banco: a lista dos jovens soma 15 em 2 pedidos', [$st, $demanda['pessoas'] ?? null, $demanda['pedidos'] ?? null], [201, 15, 2]);
    $aviso = mcp_montar_email_turma_equipe($pedido((string) $d['protocolo']), mcp_turma_progresso(15));
    verificar('e-mail: secretaria recebe "lista completa" com o portal da lista', [str_starts_with($aviso['assunto'], '[Lista completa]'),
        str_contains($aviso['html'], 'v=turmas&amp;curso=primeiros-socorros-jovens&amp;idioma=pt')], [true, true]);

    // ------------------------------------------------------------------ portal
    [$st, , $html] = http($base . 'painel.php?v=turmas', 'GET', null, [$cookie]);
    preg_match('/Turmas sob demanda<\/span><span class="badge[^"]*"[^>]*>(\d+)/', $html, $badge);
    verificar('portal: número no menu soma turma fechada nova e lista completa', [$st, $badge[1] ?? null], [200, '2']);
    verificar('portal: pedido de turma fechada aparece com o local fora da sede', [str_contains($html, 'Pessoa Teste 1'), str_contains($html, 'Empresa Exemplo'),
        str_contains($html, 'Fora da sede'), str_contains($html, 'Pessoa Teste 2')], [true, true, true, false]);
    verificar('portal: listas com soma e a dos jovens pronta para abrir', [str_contains($html, 'Primeiros Socorros para Jovens (12 a 14 anos)'),
        str_contains($html, 'Pronta para abrir'), str_contains($html, 'Primeiros Socorros Básico, em inglês')], [true, true, true]);

    [$st, , $html] = http($base . 'painel.php?v=turmas&curso=primeiros-socorros-jovens&idioma=pt', 'GET', null, [$cookie]);
    verificar('portal: a lista de um curso mostra só quem está nela', [$st, str_contains($html, 'Escola Exemplo'), str_contains($html, 'Empresa Exemplo'),
        str_contains($html, 'Mudar todos')], [200, true, false, true]);
    [$st, $cab, $csv] = http($base . 'painel.php?v=turmas&curso=primeiros-socorros-jovens&idioma=pt&csv=1', 'GET', null, [$cookie]);
    verificar('portal: planilha da lista, com BOM e sem IP', [$st, str_contains((string) ($cab['content-type'] ?? ''), 'text/csv'), str_starts_with($csv, "\xEF\xBB\xBF"),
        substr_count($csv, "\n"), str_contains($csv, '127.0.0.1')], [200, true, true, 3, false]);
    verificar('portal: sem sessão, pede para entrar', str_contains(http($base . 'painel.php?v=turmas')[2], '<h1>Entrar</h1>'), true);

    $id = (int) $fechada['id'];
    $mudar = static fn(string $acao, int $alvo, string $status, string $t, array $extra = []): array => http($base . 'painel.php', 'POST',
        http_build_query(['acao' => $acao, 'id' => $alvo, 'status' => $status, 't' => $t] + $extra), [$cookie, 'Content-Type: application/x-www-form-urlencoded']);
    [$st, $cab] = $mudar('turma_status', $id, 'em_contato', mcp_painel_csrf('contato@exemplo.org', 'turma_status', $id));
    verificar('portal: situação do pedido muda e volta para a lista', [$st, $cab['location'] ?? null, $pedido((string) $fechada['protocolo'])['status'] ?? null,
        $pedido((string) $fechada['protocolo'])['status_por'] ?? null], [303, 'painel.php?v=turmas&ok=ts_ok', 'em_contato', 'contato@exemplo.org']);
    $mudar('turma_status', $id, 'arquivado', 'token-errado');
    verificar('portal: token errado não muda nada', $pedido((string) $fechada['protocolo'])['status'] ?? null, 'em_contato');
    // A secretaria vê a lista dos jovens; antes do clique em "Mudar todos", chega mais um pedido (fica de fora).
    preg_match('/name="ate" value="(\d+)"/', $html, $ate);
    // Direto no banco: pelo formulário, seria o 7º pedido do mesmo IP na hora, e o freio (6) recusaria.
    $tarde = mcp_turma_conferir(['curso' => 'primeiros-socorros-jovens', 'idioma' => 'pt', 'pessoas' => 2, 'nome' => 'Pessoa Tarde',
        'email' => "t-$sufixo-98@exemplo.org", 'telefone' => '21999990000', 'consentimento' => true]);
    mcp_turma_gravar($tarde['dados']);
    [$st, $cab] = $mudar('turma_lista', 0, 'turma_marcada', mcp_painel_csrf('contato@exemplo.org', 'turma_lista', 0), ['curso' => 'primeiros-socorros-jovens', 'idioma' => 'pt', 'ate' => $ate[1] ?? '0']);
    verificar('portal: "Mudar todos" marca só quem estava na tela; quem entrou depois continua na lista', [$st, $cab['location'] ?? null,
        mcp_turma_demanda('primeiros-socorros-jovens', 'pt')[0]['pessoas'] ?? null, count(mcp_turma_demanda('primeiros-socorros-basico', 'en'))],
        [303, 'painel.php?v=turmas&curso=primeiros-socorros-jovens&idioma=pt&ok=ts_lista', 2, 1]);
    verificar('portal: "Mudar todos" sem o limite da tela não muda nada', mcp_turma_status_lista('primeiros-socorros-jovens', 'pt', 'arquivado', 'teste', 0), 0);
    $db->exec("UPDATE mcp_turmas_pedidos SET status = 'arquivado' WHERE email = 't-$sufixo-98@exemplo.org'");
    verificar('portal: nada mais pede ação', mcp_turma_contar(), ['fechadas_novas' => 0, 'listas_prontas' => 0, 'acao' => 0]);

    $errosPhp = array_values(array_filter(file($logErros) ?: [], static fn(string $l): bool => (bool) preg_match('/PHP (Warning|Notice|Deprecated|Fatal|Parse)|erro não tratado/', $l)));
    verificar('sem aviso nem erro do PHP no servidor', $errosPhp, []);
} finally {
    proc_terminate($servidor);
    proc_close($servidor);
    limpar($db);
    foreach ([$config, $logErros] as $arquivo) {
        @unlink($arquivo);
    }
}

printf("%d testes, %d falhas\n", $total, $falhas);
exit($falhas > 0 ? 1 : 0);
