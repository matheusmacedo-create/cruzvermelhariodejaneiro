#!/usr/bin/env php
<?php
/**
 * Teste da recuperação do funil (api/lib/recuperacao_funil.php e api/recuperacao.php), contra um MariaDB LOCAL:
 *   - agrupamento da pessoa por CPF, e-mail (sem diferença de maiúsculas) e telefone (só dígitos);
 *   - quem sai: pagou OUTRO curso (mesmo fora da janela), pagou com a ligação só pelo telefone, pediu para não receber
 *     (e-mail ou WhatsApp, pelo hash de mcp_avisos_hash), CPF de teste, curso de teste, tentativa de mais de 180 dias;
 *   - a mensagem (com e sem Primeiros Socorros Básico) e o link do wa.me;
 *   - a página: sem sessão, só o link de entrada (GET 200 e POST 403, sem dado nenhum); token do formulário
 *     obrigatório; o WhatsApp registra o contato e responde 303 para o wa.me; "Não quer contato" bloqueia os dois
 *     canais e tira a pessoa da lista; a pessoa que paga depois do contato vira "recuperada"; a planilha (CSV);
 *   - as métricas: recuperadas pelo lembrete do PIX, quebra por curso e os 14 dias.
 *
 * O teste sobe o site num servidor PHP local (php -S) com um oferta.json próprio (turma de Primeiros Socorros Básico
 * daqui a 18 dias). Nenhum serviço externo é chamado: o wa.me só é conferido no cabeçalho Location, nunca aberto.
 * CPFs gerados com dígito verificador válido; tudo o que o teste cria é apagado no fim.
 *
 * Uso: MCP_CONFIG_ARQUIVO=/caminho/config-teste.php php scripts/testar_recuperacao_funil_integracao.php
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$configOriginal = (string) getenv('MCP_CONFIG_ARQUIVO');
if ($configOriginal === '' || !is_file($configOriginal)) {
    fwrite(STDERR, "Falta MCP_CONFIG_ARQUIVO com o banco local (veja o cabeçalho deste arquivo).\n");
    exit(2);
}

function cpf_de_teste(): string
{
    $d = array_map(static fn(): int => random_int(0, 9), range(1, 9));
    for ($n = 9; $n < 11; $n++) {
        $s = 0;
        foreach ($d as $i => $v) {
            $s += $v * ($n + 1 - $i);
        }
        $d[] = (10 * $s) % 11 % 10;
    }
    return implode('', $d);
}

$porta = 19600 + random_int(0, 299);
$quem = 'recuperacao-funil@exemplo.org';
$cpfTeste = cpf_de_teste();
$arquivoConfig = tempnam(sys_get_temp_dir(), 'mcp-rf-cfg-');
file_put_contents($arquivoConfig, '<?php return ' . var_export([
    'PAINEL_EMAILS' => $quem, 'EMAIL_SECRETARIA' => '', 'SITE_URL' => "http://127.0.0.1:$porta", 'RESEND_API_KEY' => '', 'TESTE_CPFS' => [$cpfTeste],
], true) . ' + (require ' . var_export($configOriginal, true) . ');');
// Turma de Primeiros Socorros Básico daqui a 18 dias (o teste não depende da data de hoje nem do oferta.json real).
$inicioTurma = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->modify('+18 days')->setTime(9, 0);
$oferta = tempnam(sys_get_temp_dir(), 'mcp-rf-oferta-');
file_put_contents($oferta, json_encode(['parcelado_no_ar' => false, 'sem_turma' => [], 'turmas' => [[
    'curso' => 'primeiros-socorros-basico', 'id_escola' => 'turma-teste-rf', 'inicio' => $inicioTurma->format('c'),
    'fim' => $inicioTurma->setTime(17, 0)->format('c'), 'inscricoes_ate' => $inicioTurma->modify('-1 day')->setTime(23, 59, 59)->format('c'), 'lotada' => false,
]]]));
$semEscola = tempnam(sys_get_temp_dir(), 'mcp-rf-escola-');
file_put_contents($semEscola, '<?php return [];');
putenv("MCP_CONFIG_ARQUIVO=$arquivoConfig");
putenv("MCP_OFERTA_ARQUIVO=$oferta");
putenv("MCP_CONFIG_ESCOLA_ARQUIVO=$semEscola");
putenv("MCP_CONFIG_WHATSAPP_ARQUIVO=$semEscola");
putenv('MCP_CATALOGO_ARQUIVO=' . $raiz . '/site/matricula-cursos-presenciais/cursos.json');
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $raiz . '/site/matricula-cursos-presenciais/api/lib.php';
require_once $raiz . '/site/matricula-cursos-presenciais/api/lib/recuperacao_funil.php';
restore_exception_handler();

if (!in_array((string) mcp_cfg('DB_HOST', 'localhost'), ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "DB_HOST não é local. Este teste grava e apaga dados; só roda num banco local.\n");
    exit(2);
}
// A sessão do Portal antes de qualquer saída (o setcookie precisa dos cabeçalhos ainda abertos).
mcp_painel_sessao_abrir($quem);
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

/** [status, cabeçalhos (minúsculos), corpo]. Não segue redirecionamento. */
function http(string $url, string $metodo = 'GET', ?array $campos = null, array $cabecalhos = []): array
{
    $recebidos = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $cabecalhos, CURLOPT_TIMEOUT => 60,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $linha) use (&$recebidos): int {
            if (str_contains($linha, ':')) {
                [$nome, $valor] = explode(':', $linha, 2);
                $recebidos[strtolower(trim($nome))] = trim($valor);
            }
            return strlen($linha);
        },
    ]);
    if ($campos !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($campos));
    }
    $resposta = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $recebidos, $resposta];
}

ignore_user_abort(true);
$db = mcp_db();
$sufixo = bin2hex(random_bytes(3));
$padraoEmail = '^rf-[a-z0-9]+-[0-9a-f]{6}@exemplo[.]org$';
$hashesCriados = [];

function limpar(PDO $db, string $padraoEmail, string $quem, array $hashes): void
{
    $stmt = $db->prepare('SELECT id FROM mcp_inscricoes WHERE email REGEXP ?');
    $stmt->execute([$padraoEmail]);
    $ids = implode(', ', array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    if ($ids !== '') {
        $db->exec("DELETE FROM mcp_eventos WHERE inscricao_id IN ($ids)");
        $db->exec("DELETE FROM mcp_recuperacao_contatos WHERE inscricao_id IN ($ids)");
        $db->exec("DELETE FROM mcp_inscricoes WHERE id IN ($ids)");
    }
    $db->prepare('DELETE FROM mcp_recuperacao_contatos WHERE autor = ?')->execute([$quem]);
    $db->prepare("DELETE FROM mcp_eventos WHERE tipo = 'recuperacao_exportacao' AND detalhe LIKE ?")->execute([$quem . '%']);
    foreach ($hashes as [$canal, $hash]) {
        $db->prepare('DELETE FROM mcp_avisos_bloqueios WHERE canal = ? AND destino_hash = ?')->execute([$canal, $hash]);
    }
}
limpar($db, $padraoEmail, $quem, []);

$agora = time();
$diasAtras = static fn(float $d): string => gmdate('Y-m-d H:i:s', (int) ($agora - $d * 86400));
$telefone = static fn(): string => '2198' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
$criar = static function (string $chave, float $dias, array $campos = []) use ($db, $diasAtras, $sufixo, $telefone): array {
    $quando = $diasAtras($dias);
    $linha = $campos + [
        'token' => mcp_token_novo(), 'cpf' => cpf_de_teste(), 'telefone' => $telefone(), 'metodo' => 'pix',
        'curso_slug' => 'puncao-venosa', 'curso_nome' => 'Punção Venosa', 'nome' => "Pessoa Teste $chave",
        'email' => "rf-$chave-$sufixo@exemplo.org", 'inscricao_centavos' => 9900, 'taxa_centavos' => 0, 'total_centavos' => 9900,
        'status' => 'pendente', 'escola_status' => 'nao_aplicavel', 'criado_em' => $quando, 'atualizado_em' => $quando, 'pago_em' => null,
    ];
    $colunas = array_keys($linha);
    $db->prepare('INSERT INTO mcp_inscricoes (' . implode(', ', $colunas) . ') VALUES (' . implode(', ', array_fill(0, count($colunas), '?')) . ')')
        ->execute(array_values($linha));
    $linha['id'] = (int) $db->lastInsertId();
    return $linha;
};
/** A pessoa do público que tem esta inscrição, ou null. */
$pessoaDe = static fn(array $pessoas, array $linha): ?array => mcp_rf_pessoa_por_inscricao($pessoas, (int) $linha['id']);

$servidor = null;
try {
    // ------------------------------------------------------------------------- dados
    $a1 = $criar('cpf', 10, ['nome' => 'MARIA DA SILVA']);
    $a2 = $criar('cpfdois', 3, ['cpf' => $a1['cpf'], 'curso_slug' => 'bombeiro-civil', 'curso_nome' => 'Bombeiro Civil', 'nome' => 'MARIA DA SILVA']);
    $b1 = $criar('email', 20);
    $b2 = $criar('emaildois', 5, ['email' => strtoupper($b1['email'])]);
    $c1 = $criar('tel', 30);
    $c2 = $criar('teldois', 4, ['telefone' => '(' . substr($c1['telefone'], 0, 2) . ') ' . substr($c1['telefone'], 2, 5) . '-' . substr($c1['telefone'], 7)]);
    $d1 = $criar('pagououtro', 6);
    $d2 = $criar('pagououtrodois', 300, ['cpf' => $d1['cpf'], 'curso_slug' => 'bombeiro-civil', 'curso_nome' => 'Bombeiro Civil', 'status' => 'pago', 'pago_em' => $diasAtras(299)]);
    $e1 = $criar('pagoutel', 6);
    $e2 = $criar('pagouteldois', 50, ['telefone' => '55' . $e1['telefone'], 'status' => 'estornado', 'pago_em' => $diasAtras(49)]);
    $f1 = $criar('bloqzap', 2);
    $f2 = $criar('bloqmail', 2);
    $g1 = $criar('cursoteste', 2, ['curso_slug' => 'curso-teste', 'curso_nome' => 'Curso Teste']);
    $g2 = $criar('cpfteste', 2, ['cpf' => $cpfTeste]);
    $g3 = $criar('cursodev', 2, ['curso_slug' => 'primeiros-socorros-dev', 'curso_nome' => 'Primeiros Socorros (dev)']);
    $h1 = $criar('velho', 200);
    $ps = $criar('ps', 1, ['curso_slug' => 'primeiros-socorros-basico', 'curso_nome' => 'Primeiros Socorros Básico', 'nome' => 'joão pereira']);
    $r1 = $criar('recupera', 8);
    $n1 = $criar('naoquer', 7);
    $semCel = $criar('fixo', 7, ['telefone' => '2122223333']);
    // Lembrete do PIX seguido de pagamento do mesmo curso pela mesma pessoa (por CPF).
    $p1 = $criar('pix', 2);
    $db->prepare("INSERT INTO mcp_eventos (inscricao_id, tipo, detalhe, criado_em) VALUES (?, 'email_pix_lembrete', 'teste', ?)")->execute([$p1['id'], $diasAtras(1.9)]);
    $p2 = $criar('pixpago', 1.5, ['cpf' => $p1['cpf'], 'status' => 'pago', 'pago_em' => $diasAtras(1.4)]);

    $zap = mcp_whatsapp_numero($f1['telefone']);
    $hashesCriados[] = ['whatsapp', mcp_avisos_hash('whatsapp', (string) $zap)];
    $hashesCriados[] = ['email', mcp_avisos_hash('email', $f2['email'])];
    mcp_avisos_bloquear_hash('whatsapp', $hashesCriados[0][1], 'respondeu PARAR');
    mcp_avisos_bloquear_hash('email', $hashesCriados[1][1], 'pagina');
    foreach ([$n1, $r1] as $l) {
        $hashesCriados[] = ['email', mcp_avisos_hash('email', $l['email'])];
        $hashesCriados[] = ['whatsapp', mcp_avisos_hash('whatsapp', (string) mcp_whatsapp_numero($l['telefone']))];
    }

    // ------------------------------------------------------------------------- público
    $pessoas = mcp_rf_carregar();
    $pa = $pessoaDe($pessoas, $a1);
    verificar('CPF: duas tentativas viram uma pessoa', $pa !== null && $pa === $pessoaDe($pessoas, $a2), true);
    verificar('CPF: tentativas, cursos distintos e o mais recente primeiro', [$pa['tentativas'] ?? 0, $pa['cursos'] ?? []],
        [2, ['bombeiro-civil' => 'Bombeiro Civil', 'puncao-venosa' => 'Punção Venosa']]);
    verificar('nome em maiúsculas: primeiro nome capitalizado', $pa['primeiro_nome'] ?? '', 'Maria');
    verificar('CPF mascarado na lista', $pa['cpf_mascarado'] ?? '', '***.' . substr($a1['cpf'], 3, 3) . '.' . substr($a1['cpf'], 6, 3) . '-**');
    $pb = $pessoaDe($pessoas, $b1);
    verificar('e-mail (maiúsculas ignoradas) liga dois CPFs', $pb !== null && $pb === $pessoaDe($pessoas, $b2) && $pb['tentativas'] === 2, true);
    $pc = $pessoaDe($pessoas, $c1);
    verificar('telefone (formatado de jeitos diferentes) liga dois CPFs', $pc !== null && $pc === $pessoaDe($pessoas, $c2) && $pc['tentativas'] === 2, true);
    verificar('sai quem pagou OUTRO curso (mesmo CPF, fora da janela)', $pessoaDe($pessoas, $d1), null);
    verificar('sai quem tem estorno ligado só pelo telefone (com 55)', $pessoaDe($pessoas, $e1), null);
    verificar('sai quem bloqueou o WhatsApp', $pessoaDe($pessoas, $f1), null);
    verificar('sai quem bloqueou o e-mail', $pessoaDe($pessoas, $f2), null);
    verificar('saem curso de teste, CPF de teste e curso dev', [$pessoaDe($pessoas, $g1), $pessoaDe($pessoas, $g2), $pessoaDe($pessoas, $g3)], [null, null, null]);
    verificar('sai tentativa de mais de 180 dias', $pessoaDe($pessoas, $h1), null);
    verificar('quem pagou o curso depois do lembrete do PIX não está no público', $pessoaDe($pessoas, $p1), null);
    verificar('situação inicial: novo', $pessoaDe($pessoas, $r1)['situacao'] ?? '', 'novo');
    verificar('filtro por curso', in_array($pa, mcp_rf_filtrar($pessoas, 'bombeiro-civil'), true) && !in_array($pb, mcp_rf_filtrar($pessoas, 'bombeiro-civil'), true), true);
    verificar('telefone mascarado', mcp_rf_telefone_mascarado('21987654321'), '(21) 9****-4321');

    // ------------------------------------------------------------------------- mensagem
    $turmaFixa = ['inicio' => '2026-11-17T09:00:00-03:00'];
    $link = mcp_site_url() . '/matricula-cursos-presenciais/checkout/?curso=primeiros-socorros-basico&utm_source=whatsapp&utm_medium=recuperacao&utm_campaign=ps-20261117';
    verificar('mensagem: curso de interesse', mcp_rf_mensagem(['primeiro_nome' => 'Maria', 'curso_interesse' => 'Bombeiro Civil', 'cursos' => ['bombeiro-civil' => 'Bombeiro Civil']], $turmaFixa),
        'Olá, Maria! Aqui é a Cruz Vermelha RJ. Você se interessou pelo nosso curso de Bombeiro Civil, e queremos te contar que abrimos uma turma de Primeiros Socorros Básico em terça-feira, 17/11, '
        . 'na sede do Centro do Rio. São 8 horas de aula presencial com prática de RCP, uso do desfibrilador (DEA), engasgo e controle de hemorragias. O curso inclui a Lei Lucas e dá certificado da Cruz Vermelha. '
        . "Inscrição: $link. Se não quiser mais receber nossas mensagens, é só responder SAIR.");
    $pps = $pessoaDe($pessoas, $ps);
    $textoPs = mcp_rf_mensagem($pps, $turmaFixa);
    verificar('mensagem: quem tentou Primeiros Socorros Básico ouve que não concluiu, sem repetir o curso',
        [str_starts_with($textoPs, 'Olá, João! Aqui é a Cruz Vermelha RJ. Você começou sua inscrição no curso de Primeiros Socorros Básico e ela não foi concluída. Queremos te contar que abrimos uma turma em terça-feira, 17/11,'),
            substr_count($textoPs, 'Primeiros Socorros Básico')], [true, 1]);
    verificar('mensagem: sem preço nem escassez', preg_match('/R\$|vaga|últim|corra|só hoje/iu', $textoPs), 0);
    verificar('campanha da turma', mcp_rf_campanha($turmaFixa), 'ps-20261117');
    verificar('link do wa.me', mcp_rf_link_whatsapp('5521987654321', 'Olá, Ana! a&b'), 'https://wa.me/5521987654321?text=Ol%C3%A1%2C%20Ana%21%20a%26b');

    // ------------------------------------------------------------------------- a página
    $nulo = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $logErros = tempnam(sys_get_temp_dir(), 'mcp-rf-php-');
    $servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$porta", '-t', $raiz . '/site', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$logErros"], $nulo, $tubos, null, [
        'MCP_CONFIG_ARQUIVO' => $arquivoConfig, 'MCP_OFERTA_ARQUIVO' => $oferta, 'MCP_CONFIG_ESCOLA_ARQUIVO' => $semEscola, 'MCP_CONFIG_WHATSAPP_ARQUIVO' => $semEscola,
        'MCP_CATALOGO_ARQUIVO' => getenv('MCP_CATALOGO_ARQUIVO'), 'PATH' => (string) getenv('PATH'),
    ]);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $porta) === false; $i++) {
        usleep(100000);
    }
    $url = "http://127.0.0.1:$porta/matricula-cursos-presenciais/api/recuperacao.php";
    $cookie = 'Cookie: mcp_painel=' . $_COOKIE[MCP_PAINEL_COOKIE];
    $csrf = static fn(string $acao, int $id): string => mcp_painel_csrf($quem, $acao, $id);
    $contatosDe = static function (array $l) use ($db): array {
        $stmt = $db->prepare('SELECT pessoa_chave, canal, campanha, autor FROM mcp_recuperacao_contatos WHERE inscricao_id = ?');
        $stmt->execute([$l['id']]);
        return $stmt->fetchAll();
    };

    [$st, $cab, $html] = http($url);
    verificar('sem sessão: 200 com o link de entrada', [$st, str_contains($html, 'Entrar pelo Portal da secretaria'), str_contains($html, 'href="painel.php"')], [200, true, true]);
    verificar('sem sessão: nenhum dado', [str_contains($html, $sufixo), str_contains($html, 'No público'), str_contains($html, 'Pessoa Teste')], [false, false, false]);
    verificar('cabeçalhos noindex e no-store', [$cab['x-robots-tag'] ?? '', $cab['cache-control'] ?? ''], ['noindex, nofollow', 'no-store']);
    [$st, , $html] = http($url, 'POST', ['acao' => 'whatsapp', 'id' => $r1['id'], 't' => $csrf('rf_whatsapp', (int) $r1['id'])]);
    verificar('sem sessão: POST recusado (403) e nada registrado', [$st, str_contains($html, $sufixo), $contatosDe($r1)], [403, false, []]);
    [$st, , $html] = http($url . '?situacao=x&curso=' . rawurlencode('<b>'), 'GET', null, ["Cookie: mcp_painel=adulterado.$sufixo"]);
    verificar('cookie adulterado: só o link de entrada', [$st, str_contains($html, 'Entrar pelo Portal'), str_contains($html, $sufixo)], [200, true, false]);

    [$st, , $html] = http($url, 'GET', null, [$cookie]);
    verificar('com sessão: a lista aparece com as métricas', [$st, str_contains($html, 'No público'), str_contains($html, 'Pessoa Teste recupera'), str_contains($html, 'Pessoa Teste pagououtro')], [200, true, true, false]);
    verificar('com sessão: e-mail visível, telefone mascarado (o número inteiro não está na página)', [str_contains($html, $r1['email']), str_contains($html, $r1['telefone'])], [true, false]);
    verificar('com sessão: CPF nunca em claro', str_contains($html, $r1['cpf']), false);
    verificar('sem celular: botão desligado com o motivo', str_contains($html, 'Sem celular válido'), true);

    [$st] = http($url, 'POST', ['acao' => 'whatsapp', 'id' => $r1['id']], [$cookie]);
    [$st2] = http($url, 'POST', ['acao' => 'whatsapp', 'id' => $r1['id'], 't' => $csrf('rf_whatsapp', (int) $r1['id'] + 1)], [$cookie]);
    [$st3] = http($url, 'POST', ['acao' => 'whatsapp', 'id' => $r1['id'], 't' => $csrf('rf_nao_quer', (int) $r1['id'])], [$cookie]);
    verificar('CSRF: sem token, token de outra pessoa ou de outra ação → 403, nada registrado', [$st, $st2, $st3, $contatosDe($r1)], [403, 403, 403, []]);

    [$st, $cab] = http($url, 'POST', ['acao' => 'whatsapp', 'id' => $r1['id'], 't' => $csrf('rf_whatsapp', (int) $r1['id'])], [$cookie]);
    $local = (string) ($cab['location'] ?? '');
    $numero = (string) mcp_whatsapp_numero($r1['telefone']);
    parse_str((string) parse_url($local, PHP_URL_QUERY), $q);
    verificar('WhatsApp: 303 para wa.me/55<telefone>', [$st, str_starts_with($local, "https://wa.me/$numero?text=")], [303, true]);
    $campanha = mcp_rf_campanha(['inicio' => $inicioTurma->format('c')]);
    verificar('WhatsApp: a mensagem leva o nome, o curso e o link com a campanha da turma',
        [str_starts_with((string) ($q['text'] ?? ''), 'Olá, Pessoa! Aqui é a Cruz Vermelha RJ. Você se interessou pelo nosso curso de Punção Venosa,'),
            str_contains((string) ($q['text'] ?? ''), "utm_campaign=$campanha")], [true, true]);
    $contatos = $contatosDe($r1);
    verificar('WhatsApp: contato registrado com a chave da pessoa, sem CPF em claro',
        [count($contatos), $contatos[0]['canal'] ?? '', $contatos[0]['campanha'] ?? '', $contatos[0]['autor'] ?? '', $contatos[0]['pessoa_chave'] ?? '', strlen((string) ($contatos[0]['pessoa_chave'] ?? ''))],
        [1, 'whatsapp', $campanha, $quem, mcp_rf_chave($r1['cpf']), 64]);
    $semCpf = $db->prepare('SELECT COUNT(*) FROM mcp_recuperacao_contatos WHERE pessoa_chave LIKE ? OR campanha LIKE ? OR autor LIKE ?');
    $semCpf->execute(array_fill(0, 3, '%' . $r1['cpf'] . '%'));
    verificar('a tabela de contatos não guarda o CPF', (int) $semCpf->fetchColumn(), 0);
    $pessoas = mcp_rf_carregar();
    verificar('depois do contato: contatado', [$pessoaDe($pessoas, $r1)['situacao'] ?? '', $pessoaDe($pessoas, $r1)['contatos'] ?? 0], ['contatado', 1]);
    [$st, $cab] = http($url, 'POST', ['acao' => 'whatsapp', 'id' => $semCel['id'], 't' => $csrf('rf_whatsapp', (int) $semCel['id'])], [$cookie]);
    verificar('sem celular válido: volta para a página, sem wa.me nem contato', [$st, str_contains((string) ($cab['location'] ?? ''), 'erro=sem_celular'), $contatosDe($semCel)], [303, true, []]);

    // Pagou depois do contato (outro curso, outra inscrição do mesmo CPF): recuperada, fora da lista de trabalho.
    $r2 = $criar('recuperadois', 0, ['cpf' => $r1['cpf'], 'curso_slug' => 'primeiros-socorros-basico', 'curso_nome' => 'Primeiros Socorros Básico',
        'status' => 'pago', 'pago_em' => gmdate('Y-m-d H:i:s', time() + 60)]);
    $pessoas = mcp_rf_carregar();
    $pr = $pessoaDe($pessoas, $r1);
    verificar('pagou depois do contato: recuperado', $pr['situacao'] ?? '', 'recuperado');
    verificar('recuperado sai da lista de trabalho e aparece no filtro', [in_array($pr, mcp_rf_filtrar($pessoas), true), in_array($pr, mcp_rf_filtrar($pessoas, '', 'recuperado'), true)], [false, true]);
    [, , $html] = http($url, 'GET', null, [$cookie]);
    verificar('recuperado não aparece na lista da página', str_contains($html, 'Pessoa Teste recupera'), false);

    // Não quer contato.
    [$st] = http($url, 'POST', ['acao' => 'nao_quer', 'id' => $n1['id'], 't' => 'x'], [$cookie]);
    verificar('não quer contato: CSRF obrigatório', $st, 403);
    [$st, $cab] = http($url, 'POST', ['acao' => 'nao_quer', 'id' => $n1['id'], 't' => $csrf('rf_nao_quer', (int) $n1['id'])], [$cookie]);
    verificar('não quer contato: 303 de volta com aviso', [$st, str_contains((string) ($cab['location'] ?? ''), 'ok=nao_quer')], [303, true]);
    $bloq = $db->prepare("SELECT canal FROM mcp_avisos_bloqueios WHERE origem = 'recuperacao' AND ((canal = 'email' AND destino_hash = ?) OR (canal = 'whatsapp' AND destino_hash = ?)) ORDER BY canal");
    $bloq->execute([mcp_avisos_hash('email', $n1['email']), mcp_avisos_hash('whatsapp', (string) mcp_whatsapp_numero($n1['telefone']))]);
    verificar('não quer contato: bloqueia e-mail e WhatsApp (origem recuperacao)', $bloq->fetchAll(PDO::FETCH_COLUMN), ['email', 'whatsapp']);
    $pessoas = mcp_rf_carregar();
    $pn = $pessoaDe($pessoas, $n1);
    verificar('não quer contato: situação nao_quer, fora da lista de trabalho', [$pn['situacao'] ?? '', in_array($pn, mcp_rf_filtrar($pessoas), true)], ['nao_quer', false]);
    [$st, $cab] = http($url, 'POST', ['acao' => 'whatsapp', 'id' => $n1['id'], 't' => $csrf('rf_whatsapp', (int) $n1['id'])], [$cookie]);
    verificar('não quer contato: o WhatsApp não abre mais', [$st, str_contains((string) ($cab['location'] ?? ''), 'erro=saiu')], [303, true]);
    [, , $html] = http($url, 'GET', null, [$cookie]);
    verificar('não quer contato: some da página', str_contains($html, 'Pessoa Teste naoquer'), false);

    // ------------------------------------------------------------------------- métricas
    $m = mcp_rf_metricas($pessoas, mcp_rf_contatos_recentes(), mcp_rf_pix_recuperadas());
    verificar('métricas: recuperadas e contatadas contam a pessoa recuperada', [$m['recuperadas'] >= 1, $m['contatadas'] >= 1, $m['taxa'] !== null], [true, true, true]);
    verificar('métricas: recuperada pelo lembrete do PIX', $m['pix'] >= 1, true);
    verificar('métricas: 14 dias, hoje com o WhatsApp', [count($m['dias']), end($m['dias'])['whatsapp'] >= 1], [14, true]);
    verificar('métricas: quebra por curso', isset($m['cursos']['puncao-venosa']['pessoas']) && $m['cursos']['puncao-venosa']['pessoas'] >= 3, true);
    verificar('lembrete do PIX: curso diferente não conta', mcp_rf_pix_contar(
        [['inscricao_id' => 1, 'cpf' => '1', 'email' => 'a@x.org', 'telefone' => '', 'curso_slug' => 'a', 'enviado_em' => '2026-10-01 10:00:00']],
        [['cpf' => '', 'email' => 'A@x.org', 'telefone' => '', 'curso_slug' => 'b', 'pago_em' => '2026-10-02 10:00:00']]), 0);
    verificar('lembrete do PIX: pagamento antes do lembrete não conta, depois conta (pelo e-mail)', [
        mcp_rf_pix_contar([['inscricao_id' => 1, 'cpf' => '', 'email' => 'a@x.org', 'telefone' => '', 'curso_slug' => 'a', 'enviado_em' => '2026-10-03 10:00:00']],
            [['cpf' => '', 'email' => 'A@x.org', 'telefone' => '', 'curso_slug' => 'a', 'pago_em' => '2026-10-02 10:00:00']]),
        mcp_rf_pix_contar([['inscricao_id' => 1, 'cpf' => '', 'email' => 'a@x.org', 'telefone' => '', 'curso_slug' => 'a', 'enviado_em' => '2026-10-01 10:00:00']],
            [['cpf' => '', 'email' => 'A@x.org', 'telefone' => '', 'curso_slug' => 'a', 'pago_em' => '2026-10-02 10:00:00']]),
    ], [0, 1]);

    // ------------------------------------------------------------------------- planilha
    [$st] = http($url, 'POST', ['acao' => 'exportar', 'id' => 0], [$cookie]);
    verificar('exportar: CSRF obrigatório', $st, 403);
    [$st] = http($url, 'POST', ['acao' => 'exportar', 'id' => 0, 't' => $csrf('rf_exportar', 0)]);
    verificar('exportar: sem sessão, 403', $st, 403);
    [$st, $cab, $csv] = http($url, 'POST', ['acao' => 'exportar', 'id' => 0, 'curso' => 'puncao-venosa', 't' => $csrf('rf_exportar', 0)], [$cookie]);
    $linhasCsv = array_map(static fn(string $l): array => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", $csv)));
    $porEmail = array_column($linhasCsv, null, 3);
    verificar('exportar: CSV com o cabeçalho pedido', [$st, str_starts_with((string) ($cab['content-type'] ?? ''), 'text/csv'), $linhasCsv[0] ?? []],
        [200, true, ['nome', 'primeiro_nome', 'telefone_e164', 'email', 'curso_interesse', 'ultima_tentativa']]);
    verificar('exportar: linha da pessoa (telefone 55..., curso, data)', $porEmail[mcp_rf_email($b2['email'])] ?? null,
        ['Pessoa Teste emaildois', 'Pessoa', (string) mcp_whatsapp_numero($b2['telefone']), mcp_rf_email($b2['email']), 'Punção Venosa', mcp_data_brt($b2['criado_em'], 'Y-m-d')]);
    verificar('exportar: sem recuperado, sem "não quer", sem quem pagou, só o curso filtrado',
        [isset($porEmail[$r1['email']]), isset($porEmail[$n1['email']]), isset($porEmail[$d1['email']]), isset($porEmail[$ps['email']])], [false, false, false, false]);
    $ev = $db->prepare("SELECT COUNT(*) FROM mcp_eventos WHERE tipo = 'recuperacao_exportacao' AND detalhe LIKE ?");
    $ev->execute([$quem . ' · %']);
    verificar('exportar: quem exportou fica registrado', (int) $ev->fetchColumn(), 1);
    verificar('exportar: cada pessoa exportada conta como contato (canal exportacao)', array_column($contatosDe($b2), 'canal'), ['exportacao']);
    verificar('CSV: fórmula neutralizada', str_contains(mcp_rf_csv([['nome' => '=HYPERLINK("x")', 'primeiro_nome' => '=x', 'whatsapp' => null, 'email' => 'a@b.c', 'curso_interesse' => 'C', 'ultima_tentativa' => '2026-10-01 10:00:00']]), "'=HYPERLINK"), true);

    $erros = trim((string) file_get_contents($logErros));
    verificar('servidor sem erro de PHP no log', $erros, '');
} finally {
    if (is_resource($servidor)) {
        proc_terminate($servidor);
    }
    limpar($db, $padraoEmail, $quem, $hashesCriados);
    @unlink($arquivoConfig);
    @unlink($oferta);
    @unlink($semEscola);
}
echo "\n$total verificações, $falhas falha(s).\n";
exit($falhas > 0 ? 1 : 0);
