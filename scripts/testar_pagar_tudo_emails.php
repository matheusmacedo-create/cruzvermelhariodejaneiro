#!/usr/bin/env php
<?php
/**
 * Pagar tudo: testes da comunicação (e-mails da 1.14 e da 10.6, lembrete do PIX, comprovante da 1.15), sem banco e sem
 * rede. Chamado pelo scripts/testar_checkout.php; também roda sozinho.
 *
 * Uso: php scripts/testar_pagar_tudo_emails.php [pasta]   (com a pasta, grava o HTML/texto de cada e-mail e os PDFs)
 * Configuração descartável (arquivo temporário), oferta.json temporário com uma turma de Primeiros Socorros Básico daqui
 * a 10 dias e a venda sem turma de Cuidador de Idosos. CPFs fictícios com dígito válido; e-mails @exemplo.org.
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$tmp = sys_get_temp_dir();
$config = tempnam($tmp, 'mcp-config-');
file_put_contents($config, "<?php return [
    'SITE_URL' => 'https://cruzvermelhariodejaneiro.org', 'INSCRICAO_CENTAVOS' => '', 'PRECO_TESTE_CENTAVOS' => '',
    'TAXA_PIX_PCT' => 5.0, 'TAXA_PIX_FIXA' => 0, 'TAXA_CARTAO_PCT' => 5.0, 'TAXA_CARTAO_FIXA' => 0,
    'ESCOLA_API_URL' => 'https://escola.exemplo.invalid/rest/v1/rpc/matricula_rapida', 'ESCOLA_URL' => 'https://escola.cursoscruzvermelha.org', 'EMAIL_CONTATO' => 'contato@cruzvermelhariodejaneiro.org',
];");
putenv("MCP_CONFIG_ARQUIVO=$config");
putenv('MCP_CONFIG_ESCOLA_ARQUIVO=' . $tmp . '/nao-existe-escola.php');
putenv('MCP_CONFIG_WHATSAPP_ARQUIVO=' . $tmp . '/nao-existe-wa.php');
putenv('MCP_CONFIG_META_ARQUIVO=' . $tmp . '/nao-existe-meta.php');
putenv('MCP_CATALOGO_ARQUIVO=' . $raiz . '/site/matricula-cursos-presenciais/cursos.json');
$oferta = tempnam($tmp, 'mcp-oferta-');
file_put_contents($oferta, json_encode([
    'parcelado_no_ar' => false,
    'turmas' => [[
        'curso' => 'primeiros-socorros-basico', 'id_escola' => '47d694b2-b2bc-4885-a0aa-67637b847957',
        'inicio' => gmdate('Y-m-d', time() + 10 * 86400) . 'T09:00:00-03:00', 'fim' => gmdate('Y-m-d', time() + 10 * 86400) . 'T17:00:00-03:00',
        'inscricoes_ate' => gmdate('Y-m-d', time() + 9 * 86400) . 'T23:59:59-03:00', 'lotada' => false,
    ]],
    'sem_turma' => ['cuidador-de-idosos' => ['max_fila' => 30]],
]));
putenv("MCP_OFERTA_ARQUIVO=$oferta");
ini_set('error_log', $tmp . '/mcp-teste-emails.log');
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $raiz . '/site/matricula-cursos-presenciais/api/lib.php';
restore_exception_handler();
$saidaTemp = !isset($argv[1]);
$saida = $argv[1] ?? ($tmp . '/mcp-emails-' . getmypid());
register_shutdown_function(static function () use ($config, $oferta, $saida, $saidaTemp) {
    @unlink($config);
    @unlink($oferta);
    if ($saidaTemp) {
        array_map('unlink', glob("$saida/*") ?: []);
        @rmdir($saida);
    }
});
@mkdir($saida, 0777, true);
$ok = 0; $falhas = [];
function confere(string $nome, bool $cond, string $extra = ''): void { global $ok, $falhas; if ($cond) { $ok++; } else { $falhas[] = $nome . ($extra !== '' ? " — $extra" : ''); } }
function tem(array $m, string $s): bool { return str_contains($m['html'], mcp_escapar($s)) || str_contains($m['html'], $s) || str_contains($m['texto'], $s); }
function grava(string $nome, array $m): void { global $saida; file_put_contents("$saida/$nome.html", $m['html']); file_put_contents("$saida/$nome.txt", "Assunto: {$m['assunto']}\n\n{$m['texto']}\n"); }

$turmaId = '47d694b2-b2bc-4885-a0aa-67637b847957';
$diaTurma = gmdate('Y-m-d', time() + 10 * 86400);
$diaTurmaBr = (new DateTimeImmutable($diaTurma))->format('d/m/Y');
$base = [
    'id' => 42, 'token' => str_repeat('ab12', 10), 'status' => 'pago', 'metodo' => 'pix', 'curso_slug' => 'primeiros-socorros-basico', 'curso_nome' => 'Primeiros Socorros Básico',
    'nome' => 'Joana Teste da Silva', 'cpf' => '52998224725', 'email' => 'joana@exemplo.org', 'telefone' => '21999998888',
    'inscricao_centavos' => 9900, 'taxa_centavos' => 0, 'divulgacao_centavos' => 0, 'total_centavos' => 27900, 'unicopag_hash' => 'tx_teste01',
    'pix_copia_cola' => '000201PIXTESTE', 'bandeira' => null, 'ultimos4' => null, 'utm_source' => null, 'utm_campaign' => null,
    'criado_em' => gmdate('Y-m-d H:i:s', time() - 3600), 'pago_em' => gmdate('Y-m-d H:i:s', time() - 600),
    'escola_status' => 'ok', 'escola_tentativas' => 1, 'escola_token' => str_repeat('a', 64),
    'plano' => 'taxa_e_matricula', 'matricula_centavos' => 18000, 'matricula_preco_centavos' => 18000, 'parcelas' => 1, 'juros_centavos' => 0,
    'total_cobrado_centavos' => null, 'turma_id' => $turmaId, 'turma_inicio' => $diaTurma . ' 12:00:00', 'diferenca_devolver_centavos' => 0,
    'espera_status' => null, 'espera_prazo' => null, 'espera_desde' => null,
];
$acesso = static fn(array $extra = []): string => json_encode($extra + [
    'resultado' => 'matriculado', 'aluno_novo' => true, 'email_conta' => null, 'email_confere' => true, 'matricula_id' => 'm1',
    'turma_inicio' => $GLOBALS['diaTurma'], 'aviso' => null, 'link' => true, 'link_expira_em' => gmdate('Y-m-d\TH:i:s\Z', time() + 72 * 3600),
    'url_login' => 'https://escola.cursoscruzvermelha.org/login', 'matricula_paga' => true, 'avisos' => [], 'turma_id' => $GLOBALS['turmaId'],
    'turma_primeira_aula' => $GLOBALS['diaTurma'], 'turma_horario' => '09:00 - 17:00', 'turma_status' => 'ABERTA', 'matricula_status' => 'PAGO', 'espera_motivo' => null,
]);
$paga = ['escola_acesso' => $acesso()] + $base;
$cartao10 = ['metodo' => 'cartao', 'bandeira' => 'visa', 'ultimos4' => '1111', 'parcelas' => 10, 'juros_centavos' => 7533, 'total_cobrado_centavos' => 35433, 'taxa_mes_pct' => '4.600', 'cet_ano_pct' => '71.550'] + $paga;

// ---------------------------------------------------------------- helpers
confere('divisão 10x', mcp_email_divisao(35433, 10) === ['n' => 10, 'parcela' => 3543, 'primeira' => 3546, 'total' => 35433]);
foreach ([[27900, 1], [28737, 2], [29574, 3], [35433, 10], [37107, 12]] as [$t, $n]) { $d = mcp_email_divisao($t, $n); confere("soma das parcelas $n", $d['primeira'] + ($n - 1) * $d['parcela'] === $t); }
confere('pago por PIX', mcp_email_pago_por($paga) === 'PIX');
confere('pago por crédito 1x', mcp_email_pago_por(['metodo' => 'cartao', 'ultimos4' => '4242', 'parcelas' => 1] + $paga) === 'Crédito, final 4242');
confere('pago por 10x', mcp_email_pago_por($cartao10) === 'Crédito em 10 parcelas mensais (1ª de R$ 35,46 e 9 de R$ 35,43), final 1111', mcp_email_pago_por($cartao10));
confere('pago por 3x iguais', mcp_email_pago_por(['parcelas' => 3, 'total_cobrado_centavos' => 29574] + $cartao10) === 'Crédito em 3 parcelas mensais de R$ 98,58, final 1111', mcp_email_pago_por(['parcelas' => 3, 'total_cobrado_centavos' => 29574] + $cartao10));
confere('acréscimo 27%', mcp_email_acrescimo($cartao10) === '27%', mcp_email_acrescimo($cartao10));
confere('linha do crédito', mcp_email_linha_credito($cartao10) === 'Parcelamento: 10 parcelas mensais (1ª de R$ 35,46 e 9 de R$ 35,43) · juros de 4,60% ao mês · CET de 71,55% ao ano · preço à vista R$ 279,00 · total R$ 354,33 · concedido por O-CVB Filial Rio de Janeiro Ensino Ltda', mcp_email_linha_credito($cartao10));
confere('linha do crédito some à vista', mcp_email_linha_credito($paga) === '');
confere('total pago = cobrado', mcp_email_total_pago_centavos($cartao10) === 35433 && mcp_email_total_pago_centavos($paga) === 27900);
confere('matrícula: preço gravado > catálogo', mcp_email_matricula(['matricula_preco_centavos' => 17000] + $paga) === 'R$ 170,00' && mcp_email_matricula(['matricula_preco_centavos' => null] + $paga) === 'R$ 180,00');
confere('taxa em dobro com juros (12x do exemplo)', mcp_email_taxa_em_dobro_centavos(['total_centavos' => 27900, 'total_cobrado_centavos' => 37107, 'inscricao_centavos' => 9900, 'parcelas' => 12, 'metodo' => 'cartao']) === 13167);
confere('janela: envio + 7 ou véspera', mcp_email_janela_ate(gmdate('Y-m-d', time() + 30 * 86400), time()) === (new DateTimeImmutable('@' . time()))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->modify('+7 days')->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
$vesp = mcp_email_janela_ate(gmdate('Y-m-d', time() + 3 * 86400), time());
confere('janela: véspera quando antes', mcp_email_quando($vesp, true)->format('Y-m-d') === (new DateTimeImmutable(gmdate('Y-m-d', time() + 3 * 86400)))->modify('-1 day')->format('Y-m-d'));
$prazoUtc = (new DateTimeImmutable('2027-01-06 23:59:59', new DateTimeZone('America/Sao_Paulo')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
confere('data limite e marcar até', mcp_email_data_limite(['espera_prazo' => $prazoUtc]) === '06/01/2027' && mcp_email_marcar_ate(['espera_prazo' => $prazoUtc]) === '27/12/2026');
$t = mcp_email_turma_da_inscricao($paga);
confere('turma da escola', $t['data'] === $diaTurmaBr && $t['horario'] === '09:00 - 17:00' && $t['prazo'] !== '' && $t['aberta'], json_encode($t, JSON_UNESCAPED_UNICODE));

// ---------------------------------------------------------------- PIX aberto e lembrete
$pix1 = ['status' => 'pendente', 'pago_em' => null, 'escola_acesso' => null, 'escola_status' => 'nao_aplicavel'] + $base;
$m = mcp_montar_email_pix_aberto($pix1); grava('01-pix-opcao1', $m);
confere('PIX op1 assunto', $m['assunto'] === 'Falta só o PIX para concluir sua inscrição e matrícula em Primeiros Socorros Básico', $m['assunto']);
confere('PIX op1 caixa e passos', tem($m, 'Valor da matrícula') && tem($m, 'Matrícula confirmada') && tem($m, 'Você entra na turma de') && tem($m, MCP_EMAIL_PASSO_03) && tem($m, 'Pague até'));
confere('PIX op1 prévia', tem($m, 'Matrícula confirmada assim que o PIX cair.'));
$pix2 = ['plano' => 'so_taxa', 'matricula_centavos' => 0, 'total_centavos' => 9900] + $pix1;
$m = mcp_montar_email_pix_aberto($pix2); grava('02-pix-opcao2', $m);
confere('PIX op2 assunto', $m['assunto'] === 'Falta só o PIX para concluir sua inscrição em Primeiros Socorros Básico', $m['assunto']);
confere('PIX op2 matrícula depois', tem($m, 'Depois, a matrícula') && tem($m, 'R$ 180,00 à vista, paga antes da aula') && !tem($m, 'Valor da matrícula') && tem($m, 'Pague até'));
$pixSem = ['curso_slug' => 'cuidador-de-idosos', 'curso_nome' => 'Cuidador de Idosos (Curso Livre)', 'turma_id' => null, 'turma_inicio' => null, 'matricula_centavos' => 95000, 'matricula_preco_centavos' => 95000, 'total_centavos' => 104900, 'espera_prazo' => $prazoUtc] + $pix1;
$m = mcp_montar_email_pix_aberto($pixSem); grava('03-pix-sem-turma', $m);
confere('PIX sem turma', tem($m, 'você entra na fila da próxima turma deste curso') && tem($m, 'Fila da próxima turma') && tem($m, '06/01/2027') && !tem($m, 'Pague até') && tem($m, 'Acompanhe sua inscrição'));
$m = mcp_montar_email_pix_lembrete($pix1); grava('04-lembrete-opcao1', $m);
confere('lembrete op1', $m['assunto'] === 'Sua inscrição e matrícula em Primeiros Socorros Básico continuam abertas: falta só o PIX' && tem($m, 'a matrícula é confirmada e a confirmação chega neste e-mail') && tem($m, 'Matrícula confirmada'));
$m = mcp_montar_email_pix_lembrete($pix2); grava('05-lembrete-opcao2', $m);
confere('lembrete op2', $m['assunto'] === 'Sua inscrição em Primeiros Socorros Básico continua aberta: falta só o PIX');
$m = mcp_montar_email_pix_lembrete($pixSem); grava('06-lembrete-sem-turma', $m);
confere('lembrete sem turma', tem($m, 'Fila da próxima turma'));
confere('lembrete: turma aberta não é fechada', !mcp_pix_lembrete_turma_fechada($pix1));
confere('lembrete: turma fora do oferta é fechada', mcp_pix_lembrete_turma_fechada(['turma_id' => 'outra'] + $pix1));
confere('lembrete: só a taxa nunca fecha', !mcp_pix_lembrete_turma_fechada(['turma_id' => 'outra'] + $pix2));
confere('lembrete: sem turma nunca fecha', !mcp_pix_lembrete_turma_fechada($pixSem));

// ---------------------------------------------------------------- pagamento confirmado: opção 1
$casos = [
    'paga' => [$paga, 'Matrícula confirmada em Primeiros Socorros Básico', ['Sua matrícula na turma de', 'Criar minha senha', 'Ver minhas inscrições', 'Resumo das condições', 'Venha para a aula', '09:00 - 17:00']],
    'paga-10x' => [$cartao10, 'Matrícula confirmada em Primeiros Socorros Básico', ['Crédito em 10 parcelas mensais (1ª de R$ 35,46 e 9 de R$ 35,43), final 1111', 'Juros do parcelamento (acréscimo de 27% sobre o valor à vista)', 'R$ 354,33', 'Parcelamento: 10 parcelas mensais', 'aparece como "À vista", no valor de R$ 279,00']],
    'dobro' => [['escola_acesso' => $acesso(['avisos' => ['taxa_em_dobro']])] + $paga, 'Matrícula confirmada em Primeiros Socorros Básico', ['Você já tinha pago a taxa de inscrição deste curso. Os', 'R$ 99,00']],
    // turma_lotada (P3, decisão 14): quem pagou tudo entra acima da vaga e recebe a variante paga, sem nota.
    'lotada' => [['escola_acesso' => $acesso(['avisos' => ['turma_lotada']])] + $paga, 'Matrícula confirmada em Primeiros Socorros Básico', ['Sua matrícula na turma de', 'Venha para a aula']],
    'diferente' => [['escola_acesso' => $acesso(['avisos' => ['turma_diferente'], 'turma_primeira_aula' => gmdate('Y-m-d', time() + 20 * 86400), 'turma_inicio' => gmdate('Y-m-d', time() + 20 * 86400)])] + $paga, 'Pagamento confirmado em Primeiros Socorros Básico: confira a data da sua turma', ['lotou ou fechou antes da confirmação do seu pagamento', 'reservamos para você a turma de']],
    'ja-pago' => [['escola_acesso' => $acesso(['avisos' => ['curso_ja_pago'], 'matricula_paga' => false])] + $paga, 'Sua matrícula em Primeiros Socorros Básico já estava paga', ['será devolvido por inteiro', 'Não pague de novo']],
    'nao-marcada' => [['escola_acesso' => $acesso(['matricula_paga' => null, 'avisos' => []])] + $paga, 'Pagamento confirmado em Primeiros Socorros Básico', ['A secretaria está concluindo o registro da sua matrícula', 'não pague de novo: ela já está paga']],
    'sem-escola' => [['escola_acesso' => null, 'escola_status' => 'erro'] + $paga, 'Pagamento confirmado em Primeiros Socorros Básico', ['A secretaria está concluindo o registro']],
];
$espera = ['curso_slug' => 'cuidador-de-idosos', 'curso_nome' => 'Cuidador de Idosos (Curso Livre)', 'turma_id' => null, 'turma_inicio' => null, 'matricula_centavos' => 95000, 'matricula_preco_centavos' => 95000, 'total_centavos' => 104900,
    'espera_status' => 'aguardando', 'espera_prazo' => $prazoUtc, 'espera_desde' => $base['pago_em'],
    'escola_acesso' => $acesso(['resultado' => 'sem_turma', 'matricula_paga' => false, 'avisos' => ['matricula_paga_sem_turma'], 'turma_id' => null, 'turma_inicio' => null, 'turma_primeira_aula' => null, 'turma_horario' => null, 'espera_motivo' => 'na_fila'])] + $paga;
$casos['espera'] = [$espera, 'Inscrição e matrícula pagas em Cuidador de Idosos (Curso Livre): você está na fila da próxima turma', ['Tudo pago', 'Data limite', '06/01/2027', 'Não pague de novo e não se inscreva pela Escola', 'Ainda não há turma marcada. Quando a Escola marcar uma', 'Acompanhe sua inscrição', 'Nos cursos comprados sem turma aberta']];
$casos['espera-fechou'] = [['turma_id' => $turmaId, 'turma_inicio' => $diaTurma . ' 12:00:00', 'curso_slug' => 'primeiros-socorros-basico', 'curso_nome' => 'Primeiros Socorros Básico', 'total_centavos' => 27900, 'matricula_centavos' => 18000] + $espera,
    'Pagamento confirmado em Primeiros Socorros Básico: você está na fila da próxima turma', ['fecharam antes da confirmação do seu pagamento', 'Você fica na fila da próxima turma, com tudo pago']];
foreach ($casos as $nome => [$i, $assunto, $trechos]) {
    $m = mcp_montar_email_aluno_pago($i, true); grava("10-pago-$nome", $m);
    confere("pago $nome: assunto", $m['assunto'] === $assunto, $m['assunto']);
    foreach ($trechos as $tr) { confere("pago $nome: '$tr'", tem($m, $tr)); }
    foreach (['Entrar e pagar a matrícula', 'próximo passo, pagar a matrícula', 'pague a matrícula', 'Pague a matrícula'] as $proibido) { confere("pago $nome: sem '$proibido'", !tem($m, $proibido)); }
    confere("pago $nome: fornecedor no rodapé", str_contains($m['html'], 'O-CVB Filial Rio de Janeiro Ensino Ltda · CNPJ 67.733.551/0001-35') && !str_contains($m['html'], '08.560.973'));
    confere("pago $nome: sem WhatsApp", stripos($m['html'] . $m['texto'], 'whatsapp') === false);
}
// ---------------------------------------------------------------- opção 2
$taxa = ['plano' => 'so_taxa', 'matricula_centavos' => 0, 'total_centavos' => 9900, 'escola_acesso' => $acesso(['matricula_paga' => null])] + $base;
$m = mcp_montar_email_aluno_pago($taxa); grava('20-taxa-com-turma', $m);
confere('op2 com turma: assunto', $m['assunto'] === 'Taxa de inscrição confirmada em Primeiros Socorros Básico: falta só o pagamento da matrícula', $m['assunto']);
confere('op2 com turma: textos', tem($m, 'Falta pagar a matrícula.') && tem($m, 'A secretaria da Escola escreve para você com o jeito de pagar.') && tem($m, 'Vaga reservada') && !tem($m, 'Entrar e pagar a matrícula'));
$m = mcp_montar_email_aluno_pago(['escola_acesso' => $acesso(['resultado' => 'sem_turma', 'matricula_paga' => null, 'turma_inicio' => null, 'turma_primeira_aula' => null]), 'turma_id' => null, 'turma_inicio' => null, 'curso_slug' => 'bombeiro-civil', 'curso_nome' => 'Bombeiro Civil', 'matricula_preco_centavos' => 95000] + $taxa); grava('21-taxa-sem-turma', $m);
confere('op2 sem turma', $m['assunto'] === 'Você está na lista da próxima turma de Bombeiro Civil' && tem($m, 'A matrícula, depois') && tem($m, 'orienta o pagamento da matrícula (R$ 950,00 à vista)'));
$m = mcp_montar_email_aluno_pago(['escola_acesso' => $acesso(['aviso' => 'taxa_ja_confirmada', 'avisos' => ['taxa_ja_confirmada']])] + $taxa); grava('22-taxa-ja-confirmada', $m);
confere('op2 taxa já confirmada', $m['assunto'] === 'Você já tinha pago a inscrição em Primeiros Socorros Básico' && tem($m, 'será devolvido por inteiro'));
$m = mcp_montar_email_aluno_pago(['escola_acesso' => null, 'escola_status' => 'nao_aplicavel'] + $taxa, false); grava('23-taxa-versao-b', $m);
confere('op2 versão B', $m['tipo'] === 'confirmacao' && tem($m, 'este e-mail é o seu comprovante'));

// ---------------------------------------------------------------- secretaria
$s = mcp_montar_email_secretaria($paga); grava('30-secretaria-op1', $s);
confere('secretaria op1 assunto', $s['assunto'] === 'Matrícula paga (taxa + matrícula): Joana Teste da Silva — Primeiros Socorros Básico', $s['assunto']);
confere('secretaria op1 linhas', tem($s, 'Taxa de inscrição + matrícula') && tem($s, 'R$ 279,00 (taxa R$ 99,00 + matrícula R$ 180,00)') && tem($s, 'Parcelas: à vista'));
$s = mcp_montar_email_secretaria($cartao10);
confere('secretaria 10x', tem($s, '10× (1ª de R$ 35,46 e 9 de R$ 35,43) (juros R$ 75,33)') && tem($s, 'R$ 354,33 (taxa R$ 99,00 + matrícula R$ 180,00 + juros R$ 75,33)'));
$s = mcp_montar_email_secretaria($casos['nao-marcada'][0]); grava('31-secretaria-urgente', $s);
confere('secretaria urgente não marcada', str_starts_with($s['assunto'], 'URGENTE: Matrícula paga'), $s['assunto']);
$s = mcp_montar_email_secretaria(['posicao_fila' => 2] + $espera); grava('32-secretaria-espera', $s);
confere('secretaria espera', $s['assunto'] === 'Pagou tudo e espera turma: Joana Teste da Silva — Cuidador de Idosos (Curso Livre)' && tem($s, '(é a 2ª da fila)') && tem($s, 'Data limite'), $s['assunto']);
$s = mcp_montar_email_secretaria($taxa);
confere('secretaria op2 com turma', $s['assunto'] === 'Inscrição paga: Joana Teste da Silva — Primeiros Socorros Básico' && tem($s, 'Nova inscrição paga (só a taxa).'));
$s = mcp_montar_email_secretaria(['escola_acesso' => $acesso(['aviso' => 'taxa_ja_confirmada', 'avisos' => ['taxa_ja_confirmada']])] + $taxa);
confere('secretaria op2 taxa já confirmada urgente', str_starts_with($s['assunto'], 'URGENTE: '), $s['assunto']);
// estorno
foreach ([['a', ['escola_acesso' => $acesso(['avisos' => ['curso_ja_pago']])] + $paga], ['b', $paga], ['c', $taxa], ['d', $espera]] as [$caso, $i]) {
    $e = mcp_montar_email_estorno_secretaria($i, false); grava("33-estorno-$caso", $e);
    confere("estorno caso $caso", $e['caso'] === $caso && $e['assunto'] === 'Estorno confirmado: Joana Teste da Silva — ' . $i['curso_nome'], $e['caso']);
}
confere('estorno dobro (só a taxa)', mcp_montar_email_estorno_secretaria($taxa, true)['caso'] === 'a');

// ---------------------------------------------------------------- venda sem turma (10.6)
$naTurma = ['espera_status' => 'turma', 'turma_id' => 't-nova', 'espera_janela_ate' => null,
    'escola_acesso' => $acesso(['turma_id' => 't-nova', 'turma_primeira_aula' => gmdate('Y-m-d', time() + 15 * 86400), 'turma_inicio' => gmdate('Y-m-d', time() + 15 * 86400), 'turma_horario' => '18:00 - 22:00', 'turma_status' => 'ABERTA', 'link' => false])] + $espera;
$m = mcp_montar_email_turma_aberta($naTurma); grava('40-turma-aberta', $m);
confere('turma abriu assunto', str_starts_with($m['assunto'], 'Sua turma de Cuidador de Idosos (Curso Livre) abriu: começa em '), $m['assunto']);
confere('turma abriu textos', tem($m, 'A turma ainda precisa de um número mínimo de alunos') && tem($m, 'A data ou o horário não servem?') && tem($m, 'Até a turma ser confirmada: tudo de volta.') && tem($m, '18:00 - 22:00') && tem($m, 'Ver minhas inscrições') && tem($m, 'Ainda não criou a senha?'));
$m = mcp_montar_email_turma_aberta(['escola_acesso' => $acesso(['turma_id' => 't-nova', 'turma_primeira_aula' => gmdate('Y-m-d', time() + 15 * 86400), 'turma_horario' => '18:00 - 22:00', 'turma_status' => 'CONFIRMADA'])] + $naTurma);
confere('turma abriu confirmada', tem($m, 'A turma já está confirmada.') && !tem($m, 'Até a turma ser confirmada'));
$m = mcp_montar_email_turma_mudou(['espera_janela_ate' => gmdate('Y-m-d H:i:s', time() + 7 * 86400)] + $naTurma, ['primeira_aula' => gmdate('Y-m-d', time() + 12 * 86400)]); grava('41-turma-mudou', $m);
confere('turma mudou', str_starts_with($m['assunto'], 'A turma de Cuidador de Idosos (Curso Livre) mudou: agora começa em') && tem($m, 'passou de'));
$m = mcp_montar_email_turma_cancelada(['espera_turma_dados' => 't-nova|' . gmdate('Y-m-d', time() + 15 * 86400) . '|18:00 - 22:00|CANCELADA|PAGO', 'espera_janela_ate' => gmdate('Y-m-d H:i:s', time() + 7 * 86400)] + $naTurma, '20/10'); grava('42-turma-cancelada', $m);
confere('turma cancelada', str_contains($m['assunto'], 'foi cancelada') && str_contains($m['assunto'], (new DateTimeImmutable(gmdate('Y-m-d', time() + 15 * 86400)))->format('d/m/Y')) && tem($m, 'devolvemos tudo, sem você precisar pedir'), $m['assunto']);
$m = mcp_montar_email_turma_confirmada($naTurma); grava('43-turma-confirmada', $m);
confere('turma confirmada', str_starts_with($m['assunto'], 'Turma confirmada: Cuidador de Idosos (Curso Livre) começa em') && tem($m, 'a matrícula volta, com os juros do parcelamento que correspondem a ela'));
foreach (['30' => 'Ainda sem data para Cuidador de Idosos (Curso Livre): você continua na fila', 'prazo' => 'Cuidador de Idosos (Curso Livre): se a turma não for marcada até 27/12/2026, devolvemos tudo'] as $v => $assunto) {
    $m = mcp_montar_email_espera_lembrete($espera, (string) $v); grava("44-espera-$v", $m);
    confere("espera lembrete $v", $m['assunto'] === $assunto, $m['assunto']);
}
confere('prazo PIX com "Quero continuar"', tem(mcp_montar_email_espera_lembrete($espera, 'prazo'), 'Quero continuar'));
confere('prazo cartão sem limite: sem "Quero continuar"', !tem(mcp_montar_email_espera_lembrete(['metodo' => 'cartao'] + $espera, 'prazo'), 'Quero continuar'));
$m = mcp_montar_email_espera_prorrogada($espera); grava('45-espera-prorrogada', $m);
confere('prorrogada', $m['assunto'] === 'Você continua na fila de Cuidador de Idosos (Curso Livre) até 06/01/2027');
foreach (['prazo' => 'A turma de Cuidador de Idosos (Curso Livre) não foi marcada a tempo: vamos devolver tudo o que você pagou', 'pedido' => 'Pedido de devolução recebido: Cuidador de Idosos (Curso Livre)',
    'turma_cancelada' => 'Vamos devolver o seu pagamento em Cuidador de Idosos (Curso Livre)', 'feita' => 'Devolução feita: R$ 1.049,00 em Cuidador de Idosos (Curso Livre)', 'secretaria' => 'Vamos devolver o seu pagamento em Cuidador de Idosos (Curso Livre)'] as $motivo => $assunto) {
    $m = mcp_montar_email_espera_devolucao($espera, $motivo); grava("46-devolucao-$motivo", $m);
    confere("devolução $motivo", $m['assunto'] === $assunto, $m['assunto']);
}
confere('devolução cartão', tem(mcp_montar_email_espera_devolucao(['metodo' => 'cartao'] + $espera, 'pedido'), 'Vamos pedir o estorno dos R$ 1.049,00'));
confere('devolução PIX > 90 dias', tem(mcp_montar_email_espera_devolucao(['pago_em' => gmdate('Y-m-d H:i:s', time() - 95 * 86400)] + $espera, 'pedido'), 'responda este e-mail com a chave PIX'));

// ---------------------------------------------------------------- comprovante
$c = mcp_comprovante_conteudo($cartao10, gmdate('Y-m-d H:i:s'));
confere('comprovante op1 título', $c['titulo'] === 'COMPROVANTE DE INSCRIÇÃO E MATRÍCULA' && $c['subtitulo'] === 'Primeiros Socorros Básico — Inscrição e matrícula');
confere('comprovante op1 linhas', array_column($c['inscricao'], 0) === ['Produto', 'Quantidade', 'Taxa de inscrição', 'Matrícula', 'Juros do parcelamento (acréscimo de 27%)'], json_encode(array_column($c['inscricao'], 0), JSON_UNESCAPED_UNICODE));
confere('comprovante op1 total cobrado', end($c['pagamento']) === ['Total', 'R$ 354,33']);
confere('comprovante método parcelado', $c['pagamento'][1][1] === 'Cartão de crédito · Visa final 1111 · 10 parcelas mensais (1ª de R$ 35,46 e 9 de R$ 35,43)', $c['pagamento'][1][1]);
confere('comprovante condições', count($c['condicoes']) === 2 && str_starts_with($c['condicoes'][1], 'Resumo das condições: Inclui: aulas presenciais de 8 horas na turma de'));
confere('comprovante recebedor', $c['recebedor'] === 'O-CVB Filial Rio de Janeiro Ensino Ltda' && $c['recebedor_cnpj'] === 'CNPJ 67.733.551/0001-35');
$c2 = mcp_comprovante_conteudo($taxa);
confere('comprovante op2', $c2['titulo'] === 'COMPROVANTE DE INSCRIÇÃO' && $c2['subtitulo'] === 'Primeiros Socorros Básico — Taxa de inscrição' && $c2['inscricao'][2] === ['Taxa de inscrição', 'R$ 99,00'] && $c2['condicoes'] === []);
$c3 = mcp_comprovante_conteudo($espera);
confere('comprovante sem turma: data limite', in_array(['Data limite', '06/01/2027'], $c3['inscricao'], true) && str_contains($c3['condicoes'][0], 'Ainda não há turma marcada'));
// pior caso: 12x, Bombeiro Civil, os dois opcionais, nome de 60 caracteres
$pior = ['curso_slug' => 'bombeiro-civil', 'curso_nome' => 'Bombeiro Civil', 'nome' => 'Maria Aparecida dos Santos Albuquerque de Oliveira Figueiredo', 'metodo' => 'cartao', 'bandeira' => 'mastercard', 'ultimos4' => '5454',
    'parcelas' => 12, 'taxa_centavos' => 495, 'divulgacao_centavos' => 1490, 'matricula_centavos' => 95000, 'matricula_preco_centavos' => 95000, 'total_centavos' => 106885, 'total_cobrado_centavos' => 142157, 'juros_centavos' => 35272,
    'taxa_mes_pct' => '4.690', 'cet_ano_pct' => '73.240', 'criado_em' => gmdate('Y-m-d H:i:s', time() - 7200), 'turma_id' => null, 'turma_inicio' => null, 'espera_status' => 'aguardando', 'espera_prazo' => $prazoUtc, 'espera_desde' => $base['pago_em'],
    'escola_acesso' => null] + $paga;
$cp = mcp_comprovante_conteudo($pior);
$fatorQueCabe = null;
$lay = mcp_comprovante_layout($cp); $fatorQueCabe = $lay["fim"] <= McpPdf::ALTURA - 28 ? $lay["fator"] . " em " . $lay["paginas"] . " página(s)" : null;
confere('comprovante pior caso (sem turma, 12x, Bombeiro, opcionais) cabe na folha (1 ou 2 páginas)', $fatorQueCabe !== null, 'fim ' . mcp_comprovante_desenhar($cp, 0.72)[1]);
$cpTurma = mcp_comprovante_conteudo(['turma_id' => $turmaId, 'turma_inicio' => $diaTurma . ' 12:00:00', 'espera_status' => null, 'espera_prazo' => null, 'espera_desde' => null] + $pior);
$fatorTurma = null;
$lay = mcp_comprovante_layout($cpTurma); $fatorTurma = $lay["fim"] <= McpPdf::ALTURA - 28 ? $lay["fator"] . " em " . $lay["paginas"] . " página(s)" : null;
confere('comprovante pior caso com turma cabe', $fatorTurma !== null);
file_put_contents("$saida/50-comprovante-pior-caso.pdf", mcp_comprovante_pdf($pior));
file_put_contents("$saida/51-comprovante-10x.pdf", mcp_comprovante_pdf($cartao10));
file_put_contents("$saida/52-comprovante-op2.pdf", mcp_comprovante_pdf($taxa));
echo "fator pior caso sem turma: $fatorQueCabe · com turma: $fatorTurma\n";

// ---------------------------------------------------------------- chat
$cf = mcp_montar_email_contato_confirmacao(['id' => 7, 'protocolo' => 'CVB-1', 'nome' => 'Ana', 'email' => 'ana@exemplo.org', 'telefone' => '', 'assunto' => 'curso', 'curso_slug' => 'puncao-venosa', 'curso_nome' => 'Punção Venosa', 'mensagem' => 'Oi', 'criado_em' => gmdate('Y-m-d H:i:s')]);
confere('chat: sem "paga antes da aula, na área do aluno"', !tem($cf, 'na área do aluno') || !tem($cf, 'A matrícula você paga'));

foreach ($falhas as $f) {
    fwrite(STDERR, "FALHOU $f\n");
}
// ---------------------------------------------------------------- correções das revisões de 09/10
// Rodapé de quem pagou só a taxa: sem "inclusive a matrícula e os juros do parcelamento".
confere('estorno: só a taxa sem matrícula e juros', !str_contains(mcp_email_texto_estorno(['plano' => 'so_taxa']), 'inclusive a matrícula') && str_contains(mcp_email_texto_estorno(['plano' => 'so_taxa']), 'recebe de volta tudo o que pagou'));
confere('estorno: opção 1 com matrícula e juros', str_contains(mcp_email_texto_estorno(['plano' => 'taxa_e_matricula', 'matricula_centavos' => 18000]), 'inclusive a matrícula e os juros do parcelamento'));
// Bombeiro Civil: a frase literal da escola no Resumo das condições (decisão 12).
$bombeiro = ['curso_slug' => 'bombeiro-civil', 'curso_nome' => 'Bombeiro Civil', 'matricula_centavos' => 95000] + $paga;
confere('resumo: homologação literal da escola', str_contains(mcp_email_resumo_condicoes($bombeiro), 'Não inclui a homologação: somente no final do curso, valor a consultar, a cargo do aluno.'));
// PIX aberto sem turma: o limite de 7 dias da "data não serve".
$passosSem = mcp_email_passos_pix($pixSem, ['x', 'y']);
confere('pix sem turma: 7 dias depois do e-mail com a data', str_contains($passosSem[2][1], 'Avise em até 7 dias depois do e-mail com a data'));
// Versão B, só a taxa: a matrícula não é "paga na área do aluno".
$texto = implode(' ', array_map(static fn(array $m): string => $m['texto'], [mcp_montar_email_aluno_pago(['escola_status' => 'nao_aplicavel', 'escola_acesso' => null] + $pix2 + ['status' => 'pago', 'pago_em' => gmdate('Y-m-d H:i:s')])]));
confere('versão B: sem "paga na área do aluno"', !str_contains($texto, 'A matrícula é paga na área do aluno'));
// Turma aberta: "a sua matrícula já está nela".
confere('turma aberta: concordância', tem(mcp_montar_email_turma_aberta(['espera_status' => 'turma', 'escola_acesso' => $acesso(['turma_primeira_aula' => gmdate('Y-m-d', time() + 20 * 86400)])] + $paga), 'e a sua matrícula já está nela'));

printf("%d testes, %d falhas\n", $ok + count($falhas), count($falhas));
exit($falhas ? 1 : 0);
