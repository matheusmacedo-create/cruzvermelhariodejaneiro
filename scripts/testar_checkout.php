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
// Chave da escola num arquivo à parte, como no servidor: só as chaves ESCOLA_* podem valer.
$configEscola = tempnam(sys_get_temp_dir(), 'mcp-escola-');
file_put_contents($configEscola, "<?php return ['ESCOLA_API_URL' => 'https://escola-db.exemplo.org/rest/v1/rpc/matricula_rapida',
    'ESCOLA_API_TOKEN' => 'chave-de-teste', 'SITE_URL' => 'https://nao-pode-valer.exemplo.org'];");
putenv("MCP_CONFIG_ESCOLA_ARQUIVO=$configEscola");
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
    'criado_em' => '2026-09-18 00:00:00', 'pago_em' => '2026-09-18 00:01:00', 'escola_status' => 'ok', 'escola_tentativas' => 1,
    'escola_acesso' => json_encode(['resultado' => 'matriculado', 'aluno_novo' => true, 'email_conta' => 'm***@exemplo.org', 'email_confere' => true,
        'matricula_id' => 'm-1', 'turma_inicio' => '2026-10-21', 'aviso' => null, 'link' => true, 'link_expira_em' => '2099-01-01T15:00:00Z',
        'url_login' => 'https://escola.exemplo.org/login']),
    'escola_token' => str_repeat('ab', 32),
];
$linkEscola = 'https://escola.exemplo.org/redefinir-senha?token=' . str_repeat('ab', 32);
$publico = mcp_publico($inscricao);
$serializado = json_encode($publico);
verificar('público sem cpf', str_contains($serializado, '52998224725'), false);
verificar('público sem hash', str_contains($serializado, 'h4sh'), false);
verificar('público sem ip', str_contains($serializado, '10.0.0.1'), false);
verificar('público sem telefone', str_contains($serializado, '21999998888'), false);
verificar('público primeiro nome', $publico['nome'], 'Maria');
verificar('público cartão', $publico['cartao'], ['bandeira' => 'visa', 'ultimos4' => '1111']);
verificar('público pix nulo', $publico['pix'], null);
verificar('público matrícula na escola quando pago', [$publico['escola']['resultado'], $publico['escola']['aluno_novo'], $publico['escola']['turma_inicio']], ['matriculado', true, '2026-10-21']);
verificar('público link de entrada da escola', $publico['escola']['url'], 'https://escola.exemplo.org/login');
verificar('público nunca traz senha', array_key_exists('senha', $publico['escola']), false);
verificar('público link de criar senha quando pago', [$publico['escola']['link'], $publico['escola']['link_validade']], [$linkEscola, '01/01 às 12h00']);
verificar('público sem link quando vencido', mcp_publico(['escola_acesso' => json_encode(['link_expira_em' => '2020-01-01T00:00:00Z'] + json_decode($inscricao['escola_acesso'], true))] + $inscricao)['escola']['link'], null);
verificar('público sem link sem token', mcp_publico(['escola_token' => null] + $inscricao)['escola']['link'], null);
$pendente = mcp_publico(['status' => 'pendente'] + $inscricao);
verificar('público sem acesso quando pendente', [$pendente['escola']['resultado'], $pendente['escola']['link']], [null, null]);
verificar('público tentativas esgotadas', mcp_publico(['escola_status' => 'erro', 'escola_tentativas' => 5, 'escola_acesso' => null] + $inscricao)['escola']['esgotado'], true);

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
verificar('pago A assunto', $a['assunto'], 'Matrícula feita: seu acesso à plataforma da escola — Curso X');
verificar('pago A conta nova: botão de criar senha com o link', str_contains($a['html'], 'Criar minha senha') && str_contains($a['html'], $linkEscola) && str_contains($a['texto'], $linkEscola), true);
verificar('pago A conta nova: validade do link', str_contains($a['html'], 'o link vale até 01/01 às 12h00'), true);
verificar('pago A nunca usa os 4 últimos dígitos do CPF', str_contains($a['html'] . $a['texto'], '4 últimos') || str_contains($a['html'] . $a['texto'], '4725'), false);
$vencido = ['escola_acesso' => json_encode(['link_expira_em' => '2020-01-01T00:00:00Z'] + json_decode($inscricao['escola_acesso'], true))] + $inscricao;
$aVencido = mcp_montar_email_aluno_pago($vencido);
verificar('pago A link vencido: Esqueci minha senha', str_contains($aVencido['html'], 'Esqueci minha senha') && !str_contains($aVencido['html'], 'redefinir-senha'), true);
verificar('pago A turma', str_contains($a['html'], '21/10/2026') && str_contains($a['texto'], '21/10/2026'), true);
verificar('pago A link de entrada', str_contains($a['html'], 'https://escola.exemplo.org/login') && str_contains($a['html'], 'Entrar na plataforma da escola'), true);
$existente = ['escola_acesso' => json_encode(['resultado' => 'matriculado', 'aluno_novo' => false, 'email_conta' => 'o***@exemplo.com', 'email_confere' => false,
    'turma_inicio' => '2026-10-21', 'aviso' => null, 'url_login' => 'https://escola.exemplo.org/login'])] + $inscricao;
$aExistente = mcp_montar_email_aluno_pago($existente);
verificar('pago A conta existente com outro e-mail', str_contains($aExistente['html'], 'o***@exemplo.com') && str_contains($aExistente['html'], 'senha de sempre')
    && !str_contains($aExistente['html'], 'redefinir-senha'), true);
$semTurma = ['escola_acesso' => json_encode(['resultado' => 'sem_turma', 'aluno_novo' => true, 'email_conta' => 'm***@exemplo.org', 'email_confere' => true,
    'turma_inicio' => null, 'aviso' => null, 'url_login' => 'https://escola.exemplo.org/login'])] + $inscricao;
$aSemTurma = mcp_montar_email_aluno_pago($semTurma);
verificar('pago A sem turma', $aSemTurma['assunto'] === 'Inscrição paga: sua conta na plataforma da escola — Curso X'
    && str_contains($aSemTurma['html'], 'matricula você na próxima turma'), true);
$conflito = ['escola_status' => 'erro', 'escola_tentativas' => 5, 'escola_acesso' => json_encode(['erro' => 'email_em_uso'])] + $inscricao;
verificar('pago com conflito na escola vai na versão B', mcp_montar_email_aluno_pago($conflito)['tipo'], 'confirmacao');
verificar('escola: resumo matriculado', mcp_escola_resumo($inscricao), 'matriculado na turma que começa em 21/10/2026, conta criada pelo site, taxa confirmada');
verificar('escola: resumo conta existente', str_contains(mcp_escola_resumo($existente), 'conta que já existia (e-mail da conta: o***@exemplo.com)'), true);
verificar('escola: resumo sem turma', str_starts_with(mcp_escola_resumo($semTurma), 'SEM TURMA ABERTA: conta criada pelo site.'), true);
verificar('escola: resumo conflito', mcp_escola_resumo($conflito), 'NÃO MATRICULADO: o e-mail já está em outra conta da escola (com outro CPF). Criar a matrícula à mão e aplicar o valor da inscrição');
verificar('escola: resumo taxa repetida', str_contains(mcp_escola_resumo(['escola_acesso' => json_encode(['resultado' => 'matriculado', 'aluno_novo' => false, 'email_confere' => true,
    'turma_inicio' => '2026-10-21', 'aviso' => 'taxa_ja_confirmada'])] + $inscricao), 'avaliar o estorno'), true);
verificar('escola: resumo falha técnica', str_starts_with(mcp_escola_resumo(['escola_status' => 'erro', 'escola_acesso' => null] + $inscricao), 'falha técnica'), true);
$senhaA = mcp_escola_senha_aleatoria();
$senhaB = mcp_escola_senha_aleatoria();
verificar('senha de conta nova em argon2id', is_string($senhaA) && str_starts_with($senhaA, '$argon2id$v=19$'), true);
verificar('senha de conta nova é aleatória (nunca os 4 últimos dígitos)', $senhaA !== $senhaB && !password_verify('4725', (string) $senhaA), true);
verificar('link da escola aceito', mcp_escola_url_login('https://escola.exemplo.org/login'), 'https://escola.exemplo.org/login');
verificar('link de outro site vira o padrão', mcp_escola_url_login('https://golpe.exemplo.com/login'), 'https://escola.exemplo.org/login');
verificar('link sem https vira o padrão', mcp_escola_url_login('http://escola.exemplo.org/login'), 'https://escola.exemplo.org/login');
verificar('data da turma', [mcp_escola_data('2026-10-21'), mcp_escola_data('ontem'), mcp_escola_data(null)], ['21/10/2026', '', '']);
verificar('config escola: só ESCOLA_* valem', [mcp_cfg('ESCOLA_API_TOKEN'), mcp_site_url(), mcp_escola_configurada()], ['chave-de-teste', 'https://exemplo.org', true]);
$b = mcp_montar_email_aluno_pago(['escola_acesso' => null] + $inscricao);
verificar('pago B tipo', $b['tipo'], 'confirmacao');
verificar('pago B assunto', $b['assunto'], 'Inscrição confirmada: sua vaga em Curso X');
verificar('pago B próximos passos por e-mail', str_contains($b['html'], 'por e-mail em até 3 dias úteis') && str_contains($b['html'], 'Ver minha inscrição'), true);
verificar('pago B cartão final', str_contains($b['html'], 'Cartão final 1111'), true);
verificar('pago sem whatsapp', stripos($a['html'] . $b['html'] . $a['texto'] . $b['texto'], 'whatsapp'), false);
$sec = mcp_montar_email_secretaria($inscricao);
verificar('secretaria telefone', str_contains($sec['html'], 'Telefone') && str_contains($sec['texto'], "Telefone: 21999998888\n"), true);
verificar('secretaria linha da escola', str_contains($sec['texto'], "Escola: matriculado na turma que começa em 21/10/2026, conta criada pelo site, taxa confirmada\n"), true);
verificar('secretaria: aluno já matriculado', str_contains($sec['html'], 'já está matriculado na plataforma da escola'), true);
verificar('secretaria: sem integração pede contato', str_contains(mcp_montar_email_secretaria($conflito)['html'], 'Entrar em contato com o aluno'), true);
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

// Questionário de dias e horários (lib/horarios.php): conferência, texto, mapa, planilha, visão pública, e-mails e lembretes.
verificar('horários: 18 combinações dia-período', [count(mcp_horarios_slots()), mcp_horarios_slots()[0], mcp_horarios_slots()[17]], [18, 'seg-manha', 'sab-noite']);
verificar('horários: lista na ordem da semana, sem repetição nem valor desconhecido', mcp_horarios_conferir(
    ['horarios' => ['sab-manha', 'seg-noite', 'seg-noite', 'dom-noite', 'seg-madrugada', 7], 'inicio' => 'proxima', 'turma_serve' => 'sim', 'observacao' => "  Só depois\n\n\n das 19h  "], false),
    ['ok' => true, 'dados' => ['horarios' => ['seg-noite', 'sab-manha'], 'inicio' => 'proxima', 'turma_serve' => null, 'observacao' => "Só depois\n\ndas 19h"]]);
$respostaValida = ['horarios' => ['ter-manha'], 'inicio' => '1mes'];
$campoDoErro = static fn(array $b, bool $turma = false): ?string => mcp_horarios_conferir($b + $respostaValida, $turma)['campo'] ?? null;
verificar('horários: campo de cada erro', [
    $campoDoErro(['horarios' => []]), $campoDoErro(['horarios' => 'seg-noite']), $campoDoErro(['horarios' => ['dom-manha']]),
    $campoDoErro(['inicio' => 'ontem']), $campoDoErro([], true), $campoDoErro(['turma_serve' => 'talvez'], true),
    $campoDoErro(['observacao' => str_repeat('a', 501)]), $campoDoErro(['observacao' => str_repeat('á', 500)]), $campoDoErro(['turma_serve' => 'nao'], true),
], ['horarios', 'horarios', 'horarios', 'inicio', 'turma_serve', 'turma_serve', 'observacao', null, null]);
verificar('horários: mensagem do erro', mcp_horarios_conferir(['horarios' => []] + $respostaValida, false)['erro'], 'Marque pelo menos um horário em que você consegue vir.');
verificar('horários: com turma guarda se a data serve', mcp_horarios_conferir(['turma_serve' => 'nao'] + $respostaValida, true)['dados']['turma_serve'], 'nao');
verificar('horários: recado vazio vira nulo', mcp_horarios_conferir(['observacao' => '   '] + $respostaValida, false)['dados']['observacao'], null);
verificar('horários: linha do banco ignora valor desconhecido', mcp_horarios_linha(['horarios' => 'sab-manha,xxx,seg-noite'])['horarios'], ['seg-noite', 'sab-manha']);
verificar('horários: lista em português', [mcp_horarios_lista([]), mcp_horarios_lista(['A']), mcp_horarios_lista(['A', 'B']), mcp_horarios_lista(['A', 'B', 'C'])], ['', 'A', 'A e B', 'A, B e C']);
verificar('horários: dias em faixa', [mcp_horarios_dias_texto(['seg', 'ter', 'qua', 'qui', 'sex']), mcp_horarios_dias_texto(['seg', 'ter', 'qua', 'sex']),
    mcp_horarios_dias_texto(['ter', 'qui']), mcp_horarios_dias_texto(['sab']), mcp_horarios_dias_texto(['seg', 'ter'])],
    ['Seg a Sex', 'Seg a Qua e Sex', 'Ter e Qui', 'Sáb', 'Seg e Ter']);
verificar('horários: texto agrupa dias com os mesmos períodos', [
    mcp_horarios_texto(['seg-noite', 'ter-noite', 'qua-noite', 'qui-noite', 'sex-noite', 'sab-manha', 'sab-tarde']),
    mcp_horarios_texto(['sab-manha', 'sab-tarde', 'sab-noite']),
    mcp_horarios_texto(['ter-manha', 'qui-manha', 'qui-noite']),
], ['Seg a Sex à noite; Sáb de manhã e à tarde', 'Sáb o dia todo', 'Ter de manhã; Qui de manhã e à noite']);
$preferencia = ['horarios' => ['seg-noite', 'qua-noite', 'sab-manha'], 'inicio' => 'proxima', 'turma_serve' => 'nao', 'observacao' => '<b>Só</b> depois das 19h',
    'atualizado_em' => '2026-09-29 15:30:00', 'vezes' => 2];
verificar('horários: resumo de uma linha', mcp_horarios_resumo($preferencia), 'Seg e Qua à noite; Sáb de manhã · Já na próxima turma');
$urlHorarios = 'https://exemplo.org/matricula-cursos-presenciais/horarios/?t=' . $token;
verificar('horários: público convida quando pago', mcp_publico($inscricao)['horarios'], ['url' => $urlHorarios, 'respondido' => false, 'resumo' => null]);
verificar('horários: público mostra o resumo do que respondeu', mcp_publico($inscricao, $preferencia)['horarios'],
    ['url' => $urlHorarios, 'respondido' => true, 'resumo' => 'Seg e Qua à noite; Sáb de manhã · Já na próxima turma']);
verificar('horários: público sem questionário quando pendente', mcp_publico(['status' => 'pendente'] + $inscricao, $preferencia)['horarios'], null);
verificar('horários: público não traz o recado', str_contains(json_encode(mcp_publico($inscricao, $preferencia)), 'depois das 19h'), false);
$tela = mcp_horarios_para_tela($inscricao, $preferencia);
verificar('horários: tela com nome, curso, turma e resposta', [$tela['nome'], $tela['curso']['nome'], $tela['turma_inicio'], $tela['resposta']['horarios'], $tela['resposta']['turma_serve']],
    ['Maria', 'Curso X', '2026-10-21', ['seg-noite', 'qua-noite', 'sab-manha'], 'nao']);
verificar('horários: tela com opções, atalhos e limite do recado', [array_keys($tela['opcoes']['dias']), $tela['opcoes']['horas']['noite'], $tela['opcoes']['observacao_max'],
    array_column($tela['opcoes']['atalhos'], 'chave'), $tela['opcoes']['atalhos'][1]['horarios']],
    [['seg', 'ter', 'qua', 'qui', 'sex', 'sab'], '18h às 22h', 500, ['noites', 'sabado', 'manhas', 'tardes'], ['sab-manha', 'sab-tarde']]);
verificar('horários: atalhos só com combinações válidas', array_values(array_filter(array_merge(...array_column($tela['opcoes']['atalhos'], 'horarios')),
    static fn(string $x): bool => !in_array($x, mcp_horarios_slots(), true))), []);
verificar('horários: tela sem turma quando a escola não matriculou', [mcp_horarios_para_tela($semTurma, null)['turma_inicio'], mcp_horarios_para_tela(['escola_acesso' => null] + $inscricao, null)['turma_inicio']], [null, null]);
$telaJson = json_encode(mcp_horarios_para_tela($inscricao, null));
verificar('horários: tela sem cpf, e-mail nem telefone', str_contains($telaJson, '52998224725') || str_contains($telaJson, 'maria@exemplo.org') || str_contains($telaJson, '21999998888'), false);

$mapa = mcp_horarios_mapa([
    ['horarios' => ['seg-noite', 'qua-noite'], 'inicio' => 'proxima', 'turma_serve' => 'sim'],
    ['horarios' => ['qua-manha', 'qua-noite'], 'inicio' => '1mes', 'turma_serve' => 'nao'],
    ['horarios' => ['sab-manha'], 'inicio' => 'proxima', 'turma_serve' => null],
]);
verificar('horários: mapa conta exatamente cada horário marcado', [$mapa['grade']['noite']['qua'], $mapa['grade']['noite']['seg'], $mapa['grade']['manha']['qua'],
    $mapa['grade']['manha']['sab'], $mapa['grade']['tarde']['qua'], $mapa['grade']['noite']['sab'], $mapa['grade']['manha']['seg']], [2, 1, 1, 1, 0, 0, 0]);
verificar('horários: mapa máximo, começo, data serve e total', [$mapa['maximo'], $mapa['inicio'], $mapa['turma'], $mapa['total']],
    [2, ['proxima' => 2, '1mes' => 1, '2meses' => 0], ['sim' => 1, 'nao' => 1], 3]);
verificar('horários: mapa vazio', [mcp_horarios_mapa([])['maximo'], mcp_horarios_mapa([])['total']], [0, 0]);

verificar('horários: planilha sem fórmula', array_map('mcp_horarios_celula', ['=1+1', '+55', '-2', '@SOMA', "\tx", 'Maria', '(21) 9']),
    ["'=1+1", "'+55", "'-2", "'@SOMA", "'\tx", 'Maria', '(21) 9']);
$csv = mcp_horarios_csv([['nome' => '=HYPERLINK("http://x")', 'email' => 'maria@exemplo.org', 'telefone' => '21999998888', 'curso_nome' => 'Curso X',
    'escola_acesso' => $inscricao['escola_acesso'], 'observacao' => "Prefiro sábado; \"de manhã\"\nobrigado"] + $preferencia]);
$fcsv = fopen('php://memory', 'w+');
fwrite($fcsv, substr($csv, 3));
rewind($fcsv);
$cabecalho = fgetcsv($fcsv, null, ';', '"', '');
$linhaCsv = fgetcsv($fcsv, null, ';', '"', '');
fclose($fcsv);
verificar('horários: planilha com BOM, 10 colunas e uma por horário', [str_starts_with($csv, "\xEF\xBB\xBF"), count($cabecalho), count($linhaCsv), $cabecalho[0], $cabecalho[10], $cabecalho[27]],
    [true, 28, 28, 'Respondido em (Brasília)', 'Seg manhã', 'Sáb noite']);
verificar('horários: planilha com os valores por extenso', array_slice($linhaCsv, 0, 10), ['29/09/2026 12:30', "'=HYPERLINK(\"http://x\")", 'maria@exemplo.org', '(21) 99999-8888', 'Curso X',
    '21/10/2026', 'Seg e Qua à noite; Sáb de manhã', 'Já na próxima turma', 'Não, preciso de outra data', "Prefiro sábado; \"de manhã\"\nobrigado"]);
verificar('horários: planilha marca x nos horários', [$linhaCsv[10 + 2], $linhaCsv[10 + 8], $linhaCsv[10 + 15], $linhaCsv[10 + 0]], ['x', 'x', 'x', '']);

$avisoHorarios = mcp_montar_email_horarios($inscricao, $preferencia);
verificar('horários: aviso à secretaria assunto', $avisoHorarios['assunto'], 'Horários: Maria da Silva — Curso X');
verificar('horários: aviso com horários, turma e recado escapado', str_contains($avisoHorarios['html'], 'Seg e Qua à noite; Sáb de manhã')
    && str_contains($avisoHorarios['html'], 'começa em 21/10/2026') && str_contains($avisoHorarios['html'], 'Não, preciso de outra data')
    && str_contains($avisoHorarios['html'], '&lt;b&gt;Só&lt;/b&gt;') && !str_contains($avisoHorarios['html'], '<b>Só</b>'), true);
verificar('horários: aviso leva ao mapa do curso no painel', str_contains($avisoHorarios['texto'], 'https://exemplo.org/matricula-cursos-presenciais/api/painel.php?v=horarios&curso=' . rawurlencode($primeiro))
    && str_contains($avisoHorarios['html'], 'Ver o mapa do curso no painel'), true);
verificar('horários: aviso sem whatsapp', stripos($avisoHorarios['html'] . $avisoHorarios['texto'], 'whatsapp'), false);
$emailA = mcp_montar_email_aluno_pago($inscricao);
$emailB = mcp_montar_email_aluno_pago(['escola_acesso' => null] + $inscricao);
$emailSemTurma = mcp_montar_email_aluno_pago($semTurma);
verificar('horários: e-mail pago A traz o quadro "Falta 1 passo"', str_contains($emailA['html'], 'Falta 1 passo') && str_contains($emailA['html'], 'Quando você pode fazer as aulas?')
    && str_contains($emailA['html'], $urlHorarios) && str_contains($emailA['texto'], $urlHorarios), true);
verificar('horários: no A o quadro vem antes dos próximos passos e o botão é contornado', strpos($emailA['html'], 'Falta 1 passo') < strpos($emailA['html'], 'Próximos passos')
    && str_contains($emailA['html'], 'bgcolor="#ffffff" style="border-radius:999px;background:#ffffff;border:1.5px solid #cc0000"><a href="' . $urlHorarios), true);
verificar('horários: e-mail com turma fala da data', str_contains($emailA['html'], 'se a da sua turma não servir'), true);
verificar('horários: e-mail sem turma não fala de data', str_contains($emailSemTurma['html'], $urlHorarios) && !str_contains($emailSemTurma['html'], 'da sua turma não servir'), true);
verificar('horários: no B o quadro vem logo depois do valor, com botão cheio', strpos($emailB['html'], 'Falta 1 passo') < strpos($emailB['html'], 'Próximos passos')
    && str_contains($emailB['html'], 'bgcolor="#cc0000" style="border-radius:999px;background:#cc0000;border:1.5px solid #cc0000"><a href="' . $urlHorarios)
    && str_contains($emailB['texto'], $urlHorarios), true);

// Lembretes: 24 h e 72 h depois do pagamento, no máximo dois, com 48 h entre eles.
$pagoEm = '2026-10-01 12:00:00';
$t0 = (int) strtotime($pagoEm . ' UTC');
verificar('lembrete: quando cabe cada um', [
    mcp_horarios_lembrete_devido($pagoEm, 0, null, $t0 + 23 * 3600),
    mcp_horarios_lembrete_devido($pagoEm, 0, null, $t0 + 24 * 3600),
    mcp_horarios_lembrete_devido($pagoEm, 1, '2026-10-02 12:00:00', $t0 + 71 * 3600),
    mcp_horarios_lembrete_devido($pagoEm, 1, '2026-10-02 12:00:00', $t0 + 72 * 3600),
    mcp_horarios_lembrete_devido($pagoEm, 1, '2026-10-03 20:00:00', $t0 + 72 * 3600),
    mcp_horarios_lembrete_devido($pagoEm, 2, '2026-10-04 12:00:00', $t0 + 30 * 86400),
], [0, 1, 0, 2, 0, 0]);
$lembrete1 = mcp_montar_email_lembrete_horarios($inscricao, 1);
$lembrete2 = mcp_montar_email_lembrete_horarios($semTurma, 2);
verificar('lembrete: assuntos', [$lembrete1['assunto'], $lembrete2['assunto']],
    ['Falta 1 passo: quando você pode fazer as aulas de Curso X?', 'Ainda dá tempo: seus horários para Curso X']);
verificar('lembrete: link com utm e botão', str_contains($lembrete1['html'], $urlHorarios . '&amp;utm_source=email&amp;utm_medium=transacional&amp;utm_campaign=lembrete-horarios-1')
    && str_contains($lembrete1['html'], 'Escolher meus horários') && str_contains($lembrete1['texto'], $urlHorarios . '&utm_source=email'), true);
verificar('lembrete: com turma cita a data, sem turma não', str_contains($lembrete1['html'], '21/10/2026') && !str_contains($lembrete2['html'], 'Sua turma começa'), true);
verificar('lembrete: texto puro sem tag nem entidade', !preg_match('/<|&[a-z]+;/', $lembrete1['texto'] . $lembrete2['texto']), true);
verificar('lembrete: sem whatsapp', stripos($lembrete1['html'] . $lembrete2['html'], 'whatsapp'), false);

unlink($configTeste);
unlink($configEscola);
printf("%d testes, %d falhas\n", $total, $falhas);
exit($falhas > 0 ? 1 : 0);
