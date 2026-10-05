#!/usr/bin/env php
<?php
/**
 * Teste de ponta a ponta da API de Conversões da Meta (api/lib/meta.php) com o site de verdade num PHP local,
 * um MariaDB local e um servidor falso (HTTPS em 127.0.0.1) que faz o papel da Meta, da Unicopag e da Resend:
 * nada sai para a internet, nenhum e-mail ou evento de verdade é enviado.
 *
 * Confere: o repasse de PageView/ViewContent (api/medicao.php) só com "sim" para marketing e do próprio site;
 * Lead e AddPaymentInfo na cobrança (com dados em hash, IP, navegador e _fbp); Purchase no postback, com o
 * event_id = token e os sinais guardados na inscrição, que saem do banco depois do envio; nada com "não";
 * a escolha retirada na página de acompanhamento vale para o Purchase; Contact do chat; ViewContent e Schedule da
 * página do Dia das Crianças; o formulário de doação de brinquedos (contato.php, assunto próprio, e-mails); falha
 * da Meta registrada sem atrapalhar o aluno; o freio do repasse.
 *
 * Uso:
 *   MCP_TESTE_DB_NOME=mcp_capi_teste MCP_TESTE_DB_USUARIO=... MCP_TESTE_DB_SENHA=... php scripts/testar_meta_integracao.php
 * O nome do banco precisa ter "teste" (as tabelas de inscrições, eventos e contatos são esvaziadas).
 * Precisa de php, python3 e openssl. Sai com 1 se algum teste falhar.
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$banco = (string) getenv('MCP_TESTE_DB_NOME');
$usuario = (string) getenv('MCP_TESTE_DB_USUARIO');
$senha = (string) getenv('MCP_TESTE_DB_SENHA');
if ($banco === '' || !str_contains($banco, 'teste') || $usuario === '') {
    fwrite(STDERR, "defina MCP_TESTE_DB_NOME (com \"teste\" no nome), MCP_TESTE_DB_USUARIO e MCP_TESTE_DB_SENHA\n");
    exit(2);
}

$falhas = 0;
$total = 0;
function verificar(string $nome, mixed $obtido, mixed $esperado): void
{
    global $falhas, $total;
    $total++;
    if ($obtido === $esperado) {
        echo "ok     $nome\n";
        return;
    }
    $falhas++;
    echo "FALHOU $nome\n  esperado: " . var_export($esperado, true) . "\n  obtido:   " . var_export($obtido, true) . "\n";
}

function porta_livre(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $porta = (int) substr(strrchr((string) stream_socket_get_name($s, false), ':'), 1);
    fclose($s);
    return $porta;
}

function esperar_porta(int $porta): void
{
    for ($i = 0; $i < 100; $i++) {
        $c = @fsockopen('127.0.0.1', $porta, $e, $m, 0.2);
        if ($c) {
            fclose($c);
            return;
        }
        usleep(100000);
    }
    throw new RuntimeException("porta $porta não abriu");
}

// ----------------------------------------------------------------------------- servidores
$dir = sys_get_temp_dir() . '/mcp-meta-' . bin2hex(random_bytes(4));
mkdir($dir);
$ca = "$dir/ca.pem";
$sub = sprintf('openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=teste-ca" -keyout %1$s/ca.key -out %1$s/ca.pem 2>/dev/null'
    . ' && openssl req -newkey rsa:2048 -nodes -subj "/CN=127.0.0.1" -keyout %1$s/srv.key -out %1$s/srv.csr 2>/dev/null'
    . ' && printf "subjectAltName=IP:127.0.0.1" > %1$s/ext.cnf'
    . ' && openssl x509 -req -in %1$s/srv.csr -CA %1$s/ca.pem -CAkey %1$s/ca.key -CAcreateserial -days 1 -extfile %1$s/ext.cnf -out %1$s/srv.pem 2>/dev/null', $dir);
exec($sub, $o, $codigo);
if ($codigo !== 0) {
    fwrite(STDERR, "openssl falhou\n");
    exit(2);
}

// Servidor falso: Meta (/vNN.N/<pixel>/events), Resend (/emails) e Unicopag (/public/v1/...). Grava cada pedido.
file_put_contents("$dir/falso.py", <<<'PY'
import json, os, re, ssl, sys, secrets
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
porta, dir_ = int(sys.argv[1]), sys.argv[2]
class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def responder(self, codigo, dados):
        corpo = json.dumps(dados).encode()
        self.send_response(codigo); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(corpo))); self.end_headers(); self.wfile.write(corpo)
    def tratar(self):
        tamanho = int(self.headers.get('Content-Length') or 0)
        corpo = self.rfile.read(tamanho).decode() if tamanho else ''
        caminho = self.path.split('?')[0]
        with open(os.path.join(dir_, 'pedidos.jsonl'), 'a') as f:
            f.write(json.dumps({'metodo': self.command, 'caminho': caminho, 'consulta': self.path.partition('?')[2], 'corpo': corpo}) + '\n')
        if re.match(r'^/v\d+\.\d/\d+/events$', caminho):
            if os.path.exists(os.path.join(dir_, 'recusar')):
                return self.responder(400, {'error': {'message': 'Invalid OAuth access token - Cannot parse access token', 'type': 'OAuthException', 'code': 190}})
            return self.responder(200, {'events_received': len(json.loads(corpo).get('data', [])), 'messages': [], 'fbtrace_id': 'falso'})
        if caminho == '/emails':
            return self.responder(200, {'id': 'email-falso'})
        if caminho == '/public/v1/payments':
            return self.responder(200, {'hash': 'h' + secrets.token_hex(6), 'payment_status': 'waiting_payment',
                                        'pix': {'pix_qr_code': '00020101PIXFALSO', 'pix_url': 'https://exemplo.org/pix', 'pix_base64': 'https://exemplo.org/qr.png'}})
        m = re.match(r'^/public/v1/transactions/(\w+)$', caminho)
        if m:
            pago = os.path.exists(os.path.join(dir_, 'pago-' + m.group(1)))
            return self.responder(200, {'hash': m.group(1), 'payment_status': 'paid' if pago else 'waiting_payment'})
        return self.responder(404, {})
    do_GET = do_POST = tratar
srv = ThreadingHTTPServer(('127.0.0.1', porta), H)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(os.path.join(dir_, 'srv.pem'), os.path.join(dir_, 'srv.key'))
srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
srv.serve_forever()
PY);
$portaFalso = porta_livre();
$portaSite = porta_livre();
$falso = proc_open(['python3', "$dir/falso.py", (string) $portaFalso, $dir], [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$dir/falso.err", 'w']], $p1);
$falsoUrl = "https://127.0.0.1:$portaFalso";
file_put_contents("$dir/config.php", '<?php return ' . var_export([
    'SITE_URL' => 'https://cruzvermelhariodejaneiro.org',
    'DB_HOST' => '127.0.0.1', 'DB_PORT' => 3306, 'DB_NAME' => $banco, 'DB_USER' => $usuario, 'DB_SENHA' => $senha,
    'UNICO_API_KEY' => 'chave-falsa', 'UNICO_BASE_URL' => $falsoUrl,
    'RESEND_API_KEY' => 'chave-falsa', 'RESEND_API_URL' => "$falsoUrl/emails",
    'EMAIL_CONTATO' => 'contato@exemplo.org', 'EMAIL_SECRETARIA' => 'secretaria@exemplo.org',
    'META_CAPI_TOKEN' => 'token-de-teste', 'META_CAPI_URL' => $falsoUrl,
], true) . ';');
$ambiente = [
    'MCP_CONFIG_ARQUIVO' => "$dir/config.php", 'MCP_CONFIG_ESCOLA_ARQUIVO' => "$dir/nao-existe.php",
    'MCP_CONFIG_WHATSAPP_ARQUIVO' => "$dir/nao-existe.php", 'MCP_CONFIG_META_ARQUIVO' => "$dir/nao-existe.php",
    'MCP_CATALOGO_ARQUIVO' => "$raiz/site/matricula-cursos-presenciais/cursos.json",
    'NO_PROXY' => '127.0.0.1,localhost', 'no_proxy' => '127.0.0.1,localhost', 'PATH' => (string) getenv('PATH'),
];
$site = proc_open([PHP_BINARY, '-d', "curl.cainfo=$ca", '-d', "openssl.cafile=$ca", '-S', "127.0.0.1:$portaSite", '-t', "$raiz/site"],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$dir/site.err", 'w']], $p2, null, $ambiente);
register_shutdown_function(static function () use ($falso, $site, $dir): void {
    foreach ([$site, $falso] as $p) {
        if (is_resource($p)) {
            proc_terminate($p);
            proc_close($p);
        }
    }
    exec('rm -rf ' . escapeshellarg($dir));
});
esperar_porta($portaFalso);
esperar_porta($portaSite);

$pdo = new PDO("mysql:host=127.0.0.1;dbname=$banco;charset=utf8mb4", $usuario, $senha, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

/** Pedido ao site local, como o navegador faria (Origin do site, cookies, IP 127.0.0.1). */
function pedir(string $metodo, string $caminho, ?array $corpo = null, array $cookies = [], array $extras = []): array
{
    global $portaSite;
    $ch = curl_init("http://127.0.0.1:$portaSite/matricula-cursos-presenciais/api/$caminho");
    $padrao = ['Origin' => 'Origin: https://cruzvermelhariodejaneiro.org', 'User-Agent' => 'User-Agent: Mozilla/5.0 (Teste de integração)', 'Accept' => 'Accept: application/json'];
    foreach ($extras as $extra) {
        unset($padrao[strstr($extra, ':', true)]); // o extra substitui o padrão ("Origin:" vazio = sem Origin, como um servidor)
    }
    $cabecalhos = array_merge(array_values($padrao), array_filter($extras, static fn($x) => !str_ends_with($x, ':')));
    if ($corpo !== null) {
        $cabecalhos[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo));
    }
    if ($cookies) {
        $cabecalhos[] = 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => $k . '=' . $v, array_keys($cookies), $cookies));
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $cabecalhos, CURLOPT_TIMEOUT => 30, CURLOPT_PROXY => '']);
    $resposta = (string) curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['http' => $http, 'corpo' => json_decode($resposta, true), 'bruto' => $resposta];
}

/** Eventos que a "Meta" recebeu desde a marca. */
function eventos_meta(int &$marca): array
{
    global $dir;
    $linhas = is_file("$dir/pedidos.jsonl") ? file("$dir/pedidos.jsonl", FILE_IGNORE_NEW_LINES) : [];
    $novas = array_slice($linhas, $marca);
    $marca = count($linhas);
    $eventos = [];
    foreach ($novas as $l) {
        $p = json_decode($l, true);
        if (preg_match('#^/v\d+\.\d/\d+/events$#', $p['caminho'])) {
            $c = json_decode($p['corpo'], true);
            foreach ($c['data'] as $e) {
                $e['_token'] = $c['access_token'] ?? null;
                $e['_caminho'] = $p['caminho'];
                $e['_consulta'] = $p['consulta'];
                $eventos[] = $e;
            }
        }
    }
    return $eventos;
}

/** CPF fictício: 9 dígitos quaisquer + os dois verificadores calculados. */
function cpf_ficticio(string $base): string
{
    for ($n = 9; $n <= 10; $n++) {
        $soma = 0;
        for ($i = 0; $i < $n; $i++) {
            $soma += (int) $base[$i] * ($n + 1 - $i);
        }
        $base .= (string) ((($soma * 10) % 11) % 10);
    }
    return $base;
}
/** Valor da coluna (null de verdade), ou 'ausente' se a coluna nem veio. */
function coluna(array $linha, string $nome): mixed
{
    return array_key_exists($nome, $linha) ? $linha[$nome] : 'ausente';
}
$SIM = ['cvrj_consentimento' => rawurlencode('v=1&e=1&m=1&t=' . time() . '&r=2'), '_fbp' => 'fb.1.1790940000123.1234567890'];
$NAO = ['cvrj_consentimento' => rawurlencode('v=1&e=1&m=0&t=' . time() . '&r=2')];
// "Sim" sem a revisão atual do texto (r=2): o aviso anterior, o da Punção ou um consentimento.js antigo no cache.
$SIM_ANTIGO = ['cvrj_consentimento' => rawurlencode('v=1&e=1&m=1&t=' . time()), '_fbp' => 'fb.1.1790940000123.1234567890'];
// O "sim" de outra pessoa que abre o link da inscrição (a mãe que paga, a secretaria): outro _fbp, outro navegador.
$SIM_TERCEIRO = ['cvrj_consentimento' => rawurlencode('v=1&e=1&m=1&t=' . time() . '&r=2'), '_fbp' => 'fb.1.1790940000999.9999999999'];
$idCompra = static fn(string $t): string => 'c.' . substr(hash('sha256', 'cvrj-meta-compra|' . $t), 0, 32);
$marca = 0;

// Primeiro pedido cria as tabelas (migração automática); depois o banco de teste começa vazio.
$r = pedir('GET', 'status.php?t=' . str_repeat('0', 40)); // abre o banco: a migração cria as tabelas
if ($r['http'] !== 404) { fwrite(STDERR, "site local não respondeu como esperado: HTTP {$r['http']} {$r['bruto']}\n" . @file_get_contents("$dir/site.err")); exit(2); }
foreach (['mcp_inscricoes', 'mcp_eventos', 'mcp_contatos'] as $tabela) {
    $pdo->exec("DELETE FROM $tabela");
}
// MariaDB mostra tinyint(3) unsigned; o MySQL 8, tinyint unsigned.
$colunasDb = array_map(static fn($t) => str_replace('tinyint(3) unsigned', 'tinyint unsigned', $t), array_column($pdo->query('SHOW COLUMNS FROM mcp_inscricoes')->fetchAll(), 'Type', 'Field'));
$colunasMeta = array_intersect_key($colunasDb, array_flip(['meta_marketing', 'meta_revisao', 'meta_fbp', 'meta_fbc', 'meta_ua', 'meta_ip']));
ksort($colunasMeta);
verificar('banco: colunas novas da inscrição (meta_fbc com 600 para o fbclid inteiro)', $colunasMeta,
    ['meta_fbc' => 'varchar(600)', 'meta_fbp' => 'varchar(255)', 'meta_ip' => 'varchar(45)', 'meta_marketing' => 'tinyint(1)', 'meta_revisao' => 'tinyint unsigned', 'meta_ua' => 'varchar(512)']);

// ----------------------------------------------------------------------------- repasse das páginas
$pv = ['evento' => 'PageView', 'id' => 'pv.mgb2k1.a8f3k2l1', 'url' => 'https://cruzvermelhariodejaneiro.org/matricula-cursos-presenciais/parabens/?t=' . str_repeat('a', 40) . '&utm_source=ig&fbclid=IwAR0abc'];
$r = pedir('POST', 'medicao.php', $pv);
verificar('repasse sem escolha: 204 e nada à Meta', [$r['http'], count(eventos_meta($marca))], [204, 0]);
pedir('POST', 'medicao.php', $pv, $NAO);
verificar('repasse com "não": nada à Meta', count(eventos_meta($marca)), 0);
pedir('POST', 'medicao.php', $pv, $SIM_ANTIGO);
verificar('repasse com "sim" sem a revisão atual do texto (r): nada à Meta', count(eventos_meta($marca)), 0);
$r = pedir('POST', 'medicao.php', ['evento' => ['PageView'], 'id' => 'pv.mgb2k1.lista123'], $SIM);
verificar('repasse com o evento em lista: 204 (não 500) e nada à Meta', [$r['http'], count(eventos_meta($marca))], [204, 0]);
pedir('POST', 'medicao.php', array_merge($pv, ['id' => "pv.mgb2k1.a8f3k2l9\n"]), $SIM);
verificar('repasse com quebra de linha no fim do id: nada à Meta', count(eventos_meta($marca)), 0);
pedir('POST', 'medicao.php', $pv, $SIM, ['Origin: https://outro-site.org']);
verificar('repasse de outro site: nada à Meta', count(eventos_meta($marca)), 0);
pedir('POST', 'medicao.php', ['evento' => 'Purchase', 'id' => 'pu.mgb2k1.a8f3k2l1'], $SIM);
verificar('repasse de evento que não é de página (Purchase): nada', count(eventos_meta($marca)), 0);
$r = pedir('POST', 'medicao.php', $pv, $SIM);
$e = eventos_meta($marca);
verificar('repasse do PageView com "sim"', [$r['http'], count($e), $e[0]['event_name'] ?? null, $e[0]['event_id'] ?? null, $e[0]['action_source'] ?? null], [204, 1, 'PageView', 'pv.mgb2k1.a8f3k2l1', 'website']);
verificar('repasse: endereço sem o t=, token no corpo e não na URL, versão e pixel no caminho', [
    $e[0]['event_source_url'] ?? null, $e[0]['_token'] ?? null, $e[0]['_consulta'] ?? null, (bool) preg_match('#^/v\d+\.\d/2224500131617302/events$#', $e[0]['_caminho'] ?? ''),
], ['https://cruzvermelhariodejaneiro.org/matricula-cursos-presenciais/parabens/?utm_source=ig&fbclid=IwAR0abc', 'token-de-teste', '', true]);
verificar('repasse: anônimo, com IP, navegador, _fbp e fbc do fbclid', [array_keys($e[0]['user_data'] ?? []), $e[0]['user_data']['client_ip_address'] ?? null,
    $e[0]['user_data']['client_user_agent'] ?? null, $e[0]['user_data']['fbp'] ?? null, (bool) preg_match('/^fb\.1\.\d{13}\.IwAR0abc$/', $e[0]['user_data']['fbc'] ?? '')],
    [['client_ip_address', 'client_user_agent', 'fbp', 'fbc'], '127.0.0.1', 'Mozilla/5.0 (Teste de integração)', 'fb.1.1790940000123.1234567890', true]);
pedir('POST', 'medicao.php', ['evento' => 'ViewContent', 'id' => 'vc.mgb2k1.a8f3k2l1', 'url' => 'https://cruzvermelhariodejaneiro.org/matricula-cursos-presenciais/', 'curso' => 'puncao-venosa'], $SIM);
pedir('POST', 'medicao.php', ['evento' => 'ViewContent', 'id' => 'vc.mgb2k1.zzzzzzzz', 'curso' => 'curso-que-nao-existe'], $SIM);
$e = eventos_meta($marca);
verificar('repasse do ViewContent com os dados do catálogo (curso inexistente não vai)', [count($e), $e[0]['custom_data']['content_ids'] ?? null, $e[0]['custom_data']['value'] ?? null, $e[0]['custom_data']['currency'] ?? null],
    [1, ['puncao-venosa'], 99, 'BRL']);
// Página do Dia das Crianças: ViewContent e Schedule ("salvar na agenda") com a chave da página; os dados saem do
// servidor. Schedule sem a chave, chave desconhecida ou em lista, e evento que não é da página não vão.
$urlCriancas = 'https://cruzvermelhariodejaneiro.org/dia-das-criancas/?utm_source=whatsapp';
pedir('POST', 'medicao.php', ['evento' => 'ViewContent', 'id' => 'vc.mgb2k1.criancas', 'url' => $urlCriancas, 'conteudo' => 'dia-das-criancas-2026'], $SIM);
pedir('POST', 'medicao.php', ['evento' => 'Schedule', 'id' => 'sc.mgb2k1.criancas', 'url' => $urlCriancas, 'conteudo' => 'dia-das-criancas-2026'], $SIM);
pedir('POST', 'medicao.php', ['evento' => 'Schedule', 'id' => 'sc.mgb2k1.semchave'], $SIM);
pedir('POST', 'medicao.php', ['evento' => 'Schedule', 'id' => 'sc.mgb2k1.outrapag', 'conteudo' => 'pagina-que-nao-existe'], $SIM);
pedir('POST', 'medicao.php', ['evento' => 'Schedule', 'id' => 'sc.mgb2k1.emlista', 'conteudo' => ['dia-das-criancas-2026']], $SIM);
pedir('POST', 'medicao.php', ['evento' => 'InitiateCheckout', 'id' => 'ic.mgb2k1.criancas', 'conteudo' => 'dia-das-criancas-2026'], $SIM);
pedir('POST', 'medicao.php', ['evento' => 'Schedule', 'id' => 'sc.mgb2k1.comnao', 'conteudo' => 'dia-das-criancas-2026'], $NAO);
$e = eventos_meta($marca);
verificar('Dia das Crianças: ViewContent e Schedule com os dados do servidor; o resto não vai', [array_column($e, 'event_name'), array_column($e, 'event_id'),
    $e[0]['custom_data'] ?? null, $e[1]['custom_data'] ?? null, $e[1]['event_source_url'] ?? null],
    [['ViewContent', 'Schedule'], ['vc.mgb2k1.criancas', 'sc.mgb2k1.criancas'],
        ['content_name' => 'Dia das Crianças na Praça', 'content_category' => 'evento', 'content_ids' => ['dia-das-criancas-2026']],
        ['content_name' => 'Dia das Crianças na Praça', 'content_category' => 'evento', 'content_ids' => ['dia-das-criancas-2026']], $urlCriancas]);

// ----------------------------------------------------------------------------- cobrança, postback e Purchase
$aluno = ['curso' => 'puncao-venosa', 'nome' => 'Maria da Silva Teste', 'cpf' => cpf_ficticio('900000001'), 'email' => 'Maria.Teste@Exemplo.org', 'telefone' => '(21) 99999-8888',
    'metodo' => 'pix', 'cobre_taxa' => false, 'requisitos' => true, 'site' => '', 'origem' => ['utm_source' => 'ig', 'fbclid' => 'IwAR0abc'], 'evento_id' => 'lead.mgb2k1.a8f3k2l1'];
$r = pedir('POST', 'pagamentos.php', $aluno, $SIM, ['Referer: https://cruzvermelhariodejaneiro.org/matricula-cursos-presenciais/checkout/?curso=puncao-venosa&t=x']);
$token = (string) ($r['corpo']['token'] ?? '');
$e = eventos_meta($marca);
$nomes = array_column($e, 'event_name');
verificar('cobrança com "sim": 201 e Lead + AddPaymentInfo com os ids do navegador (o da compra, um hash do token)', [$r['http'], $nomes, array_column($e, 'event_id'), $r['corpo']['id_compra'] ?? null],
    [201, ['Lead', 'AddPaymentInfo'], ['lead.mgb2k1.a8f3k2l1', $idCompra($token) . '-pagamento'], $idCompra($token)]);
$u = $e[0]['user_data'] ?? [];
verificar('cobrança: dados da pessoa em hash (e-mail, telefone com 55, nome, token da inscrição, país) e sinais do navegador', [
    $u['em'] ?? null, $u['ph'] ?? null, $u['fn'] ?? null, $u['ln'] ?? null, $u['external_id'] ?? null, $u['country'] ?? null,
    $u['client_ip_address'] ?? null, $u['fbp'] ?? null, $e[0]['event_source_url'] ?? null,
], [hash('sha256', 'maria.teste@exemplo.org'), hash('sha256', '5521999998888'), hash('sha256', 'maria'), hash('sha256', 'da silva teste'), hash('sha256', $token),
    hash('sha256', 'br'), '127.0.0.1', 'fb.1.1790940000123.1234567890', 'https://cruzvermelhariodejaneiro.org/matricula-cursos-presenciais/checkout/?curso=puncao-venosa']);
verificar('cobrança: o CPF não vai à Meta, nem em hash', str_contains(json_encode($e), hash('sha256', cpf_ficticio('900000001'))) || str_contains(json_encode($e), cpf_ficticio('900000001')), false);
$linha = $pdo->query("SELECT * FROM mcp_inscricoes WHERE token = " . $pdo->quote($token))->fetch() ?: [];
verificar('inscrição guarda a escolha, a revisão do texto, a data dela e os sinais do navegador (com o IP) para o Purchase', [$linha['meta_marketing'] ?? null, $linha['meta_revisao'] ?? null, !empty($linha['meta_marketing_em']),
    $linha['meta_fbp'] ?? null, $linha['meta_ua'] ?? null, $linha['meta_ip'] ?? null],
    [1, 2, true, 'fb.1.1790940000123.1234567890', 'Mozilla/5.0 (Teste de integração)', '127.0.0.1']);
$eventosDaInscricao = static fn(int $id): array => $pdo->query("SELECT tipo, detalhe FROM mcp_eventos WHERE inscricao_id = $id AND tipo LIKE 'meta_capi%' ORDER BY id")->fetchAll();
verificar('registro dos envios na inscrição', array_column($eventosDaInscricao((int) $linha['id']), 'detalhe'), ['Lead · HTTP 200', 'AddPaymentInfo · HTTP 200']);

touch("$dir/pago-{$linha['unicopag_hash']}");
$r = pedir('POST', 'webhook.php', ['hash' => $linha['unicopag_hash'], 'event' => 'transaction.paid'], [], ['Origin:']);
$e = eventos_meta($marca);
$depois = $pdo->query("SELECT status, meta_marketing, meta_fbp, meta_fbc, meta_ua, meta_ip FROM mcp_inscricoes WHERE id = {$linha['id']}")->fetch();
verificar('postback (sem navegador): Purchase com o id da compra, IP e navegador guardados', [
    $r['corpo']['status'] ?? null, array_column($e, 'event_name'), $e[0]['event_id'] ?? null, $e[0]['user_data']['client_ip_address'] ?? null,
    $e[0]['user_data']['client_user_agent'] ?? null, $e[0]['custom_data']['order_id'] ?? null, $e[0]['custom_data']['value'] ?? null, isset($e[0]['custom_data']['num_items']),
], ['pago', ['Purchase'], $idCompra($token), '127.0.0.1', 'Mozilla/5.0 (Teste de integração)', $idCompra($token), 99, false]);
// O pedidos.jsonl também guarda os pedidos ao provedor e à Resend (o e-mail leva o link com o token): só os da Meta contam.
$aMeta = array_filter(file("$dir/pedidos.jsonl", FILE_IGNORE_NEW_LINES), static fn($l) => (bool) preg_match('#^/v\d+\.\d/\d+/events$#', (string) (json_decode($l, true)['caminho'] ?? '')));
verificar('o token da inscrição não vai à Meta em nenhum evento', [count($aMeta) > 0, str_contains(implode("\n", $aMeta), $token)], [true, false]);
verificar('depois do Purchase, os sinais do navegador saem do banco (a escolha fica)', $depois, ['status' => 'pago', 'meta_marketing' => 1, 'meta_fbp' => null, 'meta_fbc' => null, 'meta_ua' => null, 'meta_ip' => null]);
$antes = $pdo->query("SELECT meta_marketing, meta_marketing_em FROM mcp_inscricoes WHERE id = {$linha['id']}")->fetch();
pedir('GET', 'status.php?t=' . $token, null, $NAO);
verificar('inscrição paga aberta por outra pessoa (o link do painel) não muda a prova do consentimento', $pdo->query("SELECT meta_marketing, meta_marketing_em FROM mcp_inscricoes WHERE id = {$linha['id']}")->fetch(), $antes);

// "Não" na cobrança: nada vai, nada fica guardado.
$r = pedir('POST', 'pagamentos.php', array_merge($aluno, ['cpf' => cpf_ficticio('900000002'), 'email' => 'nao@exemplo.org', 'evento_id' => 'lead.mgb2k1.nnnnnnnn']), $NAO);
$linhaNao = $pdo->query("SELECT * FROM mcp_inscricoes WHERE token = " . $pdo->quote((string) ($r['corpo']['token'] ?? '')))->fetch() ?: [];
touch("$dir/pago-{$linhaNao['unicopag_hash']}");
pedir('POST', 'webhook.php', ['hash' => $linhaNao['unicopag_hash']], [], ['Origin:']);
verificar('cobrança e pagamento com "não": nenhum evento e nenhum sinal guardado', [$r['http'], count(eventos_meta($marca)), coluna($linhaNao, 'meta_marketing'), coluna($linhaNao, 'meta_fbp'), coluna($linhaNao, 'meta_ua')],
    [201, 0, 0, null, null]);

// "Sim" antigo (antes do texto atual): nada vai e nada fica guardado além de "sem escolha".
$r = pedir('POST', 'pagamentos.php', array_merge($aluno, ['cpf' => cpf_ficticio('900000005'), 'email' => 'antigo@exemplo.org', 'evento_id' => 'lead.mgb2k1.aaaaaaaa']), $SIM_ANTIGO);
$linhaAntiga = $pdo->query("SELECT * FROM mcp_inscricoes WHERE token = " . $pdo->quote((string) ($r['corpo']['token'] ?? '')))->fetch() ?: [];
verificar('cobrança com "sim" sem a revisão atual do texto: nenhum evento, escolha nula e nada guardado', [$r['http'], count(eventos_meta($marca)), coluna($linhaAntiga, 'meta_marketing'), coluna($linhaAntiga, 'meta_fbp')],
    [201, 0, null, null]);
// Quem abre o link com "sim" não dá a permissão no lugar do aluno: nem sobre o "não" dele, nem sobre a falta de escolha.
pedir('GET', 'status.php?t=' . $linhaAntiga['token'], null, $SIM_TERCEIRO);
pedir('GET', 'status.php?t=' . $linhaNao['token'], null, $SIM_TERCEIRO);
$r = pedir('POST', 'pagamentos.php', array_merge($aluno, ['cpf' => cpf_ficticio('900000006'), 'email' => 'recusou@exemplo.org', 'evento_id' => 'lead.mgb2k1.ffffffff']), $NAO);
$tokenRecusou = (string) ($r['corpo']['token'] ?? '');
pedir('GET', 'status.php?t=' . $tokenRecusou, null, $SIM_TERCEIRO);
$linhaRecusou = $pdo->query("SELECT * FROM mcp_inscricoes WHERE token = " . $pdo->quote($tokenRecusou))->fetch() ?: [];
touch("$dir/pago-{$linhaRecusou['unicopag_hash']}");
pedir('POST', 'webhook.php', ['hash' => $linhaRecusou['unicopag_hash']], [], ['Origin:']);
$linhaAntiga = $pdo->query("SELECT * FROM mcp_inscricoes WHERE id = " . (int) $linhaAntiga['id'])->fetch() ?: [];
verificar('o "sim" de quem abre o link não vira o do aluno (com "não" ou sem escolha), e o Purchase não sai', [
    coluna($linhaRecusou, 'meta_marketing'), coluna($linhaRecusou, 'meta_fbp'), coluna($linhaRecusou, 'meta_ua'), coluna($linhaAntiga, 'meta_marketing'), coluna($linhaAntiga, 'meta_fbp'), count(eventos_meta($marca)),
], [0, null, null, null, null, 0]);

// "Sim" na cobrança, "não" depois, na página de acompanhamento: o Purchase não vai.
$r = pedir('POST', 'pagamentos.php', array_merge($aluno, ['cpf' => cpf_ficticio('900000003'), 'email' => 'mudou@exemplo.org', 'evento_id' => 'lead.mgb2k1.mmmmmmmm']), $SIM);
$tokenMudou = (string) ($r['corpo']['token'] ?? '');
eventos_meta($marca);
pedir('GET', 'status.php?t=' . $tokenMudou, null, $NAO);
$linhaMudou = $pdo->query("SELECT * FROM mcp_inscricoes WHERE token = " . $pdo->quote($tokenMudou))->fetch() ?: [];
touch("$dir/pago-{$linhaMudou['unicopag_hash']}");
pedir('POST', 'webhook.php', ['hash' => $linhaMudou['unicopag_hash']], [], ['Origin:']);
verificar('escolha retirada na página de acompanhamento vale para o Purchase (e fica registrada, com a anterior)', [coluna($linhaMudou, 'meta_marketing'), coluna($linhaMudou, 'meta_fbp'), coluna($linhaMudou, 'meta_ip'),
    coluna($linhaMudou, 'meta_revisao'), array_column(eventos_meta($marca), 'event_name'),
    (bool) preg_match('/^retirada: sim de \d{4}-\d\d-\d\d \d\d:\d\d:\d\d → não de /', (string) $pdo->query("SELECT detalhe FROM mcp_eventos WHERE tipo = 'meta_escolha' AND inscricao_id = {$linhaMudou['id']}")->fetchColumn())],
    [0, null, null, null, [], true]);

// PIX refeito com o mesmo CPF e "não" no cookie: o "sim" do primeiro pedido deixa de valer.
$alunoPix = array_merge($aluno, ['cpf' => cpf_ficticio('900000007'), 'email' => 'refez@exemplo.org', 'evento_id' => 'lead.mgb2k1.pppppppp']);
$r = pedir('POST', 'pagamentos.php', $alunoPix, $SIM);
$tokenPix = (string) ($r['corpo']['token'] ?? '');
eventos_meta($marca);
$r = pedir('POST', 'pagamentos.php', array_merge($alunoPix, ['evento_id' => 'lead.mgb2k1.qqqqqqqq']), $NAO);
$linhaPix = $pdo->query("SELECT * FROM mcp_inscricoes WHERE token = " . $pdo->quote($tokenPix))->fetch() ?: [];
touch("$dir/pago-{$linhaPix['unicopag_hash']}");
pedir('POST', 'webhook.php', ['hash' => $linhaPix['unicopag_hash']], [], ['Origin:']);
verificar('PIX reaproveitado com "não": a escolha guardada é retirada e o Purchase não sai', [$r['corpo']['reaproveitado'] ?? null, $r['corpo']['token'] ?? null, coluna($linhaPix, 'meta_marketing'),
    coluna($linhaPix, 'meta_fbp'), array_column(eventos_meta($marca), 'event_name')], [true, $tokenPix, 0, null, []]);
// Mesmo caso, mas o PIX antigo já foi pago no banco e ninguém consultou: a reconsulta do pedido novo acha o
// pagamento, e a retirada que veio nele já vale para o Purchase.
$alunoPago = array_merge($aluno, ['cpf' => cpf_ficticio('900000008'), 'email' => 'pagou@exemplo.org', 'evento_id' => 'lead.mgb2k1.gggggggg']);
$r = pedir('POST', 'pagamentos.php', $alunoPago, $SIM);
$linhaPago = $pdo->query("SELECT * FROM mcp_inscricoes WHERE token = " . $pdo->quote((string) ($r['corpo']['token'] ?? '')))->fetch() ?: [];
eventos_meta($marca);
touch("$dir/pago-{$linhaPago['unicopag_hash']}");
$r = pedir('POST', 'pagamentos.php', array_merge($alunoPago, ['evento_id' => 'lead.mgb2k1.hhhhhhhh']), $NAO);
$linhaPago = $pdo->query("SELECT * FROM mcp_inscricoes WHERE id = " . (int) $linhaPago['id'])->fetch() ?: [];
verificar('PIX antigo pago, achado pela reconsulta de um pedido com "não": vira pago sem Purchase', [coluna($linhaPago, 'status'), coluna($linhaPago, 'meta_marketing'),
    in_array('Purchase', array_column(eventos_meta($marca), 'event_name'), true)], ['pago', 0, false]);

// ----------------------------------------------------------------------------- chat
$contato = ['nome' => 'Ana Lima', 'email' => 'ana@exemplo.org', 'telefone' => '', 'assunto' => 'matricula', 'curso' => 'puncao-venosa',
    'mensagem' => 'Queria saber a data da próxima turma de punção venosa.', 'pagina' => '/matricula-cursos-presenciais/?t=segredo&utm_source=ig', 'origem' => [], 'site' => '', 'evento_id' => 'ct.mgb2k1.a8f3k2l1'];
$r = pedir('POST', 'contato.php', $contato, $SIM);
$e = eventos_meta($marca);
verificar('chat com "sim": Contact com o id do chat, e-mail em hash e página limpa', [$r['http'], array_column($e, 'event_name'), $e[0]['event_id'] ?? null,
    $e[0]['user_data']['em'] ?? null, isset($e[0]['user_data']['ph']), $e[0]['event_source_url'] ?? null],
    [201, ['Contact'], 'ct.mgb2k1.a8f3k2l1', hash('sha256', 'ana@exemplo.org'), false, 'https://cruzvermelhariodejaneiro.org/matricula-cursos-presenciais/?utm_source=ig']);

// Formulário de doação de brinquedos da página do Dia das Crianças: o mesmo contato.php, com assunto próprio.
$doacao = ['nome' => 'Beatriz Souza', 'email' => 'bia@exemplo.org', 'telefone' => '(21) 98765-4321', 'assunto' => 'brinquedos', 'curso' => 'puncao-venosa',
    'mensagem' => "Empresa ou grupo: Loja Exemplo\n\nTemos 40 bonecas novas para doar.", 'pagina' => '/dia-das-criancas/',
    'origem' => ['utm_source' => 'instagram', 'utm_campaign' => 'dia-das-criancas'], 'site' => '', 'evento_id' => 'ct.mgb2k1.brinqued'];
$linhasAntes = count(file("$dir/pedidos.jsonl"));
$r = pedir('POST', 'contato.php', $doacao, $SIM);
$e = eventos_meta($marca);
$linhaDoacao = $pdo->query("SELECT assunto, curso_slug, pagina, utm_source, telefone FROM mcp_contatos WHERE email = 'bia@exemplo.org'")->fetch();
$emails = [];
foreach (array_slice(file("$dir/pedidos.jsonl", FILE_IGNORE_NEW_LINES), $linhasAntes) as $l) {
    $pedido = json_decode($l, true);
    if ($pedido['caminho'] === '/emails') {
        $emails[] = json_decode($pedido['corpo'], true);
    }
}
$paraEquipe = array_values(array_filter($emails, static fn($m) => in_array('contato@exemplo.org', (array) ($m['to'] ?? []), true)))[0] ?? [];
$paraPessoa = array_values(array_filter($emails, static fn($m) => in_array('bia@exemplo.org', (array) ($m['to'] ?? []), true)))[0] ?? [];
verificar('doação de brinquedos: 201, gravada com o assunto próprio e sem curso, Contact com a categoria e o id do formulário', [$r['http'],
    $linhaDoacao, array_column($e, 'event_name'), $e[0]['event_id'] ?? null, $e[0]['custom_data'] ?? null, isset($e[0]['user_data']['ph'])],
    [201, ['assunto' => 'brinquedos', 'curso_slug' => null, 'pagina' => '/dia-das-criancas/', 'utm_source' => 'instagram', 'telefone' => '21987654321'],
        ['Contact'], 'ct.mgb2k1.brinqued', ['content_category' => 'brinquedos', 'content_name' => 'brinquedos'], true]);
verificar('doação de brinquedos: aviso à equipe diz de onde veio (formulário, não o chat) e confirmação fala da entrega', [
    $paraEquipe['subject'] ?? null, str_contains($paraEquipe['text'] ?? '', 'escreveu pelo formulário de doação do Dia das Crianças sobre doação de brinquedos'),
    str_contains($paraEquipe['html'] ?? '', 'Formulário de doação'), str_contains($paraEquipe['html'] ?? '', 'pelo chat'),
    str_contains($paraPessoa['text'] ?? '', 'combinar a entrega da doação'), str_contains($paraPessoa['html'] ?? '', 'pelo formulário de doação do Dia das Crianças')],
    ['[Site] Doação de brinquedos (Dia das Crianças): Beatriz Souza · ' . ($r['corpo']['protocolo'] ?? ''), true, true, false, true, true]);
// O chat continua com os textos de sempre.
$textoChat = '';
foreach (array_slice(file("$dir/pedidos.jsonl", FILE_IGNORE_NEW_LINES), 0, $linhasAntes) as $l) {
    $pedido = json_decode($l, true);
    $m = $pedido['caminho'] === '/emails' ? json_decode($pedido['corpo'], true) : [];
    if (in_array('contato@exemplo.org', (array) ($m['to'] ?? []), true) && str_contains((string) ($m['subject'] ?? ''), 'Ana Lima')) {
        $textoChat = (string) $m['text'];
    }
}
verificar('chat: o aviso à equipe continua dizendo "pelo chat do site"', str_contains($textoChat, 'Ana Lima escreveu pelo chat do site sobre matrícula em cursos'), true);

// ----------------------------------------------------------------------------- Meta recusando (token vencido)
touch("$dir/recusar");
$r = pedir('POST', 'pagamentos.php', array_merge($aluno, ['cpf' => cpf_ficticio('900000004'), 'email' => 'recusa@exemplo.org', 'evento_id' => 'lead.mgb2k1.rrrrrrrr']), $SIM);
$linhaRecusa = $pdo->query("SELECT id FROM mcp_inscricoes WHERE token = " . $pdo->quote((string) ($r['corpo']['token'] ?? '')))->fetch() ?: ['id' => 0];
$registros = $eventosDaInscricao((int) $linhaRecusa['id']);
verificar('Meta recusando: o aluno recebe o PIX igual, e a falha fica registrada com a mensagem', [$r['http'], isset($r['corpo']['pix']['copia_cola']), array_column($registros, 'tipo'),
    str_contains((string) ($registros[0]['detalhe'] ?? ''), 'HTTP 400 · Invalid OAuth access token')], [201, true, ['meta_capi_falha', 'meta_capi_falha'], true]);
pedir('POST', 'medicao.php', array_merge($pv, ['id' => 'pv.mgb2k1.falha123']), $SIM);
pedir('POST', 'medicao.php', array_merge($pv, ['id' => 'pv.mgb2k1.falha456']), $SIM);
verificar('repasse com a Meta recusando: uma linha de falha a cada 10 minutos, não uma por evento',
    (int) $pdo->query("SELECT COUNT(*) FROM mcp_eventos WHERE tipo = 'meta_capi_falha' AND detalhe = 'repasse'")->fetchColumn(), 1);
unlink("$dir/recusar");
eventos_meta($marca);

// ----------------------------------------------------------------------------- freio do repasse
$pdo->exec("DELETE FROM mcp_eventos WHERE tipo = 'meta_repasse'");
for ($i = 0; $i < 25; $i++) {
    pedir('POST', 'medicao.php', array_merge($pv, ['id' => sprintf('pv.freio.%08d', $i)]), $SIM);
}
verificar('freio do repasse: 20 por IP em um minuto', [count(eventos_meta($marca)), (int) $pdo->query("SELECT COUNT(*) FROM mcp_eventos WHERE tipo = 'meta_repasse'")->fetchColumn()], [20, 20]);
// Os 20 de agora vão para 2 minutos atrás, e mais 100 do mesmo IP há 5 minutos: 120 em 10 minutos.
$pdo->exec("UPDATE mcp_eventos SET criado_em = '" . gmdate('Y-m-d H:i:s', time() - 120) . "' WHERE tipo = 'meta_repasse'");
$cincoMin = gmdate('Y-m-d H:i:s', time() - 300);
$pdo->exec("INSERT INTO mcp_eventos (inscricao_id, tipo, detalhe, criado_em) VALUES " . implode(',', array_fill(0, 100, "(NULL, 'meta_repasse', '127.0.0.1', '$cincoMin')")));
pedir('POST', 'medicao.php', array_merge($pv, ['id' => 'pv.freio.dezminutos']), $SIM);
verificar('freio do repasse: 120 por IP em 10 minutos', count(eventos_meta($marca)), 0);
$r = pedir('POST', 'pagamentos.php', array_merge($aluno, ['cpf' => cpf_ficticio('900000009'), 'email' => 'r999@exemplo.org', 'evento_id' => 'lead.mgb2k1.kkkkkkkk']),
    ['cvrj_consentimento' => rawurlencode('v=1&e=1&m=1&t=' . time() . '&r=999'), '_fbp' => 'fb.1.1790940000123.1234567890']);
$linhaR999 = $pdo->query("SELECT * FROM mcp_inscricoes WHERE token = " . $pdo->quote((string) ($r['corpo']['token'] ?? '')))->fetch() ?: [];
verificar('cookie com r=999: a inscrição é gravada (201), sem escolha de marketing', [$r['http'], (bool) $linhaR999, coluna($linhaR999, 'meta_marketing'), coluna($linhaR999, 'meta_revisao')], [201, true, null, null]);
eventos_meta($marca);
// Código novo com o esquema anterior (meta.php no ar antes do db.php, opcache): a cobrança já existe no provedor,
// então a inscrição tem de ser gravada mesmo sem a coluna; a escolha fica vazia e o Purchase não sai.
$pdo->exec('ALTER TABLE mcp_inscricoes DROP COLUMN meta_ip');
$r = pedir('POST', 'pagamentos.php', array_merge($aluno, ['cpf' => cpf_ficticio('900000010'), 'email' => 'esquema@exemplo.org', 'evento_id' => 'lead.mgb2k1.llllllll']), $SIM);
$pdo->exec('ALTER TABLE mcp_inscricoes ADD COLUMN meta_ip VARCHAR(45) NULL');
$linhaEsquema = $pdo->query("SELECT * FROM mcp_inscricoes WHERE token = " . $pdo->quote((string) ($r['corpo']['token'] ?? '')))->fetch() ?: [];
verificar('coluna nova ainda ausente: a inscrição é gravada (201) e a escolha fica vazia (sem Purchase)', [$r['http'], (bool) $linhaEsquema, coluna($linhaEsquema, 'meta_marketing')], [201, true, null]);
eventos_meta($marca);
// Teto do site inteiro: 120 repasses no último minuto, de outros IPs, seguram também quem ainda não usou nada.
$pdo->exec("DELETE FROM mcp_eventos WHERE tipo = 'meta_repasse'");
$agora = gmdate('Y-m-d H:i:s');
$pdo->exec("INSERT INTO mcp_eventos (inscricao_id, tipo, detalhe, criado_em) VALUES " . implode(',', array_map(static fn($i) => "(NULL, 'meta_repasse', '198.51.100." . ($i % 250) . "', '$agora')", range(1, 120))));
pedir('POST', 'medicao.php', array_merge($pv, ['id' => 'pv.teto.00000001']), $SIM);
pedir('POST', 'medicao.php', array_merge($pv, ['id' => 'pv.teto.00000003']), $SIM);
$teto = count(eventos_meta($marca));
$avisosTeto = (int) $pdo->query("SELECT COUNT(*) FROM mcp_eventos WHERE tipo = 'meta_capi_aviso' AND detalhe = 'teto'")->fetchColumn();
$pdo->exec("UPDATE mcp_eventos SET criado_em = '" . gmdate('Y-m-d H:i:s', time() - 120) . "' WHERE tipo = 'meta_repasse'");
pedir('POST', 'medicao.php', array_merge($pv, ['id' => 'pv.teto.00000002']), $SIM);
verificar('teto do repasse: 120 por minuto no site inteiro, antes do freio por IP, com um aviso só', [$teto, $avisosTeto, count(eventos_meta($marca))], [0, 1, 1]);

$erros = trim((string) @file_get_contents("$dir/site.err"));
$erros = implode("\n", array_filter(explode("\n", $erros), static fn($l) => !preg_match('/(Development Server|Accepted|Closing|Closed without sending a request|\[200\]|\[201\]|\[204\]|\[404\]|\[402\]|Listening|Press Ctrl|\[matricula\] API de Conversões: PageView · HTTP 400|\[matricula\] API de Conversões: teto de 120 repasses|\[matricula\] escolha de marketing da inscrição não gravada: PDOException: SQLSTATE\[42S22\])/', $l)));
verificar('nenhum erro do PHP no servidor local', $erros, '');
printf("\n%d testes, %d falhas\n", $total, $falhas);
exit($falhas > 0 ? 1 : 0);
