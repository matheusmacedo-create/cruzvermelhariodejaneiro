<?php
/**
 * E-mails das doações, na mesma moldura institucional do checkout (lib/email.php):
 *  - PIX gerado: o código volta para a caixa de quem doou, para pagar quando puder;
 *  - doação confirmada: agradecimento com o comprovante dos dados da doação;
 *  - aviso à equipe: a doação que acabou de entrar, com o protocolo e o contato.
 *
 * Falha de e-mail nunca derruba a requisição: a doação já está gravada e paga.
 */
declare(strict_types=1);

const MCP_DOACAO_AGRADECIMENTO = 'Como agradecimento, quem doa pelo site recebe acesso futuro a cursos gravados gratuitos da Cruz Vermelha Brasileira Rio de Janeiro.';

function mcp_doacao_remetente(): string
{
    return mcp_email_nome_oficial((string) mcp_doacao_cfg('EMAIL_REMETENTE_DOACAO', mcp_doacao_cfg('EMAIL_REMETENTE', MCP_NOME_FILIAL . ' <doacao@cruzvermelhariodejaneiro.org>')));
}

function mcp_doacao_email_equipe_endereco(): string
{
    return (string) mcp_doacao_cfg('EMAIL_DOACOES', mcp_email_contato_endereco());
}

/** Rótulo da forma de pagamento para as telas e os e-mails. */
function mcp_doacao_metodo_rotulo(array $d): string
{
    if ($d['metodo'] !== 'cartao') {
        return 'PIX';
    }
    $final = (string) ($d['ultimos4'] ?? '');
    $bandeira = trim((string) ($d['bandeira'] ?? ''));
    return 'Cartão' . ($bandeira !== '' ? ' ' . ucfirst($bandeira) : '') . ($final !== '' ? " ···· $final" : '');
}

/** Linhas do comprovante, iguais no e-mail de quem doa e no aviso da equipe. */
function mcp_doacao_linhas(array $d): array
{
    $linhas = ['Protocolo' => mcp_doacao_protocolo((int) $d['id'], (string) $d['criado_em'])];
    if ($d['frequencia'] === 'mensal') {
        $linhas['Tipo'] = 'Doação mensal';
    }
    $linhas['Forma de pagamento'] = mcp_doacao_metodo_rotulo($d);
    // Rótulos diferentes: 'Tipo' é a frequência, 'Valor da doação' é o quanto foi doado sem os custos.
    if ((int) $d['taxa_centavos'] > 0) {
        $linhas['Valor da doação'] = mcp_brl((int) $d['valor_centavos']);
        $linhas['Custos de processamento'] = mcp_brl((int) $d['taxa_centavos']);
    }
    $quando = mcp_data_brt((string) ($d['pago_em'] ?: $d['criado_em']));
    if ($quando !== '') {
        $linhas['Data'] = $quando;
    }
    if ((int) ($d['anonimo'] ?? 0) === 1) {
        $linhas['Divulgação'] = 'Doação anônima';
    }
    return $linhas;
}

// ----------------------------------------------------------------------------- PIX em aberto
function mcp_doacao_montar_email_pix(array $d): array
{
    $nome = mcp_escapar(mcp_primeiro_nome((string) $d['nome']));
    $total = mcp_brl((int) $d['total_centavos']);
    $url = mcp_doacao_url_obrigado((string) $d['token']);
    $corpo = mcp_p("$nome, seu código PIX de <strong>$total</strong> está pronto. A doação só é concluída depois do pagamento.")
        . mcp_bloco_pix((string) $d['pix_copia_cola'])
        . mcp_botao($url, 'Abrir a página e ver o QR code')
        . mcp_caixa(mcp_doacao_linhas($d), ['Total' => $total])
        . mcp_nota('O código vale por 24 horas. Depois desse prazo, é só gerar outro na página de doação.')
        . mcp_nota(mcp_escapar(MCP_DOACAO_AGRADECIMENTO));
    return [
        'para' => (string) $d['email'],
        'assunto' => "Seu PIX de $total para a Cruz Vermelha Brasileira Rio de Janeiro",
        'html' => mcp_moldura('Falta só o pagamento do PIX', $corpo, [
            'eyebrow' => 'Doação · Cruz Vermelha Brasileira Rio de Janeiro',
            'preheader' => "Código PIX de $total pronto para pagar. Vale por 24 horas.",
            'motivo' => 'Você recebeu este e-mail porque gerou um PIX de doação em cruzvermelhariodejaneiro.org/doe.',
        ]),
        'texto' => "$nome, seu código PIX de $total está pronto.\n\nPIX copia e cola:\n{$d['pix_copia_cola']}\n\nPágina da doação: $url\n\nO código vale por 24 horas.\n",
    ];
}

function mcp_doacao_email_pix(array $d): void
{
    if (empty($d['pix_copia_cola'])) {
        return;
    }
    $m = mcp_doacao_montar_email_pix($d);
    $envio = mcp_enviar_email($m['para'], $m['assunto'], $m['html'], $m['texto'], null, mcp_doacao_remetente());
    mcp_doacao_atualizar((int) $d['id'], ['email_doador' => $envio]);
    mcp_doacao_registrar((int) $d['id'], 'email_pix', $envio);
}

// ----------------------------------------------------------------------------- doação confirmada
function mcp_doacao_montar_email_confirmada(array $d): array
{
    $nome = mcp_escapar(mcp_primeiro_nome((string) $d['nome']));
    $total = mcp_brl((int) $d['total_centavos']);
    $site = mcp_site_url();
    $mensal = $d['frequencia'] === 'mensal';
    $corpo = mcp_p("$nome, sua doação de <strong>$total</strong> foi confirmada. Obrigado por manter a Cruz Vermelha Brasileira Rio de Janeiro em movimento.")
        . mcp_caixa(mcp_doacao_linhas($d), ['Total' => $total])
        . mcp_subtitulo('Para onde vai sua doação')
        . mcp_passos([
            ['Formação de voluntários', 'Turmas de formação inicial e capacitação continuada na sede, no Centro do Rio.'],
            ['Capacitação em primeiros socorros', 'Material, manequins e instrutores para quem aprende a socorrer.'],
            ['Ações comunitárias e campanhas', 'Campanha do Agasalho, Impacto das Cores e o atendimento à população.'],
        ])
        . ((int) ($d['anonimo'] ?? 0) === 1 ? mcp_nota('Sua doação foi registrada como <strong>anônima</strong>: seu nome não é usado em agradecimentos públicos nem em listas de doadores.') : '')
        . mcp_p(mcp_escapar(MCP_DOACAO_AGRADECIMENTO))
        . mcp_botao($site . '/noticias/', 'Ver as ações da filial', true)
        . mcp_nota('Guarde este e-mail: ele é o comprovante da sua doação. Cruz Vermelha Brasileira · Filial do Estado do Rio de Janeiro · CNPJ ' . MCP_EMAIL_CNPJ . '.')
        . ($mensal ? mcp_nota('Para pausar ou cancelar a doação mensal, responda este e-mail a qualquer momento.') : '');
    return [
        'para' => (string) $d['email'],
        'assunto' => "Recebemos sua doação de $total · " . mcp_doacao_protocolo((int) $d['id'], (string) $d['criado_em']),
        'html' => mcp_moldura('Sua doação foi confirmada', $corpo, [
            'eyebrow' => 'Doação · Cruz Vermelha Brasileira Rio de Janeiro',
            'preheader' => "Obrigado! Recebemos sua doação de $total.",
            'motivo' => 'Você recebeu este e-mail porque doou em cruzvermelhariodejaneiro.org/doe.',
        ]),
        'texto' => "$nome, sua doação de $total foi confirmada. Obrigado!\n\nProtocolo: " . mcp_doacao_protocolo((int) $d['id'], (string) $d['criado_em'])
            . "\nForma de pagamento: " . mcp_doacao_metodo_rotulo($d) . "\n\n" . MCP_DOACAO_AGRADECIMENTO . "\n\nCruz Vermelha Brasileira · Filial do Estado do Rio de Janeiro · CNPJ " . MCP_EMAIL_CNPJ . "\n",
    ];
}

function mcp_doacao_email_confirmada(array $d): void
{
    $m = mcp_doacao_montar_email_confirmada($d);
    $envio = mcp_enviar_email($m['para'], $m['assunto'], $m['html'], $m['texto'], null, mcp_doacao_remetente());
    mcp_doacao_atualizar((int) $d['id'], ['email_doador' => $envio]);
    mcp_doacao_registrar((int) $d['id'], 'email_confirmada', $envio);
}

// ----------------------------------------------------------------------------- aviso à equipe
function mcp_doacao_montar_email_equipe(array $d): array
{
    $total = mcp_brl((int) $d['total_centavos']);
    $email = mcp_escapar((string) $d['email']);
    $telefone = mcp_telefone_bonito((string) $d['telefone']);
    $linhas = mcp_doacao_linhas($d) + [
        'Nome' => (string) $d['nome'],
        'E-mail' => ['html' => '<a href="mailto:' . $email . '" style="color:#cc0000;text-decoration:none">' . $email . '</a>'],
        'Telefone' => $telefone,
    ];
    $origem = trim(implode(' · ', array_filter([$d['utm_source'], $d['utm_medium'], $d['utm_campaign'], $d['utm_content']])));
    if ($origem !== '') {
        $linhas['Origem'] = $origem;
    }
    $corpo = mcp_p('Uma doação foi confirmada no site.')
        . mcp_caixa($linhas, ['Total' => $total])
        . mcp_nota('Aviso automático da página de doação (/doe/). Quem doou já recebeu o e-mail de agradecimento.');
    return [
        'para' => mcp_doacao_email_equipe_endereco(),
        'assunto' => "Doação de $total · " . mcp_doacao_protocolo((int) $d['id'], (string) $d['criado_em']),
        'html' => mcp_moldura('Nova doação confirmada', $corpo, [
            'eyebrow' => 'Aviso interno · Doações',
            'preheader' => "$total · " . (string) $d['nome'],
        ]),
        'texto' => "Doação confirmada: $total\nProtocolo: " . mcp_doacao_protocolo((int) $d['id'], (string) $d['criado_em'])
            . "\nNome: {$d['nome']}\nE-mail: {$d['email']}\nTelefone: $telefone\nForma: " . mcp_doacao_metodo_rotulo($d) . "\n",
    ];
}

function mcp_doacao_email_equipe(array $d): void
{
    $m = mcp_doacao_montar_email_equipe($d);
    $envio = mcp_enviar_email($m['para'], $m['assunto'], $m['html'], $m['texto'], (string) $d['email'], mcp_doacao_remetente());
    mcp_doacao_atualizar((int) $d['id'], ['email_equipe' => $envio]);
    mcp_doacao_registrar((int) $d['id'], 'email_equipe', $envio);
}
