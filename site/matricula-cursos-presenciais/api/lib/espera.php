<?php
/**
 * Venda sem turma (08/10/2026, spec 10.7 e 10.8): a fila da próxima turma de cada curso.
 *
 * A compra "taxa + matrícula" de um curso sem turma (ou a de uma turma que fechou antes de o PIX cair) fica paga no
 * site, com espera_status = 'aguardando' e uma data limite para a primeira aula (espera_prazo). Na escola, só a conta
 * (a escola não grava matrícula sem turma). A cada 15 minutos (api/comparecimentos.php), mcp_espera_varrer():
 *  1. freios: escola na versão 3, ESCOLA_MATRICULA_PAGA ligada, uma rodada por vez (GET_LOCK);
 *  2. procura turma, curso a curso, por ordem de pagamento (só a rotina procura: a chamada do pagamento é so_conta);
 *  3. reconsulta, uma vez por dia, quem já tem turma (turma cancelada, data mudada, transferência);
 *  4. "Turma definida" à secretaria, por curso; 5. data limite: devolução sem a pessoa pedir;
 *  6. "Ainda sem data" (30 e 60 dias) e o aviso do prazo; 7. falhas; 8. saldo da Unicopag contra a reserva.
 *
 * mcp_espera_chamar() é a única que fala com a escola por essas compras, sempre com espera_turma e inicio_ate, e nunca
 * mexe em escola_status, escola_tentativas nem escola_acesso numa falha ou num "sem turma" (T1). As transições
 * (aguardando → turma | devolver) são UPDATE condicionais: "Devolver" e uma matrícula feita pela rotina não se pisam
 * (T3). Os e-mails à pessoa são montados por email.php (mcp_montar_email_*); os avisos internos, aqui.
 */
declare(strict_types=1);

const MCP_ESPERA_ANTECEDENCIA_DIAS = 10;     // a turma da espera tem a 1ª aula pelo menos 10 dias depois (v2)
const MCP_ESPERA_FALHAS_MAX = 5;
const MCP_ESPERA_JANELA_DIAS = 7;            // "a data ou o horário não servem" (P7)
const MCP_ESPERA_BIT_30 = 1;
const MCP_ESPERA_BIT_60 = 2;
const MCP_ESPERA_BIT_PRAZO = 4;
const MCP_ESPERA_LEMBRETE_FOLGA_DIAS = 15;   // lembrete não sai quando o aviso do prazo cai em menos de 15 dias
const MCP_ESPERA_MOTIVOS_DEVOLVER = ['prazo', 'pedido', 'data_nao_serve', 'requisito', 'curso_ja_pago', 'turma_cancelada', 'secretaria'];
const MCP_ESPERA_MOTIVOS_ROTULO = [
    'nenhuma_turma' => 'nenhuma turma aberta', 'turmas_lotadas' => 'turma aberta, mas lotada', 'turma_sem_aulas' => 'turma sem as datas das aulas',
    'turma_recente' => 'turma criada há menos de 6 horas', 'turma_muito_distante' => 'turma começa depois da data limite',
    'turma_muito_proxima' => 'turma começa em menos de 10 dias', 'na_fila' => 'na fila (ainda sem consulta da rotina)',
    'turma_cancelada' => 'turma cancelada na escola',
];

/** A rotina pode chamar a escola: ESCOLA_MATRICULA_PAGA, escola configurada e a função na versão 3 (10.1, item 9). */
function mcp_espera_ligada(): bool
{
    return mcp_cfg_ligada('ESCOLA_MATRICULA_PAGA') && mcp_escola_configurada() && mcp_escola_versao() >= 3;
}

/**
 * Entra na fila (no pagamento, ou quando a escola responde matricula_paga_sem_turma numa compra com turma): espera_desde
 * = pago_em e, se a compra ainda não tinha data limite (a da venda sem turma é gravada na criação), 23h59min59s do dia
 * do pagamento + ESPERA_PRAZO_DIAS. Uma vez só.
 */
function mcp_espera_entrar(int $id): bool
{
    if (!mcp_colunas_plano_ok()) {
        return false;
    }
    $i = mcp_inscricao_por('id', (string) $id);
    if (!$i || ($i['status'] ?? '') !== 'pago' || !empty($i['espera_status'])) {
        return false;
    }
    $pago = mcp_utc_ts($i['pago_em'] ?? null) ?? time();
    $stmt = mcp_db()->prepare("UPDATE mcp_inscricoes SET espera_status = 'aguardando', espera_desde = ?, espera_prazo = COALESCE(espera_prazo, ?), atualizado_em = ?
        WHERE id = ? AND espera_status IS NULL");
    $stmt->execute([gmdate('Y-m-d H:i:s', $pago), mcp_espera_prazo($pago), mcp_agora(), $id]);
    if ($stmt->rowCount() !== 1) {
        return false;
    }
    mcp_registrar($id, 'espera_entrou', (string) $i['curso_slug']);
    mcp_espera_aviso_fila_acima($i);
    return true;
}

/**
 * A fila passou do max_fila do oferta.json (um PIX gerado há mais de 24 h e pago depois, fora da conta da venda, ou o
 * máximo reduzido no oferta.json): a compra
 * entra assim mesmo, porque já está paga, e a secretaria recebe um aviso, no máximo um por curso a cada 24 h.
 */
function mcp_espera_aviso_fila_acima(array $i): void
{
    try {
        $slug = (string) ($i['curso_slug'] ?? '');
        $lista = function_exists('mcp_oferta') ? mcp_oferta()['sem_turma'] : [];
        if (!isset($lista[$slug])) {
            return;
        }
        $maximo = (int) $lista[$slug]['max_fila'];
        $fila = mcp_espera_fila($slug, false);
        if ($fila <= $maximo || $fila === PHP_INT_MAX || mcp_contar_eventos_recentes('espera_fila_acima', $slug, 86400) > 0) {
            return;
        }
        mcp_registrar((int) $i['id'], 'espera_fila_acima', $slug);
        mcp_aviso_secretaria('Fila acima do máximo — ' . $i['curso_nome'], 'Fila acima do máximo',
            'A fila de quem pagou tudo e espera turma de <strong>' . mcp_escapar((string) $i['curso_nome']) . '</strong> está com ' . $fila
                . ' pessoas, acima do máximo de ' . $maximo . ' do oferta.json (por exemplo, um PIX gerado há mais de 24 horas e pago depois, ou o máximo reduzido no oferta.json). Todas pagaram e seguem na fila, por ordem de pagamento. '
                . 'Se a escola não tiver turma para todas, a devolução segue a regra de sempre (prazo da espera).',
            mcp_aviso_linhas($i), null, (int) $i['id']);
    } catch (Throwable $e) {
        error_log('[matricula] aviso de fila acima do máximo falhou: ' . $e->getMessage());
    }
}

/** "turma|1ª aula|horário|status da turma|status da matrícula", para achar mudança na reconsulta (cabe em 160). */
function mcp_espera_turma_dados(array $acesso): string
{
    $partes = [
        mb_substr((string) ($acesso['turma_id'] ?? ''), 0, 64), (string) ($acesso['turma_primeira_aula'] ?? $acesso['turma_inicio'] ?? ''),
        mb_substr((string) ($acesso['turma_horario'] ?? ''), 0, 40), mb_substr((string) ($acesso['turma_status'] ?? ''), 0, 20),
        mb_substr((string) ($acesso['matricula_status'] ?? ''), 0, 20),
    ];
    return mb_substr(implode('|', array_map(static fn(string $p): string => str_replace('|', '/', $p), $partes)), 0, 160);
}

function mcp_espera_turma_dados_ler(?string $dados): array
{
    $p = explode('|', (string) $dados) + ['', '', '', '', ''];
    return ['turma_id' => $p[0], 'primeira_aula' => $p[1], 'horario' => $p[2], 'turma_status' => $p[3], 'matricula_status' => $p[4]];
}

/** Início da turma (UTC) a partir da 1ª aula da escola e do horário ("18:00 - 22:00"), para "turma no futuro". */
function mcp_espera_turma_inicio(array $acesso): ?string
{
    $data = $acesso['turma_primeira_aula'] ?? $acesso['turma_inicio'] ?? null;
    if (!is_string($data) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        return null;
    }
    $hora = is_string($acesso['turma_horario'] ?? null) && preg_match('/^(\d{2}):(\d{2})/', $acesso['turma_horario'], $m) ? "$m[1]:$m[2]:00" : '00:00:00';
    try {
        return (new DateTimeImmutable("$data $hora", mcp_fuso_brt()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

/**
 * Fim da janela "a data ou o horário não servem" (P7): o mais cedo entre agora + 7 dias e a véspera da primeira aula
 * (23h59min59s em Brasília). UTC.
 */
function mcp_espera_janela(array $acesso, ?int $agora = null): string
{
    $agora ??= time();
    $fim = $agora + MCP_ESPERA_JANELA_DIAS * 86400;
    $data = $acesso['turma_primeira_aula'] ?? $acesso['turma_inicio'] ?? null;
    if (is_string($data) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        try {
            $vespera = (new DateTimeImmutable($data . ' 23:59:59', mcp_fuso_brt()))->modify('-1 day')->getTimestamp();
            $fim = min($fim, max($agora, $vespera));
        } catch (Throwable) {
        }
    }
    return gmdate('Y-m-d H:i:s', $fim);
}

/** Junta a resposta nova ao escola_acesso, sem apagar o aluno_novo, o link e o e-mail da primeira resposta. */
function mcp_espera_mesclar_acesso(array $inscricao, array $novo): array
{
    $antigo = mcp_escola_acesso($inscricao) ?? [];
    foreach (['aluno_novo', 'link', 'link_expira_em', 'email_conta', 'email_confere', 'url_login'] as $chave) {
        if (array_key_exists($chave, $antigo)) {
            $novo[$chave] = $antigo[$chave];
        }
    }
    return $novo;
}

/**
 * A chamada da rotina à escola por uma compra que espera turma (T1, T3, T8). Relê a inscrição; não chama se ela não
 * está paga, se já foi marcada para devolver, ou sem a escola na versão 3. Devolve: matriculado, sem_turma,
 * curso_ja_pago, recusa (ok:false ou HTTP 400), falha (rede, tempo esgotado, 5xx), desligada ou ignorado.
 * $soConta: a chamada logo depois do pagamento (cria a conta; não procura turma).
 */
function mcp_espera_chamar(int $id, bool $soConta = false): string
{
    $i = mcp_inscricao_por('id', (string) $id);
    if (!$i || ($i['status'] ?? '') !== 'pago' || empty($i['espera_status']) || $i['espera_status'] === 'devolver') {
        return 'ignorado';
    }
    if (!mcp_espera_ligada()) {
        return 'desligada';
    }
    $payload = mcp_escola_payload($i, mcp_escola_token($i));
    unset($payload['dados']['turma_id']);
    $payload['dados']['espera_turma'] = true;
    $payload['dados']['inicio_ate'] = mcp_espera_inicio_ate($i['espera_prazo'] ?? null) ?: mcp_espera_inicio_ate(mcp_espera_prazo(time()));
    if ($soConta) {
        $payload['dados']['so_conta'] = true;
    }
    $r = mcp_escola_post((string) mcp_cfg('ESCOLA_API_URL'), (string) json_encode($payload, JSON_UNESCAPED_UNICODE), 40);
    $dados = is_array($r['dados']) ? $r['dados'] : [];
    $agora = mcp_agora();
    $pdo = mcp_db();

    if ($r['status'] === 200 && ($dados['ok'] ?? null) === true) {
        $novo = mcp_escola_acesso_da_resposta($dados, true);
        if (in_array('curso_ja_pago', $novo['avisos'], true)) {
            // A pessoa pagou o curso na escola durante a espera: devolver esta compra (estorno total).
            $acesso = json_encode(mcp_espera_mesclar_acesso($i, $novo), JSON_UNESCAPED_UNICODE);
            $stmt = $pdo->prepare("UPDATE mcp_inscricoes SET espera_status = 'devolver', espera_devolver_motivo = 'curso_ja_pago', espera_devolver_em = ?,
                espera_consultada_em = ?, espera_falhas = 0, espera_erro = NULL, escola_status = 'ok', escola_acesso = ?, atualizado_em = ?
                WHERE id = ? AND espera_status = 'aguardando'");
            $stmt->execute([$agora, $agora, $acesso, $agora, $id]);
            if ($stmt->rowCount() === 1) {
                mcp_registrar($id, 'espera_devolver', $i['curso_slug'] . ' · curso_ja_pago');
                $atual = mcp_inscricao_por('id', (string) $id) ?? $i;
                mcp_email_aluno_pago($atual); // a variante curso_ja_pago da 1.14
                mcp_aviso_secretaria('URGENTE: devolver — 1 compra sem turma', 'Devolver: curso já pago na escola',
                    'Esta pessoa esperava turma e pagou o curso na escola durante a espera. <strong>Estorne esta compra inteira no painel da Unicopag em até 2 dias úteis.</strong> NÃO cancele a matrícula na escola: ela continua paga.',
                    mcp_aviso_linhas($atual), (string) $i['email'], $id);
            }
            return 'curso_ja_pago';
        }
        if ($novo['resultado'] === 'sem_turma') {
            $campos = ['espera_motivo' => $novo['espera_motivo'], 'espera_consultada_em' => $agora, 'espera_falhas' => 0, 'espera_erro' => null];
            $primeira = mcp_escola_acesso($i) === null;
            if ($primeira) {
                $campos['escola_acesso'] = json_encode($novo, JSON_UNESCAPED_UNICODE);
                $campos['escola_status'] = 'ok';
            }
            mcp_atualizar($id, $campos);
            if (($i['espera_motivo'] ?? null) !== $novo['espera_motivo']) {
                mcp_registrar($id, 'espera_sem_turma', (string) ($novo['espera_motivo'] ?? '?') . ($soConta ? ' · so_conta' : ''));
            }
            // A primeira chamada tinha falhado e o aluno já recebeu o e-mail sem o acesso: agora, com o acesso.
            if ($primeira && $i['email_aluno'] !== null && $i['email_aluno'] !== 'acesso') {
                mcp_email_aluno_pago(mcp_inscricao_por('id', (string) $id) ?? $i);
            }
            return 'sem_turma';
        }
        // matriculado sem a matrícula marcada como paga (spec 10.8: só "matriculado" + matricula_paga = true passa a
        // 'turma'): a compra continua na fila, a secretaria recebe o URGENTE (no máximo um por dia) e a pessoa não recebe
        // "Sua turma abriu" com uma matrícula pendente na escola.
        if (($novo['matricula_paga'] ?? null) !== true && ($i['espera_status'] ?? '') === 'aguardando') {
            $pdo->prepare("UPDATE mcp_inscricoes SET espera_falhas = LEAST(espera_falhas + 1, 255), espera_consultada_em = ?, espera_erro = 'matricula_nao_marcada',
                atualizado_em = ? WHERE id = ? AND espera_status = 'aguardando'")->execute([$agora, $agora, $id]);
            if (mcp_contar_eventos_recentes('espera_nao_marcada', (string) $id, 86400) === 0) {
                mcp_registrar($id, 'espera_nao_marcada', (string) $id);
                mcp_aviso_secretaria('URGENTE: matrícula não marcada como paga — ' . $i['nome'] . ' — ' . $i['curso_nome'], 'Matrícula não marcada como paga',
                    'A escola respondeu que matriculou esta pessoa, que pagou tudo e esperava turma, mas sem marcar a matrícula como paga. A compra continua na fila e a pessoa ainda não recebeu a data. '
                        . '<strong>Confira a matrícula ' . mcp_escapar((string) ($novo['matricula_id'] ?? '')) . ' na escola</strong> (deve ficar À vista, PAGO, Pagamento CURSO, gateway unicopag-2) e avise a TI.',
                    mcp_aviso_linhas($i), (string) $i['email'], $id);
            }
            return 'recusa';
        }
        // matriculado (a turma abriu, a pessoa já tinha matrícula pendente na escola, ou a repetição da reconsulta)
        $acesso = mcp_espera_mesclar_acesso($i, $novo);
        $stmt = $pdo->prepare("UPDATE mcp_inscricoes SET espera_status = 'turma', turma_id = ?, turma_inicio = ?, espera_turma_dados = ?,
            espera_consultada_em = ?, espera_reconsulta_em = ?, espera_falhas = 0, espera_erro = NULL, espera_motivo = NULL,
            escola_status = 'ok', escola_acesso = ?, atualizado_em = ? WHERE id = ? AND espera_status = 'aguardando'");
        $stmt->execute([$novo['turma_id'], mcp_espera_turma_inicio($novo), mcp_espera_turma_dados($novo), $agora, $agora,
            json_encode($acesso, JSON_UNESCAPED_UNICODE), $agora, $id]);
        if ($stmt->rowCount() === 1) {
            $dias = intdiv(time() - (mcp_utc_ts($i['espera_desde'] ?? null) ?? time()), 86400);
            mcp_registrar($id, 'espera_turma_definida', $i['curso_slug'] . " · $dias dias");
            mcp_espera_email_turma_aberta($id);
            return 'matriculado';
        }
        $atual = mcp_inscricao_por('id', (string) $id) ?? $i;
        if (($atual['espera_status'] ?? '') === 'devolver') {
            // "Devolver" no meio da chamada (T3): a escola matriculou (paga) quem pediu a devolução.
            $stmt = $pdo->prepare('UPDATE mcp_inscricoes SET turma_id = ?, espera_matricula_escola = ?, atualizado_em = ? WHERE id = ? AND espera_matricula_escola IS NULL');
            $stmt->execute([$novo['turma_id'], $novo['matricula_id'] ?? 'sem id', $agora, $id]);
            if ($stmt->rowCount() === 1) {
                $mid = (string) ($novo['matricula_id'] ?? '');
                mcp_registrar($id, 'espera_matricula_devolver', $mid);
                mcp_aviso_secretaria('URGENTE: cancelar a matrícula ' . $mid . ' na escola — ' . $atual['nome'], 'Cancelar a matrícula na escola',
                    'A escola matriculou esta pessoa (paga) enquanto a devolução era pedida. <strong>Cancele a matrícula ' . mcp_escapar($mid)
                        . ' na escola</strong> (Admin → aluno → matrícula → Cancelar inscrição) antes de estornar, e marque "Já cancelei na escola" na ficha.',
                    mcp_aviso_linhas($atual), (string) $atual['email'], $id);
            }
        } elseif (($atual['espera_status'] ?? '') === 'turma') {
            mcp_espera_reconsulta_aplicar($atual, $novo);
        }
        return 'matriculado';
    }

    $recusa = ($r['status'] === 200 && ($dados['ok'] ?? null) === false) || $r['status'] === 400;
    $erro = $recusa ? mb_substr((string) ($dados['erro'] ?? (str_starts_with((string) ($dados['message'] ?? ''), 'dados inválidos') ? 'dados_invalidos' : 'recusado')), 0, 40) : null;
    $pdo->prepare('UPDATE mcp_inscricoes SET espera_falhas = LEAST(espera_falhas + 1, 255), espera_consultada_em = ?, espera_erro = COALESCE(?, espera_erro),
        atualizado_em = ? WHERE id = ?')->execute([$agora, $erro, $agora, $id]);
    if ((int) $i['espera_falhas'] === 0 || $recusa) {
        mcp_registrar($id, 'espera_falha', 'HTTP ' . $r['status'] . ($erro !== null ? " · $erro" : '') . ($r['erro'] !== '' ? ' · ' . mb_substr($r['erro'], 0, 80) : ''));
    }
    return $recusa ? 'recusa' : 'falha';
}

/**
 * Reconsulta diária de quem já tem turma (F2, T4): compara a resposta com espera_turma_dados. Turma CANCELADA ou
 * matrícula cancelada na escola: e-mail "Turma cancelada", URGENTE e 7 dias para a pessoa escolher (sem resposta,
 * devolver). Outra turma, 1ª aula ou horário: e-mail "Sua turma mudou", URGENTE e nova janela. Igual: só a data.
 */
function mcp_espera_reconsulta_aplicar(array $i, array $novo): void
{
    $id = (int) $i['id'];
    $agora = mcp_agora();
    $antes = mcp_espera_turma_dados_ler($i['espera_turma_dados'] ?? null);
    $dados = mcp_espera_turma_dados($novo);
    $depois = mcp_espera_turma_dados_ler($dados);
    $cancelada = $depois['turma_status'] === 'CANCELADA' || in_array($depois['matricula_status'], ['CANCELADO', 'ESTORNADO'], true);
    $base = ['espera_reconsulta_em' => $agora, 'espera_consultada_em' => $agora, 'espera_falhas' => 0, 'espera_erro' => null];
    if ($cancelada) {
        if (($i['espera_motivo'] ?? null) === 'turma_cancelada') {
            mcp_atualizar($id, $base);
            return;
        }
        $limite = time() + MCP_ESPERA_JANELA_DIAS * 86400;
        mcp_atualizar($id, $base + ['espera_motivo' => 'turma_cancelada', 'espera_janela_ate' => gmdate('Y-m-d H:i:s', $limite), 'espera_turma_dados' => $dados]);
        mcp_registrar($id, 'espera_turma_cancelada', (string) $i['curso_slug']);
        $atual = mcp_inscricao_por('id', (string) $id) ?? $i;
        mcp_espera_email_pessoa('turma_cancelada', $atual, [(new DateTimeImmutable('@' . $limite))->setTimezone(mcp_fuso_brt())->format('d/m')]);
        $o_que = $depois['turma_status'] === 'CANCELADA' ? 'turma cancelada' : 'matrícula cancelada na escola';
        mcp_aviso_secretaria('URGENTE: turma de quem esperava mudou — ' . $i['curso_nome'], 'Turma de quem esperava mudou',
            'A reconsulta diária achou: ' . $o_que . ', para ' . mcp_escapar((string) $i['nome']) . ' — turma ' . mcp_escapar($antes['turma_id'])
                . ' — ' . mcp_escola_data($antes['primeira_aula']) . '. A pessoa recebeu o e-mail com a escolha. Se ela escolher outra turma, transfira a matrícula na escola (Admin → inscrição → Transferir): o site percebe e manda a nova data.',
            mcp_aviso_linhas($atual), (string) $i['email'], $id);
        return;
    }
    $mudou = $depois['turma_id'] !== $antes['turma_id'] || $depois['primeira_aula'] !== $antes['primeira_aula'] || $depois['horario'] !== $antes['horario'];
    if (!$mudou && ($i['espera_motivo'] ?? null) !== 'turma_cancelada') {
        mcp_atualizar($id, $base + ['espera_turma_dados' => $dados]);
        return;
    }
    $acesso = mcp_espera_mesclar_acesso($i, $novo);
    mcp_atualizar($id, $base + [
        'turma_id' => $novo['turma_id'], 'turma_inicio' => mcp_espera_turma_inicio($novo), 'espera_turma_dados' => $dados,
        'espera_motivo' => null, 'espera_janela_ate' => mcp_espera_janela($novo), 'escola_acesso' => json_encode($acesso, JSON_UNESCAPED_UNICODE),
    ]);
    mcp_registrar($id, 'espera_turma_mudou', (string) $i['curso_slug']);
    $atual = mcp_inscricao_por('id', (string) $id) ?? $i;
    mcp_espera_email_pessoa('turma_mudou', $atual, [$antes]);
    mcp_aviso_secretaria('URGENTE: turma de quem esperava mudou — ' . $i['curso_nome'], 'Turma de quem esperava mudou',
        'A reconsulta diária achou: data mudada, para ' . mcp_escapar((string) $i['nome']) . ' — turma ' . mcp_escapar($depois['turma_id'])
            . ' — ' . mcp_escola_data($depois['primeira_aula']) . ' (antes: ' . (mcp_escola_data($antes['primeira_aula']) ?: '?')
            . '). A pessoa recebeu o e-mail com a nova data e pode pedir a devolução até ' . mcp_data_brt((string) $atual['espera_janela_ate'], 'd/m') . '.',
        mcp_aviso_linhas($atual), (string) $i['email'], $id);
}

/**
 * "Sua turma abriu" (uma vez por inscrição, venha a resposta de onde vier; T8): reserva em espera_turma_email_em,
 * grava a janela da "data não serve" contada do envio e desfaz a reserva se o envio falhar (a rodada seguinte tenta).
 */
function mcp_espera_email_turma_aberta(int $id): bool
{
    $pdo = mcp_db();
    $stmt = $pdo->prepare("UPDATE mcp_inscricoes SET espera_turma_email_em = ? WHERE id = ? AND espera_turma_email_em IS NULL AND espera_status = 'turma'");
    $stmt->execute([mcp_agora(), $id]);
    if ($stmt->rowCount() !== 1) {
        return false;
    }
    $i = mcp_inscricao_por('id', (string) $id);
    $acesso = $i ? mcp_escola_acesso($i) : null;
    if ($i && $acesso) {
        mcp_atualizar($id, ['espera_janela_ate' => mcp_espera_janela($acesso)]);
        $i = mcp_inscricao_por('id', (string) $id) ?? $i;
    }
    if ($i && mcp_espera_email_pessoa('turma_aberta', $i)) {
        return true;
    }
    $pdo->prepare('UPDATE mcp_inscricoes SET espera_turma_email_em = NULL WHERE id = ?')->execute([$id]);
    return false;
}

/**
 * E-mail da espera à pessoa, montado por email.php (mcp_montar_email_{tipo}). Sem o modelo (email.php ainda sem ele),
 * não envia e devolve false: quem reservou desfaz e a rodada seguinte tenta de novo.
 */
function mcp_espera_email_pessoa(string $tipo, array $i, array $args = []): bool
{
    static $avisado = [];
    $funcao = 'mcp_montar_email_' . $tipo;
    if (!function_exists($funcao)) {
        if (!isset($avisado[$tipo])) {
            $avisado[$tipo] = true;
            error_log("[matricula] e-mail da espera sem modelo em email.php: $funcao");
        }
        return false;
    }
    try {
        $m = $funcao($i, ...$args);
    } catch (Throwable $e) {
        error_log("[matricula] e-mail $tipo não montado (inscrição " . (int) $i['id'] . '): ' . get_class($e) . ': ' . $e->getMessage());
        return false;
    }
    $r = mcp_enviar_email((string) $i['email'], (string) $m['assunto'], (string) $m['html'], (string) $m['texto']);
    mcp_registrar((int) $i['id'], 'email_' . mb_substr($tipo, 0, 30), $r);
    return $r !== 'falhou';
}

// ----------------------------------------------------------------------------- a rotina
/**
 * A rodada de 15 minutos (10.8). Devolve as contagens. Os passos 5 a 8 rodam mesmo sem a escola (eles não a chamam):
 * a data limite continua correndo e a devolução continua garantida.
 */
function mcp_espera_varrer(?int $agora = null): array
{
    $agora ??= time();
    $r = ['rodou' => false, 'chamadas' => 0, 'matriculados' => 0, 'reconsultas' => 0, 'devolver' => 0, 'lembretes' => 0, 'falha_rede' => false];
    if (!mcp_colunas_plano_ok()) {
        return $r;
    }
    $pdo = mcp_db();
    if ((int) $pdo->query("SELECT GET_LOCK('mcp_espera', 0)")->fetchColumn() !== 1) {
        return $r + ['ocupada' => true];
    }
    try {
        $r['rodou'] = true;
        $agoraSql = gmdate('Y-m-d H:i:s', $agora);
        $lote = max(1, (int) mcp_cfg('ESPERA_LOTE', 30));
        $matriculados = [];
        if (mcp_espera_ligada()) {
            // 2. Procura de turma, curso a curso: primeiro o de quem espera há mais tempo. Sem filtro de escola_status (T1).
            $cursos = $pdo->query("SELECT curso_slug, MIN(pago_em) AS desde FROM mcp_inscricoes
                WHERE status = 'pago' AND plano = 'taxa_e_matricula' AND espera_status = 'aguardando'
                GROUP BY curso_slug ORDER BY desde")->fetchAll(PDO::FETCH_COLUMN);
            $fila = $pdo->prepare("SELECT id FROM mcp_inscricoes WHERE status = 'pago' AND plano = 'taxa_e_matricula' AND espera_status = 'aguardando'
                AND curso_slug = ? AND (espera_falhas < ? OR espera_consultada_em IS NULL OR espera_consultada_em < ?) ORDER BY pago_em, id");
            foreach ($cursos as $slug) {
                $fila->execute([$slug, MCP_ESPERA_FALHAS_MAX, gmdate('Y-m-d H:i:s', $agora - 6 * 3600)]);
                foreach ($fila->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    if ($r['chamadas'] >= $lote) {
                        break 2;
                    }
                    $resultado = mcp_espera_chamar((int) $id);
                    $r['chamadas']++;
                    if ($resultado === 'matriculado') {
                        $matriculados[(string) $slug][] = (int) $id;
                        $r['matriculados']++;
                    } elseif ($resultado === 'sem_turma') {
                        break; // os outros da fila teriam a mesma resposta
                    } elseif ($resultado === 'falha') {
                        $r['falha_rede'] = true;
                        break 2; // a escola não respondeu: para só a procura e a reconsulta (T14)
                    }
                }
            }
            // 3. Reconsulta diária de quem já tem turma, até completar o lote.
            if (!$r['falha_rede'] && $r['chamadas'] < $lote) {
                $stmt = $pdo->prepare("SELECT id FROM mcp_inscricoes WHERE status = 'pago' AND plano = 'taxa_e_matricula' AND espera_status = 'turma'
                    AND turma_inicio > ? AND (espera_reconsulta_em IS NULL OR espera_reconsulta_em < ?) ORDER BY espera_reconsulta_em, id LIMIT " . ($lote - $r['chamadas']));
                $stmt->execute([$agoraSql, gmdate('Y-m-d H:i:s', $agora - 86400)]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $resultado = mcp_espera_chamar((int) $id);
                    $r['chamadas']++;
                    $r['reconsultas']++;
                    if ($resultado === 'falha') {
                        $r['falha_rede'] = true;
                        break;
                    }
                }
            }
        } else {
            mcp_espera_sem_escola($agora);
        }
        // "Sua turma abriu" que falhou numa rodada anterior.
        $stmt = $pdo->prepare("SELECT id FROM mcp_inscricoes WHERE status = 'pago' AND espera_status = 'turma' AND espera_turma_email_em IS NULL
            AND turma_inicio > ? ORDER BY id LIMIT 20");
        $stmt->execute([$agoraSql]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            mcp_espera_email_turma_aberta((int) $id);
        }
        // 4. "Turma definida" à secretaria, por curso que teve matrícula nesta rodada.
        foreach ($matriculados as $slug => $ids) {
            mcp_espera_aviso_turma_definida((string) $slug, $ids);
        }
        // 5. Data limite (depois da procura: uma turma marcada no último dia ainda vale) e turma cancelada sem resposta.
        $r['devolver'] = mcp_espera_devolver_vencidas($agora);
        // 6. E-mails da espera.
        $r['lembretes'] = mcp_espera_lembretes($agora);
        // 7. Falhas: um URGENTE por dia, no máximo.
        mcp_espera_aviso_falhas($agora);
        // 8. Saldo da Unicopag contra a reserva: uma vez por dia.
        mcp_espera_conferir_saldo($agora);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('mcp_espera')");
    }
    return $r;
}

/** Sem a escola na versão 3 (ou desligada) e com gente na fila: registra espera_sem_escola uma vez por hora. */
function mcp_espera_sem_escola(int $agora): void
{
    $n = (int) mcp_db()->query("SELECT COUNT(*) FROM mcp_inscricoes WHERE status = 'pago' AND espera_status IN ('aguardando','turma')")->fetchColumn();
    if ($n === 0) {
        return;
    }
    $ultima = mcp_compra_chave_ler('espera_sem_escola_em');
    if ($ultima !== null && $agora - (int) mcp_utc_ts($ultima['criado_em']) < 3600) {
        return;
    }
    mcp_compra_chave_gravar('espera_sem_escola_em', '1');
    mcp_registrar(null, 'espera_sem_escola', "$n compras na espera; a rotina não chama a escola (versão < 3 ou ESCOLA_MATRICULA_PAGA desligada)");
}

/** Aviso "Turma definida" (F5, F10, T12): quem entrou, quem continua na fila e a lista de interesse (só a taxa). */
function mcp_espera_aviso_turma_definida(string $slug, array $ids): void
{
    $pdo = mcp_db();
    $grupos = [];
    foreach ($ids as $id) {
        $i = mcp_inscricao_por('id', (string) $id);
        if ($i) {
            $grupos[(string) ($i['turma_id'] ?? '')][] = $i;
        }
    }
    foreach ($grupos as $lista) {
        $primeira = $lista[0];
        $acesso = mcp_escola_acesso($primeira) ?? [];
        $data = mcp_escola_data_turma($acesso) ?: '?';
        $pessoas = implode('<br>', array_map(static fn(array $i): string => mcp_escapar($i['nome'] . ' — ' . mcp_data_brt((string) $i['pago_em'], 'd/m/Y') . ' — ' . mcp_brl(mcp_total_cobrado($i))), $lista));
        $resto = $pdo->prepare("SELECT espera_motivo, COUNT(*) AS n FROM mcp_inscricoes WHERE status = 'pago' AND plano = 'taxa_e_matricula' AND espera_status = 'aguardando' AND curso_slug = ? GROUP BY espera_motivo");
        $resto->execute([$slug]);
        $continuam = 0;
        $motivos = [];
        foreach ($resto->fetchAll() as $l) {
            $continuam += (int) $l['n'];
            $motivos[] = MCP_ESPERA_MOTIVOS_ROTULO[$l['espera_motivo'] ?? ''] ?? 'sem motivo da escola';
        }
        $interesse = $pdo->prepare("SELECT nome, email, pago_em FROM mcp_inscricoes WHERE status = 'pago' AND plano = 'so_taxa' AND curso_slug = ?
            AND pago_em > ? AND (escola_acesso IS NULL OR escola_acesso NOT LIKE '%\"resultado\":\"matriculado\"%') ORDER BY pago_em LIMIT 200");
        $interesse->execute([$slug, gmdate('Y-m-d H:i:s', time() - 365 * 86400)]);
        $listaInteresse = array_map(static fn(array $l): string => mcp_escapar($l['nome'] . ' — ' . $l['email'] . ' — ' . mcp_data_brt((string) $l['pago_em'], 'd/m/Y')), $interesse->fetchAll());
        $mes = mcp_data_brt((string) $primeira['pago_em'], 'm/Y');
        $uma = count($lista) === 1;
        $html = 'O site matriculou ' . count($lista) . ($uma ? ' pessoa que tinha pago tudo e esperava turma' : ' pessoas que tinham pago tudo e esperavam turma') . ', na turma de ' . $data . ':<br>' . $pessoas
            . '<br><br>' . ($uma ? 'Ela recebeu' : 'Elas receberam') . ' o e-mail com a data. Continuam na fila: ' . $continuam . ($motivos ? ' (' . mcp_escapar(implode('; ', array_unique($motivos))) . ')' : '') . '.'
            . '<br><br>Pagaram só a taxa e estão na lista de interesse, por ordem de pagamento:<br>' . ($listaInteresse ? implode('<br>', $listaInteresse) : 'ninguém')
            . '<br>Avise a cada uma como pagar a matrícula enquanto houver vaga.'
            . '<br><br>O Financeiro da escola registra estes pagamentos no dia em que foram pagos (' . $mes . '): se esse mês já foi fechado, ajuste. Quando a turma atingir o mínimo, marque "Turma confirmada" no painel.';
        mcp_aviso_secretaria('Turma definida para quem esperava: ' . $primeira['curso_nome'] . ', ' . $data, 'Turma definida para quem esperava', $html, [], null, null);
    }
}

/** Passo 5: data limite vencida (já não dá para marcar a turma a tempo) e turma cancelada sem resposta: devolver. */
function mcp_espera_devolver_vencidas(int $agora): int
{
    $pdo = mcp_db();
    $agoraSql = gmdate('Y-m-d H:i:s', $agora);
    $candidatas = [];
    $stmt = $pdo->prepare("SELECT id FROM mcp_inscricoes WHERE espera_status = 'aguardando' AND status = 'pago' AND espera_prazo IS NOT NULL
        AND espera_prazo < ? ORDER BY pago_em LIMIT 100");
    $stmt->execute([gmdate('Y-m-d H:i:s', $agora + MCP_ESPERA_ANTECEDENCIA_DIAS * 86400)]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $candidatas[] = [(int) $id, 'prazo', 'aguardando'];
    }
    $stmt = $pdo->prepare("SELECT id FROM mcp_inscricoes WHERE espera_status = 'turma' AND status = 'pago' AND espera_motivo = 'turma_cancelada'
        AND espera_janela_ate IS NOT NULL AND espera_janela_ate < ? ORDER BY pago_em LIMIT 100");
    $stmt->execute([$agoraSql]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $candidatas[] = [(int) $id, 'turma_cancelada', 'turma'];
    }
    $feitas = [];
    $trava = $pdo->prepare('UPDATE mcp_inscricoes SET espera_status = \'devolver\', espera_devolver_motivo = ?, espera_devolver_em = ?, atualizado_em = ? WHERE id = ? AND espera_status = ?');
    foreach ($candidatas as [$id, $motivo, $de]) {
        $trava->execute([$motivo, $agoraSql, $agoraSql, $id, $de]);
        if ($trava->rowCount() !== 1) {
            continue;
        }
        $i = mcp_inscricao_por('id', (string) $id);
        if (!$i) {
            continue;
        }
        mcp_registrar($id, 'espera_devolver', $i['curso_slug'] . " · $motivo");
        mcp_espera_email_pessoa('espera_devolucao', $i, [$motivo]);
        $feitas[] = $i;
    }
    if ($feitas) {
        $rotulos = ['prazo' => 'data limite', 'turma_cancelada' => 'turma cancelada sem resposta'];
        $linhas = implode('<br>', array_map(static fn(array $i): string => mcp_escapar($i['nome'] . ' — ' . $i['curso_nome'] . ' — ' . mcp_brl(mcp_total_cobrado($i))
            . ' — ' . mcp_data_brt((string) $i['pago_em'], 'd/m/Y') . ' — ' . ($rotulos[$i['espera_devolver_motivo']] ?? $i['espera_devolver_motivo'])
            . ' — ' . ($i['metodo'] === 'pix' ? 'PIX' : 'cartão')), $feitas));
        mcp_aviso_secretaria('URGENTE: devolver — ' . count($feitas) . ' compra' . (count($feitas) === 1 ? '' : 's') . ' sem turma', 'Devolver compras sem turma',
            'Estas compras passaram da data limite, a turma foi cancelada sem resposta, ou a pessoa pediu a devolução. <strong>Estorne cada uma no painel da Unicopag em até 2 dias úteis</strong> (o PIX só pode ser devolvido até 90 dias depois do pagamento; depois, transferência para uma conta no nome da pessoa):<br>' . $linhas);
    }
    return count($feitas);
}

/**
 * Passo 6: "Ainda sem data" aos 30 e aos 60 dias de espera (não sai quando o aviso do prazo cai em menos de 15 dias) e
 * o aviso do prazo 10 dias antes do último dia de marcar a turma. Cada um uma vez só (bit com trava), no máximo um por
 * pessoa e por rodada; envio que falha desfaz o bit.
 */
function mcp_espera_lembretes(int $agora): int
{
    $pdo = mcp_db();
    $stmt = $pdo->query("SELECT id, espera_desde, espera_prazo, espera_avisos_enviados FROM mcp_inscricoes
        WHERE espera_status = 'aguardando' AND status = 'pago' AND espera_desde IS NOT NULL AND espera_prazo IS NOT NULL ORDER BY pago_em LIMIT 500");
    $enviados = 0;
    foreach ($stmt->fetchAll() as $l) {
        if ($enviados >= 50) {
            break;
        }
        $desde = (int) mcp_utc_ts($l['espera_desde']);
        $prazo = (int) mcp_utc_ts($l['espera_prazo']);
        $marcarAte = $prazo - MCP_ESPERA_ANTECEDENCIA_DIAS * 86400;
        $avisoPrazo = $marcarAte - 10 * 86400;
        $bits = (int) $l['espera_avisos_enviados'];
        // [bit que marca este aviso, bits a gravar, variante]. O de 60 dias marca também o de 30 (um só por rodada).
        $escolha = null;
        if ($agora >= $avisoPrazo && $agora < $marcarAte && !($bits & MCP_ESPERA_BIT_PRAZO)) {
            $escolha = [MCP_ESPERA_BIT_PRAZO, MCP_ESPERA_BIT_PRAZO, 'prazo'];
        } elseif ($avisoPrazo - $agora >= MCP_ESPERA_LEMBRETE_FOLGA_DIAS * 86400) {
            if ($agora >= $desde + 60 * 86400 && !($bits & MCP_ESPERA_BIT_60)) {
                $escolha = [MCP_ESPERA_BIT_60, MCP_ESPERA_BIT_60 | MCP_ESPERA_BIT_30, '60'];
            } elseif ($agora >= $desde + 30 * 86400 && !($bits & MCP_ESPERA_BIT_30)) {
                $escolha = [MCP_ESPERA_BIT_30, MCP_ESPERA_BIT_30, '30'];
            }
        }
        if ($escolha === null) {
            continue;
        }
        [$marca, $gravar, $variante] = $escolha;
        $trava = $pdo->prepare("UPDATE mcp_inscricoes SET espera_avisos_enviados = espera_avisos_enviados | ? WHERE id = ? AND (espera_avisos_enviados & ?) = 0 AND espera_status = 'aguardando'");
        $trava->execute([$gravar, (int) $l['id'], $marca]);
        if ($trava->rowCount() !== 1) {
            continue;
        }
        $i = mcp_inscricao_por('id', (string) $l['id']);
        if ($i && mcp_espera_email_pessoa('espera_lembrete', $i, [$variante])) {
            $enviados++;
            continue;
        }
        // Envio que falhou (ou sem o modelo em email.php): desfaz só os bits que esta rodada gravou.
        $novos = $gravar & ~$bits & 255;
        $pdo->prepare('UPDATE mcp_inscricoes SET espera_avisos_enviados = espera_avisos_enviados & ? WHERE id = ?')->execute([255 - $novos, (int) $l['id']]);
    }
    return $enviados;
}

/** Passo 7: um URGENTE por dia, no máximo, com as compras de 5+ falhas seguidas ou recusadas pela escola. */
function mcp_espera_aviso_falhas(int $agora): void
{
    $stmt = mcp_db()->prepare("SELECT * FROM mcp_inscricoes WHERE espera_status = 'aguardando' AND status = 'pago' AND (espera_falhas >= ? OR espera_erro IS NOT NULL) ORDER BY pago_em LIMIT 100");
    $stmt->execute([MCP_ESPERA_FALHAS_MAX]);
    $linhas = $stmt->fetchAll();
    if (!$linhas) {
        return;
    }
    $ultima = mcp_compra_chave_ler('espera_alerta_em');
    if ($ultima !== null && $agora - (int) mcp_utc_ts($ultima['criado_em']) < 86400) {
        return;
    }
    mcp_compra_chave_gravar('espera_alerta_em', (string) count($linhas));
    $erros = array_values(array_unique(array_filter(array_map(static fn(array $l): ?string => $l['espera_erro'], $linhas))));
    $lista = implode('<br>', array_map(static fn(array $l): string => mcp_escapar($l['nome'] . ' — ' . $l['curso_nome'] . ' — ' . (int) $l['espera_falhas'] . ' falhas'
        . ($l['espera_erro'] ? ' — ' . $l['espera_erro'] : '')), $linhas));
    mcp_aviso_secretaria('URGENTE: a escola não responde à rotina da espera', 'A escola não responde à rotina da espera',
        count($linhas) . ' compra' . (count($linhas) === 1 ? '' : 's') . ' sem turma ' . (count($linhas) === 1 ? 'está' : 'estão') . ' com 5 ou mais tentativas seguidas sem resposta da escola, ou com recusa'
            . ($erros ? ' (' . mcp_escapar(implode(', ', $erros)) . ')' : '') . '. Confira a escola e avise a TI. As pessoas continuam na fila, e a data limite continua correndo. Curso inativo na escola: marque "Devolver" para a fila do curso.<br><br>' . $lista);
}

/** A reserva para devoluções (F4): o pago por quem espera turma ou ainda não teve a primeira aula. */
function mcp_espera_reserva(?int $agora = null): int
{
    if (!mcp_colunas_plano_ok()) {
        return 0;
    }
    $stmt = mcp_db()->prepare("SELECT COALESCE(SUM(COALESCE(total_cobrado_centavos, total_centavos)), 0) FROM mcp_inscricoes WHERE status = 'pago' AND plano = 'taxa_e_matricula'
        AND (espera_status = 'aguardando' OR (espera_status = 'turma' AND turma_inicio > ?))");
    $stmt->execute([gmdate('Y-m-d H:i:s', $agora ?? time())]);
    return (int) $stmt->fetchColumn();
}

/** Saldo disponível na resposta de GET /public/v1/balance (formato não documentado: aceita os nomes prováveis). */
function mcp_espera_saldo_ler(array $resposta): ?int
{
    foreach ([$resposta, is_array($resposta['data'] ?? null) ? $resposta['data'] : []] as $nivel) {
        foreach (['available', 'available_balance', 'balance', 'amount', 'saldo'] as $chave) {
            if (isset($nivel[$chave]) && is_numeric($nivel[$chave])) {
                return (int) round((float) $nivel[$chave]);
            }
            if (isset($nivel[$chave]) && is_array($nivel[$chave]) && is_numeric($nivel[$chave]['amount'] ?? null)) {
                return (int) round((float) $nivel[$chave]['amount']);
            }
        }
    }
    return null;
}

/** Passo 8 (F4): uma vez por dia, saldo da Unicopag (só leitura) contra a reserva; abaixo, URGENTE. */
function mcp_espera_conferir_saldo(int $agora): void
{
    $reserva = mcp_espera_reserva($agora);
    if ($reserva <= 0) {
        return;
    }
    $ultima = mcp_compra_chave_ler('espera_saldo_em');
    if ($ultima !== null && $agora - (int) mcp_utc_ts($ultima['criado_em']) < 86400) {
        return;
    }
    mcp_compra_chave_gravar('espera_saldo_em', '1');
    try {
        $saldo = mcp_espera_saldo_ler(mcp_unicopag('GET', '/public/v1/balance', null, null, 15));
    } catch (McpUnicopagErro $e) {
        mcp_registrar(null, 'espera_saldo_falhou', $e->status . ' ' . mb_substr($e->getMessage(), 0, 120));
        return;
    }
    if ($saldo === null) {
        mcp_registrar(null, 'espera_saldo_falhou', 'formato desconhecido');
        return;
    }
    mcp_compra_chave_gravar('espera_saldo', $saldo . '|' . $reserva);
    if ($saldo < $reserva) {
        mcp_aviso_secretaria('URGENTE: saldo da Unicopag abaixo da reserva das compras sem turma', 'Saldo abaixo da reserva',
            'O saldo disponível na Unicopag (' . mcp_brl($saldo) . ') está abaixo do total pago por quem espera turma ou ainda não teve a primeira aula (' . mcp_brl($reserva)
                . '). Não saque abaixo da reserva: as devoluções da espera saem desse saldo.');
    }
}

/** O último saldo lido pela rotina: ['saldo' => centavos, 'reserva' => centavos, 'lido_em' => UTC], ou null. */
function mcp_espera_saldo(): ?array
{
    $l = mcp_compra_chave_ler('espera_saldo');
    if ($l === null || !preg_match('/^(-?\d+)\|(\d+)$/', (string) $l['valor'], $m)) {
        return null;
    }
    return ['saldo' => (int) $m[1], 'reserva' => (int) $m[2], 'lido_em' => $l['criado_em']];
}

// ----------------------------------------------------------------------------- ações da secretaria (10.8; o painel liga os botões)
/** "Devolver": aguardando ou turma → devolver, com o motivo; para a rotina e manda "Pedido de devolução recebido". */
function mcp_espera_devolver(int $id, string $motivo, string $quem): bool
{
    if (!in_array($motivo, ['pedido', 'data_nao_serve', 'requisito', 'secretaria'], true) || !mcp_colunas_plano_ok()) {
        return false;
    }
    $agora = mcp_agora();
    $stmt = mcp_db()->prepare("UPDATE mcp_inscricoes SET espera_status = 'devolver', espera_devolver_motivo = ?, espera_devolver_em = ?, atualizado_em = ?
        WHERE id = ? AND status = 'pago' AND espera_status IN ('aguardando','turma')");
    $stmt->execute([$motivo, $agora, $agora, $id]);
    if ($stmt->rowCount() !== 1) {
        return false;
    }
    $i = mcp_inscricao_por('id', (string) $id);
    mcp_registrar($id, 'espera_devolver', ($i['curso_slug'] ?? '') . " · $motivo · " . mb_substr($quem, 0, 120));
    if ($i) {
        mcp_espera_email_pessoa('espera_devolucao', $i, [$motivo]);
    }
    return true;
}

/**
 * "Continua esperando" só uma vez (F11), com aguardando ou com devolver por data limite ainda sem estorno. No cartão,
 * só com o limite de estorno informado pela Unicopag (ESTORNO_CARTAO_LIMITE_DIAS) e a nova data dentro dele.
 */
function mcp_espera_pode_prorrogar(array $i): bool
{
    if (($i['status'] ?? '') !== 'pago' || (int) ($i['espera_prorrogada'] ?? 0) !== 0
        || !(($i['espera_status'] ?? '') === 'aguardando' || (($i['espera_status'] ?? '') === 'devolver' && ($i['espera_devolver_motivo'] ?? '') === 'prazo'))) {
        return false;
    }
    if (($i['metodo'] ?? '') === 'cartao') {
        $limite = (int) mcp_cfg('ESTORNO_CARTAO_LIMITE_DIAS', 0);
        $pago = mcp_utc_ts($i['pago_em'] ?? null);
        $novo = mcp_utc_ts($i['espera_prazo'] ?? null);
        if ($limite <= 0 || $pago === null || $novo === null) {
            return false;
        }
        return $novo + mcp_espera_prazo_dias() * 86400 <= $pago + $limite * 86400;
    }
    return true;
}

/**
 * "Continua esperando": soma ESPERA_PRAZO_DIAS à data limite, volta a aguardando, espera_prorrogada = 1, zera o bit do
 * aviso do prazo e manda "Você continua na fila". O id da mensagem da pessoa ("Quero continuar") e o sha256 do texto
 * vão para mcp_eventos (espera_prorrogada).
 */
function mcp_espera_prorrogar(int $id, string $mensagemId, string $textoMensagem, string $quem): bool
{
    $i = mcp_inscricao_por('id', (string) $id);
    if (!$i || !mcp_colunas_plano_ok() || !mcp_espera_pode_prorrogar($i) || trim($mensagemId) === '') {
        return false;
    }
    $novo = gmdate('Y-m-d H:i:s', (int) mcp_utc_ts($i['espera_prazo']) + mcp_espera_prazo_dias() * 86400);
    $stmt = mcp_db()->prepare("UPDATE mcp_inscricoes SET espera_status = 'aguardando', espera_prazo = ?, espera_prorrogada = 1, espera_devolver_motivo = NULL,
        espera_devolver_em = NULL, espera_avisos_enviados = espera_avisos_enviados & ?, atualizado_em = ?
        WHERE id = ? AND status = 'pago' AND espera_prorrogada = 0 AND (espera_status = 'aguardando' OR (espera_status = 'devolver' AND espera_devolver_motivo = 'prazo'))");
    $stmt->execute([$novo, 255 - MCP_ESPERA_BIT_PRAZO, mcp_agora(), $id]);
    if ($stmt->rowCount() !== 1) {
        return false;
    }
    mcp_registrar($id, 'espera_prorrogada', 'mensagem ' . mb_substr(trim($mensagemId), 0, 120) . ' · sha256 ' . hash('sha256', $textoMensagem) . ' · ' . mb_substr($quem, 0, 120));
    mcp_espera_email_pessoa('espera_prorrogada', mcp_inscricao_por('id', (string) $id) ?? $i);
    return true;
}

/**
 * "Turma confirmada" (F10): grava a confirmação em todas as compras "taxa + matrícula" pagas daquela turma, as que vieram
 * da fila e as compradas já com a data, e manda o e-mail a cada uma. É o e-mail que define "turma confirmada" em
 * /reembolso/ (a desistência passa a seguir a regra B): sem ele, quem comprou com data nunca teria a turma confirmada.
 */
function mcp_espera_turma_confirmada(string $turmaId, string $quem): int
{
    if ($turmaId === '' || !mcp_colunas_plano_ok()) {
        return 0;
    }
    $stmt = mcp_db()->prepare("SELECT id FROM mcp_inscricoes WHERE status = 'pago' AND plano = 'taxa_e_matricula' AND turma_id = ?
        AND (espera_status IS NULL OR espera_status = 'turma') AND espera_turma_confirmada_em IS NULL");
    $stmt->execute([$turmaId]);
    $n = 0;
    $trava = mcp_db()->prepare('UPDATE mcp_inscricoes SET espera_turma_confirmada_em = ?, atualizado_em = ? WHERE id = ? AND espera_turma_confirmada_em IS NULL');
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $agora = mcp_agora();
        $trava->execute([$agora, $agora, (int) $id]);
        if ($trava->rowCount() !== 1) {
            continue;
        }
        $n++;
        mcp_registrar((int) $id, 'espera_turma_confirmada', mb_substr($turmaId . ' · ' . $quem, 0, 200));
        if ($i = mcp_inscricao_por('id', (string) $id)) {
            mcp_espera_email_pessoa('turma_confirmada', $i);
        }
    }
    return $n;
}

/** "Já cancelei na escola" (com espera_matricula_escola): registra e libera o estorno. */
function mcp_espera_cancelada_na_escola(int $id, string $quem): bool
{
    if (!mcp_colunas_plano_ok()) {
        return false;
    }
    $stmt = mcp_db()->prepare('UPDATE mcp_inscricoes SET escola_resolvido_em = ?, atualizado_em = ? WHERE id = ? AND espera_matricula_escola IS NOT NULL AND escola_resolvido_em IS NULL');
    $agora = mcp_agora();
    $stmt->execute([$agora, $agora, $id]);
    if ($stmt->rowCount() !== 1) {
        return false;
    }
    mcp_registrar($id, 'espera_cancelada_na_escola', mb_substr($quem, 0, 120));
    return true;
}

/** "Já resolvi na escola" (F15): some o selo de erro/alerta do plano completo e sai do filtro "Precisam de atenção". */
function mcp_escola_resolvido(int $id, string $quem): bool
{
    if (!mcp_colunas_plano_ok()) {
        return false;
    }
    $agora = mcp_agora();
    $stmt = mcp_db()->prepare('UPDATE mcp_inscricoes SET escola_resolvido_em = ?, atualizado_em = ? WHERE id = ? AND escola_resolvido_em IS NULL');
    $stmt->execute([$agora, $agora, $id]);
    if ($stmt->rowCount() !== 1) {
        return false;
    }
    mcp_registrar($id, 'escola_resolvido', mb_substr($quem, 0, 120));
    return true;
}

// ----------------------------------------------------------------------------- consultas do painel (10.9)
/** Posição na fila do curso (1 = a primeira), só para quem está aguardando. */
function mcp_espera_posicao(array $i): ?int
{
    if (($i['espera_status'] ?? '') !== 'aguardando' || ($i['status'] ?? '') !== 'pago') {
        return null;
    }
    $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_inscricoes WHERE status = 'pago' AND plano = 'taxa_e_matricula' AND espera_status = 'aguardando'
        AND curso_slug = ? AND (pago_em < ? OR (pago_em = ? AND id <= ?))");
    $stmt->execute([$i['curso_slug'], $i['pago_em'], $i['pago_em'], (int) $i['id']]);
    return (int) $stmt->fetchColumn();
}

/**
 * Cartão "Pagaram tudo e esperam turma": uma linha por curso com gente na fila (só contagens e somas): pessoas, fila
 * máxima, recebido sem juros, dias de espera da mais antiga, quantas chegam à devolução automática em 15 dias e o
 * último motivo da escola (rótulo).
 */
function mcp_espera_cursos(?int $agora = null): array
{
    if (!mcp_colunas_plano_ok()) {
        return [];
    }
    $agora ??= time();
    $stmt = mcp_db()->prepare("SELECT curso_slug, MAX(curso_nome) AS curso_nome, COUNT(*) AS pessoas, SUM(total_centavos) AS recebido, MIN(espera_desde) AS desde,
        SUM(espera_prazo < ?) AS devolucao_15d FROM mcp_inscricoes WHERE status = 'pago' AND plano = 'taxa_e_matricula' AND espera_status = 'aguardando'
        GROUP BY curso_slug ORDER BY desde");
    $stmt->execute([gmdate('Y-m-d H:i:s', $agora + (15 + MCP_ESPERA_ANTECEDENCIA_DIAS) * 86400)]);
    $lista = mcp_oferta()['sem_turma'];
    $motivo = mcp_db()->prepare("SELECT espera_motivo FROM mcp_inscricoes WHERE status = 'pago' AND espera_status = 'aguardando' AND curso_slug = ? AND espera_motivo IS NOT NULL ORDER BY espera_consultada_em DESC LIMIT 1");
    $saida = [];
    foreach ($stmt->fetchAll() as $l) {
        $motivo->execute([$l['curso_slug']]);
        $m = $motivo->fetchColumn();
        $saida[] = [
            'curso_slug' => $l['curso_slug'], 'curso_nome' => $l['curso_nome'], 'pessoas' => (int) $l['pessoas'],
            'max_fila' => $lista[$l['curso_slug']]['max_fila'] ?? null, 'recebido_centavos' => (int) $l['recebido'],
            'dias_mais_antiga' => $l['desde'] ? intdiv($agora - (int) mcp_utc_ts($l['desde']), 86400) : 0,
            'devolucao_em_15_dias' => (int) $l['devolucao_15d'],
            'motivo' => $m ? (MCP_ESPERA_MOTIVOS_ROTULO[$m] ?? $m) : null,
            'faltam_para_minimo' => max(0, 15 - (int) $l['pessoas']),
        ];
    }
    return $saida;
}

/** Filtro "Esperam turma": a fila na ordem (por curso, por pagamento). Sem dado de cartão. */
function mcp_espera_lista(?string $curso = null, int $limite = 200): array
{
    if (!mcp_colunas_plano_ok()) {
        return [];
    }
    $sql = "SELECT id, nome, curso_slug, curso_nome, pago_em, espera_desde, espera_prazo, total_centavos, total_cobrado_centavos, espera_avisos_enviados, espera_motivo, espera_falhas, espera_erro
        FROM mcp_inscricoes WHERE status = 'pago' AND plano = 'taxa_e_matricula' AND espera_status = 'aguardando'";
    $params = [];
    if ($curso !== null && $curso !== '') {
        $sql .= ' AND curso_slug = ?';
        $params[] = $curso;
    }
    $stmt = mcp_db()->prepare($sql . ' ORDER BY curso_slug, pago_em, id LIMIT ' . max(1, min(1000, $limite)));
    $stmt->execute($params);
    $saida = [];
    $posicoes = [];
    foreach ($stmt->fetchAll() as $l) {
        $posicoes[$l['curso_slug']] = ($posicoes[$l['curso_slug']] ?? 0) + 1;
        $l['posicao'] = $posicoes[$l['curso_slug']];
        $l['data_limite'] = mcp_espera_data_limite($l['espera_prazo']);
        $l['motivo_rotulo'] = $l['espera_motivo'] ? (MCP_ESPERA_MOTIVOS_ROTULO[$l['espera_motivo']] ?? $l['espera_motivo']) : null;
        $saida[] = $l;
    }
    return $saida;
}

/**
 * Turmas com quem pagou tudo, para o botão "Turma confirmada": as que ainda não começaram (compradas com a data ou
 * definidas para a fila) e as definidas para a fila nos últimos N dias. Turma, curso, data, pessoas, quantas vieram da
 * fila e quantas já receberam o e-mail.
 */
function mcp_espera_turmas_recentes(int $dias = 7, ?int $agora = null): array
{
    if (!mcp_colunas_plano_ok()) {
        return [];
    }
    $agora ??= time();
    $stmt = mcp_db()->prepare("SELECT i.turma_id, MAX(i.curso_nome) AS curso_nome, MIN(i.turma_inicio) AS turma_inicio, COUNT(*) AS pessoas,
        SUM(i.espera_status = 'turma') AS da_fila, SUM(i.espera_turma_confirmada_em IS NOT NULL) AS confirmadas FROM mcp_inscricoes i
        WHERE i.status = 'pago' AND i.plano = 'taxa_e_matricula' AND i.turma_id IS NOT NULL AND (i.espera_status IS NULL OR i.espera_status = 'turma')
          AND (i.turma_inicio > ? OR (i.espera_status = 'turma' AND i.espera_turma_email_em > ?))
        GROUP BY i.turma_id ORDER BY turma_inicio");
    $stmt->execute([gmdate('Y-m-d H:i:s', $agora), gmdate('Y-m-d H:i:s', $agora - $dias * 86400)]);
    return array_map(static fn(array $l): array => $l + ['data' => mcp_espera_data_limite($l['turma_inicio'])], $stmt->fetchAll());
}
