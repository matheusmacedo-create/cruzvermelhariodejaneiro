#!/usr/bin/env php
<?php
/**
 * Teste de ponta a ponta da matrícula na escola: o código do site (api/lib/escola.php) chamando a
 * função public.matricula_rapida por HTTP, contra uma cópia LOCAL do banco da escola.
 *
 * Precisa de três serviços locais (passo a passo em docs/escola/README.md):
 *   - MariaDB com um banco vazio para o site;
 *   - Postgres com a cópia do banco da escola (docs/escola/teste-local/*.sql) e a função aplicada;
 *   - PostgREST na frente desse Postgres.
 * Variáveis: MCP_CONFIG_ARQUIVO (config do site, banco local), MCP_CONFIG_ESCOLA_ARQUIVO (URL do
 * PostgREST local e um JWT de service_role), ESCOLA_PG_DSN / ESCOLA_PG_USUARIO (para conferir o que
 * foi gravado na cópia da escola). Nunca aponte para produção: o teste grava e apaga dados.
 *
 * Uso: php scripts/testar_escola_integracao.php
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
foreach (['MCP_CONFIG_ARQUIVO', 'MCP_CONFIG_ESCOLA_ARQUIVO', 'ESCOLA_PG_DSN'] as $variavel) {
    if (!getenv($variavel)) {
        fwrite(STDERR, "Falta a variável $variavel (veja docs/escola/README.md).\n");
        exit(2);
    }
}
putenv('MCP_CATALOGO_ARQUIVO=' . $raiz . '/site/matricula-cursos-presenciais/cursos.json');
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $raiz . '/site/matricula-cursos-presenciais/api/lib.php';
restore_exception_handler();

$url = (string) mcp_cfg('ESCOLA_API_URL');
if (!in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "ESCOLA_API_URL não é local ($url). Este teste só roda contra a cópia local.\n");
    exit(2);
}
$pg = new PDO((string) getenv('ESCOLA_PG_DSN'), getenv('ESCOLA_PG_USUARIO') ?: null, getenv('ESCOLA_PG_SENHA') ?: null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$falhas = 0;
$total = 0;
function verificar(string $nome, mixed $obtido, mixed $esperado): void
{
    global $falhas, $total;
    $total++;
    if ($obtido === $esperado) {
        echo "ok   $nome\n";
        return;
    }
    $falhas++;
    fwrite(STDERR, sprintf("FALHOU %s\n  esperado: %s\n  obtido:   %s\n", $nome, var_export($esperado, true), var_export($obtido, true)));
}

/** CPF com dígito verificador válido a partir de 9 dígitos (só para esta cópia local). */
function cpf_de_teste(string $base): string
{
    $d = array_map('intval', str_split($base));
    $s = 0;
    foreach ($d as $i => $n) {
        $s += $n * (10 - $i);
    }
    $d[] = ($s * 10) % 11 % 10;
    $s = 0;
    foreach ($d as $i => $n) {
        $s += $n * (11 - $i);
    }
    $d[] = ($s * 10) % 11 % 10;
    return implode('', $d);
}

function inscricao_paga(string $slug, string $cpf, string $email, string $hash): int
{
    $curso = mcp_curso($slug) ?? ['nome' => 'Curso inexistente'];
    $agora = mcp_agora();
    mcp_db()->prepare("INSERT INTO mcp_inscricoes (token, curso_slug, curso_nome, nome, cpf, email, telefone, metodo, inscricao_centavos,
            taxa_centavos, total_centavos, status, unicopag_hash, unicopag_status, escola_status, criado_em, atualizado_em, pago_em)
        VALUES (?, ?, ?, 'Pessoa de Teste Integrada', ?, ?, '21987654321', 'pix', 9900, 495, 10395, 'pago', ?, 'paid', 'pendente', ?, ?, ?)")
        ->execute([bin2hex(random_bytes(20)), $slug, $curso['nome'], $cpf, $email, $hash, $agora, $agora, $agora]);
    return (int) mcp_db()->lastInsertId();
}

$sufixo = substr((string) time(), -6);
$cpfNovo = cpf_de_teste('71' . $sufixo . '1');
$cpfSemTurma = cpf_de_teste('71' . $sufixo . '2');
$cpfConflito = cpf_de_teste('71' . $sufixo . '3');
$emailNovo = "integrada.$sufixo@exemplo.test";

// 1) Aluno novo, curso com turma aberta (Bombeiro Civil, turma real de 22/10/2026 na cópia).
$id = inscricao_paga('bombeiro-civil', $cpfNovo, $emailNovo, "INTEG{$sufixo}A");
mcp_escola_tentar($id);
$i = mcp_inscricao_por('id', (string) $id);
$acesso = mcp_escola_acesso($i);
verificar('novo: escola ok', $i['escola_status'], 'ok');
verificar('novo: matriculado', $acesso['resultado'] ?? null, 'matriculado');
verificar('novo: conta criada', $acesso['aluno_novo'] ?? null, true);
verificar('novo: link de entrada', $acesso['url_login'] ?? null, 'https://escola.cursoscruzvermelha.org/login');
$i = mcp_inscricao_por('id', (string) $id);
$link = mcp_escola_link($i);
verificar('novo: link de criar senha na escola', is_string($link) && str_starts_with($link, 'https://escola.cursoscruzvermelha.org/redefinir-senha?token='), true);
$token = $pg->query("select k.\"tokenHash\", k.tipo, k.\"usadoEm\" is null as livre, k.\"expiraEm\" > now() at time zone 'UTC' + interval '71 hours' as validade
    from \"TokenAuth\" k join \"Usuario\" u on u.id = k.\"usuarioId\" where u.\"cpfCnpj\" = " . $pg->quote($cpfNovo))->fetch();
verificar('novo: escola guarda o sha256 do link, 72 horas', [$token['tokenHash'] ?? null, $token['tipo'] ?? null, $token['livre'] ?? null, $token['validade'] ?? null],
    [hash('sha256', (string) $i['escola_token']), 'RESET_SENHA', true, true]);
$linha = $pg->query("select u.\"senhaHash\", m.plano::text as plano, m.\"valorCurso\"::text as valor, m.\"taxaConfirmada\" as taxa,
        p.valor::text as pago, p.gateway, t.\"cursoId\"
    from \"Usuario\" u join \"Matricula\" m on m.\"alunoId\" = u.id join \"Turma\" t on t.id = m.\"turmaId\"
    join \"Pagamento\" p on p.\"matriculaId\" = m.id where u.\"cpfCnpj\" = " . $pg->quote($cpfNovo))->fetch();
verificar('novo: turma do curso certo', $linha['cursoId'] ?? null, '5bd737ee-00a6-48dc-b5ab-08cde9b12897');
verificar('novo: matrícula à vista, saldo = preço à vista', [$linha['plano'] ?? null, $linha['valor'] ?? null, $linha['taxa'] ?? null], ['A_VISTA', '1049.00', true]);
verificar('novo: pagamento TAXA de R$ 99 pelo site', [$linha['pago'] ?? null, $linha['gateway'] ?? null], ['99.00', 'site']);
verificar('novo: senha aleatória em argon2id, nunca os 4 últimos dígitos do CPF', str_starts_with((string) ($linha['senhaHash'] ?? ''), '$argon2id$')
    && !password_verify(substr($cpfNovo, -4), (string) ($linha['senhaHash'] ?? '')), true);

// 2) A mesma inscrição de novo (retentativa): nada duplica.
mcp_escola_tentar($id);
$contagem = $pg->query("select count(*) from \"Matricula\" m join \"Usuario\" u on u.id = m.\"alunoId\" where u.\"cpfCnpj\" = " . $pg->quote($cpfNovo))->fetchColumn();
verificar('repetida: continua uma matrícula', (int) $contagem, 1);
verificar('repetida: escola ok e o mesmo link', [mcp_inscricao_por('id', (string) $id)['escola_status'], mcp_escola_link(mcp_inscricao_por('id', (string) $id))], ['ok', $link]);
verificar('repetida: um link só na escola', (int) $pg->query("select count(*) from \"TokenAuth\" k join \"Usuario\" u on u.id = k.\"usuarioId\" where u.\"cpfCnpj\" = " . $pg->quote($cpfNovo))->fetchColumn(), 1);

// 3) Curso sem turma aberta (Cuidador de Idosos): só a conta.
$id = inscricao_paga('cuidador-de-idosos', $cpfSemTurma, "semturma.$sufixo@exemplo.test", "INTEG{$sufixo}B");
mcp_escola_tentar($id);
$acesso = mcp_escola_acesso(mcp_inscricao_por('id', (string) $id));
verificar('sem turma: resultado', [$acesso['resultado'] ?? null, $acesso['aluno_novo'] ?? null, array_key_exists('turma_inicio', $acesso ?? []) ? $acesso['turma_inicio'] : 'ausente'], ['sem_turma', true, null]);

// 4) E-mail que já é de outra conta: não grava nada e não tenta de novo.
$id = inscricao_paga('bombeiro-civil', $cpfConflito, $emailNovo, "INTEG{$sufixo}C");
mcp_escola_tentar($id);
$i = mcp_inscricao_por('id', (string) $id);
verificar('conflito: erro definitivo', [$i['escola_status'], (int) $i['escola_tentativas'], mcp_escola_erro($i)], ['erro', MCP_ESCOLA_MAX_TENTATIVAS, 'email_em_uso']);
verificar('conflito: nenhuma conta criada', (int) $pg->query("select count(*) from \"Usuario\" where \"cpfCnpj\" = " . $pg->quote($cpfConflito))->fetchColumn(), 0);
verificar('conflito: e-mail da secretaria explica', str_starts_with(mcp_escola_resumo($i), 'NÃO MATRICULADO: o e-mail já está em outra conta'), true);

// 5) Curso fora do catálogo (sem id da escola): recusado na hora, sem novas tentativas.
$id = inscricao_paga('curso-que-nao-existe', cpf_de_teste('71' . $sufixo . '4'), "recusado.$sufixo@exemplo.test", "INTEG{$sufixo}D");
mcp_escola_tentar($id);
$i = mcp_inscricao_por('id', (string) $id);
verificar('recusado: erro definitivo com o campo', [$i['escola_status'], (int) $i['escola_tentativas'], mcp_escola_erro($i)], ['erro', MCP_ESCOLA_MAX_TENTATIVAS, 'dados inválidos: curso_id']);

// 7) Ponto da sede: a função aulas_do_aluno (docs/escola/aulas_do_aluno.sql), pela mesma chave, devolve as aulas
//    do dia do aluno matriculado no passo 1. O PostgREST relê as funções antes (a função pode ser nova na cópia).
$pg->exec("NOTIFY pgrst, 'reload schema'");
usleep(1500000);
$hojeBrt = mcp_ponto_hoje();
$turmaNovo = (string) $pg->query("select m.\"turmaId\" from \"Matricula\" m join \"Usuario\" u on u.id = m.\"alunoId\" where u.\"cpfCnpj\" = " . $pg->quote($cpfNovo))->fetchColumn();
$pg->exec("delete from \"AulaData\" where id = 'ad-ponto-teste'");
$pg->prepare('insert into "AulaData" (id, "turmaId", data, horario) values (?, ?, ?, ?)')->execute(['ad-ponto-teste', $turmaNovo, $hojeBrt, '18:00 - 22:00']);
$aulas = mcp_escola_aulas($cpfNovo, $hojeBrt);
verificar('ponto: aulas de hoje pela função da escola', [$aulas['ok'], $aulas['aluno']['email'] ?? null, count($aulas['aulas'] ?? []), $aulas['aulas'][0]['id'] ?? null,
    $aulas['aulas'][0]['horario'] ?? null, $aulas['aulas'][0]['curso'] ?? null], [true, $emailNovo, 1, 'ad-ponto-teste', '18:00 - 22:00', 'Bombeiro Civil']);
verificar('ponto: dia sem aula não devolve nem o nome', mcp_escola_aulas($cpfNovo, '2030-01-01'), ['ok' => true, 'aluno' => null, 'aulas' => []]);
$pg->exec("delete from \"AulaData\" where id = 'ad-ponto-teste'");

// 6) A escola fora do ar: erro que pode ser tentado de novo (tentativas continuam abaixo do limite).
$foraDoAr = tempnam(sys_get_temp_dir(), 'mcp-escola-fora-');
file_put_contents($foraDoAr, "<?php return ['ESCOLA_API_URL' => 'http://127.0.0.1:9/rpc/matricula_rapida', 'ESCOLA_API_TOKEN' => 'x'];");
$id = inscricao_paga('bombeiro-civil', cpf_de_teste('71' . $sufixo . '5'), "foradoar.$sufixo@exemplo.test", "INTEG{$sufixo}E");
$cmd = sprintf('MCP_CONFIG_ESCOLA_ARQUIVO=%s php -r %s 2>&1', escapeshellarg($foraDoAr), escapeshellarg(
    '$_SERVER["REQUEST_METHOD"] = "CLI"; putenv("MCP_CATALOGO_ARQUIVO=' . $raiz . '/site/matricula-cursos-presenciais/cursos.json");'
    . 'require "' . $raiz . '/site/matricula-cursos-presenciais/api/lib.php"; restore_exception_handler(); mcp_escola_tentar(' . $id . ');'));
exec($cmd, $saida, $codigo);
unlink($foraDoAr);
$i = mcp_inscricao_por('id', (string) $id);
verificar('fora do ar: erro com nova tentativa', [$codigo, $i['escola_status'], (int) $i['escola_tentativas'], $i['escola_acesso']], [0, 'erro', 1, null]);

printf("%d testes, %d falhas\n", $total, $falhas);
exit($falhas > 0 ? 1 : 0);
