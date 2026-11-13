<?php
/**
 * Cupons de desconto do checkout (30/10/2026, pedido do Matheus). Um cupom fixa o valor final da compra da taxa de
 * inscrição + matrícula, com tudo incluso (sem custos de processamento, sem contribuição, à vista), num curso, para um
 * número limitado de alunos.
 *
 * - O desconto sai da matrícula: a taxa de inscrição fica igual e a matrícula vira (valor final − taxa). A escola
 *   recebe a matrícula pelo valor pago e marca preco_divergente, que a secretaria confere (matricula_rapida v2).
 * - As vagas contam as inscrições pagas com o cupom e as pendentes enquanto o PIX ainda pode ser pago (1 dia,
 *   expire_in_days da cobrança): ninguém paga um PIX com o cupom além do limite. Estornada, recusada e expirada liberam
 *   a vaga. A contagem e a cobrança acontecem com GET_LOCK, uma compra com cupom por vez.
 * - O CPF de teste (TESTE_CPFS) usa o cupom sem desconto: o preço de teste nunca vira cobrança real.
 * - Para criar outro cupom, acrescente uma linha em MCP_CUPONS e publique este arquivo. Para encerrar um, ponha 'ate'
 *   no passado ou tire a linha (inscrições já feitas não mudam).
 * - 'limite' => null (06/11/2026): cupom sem limite de vagas, só com o prazo ('ate'); a página e o checkout mostram "até
 *   DD/MM" no lugar das vagas que restam (a promoção da escola no Curativos vale o mês todo, sem número de vagas).
 * - 'vitrine' => true (30/10/2026): o código vai a público no info.php (mcp_cupons_vitrine), e o checkout mostra o
 *   balão da oferta com as vagas que restam e já aplica o cupom no curso. Sem 'vitrine', o código só vale digitado.
 * - 'grupo' => N (09/11/2026): um pagamento só para N pessoas (N × total_centavos), numa cobrança da Unicopag. O checkout
 *   pede os dados das N; cada uma vira uma inscrição com os valores de uma pessoa (a escola e a secretaria veem cada
 *   aluno como sempre). A primeira (a principal, grupo_ordem 1) guarda a cobrança, o PIX e o grupo_qtd; as outras têm
 *   grupo_id = id da principal, sem hash, e seguem o status dela (mcp_aplicar_status). As N vagas são conferidas de uma
 *   vez. Na escola, cada uma vai com uma transação própria (o hash da principal + "-g<ordem>"), porque a
 *   matricula_rapida reconhece a repetição pelo hash. Só à vista (PIX ou cartão 1×), como todo cupom.
 */
declare(strict_types=1);

const MCP_CUPONS = [
    // Primeiros Socorros Básico por R$ 150,00 com tudo incluso, para 30 alunos por turma (código NOV15, agenda, 30/10/2026).
    // 13/11/2026: a turma de 17/11 lotou com 28 das 30 vagas do cupom usadas, e a venda passou à turma de 02/12. O limite
    // conta todas as inscrições com o código, de qualquer turma, então vai a 58: as 28 já usadas + 30 da turma de dezembro.
    // É o preço da escola, que vende o curso por R$ 150,00 sem taxa de inscrição; aqui a tabela é R$ 150 + R$ 99.
    'NOV15' => ['cursos' => ['primeiros-socorros-basico'], 'total_centavos' => 15000, 'limite' => 58, 'ate' => null, 'vitrine' => true],
    // Primeiros Socorros Lei Lucas por R$ 120,00 com tudo incluso, para 10 alunos por turma (código PRO12, 03/11/2026).
    // Valia até o fim das inscrições da turma de 12/11; em 13/11/2026 passa a valer para a turma de 09/12 (pedido do
    // Matheus), até o fim das inscrições dela (oferta.json: inscricoes_ate).
    'PRO12' => ['cursos' => ['primeiros-socorros-lei-lucas'], 'total_centavos' => 12000, 'limite' => 10, 'ate' => '2026-12-08T23:59:59-03:00', 'vitrine' => true],
    // Curativos e Tratamento de Feridas por R$ 150,00 com tudo incluso em novembro (06/11/2026): o preço da escola, que
    // vende o curso sem taxa de inscrição neste mês; aqui o curso tem o preço padrão (R$ 150 + R$ 99) e a promoção é o cupom.
    'CURATIVOS' => ['cursos' => ['curativos-e-tratamento-de-feridas'], 'total_centavos' => 15000, 'limite' => null, 'ate' => '2026-11-30T23:59:59-03:00', 'vitrine' => true],
    // Grupo de 5 cuidadoras na turma de 17/11 de Primeiros Socorros Básico, por R$ 150,00 cada com tudo incluso (09/11/2026,
    // pedido do Matheus; substitui o link avulso feito na ÚnicoPag). Sem vitrine: vale só pelo link ou digitado. Com
    // 'grupo' => 5, um pagamento só de R$ 750,00 pelas 5, com os dados de cada uma (a matrícula na escola é pelo CPF).
    // Vale até o fim das inscrições da turma de 17/11 (oferta.json: inscricoes_ate).
    'CUIDADORAS17' => ['cursos' => ['primeiros-socorros-basico'], 'total_centavos' => 15000, 'limite' => 5, 'ate' => '2026-11-16T23:59:59-03:00', 'grupo' => 5],
];
/** Quanto tempo uma inscrição pendente com cupom segura a vaga: o PIX vale 1 dia. */
const MCP_CUPOM_RESERVA_SEGUNDOS = 86400;
/** Consultas de cupom por IP (info.php?cupom=): folga para quem digita errado, freio para quem tenta adivinhar. */
const MCP_CUPOM_LIMITE_CONSULTAS = [30, 600];

/** O código como a pessoa digitou, em maiúsculas, só letras e números (até 30). */
function mcp_cupom_normalizar(mixed $codigo): string
{
    $texto = is_string($codigo) ? $codigo : '';
    return mb_substr((string) preg_replace('/[^A-Z0-9]/', '', strtoupper($texto)), 0, 30);
}

/**
 * O cupom, se existe e vale para o curso agora: ['codigo', 'total_centavos', 'limite', 'cursos', 'ate']. Senão,
 * ['erro' => motivo] com 'invalido' (não existe), 'curso' (existe, para outro curso) ou 'encerrado' (passou do 'ate').
 */
function mcp_cupom(string $codigo, string $slug, ?int $agora = null): array
{
    $codigo = mcp_cupom_normalizar($codigo);
    $def = $codigo !== '' ? (MCP_CUPONS[$codigo] ?? null) : null;
    if ($def === null) {
        return ['erro' => 'invalido'];
    }
    $cupom = ['codigo' => $codigo] + $def;
    if (!in_array($slug, $def['cursos'], true)) {
        return ['erro' => 'curso'] + $cupom;
    }
    if ($def['ate'] !== null && (int) mcp_iso_ts($def['ate']) < ($agora ?? time())) {
        return ['erro' => 'encerrado'] + $cupom;
    }
    return $cupom;
}

/** Mensagem do motivo de um cupom recusado (nunca diz nada de quem usou). */
function mcp_cupom_mensagem(string $motivo, ?array $cupom = null): string
{
    $nomes = [];
    foreach ($cupom['cursos'] ?? [] as $slug) {
        $nomes[] = (string) (mcp_curso($slug)['nome'] ?? $slug);
    }
    return match ($motivo) {
        'curso' => 'Este cupom vale só para ' . ($nomes ? implode(', ', $nomes) : 'outro curso') . '.',
        'encerrado' => 'Este cupom não está mais valendo.',
        'esgotado' => 'As vagas com este cupom acabaram.',
        'plano' => 'O cupom vale para a taxa de inscrição + matrícula, à vista.',
        'indisponivel' => 'O cupom está indisponível agora. Tente de novo em alguns minutos.',
        default => 'Cupom inválido. Confira o código.',
    };
}

/** A coluna cupom existe? Sem ela (logo depois de uma publicação, antes da migração), o cupom fica indisponível. */
function mcp_cupom_coluna_ok(): bool
{
    static $ok = null;
    if ($ok === true) {
        return true;
    }
    try {
        $existentes = array_column(mcp_db()->query('SHOW COLUMNS FROM mcp_inscricoes')->fetchAll(), 'Field');
    } catch (Throwable) {
        return false;
    }
    return $ok = in_array('cupom', $existentes, true) && in_array('cupom_desconto_centavos', $existentes, true);
}

/** Vagas usadas: pagas com o cupom e pendentes cujo PIX ainda pode ser pago. */
function mcp_cupom_usados(string $codigo, ?int $agora = null): int
{
    $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_inscricoes WHERE cupom = ? AND (status = 'pago' OR (status = 'pendente' AND criado_em > ?))");
    $stmt->execute([$codigo, gmdate('Y-m-d H:i:s', ($agora ?? time()) - MCP_CUPOM_RESERVA_SEGUNDOS)]);
    return (int) $stmt->fetchColumn();
}

function mcp_cupom_restantes(array $cupom, ?int $agora = null): int
{
    if (($cupom['limite'] ?? null) === null) {
        return PHP_INT_MAX; // sem limite de vagas: vale até o 'ate'
    }
    return max(0, (int) $cupom['limite'] - mcp_cupom_usados((string) $cupom['codigo'], $agora));
}

/**
 * Uma compra com cupom por vez (até a cobrança ser gravada): a trava do MySQL é solta no fim da requisição, quando a
 * conexão fecha, ou por mcp_cupom_soltar(). Devolve false se outra compra com o mesmo cupom segurou a trava por 15 s.
 */
function mcp_cupom_travar(string $codigo): bool
{
    $stmt = mcp_db()->prepare('SELECT GET_LOCK(?, 15)');
    $stmt->execute(['mcp_cupom_' . $codigo]);
    return (int) $stmt->fetchColumn() === 1;
}

function mcp_cupom_soltar(string $codigo): void
{
    try {
        mcp_db()->prepare('SELECT RELEASE_LOCK(?)')->execute(['mcp_cupom_' . $codigo]);
    } catch (Throwable) {
    }
}

/**
 * A compra de mcp_compra() com o cupom: total = valor do cupom, sem custos, sem contribuição e à vista. A taxa de
 * inscrição fica igual (ou o total inteiro, se o cupom valer menos que ela) e a matrícula leva o resto. Com o CPF de
 * teste, a compra de teste fica como está (o cupom é registrado, sem desconto).
 */
function mcp_compra_com_cupom(array $compra, array $cupom, ?string $cpf = null): array
{
    $cheio = (int) $compra['inscricao'] + (int) $compra['matricula'];
    if (mcp_cpf_de_teste($cpf)) {
        $total = (int) $compra['inscricao'] + (int) $compra['matricula'];
        return array_replace($compra, ['custos' => 0, 'divulgacao' => 0, 'amount' => $total, 'parcelas' => 1,
            'cupom' => $cupom['codigo'], 'cupom_desconto' => 0]);
    }
    $total = (int) $cupom['total_centavos'];
    $inscricao = min((int) $compra['inscricao'], $total);
    return array_replace($compra, [
        'inscricao' => $inscricao, 'matricula' => $total - $inscricao, 'custos' => 0, 'divulgacao' => 0,
        'amount' => $total, 'parcelas' => 1, 'cupom' => $cupom['codigo'], 'cupom_desconto' => max(0, $cheio - $total),
    ]);
}

/** O fim da validade de um cupom em Brasília, "30/11", ou null (sem 'ate'). */
function mcp_cupom_ate_texto(array $cupom): ?string
{
    $ts = ($cupom['ate'] ?? null) !== null ? mcp_iso_ts($cupom['ate']) : null;
    return $ts !== null ? (new DateTimeImmutable('@' . $ts))->setTimezone(mcp_fuso_brt())->format('d/m') : null;
}

/** O que a página pode saber de um cupom (info.php?cupom=): nada de quem usou, só o que falta. */
function mcp_cupom_publico(string $codigo, string $slug): array
{
    $cupom = mcp_cupom($codigo, $slug);
    if (isset($cupom['erro'])) {
        return ['valido' => false, 'motivo' => $cupom['erro'], 'mensagem' => mcp_cupom_mensagem($cupom['erro'], $cupom)];
    }
    if (!mcp_cupom_coluna_ok()) {
        return ['valido' => false, 'motivo' => 'indisponivel', 'mensagem' => mcp_cupom_mensagem('indisponivel')];
    }
    $restantes = mcp_cupom_restantes($cupom);
    if ($restantes <= 0) {
        return ['valido' => false, 'motivo' => 'esgotado', 'mensagem' => mcp_cupom_mensagem('esgotado')];
    }
    $semLimite = ($cupom['limite'] ?? null) === null;
    $grupo = mcp_cupom_grupo($cupom);
    if ($grupo > 1 && (!mcp_grupo_colunas_ok() || $restantes < $grupo)) {
        return ['valido' => false, 'motivo' => mcp_grupo_colunas_ok() ? 'esgotado' : 'indisponivel',
            'mensagem' => mcp_cupom_mensagem(mcp_grupo_colunas_ok() ? 'esgotado' : 'indisponivel')];
    }
    return ['valido' => true, 'codigo' => $cupom['codigo'], 'curso' => $slug, 'total_centavos' => (int) $cupom['total_centavos'],
        'restantes' => $semLimite ? null : $restantes, 'limite' => $semLimite ? null : (int) $cupom['limite'], 'ate' => mcp_cupom_ate_texto($cupom)]
        + ($grupo > 1 ? ['grupo' => $grupo] : []);
}

// ----------------------------------------------------------------------------- cupom de grupo (09/11/2026)
/** Quantas pessoas o cupom paga de uma vez (1 = o cupom de sempre). */
function mcp_cupom_grupo(?array $cupom): int
{
    return max(1, min(20, (int) ($cupom['grupo'] ?? 1)));
}

/** As colunas grupo_id, grupo_ordem e grupo_qtd existem? Sem elas, o cupom de grupo fica indisponível. */
function mcp_grupo_colunas_ok(): bool
{
    static $ok = null;
    if ($ok === true) {
        return true;
    }
    try {
        $existentes = array_column(mcp_db()->query('SHOW COLUMNS FROM mcp_inscricoes')->fetchAll(), 'Field');
    } catch (Throwable) {
        return false;
    }
    return $ok = !array_diff(['grupo_id', 'grupo_ordem', 'grupo_qtd'], $existentes);
}

/** Quantas pessoas a cobrança desta inscrição paga: N na principal de um grupo, 1 em qualquer outra. */
function mcp_grupo_qtd(array $i): int
{
    return !empty($i['grupo_id']) && (int) $i['grupo_id'] === (int) ($i['id'] ?? 0) ? max(1, (int) ($i['grupo_qtd'] ?? 1)) : 1;
}

/** É uma inscrição do grupo que não guarda a cobrança (segue a principal)? */
function mcp_grupo_e_filha(array $i): bool
{
    return !empty($i['grupo_id']) && (int) $i['grupo_id'] !== (int) ($i['id'] ?? 0);
}

/** O valor da cobrança desta inscrição (o PIX que a pessoa paga): total × N na principal de um grupo. */
function mcp_grupo_valor_cobranca(array $i): int
{
    return (int) $i['total_centavos'] * mcp_grupo_qtd($i);
}

/** O cobrado e os juros da cobrança do grupo, divididos por pessoa (cada inscrição guarda o de uma pessoa). */
function mcp_grupo_valores_por_pessoa(array $valores, int $qtd): array
{
    foreach (['total_cobrado_centavos', 'juros_centavos'] as $campo) {
        if (isset($valores[$campo])) {
            $valores[$campo] = intdiv((int) $valores[$campo], max(1, $qtd));
        }
    }
    return $valores;
}

/**
 * As linhas dos avisos à secretaria de uma inscrição de grupo: "Grupo" (pessoa n de N, o pagamento único) e, nas
 * outras pessoas, a "Transação Unicopag" da cobrança única. Fora de grupo, nada.
 */
function mcp_grupo_linhas(array $i): array
{
    if (empty($i['grupo_id'])) {
        return [];
    }
    $qtd = max(1, (int) ($i['grupo_qtd'] ?? 1));
    $linhas = ['Grupo' => 'pessoa ' . (int) ($i['grupo_ordem'] ?? 1) . " de $qtd · pagamento único de " . mcp_brl((int) $i['total_centavos'] * $qtd)
        . (!empty($i['cupom']) ? ' (cupom ' . $i['cupom'] . ')' : '') . ' · inscrição principal ' . (int) $i['grupo_id']];
    if (mcp_grupo_e_filha($i)) {
        $linhas['Transação Unicopag'] = (string) preg_replace('/-g\d+$/', '', mcp_grupo_transacao($i)) ?: '—';
    }
    return $linhas;
}

/** Os ids das outras inscrições do grupo desta principal, na ordem. */
function mcp_grupo_filhas(int $principalId): array
{
    if (!mcp_grupo_colunas_ok()) {
        return [];
    }
    $stmt = mcp_db()->prepare('SELECT id FROM mcp_inscricoes WHERE grupo_id = ? AND id <> ? ORDER BY grupo_ordem, id');
    $stmt->execute([$principalId, $principalId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Os primeiros nomes do grupo, na ordem (para a tela e o e-mail de quem paga). */
function mcp_grupo_nomes(int $principalId): array
{
    $stmt = mcp_db()->prepare('SELECT nome FROM mcp_inscricoes WHERE grupo_id = ? ORDER BY grupo_ordem, id');
    $stmt->execute([$principalId]);
    return array_map(static fn($n): string => mcp_primeiro_nome((string) $n), $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * A transação que vai à escola: a principal (e qualquer inscrição fora de grupo) manda o hash da Unicopag; as outras do
 * grupo mandam o hash da principal + "-g<ordem>" (a matricula_rapida trata o mesmo hash como a mesma compra).
 */
function mcp_grupo_transacao(array $i): string
{
    $hash = (string) ($i['unicopag_hash'] ?? '');
    if (!mcp_grupo_e_filha($i)) {
        return $hash;
    }
    $stmt = mcp_db()->prepare('SELECT unicopag_hash FROM mcp_inscricoes WHERE id = ?');
    $stmt->execute([(int) $i['grupo_id']]);
    $principal = (string) ($stmt->fetchColumn() ?: '');
    return $principal === '' ? '' : mb_substr($principal, 0, 58) . '-g' . max(2, (int) ($i['grupo_ordem'] ?? 2));
}

/**
 * As outras pessoas do grupo, validadas como a primeira (nome, CPF, e-mail e telefone). Cada falha responde 422 com o
 * campo "grupo.<n>.<campo>" (n = 2..N), para a página marcar. CPFs repetidos no grupo também falham.
 */
function mcp_grupo_validar(mixed $lista, array $primeira, int $qtd): array
{
    $lista = is_array($lista) ? array_values($lista) : [];
    if (count($lista) !== $qtd - 1) {
        mcp_falhar(422, 'Preencha os dados das ' . $qtd . ' pessoas do grupo.', ['campo' => 'grupo']);
    }
    $cpfs = [$primeira['cpf']];
    $pessoas = [];
    foreach ($lista as $k => $p) {
        $n = $k + 2;
        $p = is_array($p) ? $p : [];
        $nome = mcp_texto($p['nome'] ?? '', 160);
        if (mb_strlen($nome) < 5 || !str_contains($nome, ' ')) {
            mcp_falhar(422, "Informe o nome completo da pessoa $n.", ['campo' => "grupo.$n.nome"]);
        }
        $cpf = mcp_digitos(mcp_texto($p['cpf'] ?? '', 20));
        if (!mcp_cpf_valido($cpf)) {
            mcp_falhar(422, "CPF da pessoa $n inválido. Confira os 11 números.", ['campo' => "grupo.$n.cpf"]);
        }
        if (in_array($cpf, $cpfs, true)) {
            mcp_falhar(422, "O CPF da pessoa $n já está em outra pessoa do grupo. Cada pessoa tem o seu.", ['campo' => "grupo.$n.cpf"]);
        }
        $cpfs[] = $cpf;
        $email = mb_strtolower(mcp_texto($p['email'] ?? '', 190));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            mcp_falhar(422, "E-mail da pessoa $n inválido.", ['campo' => "grupo.$n.email"]);
        }
        $telefone = mcp_telefone(mcp_texto($p['telefone'] ?? '', 30));
        if ($telefone === '') {
            mcp_falhar(422, "Informe o telefone da pessoa $n, com DDD.", ['campo' => "grupo.$n.telefone"]);
        }
        $pessoas[] = ['ordem' => $n, 'nome' => $nome, 'cpf' => $cpf, 'email' => $email, 'telefone' => $telefone];
    }
    return $pessoas;
}

/**
 * Grava as outras inscrições do grupo, copiando a principal (curso, valores, plano, turma, cupom, origem) com os dados
 * de cada pessoa. Ficam sem a cobrança (hash, PIX e cartão só na principal) e com o status dela.
 */
function mcp_grupo_gravar(int $principalId, array $pessoas): void
{
    $pdo = mcp_db();
    $qtd = count($pessoas) + 1;
    $pdo->prepare('UPDATE mcp_inscricoes SET grupo_id = ?, grupo_ordem = 1, grupo_qtd = ? WHERE id = ?')->execute([$principalId, $qtd, $principalId]);
    $stmt = $pdo->prepare('SELECT * FROM mcp_inscricoes WHERE id = ?');
    $stmt->execute([$principalId]);
    $base = $stmt->fetch();
    if (!$base) {
        return;
    }
    // Só o que é da compra passa para as outras: nada da cobrança, dos e-mails, da escola, da Meta nem dos avisos. O IP
    // fica só na principal: o freio por IP (pagamentos.php) conta uma compra de grupo como uma compra, não como N.
    $copiar = ['curso_slug', 'curso_nome', 'metodo', 'inscricao_centavos', 'taxa_centavos', 'divulgacao_centavos', 'total_centavos',
        'cobre_taxa', 'divulgacao_oferta_centavos', 'status', 'unicopag_status', 'utm_source', 'utm_medium', 'utm_campaign',
        'utm_content', 'utm_term', 'escola_status', 'criado_em', 'atualizado_em', 'consultado_em',
        'plano', 'plano_oferta', 'matricula_centavos', 'matricula_preco_centavos', 'parcelas', 'total_mostrado_centavos',
        'turma_id', 'turma_inicio', 'aceite_versao', 'aceite_sha256', 'aceite_em', 'cupom', 'cupom_desconto_centavos'];
    foreach ($pessoas as $p) {
        $linha = [];
        foreach ($copiar as $coluna) {
            if (array_key_exists($coluna, $base)) {
                $linha[$coluna] = $base[$coluna];
            }
        }
        $linha = array_replace($linha, [
            'token' => mcp_token_novo(), 'nome' => $p['nome'], 'cpf' => $p['cpf'], 'email' => $p['email'], 'telefone' => $p['telefone'],
            'grupo_id' => $principalId, 'grupo_ordem' => $p['ordem'], 'grupo_qtd' => $qtd,
            // Pendente sempre: se a principal já virou paga (postback antes deste INSERT), o acerto abaixo aplica o
            // pagamento pela porta única, com o pós-pagamento de cada pessoa.
            'status' => in_array($base['status'], ['recusado', 'expirado'], true) ? $base['status'] : 'pendente',
        ]);
        $colunas = implode(', ', array_keys($linha));
        $marcadores = implode(', ', array_fill(0, count($linha), '?'));
        $pdo->prepare("INSERT INTO mcp_inscricoes ($colunas) VALUES ($marcadores)")->execute(array_values($linha));
        mcp_registrar((int) $pdo->lastInsertId(), 'grupo', "pessoa {$p['ordem']} de $qtd · cobrança na inscrição $principalId");
    }
    mcp_registrar($principalId, 'grupo', "pessoa 1 de $qtd · " . mcp_brl((int) $base['total_centavos'] * $qtd) . ' numa cobrança só');
    $stmt->execute([$principalId]);
    $agora = (string) (($stmt->fetch() ?: [])['status'] ?? '');
    if (in_array($agora, ['pago', 'estornado'], true)) {
        foreach (mcp_grupo_filhas($principalId) as $filha) {
            mcp_aplicar_status($filha, $agora);
        }
    }
}

/**
 * Os cupons de vitrine que ainda valem e têm vaga, para o balão da oferta no checkout (info.php): código, cursos, valor
 * final e vagas. A contagem é a mesma de mcp_cupom_restantes(), então o número do balão é o do servidor, nunca
 * inventado. Qualquer falha (banco, coluna ainda sem migração) devolve lista vazia: o balão some, o checkout segue.
 */
function mcp_cupons_vitrine(?int $agora = null): array
{
    $lista = [];
    try {
        if (!mcp_cupom_coluna_ok()) {
            return [];
        }
        foreach (MCP_CUPONS as $codigo => $def) {
            if (empty($def['vitrine']) || ($def['ate'] !== null && (int) mcp_iso_ts($def['ate']) < ($agora ?? time()))) {
                continue;
            }
            $restantes = mcp_cupom_restantes(['codigo' => (string) $codigo] + $def, $agora);
            if ($restantes > 0) {
                $semLimite = ($def['limite'] ?? null) === null;
                $lista[] = ['codigo' => (string) $codigo, 'cursos' => array_values($def['cursos']), 'total_centavos' => (int) $def['total_centavos'],
                    'limite' => $semLimite ? null : (int) $def['limite'], 'restantes' => $semLimite ? null : $restantes, 'ate' => mcp_cupom_ate_texto($def)];
            }
        }
    } catch (Throwable) {
        return [];
    }
    return $lista;
}
