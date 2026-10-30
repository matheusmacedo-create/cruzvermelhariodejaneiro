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
 */
declare(strict_types=1);

const MCP_CUPONS = [
    // Primeiros Socorros Básico por R$ 150,00 com tudo incluso, para 30 alunos (agenda, 30/10/2026).
    'AGENDA150' => ['cursos' => ['primeiros-socorros-basico'], 'total_centavos' => 15000, 'limite' => 30, 'ate' => null],
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
    return ['valido' => true, 'codigo' => $cupom['codigo'], 'curso' => $slug, 'total_centavos' => (int) $cupom['total_centavos'],
        'restantes' => $restantes, 'limite' => (int) $cupom['limite']];
}
