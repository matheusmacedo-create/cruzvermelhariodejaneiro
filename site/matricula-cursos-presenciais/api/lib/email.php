<?php
/**
 * E-mails transacionais no padrão visual do site (logo, faixa vermelha, Inter, rodapé institucional).
 *
 * Componentes: moldura, botão, caixa de valores, passos numerados, bloco do PIX, citação. Envio por
 * Resend com fallback em mail(). Mensagens: PIX aberto (recuperação do pagamento), inscrição paga
 * (versão A com acesso da escola, versão B sem API), aviso à secretaria e as duas do chat de contato
 * (aviso à equipe e confirmação a quem escreveu).
 *
 * Cada mensagem tem uma função pura mcp_montar_email_*() que devolve ['assunto', 'html', 'texto'],
 * testável e pré-visualizável (scripts/previsualizar_emails.php), e uma mcp_email_*() que envia.
 * Desde 19/09/2026 nenhum e-mail cita WhatsApp: o contato é por e-mail (EMAIL_CONTATO), com
 * responder-para apontando para ele, e o chat do site fica em <site>/#chat.
 */
declare(strict_types=1);

const MCP_EMAIL_FONTE = "Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
const MCP_EMAIL_PRAZO = '2 dias úteis';
const MCP_EMAIL_CNPJ = '08.560.973/0001-97';
const MCP_NOME_FILIAL = 'Cruz Vermelha Brasileira Rio de Janeiro';
/** Nomes curtos que aparecem em configurações antigas (EMAIL_REMETENTE): o remetente sai sempre com o nome completo. */
const MCP_NOMES_CURTOS = ['cruz vermelha', 'cruz vermelha rj', 'cruz vermelha brasileira', 'cruz vermelha brasileira rj', 'cruz vermelha brasileira - rj', 'cruz vermelha brasileira – rj', 'cvb-rj', 'cvb rj', 'cvb'];
const MCP_TEXTO_ESTORNO = 'A inscrição reserva sua vaga. Se não houver horário compatível ou você desistir antes da confirmação da aula, o valor é estornado. O prazo para aparecer na conta depende de PIX ou cartão.';

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
 * CNPJ e endereço. Opções: eyebrow (chapéu), preheader (linha de prévia na caixa de entrada) e
 * motivo (por que a pessoa recebeu). Tabelas e CSS em linha para Gmail, Outlook e Apple Mail.
 */
function mcp_moldura(string $titulo, string $corpo, array $opcoes = []): string
{
    $t = mcp_escapar($titulo);
    $eyebrow = mcp_escapar((string) ($opcoes['eyebrow'] ?? 'Cruz Vermelha Brasileira · Rio de Janeiro'));
    $preheader = (string) ($opcoes['preheader'] ?? '');
    $motivo = (string) ($opcoes['motivo'] ?? '');
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
        . "<p style=\"margin:0 0 4px\">Cruz Vermelha Brasileira · Filial do Estado do Rio de Janeiro · CNPJ " . MCP_EMAIL_CNPJ . "</p>"
        . "<p style=\"margin:0\">Praça da Cruz Vermelha, 10 · Centro · Rio de Janeiro · RJ · CEP 20230-130</p></td></tr>"
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
 */
function mcp_caixa(array $linhas, array $total = []): string
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
    return $html . '</table></td></tr></table>';
}

/** Passos numerados (círculo vermelho, título e texto). $passos = [[título, html], ...]. */
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
 */
function mcp_enviar_email(string $para, string $assunto, string $html, string $texto, ?string $responderPara = null, ?string $remetente = null): string
{
    $remetente = mcp_email_nome_oficial($remetente ?? (string) mcp_cfg('EMAIL_REMETENTE', MCP_NOME_FILIAL . ' <matricula@cruzvermelhariodejaneiro.org>'));
    $resposta = $responderPara ?? (string) mcp_cfg('EMAIL_RESPOSTA', mcp_email_contato_endereco());
    if (!filter_var($resposta, FILTER_VALIDATE_EMAIL)) {
        $resposta = '';
    }
    $chave = (string) mcp_cfg('RESEND_API_KEY', '');
    if ($chave !== '') {
        $corpo = ['from' => $remetente, 'to' => [$para], 'subject' => $assunto, 'html' => $html, 'text' => $texto];
        if ($resposta !== '') {
            $corpo['reply_to'] = $resposta;
        }
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($corpo, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $chave, 'Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $r = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($r !== false && $status < 300) {
            return 'resend';
        }
        error_log("[matricula] Resend falhou ($status): " . mb_substr((string) $r, 0, 300));
    }
    // Fallback: mail() local. Cabeçalhos só com valores da configuração ou e-mails validados.
    $enderecoRemetente = preg_match('/<([^>]+)>/', $remetente, $m) ? $m[1] : $remetente;
    $cabecalhos = "From: $remetente\r\n" . ($resposta !== '' ? "Reply-To: $resposta\r\n" : '')
        . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
    $assuntoCodificado = '=?UTF-8?B?' . base64_encode($assunto) . '?=';
    $ok = @mail($para, $assuntoCodificado, $html, $cabecalhos, '-f' . $enderecoRemetente);
    return $ok ? 'mail' : 'falhou';
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

/**
 * E-mail de recuperação: sai no momento em que o PIX é gerado e é o caminho de volta de quem fechou
 * a página. Copy orientada à conclusão: o que falta, quanto é, botão único, código, validade e o que
 * acontece depois. Puro: devolve assunto, html e texto.
 */
function mcp_montar_email_pix_aberto(array $inscricao): array
{
    $nome = mcp_primeiro_nome((string) $inscricao['nome']);
    $curso = (string) $inscricao['curso_nome'];
    $total = mcp_brl((int) $inscricao['total_centavos']);
    $taxa = (int) ($inscricao['taxa_centavos'] ?? 0);
    $codigo = (string) ($inscricao['pix_copia_cola'] ?? '');
    $link = mcp_url_pagina('pendente', (string) $inscricao['token']) . '&utm_source=email&utm_medium=transacional&utm_campaign=pix-aberto';
    $validade = mcp_pix_validade($inscricao);
    $ateQuando = $validade !== '' ? " (até $validade)" : '';

    $linhas = ['Curso' => $curso, 'Inscrição' => mcp_brl((int) $inscricao['inscricao_centavos'])];
    if ($taxa > 0) {
        $linhas['Custos de processamento (você escolheu cobrir)'] = mcp_brl($taxa);
    }
    $corpo = mcp_p('Oi, ' . mcp_escapar($nome) . '. Sua inscrição em <strong>' . mcp_escapar($curso) . '</strong> já está aberta: só falta o pagamento do PIX para a vaga ficar garantida. Assim que ele cair, você recebe a confirmação por e-mail, na hora.')
        . mcp_caixa($linhas, ['Total do PIX' => $total])
        . mcp_botao($link, 'Concluir pagamento')
        . mcp_nota('Pelo botão você vê o QR code e acompanha a confirmação em tempo real. Ou pague agora com o código:')
        . mcp_bloco_pix($codigo)
        . mcp_p('<strong>O código vale por 24 horas' . mcp_escapar($ateQuando) . '.</strong> Passou do prazo? Gere outro pelo mesmo botão, sem custo.')
        . mcp_subtitulo('O que acontece depois')
        . mcp_passos([
            ['Pagamento confirmado na hora', 'O comprovante chega neste e-mail e sua vaga fica reservada.'],
            ['A secretaria confirma turma e horário', 'A Escola de Educação e Saúde CVB-RJ entra em contato por e-mail em até ' . MCP_EMAIL_PRAZO . '. Você não precisa se inscrever de novo.'],
            ['O valor do curso é pago depois', 'Direto na plataforma da escola, quando a turma estiver confirmada.'],
        ])
        . mcp_nota(mcp_escapar(MCP_TEXTO_ESTORNO));
    $texto = "Oi, $nome. Sua inscrição em $curso já está aberta: só falta o pagamento do PIX de $total para a vaga ficar garantida.\n\n"
        . "Concluir pagamento (QR code e acompanhamento em tempo real): $link\n\n"
        . "PIX copia e cola:\n$codigo\n\n"
        . "O código vale por 24 horas$ateQuando. Passou do prazo? Gere outro pelo mesmo link, sem custo.\n\n"
        . "O que acontece depois: 1) pagamento confirmado na hora, comprovante neste e-mail; 2) a secretaria da Escola entra em contato por e-mail em até " . MCP_EMAIL_PRAZO
        . " para confirmar turma e horário; 3) o valor do curso é pago depois, na plataforma da escola.\n\n" . MCP_TEXTO_ESTORNO
        . "\n\nDúvidas? Responda este e-mail ou escreva para " . mcp_email_contato_endereco() . '.';
    return [
        'assunto' => "Falta só o PIX para garantir sua vaga em $curso",
        'html' => mcp_moldura("Falta só o PIX, $nome.", $corpo, [
            'eyebrow' => 'Matrícula cursos presenciais',
            'preheader' => "Seu código de $total vale por 24 horas. Pagamento confirmado na hora, comprovante por e-mail.",
            'motivo' => 'Você recebeu este e-mail porque iniciou uma matrícula em cruzvermelhariodejaneiro.org com este endereço.',
        ]),
        'texto' => $texto,
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
 * Pagamento confirmado. Versão A (com acesso devolvido pela escola): usuário, senha e link.
 * Versão B (sem API da escola): comprovante e os próximos passos, com a secretaria escrevendo por e-mail.
 * Devolve também 'tipo' ('acesso' ou 'confirmacao') para o registro.
 */
function mcp_montar_email_aluno_pago(array $inscricao): array
{
    $nome = mcp_primeiro_nome((string) $inscricao['nome']);
    $curso = (string) $inscricao['curso_nome'];
    $total = mcp_brl((int) $inscricao['total_centavos']);
    $taxa = (int) ($inscricao['taxa_centavos'] ?? 0);
    $escolaUrl = (string) mcp_cfg('ESCOLA_URL', 'https://escola.cursoscruzvermelha.org');
    $acesso = mcp_escola_acesso($inscricao);
    $linkParabens = mcp_url_pagina('parabens', (string) $inscricao['token']);
    $pagoPor = ($inscricao['metodo'] ?? 'pix') === 'pix' ? 'PIX' : 'Cartão' . (!empty($inscricao['ultimos4']) ? ' final ' . $inscricao['ultimos4'] : '');
    $quando = mcp_data_brt((string) ($inscricao['pago_em'] ?? '') ?: (string) ($inscricao['criado_em'] ?? ''), 'd/m/Y \à\s H\hi');

    $linhas = ['Curso' => $curso, 'Inscrição' => mcp_brl((int) $inscricao['inscricao_centavos'])];
    if ($taxa > 0) {
        $linhas['Custos de processamento (você escolheu cobrir)'] = mcp_brl($taxa);
    }
    $linhas['Pago por'] = $pagoPor;
    if ($quando !== '') {
        $linhas['Data'] = $quando . ' (Brasília)';
    }
    $caixa = mcp_caixa($linhas, ['Total pago' => $total]);
    $obrigado = $taxa > 0 ? ' Obrigado por cobrir os custos de processamento: assim a Cruz Vermelha recebe a inscrição integral.' : '';

    if ($acesso) {
        $usuario = (string) ($acesso['usuario'] ?? $inscricao['email']);
        $url = (string) ($acesso['acesso']['url'] ?? $acesso['url_ambiente'] ?? $escolaUrl);
        $senha = isset($acesso['acesso']['senha']) ? (string) $acesso['acesso']['senha'] : '';
        $corpo = mcp_p('Parabéns, ' . mcp_escapar($nome) . '. Recebemos o pagamento da sua inscrição em <strong>' . mcp_escapar($curso) . '</strong> e aqui está seu acesso à secretaria da escola.' . $obrigado)
            . $caixa
            . '<div style="margin:18px 0;padding:14px 18px;background:#f7f8fa;border:1px solid #e2e8f0;border-radius:12px;font-size:15px">Usuário: <strong>' . mcp_escapar($usuario) . '</strong>'
            . ($senha !== '' ? '<br>Senha temporária: <strong>' . mcp_escapar($senha) . '</strong> <span style="color:#718096">(troque no primeiro acesso)</span>' : '') . '</div>'
            . mcp_botao($url, 'Acessar ambiente da secretaria')
            . mcp_p('Horário e turma você escolhe lá.')
            . mcp_nota(mcp_escapar(MCP_TEXTO_ESTORNO));
        $texto = "Parabéns, $nome. Recebemos o pagamento ($total) da sua inscrição em $curso.\nUsuário: $usuario" . ($senha !== '' ? "\nSenha temporária: $senha (troque no primeiro acesso)" : '')
            . "\nAcesso: $url\n\nHorário e turma você escolhe lá. " . MCP_TEXTO_ESTORNO;
        return ['tipo' => 'acesso', 'assunto' => "Seu acesso à secretaria da escola — $curso", 'texto' => $texto,
            'html' => mcp_moldura("Vaga garantida, $nome: aqui está seu acesso", $corpo, [
                'eyebrow' => 'Matrícula cursos presenciais',
                'preheader' => "Pagamento de $total confirmado. Usuário e acesso à secretaria da escola neste e-mail.",
                'motivo' => 'Você recebeu este e-mail porque pagou uma inscrição em cruzvermelhariodejaneiro.org com este endereço.',
            ])];
    }

    $corpo = mcp_p('Parabéns, ' . mcp_escapar($nome) . '. Recebemos o pagamento da sua inscrição em <strong>' . mcp_escapar($curso) . '</strong>. Sua vaga está reservada e este e-mail é o seu comprovante.' . $obrigado)
        . $caixa
        . mcp_subtitulo('Próximos passos')
        . mcp_passos([
            ['Inscrição paga', 'Feito. Sua vaga está reservada e você não precisa se inscrever de novo na plataforma.'],
            ['A secretaria confirma turma e horário', 'A Escola de Educação e Saúde CVB-RJ entra em contato <strong>por e-mail em até ' . MCP_EMAIL_PRAZO . '</strong> para confirmar turma, data e horário. Fique de olho na caixa de entrada e no spam.'],
            ['O valor do curso é pago depois', 'Direto na <a href="' . mcp_escapar($escolaUrl) . '" style="color:#cc0000">plataforma da escola</a>, quando a turma estiver confirmada.'],
        ])
        . mcp_botao($linkParabens, 'Ver minha inscrição')
        . mcp_nota(mcp_escapar(MCP_TEXTO_ESTORNO));
    $texto = "Parabéns, $nome. Recebemos o pagamento ($total) da sua inscrição em $curso. Sua vaga está reservada e este e-mail é o seu comprovante.\n\n"
        . "Próximos passos: 1) inscrição paga, não precisa se inscrever de novo; 2) a secretaria da Escola entra em contato por e-mail em até " . MCP_EMAIL_PRAZO
        . " para confirmar turma, data e horário; 3) o valor do curso é pago depois, na plataforma da escola ($escolaUrl).\n\nMinha inscrição: $linkParabens\n\n" . MCP_TEXTO_ESTORNO;
    return ['tipo' => 'confirmacao', 'assunto' => "Inscrição confirmada: sua vaga em $curso", 'texto' => $texto,
        'html' => mcp_moldura("Vaga garantida, $nome!", $corpo, [
            'eyebrow' => 'Matrícula cursos presenciais',
            'preheader' => "Pagamento de $total confirmado. A secretaria escreve em até " . MCP_EMAIL_PRAZO . ' para confirmar turma e horário.',
            'motivo' => 'Você recebeu este e-mail porque pagou uma inscrição em cruzvermelhariodejaneiro.org com este endereço.',
        ])];
}

function mcp_email_aluno_pago(array $inscricao): void
{
    $m = mcp_montar_email_aluno_pago($inscricao);
    $r = mcp_enviar_email((string) $inscricao['email'], $m['assunto'], $m['html'], $m['texto']);
    mcp_atualizar((int) $inscricao['id'], ['email_aluno' => $r === 'falhou' ? 'falhou' : $m['tipo']]);
    mcp_registrar((int) $inscricao['id'], 'email_aluno', "{$m['tipo']} · $r");
}

// ----------------------------------------------------------------------------- aviso à secretaria
function mcp_montar_email_secretaria(array $inscricao): array
{
    $pago = mcp_brl((int) $inscricao['total_centavos']) . ' (inscrição ' . mcp_brl((int) $inscricao['inscricao_centavos'])
        . ((int) $inscricao['taxa_centavos'] > 0 ? ' + custos ' . mcp_brl((int) $inscricao['taxa_centavos']) : '') . ')';
    $linhas = [
        'Curso' => $inscricao['curso_nome'],
        'Aluno' => $inscricao['nome'],
        'CPF' => $inscricao['cpf'],
        'E-mail' => $inscricao['email'],
        'Telefone' => $inscricao['telefone'],
        'Pago' => $pago,
        'Método' => $inscricao['metodo'] === 'pix' ? 'PIX' : 'Cartão ' . ($inscricao['bandeira'] ?? '') . ' final ' . ($inscricao['ultimos4'] ?? ''),
        'Transação Unicopag' => $inscricao['unicopag_hash'],
        'Origem' => trim(($inscricao['utm_source'] ?? '') . ' ' . ($inscricao['utm_campaign'] ?? '')) ?: 'direto',
        'Escola' => mcp_escola_configurada() ? $inscricao['escola_status'] : 'sem API: criar a matrícula e aplicar o valor da inscrição',
    ];
    $texto = '';
    foreach ($linhas as $rotulo => $valor) {
        $texto .= "$rotulo: $valor\n";
    }
    $corpo = mcp_p('Nova inscrição paga pela página de matrícula. Entrar em contato com o aluno <strong>por e-mail em até ' . MCP_EMAIL_PRAZO . '</strong> para fechar turma e horário: basta responder este e-mail, que vai direto para ele.')
        . mcp_caixa($linhas);
    return [
        'assunto' => 'Inscrição paga: ' . $inscricao['nome'] . ' — ' . $inscricao['curso_nome'],
        'html' => mcp_moldura('Nova inscrição paga', $corpo, ['eyebrow' => 'Aviso interno · matrícula cursos presenciais', 'motivo' => 'Aviso automático do checkout de cruzvermelhariodejaneiro.org para a secretaria.']),
        'texto' => $texto,
    ];
}

/** Desligado enquanto EMAIL_SECRETARIA estiver vazio. Responder-para é o aluno. */
function mcp_email_secretaria(array $inscricao): void
{
    $para = (string) mcp_cfg('EMAIL_SECRETARIA', '');
    if ($para === '') {
        return;
    }
    $m = mcp_montar_email_secretaria($inscricao);
    $r = mcp_enviar_email($para, $m['assunto'], $m['html'], $m['texto'], (string) $inscricao['email']);
    mcp_atualizar((int) $inscricao['id'], ['email_secretaria' => $r]);
    mcp_registrar((int) $inscricao['id'], 'email_secretaria', $r);
}

// ----------------------------------------------------------------------------- chat de contato
function mcp_contato_assuntos(): array
{
    return [
        'matricula' => 'Matrícula em cursos', 'curso' => 'Dúvida sobre um curso', 'pagamento' => 'Pagamento ou PIX',
        'voluntariado' => 'Voluntariado', 'doacoes' => 'Doações e parcerias', 'outro' => 'Outro assunto',
    ];
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

    $corpo = mcp_p('<strong>' . mcp_escapar($nome) . '</strong> escreveu pelo chat do site sobre <strong>' . mcp_escapar(mb_strtolower($assunto)) . '</strong>'
            . ($curso !== '' ? ' (' . mcp_escapar($curso) . ')' : '') . '. A pessoa já foi avisada de que a resposta chega por e-mail em até ' . MCP_EMAIL_PRAZO . '.')
        . mcp_citacao((string) $c['mensagem'])
        . ($linkPainel !== ''
            ? mcp_botao($linkPainel, 'Responder no painel')
                . mcp_nota('Pelo painel a resposta sai no padrão visual do site, com o protocolo no assunto, e fica registrada com data e quem respondeu.')
            : '')
        . mcp_botao($mailto, 'Responder por e-mail', true)
        . mcp_nota('Responder este e-mail também funciona: a resposta vai direto para ' . mcp_escapar($primeiro) . ', mas não fica registrada no painel.')
        . mcp_subtitulo('Dados do contato')
        . mcp_caixa($linhas);

    $texto = "$nome escreveu pelo chat do site sobre " . mb_strtolower($assunto) . ($curso !== '' ? " ($curso)" : '') . ".\n\nMensagem:\n{$c['mensagem']}\n\n"
        . ($linkPainel !== '' ? "Responder no painel: $linkPainel\n" : '')
        . "Responder por e-mail: $email (ou responda este e-mail).\n\n";
    foreach ($linhas as $rotulo => $valor) {
        $texto .= "$rotulo: " . (is_array($valor) ? ($rotulo === 'E-mail' ? $email : mcp_telefone_bonito($telefone)) : $valor) . "\n";
    }
    return [
        'assunto' => "[Site] $assunto: $nome" . ($curso !== '' ? " · $curso" : '') . ($protocolo !== '' ? " · $protocolo" : ''),
        'html' => mcp_moldura("Nova mensagem de $primeiro", $corpo, [
            'eyebrow' => 'Chat do site' . ($protocolo !== '' ? " · $protocolo" : ''),
            'preheader' => mb_substr((string) $c['mensagem'], 0, 140),
            'motivo' => 'Aviso automático do chat de cruzvermelhariodejaneiro.org para ' . mcp_email_contato_endereco() . '.',
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
    $corpo = mcp_p('Oi, ' . mcp_escapar($nome) . '. Sua mensagem chegou e já está com a nossa equipe. Guarde o protocolo <strong>' . mcp_escapar($protocolo) . '</strong>: ele identifica a sua conversa.')
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
        $corpo .= mcp_p('Já decidiu? Dá para garantir a vaga agora: a inscrição de <strong>' . $inscricao . '</strong> reserva seu lugar em <strong>' . mcp_escapar((string) $c['curso_nome']) . '</strong>, e a secretaria confirma turma e horário depois.')
            . mcp_botao($link, 'Fazer matrícula em ' . (string) $c['curso_nome']);
        $texto .= "\nJá decidiu? A inscrição de $inscricao reserva sua vaga em {$c['curso_nome']}: $link\n";
    } elseif ($comCurso) {
        $link = mcp_site_url() . '/matricula-cursos-presenciais/';
        $corpo .= mcp_p('Enquanto isso, você pode ver os cursos presenciais, valores e dúvidas frequentes na página de matrícula.')
            . mcp_botao($link, 'Ver cursos presenciais', true);
        $texto .= "\nCursos presenciais, valores e dúvidas frequentes: $link\n";
    }
    $corpo .= mcp_nota('Não foi você quem enviou esta mensagem? Ignore este e-mail.');
    return [
        'assunto' => "Recebemos sua mensagem · $protocolo",
        'html' => mcp_moldura("Recebemos sua mensagem, $nome.", $corpo, [
            'eyebrow' => 'Atendimento por e-mail',
            'preheader' => "Protocolo $protocolo. A resposta chega por e-mail em até " . MCP_EMAIL_PRAZO . '.',
            'motivo' => 'Você recebeu este e-mail porque enviou uma mensagem pelo chat de cruzvermelhariodejaneiro.org.',
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
        $corpo .= mcp_botao($link, 'Fazer matrícula em ' . (string) $c['curso_nome'], true);
        $texto .= "\nFazer matrícula em {$c['curso_nome']}: $link\n";
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
    $corpo = mcp_p('Clique no botão para entrar no painel de contatos do chat. O link vale por <strong>' . MCP_PAINEL_LINK_ENTRADA_MINUTOS . ' minutos</strong> e abre uma sessão de ' . MCP_PAINEL_SESSAO_HORAS . ' horas neste navegador.')
        . mcp_botao($link, 'Entrar no painel')
        . mcp_nota('Se não foi você quem pediu, ignore este e-mail: nada acontece sem o clique.');
    return [
        'assunto' => 'Acesso ao painel de contatos',
        'html' => mcp_moldura('Seu link de acesso ao painel', $corpo, ['eyebrow' => 'Painel de contatos', 'motivo' => 'Pedido feito em ' . mcp_painel_url() . '.']),
        'texto' => "Entrar no painel de contatos (vale " . MCP_PAINEL_LINK_ENTRADA_MINUTOS . " minutos): $link\n\nSe não foi você quem pediu, ignore este e-mail.",
    ];
}

function mcp_email_painel_link(string $email): string
{
    $m = mcp_montar_email_painel_link(mcp_painel_link_entrada($email));
    return mcp_enviar_email($email, $m['assunto'], $m['html'], $m['texto']);
}
