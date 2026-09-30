<?php
/**
 * Ponto da sede (29/09/2026): colaboradores registram entrada e saída para contar as horas doadas à
 * instituição, e alunos registram a chegada às aulas presenciais (lib/presenca.php).
 *
 * Há dois jeitos de registrar, os dois na página /ponto/ (api/ponto.php):
 *  - aparelho da sede: um tablet ou computador da recepção que a secretaria liberou no portal. O
 *    aparelho guarda um cookie assinado (400 dias) e pode ser desativado no portal a qualquer hora;
 *  - celular da pessoa, pelo QR code impresso na sede: só vale com a localização do celular a até
 *    PONTO_RAIO_METROS (150 m) da sede. A localização não é guardada, só a distância em metros.
 *
 * A pessoa se identifica pelo CPF. Depois de conferir quem é e onde está, o servidor devolve uma
 * sessão de 3 minutos com o que conferiu: CPF, modo, distância e aulas do dia. O registro usa essa
 * sessão, sem consultar a escola de novo. A sessão vai cifrada (AES-256-GCM): quem mexer no aparelho
 * da recepção não lê CPF nem e-mail de quem usou antes, e ninguém consegue alterar o conteúdo.
 *
 * Horas: conta cada par entrada–saída. Uma entrada sem saída há mais de 16 horas é uma saída
 * esquecida. Ela não conta até a secretaria corrigir no portal, e a pessoa pode registrar outra entrada.
 *
 * Vínculo (30/09/2026): cada vínculo tem uma regra, para a instituição ficar coberta.
 *  - Diretoria e voluntários: horas doadas, declaração de horas e termo de adesão ao serviço
 *    voluntário (Lei 9.608/1998). O registro serve para reconhecer as horas, nunca para cobrar horário.
 *  - Empregados, terceirizados e outros: só a presença na sede, por segurança. Não soma horas, não tem
 *    correção nem declaração e é apagada depois de 90 dias: não é o ponto oficial dos empregados
 *    (Portaria MTP 671/2021) e não vira um segundo registro de jornada.
 * A natureza de cada registro fica gravada na entrada (mcp_ponto.voluntario): mudar o vínculo depois não
 * muda o que já foi registrado.
 */
declare(strict_types=1);

const MCP_PONTO_SEDE_PADRAO = [-22.91132, -43.18779];  // Praça da Cruz Vermelha, 10 (OpenStreetMap)
const MCP_PONTO_RAIO_PADRAO = 150;
/** Localização com precisão pior que isso (em metros) não serve para confirmar que a pessoa está na sede. */
const MCP_PONTO_PRECISAO_MAXIMA = 1000;
/** Folga dada pela imprecisão do GPS, somada ao raio, no máximo isto (em metros). */
const MCP_PONTO_FOLGA_PRECISAO = 100;
const MCP_PONTO_SESSAO_SEGUNDOS = 180;
const MCP_PONTO_ESQUECIDA_HORAS = 16;
const MCP_PONTO_MINIMO_SEGUNDOS = 60;
/** Um turno lançado ou corrigido no portal não passa disto (horas). */
const MCP_PONTO_TURNO_MAXIMO_HORAS = 16;
const MCP_PONTO_COOKIE_APARELHO = 'mcp_ponto_aparelho';
const MCP_PONTO_COOKIE_PESSOA = 'mcp_ponto_pessoa';
const MCP_PONTO_APARELHO_DIAS = 400;
const MCP_PONTO_PESSOA_DIAS = 180;
const MCP_PONTO_ORIGENS = ['aparelho' => 'Aparelho da sede', 'celular' => 'Celular', 'portal' => 'Portal'];
/** Consultas por CPF: [máximo, janela em segundos], por aparelho ou, no celular, por IP. */
const MCP_PONTO_LIMITE = ['aparelho' => [240, 600], 'celular' => [15, 600]];
const MCP_PONTO_FUSO = 'America/Sao_Paulo';
const MCP_PONTO_MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
const MCP_PONTO_VINCULOS = [
    'voluntario' => 'Voluntário',
    'diretoria' => 'Diretoria (voluntária)',
    'empregado' => 'Empregado (CLT)',
    'terceirizado' => 'Terceirizado',
    'outro' => 'Outro (estagiário, prestador de serviço)',
];
/** Vínculos que somam horas doadas e assinam o termo de adesão. */
const MCP_PONTO_VINCULOS_VOLUNTARIOS = ['voluntario', 'diretoria'];
/** Presença de quem não é voluntário: guardada por tantos dias e depois apagada (api/comparecimentos.php). */
const MCP_PONTO_PRESENCA_DIAS = 90;
/** Versão do texto do termo de adesão (mcp_ponto_termo_conteudo). Mudou o texto, muda a versão. */
const MCP_PONTO_TERMO_MODELO = '2026-09';

// ----------------------------------------------------------------------------- assinatura, cifra e sessão
function mcp_ponto_assinar(string $dados): string
{
    return substr(hash_hmac('sha256', $dados, mcp_segredo('ponto')), 0, 40);
}

function mcp_ponto_b64(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function mcp_ponto_deb64(string $texto): string|false
{
    return base64_decode(strtr($texto, '-_', '+/'), true);
}

/** Chave de cifra para cada uso (sessão, pessoa lembrada), derivada do segredo guardado no banco. */
function mcp_ponto_chave(string $uso): string
{
    return hash('sha256', "$uso|" . mcp_segredo('ponto'), true);
}

/** AES-256-GCM: cifra e autentica. Devolve base64url(iv + tag + cifra). */
function mcp_ponto_cifrar(string $uso, string $texto): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cifra = openssl_encrypt($texto, 'aes-256-gcm', mcp_ponto_chave($uso), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cifra === false) {
        throw new RuntimeException('Não foi possível cifrar.');
    }
    return mcp_ponto_b64($iv . $tag . $cifra);
}

/** O texto original, ou null se o valor foi alterado, é de outro uso ou não é uma cifra nossa. */
function mcp_ponto_decifrar(string $uso, string $valor): ?string
{
    $bruto = preg_match('/^[A-Za-z0-9_-]{38,12000}$/', $valor) ? mcp_ponto_deb64($valor) : false;
    if ($bruto === false || strlen($bruto) < 29) {
        return null;
    }
    $texto = openssl_decrypt(substr($bruto, 28), 'aes-256-gcm', mcp_ponto_chave($uso), OPENSSL_RAW_DATA, substr($bruto, 0, 12), substr($bruto, 12, 16));
    return is_string($texto) ? $texto : null;
}

/** Sessão de identificação: o que o servidor conferiu, cifrado, válido por 3 minutos. */
function mcp_ponto_sessao_criar(array $dados, ?int $agora = null): string
{
    return mcp_ponto_cifrar('sessao', (string) json_encode(['x' => ($agora ?? time()) + MCP_PONTO_SESSAO_SEGUNDOS] + $dados, JSON_UNESCAPED_UNICODE));
}

function mcp_ponto_sessao_ler(mixed $sessao, ?int $agora = null): ?array
{
    $texto = is_string($sessao) ? mcp_ponto_decifrar('sessao', $sessao) : null;
    $dados = $texto !== null ? json_decode($texto, true) : null;
    return is_array($dados) && (int) ($dados['x'] ?? 0) >= ($agora ?? time()) ? $dados : null;
}

// ----------------------------------------------------------------------------- datas (Brasília)
function mcp_ponto_fuso(): DateTimeZone
{
    return new DateTimeZone(MCP_PONTO_FUSO);
}

/** Data de hoje em Brasília (AAAA-MM-DD). */
function mcp_ponto_hoje(?int $agora = null): string
{
    return (new DateTimeImmutable('@' . ($agora ?? time())))->setTimezone(mcp_ponto_fuso())->format('Y-m-d');
}

function mcp_ponto_mes_atual(?int $agora = null): string
{
    return substr(mcp_ponto_hoje($agora), 0, 7);
}

function mcp_ponto_mes_valido(string $mes): bool
{
    return (bool) preg_match('/^(20[2-9]\d)-(0[1-9]|1[0-2])$/', $mes);
}

/** Horário local de Brasília (AAAA-MM-DD HH:MM[:SS]) em UTC, ou null se não for uma data válida. */
function mcp_ponto_local_para_utc(string $local): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $local)) {
        return null;
    }
    $data = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', str_replace('T', ' ', strlen($local) === 16 ? $local . ':00' : $local), mcp_ponto_fuso());
    $erros = DateTimeImmutable::getLastErrors();
    if (!$data || ($erros && ($erros['warning_count'] || $erros['error_count']))) {
        return null;
    }
    return $data->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Início e fim (UTC) de um período local [de, ate) dado por datas AAAA-MM-DD de Brasília. */
function mcp_ponto_periodo_utc(string $deIso, string $ateIsoExclusivo): array
{
    return [(string) mcp_ponto_local_para_utc("$deIso 00:00:00"), (string) mcp_ponto_local_para_utc("$ateIsoExclusivo 00:00:00")];
}

/** Primeiro dia do mês e primeiro dia do mês seguinte (AAAA-MM-DD). */
function mcp_ponto_mes_dias(string $mes): array
{
    $ini = new DateTimeImmutable($mes . '-01');
    return [$ini->format('Y-m-d'), $ini->modify('+1 month')->format('Y-m-d')];
}

function mcp_ponto_mes_nome(string $mes): string
{
    return MCP_PONTO_MESES[(int) substr($mes, 5, 2) - 1] . ' de ' . substr($mes, 0, 4);
}

function mcp_ponto_mes_vizinho(string $mes, int $passo): string
{
    return (new DateTimeImmutable($mes . '-01'))->modify(($passo >= 0 ? '+' : '') . $passo . ' month')->format('Y-m');
}

/** "4h10", "0h05", "22h30". */
function mcp_ponto_horas_texto(int $minutos): string
{
    return intdiv(max(0, $minutos), 60) . 'h' . str_pad((string) (max(0, $minutos) % 60), 2, '0', STR_PAD_LEFT);
}

/** "22 horas e 30 minutos", para a declaração. */
function mcp_ponto_horas_extenso(int $minutos): string
{
    $h = intdiv(max(0, $minutos), 60);
    $m = max(0, $minutos) % 60;
    $horas = $h === 1 ? '1 hora' : "$h horas";
    return $m === 0 ? $horas : $horas . ' e ' . ($m === 1 ? '1 minuto' : "$m minutos");
}

// ----------------------------------------------------------------------------- localização
function mcp_ponto_sede(): array
{
    $lat = mcp_cfg('PONTO_SEDE_LAT', MCP_PONTO_SEDE_PADRAO[0]);
    $lng = mcp_cfg('PONTO_SEDE_LNG', MCP_PONTO_SEDE_PADRAO[1]);
    return [
        'lat' => is_numeric($lat) ? (float) $lat : MCP_PONTO_SEDE_PADRAO[0],
        'lng' => is_numeric($lng) ? (float) $lng : MCP_PONTO_SEDE_PADRAO[1],
        'raio' => max(30, (int) mcp_cfg('PONTO_RAIO_METROS', MCP_PONTO_RAIO_PADRAO)),
    ];
}

/** Distância em metros entre dois pontos (haversine). */
function mcp_ponto_distancia(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * 6371000.0 * asin(min(1.0, sqrt($a)));
}

function mcp_ponto_distancia_texto(int $metros): string
{
    return $metros >= 1000 ? str_replace('.', ',', (string) round($metros / 1000, 1)) . ' km' : $metros . ' m';
}

/**
 * Confere a localização mandada pelo celular ({lat, lng, precisao}).
 * @return array{ok: bool, distancia: ?int, erro: ?string}
 */
function mcp_ponto_conferir_localizacao(mixed $posicao): array
{
    $sem = 'Para registrar pelo celular, permita que a página use a localização. Ela só confirma que você está na sede e não fica guardada.';
    if (!is_array($posicao) || !is_numeric($posicao['lat'] ?? null) || !is_numeric($posicao['lng'] ?? null)) {
        return ['ok' => false, 'distancia' => null, 'erro' => $sem];
    }
    $lat = (float) $posicao['lat'];
    $lng = (float) $posicao['lng'];
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return ['ok' => false, 'distancia' => null, 'erro' => $sem];
    }
    $precisao = is_numeric($posicao['precisao'] ?? null) ? max(0.0, (float) $posicao['precisao']) : MCP_PONTO_PRECISAO_MAXIMA + 1.0;
    if ($precisao > MCP_PONTO_PRECISAO_MAXIMA) {
        return ['ok' => false, 'distancia' => null, 'erro' => 'A localização do celular está imprecisa agora. Ative a localização precisa, espere alguns segundos e tente de novo, ou use o aparelho da recepção.'];
    }
    $sede = mcp_ponto_sede();
    $distancia = (int) round(mcp_ponto_distancia($lat, $lng, $sede['lat'], $sede['lng']));
    if ($distancia > $sede['raio'] + min($precisao, MCP_PONTO_FOLGA_PRECISAO)) {
        return ['ok' => false, 'distancia' => $distancia, 'erro' => 'Você está a ' . mcp_ponto_distancia_texto($distancia) . ' da sede. Pelo celular, o registro só vale na sede.'];
    }
    return ['ok' => true, 'distancia' => min($distancia, 65535), 'erro' => null];
}

// ----------------------------------------------------------------------------- aparelhos da sede
function mcp_ponto_cookie_opcoes(int $expira): array
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ['expires' => $expira, 'path' => MCP_PAINEL_CAMINHO_COOKIE, 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax'];
}

function mcp_ponto_aparelho_cookie_valor(int $id): string
{
    return $id . '.' . mcp_ponto_assinar("a|$id");
}

function mcp_ponto_aparelho_id_do_cookie(string $valor): ?int
{
    return preg_match('/^(\d{1,9})\.([a-f0-9]{40})$/', $valor, $m) && hash_equals(mcp_ponto_assinar("a|$m[1]"), $m[2]) ? (int) $m[1] : null;
}

/** O aparelho da sede que fez o pedido: cookie assinado e aparelho ainda ativo no portal. */
function mcp_ponto_aparelho_atual(): ?array
{
    $id = mcp_ponto_aparelho_id_do_cookie((string) ($_COOKIE[MCP_PONTO_COOKIE_APARELHO] ?? ''));
    if ($id === null) {
        return null;
    }
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_ponto_aparelhos WHERE id = ? AND ativo = 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function mcp_ponto_aparelho_criar(string $nome, string $quem): int
{
    mcp_db()->prepare('INSERT INTO mcp_ponto_aparelhos (nome, ativo, criado_por, criado_em) VALUES (?, 1, ?, ?)')->execute([$nome, $quem, mcp_agora()]);
    return (int) mcp_db()->lastInsertId();
}

/** Grava no navegador o cookie do aparelho (quem libera é a secretaria, no portal). */
function mcp_ponto_aparelho_liberar(int $id): void
{
    $valor = mcp_ponto_aparelho_cookie_valor($id);
    setcookie(MCP_PONTO_COOKIE_APARELHO, $valor, mcp_ponto_cookie_opcoes(time() + MCP_PONTO_APARELHO_DIAS * 86400));
    $_COOKIE[MCP_PONTO_COOKIE_APARELHO] = $valor;
}

function mcp_ponto_aparelho_usado(int $id): void
{
    mcp_db()->prepare('UPDATE mcp_ponto_aparelhos SET usado_em = ? WHERE id = ?')->execute([mcp_agora(), $id]);
}

function mcp_ponto_aparelho_desativar(int $id): void
{
    mcp_db()->prepare('UPDATE mcp_ponto_aparelhos SET ativo = 0 WHERE id = ?')->execute([$id]);
}

function mcp_ponto_aparelhos_listar(): array
{
    return mcp_db()->query('SELECT * FROM mcp_ponto_aparelhos ORDER BY ativo DESC, id DESC')->fetchAll();
}

// ----------------------------------------------------------------------------- pessoa lembrada no celular
/** O CPF vai cifrado no cookie: quem abrir os cookies do celular não lê o número. */
function mcp_ponto_pessoa_lembrar(string $cpf): void
{
    setcookie(MCP_PONTO_COOKIE_PESSOA, mcp_ponto_cifrar('pessoa', $cpf), mcp_ponto_cookie_opcoes(time() + MCP_PONTO_PESSOA_DIAS * 86400));
}

function mcp_ponto_pessoa_lembrada(): ?string
{
    $cpf = mcp_ponto_decifrar('pessoa', (string) ($_COOKIE[MCP_PONTO_COOKIE_PESSOA] ?? ''));
    return $cpf !== null && mcp_cpf_valido($cpf) ? $cpf : null;
}

function mcp_ponto_pessoa_esquecer(): void
{
    setcookie(MCP_PONTO_COOKIE_PESSOA, '', mcp_ponto_cookie_opcoes(1));
    unset($_COOKIE[MCP_PONTO_COOKIE_PESSOA]);
}

// ----------------------------------------------------------------------------- colaboradores
function mcp_colaborador_por_cpf(string $cpf): ?array
{
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_colaboradores WHERE cpf = ?');
    $stmt->execute([$cpf]);
    return $stmt->fetch() ?: null;
}

function mcp_colaborador_por_id(int $id): ?array
{
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_colaboradores WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function mcp_colaboradores_listar(): array
{
    return mcp_db()->query('SELECT * FROM mcp_colaboradores ORDER BY ativo DESC, nome')->fetchAll();
}

/**
 * Confere o formulário de cadastro do portal.
 * @return array{ok: bool, dados?: array, campo?: string, erro?: string}
 */
function mcp_colaborador_conferir(array $f): array
{
    $nome = mcp_texto($f['nome'] ?? '', 160);
    if (mb_strlen($nome) < 5 || !str_contains($nome, ' ')) {
        return ['ok' => false, 'campo' => 'nome', 'erro' => 'Escreva o nome completo.'];
    }
    $cpf = mcp_digitos((string) ($f['cpf'] ?? ''));
    if (!mcp_cpf_valido($cpf)) {
        return ['ok' => false, 'campo' => 'cpf', 'erro' => 'CPF inválido. Confira os números.'];
    }
    $email = mb_strtolower(mcp_texto($f['email'] ?? '', 190));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'campo' => 'email', 'erro' => 'E-mail inválido.'];
    }
    $telefoneBruto = mcp_texto($f['telefone'] ?? '', 30);
    $telefone = $telefoneBruto !== '' ? mcp_telefone($telefoneBruto) : '';
    if ($telefoneBruto !== '' && $telefone === '') {
        return ['ok' => false, 'campo' => 'telefone', 'erro' => 'Telefone inválido: escreva o DDD e o número.'];
    }
    $vinculo = mcp_texto($f['vinculo'] ?? '', 12);
    if (!isset(MCP_PONTO_VINCULOS[$vinculo])) {
        return ['ok' => false, 'campo' => 'vinculo', 'erro' => 'Escolha o vínculo com a instituição.'];
    }
    return ['ok' => true, 'dados' => [
        'nome' => $nome, 'cpf' => $cpf, 'email' => $email !== '' ? $email : null, 'telefone' => $telefone !== '' ? $telefone : null,
        'funcao' => mcp_texto($f['funcao'] ?? '', 120) ?: null, 'vinculo' => $vinculo, 'ativo' => !empty($f['ativo']) ? 1 : 0,
    ]];
}

/** Diretoria e voluntários somam horas doadas; os demais vínculos registram só a presença. */
function mcp_ponto_voluntario(array $colaborador): bool
{
    return in_array((string) ($colaborador['vinculo'] ?? ''), MCP_PONTO_VINCULOS_VOLUNTARIOS, true);
}

function mcp_ponto_vinculo_nome(array $colaborador): string
{
    return MCP_PONTO_VINCULOS[(string) ($colaborador['vinculo'] ?? '')] ?? (string) ($colaborador['vinculo'] ?? '');
}

/** Voluntário ou diretoria, ativo, sem o termo de adesão registrado. */
function mcp_ponto_termo_pendente(array $colaborador): bool
{
    return mcp_ponto_voluntario($colaborador) && (int) $colaborador['ativo'] && empty($colaborador['termo_em']);
}

/** Grava o colaborador ($id null = novo). Devolve o id, ou null se o CPF já é de outra pessoa. */
function mcp_colaborador_salvar(?int $id, array $d, string $quem): ?int
{
    $outro = mcp_colaborador_por_cpf($d['cpf']);
    if ($outro && (int) $outro['id'] !== (int) $id) {
        return null;
    }
    $agora = mcp_agora();
    if ($id === null) {
        mcp_db()->prepare('INSERT INTO mcp_colaboradores (nome, cpf, email, telefone, funcao, vinculo, ativo, criado_por, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$d['nome'], $d['cpf'], $d['email'], $d['telefone'], $d['funcao'], $d['vinculo'], $d['ativo'], $quem, $agora, $agora]);
        return (int) mcp_db()->lastInsertId();
    }
    // Desligamento: a data fica ao desativar e sai ao reativar (vale para o prazo de guarda dos registros).
    $antes = mcp_colaborador_por_id($id);
    $desligado = $antes['desligado_em'] ?? null;
    if (!$d['ativo'] && ($antes === null || (int) $antes['ativo'])) {
        $desligado = mcp_ponto_hoje();
    } elseif ($d['ativo']) {
        $desligado = null;
    }
    mcp_db()->prepare('UPDATE mcp_colaboradores SET nome = ?, cpf = ?, email = ?, telefone = ?, funcao = ?, vinculo = ?, ativo = ?, desligado_em = ?, atualizado_em = ? WHERE id = ?')
        ->execute([$d['nome'], $d['cpf'], $d['email'], $d['telefone'], $d['funcao'], $d['vinculo'], $d['ativo'], $desligado, $agora, $id]);
    return $id;
}

/**
 * Registra (data em Brasília) ou remove ($data null) o termo de adesão assinado. Devolve a mensagem do
 * erro, ou null. Só diretoria e voluntários assinam o termo.
 */
function mcp_ponto_termo_registrar(array $colaborador, ?string $data, string $quem, ?int $agora = null): ?string
{
    $agora ??= time();
    if ($data !== null) {
        if (!mcp_ponto_voluntario($colaborador)) {
            return 'O termo de adesão é só para voluntários e diretoria.';
        }
        $dia = DateTimeImmutable::createFromFormat('!Y-m-d', $data, mcp_ponto_fuso());
        if (!$dia || $dia->format('Y-m-d') !== $data) {
            return 'Data inválida.';
        }
        if ($data > mcp_ponto_hoje($agora)) {
            return 'A data da assinatura não pode ser no futuro.';
        }
    }
    $antes = $colaborador['termo_em'] ?: 'sem termo';
    mcp_db()->prepare('UPDATE mcp_colaboradores SET termo_em = ?, termo_modelo = ?, termo_registrado_por = ?, termo_registrado_em = ?, atualizado_em = ? WHERE id = ?')
        ->execute([$data, $data !== null ? MCP_PONTO_TERMO_MODELO : null, $data !== null ? $quem : null, $data !== null ? gmdate('Y-m-d H:i:s', $agora) : null,
            gmdate('Y-m-d H:i:s', $agora), (int) $colaborador['id']]);
    mcp_registrar(null, 'ponto_termo', '#' . $colaborador['id'] . " · $quem · $antes → " . ($data ?? 'removido'));
    return null;
}

// ----------------------------------------------------------------------------- entrada e saída
function mcp_ponto_limite_aberto(int $agora): string
{
    return gmdate('Y-m-d H:i:s', $agora - MCP_PONTO_ESQUECIDA_HORAS * 3600);
}

/** Entrada sem saída das últimas 16 horas (a pessoa está na sede), ou null. */
function mcp_ponto_aberto(int $colaboradorId, ?int $agora = null): ?array
{
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_ponto WHERE colaborador_id = ? AND saida IS NULL AND entrada > ? ORDER BY entrada DESC LIMIT 1');
    $stmt->execute([$colaboradorId, mcp_ponto_limite_aberto($agora ?? time())]);
    return $stmt->fetch() ?: null;
}

function mcp_ponto_registro(int $id): ?array
{
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_ponto WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Entrada ou saída pelo ponto. Trava a linha do colaborador: dois toques seguidos não viram duas entradas.
 * @return array{ok: bool, registro?: array, erro?: string, codigo?: string}
 */
function mcp_ponto_registrar(array $colaborador, string $tipo, string $origem, ?int $aparelhoId, ?int $distancia, ?int $agora = null): array
{
    $agora ??= time();
    $pdo = mcp_db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM mcp_colaboradores WHERE id = ? FOR UPDATE')->execute([(int) $colaborador['id']]);
        $aberto = mcp_ponto_aberto((int) $colaborador['id'], $agora);
        $quando = gmdate('Y-m-d H:i:s', $agora);
        if ($tipo === 'entrada') {
            if ($aberto) {
                $pdo->rollBack();
                return ['ok' => false, 'codigo' => 'ja_na_sede', 'erro' => 'Você já registrou a entrada às ' . mcp_data_brt($aberto['entrada'], 'H:i') . '. Para ir embora, registre a saída.'];
            }
            $pdo->prepare('INSERT INTO mcp_ponto (colaborador_id, voluntario, entrada, origem_entrada, aparelho_entrada, distancia_entrada, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([(int) $colaborador['id'], mcp_ponto_voluntario($colaborador) ? 1 : 0, $quando, $origem, $aparelhoId, $distancia, $quando, $quando]);
            $id = (int) $pdo->lastInsertId();
        } else {
            if (!$aberto) {
                $pdo->rollBack();
                return ['ok' => false, 'codigo' => 'sem_entrada', 'erro' => 'Não há entrada registrada hoje. Registre a entrada primeiro.'];
            }
            if ($agora - (int) strtotime($aberto['entrada'] . ' UTC') < MCP_PONTO_MINIMO_SEGUNDOS) {
                $pdo->rollBack();
                return ['ok' => false, 'codigo' => 'cedo', 'erro' => 'Você registrou a entrada agora há pouco. Espere um minuto para registrar a saída.'];
            }
            $pdo->prepare('UPDATE mcp_ponto SET saida = ?, origem_saida = ?, aparelho_saida = ?, distancia_saida = ?, atualizado_em = ? WHERE id = ?')
                ->execute([$quando, $origem, $aparelhoId, $distancia, $quando, (int) $aberto['id']]);
            $id = (int) $aberto['id'];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['ok' => true, 'registro' => mcp_ponto_registro($id)];
}

/** Minutos doados: pares entrada–saída de voluntário cuja entrada cai em [de, ate) (UTC). */
function mcp_ponto_minutos(int $colaboradorId, string $deUtc, string $ateUtc): int
{
    $stmt = mcp_db()->prepare('SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, entrada, saida)), 0) FROM mcp_ponto
        WHERE colaborador_id = ? AND voluntario = 1 AND saida IS NOT NULL AND entrada >= ? AND entrada < ?');
    $stmt->execute([$colaboradorId, $deUtc, $ateUtc]);
    return intdiv((int) $stmt->fetchColumn(), 60);
}

/**
 * O que o colaborador vê no ponto depois do CPF: se está na sede e, para voluntários e diretoria, as horas
 * doadas hoje e no mês. Para os outros vínculos, só a presença: nada de horas (horas = false).
 */
function mcp_ponto_resumo(array $colaborador, ?int $agora = null): array
{
    $agora ??= time();
    $id = (int) $colaborador['id'];
    $aberto = mcp_ponto_aberto($id, $agora);
    $hoje = mcp_ponto_hoje($agora);
    if (!mcp_ponto_voluntario($colaborador)) {
        return [
            'na_sede' => $aberto !== null, 'desde' => $aberto ? mcp_data_brt($aberto['entrada'], 'H:i') : null, 'horas' => false,
            'agora' => null, 'hoje' => null, 'mes' => null, 'mes_nome' => null, 'termo_pendente' => false,
        ];
    }
    [$diaDe, $diaAte] = mcp_ponto_periodo_utc($hoje, (new DateTimeImmutable($hoje))->modify('+1 day')->format('Y-m-d'));
    [$mesIni, $mesFim] = mcp_ponto_mes_dias(substr($hoje, 0, 7));
    [$mesDe, $mesAte] = mcp_ponto_periodo_utc($mesIni, $mesFim);
    $hojeMin = mcp_ponto_minutos($id, $diaDe, $diaAte);
    $mesMin = mcp_ponto_minutos($id, $mesDe, $mesAte);
    $abertoMin = $aberto ? intdiv($agora - (int) strtotime($aberto['entrada'] . ' UTC'), 60) : 0;
    return [
        'na_sede' => $aberto !== null,
        'desde' => $aberto ? mcp_data_brt($aberto['entrada'], 'H:i') : null,
        'horas' => true,
        'agora' => $aberto ? mcp_ponto_horas_texto($abertoMin) : null,
        'hoje' => mcp_ponto_horas_texto($hojeMin),
        'mes' => mcp_ponto_horas_texto($mesMin),
        'mes_nome' => mcp_ponto_mes_nome(substr($hoje, 0, 7)),
        'termo_pendente' => empty($colaborador['termo_em']),
    ];
}

// ----------------------------------------------------------------------------- portal: relatórios e correções
/**
 * Registros com entrada em [de, ate) (UTC), de todos ou de um colaborador, em ordem. $voluntario filtra
 * pela natureza do registro (true = horas doadas, false = só presença, null = todos).
 */
function mcp_ponto_registros(string $deUtc, string $ateUtc, ?int $colaboradorId = null, ?bool $voluntario = null): array
{
    $sql = 'SELECT p.*, c.nome, c.funcao, c.vinculo FROM mcp_ponto p JOIN mcp_colaboradores c ON c.id = p.colaborador_id WHERE p.entrada >= ? AND p.entrada < ?';
    $params = [$deUtc, $ateUtc];
    if ($colaboradorId !== null) {
        $sql .= ' AND p.colaborador_id = ?';
        $params[] = $colaboradorId;
    }
    if ($voluntario !== null) {
        $sql .= ' AND p.voluntario = ?';
        $params[] = $voluntario ? 1 : 0;
    }
    $stmt = mcp_db()->prepare($sql . ' ORDER BY p.entrada, p.id');
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Duração de um registro em segundos (0 se não tem saída). */
function mcp_ponto_segundos(array $r): int
{
    return $r['saida'] !== null ? max(0, (int) strtotime($r['saida'] . ' UTC') - (int) strtotime($r['entrada'] . ' UTC')) : 0;
}

function mcp_ponto_esquecido(array $r, int $agora): bool
{
    return $r['saida'] === null && (int) strtotime($r['entrada'] . ' UTC') <= $agora - MCP_PONTO_ESQUECIDA_HORAS * 3600;
}

/**
 * Horas doadas de um período por colaborador: ativos (mesmo sem horas) e inativos com registro no
 * período. Só os registros de voluntário contam horas, dias e saídas esquecidas; voluntario diz a regra
 * do vínculo atual (horas ou só presença). Os dias são contados no calendário de Brasília.
 */
function mcp_ponto_relatorio(string $deIso, string $ateIsoExclusivo, ?int $agora = null): array
{
    $agora ??= time();
    [$de, $ate] = mcp_ponto_periodo_utc($deIso, $ateIsoExclusivo);
    $por = [];
    $presentes = [];
    foreach (mcp_ponto_registros($de, $ate, null, false) as $r) {
        $presentes[(int) $r['colaborador_id']] = true;
    }
    foreach (mcp_ponto_registros($de, $ate, null, true) as $r) {
        $c = (int) $r['colaborador_id'];
        $por[$c] ??= ['segundos' => 0, 'dias' => [], 'esquecidas' => 0];
        if ($r['saida'] !== null) {
            $por[$c]['segundos'] += mcp_ponto_segundos($r);
            $por[$c]['dias'][mcp_data_brt($r['entrada'], 'Y-m-d')] = true;
        } elseif (mcp_ponto_esquecido($r, $agora)) {
            $por[$c]['esquecidas']++;
        }
    }
    $ultimas = [];
    foreach (mcp_db()->query('SELECT colaborador_id, MAX(entrada) AS ultima FROM mcp_ponto GROUP BY colaborador_id')->fetchAll() as $u) {
        $ultimas[(int) $u['colaborador_id']] = $u['ultima'];
    }
    $abertos = [];
    foreach (mcp_ponto_na_sede($agora) as $a) {
        $abertos[(int) $a['colaborador_id']] = $a;
    }
    $linhas = [];
    foreach (mcp_colaboradores_listar() as $col) {
        $id = (int) $col['id'];
        $x = $por[$id] ?? null;
        if (!(int) $col['ativo'] && $x === null && !isset($presentes[$id])) {
            continue;
        }
        $linhas[] = $col + [
            'minutos' => intdiv($x['segundos'] ?? 0, 60), 'dias' => count($x['dias'] ?? []), 'esquecidas' => $x['esquecidas'] ?? 0,
            'ultima' => $ultimas[$id] ?? null, 'aberto' => $abertos[$id] ?? null,
            'voluntario' => mcp_ponto_voluntario($col), 'termo_pendente' => mcp_ponto_termo_pendente($col),
        ];
    }
    return $linhas;
}

/** Quem está na sede agora (entrada sem saída nas últimas 16 horas), por ordem de chegada. */
function mcp_ponto_na_sede(?int $agora = null): array
{
    $stmt = mcp_db()->prepare('SELECT p.*, c.nome, c.funcao, c.vinculo FROM mcp_ponto p JOIN mcp_colaboradores c ON c.id = p.colaborador_id
        WHERE p.saida IS NULL AND p.entrada > ? ORDER BY p.entrada');
    $stmt->execute([mcp_ponto_limite_aberto($agora ?? time())]);
    return $stmt->fetchAll();
}

/**
 * Saídas esquecidas de voluntário (entrada sem saída há mais de 16 horas), em qualquer mês: pedem
 * correção. As de quem registra só presença não contam hora nenhuma e não pedem nada.
 */
function mcp_ponto_esquecidas_contar(?int $agora = null): int
{
    $stmt = mcp_db()->prepare('SELECT COUNT(*) FROM mcp_ponto WHERE voluntario = 1 AND saida IS NULL AND entrada <= ?');
    $stmt->execute([mcp_ponto_limite_aberto($agora ?? time())]);
    return (int) $stmt->fetchColumn();
}

/** Voluntários e diretoria ativos sem o termo de adesão registrado. */
function mcp_ponto_termos_pendentes_contar(): int
{
    $marcas = implode(',', array_fill(0, count(MCP_PONTO_VINCULOS_VOLUNTARIOS), '?'));
    $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_colaboradores WHERE ativo = 1 AND termo_em IS NULL AND vinculo IN ($marcas)");
    $stmt->execute(MCP_PONTO_VINCULOS_VOLUNTARIOS);
    return (int) $stmt->fetchColumn();
}

/**
 * Apaga a presença de quem não é voluntário registrada há mais de 90 dias (rotina de
 * api/comparecimentos.php). Devolve quantos registros saíram.
 */
function mcp_ponto_presencas_apagar_antigas(?int $agora = null): int
{
    $stmt = mcp_db()->prepare('DELETE FROM mcp_ponto WHERE voluntario = 0 AND entrada < ?');
    $stmt->execute([gmdate('Y-m-d H:i:s', ($agora ?? time()) - MCP_PONTO_PRESENCA_DIAS * 86400)]);
    return $stmt->rowCount();
}

/**
 * Confere um turno lançado ou corrigido no portal (horários em UTC). Devolve null se estiver certo,
 * ou a mensagem do que está errado.
 */
function mcp_ponto_conferir_turno(int $colaboradorId, string $entradaUtc, ?string $saidaUtc, ?int $ignorarId, int $agora): ?string
{
    $entrada = (int) strtotime($entradaUtc . ' UTC');
    if ($entrada > $agora) {
        return 'A entrada não pode ser no futuro.';
    }
    if ($saidaUtc !== null) {
        $saida = (int) strtotime($saidaUtc . ' UTC');
        if ($saida > $agora) {
            return 'A saída não pode ser no futuro.';
        }
        if ($saida - $entrada < MCP_PONTO_MINIMO_SEGUNDOS) {
            return 'A saída precisa ser depois da entrada.';
        }
        if ($saida - $entrada > MCP_PONTO_TURNO_MAXIMO_HORAS * 3600) {
            return 'Um turno não passa de ' . MCP_PONTO_TURNO_MAXIMO_HORAS . ' horas. Lance dias diferentes em registros separados.';
        }
    }
    // Não pode cruzar outro registro da mesma pessoa (entrada sem saída conta como um instante).
    $stmt = mcp_db()->prepare('SELECT COUNT(*) FROM mcp_ponto WHERE colaborador_id = ? AND id <> ? AND entrada < ?
        AND COALESCE(saida, DATE_ADD(entrada, INTERVAL 1 SECOND)) > ?');
    $stmt->execute([$colaboradorId, (int) $ignorarId, $saidaUtc ?? gmdate('Y-m-d H:i:s', $entrada + 1), $entradaUtc]);
    return (int) $stmt->fetchColumn() > 0 ? 'Esse horário cruza outro registro desta pessoa. Corrija o outro registro primeiro.' : null;
}

function mcp_ponto_nota_ajuste(?string $anterior, string $quem, string $texto): string
{
    $linha = mcp_data_brt(mcp_agora(), 'd/m/Y H:i') . ' · ' . $quem . ' · ' . $texto;
    return trim(((string) $anterior) . "\n" . $linha);
}

/** Corrige entrada e saída de um registro. Devolve null se deu certo, ou a mensagem do erro. */
function mcp_ponto_corrigir(int $id, string $entradaUtc, ?string $saidaUtc, string $quem, string $motivo, ?int $agora = null): ?string
{
    $agora ??= time();
    $r = mcp_ponto_registro($id);
    if (!$r) {
        return 'Registro não encontrado.';
    }
    if (($erro = mcp_ponto_conferir_turno((int) $r['colaborador_id'], $entradaUtc, $saidaUtc, $id, $agora)) !== null) {
        return $erro;
    }
    $antes = mcp_data_brt($r['entrada'], 'd/m H:i') . '–' . ($r['saida'] ? mcp_data_brt($r['saida'], 'H:i') : 'sem saída');
    $depois = mcp_data_brt($entradaUtc, 'd/m H:i') . '–' . ($saidaUtc ? mcp_data_brt($saidaUtc, 'H:i') : 'sem saída');
    // Horário mudado no portal passa a ter origem "portal"; o que não mudou mantém a origem do ponto.
    $origemEntrada = $r['entrada'] !== $entradaUtc ? 'portal' : $r['origem_entrada'];
    $origemSaida = $saidaUtc === null ? null : ($r['saida'] !== $saidaUtc ? 'portal' : $r['origem_saida']);
    mcp_db()->prepare('UPDATE mcp_ponto SET entrada = ?, saida = ?, origem_entrada = ?, origem_saida = ?, ajuste = ?, atualizado_em = ? WHERE id = ?')
        ->execute([$entradaUtc, $saidaUtc, $origemEntrada, $origemSaida, mcp_ponto_nota_ajuste($r['ajuste'], $quem, "corrigiu $antes para $depois: $motivo"), mcp_agora(), $id]);
    mcp_registrar(null, 'ponto_corrigido', "#$id · $quem · $antes → $depois · $motivo");
    return null;
}

/** Lança um turno à mão. Devolve o id do registro, ou a mensagem do erro. */
function mcp_ponto_lancar(int $colaboradorId, string $entradaUtc, string $saidaUtc, string $quem, string $motivo, ?int $agora = null): int|string
{
    $agora ??= time();
    if (($erro = mcp_ponto_conferir_turno($colaboradorId, $entradaUtc, $saidaUtc, null, $agora)) !== null) {
        return $erro;
    }
    $quando = mcp_agora();
    mcp_db()->prepare("INSERT INTO mcp_ponto (colaborador_id, voluntario, entrada, saida, origem_entrada, origem_saida, ajuste, criado_em, atualizado_em) VALUES (?, 1, ?, ?, 'portal', 'portal', ?, ?, ?)")
        ->execute([$colaboradorId, $entradaUtc, $saidaUtc, mcp_ponto_nota_ajuste(null, $quem, "lançou à mão: $motivo"), $quando, $quando]);
    $id = (int) mcp_db()->lastInsertId();
    mcp_registrar(null, 'ponto_lancado', "#$id · colaborador $colaboradorId · $quem · $motivo");
    return $id;
}

function mcp_ponto_apagar(int $id, string $quem, string $motivo): bool
{
    $r = mcp_ponto_registro($id);
    if (!$r) {
        return false;
    }
    mcp_db()->prepare('DELETE FROM mcp_ponto WHERE id = ?')->execute([$id]);
    mcp_registrar(null, 'ponto_apagado', "#$id · colaborador {$r['colaborador_id']} · {$r['entrada']}–" . ($r['saida'] ?? 'sem saída') . " · $quem · $motivo");
    return true;
}

/** Planilha dos registros de um período (CSV com ; e BOM). Sem CPF, como as outras planilhas do portal. */
function mcp_ponto_csv(array $registros, ?int $agora = null): string
{
    $agora ??= time();
    $f = fopen('php://temp', 'w+');
    fputcsv($f, ['Data (Brasília)', 'Colaborador', 'Vínculo', 'Função', 'Entrada', 'Saída', 'Horas', 'Registro da entrada', 'Registro da saída', 'Ajustes'], ';', '"', '');
    foreach ($registros as $r) {
        fputcsv($f, array_map('mcp_horarios_celula', [
            mcp_data_brt($r['entrada'], 'd/m/Y'), $r['nome'], mcp_ponto_vinculo_nome($r), (string) $r['funcao'], mcp_data_brt($r['entrada'], 'H:i'),
            $r['saida'] !== null
                ? (mcp_data_brt($r['saida'], 'd/m/Y') !== mcp_data_brt($r['entrada'], 'd/m/Y') ? mcp_data_brt($r['saida'], 'd/m H:i') : mcp_data_brt($r['saida'], 'H:i'))
                : (mcp_ponto_esquecido($r, $agora) ? 'saída esquecida' : 'na sede'),
            $r['saida'] !== null ? mcp_ponto_horas_texto(intdiv(mcp_ponto_segundos($r), 60)) : '',
            MCP_PONTO_ORIGENS[$r['origem_entrada']] ?? (string) $r['origem_entrada'],
            $r['origem_saida'] !== null ? (MCP_PONTO_ORIGENS[$r['origem_saida']] ?? (string) $r['origem_saida']) : '',
            str_replace("\n", ' | ', (string) $r['ajuste']),
        ]), ';', '"', '');
    }
    rewind($f);
    $csv = (string) stream_get_contents($f);
    fclose($f);
    return "\xEF\xBB\xBF" . $csv;
}

// ----------------------------------------------------------------------------- declaração de horas voluntárias
/**
 * Emite (ou reaproveita, se nada mudou) a declaração de horas de um período e devolve a linha gravada,
 * com o código de verificação. Período em datas de Brasília, [de, ate]. Só conta os registros de
 * voluntário e cita o termo de adesão; quem chama confere antes que o termo está registrado.
 */
function mcp_ponto_declaracao_emitir(array $colaborador, string $deIso, string $ateIso, string $quem, ?int $agora = null): array
{
    $proximo = (new DateTimeImmutable($ateIso))->modify('+1 day')->format('Y-m-d');
    [$de, $ate] = mcp_ponto_periodo_utc($deIso, $proximo);
    $segundos = 0;
    $dias = [];
    foreach (mcp_ponto_registros($de, $ate, (int) $colaborador['id'], true) as $r) {
        if ($r['saida'] !== null) {
            $segundos += mcp_ponto_segundos($r);
            $dias[mcp_data_brt($r['entrada'], 'Y-m-d')] = true;
        }
    }
    $minutos = intdiv($segundos, 60);
    $termo = $colaborador['termo_em'] ?: null;
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_declaracoes_horas WHERE colaborador_id = ? AND de = ? AND ate = ? AND minutos = ? AND dias = ?
        AND nome = ? AND cpf = ? AND COALESCE(funcao, \'\') = ? AND termo_em <=> ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int) $colaborador['id'], $deIso, $ateIso, $minutos, count($dias), $colaborador['nome'], $colaborador['cpf'], (string) $colaborador['funcao'], $termo]);
    if ($existente = $stmt->fetch()) {
        return $existente;
    }
    mcp_db()->prepare('INSERT INTO mcp_declaracoes_horas (codigo, colaborador_id, nome, cpf, funcao, de, ate, minutos, dias, termo_em, emitida_por, emitida_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([mcp_codigo_novo(), (int) $colaborador['id'], $colaborador['nome'], $colaborador['cpf'], $colaborador['funcao'], $deIso, $ateIso, $minutos, count($dias), $termo, $quem,
            gmdate('Y-m-d H:i:s', $agora ?? time())]);
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_declaracoes_horas WHERE id = ?');
    $stmt->execute([(int) mcp_db()->lastInsertId()]);
    return $stmt->fetch();
}

function mcp_ponto_declaracao_por_codigo(string $codigo): ?array
{
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_declaracoes_horas WHERE codigo = ?');
    $stmt->execute([$codigo]);
    return $stmt->fetch() ?: null;
}

/** Conteúdo da declaração de horas (sem desenho): é o que os testes conferem. */
function mcp_ponto_declaracao_conteudo(array $d, ?string $agoraUtc = null): array
{
    $nome = mcp_nome_proprio((string) $d['nome']);
    $cpf = mcp_cpf_formatado((string) $d['cpf']);
    $de = mcp_escola_data((string) $d['de']);
    $ate = mcp_escola_data((string) $d['ate']);
    $periodo = $d['de'] === $d['ate'] ? "no dia $de" : "entre $de e $ate";
    $funcao = trim((string) $d['funcao']);
    $horas = mcp_ponto_horas_extenso((int) $d['minutos']);
    $codigo = mcp_codigo_formatado((string) $d['codigo']);
    $emitida = mcp_data_brt((string) $d['emitida_em'], 'd/m/Y \à\s H\hi');
    $termo = !empty($d['termo_em']) ? mcp_escola_data((string) $d['termo_em']) : null;
    return [
        'titulo' => 'DECLARAÇÃO DE HORAS VOLUNTÁRIAS',
        'subtitulo' => 'Trabalho voluntário na sede da ' . MCP_NOME_FILIAL,
        'assunto' => "Declaração de horas voluntárias de $nome",
        'rotulo_pessoa' => 'COLABORADOR',
        'nome' => $nome,
        'cpf' => "CPF: $cpf",
        'texto' => "Declaramos, para os devidos fins, que $nome, CPF $cpf, prestou serviço voluntário na " . MCP_NOME_FILIAL
            . ($funcao !== '' ? ", na função de $funcao," : '') . " somando $horas de atividades na sede da instituição $periodo, "
            . 'conforme os registros de entrada e saída do ponto da sede.'
            . ($termo !== null ? " O serviço foi prestado nos termos da Lei nº 9.608/1998 e do termo de adesão assinado em $termo, sem vínculo empregatício." : ''),
        'secao' => 'HORAS REGISTRADAS',
        'linhas' => array_values(array_filter([
            ['Período', $d['de'] === $d['ate'] ? $de : "$de a $ate"],
            $funcao !== '' ? ['Função', $funcao] : null,
            ['Dias com registro', (string) (int) $d['dias']],
            ['Total de horas', mcp_ponto_horas_texto((int) $d['minutos'])],
            $termo !== null ? ['Termo de adesão', "assinado em $termo"] : null,
            ['Local', 'Praça da Cruz Vermelha, 10, Centro, Rio de Janeiro/RJ'],
        ])),
        'local_data' => 'Rio de Janeiro, ' . mcp_data_extenso(mcp_data_brt((string) $d['emitida_em'], 'Y-m-d')) . '.',
        'assinatura' => ['Secretaria', MCP_NOME_FILIAL],
        'codigo' => $codigo,
        'conferir' => mcp_conferir_url((string) $d['codigo']),
        'rodape' => "Declaração emitida eletronicamente em $emitida (horário de Brasília) pela secretaria, a partir dos registros do ponto da sede. "
            . 'Confira a autenticidade em ' . mcp_conferir_url_curta() . " com o código $codigo. Dúvidas: " . mcp_email_contato_endereco() . '.',
    ];
}

/** "Declaracao_de_Horas_Voluntarias_Joao_da_Silva_2026-09.pdf" */
function mcp_ponto_declaracao_arquivo(array $d): string
{
    return mcp_nome_arquivo('Declaracao de Horas Voluntarias ' . mcp_nome_proprio((string) $d['nome']) . ' ' . $d['de'] . ($d['de'] !== $d['ate'] ? ' a ' . $d['ate'] : ''));
}

// ----------------------------------------------------------------------------- termo de adesão (Lei 9.608/1998)
/** A entidade como aparece nos documentos com valor jurídico (a mesma da Política de Privacidade). */
const MCP_PONTO_ENTIDADE = 'Cruz Vermelha Brasileira — Filial do Estado do Rio de Janeiro, CNPJ 08.560.973/0001-97, com sede na Praça da Cruz Vermelha, 10, Centro, Rio de Janeiro/RJ, CEP 20230-130';

/**
 * Conteúdo do termo de adesão ao serviço voluntário, preenchido com os dados do cadastro (sem desenho:
 * é o que os testes conferem). A data e as assinaturas ficam em branco, para a via impressa; a
 * secretaria registra no portal a data em que foi assinado. Mudou o texto, muda MCP_PONTO_TERMO_MODELO.
 * O modelo deve passar pela revisão do jurídico da instituição antes do primeiro uso.
 */
function mcp_ponto_termo_conteudo(array $colaborador, ?string $agoraUtc = null): array
{
    $nome = mcp_nome_proprio((string) $colaborador['nome']);
    $cpf = mcp_cpf_formatado((string) $colaborador['cpf']);
    $funcao = trim((string) $colaborador['funcao']);
    $contatos = array_values(array_filter([
        !empty($colaborador['email']) ? 'e-mail ' . $colaborador['email'] : null,
        !empty($colaborador['telefone']) ? 'telefone ' . mcp_telefone_bonito((string) $colaborador['telefone']) : null,
    ]));
    $gerado = mcp_data_brt($agoraUtc ?? mcp_agora(), 'd/m/Y');
    $lei = 'Lei nº 9.608/1998';
    return [
        'titulo' => 'TERMO DE ADESÃO AO SERVIÇO VOLUNTÁRIO',
        'subtitulo' => 'Lei nº 9.608, de 18 de fevereiro de 1998',
        'assunto' => "Termo de adesão ao serviço voluntário de $nome",
        'nome' => $nome,
        'partes' => [
            ['ENTIDADE', MCP_PONTO_ENTIDADE . ', representada na forma do seu estatuto.'],
            ['VOLUNTÁRIO(A)', "$nome, CPF $cpf" . ($contatos ? ', ' . implode(', ', $contatos) : '') . '.'],
        ],
        'abertura' => "As partes acima celebram este Termo de Adesão ao Serviço Voluntário, nos termos da $lei, com as cláusulas a seguir.",
        'clausulas' => [
            ['1. Objeto', 'O(A) VOLUNTÁRIO(A) prestará serviço voluntário à ENTIDADE' . ($funcao !== '' ? ", na função de $funcao," : '')
                . ' em atividades de interesse humanitário e social ligadas às finalidades da instituição, conforme a orientação da coordenação responsável.'],
            ['2. Natureza do serviço', 'O serviço é prestado de forma espontânea e gratuita, sem remuneração de qualquer espécie, e não gera vínculo '
                . "empregatício nem obrigação de natureza trabalhista, previdenciária ou afim, conforme o art. 1º, parágrafo único, da $lei."],
            ['3. Dias e horários', 'As atividades são realizadas nos dias e horários combinados entre as partes, de acordo com a disponibilidade do(a) '
                . 'VOLUNTÁRIO(A), que avisa a coordenação quando não puder comparecer a uma atividade combinada.'],
            ['4. Registro das horas', 'O(A) VOLUNTÁRIO(A) registra a chegada e a saída no ponto da sede. O registro serve para reconhecer as horas doadas, '
                . 'emitir a declaração de horas voluntárias a pedido do(a) VOLUNTÁRIO(A) e prestar contas do trabalho voluntário, inclusive na contabilidade '
                . 'da ENTIDADE. Não serve para remuneração, controle de jornada ou punição.'],
            ['5. Despesas', 'O(A) VOLUNTÁRIO(A) pode ser ressarcido(a) das despesas que comprovadamente realizar no desempenho das atividades, desde que '
                . "autorizadas antes, por escrito, pela ENTIDADE, conforme o art. 3º da $lei. O ressarcimento não tem natureza de remuneração."],
            ['6. Compromissos do(a) voluntário(a)', 'Respeitar os Princípios Fundamentais do Movimento Internacional da Cruz Vermelha e do Crescente Vermelho '
                . '(Humanidade, Imparcialidade, Neutralidade, Independência, Voluntariado, Unidade e Universalidade), o estatuto, as normas internas e as '
                . 'orientações de segurança da ENTIDADE; zelar pelos materiais e equipamentos que usar; usar o nome e o emblema da Cruz Vermelha só nas '
                . 'atividades autorizadas; e manter sigilo sobre as informações e os dados pessoais de terceiros a que tiver acesso, que só podem ser usados '
                . 'nas atividades do voluntariado.'],
            ['7. Compromissos da entidade', 'Orientar o(a) VOLUNTÁRIO(A) sobre as atividades e os cuidados de segurança, oferecer as condições necessárias '
                . 'para realizá-las e emitir, a pedido, a declaração das horas registradas.'],
            ['8. Dados pessoais', 'A ENTIDADE trata os dados do(a) VOLUNTÁRIO(A) (nome, CPF, contatos, função e os horários registrados no ponto da sede) '
                . 'para executar este Termo, cumprir obrigações legais e contábeis e garantir a segurança da sede, na forma da Lei nº 13.709/2018 (LGPD) e '
                . 'da Política de Privacidade publicada em cruzvermelhariodejaneiro.org/privacidade. Os registros são guardados enquanto durar o voluntariado '
                . 'e por até 5 anos depois do seu fim.'],
            ['9. Vigência e desligamento', 'Este Termo vale por prazo indeterminado, a partir da assinatura, e pode ser encerrado por qualquer das partes a '
                . 'qualquer tempo, por simples comunicação, sem ônus para nenhuma delas.'],
            ['10. Voluntário(a) com menos de 18 anos', 'Neste caso, este Termo é assinado também pelo responsável legal, que autoriza a participação nas atividades.'],
            ['11. Foro', 'Fica eleito o foro da Comarca da Capital do Estado do Rio de Janeiro para resolver qualquer questão sobre este Termo.'],
        ],
        'fecho' => 'E, por estarem de acordo, as partes assinam este Termo em duas vias de igual teor.',
        'local_data' => 'Rio de Janeiro, ______ de ________________________ de ________.',
        'assinaturas' => [
            ['VOLUNTÁRIO(A)', "$nome · CPF $cpf"],
            ['PELA ENTIDADE', 'Nome e cargo:'],
            ['RESPONSÁVEL LEGAL (se o voluntário tiver menos de 18 anos)', 'Nome e CPF:'],
        ],
        'rodape' => 'Termo de adesão ao serviço voluntário · modelo ' . MCP_PONTO_TERMO_MODELO . " · gerado em $gerado · $nome",
    ];
}

/** "Termo_de_Adesao_Voluntario_Joao_da_Silva.pdf" */
function mcp_ponto_termo_arquivo(array $colaborador): string
{
    return mcp_nome_arquivo('Termo de Adesao Voluntario ' . mcp_nome_proprio((string) $colaborador['nome']));
}
