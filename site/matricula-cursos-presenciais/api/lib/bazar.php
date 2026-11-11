<?php
/**
 * Bazar solidário (11/11/2026, pedido do Matheus): a pessoa avisa, na página /bazar/, o que quer doar; o site grava
 * (mcp_bazar_doacoes), manda um e-mail à equipe e avisa a responsável do bazar pelo WhatsApp.
 *
 * O aviso por WhatsApp sai pelo número dedicado da Kapso (o mesmo da recuperação, lib/recuperacao_whatsapp.php), com
 * o modelo MCP_BAZAR_MODELO. Ele SÓ sai quando as quatro coisas valem ao mesmo tempo:
 *   1. a Kapso está configurada (WHATSAPP_KAPSO_CHAVE e WHATSAPP_KAPSO_NUMERO_ID);
 *   2. o celular de quem recebe está no servidor (WHATSAPP_BAZAR_AVISO, em config-whatsapp.php ou config.php: o número
 *      é de uma pessoa e por isso não fica no código);
 *   3. o modelo está APROVADO na Meta (lido da lista de modelos, pela Kapso, na hora do envio);
 *   4. o modelo que a Meta tem confere com a mensagem: mesmo número de parâmetros e nenhum botão com variável.
 * Faltando qualquer uma, o registro fica gravado com o aviso 'pendente' e nada é enviado. A rodada de hora em hora
 * (api/lembretes.php → mcp_bazar_rodar) tenta de novo os pendentes das últimas MCP_BAZAR_VALIDADE_S; depois disso o
 * aviso vira 'vencido' (um aviso de três dias atrás não ajuda ninguém, e no dia da aprovação do modelo a responsável
 * não recebe uma pilha de mensagens velhas). O e-mail à equipe não depende de nada disso.
 *
 * Regras puras (sem banco nem rede, conferidas em scripts/testar_bazar.php): mcp_bazar_validar, mcp_bazar_protocolo,
 * mcp_bazar_itens_texto, mcp_bazar_parametro, mcp_bazar_modelo, mcp_bazar_corpo_envio, mcp_bazar_modelo_problema e
 * mcp_bazar_no_horario.
 */
declare(strict_types=1);

require_once __DIR__ . '/recuperacao_whatsapp.php';

// O nome do modelo na Meta (categoria Utilidade, pt_BR). O texto está em docs/bazar.md; mudou o texto, muda o nome
// (bazar_doacao_nova_v2…) aqui e lá: a Meta não deixa editar um modelo sem nova análise.
const MCP_BAZAR_MODELO = 'bazar_doacao_nova';
// Quantos {{n}} o texto do modelo tem: quem doa, WhatsApp, o que vai doar, entrega prevista e protocolo.
const MCP_BAZAR_MODELO_PARAMS = 5;
// O que a pessoa pode marcar. A chave vai para o banco; o rótulo, para a tela, o e-mail e o WhatsApp.
const MCP_BAZAR_ITENS = [
    'roupas' => 'Roupas',
    'calcados' => 'Calçados',
    'acessorios' => 'Bolsas e acessórios',
    'cama-mesa-banho' => 'Cama, mesa e banho',
    'cozinha' => 'Utensílios de cozinha',
    'casa' => 'Objetos de casa',
    'aparelhos' => 'Pequenos aparelhos',
    'outros' => 'Outros itens',
];
const MCP_BAZAR_ENTREGAS = [
    'esta-semana' => 'Esta semana',
    'proxima-semana' => 'Na próxima semana',
    'este-mes' => 'Ainda neste mês',
    'nao-sei' => 'Ainda não sabe',
];
const MCP_BAZAR_DESCRICAO_MAX = 600;
// Freios de abuso: por IP e por telefone, na última hora.
const MCP_BAZAR_LIMITE_IP = [6, 3600];
const MCP_BAZAR_LIMITE_TELEFONE = [3, 3600];
// Por quanto tempo um aviso pendente ainda é tentado, quantas vezes e quantos por rodada.
const MCP_BAZAR_VALIDADE_S = 48 * 3600;
const MCP_BAZAR_TENTATIVAS = 6;
const MCP_BAZAR_POR_RODADA = 20;
// A rodada só manda das 8h às 20h de Brasília; o aviso na hora do registro também respeita o horário.
const MCP_BAZAR_HORARIO = [8, 20];

// ----------------------------------------------------------------------------- regras puras
/**
 * Confere o que veio do formulário. Devolve ['ok' => true, 'dados' => [...]] ou ['ok' => false, 'campo', 'erro'].
 * O telefone tem de ser um celular com WhatsApp (55 + DDD + 9 dígitos): é por ele que a equipe combina a entrega.
 */
function mcp_bazar_validar(array $b): array
{
    $falha = static fn(string $campo, string $erro): array => ['ok' => false, 'campo' => $campo, 'erro' => $erro];
    $nome = mcp_texto($b['nome'] ?? '', 120);
    if (mb_strlen($nome) < 2) {
        return $falha('nome', 'Digite o seu nome.');
    }
    $numero = mcp_whatsapp_numero(mcp_texto($b['telefone'] ?? '', 30));
    if ($numero === null) {
        return $falha('telefone', 'Digite o seu celular com DDD, por exemplo (21) 91234-5678.');
    }
    $itens = [];
    foreach (is_array($b['itens'] ?? null) ? $b['itens'] : [] as $chave) {
        if (is_string($chave) && isset(MCP_BAZAR_ITENS[$chave]) && !in_array($chave, $itens, true)) {
            $itens[] = $chave;
        }
    }
    if ($itens === []) {
        return $falha('itens', 'Marque pelo menos um tipo de item que você quer doar.');
    }
    $descricao = mcp_texto_longo($b['descricao'] ?? '', MCP_BAZAR_DESCRICAO_MAX + 1);
    if (mb_strlen($descricao) > MCP_BAZAR_DESCRICAO_MAX) {
        return $falha('descricao', 'O texto pode ter até ' . MCP_BAZAR_DESCRICAO_MAX . ' letras.');
    }
    if (in_array('outros', $itens, true) && mb_strlen($descricao) < 3) {
        return $falha('descricao', 'Você marcou “Outros itens”: escreva quais são.');
    }
    $entrega = is_string($b['entrega'] ?? null) && isset(MCP_BAZAR_ENTREGAS[$b['entrega']]) ? $b['entrega'] : 'nao-sei';
    return ['ok' => true, 'dados' => [
        'nome' => $nome, 'telefone' => substr($numero, 2), 'itens' => implode(',', $itens),
        'descricao' => $descricao !== '' ? $descricao : null, 'entrega' => $entrega,
    ]];
}

/** Protocolo que a pessoa cita ao entregar: BZ-aammdd-NNNN (data de Brasília, id do registro). */
function mcp_bazar_protocolo(int $id, ?string $agoraUtc = null): string
{
    $data = (new DateTimeImmutable($agoraUtc ?? mcp_agora(), new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Sao_Paulo'));
    return sprintf('BZ-%s-%04d', $data->format('ymd'), $id);
}

/** "Roupas, Calçados. Detalhes: 3 sacos de roupa de criança": os tipos marcados e o que a pessoa escreveu. */
function mcp_bazar_itens_texto(array $r): string
{
    $rotulos = [];
    foreach (explode(',', (string) ($r['itens'] ?? '')) as $chave) {
        if (isset(MCP_BAZAR_ITENS[$chave])) {
            $rotulos[] = MCP_BAZAR_ITENS[$chave];
        }
    }
    $texto = implode(', ', $rotulos);
    $descricao = trim((string) ($r['descricao'] ?? ''));
    return $descricao !== '' ? ($texto !== '' ? "$texto. Detalhes: $descricao" : $descricao) : $texto;
}

/**
 * Um parâmetro como a Meta aceita: numa linha só (ela recusa quebra de linha, tabulação e mais de 4 espaços
 * seguidos), cortado em $limite, e nunca vazio (parâmetro vazio é recusado).
 */
function mcp_bazar_parametro(string $texto, int $limite): string
{
    $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));
    if (mb_strlen($texto) > $limite) {
        $texto = rtrim(mb_substr($texto, 0, $limite - 1)) . '…';
    }
    return $texto !== '' ? $texto : '-';
}

/** O modelo e os parâmetros do aviso de um registro, na ordem do texto aprovado (docs/bazar.md). */
function mcp_bazar_modelo(array $r): array
{
    return ['nome' => MCP_BAZAR_MODELO, 'parametros' => [
        mcp_bazar_parametro((string) ($r['nome'] ?? ''), 80),
        mcp_bazar_parametro(mcp_telefone_bonito((string) ($r['telefone'] ?? '')), 20),
        mcp_bazar_parametro(mcp_bazar_itens_texto($r), 400),
        mcp_bazar_parametro(MCP_BAZAR_ENTREGAS[(string) ($r['entrega'] ?? '')] ?? MCP_BAZAR_ENTREGAS['nao-sei'], 40),
        mcp_bazar_parametro((string) ($r['protocolo'] ?? ''), 20),
    ]];
}

/** O corpo do POST /{numero}/messages: modelo em pt_BR, só com os parâmetros do texto (o modelo não tem botão). */
function mcp_bazar_corpo_envio(string $numero, array $modelo): array
{
    return [
        'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $numero, 'type' => 'template',
        'template' => [
            'name' => $modelo['nome'], 'language' => ['code' => 'pt_BR'],
            'components' => [
                ['type' => 'body', 'parameters' => array_map(static fn(string $t): array => ['type' => 'text', 'text' => $t], array_values($modelo['parametros']))],
            ],
        ],
    ];
}

/**
 * O que impede mandar o aviso com o modelo como a Meta o tem, ou null. $m: ['situacao' => 'APPROVED'…, 'params' =>
 * quantos {{n}} o texto tem (null = a Meta não mandou os componentes), 'botao' => índice do botão com variável (-1 =
 * não tem)]. Sem os componentes não há como conferir, e a regra é só disparar quando estiver correto: não manda.
 */
function mcp_bazar_modelo_problema(array $m): ?string
{
    $situacao = (string) ($m['situacao'] ?? 'ausente');
    if ($situacao !== 'APPROVED') {
        return $situacao === 'ausente' ? 'o modelo ' . MCP_BAZAR_MODELO . ' não existe na Meta' : 'o modelo ' . MCP_BAZAR_MODELO . " ainda não está aprovado na Meta ($situacao)";
    }
    $params = $m['params'] ?? null;
    if ($params === null) {
        return 'a Meta não informou o texto do modelo ' . MCP_BAZAR_MODELO . ' para conferir';
    }
    if ($params !== MCP_BAZAR_MODELO_PARAMS) {
        return 'o modelo ' . MCP_BAZAR_MODELO . " na Meta tem $params parâmetro(s), e a mensagem manda " . MCP_BAZAR_MODELO_PARAMS;
    }
    if (($m['botao'] ?? -1) !== -1) {
        return 'o modelo ' . MCP_BAZAR_MODELO . ' na Meta tem um botão com variável, que a mensagem não preenche';
    }
    return null;
}

/** Das 8h às 20h de Brasília? */
function mcp_bazar_no_horario(int $agora): bool
{
    $hora = (int) (new DateTimeImmutable('@' . $agora))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('G');
    return $hora >= MCP_BAZAR_HORARIO[0] && $hora < MCP_BAZAR_HORARIO[1];
}

// ----------------------------------------------------------------------------- banco
function mcp_bazar_gravar(array $d, string $ip): int
{
    $agora = mcp_agora();
    $pdo = mcp_db();
    $pdo->prepare('INSERT INTO mcp_bazar_doacoes (nome, telefone, itens, descricao, entrega, ip, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$d['nome'], $d['telefone'], $d['itens'], $d['descricao'], $d['entrega'], $ip !== '' ? $ip : null, $agora, $agora]);
    return (int) $pdo->lastInsertId();
}

function mcp_bazar_por_id(int $id): ?array
{
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_bazar_doacoes WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function mcp_bazar_atualizar(int $id, array $campos): void
{
    $permitidos = ['protocolo', 'email_equipe', 'aviso_status', 'aviso_modelo', 'aviso_provedor_id', 'aviso_erro', 'aviso_tentativas', 'aviso_em'];
    $campos = array_intersect_key($campos, array_flip($permitidos));
    if ($campos === []) {
        return;
    }
    $campos['atualizado_em'] = mcp_agora();
    $sql = implode(', ', array_map(static fn(string $c): string => "$c = ?", array_keys($campos)));
    mcp_db()->prepare("UPDATE mcp_bazar_doacoes SET $sql WHERE id = ?")->execute([...array_values($campos), $id]);
}

function mcp_bazar_contar_recentes(string $coluna, string $valor, int $segundos): int
{
    if (!in_array($coluna, ['ip', 'telefone'], true)) {
        throw new InvalidArgumentException("coluna não permitida: $coluna");
    }
    $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_bazar_doacoes WHERE $coluna = ? AND criado_em > ?");
    $stmt->execute([$valor, gmdate('Y-m-d H:i:s', time() - $segundos)]);
    return (int) $stmt->fetchColumn();
}

// ----------------------------------------------------------------------------- e-mail à equipe
function mcp_montar_email_bazar_equipe(array $r): array
{
    $protocolo = (string) ($r['protocolo'] ?? '');
    $telefone = (string) $r['telefone'];
    $entrega = MCP_BAZAR_ENTREGAS[(string) $r['entrega']] ?? MCP_BAZAR_ENTREGAS['nao-sei'];
    $quando = mcp_data_brt((string) ($r['criado_em'] ?? ''), 'd/m/Y \à\s H\hi');
    $zap = 'https://wa.me/55' . mcp_digitos($telefone);
    $corpo = mcp_p('Uma pessoa avisou, pela página do bazar, o que quer doar. O registro está guardado no site; combine a entrega pelo WhatsApp dela.')
        . mcp_caixa([
            'Nome' => (string) $r['nome'],
            'WhatsApp' => ['html' => '<a href="' . mcp_escapar($zap) . '" style="color:#cc0000;text-decoration:none">' . mcp_escapar(mcp_telefone_bonito($telefone)) . '</a>'],
            'O que vai doar' => mcp_bazar_itens_texto($r),
            'Entrega prevista' => $entrega,
            'Protocolo' => $protocolo,
            'Registrado em' => $quando,
        ])
        . mcp_botao($zap, 'Chamar no WhatsApp');
    $texto = "Nova doação para o bazar\n\nNome: {$r['nome']}\nWhatsApp: " . mcp_telefone_bonito($telefone) . "\nO que vai doar: " . mcp_bazar_itens_texto($r)
        . "\nEntrega prevista: $entrega\nProtocolo: $protocolo\nRegistrado em: $quando\n\nChamar no WhatsApp: $zap\n";
    return [
        'assunto' => "Bazar: nova doação · $protocolo",
        'html' => mcp_moldura('Nova doação para o bazar', $corpo, ['eyebrow' => 'Bazar solidário', 'preheader' => mcp_primeiro_nome((string) $r['nome']) . ' quer doar: ' . mcp_bazar_itens_texto($r)]),
        'texto' => $texto,
    ];
}

/** Manda o e-mail à equipe (EMAIL_CONTATO). Devolve resend, mail ou falhou; nunca derruba o registro. */
function mcp_email_bazar_equipe(array $r): string
{
    try {
        $m = mcp_montar_email_bazar_equipe($r);
        return mcp_enviar_email(mcp_email_contato_endereco(), $m['assunto'], $m['html'], $m['texto']);
    } catch (Throwable $e) {
        error_log('[bazar] e-mail à equipe falhou: ' . get_class($e));
        return 'falhou';
    }
}

// ----------------------------------------------------------------------------- aviso por WhatsApp
/** O WhatsApp de quem recebe os avisos (55 + DDD + número), ou null se não está no servidor ou é inválido. */
function mcp_bazar_destino(): ?string
{
    return mcp_whatsapp_numero((string) mcp_cfg('WHATSAPP_BAZAR_AVISO', ''));
}

/**
 * O modelo do bazar como está na Meta, lido pela Kapso: ['ok' => a lista veio, 'situacao' => APPROVED, PENDING,
 * REJECTED… ou 'ausente', 'categoria', 'params', 'botao', 'erro']. O WABA vem do número, como em mcp_rw_modelos.
 */
function mcp_bazar_modelo_na_meta(array $cfg): array
{
    $falhou = static fn(?string $e): array => ['ok' => false, 'situacao' => 'ausente', 'categoria' => '', 'params' => null, 'botao' => null, 'erro' => $e];
    [, $numero, $erro] = mcp_rw_kapso_pedir($cfg, 'GET', $cfg['base'] . '/platform/v1/whatsapp/phone_numbers/' . rawurlencode($cfg['numero_id']), null, 10);
    $d = is_array($numero['data'] ?? null) ? $numero['data'] : [];
    $waba = null;
    foreach ([$d['business_account_id'] ?? null, $d['whatsapp_business_account_id'] ?? null, $d['waba_id'] ?? null, $d['business_account']['id'] ?? null] as $v) {
        if ((is_string($v) || is_int($v)) && preg_match('/^\d{5,30}$/', (string) $v)) {
            $waba = (string) $v;
            break;
        }
    }
    if ($waba === null) {
        return $falhou($erro ?? 'a Kapso não disse a conta (WABA) do número');
    }
    $url = $cfg['base'] . '/meta/whatsapp/v24.0/' . rawurlencode($waba) . '/message_templates?name=' . rawurlencode(MCP_BAZAR_MODELO)
        . '&fields=name,status,language,category,components&limit=50';
    [, $lista, $erro] = mcp_rw_kapso_pedir($cfg, 'GET', $url, null, 10);
    if ($erro !== null) {
        return $falhou($erro);
    }
    $r = ['ok' => true, 'situacao' => 'ausente', 'categoria' => '', 'params' => null, 'botao' => null, 'erro' => null];
    foreach (is_array($lista['data'] ?? null) ? $lista['data'] : [] as $m) {
        // O filtro "name" da Meta é por trecho: só vale o nome exato, em pt_BR.
        if (($m['name'] ?? '') !== MCP_BAZAR_MODELO || ($m['language'] ?? 'pt_BR') !== 'pt_BR') {
            continue;
        }
        $e = mcp_rw_estrutura($m['components'] ?? null);
        $r = ['ok' => true, 'situacao' => mb_substr(strtoupper((string) ($m['status'] ?? '')), 0, 12) ?: 'ausente',
            'categoria' => mb_substr(strtoupper((string) ($m['category'] ?? '')), 0, 20), 'params' => $e['params'], 'botao' => $e['botao'], 'erro' => null];
        break;
    }
    return $r;
}

/**
 * O que está segurando os avisos agora, ou null se está tudo certo para mandar. Lê a Meta (uma chamada por rodada ou
 * por registro): $modeloNaMeta permite reaproveitar a leitura dentro da rodada.
 * @return array{0: ?string, 1: array, 2: ?string} [motivo, configuração da Kapso, número de destino]
 */
function mcp_bazar_pronto(?array $modeloNaMeta = null): array
{
    $cfg = mcp_rw_kapso_config();
    if (!$cfg['ok']) {
        return ['falta configurar a Kapso (' . implode(', ', $cfg['falta']) . ')', $cfg, null];
    }
    $destino = mcp_bazar_destino();
    if ($destino === null) {
        return ['falta o celular de quem recebe os avisos (WHATSAPP_BAZAR_AVISO)', $cfg, null];
    }
    $m = $modeloNaMeta ?? mcp_bazar_modelo_na_meta($cfg);
    if (!$m['ok']) {
        return ['a Kapso não informou a situação do modelo (' . ($m['erro'] ?? 'sem resposta') . ')', $cfg, $destino];
    }
    return [mcp_bazar_modelo_problema($m), $cfg, $destino];
}

/**
 * Tenta mandar o aviso de UM registro pendente. Devolve 'enviado', 'falhou' (não adianta tentar de novo), 'pendente'
 * (fica para a próxima rodada; $motivo diz por quê) ou 'parar' (erro que vale para todos: a rodada para).
 * A linha é marcada ANTES do envio (aviso_tentativas + 1 com a condição de ainda estar pendente): duas rodadas ao mesmo
 * tempo não mandam o mesmo aviso duas vezes.
 */
function mcp_bazar_avisar(array $r, array $cfg, string $destino, ?string &$motivo = null): string
{
    $id = (int) $r['id'];
    $stmt = mcp_db()->prepare("UPDATE mcp_bazar_doacoes SET aviso_tentativas = aviso_tentativas + 1, atualizado_em = ? WHERE id = ? AND aviso_status = 'pendente' AND aviso_tentativas = ?");
    $stmt->execute([mcp_agora(), $id, (int) $r['aviso_tentativas']]);
    if ($stmt->rowCount() !== 1) {
        $motivo = 'outra rodada já pegou este aviso';
        return 'pendente';
    }
    $modelo = mcp_bazar_modelo($r);
    $envio = mcp_rw_kapso_enviar($cfg, mcp_bazar_corpo_envio($destino, $modelo));
    if ($envio['ok']) {
        // "incerto" (a Kapso não confirmou a tempo) também conta como enviado: a mensagem pode ter saído, e não vai de novo.
        mcp_bazar_atualizar($id, ['aviso_status' => 'enviado', 'aviso_modelo' => $modelo['nome'], 'aviso_provedor_id' => $envio['id'], 'aviso_erro' => $envio['erro'], 'aviso_em' => mcp_agora()]);
        return 'enviado';
    }
    $motivo = (string) $envio['erro'];
    if ($envio['escopo'] === 'global') {
        // Kapso ou Meta fora, chave recusada, limite: não é deste aviso. A tentativa não conta e a rodada para.
        mcp_db()->prepare('UPDATE mcp_bazar_doacoes SET aviso_tentativas = aviso_tentativas - 1, aviso_erro = ?, atualizado_em = ? WHERE id = ? AND aviso_tentativas > 0')
            ->execute([mb_substr($motivo, 0, 250), mcp_agora(), $id]);
        return 'parar';
    }
    $esgotou = (int) $r['aviso_tentativas'] + 1 >= MCP_BAZAR_TENTATIVAS;
    if ($envio['escopo'] === 'destino' || $esgotou) {
        // De quem recebe (número sem WhatsApp, bloqueou o número…) ou tentativas esgotadas: não adianta insistir.
        mcp_bazar_atualizar($id, ['aviso_status' => 'falhou', 'aviso_modelo' => $modelo['nome'], 'aviso_erro' => mb_substr($motivo, 0, 250)]);
        return 'falhou';
    }
    mcp_bazar_atualizar($id, ['aviso_erro' => mb_substr($motivo, 0, 250)]);
    return 'pendente';
}

/**
 * A rodada: vence os pendentes velhos e tenta os outros, do mais antigo para o mais novo. Fora do horário, ou com
 * qualquer coisa faltando (configuração, destino, modelo aprovado e conferido), não manda nada e diz o motivo.
 * @return array{enviados: int, falhas: int, pendentes: int, vencidos: int, motivo: ?string}
 */
function mcp_bazar_rodar(?int $agora = null): array
{
    $agora ??= time();
    $r = ['enviados' => 0, 'falhas' => 0, 'pendentes' => 0, 'vencidos' => 0, 'motivo' => null];
    $pdo = mcp_db();
    $limite = gmdate('Y-m-d H:i:s', $agora - MCP_BAZAR_VALIDADE_S);
    $stmt = $pdo->prepare("UPDATE mcp_bazar_doacoes SET aviso_status = 'vencido', atualizado_em = ? WHERE aviso_status = 'pendente' AND criado_em < ?");
    $stmt->execute([gmdate('Y-m-d H:i:s', $agora), $limite]);
    $r['vencidos'] = $stmt->rowCount();
    $stmt = $pdo->prepare("SELECT * FROM mcp_bazar_doacoes WHERE aviso_status = 'pendente' AND criado_em >= ? AND protocolo IS NOT NULL ORDER BY id LIMIT " . (MCP_BAZAR_POR_RODADA + 1));
    $stmt->execute([$limite]);
    $fila = $stmt->fetchAll();
    $r['pendentes'] = count($fila);
    if ($fila === []) {
        return $r;
    }
    if (!mcp_bazar_no_horario($agora)) {
        $r['motivo'] = 'fora do horário (8h às 20h)';
        return $r;
    }
    [$motivo, $cfg, $destino] = mcp_bazar_pronto();
    if ($motivo !== null) {
        $r['motivo'] = $motivo;
        return $r;
    }
    foreach (array_slice($fila, 0, MCP_BAZAR_POR_RODADA) as $linha) {
        $porque = null;
        $resultado = mcp_bazar_avisar($linha, $cfg, (string) $destino, $porque);
        if ($resultado === 'enviado') {
            $r['enviados']++;
            $r['pendentes']--;
        } elseif ($resultado === 'falhou') {
            $r['falhas']++;
            $r['pendentes']--;
        } elseif ($resultado === 'parar') {
            $r['motivo'] = $porque;
            break;
        }
    }
    return $r;
}

/**
 * O aviso logo depois do registro (api/bazar.php): a mesma conferência da rodada, para um registro só. Qualquer
 * problema deixa o aviso pendente para a rodada; nunca derruba o registro. Devolve o que aconteceu, para o evento.
 */
function mcp_bazar_avisar_agora(int $id, ?int $agora = null): string
{
    try {
        if (!mcp_bazar_no_horario($agora ?? time())) {
            return 'pendente (fora do horário)';
        }
        [$motivo, $cfg, $destino] = mcp_bazar_pronto();
        if ($motivo !== null) {
            return "pendente ($motivo)";
        }
        $linha = mcp_bazar_por_id($id);
        if ($linha === null || $linha['aviso_status'] !== 'pendente') {
            return 'pendente';
        }
        $porque = null;
        $resultado = mcp_bazar_avisar($linha, $cfg, (string) $destino, $porque);
        return $resultado === 'enviado' ? 'enviado' : ($resultado === 'falhou' ? "falhou ($porque)" : "pendente ($porque)");
    } catch (Throwable $e) {
        error_log('[bazar] aviso por WhatsApp: ' . get_class($e) . ': ' . $e->getMessage());
        return 'pendente (erro)';
    }
}
