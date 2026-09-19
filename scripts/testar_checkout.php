#!/usr/bin/env php
<?php
/**
 * Testes das funções puras do backend do checkout (sem banco, sem rede, sem segredos).
 *
 * Uso:  php scripts/testar_checkout.php
 * Sai com código 1 se algum teste falhar. Usa uma configuração descartável via MCP_CONFIG_ARQUIVO
 * e o catálogo publicado (site/matricula-cursos-presenciais/cursos.json).
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$configTeste = tempnam(sys_get_temp_dir(), 'mcp-config-');
file_put_contents($configTeste, "<?php return [
    'SITE_URL' => 'https://exemplo.org', 'INSCRICAO_CENTAVOS' => '', 'PRECO_TESTE_CENTAVOS' => '',
    'TAXA_PIX_PCT' => 5.0, 'TAXA_PIX_FIXA' => 0, 'TAXA_CARTAO_PCT' => 5.0, 'TAXA_CARTAO_FIXA' => 0,
    'ESCOLA_API_URL' => '', 'ESCOLA_URL' => 'https://escola.exemplo.org', 'EMAIL_CONTATO' => 'contato@exemplo.org',
];");
putenv("MCP_CONFIG_ARQUIVO=$configTeste");
putenv('MCP_CATALOGO_ARQUIVO=' . $raiz . '/site/matricula-cursos-presenciais/cursos.json');
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $raiz . '/site/matricula-cursos-presenciais/api/lib.php';
restore_exception_handler();

$falhas = 0;
$total = 0;
function verificar(string $nome, mixed $obtido, mixed $esperado): void
{
    global $falhas, $total;
    $total++;
    if ($obtido === $esperado) {
        return;
    }
    $falhas++;
    fwrite(STDERR, sprintf("FALHOU %s\n  esperado: %s\n  obtido:   %s\n", $nome, var_export($esperado, true), var_export($obtido, true)));
}

// CPF: dígitos verificadores e sequências repetidas.
verificar('cpf válido', mcp_cpf_valido('529.982.247-25'), true);
verificar('cpf válido só dígitos', mcp_cpf_valido('11144477735'), true);
verificar('cpf dígito errado', mcp_cpf_valido('52998224726'), false);
verificar('cpf repetido', mcp_cpf_valido('11111111111'), false);
verificar('cpf curto', mcp_cpf_valido('123'), false);

// Luhn: cartão de teste conhecido e um vizinho inválido.
verificar('luhn válido', mcp_luhn_valido('4111111111111111'), true);
verificar('luhn inválido', mcp_luhn_valido('4111111111111112'), false);
verificar('luhn vazio', mcp_luhn_valido(''), false);
verificar('luhn letras', mcp_luhn_valido('4111a11111111111'), false);

// Telefone: 10 ou 11 dígitos, 55 opcional, sem DDD começando em 0.
verificar('telefone celular', mcp_telefone('(21) 99999-8888'), '21999998888');
verificar('telefone fixo', mcp_telefone('21 3333-4444'), '2133334444');
verificar('telefone com 55', mcp_telefone('+55 21 99999-8888'), '21999998888');
verificar('telefone curto', mcp_telefone('9999-8888'), '');
verificar('telefone ddd zero', mcp_telefone('0219999988888'), '');

// Texto de formulário: uma linha, sem excesso de espaço, limitado.
verificar('texto normalizado', mcp_texto("  Maria \n  da   Silva ", 160), 'Maria da Silva');
verificar('texto limitado', mcp_texto(str_repeat('a', 200), 10), str_repeat('a', 10));
verificar('texto não escalar', mcp_texto(['x'], 10), '');
verificar('primeiro nome', mcp_primeiro_nome('Maria da Silva'), 'Maria');
verificar('escapar html', mcp_escapar('<b>"x"</b>'), '&lt;b&gt;&quot;x&quot;&lt;/b&gt;');

// Preços: catálogo (9900) e 5% de custos em ambos os métodos.
verificar('inscrição do catálogo', mcp_inscricao_centavos(), 9900);
verificar('taxa pix 5%', mcp_taxa('pix', 9900), 495);
verificar('taxa cartão 5%', mcp_taxa('cartao', 9900), 495);
verificar('taxa arredonda', mcp_taxa('pix', 101), 5);
verificar('modo teste desligado', mcp_modo_teste(), false);
verificar('brl', mcp_brl(10395), 'R$ 103,95');

// Catálogo: curso conhecido e desconhecido.
$primeiro = mcp_catalogo()['cursos'][0]['slug'] ?? '';
verificar('curso existente', mcp_curso($primeiro)['slug'] ?? null, $primeiro);
verificar('curso inexistente', mcp_curso('nao-existe'), null);

// Status da Unicopag: desconhecido é pendente.
foreach (['waiting_payment' => 'pendente', 'paid' => 'pago', 'PAID' => 'pago', 'refused' => 'recusado', 'cancelled' => 'expirado',
          'refunded' => 'estornado', 'chargeback' => 'estornado', 'pre_chargeback' => 'pago', 'zzz' => 'pendente', '' => 'pendente'] as $origem => $esperado) {
    verificar("status $origem", mcp_traduzir_status($origem), $esperado);
}
verificar('status nulo', mcp_traduzir_status(null), 'pendente');

// Tokens e URLs.
$token = mcp_token_novo();
verificar('token tamanho', strlen($token), 40);
verificar('token válido', mcp_token_valido($token), true);
verificar('token maiúsculo inválido', mcp_token_valido(strtoupper($token)), false);
verificar('token curto inválido', mcp_token_valido('abc'), false);
verificar('url página', mcp_url_pagina('pendente', 'abc'), 'https://exemplo.org/matricula-cursos-presenciais/pendente/?t=abc');
verificar('origens permitidas', mcp_origens_permitidas(), ['https://exemplo.org', 'https://www.exemplo.org']);

// Mensagem ao aluno a partir do erro da Unicopag.
$erro = new McpUnicopagErro(422, 'The given data was invalid.', ['customer.document' => ['O campo documento é obrigatório.']]);
verificar('erro campo', $erro->paraOAluno(), 'O campo documento é obrigatório.');
verificar('erro 402 geral', (new McpUnicopagErro(402, 'Cartão recusado'))->paraOAluno(), 'Cartão recusado');
verificar('erro 500 genérico', (new McpUnicopagErro(500, 'stack trace'))->paraOAluno(), 'Não foi possível processar o pagamento. Tente novamente.');

// Visão pública: nunca CPF, hash, IP ou dado de cartão além de bandeira e final.
$inscricao = [
    'id' => 1, 'token' => $token, 'status' => 'pago', 'metodo' => 'cartao', 'curso_slug' => $primeiro, 'curso_nome' => 'Curso X',
    'nome' => 'Maria da Silva', 'cpf' => '52998224725', 'email' => 'maria@exemplo.org', 'telefone' => '21999998888',
    'inscricao_centavos' => 9900, 'taxa_centavos' => 495, 'total_centavos' => 10395, 'unicopag_hash' => 'h4sh', 'ip' => '10.0.0.1',
    'pix_copia_cola' => null, 'pix_url' => null, 'pix_imagem' => null, 'bandeira' => 'visa', 'ultimos4' => '1111',
    'criado_em' => '2026-09-18 00:00:00', 'pago_em' => '2026-09-18 00:01:00', 'escola_status' => 'nao_aplicavel',
    'escola_acesso' => json_encode(['usuario' => 'maria', 'acesso' => ['senha' => 's3', 'url' => 'https://escola.exemplo.org/x']]),
];
$publico = mcp_publico($inscricao);
$serializado = json_encode($publico);
verificar('público sem cpf', str_contains($serializado, '52998224725'), false);
verificar('público sem hash', str_contains($serializado, 'h4sh'), false);
verificar('público sem ip', str_contains($serializado, '10.0.0.1'), false);
verificar('público sem telefone', str_contains($serializado, '21999998888'), false);
verificar('público primeiro nome', $publico['nome'], 'Maria');
verificar('público cartão', $publico['cartao'], ['bandeira' => 'visa', 'ultimos4' => '1111']);
verificar('público pix nulo', $publico['pix'], null);
verificar('público acesso quando pago', $publico['escola']['usuario'], 'maria');
$pendente = mcp_publico(['status' => 'pendente'] + $inscricao);
verificar('público sem acesso quando pendente', $pendente['escola']['usuario'], null);
verificar('público sem senha quando pendente', $pendente['escola']['senha'], null);

// Texto de várias linhas (mensagem do chat).
verificar('texto longo normalizado', mcp_texto_longo("  Olá,\r\n\r\n\r\n\tcomo   vai?\x07 \n  linha  ", 100), "Olá,\n\ncomo vai?\nlinha");
verificar('texto longo limitado', mb_strlen(mcp_texto_longo(str_repeat('á', 50), 10)), 10);
verificar('texto longo não escalar', mcp_texto_longo(['x'], 10), '');

// Chat de contato: assuntos, protocolo (data de Brasília) e o que pede curso.
verificar('assunto desconhecido vira outro', mcp_contato_assunto_rotulo('zzz'), 'Outro assunto');
verificar('assunto conhecido', mcp_contato_assunto_rotulo('curso'), 'Dúvida sobre um curso');
verificar('assunto com curso', mcp_contato_com_curso('pagamento') && !mcp_contato_com_curso('voluntariado'), true);
verificar('protocolo', mcp_contato_protocolo(12, '2026-09-19 02:30:00'), 'CV-260918-0012');

// E-mail: moldura no padrão da instituição (logo, contato, CNPJ), botão e componentes escapam o que recebem.
verificar('botão escapa', str_contains(mcp_botao('https://x/?a=1&b=2', '<Ir>'), 'a=1&amp;b=2') && str_contains(mcp_botao('https://x', '<Ir>'), '&lt;Ir&gt;'), true);
$moldura = mcp_moldura('Título <x>', '<p>corpo</p>', ['eyebrow' => 'Chapéu', 'preheader' => 'Prévia da caixa', 'motivo' => 'Porque sim']);
verificar('moldura logo', str_contains($moldura, 'https://exemplo.org/assets/otim/logo-cvb-rj-480.png'), true);
verificar('moldura contato', str_contains($moldura, 'mailto:contato@exemplo.org'), true);
verificar('moldura cnpj', str_contains($moldura, '08.560.973/0001-97'), true);
verificar('moldura chapéu, prévia e motivo', str_contains($moldura, 'Chapéu') && str_contains($moldura, 'Prévia da caixa') && str_contains($moldura, 'Porque sim'), true);
verificar('moldura escapa título', str_contains($moldura, 'Título &lt;x&gt;') && !str_contains($moldura, 'Título <x>'), true);
verificar('moldura sem whatsapp', stripos($moldura, 'whatsapp'), false);
verificar('caixa total', str_contains(mcp_caixa(['Curso' => 'X'], ['Total' => 'R$ 1,00']), 'R$ 1,00'), true);
verificar('citação quebra linha', str_contains(mcp_citacao("a\nb <i>"), "a<br />\nb &lt;i&gt;"), true);
verificar('data brt', mcp_data_brt('2026-09-19 12:00:00'), '19/09 às 09h00');
verificar('data brt inválida', mcp_data_brt('nada'), '');
verificar('pix validade', mcp_pix_validade(['criado_em' => '2026-09-19 12:00:00']), '20/09 às 09h00');

// E-mail de recuperação do PIX: copy de conversão, valores certos, link com UTM, tudo escapado, sem WhatsApp.
$pix = ['pix_copia_cola' => '00020126BR.GOV.BCB.PIX<teste>', 'metodo' => 'pix', 'nome' => '<b>Maria</b> da Silva', 'criado_em' => '2026-09-19 12:00:00'] + $inscricao;
$m = mcp_montar_email_pix_aberto($pix);
verificar('pix assunto', $m['assunto'], 'Falta só o PIX para garantir sua vaga em Curso X');
verificar('pix título com primeiro nome escapado', str_contains($m['html'], 'Falta só o PIX, &lt;b&gt;Maria&lt;/b&gt;.') && !str_contains($m['html'], '<b>Maria</b>'), true);
verificar('pix botão', str_contains($m['html'], 'Concluir pagamento'), true);
verificar('pix link com utm', str_contains($m['html'], 'https://exemplo.org/matricula-cursos-presenciais/pendente/?t=' . $token . '&amp;utm_source=email&amp;utm_medium=transacional&amp;utm_campaign=pix-aberto'), true);
verificar('pix código escapado', str_contains($m['html'], '00020126BR.GOV.BCB.PIX&lt;teste&gt;'), true);
verificar('pix valores', str_contains($m['html'], 'R$ 99,00') && str_contains($m['html'], 'R$ 4,95') && str_contains($m['html'], 'R$ 103,95'), true);
verificar('pix validade no corpo', str_contains($m['html'], 'até 20/09 às 09h00'), true);
verificar('pix texto puro', str_contains($m['texto'], '00020126BR.GOV.BCB.PIX<teste>') && str_contains($m['texto'], 'R$ 103,95'), true);
verificar('pix sem whatsapp', stripos($m['html'] . $m['texto'], 'whatsapp'), false);

// Inscrição paga: versão A (com acesso da escola) e versão B (a secretaria escreve por e-mail).
$a = mcp_montar_email_aluno_pago($inscricao);
verificar('pago A tipo', $a['tipo'], 'acesso');
verificar('pago A acesso', str_contains($a['html'], 'maria') && str_contains($a['html'], 's3') && str_contains($a['html'], 'https://escola.exemplo.org/x'), true);
$b = mcp_montar_email_aluno_pago(['escola_acesso' => null] + $inscricao);
verificar('pago B tipo', $b['tipo'], 'confirmacao');
verificar('pago B assunto', $b['assunto'], 'Inscrição confirmada: sua vaga em Curso X');
verificar('pago B próximos passos por e-mail', str_contains($b['html'], 'por e-mail em até 2 dias úteis') && str_contains($b['html'], 'Ver minha inscrição'), true);
verificar('pago B cartão final', str_contains($b['html'], 'Cartão final 1111'), true);
verificar('pago sem whatsapp', stripos($a['html'] . $b['html'] . $a['texto'] . $b['texto'], 'whatsapp'), false);
$sec = mcp_montar_email_secretaria($inscricao);
verificar('secretaria telefone', str_contains($sec['html'], 'Telefone') && str_contains($sec['texto'], "Telefone: 21999998888\n"), true);
verificar('secretaria sem whatsapp', stripos($sec['html'] . $sec['texto'], 'whatsapp'), false);

// Chat de contato: aviso à equipe e confirmação à pessoa.
$contato = [
    'id' => 7, 'protocolo' => 'CV-260919-0007', 'nome' => 'João <script>alert(1)</script> Souza', 'email' => 'joao@exemplo.org', 'telefone' => '21988887777',
    'assunto' => 'curso', 'curso_slug' => $primeiro, 'curso_nome' => 'Curso X', 'mensagem' => "Tem turma à noite?\n<b>Obrigado</b>", 'pagina' => '/matricula-cursos-presenciais/?curso=' . $primeiro,
    'utm_source' => 'instagram', 'utm_medium' => null, 'utm_campaign' => 'bio', 'utm_content' => null, 'utm_term' => null, 'fbclid' => null, 'gclid' => null,
    'criado_em' => '2026-09-19 15:00:00',
];
$eq = mcp_montar_email_contato_equipe($contato);
verificar('equipe assunto', $eq['assunto'], '[Site] Dúvida sobre um curso: João <script>alert(1)</script> Souza · Curso X · CV-260919-0007');
verificar('equipe escapa nome', str_contains($eq['html'], 'João &lt;script&gt;alert(1)&lt;/script&gt; Souza') && !str_contains($eq['html'], '<script>alert(1)</script>'), true);
verificar('equipe mensagem com quebra', str_contains($eq['html'], "Tem turma à noite?<br />\n&lt;b&gt;Obrigado&lt;/b&gt;"), true);
verificar('equipe responder', str_contains($eq['html'], 'mailto:joao@exemplo.org?subject=Re%3A%20D'), true);
verificar('equipe origem e hora', str_contains($eq['html'], 'instagram bio') && str_contains($eq['html'], '19/09/2026 às 12h00 (Brasília)'), true);
$cf = mcp_montar_email_contato_confirmacao($contato);
verificar('confirmação assunto', $cf['assunto'], 'Recebemos sua mensagem · CV-260919-0007');
verificar('confirmação primeiro nome', str_contains($cf['html'], 'Recebemos sua mensagem, João.'), true);
verificar('confirmação matrícula do curso', str_contains($cf['html'], 'https://exemplo.org/matricula-cursos-presenciais/checkout/?curso=' . $primeiro) && str_contains($cf['html'], 'R$ 99,00'), true);
$cf2 = mcp_montar_email_contato_confirmacao(['assunto' => 'voluntariado', 'curso_slug' => null, 'curso_nome' => null] + $contato);
verificar('confirmação sem curso não vende', str_contains($cf2['html'], 'checkout') || str_contains($cf2['html'], 'Ver cursos presenciais'), false);
verificar('contato sem whatsapp', stripos($eq['html'] . $cf['html'] . $eq['texto'] . $cf['texto'], 'whatsapp'), false);
verificar('equipe telefone formatado com link', str_contains($eq['html'], 'tel:+5521988887777') && str_contains($eq['html'], '(21) 98888-7777'), true);
verificar('equipe sem painel não mostra botão', str_contains($eq['html'], 'Responder no painel'), false);
$eqPainel = mcp_montar_email_contato_equipe(['link_painel' => 'https://exemplo.org/matricula-cursos-presenciais/api/painel.php?c=7&e=1&k=abc'] + $contato);
verificar('equipe com painel', str_contains($eqPainel['html'], 'Responder no painel') && str_contains($eqPainel['html'], 'painel.php?c=7&amp;e=1&amp;k=abc') && str_contains($eqPainel['texto'], 'painel.php?c=7&e=1&k=abc'), true);
verificar('equipe título', str_contains($eqPainel['html'], 'Nova mensagem de João'), true);
verificar('confirmação explica a resposta por e-mail', str_contains($cf['html'], 'Respondemos por e-mail em até 2 dias úteis') && str_contains($cf['html'], 'contato@exemplo.org') && str_contains($cf['html'], 'CV-260919-0007'), true);
verificar('confirmação telefone opcional', str_contains($cf['html'], 'também podemos ligar para (21) 98888-7777'), true);

// Resposta do painel e link de entrada.
$resp = mcp_montar_email_resposta_contato($contato, "Oi, João!\n\nTemos turma à noite às terças <b>e</b> quintas.\n\nAbraço", 'Ana, da equipe de cursos');
verificar('resposta assunto', $resp['assunto'], 'Resposta da Cruz Vermelha Brasileira Rio de Janeiro · CV-260919-0007');
verificar('resposta título', str_contains($resp['html'], 'Respondemos sua mensagem, João.'), true);
verificar('resposta corpo escapado com quebras', str_contains($resp['html'], "às terças &lt;b&gt;e&lt;/b&gt; quintas.<br />"), true);
verificar('resposta assinatura', str_contains($resp['html'], 'Ana, da equipe de cursos') && str_contains($resp['texto'], 'Ana, da equipe de cursos'), true);
verificar('resposta cita a mensagem original e a matrícula', str_contains($resp['html'], 'Tem turma à noite?') && str_contains($resp['html'], 'checkout/?curso=' . $primeiro), true);
verificar('resposta sem whatsapp', stripos($resp['html'] . $resp['texto'], 'whatsapp'), false);
$entrada = mcp_montar_email_painel_link('https://exemplo.org/matricula-cursos-presenciais/api/painel.php?entrar=a%40b.co&e=1&k=abc');
verificar('link de entrada no e-mail', str_contains($entrada['html'], 'painel.php?entrar=a%40b.co&amp;e=1&amp;k=abc') && str_contains($entrada['html'], 'Entrar no painel'), true);
verificar('telefone bonito', mcp_telefone_bonito('21999990000') . ' ' . mcp_telefone_bonito('2133334444') . ' ' . mcp_telefone_bonito('123'), '(21) 99999-0000 (21) 3333-4444 123');
verificar('remetente endereço', mcp_email_endereco('Cruz Vermelha RJ <x@exemplo.org>') . '|' . mcp_email_endereco('y@exemplo.org'), 'x@exemplo.org|y@exemplo.org');
verificar('remetente nome curto vira nome completo', mcp_email_nome_oficial('Cruz Vermelha RJ <matricula@info.exemplo.org>'), 'Cruz Vermelha Brasileira Rio de Janeiro <matricula@info.exemplo.org>');
verificar('remetente nacional vira nome completo', mcp_email_nome_oficial('"Cruz Vermelha Brasileira" <x@exemplo.org>'), 'Cruz Vermelha Brasileira Rio de Janeiro <x@exemplo.org>');
verificar('remetente só endereço ganha nome', mcp_email_nome_oficial('x@exemplo.org'), 'Cruz Vermelha Brasileira Rio de Janeiro <x@exemplo.org>');
verificar('remetente com nome próprio é mantido', mcp_email_nome_oficial('Secretaria de Cursos <x@exemplo.org>'), 'Secretaria de Cursos <x@exemplo.org>');
verificar('remetente inválido é devolvido como veio', mcp_email_nome_oficial('lixo'), 'lixo');
verificar('remetente do contato normalizado', str_starts_with(mcp_email_remetente_contato(), 'Cruz Vermelha Brasileira Rio de Janeiro <') ? 'ok' : mcp_email_remetente_contato(), 'ok');
verificar('painel e-mails permitidos', mcp_painel_emails_permitidos(), ['contato@exemplo.org']);
verificar('painel url', mcp_painel_url(), 'https://exemplo.org/matricula-cursos-presenciais/api/painel.php');

unlink($configTeste);
printf("%d testes, %d falhas\n", $total, $falhas);
exit($falhas > 0 ? 1 : 0);
