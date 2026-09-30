<?php
/**
 * Páginas pessoais dos avisos do ponto (lib/avisos.php e lib/comunicacao.php):
 *   GET  ?r=<id>.<destino>.<assinatura>          clique num link de aviso: conta o clique e leva ao destino;
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
        // Equipe contratada: lembrete só em dia útil (mcp_avisos_recebe_vespera).
        'dias_opcoes' => mcp_ponto_voluntario($c) ? MCP_AVISOS_DIAS : array_intersect_key(MCP_AVISOS_DIAS, array_flip(MCP_AVISOS_DIAS_UTEIS)),
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
        'dias' => mcp_ponto_voluntario($c) ? (array) ($corpo['dias'] ?? []) : array_intersect(mcp_avisos_dias($corpo['dias'] ?? []), MCP_AVISOS_DIAS_UTEIS),
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
        mcp_registrar(null, 'opiniao', '#' . $ctx['campanha']['id'] . ' · ' . $ctx['publico']);
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
