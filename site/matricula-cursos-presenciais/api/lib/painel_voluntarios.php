<?php
/**
 * Portal da secretaria (api/painel.php), 10/2026: seção Voluntários, as fichas de cadastro preenchidas em
 * /voluntariado/cadastro/ (lib/voluntarios.php). Só o painel.php carrega este arquivo (lib.php não), porque usa
 * as funções de página dele (pn_pagina, pn_e, pn_data, pn_redirecionar, pn_login).
 *
 * Telas:
 *  - Lista (?v=voluntarios): chips por situação com a contagem, busca por nome, e-mail, protocolo ou cidade,
 *    filtro por frente, 50 por página e a planilha (CSV sem CPF) do que está filtrado;
 *  - Ficha (?v=voluntario&id=): tudo o que a pessoa preencheu, em grupos, o CPF só com os 3 primeiros dígitos,
 *    os consentimentos (LGPD) com data e revisão da política, a origem, os e-mails que saíram, o PDF e a
 *    planilha no Drive, o histórico (notas e mudanças de situação) e as ações: em análise, entrevista marcada,
 *    aceitar (cria o colaborador do ponto da sede, pede o CPF se a ficha veio sem), recusar, arquivar, reabrir,
 *    nota, PDF da ficha e o e-mail para a pessoa (mailto, com o protocolo no assunto).
 * Cada ação é um formulário POST com o token do portal (mcp_painel_csrf), conferido em painel.php antes de
 * chegar a pv_post(). Quem está logado (o e-mail da sessão) fica registrado em status_por, nas notas e nos
 * eventos. O CPF inteiro nunca sai daqui: nem na lista, nem na planilha, nem na ficha.
 */
declare(strict_types=1);

/** Ações dos formulários desta parte do portal (cada uma com o token do formulário, como as outras). */
const PV_ACOES = ['voluntario_status', 'voluntario_aceitar', 'voluntario_nota', 'voluntarios_csv', 'voluntario_pdf'];
const PV_POR_PAGINA = 50;
/** Chips da lista: "todas" mais as situações do ENUM, com o rótulo no plural. */
const PV_FILTROS = ['todas' => 'Todas', 'nova' => 'Novas', 'em_analise' => 'Em análise', 'entrevista' => 'Entrevista', 'aceita' => 'Aceitas', 'recusada' => 'Recusadas', 'arquivada' => 'Arquivadas'];
/** Situações que os botões da ficha escolhem; 'aceita' só pela ação "Aceitar", que cria o colaborador do ponto. */
const PV_STATUS_BOTOES = ['em_analise', 'entrevista', 'recusada', 'arquivada'];
/** Cor do selo de cada situação: a nova pede leitura (vermelho), a aceita está resolvida (verde). */
const PV_SELOS = ['nova' => 'erro', 'em_analise' => 'alerta', 'entrevista' => 'info', 'aceita' => 'ok', 'recusada' => 'neutro', 'arquivada' => 'neutro'];
const PV_AVISOS = [
    'vs_analise' => ['Ficha em análise.', 'ok'],
    'vs_entrev' => ['Entrevista marcada registrada na ficha.', 'ok'],
    'vs_recusada' => ['Ficha recusada. A pessoa não é avisada sozinha: se quiser, escreva o e-mail pelo botão da ficha.', 'ok'],
    'vs_arquivada' => ['Ficha arquivada.', 'ok'],
    'vs_reaberta' => ['Ficha reaberta: voltou para "Em análise".', 'ok'],
    'va_ok' => ['Ficha aceita. O cadastro no ponto da sede está pronto: abra a ficha do colaborador (link em "Situação e ações") para imprimir o termo de adesão.', 'ok'],
    'vn_ok' => ['Nota adicionada ao histórico.', 'ok'],
];

// ----------------------------------------------------------------------------- utilidades de página
/** Formulário POST com a ação, o id e o token (amarrado a quem está logado, à ação e ao id). */
function pv_form(string $usuario, string $acao, int $id, string $conteudo, string $atributos = ''): string
{
    return '<form method="post" action="painel.php"' . $atributos . '><input type="hidden" name="acao" value="' . pn_e($acao) . '"><input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="t" value="' . pn_e(mcp_painel_csrf($usuario, $acao, $id)) . '">' . $conteudo . '</form>';
}

function pv_selo(string $status): string
{
    return '<span class="selo ' . (PV_SELOS[$status] ?? 'neutro') . '">' . pn_e(mcp_voluntario_status_rotulo($status)) . '</span>';
}

/** Lista rótulo/valor (o valor já vem em HTML). */
function pv_dl(array $linhas): string
{
    $html = '';
    foreach ($linhas as $rotulo => $valor) {
        $html .= '<dt>' . pn_e((string) $rotulo) . '</dt><dd>' . $valor . '</dd>';
    }
    return '<dl>' . $html . '</dl>';
}

function pv_vazio(string $texto = 'não informado'): string
{
    return '<span style="color:var(--muted);font-weight:400">' . pn_e($texto) . '</span>';
}

/**
 * Quantas fichas há em cada situação (e no total) dentro da busca e da frente atuais, para os chips. Uma consulta
 * só, com o mesmo WHERE da lista (mcp_voluntarios_filtro_sql), menos o status.
 *
 * @return array<string, int> chave do chip => contagem
 */
function pv_contagens(array $filtros): array
{
    [$onde, $params] = mcp_voluntarios_filtro_sql(array_diff_key($filtros, ['status' => 1]));
    $stmt = mcp_db()->prepare("SELECT status, COUNT(*) AS n FROM mcp_voluntarios$onde GROUP BY status");
    $stmt->execute($params);
    $n = array_fill_keys(array_keys(mcp_voluntarios_status()), 0);
    foreach ($stmt->fetchAll() as $l) {
        $n[(string) $l['status']] = (int) $l['n'];
    }
    return ['todas' => array_sum($n)] + $n;
}

/** Cartão do Início: as fichas novas (até 6, as mais recentes) e o caminho para a lista. */
function pv_cartao_inicio(): string
{
    $novas = mcp_voluntarios_novas_contar();
    $linhas = '';
    foreach (mcp_voluntarios_listar(['status' => 'nova'], 1, 6)['linhas'] as $l) {
        $linhas .= '<li><div><a href="painel.php?v=voluntario&amp;id=' . (int) $l['id'] . '">' . pn_e((string) $l['nome']) . '</a><small>'
            . pn_e(mcp_voluntario_rotulos((string) $l['frentes'], mcp_voluntarios_frentes())) . ' · ' . pn_e((string) $l['cidade']) . ' · ' . pn_e(pn_data((string) $l['criado_em'])) . '</small></div>'
            . pv_selo((string) $l['status']) . '</li>';
    }
    return '<div class="cartao" style="margin-top:18px"><h2>Fichas de voluntariado' . ($novas > 0 ? ' <small>' . $novas . ($novas === 1 ? ' nova' : ' novas') . '</small>' : '') . '</h2>'
        . ($linhas !== '' ? '<ul class="lista-curta">' . $linhas . '</ul>' : '<p class="nota">Nenhuma ficha nova esperando leitura.</p>')
        . '<p class="nota"><a href="painel.php?v=voluntarios">Ver todas as fichas →</a></p></div>';
}

// ----------------------------------------------------------------------------- lista
/** Lista das fichas: chips por situação, busca, frente, paginação e a planilha do que está filtrado. */
function pv_lista(string $usuario, string $aviso = '', string $classe = 'ok'): never
{
    $q = mcp_texto($_GET['q'] ?? '', 120);
    $frente = mcp_texto($_GET['frente'] ?? '', 40);
    if (!isset(mcp_voluntarios_frentes()[$frente])) {
        $frente = '';
    }
    $contagens = pv_contagens(['busca' => $q, 'frente' => $frente]);
    $f = mcp_texto($_GET['f'] ?? '', 12);
    if (!isset(PV_FILTROS[$f])) {
        // Sem chip escolhido: as novas, quando há (é o que pede leitura); com busca ou sem novas, todas.
        $f = $q === '' && $frente === '' && $contagens['nova'] > 0 ? 'nova' : 'todas';
    }
    $pagina = min(max(1, (int) ($_GET['p'] ?? 1)), 10000);
    $filtros = ['status' => $f === 'todas' ? null : $f, 'busca' => $q, 'frente' => $frente];
    $r = mcp_voluntarios_listar($filtros, $pagina, PV_POR_PAGINA);
    $total = (int) $r['total'];
    $paginas = max(1, (int) ceil($total / PV_POR_PAGINA));
    $params = array_filter(['v' => 'voluntarios', 'f' => $f, 'q' => $q, 'frente' => $frente], static fn(string $x): bool => $x !== '');
    $url = static fn(array $extra): string => 'painel.php?' . http_build_query(array_merge($params, $extra));

    $pilulas = '';
    foreach (PV_FILTROS as $chave => $rotulo) {
        $pilulas .= '<a class="pilula' . ($chave === $f ? ' ativo' : '') . '" href="' . pn_e($url(['f' => $chave, 'p' => null])) . '">' . pn_e($rotulo) . ' <span class="n">' . $contagens[$chave] . '</span></a>';
    }
    $opcoes = '<option value="">Todas as frentes</option>';
    foreach (mcp_voluntarios_frentes() as $chave => $rotulo) {
        $opcoes .= '<option value="' . pn_e($chave) . '"' . ($chave === $frente ? ' selected' : '') . '>' . pn_e($rotulo) . '</option>';
    }
    $busca = '<form class="busca" method="get" action="painel.php" role="search"><input type="hidden" name="v" value="voluntarios"><input type="hidden" name="f" value="' . pn_e($f) . '">'
        . '<label class="sr" for="pv-q">Buscar</label><input id="pv-q" name="q" value="' . pn_e($q) . '" placeholder="Nome, e-mail, protocolo ou cidade">'
        . '<label class="sr" for="pv-frente">Frente</label><select id="pv-frente" name="frente">' . $opcoes . '</select>'
        . '<button class="btn btn-outline" type="submit">Buscar</button>'
        . ($q !== '' || $frente !== '' ? '<a class="btn btn-outline" href="' . pn_e($url(['q' => null, 'frente' => null, 'p' => null])) . '">Limpar</a>' : '') . '</form>';

    $tabela = '';
    foreach ($r['linhas'] as $l) {
        $ficha = 'painel.php?v=voluntario&amp;id=' . (int) $l['id'];
        $idade = mcp_voluntario_idade((string) $l['data_nascimento']);
        $tabela .= '<tr><td data-rotulo="Recebida">' . pn_e(pn_data((string) $l['criado_em'])) . '</td>'
            . '<td data-rotulo="Protocolo">' . pn_e((string) ($l['protocolo'] ?: '—')) . '</td>'
            . '<td class="aluno"><a class="nome" href="' . $ficha . '">' . pn_e((string) $l['nome']) . '</a><small>' . pn_e((string) $l['email']) . '</small></td>'
            . '<td data-rotulo="Idade">' . ($idade !== null ? $idade . ' anos' . ($idade < MCP_VOL_MAIORIDADE ? '<small>menor de 18</small>' : '') : '—') . '</td>'
            . '<td data-rotulo="Cidade">' . pn_e((string) $l['cidade']) . '<small>' . pn_e(trim((!empty($l['bairro']) ? $l['bairro'] . ' · ' : '') . (string) $l['estado'])) . '</small></td>'
            . '<td data-rotulo="Frentes">' . pn_e(mcp_voluntario_rotulos((string) $l['frentes'], mcp_voluntarios_frentes())) . '</td>'
            . '<td data-rotulo="Situação">' . pv_selo((string) $l['status']) . ($l['status_em'] ? '<small>' . pn_e(pn_data((string) $l['status_em'])) . '</small>' : '') . '</td>'
            . '<td class="abrir"><a class="btn btn-outline pc-mini" href="' . $ficha . '">Abrir ficha</a></td></tr>';
    }
    // A planilha sai por POST com o token, com os filtros desta tela.
    $baixar = $total > 0 ? pv_form($usuario, 'voluntarios_csv', 0, '<input type="hidden" name="status" value="' . pn_e($f === 'todas' ? '' : $f) . '">'
        . '<input type="hidden" name="busca" value="' . pn_e($q) . '"><input type="hidden" name="frente" value="' . pn_e($frente) . '">'
        . '<button class="btn btn-outline" type="submit">Baixar planilha</button>') : '';
    $paginacao = '';
    if ($paginas > 1) {
        $paginacao = '<div class="paginacao">' . ($pagina > 1 ? '<a href="' . pn_e($url(['p' => $pagina - 1])) . '">← Anterior</a>' : '')
            . '<span style="color:var(--muted)">Página ' . $pagina . ' de ' . $paginas . ' · ' . $total . ' fichas</span>'
            . ($pagina < $paginas ? '<a href="' . pn_e($url(['p' => $pagina + 1])) . '">Próxima →</a>' : '') . '</div>';
    }
    $vazio = $q !== '' || $frente !== ''
        ? 'Nenhuma ficha com essa busca. <a href="' . pn_e($url(['q' => null, 'frente' => null, 'p' => null])) . '">Limpar a busca</a>'
        : 'Nenhuma ficha aqui.';
    $corpo = '<div class="cabeca"><div><p class="eyebrow">Voluntariado</p><h1>Fichas de voluntariado</h1>'
        . '<p class="nota">As fichas preenchidas em cruzvermelhariodejaneiro.org/voluntariado/cadastro. Abra cada uma para ler, anotar, marcar a entrevista e aceitar; aceitar cria o cadastro da pessoa no ponto da sede. '
        . 'A pessoa já foi avisada de que a equipe responde por e-mail em até ' . MCP_EMAIL_PRAZO . '. As fichas não aceitas são apagadas depois de 12 meses, como diz a Política de Privacidade.</p></div>'
        . ($baixar !== '' ? '<div class="acoes" style="margin-top:0">' . $baixar . '</div>' : '') . '</div>'
        . ($aviso !== '' ? '<div class="aviso ' . pn_e($classe) . '">' . pn_e($aviso) . '</div>' : '')
        . '<div class="filtros">' . $pilulas . '</div>' . $busca
        . '<div class="tabela rolagem"><table class="respostas"><thead><tr><th>Recebida</th><th>Protocolo</th><th>Nome</th><th>Idade</th><th>Cidade</th><th>Frentes</th><th>Situação</th><th class="abrir"></th></tr></thead><tbody>'
        . ($tabela !== '' ? $tabela : '<tr><td colspan="8" class="vazio">' . $vazio . '</td></tr>') . '</tbody></table></div>' . $paginacao;
    pn_pagina('Voluntários', $corpo, $usuario, true, 'voluntarios');
}

// ----------------------------------------------------------------------------- ficha
/**
 * Histórico da ficha em ordem cronológica: a chegada, as mudanças de situação (eventos 'voluntario_status' que
 * lib/voluntarios.php grava como "#id · antes → depois · quem[ · colaborador #N]") e as notas.
 *
 * @return list<array{quando: string, texto: string, quem: ?string}> quem só nas notas
 */
function pv_historico(array $f): array
{
    $id = (int) $f['id'];
    $itens = [['quando' => (string) $f['criado_em'], 'texto' => 'Ficha recebida pelo site' . (!empty($f['pagina']) ? ' (' . $f['pagina'] . ')' : '') . '.', 'quem' => null]];
    $stmt = mcp_db()->prepare("SELECT detalhe, criado_em FROM mcp_eventos WHERE tipo = 'voluntario_status' AND detalhe LIKE ? ORDER BY criado_em, id");
    $stmt->execute(["#$id · %"]);
    $rotulos = mcp_voluntarios_status();
    foreach ($stmt->fetchAll() as $e) {
        $detalhe = (string) $e['detalhe'];
        if (preg_match('/^#\d+ · (\S+) → (\S+) · (.*)$/u', $detalhe, $m)) {
            $texto = 'Situação: ' . ($rotulos[$m[1]] ?? $m[1]) . ' → ' . ($rotulos[$m[2]] ?? $m[2]) . ' · por ' . $m[3];
        } else {
            $texto = $detalhe;
        }
        $itens[] = ['quando' => (string) $e['criado_em'], 'texto' => $texto, 'quem' => null];
    }
    foreach (mcp_voluntario_notas($id) as $n) {
        $itens[] = ['quando' => (string) $n['criado_em'], 'texto' => (string) $n['texto'], 'quem' => (string) $n['quem']];
    }
    // A ordenação do PHP é estável: no mesmo segundo, a mudança de situação fica antes da nota que veio com ela.
    usort($itens, static fn(array $a, array $b): int => strcmp($a['quando'], $b['quando']));
    return $itens;
}

/**
 * Ficha de uma pessoa, com os grupos de dados, o histórico e as ações. $abrir = 'aceitar' deixa aberto o bloco
 * da aceitação (depois de um erro nele, como o CPF que faltou).
 */
function pv_ficha(string $usuario, int $id, string $aviso = '', string $classe = 'ok', string $abrir = ''): never
{
    $f = $id > 0 ? mcp_voluntario_por_id($id) : null;
    if (!$f) {
        pn_redirecionar('v=voluntarios');
    }
    $protocolo = (string) ($f['protocolo'] ?: "ficha #$id");
    $status = (string) $f['status'];
    $menor = mcp_voluntario_menor($f);
    $idade = mcp_voluntario_idade((string) $f['data_nascimento']);
    $cpfParcial = mcp_voluntario_cpf_parcial($f['cpf'] ?? null);
    $email = (string) $f['email'];
    $mailto = 'mailto:' . $email . '?subject=' . rawurlencode("Cruz Vermelha RJ · voluntariado · protocolo $protocolo");

    // Dados, em grupos.
    $pessoais = pv_dl([
        'Nome' => pn_e((string) $f['nome']),
        'Nascimento' => pn_e(mcp_voluntario_nascimento_texto($f)) . ($idade !== null ? ' · ' . $idade . ' anos' . ($menor ? ' <span class="selo alerta">menor de 18</span>' : '') : ''),
        'CPF' => $cpfParcial !== '' ? '<span title="O portal mostra só os 3 primeiros dígitos">' . pn_e($cpfParcial) . '</span>' : pv_vazio('não informado (pede-se na aceitação)'),
    ]);
    $telefone = (string) $f['telefone'];
    $contato = pv_dl([
        'E-mail' => '<a href="mailto:' . pn_e($email) . '">' . pn_e($email) . '</a>',
        'Telefone' => $telefone !== '' ? '<a href="tel:+55' . pn_e(mcp_digitos($telefone)) . '">' . pn_e(mcp_telefone_bonito($telefone)) . '</a>' : pv_vazio(),
        'Onde mora' => pn_e(mcp_voluntario_local_texto($f)),
        'Aceita contato por e-mail e telefone' => (int) ($f['aceita_contato'] ?? 0) === 1 ? 'Sim' : 'Não',
    ]);
    $perfil = pv_dl([
        'Escolaridade' => pn_e(mcp_voluntarios_escolaridades()[$f['escolaridade']] ?? (string) $f['escolaridade']),
        'Profissão' => !empty($f['profissao']) ? pn_e((string) $f['profissao']) : pv_vazio('não informada'),
        'Formação na área da saúde' => (int) ($f['saude'] ?? 0) === 1 ? 'Sim' . (!empty($f['saude_qual']) ? ': ' . pn_e((string) $f['saude_qual']) : '') : 'Não',
        'Como conheceu' => !empty($f['como_conheceu']) ? pn_e(mcp_voluntarios_como_conheceu()[$f['como_conheceu']] ?? (string) $f['como_conheceu']) : pv_vazio(),
    ]);
    $ajudar = pv_dl([
        'Frentes' => pn_e(mcp_voluntario_rotulos((string) $f['frentes'], mcp_voluntarios_frentes())),
        'Dias' => pn_e(mcp_voluntario_rotulos((string) $f['dias'], mcp_voluntarios_dias())),
        'Períodos' => pn_e(mcp_voluntario_rotulos((string) $f['periodos'], mcp_voluntarios_periodos())),
    ]);
    // Textos da pessoa: escapados, com as quebras de linha dela (nl2br); o white-space normal evita a quebra dobrada.
    $texto = static fn(string $t): string => '<p class="mensagem" style="white-space:normal;font-size:.95rem">' . nl2br(pn_e($t), false) . '</p>';
    $sobre = '<p class="pc-rotulo" style="margin-top:0">Motivação</p>' . $texto((string) $f['motivacao'])
        . (!empty($f['experiencia']) ? '<p class="pc-rotulo">Experiência com voluntariado</p>' . $texto((string) $f['experiencia']) : '')
        . (!empty($f['habilidades']) ? '<p class="pc-rotulo">Habilidades e formação</p>' . $texto((string) $f['habilidades']) : '');
    $consentimentos = [];
    foreach (mcp_voluntario_consentimentos($f) as [$rotulo, $valor]) {
        $consentimentos[$rotulo] = pn_e($valor);
    }
    $consentimentos['Guarda dos dados'] = 'Só para a seleção e o acompanhamento do voluntariado. Ficha não aceita é apagada depois de 12 meses; a aceita segue o termo de adesão.';
    $origem = array_filter([
        'Página' => !empty($f['pagina']) ? '<a href="' . pn_e(mcp_site_url() . (string) $f['pagina']) . '" target="_blank" rel="noopener">' . pn_e((string) $f['pagina']) . '</a>' : null,
        'Origem' => pn_e(trim((string) ($f['utm_source'] ?? '') . ' ' . (string) ($f['utm_campaign'] ?? '')) ?: 'direto (sem UTM)'),
        'utm_source' => !empty($f['utm_source']) ? pn_e((string) $f['utm_source']) : null,
        'utm_medium' => !empty($f['utm_medium']) ? pn_e((string) $f['utm_medium']) : null,
        'utm_campaign' => !empty($f['utm_campaign']) ? pn_e((string) $f['utm_campaign']) : null,
        'utm_content' => !empty($f['utm_content']) ? pn_e((string) $f['utm_content']) : null,
        'utm_term' => !empty($f['utm_term']) ? pn_e((string) $f['utm_term']) : null,
        'Clique em anúncio' => !empty($f['fbclid']) || !empty($f['gclid']) ? pn_e(implode(' e ', array_filter([!empty($f['fbclid']) ? 'Meta (fbclid)' : '', !empty($f['gclid']) ? 'Google (gclid)' : '']))) : null,
        'IP' => !empty($f['ip']) ? pn_e((string) $f['ip']) : null,
    ], static fn(?string $v): bool => $v !== null);
    $envio = static fn(?string $r): string => match ((string) $r) {
        'resend' => '<span class="selo ok">enviado</span> pela Resend',
        'mail' => '<span class="selo ok">enviado</span> pelo servidor do site',
        'falhou' => '<span class="selo erro">falhou</span>',
        '' => pv_vazio('—'),
        default => pn_e((string) $r),
    };
    $envios = '<p class="pc-rotulo" style="margin-top:0">E-mails enviados</p>' . pv_dl([
        'Aviso à equipe de voluntariado' => $envio($f['email_equipe'] ?? null),
        'Confirmação à pessoa' => $envio($f['email_confirmacao'] ?? null),
    ]) . '<p class="pc-rotulo">Google Drive</p>' . pv_dl([
        'PDF da ficha' => !empty($f['drive_pdf_id']) ? '<a href="https://drive.google.com/file/d/' . pn_e(rawurlencode((string) $f['drive_pdf_id'])) . '/view" target="_blank" rel="noopener">Abrir no Drive ↗</a>' : pv_vazio('ainda não enviado'),
        'Linha na planilha' => !empty($f['drive_planilha_em']) ? 'acrescentada em ' . pn_e(pn_data((string) $f['drive_planilha_em'])) : pv_vazio('ainda não'),
    ]) . '<p class="nota">O PDF e a linha da planilha vão para o Drive pela rotina do site, minutos depois da ficha chegar (quando a ligação com o Drive está configurada).</p>';

    // Situação e ações.
    $situacao = '<p style="margin:0 0 6px">' . pv_selo($status) . (!empty($f['status_em']) ? ' <small style="color:var(--muted)">desde ' . pn_e(pn_data((string) $f['status_em']))
        . (!empty($f['status_por']) ? ' · por ' . pn_e((string) $f['status_por']) : '') . '</small>' : '') . '</p>';
    if (!empty($f['colaborador_id'])) {
        $situacao .= '<p class="nota" style="margin:0 0 10px"><b>Cadastro no ponto da sede:</b> <a href="painel.php?v=colaborador&amp;id=' . (int) $f['colaborador_id'] . '">Abrir a ficha do colaborador #' . (int) $f['colaborador_id'] . ' →</a>'
            . ' É lá que se imprime e registra o termo de adesão' . ($menor ? ' (assinado também pelo responsável legal)' : '') . '.</p>';
    }
    $botao = static fn(string $novo, string $rotulo, string $classeBotao = 'btn-outline'): string => pv_form($usuario, 'voluntario_status', $id,
        '<input type="hidden" name="status" value="' . $novo . '"><button class="btn ' . $classeBotao . ' pc-mini" type="submit">' . pn_e($rotulo) . '</button>');
    $comNota = static fn(string $novo, string $rotulo, string $explica, string $classeBotao = 'btn-outline'): string => '<details class="ajustar"' . ($abrir === $novo ? ' open' : '') . '><summary>' . pn_e($rotulo) . '</summary>'
        . pv_form($usuario, 'voluntario_status', $id, '<div class="mini"><input type="hidden" name="status" value="' . $novo . '">'
            . ($explica !== '' ? '<p class="nota" style="margin:0">' . $explica . '</p>' : '')
            . '<label>Nota (opcional)<input name="nota" maxlength="4000" placeholder="Ex.: dia, hora e lugar da entrevista; o motivo"></label>'
            . '<button class="btn ' . $classeBotao . '" type="submit">' . pn_e($rotulo) . '</button></div>') . '</details>';
    $aceitar = '<details class="ajustar"' . ($abrir === 'aceitar' ? ' open' : '') . '><summary>Aceitar (cria o cadastro no ponto da sede)</summary>'
        . pv_form($usuario, 'voluntario_aceitar', $id, '<div class="mini">'
            . '<p class="nota" style="margin:0">A pessoa passa a registrar entrada e saída no ponto da sede com o CPF, com vínculo de voluntário(a). Depois, imprima o termo de adesão na ficha do colaborador'
            . ($menor ? ', que o responsável legal também assina' : '') . '.</p>'
            . ($cpfParcial !== '' ? '<p class="nota" style="margin:0">CPF informado na ficha: <b>' . pn_e($cpfParcial) . '</b>.</p>'
                : '<label for="pv-cpf">CPF do(a) voluntário(a) (a ficha veio sem)<input id="pv-cpf" name="cpf" inputmode="numeric" maxlength="14" placeholder="000.000.000-00" autocomplete="off"></label>')
            . '<label>Nota (opcional)<input name="nota" maxlength="4000" placeholder="Ex.: entrevista feita em 20/10, aprovada pela coordenação"></label>'
            . '<button class="btn btn-red" type="submit">Aceitar a ficha</button></div>') . '</details>';
    $acoes = match ($status) {
        'nova' => '<div class="acoes" style="margin-top:0">' . $botao('em_analise', 'Em análise', 'btn-red') . '</div>'
            . $comNota('entrevista', 'Entrevista marcada', 'Anote o dia, a hora e o lugar (sede ou vídeo).') . $aceitar
            . $comNota('recusada', 'Recusar', 'A pessoa não é avisada sozinha: escreva o e-mail pelo botão "Escrever e-mail".') . $comNota('arquivada', 'Arquivar', 'Para a ficha que não vai adiante (sem resposta, desistiu).'),
        'em_analise' => $comNota('entrevista', 'Entrevista marcada', 'Anote o dia, a hora e o lugar (sede ou vídeo).') . $aceitar
            . $comNota('recusada', 'Recusar', 'A pessoa não é avisada sozinha: escreva o e-mail pelo botão "Escrever e-mail".') . $comNota('arquivada', 'Arquivar', 'Para a ficha que não vai adiante (sem resposta, desistiu).'),
        'entrevista' => '<div class="acoes" style="margin-top:0">' . $botao('em_analise', 'Voltar para em análise') . '</div>' . $aceitar
            . $comNota('recusada', 'Recusar', 'A pessoa não é avisada sozinha: escreva o e-mail pelo botão "Escrever e-mail".') . $comNota('arquivada', 'Arquivar', 'Para a ficha que não vai adiante (sem resposta, desistiu).'),
        'recusada', 'arquivada' => '<div class="acoes" style="margin-top:0">' . $botao('em_analise', 'Reabrir', 'btn-red') . '</div><p class="nota">Reabrir volta a ficha para "Em análise".</p>',
        default => '<p class="nota">Ficha aceita: o acompanhamento segue na ficha do colaborador, no ponto da sede.</p>',
    };
    $nota = pv_form($usuario, 'voluntario_nota', $id, '<label for="pv-nota">Nova nota</label><textarea id="pv-nota" name="nota" required maxlength="4000" style="min-height:90px" placeholder="Ex.: Liguei; combinamos a entrevista para quinta às 15h."></textarea>'
        . '<div class="acoes"><button class="btn btn-outline" type="submit">Adicionar nota</button></div>');

    // Histórico.
    $tempo = '';
    foreach (pv_historico($f) as $h) {
        $tempo .= '<li><time>' . pn_e(pn_data($h['quando'])) . '</time><span>'
            . ($h['quem'] !== null ? '<b>Nota de ' . pn_e($h['quem']) . '</b><br>' . nl2br(pn_e($h['texto']), false) : pn_e($h['texto'])) . '</span></li>';
    }

    $topo = '<a class="voltar" href="painel.php?v=voluntarios">← Voltar para as fichas de voluntariado</a>'
        . ($aviso !== '' ? '<div class="aviso ' . pn_e($classe) . '">' . pn_e($aviso) . '</div>' : '')
        . '<div class="cabeca"><div><p class="eyebrow">Voluntariado · ' . pn_e($protocolo) . '</p><h1>' . pn_e((string) $f['nome']) . '</h1>'
        . '<p class="nota">' . pv_selo($status) . ' Ficha recebida em ' . pn_e(pn_data((string) $f['criado_em'])) . ' (Brasília)' . ($idade !== null ? ' · ' . $idade . ' anos' : '') . ' · ' . pn_e(mcp_voluntario_local_texto($f)) . '</p></div>'
        . '<div class="acoes" style="margin-top:0">' . pv_form($usuario, 'voluntario_pdf', $id, '<button class="btn btn-outline" type="submit">Baixar PDF da ficha</button>')
        . '<a class="btn btn-outline" href="' . pn_e($mailto) . '">Escrever e-mail</a></div></div>'
        . ($menor ? '<div class="orientacao" role="note" style="background:#fff7e6;border:1px solid #f6d7a7;color:#6b4400"><b>Menor de 18 anos.</b> Pode ser voluntário(a): o termo de adesão é assinado também pelo responsável legal (Lei 9.608/1998). Combine isso na entrevista.</div>' : '');
    $corpo = $topo
        . '<div class="ficha">'
        . '<div class="cartao"><h2>Situação e ações</h2>' . $situacao . $acoes . '</div>'
        . '<div class="cartao"><h2>Dados pessoais</h2>' . $pessoais . '</div>'
        . '<div class="cartao"><h2>Contato</h2>' . $contato . '</div>'
        . '<div class="cartao"><h2>Perfil</h2>' . $perfil . '</div>'
        . '<div class="cartao"><h2>Como quer ajudar</h2>' . $ajudar . '</div>'
        . '<div class="cartao"><h2>Consentimentos (LGPD)</h2>' . pv_dl($consentimentos) . '</div>'
        . '</div>'
        . '<div class="cartao" style="margin-top:18px"><h2>Sobre a pessoa</h2>' . $sobre . '</div>'
        . '<div class="ficha">'
        . '<div class="cartao"><h2>Origem</h2>' . pv_dl($origem) . '</div>'
        . '<div class="cartao"><h2>E-mails e Drive</h2>' . $envios . '</div>'
        . '</div>'
        . '<div class="cartao" id="historico" style="margin-top:18px"><h2>Histórico</h2><ul class="tempo">' . $tempo . '</ul>'
        . '<h2 style="margin-top:18px">Adicionar nota</h2>' . $nota . '</div>';
    pn_pagina((string) $f['nome'], $corpo, $usuario, true, 'voluntarios');
}

// ----------------------------------------------------------------------------- formulários (POST)
/** Trata as ações desta parte do portal. Quem chama já conferiu a sessão e o token do formulário. */
function pv_post(string $sessao, string $acao, int $id): never
{
    if ($acao === 'voluntarios_csv') {
        // Os mesmos filtros da lista; o CSV (lib/voluntarios.php) nunca leva CPF nem IP.
        $filtros = ['status' => mcp_texto($_POST['status'] ?? '', 20) ?: null, 'busca' => mcp_texto($_POST['busca'] ?? '', 120), 'frente' => mcp_texto($_POST['frente'] ?? '', 40)];
        mcp_registrar(null, 'painel_voluntarios_csv', $sessao . ' · ' . ($filtros['status'] ?? 'todas') . ($filtros['busca'] !== '' ? ' · com busca' : '') . ($filtros['frente'] !== '' ? ' · ' . $filtros['frente'] : ''));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="fichas-voluntarios-' . mcp_voluntario_hoje() . '.csv"');
        echo mcp_voluntarios_csv($filtros);
        exit;
    }
    $f = $id > 0 ? mcp_voluntario_por_id($id) : null;
    if (!$f) {
        pn_redirecionar('v=voluntarios');
    }
    $nota = mcp_texto_longo($_POST['nota'] ?? '', 4000);
    switch ($acao) {
        case 'voluntario_pdf':
            mcp_registrar(null, 'painel_voluntario_pdf', "#$id · $sessao");
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . mcp_voluntario_pdf_arquivo($f) . '"');
            echo mcp_voluntario_pdf($f);
            exit;
        case 'voluntario_status':
            $status = mcp_texto($_POST['status'] ?? '', 20);
            if (!in_array($status, PV_STATUS_BOTOES, true)) {
                pv_ficha($sessao, $id, $status === 'aceita' ? 'Para aceitar, use a ação "Aceitar": ela cria o cadastro no ponto da sede.' : 'Situação desconhecida.', 'erro');
            }
            $antes = (string) $f['status'];
            $erro = mcp_voluntario_mudar_status($id, $status, $sessao, $nota);
            if ($erro !== null) {
                pv_ficha($sessao, $id, $erro, 'erro', $status);
            }
            $codigo = match ($status) {
                'em_analise' => in_array($antes, ['recusada', 'arquivada'], true) ? 'vs_reaberta' : 'vs_analise',
                'entrevista' => 'vs_entrev',
                'recusada' => 'vs_recusada',
                default => 'vs_arquivada',
            };
            pn_redirecionar("v=voluntario&id=$id&ok=$codigo");
        case 'voluntario_aceitar':
            $r = mcp_voluntario_aceitar($id, $sessao, mcp_texto($_POST['cpf'] ?? '', 20));
            if (!$r['ok']) {
                pv_ficha($sessao, $id, (string) $r['erro'], 'erro', 'aceitar');
            }
            if ($nota !== '') {
                mcp_voluntario_nota_registrar($id, $sessao, $nota);
            }
            mcp_registrar(null, 'painel_voluntario_aceitar', "#$id · colaborador #{$r['colaborador_id']} · $sessao");
            pn_redirecionar("v=voluntario&id=$id&ok=va_ok");
        case 'voluntario_nota':
            if ($nota === '') {
                pv_ficha($sessao, $id, 'Escreva a nota antes de adicionar.', 'erro');
            }
            mcp_voluntario_nota_registrar($id, $sessao, $nota);
            pn_redirecionar("v=voluntario&id=$id&ok=vn_ok#historico");
    }
    pn_redirecionar('v=voluntarios');
}
