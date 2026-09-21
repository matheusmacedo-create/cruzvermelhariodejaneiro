<?php
/**
 * POST: mensagem do chat de contato do site (site/chat/chat.js). Grava em mcp_contatos, manda o aviso
 * à equipe (EMAIL_CONTATO, com responder-para de quem escreveu) e a confirmação à pessoa, e devolve o
 * protocolo. Entrou em 19/09/2026 no lugar do WhatsApp da secretaria como canal de dúvidas.
 *
 * O banco é a fonte da verdade: se o e-mail falhar, a mensagem continua gravada (email_equipe = 'falhou').
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Limites de abuso: por IP e por e-mail, na última hora. Folga para uma pessoa, freio para robô.
const MCP_CONTATO_LIMITE_IP = [8, 3600];
const MCP_CONTATO_LIMITE_EMAIL = [4, 3600];
const MCP_CONTATO_MENSAGEM_MIN = 10;
const MCP_CONTATO_MENSAGEM_MAX = 3000;

/** Campos validados. Cada falha responde 422 com o nome do campo, e o chat volta para a pergunta certa. */
function mcp_validar_contato(array $b): array
{
    $nome = mcp_texto($b['nome'] ?? '', 120);
    if (mb_strlen($nome) < 2) {
        mcp_falhar(422, 'Digite seu nome para a gente saber com quem fala.', ['campo' => 'nome']);
    }
    $email = mb_strtolower(mcp_texto($b['email'] ?? '', 190));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        mcp_falhar(422, 'Esse e-mail não parece válido. Confira e envie de novo.', ['campo' => 'email']);
    }
    $telefoneBruto = mcp_texto($b['telefone'] ?? '', 30);
    $telefone = $telefoneBruto === '' ? '' : mcp_telefone($telefoneBruto);
    if ($telefoneBruto !== '' && $telefone === '') {
        mcp_falhar(422, 'Telefone incompleto. Use DDD + número, ou deixe em branco.', ['campo' => 'telefone']);
    }
    $assunto = mcp_texto($b['assunto'] ?? '', 30);
    if (!array_key_exists($assunto, mcp_contato_assuntos())) {
        $assunto = 'outro';
    }
    $curso = mcp_contato_com_curso($assunto) ? mcp_curso(mcp_texto($b['curso'] ?? '', 80)) : null;
    $mensagem = mcp_texto_longo($b['mensagem'] ?? '', MCP_CONTATO_MENSAGEM_MAX + 1);
    if (mb_strlen($mensagem) < MCP_CONTATO_MENSAGEM_MIN) {
        mcp_falhar(422, 'Conte um pouco mais: a mensagem precisa ter pelo menos ' . MCP_CONTATO_MENSAGEM_MIN . ' caracteres.', ['campo' => 'mensagem']);
    }
    if (mb_strlen($mensagem) > MCP_CONTATO_MENSAGEM_MAX) {
        mcp_falhar(422, 'A mensagem pode ter até ' . number_format(MCP_CONTATO_MENSAGEM_MAX, 0, ',', '.') . ' caracteres.', ['campo' => 'mensagem']);
    }
    // Página de onde veio: só caminhos do próprio site (o chat manda pathname + query).
    $pagina = mcp_texto($b['pagina'] ?? '', 255);
    if ($pagina !== '' && (!str_starts_with($pagina, '/') || str_starts_with($pagina, '//'))) {
        $pagina = '';
    }
    // O que o chat já respondeu antes de a pessoa abrir chamado. Cada texto é conferido contra as
    // perguntas que existem de verdade; o que não bate é descartado. Serve para a equipe não
    // repetir o que a pessoa acabou de ler.
    $conhecidas = mcp_perguntas_conhecidas();
    $lidas = [];
    foreach (is_array($b['ja_respondido'] ?? null) ? $b['ja_respondido'] : [] as $texto) {
        $texto = mcp_texto((string) $texto, 200);
        if ($texto !== '' && isset($conhecidas[$texto]) && !in_array($texto, $lidas, true)) {
            $lidas[] = $texto;
        }
        if (count($lidas) >= 8) {
            break;
        }
    }
    $origem = is_array($b['origem'] ?? null) ? $b['origem'] : [];
    $utm = static fn(string $k, int $limite = 160): ?string => mcp_texto($origem[$k] ?? '', $limite) ?: null;
    return [
        'nome' => $nome, 'email' => $email, 'telefone' => $telefone, 'assunto' => $assunto,
        'curso_slug' => $curso['slug'] ?? null, 'curso_nome' => $curso['nome'] ?? null,
        'mensagem' => $mensagem, 'pagina' => $pagina ?: null, 'ja_respondido' => $lidas,
        'utm_source' => $utm('utm_source', 120), 'utm_medium' => $utm('utm_medium', 120),
        'utm_campaign' => $utm('utm_campaign'), 'utm_content' => $utm('utm_content'), 'utm_term' => $utm('utm_term'),
        'fbclid' => $utm('fbclid', 255), 'gclid' => $utm('gclid', 255),
    ];
}

// ----------------------------------------------------------------------------- fluxo
$b = mcp_exigir_post_json();

// Campo armadilha: o chat sempre manda vazio; robô que preenche tudo cai aqui.
if (mcp_texto($b['site'] ?? '', 10) !== '') {
    mcp_registrar(null, 'armadilha', 'contato · ' . mcp_ip());
    mcp_falhar(422, 'Não foi possível enviar. Tente novamente.');
}

$contato = mcp_validar_contato($b);
unset($b);

foreach ([['ip', mcp_ip(), MCP_CONTATO_LIMITE_IP], ['email', $contato['email'], MCP_CONTATO_LIMITE_EMAIL]] as [$coluna, $valor, [$maximo, $janela]]) {
    if ($valor !== '' && mcp_contar_contatos_recentes($coluna, $valor, $janela) >= $maximo) {
        mcp_registrar(null, 'limite', "contato · $coluna · $maximo em {$janela}s");
        mcp_falhar(429, 'Você já enviou várias mensagens em pouco tempo. Aguarde um pouco ou escreva para ' . mcp_email_contato_endereco() . '.');
    }
}

$id = mcp_contato_gravar($contato);
$protocolo = mcp_contato_protocolo($id);
mcp_contato_atualizar($id, ['protocolo' => $protocolo]);
$registro = mcp_contato_por_id($id) ?? ($contato + ['id' => $id, 'protocolo' => $protocolo, 'criado_em' => mcp_agora()]);
// O que o chat já respondeu não vai para a tabela (é do atendimento, não do contato): reanexa
// aqui, depois da releitura, para entrar nos dois e-mails.
$registro['ja_respondido'] = $contato['ja_respondido'];
try {
    $registro['link_painel'] = mcp_painel_link_contato($id); // botão "Responder no painel" do aviso à equipe
} catch (Throwable $e) {
    error_log('[matricula] link do painel falhou: ' . $e->getMessage());
}

$envios = mcp_email_contato($registro);
mcp_contato_atualizar($id, ['email_equipe' => $envios['equipe'], 'email_confirmacao' => $envios['confirmacao']]);
mcp_registrar(null, 'contato', "#$id · {$contato['assunto']} · equipe {$envios['equipe']} · confirmação {$envios['confirmacao']}");

mcp_json([
    'ok' => true,
    'protocolo' => $protocolo,
    'nome' => mcp_primeiro_nome($contato['nome']),
    'email' => $contato['email'],
    'prazo' => MCP_EMAIL_PRAZO,
    'copia_enviada' => $envios['confirmacao'] !== 'falhou',
], 201);
