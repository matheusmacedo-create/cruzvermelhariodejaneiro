#!/usr/bin/env php
<?php
/**
 * Teste da recuperação do lead (api/lib/recuperacao.php), contra um MariaDB LOCAL:
 *   - quem recebe o lembrete do PIX em aberto (janela de 2 a 20 h, a inscrição mais recente do e-mail e curso, sem
 *     pagamento do mesmo curso, um lembrete só por e-mail e curso);
 *   - a rodada: confere o pagamento antes (pago, vencido ou consulta que falhou não recebem), registra o envio e a
 *     falha, e não repete;
 *   - o conteúdo dos e-mails (lembrete e PIX aberto): certificado de amostra do curso, passo da escola, link com UTM;
 *   - a rotina de hora em hora (api/lembretes.php) roda as duas partes.
 *
 * As inscrições fictícias têm criado_em num "agora" de 2031, para nenhuma linha de outro teste cair na janela. A
 * consulta à Unicopag e o envio do e-mail são trocados por funções do teste; nenhum e-mail sai (o teste recusa
 * RESEND_API_KEY, e a rotina roda com sendmail_path=/bin/true). Apaga tudo no fim.
 *
 * Uso: MCP_CONFIG_ARQUIVO=/caminho/config-teste.php php scripts/testar_recuperacao_integracao.php
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$config = (string) getenv('MCP_CONFIG_ARQUIVO');
if ($config === '' || !is_file($config)) {
    fwrite(STDERR, "Falta MCP_CONFIG_ARQUIVO com o banco local (veja o cabeçalho deste arquivo).\n");
    exit(2);
}
$semEscola = tempnam(sys_get_temp_dir(), 'mcp-recuperacao-escola-');
file_put_contents($semEscola, '<?php return [];');
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

ignore_user_abort(true);

function limpar(PDO $db, array $ids): void
{
    $lista = implode(', ', array_map('intval', $ids));
    if ($lista !== '') {
        $db->exec("DELETE FROM mcp_eventos WHERE inscricao_id IN ($lista)");
        $db->exec("DELETE FROM mcp_inscricoes WHERE id IN ($lista)");
    }
}

$db = mcp_db();
$sobras = $db->prepare('SELECT id FROM mcp_inscricoes WHERE email REGEXP ?');
$sobras->execute(['^pix-[a-z0-9]+-[0-9a-f]{6}@exemplo[.]org$']);
limpar($db, $sobras->fetchAll(PDO::FETCH_COLUMN));

$T = (int) strtotime('2031-03-10 15:00:00 UTC'); // o "agora" do teste
$horasAtras = static fn(float $h): string => gmdate('Y-m-d H:i:s', (int) ($T - $h * 3600));
$sufixo = bin2hex(random_bytes(3));
$ids = [];
$criar = static function (string $chave, float $horas, array $campos = []) use ($db, $horasAtras, $sufixo, &$ids): array {
    $quando = $horasAtras($horas);
    $linha = $campos + [
        'token' => mcp_token_novo(), 'cpf' => (string) random_int(10000000000, 99999999999), 'telefone' => '21999990000', 'metodo' => 'pix',
        'curso_slug' => 'puncao-venosa', 'curso_nome' => 'Punção Venosa', 'nome' => "Pessoa Teste $chave",
        'email' => "pix-$chave-$sufixo@exemplo.org", 'inscricao_centavos' => 9900, 'taxa_centavos' => 0, 'total_centavos' => 9900,
        'status' => 'pendente', 'escola_status' => 'nao_aplicavel', 'unicopag_hash' => 'teste-' . bin2hex(random_bytes(8)),
        'pix_copia_cola' => "00020126PIXDETESTE$chave", 'criado_em' => $quando, 'atualizado_em' => $quando, 'pago_em' => null,
    ];
    $colunas = array_keys($linha);
    $db->prepare('INSERT INTO mcp_inscricoes (' . implode(', ', $colunas) . ') VALUES (' . implode(', ', array_fill(0, count($colunas), '?')) . ')')
        ->execute(array_values($linha));
    $linha['id'] = (int) $db->lastInsertId();
    $ids[] = $linha['id'];
    return $linha;
};
$eventos = static function (int $id) use ($db): array {
    $stmt = $db->prepare("SELECT tipo FROM mcp_eventos WHERE inscricao_id = ? AND tipo LIKE 'email_pix_lembrete%' ORDER BY id");
    $stmt->execute([$id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
};

try {
    // ------------------------------------------------------------------------- quem entra na janela
    $ok = $criar('ok', 3);                                                       // recebe
    $cedo = $criar('cedo', 1);                                                   // antes de 2 h
    $tarde = $criar('tarde', 21);                                                // depois de 20 h
    $cartao = $criar('cartao', 3, ['metodo' => 'cartao', 'pix_copia_cola' => null]);
    $semHash = $criar('semhash', 3, ['unicopag_hash' => null]);
    $velha = $criar('dup', 5);                                                   // a mais nova do mesmo e-mail e curso ganha
    $nova = $criar('dup', 3, ['email' => $velha['email'], 'token' => mcp_token_novo()]);
    $outroCurso = $criar('outro', 4, ['email' => $velha['email'], 'curso_slug' => 'bombeiro-civil', 'curso_nome' => 'Bombeiro Civil']);
    $jaPagou = $criar('pagou', 30, ['status' => 'pago', 'pago_em' => $horasAtras(29)]);
    $pagouAntes = $criar('pagou', 3, ['email' => $jaPagou['email']]);          // já pagou este curso noutra inscrição
    $pagaNaConsulta = $criar('pagaconsulta', 4);
    $consultaFalha = $criar('falhaconsulta', 6);
    $envioFalha = $criar('falhaenvio', 7, ['curso_slug' => 'bombeiro-civil', 'curso_nome' => 'Bombeiro Civil']);
    // Mesma pessoa pelo CPF: pagou com outro e-mail (ex.: corrigiu o e-mail digitado errado).
    $cpfPagou = $criar('cpfpagou', 2.5, ['status' => 'pago', 'pago_em' => $horasAtras(2.4)]);
    $cpfPendente = $criar('cpfpend', 3, ['cpf' => $cpfPagou['cpf']]);
    // PIX seguido de cartão recusado: o PIX continua pagável e recebe.
    $pixAntesRecusa = $criar('recusa', 4);
    $recusado = $criar('recusa', 3.9, ['email' => $pixAntesRecusa['email'], 'token' => mcp_token_novo(), 'metodo' => 'cartao',
        'pix_copia_cola' => null, 'status' => 'recusado']);
    $vence = $criar('vence', 5);

    $candidatos = array_column(mcp_pix_lembrete_candidatos($T), 'id');
    sort($candidatos);
    $esperados = [$ok['id'], $nova['id'], $outroCurso['id'], $pagaNaConsulta['id'], $consultaFalha['id'], $envioFalha['id'],
        $pixAntesRecusa['id'], $vence['id']];
    sort($esperados);
    verificar('candidatos: só PIX pendente de 2 a 20 h, o mais novo da pessoa (e-mail ou CPF) e curso, sem pagamento do curso', $candidatos, $esperados);
    verificar('candidatos: limite por rodada', count(mcp_pix_lembrete_candidatos($T, 2)), 2);

    // ------------------------------------------------------------------------- a rodada
    $situacao = [$pagaNaConsulta['id'] => 'pago', $consultaFalha['id'] => null, $vence['id'] => 'expirado'];
    // Como a consulta de verdade, a que acha o pagamento grava o status (aqui sem o pós-pagamento, que mandaria e-mails).
    $consultar = static function (array $i) use ($situacao, $db): ?string {
        $s = array_key_exists((int) $i['id'], $situacao) ? $situacao[(int) $i['id']] : 'pendente';
        if ($s === 'pago') {
            $db->prepare("UPDATE mcp_inscricoes SET status = 'pago' WHERE id = ?")->execute([(int) $i['id']]);
        }
        return $s;
    };
    $enviados = [];
    $enviar = static function (string $para, string $assunto, string $html, string $texto) use (&$enviados, $envioFalha): string {
        $enviados[] = compact('para', 'assunto', 'html', 'texto');
        return $para === $envioFalha['email'] ? 'falhou' : 'mail';
    };
    $r = mcp_pix_lembrete_rodar($T, $consultar, $enviar);
    verificar('rodada 1: contagem', $r, ['enviados' => 4, 'falhas' => 1, 'pulados' => 3, 'vistos' => 8]);
    verificar('rodada 1: registros', [$eventos($ok['id']), $eventos($nova['id']), $eventos($outroCurso['id']), $eventos($envioFalha['id']),
        $eventos($pagaNaConsulta['id']), $eventos($consultaFalha['id']), $eventos($pixAntesRecusa['id']), $eventos($vence['id'])],
        [['email_pix_lembrete'], ['email_pix_lembrete'], ['email_pix_lembrete'], ['email_pix_lembrete_falhou'], [], [], ['email_pix_lembrete'], []]);

    // Segunda rodada: só a consulta que falhou volta (agora pendente); ninguém recebe duas vezes, nem com inscrição nova.
    $refez = $criar('ok', 2.5, ['email' => $ok['email'], 'token' => mcp_token_novo()]);
    $enviados2 = [];
    $enviar2 = static function (string $para, string $assunto, string $html, string $texto) use (&$enviados2): string {
        $enviados2[] = $para;
        return 'mail';
    };
    $r2 = mcp_pix_lembrete_rodar($T + 1800, static fn(array $i): string => 'pendente', $enviar2);
    // A que venceu continua 'pendente' no banco (o teste não aplica o status), então volta: só a falha e ela.
    sort($enviados2);
    $esperados2 = [$consultaFalha['email'], $vence['email']];
    sort($esperados2);
    verificar('rodada 2: só quem ficou sem lembrete', [$r2['enviados'], $enviados2], [2, $esperados2]);
    verificar('rodada 2: inscrição nova do mesmo e-mail e curso não recebe outro lembrete', $eventos($refez['id']), []);

    // ------------------------------------------------------------------------- a consulta de verdade
    verificar('situação: status conhecidos traduzidos, vazio e desconhecido não mandam', [
        mcp_pix_lembrete_situacao('waiting_payment'), mcp_pix_lembrete_situacao('PAID'), mcp_pix_lembrete_situacao('expired'),
        mcp_pix_lembrete_situacao('refused'), mcp_pix_lembrete_situacao(''), mcp_pix_lembrete_situacao('in_analysis'),
    ], ['pendente', 'pago', 'expirado', 'recusado', null, null]);
    if ((string) mcp_cfg('UNICO_API_KEY', '') === '') {
        // Sem chave, mcp_unicopag lança exceção: o caminho de falha da consulta real.
        verificar('consulta real que falha: não manda e registra', [mcp_pix_lembrete_consultar($ok),
            in_array('consulta_falhou', $db->query('SELECT tipo FROM mcp_eventos WHERE inscricao_id = ' . (int) $ok['id'])->fetchAll(PDO::FETCH_COLUMN), true)],
            [null, true]);
    } else {
        echo "pulado: a configuração de teste tem UNICO_API_KEY; o caminho de falha da consulta real não foi exercitado\n";
    }

    // ------------------------------------------------------------------------- o e-mail do lembrete
    $m = null;
    foreach ($enviados as $e) {
        if ($e['para'] === $ok['email']) {
            $m = $e;
        }
    }
    $urlCert = mcp_site_url() . '/matricula-cursos-presenciais/img/certificado-puncao-venosa-email.jpg';
    verificar('lembrete: assunto', $m['assunto'] ?? null, 'Sua inscrição em Punção Venosa continua aberta: falta só o PIX');
    verificar('lembrete: certificado, código, link com UTM e passo do certificado', [
        str_contains($m['html'], 'src="' . $urlCert . '"'),
        str_contains($m['html'], '00020126PIXDETESTEok'),
        str_contains($m['html'], 'utm_campaign=pix-lembrete'),
        str_contains($m['html'], 'Você conclui e recebe o certificado'),
        str_contains($m['html'], 'reconhecida nacional e internacionalmente'),
        str_contains($m['texto'], 'utm_campaign=pix-lembrete'),
        str_contains($m['html'] . $m['texto'], 'MEC'),
        str_contains($m['html'] . $m['texto'], 'reserva sua vaga'),
        str_contains($m['html'], 'desistir em até 7 dias'),
    ], [true, true, true, true, true, true, false, false, true]);
    verificar('lembrete: sem imagem do curso, sem bloco do certificado', mcp_email_bloco_certificado('curso-que-nao-existe', 'X'), '');
    verificar('lembrete: slug estranho não vira caminho', mcp_certificado_url('../../config'), '');

    // ------------------------------------------------------------------------- o e-mail do PIX aberto
    $aberto = mcp_montar_email_pix_aberto(mcp_inscricao_por('id', (string) $ok['id']));
    $passo = mcp_email_passo_escola();
    verificar('PIX aberto: certificado, passo da escola de acordo com a configuração e passo do certificado', [
        str_contains($aberto['html'], 'src="' . $urlCert . '"'),
        str_contains($aberto['html'], mcp_escapar($passo[0])),
        str_contains($aberto['html'], 'Você conclui e recebe o certificado'),
        str_contains($aberto['texto'], 'reconhecida nacional e internacionalmente'),
        str_contains($aberto['html'], 'utm_campaign=pix-aberto'),
    ], [true, true, true, true, true]);
    verificar('PIX aberto: sem a escola configurada, a secretaria confirma a turma', $passo[0] === (mcp_escola_configurada() ? 'Sua matrícula entra na turma' : 'A secretaria confirma turma e horário'), true);

    // ------------------------------------------------------------------------- a rotina de hora em hora
    // Roda o arquivo como o cron (as inscrições do teste estão em 2031, fora da janela de hoje).
    $saida = [];
    exec(escapeshellarg(PHP_BINARY) . ' -d sendmail_path=/bin/true ' . escapeshellarg($raiz . '/site/matricula-cursos-presenciais/api/lembretes.php') . ' 2>&1', $saida, $codigo);
    $saida = implode("\n", $saida);
    verificar('rotina: roda o lembrete de horários e o do PIX', [str_contains($saida, ' lembretes: '), str_contains($saida, ' lembrete do PIX: ')], [true, true]);
} finally {
    limpar($db, $ids);
    @unlink($semEscola);
}

echo "\n$total verificações, $falhas falha(s).\n";
exit($falhas > 0 ? 1 : 0);
