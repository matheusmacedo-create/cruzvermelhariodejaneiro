<?php
/**
 * Comprovantes de comparecimento (lib/presenca.php): manda o e-mail com o PDF a cada aluno que
 * registrou presença numa aula que já terminou.
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
    printf("%s comparecimentos: %d enviados, %d falhas, %d presenças vistas\n", gmdate('Y-m-d H:i:s'), $r['enviados'], $r['falhas'], $r['vistos']);
    exit($r['falhas'] > 0 ? 1 : 0);
} finally {
    $db->query("SELECT RELEASE_LOCK('mcp_comparecimentos')");
}
