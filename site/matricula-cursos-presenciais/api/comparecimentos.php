<?php
/**
 * Rotina do ponto da sede, a cada 15 minutos:
 *  - comprovantes de comparecimento (lib/presenca.php): manda o e-mail com o PDF a cada aluno que
 *    registrou presença numa aula que já terminou;
 *  - apaga a presença de empregados, terceirizados e outros vínculos registrada há mais de 90 dias
 *    (lib/ponto.php). As horas de voluntários e diretoria ficam;
 *  - avisos do ponto (lib/avisos.php): lembretes ligados no portal, comunicados agendados, envio da fila
 *    (só das 8h às 20h) e faxina do registro. Tudo começa desligado: sem nada ligado no portal, não sai nada.
 *
 * Só pela linha de comando, rodado pelo cron da hospedagem a cada 15 minutos:
 *   php /caminho/public_html/matricula-cursos-presenciais/api/comparecimentos.php
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
if ((int) $db->query("SELECT GET_LOCK('mcp_comparecimentos', 0)")->fetchColumn() !== 1) {
    fwrite(STDERR, "Outra rodada ainda está em andamento.\n");
    exit(0);
}
try {
    $r = mcp_presencas_enviar_pendentes();
    $apagadas = mcp_ponto_presencas_apagar_antigas();
    // Os avisos não podem derrubar os comprovantes: erro aqui vai para o log e a rodada termina normalmente.
    try {
        $a = mcp_avisos_rodar();
        $avisos = sprintf('avisos: %d preparados, %d enviados, %d falhas, %d vencidos', $a['preparados'], $a['enviados'], $a['falhas'], $a['expirados']);
    } catch (Throwable $e) {
        error_log('[matricula] rotina de avisos: ' . get_class($e) . ': ' . $e->getMessage());
        $a = ['falhas' => 1];
        $avisos = 'avisos: erro (' . get_class($e) . ')';
    }
    printf("%s comparecimentos: %d enviados, %d falhas, %d presenças vistas; ponto: %d presenças antigas apagadas; %s\n",
        gmdate('Y-m-d H:i:s'), $r['enviados'], $r['falhas'], $r['vistos'], $apagadas, $avisos);
    exit($r['falhas'] > 0 || $a['falhas'] > 0 ? 1 : 0);
} finally {
    $db->query("SELECT RELEASE_LOCK('mcp_comparecimentos')");
}
