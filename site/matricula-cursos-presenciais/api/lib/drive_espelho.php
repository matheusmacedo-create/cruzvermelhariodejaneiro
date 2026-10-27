<?php
/**
 * Espelho do site no Google Drive da filial (10/2026): o que o banco e o servidor guardam ganha uma cópia que a
 * secretaria abre sem senha de servidor. Nada aqui é fonte da verdade: o banco continua sendo; o Drive é cópia.
 *
 *  - fila (mcp_drive_fila): trabalhos pequenos que a rotina da linha de comando (api/drive.php) executa, com até
 *    5 tentativas e espera crescente entre elas (5, 15, 60, 240 e 960 minutos). Tipos:
 *      ficha_voluntario  PDF da ficha em Voluntariado/Fichas e uma linha na planilha "Fichas de voluntários";
 *      apagar_ficha_drive expurgo (12 meses, Política de Privacidade): apaga o PDF e tira a linha da planilha;
 *      exportacao_diaria CSVs do dia (sem CPF) em Secretaria/Exportações/AAAA/;
 *      backup_banco      cópia completa de cada tabela mcp_* em JSON comprimido, em Backups/banco/AAAA-MM-DD/;
 *  - espelho dos assets (public_html/assets → Site/assets), guiado pelo manifesto mcp_drive_arquivos (tamanho e data
 *    de modificação dizem o que precisa subir de novo);
 *  - pastas por caminho ("Voluntariado/Fichas"), criadas sob GOOGLE_DRIVE_RAIZ_ID e lembradas em mcp_chaves.
 *
 * Tudo começa desligado: sem GOOGLE_SA e GOOGLE_DRIVE_RAIZ_ID em config-google.php, mcp_drive_ligado() é false, a
 * fila não recebe nada e a rotina sai em silêncio. As chamadas HTTP ficam em lib/google_drive.php.
 */
declare(strict_types=1);

const MCP_DRIVE_TENTATIVAS_MAX = 5;
/** Minutos de espera depois da 1ª, 2ª, 3ª… falha. Depois da 5ª o trabalho fica como 'falhou'. */
const MCP_DRIVE_ESPERAS_MIN = [5, 15, 60, 240, 960];
const MCP_DRIVE_ASSET_MAX = 50 * 1024 * 1024;
const MCP_DRIVE_BACKUP_LOTE = 500;
const MCP_DRIVE_EXPORTACAO_TABELAS = ['mcp_inscricoes', 'mcp_contatos', 'mcp_voluntarios', 'mcp_colaboradores', 'mcp_ponto'];
const MCP_DRIVE_FUSO = 'America/Sao_Paulo';
const MCP_DRIVE_PASTA_FICHAS = 'Voluntariado/Fichas';
const MCP_DRIVE_PASTA_EXPORTACOES = 'Secretaria/Exportações';
const MCP_DRIVE_PASTA_BACKUPS = 'Backups/banco';
const MCP_DRIVE_PASTA_ASSETS = 'Site/assets';
const MCP_DRIVE_ABA_FICHAS = 'Fichas';

/** Trabalho da fila que não tem mais o que fazer (ficha apagada, tipo desconhecido): vira 'feito' com a observação. */
final class McpDriveDescartar extends RuntimeException
{
}

// ----------------------------------------------------------------------------- liga e desliga
/** Há conta de serviço e pasta raiz? Sem as duas, nada deste módulo sai do lugar. */
function mcp_drive_ligado(): bool
{
    $sa = mcp_cfg('GOOGLE_SA');
    if (is_string($sa)) {
        $sa = json_decode($sa, true);
    }
    return is_array($sa) && (string) ($sa['client_email'] ?? '') !== '' && (string) ($sa['private_key'] ?? '') !== ''
        && (string) mcp_cfg('GOOGLE_DRIVE_RAIZ_ID', '') !== '';
}

function mcp_drive_raiz(): string
{
    $raiz = (string) mcp_cfg('GOOGLE_DRIVE_RAIZ_ID', '');
    if ($raiz === '') {
        throw new McpDriveErro('GOOGLE_DRIVE_RAIZ_ID vazio (config-google.php).');
    }
    return $raiz;
}

// ----------------------------------------------------------------------------- mcp_chaves como memória pequena
function mcp_drive_chave_ler(string $nome): ?string
{
    $stmt = mcp_db()->prepare('SELECT valor FROM mcp_chaves WHERE nome = ?');
    $stmt->execute([$nome]);
    $valor = $stmt->fetchColumn();
    return $valor === false ? null : (string) $valor;
}

function mcp_drive_chave_gravar(string $nome, string $valor): void
{
    mcp_db()->prepare('INSERT INTO mcp_chaves (nome, valor, criado_em) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor), criado_em = VALUES(criado_em)')
        ->execute([$nome, mb_substr($valor, 0, 128), mcp_agora()]);
}

function mcp_drive_chave_apagar(string $nome): void
{
    mcp_db()->prepare('DELETE FROM mcp_chaves WHERE nome = ?')->execute([$nome]);
}

// ----------------------------------------------------------------------------- pastas
/**
 * Chave do cache de uma pasta em mcp_chaves. O nome da chave cabe em 40 caracteres (o tamanho da coluna), por isso
 * vai 'drive_pasta:' com os 28 primeiros dígitos do md5 do caminho, e não o md5 inteiro.
 */
function mcp_drive_pasta_chave(string $caminho): string
{
    return 'drive_pasta:' . substr(md5($caminho), 0, 28);
}

/** "Secretaria//Exportações/" → ['Secretaria', 'Exportações']. */
function mcp_drive_pasta_niveis(string $caminho): array
{
    $niveis = [];
    foreach (explode('/', str_replace('\\', '/', $caminho)) as $nivel) {
        $nivel = trim($nivel);
        if ($nivel !== '' && $nivel !== '.' && $nivel !== '..') {
            $niveis[] = $nivel;
        }
    }
    return $niveis;
}

/** Cache em memória dos ids das pastas desta execução (a rotina pede a mesma pasta dezenas de vezes). */
function &mcp_drive_pasta_memoria(): array
{
    static $memoria = [];
    return $memoria;
}

/**
 * Id da pasta deste caminho, criando cada nível que falta sob a raiz. Cada nível é lembrado em memória e em mcp_chaves;
 * assim a segunda chamada (e a rodada seguinte) não bate na API. Caminho vazio = a própria raiz.
 */
function mcp_drive_pasta_garantir(string $caminho): string
{
    $memoria = &mcp_drive_pasta_memoria();
    $paiId = mcp_drive_raiz();
    $acumulado = '';
    foreach (mcp_drive_pasta_niveis($caminho) as $nome) {
        $acumulado = $acumulado === '' ? $nome : $acumulado . '/' . $nome;
        if (isset($memoria[$acumulado])) {
            $paiId = $memoria[$acumulado];
            continue;
        }
        $id = mcp_drive_chave_ler(mcp_drive_pasta_chave($acumulado));
        if ($id === null || $id === '') {
            $id = mcp_drive_pasta_buscar($nome, $paiId) ?? mcp_drive_pasta_criar($nome, $paiId);
            mcp_drive_chave_gravar(mcp_drive_pasta_chave($acumulado), $id);
        }
        $memoria[$acumulado] = $id;
        $paiId = $id;
    }
    return $paiId;
}

/** Esquece o cache deste caminho e dos caminhos acima dele (alguém apagou ou moveu a pasta no Drive). */
function mcp_drive_pasta_esquecer(string $caminho): void
{
    $memoria = &mcp_drive_pasta_memoria();
    $acumulado = '';
    foreach (mcp_drive_pasta_niveis($caminho) as $nome) {
        $acumulado = $acumulado === '' ? $nome : $acumulado . '/' . $nome;
        unset($memoria[$acumulado]);
        mcp_drive_chave_apagar(mcp_drive_pasta_chave($acumulado));
    }
}

/**
 * Roda $acao(idDaPasta). Se o Drive responder 404 (a pasta lembrada não existe mais), esquece o cache e tenta uma
 * vez mais com a pasta recriada.
 */
function mcp_drive_com_pasta(string $caminho, callable $acao): mixed
{
    try {
        return $acao(mcp_drive_pasta_garantir($caminho));
    } catch (McpDriveErro $e) {
        if ($e->status !== 404) {
            throw $e;
        }
        mcp_drive_pasta_esquecer($caminho);
        return $acao(mcp_drive_pasta_garantir($caminho));
    }
}

// ----------------------------------------------------------------------------- arquivos e planilha
/**
 * Envia um arquivo à pasta (ou troca o conteúdo de $substituirId). Se o arquivo a substituir já não existe (404), sobe
 * como novo em vez de falhar. Devolve o id.
 */
function mcp_drive_enviar_arquivo(string $pastaId, string $nome, string $conteudo, string $mime, ?string $substituirId = null): string
{
    try {
        return mcp_drive_upload($pastaId, $nome, $conteudo, $mime, $substituirId);
    } catch (McpDriveErro $e) {
        if ($substituirId === null || $substituirId === '' || $e->status !== 404) {
            throw $e;
        }
        return mcp_drive_upload($pastaId, $nome, $conteudo, $mime, null);
    }
}

/** Envia pelo nome: se já existe um arquivo com este nome na pasta, troca o conteúdo dele; senão cria. */
function mcp_drive_enviar_pelo_nome(string $pastaId, string $nome, string $conteudo, string $mime): string
{
    $existente = mcp_drive_buscar($nome, $pastaId);
    $id = $existente !== null && $existente['mimeType'] !== MCP_DRIVE_MIME_PASTA ? $existente['id'] : null;
    return mcp_drive_enviar_arquivo($pastaId, $nome, $conteudo, $mime, $id);
}

function mcp_drive_planilha_acrescentar(string $planilhaId, string $aba, array $linha): void
{
    mcp_sheets_acrescentar($planilhaId, $aba, $linha);
}

// ----------------------------------------------------------------------------- fila
/**
 * Põe um trabalho na fila. Com o Drive desligado não insere nada; falha do banco vai para o log e não derruba quem
 * chamou (a ficha do voluntário já foi gravada; o espelho é secundário). Um trabalho pendente igual (tipo e
 * referência) não é repetido.
 */
function mcp_drive_enfileirar(string $tipo, ?int $referencia, array $dados = []): void
{
    if (!mcp_drive_ligado()) {
        return;
    }
    try {
        $pdo = mcp_db();
        if ($referencia !== null) {
            $stmt = $pdo->prepare("SELECT id FROM mcp_drive_fila WHERE tipo = ? AND referencia = ? AND estado = 'pendente' LIMIT 1");
            $stmt->execute([$tipo, $referencia]);
            if ($stmt->fetchColumn() !== false) {
                return;
            }
        }
        $agora = mcp_agora();
        $pdo->prepare('INSERT INTO mcp_drive_fila (tipo, referencia, dados, estado, tentativas, criado_em, atualizado_em) VALUES (?, ?, ?, ?, 0, ?, ?)')
            ->execute([mb_substr($tipo, 0, 30), $referencia, $dados ? json_encode($dados, JSON_UNESCAPED_UNICODE) : null, 'pendente', $agora, $agora]);
    } catch (Throwable $e) {
        error_log('[matricula] drive: a fila não recebeu ' . $tipo . ': ' . get_class($e) . ': ' . $e->getMessage());
    }
}

/**
 * Executa os trabalhos pendentes cuja hora chegou, em ordem de chegada. Sucesso = 'feito'; erro = conta a tentativa e
 * marca a próxima hora (5/15/60/240/960 min); na 5ª falha vira 'falhou' com o erro resumido. Cada trabalho no seu try.
 * @return array{feitos: int, falhas: int}
 */
function mcp_drive_processar_fila(int $limite = 20): array
{
    $pdo = mcp_db();
    $stmt = $pdo->prepare("SELECT * FROM mcp_drive_fila WHERE estado = 'pendente' AND (proxima_em IS NULL OR proxima_em <= ?) ORDER BY id LIMIT " . max(1, min(500, $limite)));
    $stmt->execute([mcp_agora()]);
    $feitos = 0;
    $falhas = 0;
    foreach ($stmt->fetchAll() as $trabalho) {
        $id = (int) $trabalho['id'];
        $tentativas = (int) $trabalho['tentativas'] + 1;
        $dados = json_decode((string) ($trabalho['dados'] ?? ''), true);
        try {
            $observacao = mcp_drive_tarefa((string) $trabalho['tipo'], $trabalho['referencia'] !== null ? (int) $trabalho['referencia'] : null, is_array($dados) ? $dados : []);
            mcp_drive_fila_marcar($id, 'feito', $tentativas, $observacao !== '' ? $observacao : null, null);
            $feitos++;
        } catch (McpDriveDescartar $e) {
            mcp_drive_fila_marcar($id, 'feito', $tentativas, mb_substr('descartado: ' . $e->getMessage(), 0, 255), null);
            $feitos++;
        } catch (Throwable $e) {
            $erro = mcp_drive_resumir_erro($e);
            $definitiva = $tentativas >= MCP_DRIVE_TENTATIVAS_MAX;
            $espera = MCP_DRIVE_ESPERAS_MIN[min($tentativas, count(MCP_DRIVE_ESPERAS_MIN)) - 1];
            mcp_drive_fila_marcar($id, $definitiva ? 'falhou' : 'pendente', $tentativas, $erro,
                $definitiva ? null : gmdate('Y-m-d H:i:s', time() + $espera * 60));
            error_log(sprintf('[matricula] drive: trabalho %d (%s) falhou na tentativa %d%s: %s', $id, $trabalho['tipo'], $tentativas,
                $definitiva ? ', desistiu' : ', tenta de novo em ' . $espera . ' min', $erro));
            $falhas++;
        }
    }
    return ['feitos' => $feitos, 'falhas' => $falhas];
}

function mcp_drive_fila_marcar(int $id, string $estado, int $tentativas, ?string $erro, ?string $proximaEm): void
{
    mcp_db()->prepare('UPDATE mcp_drive_fila SET estado = ?, tentativas = ?, erro = ?, proxima_em = ?, atualizado_em = ? WHERE id = ?')
        ->execute([$estado, min(255, $tentativas), $erro, $proximaEm, mcp_agora(), $id]);
}

/** Classe e mensagem numa linha de até 255 caracteres (as mensagens do cliente nunca trazem token nem chave). */
function mcp_drive_resumir_erro(Throwable $e): string
{
    $classe = (new ReflectionClass($e))->getShortName();
    $texto = trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? $e->getMessage());
    return mb_substr($classe . ($e instanceof McpDriveErro && $e->status > 0 ? ' ' . $e->status : '') . ': ' . $texto, 0, 255);
}

/** Despacha um trabalho pelo tipo. Devolve uma observação curta (ou '') que fica na coluna erro do trabalho feito. */
function mcp_drive_tarefa(string $tipo, ?int $referencia, array $dados): string
{
    return match ($tipo) {
        'ficha_voluntario' => mcp_drive_tarefa_ficha($referencia, $dados),
        'apagar_ficha_drive' => mcp_drive_tarefa_apagar_ficha($dados),
        'exportacao_diaria' => mcp_drive_tarefa_exportacao($dados),
        'backup_banco' => mcp_drive_tarefa_backup($dados),
        default => throw new McpDriveDescartar('tipo desconhecido: ' . $tipo),
    };
}

/**
 * Expurgo da ficha (Política de Privacidade: ficha, PDF e linha da planilha somem depois de 12 meses). Dados do
 * trabalho: {protocolo, drive_pdf_id}. Apaga o PDF no Drive (um 404 conta como apagado) e remove da aba "Fichas" toda
 * linha cuja coluna A seja o protocolo. Quem enfileira é mcp_voluntarios_expurgar, em lib/voluntarios.php.
 */
function mcp_drive_tarefa_apagar_ficha(array $dados): string
{
    $protocolo = trim((string) ($dados['protocolo'] ?? ''));
    $pdfId = trim((string) ($dados['drive_pdf_id'] ?? ''));
    if ($protocolo === '' && $pdfId === '') {
        throw new McpDriveDescartar('expurgo sem protocolo nem drive_pdf_id');
    }
    $partes = [];
    if ($pdfId !== '') {
        mcp_drive_apagar_arquivo($pdfId);
        $partes[] = 'PDF apagado';
    }
    $planilha = (string) mcp_cfg('GOOGLE_SHEET_FICHAS_ID', '');
    if ($protocolo !== '' && $planilha !== '') {
        $removidas = 0;
        // Mais de uma linha com o mesmo protocolo (reprocessamento antigo) sai também; o limite evita um laço sem fim.
        while ($removidas < 5 && mcp_drive_planilha_remover_linha($planilha, MCP_DRIVE_ABA_FICHAS, $protocolo)) {
            $removidas++;
        }
        $partes[] = $removidas > 0 ? sprintf('%d linha(s) da planilha removida(s)', $removidas) : 'protocolo não estava na planilha';
    } elseif ($protocolo !== '') {
        $partes[] = 'sem GOOGLE_SHEET_FICHAS_ID: planilha não conferida';
    }
    return $protocolo . ': ' . implode(', ', $partes);
}

// ----------------------------------------------------------------------------- ficha de voluntário
/**
 * PDF da ficha em Voluntariado/Fichas ("VOL-aammdd-0001-Nome_da_Pessoa.pdf") e uma linha na planilha das fichas. Os
 * ids ficam na própria ficha (drive_pdf_id, drive_planilha_em): numa nova tentativa o PDF é trocado no mesmo arquivo e a
 * linha da planilha não se repete. Usa lib/voluntarios.php quando ele existe; sem ele, lê e grava direto na tabela.
 */
function mcp_drive_tarefa_ficha(?int $id, array $dados): string
{
    if ($id === null || $id <= 0) {
        throw new McpDriveDescartar('ficha sem id');
    }
    $ficha = function_exists('mcp_voluntario_por_id') ? mcp_voluntario_por_id($id) : mcp_drive_ficha_ler($id);
    if ($ficha === null) {
        throw new McpDriveDescartar("a ficha $id não existe mais");
    }
    $protocolo = mcp_drive_ficha_protocolo($ficha);
    $pdf = function_exists('mcp_voluntario_pdf') ? mcp_voluntario_pdf($ficha) : mcp_drive_ficha_pdf_simples($ficha, $protocolo);
    $nome = $protocolo . '-' . mcp_drive_nome_ascii((string) $ficha['nome']) . '.pdf';
    $existente = (string) ($ficha['drive_pdf_id'] ?? '');
    $pdfId = (string) mcp_drive_com_pasta(MCP_DRIVE_PASTA_FICHAS,
        static fn(string $pastaId): string => mcp_drive_enviar_arquivo($pastaId, $nome, $pdf, 'application/pdf', $existente !== '' ? $existente : null));
    if ($pdfId !== $existente) {
        mcp_drive_ficha_atualizar($id, ['drive_pdf_id' => $pdfId]);
    }
    $planilha = (string) mcp_cfg('GOOGLE_SHEET_FICHAS_ID', '');
    if ($planilha === '') {
        return 'PDF enviado; sem GOOGLE_SHEET_FICHAS_ID, a linha da planilha não foi acrescentada';
    }
    if ((string) ($ficha['drive_planilha_em'] ?? '') === '') {
        mcp_drive_planilha_acrescentar($planilha, MCP_DRIVE_ABA_FICHAS, mcp_drive_ficha_linha($ficha, $protocolo));
        mcp_drive_ficha_atualizar($id, ['drive_planilha_em' => mcp_agora()]);
    }
    return '';
}

function mcp_drive_ficha_ler(int $id): ?array
{
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_voluntarios WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $linha = $stmt->fetch();
    return $linha ?: null;
}

function mcp_drive_ficha_atualizar(int $id, array $campos): void
{
    if (function_exists('mcp_voluntario_atualizar')) {
        mcp_voluntario_atualizar($id, $campos);
        return;
    }
    $sets = [];
    $valores = [];
    foreach ($campos as $coluna => $valor) {
        if (!preg_match('/^[a-z_]+$/', (string) $coluna)) {
            throw new InvalidArgumentException('coluna inválida: ' . $coluna);
        }
        $sets[] = "`$coluna` = ?";
        $valores[] = $valor;
    }
    $sets[] = 'atualizado_em = ?';
    $valores[] = mcp_agora();
    $valores[] = $id;
    mcp_db()->prepare('UPDATE mcp_voluntarios SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($valores);
}

/** "VOL-aammdd-0001" (data de Brasília da ficha), como mcp_voluntario_protocolo / mcp_contato_protocolo. */
function mcp_drive_ficha_protocolo(array $ficha): string
{
    if ((string) ($ficha['protocolo'] ?? '') !== '') {
        return (string) $ficha['protocolo'];
    }
    if (function_exists('mcp_voluntario_protocolo')) {
        return mcp_voluntario_protocolo((int) $ficha['id'], (string) ($ficha['criado_em'] ?? '') ?: null);
    }
    $data = (new DateTimeImmutable((string) ($ficha['criado_em'] ?? '') ?: mcp_agora(), new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(MCP_DRIVE_FUSO));
    return sprintf('VOL-%s-%04d', $data->format('ymd'), (int) $ficha['id']);
}

/** Nome de pessoa em ASCII para o nome do arquivo: acentos retirados, o resto vira sublinhado. */
function mcp_drive_nome_ascii(string $nome): string
{
    $texto = trim(preg_replace('/\s+/u', ' ', $nome) ?? $nome);
    if (class_exists('Normalizer')) {
        $texto = preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($texto, Normalizer::FORM_D)) ?? $texto;
    } else {
        $texto = (string) (@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) ?: $texto);
    }
    $texto = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $texto) ?? '', '_');
    return substr($texto !== '' ? $texto : 'Voluntario', 0, 60);
}

/** Rótulos em português para os códigos da ficha, do lib/voluntarios.php quando ele existe. */
function mcp_drive_rotulos(string $funcao, array $padrao): array
{
    if (function_exists($funcao)) {
        $lista = $funcao();
        if (is_array($lista) && $lista) {
            return $lista;
        }
    }
    return $padrao;
}

function mcp_drive_rotular(string $codigos, array $rotulos): string
{
    $partes = [];
    foreach (explode(',', $codigos) as $codigo) {
        $codigo = trim($codigo);
        if ($codigo !== '') {
            $partes[] = (string) ($rotulos[$codigo] ?? $codigo);
        }
    }
    return implode(', ', $partes);
}

/**
 * A linha da planilha "Fichas": protocolo, data, nome, idade, e-mail, telefone, cidade/bairro, escolaridade, profissão,
 * saúde, frentes, dias, períodos, motivação, como conheceu, status. Sem CPF: a planilha é vista por mais gente.
 */
function mcp_drive_ficha_linha(array $ficha, string $protocolo): array
{
    $frentes = mcp_drive_rotulos('mcp_voluntarios_frentes', [
        'formacao_primeiros_socorros' => 'Formação em primeiros socorros', 'campanhas_acoes' => 'Campanhas e ações', 'juventude' => 'Juventude',
        'comunicacao' => 'Comunicação', 'gestao_riscos' => 'Gestão de riscos', 'psicologia_servico_social' => 'Psicologia e serviço social',
        'esportes' => 'Esportes', 'administrativo' => 'Administrativo', 'outra' => 'Outra',
    ]);
    $escolaridades = mcp_drive_rotulos('mcp_voluntarios_escolaridades', [
        'fundamental' => 'Ensino fundamental', 'medio' => 'Ensino médio', 'tecnico' => 'Técnico', 'superior_incompleto' => 'Superior incompleto',
        'superior' => 'Superior', 'pos' => 'Pós-graduação',
    ]);
    $comoConheceu = mcp_drive_rotulos('mcp_voluntarios_como_conheceu', [
        'instagram' => 'Instagram', 'site' => 'Site', 'amigos' => 'Amigos', 'escola' => 'Escola', 'campanha' => 'Campanha', 'imprensa' => 'Imprensa', 'outro' => 'Outro',
    ]);
    $status = mcp_drive_rotulos('mcp_voluntarios_status', [
        'nova' => 'Nova', 'em_analise' => 'Em análise', 'entrevista' => 'Entrevista', 'aceita' => 'Aceita', 'recusada' => 'Recusada', 'arquivada' => 'Arquivada',
    ]);
    $dias = ['seg' => 'Seg', 'ter' => 'Ter', 'qua' => 'Qua', 'qui' => 'Qui', 'sex' => 'Sex', 'sab' => 'Sáb', 'dom' => 'Dom'];
    $periodos = ['manha' => 'Manhã', 'tarde' => 'Tarde', 'noite' => 'Noite'];
    $cidade = trim((string) ($ficha['cidade'] ?? '')) . ((string) ($ficha['bairro'] ?? '') !== '' ? ' / ' . $ficha['bairro'] : '') . ((string) ($ficha['estado'] ?? '') !== '' ? ' (' . $ficha['estado'] . ')' : '');
    $saude = (int) ($ficha['saude'] ?? 0) === 1 ? 'Sim' . ((string) ($ficha['saude_qual'] ?? '') !== '' ? ': ' . $ficha['saude_qual'] : '') : 'Não';
    return [
        $protocolo,
        mcp_drive_data_brt((string) ($ficha['criado_em'] ?? '')),
        (string) ($ficha['nome'] ?? ''),
        mcp_drive_idade((string) ($ficha['data_nascimento'] ?? ''), (string) ($ficha['criado_em'] ?? '')),
        (string) ($ficha['email'] ?? ''),
        function_exists('mcp_telefone_bonito') ? mcp_telefone_bonito((string) ($ficha['telefone'] ?? '')) : (string) ($ficha['telefone'] ?? ''),
        $cidade,
        (string) ($escolaridades[(string) ($ficha['escolaridade'] ?? '')] ?? $ficha['escolaridade'] ?? ''),
        (string) ($ficha['profissao'] ?? ''),
        $saude,
        mcp_drive_rotular((string) ($ficha['frentes'] ?? ''), $frentes),
        mcp_drive_rotular((string) ($ficha['dias'] ?? ''), $dias),
        mcp_drive_rotular((string) ($ficha['periodos'] ?? ''), $periodos),
        (string) ($ficha['motivacao'] ?? ''),
        (string) ($comoConheceu[(string) ($ficha['como_conheceu'] ?? '')] ?? $ficha['como_conheceu'] ?? ''),
        (string) ($status[(string) ($ficha['status'] ?? '')] ?? $ficha['status'] ?? ''),
    ];
}

function mcp_drive_idade(string $nascimento, string $referenciaUtc): string
{
    try {
        $n = new DateTimeImmutable($nascimento, new DateTimeZone(MCP_DRIVE_FUSO));
        $r = (new DateTimeImmutable($referenciaUtc !== '' ? $referenciaUtc : mcp_agora(), new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(MCP_DRIVE_FUSO));
        return (string) $n->diff($r)->y;
    } catch (Throwable) {
        return '';
    }
}

function mcp_drive_data_brt(string $utc, string $formato = 'd/m/Y H:i'): string
{
    try {
        return $utc === '' ? '' : (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(MCP_DRIVE_FUSO))->format($formato);
    } catch (Throwable) {
        return '';
    }
}

/**
 * PDF simples da ficha, só para quando lib/voluntarios.php (mcp_voluntario_pdf) ainda não está publicado: uma página
 * com os campos em texto, CPF só com os 3 primeiros dígitos. O PDF completo é o do módulo de voluntários.
 */
function mcp_drive_ficha_pdf_simples(array $ficha, string $protocolo): string
{
    $pdf = new McpPdf();
    $pdf->metadados('Ficha de cadastro de voluntário — ' . $protocolo, 'Cruz Vermelha Brasileira — Rio de Janeiro', 'Ficha de cadastro de voluntário');
    $margem = 56.0;
    $largura = McpPdf::LARGURA - 2 * $margem;
    $y = 80.0;
    $pdf->texto($margem, $y, 'Ficha de cadastro de voluntário', 'negrito', 18, '#222222');
    $y += 22;
    $pdf->texto($margem, $y, 'Protocolo ' . $protocolo . ' — recebida em ' . mcp_drive_data_brt((string) ($ficha['criado_em'] ?? '')) . ' (Brasília)', 'normal', 10, '#555555');
    $y += 26;
    $linha = mcp_drive_ficha_linha($ficha, $protocolo);
    $cpf = (string) ($ficha['cpf'] ?? '');
    $campos = [
        'Nome' => $linha[2], 'Idade' => $linha[3] . ' anos', 'CPF' => $cpf !== '' ? substr($cpf, 0, 3) . '.***.***-**' : 'não informado',
        'E-mail' => $linha[4], 'Telefone' => $linha[5], 'Cidade' => $linha[6], 'Escolaridade' => $linha[7], 'Profissão' => $linha[8],
        'Formação em saúde' => $linha[9], 'Frentes' => $linha[10], 'Dias' => $linha[11], 'Períodos' => $linha[12], 'Experiência' => (string) ($ficha['experiencia'] ?? ''),
        'Habilidades' => (string) ($ficha['habilidades'] ?? ''), 'Motivação' => $linha[13], 'Como conheceu' => $linha[14], 'Status' => $linha[15],
        'Consentimento' => mcp_drive_data_brt((string) ($ficha['consentimento_em'] ?? '')) . ' (política ' . (string) ($ficha['politica_versao'] ?? '') . ')',
        'Veracidade' => mcp_drive_data_brt((string) ($ficha['veracidade_em'] ?? '')),
    ];
    foreach ($campos as $rotulo => $valor) {
        $valor = trim((string) $valor);
        if ($valor === '') {
            continue;
        }
        $pdf->texto($margem, $y, $rotulo, 'negrito', 9, '#555555');
        $y += 12;
        foreach (McpPdf::quebrar($valor, 'normal', 10.5, $largura) as $parte) {
            if ($y > McpPdf::ALTURA - 60) {
                $pdf->novaPagina();
                $y = 70.0;
            }
            $pdf->texto($margem, $y, $parte, 'normal', 10.5, '#222222');
            $y += 14;
        }
        $y += 6;
    }
    return $pdf->gerar();
}

// ----------------------------------------------------------------------------- exportação diária (CSV sem CPF)
/**
 * CSVs do dia (fuso de Brasília; padrão: ontem, porque a rotina enfileira na primeira rodada do dia seguinte) das
 * tabelas da secretaria, sem nenhuma coluna de CPF, em Secretaria/Exportações/AAAA/AAAA-MM-DD-<tabela>.csv. Tabela sem
 * coluna de data (criado_em ou entrada) vai inteira. Um arquivo do mesmo nome que já exista é substituído.
 */
function mcp_drive_tarefa_exportacao(array $dados): string
{
    $dia = mcp_drive_dia_valido($dados['dia'] ?? null) ?? mcp_drive_dia_brt(-1);
    [$inicio, $fim] = mcp_drive_janela_utc($dia);
    $pdo = mcp_db();
    $enviadas = [];
    $vazias = [];
    foreach (MCP_DRIVE_EXPORTACAO_TABELAS as $tabela) {
        $colunas = mcp_drive_tabela_colunas($pdo, $tabela);
        if ($colunas === []) {
            continue; // a tabela ainda não existe neste banco (módulo não publicado)
        }
        [$csv, $linhas] = mcp_drive_tabela_csv($pdo, $tabela, $colunas, $inicio, $fim);
        if ($linhas === 0) {
            $vazias[] = $tabela;
            continue;
        }
        $nome = $dia . '-' . $tabela . '.csv';
        mcp_drive_com_pasta(MCP_DRIVE_PASTA_EXPORTACOES . '/' . substr($dia, 0, 4),
            static fn(string $pastaId): string => mcp_drive_enviar_pelo_nome($pastaId, $nome, $csv, 'text/csv'));
        $enviadas[] = $tabela . ' (' . $linhas . ')';
    }
    return $dia . ': ' . ($enviadas ? implode(', ', $enviadas) : 'nenhuma linha') . ($vazias ? '; sem linhas: ' . implode(', ', $vazias) : '');
}

/** Colunas da tabela (vazio se ela não existe). */
function mcp_drive_tabela_colunas(PDO $pdo, string $tabela): array
{
    if (!preg_match('/^mcp_[a-z0-9_]+$/', $tabela)) {
        throw new InvalidArgumentException('tabela inválida: ' . $tabela);
    }
    try {
        return array_map(static fn(array $c): string => (string) $c['Field'], $pdo->query("SHOW COLUMNS FROM `$tabela`")->fetchAll());
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '42S02') {
            return [];
        }
        throw $e;
    }
}

/**
 * @return array{0: string, 1: int} o CSV (com BOM e ponto e vírgula, que o Excel em português abre direto) e a contagem
 */
function mcp_drive_tabela_csv(PDO $pdo, string $tabela, array $colunas, string $inicioUtc, string $fimUtc): array
{
    $exportar = array_values(array_filter($colunas, static fn(string $c): bool => !str_contains(strtolower($c), 'cpf')));
    $data = null;
    foreach (['criado_em', 'entrada'] as $candidata) {
        if (in_array($candidata, $colunas, true)) {
            $data = $candidata;
            break;
        }
    }
    $sql = 'SELECT ' . implode(', ', array_map(static fn(string $c): string => "`$c`", $exportar)) . " FROM `$tabela`";
    $parametros = [];
    if ($data !== null) {
        $sql .= " WHERE `$data` >= ? AND `$data` < ?";
        $parametros = [$inicioUtc, $fimUtc];
    }
    $sql .= in_array('id', $colunas, true) ? ' ORDER BY id' : '';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);
    $f = fopen('php://temp', 'w+');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, $exportar, ';', '"', '');
    $linhas = 0;
    while (($linha = $stmt->fetch()) !== false) {
        fputcsv($f, array_map('mcp_drive_celula', array_values($linha)), ';', '"', '');
        $linhas++;
    }
    rewind($f);
    $csv = (string) stream_get_contents($f);
    fclose($f);
    return [$csv, $linhas];
}

/** Célula de CSV: o que começa com =, +, -, @ ou tabulação ganha um apóstrofo, para a planilha não executar fórmula. */
function mcp_drive_celula(mixed $valor): string
{
    $texto = $valor === null ? '' : (string) $valor;
    return preg_match('/^[=+\-@\t\r]/', $texto) ? "'" . $texto : $texto;
}

// ----------------------------------------------------------------------------- cópia do banco
/**
 * Cada tabela mcp_* inteira em JSON ({tabela, gerado_em, linhas: [...]}) comprimido em gzip, lida em lotes de 500 linhas
 * e comprimida aos poucos (a memória guarda só o comprimido), em Backups/banco/AAAA-MM-DD/<tabela>.json.gz.
 */
function mcp_drive_tarefa_backup(array $dados): string
{
    $dia = mcp_drive_dia_valido($dados['dia'] ?? null) ?? mcp_drive_dia_brt(0);
    $pdo = mcp_db();
    $tabelas = array_values(array_filter($pdo->query("SHOW TABLES LIKE 'mcp\\_%'")->fetchAll(PDO::FETCH_COLUMN),
        static fn(mixed $t): bool => is_string($t) && preg_match('/^mcp_[a-z0-9_]+$/', $t) === 1));
    $bytes = 0;
    foreach ($tabelas as $tabela) {
        $gz = mcp_drive_tabela_json_gz($pdo, $tabela);
        $bytes += strlen($gz);
        mcp_drive_com_pasta(MCP_DRIVE_PASTA_BACKUPS . '/' . $dia,
            static fn(string $pastaId): string => mcp_drive_enviar_pelo_nome($pastaId, $tabela . '.json.gz', $gz, 'application/gzip'));
    }
    return sprintf('%s: %d tabela(s), %s comprimidos', $dia, count($tabelas), mcp_drive_tamanho_humano($bytes));
}

function mcp_drive_tabela_json_gz(PDO $pdo, string $tabela): string
{
    if (!preg_match('/^mcp_[a-z0-9_]+$/', $tabela)) {
        throw new InvalidArgumentException('tabela inválida: ' . $tabela);
    }
    $colunas = mcp_drive_tabela_colunas($pdo, $tabela);
    $ordem = in_array('id', $colunas, true) ? ' ORDER BY id' : (in_array('ficha_id', $colunas, true) ? ' ORDER BY ficha_id' : '');
    $contexto = function_exists('deflate_init') ? deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]) : null;
    // As selfies dos voluntários (~100 KB cada, em base64) vão em lotes de 20: 500 delas seguraria ~50 MB na memória.
    $loteMax = $tabela === 'mcp_voluntarios_selfies' ? 20 : MCP_DRIVE_BACKUP_LOTE;
    $saida = '';
    $cru = '';
    $escrever = static function (string $pedaco) use (&$saida, &$cru, $contexto): void {
        if ($contexto) {
            $saida .= deflate_add($contexto, $pedaco, ZLIB_NO_FLUSH);
        } else {
            $cru .= $pedaco;
        }
    };
    $escrever('{"tabela":' . json_encode($tabela) . ',"gerado_em":' . json_encode(mcp_agora()) . ',"linhas":[');
    $primeira = true;
    $deslocamento = 0;
    while (true) {
        $lote = $pdo->query("SELECT * FROM `$tabela`$ordem LIMIT " . $loteMax . " OFFSET $deslocamento")->fetchAll();
        foreach ($lote as $linha) {
            $escrever(($primeira ? '' : ',') . json_encode($linha, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
            $primeira = false;
        }
        if (count($lote) < $loteMax) {
            break;
        }
        $deslocamento += $loteMax;
    }
    $escrever(']}');
    if ($contexto) {
        return $saida . deflate_add($contexto, '', ZLIB_FINISH);
    }
    return (string) gzencode($cru, 6);
}

function mcp_drive_tamanho_humano(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
    return $bytes >= 1024 ? number_format($bytes / 1024, 0, ',', '.') . ' KB' : $bytes . ' B';
}

// ----------------------------------------------------------------------------- datas (Brasília)
function mcp_drive_dia_brt(int $deslocamentoDias = 0, ?int $agora = null): string
{
    return (new DateTimeImmutable('@' . ($agora ?? time())))->setTimezone(new DateTimeZone(MCP_DRIVE_FUSO))->modify(sprintf('%+d day', $deslocamentoDias))->format('Y-m-d');
}

function mcp_drive_dia_valido(mixed $dia): ?string
{
    return is_string($dia) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia) && checkdate((int) substr($dia, 5, 2), (int) substr($dia, 8, 2), (int) substr($dia, 0, 4)) ? $dia : null;
}

/** O dia de Brasília em UTC: [00:00 do dia, 00:00 do dia seguinte), no formato das colunas DATETIME. */
function mcp_drive_janela_utc(string $dia): array
{
    $inicio = new DateTimeImmutable($dia . ' 00:00:00', new DateTimeZone(MCP_DRIVE_FUSO));
    $utc = new DateTimeZone('UTC');
    return [$inicio->setTimezone($utc)->format('Y-m-d H:i:s'), $inicio->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
}

// ----------------------------------------------------------------------------- espelho dos assets
/**
 * Percorre public_html/assets e envia para Site/assets/<caminho> o que é novo ou mudou (tamanho ou data de modificação
 * diferentes do manifesto mcp_drive_arquivos), até $maxArquivos arquivos ou $maxSegundos segundos por rodada (a rotina
 * roda a cada 15 minutos; o que sobrar vai na próxima). Arquivos acima de 50 MB e os que começam com ponto (e tudo em
 * pastas que começam com ponto) ficam de fora. $raiz troca a pasta só nos testes.
 * @return array{vistos: int, enviados: int, ignorados: int, falhas: int, restantes: int}
 */
function mcp_drive_espelhar_assets(int $maxArquivos = 40, int $maxSegundos = 50, ?string $raiz = null): array
{
    $comeco = microtime(true);
    $raiz = rtrim($raiz ?? dirname(__DIR__, 3) . '/assets', '/');
    $resultado = ['vistos' => 0, 'enviados' => 0, 'ignorados' => 0, 'falhas' => 0, 'restantes' => 0];
    if (!is_dir($raiz)) {
        return $resultado;
    }
    $pdo = mcp_db();
    $manifesto = [];
    foreach ($pdo->query('SELECT caminho, tamanho, modificado_em, drive_id FROM mcp_drive_arquivos')->fetchAll() as $linha) {
        $manifesto[(string) $linha['caminho']] = $linha;
    }
    $candidatos = [];
    $iterador = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
        static fn(SplFileInfo $item): bool => !str_starts_with($item->getFilename(), '.')
    ));
    $ocultos = 0;
    foreach ($iterador as $arquivo) {
        /** @var SplFileInfo $arquivo */
        if (!$arquivo->isFile()) {
            continue;
        }
        $relativo = substr($arquivo->getPathname(), strlen($raiz) + 1);
        if ($arquivo->getSize() > MCP_DRIVE_ASSET_MAX || strlen($relativo) > 255) {
            $resultado['ignorados']++;
            continue;
        }
        $resultado['vistos']++;
        $modificado = gmdate('Y-m-d H:i:s', $arquivo->getMTime());
        $conhecido = $manifesto[$relativo] ?? null;
        if ($conhecido !== null && (int) $conhecido['tamanho'] === $arquivo->getSize() && (string) $conhecido['modificado_em'] === $modificado) {
            continue;
        }
        $candidatos[$relativo] = ['tamanho' => $arquivo->getSize(), 'modificado_em' => $modificado, 'drive_id' => $conhecido !== null ? (string) $conhecido['drive_id'] : null];
    }
    // Os que começam com ponto nem entram no iterador; conta-os à parte só para o resumo.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
        if (str_starts_with($item->getFilename(), '.')) {
            $ocultos++;
        }
    }
    $resultado['ignorados'] += $ocultos;
    ksort($candidatos, SORT_STRING);
    $gravar = $pdo->prepare('INSERT INTO mcp_drive_arquivos (caminho, tamanho, modificado_em, drive_id, enviado_em) VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE tamanho = VALUES(tamanho), modificado_em = VALUES(modificado_em), drive_id = VALUES(drive_id), enviado_em = VALUES(enviado_em)');
    $pendentes = count($candidatos);
    foreach ($candidatos as $relativo => $info) {
        if ($resultado['enviados'] + $resultado['falhas'] >= $maxArquivos || microtime(true) - $comeco >= $maxSegundos) {
            break;
        }
        $pendentes--;
        try {
            $conteudo = file_get_contents($raiz . '/' . $relativo);
            if ($conteudo === false) {
                throw new RuntimeException('não foi possível ler ' . $relativo);
            }
            $pasta = dirname($relativo);
            $caminhoPasta = MCP_DRIVE_PASTA_ASSETS . ($pasta !== '.' && $pasta !== '' ? '/' . $pasta : '');
            $nome = basename($relativo);
            $mime = mcp_drive_mime($nome);
            $id = (string) mcp_drive_com_pasta($caminhoPasta,
                static fn(string $pastaId): string => mcp_drive_enviar_arquivo($pastaId, $nome, $conteudo, $mime, $info['drive_id']));
            $gravar->execute([$relativo, $info['tamanho'], $info['modificado_em'], $id, mcp_agora()]);
            $resultado['enviados']++;
        } catch (Throwable $e) {
            $resultado['falhas']++;
            error_log('[matricula] drive: assets/' . $relativo . ' não subiu: ' . mcp_drive_resumir_erro($e));
            // Sem rede ou sem permissão, os outros também vão falhar: para aqui e deixa para a próxima rodada.
            if ($e instanceof McpDriveErro && in_array($e->status, [0, 401, 403], true)) {
                break;
            }
        }
    }
    $resultado['restantes'] = $pendentes;
    return $resultado;
}

/** Tipo MIME pela extensão (o que o site usa); o resto por fileinfo ou genérico. */
function mcp_drive_mime(string $nome): string
{
    $tipos = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
        'avif' => 'image/avif', 'ico' => 'image/x-icon', 'css' => 'text/css', 'js' => 'text/javascript', 'mjs' => 'text/javascript', 'json' => 'application/json',
        'html' => 'text/html', 'htm' => 'text/html', 'txt' => 'text/plain', 'md' => 'text/markdown', 'xml' => 'application/xml', 'pdf' => 'application/pdf',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
        'mp3' => 'audio/mpeg', 'zip' => 'application/zip', 'gz' => 'application/gzip', 'csv' => 'text/csv', 'webmanifest' => 'application/manifest+json',
    ];
    $extensao = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
    return $tipos[$extensao] ?? 'application/octet-stream';
}
