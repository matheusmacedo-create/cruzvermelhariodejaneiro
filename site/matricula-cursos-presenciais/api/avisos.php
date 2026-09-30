<?php
/**
 * Páginas pessoais dos avisos do ponto (lib/avisos.php e lib/comunicacao.php):
 *   GET  ?r=<id>.<destino>.<assinatura>          clique num link de aviso: conta o clique e leva ao destino;
 *   POST ?u=<id>.<assinatura>                     descadastro de um clique (List-Unsubscribe do e-mail); o GET mostra
 *                                                  uma página com o botão (POST confirmar=1), sem link pessoal;
 *   POST {acao: lembretes_ler | lembretes_salvar, t, …}  o que o colaborador quer receber (ponto/lembretes/);
 *   POST {acao: saida_ler | saida_informar, t, hora, dia_seguinte}  saída sem registro do voluntário (ponto/saida/);
 *   POST {acao: opiniao_ler | opiniao_salvar, t, …}      opinião depois de 2 semanas (ponto/opiniao/);
 *   POST {acao: sair_ler | sair_confirmar, t}             aluno que não quer mais receber avisos (ponto/sair/).
 * O token (t) vem no link pessoal, assinado, com validade, e é conferido a cada chamada. Sem ele, nada.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

/** Cliques e salvamentos por IP a cada 10 minutos. */
const AV_LIMITE_SALVAR = [30, 600];

$metodo = $_SERVER['REQUEST_METHOD'] ?? '';
// Descadastro de um clique (List-Unsubscribe, RFC 8058): o programa de e-mail manda um POST sozinho. Abrir o
// link (GET) mostra uma página com um botão, sem descadastrar (robôs e verificadores de e-mail abrem links
// sem ninguém pedir) e sem link pessoal (quem tem o cabeçalho não ganha acesso às escolhas da pessoa).
if (isset($_GET['u'])) {
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    $aviso = mcp_avisos_descadastro_aviso(mcp_texto($_GET['u'], 40));
    $pelaPagina = $metodo === 'POST' && !empty($_POST['confirmar']);
    if ($metodo === 'POST' && !$pelaPagina) {
        $valeu = $aviso !== null && mcp_avisos_descadastrar($aviso);
        http_response_code($valeu ? 200 : 404);
        header('Content-Type: text/plain; charset=utf-8');
        echo $valeu ? 'Pronto: você não vai mais receber estes avisos por e-mail.' : 'Link inválido.';
        exit;
    }
    $valeu = $aviso !== null && (!$pelaPagina || mcp_avisos_descadastrar($aviso));
    http_response_code($valeu ? 200 : 404);
    av_pagina_descadastro($valeu ? ($pelaPagina ? 'pronto' : 'pergunta') : 'invalido');
    exit;
}

/** Página do descadastro aberta no navegador: pergunta (com o botão), pronto ou link que não vale mais. */
function av_pagina_descadastro(string $estado): void
{
    header('Content-Type: text/html; charset=utf-8');
    $contato = mcp_escapar(mcp_email_contato_endereco());
    $css = '/matricula-cursos-presenciais/static/ponto.css?v=' . substr((string) md5_file(__DIR__ . '/../static/ponto.css'), 0, 10);
    $corpo = match ($estado) {
        'pergunta' => '<h1>Parar os avisos por e-mail</h1><p>Toque no botão para não receber mais estes avisos neste e-mail.</p>'
            . '<form method="post"><input type="hidden" name="confirmar" value="1"><button class="pt-btn" type="submit">Não quero mais receber</button></form>'
            . '<p class="pt-nota">Para escolher os dias ou receber pelo WhatsApp, use o link "Escolher o que recebo" de uma mensagem recente.</p>',
        'pronto' => '<h1>Pronto</h1><p>Você não vai mais receber estes avisos por e-mail.</p>'
            . '<p class="pt-nota">Mudou de ideia? Escreva para a secretaria: <a href="mailto:' . $contato . '">' . $contato . '</a>.</p>',
        default => '<h1>Este link não vale mais</h1><p>Ele foi trocado, ou o e-mail do cadastro mudou.</p>'
            . '<p class="pt-nota">Para parar os avisos, use o link da mensagem mais recente ou escreva para a secretaria: <a href="mailto:' . $contato . '">' . $contato . '</a>.</p>',
    };
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer"><meta name="theme-color" content="#cc0000">'
        . '<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg"><title>Avisos por e-mail | ' . mcp_escapar(MCP_NOME_FILIAL) . '</title>'
        . '<link rel="stylesheet" href="' . $css . '"></head><body><div class="pt-faixa"></div>'
        . '<header class="pt-topo"><a href="/"><img src="/assets/otim/logo-cvb-rj-480.png" alt="Cruz Vermelha Brasileira · Rio de Janeiro" width="160" height="48"></a>'
        . '<div class="pt-titulo"><b>Escola de Educação e Saúde</b><small>Avisos por e-mail e WhatsApp</small></div></header>'
        . '<main class="pt-main"><section class="pt-card">' . $corpo . '</section></main>'
        . '<footer class="pt-rodape"><p>Os e-mails da matrícula e o comprovante de comparecimento continuam chegando. <a href="/privacidade/">Privacidade</a></p></footer>'
        . '</body></html>';
}
if ($metodo === 'GET' || $metodo === 'HEAD') {
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    // HEAD e robôs (prévias de link do WhatsApp, verificadores de e-mail): vão ao ponto, sem contar clique
    // e sem gerar link pessoal.
    $robo = mcp_avisos_eh_robo((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $destino = $metodo === 'GET' && !$robo ? mcp_avisos_clique(mcp_texto($_GET['r'] ?? '', 80)) : null;
    header('Location: ' . ($destino ?? mcp_site_url() . '/ponto/'), true, 302);
    exit;
}

$corpo = mcp_exigir_post_json();
$acao = mcp_texto($corpo['acao'] ?? '', 20);
$usos = [
    'lembretes_ler' => 'lembretes', 'lembretes_salvar' => 'lembretes', 'saida_ler' => 'saida', 'saida_informar' => 'saida',
    'opiniao_ler' => 'opiniao', 'opiniao_salvar' => 'opiniao', 'sair_ler' => 'sair', 'sair_confirmar' => 'sair',
];
if (!isset($usos[$acao])) {
    mcp_falhar(400, 'Ação desconhecida.');
}
$token = mcp_avisos_token_ler($usos[$acao], $corpo['t'] ?? null);
if (!$token['ok']) {
    if (!empty($token['vencido'])) {
        mcp_falhar(410, 'Este link venceu. Peça um novo à secretaria, ou use o link do aviso mais recente.', ['motivo' => 'vencido', 'contato' => mcp_email_contato_endereco()]);
    }
    mcp_falhar(404, 'Link inválido. Confira se ele veio inteiro, ou peça um novo à secretaria.', ['motivo' => 'invalido', 'contato' => mcp_email_contato_endereco()]);
}
if (str_ends_with($acao, '_salvar') || str_ends_with($acao, '_informar') || str_ends_with($acao, '_confirmar')) {
    [$maximo, $janela] = AV_LIMITE_SALVAR;
    if (mcp_contar_eventos_recentes('aviso_pagina', mcp_ip_balde(), $janela) >= $maximo) {
        mcp_falhar(429, 'Muitas tentativas em pouco tempo. Aguarde alguns minutos e tente de novo.');
    }
    mcp_registrar(null, 'aviso_pagina', mcp_ip_balde());
}
$c = $token['colaborador'];
if ($c !== null && !(int) $c['ativo']) {
    mcp_falhar(410, 'Seu cadastro de colaborador não está ativo. Fale com a secretaria.', ['motivo' => 'inativo']);
}

// ----------------------------------------------------------------------------- lembretes do colaborador
/** O que a página de lembretes mostra: preferências atuais e o que está ligado no portal. */
function av_lembretes(array $c): array
{
    $numero = mcp_whatsapp_numero((string) $c['telefone']);
    return [
        'ok' => true,
        'nome' => mcp_primeiro_nome(mcp_nome_proprio((string) $c['nome'])),
        'voluntario' => mcp_ponto_voluntario($c),
        'email' => filter_var((string) $c['email'], FILTER_VALIDATE_EMAIL) ? mcp_email_mascarado((string) $c['email']) : null,
        'whatsapp' => $numero !== null ? mcp_whatsapp_mascarado($numero) : null,
        'whatsapp_disponivel' => mcp_whatsapp_ativo(),
        'vespera_ligada' => mcp_ajuste_ligado('lembrete_vespera'),
        'saida_ligada' => mcp_ajuste_ligado('lembrete_saida'),
        // Equipe contratada: lembrete só de terça a sexta, com a véspera em dia útil (mcp_avisos_recebe_vespera).
        'dias_opcoes' => mcp_ponto_voluntario($c) ? MCP_AVISOS_DIAS : array_intersect_key(MCP_AVISOS_DIAS, array_flip(MCP_AVISOS_DIAS_EQUIPE)),
        'prefs' => [
            'email' => (int) $c['aviso_email'] === 1, 'whatsapp' => (int) $c['aviso_whatsapp'] === 1, 'dias' => mcp_avisos_dias($c['aviso_dias']),
            'saida' => (int) $c['aviso_saida'] === 1, 'comunicados' => (int) $c['aviso_comunicados'] === 1,
        ],
    ];
}

if ($c !== null && $acao === 'lembretes_ler') {
    mcp_json(av_lembretes($c));
}
if ($c !== null && $acao === 'lembretes_salvar') {
    $whatsapp = !empty($corpo['whatsapp']);
    $telefone = $c['telefone'];
    $novoNumero = mcp_texto($corpo['telefone'] ?? '', 30);
    if ($novoNumero !== '') {
        $numero = mcp_whatsapp_numero($novoNumero);
        if ($numero === null) {
            mcp_falhar(422, 'Número inválido: escreva o DDD e o celular, com o 9 na frente (ex.: 21 99999-9999).', ['campo' => 'telefone']);
        }
        // Trocar um número já cadastrado só pela secretaria: um link encaminhado não pode mandar as
        // mensagens para o celular de outra pessoa. Quem ainda não tem celular no cadastro pode incluir.
        $atualNumero = mcp_whatsapp_numero((string) $c['telefone']);
        if ($atualNumero !== null && $atualNumero !== $numero) {
            mcp_falhar(422, 'Para trocar o número do WhatsApp, fale com a secretaria.', ['campo' => 'telefone']);
        }
        $telefone = substr($numero, 2);
    }
    if ($whatsapp && mcp_whatsapp_numero((string) $telefone) === null) {
        mcp_falhar(422, 'Para receber pelo WhatsApp, escreva o número do seu celular.', ['campo' => 'telefone']);
    }
    // Sem e-mail no cadastro, a escolha do e-mail fica como estava (quando a secretaria incluir o e-mail,
    // os avisos passam a chegar por ele).
    $email = filter_var((string) $c['email'], FILTER_VALIDATE_EMAIL) ? !empty($corpo['email']) : (int) $c['aviso_email'] === 1;
    if ($telefone !== $c['telefone']) {
        mcp_registrar(null, 'aviso_telefone', '#' . $c['id'] . ' · pela pessoa · ' . mcp_whatsapp_mascarado((string) $c['telefone']) . ' → ' . mcp_whatsapp_mascarado('55' . $telefone));
    }
    mcp_avisos_preferencias_salvar($c, [
        'email' => $email, 'whatsapp' => $whatsapp, 'telefone' => $telefone,
        'dias' => mcp_ponto_voluntario($c) ? (array) ($corpo['dias'] ?? []) : array_intersect(mcp_avisos_dias($corpo['dias'] ?? []), MCP_AVISOS_DIAS_EQUIPE),
        'saida' => !empty($corpo['saida']), 'comunicados' => !empty($corpo['comunicados']), 'como' => 'pela página de lembretes',
    ], 'a própria pessoa', true);
    $atual = mcp_colaborador_por_id((int) $c['id']);
    $dias = mcp_avisos_dias($atual['aviso_dias']);
    $canais = array_keys(mcp_avisos_canais_todos($atual));
    $canaisTexto = $canais === ['email', 'whatsapp'] ? 'por e-mail e WhatsApp' : ($canais === ['whatsapp'] ? 'pelo WhatsApp' : ($canais === ['email'] ? 'por e-mail' : ''));
    $mensagem = match (true) {
        !$canais => 'Pronto. Você não vai receber lembretes. Se mudar de ideia, é só voltar por este link.',
        $dias !== [] => 'Pronto! Você recebe o lembrete na véspera das ' . mcp_avisos_dias_texto($dias) . ", às 18h, $canaisTexto.",
        default => "Pronto! Suas escolhas foram salvas. Sem dias marcados, você não recebe o lembrete da véspera; os outros avisos chegam $canaisTexto.",
    };
    mcp_json(['mensagem' => $mensagem] + av_lembretes($atual));
}

// ----------------------------------------------------------------------------- saída sem registro
if ($c !== null && ($acao === 'saida_ler' || $acao === 'saida_informar')) {
    $registro = mcp_ponto_registro((int) $token['extra']);
    if (!$registro || (int) $registro['colaborador_id'] !== (int) $c['id']) {
        mcp_falhar(404, 'Registro não encontrado. Fale com a secretaria.');
    }
    $agora = time();
    $dia = mcp_data_brt((string) $registro['entrada'], 'Y-m-d');
    $resposta = static function (array $r) use ($c, $dia, $agora): array {
        return [
            'ok' => true,
            'nome' => mcp_primeiro_nome(mcp_nome_proprio((string) $c['nome'])),
            'quando' => mcp_avisos_quando($dia, $agora),
            'data' => mcp_escola_data($dia),
            'entrada' => mcp_data_brt((string) $r['entrada'], 'H:i'),
            'saida' => $r['saida'] !== null ? mcp_data_brt((string) $r['saida'], 'H:i') : null,
            'informada' => $r['saida_informada'] !== null ? mcp_data_brt((string) $r['saida_informada'], 'H:i') : null,
            'pode' => mcp_ponto_saida_informavel($r, $agora),
        ];
    };
    if ($acao === 'saida_informar') {
        $erro = mcp_ponto_saida_informar($registro, mcp_texto($corpo['hora'] ?? '', 5), !empty($corpo['dia_seguinte']), 'link do lembrete', $agora);
        if ($erro !== null) {
            mcp_falhar(422, $erro, ['campo' => 'hora']);
        }
        $registro = mcp_ponto_registro((int) $registro['id']);
        mcp_json(['mensagem' => 'Obrigado! A secretaria confere e as horas desse dia entram na sua conta de horas doadas.'] + $resposta($registro));
    }
    mcp_json($resposta($registro));
}

// ----------------------------------------------------------------------------- opinião
if ($acao === 'opiniao_ler' || $acao === 'opiniao_salvar') {
    $ctx = mcp_opiniao_contexto($token);
    if ($ctx === null) {
        mcp_falhar(404, 'Esta pesquisa não está mais disponível. Obrigado!');
    }
    $publico = static function (array $ctx): array {
        $r = $ctx['resposta'];
        return [
            'ok' => true,
            'nome' => $ctx['primeiro_nome'],
            'publico' => $ctx['publico'],
            'opcoes' => [
                'facilidade' => MCP_OPINIAO_FACILIDADE,
                'como' => $ctx['publico'] === 'aluno' ? MCP_OPINIAO_COMO_ALUNO : MCP_OPINIAO_COMO,
                'problemas' => $ctx['publico'] === 'aluno' ? MCP_OPINIAO_PROBLEMAS_ALUNO : MCP_OPINIAO_PROBLEMAS,
                'lembretes' => $ctx['publico'] === 'colaborador' ? MCP_OPINIAO_LEMBRETES : null,
            ],
            'resposta' => $r ? [
                'facilidade' => (int) $r['facilidade'], 'como' => (string) $r['como'], 'problemas' => array_values(array_filter(explode(',', (string) $r['problemas']))),
                'lembretes' => $r['lembretes'], 'comentario' => (string) $r['comentario'], 'contato_ok' => (bool) (int) $r['contato_ok'],
            ] : null,
        ];
    };
    if ($acao === 'opiniao_salvar') {
        $erro = mcp_opiniao_salvar($ctx, $corpo);
        if ($erro !== null) {
            mcp_falhar(422, $erro[1], ['campo' => $erro[0]]);
        }
        // Sem registro de evento: a hora de cada resposta, guardada à parte, desfaria o anonimato da opinião.
        mcp_json(['mensagem' => 'Obrigado pela sua opinião! Ela ajuda a melhorar o ponto para todo mundo.'] + $publico(mcp_opiniao_contexto($token)));
    }
    mcp_json($publico($ctx));
}

// ----------------------------------------------------------------------------- aluno: não receber mais
if ($c === null && ($acao === 'sair_ler' || $acao === 'sair_confirmar')) {
    $hashEmail = substr((string) $token['pessoa'], 1);
    $hashWhatsapp = (string) $token['extra'];
    $bloqueado = static function () use ($hashEmail): bool {
        $stmt = mcp_db()->prepare("SELECT 1 FROM mcp_avisos_bloqueios WHERE canal = 'email' AND destino_hash = ?");
        $stmt->execute([$hashEmail]);
        return (bool) $stmt->fetchColumn();
    };
    if ($acao === 'sair_confirmar') {
        mcp_avisos_bloquear_hash('email', $hashEmail, 'pagina');
        if (preg_match('/^[a-f0-9]{32}$/', $hashWhatsapp)) {
            mcp_avisos_bloquear_hash('whatsapp', $hashWhatsapp, 'pagina');
        }
        mcp_registrar(null, 'aviso_sair', 'aluno');
        mcp_json(['ok' => true, 'bloqueado' => true, 'mensagem' => 'Pronto. Você não vai mais receber os lembretes das aulas nem os avisos do ponto. Os e-mails da sua matrícula e o comprovante de comparecimento continuam chegando.']);
    }
    mcp_json(['ok' => true, 'bloqueado' => $bloqueado()]);
}

mcp_falhar(404, 'Link inválido. Confira se ele veio inteiro, ou peça um novo à secretaria.', ['motivo' => 'invalido']);
