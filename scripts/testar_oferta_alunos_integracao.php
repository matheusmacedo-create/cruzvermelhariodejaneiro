#!/usr/bin/env php
<?php
/**
 * Teste da oferta a ex-alunos por WhatsApp (api/lib/oferta_alunos.php, o link curto "alunos" do api/ir.php, o teto em
 * 24 h somado e a entrega/resposta pelo webhook da recuperação). Contra um MariaDB LOCAL e um servidor falso local que
 * faz de Kapso (anota tudo; nada sai de verdade). Cobre:
 *   - o link curto: checkout do curso com o cupom e utm_medium=oferta; curso sem oferta não vale; a rec3cupom não mudou;
 *   - a lista do servidor: repetidos e telefones inválidos ficam de fora; sem arquivo ou desligada, nada sai;
 *   - a rodada: fora do horário, modelo em análise (ninguém perde a vez), Kapso sem a lista de modelos, a mensagem
 *     (modelo, nome, cupom, data, botão), bloqueado e quem já comprou o curso de fora, ninguém recebe duas vezes, o teto
 *     por rodada, o número sem WhatsApp (gasta a vez), o erro da Meta que para a rodada (não gasta) e a oferta vencida;
 *   - o banco: só o hash do celular, nunca nome nem número; o teto em 24 h conta estas mensagens;
 *   - o webhook: leitura e "Não tenho interesse" (guarda a resposta e bloqueia o celular);
 *   - os números do painel.
 *
 * As datas são fixas (10/11/2026, com o cupom CURATIVOS valendo e a turma de 28/11): a rodada recebe a hora por
 * parâmetro. Tudo o que o teste cria é apagado no fim.
 *
 * Uso: MCP_CONFIG_ARQUIVO=/caminho/config-teste.php php scripts/testar_oferta_alunos_integracao.php
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$configOriginal = (string) getenv('MCP_CONFIG_ARQUIVO');
if ($configOriginal === '' || !is_file($configOriginal)) {
    fwrite(STDERR, "Falta MCP_CONFIG_ARQUIVO com o banco local (veja o cabeçalho deste arquivo).\n");
    exit(2);
}
$porta = 21200 + random_int(0, 499);
$falso = sys_get_temp_dir() . '/mcp-oa-falso-' . bin2hex(random_bytes(4));
mkdir($falso);
$temp = static function (string $conteudo) use ($falso): string {
    $arq = tempnam($falso, 'cfg-');
    file_put_contents($arq, $conteudo);
    return $arq;
};
$config = $temp('<?php return ' . var_export(['WHATSAPP_KAPSO_BASE' => "http://127.0.0.1:$porta", 'WHATSAPP_KAPSO_CHAVE' => 'kapso-chave-de-teste-123',
    'WHATSAPP_KAPSO_NUMERO_ID' => '1112223334', 'SITE_URL' => 'http://127.0.0.1:1', 'PAINEL_EMAILS' => 'oferta-alunos@exemplo.org'], true) . ' + (require ' . var_export($configOriginal, true) . ');');
$vazio = $temp('<?php return [];');
putenv("MCP_CONFIG_ARQUIVO=$config");
putenv("MCP_CONFIG_ESCOLA_ARQUIVO=$vazio");
putenv("MCP_CONFIG_WHATSAPP_ARQUIVO=$vazio");
putenv("MCP_CONFIG_RECUPERACAO_ARQUIVO=$falso/sem-importados.php");
putenv('MCP_CATALOGO_ARQUIVO=' . $raiz . '/site/matricula-cursos-presenciais/cursos.json');
$listaArq = "$falso/config-oferta-alunos.php";
putenv("MCP_CONFIG_OFERTA_ALUNOS_ARQUIVO=$listaArq");
// A turma de Curativos de 28/11/2026 (como no oferta.json de hoje), para a data do {{3}} não depender do arquivo real.
$ofertaArq = $temp(json_encode(['parcelado_no_ar' => false, 'sem_turma' => [], 'turmas' => [[
    'curso' => 'curativos-e-tratamento-de-feridas', 'id_escola' => 'oa-teste', 'inicio' => '2026-11-28T09:00:00-03:00', 'fim' => '2026-11-28T17:00:00-03:00',
    'inscricoes_ate' => '2026-11-27T23:59:59-03:00', 'lotada' => false]]]));
putenv("MCP_OFERTA_ARQUIVO=$ofertaArq");
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $raiz . '/site/matricula-cursos-presenciais/api/lib.php';
require_once $raiz . '/site/matricula-cursos-presenciais/api/lib/recuperacao_whatsapp.php';
restore_exception_handler();
if (!in_array((string) mcp_cfg('DB_HOST', 'localhost'), ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "DB_HOST não é local. Este teste grava e apaga dados; só roda num banco local.\n");
    exit(2);
}

mcp_painel_sessao_abrir('oferta-alunos@exemplo.org'); // antes de qualquer saída (grava o cookie)
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

// ----------------------------------------------------------------------------- Kapso falsa
file_put_contents("$falso/roteador.php", <<<'PHP'
<?php
$dir = getenv('FALSO_DIR');
$caminho = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$controle = json_decode((string) @file_get_contents("$dir/controle.json"), true) ?: [];
header('Content-Type: application/json');
if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== 'kapso-chave-de-teste-123') { http_response_code(401); echo '{"error":"Invalid API key"}'; return true; }
if ($caminho === '/meta/whatsapp/v24.0/1112223334/messages' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $erro = $controle['erros'][$d['to'] ?? ''] ?? null;
    if ($erro !== null) {
        file_put_contents("$dir/recusas.jsonl", json_encode($d) . "\n", FILE_APPEND);
        http_response_code(400);
        echo json_encode(['error' => ['message' => 'recusado (teste)', 'code' => $erro]]);
        return true;
    }
    file_put_contents("$dir/kapso.jsonl", json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    echo json_encode(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.oa-' . count(file("$dir/kapso.jsonl"))]]]);
    return true;
}
if ($caminho === '/platform/v1/whatsapp/phone_numbers/1112223334') {
    if (!empty($controle['sem_modelos'])) { http_response_code(503); echo '{}'; return true; }
    echo '{"data":{"id":"1112223334","business_account_id":"998877665544"}}';
    return true;
}
if ($caminho === '/meta/whatsapp/v24.0/998877665544/message_templates') {
    $botoes = [['type' => 'URL', 'text' => 'Ver minha oferta', 'url' => 'https://cruzvermelhariodejaneiro.org/{{1}}'], ['type' => 'QUICK_REPLY', 'text' => 'Não tenho interesse']];
    echo json_encode(['data' => [
        ['name' => 'ps_cupom_oferta', 'status' => 'APPROVED', 'language' => 'pt_BR', 'category' => 'MARKETING'],
        ['name' => 'cur_cupom_oferta', 'status' => $controle['situacao'] ?? 'APPROVED', 'language' => 'pt_BR', 'category' => 'MARKETING', 'components' => [
            ['type' => 'BODY', 'text' => 'Olá, {{1}}! Cupom *{{2}}* para a turma de {{3}}.'], ['type' => 'BUTTONS', 'buttons' => $botoes]]],
    ]]);
    return true;
}
http_response_code(404);
echo '{}';
return true;
PHP);
$servidor = proc_open(['php', '-S', "127.0.0.1:$porta", "$falso/roteador.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $canos, null, ['FALSO_DIR' => $falso] + getenv());
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $porta); $i++) {
    usleep(100000);
}
$enviadas = static fn(): array => is_file("$falso/kapso.jsonl") ? array_map(static fn(string $l): array => json_decode($l, true), file("$falso/kapso.jsonl", FILE_IGNORE_NEW_LINES)) : [];
$controle = static function (array $c) use ($falso): void {
    file_put_contents("$falso/controle.json", json_encode($c));
};
$lista = static function (array $pessoas, bool $ligada = true) use ($listaArq): void {
    file_put_contents($listaArq, '<?php return ' . var_export(['OFERTA_ALUNOS_LIGADA' => $ligada, 'OFERTA_ALUNOS' => $pessoas], true) . ';');
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($listaArq, true);
    }
};
$db = mcp_db();
$celular = static fn(int $n): string => '219' . str_pad((string) (71230000 + $n), 8, '0', STR_PAD_LEFT);
$numeros = [];
for ($i = 1; $i <= 60; $i++) {
    $numeros[$i] = '55' . $celular($i);
}
$sufixo = bin2hex(random_bytes(3));
$inicioTeste = gmdate('Y-m-d H:i:s', time() - 5);
$limpar = static function () use ($db, $numeros, $sufixo, $inicioTeste): void {
    $db->prepare('DELETE FROM mcp_oferta_alunos WHERE campanha = ?')->execute([MCP_OA_CAMPANHA]);
    foreach ($numeros as $n) {
        $db->prepare("DELETE FROM mcp_avisos_bloqueios WHERE canal = 'whatsapp' AND destino_hash = ?")->execute([mcp_avisos_hash('whatsapp', $n)]);
    }
    $db->prepare('DELETE FROM mcp_inscricoes WHERE email = ?')->execute(["oa-$sufixo@exemplo.org"]);
    $db->exec("DELETE FROM mcp_chaves WHERE nome = 'rw_cache_modelos'");
    $db->prepare("DELETE FROM mcp_eventos WHERE inscricao_id IS NULL AND criado_em >= ? AND tipo IN ('oferta_alunos_rodada', 'rw_cache_modelos')")->execute([$inicioTeste]);
};
$semCache = static fn() => $db->exec("DELETE FROM mcp_chaves WHERE nome = 'rw_cache_modelos'");
$linhas = static function () use ($db): array {
    $stmt = $db->prepare('SELECT destino_hash, status, modelo, provedor_id, entrega, resposta, erro FROM mcp_oferta_alunos WHERE campanha = ? ORDER BY id');
    $stmt->execute([MCP_OA_CAMPANHA]);
    return $stmt->fetchAll();
};
$limpar();

// 10/11/2026, 10h de Brasília: o cupom CURATIVOS vale (até 30/11) e a turma de 28/11 está aberta.
$A = (int) strtotime('2026-11-10 13:00:00 UTC');
$curso = 'curativos-e-tratamento-de-feridas';

try {
    // ------------------------------------------------------------------------- link curto
    verificar('link: /w/<curativos>/alunos → checkout com o cupom do curso e utm_medium=oferta',
        mcp_rw_link_destino("/w/$curso/alunos"),
        ['destino' => "/matricula-cursos-presenciais/checkout/?curso=$curso&via=agenda&cupom=CURATIVOS&utm_source=whatsapp&utm_medium=oferta&utm_campaign=alunos", 'token' => '', 'valido' => true]);
    verificar('link: curso sem oferta não vale na campanha alunos', mcp_rw_link_destino('/w/puncao-venosa/alunos')['valido'], false);
    verificar('link: a campanha alunos sem curso não vale', mcp_rw_link_destino('/w/cursos/alunos')['valido'], false);
    verificar('link: a rec3cupom continua como recuperação',
        mcp_rw_link_destino("/w/$curso/rec3cupom")['destino'],
        "/matricula-cursos-presenciais/checkout/?curso=$curso&via=agenda&cupom=CURATIVOS&utm_source=whatsapp&utm_medium=recuperacao&utm_campaign=rec3cupom");
    verificar('link: o caminho do botão só tem letras, números, "-", "." e "/"', mcp_oa_modelo(['nome' => 'Ana'], ['codigo' => 'CURATIVOS', 'data' => 'sábado, 28/11'])['caminho'],
        "matricula-cursos-presenciais/api/ir.php/w/$curso/alunos");

    // ------------------------------------------------------------------------- lista
    @unlink($listaArq);
    verificar('lista: sem o arquivo, desligada e vazia', mcp_oa_config(), ['ligada' => false, 'lista' => [], 'invalidos' => 0, 'repetidos' => 0]);
    $r = mcp_oa_rodar($A);
    verificar('rodada: sem o arquivo, nada sai', [$r['enviados'], $r['motivo'], count($enviadas())], [0, 'sem lista (api/config-oferta-alunos.php)', 0]);
    $base = [
        ['nome' => 'maria aparecida da silva', 'telefone' => '(21) ' . substr($celular(1), 2, 5) . '-' . substr($celular(1), 7)],
        ['nome' => 'Dr. João Pedro', 'telefone' => $celular(2)],
        ['nome' => '', 'telefone' => $numeros[3]],
        ['nome' => 'Bloqueada Teste', 'telefone' => $celular(4)],
        ['nome' => 'Comprou Teste', 'telefone' => $celular(5)],
        ['nome' => 'Repetida Teste', 'telefone' => $celular(1)],
        ['nome' => 'Fixo Teste', 'telefone' => '(21) 2233-4455'],
        ['nome' => 'Incompleto Teste', 'telefone' => '(55) 21971-9532'],
    ];
    $lista($base, false);
    $c = mcp_oa_config();
    verificar('lista: repetidos e inválidos de fora, números com 55', [$c['ligada'], array_column($c['lista'], 'numero'), $c['invalidos'], $c['repetidos']],
        [false, [$numeros[1], $numeros[2], $numeros[3], $numeros[4], $numeros[5]], 2, 1]);
    $r = mcp_oa_rodar($A);
    verificar('rodada: desligada, nada sai', [$r['enviados'], $r['motivo'], count($enviadas())], [0, 'desligada (OFERTA_ALUNOS_LIGADA)', 0]);

    // ------------------------------------------------------------------------- o que segura a rodada
    $lista($base);
    $r = mcp_oa_rodar($A - 11 * 3600);
    verificar('rodada: fora do horário (23h de Brasília), nada sai', [$r['enviados'], $r['motivo'], count($enviadas())], [0, 'fora do horário (8h–20h de Brasília)', 0]);
    $controle(['situacao' => 'PENDING']);
    $semCache();
    $r = mcp_oa_rodar($A);
    verificar('rodada: modelo em análise na Meta → nada sai e ninguém perde a vez', [$r['enviados'], $r['motivo'], count($enviadas()), count($linhas())],
        [0, 'modelo sem aprovação: cur_cupom_oferta', 0, 0]);
    $controle(['sem_modelos' => true]);
    $semCache();
    $r = mcp_oa_rodar($A);
    verificar('rodada: Kapso sem a lista de modelos → espera (é marketing: não manda no escuro)', [$r['enviados'], str_starts_with((string) $r['motivo'], 'Kapso: '), count($enviadas())], [0, true, 0]);
    $r = mcp_oa_rodar((int) strtotime('2026-12-01 13:00:00 UTC'));
    verificar('rodada: cupom vencido (01/12) → nada sai', [$r['enviados'], $r['motivo'], count($enviadas())],
        [0, 'a oferta não vale agora (cupom encerrado ou sem vaga, ou curso sem turma aberta)', 0]);

    // ------------------------------------------------------------------------- a rodada que manda
    $controle([]);
    $semCache();
    mcp_avisos_bloquear_hash('whatsapp', mcp_avisos_hash('whatsapp', $numeros[4]), 'recuperacao');
    $quando = gmdate('Y-m-d H:i:s', $A - 86400);
    $insc = ['token' => mcp_token_novo(), 'cpf' => '52998224725', 'telefone' => '(21) ' . substr($celular(5), 2, 5) . '-' . substr($celular(5), 7), 'metodo' => 'pix', 'curso_slug' => $curso,
        'curso_nome' => 'Curativos e Tratamento de Feridas', 'nome' => 'Comprou Teste', 'email' => "oa-$sufixo@exemplo.org", 'inscricao_centavos' => 9900, 'taxa_centavos' => 0,
        'total_centavos' => 15000, 'status' => 'pago', 'escola_status' => 'nao_aplicavel', 'criado_em' => $quando, 'atualizado_em' => $quando, 'pago_em' => $quando, 'utm_campaign' => 'alunos'];
    $db->prepare('INSERT INTO mcp_inscricoes (' . implode(', ', array_keys($insc)) . ') VALUES (' . implode(', ', array_fill(0, count($insc), '?')) . ')')->execute(array_values($insc));
    $r = mcp_oa_rodar($A);
    $e = $enviadas();
    verificar('rodada: 3 recebem; a bloqueada e quem já comprou o curso ficam de fora', [$r['enviados'], $r['falhas'], $r['pulados'], array_column($e, 'to')],
        [3, 0, 2, [$numeros[1], $numeros[2], $numeros[3]]]);
    $comp = $e[0]['template']['components'] ?? [];
    verificar('mensagem: modelo de cupom do curso, em pt_BR', [$e[0]['template']['name'] ?? '', $e[0]['template']['language']['code'] ?? ''], ['cur_cupom_oferta', 'pt_BR']);
    verificar('mensagem: {{1}} primeiro nome, {{2}} o cupom, {{3}} a data da turma', array_column($comp[0]['parameters'] ?? [], 'text'), ['Maria', 'CURATIVOS', 'sábado, 28/11']);
    verificar('mensagem: botão de URL (índice 0) com o link curto da campanha alunos', [$comp[1]['sub_type'] ?? '', $comp[1]['index'] ?? '', $comp[1]['parameters'][0]['text'] ?? ''],
        ['url', '0', "matricula-cursos-presenciais/api/ir.php/w/$curso/alunos"]);
    verificar('mensagem: título no nome é tirado; sem nome vai o padrão', [$e[1]['template']['components'][0]['parameters'][0]['text'] ?? '', $e[2]['template']['components'][0]['parameters'][0]['text'] ?? ''],
        ['João', MCP_RW_NOME_PADRAO]);
    $l = $linhas();
    verificar('banco: uma linha por celular, enviada, com o hash e o wamid', array_map(static fn(array $x): array => [$x['destino_hash'], $x['status'], $x['modelo'], $x['provedor_id']], $l),
        [[mcp_avisos_hash('whatsapp', $numeros[1]), 'enviado', 'cur_cupom_oferta', 'wamid.oa-1'], [mcp_avisos_hash('whatsapp', $numeros[2]), 'enviado', 'cur_cupom_oferta', 'wamid.oa-2'],
            [mcp_avisos_hash('whatsapp', $numeros[3]), 'enviado', 'cur_cupom_oferta', 'wamid.oa-3']]);
    $tudo = json_encode($db->query('SELECT * FROM mcp_oferta_alunos')->fetchAll());
    verificar('banco: nenhum número nem nome na tabela', [str_contains($tudo, substr($numeros[1], 4)), stripos($tudo, 'maria') !== false], [false, false]);
    verificar('teto: as mensagens da oferta contam nas 24 h do número', mcp_rw_enviadas_24h($A + 60) >= 3, true);
    $r = mcp_oa_rodar($A + 3600);
    verificar('rodada seguinte: ninguém recebe duas vezes', [$r['enviados'], count($enviadas())], [0, 3]);

    // ------------------------------------------------------------------------- webhook: leitura e "Não tenho interesse"
    $n = mcp_rw_webhook_tratar([['tipo' => 'entrega', 'id' => 'wamid.oa-1', 'entrega' => 'lida', 'erro' => null],
        ['tipo' => 'resposta', 'de' => $numeros[2], 'texto' => 'Não tenho interesse', 'citada' => 'wamid.oa-2', 'ts' => $A + 100]], $A + 120);
    $l = $linhas();
    verificar('webhook: a leitura e a resposta ficam na linha da mensagem', [$n >= 2, $l[0]['entrega'], $l[1]['resposta']], [true, 'lida', 'Não tenho interesse']);
    verificar('webhook: "Não tenho interesse" bloqueia o celular', mcp_avisos_bloqueado('whatsapp', $numeros[2]), true);

    // ------------------------------------------------------------------------- painel
    $m = mcp_oa_metricas($A + 200);
    verificar('painel: lista, enviadas, entregues, lidas, respostas, bloqueados e compras pelo link',
        [$m['ligada'], $m['na_lista'], $m['invalidos'], $m['repetidos'], $m['enviadas'], $m['falharam'], $m['entregues'], $m['lidas'], $m['responderam'], $m['nao_querem'], $m['bloqueados'], $m['compras'],
            $m['oferta']['codigo'] ?? null, $m['rodada']['enviados'] ?? null],
        [true, 5, 2, 1, 3, 0, 1, 1, 1, 1, 1, 1, 'CURATIVOS', 3]);

    // ------------------------------------------------------------------------- painel e link curto pelo HTTP
    $portaSite = $porta + 500;
    $nulo = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $site = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaSite", '-t', $raiz . '/site', '-d', 'display_errors=0'], $nulo, $canos2, null, [
        'MCP_CONFIG_ARQUIVO' => $config, 'MCP_CONFIG_ESCOLA_ARQUIVO' => $vazio, 'MCP_CONFIG_WHATSAPP_ARQUIVO' => $vazio, 'MCP_CONFIG_RECUPERACAO_ARQUIVO' => "$falso/sem-importados.php",
        'MCP_CONFIG_OFERTA_ALUNOS_ARQUIVO' => $listaArq, 'MCP_CATALOGO_ARQUIVO' => (string) getenv('MCP_CATALOGO_ARQUIVO'), 'MCP_OFERTA_ARQUIVO' => $ofertaArq, 'PATH' => (string) getenv('PATH')]);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $portaSite); $i++) {
        usleep(100000);
    }
    $pedir = static function (string $caminho, array $cab = []) use ($portaSite): array {
        $ch = curl_init("http://127.0.0.1:$portaSite/matricula-cursos-presenciais/api/$caminho");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $cab, CURLOPT_TIMEOUT => 60]);
        $r = (string) curl_exec($ch);
        $st = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$st, $r];
    };
    [$st, $html] = $pedir('recuperacao.php', ['Cookie: ' . MCP_PAINEL_COOKIE . '=' . $_COOKIE[MCP_PAINEL_COOKIE]]);
    verificar('painel: a seção da oferta aparece com os números e sem nenhum celular nem nome da lista', [$st, str_contains($html, 'id="oferta-alunos"'), str_contains($html, 'Compraram pelo link'),
        str_contains($html, 'Curativos e Tratamento de Feridas'), str_contains($html, substr($numeros[1], 4)), stripos($html, 'maria aparecida') !== false], [200, true, true, true, false, false]);
    [$st, $resp] = $pedir("ir.php/w/$curso/alunos");
    verificar('ir.php: o botão responde 302 para o checkout com o cupom', [$st, (bool) preg_match("~\\nLocation: /matricula-cursos-presenciais/checkout/\\?curso=$curso&via=agenda&cupom=CURATIVOS&utm_source=whatsapp&utm_medium=oferta&utm_campaign=alunos\\r~", $resp)],
        [302, true]);
    proc_terminate($site);

    // ------------------------------------------------------------------------- erros da Kapso e teto por rodada
    $limpar();
    @unlink("$falso/kapso.jsonl");
    $muitos = [];
    for ($i = 10; $i < 10 + MCP_OA_POR_RODADA + 6; $i++) {
        $muitos[] = ['nome' => "Aluno $i", 'telefone' => $celular($i)];
    }
    $lista($muitos);
    $controle(['erros' => [$numeros[10] => 131026]]); // número sem WhatsApp: é de quem recebe
    $r = mcp_oa_rodar($A);
    $l = $linhas();
    verificar('rodada: número sem WhatsApp gasta a vez (falhou) e a fila segue até o teto por rodada', [$r['enviados'], $r['falhas'], $l[0]['status'], str_contains((string) $l[0]['erro'], '131026'),
        str_starts_with((string) $r['motivo'], 'teto de ' . MCP_OA_POR_RODADA), count($enviadas())], [MCP_OA_POR_RODADA - 1, 1, 'falhou', true, true, MCP_OA_POR_RODADA - 1]);
    $controle(['erros' => [$numeros[10 + MCP_OA_POR_RODADA] => 131048]]); // limite de spam da Meta: vale para todos
    $r = mcp_oa_rodar($A + 3600);
    verificar('rodada: erro da Meta que vale para todos para a rodada e não gasta a vez de ninguém', [$r['enviados'], $r['falhas'], str_starts_with((string) $r['motivo'], 'Kapso: '), count($linhas())],
        [0, 1, true, MCP_OA_POR_RODADA]);
    $controle([]);
    $r = mcp_oa_rodar($A + 7200);
    verificar('rodada: na hora seguinte o resto recebe, e acaba', [$r['enviados'], count($linhas()), mcp_oa_rodar($A + 10800)['motivo']],
        [6, MCP_OA_POR_RODADA + 6, 'todos da lista já foram atendidos']);
} finally {
    $limpar();
    proc_terminate($servidor);
    array_map('unlink', glob("$falso/*") ?: []);
    @rmdir($falso);
}

echo $falhas === 0 ? "\nTudo certo: $total verificações.\n" : "\n$falhas de $total verificações falharam.\n";
exit($falhas === 0 ? 0 : 1);
