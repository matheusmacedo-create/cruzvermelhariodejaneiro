<?php
/** MySQL (PDO): conexão, tabelas, leitura e escrita das inscrições, registro de eventos. */
declare(strict_types=1);

/** Banco fora do ar. O tratador global em lib.php responde 503 com mensagem amigável. */
class McpBancoIndisponivel extends RuntimeException
{
}

function mcp_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        mcp_cfg('DB_HOST', 'localhost'), (int) mcp_cfg('DB_PORT', 3306), mcp_cfg('DB_NAME', ''));
    try {
        $conexao = new PDO($dsn, (string) mcp_cfg('DB_USER', ''), (string) mcp_cfg('DB_SENHA', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        mcp_migrar($conexao);
    } catch (PDOException $e) {
        error_log('[matricula] banco indisponível: ' . $e->getMessage());
        throw new McpBancoIndisponivel('Banco de dados indisponível.', 0, $e);
    }
    $pdo = $conexao;
    return $pdo;
}

/**
 * Versão do esquema: o md5 deste arquivo sem esta linha (scripts/testar_checkout.php confere e diz o valor
 * novo quando o arquivo muda). Vem do código que está rodando, e não do arquivo em disco: logo depois de um
 * deploy, uma requisição servida com o db.php antigo (opcache) não grava a versão nova sem criar o que é novo.
 */
const MCP_DB_VERSAO = '9b34f3381fda8336';

/**
 * Cria e atualiza as tabelas (CREATE IF NOT EXISTS evita passo manual no deploy). Roda inteira só quando
 * MCP_DB_VERSAO mudou desde a última vez (a versão fica em mcp_chaves). No dia a dia, uma consulta por
 * requisição em vez de ~20 comandos. Para forçar, apague a linha versao_banco de mcp_chaves.
 */
function mcp_migrar(PDO $pdo): void
{
    $versao = 'db:' . MCP_DB_VERSAO;
    try {
        if ($pdo->query("SELECT valor FROM mcp_chaves WHERE nome = 'versao_banco'")->fetchColumn() === $versao) {
            return;
        }
    } catch (PDOException) {
        // Primeira vez: mcp_chaves ainda não existe.
    }
    mcp_migrar_tudo($pdo);
    $pdo->prepare("INSERT INTO mcp_chaves (nome, valor, criado_em) VALUES ('versao_banco', ?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor), criado_em = VALUES(criado_em)")
        ->execute([$versao, gmdate('Y-m-d H:i:s')]);
}

function mcp_migrar_tudo(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_inscricoes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        token CHAR(40) NOT NULL,
        curso_slug VARCHAR(80) NOT NULL,
        curso_nome VARCHAR(160) NOT NULL,
        nome VARCHAR(160) NOT NULL,
        cpf CHAR(11) NOT NULL,
        email VARCHAR(190) NOT NULL,
        telefone VARCHAR(20) NOT NULL,
        metodo ENUM('pix','cartao') NOT NULL,
        inscricao_centavos INT UNSIGNED NOT NULL,
        taxa_centavos INT UNSIGNED NOT NULL DEFAULT 0,
        total_centavos INT UNSIGNED NOT NULL,
        cobre_taxa TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('pendente','pago','recusado','expirado','estornado') NOT NULL DEFAULT 'pendente',
        unicopag_hash VARCHAR(64) NULL,
        unicopag_status VARCHAR(40) NULL,
        pix_copia_cola TEXT NULL,
        pix_url VARCHAR(255) NULL,
        pix_imagem VARCHAR(255) NULL,
        bandeira VARCHAR(30) NULL,
        ultimos4 CHAR(4) NULL,
        utm_source VARCHAR(120) NULL,
        utm_medium VARCHAR(120) NULL,
        utm_campaign VARCHAR(160) NULL,
        utm_content VARCHAR(160) NULL,
        utm_term VARCHAR(160) NULL,
        fbclid VARCHAR(255) NULL,
        gclid VARCHAR(255) NULL,
        ip VARCHAR(45) NULL,
        escola_status ENUM('nao_aplicavel','pendente','ok','erro') NOT NULL DEFAULT 'nao_aplicavel',
        escola_tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
        escola_tentativa_em DATETIME NULL,
        escola_acesso TEXT NULL,
        escola_token CHAR(64) NULL,
        email_aluno VARCHAR(20) NULL,
        email_secretaria VARCHAR(20) NULL,
        criado_em DATETIME NOT NULL,
        atualizado_em DATETIME NOT NULL,
        pago_em DATETIME NULL,
        consultado_em DATETIME NULL,
        UNIQUE KEY uq_token (token),
        KEY ix_hash (unicopag_hash),
        KEY ix_email (email, criado_em),
        KEY ix_cpf (cpf, criado_em),
        KEY ix_status (status),
        KEY ix_ip (ip, criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Link de criar senha na plataforma da escola (28/09/2026) em bancos que já tinham a tabela.
    mcp_garantir_colunas($pdo, 'mcp_inscricoes', ['escola_token' => 'CHAR(64) NULL']);
    // Mensagens do chat de contato do site (api/contato.php). Fonte da verdade: o e-mail à equipe é cópia.
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_contatos (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        protocolo VARCHAR(24) NULL,
        nome VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL,
        telefone VARCHAR(20) NULL,
        assunto VARCHAR(30) NOT NULL,
        curso_slug VARCHAR(80) NULL,
        curso_nome VARCHAR(160) NULL,
        mensagem TEXT NOT NULL,
        pagina VARCHAR(255) NULL,
        utm_source VARCHAR(120) NULL,
        utm_medium VARCHAR(120) NULL,
        utm_campaign VARCHAR(160) NULL,
        utm_content VARCHAR(160) NULL,
        utm_term VARCHAR(160) NULL,
        fbclid VARCHAR(255) NULL,
        gclid VARCHAR(255) NULL,
        ip VARCHAR(45) NULL,
        email_equipe VARCHAR(20) NULL,
        email_confirmacao VARCHAR(20) NULL,
        status ENUM('novo','respondido','arquivado') NOT NULL DEFAULT 'novo',
        resposta TEXT NULL,
        respondido_por VARCHAR(120) NULL,
        email_resposta VARCHAR(20) NULL,
        respondido_em DATETIME NULL,
        criado_em DATETIME NOT NULL,
        KEY ix_email (email, criado_em),
        KEY ix_ip (ip, criado_em),
        KEY ix_criado (criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Colunas do painel de respostas (19/09, à noite) em bancos que já tinham a tabela.
    mcp_garantir_colunas($pdo, 'mcp_contatos', [
        'status' => "ENUM('novo','respondido','arquivado') NOT NULL DEFAULT 'novo'",
        'resposta' => 'TEXT NULL',
        'respondido_por' => 'VARCHAR(120) NULL',
        'email_resposta' => 'VARCHAR(20) NULL',
    ]);
    // Chaves geradas no servidor (segredo dos links do painel): não exigem editar config.php.
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_chaves (
        nome VARCHAR(40) NOT NULL PRIMARY KEY,
        valor VARCHAR(128) NOT NULL,
        criado_em DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_eventos (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        inscricao_id INT UNSIGNED NULL,
        tipo VARCHAR(40) NOT NULL,
        detalhe TEXT NULL,
        criado_em DATETIME NOT NULL,
        KEY ix_inscricao (inscricao_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Dias e horários preferidos pelo aluno (29/09/2026): uma linha por inscrição paga, que ele pode
    // mudar pelo mesmo link. horarios = combinações dia-período marcadas ("seg-noite,sab-manha").
    // A secretaria vê o mapa por curso no painel (lib/horarios.php).
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_preferencias (
        inscricao_id INT UNSIGNED NOT NULL PRIMARY KEY,
        curso_slug VARCHAR(80) NOT NULL,
        horarios VARCHAR(200) NOT NULL,
        inicio VARCHAR(20) NOT NULL,
        turma_serve VARCHAR(10) NULL,
        observacao TEXT NULL,
        vezes INT UNSIGNED NOT NULL DEFAULT 1,
        criado_em DATETIME NOT NULL,
        atualizado_em DATETIME NOT NULL,
        KEY ix_curso (curso_slug, atualizado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Ponto da sede (29/09/2026, lib/ponto.php e lib/presenca.php). Colaboradores cadastrados pela
    // secretaria registram entrada e saída; alunos registram a chegada às aulas, e a presença vira o
    // comprovante de comparecimento. Horários em UTC, como no resto do banco.
    // vinculo: diretoria e voluntario somam horas doadas e assinam o termo de adesão (Lei 9.608/1998,
    // termo_*); empregado, terceirizado e outro registram só a presença na sede.
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_colaboradores (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(160) NOT NULL,
        cpf CHAR(11) NOT NULL,
        email VARCHAR(190) NULL,
        telefone VARCHAR(20) NULL,
        funcao VARCHAR(120) NULL,
        vinculo VARCHAR(12) NOT NULL DEFAULT 'voluntario',
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        desligado_em DATE NULL,
        termo_em DATE NULL,
        termo_modelo VARCHAR(12) NULL,
        termo_registrado_por VARCHAR(190) NULL,
        termo_registrado_em DATETIME NULL,
        aviso_email TINYINT(1) NOT NULL DEFAULT 1,
        aviso_whatsapp TINYINT(1) NOT NULL DEFAULT 0,
        aviso_dias VARCHAR(30) NULL,
        aviso_saida TINYINT(1) NOT NULL DEFAULT 1,
        aviso_comunicados TINYINT(1) NOT NULL DEFAULT 1,
        aviso_chave CHAR(16) NULL,
        aviso_whatsapp_em DATETIME NULL,
        aviso_whatsapp_por VARCHAR(190) NULL,
        aviso_whatsapp_como VARCHAR(160) NULL,
        aviso_dias_por VARCHAR(190) NULL,
        aviso_atualizado_em DATETIME NULL,
        criado_por VARCHAR(190) NULL,
        criado_em DATETIME NOT NULL,
        atualizado_em DATETIME NOT NULL,
        UNIQUE KEY ux_cpf (cpf)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Lembretes e comunicados do ponto (30/09/2026, lib/avisos.php): canais, dias da véspera e o
    // consentimento para o WhatsApp, em bancos que já tinham a tabela.
    mcp_garantir_colunas($pdo, 'mcp_colaboradores', MCP_DB_COLUNAS_AVISOS);
    // voluntario: natureza do registro no momento da entrada (1 = horas doadas; 0 = só presença, apagada
    // depois de 90 dias). Mudar o vínculo depois não muda o que já foi registrado.
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_ponto (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        colaborador_id INT UNSIGNED NOT NULL,
        voluntario TINYINT(1) NOT NULL DEFAULT 1,
        entrada DATETIME NOT NULL,
        saida DATETIME NULL,
        origem_entrada VARCHAR(12) NOT NULL,
        origem_saida VARCHAR(12) NULL,
        aparelho_entrada INT UNSIGNED NULL,
        aparelho_saida INT UNSIGNED NULL,
        distancia_entrada SMALLINT UNSIGNED NULL,
        distancia_saida SMALLINT UNSIGNED NULL,
        ajuste TEXT NULL,
        saida_informada DATETIME NULL,
        saida_informada_em DATETIME NULL,
        criado_em DATETIME NOT NULL,
        atualizado_em DATETIME NOT NULL,
        KEY ix_colaborador (colaborador_id, entrada),
        KEY ix_entrada (entrada),
        KEY ix_presenca (voluntario, entrada)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Saída informada pela própria pessoa (lembrete da manhã seguinte ou tela do ponto): fica à espera
    // da secretaria, que aceita ou recusa no portal.
    mcp_garantir_colunas($pdo, 'mcp_ponto', ['saida_informada' => 'DATETIME NULL', 'saida_informada_em' => 'DATETIME NULL']);
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_ponto_aparelhos (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(80) NOT NULL,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        criado_por VARCHAR(190) NOT NULL,
        criado_em DATETIME NOT NULL,
        usado_em DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_presencas (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        token CHAR(40) NOT NULL,
        codigo CHAR(8) NOT NULL,
        cpf CHAR(11) NOT NULL,
        nome VARCHAR(160) NOT NULL,
        email VARCHAR(190) NULL,
        aula_id VARCHAR(64) NOT NULL,
        turma_id VARCHAR(64) NULL,
        curso_id VARCHAR(64) NULL,
        curso_nome VARCHAR(160) NOT NULL,
        aula_data DATE NOT NULL,
        horario VARCHAR(60) NOT NULL,
        inicio DATETIME NULL,
        fim DATETIME NOT NULL,
        chegada DATETIME NOT NULL,
        origem VARCHAR(12) NOT NULL,
        aparelho_id INT UNSIGNED NULL,
        distancia SMALLINT UNSIGNED NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'valida',
        cancelada_por VARCHAR(190) NULL,
        cancelada_em DATETIME NULL,
        email_status VARCHAR(20) NULL,
        email_tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
        email_em DATETIME NULL,
        criado_em DATETIME NOT NULL,
        UNIQUE KEY ux_aula (cpf, aula_id),
        UNIQUE KEY ux_token (token),
        UNIQUE KEY ux_codigo (codigo),
        KEY ix_data (aula_data),
        KEY ix_envio (email_em, fim)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Declarações de horas voluntárias emitidas no portal: o código impresso no PDF é conferido em conferir/.
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_declaracoes_horas (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        codigo CHAR(8) NOT NULL,
        colaborador_id INT UNSIGNED NOT NULL,
        nome VARCHAR(160) NOT NULL,
        cpf CHAR(11) NOT NULL,
        funcao VARCHAR(120) NULL,
        de DATE NOT NULL,
        ate DATE NOT NULL,
        minutos INT UNSIGNED NOT NULL,
        dias INT UNSIGNED NOT NULL,
        termo_em DATE NULL,
        emitida_por VARCHAR(190) NOT NULL,
        emitida_em DATETIME NOT NULL,
        UNIQUE KEY ux_codigo (codigo),
        KEY ix_colaborador (colaborador_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Lembretes e comunicados do ponto (30/09/2026, lib/avisos.php e lib/comunicacao.php).
    // Ajustes que a secretaria liga e desliga no portal (lembretes, WhatsApp, data do lançamento).
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_ajustes (
        nome VARCHAR(40) NOT NULL PRIMARY KEY,
        valor VARCHAR(255) NOT NULL,
        atualizado_por VARCHAR(190) NULL,
        atualizado_em DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Comunicados das três fases da implantação (antes, durante e depois de 2 semanas) e avulsos.
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_campanhas (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        fase VARCHAR(10) NOT NULL,
        nome VARCHAR(120) NOT NULL,
        publico VARCHAR(20) NOT NULL,
        canais VARCHAR(40) NOT NULL,
        assunto VARCHAR(160) NOT NULL,
        titulo VARCHAR(120) NOT NULL,
        mensagem TEXT NOT NULL,
        botao VARCHAR(12) NOT NULL DEFAULT 'nenhum',
        botao_rotulo VARCHAR(60) NULL,
        whatsapp TEXT NULL,
        aviso_ponto VARCHAR(255) NULL,
        aviso_dias TINYINT UNSIGNED NOT NULL DEFAULT 14,
        status VARCHAR(10) NOT NULL DEFAULT 'rascunho',
        agendada_para DATETIME NULL,
        iniciada_em DATETIME NULL,
        concluida_em DATETIME NULL,
        criado_por VARCHAR(190) NOT NULL,
        criado_em DATETIME NOT NULL,
        atualizado_por VARCHAR(190) NULL,
        atualizado_em DATETIME NOT NULL,
        KEY ix_status (status, agendada_para)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Cada mensagem, por canal: fila, registro do envio e clique. chave impede mandar a mesma coisa duas
    // vezes (ex.: "vespera|12|2026-10-01|email"). dados guarda o contexto; o texto é montado na hora de enviar.
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_avisos (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        chave VARCHAR(120) NOT NULL,
        tipo VARCHAR(12) NOT NULL,
        canal VARCHAR(10) NOT NULL,
        campanha_id INT UNSIGNED NULL,
        colaborador_id INT UNSIGNED NULL,
        pessoa VARCHAR(40) NULL,
        nome VARCHAR(160) NOT NULL,
        destino VARCHAR(190) NOT NULL,
        referencia VARCHAR(20) NULL,
        dados TEXT NULL,
        assunto VARCHAR(200) NULL,
        texto TEXT NULL,
        status VARCHAR(10) NOT NULL,
        provedor VARCHAR(10) NULL,
        provedor_id VARCHAR(120) NULL,
        entrega VARCHAR(10) NULL,
        erro VARCHAR(255) NULL,
        tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
        agendado_para DATETIME NOT NULL,
        expira_em DATETIME NULL,
        enviado_em DATETIME NULL,
        enviado_por VARCHAR(190) NULL,
        clicado_em DATETIME NULL,
        cliques SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        criado_em DATETIME NOT NULL,
        atualizado_em DATETIME NOT NULL,
        UNIQUE KEY ux_chave (chave),
        KEY ix_fila (status, agendado_para),
        KEY ix_colaborador (colaborador_id, criado_em),
        KEY ix_campanha (campanha_id, status),
        KEY ix_provedor (provedor_id),
        KEY ix_tipo (tipo, criado_em),
        KEY ix_criado (criado_em),
        KEY ix_enviado (status, enviado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Quem pediu para não receber (alunos, que não têm cadastro aqui): só o hash do e-mail ou do celular.
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_avisos_bloqueios (
        canal VARCHAR(10) NOT NULL,
        destino_hash CHAR(64) NOT NULL,
        origem VARCHAR(20) NOT NULL,
        criado_em DATETIME NOT NULL,
        PRIMARY KEY (canal, destino_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Opinião sobre o ponto (comunicado das 2 semanas). pessoa = "c<id>" (colaborador) ou "a<hash>"
    // (aluno), para não responder duas vezes; o portal mostra o nome só de quem marcou contato_ok.
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_opinioes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        campanha_id INT UNSIGNED NOT NULL,
        pessoa VARCHAR(40) NOT NULL,
        publico VARCHAR(12) NOT NULL,
        vinculo VARCHAR(12) NULL,
        nome VARCHAR(160) NULL,
        contato VARCHAR(190) NULL,
        facilidade TINYINT UNSIGNED NULL,
        como VARCHAR(12) NULL,
        problemas VARCHAR(200) NULL,
        lembretes VARCHAR(12) NULL,
        comentario TEXT NULL,
        contato_ok TINYINT(1) NOT NULL DEFAULT 0,
        criado_em DATETIME NOT NULL,
        atualizado_em DATETIME NOT NULL,
        UNIQUE KEY ux_pessoa (campanha_id, pessoa)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Colunas dos lembretes em mcp_colaboradores (as mesmas do CREATE TABLE, para bancos antigos). */
const MCP_DB_COLUNAS_AVISOS = [
    'aviso_email' => 'TINYINT(1) NOT NULL DEFAULT 1',
    'aviso_whatsapp' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'aviso_dias' => 'VARCHAR(30) NULL',
    'aviso_saida' => 'TINYINT(1) NOT NULL DEFAULT 1',
    'aviso_comunicados' => 'TINYINT(1) NOT NULL DEFAULT 1',
    'aviso_chave' => 'CHAR(16) NULL',
    'aviso_whatsapp_em' => 'DATETIME NULL',
    'aviso_whatsapp_por' => 'VARCHAR(190) NULL',
    // Como a pessoa autorizou o WhatsApp (formulário, pessoalmente, pela página): a prova do consentimento.
    'aviso_whatsapp_como' => 'VARCHAR(160) NULL',
    // Quem escolheu os dias dos lembretes ("a própria pessoa" ou o e-mail da secretaria).
    'aviso_dias_por' => 'VARCHAR(190) NULL',
    'aviso_atualizado_em' => 'DATETIME NULL',
];

/**
 * Índices das tabelas que já existiam antes deles (freios, "na sede", presenças por e-mail, cota de e-mail).
 * Roda só na rotina da linha de comando (a cada 15 minutos), para não pesar em cada visita.
 */
const MCP_DB_INDICES = [
    'mcp_avisos' => ['ix_criado' => 'criado_em', 'ix_enviado' => 'status, enviado_em'],
    'mcp_eventos' => ['ix_tipo_criado' => 'tipo, criado_em'],
    'mcp_ponto' => ['ix_sem_saida' => 'saida, entrada'],
    'mcp_presencas' => ['ix_email' => 'email, chegada'],
];

function mcp_garantir_indices(PDO $pdo): void
{
    foreach (MCP_DB_INDICES as $tabela => $indices) {
        $existentes = array_column($pdo->query("SHOW INDEX FROM $tabela")->fetchAll(), 'Key_name');
        foreach ($indices as $nome => $colunas) {
            if (!in_array($nome, $existentes, true)) {
                $pdo->exec("ALTER TABLE $tabela ADD KEY $nome ($colunas)");
            }
        }
    }
}

/**
 * Faxina dos registros de freio (IP de quem consultou o ponto, abriu as páginas pessoais etc.): só valem
 * por minutos; depois de 2 dias saem. Os de armadilha (robôs), depois de 30.
 */
function mcp_eventos_apagar_freios(?int $agora = null): int
{
    $agora ??= time();
    $stmt = mcp_db()->prepare("DELETE FROM mcp_eventos WHERE (tipo IN ('ponto_consulta', 'ponto_consulta_falha', 'aviso_pagina', 'conferir', 'painel_link', 'escola_horarios_negado', 'escola_fora', 'ponto_rede_sede')
        AND criado_em < ?) OR (tipo = 'armadilha' AND criado_em < ?)");
    $stmt->execute([gmdate('Y-m-d H:i:s', $agora - 2 * 86400), gmdate('Y-m-d H:i:s', $agora - 30 * 86400)]);
    return $stmt->rowCount();
}

/** Acrescenta à tabela as colunas que ainda não existem (migração idempotente, barata: um SHOW COLUMNS). */
function mcp_garantir_colunas(PDO $pdo, string $tabela, array $colunas): void
{
    $existentes = array_column($pdo->query("SHOW COLUMNS FROM $tabela")->fetchAll(), 'Field');
    foreach ($colunas as $nome => $definicao) {
        if (!in_array($nome, $existentes, true)) {
            try {
                $pdo->exec("ALTER TABLE $tabela ADD COLUMN $nome $definicao");
            } catch (PDOException $e) {
                // Outra requisição acrescentou a coluna no mesmo instante (1060: Duplicate column name).
                if ((int) ($e->errorInfo[1] ?? 0) !== 1060) {
                    throw $e;
                }
            }
        }
    }
}

/** Segredo gerado uma vez e guardado no banco (ex.: assinatura dos links do painel). */
function mcp_segredo(string $nome): string
{
    static $cache = [];
    if (isset($cache[$nome])) {
        return $cache[$nome];
    }
    $pdo = mcp_db();
    $stmt = $pdo->prepare('SELECT valor FROM mcp_chaves WHERE nome = ?');
    $stmt->execute([$nome]);
    $valor = $stmt->fetchColumn();
    if (!$valor) {
        $pdo->prepare('INSERT IGNORE INTO mcp_chaves (nome, valor, criado_em) VALUES (?, ?, ?)')->execute([$nome, bin2hex(random_bytes(32)), mcp_agora()]);
        $stmt->execute([$nome]); // relê: outro processo pode ter inserido antes
        $valor = $stmt->fetchColumn();
    }
    return $cache[$nome] = (string) $valor;
}

/** Trilha de auditoria por inscrição. Nunca recebe dado de cartão nem senha. Falha aqui não derruba a requisição. */
function mcp_registrar(?int $inscricaoId, string $tipo, string $detalhe = ''): void
{
    try {
        mcp_db()->prepare('INSERT INTO mcp_eventos (inscricao_id, tipo, detalhe, criado_em) VALUES (?, ?, ?, ?)')
            ->execute([$inscricaoId, $tipo, mb_substr($detalhe, 0, 4000), mcp_agora()]);
    } catch (Throwable $e) {
        error_log('[matricula] registro falhou: ' . $e->getMessage());
    }
}

function mcp_inscricao_por(string $coluna, string $valor): ?array
{
    if (!in_array($coluna, ['token', 'unicopag_hash', 'id'], true)) {
        throw new InvalidArgumentException("coluna de busca não permitida: $coluna");
    }
    $stmt = mcp_db()->prepare("SELECT * FROM mcp_inscricoes WHERE $coluna = ? LIMIT 1");
    $stmt->execute([$valor]);
    $linha = $stmt->fetch();
    return $linha ?: null;
}

/** Atualiza colunas conhecidas de uma inscrição (as chaves vêm sempre do código, nunca do cliente). */
function mcp_atualizar(int $id, array $campos): void
{
    foreach (array_keys($campos) as $coluna) {
        if (!preg_match('/^[a-z_]+$/', (string) $coluna)) {
            throw new InvalidArgumentException("coluna inválida: $coluna");
        }
    }
    $campos['atualizado_em'] = mcp_agora();
    $sets = implode(', ', array_map(static fn($c) => "$c = :$c", array_keys($campos)));
    $campos['id'] = $id;
    mcp_db()->prepare("UPDATE mcp_inscricoes SET $sets WHERE id = :id")->execute($campos);
}

/** Quantas inscrições uma chave (ip, cpf ou email) abriu nos últimos N segundos. Base dos freios. */
function mcp_contar_recentes(string $coluna, string $valor, int $segundos): int
{
    if (!in_array($coluna, ['ip', 'cpf', 'email'], true)) {
        throw new InvalidArgumentException("coluna não permitida: $coluna");
    }
    $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_inscricoes WHERE $coluna = ? AND criado_em > ?");
    $stmt->execute([$valor, gmdate('Y-m-d H:i:s', time() - $segundos)]);
    return (int) $stmt->fetchColumn();
}

// ----------------------------------------------------------------------------- contatos (chat do site)
/** Grava a mensagem do chat e devolve o id. As chaves vêm do código (contato.php), nunca do cliente. */
function mcp_contato_gravar(array $c): int
{
    $agora = mcp_agora();
    $linha = [
        'nome' => $c['nome'], 'email' => $c['email'], 'telefone' => $c['telefone'] ?: null, 'assunto' => $c['assunto'],
        'curso_slug' => $c['curso_slug'], 'curso_nome' => $c['curso_nome'], 'mensagem' => $c['mensagem'], 'pagina' => $c['pagina'],
        'utm_source' => $c['utm_source'], 'utm_medium' => $c['utm_medium'], 'utm_campaign' => $c['utm_campaign'],
        'utm_content' => $c['utm_content'], 'utm_term' => $c['utm_term'], 'fbclid' => $c['fbclid'], 'gclid' => $c['gclid'],
        'ip' => mcp_ip(), 'criado_em' => $agora,
    ];
    $colunas = implode(', ', array_keys($linha));
    $marcadores = implode(', ', array_fill(0, count($linha), '?'));
    $pdo = mcp_db();
    $pdo->prepare("INSERT INTO mcp_contatos ($colunas) VALUES ($marcadores)")->execute(array_values($linha));
    return (int) $pdo->lastInsertId();
}

function mcp_contato_por_id(int $id): ?array
{
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_contatos WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $linha = $stmt->fetch();
    return $linha ?: null;
}

function mcp_contato_atualizar(int $id, array $campos): void
{
    foreach (array_keys($campos) as $coluna) {
        if (!preg_match('/^[a-z_]+$/', (string) $coluna)) {
            throw new InvalidArgumentException("coluna inválida: $coluna");
        }
    }
    $sets = implode(', ', array_map(static fn($c) => "$c = :$c", array_keys($campos)));
    $campos['id'] = $id;
    mcp_db()->prepare("UPDATE mcp_contatos SET $sets WHERE id = :id")->execute($campos);
}

/** Quantas mensagens uma chave (ip ou email) enviou nos últimos N segundos. Base dos freios do chat. */
function mcp_contar_contatos_recentes(string $coluna, string $valor, int $segundos): int
{
    if (!in_array($coluna, ['ip', 'email'], true)) {
        throw new InvalidArgumentException("coluna não permitida: $coluna");
    }
    $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_contatos WHERE $coluna = ? AND criado_em > ?");
    $stmt->execute([$valor, gmdate('Y-m-d H:i:s', time() - $segundos)]);
    return (int) $stmt->fetchColumn();
}

/** Protocolo legível para a pessoa citar na resposta: CV-aammdd-NNNN (data de Brasília, id da mensagem). */
function mcp_contato_protocolo(int $id, ?string $agoraUtc = null): string
{
    $data = (new DateTimeImmutable($agoraUtc ?? mcp_agora(), new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Sao_Paulo'));
    return sprintf('CV-%s-%04d', $data->format('ymd'), $id);
}

/** Quantos eventos de um tipo com o mesmo detalhe (ex.: IP) nos últimos N segundos. Freio de ações sem tabela própria. */
function mcp_contar_eventos_recentes(string $tipo, string $detalhe, int $segundos): int
{
    $stmt = mcp_db()->prepare('SELECT COUNT(*) FROM mcp_eventos WHERE tipo = ? AND detalhe = ? AND criado_em > ?');
    $stmt->execute([$tipo, $detalhe, gmdate('Y-m-d H:i:s', time() - $segundos)]);
    return (int) $stmt->fetchColumn();
}

/** Contatos para o painel, mais recentes primeiro; $status null = todos. */
function mcp_contatos_listar(?string $status, int $limite = 50, int $deslocamento = 0): array
{
    $sql = 'SELECT id, protocolo, nome, email, telefone, assunto, curso_nome, status, criado_em, respondido_em FROM mcp_contatos';
    $params = [];
    if ($status !== null) {
        $sql .= ' WHERE status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(200, $limite)) . ' OFFSET ' . max(0, $deslocamento);
    $stmt = mcp_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Total de contatos por status: ['novo' => n, 'respondido' => n, 'arquivado' => n]. */
function mcp_contatos_contar(): array
{
    $totais = ['novo' => 0, 'respondido' => 0, 'arquivado' => 0];
    foreach (mcp_db()->query('SELECT status, COUNT(*) AS n FROM mcp_contatos GROUP BY status')->fetchAll() as $linha) {
        $totais[(string) $linha['status']] = (int) $linha['n'];
    }
    return $totais;
}
