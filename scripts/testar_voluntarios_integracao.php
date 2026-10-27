#!/usr/bin/env php
<?php
/**
 * Teste de ponta a ponta do cadastro de voluntários, contra um MariaDB LOCAL. Cobre:
 *   - api/voluntarios.php em JSON: ficha válida (201, linha com consentimento e versão da política), repetida (409),
 *     menor de 18 (422 em data_nascimento), adulta (201 com menor = false), armadilha (422), freios por e-mail e
 *     por IP (429), registro em mcp_eventos sem CPF;
 *   - o modo sem JavaScript (formulário): 303 com ?protocolo= ou ?erro=<campo>;
 *   - o acompanhamento (lib/voluntarios.php): situação, notas, aceitação com CPF (cria o colaborador do ponto com
 *     vínculo voluntário, reaproveita e reativa o que já existe), aceitação sem CPF (erro), lista, contagens, CSV sem
 *     CPF, PDF e o expurgo de 12 meses (apaga a recusada antiga, poupa a aceita);
 *   - a selfie opcional (27/10/2026): no JSON (data URL) e no formulário (multipart), recusa do que não é foto, corpo
 *     grande demais (413), gravação reduzida e sem EXIF e a exclusão junto com a ficha no expurgo;
 *   - a ligação com a Redação (api/redacao-voluntarios.php): token, lista paginada com a selfie, aprovar e recusar.
 * As chamadas passam pelo servidor embutido do PHP, como as de um navegador; o teste sobe e derruba o servidor pelo PID.
 *
 * Precisa de MCP_CONFIG_ARQUIVO com o banco local (DB_HOST 127.0.0.1 ou localhost) e sem RESEND_API_KEY (nenhum e-mail
 * sai; o sendmail do servidor embutido é /bin/true). CPFs sintéticos; tudo o que o teste cria é apagado no fim.
 *
 * Uso: MCP_CONFIG_ARQUIVO=/caminho/config-teste.php php scripts/testar_voluntarios_integracao.php
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$configOriginal = (string) getenv('MCP_CONFIG_ARQUIVO');
if ($configOriginal === '' || !is_file($configOriginal)) {
    fwrite(STDERR, "Falta MCP_CONFIG_ARQUIVO com o banco local (veja o cabeçalho deste arquivo).\n");
    exit(2);
}
$portaSite = (int) (getenv('MCP_TESTE_PORTA') ?: 18900 + random_int(0, 499));
$portaAviso = $portaSite + 1000;
$config = tempnam(sys_get_temp_dir(), 'mcp-vol-');
file_put_contents($config, '<?php return [\'SITE_URL\' => \'http://127.0.0.1:' . $portaSite . '\', \'EMAIL_CONTATO\' => \'contato@exemplo.org\', '
    . '\'EMAIL_VOLUNTARIADO\' => \'voluntariado@exemplo.org\', \'PAINEL_EMAILS\' => \'vol@exemplo.org\', \'ESCOLA_API_URL\' => \'\', \'REDACAO_TOKEN\' => \'' . str_repeat('r', 40) . '\', \'REDACAO_AVISO_URL\' => \'http://127.0.0.1:' . $portaAviso . '/aviso\'] + (require '
    . var_export($configOriginal, true) . ');');
$semExtra = tempnam(sys_get_temp_dir(), 'mcp-vol-extra-');
file_put_contents($semExtra, '<?php return [];');
putenv("MCP_CONFIG_ARQUIVO=$config");
$ambiente = ['MCP_CONFIG_ARQUIVO' => $config, 'PATH' => (string) getenv('PATH')];
foreach (['MCP_CONFIG_ESCOLA_ARQUIVO', 'MCP_CONFIG_WHATSAPP_ARQUIVO', 'MCP_CONFIG_META_ARQUIVO', 'MCP_CONFIG_PAGAR_TUDO_ARQUIVO'] as $variavel) {
    putenv("$variavel=$semExtra");
    $ambiente[$variavel] = $semExtra;
}
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

$db = mcp_db();
$sufixo = bin2hex(random_bytes(3));
$marca = "Teste Voluntário $sufixo";

/** Apaga o que este teste (ou uma execução interrompida) criou: nomes com "Teste Voluntário". */
function limpar(PDO $db): void
{
    $ids = $db->query("SELECT id FROM mcp_voluntarios WHERE nome LIKE '%Teste Voluntário %'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $lista = implode(', ', array_map('intval', $ids));
        $db->exec("DELETE FROM mcp_voluntarios_notas WHERE ficha_id IN ($lista)");
        $db->exec("DELETE FROM mcp_voluntarios_selfies WHERE ficha_id IN ($lista)");
        $db->exec("DELETE FROM mcp_voluntarios_aprovacoes WHERE ficha_id IN ($lista)");
        $db->exec("DELETE FROM mcp_drive_fila WHERE tipo = 'ficha_voluntario' AND referencia IN ($lista)");
        $db->exec("DELETE FROM mcp_voluntarios WHERE id IN ($lista)");
    }
    $colaboradores = $db->query("SELECT id FROM mcp_colaboradores WHERE nome LIKE '%Teste Voluntário %'")->fetchAll(PDO::FETCH_COLUMN);
    if ($colaboradores) {
        $lista = implode(', ', array_map('intval', $colaboradores));
        $db->exec("DELETE FROM mcp_ponto WHERE colaborador_id IN ($lista)");
        $db->exec("DELETE FROM mcp_colaboradores WHERE id IN ($lista)");
    }
    $db->exec("DELETE FROM mcp_eventos WHERE tipo IN ('voluntario', 'voluntario_status', 'voluntario_repetida', 'voluntarios_expurgo', 'voluntario_aprovacao', 'redacao_negado', 'redacao_voluntario')
        OR (tipo IN ('limite', 'armadilha') AND detalhe LIKE 'voluntarios%')");
    $db->exec("DELETE FROM mcp_chaves WHERE nome = 'voluntarios_expurgo_em'");
}
limpar($db);

// ----------------------------------------------------------------------------- servidor
$logErros = tempnam(sys_get_temp_dir(), 'mcp-vol-php-');
$nulo = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
$servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaSite", '-t', $raiz . '/site', '-d', 'sendmail_path=/bin/true',
    '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$logErros"], $nulo, $tubos, null, $ambiente);
$pidServidor = (int) (proc_get_status($servidor)['pid'] ?? 0);
$base = "http://127.0.0.1:$portaSite/matricula-cursos-presenciais/api/";
// No lugar da Redação: um receptor que anota cada aviso de ficha nova (método e chave) e responde 202.
$pastaAviso = sys_get_temp_dir() . '/mcp-vol-aviso-' . bin2hex(random_bytes(4));
mkdir($pastaAviso);
$avisos = "$pastaAviso/avisos.txt";
file_put_contents("$pastaAviso/index.php", '<?php file_put_contents(' . var_export($avisos, true) . ', $_SERVER["REQUEST_METHOD"] . " " . ($_SERVER["HTTP_AUTHORIZATION"] ?? "") . "\\n", FILE_APPEND); http_response_code(202);');
$receptor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaAviso", "$pastaAviso/index.php"], $nulo, $tubosAviso);
$pidReceptor = (int) (proc_get_status($receptor)['pid'] ?? 0);
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $portaSite) === false; $i++) {
    usleep(100000);
}

/** [status, cabeçalhos (minúsculos), corpo]. Não segue redirecionamentos: o 303 é o que se confere. */
function http(string $url, string $metodo = 'GET', string|array|null $corpo = null, array $cabecalhos = []): array
{
    $recebidos = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $cabecalhos, CURLOPT_TIMEOUT => 30,
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

/** POST JSON em api/voluntarios.php: [status, dados, cabeçalhos]. */
function enviar(string $base, array $corpo): array
{
    [$st, $h, $r] = http($base . 'voluntarios.php', 'POST', json_encode($corpo, JSON_UNESCAPED_UNICODE), ['Content-Type: application/json']);
    return [$st, json_decode($r, true) ?? [], $h];
}

/** Uma selfie de teste (JPG 300x300), a mesma para todas as fichas. */
function selfie_de_teste(): string
{
    static $jpeg = null;
    if ($jpeg === null) {
        $i = imagecreatetruecolor(300, 300);
        ob_start();
        imagejpeg($i, null, 80);
        imagedestroy($i);
        $jpeg = (string) ob_get_clean();
    }
    return $jpeg;
}

/**
 * POST de formulário (sem JavaScript) em api/voluntarios.php: [status, cabeçalhos, corpo]. É multipart, como a página
 * manda, com a selfie (obrigatória desde 27/10/2026) no arquivo "selfie"; $semSelfie manda sem ela.
 */
function formulario(string $base, array $campos, bool $semSelfie = false): array
{
    $planos = [];
    foreach ($campos as $k => $v) {
        if ($k === 'selfie') {
            continue;
        }
        if (is_array($v)) {
            foreach (array_values($v) as $i => $item) {
                $planos["{$k}[$i]"] = (string) $item;
            }
        } else {
            $planos[$k] = is_bool($v) ? ($v ? 'on' : '') : (string) $v;
        }
    }
    if (!$semSelfie) {
        $arquivo = sys_get_temp_dir() . '/mcp-selfie-teste.jpg';
        file_put_contents($arquivo, selfie_de_teste());
        $planos['selfie'] = new CURLFile($arquivo, 'image/jpeg', 'eu.jpg');
    }
    return http($base . 'voluntarios.php', 'POST', $planos, []);
}

$ficha = static fn(string $email, array $muda = []): array => $muda + [
    'nome' => "Ana $marca", 'data_nascimento' => '1992-03-10', 'email' => $email, 'telefone' => '(21) 99876-5432', 'cpf' => '',
    'cidade' => 'Rio de Janeiro', 'bairro' => 'Tijuca', 'estado' => 'RJ', 'escolaridade' => 'superior', 'profissao' => 'Professora',
    'saude' => 'nao', 'experiencia' => 'Fui voluntária na escola do bairro.', 'habilidades' => 'Primeiros socorros básicos',
    'frentes' => ['juventude', 'campanhas_acoes'], 'dias' => ['sab', 'dom'], 'periodos' => ['manha'],
    'motivacao' => 'Quero contribuir com a comunidade e aprender com a equipe.', 'como_conheceu' => 'site',
    'aceita_contato' => true, 'consentimento' => true, 'veracidade' => true, 'site' => '', 'pagina' => '/voluntariado/cadastro/',
    'origem' => ['utm_source' => 'teste', 'utm_campaign' => 'integracao'],
    'selfie' => 'data:image/jpeg;base64,' . base64_encode(selfie_de_teste()),
];
$politicas = json_decode((string) file_get_contents($raiz . '/site/politicas.json'), true);
$politica = (string) ($politicas['paginas']['privacidade']['revisao'] ?? $politicas['revisao'] ?? '');
$cpfAna = cpf_de_teste();
$cpfBia = cpf_de_teste();

try {
    // ------------------------------------------------------------------------- JSON: ficha válida
    $emailAna = "ana-$sufixo@exemplo.org";
    [$st, $r] = enviar($base, $ficha($emailAna, ['cpf' => mcp_cpf_formatado($cpfAna)]));
    $respostaAna = $r;
    verificar('ficha válida: 201 com as chaves do contrato', [$st, array_keys($r)], [201, ['ok', 'protocolo', 'nome', 'email', 'prazo', 'aviso', 'menor', 'selfie']]);
    verificar('ficha válida: protocolo VOL-aammdd-NNNN, primeiro nome, e-mail, prazo, maior de idade',
        [(bool) preg_match('/^VOL-\d{6}-\d{4,}$/', (string) ($r['protocolo'] ?? '')), $r['nome'] ?? null, $r['email'] ?? null, $r['prazo'] ?? null, $r['menor'] ?? null],
        [true, 'Ana', $emailAna, MCP_VOL_PROXIMAS_ENTRADAS, false]);
    $ana = $db->query("SELECT * FROM mcp_voluntarios WHERE email = " . $db->quote($emailAna))->fetch() ?: [];
    $anaId = (int) ($ana['id'] ?? 0);
    verificar('ficha válida: linha gravada com protocolo, consentimento, versão da política e veracidade', [
        $ana['protocolo'] ?? null, isset($ana['consentimento_em']) && $ana['consentimento_em'] !== null, $ana['politica_versao'] ?? null,
        isset($ana['veracidade_em']) && $ana['veracidade_em'] !== null, $ana['status'] ?? null, $ana['cpf'] ?? null, $ana['frentes'] ?? null, $ana['dias'] ?? null,
        $ana['estado'] ?? null, (int) ($ana['aceita_contato'] ?? -1), $ana['ip'] ?? null, $ana['utm_campaign'] ?? null,
    ], [$r['protocolo'] ?? null, true, $politica, true, 'nova', $cpfAna, 'campanhas_acoes,juventude', 'sab,dom', 'RJ', 1, '127.0.0.1', 'integracao']);
    verificar('ficha válida: protocolo bate com o id e a data', $ana['protocolo'] ?? null, mcp_voluntario_protocolo($anaId, (string) ($ana['criado_em'] ?? mcp_agora())));
    verificar('nenhum e-mail sai (sem Resend), mas o resultado fica registrado', [in_array($ana['email_equipe'] ?? null, ['mail', 'falhou'], true), in_array($ana['email_confirmacao'] ?? null, ['mail', 'falhou'], true)], [true, true]);
    $evento = (string) ($db->query("SELECT detalhe FROM mcp_eventos WHERE tipo = 'voluntario' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: '');
    for ($i = 0; $i < 30 && !is_file($avisos); $i++) {
        usleep(100000);
    }
    verificar('ficha nova avisa a Redação depois da resposta: POST com a chave da ligação',
        explode("\n", (string) @file_get_contents($avisos))[0], 'POST Bearer ' . str_repeat('r', 40));
    verificar('registro em mcp_eventos: id e protocolo, sem CPF, e-mail ou nome', [str_contains($evento, "#$anaId · " . $ana['protocolo']), str_contains($evento, $cpfAna), str_contains($evento, $emailAna), str_contains($evento, 'Ana')], [true, false, false, false]);
    if (function_exists('mcp_drive_enfileirar')) {
        verificar('gancho do Drive: com o módulo publicado e o Drive desligado, nada entra na fila', (int) $db->query("SELECT COUNT(*) FROM mcp_drive_fila WHERE tipo = 'ficha_voluntario' AND referencia = $anaId")->fetchColumn(), function_exists('mcp_drive_ligado') && mcp_drive_ligado() ? 1 : 0);
    } else {
        verificar('gancho do Drive: sem o módulo, a ficha segue sem fila', true, true);
    }

    // ------------------------------------------------------------------------- JSON: repetida, menor de 18 (recusa), armadilha, adulta
    [$st, $r] = enviar($base, $ficha($emailAna));
    verificar('ficha repetida (mesmo e-mail, em aberto): 409 com a data e o protocolo da anterior',
        [$st, array_keys($r), str_contains((string) ($r['erro'] ?? ''), 'Já recebemos a sua ficha em ' . mcp_data_brt((string) $ana['criado_em'], 'd/m')), str_contains((string) ($r['erro'] ?? ''), (string) $ana['protocolo'])],
        [409, ['ok', 'erro'], true, true]);
    $hoje = new DateTimeImmutable(mcp_voluntario_hoje(), mcp_voluntario_fuso());
    [$st, $r] = enviar($base, $ficha("crianca-$sufixo@exemplo.org", ['data_nascimento' => $hoje->modify('-18 years +1 day')->format('Y-m-d')]));
    verificar('menor de 18 (faz 18 amanhã): 422 no campo data_nascimento', [$st, $r['campo'] ?? null, str_contains((string) ($r['erro'] ?? ''), '18 anos')], [422, 'data_nascimento', true]);
    [$st, $r] = enviar($base, $ficha("robo-$sufixo@exemplo.org", ['site' => 'http://spam']));
    verificar('armadilha preenchida: 422 genérico, sem campo', [$st, campo($r, 'campo'), $r['erro'] ?? null], [422, 'ausente', 'Não foi possível enviar. Tente novamente.']);
    verificar('nem a menor de 18 nem o robô geraram linha', (int) $db->query("SELECT COUNT(*) FROM mcp_voluntarios WHERE nome LIKE '%$marca%'")->fetchColumn(), 1);
    $emailBia = "bia-$sufixo@exemplo.org";
    [$st, $r] = enviar($base, $ficha($emailBia, ['nome' => "Bia $marca", 'data_nascimento' => $hoje->modify('-25 years')->format('Y-m-d'), 'escolaridade' => 'medio', 'profissao' => '']));
    verificar('adulta: 201 com menor = false', [$st, $r['menor'] ?? null], [201, false]);
    $biaId = (int) $db->query("SELECT id FROM mcp_voluntarios WHERE email = " . $db->quote($emailBia))->fetchColumn();
    [$st, $r] = enviar($base, $ficha("x-$sufixo@exemplo.org", ['frentes' => ['juventude', 'cozinha']]));
    verificar('frente desconhecida: 422 no campo frentes', [$st, $r['campo'] ?? null], [422, 'frentes']);
    [$st, $r] = enviar($base, $ficha("x-$sufixo@exemplo.org", ['consentimento' => false]));
    verificar('sem consentimento: 422 no campo consentimento', [$st, $r['campo'] ?? null], [422, 'consentimento']);
    [$st, $r] = http($base . 'voluntarios.php', 'GET');
    verificar('GET não é permitido (405)', $st, 405);

    // ------------------------------------------------------------------------- formulário (sem JavaScript)
    $emailCarla = "carla-$sufixo@exemplo.org";
    $campos = $ficha($emailCarla, ['nome' => "Carla $marca", 'aceita_contato' => 'on', 'consentimento' => 'on', 'veracidade' => 'on', 'data_nascimento' => '10/03/1985']);
    unset($campos['origem']);
    [$st, $cab, $corpo] = formulario($base, $campos);
    $carla = $db->query("SELECT * FROM mcp_voluntarios WHERE email = " . $db->quote($emailCarla))->fetch() ?: [];
    verificar('formulário: 303 para a página do cadastro com o protocolo', [$st, $cab['location'] ?? null, $corpo, $carla['data_nascimento'] ?? null, (int) ($carla['aceita_contato'] ?? -1)],
        [303, '/voluntariado/cadastro/?protocolo=' . ($carla['protocolo'] ?? ''), '', '1985-03-10', 1]);
    verificar('formulário: Location contém ?protocolo=VOL-', str_contains($cab['location'] ?? '', '?protocolo=VOL-'), true);
    [$st, $cab] = formulario($base, array_diff_key($campos, ['frentes' => 1]));
    verificar('formulário sem frentes: 303 com ?erro=frentes', [$st, $cab['location'] ?? null], [303, '/voluntariado/cadastro/?erro=frentes']);
    [$st, $cab] = formulario($base, $campos);
    verificar('formulário repetido: 303 com ?erro=duplicada', [$st, $cab['location'] ?? null], [303, '/voluntariado/cadastro/?erro=duplicada']);
    [$st, $cab] = formulario($base, ['site' => 'spam'] + $campos);
    verificar('formulário com a armadilha: 303 com ?erro=envio', [$st, $cab['location'] ?? null], [303, '/voluntariado/cadastro/?erro=envio']);
    [$st, $cab] = http($base . 'voluntarios.php', 'POST', http_build_query($campos), ['Content-Type: application/x-www-form-urlencoded', 'Origin: https://outro.site']);
    verificar('formulário de outro site: 303 com ?erro=envio', [$st, $cab['location'] ?? null], [303, '/voluntariado/cadastro/?erro=envio']);
    [$st] = http($base . 'voluntarios.php', 'POST', json_encode($ficha("y-$sufixo@exemplo.org")), ['Content-Type: application/json', 'Origin: https://outro.site']);
    verificar('JSON de outro site: 403', $st, 403);

    // ------------------------------------------------------------------------- selfie opcional
    $foto = (static function (int $w, int $h, string $tipo = 'jpeg'): string {
        $i = imagecreatetruecolor($w, $h);
        imagefill($i, 0, 0, (int) imagecolorallocate($i, 20, 120, 200));
        ob_start();
        $tipo === 'png' ? imagepng($i) : imagejpeg($i, null, 85);
        imagedestroy($i);
        return (string) ob_get_clean();
    });
    verificar('ficha válida: selfie = true e guardada', [$respostaAna['selfie'] ?? null, mcp_voluntario_selfie($anaId)['largura'] ?? 0], [true, 300]);
    $emailSem = "sem-selfie-$sufixo@exemplo.org";
    $semSelfie = $ficha($emailSem, ['nome' => "Sem $marca"]);
    unset($semSelfie['selfie']);
    [$st, $r] = enviar($base, $semSelfie);
    verificar('JSON sem selfie (obrigatória): 422 no campo selfie, nada gravado', [$st, $r['campo'] ?? null, (int) $db->query("SELECT COUNT(*) FROM mcp_voluntarios WHERE email = " . $db->quote($emailSem))->fetchColumn()], [422, 'selfie', 0]);
    [$st, $cab] = formulario($base, ['aceita_contato' => 'on', 'consentimento' => 'on', 'veracidade' => 'on'] + $semSelfie, true);
    verificar('formulário sem selfie: 303 com ?erro=selfie', [$st, $cab['location'] ?? null], [303, '/voluntariado/cadastro/?erro=selfie']);
    $emailDani = "dani-$sufixo@exemplo.org";
    [$st, $r] = enviar($base, $ficha($emailDani, ['nome' => "Dani $marca", 'selfie' => 'data:image/jpeg;base64,' . base64_encode($foto(1600, 1200))]));
    $daniId = (int) $db->query("SELECT id FROM mcp_voluntarios WHERE email = " . $db->quote($emailDani))->fetchColumn();
    $selfieDani = mcp_voluntario_selfie($daniId);
    verificar('JSON com selfie: 201, selfie = true, guardada com 800 px e em JPG', [$st, $r['selfie'] ?? null, $selfieDani['largura'] ?? 0, $selfieDani['altura'] ?? 0, str_starts_with((string) ($selfieDani['jpeg'] ?? ''), "\xFF\xD8\xFF")], [201, true, 800, 600, true]);
    verificar('JSON com selfie: registrado em mcp_eventos', str_contains((string) $db->query("SELECT detalhe FROM mcp_eventos WHERE tipo = 'voluntario' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'selfie recebida'), true);
    $emailEdu = "edu-$sufixo@exemplo.org";
    [$st, $r] = enviar($base, $ficha($emailEdu, ['nome' => "Edu $marca", 'selfie' => 'data:image/gif;base64,R0lGODlhAQABAAAAACw=']));
    verificar('JSON com selfie que não é JPG/PNG: 422 no campo selfie, nada gravado', [$st, $r['campo'] ?? null, (int) $db->query("SELECT COUNT(*) FROM mcp_voluntarios WHERE email = " . $db->quote($emailEdu))->fetchColumn()], [422, 'selfie', 0]);
    [$st] = http($base . 'voluntarios.php', 'POST', json_encode($ficha("grande-$sufixo@exemplo.org", ['selfie' => 'data:image/jpeg;base64,' . str_repeat('A', 800000)])), ['Content-Type: application/json']);
    verificar('JSON maior que 768 KB: 413', $st, 413);
    $multipart = static function (array $campos): array {
        $planos = [];
        foreach ($campos as $k => $v) {
            if (is_array($v)) {
                foreach (array_values($v) as $i => $item) {
                    $planos["{$k}[$i]"] = $item;
                }
            } else {
                $planos[$k] = $v;
            }
        }
        return $planos;
    };
    $arquivo = tempnam(sys_get_temp_dir(), 'mcp-selfie-');
    file_put_contents($arquivo, $foto(500, 900, 'png'));
    $emailFe = "fe-$sufixo@exemplo.org";
    $camposFe = $ficha($emailFe, ['nome' => "Fe $marca", 'aceita_contato' => 'on', 'consentimento' => 'on', 'veracidade' => 'on']);
    unset($camposFe['origem'], $camposFe['selfie']);
    $ch = curl_init($base . 'voluntarios.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => $multipart($camposFe) + ['selfie' => new CURLFile($arquivo, 'image/png', 'eu.png')]]);
    $resposta = (string) curl_exec($ch);
    $stFe = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $fe = $db->query("SELECT id, protocolo FROM mcp_voluntarios WHERE email = " . $db->quote($emailFe))->fetch() ?: [];
    $selfieFe = mcp_voluntario_selfie((int) ($fe['id'] ?? 0));
    verificar('formulário com selfie (multipart): 303 com o protocolo e a selfie guardada em pé, com 800 px', [$stFe, str_contains($resposta, '?protocolo=' . ($fe['protocolo'] ?? 'x')), $selfieFe['largura'] ?? 0, $selfieFe['altura'] ?? 0], [303, true, 444, 800]);
    file_put_contents($arquivo, 'isto não é uma foto');
    $ch = curl_init($base . 'voluntarios.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => $multipart($ficha("gui-$sufixo@exemplo.org", ['nome' => "Gui $marca", 'consentimento' => 'on', 'veracidade' => 'on', 'origem' => ''])) + ['selfie' => new CURLFile($arquivo, 'image/jpeg', 'eu.jpg')]]);
    $resposta = (string) curl_exec($ch);
    curl_close($ch);
    unlink($arquivo);
    verificar('formulário com arquivo que não é foto: 303 com ?erro=selfie', str_contains($resposta, '/voluntariado/cadastro/?erro=selfie'), true);
    verificar('apagar a selfie (pedido da pessoa): some só a foto', [mcp_voluntario_selfie_apagar([$daniId]), mcp_voluntario_selfie($daniId), mcp_voluntario_por_id($daniId) !== null], [1, null, true]);
    // As fichas deste bloco saem daqui: o resto do teste conta as fichas e os freios por IP.
    $deste = array_map('intval', $db->query("SELECT id FROM mcp_voluntarios WHERE nome IN (" . $db->quote("Dani $marca") . ', ' . $db->quote("Fe $marca") . ', ' . $db->quote("Gui $marca") . ')')->fetchAll(PDO::FETCH_COLUMN));
    if ($deste) {
        mcp_voluntario_selfie_apagar($deste);
        $db->exec('DELETE FROM mcp_drive_fila WHERE tipo = \'ficha_voluntario\' AND referencia IN (' . implode(', ', $deste) . ')');
        $db->exec('DELETE FROM mcp_voluntarios WHERE id IN (' . implode(', ', $deste) . ')');
    }
    $db->exec("DELETE FROM mcp_eventos WHERE tipo = 'limite' AND detalhe LIKE 'voluntarios%'");

    // ------------------------------------------------------------------------- acompanhamento: situação e notas
    $quem = 'vol@exemplo.org';
    verificar('mudar situação: nova → em_analise com nota', mcp_voluntario_mudar_status($anaId, 'em_analise', $quem, 'Ligar na terça.'), null);
    $ana = mcp_voluntario_por_id($anaId);
    $notas = mcp_voluntario_notas($anaId);
    verificar('situação gravada com quem e quando; a nota ficou', [$ana['status'], $ana['status_por'], $ana['status_em'] !== null, count($notas), $notas[0]['texto'] ?? null, $notas[0]['quem'] ?? null], ['em_analise', $quem, true, 1, 'Ligar na terça.', $quem]);
    verificar('mudar situação: desconhecida e ficha inexistente', [mcp_voluntario_mudar_status($anaId, 'zzz', $quem), mcp_voluntario_mudar_status(99999999, 'nova', $quem)], ['Situação desconhecida.', 'Ficha não encontrada.']);
    verificar('mudar situação para aceita sem colaborador pede a ação Aceitar', str_contains((string) mcp_voluntario_mudar_status($anaId, 'aceita', $quem), 'CPF'), true);
    mcp_voluntario_nota_registrar($anaId, $quem, "  Segunda nota \n\n\n\n com espaço  ");
    verificar('nota registrada à parte, normalizada', array_column(mcp_voluntario_notas($anaId), 'texto'), ['Ligar na terça.', "Segunda nota\n\ncom espaço"]);
    $eventos = (int) $db->query("SELECT COUNT(*) FROM mcp_eventos WHERE tipo = 'voluntario_status' AND detalhe LIKE '#$anaId · nova → em_analise · $quem'")->fetchColumn();
    verificar('evento voluntario_status registrado', $eventos, 1);
    verificar('mudar situação: em_analise → arquivada', mcp_voluntario_mudar_status($anaId, 'arquivada', $quem), null);

    // ------------------------------------------------------------------------- freios: por e-mail (2 no dia) e por IP (5 na hora)
    [$st] = enviar($base, $ficha($emailAna));
    verificar('a ficha arquivada não conta como repetida: o mesmo e-mail entra de novo (201)', $st, 201);
    [$st, $r] = enviar($base, $ficha($emailAna));
    verificar('3ª ficha do mesmo e-mail no dia: 429', [$st, array_keys($r), str_contains((string) ($r['erro'] ?? ''), 'contato@exemplo.org')], [429, ['ok', 'erro'], true]);
    // Até aqui 4 fichas deste IP (Ana, Bia, Carla, Ana de novo); a 5ª passa e a 6ª para.
    [$st] = enviar($base, $ficha("dani-$sufixo@exemplo.org", ['nome' => "Dani $marca"]));
    verificar('5ª ficha do IP na hora: ainda passa (201)', $st, 201);
    [$st, $r] = enviar($base, $ficha("edu-$sufixo@exemplo.org", ['nome' => "Edu $marca"]));
    verificar('6ª ficha do IP na hora: 429', [$st, $r['ok'] ?? null, str_contains((string) ($r['erro'] ?? ''), 'várias fichas')], [429, false, true]);
    [$st, $cab] = formulario($base, $ficha("edu-$sufixo@exemplo.org", ['nome' => "Edu $marca", 'consentimento' => 'on', 'veracidade' => 'on']));
    verificar('formulário no freio do IP: 303 com ?erro=limite', [$st, $cab['location'] ?? null], [303, '/voluntariado/cadastro/?erro=limite']);
    verificar('freios registrados em mcp_eventos', (int) $db->query("SELECT COUNT(*) FROM mcp_eventos WHERE tipo = 'limite' AND detalhe LIKE 'voluntarios · %'")->fetchColumn(), 3);

    // ------------------------------------------------------------------------- aceitação: cria o colaborador do ponto
    verificar('código de aprovação antes do aceite: recusado', mcp_voluntario_aprovacao_emitir($biaId, $quem), ['ok' => false, 'erro' => 'O código de aprovação só é gerado para ficha aceita.']);
    $semCpf = mcp_voluntario_aceitar($biaId, $quem, '');
    verificar('aceitar sem CPF (a ficha também não tem): erro pedindo o CPF', [$semCpf['ok'], str_contains((string) ($semCpf['erro'] ?? ''), 'CPF'), campo($semCpf, 'colaborador_id')], [false, true, 'ausente']);
    verificar('aceitar com CPF inválido: erro', mcp_voluntario_aceitar($biaId, $quem, '123.456.789-00')['ok'], false);
    verificar('aceitar ficha inexistente: erro', mcp_voluntario_aceitar(99999999, $quem, $cpfBia), ['ok' => false, 'erro' => 'Ficha não encontrada.']);
    $aceite = mcp_voluntario_aceitar($biaId, $quem, mcp_cpf_formatado($cpfBia));
    $colaborador = !empty($aceite['colaborador_id']) ? mcp_colaborador_por_id((int) $aceite['colaborador_id']) : null;
    $bia = mcp_voluntario_por_id($biaId);
    verificar('aceitar com CPF: colaborador criado com vínculo voluntário, função, ativo e os contatos da ficha',
        [$aceite['ok'], $colaborador['vinculo'] ?? null, $colaborador['funcao'] ?? null, (int) ($colaborador['ativo'] ?? 0), $colaborador['nome'] ?? null, $colaborador['cpf'] ?? null, $colaborador['email'] ?? null, $colaborador['telefone'] ?? null, $colaborador['criado_por'] ?? null],
        [true, 'voluntario', 'Voluntário(a)', 1, "Bia $marca", $cpfBia, $emailBia, '21998765432', $quem]);
    verificar('aceitar: a ficha fica aceita, com o colaborador e o CPF informado', [$bia['status'], (int) $bia['colaborador_id'], $bia['cpf'], $bia['status_por']], ['aceita', (int) $aceite['colaborador_id'], $cpfBia, $quem]);
    verificar('termo de adesão pendente para o novo voluntário (a secretaria registra a assinatura no portal)', mcp_ponto_termo_pendente($colaborador), true);

    // ------------------------------------------------------------------------- código de aprovação (27/10/2026)
    $cod = mcp_voluntario_aprovacao_emitir($biaId, $quem);
    verificar('código de aprovação: gerado na ficha aceita, 8 caracteres do alfabeto das declarações', [$cod['ok'], $cod['novo'], mcp_codigo_normalizar($cod['codigo'] ?? '') === ($cod['codigo'] ?? 'x')], [true, true, true]);
    verificar('código de aprovação: pedir de novo devolve o mesmo (não troca o que a pessoa recebeu)', mcp_voluntario_aprovacao_emitir($biaId, $quem), ['ok' => true, 'codigo' => $cod['codigo'], 'novo' => false]);
    $conf = mcp_conferir((string) $cod['codigo']);
    verificar('conferir: aprovação válida, nome, CPF mascarado e a data', [$conf['tipo'] ?? null, $conf['valido'] ?? null, $conf['nome'] ?? null, $conf['cpf'] ?? null, $conf['linhas'][1][1] ?? null],
        ['Aprovação no voluntariado', true, mcp_nome_proprio("Bia $marca"), mcp_cpf_mascarado($cpfBia), mcp_data_brt(mcp_agora(), 'd/m/Y')]);
    [$st, , $corpoConf] = http($base . 'conferir.php?c=' . strtolower(mcp_codigo_formatado((string) $cod['codigo'])));
    $jsonConf = json_decode($corpoConf, true) ?: [];
    verificar('conferir.php pela URL (minúsculas e hífen): 200 com a aprovação, sem o CPF inteiro', [$st, $jsonConf['tipo'] ?? null, $jsonConf['situacao'] ?? null, str_contains($corpoConf, $cpfBia)], [200, 'Aprovação no voluntariado', 'Aprovação válida', false]);
    $msg = mcp_voluntario_aprovacao_mensagem(mcp_voluntario_por_id($biaId), (string) $cod['codigo']);
    verificar('mensagem do WhatsApp: código formatado e o link de conferência', [str_contains($msg, mcp_codigo_formatado((string) $cod['codigo'])), str_contains($msg, mcp_conferir_url((string) $cod['codigo']))], [true, true]);
    verificar('cancelar o código: some da validade; cancelar de novo não faz nada', [mcp_voluntario_aprovacao_cancelar($biaId, $quem), mcp_voluntario_aprovacao_cancelar($biaId, $quem), mcp_conferir((string) $cod['codigo'])['valido'] ?? null, str_starts_with((string) (mcp_conferir((string) $cod['codigo'])['situacao'] ?? ''), 'Aprovação cancelada')], [true, false, false, true]);
    $cod2 = mcp_voluntario_aprovacao_emitir($biaId, $quem);
    verificar('gerar de novo depois de cancelar: código novo e válido; o antigo deixa de existir', [$cod2['novo'], $cod2['codigo'] !== $cod['codigo'], mcp_conferir((string) $cod2['codigo'])['valido'] ?? null, mcp_conferir((string) $cod['codigo'])], [true, true, true, null]);
    // A mesma pessoa (mesmo CPF) numa segunda ficha, com o colaborador desativado: reaproveita e reativa, sem outro cadastro.
    $colId = (int) $aceite['colaborador_id'];
    mcp_colaborador_salvar($colId, ['nome' => $colaborador['nome'], 'cpf' => $cpfBia, 'email' => $colaborador['email'], 'telefone' => $colaborador['telefone'], 'funcao' => 'Socorrista', 'vinculo' => 'voluntario', 'ativo' => 0], $quem);
    $carlaId = (int) $carla['id'];
    $reaproveite = mcp_voluntario_aceitar($carlaId, $quem, $cpfBia);
    $reativado = mcp_colaborador_por_id($colId);
    verificar('aceitar com CPF de colaborador inativo: reaproveita o cadastro e reativa, mantendo a função dele',
        [$reaproveite, (int) $reativado['ativo'], $reativado['funcao'], $reativado['desligado_em'], (int) $db->query("SELECT COUNT(*) FROM mcp_colaboradores WHERE cpf = " . $db->quote($cpfBia))->fetchColumn()],
        [['ok' => true, 'colaborador_id' => $colId], 1, 'Socorrista', null, 1]);
    // Ficha que já trouxe o CPF: aceitar sem informar usa o da ficha.
    $anaAceite = mcp_voluntario_aceitar($anaId, $quem);
    verificar('aceitar ficha que já tinha CPF, sem informar outro: cria o colaborador com o CPF da ficha', [$anaAceite['ok'], mcp_colaborador_por_id((int) $anaAceite['colaborador_id'])['cpf'] ?? null], [true, $cpfAna]);
    verificar('aceitar de novo uma ficha aceita: reaproveita o colaborador', mcp_voluntario_aceitar($anaId, $quem), $anaAceite);
    verificar('mudar situação de uma ficha aceita (com colaborador) para aceita: aceito', mcp_voluntario_mudar_status($anaId, 'aceita', $quem), null);

    // ------------------------------------------------------------------------- lista, contagens, CSV, PDF
    $lista = mcp_voluntarios_listar(['busca' => $marca]);
    verificar('listar: as 5 fichas do teste, mais recentes primeiro, sem a coluna cpf', [$lista['total'], count($lista['linhas']), array_key_exists('cpf', $lista['linhas'][0]), (int) $lista['linhas'][0]['id'] > (int) $lista['linhas'][4]['id']], [5, 5, false, true]);
    verificar('listar: filtro por situação (lista) e por protocolo', [mcp_voluntarios_listar(['status' => ['aceita'], 'busca' => $marca])['total'], mcp_voluntarios_listar(['busca' => (string) $bia['protocolo']])['total'], mcp_voluntarios_listar(['status' => 'recusada', 'busca' => $marca])['total']], [3, 1, 0]);
    verificar('listar: filtro por frente e paginação', [mcp_voluntarios_listar(['frente' => 'juventude', 'busca' => $marca])['total'], count(mcp_voluntarios_listar(['busca' => $marca], 2, 2)['linhas']), count(mcp_voluntarios_listar(['busca' => $marca], 3, 2)['linhas'])], [5, 2, 1]);
    verificar('contagens: por situação e novas', [mcp_voluntarios_contar('aceita') >= 3, mcp_voluntarios_contar('zzz'), mcp_voluntarios_novas_contar() >= 2, mcp_voluntarios_contar() >= 5], [true, 0, true, true]);
    $csv = mcp_voluntarios_csv(['busca' => $marca]);
    verificar('csv do banco: as fichas, sem CPF e sem IP', [substr_count($csv, "\n"), str_contains($csv, $cpfAna), str_contains($csv, $cpfBia), str_contains($csv, '127.0.0.1'), str_contains($csv, "Bia $marca"), str_contains($csv, 'Aceita')], [6, false, false, false, true, true]);
    $pdf = mcp_voluntario_pdf(mcp_voluntario_por_id($biaId));
    verificar('pdf da ficha do banco: começa com %PDF', [str_starts_with($pdf, '%PDF-1.4'), strlen($pdf) > 5000], [true, true]);
    $equipe = mcp_montar_email_voluntario_equipe(mcp_voluntario_por_id($biaId));
    verificar('e-mail à equipe da ficha do banco: botão do portal com o id', str_contains($equipe['html'], 'painel.php?v=voluntario&amp;id=' . $biaId), true);

    // ------------------------------------------------------------------------- ligação com a Redação (27/10/2026)
    $tokenRedacao = str_repeat('r', 40);
    $redacao = static function (string $metodo, string $q = '', ?array $corpo = null, ?string $token = null) use ($base, $tokenRedacao): array {
        $cab = ['Authorization: Bearer ' . ($token ?? $tokenRedacao)];
        if ($corpo !== null) {
            $cab[] = 'Content-Type: application/json';
        }
        [$st, , $r] = http($base . 'redacao-voluntarios.php' . $q, $metodo, $corpo !== null ? json_encode($corpo) : null, $cab);
        return [$st, json_decode($r, true) ?? []];
    };
    [$st] = http($base . 'redacao-voluntarios.php');
    [$stErrado] = $redacao('GET', '', null, str_repeat('x', 40));
    verificar('Redação: sem token e com token errado, 401', [$st, $stErrado], [401, 401]);
    [$st, $r] = $redacao('GET', '?desde=0&limite=200');
    $doTeste = array_values(array_filter($r['fichas'] ?? [], static fn(array $f): bool => str_contains((string) $f['nome'], $marca)));
    $anaR = current(array_filter($doTeste, static fn(array $f): bool => $f['id'] === $anaId)) ?: [];
    verificar('Redação: lista as fichas com protocolo, situação, rótulos legíveis e a selfie em base64',
        [$st, count($doTeste) >= 2, $anaR['protocolo'] ?? null, $anaR['frentes'] ?? null, str_starts_with((string) base64_decode((string) ($anaR['selfie'] ?? '')), "\xFF\xD8\xFF"), isset($anaR['cpf'])],
        [200, true, (string) mcp_voluntario_por_id($anaId)['protocolo'], ['Campanhas e ações humanitárias', 'Juventude'], true, true]);
    [, $p1] = $redacao('GET', '?desde=0&limite=1');
    [, $p2] = $redacao('GET', '?desde=' . (int) ($p1['ultimo'] ?? 0) . '&limite=1');
    verificar('Redação: paginação por id (limite 1 = uma ficha, mais = true; a seguinte vem depois)', [count($p1['fichas'] ?? []), $p1['mais'] ?? null, ($p2['fichas'][0]['id'] ?? 0) > ($p1['ultimo'] ?? 0)], [1, true, true]);
    // Fichas gravadas direto (o freio por IP já está no limite neste ponto do teste).
    $gravarFicha = static function (string $nome, string $email): array {
        $id = mcp_voluntario_gravar(['nome' => $nome, 'data_nascimento' => '1990-01-01', 'email' => $email, 'telefone' => '21999990000', 'cidade' => 'Rio de Janeiro',
            'escolaridade' => 'medio', 'frentes' => 'juventude', 'dias' => 'sab', 'periodos' => 'manha', 'motivacao' => 'Quero ajudar a minha comunidade.']);
        mcp_voluntario_atualizar($id, ['protocolo' => mcp_voluntario_protocolo($id)]);
        return mcp_voluntario_por_id($id) ?? [];
    };
    $rita = $gravarFicha("Rita $marca", "red-$sufixo@exemplo.org");
    $cpfRita = cpf_de_teste();
    [$st, $r] = $redacao('POST', '', ['acao' => 'aprovar', 'protocolo' => $rita['protocolo'] ?? '', 'quem' => 'coord@exemplo.org']);
    verificar('Redação: aprovar ficha sem CPF e sem mandar CPF: 422 pedindo o CPF', [$st, str_contains((string) ($r['erro'] ?? ''), 'CPF')], [422, true]);
    [$st, $r] = $redacao('POST', '', ['acao' => 'aprovar', 'protocolo' => $rita['protocolo'] ?? '', 'quem' => 'coord@exemplo.org', 'cpf' => mcp_cpf_formatado($cpfRita)]);
    $ritaDepois = mcp_voluntario_por_id((int) ($rita['id'] ?? 0));
    verificar('Redação: aprovar com CPF aceita a ficha, cria o colaborador e devolve o código e o link de conferência',
        [$st, $r['ok'] ?? null, $ritaDepois['status'] ?? null, !empty($ritaDepois['colaborador_id']), mcp_codigo_normalizar((string) ($r['codigo'] ?? '')) !== '', str_contains((string) ($r['conferir_url'] ?? ''), '/conferir/?c='), str_starts_with((string) ($ritaDepois['status_por'] ?? ''), 'Redação · ')],
        [200, true, 'aceita', true, true, true, true]);
    [, $r2] = $redacao('POST', '', ['acao' => 'aprovar', 'protocolo' => $rita['protocolo'] ?? '', 'quem' => 'coord@exemplo.org']);
    verificar('Redação: aprovar de novo devolve o mesmo código (sem duplicar)', $r2['codigo'] ?? null, $r['codigo'] ?? 'x');
    [$st, $r3] = $redacao('POST', '', ['acao' => 'recusar', 'protocolo' => $rita['protocolo'] ?? '', 'quem' => 'coord@exemplo.org']);
    verificar('Redação: recusar ficha já aceita é recusado (422)', [$st, (string) ($r3['erro'] ?? '') !== ''], [422, true]);
    $raul = $gravarFicha("Raul $marca", "rec-$sufixo@exemplo.org");
    [$st, $r4] = $redacao('POST', '', ['acao' => 'recusar', 'protocolo' => $raul['protocolo'] ?? '', 'quem' => 'coord@exemplo.org']);
    verificar('Redação: recusar marca a ficha recusada no site, com a nota', [$st, mcp_voluntario_por_id((int) $raul['id'])['status'] ?? null, mcp_voluntario_notas((int) $raul['id'])[0]['texto'] ?? null], [200, 'recusada', 'Recusada na Redação.']);
    [$st] = $redacao('POST', '', ['acao' => 'aprovar', 'protocolo' => 'VOL-000000-0000', 'quem' => 'x']);
    verificar('Redação: protocolo que não existe, 422', $st, 422);
    $ritaR = current(array_filter($redacao('GET', '?desde=' . ((int) $rita['id'] - 1) . '&limite=1')[1]['fichas'] ?? [], static fn(array $f): bool => true)) ?: [];
    verificar('Redação: a lista leva a situação e o código de aprovação da ficha aceita', [$ritaR['situacao'] ?? null, $ritaR['codigo_aprovacao'] ?? null], ['aceita', $r['codigo'] ?? 'x']);
    // As fichas deste bloco saem daqui (o resto do teste conta fichas e freios).
    $deste = array_map('intval', [$rita['id'] ?? 0, $raul['id'] ?? 0]);
    $db->exec('DELETE FROM mcp_voluntarios_aprovacoes WHERE ficha_id IN (' . implode(', ', $deste) . ')');
    $db->exec('DELETE FROM mcp_voluntarios_selfies WHERE ficha_id IN (' . implode(', ', $deste) . ')');
    $db->exec('DELETE FROM mcp_voluntarios_notas WHERE ficha_id IN (' . implode(', ', $deste) . ')');
    $db->exec('DELETE FROM mcp_drive_fila WHERE tipo = \'ficha_voluntario\' AND referencia IN (' . implode(', ', $deste) . ')');
    $db->exec('DELETE FROM mcp_voluntarios WHERE id IN (' . implode(', ', $deste) . ')');

    // ------------------------------------------------------------------------- expurgo de 12 meses (Política de Privacidade)
    $antiga = static function (string $nome, string $status, string $criadoEm) use ($db, $marca, $sufixo): int {
        $db->prepare("INSERT INTO mcp_voluntarios (protocolo, nome, data_nascimento, email, telefone, cidade, escolaridade, frentes, dias, periodos, motivacao, consentimento_em, politica_versao, veracidade_em, status, criado_em, atualizado_em)
            VALUES (?, ?, '1990-01-01', ?, '21999990000', 'Niterói', 'medio', 'esportes', 'seg', 'tarde', 'motivação antiga para o teste', ?, '2025-01-01', ?, ?, ?, ?)")
            ->execute(["VOL-TESTE-$nome", "$nome $marca", "$nome-$sufixo@exemplo.org", $criadoEm, $criadoEm, $status, $criadoEm, $criadoEm]);
        return (int) $db->lastInsertId();
    };
    $ha13Meses = gmdate('Y-m-d H:i:s', strtotime('-13 months'));
    $ha11Meses = gmdate('Y-m-d H:i:s', strtotime('-11 months'));
    $recusada = $antiga('Recusada', 'recusada', $ha13Meses);
    $aceitaAntiga = $antiga('Aceita', 'aceita', $ha13Meses);
    $novaRecente = $antiga('Recente', 'nova', $ha11Meses);
    mcp_voluntario_nota_registrar($recusada, $quem, 'Nota que vai embora com a ficha.');
    mcp_voluntario_selfie_gravar($recusada, mcp_voluntario_selfie_preparar($foto(300, 300)));
    mcp_voluntario_selfie_gravar($aceitaAntiga, mcp_voluntario_selfie_preparar($foto(300, 300)));
    $x = mcp_voluntarios_expurgar(12);
    verificar('expurgo: a selfie vai junto com a ficha apagada e fica na aceita', [mcp_voluntario_selfie($recusada), mcp_voluntario_selfie($aceitaAntiga) !== null], [null, true]);
    verificar('expurgo: apaga a recusada de 13 meses (e a nota dela), poupa a aceita antiga e a recente',
        [$x, mcp_voluntario_por_id($recusada), mcp_voluntario_notas($recusada), mcp_voluntario_por_id($aceitaAntiga) !== null, mcp_voluntario_por_id($novaRecente) !== null, mcp_voluntario_por_id($anaId) !== null],
        [['apagadas' => 1], null, [], true, true, true]);
    verificar('expurgo: registrado em mcp_eventos', (string) $db->query("SELECT detalhe FROM mcp_eventos WHERE tipo = 'voluntarios_expurgo' ORDER BY id DESC LIMIT 1")->fetchColumn(), '1 ficha');
    verificar('expurgo sem nada a apagar: zero, sem novo evento', [mcp_voluntarios_expurgar(12), (int) $db->query("SELECT COUNT(*) FROM mcp_eventos WHERE tipo = 'voluntarios_expurgo'")->fetchColumn()], [['apagadas' => 0], 1]);
    $recusada2 = $antiga('Recusada2', 'arquivada', $ha13Meses);
    $diario = mcp_voluntarios_expurgo_diario();
    verificar('expurgo diário: roda uma vez por dia (mcp_chaves) e apaga a arquivada antiga', [$diario, mcp_voluntarios_expurgo_diario(), $db->query("SELECT valor FROM mcp_chaves WHERE nome = 'voluntarios_expurgo_em'")->fetchColumn()], [['apagadas' => 1], null, mcp_voluntario_hoje()]);

    $erros = array_values(array_filter(file($logErros) ?: [], static fn(string $l): bool => (bool) preg_match('/PHP (Warning|Notice|Deprecated|Fatal|Parse)/', $l)));
    verificar('sem aviso nem erro do PHP no servidor', $erros, []);
} finally {
    // Derruba o servidor embutido pelo PID (SIGTERM e, se ainda estiver vivo, SIGKILL).
    if ($pidServidor > 0 && function_exists('posix_kill')) {
        posix_kill($pidServidor, SIGTERM);
        usleep(200000);
        if (proc_get_status($servidor)['running'] ?? false) {
            posix_kill($pidServidor, SIGKILL);
        }
    } else {
        proc_terminate($servidor);
    }
    proc_close($servidor);
    if ($pidReceptor > 0 && function_exists('posix_kill')) {
        posix_kill($pidReceptor, SIGKILL);
    }
    proc_close($receptor);
    foreach (glob("$pastaAviso/*") ?: [] as $arquivo) {
        @unlink($arquivo);
    }
    @rmdir($pastaAviso);
    limpar($db);
    foreach ([$config, $semExtra, $logErros] as $arquivo) {
        @unlink($arquivo);
    }
}

printf("%d testes, %d falhas\n", $total, $falhas);
exit($falhas > 0 ? 1 : 0);
