<?php
/**
 * Lembretes do questionário de dias e horários (lib/horarios.php): manda, a quem pagou e ainda não
 * respondeu, o 1º lembrete 24 h depois do pagamento e o 2º 72 h depois. Para assim que o aluno responde.
 * Na mesma rodada, o lembrete único do PIX em aberto (lib/recuperacao.php): a quem gerou o PIX há 2 a 20 h e
 * não pagou, depois de conferir o pagamento na Unicopag.
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
    exit($r['falhas'] + $p['falhas'] > 0 ? 1 : 0);
} finally {
    $db->query("SELECT RELEASE_LOCK('mcp_lembretes_horarios')");
}
