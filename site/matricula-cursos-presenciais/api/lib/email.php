<?php
/**
 * E-mails transacionais no padrão visual do site (logo, faixa vermelha, Inter, rodapé institucional).
 *
 * Componentes: moldura, botão, caixa de valores, passos numerados, bloco do PIX, citação. Envio por
 * Resend com fallback em mail(). Mensagens: PIX aberto (recuperação do pagamento), inscrição paga
 * (versão A com acesso da escola, versão B sem API), aviso à secretaria e as duas do chat de contato
 * (aviso à equipe e confirmação a quem escreveu).
 *
 * Pagar tudo (10/2026; spec-pagar-tudo.md 1.14 e 10.6): cada e-mail do pagamento tem a versão "taxa de inscrição +
 * matrícula" (com turma, sem turma na fila da próxima turma, acima da vaga, turma diferente, curso já pago, não
 * marcada) e a versão "só a taxa"; "Pago por" com as parcelas mensais; caixa com matrícula, juros e a linha do crédito;
 * Resumo das condições; rodapé com quem vende (a empresa de ensino, decisão 6 do dono). Entram o aviso de estorno, o
 * pré-chargeback e os e-mails da venda sem turma (turma abriu, mudou, foi cancelada ou confirmada, ainda sem data,
 * prazo, prorrogação, devolução) e os avisos à secretaria da rotina da espera.
 *
 * Cada mensagem tem uma função pura mcp_montar_email_*() que devolve ['assunto', 'html', 'texto'],
 * testável e pré-visualizável (scripts/previsualizar_emails.php), e uma mcp_email_*() que envia.
 * Desde 19/09/2026 nenhum e-mail cita WhatsApp: o contato é por e-mail (EMAIL_CONTATO), com
 * responder-para apontando para ele, e o chat do site fica em <site>/#chat.
 */
declare(strict_types=1);

const MCP_EMAIL_FONTE = "Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
const MCP_EMAIL_PRAZO = '3 dias úteis';
const MCP_EMAIL_CNPJ = '08.560.973/0001-97';
const MCP_NOME_FILIAL = 'Cruz Vermelha Brasileira Rio de Janeiro';
/** Nomes curtos que aparecem em configurações antigas (EMAIL_REMETENTE): o remetente sai sempre com o nome completo. */
const MCP_NOMES_CURTOS = ['cruz vermelha', 'cruz vermelha rj', 'cruz vermelha brasileira', 'cruz vermelha brasileira rj', 'cruz vermelha brasileira - rj', 'cruz vermelha brasileira – rj', 'cvb-rj', 'cvb rj', 'cvb'];
const MCP_TEXTO_ESTORNO = 'Você pode desistir em até 7 dias depois do pagamento e recebe de volta tudo o que pagou, inclusive a matrícula e os juros do parcelamento. Depois disso, o valor também é devolvido se não houver turma com horário compatível ou se você desistir antes da confirmação da turma. O prazo para o dinheiro aparecer depende de PIX ou cartão.';
/** Frase da venda sem turma (spec 10.10), acrescentada ao rodapé quando ela está ligada ou a compra esperou turma. */
/** O rodapé de quem pagou só a taxa: o mesmo direito, sem falar de matrícula e juros que essa compra não teve. */
const MCP_TEXTO_ESTORNO_SO_TAXA = 'Você pode desistir em até 7 dias depois do pagamento e recebe de volta tudo o que pagou. Depois disso, o valor também é devolvido se não houver turma com horário compatível ou se você desistir antes da confirmação da turma. O prazo para o dinheiro aparecer depende de PIX ou cartão.';
const MCP_TEXTO_ESTORNO_SEM_TURMA = 'Nos cursos comprados sem turma aberta, também volta tudo se a data ou o horário não servirem, se a turma for cancelada, ou se não houver turma marcada a tempo.';

/** Endereço que recebe o chat do site e responde os e-mails ao aluno. */
function mcp_email_contato_endereco(): string
{
    return (string) mcp_cfg('EMAIL_CONTATO', 'contato@cruzvermelhariodejaneiro.org');
}

/** Remetente das respostas do painel ao cliente (EMAIL_REMETENTE_CONTATO ou o remetente geral). */
function mcp_email_remetente_contato(): string
{
    return mcp_email_nome_oficial((string) mcp_cfg('EMAIL_REMETENTE_CONTATO', mcp_cfg('EMAIL_REMETENTE', MCP_NOME_FILIAL . ' <matricula@cruzvermelhariodejaneiro.org>')));
}

/** Só o endereço de um remetente no formato "Nome <endereco>". */
function mcp_email_endereco(string $remetente): string
{
    return preg_match('/<([^>]+)>/', $remetente, $m) ? $m[1] : trim($remetente);
}

/** "Nome <endereco>" com o nome completo da filial quando a configuração traz um nome curto ou só o endereço. */
function mcp_email_nome_oficial(string $remetente): string
{
    $remetente = trim($remetente);
    if (!preg_match('/^(.*?)\s*<([^>]+)>$/s', $remetente, $m)) {
        return filter_var($remetente, FILTER_VALIDATE_EMAIL) ? MCP_NOME_FILIAL . " <$remetente>" : $remetente;
    }
    $nome = trim($m[1], " \t\"'");
    if ($nome === '' || in_array(mb_strtolower($nome), MCP_NOMES_CURTOS, true)) {
        $nome = MCP_NOME_FILIAL;
    }
    return "$nome <{$m[2]}>";
}

/** Logo em PNG (WebP não abre no Outlook), 480 px para ficar nítido em tela retina a 180 px. */
function mcp_email_logo(): string
{
    return mcp_site_url() . '/assets/otim/logo-cvb-rj-480.png';
}

/** Data/hora UTC do banco em horário de Brasília. Devolve '' se o valor for inválido. */
function mcp_data_brt(?string $utc, string $formato = 'd/m \à\s H\hi'): string
{
    if ($utc === null || $utc === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format($formato);
    } catch (Throwable) {
        return '';
    }
}

// ----------------------------------------------------------------------------- componentes
/**
 * Moldura de todos os e-mails: faixa vermelha, logo, chapéu, título, corpo e rodapé com contato,
 * CNPJ e endereço. Opções: eyebrow (chapéu), preheader (linha de prévia na caixa de entrada),
 * motivo (por que a pessoa recebeu) e fornecedor (e-mails ao aluno sobre a compra: o rodapé diz quem vende e
 * recebe, com o CNPJ dele, no lugar do CNPJ da filial; spec 1.14, F2). Tabelas e CSS em linha para Gmail, Outlook e
 * Apple Mail.
 */
function mcp_moldura(string $titulo, string $corpo, array $opcoes = []): string
{
    $t = mcp_escapar($titulo);
    $eyebrow = mcp_escapar((string) ($opcoes['eyebrow'] ?? 'Cruz Vermelha Brasileira · Rio de Janeiro'));
    $preheader = (string) ($opcoes['preheader'] ?? '');
    $motivo = (string) ($opcoes['motivo'] ?? '');
    $fornecedor = '';
    if (!empty($opcoes['fornecedor'])) {
        $recebedor = mcp_email_recebedor();
        $fornecedor = mcp_escapar($recebedor['nome']) . ' · CNPJ ' . mcp_escapar($recebedor['cnpj']) . ' · Praça da Cruz Vermelha, 10, Centro, Rio de Janeiro/RJ';
    }
    $contato = mcp_escapar(mcp_email_contato_endereco());
    $site = mcp_escapar(mcp_site_url());
    $siteVisivel = mcp_escapar((string) preg_replace('#^https?://#', '', mcp_site_url()));
    $logo = mcp_escapar(mcp_email_logo());
    $f = MCP_EMAIL_FONTE;
    $pre = $preheader !== ''
        ? '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#f7f8fa">' . mcp_escapar($preheader) . str_repeat('&nbsp;&zwnj;', 40) . '</div>'
        : '';
    return "<!doctype html><html lang=\"pt-BR\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
        . "<meta name=\"color-scheme\" content=\"light\"><meta name=\"supported-color-schemes\" content=\"light\"><title>$t</title>"
        . "<link href=\"https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap\" rel=\"stylesheet\">"
        . "<style>body{margin:0;padding:0}a{color:#cc0000}@media (max-width:620px){.mcp-wrap{padding:12px 6px!important}.mcp-pad{padding-left:20px!important;padding-right:20px!important}.mcp-h1{font-size:24px!important}}</style></head>"
        . "<body style=\"margin:0;padding:0;background:#f7f8fa;-webkit-text-size-adjust:100%\" bgcolor=\"#f7f8fa\">$pre"
        . "<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" bgcolor=\"#f7f8fa\" style=\"background:#f7f8fa\"><tr><td class=\"mcp-wrap\" align=\"center\" style=\"padding:28px 12px\">"
        . "<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"600\" bgcolor=\"#ffffff\" style=\"width:100%;max-width:600px;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;font-family:$f;color:#1a202c\">"
        . "<tr><td bgcolor=\"#cc0000\" style=\"height:6px;line-height:6px;font-size:6px;background:#cc0000\">&nbsp;</td></tr>"
        . "<tr><td class=\"mcp-pad\" style=\"padding:26px 32px 0\"><a href=\"$site/\" style=\"text-decoration:none\"><img src=\"$logo\" width=\"180\" height=\"54\" alt=\"Cruz Vermelha Brasileira · Rio de Janeiro\" style=\"display:block;width:180px;height:54px;border:0\"></a></td></tr>"
        . "<tr><td class=\"mcp-pad\" style=\"padding:26px 32px 6px\"><p style=\"margin:0 0 8px;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#cc0000;font-weight:800\">$eyebrow</p>"
        . "<h1 class=\"mcp-h1\" style=\"margin:0;font-size:27px;line-height:1.15;letter-spacing:-.02em;color:#0f1318;font-weight:800\">$t</h1></td></tr>"
        . "<tr><td class=\"mcp-pad\" style=\"padding:10px 32px 30px;font-size:16px;line-height:1.55;color:#1a202c\">$corpo</td></tr>"
        . "<tr><td class=\"mcp-pad\" bgcolor=\"#f7f8fa\" style=\"padding:20px 32px;background:#f7f8fa;border-top:1px solid #e2e8f0;font-size:13px;line-height:1.55;color:#718096\">"
        . "<p style=\"margin:0 0 8px;color:#1a202c\"><strong>Dúvidas?</strong> Responda este e-mail ou escreva para <a href=\"mailto:$contato\" style=\"color:#cc0000;font-weight:700;text-decoration:none\">$contato</a>. Se preferir, use o chat em <a href=\"$site/#chat\" style=\"color:#cc0000;text-decoration:none\">$siteVisivel</a>.</p>"
        . ($fornecedor !== ''
            ? "<p style=\"margin:0 0 4px\">Cruz Vermelha Brasileira · Filial do Estado do Rio de Janeiro</p><p style=\"margin:0\">$fornecedor</p></td></tr>"
            : "<p style=\"margin:0 0 4px\">Cruz Vermelha Brasileira · Filial do Estado do Rio de Janeiro · CNPJ " . MCP_EMAIL_CNPJ . "</p>"
              . "<p style=\"margin:0\">Praça da Cruz Vermelha, 10 · Centro · Rio de Janeiro · RJ · CEP 20230-130</p></td></tr>")
        . "</table>"
        . ($motivo !== '' ? "<p style=\"margin:14px auto 0;max-width:600px;font-family:$f;font-size:12px;line-height:1.5;color:#a0aec0;text-align:center\">" . mcp_escapar($motivo) . "</p>" : '')
        . "</td></tr></table></body></html>";
}

/** Botão em tabela (funciona no Outlook). Secundário = branco com borda vermelha. */
function mcp_botao(string $url, string $rotulo, bool $secundario = false): string
{
    $fundo = $secundario ? '#ffffff' : '#cc0000';
    $cor = $secundario ? '#cc0000' : '#ffffff';
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0"><tr><td bgcolor="' . $fundo . '" style="border-radius:999px;background:' . $fundo . ';border:1.5px solid #cc0000">'
        . '<a href="' . mcp_escapar($url) . '" style="display:inline-block;padding:14px 26px;color:' . $cor . ';font-weight:800;font-size:16px;line-height:1.2;text-decoration:none;border-radius:999px">' . mcp_escapar($rotulo) . '</a></td></tr></table>';
}

function mcp_p(string $html): string
{
    return '<p style="margin:0 0 14px">' . $html . '</p>';
}

function mcp_nota(string $html): string
{
    return '<p style="margin:0 0 12px;font-size:13px;line-height:1.5;color:#718096">' . $html . '</p>';
}

function mcp_subtitulo(string $texto): string
{
    return '<h2 style="margin:26px 0 8px;font-size:18px;line-height:1.3;color:#0f1318;font-weight:800">' . mcp_escapar($texto) . '</h2>';
}

/**
 * Caixa cinza com linhas rótulo/valor; $total (uma linha) fica em destaque, separado por uma régua.
 * Um valor pode ser ['html' => '...'] já escapado pelo chamador (links de e-mail e telefone).
 * $rodapeHtml (já escapado): uma linha inteira, em letra menor, depois do total (a linha do crédito do parcelado).
 */
function mcp_caixa(array $linhas, array $total = [], string $rodapeHtml = ''): string
{
    $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#f7f8fa" style="margin:18px 0;background:#f7f8fa;border:1px solid #e2e8f0;border-radius:12px"><tr><td style="padding:14px 18px">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="font-size:15px;line-height:1.4">';
    $primeira = true;
    foreach ($linhas as $rotulo => $valor) {
        $borda = $primeira ? '0' : '1px solid #e2e8f0';
        $primeira = false;
        $v = is_array($valor) ? (string) ($valor['html'] ?? '') : mcp_escapar((string) $valor);
        $html .= '<tr><td valign="top" style="padding:7px 12px 7px 0;border-top:' . $borda . ';color:#718096">' . mcp_escapar((string) $rotulo) . '</td>'
            . '<td valign="top" align="right" style="padding:7px 0;border-top:' . $borda . ';color:#1a202c;font-weight:600;text-align:right">' . $v . '</td></tr>';
    }
    foreach ($total as $rotulo => $valor) {
        $html .= '<tr><td style="padding:10px 12px 4px 0;border-top:2px solid #0f1318;color:#0f1318;font-weight:800;font-size:16px">' . mcp_escapar((string) $rotulo) . '</td>'
            . '<td align="right" style="padding:10px 0 4px;border-top:2px solid #0f1318;color:#cc0000;font-weight:800;font-size:19px;text-align:right">' . mcp_escapar((string) $valor) . '</td></tr>';
    }
    if ($rodapeHtml !== '') {
        $html .= '<tr><td colspan="2" style="padding:8px 0 2px;font-size:13px;line-height:1.5;color:#4a5568">' . $rodapeHtml . '</td></tr>';
    }
    return $html . '</table></td></tr></table>';
}

/** Passos numerados (círculo vermelho, título e texto). $passos = [[título, html], ...]. */
/** Lista simples com marcador, para enumerar itens curtos. O HTML de cada item já vem escapado. */
function mcp_lista(array $itens): string
{
    $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:4px 0 12px">';
    foreach ($itens as $item) {
        $html .= '<tr><td width="16" valign="top" style="width:16px;padding:3px 0;color:#cc0000;font-size:15px;line-height:1.5">&bull;</td>'
            . '<td valign="top" style="padding:3px 0;font-size:15px;line-height:1.5;color:#4a5568">' . $item . '</td></tr>';
    }
    return $html . '</table>';
}

function mcp_passos(array $passos): string
{
    $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:4px 0 12px">';
    foreach (array_values($passos) as $i => [$titulo, $texto]) {
        $html .= '<tr><td width="40" valign="top" style="padding:8px 0;width:40px"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td width="28" height="28" align="center" valign="middle" bgcolor="#cc0000" style="width:28px;height:28px;border-radius:50%;background:#cc0000;color:#ffffff;font-size:13px;font-weight:800;line-height:28px">' . ($i + 1) . '</td></tr></table></td>'
            . '<td valign="top" style="padding:8px 0 8px 4px;font-size:15px;line-height:1.5"><strong style="color:#0f1318">' . mcp_escapar($titulo) . '</strong><br><span style="color:#4a5568">' . $texto . '</span></td></tr>';
    }
    return $html . '</table>';
}

/** Código PIX copia e cola com a instrução de pagamento. */
function mcp_bloco_pix(string $codigo): string
{
    return '<p style="margin:18px 0 6px;font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:#718096;font-weight:800">PIX copia e cola</p>'
        . '<div style="padding:14px;background:#f7f8fa;border:1px dashed #cbd5e1;border-radius:12px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;line-height:1.5;color:#1a202c;word-break:break-all">' . mcp_escapar($codigo) . '</div>'
        . mcp_nota('Como pagar: abra o app do seu banco, escolha <strong>Pix › Copia e Cola</strong>, cole o código acima e confirme.');
}

/** Texto de outra pessoa (mensagem do chat) em bloco destacado, com as quebras de linha. */
function mcp_citacao(string $texto): string
{
    return '<div style="margin:14px 0;padding:14px 18px;border-left:4px solid #cc0000;background:#f7f8fa;border-radius:0 12px 12px 0;font-size:15px;line-height:1.55;color:#1a202c">' . nl2br(mcp_escapar($texto)) . '</div>';
}

// ----------------------------------------------------------------------------- envio
/**
 * Envia por Resend (quando há chave) ou pelo mail() da Hostinger. Devolve 'resend', 'mail' ou
 * 'falhou'. Falha de e-mail nunca derruba a requisição: o pagamento ou a mensagem já foram gravados.
 * Responder-para: $responderPara (e-mail já validado com FILTER_VALIDATE_EMAIL, que não aceita quebra
 * de linha) ou, por padrão, EMAIL_RESPOSTA / EMAIL_CONTATO.
 * Anexos: lista de ['nome' => 'arquivo.pdf', 'conteudo' => bytes, 'tipo' => 'application/pdf'], usados
 * pelo comprovante de inscrição. O nome é saneado para ASCII antes de ir para cabeçalho.
 */
/**
 * Manda um e-mail pela Resend (ou, sem a chave dela, pelo mail() da hospedagem). Devolve resend, mail ou
 * falhou. $opcoes (avisos do ponto): sem_reserva (com a Resend configurada, não cai no mail() quando ela
 * falha: devolve falhou, limite no 429 ou incerto quando o tempo acabou depois de o pedido chegar) e
 * tempo (segundos de espera pela Resend; padrão 30).
 */
function mcp_enviar_email(string $para, string $assunto, string $html, string $texto, ?string $responderPara = null, ?string $remetente = null, array $anexos = [], array $opcoes = []): string
{
    $anexos = array_values(array_filter(array_map(static fn(array $a): array => [
        'nome' => (preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($a['nome'] ?? '')) ?: 'anexo'),
        'conteudo' => (string) ($a['conteudo'] ?? ''),
        'tipo' => preg_match('#^[a-z]+/[a-z0-9.+-]+$#', (string) ($a['tipo'] ?? '')) ? (string) $a['tipo'] : 'application/octet-stream',
    ], $anexos), static fn(array $a): bool => $a['conteudo'] !== ''));
    $remetente = mcp_email_nome_oficial($remetente ?? (string) mcp_cfg('EMAIL_REMETENTE', MCP_NOME_FILIAL . ' <matricula@cruzvermelhariodejaneiro.org>'));
    $resposta = $responderPara ?? (string) mcp_cfg('EMAIL_RESPOSTA', mcp_email_contato_endereco());
    if (!filter_var($resposta, FILTER_VALIDATE_EMAIL)) {
        $resposta = '';
    }
    $chave = (string) mcp_cfg('RESEND_API_KEY', '');
    // Sem reserva (avisos do ponto): nada de mail() da hospedagem, nem sem a Resend configurada.
    if ($chave === '' && !empty($opcoes['sem_reserva'])) {
        return 'sem_resend';
    }
    if ($chave !== '') {
        $corpo = ['from' => $remetente, 'to' => [$para], 'subject' => $assunto, 'html' => $html, 'text' => $texto];
        if ($resposta !== '') {
            $corpo['reply_to'] = $resposta;
        }
        // Cabeçalhos extras (ex.: List-Unsubscribe dos avisos do ponto), sem quebra de linha.
        $extras = [];
        foreach ((array) ($opcoes['cabecalhos'] ?? []) as $nome => $valor) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9-]{1,60}$/', (string) $nome) && !preg_match('/[\r\n]/', (string) $valor)) {
                $extras[(string) $nome] = (string) $valor;
            }
        }
        if ($extras) {
            $corpo['headers'] = $extras;
        }
        if ($anexos) {
            // Só filename e content: a Resend deduz o tipo pela extensão, e campo que ela não conhece
            // devolveria 422 e jogaria o envio no mail() de reserva, que entrega pior.
            $corpo['attachments'] = array_map(static fn(array $a): array => [
                'filename' => $a['nome'], 'content' => base64_encode($a['conteudo']),
            ], $anexos);
        }
        // RESEND_API_URL só existe para os testes locais (um servidor falso em 127.0.0.1); em produção, a da Resend.
        $url = (string) mcp_cfg('RESEND_API_URL', 'https://api.resend.com/emails');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($corpo, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $chave, 'Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => max(1, (int) ($opcoes['tempo'] ?? 30)),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (parse_url($url, PHP_URL_HOST) === '127.0.0.1' ? CURLPROTO_HTTP : 0),
        ]);
        $r = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $esgotado = curl_errno($ch) === CURLE_OPERATION_TIMEDOUT && (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME) > 0;
        curl_close($ch);
        if ($r !== false && $status > 0 && $status < 300) {
            return 'resend';
        }
        // No registro, só o erro (sem endereço de e-mail): a resposta da Resend pode citar o destinatário.
        error_log("[matricula] Resend falhou ($status): " . mb_substr((string) preg_replace('/[^\s"<>]+@[^\s"<>]+/', '[e-mail]', (string) $r), 0, 200));
        if (!empty($opcoes['sem_reserva'])) {
            return $status === 429 ? 'limite' : ($esgotado ? 'incerto' : 'falhou');
        }
    }
    // Fallback: mail() local. Cabeçalhos só com valores da configuração ou e-mails validados.
    $enderecoRemetente = preg_match('/<([^>]+)>/', $remetente, $m) ? $m[1] : $remetente;
    $cabecalhos = "From: $remetente\r\n" . ($resposta !== '' ? "Reply-To: $resposta\r\n" : '') . "MIME-Version: 1.0\r\n";
    $mensagem = $html;
    if ($anexos) {
        // Com anexo, a mensagem vira multipart/mixed: o HTML em base64 (evita linha acima de 998
        // caracteres, que servidor de e-mail corta) e cada anexo em base64 quebrado em 76 colunas.
        $fronteira = 'mcp_' . bin2hex(random_bytes(12));
        $cabecalhos .= "Content-Type: multipart/mixed; boundary=\"$fronteira\"\r\n";
        $mensagem = "--$fronteira\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html));
        foreach ($anexos as $a) {
            $mensagem .= "--$fronteira\r\nContent-Type: {$a['tipo']}; name=\"{$a['nome']}\"\r\n"
                . "Content-Disposition: attachment; filename=\"{$a['nome']}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($a['conteudo']));
        }
        $mensagem .= "--$fronteira--\r\n";
    } else {
        $cabecalhos .= "Content-Type: text/html; charset=UTF-8\r\n";
    }
    $assuntoCodificado = '=?UTF-8?B?' . base64_encode($assunto) . '?=';
    $ok = @mail($para, $assuntoCodificado, $mensagem, $cabecalhos, '-f' . $enderecoRemetente);
    return $ok ? 'mail' : 'falhou';
}

// ----------------------------------------------------------------------------- pagar tudo: dados comuns
/**
 * Pagar tudo (10/2026): a inscrição guarda o plano (só a taxa ou taxa + matrícula), as parcelas, os juros, o total
 * cobrado e a turma vendida (spec 3.3), e a fila de quem pagou tudo sem turma (spec 10.8). As funções abaixo leem
 * essas colunas com padrão seguro: linha antiga, sem as colunas, é "só a taxa", à vista, sem turma gravada.
 */
const MCP_EMAIL_MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
const MCP_EMAIL_SEMANA = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
/** Passo 03 da escola (spec 1.9), literal. */
const MCP_EMAIL_PASSO_03 = 'Antes da primeira aula, você recebe por e-mail e na sua conta as orientações da turma: data, horário, local e o que vestir. A entrada na aula é liberada com a matrícula paga.';
const MCP_EMAIL_LOCAL = 'Praça da Cruz Vermelha, 10 · Centro';
const MCP_EMAIL_NAO_INSCREVA = 'Na área do aluno, o curso só aparece quando a turma abrir. Não pague de novo e não se inscreva pela Escola: você entra sozinho na turma.';
/** Motivos da devolução na espera (spec 10.8), como a secretaria e a pessoa leem. */
const MCP_EMAIL_DEVOLVER_MOTIVOS = [
    'prazo' => 'data limite', 'pedido' => 'pedido da pessoa', 'data_nao_serve' => 'a data ou o horário não servem',
    'requisito' => 'requisito do curso', 'curso_ja_pago' => 'curso já pago na escola', 'turma_cancelada' => 'turma cancelada, sem resposta',
    'secretaria' => 'decisão da secretaria',
];

function mcp_email_ligada(string $chave): bool
{
    return function_exists('mcp_cfg_ligada') ? mcp_cfg_ligada($chave) : mcp_cfg($chave) === true;
}

/** Quem vende e recebe (decisão 6 do dono): ['nome', 'cnpj']. */
function mcp_email_recebedor(): array
{
    return function_exists('mcp_recebedor') ? mcp_recebedor()
        : ['nome' => (string) mcp_cfg('RECEBEDOR_NOME', 'O-CVB Filial Rio de Janeiro Ensino Ltda'), 'cnpj' => (string) mcp_cfg('RECEBEDOR_CNPJ', '67.733.551/0001-35')];
}

/** Quem concede o parcelamento (CDC, art. 54-B, § 3º): ['nome', 'cnpj']; padrão, o recebedor. */
function mcp_email_financiador(): array
{
    return function_exists('mcp_agente_financiador') ? mcp_agente_financiador() : mcp_email_recebedor();
}

function mcp_email_plano(array $i): string
{
    return ($i['plano'] ?? '') === 'taxa_e_matricula' ? 'taxa_e_matricula' : 'so_taxa';
}

function mcp_email_completo(array $i): bool
{
    return mcp_email_plano($i) === 'taxa_e_matricula';
}

/** A matrícula citada: o preço gravado na inscrição (matricula_preco_centavos); inscrição antiga cai no catálogo. 0 = sem valor. */
function mcp_email_matricula_centavos(array $i): int
{
    if (isset($i['matricula_preco_centavos']) && $i['matricula_preco_centavos'] !== '' && (int) $i['matricula_preco_centavos'] > 0) {
        return (int) $i['matricula_preco_centavos'];
    }
    $curso = mcp_curso((string) ($i['curso_slug'] ?? ''));
    return (int) ($curso['valor_curso_centavos'] ?? 0);
}

/** A matrícula com centavos ("R$ 180,00"), ou '' se o curso não tem valor. */
function mcp_email_matricula(array $inscricao): string
{
    $centavos = mcp_email_matricula_centavos($inscricao);
    return $centavos > 0 ? mcp_brl($centavos) : '';
}

/** A matrícula cobrada nesta compra (plano completo): matricula_centavos; sem ele, o preço. */
function mcp_email_matricula_cobrada(array $i): int
{
    return (int) ($i['matricula_centavos'] ?? 0) > 0 ? (int) $i['matricula_centavos'] : mcp_email_matricula_centavos($i);
}

/** O total pago: o cobrado com juros (total_cobrado_centavos); nulo, o total sem juros (total_centavos). */
function mcp_email_total_pago_centavos(array $i): int
{
    $cobrado = $i['total_cobrado_centavos'] ?? null;
    return $cobrado !== null && $cobrado !== '' && (int) $cobrado > 0 ? (int) $cobrado : (int) ($i['total_centavos'] ?? 0);
}

/** Parcelas no cartão (1 a 12); PIX é sempre 1. */
function mcp_email_parcelas(array $i): int
{
    return ($i['metodo'] ?? 'pix') === 'pix' ? 1 : max(1, min(12, (int) ($i['parcelas'] ?? 1)));
}

/** O total em n parcelas: a parcela truncada no centavo, e a 1ª com a diferença (spec 2.4). A soma é sempre o total. */
function mcp_email_divisao(int $total, int $n): array
{
    $n = max(1, $n);
    $parcela = intdiv($total, $n);
    return ['n' => $n, 'parcela' => $parcela, 'primeira' => $total - ($n - 1) * $parcela, 'total' => $total];
}

/** "10 parcelas mensais de R$ 35,43" ou, com a 1ª diferente, "10 parcelas mensais (1ª de R$ 35,46 e 9 de R$ 35,43)". */
function mcp_email_parcelas_texto(array $d): string
{
    return $d['primeira'] === $d['parcela']
        ? $d['n'] . ' parcelas mensais de ' . mcp_brl($d['parcela'])
        : $d['n'] . ' parcelas mensais (1ª de ' . mcp_brl($d['primeira']) . ' e ' . ($d['n'] - 1) . ' de ' . mcp_brl($d['parcela']) . ')';
}

/** "Pago por": PIX, "Crédito, final 1234" ou "Crédito em 10 parcelas mensais de R$ 35,43, final 1234" (spec 1.14). */
function mcp_email_pago_por(array $i): string
{
    if (($i['metodo'] ?? 'pix') === 'pix') {
        return 'PIX';
    }
    $final = trim((string) ($i['ultimos4'] ?? ''));
    $sufixo = $final !== '' ? ", final $final" : '';
    $n = mcp_email_parcelas($i);
    return $n > 1 ? 'Crédito em ' . mcp_email_parcelas_texto(mcp_email_divisao(mcp_email_total_pago_centavos($i), $n)) . $sufixo : 'Crédito' . $sufixo;
}

/** Juros do parcelamento: juros_centavos gravado, ou total cobrado − total sem juros. 0 à vista. */
function mcp_email_juros_centavos(array $i): int
{
    if (mcp_email_parcelas($i) <= 1) {
        return 0;
    }
    $juros = (int) ($i['juros_centavos'] ?? 0);
    return $juros > 0 ? $juros : max(0, mcp_email_total_pago_centavos($i) - (int) ($i['total_centavos'] ?? 0));
}

/** "27%", "2,21%" ($inteiroSemCasas) ou "4,60%". */
function mcp_email_pct(float $valor, bool $inteiroSemCasas = false): string
{
    $texto = number_format($valor, 2, ',', '.');
    return ($inteiroSemCasas ? (string) preg_replace('/,00$/', '', $texto) : $texto) . '%';
}

/** Acréscimo do parcelado sobre o valor à vista, como no checkout ("27%"). */
function mcp_email_acrescimo(array $i): string
{
    $amount = (int) ($i['total_centavos'] ?? 0);
    return $amount > 0 ? mcp_email_pct(mcp_email_juros_centavos($i) / $amount * 100, true) : '';
}

/**
 * A linha do crédito (F8), só no parcelado: parcelas, taxa ao mês e CET gravados na hora da cobrança (nunca
 * recalculados), preço à vista, total e quem concede. Texto puro.
 */
function mcp_email_linha_credito(array $i): string
{
    $n = mcp_email_parcelas($i);
    if ($n <= 1) {
        return '';
    }
    $d = mcp_email_divisao(mcp_email_total_pago_centavos($i), $n);
    $partes = ['Parcelamento: ' . mcp_email_parcelas_texto($d)];
    if (isset($i['taxa_mes_pct']) && $i['taxa_mes_pct'] !== null && $i['taxa_mes_pct'] !== '') {
        $partes[] = 'juros de ' . mcp_email_pct((float) $i['taxa_mes_pct']) . ' ao mês';
    }
    if (isset($i['cet_ano_pct']) && $i['cet_ano_pct'] !== null && $i['cet_ano_pct'] !== '') {
        $partes[] = 'CET de ' . mcp_email_pct((float) $i['cet_ano_pct']) . ' ao ano';
    }
    $partes[] = 'preço à vista ' . mcp_brl((int) ($i['total_centavos'] ?? 0));
    $partes[] = 'total ' . mcp_brl($d['total']);
    $partes[] = 'concedido por ' . mcp_email_financiador()['nome'];
    return implode(' · ', $partes);
}

/** "taxa R$ 99,00 + matrícula R$ 180,00 + custos R$ 4,95 + divulgação R$ 14,90 + juros R$ 75,33". */
function mcp_email_decomposicao(array $i): string
{
    $partes = ['taxa ' . mcp_brl((int) ($i['inscricao_centavos'] ?? 0))];
    if (mcp_email_completo($i)) {
        $partes[] = 'matrícula ' . mcp_brl(mcp_email_matricula_cobrada($i));
    }
    if ((int) ($i['taxa_centavos'] ?? 0) > 0) {
        $partes[] = 'custos ' . mcp_brl((int) $i['taxa_centavos']);
    }
    if ((int) ($i['divulgacao_centavos'] ?? 0) > 0) {
        $partes[] = 'divulgação ' . mcp_brl((int) $i['divulgacao_centavos']);
    }
    if (mcp_email_juros_centavos($i) > 0) {
        $partes[] = 'juros ' . mcp_brl(mcp_email_juros_centavos($i));
    }
    return implode(' + ', $partes);
}

// ----------------------------------------------------------------------------- pagar tudo: datas e turma
function mcp_email_fuso(): DateTimeZone
{
    static $fuso = null;
    return $fuso ??= new DateTimeZone('America/Sao_Paulo');
}

/**
 * Data e hora em Brasília de: um ISO com fuso ("2026-10-21T09:00:00-03:00"), um dia "AAAA-MM-DD" (meia-noite em
 * Brasília) ou um DATETIME do banco ($bancoUtc). null se vazio ou inválido.
 */
function mcp_email_quando(mixed $valor, bool $bancoUtc = false): ?DateTimeImmutable
{
    if (!is_string($valor) || trim($valor) === '') {
        return null;
    }
    try {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return new DateTimeImmutable($valor . ' 00:00:00', mcp_email_fuso());
        }
        return (new DateTimeImmutable($valor, $bancoUtc ? new DateTimeZone('UTC') : mcp_email_fuso()))->setTimezone(mcp_email_fuso());
    } catch (Throwable) {
        return null;
    }
}

/** "21 de outubro de 2026". */
function mcp_email_data_longa(DateTimeImmutable $d): string
{
    return (int) $d->format('j') . ' de ' . MCP_EMAIL_MESES[(int) $d->format('n') - 1] . ' de ' . $d->format('Y');
}

/** "terça, 20/10, às 23h59" ou, sem a hora, "terça, 20/10". */
function mcp_email_dia_semana(DateTimeImmutable $d, bool $comHora = true): string
{
    return MCP_EMAIL_SEMANA[(int) $d->format('w')] . ', ' . $d->format('d/m') . ($comHora ? ', às ' . $d->format('H\hi') : '');
}

/** O oferta.json (spec 2.5): de compra.php quando ele existe; senão, lido aqui (MCP_OFERTA_ARQUIVO nos testes). */
function mcp_email_oferta(): array
{
    if (function_exists('mcp_oferta')) {
        $oferta = mcp_oferta();
        return is_array($oferta) ? $oferta : [];
    }
    static $cache = [];
    $arquivo = getenv('MCP_OFERTA_ARQUIVO') ?: dirname(__DIR__, 2) . '/oferta.json';
    if (!array_key_exists($arquivo, $cache)) {
        $dados = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;
        $cache[$arquivo] = is_array($dados) ? $dados : [];
    }
    return $cache[$arquivo];
}

/** A turma do oferta.json pelo id da escola, ou null. */
function mcp_email_oferta_turma(?string $turmaId): ?array
{
    if ($turmaId === null || $turmaId === '') {
        return null;
    }
    foreach ((array) (mcp_email_oferta()['turmas'] ?? []) as $turma) {
        if (is_array($turma) && (string) ($turma['id_escola'] ?? '') === $turmaId) {
            return $turma;
        }
    }
    return null;
}

/** A turma à venda de um curso no oferta.json (prazo no futuro, não lotada), a que começa primeiro, ou null. */
function mcp_email_oferta_turma_do_curso(string $slug, ?int $agora = null): ?array
{
    $agora ??= time();
    $melhor = null;
    foreach ((array) (mcp_email_oferta()['turmas'] ?? []) as $turma) {
        if (!is_array($turma) || ($turma['curso'] ?? '') !== $slug || ($turma['lotada'] ?? false) === true) {
            continue;
        }
        $ate = mcp_email_quando($turma['inscricoes_ate'] ?? null);
        $inicio = mcp_email_quando($turma['inicio'] ?? null);
        if ($ate && $inicio && $ate->getTimestamp() > $agora && ($melhor === null || $inicio < $melhor[0])) {
            $melhor = [$inicio, $turma];
        }
    }
    return $melhor[1] ?? null;
}

/**
 * A turma da inscrição, com os rótulos dos e-mails. $daEscola: a data e o horário da resposta da escola (depois do
 * pagamento, spec 1.14); sem ela, a turma vendida (turma_inicio gravado e o oferta.json pelo turma_id). Só a taxa sem
 * turma gravada (inscrição feita antes do pagar tudo) cai na turma à venda do curso.
 * Devolve data ("21/10/2026"), longa ("21 de outubro de 2026"), dia ("21/10"), ymd, horario ("09:00 - 17:00" ou ''),
 * prazo ("terça, 20/10, às 23h59" ou '') e aberta (a turma vendida continua à venda).
 */
function mcp_email_turma_da_inscricao(array $i, bool $daEscola = true, ?int $agora = null): array
{
    $agora ??= time();
    $r = ['data' => '', 'longa' => '', 'dia' => '', 'ymd' => '', 'horario' => '', 'prazo' => '', 'aberta' => false];
    $oferta = mcp_email_oferta_turma(isset($i['turma_id']) ? (string) $i['turma_id'] : null);
    if ($oferta === null && empty($i['turma_id']) && !mcp_email_completo($i) && !empty($i['curso_slug'])) {
        $oferta = mcp_email_oferta_turma_do_curso((string) $i['curso_slug'], $agora);
    }
    $inicioOferta = $oferta ? mcp_email_quando($oferta['inicio'] ?? null) : null;
    $fimOferta = $oferta ? mcp_email_quando($oferta['fim'] ?? null) : null;
    $dia = null;
    $horario = '';
    $acesso = $daEscola ? mcp_escola_acesso($i) : null;
    if ($acesso) {
        foreach (['turma_primeira_aula', 'turma_inicio'] as $chave) {
            $valor = is_string($acesso[$chave] ?? null) ? substr($acesso[$chave], 0, 10) : null;
            if ($valor !== null && ($dia = mcp_email_quando($valor)) !== null) {
                break;
            }
        }
        $horario = is_string($acesso['turma_horario'] ?? null) ? trim($acesso['turma_horario']) : '';
    }
    $dia ??= mcp_email_quando($i['turma_inicio'] ?? null, true) ?? $inicioOferta;
    if ($dia !== null && $horario === '' && $inicioOferta && $fimOferta && $inicioOferta->format('Y-m-d') === $dia->format('Y-m-d')) {
        $horario = $inicioOferta->format('H:i') . ' - ' . $fimOferta->format('H:i');
    }
    if ($dia !== null) {
        $r = array_merge($r, ['data' => $dia->format('d/m/Y'), 'longa' => mcp_email_data_longa($dia), 'dia' => $dia->format('d/m'), 'ymd' => $dia->format('Y-m-d')]);
    }
    $r['horario'] = $horario;
    if ($oferta) {
        $ate = mcp_email_quando($oferta['inscricoes_ate'] ?? null);
        if ($ate) {
            $r['prazo'] = mcp_email_dia_semana($ate);
            $r['aberta'] = $ate->getTimestamp() > $agora && ($oferta['lotada'] ?? false) !== true;
        }
    }
    return $r;
}

/** "das 09:00 às 17:00" a partir de "09:00 - 17:00". */
function mcp_email_das(string $horario): string
{
    return 'das ' . str_replace(' - ', ' às ', $horario);
}

/** Compra do plano completo que espera ou esperou turma, ou que foi vendida sem turma (spec 10). */
function mcp_email_sem_turma(array $i): bool
{
    return mcp_email_completo($i) && (!empty($i['espera_status']) || !empty($i['espera_desde']) || empty($i['turma_id']));
}

/** A data limite da espera ("06/01/2027"), ou ''. */
function mcp_email_data_limite(array $i): string
{
    $d = mcp_email_quando($i['espera_prazo'] ?? null, true);
    return $d ? $d->format('d/m/Y') : '';
}

/** O último dia de marcar a turma: a data limite − 10 dias ("27/12/2026"), ou ''. */
function mcp_email_marcar_ate(array $i): string
{
    $d = mcp_email_quando($i['espera_prazo'] ?? null, true);
    return $d ? $d->modify('-10 days')->format('d/m/Y') : '';
}

function mcp_email_prazo_dias(): int
{
    return function_exists('mcp_espera_prazo_dias') ? mcp_espera_prazo_dias() : max(11, min(365, (int) mcp_cfg('ESPERA_PRAZO_DIAS', 90)));
}

/**
 * Fim da janela "a data ou o horário não servem" (P7): o dia mais cedo entre o envio + 7 dias e a véspera da primeira
 * aula, às 23h59min59s de Brasília. Devolve o DATETIME UTC que vai para espera_janela_ate.
 */
function mcp_email_janela_ate(string $primeiraAulaYmd, ?int $envio = null): string
{
    $fim = (new DateTimeImmutable('@' . ($envio ?? time())))->setTimezone(mcp_email_fuso())->modify('+7 days')->setTime(23, 59, 59);
    $aula = preg_match('/^\d{4}-\d{2}-\d{2}$/', $primeiraAulaYmd) ? mcp_email_quando($primeiraAulaYmd) : null;
    if ($aula !== null) {
        $vespera = $aula->modify('-1 day')->setTime(23, 59, 59);
        if ($vespera < $fim) {
            $fim = $vespera;
        }
    }
    return $fim->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** {prazo da data} ("terça, 20/10"): espera_janela_ate gravado, ou a conta da janela a partir de agora. */
function mcp_email_prazo_da_data(array $i, ?int $agora = null): string
{
    $ate = !empty($i['espera_janela_ate']) ? (string) $i['espera_janela_ate'] : mcp_email_janela_ate(mcp_email_turma_da_inscricao($i, true, $agora)['ymd'], $agora);
    $d = mcp_email_quando($ate, true);
    return $d ? mcp_email_dia_semana($d, false) : '';
}

/**
 * Os avisos da escola sobre a compra (escola_acesso.avisos e o aviso de antes). No plano completo, matricula_paga
 * diferente de true sem curso_ja_pago, matricula_paga_sem_turma ou turma_diferente vira matricula_nao_marcada (spec 3.2).
 */
function mcp_email_avisos(array $i): array
{
    $acesso = mcp_escola_acesso($i);
    if (!$acesso) {
        return [];
    }
    $avisos = [];
    if (function_exists('mcp_escola_avisos')) {
        $avisos = mcp_escola_avisos($i);
    } else {
        foreach (array_merge(is_array($acesso['avisos'] ?? null) ? $acesso['avisos'] : [], [$acesso['aviso'] ?? null]) as $aviso) {
            if (is_string($aviso) && $aviso !== '') {
                $avisos[] = $aviso;
            }
        }
    }
    if (mcp_email_completo($i) && ($acesso['matricula_paga'] ?? null) !== true
        && !array_intersect(['curso_ja_pago', 'matricula_paga_sem_turma', 'turma_diferente'], $avisos)) {
        $avisos[] = 'matricula_nao_marcada';
    }
    return array_values(array_unique($avisos));
}

/** A regra da desistência depois dos 7 dias com a turma confirmada (P4, versão B, padrão), em uma frase. */
function mcp_email_regra_p4_frase(): string
{
    return 'a matrícula volta, com os juros do parcelamento que correspondem a ela, e a taxa de inscrição fica com ' . mcp_email_recebedor()['nome'];
}

/** A regra da P4 inteira, para o Resumo das condições ("Desistência: até 7 dias…; depois, {regra}."). */
function mcp_email_regra_p4(): string
{
    return 'tudo volta se você desistir antes de a turma ser confirmada; com a turma confirmada, se você desistir antes da primeira aula, ' . mcp_email_regra_p4_frase();
}

/** O rodapé de cancelamento dos e-mails ao aluno (spec 1.14), com a frase da venda sem turma (10.10) quando vale. */
function mcp_email_texto_estorno(array $i = []): string
{
    // Só a taxa (ou sem inscrição): sem "inclusive a matrícula e os juros do parcelamento", que essa compra não tem.
    $base = $i && !mcp_email_completo($i) ? MCP_TEXTO_ESTORNO_SO_TAXA : MCP_TEXTO_ESTORNO;
    return $base . (mcp_email_ligada('PLANO_COMPLETO_SEM_TURMA') || !empty($i['espera_prazo']) ? ' ' . MCP_TEXTO_ESTORNO_SEM_TURMA : '');
}

/**
 * Resumo das condições (spec 1.12; sem turma, 10.4), o mesmo texto do checkout, do e-mail e do comprovante. Só no
 * plano completo; texto puro.
 */
function mcp_email_resumo_condicoes(array $i): string
{
    if (!mcp_email_completo($i)) {
        return '';
    }
    $curso = mcp_curso((string) ($i['curso_slug'] ?? '')) ?? [];
    $carga = trim((string) ($curso['carga_horaria'] ?? ''));
    $aulas = 'aulas presenciais' . ($carga !== '' ? " de $carga" : '');
    // Bombeiro Civil: a frase literal da escola (decisão 12).
    $homologacao = ($i['curso_slug'] ?? '') === 'bombeiro-civil' ? ' Não inclui a homologação: somente no final do curso, valor a consultar, a cargo do aluno.' : '';
    $desistencia = 'Desistência: até 7 dias depois do pagamento, tudo de volta; depois, ' . mcp_email_regra_p4() . '.';
    if (mcp_email_sem_turma($i) && (($i['espera_status'] ?? '') !== 'turma')) {
        $limite = mcp_email_data_limite($i);
        $ateLimite = $limite !== '' ? "até $limite" : 'em até ' . mcp_email_prazo_dias() . ' dias da inscrição';
        return "Inclui: $aulas na primeira turma deste curso que tiver vaga, na Praça da Cruz Vermelha, 10, e o certificado para quem cumprir os requisitos do curso.$homologacao"
            . ' Ainda não há turma marcada. Quando a Escola marcar uma, você entra nela, por ordem de pagamento, e recebe por e-mail a data, o horário e o local, pelo menos 10 dias antes da primeira aula.'
            . ' Se a data ou o horário não servirem, avise em até 7 dias depois desse e-mail e você recebe tudo de volta.'
            . ' Se você desistir antes de a turma ser confirmada (você recebe um e-mail quando ela for), também recebe tudo de volta.'
            . " Se não houver turma marcada para começar $ateLimite, devolvemos tudo, sem você precisar pedir."
            . ' Se a turma for cancelada ou mudar de data, você escolhe outra turma ou recebe tudo de volta.'
            . ' Se a Escola não puder aceitar a sua matrícula por um requisito que não estava nesta página, devolvemos tudo.'
            . ' "Tudo" é a taxa de inscrição, a matrícula, os juros do parcelamento e os opcionais.'
            . ' No PIX, a devolução feita mais de 90 dias depois do pagamento é uma transferência para uma conta no seu nome. '
            . $desistencia;
    }
    $turma = mcp_email_turma_da_inscricao($i, true);
    $naTurma = $turma['data'] !== '' ? 'na turma de ' . $turma['data'] . ($turma['horario'] !== '' ? ', ' . mcp_email_das($turma['horario']) : '') : 'na turma escolhida';
    return "Inclui: $aulas $naTurma, na Praça da Cruz Vermelha, 10, e o certificado para quem cumprir os requisitos do curso.$homologacao"
        . ' A turma precisa de um número mínimo de alunos; se não for formada ou for adiada, você escolhe outra data ou recebe tudo de volta. '
        . $desistencia;
}

/** Quanto volta da taxa paga em dobro, com os juros proporcionais: taxa × total cobrado ÷ amount (spec 2.6). */
function mcp_email_taxa_em_dobro_centavos(array $i): int
{
    $amount = (int) ($i['total_centavos'] ?? 0);
    $taxa = (int) ($i['inscricao_centavos'] ?? 0);
    return $amount > 0 ? (int) round($taxa * mcp_email_total_pago_centavos($i) / $amount) : $taxa;
}

/** Posição da compra na fila do curso (ordem de pagamento), ou null se não der para contar. */
function mcp_email_posicao_fila(array $i): ?int
{
    if (($i['espera_status'] ?? '') !== 'aguardando' || empty($i['pago_em'])) {
        return null;
    }
    try {
        $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_inscricoes WHERE status = 'pago' AND plano = 'taxa_e_matricula' AND espera_status = 'aguardando'
            AND curso_slug = ? AND (pago_em < ? OR (pago_em = ? AND id <= ?))");
        $stmt->execute([(string) $i['curso_slug'], (string) $i['pago_em'], (string) $i['pago_em'], (int) $i['id']]);
        return max(1, (int) $stmt->fetchColumn());
    } catch (Throwable $e) {
        error_log('[matricula] posição na fila não calculada: ' . $e->getMessage());
        return null;
    }
}

// ----------------------------------------------------------------------------- blocos com versão em texto
/** Texto puro de um trecho de HTML dos e-mails. */
function mcp_email_html_texto(string $html): string
{
    $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/** Cada bloco é [html, texto]; mcp_email_juntar devolve ['html', 'texto']. */
function mcp_email_b_p(string $html): array
{
    return [mcp_p($html), mcp_email_html_texto($html)];
}

function mcp_email_b_nota(string $html): array
{
    return [mcp_nota($html), mcp_email_html_texto($html)];
}

function mcp_email_b_subtitulo(string $texto): array
{
    return [mcp_subtitulo($texto), mb_strtoupper($texto)];
}

function mcp_email_b_passos(array $passos): array
{
    $texto = [];
    foreach (array_values($passos) as $n => [$titulo, $html]) {
        $texto[] = ($n + 1) . ') ' . $titulo . ': ' . mcp_email_html_texto($html);
    }
    return [mcp_passos($passos), implode("\n", $texto)];
}

/** Caixa de valores e a versão em texto ("Rótulo: valor"). $rodape: texto puro, numa linha inteira depois do total. */
function mcp_email_b_caixa(array $linhas, array $total = [], string $rodape = ''): array
{
    $texto = [];
    foreach ($linhas + $total as $rotulo => $valor) {
        $texto[] = "$rotulo: " . (is_array($valor) ? mcp_email_html_texto((string) ($valor['html'] ?? '')) : $valor);
    }
    if ($rodape !== '') {
        $texto[] = $rodape;
    }
    return [mcp_caixa($linhas, $total, $rodape !== '' ? mcp_escapar($rodape) : ''), implode("\n", $texto)];
}

function mcp_email_b_botao(string $url, string $rotulo, bool $secundario = false): array
{
    return [mcp_botao($url, $rotulo, $secundario), "$rotulo: $url"];
}

/** Quadro rosa de destaque (o "Falta pagar a matrícula." e o "A data ou o horário não servem?"). */
function mcp_email_b_destaque(string $titulo, string $html): array
{
    return ['<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#fff7f7" style="margin:18px 0;background:#fff7f7;border:1.5px solid #cc0000;border-radius:12px"><tr><td style="padding:16px 20px">'
        . '<p style="margin:0 0 6px;font-size:17px;line-height:1.3;color:#0f1318;font-weight:800">' . mcp_escapar($titulo) . '</p>'
        . '<p style="margin:0;font-size:15px;line-height:1.55;color:#1a202c">' . $html . '</p></td></tr></table>',
        mb_strtoupper($titulo) . ' ' . mcp_email_html_texto($html)];
}

/** Bloco de HTML pronto, com o texto dado. */
function mcp_email_b_html(string $html, string $texto): array
{
    return [$html, $texto];
}

function mcp_email_juntar(array $blocos): array
{
    $blocos = array_values(array_filter($blocos, static fn($b): bool => is_array($b) && isset($b[0], $b[1]) && ($b[0] !== '' || $b[1] !== '')));
    return [
        'html' => implode('', array_column($blocos, 0)),
        'texto' => trim(implode("\n\n", array_filter(array_column($blocos, 1), static fn(string $t): bool => $t !== ''))),
    ];
}

/** As duas linhas da venda sem turma (spec 10.6, F12): "Acompanhe sua inscrição" e, quando houver, o descritor da fatura. */
function mcp_email_b_acompanhe(array $i): array
{
    $link = mcp_url_pagina('parabens', (string) ($i['token'] ?? ''));
    $descritor = trim((string) mcp_cfg('DESCRITOR_FATURA', ''));
    return mcp_email_b_html(
        mcp_nota('Acompanhe sua inscrição: <a href="' . mcp_escapar($link) . '" style="color:#cc0000">' . mcp_escapar($link) . '</a>')
            . ($descritor !== '' ? mcp_nota('Na fatura ou no extrato, a compra aparece como <strong>' . mcp_escapar($descritor) . '</strong>.') : ''),
        "Acompanhe sua inscrição: $link" . ($descritor !== '' ? "\nNa fatura ou no extrato, a compra aparece como $descritor." : '')
    );
}

/** Opções da moldura dos e-mails ao aluno: chapéu, prévia, motivo e a linha do fornecedor no rodapé (F2). */
function mcp_email_opcoes_aluno(string $preheader, string $motivo = 'Você recebeu este e-mail porque pagou uma inscrição em cruzvermelhariodejaneiro.org com este endereço.'): array
{
    return ['eyebrow' => 'Cursos presenciais', 'preheader' => $preheader, 'motivo' => $motivo, 'fornecedor' => true];
}

/** Opções da moldura dos avisos internos. */
function mcp_email_opcoes_secretaria(): array
{
    return ['eyebrow' => 'Aviso interno · matrícula cursos presenciais', 'motivo' => 'Aviso automático do checkout de cruzvermelhariodejaneiro.org para a secretaria.'];
}

// ----------------------------------------------------------------------------- PIX aberto (recuperação)
/** Vencimento do código: 24 h depois de criado, em horário de Brasília. */
function mcp_pix_validade(array $inscricao): string
{
    $criado = (string) ($inscricao['criado_em'] ?? '');
    if ($criado === '') {
        return '';
    }
    try {
        $fim = (new DateTimeImmutable($criado, new DateTimeZone('UTC')))->modify('+24 hours');
        return mcp_data_brt($fim->format('Y-m-d H:i:s'));
    } catch (Throwable) {
        return '';
    }
}

/** Linhas da caixa do PIX: curso, matrícula (opção 1), taxa e os opcionais. */
function mcp_email_linhas_pix(array $i): array
{
    $linhas = ['Curso' => (string) $i['curso_nome']];
    if (mcp_email_completo($i)) {
        $linhas['Valor da matrícula'] = mcp_brl(mcp_email_matricula_cobrada($i));
    }
    $linhas['Taxa de inscrição'] = mcp_brl((int) $i['inscricao_centavos']);
    if ((int) ($i['taxa_centavos'] ?? 0) > 0) {
        $linhas['Custos de processamento (você escolheu cobrir)'] = mcp_brl((int) $i['taxa_centavos']);
    }
    if ((int) ($i['divulgacao_centavos'] ?? 0) > 0) {
        $linhas['Contribuição para a divulgação (você escolheu ajudar)'] = mcp_brl((int) $i['divulgacao_centavos']);
    }
    return $linhas;
}

/** "Pague até terça, 20/10, às 23h59, para entrar na turma de 21/10." sempre que houver turma (T8), ou ''. */
function mcp_email_pague_ate(array $i): string
{
    if (mcp_email_completo($i) && empty($i['turma_id'])) {
        return '';
    }
    $turma = mcp_email_turma_da_inscricao($i, false);
    return $turma['prazo'] !== '' && $turma['dia'] !== '' ? "Pague até {$turma['prazo']}, para entrar na turma de {$turma['dia']}." : '';
}

/** O certificado, último passo de todos os e-mails do PIX. */
function mcp_email_passo_certificado(): array
{
    return ['Você conclui e recebe o certificado', 'O certificado da Cruz Vermelha Brasileira Rio de Janeiro, com o seu nome, o curso e a carga horária.'];
}

/**
 * Passos do PIX (aberto e lembrete) por plano (spec 1.14 e 10.6): opção 1 com turma, opção 1 sem turma e só a taxa.
 * $primeiro: o passo 1 de cada e-mail.
 */
function mcp_email_passos_pix(array $i, array $primeiro): array
{
    if (mcp_email_completo($i) && empty($i['turma_id'])) {
        $limite = mcp_email_data_limite($i);
        return [
            $primeiro,
            ['Fila da próxima turma', 'Quando a Escola marcar a turma, você entra na primeira que tiver vaga, já com tudo pago, e recebe a data por e-mail.'],
            ['Se a data ou o horário não servirem', 'Avise em até 7 dias depois do e-mail com a data e você recebe tudo de volta.' . ($limite !== '' ? ' Se não houver turma marcada para começar até <strong>' . mcp_escapar($limite) . '</strong>, também.' : '')],
            mcp_email_passo_certificado(),
        ];
    }
    if (mcp_email_completo($i)) {
        $turma = mcp_email_turma_da_inscricao($i, false);
        return [
            $primeiro,
            ['Matrícula confirmada', 'Você entra na turma' . ($turma['data'] !== '' ? ' de <strong>' . mcp_escapar($turma['data']) . '</strong>' : '') . ' com a inscrição e a matrícula pagas.'],
            ['Receba as orientações da Escola', MCP_EMAIL_PASSO_03],
            mcp_email_passo_certificado(),
        ];
    }
    $matricula = mcp_email_matricula($i);
    return [
        $primeiro,
        mcp_email_passo_escola($i),
        ['Depois, a matrícula', ($matricula !== '' ? mcp_escapar($matricula) . ' à vista' : 'À vista') . ', paga antes da aula; a secretaria da Escola orienta como pagar. A participação nas aulas é liberada só com a matrícula paga, além da taxa de inscrição.'],
        mcp_email_passo_certificado(),
    ];
}

/**
 * E-mail de recuperação: sai no momento em que o PIX é gerado e é o caminho de volta de quem fechou a página.
 * Três versões (spec 1.14 e 10.6): taxa + matrícula com turma, taxa + matrícula sem turma (a fila) e só a taxa.
 * Puro: devolve assunto, html e texto.
 */
function mcp_montar_email_pix_aberto(array $inscricao): array
{
    $nome = mcp_primeiro_nome((string) $inscricao['nome']);
    $curso = (string) $inscricao['curso_nome'];
    $total = mcp_brl((int) $inscricao['total_centavos']);
    $completo = mcp_email_completo($inscricao);
    $semTurma = $completo && empty($inscricao['turma_id']);
    $codigo = (string) ($inscricao['pix_copia_cola'] ?? '');
    $link = mcp_url_pagina('pendente', (string) $inscricao['token']) . '&utm_source=email&utm_medium=transacional&utm_campaign=pix-aberto';
    $validade = mcp_pix_validade($inscricao);
    $ateQuando = $validade !== '' ? " (até $validade)" : '';
    $pagueAte = mcp_email_pague_ate($inscricao);

    $abertura = match (true) {
        $semTurma => 'Oi, ' . mcp_escapar($nome) . '. Sua inscrição e matrícula em <strong>' . mcp_escapar($curso) . '</strong> estão abertas: só falta o pagamento do PIX. Assim que ele cair, você entra na fila da próxima turma deste curso, por ordem de pagamento, e recebe a confirmação por e-mail, na hora.',
        $completo => 'Oi, ' . mcp_escapar($nome) . '. Sua inscrição e matrícula em <strong>' . mcp_escapar($curso) . '</strong> já estão abertas: só falta o pagamento do PIX para concluir. Assim que ele cair, a matrícula é confirmada e você recebe a confirmação por e-mail, na hora.',
        default => 'Oi, ' . mcp_escapar($nome) . '. Sua inscrição em <strong>' . mcp_escapar($curso) . '</strong> já está aberta: só falta o pagamento do PIX da taxa de inscrição para concluir. Assim que ele cair, você recebe a confirmação por e-mail, na hora.',
    };
    $c = mcp_email_juntar([
        mcp_email_b_p($abertura),
        mcp_email_b_caixa(mcp_email_linhas_pix($inscricao), ['Total do PIX' => $total]),
        mcp_email_b_botao($link, 'Concluir pagamento'),
        mcp_email_b_nota('Pelo botão você vê o QR code e acompanha a confirmação em tempo real. Ou pague agora com o código:'),
        mcp_email_b_html(mcp_bloco_pix($codigo), "PIX copia e cola:\n$codigo"),
        mcp_email_b_p('<strong>O código vale por 24 horas' . mcp_escapar($ateQuando) . '.</strong> Passou do prazo? Gere outro pelo mesmo botão, sem custo.'),
        $pagueAte !== '' ? mcp_email_b_p('<strong>' . mcp_escapar($pagueAte) . '</strong>') : [],
        mcp_email_b_subtitulo('O que acontece depois'),
        mcp_email_b_passos(mcp_email_passos_pix($inscricao, ['Pagamento confirmado na hora', 'O comprovante chega neste e-mail.'])),
        mcp_email_b_html(mcp_email_bloco_certificado((string) $inscricao['curso_slug'], $curso), MCP_CERT_PESO),
        $semTurma ? mcp_email_b_acompanhe($inscricao) : [],
        mcp_email_b_nota(mcp_escapar(mcp_email_texto_estorno($inscricao))),
    ]);
    $preheader = match (true) {
        $semTurma => "Seu código de $total vale por 24 horas. Assim que o PIX cair, você entra na fila da próxima turma.",
        $completo => "Seu código de $total vale por 24 horas. Matrícula confirmada assim que o PIX cair.",
        default => "Seu código de $total vale por 24 horas. Pagamento confirmado na hora, comprovante por e-mail.",
    };
    return [
        'assunto' => $completo ? "Falta só o PIX para concluir sua inscrição e matrícula em $curso" : "Falta só o PIX para concluir sua inscrição em $curso",
        'html' => mcp_moldura("Falta só o PIX, $nome.", $c['html'], mcp_email_opcoes_aluno($preheader,
            'Você recebeu este e-mail porque iniciou uma inscrição em cruzvermelhariodejaneiro.org com este endereço.')),
        'texto' => $c['texto'] . "\n\nDúvidas? Responda este e-mail ou escreva para " . mcp_email_contato_endereco() . '.',
    ];
}

function mcp_email_pix_aberto(array $inscricao): void
{
    if (empty($inscricao['pix_copia_cola'])) {
        return;
    }
    $m = mcp_montar_email_pix_aberto($inscricao);
    $r = mcp_enviar_email((string) $inscricao['email'], $m['assunto'], $m['html'], $m['texto']);
    mcp_registrar((int) $inscricao['id'], 'email_pix', $r);
}

// ----------------------------------------------------------------------------- inscrição paga
/**
 * Convite para o questionário de dias e horários (tela horarios/), no e-mail de inscrição paga: um quadro
 * "Responda em 30 segundos" logo depois do principal. $principal: botão cheio (versão B, em que é a única ação do
 * aluno); na versão A o botão cheio é o de criar a senha, e este fica contornado.
 */
function mcp_email_bloco_horarios(array $inscricao, bool $comTurma, bool $principal = false): array
{
    $url = mcp_url_pagina('horarios', (string) $inscricao['token']);
    // Quem pagou tudo sem turma entra pela ordem de pagamento: os horários ajudam a Escola a marcar turmas, não a escolher a de cada um.
    $espera = mcp_email_completo($inscricao) && empty($inscricao['turma_id']);
    $frase = $espera
        ? 'Toque nos dias e horários em que você consegue vir. Leva 30 segundos e ajuda a Escola a marcar turmas em horários que sirvam a quem está na fila.'
        : 'Toque nos dias e horários em que você consegue vir. Leva 30 segundos e ajuda a secretaria a encaixar você na turma certa'
            . ($comTurma ? ' ou a achar outra data, se a da sua turma não servir.' : '.');
    return [
        'html' => '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#fff7f7" style="margin:22px 0;background:#fff7f7;border:1.5px solid #cc0000;border-radius:12px"><tr><td style="padding:18px 20px 2px">'
            . '<p style="margin:0 0 6px;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#cc0000;font-weight:800">Responda em 30 segundos</p>'
            . '<p style="margin:0 0 8px;font-size:18px;line-height:1.3;color:#0f1318;font-weight:800">Quando você pode vir?</p>'
            . '<p style="margin:0;font-size:15px;line-height:1.55;color:#1a202c">' . mcp_escapar($frase) . '</p>'
            . mcp_botao($url, 'Escolher meus horários', !$principal) . '</td></tr></table>',
        'texto' => "RESPONDA EM 30 SEGUNDOS: quando você pode vir? $frase\nEscolher meus horários: $url",
    ];
}

/**
 * O que os e-mails de pagamento confirmado têm em comum: o acesso à área do aluno (conta nova com link, conta nova
 * sem link ou conta de sempre), o agradecimento pelos opcionais e a frase do comprovante.
 */
function mcp_email_contexto_pago(array $i, bool $comComprovante): array
{
    $acesso = mcp_escola_acesso($i);
    $escolaUrl = (string) mcp_cfg('ESCOLA_URL', 'https://escola.cursoscruzvermelha.org');
    $novo = $acesso && !empty($acesso['aluno_novo']);
    $link = $novo ? mcp_escola_link($i) : null;
    $validade = $link ? mcp_escola_link_validade($i) : '';
    $emailConta = (string) ($i['email'] ?? '');
    $outroEmail = $acesso && !$novo && empty($acesso['email_confere']) && !empty($acesso['email_conta']) ? (string) $acesso['email_conta'] : '';
    $taxa = (int) ($i['taxa_centavos'] ?? 0);
    $divulgacao = (int) ($i['divulgacao_centavos'] ?? 0);
    $entrada = $novo
        ? ($link
            ? 'Sua conta foi criada com o seu CPF. Para entrar pela primeira vez, <strong>crie sua senha no botão abaixo</strong>'
              . ($validade !== '' ? ' (o link vale até ' . mcp_escapar($validade) . ')' : '') . '. Depois é só entrar com seu <strong>CPF</strong> ou e-mail e a senha que você criou.'
            : 'Sua conta foi criada com o seu CPF. Para entrar pela primeira vez, use <strong>"Esqueci minha senha"</strong> na tela de entrada, com o e-mail <strong>'
              . mcp_escapar($emailConta) . '</strong>: você recebe um link para criar sua senha.')
        : 'Você já tinha conta na área do aluno' . ($outroEmail !== '' ? ', com o e-mail <strong>' . mcp_escapar($outroEmail) . '</strong>' : '')
          . '. Entre com seu <strong>CPF</strong> ou e-mail e a senha de sempre. Esqueceu? Use "Esqueci minha senha" na tela de entrada.';
    return [
        'acesso' => $acesso,
        'novo' => $novo,
        'link' => $link,
        'url_login' => $acesso ? (string) ($acesso['url_login'] ?? rtrim($escolaUrl, '/') . '/login') : rtrim($escolaUrl, '/') . '/login',
        'escola_url' => $escolaUrl,
        'entrada' => $acesso ? mcp_email_b_html(mcp_subtitulo('Seu acesso à área do aluno')
            . '<div style="margin:12px 0 18px;padding:14px 18px;background:#f7f8fa;border:1px solid #e2e8f0;border-radius:12px;font-size:15px;line-height:1.6">' . $entrada . '</div>',
            "SEU ACESSO À ÁREA DO ALUNO\n" . mcp_email_html_texto($entrada)) : [],
        'obrigado' => match (true) {
            $taxa > 0 && $divulgacao > 0 => ' Obrigado por cobrir os custos de processamento e por ajudar a divulgar os cursos.',
            $taxa > 0 => ' Obrigado por cobrir os custos de processamento: assim a Cruz Vermelha recebe a taxa de inscrição inteira.',
            $divulgacao > 0 => ' Obrigado por ajudar a divulgar os cursos: assim eles chegam a mais pessoas.',
            default => '',
        },
        'comprovante' => $comComprovante
            ? (mcp_email_completo($i) ? ' O comprovante de inscrição e matrícula em PDF vai anexado a este e-mail.' : ' O comprovante de inscrição em PDF vai anexado a este e-mail.')
            : '',
    ];
}

/**
 * Botões do acesso. $modo: 'senha' (com link, "Criar minha senha"; sem link, nada), 'inscricoes' ("Ver minhas
 * inscrições"; com link, "Criar minha senha" antes) e 'senha_e_inscricoes' (com link, "Criar minha senha" e "Já criou a
 * senha? Ver minhas inscrições"; sem link, "Ver minhas inscrições").
 */
function mcp_email_b_botoes_acesso(array $ctx, string $modo): array
{
    $url = $ctx['url_login'];
    if ($ctx['link']) {
        $criar = mcp_email_b_botao($ctx['link'], 'Criar minha senha');
        return match ($modo) {
            'senha' => $criar,
            'inscricoes' => mcp_email_bloco(mcp_email_juntar([$criar, mcp_email_b_botao($url, 'Ver minhas inscrições', true)])),
            default => mcp_email_bloco(mcp_email_juntar([$criar, mcp_email_b_p('Já criou a senha? <a href="' . mcp_escapar($url) . '" style="color:#cc0000">Ver minhas inscrições</a>.')])),
        };
    }
    return $modo === 'senha' ? [] : mcp_email_b_botao($url, 'Ver minhas inscrições');
}

/** Converte o retorno de mcp_email_juntar num bloco [html, texto]. */
function mcp_email_bloco(array $juntado): array
{
    return [(string) ($juntado['html'] ?? ''), (string) ($juntado['texto'] ?? '')];
}

/** Caixa do pagamento confirmado (spec 1.14): valores, pago por, data, total pago e, no parcelado, a linha do crédito. */
function mcp_email_b_caixa_paga(array $i, array $extra = []): array
{
    $linhas = mcp_email_linhas_pix($i);
    $juros = mcp_email_juros_centavos($i);
    if ($juros > 0) {
        $linhas['Juros do parcelamento (acréscimo de ' . mcp_email_acrescimo($i) . ' sobre o valor à vista)'] = mcp_brl($juros);
    }
    $linhas['Pago por'] = mcp_email_pago_por($i);
    $quando = mcp_data_brt((string) ($i['pago_em'] ?? '') ?: (string) ($i['criado_em'] ?? ''), 'd/m/Y \à\s H\hi');
    if ($quando !== '') {
        $linhas['Data'] = $quando . ' (Brasília)';
    }
    return mcp_email_b_caixa($linhas + $extra, ['Total pago' => mcp_brl(mcp_email_total_pago_centavos($i))], mcp_email_linha_credito($i));
}

/** Resumo das condições e, no parcelado, a nota da área do aluno (F14, F20), depois da caixa da opção 1. */
function mcp_email_b_condicoes(array $i, bool $notaAreaDoAluno = true): array
{
    $resumo = mcp_email_resumo_condicoes($i);
    $blocos = $resumo !== '' ? [mcp_email_b_subtitulo('Resumo das condições'), mcp_email_b_nota(mcp_escapar($resumo))] : [];
    if ($notaAreaDoAluno && mcp_email_parcelas($i) > 1) {
        // A escola grava taxa + matrícula, sem juros e sem os opcionais (v_valor + v_valor_matricula).
        $naEscola = (int) ($i['inscricao_centavos'] ?? 0) + (int) ($i['matricula_centavos'] ?? 0);
        $blocos[] = mcp_email_b_nota('Na área do aluno, o pagamento aparece como "À vista", no valor de ' . mcp_brl($naEscola)
            . ' (taxa de inscrição + matrícula, sem juros). Os juros do parcelamento aparecem só na fatura do seu cartão.');
    }
    return mcp_email_bloco(mcp_email_juntar($blocos));
}

/** "Cobramos R$ X a mais que o valor mostrado…" (spec 2.4), quando o cartão cobrou mais que o total mostrado. */
function mcp_email_b_diferenca(array $i): array
{
    $diferenca = (int) ($i['diferenca_devolver_centavos'] ?? 0);
    return $diferenca > 0 ? mcp_email_b_p('Cobramos <strong>' . mcp_brl($diferenca) . '</strong> a mais que o valor mostrado. A diferença volta por PIX em até 2 dias úteis.') : [];
}

/**
 * Pagamento confirmado. Taxa + matrícula: as variantes da spec 1.14 e 10.6 (paga, acima da vaga, turma diferente, já
 * paga, não marcada, na fila da próxima turma). Só a taxa: com turma, sem turma, taxa já paga e a versão B (sem a
 * escola). Devolve também 'tipo' ('acesso' quando a escola respondeu, 'confirmacao' sem resposta) para o registro: com
 * 'confirmacao', a retentativa da escola manda de novo o e-mail com o acesso.
 * $comComprovante: o PDF foi gerado e vai anexado. Sem ele o texto não cita anexo — o e-mail nunca promete um arquivo
 * que não está lá.
 */
function mcp_montar_email_aluno_pago(array $inscricao, bool $comComprovante = true): array
{
    return mcp_email_completo($inscricao)
        ? mcp_montar_email_aluno_pago_completo($inscricao, $comComprovante)
        : mcp_montar_email_aluno_pago_taxa($inscricao, $comComprovante);
}

/** A variante do e-mail de pagamento confirmado da opção 1 (taxa + matrícula). */
function mcp_email_variante_completo(array $i): string
{
    $acesso = mcp_escola_acesso($i);
    $avisos = mcp_email_avisos($i);
    $espera = (string) ($i['espera_status'] ?? '');
    return match (true) {
        in_array('curso_ja_pago', $avisos, true) => 'curso_ja_pago',
        $espera === 'turma' && $acesso && ($acesso['matricula_paga'] ?? null) === true => 'paga',
        $espera === 'aguardando' || ($espera === '' && in_array('matricula_paga_sem_turma', $avisos, true)) => !empty($i['turma_id']) ? 'espera_fechou' : 'espera',
        !$acesso || in_array('matricula_nao_marcada', $avisos, true) => 'nao_marcada',
        in_array('turma_diferente', $avisos, true) => 'turma_diferente',
        // turma_lotada (P3, decisão 14): quem pagou tudo entra acima da vaga e lê "Matrícula confirmada", sem nota.
        default => 'paga',
    };
}

function mcp_montar_email_aluno_pago_completo(array $i, bool $comComprovante): array
{
    $nome = mcp_primeiro_nome((string) $i['nome']);
    $n = mcp_escapar($nome);
    $curso = (string) $i['curso_nome'];
    $c = '<strong>' . mcp_escapar($curso) . '</strong>';
    $total = mcp_brl(mcp_email_total_pago_centavos($i));
    $ctx = mcp_email_contexto_pago($i, $comComprovante);
    $avisos = mcp_email_avisos($i);
    $variante = mcp_email_variante_completo($i);
    $turma = mcp_email_turma_da_inscricao($i, true);
    $vendida = mcp_email_turma_da_inscricao($i, false);
    $data = $turma['data'] !== '' ? '<strong>' . mcp_escapar($turma['data']) . '</strong>' : '';
    $recebemos = 'Parabéns, ' . $n . '. Recebemos o pagamento da inscrição e da matrícula em ' . $c . '.';
    $fecho = $ctx['obrigado'] . $ctx['comprovante'];
    $passoVenha = ['Venha para a aula', ($data !== '' ? $data . ', ' : '') . ($turma['horario'] !== '' ? mcp_escapar($turma['horario']) . ', ' : '') . 'na ' . MCP_EMAIL_LOCAL . '.'];
    $passoVenha[1] = mb_strtoupper(mb_substr($passoVenha[1], 0, 1)) . mb_substr($passoVenha[1], 1);
    $passoOrientacoes = ['Receba as orientações da Escola', MCP_EMAIL_PASSO_03];
    $limite = mcp_email_data_limite($i);
    $preNovo = $ctx['novo'] ? ' Crie sua senha na área do aluno.' : '';
    $comAcessoHorarios = [$ctx['entrada']];
    $horarios = mcp_email_bloco_horarios($i, $turma['data'] !== '');
    $blocoHorarios = [$horarios['html'], $horarios['texto']];

    switch ($variante) {
        case 'curso_ja_pago':
            $assunto = "Sua matrícula em $curso já estava paga";
            $titulo = "Sua matrícula já estava paga, $nome.";
            $preheader = "Este pagamento de $total será devolvido por inteiro.";
            $blocos = [
                mcp_email_b_p('Recebemos este pagamento, mas a sua matrícula em ' . $c . ' já estava paga na área do aluno. Este pagamento será devolvido por inteiro: a secretaria avisa por e-mail quando a devolução for feita. Não pague de novo.'),
                mcp_email_b_caixa_paga($i),
                mcp_email_b_botoes_acesso($ctx, 'inscricoes'),
            ];
            break;
        case 'nao_marcada':
            $assunto = "Pagamento confirmado em $curso";
            $titulo = "Pagamento confirmado, $nome.";
            $preheader = "Pagamento de $total confirmado. A secretaria conclui o registro da matrícula.";
            $blocos = array_merge([
                mcp_email_b_p($recebemos . ' A secretaria está concluindo o registro da sua matrícula na área do aluno e confirma por e-mail. Se a área do aluno mostrar a matrícula como pendente, não pague de novo: ela já está paga.' . $fecho),
                mcp_email_b_diferenca($i),
                mcp_email_b_caixa_paga($i),
                mcp_email_b_condicoes($i),
            ], $comAcessoHorarios, [mcp_email_b_botoes_acesso($ctx, 'inscricoes')]);
            break;
        case 'turma_diferente':
            $assunto = "Pagamento confirmado em $curso: confira a data da sua turma";
            $titulo = "Pagamento confirmado, $nome.";
            $preheader = 'Sua turma é a de ' . ($turma['data'] !== '' ? $turma['data'] : 'outra data') . '.';
            $blocos = array_merge([
                mcp_email_b_p($recebemos . ' A turma' . ($vendida['data'] !== '' ? ' de <strong>' . mcp_escapar($vendida['data']) . '</strong>' : ' anunciada')
                    . ' lotou ou fechou antes da confirmação do seu pagamento, e reservamos para você a turma' . ($data !== '' ? ' de ' . $data : ' seguinte')
                    . '. Se a data não servir, responda este e-mail ou peça pelo chat a devolução de tudo o que pagou.' . $fecho),
                mcp_email_b_diferenca($i),
                mcp_email_b_caixa_paga($i),
                mcp_email_b_condicoes($i),
            ], $comAcessoHorarios, [
                mcp_email_b_botoes_acesso($ctx, 'senha_e_inscricoes'),
                $blocoHorarios,
                mcp_email_b_subtitulo('Próximos passos'),
                mcp_email_b_passos([$passoOrientacoes, $passoVenha]),
            ]);
            break;
        case 'espera':
        case 'espera_fechou':
            $fechou = $variante === 'espera_fechou';
            $dataVendida = $vendida['data'] !== '' ? $vendida['data'] : '';
            $assunto = $fechou ? "Pagamento confirmado em $curso: você está na fila da próxima turma" : "Inscrição e matrícula pagas em $curso: você está na fila da próxima turma";
            $titulo = $fechou ? "Pagamento confirmado, $nome." : "Tudo pago, $nome!";
            $preheader = $fechou
                ? 'A turma' . ($dataVendida !== '' ? " de $dataVendida" : '') . ' fechou antes do seu pagamento. Você entra na próxima que tiver vaga, com tudo pago.'
                : "Pagamento de $total confirmado. A data da turma chega por e-mail." . $preNovo;
            $abertura = $fechou
                ? 'Recebemos o pagamento da inscrição e da matrícula em ' . $c . ', mas as inscrições da turma' . ($dataVendida !== '' ? ' de ' . mcp_escapar($dataVendida) : '')
                    . ' fecharam antes da confirmação do seu pagamento. Você fica na fila da próxima turma, com tudo pago: quando ela for marcada, você entra na primeira que tiver vaga e recebe a data por e-mail. Se preferir, peça pelo chat a devolução de tudo o que pagou.'
                    . ($limite !== '' ? ' Se não houver turma marcada para começar até ' . mcp_escapar($limite) . ', devolvemos tudo, sem você precisar pedir.' : '')
                : $recebemos . ' Ainda não há turma marcada: quando a Escola marcar uma, você entra na primeira que tiver vaga, por ordem de pagamento, e recebe por e-mail a data, o horário e o local.';
            $passos = [
                ['A data, por e-mail', 'Quando a turma for marcada, você recebe a data, o horário e o local, pelo menos 10 dias antes da primeira aula. Se a data ou o horário não servirem, avise em até 7 dias: devolvemos tudo o que você pagou.'],
            ];
            if ($limite !== '') {
                $passos[] = ['Data limite', 'Se não houver turma marcada para começar até <strong>' . mcp_escapar($limite) . '</strong>, devolvemos tudo, sem você precisar pedir.'];
            }
            if (!$fechou) {
                array_unshift($passos, ['Inscrição e matrícula pagas', 'Feito. Você está na fila da próxima turma de ' . $c . '.']);
            }
            $blocos = array_merge([
                mcp_email_b_p($abertura . $fecho),
                mcp_email_b_diferenca($i),
                mcp_email_b_caixa_paga($i, $limite !== '' ? ['Data limite' => $limite] : []),
                mcp_email_b_subtitulo('Próximos passos'),
                mcp_email_b_passos($passos),
                mcp_email_b_nota(mcp_escapar(MCP_EMAIL_NAO_INSCREVA)),
                mcp_email_b_condicoes($i),
            ], $comAcessoHorarios, [
                mcp_email_b_botoes_acesso($ctx, $fechou ? 'senha_e_inscricoes' : 'senha'),
                $blocoHorarios,
                mcp_email_b_acompanhe($i),
            ]);
            break;
        default: // paga
            $assunto = "Matrícula confirmada em $curso";
            $titulo = "Matrícula confirmada, $nome!";
            $preheader = "Pagamento de $total confirmado. Inscrição e matrícula pagas." . $preNovo;
            $dobro = in_array('taxa_em_dobro', $avisos, true)
                ? mcp_email_b_p('Você já tinha pago a taxa de inscrição deste curso. Os <strong>' . mcp_brl(mcp_email_taxa_em_dobro_centavos($i)) . '</strong> pagos a mais, com os juros do parcelamento correspondentes, voltam para você: a secretaria avisa por e-mail quando a devolução for feita.')
                : [];
            $blocos = array_merge([
                mcp_email_b_p($recebemos . ' Sua matrícula' . ($data !== '' ? ' na turma de ' . $data : '') . ' está confirmada.' . $fecho),
                $dobro,
                mcp_email_b_diferenca($i),
                mcp_email_b_caixa_paga($i),
                mcp_email_b_condicoes($i),
            ], $comAcessoHorarios, [
                mcp_email_b_botoes_acesso($ctx, 'senha_e_inscricoes'),
                $blocoHorarios,
                mcp_email_b_subtitulo('Próximos passos'),
                mcp_email_b_passos([
                    ['Inscrição e matrícula pagas', 'Feito. Sua matrícula' . ($data !== '' ? ' na turma de ' . $data : '') . ' está confirmada.'],
                    $passoOrientacoes,
                    $passoVenha,
                ]),
            ]);
    }
    $blocos[] = mcp_email_b_nota(mcp_escapar(mcp_email_texto_estorno($i)));
    $corpo = mcp_email_juntar($blocos);
    return [
        'tipo' => $ctx['acesso'] ? 'acesso' : 'confirmacao',
        'variante' => $variante,
        'assunto' => $assunto,
        'html' => mcp_moldura($titulo, $corpo['html'], mcp_email_opcoes_aluno($preheader)),
        'texto' => $corpo['texto'],
    ];
}

function mcp_montar_email_aluno_pago_taxa(array $i, bool $comComprovante): array
{
    $nome = mcp_primeiro_nome((string) $i['nome']);
    $n = mcp_escapar($nome);
    $curso = (string) $i['curso_nome'];
    $c = '<strong>' . mcp_escapar($curso) . '</strong>';
    $total = mcp_brl(mcp_email_total_pago_centavos($i));
    $ctx = mcp_email_contexto_pago($i, $comComprovante);
    $acesso = $ctx['acesso'];
    $matricula = mcp_email_matricula($i);
    $matriculaAVista = $matricula !== '' ? "$matricula à vista" : 'À vista';
    $fecho = $ctx['obrigado'] . $ctx['comprovante'];
    $caixa = mcp_email_b_caixa_paga($i);

    if (!$acesso) {
        // Versão B (sem a escola): a secretaria conclui a inscrição.
        $comprovante = $comComprovante ? 'o comprovante de inscrição em PDF vai anexado a este e-mail' : 'este e-mail é o seu comprovante';
        $linkParabens = mcp_url_pagina('parabens', (string) $i['token']);
        $horarios = mcp_email_bloco_horarios($i, false, true);
        $corpo = mcp_email_juntar([
            mcp_email_b_p('Parabéns, ' . $n . '. Recebemos o pagamento da taxa de inscrição em ' . $c . '. A secretaria vai concluir sua inscrição, e ' . $comprovante . '.' . $ctx['obrigado']),
            $caixa,
            [$horarios['html'], $horarios['texto']],
            mcp_email_b_subtitulo('Próximos passos'),
            mcp_email_b_passos([
                ['Taxa de inscrição paga', 'Feito. Não se inscreva de novo pela área do aluno: sua taxa já está paga.'],
                ['A secretaria confirma turma e horário', 'A Escola de Educação e Saúde CVB-RJ entra em contato <strong>por e-mail em até ' . MCP_EMAIL_PRAZO . '</strong> para confirmar turma, data e horário. Fique de olho na caixa de entrada e no spam.'],
                ['Depois, a matrícula', mcp_escapar($matriculaAVista) . ', antes da aula. A secretaria da Escola escreve para você com o jeito de pagar.'],
            ]),
            mcp_email_b_botao($linkParabens, 'Ver minha inscrição', true),
            mcp_email_b_nota(mcp_escapar(mcp_email_texto_estorno($i))),
        ]);
        return ['tipo' => 'confirmacao', 'variante' => 'b', 'assunto' => "Taxa de inscrição paga: a secretaria vai concluir sua inscrição em $curso", 'texto' => $corpo['texto'],
            'html' => mcp_moldura("Taxa de inscrição paga, $nome!", $corpo['html'], mcp_email_opcoes_aluno("Pagamento de $total confirmado. A secretaria escreve em até " . MCP_EMAIL_PRAZO . ' para confirmar turma e horário.'))];
    }

    $semTurma = ($acesso['resultado'] ?? '') === 'sem_turma';
    $turma = mcp_email_turma_da_inscricao($i, true);
    $data = !$semTurma && $turma['data'] !== '' ? '<strong>' . mcp_escapar($turma['data']) . '</strong>' : '';
    $horarios = mcp_email_bloco_horarios($i, !$semTurma && $data !== '');
    $blocoHorarios = [$horarios['html'], $horarios['texto']];
    $avisos = mcp_email_avisos($i);

    if (in_array('taxa_ja_confirmada', $avisos, true)) {
        $corpo = mcp_email_juntar([
            mcp_email_b_p('Oi, ' . $n . '. Você já tinha pago a inscrição de ' . $c . '. Este pagamento de <strong>' . $total . '</strong> será devolvido por inteiro. Não pague de novo.'),
            $caixa,
            $ctx['entrada'],
            mcp_email_b_botoes_acesso($ctx, 'inscricoes'),
            mcp_email_b_nota(mcp_escapar(mcp_email_texto_estorno($i))),
        ]);
        return ['tipo' => 'acesso', 'variante' => 'taxa_ja_confirmada', 'assunto' => "Você já tinha pago a inscrição em $curso", 'texto' => $corpo['texto'],
            'html' => mcp_moldura("Não pague de novo, $nome.", $corpo['html'], mcp_email_opcoes_aluno("Este pagamento de $total será devolvido por inteiro."))];
    }

    if ($semTurma) {
        // Só a taxa sem turma (spec 1.14, "Opção 2, sem turma"): a lista da próxima turma. Com a venda sem turma no ar
        // para este curso, a lista é de interesse: quem pagou tudo entra primeiro (spec 10.4, F5).
        $listaDeInteresse = mcp_email_ligada('PLANO_COMPLETO_SEM_TURMA') && array_key_exists((string) $i['curso_slug'], (array) (mcp_email_oferta()['sem_turma'] ?? []));
        $passos = [
            ['Taxa de inscrição paga', 'Feito. Você está na lista da próxima turma de ' . mcp_escapar($curso) . '.'],
            $listaDeInteresse
                ? ['A secretaria avisa a data', 'Quando a turma for marcada, a secretaria avisa por e-mail e diz como pagar a matrícula. O lugar fica com quem pagar primeiro, enquanto houver vaga. Quem já pagou tudo entra primeiro. Não se inscreva de novo pela área do aluno: sua taxa já está paga.']
                : ['A secretaria avisa a data', 'Ainda não há turma aberta. Quando a data sair, a secretaria coloca você na turma e avisa por e-mail. Não se inscreva de novo pela área do aluno: sua taxa já está paga.'],
            ['A matrícula, depois', $listaDeInteresse
                ? 'Quando a turma for marcada, a secretaria orienta o pagamento da matrícula (' . mcp_escapar($matriculaAVista) . ').'
                : 'Quando a turma abrir, a secretaria coloca você nela, avisa por e-mail e orienta o pagamento da matrícula (' . mcp_escapar($matriculaAVista) . ').'],
        ];
        $url = $ctx['url_login'];
        $corpo = mcp_email_juntar([
            mcp_email_b_p('Parabéns, ' . $n . '. Recebemos o pagamento da taxa de inscrição em ' . $c . ' e você está na lista da próxima turma.' . $fecho),
            $caixa,
            $ctx['entrada'],
            $ctx['link']
                ? mcp_email_bloco(mcp_email_juntar([mcp_email_b_botao($ctx['link'], 'Criar minha senha'), mcp_email_b_p('Já criou a senha? <a href="' . mcp_escapar($url) . '" style="color:#cc0000">Entrar na área do aluno</a>.')]))
                : mcp_email_b_botao($url, 'Entrar na área do aluno'),
            $blocoHorarios,
            mcp_email_b_subtitulo('Próximos passos'),
            mcp_email_b_passos($passos),
            mcp_email_b_nota(mcp_escapar(mcp_email_texto_estorno($i))),
        ]);
        return ['tipo' => 'acesso', 'variante' => 'sem_turma', 'assunto' => "Você está na lista da próxima turma de $curso", 'texto' => $corpo['texto'],
            'html' => mcp_moldura("Taxa de inscrição paga, $nome!", $corpo['html'], mcp_email_opcoes_aluno("Pagamento de $total confirmado. Você está na lista da próxima turma."))];
    }

    // Só a taxa com turma (spec 1.14, "Opção 2, com turma").
    $corpo = mcp_email_juntar([
        mcp_email_b_p('Parabéns, ' . $n . '. Recebemos o pagamento da taxa de inscrição em ' . $c . ', e sua vaga' . ($data !== '' ? ' na turma de ' . $data : '') . ' está reservada.' . $fecho),
        mcp_email_b_destaque('Falta pagar a matrícula.', 'A taxa de inscrição reserva a sua vaga, mas sem o pagamento da matrícula a entrada na aula não é liberada.'),
        $caixa,
        $ctx['entrada'],
        mcp_email_b_botoes_acesso($ctx, 'senha_e_inscricoes'),
        $blocoHorarios,
        mcp_email_b_subtitulo('Próximos passos'),
        mcp_email_b_passos([
            ['Taxa de inscrição paga', 'Feito. ' . ($data !== '' ? 'Sua vaga na turma de ' . $data . ' está reservada.' : 'Sua vaga está reservada.')],
            ['Pagamento da matrícula', mcp_escapar($matriculaAVista) . ', antes da aula. A secretaria da Escola escreve para você com o jeito de pagar.'],
            ['Venha para a aula', ($data !== '' ? $data . ', na Praça da Cruz Vermelha, 10. O horário está na sua área do aluno.' : 'Na Praça da Cruz Vermelha, 10. A data e o horário estão na sua área do aluno.')],
        ]),
        mcp_email_b_nota(mcp_escapar(mcp_email_texto_estorno($i))),
    ]);
    return ['tipo' => 'acesso', 'variante' => 'com_turma', 'assunto' => "Taxa de inscrição confirmada em $curso: falta só o pagamento da matrícula", 'texto' => $corpo['texto'],
        'html' => mcp_moldura("Vaga reservada, $nome!", $corpo['html'], mcp_email_opcoes_aluno("Pagamento de $total confirmado. Falta só o pagamento da matrícula."))];
}

function mcp_email_aluno_pago(array $inscricao): void
{
    // O comprovante em PDF é um extra: se falhar (logo ausente, qualquer coisa), o aluno recebe a
    // confirmação do mesmo jeito, sem anexo. A mensagem de erro não leva dado do aluno.
    $anexos = [];
    try {
        $anexos[] = ['nome' => mcp_comprovante_arquivo($inscricao), 'conteudo' => mcp_comprovante_pdf($inscricao), 'tipo' => 'application/pdf'];
    } catch (Throwable $e) {
        error_log('[matricula] comprovante em PDF falhou (inscrição ' . (int) $inscricao['id'] . '): ' . $e->getMessage());
    }
    $m = mcp_montar_email_aluno_pago($inscricao, $anexos !== []);
    $r = mcp_enviar_email((string) $inscricao['email'], $m['assunto'], $m['html'], $m['texto'], null, null, $anexos);
    mcp_atualizar((int) $inscricao['id'], ['email_aluno' => $r === 'falhou' ? 'falhou' : $m['tipo']]);
    mcp_registrar((int) $inscricao['id'], 'email_aluno', "{$m['tipo']} · $r · " . ($anexos ? 'com comprovante PDF' : 'sem comprovante PDF') . ' · ' . ($m['variante'] ?? ''));
}

/** E-mail curto ao aluno quando o cartão cobrou mais que o total mostrado e a diferença ainda não foi avisada (spec 2.4). */
function mcp_montar_email_diferenca_aluno(array $i): array
{
    $nome = mcp_primeiro_nome((string) $i['nome']);
    $curso = (string) $i['curso_nome'];
    $diferenca = mcp_brl((int) ($i['diferenca_devolver_centavos'] ?? 0));
    $corpo = mcp_email_juntar([
        mcp_email_b_p('Oi, ' . mcp_escapar($nome) . '. Na sua compra em <strong>' . mcp_escapar($curso) . '</strong>, cobramos <strong>' . $diferenca . '</strong> a mais que o valor mostrado. A diferença volta por PIX em até 2 dias úteis.'),
        mcp_email_b_p('Para receber, responda este e-mail com uma chave PIX em seu nome.'),
    ]);
    return ['assunto' => "Vamos devolver a diferença da sua compra em $curso", 'texto' => $corpo['texto'],
        'html' => mcp_moldura("Vamos devolver a diferença, $nome.", $corpo['html'], mcp_email_opcoes_aluno("Cobramos $diferenca a mais que o valor mostrado. A diferença volta por PIX em até 2 dias úteis."))];
}

function mcp_email_diferenca_aluno(array $inscricao): string
{
    if ((int) ($inscricao['diferenca_devolver_centavos'] ?? 0) <= 0) {
        return 'nada';
    }
    $m = mcp_montar_email_diferenca_aluno($inscricao);
    $r = mcp_enviar_email((string) $inscricao['email'], $m['assunto'], $m['html'], $m['texto']);
    mcp_registrar((int) $inscricao['id'], 'email_diferenca', $r);
    return $r;
}

// ----------------------------------------------------------------------------- aviso à secretaria
/** Por que o aviso à secretaria é URGENTE (spec 1.14), ou null. $urgente: motivo vindo de fora (tentativas esgotadas…). */
function mcp_email_secretaria_urgencia(array $i, ?string $urgente = null): ?string
{
    if ($urgente !== null && $urgente !== '') {
        return $urgente;
    }
    $avisos = mcp_email_avisos($i);
    if (!mcp_email_completo($i)) {
        return in_array('taxa_ja_confirmada', $avisos, true) ? 'taxa_ja_confirmada' : null;
    }
    if (!empty($i['espera_status'])) {
        return in_array('curso_ja_pago', $avisos, true) ? 'curso_ja_pago' : null;
    }
    foreach (['curso_ja_pago', 'matricula_paga_sem_turma', 'matricula_nao_marcada', 'turma_diferente'] as $aviso) {
        if (in_array($aviso, $avisos, true)) {
            return $aviso;
        }
    }
    if (mcp_escola_erro($i) !== null) {
        return 'escola_recusou';
    }
    if (($i['escola_status'] ?? '') === 'erro' && (int) ($i['escola_tentativas'] ?? 0) >= MCP_ESCOLA_MAX_TENTATIVAS) {
        return 'esgotado';
    }
    if ((int) ($i['diferenca_devolver_centavos'] ?? 0) > 0) {
        return 'diferenca';
    }
    // A regra de escola.php (mcp_escola_urgente), quando existe, também vale: as duas listas são a da spec 1.14.
    return function_exists('mcp_escola_urgente') && mcp_escola_urgente($i) ? 'escola' : null;
}

/** As linhas da caixa do aviso à secretaria (spec 1.14): plano, matrícula, parcelas e a decomposição do valor pago. */
function mcp_montar_email_secretaria_linhas(array $i): array
{
    $completo = mcp_email_completo($i);
    $n = mcp_email_parcelas($i);
    $juros = mcp_email_juros_centavos($i);
    $matricula = $completo ? mcp_brl(mcp_email_matricula_cobrada($i)) : (mcp_email_matricula($i) !== '' ? mcp_email_matricula($i) . ' à vista, não paga' : '—');
    $parcelas = 'à vista';
    if ($n > 1) {
        $d = mcp_email_divisao(mcp_email_total_pago_centavos($i), $n);
        $parcelas = ($d['primeira'] === $d['parcela'] ? "{$n}× de " . mcp_brl($d['parcela'])
            : "{$n}× (1ª de " . mcp_brl($d['primeira']) . ' e ' . ($n - 1) . ' de ' . mcp_brl($d['parcela']) . ')') . ' (juros ' . mcp_brl($juros) . ')';
    }
    $linhas = [
        'Curso' => (string) $i['curso_nome'],
        'Aluno' => (string) $i['nome'],
        'CPF' => (string) $i['cpf'],
        'E-mail' => (string) $i['email'],
        'Telefone' => (string) $i['telefone'],
        'Pago' => mcp_brl(mcp_email_total_pago_centavos($i)) . ' (' . mcp_email_decomposicao($i) . ')',
        'Plano' => $completo ? 'Taxa de inscrição + matrícula' : 'Só a taxa de inscrição',
        'Matrícula' => $matricula,
        'Parcelas' => $parcelas,
    ];
    if ((int) ($i['diferenca_devolver_centavos'] ?? 0) > 0) {
        $linhas['Diferença a devolver'] = mcp_brl((int) $i['diferenca_devolver_centavos']) . ' (o cartão cobrou mais que o total mostrado: PIX em até 2 dias úteis)';
    }
    if ($completo && !empty($i['espera_status']) && mcp_email_data_limite($i) !== '') {
        $linhas['Data limite'] = mcp_email_data_limite($i);
    }
    return $linhas + [
        'Método' => ($i['metodo'] ?? '') === 'pix' ? 'PIX' : trim('Cartão ' . ($i['bandeira'] ?? '') . ' final ' . ($i['ultimos4'] ?? '')),
        'Transação Unicopag' => (string) ($i['unicopag_hash'] ?? ''),
        'Origem' => trim(($i['utm_source'] ?? '') . ' ' . ($i['utm_campaign'] ?? '')) ?: 'direto',
        'Escola' => mcp_escola_resumo($i),
    ];
}

/**
 * Aviso de inscrição paga à secretaria (spec 1.14 e 10.6): assunto por plano (URGENTE quando pede ação), a primeira
 * frase do caso e a caixa com plano, matrícula, parcelas e a decomposição do valor pago. Responder-para é o aluno.
 * $inscricao['posicao_fila'] (opcional): a posição na fila, calculada por quem envia.
 */
function mcp_montar_email_secretaria(array $inscricao, ?string $urgente = null): array
{
    $i = $inscricao;
    $completo = mcp_email_completo($i);
    $espera = $completo && ($i['espera_status'] ?? '') === 'aguardando';
    $motivo = mcp_email_secretaria_urgencia($i, $urgente);
    $acesso = mcp_escola_acesso($i);
    $avisos = mcp_email_avisos($i);
    $linhas = mcp_montar_email_secretaria_linhas($i);
    $posicao = isset($i['posicao_fila']) && (int) $i['posicao_fila'] > 0 ? ' (é a ' . (int) $i['posicao_fila'] . 'ª da fila)' : '';
    $primeira = match (true) {
        $espera => 'Nova inscrição paga sem turma: inscrição e matrícula pagas no site. A pessoa entrou na fila da próxima turma do curso' . $posicao . '. Há conta na escola, mas nenhuma matrícula, e não há nada a fazer agora. Quando a escola abrir uma turma do curso com vaga, com as aulas cadastradas e a primeira aula pelo menos 10 dias depois, o site matricula sozinho, já paga, por ordem de pagamento, em até 6 h e 15 min da criação da turma. <strong>NÃO marque nada como pago na escola, NÃO use "Encaixar" e NÃO use o convite de Alunos &gt; "sem inscrição" para esta pessoa.</strong> Para pôr a pessoa numa turma específica (por exemplo, uma que começa em menos de 10 dias, com o acordo dela), crie a matrícula pendente na escola, sem marcar a taxa: a rotina a paga em até 15 minutos.',
        $completo && $motivo === null && $acesso && ($acesso['matricula_paga'] ?? null) === true => 'Nova matrícula paga pela página de cursos: inscrição e matrícula pagas no site, e a escola registrou a matrícula como paga (detalhes abaixo). Para combinar turma ou horário, basta responder este e-mail, que vai direto para o aluno.',
        $completo => 'Nova compra da inscrição e da matrícula pela página de cursos, paga no site. <strong>A situação na escola pede ação: veja a linha Escola abaixo.</strong> Para falar com o aluno, basta responder este e-mail, que vai direto para ele.',
        $acesso && ($acesso['resultado'] ?? '') === 'matriculado' && !in_array('taxa_ja_confirmada', $avisos, true) => 'Nova inscrição paga (só a taxa). O aluno está matriculado com a taxa confirmada e a matrícula'
            . (mcp_email_matricula($i) !== '' ? ' (' . mcp_email_matricula($i) . ' à vista)' : '') . ' ainda não está paga: combine com ele o pagamento antes da aula. Na área do aluno não há pagamento online para este caso.',
        $acesso && ($acesso['resultado'] ?? '') === 'matriculado' => 'Nova inscrição paga pela página de matrícula. O aluno já está matriculado na plataforma da escola, com a taxa confirmada (detalhes abaixo). Para combinar turma, horário ou o pagamento do curso, basta responder este e-mail, que vai direto para ele.',
        default => 'Nova inscrição paga pela página de matrícula. Entrar em contato com o aluno <strong>por e-mail em até ' . MCP_EMAIL_PRAZO . '</strong> para fechar turma e horário: basta responder este e-mail, que vai direto para ele.',
    };
    $porque = [
        'diferenca' => 'O cartão cobrou mais que o total mostrado ao aluno: devolver a diferença por PIX em até 2 dias úteis (ou estornar tudo, com aviso).',
        'esgotado' => 'A escola não respondeu depois de ' . MCP_ESCOLA_MAX_TENTATIVAS . ' tentativas: a matrícula paga não foi registrada na escola.',
        'escola_recusou' => 'A escola recusou o registro desta compra.',
    ][$motivo ?? ''] ?? '';
    $nomeCurso = $i['nome'] . ' — ' . $i['curso_nome'];
    $assunto = match (true) {
        $espera => "Pagou tudo e espera turma: $nomeCurso",
        $completo => "Matrícula paga (taxa + matrícula): $nomeCurso",
        default => "Inscrição paga: $nomeCurso",
    };
    $c = mcp_email_juntar([
        mcp_email_b_p($primeira),
        $porque !== '' ? mcp_email_b_p('<strong>' . mcp_escapar($porque) . '</strong>') : [],
        mcp_email_b_caixa($linhas),
    ]);
    return [
        'assunto' => ($motivo !== null ? 'URGENTE: ' : '') . $assunto,
        'urgente' => $motivo,
        'html' => mcp_moldura($espera ? 'Pagou tudo e espera turma' : ($completo ? 'Nova matrícula paga' : 'Nova inscrição paga'), $c['html'], mcp_email_opcoes_secretaria()),
        'texto' => $c['texto'],
    ];
}

/**
 * Desligado enquanto EMAIL_SECRETARIA estiver vazio. Responder-para é o aluno. $urgente: um segundo aviso, URGENTE, por
 * um motivo que apareceu depois do primeiro ('esgotado' quando a varredura esgota as tentativas; 'diferenca' quando a
 * reconsulta acha o cartão cobrando mais que o total mostrado).
 */
function mcp_email_secretaria(array $inscricao, ?string $urgente = null): void
{
    $para = (string) mcp_cfg('EMAIL_SECRETARIA', '');
    if ($para === '') {
        return;
    }
    if (mcp_email_completo($inscricao) && ($inscricao['espera_status'] ?? '') === 'aguardando' && !isset($inscricao['posicao_fila'])) {
        $inscricao['posicao_fila'] = mcp_email_posicao_fila($inscricao);
    }
    $m = mcp_montar_email_secretaria($inscricao, $urgente);
    $r = mcp_enviar_email($para, $m['assunto'], $m['html'], $m['texto'], (string) $inscricao['email']);
    mcp_atualizar((int) $inscricao['id'], ['email_secretaria' => $r]);
    mcp_registrar((int) $inscricao['id'], 'email_secretaria', $r . ($m['urgente'] !== null ? ' · URGENTE · ' . $m['urgente'] : ''));
}

/**
 * Aviso de estorno à secretaria (spec 1.14, T5; 10.6, texto d): o texto do caso e a caixa do aviso de pagamento. Quem
 * envia é mcp_pos_estorno() (unicopag.php). $dobro: é só a taxa, e o mesmo CPF tem outra compra paga de taxa + matrícula
 * do curso; sem ele (null), a conferência é feita aqui, no banco.
 */
function mcp_montar_email_estorno_secretaria(array $i, ?bool $dobro = null): array
{
    if ($dobro === null) {
        $dobro = false;
        if (!mcp_email_completo($i) && !empty($i['id'])) {
            try {
                $dobro = function_exists('mcp_compra_completa_paga_do_cpf') ? mcp_compra_completa_paga_do_cpf($i) : false;
            } catch (Throwable $e) {
                error_log('[matricula] estorno: conferência do pagamento em dobro falhou: ' . $e->getMessage());
            }
        }
    }
    $total = mcp_brl(mcp_email_total_pago_centavos($i));
    $avisos = mcp_email_avisos($i);
    $esperou = mcp_email_completo($i) && (!empty($i['espera_status']) || !empty($i['espera_desde']));
    $semMatricula = $esperou && ($i['espera_status'] ?? '') !== 'turma' && empty($i['espera_matricula_escola'])
        && (empty($i['turma_id']) || (mcp_escola_acesso($i)['resultado'] ?? '') === 'sem_turma');
    $texto = match (true) {
        in_array('curso_ja_pago', $avisos, true) || $dobro => 'Estorno do pagamento em dobro. <strong>NÃO cancele a matrícula na escola: ela continua paga.</strong>',
        $semMatricula => 'A Unicopag confirmou o estorno desta compra sem turma (' . $total . '). Não há matrícula na escola: nada a fazer lá.',
        mcp_email_completo($i) => 'A Unicopag confirmou o estorno desta compra (' . $total . '). Confira na escola: se a matrícula não estiver como Estornada, cancele-a (Admin → aluno → matrícula → Cancelar inscrição). O aluno não deve ficar com a matrícula paga na escola. Depois, marque "Já resolvi na escola" na ficha da inscrição.',
        default => 'A Unicopag confirmou o estorno desta inscrição (' . $total . '). Confira se a taxa foi desfeita na escola.',
    };
    $c = mcp_email_juntar([mcp_email_b_p($texto), mcp_email_b_caixa(mcp_montar_email_secretaria_linhas($i))]);
    return [
        'assunto' => 'Estorno confirmado: ' . $i['nome'] . ' — ' . $i['curso_nome'],
        'html' => mcp_moldura('Estorno confirmado', $c['html'], mcp_email_opcoes_secretaria()),
        'texto' => $c['texto'],
        'caso' => match (true) { in_array('curso_ja_pago', $avisos, true) || $dobro => 'a', $semMatricula => 'd', mcp_email_completo($i) => 'b', default => 'c' },
    ];
}

// ----------------------------------------------------------------------------- venda sem turma: e-mails à pessoa (spec 10.6)
/** Envia um e-mail da espera à pessoa e registra (tipo do evento = $evento). Devolve resend, mail ou falhou. */
function mcp_email_espera_enviar(array $inscricao, array $m, string $evento): string
{
    $r = mcp_enviar_email((string) $inscricao['email'], $m['assunto'], $m['html'], $m['texto']);
    mcp_registrar((int) $inscricao['id'], $r === 'falhou' ? $evento . '_falhou' : $evento, $r);
    return $r;
}

/**
 * "Sua turma abriu" (spec 10.6): sai uma vez por inscrição, na passagem de aguardando para turma. A data e o horário
 * são os da primeira aula, da resposta da escola (turma_primeira_aula e turma_horario em escola_acesso); o prazo da
 * "data não serve" é o espera_janela_ate gravado, ou a conta da janela feita agora (mcp_email_janela_ate).
 */
function mcp_montar_email_turma_aberta(array $i, ?int $agora = null): array
{
    $nome = mcp_primeiro_nome((string) $i['nome']);
    $n = mcp_escapar($nome);
    $curso = (string) $i['curso_nome'];
    $c = '<strong>' . mcp_escapar($curso) . '</strong>';
    $turma = mcp_email_turma_da_inscricao($i, true, $agora);
    $acesso = mcp_escola_acesso($i) ?? [];
    $confirmada = strtoupper((string) ($acesso['turma_status'] ?? '')) === 'CONFIRMADA' || !empty($i['espera_turma_confirmada_em']);
    $prazo = mcp_email_prazo_da_data($i, $agora);
    $pagoEm = mcp_data_brt((string) ($i['pago_em'] ?? ''), 'd/m/Y');
    $data = $turma['data'] !== '' ? $turma['data'] : 'a data informada pela secretaria';
    $ctx = mcp_email_contexto_pago($i, false);
    $local = 'Praça da Cruz Vermelha, 10';
    $itens = [
        'Até ' . $prazo . ', se a data ou o horário não servirem: tudo de volta.',
    ];
    if (!$confirmada) {
        $itens[] = 'Até a turma ser confirmada: tudo de volta.';
    }
    $itens[] = 'Depois da confirmação: se você desistir antes da primeira aula, ' . mcp_email_regra_p4_frase() . '.';
    $lista = '';
    foreach ($itens as $k => $item) {
        $lista .= ($k + 1) . '. ' . $item . ' ';
    }
    $linkVencido = $ctx['novo'] && !$ctx['link'];
    $corpo = mcp_email_juntar([
        mcp_email_b_p('Oi, ' . $n . '. A Escola marcou a turma de ' . $c . ', e a sua matrícula já está nela, com a inscrição e a matrícula já pagas' . ($pagoEm !== '' ? ' em ' . $pagoEm : '') . '. '
            . ($confirmada ? 'A turma já está confirmada.' : 'A turma ainda precisa de um número mínimo de alunos para ser confirmada: você recebe outro e-mail quando ela for.')),
        mcp_email_b_caixa(array_filter([
            'Curso' => $curso,
            'Início' => $turma['longa'],
            'Horário' => $turma['horario'],
            'Local' => MCP_EMAIL_LOCAL,
            'Pago' => mcp_brl(mcp_email_total_pago_centavos($i)),
        ], static fn($v): bool => $v !== '')),
        mcp_email_b_destaque('A data ou o horário não servem?', 'Responda este e-mail ou fale com a secretaria pelo chat até <strong>' . mcp_escapar($prazo) . '</strong>: você recebe de volta tudo o que pagou.'),
        mcp_email_b_subtitulo('Até quando dá para desistir'),
        mcp_email_b_p(mcp_escapar(trim($lista))),
        mcp_email_b_subtitulo('Próximos passos'),
        mcp_email_b_passos([
            ['Matrícula confirmada', 'Você está na turma de <strong>' . mcp_escapar($data) . '</strong>.'],
            ['Receba as orientações da Escola', MCP_EMAIL_PASSO_03],
            ['Venha para a aula', '<strong>' . mcp_escapar($data) . '</strong>, ' . ($turma['horario'] !== '' ? mcp_escapar($turma['horario']) . ', ' : '') . 'na ' . MCP_EMAIL_LOCAL . '.'],
        ]),
        $ctx['link'] ? mcp_email_b_botao($ctx['link'], 'Criar minha senha') : [],
        mcp_email_b_botao($ctx['url_login'], 'Ver minhas inscrições', (bool) $ctx['link']),
        $linkVencido ? mcp_email_b_nota('Ainda não criou a senha? Use “Esqueci minha senha” na tela de entrada.') : [],
        mcp_email_b_acompanhe($i),
        mcp_email_b_nota(mcp_escapar(mcp_email_texto_estorno($i))),
    ]);
    return [
        'assunto' => "Sua turma de $curso abriu: começa em $data",
        'html' => mcp_moldura("Sua turma abriu, $nome!", $corpo['html'], mcp_email_opcoes_aluno(
            $data . ($turma['horario'] !== '' ? ', ' . $turma['horario'] : '') . ", na $local. A inscrição e a matrícula já estão pagas.")),
        'texto' => $corpo['texto'],
    ];
}

/**
 * Envia "Sua turma abriu". A reserva de uma vez por inscrição (espera_turma_email_em, desfeita se o envio falhar) e a
 * gravação de espera_janela_ate são de quem chama (lib/espera.php, spec 10.8): grave espera_janela_ate antes, com
 * mcp_email_janela_ate(), para o e-mail e o banco dizerem a mesma data.
 */
function mcp_email_turma_aberta(array $inscricao): string
{
    return mcp_email_espera_enviar($inscricao, mcp_montar_email_turma_aberta($inscricao), 'email_turma_aberta');
}

/**
 * "Sua turma mudou" (spec 10.6, F2, T4). $antes: os dados da turma de antes (mcp_espera_turma_dados_ler: primeira_aula
 * em "AAAA-MM-DD") ou só a data ("AAAA-MM-DD" ou "dd/mm/aaaa"). O novo prazo é o espera_janela_ate gravado.
 */
function mcp_montar_email_turma_mudou(array $i, array|string $antes = '', ?int $agora = null): array
{
    $nome = mcp_primeiro_nome((string) $i['nome']);
    $curso = (string) $i['curso_nome'];
    $turma = mcp_email_turma_da_inscricao($i, true, $agora);
    $dataAntiga = is_array($antes) ? (string) ($antes['primeira_aula'] ?? '') : $antes;
    $antiga = ($d = mcp_email_quando($dataAntiga)) !== null ? $d->format('d/m/Y') : ($dataAntiga !== '' ? $dataAntiga : 'a data anterior');
    $nova = $turma['data'] !== '' ? $turma['data'] : 'outra data';
    $prazo = mcp_email_prazo_da_data($i, $agora);
    $corpo = mcp_email_juntar([
        mcp_email_b_p('Oi, ' . mcp_escapar($nome) . '. A sua turma de <strong>' . mcp_escapar($curso) . '</strong> mudou: a primeira aula passou de ' . mcp_escapar($antiga) . ' para '
            . mcp_escapar($nova) . ($turma['horario'] !== '' ? ', ' . mcp_escapar($turma['horario']) : '') . '. Se a nova data ou o horário não servirem, responda este e-mail ou fale com a secretaria pelo chat até '
            . '<strong>' . mcp_escapar($prazo) . '</strong>, e devolvemos tudo o que você pagou. Se servirem, não precisa fazer nada.'),
        mcp_email_b_acompanhe($i),
        mcp_email_b_nota(mcp_escapar(mcp_email_texto_estorno($i))),
    ]);
    return [
        'assunto' => "A turma de $curso mudou: agora começa em $nova",
        'html' => mcp_moldura("A sua turma mudou, $nome.", $corpo['html'], mcp_email_opcoes_aluno("A primeira aula passou para $nova.")),
        'texto' => $corpo['texto'],
    ];
}

function mcp_email_turma_mudou(array $inscricao, array|string $antes): string
{
    return mcp_email_espera_enviar($inscricao, mcp_montar_email_turma_mudou($inscricao, $antes), 'email_turma_mudou');
}

/**
 * "Turma cancelada" (spec 10.6, F2). A data da turma cancelada sai de espera_turma_dados (gravado pela reconsulta) ou da
 * resposta da escola; $data só vale se for uma data ("AAAA-MM-DD"): a rotina manda o prazo ("d/m"), que é ignorado. O
 * prazo da resposta é o espera_janela_ate gravado (envio + 7 dias) ou, sem ele, agora + 7 dias.
 */
function mcp_montar_email_turma_cancelada(array $i, ?string $data = null, ?int $agora = null): array
{
    $nome = mcp_primeiro_nome((string) $i['nome']);
    $curso = (string) $i['curso_nome'];
    $dados = explode('|', (string) ($i['espera_turma_dados'] ?? '')) + ['', ''];
    $candidatas = [$data !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) ? $data : null, preg_match('/^\d{4}-\d{2}-\d{2}$/', $dados[1]) ? $dados[1] : null];
    $dataTurma = '';
    foreach ($candidatas as $c) {
        if ($c !== null && ($d = mcp_email_quando($c)) !== null) {
            $dataTurma = $d->format('d/m/Y');
            break;
        }
    }
    $dataTurma = $dataTurma !== '' ? $dataTurma : (mcp_email_turma_da_inscricao($i, true, $agora)['data'] ?: 'a data marcada');
    $ate = mcp_email_quando(!empty($i['espera_janela_ate']) ? (string) $i['espera_janela_ate'] : gmdate('Y-m-d H:i:s', ($agora ?? time()) + 7 * 86400), true);
    $prazo = $ate ? mcp_email_dia_semana($ate, false) : '';
    $total = mcp_brl(mcp_email_total_pago_centavos($i));
    $corpo = mcp_email_juntar([
        mcp_email_b_p('Oi, ' . mcp_escapar($nome) . '. A Escola cancelou a turma de <strong>' . mcp_escapar($curso) . '</strong> que começaria em ' . mcp_escapar($dataTurma)
            . '. Você não perde nada: escolha entre entrar na próxima turma do curso que tiver vaga ou receber de volta os ' . $total . ' que você pagou. Responda este e-mail ou fale com a secretaria pelo chat.'
            . ($prazo !== '' ? ' Se não recebermos a sua resposta até <strong>' . mcp_escapar($prazo) . '</strong>, devolvemos tudo, sem você precisar pedir.' : '')),
        mcp_email_b_acompanhe($i),
    ]);
    return [
        'assunto' => "A turma de $curso que começaria em $dataTurma foi cancelada",
        'html' => mcp_moldura("A turma foi cancelada, $nome.", $corpo['html'], mcp_email_opcoes_aluno('Escolha entre a próxima turma do curso ou a devolução de tudo o que você pagou.')),
        'texto' => $corpo['texto'],
    ];
}

function mcp_email_turma_cancelada(array $inscricao, ?string $data = null): string
{
    return mcp_email_espera_enviar($inscricao, mcp_montar_email_turma_cancelada($inscricao, $data), 'email_turma_cancelada');
}

/** "Turma confirmada" (spec 10.6, F10): a secretaria marcou a turma como confirmada no painel. */
function mcp_montar_email_turma_confirmada(array $i): array
{
    $nome = mcp_primeiro_nome((string) $i['nome']);
    $curso = (string) $i['curso_nome'];
    $turma = mcp_email_turma_da_inscricao($i, true);
    $data = $turma['data'] !== '' ? $turma['data'] : 'a data marcada';
    $corpo = mcp_email_juntar([
        mcp_email_b_p('Oi, ' . mcp_escapar($nome) . '. A turma de <strong>' . mcp_escapar($curso) . '</strong> que começa em ' . mcp_escapar($data)
            . ($turma['horario'] !== '' ? ', ' . mcp_escapar($turma['horario']) : '') . ', atingiu o número mínimo de alunos e está confirmada. A partir de hoje, se você desistir antes da primeira aula, '
            . mcp_escapar(mcp_email_regra_p4_frase()) . '. Antes da aula, você recebe as orientações da Escola por e-mail.'),
        mcp_email_b_acompanhe($i),
    ]);
    return [
        'assunto' => "Turma confirmada: $curso começa em $data",
        'html' => mcp_moldura("Turma confirmada, $nome!", $corpo['html'], mcp_email_opcoes_aluno("$curso começa em $data.")),
        'texto' => $corpo['texto'],
    ];
}

function mcp_email_turma_confirmada(array $inscricao): string
{
    return mcp_email_espera_enviar($inscricao, mcp_montar_email_turma_confirmada($inscricao), 'email_turma_confirmada');
}

/**
 * Limite do estorno no cartão, em dias da venda (pergunta e do passo 0 à Unicopag: ESTORNO_CARTAO_LIMITE_DIAS no
 * config.php). 0 = sem resposta: a prorrogação no cartão fica fechada (spec 10.8).
 */
function mcp_email_limite_estorno_cartao_dias(): int
{
    return max(0, (int) mcp_cfg('ESTORNO_CARTAO_LIMITE_DIAS', 0));
}

/** A pessoa ainda pode pedir para continuar esperando (uma vez só; no cartão, só com a nova data dentro do limite do estorno). */
function mcp_email_pode_prorrogar(array $i): bool
{
    if ((int) ($i['espera_prorrogada'] ?? 0) !== 0) {
        return false;
    }
    if (($i['metodo'] ?? 'pix') !== 'cartao') {
        return true;
    }
    $limite = mcp_email_limite_estorno_cartao_dias();
    $prazo = mcp_email_quando($i['espera_prazo'] ?? null, true);
    $pago = mcp_email_quando($i['pago_em'] ?? null, true);
    return $limite > 0 && $prazo && $pago && $prazo->modify('+' . mcp_email_prazo_dias() . ' days') <= $pago->modify("+$limite days");
}

/**
 * "Ainda sem data" (aos 30 e aos 60 dias) e o aviso do prazo, 10 dias antes do último dia de marcar a turma (spec 10.6,
 * F11). $variante: 'sem_data' ou 'prazo'.
 */
function mcp_montar_email_espera_lembrete(array $i, string $variante = 'sem_data'): array
{
    $nome = mcp_primeiro_nome((string) $i['nome']);
    $curso = (string) $i['curso_nome'];
    $c = '<strong>' . mcp_escapar($curso) . '</strong>';
    $limite = mcp_email_data_limite($i);
    $total = mcp_brl(mcp_email_total_pago_centavos($i));
    if ($variante === 'prazo') {
        $marcarAte = mcp_email_marcar_ate($i);
        $novaLimite = ($d = mcp_email_quando($i['espera_prazo'] ?? null, true)) !== null ? $d->modify('+' . mcp_email_prazo_dias() . ' days')->format('d/m/Y') : '';
        $pago = mcp_email_quando($i['pago_em'] ?? null, true);
        $prorroga = mcp_email_pode_prorrogar($i);
        $frasePix = $pago ? 'Depois de ' . $pago->modify('+90 days')->format('d/m/Y') . ', a devolução do PIX passa a ser uma transferência para uma conta no seu nome, que vamos pedir a você.' : '';
        $limiteCartao = mcp_email_limite_estorno_cartao_dias();
        $fraseCartao = $pago && $limiteCartao > 0 ? 'Depois de ' . $pago->modify("+$limiteCartao days")->format('d/m/Y') . ', a devolução pode deixar de ser um estorno no cartão e passar a ser uma transferência para uma conta no seu nome.' : '';
        $corpo = mcp_email_juntar([
            mcp_email_b_p('Oi, ' . mcp_escapar($nome) . '. A turma de ' . $c . ' ainda não foi marcada. Se ela não for marcada até <strong>' . mcp_escapar($marcarAte) . '</strong>, devolvemos os <strong>' . $total . '</strong> que você pagou, sem você precisar pedir.'
                . ($prorroga && $novaLimite !== '' ? ' Se preferir continuar na fila, responda este e-mail até ' . mcp_escapar($marcarAte) . ' escrevendo "Quero continuar". A nova data limite passa a ser ' . mcp_escapar($novaLimite) . ', e só dá para continuar uma vez.' : '')),
            ($i['metodo'] ?? 'pix') === 'pix' ? ($frasePix !== '' ? mcp_email_b_p(mcp_escapar($frasePix)) : []) : ($fraseCartao !== '' ? mcp_email_b_p(mcp_escapar($fraseCartao)) : []),
            mcp_email_b_acompanhe($i),
        ]);
        return [
            'assunto' => "$curso: se a turma não for marcada até $marcarAte, devolvemos tudo",
            'html' => mcp_moldura("Faltam 10 dias, $nome.", $corpo['html'], mcp_email_opcoes_aluno("Se a turma não for marcada até $marcarAte, os $total voltam para você.")),
            'texto' => $corpo['texto'],
        ];
    }
    $corpo = mcp_email_juntar([
        mcp_email_b_p('Oi, ' . mcp_escapar($nome) . '. A Escola ainda não marcou a próxima turma de ' . $c . '. Você continua na fila, com a inscrição e a matrícula pagas, e recebe a data por e-mail assim que ela for marcada. Se preferir não esperar, responda este e-mail ou fale com a secretaria pelo chat: devolvemos tudo o que você pagou.'
            . ($limite !== '' ? ' Se não houver turma marcada para começar até <strong>' . mcp_escapar($limite) . '</strong>, devolvemos tudo, sem você precisar pedir.' : '')),
        mcp_email_b_nota(mcp_escapar(MCP_EMAIL_NAO_INSCREVA)),
        mcp_email_b_acompanhe($i),
    ]);
    return [
        'assunto' => "Ainda sem data para $curso: você continua na fila",
        'html' => mcp_moldura("Você continua na fila, $nome.", $corpo['html'], mcp_email_opcoes_aluno("A turma de $curso ainda não foi marcada." . ($limite !== '' ? " Se não houver turma para começar até $limite, devolvemos tudo." : ''))),
        'texto' => $corpo['texto'],
    ];
}

function mcp_email_espera_lembrete(array $inscricao, string $variante = 'sem_data'): string
{
    return mcp_email_espera_enviar($inscricao, mcp_montar_email_espera_lembrete($inscricao, $variante), $variante === 'prazo' ? 'email_espera_prazo' : 'email_espera_lembrete');
}

/** "Você continua na fila" (spec 10.6, F11): a secretaria marcou "Continua esperando". Lê a nova data limite já gravada. */
function mcp_montar_email_espera_prorrogada(array $i): array
{
    $nome = mcp_primeiro_nome((string) $i['nome']);
    $curso = (string) $i['curso_nome'];
    $limite = mcp_email_data_limite($i);
    $corpo = mcp_email_juntar([
        mcp_email_b_p('Oi, ' . mcp_escapar($nome) . '. Recebemos o seu pedido: você continua na fila da próxima turma de <strong>' . mcp_escapar($curso) . '</strong>. A nova data limite é <strong>'
            . mcp_escapar($limite) . '</strong>. Se não houver turma marcada para começar até lá, devolvemos tudo, sem você precisar pedir. Se mudar de ideia antes, é só responder este e-mail.'),
        mcp_email_b_acompanhe($i),
    ]);
    return [
        'assunto' => "Você continua na fila de $curso até $limite",
        'html' => mcp_moldura("Você continua na fila, $nome.", $corpo['html'], mcp_email_opcoes_aluno("Nova data limite: $limite.")),
        'texto' => $corpo['texto'],
    ];
}

function mcp_email_espera_prorrogada(array $inscricao): string
{
    return mcp_email_espera_enviar($inscricao, mcp_montar_email_espera_prorrogada($inscricao), 'email_espera_prorrogada');
}

/** A primeira frase da devolução, pelo meio de pagamento (spec 10.6, F17). */
function mcp_email_frase_devolucao(array $i, ?int $agora = null): string
{
    $total = mcp_brl(mcp_email_total_pago_centavos($i));
    if (($i['metodo'] ?? 'pix') === 'pix') {
        $pago = mcp_email_quando($i['pago_em'] ?? null, true);
        $passou90 = $pago && $pago->modify('+90 days')->getTimestamp() < ($agora ?? time());
        return "Vamos devolver os $total que você pagou por PIX, em até 2 dias úteis. "
            . ($passou90 ? 'A devolução será por transferência PIX para uma conta no seu nome: responda este e-mail com a chave PIX.' : 'O valor volta para a conta de onde saiu o PIX.');
    }
    return "Vamos pedir o estorno dos $total que você pagou em até 2 dias úteis. Como ele aparece na fatura depende do banco emissor.";
}

/** "a taxa de inscrição, a matrícula, os juros do parcelamento e os opcionais", só com o que a compra teve. */
function mcp_email_o_que_volta(array $i): string
{
    $partes = ['a taxa de inscrição', 'a matrícula'];
    if (mcp_email_juros_centavos($i) > 0) {
        $partes[] = 'os juros do parcelamento';
    }
    if ((int) ($i['taxa_centavos'] ?? 0) > 0 || (int) ($i['divulgacao_centavos'] ?? 0) > 0) {
        $partes[] = 'os opcionais';
    }
    $ultimo = array_pop($partes);
    return implode(', ', $partes) . ' e ' . $ultimo;
}

/**
 * Devolução da compra sem turma (spec 10.6). $motivo: prazo, pedido, data_nao_serve, requisito, turma_cancelada,
 * secretaria ou feita (o postback "refunded" chegou). curso_ja_pago usa o e-mail de pagamento confirmado (1.14).
 */
function mcp_montar_email_espera_devolucao(array $i, string $motivo, ?int $agora = null): array
{
    $nome = mcp_primeiro_nome((string) $i['nome']);
    $n = mcp_escapar($nome);
    $curso = (string) $i['curso_nome'];
    $c = '<strong>' . mcp_escapar($curso) . '</strong>';
    $total = mcp_brl(mcp_email_total_pago_centavos($i));
    $frase = mcp_escapar(mcp_email_frase_devolucao($i, $agora));
    $titulo = "Vamos devolver o seu pagamento, $nome.";
    $turmaDefinida = ($i['espera_status'] ?? '') === 'turma' || !empty($i['espera_matricula_escola'])
        || (($i['espera_status'] ?? '') === 'devolver' && !empty($i['turma_id']) && (mcp_escola_acesso($i)['matricula_paga'] ?? null) === true);
    $dataTurma = mcp_email_turma_da_inscricao($i, true, $agora)['data'];
    $cancelaMatricula = $turmaDefinida ? ' A sua matrícula na turma' . ($dataTurma !== '' ? ' de ' . mcp_escapar($dataTurma) : '') . ' será cancelada.' : '';
    switch ($motivo) {
        case 'prazo':
            $limite = mcp_email_data_limite($i);
            $assunto = "A turma de $curso não foi marcada a tempo: vamos devolver tudo o que você pagou";
            $preheader = "$total de volta, sem você precisar pedir.";
            $texto = 'Oi, ' . $n . '. Combinamos que, se não houvesse turma de ' . $c . ' marcada para começar até ' . mcp_escapar($limite) . ', você receberia tudo de volta. A turma não foi marcada a tempo. '
                . $frase . ' A devolução inclui ' . mcp_escapar(mcp_email_o_que_volta($i)) . '. Quando a turma for marcada, você pode se inscrever de novo pela página de cursos.';
            break;
        case 'turma_cancelada':
            $assunto = "Vamos devolver o seu pagamento em $curso";
            $preheader = "$total de volta, sem você precisar pedir.";
            $texto = 'Oi, ' . $n . '. A turma de ' . $c . ' foi cancelada e não recebemos a sua escolha. ' . $frase;
            break;
        case 'feita':
            $assunto = "Devolução feita: $total em $curso";
            $titulo = "Devolução feita, $nome.";
            $preheader = "Os $total que você pagou estão voltando.";
            $texto = 'Oi, ' . $n . '. A devolução dos <strong>' . $total . '</strong> que você pagou em ' . $c . ' foi feita hoje. '
                . (($i['metodo'] ?? 'pix') === 'pix' ? 'O valor volta para a conta de onde saiu o PIX.' : 'Como ela aparece na fatura depende do banco emissor.');
            break;
        case 'secretaria':
            $assunto = "Vamos devolver o seu pagamento em $curso";
            $preheader = "$total de volta.";
            $texto = 'Oi, ' . $n . '. ' . $frase . $cancelaMatricula;
            break;
        default: // pedido, data_nao_serve, requisito
            $assunto = "Pedido de devolução recebido: $curso";
            $preheader = "$total de volta.";
            $texto = 'Oi, ' . $n . '. Recebemos o seu pedido. ' . $frase . $cancelaMatricula;
    }
    $corpo = mcp_email_juntar([mcp_email_b_p($texto), mcp_email_b_acompanhe($i)]);
    return [
        'assunto' => $assunto,
        'html' => mcp_moldura($titulo, $corpo['html'], mcp_email_opcoes_aluno($preheader)),
        'texto' => $corpo['texto'],
    ];
}

function mcp_email_espera_devolucao(array $inscricao, string $motivo): string
{
    return mcp_email_espera_enviar($inscricao, mcp_montar_email_espera_devolucao($inscricao, $motivo), $motivo === 'feita' ? 'email_espera_devolvido' : 'email_espera_devolucao');
}

// ----------------------------------------------------------------------------- chat de contato
function mcp_contato_assuntos(): array
{
    return [
        'matricula' => 'Matrícula em cursos', 'curso' => 'Dúvida sobre um curso', 'pagamento' => 'Pagamento ou PIX',
        'voluntariado' => 'Voluntariado', 'doacoes' => 'Campanha do Agasalho e parcerias', 'outro' => 'Outro assunto',
        // Só do formulário da página /dia-das-criancas/ (o chat não oferece este assunto).
        'brinquedos' => 'Doação de brinquedos (Dia das Crianças)',
    ];
}

/**
 * Por onde a mensagem chegou, para os textos dos e-mails. O chat é o canal de sempre; a doação de brinquedos
 * chega pelo formulário da página do Dia das Crianças.
 */
function mcp_contato_canal(string $assunto): array
{
    return $assunto === 'brinquedos'
        ? ['nome' => 'formulário de doação do Dia das Crianças', 'rotulo' => 'Formulário de doação',
            'site' => 'formulário de doação do Dia das Crianças, em cruzvermelhariodejaneiro.org']
        : ['nome' => 'chat do site', 'rotulo' => 'Chat do site', 'site' => 'chat de cruzvermelhariodejaneiro.org'];
}

function mcp_contato_assunto_rotulo(string $chave): string
{
    return mcp_contato_assuntos()[$chave] ?? mcp_contato_assuntos()['outro'];
}

/** Assuntos em que faz sentido perguntar o curso e oferecer a matrícula. */
function mcp_contato_com_curso(string $assunto): bool
{
    return in_array($assunto, ['matricula', 'curso', 'pagamento'], true);
}

/**
 * Aviso à equipe (EMAIL_CONTATO): a mensagem primeiro, depois as duas formas de responder (painel, com
 * registro, ou o próprio e-mail, que tem responder-para = a pessoa) e os dados do contato.
 * $c['link_painel'] (opcional) é o link assinado do painel para este contato.
 */
function mcp_montar_email_contato_equipe(array $c): array
{
    $assunto = mcp_contato_assunto_rotulo((string) $c['assunto']);
    $canal = mcp_contato_canal((string) $c['assunto']);
    $nome = (string) $c['nome'];
    $primeiro = mcp_primeiro_nome($nome);
    $protocolo = (string) ($c['protocolo'] ?? '');
    $email = (string) $c['email'];
    $telefone = (string) ($c['telefone'] ?? '');
    $curso = (string) ($c['curso_nome'] ?? '');
    $linkPainel = (string) ($c['link_painel'] ?? '');
    $mailto = 'mailto:' . $email . '?subject=' . rawurlencode("Re: $assunto · $protocolo");
    $quando = mcp_data_brt((string) ($c['criado_em'] ?? ''), 'd/m/Y \à\s H\hi');

    $linhas = [
        'Nome' => $nome,
        'E-mail' => ['html' => '<a href="mailto:' . mcp_escapar($email) . '" style="color:#cc0000;text-decoration:none">' . mcp_escapar($email) . '</a>'],
        'Telefone' => $telefone !== ''
            ? ['html' => '<a href="tel:+55' . mcp_escapar(mcp_digitos($telefone)) . '" style="color:#1a202c;text-decoration:none">' . mcp_escapar(mcp_telefone_bonito($telefone)) . '</a>']
            : 'não informado',
        'Assunto' => $assunto,
    ];
    if ($curso !== '') {
        $linhas['Curso'] = $curso;
    }
    $linhas['Página'] = $c['pagina'] ?: 'não informada';
    $linhas['Origem'] = trim(($c['utm_source'] ?? '') . ' ' . ($c['utm_campaign'] ?? '')) ?: 'direto';
    if ($quando !== '') {
        $linhas['Recebido em'] = $quando . ' (Brasília)';
    }
    $linhas['Protocolo'] = $protocolo;

    // O que o chat já respondeu antes do chamado: a equipe não precisa repetir, e saber o que a
    // pessoa leu e mesmo assim não resolveu costuma ser a parte mais útil da mensagem.
    $lidas = is_array($c['ja_respondido'] ?? null) ? $c['ja_respondido'] : [];
    $jaLido = $lidas
        ? mcp_subtitulo('Já respondido no chat, antes de escrever')
            . mcp_lista(array_map(static fn(string $q): string => mcp_escapar($q), $lidas))
            . mcp_nota('A pessoa leu estas respostas no chat e ainda assim abriu o chamado.')
        : '';

    $corpo = mcp_p('<strong>' . mcp_escapar($nome) . '</strong> escreveu pelo ' . mcp_escapar($canal['nome']) . ' sobre <strong>' . mcp_escapar(mb_strtolower($assunto)) . '</strong>'
            . ($curso !== '' ? ' (' . mcp_escapar($curso) . ')' : '') . '. A pessoa já foi avisada de que a resposta chega por e-mail em até ' . MCP_EMAIL_PRAZO . '.')
        . mcp_citacao((string) $c['mensagem'])
        . $jaLido
        . ($linkPainel !== ''
            ? mcp_botao($linkPainel, 'Responder no painel')
                . mcp_nota('Pelo painel a resposta sai no padrão visual do site, com o protocolo no assunto, e fica registrada com data e quem respondeu.')
            : '')
        . mcp_botao($mailto, 'Responder por e-mail', true)
        . mcp_nota('Responder este e-mail também funciona: a resposta vai direto para ' . mcp_escapar($primeiro) . ', mas não fica registrada no painel.')
        . mcp_subtitulo('Dados do contato')
        . mcp_caixa($linhas);

    $texto = "$nome escreveu pelo {$canal['nome']} sobre " . mb_strtolower($assunto) . ($curso !== '' ? " ($curso)" : '') . ".\n\nMensagem:\n{$c['mensagem']}\n\n"
        . ($linkPainel !== '' ? "Responder no painel: $linkPainel\n" : '')
        . "Responder por e-mail: $email (ou responda este e-mail).\n\n";
    foreach ($linhas as $rotulo => $valor) {
        $texto .= "$rotulo: " . (is_array($valor) ? ($rotulo === 'E-mail' ? $email : mcp_telefone_bonito($telefone)) : $valor) . "\n";
    }
    return [
        'assunto' => "[Site] $assunto: $nome" . ($curso !== '' ? " · $curso" : '') . ($protocolo !== '' ? " · $protocolo" : ''),
        'html' => mcp_moldura("Nova mensagem de $primeiro", $corpo, [
            'eyebrow' => $canal['rotulo'] . ($protocolo !== '' ? " · $protocolo" : ''),
            'preheader' => mb_substr((string) $c['mensagem'], 0, 140),
            'motivo' => 'Aviso automático do ' . $canal['site'] . ' para ' . mcp_email_contato_endereco() . '.',
        ]),
        'texto' => $texto,
    ];
}

/**
 * Confirmação a quem escreveu: deixa claro que a conversa segue por e-mail (prazo, remetente, protocolo,
 * spam), repete a mensagem e, se o assunto for curso, oferece a matrícula.
 */
function mcp_montar_email_contato_confirmacao(array $c): array
{
    $nome = mcp_primeiro_nome((string) $c['nome']);
    $assunto = mcp_contato_assunto_rotulo((string) $c['assunto']);
    $protocolo = (string) ($c['protocolo'] ?? '');
    $email = (string) $c['email'];
    $telefone = (string) ($c['telefone'] ?? '');
    $comCurso = mcp_contato_com_curso((string) $c['assunto']);
    $canal = mcp_contato_canal((string) $c['assunto']);
    $contato = mcp_email_contato_endereco();
    $remetente = mcp_email_endereco(mcp_email_remetente_contato());
    $inscricao = mcp_brl(mcp_inscricao_centavos());
    $quando = mcp_data_brt((string) ($c['criado_em'] ?? ''), 'd/m/Y \à\s H\hi');

    $linhas = ['Protocolo' => $protocolo, 'Assunto' => $assunto];
    if (!empty($c['curso_nome'])) {
        $linhas['Curso'] = $c['curso_nome'];
    }
    if ($quando !== '') {
        $linhas['Enviada em'] = $quando . ' (Brasília)';
    }
    $passos = [
        ['Respondemos por e-mail em até ' . MCP_EMAIL_PRAZO,
            'A resposta vai para <strong>' . mcp_escapar($email) . '</strong>, com o protocolo <strong>' . mcp_escapar($protocolo) . '</strong> no assunto.'
            . ($telefone !== '' ? ' Se for preciso, também podemos ligar para ' . mcp_escapar(mcp_telefone_bonito($telefone)) . '.' : '')],
        ['Fique de olho na caixa de entrada e no spam',
            'O remetente é <strong>' . mcp_escapar($remetente) . '</strong>. Salve <strong>' . mcp_escapar($contato) . '</strong> nos seus contatos para a resposta não se perder.'],
        ['Para continuar a conversa, responda o e-mail',
            'Daqui em diante tudo acontece por e-mail, sempre com o protocolo. Você não precisa enviar a mensagem de novo.'],
    ];
    // A ficha do curso, montada aqui a partir do catálogo: quem escreveu perguntando de um curso
    // recebe carga horária, escolaridade e valor junto da confirmação, sem esperar dois dias.
    $ficha = '';
    $dadosCurso = !empty($c['curso_slug']) ? mcp_curso((string) $c['curso_slug']) : null;
    if ($dadosCurso) {
        $itens = [];
        if (!empty($dadosCurso['carga_horaria'])) {
            $itens[] = '<strong>' . mcp_escapar((string) $dadosCurso['carga_horaria']) . '</strong> presenciais, na sede, no Centro do Rio';
        }
        if (!empty($dadosCurso['escolaridade'])) {
            $itens[] = 'Escolaridade mínima: <strong>' . mcp_escapar((string) $dadosCurso['escolaridade']) . '</strong>';
        }
        if (!empty($dadosCurso['valor_curso_centavos'])) {
            $itens[] = 'Matrícula à vista: <strong>' . mcp_brl((int) $dadosCurso['valor_curso_centavos']) . '</strong> · Taxa de inscrição: <strong>' . $inscricao . '</strong> · Total à vista: <strong>'
                . mcp_brl((int) $dadosCurso['valor_curso_centavos'] + mcp_inscricao_centavos()) . '</strong>';
        }
        $itens[] = 'Certificado da Cruz Vermelha Brasileira Rio de Janeiro';
        $ficha = mcp_subtitulo('Sobre o ' . (string) $c['curso_nome']) . mcp_lista($itens);
    }

    $corpo = mcp_p('Oi, ' . mcp_escapar($nome) . '. Sua mensagem chegou e já está com a nossa equipe. Guarde o protocolo <strong>' . mcp_escapar($protocolo) . '</strong>: ele identifica a sua conversa.')
        . $ficha
        . mcp_subtitulo('Como funciona a resposta')
        . mcp_passos($passos)
        . mcp_caixa($linhas)
        . mcp_subtitulo('Sua mensagem')
        . mcp_citacao((string) $c['mensagem']);
    $texto = "Oi, $nome. Sua mensagem chegou e já está com a nossa equipe. Protocolo: $protocolo.\n\n"
        . "Como funciona a resposta:\n1) Respondemos por e-mail em até " . MCP_EMAIL_PRAZO . ", para $email, com o protocolo no assunto."
        . ($telefone !== '' ? ' Se for preciso, também podemos ligar para ' . mcp_telefone_bonito($telefone) . '.' : '') . "\n"
        . "2) Fique de olho na caixa de entrada e no spam. O remetente é $remetente; salve $contato nos seus contatos.\n"
        . "3) Para continuar a conversa, responda o e-mail. Você não precisa enviar a mensagem de novo.\n\n"
        . "Assunto: $assunto" . (!empty($c['curso_nome']) ? "\nCurso: {$c['curso_nome']}" : '') . "\n\nSua mensagem:\n{$c['mensagem']}\n";
    if ($comCurso && !empty($c['curso_slug'])) {
        $link = mcp_site_url() . '/matricula-cursos-presenciais/checkout/?curso=' . rawurlencode((string) $c['curso_slug']);
        // Sem prometer o plano: com o pagar tudo desligado, ou sem turma, o checkout oferece só a taxa (spec 2.1).
        $corpo .= mcp_p('Já decidiu? A inscrição em <strong>' . mcp_escapar((string) $c['curso_nome']) . '</strong> é feita no site, por PIX ou cartão, e a tela de inscrição mostra as opções de pagamento do curso. A entrada na aula é liberada com a matrícula paga.')
            . mcp_botao($link, 'Inscrever-se em ' . (string) $c['curso_nome']);
        $texto .= "\nJá decidiu? A inscrição em {$c['curso_nome']} é feita no site, por PIX ou cartão, e a tela de inscrição mostra as opções de pagamento do curso. A entrada na aula é liberada com a matrícula paga. Inscrever-se: $link\n";
    } elseif ($comCurso) {
        $link = mcp_site_url() . '/matricula-cursos-presenciais/';
        $corpo .= mcp_p('Enquanto isso, você pode ver os cursos presenciais, valores e dúvidas frequentes na página de matrícula.')
            . mcp_botao($link, 'Ver cursos presenciais', true);
        $texto .= "\nCursos presenciais, valores e dúvidas frequentes: $link\n";
    }
    if ((string) $c['assunto'] === 'brinquedos') {
        $corpo .= mcp_p('Obrigado por ajudar a fazer o Dia das Crianças na Praça. A equipe responde para combinar a entrega da doação.');
        $texto .= "\nObrigado por ajudar a fazer o Dia das Crianças na Praça. A equipe responde para combinar a entrega da doação.\n";
    }
    $corpo .= mcp_nota('Não foi você quem enviou esta mensagem? Ignore este e-mail.');
    return [
        'assunto' => "Recebemos sua mensagem · $protocolo",
        'html' => mcp_moldura("Recebemos sua mensagem, $nome.", $corpo, [
            'eyebrow' => 'Atendimento por e-mail',
            'preheader' => "Protocolo $protocolo. A resposta chega por e-mail em até " . MCP_EMAIL_PRAZO . '.',
            'motivo' => 'Você recebeu este e-mail porque enviou uma mensagem pelo ' . $canal['site'] . '.',
        ]),
        'texto' => $texto,
    ];
}

/** Envia o aviso à equipe (responder-para = quem escreveu) e a confirmação à pessoa. Devolve o resultado dos dois. */
function mcp_email_contato(array $c): array
{
    $equipe = mcp_montar_email_contato_equipe($c);
    $r1 = mcp_enviar_email(mcp_email_contato_endereco(), $equipe['assunto'], $equipe['html'], $equipe['texto'], (string) $c['email']);
    $confirmacao = mcp_montar_email_contato_confirmacao($c);
    $r2 = mcp_enviar_email((string) $c['email'], $confirmacao['assunto'], $confirmacao['html'], $confirmacao['texto']);
    return ['equipe' => $r1, 'confirmacao' => $r2];
}

// ----------------------------------------------------------------------------- painel: resposta e acesso
/** Resposta da equipe a um contato, escrita no painel: o texto, a assinatura, a mensagem original e o caminho de volta. */
function mcp_montar_email_resposta_contato(array $c, string $resposta, string $assinatura): array
{
    $nome = mcp_primeiro_nome((string) $c['nome']);
    $assunto = mcp_contato_assunto_rotulo((string) $c['assunto']);
    $protocolo = (string) ($c['protocolo'] ?? '');
    $comCurso = mcp_contato_com_curso((string) $c['assunto']);
    $corpo = '<div style="font-size:16px;line-height:1.6;color:#1a202c">' . nl2br(mcp_escapar($resposta)) . '</div>'
        . '<p style="margin:22px 0 0;font-size:15px;line-height:1.5;color:#1a202c"><strong>' . mcp_escapar($assinatura) . '</strong><br><span style="color:#718096">Equipe Cruz Vermelha Brasileira Rio de Janeiro · Atendimento por e-mail</span></p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#fff7f7" style="margin:22px 0 0;background:#fff7f7;border:1px solid #f5c2c7;border-radius:12px"><tr><td style="padding:12px 16px;font-size:14px;line-height:1.5;color:#1a202c">'
        . 'Ficou alguma dúvida? <strong>Responda este e-mail</strong> e a conversa continua por aqui, sempre com o protocolo <strong>' . mcp_escapar($protocolo) . '</strong>.</td></tr></table>';
    $texto = "$resposta\n\n$assinatura\nEquipe Cruz Vermelha Brasileira Rio de Janeiro · Atendimento por e-mail\n\nFicou alguma dúvida? Responda este e-mail (protocolo $protocolo).\n";
    if ($comCurso && !empty($c['curso_slug'])) {
        $link = mcp_site_url() . '/matricula-cursos-presenciais/checkout/?curso=' . rawurlencode((string) $c['curso_slug']);
        $corpo .= mcp_botao($link, 'Inscrever-se em ' . (string) $c['curso_nome'], true);
        $texto .= "\nInscrever-se em {$c['curso_nome']}: $link\n";
    }
    $corpo .= mcp_subtitulo('Sua mensagem')
        . mcp_citacao((string) $c['mensagem']);
    $texto .= "\nSua mensagem:\n{$c['mensagem']}\n";
    return [
        'assunto' => "Resposta da Cruz Vermelha Brasileira Rio de Janeiro · $protocolo",
        'html' => mcp_moldura("Respondemos sua mensagem, $nome.", $corpo, [
            'eyebrow' => 'Atendimento por e-mail · ' . $assunto,
            'preheader' => mb_substr(preg_replace('/\s+/u', ' ', $resposta) ?? '', 0, 140),
            'motivo' => "Você recebeu este e-mail porque enviou uma mensagem pelo chat de cruzvermelhariodejaneiro.org (protocolo $protocolo).",
        ]),
        'texto' => $texto,
    ];
}

/** Envia a resposta ao cliente pelo remetente de contato; responder-para é EMAIL_CONTATO. */
function mcp_email_resposta_contato(array $c, string $resposta, string $assinatura): string
{
    $m = mcp_montar_email_resposta_contato($c, $resposta, $assinatura);
    return mcp_enviar_email((string) $c['email'], $m['assunto'], $m['html'], $m['texto'], null, mcp_email_remetente_contato());
}

/** Link de entrada no painel (vale 20 minutos). */
function mcp_montar_email_painel_link(string $link): array
{
    $corpo = mcp_p('Clique no botão para entrar no portal da secretaria (inscrições, horários dos alunos e mensagens do chat). O link vale por <strong>' . MCP_PAINEL_LINK_ENTRADA_MINUTOS . ' minutos</strong> e abre uma sessão de ' . MCP_PAINEL_SESSAO_HORAS . ' horas neste navegador.')
        . mcp_botao($link, 'Entrar no portal')
        . mcp_nota('Se não foi você quem pediu, ignore este e-mail: nada acontece sem o clique.');
    return [
        'assunto' => 'Acesso ao portal da secretaria',
        'html' => mcp_moldura('Seu link de acesso ao portal', $corpo, ['eyebrow' => 'Portal da secretaria', 'motivo' => 'Pedido feito em ' . mcp_painel_url() . '.']),
        'texto' => "Entrar no portal da secretaria (vale " . MCP_PAINEL_LINK_ENTRADA_MINUTOS . " minutos): $link\n\nSe não foi você quem pediu, ignore este e-mail.",
    ];
}

function mcp_email_painel_link(string $email): string
{
    $m = mcp_montar_email_painel_link(mcp_painel_link_entrada($email));
    return mcp_enviar_email($email, $m['assunto'], $m['html'], $m['texto']);
}
