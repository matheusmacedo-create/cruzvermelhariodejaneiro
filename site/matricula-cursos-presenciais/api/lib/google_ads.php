<?php
/**
 * Matrículas pagas para o Google Ads, pelo servidor (21/11/2026).
 *
 * O `purchase` do GA4 só sai no navegador, na tela Parabéns: quem paga o PIX no aplicativo do banco e não volta
 * para o site, ou fecha a aba antes, não vira conversão no Google Ads, mesmo tendo aceitado os cookies. Aqui a
 * venda sai do banco: o Google Ads busca um CSV em api/google-ads-conversoes.php, uma vez por dia (Metas →
 * Conversões → Envios → Programações, origem HTTPS, com usuário e senha), e casa cada linha com o clique do
 * anúncio pelo gclid que a inscrição já guarda.
 *
 * O que entra, e só isto:
 *  - inscrição com status 'pago' agora (a estornada sai da lista; a que já foi enviada continua contada lá);
 *  - com gclid (gravado só de quem aceitou a estatística, docs/rastreamento.md) e com "sim" para marketing
 *    guardado na inscrição (meta_marketing = 1, a mesma escolha m=1 do aviso de cookies que libera o
 *    ad_storage e o ad_user_data do Google no navegador). Quem recusou não entra: é a mesma regra do navegador;
 *  - o pagamento da matrícula feito depois (mcp_matricula_pagamentos), pelo gclid e pela escolha da inscrição;
 *  - no cupom de grupo, só a inscrição que guarda a cobrança, com o valor cobrado (total × pessoas).
 *
 * O que vai ao Google em cada linha: o gclid, o nome da conversão, a hora do pagamento (UTC), o valor e um id
 * da compra que é hash do token (o mesmo `transaction_id` do GA4). Nenhum nome, CPF, e-mail ou telefone.
 *
 * O arquivo não guarda estado: cada busca devolve os pagamentos dos últimos dias (GOOGLE_ADS_IMPORT_DIAS). O
 * Google Ads recusa a linha repetida (mesmo gclid, conversão e hora) com um aviso de duplicada, que é o
 * esperado; a folga de dias serve para o dia em que a busca falhou e para o clique recente demais, que ele
 * ainda não aceita no mesmo dia.
 *
 * Chaves (em api/config-google.php, só no servidor): GOOGLE_ADS_IMPORT_SENHA (24 caracteres ou mais; vazia =
 * o endereço responde 404), GOOGLE_ADS_IMPORT_USUARIO (padrão "google-ads"), GOOGLE_ADS_IMPORT_CONVERSAO (o
 * nome exato da ação de conversão criada no Google Ads) e GOOGLE_ADS_IMPORT_DIAS.
 *
 * Módulo opcional: só o google-ads-conversoes.php o carrega. O checkout não depende dele.
 */
declare(strict_types=1);

const MCP_GADS_SENHA_MIN = 24;
/** Senhas erradas por IP antes do 429: [quantas, em quantos segundos]. */
const MCP_GADS_FALHAS = [20, 3600];
const MCP_GADS_DIAS_PADRAO = 4;
const MCP_GADS_DIAS_MAX = 30;
const MCP_GADS_CONVERSAO_PADRAO = 'Matricula paga (servidor)';
const MCP_GADS_LINHAS_MAX = 2000;
const MCP_GADS_COLUNAS = ['Google Click ID', 'Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency', 'Order ID'];

function mcp_gads_senha(): string
{
    return (string) mcp_cfg('GOOGLE_ADS_IMPORT_SENHA', '');
}

function mcp_gads_usuario(): string
{
    return trim((string) mcp_cfg('GOOGLE_ADS_IMPORT_USUARIO', 'google-ads'));
}

function mcp_gads_configurado(): bool
{
    return strlen(mcp_gads_senha()) >= MCP_GADS_SENHA_MIN && mcp_gads_usuario() !== '';
}

/** O nome da ação de conversão no Google Ads: tem de ser igual, letra por letra, ao que foi criado lá. */
function mcp_gads_conversao(): string
{
    $nome = trim((string) mcp_cfg('GOOGLE_ADS_IMPORT_CONVERSAO', MCP_GADS_CONVERSAO_PADRAO));
    return $nome !== '' ? mb_substr($nome, 0, 100) : MCP_GADS_CONVERSAO_PADRAO;
}

function mcp_gads_dias(): int
{
    return max(1, min(MCP_GADS_DIAS_MAX, (int) mcp_cfg('GOOGLE_ADS_IMPORT_DIAS', MCP_GADS_DIAS_PADRAO)));
}

/**
 * Confere "Authorization: Basic <usuário:senha em base64>" em tempo constante. O usuário e a senha são os que
 * a programação do Google Ads guarda. Sem a senha configurada, nada confere.
 */
function mcp_gads_autorizado(string $cabecalho): bool
{
    if (!mcp_gads_configurado() || !preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/', trim($cabecalho), $m)) {
        return false;
    }
    $par = base64_decode($m[1], true);
    if ($par === false || !str_contains($par, ':')) {
        return false;
    }
    [$usuario, $senha] = explode(':', $par, 2);
    // As duas comparações rodam sempre: o tempo não diz qual das duas errou.
    $usuarioOk = hash_equals(hash('sha256', mcp_gads_usuario()), hash('sha256', $usuario));
    $senhaOk = hash_equals(hash('sha256', mcp_gads_senha()), hash('sha256', $senha));
    return $usuarioOk && $senhaOk;
}

/** O gclid como o Google o escreve (letras, números, "-" e "_"), ou null. Qualquer outra coisa não vai ao arquivo. */
function mcp_gads_gclid(mixed $gclid): ?string
{
    $gclid = is_string($gclid) ? trim($gclid) : '';
    return preg_match('/^[A-Za-z0-9_-]{20,255}\z/', $gclid) ? $gclid : null;
}

/** 'AAAA-MM-DD HH:MM:SS' (UTC, como o banco guarda) conferido, ou null. */
function mcp_gads_hora(mixed $utc): ?string
{
    if (!is_string($utc) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $utc)) {
        return null;
    }
    $data = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));
    return $data !== false && $data->format('Y-m-d H:i:s') === $utc ? $utc : null;
}

/** Id da compra no arquivo: hash do token, nunca o token (que abre a inscrição). */
function mcp_gads_id_da_matricula(string $token): string
{
    return 'm.' . substr(hash('sha256', 'cvrj-gads-matricula|' . $token), 0, 32);
}

/**
 * As linhas do arquivo, a partir das inscrições pagas e dos pagamentos de matrícula (linhas do banco). Função
 * pura: confere de novo, linha por linha, tudo o que a consulta já filtrou (pago, com "sim" para marketing, com
 * gclid válido), para um filtro errado na consulta não mandar ao Google quem não deu permissão.
 * $pagamentos: linhas de mcp_matricula_pagamentos com gclid e meta_marketing da inscrição.
 */
function mcp_gads_linhas(array $inscricoes, array $pagamentos, string $conversao): array
{
    $linhas = [];
    $vistos = [];
    $juntar = static function (array $r, int $centavos, string $idCompra) use (&$linhas, &$vistos, $conversao): void {
        $gclid = mcp_gads_gclid($r['gclid'] ?? null);
        $hora = mcp_gads_hora($r['pago_em'] ?? null);
        if (($r['status'] ?? '') !== 'pago' || (string) ($r['meta_marketing'] ?? '') !== '1' || $gclid === null || $hora === null || $centavos <= 0) {
            return;
        }
        // O Google Ads trata como a mesma conversão o mesmo clique, nome e hora: a segunda não entra no arquivo.
        $chave = $gclid . '|' . $hora;
        if (isset($vistos[$chave])) {
            return;
        }
        $vistos[$chave] = true;
        $linhas[] = [$gclid, $conversao, $hora, number_format($centavos / 100, 2, '.', ''), 'BRL', $idCompra];
    };
    foreach ($inscricoes as $i) {
        // Cupom de grupo: quem não guarda a cobrança segue a principal, que já leva o valor de todos.
        if (!empty($i['grupo_id']) && (int) $i['grupo_id'] !== (int) ($i['id'] ?? 0)) {
            continue;
        }
        $pessoas = !empty($i['grupo_id']) ? max(1, (int) ($i['grupo_qtd'] ?? 1)) : 1;
        $token = (string) ($i['token'] ?? '');
        if ($token === '') {
            continue;
        }
        $juntar($i, (int) ($i['total_centavos'] ?? 0) * $pessoas, mcp_meta_id_da_compra($token));
    }
    foreach ($pagamentos as $p) {
        $token = (string) ($p['token'] ?? '');
        if ($token === '') {
            continue;
        }
        $juntar($p, (int) ($p['valor_centavos'] ?? 0), mcp_gads_id_da_matricula($token));
    }
    usort($linhas, static fn(array $a, array $b): int => [$a[2], $a[5]] <=> [$b[2], $b[5]]);
    return $linhas;
}

/** Um campo do CSV: entre aspas quando tem vírgula, aspas ou quebra de linha. */
function mcp_gads_campo(string $valor): string
{
    $valor = str_replace(["\r", "\n"], ' ', $valor);
    return preg_match('/[",]/', $valor) ? '"' . str_replace('"', '""', $valor) . '"' : $valor;
}

/**
 * O arquivo no formato do modelo "Conversões de cliques" do Google Ads: a linha do fuso (as horas vão em UTC),
 * o cabeçalho e uma linha por pagamento. Sem pagamento no período, sai só o fuso e o cabeçalho.
 */
function mcp_gads_csv(array $linhas): string
{
    $saida = "Parameters:TimeZone=+0000\n" . implode(',', MCP_GADS_COLUNAS) . "\n";
    foreach ($linhas as $linha) {
        $saida .= implode(',', array_map(static fn($campo): string => mcp_gads_campo((string) $campo), $linha)) . "\n";
    }
    return $saida;
}

/** Os pagamentos dos últimos $dias dias que podem ir ao Google Ads, já em linhas do arquivo. */
function mcp_gads_buscar(int $dias, ?int $agora = null): array
{
    $desde = gmdate('Y-m-d H:i:s', ($agora ?? time()) - $dias * 86400);
    $db = mcp_db();
    $stmt = $db->prepare("SELECT * FROM mcp_inscricoes WHERE status = 'pago' AND pago_em >= ? AND meta_marketing = 1
        AND gclid IS NOT NULL AND gclid <> '' ORDER BY pago_em, id LIMIT " . MCP_GADS_LINHAS_MAX);
    $stmt->execute([$desde]);
    $inscricoes = $stmt->fetchAll();
    $pagamentos = [];
    try {
        $stmt = $db->prepare("SELECT p.token, p.valor_centavos, p.status, p.pago_em, i.gclid, i.meta_marketing
            FROM mcp_matricula_pagamentos p JOIN mcp_inscricoes i ON i.id = p.inscricao_id
            WHERE p.status = 'pago' AND p.pago_em >= ? AND i.meta_marketing = 1 AND i.gclid IS NOT NULL AND i.gclid <> ''
            ORDER BY p.pago_em, p.id LIMIT " . MCP_GADS_LINHAS_MAX);
        $stmt->execute([$desde]);
        $pagamentos = $stmt->fetchAll();
    } catch (PDOException $e) {
        // Banco sem a tabela dos pagamentos de matrícula: o arquivo sai só com as inscrições.
        error_log('[matricula] google ads: pagamentos de matrícula fora do arquivo: ' . $e->getMessage());
    }
    return mcp_gads_linhas($inscricoes, $pagamentos, mcp_gads_conversao());
}
