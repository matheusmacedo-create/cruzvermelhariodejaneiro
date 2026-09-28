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

function mcp_escola_tentar(int $id): void
{
    $inscricao = mcp_inscricao_por('id', (string) $id);
    if (!$inscricao) {
        return;
    }
    $tentativas = (int) $inscricao['escola_tentativas'] + 1;
    $curso = mcp_curso((string) $inscricao['curso_slug']);
    // O mesmo link em todas as tentativas: a escola só aceita o que foi gravado na primeira.
    $token = (string) ($inscricao['escola_token'] ?? '');
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        $token = bin2hex(random_bytes(32));
        mcp_atualizar($id, ['escola_token' => $token]);
    }
    $pagoEm = (string) ($inscricao['pago_em'] ?? '');
    $payload = ['dados' => [
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
    ]];
    $url = (string) mcp_cfg('ESCOLA_API_URL');
    $chave = (string) mcp_cfg('ESCOLA_API_TOKEN', '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json', 'Content-Type: application/json',
            // Chave secreta do Supabase da escola (papel service_role, que só executa esta função).
            'apikey: ' . $chave, 'Authorization: Bearer ' . $chave,
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 40,
        // HTTPS sempre; HTTP só para o teste local contra um PostgREST em 127.0.0.1.
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true) ? CURLPROTO_HTTP : 0),
    ]);
    $resposta = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);
    $dados = is_string($resposta) ? json_decode($resposta, true) : null;
    $dados = is_array($dados) ? $dados : [];

    if ($status === 200 && ($dados['ok'] ?? null) === true) {
        mcp_atualizar($id, [
            'escola_status' => 'ok', 'escola_tentativas' => $tentativas, 'escola_tentativa_em' => mcp_agora(),
            'escola_acesso' => json_encode([
                'resultado' => ($dados['resultado'] ?? '') === 'sem_turma' ? 'sem_turma' : 'matriculado',
                'aluno_novo' => (bool) ($dados['aluno_novo'] ?? false),
                'email_conta' => is_string($dados['email_conta'] ?? null) ? $dados['email_conta'] : null,
                'email_confere' => (bool) ($dados['email_confere'] ?? true),
                'matricula_id' => is_string($dados['matricula_id'] ?? null) ? $dados['matricula_id'] : null,
                'turma_inicio' => is_string($dados['turma']['inicio'] ?? null) ? $dados['turma']['inicio'] : null,
                'aviso' => is_string($dados['aviso'] ?? null) ? $dados['aviso'] : null,
                'link' => ($dados['link_acesso'] ?? false) === true,
                'link_expira_em' => is_string($dados['link_expira_em'] ?? null) ? $dados['link_expira_em'] : null,
                'url_login' => mcp_escola_url_login($dados['url_login'] ?? null),
            ], JSON_UNESCAPED_UNICODE),
        ]);
        mcp_registrar($id, 'escola_ok', "tentativa $tentativas · " . ($dados['resultado'] ?? '?')
            . (!empty($dados['repetido']) ? ' · repetido' : '') . (!empty($dados['aviso']) ? ' · ' . $dados['aviso'] : ''));
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

/** Retentativa enquanto o aluno acompanha a tela: no máximo 5 tentativas, 1 por minuto. */
function mcp_escola_retentar_se_preciso(array $inscricao): array
{
    if (($inscricao['status'] ?? '') !== 'pago' || ($inscricao['escola_status'] ?? '') !== 'erro'
        || (int) $inscricao['escola_tentativas'] >= MCP_ESCOLA_MAX_TENTATIVAS) {
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

/** Uma linha para a secretaria: o que aconteceu na plataforma da escola com esta inscrição. */
function mcp_escola_resumo(array $inscricao): string
{
    if (!mcp_escola_configurada()) {
        return 'sem integração: criar a matrícula e aplicar o valor da inscrição';
    }
    $acesso = mcp_escola_acesso($inscricao);
    if ($acesso) {
        $conta = !empty($acesso['aluno_novo']) ? 'conta criada pelo site'
            : 'conta que já existia' . (empty($acesso['email_confere']) && !empty($acesso['email_conta']) ? ' (e-mail da conta: ' . $acesso['email_conta'] . ')' : '');
        if ($acesso['resultado'] === 'sem_turma') {
            return "SEM TURMA ABERTA: $conta. Matricular o aluno quando abrir turma e aplicar a inscrição paga";
        }
        $data = mcp_escola_data($acesso['turma_inicio'] ?? null);
        $texto = 'matriculado' . ($data !== '' ? " na turma que começa em $data" : '') . ", $conta, taxa confirmada";
        if (($acesso['aviso'] ?? '') === 'taxa_ja_confirmada') {
            $texto .= '. ATENÇÃO: a taxa já estava confirmada na escola; avaliar o estorno desta inscrição';
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
        return 'NÃO MATRICULADO: ' . ($motivos[$erro] ?? $erro) . '. Criar a matrícula à mão e aplicar o valor da inscrição';
    }
    return ($inscricao['escola_status'] ?? '') === 'erro'
        ? 'falha técnica na integração (o site tenta de novo enquanto o aluno está na página): conferir na escola antes de matricular à mão'
        : 'matrícula na escola em andamento';
}
