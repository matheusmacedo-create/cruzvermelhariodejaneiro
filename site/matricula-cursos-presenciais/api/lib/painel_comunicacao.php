<?php
/**
 * Portal da secretaria (api/painel.php), 30/09/2026: seção Comunicação e os acréscimos do ponto.
 * Só o painel.php carrega este arquivo (lib.php não), porque usa as funções de página dele
 * (pn_pagina, pn_e, pn_data, pn_redirecionar, pn_login, pn_colaborador).
 *
 * Comunicação (?v=comunicacao&aba=…):
 *  - Visão geral: canais (e-mail e WhatsApp), lembretes automáticos (liga e desliga), a implantação em
 *    três fases com a data do lançamento e o estado dos contatos dos colaboradores;
 *  - Comunicados (?aba=comunicados e ?v=comunicado&id=): escrever, ver a prévia do e-mail, do WhatsApp e
 *    do aviso no ponto, mandar um teste para si, agendar, mandar agora, interromper;
 *  - Fila do WhatsApp: no modo manual, cada mensagem com o botão que abre o WhatsApp com o texto pronto;
 *  - Envios: o registro de tudo o que saiu (ou não), com "tentar de novo" nas falhas;
 *  - Resultados: adesão, registros por dia, horas, saídas esquecidas, efeito dos lembretes e opinião,
 *    por período, com comparação e planilha por pessoa.
 * Ponto: saídas informadas para conferir (aceitar ou recusar), importar a planilha de colaboradores e o
 * cartão "Lembretes e contato" na ficha de cada um.
 */
declare(strict_types=1);

const PC_ABAS = ['geral' => 'Visão geral', 'comunicados' => 'Comunicados', 'fila' => 'Fila do WhatsApp', 'envios' => 'Envios', 'resultados' => 'Resultados'];
/** Ações dos formulários desta parte do portal (cada uma com o token do formulário, como as outras). */
const PC_ACOES = [
    'ajustes_salvar', 'implantacao', 'links_enviar', 'campanha_salvar', 'campanha_teste', 'campanha_agendar', 'campanha_enviar',
    'campanha_cancelar', 'campanha_apagar', 'fila_enviada', 'fila_pular', 'aviso_repetir', 'aviso_parar', 'saida_aceitar', 'saida_recusar',
    'col_importar', 'col_avisos', 'col_link', 'col_links_novos',
];
const PC_AVISOS = [
    'aj_ok' => ['Ajustes salvos.', 'ok'],
    'imp_ok' => ['Comunicados da implantação preparados como rascunho. Confira cada um, mande um teste para você e agende.', 'ok'],
    'imp_data' => ['Data do lançamento salva. Os rascunhos ganharam as datas novas.', 'ok'],
    'imp_agend' => ['Data do lançamento salva. Os rascunhos ganharam as datas novas; os comunicados já agendados mantêm a data deles: confira cada um.', 'ok'],
    'lk_ok' => ['Os links de lembretes foram para a fila. Saem por e-mail das 8h às 20h.', 'ok'],
    'lk_nada' => ['Ninguém para receber: todos os ativos com e-mail já escolheram os lembretes ou já receberam o link hoje.', 'ok'],
    'cp_ok' => ['Comunicado salvo.', 'ok'],
    'cp_teste' => ['Teste enviado. Confira a sua caixa de entrada (e o spam).', 'ok'],
    'cp_tfalha' => ['O teste não saiu. Veja o motivo em Envios.', 'erro'],
    'cp_agend' => ['Comunicado agendado. Ele sai sozinho na hora marcada (das 8h às 20h).', 'ok'],
    'cp_envio' => ['O comunicado começou a sair. O que faltar sai nos próximos minutos.', 'ok'],
    'cp_janela' => ['Fora da janela das 8h às 20h: o comunicado fica agendado e começa a sair às 8h.', 'ok'],
    'cp_canc' => ['Pronto. O comunicado voltou a rascunho (ou foi interrompido, se já estava saindo).', 'ok'],
    'cp_apag' => ['Rascunho apagado.', 'ok'],
    'fl_ok' => ['Marcado como enviado.', 'ok'],
    'fl_pulo' => ['Mensagem tirada da fila.', 'ok'],
    'av_rep' => ['A mensagem voltou para a fila e sai na próxima rodada (das 8h às 20h).', 'ok'],
    'av_tarde' => ['Esta mensagem não pode mais sair: era para a véspera (ou o prazo dela acabou).', 'erro'],
    'av_parou' => ['Pedido registrado: essa pessoa não recebe mais por esse canal, e o que estava na fila foi cancelado.', 'ok'],
    'cl_novos' => ['Pronto: os links já enviados a essa pessoa deixaram de valer. Os próximos avisos levam links novos.', 'ok'],
    'sd_ok' => ['Saída aceita: as horas desse dia já contam.', 'ok'],
    'sd_rec' => ['Saída informada recusada. O registro continua como saída esquecida, para corrigir à mão.', 'ok'],
    'ci_ok' => ['Colaboradores importados.', 'ok'],
    'ca_ok' => ['Lembretes e contato salvos.', 'ok'],
    'cl_ok' => ['Link das preferências enviado por e-mail.', 'ok'],
    'cl_fila' => ['Fora da janela das 8h às 20h: o link sai por e-mail às 8h.', 'ok'],
];

// ----------------------------------------------------------------------------- utilidades de página
/** Formulário POST com a ação, o id e o token (amarrado a quem está logado, à ação e ao id). */
function pc_form(string $usuario, string $acao, int $id, string $conteudo, string $atributos = ''): string
{
    return '<form method="post" action="painel.php"' . $atributos . '><input type="hidden" name="acao" value="' . pn_e($acao) . '"><input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="t" value="' . pn_e(mcp_painel_csrf($usuario, $acao, $id)) . '">' . $conteudo . '</form>';
}

function pc_botao(string $rotulo, string $classe = 'btn-outline', string $extra = ''): string
{
    return '<button class="btn ' . $classe . ' pc-mini" type="submit"' . $extra . '>' . pn_e($rotulo) . '</button>';
}

function pc_selo(string $classe, string $texto): string
{
    return '<span class="selo ' . $classe . '">' . pn_e($texto) . '</span>';
}

function pc_selo_aviso(string $status): string
{
    $classe = ['enviado' => 'ok', 'falhou' => 'erro', 'pendente' => 'alerta', 'enviando' => 'alerta', 'manual' => 'alerta'][$status] ?? 'neutro';
    return pc_selo($classe, MCP_AVISOS_STATUS[$status] ?? $status);
}

function pc_selo_campanha(array $c): string
{
    $classe = ['rascunho' => 'neutro', 'agendada' => 'alerta', 'enviando' => 'alerta', 'enviada' => 'ok', 'falhou' => 'erro', 'cancelada' => 'neutro'][$c['status']] ?? 'neutro';
    return pc_selo($classe, MCP_CAMPANHA_STATUS[$c['status']] ?? (string) $c['status']);
}

/** "1 clique", "3 cliques". */
function pc_plural(int $n, string $um, string $varios): string
{
    return $n . ' ' . ($n === 1 ? $um : $varios);
}

function pc_pct(?int $p): string
{
    return $p === null ? '—' : $p . '%';
}

/** "▲ 12%" / "▼ 3%" contra o período anterior; a cor diz se a mudança é boa (e há sempre o texto, nunca só cor). */
function pc_variacao(float $atual, float $anterior, bool $menorMelhor = false): string
{
    if ($anterior <= 0) {
        return $atual > 0 ? '<small class="pc-var">novo neste período</small>' : '<small class="pc-var">sem base para comparar</small>';
    }
    $mudanca = (int) round(100 * ($atual - $anterior) / $anterior);
    if ($mudanca === 0) {
        return '<small class="pc-var">igual ao período anterior</small>';
    }
    $bom = $menorMelhor ? $mudanca < 0 : $mudanca > 0;
    return '<small class="pc-var ' . ($bom ? 'bom' : 'ruim') . '">' . ($mudanca > 0 ? '▲ ' : '▼ ') . abs($mudanca) . '% <span>contra o período anterior</span></small>';
}

/** Endereço de e-mail ou WhatsApp só com o bastante para reconhecer. */
function pc_destino(string $canal, string $destino): string
{
    return $canal === 'whatsapp' ? mcp_whatsapp_mascarado($destino) : mcp_email_mascarado($destino);
}

function pc_abas(string $aba): string
{
    $fila = mcp_avisos_fila_manual_contar();
    $html = '';
    foreach (PC_ABAS as $chave => $rotulo) {
        $n = $chave === 'fila' ? $fila : 0;
        $html .= '<a class="pilula' . ($chave === $aba ? ' ativo' : '') . '" href="painel.php?v=comunicacao' . ($chave !== 'geral' ? '&amp;aba=' . $chave : '') . '">' . pn_e($rotulo)
            . ($n > 0 ? ' <span class="n">' . $n . '</span>' : '') . '</a>';
    }
    return '<div class="filtros">' . $html . '</div>';
}

function pc_topo(string $titulo, string $explica, string $aba, string $aviso, string $classe, string $acoes = ''): string
{
    return '<div class="cabeca"><div><p class="eyebrow">Comunicação do ponto</p><h1>' . pn_e($titulo) . '</h1><p class="nota">' . $explica . '</p></div>'
        . ($acoes !== '' ? '<div class="acoes" style="margin-top:0">' . $acoes . '</div>' : '') . '</div>'
        . pc_abas($aba) . ($aviso !== '' ? '<div class="aviso ' . pn_e($classe) . '">' . pn_e($aviso) . '</div>' : '');
}

/** Quantas pessoas recebem o lembrete da véspera amanhã (e por quais canais). */
function pc_previa_vespera(?int $agora = null): array
{
    $agora ??= time();
    $amanha = mcp_avisos_dia_mais(mcp_ponto_hoje($agora), 1);
    $dia = mcp_avisos_dia_chave($amanha);
    $r = ['data' => $amanha, 'pessoas' => 0, 'sem_canal' => 0];
    foreach (mcp_colaboradores_listar() as $c) {
        if ((int) $c['ativo'] && in_array($dia, mcp_avisos_dias($c['aviso_dias']), true)) {
            mcp_avisos_canais($c, 'vespera') ? $r['pessoas']++ : $r['sem_canal']++;
        }
    }
    return $r;
}

/** Registros de voluntário de ontem sem saída (os que recebem o aviso das 9h). */
function pc_previa_saida(?int $agora = null): int
{
    $hoje = mcp_ponto_hoje($agora);
    [$de, $ate] = mcp_ponto_periodo_utc(mcp_avisos_dia_mais($hoje, -1), $hoje);
    $stmt = mcp_db()->prepare('SELECT COUNT(*) FROM mcp_ponto p JOIN mcp_colaboradores c ON c.id = p.colaborador_id
        WHERE p.voluntario = 1 AND p.saida IS NULL AND p.saida_informada IS NULL AND p.entrada >= ? AND p.entrada < ? AND c.ativo = 1 AND c.aviso_saida = 1');
    $stmt->execute([$de, $ate]);
    return (int) $stmt->fetchColumn();
}

// ----------------------------------------------------------------------------- Comunicação: visão geral
function pc_comunicacao(string $usuario, string $aviso = '', string $classe = 'ok'): never
{
    $aba = mcp_texto($_GET['aba'] ?? '', 20);
    match ($aba) {
        'comunicados' => pc_comunicados($usuario, $aviso, $classe),
        'fila' => pc_fila($usuario, $aviso, $classe),
        'envios' => pc_envios($usuario, $aviso, $classe),
        'resultados' => pc_resultados($usuario, $aviso, $classe),
        default => pc_geral($usuario, $aviso, $classe),
    };
}

function pc_geral(string $usuario, string $aviso, string $classe): never
{
    $agora = time();
    $semana = gmdate('Y-m-d H:i:s', $agora - 7 * 86400);
    $contar = static function (string $sql, array $params): int {
        $stmt = mcp_db()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    };
    $n = [
        'enviados' => $contar("SELECT COUNT(*) FROM mcp_avisos WHERE status = 'enviado' AND enviado_em >= ?", [$semana]),
        'falhas' => $contar("SELECT COUNT(*) FROM mcp_avisos WHERE status = 'falhou' AND criado_em >= ?", [gmdate('Y-m-d H:i:s', $agora - 14 * 86400)]),
        'cliques' => $contar('SELECT COUNT(*) FROM mcp_avisos WHERE criado_em >= ? AND clicado_em >= ?', [gmdate('Y-m-d H:i:s', $agora - 67 * 86400), $semana]),
    ];
    $fila = mcp_avisos_fila_manual_contar($agora);
    $modo = mcp_whatsapp_modo();
    $numeros = '<div class="numeros">'
        . '<a class="numero" href="painel.php?v=comunicacao&amp;aba=envios"><b>' . (int) $n['enviados'] . '</b><span>Mensagens enviadas</span><small>nos últimos 7 dias</small></a>'
        . '<a class="numero' . ($fila > 0 ? ' alerta' : '') . '" href="painel.php?v=comunicacao&amp;aba=fila"><b>' . $fila . '</b><span>Na fila do WhatsApp</span><small>para abrir e mandar hoje</small></a>'
        . '<a class="numero' . ((int) $n['falhas'] > 0 ? ' alerta' : '') . '" href="painel.php?v=comunicacao&amp;aba=envios&amp;status=falhou"><b>' . (int) $n['falhas'] . '</b><span>Falhas</span><small>nos últimos 7 dias</small></a>'
        . '<a class="numero" href="painel.php?v=comunicacao&amp;aba=resultados"><b>' . (int) $n['cliques'] . '</b><span>Cliques nos links</span><small>nos últimos 7 dias</small></a>'
        . '</div>';

    // Canais.
    $resend = (string) mcp_cfg('RESEND_API_KEY', '') !== '';
    $remetente = mcp_email_endereco(mcp_email_nome_oficial((string) (mcp_cfg('EMAIL_REMETENTE_PONTO', '') ?: mcp_cfg('EMAIL_REMETENTE', MCP_NOME_FILIAL . ' <matricula@cruzvermelhariodejaneiro.org>'))));
    $explicaModo = match ($modo) {
        'cloud' => 'As mensagens saem sozinhas pela API oficial do WhatsApp, com os modelos aprovados pela Meta: ' . pn_e(implode(', ', array_keys(MCP_WHATSAPP_MODELOS))) . '. Quem responde PARAR deixa de receber.',
        'evolution' => 'As mensagens saem sozinhas pelo WhatsApp do Palácio Virtual (o mesmo número dos avisos da equipe), uma a cada 8 segundos, até '
            . MCP_WHATSAPP_EVOLUTION_POR_RODADA . ' a cada 15 minutos, para não atrapalhar os avisos do Palácio. A Evolution não é a API oficial do WhatsApp: '
            . 'o número pode ser bloqueado se muita gente denunciar as mensagens, e aí param também os avisos do Palácio. Mande só a quem autorizou. '
            . 'Quem responde à mensagem cai no robô do Palácio, e não aqui: se alguém pedir para parar por lá, registre em Envios (<b>Pediu para parar</b>).',
        'webhook' => 'As mensagens vão para o cenário do Make, que manda pelo WhatsApp. O retorno do Make diz se saiu.',
        default => 'Hoje o WhatsApp é manual: cada mensagem entra na <a href="painel.php?v=comunicacao&amp;aba=fila">Fila do WhatsApp</a> e você manda com um toque, pelo WhatsApp da instituição. '
            . 'Por isso os lembretes também vão por e-mail, para ninguém ficar sem aviso se a fila atrasar. Para o envio automático, fale com o suporte técnico.',
    };
    // O WhatsApp do Palácio (Evolution) está configurado, mas só vale depois de a instituição aceitar o risco.
    $evolucaoPendente = (string) mcp_cfg('WHATSAPP_EVOLUTION_URL', '') !== '' && !mcp_ajuste_ligado('evolution_riscos') && mcp_whatsapp_modo() !== 'cloud';
    $rotinaEm = mcp_ajuste('rotina_em');
    $rotinaParada = $rotinaEm !== '' && (int) strtotime($rotinaEm . ' UTC') < $agora - 45 * 60;
    $canais = '<div class="cartao"><h2>Canais</h2><ul class="contas">'
        . '<li><span>E-mail</span><b>' . ($resend ? 'Resend' : 'envio simples pelo servidor do site (pode cair no spam)') . '</b></li>'
        . '<li><span>Remetente</span><b>' . pn_e($remetente) . '</b></li>'
        . '<li><span>WhatsApp</span><b>' . (mcp_whatsapp_ativo() ? 'ligado · ' : 'desligado · ') . pn_e(mcp_whatsapp_modo_nome($modo)) . '</b></li>'
        . '</ul><p class="nota">' . $explicaModo . '</p>'
        . '<p class="nota">Nada sai fora da janela das 8h às 20h (Brasília), nem véspera de feriado ou de dia sem expediente. WhatsApp só para quem autorizou, com a data registrada na ficha.</p>'
        . ($rotinaParada ? '<p class="nota" role="alert" style="color:var(--red)"><b>A rotina parou</b>: a última rodada foi ' . pn_e(pn_data($rotinaEm)) . '. Sem ela, nada sai (nem os comprovantes das aulas). Avise o suporte técnico.</p>' : '')
        . ($evolucaoPendente ? pc_form($usuario, 'ajustes_salvar', 0, '<input type="hidden" name="so_evolucao" value="1">'
            . '<p class="nota" style="margin-bottom:6px"><b>O WhatsApp do Palácio Virtual está configurado, mas desligado.</b> Ele não é a API oficial do WhatsApp: se muita gente denunciar as mensagens, '
            . 'o número pode ser bloqueado, e com ele param os avisos do Palácio. Leia os riscos em docs/ponto-comunicacao.md antes de ligar.</p>'
            . '<label class="check pc-check"><input type="checkbox" name="evolution_riscos" value="1"><span><b>A instituição decidiu usar o WhatsApp do Palácio sabendo dos riscos</b>'
            . '<small>Fica registrado quem marcou e quando. Mande só a quem autorizou; os pedidos de parar que chegarem ao robô do Palácio são registrados em Envios.</small></span></label>'
            . '<div class="acoes">' . pc_botao('Registrar a decisão', 'btn-outline') . '</div>') : '')
        . '</div>';

    // Lembretes automáticos.
    $vespera = pc_previa_vespera($agora);
    $saidas = pc_previa_saida($agora);
    $aulaPreparada = mcp_ajuste('aula_preparada');
    $check = static fn(string $nome, string $rotulo, string $explica): string => '<label class="check pc-check"><input type="checkbox" name="' . $nome . '" value="1"'
        . (mcp_ajuste_ligado($nome) ? ' checked' : '') . '><span><b>' . pn_e($rotulo) . '</b><small>' . $explica . '</small></span></label>';
    $lembretes = '<div class="cartao"><h2>Lembretes automáticos</h2>'
        . pc_form($usuario, 'ajustes_salvar', 0,
            $check('lembrete_vespera', 'Véspera, às 18h: "amanhã, registre a chegada e a saída"',
                'Para quem escolheu os dias em que vem à sede. Amanhã (' . pn_e(mcp_avisos_dia_texto($vespera['data'])) . '): <b>' . $vespera['pessoas'] . '</b> ' . ($vespera['pessoas'] === 1 ? 'pessoa' : 'pessoas')
                . ($vespera['sem_canal'] > 0 ? ', mais ' . $vespera['sem_canal'] . ' sem e-mail nem WhatsApp' : '') . '.')
            . $check('lembrete_saida', 'Saída não registrada, às 9h', 'Para o voluntário que entrou ontem e não registrou a saída, com o link para informar o horário. Hoje: <b>' . $saidas . '</b>.')
            . $check('lembrete_aula', 'Aula de amanhã, às 18h (alunos)', 'Usa a lista de aulas da plataforma da escola. '
                . ($aulaPreparada !== '' ? 'Última lista preparada: aulas de ' . pn_e(mcp_escola_data($aulaPreparada)) . '.' : 'Se depois das 8h continuar sem lista preparada, avise o suporte técnico.'))
            . $check('aula_whatsapp', 'Lembrete da aula também pelo WhatsApp', 'Vale só para os alunos que autorizaram o WhatsApp na escola (a escola informa quem). Os outros recebem só por e-mail.')
            . $check('whatsapp_ativo', 'Usar o WhatsApp', 'Se desmarcar, nada vai pelo WhatsApp (nem para a fila) e tudo segue por e-mail. O que estava esperando volta a sair se religar dentro do prazo.')
            . '<label style="margin-top:12px">Dias sem expediente na sede (além dos feriados)<input name="dias_fechados" placeholder="24/12, 31/12" value="'
                . pn_e(implode(', ', array_map(static fn(string $d): string => mcp_escola_data($d), mcp_avisos_dias_fechados()))) . '"></label>'
            . '<p class="nota" style="margin-top:4px">Na véspera desses dias e dos feriados (nacionais, do estado e da cidade do Rio), o lembrete não sai.</p>'
            . '<div class="acoes">' . pc_botao('Salvar os ajustes', 'btn-red') . '</div>')
        . '</div>';

    // Implantação em três fases.
    $lancamento = mcp_ajuste('lancamento');
    $campanhas = mcp_campanhas_listar();
    $fases = '';
    foreach (['antes', 'durante', 'depois'] as $fase) {
        $itens = array_filter($campanhas, static fn(array $c): bool => $c['fase'] === $fase);
        $linhas = '';
        foreach ($itens as $c) {
            $num = mcp_campanha_numeros((int) $c['id']);
            $linhas .= '<li><div><a href="painel.php?v=comunicado&amp;id=' . (int) $c['id'] . '">' . pn_e((string) $c['nome']) . '</a><small>'
                . pn_e(MCP_CAMPANHA_PUBLICOS[$c['publico']] ?? $c['publico']) . ($c['agendada_para'] ? ' · ' . pn_e(pn_data((string) $c['agendada_para'])) : '')
                . ($num['total'] > 0 ? ' · ' . $num['enviado'] . ' de ' . $num['total'] . ' enviadas' . ($num['manual'] > 0 ? ' · ' . $num['manual'] . ' na fila do WhatsApp' : '')
                    . ($num['opinioes'] > 0 ? ' · ' . pc_plural($num['opinioes'], 'opinião', 'opiniões') : '') : '') . '</small></div>'
                . pc_selo_campanha($c) . '</li>';
        }
        $fases .= '<li class="pc-fase"><b>' . pn_e(MCP_CAMPANHA_FASES[$fase]) . '</b>' . ($linhas !== '' ? '<ul class="lista-curta">' . $linhas . '</ul>' : '<p class="nota" style="margin:4px 0 0">Nenhum comunicado.</p>') . '</li>';
    }
    $sugestao = $lancamento !== '' ? $lancamento : mcp_avisos_dia_mais(mcp_ponto_hoje($agora), 7);
    $implantacao = '<div class="cartao" id="implantacao"><h2>Implantação em três fases</h2>'
        . '<p class="nota" style="margin-top:0">Antes do lançamento (5 dias antes), no dia (8h30) e depois de 2 semanas, com a opinião de todos. '
        . ($lancamento !== '' ? 'Lançamento: <b>' . pn_e(mcp_comunicado_data($lancamento)) . '</b>.' : 'Escolha a data do lançamento para preparar os comunicados.') . '</p>'
        . '<ol class="pc-fases">' . $fases . '</ol>'
        . pc_form($usuario, 'implantacao', 0, '<div class="mini" style="max-width:none;grid-template-columns:auto auto;align-items:end;justify-content:start">'
            . '<label>Data do lançamento<input type="date" name="data" required value="' . pn_e($sugestao) . '"></label>'
            . pc_botao($campanhas ? 'Salvar a data do lançamento' : 'Preparar os comunicados', 'btn-red') . '</div>')
        . '<p class="nota">Os comunicados nascem como rascunho, com o texto sugerido. Nada sai antes de você agendar.</p></div>';

    // Colaboradores e contatos.
    $ativos = array_filter(mcp_colaboradores_listar(), static fn(array $c): bool => (bool) (int) $c['ativo']);
    $comEmail = $comWhatsapp = $comDias = $escolheram = 0;
    $semContato = [];
    foreach ($ativos as $c) {
        $email = filter_var((string) $c['email'], FILTER_VALIDATE_EMAIL) !== false;
        $whats = (int) $c['aviso_whatsapp'] === 1 && mcp_whatsapp_numero((string) $c['telefone']) !== null;
        $comEmail += $email ? 1 : 0;
        $comWhatsapp += $whats ? 1 : 0;
        $comDias += mcp_avisos_dias($c['aviso_dias']) ? 1 : 0;
        $escolheram += $c['aviso_atualizado_em'] !== null ? 1 : 0;
        if (!$email && !$whats) {
            $semContato[] = '<a href="painel.php?v=colaborador&amp;id=' . (int) $c['id'] . '#lembretes">' . pn_e((string) $c['nome']) . '</a>';
        }
    }
    $contatos = '<div class="cartao"><h2>Colaboradores e contatos</h2><ul class="contas">'
        . '<li><span>Ativos</span><b>' . count($ativos) . '</b></li>'
        . '<li><span>Com e-mail</span><b>' . $comEmail . '</b></li>'
        . '<li><span>WhatsApp autorizado</span><b>' . $comWhatsapp . '</b></li>'
        . '<li><span>Escolheram os dias da véspera</span><b>' . $comDias . '</b></li>'
        . '<li><span>Com preferências definidas</span><b>' . $escolheram . '</b></li></ul>'
        . ($semContato ? '<p class="nota"><b>Sem e-mail nem WhatsApp</b> (não recebem nada): ' . implode(', ', $semContato) . '.</p>' : '')
        . pc_form($usuario, 'links_enviar', 0, '<div class="acoes">' . pc_botao('Mandar o link de lembretes a quem ainda não escolheu') . '</div>',
            ' onsubmit="return confirm(\'Mandar por e-mail o link das preferências a todos os ativos com e-mail que ainda não escolheram?\')"')
        . '<p class="nota">Cada pessoa também tem o link na ficha, em "Lembretes e contato". <a href="painel.php?v=importar">Importar a planilha de colaboradores →</a></p></div>';

    $corpo = pc_topo('Visão geral', 'Lembretes de entrada e saída, comunicados da implantação e o que as pessoas acharam. Tudo começa desligado: nada sai sem você ligar ou agendar.', 'geral', $aviso, $classe,
            '<a class="btn btn-red" href="painel.php?v=comunicado&amp;novo=1">Novo comunicado</a>')
        . $numeros . '<div class="grade-inicio">' . $lembretes . $canais . '</div>'
        . '<div class="grade-inicio" style="margin-top:18px">' . $implantacao . $contatos . '</div>';
    pn_pagina('Comunicação', $corpo, $usuario, true, 'comunicacao');
}

// ----------------------------------------------------------------------------- Comunicação: comunicados
function pc_comunicados(string $usuario, string $aviso, string $classe): never
{
    $linhas = '';
    foreach (mcp_campanhas_listar() as $c) {
        $num = mcp_campanha_numeros((int) $c['id']);
        $canais = implode(', ', array_map(static fn(string $k): string => MCP_CAMPANHA_CANAIS[$k], mcp_campanha_canais($c)));
        $linhas .= '<tr><td class="aluno"><a class="nome" href="painel.php?v=comunicado&amp;id=' . (int) $c['id'] . '">' . pn_e((string) $c['nome']) . '</a><small>'
            . pn_e(MCP_CAMPANHA_FASES[$c['fase']] ?? $c['fase']) . ' · ' . pn_e(MCP_CAMPANHA_PUBLICOS[$c['publico']] ?? $c['publico']) . '</small></td>'
            . '<td data-rotulo="Canais">' . pn_e($canais) . '</td>'
            . '<td data-rotulo="Quando">' . ($c['agendada_para'] ? pn_e(pn_data((string) ($c['iniciada_em'] ?? $c['agendada_para']))) : '<span style="color:var(--muted)">sem data</span>') . '</td>'
            . '<td data-rotulo="Situação">' . pc_selo_campanha($c) . '</td>'
            . '<td data-rotulo="Resultado">' . ($num['total'] > 0 ? $num['enviado'] . ' de ' . $num['total'] . ' enviadas<small>' . pc_plural($num['clicados'], 'clique', 'cliques')
                . ($num['manual'] ? ' · ' . $num['manual'] . ' na fila do WhatsApp' : '') . ($num['opinioes'] ? ' · ' . pc_plural($num['opinioes'], 'opinião', 'opiniões') : '') . '</small>' : '—') . '</td>'
            . '<td class="abrir"><a class="btn btn-outline pc-mini" href="painel.php?v=comunicado&amp;id=' . (int) $c['id'] . '">Abrir</a></td></tr>';
    }
    $corpo = pc_topo('Comunicados', 'Os avisos para todos: antes do lançamento, no dia e depois de 2 semanas, e os avulsos. Cada um tem prévia, teste e agendamento.', 'comunicados', $aviso, $classe,
            '<a class="btn btn-red" href="painel.php?v=comunicado&amp;novo=1">Novo comunicado</a>')
        . '<div class="tabela rolagem"><table class="respostas"><thead><tr><th>Comunicado</th><th>Canais</th><th>Quando</th><th>Situação</th><th>Resultado</th><th class="abrir"></th></tr></thead><tbody>'
        . ($linhas !== '' ? $linhas : '<tr><td colspan="6" class="vazio">Nenhum comunicado ainda. Na <a href="painel.php?v=comunicacao#implantacao">Visão geral</a>, escolha a data do lançamento para preparar os três da implantação.</td></tr>')
        . '</tbody></table></div>';
    pn_pagina('Comunicados', $corpo, $usuario, true, 'comunicacao');
}

/** Campo de texto do formulário do comunicado, com o destaque de erro. */
function pc_campo(string $nome, string $rotulo, string $valor, string $campoErro, string $extra = '', string $ajuda = ''): string
{
    return '<div' . ($campoErro === $nome ? ' class="campo-erro"' : '') . '><label for="cp-' . $nome . '">' . pn_e($rotulo) . '</label>'
        . '<input id="cp-' . $nome . '" name="' . $nome . '" value="' . pn_e($valor) . '"' . $extra . '>' . ($ajuda !== '' ? '<p class="nota" style="margin-top:4px">' . $ajuda . '</p>' : '') . '</div>';
}

function pc_select(string $nome, string $rotulo, array $opcoes, string $atual, string $campoErro): string
{
    $html = '';
    foreach ($opcoes as $k => $v) {
        $html .= '<option value="' . pn_e((string) $k) . '"' . ((string) $k === $atual ? ' selected' : '') . '>' . pn_e($v) . '</option>';
    }
    return '<div' . ($campoErro === $nome ? ' class="campo-erro"' : '') . '><label for="cp-' . $nome . '">' . pn_e($rotulo) . '</label><select class="campo" id="cp-' . $nome . '" name="' . $nome . '">' . $html . '</select></div>';
}

/**
 * Um comunicado: formulário (rascunho e agendado), prévia do e-mail, do WhatsApp e do aviso no ponto,
 * público, teste e agendamento; depois de sair, o resultado e quem recebeu.
 */
function pc_comunicado(string $usuario, ?int $id, string $aviso = '', string $classe = 'ok', ?array $form = null, string $campoErro = ''): never
{
    $c = $id !== null ? mcp_campanha($id) : null;
    if ($id !== null && !$c) {
        pn_redirecionar('v=comunicacao&aba=comunicados');
    }
    $editavel = $c === null || in_array($c['status'], ['rascunho', 'agendada'], true);
    $v = $form ?? ($c ?? ['nome' => '', 'fase' => 'livre', 'publico' => 'colaboradores', 'canais' => 'email', 'assunto' => '', 'titulo' => 'Olá, {primeiro_nome}!', 'mensagem' => '',
        'botao' => 'ponto', 'botao_rotulo' => '', 'whatsapp' => '', 'aviso_ponto' => '', 'aviso_dias' => 14]);
    $canaisAtuais = is_array($v['canais'] ?? null) ? $v['canais'] : explode(',', (string) ($v['canais'] ?? ''));
    $idForm = $c ? (int) $c['id'] : 0;
    $agora = time();

    $campos = '<div class="form-grade">'
        . pc_campo('nome', 'Nome do comunicado (só a secretaria vê)', (string) $v['nome'], $campoErro, ' required maxlength="120"')
        . pc_select('fase', 'Fase', MCP_CAMPANHA_FASES, (string) $v['fase'], $campoErro)
        . pc_select('publico', 'Para quem', MCP_CAMPANHA_PUBLICOS, (string) $v['publico'], $campoErro)
        . '<div' . ($campoErro === 'canais' ? ' class="campo-erro"' : '') . '><label>Canais</label><div class="pc-canais">'
        . implode('', array_map(static fn(string $k, string $r): string => '<label class="check"><input type="checkbox" name="canais[]" value="' . $k . '"' . (in_array($k, $canaisAtuais, true) ? ' checked' : '') . '> ' . pn_e($r) . '</label>',
            array_keys(MCP_CAMPANHA_CANAIS), MCP_CAMPANHA_CANAIS)) . '</div></div>'
        . '</div>'
        . '<h2 style="margin:22px 0 4px">E-mail</h2>'
        . pc_campo('assunto', 'Assunto', (string) $v['assunto'], $campoErro, ' maxlength="160"')
        . pc_campo('titulo', 'Título grande do e-mail', (string) $v['titulo'], $campoErro, ' maxlength="120"')
        . '<div' . ($campoErro === 'mensagem' ? ' class="campo-erro"' : '') . '><label for="cp-mensagem">Mensagem</label><textarea id="cp-mensagem" name="mensagem" maxlength="5000" style="min-height:260px">' . pn_e((string) $v['mensagem']) . '</textarea>'
        . '<p class="nota" style="margin-top:4px">Linha em branco separa parágrafos. <b>*assim*</b> fica em negrito. Linhas começando com "- " viram lista; com "1. ", passos numerados. '
        . 'Campos: ' . implode(', ', array_map(static fn(string $k, string $d): string => '<code title="' . pn_e($d) . '">{' . $k . '}</code>', array_keys(MCP_COMUNICADO_CAMPOS), MCP_COMUNICADO_CAMPOS)) . '.</p></div>'
        . '<div class="form-grade">' . pc_select('botao', 'Botão', MCP_CAMPANHA_BOTOES, (string) $v['botao'], $campoErro)
        . pc_campo('botao_rotulo', 'Texto do botão (opcional)', (string) ($v['botao_rotulo'] ?? ''), $campoErro, ' maxlength="60"') . '</div>'
        . '<h2 style="margin:22px 0 4px">WhatsApp</h2>'
        . '<div' . ($campoErro === 'whatsapp' ? ' class="campo-erro"' : '') . '><label for="cp-whatsapp">Mensagem do WhatsApp (fila manual e Make)</label><textarea id="cp-whatsapp" name="whatsapp" maxlength="1500" style="min-height:140px">' . pn_e((string) ($v['whatsapp'] ?? '')) . '</textarea>'
        . '<p class="nota" style="margin-top:4px">Curta, com o essencial. <code>{link}</code> é o link do botão (se faltar, vai no fim). Na API oficial, sai o modelo aprovado da fase (' . pn_e(implode(', ', MCP_WHATSAPP_MODELO_FASE)) . '), não este texto.</p></div>'
        . '<h2 style="margin:22px 0 4px">Tela do ponto</h2>'
        . '<div class="form-grade">' . pc_campo('aviso_ponto', 'Aviso que aparece depois do CPF', (string) ($v['aviso_ponto'] ?? ''), $campoErro, ' maxlength="255"')
        . pc_campo('aviso_dias', 'Por quantos dias', (string) ($v['aviso_dias'] ?? 14), $campoErro, ' type="number" min="1" max="30"') . '</div>';
    $formulario = $editavel
        ? pc_form($usuario, 'campanha_salvar', $idForm, $campos . '<div class="acoes"><button class="btn btn-red" type="submit">' . ($c ? 'Salvar alterações' : 'Criar o rascunho') . '</button></div>')
        : '';

    // Prévia (com uma pessoa de exemplo: Maria da Silva, voluntária, ou empregada, conforme a escolha).
    $previa = '';
    $publico = '';
    if ($c) {
        $comoOutros = ($_GET['previa'] ?? '') === 'outros' && $c['publico'] !== 'alunos' && $c['publico'] !== 'voluntarios';
        $exemplo = mcp_comunicado_pessoa_exemplo($comoOutros ? ['publico' => 'outros'] + $c : $c);
        $msg = mcp_campanha_mensagem($c, $exemplo, 0, $agora, false, true);
        // Na prévia, o link de cada pessoa aparece como "[link pessoal]".
        $msg['whatsapp'] = (string) preg_replace('~https?://\S+/api/avisos\.php\?r=0\.[a-z]\.[a-f0-9]{16}~', '[link pessoal]', $msg['whatsapp']);
        $canais = mcp_campanha_canais($c);
        $contagem = mcp_campanha_contagem($c, $agora);
        $troca = $c['publico'] === 'colaboradores'
            ? '<p class="nota" style="margin:0 0 10px">Exemplo: ' . ($comoOutros ? 'empregada · <a href="painel.php?v=comunicado&amp;id=' . (int) $c['id'] . '">ver como voluntária</a>'
                : 'voluntária · <a href="painel.php?v=comunicado&amp;id=' . (int) $c['id'] . '&amp;previa=outros">ver como empregada</a>') . '</p>' : '';
        $previa = '<div class="cartao"><h2>Prévia</h2>' . $troca
            . (in_array('email', $canais, true) ? '<p class="pc-rotulo">E-mail · <b>' . pn_e($msg['assunto']) . '</b></p><iframe class="pc-previa" sandbox title="Prévia do e-mail" srcdoc="' . pn_e($msg['html']) . '"></iframe>' : '')
            . (in_array('whatsapp', $canais, true) && $msg['whatsapp'] !== '' ? '<p class="pc-rotulo">WhatsApp</p><div class="pc-zap"><div class="pc-balao">' . nl2br(preg_replace('/\*([^*\n]+)\*/u', '<b>$1</b>', pn_e($msg['whatsapp'])) ?? '', false) . '</div></div>' : '')
            . (in_array('ponto', $canais, true) && trim((string) $c['aviso_ponto']) !== '' ? '<p class="pc-rotulo">Tela do ponto, depois do CPF</p><p class="pc-ponto">' . pn_e((string) $c['aviso_ponto']) . '</p>' : '')
            . '</div>';
        $semNomes = $contagem['sem_contato_nomes'] ? ': ' . pn_e(implode(', ', array_slice($contagem['sem_contato_nomes'], 0, 12))) . (count($contagem['sem_contato_nomes']) > 12 ? '…' : '') : '';
        $publico = '<div class="cartao"><h2>Quem recebe</h2><ul class="contas">'
            . '<li><span>Pessoas</span><b>' . $contagem['pessoas'] . '</b></li>'
            . (in_array('email', $canais, true) ? '<li><span>Por e-mail</span><b>' . $contagem['email'] . '</b></li>' : '')
            . (in_array('whatsapp', $canais, true) ? '<li><span>Por WhatsApp' . (mcp_whatsapp_modo() === 'manual' ? ' (fila manual)' : '') . '</span><b>' . $contagem['whatsapp'] . '</b></li>' : '')
            . '</ul>' . ($contagem['sem_contato'] > 0 ? '<p class="nota">' . $contagem['sem_contato'] . ' sem e-mail nem WhatsApp autorizado' . $semNomes . '.</p>' : '')
            . (in_array('whatsapp', $canais, true) && !mcp_whatsapp_ativo() ? '<p class="nota">O WhatsApp está desligado na Visão geral: por enquanto, nada vai por ele.</p>' : '')
            . '</div>';
    }

    // Ações.
    $acoes = '';
    if ($c && $editavel) {
        $sugestao = $c['agendada_para'] ? mcp_data_brt((string) $c['agendada_para'], 'Y-m-d\TH:i') : mcp_data_brt(gmdate('Y-m-d H:i:s', $agora + 3600), 'Y-m-d\TH:00');
        $acoes = '<div class="cartao"><h2>Mandar</h2>'
            . pc_form($usuario, 'campanha_teste', (int) $c['id'], '<p class="nota" style="margin-top:0">O teste vai para <b>' . pn_e($usuario) . '</b>, com uma pessoa de exemplo.</p>'
                . (mcp_whatsapp_modo() !== 'manual' && in_array('whatsapp', mcp_campanha_canais($c), true) ? '<div class="mini"><label>Testar também no WhatsApp (opcional)<input name="whatsapp_teste" inputmode="tel" placeholder="(21) 99999-9999"></label></div>' : '')
                . '<div class="acoes" style="margin-top:8px">' . pc_botao('Mandar um teste para mim') . '</div>')
            . pc_form($usuario, 'campanha_agendar', (int) $c['id'], '<div class="mini" style="max-width:none;grid-template-columns:auto auto;align-items:end;justify-content:start;margin-top:16px">'
                . '<label>Agendar para<input type="datetime-local" name="quando" required value="' . pn_e($sugestao) . '"></label>' . pc_botao($c['status'] === 'agendada' ? 'Reagendar' : 'Agendar', 'btn-red') . '</div>')
            . '<div class="acoes">'
            . pc_form($usuario, 'campanha_enviar', (int) $c['id'], pc_botao('Mandar agora'), ' onsubmit="return confirm(\'Mandar este comunicado agora para ' . (int) ($contagem['pessoas'] ?? 0) . ' pessoas?\')"')
            . ($c['status'] === 'agendada' ? pc_form($usuario, 'campanha_cancelar', (int) $c['id'], pc_botao('Desagendar')) : '')
            . ($c['status'] === 'rascunho' ? pc_form($usuario, 'campanha_apagar', (int) $c['id'], pc_botao('Apagar o rascunho'), ' onsubmit="return confirm(\'Apagar este rascunho?\')"') : '')
            . '</div>'
            . ($c['status'] === 'agendada' ? '<p class="nota">Agendado para <b>' . pn_e(pn_data((string) $c['agendada_para'])) . '</b>. Sai sozinho (das 8h às 20h).</p>' : '')
            . '</div>';
    }

    // Depois de sair: resultado e quem recebeu.
    $resultado = '';
    if ($c && !$editavel) {
        $num = mcp_campanha_numeros((int) $c['id']);
        $stmt = mcp_db()->prepare("SELECT * FROM mcp_avisos WHERE campanha_id = ? AND tipo = 'campanha' ORDER BY nome, canal LIMIT 1000");
        $stmt->execute([(int) $c['id']]);
        $linhas = '';
        foreach ($stmt->fetchAll() as $a) {
            $linhas .= '<tr><td class="aluno"><b>' . pn_e((string) $a['nome']) . '</b><small>' . pn_e(pc_destino((string) $a['canal'], (string) $a['destino'])) . '</small></td>'
                . '<td data-rotulo="Canal">' . ($a['canal'] === 'whatsapp' ? 'WhatsApp' : 'E-mail') . '</td>'
                . '<td data-rotulo="Situação">' . pc_selo_aviso((string) $a['status']) . ($a['erro'] ? '<small>' . pn_e((string) $a['erro']) . '</small>' : '') . '</td>'
                . '<td data-rotulo="Enviado">' . ($a['enviado_em'] ? pn_e(pn_data((string) $a['enviado_em'])) : '—') . '</td>'
                . '<td data-rotulo="Clique">' . ($a['clicado_em'] ? pn_e(pn_data((string) $a['clicado_em'])) : '—') . '</td></tr>';
        }
        $resultado = '<div class="numeros">'
            . '<div class="numero"><b>' . $num['enviado'] . '</b><span>Enviadas</span><small>de ' . $num['total'] . ' mensagens</small></div>'
            . '<div class="numero"><b>' . pc_pct(mcp_metricas_pct($num['clicados'], $num['enviado'])) . '</b><span>Clicaram</span><small>' . pc_plural($num['clicados'], 'mensagem clicada', 'mensagens clicadas') . '</small></div>'
            . '<div class="numero' . ($num['falhou'] > 0 ? ' alerta' : '') . '"><b>' . $num['falhou'] . '</b><span>Falhas</span><small>' . $num['fila'] . ' na fila · ' . $num['manual'] . ' na fila do WhatsApp</small></div>'
            . '<div class="numero"><b>' . $num['opinioes'] . '</b><span>Opiniões</span><small>' . ($c['botao'] === 'opiniao' ? '<a href="painel.php?v=comunicacao&amp;aba=resultados#opiniao">ver o resultado</a>' : 'este comunicado não pede opinião') . '</small></div>'
            . '</div>'
            . ($c['status'] === 'enviando' ? '<div class="acoes" style="margin:0 0 16px">' . pc_form($usuario, 'campanha_cancelar', (int) $c['id'], pc_botao('Interromper o envio'),
                ' onsubmit="return confirm(\'Interromper? O que ainda não saiu fica cancelado.\')"') . '</div>' : '')
            . '<div class="tabela rolagem"><table class="respostas"><thead><tr><th>Pessoa</th><th>Canal</th><th>Situação</th><th>Enviado</th><th>Clique</th></tr></thead><tbody>'
            . ($linhas !== '' ? $linhas : '<tr><td colspan="5" class="vazio">Nenhuma mensagem (só aviso na tela do ponto, ou ninguém com contato).</td></tr>') . '</tbody></table></div>';
    }

    $topo = '<a class="voltar" href="painel.php?v=comunicacao&amp;aba=comunicados">← Voltar para os comunicados</a>'
        . ($aviso !== '' ? '<div class="aviso ' . pn_e($classe) . '">' . pn_e($aviso) . '</div>' : '');
    $titulo = $c ? (string) $c['nome'] : 'Novo comunicado';
    $cabeca = '<div class="cabeca"><div><p class="eyebrow">Comunicado' . ($c ? ' · ' . pn_e(MCP_CAMPANHA_FASES[$c['fase']] ?? $c['fase']) : '') . '</p><h1>' . pn_e($titulo) . '</h1>'
        . ($c ? '<p class="nota">' . pc_selo_campanha($c) . ($c['iniciada_em'] ? ' Começou a sair em ' . pn_e(pn_data((string) $c['iniciada_em'])) . '.' : '') . '</p>' : '<p class="nota">Escreva, salve como rascunho e veja a prévia antes de mandar.</p>') . '</div></div>';
    if ($editavel) {
        $corpo = $topo . $cabeca . '<div class="pc-editor"><div class="cartao">' . $formulario . '</div><div>' . $acoes . $previa . $publico . '</div></div>';
    } else {
        $corpo = $topo . $cabeca . $resultado . '<div class="pc-editor" style="margin-top:18px"><div>' . $previa . '</div><div>' . $publico . '</div></div>';
    }
    pn_pagina($titulo, $corpo, $usuario, true, 'comunicacao');
}

// ----------------------------------------------------------------------------- Comunicação: fila do WhatsApp
function pc_fila(string $usuario, string $aviso, string $classe): never
{
    $itens = mcp_avisos_fila_manual();
    $linhas = '';
    foreach ($itens as $a) {
        $id = (int) $a['id'];
        $linhas .= '<li class="pc-item"><div class="pc-item-topo"><div><b>' . pn_e((string) $a['nome']) . '</b><small>' . pn_e(MCP_AVISOS_TIPOS[$a['tipo']] ?? $a['tipo']) . ' · '
            . pn_e(mcp_whatsapp_mascarado((string) $a['destino_atual'])) . ' · desde ' . pn_e(pn_data((string) $a['criado_em'])) . '</small></div>'
            . '<div class="acoes" style="margin:0;gap:6px"><a class="btn btn-red pc-mini" href="' . pn_e($a['link_whatsapp']) . '" target="_blank" rel="noopener">Abrir no WhatsApp</a>'
            . pc_form($usuario, 'fila_enviada', $id, pc_botao('Enviei'))
            . pc_form($usuario, 'fila_pular', $id, pc_botao('Pular'), ' onsubmit="return confirm(\'Tirar esta mensagem da fila sem mandar?\')"') . '</div></div>'
            . '<details><summary>Ver o texto</summary><textarea readonly rows="5">' . pn_e((string) $a['texto_pronto']) . '</textarea></details></li>';
    }
    $modo = mcp_whatsapp_modo();
    $explica = $modo === 'manual'
        ? 'Toque em <b>Abrir no WhatsApp</b>: abre a conversa com o texto pronto no WhatsApp deste aparelho (use o da instituição). Mande e volte aqui para marcar <b>Enviei</b>. O que não sair até o fim do prazo vence sozinho.'
        : 'O WhatsApp está no modo ' . pn_e(mcp_whatsapp_modo_nome($modo)) . ': as mensagens saem sozinhas e esta fila fica vazia.';
    $corpo = pc_topo('Fila do WhatsApp', $explica, 'fila', $aviso, $classe)
        . ($linhas !== '' ? '<ul class="pc-fila">' . $linhas . '</ul>' : '<div class="cartao vazio">Nada na fila agora.</div>');
    pn_pagina('Fila do WhatsApp', $corpo, $usuario, true, 'comunicacao');
}

// ----------------------------------------------------------------------------- Comunicação: envios
function pc_envios(string $usuario, string $aviso, string $classe): never
{
    $tipo = mcp_texto($_GET['tipo'] ?? '', 12);
    $status = mcp_texto($_GET['status'] ?? '', 10);
    $sql = 'SELECT * FROM mcp_avisos WHERE 1 = 1';
    $params = [];
    if (isset(MCP_AVISOS_TIPOS[$tipo])) {
        $sql .= ' AND tipo = ?';
        $params[] = $tipo;
    }
    if (isset(MCP_AVISOS_STATUS[$status])) {
        $sql .= ' AND status = ?';
        $params[] = $status;
    }
    $stmt = mcp_db()->prepare($sql . ' ORDER BY id DESC LIMIT 200');
    $stmt->execute($params);
    $linhas = '';
    foreach ($stmt->fetchAll() as $a) {
        $repetir = $a['status'] === 'falhou' && $a['tipo'] !== 'teste'
            ? pc_form($usuario, 'aviso_repetir', (int) $a['id'], pc_botao('Tentar de novo'), ' onsubmit="return confirm(\'Mandar de novo? Confira antes se a pessoa já não recebeu.\')"') : '';
        // A pessoa respondeu "pare" (no WhatsApp da instituição, no robô do Palácio ou por e-mail): a secretaria registra.
        $repetir .= $a['tipo'] !== 'teste' && $a['destino'] !== ''
            ? pc_form($usuario, 'aviso_parar', (int) $a['id'], pc_botao('Pediu para parar'), ' onsubmit="return confirm(\'Registrar que esta pessoa pediu para não receber mais por este canal?\')"') : '';
        $provedor = ['resend' => 'pela Resend', 'mail' => 'pelo servidor do site', 'manual' => 'mandado à mão', 'cloud' => 'pela API oficial', 'evolution' => 'pelo WhatsApp do Palácio',
            'webhook' => 'pelo Make', 'email' => 'e-mail', 'fila' => ''][$a['provedor'] ?? ''] ?? (string) $a['provedor'];
        $linhas .= '<tr><td data-rotulo="Quando">' . pn_e(pn_data((string) ($a['enviado_em'] ?? $a['criado_em']))) . '</td>'
            . '<td class="aluno"><b>' . pn_e((string) $a['nome']) . '</b><small>' . pn_e(pc_destino((string) $a['canal'], (string) $a['destino'])) . '</small></td>'
            . '<td data-rotulo="Aviso">' . pn_e(MCP_AVISOS_TIPOS[$a['tipo']] ?? $a['tipo']) . '<small>' . ($a['canal'] === 'whatsapp' ? 'WhatsApp' : 'E-mail') . ($provedor !== '' ? ' · ' . pn_e($provedor) : '') . '</small></td>'
            . '<td data-rotulo="Situação">' . pc_selo_aviso((string) $a['status']) . ($a['entrega'] ? '<small>WhatsApp: ' . pn_e(['sent' => 'enviada', 'delivered' => 'entregue', 'read' => 'lida', 'failed' => 'falhou'][$a['entrega']] ?? (string) $a['entrega']) . '</small>' : '')
            . ($a['erro'] ? '<small>' . pn_e((string) $a['erro']) . '</small>' : '') . '</td>'
            . '<td data-rotulo="Clique">' . ($a['clicado_em'] ? pn_e(pn_data((string) $a['clicado_em'])) . ((int) $a['cliques'] > 1 ? '<small>' . (int) $a['cliques'] . ' cliques</small>' : '') : '—') . '</td>'
            . '<td>' . $repetir . '</td></tr>';
    }
    $filtro = static function (string $nome, array $opcoes, string $atual) use ($tipo, $status): string {
        $html = '<option value="">' . ($nome === 'tipo' ? 'Todos os tipos' : 'Todas as situações') . '</option>';
        foreach ($opcoes as $k => $r) {
            $html .= '<option value="' . pn_e($k) . '"' . ($k === $atual ? ' selected' : '') . '>' . pn_e($r) . '</option>';
        }
        return '<select name="' . $nome . '" aria-label="' . ($nome === 'tipo' ? 'Tipo de aviso' : 'Situação') . '">' . $html . '</select>';
    };
    $corpo = pc_topo('Envios', 'Tudo o que foi preparado para sair, os 200 mais recentes, com a situação de cada um. O registro é apagado depois de 1 ano.', 'envios', $aviso, $classe)
        . '<form class="busca" method="get" action="painel.php"><input type="hidden" name="v" value="comunicacao"><input type="hidden" name="aba" value="envios">'
        . $filtro('tipo', MCP_AVISOS_TIPOS, $tipo) . $filtro('status', MCP_AVISOS_STATUS, $status) . '<button class="btn btn-outline" type="submit">Filtrar</button></form>'
        . '<div class="tabela rolagem"><table class="respostas"><thead><tr><th>Quando</th><th>Pessoa</th><th>Aviso</th><th>Situação</th><th>Clique</th><th></th></tr></thead><tbody>'
        . ($linhas !== '' ? $linhas : '<tr><td colspan="6" class="vazio">' . ($tipo !== '' || $status !== '' ? 'Nenhuma mensagem com esses filtros. <a href="painel.php?v=comunicacao&amp;aba=envios">Limpar filtros</a>' : 'Nada por aqui.') . '</td></tr>') . '</tbody></table></div>';
    pn_pagina('Envios', $corpo, $usuario, true, 'comunicacao');
}

// ----------------------------------------------------------------------------- Comunicação: resultados
/** Barras horizontais de uma série só (uma cor), com o valor na ponta e a tabela embaixo. */
function pc_barras(array $itens, string $unidade = ''): string
{
    $max = max(1, ...array_values(array_map('intval', $itens)) ?: [1]);
    $html = '<ul class="pc-barras">';
    foreach ($itens as $rotulo => $n) {
        $largura = (int) round(100 * (int) $n / $max);
        $html .= '<li><span class="pc-barras-rotulo">' . pn_e((string) $rotulo) . '</span><span class="pc-barras-trilho"><span class="pc-barras-barra" style="width:' . max($largura, (int) $n > 0 ? 2 : 0) . '%"></span></span>'
            . '<b>' . (int) $n . ($unidade !== '' ? ' ' . pn_e($unidade) : '') . '</b></li>';
    }
    return $html . '</ul>';
}

/** Colunas por dia (uma série: entradas no ponto), com o valor no título de cada coluna e a tabela em "ver os números". */
function pc_colunas_dias(array $porDia, string $de, string $ate): string
{
    $dias = [];
    for ($d = $de; $d < $ate; $d = mcp_avisos_dia_mais($d, 1)) {
        $dias[$d] = (int) ($porDia[$d] ?? 0);
    }
    $max = max(1, ...array_values($dias) ?: [1]);
    $colunas = '';
    $tabela = '';
    $i = 0;
    foreach ($dias as $d => $n) {
        $rotulo = mcp_avisos_dia_texto($d);
        $colunas .= '<div class="pc-coluna" title="' . pn_e($rotulo . ': ' . $n . ($n === 1 ? ' entrada' : ' entradas')) . '"><span class="pc-coluna-valor">' . ($n > 0 && count($dias) <= 31 ? $n : '') . '</span>'
            . '<span class="pc-coluna-barra" style="height:' . (int) round(100 * $n / $max) . '%"></span>'
            . '<span class="pc-coluna-dia">' . (($i % max(1, (int) ceil(count($dias) / 10))) === 0 ? pn_e(substr($d, 8, 2) . '/' . substr($d, 5, 2)) : '') . '</span></div>';
        $tabela .= '<tr><td>' . pn_e($rotulo) . '</td><td>' . $n . '</td></tr>';
        $i++;
    }
    return '<div class="pc-colunas" aria-hidden="true">' . $colunas . '</div>'
        . '<details class="ajustar"><summary>Ver os números dia a dia</summary><div class="tabela" style="margin-top:8px"><table><thead><tr><th>Dia</th><th>Entradas</th></tr></thead><tbody>' . $tabela . '</tbody></table></div></details>';
}

function pc_resultados(string $usuario, string $aviso, string $classe): never
{
    $pedido = mcp_texto($_GET['periodo'] ?? '14', 12);
    [$de, $ate, $rotuloPeriodo] = mcp_metricas_periodo($pedido);
    if (isset($_GET['csv'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="resultados-ponto-' . $de . '.csv"');
        mcp_registrar(null, 'painel_resultados_csv', "$usuario · $de");
        echo mcp_metricas_csv(mcp_metricas_pessoas($de, $ate));
        exit;
    }
    $m = mcp_metricas($de, $ate);
    $a = $m['atual'];
    $p = $m['anterior'];
    $pilulas = '';
    foreach (['14' => '14 dias', '30' => '30 dias', '60' => '60 dias', 'lancamento' => 'Desde o lançamento'] as $k => $r) {
        $k = (string) $k; // o PHP guarda '14' como chave inteira
        if ($k === 'lancamento' && mcp_ajuste('lancamento') === '') {
            continue;
        }
        $pilulas .= '<a class="pilula' . ($pedido === $k || ($k === '14' && !in_array($pedido, ['30', '60', 'lancamento'], true)) ? ' ativo' : '') . '" href="painel.php?v=comunicacao&amp;aba=resultados&amp;periodo=' . $k . '">' . pn_e($r) . '</a>';
    }
    $adesao = mcp_metricas_pct($a['com_registro'], $a['ativos']);
    $adesaoAntes = mcp_metricas_pct($p['com_registro'], $p['ativos']);
    $esquecidasPct = mcp_metricas_pct($a['esquecidas'], $a['entradas_voluntario']);
    $esquecidasAntes = mcp_metricas_pct($p['esquecidas'], $p['entradas_voluntario']);
    $numeros = '<div class="numeros">'
        . '<div class="numero"><b>' . pc_pct($adesao) . '</b><span>Adesão</span><small>' . $a['com_registro'] . ' de ' . $a['ativos'] . ' colaboradores ativos registraram</small>' . pc_variacao((float) ($adesao ?? 0), (float) ($adesaoAntes ?? 0)) . '</div>'
        . '<div class="numero"><b>' . $a['entradas'] . '</b><span>Entradas no ponto</span><small>em ' . $m['dias'] . ' dias</small>' . pc_variacao($a['entradas'], $p['entradas']) . '</div>'
        . '<div class="numero"><b>' . pn_e(mcp_ponto_horas_texto($a['horas_minutos'])) . '</b><span>Horas doadas</span><small>voluntários e diretoria</small>' . pc_variacao($a['horas_minutos'], $p['horas_minutos']) . '</div>'
        . '<div class="numero' . ($a['esquecidas'] > 0 ? ' alerta' : '') . '"><b>' . pc_pct($esquecidasPct) . '</b><span>Saídas não registradas na hora</span><small>' . $a['esquecidas'] . ' de ' . $a['entradas_voluntario'] . ' entradas de voluntários, contando as resolvidas depois'
        . ($a['informadas'] ? ' · ' . pc_plural($a['informadas'], 'informada', 'informadas') . ' pela própria pessoa' : '') . '</small>' . pc_variacao((float) ($esquecidasPct ?? 0), (float) ($esquecidasAntes ?? 0), true) . '</div>'
        . '</div>';
    $origens = [];
    foreach ($a['por_origem'] as $k => $n) {
        if ($n > 0 || $k !== 'informada') {
            $origens[MCP_PONTO_ORIGENS[$k] ?? $k] = $n;
        }
    }
    $registros = '<div class="cartao"><h2>Entradas por dia <small>' . pn_e($rotuloPeriodo) . '</small></h2>' . pc_colunas_dias($a['por_dia'], $de, $ate)
        . '<p class="nota">Alunos: ' . $a['presencas_alunos'] . ' presenças confirmadas no período' . ($p['presencas_alunos'] ? ' (' . $p['presencas_alunos'] . ' no período anterior)' : '') . '.</p></div>'
        . '<div class="cartao"><h2>Como registram</h2>' . pc_barras($origens, '') . '<p class="nota">Aparelho da recepção, celular pelo QR code, lançado no portal ou saída informada pela própria pessoa.</p></div>';

    // Lembretes: saíram, clicaram, deram certo.
    $lembretes = '';
    foreach (['vespera', 'saida', 'aula', 'campanha', 'link'] as $tipo) {
        $t = $m['avisos'][$tipo];
        if ($t['preparados'] === 0) {
            continue;
        }
        $certo = in_array($tipo, ['vespera', 'saida', 'aula'], true) ? pc_pct(mcp_metricas_pct($t['convertidos'], $t['avaliados'])) . '<small>' . $t['convertidos'] . ' de ' . $t['avaliados'] . '</small>' : '—';
        $lembretes .= '<tr><td class="aluno"><b>' . pn_e(MCP_AVISOS_TIPOS[$tipo]) . '</b><small>' . $t['email'] . ' por e-mail · ' . $t['whatsapp'] . ' por WhatsApp</small></td>'
            . '<td data-rotulo="Enviados">' . $t['enviados'] . '<small>de ' . $t['preparados'] . ' preparados</small></td>'
            . '<td data-rotulo="Falhas">' . $t['falhas'] . ($t['fila'] ? '<small>' . $t['fila'] . ' na fila</small>' : '') . '</td>'
            . '<td data-rotulo="Clicaram">' . pc_pct(mcp_metricas_pct($t['clicados'], $t['enviados'])) . '</td>'
            . '<td data-rotulo="Registraram depois">' . $certo . '</td></tr>';
    }
    $blocoLembretes = '<h2 style="margin:26px 0 6px">Lembretes e comunicados</h2>'
        . '<p class="nota" style="margin:0 0 10px">"Registraram depois": depois do lembrete da véspera, a pessoa registrou a entrada no dia; depois do aviso de saída, a saída foi informada ou corrigida; depois do lembrete da aula, o aluno confirmou presença. '
        . 'Só conta o que já podia dar resultado (o dia já passou). Quem escolheu os dias talvez viesse de qualquer jeito: é um sinal, não uma prova do efeito do lembrete.</p>'
        . '<div class="tabela rolagem"><table class="respostas"><thead><tr><th>Aviso</th><th>Enviados</th><th>Falhas</th><th>Clicaram</th><th>Registraram depois</th></tr></thead><tbody>'
        . ($lembretes !== '' ? $lembretes : '<tr><td colspan="5" class="vazio">Nenhum aviso preparado neste período.</td></tr>') . '</tbody></table></div>';

    // Opinião.
    $o = $m['opiniao'];
    $blocoOpiniao = '<h2 id="opiniao" style="margin:26px 0 10px">Opinião das pessoas <small>todas as respostas</small></h2>';
    if ($o['respostas'] === 0) {
        $blocoOpiniao .= '<div class="cartao vazio">Ainda sem respostas. A pesquisa vai no comunicado "Depois de 2 semanas".</div>';
    } else {
        $facilidade = [];
        foreach (MCP_OPINIAO_FACILIDADE as $k => $r) {
            $facilidade["$k · $r"] = $o['facilidade'][$k];
        }
        $problemas = [];
        foreach ($o['problemas'] as $k => $n) {
            if ($n > 0) {
                $problemas[(MCP_OPINIAO_PROBLEMAS + MCP_OPINIAO_PROBLEMAS_ALUNO)[$k]] = $n;
            }
        }
        $problemas['Nenhum problema'] = $o['sem_problema'];
        $como = [];
        foreach ($o['como'] as $k => $n) {
            $como[MCP_OPINIAO_COMO[$k]] = $n;
        }
        $lemb = [];
        foreach ($o['lembretes'] as $k => $n) {
            $lemb[MCP_OPINIAO_LEMBRETES[$k]] = $n;
        }
        $comentarios = '';
        foreach (array_slice($o['comentarios'], 0, 60) as $cm) {
            $comentarios .= '<li><p class="mensagem" style="font-size:.92rem">' . pn_e($cm['texto']) . '</p><small>' . pn_e(mcp_escola_data($cm['quando'])) . ' · nota ' . $cm['facilidade']
                . ' · ' . ($cm['publico'] === 'aluno' ? 'aluno' : pn_e((string) ($cm['vinculo'] ?? 'colaborador')))
                . ($cm['nome'] !== null ? ' · <b>' . pn_e($cm['nome']) . '</b> (pode ser procurado: ' . pn_e((string) $cm['contato']) . ')' : ' · sem nome') . '</small></li>';
        }
        $blocoOpiniao .= '<div class="numeros"><div class="numero"><b>' . $o['respostas'] . '</b><span>Respostas</span><small>' . $o['por_publico']['colaborador'] . ' colaboradores · ' . $o['por_publico']['aluno'] . ' alunos</small></div>'
            . '<div class="numero"><b>' . ($o['media'] !== null ? str_replace('.', ',', (string) $o['media']) : '—') . '</b><span>Facilidade média</span><small>de 1 (muito difícil) a 5 (muito fácil)</small></div>'
            . '<div class="numero"><b>' . pc_pct(mcp_metricas_pct($o['sem_problema'], $o['respostas'])) . '</b><span>Sem problemas</span><small>não marcaram nenhum</small></div>'
            . '<div class="numero"><b>' . pc_pct(mcp_metricas_pct($o['lembretes']['ajudam'], array_sum($o['lembretes']))) . '</b><span>Lembretes ajudam</span><small>entre quem respondeu sobre eles</small></div></div>'
            . '<div class="grade-inicio"><div class="cartao"><h2>Registrar é…</h2>' . pc_barras($facilidade) . '</div><div class="cartao"><h2>Problemas citados</h2>' . pc_barras($problemas) . '</div>'
            . '<div class="cartao"><h2>Como registram</h2>' . pc_barras($como) . '</div><div class="cartao"><h2>Os lembretes…</h2>' . pc_barras($lemb) . '</div></div>'
            . ($comentarios !== '' ? '<div class="cartao" style="margin-top:18px"><h2>O que podemos melhorar</h2><ul class="pc-comentarios">' . $comentarios . '</ul></div>' : '');
    }

    // Por pessoa.
    $pessoas = '';
    foreach ($m['pessoas'] as $l) {
        $pessoas .= '<tr><td class="aluno"><a class="nome" href="painel.php?v=colaborador&amp;id=' . $l['id'] . '">' . pn_e($l['nome']) . '</a><small>' . pn_e(MCP_PONTO_VINCULOS[$l['vinculo']] ?? $l['vinculo'])
            . ($l['ativo'] ? '' : ' · inativo') . '</small></td>'
            . '<td data-rotulo="Dias">' . $l['dias'] . '</td>'
            . '<td data-rotulo="Horas">' . pn_e(mcp_ponto_horas_texto($l['minutos'])) . '</td>'
            . '<td data-rotulo="Saídas a completar">' . ($l['esquecidas'] ? $l['esquecidas'] : '0') . '</td>'
            . '<td data-rotulo="Lembretes">' . ($l['lembrete'] ? 'véspera' : '—') . ($l['whatsapp'] ? '<small>WhatsApp</small>' : '') . (!$l['contato'] ? '<small>sem contato</small>' : '') . '</td></tr>';
    }
    $blocoPessoas = '<h2 style="margin:26px 0 10px">Voluntários e diretoria <small>' . pn_e($rotuloPeriodo) . '</small></h2>'
        . '<div class="tabela rolagem"><table class="respostas"><thead><tr><th>Voluntário</th><th>Dias</th><th>Horas</th><th>Saídas a completar</th><th>Lembretes</th></tr></thead><tbody>'
        . ($pessoas !== '' ? $pessoas : '<tr><td colspan="5" class="vazio">Nenhum voluntário cadastrado.</td></tr>') . '</tbody></table></div>'
        . '<p class="nota">Por nome, sem ranking: o voluntariado não é competição. A equipe contratada fica só nos totais (presença por pessoa pareceria controle de jornada). '
        . 'Cliques e opiniões aparecem só no total.</p>';

    $corpo = pc_topo('Resultados', 'Adesão ao ponto, registros, horas doadas, saídas esquecidas, efeito dos lembretes e a opinião das pessoas. Comparado com o período anterior, do mesmo tamanho.', 'resultados', $aviso, $classe,
            '<a class="btn btn-outline" href="' . pn_e('painel.php?v=comunicacao&aba=resultados&periodo=' . $pedido . '&csv=1') . '">Baixar planilha dos voluntários</a><button class="btn btn-outline" type="button" onclick="window.print()">Imprimir relatório</button>')
        . '<div class="filtros pc-periodo">' . $pilulas . '</div>' . $numeros . '<div class="grade-inicio">' . $registros . '</div>' . $blocoLembretes . $blocoOpiniao . $blocoPessoas;
    pn_pagina('Resultados', $corpo, $usuario, true, 'comunicacao');
}

// ----------------------------------------------------------------------------- Ponto: saídas informadas e importação
/** Cartão das saídas informadas pelas pessoas, esperando a secretaria (vai no topo do Ponto da sede). */
function pc_saidas_informadas(string $usuario): string
{
    $linhas = '';
    foreach (mcp_ponto_saidas_informadas() as $r) {
        $id = (int) $r['id'];
        $linhas .= '<tr><td class="aluno"><a class="nome" href="painel.php?v=colaborador&amp;id=' . (int) $r['colaborador_id'] . '&amp;mes=' . pn_e(mcp_data_brt((string) $r['entrada'], 'Y-m')) . '">' . pn_e((string) $r['nome']) . '</a>'
            . '<small>' . pn_e(mcp_ponto_vinculo_nome($r)) . '</small></td>'
            . '<td data-rotulo="Dia">' . pn_e(mcp_data_brt((string) $r['entrada'], 'd/m/Y')) . '<small class="dia">' . pn_e(mcp_dia_semana(mcp_data_brt((string) $r['entrada'], 'Y-m-d'))) . '</small></td>'
            . '<td data-rotulo="Entrada">' . pn_e(mcp_data_brt((string) $r['entrada'], 'H:i')) . '</td>'
            . '<td data-rotulo="Saída informada"><b>' . pn_e(mcp_data_brt((string) $r['saida_informada'], mcp_data_brt((string) $r['saida_informada'], 'Y-m-d') !== mcp_data_brt((string) $r['entrada'], 'Y-m-d') ? 'd/m H:i' : 'H:i')) . '</b>'
            . '<small>' . pn_e(mcp_ponto_horas_texto(intdiv(max(0, (int) strtotime($r['saida_informada'] . ' UTC') - (int) strtotime($r['entrada'] . ' UTC')), 60))) . ' · informada em ' . pn_e(pn_data((string) $r['saida_informada_em'])) . '</small></td>'
            . '<td><div class="acoes" style="margin:0;gap:6px">' . pc_form($usuario, 'saida_aceitar', $id, '<input type="hidden" name="visto" value="' . pn_e((string) $r['saida_informada']) . '">'
                . pc_botao('Aceitar', 'btn-red', ' aria-label="' . pn_e('Aceitar a saída de ' . $r['nome'] . ', ' . mcp_data_brt((string) $r['entrada'], 'd/m')) . '"'))
            . '<details class="ajustar"><summary>Recusar</summary>' . pc_form($usuario, 'saida_recusar', $id, '<div class="mini"><label>Motivo (opcional)<input name="motivo" maxlength="200" placeholder="Ex.: saiu mais cedo, confirmado com a coordenação"></label>'
                . pc_botao('Recusar') . '</div>') . '</details></div></td></tr>';
    }
    if ($linhas === '') {
        return '';
    }
    return '<div class="cartao" id="saidas-informadas" style="margin-bottom:18px"><h2>Saídas informadas para conferir <small>pelas próprias pessoas</small></h2>'
        . '<p class="nota" style="margin:0 0 12px">A pessoa esqueceu de registrar a saída e informou o horário (pelo link do aviso ou na tela do ponto). Aceite para as horas contarem, ou recuse e corrija à mão na ficha.</p>'
        . '<div class="tabela rolagem"><table class="respostas"><thead><tr><th>Colaborador</th><th>Dia</th><th>Entrada</th><th>Saída informada</th><th></th></tr></thead><tbody>' . $linhas . '</tbody></table></div></div>';
}

/** Importar a planilha de colaboradores: colar, conferir linha a linha e importar. */
function pc_importar(string $usuario, string $aviso = '', string $classe = 'ok', string $texto = '', ?array $conferido = null, string $erroConsentimento = ''): never
{
    $exemplo = "Nome;CPF;Vínculo;Função;E-mail;Telefone;Dias;WhatsApp\nMaria da Silva;123.456.789-09;voluntário;Socorrista;maria@exemplo.com;(21) 99999-0000;seg,qua;sim";
    $tabela = '';
    $prontas = 0;
    if ($conferido !== null) {
        foreach ($conferido['linhas'] as $l) {
            $prontas += $l['ok'] ? 1 : 0;
            $d = $l['dados'] ?? [];
            $tabela .= '<tr><td data-rotulo="Linha">' . (int) $l['linha'] . '</td>'
                . '<td class="aluno"><b>' . pn_e((string) ($d['nome'] ?? $l['bruto'][0] ?? '')) . '</b><small>' . pn_e(isset($d['cpf']) ? mcp_cpf_mascarado($d['cpf']) : '') . '</small></td>'
                . '<td data-rotulo="Vínculo">' . pn_e(isset($d['vinculo']) ? (MCP_PONTO_VINCULOS[$d['vinculo']] ?? $d['vinculo']) : '') . '</td>'
                . '<td data-rotulo="Contato">' . pn_e((string) ($d['email'] ?? '')) . (!empty($d['telefone']) ? '<small>' . pn_e(mcp_telefone_bonito($d['telefone'])) . '</small>' : '') . '</td>'
                . '<td data-rotulo="Lembretes">' . pn_e(mcp_avisos_dias_texto($l['dias'] ?? []) ?: '—') . (!empty($l['whatsapp']) ? '<small>WhatsApp autorizado</small>' : '') . '</td>'
                . '<td data-rotulo="Situação">' . ($l['ok'] ? pc_selo('ok', $l['existe'] ? 'Atualiza o cadastro' : 'Novo') : pc_selo('erro', 'Com erro') . '<small>' . pn_e((string) $l['erro']) . '</small>') . '</td></tr>';
        }
    }
    $whatsapp = $conferido !== null && array_filter($conferido['linhas'], static fn(array $l): bool => $l['ok'] && !empty($l['whatsapp']));
    $corpo = '<a class="voltar" href="painel.php?v=ponto">← Voltar para o ponto da sede</a>'
        . ($aviso !== '' ? '<div class="aviso ' . pn_e($classe) . '">' . pn_e($aviso) . '</div>' : '')
        . '<div class="cabeca"><div><p class="eyebrow">Ponto da sede</p><h1>Importar colaboradores</h1>'
        . '<p class="nota">Cole as linhas da planilha (Excel ou Google Planilhas: selecione, copie e cole aqui). Colunas, nesta ordem ou com o cabeçalho: '
        . '<b>Nome; CPF; Vínculo; Função; E-mail; Telefone; Dias; WhatsApp</b>. Vínculo: voluntário, diretoria, empregado, terceirizado ou outro. '
        . 'Dias: os dias em que a pessoa costuma vir (ex.: seg, qua). WhatsApp: "sim" só se a pessoa autorizou receber lembretes por WhatsApp. CPF já cadastrado atualiza o cadastro.</p></div></div>'
        . '<div class="cartao" style="margin-top:18px">' . pc_form($usuario, 'col_importar', 0,
            '<label for="imp-texto">Linhas da planilha</label><textarea id="imp-texto" name="texto" required maxlength="200000" style="min-height:220px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.88rem" placeholder="' . pn_e($exemplo) . '">' . pn_e($texto) . '</textarea>'
            . ($whatsapp ? '<label class="check" style="margin-top:14px' . ($erroConsentimento !== '' ? ';color:var(--red)' : '') . '"><input type="checkbox" name="consentimento" value="1"> Confirmo que as pessoas marcadas com WhatsApp "sim" autorizaram receber lembretes por WhatsApp.</label>' : '')
            . '<div class="acoes"><button class="btn btn-outline" type="submit" name="etapa" value="conferir">Conferir</button>'
            . ($prontas > 0 ? '<button class="btn btn-red" type="submit" name="etapa" value="importar">Importar ' . $prontas . ($prontas === 1 ? ' linha' : ' linhas') . '</button>' : '') . '</div>')
        . '</div>'
        . ($conferido !== null ? '<h2 style="margin:24px 0 10px">Conferência <small>' . $prontas . ' prontas · ' . (count($conferido['linhas']) - $prontas) . ' com erro (ficam de fora)</small></h2>'
            . '<div class="tabela rolagem"><table class="respostas"><thead><tr><th>Linha</th><th>Nome</th><th>Vínculo</th><th>Contato</th><th>Lembretes</th><th>Situação</th></tr></thead><tbody>'
            . ($tabela !== '' ? $tabela : '<tr><td colspan="6" class="vazio">Nenhuma linha encontrada.</td></tr>') . '</tbody></table></div>' : '');
    pn_pagina('Importar colaboradores', $corpo, $usuario, true, 'ponto');
}

/** Cartão "Lembretes e contato" na ficha do colaborador. */
function pc_ficha_lembretes(string $usuario, array $c): string
{
    $id = (int) $c['id'];
    $dias = mcp_avisos_dias($c['aviso_dias']);
    $numero = mcp_whatsapp_numero((string) $c['telefone']);
    $marcaDia = '';
    foreach (MCP_AVISOS_DIAS as $k => $r) {
        $marcaDia .= '<label class="check"><input type="checkbox" name="dias[]" value="' . $k . '"' . (in_array($k, $dias, true) ? ' checked' : '') . '> ' . pn_e(mb_substr($r, 0, 3)) . '</label>';
    }
    $consentimento = (int) $c['aviso_whatsapp'] === 1
        ? 'WhatsApp autorizado em ' . pn_e(pn_data((string) $c['aviso_whatsapp_em'])) . ' por ' . pn_e((string) $c['aviso_whatsapp_por'])
            . (!empty($c['aviso_whatsapp_como']) ? ' (' . pn_e((string) $c['aviso_whatsapp_como']) . ')' : '') . '.'
        : 'WhatsApp não autorizado.';
    $voluntario = mcp_ponto_voluntario($c);
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_avisos WHERE colaborador_id = ? ORDER BY id DESC LIMIT 8');
    $stmt->execute([$id]);
    $ultimos = '';
    foreach ($stmt->fetchAll() as $a) {
        $ultimos .= '<li><div>' . pn_e(MCP_AVISOS_TIPOS[$a['tipo']] ?? $a['tipo']) . ' · ' . ($a['canal'] === 'whatsapp' ? 'WhatsApp' : 'e-mail') . '<small>' . pn_e(pn_data((string) ($a['enviado_em'] ?? $a['criado_em'])))
            . ($a['status'] !== 'enviado' && $a['erro'] ? ' · ' . pn_e((string) $a['erro']) : '') . '</small></div>' . pc_selo_aviso((string) $a['status']) . '</li>';
    }
    $link = (int) $c['ativo'] ? mcp_avisos_link('lembretes', 'c' . $id) : '';
    return '<div class="cartao" id="lembretes" style="margin-top:18px"><h2>Lembretes e contato</h2>'
        . '<p class="nota" style="margin-top:0">' . ($c['aviso_atualizado_em'] ? 'Última mudança em ' . pn_e(pn_data((string) $c['aviso_atualizado_em'])) . '. ' : 'A pessoa ainda não abriu as preferências. ') . $consentimento . '</p>'
        . pc_form($usuario, 'col_avisos', $id,
            '<fieldset class="pc-dias"><legend>Dias em que costuma vir (lembrete na véspera, às 18h)</legend><div class="pc-canais">' . $marcaDia . '</div></fieldset>'
            . (!$voluntario ? '<p class="nota" style="margin:4px 0 0">Para quem não é voluntário, o lembrete só sai se a própria pessoa escolher os dias pelo link, e só em dia útil '
                . '(lembrete de presença mandado pela instituição a empregado pareceria controle de jornada).' . (($c['aviso_dias_por'] ?? null) === 'a própria pessoa' ? ' Estes dias foram escolhidos pela pessoa.' : '') . '</p>' : '')
            . '<label class="check" style="margin-top:12px"><input type="checkbox" name="email" value="1"' . ((int) $c['aviso_email'] ? ' checked' : '') . (filter_var((string) $c['email'], FILTER_VALIDATE_EMAIL) ? '' : ' disabled') . '> Receber por e-mail'
            . (filter_var((string) $c['email'], FILTER_VALIDATE_EMAIL) ? '' : ' (sem e-mail no cadastro)') . '</label>'
            . '<label class="check"><input type="checkbox" name="whatsapp" value="1"' . ((int) $c['aviso_whatsapp'] ? ' checked' : '') . ($numero ? '' : ' disabled') . '> A pessoa autorizou receber os avisos do ponto por WhatsApp'
            . ($numero ? ' no ' . pn_e(mcp_whatsapp_mascarado($numero)) : ' (cadastre um celular com DDD)') . '</label>'
            . ((int) $c['aviso_whatsapp'] !== 1 && $numero ? '<label style="margin-top:6px">Como a pessoa autorizou (obrigatório ao marcar)<input name="como" maxlength="160" placeholder="Ex.: formulário assinado; pessoalmente na recepção"></label>' : '')
            . (mcp_ponto_voluntario($c) ? '<label class="check"><input type="checkbox" name="saida" value="1"' . ((int) $c['aviso_saida'] ? ' checked' : '') . '> Avisar quando esquecer de registrar a saída</label>' : '')
            . '<label class="check"><input type="checkbox" name="comunicados" value="1"' . ((int) $c['aviso_comunicados'] ? ' checked' : '') . '> Receber os comunicados sobre o ponto</label>'
            . '<div class="acoes">' . pc_botao('Salvar lembretes e contato', 'btn-red') . '</div>')
        . ($link !== '' ? '<div class="acoes">' . pc_form($usuario, 'col_link', $id, pc_botao('Mandar o link das preferências por e-mail'), filter_var((string) $c['email'], FILTER_VALIDATE_EMAIL) ? '' : ' hidden') . '</div>'
            . '<details class="ajustar"><summary>Copiar o link das preferências (vale ' . MCP_AVISOS_LINK_DIAS['lembretes'] . ' dias)</summary><input readonly value="' . pn_e($link) . '" onclick="this.select()" style="margin-top:8px;font-size:.85rem"></details>' : '')
        . ($ultimos !== '' ? '<h2 style="margin:18px 0 6px;font-size:.9rem">Últimos avisos</h2><ul class="lista-curta">' . $ultimos . '</ul>' : '')
        . pc_form($usuario, 'col_links_novos', $id, '<div class="acoes">' . pc_botao('Invalidar os links já enviados') . '</div>',
            ' onsubmit="return confirm(\'Os links pessoais já enviados a esta pessoa deixam de valer (use se um e-mail foi para o endereço errado). Continuar?\')"')
        . '</div>';
}

// ----------------------------------------------------------------------------- formulários (POST)
/** Trata as ações desta parte do portal. Quem chama já conferiu a sessão e o token do formulário. */
function pc_post(string $sessao, string $acao, int $id): never
{
    $agora = time();
    switch ($acao) {
        case 'ajustes_salvar':
            // A decisão sobre o WhatsApp do Palácio vem num formulário à parte (não mexe nos outros ajustes).
            $nomes = !empty($_POST['so_evolucao']) ? ['evolution_riscos'] : ['lembrete_vespera', 'lembrete_saida', 'lembrete_aula', 'aula_whatsapp', 'whatsapp_ativo'];
            foreach ($nomes as $nome) {
                $novo = !empty($_POST[$nome]) ? '1' : '0';
                if (mcp_ajuste($nome, '0') !== $novo) {
                    mcp_ajuste_gravar($nome, $novo, $sessao);
                    mcp_registrar(null, 'painel_ajuste', "$sessao · $nome = $novo");
                    // Lembrete desligado: o que estava na fila daquele tipo também não sai.
                    $tipo = array_search($nome, MCP_AVISOS_AJUSTES, true);
                    if ($novo === '0' && $tipo !== false) {
                        mcp_db()->prepare("UPDATE mcp_avisos SET status = 'cancelado', erro = 'lembrete desligado no portal', atualizado_em = ? WHERE tipo = ? AND status IN ('pendente', 'manual')")
                            ->execute([mcp_agora(), $tipo]);
                    }
                }
            }
            if (empty($_POST['so_evolucao'])) {
                $fechados = [];
                foreach (preg_split('/[\s,;]+/', mcp_texto($_POST['dias_fechados'] ?? '', 400)) ?: [] as $d) {
                    if (preg_match('~^(\d{1,2})/(\d{1,2})(?:/(\d{2,4}))?$~', $d, $m)) {
                        $ano = isset($m[3]) ? ((int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3]) : (int) substr(mcp_ponto_hoje($agora), 0, 4);
                        if (checkdate((int) $m[2], (int) $m[1], $ano)) {
                            $fechados[] = sprintf('%04d-%02d-%02d', $ano, $m[2], $m[1]);
                        }
                    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                        $fechados[] = $d;
                    }
                }
                $fechados = implode(',', array_values(array_unique($fechados)));
                if (mcp_ajuste('dias_fechados') !== $fechados) {
                    mcp_ajuste_gravar('dias_fechados', $fechados, $sessao);
                }
            }
            pn_redirecionar('v=comunicacao&ok=aj_ok');
        case 'implantacao':
            $data = mcp_texto($_POST['data'] ?? '', 10);
            if (mcp_comunicado_data($data) === '') {
                pn_redirecionar('v=comunicacao');
            }
            $havia = mcp_campanhas_listar() !== [];
            $r = mcp_campanhas_preparar_implantacao($data, $sessao);
            pn_redirecionar('v=comunicacao&ok=' . (!$havia ? 'imp_ok' : ($r['agendados'] > 0 ? 'imp_agend' : 'imp_data')) . '#implantacao');
        case 'links_enviar':
            $n = 0;
            foreach (mcp_colaboradores_listar() as $c) {
                if ((int) $c['ativo'] && $c['aviso_atualizado_em'] === null && filter_var((string) $c['email'], FILTER_VALIDATE_EMAIL)) {
                    $n += mcp_aviso_criar(['chave' => 'link|' . $c['id'] . '|' . mcp_ponto_hoje($agora), 'tipo' => 'link', 'canal' => 'email', 'colaborador_id' => (int) $c['id'],
                        'pessoa' => 'c' . $c['id'], 'nome' => (string) $c['nome'], 'destino' => mb_strtolower((string) $c['email']), 'expira_em' => gmdate('Y-m-d H:i:s', $agora + 3 * 86400)], $agora) !== null ? 1 : 0;
                }
            }
            mcp_registrar(null, 'painel_links', "$sessao · $n");
            pn_redirecionar('v=comunicacao&ok=' . ($n > 0 ? 'lk_ok' : 'lk_nada'));
        case 'campanha_salvar':
            $conferido = mcp_campanha_conferir($_POST);
            if (!$conferido['ok']) {
                pc_comunicado($sessao, $id ?: null, $conferido['erro'], 'erro', $_POST + ['canais' => (array) ($_POST['canais'] ?? [])], $conferido['campo']);
            }
            $salvo = mcp_campanha_salvar($id ?: null, $conferido['dados'], $sessao);
            if ($salvo === null) {
                pc_comunicado($sessao, $id, 'Este comunicado já saiu e não pode mais mudar.', 'erro');
            }
            mcp_registrar(null, 'painel_campanha', "#$salvo · $sessao · " . ($id ? 'editou' : 'criou'));
            pn_redirecionar('v=comunicado&id=' . $salvo . '&ok=cp_ok');
        case 'campanha_teste':
            $campanha = mcp_campanha($id);
            if (!$campanha) {
                pn_redirecionar('v=comunicacao&aba=comunicados');
            }
            $falhou = false;
            $destinos = ['email' => $sessao];
            $numero = mcp_whatsapp_numero(mcp_texto($_POST['whatsapp_teste'] ?? '', 30));
            if ($numero !== null && mcp_whatsapp_modo() !== 'manual') {
                $destinos['whatsapp'] = $numero;
            }
            foreach ($destinos as $canal => $destino) {
                $avisoId = mcp_aviso_criar(['chave' => "teste|$id|$canal|" . $agora . '|' . bin2hex(random_bytes(3)), 'tipo' => 'teste', 'canal' => $canal, 'campanha_id' => $id,
                    'nome' => 'Teste (' . $sessao . ')', 'destino' => $destino], $agora);
                $falhou = $falhou || $avisoId === null || mcp_aviso_enviar(mcp_aviso_por_id($avisoId), $agora) !== 'enviado';
            }
            mcp_registrar(null, 'painel_campanha_teste', "#$id · $sessao");
            pn_redirecionar('v=comunicado&id=' . $id . '&ok=' . ($falhou ? 'cp_tfalha' : 'cp_teste'));
        case 'campanha_agendar':
        case 'campanha_enviar':
            $quando = $acao === 'campanha_agendar' ? mcp_ponto_local_para_utc(mcp_texto($_POST['quando'] ?? '', 20)) : null;
            if ($acao === 'campanha_agendar' && $quando === null) {
                pc_comunicado($sessao, $id, 'Data ou hora inválida.', 'erro');
            }
            $erro = mcp_campanha_agendar($id, $quando, $sessao, $agora);
            if ($erro !== null) {
                pc_comunicado($sessao, $id, $erro, 'erro');
            }
            if ($acao === 'campanha_agendar') {
                pn_redirecionar('v=comunicado&id=' . $id . '&ok=cp_agend');
            }
            if (!mcp_avisos_na_janela($agora)) {
                pn_redirecionar('v=comunicado&id=' . $id . '&ok=cp_janela');
            }
            // Começa já: prepara as mensagens e manda um lote pequeno; o resto sai na rotina (a cada 15 minutos).
            mcp_campanhas_disparar($agora);
            mcp_avisos_enviar_pendentes($agora, 15, $id);
            mcp_campanhas_concluir($agora);
            pn_redirecionar('v=comunicado&id=' . $id . '&ok=cp_envio');
        case 'campanha_cancelar':
            mcp_campanha_cancelar($id, $sessao);
            pn_redirecionar('v=comunicado&id=' . $id . '&ok=cp_canc');
        case 'campanha_apagar':
            mcp_campanha_apagar($id, $sessao);
            pn_redirecionar('v=comunicacao&aba=comunicados&ok=cp_apag');
        case 'fila_enviada':
        case 'fila_pular':
            mcp_avisos_fila_marcar($id, $acao === 'fila_enviada', $sessao, $agora);
            pn_redirecionar('v=comunicacao&aba=fila&ok=' . ($acao === 'fila_enviada' ? 'fl_ok' : 'fl_pulo'));
        case 'aviso_repetir':
            $aviso = mcp_aviso_por_id($id);
            // Véspera e aula valem até as 20h da véspera: depois disso, "amanhã" viraria "hoje". O prazo não se estende.
            $prazo = $aviso ? mcp_aviso_validade($aviso, $agora) : null;
            if ($prazo === null || (int) strtotime($prazo . ' UTC') <= $agora) {
                pn_redirecionar('v=comunicacao&aba=envios&ok=av_tarde');
            }
            mcp_db()->prepare("UPDATE mcp_avisos SET status = 'pendente', tentativas = 0, erro = NULL, agendado_para = ?, expira_em = ?, atualizado_em = ?
                WHERE id = ? AND status = 'falhou' AND tipo <> 'teste'")
                ->execute([gmdate('Y-m-d H:i:s', $agora), $prazo, gmdate('Y-m-d H:i:s', $agora), $id]);
            mcp_registrar(null, 'painel_aviso_repetir', "#$id · $sessao");
            pn_redirecionar('v=comunicacao&aba=envios&ok=av_rep');
        case 'aviso_parar':
            $aviso = mcp_aviso_por_id($id);
            if (!$aviso || $aviso['tipo'] === 'teste') {
                pn_redirecionar('v=comunicacao&aba=envios');
            }
            // Quem registrou fica no evento do portal; a lista de bloqueio guarda só a origem.
            $origem = 'secretaria';
            if ($aviso['canal'] === 'whatsapp') {
                mcp_whatsapp_parar(mcp_digitos((string) $aviso['destino']), $origem);
            } elseif (!empty($aviso['colaborador_id'])) {
                mcp_db()->prepare('UPDATE mcp_colaboradores SET aviso_email = 0, aviso_atualizado_em = ?, atualizado_em = ? WHERE id = ?')->execute([mcp_agora(), mcp_agora(), (int) $aviso['colaborador_id']]);
                mcp_db()->prepare("UPDATE mcp_avisos SET status = 'cancelado', erro = 'pediu para parar', atualizado_em = ? WHERE colaborador_id = ? AND canal = 'email' AND status IN ('pendente', 'manual')")
                    ->execute([mcp_agora(), (int) $aviso['colaborador_id']]);
            } else {
                mcp_avisos_bloquear_hash('email', mcp_avisos_hash('email', (string) $aviso['destino']), $origem);
            }
            mcp_registrar(null, 'painel_aviso_parar', "#$id · {$aviso['canal']} · $sessao");
            pn_redirecionar('v=comunicacao&aba=envios&ok=av_parou');
        case 'saida_aceitar':
        case 'saida_recusar':
            // "visto": o horário que a secretaria viu na lista (sem ele, de uma página aberta antes, não confere).
            $visto = mcp_texto($_POST['visto'] ?? '', 19);
            $erro = mcp_ponto_saida_decidir($id, $acao === 'saida_aceitar', $sessao, mcp_texto($_POST['motivo'] ?? '', 200), $agora,
                $acao === 'saida_aceitar' && $visto !== '' ? $visto : null);
            if ($erro !== null) {
                $_GET = [];
                pn_ponto($sessao, $erro, 'erro');
            }
            pn_redirecionar('v=ponto&ok=' . ($acao === 'saida_aceitar' ? 'sd_ok' : 'sd_rec'));
        case 'col_importar':
            $texto = mcp_importar_texto($_POST['texto'] ?? '', 200000);
            $conferido = mcp_colaboradores_importar_conferir($texto);
            if (($_POST['etapa'] ?? '') !== 'importar') {
                pc_importar($sessao, '', 'ok', $texto, $conferido);
            }
            $comWhatsapp = array_filter($conferido['linhas'], static fn(array $l): bool => $l['ok'] && !empty($l['whatsapp']));
            if ($comWhatsapp && empty($_POST['consentimento'])) {
                pc_importar($sessao, 'Confirme que as pessoas marcadas com WhatsApp "sim" autorizaram receber lembretes por WhatsApp, ou tire o "sim".', 'erro', $texto, $conferido, 'consentimento');
            }
            $r = mcp_colaboradores_importar($conferido['linhas'], $sessao);
            mcp_registrar(null, 'painel_importar', "$sessao · {$r['criados']} novos · {$r['atualizados']} atualizados · {$r['erros']} com erro");
            pc_importar($sessao, "Pronto: {$r['criados']} cadastrados, {$r['atualizados']} atualizados" . ($r['erros'] ? ", {$r['erros']} linhas com erro ficaram de fora" : '') . '.', 'ok');
        case 'col_avisos':
            $c = mcp_colaborador_por_id($id);
            if (!$c) {
                pn_redirecionar('v=ponto');
            }
            $whatsapp = !empty($_POST['whatsapp']) && mcp_whatsapp_numero((string) $c['telefone']) !== null;
            $como = mcp_texto($_POST['como'] ?? '', 160);
            // A autorização do WhatsApp marcada pela secretaria precisa dizer como a pessoa autorizou (a prova do consentimento).
            if ($whatsapp && (int) $c['aviso_whatsapp'] !== 1 && mb_strlen($como) < 4) {
                pn_colaborador($sessao, $id, 'Diga como a pessoa autorizou o WhatsApp (ex.: formulário assinado, pessoalmente na recepção).', 'erro');
            }
            mcp_avisos_preferencias_salvar($c, [
                'email' => filter_var((string) $c['email'], FILTER_VALIDATE_EMAIL) ? !empty($_POST['email']) : (int) $c['aviso_email'] === 1, 'whatsapp' => $whatsapp,
                'dias' => (array) ($_POST['dias'] ?? []), 'saida' => !empty($_POST['saida']), 'comunicados' => !empty($_POST['comunicados']), 'como' => $como,
            ], $sessao, false);
            pn_redirecionar('v=colaborador&id=' . $id . '&ok=ca_ok#lembretes');
        case 'col_links_novos':
            $c = mcp_colaborador_por_id($id);
            if ($c) {
                mcp_avisos_chave_colaborador($c, true);
                mcp_registrar(null, 'painel_links_novos', "#$id · $sessao");
            }
            pn_redirecionar('v=colaborador&id=' . $id . '&ok=cl_novos#lembretes');
        case 'col_link':
            $c = mcp_colaborador_por_id($id);
            if (!$c || !(int) $c['ativo'] || !filter_var((string) $c['email'], FILTER_VALIDATE_EMAIL)) {
                pn_redirecionar('v=colaborador&id=' . $id . '#lembretes');
            }
            $avisoId = mcp_aviso_criar(['chave' => 'link|' . $id . '|' . $agora . '|' . bin2hex(random_bytes(3)), 'tipo' => 'link', 'canal' => 'email', 'colaborador_id' => $id,
                'pessoa' => 'c' . $id, 'nome' => (string) $c['nome'], 'destino' => mb_strtolower((string) $c['email']), 'expira_em' => gmdate('Y-m-d H:i:s', $agora + 3 * 86400)], $agora);
            if ($avisoId !== null && mcp_avisos_na_janela($agora)) {
                mcp_aviso_enviar(mcp_aviso_por_id($avisoId), $agora);
                pn_redirecionar('v=colaborador&id=' . $id . '&ok=cl_ok#lembretes');
            }
            pn_redirecionar('v=colaborador&id=' . $id . '&ok=cl_fila#lembretes');
    }
    pn_redirecionar('v=comunicacao');
}
