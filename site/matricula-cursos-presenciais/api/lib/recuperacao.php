<?php
/**
 * Recuperação do lead (04/10/2026): o certificado de amostra nos e-mails e um lembrete único a quem gerou o PIX
 * e ainda não pagou.
 *
 *  - O certificado: imagens de scripts/gerar_certificado_modelo.py (img/certificado-<slug>-email.jpg, JPEG porque
 *    e-mail não abre WebP). É a amostra ("Seu nome aqui", marca MODELO), nunca um certificado de verdade.
 *  - O lembrete: um por pessoa e curso, de 2 a 20 horas depois de o PIX ser gerado (ele vale 24 h). A pessoa é o
 *    e-mail OU o CPF (o checkout reaproveita o PIX por CPF, e quem corrige o e-mail digitado errado não pode receber
 *    "falta o PIX" no endereço velho). Só a inscrição pendente mais recente; nunca se a pessoa já pagou esse curso.
 *    Antes de mandar, confere o pagamento na Unicopag: se a consulta falhar ou vier um status que não conhecemos,
 *    não manda (quem pagou não pode receber "falta o PIX"); se estiver pago, aplica o pagamento (o
 *    pós-pagamento sai normalmente). Roda na rotina de hora em hora (api/lembretes.php), no máximo 20 por rodada:
 *    a cota diária do Resend é dividida com o resto do site.
 *
 * Só afirmações com fonte sobre o certificado (as mesmas da página: CERT_PESO no gerador da página).
 */
declare(strict_types=1);

const MCP_PIX_LEMBRETE_HORAS = [2, 20];
const MCP_PIX_LEMBRETE_LIMITE = 20;
// Antes do pagamento a vaga não está reservada, e a regra depois dos 7 dias ainda espera a filial (a página também
// não a cita): por isso o lembrete não usa o MCP_TEXTO_ESTORNO.
const MCP_PIX_LEMBRETE_DESISTIR = 'Depois de pagar, você pode desistir em até 7 dias e recebe o valor de volta.';
// Status da Unicopag que o lembrete aceita como resposta (os do mcp_traduzir_status). Fora deles, não manda.
const MCP_PIX_LEMBRETE_STATUS = ['waiting_payment', 'pending', 'processing', 'paid', 'pre_chargeback', 'refused', 'failed',
    'cancelled', 'canceled', 'expired', 'refunded', 'chargeback'];
const MCP_CERT_PESO = 'A Cruz Vermelha é reconhecida nacional e internacionalmente pela tradição em formação humanitária e em emergências.';

/** Endereço público da amostra do certificado do curso, ou '' se a imagem não existe. */
function mcp_certificado_url(string $slug): string
{
    if (!preg_match('/^[a-z0-9-]+$/', $slug) || !is_file(dirname(__DIR__, 2) . "/img/certificado-$slug-email.jpg")) {
        return '';
    }
    return mcp_site_url() . "/matricula-cursos-presenciais/img/certificado-$slug-email.jpg";
}

/** Bloco do certificado para os e-mails: a amostra do curso e o que ela significa. '' sem imagem. */
function mcp_email_bloco_certificado(string $slug, string $curso): string
{
    $url = mcp_certificado_url($slug);
    if ($url === '') {
        return '';
    }
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:18px 0"><tr><td align="center" style="padding:0">'
        . '<img src="' . mcp_escapar($url) . '" width="520" alt="Modelo do certificado de ' . mcp_escapar($curso) . ' da Cruz Vermelha Brasileira Rio de Janeiro" '
        . 'style="display:block;width:100%;max-width:520px;height:auto;border:1px solid #e2e8f0;border-radius:8px">'
        . '</td></tr><tr><td style="padding:10px 4px 0;font-size:14px;line-height:1.5;color:#4a5568;text-align:center">'
        . '<strong style="color:#0f1318">Ao concluir, você recebe o certificado da Cruz Vermelha</strong>, com o seu nome, o curso e a carga horária. '
        . mcp_escapar(MCP_CERT_PESO) . ' <span style="color:#718096">(Imagem de modelo.)</span></td></tr></table>';
}

/** "Sua matrícula na escola": o passo que depende da matrícula automática (config-escola.php). */
function mcp_email_passo_escola(): array
{
    return mcp_escola_configurada()
        ? ['Sua matrícula entra na turma', 'Se o curso já tem turma aberta, sua matrícula entra nela e a data aparece na confirmação. Se ainda não tem, a secretaria coloca você na próxima turma e avisa por e-mail.']
        : ['A secretaria confirma turma e horário', 'A Escola de Educação e Saúde CVB-RJ entra em contato por e-mail em até ' . MCP_EMAIL_PRAZO . '. Você não precisa se inscrever de novo.'];
}

// ----------------------------------------------------------------------------- lembrete do PIX em aberto
/** Lembrete a quem gerou o PIX e não pagou. Puro: devolve assunto, html e texto. */
function mcp_montar_email_pix_lembrete(array $inscricao): array
{
    $nome = mcp_primeiro_nome((string) $inscricao['nome']);
    $curso = (string) $inscricao['curso_nome'];
    $total = mcp_brl((int) $inscricao['total_centavos']);
    $codigo = (string) ($inscricao['pix_copia_cola'] ?? '');
    $link = mcp_url_pagina('pendente', (string) $inscricao['token']) . '&utm_source=email&utm_medium=transacional&utm_campaign=pix-lembrete';
    $validade = mcp_pix_validade($inscricao);
    $corpo = mcp_p('Oi, ' . mcp_escapar($nome) . '. Sua inscrição em <strong>' . mcp_escapar($curso) . '</strong> continua aberta, esperando só o PIX de <strong>' . mcp_escapar($total) . '</strong>. Assim que ele cair, a vaga fica garantida e a confirmação chega neste e-mail.')
        . mcp_email_bloco_certificado((string) $inscricao['curso_slug'], $curso)
        . mcp_botao($link, 'Concluir pagamento')
        . mcp_nota('Pelo botão você vê o QR code e acompanha a confirmação na hora. Ou pague agora com o código:')
        . mcp_bloco_pix($codigo)
        . mcp_p('<strong>O código vale' . ($validade !== '' ? ' até ' . mcp_escapar($validade) : ' por 24 horas') . '.</strong> Passou do prazo? Gere outro pelo mesmo botão, sem custo.')
        . mcp_subtitulo('Depois do pagamento')
        . mcp_passos([
            ['Confirmação neste e-mail', 'O comprovante da inscrição chega na hora.'],
            mcp_email_passo_escola(),
            ['Você conclui e recebe o certificado', 'O certificado da Cruz Vermelha Brasileira Rio de Janeiro, com o seu nome, o curso e a carga horária.'],
        ])
        . mcp_nota(mcp_escapar(MCP_PIX_LEMBRETE_DESISTIR) . ' Já pagou? Ignore este e-mail: a confirmação chega em instantes. Não quer seguir? É só não pagar: o PIX vence sozinho e nada é cobrado.');
    $texto = "Oi, $nome. Sua inscrição em $curso continua aberta, esperando só o PIX de $total. Assim que ele cair, a vaga fica garantida.\n\n"
        . "Ao concluir, você recebe o certificado da Cruz Vermelha, com o seu nome, o curso e a carga horária. " . MCP_CERT_PESO . "\n\n"
        . "Concluir pagamento: $link\n\nPIX copia e cola:\n$codigo\n\n"
        . 'O código vale' . ($validade !== '' ? " até $validade" : ' por 24 horas') . ". Passou do prazo? Gere outro pelo mesmo link, sem custo.\n\n"
        . MCP_PIX_LEMBRETE_DESISTIR . " Já pagou? Ignore este e-mail.\n\nDúvidas? Responda este e-mail ou escreva para " . mcp_email_contato_endereco() . ".\n";
    return [
        'assunto' => "Sua inscrição em $curso continua aberta: falta só o PIX",
        'html' => mcp_moldura("Falta só o PIX, $nome.", $corpo, [
            'eyebrow' => 'Matrícula cursos presenciais',
            'preheader' => 'Falta só o PIX de ' . $total . ($validade !== '' ? ". O código vale até $validade." : '.'),
            'motivo' => 'Você recebeu este e-mail porque iniciou uma matrícula em cruzvermelhariodejaneiro.org com este endereço. É o único lembrete.',
        ]),
        'texto' => $texto,
    ];
}

/**
 * Inscrições que recebem o lembrete agora: PIX pendente gerado há 2 a 20 horas, sem inscrição paga nem pendente mais
 * nova da mesma pessoa (e-mail ou CPF) e curso, e sem lembrete já enviado (ou tentado) para ela nesse curso. Uma
 * tentativa recusada ou vencida depois do PIX não conta: o PIX continua pagável.
 */
function mcp_pix_lembrete_candidatos(?int $agora = null, int $limite = MCP_PIX_LEMBRETE_LIMITE): array
{
    $agora ??= time();
    [$min, $max] = MCP_PIX_LEMBRETE_HORAS;
    $stmt = mcp_db()->prepare("SELECT i.* FROM mcp_inscricoes i
        WHERE i.status = 'pendente' AND i.metodo = 'pix' AND i.pix_copia_cola IS NOT NULL AND i.pix_copia_cola <> '' AND i.unicopag_hash IS NOT NULL
          AND i.criado_em <= ? AND i.criado_em >= ?
          AND NOT EXISTS (SELECT 1 FROM mcp_inscricoes o WHERE (o.email = i.email OR o.cpf = i.cpf) AND o.curso_slug = i.curso_slug
                          AND o.id <> i.id AND (o.status = 'pago' OR (o.status = 'pendente'
                          AND (o.criado_em > i.criado_em OR (o.criado_em = i.criado_em AND o.id > i.id)))))
          AND NOT EXISTS (SELECT 1 FROM mcp_eventos e JOIN mcp_inscricoes o ON o.id = e.inscricao_id
                          WHERE (o.email = i.email OR o.cpf = i.cpf) AND o.curso_slug = i.curso_slug
                            AND e.tipo IN ('email_pix_lembrete', 'email_pix_lembrete_falhou'))
        ORDER BY i.criado_em LIMIT " . max(1, $limite));
    $stmt->execute([gmdate('Y-m-d H:i:s', $agora - $min * 3600), gmdate('Y-m-d H:i:s', $agora - $max * 3600)]);
    return $stmt->fetchAll();
}

/**
 * Situação do PIX na Unicopag ('pendente', 'pago', 'recusado', 'expirado'…) ou null se a consulta falhou ou o status
 * não é conhecido (o mcp_traduzir_status chuta 'pendente' para o desconhecido, bom para a tela, ruim para cobrar).
 * Como o mcp_sincronizar() do status.php, aplica o que mudou (pago dispara o pós-pagamento uma vez só).
 */
function mcp_pix_lembrete_consultar(array $inscricao): ?string
{
    $id = (int) $inscricao['id'];
    try {
        $transacao = mcp_unicopag('GET', '/public/v1/transactions/' . rawurlencode((string) $inscricao['unicopag_hash']));
    } catch (Throwable $e) {
        mcp_registrar($id, 'consulta_falhou', 'lembrete do PIX · ' . mb_substr($e->getMessage(), 0, 200));
        mcp_atualizar($id, ['consultado_em' => mcp_agora()]);
        return null;
    }
    $origem = (string) ($transacao['payment_status'] ?? '');
    mcp_atualizar($id, ['consultado_em' => mcp_agora(), 'unicopag_status' => mb_substr($origem, 0, 40)]);
    if (mcp_pix_lembrete_situacao($origem) === null) {
        mcp_registrar($id, 'consulta_falhou', 'lembrete do PIX · status desconhecido: ' . mb_substr($origem, 0, 40));
        return null;
    }
    return (string) (mcp_aplicar_status($id, mcp_traduzir_status($origem))['status'] ?? '');
}

/** Status da Unicopag traduzido, ou null se vazio ou desconhecido. Puro. */
function mcp_pix_lembrete_situacao(string $origem): ?string
{
    return in_array(strtolower(trim($origem)), MCP_PIX_LEMBRETE_STATUS, true) ? mcp_traduzir_status($origem) : null;
}

/**
 * Uma rodada: confere cada candidato na Unicopag e manda o lembrete a quem continua pendente. $consultar e $enviar
 * só existem para o teste (scripts/testar_recuperacao_integracao.php); o cron usa os de verdade.
 */
function mcp_pix_lembrete_rodar(?int $agora = null, ?callable $consultar = null, ?callable $enviar = null): array
{
    $r = ['enviados' => 0, 'falhas' => 0, 'pulados' => 0, 'vistos' => 0];
    foreach (mcp_pix_lembrete_candidatos($agora) as $inscricao) {
        $r['vistos']++;
        $situacao = $consultar ? $consultar($inscricao) : mcp_pix_lembrete_consultar($inscricao);
        if ($situacao !== 'pendente') {
            $r['pulados']++;
            continue;
        }
        $m = mcp_montar_email_pix_lembrete($inscricao);
        $envio = ($enviar ?? 'mcp_enviar_email')((string) $inscricao['email'], $m['assunto'], $m['html'], $m['texto']);
        if ($envio === 'falhou') {
            $r['falhas']++;
            mcp_registrar((int) $inscricao['id'], 'email_pix_lembrete_falhou', '');
            continue;
        }
        $r['enviados']++;
        mcp_registrar((int) $inscricao['id'], 'email_pix_lembrete', $envio);
    }
    return $r;
}
