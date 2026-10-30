<?php
/**
 * Recuperação do funil (30/10/2026, pedido do Matheus): quem deixou dados no checkout e nunca pagou nada, para a
 * secretaria chamar pelo WhatsApp (à mão, pelo wa.me) ou exportar para o disparo com os modelos aprovados na Kapso.
 * A página é api/recuperacao.php; aqui fica a lógica, separada do banco onde dá (mcp_rf_agrupar, mcp_rf_mensagem,
 * mcp_rf_metricas e mcp_rf_csv não tocam no banco), para o teste conferir cada regra.
 *
 * Quem entra (o "público"):
 *  - inscrições criadas nos últimos MCP_RF_JANELA_DIAS dias, que nunca viraram pagamento;
 *  - uma PESSOA é o grupo de linhas ligadas por CPF, e-mail (minúsculo) ou telefone (só dígitos): quem errou o CPF numa
 *    tentativa e acertou na outra, ou trocou de e-mail, continua sendo uma pessoa só;
 *  - se QUALQUER linha da pessoa, de qualquer curso e de qualquer época, está paga ou estornada, ela sai (quem já é
 *    aluno não recebe "termine sua inscrição"). A exceção é quem pagou DEPOIS do primeiro contato daqui: essa pessoa
 *    é "recuperada" e aparece só nas métricas;
 *  - sai quem pediu para não receber (mcp_avisos_bloqueios, por e-mail ou WhatsApp, com o hash de mcp_avisos_hash).
 *    Quem a secretaria marcou "Não quer contato" aqui fica como 'nao_quer' (origem 'recuperacao'), fora da lista;
 *  - saem os CPFs de teste (TESTE_CPFS) e os cursos de teste (slug ou nome com "teste" ou "dev").
 *
 * O registro dos contatos (mcp_recuperacao_contatos) guarda só o HMAC do CPF: dá para saber se a pessoa já foi
 * chamada sem guardar o CPF de novo. A chave do HMAC fica em mcp_chaves (mcp_segredo), como a dos links do painel.
 *
 * A mensagem só diz o que é verdade e tem fonte: a data vem da turma aberta do oferta.json (mcp_turma_aberta), a carga
 * horária e o conteúdo, do catálogo (cursos.json). Nada de "últimas vagas" nem preço: o preço quem mostra é o checkout.
 */
declare(strict_types=1);

const MCP_RF_JANELA_DIAS = 180;
const MCP_RF_POR_PAGINA = 50;
const MCP_RF_DIAS_METRICA = 14;
// A turma que a mensagem oferece. Trocar de curso aqui exige trocar o texto de mcp_rf_mensagem junto.
const MCP_RF_CURSO_CONVITE = 'primeiros-socorros-basico';
const MCP_RF_SITUACOES = ['novo' => 'Novo', 'contatado' => 'Contatado', 'recuperado' => 'Recuperado', 'nao_quer' => 'Não quer contato'];
const MCP_RF_CANAIS = ['whatsapp', 'email', 'exportacao'];
const MCP_RF_PAGOS = ['pago', 'estornado'];

// ----------------------------------------------------------------------------- identidade
/** Chave da pessoa nos contatos: HMAC-SHA256 do CPF (64 hex). Sem o segredo do banco, não se volta ao CPF. */
function mcp_rf_chave(string $cpf): string
{
    return hash_hmac('sha256', mcp_digitos($cpf), mcp_segredo('recuperacao_funil'));
}

function mcp_rf_email(string $email): string
{
    return mb_strtolower(trim($email));
}

/** Telefone só com dígitos (DDD + número, sem o 55), ou '' se não for um telefone brasileiro válido. */
function mcp_rf_telefone(string $telefone): string
{
    return mcp_telefone($telefone);
}

/** Linha de teste: CPF da lista TESTE_CPFS ou curso com "teste" ou "dev" no slug ou no nome. */
function mcp_rf_linha_de_teste(array $l): bool
{
    if (mcp_cpf_de_teste(mcp_digitos((string) ($l['cpf'] ?? '')))) {
        return true;
    }
    $curso = mb_strtolower((string) ($l['curso_slug'] ?? '') . ' ' . (string) ($l['curso_nome'] ?? ''));
    return str_contains($curso, 'teste') || str_contains($curso, 'dev');
}

/** "MARIA" e "maria" viram "Maria"; nome já escrito com maiúsculas e minúsculas fica como está. */
function mcp_rf_primeiro_nome(string $nome): string
{
    $p = mcp_primeiro_nome($nome);
    return ($p === mb_strtoupper($p) || $p === mb_strtolower($p)) ? mb_convert_case(mb_strtolower($p), MB_CASE_TITLE) : $p;
}

function mcp_rf_ts(?string $utc): ?int
{
    if ($utc === null || $utc === '') {
        return null;
    }
    $ts = strtotime($utc . ' UTC');
    return $ts === false ? null : $ts;
}

// ----------------------------------------------------------------------------- agrupamento (puro)
/**
 * As pessoas a partir das linhas de mcp_inscricoes (as da janela e as pagas de qualquer época), sem tocar no banco.
 *
 * @param array    $linhas    id, cpf, email, telefone, nome, curso_slug, curso_nome, status, criado_em, pago_em, atualizado_em
 * @param array    $contatos  chave => lista de ['canal', 'criado_em'] (de mcp_recuperacao_contatos)
 * @param callable $chave     fn(string $cpf): string (mcp_rf_chave)
 * @param callable $bloqueio  fn(string $canal, string $destino): ?string — a origem do bloqueio, ou null
 * @param string   $inicio    início da janela (UTC, "Y-m-d H:i:s"): linha não paga mais velha que isso não conta
 * @return array pessoas, da tentativa mais recente para a mais antiga
 */
function mcp_rf_agrupar(array $linhas, array $contatos, callable $chave, callable $bloqueio, string $inicio): array
{
    $linhas = array_values(array_filter($linhas, static fn(array $l): bool => !mcp_rf_linha_de_teste($l)));
    // União das linhas que dividem CPF, e-mail ou telefone (union-find com compressão de caminho).
    $pai = array_keys($linhas);
    $raiz = static function (int $i) use (&$pai): int {
        while ($pai[$i] !== $i) {
            $pai[$i] = $pai[$pai[$i]];
            $i = $pai[$i];
        }
        return $i;
    };
    $dono = [];
    foreach ($linhas as $i => $l) {
        $ids = [];
        $cpf = mcp_digitos((string) $l['cpf']);
        if (strlen($cpf) === 11) {
            $ids[] = "c:$cpf";
        }
        $email = mcp_rf_email((string) $l['email']);
        if ($email !== '') {
            $ids[] = "e:$email";
        }
        $tel = mcp_rf_telefone((string) $l['telefone']);
        if ($tel !== '') {
            $ids[] = "t:$tel";
        }
        foreach ($ids as $id) {
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
    foreach ($linhas as $i => $l) {
        $grupos[$raiz($i)][] = $l;
    }

    $pessoas = [];
    foreach ($grupos as $grupo) {
        $pendentes = [];
        $pagoTs = null;
        foreach ($grupo as $l) {
            if (in_array($l['status'], MCP_RF_PAGOS, true)) {
                $ts = mcp_rf_ts($l['pago_em'] ?: ($l['atualizado_em'] ?: $l['criado_em']));
                $pagoTs = $pagoTs === null ? $ts : min($pagoTs, (int) $ts);
            } elseif ((string) $l['criado_em'] >= $inicio) {
                $pendentes[] = $l;
            }
        }
        if (!$pendentes) {
            continue; // só linhas pagas, ou tentativas velhas demais
        }
        // A mais recente primeiro: é dela o nome, o e-mail e o curso de interesse.
        usort($pendentes, static fn(array $a, array $b): int => [$b['criado_em'], (int) $b['id']] <=> [$a['criado_em'], (int) $a['id']]);
        $recente = $pendentes[0];

        $chaves = [];
        $emails = [];
        $whatsapps = [];
        $telefone = '';
        foreach ($grupo as $l) {
            $cpf = mcp_digitos((string) $l['cpf']);
            if (strlen($cpf) === 11) {
                $chaves[$chave($cpf)] = true;
            }
            $email = mcp_rf_email((string) $l['email']);
            if ($email !== '') {
                $emails[$email] = true;
            }
        }
        foreach ($pendentes as $l) {
            $tel = mcp_rf_telefone((string) $l['telefone']);
            $telefone = $telefone !== '' ? $telefone : $tel;
            $numero = mcp_whatsapp_numero($tel);
            if ($numero !== null) {
                $whatsapps[$numero] = true;
            }
        }
        foreach ($grupo as $l) {
            if (($numero = mcp_whatsapp_numero(mcp_rf_telefone((string) $l['telefone']))) !== null) {
                $whatsapps += [$numero => true]; // os das linhas pagas também contam para o bloqueio
            }
        }

        $meus = [];
        foreach (array_keys($chaves) as $k) {
            foreach ($contatos[$k] ?? [] as $c) {
                $meus[] = $c;
            }
        }
        usort($meus, static fn(array $a, array $b): int => strcmp((string) $a['criado_em'], (string) $b['criado_em']));
        $primeiroContato = $meus ? (string) $meus[0]['criado_em'] : null;

        if ($pagoTs !== null) {
            // Pagou alguma coisa: só fica (como recuperada) se pagou depois do primeiro contato daqui.
            if ($primeiroContato === null || $pagoTs <= (int) mcp_rf_ts($primeiroContato)) {
                continue;
            }
            $situacao = 'recuperado';
        } else {
            $origens = [];
            foreach (array_keys($emails) as $e) {
                $origens[] = $bloqueio('email', $e);
            }
            foreach (array_keys($whatsapps) as $n) {
                $origens[] = $bloqueio('whatsapp', (string) $n);
            }
            $origens = array_values(array_filter($origens, static fn($o): bool => $o !== null));
            if (in_array('recuperacao', $origens, true)) {
                $situacao = 'nao_quer';
            } elseif ($origens) {
                continue; // pediu para não receber por outro caminho (link do e-mail, PARAR no WhatsApp)
            } else {
                $situacao = $meus ? 'contatado' : 'novo';
            }
        }

        $cursos = [];
        foreach ($pendentes as $l) {
            $cursos[(string) $l['curso_slug']] ??= (string) $l['curso_nome'];
        }
        $cpfRecente = mcp_digitos((string) $recente['cpf']);
        $whatsappRecente = null;
        foreach ($pendentes as $l) {
            if (($whatsappRecente = mcp_whatsapp_numero(mcp_rf_telefone((string) $l['telefone']))) !== null) {
                break;
            }
        }
        $pessoas[] = [
            'id' => (int) $recente['id'],
            'ids' => array_map(static fn(array $l): int => (int) $l['id'], $pendentes),
            'chave' => strlen($cpfRecente) === 11 ? $chave($cpfRecente) : '',
            'nome' => trim((string) $recente['nome']),
            'primeiro_nome' => mcp_rf_primeiro_nome((string) $recente['nome']),
            'email' => mcp_rf_email((string) $recente['email']),
            'emails' => array_keys($emails),
            'telefone' => $telefone,
            'whatsapp' => $whatsappRecente,
            'whatsapps' => array_map('strval', array_keys($whatsapps)),
            'cpf_mascarado' => mcp_cpf_mascarado($cpfRecente),
            'cursos' => $cursos,
            'curso_slug' => (string) $recente['curso_slug'],
            'curso_interesse' => (string) $recente['curso_nome'],
            'ultima_tentativa' => (string) $recente['criado_em'],
            'tentativas' => count($pendentes),
            'situacao' => $situacao,
            'contatos' => count($meus),
            'primeiro_contato' => $primeiroContato,
            'ultimo_contato' => $meus ? (string) end($meus)['criado_em'] : null,
            'recuperado_em' => $situacao === 'recuperado' ? gmdate('Y-m-d H:i:s', (int) $pagoTs) : null,
        ];
    }
    usort($pessoas, static fn(array $a, array $b): int => [$b['ultima_tentativa'], $b['id']] <=> [$a['ultima_tentativa'], $a['id']]);
    return $pessoas;
}

/**
 * Filtro da lista: situação '' é a lista de trabalho (novos e contatados); recuperados e "não quer" aparecem só quando
 * pedidos. Curso '' = todos; senão, quem tentou esse curso em alguma das tentativas.
 */
function mcp_rf_filtrar(array $pessoas, string $curso = '', string $situacao = ''): array
{
    return array_values(array_filter($pessoas, static function (array $p) use ($curso, $situacao): bool {
        $ok = $situacao === '' ? in_array($p['situacao'], ['novo', 'contatado'], true) : $p['situacao'] === $situacao;
        return $ok && ($curso === '' || isset($p['cursos'][$curso]));
    }));
}

/** A pessoa de uma inscrição (qualquer tentativa dela na janela), ou null se ela não está mais no público. */
function mcp_rf_pessoa_por_inscricao(array $pessoas, int $id): ?array
{
    foreach ($pessoas as $p) {
        if (in_array($id, $p['ids'], true)) {
            return $p;
        }
    }
    return null;
}

// ----------------------------------------------------------------------------- mensagem do WhatsApp
/** A turma que a mensagem oferece (a próxima aberta do curso no oferta.json), ou null: aí o botão fica desligado. */
function mcp_rf_turma(?int $agora = null): ?array
{
    return mcp_turma_aberta(MCP_RF_CURSO_CONVITE, $agora);
}

/** "terça-feira, 17/11", no horário de Brasília. */
function mcp_rf_data_curta(array $turma): string
{
    $ini = (new DateTimeImmutable('@' . (int) mcp_iso_ts($turma['inicio'])))->setTimezone(mcp_fuso_brt());
    return MCP_DIAS_SEMANA[(int) $ini->format('w')] . ', ' . $ini->format('d/m');
}

/** Campanha da turma: "ps-20261117". Vai no utm_campaign e no registro do contato. */
function mcp_rf_campanha(array $turma): string
{
    return 'ps-' . (new DateTimeImmutable('@' . (int) mcp_iso_ts($turma['inicio'])))->setTimezone(mcp_fuso_brt())->format('Ymd');
}

function mcp_rf_link_checkout(array $turma): string
{
    return mcp_site_url() . '/matricula-cursos-presenciais/checkout/?curso=' . MCP_RF_CURSO_CONVITE
        . '&utm_source=whatsapp&utm_medium=recuperacao&utm_campaign=' . mcp_rf_campanha($turma);
}

/**
 * O texto do convite. Quem já tinha tentado Primeiros Socorros Básico (em qualquer tentativa) ouve que a inscrição não
 * foi concluída, sem o curso repetido; os outros, o curso de interesse da tentativa mais recente.
 */
function mcp_rf_mensagem(array $pessoa, array $turma): string
{
    $abertura = isset($pessoa['cursos'][MCP_RF_CURSO_CONVITE])
        ? 'Você começou sua inscrição no curso de Primeiros Socorros Básico e ela não foi concluída. Queremos te contar que abrimos uma turma'
        : 'Você se interessou pelo nosso curso de ' . $pessoa['curso_interesse'] . ', e queremos te contar que abrimos uma turma de Primeiros Socorros Básico';
    return 'Olá, ' . $pessoa['primeiro_nome'] . '! Aqui é a Cruz Vermelha RJ. ' . $abertura . ' em ' . mcp_rf_data_curta($turma)
        . ', na sede do Centro do Rio. São 8 horas de aula presencial com prática de RCP, uso do desfibrilador (DEA), engasgo e controle de hemorragias. '
        . 'O curso inclui a Lei Lucas e dá certificado da Cruz Vermelha. Inscrição: ' . mcp_rf_link_checkout($turma)
        . '. Se não quiser mais receber nossas mensagens, é só responder SAIR.';
}

/** wa.me com o número inteiro (55 + DDD + celular) e o texto pronto: a secretaria só confere e toca em enviar. */
function mcp_rf_link_whatsapp(string $numero, string $texto): string
{
    return 'https://wa.me/' . mcp_digitos($numero) . '?text=' . rawurlencode($texto);
}

/** "(21) 9****-4321"; telefone fixo: "(21) ****-4321". */
function mcp_rf_telefone_mascarado(string $digitos): string
{
    $d = mcp_digitos($digitos);
    if (strlen($d) === 11) {
        return mcp_whatsapp_mascarado($d);
    }
    return strlen($d) === 10 ? '(' . substr($d, 0, 2) . ') ****-' . substr($d, 6) : '';
}

// ----------------------------------------------------------------------------- métricas (puro)
/**
 * Números do topo, sem dado pessoal: o público (novos + contatados), contatadas, recuperadas e a taxa (recuperadas ÷
 * contatadas), a quebra por curso de interesse e os últimos 14 dias (contatos × recuperações, dia de Brasília).
 *
 * @param array $contatosLinhas linhas de mcp_recuperacao_contatos (canal, criado_em) dos últimos dias
 */
function mcp_rf_metricas(array $pessoas, array $contatosLinhas, int $pixRecuperadas, ?int $agora = null): array
{
    $agora ??= time();
    $m = ['publico' => 0, 'novas' => 0, 'contatadas' => 0, 'recuperadas' => 0, 'nao_quer' => 0, 'taxa' => null, 'pix' => $pixRecuperadas, 'cursos' => [], 'dias' => []];
    $fuso = mcp_fuso_brt();
    $hoje = (new DateTimeImmutable('@' . $agora))->setTimezone($fuso)->setTime(0, 0);
    for ($i = MCP_RF_DIAS_METRICA - 1; $i >= 0; $i--) {
        $m['dias'][$hoje->modify("-$i days")->format('Y-m-d')] = ['whatsapp' => 0, 'email' => 0, 'exportacao' => 0, 'recuperacoes' => 0];
    }
    $dia = static fn(string $utc): string => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($fuso)->format('Y-m-d');
    foreach ($pessoas as $p) {
        $noPublico = in_array($p['situacao'], ['novo', 'contatado'], true);
        $contatada = $p['contatos'] > 0;
        $recuperada = $p['situacao'] === 'recuperado';
        $m['publico'] += $noPublico ? 1 : 0;
        $m['novas'] += $p['situacao'] === 'novo' ? 1 : 0;
        $m['contatadas'] += $contatada ? 1 : 0;
        $m['recuperadas'] += $recuperada ? 1 : 0;
        $m['nao_quer'] += $p['situacao'] === 'nao_quer' ? 1 : 0;
        foreach ($p['cursos'] as $slug => $nome) {
            $m['cursos'][$slug] ??= ['nome' => $nome, 'pessoas' => 0, 'contatadas' => 0, 'recuperadas' => 0];
            $m['cursos'][$slug]['pessoas'] += $noPublico ? 1 : 0;
            $m['cursos'][$slug]['contatadas'] += $contatada ? 1 : 0;
            $m['cursos'][$slug]['recuperadas'] += $recuperada ? 1 : 0;
        }
        if ($recuperada && isset($m['dias'][$d = $dia((string) $p['recuperado_em'])])) {
            $m['dias'][$d]['recuperacoes']++;
        }
    }
    foreach ($contatosLinhas as $c) {
        if (in_array($c['canal'], MCP_RF_CANAIS, true) && isset($m['dias'][$d = $dia((string) $c['criado_em'])])) {
            $m['dias'][$d][$c['canal']]++;
        }
    }
    $m['taxa'] = $m['contatadas'] > 0 ? $m['recuperadas'] / $m['contatadas'] : null;
    uasort($m['cursos'], static fn(array $a, array $b): int => [$b['pessoas'], $a['nome']] <=> [$a['pessoas'], $b['nome']]);
    return $m;
}

/**
 * Quantas inscrições que receberam o lembrete do PIX (evento email_pix_lembrete) viraram pagamento da mesma pessoa
 * (CPF, e-mail ou telefone) no mesmo curso, depois do lembrete. Puro: recebe as duas listas.
 *
 * @param array $lembretes inscricao_id, cpf, email, telefone, curso_slug, enviado_em
 * @param array $pagos     cpf, email, telefone, curso_slug, pago_em
 */
function mcp_rf_pix_contar(array $lembretes, array $pagos): int
{
    $indice = [];
    foreach ($pagos as $p) {
        $ts = (int) mcp_rf_ts((string) $p['pago_em']);
        foreach (['c:' . mcp_digitos((string) $p['cpf']), 'e:' . mcp_rf_email((string) $p['email']), 't:' . mcp_rf_telefone((string) $p['telefone'])] as $id) {
            if (!str_ends_with($id, ':')) {
                $k = $p['curso_slug'] . '|' . $id;
                $indice[$k] = max($indice[$k] ?? 0, $ts);
            }
        }
    }
    $contadas = [];
    foreach ($lembretes as $l) {
        $quando = (int) mcp_rf_ts((string) $l['enviado_em']);
        foreach (['c:' . mcp_digitos((string) $l['cpf']), 'e:' . mcp_rf_email((string) $l['email']), 't:' . mcp_rf_telefone((string) $l['telefone'])] as $id) {
            if (!str_ends_with($id, ':') && ($indice[$l['curso_slug'] . '|' . $id] ?? 0) > $quando) {
                $contadas[(int) $l['inscricao_id']] = true;
                break;
            }
        }
    }
    return count($contadas);
}

// ----------------------------------------------------------------------------- planilha (pura)
/** CSV para a Kapso: vírgula, UTF-8, cabeçalho em minúsculas. Fórmulas neutralizadas (mcp_horarios_celula). */
function mcp_rf_csv(array $pessoas): string
{
    $f = fopen('php://temp', 'w+');
    fputcsv($f, ['nome', 'primeiro_nome', 'telefone_e164', 'email', 'curso_interesse', 'ultima_tentativa'], ',', '"', '');
    foreach ($pessoas as $p) {
        fputcsv($f, array_map('mcp_horarios_celula', [
            $p['nome'], $p['primeiro_nome'], (string) ($p['whatsapp'] ?? ''), $p['email'], $p['curso_interesse'],
            mcp_data_brt($p['ultima_tentativa'], 'Y-m-d'),
        ]), ',', '"', '');
    }
    rewind($f);
    $csv = (string) stream_get_contents($f);
    fclose($f);
    return $csv;
}

// ----------------------------------------------------------------------------- banco
/** Início da janela (UTC). */
function mcp_rf_inicio(?int $agora = null): string
{
    return gmdate('Y-m-d H:i:s', ($agora ?? time()) - MCP_RF_JANELA_DIAS * 86400);
}

/** O público inteiro, com a situação de cada pessoa. Lê o banco; a regra está em mcp_rf_agrupar. */
function mcp_rf_carregar(?int $agora = null): array
{
    $db = mcp_db();
    $inicio = mcp_rf_inicio($agora);
    $stmt = $db->prepare("SELECT id, cpf, email, telefone, nome, curso_slug, curso_nome, status, criado_em, pago_em, atualizado_em
        FROM mcp_inscricoes WHERE criado_em >= ? OR status IN ('pago', 'estornado')");
    $stmt->execute([$inicio]);
    $linhas = $stmt->fetchAll();
    $contatos = [];
    foreach ($db->query('SELECT pessoa_chave, canal, criado_em FROM mcp_recuperacao_contatos ORDER BY id') as $c) {
        $contatos[$c['pessoa_chave']][] = ['canal' => $c['canal'], 'criado_em' => $c['criado_em']];
    }
    $bloqueios = [];
    foreach ($db->query('SELECT canal, destino_hash, origem FROM mcp_avisos_bloqueios') as $b) {
        $bloqueios[$b['canal'] . '|' . $b['destino_hash']] = (string) $b['origem'];
    }
    $bloqueio = static fn(string $canal, string $destino): ?string => $bloqueios[$canal . '|' . mcp_avisos_hash($canal, $destino)] ?? null;
    return mcp_rf_agrupar($linhas, $contatos, 'mcp_rf_chave', $bloqueio, $inicio);
}

/** Contatos dos últimos 14 dias (para a tabela por dia). */
function mcp_rf_contatos_recentes(?int $agora = null): array
{
    $stmt = mcp_db()->prepare('SELECT canal, criado_em FROM mcp_recuperacao_contatos WHERE criado_em >= ?');
    $stmt->execute([gmdate('Y-m-d H:i:s', ($agora ?? time()) - (MCP_RF_DIAS_METRICA + 1) * 86400)]);
    return $stmt->fetchAll();
}

/** Recuperadas pelo lembrete do PIX que já existe (lib/recuperacao.php), na mesma janela. */
function mcp_rf_pix_recuperadas(?int $agora = null): int
{
    $db = mcp_db();
    $inicio = mcp_rf_inicio($agora);
    $stmt = $db->prepare("SELECT e.inscricao_id, e.criado_em AS enviado_em, i.cpf, i.email, i.telefone, i.curso_slug
        FROM mcp_eventos e JOIN mcp_inscricoes i ON i.id = e.inscricao_id WHERE e.tipo = 'email_pix_lembrete' AND e.criado_em >= ?");
    $stmt->execute([$inicio]);
    $lembretes = $stmt->fetchAll();
    if (!$lembretes) {
        return 0;
    }
    $stmt = $db->prepare("SELECT cpf, email, telefone, curso_slug, COALESCE(pago_em, atualizado_em) AS pago_em FROM mcp_inscricoes
        WHERE status IN ('pago', 'estornado') AND COALESCE(pago_em, atualizado_em) >= ?");
    $stmt->execute([$inicio]);
    return mcp_rf_pix_contar($lembretes, $stmt->fetchAll());
}

/** Um contato com uma pessoa (ou várias, na exportação), numa transação. */
function mcp_rf_registrar_contatos(array $pessoas, string $canal, string $campanha, string $autor): int
{
    if (!in_array($canal, MCP_RF_CANAIS, true)) {
        throw new InvalidArgumentException('Canal desconhecido.');
    }
    $db = mcp_db();
    $stmt = $db->prepare('INSERT INTO mcp_recuperacao_contatos (pessoa_chave, inscricao_id, canal, campanha, autor, criado_em) VALUES (?, ?, ?, ?, ?, ?)');
    $agora = mcp_agora();
    $n = 0;
    $db->beginTransaction();
    try {
        foreach ($pessoas as $p) {
            if ($p['chave'] === '') {
                continue;
            }
            $stmt->execute([$p['chave'], $p['id'], $canal, mb_substr($campanha, 0, 40) ?: null, mb_substr($autor, 0, 190), $agora]);
            $n++;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return $n;
}

/**
 * "Não quer contato": bloqueia todos os e-mails e celulares da pessoa nos dois canais (mcp_avisos_bloqueios, origem
 * 'recuperacao'). Vale também para os avisos das aulas e comunicados, como o "não quero mais receber" da página.
 */
function mcp_rf_nao_quer(array $pessoa, string $autor): void
{
    foreach ($pessoa['emails'] as $email) {
        mcp_avisos_bloquear_hash('email', mcp_avisos_hash('email', $email), 'recuperacao');
    }
    foreach ($pessoa['whatsapps'] as $numero) {
        mcp_avisos_bloquear_hash('whatsapp', mcp_avisos_hash('whatsapp', $numero), 'recuperacao');
    }
    mcp_registrar((int) $pessoa['id'], 'recuperacao_nao_quer', $autor);
}
