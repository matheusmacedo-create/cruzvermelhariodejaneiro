#!/usr/bin/env php
<?php
/**
 * Testes das funções puras do cadastro de voluntários (lib/voluntarios.php): sem banco, sem rede, sem segredos.
 * Validação campo a campo, protocolo, idade, rótulos, CSV sem CPF, PDF e os dois e-mails (montagem, sem envio).
 *
 * Uso:  php scripts/testar_voluntarios.php
 * Sai com código 1 se algum teste falhar. CPFs sintéticos, gerados pelo algoritmo do dígito verificador.
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$configTeste = tempnam(sys_get_temp_dir(), 'mcp-vol-config-');
file_put_contents($configTeste, "<?php return ['SITE_URL' => 'https://exemplo.org', 'EMAIL_CONTATO' => 'contato@exemplo.org',
    'EMAIL_VOLUNTARIADO' => 'voluntariado@exemplo.org', 'ESCOLA_API_URL' => '', 'RESEND_API_KEY' => ''];");
putenv("MCP_CONFIG_ARQUIVO=$configTeste");
$semExtra = tempnam(sys_get_temp_dir(), 'mcp-vol-extra-');
file_put_contents($semExtra, '<?php return [];');
foreach (['MCP_CONFIG_ESCOLA_ARQUIVO', 'MCP_CONFIG_WHATSAPP_ARQUIVO', 'MCP_CONFIG_META_ARQUIVO', 'MCP_CONFIG_PAGAR_TUDO_ARQUIVO'] as $variavel) {
    putenv("$variavel=$semExtra");
}
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

/** CPF sintético com os dígitos verificadores certos (nunca um CPF real). */
function cpf_sintetico(int $semente): string
{
    mt_srand($semente);
    $d = array_map(static fn(): int => mt_rand(0, 9), range(1, 9));
    for ($n = 9; $n < 11; $n++) {
        $s = 0;
        foreach ($d as $i => $v) {
            $s += $v * ($n + 1 - $i);
        }
        $d[] = ($s * 10) % 11 % 10;
    }
    return implode('', $d);
}

/** Campo do erro de uma validação que falhou ('ok' quando passou). */
function campo_erro(array $resultado): string
{
    return $resultado['ok'] ? 'ok' : (string) $resultado['campo'];
}

$hoje = '2026-10-16';
$cpf = cpf_sintetico(7);
verificar('cpf sintético é válido', mcp_cpf_valido($cpf), true);
$cpfInvalido = substr($cpf, 0, 10) . (((int) $cpf[10] + 1) % 10);
verificar('cpf sintético com o último dígito trocado é inválido', mcp_cpf_valido($cpfInvalido), false);

$base = [
    'nome' => '  maria  da Silva ', 'data_nascimento' => '1990-05-20', 'email' => 'Maria@Exemplo.org', 'telefone' => '(21) 99999-8888',
    'cpf' => '', 'cidade' => 'Rio de Janeiro', 'bairro' => 'Centro', 'estado' => '', 'escolaridade' => 'superior', 'profissao' => 'Enfermeira',
    'saude' => 'nao', 'saude_qual' => '', 'experiencia' => '', 'habilidades' => "Primeiros socorros\n\n\n\nInglês",
    'frentes' => ['juventude', 'formacao_primeiros_socorros'], 'dias' => ['sab', 'seg'], 'periodos' => ['noite', 'manha'],
    'motivacao' => 'Quero ajudar as pessoas da minha cidade em emergências.', 'como_conheceu' => 'instagram',
    'aceita_contato' => true, 'consentimento' => true, 'veracidade' => true, 'site' => '',
    'pagina' => '/voluntariado/cadastro/?utm_source=ig', 'origem' => ['utm_source' => 'instagram', 'utm_campaign' => 'voluntariado-2026', 'fbclid' => 'abc'],
];

// ----------------------------------------------------------------------------- validação: ficha boa
$r = mcp_voluntario_validar($base, $hoje);
verificar('ficha boa passa', $r['ok'], true);
$d = $r['dados'];
verificar('nome normalizado', $d['nome'], 'maria da Silva');
verificar('e-mail em minúsculas', $d['email'], 'maria@exemplo.org');
verificar('telefone só dígitos', $d['telefone'], '21999998888');
verificar('cpf vazio vira null', $d['cpf'], null);
verificar('estado vazio vira RJ', $d['estado'], 'RJ');
verificar('frentes em CSV na ordem da tabela', $d['frentes'], 'formacao_primeiros_socorros,juventude');
verificar('dias em CSV na ordem da semana', $d['dias'], 'seg,sab');
verificar('períodos em CSV na ordem do dia', $d['periodos'], 'manha,noite');
verificar('saúde não', [$d['saude'], $d['saude_qual']], [0, null]);
verificar('idade e maior de idade', [$d['idade'], $d['menor']], [36, false]);
verificar('aceita contato', $d['aceita_contato'], 1);
verificar('experiência vazia vira null; habilidades sem excesso de linhas em branco', [$d['experiencia'], $d['habilidades']], [null, "Primeiros socorros\n\nInglês"]);
verificar('como conheceu', $d['como_conheceu'], 'instagram');
verificar('página do site e origem', [$d['pagina'], $d['utm_source'], $d['utm_campaign'], $d['fbclid'], $d['utm_medium']], ['/voluntariado/cadastro/?utm_source=ig', 'instagram', 'voluntariado-2026', 'abc', null]);
verificar('profissão e bairro', [$d['profissao'], $d['bairro']], ['Enfermeira', 'Centro']);

// ----------------------------------------------------------------------------- validação: campo a campo
$com = static fn(array $muda): array => mcp_voluntario_validar($muda + $base, $hoje);
verificar('nome vazio', campo_erro($com(['nome' => ''])), 'nome');
verificar('nome sem sobrenome', campo_erro($com(['nome' => 'Maria'])), 'nome');
verificar('data vazia', campo_erro($com(['data_nascimento' => ''])), 'data_nascimento');
verificar('data inválida (mês 13)', campo_erro($com(['data_nascimento' => '2000-13-01'])), 'data_nascimento');
verificar('data no futuro', campo_erro($com(['data_nascimento' => '2027-01-01'])), 'data_nascimento');
verificar('data em dd/mm/aaaa (sem JavaScript) é aceita', $com(['data_nascimento' => '20/05/1990'])['dados']['data_nascimento'] ?? null, '1990-05-20');
// Idade mínima de 18 anos desde 25/10/2026 (antes, 16). A mensagem não manda escrever para o contato.
$com16 = $com(['data_nascimento' => '2010-10-16']);
verificar('16 anos: recusa no campo data_nascimento, sem o e-mail de contato', [campo_erro($com16), str_contains($com16['erro'], '18 anos'), str_contains($com16['erro'], '@')], ['data_nascimento', true, false]);
$quase18 = $com(['data_nascimento' => '2008-10-17']);
verificar('faz 18 amanhã (17 anos): recusa', campo_erro($quase18), 'data_nascimento');
$faz18 = $com(['data_nascimento' => '2008-10-16']);
verificar('faz 18 hoje: passa e não é menor', [$faz18['ok'], $faz18['dados']['idade'], $faz18['dados']['menor']], [true, 18, false]);
verificar('e-mail inválido', campo_erro($com(['email' => 'maria@'])), 'email');
verificar('telefone vazio', campo_erro($com(['telefone' => ''])), 'telefone');
verificar('telefone sem DDD', campo_erro($com(['telefone' => '9999-8888'])), 'telefone');
verificar('cpf inválido', campo_erro($com(['cpf' => $cpfInvalido])), 'cpf');
verificar('cpf válido formatado vira só dígitos', $com(['cpf' => mcp_cpf_formatado($cpf)])['dados']['cpf'] ?? null, $cpf);
verificar('cidade vazia', campo_erro($com(['cidade' => ''])), 'cidade');
verificar('estado desconhecido', campo_erro($com(['estado' => 'XX'])), 'estado');
verificar('estado em minúsculas vira UF', $com(['estado' => 'sp'])['dados']['estado'] ?? null, 'SP');
verificar('escolaridade fora da lista', campo_erro($com(['escolaridade' => 'doutorado'])), 'escolaridade');
verificar('frentes vazias', campo_erro($com(['frentes' => []])), 'frentes');
verificar('frente desconhecida', campo_erro($com(['frentes' => ['juventude', 'cozinha']])), 'frentes');
verificar('frentes em texto separado por vírgula', $com(['frentes' => 'esportes,juventude'])['dados']['frentes'] ?? null, 'juventude,esportes');
verificar('frentes repetidas contam uma vez', $com(['frentes' => ['outra', 'outra']])['dados']['frentes'] ?? null, 'outra');
verificar('todas as 9 frentes cabem na coluna', strlen($com(['frentes' => array_keys(mcp_voluntarios_frentes())])['dados']['frentes']) <= 255, true);
verificar('dias vazios', campo_erro($com(['dias' => []])), 'dias');
verificar('dia desconhecido', campo_erro($com(['dias' => ['segunda']])), 'dias');
verificar('períodos vazios', campo_erro($com(['periodos' => []])), 'periodos');
verificar('período desconhecido', campo_erro($com(['periodos' => ['madrugada']])), 'periodos');
verificar('motivação curta (19)', campo_erro($com(['motivacao' => str_repeat('a', 19)])), 'motivacao');
verificar('motivação com 20 passa', campo_erro($com(['motivacao' => str_repeat('a', 20)])), 'ok');
verificar('motivação longa (1501)', campo_erro($com(['motivacao' => str_repeat('a', 1501)])), 'motivacao');
verificar('motivação com 1500 passa', campo_erro($com(['motivacao' => str_repeat('a', 1500)])), 'ok');
verificar('consentimento falso', campo_erro($com(['consentimento' => false])), 'consentimento');
verificar('consentimento ausente', campo_erro(mcp_voluntario_validar(array_diff_key($base, ['consentimento' => 1]), $hoje)), 'consentimento');
verificar('consentimento "on" (formulário) vale', campo_erro($com(['consentimento' => 'on'])), 'ok');
verificar('veracidade falsa', campo_erro($com(['veracidade' => false])), 'veracidade');
verificar('veracidade "0" não vale', campo_erro($com(['veracidade' => '0'])), 'veracidade');
$saude = $com(['saude' => 'sim', 'saude_qual' => ' Técnico de enfermagem ']);
verificar('saúde sim com qual', [$saude['dados']['saude'], $saude['dados']['saude_qual']], [1, 'Técnico de enfermagem']);
$saudeNao = $com(['saude' => 'nao', 'saude_qual' => 'texto perdido']);
verificar('saúde não descarta o "qual"', [$saudeNao['dados']['saude'], $saudeNao['dados']['saude_qual']], [0, null]);
verificar('como conheceu desconhecido vira outro', $com(['como_conheceu' => 'tiktok'])['dados']['como_conheceu'] ?? null, 'outro');
verificar('como conheceu vazio vira null', $com(['como_conheceu' => ''])['dados']['como_conheceu'], null);
verificar('página de outro site é descartada', [$com(['pagina' => 'https://outro.site/'])['dados']['pagina'], $com(['pagina' => '//outro.site/'])['dados']['pagina']], [null, null]);
verificar('aceita contato ausente é 0', mcp_voluntario_validar(array_diff_key($base, ['aceita_contato' => 1]), $hoje)['dados']['aceita_contato'], 0);
verificar('booleanos do formulário', array_map('mcp_voluntario_bool', [true, '1', 'on', 'sim', 'true', 1, false, '0', '', 'nao', null, 0]), [true, true, true, true, true, true, false, false, false, false, false, false]);

// ----------------------------------------------------------------------------- protocolo, idade, política
verificar('protocolo com a data de Brasília (02:30 UTC ainda é o dia anterior)', mcp_voluntario_protocolo(7, '2026-10-16 02:30:00'), 'VOL-261015-0007');
verificar('protocolo ao meio-dia', mcp_voluntario_protocolo(12, '2026-10-16 12:00:00'), 'VOL-261016-0012');
verificar('protocolo com id grande', mcp_voluntario_protocolo(12345, '2026-10-16 12:00:00'), 'VOL-261016-12345');
verificar('idade', [mcp_voluntario_idade('2010-10-16', '2026-10-16'), mcp_voluntario_idade('2010-10-17', '2026-10-16'), mcp_voluntario_idade('x', '2026-10-16')], [16, 15, null]);
verificar('hoje em Brasília', mcp_voluntario_hoje((int) strtotime('2026-10-16 01:20:00 UTC')), '2026-10-15'); // 01:20 UTC = 22:20 do dia anterior em Brasília
$politicas = json_decode((string) file_get_contents($raiz . '/site/politicas.json'), true);
verificar('versão da política vem de site/politicas.json', mcp_voluntario_politica_versao(), (string) $politicas['revisao']);
verificar('versão da política cabe em VARCHAR(10)', strlen(mcp_voluntario_politica_versao()) <= 10, true);
verificar('cpf parcial', [mcp_voluntario_cpf_parcial($cpf), mcp_voluntario_cpf_parcial(''), mcp_voluntario_cpf_parcial(null)], [substr($cpf, 0, 3) . '.***.***-**', '', '']);
verificar('e-mail de destino: EMAIL_VOLUNTARIADO', mcp_voluntarios_email_destino(), 'voluntariado@exemplo.org');
verificar('sem EMAIL_VOLUNTARIADO, a coordenação (não o contato)', MCP_EMAIL_VOLUNTARIADO_PADRAO, 'voluntariado@cruzvermelhariodejaneiro.org');
verificar('chat sobre voluntariado vai só à coordenação', mcp_email_contato_destino('voluntariado'), 'voluntariado@exemplo.org');
verificar('chat sobre outros assuntos continua no contato', [mcp_email_contato_destino('matricula'), mcp_email_contato_destino('doacoes'), mcp_email_contato_destino('outro')], ['contato@exemplo.org', 'contato@exemplo.org', 'contato@exemplo.org']);
verificar('link do portal', mcp_voluntario_link_portal(42), 'https://exemplo.org/matricula-cursos-presenciais/api/painel.php?v=voluntario&id=42');

// ----------------------------------------------------------------------------- rótulos completos
$esperados = [
    'frentes' => ['formacao_primeiros_socorros', 'campanhas_acoes', 'juventude', 'comunicacao', 'gestao_riscos', 'psicologia_servico_social', 'esportes', 'administrativo', 'outra'],
    'escolaridades' => ['fundamental', 'medio', 'tecnico', 'superior_incompleto', 'superior', 'pos'],
    'como_conheceu' => ['instagram', 'site', 'amigos', 'escola', 'campanha', 'imprensa', 'outro'],
    'status' => ['nova', 'em_analise', 'entrevista', 'aceita', 'recusada', 'arquivada'],
    'dias' => ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'],
    'periodos' => ['manha', 'tarde', 'noite'],
];
foreach ($esperados as $lista => $chaves) {
    $tabela = ('mcp_voluntarios_' . $lista)();
    verificar("rótulos de $lista: todas as chaves da especificação", array_keys($tabela), $chaves);
    $vazios = array_filter($tabela, static fn(string $rotulo, string $chave): bool => trim($rotulo) === '' || $rotulo === $chave || !preg_match('/^\p{Lu}/u', $rotulo), ARRAY_FILTER_USE_BOTH);
    verificar("rótulos de $lista: em português, com maiúscula, nenhum igual à chave", $vazios, []);
}
verificar('rótulos a partir do CSV', mcp_voluntario_rotulos('juventude,esportes,x', mcp_voluntarios_frentes()), 'Juventude, Esportes, x');
verificar('rótulo da situação', [mcp_voluntario_status_rotulo('em_analise'), mcp_voluntario_status_rotulo('zzz')], ['Em análise', 'zzz']);

// ----------------------------------------------------------------------------- ficha gravada (sintética) para CSV, PDF e e-mails
$ficha = [
    'id' => 42, 'protocolo' => 'VOL-261016-0042', 'cpf' => $cpf, 'status' => 'nova', 'status_em' => null, 'status_por' => null,
    'consentimento_em' => '2026-10-16 14:03:00', 'politica_versao' => '2026-10-02', 'veracidade_em' => '2026-10-16 14:03:00',
    'ip' => '10.0.0.1', 'email_equipe' => null, 'email_confirmacao' => null, 'colaborador_id' => null, 'drive_pdf_id' => null, 'drive_planilha_em' => null,
    'criado_em' => '2026-10-16 14:03:00', 'atualizado_em' => '2026-10-16 14:03:00',
] + $d;
$ficha['motivacao'] = "=SOMA(1;2) " . $ficha['motivacao']; // célula que começa com = não pode virar fórmula na planilha

$csv = mcp_voluntarios_csv_linhas([$ficha]);
verificar('csv: BOM e cabeçalho sem CPF', [str_starts_with($csv, "\xEF\xBB\xBF"), str_contains(explode("\n", $csv)[0], 'CPF')], [true, false]);
verificar('csv: sem o CPF (dígitos ou formatado) nem o IP', [str_contains($csv, $cpf), str_contains($csv, mcp_cpf_formatado($cpf)), str_contains($csv, '10.0.0.1')], [false, false, false]);
verificar('csv: nome, protocolo, rótulos e idade', [str_contains($csv, 'maria da Silva'), str_contains($csv, 'VOL-261016-0042'), str_contains($csv, 'Formação e primeiros socorros, Juventude'),
    str_contains($csv, 'Segunda, Sábado'), str_contains($csv, 'Superior completo'), str_contains($csv, ';"36 anos";'), str_contains($csv, '20/05/1990')], [true, true, true, true, true, true, true]);
verificar('csv: célula que começa com = vira texto', str_contains($csv, "\"'=SOMA(1;2)"), true);
verificar('csv: uma linha por ficha (a quebra de linha das habilidades fica dentro das aspas)', preg_match_all('/^"\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}";/m', $csv), 1);

/** Texto dos fluxos de conteúdo do PDF (descomprimidos), para procurar o que foi desenhado. */
function pdf_texto(string $pdf): string
{
    $texto = '';
    if (preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $m)) {
        foreach ($m[1] as $fluxo) {
            $aberto = @gzuncompress($fluxo);
            $texto .= $aberto !== false ? $aberto : $fluxo;
        }
    }
    return $texto;
}

$pdf = mcp_voluntario_pdf($ficha);
$conteudo = pdf_texto($pdf);
verificar('pdf: começa com %PDF e termina com %%EOF', [str_starts_with($pdf, '%PDF-1.4'), str_ends_with($pdf, "%%EOF\n")], [true, true]);
verificar('pdf: 1 página para a ficha curta', substr_count($pdf, '/Type /Page /Parent'), 1);
verificar('pdf: protocolo, nome e CPF só com os 3 primeiros dígitos', [str_contains($conteudo, 'VOL-261016-0042'), str_contains($conteudo, 'maria da Silva'),
    str_contains($conteudo, substr($cpf, 0, 3) . '.***.***-**'), str_contains($conteudo, $cpf), str_contains($conteudo, mcp_cpf_formatado($cpf)), str_contains($conteudo, substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3))], [true, true, true, false, false, false]);
verificar('pdf: consentimentos com data em Brasília e revisão da política', [str_contains($conteudo, '16/10/2026'), str_contains($conteudo, '2026-10-02'), str_contains($conteudo, 'CONSENTIMENTOS \\(LGPD\\)')], [true, true, true]);
verificar('pdf: frentes e disponibilidade', [str_contains($conteudo, 'Juventude'), str_contains($conteudo, 'Segunda, S')], [true, true]);
verificar('pdf: metadados com título', str_contains($pdf, strtoupper(bin2hex(mb_convert_encoding('Ficha de cadastro', 'UTF-16BE', 'UTF-8')))), true);
$longa = $ficha;
$longa['motivacao'] = trim(str_repeat('Quero ajudar as pessoas da minha cidade em emergências e aprender com a equipe. ', 20));
$longa['experiencia'] = mcp_texto_longo(str_repeat('Fui voluntária em campanhas de arrecadação e em ações de rua durante três anos, organizando a logística. ', 12), MCP_VOL_TEXTO_LONGO_MAX);
$longa['habilidades'] = mcp_texto_longo(str_repeat('Primeiros socorros, inglês avançado, direção defensiva, organização de eventos, redes sociais. ', 12), MCP_VOL_TEXTO_LONGO_MAX);
$longa['data_nascimento'] = '2009-06-01';
$pdfLongo = mcp_voluntario_pdf($longa);
$conteudoLongo = pdf_texto($pdfLongo);
verificar('pdf: ficha com os textos no máximo cabe em 2 páginas', substr_count($pdfLongo, '/Type /Page /Parent'), 2);
verificar('pdf: menor de 18 avisa do responsável; numeração das páginas', [str_contains($conteudoLongo, 'respons'), str_contains($conteudoLongo, 'gina 2 de 2')], [true, true]);
verificar('nome do arquivo do PDF', mcp_voluntario_pdf_arquivo(['protocolo' => 'VOL-261016-0042', 'nome' => 'MARIA DA SILVA']), 'VOL-261016-0042_Maria_da_Silva.pdf');

// ----------------------------------------------------------------------------- e-mails (montagem)
$equipe = mcp_montar_email_voluntario_equipe($ficha);
$tudoEquipe = $equipe['assunto'] . $equipe['html'] . $equipe['texto'];
verificar('e-mail à equipe: assunto', $equipe['assunto'], '[Voluntariado] Nova ficha: maria da Silva · Rio de Janeiro · VOL-261016-0042');
verificar('e-mail à equipe: botão Abrir no portal com o link da ficha', [str_contains($equipe['html'], '>Abrir no portal<'), str_contains($equipe['html'], 'painel.php?v=voluntario&amp;id=42'), str_contains($equipe['texto'], 'painel.php?v=voluntario&id=42')], [true, true, true]);
verificar('e-mail à equipe: motivação, frentes e disponibilidade', [str_contains($equipe['html'], 'Quero ajudar as pessoas'), str_contains($equipe['html'], 'Formação e primeiros socorros, Juventude'), str_contains($equipe['html'], 'Segunda, Sábado · Manhã, Noite')], [true, true, true]);
verificar('e-mail à equipe: sem CPF, sem número nem link de WhatsApp, sem o domínio antigo', [str_contains($tudoEquipe, $cpf), str_contains($tudoEquipe, substr($cpf, 3, 3)), (bool) preg_match('/97036|api\.whatsapp|wa\.me/', $tudoEquipe), str_contains($tudoEquipe, 'cruzvermelharj')], [false, false, false, false]);
verificar('e-mail à equipe: adulto não leva o aviso do responsável', str_contains($tudoEquipe, 'Menor de 18'), false);
$equipeMenor = mcp_montar_email_voluntario_equipe(['data_nascimento' => '2009-06-01'] + $ficha);
verificar('e-mail à equipe: menor de 18 leva o aviso', str_contains($equipeMenor['html'], 'Menor de 18 anos'), true);

// Turma do CBFI de 28/11/2026: até o dia dela os textos falam da turma; no dia seguinte voltam para janeiro.
mcp_vol_hoje('2026-11-28');
verificar('CBFI: no dia 28/11 a turma ainda vale', [mcp_vol_cbfi_aberto(), mcp_vol_prazo()], [true, 'antes do CBFI de 28 de novembro de 2026']);
$cbfiConfirmacao = mcp_montar_email_voluntario_confirmacao($ficha);
$cbfiTudo = $cbfiConfirmacao['assunto'] . $cbfiConfirmacao['html'] . $cbfiConfirmacao['texto'];
$cbfiEquipe = mcp_montar_email_voluntario_equipe($ficha);
verificar('CBFI: confirmação, aviso à equipe e frase da API falam da turma, não de janeiro', [str_contains($cbfiTudo, '28 de novembro de 2026'), str_contains($cbfiTudo, 'janeiro de 2027'), str_contains($cbfiConfirmacao['html'], 'A próxima turma do CBFI é no dia 28 de novembro de 2026'), str_contains($cbfiEquipe['html'], 'turma do CBFI de 28 de novembro de 2026'), str_contains(mcp_vol_aviso_prazo(), '28 de novembro de 2026')], [true, false, true, true, true]);
// O contato da equipe é pelo WhatsApp (27/10/2026): a confirmação diz isso e mostra o telefone da ficha.
verificar('confirmação: contato pelo WhatsApp, no telefone da ficha, não por e-mail', [str_contains($cbfiTudo, 'O contato vem pelo WhatsApp'), str_contains($cbfiTudo, mcp_telefone_bonito((string) $ficha['telefone'])), str_contains($cbfiTudo, 'O contato vem por e-mail')], [true, true, false]);
verificar('CBFI: texto puro continua com os passos numerados', (bool) preg_match('/^1\) .+\n2\) .+\n3\) .+\n4\) .+$/m', $cbfiConfirmacao['texto']), true);
mcp_vol_hoje('2026-11-29');
verificar('CBFI: em 29/11 volta para janeiro', [mcp_vol_cbfi_aberto(), mcp_vol_prazo(), str_contains(mcp_vol_aviso_prazo(), 'janeiro de 2027')], [false, 'em janeiro de 2027', true]);

$confirmacao = mcp_montar_email_voluntario_confirmacao($ficha);
$tudo = $confirmacao['assunto'] . $confirmacao['html'] . $confirmacao['texto'];
verificar('confirmação: assunto com o protocolo', $confirmacao['assunto'], 'Recebemos sua ficha de voluntariado · VOL-261016-0042');
verificar('confirmação: próximos passos (contato, entrevista, CBFI, termo de adesão)', [str_contains($tudo, 'ficha de entrevista'), str_contains($tudo, 'CBFI'), str_contains($tudo, 'quatro vezes por ano'), str_contains($tudo, 'Termo de adesão'), str_contains($tudo, 'janeiro de 2027'), str_contains($tudo, '3 dias úteis')], [true, true, true, true, true, false]);
verificar('confirmação: protocolo, frentes e política', [str_contains($confirmacao['html'], 'VOL-261016-0042'), str_contains($confirmacao['html'], 'Formação e primeiros socorros, Juventude'), str_contains($confirmacao['html'], 'https://exemplo.org/privacidade/'), str_contains($confirmacao['html'], '2026-10-02')], [true, true, true, true]);
verificar('confirmação: sem CPF, sem número nem link de WhatsApp, sem doação, sem o domínio antigo', [str_contains($tudo, $cpf), (bool) preg_match('/97036|api\.whatsapp|wa\.me/', $tudo), stripos($tudo, 'doaç') !== false, str_contains($tudo, 'cruzvermelharj')], [false, false, false, false]);
verificar('confirmação: adulto não leva o aviso do responsável', str_contains($tudo, 'responsável legal'), false);
$confirmacaoMenor = mcp_montar_email_voluntario_confirmacao(['data_nascimento' => '2009-06-01'] + $ficha);
verificar('confirmação: menor de 18 leva o aviso do responsável no HTML e no texto', [str_contains($confirmacaoMenor['html'], 'responsável legal'), str_contains($confirmacaoMenor['texto'], 'responsável legal'), str_contains($confirmacaoMenor['html'], 'Você tem menos de 18 anos')], [true, true, true]);
verificar('confirmação: texto puro tem os passos numerados', (bool) preg_match('/^1\) .+\n2\) .+\n3\) .+\n4\) .+$/m', $confirmacao['texto']), true);

unlink($configTeste);
unlink($semExtra);
printf("%d testes, %d falhas\n", $total, $falhas);
exit($falhas > 0 ? 1 : 0);
