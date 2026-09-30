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
 *  - o registro dos envios é apagado depois de 1 ano.
 */
declare(strict_types=1);

const MCP_AVISOS_HORA_VESPERA = '18:00';
const MCP_AVISOS_HORA_SAIDA = '09:00';
/** Janela em que os avisos saem (Brasília). Fora dela, esperam o dia seguinte. */
const MCP_AVISOS_JANELA = ['08:00', '20:00'];
/** Mensagens por rodada da rotina (a cada 15 minutos) e pausa entre e-mails (a Resend aceita 2 por segundo). */
const MCP_AVISOS_LOTE = 80;
const MCP_AVISOS_PAUSA_MS = 550;
const MCP_AVISOS_TENTATIVAS = 3;
const MCP_AVISOS_GUARDA_DIAS = 365;
/** Validade dos links pessoais, em dias. */
const MCP_AVISOS_LINK_DIAS = ['lembretes' => 60, 'saida' => 7, 'opiniao' => 30, 'sair' => 365];
/** Um link de clique (api/avisos.php?r=) leva ao destino por até tantos dias depois do aviso. */
const MCP_AVISOS_CLIQUE_DIAS = 60;
const MCP_AVISOS_DIAS = ['dom' => 'Domingo', 'seg' => 'Segunda', 'ter' => 'Terça', 'qua' => 'Quarta', 'qui' => 'Quinta', 'sex' => 'Sexta', 'sab' => 'Sábado'];
const MCP_AVISOS_DIAS_PLURAL = ['dom' => 'domingos', 'seg' => 'segundas', 'ter' => 'terças', 'qua' => 'quartas', 'qui' => 'quintas', 'sex' => 'sextas', 'sab' => 'sábados'];
const MCP_AVISOS_TIPOS = [
    'vespera' => 'Lembrete da véspera',
    'saida' => 'Saída não registrada',
    'aula' => 'Aula de amanhã',
    'campanha' => 'Comunicado',
    'teste' => 'Teste',
    'link' => 'Link das preferências',
];
const MCP_AVISOS_STATUS = [
    'pendente' => 'Na fila', 'enviando' => 'Enviando', 'manual' => 'Fila do WhatsApp', 'enviado' => 'Enviado',
    'falhou' => 'Falhou', 'cancelado' => 'Cancelado', 'expirado' => 'Venceu sem sair',
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

/** Número do WhatsApp (55 + DDD + 9 dígitos) de um celular brasileiro, ou null (fixo e inválido não servem). */
function mcp_whatsapp_numero(?string $telefone): ?string
{
    $d = mcp_telefone((string) $telefone);
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
    mcp_db()->prepare('UPDATE mcp_colaboradores SET aviso_email = ?, aviso_whatsapp = ?, aviso_dias = ?, aviso_saida = ?, aviso_comunicados = ?,
        telefone = ?, aviso_whatsapp_em = ?, aviso_whatsapp_por = ?, aviso_atualizado_em = ?, atualizado_em = ? WHERE id = ?')
        ->execute([
            !empty($p['email']) ? 1 : 0, $whatsapp, implode(',', mcp_avisos_dias($p['dias'] ?? [])) ?: null,
            !empty($p['saida']) ? 1 : 0, !empty($p['comunicados']) ? 1 : 0, $telefone,
            $mudouConsentimento ? ($whatsapp ? mcp_agora() : null) : $c['aviso_whatsapp_em'],
            $mudouConsentimento ? ($whatsapp ? ($pelaPessoa ? 'a própria pessoa' : $quem) : null) : $c['aviso_whatsapp_por'],
            mcp_agora(), mcp_agora(), (int) $c['id'],
        ]);
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
    return substr(hash('sha256', $canal . '|' . mb_strtolower(trim($destino))), 0, 32);
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
    return mcp_avisos_pagina($uso) . '?t=' . mcp_avisos_token($uso, $pessoa, $extra, $agora);
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

/** Link de clique de um aviso: registra o clique e leva ao destino (api/avisos.php?r=). */
function mcp_avisos_link_clique(int $avisoId, string $destino): string
{
    return mcp_site_url() . '/matricula-cursos-presenciais/api/avisos.php?r=' . mcp_avisos_sufixo_clique($avisoId, $destino);
}

/** O que vai depois de "?r=": id.código.assinatura. É o parâmetro do botão dos modelos do WhatsApp. */
function mcp_avisos_sufixo_clique(int $avisoId, string $destino): string
{
    $codigo = (string) array_search($destino, MCP_AVISOS_DESTINOS, true);
    return $avisoId . '.' . $codigo . '.' . substr(mcp_avisos_assinar("r|$avisoId|$codigo"), 0, 16);
}

/**
 * Registra o clique e devolve a URL de destino (com um link pessoal novo, quando é o caso), ou null se
 * o código não vale. O clique conta uma vez por aviso em clicado_em; cliques soma todos.
 */
function mcp_avisos_clique(string $r, ?int $agora = null): ?string
{
    $agora ??= time();
    if (!preg_match('/^(\d{1,10})\.([a-z])\.([a-f0-9]{16})$/', $r, $m) || !isset(MCP_AVISOS_DESTINOS[$m[2]])
        || !hash_equals(substr(mcp_avisos_assinar("r|{$m[1]}|{$m[2]}"), 0, 16), $m[3])) {
        return null;
    }
    $aviso = mcp_aviso_por_id((int) $m[1]);
    if (!$aviso) {
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

/** Bloqueia um canal pelo hash (a página "sair" só conhece o hash, nunca o endereço). */
function mcp_avisos_bloquear_hash(string $canal, string $hash, string $origem): void
{
    if (!preg_match('/^[a-f0-9]{32}$/', $hash)) {
        return;
    }
    mcp_db()->prepare('INSERT IGNORE INTO mcp_avisos_bloqueios (canal, destino_hash, origem, criado_em) VALUES (?, ?, ?, ?)')
        ->execute([$canal, $hash, $origem, mcp_agora()]);
    // O que já estava na fila para esse destino não sai mais.
    mcp_db()->prepare("UPDATE mcp_avisos SET status = 'cancelado', erro = 'pediu para não receber', atualizado_em = ?
        WHERE status IN ('pendente', 'manual') AND canal = ? AND pessoa IS NOT NULL AND colaborador_id IS NULL AND SUBSTRING(SHA2(CONCAT(canal, '|', LOWER(destino)), 256), 1, 32) = ?")
        ->execute([mcp_agora(), $canal, $hash]);
}

// ----------------------------------------------------------------------------- registro dos avisos
function mcp_aviso_por_id(int $id): ?array
{
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_avisos WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
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
        return ['ok' => true, 'nome' => (string) $c['nome'], 'destino' => $destino, 'colaborador' => $c];
    }
    if ($aviso['canal'] === 'whatsapp' && !mcp_whatsapp_ativo()) {
        return ['ok' => false, 'motivo' => 'WhatsApp desligado no portal'];
    }
    if (mcp_avisos_bloqueado((string) $aviso['canal'], (string) $aviso['destino'])) {
        return ['ok' => false, 'motivo' => 'pediu para não receber'];
    }
    return ['ok' => true, 'nome' => (string) $aviso['nome'], 'destino' => (string) $aviso['destino'], 'colaborador' => null];
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

function mcp_aviso_atualizar(int $id, array $campos): void
{
    foreach (array_keys($campos) as $coluna) {
        if (!preg_match('/^[a-z_]+$/', (string) $coluna)) {
            throw new InvalidArgumentException("coluna inválida: $coluna");
        }
    }
    $campos['atualizado_em'] = mcp_agora();
    $sets = implode(', ', array_map(static fn($c) => "$c = :$c", array_keys($campos)));
    $campos['id'] = $id;
    mcp_db()->prepare("UPDATE mcp_avisos SET $sets WHERE id = :id")->execute($campos);
}

// ----------------------------------------------------------------------------- WhatsApp
/** cloud (API oficial da Meta), webhook (Make ou similar) ou manual (fila do portal). */
function mcp_whatsapp_modo(): string
{
    if ((string) mcp_cfg('WHATSAPP_CLOUD_TOKEN', '') !== '' && (string) mcp_cfg('WHATSAPP_CLOUD_NUMERO_ID', '') !== '') {
        return 'cloud';
    }
    if ((string) mcp_cfg('WHATSAPP_WEBHOOK_URL', '') !== '') {
        return 'webhook';
    }
    return 'manual';
}

/** A secretaria ligou o WhatsApp no portal (em qualquer modo). */
function mcp_whatsapp_ativo(): bool
{
    return mcp_ajuste_ligado('whatsapp_ativo');
}

function mcp_whatsapp_modo_nome(string $modo): string
{
    return ['cloud' => 'automático, pela API oficial do WhatsApp', 'webhook' => 'automático, pelo cenário do Make', 'manual' => 'manual, pela fila do portal'][$modo] ?? $modo;
}

/** Link que abre o WhatsApp com a mensagem pronta (fila manual). */
function mcp_whatsapp_link_manual(string $numero, string $texto): string
{
    return 'https://wa.me/' . mcp_digitos($numero) . '?text=' . rawurlencode($texto);
}

/** POST JSON com curl. HTTPS sempre; HTTP só para teste local em 127.0.0.1. Devolve [status, corpo decodificado, erro]. */
function mcp_avisos_post_json(string $url, string $corpo, array $cabecalhos): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $corpo,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $cabecalhos),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true) ? CURLPROTO_HTTP : 0),
    ]);
    $resposta = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erro = curl_error($ch);
    curl_close($ch);
    $dados = is_string($resposta) ? json_decode($resposta, true) : null;
    return [$status, is_array($dados) ? $dados : [], $erro];
}

/**
 * Manda uma mensagem de WhatsApp pelo modo configurado. $msg: whatsapp (texto livre) e modelo
 * (nome, parametros, botao) para a API oficial.
 * @return array{ok: bool, provedor: string, id: ?string, erro: ?string}
 */
function mcp_whatsapp_enviar(string $numero, array $msg, int $avisoId): array
{
    $modo = mcp_whatsapp_modo();
    if ($modo === 'cloud') {
        $modelo = $msg['modelo'] ?? null;
        if (!is_array($modelo) || empty($modelo['nome'])) {
            return ['ok' => false, 'provedor' => 'cloud', 'id' => null, 'erro' => 'sem modelo aprovado para este aviso'];
        }
        $componentes = [];
        if (!empty($modelo['parametros'])) {
            $componentes[] = ['type' => 'body', 'parameters' => array_map(static fn($p): array => ['type' => 'text', 'text' => mb_substr((string) $p, 0, 900)], array_values($modelo['parametros']))];
        }
        if (($modelo['botao'] ?? '') !== '') {
            $componentes[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => (string) $modelo['botao']]]];
        }
        $corpo = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $numero, 'type' => 'template',
            'template' => ['name' => (string) $modelo['nome'], 'language' => ['code' => (string) mcp_cfg('WHATSAPP_CLOUD_IDIOMA', 'pt_BR')], 'components' => $componentes]];
        $url = rtrim((string) mcp_cfg('WHATSAPP_CLOUD_BASE', 'https://graph.facebook.com'), '/') . '/' . mcp_cfg('WHATSAPP_CLOUD_VERSAO', 'v23.0') . '/'
            . rawurlencode((string) mcp_cfg('WHATSAPP_CLOUD_NUMERO_ID', '')) . '/messages';
        [$status, $resposta, $erroCurl] = mcp_avisos_post_json($url, (string) json_encode($corpo, JSON_UNESCAPED_UNICODE), ['Authorization: Bearer ' . mcp_cfg('WHATSAPP_CLOUD_TOKEN', '')]);
        $id = $resposta['messages'][0]['id'] ?? null;
        if ($status >= 200 && $status < 300 && is_string($id)) {
            return ['ok' => true, 'provedor' => 'cloud', 'id' => mb_substr($id, 0, 120), 'erro' => null];
        }
        $erro = is_string($resposta['error']['message'] ?? null) ? $resposta['error']['message'] : ($status > 0 ? "HTTP $status" : ($erroCurl ?: 'sem resposta'));
        return ['ok' => false, 'provedor' => 'cloud', 'id' => null, 'erro' => mb_substr($erro, 0, 250)];
    }
    if ($modo === 'webhook') {
        $segredo = (string) mcp_cfg('WHATSAPP_WEBHOOK_SEGREDO', '');
        if (strlen($segredo) < 24) {
            return ['ok' => false, 'provedor' => 'webhook', 'id' => null, 'erro' => 'WHATSAPP_WEBHOOK_SEGREDO ausente ou curto (24 caracteres ou mais)'];
        }
        $corpo = (string) json_encode([
            'evento' => 'whatsapp', 'aviso' => $avisoId, 'telefone' => $numero, 'texto' => (string) ($msg['whatsapp'] ?? ''),
            'modelo' => $msg['modelo'] ?? null, 'enviado_em' => gmdate('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        [$status, $resposta, $erroCurl] = mcp_avisos_post_json((string) mcp_cfg('WHATSAPP_WEBHOOK_URL', ''), $corpo, ['X-CVB-Assinatura: sha256=' . hash_hmac('sha256', $corpo, $segredo)]);
        if ($status >= 200 && $status < 300) {
            $id = $resposta['id'] ?? null;
            return ['ok' => true, 'provedor' => 'webhook', 'id' => is_scalar($id) ? mb_substr((string) $id, 0, 120) : null, 'erro' => null];
        }
        return ['ok' => false, 'provedor' => 'webhook', 'id' => null, 'erro' => $status > 0 ? "HTTP $status" : ($erroCurl ?: 'sem resposta')];
    }
    return ['ok' => false, 'provedor' => 'manual', 'id' => null, 'erro' => 'WhatsApp no modo manual: envie pela fila do portal'];
}

// ----------------------------------------------------------------------------- envio
/**
 * Manda um aviso da fila. Trava a linha (status enviando) para duas rodadas não mandarem a mesma
 * mensagem. Devolve o status final: enviado, pendente (vai tentar de novo), falhou ou cancelado.
 */
function mcp_aviso_enviar(array $aviso, ?int $agora = null): string
{
    $agora ??= time();
    $stmt = mcp_db()->prepare("UPDATE mcp_avisos SET status = 'enviando', tentativas = tentativas + 1, atualizado_em = ? WHERE id = ? AND status = 'pendente'");
    $stmt->execute([gmdate('Y-m-d H:i:s', $agora), (int) $aviso['id']]);
    if ($stmt->rowCount() === 0) {
        return 'ocupado';
    }
    $aviso['tentativas'] = (int) $aviso['tentativas'] + 1;
    $dest = mcp_aviso_destinatario($aviso);
    if (!$dest['ok']) {
        mcp_aviso_atualizar((int) $aviso['id'], ['status' => 'cancelado', 'erro' => $dest['motivo']]);
        return 'cancelado';
    }
    $msg = mcp_aviso_mensagem($aviso, $dest, $agora);
    if ($msg === null) {
        mcp_aviso_atualizar((int) $aviso['id'], ['status' => 'cancelado', 'erro' => 'não se aplica mais']);
        return 'cancelado';
    }
    if ($aviso['canal'] === 'email') {
        $via = mcp_enviar_email((string) $dest['destino'], $msg['assunto'], $msg['html'], $msg['texto'], null,
            ($r = (string) mcp_cfg('EMAIL_REMETENTE_PONTO', '')) !== '' ? $r : null);
        $resultado = $via === 'falhou' ? ['ok' => false, 'provedor' => 'email', 'id' => null, 'erro' => 'o e-mail não saiu (Resend e mail())']
            : ['ok' => true, 'provedor' => $via, 'id' => null, 'erro' => null];
    } else {
        $resultado = mcp_whatsapp_enviar((string) $dest['destino'], $msg, (int) $aviso['id']);
    }
    $campos = ['destino' => mb_substr((string) $dest['destino'], 0, 190), 'nome' => mb_substr((string) $dest['nome'], 0, 160), 'assunto' => mb_substr((string) $msg['assunto'], 0, 200),
        'texto' => $aviso['canal'] === 'email' ? $msg['texto'] : $msg['whatsapp'], 'provedor' => $resultado['provedor']];
    if ($resultado['ok']) {
        mcp_aviso_atualizar((int) $aviso['id'], $campos + ['status' => 'enviado', 'provedor_id' => $resultado['id'], 'erro' => null, 'enviado_em' => gmdate('Y-m-d H:i:s', $agora)]);
        return 'enviado';
    }
    $final = $aviso['tentativas'] >= MCP_AVISOS_TENTATIVAS;
    mcp_aviso_atualizar((int) $aviso['id'], $campos + [
        'status' => $final ? 'falhou' : 'pendente', 'erro' => mb_substr((string) $resultado['erro'], 0, 250),
        // Espera 15 min, depois 30, antes de tentar de novo.
        'agendado_para' => gmdate('Y-m-d H:i:s', $agora + 900 * $aviso['tentativas']),
    ]);
    return $final ? 'falhou' : 'pendente';
}

/**
 * Manda os avisos que já podem sair (só dentro da janela das 8h às 20h). Devolve [enviados, falhas].
 * $campanhaId limita a um comunicado (o "Enviar agora" do portal manda um lote pequeno na hora).
 */
function mcp_avisos_enviar_pendentes(?int $agora = null, int $limite = MCP_AVISOS_LOTE, ?int $campanhaId = null): array
{
    $agora ??= time();
    if (!mcp_avisos_na_janela($agora)) {
        return [0, 0];
    }
    $sql = "SELECT * FROM mcp_avisos WHERE status = 'pendente' AND agendado_para <= ? AND (expira_em IS NULL OR expira_em > ?)";
    $params = [gmdate('Y-m-d H:i:s', $agora), gmdate('Y-m-d H:i:s', $agora)];
    if ($campanhaId !== null) {
        $sql .= ' AND campanha_id = ?';
        $params[] = $campanhaId;
    }
    $stmt = mcp_db()->prepare($sql . ' ORDER BY agendado_para, id LIMIT ' . max(1, $limite));
    $stmt->execute($params);
    $enviados = 0;
    $falhas = 0;
    $emails = 0;
    foreach ($stmt->fetchAll() as $aviso) {
        if ($aviso['canal'] === 'email' && $emails++ > 0 && MCP_AVISOS_PAUSA_MS > 0 && (string) mcp_cfg('RESEND_API_KEY', '') !== '') {
            usleep(MCP_AVISOS_PAUSA_MS * 1000);
        }
        $r = mcp_aviso_enviar($aviso, $agora);
        if ($r === 'enviado') {
            $enviados++;
        } elseif ($r === 'falhou' || $r === 'pendente') {
            $falhas++;
        }
    }
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

/** Apaga o registro de avisos com mais de 1 ano. */
function mcp_avisos_apagar_antigos(?int $agora = null): int
{
    $stmt = mcp_db()->prepare('DELETE FROM mcp_avisos WHERE criado_em < ?');
    $stmt->execute([gmdate('Y-m-d H:i:s', ($agora ?? time()) - MCP_AVISOS_GUARDA_DIAS * 86400)]);
    return $stmt->rowCount();
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
            mcp_aviso_atualizar((int) $aviso['id'], ['status' => 'cancelado', 'erro' => $dest['motivo']]);
            continue;
        }
        $msg = mcp_aviso_mensagem($aviso, $dest, $agora);
        if ($msg === null) {
            mcp_aviso_atualizar((int) $aviso['id'], ['status' => 'cancelado', 'erro' => 'não se aplica mais']);
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
    $aviso = mcp_aviso_por_id($id);
    if (!$aviso || $aviso['status'] !== 'manual') {
        return false;
    }
    $campos = ['status' => $enviado ? 'enviado' : 'cancelado', 'enviado_por' => $quem, 'provedor' => 'manual'];
    if ($enviado) {
        $dest = mcp_aviso_destinatario($aviso);
        $msg = $dest['ok'] ? mcp_aviso_mensagem($aviso, $dest, $agora ?? time()) : null;
        $campos += ['enviado_em' => gmdate('Y-m-d H:i:s', $agora ?? time()), 'texto' => $msg['whatsapp'] ?? null, 'assunto' => isset($msg['assunto']) ? mb_substr($msg['assunto'], 0, 200) : null];
    } else {
        $campos['erro'] = 'a secretaria pulou';
    }
    mcp_aviso_atualizar($id, $campos);
    return true;
}

// ----------------------------------------------------------------------------- lembretes automáticos
/**
 * Véspera: a partir das 8h, prepara os lembretes de amanhã para quem escolheu esse dia da semana.
 * Saem às 18h (a fila manual já mostra de manhã). Valem até as 10h do dia seguinte.
 */
function mcp_avisos_preparar_vespera(?int $agora = null): int
{
    $agora ??= time();
    if (mcp_avisos_hora_local($agora) < MCP_AVISOS_JANELA[0]) {
        return 0;
    }
    $hoje = mcp_ponto_hoje($agora);
    $amanha = mcp_avisos_dia_mais($hoje, 1);
    $dia = mcp_avisos_dia_chave($amanha);
    $stmt = mcp_db()->prepare("SELECT * FROM mcp_colaboradores WHERE ativo = 1 AND aviso_dias LIKE ?");
    $stmt->execute(['%' . $dia . '%']);
    $n = 0;
    foreach ($stmt->fetchAll() as $c) {
        if (!in_array($dia, mcp_avisos_dias($c['aviso_dias']), true)) {
            continue;
        }
        foreach (mcp_avisos_canais($c, 'vespera') as $canal => $destino) {
            $n += mcp_aviso_criar([
                'chave' => "vespera|{$c['id']}|$amanha|$canal", 'tipo' => 'vespera', 'canal' => $canal, 'colaborador_id' => (int) $c['id'], 'pessoa' => 'c' . $c['id'],
                'nome' => (string) $c['nome'], 'destino' => $destino, 'referencia' => $amanha, 'dados' => ['data' => $amanha],
                'agendado_para' => mcp_ponto_local_para_utc("$hoje " . MCP_AVISOS_HORA_VESPERA . ':00'),
                'expira_em' => mcp_ponto_local_para_utc("$amanha 10:00:00"),
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
    [$de, $ate] = mcp_ponto_periodo_utc(mcp_avisos_dia_mais($hoje, -1), $hoje);
    $stmt = mcp_db()->prepare('SELECT p.id AS ponto_id, p.entrada, c.* FROM mcp_ponto p JOIN mcp_colaboradores c ON c.id = p.colaborador_id
        WHERE p.voluntario = 1 AND p.saida IS NULL AND p.saida_informada IS NULL AND p.entrada >= ? AND p.entrada < ? AND c.ativo = 1 AND c.aviso_saida = 1');
    $stmt->execute([$de, $ate]);
    $n = 0;
    foreach ($stmt->fetchAll() as $l) {
        if (!mcp_avisos_recebe_saida($l)) {
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
    if (mcp_ajuste('aula_preparada') === $amanha) {
        return 0;
    }
    $escola = mcp_escola_aulas_do_dia($amanha);
    if (!$escola['ok']) {
        return 0;
    }
    $n = 0;
    foreach ($escola['alunos'] as $aluno) {
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
        if ($numero !== null && mcp_whatsapp_ativo() && mcp_ajuste_ligado('aula_whatsapp') && !mcp_avisos_bloqueado('whatsapp', $numero)) {
            $canais['whatsapp'] = $numero;
        }
        foreach ($canais as $canal => $destino) {
            $n += mcp_aviso_criar([
                'chave' => 'aula|' . mb_substr($aluno['id'], 0, 64) . "|$amanha|$canal", 'tipo' => 'aula', 'canal' => $canal, 'pessoa' => $pessoa,
                'nome' => $aluno['nome'], 'destino' => $destino, 'referencia' => $amanha, 'dados' => $dados,
                'agendado_para' => mcp_ponto_local_para_utc("$hoje " . MCP_AVISOS_HORA_VESPERA . ':00'),
                'expira_em' => mcp_ponto_local_para_utc("$amanha 12:00:00"),
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
            foreach ((array) ($valor['statuses'] ?? []) as $s) {
                if (!is_array($s) || !is_string($s['id'] ?? null) || !in_array($s['status'] ?? '', ['sent', 'delivered', 'read', 'failed'], true)) {
                    continue;
                }
                $campos = ['entrega' => $s['status']];
                if ($s['status'] === 'failed') {
                    $campos['status'] = 'falhou';
                    $campos['erro'] = mb_substr((string) ($s['errors'][0]['title'] ?? $s['errors'][0]['message'] ?? 'falhou no WhatsApp'), 0, 250);
                }
                // A situação só avança (lida não volta para entregue quando os eventos chegam fora de ordem).
                $ordem = "FIELD(entrega, 'sent', 'delivered', 'read')";
                $sets = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($campos)));
                $stmt = mcp_db()->prepare("UPDATE mcp_avisos SET $sets, atualizado_em = ? WHERE provedor_id = ? AND (entrega IS NULL OR ? = 'failed' OR $ordem < FIELD(?, 'sent', 'delivered', 'read'))");
                $stmt->execute(array_merge(array_values($campos), [gmdate('Y-m-d H:i:s', $agora), $s['id'], $s['status'], $s['status']]));
                $n += $stmt->rowCount();
            }
            foreach ((array) ($valor['messages'] ?? []) as $m) {
                if (!is_array($m) || !is_string($m['from'] ?? null)) {
                    continue;
                }
                $texto = (string) ($m['text']['body'] ?? $m['button']['text'] ?? $m['button']['payload'] ?? $m['interactive']['button_reply']['title'] ?? '');
                $normal = trim(mb_strtolower((string) preg_replace('/[^\p{L}\s]+/u', '', $texto)));
                if (in_array($normal, MCP_AVISOS_PALAVRAS_PARAR, true)) {
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
    $local = substr($numero, 2);
    $stmt = mcp_db()->prepare('SELECT id FROM mcp_colaboradores WHERE telefone = ? AND aviso_whatsapp = 1');
    $stmt->execute([$local]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
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
