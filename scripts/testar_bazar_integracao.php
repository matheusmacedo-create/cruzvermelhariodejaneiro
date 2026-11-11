#!/usr/bin/env php
<?php
/**
 * Teste do registro de doações do bazar (api/bazar.php e api/lib/bazar.php). Contra um MariaDB LOCAL e um servidor
 * falso local que faz de Kapso (anota tudo; nada sai de verdade). Cobre:
 *   - as regras puras: validação do formulário, protocolo, texto dos itens, parâmetros numa linha só, corpo do envio,
 *     conferência do modelo e horário;
 *   - o aviso por WhatsApp SÓ sai com tudo certo: sem o celular de destino, com o modelo ausente, em análise, recusado,
 *     com outro número de parâmetros, com botão de variável ou sem os componentes, nada é enviado e o aviso fica pendente;
 *   - com o modelo aprovado e conferido: a mensagem (modelo, destino, os cinco parâmetros), uma vez só por registro;
 *   - a rodada: fora do horário, pendentes velhos vencem (não saem em pilha no dia da aprovação), erro de quem recebe
 *     (falhou), erro que vale para todos (para a rodada e não gasta a tentativa), teto por rodada;
 *   - a página gerada: as opções do formulário são as que o servidor aceita;
 *   - o endpoint, por HTTP: 201 com o protocolo, armadilha, aceite, campos inválidos e o freio por telefone.
 *
 * Tudo o que o teste cria é apagado no fim.
 * Uso: MCP_CONFIG_ARQUIVO=/caminho/config-teste.php php scripts/testar_bazar_integracao.php
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$api = $raiz . '/site/matricula-cursos-presenciais/api';
$configOriginal = (string) getenv('MCP_CONFIG_ARQUIVO');
if ($configOriginal === '' || !is_file($configOriginal)) {
    fwrite(STDERR, "Falta MCP_CONFIG_ARQUIVO com o banco local (veja o cabeçalho deste arquivo).\n");
    exit(2);
}
$porta = 21800 + random_int(0, 199);
$portaApi = $porta + 200;
$falso = sys_get_temp_dir() . '/mcp-bazar-falso-' . bin2hex(random_bytes(4));
mkdir($falso);
$temp = static function (string $conteudo) use ($falso): string {
    $arq = tempnam($falso, 'cfg-');
    file_put_contents($arq, $conteudo);
    return $arq;
};
const DESTINO = '21987650001'; // fictício
$configArq = "$falso/config.php";
$gravarConfig = static function (array $extra) use ($configArq, $configOriginal, $porta): void {
    file_put_contents($configArq, '<?php return ' . var_export($extra + ['WHATSAPP_KAPSO_BASE' => "http://127.0.0.1:$porta", 'WHATSAPP_KAPSO_CHAVE' => 'kapso-chave-de-teste-123',
        'WHATSAPP_KAPSO_NUMERO_ID' => '1112223334', 'SITE_URL' => 'http://127.0.0.1:1', 'RESEND_API_KEY' => ''], true) . ' + (require ' . var_export($configOriginal, true) . ');');
};
$gravarConfig(['WHATSAPP_BAZAR_AVISO' => DESTINO]);
$vazio = $temp('<?php return [];');
$ambiente = ['MCP_CONFIG_ARQUIVO' => $configArq, 'MCP_CONFIG_ESCOLA_ARQUIVO' => $vazio, 'MCP_CONFIG_WHATSAPP_ARQUIVO' => $vazio,
    'MCP_CONFIG_RECUPERACAO_ARQUIVO' => "$falso/sem-importados.php", 'MCP_CATALOGO_ARQUIVO' => $raiz . '/site/matricula-cursos-presenciais/cursos.json'];
foreach ($ambiente as $k => $v) {
    putenv("$k=$v");
}
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $api . '/lib.php';
restore_exception_handler();
if (!in_array((string) mcp_cfg('DB_HOST', 'localhost'), ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "DB_HOST não é local. Este teste grava e apaga dados; só roda num banco local.\n");
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

// ----------------------------------------------------------------------------- regras puras
verificar('módulo carregado pelo lib.php', function_exists('mcp_bazar_rodar'), true);
$bom = ['nome' => '  Maria   da Silva ', 'telefone' => '(21) 98765-4321', 'itens' => ['roupas', 'calcados', 'roupas', 'inventado'], 'descricao' => "3 sacos\nde roupa de criança", 'entrega' => 'esta-semana'];
$v = mcp_bazar_validar($bom);
verificar('validar: aceita o formulário certo', $v['ok'], true);
verificar('validar: telefone sem o 55, itens sem repetido nem inventado', [$v['dados']['telefone'], $v['dados']['itens'], $v['dados']['entrega']], ['21987654321', 'roupas,calcados', 'esta-semana']);
verificar('validar: nome obrigatório', mcp_bazar_validar(['nome' => 'A'] + $bom)['campo'] ?? null, 'nome');
verificar('validar: telefone fixo não serve', mcp_bazar_validar(['telefone' => '(21) 2222-3333'] + $bom)['campo'] ?? null, 'telefone');
verificar('validar: celular antigo ganha o 9', mcp_bazar_validar(['telefone' => '21 8765-4321'] + $bom)['dados']['telefone'] ?? null, '21987654321');
verificar('validar: pelo menos um item', mcp_bazar_validar(['itens' => []] + $bom)['campo'] ?? null, 'itens');
verificar('validar: "outros" pede a descrição', mcp_bazar_validar(['itens' => ['outros'], 'descricao' => ''] + $bom)['campo'] ?? null, 'descricao');
verificar('validar: descrição longa demais', mcp_bazar_validar(['descricao' => str_repeat('a', MCP_BAZAR_DESCRICAO_MAX + 1)] + $bom)['campo'] ?? null, 'descricao');
verificar('validar: entrega desconhecida vira "ainda não sabe"', mcp_bazar_validar(['entrega' => 'x'] + $bom)['dados']['entrega'] ?? null, 'nao-sei');
verificar('protocolo: BZ-aammdd-NNNN pela data de Brasília', mcp_bazar_protocolo(7, '2026-11-12 01:30:00'), 'BZ-261111-0007');
verificar('itens: rótulos e detalhes', mcp_bazar_itens_texto(['itens' => 'roupas,cozinha', 'descricao' => 'panelas']), 'Roupas, Utensílios de cozinha. Detalhes: panelas');
verificar('parâmetro: uma linha só, cortado, nunca vazio', [mcp_bazar_parametro("a\n\tb     c", 50), mcp_bazar_parametro(str_repeat('x', 30), 10), mcp_bazar_parametro('  ', 10)], ['a b c', 'xxxxxxxxx…', '-']);
$registro = $v['dados'] + ['protocolo' => 'BZ-261111-0007'];
$modelo = mcp_bazar_modelo($registro);
verificar('modelo: nome e os cinco parâmetros na ordem', $modelo, ['nome' => 'bazar_doacao_nova', 'parametros' => [
    'Maria da Silva', '(21) 98765-4321', 'Roupas, Calçados. Detalhes: 3 sacos de roupa de criança', 'Esta semana', 'BZ-261111-0007']]);
verificar('modelo: número de parâmetros é o da constante', count($modelo['parametros']), MCP_BAZAR_MODELO_PARAMS);
$corpo = mcp_bazar_corpo_envio('5521987650001', $modelo);
verificar('corpo: modelo em pt_BR, só o corpo, sem botão', [$corpo['to'], $corpo['type'], $corpo['template']['language']['code'], count($corpo['template']['components']), $corpo['template']['components'][0]['type']],
    ['5521987650001', 'template', 'pt_BR', 1, 'body']);
$certo = ['situacao' => 'APPROVED', 'params' => 5, 'botao' => -1];
verificar('conferência: aprovado e conferido passa', mcp_bazar_modelo_problema($certo), null);
foreach (['ausente' => ['situacao' => 'ausente'], 'em análise' => ['situacao' => 'PENDING'] + $certo, 'recusado' => ['situacao' => 'REJECTED'] + $certo, 'pausado' => ['situacao' => 'PAUSED'] + $certo,
    'outro número de parâmetros' => ['params' => 4] + $certo, 'sem os componentes' => ['params' => null] + $certo, 'botão com variável' => ['botao' => 0] + $certo] as $caso => $m) {
    verificar("conferência: $caso segura o aviso", is_string(mcp_bazar_modelo_problema($m)), true);
}
$brt = static fn(string $h): int => (new DateTimeImmutable("2026-11-12 $h", new DateTimeZone('America/Sao_Paulo')))->getTimestamp();
verificar('horário: 8h às 20h de Brasília', [mcp_bazar_no_horario($brt('07:59')), mcp_bazar_no_horario($brt('08:00')), mcp_bazar_no_horario($brt('19:59')), mcp_bazar_no_horario($brt('20:00'))], [false, true, true, false]);

// A página gerada tem as mesmas chaves que o servidor aceita (BAZAR_ITENS e BAZAR_ENTREGAS em gerar_institucional.py).
$pagina = (string) file_get_contents($raiz . '/site/bazar/index.html');
preg_match_all('/name="itens" value="([^"]+)"/', $pagina, $mi);
preg_match_all('/name="entrega" value="([^"]+)"/', $pagina, $me);
verificar('página: os itens do formulário são os do servidor', $mi[1], array_keys(MCP_BAZAR_ITENS));
verificar('página: as opções de entrega são as do servidor', $me[1], array_keys(MCP_BAZAR_ENTREGAS));
verificar('página: o formulário manda para api/bazar.php', str_contains($pagina, 'action="/matricula-cursos-presenciais/api/bazar.php"'), true);

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
    if (isset($controle['erro'])) {
        file_put_contents("$dir/recusas.jsonl", json_encode($d) . "\n", FILE_APPEND);
        http_response_code(400);
        echo json_encode(['error' => ['message' => 'recusado (teste)', 'code' => $controle['erro']]]);
        return true;
    }
    file_put_contents("$dir/kapso.jsonl", json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    echo json_encode(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.bz-' . count(file("$dir/kapso.jsonl"))]]]);
    return true;
}
if ($caminho === '/platform/v1/whatsapp/phone_numbers/1112223334') {
    if (!empty($controle['sem_modelos'])) { http_response_code(503); echo '{}'; return true; }
    echo '{"data":{"id":"1112223334","business_account_id":"998877665544"}}';
    return true;
}
if ($caminho === '/meta/whatsapp/v24.0/998877665544/message_templates') {
    $texto = $controle['texto'] ?? 'Nova doação. Quem doa: {{1}}. WhatsApp: {{2}}. Itens: {{3}}. Entrega: {{4}}. Protocolo: {{5}}. Fim.';
    $componentes = [['type' => 'BODY', 'text' => $texto]];
    if (!empty($controle['botao'])) { $componentes[] = ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Abrir', 'url' => 'https://exemplo.org/{{1}}']]]; }
    // Um modelo de nome parecido (o filtro da Meta é por trecho) vem antes, aprovado: não pode valer pelo do bazar.
    $lista = [['name' => 'bazar_doacao_nova_antigo', 'status' => 'APPROVED', 'language' => 'pt_BR', 'category' => 'UTILITY', 'components' => $componentes]];
    if (($controle['situacao'] ?? 'ausente') !== 'ausente') {
        $m = ['name' => 'bazar_doacao_nova', 'status' => $controle['situacao'], 'language' => 'pt_BR', 'category' => 'UTILITY'];
        if (empty($controle['sem_componentes'])) { $m['components'] = $componentes; }
        $lista[] = $m;
    }
    echo json_encode(['data' => $lista]);
    return true;
}
http_response_code(404);
echo '{}';
return true;
PHP);
$servidor = proc_open(['php', '-S', "127.0.0.1:$porta", "$falso/roteador.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $canos, null, ['FALSO_DIR' => $falso] + getenv());
$servidorApi = proc_open(['php', '-S', "127.0.0.1:$portaApi", '-t', $api], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $canosApi, null, $ambiente + getenv());
for ($i = 0; $i < 50 && !(@fsockopen('127.0.0.1', $porta) && @fsockopen('127.0.0.1', $portaApi)); $i++) {
    usleep(100000);
}
$enviadas = static fn(): array => is_file("$falso/kapso.jsonl") ? array_map(static fn(string $l): array => json_decode($l, true), file("$falso/kapso.jsonl", FILE_IGNORE_NEW_LINES)) : [];
$recusas = static fn(): int => is_file("$falso/recusas.jsonl") ? count(file("$falso/recusas.jsonl")) : 0;
$controle = static function (array $c) use ($falso): void {
    file_put_contents("$falso/controle.json", json_encode($c));
};
$db = mcp_db();
$inicioTeste = gmdate('Y-m-d H:i:s', time() - 5);
$marca = 'Teste Bazar ' . bin2hex(random_bytes(3));
$limpar = static function () use ($db, $marca, $inicioTeste): void {
    $db->prepare('DELETE FROM mcp_bazar_doacoes WHERE nome LIKE ?')->execute(["$marca%"]);
    $db->prepare("DELETE FROM mcp_eventos WHERE inscricao_id IS NULL AND criado_em >= ? AND tipo IN ('bazar', 'armadilha', 'limite')")->execute([$inicioTeste]);
};
$limpar();
// Um registro como o endpoint grava; $idade em segundos (para os que vencem).
$novo = static function (string $sufixo, int $idade = 0) use ($db, $marca): int {
    $id = mcp_bazar_gravar(['nome' => "$marca $sufixo", 'telefone' => '21987654321', 'itens' => 'roupas', 'descricao' => null, 'entrega' => 'nao-sei'], '203.0.113.9');
    mcp_bazar_atualizar($id, ['protocolo' => mcp_bazar_protocolo($id)]);
    if ($idade > 0) {
        $db->prepare('UPDATE mcp_bazar_doacoes SET criado_em = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - $idade), $id]);
    }
    return $id;
};
$situacao = static fn(int $id): array => array_intersect_key(mcp_bazar_por_id($id) ?? [], array_flip(['aviso_status', 'aviso_tentativas']));
$meioDia = (new DateTimeImmutable('today 12:00', new DateTimeZone('America/Sao_Paulo')))->getTimestamp();
if ($meioDia > time()) {
    $meioDia -= 86400; // um meio-dia que já passou: os registros de agora não são "do futuro" para a rodada
}
$rodar = static fn(?int $quando = null): array => mcp_bazar_rodar($quando ?? time());
// A rodada usa a hora que recebe; os registros são de agora. Para ela valer "no horário", passa-se um meio-dia.
$noHorario = static function () use ($db, $marca, $meioDia): int {
    $db->prepare('UPDATE mcp_bazar_doacoes SET criado_em = ? WHERE nome LIKE ? AND criado_em > ?')->execute([gmdate('Y-m-d H:i:s', $meioDia - 60), "$marca%", gmdate('Y-m-d H:i:s', $meioDia - 60)]);
    return $meioDia;
};

// ----------------------------------------------------------------------------- só dispara quando está tudo certo
$id1 = $novo('um');
foreach ([
    'modelo que não existe na Meta' => ['situacao' => 'ausente'],
    'modelo em análise' => ['situacao' => 'PENDING'],
    'modelo recusado' => ['situacao' => 'REJECTED'],
    'modelo aprovado com outro texto (4 parâmetros)' => ['situacao' => 'APPROVED', 'texto' => 'Doação de {{1}}, {{2}}, {{3}} e {{4}}. Fim.'],
    'modelo aprovado com botão de variável' => ['situacao' => 'APPROVED', 'botao' => true],
    'modelo aprovado sem os componentes' => ['situacao' => 'APPROVED', 'sem_componentes' => true],
    'Kapso sem a lista de modelos' => ['situacao' => 'APPROVED', 'sem_modelos' => true],
] as $caso => $c) {
    $controle($c);
    $r = $rodar($noHorario());
    verificar("segura: $caso → nada enviado, aviso pendente, tentativa não gasta",
        [$r['enviados'], is_string($r['motivo']), count($enviadas()), $situacao($id1)], [0, true, 0, ['aviso_status' => 'pendente', 'aviso_tentativas' => 0]]);
}
$controle(['situacao' => 'APPROVED']);
$gravarConfig(['WHATSAPP_BAZAR_AVISO' => '']);
$semDestino = (string) shell_exec('MCP_CONFIG_ARQUIVO=' . escapeshellarg($configArq) . ' php -r ' . escapeshellarg('$_SERVER["REQUEST_METHOD"]="CLI"; require ' . var_export($api . '/lib.php', true) . '; echo json_encode(mcp_bazar_pronto());'));
$gravarConfig(['WHATSAPP_BAZAR_AVISO' => DESTINO]);
verificar('segura: sem o celular de destino no servidor', str_contains($semDestino, 'WHATSAPP_BAZAR_AVISO'), true);
verificar('segura: nada saiu até aqui', count($enviadas()), 0);
$r = $rodar($brt('21:30'));
verificar('rodada: fora do horário não manda', [$r['enviados'], $r['motivo']], [0, 'fora do horário (8h às 20h)']);

// ----------------------------------------------------------------------------- com o modelo aprovado e conferido
$r = $rodar($noHorario());
$msg = $enviadas()[0] ?? [];
verificar('envia: uma mensagem, aviso enviado', [$r['enviados'], count($enviadas()), $situacao($id1)['aviso_status']], [1, 1, 'enviado']);
verificar('envia: para o celular do servidor, com o modelo do bazar', [$msg['to'] ?? null, $msg['template']['name'] ?? null], ['55' . DESTINO, 'bazar_doacao_nova']);
verificar('envia: os cinco parâmetros', array_column($msg['template']['components'][0]['parameters'] ?? [], 'text'),
    ["$marca um", '(21) 98765-4321', 'Roupas', 'Ainda não sabe', (string) mcp_bazar_por_id($id1)['protocolo']]);
verificar('envia: guarda o id da mensagem', mcp_bazar_por_id($id1)['aviso_provedor_id'], 'wamid.bz-1');
$rodar($noHorario());
verificar('envia: ninguém é avisado duas vezes do mesmo registro', count($enviadas()), 1);
// Duas rodadas ao mesmo tempo: a segunda não acha a linha como a leu.
$id2 = $novo('dois');
$linha = mcp_bazar_por_id($id2);
[, $cfg, $dest] = mcp_bazar_pronto();
verificar('concorrência: a primeira manda', mcp_bazar_avisar($linha, $cfg, (string) $dest), 'enviado');
verificar('concorrência: a segunda, com a linha velha, não manda', [mcp_bazar_avisar($linha, $cfg, (string) $dest), count($enviadas())], ['pendente', 2]);

// Pendentes velhos vencem: no dia em que o modelo é aprovado, não sai uma pilha de avisos antigos.
$controle(['situacao' => 'PENDING']);
$velho = $novo('velho', MCP_BAZAR_VALIDADE_S + 3600);
$recente = $novo('recente', 3600);
$r = $rodar();
verificar('vence: o de mais de 48 h vira vencido, o recente espera', [$r['vencidos'], $situacao($velho)['aviso_status'], $situacao($recente)['aviso_status']], [1, 'vencido', 'pendente']);
$controle(['situacao' => 'APPROVED']);
$r = $rodar($noHorario());
verificar('vence: aprovado depois, só o recente sai', [$r['enviados'], count($enviadas()), $situacao($velho)['aviso_status'], $situacao($recente)['aviso_status']], [1, 3, 'vencido', 'enviado']);

// Erros de envio.
$idErro = $novo('erro');
$controle(['situacao' => 'APPROVED', 'erro' => 131026]); // de quem recebe: número sem WhatsApp
$r = $rodar($noHorario());
verificar('erro de quem recebe: falhou, sem insistir', [$r['falhas'], $situacao($idErro)['aviso_status'], count($enviadas())], [1, 'falhou', 3]);
$idGlobal = $novo('global');
$idGlobal2 = $novo('global dois');
$antes = $recusas();
$controle(['situacao' => 'APPROVED', 'erro' => 132001]); // o modelo não existe/não está aprovado para envio: vale para todos
$r = $rodar($noHorario());
verificar('erro de todos: a rodada para no primeiro e não gasta a tentativa', [$r['enviados'], $recusas() - $antes, is_string($r['motivo']), $situacao($idGlobal), $situacao($idGlobal2)],
    [0, 1, true, ['aviso_status' => 'pendente', 'aviso_tentativas' => 0], ['aviso_status' => 'pendente', 'aviso_tentativas' => 0]]);
$controle(['situacao' => 'APPROVED']);
$r = $rodar($noHorario());
verificar('erro de todos: passou, os dois saem na rodada seguinte', [$r['enviados'], count($enviadas())], [2, 5]);
// Teto por rodada.
for ($i = 0; $i < MCP_BAZAR_POR_RODADA + 2; $i++) {
    $novo("teto $i");
}
$r = $rodar($noHorario());
verificar('teto: no máximo ' . MCP_BAZAR_POR_RODADA . ' por rodada, o resto fica', [$r['enviados'], $r['pendentes']], [MCP_BAZAR_POR_RODADA, 1]);

// ----------------------------------------------------------------------------- o e-mail à equipe
$m = mcp_montar_email_bazar_equipe(mcp_bazar_por_id($id1));
verificar('e-mail: assunto com o protocolo e corpo com o WhatsApp de quem doa', [str_starts_with($m['assunto'], 'Bazar: nova doação · BZ-'), str_contains($m['html'], 'https://wa.me/5521987654321'), str_contains($m['texto'], '(21) 98765-4321')], [true, true, true]);

// ----------------------------------------------------------------------------- o endpoint, por HTTP
$post = static function (array $corpo, array $cabecalhos = []) use ($portaApi): array {
    $ch = curl_init("http://127.0.0.1:$portaApi/bazar.php");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($corpo),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', ...$cabecalhos], CURLOPT_TIMEOUT => 60]);
    $r = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, is_string($r) ? (json_decode($r, true) ?: []) : []];
};
$form = ['nome' => "$marca http", 'telefone' => '(21) 97777-0001', 'itens' => ['roupas', 'cozinha'], 'descricao' => 'duas caixas', 'entrega' => 'proxima-semana', 'aceite' => true, 'site' => ''];
$controle(['situacao' => 'PENDING']);
$antes = count($enviadas());
[$status, $resp] = $post($form);
$linha = $db->query("SELECT * FROM mcp_bazar_doacoes WHERE telefone = '21977770001' ORDER BY id DESC LIMIT 1")->fetch() ?: [];
verificar('endpoint: 201 com o protocolo e o primeiro nome', [$status, $resp['ok'] ?? null, $resp['protocolo'] ?? null, $resp['nome'] ?? null], [201, true, $linha['protocolo'] ?? '?', 'Teste']);
verificar('endpoint: gravou o registro', [$linha['itens'] ?? null, $linha['descricao'] ?? null, $linha['entrega'] ?? null], ['roupas,cozinha', 'duas caixas', 'proxima-semana']);
verificar('endpoint: com o modelo em análise, nada sai e o aviso fica pendente', [count($enviadas()) - $antes, $linha['aviso_status'] ?? null], [0, 'pendente']);
$evento = (string) $db->query("SELECT detalhe FROM mcp_eventos WHERE tipo = 'bazar' ORDER BY id DESC LIMIT 1")->fetchColumn();
verificar('endpoint: o evento não leva nome nem telefone', [str_contains($evento, '#' . ($linha['id'] ?? '?')), str_contains($evento, 'Teste'), str_contains($evento, '97777')], [true, false, false]);
verificar('endpoint: armadilha preenchida', $post(['site' => 'x'] + $form)[0], 422);
verificar('endpoint: sem o aceite', $post(['aceite' => false] + $form)[1]['campo'] ?? null, 'aceite');
verificar('endpoint: telefone inválido diz o campo', $post(['telefone' => '123'] + $form)[1]['campo'] ?? null, 'telefone');
verificar('endpoint: sem item diz o campo', $post(['itens' => []] + $form)[1]['campo'] ?? null, 'itens');
verificar('endpoint: pedido de outro site é recusado', $post($form, ['Origin: https://outro.exemplo'])[0], 403);
verificar('endpoint: GET não vale', (int) substr((string) (get_headers("http://127.0.0.1:$portaApi/bazar.php")[0] ?? ''), 9, 3), 405);
$post($form);
$post($form);
verificar('endpoint: freio por telefone (' . MCP_BAZAR_LIMITE_TELEFONE[0] . ' por hora)', $post($form)[0], 429);
// Com o modelo aprovado, o aviso sai na hora do registro (se for horário comercial em Brasília).
$controle(['situacao' => 'APPROVED']);
$antes = count($enviadas());
[$status] = $post(['telefone' => '(21) 97777-0002'] + $form);
$linha = $db->query("SELECT * FROM mcp_bazar_doacoes WHERE telefone = '21977770002' ORDER BY id DESC LIMIT 1")->fetch() ?: [];
$esperado = mcp_bazar_no_horario(time()) ? [201, 1, 'enviado'] : [201, 0, 'pendente'];
verificar('endpoint: com o modelo aprovado, o aviso sai na hora (ou espera o horário)', [$status, count($enviadas()) - $antes, $linha['aviso_status'] ?? null], $esperado);

// ----------------------------------------------------------------------------- fim
$limpar();
foreach ([$servidor, $servidorApi] as $p) {
    proc_terminate($p);
    proc_close($p);
}
array_map('unlink', glob("$falso/*") ?: []);
@rmdir($falso);
echo $falhas === 0 ? "\nTudo certo: $total verificações.\n" : "\n$falhas de $total verificações falharam.\n";
exit($falhas === 0 ? 0 : 1);
