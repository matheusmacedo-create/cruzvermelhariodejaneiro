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

const MCP_DOACAO_FORMULARIO_VOLUNTARIO = 'https://form.spotform.com.br/voluntariocruzvermelharj';
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

/**
 * Selo do valor doado, em destaque no topo do agradecimento. Tabela (e não div) porque o Outlook
 * ignora boa parte do CSS moderno, e cor de fundo repetida em bgcolor pelo mesmo motivo.
 */
function mcp_doacao_selo(string $total, string $quando): string
{
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#fff5f5" style="margin:20px 0 6px;background:#fff5f5;border:1px solid #f5c2c7;border-radius:14px">'
        . '<tr><td align="center" style="padding:22px 18px">'
        . '<p style="margin:0 0 6px;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#cc0000;font-weight:800">Doação confirmada</p>'
        . '<p style="margin:0;font-size:38px;line-height:1.05;letter-spacing:-.03em;color:#cc0000;font-weight:800">' . mcp_escapar($total) . '</p>'
        . ($quando !== '' ? '<p style="margin:8px 0 0;font-size:13px;color:#718096">' . mcp_escapar($quando) . '</p>' : '')
        . '</td></tr></table>';
}

/**
 * O que a faixa de valor sustenta. São as mesmas referências da página (IMPACTO em
 * scripts/gerar_doe.py): ao mudar lá, mudar aqui. Não é pacote fechado, e o texto diz isso.
 */
function mcp_doacao_impacto(int $centavos): array
{
    if ($centavos < 6000) {
        return ['Material de primeiros socorros', 'Insumos das aulas práticas: ataduras, luvas e material de treino que passam pelas mãos de cada turma.'];
    }
    if ($centavos < 10000) {
        return ['Educação preventiva', 'Orientação à população em ações comunitárias: o que fazer antes de o socorro chegar.'];
    }
    if ($centavos < 25000) {
        return ['Voluntariado preparado', 'Formação inicial e capacitação continuada de quem veste o colete na rua.'];
    }
    return ['Ação comunitária', 'Campanhas como a do Agasalho e o Impacto das Cores, que chegam a quem mais precisa.'];
}

/** Bloco do impacto, com o título da faixa em destaque. */
function mcp_doacao_bloco_impacto(int $centavos): string
{
    [$titulo, $texto] = mcp_doacao_impacto($centavos);
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:6px 0 4px">'
        . '<tr><td style="padding:16px 18px;border-left:4px solid #cc0000;background:#f7f8fa;border-radius:0 12px 12px 0">'
        . '<p style="margin:0 0 4px;font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:#718096;font-weight:800">O que esse valor sustenta</p>'
        . '<p style="margin:0 0 4px;font-size:17px;color:#0f1318;font-weight:800">' . mcp_escapar($titulo) . '</p>'
        . '<p style="margin:0;font-size:15px;line-height:1.5;color:#4a5568">' . mcp_escapar($texto) . '</p>'
        . '</td></tr></table>';
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
    $valor = (int) $d['valor_centavos'];
    $taxa = (int) $d['taxa_centavos'];
    $total = mcp_brl((int) $d['total_centavos']);
    $protocolo = mcp_doacao_protocolo((int) $d['id'], (string) $d['criado_em']);
    $quando = mcp_data_brt((string) ($d['pago_em'] ?: $d['criado_em']), 'd/m/Y \à\s H\hi');
    $site = mcp_site_url();
    $mensal = $d['frequencia'] === 'mensal';
    $anonima = (int) ($d['anonimo'] ?? 0) === 1;

    // Abertura: agradece pelo nome e diz onde o dinheiro já está. Nada de "prezado(a)".
    $corpo = mcp_p('Sua doação acabou de chegar na Cruz Vermelha Brasileira Rio de Janeiro. Ela vira preparo, presença e resposta para quem precisa no estado do Rio.')
        . mcp_doacao_selo($total, $quando)
        . mcp_doacao_bloco_impacto($valor);

    // O que muda conforme as escolhas de quem doou.
    if ($taxa > 0) {
        $corpo .= mcp_p('Você ainda escolheu cobrir os custos de processamento, então <strong>'
            . mcp_escapar(mcp_brl($valor)) . '</strong> chegam inteiros à filial, sem desconto nenhum.');
    }
    if ($anonima) {
        $corpo .= mcp_p('Registramos sua doação como <strong>anônima</strong>: seu nome não entra em agradecimentos públicos, redes sociais nem listas de doadores. Ele fica só com a equipe que cuida das doações.');
    }
    if ($mensal) {
        $corpo .= mcp_p('Esta é uma <strong>doação mensal</strong>: a cobrança se repete todo mês, e avisamos você antes de cada uma. Para pausar ou cancelar, basta responder este e-mail.');
    }

    $corpo .= mcp_subtitulo('Seu comprovante')
        . mcp_caixa(mcp_doacao_linhas($d), ['Total' => $total])
        . mcp_nota('Guarde este e-mail: ele é o comprovante da sua doação, com o protocolo <strong>' . mcp_escapar($protocolo)
            . '</strong> e o CNPJ ' . MCP_EMAIL_CNPJ . ' da Cruz Vermelha Brasileira · Filial do Estado do Rio de Janeiro.')
        . mcp_subtitulo('Continue por perto')
        . mcp_p('Quem doa costuma querer ver o resultado. As ações da filial, as turmas formadas e as campanhas em andamento ficam nas notícias do site e no Instagram.')
        . mcp_botao($site . '/noticias/', 'Ver as ações da filial')
        . mcp_p('E se você quiser ir além da doação: a filial forma os próprios voluntários, com turmas na sede, no Centro do Rio. <a href="' . MCP_DOACAO_FORMULARIO_VOLUNTARIO . '" style="color:#cc0000;font-weight:700">Cadastre-se como voluntário</a> ou acompanhe pelo <a href="https://www.instagram.com/cruzvermelhabrasileirarj/" style="color:#cc0000;font-weight:700">Instagram @cruzvermelhabrasileirarj</a>.')
        . mcp_nota(mcp_escapar(MCP_DOACAO_AGRADECIMENTO))
        . '<p style="margin:26px 0 0;font-size:15px;line-height:1.5;color:#1a202c">Com gratidão,<br><strong>Equipe da Cruz Vermelha Brasileira Rio de Janeiro</strong></p>';

    $texto = "Obrigado, $nome.\n\nSua doação acabou de chegar na Cruz Vermelha Brasileira Rio de Janeiro. Ela vira preparo, presença e resposta para quem precisa no estado do Rio.\n\n"
        . "Doação confirmada: $total" . ($quando !== '' ? " em $quando" : '') . "\n"
        . "Protocolo: $protocolo\n"
        . 'Forma de pagamento: ' . mcp_doacao_metodo_rotulo($d) . "\n"
        . ($taxa > 0 ? 'Você cobriu os custos de processamento, então ' . mcp_brl($valor) . " chegam inteiros à filial, sem desconto.\n" : '')
        . ($anonima ? "Sua doação foi registrada como anônima.\n" : '')
        . ($mensal ? "Doação mensal: a cobrança se repete todo mês. Para pausar ou cancelar, responda este e-mail.\n" : '')
        . "\nAções da filial: $site/noticias/\n"
        . 'Seja voluntário: ' . MCP_DOACAO_FORMULARIO_VOLUNTARIO . "\n\n"
        . MCP_DOACAO_AGRADECIMENTO . "\n\n"
        . 'Com gratidão,\nEquipe da Cruz Vermelha Brasileira Rio de Janeiro\nCNPJ ' . MCP_EMAIL_CNPJ . "\n";

    return [
        'para' => (string) $d['email'],
        'assunto' => "Obrigado, $nome! Sua doação de $total foi confirmada",
        'html' => mcp_moldura('Obrigado, ' . $nome . '.', $corpo, [
            'eyebrow' => 'Doação · Cruz Vermelha Brasileira Rio de Janeiro',
            'preheader' => "Recebemos sua doação de $total. Aqui está o comprovante e o que ela sustenta.",
            'motivo' => 'Você recebeu este e-mail porque doou em cruzvermelhariodejaneiro.org/doe.',
        ]),
        'texto' => $texto,
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
