#!/usr/bin/env php
<?php
/**
 * Teste de ponta a ponta da página da coordenação do voluntariado (api/coordenacao.php), contra um MariaDB LOCAL:
 *   - sem a chave configurada: 404; chave errada: 404 e o registro do erro; chave certa: a lista;
 *   - a lista "Para decidir" com as fichas nova/em análise/entrevista, a mais antiga primeiro, com a foto de quem tem;
 *   - a foto (JPEG) só com a chave;
 *   - a ficha inteira (motivação, experiência, habilidades, WhatsApp) e a confirmação antes de decidir;
 *   - aprovar: cria o colaborador do ponto, a ficha fica aceita, sai o código de aprovação e o WhatsApp com a mensagem;
 *     repetir não troca o código; ficha sem CPF pede o CPF e recusa CPF errado;
 *   - recusar e desfazer a recusa; quem decidiu fica como "<COORDENACAO_NOME> · link da coordenação";
 *   - mandar e-mail (a uma Resend falsa em 127.0.0.1): texto pronto, para a pessoa, responder-para o voluntariado, anotado na
 *     ficha; mensagem curta recusada; o código por e-mail depois de aprovar;
 *   - recusar com motivo: a confirmação traz os motivos e a mensagem pronta; com "avisar", o e-mail sai e a anotação leva
 *     o motivo; avisar sem mensagem não recusa; a tela final oferece o WhatsApp com a mesma mensagem;
 *   - pedir a foto (ficha sem selfie): botões só nela, o link da ficha (api/voluntario-foto.php) abre o formulário, link
 *     errado dá 404, a foto enviada entra na ficha (JPEG regravado) e o link passa a dizer "já recebemos".
 * Mesmo modelo de testar_voluntarios_integracao.php (servidor embutido do PHP, CPFs sintéticos, tudo apagado no fim).
 *
 * Uso: MCP_CONFIG_ARQUIVO=/caminho/config-teste.php php scripts/testar_coordenacao_integracao.php
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$configOriginal = (string) getenv('MCP_CONFIG_ARQUIVO');
if ($configOriginal === '' || !is_file($configOriginal)) {
    fwrite(STDERR, "Falta MCP_CONFIG_ARQUIVO com o banco local.\n");
    exit(2);
}
$porta = 19500 + random_int(0, 400);
$portaResend = $porta + 1000;
$pastaResend = sys_get_temp_dir() . '/mcp-coord-resend-' . bin2hex(random_bytes(3));
mkdir($pastaResend);
file_put_contents("$pastaResend/index.php", '<?php file_put_contents(__DIR__ . "/ultimo.json", file_get_contents("php://input")); header("Content-Type: application/json"); echo json_encode(["id" => "teste"]);');
$chave = bin2hex(random_bytes(20));
$config = tempnam(sys_get_temp_dir(), 'mcp-coord-');
file_put_contents($config, '<?php return [\'SITE_URL\' => \'http://127.0.0.1:' . $porta . '\', \'ESCOLA_API_URL\' => \'\', \'RESEND_API_KEY\' => \'re_teste\', '
    . '\'RESEND_API_URL\' => \'http://127.0.0.1:' . $portaResend . '/emails\', \'EMAIL_VOLUNTARIADO\' => \'voluntariado@exemplo.org\'] + (require ' . var_export($configOriginal, true) . ');');
$coord = tempnam(sys_get_temp_dir(), 'mcp-coord-chave-');
$semChave = tempnam(sys_get_temp_dir(), 'mcp-coord-sem-');
file_put_contents($semChave, '<?php return [];');
$semExtra = $semChave;
putenv("MCP_CONFIG_ARQUIVO=$config");
foreach (['MCP_CONFIG_ESCOLA_ARQUIVO', 'MCP_CONFIG_WHATSAPP_ARQUIVO', 'MCP_CONFIG_META_ARQUIVO', 'MCP_CONFIG_PAGAR_TUDO_ARQUIVO', 'MCP_CONFIG_REDACAO_ARQUIVO', 'MCP_CONFIG_GOOGLE_ARQUIVO'] as $v) {
    putenv("$v=$semExtra");
}
putenv("MCP_CONFIG_COORDENACAO_ARQUIVO=$coord");
putenv('MCP_CATALOGO_ARQUIVO=' . $raiz . '/site/matricula-cursos-presenciais/cursos.json');
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $raiz . '/site/matricula-cursos-presenciais/api/lib.php';
restore_exception_handler();
if (!in_array((string) mcp_cfg('DB_HOST', 'localhost'), ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "DB_HOST não é local.\n");
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

$db = mcp_db();
function limpar(PDO $db): void
{
    $fichas = $db->query("SELECT id, cpf FROM mcp_voluntarios WHERE nome LIKE '%Teste Coordenação %'")->fetchAll();
    foreach ($fichas as $f) {
        $id = (int) $f['id'];
        $db->prepare('DELETE FROM mcp_voluntarios_selfies WHERE ficha_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM mcp_voluntarios_aprovacoes WHERE ficha_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM mcp_voluntarios_notas WHERE ficha_id = ?')->execute([$id]);
        $db->prepare("DELETE FROM mcp_eventos WHERE tipo = 'voluntario_foto_recebida' AND detalhe = ?")->execute(["#$id"]);
        $db->prepare('DELETE FROM mcp_voluntarios WHERE id = ?')->execute([$id]);
    }
    $db->exec("DELETE FROM mcp_colaboradores WHERE nome LIKE '%Teste Coordenação %'");
    $db->exec("DELETE FROM mcp_eventos WHERE tipo IN ('coordenacao_negado', 'voluntario_foto_envio') AND detalhe IN ('127.0.0.1', '::1')");
}
limpar($db);

function ficha(string $nome, ?string $cpf, string $status = 'nova', bool $foto = true): int
{
    $id = mcp_voluntario_gravar([
        'nome' => $nome, 'data_nascimento' => '1960-03-15', 'email' => 'coord' . random_int(1000, 99999) . '@exemplo.org', 'telefone' => '21987654321',
        'cpf' => $cpf, 'cidade' => 'Rio de Janeiro', 'bairro' => 'Tijuca', 'estado' => 'RJ', 'escolaridade' => 'superior_completo',
        'profissao' => 'Aposentada', 'saude' => 1, 'saude_qual' => 'Enfermagem', 'experiencia' => 'Ajudei na paróquia por dez anos.',
        'habilidades' => 'Costura e primeiros socorros.', 'frentes' => 'campanhas_acoes', 'dias' => 'sab', 'periodos' => 'manha',
        'motivacao' => 'Quero ajudar as pessoas do meu bairro quando precisarem.', 'como_conheceu' => array_key_first(MCP_VOL_COMO_CONHECEU), 'aceita_contato' => 1,
    ]);
    mcp_voluntario_atualizar($id, ['protocolo' => mcp_voluntario_protocolo($id)] + ($status !== 'nova' ? ['status' => $status] : []));
    if ($foto) {
        $img = imagecreatetruecolor(40, 40);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 16, 46));
        ob_start();
        imagejpeg($img);
        mcp_voluntario_selfie_gravar($id, ['largura' => 40, 'altura' => 40, 'jpeg' => (string) ob_get_clean()]);
    }
    return $id;
}

function http(string $url, string $metodo = 'GET', ?array $campos = null): array
{
    $ctx = stream_context_create(['http' => ['method' => $metodo, 'ignore_errors' => true, 'timeout' => 20, 'follow_location' => 0,
        'header' => $campos !== null ? "Content-Type: application/x-www-form-urlencoded\r\n" : '', 'content' => $campos !== null ? http_build_query($campos) : '']]);
    $corpo = @file_get_contents($url, false, $ctx);
    $linha = $http_response_header[0] ?? '';
    preg_match('#\s(\d{3})\s#', $linha . ' ', $m);
    $tipo = '';
    foreach ($http_response_header ?? [] as $h) {
        if (stripos($h, 'Content-Type:') === 0) {
            $tipo = trim(substr($h, 13));
        }
    }
    return ['status' => (int) ($m[1] ?? 0), 'corpo' => (string) $corpo, 'tipo' => $tipo];
}

$log = tempnam(sys_get_temp_dir(), 'mcp-coord-log-');
$nulo = [['file', '/dev/null', 'r'], ['file', $log, 'a'], ['file', $log, 'a']];
$servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$porta", '-t', $raiz . '/site', '-d', 'sendmail_path=/bin/true', '-d', 'display_errors=stderr'], $nulo, $tubos, null,
    ['MCP_CONFIG_ARQUIVO' => $config, 'MCP_CONFIG_COORDENACAO_ARQUIVO' => $coord, 'MCP_CONFIG_ESCOLA_ARQUIVO' => $semExtra, 'MCP_CONFIG_WHATSAPP_ARQUIVO' => $semExtra,
     'MCP_CONFIG_META_ARQUIVO' => $semExtra, 'MCP_CONFIG_PAGAR_TUDO_ARQUIVO' => $semExtra, 'MCP_CONFIG_REDACAO_ARQUIVO' => $semExtra, 'MCP_CONFIG_GOOGLE_ARQUIVO' => $semExtra,
     'MCP_CATALOGO_ARQUIVO' => $raiz . '/site/matricula-cursos-presenciais/cursos.json', 'PATH' => (string) getenv('PATH')]);
$resend = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaResend", "$pastaResend/index.php"], $nulo, $tubosResend);
$base = "http://127.0.0.1:$porta/matricula-cursos-presenciais/api/coordenacao.php";
for ($i = 0; $i < 50 && (@fsockopen('127.0.0.1', $porta) === false || @fsockopen('127.0.0.1', $portaResend) === false); $i++) {
    usleep(100000);
}

try {
    // Sem chave configurada: 404.
    file_put_contents($coord, '<?php return [];');
    verificar('sem COORDENACAO_CHAVE: 404', http("$base?k=qualquer")['status'], 404);
    file_put_contents($coord, '<?php return [\'COORDENACAO_CHAVE\' => \'curta\'];');
    verificar('chave curta demais: 404', http("$base?k=curta")['status'], 404);
    file_put_contents($coord, '<?php return [\'COORDENACAO_CHAVE\' => ' . var_export($chave, true) . ', \'COORDENACAO_NOME\' => \'Dona Teste\'];');

    $antes = mcp_contar_eventos_recentes('coordenacao_negado', '127.0.0.1', 3600);
    verificar('chave errada: 404', http("$base?k=errada")['status'], 404);
    verificar('sem chave no link: 404', http($base)['status'], 404);
    verificar('chave errada registra o erro (freio)', mcp_contar_eventos_recentes('coordenacao_negado', '127.0.0.1', 3600) - $antes, 2);

    $sufixo = bin2hex(random_bytes(2));
    $a = ficha("Ana Teste Coordenação $sufixo", cpf_de_teste());
    $b = ficha("Bia Teste Coordenação $sufixo", null, 'em_analise', false);
    $c = ficha("Caio Teste Coordenação $sufixo", cpf_de_teste(), 'aceita');
    $d = ficha("Duda Teste Coordenação $sufixo", cpf_de_teste());

    $k = '?k=' . $chave;
    $lista = http("$base$k");
    verificar('lista: 200 com a chave', $lista['status'], 200);
    verificar('lista: noindex', str_contains($lista['corpo'], 'noindex'), true);
    verificar('lista "Para decidir": Ana, Bia e Duda, não Caio (aceita)', [str_contains($lista['corpo'], "Ana Teste Coordenação $sufixo"), str_contains($lista['corpo'], "Bia Teste Coordenação $sufixo"),
        str_contains($lista['corpo'], "Duda Teste Coordenação $sufixo"), str_contains($lista['corpo'], "Caio Teste Coordenação $sufixo")], [true, true, true, false]);
    verificar('lista: a mais antiga primeiro', strpos($lista['corpo'], "Ana Teste") < strpos($lista['corpo'], "Duda Teste"), true);
    verificar('lista: foto de quem tem, "Sem foto" de quem não tem', [str_contains($lista['corpo'], 'foto=' . $a), str_contains($lista['corpo'], 'foto=' . $b)], [true, false]);
    verificar('lista: os links levam a chave', str_contains($lista['corpo'], 'coordenacao.php?k=' . $chave . '&amp;id=' . $a), true);
    $aprovados = http("$base$k&ver=aprovados");
    verificar('aba Aprovados mostra o Caio', str_contains($aprovados['corpo'], "Caio Teste Coordenação $sufixo"), true);

    $foto = http("$base$k&foto=$a");
    verificar('foto: JPEG com a chave', [$foto['status'], $foto['tipo'], str_starts_with($foto['corpo'], "\xFF\xD8")], [200, 'image/jpeg', true]);
    verificar('foto: sem a chave, 404', http("$base?foto=$a")['status'], 404);

    $ver = http("$base$k&id=$a");
    verificar('ficha: tudo o que a pessoa escreveu', [str_contains($ver['corpo'], 'Quero ajudar as pessoas do meu bairro'), str_contains($ver['corpo'], 'paróquia'),
        str_contains($ver['corpo'], 'Costura'), str_contains($ver['corpo'], 'Enfermagem'), str_contains($ver['corpo'], 'Tijuca')], [true, true, true, true, true]);
    verificar('ficha: WhatsApp e botões Aprovar e Recusar', [str_contains($ver['corpo'], 'https://wa.me/5521987654321'), str_contains($ver['corpo'], 'passo=aprovar'), str_contains($ver['corpo'], 'passo=recusar')], [true, true, true]);
    verificar('ficha: CPF só parcial', str_contains($ver['corpo'], (string) mcp_voluntario_por_id($a)['cpf']), false);

    $conf = http("$base$k&id=$a&passo=aprovar");
    verificar('confirmação de aprovar: pergunta e não decide sozinha', [str_contains($conf['corpo'], 'Sim, aprovar'), (string) mcp_voluntario_por_id($a)['status']], [true, 'nova']);

    $ok = http($base, 'POST', ['k' => $chave, 'acao' => 'aprovar', 'id' => $a]);
    $fa = mcp_voluntario_por_id($a);
    $cod = mcp_voluntario_aprovacao($a);
    verificar('aprovar: ficha aceita, com colaborador e código', [$ok['status'], (string) $fa['status'], (int) $fa['colaborador_id'] > 0, is_array($cod)], [200, 'aceita', true, true]);
    verificar('aprovar: quem decidiu', (string) $fa['status_por'], 'Dona Teste · link da coordenação');
    verificar('aprovar: mostra o código e o WhatsApp com a mensagem', [str_contains($ok['corpo'], mcp_codigo_formatado((string) $cod['codigo'])), str_contains($ok['corpo'], 'wa.me/5521987654321?text=')], [true, true]);
    verificar('aprovar: oferece a próxima ficha', str_contains($ok['corpo'], 'id=' . $b), true);
    $de_novo = http($base, 'POST', ['k' => $chave, 'acao' => 'aprovar', 'id' => $a]);
    verificar('aprovar de novo (recarregar a página) não troca o código', [(string) mcp_voluntario_aprovacao($a)['codigo'], str_contains($de_novo['corpo'], mcp_codigo_formatado((string) $cod['codigo']))], [(string) $cod['codigo'], true]);

    $semCpf = http("$base$k&id=$b&passo=aprovar");
    verificar('ficha sem CPF: a confirmação pede o CPF', str_contains($semCpf['corpo'], 'name="cpf"'), true);
    $cpfErrado = http($base, 'POST', ['k' => $chave, 'acao' => 'aprovar', 'id' => $b, 'cpf' => '111.111.111-12']);
    verificar('CPF errado: não aprova e avisa', [(string) mcp_voluntario_por_id($b)['status'], str_contains($cpfErrado['corpo'], 'CPF não está certo')], ['em_analise', true]);
    $cpfB = cpf_de_teste();
    http($base, 'POST', ['k' => $chave, 'acao' => 'aprovar', 'id' => $b, 'cpf' => $cpfB]);
    verificar('CPF certo: aprova e grava o CPF na ficha', [(string) mcp_voluntario_por_id($b)['status'], (string) mcp_voluntario_por_id($b)['cpf']], ['aceita', $cpfB]);

    $rec = http($base, 'POST', ['k' => $chave, 'acao' => 'recusar', 'id' => $d]);
    verificar('recusar: ficha recusada, com a nota', [(string) mcp_voluntario_por_id($d)['status'], count(mcp_voluntario_notas($d))], ['recusada', 1]);
    verificar('recusar sem mais nenhuma: volta para a lista com o aviso', str_contains($rec['corpo'], 'foi recusada'), true);
    verificar('recusar ficha aceita não acontece', [str_contains(http($base, 'POST', ['k' => $chave, 'acao' => 'recusar', 'id' => $c])['corpo'], 'já foi decidida'), (string) mcp_voluntario_por_id($c)['status']], [true, 'aceita']);
    http($base, 'POST', ['k' => $chave, 'acao' => 'desfazer', 'id' => $d]);
    verificar('desfazer a recusa: volta para em análise', (string) mcp_voluntario_por_id($d)['status'], 'em_analise');
    verificar('POST sem a chave: 404 e nada muda', [http($base, 'POST', ['acao' => 'recusar', 'id' => $d])['status'], (string) mcp_voluntario_por_id($d)['status']], [404, 'em_analise']);

    // Recusar com motivo e mensagem (Eva).
    $e = ficha("Eva Teste Coordenação $sufixo", cpf_de_teste());
    $confR = http("$base$k&id=$e&passo=recusar");
    verificar('confirmação da recusa: motivos, lotação marcada, mensagem pronta e "avisar" marcado', [str_contains($confR['corpo'], 'Lotação máxima'), (bool) preg_match('/value="lotacao" checked/', $confR['corpo']),
        str_contains($confR['corpo'], 'lotação máxima, e não conseguimos'), (bool) preg_match('/name="avisar" value="1" checked/', $confR['corpo']), str_contains($confR['corpo'], 'Não conseguimos falar com a pessoa')], [true, true, true, true, true]);
    $vazia = http($base, 'POST', ['k' => $chave, 'acao' => 'recusar', 'id' => $e, 'motivo' => 'lotacao', 'avisar' => '1', 'mensagem' => '']);
    verificar('avisar sem mensagem: não recusa e pede a mensagem', [(string) mcp_voluntario_por_id($e)['status'], str_contains($vazia['corpo'], 'Escreva a mensagem')], ['nova', true]);
    @unlink("$pastaResend/ultimo.json");
    $notasE = count(mcp_voluntario_notas($e));
    $recE = http($base, 'POST', ['k' => $chave, 'acao' => 'recusar', 'id' => $e, 'motivo' => 'lotacao', 'avisar' => '1', 'mensagem' => "Olá, Eva!\n\nAs vagas chegaram à lotação máxima."]);
    $saiu = json_decode((string) @file_get_contents("$pastaResend/ultimo.json"), true) ?: [];
    $notasTexto = implode(' | ', array_column(mcp_voluntario_notas($e), 'texto'));
    verificar('recusar com aviso: recusada, e-mail enviado, motivo e mensagem anotados', [(string) mcp_voluntario_por_id($e)['status'], str_contains((string) ($saiu['text'] ?? ''), 'lotação máxima'),
        str_contains($notasTexto, 'Motivo: Lotação máxima'), count(mcp_voluntario_notas($e)) - $notasE], ['recusada', true, true, 2]);
    verificar('tela final: e-mail enviado e WhatsApp com a mesma mensagem', [str_contains($recE['corpo'], 'foi enviada por e-mail'), str_contains($recE['corpo'], 'wa.me/5521987654321?text=' . rawurlencode("Olá, Eva!"))], [true, true]);

    // Pedir a foto (a Bia não tem selfie; a Ana tem).
    $verB = http("$base$k&id=$b");
    $verA = http("$base$k&id=$a");
    verificar('sem foto: botões de pedir a foto; com foto: não', [str_contains($verB['corpo'], 'Pedir a foto pelo WhatsApp'), str_contains($verB['corpo'], 'Pedir a foto por e-mail'), str_contains($verA['corpo'], 'Pedir a foto')], [true, true, false]);
    require_once $raiz . '/site/matricula-cursos-presenciais/api/lib/voluntario_foto.php';
    $linkFoto = mcp_vf_link($b);
    verificar('pedido de foto: o WhatsApp leva o link da ficha', str_contains($verB['corpo'], rawurlencode($linkFoto)), true);
    @unlink("$pastaResend/ultimo.json");
    preg_match('/name="mensagem" value="([^"]*)"><button class="btn azul" style="width:100%" type="submit">Pedir a foto por e-mail/', $verB['corpo'], $mf);
    http($base, 'POST', ['k' => $chave, 'acao' => 'email', 'id' => $b, 'mensagem' => html_entity_decode($mf[1] ?? '', ENT_QUOTES)]);
    $saiu = json_decode((string) @file_get_contents("$pastaResend/ultimo.json"), true) ?: [];
    verificar('pedido de foto por e-mail: sai com o link', str_contains((string) ($saiu['text'] ?? ''), $linkFoto), true);
    $form = http($linkFoto);
    verificar('link da foto: abre o formulário com o primeiro nome', [$form['status'], str_contains($form['corpo'], 'Olá, Bia!'), str_contains($form['corpo'], 'name="selfie"')], [200, true, true]);
    verificar('link da foto errado: 404', [http(preg_replace('/k=\w+/', 'k=errado', $linkFoto))['status'], http(str_replace('f=' . $b, 'f=' . $a, $linkFoto))['status']], [404, 404]);
    $img = imagecreatetruecolor(1200, 900);
    imagefill($img, 0, 0, imagecolorallocate($img, 30, 120, 200));
    ob_start();
    imagejpeg($img);
    $jpg = (string) ob_get_clean();
    $fronteira = 'teste' . bin2hex(random_bytes(6));
    preg_match('/[?&]k=(\w+)/', $linkFoto, $mk);
    $corpo = "--$fronteira\r\nContent-Disposition: form-data; name=\"f\"\r\n\r\n$b\r\n--$fronteira\r\nContent-Disposition: form-data; name=\"k\"\r\n\r\n{$mk[1]}\r\n"
        . "--$fronteira\r\nContent-Disposition: form-data; name=\"selfie\"; filename=\"eu.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n$jpg\r\n--$fronteira--\r\n";
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'header' => "Content-Type: multipart/form-data; boundary=$fronteira\r\n", 'content' => $corpo]]);
    $resp = (string) @file_get_contents("http://127.0.0.1:$porta/matricula-cursos-presenciais/api/voluntario-foto.php", false, $ctx);
    $sf = mcp_voluntario_selfie($b);
    verificar('foto enviada: entra na ficha, reduzida a 800 px', [str_contains($resp, 'Foto recebida'), $sf !== null, $sf['largura'] ?? null], [true, true, 800]);
    verificar('depois de enviar: o link diz "já recebemos" e a coordenadora vê a foto', [str_contains(http($linkFoto)['corpo'], 'Já recebemos'), str_contains(http("$base$k&id=$b")['corpo'], 'foto=' . $b)], [true, true]);

    // E-mail à pessoa.
    $email = (string) mcp_voluntario_por_id($d)['email'];
    $ver = http("$base$k&id=$d");
    verificar('ficha: bloco "Mandar e-mail" com o texto pronto', [str_contains($ver['corpo'], 'name="mensagem"'), str_contains($ver['corpo'], 'Olá, Duda!'), str_contains($ver['corpo'], 'href="#email"')], [true, true, true]);
    $notasAntes = count(mcp_voluntario_notas($d));
    @unlink("$pastaResend/ultimo.json");
    $env = http($base, 'POST', ['k' => $chave, 'acao' => 'email', 'id' => $d, 'mensagem' => "Olá, Duda!\n\nPodemos conversar no sábado às 10h?"]);
    $saiu = json_decode((string) @file_get_contents("$pastaResend/ultimo.json"), true) ?: [];
    verificar('e-mail: enviado para a pessoa, responder-para o voluntariado', [$env['status'], $saiu['to'] ?? null, $saiu['reply_to'] ?? null], [200, [$email], 'voluntariado@exemplo.org']);
    verificar('e-mail: o texto da coordenadora, com o protocolo no assunto', [str_contains((string) ($saiu['text'] ?? ''), 'sábado às 10h'), str_contains((string) ($saiu['subject'] ?? ''), (string) mcp_voluntario_por_id($d)['protocolo']),
        str_contains((string) ($saiu['html'] ?? ''), 'Podemos conversar no sábado')], [true, true, true]);
    verificar('e-mail: aviso na tela e anotação na ficha', [str_contains($env['corpo'], 'E-mail enviado para'), count(mcp_voluntario_notas($d)) - $notasAntes], [true, 1]);
    @unlink("$pastaResend/ultimo.json");
    $curto = http($base, 'POST', ['k' => $chave, 'acao' => 'email', 'id' => $d, 'mensagem' => 'oi']);
    verificar('e-mail curto demais: não envia e avisa', [is_file("$pastaResend/ultimo.json"), str_contains($curto['corpo'], 'curta demais')], [false, true]);
    $aprovadoEmail = http($base, 'POST', ['k' => $chave, 'acao' => 'aprovar', 'id' => $d]);
    verificar('depois de aprovar: botão "Mandar por e-mail" com o código', [str_contains($aprovadoEmail['corpo'], 'Mandar por e-mail'), str_contains($aprovadoEmail['corpo'], mcp_codigo_formatado((string) mcp_voluntario_aprovacao($d)['codigo']))], [true, true]);
    preg_match('/name="mensagem" value="([^"]*)"/', $aprovadoEmail['corpo'], $mm);
    http($base, 'POST', ['k' => $chave, 'acao' => 'email', 'id' => $d, 'mensagem' => html_entity_decode($mm[1] ?? '', ENT_QUOTES)]);
    $saiu = json_decode((string) @file_get_contents("$pastaResend/ultimo.json"), true) ?: [];
    verificar('código por e-mail: sai com o código e o link de conferência', [str_contains((string) ($saiu['text'] ?? ''), mcp_codigo_formatado((string) mcp_voluntario_aprovacao($d)['codigo'])), str_contains((string) ($saiu['text'] ?? ''), '/conferir/?c=')], [true, true]);

    $erros = array_values(array_filter(explode("\n", (string) file_get_contents($log)), static fn(string $l): bool => (bool) preg_match('/PHP (Warning|Notice|Fatal|Deprecated|Parse)/', $l)));
    verificar('sem aviso nem erro do PHP no servidor', $erros, []);
} finally {
    proc_terminate($servidor);
    proc_close($servidor);
    proc_terminate($resend);
    proc_close($resend);
    @unlink("$pastaResend/index.php");
    @unlink("$pastaResend/ultimo.json");
    @rmdir($pastaResend);
    limpar($db);
    @unlink($config);
    @unlink($coord);
    @unlink($semChave);
    @unlink($log);
}
echo "$total testes, $falhas falhas\n";
exit($falhas > 0 ? 1 : 0);
