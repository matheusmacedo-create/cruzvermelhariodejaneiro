<?php
/**
 * Portal da secretaria (api/painel.php), 29/09/2026: as inscrições do site para a secretaria acompanhar.
 * Para cada inscrição, mostra quem pagou, o que a escola fez com a matrícula e se o aluno já disse os
 * horários. Tem busca, filtros, planilha, a ficha de cada inscrição com o histórico e o lembrete de
 * horários mandado à mão.
 *
 * A planilha não leva CPF: o arquivo costuma circular. O CPF aparece só na ficha, dentro do portal, como
 * já aparece no aviso de inscrição paga que a secretaria recebe por e-mail.
 *
 * Pagar tudo (10/2026; spec 3.2, 10.9): selos do plano completo (matrícula paga, já paga, não marcada, turma diferente,
 * acima da vaga, taxa em dobro, diferença a devolver, escola sem resposta, estornada a cancelar na escola) e da venda sem
 * turma (posição na fila e data limite, turma definida, confirmada, cancelada ou mudada, devolver, cancelar a matrícula,
 * falhas da escola); o filtro "Precisam de atenção" com os casos novos (o mesmo em PHP e em SQL); o filtro "Esperam
 * turma"; a coluna Pagamento com o plano e as parcelas; o cartão "Plano completo" (adesão) e as colunas novas da
 * planilha, mais a planilha mensal da espera para o financeiro. As ações da espera (Devolver, Continua esperando, Turma
 * confirmada, Já cancelei, Já resolvi) estão em lib/espera.php; o painel só as liga.
 */
declare(strict_types=1);

const MCP_SECRETARIA_FILTROS = [
    'pagas' => 'Pagas',
    'atencao' => 'Precisam de atenção',
    'espera' => 'Esperam turma',
    'sem_horarios' => 'Sem horários',
    'pendentes' => 'Aguardando pagamento',
    'todas' => 'Todas',
];
const MCP_SECRETARIA_STATUS = ['pendente' => 'Aguardando pagamento', 'pago' => 'Paga', 'recusado' => 'Recusada', 'expirado' => 'Expirada', 'estornado' => 'Estornada'];
/** Intervalo mínimo entre dois lembretes de horários ao mesmo aluno, automáticos ou à mão. */
const MCP_SECRETARIA_LEMBRETE_INTERVALO = 24 * 3600;
const MCP_SECRETARIA_ESCOLA_MOTIVOS = [
    'email_em_uso' => 'o e-mail já está em outra conta da escola, com outro CPF',
    'documento_da_equipe' => 'o CPF é de uma conta da equipe da escola',
    'aluno_bloqueado' => 'a conta do aluno está bloqueada na escola',
    'curso_inativo' => 'o curso está inativo na escola',
    'curso_inexistente' => 'o curso não foi encontrado na escola',
];

/** As colunas do pagar tudo (spec 3.3 e 10.8) existem? Sem elas (migração ainda não rodou), o painel fica como antes. */
function mcp_secretaria_colunas_ok(): bool
{
    return function_exists('mcp_colunas_plano_ok') && mcp_colunas_plano_ok();
}

/** Avisos da escola que pedem ação no plano completo (spec 3.2 e 10.7), com o selo e o tom de cada um. */
const MCP_SECRETARIA_AVISOS = [
    'curso_ja_pago' => ['Matrícula já estava paga: estornar', 'erro'],
    'matricula_nao_marcada' => ['Matrícula não marcada como paga: NÃO usar Encaixar', 'erro'],
    'turma_diferente' => ['Turma diferente da vendida', 'erro'],
    'turma_lotada' => ['Acima da vaga', 'alerta'],
    'taxa_em_dobro' => ['Taxa paga em dobro: devolver com juros', 'alerta'],
    'taxa_paga_antes' => ['Taxa paga antes no site: devolver a taxa', 'alerta'],
    'pendente_antiga' => ['Matrícula pendente antiga na escola: conferir', 'alerta'],
    'preco_divergente' => ['Preço diferente da escola', 'alerta'],
    'cobranca_escola_aberta' => ['Cobrança da escola cancelada', 'alerta'],
];
const MCP_SECRETARIA_TONS = ['erro' => 0, 'alerta' => 1, 'ok' => 2, 'info' => 3, 'neutro' => 4];
const MCP_SECRETARIA_DEVOLVER = [
    'prazo' => 'data limite', 'pedido' => 'pedido da pessoa', 'data_nao_serve' => 'a data ou o horário não servem', 'requisito' => 'requisito do curso',
    'curso_ja_pago' => 'curso já pago na escola', 'turma_cancelada' => 'turma cancelada, sem resposta', 'secretaria' => 'decisão da secretaria',
];

/**
 * Os selos da inscrição (spec 3.2 e 10.9), do mais grave ao menos grave: ['rotulo', 'tom', 'grupo'], com tom erro,
 * alerta, ok, info ou neutro. Erro e alerta pedem ação (filtro "Precisam de atenção"). grupo 'escola': o que a secretaria
 * resolve na escola (no plano completo, some com "Já resolvi na escola"); grupo 'espera': a fila da venda sem turma (não
 * some com ele: a devolução e a data limite seguem até o estorno). $opcoes: posicao (da fila, já calculada), agora,
 * eventos (o histórico da ficha, para o selo "Turma mudou").
 */
function mcp_secretaria_selos(array $i, array $opcoes = []): array
{
    $agora = (int) ($opcoes['agora'] ?? time());
    $status = (string) ($i['status'] ?? '');
    $completo = ($i['plano'] ?? '') === 'taxa_e_matricula';
    $resolvido = $completo && !empty($i['escola_resolvido_em']);
    $espera = (string) ($i['espera_status'] ?? '');
    $selos = [];
    $add = static function (string $rotulo, string $tom, string $grupo = 'escola') use (&$selos, $resolvido): void {
        if ($resolvido && $grupo === 'escola' && in_array($tom, ['erro', 'alerta'], true)) {
            return;
        }
        $selos[] = ['rotulo' => $rotulo, 'tom' => $tom, 'grupo' => $grupo];
    };
    if ($status === 'estornado') {
        if ($completo && (!in_array($espera, ['aguardando', 'devolver'], true) || !empty($i['espera_matricula_escola']))) {
            $add('Estornada: cancelar na escola', 'alerta');
        }
    } elseif ($status === 'pago') {
        $acesso = mcp_escola_acesso($i);
        $avisos = function_exists('mcp_email_avisos') ? mcp_email_avisos($i) : [];
        // A venda sem turma (10.9).
        if ($completo && $espera !== '') {
            if ($espera === 'aguardando') {
                $posicao = $opcoes['posicao'] ?? null;
                if ($posicao === null && function_exists('mcp_espera_posicao')) {
                    try {
                        $posicao = mcp_espera_posicao($i);
                    } catch (Throwable) {
                        $posicao = null;
                    }
                }
                $prazo = !empty($i['espera_prazo']) ? (int) strtotime($i['espera_prazo'] . ' UTC') : 0;
                $add('Espera turma: ' . ($posicao ? $posicao . 'ª da fila, ' : '') . 'data limite ' . ($prazo ? mcp_data_brt((string) $i['espera_prazo'], 'd/m') : '?'),
                    $prazo && $prazo < $agora + 20 * 86400 ? 'alerta' : 'info', 'espera');
                if ((int) ($i['espera_falhas'] ?? 0) >= 5) {
                    $add('Escola sem resposta na espera (' . (int) $i['espera_falhas'] . ' falhas)', 'erro', 'espera');
                }
                if (!empty($i['espera_erro'])) {
                    $add('Escola recusou: ' . $i['espera_erro'], 'erro', 'espera');
                }
                $add('Não usar o convite de "sem inscrição"', 'info', 'espera');
            } elseif ($espera === 'turma') {
                $data = function_exists('mcp_escola_data_turma') ? mcp_escola_data_turma($acesso) : '';
                if (($i['espera_motivo'] ?? '') === 'turma_cancelada') {
                    $add('Turma cancelada na escola', 'erro', 'espera');
                } elseif (!empty($i['espera_janela_ate']) && strtotime($i['espera_janela_ate'] . ' UTC') > $agora && mcp_secretaria_turma_mudou($opcoes['eventos'] ?? [])) {
                    $add('Turma mudou: ' . ($data !== '' ? $data : 'nova data'), 'erro', 'espera');
                }
                $add('Turma definida na espera' . ($data !== '' ? ": $data" : ''), 'ok', 'espera');
                if (!empty($i['espera_turma_confirmada_em'])) {
                    $add('Turma confirmada em ' . mcp_data_brt((string) $i['espera_turma_confirmada_em'], 'd/m'), 'ok', 'espera');
                }
            } elseif ($espera === 'devolver') {
                $add('Devolver: ' . (MCP_SECRETARIA_DEVOLVER[$i['espera_devolver_motivo'] ?? ''] ?? 'devolução'), 'erro', 'espera');
            }
            if (!empty($i['espera_matricula_escola'])) {
                $add('Cancelar a matrícula ' . $i['espera_matricula_escola'] . ' na escola', 'erro');
            }
        } elseif ($completo) {
            // Taxa + matrícula com turma (3.2).
            if ($acesso) {
                if (in_array('matricula_paga_sem_turma', $avisos, true)) {
                    $add('Matrícula paga sem turma', 'erro');
                }
                if (($acesso['resultado'] ?? '') === 'sem_turma' && !in_array('matricula_paga_sem_turma', $avisos, true)) {
                    $add('Sem turma na escola', 'erro');
                }
            } elseif (mcp_escola_erro($i) !== null || (($i['escola_status'] ?? '') === 'erro' && (int) ($i['escola_tentativas'] ?? 0) >= MCP_ESCOLA_MAX_TENTATIVAS)) {
                $add('Matrícula não marcada como paga: NÃO usar Encaixar', 'erro');
            }
        } else {
            // Só a taxa: os selos de antes.
            if ($acesso && ($acesso['resultado'] ?? '') === 'sem_turma') {
                $add('Sem turma aberta', 'alerta');
            }
            if ($acesso && in_array('taxa_ja_confirmada', $avisos, true)) {
                $add('Taxa já estava paga na escola', 'alerta');
            }
            if (!$acesso && mcp_escola_erro($i) !== null) {
                $add('Não matriculado', 'erro');
            }
        }
        foreach (MCP_SECRETARIA_AVISOS as $aviso => [$rotulo, $tom]) {
            if (in_array($aviso, $avisos, true)) {
                $add($rotulo, $tom);
            }
        }
        if ($espera === '' && !$acesso && mcp_escola_erro($i) === null && ($i['escola_status'] ?? '') === 'erro'
            && !($completo && (int) ($i['escola_tentativas'] ?? 0) >= MCP_ESCOLA_MAX_TENTATIVAS)) {
            $add('Falha na integração', 'erro');
        }
        if ($espera === '' && ($i['escola_status'] ?? '') === 'pendente' && !empty($i['pago_em']) && strtotime($i['pago_em'] . ' UTC') < $agora - 600) {
            $add('Escola sem resposta há mais de 10 min', 'erro');
        }
        if ((int) ($i['diferenca_devolver_centavos'] ?? 0) > 0) {
            $add('Diferença a devolver: ' . mcp_brl((int) $i['diferenca_devolver_centavos']), 'erro');
        }
        if ($completo && $espera === '' && $acesso && ($acesso['matricula_paga'] ?? null) === true) {
            $data = function_exists('mcp_escola_data_turma') ? mcp_escola_data_turma($acesso) : mcp_escola_data($acesso['turma_inicio'] ?? null);
            $add('Matrícula paga' . ($data !== '' ? " · turma de $data" : ''), 'ok');
        } elseif (!array_filter($selos, static fn(array $s): bool => $s['grupo'] === 'escola' && $s['tom'] !== 'info')) {
            if (!$completo && $acesso && ($acesso['resultado'] ?? '') === 'matriculado') {
                $data = mcp_escola_data($acesso['turma_inicio'] ?? null);
                $add('Matriculado' . ($data !== '' ? " · turma de $data" : ''), 'ok');
            } elseif ($espera === '' && !$acesso) {
                match ((string) ($i['escola_status'] ?? '')) {
                    'pendente' => $add('Matrícula em andamento', 'neutro'),
                    'erro' => null,
                    default => $add('Sem integração na época', 'neutro'),
                };
            }
        }
    }
    if ($resolvido) {
        $selos[] = ['rotulo' => 'Resolvido na escola em ' . mcp_data_brt((string) $i['escola_resolvido_em'], 'd/m'), 'tom' => 'ok', 'grupo' => 'escola'];
    }
    if (!$selos) {
        return [['rotulo' => '—', 'tom' => 'neutro', 'grupo' => 'escola']];
    }
    usort($selos, static fn(array $a, array $b): int => MCP_SECRETARIA_TONS[$a['tom']] <=> MCP_SECRETARIA_TONS[$b['tom']]);
    return $selos;
}

/** O histórico da ficha mostra uma mudança de turma depois da última definição? (selo "Turma mudou"). */
function mcp_secretaria_turma_mudou(array $eventos): bool
{
    $mudou = false;
    foreach ($eventos as $e) {
        $tipo = (string) ($e['tipo'] ?? '');
        if ($tipo === 'espera_turma_mudou') {
            $mudou = true;
        } elseif (in_array($tipo, ['espera_turma_definida', 'espera_devolver'], true)) {
            $mudou = false;
        }
    }
    return $mudou;
}

/**
 * Situação da matrícula na escola, em poucas palavras: o selo mais grave da inscrição (mcp_secretaria_selos), com
 * tom ok, alerta, erro, info ou neutro. Alerta e erro pedem ação da secretaria (filtro "Precisam de atenção").
 */
function mcp_secretaria_escola(array $i): array
{
    $selo = mcp_secretaria_selos($i)[0];
    return ['rotulo' => $selo['rotulo'], 'tom' => $selo['tom']];
}

/** O que a secretaria precisa fazer na escola, frase a frase (ficha da inscrição). */
function mcp_secretaria_orientacoes(array $i): array
{
    $status = (string) ($i['status'] ?? '');
    $completo = ($i['plano'] ?? '') === 'taxa_e_matricula';
    $espera = (string) ($i['espera_status'] ?? '');
    $acesso = mcp_escola_acesso($i);
    $avisos = function_exists('mcp_email_avisos') ? mcp_email_avisos($i) : [];
    if ($status === 'estornado') {
        if ($completo && (!in_array($espera, ['aguardando', 'devolver'], true) || !empty($i['espera_matricula_escola']))) {
            return [empty($i['escola_resolvido_em'])
                ? 'Compra estornada. Confira na escola: se a matrícula não estiver como Estornada, cancele-a (Admin → aluno → matrícula → Cancelar inscrição). Depois, marque "Já resolvi na escola".'
                : 'Compra estornada e resolvida na escola.'];
        }
        return ['Compra estornada.' . ($completo ? ' Não há matrícula na escola: nada a fazer lá.' : '')];
    }
    if ($status !== 'pago') {
        return ['A matrícula na escola é feita sozinha quando o pagamento é confirmado.'];
    }
    $frases = [];
    if ($completo && $espera !== '') {
        $frases[] = match ($espera) {
            'aguardando' => 'Pagou tudo e espera turma. Nada a fazer na escola agora: o site matricula sozinho, já paga, quando a escola abrir turma do curso com vaga, com as aulas cadastradas e a primeira aula pelo menos 10 dias depois. NÃO marque nada como pago na escola, NÃO use "Encaixar" e NÃO use o convite de Alunos > "sem inscrição" para esta pessoa.',
            'turma' => 'A pessoa saiu da fila e está matriculada, já paga, na turma da escola. Quando a turma atingir o mínimo de alunos, marque "Turma confirmada" no Início.',
            default => 'Devolver: estorne a compra inteira no painel da Unicopag em até 2 dias úteis (o PIX só pode ser devolvido até 90 dias depois do pagamento; depois, transferência para uma conta no nome da pessoa).',
        };
        if (!empty($i['espera_matricula_escola'])) {
            $frases[] = empty($i['escola_resolvido_em'])
                ? 'A escola matriculou esta pessoa enquanto a devolução era pedida: antes de estornar, cancele a matrícula ' . $i['espera_matricula_escola'] . ' na escola (Admin → aluno → matrícula → Cancelar inscrição) e marque "Já cancelei na escola".'
                : 'A matrícula ' . $i['espera_matricula_escola'] . ' já foi cancelada na escola: pode estornar.';
        }
    } elseif ($completo) {
        $naoMarcado = in_array('matricula_nao_marcada', $avisos, true) || (!$acesso && (mcp_escola_erro($i) !== null
            || (($i['escola_status'] ?? '') === 'erro' && (int) ($i['escola_tentativas'] ?? 0) >= MCP_ESCOLA_MAX_TENTATIVAS)));
        if (in_array('curso_ja_pago', $avisos, true)) {
            $frases[] = 'A matrícula já estava paga na escola: estorne esta compra inteira no painel da Unicopag. NÃO cancele a matrícula na escola: ela continua paga.';
        } elseif (in_array('matricula_paga_sem_turma', $avisos, true)) {
            $frases[] = 'Pagou tudo, mas a escola não tinha turma para esta compra. Ofereça a próxima turma, com tudo pago, ou a devolução total (estorno no painel da Unicopag).';
        } elseif ($naoMarcado) {
            $frases[] = 'A escola não registrou a matrícula como paga. Matricule à mão como À vista, PAGO, com um Pagamento CURSO de '
                . mcp_brl((int) $i['inscricao_centavos'] + (int) ($i['matricula_centavos'] ?? 0)) . ' no gateway unicopag-2. NÃO use "Encaixar". Depois, marque "Já resolvi na escola" e avise a TI.';
        } elseif (in_array('turma_diferente', $avisos, true)) {
            $frases[] = 'A escola gravou outra turma no lugar da vendida. Confirme a data com o aluno; se não servir, estorne a compra inteira.';
        } elseif ($acesso && ($acesso['matricula_paga'] ?? null) === true) {
            $frases[] = 'Inscrição e matrícula pagas no site, e a escola registrou a matrícula como paga.';
        } elseif (!$acesso) {
            $frases[] = ($i['escola_status'] ?? '') === 'pendente'
                ? 'A matrícula na escola está sendo feita.' : 'A integração falhou por um problema técnico: o site tenta de novo. Confira na escola antes de matricular à mão.';
        }
        if (in_array('turma_lotada', $avisos, true)) {
            $frases[] = 'Entrou acima da vaga (turma marcada como lotada depois do pagamento): ajuste as vagas da turma na escola. O aluno leu "Matrícula confirmada".';
        }
    } elseif ($acesso) {
        $frases[] = match (true) {
            ($acesso['resultado'] ?? '') === 'sem_turma' => 'A conta do aluno está pronta na escola, mas o curso não tinha turma aberta. Matricule o aluno quando abrir turma e aplique a inscrição paga.',
            in_array('taxa_ja_confirmada', $avisos, true) => 'A taxa de inscrição já estava confirmada na escola antes deste pagamento: este pagamento é devolvido por inteiro (estorno no painel da Unicopag).',
            default => 'O aluno está matriculado, com a taxa confirmada. A matrícula ainda não está paga: combine com ele o pagamento antes da aula.',
        };
    } else {
        $erro = mcp_escola_erro($i);
        $frases[] = $erro !== null
            ? 'A escola recusou a matrícula: ' . (MCP_SECRETARIA_ESCOLA_MOTIVOS[$erro] ?? $erro) . '. Crie a matrícula à mão e aplique o valor da inscrição.'
            : match ((string) ($i['escola_status'] ?? '')) {
                'erro' => 'A integração falhou por um problema técnico. Confira na escola antes de matricular à mão.',
                'pendente' => 'A matrícula na escola está sendo feita.',
                default => 'Este pagamento é de antes da integração com a escola (28/09/2026). Confira se a matrícula foi criada.',
            };
    }
    if (in_array('taxa_em_dobro', $avisos, true) || in_array('taxa_paga_antes', $avisos, true)) {
        $valor = function_exists('mcp_email_taxa_em_dobro_centavos') ? mcp_email_taxa_em_dobro_centavos($i) : (int) $i['inscricao_centavos'];
        $frases[] = 'A taxa de inscrição foi paga duas vezes. Confira antes (a taxa pode ter sido marcada à mão na escola) e devolva por PIX ' . mcp_brl($valor) . ', a taxa com os juros proporcionais.';
    }
    if (in_array('pendente_antiga', $avisos, true)) {
        $frases[] = 'Há uma matrícula pendente antiga, com a taxa paga, numa turma deste curso que já começou: se o aluno não fez aquela turma, cancele-a na escola e devolva a taxa paga a mais.';
    }
    if (in_array('preco_divergente', $avisos, true)) {
        $frases[] = 'O preço da matrícula no site difere do da escola: confira.';
    }
    if (in_array('cobranca_escola_aberta', $avisos, true)) {
        $frases[] = 'Havia cobrança online da escola em aberto para esta matrícula, já cancelada: confira se o aluno não pagou também por lá.';
    }
    if ((int) ($i['diferenca_devolver_centavos'] ?? 0) > 0) {
        $frases[] = 'O cartão cobrou ' . mcp_brl((int) $i['diferenca_devolver_centavos']) . ' a mais que o total mostrado: devolva a diferença por PIX em até 2 dias úteis, para uma conta no nome do aluno.';
    }
    if ($completo && !empty($i['escola_resolvido_em'])) {
        $frases[] = 'Marcado como resolvido na escola em ' . mcp_data_brt((string) $i['escola_resolvido_em'], 'd/m/Y \à\s H\hi') . '.';
    }
    return $frases;
}

/** O que a secretaria precisa fazer na escola, em uma frase (compatível com a ficha de antes). */
function mcp_secretaria_escola_orientacao(array $i): string
{
    return implode(' ', mcp_secretaria_orientacoes($i));
}

/** A conta do aluno na escola, quando a matrícula passou pela integração: criada pelo site ou já existente (e com qual e-mail). */
function mcp_secretaria_escola_conta(array $i): ?string
{
    $acesso = mcp_escola_acesso($i);
    if (!$acesso) {
        return null;
    }
    if (!empty($acesso['aluno_novo'])) {
        return 'Conta criada pelo site, com o e-mail da inscrição';
    }
    return 'O aluno já tinha conta na escola' . (empty($acesso['email_confere']) && !empty($acesso['email_conta'])
        ? ', com outro e-mail: ' . $acesso['email_conta'] : ', com o mesmo e-mail');
}

/**
 * Precisa de atenção: algum selo de erro ou alerta (paga, ou estornada do plano completo). O selo "Turma mudou" depende do
 * histórico e fica só na ficha. Espelha mcp_secretaria_condicao('atencao'), que o teste de integração executa no banco.
 */
function mcp_secretaria_precisa_atencao(array $i, ?int $agora = null): bool
{
    if (!in_array($i['status'] ?? '', ['pago', 'estornado'], true)) {
        return false;
    }
    foreach (mcp_secretaria_selos($i, ['agora' => $agora ?? time()]) as $selo) {
        if (in_array($selo['tom'], ['erro', 'alerta'], true)) {
            return true;
        }
    }
    return false;
}

/**
 * "Precisam de atenção" em SQL (spec 3.2 e 10.9; T22, F15). Grupo escola: falha ou falta de resposta da escola, avisos
 * da escola, diferença a devolver, matrícula a cancelar de quem pediu devolução; no plano completo, some com "Já resolvi
 * na escola" (escola_resolvido_em). Grupo espera: devolver, data limite em 20 dias, falhas ou recusa da escola, turma
 * cancelada. Mais a estornada do plano completo que ainda tem matrícula na escola. Sem as colunas novas, a de antes.
 */
function mcp_secretaria_condicao_atencao(): string
{
    if (!mcp_secretaria_colunas_ok()) {
        return "i.status = 'pago' AND (i.escola_status = 'erro' OR i.escola_acesso LIKE '%\"resultado\":\"sem_turma\"%' OR i.escola_acesso LIKE '%\"aviso\":\"taxa_ja_confirmada\"%')";
    }
    $like = static fn(string $aviso): string => "i.escola_acesso LIKE '%\"$aviso\"%'";
    $avisos = implode(' OR ', array_map($like, array_keys(MCP_SECRETARIA_AVISOS)));
    $escola = "(i.espera_status IS NULL AND (i.escola_status = 'erro' OR i.escola_acesso LIKE '%\"resultado\":\"sem_turma\"%' OR " . $like('matricula_paga_sem_turma')
        . " OR (i.escola_status = 'pendente' AND i.pago_em < UTC_TIMESTAMP() - INTERVAL 10 MINUTE)))"
        . " OR (i.plano <> 'taxa_e_matricula' AND " . $like('taxa_ja_confirmada') . ")"
        . " OR $avisos OR i.diferenca_devolver_centavos > 0"
        . " OR (i.plano = 'taxa_e_matricula' AND i.espera_status IS NOT NULL AND i.espera_matricula_escola IS NOT NULL)";
    $espera = "i.plano = 'taxa_e_matricula' AND (i.espera_status = 'devolver'"
        . " OR (i.espera_status = 'aguardando' AND (i.espera_falhas >= 5 OR i.espera_erro IS NOT NULL OR i.espera_prazo < UTC_TIMESTAMP() + INTERVAL 20 DAY))"
        . " OR (i.espera_status = 'turma' AND i.espera_motivo = 'turma_cancelada'))";
    return "((i.status = 'pago' AND ((($escola) AND (i.plano <> 'taxa_e_matricula' OR i.escola_resolvido_em IS NULL)) OR ($espera)))"
        . " OR (i.status = 'estornado' AND i.plano = 'taxa_e_matricula' AND i.escola_resolvido_em IS NULL"
        . " AND (COALESCE(i.espera_status, '') NOT IN ('aguardando', 'devolver') OR i.espera_matricula_escola IS NOT NULL)))";
}

/** Condição SQL de cada filtro (i = mcp_inscricoes, p = mcp_preferencias). "atencao" espelha mcp_secretaria_precisa_atencao. */
function mcp_secretaria_condicao(string $filtro): string
{
    return match ($filtro) {
        'pagas' => "i.status = 'pago'",
        'atencao' => mcp_secretaria_condicao_atencao(),
        'espera' => mcp_secretaria_colunas_ok() ? "i.status = 'pago' AND i.plano = 'taxa_e_matricula' AND i.espera_status = 'aguardando'" : '1 = 0',
        'sem_horarios' => "i.status = 'pago' AND p.inscricao_id IS NULL",
        'pendentes' => "i.status = 'pendente'",
        default => '1 = 1',
    };
}

/**
 * WHERE e parâmetros para filtro, curso e busca. A busca procura por nome ou e-mail, por telefone (4 dígitos
 * ou mais) e por CPF (11 dígitos exatos).
 */
function mcp_secretaria_where(string $filtro, ?string $curso, string $busca): array
{
    $partes = [mcp_secretaria_condicao($filtro)];
    $params = [];
    if ($curso !== null && $curso !== '') {
        $partes[] = 'i.curso_slug = ?';
        $params[] = $curso;
    }
    $busca = trim($busca);
    if ($busca !== '') {
        $like = '%' . addcslashes($busca, '%_\\') . '%';
        $ou = ['i.nome LIKE ?', 'i.email LIKE ?'];
        array_push($params, $like, $like);
        $digitos = mcp_digitos($busca);
        if (strlen($digitos) >= 4) {
            $ou[] = 'i.telefone LIKE ?';
            $params[] = '%' . $digitos . '%';
        }
        if (strlen($digitos) === 11) {
            $ou[] = 'i.cpf = ?';
            $params[] = $digitos;
        }
        $partes[] = '(' . implode(' OR ', $ou) . ')';
    }
    return [implode(' AND ', $partes), $params];
}

const MCP_SECRETARIA_SELECT = "SELECT i.*, p.horarios, p.inicio, p.turma_serve, p.observacao, p.vezes, p.atualizado_em AS horarios_em,
    (SELECT COUNT(*) FROM mcp_eventos e WHERE e.inscricao_id = i.id AND e.tipo = 'lembrete_horarios') AS lembretes,
    (SELECT MAX(e.criado_em) FROM mcp_eventos e WHERE e.inscricao_id = i.id AND e.tipo = 'lembrete_horarios') AS lembrete_em
    FROM mcp_inscricoes i LEFT JOIN mcp_preferencias p ON p.inscricao_id = i.id";

/** Linha com as respostas de horários em 'preferencia' (ou null, se o aluno não respondeu). */
function mcp_secretaria_linha(array $l): array
{
    $l['preferencia'] = $l['horarios'] !== null ? mcp_horarios_linha([
        'horarios' => $l['horarios'], 'inicio' => $l['inicio'], 'turma_serve' => $l['turma_serve'], 'observacao' => $l['observacao'],
        'vezes' => $l['vezes'], 'atualizado_em' => $l['horarios_em'],
    ]) : null;
    return $l;
}

function mcp_secretaria_listar(string $filtro, ?string $curso = null, string $busca = '', int $limite = 50, int $deslocamento = 0): array
{
    [$where, $params] = mcp_secretaria_where($filtro, $curso, $busca);
    // "Esperam turma" vem na ordem da fila (curso, pagamento); os outros, do mais recente ao mais antigo.
    $ordem = $filtro === 'espera' ? 'i.curso_slug, i.pago_em, i.id' : 'COALESCE(i.pago_em, i.criado_em) DESC, i.id DESC';
    $stmt = mcp_db()->prepare(MCP_SECRETARIA_SELECT . " WHERE $where ORDER BY $ordem LIMIT "
        . max(1, $limite) . ' OFFSET ' . max(0, $deslocamento));
    $stmt->execute($params);
    return array_map('mcp_secretaria_linha', $stmt->fetchAll());
}

/** Quantas inscrições em cada filtro, com o mesmo curso e a mesma busca. */
function mcp_secretaria_contar(?string $curso = null, string $busca = ''): array
{
    $n = [];
    foreach (array_keys(MCP_SECRETARIA_FILTROS) as $filtro) {
        [$where, $params] = mcp_secretaria_where($filtro, $curso, $busca);
        $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_inscricoes i LEFT JOIN mcp_preferencias p ON p.inscricao_id = i.id WHERE $where");
        $stmt->execute($params);
        $n[$filtro] = (int) $stmt->fetchColumn();
    }
    return $n;
}

/** Pagas nos últimos $dias dias. */
function mcp_secretaria_pagas_recentes(int $dias = 7): int
{
    $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_inscricoes WHERE status = 'pago' AND pago_em >= ?");
    $stmt->execute([gmdate('Y-m-d H:i:s', time() - $dias * 86400)]);
    return (int) $stmt->fetchColumn();
}

/**
 * Teste da contribuição para a divulgação (07/10/2026), por valor oferecido no checkout: inscrições pagas que viram a
 * opção, quantas contribuíram, quanto somou e desde quando o valor aparece. Estorno não entra (deixa de ser "pago").
 */
function mcp_secretaria_divulgacao(): array
{
    return mcp_db()->query("SELECT divulgacao_oferta_centavos AS oferta, COUNT(*) AS pagas, SUM(divulgacao_centavos > 0) AS contribuiram,
        SUM(divulgacao_centavos) AS arrecadado, MIN(criado_em) AS desde FROM mcp_inscricoes
        WHERE status = 'pago' AND divulgacao_oferta_centavos IS NOT NULL GROUP BY divulgacao_oferta_centavos ORDER BY desde")->fetchAll();
}

/** Cursos que têm inscrição, para o filtro: slug => nome. */
function mcp_secretaria_cursos(): array
{
    $cursos = [];
    foreach (mcp_db()->query('SELECT curso_slug, MAX(curso_nome) AS curso_nome FROM mcp_inscricoes GROUP BY curso_slug ORDER BY curso_nome')->fetchAll() as $l) {
        $cursos[(string) $l['curso_slug']] = (string) $l['curso_nome'];
    }
    return $cursos;
}

function mcp_secretaria_inscricao(int $id): ?array
{
    $stmt = mcp_db()->prepare(MCP_SECRETARIA_SELECT . ' WHERE i.id = ?');
    $stmt->execute([$id]);
    $linha = $stmt->fetch();
    return $linha ? mcp_secretaria_linha($linha) : null;
}

function mcp_secretaria_eventos(int $inscricaoId): array
{
    $stmt = mcp_db()->prepare('SELECT tipo, detalhe, criado_em FROM mcp_eventos WHERE inscricao_id = ? ORDER BY criado_em, id');
    $stmt->execute([$inscricaoId]);
    return $stmt->fetchAll();
}

/**
 * O evento em linguagem da secretaria, para o histórico da ficha. Devolve null para o que não interessa
 * a ela; o detalhe técnico só aparece quando ajuda, e nunca IP nem dado de cartão.
 */
function mcp_secretaria_evento(string $tipo, ?string $detalhe): ?string
{
    $d = (string) $detalhe;
    $partes = array_map('trim', explode('·', $d));
    $falhou = $d === 'falhou' || str_contains($d, '· falhou') || str_contains($d, ' falhou ·');
    $envio = static fn(string $texto): string => $texto . ($falhou ? ' (o envio falhou)' : '');
    switch ($tipo) {
        case 'cobranca_criada':
            $metodo = ($partes[0] ?? '') === 'pix' ? 'PIX' : 'cartão';
            return 'Cobrança criada: ' . $metodo . (isset($partes[2]) ? ', ' . $partes[2] : '');
        case 'pix_reaproveitado':
            return 'O aluno voltou e recebeu o mesmo PIX, ainda válido';
        case 'email_pix':
            return $envio('E-mail com o código PIX enviado ao aluno');
        case 'pago':
            return 'Pagamento confirmado';
        case 'recusado':
            return 'Pagamento recusado';
        case 'expirado':
            return 'A cobrança venceu sem pagamento';
        case 'estornado':
            return 'Pagamento estornado';
        case 'postback':
            return 'Aviso de pagamento recebido da Unicopag';
        case 'consulta_falhou':
            return 'A consulta à Unicopag falhou (o site tenta de novo)';
        case 'escola_ok':
            $resultado = $partes[1] ?? '';
            return ($resultado === 'sem_turma' ? 'Escola: conta pronta, mas sem turma aberta' : 'Escola: matrícula feita')
                . (in_array('taxa_ja_confirmada', $partes, true) ? ' (a taxa já estava confirmada)' : '')
                . (in_array('repetido', $partes, true) ? ' (confirmação repetida)' : '');
        case 'escola_erro':
            $motivo = '';
            foreach ($partes as $p) {
                if (isset(MCP_SECRETARIA_ESCOLA_MOTIVOS[$p])) {
                    $motivo = MCP_SECRETARIA_ESCOLA_MOTIVOS[$p];
                }
            }
            return 'Escola: a matrícula não foi feita' . ($motivo !== '' ? " ($motivo)" : ' (' . implode(' · ', array_slice($partes, 1)) . ')');
        case 'email_aluno':
            return $envio('E-mail de inscrição paga enviado ao aluno' . (str_contains($d, 'com comprovante') ? ', com o comprovante em PDF' : ''));
        case 'email_secretaria':
            return $envio('Aviso de inscrição paga enviado à secretaria');
        case 'horarios':
            return 'O aluno salvou os horários';
        case 'email_horarios':
            return $envio('Aviso dos horários enviado à secretaria');
        case 'lembrete_horarios':
            return 'Lembrete de horários enviado ao aluno' . (preg_match('/manual (\S+)/', $d, $m) ? ', à mão, por ' . $m[1] : '');
        case 'lembrete_horarios_falhou':
            return 'Um lembrete de horários não foi enviado (falha no envio)';
        // Pagar tudo (10/2026)
        case 'escola_esgotada':
            return 'Escola: as tentativas de registrar a matrícula acabaram';
        case 'escola_funcao_antiga':
        case 'escola_versao_antiga':
            return 'Escola: a função da escola respondeu como a versão antiga (sem "matrícula paga")';
        case 'diferenca_devolver':
        case 'juros_divergente':
            return 'O cartão cobrou mais que o total mostrado: há diferença a devolver';
        case 'valor_divergente':
            return 'A Unicopag informou um valor diferente do cobrado (conferir)';
        case 'pre_chargeback':
            return 'Pré-chargeback avisado pela Unicopag';
        case 'aviso_estorno':
            return 'Estorno confirmado pela Unicopag';
        case 'email_estorno_secretaria':
            return $envio('Aviso de estorno enviado à secretaria');
        case 'aviso_secretaria':
            return $envio('Aviso enviado à secretaria' . (($partes[0] ?? '') !== '' ? ': ' . $partes[0] : ''));
        case 'escola_resolvido':
            return 'Marcado como resolvido na escola' . ($d !== '' ? ', por ' . $d : '');
        case 'espera_entrou':
            return 'Entrou na fila da próxima turma (pagou tudo, sem turma)';
        case 'espera_sem_turma':
            return 'Escola: ainda sem turma para a fila' . (($partes[0] ?? '') !== '' ? ' (' . (defined('MCP_ESPERA_MOTIVOS_ROTULO') ? (MCP_ESPERA_MOTIVOS_ROTULO[$partes[0]] ?? $partes[0]) : $partes[0]) . ')' : '');
        case 'espera_turma_definida':
            return 'Saiu da fila: matriculado (pago) numa turma da escola' . (isset($partes[1]) ? ', depois de ' . $partes[1] . ' de espera' : '');
        case 'espera_turma_mudou':
            return 'A turma de quem esperava mudou na escola (e-mail enviado com a nova data)';
        case 'espera_turma_cancelada':
            return 'A turma foi cancelada na escola (e-mail enviado com a escolha)';
        case 'espera_turma_confirmada':
            return 'Turma confirmada pela secretaria (e-mail enviado)';
        case 'espera_devolver':
            return 'Marcado para devolver: ' . (MCP_SECRETARIA_DEVOLVER[$partes[1] ?? ''] ?? ($partes[1] ?? 'devolução'));
        case 'espera_prorrogada':
            return 'Continua esperando: data limite prorrogada (a pedido da pessoa)';
        case 'espera_matricula_devolver':
            return 'A escola matriculou quem pedia a devolução: cancelar a matrícula ' . $d . ' na escola';
        case 'espera_cancelada_na_escola':
            return 'Matrícula cancelada na escola (antes do estorno)';
        case 'espera_falha':
            return 'A rotina da espera não teve resposta da escola (tenta de novo)';
        case 'email_turma_aberta':
            return $envio('E-mail "Sua turma abriu" enviado');
        case 'email_turma_mudou':
            return $envio('E-mail "Sua turma mudou" enviado');
        case 'email_turma_cancelada':
            return $envio('E-mail "Turma cancelada" enviado');
        case 'email_turma_confirmada':
            return $envio('E-mail "Turma confirmada" enviado');
        case 'email_espera_lembrete':
        case 'email_espera_prazo':
            return $envio('E-mail da espera enviado (ainda sem data ou aviso do prazo)');
        case 'email_espera_prorrogada':
            return $envio('E-mail "Você continua na fila" enviado');
        case 'email_espera_devolucao':
        case 'email_espera_devolvido':
            return $envio('E-mail da devolução enviado');
        case 'email_diferenca':
            return $envio('E-mail ao aluno sobre a diferença a devolver');
        default:
            return null;
    }
}

/**
 * Lembrete de horários mandado à mão pela secretaria. Devolve 'enviado', 'respondido', 'nao_pago',
 * 'recente' (houve lembrete há menos de 24 h) ou 'falhou'. Conta junto com os automáticos: o cron vê
 * este lembrete e espera antes do próximo. Usa a mesma trava do cron (api/lembretes.php): um clique
 * duplo ou a rodada da hora não mandam dois e-mails ao mesmo aluno.
 */
function mcp_secretaria_lembrete(array $i, string $quem, ?int $agora = null): string
{
    $agora ??= time();
    if (($i['status'] ?? '') !== 'pago') {
        return 'nao_pago';
    }
    $db = mcp_db();
    if ((int) $db->query("SELECT GET_LOCK('mcp_lembretes_horarios', 10)")->fetchColumn() !== 1) {
        return 'falhou';
    }
    try {
        if (mcp_horarios_por_inscricao((int) $i['id']) !== null) {
            return 'respondido';
        }
        $stmt = $db->prepare("SELECT COUNT(*) AS n, MAX(criado_em) AS ultimo FROM mcp_eventos WHERE inscricao_id = ? AND tipo = 'lembrete_horarios'");
        $stmt->execute([(int) $i['id']]);
        $ja = $stmt->fetch();
        if (!empty($ja['ultimo']) && $agora - (int) strtotime($ja['ultimo'] . ' UTC') < MCP_SECRETARIA_LEMBRETE_INTERVALO) {
            return 'recente';
        }
        $numero = min((int) $ja['n'] + 1, count(MCP_HORARIOS_LEMBRETES_HORAS));
        $m = mcp_montar_email_lembrete_horarios($i, $numero);
        $envio = mcp_enviar_email((string) $i['email'], $m['assunto'], $m['html'], $m['texto']);
        if ($envio === 'falhou') {
            mcp_registrar((int) $i['id'], 'lembrete_horarios_falhou', "#$numero manual $quem");
            return 'falhou';
        }
        mcp_registrar((int) $i['id'], 'lembrete_horarios', "#$numero manual $quem · $envio");
        return 'enviado';
    } finally {
        $db->query("SELECT RELEASE_LOCK('mcp_lembretes_horarios')");
    }
}

/** Planilha das inscrições (CSV com ; e BOM). Sem CPF, de propósito. Colunas do pagar tudo no fim (spec 3.2 e 10.9). */
function mcp_secretaria_csv(array $linhas): string
{
    $f = fopen('php://temp', 'w+');
    fputcsv($f, ['Data (Brasília)', 'Situação', 'Nome', 'E-mail', 'Telefone', 'Curso', 'Valor', 'Método', 'Escola', 'Horários', 'Começo', 'Recado',
        'Plano', 'Parcelas', 'Juros', 'Total cobrado', 'Espera', 'Na fila desde', 'Data limite', 'Turma definida em', 'Turma confirmada em', 'Motivo da devolução'], ';', '"', '');
    $espera = ['aguardando' => 'na fila', 'turma' => 'turma definida', 'devolver' => 'devolver'];
    foreach ($linhas as $l) {
        $p = $l['preferencia'] ?? null;
        $completo = ($l['plano'] ?? '') === 'taxa_e_matricula';
        $parcelas = ($l['metodo'] ?? 'pix') === 'pix' ? 1 : max(1, (int) ($l['parcelas'] ?? 1));
        $cobrado = (int) ($l['total_cobrado_centavos'] ?? 0) > 0 ? (int) $l['total_cobrado_centavos'] : (int) $l['total_centavos'];
        $data = static fn(?string $utc, string $formato = 'd/m/Y'): string => $utc ? mcp_data_brt($utc, $formato) : '';
        fputcsv($f, array_map('mcp_horarios_celula', [
            mcp_data_brt((string) ($l['pago_em'] ?: $l['criado_em']), 'd/m/Y H:i'),
            MCP_SECRETARIA_STATUS[$l['status']] ?? $l['status'],
            $l['nome'], $l['email'], mcp_telefone_bonito((string) $l['telefone']), $l['curso_nome'],
            mcp_brl((int) $l['total_centavos']), $l['metodo'] === 'pix' ? 'PIX' : 'Cartão',
            mcp_secretaria_escola($l)['rotulo'],
            $p ? mcp_horarios_texto($p['horarios']) : ($l['status'] === 'pago' ? 'não respondeu' : ''),
            $p ? (MCP_HORARIOS_INICIO[$p['inicio']] ?? $p['inicio']) : '',
            $p ? (string) $p['observacao'] : '',
            $completo ? 'Taxa + matrícula' : 'Só a taxa',
            $parcelas . '×',
            mcp_brl(max(0, (int) ($l['juros_centavos'] ?? 0))),
            mcp_brl($cobrado),
            $espera[$l['espera_status'] ?? ''] ?? '',
            $data($l['espera_desde'] ?? null),
            $data($l['espera_prazo'] ?? null),
            $data($l['espera_turma_email_em'] ?? null),
            $data($l['espera_turma_confirmada_em'] ?? null),
            MCP_SECRETARIA_DEVOLVER[$l['espera_devolver_motivo'] ?? ''] ?? '',
        ]), ';', '"', '');
    }
    rewind($f);
    $csv = (string) stream_get_contents($f);
    fclose($f);
    return "\xEF\xBB\xBF" . $csv;
}

// ----------------------------------------------------------------------------- pagar tudo: pagamento, adesão e espera
/** Coluna Pagamento da lista (spec 3.2 e 10.9): "R$ 354,33 · Taxa + matrícula · 10×", "… · espera turma" ou "R$ 99,00 · Só a taxa". */
function mcp_secretaria_pagamento_resumo(array $i): string
{
    if (($i['plano'] ?? '') !== 'taxa_e_matricula') {
        return mcp_brl((int) $i['total_centavos']) . ' · Só a taxa';
    }
    $cobrado = (int) ($i['total_cobrado_centavos'] ?? 0) > 0 ? (int) $i['total_cobrado_centavos'] : (int) $i['total_centavos'];
    $parcelas = ($i['metodo'] ?? 'pix') === 'pix' ? 1 : max(1, (int) ($i['parcelas'] ?? 1));
    return mcp_brl($cobrado) . ' · Taxa + matrícula · ' . $parcelas . '×' . (!empty($i['espera_status']) && $i['espera_status'] !== 'turma' ? ' · espera turma' : '');
}

/**
 * Linhas de valor da ficha: as da caixa do aviso à secretaria (spec 1.14), sem os dados pessoais: Pago (com a
 * decomposição), Plano, Matrícula, Parcelas e, quando houver, Diferença a devolver e Data limite.
 */
function mcp_secretaria_valores(array $i): array
{
    if (!function_exists('mcp_montar_email_secretaria_linhas')) {
        return ['Pago' => mcp_brl((int) $i['total_centavos'])];
    }
    $linhas = mcp_montar_email_secretaria_linhas($i);
    return array_intersect_key($linhas, array_flip(['Pago', 'Plano', 'Matrícula', 'Parcelas', 'Diferença a devolver', 'Data limite']));
}

/**
 * Cartão "Plano completo" (spec 3.2, 4.3 e 10.12): entre as pagas que viram as duas opções (plano_oferta = 'ambos'),
 * quantas escolheram taxa + matrícula, com turma e sem turma, e a distribuição das parcelas das que pagaram tudo.
 * Estornadas não entram (deixam de ser "pago"). Só contagens.
 */
function mcp_secretaria_plano_completo(): array
{
    if (!mcp_secretaria_colunas_ok()) {
        return [];
    }
    $semTurma = "(i.espera_desde IS NOT NULL OR i.turma_id IS NULL)";
    $linhas = mcp_db()->query("SELECT $semTurma AS sem_turma, COUNT(*) AS viram, SUM(i.plano = 'taxa_e_matricula') AS completo,
        SUM(i.plano = 'taxa_e_matricula' AND (i.metodo = 'pix' OR i.parcelas <= 1)) AS a_vista,
        SUM(i.plano = 'taxa_e_matricula' AND i.metodo = 'cartao' AND i.parcelas BETWEEN 2 AND 6) AS p2a6,
        SUM(i.plano = 'taxa_e_matricula' AND i.metodo = 'cartao' AND i.parcelas >= 7) AS p7a12,
        MIN(i.pago_em) AS desde
        FROM mcp_inscricoes i WHERE i.status = 'pago' AND i.plano_oferta = 'ambos' GROUP BY $semTurma ORDER BY sem_turma")->fetchAll();
    $saida = [];
    foreach ($linhas as $l) {
        $saida[(int) $l['sem_turma'] ? 'sem_turma' : 'com_turma'] = [
            'viram' => (int) $l['viram'], 'completo' => (int) $l['completo'], 'a_vista' => (int) $l['a_vista'],
            'p2a6' => (int) $l['p2a6'], 'p7a12' => (int) $l['p7a12'], 'desde' => $l['desde'],
        ];
    }
    return $saida;
}

/**
 * Planilha mensal da espera para o financeiro (spec 10.9, F19): por mês do pagamento (Brasília), as compras que pagaram
 * tudo sem turma: pagas, ainda esperando, viraram turma e devolvidas (ou a devolver). Valores com juros (o cobrado).
 */
function mcp_secretaria_csv_espera_mensal(): string
{
    $f = fopen('php://temp', 'w+');
    fputcsv($f, ['Mês do pagamento', 'Pagas sem turma', 'Valor pago', 'Esperando agora', 'Valor esperando', 'Viraram turma', 'Valor das que viraram turma', 'Devolvidas ou a devolver', 'Valor devolvido ou a devolver'], ';', '"', '');
    if (mcp_secretaria_colunas_ok()) {
        $linhas = mcp_db()->query("SELECT pago_em, status, espera_status, COALESCE(NULLIF(total_cobrado_centavos, 0), total_centavos) AS valor
            FROM mcp_inscricoes WHERE plano = 'taxa_e_matricula' AND espera_desde IS NOT NULL AND status IN ('pago', 'estornado') ORDER BY pago_em")->fetchAll();
        $meses = [];
        foreach ($linhas as $l) {
            $mes = mcp_data_brt((string) $l['pago_em'], 'Y-m');
            $m = &$meses[$mes];
            $m ??= ['pagas' => 0, 'valor' => 0, 'esperando' => 0, 'v_esperando' => 0, 'turma' => 0, 'v_turma' => 0, 'devolvidas' => 0, 'v_devolvidas' => 0];
            $valor = (int) $l['valor'];
            $m['pagas']++;
            $m['valor'] += $valor;
            if ($l['status'] === 'estornado' || $l['espera_status'] === 'devolver') {
                $m['devolvidas']++;
                $m['v_devolvidas'] += $valor;
            } elseif ($l['espera_status'] === 'turma') {
                $m['turma']++;
                $m['v_turma'] += $valor;
            } else {
                $m['esperando']++;
                $m['v_esperando'] += $valor;
            }
            unset($m);
        }
        foreach ($meses as $mes => $m) {
            fputcsv($f, [substr($mes, 5, 2) . '/' . substr($mes, 0, 4), $m['pagas'], mcp_brl($m['valor']), $m['esperando'], mcp_brl($m['v_esperando']),
                $m['turma'], mcp_brl($m['v_turma']), $m['devolvidas'], mcp_brl($m['v_devolvidas'])], ';', '"', '');
        }
    }
    rewind($f);
    $csv = (string) stream_get_contents($f);
    fclose($f);
    return "\xEF\xBB\xBF" . $csv;
}
