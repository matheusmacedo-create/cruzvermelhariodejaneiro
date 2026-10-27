#!/usr/bin/env php
<?php
/**
 * Teste de ponta a ponta da seção Voluntários do portal da secretaria (api/painel.php + lib/painel_voluntarios.php),
 * contra um MariaDB LOCAL e o servidor embutido do PHP. Cobre: menu com a contagem de fichas novas, cartão do Início,
 * lista (chips com contagem, filtro por situação, busca), ficha (CPF só parcial, rótulos legíveis, consentimentos),
 * CSRF (POST sem token recusado), mudança de situação com nota no histórico, aceitação sem CPF (pede o CPF) e com CPF
 * (cria o colaborador do ponto e mostra o link), planilha CSV sem CPF e PDF da ficha.
 *
 * Precisa de MCP_CONFIG_ARQUIVO com o banco local e sem RESEND_API_KEY. CPFs sintéticos; tudo é apagado no fim.
 *
 * Uso: MCP_CONFIG_ARQUIVO=/caminho/config-teste.php php scripts/testar_voluntarios_portal_integracao.php
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$configOriginal = (string) getenv('MCP_CONFIG_ARQUIVO');
if ($configOriginal === '' || !is_file($configOriginal)) {
    fwrite(STDERR, "Falta MCP_CONFIG_ARQUIVO com o banco local (veja o cabeçalho deste arquivo).\n");
    exit(2);
}
$portaSite = (int) (getenv('MCP_TESTE_PORTA') ?: 18900 + random_int(0, 499));
$config = tempnam(sys_get_temp_dir(), 'mcp-volp-');
file_put_contents($config, '<?php return [\'SITE_URL\' => \'http://127.0.0.1:' . $portaSite . '\', \'EMAIL_CONTATO\' => \'contato@exemplo.org\', '
    . '\'EMAIL_VOLUNTARIADO\' => \'voluntariado@exemplo.org\', \'PAINEL_EMAILS\' => \'vol@exemplo.org\', \'ESCOLA_API_URL\' => \'\'] + (require '
    . var_export($configOriginal, true) . ');');
$semExtra = tempnam(sys_get_temp_dir(), 'mcp-volp-extra-');
file_put_contents($semExtra, '<?php return [];');
putenv("MCP_CONFIG_ARQUIVO=$config");
$ambiente = ['MCP_CONFIG_ARQUIVO' => $config, 'PATH' => (string) getenv('PATH')];
foreach (['MCP_CONFIG_ESCOLA_ARQUIVO', 'MCP_CONFIG_WHATSAPP_ARQUIVO', 'MCP_CONFIG_META_ARQUIVO', 'MCP_CONFIG_PAGAR_TUDO_ARQUIVO', 'MCP_CONFIG_GOOGLE_ARQUIVO'] as $variavel) {
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
$marca = "Teste Voluntário Portal $sufixo";

/** Apaga o que este teste (ou uma execução interrompida) criou: nomes com "Teste Voluntário". */
function limpar(PDO $db): void
{
    $ids = $db->query("SELECT id FROM mcp_voluntarios WHERE nome LIKE '%Teste Voluntário %'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $lista = implode(', ', array_map('intval', $ids));
        $db->exec("DELETE FROM mcp_voluntarios_notas WHERE ficha_id IN ($lista)");
        $db->exec("DELETE FROM mcp_voluntarios_selfies WHERE ficha_id IN ($lista)");
        $db->exec("DELETE FROM mcp_drive_fila WHERE tipo = 'ficha_voluntario' AND referencia IN ($lista)");
        $db->exec("DELETE FROM mcp_voluntarios WHERE id IN ($lista)");
    }
    $colaboradores = $db->query("SELECT id FROM mcp_colaboradores WHERE nome LIKE '%Teste Voluntário %'")->fetchAll(PDO::FETCH_COLUMN);
    if ($colaboradores) {
        $lista = implode(', ', array_map('intval', $colaboradores));
        $db->exec("DELETE FROM mcp_ponto WHERE colaborador_id IN ($lista)");
        $db->exec("DELETE FROM mcp_colaboradores WHERE id IN ($lista)");
    }
    $db->exec("DELETE FROM mcp_eventos WHERE tipo IN ('voluntario', 'voluntario_status', 'voluntario_repetida', 'voluntarios_expurgo')
        OR (tipo IN ('limite', 'armadilha') AND detalhe LIKE 'voluntarios%')");
    $db->exec("DELETE FROM mcp_chaves WHERE nome = 'voluntarios_expurgo_em'");
}
limpar($db);

// ----------------------------------------------------------------------------- servidor
$logErros = tempnam(sys_get_temp_dir(), 'mcp-volp-php-');
$nulo = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
$servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$portaSite", '-t', $raiz . '/site', '-d', 'sendmail_path=/bin/true',
    '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$logErros"], $nulo, $tubos, null, $ambiente);
$pidServidor = (int) (proc_get_status($servidor)['pid'] ?? 0);
$base = "http://127.0.0.1:$portaSite/matricula-cursos-presenciais/api/";
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $portaSite) === false; $i++) {
    usleep(100000);
}

/** [status, cabeçalhos (minúsculos), corpo]. Não segue redirecionamentos: o 303 é o que se confere. */
function http(string $url, string $metodo = 'GET', ?string $corpo = null, array $cabecalhos = []): array
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


/** POST de formulário no portal com a sessão: [status, cabeçalhos, corpo]. */
function portal(string $base, string $sessao, array $campos): array
{
    return http($base . 'painel.php', 'POST', http_build_query($campos), ['Content-Type: application/x-www-form-urlencoded', "Cookie: $sessao"]);
}

$quem = 'vol@exemplo.org';
mcp_painel_sessao_abrir($quem);
$sessao = 'mcp_painel=' . $_COOKIE[MCP_PAINEL_COOKIE];
$csrf = static fn(string $acao, int $id): string => mcp_painel_csrf($quem, $acao, $id);
$get = static fn(string $q): array => http($base . 'painel.php?' . $q, 'GET', null, ["Cookie: $sessao"]);

$gravar = static function (string $nome, string $email, array $muda = []) use ($marca): int {
    $agora = mcp_agora();
    return mcp_voluntario_gravar($muda + [
        'nome' => "$nome $marca", 'data_nascimento' => '1990-05-20', 'email' => $email, 'telefone' => '21998765432', 'cpf' => null,
        'cidade' => 'Niterói', 'bairro' => 'Icaraí', 'estado' => 'RJ', 'escolaridade' => 'superior', 'profissao' => 'Enfermeira',
        'saude' => 1, 'saude_qual' => 'Técnica de enfermagem', 'experiencia' => "Linha 1\nLinha 2 <b>negrito</b>", 'habilidades' => 'Inglês',
        'frentes' => 'formacao_primeiros_socorros,comunicacao', 'dias' => 'sab,dom', 'periodos' => 'manha', 'motivacao' => 'Quero ajudar a minha cidade em emergências e campanhas.',
        'como_conheceu' => 'instagram', 'aceita_contato' => 1, 'consentimento_em' => $agora, 'politica_versao' => mcp_voluntario_politica_versao(), 'veracidade_em' => $agora,
        'pagina' => '/voluntariado/cadastro/', 'utm_source' => 'instagram', 'utm_campaign' => 'outubro', 'ip' => '127.0.0.1',
    ]);
};

try {
    $cpfAna = cpf_de_teste();
    $ana = $gravar('Ana', "ana.$sufixo@exemplo.org", ['cpf' => $cpfAna]);
    $bruno = $gravar('Bruno', "bruno.$sufixo@exemplo.org", ['data_nascimento' => date('Y-m-d', strtotime('-17 years'))]);
    $carla = $gravar('Carla', "carla.$sufixo@exemplo.org", ['cidade' => 'Petrópolis']);
    foreach ([$ana, $bruno, $carla] as $id) {
        mcp_voluntario_atualizar($id, ['protocolo' => mcp_voluntario_protocolo($id)]);
    }

    // Sem sessão: o portal pede login.
    [$st, , $html] = http($base . 'painel.php?v=voluntarios');
    verificar('sem sessão: não mostra as fichas', [$st === 200 || $st === 303, str_contains($html, "Ana $marca")], [true, false]);

    // Início e menu.
    [$st, , $html] = $get('');
    verificar('início: cartão de fichas novas com os nomes e o link da lista', [$st, str_contains($html, 'Fichas de voluntariado'), str_contains($html, "Ana $marca"), str_contains($html, 'painel.php?v=voluntarios')], [200, true, true, true]);
    verificar('menu: item Voluntários com a contagem de novas', (bool) preg_match('~href="painel\.php\?v=voluntarios"[^>]*>.*?Voluntários.*?<~s', $html), true);

    // Lista.
    [$st, , $html] = $get('v=voluntarios');
    verificar('lista: 200, as três fichas e o selo Nova', [$st, str_contains($html, "Ana $marca"), str_contains($html, "Bruno $marca"), str_contains($html, "Carla $marca"), str_contains($html, '>Nova<')], [200, true, true, true, true]);
    verificar('lista: menor de 18 sinalizado, cidade e frentes legíveis', [str_contains($html, 'menor de 18'), str_contains($html, 'Petrópolis'), str_contains($html, 'Formação e primeiros socorros')], [true, true, true]);
    verificar('lista: nenhum CPF', str_contains($html, $cpfAna) || str_contains($html, substr($cpfAna, 0, 3) . '.' . substr($cpfAna, 3, 3)), false);
    [, , $html] = $get('v=voluntarios&q=' . rawurlencode("Carla $marca"));
    verificar('busca: só a Carla', [str_contains($html, "Carla $marca"), str_contains($html, "Ana $marca")], [true, false]);

    // Ficha.
    [$st, , $html] = $get("v=voluntario&id=$ana");
    verificar('ficha: 200, nome, CPF parcial e nunca o inteiro', [$st, str_contains($html, "Ana $marca"), str_contains($html, substr($cpfAna, 0, 3) . '.***'), str_contains($html, $cpfAna)], [200, true, true, false]);
    verificar('ficha: rótulo da formação em saúde (não é condição de saúde)', [str_contains($html, 'Formação na área da saúde'), str_contains($html, 'Condição de saúde')], [true, false]);
    verificar('ficha: texto da pessoa escapado com as quebras', [str_contains($html, 'Linha 1<br>'), str_contains($html, '&lt;b&gt;negrito&lt;/b&gt;'), str_contains($html, '<b>negrito</b>')], [true, true, false]);
    verificar('ficha: consentimento com a versão da política e a origem', [str_contains($html, mcp_voluntario_politica_versao()), str_contains($html, 'instagram outubro')], [true, true]);
    [, , $htmlBruno] = $get("v=voluntario&id=$bruno");
    verificar('ficha do menor: aviso do responsável legal', str_contains($htmlBruno, 'Menor de 18 anos'), true);

    // Selfie (27/10/2026): aparece na ficha, a imagem só sai com a sessão e sem cache, e o botão apaga só a foto.
    verificar('ficha sem selfie: diz que não veio, sem imagem', [str_contains($html, 'não tem selfie'), str_contains($html, 'selfie=1')], [true, false]);
    $img = imagecreatetruecolor(900, 1200);
    ob_start();
    imagejpeg($img);
    imagedestroy($img);
    mcp_voluntario_selfie_gravar($ana, mcp_voluntario_selfie_preparar((string) ob_get_clean()));
    [, , $html] = $get("v=voluntario&id=$ana");
    verificar('ficha com selfie: imagem e botão de apagar', [str_contains($html, 'v=voluntario&amp;id=' . $ana . '&amp;selfie=1'), str_contains($html, 'Apagar a selfie'), str_contains($html, 'width="600" height="800"')], [true, true, true]);
    [$st, $h, $jpeg] = $get("v=voluntario&id=$ana&selfie=1");
    verificar('imagem da selfie: JPG, sem cache, com a sessão', [$st, $h['content-type'] ?? null, $h['cache-control'] ?? null, str_starts_with($jpeg, "\xFF\xD8\xFF")], [200, 'image/jpeg', 'no-store, private', true]);
    [$st, $h, $semSessao] = http($base . "painel.php?v=voluntario&id=$ana&selfie=1");
    verificar('imagem da selfie sem sessão: não sai', [str_starts_with($semSessao, "\xFF\xD8\xFF"), str_starts_with((string) ($h['content-type'] ?? ''), 'image/')], [false, false]);
    [$st] = $get("v=voluntario&id=$bruno&selfie=1");
    verificar('imagem de ficha sem selfie: 404', $st, 404);
    portal($base, $sessao, ['acao' => 'voluntario_foto_del', 'id' => $ana, 't' => 'falso']);
    verificar('apagar a selfie com token falso: nada muda', mcp_voluntario_selfie($ana) !== null, true);
    [$st, $h] = portal($base, $sessao, ['acao' => 'voluntario_foto_del', 'id' => $ana, 't' => $csrf('voluntario_foto_del', $ana)]);
    verificar('apagar a selfie: 303 com aviso, a foto some e a ficha fica', [$st, $h['location'] ?? null, mcp_voluntario_selfie($ana), mcp_voluntario_por_id($ana) !== null], [303, "painel.php?v=voluntario&id=$ana&ok=vf_apagada", null, true]);
    [, , $html] = $get("v=voluntario&id=$ana&ok=vf_apagada");
    verificar('depois de apagar: aviso e ficha sem a imagem', [str_contains($html, 'Selfie apagada'), str_contains($html, 'selfie=1')], [true, false]);

    // CSRF.
    [$st, , $html] = portal($base, $sessao, ['acao' => 'voluntario_status', 'id' => $ana, 'status' => 'em_analise', 't' => 'falso']);
    verificar('POST com token falso: recusado e nada muda', [str_contains($html, 'formulário não é mais válido') || $st === 303, mcp_voluntario_por_id($ana)['status']], [true, 'nova']);

    // Situação e nota.
    [$st, $h] = portal($base, $sessao, ['acao' => 'voluntario_status', 'id' => $ana, 'status' => 'entrevista', 'nota' => 'Entrevista na quinta às 15h', 't' => $csrf('voluntario_status', $ana)]);
    verificar('entrevista marcada: 303 com aviso e situação gravada', [$st, $h['location'] ?? null, mcp_voluntario_por_id($ana)['status'], mcp_voluntario_por_id($ana)['status_por']], [303, "painel.php?v=voluntario&id=$ana&ok=vs_entrev", 'entrevista', $quem]);
    [, , $html] = $get("v=voluntario&id=$ana&ok=vs_entrev");
    verificar('histórico: mudança de situação e a nota', [str_contains($html, 'Entrevista na quinta às 15h'), str_contains($html, 'Nova → Entrevista') || str_contains($html, '→ Entrevista'), str_contains($html, 'Entrevista marcada registrada')], [true, true, true]);
    [$st, $h] = portal($base, $sessao, ['acao' => 'voluntario_nota', 'id' => $ana, 'nota' => 'Ligou de volta.', 't' => $csrf('voluntario_nota', $ana)]);
    verificar('nota avulsa: gravada', [$st, count(mcp_voluntario_notas($ana))], [303, 2]);
    [, , $html] = portal($base, $sessao, ['acao' => 'voluntario_status', 'id' => $ana, 'status' => 'aceita', 't' => $csrf('voluntario_status', $ana)]);
    verificar('aceitar pelo botão de situação: recusado, manda usar Aceitar', [str_contains($html, 'use a ação'), mcp_voluntario_por_id($ana)['status']], [true, 'entrevista']);

    // Aceitação.
    [, , $html] = portal($base, $sessao, ['acao' => 'voluntario_aceitar', 'id' => $carla, 'cpf' => '', 't' => $csrf('voluntario_aceitar', $carla)]);
    verificar('aceitar sem CPF: pede o CPF e não aceita', [str_contains($html, 'CPF'), str_contains($html, 'name="cpf"'), mcp_voluntario_por_id($carla)['status']], [true, true, 'nova']);
    $cpfCarla = cpf_de_teste();
    [$st, $h] = portal($base, $sessao, ['acao' => 'voluntario_aceitar', 'id' => $carla, 'cpf' => $cpfCarla, 'nota' => 'Aprovada', 't' => $csrf('voluntario_aceitar', $carla)]);
    $c = mcp_voluntario_por_id($carla);
    $colab = $c['colaborador_id'] ? $db->query('SELECT vinculo, ativo, cpf FROM mcp_colaboradores WHERE id = ' . (int) $c['colaborador_id'])->fetch() : null;
    verificar('aceitar com CPF: aceita, cria o colaborador voluntário ativo', [$st, $h['location'] ?? null, $c['status'], $colab['vinculo'] ?? null, (int) ($colab['ativo'] ?? 0), $colab['cpf'] ?? null],
        [303, "painel.php?v=voluntario&id=$carla&ok=va_ok", 'aceita', 'voluntario', 1, $cpfCarla]);
    [, , $html] = $get("v=voluntario&id=$carla&ok=va_ok");
    verificar('ficha aceita: link para o colaborador do ponto', str_contains($html, 'painel.php?v=colaborador&amp;id=' . (int) $c['colaborador_id']), true);

    // CSV e PDF.
    [$st, $h, $csv] = portal($base, $sessao, ['acao' => 'voluntarios_csv', 'id' => 0, 'status' => '', 'busca' => '', 'frente' => '', 't' => $csrf('voluntarios_csv', 0)]);
    verificar('CSV: anexo, com as fichas e sem CPF nem IP', [$st, str_contains($h['content-disposition'] ?? '', 'attachment; filename="fichas-voluntarios-'), str_contains($csv, "Ana $marca"), str_contains($csv, $cpfAna), str_contains($csv, $cpfCarla), str_contains(mb_strtolower($csv), 'cpf'), str_contains($csv, '127.0.0.1')],
        [200, true, true, false, false, false, false]);
    verificar('CSV: cabeçalho com Formação em saúde', str_contains($csv, 'Formação em saúde'), true);
    [$st, $h, $pdf] = portal($base, $sessao, ['acao' => 'voluntario_pdf', 'id' => $ana, 't' => $csrf('voluntario_pdf', $ana)]);
    verificar('PDF: anexo que começa com %PDF', [$st, str_starts_with($pdf, '%PDF'), str_contains($h['content-type'] ?? '', 'application/pdf'), str_contains($h['content-disposition'] ?? '', 'attachment')], [200, true, true, true]);
    [$st, , $html] = portal($base, $sessao, ['acao' => 'voluntario_pdf', 'id' => $ana, 't' => 'falso']);
    verificar('PDF com token falso: não sai', str_starts_with($html, '%PDF'), false);

    $erros = array_values(array_filter(file($logErros) ?: [], static fn(string $l): bool => (bool) preg_match('/PHP (Warning|Notice|Deprecated|Fatal|Parse)/', $l)));
    verificar('sem aviso nem erro do PHP no servidor', $erros, []);
} finally {
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
    limpar($db);
    $db->exec("DELETE FROM mcp_eventos WHERE tipo LIKE 'painel_voluntari%'");
    foreach ([$config, $semExtra, $logErros] as $arquivo) {
        @unlink($arquivo);
    }
}

printf("%d testes, %d falhas\n", $total, $falhas);
exit($falhas > 0 ? 1 : 0);
