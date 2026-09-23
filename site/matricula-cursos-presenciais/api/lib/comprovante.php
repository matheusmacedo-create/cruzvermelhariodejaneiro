<?php
/**
 * Comprovante de inscrição em PDF, anexado ao e-mail do aluno quando o pagamento confirma.
 *
 * O layout segue o modelo enviado pela filial em 23/09/2026 (comprovante da Punção Venosa de
 * 22/09): faixa vermelha, logo, título, caixa do participante com borda vermelha, dados da
 * inscrição, pagamento, empresa recebedora e rodapé. Cores, posições e tamanhos foram lidos do
 * próprio PDF modelo. A fonte é Helvetica (padrão do PDF, não precisa ser embutida) no lugar da
 * DejaVu do modelo; como a DejaVu é ~9% mais larga, o corpo sobe um pouco e as caixas altas ganham
 * espaçamento entre letras.
 *
 * Três ajustes em relação ao modelo, de propósito:
 *  - "CPF:" em vez de "CPF/CNPJ:": o checkout só aceita pessoa física;
 *  - rótulos em caixa de frase ("Método de pagamento"), como no resto do site — o modelo misturava
 *    "Data do Pedido" com "Código da compra";
 *  - o rodapé diz de onde vêm os dados (a transação na UnicoPag) em vez de "comprovante fornecido",
 *    que era o jeito de o modelo ter sido feito à mão.
 *
 * mcp_comprovante_pdf() é pura: recebe a inscrição e devolve os bytes. Se falhar, quem envia segue
 * sem o anexo — o e-mail do aluno nunca deixa de sair por causa do PDF.
 */
declare(strict_types=1);

const MCP_COMPROVANTE_LOGO = __DIR__ . '/comprovante-logo.png';
/**
 * Quem recebe os pagamentos da matrícula: a empresa de ensino da filial, com CNPJ próprio — não é o
 * CNPJ da filial (08.560.973/0001-97), que é o das doações. Dado tirado do comprovante UnicoPag de
 * uma compra real pelo checkout; RECEBEDOR_NOME e RECEBEDOR_CNPJ no config.php sobrepõem.
 */
const MCP_COMPROVANTE_RECEBEDOR = 'O-CVB FILIAL RIO DE JANEIRO ENSINO LTDA - EPP';
const MCP_COMPROVANTE_RECEBEDOR_CNPJ = '67.733.551/0001-35';

const MCP_CP_VERMELHO = '#e1262f';
const MCP_CP_TEXTO = '#24272b';
const MCP_CP_CINZA = '#667078';
const MCP_CP_CAIXA = '#f5f6f7';
const MCP_CP_FIO = '#e8e9eb';
const MCP_CP_MARGEM = 48.0;
const MCP_CP_VALOR_X = 225.0;   // coluna dos valores
const MCP_CP_ROTULO_X = 65.5;   // coluna dos rótulos (recuada, como no modelo)

/** Partículas que ficam minúsculas no meio do nome. */
const MCP_PARTICULAS = ['da', 'das', 'de', 'do', 'dos', 'e', 'di', 'du', 'del', 'van', 'von'];

/**
 * "joana maria dos santos" -> "Joana Maria dos Santos". Só sobe a primeira letra de cada
 * palavra (não baixa as outras, para não estragar "McDonald"); partícula fica minúscula; nome todo em
 * caixa alta é baixado antes, senão sairia "MARIA da SILVA".
 */
function mcp_nome_proprio(string $nome): string
{
    $nome = trim(preg_replace('/\s+/u', ' ', $nome) ?? '');
    if ($nome === '') {
        return '';
    }
    if ($nome === mb_strtoupper($nome)) {
        $nome = mb_strtolower($nome);
    }
    $palavras = explode(' ', $nome);
    foreach ($palavras as $i => $p) {
        $minuscula = mb_strtolower($p);
        $palavras[$i] = ($i > 0 && in_array($minuscula, MCP_PARTICULAS, true))
            ? $minuscula
            : mb_strtoupper(mb_substr($p, 0, 1)) . mb_substr($p, 1);
    }
    return implode(' ', $palavras);
}

function mcp_cpf_formatado(string $cpf): string
{
    $d = preg_replace('/\D+/', '', $cpf) ?? '';
    return strlen($d) === 11 ? substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9, 2) : $cpf;
}

/** "Cartão de crédito · Visa final 4242", ou "PIX". */
function mcp_comprovante_metodo(array $inscricao): string
{
    if (($inscricao['metodo'] ?? 'pix') === 'pix') {
        return 'PIX';
    }
    $bandeira = trim((string) ($inscricao['bandeira'] ?? ''));
    $final = trim((string) ($inscricao['ultimos4'] ?? ''));
    $detalhe = trim(($bandeira !== '' ? mb_convert_case($bandeira, MB_CASE_TITLE) : '') . ($final !== '' ? " final $final" : ''));
    return 'Cartão de crédito' . ($detalhe !== '' ? " · $detalhe" : '');
}

/**
 * O conteúdo do comprovante, sem desenho — é o que os testes conferem.
 *
 * @return array{titulo:string, subtitulo:string, nome:string, cpf:string,
 *   inscricao:list<array{0:string,1:string}>, pagamento:list<array{0:string,1:string}>,
 *   recebedor:string, recebedor_cnpj:string, rodape:string}
 */
function mcp_comprovante_conteudo(array $inscricao, ?string $agoraUtc = null): array
{
    $curso = trim((string) ($inscricao['curso_nome'] ?? ''));
    $produto = $curso . ' — Inscrição';
    $taxa = (int) ($inscricao['taxa_centavos'] ?? 0);
    $pedido = mcp_data_brt((string) ($inscricao['criado_em'] ?? ''), 'd/m/Y H:i');
    $pago = mcp_data_brt((string) ($inscricao['pago_em'] ?? ''), 'd/m/Y H:i');
    $hash = trim((string) ($inscricao['unicopag_hash'] ?? ''));

    $dados = [['Produto', $produto], ['Quantidade', '1'], ['Valor', mcp_brl((int) ($inscricao['inscricao_centavos'] ?? 0))]];
    if ($taxa > 0) {
        $dados[] = ['Custos de processamento', mcp_brl($taxa)];
    }
    $pagamento = [['Status do pagamento', 'Pago'], ['Método de pagamento', mcp_comprovante_metodo($inscricao)]];
    if ($pedido !== '') {
        $pagamento[] = ['Data do pedido', $pedido];
    }
    // Só mostra a data do pagamento quando difere da do pedido: no cartão é o mesmo minuto, e a linha
    // repetida só ocupa espaço; no PIX pago horas depois, é a data que interessa.
    if ($pago !== '' && $pago !== $pedido) {
        $pagamento[] = ['Data do pagamento', $pago];
    }
    $pagamento[] = ['Código da compra', $hash !== '' ? $hash : '—'];
    $pagamento[] = ['Total', mcp_brl((int) ($inscricao['total_centavos'] ?? 0))];

    $emitido = mcp_data_brt($agoraUtc ?? gmdate('Y-m-d H:i:s'), 'd/m/Y \à\s H\hi');
    $rodape = "Comprovante gerado automaticamente em $emitido (horário de Brasília)"
        . ($hash !== '' ? ", a partir da transação $hash processada pela UnicoPag" : '')
        . '. Dúvidas: ' . mcp_email_contato_endereco() . '.';

    return [
        'titulo' => 'COMPROVANTE DE INSCRIÇÃO',
        'subtitulo' => $produto,
        'nome' => mcp_nome_proprio((string) ($inscricao['nome'] ?? '')),
        'cpf' => 'CPF: ' . mcp_cpf_formatado((string) ($inscricao['cpf'] ?? '')),
        'inscricao' => $dados,
        'pagamento' => $pagamento,
        'recebedor' => (string) mcp_cfg('RECEBEDOR_NOME', MCP_COMPROVANTE_RECEBEDOR),
        'recebedor_cnpj' => 'CNPJ ' . (string) mcp_cfg('RECEBEDOR_CNPJ', MCP_COMPROVANTE_RECEBEDOR_CNPJ),
        'rodape' => $rodape,
    ];
}

/**
 * Os bytes do PDF. Lança exceção se algo falhar (o logo sumir, por exemplo).
 *
 * Cada linha a mais (custos de processamento, data do pagamento no PIX, nome ou curso em duas
 * linhas) empurra o resto para baixo. No pior caso realista o rodapé passaria da borda do A4, então
 * o desenho é feito com um fator de espaçamento e, se não couber, refeito mais apertado — só os
 * espaços entre linhas diminuem, nunca a fonte.
 */
function mcp_comprovante_pdf(array $inscricao, ?string $agoraUtc = null): string
{
    $c = mcp_comprovante_conteudo($inscricao, $agoraUtc);
    foreach ([1.0, 0.92, 0.85, 0.78, 0.72] as $fator) {
        [$pdf, $fimY] = mcp_comprovante_desenhar($c, $fator);
        if ($fimY <= McpPdf::ALTURA - 28) {
            break;
        }
    }
    return $pdf->gerar();
}

/**
 * Desenha o comprovante com os espaços verticais multiplicados por $f.
 *
 * @return array{0: McpPdf, 1: float} o PDF e a linha de base da última linha do rodapé
 */
function mcp_comprovante_desenhar(array $c, float $f): array
{
    $pdf = new McpPdf();
    $pdf->metadados('Comprovante de inscrição — ' . $c['subtitulo'], MCP_NOME_FILIAL, 'Comprovante de inscrição de ' . $c['nome']);
    $direita = McpPdf::LARGURA - MCP_CP_MARGEM;
    $largura = $direita - MCP_CP_MARGEM;

    // Faixa, logo (a tinta da cruz alinhada com a margem do texto) e fio. Cabeçalho não encolhe.
    $pdf->retangulo(0, 0, McpPdf::LARGURA, 8, MCP_CP_VERMELHO);
    $logoL = 345.0;
    $logoA = $logoL * 398 / 1325;
    $pdf->imagemPng(MCP_COMPROVANTE_LOGO, MCP_CP_MARGEM - 0.0257 * $logoL, 44 - 0.0427 * $logoA, $logoL, $logoA);
    $pdf->linha(MCP_CP_MARGEM, 158, $direita, 158, MCP_CP_FIO, 0.8);

    // Título e subtítulo (o subtítulo quebra se o nome do curso for longo).
    $pdf->texto(MCP_CP_MARGEM, 200.5, $c['titulo'], 'negrito', 20, MCP_CP_TEXTO, 0.5);
    $sub = McpPdf::quebrar($c['subtitulo'], 'normal', 12, $largura);
    foreach ($sub as $i => $l) {
        $pdf->texto(MCP_CP_MARGEM, 226 + $i * 15, $l, 'normal', 12, MCP_CP_CINZA);
    }
    $y = 226 + (count($sub) - 1) * 15;  // linha de base da última linha do subtítulo

    // Caixa do participante: faixa vermelha com os cantos de fora arredondados, cinza com os da direita.
    $nomeLinhas = McpPdf::quebrar($c['nome'], 'negrito', 14, $direita - 16 - MCP_CP_ROTULO_X);
    $topo = $y + 25.3;
    $altura = 81.2 + (count($nomeLinhas) - 1) * 17;
    $pdf->retanguloArredondado(MCP_CP_MARGEM, $topo, 13, $altura, [7, 0, 0, 7], MCP_CP_VERMELHO);
    $pdf->retanguloArredondado(MCP_CP_MARGEM + 5, $topo, $largura - 5, $altura, [0, 7, 7, 0], MCP_CP_CAIXA);
    $pdf->texto(MCP_CP_ROTULO_X, $topo + 25.8, 'PARTICIPANTE', 'negrito', 8.9, MCP_CP_CINZA, 0.9);
    foreach ($nomeLinhas as $i => $l) {
        $pdf->texto(MCP_CP_ROTULO_X, $topo + 49.2 + $i * 17, $l, 'negrito', 14, MCP_CP_TEXTO);
    }
    $pdf->texto(MCP_CP_ROTULO_X, $topo + 69.2 + (count($nomeLinhas) - 1) * 17, $c['cpf'], 'normal', 10.3, MCP_CP_TEXTO);
    $y = $topo + $altura;

    // Seções de linhas rótulo/valor. Devolve a linha de base da última linha.
    $secao = static function (string $titulo, array $linhas, float $y) use ($pdf, $direita, $f): float {
        $pdf->texto(MCP_CP_MARGEM, $y, $titulo, 'negrito', 10.1, MCP_CP_VERMELHO, 0.6);
        $pdf->linha(MCP_CP_MARGEM, $y + 10, $direita, $y + 10, MCP_CP_FIO, 0.8);
        $y += 10 + 22 * $f;
        $ultima = $y;
        foreach ($linhas as [$rotulo, $valor]) {
            $pdf->texto(MCP_CP_ROTULO_X, $y, $rotulo, 'normal', 10, MCP_CP_CINZA);
            $partes = McpPdf::quebrar($valor, 'negrito', 10.9, $direita - MCP_CP_VALOR_X);
            foreach ($partes as $i => $l) {
                $pdf->texto(MCP_CP_VALOR_X, $y + $i * 14, $l, 'negrito', 10.9, MCP_CP_TEXTO);
            }
            $ultima = $y + (count($partes) - 1) * 14;
            $y = $ultima + 28 * $f;
        }
        return $ultima;
    };
    $y = $secao('DADOS DA INSCRIÇÃO', $c['inscricao'], $y + 34.5 * $f);
    $y = $secao('PAGAMENTO', $c['pagamento'], $y + 41 * $f);

    // Empresa recebedora.
    $y += 41 * $f;
    $pdf->texto(MCP_CP_MARGEM, $y, 'EMPRESA RECEBEDORA', 'negrito', 10.1, MCP_CP_VERMELHO, 0.6);
    $pdf->linha(MCP_CP_MARGEM, $y + 10, $direita, $y + 10, MCP_CP_FIO, 0.8);
    $y += 10 + 25 * $f;
    $empresa = McpPdf::quebrar($c['recebedor'], 'negrito', 10.8, $largura);
    foreach ($empresa as $i => $l) {
        $pdf->texto(MCP_CP_MARGEM, $y + $i * 14, $l, 'negrito', 10.8, MCP_CP_TEXTO);
    }
    $y += (count($empresa) - 1) * 14 + 21;
    $pdf->texto(MCP_CP_MARGEM, $y, $c['recebedor_cnpj'], 'normal', 10.3, MCP_CP_TEXTO);

    // Rodapé.
    $y += 27 * $f;
    $pdf->linha(MCP_CP_MARGEM, $y, $direita, $y, MCP_CP_FIO, 0.8);
    $y += 17;
    $rodape = McpPdf::quebrar($c['rodape'], 'normal', 8.4, $largura);
    foreach ($rodape as $i => $l) {
        $pdf->texto(MCP_CP_MARGEM, $y + $i * 11.5, $l, 'normal', 8.4, MCP_CP_CINZA);
    }
    return [$pdf, $y + (count($rodape) - 1) * 11.5];
}

/** "Comprovante_de_Inscricao_Puncao_Venosa_Joana_Maria_dos_Santos.pdf" — o mesmo padrão do modelo. */
function mcp_comprovante_arquivo(array $inscricao): string
{
    $partes = 'Comprovante de Inscricao ' . (string) ($inscricao['curso_nome'] ?? '') . ' ' . mcp_nome_proprio((string) ($inscricao['nome'] ?? ''));
    $sem = strtr($partes, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
        'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e', 'É' => 'E', 'Ê' => 'E', 'È' => 'E', 'Ë' => 'E',
        'í' => 'i', 'î' => 'i', 'ì' => 'i', 'ï' => 'i', 'Í' => 'I', 'Î' => 'I', 'Ì' => 'I', 'Ï' => 'I',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ö' => 'o', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ò' => 'O', 'Ö' => 'O',
        'ú' => 'u', 'û' => 'u', 'ù' => 'u', 'ü' => 'u', 'Ú' => 'U', 'Û' => 'U', 'Ù' => 'U', 'Ü' => 'U',
        'ç' => 'c', 'Ç' => 'C', 'ñ' => 'n', 'Ñ' => 'N',
    ]);
    $sem = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $sem) ?? '', '_');
    $sem = $sem !== '' ? $sem : 'Comprovante_de_Inscricao';
    if (strlen($sem) > 120) {
        $corte = strrpos(substr($sem, 0, 121), '_');
        $sem = substr($sem, 0, $corte !== false && $corte > 40 ? $corte : 120);
    }
    return $sem . '.pdf';
}
