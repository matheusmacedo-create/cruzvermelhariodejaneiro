<?php
/**
 * Lembretes do questionário de dias e horários (lib/horarios.php): manda, a quem pagou e ainda não
 * respondeu, o 1º lembrete 24 h depois do pagamento e o 2º 72 h depois. Para assim que o aluno responde.
 * Na mesma rodada, o lembrete único do PIX em aberto (lib/recuperacao.php): a quem gerou o PIX há 2 a 20 h e
 * não pagou, depois de conferir o pagamento na Unicopag. E a recuperação por WhatsApp (lib/recuperacao_whatsapp.php,
 * 30/10/2026): as três mensagens automáticas a quem se cadastrou no site ou na escola e não pagou; e, logo depois, os
 * três e-mails da mesma recuperação a esse mesmo público (lib/recuperacao_email.php).
 *
 * Só pela linha de comando, rodado pelo cron da hospedagem a cada hora:
 *   php /caminho/public_html/matricula-cursos-presenciais/api/lembretes.php
 * Por HTTP responde 404 (e o .htaccess nega o arquivo). Uma trava no banco impede duas rodadas juntas.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/lib.php';
restore_exception_handler();

$db = mcp_db();
if ((int) $db->query("SELECT GET_LOCK('mcp_lembretes_horarios', 0)")->fetchColumn() !== 1) {
    fwrite(STDERR, "Outra rodada ainda está em andamento.\n");
    exit(0);
}
try {
    $r = mcp_horarios_enviar_lembretes();
    printf("%s lembretes: %d enviados, %d falhas, %d inscrições vistas\n", gmdate('Y-m-d H:i:s'), $r['enviados'], $r['falhas'], $r['vistos']);
    // Um erro no lembrete do PIX não pode derrubar o de horários (que já rodou) nem passar em silêncio.
    try {
        $p = mcp_pix_lembrete_rodar();
        printf("%s lembrete do PIX: %d enviados, %d falhas, %d pulados (pagos, vencidos ou sem resposta da Unicopag), %d vistos\n",
            gmdate('Y-m-d H:i:s'), $p['enviados'], $p['falhas'], $p['pulados'], $p['vistos']);
    } catch (Throwable $e) {
        error_log('[matricula] lembrete do PIX: ' . get_class($e) . ': ' . $e->getMessage());
        $p = ['falhas' => 1];
    }
    // Recuperação por WhatsApp: módulo opcional (publicado à parte, o resto roda sem ele). Uma falha aqui também não
    // derruba o que já rodou; sem as chaves da Kapso, não manda nada e só diz o motivo.
    $w = ['falhas' => 0];
    if (is_file(__DIR__ . '/lib/recuperacao_whatsapp.php')) {
        require_once __DIR__ . '/lib/recuperacao_whatsapp.php';
        try {
            $w = mcp_rw_rodar();
            printf("%s recuperação por WhatsApp: %d enviadas, %d falhas, %d puladas, %d pessoas no fluxo, escola %s%s\n",
                gmdate('Y-m-d H:i:s'), $w['enviados'], $w['falhas'], $w['pulados'], $w['vistos'], $w['escola'], $w['motivo'] !== null ? ' (' . $w['motivo'] . ')' : '');
        } catch (Throwable $e) {
            error_log('[matricula] recuperação por WhatsApp: ' . get_class($e) . ': ' . $e->getMessage());
            $w = ['falhas' => 1];
        }
        // Recuperação por e-mail (lib/recuperacao_email.php): o mesmo público, logo depois e independente do WhatsApp
        // (aproveita o público que ele já leu nesta hora). Uma falha aqui não desfaz nem segura o que já rodou; sem a
        // RESEND_API_KEY, não manda nada e só diz o motivo.
        try {
            $e = mcp_rw_email_rodar(null, $w['carga'] ?? null);
            printf("%s recuperação por e-mail: %d enviados, %d falhas, %d pulados, %d etapas 1 cumpridas pelo lembrete do PIX, %d esperando o lembrete do PIX, %d pessoas no fluxo, escola %s%s\n",
                gmdate('Y-m-d H:i:s'), $e['enviados'], $e['falhas'], $e['pulados'], $e['pulados_pix'], $e['esperando_pix'], $e['vistos'], $e['escola'],
                $e['motivo'] !== null ? ' (' . $e['motivo'] . ')' : '');
            $w['falhas'] += $e['falhas'];
        } catch (Throwable $erro) {
            error_log('[matricula] recuperação por e-mail: ' . get_class($erro) . ': ' . $erro->getMessage());
            $w['falhas'] += 1;
        }
    }
    exit($r['falhas'] + $p['falhas'] + $w['falhas'] > 0 ? 1 : 0);
} finally {
    $db->query("SELECT RELEASE_LOCK('mcp_lembretes_horarios')");
}
