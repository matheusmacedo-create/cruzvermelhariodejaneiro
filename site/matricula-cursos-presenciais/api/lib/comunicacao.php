<?php
/**
 * Comunicação do ponto da sede (30/09/2026): o texto de cada aviso, os comunicados das três fases da
 * implantação, a opinião depois de 2 semanas, a saída informada pela própria pessoa e os avisos que
 * aparecem na tela do ponto. A fila, o envio e os canais ficam em lib/avisos.php.
 *
 * Fases da implantação (comunicados preparados no portal com "Preparar os comunicados", a partir da
 * data do lançamento; a secretaria revê o texto, manda um teste para si e agenda):
 *  - antes (5 dias antes, 10h): o que muda, por que, como funciona e o convite para escolher lembretes;
 *  - durante (no dia, 8h30): "começou hoje", com as dicas para ser rápido; aviso na tela do ponto;
 *  - depois (14 dias depois, 10h): o resumo de cada um (dias e horas doadas) e a opinião em 1 minuto;
 *    também aos alunos que confirmaram presença pelo ponto.
 *
 * Cada mensagem tem uma função pura que devolve assunto, título, html, texto, whatsapp (texto livre, para
 * a fila manual e o Make) e modelo (o modelo aprovado na Meta, para a API oficial). Os modelos estão em
 * docs/ponto-comunicacao.md, com o texto exato para cadastrar.
 */
declare(strict_types=1);

const MCP_CAMPANHA_FASES = ['antes' => 'Antes do lançamento', 'durante' => 'No dia do lançamento', 'depois' => 'Depois de 2 semanas', 'livre' => 'Avulso'];
const MCP_CAMPANHA_PUBLICOS = [
    'colaboradores' => 'Todos os colaboradores',
    'voluntarios' => 'Voluntários e diretoria',
    'outros' => 'Empregados, terceirizados e outros',
    'alunos' => 'Alunos (inscritos e com presença recente)',
];
const MCP_CAMPANHA_CANAIS = ['email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'ponto' => 'Aviso na tela do ponto'];
const MCP_CAMPANHA_BOTOES = ['lembretes' => 'Escolher meus lembretes', 'ponto' => 'Abrir o ponto', 'opiniao' => 'Responder em 1 minuto', 'nenhum' => 'Sem botão'];
const MCP_CAMPANHA_STATUS = ['rascunho' => 'Rascunho', 'agendada' => 'Agendado', 'enviando' => 'Enviando', 'enviada' => 'Enviado', 'cancelada' => 'Cancelado'];
/** Campos que o texto de um comunicado pode usar. */
const MCP_COMUNICADO_CAMPOS = [
    'primeiro_nome' => 'primeiro nome da pessoa',
    'nome' => 'nome completo',
    'data_lancamento' => 'data do lançamento por extenso (ex.: segunda-feira, 5 de outubro)',
    'data_curta' => 'data do lançamento curta (ex.: 5/10)',
    'vinculo_frase' => 'frase sobre o registro, conforme o vínculo (horas doadas ou só presença)',
    'vinculo_frase_curta' => 'a mesma frase, curta, para o WhatsApp',
    'resumo' => 'resumo das últimas 2 semanas da pessoa (dias e horas doadas)',
    'link' => 'link do botão (só no texto do WhatsApp)',
];
/** Modelos do WhatsApp (API oficial) por fase; o texto exato de cada um está em docs/ponto-comunicacao.md. */
const MCP_WHATSAPP_MODELOS = [
    'cvb_ponto_vespera' => 'Lembrete da véspera (colaboradores)',
    'cvb_ponto_saida' => 'Saída não registrada (voluntários)',
    'cvb_aula_amanha' => 'Aula de amanhã (alunos)',
    'cvb_ponto_novidade' => 'Comunicado: antes do lançamento',
    'cvb_ponto_comecou' => 'Comunicado: no dia do lançamento',
    'cvb_ponto_opiniao' => 'Comunicado: opinião depois de 2 semanas',
];
const MCP_WHATSAPP_MODELO_FASE = ['antes' => 'cvb_ponto_novidade', 'durante' => 'cvb_ponto_comecou', 'depois' => 'cvb_ponto_opiniao'];
const MCP_OPINIAO_FACILIDADE = [1 => 'Muito difícil', 2 => 'Difícil', 3 => 'Mais ou menos', 4 => 'Fácil', 5 => 'Muito fácil'];
const MCP_OPINIAO_COMO = ['aparelho' => 'No tablet da recepção', 'celular' => 'No meu celular', 'os_dois' => 'Nos dois', 'nao_usei' => 'Ainda não registrei'];
const MCP_OPINIAO_PROBLEMAS = [
    'localizacao' => 'A localização do celular não funcionou',
    'cpf' => 'O CPF não foi encontrado',
    'esqueci' => 'Esqueci de registrar a saída',
    'aparelho' => 'O tablet da recepção estava desligado ou com problema',
    'internet' => 'Internet lenta ou fora do ar',
    'nao_sabia' => 'Não sabia que precisava registrar',
    'outro' => 'Outro problema (conte abaixo)',
];
const MCP_OPINIAO_LEMBRETES = ['ajudam' => 'Ajudam', 'indiferente' => 'Não fazem diferença', 'demais' => 'Chegam demais', 'nao_recebo' => 'Não recebo lembretes'];
const MCP_OPINIAO_COMENTARIO_MAX = 1000;
/** Saída que a pessoa pode informar: entrada de outro dia, até tantos dias atrás. */
const MCP_PONTO_SAIDA_INFORMAR_DIAS = 7;

// ----------------------------------------------------------------------------- texto dos comunicados
/** Troca {campo} pelos valores (os que não existem ficam como estão, para aparecerem na prévia). */
function mcp_comunicado_trocar(string $texto, array $vars): string
{
    return (string) preg_replace_callback('/\{([a-z_]+)\}/', static fn(array $m): string => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $texto);
}

/** Uma linha do comunicado em HTML: escapada, com *negrito* do jeito do WhatsApp. */
function mcp_comunicado_linha_html(string $linha): string
{
    return (string) preg_replace('/\*([^*\n]+)\*/u', '<strong>$1</strong>', mcp_escapar($linha));
}

/** Lista numerada (círculos vermelhos), como os passos dos outros e-mails. O HTML de cada item já vem escapado. */
function mcp_comunicado_numerada(array $itens): string
{
    $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:4px 0 14px">';
    foreach (array_values($itens) as $i => $item) {
        $html .= '<tr><td width="36" valign="top" style="padding:5px 0;width:36px"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td width="24" height="24" align="center" valign="middle" bgcolor="#cc0000" style="width:24px;height:24px;border-radius:50%;background:#cc0000;color:#ffffff;font-size:12px;font-weight:800;line-height:24px">' . ($i + 1) . '</td></tr></table></td>'
            . '<td valign="top" style="padding:6px 0;font-size:15px;line-height:1.5;color:#1a202c">' . $item . '</td></tr>';
    }
    return $html . '</table>';
}

/**
 * Texto do comunicado (já com os campos trocados) em HTML: parágrafos separados por linha em branco,
 * linhas com "- " viram lista e linhas com "1. " viram lista numerada.
 */
function mcp_comunicado_html(string $texto): string
{
    $html = '';
    foreach (preg_split('/\n\s*\n/', trim($texto)) ?: [] as $bloco) {
        $intro = [];
        $itens = [];
        $numerada = null;
        foreach (explode("\n", $bloco) as $linha) {
            $linha = trim($linha);
            if ($linha === '') {
                continue;
            }
            if (preg_match('/^[-•]\s+(.+)$/u', $linha, $m)) {
                $itens[] = $m[1];
                $numerada ??= false;
            } elseif (preg_match('/^\d{1,2}[.)]\s+(.+)$/u', $linha, $m)) {
                $itens[] = $m[1];
                $numerada ??= true;
            } elseif ($itens) {
                $itens[count($itens) - 1] .= ' ' . $linha;
            } else {
                $intro[] = $linha;
            }
        }
        if ($intro) {
            $html .= mcp_p(implode('<br>', array_map('mcp_comunicado_linha_html', $intro)));
        }
        if ($itens) {
            $itensHtml = array_map('mcp_comunicado_linha_html', $itens);
            $html .= $numerada ? mcp_comunicado_numerada($itensHtml) : mcp_lista($itensHtml);
        }
    }
    return $html;
}

/** O mesmo texto para o e-mail em texto puro: sem os asteriscos do negrito. */
function mcp_comunicado_texto(string $texto): string
{
    return trim((string) preg_replace('/\*([^*\n]+)\*/u', '$1', $texto));
}

/** "segunda-feira, 5 de outubro" e "5/10" a partir de AAAA-MM-DD. */
function mcp_comunicado_data(string $iso, bool $curta = false): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return '';
    }
    if ($curta) {
        return (int) $m[3] . '/' . (int) $m[2];
    }
    return mcp_dia_semana($iso) . ', ' . ((int) $m[3] === 1 ? '1º' : (string) (int) $m[3]) . ' de ' . MCP_PONTO_MESES[(int) $m[2] - 1];
}

/**
 * Resumo das últimas 2 semanas da pessoa, para o comunicado "depois": dias e horas doadas (voluntários),
 * dias com presença (outros vínculos) ou aulas com presença (alunos).
 */
function mcp_comunicado_resumo(array $pessoa, int $agora): string
{
    $ate = mcp_ponto_hoje($agora);
    $de = mcp_avisos_dia_mais($ate, -14);
    [$deUtc, $ateUtc] = mcp_ponto_periodo_utc($de, $ate);
    if ($pessoa['publico'] === 'aluno') {
        $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_presencas WHERE status = 'valida' AND email = ? AND chegada >= ? AND chegada < ?");
        $stmt->execute([(string) ($pessoa['email'] ?? ''), $deUtc, $ateUtc]);
        $n = (int) $stmt->fetchColumn();
        return $n > 0 ? "Nestas semanas, você confirmou presença em *$n " . ($n === 1 ? 'aula' : 'aulas') . '* pelo ponto da recepção.' : '';
    }
    $c = $pessoa['colaborador'];
    $dias = [];
    $segundos = 0;
    foreach (mcp_ponto_registros($deUtc, $ateUtc, (int) $c['id']) as $r) {
        $dias[mcp_data_brt((string) $r['entrada'], 'Y-m-d')] = true;
        if ((int) $r['voluntario'] === 1 && $r['saida'] !== null) {
            $segundos += mcp_ponto_segundos($r);
        }
    }
    $n = count($dias);
    if ($n === 0) {
        return 'Ainda não vimos nenhum registro seu no ponto. Se algo atrapalhou, conte na pesquisa: é justamente isso que queremos saber.';
    }
    $diasTexto = "*$n " . ($n === 1 ? 'dia' : 'dias') . '*';
    if (mcp_ponto_voluntario($c) && $segundos >= 60) {
        return "Nestas duas semanas, você registrou $diasTexto na sede e *" . mcp_ponto_horas_texto(intdiv($segundos, 60)) . '* de horas doadas. Obrigado!';
    }
    return "Nestas duas semanas, você registrou presença na sede em $diasTexto.";
}

/** Valores dos campos de um comunicado para uma pessoa ($exemplo: resumo de exemplo, para o teste e a prévia). */
function mcp_comunicado_vars(array $pessoa, int $agora, bool $exemplo = false): array
{
    $nome = mcp_nome_proprio((string) $pessoa['nome']);
    $lancamento = mcp_ajuste('lancamento');
    $voluntario = $pessoa['publico'] === 'colaborador' && mcp_ponto_voluntario($pessoa['colaborador'] ?? []);
    $vinculo = match (true) {
        $pessoa['publico'] !== 'colaborador' => ['', ''],
        $voluntario => [
            'Para você, que é voluntário, cada hora doada fica registrada e pode virar uma declaração de horas voluntárias sempre que precisar. O registro serve para reconhecer o seu trabalho, nunca para cobrar horário.',
            'Para os voluntários, cada hora doada fica registrada e vira declaração de horas quando precisar.',
        ],
        default => [
            'Para você, o registro é só da presença na sede, pela segurança de todos. Ele não substitui o ponto oficial nem muda nada na sua jornada.',
            'Para a equipe, é só o registro da presença na sede, pela segurança de todos.',
        ],
    };
    return [
        'primeiro_nome' => mcp_primeiro_nome($nome),
        'nome' => $nome,
        'data_lancamento' => $lancamento !== '' ? mcp_comunicado_data($lancamento) : 'em breve',
        'data_curta' => $lancamento !== '' ? mcp_comunicado_data($lancamento, true) : 'em breve',
        'vinculo_frase' => $vinculo[0],
        'vinculo_frase_curta' => $vinculo[1],
        'resumo' => !$exemplo ? mcp_comunicado_resumo($pessoa, $agora) : ($pessoa['publico'] === 'aluno'
            ? 'Nestas semanas, você confirmou presença em *3 aulas* pelo ponto da recepção.'
            : ($voluntario ? 'Nestas duas semanas, você registrou *6 dias* na sede e *23h40* de horas doadas. Obrigado!' : 'Nestas duas semanas, você registrou presença na sede em *6 dias*.')),
    ];
}

/**
 * Mensagem de um comunicado para uma pessoa. $avisoId monta os links de clique (0 na prévia do portal).
 * $teste: vai para a secretaria, com o aviso de teste; $exemplo: pessoa e resumo de exemplo (teste e prévia).
 * @return array{assunto: string, titulo: string, html: string, texto: string, whatsapp: string, modelo: ?array}
 */
function mcp_campanha_mensagem(array $campanha, array $pessoa, int $avisoId, int $agora, bool $teste = false, bool $exemplo = false): array
{
    $vars = mcp_comunicado_vars($pessoa, $agora, $teste || $exemplo);
    $colaborador = $pessoa['publico'] === 'colaborador';
    $destino = ['ponto' => 'ponto', 'lembretes' => 'lembretes', 'opiniao' => 'opiniao'][$campanha['botao']] ?? null;
    if ($destino === 'lembretes' && !$colaborador) {
        $destino = null;
    }
    $link = $destino !== null ? mcp_avisos_link_clique($avisoId, $destino) : '';
    $rotulo = trim((string) $campanha['botao_rotulo']) !== '' ? (string) $campanha['botao_rotulo'] : (MCP_CAMPANHA_BOTOES[$campanha['botao']] ?? '');
    $sair = mcp_avisos_link_clique($avisoId, $colaborador ? 'lembretes' : 'sair');
    $corpoTexto = mcp_comunicado_trocar((string) $campanha['mensagem'], $vars);
    $titulo = mcp_comunicado_trocar((string) $campanha['titulo'], $vars);
    $assunto = ($teste ? '[Teste] ' : '') . mcp_comunicado_trocar((string) $campanha['assunto'], $vars);
    $privacidade = mcp_site_url() . '/privacidade/';
    $html = mcp_comunicado_html($corpoTexto)
        . ($link !== '' ? mcp_botao($link, $rotulo) : '')
        . mcp_nota('No celular, a localização só confirma que você está na sede e não fica guardada. Saiba mais na <a href="' . mcp_escapar($privacidade) . '">Política de Privacidade</a>.'
            . ' ' . ($colaborador ? 'Para escolher o que você recebe, <a href="' . mcp_escapar($sair) . '">toque aqui</a>.' : 'Não quer receber estes avisos? <a href="' . mcp_escapar($sair) . '">Toque aqui</a>.'));
    if ($teste) {
        $html = '<p style="margin:0 0 16px;padding:10px 14px;border-radius:10px;background:#fff4e5;color:#8a5200;font-size:14px"><strong>Teste.</strong> É assim que o comunicado chega. Os dados da pessoa são de exemplo.</p>' . $html;
    }
    $texto = mcp_comunicado_texto($corpoTexto) . ($link !== '' ? "\n\n$rotulo: $link" : '')
        . "\n\n" . ($colaborador ? "Para escolher o que você recebe: $sair" : "Não quer receber estes avisos? $sair");
    $whatsapp = trim(mcp_comunicado_trocar((string) $campanha['whatsapp'], $vars + ['link' => $link]));
    if ($link !== '' && !str_contains((string) $campanha['whatsapp'], '{link}')) {
        $whatsapp .= "\n\n$link";
    }
    $modeloNome = $colaborador ? (MCP_WHATSAPP_MODELO_FASE[$campanha['fase']] ?? null) : null;
    $modelo = $modeloNome === null ? null : [
        'nome' => $modeloNome,
        'parametros' => match ($campanha['fase']) {
            'antes' => [$vars['primeiro_nome'], $vars['data_lancamento']],
            'depois' => [$vars['primeiro_nome'], mcp_comunicado_texto($vars['resumo']) ?: 'Obrigado por fazer parte da Cruz Vermelha.'],
            default => [$vars['primeiro_nome']],
        },
        'botao' => $destino !== null && $destino !== 'ponto' ? mcp_avisos_sufixo_clique($avisoId, $destino) : '',
    ];
    return [
        'assunto' => $assunto,
        'titulo' => $titulo,
        'html' => mcp_moldura($titulo, $html, [
            'eyebrow' => $colaborador ? 'Ponto da sede' : 'Escola de Educação e Saúde',
            'preheader' => mb_substr(mcp_comunicado_texto(str_replace("\n", ' ', $corpoTexto)), 0, 140),
            'motivo' => $colaborador
                ? 'Você recebeu este comunicado porque está cadastrado como colaborador da sede da ' . MCP_NOME_FILIAL . '.'
                : 'Você recebeu este aviso porque fez inscrição ou teve aula presencial na Escola de Educação e Saúde da ' . MCP_NOME_FILIAL . '.',
        ]),
        'texto' => $texto,
        'whatsapp' => $whatsapp,
        'modelo' => $modelo,
    ];
}

// ----------------------------------------------------------------------------- texto dos lembretes
/** "ontem (quinta, 1º/10)", "na quinta, 1º/10" ou "no sábado, 3/10" para uma data AAAA-MM-DD. */
function mcp_avisos_quando(string $iso, int $agora): string
{
    $texto = mcp_avisos_dia_texto($iso);
    if ($iso === mcp_avisos_dia_mais(mcp_ponto_hoje($agora), -1)) {
        return "ontem ($texto)";
    }
    return (in_array(mcp_avisos_dia_chave($iso), ['sab', 'dom'], true) ? 'no ' : 'na ') . $texto;
}

function mcp_montar_aviso_vespera(array $colaborador, string $dataIso, int $avisoId): array
{
    $primeiro = mcp_primeiro_nome(mcp_nome_proprio((string) $colaborador['nome']));
    $dia = mcp_avisos_dia_texto($dataIso);
    $dias = mcp_avisos_dias_texto(mcp_avisos_dias($colaborador['aviso_dias'] ?? ''));
    $ponto = mcp_avisos_link_clique($avisoId, 'ponto');
    $prefs = mcp_avisos_link_clique($avisoId, 'lembretes');
    $voluntario = mcp_ponto_voluntario($colaborador);
    $porque = $voluntario
        ? 'É assim que suas horas doadas ficam registradas e podem virar declaração de horas voluntárias quando você precisar.'
        : 'O registro serve para sabermos quem está na sede, pela segurança de todos.';
    $corpo = mcp_p('Oi, ' . mcp_escapar($primeiro) . '. ' . ($dias !== '' ? 'Você nos disse que costuma vir à sede às ' . mcp_escapar($dias) . '. ' : '')
            . 'Se vier amanhã, <strong>' . mcp_escapar($dia) . '</strong>, lembre-se do ponto:')
        . mcp_lista([
            '<strong>Ao chegar:</strong> digite seu CPF no tablet da recepção ou leia o QR code do cartaz com o celular e toque em <strong>Registrar entrada</strong>.',
            '<strong>Ao ir embora:</strong> toque em <strong>Registrar saída</strong>. Leva 10 segundos.',
        ])
        . mcp_p(mcp_escapar($porque))
        . mcp_botao($ponto, 'Abrir o ponto')
        . mcp_nota('No celular, o ponto só funciona na sede. ' . ($voluntario ? 'Se não puder vir, tudo bem: o voluntariado é no seu ritmo e não precisa avisar por aqui. ' : '')
            . 'Para mudar os dias ou parar os lembretes, <a href="' . mcp_escapar($prefs) . '">toque aqui</a>.');
    $texto = "Oi, $primeiro. Se vier à sede amanhã, $dia, lembre-se do ponto:\n- Ao chegar: digite seu CPF no tablet da recepção ou leia o QR code com o celular e toque em Registrar entrada.\n"
        . "- Ao ir embora: toque em Registrar saída.\n\n$porque\n\nAbrir o ponto: $ponto\nMudar os dias ou parar os lembretes: $prefs";
    $whatsapp = "Olá, $primeiro! Lembrete da secretaria da Cruz Vermelha Brasileira RJ: se vier à sede amanhã, $dia, registre a *entrada* ao chegar e a *saída* ao ir embora "
        . "(CPF no tablet da recepção ou QR code no celular).\n\n" . ($voluntario ? "Se não puder vir, tudo bem.\n" : '') . "Mudar os dias ou parar os lembretes: $prefs";
    return [
        'assunto' => "Lembrete para amanhã, $dia: registre a chegada e a saída",
        'titulo' => "Até amanhã, $primeiro!",
        'html' => mcp_moldura("Até amanhã, $primeiro!", $corpo, [
            'eyebrow' => 'Ponto da sede',
            'preheader' => 'Ao chegar, registre a entrada; ao ir embora, a saída. Leva 10 segundos.',
            'motivo' => 'Você recebeu este lembrete porque escolheu ser avisado na véspera dos dias em que vem à sede da ' . MCP_NOME_FILIAL . '.',
        ]),
        'texto' => $texto,
        'whatsapp' => $whatsapp,
        'modelo' => ['nome' => 'cvb_ponto_vespera', 'parametros' => [$primeiro, $dia], 'botao' => mcp_avisos_sufixo_clique($avisoId, 'lembretes')],
    ];
}

function mcp_montar_aviso_saida(array $colaborador, array $registro, int $avisoId, int $agora): array
{
    $primeiro = mcp_primeiro_nome(mcp_nome_proprio((string) $colaborador['nome']));
    $quando = mcp_avisos_quando(mcp_data_brt((string) $registro['entrada'], 'Y-m-d'), $agora);
    $hora = mcp_data_brt((string) $registro['entrada'], 'H:i');
    $link = mcp_avisos_link_clique($avisoId, 'saida');
    $abertura = mb_strtoupper(mb_substr($quando, 0, 1)) . mb_substr($quando, 1);
    $corpo = mcp_p('Oi, ' . mcp_escapar($primeiro) . '. ' . mcp_escapar($abertura) . ' você registrou a entrada na sede às <strong>' . mcp_escapar($hora)
            . '</strong>, mas a saída ficou sem registro. Sem ela, as horas desse dia ainda não entram na sua conta de horas doadas.')
        . mcp_botao($link, 'Informar a que horas saí')
        . mcp_p('Leva 10 segundos. A secretaria confere e as horas entram na sua conta.')
        . mcp_nota('Se você ainda está na sede, é só registrar a saída ao ir embora. O link vale por ' . MCP_AVISOS_LINK_DIAS['saida'] . ' dias. Da próxima vez que abrir o ponto, ele também pergunta.');
    return [
        'assunto' => 'Faltou registrar sua saída de ' . mcp_avisos_dia_texto(mcp_data_brt((string) $registro['entrada'], 'Y-m-d')),
        'titulo' => "Faltou só a saída, $primeiro",
        'html' => mcp_moldura("Faltou só a saída, $primeiro", $corpo, [
            'eyebrow' => 'Ponto da sede',
            'preheader' => "Informe a que horas saiu para as horas entrarem na sua conta.",
            'motivo' => 'Você recebeu este aviso porque registrou a entrada no ponto da sede e a saída ficou sem registro. Para não receber este aviso, mude nas suas preferências de lembretes.',
        ]),
        'texto' => "Oi, $primeiro. $abertura você registrou a entrada na sede às $hora, mas a saída ficou sem registro. Sem ela, as horas desse dia ainda não entram na sua conta de horas doadas.\n\n"
            . "Informe a que horas saiu (leva 10 segundos): $link\n\nA secretaria confere e as horas entram na sua conta.",
        'whatsapp' => "Olá, $primeiro! $abertura você registrou a entrada na sede às $hora, mas a saída ficou sem registro. Informe a que horas saiu para as horas entrarem na sua conta de horas doadas: $link",
        'modelo' => ['nome' => 'cvb_ponto_saida', 'parametros' => [$primeiro, $quando, $hora], 'botao' => mcp_avisos_sufixo_clique($avisoId, 'saida')],
    ];
}

function mcp_montar_aviso_aula(string $nome, string $dataIso, array $aulas, int $avisoId): array
{
    $primeiro = mcp_primeiro_nome(mcp_nome_proprio($nome));
    $dia = mcp_avisos_dia_texto($dataIso);
    $linhas = [];
    foreach ($aulas as $a) {
        $h = mcp_presenca_horario((string) ($a['horario'] ?? ''), $dataIso)['texto'];
        $linhas[] = [(string) $a['curso'], $h];
    }
    $cursos = implode(' e ', array_values(array_unique(array_column($linhas, 0))));
    $horario = $linhas[0][1] ?? '';
    $mapa = mcp_avisos_link_clique($avisoId, 'mapa');
    $sair = mcp_avisos_link_clique($avisoId, 'sair');
    $caixa = [];
    foreach ($linhas as [$curso, $h]) {
        $caixa[$curso . (isset($caixa[$curso]) ? ' ' : '')] = $h;
    }
    $corpo = mcp_p('Oi, ' . mcp_escapar($primeiro) . '. Lembrete: amanhã, <strong>' . mcp_escapar($dia) . '</strong>, você tem aula na sede da ' . MCP_NOME_FILIAL . ':')
        . mcp_caixa($caixa)
        . mcp_p('Ao chegar, confirme a presença no <strong>ponto da recepção</strong> com o seu CPF. No fim da aula, o comprovante de comparecimento chega neste e-mail.')
        . mcp_botao($mapa, 'Como chegar', true)
        . mcp_nota(mcp_escapar(MCP_PRESENCA_LOCAL) . '. Não quer receber estes lembretes? <a href="' . mcp_escapar($sair) . '">Toque aqui</a>.');
    $lista = implode("\n", array_map(static fn(array $l): string => "- {$l[0]}, {$l[1]}", $linhas));
    return [
        'assunto' => count(array_unique(array_column($linhas, 0))) === 1 ? "Amanhã tem aula: $cursos" : 'Amanhã tem aula na sede',
        'titulo' => "Até amanhã, $primeiro!",
        'html' => mcp_moldura("Até amanhã, $primeiro!", $corpo, [
            'eyebrow' => 'Escola de Educação e Saúde',
            'preheader' => "Amanhã, $dia: $cursos, $horario. Confirme a presença no ponto da recepção.",
            'motivo' => 'Você recebeu este lembrete porque tem aula presencial amanhã na Escola de Educação e Saúde da ' . MCP_NOME_FILIAL . '.',
        ]),
        'texto' => "Oi, $primeiro. Lembrete: amanhã, $dia, você tem aula na sede:\n$lista\n\nAo chegar, confirme a presença no ponto da recepção com o seu CPF. No fim da aula, o comprovante de comparecimento chega neste e-mail.\n\n"
            . MCP_PRESENCA_LOCAL . "\nComo chegar: $mapa\nNão quer receber estes lembretes? $sair",
        'whatsapp' => "Olá, $primeiro! Lembrete da Escola de Educação e Saúde da Cruz Vermelha Brasileira RJ: amanhã, $dia, tem aula de *$cursos*, $horario, na Praça da Cruz Vermelha, 10 (Centro). "
            . "Ao chegar, confirme a presença no ponto da recepção com o seu CPF.\n\nNão quer receber estes lembretes? $sair",
        'modelo' => ['nome' => 'cvb_aula_amanha', 'parametros' => [$primeiro, $dia, $cursos, $horario], 'botao' => mcp_avisos_sufixo_clique($avisoId, 'sair')],
    ];
}

function mcp_montar_aviso_link(array $colaborador, int $avisoId): array
{
    $primeiro = mcp_primeiro_nome(mcp_nome_proprio((string) $colaborador['nome']));
    $link = mcp_avisos_link_clique($avisoId, 'lembretes');
    $corpo = mcp_p('Oi, ' . mcp_escapar($primeiro) . '. A secretaria da ' . MCP_NOME_FILIAL . ' mandou este link para você escolher como quer ser lembrado de registrar a chegada e a saída na sede:')
        . mcp_lista([
            'em quais dias da semana você costuma vir (o lembrete chega na véspera, às 18h);',
            'se prefere receber por e-mail, WhatsApp ou os dois;',
            mcp_ponto_voluntario($colaborador) ? 'se quer um aviso quando esquecer de registrar a saída.' : 'se quer receber os comunicados sobre o ponto.',
        ])
        . mcp_botao($link, 'Escolher meus lembretes')
        . mcp_nota('O link é pessoal e vale por ' . MCP_AVISOS_LINK_DIAS['lembretes'] . ' dias. Você pode mudar quando quiser.');
    return [
        'assunto' => 'Seus lembretes do ponto da sede',
        'titulo' => "Escolha seus lembretes, $primeiro",
        'html' => mcp_moldura("Escolha seus lembretes, $primeiro", $corpo, [
            'eyebrow' => 'Ponto da sede',
            'preheader' => 'Escolha os dias e o jeito de receber os lembretes do ponto.',
            'motivo' => 'Você recebeu este e-mail porque a secretaria da ' . MCP_NOME_FILIAL . ' mandou o link das suas preferências.',
        ]),
        'texto' => "Oi, $primeiro. A secretaria mandou este link para você escolher como quer ser lembrado de registrar a chegada e a saída na sede: $link\n\nO link é pessoal e vale por "
            . MCP_AVISOS_LINK_DIAS['lembretes'] . ' dias.',
        'whatsapp' => "Olá, $primeiro! Escolha aqui como quer receber os lembretes do ponto da sede da Cruz Vermelha Brasileira RJ (dias da semana, e-mail ou WhatsApp): $link",
        'modelo' => null,
    ];
}

/** Pessoa de exemplo dos testes de comunicado (a mensagem vai para a própria secretaria). */
function mcp_comunicado_pessoa_exemplo(array $campanha): array
{
    $aluno = $campanha['publico'] === 'alunos';
    return [
        'publico' => $aluno ? 'aluno' : 'colaborador', 'nome' => 'Maria da Silva', 'pessoa' => null, 'email' => null,
        'colaborador' => $aluno ? null : ['id' => 0, 'nome' => 'Maria da Silva', 'vinculo' => $campanha['publico'] === 'outros' ? 'empregado' : 'voluntario', 'ativo' => 1],
    ];
}

/**
 * Mensagem de um aviso da fila, pronta para mandar, ou null se já não se aplica (a saída foi
 * registrada, o comunicado foi apagado…). $dest vem de mcp_aviso_destinatario().
 */
function mcp_aviso_mensagem(array $aviso, array $dest, int $agora): ?array
{
    $id = (int) $aviso['id'];
    $dados = json_decode((string) ($aviso['dados'] ?? ''), true) ?: [];
    $c = $dest['colaborador'] ?? null;
    switch ($aviso['tipo']) {
        case 'vespera':
            return $c ? mcp_montar_aviso_vespera($c, (string) $aviso['referencia'], $id) : null;
        case 'saida':
            $registro = mcp_ponto_registro((int) ($dados['ponto'] ?? 0));
            if (!$c || !$registro || $registro['saida'] !== null || $registro['saida_informada'] !== null || (int) $registro['colaborador_id'] !== (int) $c['id']) {
                return null;
            }
            return mcp_montar_aviso_saida($c, $registro, $id, $agora);
        case 'aula':
            return mcp_montar_aviso_aula((string) $dest['nome'], (string) $aviso['referencia'], (array) ($dados['aulas'] ?? []), $id);
        case 'link':
            return $c ? mcp_montar_aviso_link($c, $id) : null;
        case 'campanha':
        case 'teste':
            $campanha = mcp_campanha((int) $aviso['campanha_id']);
            if (!$campanha) {
                return null;
            }
            if ($aviso['tipo'] === 'teste') {
                return mcp_campanha_mensagem($campanha, mcp_comunicado_pessoa_exemplo($campanha), $id, $agora, true);
            }
            $pessoa = $c
                ? ['publico' => 'colaborador', 'nome' => (string) $c['nome'], 'colaborador' => $c, 'pessoa' => 'c' . $c['id'], 'email' => $c['email']]
                : ['publico' => 'aluno', 'nome' => (string) $dest['nome'], 'colaborador' => null, 'pessoa' => $aviso['pessoa'], 'email' => $aviso['canal'] === 'email' ? $aviso['destino'] : null];
            return mcp_campanha_mensagem($campanha, $pessoa, $id, (int) strtotime(($campanha['iniciada_em'] ?? mcp_agora()) . ' UTC'));
    }
    return null;
}

// ----------------------------------------------------------------------------- comunicados: cadastro
function mcp_campanha(int $id): ?array
{
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_campanhas WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Comunicados na ordem das fases (antes, durante, depois, avulsos) e da data. */
function mcp_campanhas_listar(): array
{
    return mcp_db()->query("SELECT * FROM mcp_campanhas ORDER BY FIELD(fase, 'antes', 'durante', 'depois', 'livre'), COALESCE(agendada_para, criado_em), id")->fetchAll();
}

function mcp_campanha_canais(array $campanha): array
{
    return array_values(array_intersect(array_keys(MCP_CAMPANHA_CANAIS), explode(',', (string) $campanha['canais'])));
}

/**
 * Confere o formulário de um comunicado.
 * @return array{ok: bool, dados?: array, campo?: string, erro?: string}
 */
function mcp_campanha_conferir(array $f): array
{
    $canais = array_values(array_intersect(array_keys(MCP_CAMPANHA_CANAIS), array_map('strval', (array) ($f['canais'] ?? []))));
    $d = [
        'nome' => mcp_texto($f['nome'] ?? '', 120),
        'fase' => mcp_texto($f['fase'] ?? '', 10),
        'publico' => mcp_texto($f['publico'] ?? '', 20),
        'canais' => $canais,
        'assunto' => mcp_texto($f['assunto'] ?? '', 160),
        'titulo' => mcp_texto($f['titulo'] ?? '', 120),
        'mensagem' => mcp_texto_longo($f['mensagem'] ?? '', 5000),
        'botao' => mcp_texto($f['botao'] ?? '', 12),
        'botao_rotulo' => mcp_texto($f['botao_rotulo'] ?? '', 60),
        'whatsapp' => mcp_texto_longo($f['whatsapp'] ?? '', 1500),
        'aviso_ponto' => mcp_texto($f['aviso_ponto'] ?? '', 255),
        'aviso_dias' => max(1, min(30, (int) ($f['aviso_dias'] ?? 14))),
    ];
    $erros = [
        'nome' => mb_strlen($d['nome']) < 3 ? 'Dê um nome ao comunicado (só a secretaria vê).' : null,
        'fase' => !isset(MCP_CAMPANHA_FASES[$d['fase']]) ? 'Escolha a fase.' : null,
        'publico' => !isset(MCP_CAMPANHA_PUBLICOS[$d['publico']]) ? 'Escolha para quem vai.' : null,
        'canais' => !$canais ? 'Escolha pelo menos um canal.' : null,
        'assunto' => in_array('email', $canais, true) && mb_strlen($d['assunto']) < 3 ? 'Escreva o assunto do e-mail.' : null,
        'titulo' => in_array('email', $canais, true) && mb_strlen($d['titulo']) < 3 ? 'Escreva o título do e-mail.' : null,
        'mensagem' => in_array('email', $canais, true) && mb_strlen($d['mensagem']) < 10 ? 'Escreva a mensagem do e-mail.' : null,
        'botao' => !isset(MCP_CAMPANHA_BOTOES[$d['botao']]) ? 'Escolha o botão.' : null,
        'whatsapp' => in_array('whatsapp', $canais, true) && mb_strlen($d['whatsapp']) < 10 ? 'Escreva a mensagem do WhatsApp.' : null,
        'aviso_ponto' => in_array('ponto', $canais, true) && mb_strlen($d['aviso_ponto']) < 5 ? 'Escreva o aviso que aparece na tela do ponto.' : null,
    ];
    foreach ($erros as $campo => $erro) {
        if ($erro !== null) {
            return ['ok' => false, 'campo' => $campo, 'erro' => $erro];
        }
    }
    if ($d['publico'] === 'alunos' && in_array('whatsapp', $canais, true)) {
        return ['ok' => false, 'campo' => 'canais', 'erro' => 'Para alunos, os comunicados vão só por e-mail e na tela do ponto: o WhatsApp dos alunos fica para o lembrete da aula.'];
    }
    if ($d['publico'] === 'alunos' && $d['botao'] === 'lembretes') {
        return ['ok' => false, 'campo' => 'botao', 'erro' => 'Alunos não têm a página de lembretes: escolha outro botão.'];
    }
    foreach (['assunto', 'titulo', 'mensagem', 'whatsapp', 'aviso_ponto'] as $campo) {
        preg_match_all('/\{([a-z_]+)\}/', $d[$campo], $m);
        $desconhecidos = array_diff($m[1], array_keys(MCP_COMUNICADO_CAMPOS));
        if ($desconhecidos) {
            return ['ok' => false, 'campo' => $campo, 'erro' => 'O campo {' . reset($desconhecidos) . '} não existe. Campos que valem: ' . implode(', ', array_map(static fn($c) => '{' . $c . '}', array_keys(MCP_COMUNICADO_CAMPOS))) . '.'];
        }
        if ($campo !== 'whatsapp' && str_contains($d[$campo], '{link}')) {
            return ['ok' => false, 'campo' => $campo, 'erro' => 'O campo {link} vale só no texto do WhatsApp; no e-mail, o link vai no botão.'];
        }
    }
    $d['canais'] = implode(',', $canais);
    return ['ok' => true, 'dados' => $d];
}

/** Grava o comunicado ($id null = novo). Só rascunho e agendado podem mudar. Devolve o id, ou null se não pode. */
function mcp_campanha_salvar(?int $id, array $d, string $quem): ?int
{
    $agora = mcp_agora();
    if ($id === null) {
        mcp_db()->prepare('INSERT INTO mcp_campanhas (fase, nome, publico, canais, assunto, titulo, mensagem, botao, botao_rotulo, whatsapp, aviso_ponto, aviso_dias, status, criado_por, criado_em, atualizado_por, atualizado_em)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'rascunho\', ?, ?, ?, ?)')
            ->execute([$d['fase'], $d['nome'], $d['publico'], $d['canais'], $d['assunto'], $d['titulo'], $d['mensagem'], $d['botao'], $d['botao_rotulo'] ?: null,
                $d['whatsapp'] ?: null, $d['aviso_ponto'] ?: null, $d['aviso_dias'], $quem, $agora, $quem, $agora]);
        return (int) mcp_db()->lastInsertId();
    }
    $stmt = mcp_db()->prepare("UPDATE mcp_campanhas SET fase = ?, nome = ?, publico = ?, canais = ?, assunto = ?, titulo = ?, mensagem = ?, botao = ?, botao_rotulo = ?, whatsapp = ?,
        aviso_ponto = ?, aviso_dias = ?, atualizado_por = ?, atualizado_em = ? WHERE id = ? AND status IN ('rascunho', 'agendada')");
    $stmt->execute([$d['fase'], $d['nome'], $d['publico'], $d['canais'], $d['assunto'], $d['titulo'], $d['mensagem'], $d['botao'], $d['botao_rotulo'] ?: null,
        $d['whatsapp'] ?: null, $d['aviso_ponto'] ?: null, $d['aviso_dias'], $quem, $agora, $id]);
    $campanha = mcp_campanha($id);
    return $campanha && in_array($campanha['status'], ['rascunho', 'agendada'], true) ? $id : null;
}

/** Agenda (ou manda agora, com $quandoUtc null) um rascunho ou reagenda. Devolve a mensagem do erro, ou null. */
function mcp_campanha_agendar(int $id, ?string $quandoUtc, string $quem, ?int $agora = null): ?string
{
    $agora ??= time();
    $campanha = mcp_campanha($id);
    if (!$campanha || !in_array($campanha['status'], ['rascunho', 'agendada'], true)) {
        return 'Este comunicado já saiu ou foi cancelado.';
    }
    if ($quandoUtc !== null && (int) strtotime($quandoUtc . ' UTC') < $agora - 300) {
        return 'A data do agendamento já passou. Escolha outra ou use "Enviar agora".';
    }
    if (preg_match('/\{(data_lancamento|data_curta)\}/', $campanha['assunto'] . $campanha['titulo'] . $campanha['mensagem'] . $campanha['whatsapp']) && mcp_ajuste('lancamento') === '') {
        return 'O texto usa a data do lançamento, que ainda não foi definida. Defina a data na Visão geral.';
    }
    mcp_db()->prepare("UPDATE mcp_campanhas SET status = 'agendada', agendada_para = ?, atualizado_por = ?, atualizado_em = ? WHERE id = ?")
        ->execute([$quandoUtc ?? gmdate('Y-m-d H:i:s', $agora), $quem, gmdate('Y-m-d H:i:s', $agora), $id]);
    mcp_registrar(null, 'campanha_agendada', "#$id · $quem · " . ($quandoUtc ?? 'agora'));
    return null;
}

/** Desagenda (volta a rascunho) ou interrompe um comunicado que está saindo. */
function mcp_campanha_cancelar(int $id, string $quem): bool
{
    $campanha = mcp_campanha($id);
    if (!$campanha) {
        return false;
    }
    if ($campanha['status'] === 'agendada') {
        mcp_db()->prepare("UPDATE mcp_campanhas SET status = 'rascunho', atualizado_por = ?, atualizado_em = ? WHERE id = ? AND status = 'agendada'")->execute([$quem, mcp_agora(), $id]);
    } elseif ($campanha['status'] === 'enviando') {
        mcp_db()->prepare("UPDATE mcp_campanhas SET status = 'cancelada', concluida_em = ?, atualizado_por = ?, atualizado_em = ? WHERE id = ?")->execute([mcp_agora(), $quem, mcp_agora(), $id]);
        mcp_db()->prepare("UPDATE mcp_avisos SET status = 'cancelado', erro = 'comunicado interrompido', atualizado_em = ? WHERE campanha_id = ? AND status IN ('pendente', 'manual')")->execute([mcp_agora(), $id]);
    } else {
        return false;
    }
    mcp_registrar(null, 'campanha_cancelada', "#$id · $quem · era {$campanha['status']}");
    return true;
}

/** Apaga um rascunho (o que já saiu fica no registro). */
function mcp_campanha_apagar(int $id, string $quem): bool
{
    $stmt = mcp_db()->prepare("DELETE FROM mcp_campanhas WHERE id = ? AND status = 'rascunho' AND iniciada_em IS NULL");
    $stmt->execute([$id]);
    if ($stmt->rowCount() > 0) {
        mcp_registrar(null, 'campanha_apagada', "#$id · $quem");
        return true;
    }
    return false;
}

// ----------------------------------------------------------------------------- comunicados: público e disparo
/** Alunos que fizeram inscrição paga (180 dias) ou confirmaram presença pelo ponto (60 dias), sem repetir e-mail. */
function mcp_campanha_alunos(?int $agora = null): array
{
    $agora ??= time();
    $alunos = [];
    $stmt = mcp_db()->prepare("SELECT email, nome, pago_em AS quando FROM mcp_inscricoes WHERE status = 'pago' AND pago_em >= ?
        UNION ALL SELECT email, nome, chegada AS quando FROM mcp_presencas WHERE status = 'valida' AND email IS NOT NULL AND chegada >= ? ORDER BY quando");
    $stmt->execute([gmdate('Y-m-d H:i:s', $agora - 180 * 86400), gmdate('Y-m-d H:i:s', $agora - 60 * 86400)]);
    foreach ($stmt->fetchAll() as $l) {
        $email = mb_strtolower(trim((string) $l['email']));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $alunos[$email] = ['publico' => 'aluno', 'nome' => mcp_nome_proprio((string) $l['nome']), 'colaborador' => null, 'pessoa' => 'a' . mcp_avisos_hash('email', $email), 'email' => $email];
        }
    }
    return array_values($alunos);
}

/** Quem recebe o comunicado, com os canais de cada um: [['pessoa' => [...], 'canais' => ['email' => …]], …]. */
function mcp_campanha_destinatarios(array $campanha, ?int $agora = null): array
{
    $canaisCampanha = mcp_campanha_canais($campanha);
    $lista = [];
    if ($campanha['publico'] === 'alunos') {
        foreach (mcp_campanha_alunos($agora) as $p) {
            $canais = in_array('email', $canaisCampanha, true) && !mcp_avisos_bloqueado('email', (string) $p['email']) ? ['email' => $p['email']] : [];
            $lista[] = ['pessoa' => $p, 'canais' => $canais];
        }
        return $lista;
    }
    foreach (mcp_colaboradores_listar() as $c) {
        if (!(int) $c['ativo']) {
            continue;
        }
        $voluntario = mcp_ponto_voluntario($c);
        if (($campanha['publico'] === 'voluntarios' && !$voluntario) || ($campanha['publico'] === 'outros' && $voluntario)) {
            continue;
        }
        $canais = array_intersect_key(mcp_avisos_canais($c, 'campanha'), array_flip($canaisCampanha));
        $lista[] = ['pessoa' => ['publico' => 'colaborador', 'nome' => (string) $c['nome'], 'colaborador' => $c, 'pessoa' => 'c' . $c['id'], 'email' => $c['email']], 'canais' => $canais];
    }
    return $lista;
}

/** Quantos recebem: pessoas, por canal e sem contato (nem e-mail nem WhatsApp autorizado). */
function mcp_campanha_contagem(array $campanha, ?int $agora = null): array
{
    $r = ['pessoas' => 0, 'email' => 0, 'whatsapp' => 0, 'sem_contato' => 0, 'sem_contato_nomes' => []];
    $mensagem = array_intersect(mcp_campanha_canais($campanha), ['email', 'whatsapp']);
    foreach (mcp_campanha_destinatarios($campanha, $agora) as $d) {
        $r['pessoas']++;
        $r['email'] += isset($d['canais']['email']) ? 1 : 0;
        $r['whatsapp'] += isset($d['canais']['whatsapp']) ? 1 : 0;
        if ($mensagem && !$d['canais']) {
            $r['sem_contato']++;
            $r['sem_contato_nomes'][] = (string) $d['pessoa']['nome'];
        }
    }
    return $r;
}

/** Comunicados agendados cuja hora chegou: põe as mensagens na fila (dentro da janela das 8h às 20h). */
function mcp_campanhas_disparar(?int $agora = null): int
{
    $agora ??= time();
    if (!mcp_avisos_na_janela($agora)) {
        return 0;
    }
    $stmt = mcp_db()->prepare("SELECT * FROM mcp_campanhas WHERE status = 'agendada' AND agendada_para <= ?");
    $stmt->execute([gmdate('Y-m-d H:i:s', $agora)]);
    $n = 0;
    foreach ($stmt->fetchAll() as $campanha) {
        $n += mcp_campanha_materializar($campanha, $agora);
    }
    return $n;
}

/** Cria as mensagens de um comunicado. Só quem muda o status de agendado para enviando cria (sem duplicar). */
function mcp_campanha_materializar(array $campanha, int $agora): int
{
    $quando = gmdate('Y-m-d H:i:s', $agora);
    $stmt = mcp_db()->prepare("UPDATE mcp_campanhas SET status = 'enviando', iniciada_em = ?, atualizado_em = ? WHERE id = ? AND status = 'agendada'");
    $stmt->execute([$quando, $quando, (int) $campanha['id']]);
    if ($stmt->rowCount() === 0) {
        return 0;
    }
    $n = 0;
    foreach (mcp_campanha_destinatarios($campanha, $agora) as $d) {
        $p = $d['pessoa'];
        foreach ($d['canais'] as $canal => $destino) {
            $n += mcp_aviso_criar([
                'chave' => "campanha|{$campanha['id']}|{$p['pessoa']}|$canal", 'tipo' => 'campanha', 'canal' => $canal, 'campanha_id' => (int) $campanha['id'],
                'colaborador_id' => $p['colaborador'] ? (int) $p['colaborador']['id'] : null, 'pessoa' => $p['pessoa'], 'nome' => (string) $p['nome'],
                'destino' => $destino, 'agendado_para' => $quando, 'expira_em' => gmdate('Y-m-d H:i:s', $agora + 7 * 86400),
            ], $agora) !== null ? 1 : 0;
        }
    }
    mcp_registrar(null, 'campanha_iniciada', "#{$campanha['id']} · $n mensagens");
    return $n;
}

/** Comunicado sem nada na fila automática vira "enviado" (a fila manual do WhatsApp não segura). */
function mcp_campanhas_concluir(?int $agora = null): void
{
    mcp_db()->prepare("UPDATE mcp_campanhas c SET status = 'enviada', concluida_em = ?, atualizado_em = ? WHERE status = 'enviando'
        AND NOT EXISTS (SELECT 1 FROM mcp_avisos a WHERE a.campanha_id = c.id AND a.status IN ('pendente', 'enviando'))")
        ->execute([gmdate('Y-m-d H:i:s', $agora ?? time()), gmdate('Y-m-d H:i:s', $agora ?? time())]);
}

/** Números de um comunicado: mensagens por situação e canal, cliques e respostas da opinião. */
function mcp_campanha_numeros(int $id): array
{
    $r = ['total' => 0, 'enviado' => 0, 'falhou' => 0, 'fila' => 0, 'manual' => 0, 'cancelado' => 0, 'expirado' => 0, 'clicados' => 0, 'email' => 0, 'whatsapp' => 0, 'opinioes' => 0];
    $stmt = mcp_db()->prepare('SELECT status, canal, COUNT(*) AS n, SUM(clicado_em IS NOT NULL) AS cliques FROM mcp_avisos WHERE campanha_id = ? AND tipo = \'campanha\' GROUP BY status, canal');
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $l) {
        $n = (int) $l['n'];
        $r['total'] += $n;
        $r[$l['canal']] = ($r[$l['canal']] ?? 0) + $n;
        $chave = in_array($l['status'], ['pendente', 'enviando'], true) ? 'fila' : (string) $l['status'];
        $r[$chave] = ($r[$chave] ?? 0) + $n;
        $r['clicados'] += (int) $l['cliques'];
    }
    $stmt = mcp_db()->prepare('SELECT COUNT(*) FROM mcp_opinioes WHERE campanha_id = ?');
    $stmt->execute([$id]);
    $r['opinioes'] = (int) $stmt->fetchColumn();
    return $r;
}

// ----------------------------------------------------------------------------- comunicados da implantação
/** Os comunicados sugeridos para as três fases, com o texto revisado. As datas saem da data do lançamento. */
function mcp_campanhas_modelos(string $lancamentoIso): array
{
    $em = static fn(string $iso, string $hora): ?string => mcp_ponto_local_para_utc("$iso $hora:00");
    return [
        [
            'fase' => 'antes', 'nome' => 'Antes: anúncio do ponto', 'publico' => 'colaboradores', 'canais' => 'email,whatsapp', 'botao' => 'lembretes', 'botao_rotulo' => 'Escolher meus lembretes',
            'agendada_para' => $em(mcp_avisos_dia_mais($lancamentoIso, -5), '10:00'), 'aviso_dias' => 5,
            'assunto' => 'Novidade na sede: o ponto de chegada e saída começa em {data_curta}',
            'titulo' => 'Novidade na sede, {primeiro_nome}',
            'mensagem' => "A partir de *{data_lancamento}*, a sede da Cruz Vermelha Brasileira Rio de Janeiro passa a ter um ponto de chegada e saída para todos que fazem a instituição funcionar: diretoria, voluntários, equipe e prestadores de serviço.\n\n"
                . "{vinculo_frase}\n\nComo funciona, em 10 segundos:\n1. Ao chegar, digite seu CPF no tablet da recepção ou leia com o celular o QR code do cartaz.\n2. Toque em *Registrar entrada*.\n"
                . "3. Ao ir embora, faça o mesmo e toque em *Registrar saída*.\n\nQuer um lembrete na véspera dos dias em que você vem? No botão abaixo, você escolhe os dias e se prefere receber por e-mail ou WhatsApp.",
            'whatsapp' => "Olá, {primeiro_nome}! Aqui é a secretaria da Cruz Vermelha Brasileira RJ. A partir de *{data_lancamento}*, a sede terá um ponto de chegada e saída: ao chegar e ao ir embora, "
                . "digite seu CPF no tablet da recepção ou leia o QR code com o celular. Leva 10 segundos.\n\n{vinculo_frase_curta}\n\nQuer lembrete na véspera dos dias em que vem? Escolha aqui: {link}",
            'aviso_ponto' => null,
        ],
        [
            'fase' => 'durante', 'nome' => 'Durante: o ponto começou', 'publico' => 'colaboradores', 'canais' => 'email,whatsapp,ponto', 'botao' => 'ponto', 'botao_rotulo' => 'Abrir o ponto',
            'agendada_para' => $em($lancamentoIso, '08:30'), 'aviso_dias' => 14,
            'assunto' => 'Começou hoje: registre sua chegada e sua saída na sede',
            'titulo' => 'O ponto começou, {primeiro_nome}!',
            'mensagem' => "Bom dia! Desde hoje, o ponto da sede está funcionando. Ao chegar, registre a entrada; ao ir embora, registre a saída.\n\nDicas para ser rápido:\n"
                . "- No celular, permita a localização quando a página pedir. Ela só confirma que você está na sede e não fica guardada.\n"
                . "- Marque *Lembrar de mim neste celular*: da próxima vez, é um toque.\n- Adicione o ponto à tela inicial do celular, como um aplicativo.\n"
                . "- Esqueceu de registrar a saída? Da próxima vez que abrir o ponto, ele pergunta a que horas você saiu.\n\n"
                . "Teve algum problema? Responda este e-mail ou fale com a secretaria. Nas próximas duas semanas, vamos acompanhar de perto para ajustar o que for preciso.",
            'whatsapp' => "Bom dia, {primeiro_nome}! O ponto da sede da Cruz Vermelha Brasileira RJ começa hoje. Ao chegar, registre a *entrada*; ao ir embora, a *saída* "
                . "(CPF no tablet da recepção ou QR code no celular).\n\nDica: no celular, marque “Lembrar de mim” e da próxima vez é um toque. Algum problema? Fale com a secretaria.\n\nPonto: {link}",
            'aviso_ponto' => 'Dica: no celular, marque “Lembrar de mim” e adicione esta página à tela inicial. Da próxima vez, é um toque.',
        ],
        [
            'fase' => 'depois', 'nome' => 'Depois de 2 semanas: opinião e resumo', 'publico' => 'colaboradores', 'canais' => 'email,whatsapp,ponto', 'botao' => 'opiniao', 'botao_rotulo' => 'Responder em 1 minuto',
            'agendada_para' => $em(mcp_avisos_dia_mais($lancamentoIso, 14), '10:00'), 'aviso_dias' => 10,
            'assunto' => 'Duas semanas de ponto: como está sendo para você?',
            'titulo' => 'Como está sendo, {primeiro_nome}?',
            'mensagem' => "Faz duas semanas que o ponto da sede começou. {resumo}\n\nQueremos saber como está sendo para você: são 5 perguntas rápidas, leva 1 minuto. O que não estiver funcionando, a gente ajusta.\n\n"
                . 'Obrigado por fazer parte da Cruz Vermelha Brasileira Rio de Janeiro.',
            'whatsapp' => "Olá, {primeiro_nome}! Faz duas semanas que o ponto da sede da Cruz Vermelha Brasileira RJ começou. {resumo}\n\nConte como está sendo para você: 5 perguntas, 1 minuto. {link}",
            'aviso_ponto' => 'Conte como está sendo o ponto: 5 perguntas, 1 minuto.',
        ],
        [
            'fase' => 'depois', 'nome' => 'Depois de 2 semanas: opinião dos alunos', 'publico' => 'alunos', 'canais' => 'email,ponto', 'botao' => 'opiniao', 'botao_rotulo' => 'Responder em 1 minuto',
            'agendada_para' => $em(mcp_avisos_dia_mais($lancamentoIso, 14), '10:30'), 'aviso_dias' => 10,
            'assunto' => 'Como foi confirmar a presença pelo ponto da recepção?',
            'titulo' => 'Como está sendo, {primeiro_nome}?',
            'mensagem' => "Há duas semanas, a presença nas aulas presenciais passou a ser confirmada no ponto da recepção, com o CPF. {resumo}\n\n"
                . "Conte como está sendo para você: são 4 perguntas rápidas, leva 1 minuto. Sua opinião ajuda a Escola a melhorar.",
            'whatsapp' => null,
            'aviso_ponto' => 'Conte como foi confirmar a presença pelo ponto: 4 perguntas, 1 minuto.',
        ],
    ];
}

/**
 * Grava a data do lançamento e cria, como rascunho, os comunicados das fases que ainda não existem.
 * Devolve quantos comunicados foram criados.
 */
function mcp_campanhas_preparar_implantacao(string $lancamentoIso, string $quem): int
{
    mcp_ajuste_gravar('lancamento', $lancamentoIso, $quem);
    $existentes = [];
    foreach (mcp_campanhas_listar() as $c) {
        $existentes[$c['fase'] . '|' . $c['publico']] = true;
    }
    $n = 0;
    foreach (mcp_campanhas_modelos($lancamentoIso) as $m) {
        if (isset($existentes[$m['fase'] . '|' . $m['publico']])) {
            continue;
        }
        $id = mcp_campanha_salvar(null, $m, $quem);
        mcp_db()->prepare('UPDATE mcp_campanhas SET agendada_para = ? WHERE id = ?')->execute([$m['agendada_para'], $id]);
        $n++;
    }
    mcp_registrar(null, 'campanhas_implantacao', "$quem · lançamento $lancamentoIso · $n criados");
    return $n;
}

// ----------------------------------------------------------------------------- avisos na tela do ponto
/**
 * Avisos que aparecem para a pessoa depois do CPF: os comunicados com "aviso na tela do ponto" no ar
 * (do início até N dias depois). No celular, com o link (opinião, lembretes); no aparelho da sede, só o texto.
 * @return list<array{texto: string, link: ?string, rotulo: ?string}>
 */
function mcp_comunicacao_avisos_ponto(?array $colaborador, ?array $aluno, bool $celular, ?int $agora = null): array
{
    $agora ??= time();
    $stmt = mcp_db()->prepare("SELECT * FROM mcp_campanhas WHERE status IN ('enviando', 'enviada') AND canais LIKE '%ponto%' AND aviso_ponto IS NOT NULL AND iniciada_em <= ?
        ORDER BY iniciada_em DESC LIMIT 10");
    $stmt->execute([gmdate('Y-m-d H:i:s', $agora)]);
    $avisos = [];
    foreach ($stmt->fetchAll() as $c) {
        if ((int) strtotime($c['iniciada_em'] . ' UTC') + (int) $c['aviso_dias'] * 86400 < $agora) {
            continue;
        }
        $pessoa = null;
        if ($colaborador && $c['publico'] !== 'alunos') {
            $voluntario = mcp_ponto_voluntario($colaborador);
            if (($c['publico'] === 'voluntarios' && !$voluntario) || ($c['publico'] === 'outros' && $voluntario)) {
                continue;
            }
            $pessoa = 'c' . $colaborador['id'];
        } elseif ($aluno && $c['publico'] === 'alunos' && !empty($aluno['email'])) {
            $pessoa = 'a' . mcp_avisos_hash('email', (string) $aluno['email']);
        } else {
            continue;
        }
        $link = null;
        $rotulo = null;
        if ($c['botao'] === 'opiniao') {
            $stmtR = mcp_db()->prepare('SELECT 1 FROM mcp_opinioes WHERE campanha_id = ? AND pessoa = ?');
            $stmtR->execute([(int) $c['id'], $pessoa]);
            if ($stmtR->fetchColumn()) {
                continue; // já respondeu: o aviso some
            }
            if ($celular) {
                $link = mcp_avisos_link('opiniao', $pessoa, (string) $c['id'], $agora);
                $rotulo = 'Responder';
            }
        } elseif ($c['botao'] === 'lembretes' && $celular && str_starts_with($pessoa, 'c')) {
            $link = mcp_avisos_link('lembretes', $pessoa, '', $agora);
            $rotulo = 'Escolher lembretes';
        }
        $avisos[] = ['texto' => (string) $c['aviso_ponto'], 'link' => $link, 'rotulo' => $rotulo];
        if (count($avisos) >= 2) {
            break;
        }
    }
    return $avisos;
}

// ----------------------------------------------------------------------------- opinião (depois de 2 semanas)
/**
 * O que a página de opinião precisa, a partir do token: o comunicado, quem é (sem expor dados) e a
 * resposta anterior, se houver.
 */
function mcp_opiniao_contexto(array $token): ?array
{
    $campanha = mcp_campanha((int) $token['extra']);
    if (!$campanha) {
        return null;
    }
    $c = $token['colaborador'];
    if ($c !== null && !(int) $c['ativo']) {
        return null;
    }
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_opinioes WHERE campanha_id = ? AND pessoa = ?');
    $stmt->execute([(int) $campanha['id'], $token['pessoa']]);
    $resposta = $stmt->fetch() ?: null;
    return [
        'campanha' => $campanha,
        'pessoa' => (string) $token['pessoa'],
        'publico' => $c ? 'colaborador' : 'aluno',
        'colaborador' => $c,
        'primeiro_nome' => $c ? mcp_primeiro_nome(mcp_nome_proprio((string) $c['nome'])) : null,
        'resposta' => $resposta,
    ];
}

/** Grava (ou atualiza) a opinião. Devolve null se deu certo, ou [campo, erro]. */
function mcp_opiniao_salvar(array $ctx, array $f): ?array
{
    $facilidade = (int) ($f['facilidade'] ?? 0);
    if (!isset(MCP_OPINIAO_FACILIDADE[$facilidade])) {
        return ['facilidade', 'Escolha de 1 a 5: quanto foi fácil registrar.'];
    }
    $como = mcp_texto($f['como'] ?? '', 12);
    if (!isset(MCP_OPINIAO_COMO[$como])) {
        return ['como', 'Diga como você costuma registrar.'];
    }
    $problemas = array_values(array_intersect(array_keys(MCP_OPINIAO_PROBLEMAS), array_map('strval', (array) ($f['problemas'] ?? []))));
    $lembretes = mcp_texto($f['lembretes'] ?? '', 12);
    if ($ctx['publico'] === 'colaborador' && !isset(MCP_OPINIAO_LEMBRETES[$lembretes])) {
        return ['lembretes', 'Diga o que acha dos lembretes.'];
    }
    $comentario = mcp_texto_longo($f['comentario'] ?? '', MCP_OPINIAO_COMENTARIO_MAX);
    $contatoOk = !empty($f['contato_ok']) ? 1 : 0;
    $c = $ctx['colaborador'];
    $nome = null;
    $contato = null;
    if ($contatoOk) {
        if ($c) {
            $nome = (string) $c['nome'];
            $contato = (string) ($c['email'] ?: ($c['telefone'] ? mcp_telefone_bonito((string) $c['telefone']) : ''));
        } else {
            $nome = mcp_texto($f['nome'] ?? '', 160);
            $contato = mcp_texto($f['contato'] ?? '', 190);
            if (mb_strlen($nome) < 2 || mb_strlen($contato) < 5) {
                return ['contato', 'Para a secretaria falar com você, escreva seu nome e um e-mail ou telefone.'];
            }
        }
    }
    $agora = mcp_agora();
    mcp_db()->prepare('INSERT INTO mcp_opinioes (campanha_id, pessoa, publico, vinculo, nome, contato, facilidade, como, problemas, lembretes, comentario, contato_ok, criado_em, atualizado_em)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE vinculo = VALUES(vinculo), nome = VALUES(nome), contato = VALUES(contato), facilidade = VALUES(facilidade), como = VALUES(como),
        problemas = VALUES(problemas), lembretes = VALUES(lembretes), comentario = VALUES(comentario), contato_ok = VALUES(contato_ok), atualizado_em = VALUES(atualizado_em)')
        ->execute([(int) $ctx['campanha']['id'], $ctx['pessoa'], $ctx['publico'], $c['vinculo'] ?? null, $nome, $contato, $facilidade, $como,
            implode(',', $problemas) ?: null, $lembretes !== '' && isset(MCP_OPINIAO_LEMBRETES[$lembretes]) ? $lembretes : null, $comentario !== '' ? $comentario : null, $contatoOk, $agora, $agora]);
    return null;
}

/**
 * Resultado das opiniões (de um comunicado ou de todos): respostas, média e distribuição da facilidade,
 * como registram, problemas mais citados, lembretes e comentários (com nome só de quem autorizou).
 */
function mcp_opinioes_resultado(?int $campanhaId = null): array
{
    $sql = 'SELECT * FROM mcp_opinioes' . ($campanhaId !== null ? ' WHERE campanha_id = ?' : '') . ' ORDER BY atualizado_em DESC';
    $stmt = mcp_db()->prepare($sql);
    $stmt->execute($campanhaId !== null ? [$campanhaId] : []);
    $linhas = $stmt->fetchAll();
    $r = [
        'respostas' => count($linhas), 'por_publico' => ['colaborador' => 0, 'aluno' => 0], 'media' => null,
        'facilidade' => array_fill_keys(array_keys(MCP_OPINIAO_FACILIDADE), 0), 'como' => array_fill_keys(array_keys(MCP_OPINIAO_COMO), 0),
        'problemas' => array_fill_keys(array_keys(MCP_OPINIAO_PROBLEMAS), 0), 'lembretes' => array_fill_keys(array_keys(MCP_OPINIAO_LEMBRETES), 0),
        'sem_problema' => 0, 'comentarios' => [],
    ];
    $soma = 0;
    foreach ($linhas as $l) {
        $r['por_publico'][$l['publico']] = ($r['por_publico'][$l['publico']] ?? 0) + 1;
        $f = (int) $l['facilidade'];
        if (isset($r['facilidade'][$f])) {
            $r['facilidade'][$f]++;
            $soma += $f;
        }
        if (isset($r['como'][$l['como']])) {
            $r['como'][$l['como']]++;
        }
        $problemas = array_filter(explode(',', (string) $l['problemas']));
        foreach ($problemas as $p) {
            if (isset($r['problemas'][$p])) {
                $r['problemas'][$p]++;
            }
        }
        $r['sem_problema'] += $problemas ? 0 : 1;
        if ($l['lembretes'] !== null && isset($r['lembretes'][$l['lembretes']])) {
            $r['lembretes'][$l['lembretes']]++;
        }
        if ($l['comentario'] !== null) {
            $r['comentarios'][] = [
                'texto' => (string) $l['comentario'], 'facilidade' => $f, 'publico' => (string) $l['publico'],
                'vinculo' => $l['vinculo'] !== null ? (MCP_PONTO_VINCULOS[$l['vinculo']] ?? (string) $l['vinculo']) : null,
                'nome' => (int) $l['contato_ok'] ? (string) $l['nome'] : null, 'contato' => (int) $l['contato_ok'] ? (string) $l['contato'] : null,
                'quando' => (string) $l['atualizado_em'],
            ];
        }
    }
    $r['media'] = $linhas ? round($soma / count($linhas), 1) : null;
    arsort($r['problemas']);
    return $r;
}

// ----------------------------------------------------------------------------- saída informada pela pessoa
/**
 * Registros de voluntário sem saída, com entrada em outro dia (Brasília) dos últimos 7 dias: a pessoa
 * pode informar a que horas saiu. Os que já têm saída informada vêm com ela (esperando a secretaria).
 */
function mcp_ponto_saidas_sem_registro(int $colaboradorId, ?int $agora = null): array
{
    $agora ??= time();
    $hoje = mcp_ponto_hoje($agora);
    [$de] = mcp_ponto_periodo_utc(mcp_avisos_dia_mais($hoje, -MCP_PONTO_SAIDA_INFORMAR_DIAS), $hoje);
    [$inicioHoje] = mcp_ponto_periodo_utc($hoje, mcp_avisos_dia_mais($hoje, 1));
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_ponto WHERE colaborador_id = ? AND voluntario = 1 AND saida IS NULL AND entrada >= ? AND entrada < ? ORDER BY entrada DESC LIMIT 5');
    $stmt->execute([$colaboradorId, $de, $inicioHoje]);
    return $stmt->fetchAll();
}

/** A pessoa pode informar a saída deste registro agora? */
function mcp_ponto_saida_informavel(array $registro, ?int $agora = null): bool
{
    $agora ??= time();
    $dia = mcp_data_brt((string) $registro['entrada'], 'Y-m-d');
    $hoje = mcp_ponto_hoje($agora);
    return (int) $registro['voluntario'] === 1 && $registro['saida'] === null && $dia < $hoje && $dia >= mcp_avisos_dia_mais($hoje, -MCP_PONTO_SAIDA_INFORMAR_DIAS);
}

/**
 * Grava a saída informada pela pessoa (hora de Brasília "HH:MM", no dia da entrada ou no seguinte).
 * Fica à espera da secretaria. Devolve null se deu certo, ou a mensagem do erro.
 */
function mcp_ponto_saida_informar(array $registro, string $hora, bool $diaSeguinte, string $origem, ?int $agora = null): ?string
{
    $agora ??= time();
    if (!mcp_ponto_saida_informavel($registro, $agora)) {
        return $registro['saida'] !== null ? 'A saída deste dia já está registrada.' : 'Este registro não pode mais ser informado por aqui. Fale com a secretaria.';
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
        return 'Escolha a hora em que você saiu.';
    }
    $dia = mcp_data_brt((string) $registro['entrada'], 'Y-m-d');
    $saida = mcp_ponto_local_para_utc(($diaSeguinte ? mcp_avisos_dia_mais($dia, 1) : $dia) . " $hora:00");
    if ($saida === null) {
        return 'Hora inválida.';
    }
    $erro = mcp_ponto_conferir_turno((int) $registro['colaborador_id'], (string) $registro['entrada'], $saida, (int) $registro['id'], $agora);
    if ($erro !== null) {
        return $erro === 'A saída precisa ser depois da entrada.' ? 'A saída precisa ser depois da entrada, às ' . mcp_data_brt((string) $registro['entrada'], 'H:i') . '.' : $erro;
    }
    mcp_db()->prepare('UPDATE mcp_ponto SET saida_informada = ?, saida_informada_em = ?, atualizado_em = ? WHERE id = ? AND saida IS NULL')
        ->execute([$saida, gmdate('Y-m-d H:i:s', $agora), gmdate('Y-m-d H:i:s', $agora), (int) $registro['id']]);
    mcp_registrar(null, 'ponto_saida_informada', '#' . $registro['id'] . " · $origem · " . mcp_data_brt($saida, 'd/m H:i'));
    return null;
}

/** Saídas informadas esperando a secretaria, das mais antigas para as mais novas. */
function mcp_ponto_saidas_informadas(): array
{
    return mcp_db()->query('SELECT p.*, c.nome, c.funcao, c.vinculo FROM mcp_ponto p JOIN mcp_colaboradores c ON c.id = p.colaborador_id
        WHERE p.saida IS NULL AND p.saida_informada IS NOT NULL ORDER BY p.saida_informada_em, p.id')->fetchAll();
}

function mcp_ponto_saidas_informadas_contar(): int
{
    return (int) mcp_db()->query('SELECT COUNT(*) FROM mcp_ponto WHERE saida IS NULL AND saida_informada IS NOT NULL')->fetchColumn();
}

/** A secretaria aceita (a saída vira a do registro, com origem "informada") ou recusa. Devolve o erro, ou null. */
function mcp_ponto_saida_decidir(int $id, bool $aceitar, string $quem, string $motivo = '', ?int $agora = null): ?string
{
    $agora ??= time();
    $r = mcp_ponto_registro($id);
    if (!$r || $r['saida_informada'] === null || $r['saida'] !== null) {
        return 'Esta saída informada já foi resolvida.';
    }
    $informada = mcp_data_brt((string) $r['saida_informada'], 'd/m H:i');
    $quando = mcp_data_brt((string) $r['saida_informada_em'], 'd/m \à\s H:i');
    if ($aceitar) {
        $erro = mcp_ponto_corrigir($id, (string) $r['entrada'], (string) $r['saida_informada'], $quem, "aceitou a saída informada pela pessoa em $quando", $agora);
        if ($erro !== null) {
            return $erro;
        }
        mcp_db()->prepare("UPDATE mcp_ponto SET origem_saida = 'informada', saida_informada = NULL, saida_informada_em = NULL WHERE id = ?")->execute([$id]);
        return null;
    }
    mcp_db()->prepare('UPDATE mcp_ponto SET saida_informada = NULL, saida_informada_em = NULL, ajuste = ?, atualizado_em = ? WHERE id = ?')
        ->execute([mcp_ponto_nota_ajuste($r['ajuste'], $quem, "recusou a saída informada ($informada, informada em $quando)" . ($motivo !== '' ? ": $motivo" : '')), mcp_agora(), $id]);
    mcp_registrar(null, 'ponto_saida_recusada', "#$id · $quem · $informada · $motivo");
    return null;
}

// ----------------------------------------------------------------------------- importar colaboradores (planilha colada)
/** Colunas da planilha, na ordem padrão; o cabeçalho, se vier, pode trazê-las em outra ordem. */
const MCP_IMPORTAR_COLUNAS = ['nome', 'cpf', 'vinculo', 'funcao', 'email', 'telefone', 'dias', 'whatsapp'];

/** Texto sem acento, minúsculo e sem espaços nas pontas (para comparar vínculos, dias e cabeçalhos). */
function mcp_sem_acento(string $texto): string
{
    return trim(strtr(mb_strtolower($texto), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']));
}

/** Vínculo escrito do jeito que a planilha trouxer ("Voluntária", "CLT", "presidente"…), ou ''. */
function mcp_importar_vinculo(string $texto): string
{
    $t = mcp_sem_acento($texto);
    return match (true) {
        $t === '' => '',
        str_starts_with($t, 'volunt') => 'voluntario',
        str_starts_with($t, 'diret') || str_starts_with($t, 'presid') || str_starts_with($t, 'vice') || str_starts_with($t, 'conselh') => 'diretoria',
        str_starts_with($t, 'empreg') || $t === 'clt' || str_starts_with($t, 'funcion') => 'empregado',
        str_starts_with($t, 'tercei') => 'terceirizado',
        str_starts_with($t, 'outro') || str_starts_with($t, 'estag') || str_starts_with($t, 'prestad') => 'outro',
        default => '',
    };
}

/** Dias da semana escritos à mão ("seg, qua", "segunda e quarta", "sábado") → ['seg', 'qua']. */
function mcp_importar_dias(string $texto): array
{
    $partes = preg_split('/[\s,;\/|+]+|\be\b/u', mcp_sem_acento($texto)) ?: [];
    return mcp_avisos_dias(array_map(static fn(string $p): string => mb_substr($p, 0, 3), array_filter($partes, static fn($p) => $p !== '')));
}

/**
 * Confere as linhas coladas da planilha, sem gravar nada.
 * @return array{linhas: list<array{linha: int, ok: bool, erro: ?string, dados: ?array, dias: array, whatsapp: bool, existe: ?int, bruto: array}>}
 */
function mcp_colaboradores_importar_conferir(string $texto): array
{
    $linhas = array_values(array_filter(array_map('rtrim', explode("\n", str_replace("\r", '', $texto))), static fn(string $l): bool => trim($l) !== ''));
    $r = ['linhas' => []];
    if (!$linhas) {
        return $r;
    }
    // Separador: tabulação (colado do Excel/Planilhas), ponto e vírgula ou vírgula, o que aparecer na 1ª linha.
    $primeira = $linhas[0];
    $sep = str_contains($primeira, "\t") ? "\t" : (substr_count($primeira, ';') >= substr_count($primeira, ',') ? ';' : ',');
    $colunas = MCP_IMPORTAR_COLUNAS;
    $cabecalho = array_map(static fn(string $c): string => mcp_sem_acento($c), str_getcsv($primeira, $sep, '"', ''));
    if (in_array('nome', $cabecalho, true) && in_array('cpf', $cabecalho, true)) {
        $mapa = ['nome' => 'nome', 'cpf' => 'cpf', 'vinculo' => 'vinculo', 'funcao' => 'funcao', 'cargo' => 'funcao', 'e-mail' => 'email', 'email' => 'email',
            'telefone' => 'telefone', 'celular' => 'telefone', 'whatsapp' => 'whatsapp', 'dias' => 'dias'];
        $colunas = array_map(static fn(string $c): string => $mapa[$c] ?? '', $cabecalho);
        array_shift($linhas);
        $inicio = 2;
    } else {
        $inicio = 1;
    }
    $vistos = [];
    foreach ($linhas as $i => $linha) {
        $valores = str_getcsv($linha, $sep, '"', '');
        $campos = [];
        foreach ($colunas as $j => $coluna) {
            if ($coluna !== '' && !isset($campos[$coluna])) {
                $campos[$coluna] = trim((string) ($valores[$j] ?? ''));
            }
        }
        $item = ['linha' => $i + $inicio, 'ok' => false, 'erro' => null, 'dados' => null, 'dias' => [], 'whatsapp' => false, 'existe' => null, 'bruto' => $valores];
        $vinculo = mcp_importar_vinculo((string) ($campos['vinculo'] ?? ''));
        $conferido = mcp_colaborador_conferir([
            'nome' => $campos['nome'] ?? '', 'cpf' => $campos['cpf'] ?? '', 'email' => $campos['email'] ?? '', 'telefone' => $campos['telefone'] ?? '',
            'funcao' => $campos['funcao'] ?? '', 'vinculo' => $vinculo, 'ativo' => 1,
        ]);
        if (!$conferido['ok']) {
            $item['erro'] = $conferido['campo'] === 'vinculo' ? 'Vínculo não reconhecido: use voluntário, diretoria, empregado, terceirizado ou outro.' : $conferido['erro'];
            $item['dados'] = ['nome' => $campos['nome'] ?? ''];
            $r['linhas'][] = $item;
            continue;
        }
        $d = $conferido['dados'];
        if (isset($vistos[$d['cpf']])) {
            $item['erro'] = 'CPF repetido na planilha (linha ' . $vistos[$d['cpf']] . ').';
            $item['dados'] = $d;
            $r['linhas'][] = $item;
            continue;
        }
        $vistos[$d['cpf']] = $item['linha'];
        $whatsapp = in_array(mcp_sem_acento((string) ($campos['whatsapp'] ?? '')), ['sim', 's', 'x', '1', 'yes', 'autorizado', 'autorizou'], true);
        if ($whatsapp && mcp_whatsapp_numero((string) $d['telefone']) === null) {
            $item['erro'] = 'WhatsApp "sim" precisa de um celular com DDD (com o 9 na frente).';
            $item['dados'] = $d;
            $r['linhas'][] = $item;
            continue;
        }
        $existente = mcp_colaborador_por_cpf($d['cpf']);
        $r['linhas'][] = ['ok' => true, 'dados' => $d, 'dias' => mcp_importar_dias((string) ($campos['dias'] ?? '')), 'whatsapp' => $whatsapp, 'existe' => $existente ? (int) $existente['id'] : null] + $item;
    }
    return $r;
}

/**
 * Grava as linhas conferidas: CPF novo vira cadastro ativo; CPF existente tem nome, vínculo e função
 * atualizados, e e-mail e telefone só quando a planilha traz. Dias e WhatsApp entram nas preferências
 * (o WhatsApp com o consentimento registrado em nome de quem importou). Linhas com erro ficam de fora.
 * @return array{criados: int, atualizados: int, erros: int}
 */
function mcp_colaboradores_importar(array $linhas, string $quem): array
{
    $r = ['criados' => 0, 'atualizados' => 0, 'erros' => 0];
    foreach ($linhas as $l) {
        if (!$l['ok']) {
            $r['erros']++;
            continue;
        }
        $d = $l['dados'];
        $existente = mcp_colaborador_por_cpf($d['cpf']);
        if ($existente) {
            $d['email'] = $d['email'] ?? $existente['email'];
            $d['telefone'] = $d['telefone'] ?? $existente['telefone'];
            $d['funcao'] = $d['funcao'] ?? $existente['funcao'];
            $d['ativo'] = (int) $existente['ativo'];
        }
        $id = mcp_colaborador_salvar($existente ? (int) $existente['id'] : null, $d, $quem . ' (importação)');
        if ($id === null) {
            $r['erros']++;
            continue;
        }
        $existente ? $r['atualizados']++ : $r['criados']++;
        $c = mcp_colaborador_por_id($id);
        if ($l['dias'] || $l['whatsapp']) {
            mcp_avisos_preferencias_salvar($c, [
                'email' => (int) $c['aviso_email'] === 1, 'whatsapp' => $l['whatsapp'] || (int) $c['aviso_whatsapp'] === 1,
                'dias' => $l['dias'] ?: mcp_avisos_dias($c['aviso_dias']), 'saida' => (int) $c['aviso_saida'] === 1, 'comunicados' => (int) $c['aviso_comunicados'] === 1,
            ], $quem . ' (importação)', false);
        }
    }
    return $r;
}
