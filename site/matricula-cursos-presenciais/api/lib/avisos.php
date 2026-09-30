<?php
/**
 * Avisos do ponto da sede (30/09/2026): lembretes automáticos, comunicados da implantação, fila do
 * WhatsApp, cliques e o que a pessoa escolheu receber. Os textos e os comunicados ficam em
 * lib/comunicacao.php; aqui fica a máquina que prepara, manda e registra.
 *
 * Lembretes (cada um se liga e desliga no portal, em Comunicação; todos começam desligados):
 *  - véspera: às 18h, a quem escolheu os dias em que costuma vir à sede, "amanhã, registre a entrada
 *    ao chegar e a saída ao sair";
 *  - saída não registrada: às 9h, ao voluntário que registrou a entrada ontem e não a saída, com o link
 *    para informar a que horas saiu (a secretaria confere no portal);
 *  - aula de amanhã: às 18h, a cada aluno com aula no dia seguinte, pela função aulas_do_dia da escola.
 * Os comunicados das três fases (antes, durante e depois de 2 semanas) são agendados no portal.
 *
 * Canais: e-mail (Resend, como os outros e-mails do site) e WhatsApp, de três jeitos:
 *  - manual (padrão, sem configuração): a mensagem vai para a "Fila do WhatsApp" do portal, e a
 *    secretaria abre cada uma no WhatsApp da instituição com um toque (link wa.me com o texto pronto);
 *  - cloud: API oficial do WhatsApp (Meta), com os modelos aprovados (docs/ponto-comunicacao.md);
 *  - webhook: um POST assinado para um cenário do Make (ou similar) que manda pelo WhatsApp.
 * Lembrete vai por um canal só: WhatsApp automático, se a pessoa autorizou; senão, e-mail (no modo
 * manual, os dois: a fila pode ficar parada). Comunicado vai pelos dois.
 *
 * Regras que atravessam tudo:
 *  - nada sai fora da janela das 8h às 20h (Brasília): mensagem de trabalho não chega de noite;
 *  - WhatsApp só com o consentimento registrado (pela própria pessoa ou pela secretaria, com data);
 *  - cada mensagem tem uma chave única (tipo, pessoa, data, canal): a mesma coisa nunca sai duas vezes;
 *  - o consentimento é conferido de novo na hora de mandar: quem desligou depois não recebe;
 *  - voluntário recebe lembrete como gentileza, nunca cobrança (Lei 9.608/1998): o texto diz que, se
 *    não puder vir, está tudo bem;
 *  - o registro dos envios e as opiniões são apagados depois de 1 ano.
 */
declare(strict_types=1);

const MCP_AVISOS_HORA_VESPERA = '18:00';
const MCP_AVISOS_HORA_SAIDA = '09:00';
/** Janela em que os avisos saem (Brasília). Fora dela, esperam o dia seguinte. */
const MCP_AVISOS_JANELA = ['08:00', '20:00'];
/** Mensagens por rodada da rotina (a cada 15 minutos) e pausa entre e-mails (a Resend aceita 2 por segundo). */
const MCP_AVISOS_LOTE = 80;
const MCP_AVISOS_PAUSA_MS = 550;
/**
 * E-mails dos avisos por dia (Brasília). A conta da Resend é dividida com a matrícula (recibos, PIX,
 * comprovantes): no plano grátis são 100 por dia, e a cota protege o resto. AVISOS_EMAILS_POR_DIA muda
 * ('0' = sem cota, num plano pago). Acabou a cota, o resto espera o dia seguinte, dentro do prazo de cada um.
 */
const MCP_AVISOS_EMAILS_POR_DIA = 60;
/**
 * Parte da cota que o comunicado deixa livre, além dos lembretes do dia já preparados: para os que nascem
 * depois (e-mail de reserva de um WhatsApp que falhou, quem ligou os lembretes à tarde).
 */
const MCP_AVISOS_EMAILS_FOLGA = 0.15;
/**
 * Lembrete que ainda espera o WhatsApp perto do prazo vai por e-mail: a partir de HORA_TROCA se o WhatsApp
 * está fora do ar ou desligado; na última rodada (HORA_ULTIMA), seja qual for o motivo.
 */
const MCP_AVISOS_HORA_TROCA = '19:00';
const MCP_AVISOS_HORA_ULTIMA = '19:45';
/**
 * WhatsApp pelo número do Palácio Virtual (Evolution): um freio fixo, para não disputar o número com os
 * avisos do Palácio (que manda até 12 por minuto) nem travar a rotina. 8 s entre mensagens
 * (WHATSAPP_EVOLUTION_PAUSA_S muda) e até 30 por rodada da rotina; num clique do portal, até 3.
 */
const MCP_WHATSAPP_EVOLUTION_PAUSA_S = 8;
const MCP_WHATSAPP_EVOLUTION_POR_RODADA = 30;
const MCP_WHATSAPP_EVOLUTION_POR_CLIQUE = 3;
/** Logo depois de conectar pelo QR code, a Evolution demora a confirmar cada envio (visto no Palácio). */
const MCP_WHATSAPP_EVOLUTION_TEMPO_S = 40;
const MCP_AVISOS_TENTATIVAS = 3;
const MCP_AVISOS_GUARDA_DIAS = 365;
/** Validade dos links pessoais, em dias. */
const MCP_AVISOS_LINK_DIAS = ['lembretes' => 60, 'saida' => 7, 'opiniao' => 30, 'sair' => 365];
/** Um link de clique (api/avisos.php?r=) leva ao destino por até tantos dias depois do aviso. */
const MCP_AVISOS_CLIQUE_DIAS = 60;
const MCP_AVISOS_DIAS = ['dom' => 'Domingo', 'seg' => 'Segunda', 'ter' => 'Terça', 'qua' => 'Quarta', 'qui' => 'Quinta', 'sex' => 'Sexta', 'sab' => 'Sábado'];
const MCP_AVISOS_DIAS_PLURAL = ['dom' => 'domingos', 'seg' => 'segundas', 'ter' => 'terças', 'qua' => 'quartas', 'qui' => 'quintas', 'sex' => 'sextas', 'sab' => 'sábados'];
const MCP_AVISOS_DIAS_UTEIS = ['seg', 'ter', 'qua', 'qui', 'sex'];
/** Avisos por e-mail que levam o descadastro de um clique (List-Unsubscribe): os que a pessoa não pediu naquela hora. */
const MCP_AVISOS_COM_DESCADASTRO = ['vespera', 'saida', 'aula', 'campanha'];
/** O ajuste do portal que liga cada lembrete: desligado, o que estava na fila também não sai. */
const MCP_AVISOS_AJUSTES = ['vespera' => 'lembrete_vespera', 'saida' => 'lembrete_saida', 'aula' => 'lembrete_aula'];
/** Ordem de saída: o que tem hora certa (teste, link, saída, véspera, aula) antes dos comunicados. */
const MCP_AVISOS_PRIORIDADE = "FIELD(tipo, 'teste', 'link', 'saida', 'vespera', 'aula', 'campanha')";
/** Tempo máximo de uma rodada mandando (a rotina roda a cada 15 minutos); num clique do portal, bem menos. */
const MCP_AVISOS_RODADA_S = ['cli' => 600, 'web' => 20];
const MCP_AVISOS_TIPOS = [
    'vespera' => 'Lembrete da véspera',
    'saida' => 'Saída não registrada',
    'aula' => 'Aula de amanhã',
    'campanha' => 'Comunicado',
    'teste' => 'Teste',
    'link' => 'Link das preferências',
];
const MCP_AVISOS_STATUS = [
    'pendente' => 'Aguardando envio', 'enviando' => 'Enviando', 'manual' => 'Para mandar pelo WhatsApp', 'enviado' => 'Enviado',
    'falhou' => 'Falhou', 'cancelado' => 'Não foi preciso', 'expirado' => 'Não saiu no prazo',
];
/** Destinos dos links de clique: o código curto vai na URL (api/avisos.php?r=id.código.assinatura). */
const MCP_AVISOS_DESTINOS = ['p' => 'ponto', 'l' => 'lembretes', 's' => 'saida', 'o' => 'opiniao', 'x' => 'sair', 'm' => 'mapa'];
const MCP_AVISOS_MAPA = 'https://www.google.com/maps/search/?api=1&query=Pra%C3%A7a+da+Cruz+Vermelha%2C+10+-+Centro%2C+Rio+de+Janeiro+-+RJ';
/** Palavras que, respondidas no WhatsApp (modo cloud), desligam os avisos daquele número. */
const MCP_AVISOS_PALAVRAS_PARAR = ['parar', 'pare', 'sair', 'stop', 'cancelar', 'descadastrar', 'nao quero', 'não quero', 'parar lembretes'];

// ----------------------------------------------------------------------------- ajustes do portal
/** Valor de um ajuste (mcp_ajustes), ou o padrão. Lidos uma vez por requisição. */
function mcp_ajuste(string $nome, string $padrao = ''): string
{
    $todos = mcp_ajustes_todos();
    return array_key_exists($nome, $todos) ? $todos[$nome] : $padrao;
}

function mcp_ajustes_todos(bool $recarregar = false): array
{
    static $cache = null;
    if ($cache === null || $recarregar) {
        $cache = [];
        foreach (mcp_db()->query('SELECT nome, valor FROM mcp_ajustes')->fetchAll() as $l) {
            $cache[(string) $l['nome']] = (string) $l['valor'];
        }
    }
    return $cache;
}

function mcp_ajuste_gravar(string $nome, string $valor, string $quem): void
{
    mcp_db()->prepare('INSERT INTO mcp_ajustes (nome, valor, atualizado_por, atualizado_em) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_por = VALUES(atualizado_por), atualizado_em = VALUES(atualizado_em)')
        ->execute([$nome, mb_substr($valor, 0, 255), $quem, mcp_agora()]);
    mcp_ajustes_todos(true);
}

function mcp_ajuste_ligado(string $nome): bool
{
    return mcp_ajuste($nome, '0') === '1';
}

// ----------------------------------------------------------------------------- horário de Brasília
/** "HH:MM" de agora, em Brasília. */
function mcp_avisos_hora_local(int $agora): string
{
    return (new DateTimeImmutable('@' . $agora))->setTimezone(mcp_ponto_fuso())->format('H:i');
}

function mcp_avisos_na_janela(int $agora): bool
{
    $hora = mcp_avisos_hora_local($agora);
    return $hora >= MCP_AVISOS_JANELA[0] && $hora < MCP_AVISOS_JANELA[1];
}

/** Data (AAAA-MM-DD) somada de N dias. */
function mcp_avisos_dia_mais(string $iso, int $dias): string
{
    return (new DateTimeImmutable($iso))->modify(($dias >= 0 ? '+' : '') . $dias . ' day')->format('Y-m-d');
}

/** Chave do dia da semana (dom, seg, …) de uma data AAAA-MM-DD. */
function mcp_avisos_dia_chave(string $iso): string
{
    return array_keys(MCP_AVISOS_DIAS)[(int) (new DateTimeImmutable($iso))->format('w')];
}

/** "quinta, 1º/10" */
function mcp_avisos_dia_texto(string $iso): string
{
    $d = new DateTimeImmutable($iso);
    $dia = (int) $d->format('j');
    return mb_strtolower(MCP_AVISOS_DIAS[mcp_avisos_dia_chave($iso)]) . ', ' . ($dia === 1 ? '1º' : $d->format('d')) . '/' . $d->format('m');
}

// ----------------------------------------------------------------------------- preferências
/** Dias válidos, na ordem da semana, de "qua,seg,xyz" → ['seg', 'qua']. */
function mcp_avisos_dias(mixed $valor): array
{
    $lista = is_array($valor) ? $valor : explode(',', (string) $valor);
    $lista = array_map(static fn($d): string => strtolower(trim((string) $d)), $lista);
    return array_values(array_filter(array_keys(MCP_AVISOS_DIAS), static fn(string $d): bool => in_array($d, $lista, true)));
}

/** "segundas e quartas", "sábados". */
function mcp_avisos_dias_texto(array $dias): string
{
    $nomes = array_map(static fn(string $d): string => MCP_AVISOS_DIAS_PLURAL[$d], mcp_avisos_dias($dias));
    if (count($nomes) <= 1) {
        return $nomes[0] ?? '';
    }
    $ultimo = array_pop($nomes);
    return implode(', ', $nomes) . ' e ' . $ultimo;
}

/**
 * Número do WhatsApp (55 + DDD + 9 dígitos) de um celular brasileiro, ou null (fixo e inválido não servem).
 * Celular antigo sem o nono dígito (21 8765-4321) ganha o 9, como no Palácio Virtual.
 */
function mcp_whatsapp_numero(?string $telefone): ?string
{
    $d = mcp_telefone((string) $telefone);
    if (strlen($d) === 10 && in_array($d[2], ['6', '7', '8', '9'], true)) {
        $d = substr($d, 0, 2) . '9' . substr($d, 2);
    }
    return strlen($d) === 11 && $d[2] === '9' ? '55' . $d : null;
}

/** "(21) 9****-4321": o bastante para a pessoa reconhecer o número. */
function mcp_whatsapp_mascarado(?string $numero): string
{
    $d = mcp_digitos((string) $numero);
    if (strlen($d) === 13 && str_starts_with($d, '55')) {
        $d = substr($d, 2);
    }
    return strlen($d) === 11 ? '(' . substr($d, 0, 2) . ') ' . $d[2] . '****-' . substr($d, 7) : '';
}

/** Domingo de Páscoa (algoritmo de Meeus/Jones/Butcher; sem depender da extensão calendar do PHP). */
function mcp_avisos_pascoa(int $ano): string
{
    $a = $ano % 19;
    $b = intdiv($ano, 100);
    $c = $ano % 100;
    $h = (19 * $a + $b - intdiv($b, 4) - intdiv($b - intdiv($b + 8, 25) + 1, 3) + 15) % 30;
    $l = (32 + 2 * ($b % 4) + 2 * intdiv($c, 4) - $h - $c % 4) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mes = intdiv($h + $l - 7 * $m + 114, 31);
    $dia = ($h + $l - 7 * $m + 114) % 31 + 1;
    return sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
}

/**
 * Feriado ou dia sem expediente na sede (nome do dia), ou null. Nacionais, do estado do Rio (São Jorge,
 * Carnaval) e da cidade (São Sebastião), mais os dias que a secretaria marcar no portal (recessos).
 * AVISOS_FERIADOS = '0' (a sede abre nos feriados): valem só os dias marcados no portal.
 */
function mcp_avisos_dia_fechado(string $iso): ?string
{
    if (!preg_match('/^(\d{4})-(\d{2}-\d{2})$/', $iso, $m)) {
        return null;
    }
    $feriado = (string) mcp_cfg('AVISOS_FERIADOS', '1') !== '0' ? mcp_avisos_feriado($iso) : null;
    return $feriado ?? (in_array($iso, mcp_avisos_dias_fechados(), true) ? 'dia sem expediente na sede' : null);
}

/** Nome do feriado (nacional, do estado do Rio ou da cidade) numa data AAAA-MM-DD, ou null. */
function mcp_avisos_feriado(string $iso): ?string
{
    if (!preg_match('/^(\d{4})-(\d{2}-\d{2})$/', $iso, $m)) {
        return null;
    }
    $fixos = [
        '01-01' => 'Confraternização Universal', '01-20' => 'Dia de São Sebastião', '04-21' => 'Tiradentes', '04-23' => 'Dia de São Jorge',
        '05-01' => 'Dia do Trabalho', '09-07' => 'Independência do Brasil', '10-12' => 'Nossa Senhora Aparecida', '11-02' => 'Finados',
        '11-15' => 'Proclamação da República', '11-20' => 'Dia Nacional de Zumbi e da Consciência Negra', '12-25' => 'Natal',
    ];
    if (isset($fixos[$m[2]])) {
        return $fixos[$m[2]];
    }
    $pascoa = mcp_avisos_pascoa((int) $m[1]);
    $moveis = [mcp_avisos_dia_mais($pascoa, -48) => 'Carnaval', mcp_avisos_dia_mais($pascoa, -47) => 'Carnaval',
        mcp_avisos_dia_mais($pascoa, -2) => 'Sexta-feira Santa', mcp_avisos_dia_mais($pascoa, 60) => 'Corpus Christi'];
    return $moveis[$iso] ?? null;
}

/** Dias sem expediente marcados no portal (AAAA-MM-DD), além dos feriados. */
function mcp_avisos_dias_fechados(): array
{
    return array_values(array_filter(array_map('trim', explode(',', mcp_ajuste('dias_fechados'))), static fn(string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)));
}

/**
 * Quem recebe o lembrete da véspera naquele dia. Voluntário e diretoria: nos dias escolhidos. Os outros
 * vínculos (equipe contratada, terceirizados): só em dia útil e só se a própria pessoa escolheu os dias
 * pelo link, porque lembrete de presença mandado pela instituição a empregado parece controle de jornada.
 */
function mcp_avisos_recebe_vespera(array $c, string $dataIso): bool
{
    if (mcp_ponto_voluntario($c)) {
        return true;
    }
    return in_array(mcp_avisos_dia_chave($dataIso), MCP_AVISOS_DIAS_UTEIS, true) && ($c['aviso_dias_por'] ?? null) === 'a própria pessoa';
}

/** Voluntário ou diretoria recebe o aviso de saída não registrada (só eles somam horas). */
function mcp_avisos_recebe_saida(array $c): bool
{
    return mcp_ponto_voluntario($c) && (int) ($c['aviso_saida'] ?? 1) === 1;
}

/**
 * Canais em que o colaborador recebe um tipo de aviso agora: ['email' => endereço, 'whatsapp' => '55…'].
 * Lembrete vai por um canal só (WhatsApp automático, se houver; no modo manual, e-mail também);
 * comunicado vai pelos dois; teste e link vão por e-mail sem olhar a preferência.
 */
function mcp_avisos_canais(array $c, string $tipo): array
{
    if (!(int) $c['ativo']) {
        return [];
    }
    if ($tipo === 'campanha' && !(int) ($c['aviso_comunicados'] ?? 1)) {
        return [];
    }
    $canais = mcp_avisos_canais_todos($c);
    if ($tipo !== 'campanha' && isset($canais['whatsapp'], $canais['email']) && mcp_whatsapp_modo() !== 'manual') {
        unset($canais['email']);
    }
    return $canais;
}

/** Segredo dos links pessoais do colaborador. Criado no primeiro uso; trocar invalida os links antigos. */
function mcp_avisos_chave_colaborador(array $c, bool $trocar = false): string
{
    if (!$trocar && preg_match('/^[a-f0-9]{16}$/', (string) ($c['aviso_chave'] ?? ''))) {
        return (string) $c['aviso_chave'];
    }
    $chave = bin2hex(random_bytes(8));
    $sql = 'UPDATE mcp_colaboradores SET aviso_chave = ? WHERE id = ?' . ($trocar ? '' : ' AND aviso_chave IS NULL');
    $stmt = mcp_db()->prepare($sql);
    $stmt->execute([$chave, (int) $c['id']]);
    if (!$trocar && $stmt->rowCount() === 0) {
        // Outro processo criou antes: vale a que está no banco.
        return (string) mcp_colaborador_por_id((int) $c['id'])['aviso_chave'];
    }
    return $chave;
}

/** Recebe o que a pessoa escolheu na página de lembretes (ou a secretaria no portal) e grava. */
function mcp_avisos_preferencias_salvar(array $c, array $p, string $quem, bool $pelaPessoa): void
{
    $whatsapp = !empty($p['whatsapp']) ? 1 : 0;
    $telefone = array_key_exists('telefone', $p) ? $p['telefone'] : $c['telefone'];
    $mudouConsentimento = $whatsapp !== (int) $c['aviso_whatsapp'] || ($whatsapp && $telefone !== $c['telefone']);
    $dias = implode(',', mcp_avisos_dias($p['dias'] ?? [])) ?: null;
    // Quem escolheu os dias importa: para quem não é voluntário, o lembrete só sai se foi a própria pessoa.
    // Salvar pela página conta como escolha dela, mesmo sem mudar os dias; a secretaria mexendo em outra
    // coisa da ficha não apaga essa escolha.
    $diasPor = match (true) {
        $dias === null => null,
        $pelaPessoa => 'a própria pessoa',
        $dias !== ($c['aviso_dias'] ?: null) => $quem,
        default => $c['aviso_dias_por'] ?? null,
    };
    mcp_db()->prepare('UPDATE mcp_colaboradores SET aviso_email = ?, aviso_whatsapp = ?, aviso_dias = ?, aviso_saida = ?, aviso_comunicados = ?,
        telefone = ?, aviso_whatsapp_em = ?, aviso_whatsapp_por = ?, aviso_whatsapp_como = ?, aviso_dias_por = ?, aviso_atualizado_em = ?, atualizado_em = ? WHERE id = ?')
        ->execute([
            !empty($p['email']) ? 1 : 0, $whatsapp, $dias,
            !empty($p['saida']) ? 1 : 0, !empty($p['comunicados']) ? 1 : 0, $telefone,
            $mudouConsentimento ? ($whatsapp ? mcp_agora() : null) : $c['aviso_whatsapp_em'],
            $mudouConsentimento ? ($whatsapp ? ($pelaPessoa ? 'a própria pessoa' : $quem) : null) : $c['aviso_whatsapp_por'],
            $mudouConsentimento ? ($whatsapp ? mb_substr((string) ($p['como'] ?? ''), 0, 160) ?: null : null) : ($c['aviso_whatsapp_como'] ?? null),
            $diasPor,
            mcp_agora(), mcp_agora(), (int) $c['id'],
        ]);
    // Religar o WhatsApp de quem tinha pedido para parar tira o número da lista de bloqueio.
    if ($whatsapp && ($numero = mcp_whatsapp_numero((string) $telefone)) !== null && $mudouConsentimento) {
        mcp_db()->prepare("DELETE FROM mcp_avisos_bloqueios WHERE canal = 'whatsapp' AND destino_hash = ?")->execute([mcp_avisos_hash('whatsapp', $numero)]);
    }
    mcp_registrar(null, 'aviso_preferencias', '#' . $c['id'] . ' · ' . ($pelaPessoa ? 'pela pessoa' : $quem) . ' · e-mail ' . (!empty($p['email']) ? 'sim' : 'não')
        . ' · WhatsApp ' . ($whatsapp ? 'sim' : 'não') . ' · dias ' . (implode(',', mcp_avisos_dias($p['dias'] ?? [])) ?: '—'));
}

// ----------------------------------------------------------------------------- links pessoais e de clique
function mcp_avisos_assinar(string $dados, string $chavePessoa = ''): string
{
    return substr(hash_hmac('sha256', $dados, mcp_segredo('avisos') . '|' . $chavePessoa), 0, 32);
}

/** Pessoa de um aluno (que não tem cadastro aqui): "a" + hash do e-mail (ou do celular, sem e-mail). */
function mcp_avisos_hash(string $canal, string $destino): string
{
    return substr(hash_hmac('sha256', $canal . '|' . mb_strtolower(trim($destino)), mcp_segredo('avisos_hash')), 0, 32);
}

/** Endereço de uma página pessoal (lembretes, saida, opiniao, sair). */
function mcp_avisos_pagina(string $uso): string
{
    return mcp_site_url() . '/matricula-cursos-presenciais/ponto/' . $uso . '/';
}

/** Token de uma página pessoal: uso, pessoa ("c12" ou "a<hash>"), extra e validade, assinados. */
function mcp_avisos_token(string $uso, string $pessoa, string $extra = '', ?int $agora = null): string
{
    $chave = '';
    if (preg_match('/^c(\d{1,9})$/', $pessoa, $m)) {
        $c = mcp_colaborador_por_id((int) $m[1]);
        $chave = $c ? mcp_avisos_chave_colaborador($c) : '';
    }
    $exp = ($agora ?? time()) + (MCP_AVISOS_LINK_DIAS[$uso] ?? 7) * 86400;
    $corpo = mcp_ponto_b64((string) json_encode([$uso, $pessoa, $extra, $exp]));
    return $corpo . '.' . mcp_avisos_assinar("t|$corpo", $chave);
}

function mcp_avisos_link(string $uso, string $pessoa, string $extra = '', ?int $agora = null): string
{
    // O token vai no fragmento (#t=): o navegador não manda essa parte ao servidor, então ela não fica
    // no log da hospedagem. A página lê de location.hash (e ainda aceita ?t= dos links antigos).
    return mcp_avisos_pagina($uso) . '#t=' . mcp_avisos_token($uso, $pessoa, $extra, $agora);
}

/**
 * Confere o token de uma página pessoal.
 * @return array{ok: bool, vencido?: bool, pessoa?: string, extra?: string, colaborador?: ?array}
 */
function mcp_avisos_token_ler(string $uso, mixed $token, ?int $agora = null): array
{
    if (!is_string($token) || !preg_match('/^([A-Za-z0-9_-]{10,600})\.([a-f0-9]{32})$/', $token, $m)) {
        return ['ok' => false];
    }
    $dados = json_decode((string) mcp_ponto_deb64($m[1]), true);
    if (!is_array($dados) || count($dados) !== 4 || ($dados[0] ?? null) !== $uso || !is_string($dados[1]) || !is_string($dados[2]) || !is_int($dados[3])) {
        return ['ok' => false];
    }
    $colaborador = null;
    $chave = '';
    if (preg_match('/^c(\d{1,9})$/', $dados[1], $p)) {
        $colaborador = mcp_colaborador_por_id((int) $p[1]);
        if (!$colaborador || empty($colaborador['aviso_chave'])) {
            return ['ok' => false];
        }
        $chave = (string) $colaborador['aviso_chave'];
    } elseif (!preg_match('/^a[a-f0-9]{32}$/', $dados[1])) {
        return ['ok' => false];
    }
    if (!hash_equals(mcp_avisos_assinar("t|{$m[1]}", $chave), $m[2])) {
        return ['ok' => false];
    }
    if ($dados[3] < ($agora ?? time())) {
        return ['ok' => false, 'vencido' => true];
    }
    return ['ok' => true, 'pessoa' => $dados[1], 'extra' => $dados[2], 'colaborador' => $colaborador];
}

/**
 * Chave pessoal que entra na assinatura dos links de um aviso (?r= e ?u=): "Invalidar os links já enviados"
 * e a troca do e-mail ou do celular derrubam também os links das mensagens que já saíram. Aluno não tem
 * chave (''). $criar: ao montar o link, o colaborador sem chave ganha uma; ao conferir, sem chave nada vale.
 */
function mcp_avisos_chave_do_aviso(?array $aviso, bool $criar): string
{
    if ($aviso === null || empty($aviso['colaborador_id'])) {
        return '';
    }
    $c = mcp_colaborador_por_id((int) $aviso['colaborador_id']);
    if (!$c) {
        return '-';
    }
    if ($criar) {
        return mcp_avisos_chave_colaborador($c);
    }
    return preg_match('/^[a-f0-9]{16}$/', (string) ($c['aviso_chave'] ?? '')) ? (string) $c['aviso_chave'] : '-';
}

// ----------------------------------------------------------------------------- descadastro de um clique
/**
 * Cabeçalhos do descadastro de um clique (RFC 8058): o Gmail e outros mostram "cancelar inscrição" ao lado
 * do remetente e mandam um POST a api/avisos.php?u=, em vez de a pessoa marcar a mensagem como spam.
 */
function mcp_avisos_cabecalhos_descadastro(array $aviso): array
{
    $id = (int) $aviso['id'];
    $url = mcp_site_url() . '/matricula-cursos-presenciais/api/avisos.php?u=' . $id . '.' . substr(mcp_avisos_assinar("u|$id", mcp_avisos_chave_do_aviso($aviso, true)), 0, 16);
    return ['List-Unsubscribe' => "<$url>", 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
}

/** O aviso por e-mail de um código de descadastro (id.assinatura), ou null. */
function mcp_avisos_descadastro_aviso(string $codigo): ?array
{
    if (!preg_match('/^(\d{1,10})\.([a-f0-9]{16})$/', $codigo, $m)) {
        return null;
    }
    $aviso = mcp_aviso_por_id((int) $m[1]);
    if (!$aviso || $aviso['canal'] !== 'email' || !hash_equals(substr(mcp_avisos_assinar("u|{$m[1]}", mcp_avisos_chave_do_aviso($aviso, false)), 0, 16), $m[2])) {
        return null;
    }
    return $aviso;
}

/**
 * Descadastro de um clique: o colaborador deixa de receber avisos por e-mail; o aluno entra na lista de
 * bloqueio. Devolve false se não valeu (o e-mail do cadastro já não é o da mensagem).
 */
function mcp_avisos_descadastrar(array $aviso): bool
{
    if (!empty($aviso['colaborador_id'])) {
        $id = (int) $aviso['colaborador_id'];
        $c = mcp_colaborador_por_id($id);
        // Quem recebeu por engano (o e-mail estava errado e foi corrigido) não desliga o e-mail certo.
        if (!$c || mb_strtolower(trim((string) $c['email'])) !== mb_strtolower(trim((string) $aviso['destino']))) {
            return false;
        }
        mcp_db()->prepare('UPDATE mcp_colaboradores SET aviso_email = 0, aviso_atualizado_em = ?, atualizado_em = ? WHERE id = ?')->execute([mcp_agora(), mcp_agora(), $id]);
        mcp_db()->prepare("UPDATE mcp_avisos SET status = 'cancelado', erro = 'pediu para parar', atualizado_em = ? WHERE colaborador_id = ? AND canal = 'email' AND status IN ('pendente', 'manual')")
            ->execute([mcp_agora(), $id]);
    } else {
        mcp_avisos_bloquear_hash('email', mcp_avisos_hash('email', (string) $aviso['destino']), 'descadastro');
    }
    mcp_registrar(null, 'aviso_descadastro', '#' . $aviso['id'] . ' · ' . $aviso['tipo']);
    return true;
}

/** Link de clique de um aviso: registra o clique e leva ao destino (api/avisos.php?r=). */
function mcp_avisos_link_clique(int $avisoId, string $destino): string
{
    return mcp_site_url() . '/matricula-cursos-presenciais/api/avisos.php?r=' . mcp_avisos_sufixo_clique($avisoId, $destino);
}

/** O que vai depois de "?r=": id.código.assinatura. É o parâmetro do botão dos modelos do WhatsApp. */
function mcp_avisos_sufixo_clique(int $avisoId, string $destino): string
{
    $codigo = (string) array_search($destino, MCP_AVISOS_DESTINOS, true);
    $chave = $avisoId > 0 ? mcp_avisos_chave_do_aviso(mcp_aviso_por_id($avisoId), true) : '';
    return $avisoId . '.' . $codigo . '.' . substr(mcp_avisos_assinar("r|$avisoId|$codigo", $chave), 0, 16);
}

/**
 * Prévia de link e robôs (WhatsApp, Meta, Telegram, Slack, buscadores, verificadores de e-mail e
 * programas): abrem o link sem ninguém ter clicado. Não contam como clique e não ganham link pessoal.
 */
function mcp_avisos_eh_robo(string $agente): bool
{
    return $agente === '' || (bool) preg_match('~whatsapp|facebookexternalhit|facebot|meta-externalagent|telegrambot|slackbot|discordbot|twitterbot|linkedinbot'
        . '|skypeuripreview|microsoftpreview|googlebot|bingbot|applebot|yandex|duckduckbot|googleimageproxy|ggpht|yahoo! slurp|embedly|iframely|outlook-ios-linkpreview'
        . '|barracuda|mimecast|proofpoint|safelinks|bot\b|crawler|spider|preview|python-|curl/|wget/|go-http-client|okhttp|java/|headlesschrome~i', $agente);
}

/**
 * Registra o clique e devolve a URL de destino (com um link pessoal novo, quando é o caso), ou null se
 * o código não vale. O clique conta uma vez por aviso em clicado_em; cliques soma todos.
 */
function mcp_avisos_clique(string $r, ?int $agora = null): ?string
{
    $agora ??= time();
    if (!preg_match('/^(\d{1,10})\.([a-z])\.([a-f0-9]{16})$/', $r, $m) || !isset(MCP_AVISOS_DESTINOS[$m[2]])) {
        return null;
    }
    $aviso = mcp_aviso_por_id((int) $m[1]);
    if (!$aviso || !hash_equals(substr(mcp_avisos_assinar("r|{$m[1]}|{$m[2]}", mcp_avisos_chave_do_aviso($aviso, false)), 0, 16), $m[3])) {
        return null;
    }
    mcp_db()->prepare('UPDATE mcp_avisos SET cliques = LEAST(cliques + 1, 65535), clicado_em = COALESCE(clicado_em, ?) WHERE id = ?')
        ->execute([gmdate('Y-m-d H:i:s', $agora), (int) $aviso['id']]);
    $destino = MCP_AVISOS_DESTINOS[$m[2]];
    if ($destino === 'ponto') {
        return mcp_site_url() . '/ponto/';
    }
    if ($destino === 'mapa') {
        return MCP_AVISOS_MAPA;
    }
    // Link pessoal: só dentro do prazo, e gerado agora (o link do aviso não carrega o token).
    if ((int) strtotime($aviso['criado_em'] . ' UTC') < $agora - MCP_AVISOS_CLIQUE_DIAS * 86400 || empty($aviso['pessoa'])) {
        return mcp_site_url() . '/ponto/';
    }
    // Se o e-mail ou o número da mensagem não é mais o do cadastro (estava errado e foi corrigido), quem
    // clica não é a pessoa: vai para o ponto, sem link pessoal.
    if (!empty($aviso['colaborador_id'])) {
        $c = mcp_colaborador_por_id((int) $aviso['colaborador_id']);
        if (!$c || !(int) $c['ativo'] || !in_array(mb_strtolower((string) $aviso['destino']), array_map('mb_strtolower', mcp_avisos_contatos($c)), true)) {
            return mcp_site_url() . '/ponto/';
        }
    }
    $pessoa = (string) $aviso['pessoa'];
    $dados = json_decode((string) $aviso['dados'], true) ?: [];
    if ($destino === 'saida') {
        return mcp_avisos_link('saida', $pessoa, (string) (int) ($dados['ponto'] ?? 0), $agora);
    }
    if ($destino === 'opiniao') {
        return mcp_avisos_link('opiniao', $pessoa, (string) (int) ($aviso['campanha_id'] ?? 0), $agora);
    }
    if ($destino === 'sair' && str_starts_with($pessoa, 'a')) {
        return mcp_avisos_link('sair', $pessoa, (string) ($dados['whatsapp_hash'] ?? ''), $agora);
    }
    return str_starts_with($pessoa, 'c') ? mcp_avisos_link('lembretes', $pessoa, '', $agora) : mcp_site_url() . '/ponto/';
}

// ----------------------------------------------------------------------------- bloqueios (alunos)
function mcp_avisos_bloqueado(string $canal, string $destino): bool
{
    $stmt = mcp_db()->prepare('SELECT 1 FROM mcp_avisos_bloqueios WHERE canal = ? AND destino_hash = ?');
    $stmt->execute([$canal, mcp_avisos_hash($canal, $destino)]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Aluno que disse "não quero mais receber" na página (pelo link de um e-mail, que não conhece o celular dele):
 * vale também para o WhatsApp das aulas. O descadastro de um clique do e-mail vale só para o e-mail.
 */
function mcp_avisos_aluno_saiu(array $dados): bool
{
    $email = (string) ($dados['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $stmt = mcp_db()->prepare("SELECT 1 FROM mcp_avisos_bloqueios WHERE canal = 'email' AND destino_hash = ? AND origem = 'pagina'");
    $stmt->execute([mcp_avisos_hash('email', $email)]);
    return (bool) $stmt->fetchColumn();
}

/** Bloqueia um canal pelo hash (a página "sair" só conhece o hash, nunca o endereço). */
function mcp_avisos_bloquear_hash(string $canal, string $hash, string $origem): void
{
    if (!preg_match('/^[a-f0-9]{32}$/', $hash)) {
        return;
    }
    mcp_db()->prepare('INSERT IGNORE INTO mcp_avisos_bloqueios (canal, destino_hash, origem, criado_em) VALUES (?, ?, ?, ?)')
        ->execute([$canal, $hash, $origem, mcp_agora()]);
    // O que já estava na fila para esse destino não sai mais (o hash tem segredo: a conta é feita aqui).
    $stmt = mcp_db()->prepare("SELECT id, destino, status FROM mcp_avisos WHERE status IN ('pendente', 'manual') AND canal = ? AND colaborador_id IS NULL");
    $stmt->execute([$canal]);
    foreach ($stmt->fetchAll() as $a) {
        // Só se ainda estiver na fila: se a rotina acabou de pegar (enviando), o envio fica registrado como foi.
        if (hash_equals($hash, mcp_avisos_hash($canal, (string) $a['destino']))) {
            mcp_aviso_atualizar((int) $a['id'], ['status' => 'cancelado', 'erro' => 'pediu para não receber'], (string) $a['status']);
        }
    }
}

// ----------------------------------------------------------------------------- registro dos avisos
function mcp_aviso_por_id(int $id): ?array
{
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_avisos WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function mcp_aviso_por_chave(string $chave): ?array
{
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_avisos WHERE chave = ?');
    $stmt->execute([$chave]);
    return $stmt->fetch() ?: null;
}

/**
 * Até quando um aviso pode sair. Véspera e aula: até as 20h da véspera (depois disso, "amanhã" viraria
 * "hoje" e a mensagem estaria errada); comunicado: o prazo da fase (lib/comunicacao.php); saída não
 * registrada: 3 dias; o resto, 1 dia.
 */
function mcp_aviso_validade(array $aviso, int $agora): string
{
    if (in_array($aviso['tipo'], ['vespera', 'aula'], true) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $aviso['referencia'])) {
        return mcp_ponto_local_para_utc(mcp_avisos_dia_mais((string) $aviso['referencia'], -1) . ' ' . MCP_AVISOS_JANELA[1] . ':00');
    }
    if ($aviso['tipo'] === 'campanha' && !empty($aviso['campanha_id']) && ($campanha = mcp_campanha((int) $aviso['campanha_id']))) {
        return mcp_campanha_validade($campanha, $agora);
    }
    return gmdate('Y-m-d H:i:s', $agora + ($aviso['tipo'] === 'saida' ? 3 : 1) * 86400);
}

/**
 * Põe um aviso na fila. $a: chave, tipo, canal, nome, destino e, se houver, campanha_id,
 * colaborador_id, pessoa, referencia, dados (array), agendado_para, expira_em. Devolve o id, ou null se
 * a chave já existe (o aviso já foi preparado antes).
 */
function mcp_aviso_criar(array $a, ?int $agora = null): ?int
{
    $quando = gmdate('Y-m-d H:i:s', $agora ?? time());
    $status = $a['canal'] === 'whatsapp' && mcp_whatsapp_modo() === 'manual' && $a['tipo'] !== 'teste' ? 'manual' : 'pendente';
    $stmt = mcp_db()->prepare('INSERT IGNORE INTO mcp_avisos (chave, tipo, canal, campanha_id, colaborador_id, pessoa, nome, destino, referencia, dados,
        status, agendado_para, expira_em, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        mb_substr((string) $a['chave'], 0, 120), $a['tipo'], $a['canal'], $a['campanha_id'] ?? null, $a['colaborador_id'] ?? null, $a['pessoa'] ?? null,
        mb_substr((string) $a['nome'], 0, 160), mb_substr((string) $a['destino'], 0, 190), $a['referencia'] ?? null,
        isset($a['dados']) ? json_encode($a['dados'], JSON_UNESCAPED_UNICODE) : null,
        $status, $a['agendado_para'] ?? $quando, $a['expira_em'] ?? null, $quando, $quando,
    ]);
    return $stmt->rowCount() > 0 ? (int) mcp_db()->lastInsertId() : null;
}

/**
 * Quem recebe agora, conferido de novo: nome, destino e se ainda pode receber por este canal.
 * @return array{ok: bool, nome?: string, destino?: string, colaborador?: ?array, motivo?: string}
 */
function mcp_aviso_destinatario(array $aviso): array
{
    if ($aviso['tipo'] === 'teste' || ($aviso['tipo'] === 'link' && $aviso['canal'] === 'email')) {
        $c = $aviso['colaborador_id'] ? mcp_colaborador_por_id((int) $aviso['colaborador_id']) : null;
        return ['ok' => true, 'nome' => (string) ($c['nome'] ?? $aviso['nome']), 'destino' => (string) $aviso['destino'], 'colaborador' => $c];
    }
    // WhatsApp desligado no portal: uma pausa, não um cancelamento (religado, sai o que ainda estiver no prazo).
    if ($aviso['canal'] === 'whatsapp' && !mcp_whatsapp_ativo()) {
        return ['ok' => false, 'motivo' => 'WhatsApp desligado no portal', 'pausa' => true];
    }
    $ajuste = MCP_AVISOS_AJUSTES[$aviso['tipo']] ?? null;
    if ($ajuste !== null && !mcp_ajuste_ligado($ajuste)) {
        return ['ok' => false, 'motivo' => 'lembrete desligado no portal'];
    }
    if ($aviso['tipo'] === 'aula' && $aviso['canal'] === 'whatsapp' && !mcp_ajuste_ligado('aula_whatsapp')) {
        return ['ok' => false, 'motivo' => 'WhatsApp da aula desligado no portal'];
    }
    // Recesso marcado depois do preparo (às 8h) também barra o lembrete da aula.
    if ($aviso['tipo'] === 'aula' && mcp_avisos_dia_fechado((string) $aviso['referencia']) !== null) {
        return ['ok' => false, 'motivo' => 'sede fechada nesse dia'];
    }
    if ($aviso['tipo'] === 'aula' && $aviso['canal'] === 'whatsapp' && mcp_avisos_aluno_saiu(json_decode((string) ($aviso['dados'] ?? ''), true) ?: [])) {
        return ['ok' => false, 'motivo' => 'pediu para não receber'];
    }
    if ($aviso['tipo'] === 'campanha') {
        $campanha = !empty($aviso['campanha_id']) ? mcp_campanha((int) $aviso['campanha_id']) : null;
        if (!$campanha || $campanha['status'] === 'cancelada') {
            return ['ok' => false, 'motivo' => 'comunicado interrompido'];
        }
    }
    if ($aviso['colaborador_id']) {
        $c = mcp_colaborador_por_id((int) $aviso['colaborador_id']);
        if (!$c || !(int) $c['ativo']) {
            return ['ok' => false, 'motivo' => 'colaborador inativo'];
        }
        // Comunicado confere também "receber comunicados"; lembrete, só se a pessoa ainda aceita o canal
        // (a regra de "um canal só" vale na hora de preparar, não aqui).
        $canais = $aviso['tipo'] === 'campanha' ? mcp_avisos_canais($c, 'campanha') : mcp_avisos_canais_todos($c);
        $destino = $canais[(string) $aviso['canal']] ?? null;
        if ($destino === null) {
            return ['ok' => false, 'motivo' => 'desligou este canal'];
        }
        if ($aviso['tipo'] === 'saida' && !mcp_avisos_recebe_saida($c)) {
            return ['ok' => false, 'motivo' => 'desligou o aviso de saída'];
        }
        if ($aviso['tipo'] === 'vespera' && !in_array(mcp_avisos_dia_chave((string) $aviso['referencia']), mcp_avisos_dias($c['aviso_dias']), true)) {
            return ['ok' => false, 'motivo' => 'tirou este dia dos lembretes'];
        }
        if ($aviso['tipo'] === 'vespera' && (!mcp_avisos_recebe_vespera($c, (string) $aviso['referencia']) || mcp_avisos_dia_fechado((string) $aviso['referencia']) !== null)) {
            return ['ok' => false, 'motivo' => 'não se aplica a este dia'];
        }
        // Lembrete é por um canal só: se a pessoa passou a receber pelo WhatsApp automático e ele já está
        // na fila (ou saiu), o e-mail preparado antes não sai também. No modo manual, o e-mail é a garantia
        // de quem a fila não alcançou: se a secretaria já mandou o WhatsApp, ele não sai em dobro.
        if ($aviso['canal'] === 'email' && in_array($aviso['tipo'], ['vespera', 'saida'], true) && str_ends_with((string) $aviso['chave'], '|email')) {
            $gemeo = mcp_aviso_por_chave(substr((string) $aviso['chave'], 0, -5) . 'whatsapp');
            $umCanal = !isset(mcp_avisos_canais($c, (string) $aviso['tipo'])['email']);
            if ($gemeo && ($gemeo['status'] === 'enviado' || ($umCanal && in_array($gemeo['status'], ['pendente', 'enviando'], true)))) {
                return ['ok' => false, 'motivo' => $gemeo['status'] === 'enviado' ? 'já foi pelo WhatsApp' : 'vai pelo WhatsApp'];
            }
        }
        return ['ok' => true, 'nome' => (string) $c['nome'], 'destino' => $destino, 'colaborador' => $c];
    }
    if (mcp_avisos_bloqueado((string) $aviso['canal'], (string) $aviso['destino'])) {
        return ['ok' => false, 'motivo' => 'pediu para não receber'];
    }
    return ['ok' => true, 'nome' => (string) $aviso['nome'], 'destino' => (string) $aviso['destino'], 'colaborador' => null];
}

/** E-mail e número (com o 55 e o 9) do cadastro, com ou sem autorização: para conferir de quem é um destino. */
function mcp_avisos_contatos(array $c): array
{
    return array_values(array_filter([mb_strtolower(trim((string) ($c['email'] ?? ''))), (string) mcp_whatsapp_numero((string) ($c['telefone'] ?? ''))]));
}

/** Os dois canais que a pessoa aceita, sem a regra de "um canal só" dos lembretes. */
function mcp_avisos_canais_todos(array $c): array
{
    $canais = [];
    $email = mb_strtolower(trim((string) ($c['email'] ?? '')));
    if ((int) ($c['aviso_email'] ?? 1) === 1 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $canais['email'] = $email;
    }
    $numero = mcp_whatsapp_numero((string) ($c['telefone'] ?? ''));
    if ((int) ($c['aviso_whatsapp'] ?? 0) === 1 && $numero !== null && mcp_whatsapp_ativo()) {
        $canais['whatsapp'] = $numero;
    }
    return $canais;
}

/** Atualiza um aviso. Com $seStatus, só se ele ainda estiver nesse status; devolve se mudou. */
function mcp_aviso_atualizar(int $id, array $campos, ?string $seStatus = null): bool
{
    foreach (array_keys($campos) as $coluna) {
        if (!preg_match('/^[a-z_]+$/', (string) $coluna)) {
            throw new InvalidArgumentException("coluna inválida: $coluna");
        }
    }
    $campos['atualizado_em'] = mcp_agora();
    $sets = implode(', ', array_map(static fn($c) => "$c = :$c", array_keys($campos)));
    $campos['id'] = $id;
    $sql = "UPDATE mcp_avisos SET $sets WHERE id = :id";
    if ($seStatus !== null) {
        $sql .= ' AND status = :se_status';
        $campos['se_status'] = $seStatus;
    }
    $stmt = mcp_db()->prepare($sql);
    $stmt->execute($campos);
    return $stmt->rowCount() > 0;
}

// ----------------------------------------------------------------------------- WhatsApp
/**
 * cloud (API oficial da Meta), evolution (o WhatsApp do Palácio Virtual, pela Evolution API), webhook
 * (Make ou similar) ou manual (fila do portal).
 */
function mcp_whatsapp_modo(): string
{
    if ((string) mcp_cfg('WHATSAPP_CLOUD_TOKEN', '') !== '' && (string) mcp_cfg('WHATSAPP_CLOUD_NUMERO_ID', '') !== '') {
        return 'cloud';
    }
    // A Evolution não é a API oficial: só vale depois que a instituição registra no portal que aceita o risco.
    if ((string) mcp_cfg('WHATSAPP_EVOLUTION_URL', '') !== '' && mcp_ajuste_ligado('evolution_riscos')) {
        return 'evolution';
    }
    if ((string) mcp_cfg('WHATSAPP_WEBHOOK_URL', '') !== '') {
        return 'webhook';
    }
    return 'manual';
}

/**
 * Servidor, instância e chave da Evolution (a mesma instância do Palácio Virtual), conferidos; ou o que
 * falta. A chave de preferência é o token da instância (só mexe nela), não a chave global do servidor.
 * @return array{ok: true, url: string, instancia: string, chave: string}|array{ok: false, erro: string}
 */
function mcp_whatsapp_evolution_config(): array
{
    $url = rtrim(trim((string) mcp_cfg('WHATSAPP_EVOLUTION_URL', '')), '/');
    $partes = parse_url($url);
    $local = in_array($partes['host'] ?? '', ['127.0.0.1', 'localhost'], true);
    if (!is_array($partes) || !isset($partes['host']) || !in_array($partes['scheme'] ?? '', $local ? ['http', 'https'] : ['https'], true)
        || isset($partes['user']) || isset($partes['query']) || isset($partes['fragment'])) {
        return ['ok' => false, 'erro' => 'WHATSAPP_EVOLUTION_URL precisa ser o endereço https do servidor da Evolution'];
    }
    $instancia = trim((string) mcp_cfg('WHATSAPP_EVOLUTION_INSTANCIA', ''));
    if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $instancia)) {
        return ['ok' => false, 'erro' => 'WHATSAPP_EVOLUTION_INSTANCIA ausente ou inválida (letras, números, ponto, hífen e sublinhado)'];
    }
    $chave = trim((string) mcp_cfg('WHATSAPP_EVOLUTION_CHAVE', ''));
    if (strlen($chave) < 8) {
        return ['ok' => false, 'erro' => 'WHATSAPP_EVOLUTION_CHAVE ausente ou curta'];
    }
    return ['ok' => true, 'url' => $url, 'instancia' => $instancia, 'chave' => $chave];
}

/** A mensagem de erro de um corpo da Evolution ({response: {message}}, {message} ou {error}). */
function mcp_whatsapp_evolution_erro(array $dados): ?string
{
    $candidata = $dados['response']['message'] ?? $dados['message'] ?? $dados['error'] ?? null;
    if (is_array($candidata)) {
        $primeiro = $candidata[0] ?? null;
        if (is_array($primeiro) && ($primeiro['exists'] ?? null) === false) {
            return 'esse número não tem WhatsApp';
        }
        $partes = array_map(static fn($c): string => is_string($c) ? $c : (string) json_encode($c, JSON_UNESCAPED_UNICODE), $candidata);
        return $partes ? mb_substr(implode('; ', $partes), 0, 200) : null;
    }
    return is_string($candidata) && $candidata !== '' ? mb_substr($candidata, 0, 200) : null;
}

/**
 * Provedor do WhatsApp fora do ar ou recusando a configuração nesta rodada (servidor caiu, chave errada,
 * instância sumiu): o resto do WhatsApp fica para a próxima rodada, sem gastar tentativas nem esperar
 * o tempo de cada mensagem. Vale só para este processo.
 */
function mcp_whatsapp_fora(?string $motivo = null): ?string
{
    static $fora = null;
    if ($motivo !== null) {
        $fora = $motivo;
    }
    return $fora;
}

/** A secretaria ligou o WhatsApp no portal (em qualquer modo). */
function mcp_whatsapp_ativo(): bool
{
    return mcp_ajuste_ligado('whatsapp_ativo');
}

function mcp_whatsapp_modo_nome(string $modo): string
{
    return ['cloud' => 'automático, pela API oficial do WhatsApp', 'evolution' => 'automático, pelo WhatsApp do Palácio Virtual (Evolution)',
        'webhook' => 'automático, pelo cenário do Make', 'manual' => 'manual, pela fila do portal'][$modo] ?? $modo;
}

/** Link que abre o WhatsApp com a mensagem pronta (fila manual). */
function mcp_whatsapp_link_manual(string $numero, string $texto): string
{
    return 'https://wa.me/' . mcp_digitos($numero) . '?text=' . rawurlencode($texto);
}

/**
 * POST JSON com curl. HTTPS sempre; HTTP só para teste local em 127.0.0.1. Devolve [status, corpo
 * decodificado, erro, tempo esgotado]: com o tempo esgotado, o outro lado pode ter recebido mesmo assim.
 */
function mcp_avisos_post_json(string $url, string $corpo, array $cabecalhos, int $tempo = 20): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $corpo,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $cabecalhos),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => max(1, $tempo),
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true) ? CURLPROTO_HTTP : 0),
    ]);
    $resposta = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erro = curl_error($ch);
    // Esgotou depois de conectar: o pedido pode ter chegado (sem conectar, com certeza não chegou).
    $esgotado = curl_errno($ch) === CURLE_OPERATION_TIMEDOUT && (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME) > 0;
    curl_close($ch);
    $dados = is_string($resposta) ? json_decode($resposta, true) : null;
    return [$status, is_array($dados) ? $dados : [], $erro, $esgotado];
}

/**
 * Manda uma mensagem de WhatsApp pelo modo configurado. $msg: whatsapp (texto livre) e modelo
 * (nome, parametros, botao) para a API oficial.
 * Além de ok/erro: definitivo (não adianta tentar de novo) e incerto (o tempo acabou depois de o pedido
 * chegar: pode ter saído, então não se manda de novo). Provedor fora do ar marca mcp_whatsapp_fora().
 * @return array{ok: bool, provedor: string, id: ?string, erro: ?string, definitivo?: bool, incerto?: bool}
 */
function mcp_whatsapp_enviar(string $numero, array $msg, int $avisoId): array
{
    $modo = mcp_whatsapp_modo();
    // Sem resposta, servidor com erro, chave recusada ou endereço inexistente: o resto espera a próxima rodada.
    $cair = static function (string $provedor, int $status, string $erro): void {
        if ($status === 0 || $status === 401 || $status === 403 || $status === 404 || $status >= 500) {
            mcp_whatsapp_fora("$provedor: $erro");
        }
    };
    $incerto = static function (string $provedor, string $quem): array {
        mcp_whatsapp_fora("$provedor: sem confirmação a tempo");
        return ['ok' => true, 'provedor' => $provedor, 'id' => null, 'erro' => "$quem não confirmou a tempo; a mensagem pode ter saído (não vai de novo)", 'incerto' => true];
    };
    if ($modo === 'cloud') {
        $modelo = $msg['modelo'] ?? null;
        if (!is_array($modelo) || empty($modelo['nome'])) {
            return ['ok' => false, 'provedor' => 'cloud', 'id' => null, 'erro' => 'sem modelo aprovado para este aviso', 'definitivo' => true];
        }
        $componentes = [];
        if (!empty($modelo['parametros'])) {
            // A Meta recusa parâmetro com quebra de linha, tabulação ou mais de 4 espaços seguidos.
            $componentes[] = ['type' => 'body', 'parameters' => array_map(static fn($p): array => ['type' => 'text', 'text' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $p)), 0, 900)],
                array_values($modelo['parametros']))];
        }
        if (($modelo['botao'] ?? '') !== '') {
            $componentes[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => (string) $modelo['botao']]]];
        }
        $corpo = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $numero, 'type' => 'template',
            'template' => ['name' => (string) $modelo['nome'], 'language' => ['code' => (string) mcp_cfg('WHATSAPP_CLOUD_IDIOMA', 'pt_BR')], 'components' => $componentes]];
        $url = rtrim((string) mcp_cfg('WHATSAPP_CLOUD_BASE', 'https://graph.facebook.com'), '/') . '/' . mcp_cfg('WHATSAPP_CLOUD_VERSAO', 'v23.0') . '/'
            . rawurlencode((string) mcp_cfg('WHATSAPP_CLOUD_NUMERO_ID', '')) . '/messages';
        [$status, $resposta, $erroCurl, $esgotado] = mcp_avisos_post_json($url, (string) json_encode($corpo, JSON_UNESCAPED_UNICODE), ['Authorization: Bearer ' . mcp_cfg('WHATSAPP_CLOUD_TOKEN', '')]);
        if ($esgotado) {
            return $incerto('cloud', 'A Meta');
        }
        $id = $resposta['messages'][0]['id'] ?? null;
        if ($status >= 200 && $status < 300 && is_string($id)) {
            return ['ok' => true, 'provedor' => 'cloud', 'id' => mb_substr($id, 0, 120), 'erro' => null];
        }
        $erro = is_string($resposta['error']['message'] ?? null) ? $resposta['error']['message'] : ($status > 0 ? "HTTP $status" : ($erroCurl ?: 'sem resposta'));
        $codigo = (int) ($resposta['error']['code'] ?? 0);
        $cair('cloud', $status, $erro);
        // 131026: não dá para entregar a esse número; 131049: a Meta segurou por limite por pessoa (pede 24 h).
        return ['ok' => false, 'provedor' => 'cloud', 'id' => null, 'erro' => mb_substr(($codigo ? "#$codigo " : '') . $erro, 0, 250), 'definitivo' => in_array($codigo, [131026, 131049], true)];
    }
    if ($modo === 'evolution') {
        $cfg = mcp_whatsapp_evolution_config();
        if (!$cfg['ok']) {
            mcp_whatsapp_fora('evolution: ' . $cfg['erro']);
            return ['ok' => false, 'provedor' => 'evolution', 'id' => null, 'erro' => $cfg['erro']];
        }
        // O número é o mesmo do Palácio: quem responde cai no robô de lá, e não aqui.
        $texto = rtrim((string) ($msg['whatsapp'] ?? '')) . "\n\n_Mensagem automática da secretaria. Para não receber mais, use o link acima._";
        $tempo = max(1, (int) mcp_cfg('WHATSAPP_EVOLUTION_TEMPO_S', MCP_WHATSAPP_EVOLUTION_TEMPO_S));
        [$status, $resposta, $erroCurl, $esgotado] = mcp_avisos_post_json($cfg['url'] . '/message/sendText/' . rawurlencode($cfg['instancia']),
            (string) json_encode(['number' => $numero, 'text' => $texto, 'linkPreview' => false], JSON_UNESCAPED_UNICODE), ['apikey: ' . $cfg['chave']], $tempo);
        if ($esgotado) {
            return $incerto('evolution', 'A Evolution');
        }
        // A Evolution devolve alguns erros com 200 e {"error": true, "message": …}.
        if ($status >= 200 && $status < 300 && ($resposta['error'] ?? null) !== true) {
            $id = $resposta['key']['id'] ?? null;
            return ['ok' => true, 'provedor' => 'evolution', 'id' => is_string($id) ? mb_substr($id, 0, 120) : null, 'erro' => null];
        }
        $detalhe = mcp_whatsapp_evolution_erro($resposta);
        if ($status === 400 && $detalhe === 'esse número não tem WhatsApp') {
            return ['ok' => false, 'provedor' => 'evolution', 'id' => null, 'erro' => $detalhe, 'definitivo' => true];
        }
        $erro = match (true) {
            $status === 401 || $status === 403 => 'a Evolution recusou a chave (confira WHATSAPP_EVOLUTION_CHAVE)',
            $status === 404 => 'instância não encontrada na Evolution (confira WHATSAPP_EVOLUTION_INSTANCIA)' . ($detalhe !== null ? ": $detalhe" : ''),
            $status === 0 => 'sem resposta do servidor da Evolution' . ($erroCurl !== '' ? " ($erroCurl)" : ''),
            default => $detalhe ?? "HTTP $status",
        };
        // A chave nunca vai para o registro, nem dentro de uma mensagem de erro.
        $erro = str_replace([$cfg['chave'], $cfg['url']], ['[chave]', '[servidor]'], $erro);
        $cair('evolution', $status, $erro);
        return ['ok' => false, 'provedor' => 'evolution', 'id' => null, 'erro' => mb_substr($erro, 0, 250)];
    }
    if ($modo === 'webhook') {
        $segredo = (string) mcp_cfg('WHATSAPP_WEBHOOK_SEGREDO', '');
        if (strlen($segredo) < 24) {
            mcp_whatsapp_fora('webhook: segredo curto');
            return ['ok' => false, 'provedor' => 'webhook', 'id' => null, 'erro' => 'WHATSAPP_WEBHOOK_SEGREDO ausente ou curto (24 caracteres ou mais)'];
        }
        $corpo = (string) json_encode([
            'evento' => 'whatsapp', 'aviso' => $avisoId, 'telefone' => $numero, 'texto' => (string) ($msg['whatsapp'] ?? ''),
            'modelo' => $msg['modelo'] ?? null, 'enviado_em' => gmdate('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        [$status, $resposta, $erroCurl, $esgotado] = mcp_avisos_post_json((string) mcp_cfg('WHATSAPP_WEBHOOK_URL', ''), $corpo, ['X-CVB-Assinatura: sha256=' . hash_hmac('sha256', $corpo, $segredo)]);
        if ($esgotado) {
            return $incerto('webhook', 'O Make');
        }
        if ($status >= 200 && $status < 300) {
            $id = $resposta['id'] ?? null;
            return ['ok' => true, 'provedor' => 'webhook', 'id' => is_scalar($id) ? mb_substr((string) $id, 0, 120) : null, 'erro' => null];
        }
        $erro = $status > 0 ? "HTTP $status" : ($erroCurl ?: 'sem resposta');
        $cair('webhook', $status, $erro);
        return ['ok' => false, 'provedor' => 'webhook', 'id' => null, 'erro' => $erro];
    }
    return ['ok' => false, 'provedor' => 'manual', 'id' => null, 'erro' => 'WhatsApp no modo manual: envie pela fila do portal', 'definitivo' => true];
}

// ----------------------------------------------------------------------------- envio
/**
 * Manda um aviso da fila. Trava a linha (status enviando) para duas rodadas não mandarem a mesma
 * mensagem. Devolve o status final: enviado, pendente (vai tentar de novo), falhou, cancelado, pausado
 * (volta para a fila sem gastar tentativa) ou ocupado (outro processo pegou, ou ainda não é hora).
 */
function mcp_aviso_enviar(array $aviso, ?int $agora = null): string
{
    $agora ??= time();
    $quando = gmdate('Y-m-d H:i:s', $agora);
    // A trava confere também a hora e o prazo: a espera entre tentativas vale para quem chama direto.
    $stmt = mcp_db()->prepare("UPDATE mcp_avisos SET status = 'enviando', tentativas = tentativas + 1, atualizado_em = ? WHERE id = ? AND status = 'pendente'
        AND agendado_para <= ? AND (expira_em IS NULL OR expira_em > ?)");
    $stmt->execute([$quando, (int) $aviso['id'], $quando, $quando]);
    if ($stmt->rowCount() === 0) {
        return 'ocupado';
    }
    $aviso['tentativas'] = (int) $aviso['tentativas'] + 1;
    $devolver = static function (?string $erro) use ($aviso, $quando): void {
        mcp_db()->prepare("UPDATE mcp_avisos SET status = 'pendente', tentativas = GREATEST(tentativas - 1, 0), erro = COALESCE(?, erro), atualizado_em = ? WHERE id = ? AND status = 'enviando'")
            ->execute([$erro, $quando, (int) $aviso['id']]);
    };
    $dest = mcp_aviso_destinatario($aviso);
    if (!$dest['ok']) {
        if (!empty($dest['pausa'])) {
            $devolver(null);
            return 'pausado';
        }
        mcp_aviso_atualizar((int) $aviso['id'], ['status' => 'cancelado', 'erro' => $dest['motivo']], 'enviando');
        return 'cancelado';
    }
    $msg = mcp_aviso_mensagem($aviso, $dest, $agora);
    if ($msg === null) {
        mcp_aviso_atualizar((int) $aviso['id'], ['status' => 'cancelado', 'erro' => 'não se aplica mais'], 'enviando');
        return 'cancelado';
    }
    if ($aviso['canal'] === 'email') {
        // Sem o mail() de reserva: aviso em quantidade pelo servidor da hospedagem cai no spam e esbarra no
        // limite de envios dela. Com a Resend fora, espera a próxima tentativa.
        $via = mcp_enviar_email((string) $dest['destino'], $msg['assunto'], $msg['html'], $msg['texto'], null,
            ($r = (string) mcp_cfg('EMAIL_REMETENTE_PONTO', '')) !== '' ? $r : null, [], ['sem_reserva' => true, 'tempo' => 15,
                'cabecalhos' => in_array($aviso['tipo'], MCP_AVISOS_COM_DESCADASTRO, true) ? mcp_avisos_cabecalhos_descadastro($aviso) : []]);
        $resultado = match ($via) {
            'sem_resend' => ['ok' => false, 'provedor' => 'resend', 'id' => null, 'erro' => 'falta a chave da Resend (RESEND_API_KEY): os avisos não saem pelo servidor do site', 'definitivo' => true],
            'falhou' => ['ok' => false, 'provedor' => 'resend', 'id' => null, 'erro' => 'o e-mail não saiu (Resend)'],
            'limite' => ['ok' => false, 'provedor' => 'resend', 'id' => null, 'erro' => 'a Resend recusou por limite de envio; tenta de novo depois', 'limite' => true],
            'incerto' => ['ok' => true, 'provedor' => 'resend', 'id' => null, 'erro' => 'a Resend não confirmou a tempo; o e-mail pode ter saído (não vai de novo)'],
            default => ['ok' => true, 'provedor' => $via, 'id' => null, 'erro' => null],
        };
        if ($via === 'sem_resend') {
            // Sem a Resend, o resto do e-mail da rodada nem tenta.
            mcp_email_fora('sem a chave da Resend');
        }
    } else {
        $resultado = mcp_whatsapp_enviar((string) $dest['destino'], $msg, (int) $aviso['id']);
    }
    $campos = ['destino' => mb_substr((string) $dest['destino'], 0, 190), 'nome' => mb_substr((string) $dest['nome'], 0, 160), 'assunto' => mb_substr((string) $msg['assunto'], 0, 200),
        'texto' => $aviso['canal'] === 'email' ? $msg['texto'] : $msg['whatsapp'], 'provedor' => $resultado['provedor']];
    if ($resultado['ok']) {
        // Envio incerto (o tempo acabou depois de o pedido chegar) conta como enviado, com a ressalva no erro.
        mcp_aviso_atualizar((int) $aviso['id'], $campos + ['status' => 'enviado', 'provedor_id' => $resultado['id'], 'erro' => $resultado['erro'] ?? null,
            'enviado_em' => $quando], 'enviando');
        return 'enviado';
    }
    if (!empty($resultado['limite'])) {
        // Limite da Resend: volta para a fila sem gastar tentativa, e o resto do e-mail da rodada espera.
        $devolver($resultado['erro']);
        mcp_email_fora('limite da Resend');
        return 'pausado';
    }
    $final = !empty($resultado['definitivo']) || $aviso['tentativas'] >= MCP_AVISOS_TENTATIVAS;
    mcp_aviso_atualizar((int) $aviso['id'], $campos + [
        'status' => $final ? 'falhou' : 'pendente', 'erro' => mb_substr((string) $resultado['erro'], 0, 250),
        // Espera 15 min, depois 30, antes de tentar de novo (depois do prazo, o aviso vence).
        'agendado_para' => gmdate('Y-m-d H:i:s', $agora + 900 * $aviso['tentativas']),
    ], 'enviando');
    if ($final) {
        mcp_aviso_reserva_email($aviso, $agora);
    }
    return $final ? 'falhou' : 'pendente';
}

/**
 * Lembrete que não saiu pelo WhatsApp de vez (número sem WhatsApp, recusado pela Meta, três falhas): vai
 * por e-mail, se a pessoa tiver e se ainda der tempo. A chave única impede mandar o e-mail duas vezes.
 */
function mcp_aviso_reserva_email(array $aviso, int $agora): ?int
{
    if ($aviso['canal'] !== 'whatsapp' || !isset(MCP_AVISOS_AJUSTES[$aviso['tipo']]) || !str_ends_with((string) $aviso['chave'], '|whatsapp')) {
        return null;
    }
    $dados = json_decode((string) ($aviso['dados'] ?? ''), true) ?: [];
    $email = null;
    if (!empty($aviso['colaborador_id'])) {
        $c = mcp_colaborador_por_id((int) $aviso['colaborador_id']);
        $email = $c && (int) $c['ativo'] ? (mcp_avisos_canais_todos($c)['email'] ?? null) : null;
    } elseif (filter_var($dados['email'] ?? '', FILTER_VALIDATE_EMAIL) && !mcp_avisos_bloqueado('email', (string) $dados['email'])) {
        $email = (string) $dados['email'];
    }
    $expira = $aviso['expira_em'] ?? null;
    if ($email === null || ($expira !== null && (int) strtotime($expira . ' UTC') <= $agora)) {
        return null;
    }
    $chave = substr((string) $aviso['chave'], 0, -8) . 'email';
    $id = mcp_aviso_criar([
        'chave' => $chave, 'tipo' => $aviso['tipo'], 'canal' => 'email', 'colaborador_id' => $aviso['colaborador_id'] ?: null,
        'pessoa' => $aviso['pessoa'], 'nome' => (string) $aviso['nome'], 'destino' => $email, 'referencia' => $aviso['referencia'], 'dados' => $dados ?: null,
        'agendado_para' => gmdate('Y-m-d H:i:s', $agora), 'expira_em' => $expira,
    ], $agora);
    if ($id !== null) {
        return $id;
    }
    // O e-mail deste lembrete já existia e foi cancelado porque ia (ou foi) pelo WhatsApp: volta para a fila.
    $quando = gmdate('Y-m-d H:i:s', $agora);
    $stmt = mcp_db()->prepare("UPDATE mcp_avisos SET status = 'pendente', erro = NULL, tentativas = 0, destino = ?, agendado_para = ?, expira_em = ?, atualizado_em = ?
        WHERE chave = ? AND status = 'cancelado' AND erro IN ('vai pelo WhatsApp', 'já foi pelo WhatsApp')");
    $stmt->execute([$email, $quando, $expira, $quando, $chave]);
    return $stmt->rowCount() > 0 ? (int) (mcp_aviso_por_chave($chave)['id'] ?? 0) ?: null : null;
}

/**
 * Perto do prazo, lembrete que ainda espera o WhatsApp vai por e-mail (quem tem e-mail), e o WhatsApp dele
 * não sai mais: um canal só. Só os que vencem hoje (véspera, aula e a saída no último dia). Devolve quantos.
 */
function mcp_avisos_trocar_para_email(int $agora): int
{
    if (mcp_avisos_hora_local($agora) < MCP_AVISOS_HORA_TROCA) {
        return 0;
    }
    $fim = mcp_ponto_local_para_utc(mcp_ponto_hoje($agora) . ' ' . MCP_AVISOS_JANELA[1] . ':00');
    $stmt = mcp_db()->prepare("SELECT * FROM mcp_avisos WHERE status = 'pendente' AND canal = 'whatsapp' AND tipo IN ('vespera', 'saida', 'aula')
        AND expira_em IS NOT NULL AND expira_em <= ? AND expira_em > ? ORDER BY id");
    $stmt->execute([$fim, gmdate('Y-m-d H:i:s', $agora)]);
    $n = 0;
    foreach ($stmt->fetchAll() as $aviso) {
        // Primeiro tira o WhatsApp da fila (se outra rodada já o pegou, fica com ela); depois nasce o e-mail.
        if (!mcp_aviso_atualizar((int) $aviso['id'], ['status' => 'cancelado', 'erro' => 'o WhatsApp não saiu a tempo: foi por e-mail'], 'pendente')) {
            continue;
        }
        if (mcp_aviso_reserva_email($aviso, $agora) !== null) {
            $n++;
        } else {
            // Sem e-mail (ou já vencido): o WhatsApp volta para a fila; ainda pode sair até as 20h.
            mcp_aviso_atualizar((int) $aviso['id'], ['status' => 'pendente', 'erro' => $aviso['erro']], 'cancelado');
        }
    }
    return $n;
}

/** Cota diária dos e-mails dos avisos (0 = sem cota). */
function mcp_avisos_emails_cota(): int
{
    return max(0, (int) mcp_cfg('AVISOS_EMAILS_POR_DIA', MCP_AVISOS_EMAILS_POR_DIA));
}

/** E-mails dos avisos que já saíram no dia (Brasília) de $agora. */
function mcp_avisos_emails_hoje(?int $agora = null): int
{
    $agora ??= time();
    $dia = mcp_ponto_hoje($agora);
    $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_avisos WHERE status = 'enviado' AND enviado_em >= ? AND enviado_em < ? AND canal = 'email'");
    $stmt->execute([mcp_ponto_local_para_utc("$dia 00:00:00"), mcp_ponto_local_para_utc(mcp_avisos_dia_mais($dia, 1) . ' 00:00:00')]);
    return (int) $stmt->fetchColumn();
}

/** E-mails de lembrete (tudo menos comunicado) que ainda vão sair hoje: o que a cota precisa guardar para eles. */
function mcp_avisos_emails_lembretes_hoje(?int $agora = null): int
{
    $agora ??= time();
    $quando = gmdate('Y-m-d H:i:s', $agora);
    $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_avisos WHERE status = 'pendente' AND canal = 'email' AND tipo <> 'campanha'
        AND agendado_para < ? AND (expira_em IS NULL OR expira_em > ?)");
    $stmt->execute([mcp_ponto_local_para_utc(mcp_ponto_hoje($agora) . ' ' . MCP_AVISOS_JANELA[1] . ':00'), $quando]);
    return (int) $stmt->fetchColumn();
}

/**
 * Quantos e-mails de comunicado ainda cabem hoje (null = sem cota): a cota menos o que já saiu, menos os
 * lembretes do dia que ainda vão sair e a folga. Um comunicado grande não tira o e-mail dos lembretes das 18h.
 */
function mcp_avisos_cota_comunicados(?int $agora = null): ?int
{
    $cota = mcp_avisos_emails_cota();
    if ($cota <= 0) {
        return null;
    }
    return max(0, $cota - mcp_avisos_emails_hoje($agora) - mcp_avisos_emails_lembretes_hoje($agora) - (int) ceil($cota * MCP_AVISOS_EMAILS_FOLGA));
}

/** A Resend recusando nesta rodada (limite ou três falhas seguidas): o resto do e-mail fica para a próxima. */
function mcp_email_fora(?string $motivo = null): ?string
{
    static $fora = null;
    if ($motivo !== null) {
        $fora = $motivo;
    }
    return $fora;
}

/**
 * Manda os avisos que já podem sair (só dentro da janela das 8h às 20h). Devolve [enviados, falhas].
 * Um lote por canal, os lembretes antes dos comunicados: WhatsApp parado não segura os e-mails, e um
 * comunicado grande não passa na frente dos lembretes das 18h (nem gasta a cota de e-mail deles). Depois
 * do WhatsApp, perto do prazo, o que ainda o espera vai por e-mail, e uma segunda passada de e-mail manda
 * já nesta rodada as reservas que nasceram. $campanhaId limita a um comunicado (o "Mandar agora" do
 * portal manda um lote pequeno na hora).
 */
function mcp_avisos_enviar_pendentes(?int $agora = null, int $limite = MCP_AVISOS_LOTE, ?int $campanhaId = null): array
{
    $agora ??= time();
    $inicio = time();
    if (!mcp_avisos_na_janela($agora)) {
        return [0, 0];
    }
    $quando = gmdate('Y-m-d H:i:s', $agora);
    $modo = mcp_whatsapp_modo();
    // Trocou o modo do WhatsApp com mensagens na fila: nada se perde. No manual, o que esperava o envio
    // automático vai para a Fila do WhatsApp; num modo automático, o que estava na fila manual sai sozinho.
    if ($modo === 'manual') {
        mcp_db()->prepare("UPDATE mcp_avisos SET status = 'manual', atualizado_em = ? WHERE status = 'pendente' AND canal = 'whatsapp' AND tipo <> 'teste'")->execute([$quando]);
    } else {
        mcp_db()->prepare("UPDATE mcp_avisos SET status = 'pendente', atualizado_em = ? WHERE status = 'manual' AND canal = 'whatsapp' AND (expira_em IS NULL OR expira_em > ?)")
            ->execute([$quando, $quando]);
    }
    $lugar = PHP_SAPI === 'cli' ? 'cli' : 'web';
    $evolution = $modo === 'evolution';
    $tetos = ['email' => $limite, 'whatsapp' => $evolution ? min($limite, $lugar === 'cli' ? MCP_WHATSAPP_EVOLUTION_POR_RODADA : MCP_WHATSAPP_EVOLUTION_POR_CLIQUE) : $limite];
    // Cota diária dos e-mails: os lembretes usam o que sobrou dela; o comunicado, só o que os lembretes do
    // dia não vão precisar (mcp_avisos_cota_comunicados). O resto do comunicado espera o dia seguinte.
    $tetoComunicados = PHP_INT_MAX;
    if (($cota = mcp_avisos_emails_cota()) > 0) {
        $tetos['email'] = min($tetos['email'], max(0, $cota - mcp_avisos_emails_hoje($agora)));
        $tetoComunicados = (int) mcp_avisos_cota_comunicados($agora);
    }
    $pausaEvolution = max(0, (int) mcp_cfg('WHATSAPP_EVOLUTION_PAUSA_S', MCP_WHATSAPP_EVOLUTION_PAUSA_S));
    $enviados = 0;
    $falhas = 0;
    $usados = ['email' => 0, 'whatsapp' => 0];
    $parar = false;
    $lote = static function (string $canal) use (&$enviados, &$falhas, &$usados, &$parar, &$tetoComunicados, $tetos, $modo, $evolution, $pausaEvolution,
        $quando, $campanhaId, $agora, $inicio, $lugar): void {
        $resta = $tetos[$canal] - $usados[$canal];
        if ($parar || $resta <= 0 || ($canal === 'whatsapp' && ($modo === 'manual' || !mcp_whatsapp_ativo() || mcp_whatsapp_fora() !== null))
            || ($canal === 'email' && mcp_email_fora() !== null)) {
            return;
        }
        $sql = "SELECT * FROM mcp_avisos WHERE status = 'pendente' AND canal = ? AND agendado_para <= ? AND (expira_em IS NULL OR expira_em > ?)";
        $params = [$canal, $quando, $quando];
        if ($campanhaId !== null) {
            $sql .= ' AND campanha_id = ?';
            $params[] = $campanhaId;
        }
        if ($canal === 'email' && $tetoComunicados <= 0) {
            $sql .= " AND tipo <> 'campanha'";
        }
        $stmt = mcp_db()->prepare($sql . ' ORDER BY ' . MCP_AVISOS_PRIORIDADE . ', agendado_para, id LIMIT ' . $resta);
        $stmt->execute($params);
        $n = 0;
        $seguidas = 0;
        foreach ($stmt->fetchAll() as $aviso) {
            // A janela e o tempo da rodada valem para cada envio, não só para o começo (provedor lento às 19h55).
            $passou = time() - $inicio;
            $momento = $agora + $passou;
            if (!mcp_avisos_na_janela($momento) || $passou > MCP_AVISOS_RODADA_S[$lugar]) {
                $parar = true;
                return;
            }
            if ($canal === 'email' && $aviso['tipo'] === 'campanha') {
                if ($tetoComunicados <= 0) {
                    continue;
                }
                $tetoComunicados--;
            }
            if ($canal === 'whatsapp' && $evolution && $n > 0 && $pausaEvolution > 0) {
                sleep($pausaEvolution);
            } elseif ($canal === 'email' && $n > 0 && MCP_AVISOS_PAUSA_MS > 0 && (string) mcp_cfg('RESEND_API_KEY', '') !== '') {
                usleep(MCP_AVISOS_PAUSA_MS * 1000);
            }
            $n++;
            $usados[$canal]++;
            $r = mcp_aviso_enviar($aviso, $momento);
            if ($r === 'enviado') {
                $enviados++;
                $seguidas = 0;
            } elseif ($r === 'falhou' || $r === 'pendente') {
                $falhas++;
                // Três e-mails seguidos sem sair: a Resend está com problema; o resto espera a próxima rodada.
                if ($canal === 'email' && ++$seguidas >= 3) {
                    mcp_email_fora('três falhas seguidas');
                }
            }
            if (($canal === 'email' && mcp_email_fora() !== null) || ($canal === 'whatsapp' && mcp_whatsapp_fora() !== null)) {
                return;
            }
        }
    };
    $lote('email');
    $lote('whatsapp');
    // Perto do prazo (véspera e aula vencem às 20h), o que ainda espera o WhatsApp vai por e-mail: a partir
    // das 19h com o WhatsApp fora do ar ou desligado; na última rodada, seja qual for o motivo.
    $momento = $agora + (time() - $inicio);
    if ($campanhaId === null && $modo !== 'manual' && !$parar
        && (mcp_whatsapp_fora() !== null || !mcp_whatsapp_ativo() || mcp_avisos_hora_local($momento) >= MCP_AVISOS_HORA_ULTIMA)) {
        mcp_avisos_trocar_para_email($momento);
    }
    // Segunda passada de e-mail: as reservas desta rodada (WhatsApp que falhou de vez ou trocado) não esperam a próxima.
    $lote('email');
    return [$enviados, $falhas];
}

/**
 * Faxina da fila: o que venceu sem sair vira "expirado"; o que ficou travado em "enviando" (a rodada
 * caiu no meio) vira "falhou", sem tentar de novo, para não correr o risco de mandar duas vezes.
 */
function mcp_avisos_expirar(?int $agora = null): int
{
    $agora ??= time();
    $quando = gmdate('Y-m-d H:i:s', $agora);
    $stmt = mcp_db()->prepare("UPDATE mcp_avisos SET status = 'expirado', atualizado_em = ? WHERE status IN ('pendente', 'manual') AND expira_em IS NOT NULL AND expira_em <= ?");
    $stmt->execute([$quando, $quando]);
    $n = $stmt->rowCount();
    mcp_db()->prepare("UPDATE mcp_avisos SET status = 'falhou', erro = 'envio interrompido; confira antes de mandar de novo', atualizado_em = ? WHERE status = 'enviando' AND atualizado_em < ?")
        ->execute([$quando, gmdate('Y-m-d H:i:s', $agora - 600)]);
    return $n;
}

/** Apaga o registro de avisos e as opiniões com mais de 1 ano (Política de Privacidade, "Por quanto tempo guardamos"). */
function mcp_avisos_apagar_antigos(?int $agora = null): int
{
    $limite = gmdate('Y-m-d H:i:s', ($agora ?? time()) - MCP_AVISOS_GUARDA_DIAS * 86400);
    $stmt = mcp_db()->prepare('DELETE FROM mcp_avisos WHERE criado_em < ?');
    $stmt->execute([$limite]);
    $opinioes = mcp_db()->prepare('DELETE FROM mcp_opinioes WHERE atualizado_em < ?');
    $opinioes->execute([$limite]);
    // Ao fim da pesquisa (o link da opinião vale 30 dias), a resposta deixa de ficar ligada a quem respondeu.
    mcp_db()->prepare("UPDATE mcp_opinioes o JOIN mcp_campanhas c ON c.id = o.campanha_id SET o.pessoa = CONCAT('x', o.id)
        WHERE o.pessoa NOT LIKE 'x%' AND c.iniciada_em < ?")
        ->execute([gmdate('Y-m-d H:i:s', ($agora ?? time()) - (MCP_AVISOS_LINK_DIAS['opiniao'] + 1) * 86400)]);
    return $stmt->rowCount() + $opinioes->rowCount();
}

// ----------------------------------------------------------------------------- fila manual do WhatsApp
/** Mensagens esperando a secretaria abrir no WhatsApp, com o texto pronto e o link wa.me. */
function mcp_avisos_fila_manual(?int $agora = null, int $limite = 200): array
{
    $agora ??= time();
    $stmt = mcp_db()->prepare("SELECT * FROM mcp_avisos WHERE status = 'manual' AND agendado_para <= ? AND (expira_em IS NULL OR expira_em > ?)
        ORDER BY agendado_para, id LIMIT " . max(1, $limite));
    // A fila mostra desde cedo o que sai no dia (o lembrete das 18h já aparece às 8h).
    $fimDoDia = mcp_ponto_local_para_utc(mcp_ponto_hoje($agora) . ' 23:59:59');
    $stmt->execute([$fimDoDia, gmdate('Y-m-d H:i:s', $agora)]);
    $itens = [];
    foreach ($stmt->fetchAll() as $aviso) {
        $dest = mcp_aviso_destinatario($aviso);
        if (!$dest['ok']) {
            // WhatsApp desligado no portal é pausa: some da fila, mas volta se religar dentro do prazo.
            if (empty($dest['pausa'])) {
                mcp_aviso_atualizar((int) $aviso['id'], ['status' => 'cancelado', 'erro' => $dest['motivo']], 'manual');
            }
            continue;
        }
        $msg = mcp_aviso_mensagem($aviso, $dest, $agora);
        if ($msg === null) {
            mcp_aviso_atualizar((int) $aviso['id'], ['status' => 'cancelado', 'erro' => 'não se aplica mais'], 'manual');
            continue;
        }
        $itens[] = $aviso + ['texto_pronto' => $msg['whatsapp'], 'link_whatsapp' => mcp_whatsapp_link_manual((string) $dest['destino'], $msg['whatsapp']), 'destino_atual' => $dest['destino']];
    }
    return $itens;
}

function mcp_avisos_fila_manual_contar(?int $agora = null): int
{
    $agora ??= time();
    $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_avisos WHERE status = 'manual' AND agendado_para <= ? AND (expira_em IS NULL OR expira_em > ?)");
    $stmt->execute([mcp_ponto_local_para_utc(mcp_ponto_hoje($agora) . ' 23:59:59'), gmdate('Y-m-d H:i:s', $agora)]);
    return (int) $stmt->fetchColumn();
}

/** A secretaria abriu no WhatsApp e mandou (enviado) ou decidiu não mandar (cancelado). */
function mcp_avisos_fila_marcar(int $id, bool $enviado, string $quem, ?int $agora = null): bool
{
    $agora ??= time();
    $aviso = mcp_aviso_por_id($id);
    // Venceu às 20h com a página aberta: quem já tinha mandado ainda pode marcar "Enviei" por 2 horas.
    $vencidoHaPouco = $aviso && $enviado && $aviso['status'] === 'expirado' && $aviso['canal'] === 'whatsapp' && $aviso['expira_em'] !== null
        && (int) strtotime($aviso['expira_em'] . ' UTC') > $agora - 7200;
    if (!$aviso || ($aviso['status'] !== 'manual' && !$vencidoHaPouco)) {
        return false;
    }
    // Trava: se outra pessoa da secretaria marcou este item no meio tempo, não marca de novo.
    $trava = mcp_db()->prepare("UPDATE mcp_avisos SET status = 'enviando', atualizado_em = ? WHERE id = ? AND status = ?");
    $trava->execute([mcp_agora(), $id, $aviso['status']]);
    if ($trava->rowCount() === 0) {
        return false;
    }
    $campos = ['status' => $enviado ? 'enviado' : 'cancelado', 'enviado_por' => $quem, 'provedor' => 'manual'];
    if ($enviado) {
        $dest = mcp_aviso_destinatario($aviso);
        $msg = $dest['ok'] ? mcp_aviso_mensagem($aviso, $dest, $agora) : null;
        $campos += ['enviado_em' => gmdate('Y-m-d H:i:s', $agora), 'texto' => $msg['whatsapp'] ?? null, 'assunto' => isset($msg['assunto']) ? mb_substr($msg['assunto'], 0, 200) : null];
    } else {
        $campos['erro'] = 'a secretaria pulou';
    }
    mcp_aviso_atualizar($id, $campos);
    return true;
}

// ----------------------------------------------------------------------------- lembretes automáticos
/**
 * Véspera: a partir das 8h, prepara os lembretes de amanhã para quem escolheu esse dia da semana.
 * Saem às 18h (a fila manual já mostra de manhã) e valem até as 20h: depois, "amanhã" já estaria errado.
 */
function mcp_avisos_preparar_vespera(?int $agora = null): int
{
    $agora ??= time();
    // Só dentro da janela: preparado depois das 20h, o lembrete "de amanhã" sairia no próprio dia.
    if (!mcp_avisos_na_janela($agora)) {
        return 0;
    }
    $hoje = mcp_ponto_hoje($agora);
    $amanha = mcp_avisos_dia_mais($hoje, 1);
    // Sede fechada amanhã (feriado ou dia sem expediente marcado no portal): não há o que lembrar.
    if (mcp_avisos_dia_fechado($amanha) !== null) {
        return 0;
    }
    $dia = mcp_avisos_dia_chave($amanha);
    // A rotina roda a cada 15 minutos: lê de uma vez o que já foi preparado e só cria o que falta.
    $feitos = mcp_db()->prepare("SELECT chave FROM mcp_avisos WHERE tipo = 'vespera' AND referencia = ?");
    $feitos->execute([$amanha]);
    $jaTem = array_flip($feitos->fetchAll(PDO::FETCH_COLUMN));
    $stmt = mcp_db()->prepare("SELECT * FROM mcp_colaboradores WHERE ativo = 1 AND aviso_dias LIKE ?");
    $stmt->execute(['%' . $dia . '%']);
    $n = 0;
    foreach ($stmt->fetchAll() as $c) {
        if (!in_array($dia, mcp_avisos_dias($c['aviso_dias']), true) || !mcp_avisos_recebe_vespera($c, $amanha)) {
            continue;
        }
        foreach (mcp_avisos_canais($c, 'vespera') as $canal => $destino) {
            $chave = "vespera|{$c['id']}|$amanha|$canal";
            if (isset($jaTem[$chave])) {
                continue;
            }
            $n += mcp_aviso_criar([
                'chave' => $chave, 'tipo' => 'vespera', 'canal' => $canal, 'colaborador_id' => (int) $c['id'], 'pessoa' => 'c' . $c['id'],
                'nome' => (string) $c['nome'], 'destino' => $destino, 'referencia' => $amanha, 'dados' => ['data' => $amanha],
                'agendado_para' => mcp_ponto_local_para_utc("$hoje " . MCP_AVISOS_HORA_VESPERA . ':00'),
                'expira_em' => mcp_ponto_local_para_utc("$hoje " . MCP_AVISOS_JANELA[1] . ':00'),
            ], $agora) !== null ? 1 : 0;
        }
    }
    return $n;
}

/**
 * Saída não registrada: a partir das 9h, ao voluntário que registrou a entrada ontem e não a saída
 * (nem informou o horário), um aviso com o link para informar. Vale por 3 dias.
 */
function mcp_avisos_preparar_saida(?int $agora = null): int
{
    $agora ??= time();
    if (mcp_avisos_hora_local($agora) < MCP_AVISOS_HORA_SAIDA) {
        return 0;
    }
    $hoje = mcp_ponto_hoje($agora);
    // Os últimos 3 dias (e não só ontem): se a rotina ficou parada, o aviso sai no dia seguinte. A chave
    // única impede repetir.
    [$de, $ate] = mcp_ponto_periodo_utc(mcp_avisos_dia_mais($hoje, -3), $hoje);
    $stmt = mcp_db()->prepare('SELECT p.id AS ponto_id, p.entrada, c.* FROM mcp_ponto p JOIN mcp_colaboradores c ON c.id = p.colaborador_id
        WHERE p.voluntario = 1 AND p.saida IS NULL AND p.saida_informada IS NULL AND p.entrada >= ? AND p.entrada < ? AND c.ativo = 1 AND c.aviso_saida = 1');
    $stmt->execute([$de, $ate]);
    $n = 0;
    foreach ($stmt->fetchAll() as $l) {
        // Quem entrou há menos de um turno inteiro pode estar de plantão, ainda na sede: não pergunta nada.
        if (!mcp_avisos_recebe_saida($l) || (int) strtotime($l['entrada'] . ' UTC') > $agora - MCP_PONTO_TURNO_MAXIMO_HORAS * 3600) {
            continue;
        }
        foreach (mcp_avisos_canais($l, 'saida') as $canal => $destino) {
            $n += mcp_aviso_criar([
                'chave' => "saida|{$l['ponto_id']}|$canal", 'tipo' => 'saida', 'canal' => $canal, 'colaborador_id' => (int) $l['id'], 'pessoa' => 'c' . $l['id'],
                'nome' => (string) $l['nome'], 'destino' => $destino, 'referencia' => (string) $l['ponto_id'], 'dados' => ['ponto' => (int) $l['ponto_id']],
                'agendado_para' => gmdate('Y-m-d H:i:s', $agora), 'expira_em' => gmdate('Y-m-d H:i:s', $agora + 3 * 86400),
            ], $agora) !== null ? 1 : 0;
        }
    }
    return $n;
}

/**
 * Aula de amanhã: a partir das 8h, pergunta à escola (aulas_do_dia) quem tem aula amanhã e prepara um
 * lembrete por aluno (e-mail; WhatsApp também, se ligado e o aluno tiver celular). Pergunta uma vez por
 * dia; se a escola não responder, tenta na próxima rodada.
 */
function mcp_avisos_preparar_aulas(?int $agora = null): int
{
    $agora ??= time();
    if (mcp_avisos_hora_local($agora) < MCP_AVISOS_JANELA[0] || mcp_avisos_hora_local($agora) >= MCP_AVISOS_JANELA[1]) {
        return 0;
    }
    $hoje = mcp_ponto_hoje($agora);
    $amanha = mcp_avisos_dia_mais($hoje, 1);
    if (mcp_ajuste('aula_preparada') === $amanha || mcp_avisos_dia_fechado($amanha) !== null) {
        return 0;
    }
    $escola = mcp_escola_aulas_do_dia($amanha);
    if (!$escola['ok']) {
        return 0;
    }
    $n = 0;
    foreach ($escola['alunos'] as $aluno) {
        if (!$aluno['aulas']) {
            continue;
        }
        $email = $aluno['email'];
        $numero = mcp_whatsapp_numero($aluno['celular']);
        $pessoa = 'a' . ($email !== '' ? mcp_avisos_hash('email', $email) : ($numero !== null ? mcp_avisos_hash('whatsapp', $numero) : ''));
        if ($pessoa === 'a') {
            continue;
        }
        // O e-mail vai nos dados para os Resultados saberem se o aluno confirmou presença no dia (mcp_presencas).
        $dados = ['data' => $amanha, 'aulas' => $aluno['aulas'], 'email' => $email, 'whatsapp_hash' => $numero !== null ? mcp_avisos_hash('whatsapp', $numero) : ''];
        $canais = [];
        if ($email !== '' && !mcp_avisos_bloqueado('email', $email)) {
            $canais['email'] = $email;
        }
        // WhatsApp só para quem autorizou na escola (a função aulas_do_dia devolve whatsapp_autorizado) e não
        // disse "não quero mais receber" na página.
        if ($numero !== null && $aluno['whatsapp_autorizado'] && mcp_whatsapp_ativo() && mcp_ajuste_ligado('aula_whatsapp') && !mcp_avisos_bloqueado('whatsapp', $numero)
            && !mcp_avisos_aluno_saiu($dados)) {
            $canais['whatsapp'] = $numero;
        }
        foreach ($canais as $canal => $destino) {
            $n += mcp_aviso_criar([
                'chave' => 'aula|' . mb_substr($aluno['id'], 0, 64) . "|$amanha|$canal", 'tipo' => 'aula', 'canal' => $canal, 'pessoa' => $pessoa,
                'nome' => $aluno['nome'], 'destino' => $destino, 'referencia' => $amanha, 'dados' => $dados,
                'agendado_para' => mcp_ponto_local_para_utc("$hoje " . MCP_AVISOS_HORA_VESPERA . ':00'),
                'expira_em' => mcp_ponto_local_para_utc("$hoje " . MCP_AVISOS_JANELA[1] . ':00'),
            ], $agora) !== null ? 1 : 0;
        }
    }
    mcp_ajuste_gravar('aula_preparada', $amanha, 'rotina');
    return $n;
}

/**
 * Alunos com aula num dia, pela escola (public.aulas_do_dia, em docs/escola/aulas_do_dia.sql).
 * @return array{ok: bool, alunos?: list<array{id: string, nome: string, email: string, celular: string, aulas: list<array>}>, erro?: string}
 */
function mcp_escola_aulas_do_dia(string $dataIso): array
{
    $url = (string) mcp_cfg('ESCOLA_API_AULAS_DIA_URL', '') ?: mcp_escola_url_funcao('aulas_do_dia');
    if ($url === '') {
        return ['ok' => false, 'erro' => 'sem integração com a escola'];
    }
    $chave = (string) mcp_cfg('ESCOLA_API_TOKEN', '');
    [$status, $dados, $erroCurl] = mcp_avisos_post_json($url, (string) json_encode(['dados' => ['data' => $dataIso]]), ['apikey: ' . $chave, 'Authorization: Bearer ' . $chave]);
    if ($status !== 200 || ($dados['ok'] ?? null) !== true || !is_array($dados['alunos'] ?? null)) {
        error_log('[matricula] aulas_do_dia: HTTP ' . $status . ($erroCurl !== '' ? " · $erroCurl" : '') . (isset($dados['code']) && is_scalar($dados['code']) ? ' · ' . $dados['code'] : ''));
        return ['ok' => false, 'erro' => $status > 0 ? "HTTP $status" : 'sem resposta'];
    }
    $alunos = [];
    foreach ($dados['alunos'] as $a) {
        if (!is_array($a) || !is_string($a['aluno_id'] ?? null) || !is_array($a['aulas'] ?? null)) {
            continue;
        }
        $aulas = [];
        foreach ($a['aulas'] as $aula) {
            if (is_array($aula)) {
                $aulas[] = ['curso' => mcp_texto($aula['curso_nome'] ?? '', 160) ?: 'Curso presencial', 'horario' => mcp_texto($aula['horario'] ?? '', 60)];
            }
        }
        $email = mb_strtolower(mcp_texto($a['email'] ?? '', 190));
        $alunos[] = [
            'id' => mb_substr($a['aluno_id'], 0, 64), 'nome' => mcp_texto($a['nome'] ?? '', 160) ?: 'Aluno',
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '', 'celular' => mcp_texto($a['celular'] ?? '', 30), 'aulas' => $aulas,
            // Sem esse campo (a escola ainda não pergunta), ninguém recebe a aula pelo WhatsApp.
            'whatsapp_autorizado' => ($a['whatsapp_autorizado'] ?? false) === true,
        ];
    }
    return ['ok' => true, 'alunos' => $alunos];
}

// ----------------------------------------------------------------------------- WhatsApp: webhook de entrada (api/whatsapp.php)
/** Confere a assinatura X-Hub-Signature-256 que a Meta manda com cada evento. */
function mcp_whatsapp_webhook_assinatura_ok(string $corpo, string $cabecalho): bool
{
    $segredo = (string) mcp_cfg('WHATSAPP_CLOUD_APP_SEGREDO', '');
    return $segredo !== '' && str_starts_with($cabecalho, 'sha256=') && hash_equals(hash_hmac('sha256', $corpo, $segredo), substr($cabecalho, 7));
}

/**
 * Trata o que a Meta mandou: situação das mensagens (entregue, lida, falhou) e respostas. "PARAR"
 * (e parecidas) desliga o WhatsApp daquele número. Devolve quantos eventos foram tratados.
 */
function mcp_whatsapp_webhook_tratar(array $evento, ?int $agora = null): int
{
    $agora ??= time();
    $n = 0;
    foreach ((array) ($evento['entry'] ?? []) as $entrada) {
        foreach ((array) ($entrada['changes'] ?? []) as $mudanca) {
            $valor = is_array($mudanca['value'] ?? null) ? $mudanca['value'] : [];
            // Evento de outro número da mesma conta da Meta: não é deste site.
            $numeroId = (string) ($valor['metadata']['phone_number_id'] ?? '');
            if ($numeroId !== '' && $numeroId !== (string) mcp_cfg('WHATSAPP_CLOUD_NUMERO_ID', '')) {
                continue;
            }
            foreach ((array) ($valor['statuses'] ?? []) as $s) {
                if (!is_array($s) || !is_string($s['id'] ?? null) || !in_array($s['status'] ?? '', ['sent', 'delivered', 'read', 'failed'], true)) {
                    continue;
                }
                $campos = ['entrega' => $s['status']];
                if ($s['status'] === 'failed') {
                    $codigo = (int) ($s['errors'][0]['code'] ?? 0);
                    $campos['status'] = 'falhou';
                    $campos['erro'] = mb_substr(($codigo ? "#$codigo " : '') . (string) ($s['errors'][0]['title'] ?? $s['errors'][0]['message'] ?? 'falhou no WhatsApp'), 0, 250);
                }
                // A situação só avança (lida não volta para entregue quando os eventos chegam fora de ordem).
                $ordem = "FIELD(entrega, 'sent', 'delivered', 'read')";
                $sets = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($campos)));
                $stmt = mcp_db()->prepare("UPDATE mcp_avisos SET $sets, atualizado_em = ? WHERE provedor_id = ? AND (entrega IS NULL OR ? = 'failed' OR $ordem < FIELD(?, 'sent', 'delivered', 'read'))");
                $stmt->execute(array_merge(array_values($campos), [gmdate('Y-m-d H:i:s', $agora), $s['id'], $s['status'], $s['status']]));
                $mudou = $stmt->rowCount();
                $n += $mudou;
                // Lembrete que a Meta não conseguiu entregar: vai por e-mail, se a pessoa tiver e ainda der tempo.
                if ($s['status'] === 'failed' && $mudou > 0) {
                    $falhos = mcp_db()->prepare("SELECT * FROM mcp_avisos WHERE provedor_id = ? AND canal = 'whatsapp'");
                    $falhos->execute([$s['id']]);
                    foreach ($falhos->fetchAll() as $aviso) {
                        mcp_aviso_reserva_email($aviso, $agora);
                    }
                }
            }
            foreach ((array) ($valor['messages'] ?? []) as $m) {
                if (!is_array($m) || !is_string($m['from'] ?? null)) {
                    continue;
                }
                // Mensagem antiga reenviada (a assinatura não tem data): um PARAR de meses atrás não desliga de novo quem voltou.
                if (isset($m['timestamp']) && (int) $m['timestamp'] < $agora - 86400) {
                    continue;
                }
                $texto = (string) ($m['text']['body'] ?? $m['button']['text'] ?? $m['button']['payload'] ?? $m['interactive']['button_reply']['title'] ?? '');
                $normal = trim(mb_strtolower((string) preg_replace('/[^\p{L}\s]+/u', '', $texto)));
                if (in_array($normal, MCP_AVISOS_PALAVRAS_PARAR, true) || str_starts_with($normal, 'parar ')) {
                    mcp_whatsapp_parar(mcp_digitos($m['from']), 'respondeu ' . mb_strtoupper($normal));
                    $n++;
                }
            }
        }
    }
    return $n;
}

/** Desliga o WhatsApp de um número: colaboradores com esse celular e bloqueio para alunos. */
function mcp_whatsapp_parar(string $numero, string $origem): void
{
    if (!preg_match('/^55\d{10,11}$/', $numero)) {
        return;
    }
    // O WhatsApp ainda manda muitos celulares antigos sem o nono dígito (5521 8765-4321): compara pela
    // forma com o 9, a mesma que o cadastro usa para mandar.
    $numero = mcp_whatsapp_numero(substr($numero, 2)) ?? $numero;
    $ids = [];
    foreach (mcp_db()->query('SELECT id, telefone FROM mcp_colaboradores WHERE aviso_whatsapp = 1')->fetchAll() as $c) {
        if (mcp_whatsapp_numero((string) $c['telefone']) === $numero) {
            $ids[] = (int) $c['id'];
        }
    }
    foreach ($ids as $id) {
        mcp_db()->prepare('UPDATE mcp_colaboradores SET aviso_whatsapp = 0, aviso_whatsapp_em = NULL, aviso_whatsapp_por = NULL, aviso_atualizado_em = ? WHERE id = ?')
            ->execute([mcp_agora(), (int) $id]);
        mcp_db()->prepare("UPDATE mcp_avisos SET status = 'cancelado', erro = 'pediu para parar', atualizado_em = ? WHERE colaborador_id = ? AND canal = 'whatsapp' AND status IN ('pendente', 'manual')")
            ->execute([mcp_agora(), (int) $id]);
        mcp_registrar(null, 'aviso_whatsapp_parou', "#$id · $origem");
    }
    mcp_avisos_bloquear_hash('whatsapp', mcp_avisos_hash('whatsapp', $numero), mb_substr($origem, 0, 20));
}

// ----------------------------------------------------------------------------- rotina
/**
 * Rodada da rotina do ponto (api/comparecimentos.php, a cada 15 minutos): faxina, lembretes ligados no
 * portal, comunicados agendados, envio e registro antigo.
 * @return array{preparados: int, enviados: int, falhas: int, expirados: int, apagados: int}
 */
function mcp_avisos_rodar(?int $agora = null): array
{
    $agora ??= time();
    $r = ['preparados' => 0, 'enviados' => 0, 'falhas' => 0, 'expirados' => mcp_avisos_expirar($agora), 'apagados' => 0];
    if (mcp_ajuste_ligado('lembrete_vespera')) {
        $r['preparados'] += mcp_avisos_preparar_vespera($agora);
    }
    if (mcp_ajuste_ligado('lembrete_saida')) {
        $r['preparados'] += mcp_avisos_preparar_saida($agora);
    }
    if (mcp_ajuste_ligado('lembrete_aula')) {
        $r['preparados'] += mcp_avisos_preparar_aulas($agora);
    }
    $r['preparados'] += mcp_campanhas_disparar($agora);
    [$r['enviados'], $r['falhas']] = mcp_avisos_enviar_pendentes($agora);
    mcp_campanhas_concluir($agora);
    $r['apagados'] = mcp_avisos_apagar_antigos($agora);
    return $r;
}
