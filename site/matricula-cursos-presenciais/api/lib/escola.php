<?php
/**
 * Matrícula na plataforma da escola logo depois do pagamento (versão A da tela Parabéns).
 *
 * Chama a função public.matricula_rapida do banco da escola (Supabase) pela API REST, com a chave
 * secreta do projeto: POST {ESCOLA_API_URL} com {"dados": {...}}. A função acha o aluno pelo CPF
 * ou cria a conta, matricula na próxima turma aberta com a taxa confirmada e devolve o resultado.
 * Conta nova: senha aleatória que ninguém conhece (nunca os 4 últimos dígitos do CPF, briefing 8.2)
 * e um link único de "criar senha" na própria escola (/redefinir-senha, 72 horas). O link fica só
 * aqui (escola_token); a escola guarda o sha256 dele, como faz com os links que ela mesma manda.
 * SQL, testes e o passo a passo: docs/escola/.
 */
declare(strict_types=1);

const MCP_ESCOLA_MAX_TENTATIVAS = 5;
const MCP_ESCOLA_INTERVALO = 60;
/**
 * Avisos da escola que o site guarda (3.2 e 10.7): o resto é descartado. matricula_nao_marcada também é derivado
 * pelo site (plano completo sem matricula_paga = true).
 */
const MCP_ESCOLA_AVISOS = ['taxa_ja_confirmada', 'curso_ja_pago', 'taxa_em_dobro', 'turma_lotada', 'turma_diferente',
    'matricula_paga_sem_turma', 'preco_divergente', 'cobranca_escola_aberta', 'matricula_nao_marcada', 'taxa_paga_antes', 'pendente_antiga'];

/**
 * POST JSON à API REST da escola com a chave secreta (papel service_role, que só executa as funções do site).
 * Devolve ['status' => HTTP (0 = rede), 'dados' => JSON decodificado ou null, 'erro' => erro de transporte].
 * Nada do corpo vai para log.
 */
function mcp_escola_post(string $url, string $corpo, int $tempo = 40): array
{
    $chave = (string) mcp_cfg('ESCOLA_API_TOKEN', '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $corpo,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json', 'Content-Type: application/json',
            // Chave secreta do Supabase da escola (papel service_role, que só executa esta função).
            'apikey: ' . $chave, 'Authorization: Bearer ' . $chave,
        ],
        CURLOPT_CONNECTTIMEOUT => min(10, $tempo),
        CURLOPT_TIMEOUT => $tempo,
        // HTTPS sempre; HTTP só para o teste local contra um PostgREST em 127.0.0.1.
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true) ? CURLPROTO_HTTP : 0),
    ]);
    $resposta = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erro = curl_error($ch);
    curl_close($ch);
    return ['status' => $resposta === false ? 0 : $status, 'dados' => is_string($resposta) ? json_decode($resposta, true) : null, 'erro' => $erro];
}

/**
 * O {"dados": {...}} da matricula_rapida, igual em todas as tentativas (a mesma referência, o mesmo pago_em, o mesmo
 * link). Só a taxa: exatamente o de antes (é assim que a v2 cai no caminho da v1). Plano completo (3.2): mais
 * matricula_centavos, parcelas, total_centavos = max(total cobrado, amount), juros_centavos (0 no 1×) e turma_id (a
 * turma vendida; nunca numa compra que espera turma, 10.8).
 */
function mcp_escola_payload(array $inscricao, string $token): array
{
    $curso = mcp_curso((string) $inscricao['curso_slug']);
    $pagoEm = (string) ($inscricao['pago_em'] ?? '');
    $dados = [
        'nome' => $inscricao['nome'],
        'cpf' => $inscricao['cpf'],
        'email' => $inscricao['email'],
        'celular' => $inscricao['telefone'],
        'curso_id' => (string) ($curso['uuid'] ?? ''),
        'transacao' => $inscricao['unicopag_hash'],
        'metodo' => $inscricao['metodo'],
        'valor_centavos' => (int) $inscricao['inscricao_centavos'],
        'total_centavos' => (int) $inscricao['total_centavos'],
        'pago_em' => $pagoEm !== '' ? gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($pagoEm . ' UTC')) : null,
        'referencia' => (string) $inscricao['id'],
        'senha_hash' => mcp_escola_senha_aleatoria(),
        'token_hash' => hash('sha256', $token),
    ];
    if (($inscricao['plano'] ?? 'so_taxa') === 'taxa_e_matricula' && (int) ($inscricao['matricula_centavos'] ?? 0) > 0) {
        $amount = (int) $inscricao['total_centavos'];
        $parcelas = $inscricao['metodo'] === 'pix' ? 1 : max(1, min(12, (int) ($inscricao['parcelas'] ?? 1)));
        $total = max((int) ($inscricao['total_cobrado_centavos'] ?? 0), $amount);
        $dados['matricula_centavos'] = (int) $inscricao['matricula_centavos'];
        $dados['parcelas'] = $parcelas;
        $dados['total_centavos'] = $total;
        $dados['juros_centavos'] = $parcelas > 1 ? max(0, $total - $amount) : 0;
        if (!empty($inscricao['turma_id']) && empty($inscricao['espera_status'])) {
            $dados['turma_id'] = (string) $inscricao['turma_id'];
        }
    } else {
        // Só a taxa: com gente que pagou tudo esperando turma deste curso, a escola não dá a esta compra a vaga da turma
        // nova antes da fila (fila_espera, v2 revisada em 09/10). Sem fila, o pedido é exatamente o de sempre.
        $fila = function_exists('mcp_espera_fila') ? mcp_espera_fila((string) $inscricao['curso_slug'], false) : 0;
        if ($fila > 0 && $fila < PHP_INT_MAX) {
            $dados['fila_espera'] = min(9999, $fila);
        }
    }
    return ['dados' => $dados];
}

/** O link de criar senha desta inscrição (o mesmo em todas as tentativas: a escola só aceita o da primeira). */
function mcp_escola_token(array $inscricao): string
{
    $token = (string) ($inscricao['escola_token'] ?? '');
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        $token = bin2hex(random_bytes(32));
        mcp_atualizar((int) $inscricao['id'], ['escola_token' => $token]);
    }
    return $token;
}

/**
 * O que guardar em escola_acesso a partir de uma resposta ok da escola. Plano completo: matricula_paga (null = a chave
 * não veio: função antiga), avisos filtrados e, sem matricula_paga = true e sem curso_ja_pago,
 * matricula_paga_sem_turma ou turma_diferente, o aviso matricula_nao_marcada (também no repetido; F3, T3). A turma
 * traz a data, a primeira aula, o horário e o status (10.7).
 */
function mcp_escola_acesso_da_resposta(array $dados, bool $completo): array
{
    $avisos = [];
    foreach (array_merge(is_array($dados['avisos'] ?? null) ? $dados['avisos'] : [], [$dados['aviso'] ?? null]) as $a) {
        if (is_string($a) && in_array($a, MCP_ESCOLA_AVISOS, true) && !in_array($a, $avisos, true)) {
            $avisos[] = $a;
        }
    }
    $temChave = array_key_exists('matricula_paga', $dados);
    $paga = $temChave ? $dados['matricula_paga'] === true : null;
    if ($completo && $paga !== true && !array_intersect(['curso_ja_pago', 'matricula_paga_sem_turma', 'turma_diferente', 'matricula_nao_marcada'], $avisos)) {
        $avisos[] = 'matricula_nao_marcada';
    }
    $turma = is_array($dados['turma'] ?? null) ? $dados['turma'] : [];
    $texto = static fn(mixed $v, int $n = 64): ?string => is_string($v) && $v !== '' ? mb_substr($v, 0, $n) : null;
    return [
        'resultado' => ($dados['resultado'] ?? '') === 'sem_turma' ? 'sem_turma' : 'matriculado',
        'aluno_novo' => (bool) ($dados['aluno_novo'] ?? false),
        'email_conta' => is_string($dados['email_conta'] ?? null) ? $dados['email_conta'] : null,
        'email_confere' => (bool) ($dados['email_confere'] ?? true),
        'matricula_id' => $texto($dados['matricula_id'] ?? null),
        'turma_inicio' => $texto($turma['inicio'] ?? null, 10),
        'aviso' => is_string($dados['aviso'] ?? null) ? $dados['aviso'] : null,
        'link' => ($dados['link_acesso'] ?? false) === true,
        'link_expira_em' => is_string($dados['link_expira_em'] ?? null) ? $dados['link_expira_em'] : null,
        'url_login' => mcp_escola_url_login($dados['url_login'] ?? null),
        // Pagar tudo (10/2026)
        'matricula_paga' => $paga,
        'avisos' => $avisos,
        'turma_id' => $texto($turma['id'] ?? null),
        'turma_primeira_aula' => $texto($turma['primeira_aula'] ?? null, 10),
        'turma_horario' => $texto($turma['horario'] ?? null, 40),
        'turma_status' => $texto($turma['status'] ?? null, 20),
        'matricula_status' => $texto($dados['matricula_status'] ?? null, 20),
        'espera_motivo' => $texto($dados['espera_motivo'] ?? null, 24),
    ];
}

function mcp_escola_tentar(int $id): void
{
    $inscricao = mcp_inscricao_por('id', (string) $id);
    if (!$inscricao) {
        return;
    }
    // Só a compra paga vai à escola (uma reconsulta pode tê-la achado estornada no meio do pós-pagamento).
    if (($inscricao['status'] ?? '') !== 'pago') {
        return;
    }
    // Compra que espera turma (10.8): só mcp_espera_chamar() fala com a escola por ela, sempre com espera_turma.
    if (!empty($inscricao['espera_status'])) {
        return;
    }
    // Opção 1 vendida sem turma que ainda não entrou na fila (pós-pagamento interrompido): entra agora, e a chamada é a
    // so_conta. Nunca sai uma chamada sem espera_turma para ela: a v2 a matricularia numa turma qualquer, sem carência e na
    // frente da fila (10.8).
    if (($inscricao['plano'] ?? 'so_taxa') === 'taxa_e_matricula' && empty($inscricao['turma_id'])) {
        if (function_exists('mcp_espera_entrar') && mcp_espera_entrar($id)) {
            mcp_espera_chamar($id, true);
        }
        return;
    }
    $tentativas = (int) $inscricao['escola_tentativas'] + 1;
    $completo = ($inscricao['plano'] ?? 'so_taxa') === 'taxa_e_matricula';
    // Plano completo só vai a uma escola que responde a versão 2 ou mais: a v1 leria o pedido como "só a taxa" e
    // cobraria a matrícula de novo na área do aluno (E12). Conta como tentativa: esgotadas, o aviso sai URGENTE.
    if ($completo && mcp_escola_versao() < 2) {
        mcp_atualizar($id, ['escola_status' => 'erro', 'escola_tentativas' => $tentativas, 'escola_tentativa_em' => mcp_agora()]);
        mcp_registrar($id, 'escola_versao_antiga', "tentativa $tentativas · plano completo sem a v2 da escola");
        return;
    }
    $payload = mcp_escola_payload($inscricao, mcp_escola_token($inscricao));
    $r = mcp_escola_post((string) mcp_cfg('ESCOLA_API_URL'), (string) json_encode($payload, JSON_UNESCAPED_UNICODE), 40);
    $status = $r['status'];
    $dados = is_array($r['dados']) ? $r['dados'] : [];
    $erroCurl = $r['erro'];

    if ($status === 200 && ($dados['ok'] ?? null) === true) {
        $acesso = mcp_escola_acesso_da_resposta($dados, $completo);
        mcp_atualizar($id, [
            'escola_status' => 'ok', 'escola_tentativas' => $tentativas, 'escola_tentativa_em' => mcp_agora(),
            'escola_acesso' => json_encode($acesso, JSON_UNESCAPED_UNICODE),
        ]);
        if ($completo && !array_key_exists('matricula_paga', $dados)) {
            mcp_registrar($id, 'escola_funcao_antiga', 'resposta sem matricula_paga num plano completo');
        }
        mcp_registrar($id, 'escola_ok', "tentativa $tentativas · " . ($dados['resultado'] ?? '?')
            . (!empty($dados['repetido']) ? ' · repetido' : '') . ($acesso['avisos'] ? ' · ' . implode(', ', $acesso['avisos']) : ''));
        // PIX do plano completo pago depois de a turma fechar (2.5): a compra entra na fila da próxima turma (10.8).
        if ($completo && $acesso['resultado'] === 'sem_turma' && in_array('matricula_paga_sem_turma', $acesso['avisos'], true)) {
            mcp_espera_entrar($id);
        }
        return;
    }

    // Conflito (ok=false: e-mail de outra conta, CPF da equipe...) ou dado recusado (HTTP 400): tentar
    // de novo não resolve. Fica o motivo para o aviso à secretaria; tela e e-mail do aluno vão na versão B.
    $conflito = $status === 200 && ($dados['ok'] ?? null) === false;
    $recusado = $status === 400 && str_starts_with((string) ($dados['message'] ?? ''), 'dados inválidos');
    $motivo = $conflito ? (string) ($dados['erro'] ?? 'conflito') : ($recusado ? (string) $dados['message'] : '');
    $definitivo = $conflito || $status === 400;
    mcp_atualizar($id, [
        'escola_status' => 'erro',
        'escola_tentativas' => $definitivo ? max($tentativas, MCP_ESCOLA_MAX_TENTATIVAS) : $tentativas,
        'escola_tentativa_em' => mcp_agora(),
        'escola_acesso' => $definitivo ? json_encode(['erro' => mb_substr($motivo !== '' ? $motivo : 'recusado', 0, 120)], JSON_UNESCAPED_UNICODE) : null,
    ]);
    // Só o status, o código e o erro de transporte: nada de credencial nem dado do aluno.
    $codigo = (string) ($dados['code'] ?? '');
    mcp_registrar($id, 'escola_erro', "tentativa $tentativas · HTTP $status" . ($codigo !== '' ? " · $codigo" : '')
        . ($motivo !== '' ? " · $motivo" : '') . ($erroCurl !== '' ? " · $erroCurl" : ''));
}

/**
 * Senha de conta nova: aleatória, em argon2id (o login da escola confere com argon2). Ninguém a
 * conhece: o aluno cria a dele pelo link único (ou pelo "Esqueci minha senha" da escola).
 */
function mcp_escola_senha_aleatoria(): ?string
{
    $senha = bin2hex(random_bytes(32));
    if (defined('PASSWORD_ARGON2ID')) {
        $hash = password_hash($senha, PASSWORD_ARGON2ID);
        if (is_string($hash) && str_starts_with($hash, '$argon2id$')) {
            return $hash;
        }
    }
    if (function_exists('sodium_crypto_pwhash_str')) {
        return sodium_crypto_pwhash_str($senha, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE);
    }
    return null;
}

/** Link único para o aluno criar a senha na escola, enquanto vale. Credencial: nunca vai para log. */
function mcp_escola_link(array $inscricao): ?string
{
    $acesso = mcp_escola_acesso($inscricao);
    $token = (string) ($inscricao['escola_token'] ?? '');
    if (!$acesso || empty($acesso['link']) || !preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }
    $expira = is_string($acesso['link_expira_em'] ?? null) ? strtotime($acesso['link_expira_em']) : false;
    if ($expira === false || $expira <= time()) {
        return null;
    }
    return rtrim((string) mcp_cfg('ESCOLA_URL', 'https://escola.cursoscruzvermelha.org'), '/') . '/redefinir-senha?token=' . $token;
}

/** Validade do link (ISO em UTC) no horário de Brasília: "01/10 às 19h30". */
function mcp_escola_link_validade(array $inscricao): string
{
    $acesso = mcp_escola_acesso($inscricao);
    $expira = is_string($acesso['link_expira_em'] ?? null) ? strtotime($acesso['link_expira_em']) : false;
    return $expira ? mcp_data_brt(gmdate('Y-m-d H:i:s', $expira)) : '';
}

/** Link de entrada devolvido pela escola, só se for do mesmo endereço configurado (senão, o padrão). */
function mcp_escola_url_login(mixed $url): string
{
    $padrao = rtrim((string) mcp_cfg('ESCOLA_URL', 'https://escola.cursoscruzvermelha.org'), '/') . '/login';
    if (!is_string($url) || !str_starts_with($url, 'https://')) {
        return $padrao;
    }
    return parse_url($url, PHP_URL_HOST) === parse_url($padrao, PHP_URL_HOST) ? $url : $padrao;
}

/**
 * Retentativa enquanto o aluno acompanha a tela: no máximo 5 tentativas, 1 por minuto. Aceita também a escola ainda
 * 'pendente' 120 s depois do pagamento (pós-pagamento interrompido, T4). Compra que espera turma: nunca (10.8).
 */
function mcp_escola_retentar_se_preciso(array $inscricao): array
{
    $escola = (string) ($inscricao['escola_status'] ?? '');
    // strtotime direto (e não mcp_utc_ts, do compra.php): o status.php chama esta função a cada consulta, e na publicação o
    // opcache pode servir este arquivo com o lib.php antigo por alguns segundos (T10).
    $pago = !empty($inscricao['pago_em']) ? strtotime($inscricao['pago_em'] . ' UTC') : false;
    $presa = $escola === 'pendente' && $pago !== false && time() - $pago >= 120;
    if (($inscricao['status'] ?? '') !== 'pago' || !empty($inscricao['espera_status']) || ($escola !== 'erro' && !$presa)
        || (int) $inscricao['escola_tentativas'] >= MCP_ESCOLA_MAX_TENTATIVAS || !mcp_escola_configurada()) {
        return $inscricao;
    }
    $ultima = $inscricao['escola_tentativa_em'] ? (int) strtotime($inscricao['escola_tentativa_em'] . ' UTC') : 0;
    if (time() - $ultima < MCP_ESCOLA_INTERVALO) {
        return $inscricao;
    }
    mcp_escola_tentar((int) $inscricao['id']);
    $atual = mcp_inscricao_por('id', (string) $inscricao['id']) ?? $inscricao;
    if ($atual['escola_status'] === 'ok' && $atual['email_aluno'] !== 'acesso') {
        mcp_email_aluno_pago($atual);
    }
    return $atual;
}

/** O que a escola devolveu quando deu certo, pronto para a tela e o e-mail. Nunca vai para log. */
function mcp_escola_acesso(array $inscricao): ?array
{
    if (empty($inscricao['escola_acesso'])) {
        return null;
    }
    $dados = json_decode((string) $inscricao['escola_acesso'], true);
    return is_array($dados) && empty($dados['erro']) && !empty($dados['resultado']) ? $dados : null;
}

/** Motivo de uma recusa definitiva da escola (e-mail de outra conta, CPF da equipe...), ou null. */
function mcp_escola_erro(array $inscricao): ?string
{
    $dados = empty($inscricao['escola_acesso']) ? null : json_decode((string) $inscricao['escola_acesso'], true);
    return is_array($dados) && !empty($dados['erro']) ? (string) $dados['erro'] : null;
}

/** Data da turma (AAAA-MM-DD) como 21/10/2026. */
function mcp_escola_data(?string $iso): string
{
    return is_string($iso) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) ? "$m[3]/$m[2]/$m[1]" : '';
}

/** Avisos da escola guardados nesta inscrição (só os da lista permitida). */
function mcp_escola_avisos(array $inscricao): array
{
    $acesso = mcp_escola_acesso($inscricao);
    if (!$acesso) {
        return [];
    }
    $avisos = is_array($acesso['avisos'] ?? null) ? $acesso['avisos'] : [];
    if (is_string($acesso['aviso'] ?? null)) {
        $avisos[] = $acesso['aviso']; // inscrições de antes: só o aviso principal
    }
    return array_values(array_unique(array_filter($avisos, static fn($a): bool => is_string($a) && in_array($a, MCP_ESCOLA_AVISOS, true))));
}

/**
 * A escola registrou o curso como pago por esta transação? true, false ou null (sem resposta ok, ou a resposta não
 * trouxe a chave). O feed da escola (horarios.php, E13) só leva uma inscrição taxa_e_matricula com true.
 */
function mcp_escola_matricula_paga(array $inscricao): ?bool
{
    $acesso = mcp_escola_acesso($inscricao);
    if (!$acesso || !array_key_exists('matricula_paga', $acesso) || $acesso['matricula_paga'] === null) {
        return null;
    }
    return $acesso['matricula_paga'] === true;
}

/**
 * O aviso de pagamento à secretaria sai com "URGENTE: " (1.14): plano completo com curso_ja_pago,
 * matricula_nao_marcada, turma_diferente, matricula_paga_sem_turma fora da fila, escola em erro definitivo ou com as
 * tentativas esgotadas, e diferença a devolver; opção 2 com taxa_ja_confirmada.
 */
function mcp_escola_urgente(array $inscricao): bool
{
    $avisos = mcp_escola_avisos($inscricao);
    if (($inscricao['plano'] ?? 'so_taxa') !== 'taxa_e_matricula') {
        return in_array('taxa_ja_confirmada', $avisos, true);
    }
    if ((int) ($inscricao['diferenca_devolver_centavos'] ?? 0) > 0 || array_intersect(['curso_ja_pago', 'matricula_nao_marcada', 'turma_diferente'], $avisos)) {
        return true;
    }
    if (in_array('matricula_paga_sem_turma', $avisos, true) && empty($inscricao['espera_status'])) {
        return true;
    }
    return mcp_escola_configurada() && ($inscricao['escola_status'] ?? '') === 'erro'
        && (mcp_escola_erro($inscricao) !== null || (int) ($inscricao['escola_tentativas'] ?? 0) >= MCP_ESCOLA_MAX_TENTATIVAS);
}

/** A data da turma da resposta da escola (primeira aula, senão o início) como 21/10/2026. */
function mcp_escola_data_turma(?array $acesso): string
{
    return $acesso ? mcp_escola_data($acesso['turma_primeira_aula'] ?? $acesso['turma_inicio'] ?? null) : '';
}

/**
 * Devolução da desistência depois da turma confirmada (P4 = B, spec 5.1): a matrícula com os juros proporcionais,
 * matrícula × total cobrado ÷ amount (em 10×: 180 × 354,33 ÷ 279 = R$ 228,60).
 */
function mcp_devolucao_p4b(array $i): int
{
    return (int) round((int) ($i['matricula_centavos'] ?? 0) * mcp_total_cobrado($i) / max(1, (int) $i['total_centavos']));
}

/** Uma linha para a secretaria: o que aconteceu na plataforma da escola com esta inscrição. */
function mcp_escola_resumo(array $inscricao): string
{
    if (!mcp_escola_configurada()) {
        return 'sem integração: criar a matrícula e aplicar o valor da inscrição';
    }
    $completo = ($inscricao['plano'] ?? 'so_taxa') === 'taxa_e_matricula';
    $acesso = mcp_escola_acesso($inscricao);
    $conta = $acesso ? (!empty($acesso['aluno_novo']) ? 'conta criada pelo site'
        : 'conta que já existia' . (empty($acesso['email_confere']) && !empty($acesso['email_conta']) ? ' (e-mail da conta: ' . $acesso['email_conta'] . ')' : '')) : 'conta ainda não confirmada pela escola';
    $naoMarcado = 'PAGOU TAXA + MATRÍCULA, NÃO MARCADO COMO PAGO NA ESCOLA: matricular à mão como À vista, PAGO, Pagamento CURSO de '
        . mcp_brl((int) $inscricao['inscricao_centavos'] + (int) ($inscricao['matricula_centavos'] ?? 0))
        . ', gateway unicopag-2; NÃO usar "Encaixar". Depois, marcar "Já resolvi na escola" e avisar a TI';
    $espera = $completo ? ($inscricao['espera_status'] ?? null) : null;
    if ($espera === 'devolver') {
        $motivos = ['prazo' => 'data limite', 'pedido' => 'pedido da pessoa', 'data_nao_serve' => 'a data não serve',
            'requisito' => 'requisito', 'curso_ja_pago' => 'curso já pago na escola', 'turma_cancelada' => 'turma cancelada sem resposta', 'secretaria' => 'decisão da secretaria'];
        $id = (string) ($inscricao['espera_matricula_escola'] ?? '');
        if (($inscricao['espera_devolver_motivo'] ?? '') === 'pedido' && !empty($inscricao['espera_turma_confirmada_em'])) {
            // Desistência depois da turma confirmada (P4, versão B; decisão 10): volta a matrícula com os juros dela.
            return 'DESISTÊNCIA DEPOIS DA CONFIRMAÇÃO (regra B): devolver por PIX ' . mcp_brl(mcp_devolucao_p4b($inscricao))
                . ' (a matrícula, com os juros do parcelamento que correspondem a ela); a taxa de inscrição fica. NÃO estornar a compra inteira'
                . ($id !== '' ? "; depois, cancelar a matrícula $id na escola" : '; depois, cancelar a matrícula na escola');
        }
        return 'PAGOU TUDO, DEVOLVER (' . ($motivos[$inscricao['espera_devolver_motivo'] ?? ''] ?? 'devolução') . '): '
            . ($id !== '' ? "antes, cancelar a matrícula $id na escola; depois, " : '') . 'estornar a compra inteira no painel da Unicopag';
    }
    if ($espera === 'aguardando') {
        return "PAGOU TUDO, ESPERA TURMA: $conta. Nada a fazer na escola agora; o site matricula sozinho quando a turma abrir. NÃO marcar nada como pago, NÃO usar Encaixar nem o convite de \"sem inscrição\"";
    }
    if ($acesso) {
        $avisos = mcp_escola_avisos($inscricao);
        if (!$completo) {
            if ($acesso['resultado'] === 'sem_turma') {
                return "SEM TURMA ABERTA: $conta. Matricular o aluno quando abrir turma e aplicar a inscrição paga";
            }
            $data = mcp_escola_data($acesso['turma_inicio'] ?? null);
            $texto = 'matriculado' . ($data !== '' ? " na turma que começa em $data" : '') . ", $conta, taxa confirmada";
            if (in_array('taxa_ja_confirmada', $avisos, true)) {
                $texto .= '. ATENÇÃO: a taxa já estava confirmada na escola; avaliar o estorno desta inscrição';
            }
            return $texto;
        }
        if (in_array('curso_ja_pago', $avisos, true)) {
            return 'MATRÍCULA JÁ ESTAVA PAGA na escola: estornar esta compra inteira no painel da Unicopag';
        }
        if (in_array('matricula_paga_sem_turma', $avisos, true)) {
            return "MATRÍCULA PAGA SEM TURMA: $conta. Oferecer a próxima turma, com tudo pago, ou a devolução total (estorno no painel da Unicopag)";
        }
        if (in_array('matricula_nao_marcada', $avisos, true)) {
            return $naoMarcado;
        }
        $dataEscola = mcp_escola_data_turma($acesso);
        if (in_array('turma_diferente', $avisos, true)) {
            $vendida = mcp_oferta_turma(isset($inscricao['turma_id']) ? (string) $inscricao['turma_id'] : null);
            $dataSite = $vendida['data'] ?? (mcp_espera_data_limite($inscricao['turma_inicio'] ?? null) ?: '?');
            $texto = "TURMA DIFERENTE DA VENDIDA: o aluno pagou pela turma de $dataSite e a escola gravou a de " . ($dataEscola ?: '?')
                . '. Confirmar com ele; se a data não servir, estorno total';
        } else {
            $texto = 'matriculado' . ($dataEscola !== '' ? " na turma que começa em $dataEscola" : '') . ", $conta, inscrição e matrícula pagas no site";
        }
        if (in_array('turma_lotada', $avisos, true)) {
            $texto .= '. ATENÇÃO: entrou acima da vaga (turma marcada como lotada depois do pagamento): ajuste as vagas da turma na escola';
        }
        if (in_array('taxa_em_dobro', $avisos, true) || in_array('taxa_paga_antes', $avisos, true)) {
            $amount = max(1, (int) $inscricao['total_centavos']);
            $valor = (int) round((int) $inscricao['inscricao_centavos'] * mcp_total_cobrado($inscricao) / $amount);
            $texto .= in_array('taxa_em_dobro', $avisos, true)
                ? '. ATENÇÃO: a taxa já estava paga; devolver por PIX ' . mcp_brl($valor)
                : '. ATENÇÃO: a taxa já tinha sido paga numa inscrição anterior do site, sem turma; devolver por PIX a taxa de inscrição paga a mais (' . mcp_brl($valor) . ')';
        }
        if (in_array('pendente_antiga', $avisos, true)) {
            $texto .= '. ATENÇÃO: há uma matrícula pendente antiga, com a taxa paga, numa turma deste curso que já começou; se o aluno não fez aquela turma, cancelá-la na escola e devolver a taxa paga a mais';
        }
        if (in_array('preco_divergente', $avisos, true)) {
            $texto .= '. ATENÇÃO: o preço da matrícula no site difere do da escola; conferir';
        }
        if (in_array('cobranca_escola_aberta', $avisos, true)) {
            $texto .= '. ATENÇÃO: havia cobrança online da escola em aberto para esta matrícula, já cancelada; conferir se o aluno não pagou também por lá';
        }
        return $texto;
    }
    $erro = mcp_escola_erro($inscricao);
    if ($erro !== null) {
        $motivos = [
            'email_em_uso' => 'o e-mail já está em outra conta da escola (com outro CPF)',
            'documento_da_equipe' => 'o CPF é de uma conta da equipe da escola',
            'aluno_bloqueado' => 'a conta do aluno está bloqueada na escola',
            'curso_inativo' => 'o curso está inativo na escola',
            'curso_inexistente' => 'o curso não foi encontrado na escola',
        ];
        if ($completo) {
            return 'NÃO MATRICULADO (' . ($motivos[$erro] ?? $erro) . '). ' . $naoMarcado;
        }
        return 'NÃO MATRICULADO: ' . ($motivos[$erro] ?? $erro) . '. Criar a matrícula à mão e aplicar o valor da inscrição';
    }
    if ($completo && ($inscricao['escola_status'] ?? '') === 'erro' && (int) ($inscricao['escola_tentativas'] ?? 0) >= MCP_ESCOLA_MAX_TENTATIVAS) {
        return $naoMarcado;
    }
    return ($inscricao['escola_status'] ?? '') === 'erro'
        ? 'falha técnica na integração (o site tenta de novo sozinho, a cada 15 minutos e enquanto o aluno está na página): conferir na escola antes de matricular à mão'
        : 'matrícula na escola em andamento';
}
