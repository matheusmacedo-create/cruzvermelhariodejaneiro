<?php
/**
 * Rotina do ponto da sede, a cada 15 minutos:
 *  - comprovantes de comparecimento (lib/presenca.php): manda o e-mail com o PDF a cada aluno que
 *    registrou presença numa aula que já terminou;
 *  - apaga a presença de empregados, terceirizados e outros vínculos registrada há mais de 90 dias
 *    (lib/ponto.php). As horas de voluntários e diretoria ficam;
 *  - avisos do ponto (lib/avisos.php): lembretes ligados no portal, comunicados agendados, envio da fila
 *    (só das 8h às 20h) e faxina do registro. Tudo começa desligado: sem nada ligado no portal, não sai nada;
 *  - pagar tudo (10/2026): a varredura do pós-pagamento interrompido (E17: escola, e-mails e cartões pendentes) e a
 *    rotina da venda sem turma (lib/espera.php, spec 10.8), cada uma no seu try, antes dos comprovantes; e a faxina
 *    do histórico das parcelas mostradas (7 dias).
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
    // As faxinas com prazo prometido na política (IPs dos freios em 2 dias, sinais do navegador em 8) vêm
    // primeiro e no próprio try: nem os comprovantes nem um índice que não sai (lock, permissão) as seguram.
    try {
        mcp_eventos_apagar_freios();
        mcp_meta_faxina();
    } catch (Throwable $e) {
        error_log('[matricula] faxina da rotina: ' . get_class($e) . ': ' . $e->getMessage());
    }
    // Pagar tudo: cada parte no seu try, antes dos comprovantes (que ficam fora de try): uma exceção numa não derruba a
    // outra nem a rodada (T14). Com as chaves desligadas e ninguém na fila, nada é chamado fora do banco.
    $pagarTudo = [];
    try {
        mcp_parcelas_faxina();
    } catch (Throwable $e) {
        error_log('[matricula] faxina das parcelas: ' . get_class($e) . ': ' . $e->getMessage());
    }
    try {
        $v = mcp_pos_pagamento_varrer();
        $pagarTudo[] = sprintf('pós-pagamento: %d escola, %d e-mails, %d avisos, %d cartões', $v['escola'], $v['emails'], $v['secretaria'], $v['cartoes']);
    } catch (Throwable $e) {
        error_log('[matricula] varredura do pós-pagamento: ' . get_class($e) . ': ' . $e->getMessage());
        $pagarTudo[] = 'pós-pagamento: erro (' . get_class($e) . ')';
    }
    try {
        $w = mcp_espera_varrer();
        $pagarTudo[] = !empty($w['ocupada']) ? 'espera: outra rodada em andamento'
            : sprintf('espera: %d chamadas, %d matriculados, %d reconsultas, %d a devolver, %d lembretes%s', $w['chamadas'], $w['matriculados'], $w['reconsultas'], $w['devolver'], $w['lembretes'], $w['falha_rede'] ? ', escola sem resposta' : '');
    } catch (Throwable $e) {
        error_log('[matricula] rotina da espera: ' . get_class($e) . ': ' . $e->getMessage());
        $pagarTudo[] = 'espera: erro (' . get_class($e) . ')';
    }
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
    // Manutenção: o batimento da rotina (o portal avisa quando ela para de rodar) e os índices das tabelas antigas.
    // O batimento vem antes e à parte: um índice que não se cria não pode fazer o portal dizer que a rotina parou.
    try {
        mcp_ajuste_gravar('rotina_em', gmdate('Y-m-d H:i:s'), 'rotina');
    } catch (Throwable $e) {
        error_log('[matricula] batimento da rotina: ' . get_class($e) . ': ' . $e->getMessage());
    }
    try {
        mcp_garantir_indices($db);
    } catch (Throwable $e) {
        error_log('[matricula] índices da rotina: ' . get_class($e) . ': ' . $e->getMessage());
    }
    // O resumo do pagar tudo vem antes do dos avisos: a linha continua terminando em "avisos: … vencidos", como antes.
    printf("%s comparecimentos: %d enviados, %d falhas, %d presenças vistas; ponto: %d presenças antigas apagadas; %s; %s\n",
        gmdate('Y-m-d H:i:s'), $r['enviados'], $r['falhas'], $r['vistos'], $apagadas, implode('; ', $pagarTudo), $avisos);
    exit($r['falhas'] > 0 || $a['falhas'] > 0 ? 1 : 0);
} finally {
    $db->query("SELECT RELEASE_LOCK('mcp_comparecimentos')");
}
