<?php
/**
 * Recuperação por e-mail (30/10/2026, pedido do responsável: "sincroniza o e-mail dessas pessoas também"): o MESMO
 * público da recuperação por WhatsApp (lib/recuperacao_whatsapp.php, que carrega este arquivo) recebe também três
 * e-mails, pela Resend, ao lado das mensagens e independente delas.
 *
 *  - Quem entra: a pessoa de mcp_rw_agrupar com no_fluxo_email (não pagou nada em nenhum sistema, tem tentativa aberta
 *    nos últimos MCP_RW_JANELA_DIAS dias, tem e-mail válido e não bloqueou o e-mail). Sem celular válido, recebe só o
 *    e-mail; sem e-mail, só o WhatsApp; com os dois, os dois. O e-mail NÃO espera a Meta aprovar os modelos.
 *  - Mesma régua: etapa 1 com 1 h da âncora, 2 com 24 h e 3 com 72 h (MCP_RW_ETAPAS), com os mesmos intervalos mínimos
 *    (MCP_RW_INTERVALOS) desde o e-mail anterior, só das 8h às 20h de Brasília, conferindo de novo antes de cada envio
 *    (pagou em qualquer sistema? bloqueou?), e nenhuma etapa se repete (UNIQUE(pessoa_chave, etapa) em
 *    mcp_recuperacao_email). A etapa seguinte só sai depois da anterior ENVIADA (ou dada como cumprida pelo PIX).
 *  - O grupo de controle é o mesmo do WhatsApp (mcp_rw_controle olha as duas tabelas): não recebe e-mail também, e ganha
 *    só a linha etapa 0 'controle' quando o 1º e-mail sairia (se o WhatsApp ainda não gravou a dele).
 *  - Quem respondeu "Não tenho interesse" (ou SAIR, PARAR, STOP) no WhatsApp, ou se descadastrou de um destes e-mails, não
 *    recebe mais nada nos dois canais.
 *  - Lembrete do PIX (lib/recuperacao.php, evento 'email_pix_lembrete'): se uma inscrição do site da pessoa já recebeu
 *    esse lembrete, a etapa 1 é dada como cumprida (linha 'pulado_pix', com a hora daquele lembrete, que conta para o
 *    intervalo até a 2ª). Se o lembrete ainda pode sair (PIX pendente gerado há menos de MCP_PIX_LEMBRETE_HORAS[1] horas),
 *    a etapa 1 espera por ele: sem isso, quem gera o PIX recebe o 1º e-mail com 1 h e o lembrete do PIX com 2 h.
 *  - Cota: a conta da Resend é a gratuita (MCP_RW_RESEND_COTA_DIA por dia), dividida com os recibos, o PIX e os avisos.
 *    No máximo MCP_RW_EMAIL_POR_RODADA por rodada e MCP_RW_EMAIL_POR_DIA em 24 h corridas; e nada sai se o resto do site
 *    já mandou MCP_RW_EMAIL_RESERVA_SITE e-mails nas últimas 24 h (a sobra é dos recibos de quem pagou).
 *  - Descadastro: todo e-mail tem o link "Não quero mais receber" e o cabeçalho List-Unsubscribe de um clique (RFC 8058),
 *    para api/avisos.php?rw=<id>.<assinatura> (assinado com o segredo dos avisos, como o ?u= de lib/avisos.php). Ele
 *    grava mcp_avisos_bloqueios nos canais email e whatsapp com a origem 'recuperacao', o que tira a pessoa dos dois.
 *
 * Configuração: RESEND_API_KEY (a mesma dos outros e-mails do site). Sem ela nada sai (nem pelo mail() da hospedagem) e
 * o painel diz. A rodada é mcp_rw_email_rodar, chamada pelo api/lembretes.php logo depois da do WhatsApp; uma falha aqui
 * nunca derruba o WhatsApp nem a rotina (e o contrário também).
 *
 * Regras puras (conferidas no teste): mcp_rw_email_proxima, mcp_rw_email_montar e mcp_rw_email_cabecalhos.
 */
declare(strict_types=1);

// Teto da recuperação por e-mail: a conta gratuita da Resend tem 100 por dia para o site inteiro (recibos, PIX, avisos
// das aulas e do ponto). 10 por rodada espalha o envio ao longo do dia; 30 em 24 h corridas deixa ~70 para o resto.
const MCP_RW_EMAIL_POR_RODADA = 10;
const MCP_RW_EMAIL_POR_DIA = 30;
// A cota diária da conta gratuita da Resend (só para o painel mostrar quanto já foi).
const MCP_RW_RESEND_COTA_DIA = 100;
// Se o resto do site já mandou isto em 24 h (o que fica registrado: eventos email_* e os avisos por e-mail), a recuperação
// espera: 60 + os 30 dela ainda deixam folga para os recibos de quem pagar. O recibo vale mais que o lembrete.
const MCP_RW_EMAIL_RESERVA_SITE = 60;
// Segundos de espera pela Resend em cada envio (a rodada tem outras coisas a fazer na mesma hora).
const MCP_RW_EMAIL_TEMPO_S = 20;
// A âncora de cada e-mail no grupo do painel (o "modelo" gravado na linha é a campanha do botão).
const MCP_RW_EMAIL_NOMES = ['rec1' => '1º e-mail', 'rec2' => '2º e-mail', 'rec3' => '3º e-mail', 'rec3cupom' => '3º e-mail, com cupom'];

/**
 * A configuração do e-mail, ou o que falta. RESEND_API_URL (só nos testes) aponta para um servidor falso local.
 * @return array{ok: bool, falta: string[]}
 */
function mcp_rw_email_config(): array
{
    $ok = trim((string) mcp_cfg('RESEND_API_KEY', '')) !== '';
    return ['ok' => $ok, 'falta' => $ok ? [] : ['RESEND_API_KEY']];
}

// ----------------------------------------------------------------------------- banco: as linhas
/** Todas as linhas de mcp_recuperacao_email (sem e-mail nem CPF: só os HMAC e o hash dos bloqueios). */
function mcp_rw_email_linhas(): array
{
    return mcp_db()->query('SELECT id, pessoa_chave, origem, etapa, modelo, assunto, curso_slug, curso_nome, email_hash, whatsapp_hash, chaves, status, provedor_id, erro,
        descadastro_em, criado_em, atualizado_em FROM mcp_recuperacao_email ORDER BY id')->fetchAll();
}

/**
 * O índice das linhas do e-mail (mcp_rw_indice), ou vazio se a tabela ainda não existe (logo depois de publicar, com a
 * migração adiada): quem chama é a rodada do WhatsApp, que nunca pode cair por causa do e-mail.
 */
function mcp_rw_email_indice_seguro(): array
{
    try {
        return mcp_rw_indice(mcp_rw_email_linhas());
    } catch (PDOException $e) {
        error_log('[matricula] recuperação por e-mail: linhas indisponíveis (' . $e->getCode() . '); o WhatsApp segue sem elas');
        return [];
    }
}

/** A pessoa se descadastrou de algum e-mail da recuperação (por qualquer chave dela)? Vale para os dois canais. */
function mcp_rw_email_descadastrou(array $pessoa, array $indice): bool
{
    foreach (mcp_rw_chaves($pessoa) as $k) {
        foreach ($indice[$k] ?? [] as $l) {
            if (!empty($l['descadastro_em'])) {
                return true;
            }
        }
    }
    return false;
}

// ----------------------------------------------------------------------------- régua e mensagem (puro)
/**
 * O próximo e-mail de uma pessoa e quando ele fica devido (sem o horário), ou null. A régua é a do WhatsApp
 * (mcp_rw_proxima): a linha 'pulado_pix' conta como a etapa 1 enviada (na hora do lembrete do PIX), 'falhou' para a
 * sequência, e a etapa 0 (controle) não entra. $minhas: etapa => linha com criado_ts (mcp_rw_da_pessoa).
 * @return array{etapa: int, devida_em: int}|null
 */
function mcp_rw_email_proxima(int $ancora, array $minhas): ?array
{
    $enviadas = [];
    foreach ($minhas as $etapa => $l) {
        if ((int) $etapa < 1) {
            continue;
        }
        $enviadas[(int) $etapa] = ['status' => in_array($l['status'], ['enviado', 'pulado_pix'], true) ? 'enviado' : (string) $l['status'], 'entrega' => null,
            'criado_ts' => (int) ($l['criado_ts'] ?? 0)];
    }
    return mcp_rw_proxima($ancora, $enviadas);
}

/**
 * Um e-mail da recuperação, pronto: ['modelo' (a campanha do botão: rec1, rec2, rec3 ou rec3cupom), 'assunto', 'html',
 * 'texto', 'link', 'preheader']. HTML na moldura dos e-mails do site, texto puro junto, um botão só, e nada de preço
 * nem de escassez: o valor a pessoa vê no checkout. Os botões levam os MESMOS caminhos do WhatsApp (mcp_rw_caminho_botao
 * e o do cupom), com utm_source=email. Sem curso (só a conta na escola), fala de "nossos cursos" e leva à página de
 * matrícula. A 3ª de Primeiros Socorros Básico com $oferta (mcp_rw_oferta_cupom) leva o cupom aplicado e a data da
 * turma. $descadastro: o endereço do "Não quero mais receber" (mcp_rw_email_descadastro_url). Puro.
 */
function mcp_rw_email_montar(int $etapa, array $pessoa, ?array $oferta, string $descadastro): array
{
    $nome = trim((string) ($pessoa['primeiro_nome'] ?? ''));
    $slug = (string) ($pessoa['curso_slug'] ?? '');
    $cursoNome = trim((string) ($pessoa['curso_nome'] ?? ''));
    $generico = $cursoNome === '' || $cursoNome === MCP_RW_CURSO_GENERICO;
    $curso = $generico ? MCP_RW_CURSO_GENERICO : $cursoNome;
    $cupom = $etapa === 3 && $oferta !== null && $slug === MCP_RW_CUPOM_CURSO;
    $link = mcp_site_url() . '/' . ($cupom ? mcp_rw_caminho_cupom('email') : mcp_rw_caminho_botao($slug, $etapa, 'email'));
    $oi = $nome !== '' ? 'Oi, ' . mcp_escapar($nome) . '.' : 'Oi.';
    $emCurso = $generico ? 'em um dos nossos cursos' : 'em <strong>' . mcp_escapar($curso) . '</strong>';
    $comoConcluir = $generico
        ? 'Pelo botão abaixo você vê os cursos com turma aberta e conclui a inscrição.'
        : 'Seus dados ficaram registrados conosco. Para concluir, é só seguir pelo botão abaixo.';
    $botao = 'Concluir inscrição';
    switch ($etapa) {
        case 1:
            $titulo = $nome !== '' ? "Falta pouco, $nome." : 'Falta pouco.';
            $assunto = "Sua inscrição em $curso ainda não foi concluída";
            $preheader = 'Ainda não identificamos o pagamento. Para concluir, use o botão do e-mail.';
            $blocos = [mcp_email_b_p("$oi Você começou uma inscrição $emCurso na Cruz Vermelha Brasileira RJ, mas ainda não identificamos o pagamento."),
                mcp_email_b_p($comoConcluir)];
            break;
        case 2:
            $titulo = 'Ficou alguma dúvida?';
            $assunto = "Lembrete: sua inscrição em $curso continua em aberto";
            $preheader = 'Se ficou alguma dúvida sobre o curso ou o pagamento, é só responder este e-mail.';
            $blocos = [mcp_email_b_p("$oi Passando para lembrar: a sua inscrição $emCurso continua em aberto."),
                mcp_email_b_p('<strong>Ficou alguma dúvida</strong> sobre o curso, a turma ou o pagamento? É só responder este e-mail: a secretaria da Escola de Educação e Saúde lê e responde.'),
                mcp_email_b_p($generico ? 'Para concluir, escolha o curso pelo botão abaixo.' : 'Para concluir, use o botão abaixo.')];
            break;
        default:
            $titulo = 'Último aviso sobre a sua inscrição.';
            $assunto = $cupom ? 'Último aviso: cupom ' . $oferta['codigo'] . " em $curso" : "Último aviso sobre sua inscrição em $curso";
            $preheader = $cupom ? 'O cupom ' . $oferta['codigo'] . ' já vem aplicado no link.' : 'Depois deste, não mandamos outros lembretes sobre a inscrição.';
            $blocos = [mcp_email_b_p("$oi Este é o último lembrete sobre a sua inscrição $emCurso.")];
            if ($cupom) {
                $blocos[] = mcp_email_b_p('Para a turma de ' . mcp_escapar((string) $oferta['data']) . ', separamos para você o cupom <strong>' . mcp_escapar((string) $oferta['codigo'])
                    . '</strong>. Ele já vem aplicado pelo botão abaixo, e você vê o valor final antes de pagar.');
                $botao = 'Concluir inscrição com o cupom';
            }
            $blocos[] = mcp_email_b_p('Se ainda quiser participar, conclua pelo botão abaixo. Depois deste, não mandamos outros lembretes sobre a inscrição.');
            break;
    }
    $sair = mcp_escapar($descadastro);
    $c = mcp_email_juntar(array_merge($blocos, [
        mcp_email_b_botao($link, $botao),
        mcp_email_b_nota('Já pagou? Desconsidere este e-mail.'),
        mcp_email_b_html(mcp_nota('Não quer mais receber estes lembretes? <a href="' . $sair . '" style="color:#cc0000">Não quero mais receber</a>. Vale para o e-mail e para o WhatsApp.'),
            "Não quer mais receber estes lembretes (nem por e-mail nem pelo WhatsApp)? Não quero mais receber: $descadastro"),
    ]));
    return [
        'modelo' => $cupom ? 'rec3cupom' : 'rec' . $etapa,
        'assunto' => $assunto,
        'html' => mcp_moldura($titulo, $c['html'], mcp_email_opcoes_aluno($preheader,
            'Você recebeu este e-mail porque começou uma inscrição em cruzvermelhariodejaneiro.org ou na plataforma da Escola com este endereço. São no máximo 3 lembretes.')),
        'texto' => $c['texto'] . "\n\nDúvidas? Responda este e-mail ou escreva para " . mcp_email_contato_endereco() . ".\n",
        'link' => $link,
        'preheader' => $preheader,
    ];
}

// ----------------------------------------------------------------------------- descadastro de um clique
/** A assinatura do código de descadastro de uma linha (o mesmo segredo dos links dos avisos, com outro prefixo). */
function mcp_rw_email_assinatura(int $id): string
{
    return substr(mcp_avisos_assinar("rw|$id"), 0, 16);
}

/** "Não quero mais receber" e o List-Unsubscribe: api/avisos.php?rw=<id>.<assinatura>. */
function mcp_rw_email_descadastro_url(int $id): string
{
    return mcp_site_url() . '/matricula-cursos-presenciais/api/avisos.php?rw=' . $id . '.' . mcp_rw_email_assinatura($id);
}

/** Cabeçalhos do descadastro de um clique (RFC 8058), como os dos avisos (mcp_avisos_cabecalhos_descadastro). Puro. */
function mcp_rw_email_cabecalhos(int $id): array
{
    return ['List-Unsubscribe' => '<' . mcp_rw_email_descadastro_url($id) . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
}

/** A linha de um código de descadastro (id.assinatura), ou null se ele não vale. */
function mcp_rw_email_descadastro_linha(string $codigo): ?array
{
    if (!preg_match('/^(\d{1,10})\.([a-f0-9]{16})$/', $codigo, $m) || !hash_equals(mcp_rw_email_assinatura((int) $m[1]), $m[2])) {
        return null;
    }
    $stmt = mcp_db()->prepare("SELECT id, email_hash, whatsapp_hash, descadastro_em FROM mcp_recuperacao_email WHERE id = ? AND status <> 'controle'");
    $stmt->execute([(int) $m[1]]);
    return $stmt->fetch() ?: null;
}

/**
 * Descadastro: bloqueia o e-mail e o celular da pessoa (mcp_avisos_bloqueios, origem 'recuperacao', só pelos hashes da
 * linha) e marca a linha. A pessoa sai dos dois canais da recuperação (mcp_rw_agrupar e mcp_rw_email_descadastrou) e, como
 * todo bloqueio de aluno, dos avisos por e-mail e WhatsApp das aulas.
 */
function mcp_rw_email_descadastrar(array $linha): bool
{
    mcp_avisos_bloquear_hash('email', (string) $linha['email_hash'], 'recuperacao');
    if (!empty($linha['whatsapp_hash'])) {
        mcp_avisos_bloquear_hash('whatsapp', (string) $linha['whatsapp_hash'], 'recuperacao');
    }
    mcp_db()->prepare('UPDATE mcp_recuperacao_email SET descadastro_em = COALESCE(descadastro_em, ?), atualizado_em = ? WHERE id = ?')
        ->execute([mcp_agora(), mcp_agora(), (int) $linha['id']]);
    mcp_registrar(null, 'recuperacao_descadastro', 'e-mail #' . (int) $linha['id']);
    return true;
}

// ----------------------------------------------------------------------------- banco: cota, PIX e reconferência
/** Quantos e-mails da recuperação saíram nas 24 h até $agora (janela móvel, como o teto). */
function mcp_rw_email_enviados_24h(int $agora): int
{
    $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_recuperacao_email WHERE status = 'enviado' AND criado_em > ? AND criado_em <= ?");
    $stmt->execute([gmdate('Y-m-d H:i:s', $agora - 86400), gmdate('Y-m-d H:i:s', $agora)]);
    return (int) $stmt->fetchColumn();
}

/**
 * Os outros e-mails do site nas 24 h até $agora, pelo que fica registrado: os eventos email_* que não são de falha
 * (recibos, PIX, lembrete do PIX, horários, avisos à secretaria) e os avisos por e-mail enviados. É uma estimativa por
 * baixo (o chat e as respostas do painel não deixam registro), boa para segurar a recuperação quando o dia está cheio.
 */
function mcp_rw_email_site_24h(int $agora): int
{
    $de = gmdate('Y-m-d H:i:s', $agora - 86400);
    $ate = gmdate('Y-m-d H:i:s', $agora);
    $db = mcp_db();
    $stmt = $db->prepare("SELECT COUNT(*) FROM mcp_eventos WHERE tipo LIKE 'email\\_%' AND tipo NOT LIKE '%falhou%' AND criado_em > ? AND criado_em <= ?");
    $stmt->execute([$de, $ate]);
    $n = (int) $stmt->fetchColumn();
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM mcp_avisos WHERE canal = 'email' AND status = 'enviado' AND enviado_em > ? AND enviado_em <= ?");
        $stmt->execute([$de, $ate]);
        $n += (int) $stmt->fetchColumn();
    } catch (PDOException) {
        // Sem a tabela dos avisos, conta só os eventos.
    }
    return $n;
}

/**
 * O lembrete do PIX das inscrições do site $ids: lembretes = id => hora (ts) do 1º 'email_pix_lembrete'; esperando = id
 * => true para o PIX pendente que ainda pode ganhar o lembrete (gerado há menos de MCP_PIX_LEMBRETE_HORAS[1] horas, sem
 * lembrete nem tentativa registrada).
 * @return array{lembretes: array<int, int>, esperando: array<int, bool>}
 */
function mcp_rw_email_pix(array $ids, int $agora): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $saida = ['lembretes' => [], 'esperando' => []];
    if (!$ids) {
        return $saida;
    }
    $db = mcp_db();
    foreach (array_chunk($ids, 500) as $lote) {
        $marcas = implode(',', array_fill(0, count($lote), '?'));
        $stmt = $db->prepare("SELECT inscricao_id, tipo, MIN(criado_em) AS em FROM mcp_eventos WHERE tipo IN ('email_pix_lembrete', 'email_pix_lembrete_falhou')
            AND inscricao_id IN ($marcas) GROUP BY inscricao_id, tipo");
        $stmt->execute($lote);
        $tentado = [];
        foreach ($stmt->fetchAll() as $e) {
            $tentado[(int) $e['inscricao_id']] = true;
            $ts = mcp_rw_ts($e['em']);
            if ($e['tipo'] === 'email_pix_lembrete' && $ts !== null) {
                $saida['lembretes'][(int) $e['inscricao_id']] = $ts;
            }
        }
        $stmt = $db->prepare("SELECT id FROM mcp_inscricoes WHERE id IN ($marcas) AND status = 'pendente' AND metodo = 'pix' AND pix_copia_cola IS NOT NULL
            AND pix_copia_cola <> '' AND unicopag_hash IS NOT NULL AND criado_em > ? AND criado_em <= ?");
        $stmt->execute([...$lote, gmdate('Y-m-d H:i:s', $agora - MCP_PIX_LEMBRETE_HORAS[1] * 3600), gmdate('Y-m-d H:i:s', $agora)]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (!isset($tentado[(int) $id])) {
                $saida['esperando'][(int) $id] = true;
            }
        }
    }
    return $saida;
}

/**
 * Confere de novo, logo antes do envio: bloqueou o e-mail (em qualquer e-mail da pessoa, por qualquer motivo), disse
 * que não quer pelo WhatsApp da recuperação (em qualquer celular) ou pagou no site? O motivo para não mandar, ou null.
 */
function mcp_rw_email_reconferir(array $pessoa): ?string
{
    foreach (array_unique(array_filter([$pessoa['email'], ...$pessoa['emails']])) as $e) {
        if (mcp_avisos_bloqueado('email', $e)) {
            return 'bloqueou';
        }
    }
    foreach (array_unique(array_filter([$pessoa['numero'], ...$pessoa['numeros']])) as $n) {
        if (mcp_rw_bloqueado_pela_recuperacao('whatsapp', $n)) {
            return 'bloqueou';
        }
    }
    return mcp_rw_pagou_no_site($pessoa);
}

/**
 * Grava (INSERT IGNORE) uma linha da pessoa: só os HMAC e os hashes dos bloqueios. Devolve o id, ou 0 se a etapa já
 * existia (a UNIQUE(pessoa_chave, etapa) segura dois processos ao mesmo tempo).
 */
function mcp_rw_email_gravar(array $p, int $etapa, string $status, string $quando, ?string $erro = null): int
{
    $chave = mcp_rw_hmac($p['principal']);
    $outras = array_values(array_diff(mcp_rw_chaves($p), [$chave]));
    $stmt = mcp_db()->prepare('INSERT IGNORE INTO mcp_recuperacao_email (pessoa_chave, origem, etapa, curso_slug, curso_nome, email_hash, whatsapp_hash, chaves, status, erro, criado_em, atualizado_em)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$chave, $p['origem'], $etapa, $p['curso_slug'] !== '' ? mb_substr($p['curso_slug'], 0, 80) : null, mb_substr($p['curso_nome'], 0, 160),
        mcp_avisos_hash('email', (string) $p['email']), $p['numero'] !== null ? mcp_avisos_hash('whatsapp', (string) $p['numero']) : null,
        $outras ? implode(' ', $outras) : null, $status, $erro, $quando, $quando]);
    return $stmt->rowCount() === 1 ? (int) mcp_db()->lastInsertId() : 0;
}

// ----------------------------------------------------------------------------- a rodada
/**
 * Uma rodada do e-mail (api/lembretes.php, logo depois da do WhatsApp): manda os e-mails devidos, até
 * MCP_RW_EMAIL_POR_RODADA por rodada e MCP_RW_EMAIL_POR_DIA em 24 h, dá a etapa 1 como cumprida a quem já recebeu o
 * lembrete do PIX e grava a linha etapa 0 de quem é do grupo de controle. $carga: o público que a rodada do WhatsApp já
 * leu nesta hora (null = lê de novo). Nunca lança por causa da Resend nem da escola; erro de banco sobe para quem chama.
 *  - 'resend': enviado (com o id da Resend);
 *  - 'incerto' (o tempo acabou depois de o pedido chegar): conta como enviado, para nunca repetir; duas seguidas param;
 *  - 'limite' (429) ou sem a chave: a etapa não é gasta e a rodada para;
 *  - 'falhou' (a Resend recusou, ex.: endereço inválido): a etapa fica "falhou" e a sequência dessa pessoa para. Duas
 *    recusas seguidas dizem que o problema é da Resend, não de quem recebe: as duas voltam (não gastam a etapa) e a rodada
 *    para até a hora seguinte.
 * @return array{enviados: int, falhas: int, pulados: int, pulados_pix: int, esperando_pix: int, vistos: int, controle: int, escola: string, motivo: ?string}
 */
function mcp_rw_email_rodar(?int $agora = null, ?array $carga = null): array
{
    $agora ??= time();
    $inicioReal = time();
    $agoraReal = static fn(): int => $agora + max(0, time() - $inicioReal);
    $r = ['enviados' => 0, 'falhas' => 0, 'pulados' => 0, 'pulados_pix' => 0, 'esperando_pix' => 0, 'vistos' => 0, 'controle' => 0, 'escola' => '-', 'motivo' => null];
    $cfg = mcp_rw_email_config();
    if (!$cfg['ok']) {
        $r['motivo'] = 'sem configuração (' . implode(', ', $cfg['falta']) . ')';
        return $r;
    }
    if (!mcp_rw_no_horario($agora)) {
        $r['motivo'] = 'fora do horário (8h–20h de Brasília)';
        return $r;
    }
    $carga ??= mcp_rw_carregar($agora);
    $r['escola'] = $carga['escola']['estado'];
    if ($carga['escola']['estado'] === 'indisponivel') {
        // A mesma regra do WhatsApp: com a escola fora, seguem só o site e os importados.
        $r['motivo'] = 'escola indisponível (' . $carga['escola']['erro'] . '); seguem só o site e os importados';
    }
    $indiceWhatsapp = mcp_rw_indice(mcp_rw_linhas());
    $indice = mcp_rw_indice(mcp_rw_email_linhas());
    $fila = [];
    $controle = [];
    foreach ($carga['pessoas'] as $p) {
        if (!$p['no_fluxo_email']) {
            continue;
        }
        $r['vistos']++;
        // "Não tenho interesse" no WhatsApp ou descadastro de um e-mail: nada mais, nos dois canais.
        if (mcp_rw_nao_quer($p, $indiceWhatsapp) || mcp_rw_email_descadastrou($p, $indice)) {
            continue;
        }
        $minhas = mcp_rw_da_pessoa($p, $indice);
        $proxima = mcp_rw_email_proxima((int) $p['ancora'], $minhas);
        if ($proxima === null || $proxima['devida_em'] > $agora) {
            continue;
        }
        $minhasWhatsapp = mcp_rw_da_pessoa($p, $indiceWhatsapp);
        if (mcp_rw_controle($p, $minhasWhatsapp, $minhas)) {
            if (!isset($minhas[0]) && !isset($minhasWhatsapp[0])) {
                $controle[] = $p;
            }
            continue;
        }
        $fila[] = [$proxima['devida_em'], $proxima['etapa'], $p];
    }
    // Grupo de controle: só a linha etapa 0 (se o WhatsApp ainda não gravou a dele), depois da mesma reconferência.
    foreach ($controle as $p) {
        if (mcp_rw_email_reconferir($p) === null) {
            $r['controle'] += mcp_rw_email_gravar($p, 0, 'controle', gmdate('Y-m-d H:i:s', $agoraReal())) > 0 ? 1 : 0;
        }
    }
    // Etapa 1 e o lembrete do PIX: já recebeu → dada como cumprida ('pulado_pix', na hora do lembrete); ainda pode
    // receber → espera por ele, para a pessoa não ganhar dois e-mails seguidos.
    $refs = [];
    foreach ($fila as [, $etapa, $p]) {
        if ($etapa === 1) {
            array_push($refs, ...$p['refs_site']);
        }
    }
    if ($refs) {
        $pix = mcp_rw_email_pix($refs, $agora);
        $fila = array_values(array_filter($fila, static function (array $f) use ($pix, $agora, &$r): bool {
            [, $etapa, $p] = $f;
            if ($etapa !== 1) {
                return true;
            }
            $lembrete = array_intersect_key($pix['lembretes'], array_flip($p['refs_site']));
            if ($lembrete) {
                $quando = gmdate('Y-m-d H:i:s', min(min($lembrete), $agora));
                $r['pulados_pix'] += mcp_rw_email_gravar($p, 1, 'pulado_pix', $quando) > 0 ? 1 : 0;
                return false;
            }
            if (array_intersect_key($pix['esperando'], array_flip($p['refs_site']))) {
                $r['esperando_pix']++;
                return false;
            }
            return true;
        }));
    }
    if (!$fila) {
        return $r;
    }
    // O cupom da 3ª é decidido uma vez por rodada, como no WhatsApp.
    $oferta = array_filter($fila, static fn(array $f): bool => $f[1] === 3 && $f[2]['curso_slug'] === MCP_RW_CUPOM_CURSO) ? mcp_rw_oferta_cupom($agora) : null;
    usort($fila, static fn(array $a, array $b): int => [$a[0], $a[2]['principal']] <=> [$b[0], $b[2]['principal']]);
    $noDia = mcp_rw_email_enviados_24h($agora);
    $site = mcp_rw_email_site_24h($agora);
    if ($site >= MCP_RW_EMAIL_RESERVA_SITE) {
        $r['motivo'] = "o site já mandou $site e-mails em 24 h; a cota da Resend fica para os recibos";
        return $r;
    }
    $db = mcp_db();
    $recusas = [];
    $incertos = 0;
    foreach ($fila as [, $etapa, $p]) {
        if ($r['enviados'] + $r['falhas'] >= MCP_RW_EMAIL_POR_RODADA) {
            break;
        }
        if ($noDia >= MCP_RW_EMAIL_POR_DIA) {
            $r['motivo'] = 'teto de ' . MCP_RW_EMAIL_POR_DIA . ' e-mails em 24 h';
            break;
        }
        $agoraEnvio = $agoraReal();
        if (!mcp_rw_no_horario($agoraEnvio)) {
            $r['motivo'] = 'fora do horário (8h–20h de Brasília)';
            break;
        }
        if (mcp_rw_email_reconferir($p) !== null) {
            $r['pulados']++;
            continue;
        }
        // A linha é reservada ANTES do envio (a UNIQUE garante que só um processo manda esta etapa) e dá o id do
        // descadastro, que vai no próprio e-mail.
        $id = mcp_rw_email_gravar($p, $etapa, 'falhou', gmdate('Y-m-d H:i:s', $agoraEnvio), 'enviando');
        if ($id === 0) {
            $r['pulados']++;
            continue;
        }
        $msg = mcp_rw_email_montar($etapa, $p, $oferta, mcp_rw_email_descadastro_url($id));
        mcp_email_ultimo_id('');
        $envio = mcp_enviar_email((string) $p['email'], $msg['assunto'], $msg['html'], $msg['texto'], mcp_email_contato_endereco(), null, [],
            ['sem_reserva' => true, 'tempo' => MCP_RW_EMAIL_TEMPO_S, 'cabecalhos' => mcp_rw_email_cabecalhos($id)]);
        $atualizar = static function (string $status, ?string $provedor, ?string $erro) use ($db, $id, $msg, $agoraReal): void {
            $db->prepare('UPDATE mcp_recuperacao_email SET status = ?, modelo = ?, assunto = ?, provedor_id = ?, erro = ?, atualizado_em = ? WHERE id = ?')
                ->execute([$status, $msg['modelo'], mb_substr($msg['assunto'], 0, 200), $provedor, $erro, gmdate('Y-m-d H:i:s', $agoraReal()), $id]);
        };
        if ($envio === 'resend' || $envio === 'incerto') {
            $atualizar('enviado', mcp_email_ultimo_id() ?: null, $envio === 'incerto' ? 'a Resend não confirmou a tempo; o e-mail pode ter saído (não vai de novo)' : null);
            $r['enviados']++;
            $noDia++;
            $recusas = [];
            $incertos = $envio === 'incerto' ? $incertos + 1 : 0;
            if ($incertos >= 2) {
                $r['motivo'] = 'Resend: duas respostas sem confirmação seguidas';
                error_log('[matricula] recuperação por e-mail parada nesta rodada: a Resend não confirmou dois envios seguidos');
                break;
            }
            continue;
        }
        $r['falhas']++;
        if ($envio === 'limite' || $envio === 'sem_resend') {
            // Não saiu, e a culpa não é de quem recebe: a etapa não é gasta e o resto espera a próxima rodada.
            $db->prepare('DELETE FROM mcp_recuperacao_email WHERE id = ?')->execute([$id]);
            $r['motivo'] = $envio === 'limite' ? 'Resend: limite de envio (429); o resto fica para a próxima rodada' : 'sem configuração (RESEND_API_KEY)';
            error_log('[matricula] recuperação por e-mail parada nesta rodada: ' . $r['motivo']);
            break;
        }
        $atualizar('falhou', null, 'a Resend recusou o e-mail');
        $recusas[] = $id;
        if (count($recusas) >= 2) {
            // Duas recusas seguidas: o problema é da Resend (ou da conta), não dos endereços. Não gasta a etapa de ninguém.
            $db->prepare('DELETE FROM mcp_recuperacao_email WHERE id IN (' . implode(',', array_map('intval', $recusas)) . ')')->execute();
            $r['motivo'] = 'Resend: duas recusas seguidas; tenta de novo na próxima rodada';
            error_log('[matricula] recuperação por e-mail parada nesta rodada: duas recusas seguidas da Resend');
            break;
        }
    }
    return $r;
}
