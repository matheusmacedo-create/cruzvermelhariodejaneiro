<?php
/**
 * Rotina da ligação com o Google Drive (lib/drive_espelho.php), a cada 15 minutos:
 *  - processa a fila mcp_drive_fila (fichas de voluntários, exportações, cópias do banco);
 *  - uma vez por dia (a primeira rodada depois da meia-noite de Brasília, registrada em mcp_chaves
 *    'drive_exportacao_em') enfileira a exportação em CSV do dia anterior e a cópia completa do banco, e já as executa;
 *  - espelha public_html/assets em Site/assets, até 40 arquivos ou 50 segundos por rodada.
 * Com o Drive desligado (sem GOOGLE_SA e GOOGLE_DRIVE_RAIZ_ID em config-google.php) sai em silêncio, código 0.
 *
 * Só pela linha de comando, rodado pelo cron da hospedagem:
 *   php /caminho/public_html/matricula-cursos-presenciais/api/drive.php
 *   php .../drive.php --testar   confere o token, lista a pasta raiz e grava Site/teste-ligacao.txt, passo a passo
 * Por HTTP responde 404 (e o .htaccess nega o arquivo). Uma trava no banco (GET_LOCK 'mcp_drive') impede duas
 * rodadas juntas.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/lib.php';
restore_exception_handler();
// lib.php carrega os dois módulos quando eles existem; se uma versão antiga do lib.php ainda não os conhece, carrega aqui.
if (!function_exists('mcp_google_token')) {
    require_once __DIR__ . '/lib/google_drive.php';
}
if (!function_exists('mcp_drive_ligado')) {
    require_once __DIR__ . '/lib/drive_espelho.php';
}

$testar = in_array('--testar', array_slice($argv ?? [], 1), true);

if (!mcp_drive_ligado()) {
    if ($testar) {
        echo "Drive desligado: faltam GOOGLE_SA e GOOGLE_DRIVE_RAIZ_ID em config-google.php (veja docs/google-drive.md).\n";
        exit(1);
    }
    exit(0);
}

$db = mcp_db();
if ((int) $db->query("SELECT GET_LOCK('mcp_drive', 0)")->fetchColumn() !== 1) {
    fwrite(STDERR, "Outra rodada do Drive ainda está em andamento.\n");
    exit(0);
}
// O código de saída sai depois do finally: exit() dentro do try não executaria a liberação da trava.
try {
    $codigo = $testar ? mcp_drive_rotina_testar() : mcp_drive_rotina();
} finally {
    $db->query("SELECT RELEASE_LOCK('mcp_drive')");
}
exit($codigo);

/** A rodada normal. Cada parte no seu try: uma falha não segura as outras. Devolve o código de saída. */
function mcp_drive_rotina(): int
{
    $partes = [];
    $falhas = 0;
    try {
        $r = mcp_drive_processar_fila(20);
        $falhas += $r['falhas'];
        $partes[] = sprintf('fila: %d feitos, %d falhas', $r['feitos'], $r['falhas']);
    } catch (Throwable $e) {
        error_log('[matricula] drive: fila: ' . get_class($e) . ': ' . $e->getMessage());
        $partes[] = 'fila: erro (' . get_class($e) . ')';
        $falhas++;
    }
    try {
        $hoje = mcp_drive_dia_brt(0);
        if (mcp_drive_chave_ler('drive_exportacao_em') !== $hoje) {
            // Ontem fechou: a exportação é do dia anterior inteiro; a cópia do banco é a de hoje. A chave vai antes de
            // processar, para a próxima rodada não enfileirar de novo se o processamento demorar ou falhar (a fila guarda).
            mcp_drive_chave_gravar('drive_exportacao_em', $hoje);
            mcp_drive_enfileirar('exportacao_diaria', null, ['dia' => mcp_drive_dia_brt(-1)]);
            mcp_drive_enfileirar('backup_banco', null, ['dia' => $hoje]);
            $r = mcp_drive_processar_fila(5);
            $falhas += $r['falhas'];
            $partes[] = sprintf('diário de %s: %d feitos, %d falhas', $hoje, $r['feitos'], $r['falhas']);
        }
    } catch (Throwable $e) {
        error_log('[matricula] drive: exportação diária: ' . get_class($e) . ': ' . $e->getMessage());
        $partes[] = 'diário: erro (' . get_class($e) . ')';
        $falhas++;
    }
    try {
        $a = mcp_drive_espelhar_assets(40, 50);
        $falhas += $a['falhas'];
        $partes[] = sprintf('assets: %d vistos, %d enviados, %d ignorados, %d falhas, %d restantes', $a['vistos'], $a['enviados'], $a['ignorados'], $a['falhas'], $a['restantes']);
    } catch (Throwable $e) {
        error_log('[matricula] drive: assets: ' . get_class($e) . ': ' . $e->getMessage());
        $partes[] = 'assets: erro (' . get_class($e) . ')';
        $falhas++;
    }
    printf("%s drive: %s\n", gmdate('Y-m-d H:i:s'), implode('; ', $partes));
    return $falhas > 0 ? 1 : 0;
}

/** --testar: confere a configuração passo a passo, sem tocar na fila. Nunca imprime o token nem a chave. */
function mcp_drive_rotina_testar(): int
{
    $passo = static function (string $texto): void {
        echo $texto . "\n";
    };
    try {
        $sa = mcp_google_sa();
        $passo('1. Configuração: conta de serviço ' . (string) $sa['client_email'] . ', pasta raiz ' . mcp_drive_raiz()
            . ((string) mcp_cfg('GOOGLE_SHEET_FICHAS_ID', '') !== '' ? ', planilha das fichas configurada' : ', SEM planilha das fichas (GOOGLE_SHEET_FICHAS_ID vazio)'));
        $token = mcp_google_token(true);
        $passo(sprintf('2. Token de acesso obtido (%d caracteres), guardado em %s.', strlen($token), basename(mcp_google_token_arquivo())));
        $raiz = mcp_drive_info(mcp_drive_raiz());
        $passo('3. Pasta raiz: "' . (string) ($raiz['name'] ?? '?') . '" (' . (string) ($raiz['mimeType'] ?? '?') . ')'
            . (!empty($raiz['driveId']) ? ', num Drive compartilhado' : ', no Meu Drive de alguém (prefira um Drive compartilhado)'));
        $itens = mcp_drive_listar(mcp_drive_raiz());
        $passo('4. Conteúdo da raiz: ' . (count($itens) === 0 ? 'vazia' : count($itens) . ' item(ns)'));
        foreach ($itens as $item) {
            $passo('   - ' . $item['name'] . ' (' . ($item['mimeType'] === MCP_DRIVE_MIME_PASTA ? 'pasta' : $item['mimeType']) . ')');
        }
        $texto = 'Ligação do site com o Drive testada em ' . mcp_drive_data_brt(mcp_agora(), 'd/m/Y \à\s H:i') . " (horário de Brasília).\n";
        $id = mcp_drive_com_pasta('Site', static fn(string $pastaId): string => mcp_drive_enviar_pelo_nome($pastaId, 'teste-ligacao.txt', $texto, 'text/plain'));
        $passo('5. Arquivo Site/teste-ligacao.txt gravado (id ' . $id . ').');
        $passo('Tudo certo.');
        return 0;
    } catch (Throwable $e) {
        $passo('FALHOU: ' . mcp_drive_resumir_erro($e));
        return 1;
    }
}
