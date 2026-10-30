<?php
/**
 * Recuperação por WhatsApp (30/10/2026, pedido do Matheus): três mensagens AUTOMÁTICAS, por modelos aprovados na Meta
 * (enviados pela Kapso, no número dedicado), a quem se cadastrou e não pagou, no checkout do site ou na plataforma da
 * escola. Substitui a lista manual da primeira versão (wa.me, planilha e convite para outro curso), que saiu inteira.
 *
 * Quem entra (o "público"):
 *  - site: mcp_inscricoes pendente, expirado ou recusado criadas nos últimos MCP_RW_JANELA_DIAS dias;
 *  - escola: "Usuario" com papel ALUNO criado nos últimos MCP_RW_JANELA_DIAS dias sem nenhuma "Matricula" paga
 *    (taxaConfirmada ou statusPagamento PAGO/PARCELADO): quem só criou a conta e quem se matriculou sem pagar. Lido
 *    pelo PostgREST da escola com a chave secreta (ESCOLA_API_TOKEN), paginado e com tempo curto. Escola fora do ar não
 *    derruba nada: a rodada segue só com o site e o painel diz "escola indisponível";
 *  - uma PESSOA junta as tentativas dos dois sistemas com o mesmo CPF, e-mail (minúsculo) ou celular (55+DDD+número,
 *    pelo mcp_whatsapp_numero, o mesmo de todo o WhatsApp do site);
 *  - sai quem pagou QUALQUER coisa em QUALQUER sistema (site: pago ou estornado, de qualquer época; escola: matrícula
 *    paga), quem não tem celular válido, quem está em mcp_avisos_bloqueios (WhatsApp; inclui quem respondeu "Não tenho
 *    interesse", SAIR, PARAR ou STOP) e os CPFs e cursos de teste (TESTE_CPFS; slug ou nome com "teste" ou "dev").
 *
 * O fluxo, a partir da ÂNCORA (o cadastro ou a tentativa mais recente dentro da janela): mensagem 1 com 3 h, 2 com 24 h
 * (se a 1 saiu) e 3 com 72 h (se a 2 saiu), só das 8h às 20h de Brasília. Entre uma mensagem e a seguinte vale também o
 * intervalo da régua (21 h e 48 h): sem isso, no primeiro dia no ar, quem se cadastrou há 5 dias receberia as três em
 * três horas seguidas. Antes de cada envio a pessoa é conferida de novo no banco do site (pagou? bloqueou?). Nenhuma etapa
 * se repete: UNIQUE(pessoa_chave, etapa) em mcp_recuperacao_whatsapp, e as etapas da pessoa são procuradas por todas as
 * chaves dela e pelo celular. No máximo MCP_RW_POR_RODADA envios por rodada (o número dedicado tem 250 por dia na Meta).
 * A rodada é a de hora em hora (api/lembretes.php); falha aqui nunca derruba a rotina.
 *
 * Configuração: WHATSAPP_KAPSO_CHAVE e WHATSAPP_KAPSO_NUMERO_ID (config-whatsapp.php ou config.php). Sem as duas, nada
 * sai e o painel (api/recuperacao.php) diz o que falta. A chave nunca vai para log, erro, painel nem banco.
 *
 * Regras puras (sem banco nem rede, conferidas no teste): mcp_rw_tentativas_site, mcp_rw_tentativas_escola,
 * mcp_rw_agrupar, mcp_rw_proxima, mcp_rw_no_horario, mcp_rw_corpo_envio, mcp_rw_caminho_botao, mcp_rw_ler_webhook,
 * mcp_rw_quer_parar e mcp_rw_metricas.
 */
declare(strict_types=1);

const MCP_RW_JANELA_DIAS = 10;
// As métricas olham mais longe que a janela: quem recebeu a 1ª mensagem e pagou dias depois continua contando.
const MCP_RW_JANELA_METRICA_DIAS = 40;
// Segundos depois da âncora em que cada etapa fica devida.
const MCP_RW_ETAPAS = [1 => 3 * 3600, 2 => 24 * 3600, 3 => 72 * 3600];
const MCP_RW_MODELOS = [1 => 'rec_inscricao_1', 2 => 'rec_inscricao_2', 3 => 'rec_inscricao_3'];
const MCP_RW_POR_RODADA = 40;
// Das 8h às 20h de Brasília (UTC−3, sem horário de verão desde 2019).
const MCP_RW_HORARIO = [8, 20];
const MCP_RW_FUSO_SEGUNDOS = -3 * 3600;
const MCP_RW_ABERTOS_SITE = ['pendente', 'expirado', 'recusado'];
const MCP_RW_PAGOS_SITE = ['pago', 'estornado'];
const MCP_RW_PAGOS_ESCOLA = ['PAGO', 'PARCELADO'];
const MCP_RW_CURSO_GENERICO = 'nossos cursos';
const MCP_RW_POR_PAGINA = 50;
const MCP_RW_DIAS_METRICA = 14;
// Respostas que param tudo, já normalizadas (minúsculas, sem acento, só letras e espaço). O botão do rec_inscricao_3.
const MCP_RW_PALAVRAS_PARAR = ['nao tenho interesse', 'sair', 'parar', 'stop'];
// Escola: tempo curto (a rodada e o painel não esperam a escola), páginas de 1000 e no máximo 20 páginas.
const MCP_RW_ESCOLA_TEMPO_S = 10;
const MCP_RW_ESCOLA_PAGINA = 1000;
const MCP_RW_ESCOLA_PAGINAS = 20;
const MCP_RW_ESCOLA_LOTE = 60;
const MCP_RW_KAPSO_TEMPO_S = 20;
const MCP_RW_CACHE_S = 600;
const MCP_RW_EVENTOS_WEBHOOK = ['whatsapp.message.received', 'whatsapp.message.delivered', 'whatsapp.message.read', 'whatsapp.message.failed'];

// ----------------------------------------------------------------------------- identidade
/** HMAC-SHA256 (64 hex) de um identificador ("c:<cpf>", "e:<email>", "t:<55…>"). Sem o segredo do banco, não se volta. */
function mcp_rw_hmac(string $identificador): string
{
    return hash_hmac('sha256', $identificador, mcp_segredo('recuperacao_whatsapp'));
}

function mcp_rw_email(?string $email): string
{
    $e = mb_strtolower(trim((string) $email));
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
}

function mcp_rw_cpf(?string $cpf): string
{
    $d = mcp_digitos((string) $cpf);
    return strlen($d) === 11 ? $d : '';
}

/** "MARIA" e "maria" viram "Maria"; nome já escrito com maiúsculas e minúsculas fica como está. */
function mcp_rw_primeiro_nome(string $nome): string
{
    $p = mcp_primeiro_nome(trim($nome));
    if ($p === '') {
        return '';
    }
    return ($p === mb_strtoupper($p) || $p === mb_strtolower($p)) ? mb_convert_case(mb_strtolower($p), MB_CASE_TITLE) : $p;
}

/** Curso de teste: "teste" ou "dev" no slug ou no nome. */
function mcp_rw_curso_de_teste(?string $slug, ?string $nome): bool
{
    $t = mb_strtolower((string) $slug . ' ' . (string) $nome);
    return str_contains($t, 'teste') || str_contains($t, 'dev');
}

/** Instante de um texto de data: do MySQL (UTC, sem fuso) ou do PostgREST ("2026-10-30T12:00:00.123", UTC, ou com fuso). */
function mcp_rw_ts(mixed $texto): ?int
{
    if (!is_string($texto) || trim($texto) === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable(trim($texto), new DateTimeZone('UTC')))->getTimestamp();
    } catch (Throwable) {
        return null;
    }
}

/** Catálogo: uuid da escola → slug do site (o "uuid" do cursos.json é o id do curso na escola). */
function mcp_rw_slugs_por_uuid(): array
{
    $mapa = [];
    foreach (mcp_catalogo()['cursos'] ?? [] as $c) {
        if (!empty($c['uuid']) && !empty($c['slug'])) {
            $mapa[mb_strtolower((string) $c['uuid'])] = (string) $c['slug'];
        }
    }
    return $mapa;
}

// ----------------------------------------------------------------------------- tentativas (puro)
/**
 * Linhas de mcp_inscricoes → tentativas. Cada tentativa: origem, ref, cpf, email, numero (55…, ou null), nome,
 * curso_slug, curso_nome, quando (ts), aberta (conta para o fluxo), pago, pago_ts.
 */
function mcp_rw_tentativas_site(array $linhas, int $inicio): array
{
    $saida = [];
    foreach ($linhas as $l) {
        $quando = (int) mcp_rw_ts($l['criado_em'] ?? null);
        $pago = in_array($l['status'] ?? '', MCP_RW_PAGOS_SITE, true);
        $saida[] = [
            'origem' => 'site', 'ref' => 's' . (int) ($l['id'] ?? 0), 'cpf' => mcp_rw_cpf($l['cpf'] ?? ''), 'email' => mcp_rw_email($l['email'] ?? ''),
            'numero' => mcp_whatsapp_numero((string) ($l['telefone'] ?? '')), 'nome' => trim((string) ($l['nome'] ?? '')),
            'curso_slug' => (string) ($l['curso_slug'] ?? ''), 'curso_nome' => (string) ($l['curso_nome'] ?? ''), 'quando' => $quando,
            'aberta' => !$pago && in_array($l['status'] ?? '', MCP_RW_ABERTOS_SITE, true) && $quando >= $inicio,
            'pago' => $pago, 'pago_ts' => $pago ? (int) (mcp_rw_ts($l['pago_em'] ?? null) ?? mcp_rw_ts($l['atualizado_em'] ?? null) ?? $quando) : null,
        ];
    }
    return $saida;
}

/** A matrícula da escola está paga? (taxa confirmada ou curso pago/parcelado) */
function mcp_rw_matricula_paga(array $m): bool
{
    return ($m['taxaConfirmada'] ?? false) === true || in_array($m['statusPagamento'] ?? '', MCP_RW_PAGOS_ESCOLA, true);
}

/**
 * "Usuario" da escola (com as matrículas embutidas) → tentativas: uma pelo cadastro (curso "nossos cursos") e uma por
 * matrícula (o curso da turma). Só é "aberta" quem é ALUNO, foi criado na janela e não tem matrícula paga. Usuário de
 * fora da janela entra só para dizer se a pessoa pagou (busca pelos dados de quem veio do site).
 */
function mcp_rw_tentativas_escola(array $usuarios, int $inicio, array $slugsPorUuid): array
{
    $saida = [];
    foreach ($usuarios as $u) {
        if (!is_array($u) || !is_string($u['id'] ?? null)) {
            continue;
        }
        $matriculas = array_values(array_filter(is_array($u['matriculas'] ?? null) ? $u['matriculas'] : [], 'is_array'));
        $pagas = array_values(array_filter($matriculas, 'mcp_rw_matricula_paga'));
        $pagoTs = null;
        foreach ($pagas as $m) {
            $ts = mcp_rw_ts($m['taxaConfirmadaEm'] ?? null) ?? mcp_rw_ts($m['atualizadoEm'] ?? null) ?? mcp_rw_ts($m['criadoEm'] ?? null);
            $pagoTs = $ts === null ? $pagoTs : min($pagoTs ?? PHP_INT_MAX, $ts);
        }
        $pago = $pagas !== [];
        $criado = (int) mcp_rw_ts($u['criadoEm'] ?? null);
        $abertaUsuario = !$pago && ($u['papel'] ?? 'ALUNO') === 'ALUNO' && $criado >= $inicio;
        $base = [
            'origem' => 'escola', 'cpf' => mcp_rw_cpf($u['cpfCnpj'] ?? ''), 'email' => mcp_rw_email($u['email'] ?? ''),
            'numero' => mcp_whatsapp_numero((string) ($u['celular'] ?? '')), 'nome' => trim((string) ($u['nome'] ?? '')),
            'pago' => $pago, 'pago_ts' => $pago ? ($pagoTs ?? $criado) : null,
        ];
        $saida[] = $base + ['ref' => 'u' . $u['id'], 'curso_slug' => '', 'curso_nome' => MCP_RW_CURSO_GENERICO, 'quando' => $criado, 'aberta' => $abertaUsuario];
        foreach ($matriculas as $m) {
            $curso = is_array($m['turma']['curso'] ?? null) ? $m['turma']['curso'] : [];
            $uuid = mb_strtolower((string) ($curso['id'] ?? $m['turma']['cursoId'] ?? ''));
            $quando = (int) (mcp_rw_ts($m['criadoEm'] ?? null) ?? $criado);
            $saida[] = $base + [
                'ref' => 'm' . (string) ($m['id'] ?? ''), 'curso_slug' => $slugsPorUuid[$uuid] ?? '',
                'curso_nome' => trim((string) ($curso['nome'] ?? '')) ?: MCP_RW_CURSO_GENERICO, 'quando' => $quando,
                'aberta' => $abertaUsuario && $quando >= $inicio,
            ];
        }
    }
    return $saida;
}

// ----------------------------------------------------------------------------- pessoas (puro)
/** As chaves de uma tentativa ("c:", "e:", "t:"), que ligam as tentativas da mesma pessoa. */
function mcp_rw_ids(array $t): array
{
    return array_values(array_filter([
        $t['cpf'] !== '' ? 'c:' . $t['cpf'] : '', $t['email'] !== '' ? 'e:' . $t['email'] : '', $t['numero'] !== null ? 't:' . $t['numero'] : '',
    ]));
}

/**
 * Junta as tentativas em pessoas (union-find pelas chaves) e diz de cada uma: se pagou (e quando, a 1ª vez depois de
 * qualquer instante), se está no fluxo e por quê não, a âncora, o curso e o celular. Sem banco nem rede.
 *
 * @param callable $bloqueado fn(string $numero55): bool — mcp_avisos_bloqueios, canal whatsapp
 * @return array pessoas, da âncora mais recente para a mais antiga
 */
function mcp_rw_agrupar(array $tentativas, callable $bloqueado): array
{
    $tentativas = array_values(array_filter($tentativas, static fn(array $t): bool => !($t['cpf'] !== '' && mcp_cpf_de_teste($t['cpf']))
        && !mcp_rw_curso_de_teste($t['curso_slug'], $t['curso_nome'] === MCP_RW_CURSO_GENERICO ? '' : $t['curso_nome'])));
    $pai = array_keys($tentativas);
    $raiz = static function (int $i) use (&$pai): int {
        while ($pai[$i] !== $i) {
            $pai[$i] = $pai[$pai[$i]];
            $i = $pai[$i];
        }
        return $i;
    };
    $dono = [];
    foreach ($tentativas as $i => $t) {
        foreach (mcp_rw_ids($t) as $id) {
            if (!isset($dono[$id])) {
                $dono[$id] = $i;
                continue;
            }
            $a = $raiz($i);
            $b = $raiz($dono[$id]);
            if ($a !== $b) {
                $pai[$a] = $b;
            }
        }
    }
    $grupos = [];
    foreach ($tentativas as $i => $t) {
        $grupos[$raiz($i)][] = $t;
    }

    $pessoas = [];
    foreach ($grupos as $grupo) {
        $ids = [];
        $pagamentos = [];
        foreach ($grupo as $t) {
            foreach (mcp_rw_ids($t) as $id) {
                $ids[$id] = true;
            }
            if ($t['pago']) {
                $pagamentos[] = (int) $t['pago_ts'];
            }
        }
        sort($pagamentos);
        $abertas = array_values(array_filter($grupo, static fn(array $t): bool => $t['aberta']));
        // A mais recente primeiro; no empate, a que tem curso (a matrícula vem junto com o cadastro na escola).
        $ordenar = static fn(array $a, array $b): int => [$b['quando'], $b['curso_nome'] !== MCP_RW_CURSO_GENERICO, $b['ref']]
            <=> [$a['quando'], $a['curso_nome'] !== MCP_RW_CURSO_GENERICO, $a['ref']];
        usort($abertas, $ordenar);
        $base = $abertas;
        if (!$base) {
            $base = $grupo;
            usort($base, $ordenar);
        }
        $recente = $base[0];
        // O celular é o da tentativa mais recente; sem ele, o de outra tentativa com o MESMO CPF ou e-mail: o grupo pode
        // juntar duas pessoas que dividem um e-mail ou celular, e o nome e o curso de uma não podem ir para o celular da outra.
        $numero = $recente['numero'];
        if ($numero === null) {
            foreach ($base as $t) {
                $mesma = ($recente['cpf'] !== '' && $t['cpf'] === $recente['cpf']) || ($recente['email'] !== '' && $t['email'] === $recente['email']);
                if ($mesma && $t['numero'] !== null) {
                    $numero = $t['numero'];
                    break;
                }
            }
        }
        $principal = $recente['cpf'] !== '' ? 'c:' . $recente['cpf'] : ($recente['email'] !== '' ? 'e:' . $recente['email'] : ($numero !== null ? 't:' . $numero : ''));
        if ($principal === '') {
            continue; // sem nenhum identificador: não há como saber se é a mesma pessoa de outra tentativa
        }
        $motivo = match (true) {
            $pagamentos !== [] => 'pagou',
            $abertas === [] => 'fora_da_janela',
            $numero === null => 'sem_celular',
            $bloqueado($numero) => 'bloqueou',
            default => null,
        };
        $origens = array_values(array_unique(array_map(static fn(array $t): string => $t['origem'], $abertas ?: $grupo)));
        sort($origens);
        $pessoas[] = [
            'principal' => $principal,
            'ids' => array_keys($ids),
            'origem' => $recente['origem'],
            'origens' => $origens,
            'nome' => $recente['nome'],
            'primeiro_nome' => mcp_rw_primeiro_nome($recente['nome']),
            'numero' => $numero,
            'cpf' => $recente['cpf'],
            'emails' => array_values(array_unique(array_filter(array_map(static fn(array $t): string => $t['email'], $grupo)))),
            'numeros' => array_values(array_unique(array_filter(array_map(static fn(array $t): ?string => $t['numero'], $grupo)))),
            'cpfs' => array_values(array_unique(array_filter(array_map(static fn(array $t): string => $t['cpf'], $grupo)))),
            'curso_slug' => $recente['curso_slug'],
            'curso_nome' => $recente['curso_nome'] !== '' ? $recente['curso_nome'] : MCP_RW_CURSO_GENERICO,
            'ancora' => $abertas ? (int) $abertas[0]['quando'] : null,
            'pagamentos' => $pagamentos,
            'no_fluxo' => $motivo === null,
            'motivo' => $motivo,
        ];
    }
    usort($pessoas, static fn(array $a, array $b): int => [$b['ancora'] ?? 0, $b['principal']] <=> [$a['ancora'] ?? 0, $a['principal']]);
    return $pessoas;
}

/** Hora de Brasília: das 8h às 20h? */
function mcp_rw_no_horario(int $agora): bool
{
    $hora = (int) gmdate('G', $agora + MCP_RW_FUSO_SEGUNDOS);
    return $hora >= MCP_RW_HORARIO[0] && $hora < MCP_RW_HORARIO[1];
}

/** O primeiro instante a partir de $ts dentro do horário (8h–20h de Brasília). */
function mcp_rw_ajustar_horario(int $ts): int
{
    if (mcp_rw_no_horario($ts)) {
        return $ts;
    }
    $local = $ts + MCP_RW_FUSO_SEGUNDOS;
    $dia = intdiv($local, 86400) * 86400;
    $abre = $dia + MCP_RW_HORARIO[0] * 3600;
    return ($local < $abre ? $abre : $abre + 86400) - MCP_RW_FUSO_SEGUNDOS;
}

/**
 * A próxima etapa de uma pessoa e quando ela fica devida (sem o horário), ou null quando não há mais nada a mandar.
 * $enviadas: etapa => ['status', 'entrega', 'criado_ts'] (as linhas da pessoa em mcp_recuperacao_whatsapp).
 * Etapa 2 e 3 só depois da anterior ENVIADA (e não recusada na entrega), e com o intervalo da régua desde ela.
 * @return array{etapa: int, devida_em: int}|null
 */
function mcp_rw_proxima(int $ancora, array $enviadas): ?array
{
    foreach (MCP_RW_ETAPAS as $etapa => $depois) {
        if (isset($enviadas[$etapa])) {
            continue;
        }
        if ($etapa === 1) {
            return ['etapa' => 1, 'devida_em' => $ancora + $depois];
        }
        $anterior = $enviadas[$etapa - 1] ?? null;
        if ($anterior === null || $anterior['status'] !== 'enviado' || ($anterior['entrega'] ?? null) === 'falhou') {
            return null;
        }
        return ['etapa' => $etapa, 'devida_em' => max($ancora + $depois, (int) $anterior['criado_ts'] + $depois - MCP_RW_ETAPAS[$etapa - 1])];
    }
    return null;
}

// ----------------------------------------------------------------------------- mensagem (puro)
/** O parâmetro do botão (caminho sem a barra inicial; a base https://cruzvermelhariodejaneiro.org/ está no modelo). */
function mcp_rw_caminho_botao(string $slug, int $etapa): string
{
    $utm = 'utm_source=whatsapp&utm_medium=recuperacao&utm_campaign=rec' . $etapa;
    return $slug !== '' && mcp_curso($slug) !== null
        ? 'matricula-cursos-presenciais/checkout/?curso=' . rawurlencode($slug) . '&' . $utm
        : 'matricula-cursos-presenciais/?' . $utm;
}

/** Texto de parâmetro: a Meta recusa quebra de linha, tabulação e mais de 4 espaços seguidos. */
function mcp_rw_parametro(string $texto, int $limite = 60): string
{
    return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $texto)), 0, $limite);
}

/**
 * O corpo do POST /{numero}/messages: modelo rec_inscricao_<etapa> em pt_BR, {{1}} = primeiro nome, {{2}} = curso,
 * botão de URL (índice 0) com o caminho. O "Não tenho interesse" do rec_inscricao_3 é resposta rápida fixa (índice 1):
 * não leva parâmetro, então não entra nos componentes.
 */
function mcp_rw_corpo_envio(string $numero, int $etapa, string $primeiroNome, string $cursoNome, string $caminho): array
{
    return [
        'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $numero, 'type' => 'template',
        'template' => [
            'name' => MCP_RW_MODELOS[$etapa], 'language' => ['code' => 'pt_BR'],
            'components' => [
                ['type' => 'body', 'parameters' => [
                    ['type' => 'text', 'text' => mcp_rw_parametro($primeiroNome !== '' ? $primeiroNome : 'tudo bem')],
                    ['type' => 'text', 'text' => mcp_rw_parametro($cursoNome !== '' ? $cursoNome : MCP_RW_CURSO_GENERICO, 160)],
                ]],
                ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $caminho]]],
            ],
        ],
    ];
}

/** Resposta que pede para parar: "Não tenho interesse" (o botão), SAIR, PARAR ou STOP. */
function mcp_rw_quer_parar(?string $texto): bool
{
    $t = mb_strtolower((string) $texto);
    $t = strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
    $t = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z\s]+/', ' ', $t)));
    return in_array($t, MCP_RW_PALAVRAS_PARAR, true);
}

// ----------------------------------------------------------------------------- Kapso
/**
 * A configuração da Kapso, ou o que falta. WHATSAPP_KAPSO_BASE só existe para o teste local (servidor falso em
 * 127.0.0.1); em produção fica vazia e vale https://api.kapso.ai.
 * @return array{ok: bool, falta: string[], chave: string, numero_id: string, base: string}
 */
function mcp_rw_kapso_config(): array
{
    $chave = trim((string) mcp_cfg('WHATSAPP_KAPSO_CHAVE', ''));
    $numero = trim((string) mcp_cfg('WHATSAPP_KAPSO_NUMERO_ID', ''));
    $falta = [];
    if (strlen($chave) < 8) {
        $falta[] = 'WHATSAPP_KAPSO_CHAVE';
    }
    if (!preg_match('/^\d{5,30}$/', $numero)) {
        $falta[] = 'WHATSAPP_KAPSO_NUMERO_ID';
    }
    $base = rtrim((string) mcp_cfg('WHATSAPP_KAPSO_BASE', 'https://api.kapso.ai'), '/');
    return ['ok' => $falta === [], 'falta' => $falta, 'chave' => $chave, 'numero_id' => $numero, 'base' => $base];
}

/**
 * Chama a Kapso com o cabeçalho X-API-Key. HTTPS sempre; HTTP só em 127.0.0.1 (teste). Devolve [status, corpo
 * decodificado, erro legível sem a chave, tempo esgotado depois de conectar].
 */
function mcp_rw_kapso_pedir(array $cfg, string $metodo, string $url, ?array $corpo = null, int $tempo = MCP_RW_KAPSO_TEMPO_S): array
{
    $ch = curl_init($url);
    $opcoes = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'X-API-Key: ' . $cfg['chave']],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => max(1, $tempo),
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true) ? CURLPROTO_HTTP : 0),
    ];
    if ($corpo !== null) {
        $opcoes[CURLOPT_POSTFIELDS] = (string) json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opcoes);
    $resposta = curl_exec($ch);
    $status = $resposta === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erroCurl = curl_error($ch);
    $esgotado = curl_errno($ch) === CURLE_OPERATION_TIMEDOUT && (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME) > 0;
    curl_close($ch);
    $dados = is_string($resposta) ? json_decode($resposta, true) : null;
    $dados = is_array($dados) ? $dados : [];
    $erro = null;
    if ($status < 200 || $status >= 300 || isset($dados['error'])) {
        $e = $dados['error'] ?? null;
        $mensagem = is_array($e) ? ($e['error_data']['details'] ?? $e['message'] ?? null) : $e;
        $codigo = is_array($e) ? (int) ($e['code'] ?? 0) : 0;
        $erro = match (true) {
            $status === 0 => 'sem resposta da Kapso' . ($erroCurl !== '' ? " ($erroCurl)" : ''),
            $status === 401 || $status === 403 => 'a Kapso recusou a chave (confira WHATSAPP_KAPSO_CHAVE)',
            $status === 404 && !is_string($mensagem) => 'a Kapso não achou o número (confira WHATSAPP_KAPSO_NUMERO_ID)',
            default => ($codigo ? "#$codigo " : '') . (is_string($mensagem) && $mensagem !== '' ? $mensagem : "HTTP $status"),
        };
        // A chave nunca sai daqui, nem dentro de uma mensagem de erro.
        $erro = mb_substr(str_replace($cfg['chave'], '[chave]', $erro), 0, 250);
    }
    return [$status, $dados, $erro, $esgotado];
}

/**
 * Manda a mensagem de uma etapa. transitorio = a Kapso ou a Meta estão fora ou recusam a configuração (tenta na
 * próxima rodada, sem gastar a etapa); incerto = o tempo acabou depois de o pedido chegar (pode ter saído: não repete).
 * @return array{ok: bool, id: ?string, erro: ?string, transitorio: bool, incerto: bool}
 */
function mcp_rw_kapso_enviar(array $cfg, array $corpo): array
{
    $url = $cfg['base'] . '/meta/whatsapp/v24.0/' . rawurlencode($cfg['numero_id']) . '/messages';
    [$status, $dados, $erro, $esgotado] = mcp_rw_kapso_pedir($cfg, 'POST', $url, $corpo, 30);
    if ($esgotado) {
        return ['ok' => true, 'id' => null, 'erro' => 'a Kapso não confirmou a tempo; a mensagem pode ter saído (não vai de novo)', 'transitorio' => false, 'incerto' => true];
    }
    $id = $dados['messages'][0]['id'] ?? null;
    if ($erro === null && is_string($id)) {
        return ['ok' => true, 'id' => mb_substr($id, 0, 128), 'erro' => null, 'transitorio' => false, 'incerto' => false];
    }
    $codigo = (int) ($dados['error']['code'] ?? 0);
    // Fora do ar, chave ou número recusados, limite (429), créditos (402) ou o token da Meta caído (190): espera.
    $transitorio = $status === 0 || $status === 401 || $status === 402 || $status === 403 || $status === 429 || $status >= 500 || $codigo === 190
        || ($status === 404 && $codigo === 0);
    return ['ok' => false, 'id' => null, 'erro' => $erro ?? 'resposta sem o id da mensagem', 'transitorio' => $transitorio, 'incerto' => false];
}

/** Guarda um valor curto em mcp_chaves (o cache do painel: modelos, WABA, webhook). */
function mcp_rw_cache(string $nome, ?array $valor = null, ?int $agora = null): ?array
{
    $agora ??= time();
    $db = mcp_db();
    if ($valor !== null) {
        $texto = (string) json_encode(['em' => $agora] + $valor, JSON_UNESCAPED_SLASHES);
        if (strlen($texto) <= 128) {
            $db->prepare('INSERT INTO mcp_chaves (nome, valor, criado_em) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor), criado_em = VALUES(criado_em)')
                ->execute([$nome, $texto, gmdate('Y-m-d H:i:s', $agora)]);
        }
        return $valor;
    }
    $stmt = $db->prepare('SELECT valor FROM mcp_chaves WHERE nome = ?');
    $stmt->execute([$nome]);
    $lido = json_decode((string) $stmt->fetchColumn(), true);
    return is_array($lido) && (int) ($lido['em'] ?? 0) > $agora - MCP_RW_CACHE_S ? $lido : null;
}

function mcp_rw_cache_apagar(string $nome): void
{
    mcp_db()->prepare('DELETE FROM mcp_chaves WHERE nome = ?')->execute([$nome]);
}

/**
 * A situação dos três modelos na Meta: nome => 'APPROVED', outra situação, ou 'ausente'. O WABA vem do número pela
 * API da plataforma (no número gerenciado pela Kapso, o proxy da Meta não responde o GET do número). Cache de 10 min.
 * @return array{ok: bool, modelos: array<string, string>, erro: ?string}
 */
function mcp_rw_modelos(array $cfg, ?int $agora = null): array
{
    $cache = mcp_rw_cache('rw_cache_modelos', null, $agora);
    if ($cache !== null && is_array($cache['m'] ?? null)) {
        return ['ok' => true, 'modelos' => array_combine(MCP_RW_MODELOS, array_map(static fn($s): string => (string) $s, $cache['m'])), 'erro' => null];
    }
    [$status, $numero, $erro] = mcp_rw_kapso_pedir($cfg, 'GET', $cfg['base'] . '/platform/v1/whatsapp/phone_numbers/' . rawurlencode($cfg['numero_id']), null, 10);
    $d = is_array($numero['data'] ?? null) ? $numero['data'] : [];
    $waba = null;
    foreach ([$d['business_account_id'] ?? null, $d['whatsapp_business_account_id'] ?? null, $d['waba_id'] ?? null, $d['business_account']['id'] ?? null] as $v) {
        if ((is_string($v) || is_int($v)) && preg_match('/^\d{5,30}$/', (string) $v)) {
            $waba = (string) $v;
            break;
        }
    }
    if ($waba === null) {
        return ['ok' => false, 'modelos' => [], 'erro' => $erro ?? 'a Kapso não disse a conta (WABA) do número'];
    }
    $situacao = array_fill_keys(MCP_RW_MODELOS, 'ausente');
    $depois = null;
    for ($pagina = 0; $pagina < 5; $pagina++) {
        $url = $cfg['base'] . '/meta/whatsapp/v24.0/' . rawurlencode($waba) . '/message_templates?fields=name,status,language&limit=100' . ($depois ? '&after=' . rawurlencode($depois) : '');
        [$status, $lista, $erro] = mcp_rw_kapso_pedir($cfg, 'GET', $url, null, 10);
        if ($erro !== null) {
            return ['ok' => false, 'modelos' => [], 'erro' => $erro];
        }
        foreach (is_array($lista['data'] ?? null) ? $lista['data'] : [] as $m) {
            $nome = (string) ($m['name'] ?? '');
            if (isset($situacao[$nome]) && ($m['language'] ?? 'pt_BR') === 'pt_BR' && $situacao[$nome] !== 'APPROVED') {
                $situacao[$nome] = mb_substr(strtoupper((string) ($m['status'] ?? '')), 0, 12) ?: 'ausente';
            }
        }
        $depois = is_string($lista['paging']['cursors']['after'] ?? null) && !empty($lista['paging']['next']) ? $lista['paging']['cursors']['after'] : null;
        if ($depois === null) {
            break;
        }
    }
    mcp_rw_cache('rw_cache_modelos', ['m' => array_values($situacao)], $agora);
    return ['ok' => true, 'modelos' => $situacao, 'erro' => null];
}

/** Endereço público do webhook (é para cá que a Kapso manda entrega, leitura e respostas). */
function mcp_rw_webhook_url(): string
{
    return mcp_site_url() . '/matricula-cursos-presenciais/api/kapso-webhook.php';
}

/** O webhook do número na Kapso para o nosso endereço: [lista|null, erro]. */
function mcp_rw_webhooks(array $cfg): array
{
    $url = $cfg['base'] . '/platform/v1/whatsapp/phone_numbers/' . rawurlencode($cfg['numero_id']) . '/webhooks?kind=kapso&per_page=50&url_contains=' . rawurlencode(mcp_rw_webhook_url());
    [, $dados, $erro] = mcp_rw_kapso_pedir($cfg, 'GET', $url, null, 10);
    if ($erro !== null) {
        return [null, $erro];
    }
    $lista = [];
    foreach (is_array($dados['data'] ?? null) ? $dados['data'] : [] as $w) {
        if (is_array($w) && isset($w['id']) && (string) ($w['url'] ?? '') === mcp_rw_webhook_url()) {
            $lista[] = ['id' => (string) $w['id'], 'ativo' => ($w['active'] ?? true) !== false, 'eventos' => array_values(array_filter((array) ($w['events'] ?? []), 'is_string'))];
        }
    }
    return [$lista, null];
}

/** 'ligado', 'desligado' ou 'desconhecido' (Kapso sem resposta). Cache de 10 min, apagado ao ligar. */
function mcp_rw_webhook_situacao(array $cfg, ?int $agora = null): string
{
    $cache = mcp_rw_cache('rw_cache_webhook', null, $agora);
    if ($cache !== null && in_array($cache['s'] ?? '', ['ligado', 'desligado'], true)) {
        return $cache['s'];
    }
    [$lista] = mcp_rw_webhooks($cfg);
    if ($lista === null) {
        return 'desconhecido';
    }
    $ligado = false;
    foreach ($lista as $w) {
        $ligado = $ligado || ($w['ativo'] && in_array('whatsapp.message.received', $w['eventos'], true));
    }
    $s = $ligado ? 'ligado' : 'desligado';
    mcp_rw_cache('rw_cache_webhook', ['s' => $s], $agora);
    return $s;
}

/**
 * Cria (ou acerta, se já existe um para este endereço) o webhook do número na Kapso, assinado com o segredo do site
 * (mcp_segredo('kapso_webhook')). Devolve null ou o erro.
 */
function mcp_rw_webhook_ligar(array $cfg): ?string
{
    [$lista, $erro] = mcp_rw_webhooks($cfg);
    if ($lista === null) {
        return $erro;
    }
    $corpo = ['whatsapp_webhook' => ['kind' => 'kapso', 'url' => mcp_rw_webhook_url(), 'events' => MCP_RW_EVENTOS_WEBHOOK, 'active' => true,
        'payload_version' => 'v2', 'secret_key' => mcp_segredo('kapso_webhook')]];
    $base = $cfg['base'] . '/platform/v1/whatsapp/phone_numbers/' . rawurlencode($cfg['numero_id']) . '/webhooks';
    [, , $erro] = $lista
        ? mcp_rw_kapso_pedir($cfg, 'PATCH', $base . '/' . rawurlencode($lista[0]['id']), $corpo, 15)
        : mcp_rw_kapso_pedir($cfg, 'POST', $base, $corpo, 15);
    mcp_rw_cache_apagar('rw_cache_webhook');
    return $erro;
}

// ----------------------------------------------------------------------------- escola (PostgREST)
/** A base do PostgREST da escola (ESCOLA_API_URL sem /rpc/…), ou '' se a escola não está configurada. */
function mcp_rw_escola_base(): string
{
    $url = (string) mcp_cfg('ESCOLA_API_URL', '');
    if ($url === '' || (string) mcp_cfg('ESCOLA_API_TOKEN', '') === '') {
        return '';
    }
    return preg_match('~^(https?://.+?)/rpc/[A-Za-z0-9_]+/?$~', $url, $m) ? $m[1] : '';
}

/** GET no PostgREST da escola com a chave secreta. [status (0 = rede), dados ou null]. Nada do corpo vai para log. */
function mcp_rw_escola_get(string $url): array
{
    $chave = (string) mcp_cfg('ESCOLA_API_TOKEN', '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'apikey: ' . $chave, 'Authorization: Bearer ' . $chave],
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => MCP_RW_ESCOLA_TEMPO_S,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true) ? CURLPROTO_HTTP : 0),
    ]);
    $resposta = curl_exec($ch);
    $status = $resposta === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, is_string($resposta) ? json_decode($resposta, true) : null];
}

/** As colunas pedidas (com as matrículas, a turma e o curso embutidos). */
const MCP_RW_ESCOLA_SELECT = 'id,nome,email,cpfCnpj,celular,papel,criadoEm,'
    . 'matriculas:Matricula(id,criadoEm,atualizadoEm,taxaConfirmada,taxaConfirmadaEm,statusPagamento,turma:Turma(cursoId,curso:Curso(id,nome)))';

/** Valor de um filtro in.(…) do PostgREST, entre aspas (e-mail pode ter vírgula ou parêntese). */
function mcp_rw_escola_valor(string $v): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
}

/**
 * Lê da escola: os alunos criados desde $desde (paginado) e, para os $buscar (cpfs, emails, numeros) do site, os
 * usuários com esses dados, de qualquer época (é assim que se sabe se quem veio do site pagou na escola).
 * @return array{estado: string, erro: ?string, usuarios: array} estado: ok, desligada ou indisponivel
 */
function mcp_rw_escola_ler(int $desde, array $buscar): array
{
    $base = mcp_rw_escola_base();
    if ($base === '') {
        return ['estado' => 'desligada', 'erro' => null, 'usuarios' => []];
    }
    $porId = [];
    $falhou = static fn(int $status): array => ['estado' => 'indisponivel', 'erro' => $status === 0 ? 'sem resposta da escola' : "a escola respondeu HTTP $status", 'usuarios' => []];
    $desdeIso = gmdate('Y-m-d\TH:i:s', $desde);
    for ($pagina = 0; $pagina < MCP_RW_ESCOLA_PAGINAS; $pagina++) {
        $url = $base . '/Usuario?select=' . rawurlencode(MCP_RW_ESCOLA_SELECT) . '&papel=eq.ALUNO&criadoEm=gte.' . rawurlencode($desdeIso)
            . '&order=criadoEm.asc,id.asc&limit=' . MCP_RW_ESCOLA_PAGINA . '&offset=' . ($pagina * MCP_RW_ESCOLA_PAGINA);
        [$status, $dados] = mcp_rw_escola_get($url);
        if ($status !== 200 || !is_array($dados)) {
            return $falhou($status);
        }
        foreach ($dados as $u) {
            if (is_array($u) && is_string($u['id'] ?? null)) {
                $porId[$u['id']] = $u;
            }
        }
        if (count($dados) < MCP_RW_ESCOLA_PAGINA) {
            break;
        }
    }
    // Busca pelos dados de quem veio do site, em lotes (o endereço tem limite de tamanho).
    $filtros = [];
    foreach (array_chunk(array_values(array_unique($buscar['cpfs'] ?? [])), MCP_RW_ESCOLA_LOTE) as $lote) {
        $filtros[] = 'cpfCnpj.in.(' . implode(',', array_map('mcp_rw_escola_valor', $lote)) . ')';
    }
    foreach (array_chunk(array_values(array_unique($buscar['emails'] ?? [])), MCP_RW_ESCOLA_LOTE) as $lote) {
        $filtros[] = 'email.in.(' . implode(',', array_map('mcp_rw_escola_valor', $lote)) . ')';
    }
    $celulares = [];
    foreach (array_unique($buscar['numeros'] ?? []) as $n) {
        $celulares[] = substr($n, 2); // a escola guarda o celular só com DDD + número; às vezes com o 55
        $celulares[] = $n;
    }
    foreach (array_chunk($celulares, MCP_RW_ESCOLA_LOTE) as $lote) {
        $filtros[] = 'celular.in.(' . implode(',', array_map('mcp_rw_escola_valor', $lote)) . ')';
    }
    foreach ($filtros as $filtro) {
        [$status, $dados] = mcp_rw_escola_get($base . '/Usuario?select=' . rawurlencode(MCP_RW_ESCOLA_SELECT) . '&or=' . rawurlencode('(' . $filtro . ')') . '&limit=' . MCP_RW_ESCOLA_PAGINA);
        if ($status !== 200 || !is_array($dados)) {
            return $falhou($status);
        }
        foreach ($dados as $u) {
            if (is_array($u) && is_string($u['id'] ?? null)) {
                $porId[$u['id']] = $u;
            }
        }
    }
    return ['estado' => 'ok', 'erro' => null, 'usuarios' => array_values($porId)];
}

// ----------------------------------------------------------------------------- banco
/**
 * O público inteiro, com a situação de cada pessoa: lê o site (as tentativas desde o início das métricas e as pagas de
 * qualquer época), a escola e os bloqueios. A regra está em mcp_rw_agrupar.
 * @return array{pessoas: array, escola: array{estado: string, erro: ?string}}
 */
function mcp_rw_carregar(?int $agora = null): array
{
    $agora ??= time();
    $db = mcp_db();
    $inicio = $agora - MCP_RW_JANELA_DIAS * 86400;
    $inicioMetrica = $agora - MCP_RW_JANELA_METRICA_DIAS * 86400;
    $stmt = $db->prepare("SELECT id, cpf, email, telefone, nome, curso_slug, curso_nome, status, criado_em, pago_em, atualizado_em
        FROM mcp_inscricoes WHERE criado_em >= ? OR status IN ('pago', 'estornado')");
    $stmt->execute([gmdate('Y-m-d H:i:s', $inicioMetrica)]);
    $site = mcp_rw_tentativas_site($stmt->fetchAll(), $inicio);
    // A escola é perguntada pelos dados de quem veio do site e não pagou lá (os que pagaram já saem pelo site).
    $buscar = ['cpfs' => [], 'emails' => [], 'numeros' => []];
    foreach ($site as $t) {
        if (!$t['pago'] && $t['quando'] >= $inicioMetrica) {
            if ($t['cpf'] !== '') {
                $buscar['cpfs'][] = $t['cpf'];
            }
            if ($t['email'] !== '') {
                $buscar['emails'][] = $t['email'];
            }
            if ($t['numero'] !== null) {
                $buscar['numeros'][] = $t['numero'];
            }
        }
    }
    $escola = mcp_rw_escola_ler($inicioMetrica, $buscar);
    if ($escola['estado'] === 'indisponivel') {
        error_log('[matricula] recuperação por WhatsApp: escola indisponível (' . $escola['erro'] . '); a rodada segue só com o site');
    }
    $tentativas = array_merge($site, mcp_rw_tentativas_escola($escola['usuarios'], $inicio, mcp_rw_slugs_por_uuid()));
    $bloqueios = [];
    foreach ($db->query("SELECT destino_hash FROM mcp_avisos_bloqueios WHERE canal = 'whatsapp'") as $b) {
        $bloqueios[$b['destino_hash']] = true;
    }
    $pessoas = mcp_rw_agrupar($tentativas, static fn(string $numero): bool => isset($bloqueios[mcp_avisos_hash('whatsapp', $numero)]));
    return ['pessoas' => $pessoas, 'escola' => ['estado' => $escola['estado'], 'erro' => $escola['erro']]];
}

/** As chaves (HMAC) de uma pessoa: todas as dela, e a do celular para onde a mensagem vai. */
function mcp_rw_chaves(array $pessoa): array
{
    $chaves = array_map('mcp_rw_hmac', $pessoa['ids']);
    if ($pessoa['numero'] !== null) {
        $chaves[] = mcp_rw_hmac('t:' . $pessoa['numero']);
    }
    return array_values(array_unique($chaves));
}

/** Todas as linhas de mcp_recuperacao_whatsapp, por chave e por telefone_hash (para achar as etapas de cada pessoa). */
function mcp_rw_linhas(): array
{
    return mcp_db()->query('SELECT id, pessoa_chave, origem, etapa, curso_slug, curso_nome, telefone_hash, status, provedor_id, entrega, erro, resposta, criado_em, atualizado_em
        FROM mcp_recuperacao_whatsapp ORDER BY id')->fetchAll();
}

/** As linhas de uma pessoa (por qualquer chave dela ou pelo celular), com etapa => linha (a primeira de cada etapa). */
function mcp_rw_da_pessoa(array $pessoa, array $indice): array
{
    $minhas = [];
    foreach (mcp_rw_chaves($pessoa) as $k) {
        foreach ($indice[$k] ?? [] as $l) {
            $minhas[(int) $l['id']] = $l;
        }
    }
    ksort($minhas);
    $porEtapa = [];
    foreach ($minhas as $l) {
        $porEtapa[(int) $l['etapa']] ??= $l + ['criado_ts' => (int) mcp_rw_ts($l['criado_em'])];
    }
    return $porEtapa;
}

/** Índice das linhas por pessoa_chave e por telefone_hash. */
function mcp_rw_indice(array $linhas): array
{
    $indice = [];
    foreach ($linhas as $l) {
        $indice[$l['pessoa_chave']][] = $l;
        if ($l['telefone_hash'] !== $l['pessoa_chave']) {
            $indice[$l['telefone_hash']][] = $l;
        }
    }
    return $indice;
}

/**
 * Confere de novo, no banco, logo antes do envio: pagou no site (pago ou estornado, por CPF, e-mail ou celular) ou
 * bloqueou o WhatsApp? Devolve o motivo para não mandar, ou null.
 */
function mcp_rw_reconferir(array $pessoa): ?string
{
    if ($pessoa['numero'] === null || mcp_avisos_bloqueado('whatsapp', $pessoa['numero'])) {
        return 'bloqueou';
    }
    $condicoes = [];
    $valores = [];
    if ($pessoa['cpfs']) {
        $condicoes[] = 'cpf IN (' . implode(',', array_fill(0, count($pessoa['cpfs']), '?')) . ')';
        array_push($valores, ...$pessoa['cpfs']);
    }
    if ($pessoa['emails']) {
        $condicoes[] = 'LOWER(email) IN (' . implode(',', array_fill(0, count($pessoa['emails']), '?')) . ')';
        array_push($valores, ...$pessoa['emails']);
    }
    $telefones = [];
    foreach ($pessoa['numeros'] as $n) {
        $local = substr($n, 2);
        array_push($telefones, $local, $n, substr($local, 0, 2) . substr($local, 3)); // com e sem o nono dígito, com e sem 55
    }
    if ($telefones) {
        $condicoes[] = "REGEXP_REPLACE(telefone, '[^0-9]', '') IN (" . implode(',', array_fill(0, count($telefones), '?')) . ')';
        array_push($valores, ...$telefones);
    }
    if (!$condicoes) {
        return null;
    }
    $stmt = mcp_db()->prepare("SELECT 1 FROM mcp_inscricoes WHERE status IN ('pago', 'estornado') AND (" . implode(' OR ', $condicoes) . ') LIMIT 1');
    $stmt->execute($valores);
    return $stmt->fetchColumn() ? 'pagou' : null;
}

/**
 * Uma rodada (api/lembretes.php, de hora em hora): manda as etapas devidas, até MCP_RW_POR_RODADA envios. Nunca lança
 * por causa da Kapso nem da escola; erro de banco sobe para quem chama (lembretes.php registra e segue).
 * @return array{enviados: int, falhas: int, pulados: int, vistos: int, escola: string, motivo: ?string}
 */
function mcp_rw_rodar(?int $agora = null): array
{
    $agora ??= time();
    $r = ['enviados' => 0, 'falhas' => 0, 'pulados' => 0, 'vistos' => 0, 'escola' => '-', 'motivo' => null];
    $cfg = mcp_rw_kapso_config();
    if (!$cfg['ok']) {
        $r['motivo'] = 'sem configuração (' . implode(', ', $cfg['falta']) . ')';
        return $r;
    }
    if (!mcp_rw_no_horario($agora)) {
        $r['motivo'] = 'fora do horário (8h–20h de Brasília)';
        return $r;
    }
    $carga = mcp_rw_carregar($agora);
    $r['escola'] = $carga['escola']['estado'];
    $indice = mcp_rw_indice(mcp_rw_linhas());
    // As devidas, da mais atrasada para a mais nova.
    $fila = [];
    foreach ($carga['pessoas'] as $p) {
        if (!$p['no_fluxo']) {
            continue;
        }
        $r['vistos']++;
        $proxima = mcp_rw_proxima((int) $p['ancora'], mcp_rw_da_pessoa($p, $indice));
        if ($proxima !== null && $proxima['devida_em'] <= $agora) {
            $fila[] = [$proxima['devida_em'], $proxima['etapa'], $p];
        }
    }
    usort($fila, static fn(array $a, array $b): int => [$a[0], $a[2]['principal']] <=> [$b[0], $b[2]['principal']]);
    $db = mcp_db();
    foreach ($fila as [, $etapa, $p]) {
        if ($r['enviados'] + $r['falhas'] >= MCP_RW_POR_RODADA) {
            break;
        }
        if (mcp_rw_reconferir($p) !== null) {
            $r['pulados']++;
            continue;
        }
        $chave = mcp_rw_hmac($p['principal']);
        $telefone = mcp_rw_hmac('t:' . $p['numero']);
        // A linha é reservada ANTES do envio: a UNIQUE(pessoa_chave, etapa) garante que só um processo manda esta etapa.
        $quando = gmdate('Y-m-d H:i:s', $agora);
        $reserva = $db->prepare("INSERT IGNORE INTO mcp_recuperacao_whatsapp (pessoa_chave, origem, etapa, curso_slug, curso_nome, telefone_hash, status, erro, criado_em, atualizado_em)
            VALUES (?, ?, ?, ?, ?, ?, 'falhou', 'enviando', ?, ?)");
        $reserva->execute([$chave, $p['origem'], $etapa, $p['curso_slug'] !== '' ? mb_substr($p['curso_slug'], 0, 80) : null, mb_substr($p['curso_nome'], 0, 160), $telefone, $quando, $quando]);
        if ($reserva->rowCount() !== 1) {
            $r['pulados']++;
            continue;
        }
        $id = (int) $db->lastInsertId();
        $corpo = mcp_rw_corpo_envio((string) $p['numero'], $etapa, $p['primeiro_nome'], $p['curso_nome'], mcp_rw_caminho_botao($p['curso_slug'], $etapa));
        $envio = mcp_rw_kapso_enviar($cfg, $corpo);
        if (!$envio['ok'] && $envio['transitorio']) {
            // A Kapso (ou a Meta) não está aceitando agora: a etapa não foi gasta e o resto espera a próxima rodada.
            $db->prepare('DELETE FROM mcp_recuperacao_whatsapp WHERE id = ?')->execute([$id]);
            $r['falhas']++;
            $r['motivo'] = 'Kapso: ' . $envio['erro'];
            error_log('[matricula] recuperação por WhatsApp parada nesta rodada: ' . $envio['erro']);
            break;
        }
        $db->prepare('UPDATE mcp_recuperacao_whatsapp SET status = ?, provedor_id = ?, erro = ?, atualizado_em = ? WHERE id = ?')
            ->execute([$envio['ok'] ? 'enviado' : 'falhou', $envio['id'], $envio['erro'] !== null ? mb_substr($envio['erro'], 0, 255) : null, gmdate('Y-m-d H:i:s', $agora), $id]);
        $envio['ok'] ? $r['enviados']++ : $r['falhas']++;
        // A linha nova entra no índice: se a mesma pessoa aparecesse de novo nesta rodada, não receberia outra etapa.
        foreach ([$chave, $telefone] as $k) {
            $indice[$k][] = ['id' => $id, 'etapa' => $etapa, 'status' => $envio['ok'] ? 'enviado' : 'falhou', 'entrega' => null, 'criado_em' => $quando];
        }
    }
    return $r;
}

// ----------------------------------------------------------------------------- webhook da Kapso (puro + banco)
/** A assinatura (HMAC-SHA256 hex do corpo cru, com ou sem "sha256=") confere com o segredo do site? */
function mcp_rw_assinatura_ok(string $corpo, string $cabecalho): bool
{
    $recebida = strtolower(trim($cabecalho));
    $recebida = str_starts_with($recebida, 'sha256=') ? substr($recebida, 7) : $recebida;
    return $recebida !== '' && hash_equals(hash_hmac('sha256', $corpo, mcp_segredo('kapso_webhook')), $recebida);
}

/**
 * Lê o payload v2 da Kapso (avulso ou em lote: {"batch": true, "data": [...]}) em eventos simples, sem tocar no banco:
 *  - ['tipo' => 'entrega', 'id' => wamid, 'entrega' => entregue|lida|falhou, 'erro' => ?string]
 *  - ['tipo' => 'resposta', 'de' => '55…', 'texto' => …, 'citada' => ?wamid, 'ts' => ?int]
 * O evento vem do cabeçalho X-Webhook-Event (ou do corpo). Mensagens de outro número do projeto ficam de fora.
 */
function mcp_rw_ler_webhook(array $corpo, string $eventoCabecalho, string $numeroId): array
{
    $evento = strtolower(trim($eventoCabecalho !== '' ? $eventoCabecalho : (string) ($corpo['type'] ?? $corpo['event'] ?? '')));
    $itens = ($corpo['batch'] ?? false) === true && is_array($corpo['data'] ?? null) ? $corpo['data'] : [$corpo];
    $saida = [];
    foreach ($itens as $item) {
        if (!is_array($item) || !is_array($item['message'] ?? null)) {
            continue;
        }
        $m = $item['message'];
        $doNumero = (string) ($item['phone_number_id'] ?? $item['conversation']['phone_number_id'] ?? '');
        if ($doNumero !== '' && $numeroId !== '' && $doNumero !== $numeroId) {
            continue;
        }
        $kapso = is_array($m['kapso'] ?? null) ? $m['kapso'] : [];
        $situacao = match ($evento) {
            'whatsapp.message.delivered' => 'delivered', 'whatsapp.message.read' => 'read', 'whatsapp.message.failed' => 'failed',
            default => $evento === 'whatsapp.message.received' ? '' : strtolower((string) ($kapso['status'] ?? '')),
        };
        if (in_array($situacao, ['delivered', 'read', 'failed'], true) && is_string($m['id'] ?? null)) {
            $erro = $m['errors'][0] ?? $kapso['errors'][0] ?? $kapso['error'] ?? null;
            $saida[] = ['tipo' => 'entrega', 'id' => mb_substr($m['id'], 0, 128), 'entrega' => ['delivered' => 'entregue', 'read' => 'lida', 'failed' => 'falhou'][$situacao],
                'erro' => $situacao === 'failed' ? mb_substr(is_array($erro) ? trim(((isset($erro['code']) ? '#' . $erro['code'] . ' ' : '') . (string) ($erro['title'] ?? $erro['message'] ?? ''))) : (string) ($erro ?? 'falhou no WhatsApp'), 0, 250) : null];
            continue;
        }
        if ($evento !== 'whatsapp.message.received' || ($kapso['direction'] ?? '') === 'outbound') {
            continue;
        }
        $de = mcp_digitos((string) ($m['from'] ?? $item['conversation']['phone_number'] ?? ''));
        $texto = $m['text']['body'] ?? $m['button']['text'] ?? $m['button']['payload'] ?? $m['interactive']['button_reply']['title'] ?? null;
        if ($de === '' || !is_string($texto) || trim($texto) === '') {
            continue;
        }
        $ts = $m['timestamp'] ?? null;
        $saida[] = ['tipo' => 'resposta', 'de' => $de, 'texto' => mcp_texto($texto, 200), 'citada' => is_string($m['context']['id'] ?? null) ? $m['context']['id'] : null,
            'ts' => is_numeric($ts) ? (int) $ts : null];
    }
    return $saida;
}

/**
 * Aplica os eventos: entrega/leitura pelo wamid (a situação só avança; "falhou" vale sempre), e respostas — guardadas
 * na linha da mensagem respondida (ou na mais recente para aquele celular). "Não tenho interesse", SAIR, PARAR ou STOP
 * gravam o bloqueio do WhatsApp (mcp_avisos_bloqueios, origem 'recuperacao'): a pessoa sai do fluxo e dos avisos.
 * Devolve quantos eventos mudaram alguma coisa.
 */
function mcp_rw_webhook_tratar(array $eventos, ?int $agora = null): int
{
    $agora ??= time();
    $db = mcp_db();
    $quando = gmdate('Y-m-d H:i:s', $agora);
    $n = 0;
    foreach ($eventos as $e) {
        if ($e['tipo'] === 'entrega') {
            $ordem = "FIELD(entrega, 'entregue', 'lida')";
            $stmt = $db->prepare("UPDATE mcp_recuperacao_whatsapp SET entrega = ?, erro = COALESCE(?, erro), atualizado_em = ?
                WHERE provedor_id = ? AND (entrega IS NULL OR ? = 'falhou' OR (entrega <> 'falhou' AND $ordem < FIELD(?, 'entregue', 'lida')))");
            $stmt->execute([$e['entrega'], $e['erro'], $quando, $e['id'], $e['entrega'], $e['entrega']]);
            $n += $stmt->rowCount();
            continue;
        }
        // Resposta reenviada de muito tempo atrás (a assinatura não tem data): um "parar" velho não bloqueia de novo.
        if ($e['ts'] !== null && $e['ts'] < $agora - 86400) {
            continue;
        }
        $numero = str_starts_with($e['de'], '55') ? (mcp_whatsapp_numero(substr($e['de'], 2)) ?? $e['de']) : $e['de'];
        $parar = mcp_rw_quer_parar($e['texto']);
        $linha = null;
        if ($e['citada'] !== null) {
            $stmt = $db->prepare('SELECT id, resposta FROM mcp_recuperacao_whatsapp WHERE provedor_id = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$e['citada']]);
            $linha = $stmt->fetch() ?: null;
        }
        if ($linha === null) {
            $stmt = $db->prepare('SELECT id, resposta FROM mcp_recuperacao_whatsapp WHERE telefone_hash = ? AND criado_em >= ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([mcp_rw_hmac('t:' . $numero), gmdate('Y-m-d H:i:s', $agora - 30 * 86400)]);
            $linha = $stmt->fetch() ?: null;
        }
        if ($parar) {
            mcp_avisos_bloquear_hash('whatsapp', mcp_avisos_hash('whatsapp', $numero), 'recuperacao');
            $n++;
        }
        // Um "não tenho interesse" já guardado não é coberto por um "ok" depois: é ele que conta como "não quer".
        if ($linha !== null && ($parar || !mcp_rw_quer_parar((string) $linha['resposta']))) {
            $db->prepare('UPDATE mcp_recuperacao_whatsapp SET resposta = ?, atualizado_em = ? WHERE id = ?')->execute([$e['texto'], $quando, (int) $linha['id']]);
            $n++;
        }
    }
    return $n;
}

// ----------------------------------------------------------------------------- métricas (puro)
/**
 * Os números do painel, sem dado pessoal. $linhas: todas de mcp_recuperacao_whatsapp; $pessoas: de mcp_rw_carregar.
 * Recuperada = pessoa que recebeu a 1ª mensagem e pagou (em qualquer sistema) DEPOIS dela; a taxa é sobre quem recebeu
 * a 1ª mensagem nos últimos MCP_RW_JANELA_METRICA_DIAS dias.
 */
function mcp_rw_metricas(array $pessoas, array $linhas, int $agora): array
{
    $m = ['no_fluxo' => 0, 'etapas' => [1 => 0, 2 => 0, 3 => 0], 'falhas' => 0, 'entregues' => 0, 'lidas' => 0, 'responderam' => 0, 'nao_querem' => 0,
        'receberam' => 0, 'recuperadas' => 0, 'taxa' => null, 'origens' => [], 'cursos' => [], 'dias' => [], 'lista' => []];
    $diaDe = static fn(int $ts): string => gmdate('Y-m-d', $ts + MCP_RW_FUSO_SEGUNDOS);
    for ($i = MCP_RW_DIAS_METRICA - 1; $i >= 0; $i--) {
        $m['dias'][$diaDe($agora - $i * 86400)] = ['mensagens' => 0, 'recuperacoes' => 0];
    }
    $responderam = [];
    $naoQuerem = [];
    foreach ($linhas as $l) {
        if ($l['status'] === 'enviado') {
            $m['etapas'][(int) $l['etapa']] = ($m['etapas'][(int) $l['etapa']] ?? 0) + 1;
            $m['entregues'] += in_array($l['entrega'], ['entregue', 'lida'], true) ? 1 : 0;
            $m['lidas'] += $l['entrega'] === 'lida' ? 1 : 0;
            $d = $diaDe((int) mcp_rw_ts($l['criado_em']));
            if (isset($m['dias'][$d])) {
                $m['dias'][$d]['mensagens']++;
            }
            $o = $l['origem'];
            $m['origens'][$o] ??= ['mensagens' => 0, 'pessoas' => 0, 'recuperadas' => 0];
            $m['origens'][$o]['mensagens']++;
            $c = (string) $l['curso_nome'];
            $m['cursos'][$c] ??= ['mensagens' => 0, 'pessoas' => 0, 'recuperadas' => 0];
            $m['cursos'][$c]['mensagens']++;
        } elseif ($l['erro'] !== 'enviando') {
            $m['falhas']++;
        }
        if ((string) $l['resposta'] !== '') {
            $responderam[$l['telefone_hash']] = true;
            if (mcp_rw_quer_parar((string) $l['resposta'])) {
                $naoQuerem[$l['telefone_hash']] = true;
            }
        }
    }
    $m['responderam'] = count($responderam);
    $m['nao_querem'] = count($naoQuerem);

    $indice = mcp_rw_indice($linhas);
    $inicioMetrica = $agora - MCP_RW_JANELA_METRICA_DIAS * 86400;
    $vistas = [];
    foreach ($pessoas as $p) {
        $minhas = mcp_rw_da_pessoa($p, $indice);
        $primeira = $minhas[1] ?? null;
        if ($primeira !== null && $primeira['status'] === 'enviado' && $primeira['criado_ts'] >= $inicioMetrica && !isset($vistas[$primeira['id']])) {
            $vistas[$primeira['id']] = true;
            $m['receberam']++;
            $o = $primeira['origem'];
            $c = (string) $primeira['curso_nome'];
            $m['origens'][$o] ??= ['mensagens' => 0, 'pessoas' => 0, 'recuperadas' => 0];
            $m['cursos'][$c] ??= ['mensagens' => 0, 'pessoas' => 0, 'recuperadas' => 0];
            $m['origens'][$o]['pessoas']++;
            $m['cursos'][$c]['pessoas']++;
            $depois = array_values(array_filter($p['pagamentos'], static fn(int $ts): bool => $ts > $primeira['criado_ts']));
            if ($depois) {
                $m['recuperadas']++;
                $m['origens'][$o]['recuperadas']++;
                $m['cursos'][$c]['recuperadas']++;
                $d = $diaDe($depois[0]);
                if (isset($m['dias'][$d])) {
                    $m['dias'][$d]['recuperacoes']++;
                }
            }
        }
        if (!$p['no_fluxo']) {
            continue;
        }
        $proxima = mcp_rw_proxima((int) $p['ancora'], $minhas);
        $enviadas = array_keys(array_filter($minhas, static fn(array $l): bool => $l['status'] === 'enviado'));
        if ($proxima === null) {
            continue; // já recebeu as três, ou uma etapa não chegou (número sem WhatsApp): não há mais nada a mandar
        }
        $m['no_fluxo']++;
        $respostas = array_values(array_filter(array_map(static fn(array $l): string => (string) $l['resposta'], $minhas)));
        $m['lista'][] = [
            'nome' => $p['nome'], 'origem' => $p['origem'], 'curso' => $p['curso_nome'], 'celular' => mcp_whatsapp_mascarado($p['numero']),
            'etapa' => $enviadas ? max($enviadas) : 0, 'proxima' => mcp_rw_ajustar_horario(max($proxima['devida_em'], $agora)),
            'proxima_etapa' => $proxima['etapa'], 'respostas' => $respostas,
        ];
    }
    $m['taxa'] = $m['receberam'] > 0 ? $m['recuperadas'] / $m['receberam'] : null;
    uasort($m['cursos'], static fn(array $a, array $b): int => [$b['mensagens'], $b['pessoas']] <=> [$a['mensagens'], $a['pessoas']]);
    ksort($m['origens']);
    usort($m['lista'], static fn(array $a, array $b): int => [$a['proxima'], $a['nome']] <=> [$b['proxima'], $b['nome']]);
    return $m;
}
