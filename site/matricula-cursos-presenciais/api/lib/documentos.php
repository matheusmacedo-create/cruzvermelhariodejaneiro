<?php
/**
 * Pedido de documentos da filial (página /pedido-de-documentos/, endpoint api/documentos.php), 17/10/2026.
 *
 * Regras do Matheus: tudo por e-mail; quem pede comprova quem é (documento com foto e, se for o caso,
 * procuração ou ofício) e diz para que quer o documento. Por isso:
 *  - o pedido entra em mcp_contatos com o assunto 'documentos' (protocolo, painel e resposta do atendimento);
 *  - os arquivos de comprovação NÃO são gravados no servidor nem no banco: saem anexados no e-mail à equipe
 *    (EMAIL_CONTATO) e o PHP apaga o temporário no fim da requisição. No banco fica só o resumo (nomes e
 *    tamanhos dos arquivos), para provar o que chegou;
 *  - quem decide o que enviar é a equipe, pelo quadro de "quem pode receber o quê" que vai no próprio aviso.
 *
 * Este módulo é incluído só por documentos.php (não entra na lista de lib.php).
 */
declare(strict_types=1);

const MCP_DOC_PRAZO = '20 dias';
const MCP_DOC_ARQUIVOS_MAX = 4;
const MCP_DOC_ARQUIVO_BYTES = 8 * 1024 * 1024;   // 8 MB por arquivo
const MCP_DOC_TOTAL_BYTES = 16 * 1024 * 1024;    // 16 MB somados (a Resend aceita 40 MB por e-mail)
const MCP_DOC_FINALIDADE_MIN = 20;
const MCP_DOC_FINALIDADE_MAX = 2000;
const MCP_DOC_DESCRICAO_MAX = 300;
const MCP_DOC_LIMITE_IP = [5, 3600];
const MCP_DOC_LIMITE_EMAIL = [3, 3600];

/** Documentos que podem ser pedidos. As chaves também estão no formulário (scripts/gerar_pedido_documentos.py). */
function mcp_doc_documentos(): array
{
    return [
        'estatuto' => 'Estatuto da Cruz Vermelha Brasileira do Rio de Janeiro (24/10/2016)',
        'ata-notarial-2026-02-02' => 'Escritura pública de ata notarial (02/02/2026)',
        'outro' => 'Outro documento da filial',
    ];
}

/** Quem está pedindo, e o que precisa comprovar além do documento de identidade com foto. */
function mcp_doc_perfis(): array
{
    return [
        'titular' => ['rotulo' => 'Sou a pessoa citada no documento (titular dos dados)', 'curto' => 'Titular dos dados',
            'comprovar' => 'documento de identidade com foto'],
        'representante' => ['rotulo' => 'Represento a pessoa citada (advogado ou procurador)', 'curto' => 'Representante do titular',
            'comprovar' => 'documento de identidade com foto e procuração (ou carteira da OAB e procuração)'],
        'orgao' => ['rotulo' => 'Falo em nome de órgão público, de controle ou do Judiciário', 'curto' => 'Órgão público ou de controle',
            'comprovar' => 'ofício ou requisição do órgão e documento funcional'],
        'membro' => ['rotulo' => 'Sou membro ou associado da filial', 'curto' => 'Membro ou associado',
            'comprovar' => 'documento de identidade com foto'],
        'outro' => ['rotulo' => 'Outra pessoa ou instituição (imprensa, pesquisa, empresa...)', 'curto' => 'Outra pessoa ou instituição',
            'comprovar' => 'documento de identidade com foto (e, se for o caso, o documento da instituição)'],
    ];
}

/** O que a equipe pode enviar a cada perfil. Vai no aviso à equipe; a página pública diz o mesmo com outras palavras. */
function mcp_doc_orientacao(string $perfil): string
{
    return match ($perfil) {
        'titular' => 'Pode receber a íntegra do que diz respeito a ele (LGPD, art. 18), depois de conferir que o documento de identidade é da pessoa citada.',
        'representante' => 'Pode receber a íntegra do que diz respeito ao representado, se a procuração der esse poder e a identidade conferir.',
        'orgao' => 'Pode receber a íntegra quando houver ofício ou requisição formal do órgão. Na dúvida sobre a autenticidade, confirme pelo telefone oficial do órgão antes de enviar.',
        'membro' => 'Recebe o que o Estatuto garante aos membros; dados pessoais de terceiros só com base legal. Confira o cadastro de membro.',
        default => 'Recebe a versão publicada no portal da transparência e a indicação do cartório onde pode tirar certidão. A íntegra com dados pessoais de terceiros não é enviada sem base legal.',
    };
}

/** Tipo do arquivo pelos primeiros bytes (não pela extensão nem pelo que o navegador declara). */
function mcp_doc_tipo_real(string $caminho): ?array
{
    $f = @fopen($caminho, 'rb');
    if ($f === false) {
        return null;
    }
    $inicio = (string) fread($f, 16);
    fclose($f);
    if (str_starts_with($inicio, '%PDF-')) {
        return ['ext' => 'pdf', 'tipo' => 'application/pdf'];
    }
    if (str_starts_with($inicio, "\xFF\xD8\xFF")) {
        return ['ext' => 'jpg', 'tipo' => 'image/jpeg'];
    }
    if (str_starts_with($inicio, "\x89PNG\r\n\x1A\n")) {
        return ['ext' => 'png', 'tipo' => 'image/png'];
    }
    return null;
}

/**
 * Os arquivos do campo "comprovantes[]": de 1 a MCP_DOC_ARQUIVOS_MAX, PDF, JPG ou PNG, dentro dos limites.
 * Devolve [['nome', 'conteudo', 'tipo', 'bytes'], ...] com nomes gerados aqui (o nome original vira só texto).
 */
function mcp_doc_ler_arquivos(array $files, string $protocoloProvisorio = 'pedido'): array
{
    $campo = $files['comprovantes'] ?? null;
    if (!is_array($campo) || !isset($campo['error'])) {
        mcp_falhar(422, 'Anexe o seu documento de identidade com foto (e os outros comprovantes, se for o caso).', ['campo' => 'comprovantes']);
    }
    // Um arquivo só chega como escalar; vários, como listas.
    $lista = [];
    foreach ((array) $campo['error'] as $i => $erro) {
        $lista[] = [
            'erro' => (int) $erro,
            'tmp' => (string) ((array) $campo['tmp_name'])[$i],
            'nome' => (string) ((array) $campo['name'])[$i],
            'bytes' => (int) ((array) $campo['size'])[$i],
        ];
    }
    $lista = array_values(array_filter($lista, static fn(array $a): bool => $a['erro'] !== UPLOAD_ERR_NO_FILE));
    if (!$lista) {
        mcp_falhar(422, 'Anexe o seu documento de identidade com foto (e os outros comprovantes, se for o caso).', ['campo' => 'comprovantes']);
    }
    if (count($lista) > MCP_DOC_ARQUIVOS_MAX) {
        mcp_falhar(422, 'Envie no máximo ' . MCP_DOC_ARQUIVOS_MAX . ' arquivos.', ['campo' => 'comprovantes']);
    }
    $saida = [];
    $total = 0;
    foreach ($lista as $n => $a) {
        if (in_array($a['erro'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $a['bytes'] > MCP_DOC_ARQUIVO_BYTES) {
            mcp_falhar(422, 'Cada arquivo pode ter até ' . (MCP_DOC_ARQUIVO_BYTES >> 20) . ' MB. Diminua a foto ou o PDF e envie de novo.', ['campo' => 'comprovantes']);
        }
        if ($a['erro'] !== UPLOAD_ERR_OK || !is_uploaded_file($a['tmp'])) {
            mcp_falhar(422, 'Um dos arquivos não chegou inteiro. Envie de novo.', ['campo' => 'comprovantes']);
        }
        $tipo = mcp_doc_tipo_real($a['tmp']);
        if ($tipo === null) {
            mcp_falhar(422, 'Envie os comprovantes em PDF, JPG ou PNG.', ['campo' => 'comprovantes']);
        }
        $conteudo = (string) file_get_contents($a['tmp']);
        $total += strlen($conteudo);
        if ($total > MCP_DOC_TOTAL_BYTES) {
            mcp_falhar(422, 'Os arquivos somados podem ter até ' . (MCP_DOC_TOTAL_BYTES >> 20) . ' MB.', ['campo' => 'comprovantes']);
        }
        $saida[] = [
            'nome' => sprintf('%s-comprovante-%d.%s', $protocoloProvisorio, $n + 1, $tipo['ext']),
            'original' => mcp_texto($a['nome'], 120) ?: 'sem nome',
            'conteudo' => $conteudo,
            'tipo' => $tipo['tipo'],
            'bytes' => strlen($conteudo),
        ];
    }
    return $saida;
}

/** Campos de texto do formulário, validados. Cada falha responde 422 com o nome do campo. */
function mcp_doc_validar(array $p): array
{
    $nome = mcp_texto($p['nome'] ?? '', 120);
    if (mb_strlen($nome) < 5 || !str_contains($nome, ' ')) {
        mcp_falhar(422, 'Digite seu nome completo, como está no documento.', ['campo' => 'nome']);
    }
    $email = mb_strtolower(mcp_texto($p['email'] ?? '', 190));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        mcp_falhar(422, 'Esse e-mail não parece válido. A resposta vai para ele.', ['campo' => 'email']);
    }
    $telefoneBruto = mcp_texto($p['telefone'] ?? '', 30);
    $telefone = $telefoneBruto === '' ? '' : mcp_telefone($telefoneBruto);
    if ($telefoneBruto !== '' && $telefone === '') {
        mcp_falhar(422, 'Telefone incompleto. Use DDD + número, ou deixe em branco.', ['campo' => 'telefone']);
    }
    $perfil = mcp_texto($p['perfil'] ?? '', 20);
    if (!isset(mcp_doc_perfis()[$perfil])) {
        mcp_falhar(422, 'Diga em nome de quem você faz o pedido.', ['campo' => 'perfil']);
    }
    $instituicao = mcp_texto($p['instituicao'] ?? '', 160);
    if (in_array($perfil, ['orgao'], true) && mb_strlen($instituicao) < 3) {
        mcp_falhar(422, 'Informe o órgão em nome do qual você faz o pedido.', ['campo' => 'instituicao']);
    }
    $documento = mcp_texto($p['documento'] ?? '', 40);
    if (!isset(mcp_doc_documentos()[$documento])) {
        mcp_falhar(422, 'Escolha o documento que você quer.', ['campo' => 'documento']);
    }
    $descricao = mcp_texto($p['descricao'] ?? '', MCP_DOC_DESCRICAO_MAX);
    if ($documento === 'outro' && mb_strlen($descricao) < 5) {
        mcp_falhar(422, 'Descreva o documento que você quer (nome, data ou assunto).', ['campo' => 'descricao']);
    }
    $finalidade = mcp_texto_longo($p['finalidade'] ?? '', MCP_DOC_FINALIDADE_MAX + 1);
    if (mb_strlen($finalidade) < MCP_DOC_FINALIDADE_MIN) {
        mcp_falhar(422, 'Explique para que você precisa do documento (pelo menos ' . MCP_DOC_FINALIDADE_MIN . ' caracteres).', ['campo' => 'finalidade']);
    }
    if (mb_strlen($finalidade) > MCP_DOC_FINALIDADE_MAX) {
        mcp_falhar(422, 'A explicação pode ter até ' . number_format(MCP_DOC_FINALIDADE_MAX, 0, ',', '.') . ' caracteres.', ['campo' => 'finalidade']);
    }
    if (($p['declaracao'] ?? '') !== 'sim') {
        mcp_falhar(422, 'Confirme que as informações e os documentos enviados são verdadeiros.', ['campo' => 'declaracao']);
    }
    if (($p['privacidade'] ?? '') !== 'sim') {
        mcp_falhar(422, 'Confirme que leu como usamos os seus documentos.', ['campo' => 'privacidade']);
    }
    return [
        'nome' => $nome, 'email' => $email, 'telefone' => $telefone, 'perfil' => $perfil, 'instituicao' => $instituicao,
        'documento' => $documento, 'descricao' => $descricao, 'finalidade' => $finalidade,
    ];
}

/** Texto que fica no banco (coluna mensagem): o pedido inteiro, sem o conteúdo dos arquivos. */
function mcp_doc_resumo(array $d, array $arquivos): string
{
    $perfil = mcp_doc_perfis()[$d['perfil']]['curto'];
    $linhas = [
        'Documento pedido: ' . mcp_doc_documentos()[$d['documento']] . ($d['descricao'] !== '' ? ' — ' . $d['descricao'] : ''),
        'Quem pede: ' . $perfil . ($d['instituicao'] !== '' ? ' — ' . $d['instituicao'] : ''),
        '',
        'Para que precisa:',
        $d['finalidade'],
        '',
        'Comprovantes recebidos (anexados só no e-mail à equipe, não guardados no site):',
    ];
    foreach ($arquivos as $a) {
        $linhas[] = '- ' . $a['nome'] . ' (enviado como "' . $a['original'] . '", ' . mcp_doc_tamanho($a['bytes']) . ')';
    }
    return implode("\n", $linhas);
}

function mcp_doc_tamanho(int $bytes): string
{
    return $bytes >= 1048576
        ? number_format($bytes / 1048576, 1, ',', '.') . ' MB'
        : max(1, (int) round($bytes / 1024)) . ' KB';
}

// ----------------------------------------------------------------------------- e-mails
/** Aviso à equipe: o pedido, os comprovantes anexados e o quadro de quem pode receber o quê. */
function mcp_doc_montar_email_equipe(array $c, array $d, array $arquivos): array
{
    $protocolo = (string) $c['protocolo'];
    $nome = (string) $c['nome'];
    $email = (string) $c['email'];
    $perfil = mcp_doc_perfis()[$d['perfil']];
    $documento = mcp_doc_documentos()[$d['documento']] . ($d['descricao'] !== '' ? ' — ' . $d['descricao'] : '');
    $linkPainel = (string) ($c['link_painel'] ?? '');
    $mailto = 'mailto:' . $email . '?subject=' . rawurlencode("Pedido de documento · $protocolo");
    $quando = mcp_data_brt((string) ($c['criado_em'] ?? ''), 'd/m/Y \à\s H\hi');

    $linhas = [
        'Nome' => $nome,
        'E-mail' => ['html' => '<a href="mailto:' . mcp_escapar($email) . '" style="color:#cc0000;text-decoration:none">' . mcp_escapar($email) . '</a>'],
        'Telefone' => $c['telefone'] ? mcp_telefone_bonito((string) $c['telefone']) : 'não informado',
        'Quem pede' => $perfil['curto'] . ($d['instituicao'] !== '' ? ' — ' . $d['instituicao'] : ''),
        'Documento' => $documento,
        'Recebido em' => $quando . ' (Brasília)',
        'Prazo de resposta' => MCP_DOC_PRAZO . ' (prometido à pessoa)',
        'Protocolo' => $protocolo,
    ];
    $itensArquivos = array_map(static fn(array $a): string => '<strong>' . mcp_escapar($a['nome']) . '</strong> · ' . mcp_escapar(mcp_doc_tamanho($a['bytes']))
        . ' · enviado como “' . mcp_escapar($a['original']) . '”', $arquivos);

    $corpo = mcp_p('<strong>' . mcp_escapar($nome) . '</strong> pediu um documento da filial pela página de pedido de documentos. Os comprovantes estão <strong>anexados a este e-mail</strong> e não ficam guardados no site.')
        . mcp_subtitulo('Para que precisa')
        . mcp_citacao($d['finalidade'])
        . mcp_subtitulo('Comprovantes anexados')
        . mcp_lista($itensArquivos)
        . mcp_nota('Precisa comprovar: ' . mcp_escapar($perfil['comprovar']) . '. Se faltar algo ou estiver ilegível, peça por e-mail antes de enviar qualquer documento.')
        . mcp_subtitulo('O que pode ser enviado')
        . mcp_p(mcp_escapar(mcp_doc_orientacao($d['perfil'])))
        . mcp_nota('Ninguém precisa de documento da filial para tirar certidão: a ata notarial pode ser pedida ao 12º Serviço Notarial do Rio, e o estatuto registrado, ao cartório de registro de pessoas jurídicas. Quando a resposta for a versão pública, indique esse caminho.')
        . ($linkPainel !== '' ? mcp_botao($linkPainel, 'Registrar a resposta no painel') : '')
        . mcp_botao($mailto, 'Responder por e-mail', true)
        . mcp_nota('Responder este e-mail vai direto para quem pediu. O documento, quando for o caso, segue como anexo da resposta. Depois de concluir, apague os comprovantes da caixa de e-mail que não precisar guardar.')
        . mcp_subtitulo('Dados do pedido')
        . mcp_caixa($linhas);

    $texto = "$nome pediu um documento da filial. Protocolo $protocolo.\n\nDocumento: $documento\nQuem pede: {$perfil['curto']}"
        . ($d['instituicao'] !== '' ? " — {$d['instituicao']}" : '') . "\nE-mail: $email\n\nPara que precisa:\n{$d['finalidade']}\n\nComprovantes anexados:\n"
        . implode("\n", array_map(static fn(array $a): string => "- {$a['nome']} (" . mcp_doc_tamanho($a['bytes']) . ")", $arquivos))
        . "\n\nPrecisa comprovar: {$perfil['comprovar']}.\nO que pode ser enviado: " . mcp_doc_orientacao($d['perfil'])
        . "\n\nPrazo prometido: " . MCP_DOC_PRAZO . ".\n" . ($linkPainel !== '' ? "Painel: $linkPainel\n" : '');
    return [
        'assunto' => "[Site] Pedido de documento: $nome · $protocolo",
        'html' => mcp_moldura("Pedido de documento de " . mcp_primeiro_nome($nome), $corpo, [
            'eyebrow' => "Pedido de documento · $protocolo",
            'preheader' => mb_substr("$documento — " . $d['finalidade'], 0, 140),
            'motivo' => 'Aviso automático da página de pedido de documentos de cruzvermelhariodejaneiro.org para ' . mcp_email_contato_endereco() . '.',
        ]),
        'texto' => $texto,
    ];
}

/** Confirmação a quem pediu: protocolo, prazo, o que acontece agora e o que chegou (sem os arquivos). */
function mcp_doc_montar_email_confirmacao(array $c, array $d, array $arquivos): array
{
    $protocolo = (string) $c['protocolo'];
    $nome = mcp_primeiro_nome((string) $c['nome']);
    $email = (string) $c['email'];
    $documento = mcp_doc_documentos()[$d['documento']] . ($d['descricao'] !== '' ? ' — ' . $d['descricao'] : '');
    $remetente = mcp_email_endereco(mcp_email_remetente_contato());
    $passos = [
        ['Conferimos quem você é', 'A equipe confere os comprovantes e o motivo do pedido. Se faltar algum documento ou ele estiver ilegível, pedimos por e-mail.'],
        ['Respondemos por e-mail em até ' . MCP_DOC_PRAZO, 'A resposta vai para <strong>' . mcp_escapar($email) . '</strong>, com o protocolo <strong>' . mcp_escapar($protocolo) . '</strong>. Pode ser o documento, a versão pública dele ou a explicação de por que não podemos enviar.'],
        ['Fique de olho na caixa de entrada e no spam', 'O remetente é <strong>' . mcp_escapar($remetente) . '</strong>.'],
    ];
    $linhas = ['Protocolo' => $protocolo, 'Documento pedido' => $documento, 'Arquivos recebidos' => (string) count($arquivos)];
    $corpo = mcp_p('Oi, ' . mcp_escapar($nome) . '. Recebemos o seu pedido de documento. Guarde o protocolo <strong>' . mcp_escapar($protocolo) . '</strong>.')
        . mcp_subtitulo('O que acontece agora')
        . mcp_passos($passos)
        . mcp_caixa($linhas)
        . mcp_subtitulo('O motivo que você informou')
        . mcp_citacao($d['finalidade'])
        . mcp_nota('Seus comprovantes não ficam guardados no site: foram direto para a equipe que analisa o pedido e são usados só para isso. Não foi você quem fez este pedido? Responda este e-mail avisando.');
    $texto = "Oi, $nome. Recebemos o seu pedido de documento. Protocolo: $protocolo.\n\nDocumento pedido: $documento\n\n"
        . "1) Conferimos quem você é. Se faltar algum documento, pedimos por e-mail.\n"
        . "2) Respondemos por e-mail em até " . MCP_DOC_PRAZO . ", para $email, com o protocolo.\n"
        . "3) O remetente é $remetente; olhe também o spam.\n\nO motivo que você informou:\n{$d['finalidade']}\n\n"
        . "Seus comprovantes não ficam guardados no site e são usados só para analisar este pedido. Não foi você? Responda este e-mail avisando.\n";
    return [
        'assunto' => "Recebemos seu pedido de documento · $protocolo",
        'html' => mcp_moldura("Recebemos seu pedido, $nome.", $corpo, [
            'eyebrow' => 'Pedido de documento',
            'preheader' => "Protocolo $protocolo. A resposta chega por e-mail em até " . MCP_DOC_PRAZO . '.',
            'motivo' => 'Você recebeu este e-mail porque fez um pedido de documento em cruzvermelhariodejaneiro.org.',
        ]),
        'texto' => $texto,
    ];
}

/** Envia o aviso à equipe (com os anexos; responder-para = quem pediu) e a confirmação. */
function mcp_doc_enviar_emails(array $c, array $d, array $arquivos): array
{
    $equipe = mcp_doc_montar_email_equipe($c, $d, $arquivos);
    $anexos = array_map(static fn(array $a): array => ['nome' => $a['nome'], 'conteudo' => $a['conteudo'], 'tipo' => $a['tipo']], $arquivos);
    $r1 = mcp_enviar_email(mcp_email_contato_endereco(), $equipe['assunto'], $equipe['html'], $equipe['texto'], (string) $c['email'], null, $anexos, ['tempo' => 60]);
    $confirmacao = mcp_doc_montar_email_confirmacao($c, $d, $arquivos);
    $r2 = mcp_enviar_email((string) $c['email'], $confirmacao['assunto'], $confirmacao['html'], $confirmacao['texto'], null, mcp_email_remetente_contato());
    return ['equipe' => $r1, 'confirmacao' => $r2];
}
