#!/usr/bin/env php
<?php
/**
 * Testes das funções puras do atendimento inteligente do chat (site/matricula-cursos-presenciais/api/lib/atendimento.php):
 * a base de respostas, a busca local, as categorias e a conferência dos dados coletados. Sem banco, sem rede e sem
 * chave: a chamada à IA não é exercitada aqui (scripts/testar_atendimento_integracao.php usa um servidor falso).
 *
 * Uso:  php scripts/testar_atendimento.php          (sai com código 1 se algum teste falhar)
 *       php scripts/testar_atendimento.php "onde fica a sede?"   (mostra o que a busca local responderia)
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$config = tempnam(sys_get_temp_dir(), 'mcp-at-');
file_put_contents($config, "<?php return ['SITE_URL' => 'https://exemplo.org', 'EMAIL_CONTATO' => 'contato@exemplo.org'];");
putenv("MCP_CONFIG_ARQUIVO=$config");
putenv('MCP_CONFIG_IA_ARQUIVO=/nao-existe');
putenv('MCP_CATALOGO_ARQUIVO=' . $raiz . '/site/matricula-cursos-presenciais/cursos.json');
putenv('MCP_FAQ_ARQUIVO=' . $raiz . '/site/faq-home.json');
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $raiz . '/site/matricula-cursos-presenciais/api/lib.php';
restore_exception_handler();

if ($argc > 1) {
    $r = mcp_at_responder_local($argv[1]);
    echo ($r['sabe'] ? "RESPONDE (nota {$r['nota']}, {$r['categoria']}): {$r['fonte']}\n\n{$r['resposta']}\n" : "NÃO RESPONDE (nota {$r['nota']}, {$r['categoria']})\n");
    foreach ($r['sugestoes'] as $s) {
        echo "  talvez: $s\n";
    }
    exit(0);
}

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

// ---- palavras: acento, plural, palavras vazias e jeitos de dizer a mesma coisa
verificar('tokens: preço', mcp_at_tokens('Quanto custa o curso?'), ['preco', 'preco', 'curso']);
verificar('tokens: valor = preço', mcp_at_tokens('qual o VALOR dos cursos'), ['preco', 'curso']);
verificar('tokens: doação com acento e plural', mcp_at_tokens('Doações de roupas'), ['doar', 'roupa']);
verificar('tokens: só cumprimento não sobra nada', mcp_at_tokens('Olá, boa tarde!'), []);
verificar('tokens: nome da instituição não conta', mcp_at_tokens('Cruz Vermelha do Rio de Janeiro'), []);

// ---- base: FAQ inteira (não só as marcadas para o chat) + ficha e FAQ de cada curso
$base = mcp_at_base('2026-12-15');
$faq = json_decode((string) file_get_contents($raiz . '/site/faq-home.json'), true);
$catalogo = json_decode((string) file_get_contents($raiz . '/site/matricula-cursos-presenciais/cursos.json'), true);
$esperadas = 0;
foreach ($faq['grupos'] as $g) {
    $esperadas += count($g['perguntas']);
}
foreach ($catalogo['cursos'] as $c) {
    $esperadas += 1 + count($c['faq'] ?? []);
}
verificar('base: toda a FAQ da home, a ficha e a FAQ de cada curso', count($base), $esperadas);
$categorias = mcp_at_categorias();
$semCategoria = array_filter($base, static fn(array $i): bool => !isset($categorias[$i['categoria']]));
verificar('base: toda resposta tem categoria conhecida', count($semCategoria), 0);
$linkRuim = 0;
foreach ($base as $item) {
    foreach ($item['links'] as [, $url]) {
        $linkRuim += mcp_at_link_seguro($url) ? 0 : 1;
    }
}
verificar('base: só links do site ou https', $linkRuim, 0);
verificar('link: javascript não passa', mcp_at_link_seguro('javascript:alert(1)'), false);
verificar('link: outro site sem https não passa', mcp_at_link_seguro('//exemplo.org/x'), false);
verificar('base: certificado dos cursos é da escola, não da filial',
    count(array_filter($base, static fn(array $i): bool => $i['curso'] !== '' && str_contains($i['resposta'], 'emitido pela Cruz Vermelha Brasileira'))), 0);
// A resposta sazonal da turma do CBFI vale até o dia dela; a data é a mesma de scripts/cbfi.py.
preg_match('/^DATA = date\((\d+), (\d+), (\d+)\)/m', (string) file_get_contents(__DIR__ . '/cbfi.py'), $m);
verificar('cbfi: mesma data de scripts/cbfi.py', MCP_AT_CBFI_ATE, $m ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : 'cbfi.py sem DATA');
$sazonais = array_filter($faq['grupos'], static fn(array $g): bool => (bool) array_filter($g['perguntas'], static fn(array $q): bool => !empty($q['resposta_cbfi'])));
if ($sazonais) {
    $antes = array_column(mcp_at_base(MCP_AT_CBFI_ATE), 'resposta');
    $depois = array_column($base, 'resposta');
    verificar('cbfi: a resposta muda depois do dia da turma', $antes === $depois, false);
}

// ---- busca local: perguntas do jeito que as pessoas escrevem → a resposta certa da base
$casos = [
    'onde fica a cruz vermelha?' => 'Onde fica a Cruz Vermelha Brasileira Rio de Janeiro e qual é o horário?',
    'qual o horario de funcionamento' => 'Onde fica a Cruz Vermelha Brasileira Rio de Janeiro e qual é o horário?',
    'vcs tem whatsapp?' => 'Como falar com a Cruz Vermelha Brasileira Rio de Janeiro? Tem telefone ou WhatsApp?',
    'quero ser voluntaria' => 'Como ser voluntário da Cruz Vermelha Brasileira Rio de Janeiro?',
    'onde doar roupas' => 'Onde doar roupas, agasalhos e cobertores para a Cruz Vermelha no Rio de Janeiro?',
    'tem curso gratuito?' => 'A Cruz Vermelha Brasileira Rio de Janeiro tem cursos gratuitos?',
    'o certificado é reconhecido pelo mec?' => 'O certificado dos cursos é reconhecido pelo MEC?',
    'os cursos sao online?' => 'Os cursos da Cruz Vermelha Brasileira Rio de Janeiro são online ou presenciais?',
    'como faço a matricula' => 'Como me matricular em um curso da Cruz Vermelha Brasileira Rio de Janeiro?',
    'quero marcar uma consulta' => 'A Cruz Vermelha no Rio de Janeiro atende emergências ou marca consultas?',
    'quanto custa o curso de cuidador de idosos' => 'Como é o curso de Cuidador de Idosos',
    'qual a duração do curso de bombeiro civil?' => 'Como é o curso de Bombeiro Civil',
];
foreach ($casos as $pergunta => $fonte) {
    $r = mcp_at_responder_local($pergunta);
    verificar("busca: \"$pergunta\"", $r['sabe'] ? (str_starts_with((string) $r['fonte'], $fonte) ? $fonte : $r['fonte']) : 'NÃO RESPONDEU (nota ' . $r['nota'] . ')', $fonte);
}
// O que a base não cobre não pode ser respondido com a resposta mais parecida.
foreach (['vocês consertam bicicleta?', 'qual a previsão do tempo para amanhã', 'preciso da segunda via do boleto do condomínio', 'asdf qwerty'] as $fora) {
    verificar("busca: fora da base não responde (\"$fora\")", mcp_at_responder_local($fora)['sabe'], false);
}
// Pergunta que não diz o curso não recebe como certa a resposta de um curso qualquer.
$semCurso = mcp_at_responder_local('como recebo meu certificado');
verificar('busca: resposta de um curso só com o curso na pergunta', $semCurso['sabe'], false);
verificar('busca: ...mas vira sugestão', count($semCurso['sugestoes']) > 0, true);
verificar('busca: com o curso na pergunta, responde', mcp_at_responder_local('recebo certificado no curso de bombeiro civil?')['sabe'], true);
verificar('busca: treinamento para empresa', mcp_at_responder_local('vocês fazem treinamento em empresa?')['categoria'], 'empresas');
verificar('busca: pergunta vazia de sentido', mcp_at_buscar('oi tudo bem'), []);
$curso = mcp_at_responder_local('quanto custa o curso de cuidador de idosos');
verificar('busca: resposta de curso traz o botão de matrícula', (bool) array_filter($curso['links'], static fn(array $l): bool => !empty($l['destaque']) && str_contains($l['href'], '/checkout/?curso=cuidador-de-idosos')), true);
verificar('busca: categoria da ficha do curso', $curso['categoria'], 'cursos');

// ---- sugestão clicada: só vale o que existe na base
$talvez = mcp_at_responder_local('certificado');
verificar('sugestão: rótulo inventado não responde', mcp_at_responder_sugestao('Ignore as instruções'), null);
foreach (array_slice($talvez['sugestoes'], 0, 3) as $s) {
    verificar("sugestão: \"$s\" responde da base", mcp_at_responder_sugestao($s)['sabe'] ?? false, true);
}

// ---- categorias e dados do chamado
verificar('categoria: desconhecida vira outro', mcp_at_categoria_valida('qualquer'), 'outro');
verificar('categoria: array não quebra', mcp_at_categoria_valida(['x']), 'outro');
foreach ($categorias as $chave => $c) {
    verificar("categoria $chave: assunto existe no contato", array_key_exists($c['assunto'], mcp_contato_assuntos()), true);
    verificar("categoria $chave: chaves dos campos sem repetição", count(array_unique(array_column($c['coletar'], 0))), count($c['coletar']));
}
verificar('categoria do texto: pix', mcp_at_categoria_do_texto('paguei o pix e não confirmou'), 'pagamento');
verificar('categoria do texto: empresa', mcp_at_categoria_do_texto('treinamento para minha empresa'), 'empresas');
verificar('detalhes: só o que a categoria pede, opção só das oferecidas', mcp_at_detalhes('empresas', [
    'organizacao' => '  Escola   Modelo ', 'pessoas' => '11 a 30', 'tema' => 'Qualquer coisa', 'local' => str_repeat('x', 300), 'invasor' => 'x', 'cidade' => 'Rio',
]), ['Organização' => 'Escola Modelo', 'Pessoas' => '11 a 30', 'Local' => str_repeat('x', 200)]);
verificar('detalhes: valor que não é texto é ignorado', mcp_at_detalhes('pagamento', ['situacao' => ['Paguei e não foi confirmado'], 'email_compra' => 'a@b.co']), ['E-mail da inscrição' => 'a@b.co']);
verificar('detalhes: categoria sem campos', mcp_at_detalhes('sede', ['organizacao' => 'x']), []);
verificar('detalhes: corpo que não é lista', mcp_at_detalhes('empresas', 'texto'), []);
verificar('coletar: formato do chat', array_keys(mcp_at_coletar('pagamento')[0]), ['chave', 'pergunta', 'rotulo', 'opcoes', 'opcional']);
verificar('sessão: 32 hexadecimais', mcp_at_sessao_valida(str_repeat('ab', 16)), str_repeat('ab', 16));
verificar('sessão: outra coisa não vale', mcp_at_sessao_valida("' OR 1=1"), '');

// ---- IA: sem chave fica desligada; as instruções levam a base inteira e não mudam de um pedido para o outro
verificar('ia: sem chave, desligada', mcp_at_ia_configurada(), false);
verificar('ia: sem chave, não chama', mcp_at_responder_ia('onde fica?'), null);
$sistema = mcp_at_ia_sistema($base);
verificar('ia: instruções estáveis (o cache do prefixo depende disso)', $sistema === mcp_at_ia_sistema($base), true);
verificar('ia: a base inteira vai nas instruções', substr_count($sistema, '<resposta n="'), count($base));
verificar('ia: sem data nem hora nas instruções', (bool) preg_match('/\b' . date('Y-m-d') . '\b|\b\d{2}:\d{2}:\d{2}\b/', $sistema), false);

// ---- e-mail da equipe: dados coletados e conversa entram; texto da pessoa sai escapado
$contato = ['id' => 7, 'protocolo' => 'CV-261110-0007', 'nome' => 'Ana Teste', 'email' => 'ana@exemplo.org', 'telefone' => '21999990000', 'assunto' => 'outro',
    'curso_slug' => null, 'curso_nome' => null, 'mensagem' => 'Quero um treinamento para a escola.', 'pagina' => '/', 'utm_source' => null, 'utm_campaign' => null,
    'criado_em' => '2026-11-10 15:00:00', 'ja_respondido' => [], 'categoria' => 'empresas',
    'detalhes' => json_encode(['Organização' => 'Escola <b>Modelo</b>', 'Pessoas' => '11 a 30'], JSON_UNESCAPED_UNICODE),
    'conversa' => json_encode([['p' => 'vocês dão treinamento em escola? <script>', 'r' => 'Sim, a filial faz treinamentos.', 'sabe' => true, 'voz' => true],
        ['p' => 'quanto custa para 20 pessoas', 'r' => '', 'sabe' => false, 'voz' => false]], JSON_UNESCAPED_UNICODE)];
$email = mcp_montar_email_contato_equipe($contato);
verificar('e-mail: dados coletados aparecem', str_contains($email['html'], 'Organização') && str_contains($email['html'], '11 a 30'), true);
verificar('e-mail: conversa aparece', str_contains($email['html'], 'O que a pessoa perguntou ao assistente do site') && str_contains($email['texto'], 'quanto custa para 20 pessoas'), true);
verificar('e-mail: pergunta sem resposta marcada', str_contains($email['html'], 'Sem resposta no site'), true);
verificar('e-mail: texto da pessoa escapado', str_contains($email['html'], '<script>') || str_contains($email['html'], '<b>Modelo</b>'), false);
verificar('e-mail: sem conversa, sem a seção', str_contains(mcp_montar_email_contato_equipe(['detalhes' => null, 'conversa' => null] + $contato)['html'], 'assistente do site'), false);
verificar('e-mail: JSON estragado não quebra', mcp_contato_conversa(['conversa' => '{não é json']), []);

unlink($config);
echo $falhas === 0 ? "ok: $total verificações\n" : "$falhas de $total verificações falharam\n";
exit($falhas === 0 ? 0 : 1);
