<?php
/** E-mails transacionais: moldura HTML, envio (Resend com fallback em mail()) e as três mensagens. */
declare(strict_types=1);

function mcp_moldura(string $titulo, string $corpo): string
{
    $t = mcp_escapar($titulo);
    $whats = (string) mcp_cfg('WHATSAPP_SECRETARIA', '');
    $rodape = $whats !== '' ? 'Dúvidas? Fale com a secretaria no WhatsApp +' . mcp_escapar($whats) . '.' : '';
    return "<!doctype html><html lang=\"pt-BR\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width\"><title>$t</title></head>"
        . "<body style=\"margin:0;padding:24px 12px;background:#f7f8fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:#1a202c;line-height:1.5\">"
        . "<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0\">"
        . "<tr><td style=\"height:6px;background:#cc0000;font-size:0;line-height:0\">&nbsp;</td></tr>"
        . "<tr><td style=\"padding:28px 28px 8px\"><p style=\"margin:0 0 4px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#cc0000;font-weight:700\">Cruz Vermelha Brasileira · Rio de Janeiro</p>"
        . "<h1 style=\"margin:0;font-size:22px;line-height:1.2\">$t</h1></td></tr>"
        . "<tr><td style=\"padding:8px 28px 28px;font-size:15px\">$corpo</td></tr>"
        . "<tr><td style=\"padding:18px 28px;border-top:1px solid #e2e8f0;font-size:12px;color:#718096\"><p style=\"margin:0 0 6px\">$rodape</p><p style=\"margin:0\">Praça da Cruz Vermelha, 10 · Centro · Rio de Janeiro</p></td></tr>"
        . "</table></body></html>";
}

function mcp_botao(string $url, string $rotulo): string
{
    return '<p style="margin:22px 0"><a href="' . mcp_escapar($url) . '" style="display:inline-block;background:#cc0000;color:#ffffff;text-decoration:none;font-weight:700;padding:14px 22px;border-radius:999px">' . mcp_escapar($rotulo) . '</a></p>';
}

function mcp_p(string $html): string
{
    return '<p style="margin:0 0 12px">' . $html . '</p>';
}

/**
 * Envia por Resend (quando há chave) ou pelo mail() da Hostinger. Devolve 'resend', 'mail' ou
 * 'falhou'. Falha de e-mail nunca derruba a requisição: o pagamento já aconteceu.
 */
function mcp_enviar_email(string $para, string $assunto, string $html, string $texto): string
{
    $remetente = (string) mcp_cfg('EMAIL_REMETENTE', 'matricula@cruzvermelhariodejaneiro.org');
    $resposta = (string) mcp_cfg('EMAIL_RESPOSTA', '');
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
    // Fallback: mail() local. Cabeçalhos montados só com valores da configuração, nunca do aluno.
    $enderecoRemetente = preg_match('/<([^>]+)>/', $remetente, $m) ? $m[1] : $remetente;
    $cabecalhos = "From: $remetente\r\n" . ($resposta !== '' ? "Reply-To: $resposta\r\n" : '')
        . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
    $assuntoCodificado = '=?UTF-8?B?' . base64_encode($assunto) . '?=';
    $ok = @mail($para, $assuntoCodificado, $html, $cabecalhos, '-f' . $enderecoRemetente);
    return $ok ? 'mail' : 'falhou';
}

/** PIX gerado: manda o copia e cola e o link da tela de acompanhamento. */
function mcp_email_pix_aberto(array $inscricao): void
{
    if (empty($inscricao['pix_copia_cola'])) {
        return;
    }
    $nome = mcp_primeiro_nome((string) $inscricao['nome']);
    $curso = (string) $inscricao['curso_nome'];
    $total = mcp_brl((int) $inscricao['total_centavos']);
    $link = mcp_url_pagina('pendente', (string) $inscricao['token']);
    $corpo = mcp_p('Oi, ' . mcp_escapar($nome) . '. Sua inscrição no curso <strong>' . mcp_escapar($curso) . '</strong> está aberta e o código PIX abaixo confirma sua vaga.')
        . mcp_p('Valor: <strong>' . $total . '</strong>.')
        . '<p style="margin:0 0 6px;font-size:13px;color:#718096">PIX copia e cola:</p>'
        . '<p style="margin:0 0 12px;padding:12px;background:#f7f8fa;border:1px solid #e2e8f0;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;word-break:break-all">' . mcp_escapar((string) $inscricao['pix_copia_cola']) . '</p>'
        . mcp_p('Abra o aplicativo do seu banco, escolha PIX Copia e Cola e conclua o pagamento. O código vale por 24 horas.')
        . mcp_botao($link, 'Voltar para o pagamento');
    $texto = "$nome, sua inscrição no curso $curso está aberta. Valor: $total.\n\nPIX copia e cola:\n{$inscricao['pix_copia_cola']}\n\nVoltar para o pagamento: $link";
    $r = mcp_enviar_email((string) $inscricao['email'], "Seu PIX da inscrição — $curso", mcp_moldura('Sua inscrição está aberta', $corpo), $texto);
    mcp_registrar((int) $inscricao['id'], 'email_pix', $r);
}

/**
 * Pagamento confirmado. Versão A (com acesso devolvido pela escola): usuário, senha e link.
 * Versão B (sem API da escola): confirmação e aviso de que a secretaria chama no WhatsApp.
 */
function mcp_email_aluno_pago(array $inscricao): void
{
    $nome = mcp_primeiro_nome((string) $inscricao['nome']);
    $curso = (string) $inscricao['curso_nome'];
    $total = mcp_brl((int) $inscricao['total_centavos']);
    $escolaUrl = (string) mcp_cfg('ESCOLA_URL', 'https://escola.cursoscruzvermelha.org');
    $acesso = mcp_escola_acesso($inscricao);
    $linkParabens = mcp_url_pagina('parabens', (string) $inscricao['token']);
    $estorno = 'A inscrição reserva sua vaga. Se não houver horário compatível ou você desistir antes da confirmação da aula, o valor é estornado. O prazo para aparecer na conta depende de PIX ou cartão.';

    if ($acesso) {
        $assunto = "Acesso à secretaria — $curso";
        $tipo = 'acesso';
        $usuario = (string) ($acesso['usuario'] ?? $inscricao['email']);
        $url = (string) ($acesso['acesso']['url'] ?? $acesso['url_ambiente'] ?? $escolaUrl);
        $senha = isset($acesso['acesso']['senha']) ? (string) $acesso['acesso']['senha'] : '';
        $corpo = mcp_p('Parabéns, ' . mcp_escapar($nome) . '. Sua inscrição no curso <strong>' . mcp_escapar($curso) . '</strong> está paga (' . $total . ') e aqui está seu acesso à secretaria da escola.')
            . mcp_p('Usuário: <strong>' . mcp_escapar($usuario) . '</strong>' . ($senha !== '' ? '<br>Senha temporária: <strong>' . mcp_escapar($senha) . '</strong> (troque no primeiro acesso)' : ''))
            . mcp_botao($url, 'Acessar ambiente da secretaria')
            . mcp_p('Horário e turma você escolhe lá. ' . mcp_escapar($estorno));
        $texto = "Parabéns, $nome. Sua inscrição no curso $curso está paga ($total).\nUsuário: $usuario" . ($senha !== '' ? "\nSenha temporária: $senha" : '') . "\nAcesso: $url\n\n$estorno";
        $titulo = 'Inscrição paga: aqui está seu acesso';
    } else {
        $assunto = "Inscrição confirmada — $curso";
        $tipo = 'confirmacao';
        $corpo = mcp_p('Parabéns, ' . mcp_escapar($nome) . '. Sua inscrição no curso <strong>' . mcp_escapar($curso) . '</strong> está paga (' . $total . ').')
            . mcp_p('<strong>A secretaria da Escola entra em contato pelo WhatsApp em até 2 dias úteis</strong> para fechar turma e horário. Você não precisa se inscrever de novo na plataforma.')
            . mcp_p('O valor do curso é pago depois, na plataforma da escola. ' . mcp_escapar($estorno))
            . mcp_botao($linkParabens, 'Ver minha inscrição')
            . mcp_p('<a href="' . mcp_escapar($escolaUrl) . '" style="color:#cc0000">Plataforma da escola</a>');
        $texto = "Parabéns, $nome. Sua inscrição no curso $curso está paga ($total).\nA secretaria da Escola entra em contato pelo WhatsApp em até 2 dias úteis para fechar turma e horário. Você não precisa se inscrever de novo na plataforma.\n\n$estorno\n\nMinha inscrição: $linkParabens";
        $titulo = 'Inscrição confirmada';
    }
    $r = mcp_enviar_email((string) $inscricao['email'], $assunto, mcp_moldura($titulo, $corpo), $texto);
    mcp_atualizar((int) $inscricao['id'], ['email_aluno' => $r === 'falhou' ? 'falhou' : $tipo]);
    mcp_registrar((int) $inscricao['id'], 'email_aluno', "$tipo · $r");
}

/** Aviso interno de inscrição paga. Desligado enquanto EMAIL_SECRETARIA estiver vazio. */
function mcp_email_secretaria(array $inscricao): void
{
    $para = (string) mcp_cfg('EMAIL_SECRETARIA', '');
    if ($para === '') {
        return;
    }
    $pago = mcp_brl((int) $inscricao['total_centavos']) . ' (inscrição ' . mcp_brl((int) $inscricao['inscricao_centavos'])
        . ((int) $inscricao['taxa_centavos'] > 0 ? ' + custos ' . mcp_brl((int) $inscricao['taxa_centavos']) : '') . ')';
    $linhas = [
        'Curso' => $inscricao['curso_nome'],
        'Aluno' => $inscricao['nome'],
        'CPF' => $inscricao['cpf'],
        'E-mail' => $inscricao['email'],
        'WhatsApp' => $inscricao['telefone'],
        'Pago' => $pago,
        'Método' => $inscricao['metodo'] === 'pix' ? 'PIX' : 'Cartão ' . ($inscricao['bandeira'] ?? '') . ' final ' . ($inscricao['ultimos4'] ?? ''),
        'Transação Unicopag' => $inscricao['unicopag_hash'],
        'Origem' => trim(($inscricao['utm_source'] ?? '') . ' ' . ($inscricao['utm_campaign'] ?? '')) ?: 'direto',
        'Escola' => mcp_escola_configurada() ? $inscricao['escola_status'] : 'sem API: criar a matrícula e aplicar o valor da inscrição',
    ];
    $tabela = '';
    $texto = '';
    foreach ($linhas as $rotulo => $valor) {
        $tabela .= '<tr><td style="padding:6px 10px 6px 0;color:#718096;white-space:nowrap">' . mcp_escapar((string) $rotulo) . '</td><td style="padding:6px 0">' . mcp_escapar((string) $valor) . '</td></tr>';
        $texto .= "$rotulo: $valor\n";
    }
    $corpo = mcp_p('Nova inscrição paga pela página de matrícula. Entrar em contato com o aluno pelo WhatsApp em até 2 dias úteis para fechar turma e horário.')
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px">' . $tabela . '</table>';
    $r = mcp_enviar_email($para, 'Inscrição paga: ' . $inscricao['nome'] . ' — ' . $inscricao['curso_nome'], mcp_moldura('Nova inscrição paga', $corpo), $texto);
    mcp_atualizar((int) $inscricao['id'], ['email_secretaria' => $r]);
    mcp_registrar((int) $inscricao['id'], 'email_secretaria', $r);
}
