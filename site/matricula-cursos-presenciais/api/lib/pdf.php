<?php
/**
 * Gerador de PDF mínimo, sem biblioteca: páginas A4 com retângulos, cantos arredondados, fios,
 * texto em Helvetica, uma imagem PNG e fotos JPEG. O comprovante e as declarações cabem numa página; o termo de
 * adesão do voluntário usa novaPagina().
 *
 * Por que à mão: a Hostinger não tem Composer neste projeto, e o FPDF não é baixável daqui (o proxy
 * bloqueia o GitHub). O que o comprovante precisa cabe em poucas centenas de linhas e dispensa
 * dependência nova no servidor.
 *
 * - Fontes: as 14 padrão do PDF (Helvetica e Helvetica-Bold), que todo leitor tem e por isso não
 *   são embutidas. Texto em cp1252 (WinAnsiEncoding), que cobre o português inteiro e o travessão.
 *   As larguras abaixo são as métricas oficiais (AFM) de cada byte cp1252, em milésimos de em —
 *   é o que permite quebrar linha de nome de curso comprido sem estourar a margem.
 * - Imagem: PNG sem entrelaçamento e sem alfa, lido direto dos blocos IDAT, que já são zlib com os
 *   filtros PNG — o PDF desenha isso com /Predictor 15, sem decodificar nada aqui e sem GD.
 * - Fotos: JPEG entra como está (/DCTDecode), quantas forem; só as medidas são lidas do arquivo (imagemJpeg()).
 * - Coordenadas: a API recebe y a partir do TOPO da página (como se lê o layout); a conversão para a
 *   origem do PDF (canto inferior) fica aqui dentro.
 *
 * Tudo que pode falhar lança exceção: quem chama (o e-mail do aluno) decide seguir sem o anexo.
 */
declare(strict_types=1);

final class McpPdf
{
    public const LARGURA = 595.28;  // A4, em pontos
    public const ALTURA = 841.89;

    private const FONTES = ['normal' => ['F1', 'Helvetica'], 'negrito' => ['F2', 'Helvetica-Bold']];
    /** Métricas AFM por byte cp1252 (milésimos de em). Os controles não aparecem: o texto é limpo antes. */
    private const LARGURAS = [
        'Helvetica' => [
            761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761,
            761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761,
            278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
            1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
            333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
            556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584, 761,
            556, 0, 222, 556, 333, 1000, 556, 556, 333, 1000, 667, 333, 1000, 0, 611, 0,
            0, 222, 222, 333, 333, 350, 556, 1000, 333, 1000, 500, 333, 944, 0, 500, 667,
            278, 333, 556, 556, 556, 556, 260, 556, 333, 737, 370, 556, 584, 333, 737, 333,
            400, 584, 333, 333, 333, 556, 537, 278, 333, 333, 365, 556, 834, 834, 834, 611,
            667, 667, 667, 667, 667, 667, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
            722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
            556, 556, 556, 556, 556, 556, 889, 500, 556, 556, 556, 556, 278, 278, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 584, 611, 556, 556, 556, 556, 500, 556, 500,
        ],
        'Helvetica-Bold' => [
            761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761,
            761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761, 761,
            278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
            975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
            333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
            611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584, 761,
            556, 0, 278, 556, 500, 1000, 556, 556, 333, 1000, 667, 333, 1000, 0, 611, 0,
            0, 278, 278, 500, 500, 350, 556, 1000, 333, 1000, 556, 333, 944, 0, 500, 667,
            278, 333, 556, 556, 556, 556, 280, 556, 333, 737, 370, 556, 584, 333, 737, 333,
            400, 584, 333, 333, 333, 611, 556, 278, 333, 333, 365, 556, 834, 834, 834, 611,
            722, 722, 722, 722, 722, 722, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
            722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
            556, 556, 556, 556, 556, 556, 889, 556, 556, 556, 556, 556, 278, 278, 278, 278,
            611, 611, 611, 611, 611, 611, 611, 584, 611, 611, 611, 611, 611, 556, 611, 556,
        ],    ];

    private string $conteudo = '';
    /** @var list<string> páginas já fechadas por novaPagina(); a atual fica em $conteudo */
    private array $paginas = [];
    /** @var array{largura:int, altura:int, espaco:string, cores:int, bits:int, dados:string}|null */
    private ?array $imagem = null;
    /** @var list<array{largura:int, altura:int, espaco:string, decode:string, dados:string}> fotos JPEG, na ordem em que entraram */
    private array $jpegs = [];
    /** @var array<string, string> */
    private array $info = [];

    /** Fecha a página atual e começa outra, em branco. */
    public function novaPagina(): void
    {
        $this->paginas[] = $this->conteudo;
        $this->conteudo = '';
    }

    public function totalPaginas(): int
    {
        return count($this->paginas) + 1;
    }

    public function metadados(string $titulo, string $autor, string $assunto): void
    {
        $this->info = ['Title' => $titulo, 'Author' => $autor, 'Subject' => $assunto,
            'Creator' => 'cruzvermelhariodejaneiro.org', 'Producer' => 'cruzvermelhariodejaneiro.org'];
    }

    public function retangulo(float $x, float $y, float $l, float $a, string $cor): void
    {
        $this->conteudo .= self::rgb($cor) . ' rg ' . self::n($x) . ' ' . self::n(self::ALTURA - $y - $a) . ' '
            . self::n($l) . ' ' . self::n($a) . " re f\n";
    }

    /**
     * Retângulo com raio por canto: [superior esquerdo, superior direito, inferior direito, inferior
     * esquerdo]. Raio 0 dá canto vivo — é assim que a caixa do participante tem a faixa vermelha
     * arredondada por fora e a emenda com o cinza reta.
     */
    public function retanguloArredondado(float $x, float $y, float $l, float $a, array $raios, string $cor): void
    {
        [$se, $sd, $id, $ie] = array_map('floatval', $raios);
        $k = 0.5523;  // distância do ponto de controle de Bézier que aproxima um quarto de círculo
        $esq = $x; $dir = $x + $l; $topo = self::ALTURA - $y; $base = $topo - $a;
        $p = self::rgb($cor) . " rg\n" . self::n($esq + $se) . ' ' . self::n($topo) . " m\n"
            . self::n($dir - $sd) . ' ' . self::n($topo) . " l\n";
        if ($sd > 0) {
            $p .= self::curva($dir - $sd + $k * $sd, $topo, $dir, $topo - $sd + $k * $sd, $dir, $topo - $sd);
        }
        $p .= self::n($dir) . ' ' . self::n($base + $id) . " l\n";
        if ($id > 0) {
            $p .= self::curva($dir, $base + $id - $k * $id, $dir - $id + $k * $id, $base, $dir - $id, $base);
        }
        $p .= self::n($esq + $ie) . ' ' . self::n($base) . " l\n";
        if ($ie > 0) {
            $p .= self::curva($esq + $ie - $k * $ie, $base, $esq, $base + $ie - $k * $ie, $esq, $base + $ie);
        }
        $p .= self::n($esq) . ' ' . self::n($topo - $se) . " l\n";
        if ($se > 0) {
            $p .= self::curva($esq, $topo - $se + $k * $se, $esq + $se - $k * $se, $topo, $esq + $se, $topo);
        }
        $this->conteudo .= $p . "h f\n";
    }

    public function linha(float $x1, float $y1, float $x2, float $y2, string $cor, float $espessura = 1.0): void
    {
        $this->conteudo .= self::rgb($cor) . ' RG ' . self::n($espessura) . ' w ' . self::n($x1) . ' '
            . self::n(self::ALTURA - $y1) . ' m ' . self::n($x2) . ' ' . self::n(self::ALTURA - $y2) . " l S\n";
    }

    /** Texto numa linha, com a LINHA DE BASE em $yBase (a partir do topo). */
    public function texto(float $x, float $yBase, string $texto, string $estilo, float $tamanho, string $cor,
                          float $espacamento = 0.0): void
    {
        [$ref] = self::FONTES[$estilo] ?? throw new InvalidArgumentException("estilo desconhecido: $estilo");
        $this->conteudo .= 'BT /' . $ref . ' ' . self::n($tamanho) . ' Tf ' . self::n($espacamento) . ' Tc '
            . self::rgb($cor) . ' rg 1 0 0 1 ' . self::n($x) . ' ' . self::n(self::ALTURA - $yBase) . ' Tm ('
            . self::escaparString(self::cp1252($texto)) . ") Tj ET\n";
    }

    /** Largura do texto em pontos, com o espaçamento entre letras incluído. */
    public static function larguraTexto(string $texto, string $estilo, float $tamanho, float $espacamento = 0.0): float
    {
        $nome = (self::FONTES[$estilo] ?? throw new InvalidArgumentException("estilo desconhecido: $estilo"))[1];
        $bytes = self::cp1252($texto);
        $soma = 0;
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $soma += self::LARGURAS[$nome][ord($bytes[$i])];
        }
        return $soma * $tamanho / 1000 + $espacamento * strlen($bytes);
    }

    /**
     * Quebra em linhas que cabem em $largura, por palavra. Palavra maior que a linha inteira (um
     * e-mail, um código) é partida por caractere em vez de estourar a margem.
     *
     * @return list<string>
     */
    public static function quebrar(string $texto, string $estilo, float $tamanho, float $largura): array
    {
        $linhas = [];
        $atual = '';
        foreach (preg_split('/\s+/u', trim($texto)) ?: [] as $palavra) {
            if ($palavra === '') {
                continue;
            }
            $tentativa = $atual === '' ? $palavra : "$atual $palavra";
            if (self::larguraTexto($tentativa, $estilo, $tamanho) <= $largura) {
                $atual = $tentativa;
                continue;
            }
            if ($atual !== '') {
                $linhas[] = $atual;
            }
            $atual = $palavra;
            while (self::larguraTexto($atual, $estilo, $tamanho) > $largura && mb_strlen($atual) > 1) {
                $corte = mb_strlen($atual) - 1;
                while ($corte > 1 && self::larguraTexto(mb_substr($atual, 0, $corte), $estilo, $tamanho) > $largura) {
                    $corte--;
                }
                $linhas[] = mb_substr($atual, 0, $corte);
                $atual = mb_substr($atual, $corte);
            }
        }
        if ($atual !== '') {
            $linhas[] = $atual;
        }
        return $linhas ?: [''];
    }

    /** Desenha um PNG (paleta, RGB ou cinza; 8 bits; sem entrelaçamento; sem alfa). Uma imagem por documento. */
    public function imagemPng(string $caminho, float $x, float $y, float $l, float $a): void
    {
        $this->imagem = self::lerPng($caminho);
        $this->conteudo .= "q\n" . self::n($l) . ' 0 0 ' . self::n($a) . ' ' . self::n($x) . ' '
            . self::n(self::ALTURA - $y - $a) . " cm /Im1 Do\nQ\n";
    }

    /**
     * Desenha um JPEG (os bytes, não o caminho) cobrindo o retângulo: a foto é centrada e o que sobra fica de fora,
     * como o object-fit: cover da tela. Quantas fotos quiser por documento (as fichas dos voluntários, uma por pessoa).
     * O JPEG entra no PDF como está (/DCTDecode), sem decodificar nada aqui e sem GD.
     */
    public function imagemJpeg(string $jpeg, float $x, float $y, float $l, float $a): void
    {
        $im = self::lerJpeg($jpeg);
        $this->jpegs[] = $im;
        $escala = max($l / $im['largura'], $a / $im['altura']);
        $il = $im['largura'] * $escala;
        $ia = $im['altura'] * $escala;
        $base = self::ALTURA - $y - $a;
        $this->conteudo .= "q\n" . self::n($x) . ' ' . self::n($base) . ' ' . self::n($l) . ' ' . self::n($a) . " re W n\n"
            . self::n($il) . ' 0 0 ' . self::n($ia) . ' ' . self::n($x + ($l - $il) / 2) . ' ' . self::n($base + ($a - $ia) / 2)
            . ' cm /J' . count($this->jpegs) . " Do\nQ\n";
    }

    /** O arquivo PDF inteiro. */
    public function gerar(): string
    {
        $comprimir = function_exists('gzcompress');
        $filtro = $comprimir ? ' /Filter /FlateDecode' : '';
        // Página 1 nos objetos 3 (página) e 6 (conteúdo), como sempre; as seguintes, do 9 em diante, aos pares.
        $conteudos = [...$this->paginas, $this->conteudo];
        $numeros = [[3, 6]];
        for ($i = 1, $n = count($conteudos); $i < $n; $i++) {
            $numeros[] = [7 + 2 * $i, 8 + 2 * $i];
        }
        // As fotos JPEG vêm depois da última página.
        $primeiroJpeg = 7 + 2 * count($conteudos);
        $xobjetos = $this->imagem ? ' /Im1 7 0 R' : '';
        foreach ($this->jpegs as $i => $_) {
            $xobjetos .= ' /J' . ($i + 1) . ' ' . ($primeiroJpeg + $i) . ' 0 R';
        }
        $temImagem = $xobjetos !== '';
        $recursos = '/Font << /F1 4 0 R /F2 5 0 R >>' . ($temImagem ? ' /XObject <<' . $xobjetos . ' >>' : '')
            . ' /ProcSet [/PDF /Text' . ($temImagem ? ' /ImageB /ImageC /ImageI' : '') . ']';
        $objetos = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [' . implode(' ', array_map(static fn(array $p): string => $p[0] . ' 0 R', $numeros)) . '] /Count ' . count($numeros) . ' >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        foreach ($conteudos as $i => $conteudo) {
            [$pagina, $fluxoNum] = $numeros[$i];
            $fluxo = $comprimir ? gzcompress($conteudo, 9) : $conteudo;
            $objetos[$pagina] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::n(self::LARGURA) . ' ' . self::n(self::ALTURA)
                . '] /Resources << ' . $recursos . ' >> /Contents ' . $fluxoNum . ' 0 R >>';
            $objetos[$fluxoNum] = '<< /Length ' . strlen($fluxo) . $filtro . " >>\nstream\n" . $fluxo . "\nendstream";
        }
        if ($this->imagem) {
            $im = $this->imagem;
            $objetos[7] = '<< /Type /XObject /Subtype /Image /Width ' . $im['largura'] . ' /Height ' . $im['altura']
                . ' /ColorSpace ' . $im['espaco'] . ' /BitsPerComponent ' . $im['bits']
                . ' /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors ' . $im['cores'] . ' /BitsPerComponent '
                . $im['bits'] . ' /Columns ' . $im['largura'] . ' >> /Length ' . strlen($im['dados']) . " >>\nstream\n"
                . $im['dados'] . "\nendstream";
        }
        foreach ($this->jpegs as $i => $im) {
            $objetos[$primeiroJpeg + $i] = '<< /Type /XObject /Subtype /Image /Width ' . $im['largura'] . ' /Height ' . $im['altura']
                . ' /ColorSpace ' . $im['espaco'] . ' /BitsPerComponent 8' . $im['decode'] . ' /Filter /DCTDecode /Length '
                . strlen($im['dados']) . " >>\nstream\n" . $im['dados'] . "\nendstream";
        }
        $info = [];
        foreach ($this->info as $chave => $valor) {
            $info[] = '/' . $chave . ' ' . self::stringUnicode($valor);
        }
        $info[] = '/CreationDate (D:' . gmdate('YmdHis') . "Z)";
        $objetos[8] = '<< ' . implode(' ', $info) . ' >>';
        ksort($objetos);

        // "%âãÏÓ" na segunda linha avisa aos programas de transferência que o arquivo é binário.
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $posicoes = [];
        foreach ($objetos as $num => $corpo) {
            $posicoes[$num] = strlen($pdf);
            $pdf .= "$num 0 obj\n$corpo\nendobj\n";
        }
        $total = max(array_keys($objetos)) + 1;
        $xref = strlen($pdf);
        $pdf .= "xref\n0 $total\n0000000000 65535 f \n";
        for ($num = 1; $num < $total; $num++) {
            $pdf .= isset($posicoes[$num]) ? sprintf("%010d 00000 n \n", $posicoes[$num]) : "0000000000 65535 f \n";
        }
        return $pdf . "trailer\n<< /Size $total /Root 1 0 R /Info 8 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    // ------------------------------------------------------------------------------------ internos
    /** Número com no máximo duas casas e ponto decimal, independente da localidade do servidor. */
    private static function n(float $v): string
    {
        $s = rtrim(rtrim(sprintf('%.2F', $v), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }

    private static function curva(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): string
    {
        return self::n($x1) . ' ' . self::n($y1) . ' ' . self::n($x2) . ' ' . self::n($y2) . ' '
            . self::n($x3) . ' ' . self::n($y3) . " c\n";
    }

    /** "#e1262f" -> "0.882 0.149 0.184" (cor de preenchimento/traço em DeviceRGB). */
    private static function rgb(string $hex): string
    {
        if (!preg_match('/^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m)) {
            throw new InvalidArgumentException("cor inválida: $hex");
        }
        return implode(' ', array_map(static fn(string $c): string => sprintf('%.3F', hexdec($c) / 255), array_slice($m, 1)));
    }

    /**
     * UTF-8 -> cp1252. Controles saem (quebra de linha num nome não tem lugar no comprovante); o que
     * não existe em cp1252 vira "?" — nomes e cursos em português cabem inteiros.
     */
    private static function cp1252(string $texto): string
    {
        $texto = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $texto) ?? '';
        return mb_convert_encoding($texto, 'Windows-1252', 'UTF-8');
    }

    private static function escaparString(string $bytes): string
    {
        return strtr($bytes, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    /** Metadados em UTF-16BE com BOM, o único jeito de o /Info carregar acento de forma portável. */
    private static function stringUnicode(string $texto): string
    {
        return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding($texto, 'UTF-16BE', 'UTF-8'))) . '>';
    }

    /**
     * Medidas e cores de um JPEG, lidas do marcador SOF (o quadro). Só 8 bits por cor, que é o que câmera e GD gravam.
     *
     * @return array{largura:int, altura:int, espaco:string, decode:string, dados:string}
     */
    private static function lerJpeg(string $bin): array
    {
        $n = strlen($bin);
        if ($n < 4 || !str_starts_with($bin, "\xFF\xD8")) {
            throw new RuntimeException('a foto não é JPEG');
        }
        $pos = 2;
        while ($pos + 9 < $n) {
            if ($bin[$pos] !== "\xFF") {
                break;
            }
            $marcador = ord($bin[$pos + 1]);
            if ($marcador === 0xFF) {  // preenchimento entre marcadores
                $pos++;
                continue;
            }
            if ($marcador === 0xDA) {  // começo dos dados sem ter achado o quadro
                break;
            }
            $tamanho = unpack('n', substr($bin, $pos + 2, 2))[1];
            // SOF0 a SOF15, menos DHT (C4), JPG (C8) e DAC (CC).
            if ($marcador >= 0xC0 && $marcador <= 0xCF && !in_array($marcador, [0xC4, 0xC8, 0xCC], true)) {
                $q = unpack('Cbits/naltura/nlargura/Ccores', substr($bin, $pos + 4, 6));
                if ($q['bits'] !== 8 || $q['largura'] < 1 || $q['altura'] < 1) {
                    break;
                }
                // CMYK de JPEG (Adobe) vem invertido: o /Decode desinverte.
                [$espaco, $decode] = match ($q['cores']) {
                    1 => ['/DeviceGray', ''],
                    3 => ['/DeviceRGB', ''],
                    4 => ['/DeviceCMYK', ' /Decode [1 0 1 0 1 0 1 0]'],
                    default => throw new RuntimeException('JPEG com número de cores não suportado'),
                };
                return ['largura' => $q['largura'], 'altura' => $q['altura'], 'espaco' => $espaco, 'decode' => $decode, 'dados' => $bin];
            }
            $pos += 2 + $tamanho;
        }
        throw new RuntimeException('JPEG não suportado (sem o quadro de 8 bits)');
    }

    /** @return array{largura:int, altura:int, espaco:string, cores:int, bits:int, dados:string} */
    private static function lerPng(string $caminho): array
    {
        $bin = is_file($caminho) ? (string) file_get_contents($caminho) : '';
        if (strlen($bin) < 33 || substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new RuntimeException('imagem ausente ou não é PNG: ' . basename($caminho));
        }
        $pos = 8;
        $ihdr = null;
        $paleta = '';
        $dados = '';
        while ($pos + 8 <= strlen($bin)) {
            $tamanho = unpack('N', substr($bin, $pos, 4))[1];
            $tipo = substr($bin, $pos + 4, 4);
            $corpo = substr($bin, $pos + 8, $tamanho);
            if ($tipo === 'IHDR') {
                $ihdr = unpack('Nlargura/Naltura/Cbits/Ccor/Ccompressao/Cfiltro/Centrelacamento', $corpo);
            } elseif ($tipo === 'PLTE') {
                $paleta = $corpo;
            } elseif ($tipo === 'IDAT') {
                $dados .= $corpo;
            } elseif ($tipo === 'IEND') {
                break;
            }
            $pos += 12 + $tamanho;
        }
        if (!$ihdr || $dados === '' || $ihdr['bits'] !== 8 || $ihdr['entrelacamento'] !== 0) {
            throw new RuntimeException('PNG não suportado (precisa de 8 bits e sem entrelaçamento)');
        }
        $espaco = match ($ihdr['cor']) {
            0 => ['/DeviceGray', 1],
            2 => ['/DeviceRGB', 3],
            3 => $paleta !== '' ? ['[/Indexed /DeviceRGB ' . (intdiv(strlen($paleta), 3) - 1) . ' <' . bin2hex($paleta) . '>]', 1]
                : throw new RuntimeException('PNG de paleta sem PLTE'),
            default => throw new RuntimeException('PNG com alfa não é suportado: componha sobre branco antes'),
        };
        return ['largura' => $ihdr['largura'], 'altura' => $ihdr['altura'], 'espaco' => $espaco[0],
            'cores' => $espaco[1], 'bits' => 8, 'dados' => $dados];
    }
}
