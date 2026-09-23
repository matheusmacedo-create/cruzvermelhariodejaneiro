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
verificar('pago B próximos passos por e-mail', str_contains($b['html'], 'por e-mail em até 3 dias úteis') && str_contains($b['html'], 'Ver minha inscrição'), true);
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
verificar('confirmação explica a resposta por e-mail', str_contains($cf['html'], 'Respondemos por e-mail em até 3 dias úteis') && str_contains($cf['html'], 'contato@exemplo.org') && str_contains($cf['html'], 'CV-260919-0007'), true);
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

// Comprovante de inscrição em PDF (lib/pdf.php + lib/comprovante.php).
verificar('nome próprio: minúsculo', mcp_nome_proprio('joana maria dos santos'), 'Joana Maria dos Santos');
verificar('nome próprio: caixa alta', mcp_nome_proprio('MARIA DAS GRAÇAS DOS SANTOS'), 'Maria das Graças dos Santos');
verificar('nome próprio: mantém capitular interna', mcp_nome_proprio('ana McDonald'), 'Ana McDonald');
verificar('nome próprio: espaços', mcp_nome_proprio("  joão   de  souza \n"), 'João de Souza');
verificar('nome próprio: partícula no começo sobe', mcp_nome_proprio('da silva'), 'Da Silva');
verificar('cpf formatado', mcp_cpf_formatado('52998224725'), '529.982.247-25');
verificar('cpf fora do padrão volta como veio', mcp_cpf_formatado('123'), '123');
verificar('método pix', mcp_comprovante_metodo(['metodo' => 'pix']), 'PIX');
verificar('método cartão sem detalhe', mcp_comprovante_metodo(['metodo' => 'cartao']), 'Cartão de crédito');
verificar('método cartão com bandeira e final', mcp_comprovante_metodo(['metodo' => 'cartao', 'bandeira' => 'visa', 'ultimos4' => '4242']), 'Cartão de crédito · Visa final 4242');

$inscComprovante = [
    'id' => 7, 'token' => str_repeat('b', 40), 'nome' => 'ana paula de souza', 'cpf' => '52998224725', 'email' => 'a@exemplo.org',
    'curso_nome' => 'Punção Venosa', 'metodo' => 'cartao', 'bandeira' => null, 'ultimos4' => null, 'status' => 'pago',
    'inscricao_centavos' => 9900, 'taxa_centavos' => 0, 'total_centavos' => 9900,
    'criado_em' => '2026-09-22 15:10:10', 'pago_em' => '2026-09-22 15:10:41', 'unicopag_hash' => 'exemplo001',
];
$cc = mcp_comprovante_conteudo($inscComprovante, '2026-09-23 17:00:00');
verificar('comprovante: produto', $cc['subtitulo'], 'Punção Venosa — Inscrição');
verificar('comprovante: nome', $cc['nome'], 'Ana Paula de Souza');
verificar('comprovante: cpf', $cc['cpf'], 'CPF: 529.982.247-25');
verificar('comprovante: dados sem custos', $cc['inscricao'], [['Produto', 'Punção Venosa — Inscrição'], ['Quantidade', '1'], ['Valor', 'R$ 99,00']]);
verificar('comprovante: pagamento no mesmo minuto não repete a data', $cc['pagamento'], [
    ['Status do pagamento', 'Pago'], ['Método de pagamento', 'Cartão de crédito'], ['Data do pedido', '22/09/2026 12:10'],
    ['Código da compra', 'exemplo001'], ['Total', 'R$ 99,00']]);
verificar('comprovante: recebedor padrão', [$cc['recebedor'], $cc['recebedor_cnpj']], ['O-CVB FILIAL RIO DE JANEIRO ENSINO LTDA - EPP', 'CNPJ 67.733.551/0001-35']);
verificar('comprovante: rodapé com a transação', str_contains($cc['rodape'], 'transação exemplo001') && str_contains($cc['rodape'], '23/09/2026 às 14h00'), true);

$ccPix = mcp_comprovante_conteudo(['metodo' => 'pix', 'taxa_centavos' => 495, 'total_centavos' => 10395, 'pago_em' => '2026-09-22 19:48:00', 'unicopag_hash' => ''] + $inscComprovante);
verificar('comprovante: custos entram nos dados', end($ccPix['inscricao']), ['Custos de processamento', 'R$ 4,95']);
verificar('comprovante: PIX pago depois mostra as duas datas', array_slice($ccPix['pagamento'], 2, 2), [['Data do pedido', '22/09/2026 12:10'], ['Data do pagamento', '22/09/2026 16:48']]);
verificar('comprovante: sem hash vira travessão', $ccPix['pagamento'][4], ['Código da compra', '—']);
verificar('comprovante: total com custos', end($ccPix['pagamento']), ['Total', 'R$ 103,95']);
verificar('comprovante: rodapé avisa que não é nota fiscal, com e sem código da compra',
    [str_contains($cc['rodape'], '. Este comprovante não substitui nota fiscal. Dúvidas: '),
     str_contains($ccPix['rodape'], '(horário de Brasília). Este comprovante não substitui nota fiscal. Dúvidas: ')], [true, true]);

verificar('comprovante: nome do arquivo', mcp_comprovante_arquivo($inscComprovante), 'Comprovante_de_Inscricao_Puncao_Venosa_Ana_Paula_de_Souza.pdf');
$arquivoLongo = mcp_comprovante_arquivo(['curso_nome' => 'Primeiros Socorros Lei Lucas - Ambientes com Crianças', 'nome' => str_repeat('Nomecomprido ', 20)]);
verificar('comprovante: arquivo longo corta em palavra', strlen($arquivoLongo) <= 124 && str_ends_with($arquivoLongo, 'Nomecomprido.pdf'), true);

$pdfBytes = mcp_comprovante_pdf($inscComprovante, '2026-09-23 17:00:00');
verificar('pdf: cabeçalho e fim', [substr($pdfBytes, 0, 8), rtrim(substr($pdfBytes, -6))], ['%PDF-1.4', '%%EOF']);
// A tabela xref é onde PDF escrito à mão costuma quebrar: cada entrada tem de apontar exatamente
// para o "N 0 obj" do seu objeto, e o startxref para o começo da própria tabela.
preg_match('/startxref\n(\d+)\n%%EOF/', $pdfBytes, $mx);
$inicioXref = (int) ($mx[1] ?? 0);
verificar('pdf: startxref aponta para a tabela', substr($pdfBytes, $inicioXref, 4), 'xref');
preg_match('/^xref\n0 (\d+)\n/', substr($pdfBytes, $inicioXref), $mc);
$entradas = (int) ($mc[1] ?? 0);
$xrefOk = $entradas === 9;
for ($num = 1; $xrefOk && $num < $entradas; $num++) {
    $linha = substr($pdfBytes, $inicioXref + strlen("xref\n0 $entradas\n") + 20 * $num, 20);
    $xrefOk = str_starts_with(substr($pdfBytes, (int) substr($linha, 0, 10)), "$num 0 obj");
}
verificar('pdf: 9 entradas na xref e todas certas', $xrefOk, true);
preg_match('/\/Length (\d+) \/Filter \/FlateDecode >>\nstream\n/', $pdfBytes, $ml, PREG_OFFSET_CAPTURE);
$fluxo = gzuncompress(substr($pdfBytes, $ml[0][1] + strlen($ml[0][0]), (int) $ml[1][0])) ?: '';
verificar('pdf: título em cp1252 no conteúdo', str_contains($fluxo, "(COMPROVANTE DE INSCRI\xC7\xC3O)"), true);
verificar('pdf: travessão em cp1252', str_contains($fluxo, "(Pun\xE7\xE3o Venosa \x97 Inscri\xE7\xE3o)"), true);
verificar('pdf: empresa recebedora', str_contains($fluxo, '(O-CVB FILIAL RIO DE JANEIRO ENSINO LTDA - EPP)'), true);
verificar('pdf: logo embutido como paleta', (bool) preg_match('/\/ColorSpace \[\/Indexed \/DeviceRGB \d+ </', $pdfBytes), true);
verificar('pdf: tamanho razoável para anexo', strlen($pdfBytes) > 20000 && strlen($pdfBytes) < 80000, true);
verificar('pdf: parênteses do texto escapados', str_contains((function () {
    $p = new McpPdf();
    $p->texto(10, 10, 'a (b) c\\d', 'normal', 10, '#000000');
    return gzuncompress(substr($s = $p->gerar(), strpos($s, "stream\n") + 7, (int) (preg_match('/\/Length (\d+)/', $s, $m) ? $m[1] : 0))) ?: '';
})(), '(a \\(b\\) c\\\\d)'), true);
verificar('pdf: quebra de linha respeita a largura', array_map(static fn(string $l): bool => McpPdf::larguraTexto($l, 'negrito', 11, 0) <= 300,
    McpPdf::quebrar('Primeiros Socorros Lei Lucas - Ambientes com Crianças — Inscrição', 'negrito', 11, 300)), [true, true]);
verificar('pdf: palavra maior que a linha é partida', count(McpPdf::quebrar(str_repeat('x', 200), 'normal', 10, 100)) > 1, true);

$emailCom = mcp_montar_email_aluno_pago($inscComprovante, true);
$emailSem = mcp_montar_email_aluno_pago($inscComprovante, false);
verificar('e-mail pago cita o anexo quando há PDF', str_contains($emailCom['html'], 'comprovante de inscrição em PDF vai anexado') && str_contains($emailCom['texto'], 'PDF vai anexado'), true);
verificar('e-mail pago não promete anexo sem PDF', str_contains($emailSem['html'] . $emailSem['texto'], 'anexado'), false);
verificar('e-mail pago sem PDF mantém o texto antigo', str_contains($emailSem['texto'], 'este e-mail é o seu comprovante'), true);

unlink($configTeste);
printf("%d testes, %d falhas\n", $total, $falhas);
exit($falhas > 0 ? 1 : 0);
