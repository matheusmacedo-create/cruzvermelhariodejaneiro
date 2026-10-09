#!/usr/bin/env php
<?php
/**
 * Pagar tudo: testes do backend (compra, planos, turma, parcelas e juros, cobrança, payload e resposta da escola, datas
 * da espera, visão pública e os cenários de chave), funções puras, sem banco e sem rede. Spec 6.1 e 10.13.
 * Chamado pelo scripts/testar_checkout.php; também roda sozinho.
 * Uso: php scripts/testar_pagar_tudo.php [raiz do repositório]
 * Config descartável em arquivo temporário; CPFs fictícios com dígito válido.
 */
declare(strict_types=1);

$raiz = $argv[1] ?? dirname(__DIR__);
if (!is_file("$raiz/site/matricula-cursos-presenciais/api/lib.php")) {
    fwrite(STDERR, "uso: php scripts/testar_pagar_tudo.php [raiz do repositório]\n");
    exit(2);
}
$tmp = sys_get_temp_dir() . '/mcp-pt-' . getmypid();
@mkdir($tmp);
const CPF_TESTE = '52998224725';
const CPF_OUTRO = '11144477735';

function escrever_config(string $tmp, array $extra): void
{
    $base = ['SITE_URL' => 'https://exemplo.org', 'INSCRICAO_CENTAVOS' => '', 'PRECO_TESTE_CENTAVOS' => '',
        'TAXA_PIX_PCT' => 5.0, 'TAXA_PIX_FIXA' => 0, 'TAXA_CARTAO_PCT' => 5.0, 'TAXA_CARTAO_FIXA' => 0,
        'ESCOLA_URL' => 'https://escola.exemplo.org', 'EMAIL_CONTATO' => 'contato@exemplo.org',
        'DB_HOST' => '127.0.0.1', 'DB_PORT' => 9, 'DB_NAME' => 'nenhum', 'UNICO_BASE_URL' => 'https://127.0.0.1:9'];
    file_put_contents("$tmp/config.php", '<?php return ' . var_export($extra + $base, true) . ';');
}
escrever_config($tmp, ['PLANO_COMPLETO' => true, 'PARCELAS_MAX' => 12, 'PARCELA_MINIMA_CENTAVOS' => 500,
    'PRECO_TESTE_MATRICULA_CENTAVOS' => 0, 'TESTE_CPFS' => [CPF_TESTE]]);
file_put_contents("$tmp/config-escola.php", "<?php return ['ESCOLA_API_URL' => 'https://escola-db.exemplo.org/rest/v1/rpc/matricula_rapida', 'ESCOLA_API_TOKEN' => 'chave-de-teste', 'ESCOLA_MATRICULA_PAGA' => true];");
$agora = time();
$fmt = static fn(int $ts): string => (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d\TH:i:sP');
$oferta = ['parcelado_no_ar' => false, 'turmas' => [[
    'curso' => 'primeiros-socorros-basico', 'id_escola' => 'turma-teste-1',
    'inicio' => $fmt($agora + 10 * 86400), 'fim' => $fmt($agora + 10 * 86400 + 8 * 3600),
    'inscricoes_ate' => $fmt($agora + 9 * 86400), 'lotada' => false,
]], 'sem_turma' => ['cuidador-de-idosos' => ['max_fila' => 30]]];
file_put_contents("$tmp/oferta.json", json_encode($oferta));
putenv("MCP_CONFIG_ARQUIVO=$tmp/config.php");
putenv("MCP_CONFIG_ESCOLA_ARQUIVO=$tmp/config-escola.php");
putenv("MCP_CONFIG_META_ARQUIVO=$tmp/nao-existe.php");
putenv("MCP_OFERTA_ARQUIVO=$tmp/oferta.json");
putenv('MCP_CATALOGO_ARQUIVO=' . $raiz . '/site/matricula-cursos-presenciais/cursos.json');
putenv('MCP_ESCOLA_VERSAO=3');
ini_set('error_log', getenv('TESTE_LOG') ?: "$tmp/erros.log");
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $raiz . '/site/matricula-cursos-presenciais/api/lib.php';
restore_exception_handler();

$falhas = 0;
$total = 0;
function v(string $nome, mixed $obtido, mixed $esperado): void
{
    global $falhas, $total;
    $total++;
    if ($obtido === $esperado) {
        return;
    }
    $falhas++;
    fwrite(STDERR, sprintf("FALHOU %s\n  esperado: %s\n  obtido:   %s\n", $nome, var_export($esperado, true), var_export($obtido, true)));
}
/** Troca a config sem recarregar o processo (mcp_config guarda em static): reescreve e relê por reflexão simples. */
function recarregar_config(): void
{
    // mcp_config() guarda num static; o jeito limpo é rodar cada cenário de chave num processo próprio (abaixo).
}

$psb = mcp_curso('primeiros-socorros-basico');
$bombeiro = mcp_curso('bombeiro-civil');
$cuidador = mcp_curso('cuidador-de-idosos');

// --- mcp_compra (2.2) e D11
$c = static fn(string $plano, string $metodo, bool $cobre, bool $div, int $n = 1, ?string $cpf = CPF_OUTRO): array => mcp_compra($psb, $plano, $metodo, $cobre, $div ? 1490 : 0, $n, $cpf);
v('compra 279', $c('taxa_e_matricula', 'pix', false, false)['amount'], 27900);
v('compra 283,95', $c('taxa_e_matricula', 'pix', true, false)['amount'], 28395);
v('compra 293,90', $c('taxa_e_matricula', 'cartao', false, true)['amount'], 29390);
v('compra 298,85', $c('taxa_e_matricula', 'cartao', true, true)['amount'], 29885);
v('compra 99', $c('so_taxa', 'pix', false, false)['amount'], 9900);
v('compra 118,85', $c('so_taxa', 'cartao', true, true)['amount'], 11885);
v('D11 custos 495 na opção 1', $c('taxa_e_matricula', 'cartao', true, false)['custos'], 495);
v('D11 custos 495 na opção 2', $c('so_taxa', 'pix', true, false)['custos'], 495);
v('parcelas só no cartão da opção 1', $c('taxa_e_matricula', 'pix', false, false, 10)['parcelas'], 1);
v('parcelas na opção 2 viram 1', $c('so_taxa', 'cartao', false, false, 10)['parcelas'], 1);
v('parcelas 10 no cartão', $c('taxa_e_matricula', 'cartao', false, false, 10)['parcelas'], 10);
foreach (mcp_catalogo()['cursos'] as $curso) {
    foreach (['taxa_e_matricula', 'so_taxa'] as $plano) {
        foreach (['pix', 'cartao'] as $metodo) {
            foreach ([[false, 0], [true, 0], [false, 1490], [true, 1490]] as [$cobre, $div]) {
                $x = mcp_compra($curso, $plano, $metodo, $cobre, $div, 1, CPF_OUTRO);
                v("soma {$curso['slug']} $plano $metodo", $x['amount'], $x['inscricao'] + $x['matricula'] + $x['custos'] + $x['divulgacao']);
            }
        }
    }
}

// --- modo de teste (E16, T1): sem PRECO_TESTE_CENTAVOS aqui; os cenários com ele rodam em processo próprio abaixo.
v('matrícula do CPF de teste com PRECO_TESTE_MATRICULA_CENTAVOS = 0', mcp_matricula_centavos($psb, CPF_TESTE), 0);
v('matrícula de outro CPF', mcp_matricula_centavos($psb, CPF_OUTRO), 18000);
v('matrícula sem CPF', mcp_matricula_centavos($psb), 18000);
v('planos do CPF de teste com matrícula 0', mcp_planos_info($psb, null, CPF_TESTE)['motivo'], 'sem_preco');
v('mcp_cfg_ligada estrita', mcp_cfg_ligada('PLANO_COMPLETO'), true);

// --- turma e rótulos (2.5)
$t = mcp_turma_aberta('primeiros-socorros-basico');
v('turma aberta', $t['id_escola'] ?? null, 'turma-teste-1');
v('turma: dias', $t['dias_para_inicio'] ?? null, 10);
v('turma: sem turma em bombeiro', mcp_turma_aberta('bombeiro-civil'), null);
$rot = mcp_turma_rotulos(['id_escola' => 'x', 'inicio' => '2026-10-21T09:00:00-03:00', 'fim' => '2026-10-21T17:00:00-03:00', 'inscricoes_ate' => '2026-10-20T23:59:59-03:00', 'lotada' => false], strtotime('2026-10-08T12:00:00Z'));
v('rótulo horário', $rot['horario'], '09:00 - 17:00');
v('rótulo data longa', $rot['data_longa'], '21 de outubro de 2026');
v('rótulo dia', $rot['dia'], '21/10');
v('rótulo bloco', [$rot['bloco_dia'], $rot['bloco_mes']], ['21', 'outubro']);
v('rótulo lead', $rot['lead'], '09:00 - 17:00 · início 21/10/2026');
v('rótulo prazo', $rot['prazo'], 'terça, 20/10, às 23h59');
v('rótulo dias até 21/10', $rot['dias_para_inicio'], 13);
v('estado da turma: aberta', mcp_turma_estado('primeiros-socorros-basico'), 'aberta');
v('estado da turma: prazo vencido', mcp_turma_estado('primeiros-socorros-basico', $agora + 9 * 86400 + 60), 'prazo');
v('estado sem turma', mcp_turma_estado('bombeiro-civil'), null);

// --- planos (2.1, 10.8), com tudo ligado e a escola na versão 3
$pi = mcp_planos_info($psb);
v('PSB com turma: os dois', $pi['planos'], ['taxa_e_matricula', 'so_taxa']);
v('PSB com turma: motivo null', $pi['motivo'], null);
v('PSB com turma: sem_turma false', $pi['sem_turma'], false);
$pi = mcp_planos_info($psb, $agora + 9 * 86400 + 60);
v('PSB depois do prazo (sem turma desligada): só a taxa', $pi['planos'], ['so_taxa']);
v('PSB depois do prazo: motivo prazo', $pi['motivo'], 'prazo');
v('mensagem de prazo', mcp_plano_mensagem('prazo'), 'As inscrições desta turma fecharam. Você ainda pode pagar só a taxa de inscrição e entrar na lista da próxima turma.');
v('mensagem desligado', mcp_plano_mensagem('desligado'), 'No momento, a matrícula não pode ser paga junto. Você pode pagar só a taxa de inscrição.');
v('bombeiro sem turma, venda sem turma desligada', mcp_planos_info($bombeiro)['motivo'], 'sem_turma_desligado');
v('mcp_planos_do_curso + motivo', [mcp_planos_do_curso($bombeiro), mcp_plano_motivo()], [['so_taxa'], 'sem_turma_desligado']);

// --- parcelas (2.4)
v('TIR R$ 10 em 2x (5,15 + 5,15)', round(mcp_taxa_mensal(1000, 515, 515, 2), 2), 1.99);
$o10 = mcp_parcela_opcao(27900, 10, 35433);
v('10x: parcela', $o10['parcela_centavos'], 3543);
v('10x: 1ª parcela', $o10['primeira_centavos'], 3546);
v('10x: juros', $o10['juros_centavos'], 7533);
v('10x: acréscimo 27%', $o10['acrescimo_rotulo'], '27%');
v('10x: taxa ao mês 4,60', $o10['taxa_mes_pct'], 4.6);
v('10x: CET 71,55', $o10['cet_ano_pct'], 71.55);
v('10x: rótulos', [$o10['taxa_mes_rotulo'], $o10['cet_ano_rotulo']], ['4,60%', '71,55%']);
// A tabela da 2.4 (regra ilustrativa de 3% por parcela além da 1ª), R$ 279,00.
$tabela = [2 => [14369, 14368, 28737, 1.99, 26.73], 3 => [9858, 9858, 29574, 2.97, 42.10], 4 => [7605, 7602, 30411, 3.54, 51.79],
    5 => [6252, 6249, 31248, 3.90, 58.28], 6 => [5350, 5347, 32085, 4.15, 62.82], 7 => [4704, 4703, 32922, 4.32, 66.07],
    8 => [4226, 4219, 33759, 4.44, 68.48], 9 => [3844, 3844, 34596, 4.53, 70.23], 10 => [3546, 3543, 35433, 4.60, 71.55],
    11 => [3300, 3297, 36270, 4.65, 72.53], 12 => [3095, 3092, 37107, 4.69, 73.24]];
foreach ($tabela as $n => [$p1, $p, $tot, $i, $a]) {
    $o = mcp_parcela_opcao(27900, $n, (int) round(27900 * (1 + 0.03 * ($n - 1))));
    v("tabela {$n}x total", $o['total_centavos'], $tot);
    v("tabela {$n}x parcelas", [$o['primeira_centavos'], $o['parcela_centavos']], [$p1, $p]);
    v("tabela {$n}x soma das parcelas = total (F7)", $o['primeira_centavos'] + ($n - 1) * $o['parcela_centavos'], $o['total_centavos']);
    v("tabela {$n}x taxa e CET", [$o['taxa_mes_pct'], $o['cet_ano_pct']], [$i, $a]);
}
v('acréscimo 2,21%', mcp_pct_rotulo(2.21, true), '2,21%');
v('ler: formato real', mcp_parcelas_ler(['data' => [['installments' => 1, 'installment_rate' => 0, 'installment_amount' => 1000, 'total_amount' => 1000], ['installments' => 2, 'installment_rate' => 6, 'installment_amount' => 530, 'total_amount' => 1060]]]),
    [['n' => 1, 'total' => 1000], ['n' => 2, 'total' => 1060]]);
v('ler: formato da doc com fração', mcp_parcelas_ler(['installments' => [['installment' => 3, 'amount' => 353.33, 'total' => 1060, 'interest_rate' => 6]]]), [['n' => 3, 'total' => 1060]]);
v('ler: só a parcela com fração', mcp_parcelas_ler(['installments' => [['installment' => 3, 'amount' => 353.33]]]), [['n' => 3, 'total' => 1060]]);
v('ler: formato desconhecido', mcp_parcelas_ler(['opcoes' => []]), null);
v('ler: lista vazia', mcp_parcelas_ler(['data' => []]), null);
$lista = [];
for ($n = 1; $n <= 12; $n++) {
    $lista[] = ['n' => $n, 'total' => (int) round(27900 * (1 + 0.03 * ($n - 1)))];
}
$f = mcp_parcelas_filtrar(27900, $lista);
v('filtro: 12 opções, 1x primeiro', [count($f), $f[0]['n'], $f[11]['n']], [12, 1, 12]);
v('filtro: 1x é o amount', $f[0]['total_centavos'], 27900);
$sem = $lista;
$sem[3]['total'] = 27900;
v('filtro: total ≤ amount em n ≥ 2 = só o 1x (E2)', [count(mcp_parcelas_filtrar(27900, $sem)), mcp_parcelas_motivo()], [1, 'sem_juros']);
v('filtro: falha = só o 1x', [count(mcp_parcelas_filtrar(27900, null)), mcp_parcelas_motivo()], [1, 'falha']);
$pequeno = [];
for ($n = 1; $n <= 12; $n++) {
    $pequeno[] = ['n' => $n, 'total' => (int) round(2000 * (1 + 0.03 * ($n - 1)))];
}
v('filtro: parcela abaixo de R$ 5 sai (R$ 20: até 4x)', array_column(mcp_parcelas_filtrar(2000, $pequeno), 'n'), [1, 2, 3, 4]);
v('validade 48 h', mcp_parcela_opcao(1000, 1, 1000, 1_000_000)['valido_ate'], gmdate('Y-m-d\TH:i:s\Z', 1_000_000 + 48 * 3600));

// --- cobrança (3.2)
$aluno = ['slug' => 'primeiros-socorros-basico', 'curso' => $psb, 'nome' => 'Fulana de Teste', 'email' => 'fulana@exemplo.org', 'telefone' => '21999990000',
    'cpf' => CPF_OUTRO, 'metodo' => 'cartao', 'cobre_taxa' => true, 'utm_source' => null, 'utm_campaign' => null];
$compra = mcp_compra($psb, 'taxa_e_matricula', 'cartao', true, 1490, 10, CPF_OUTRO);
$pay = mcp_montar_cobranca($aluno, str_repeat('a', 40), $compra + ['turma_id' => 'turma-teste-1', 'total_mostrado' => 39000]);
v('cobrança: soma dos price = amount', array_sum(array_column($pay['cart'], 'price')), $pay['amount']);
v('cobrança: amount = compra', $pay['amount'], 29885);
v('cobrança: itens', array_column($pay['cart'], 'hash'), ['inscricao-primeiros-socorros-basico', 'matricula-primeiros-socorros-basico', 'custos-processamento', 'divulgacao']);
v('cobrança: títulos', array_column($pay['cart'], 'title')[0] . ' / ' . array_column($pay['cart'], 'title')[1], 'Primeiros Socorros Básico — Taxa de inscrição / Primeiros Socorros Básico — Matrícula');
v('cobrança: operation_type 1', array_unique(array_column($pay['cart'], 'operation_type')), [1]);
v('cobrança: installments 10', $pay['installments'], 10);
v('cobrança: metadata', [$pay['metadata']['plano'], $pay['metadata']['parcelas'], $pay['metadata']['matricula_centavos'], $pay['metadata']['turma_id']], ['taxa_e_matricula', 10, 18000, 'turma-teste-1']);
$pix = mcp_montar_cobranca(['metodo' => 'pix'] + $aluno, str_repeat('a', 40), mcp_compra($psb, 'so_taxa', 'pix', false, 0, 1, CPF_OUTRO));
v('cobrança opção 2: um item', array_column($pix['cart'], 'hash'), ['inscricao-primeiros-socorros-basico']);
v('cobrança PIX: installments 1', [$pix['installments'], $pix['payment_method']], [1, 'pix']);

// --- payload da escola (3.2)
$base = ['id' => 7, 'curso_slug' => 'primeiros-socorros-basico', 'nome' => 'Fulana de Teste', 'cpf' => CPF_OUTRO, 'email' => 'fulana@exemplo.org',
    'telefone' => '21999990000', 'unicopag_hash' => 'h123', 'metodo' => 'cartao', 'inscricao_centavos' => 9900, 'total_centavos' => 27900,
    'pago_em' => '2026-10-08 12:00:00', 'escola_token' => str_repeat('b', 64)];
$hoje = ['nome', 'cpf', 'email', 'celular', 'curso_id', 'transacao', 'metodo', 'valor_centavos', 'total_centavos', 'pago_em', 'referencia', 'senha_hash', 'token_hash'];
v('payload opção 2: as chaves de hoje', array_keys(mcp_escola_payload($base + ['plano' => 'so_taxa', 'total_centavos' => 9900], str_repeat('b', 64))['dados']), $hoje);
v('payload inscrição antiga (sem plano): as chaves de hoje', array_keys(mcp_escola_payload($base, str_repeat('b', 64))['dados']), $hoje);
$d1 = mcp_escola_payload($base + ['plano' => 'taxa_e_matricula', 'matricula_centavos' => 18000, 'parcelas' => 1, 'turma_id' => 'turma-teste-1', 'total_cobrado_centavos' => 27900], str_repeat('b', 64))['dados'];
v('payload opção 1, 1x', [$d1['matricula_centavos'], $d1['parcelas'], $d1['juros_centavos'], $d1['turma_id'], $d1['total_centavos']], [18000, 1, 0, 'turma-teste-1', 27900]);
$d10 = mcp_escola_payload($base + ['plano' => 'taxa_e_matricula', 'matricula_centavos' => 18000, 'parcelas' => 10, 'turma_id' => 'turma-teste-1', 'total_cobrado_centavos' => 35433], str_repeat('b', 64))['dados'];
v('payload opção 1, 10x: juros 7533 e total 35433', [$d10['juros_centavos'], $d10['total_centavos']], [7533, 35433]);
$dm = mcp_escola_payload($base + ['plano' => 'taxa_e_matricula', 'matricula_centavos' => 18000, 'parcelas' => 10, 'turma_id' => 't', 'total_cobrado_centavos' => 27000], str_repeat('b', 64))['dados'];
v('payload: cobrado menor que o amount vai o amount, juros 0 (T18)', [$dm['total_centavos'], $dm['juros_centavos']], [27900, 0]);
$de = mcp_escola_payload($base + ['plano' => 'taxa_e_matricula', 'matricula_centavos' => 18000, 'parcelas' => 1, 'turma_id' => 'turma-vendida', 'espera_status' => 'aguardando'], str_repeat('b', 64))['dados'];
v('payload da espera: sem turma_id', array_key_exists('turma_id', $de), false);
v('payload PIX: parcelas 1 mesmo gravado errado', mcp_escola_payload(['metodo' => 'pix'] + $base + ['plano' => 'taxa_e_matricula', 'matricula_centavos' => 18000, 'parcelas' => 3], str_repeat('b', 64))['dados']['parcelas'], 1);

// --- resposta da escola (3.2)
$r = static fn(array $d, bool $completo = true): array => mcp_escola_acesso_da_resposta($d + ['ok' => true, 'resultado' => 'matriculado'], $completo);
v('resposta sem matricula_paga: matricula_nao_marcada', $r([])['avisos'], ['matricula_nao_marcada']);
v('resposta sem a chave: matricula_paga null', $r([])['matricula_paga'], null);
v('resposta matricula_paga false repetido', $r(['matricula_paga' => false, 'repetido' => true])['avisos'], ['matricula_nao_marcada']);
v('resposta curso_ja_pago: sem matricula_nao_marcada', $r(['matricula_paga' => false, 'avisos' => ['curso_ja_pago']])['avisos'], ['curso_ja_pago']);
v('resposta sem turma: sem matricula_nao_marcada', $r(['matricula_paga' => false, 'resultado' => 'sem_turma', 'avisos' => ['matricula_paga_sem_turma']])['avisos'], ['matricula_paga_sem_turma']);
v('resposta paga com aviso desconhecido descartado', $r(['matricula_paga' => true, 'avisos' => ['turma_lotada', 'xyz']])['avisos'], ['turma_lotada']);
v('resposta só a taxa: sem derivação', $r([], false)['avisos'], []);
v('resposta: turma com 1ª aula, horário e status', array_intersect_key($r(['matricula_paga' => true, 'turma' => ['id' => 't1', 'inicio' => '2026-10-21', 'primeira_aula' => '2026-10-21', 'horario' => '09:00 - 17:00', 'status' => 'ABERTA']]),
    array_flip(['turma_id', 'turma_primeira_aula', 'turma_horario', 'turma_status'])), ['turma_id' => 't1', 'turma_primeira_aula' => '2026-10-21', 'turma_horario' => '09:00 - 17:00', 'turma_status' => 'ABERTA']);

// --- resumo à secretaria (3.2) e urgência (1.14)
$ins = $base + ['plano' => 'taxa_e_matricula', 'matricula_centavos' => 18000, 'parcelas' => 1, 'total_cobrado_centavos' => 27900, 'status' => 'pago', 'escola_status' => 'ok', 'escola_tentativas' => 1, 'turma_id' => 'turma-teste-1'];
$com = static fn(array $acesso): array => $ins + ['escola_acesso' => json_encode($acesso + ['resultado' => 'matriculado', 'aluno_novo' => true])];
v('resumo pago', mcp_escola_resumo($com(['matricula_paga' => true, 'avisos' => [], 'turma_primeira_aula' => '2026-10-21'])), 'matriculado na turma que começa em 21/10/2026, conta criada pelo site, inscrição e matrícula pagas no site');
v('resumo curso_ja_pago', mcp_escola_resumo($com(['matricula_paga' => false, 'avisos' => ['curso_ja_pago']])), 'MATRÍCULA JÁ ESTAVA PAGA na escola: estornar esta compra inteira no painel da Unicopag');
v('resumo não marcado', str_starts_with(mcp_escola_resumo($com(['matricula_paga' => null, 'avisos' => ['matricula_nao_marcada']])), 'PAGOU TAXA + MATRÍCULA, NÃO MARCADO COMO PAGO NA ESCOLA: matricular à mão como À vista, PAGO, Pagamento CURSO de R$ 279,00'), true);
v('resumo lotada', str_contains(mcp_escola_resumo($com(['matricula_paga' => true, 'avisos' => ['turma_lotada']])), '. ATENÇÃO: entrou acima da vaga (turma marcada como lotada depois do pagamento): ajuste as vagas da turma na escola'), true);
v('resumo taxa em dobro com juros (12x)', str_contains(mcp_escola_resumo(array_replace($com(['matricula_paga' => true, 'avisos' => ['taxa_em_dobro']]), ['total_cobrado_centavos' => 37107, 'parcelas' => 12])), 'devolver por PIX R$ 131,67'), true);
v('resumo espera', str_starts_with(mcp_escola_resumo(['espera_status' => 'aguardando'] + $com(['resultado' => 'sem_turma'])), 'PAGOU TUDO, ESPERA TURMA'), true);
v('urgente: não marcado', mcp_escola_urgente($com(['matricula_paga' => null, 'avisos' => ['matricula_nao_marcada']])), true);
v('urgente: pago normal não', mcp_escola_urgente($com(['matricula_paga' => true, 'avisos' => []])), false);
v('urgente: sem turma na fila não', mcp_escola_urgente(['espera_status' => 'aguardando'] + $com(['matricula_paga' => false, 'avisos' => ['matricula_paga_sem_turma']])), false);
v('urgente: sem turma fora da fila sim', mcp_escola_urgente($com(['matricula_paga' => false, 'avisos' => ['matricula_paga_sem_turma']])), true);
v('urgente: diferença a devolver', mcp_escola_urgente(['diferenca_devolver_centavos' => 300] + $com(['matricula_paga' => true, 'avisos' => []])), true);
v('matricula_paga true', mcp_escola_matricula_paga($com(['matricula_paga' => true])), true);
v('matricula_paga ausente', mcp_escola_matricula_paga($com([])), null);

// --- datas da espera (10.3, 10.8)
$criado = strtotime('2026-10-08T15:00:00Z');
v('espera_prazo: dia + 90, 23h59min59s de Brasília', mcp_espera_prazo($criado), '2027-01-07 02:59:59');
v('data limite', mcp_espera_data_limite(mcp_espera_prazo($criado)), '06/01/2027');
v('inicio_ate', mcp_espera_inicio_ate(mcp_espera_prazo($criado)), '2027-01-06');
v('marcar até = data limite − 10', mcp_espera_marcar_ate(mcp_espera_prazo($criado)), '27/12/2026');
v('virada de ano: 23h30 de 31/12 em Brasília', mcp_espera_data_limite(mcp_espera_prazo(strtotime('2027-01-01T02:30:00Z'))), '31/03/2027');
v('virada de mês', mcp_espera_data_limite(mcp_espera_prazo(strtotime('2026-11-30T12:00:00Z'))), '28/02/2027');
v('janela da data: véspera vence antes de 7 dias', mcp_espera_janela(['turma_primeira_aula' => '2026-10-12'], strtotime('2026-10-08T12:00:00Z')), '2026-10-12 02:59:59');
v('janela da data: 7 dias', mcp_espera_janela(['turma_primeira_aula' => '2026-11-30'], strtotime('2026-10-08T12:00:00Z')), '2026-10-15 12:00:00');
v('início da turma pela 1ª aula e horário', mcp_espera_turma_inicio(['turma_primeira_aula' => '2026-11-03', 'turma_horario' => '18:00 - 22:00']), '2026-11-03 21:00:00');
v('dados da turma ida e volta', mcp_espera_turma_dados_ler(mcp_espera_turma_dados(['turma_id' => 't9', 'turma_primeira_aula' => '2026-11-03', 'turma_horario' => '18:00 - 22:00', 'turma_status' => 'ABERTA', 'matricula_status' => 'PAGO'])),
    ['turma_id' => 't9', 'primeira_aula' => '2026-11-03', 'horario' => '18:00 - 22:00', 'turma_status' => 'ABERTA', 'matricula_status' => 'PAGO']);

// --- visão pública da compra
$pub = mcp_publico_compra(array_replace($com(['matricula_paga' => true, 'avisos' => ['turma_lotada'], 'turma_id' => 'turma-teste-1', 'turma_primeira_aula' => substr($oferta['turmas'][0]['inicio'], 0, 10)]), ['parcelas' => 10, 'total_cobrado_centavos' => 35433, 'juros_centavos' => 7533]));
v('público: parcelas', [$pub['parcelas'], $pub['parcela_centavos'], $pub['primeira_parcela_centavos'], $pub['total_cobrado_centavos']], [10, 3543, 3546, 35433]);
v('público: avisos', $pub['escola']['avisos'], ['turma_lotada']);
v('público: turma da escola com o horário do oferta.json', [$pub['turma']['origem'] ?? null, $pub['turma']['horario'] ?? null], ['escola', mcp_oferta_turma('turma-teste-1')['horario']]);
v('público: sem CPF', str_contains(json_encode($pub), CPF_OUTRO), false);
v('público: espera nula', $pub['espera'], null);
v('público: PIX com turma fechada', mcp_publico_compra(['status' => 'pendente', 'metodo' => 'pix', 'plano' => 'taxa_e_matricula', 'turma_id' => 'outra', 'total_centavos' => 27900, 'inscricao_centavos' => 9900])['pix_turma_fechada'], true);
v('público: PIX com turma aberta', mcp_pix_turma_fechada(['status' => 'pendente', 'metodo' => 'pix', 'plano' => 'taxa_e_matricula', 'turma_id' => 'turma-teste-1']), false);
v('recebedor (decisão 6)', mcp_recebedor(), ['nome' => 'O-CVB Filial Rio de Janeiro Ensino Ltda', 'cnpj' => '67.733.551/0001-35']);
v('agente financiador = recebedor', mcp_agente_financiador(), mcp_recebedor());
v('oferta.json do repositório vale', (static function () use ($raiz): bool {
    $o = json_decode((string) file_get_contents("$raiz/site/matricula-cursos-presenciais/oferta.json"), true);
    foreach ($o['turmas'] as $t) {
        if (count(array_intersect(array_keys($t), ['curso', 'id_escola', 'inicio', 'fim', 'inscricoes_ate', 'lotada'])) !== 6 || strtotime($t['inscricoes_ate']) >= strtotime($t['inicio'])) {
            return false;
        }
    }
    return $o['parcelado_no_ar'] === false && count($o['sem_turma']) === 7;
})(), true);

// --- cenários de chave em processo próprio (mcp_config guarda num static)
$cenarios = [
    'desligado padrão' => [[], 'primeiros-socorros-basico', null, 3, 'desligado'],
    'PLANO_COMPLETO string false' => [['PLANO_COMPLETO' => 'false'], 'primeiros-socorros-basico', null, 3, 'desligado'],
    'ESCOLA_MATRICULA_PAGA = 1' => [['PLANO_COMPLETO' => true, '__escola' => ['ESCOLA_MATRICULA_PAGA' => 1]], 'primeiros-socorros-basico', null, 3, 'desligado'],
    'escola sem URL' => [['PLANO_COMPLETO' => true, '__escola' => ['ESCOLA_MATRICULA_PAGA' => true, 'ESCOLA_API_URL' => '']], 'primeiros-socorros-basico', null, 3, 'desligado'],
    'versão 0' => [['PLANO_COMPLETO' => true], 'primeiros-socorros-basico', null, 0, 'desligado'],
    'versão 1' => [['PLANO_COMPLETO' => true], 'primeiros-socorros-basico', null, 1, 'desligado'],
    'versão 2 com turma' => [['PLANO_COMPLETO' => true], 'primeiros-socorros-basico', null, 2, null],
    'lotada' => [['PLANO_COMPLETO' => true, '__lotada' => true], 'primeiros-socorros-basico', null, 3, 'lotada'],
    'sem turma ligado, versão 2' => [['PLANO_COMPLETO' => true, 'PLANO_COMPLETO_SEM_TURMA' => true], 'cuidador-de-idosos', null, 2, 'desligado'],
    'sem turma ligado, fora da lista' => [['PLANO_COMPLETO' => true, 'PLANO_COMPLETO_SEM_TURMA' => true], 'bombeiro-civil', null, 3, 'sem_turma_fora_da_lista'],
    'sem turma string true' => [['PLANO_COMPLETO' => true, 'PLANO_COMPLETO_SEM_TURMA' => 'true'], 'cuidador-de-idosos', null, 3, 'sem_turma_desligado'],
    'teste: CPF da lista paga 100 de taxa' => [['PLANO_COMPLETO' => true, 'PRECO_TESTE_CENTAVOS' => 100, 'PRECO_TESTE_MATRICULA_CENTAVOS' => 100, 'TESTE_CPFS' => [CPF_TESTE]], 'primeiros-socorros-basico', CPF_TESTE, 3, '#compra:200'],
    'teste: outro CPF paga o preço real' => [['PLANO_COMPLETO' => true, 'PRECO_TESTE_CENTAVOS' => 100, 'PRECO_TESTE_MATRICULA_CENTAVOS' => 100, 'TESTE_CPFS' => [CPF_TESTE]], 'primeiros-socorros-basico', CPF_OUTRO, 3, '#compra:27900'],
    'teste: info.php sem CPF mostra o real' => [['PLANO_COMPLETO' => true, 'PRECO_TESTE_CENTAVOS' => 100, 'TESTE_CPFS' => [CPF_TESTE]], 'primeiros-socorros-basico', null, 3, '#compra:27900'],
    // Revisão de regressões (09/10): nos testes reais, PLANO_COMPLETO_SO_TESTE deixa a opção 1 só para os CPFs da lista.
    'só teste: outro CPF não paga a opção 1' => [['PLANO_COMPLETO' => true, 'PLANO_COMPLETO_SO_TESTE' => true, 'PRECO_TESTE_MATRICULA_CENTAVOS' => 100, 'TESTE_CPFS' => [CPF_TESTE]], 'primeiros-socorros-basico', CPF_OUTRO, 3, 'desligado'],
    'só teste: CPF da lista paga' => [['PLANO_COMPLETO' => true, 'PLANO_COMPLETO_SO_TESTE' => true, 'PRECO_TESTE_MATRICULA_CENTAVOS' => 100, 'TESTE_CPFS' => [CPF_TESTE]], 'primeiros-socorros-basico', CPF_TESTE, 3, null],
    'só teste: info.php sem CPF continua mostrando' => [['PLANO_COMPLETO' => true, 'PLANO_COMPLETO_SO_TESTE' => true, 'TESTE_CPFS' => [CPF_TESTE]], 'primeiros-socorros-basico', null, 3, null],
    'só teste string true não liga' => [['PLANO_COMPLETO' => true, 'PLANO_COMPLETO_SO_TESTE' => 'true', 'TESTE_CPFS' => [CPF_TESTE]], 'primeiros-socorros-basico', CPF_OUTRO, 3, null],
];
$filho = <<<'PHP'
<?php
[$raiz, $cfg, $slug, $cpf, $saida] = [$argv[1], json_decode($argv[2], true), $argv[3], $argv[4] === '' ? null : $argv[4], $argv[5]];
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $raiz . '/site/matricula-cursos-presenciais/api/lib.php';
restore_exception_handler();
$curso = mcp_curso($slug);
$info = mcp_planos_info($curso, null, $cpf);
$compra = mcp_compra($curso, 'taxa_e_matricula', 'pix', false, 0, 1, $cpf);
file_put_contents($saida, json_encode(['motivo' => $info['motivo'], 'planos' => $info['planos'], 'amount' => $compra['amount']]));
PHP;
file_put_contents("$tmp/filho.php", $filho);
foreach ($cenarios as $nome => [$cfg, $slug, $cpf, $versao, $esperado]) {
    $escola = array_replace(['ESCOLA_API_URL' => 'https://escola-db.exemplo.org/rest/v1/rpc/matricula_rapida', 'ESCOLA_API_TOKEN' => 'chave-de-teste'], $cfg['__escola'] ?? ['ESCOLA_MATRICULA_PAGA' => true]);
    $of = $oferta;
    if (!empty($cfg['__lotada'])) {
        $of['turmas'][0]['lotada'] = true;
    }
    unset($cfg['__escola'], $cfg['__lotada']);
    $dir = "$tmp/c" . md5($nome);
    @mkdir($dir);
    escrever_config($dir, $cfg);
    file_put_contents("$dir/config-escola.php", '<?php return ' . var_export($escola, true) . ';');
    file_put_contents("$dir/oferta.json", json_encode($of));
    $env = "MCP_CONFIG_ARQUIVO=$dir/config.php MCP_CONFIG_ESCOLA_ARQUIVO=$dir/config-escola.php MCP_CONFIG_META_ARQUIVO=$dir/x MCP_OFERTA_ARQUIVO=$dir/oferta.json MCP_ESCOLA_VERSAO=$versao MCP_CATALOGO_ARQUIVO=$raiz/site/matricula-cursos-presenciais/cursos.json";
    exec("env $env php -d error_log=$tmp/erros.log $tmp/filho.php " . escapeshellarg($raiz) . ' ' . escapeshellarg(json_encode($cfg)) . ' ' . escapeshellarg($slug) . ' ' . escapeshellarg((string) $cpf) . " $dir/saida.json 2>&1", $out, $rc);
    $res = json_decode((string) @file_get_contents("$dir/saida.json"), true) ?? [];
    if (is_string($esperado) && str_starts_with($esperado, '#compra:')) {
        v("chave: $nome", $res['amount'] ?? null, (int) substr($esperado, 8));
    } else {
        v("chave: $nome", array_key_exists('motivo', $res) ? $res['motivo'] : 'ERRO ' . implode(' ', $out), $esperado);
    }
}

// ---------------------------------------------------------------- correções das revisões de 09/10
// Mensagens do 422 de plano: a turma vendida fechou num curso da venda sem turma (a opção 1 continua) e condições mudadas.
v('422 prazo_fila: oferece pagar tudo na fila, sem empurrar só a taxa', mcp_plano_mensagem('prazo_fila', ['data' => '21/10/2026']),
    'As inscrições da turma de 21/10/2026 fecharam. Você ainda pode pagar tudo agora e entrar na fila da próxima turma deste curso, com a data por e-mail, ou pagar só a taxa de inscrição. Confira as condições e pague de novo.');
v('422 condicoes', mcp_plano_mensagem('condicoes'), 'As condições deste curso mudaram. Confira e pague de novo.');
v('422 prazo: o texto de antes (sem venda sem turma)', str_starts_with(mcp_plano_mensagem('prazo'), 'As inscrições desta turma fecharam. Você ainda pode pagar só a taxa'), true);
// Aceite canônico (F12): o mesmo texto do checkout.js, por plano.
$psb = mcp_curso('primeiros-socorros-basico');
$cui = mcp_curso('cuidador-de-idosos');
v('aceite canônico: opção 1 com turma', mcp_aceite_texto($psb, 'taxa_e_matricula', false), 'Tenho a escolaridade mínima do curso (' . $psb['escolaridade'] . ').');
v('aceite canônico: opção 1 sem turma', mcp_aceite_texto($cui, 'taxa_e_matricula', true),
    'Tenho a escolaridade mínima do curso (' . $cui['escolaridade'] . '). Sei que o curso ainda não tem turma: entro na primeira que tiver vaga, por ordem de pagamento, e recebo a data por e-mail.');
v('aceite canônico: só a taxa', mcp_aceite_texto($psb, 'so_taxa', false),
    'Tenho a escolaridade mínima do curso (' . $psb['escolaridade'] . '). Sei que a participação nas aulas é liberada só com a matrícula paga, além da taxa de inscrição.');
$js = (string) file_get_contents($raiz . '/site/matricula-cursos-presenciais/static/checkout.js');
v('aceite canônico: as frases são as do checkout.js', [str_contains($js, "'. Sei que o curso ainda não tem turma: entro na primeira que tiver vaga, por ordem de pagamento, e recebo a data por e-mail.'"),
    str_contains($js, "'. Sei que a participação nas aulas é liberada só com a matrícula paga, além da taxa de inscrição.'"), str_contains($js, "'Tenho a escolaridade mínima do curso'")], [true, true, true]);
// P4 = B (decisão 10): a matrícula com os juros proporcionais; em 10x, 180 × 354,33 ÷ 279 = R$ 228,60.
v('devolução P4 B em 10x', mcp_devolucao_p4b(['matricula_centavos' => 18000, 'total_centavos' => 27900, 'total_cobrado_centavos' => 35433]), 22860);
v('devolução P4 B à vista', mcp_devolucao_p4b(['matricula_centavos' => 18000, 'total_centavos' => 27900, 'total_cobrado_centavos' => null]), 18000);
$devolver = ['plano' => 'taxa_e_matricula', 'espera_status' => 'devolver', 'espera_devolver_motivo' => 'pedido', 'espera_turma_confirmada_em' => '2026-11-01 12:00:00',
    'matricula_centavos' => 18000, 'total_centavos' => 27900, 'total_cobrado_centavos' => 35433, 'inscricao_centavos' => 9900, 'escola_acesso' => null];
v('painel: desistência depois da confirmação não manda estornar tudo', str_starts_with(mcp_escola_resumo($devolver), 'DESISTÊNCIA DEPOIS DA CONFIRMAÇÃO (regra B): devolver por PIX R$ 228,60'), true);
v('painel: desistência antes da confirmação: estorno total', str_contains(mcp_escola_resumo(['espera_turma_confirmada_em' => null] + $devolver), 'estornar a compra inteira'), true);
// Visão pública: "teste" só para a compra de um CPF da lista (com o preço de teste ligado).
v('público: teste desligado sem preço de teste', mcp_publico(['token' => str_repeat('a', 40), 'status' => 'pendente', 'metodo' => 'pix', 'curso_slug' => 'x', 'curso_nome' => 'X', 'nome' => 'Ana Lima',
    'email' => 'a@b.c', 'inscricao_centavos' => 9900, 'taxa_centavos' => 0, 'total_centavos' => 9900, 'pix_copia_cola' => '', 'pix_url' => '', 'pix_imagem' => '',
    'criado_em' => '2026-10-09 00:00:00', 'pago_em' => null, 'escola_status' => 'pendente', 'cpf' => CPF_TESTE])['teste'], false);

printf("%d testes, %d falhas\n", $total, $falhas);
exec('rm -rf ' . escapeshellarg($tmp));
exit($falhas > 0 ? 1 : 0);
