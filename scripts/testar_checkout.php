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
    'ESCOLA_API_TOKEN' => 'chave-de-teste', 'SITE_HORARIOS_TOKEN' => '" . str_repeat('k', 40) . "',
    'SITE_URL' => 'https://nao-pode-valer.exemplo.org'];");
putenv("MCP_CONFIG_ESCOLA_ARQUIVO=$configEscola");
// Token da API de Conversões num arquivo à parte, como no servidor: só as chaves META_* valem. O endereço da
// Meta aponta para uma porta local fechada: nenhum teste fala com a Meta de verdade.
$configMeta = tempnam(sys_get_temp_dir(), 'mcp-meta-');
file_put_contents($configMeta, "<?php return ['META_CAPI_TOKEN' => 'token-de-teste', 'META_CAPI_URL' => 'http://127.0.0.1:9',
    'SITE_URL' => 'https://nao-pode-valer.exemplo.org'];");
putenv("MCP_CONFIG_META_ARQUIVO=$configMeta");
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
verificar('link de entrada no e-mail', str_contains($entrada['html'], 'painel.php?entrar=a%40b.co&amp;e=1&amp;k=abc') && str_contains($entrada['html'], 'Entrar no portal'), true);
verificar('link de entrada: portal da secretaria no assunto e no topo', [$entrada['assunto'], str_contains($entrada['html'], 'Portal da secretaria'), str_contains($entrada['texto'], 'Entrar no portal da secretaria')],
    ['Acesso ao portal da secretaria', true, true]);
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

// Portal da secretaria (lib/secretaria.php): situação na escola, filtros, busca, histórico e planilha.
$comEscola = static fn(array $acesso, string $status = 'ok'): array => ['escola_status' => $status, 'escola_acesso' => json_encode($acesso)] + $inscricao;
$situacoes = [
    'matriculado' => $inscricao,
    'sem_turma' => $semTurma,
    'taxa' => $comEscola(['resultado' => 'matriculado', 'turma_inicio' => '2026-10-21', 'aviso' => 'taxa_ja_confirmada']),
    'recusado' => $comEscola(['erro' => 'email_em_uso'], 'erro'),
    'recusa_nova' => $comEscola(['erro' => 'motivo_novo'], 'erro'),
    'falha' => ['escola_status' => 'erro', 'escola_acesso' => null] + $inscricao,
    'andamento' => ['escola_status' => 'pendente', 'escola_acesso' => null] + $inscricao,
    'antes' => ['escola_status' => 'nao_aplicavel', 'escola_acesso' => null] + $inscricao,
    'pendente' => ['status' => 'pendente', 'pago_em' => null] + $inscricao,
];
verificar('secretaria: situação na escola', array_map('mcp_secretaria_escola', $situacoes), [
    'matriculado' => ['rotulo' => 'Matriculado · turma de 21/10/2026', 'tom' => 'ok'],
    'sem_turma' => ['rotulo' => 'Sem turma aberta', 'tom' => 'alerta'],
    'taxa' => ['rotulo' => 'Taxa já estava paga na escola', 'tom' => 'alerta'],
    'recusado' => ['rotulo' => 'Não matriculado', 'tom' => 'erro'],
    'recusa_nova' => ['rotulo' => 'Não matriculado', 'tom' => 'erro'],
    'falha' => ['rotulo' => 'Falha na integração', 'tom' => 'erro'],
    'andamento' => ['rotulo' => 'Matrícula em andamento', 'tom' => 'neutro'],
    'antes' => ['rotulo' => 'Sem integração na época', 'tom' => 'neutro'],
    'pendente' => ['rotulo' => '—', 'tom' => 'neutro'],
]);
verificar('secretaria: conta do aluno na escola', [mcp_secretaria_escola_conta($inscricao), mcp_secretaria_escola_conta($situacoes['recusado']),
    mcp_secretaria_escola_conta($comEscola(['resultado' => 'matriculado', 'aluno_novo' => false, 'email_confere' => true])),
    mcp_secretaria_escola_conta($comEscola(['resultado' => 'matriculado', 'aluno_novo' => false, 'email_confere' => false, 'email_conta' => 'm***@exemplo.org']))],
    ['Conta criada pelo site, com o e-mail da inscrição', null, 'O aluno já tinha conta na escola, com o mesmo e-mail', 'O aluno já tinha conta na escola, com outro e-mail: m***@exemplo.org']);
verificar('secretaria: precisa de atenção só quando pago com pendência na escola', array_map('mcp_secretaria_precisa_atencao', $situacoes),
    ['matriculado' => false, 'sem_turma' => true, 'taxa' => true, 'recusado' => true, 'recusa_nova' => true, 'falha' => true, 'andamento' => false, 'antes' => false, 'pendente' => false]);
$orientacoes = array_map('mcp_secretaria_escola_orientacao', $situacoes);
verificar('secretaria: orientação diz o que fazer', [
    str_starts_with($orientacoes['matriculado'], 'Nada a fazer'), str_contains($orientacoes['sem_turma'], 'quando abrir turma'),
    str_contains($orientacoes['taxa'], 'estorno'), str_contains($orientacoes['recusado'], 'o e-mail já está em outra conta da escola, com outro CPF'),
    str_contains($orientacoes['recusa_nova'], 'motivo_novo'), str_contains($orientacoes['falha'], 'problema técnico'), str_contains($orientacoes['antes'], '28/09/2026'),
], [true, true, true, true, true, true, true]);
// O filtro "Precisam de atenção" no banco tem de achar o mesmo que a regra em PHP: o JSON gravado pela escola casa com o LIKE.
$casaAtencao = static function (array $i): bool {
    $acesso = (string) $i['escola_acesso'];
    return $i['status'] === 'pago' && ($i['escola_status'] === 'erro' || str_contains($acesso, '"resultado":"sem_turma"') || str_contains($acesso, '"aviso":"taxa_ja_confirmada"'));
};
verificar('secretaria: condição SQL espelha a regra em PHP', array_map($casaAtencao, $situacoes), array_map('mcp_secretaria_precisa_atencao', $situacoes));
verificar('secretaria: condição de cada filtro', array_map('mcp_secretaria_condicao', array_keys(MCP_SECRETARIA_FILTROS)), [
    "i.status = 'pago'",
    "i.status = 'pago' AND (i.escola_status = 'erro' OR i.escola_acesso LIKE '%\"resultado\":\"sem_turma\"%' OR i.escola_acesso LIKE '%\"aviso\":\"taxa_ja_confirmada\"%')",
    "i.status = 'pago' AND p.inscricao_id IS NULL", "i.status = 'pendente'", '1 = 1',
]);
verificar('secretaria: filtro desconhecido não filtra', mcp_secretaria_condicao('<x>'), '1 = 1');
verificar('secretaria: busca por nome ou e-mail, com curso', mcp_secretaria_where('pagas', 'curso-x', ' Maria '),
    ["i.status = 'pago' AND i.curso_slug = ? AND (i.nome LIKE ? OR i.email LIKE ?)", ['curso-x', '%Maria%', '%Maria%']]);
verificar('secretaria: busca escapa curinga do LIKE', mcp_secretaria_where('todas', null, '50%_a\\b')[1], ['%50\\%\\_a\\\\b%', '%50\\%\\_a\\\\b%']);
verificar('secretaria: busca por telefone com 4 dígitos ou mais', mcp_secretaria_where('todas', null, '(21) 9999')[1], ['%(21) 9999%', '%(21) 9999%', '%219999%']);
verificar('secretaria: busca por CPF só com 11 dígitos', [mcp_secretaria_where('todas', null, '529.982.247-25'), mcp_secretaria_where('todas', '', '')],
    [["1 = 1 AND (i.nome LIKE ? OR i.email LIKE ? OR i.telefone LIKE ? OR i.cpf = ?)", ['%529.982.247-25%', '%529.982.247-25%', '%52998224725%', '52998224725']], ['1 = 1', []]]);
verificar('secretaria: histórico em linguagem da secretaria', [
    mcp_secretaria_evento('cobranca_criada', 'pix · waiting_payment · R$ 103,95'),
    mcp_secretaria_evento('pago', null),
    mcp_secretaria_evento('escola_ok', 'tentativa 1 · matriculado'),
    mcp_secretaria_evento('escola_ok', 'tentativa 2 · sem_turma · repetido'),
    mcp_secretaria_evento('escola_ok', 'tentativa 1 · matriculado · taxa_ja_confirmada'),
    mcp_secretaria_evento('escola_erro', 'tentativa 1 · HTTP 200 · email_em_uso'),
    mcp_secretaria_evento('escola_erro', 'tentativa 3 · HTTP 503'),
    mcp_secretaria_evento('email_aluno', 'acesso · resend · com comprovante PDF'),
    mcp_secretaria_evento('email_aluno', 'b · falhou · sem comprovante PDF'),
    mcp_secretaria_evento('email_secretaria', 'falhou'),
    mcp_secretaria_evento('lembrete_horarios', '#1 resend'),
    mcp_secretaria_evento('lembrete_horarios', '#2 manual ana@exemplo.org · resend'),
], [
    'Cobrança criada: PIX, R$ 103,95', 'Pagamento confirmado', 'Escola: matrícula feita', 'Escola: conta pronta, mas sem turma aberta (confirmação repetida)',
    'Escola: matrícula feita (a taxa já estava confirmada)', 'Escola: a matrícula não foi feita (o e-mail já está em outra conta da escola, com outro CPF)',
    'Escola: a matrícula não foi feita (HTTP 503)', 'E-mail de inscrição paga enviado ao aluno, com o comprovante em PDF',
    'E-mail de inscrição paga enviado ao aluno (o envio falhou)', 'Aviso de inscrição paga enviado à secretaria (o envio falhou)',
    'Lembrete de horários enviado ao aluno', 'Lembrete de horários enviado ao aluno, à mão, por ana@exemplo.org',
]);
verificar('secretaria: histórico esconde IP e o que é técnico', [mcp_secretaria_evento('horarios', '10.0.0.1'), mcp_secretaria_evento('armadilha', '10.0.0.1'),
    mcp_secretaria_evento('painel_lembrete', '#1 · a@b.co · enviado'), mcp_secretaria_evento('limite', 'ip · 5 em 60s')], ['O aluno salvou os horários', null, null, null]);
$linhaPortal = ['preferencia' => ['horarios' => ['seg-noite', 'sab-manha'], 'inicio' => 'proxima', 'observacao' => '=HYPERLINK("http://x")'], 'lembretes' => 0, 'lembrete_em' => null] + $inscricao;
$csvPortal = mcp_secretaria_csv([$linhaPortal, ['preferencia' => null, 'nome' => 'João', 'telefone' => '', 'status' => 'pendente', 'metodo' => 'pix', 'pago_em' => null] + $linhaPortal,
    ['preferencia' => null, 'nome' => 'Rita'] + $semTurma]);
$linhasPortal = array_map(static fn(string $l): array => str_getcsv($l, ';', '"', ''), explode("\n", trim(substr($csvPortal, 3))));
verificar('secretaria: planilha com BOM e 12 colunas', [str_starts_with($csvPortal, "\xEF\xBB\xBF"), count($linhasPortal), array_unique(array_map('count', $linhasPortal))], [true, 4, [12]]);
verificar('secretaria: planilha da inscrição paga', $linhasPortal[1],
    ['17/09/2026 21:01', 'Paga', 'Maria da Silva', 'maria@exemplo.org', '(21) 99999-8888', 'Curso X', 'R$ 103,95', 'Cartão', 'Matriculado · turma de 21/10/2026', 'Seg à noite; Sáb de manhã', 'Já na próxima turma', "'=HYPERLINK(\"http://x\")"]);
verificar('secretaria: planilha de pendente e de quem não respondeu', [$linhasPortal[2][1], $linhasPortal[2][7], $linhasPortal[2][8], $linhasPortal[2][9], $linhasPortal[3][8], $linhasPortal[3][9]],
    ['Aguardando pagamento', 'PIX', '—', '', 'Sem turma aberta', 'não respondeu']);
verificar('secretaria: planilha sem CPF', [str_contains($csvPortal, '52998224725'), str_contains($csvPortal, '529.982.247-25'), str_contains($csvPortal, 'CPF')], [false, false, false]);

// Leitura pelo painel da escola (api/escola-horarios.php): chave, formato e nada de CPF.
$chaveEscola = str_repeat('k', 40);
verificar('escola-horários: chave certa', mcp_horarios_escola_autorizado("Bearer $chaveEscola"), true);
verificar('escola-horários: chaves recusadas', [
    mcp_horarios_escola_autorizado(''), mcp_horarios_escola_autorizado($chaveEscola), mcp_horarios_escola_autorizado('Bearer ' . str_repeat('k', 39)),
    mcp_horarios_escola_autorizado("Basic $chaveEscola"), mcp_horarios_escola_autorizado("Bearer $chaveEscola extra"),
], [false, false, false, false, false]);
verificar('escola-horários: data ISO', [mcp_horarios_iso('2026-09-29 15:30:00'), mcp_horarios_iso(null), mcp_horarios_iso('29/09/2026')], ['2026-09-29T15:30:00Z', null, null]);
// Mesmo formato de mcp_horarios_listar: p.* (inscricao_id, sem id) com os campos da inscrição.
$respostaEscola = mcp_horarios_linha(['inscricao_id' => 1, 'horarios' => 'seg-noite,sab-manha', 'inicio' => 'proxima', 'turma_serve' => 'nao', 'observacao' => '',
    'vezes' => 2, 'atualizado_em' => '2026-09-29 15:30:00'] + array_intersect_key($inscricao, array_flip(['curso_slug', 'nome', 'email', 'telefone', 'curso_nome', 'escola_acesso', 'pago_em'])));
$paraEscola = mcp_horarios_para_escola([$respostaEscola], [['nome' => 'Rita', 'id' => 7, 'telefone' => '(21) 98888-7777'] + $semTurma], '2026-09-29 16:00:00');
verificar('escola-horários: resposta', $paraEscola['respostas'][0], [
    'inscricao_id' => 1, 'curso_slug' => $primeiro, 'curso_id' => mcp_curso($primeiro)['uuid'], 'curso_nome' => 'Curso X',
    'nome' => 'Maria da Silva', 'email' => 'maria@exemplo.org', 'telefone' => '21999998888', 'pago_em' => '2026-09-18T00:01:00Z',
    'matricula_id' => 'm-1', 'turma_inicio' => '2026-10-21', 'horarios' => ['seg-noite', 'sab-manha'], 'inicio' => 'proxima',
    'turma_serve' => 'nao', 'observacao' => null, 'vezes' => 2, 'respondido_em' => '2026-09-29T15:30:00Z',
]);
verificar('escola-horários: quem falta, sem turma', [$paraEscola['sem_resposta'][0]['nome'], $paraEscola['sem_resposta'][0]['telefone'],
    $paraEscola['sem_resposta'][0]['turma_inicio'], $paraEscola['sem_resposta'][0]['matricula_id'], array_key_exists('horarios', $paraEscola['sem_resposta'][0])],
    ['Rita', '21988887777', null, null, false]);
verificar('escola-horários: cabeçalho e rótulos', [$paraEscola['ok'], $paraEscola['gerado_em'], array_keys($paraEscola['rotulos']), $paraEscola['rotulos']['periodos']['noite']],
    [true, '2026-09-29T16:00:00Z', ['dias', 'dias_curtos', 'periodos', 'horas', 'inicio', 'turma'], 'Noite']);
$jsonEscola = json_encode($paraEscola);
verificar('escola-horários: nunca CPF, hash, IP ou token', [str_contains($jsonEscola, '52998224725'), str_contains($jsonEscola, 'h4sh'),
    str_contains($jsonEscola, '10.0.0.1'), str_contains($jsonEscola, $token), str_contains($jsonEscola, str_repeat('ab', 32))], [false, false, false, false, false]);
// Ponto da sede (lib/ponto.php, lib/presenca.php e lib/declaracao.php): horário da aula, janela da presença,
// localização, horas, código de verificação, textos dos documentos, e-mail e PDF.
verificar('ponto: horário da aula em vários formatos (Brasília → UTC)', array_map(static fn(string $h): array => array_intersect_key(mcp_presenca_horario($h, '2026-10-22'),
    ['inicio' => 1, 'fim' => 1, 'texto' => 1]), ['18:00 - 22:00', '9h às 12h', '18h30-22h', 'das 13h às 17h30', 'Manhã', '22:00 - 18:00']), [
    ['inicio' => '2026-10-22 21:00:00', 'fim' => '2026-10-23 01:00:00', 'texto' => 'das 18:00 às 22:00'],
    ['inicio' => '2026-10-22 12:00:00', 'fim' => '2026-10-22 15:00:00', 'texto' => 'das 09:00 às 12:00'],
    ['inicio' => '2026-10-22 21:30:00', 'fim' => '2026-10-23 01:00:00', 'texto' => 'das 18:30 às 22:00'],
    ['inicio' => '2026-10-22 16:00:00', 'fim' => '2026-10-22 20:30:00', 'texto' => 'das 13:00 às 17:30'],
    ['inicio' => null, 'fim' => '2026-10-23 02:59:59', 'texto' => 'Manhã'],
    ['inicio' => null, 'fim' => '2026-10-23 02:59:59', 'texto' => '22:00 - 18:00'],
]);
$aulaNoite = ['horario' => '18:00 - 22:00', 'data' => '2026-10-22'];
$inicioAula = (int) strtotime('2026-10-22 21:00:00 UTC');
verificar('ponto: janela da presença (3 h antes do início até o fim)', [
    mcp_presenca_janela($aulaNoite, $inicioAula - 181 * 60),
    mcp_presenca_janela($aulaNoite, $inicioAula - 180 * 60)['pode'],
    mcp_presenca_janela($aulaNoite, $inicioAula + 4 * 3600)['pode'],
    mcp_presenca_janela($aulaNoite, $inicioAula + 4 * 3600 + 1),
    mcp_presenca_janela(['horario' => 'a combinar', 'data' => '2026-10-22'], $inicioAula + 5 * 3600)['pode'],
], [
    ['pode' => false, 'motivo' => 'Sua aula começa às 18:00. Registre a chegada quando vier para a aula.'], true, true,
    ['pode' => false, 'motivo' => 'Esta aula terminou às 22:00. Se você veio, fale com a secretaria.'], true,
]);
verificar('ponto: horário local de Brasília em UTC e datas inválidas', [mcp_ponto_local_para_utc('2026-10-22T18:30'), mcp_ponto_local_para_utc('2026-10-22 00:00:00'),
    mcp_ponto_local_para_utc('2026-02-30 10:00'), mcp_ponto_local_para_utc('22/10/2026 10:00'), mcp_ponto_local_para_utc('2026-10-22 25:00')],
    ['2026-10-22 21:30:00', '2026-10-22 03:00:00', null, null, null]);
verificar('ponto: hoje e mês em Brasília (UTC 02:00 ainda é o dia anterior)', [mcp_ponto_hoje((int) strtotime('2026-10-01 02:00:00 UTC')), mcp_ponto_mes_atual((int) strtotime('2026-10-01 02:00:00 UTC')),
    mcp_ponto_mes_dias('2026-12'), mcp_ponto_mes_vizinho('2026-01', -1), mcp_ponto_mes_nome('2026-03'), mcp_ponto_mes_valido('2026-13'), mcp_ponto_mes_valido('2026-09')],
    ['2026-09-30', '2026-09', ['2026-12-01', '2027-01-01'], '2025-12', 'março de 2026', false, true]);
verificar('ponto: horas em texto e por extenso', [mcp_ponto_horas_texto(0), mcp_ponto_horas_texto(65), mcp_ponto_horas_texto(1350), mcp_ponto_horas_extenso(60), mcp_ponto_horas_extenso(61), mcp_ponto_horas_extenso(1350)],
    ['0h00', '1h05', '22h30', '1 hora', '1 hora e 1 minuto', '22 horas e 30 minutos']);
verificar('ponto: distância até a sede', [(int) round(mcp_ponto_distancia(-22.91132, -43.18779, -22.9115, -43.1880)), (int) round(mcp_ponto_distancia(-22.91132, -43.18779, -22.9068, -43.1729)),
    mcp_ponto_distancia_texto(29), mcp_ponto_distancia_texto(1606)], [29, 1606, '29 m', '1,6 km']);
verificar('ponto: localização do celular', [
    mcp_ponto_conferir_localizacao(['lat' => -22.9115, 'lng' => -43.1880, 'precisao' => 25]),
    // 196 m: acima dos 150 m do raio, mas dentro da folga pela imprecisão de 90 m do GPS; com 10 m de imprecisão, não.
    mcp_ponto_conferir_localizacao(['lat' => -22.9124, 'lng' => -43.1893, 'precisao' => 90]),
    mcp_ponto_conferir_localizacao(['lat' => -22.9124, 'lng' => -43.1893, 'precisao' => 10])['ok'],
    mcp_ponto_conferir_localizacao(['lat' => -22.9068, 'lng' => -43.1729, 'precisao' => 10])['erro'],
    mcp_ponto_conferir_localizacao(['lat' => -22.9115, 'lng' => -43.1880, 'precisao' => 1500])['ok'],
    mcp_ponto_conferir_localizacao(['lat' => 'x', 'lng' => -43.1880])['ok'],
    mcp_ponto_conferir_localizacao(null)['ok'],
], [['ok' => true, 'distancia' => 29, 'erro' => null], ['ok' => true, 'distancia' => 196, 'erro' => null], false, 'Você está a 1,6 km da sede. Pelo celular, o registro só vale na sede.', false, false, false]);
verificar('ponto: código de verificação sem 0/O nem 1/I', [strlen(mcp_codigo_gerar()), (bool) preg_match('/^[A-HJ-NP-Z2-9]{8}$/', mcp_codigo_gerar()), mcp_codigo_normalizar('k7qm-4xpa'), mcp_codigo_normalizar(' K7QM 4XPA '),
    mcp_codigo_normalizar('K7QM-4XP0'), mcp_codigo_normalizar(['x']), mcp_codigo_formatado('K7QM4XPA')], [8, true, 'K7QM4XPA', 'K7QM4XPA', '', '', 'K7QM-4XPA']);
verificar('ponto: textos de apoio', [mcp_data_extenso('2026-10-01'), mcp_data_extenso('2026-10-22'), mcp_dia_semana('2026-10-22'), mcp_cpf_mascarado('529.982.247-25'), mcp_email_mascarado('maria@exemplo.org'),
    mcp_nome_arquivo('Comprovante de Comparecimento Punção Venosa 2026-10-22'), mcp_conferir_url('K7QM4XPA'), mcp_conferir_url_curta()],
    ['1º de outubro de 2026', '22 de outubro de 2026', 'quinta-feira', '***.982.247-**', 'm***@exemplo.org', 'Comprovante_de_Comparecimento_Puncao_Venosa_2026-10-22.pdf',
     'https://exemplo.org/conferir/?c=K7QM4XPA', 'exemplo.org/conferir']);
verificar('ponto: endereço da função da escola a partir do da matricula_rapida', [mcp_escola_url_funcao('aulas_do_aluno')], ['https://escola-db.exemplo.org/rest/v1/rpc/aulas_do_aluno']);
$presencaTeste = ['id' => 1, 'token' => str_repeat('b', 40), 'codigo' => 'K7QM4XPA', 'cpf' => '52998224725', 'nome' => 'MARIA DAS GRAÇAS DOS SANTOS', 'email' => 'maria@exemplo.org',
    'curso_nome' => 'Bombeiro Civil', 'aula_data' => '2026-10-22', 'horario' => '18:00 - 22:00', 'inicio' => '2026-10-22 21:00:00', 'fim' => '2026-10-23 01:00:00',
    'chegada' => '2026-10-22 20:52:00', 'origem' => 'celular', 'status' => 'valida', 'email_em' => null];
$conteudo = mcp_presenca_conteudo($presencaTeste, '2026-10-23 01:07:00');
verificar('comprovante de comparecimento: texto declara a aula, o horário e o local', $conteudo['texto'],
    'Declaramos, para os devidos fins, que Maria das Graças dos Santos, CPF 529.982.247-25, compareceu à aula presencial do curso Bombeiro Civil, da Escola de Educação e Saúde da '
    . 'Cruz Vermelha Brasileira Rio de Janeiro, no dia 22 de outubro de 2026 (quinta-feira), das 18:00 às 22:00, na sede da instituição, na Praça da Cruz Vermelha, 10, Centro, Rio de Janeiro/RJ.');
verificar('comprovante de comparecimento: dados, código e rodapé', [$conteudo['linhas'][3], $conteudo['codigo'], $conteudo['local_data'], str_contains($conteudo['rodape'], 'emitido eletronicamente em 22/10/2026 às 22h07'),
    str_contains($conteudo['rodape'], 'exemplo.org/conferir com o código K7QM-4XPA')], [['Chegada registrada', '17:52, pelo celular, na sede'], 'K7QM-4XPA', 'Rio de Janeiro, 22 de outubro de 2026.', true, true]);
verificar('comprovante de comparecimento: público antes e depois do fim da aula', [
    array_intersect_key(mcp_presenca_publico($presencaTeste, (int) strtotime('2026-10-23 00:59:00 UTC')), ['disponivel' => 1, 'pdf' => 1, 'codigo' => 1, 'disponivel_em' => 1, 'email' => 1]),
    mcp_presenca_publico($presencaTeste, (int) strtotime('2026-10-23 01:00:00 UTC'))['codigo'],
    mcp_presenca_publico(['status' => 'cancelada'] + $presencaTeste, (int) strtotime('2026-10-24 00:00:00 UTC'))['disponivel'],
], [['disponivel' => false, 'disponivel_em' => '22/10 às 22:00', 'pdf' => null, 'codigo' => null, 'email' => 'm***@exemplo.org'], 'K7QM-4XPA', false]);
$pdfPresenca = mcp_presenca_pdf($presencaTeste, '2026-10-23 01:07:00');
verificar('comprovante de comparecimento: PDF de uma página', [str_starts_with($pdfPresenca, '%PDF-1.'), substr_count($pdfPresenca, '/Type /Page ') + substr_count($pdfPresenca, '/Type/Page '), mcp_presenca_arquivo($presencaTeste)],
    [true, 1, 'Comprovante_de_Comparecimento_Bombeiro_Civil_2026-10-22.pdf']);
$emailPresenca = mcp_montar_email_comparecimento($presencaTeste);
verificar('comprovante de comparecimento: e-mail com link, código e sem whatsapp', [$emailPresenca['assunto'], str_contains($emailPresenca['html'], 'https://exemplo.org/matricula-cursos-presenciais/comparecimento/?t=' . str_repeat('b', 40)),
    str_contains($emailPresenca['html'], 'Baixar meu comprovante'), str_contains($emailPresenca['texto'], 'K7QM-4XPA'), stripos($emailPresenca['html'] . $emailPresenca['texto'], 'whatsapp'), (bool) preg_match('/<|&[a-z]+;/', $emailPresenca['texto'])],
    ['Seu comprovante de comparecimento: Bombeiro Civil, 22/10/2026', true, true, true, false, false]);
$declaracaoTeste = ['codigo' => 'P3RN8WQZ', 'nome' => 'joão pedro da silva', 'cpf' => '11144477735', 'funcao' => 'Socorrista voluntário', 'de' => '2026-09-01', 'ate' => '2026-09-30',
    'minutos' => 1350, 'dias' => 9, 'emitida_em' => '2026-10-01 13:00:00'];
$conteudoHoras = mcp_ponto_declaracao_conteudo($declaracaoTeste);
verificar('declaração de horas: texto, período e código', [$conteudoHoras['texto'], $conteudoHoras['linhas'][0], $conteudoHoras['linhas'][3], $conteudoHoras['local_data'], $conteudoHoras['codigo']], [
    'Declaramos, para os devidos fins, que João Pedro da Silva, CPF 111.444.777-35, prestou serviço voluntário na Cruz Vermelha Brasileira Rio de Janeiro, na função de Socorrista voluntário, '
    . 'somando 22 horas e 30 minutos de atividades na sede da instituição entre 01/09/2026 e 30/09/2026, conforme os registros de entrada e saída do ponto da sede.',
    ['Período', '01/09/2026 a 30/09/2026'], ['Total de horas', '22h30'], 'Rio de Janeiro, 1º de outubro de 2026.', 'P3RN-8WQZ']);
verificar('declaração de horas: um dia só e sem função', [mcp_ponto_declaracao_conteudo(['de' => '2026-09-15', 'ate' => '2026-09-15', 'funcao' => null] + $declaracaoTeste)['linhas'][0],
    count(mcp_ponto_declaracao_conteudo(['funcao' => ''] + $declaracaoTeste)['linhas']), mcp_ponto_declaracao_arquivo($declaracaoTeste)],
    [['Período', '15/09/2026'], 4, 'Declaracao_de_Horas_Voluntarias_Joao_Pedro_da_Silva_2026-09-01_a_2026-09-30.pdf']);
$pdfHoras = mcp_declaracao_pdf($conteudoHoras);
verificar('declaração de horas: PDF de uma página', [str_starts_with($pdfHoras, '%PDF-1.'), substr_count($pdfHoras, '/Type /Page ') + substr_count($pdfHoras, '/Type/Page ')], [true, 1]);
$nomeLongo = mcp_presenca_pdf(['nome' => str_repeat('Maria Aparecida ', 8) . 'Santos', 'curso_nome' => str_repeat('Curso de nome comprido ', 6)] + $presencaTeste, '2026-10-23 01:07:00');
verificar('comprovante de comparecimento: nome e curso longos cabem numa página', substr_count($nomeLongo, '/Type /Page ') + substr_count($nomeLongo, '/Type/Page '), 1);

// Vínculo e termo de adesão (30/09/2026): quem soma horas, o texto do termo e o PDF de várias páginas.
$formColaborador = ['nome' => 'Maria da Silva', 'cpf' => '529.982.247-25', 'ativo' => 1];
verificar('colaborador: vínculo obrigatório e só da lista', [
    mcp_colaborador_conferir($formColaborador)['campo'] ?? null,
    mcp_colaborador_conferir(['vinculo' => 'chefe'] + $formColaborador)['campo'] ?? null,
    mcp_colaborador_conferir(['vinculo' => 'diretoria'] + $formColaborador)['dados']['vinculo'] ?? null,
], ['vinculo', 'vinculo', 'diretoria']);
verificar('vínculo: diretoria e voluntários somam horas; os outros, só presença', array_map(static fn(string $v): bool => mcp_ponto_voluntario(['vinculo' => $v]), array_keys(MCP_PONTO_VINCULOS)),
    [true, true, false, false, false]);
verificar('vínculo: termo pendente só para voluntário ativo sem termo', [
    mcp_ponto_termo_pendente(['vinculo' => 'voluntario', 'ativo' => 1, 'termo_em' => null]),
    mcp_ponto_termo_pendente(['vinculo' => 'voluntario', 'ativo' => 1, 'termo_em' => '2026-09-01']),
    mcp_ponto_termo_pendente(['vinculo' => 'voluntario', 'ativo' => 0, 'termo_em' => null]),
    mcp_ponto_termo_pendente(['vinculo' => 'empregado', 'ativo' => 1, 'termo_em' => null]),
], [true, false, false, false]);
$voluntarioTeste = ['id' => 7, 'nome' => 'JOÃO PEDRO DA SILVA', 'cpf' => '11144477735', 'email' => 'joao@exemplo.org', 'telefone' => '21988887777',
    'funcao' => 'Socorrista voluntário', 'vinculo' => 'voluntario', 'ativo' => 1, 'termo_em' => null];
$termo = mcp_ponto_termo_conteudo($voluntarioTeste, '2026-09-30 13:00:00');
$textoTermo = implode(' ', array_map(static fn(array $c): string => $c[1], $termo['clausulas']));
verificar('termo: partes com a entidade (CNPJ) e o voluntário (CPF e contatos)', [$termo['partes'][0][1], $termo['partes'][1][1]], [
    'Cruz Vermelha Brasileira — Filial do Estado do Rio de Janeiro, CNPJ 08.560.973/0001-97, com sede na Praça da Cruz Vermelha, 10, Centro, Rio de Janeiro/RJ, CEP 20230-130, representada na forma do seu estatuto.',
    'João Pedro da Silva, CPF 111.444.777-35, e-mail joao@exemplo.org, telefone (21) 98888-7777.']);
verificar('termo: objeto com a função e cláusulas da Lei 9.608/1998', [
    str_contains($termo['clausulas'][0][1], 'na função de Socorrista voluntário,'),
    str_contains($textoTermo, 'não gera vínculo empregatício nem obrigação de natureza trabalhista, previdenciária ou afim, conforme o art. 1º, parágrafo único, da Lei nº 9.608/1998'),
    str_contains($textoTermo, 'conforme o art. 3º da Lei nº 9.608/1998'),
    str_contains($textoTermo, 'Não serve para remuneração, controle de jornada ou punição.'),
    str_contains($textoTermo, 'Lei nº 13.709/2018 (LGPD)'),
    count($termo['clausulas']), $termo['assinaturas'][0][1],
], [true, true, true, true, true, 11, 'João Pedro da Silva · CPF 111.444.777-35']);
verificar('termo: sem função e sem contatos', [mcp_ponto_termo_conteudo(['funcao' => '', 'email' => null, 'telefone' => null] + $voluntarioTeste)['partes'][1][1],
    str_contains(mcp_ponto_termo_conteudo(['funcao' => ''] + $voluntarioTeste)['clausulas'][0][1], 'função')], ['João Pedro da Silva, CPF 111.444.777-35.', false]);
verificar('termo: rodapé com o modelo, a data e o nome; arquivo', [$termo['rodape'], mcp_ponto_termo_arquivo($voluntarioTeste)],
    ['Termo de adesão ao serviço voluntário · modelo ' . MCP_PONTO_TERMO_MODELO . ' · gerado em 30/09/2026 · João Pedro da Silva', 'Termo_de_Adesao_Voluntario_Joao_Pedro_da_Silva.pdf']);
$pdfTermo = mcp_termo_pdf($termo);
verificar('termo: PDF de duas páginas', [str_starts_with($pdfTermo, '%PDF-1.'), substr_count($pdfTermo, '/Type /Page '), str_contains($pdfTermo, '/Count 2 ')], [true, 2, true]);
$termoLongo = mcp_termo_pdf(mcp_ponto_termo_conteudo(['nome' => str_repeat('Maria Aparecida ', 8) . 'Santos', 'funcao' => str_repeat('Função de nome comprido ', 8)] + $voluntarioTeste));
verificar('termo: nome e função longos continuam na página seguinte', in_array(substr_count($termoLongo, '/Type /Page '), [2, 3], true), true);
$declaracaoComTermo = mcp_ponto_declaracao_conteudo(['termo_em' => '2026-08-12'] + $declaracaoTeste);
verificar('declaração: cita a Lei 9.608/1998 e o termo de adesão', [str_ends_with($declaracaoComTermo['texto'],
    'O serviço foi prestado nos termos da Lei nº 9.608/1998 e do termo de adesão assinado em 12/08/2026, sem vínculo empregatício.'), $declaracaoComTermo['linhas'][4]],
    [true, ['Termo de adesão', 'assinado em 12/08/2026']]);

// WhatsApp: número, erros da Evolution e robôs que abrem links sozinhos.
verificar('whatsapp: celular com e sem o nono dígito; fixo e inválido não servem', [mcp_whatsapp_numero('(21) 98765-4321'), mcp_whatsapp_numero('21 8765-4321'),
    mcp_whatsapp_numero('+55 21 98765-4321'), mcp_whatsapp_numero('(21) 3234-5678'), mcp_whatsapp_numero('123')], ['5521987654321', '5521987654321', '5521987654321', null, null]);
verificar('evolution: número sem WhatsApp, mensagem aninhada, texto e nada', [
    mcp_whatsapp_evolution_erro(['status' => 400, 'error' => 'Bad Request', 'response' => ['message' => [['jid' => '5521999990000@s.whatsapp.net', 'exists' => false, 'number' => '5521999990000']]]]),
    mcp_whatsapp_evolution_erro(['response' => ['message' => ['The "x" instance does not exist']]]), mcp_whatsapp_evolution_erro(['error' => true, 'message' => 'Connection Closed']),
    mcp_whatsapp_evolution_erro([])], ['esse número não tem WhatsApp', 'The "x" instance does not exist', 'Connection Closed', null]);
verificar('cliques: prévias de link e programas não contam; navegadores contam', array_map('mcp_avisos_eh_robo', [
    'WhatsApp/2.23.20.0 A', 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', 'TelegramBot (like TwitterBot)', 'Mozilla/5.0 (compatible; Googlebot/2.1)',
    'curl/8.5.0', 'python-requests/2.31', '',
    'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Linux; Android 13; moto g) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36',
]), [true, true, true, true, true, true, true, false, false, false]);

// Calendário dos lembretes: Páscoa, feriados do Rio e a regra da equipe contratada.
verificar('páscoa: anos conhecidos', array_map('mcp_avisos_pascoa', [2019, 2024, 2025, 2026, 2027]), ['2019-04-21', '2024-03-31', '2025-04-20', '2026-04-05', '2027-03-28']);
verificar('feriados: fixos, do estado e da cidade, e os móveis', array_map('mcp_avisos_feriado', ['2026-10-12', '2026-01-20', '2026-04-23', '2026-11-20', '2026-02-16', '2026-02-17', '2026-04-03', '2026-06-04', '2026-10-01', 'x']),
    ['Nossa Senhora Aparecida', 'Dia de São Sebastião', 'Dia de São Jorge', 'Dia Nacional de Zumbi e da Consciência Negra', 'Carnaval', 'Carnaval', 'Sexta-feira Santa', 'Corpus Christi', null, null]);
verificar('feriados: dia fechado sem consultar o portal quando é feriado', mcp_avisos_dia_fechado('2026-12-25'), 'Natal');
$equipe = ['vinculo' => 'empregado', 'aviso_dias_por' => 'a própria pessoa'];
// O caso em que a equipe recebe (dia e véspera úteis, sem dia fechado no portal) consulta o banco: está na integração.
verificar('véspera: voluntário em qualquer dia; equipe nunca no fim de semana nem na segunda, e só com os dias escolhidos por ela', [
    mcp_avisos_recebe_vespera(['vinculo' => 'voluntario', 'aviso_dias_por' => 'secretaria@exemplo.org'], '2026-10-03'),
    mcp_avisos_recebe_vespera($equipe, '2026-10-03'), mcp_avisos_recebe_vespera($equipe, '2026-10-04'), mcp_avisos_recebe_vespera($equipe, '2026-10-05'),
    mcp_avisos_recebe_vespera(['aviso_dias_por' => 'secretaria@exemplo.org'] + $equipe, '2026-10-01'), mcp_avisos_recebe_vespera(['aviso_dias_por' => null] + $equipe, '2026-10-01'),
], [true, false, false, false, false, false]);
$agoraAviso = (int) strtotime('2026-09-30 12:00:00 UTC');
verificar('prazo do aviso: véspera e aula até as 20h da véspera; saída 3 dias; link 1 dia', [
    mcp_aviso_validade(['tipo' => 'vespera', 'referencia' => '2026-10-01'], $agoraAviso), mcp_aviso_validade(['tipo' => 'aula', 'referencia' => '2026-10-02'], $agoraAviso),
    mcp_aviso_validade(['tipo' => 'saida', 'referencia' => '12'], $agoraAviso), mcp_aviso_validade(['tipo' => 'link', 'referencia' => null], $agoraAviso),
], ['2026-09-30 23:00:00', '2026-10-01 23:00:00', '2026-10-03 12:00:00', '2026-10-01 12:00:00']);
verificar('data no modelo do WhatsApp', [mcp_avisos_data_modelo('2026-10-01'), mcp_avisos_data_modelo('2026-10-03')], ['1º/10 (quinta)', '03/10 (sábado)']);
verificar('planilha: dias por extenso, abreviados, ordinais, intervalos e expressões', array_map('mcp_importar_dias', [
    'segunda e quarta', 'seg, qua, sex', '2ª a 6ª', '2 a 6', '2a e 4a feira', 'segunda a sexta', 'segunda até quarta', 'sex a seg', 'segunda e a quarta', 'terça-feira, quinta-feira e sábado',
    'todos os dias', 'dias úteis', 'fim de semana', 'às vezes', '',
]), [['seg', 'qua'], ['seg', 'qua', 'sex'], ['seg', 'ter', 'qua', 'qui', 'sex'], ['seg', 'ter', 'qua', 'qui', 'sex'], ['seg', 'qua'], ['seg', 'ter', 'qua', 'qui', 'sex'], ['seg', 'ter', 'qua'],
    ['dom', 'seg', 'sex', 'sab'], ['seg', 'qua'], ['ter', 'qui', 'sab'], ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sab'], ['seg', 'ter', 'qua', 'qui', 'sex'], ['dom', 'sab'], [], []]);

verificar('planilha: frequência, hora e palavras parecidas com dias não viram dia', array_map('mcp_importar_dias', [
    '3 vezes por semana', '3x por semana', '2 vezes na semana', 'qualquer dia', 'quando puder', 'quinzenal', 'segundo sábado do mês', '2h por dia',
    '2, 4 e 6', '3-5', 'de 2ª a 6ª das 8h às 12h', 'sábados e domingos', 'quartas-feiras', '5', '2as e 4as', '3as e 5as', '2as, 4as e 6as', '2°, 4°',
]), [[], [], [], [], [], [], ['sab'], [], ['seg', 'qua', 'sex'], ['ter', 'qua', 'qui'], ['seg', 'ter', 'qua', 'qui', 'sex'], ['dom', 'sab'], ['qua'], [],
    ['seg', 'qua'], ['ter', 'qui'], ['seg', 'qua', 'sex'], ['seg', 'qua']]);

// ----------------------------------------------------------------------------- API de Conversões da Meta (lib/meta.php)
verificar('meta: configurada pelo config-meta.php, que não muda o resto', [mcp_meta_configurada(), mcp_meta_pixel(), mcp_site_url()], [true, '2224500131617302', 'https://exemplo.org']);
$cookie = static function (?string $valor): void { if ($valor === null) { unset($_COOKIE['cvrj_consentimento']); } else { $_COOKIE['cvrj_consentimento'] = $valor; } };
$escolhas = [];
foreach (['v=1&e=1&m=1&t=1790940000&r=2', 'v=1&e=1&m=0&t=1', 'v=2&e=1&m=1&t=1790940000&r=2', 'v=1&e=1', 'lixo', '', null, str_repeat('m', 300), 'v=1&e=1&m=1&t=1790940000',
    'v=1&e=1&m=1&r=2', 'v=1&e=1&m=1&t=1790940000&r=1', 'v=1&e=1&m=1&t=' . (time() + 3 * 86400) . '&r=2', 'v=1&e=1&m=1&t=1790940000&r=3', 'v=1&e=0&m=0', 'v=1&e=1&m=1&t=1790940000&r=999'] as $valor) {
    $cookie($valor);
    $escolhas[] = mcp_meta_marketing_no_cookie();
}
// O "sim" só vale com a revisão atual do texto (r=2, que só o aviso atual grava) e uma data possível; o "não" vale sempre.
verificar('meta: escolha de marketing no cookie (só v=1 e m=0/1; "sim" sem r=2 ou com data no futuro não vale)', $escolhas,
    [true, false, null, null, null, null, null, null, null, null, null, null, true, false, null]);
$cookie('v=1&e=1&m=1&t=1790940000&r=2');
verificar('meta: data da escolha vem do t do cookie', mcp_meta_escolha_em(mcp_meta_escolha_no_cookie()), '2026-10-02 11:20:00');
verificar('meta: id de evento do navegador (sem quebra de linha no fim)', array_map('mcp_meta_id_valido', ['pv.mgb2k1.a8f3k2l1', 'lead.x', 'tok-' . str_repeat('a', 36) . '-pagamento', '<script>', '', 123, str_repeat('a', 81), "ev.abcdefgh3\n", ['ev.abcdefgh3']]),
    ['pv.mgb2k1.a8f3k2l1', null, 'tok-' . str_repeat('a', 36) . '-pagamento', null, null, null, null, null, null]);
verificar('meta: id da compra é um hash do token, não o token', [mcp_meta_id_da_compra(str_repeat('b', 40)), str_contains(mcp_meta_id_da_compra(str_repeat('b', 40)), str_repeat('b', 8)),
    mcp_meta_id_da_compra(str_repeat('b', 40)) === mcp_meta_id_da_compra(str_repeat('c', 40)), mcp_meta_id_valido(mcp_meta_id_da_compra(str_repeat('b', 40)) . '-pagamento') !== null],
    ['c.' . substr(hash('sha256', 'cvrj-meta-compra|' . str_repeat('b', 40)), 0, 32), false, false, true]);
$_COOKIE['_fbp'] = 'fb.1.1790940000123.1234567890';
$_COOKIE['_fbc'] = 'fb.1.1790940000123.IwAR0abc-_XYZ';
verificar('meta: _fbp e _fbc no formato da Meta', [mcp_meta_cookie_fb('_fbp'), mcp_meta_cookie_fb('_fbc')], ['fb.1.1790940000123.1234567890', 'fb.1.1790940000123.IwAR0abc-_XYZ']);
$_COOKIE['_fbc'] = 'fb.1.123.<script>';
verificar('meta: _fbc fora do formato não vai', mcp_meta_cookie_fb('_fbc'), null);
$_COOKIE['_fbc'] = 'fb.1.1790940000123.IwAR0abc' . "\n";
verificar('meta: _fbc com quebra de linha no fim não vai', mcp_meta_cookie_fb('_fbc'), null);
$_COOKIE['_fbc'] = 'fb.1.1790940000123.' . str_repeat('A', 400);
verificar('meta: _fbc com fbclid longo (anúncios de hoje) vai inteiro', mcp_meta_cookie_fb('_fbc'), 'fb.1.1790940000123.' . str_repeat('A', 400));
$_COOKIE['_fbp'] = 'fb.1.1790940000123.' . str_repeat('9', 300);
verificar('meta: _fbp maior que a coluna (255) não vai', mcp_meta_cookie_fb('_fbp'), null);
$_COOKIE['_fbp'] = 'fb.1.1790940000123.1234567890';
unset($_COOKIE['_fbc']);
verificar('meta: fbc a partir do fbclid (o de 255, que pode ter sido cortado, não vai)', [mcp_meta_fbc_do_fbclid('IwAR0abc', 1790940000123), mcp_meta_fbc_do_fbclid('a b'), mcp_meta_fbc_do_fbclid(null),
    mcp_meta_fbc_do_fbclid("ZZZ\n"), strlen((string) mcp_meta_fbc_do_fbclid(str_repeat('A', 254), 1790940000123)), mcp_meta_fbc_do_fbclid(str_repeat('A', 255))],
    ['fb.1.1790940000123.IwAR0abc', null, null, null, 254 + 19, null]);
verificar('meta: endereço sem o t= e só com parâmetros de campanha', [
    mcp_meta_url_limpa('https://exemplo.org/matricula-cursos-presenciais/parabens/?t=' . str_repeat('a', 40) . '&utm_source=ig&x=1', 'P'),
    mcp_meta_url_limpa('https://www.exemplo.org/a/?fbclid=IwAR&curso=puncao-venosa', 'P'),
    mcp_meta_url_limpa('https://outro.org/?utm_source=x', 'P'),
    mcp_meta_url_limpa('javascript:alert(1)', 'P'),
    mcp_meta_url_limpa('/matricula-cursos-presenciais/?utm_campaign=c&t=segredo', 'P'),
    mcp_meta_url_limpa('//outro.org/', 'P'),
    mcp_meta_url_limpa(null, 'P'),
], ['https://exemplo.org/matricula-cursos-presenciais/parabens/?utm_source=ig', 'https://www.exemplo.org/a/?fbclid=IwAR&curso=puncao-venosa', 'P', 'P',
    'https://exemplo.org/matricula-cursos-presenciais/?utm_campaign=c', 'P', 'P']);
verificar('meta: hash SHA-256 de valor normalizado (minúsculas, sem espaços nas pontas)', [mcp_meta_hash('  Teste@Exemplo.ORG '), mcp_meta_hash(''), mcp_meta_hash('ÁGUA')],
    [hash('sha256', 'teste@exemplo.org'), null, hash('sha256', 'água')]);
verificar('meta: telefone com 55 na frente (DDD 55 do RS não confunde)', array_map('mcp_meta_telefone', ['21999998888', '2133334444', '5521999998888', '55999998888', '+55 (21) 99999-8888', '123']),
    ['5521999998888', '552133334444', '5521999998888', '5555999998888', '5521999998888', '']);
verificar('meta: primeiro nome e o resto', [mcp_meta_nome_e_sobrenome('  Maria  da Silva Souza '), mcp_meta_nome_e_sobrenome('Ana')], [['Maria', 'da Silva Souza'], ['Ana', '']]);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (teste)';
$pessoaTeste = ['nome' => 'Maria da Silva', 'email' => 'Maria@Exemplo.org', 'telefone' => '21999998888', 'cpf' => '52998224725', 'external_id' => str_repeat('c', 40)];
$userData = mcp_meta_user_data($pessoaTeste, mcp_meta_contexto('IwAR0abc'));
verificar('meta: user_data com hash dos dados da pessoa e sinais do navegador sem hash', [
    $userData['em'], $userData['ph'], $userData['fn'], $userData['ln'], $userData['external_id'], $userData['country'],
    $userData['client_ip_address'], $userData['client_user_agent'], $userData['fbp'], str_starts_with($userData['fbc'], 'fb.1.') && str_ends_with($userData['fbc'], '.IwAR0abc'),
], [hash('sha256', 'maria@exemplo.org'), hash('sha256', '5521999998888'), hash('sha256', 'maria'), hash('sha256', 'da silva'), hash('sha256', str_repeat('c', 40)), hash('sha256', 'br'),
    '203.0.113.9', 'Mozilla/5.0 (teste)', 'fb.1.1790940000123.1234567890', true]);
verificar('meta: o CPF nunca vai (nem em hash)', in_array(hash('sha256', '52998224725'), $userData, true), false);
verificar('meta: evento de página é anônimo (sem dado da pessoa nem país)', array_keys(mcp_meta_user_data([], mcp_meta_contexto())), ['client_ip_address', 'client_user_agent', 'fbp']);
$eventoTeste = mcp_meta_evento('ViewContent', 'vc.abc.12345678', ['fbp' => 'x'], mcp_meta_dados_do_curso('puncao-venosa', 'Punção Venosa', 9900), 'https://exemplo.org/', 1790940000);
verificar('meta: evento no formato da API', $eventoTeste, ['event_name' => 'ViewContent', 'event_time' => 1790940000, 'event_id' => 'vc.abc.12345678', 'action_source' => 'website',
    'event_source_url' => 'https://exemplo.org/', 'user_data' => ['fbp' => 'x'],
    'custom_data' => ['content_name' => 'Punção Venosa', 'content_ids' => ['puncao-venosa'], 'content_type' => 'product', 'value' => 99.0, 'currency' => 'BRL']]);

// O que fica na inscrição: só com "sim" os sinais do navegador; com "não" ou sem escolha, nada além da escolha.
$cookie('v=1&e=0&m=1&t=1790940000&r=2');
$colunasSim = mcp_meta_colunas_da_inscricao('IwAR0abc');
$cookie('v=1&e=1&m=0&t=1');
$colunasNao = mcp_meta_colunas_da_inscricao('IwAR0abc');
$cookie(null);
$colunasSem = mcp_meta_colunas_da_inscricao('IwAR0abc');
verificar('meta: colunas da inscrição por escolha', [$colunasSim['meta_marketing'], $colunasSim['meta_marketing_em'], $colunasSim['meta_revisao'], $colunasSim['meta_fbp'], $colunasSim['meta_ua'], $colunasSim['meta_ip'],
    str_ends_with((string) $colunasSim['meta_fbc'], '.IwAR0abc'), $colunasNao, $colunasSem],
    [1, '2026-10-02 11:20:00', 2, 'fb.1.1790940000123.1234567890', 'Mozilla/5.0 (teste)', '203.0.113.9', true, ['meta_marketing' => 0, 'meta_marketing_em' => '1970-01-01 00:00:01'], ['meta_marketing' => null, 'meta_marketing_em' => null]]);

// Fila: os eventos saem só no fim do pedido (depois da resposta); aqui a fila é conferida e esvaziada.
$fila = &mcp_meta_fila();
$inscricaoTeste = ['id' => 7, 'token' => str_repeat('b', 40), 'curso_slug' => 'puncao-venosa', 'curso_nome' => 'Punção Venosa', 'nome' => 'Maria da Silva',
    'email' => 'maria@exemplo.org', 'telefone' => '21999998888', 'cpf' => '52998224725', 'inscricao_centavos' => 9900, 'total_centavos' => 10395,
    'ip' => '198.51.100.4', 'meta_ip' => '198.51.100.77', 'meta_marketing' => '1', 'meta_revisao' => '2', 'meta_ua' => 'UA guardado', 'meta_fbp' => 'fb.1.1790940000123.999', 'meta_fbc' => null,
    'fbclid' => 'IwAR0abc', 'criado_em' => '2026-10-02 12:00:00'];
$idCompraTeste = mcp_meta_id_da_compra(str_repeat('b', 40));
$cookie('v=1&e=1&m=1&t=1790940000&r=2');
mcp_meta_cobranca_criada($inscricaoTeste, ['slug' => 'puncao-venosa', 'fbclid' => null] + $pessoaTeste, 'lead.mgb2k1.a8f3k2l1', true);
mcp_meta_compra($inscricaoTeste);
$compra = $fila[2][0] ?? [];
verificar('meta: cobrança aceita manda Lead e AddPaymentInfo; pago manda Purchase com os sinais guardados (e o id da compra, nunca o token)', [
    array_map(static fn($i) => [$i[0]['event_name'], $i[0]['event_id'], $i[1]], $fila),
    $fila[1][0]['custom_data']['value'] ?? null,
    [$fila[2][0]['user_data']['external_id'] ?? null, isset($fila[2][0]['custom_data']['num_items'])],
    [$compra['user_data']['client_ip_address'] ?? null, $compra['user_data']['client_user_agent'] ?? null, $compra['user_data']['fbp'] ?? null, $compra['user_data']['fbc'] ?? null],
    [$compra['custom_data']['order_id'] ?? null, $compra['custom_data']['value'] ?? null, $compra['event_source_url'] ?? null],
], [
    [['Lead', 'lead.mgb2k1.a8f3k2l1', 7], ['AddPaymentInfo', $idCompraTeste . '-pagamento', 7], ['Purchase', $idCompraTeste, 7]],
    103.95,
    [hash('sha256', str_repeat('b', 40)), false],
    ['198.51.100.77', 'UA guardado', 'fb.1.1790940000123.999', 'fb.1.1790942400000.IwAR0abc'],
    [$idCompraTeste, 103.95, 'https://exemplo.org/matricula-cursos-presenciais/parabens/'],
]);
verificar('meta: o token da inscrição não aparece em nenhum evento', str_contains(json_encode(array_column($fila, 0)), str_repeat('b', 40)), false);
$fila = [];
mcp_meta_compra(['meta_revisao' => null] + $inscricaoTeste);
mcp_meta_compra(['meta_revisao' => '1'] + $inscricaoTeste);
verificar('meta: Purchase só com o "sim" dado no texto atual (meta_revisao)', count($fila), 0);
mcp_meta_compra(['meta_ip' => null] + $inscricaoTeste);
verificar('meta: Purchase sem o IP guardado com o "sim" não usa o IP de segurança da inscrição', [count($fila), $fila[0][0]['user_data']['client_ip_address'] ?? null], [1, null]);
$fila = [];
mcp_meta_compra(['meta_ua' => null] + $inscricaoTeste);
verificar('meta: Purchase sem a identificação do navegador não entra na fila (a Meta recusaria o lote)', count($fila), 0);
mcp_meta_compra(['meta_marketing' => '0'] + $inscricaoTeste);
mcp_meta_compra(['meta_marketing' => null] + $inscricaoTeste);
$cookie('v=1&e=1&m=0&t=1');
mcp_meta_cobranca_criada($inscricaoTeste, ['slug' => 'puncao-venosa', 'fbclid' => null] + $pessoaTeste, 'lead.mgb2k1.a8f3k2l1', true);
mcp_meta_contato(['nome' => 'Ana', 'email' => 'a@exemplo.org', 'assunto' => 'matricula'], 'ct.mgb2k1.a8f3k2l1');
verificar('meta: sem "sim" para marketing (na inscrição ou no cookie), a fila fica vazia', count($fila), 0);
$cookie('v=1&e=1&m=1&t=1790940000');
mcp_meta_contato(['nome' => 'Ana', 'email' => 'a@exemplo.org', 'assunto' => 'matricula'], 'ct.mgb2k1.a8f3k2l1');
verificar('meta: "sim" sem a revisão atual do texto (r) não manda nada', count($fila), 0);
$cookie('v=1&e=1&m=1&t=1790940000&r=2');
mcp_meta_contato(['nome' => 'Ana Lima', 'email' => 'a@exemplo.org', 'telefone' => '', 'assunto' => 'matricula', 'curso_nome' => null, 'pagina' => '/cursos/?t=x&utm_source=ig'], 'ct.mgb2k1.a8f3k2l1');
mcp_meta_contato(['nome' => 'Ana Lima', 'email' => 'a@exemplo.org', 'assunto' => 'matricula'], null);
verificar('meta: Contact só com o id do chat, sem telefone vazio e com a página limpa', [count($fila), $fila[0][0]['event_name'] ?? null, isset($fila[0][0]['user_data']['ph']),
    $fila[0][0]['custom_data'] ?? null, $fila[0][0]['event_source_url'] ?? null],
    [1, 'Contact', false, ['content_category' => 'matricula', 'content_name' => 'matricula'], 'https://exemplo.org/cursos/?utm_source=ig']);
$fila = [];
$envioFechado = mcp_meta_enviar([$eventoTeste]);
verificar('meta: Meta fora do ar vira resultado de falha, sem exceção', [$envioFechado['ok'], $envioFechado['http'], str_starts_with($envioFechado['erro'], 'sem resposta')], [false, 0, true]);
$cookie(null);
unset($_COOKIE['_fbp']);

// Um config-meta.php escrito com erro desliga só a API de Conversões; o checkout segue (processo à parte).
$quebrado = tempnam(sys_get_temp_dir(), 'mcp-meta-quebrado-');
file_put_contents($quebrado, "<?php return ['META_CAPI_TOKEN' => 'x' 'faltou a vírgula'];");
$saida = shell_exec(sprintf('MCP_CONFIG_ARQUIVO=%s MCP_CONFIG_META_ARQUIVO=%s MCP_CATALOGO_ARQUIVO=%s %s -d log_errors=0 -r %s 2>/dev/null',
    escapeshellarg($configTeste), escapeshellarg($quebrado), escapeshellarg($raiz . '/site/matricula-cursos-presenciais/cursos.json'), escapeshellarg(PHP_BINARY),
    escapeshellarg('$_SERVER["REQUEST_METHOD"]="CLI"; require ' . var_export($raiz . '/site/matricula-cursos-presenciais/api/lib.php', true) . '; echo json_encode([mcp_site_url(), mcp_meta_configurada()]);')));
unlink($quebrado);
verificar('meta: config-meta.php com erro de sintaxe é ignorado', $saida, '["https:\/\/exemplo.org",false]');

// A versão do banco vem de uma constante no código (e não do arquivo em disco): mudou o db.php, muda a constante.
$versaoDb = substr(md5((string) preg_replace('/^const MCP_DB_VERSAO = .*\n/m', '', (string) file_get_contents(__DIR__ . '/../site/matricula-cursos-presenciais/api/lib/db.php'))), 0, 16);
verificar("banco: MCP_DB_VERSAO acompanha o db.php (se falhar, troque o valor por '$versaoDb')", MCP_DB_VERSAO, $versaoDb);

unlink($configTeste);
unlink($configEscola);
unlink($configMeta);
printf("%d testes, %d falhas\n", $total, $falhas);
exit($falhas > 0 ? 1 : 0);
