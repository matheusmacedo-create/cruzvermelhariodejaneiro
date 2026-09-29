<?php
/**
 * Declarações em PDF do ponto da sede (29/09/2026), no mesmo desenho do comprovante de inscrição
 * (lib/comprovante.php): o comprovante de comparecimento do aluno (lib/presenca.php) e a declaração
 * de horas voluntárias do colaborador (lib/ponto.php).
 *
 * Cada documento leva um código de verificação de 8 caracteres, sem 0/O nem 1/I para não confundir.
 * Quem recebe o documento confere o código em /conferir/, que redireciona para
 * /matricula-cursos-presenciais/conferir/. A página mostra o que foi declarado, com o CPF mascarado,
 * e avisa se a secretaria cancelou.
 */
declare(strict_types=1);

const MCP_CODIGO_ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const MCP_DIAS_SEMANA = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];

// ----------------------------------------------------------------------------- código de verificação
function mcp_codigo_gerar(): string
{
    $codigo = '';
    for ($i = 0; $i < 8; $i++) {
        $codigo .= MCP_CODIGO_ALFABETO[random_int(0, strlen(MCP_CODIGO_ALFABETO) - 1)];
    }
    return $codigo;
}

/** Código novo, que não existe em nenhum dos documentos já emitidos. */
function mcp_codigo_novo(): string
{
    for ($tentativa = 0; $tentativa < 8; $tentativa++) {
        $codigo = mcp_codigo_gerar();
        $stmt = mcp_db()->prepare('SELECT (SELECT COUNT(*) FROM mcp_presencas WHERE codigo = ?) + (SELECT COUNT(*) FROM mcp_declaracoes_horas WHERE codigo = ?)');
        $stmt->execute([$codigo, $codigo]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $codigo;
        }
    }
    throw new RuntimeException('Não foi possível gerar um código de verificação único.');
}

/** O que a pessoa digitou ("k7qm-4xpa", "K7QM 4XPA") no formato gravado, ou '' se não pode ser um código. */
function mcp_codigo_normalizar(mixed $entrada): string
{
    $codigo = strtoupper((string) preg_replace('/[\s.\-]+/', '', is_string($entrada) ? $entrada : ''));
    return preg_match('/^[' . MCP_CODIGO_ALFABETO . ']{8}$/', $codigo) ? $codigo : '';
}

function mcp_codigo_formatado(string $codigo): string
{
    return strlen($codigo) === 8 ? substr($codigo, 0, 4) . '-' . substr($codigo, 4) : $codigo;
}

/** Endereço de conferência com o código já preenchido. */
function mcp_conferir_url(string $codigo): string
{
    return mcp_site_url() . '/conferir/?c=' . rawurlencode($codigo);
}

/** Endereço curto, para imprimir: "cruzvermelhariodejaneiro.org/conferir". */
function mcp_conferir_url_curta(): string
{
    return preg_replace('#^https?://#', '', mcp_site_url()) . '/conferir';
}

// ----------------------------------------------------------------------------- textos
/** "22 de outubro de 2026" a partir de AAAA-MM-DD. */
function mcp_data_extenso(string $iso): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return $iso;
    }
    // Primeiro dia do mês com ordinal, como se escreve em documentos: "1º de outubro".
    return ((int) $m[3] === 1 ? '1º' : (string) (int) $m[3]) . ' de ' . MCP_PONTO_MESES[(int) $m[2] - 1] . ' de ' . $m[1];
}

function mcp_dia_semana(string $iso): string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso) ? MCP_DIAS_SEMANA[(int) (new DateTimeImmutable($iso))->format('w')] : '';
}

/** "m***@gmail.com": mostra só o bastante para a pessoa reconhecer o próprio e-mail. */
function mcp_email_mascarado(string $email): string
{
    if (!str_contains($email, '@')) {
        return '';
    }
    [$usuario, $dominio] = explode('@', $email, 2);
    return mb_substr($usuario, 0, 1) . '***@' . $dominio;
}

/** "***.456.789-**": CPF para mostrar a quem confere um documento. */
function mcp_cpf_mascarado(string $cpf): string
{
    $d = mcp_digitos($cpf);
    return strlen($d) === 11 ? '***.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-**' : '';
}

/** Nome de arquivo sem acento nem espaço: "Comprovante_de_Comparecimento_Bombeiro_Civil_2026-10-22.pdf". */
function mcp_nome_arquivo(string $texto): string
{
    $sem = strtr($texto, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
        'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e', 'É' => 'E', 'Ê' => 'E', 'È' => 'E', 'Ë' => 'E',
        'í' => 'i', 'î' => 'i', 'ì' => 'i', 'ï' => 'i', 'Í' => 'I', 'Î' => 'I', 'Ì' => 'I', 'Ï' => 'I',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ö' => 'o', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ò' => 'O', 'Ö' => 'O',
        'ú' => 'u', 'û' => 'u', 'ù' => 'u', 'ü' => 'u', 'Ú' => 'U', 'Û' => 'U', 'Ù' => 'U', 'Ü' => 'U',
        'ç' => 'c', 'Ç' => 'C', 'ñ' => 'n', 'Ñ' => 'N',
    ]);
    $sem = trim(preg_replace('/[^A-Za-z0-9-]+/', '_', $sem) ?? '', '_');
    return substr($sem !== '' ? $sem : 'Declaracao', 0, 120) . '.pdf';
}

// ----------------------------------------------------------------------------- PDF
/**
 * Os bytes do PDF de uma declaração. Como no comprovante de inscrição, se o conteúdo não couber no
 * A4 (nome ou curso em várias linhas), o desenho é refeito com menos espaço entre os blocos, nunca
 * com fonte menor.
 *
 * @param array{titulo:string, subtitulo:string, assunto:string, rotulo_pessoa:string, nome:string, cpf:string,
 *   texto:string, secao:string, linhas:list<array{0:string,1:string}>, local_data:string, assinatura:list<string>,
 *   codigo:string, conferir:string, rodape:string} $c
 */
function mcp_declaracao_pdf(array $c): string
{
    foreach ([1.0, 0.9, 0.8, 0.7, 0.6] as $fator) {
        [$pdf, $fimY] = mcp_declaracao_desenhar($c, $fator);
        if ($fimY <= McpPdf::ALTURA - 28) {
            break;
        }
    }
    return $pdf->gerar();
}

/** @return array{0: McpPdf, 1: float} o PDF e a linha de base da última linha do rodapé */
function mcp_declaracao_desenhar(array $c, float $f): array
{
    $pdf = new McpPdf();
    $pdf->metadados($c['titulo'] . ' — ' . $c['nome'], MCP_NOME_FILIAL, $c['assunto']);
    $direita = McpPdf::LARGURA - MCP_CP_MARGEM;
    $largura = $direita - MCP_CP_MARGEM;

    // Cabeçalho igual ao do comprovante de inscrição: faixa, logo e fio.
    $pdf->retangulo(0, 0, McpPdf::LARGURA, 8, MCP_CP_VERMELHO);
    $logoL = 345.0;
    $logoA = $logoL * 398 / 1325;
    $pdf->imagemPng(MCP_COMPROVANTE_LOGO, MCP_CP_MARGEM - 0.0257 * $logoL, 44 - 0.0427 * $logoA, $logoL, $logoA);
    $pdf->linha(MCP_CP_MARGEM, 158, $direita, 158, MCP_CP_FIO, 0.8);

    $pdf->texto(MCP_CP_MARGEM, 200.5, $c['titulo'], 'negrito', 19, MCP_CP_TEXTO, 0.4);
    $sub = McpPdf::quebrar($c['subtitulo'], 'normal', 12, $largura);
    foreach ($sub as $i => $l) {
        $pdf->texto(MCP_CP_MARGEM, 226 + $i * 15, $l, 'normal', 12, MCP_CP_CINZA);
    }
    $y = 226 + (count($sub) - 1) * 15;

    // Caixa da pessoa: faixa vermelha à esquerda, fundo cinza, nome e CPF.
    $nomeLinhas = McpPdf::quebrar($c['nome'], 'negrito', 14, $direita - 16 - MCP_CP_ROTULO_X);
    $topo = $y + 25.3;
    $altura = 81.2 + (count($nomeLinhas) - 1) * 17;
    $pdf->retanguloArredondado(MCP_CP_MARGEM, $topo, 13, $altura, [7, 0, 0, 7], MCP_CP_VERMELHO);
    $pdf->retanguloArredondado(MCP_CP_MARGEM + 5, $topo, $largura - 5, $altura, [0, 7, 7, 0], MCP_CP_CAIXA);
    $pdf->texto(MCP_CP_ROTULO_X, $topo + 25.8, $c['rotulo_pessoa'], 'negrito', 8.9, MCP_CP_CINZA, 0.9);
    foreach ($nomeLinhas as $i => $l) {
        $pdf->texto(MCP_CP_ROTULO_X, $topo + 49.2 + $i * 17, $l, 'negrito', 14, MCP_CP_TEXTO);
    }
    $pdf->texto(MCP_CP_ROTULO_X, $topo + 69.2 + (count($nomeLinhas) - 1) * 17, $c['cpf'], 'normal', 10.3, MCP_CP_TEXTO);
    $y = $topo + $altura;

    // O texto da declaração.
    $y += 36 * $f;
    $texto = McpPdf::quebrar($c['texto'], 'normal', 11.3, $largura);
    foreach ($texto as $i => $l) {
        $pdf->texto(MCP_CP_MARGEM, $y + $i * 17, $l, 'normal', 11.3, MCP_CP_TEXTO);
    }
    $y += (count($texto) - 1) * 17;

    // Dados em linhas rótulo/valor.
    $y += 38 * $f;
    $pdf->texto(MCP_CP_MARGEM, $y, $c['secao'], 'negrito', 10.1, MCP_CP_VERMELHO, 0.6);
    $pdf->linha(MCP_CP_MARGEM, $y + 10, $direita, $y + 10, MCP_CP_FIO, 0.8);
    $y += 10 + 22 * $f;
    foreach ($c['linhas'] as $n => [$rotulo, $valor]) {
        $pdf->texto(MCP_CP_ROTULO_X, $y, $rotulo, 'normal', 10, MCP_CP_CINZA);
        $partes = McpPdf::quebrar($valor, 'negrito', 10.9, $direita - MCP_CP_VALOR_X);
        foreach ($partes as $i => $l) {
            $pdf->texto(MCP_CP_VALOR_X, $y + $i * 14, $l, 'negrito', 10.9, MCP_CP_TEXTO);
        }
        $y += (count($partes) - 1) * 14 + ($n < count($c['linhas']) - 1 ? 25 * $f : 0);
    }

    // Local, data e quem emite.
    $y += 38 * $f;
    $pdf->texto(MCP_CP_MARGEM, $y, $c['local_data'], 'normal', 11, MCP_CP_TEXTO);
    foreach ($c['assinatura'] as $i => $l) {
        $pdf->texto(MCP_CP_MARGEM, $y + 24 * $f + $i * 14, $l, $i === 0 ? 'negrito' : 'normal', 10.3, MCP_CP_TEXTO);
    }
    $y += 24 * $f + (count($c['assinatura']) - 1) * 14;

    // Código de verificação: quem recebe o documento confere na página.
    $y += 26 * $f;
    $caixa = 60.0;
    $pdf->retanguloArredondado(MCP_CP_MARGEM, $y, $largura, $caixa, [8, 8, 8, 8], MCP_CP_CAIXA);
    $pdf->texto(MCP_CP_MARGEM + 18, $y + 23, 'CÓDIGO DE VERIFICAÇÃO', 'negrito', 8.6, MCP_CP_CINZA, 0.9);
    $pdf->texto(MCP_CP_MARGEM + 18, $y + 45, $c['codigo'], 'negrito', 17, MCP_CP_VERMELHO, 1.6);
    $xConferir = MCP_CP_MARGEM + 210;
    $pdf->texto($xConferir, $y + 23, 'CONFIRA EM', 'negrito', 8.6, MCP_CP_CINZA, 0.9);
    $url = McpPdf::quebrar((string) preg_replace('#^https?://#', '', $c['conferir']), 'normal', 9.8, $direita - 18 - $xConferir);
    foreach (array_slice($url, 0, 2) as $i => $l) {
        $pdf->texto($xConferir, $y + 39 + $i * 12.5, $l, 'normal', 9.8, MCP_CP_TEXTO);
    }
    $y += $caixa;

    // Rodapé.
    $y += 26 * $f;
    $pdf->linha(MCP_CP_MARGEM, $y, $direita, $y, MCP_CP_FIO, 0.8);
    $y += 17;
    $rodape = McpPdf::quebrar($c['rodape'], 'normal', 8.4, $largura);
    foreach ($rodape as $i => $l) {
        $pdf->texto(MCP_CP_MARGEM, $y + $i * 11.5, $l, 'normal', 8.4, MCP_CP_CINZA);
    }
    return [$pdf, $y + (count($rodape) - 1) * 11.5];
}
