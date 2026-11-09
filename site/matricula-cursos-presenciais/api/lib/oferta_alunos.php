<?php
/**
 * Oferta a ex-alunos por WhatsApp (09/11/2026). Pedido do responsável: mandar o cupom CURATIVOS (Curativos e Tratamento
 * de Feridas por R$ 150,00 com tudo incluso, turma de 28/11) a quem já pagou um curso da escola. É um disparo ÚNICO:
 * cada celular da lista recebe uma mensagem, uma vez, e nada mais.
 *
 *  - A lista (nome e telefone) fica em api/config-oferta-alunos.php, SÓ no servidor: tem dado pessoal, nunca vai para o
 *    Git e o .htaccess a nega (config-*.php). Formato:
 *      ['OFERTA_ALUNOS_LIGADA' => true, 'OFERTA_ALUNOS' => [['nome' => '…', 'telefone' => '…'], …]]
 *    Sem o arquivo, com a chave desligada ou com a lista vazia, nada sai. Tirar o arquivo (ou pôr false) para o disparo.
 *  - A mensagem é o modelo de cupom do curso (MCP_RW_OFERTAS: cur_cupom_oferta, Marketing): {{1}} primeiro nome, {{2}} o
 *    cupom, {{3}} a data da turma, botão com o link curto …/api/ir.php/w/<curso>/alunos, que leva ao checkout com o cupom
 *    aplicado e utm_source=whatsapp&utm_medium=oferta&utm_campaign=alunos (fora das contas da recuperação). Só sai com o
 *    modelo APROVADO na Meta e com a estrutura conferida; sem a lista de modelos (Kapso fora), espera.
 *  - Vale enquanto a oferta vale (mcp_rw_oferta_cupom: cupom dentro do prazo, com vaga, e turma aberta). Depois, para sozinho.
 *  - Não recebe: celular inválido, celular em mcp_avisos_bloqueios (qualquer origem: "Não tenho interesse", SAIR, PARAR),
 *    quem já tem compra paga do curso no site, e quem já recebeu (UNIQUE(campanha, destino_hash)).
 *  - Mesmo número, mesmo teto: só das 8h às 20h de Brasília, no máximo MCP_OA_POR_RODADA por rodada, e o teto de
 *    MCP_RW_POR_DIA em 24 h é somado ao da recuperação e da matrícula pendente (mcp_rw_enviadas_24h). Roda DEPOIS delas no
 *    api/lembretes.php: lembrete com data de aula e carrinho aberto têm a vez antes de uma oferta.
 *  - Erros da Kapso como na recuperação: o que é de quem recebe gasta o envio ("falhou"); Kapso ou Meta fora, modelo
 *    pausado e limite param a rodada e tentam na hora seguinte; resposta incerta conta como enviada (nunca repete).
 *  - Banco: mcp_oferta_alunos, só com o hash do celular (o mesmo de mcp_avisos_bloqueios), nunca nome nem número. A
 *    entrega, a leitura e a resposta chegam pelo webhook da recuperação (mcp_rw_webhook_tratar). O painel
 *    api/recuperacao.php mostra os números.
 *
 * Carregado pelo lib/recuperacao_whatsapp.php (usa a Kapso, os modelos e os links curtos de lá).
 */
declare(strict_types=1);

/** O nome do disparo no banco (cada celular recebe uma vez por campanha). */
const MCP_OA_CAMPANHA = 'curativos-alunos';
/** O curso oferecido: tem de estar em MCP_RW_OFERTAS (o cupom e o modelo vêm de lá). */
const MCP_OA_CURSO = 'curativos-e-tratamento-de-feridas';
/** A campanha do link curto (o 3º pedaço do api/ir.php) e o utm_campaign do checkout. */
const MCP_OA_LINK = 'alunos';
const MCP_OA_POR_RODADA = 40;

/**
 * A lista de api/config-oferta-alunos.php: ['ligada', 'lista' => [['nome', 'numero' (55…)], …] sem repetidos,
 * 'invalidos' (telefones que não são celular), 'repetidos']. Sem o arquivo: desligada e vazia.
 */
function mcp_oa_config(): array
{
    $saida = ['ligada' => false, 'lista' => [], 'invalidos' => 0, 'repetidos' => 0];
    $arquivo = getenv('MCP_CONFIG_OFERTA_ALUNOS_ARQUIVO') ?: dirname(__DIR__) . '/config-oferta-alunos.php';
    if (!is_file($arquivo)) {
        return $saida;
    }
    $dados = (static fn(string $a): mixed => require $a)($arquivo);
    if (!is_array($dados)) {
        return $saida;
    }
    $saida['ligada'] = ($dados['OFERTA_ALUNOS_LIGADA'] ?? false) === true;
    $vistos = [];
    foreach (is_array($dados['OFERTA_ALUNOS'] ?? null) ? $dados['OFERTA_ALUNOS'] : [] as $l) {
        if (!is_array($l)) {
            continue;
        }
        $numero = mcp_whatsapp_numero((string) ($l['telefone'] ?? ''));
        if ($numero === null) {
            $saida['invalidos']++;
        } elseif (isset($vistos[$numero])) {
            $saida['repetidos']++;
        } else {
            $vistos[$numero] = true;
            $saida['lista'][] = ['nome' => trim((string) ($l['nome'] ?? '')), 'numero' => $numero];
        }
    }
    return $saida;
}

/** A mensagem para uma pessoa da lista, com a oferta de mcp_rw_oferta_cupom (['codigo', 'data']). Puro. */
function mcp_oa_modelo(array $pessoa, array $oferta): array
{
    $nome = mcp_rw_parametro(mcp_rw_primeiro_nome((string) ($pessoa['nome'] ?? '')));
    $modelo = MCP_RW_OFERTAS[MCP_OA_CURSO]['modelo'];
    $parametros = [$nome !== '' ? $nome : MCP_RW_NOME_PADRAO, mcp_rw_parametro((string) $oferta['codigo']), mcp_rw_parametro((string) $oferta['data'])];
    return ['nome' => $modelo, 'etapa' => 0, 'parametros' => $parametros, 'caminho' => mcp_rw_link('whatsapp', MCP_OA_CURSO, MCP_OA_LINK),
        'previa' => mcp_rw_previa($modelo, $parametros)];
}

/** O celular já tem uma compra paga do curso da oferta no checkout do site? */
function mcp_oa_ja_comprou(string $numero): bool
{
    $local = substr($numero, 2);
    $telefones = [$local, $numero, substr($local, 0, 2) . substr($local, 3)]; // com e sem 55, com e sem o nono dígito
    $stmt = mcp_db()->prepare("SELECT 1 FROM mcp_inscricoes WHERE status = 'pago' AND curso_slug = ? AND REGEXP_REPLACE(telefone, '[^0-9]', '') IN (?, ?, ?) LIMIT 1");
    $stmt->execute([MCP_OA_CURSO, ...$telefones]);
    return (bool) $stmt->fetchColumn();
}

/** Os hashes dos celulares que já têm linha nesta campanha (enviado ou falhou): não recebem de novo. */
function mcp_oa_feitos(): array
{
    $stmt = mcp_db()->prepare('SELECT destino_hash FROM mcp_oferta_alunos WHERE campanha = ?');
    $stmt->execute([MCP_OA_CAMPANHA]);
    return array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
}

/**
 * Uma rodada (api/lembretes.php, de hora em hora, depois da matrícula pendente e da recuperação). Nunca lança por causa
 * da Kapso; erro de banco sobe para quem chama. O resumo (só números e o motivo) vai para mcp_eventos
 * ('oferta_alunos_rodada').
 * @return array{enviados: int, falhas: int, pulados: int, faltam: int, motivo: ?string}
 */
function mcp_oa_rodar(?int $agora = null): array
{
    $agora ??= time();
    $inicioReal = time();
    $agoraReal = static fn(): int => $agora + max(0, time() - $inicioReal);
    $r = ['enviados' => 0, 'falhas' => 0, 'pulados' => 0, 'faltam' => 0, 'motivo' => null];
    $fim = static function (array $r) use ($agora): array {
        mcp_registrar(null, 'oferta_alunos_rodada', (string) json_encode(['em' => $agora, 'c' => MCP_OA_CAMPANHA] + $r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $r;
    };
    $lista = mcp_oa_config();
    if (!$lista['ligada'] || !$lista['lista']) {
        // Sem disparo ligado não há o que registrar de hora em hora.
        $r['motivo'] = $lista['lista'] ? 'desligada (OFERTA_ALUNOS_LIGADA)' : 'sem lista (api/config-oferta-alunos.php)';
        return $r;
    }
    $feitos = mcp_oa_feitos();
    $fila = array_values(array_filter($lista['lista'], static fn(array $p): bool => !isset($feitos[mcp_avisos_hash('whatsapp', $p['numero'])])));
    $r['faltam'] = count($fila);
    if (!$fila) {
        $r['motivo'] = 'todos da lista já foram atendidos';
        return $r;
    }
    if (!mcp_rw_no_horario($agora)) {
        $r['motivo'] = 'fora do horário (8h–20h de Brasília)';
        return $r;
    }
    $oferta = mcp_rw_oferta_cupom($agora, MCP_OA_CURSO);
    if ($oferta === null) {
        $r['motivo'] = 'a oferta não vale agora (cupom encerrado ou sem vaga, ou curso sem turma aberta)';
        return $fim($r);
    }
    $cfg = mcp_rw_kapso_config();
    if (!$cfg['ok']) {
        $r['motivo'] = 'sem configuração (' . implode(', ', $cfg['falta']) . ')';
        return $fim($r);
    }
    // Disparo de marketing: só com a lista de modelos da Meta em mãos, o modelo aprovado e a estrutura conferida.
    $modelos = mcp_rw_modelos($cfg, $agora);
    if (!$modelos['ok']) {
        $r['motivo'] = 'Kapso: ' . ($modelos['erro'] ?? 'sem a lista de modelos');
        return $fim($r);
    }
    $base = mcp_rw_escolher_modelo(mcp_oa_modelo($fila[0], $oferta), $modelos);
    $estrutura = $modelos['estrutura'][$base['nome']] ?? ['botao' => null, 'params' => null];
    if (($modelos['modelos'][$base['nome']] ?? '') !== 'APPROVED') {
        $r['motivo'] = 'modelo sem aprovação: ' . $base['nome'];
        return $fim($r);
    }
    if (($problema = mcp_rw_estrutura_problema($estrutura, $base)) !== null) {
        $r['motivo'] = $problema;
        return $fim($r);
    }
    $db = mcp_db();
    $noDia = mcp_rw_enviadas_24h($agora);
    $ultimoErro = null;
    foreach ($fila as $p) {
        $agoraEnvio = $agoraReal();
        if ($r['enviados'] + $r['falhas'] >= MCP_OA_POR_RODADA) {
            $r['motivo'] = 'teto de ' . MCP_OA_POR_RODADA . ' por rodada; o resto vai na próxima';
            break;
        }
        if ($noDia >= MCP_RW_POR_DIA) {
            $r['motivo'] = 'teto de ' . MCP_RW_POR_DIA . ' mensagens em 24 h (somado à recuperação)';
            break;
        }
        if (!mcp_rw_no_horario($agoraEnvio)) {
            $r['motivo'] = 'fora do horário (8h–20h de Brasília)';
            break;
        }
        if (mcp_avisos_bloqueado('whatsapp', $p['numero']) || mcp_oa_ja_comprou($p['numero'])) {
            $r['pulados']++;
            continue;
        }
        $m = array_replace(mcp_oa_modelo($p, $oferta), ['nome' => $base['nome'], 'botao' => $estrutura['botao'] ?? null]);
        $quando = gmdate('Y-m-d H:i:s', $agoraEnvio);
        $stmt = $db->prepare("INSERT IGNORE INTO mcp_oferta_alunos (campanha, destino_hash, modelo, curso_slug, status, erro, criado_em, atualizado_em)
            VALUES (?, ?, ?, ?, 'falhou', 'enviando', ?, ?)");
        $stmt->execute([MCP_OA_CAMPANHA, mcp_avisos_hash('whatsapp', $p['numero']), $m['nome'], MCP_OA_CURSO, $quando, $quando]);
        if ($stmt->rowCount() !== 1) {
            $r['pulados']++;
            continue;
        }
        $id = (int) $db->lastInsertId();
        $envio = mcp_rw_kapso_enviar($cfg, mcp_rw_corpo_envio($p['numero'], $m));
        if (!$envio['ok'] && $envio['transitorio']) {
            // Não saiu, e a culpa não é de quem recebe: a vez não é gasta (como na recuperação).
            $db->prepare('DELETE FROM mcp_oferta_alunos WHERE id = ?')->execute([$id]);
            $r['falhas']++;
            if ($envio['escopo'] === 'pessoa' && $ultimoErro !== $envio['codigo']) {
                $ultimoErro = $envio['codigo'];
                continue;
            }
            $r['motivo'] = 'Kapso: ' . $envio['erro'];
            error_log('[matricula] oferta a ex-alunos parada nesta rodada: ' . $envio['erro']);
            break;
        }
        $ultimoErro = null;
        $db->prepare('UPDATE mcp_oferta_alunos SET status = ?, provedor_id = ?, erro = ?, atualizado_em = ? WHERE id = ?')
            ->execute([$envio['ok'] ? 'enviado' : 'falhou', $envio['id'], $envio['erro'] !== null ? mb_substr($envio['erro'], 0, 255) : null, gmdate('Y-m-d H:i:s', $agoraReal()), $id]);
        if (!$envio['ok']) {
            $r['falhas']++;
            continue;
        }
        $r['enviados']++;
        $noDia++;
        if ($envio['incerto'] && $envio['parar']) {
            $r['motivo'] = 'Kapso: ' . $envio['erro'];
            break;
        }
    }
    $r['faltam'] = max(0, $r['faltam'] - $r['enviados']);
    // Só fica registrada a rodada com movimento: quem está bloqueado ou já comprou é pulado de hora em hora, em silêncio.
    return $r['enviados'] + $r['falhas'] > 0 || $r['motivo'] !== null ? $fim($r) : $r;
}

/** Resposta a uma mensagem da oferta (pelo wamid citado): guardada na linha dela. Sem a tabela ou sem a linha, false. */
function mcp_oa_guardar_resposta(string $citada, string $texto, string $quando): bool
{
    try {
        $stmt = mcp_db()->prepare('SELECT id, resposta FROM mcp_oferta_alunos WHERE provedor_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$citada]);
        $l = $stmt->fetch();
    } catch (PDOException) {
        return false;
    }
    if (!$l) {
        return false;
    }
    if (mcp_rw_quer_parar($texto) || !mcp_rw_quer_parar((string) $l['resposta'])) {
        mcp_db()->prepare('UPDATE mcp_oferta_alunos SET resposta = ?, atualizado_em = ? WHERE id = ?')->execute([$texto, $quando, (int) $l['id']]);
    }
    return true;
}

/**
 * Os números do painel: a lista, o que saiu, o que chegou, quem respondeu e as compras pagas pelo link (utm_campaign
 * 'alunos' no curso da oferta). Sem a tabela (migração adiada): null. Nenhum dado pessoal.
 */
function mcp_oa_metricas(?int $agora = null): ?array
{
    $agora ??= time();
    $lista = mcp_oa_config();
    try {
        $stmt = mcp_db()->prepare('SELECT status, entrega, resposta, criado_em FROM mcp_oferta_alunos WHERE campanha = ?');
        $stmt->execute([MCP_OA_CAMPANHA]);
        $linhas = $stmt->fetchAll();
    } catch (PDOException) {
        return null;
    }
    $m = ['ligada' => $lista['ligada'], 'na_lista' => count($lista['lista']), 'invalidos' => $lista['invalidos'], 'repetidos' => $lista['repetidos'],
        'enviadas' => 0, 'falharam' => 0, 'entregues' => 0, 'lidas' => 0, 'responderam' => 0, 'nao_querem' => 0, 'bloqueados' => 0, 'compras' => 0,
        'primeira' => null, 'ultima' => null, 'oferta' => mcp_rw_oferta_cupom($agora, MCP_OA_CURSO), 'rodada' => null];
    foreach ($linhas as $l) {
        if ($l['status'] !== 'enviado' || $l['entrega'] === 'falhou') {
            $m['falharam']++;
            continue;
        }
        $m['enviadas']++;
        $m['entregues'] += in_array($l['entrega'], ['entregue', 'lida'], true) ? 1 : 0;
        $m['lidas'] += $l['entrega'] === 'lida' ? 1 : 0;
        if ((string) $l['resposta'] !== '') {
            $m['responderam']++;
            $m['nao_querem'] += mcp_rw_quer_parar((string) $l['resposta']) ? 1 : 0;
        }
        $ts = mcp_rw_ts($l['criado_em']);
        $m['primeira'] = $m['primeira'] === null ? $ts : min($m['primeira'], $ts);
        $m['ultima'] = max((int) $m['ultima'], (int) $ts);
    }
    $feitos = mcp_oa_feitos();
    foreach ($lista['lista'] as $p) {
        $m['bloqueados'] += !isset($feitos[mcp_avisos_hash('whatsapp', $p['numero'])]) && mcp_avisos_bloqueado('whatsapp', $p['numero']) ? 1 : 0;
    }
    $m['faltam'] = max(0, $m['na_lista'] - count($feitos) - $m['bloqueados']);
    $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_inscricoes WHERE status = 'pago' AND curso_slug = ? AND utm_campaign = ?");
    $stmt->execute([MCP_OA_CURSO, MCP_OA_LINK]);
    $m['compras'] = (int) $stmt->fetchColumn();
    $stmt = mcp_db()->query("SELECT detalhe FROM mcp_eventos WHERE tipo = 'oferta_alunos_rodada' ORDER BY id DESC LIMIT 1");
    $rodada = json_decode((string) $stmt->fetchColumn(), true);
    $m['rodada'] = is_array($rodada) ? $rodada : null;
    return $m;
}
