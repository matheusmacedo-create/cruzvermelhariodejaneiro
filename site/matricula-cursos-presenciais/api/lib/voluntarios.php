<?php
/**
 * Cadastro de voluntários (10/2026): a ficha preenchida em /voluntariado/cadastro/ (api/voluntarios.php), a
 * leitura e o acompanhamento no portal (?v=voluntarios), o PDF da ficha, a planilha e os dois e-mails (aviso à
 * equipe de voluntariado e confirmação à pessoa).
 *
 * Regras:
 *  - idade mínima de 18 anos (desde 25/10/2026; antes era 16, e de 16 a 17 o responsável legal assinava o termo de
 *    adesão). As fichas de menores que já existem continuam marcadas no portal e nos e-mails (MCP_VOL_MAIORIDADE);
 *  - CPF opcional na ficha e obrigatório só na aceitação, quando a pessoa vira colaboradora do ponto da sede
 *    (mcp_colaboradores, vínculo 'voluntario'); nunca sai no CSV nem nos e-mails, e no PDF só os 3 primeiros dígitos;
 *  - LGPD: a ficha guarda quando a pessoa concordou com a Política de Privacidade (consentimento_em), sob qual
 *    revisão (politica_versao, o campo "revisao" de site/politicas.json) e quando declarou a veracidade;
 *  - freios: 5 fichas por IP na hora e 2 por e-mail no dia; a mesma pessoa com ficha 'nova' ou 'em_analise' nos
 *    últimos 30 dias recebe 409 com a data da ficha anterior, em vez de outra linha.
 *
 * O banco é a fonte da verdade: e-mail que falha e Drive fora do ar não perdem a ficha (email_* = 'falhou'; a fila
 * do Drive tenta de novo). Nada aqui é cobrado nem pede doação; o contato é por e-mail.
 */
declare(strict_types=1);

const MCP_VOL_IDADE_MINIMA = 18;
const MCP_VOL_MAIORIDADE = 18;
/** Fichas por IP na hora e por e-mail no dia (429). */
const MCP_VOL_LIMITE_IP = [5, 3600];
const MCP_VOL_LIMITE_EMAIL = [2, 86400];
const MCP_VOL_MOTIVACAO_MIN = 20;
// Novas entradas no voluntariado (decisão do Matheus, 18/10/2026): o contato com quem se cadastra é só nessa época.
const MCP_VOL_PROXIMAS_ENTRADAS = 'janeiro de 2027';
// Turma do CBFI de 28/11/2026, aberta a quem se cadastra agora (pedido do Matheus, 27/10/2026). Até essa data, em Brasília,
// os textos falam da turma; a partir do dia seguinte voltam sozinhos para as novas entradas em janeiro. A mesma data de
// scripts/cbfi.py, que faz a troca nas páginas: mudou aqui, mudar lá.
const MCP_VOL_CBFI_DATA = '2026-11-28';
const MCP_VOL_CBFI_DATA_TEXTO = '28 de novembro de 2026';
const MCP_VOL_MOTIVACAO_MAX = 1500;
// Selfie obrigatória (27/10/2026; nasceu opcional no mesmo dia): o arquivo pode chegar com até 10 MB (sem JavaScript, direto da câmera); fica guardada
// com no máximo 800 px no lado maior, em JPG. A ficha em JSON com a selfie já reduzida pelo navegador cabe em 768 KB.
const MCP_VOL_SELFIE_BYTES = 10 * 1024 * 1024;
const MCP_VOL_SELFIE_LADO = 800;
const MCP_VOL_SELFIE_QUALIDADE = 82;
const MCP_VOL_CORPO_MAX = 786432;
const MCP_VOL_TEXTO_LONGO_MAX = 1000;
/** Janela da ficha repetida: mesmo e-mail com ficha 'nova' ou 'em_analise' (409). */
const MCP_VOL_DUPLICADA_DIAS = 30;
const MCP_VOL_FUSO = 'America/Sao_Paulo';
/** Versão gravada quando site/politicas.json não puder ser lido (nunca deve acontecer no servidor). */
const MCP_VOL_POLITICA_SEM_VERSAO = 'sem-versao';

const MCP_VOL_FRENTES = [
    'formacao_primeiros_socorros' => 'Formação e primeiros socorros',
    'campanhas_acoes' => 'Campanhas e ações humanitárias',
    'juventude' => 'Juventude',
    'comunicacao' => 'Comunicação',
    'gestao_riscos' => 'Gestão de riscos e desastres',
    'psicologia_servico_social' => 'Psicologia e serviço social',
    'esportes' => 'Esportes',
    'administrativo' => 'Administrativo',
    'outra' => 'Outra frente',
];
const MCP_VOL_ESCOLARIDADES = [
    'fundamental' => 'Ensino fundamental',
    'medio' => 'Ensino médio',
    'tecnico' => 'Ensino técnico',
    'superior_incompleto' => 'Superior incompleto',
    'superior' => 'Superior completo',
    'pos' => 'Pós-graduação',
];
const MCP_VOL_COMO_CONHECEU = [
    'instagram' => 'Instagram',
    'site' => 'Site',
    'amigos' => 'Amigos ou família',
    'escola' => 'Escola ou faculdade',
    'campanha' => 'Campanha ou ação na rua',
    'imprensa' => 'Imprensa',
    'outro' => 'Outro',
];
const MCP_VOL_STATUS = [
    'nova' => 'Nova',
    'em_analise' => 'Em análise',
    'entrevista' => 'Entrevista',
    'aceita' => 'Aceita',
    'recusada' => 'Recusada',
    'arquivada' => 'Arquivada',
];
const MCP_VOL_DIAS = ['seg' => 'Segunda', 'ter' => 'Terça', 'qua' => 'Quarta', 'qui' => 'Quinta', 'sex' => 'Sexta', 'sab' => 'Sábado', 'dom' => 'Domingo'];
const MCP_VOL_PERIODOS = ['manha' => 'Manhã', 'tarde' => 'Tarde', 'noite' => 'Noite'];
const MCP_VOL_UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];
/** Colunas da ficha que o portal lista (sem o CPF: ele só aparece na ficha aberta, e mascarado). */
const MCP_VOL_COLUNAS_LISTA = 'id, protocolo, nome, data_nascimento, email, telefone, cidade, bairro, estado, escolaridade, profissao, saude, saude_qual,
    experiencia, habilidades, frentes, dias, periodos, motivacao, como_conheceu, aceita_contato, consentimento_em, politica_versao, veracidade_em,
    pagina, utm_source, utm_medium, utm_campaign, utm_content, utm_term, email_equipe, email_confirmacao, status, status_em, status_por,
    colaborador_id, drive_pdf_id, drive_planilha_em, criado_em, atualizado_em';

// ----------------------------------------------------------------------------- rótulos (portal, PDF, CSV, e-mails)
/** @return array<string, string> chave => rótulo em português */

/** Data de hoje em Brasília (AAAA-MM-DD). $fixar troca a data, só para scripts/testar_voluntarios.php. */
function mcp_vol_hoje(?string $fixar = null): string
{
    static $fixa = null;
    if ($fixar !== null) {
        $fixa = $fixar === '' ? null : $fixar;
    }
    return $fixa ?? (new DateTimeImmutable('now', new DateTimeZone(MCP_VOL_FUSO)))->format('Y-m-d');
}

/** A turma do CBFI de MCP_VOL_CBFI_DATA ainda vale (até o dia dela, inclusive). */
function mcp_vol_cbfi_aberto(): bool
{
    return mcp_vol_hoje() <= MCP_VOL_CBFI_DATA;
}

/** Quando a equipe entra em contato: "antes do CBFI de 28 de novembro de 2026" ou "em janeiro de 2027". */
function mcp_vol_prazo(): string
{
    return mcp_vol_cbfi_aberto() ? 'antes do CBFI de ' . MCP_VOL_CBFI_DATA_TEXTO : 'em ' . MCP_VOL_PROXIMAS_ENTRADAS;
}

/** A frase da tela de sucesso do cadastro (campo "aviso" da API). */
function mcp_vol_aviso_prazo(): string
{
    if (mcp_vol_cbfi_aberto()) {
        return 'A próxima turma do CBFI é no dia ' . MCP_VOL_CBFI_DATA_TEXTO . '. Antes dela, a coordenadora do voluntariado chama você pelo WhatsApp para explicar como será a entrevista.';
    }
    return 'As novas entradas abrem em ' . MCP_VOL_PROXIMAS_ENTRADAS . ': nessa época a coordenadora do voluntariado chama você pelo WhatsApp para explicar como será a entrevista, e até lá não é preciso esperar resposta.';
}
function mcp_voluntarios_frentes(): array
{
    return MCP_VOL_FRENTES;
}

function mcp_voluntarios_escolaridades(): array
{
    return MCP_VOL_ESCOLARIDADES;
}

function mcp_voluntarios_como_conheceu(): array
{
    return MCP_VOL_COMO_CONHECEU;
}

function mcp_voluntarios_status(): array
{
    return MCP_VOL_STATUS;
}

function mcp_voluntarios_dias(): array
{
    return MCP_VOL_DIAS;
}

function mcp_voluntarios_periodos(): array
{
    return MCP_VOL_PERIODOS;
}

/** "Formação e primeiros socorros, Juventude" a partir do CSV gravado; chave desconhecida sai como veio. */
function mcp_voluntario_rotulos(string $csv, array $tabela): string
{
    $partes = [];
    foreach (array_filter(explode(',', $csv), static fn(string $c): bool => trim($c) !== '') as $chave) {
        $partes[] = $tabela[trim($chave)] ?? trim($chave);
    }
    return implode(', ', $partes);
}

function mcp_voluntario_status_rotulo(string $status): string
{
    return MCP_VOL_STATUS[$status] ?? $status;
}

/** "123.***.***-**": só os 3 primeiros dígitos do CPF (PDF e portal); '' sem CPF. */
function mcp_voluntario_cpf_parcial(?string $cpf): string
{
    $d = mcp_digitos((string) $cpf);
    return strlen($d) === 11 ? substr($d, 0, 3) . '.***.***-**' : '';
}

// ----------------------------------------------------------------------------- datas e idade
function mcp_voluntario_fuso(): DateTimeZone
{
    return new DateTimeZone(MCP_VOL_FUSO);
}

/** Hoje em Brasília (AAAA-MM-DD): a idade conta pelo calendário de quem preenche, não pelo UTC do servidor. */
function mcp_voluntario_hoje(?int $agora = null): string
{
    return (new DateTimeImmutable('@' . ($agora ?? time())))->setTimezone(mcp_voluntario_fuso())->format('Y-m-d');
}

/** 'AAAA-MM-DD' ou 'DD/MM/AAAA' (quem digita sem JavaScript) -> 'AAAA-MM-DD' válido, ou null. */
function mcp_voluntario_data(mixed $valor): ?string
{
    $texto = mcp_texto($valor, 10);
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $texto, $m)) {
        $texto = "$m[3]-$m[2]-$m[1]";
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $texto, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }
    return $texto;
}

/** Idade completa em anos na data $hoje (AAAA-MM-DD; padrão: hoje em Brasília), ou null se o nascimento for inválido. */
function mcp_voluntario_idade(string $nascimento, ?string $hoje = null): ?int
{
    $data = mcp_voluntario_data($nascimento);
    if ($data === null) {
        return null;
    }
    $hoje ??= mcp_voluntario_hoje();
    $n = new DateTimeImmutable($data, mcp_voluntario_fuso());
    $h = new DateTimeImmutable($hoje, mcp_voluntario_fuso());
    if ($n > $h) {
        return null;
    }
    return $n->diff($h)->y;
}

/**
 * Revisão da Política de Privacidade publicada, gravada em cada ficha: a própria (paginas.privacidade.revisao de
 * site/politicas.json) ou, sem ela, a de todas as políticas (revisao). Até 27/10/2026 lia sempre a de todas.
 */
function mcp_voluntario_politica_versao(): string
{
    static $versao = null;
    if ($versao === null) {
        $arquivo = getenv('MCP_POLITICAS_ARQUIVO') ?: dirname(__DIR__, 3) . '/politicas.json';
        $dados = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;
        $lida = is_array($dados) ? mcp_texto($dados['paginas']['privacidade']['revisao'] ?? $dados['revisao'] ?? '', 10) : '';
        $versao = $lida !== '' ? $lida : MCP_VOL_POLITICA_SEM_VERSAO;
    }
    return $versao;
}

// ----------------------------------------------------------------------------- validação
/** true, 1, '1', 'true', 'on', 'sim', 'yes' valem como sim (JSON manda booleano; o formulário sem JavaScript manda 'on' ou 'sim'). */
function mcp_voluntario_bool(mixed $valor): bool
{
    if (is_bool($valor)) {
        return $valor;
    }
    if (is_int($valor) || is_float($valor)) {
        return (int) $valor === 1;
    }
    return is_string($valor) && in_array(mb_strtolower(trim($valor)), ['1', 'true', 'on', 'sim', 'yes'], true);
}

/**
 * Lista marcada no formulário (array do JSON ou de frentes[], ou texto separado por vírgula) filtrada pela tabela
 * de valores, sem repetição e na ordem da tabela. Devolve null se algum valor não existe na tabela.
 *
 * @return list<string>|null
 */
function mcp_voluntario_lista(mixed $valor, array $tabela): ?array
{
    if (is_string($valor)) {
        $valor = $valor === '' ? [] : explode(',', $valor);
    }
    if (!is_array($valor)) {
        return [];
    }
    $marcados = [];
    foreach ($valor as $item) {
        $chave = mcp_texto($item, 40);
        if ($chave === '') {
            continue;
        }
        if (!isset($tabela[$chave])) {
            return null;
        }
        $marcados[$chave] = true;
    }
    return array_values(array_filter(array_keys($tabela), static fn(string $k): bool => isset($marcados[$k])));
}

/**
 * Normaliza e confere a ficha. Devolve ['ok' => true, 'dados' => [...]] (as chaves que mcp_voluntario_gravar espera,
 * mais 'idade' e 'menor') ou ['ok' => false, 'campo' => ..., 'erro' => ...] para o endpoint responder 422 com o campo
 * (como o contato) ou redirecionar com ?erro=<campo> (sem JavaScript). Função pura: não grava nem lê o banco.
 */
function mcp_voluntario_validar(array $b, ?string $hoje = null): array
{
    $falha = static fn(string $campo, string $erro): array => ['ok' => false, 'campo' => $campo, 'erro' => $erro];
    $contato = mcp_email_contato_endereco();

    $nome = mcp_texto($b['nome'] ?? '', 160);
    if (mb_strlen($nome) < 5 || !str_contains($nome, ' ')) {
        return $falha('nome', 'Escreva o nome completo.');
    }
    $nascimento = mcp_voluntario_data($b['data_nascimento'] ?? '');
    if ($nascimento === null) {
        return $falha('data_nascimento', 'Informe a data de nascimento (dia, mês e ano).');
    }
    $idade = mcp_voluntario_idade($nascimento, $hoje);
    if ($idade === null || $idade > 120) {
        return $falha('data_nascimento', 'Confira a data de nascimento.');
    }
    if ($idade < MCP_VOL_IDADE_MINIMA) {
        return $falha('data_nascimento', 'No momento, o voluntariado aceita só quem tem ' . MCP_VOL_IDADE_MINIMA . ' anos ou mais.');
    }
    $email = mb_strtolower(mcp_texto($b['email'] ?? '', 190));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $falha('email', 'Esse e-mail não parece válido. Confira e envie de novo.');
    }
    $telefone = mcp_telefone(mcp_texto($b['telefone'] ?? '', 30));
    if ($telefone === '') {
        return $falha('telefone', 'Informe um telefone com DDD (celular, de preferência).');
    }
    $cpfBruto = mcp_texto($b['cpf'] ?? '', 20);
    $cpf = mcp_digitos($cpfBruto);
    if ($cpfBruto !== '' && !mcp_cpf_valido($cpf)) {
        return $falha('cpf', 'CPF inválido. Confira os números ou deixe em branco.');
    }
    $cidade = mcp_texto($b['cidade'] ?? '', 120);
    if (mb_strlen($cidade) < 2) {
        return $falha('cidade', 'Informe a cidade onde você mora.');
    }
    $estado = mb_strtoupper(mcp_texto($b['estado'] ?? '', 2));
    if ($estado === '') {
        $estado = 'RJ';
    } elseif (!in_array($estado, MCP_VOL_UFS, true)) {
        return $falha('estado', 'Escolha o estado (UF).');
    }
    $escolaridade = mcp_texto($b['escolaridade'] ?? '', 30);
    if (!isset(MCP_VOL_ESCOLARIDADES[$escolaridade])) {
        return $falha('escolaridade', 'Escolha a sua escolaridade.');
    }
    // Saúde: 'sim' ou 'nao' no contrato; booleano também vale. "Qual" só faz sentido com 'sim'.
    $saudeBruto = $b['saude'] ?? 'nao';
    $saude = (is_string($saudeBruto) && mb_strtolower(trim($saudeBruto)) === 'sim') || (!is_string($saudeBruto) && mcp_voluntario_bool($saudeBruto)) ? 1 : 0;
    $saudeQual = $saude === 1 ? mcp_texto($b['saude_qual'] ?? '', 160) : '';

    $frentes = mcp_voluntario_lista($b['frentes'] ?? [], MCP_VOL_FRENTES);
    if ($frentes === null) {
        return $falha('frentes', 'Uma das frentes marcadas não existe. Recarregue a página e marque de novo.');
    }
    if (!$frentes) {
        return $falha('frentes', 'Marque pelo menos uma frente em que você gostaria de atuar.');
    }
    $dias = mcp_voluntario_lista($b['dias'] ?? [], MCP_VOL_DIAS);
    if ($dias === null) {
        return $falha('dias', 'Um dos dias marcados não existe. Recarregue a página e marque de novo.');
    }
    if (!$dias) {
        return $falha('dias', 'Marque pelo menos um dia da semana em que você pode participar.');
    }
    $periodos = mcp_voluntario_lista($b['periodos'] ?? [], MCP_VOL_PERIODOS);
    if ($periodos === null) {
        return $falha('periodos', 'Um dos períodos marcados não existe. Recarregue a página e marque de novo.');
    }
    if (!$periodos) {
        return $falha('periodos', 'Marque pelo menos um período (manhã, tarde ou noite).');
    }
    $motivacao = mcp_texto_longo($b['motivacao'] ?? '', MCP_VOL_MOTIVACAO_MAX + 1);
    if (mb_strlen($motivacao) < MCP_VOL_MOTIVACAO_MIN) {
        return $falha('motivacao', 'Conte um pouco mais sobre a sua motivação: pelo menos ' . MCP_VOL_MOTIVACAO_MIN . ' caracteres.');
    }
    if (mb_strlen($motivacao) > MCP_VOL_MOTIVACAO_MAX) {
        return $falha('motivacao', 'A motivação pode ter até ' . number_format(MCP_VOL_MOTIVACAO_MAX, 0, ',', '.') . ' caracteres.');
    }
    $comoConheceu = mcp_texto($b['como_conheceu'] ?? '', 30);
    if ($comoConheceu !== '' && !isset(MCP_VOL_COMO_CONHECEU[$comoConheceu])) {
        $comoConheceu = 'outro';
    }
    if (!mcp_voluntario_bool($b['consentimento'] ?? false)) {
        return $falha('consentimento', 'Para enviar a ficha, é preciso concordar com a Política de Privacidade.');
    }
    if (!mcp_voluntario_bool($b['veracidade'] ?? false)) {
        return $falha('veracidade', 'Confirme que as informações da ficha são verdadeiras.');
    }
    // Página de onde veio: só caminhos do próprio site (como no contato).
    $pagina = mcp_texto($b['pagina'] ?? '', 255);
    if ($pagina !== '' && (!str_starts_with($pagina, '/') || str_starts_with($pagina, '//'))) {
        $pagina = '';
    }
    $origem = is_array($b['origem'] ?? null) ? $b['origem'] : [];
    $utm = static fn(string $k, int $limite = 160): ?string => mcp_texto($origem[$k] ?? '', $limite) ?: null;

    return ['ok' => true, 'dados' => [
        'nome' => $nome, 'data_nascimento' => $nascimento, 'idade' => $idade, 'menor' => $idade < MCP_VOL_MAIORIDADE,
        'email' => $email, 'telefone' => $telefone, 'cpf' => $cpf !== '' ? $cpf : null,
        'cidade' => $cidade, 'bairro' => mcp_texto($b['bairro'] ?? '', 120) ?: null, 'estado' => $estado,
        'escolaridade' => $escolaridade, 'profissao' => mcp_texto($b['profissao'] ?? '', 120) ?: null,
        'saude' => $saude, 'saude_qual' => $saudeQual !== '' ? $saudeQual : null,
        'experiencia' => mcp_texto_longo($b['experiencia'] ?? '', MCP_VOL_TEXTO_LONGO_MAX) ?: null,
        'habilidades' => mcp_texto_longo($b['habilidades'] ?? '', MCP_VOL_TEXTO_LONGO_MAX) ?: null,
        'frentes' => implode(',', $frentes), 'dias' => implode(',', $dias), 'periodos' => implode(',', $periodos),
        'motivacao' => $motivacao, 'como_conheceu' => $comoConheceu !== '' ? $comoConheceu : null,
        'aceita_contato' => mcp_voluntario_bool($b['aceita_contato'] ?? false) ? 1 : 0,
        'pagina' => $pagina ?: null,
        'utm_source' => $utm('utm_source', 120), 'utm_medium' => $utm('utm_medium', 120),
        'utm_campaign' => $utm('utm_campaign'), 'utm_content' => $utm('utm_content'), 'utm_term' => $utm('utm_term'),
        'fbclid' => $utm('fbclid', 255), 'gclid' => $utm('gclid', 255),
    ]];
}

// ----------------------------------------------------------------------------- banco
/**
 * Grava a ficha conferida e devolve o id. As chaves vêm de mcp_voluntario_validar(), nunca do cliente. As datas do
 * consentimento e da veracidade e a versão da política são preenchidas aqui (agora, em UTC) quando não vierem.
 */
function mcp_voluntario_gravar(array $f): int
{
    $agora = mcp_agora();
    $linha = [
        'nome' => $f['nome'], 'data_nascimento' => $f['data_nascimento'], 'email' => $f['email'], 'telefone' => $f['telefone'],
        'cpf' => $f['cpf'] ?? null, 'cidade' => $f['cidade'], 'bairro' => $f['bairro'] ?? null, 'estado' => $f['estado'] ?? 'RJ',
        'escolaridade' => $f['escolaridade'], 'profissao' => $f['profissao'] ?? null, 'saude' => (int) ($f['saude'] ?? 0),
        'saude_qual' => $f['saude_qual'] ?? null, 'experiencia' => $f['experiencia'] ?? null, 'habilidades' => $f['habilidades'] ?? null,
        'frentes' => $f['frentes'], 'dias' => $f['dias'], 'periodos' => $f['periodos'], 'motivacao' => $f['motivacao'],
        'como_conheceu' => $f['como_conheceu'] ?? null, 'aceita_contato' => (int) ($f['aceita_contato'] ?? 0),
        'consentimento_em' => $f['consentimento_em'] ?? $agora, 'politica_versao' => $f['politica_versao'] ?? mcp_voluntario_politica_versao(),
        'veracidade_em' => $f['veracidade_em'] ?? $agora,
        'pagina' => $f['pagina'] ?? null, 'utm_source' => $f['utm_source'] ?? null, 'utm_medium' => $f['utm_medium'] ?? null,
        'utm_campaign' => $f['utm_campaign'] ?? null, 'utm_content' => $f['utm_content'] ?? null, 'utm_term' => $f['utm_term'] ?? null,
        'fbclid' => $f['fbclid'] ?? null, 'gclid' => $f['gclid'] ?? null,
        'ip' => $f['ip'] ?? (mcp_ip() ?: null), 'status' => 'nova', 'criado_em' => $agora, 'atualizado_em' => $agora,
    ];
    $colunas = implode(', ', array_keys($linha));
    $marcadores = implode(', ', array_fill(0, count($linha), '?'));
    $pdo = mcp_db();
    $pdo->prepare("INSERT INTO mcp_voluntarios ($colunas) VALUES ($marcadores)")->execute(array_values($linha));
    return (int) $pdo->lastInsertId();
}

// ----------------------------------------------------------------------------- selfie (obrigatória)
/**
 * Selfie da ficha, obrigatória (pedido do Matheus, 27/10/2026; no começo do dia era opcional): só para a equipe reconhecer a pessoa na entrevista e no
 * CBFI, como diz a Política de Privacidade. Chega como arquivo do formulário (sem JavaScript, campo "selfie") ou como
 * data URL JPG/PNG que cadastro.js já reduziu (campo "selfie" do JSON). Aqui ela é conferida pelos primeiros bytes,
 * virada conforme o EXIF, reduzida a MCP_VOL_SELFIE_LADO e regravada em JPG pelo GD: a regravação descarta os
 * metadados (EXIF, com a localização do celular). Nunca vai para e-mail, PDF, CSV nem planilha; aparece só no portal.
 */

/**
 * O que a pessoa mandou no campo "selfie": null (não mandou), ['bruto' => bytes] ou ['erro' => mensagem].
 * $arquivo é $_FILES['selfie'] (formulário sem JavaScript); $dataUrl, o campo do JSON.
 */
function mcp_voluntario_selfie_entrada(mixed $dataUrl, ?array $arquivo): ?array
{
    $muitoGrande = 'A selfie pode ter até ' . (MCP_VOL_SELFIE_BYTES >> 20) . ' MB. Tire outra foto.';
    if (is_array($arquivo) && isset($arquivo['error']) && !is_array($arquivo['error']) && (int) $arquivo['error'] !== UPLOAD_ERR_NO_FILE) {
        $erro = (int) $arquivo['error'];
        if (in_array($erro, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) ($arquivo['size'] ?? 0) > MCP_VOL_SELFIE_BYTES) {
            return ['erro' => $muitoGrande];
        }
        $tmp = (string) ($arquivo['tmp_name'] ?? '');
        if ($erro !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            return ['erro' => 'A selfie não chegou inteira. Envie de novo.'];
        }
        return ['bruto' => (string) file_get_contents($tmp)];
    }
    if (!is_string($dataUrl) || $dataUrl === '') {
        return null;
    }
    if (!preg_match('#^data:image/(?:jpeg|png);base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m)) {
        return ['erro' => 'A selfie precisa ser uma foto em JPG ou PNG.'];
    }
    $bruto = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);
    if ($bruto === false || $bruto === '') {
        return ['erro' => 'A selfie não chegou inteira. Envie de novo.'];
    }
    return strlen($bruto) > MCP_VOL_SELFIE_BYTES ? ['erro' => $muitoGrande] : ['bruto' => $bruto];
}

/**
 * Confere, vira, reduz e regrava em JPG sem metadados. Devolve ['ok' => true, 'jpeg', 'largura', 'altura'] ou
 * ['ok' => false, 'motivo' => 'formato'|'gd', 'erro' => mensagem]. 'gd': o servidor não tem como tratar imagens (a ficha
 * segue sem a selfie, para não guardar a foto com a localização).
 */
function mcp_voluntario_selfie_preparar(string $bruto): array
{
    $formato = ['ok' => false, 'motivo' => 'formato', 'erro' => 'A selfie precisa ser uma foto em JPG ou PNG.'];
    $jpeg = str_starts_with($bruto, "\xFF\xD8\xFF");
    if (!$jpeg && !str_starts_with($bruto, "\x89PNG\r\n\x1A\n")) {
        return $formato;
    }
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        return ['ok' => false, 'motivo' => 'gd', 'erro' => 'O servidor não conseguiu tratar a imagem.'];
    }
    $dim = @getimagesizefromstring($bruto);
    if ($dim === false || $dim[0] < 1 || $dim[1] < 1) {
        return $formato;
    }
    // Acima de 50 megapixels (bomba de descompressão, ou câmera no modo de resolução máxima) a foto nem é aberta.
    if ($dim[0] * $dim[1] > 50_000_000) {
        return ['ok' => false, 'motivo' => 'formato', 'erro' => 'Essa foto tem resolução alta demais. Tire outra com a câmera no modo normal.'];
    }
    // O GD guarda ~5 bytes por pixel: uma foto de 48 MP pede ~240 MB. Sobe o limite só se o atual não der conta.
    $precisa = (int) ($dim[0] * $dim[1] * 5) + 64 * 1024 * 1024;
    $limite = mcp_bytes_ini((string) ini_get('memory_limit'));
    if ($limite > 0 && $limite < $precisa) {
        @ini_set('memory_limit', (string) $precisa);
    }
    $img = @imagecreatefromstring($bruto);
    if ($img === false) {
        return $formato;
    }
    // A câmera grava a foto "deitada" e diz no EXIF como girar; o JPG regravado não leva o EXIF, então gira aqui.
    if ($jpeg && function_exists('exif_read_data')) {
        // Lido de um fluxo em memória: não depende de allow_url_fopen (o data:// dependeria).
        $fluxo = fopen('php://memory', 'r+b');
        $exif = false;
        if ($fluxo !== false) {
            fwrite($fluxo, $bruto);
            rewind($fluxo);
            $exif = @exif_read_data($fluxo);
            fclose($fluxo);
        }
        $giro = match ((int) ($exif['Orientation'] ?? 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($giro !== 0) {
            $girada = imagerotate($img, $giro, 0);
            if ($girada !== false) {
                imagedestroy($img);
                $img = $girada;
            }
        }
    }
    $largura = imagesx($img);
    $altura = imagesy($img);
    $escala = min(1, MCP_VOL_SELFIE_LADO / max($largura, $altura));
    $novaLargura = max(1, (int) round($largura * $escala));
    $novaAltura = max(1, (int) round($altura * $escala));
    // Fundo branco: o PNG transparente não fica preto no JPG.
    $tela = imagecreatetruecolor($novaLargura, $novaAltura);
    imagefill($tela, 0, 0, (int) imagecolorallocate($tela, 255, 255, 255));
    imagecopyresampled($tela, $img, 0, 0, 0, 0, $novaLargura, $novaAltura, $largura, $altura);
    imagedestroy($img);
    ob_start();
    imagejpeg($tela, null, MCP_VOL_SELFIE_QUALIDADE);
    $saida = (string) ob_get_clean();
    imagedestroy($tela);
    return $saida === '' ? $formato : ['ok' => true, 'jpeg' => $saida, 'largura' => $novaLargura, 'altura' => $novaAltura];
}

function mcp_voluntario_selfie_gravar(int $fichaId, array $selfie): void
{
    mcp_db()->prepare('REPLACE INTO mcp_voluntarios_selfies (ficha_id, tipo, largura, altura, bytes, conteudo, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$fichaId, 'image/jpeg', (int) $selfie['largura'], (int) $selfie['altura'], strlen((string) $selfie['jpeg']), base64_encode((string) $selfie['jpeg']), mcp_agora()]);
}

/** "128M", "1G", "-1" (sem limite = 0) em bytes. */
function mcp_bytes_ini(string $valor): int
{
    $valor = trim($valor);
    if ($valor === '' || $valor === '-1') {
        return 0;
    }
    $n = (int) $valor;
    return match (strtolower(substr($valor, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
}

/** A selfie da ficha (['jpeg', 'largura', 'altura', 'bytes', 'criado_em']) ou null. Sem a tabela (antes da migração), null. */
function mcp_voluntario_selfie(int $fichaId): ?array
{
    try {
        $stmt = mcp_db()->prepare('SELECT largura, altura, bytes, conteudo, criado_em FROM mcp_voluntarios_selfies WHERE ficha_id = ? LIMIT 1');
        $stmt->execute([$fichaId]);
        $linha = $stmt->fetch();
    } catch (PDOException) {
        return null;
    }
    if (!$linha) {
        return null;
    }
    $jpeg = base64_decode((string) $linha['conteudo'], true);
    return $jpeg === false ? null : ['jpeg' => $jpeg, 'largura' => (int) $linha['largura'], 'altura' => (int) $linha['altura'],
        'bytes' => (int) $linha['bytes'], 'criado_em' => (string) $linha['criado_em']];
}

/** Apaga a selfie das fichas (pedido da pessoa pelo portal, ou expurgo). Devolve quantas apagou. */
function mcp_voluntario_selfie_apagar(array $fichaIds): int
{
    $ids = array_values(array_filter(array_map('intval', $fichaIds), static fn(int $i): bool => $i > 0));
    if (!$ids) {
        return 0;
    }
    try {
        $stmt = mcp_db()->prepare('DELETE FROM mcp_voluntarios_selfies WHERE ficha_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        return $stmt->rowCount();
    } catch (PDOException) {
        return 0;
    }
}

function mcp_voluntario_por_id(int $id): ?array
{
    $stmt = mcp_db()->prepare('SELECT * FROM mcp_voluntarios WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Protocolo legível para a pessoa citar: VOL-aammdd-NNNN (data de Brasília, id da ficha). */
function mcp_voluntario_protocolo(int $id, ?string $agoraUtc = null): string
{
    $data = (new DateTimeImmutable($agoraUtc ?? mcp_agora(), new DateTimeZone('UTC')))->setTimezone(mcp_voluntario_fuso());
    return sprintf('VOL-%s-%04d', $data->format('ymd'), $id);
}

/** Atualiza colunas conhecidas da ficha (as chaves vêm sempre do código, nunca do cliente). */
function mcp_voluntario_atualizar(int $id, array $campos): void
{
    foreach (array_keys($campos) as $coluna) {
        if (!preg_match('/^[a-z_]+$/', (string) $coluna)) {
            throw new InvalidArgumentException("coluna inválida: $coluna");
        }
    }
    $campos['atualizado_em'] = mcp_agora();
    $sets = implode(', ', array_map(static fn($c) => "$c = :$c", array_keys($campos)));
    $campos['id'] = $id;
    mcp_db()->prepare("UPDATE mcp_voluntarios SET $sets WHERE id = :id")->execute($campos);
}

/** Quantas fichas uma chave (ip ou email) mandou nos últimos N segundos. Base dos freios. */
function mcp_voluntarios_contar_recentes(string $coluna, string $valor, int $segundos): int
{
    if (!in_array($coluna, ['ip', 'email'], true)) {
        throw new InvalidArgumentException("coluna não permitida: $coluna");
    }
    $stmt = mcp_db()->prepare("SELECT COUNT(*) FROM mcp_voluntarios WHERE $coluna = ? AND criado_em > ?");
    $stmt->execute([$valor, gmdate('Y-m-d H:i:s', time() - $segundos)]);
    return (int) $stmt->fetchColumn();
}

/** Ficha em aberto ('nova' ou 'em_analise') do mesmo e-mail nos últimos 30 dias, ou null. */
function mcp_voluntario_duplicada(string $email, int $dias = MCP_VOL_DUPLICADA_DIAS): ?array
{
    $stmt = mcp_db()->prepare("SELECT id, protocolo, nome, status, criado_em FROM mcp_voluntarios
        WHERE email = ? AND status IN ('nova', 'em_analise') AND criado_em > ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$email, gmdate('Y-m-d H:i:s', time() - $dias * 86400)]);
    return $stmt->fetch() ?: null;
}

/** WHERE e parâmetros dos filtros do portal: status (um ou lista), busca (nome, e-mail, protocolo, cidade), frente. */
function mcp_voluntarios_filtro_sql(array $filtros): array
{
    $onde = [];
    $params = [];
    $status = $filtros['status'] ?? null;
    if (is_string($status) && $status !== '') {
        $status = [$status];
    }
    if (is_array($status)) {
        $validos = array_values(array_filter($status, static fn($s): bool => is_string($s) && isset(MCP_VOL_STATUS[$s])));
        if ($validos) {
            $onde[] = 'status IN (' . implode(', ', array_fill(0, count($validos), '?')) . ')';
            array_push($params, ...$validos);
        }
    }
    $busca = mcp_texto($filtros['busca'] ?? '', 120);
    if ($busca !== '') {
        $like = '%' . addcslashes($busca, '%_\\') . '%';
        $onde[] = '(nome LIKE ? OR email LIKE ? OR protocolo LIKE ? OR cidade LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    $frente = mcp_texto($filtros['frente'] ?? '', 40);
    if ($frente !== '' && isset(MCP_VOL_FRENTES[$frente])) {
        $onde[] = 'FIND_IN_SET(?, frentes) > 0';
        $params[] = $frente;
    }
    return [$onde ? ' WHERE ' . implode(' AND ', $onde) : '', $params];
}

/**
 * Fichas para o portal, mais recentes primeiro, com paginação. Filtros: status (string ou lista), busca, frente.
 * @return array{linhas: list<array>, total: int}
 */
function mcp_voluntarios_listar(array $filtros, int $pagina = 1, int $porPagina = 50): array
{
    [$onde, $params] = mcp_voluntarios_filtro_sql($filtros);
    $pdo = mcp_db();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM mcp_voluntarios$onde");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    $porPagina = max(1, min(5000, $porPagina));
    $deslocamento = (max(1, $pagina) - 1) * $porPagina;
    $stmt = $pdo->prepare('SELECT ' . MCP_VOL_COLUNAS_LISTA . " FROM mcp_voluntarios$onde ORDER BY id DESC LIMIT $porPagina OFFSET $deslocamento");
    $stmt->execute($params);
    return ['linhas' => $stmt->fetchAll(), 'total' => $total];
}

/** Total de fichas; com $status, só as daquela situação. */
function mcp_voluntarios_contar(?string $status = null): int
{
    if ($status !== null) {
        if (!isset(MCP_VOL_STATUS[$status])) {
            return 0;
        }
        $stmt = mcp_db()->prepare('SELECT COUNT(*) FROM mcp_voluntarios WHERE status = ?');
        $stmt->execute([$status]);
        return (int) $stmt->fetchColumn();
    }
    return (int) mcp_db()->query('SELECT COUNT(*) FROM mcp_voluntarios')->fetchColumn();
}

/** Fichas ainda não lidas (status 'nova'): a contagem do menu do portal. */
function mcp_voluntarios_novas_contar(): int
{
    return mcp_voluntarios_contar('nova');
}

/**
 * Muda a situação da ficha, registra quem e quando (status_por, status_em), o evento 'voluntario_status' e, se vier,
 * a nota. Devolve a mensagem do erro ou null. 'aceita' só por mcp_voluntario_aceitar() (precisa do CPF para criar o
 * colaborador do ponto), salvo quando a ficha já tem colaborador.
 */
function mcp_voluntario_mudar_status(int $id, string $status, string $quem, string $nota = ''): ?string
{
    if (!isset(MCP_VOL_STATUS[$status])) {
        return 'Situação desconhecida.';
    }
    $ficha = mcp_voluntario_por_id($id);
    if ($ficha === null) {
        return 'Ficha não encontrada.';
    }
    if ($status === 'aceita' && empty($ficha['colaborador_id'])) {
        return 'Para aceitar, use a ação "Aceitar" com o CPF: ela cria o cadastro no ponto da sede.';
    }
    $quem = mb_substr(trim($quem), 0, 190);
    $antes = (string) $ficha['status'];
    if ($antes !== $status) {
        mcp_voluntario_atualizar($id, ['status' => $status, 'status_em' => mcp_agora(), 'status_por' => $quem]);
        mcp_registrar(null, 'voluntario_status', "#$id · $antes → $status · $quem");
    }
    $nota = mcp_texto_longo($nota, 4000);
    if ($nota !== '') {
        mcp_voluntario_nota_registrar($id, $quem, $nota);
    }
    return null;
}

function mcp_voluntario_nota_registrar(int $id, string $quem, string $texto): void
{
    $texto = mcp_texto_longo($texto, 4000);
    if ($texto === '') {
        return;
    }
    mcp_db()->prepare('INSERT INTO mcp_voluntarios_notas (ficha_id, quem, texto, criado_em) VALUES (?, ?, ?, ?)')
        ->execute([$id, mb_substr(trim($quem), 0, 190), $texto, mcp_agora()]);
}

/** Notas da ficha, da mais antiga para a mais nova. */
function mcp_voluntario_notas(int $id): array
{
    $stmt = mcp_db()->prepare('SELECT id, ficha_id, quem, texto, criado_em FROM mcp_voluntarios_notas WHERE ficha_id = ? ORDER BY criado_em, id');
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

/**
 * Aceita a ficha: cria (ou reaproveita, pelo CPF) o colaborador do ponto da sede com vínculo 'voluntario', função
 * "Voluntário(a)" e ativo, grava colaborador_id e status 'aceita'. O CPF vem do portal ou da própria ficha; sem um
 * CPF válido, devolve o erro pedindo-o. Um colaborador que já existe inativo é reativado; um ativo fica como está.
 *
 * @return array{ok: bool, erro?: string, colaborador_id?: int}
 */
function mcp_voluntario_aceitar(int $id, string $quem, string $cpf = ''): array
{
    $ficha = mcp_voluntario_por_id($id);
    if ($ficha === null) {
        return ['ok' => false, 'erro' => 'Ficha não encontrada.'];
    }
    $cpf = mcp_digitos($cpf) ?: mcp_digitos((string) ($ficha['cpf'] ?? ''));
    if (!mcp_cpf_valido($cpf)) {
        return ['ok' => false, 'erro' => 'Informe o CPF do(a) voluntário(a) para criar o cadastro no ponto da sede.'];
    }
    $quem = mb_substr(trim($quem), 0, 190);
    $existente = mcp_colaborador_por_cpf($cpf);
    if ($existente === null) {
        $colaboradorId = mcp_colaborador_salvar(null, [
            'nome' => (string) $ficha['nome'], 'cpf' => $cpf, 'email' => (string) $ficha['email'], 'telefone' => (string) $ficha['telefone'],
            'funcao' => 'Voluntário(a)', 'vinculo' => 'voluntario', 'ativo' => 1,
        ], $quem);
        if ($colaboradorId === null) {
            return ['ok' => false, 'erro' => 'Esse CPF já é de outro colaborador.'];
        }
    } else {
        $colaboradorId = (int) $existente['id'];
        if (!(int) $existente['ativo'] || !in_array((string) $existente['vinculo'], MCP_PONTO_VINCULOS_VOLUNTARIOS, true)) {
            // Reativa (ou passa a voluntário) sem apagar o que a secretaria já preencheu: só completa o que estava vazio.
            mcp_colaborador_salvar($colaboradorId, [
                'nome' => (string) $existente['nome'], 'cpf' => $cpf,
                'email' => (string) ($existente['email'] ?: $ficha['email']), 'telefone' => (string) ($existente['telefone'] ?: $ficha['telefone']),
                'funcao' => (string) ($existente['funcao'] ?: 'Voluntário(a)'),
                'vinculo' => in_array((string) $existente['vinculo'], MCP_PONTO_VINCULOS_VOLUNTARIOS, true) ? (string) $existente['vinculo'] : 'voluntario',
                'ativo' => 1,
            ], $quem);
        }
    }
    $agora = mcp_agora();
    $campos = ['colaborador_id' => $colaboradorId, 'status' => 'aceita', 'status_em' => $agora, 'status_por' => $quem];
    if (empty($ficha['cpf'])) {
        $campos['cpf'] = $cpf; // o CPF informado na aceitação fica na ficha, para o PDF e para a conferência
    }
    mcp_voluntario_atualizar($id, $campos);
    mcp_registrar(null, 'voluntario_status', "#$id · {$ficha['status']} → aceita · $quem · colaborador #$colaboradorId" . ($existente ? ' (já existia)' : ''));
    return ['ok' => true, 'colaborador_id' => $colaboradorId];
}

/** Gancho do Drive: enfileira a ficha para o PDF e a planilha, se o módulo estiver publicado. Falha não derruba a requisição. */
function mcp_voluntario_drive_enfileirar(int $id): void
{
    if (!function_exists('mcp_drive_enfileirar')) {
        return;
    }
    try {
        mcp_drive_enfileirar('ficha_voluntario', $id);
    } catch (Throwable $e) {
        error_log('[matricula] fila do Drive (ficha_voluntario #' . $id . '): ' . $e->getMessage());
    }
}

/**
 * Expurgo prometido na Política de Privacidade: apaga as fichas que não foram aceitas (nova, em análise, entrevista,
 * recusada, arquivada) com mais de $meses meses, com as notas delas; as que já foram ao Drive entram na fila para o PDF e
 * a linha da planilha saírem de lá também (tipo 'apagar_ficha_drive', quando o módulo do Drive existir). As aceitas
 * ficam: viraram colaboradoras do ponto, e o termo de adesão rege a guarda. Devolve quantas apagou.
 *
 * @return array{apagadas: int}
 */
function mcp_voluntarios_expurgar(int $meses = 12, ?string $agoraUtc = null): array
{
    $meses = max(1, $meses);
    $corte = (new DateTimeImmutable($agoraUtc ?? mcp_agora(), new DateTimeZone('UTC')))->modify("-$meses months")->format('Y-m-d H:i:s');
    $pdo = mcp_db();
    $stmt = $pdo->prepare("SELECT id, protocolo, drive_pdf_id, drive_planilha_em FROM mcp_voluntarios WHERE status <> 'aceita' AND criado_em < ? ORDER BY id");
    $stmt->execute([$corte]);
    $fichas = $stmt->fetchAll();
    if (!$fichas) {
        return ['apagadas' => 0];
    }
    foreach ($fichas as $f) {
        if ((!empty($f['drive_pdf_id']) || !empty($f['drive_planilha_em'])) && function_exists('mcp_drive_enfileirar')) {
            try {
                mcp_drive_enfileirar('apagar_ficha_drive', null, ['protocolo' => (string) $f['protocolo'], 'drive_pdf_id' => (string) ($f['drive_pdf_id'] ?? '')]);
            } catch (Throwable $e) {
                error_log('[matricula] fila do Drive (apagar_ficha_drive ' . $f['protocolo'] . '): ' . $e->getMessage());
            }
        }
    }
    $ids = array_map('intval', array_column($fichas, 'id'));
    $marcadores = implode(', ', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM mcp_voluntarios_notas WHERE ficha_id IN ($marcadores)")->execute($ids);
    mcp_voluntario_selfie_apagar($ids);
    $pdo->prepare("DELETE FROM mcp_voluntarios WHERE id IN ($marcadores)")->execute($ids);
    $apagadas = count($ids);
    mcp_registrar(null, 'voluntarios_expurgo', $apagadas . ($apagadas === 1 ? ' ficha' : ' fichas'));
    return ['apagadas' => $apagadas];
}

/**
 * O expurgo uma vez por dia (data de Brasília em mcp_chaves 'voluntarios_expurgo_em'), para a rotina de 15 minutos
 * (api/comparecimentos.php). Devolve null quando o dia já foi feito; senão, o resultado do expurgo.
 */
function mcp_voluntarios_expurgo_diario(?int $agora = null): ?array
{
    $hoje = mcp_voluntario_hoje($agora);
    $pdo = mcp_db();
    $stmt = $pdo->prepare("SELECT valor FROM mcp_chaves WHERE nome = 'voluntarios_expurgo_em'");
    $stmt->execute();
    if ($stmt->fetchColumn() === $hoje) {
        return null;
    }
    $resultado = mcp_voluntarios_expurgar();
    $pdo->prepare("INSERT INTO mcp_chaves (nome, valor, criado_em) VALUES ('voluntarios_expurgo_em', ?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor), criado_em = VALUES(criado_em)")
        ->execute([$hoje, gmdate('Y-m-d H:i:s', $agora ?? time())]);
    return $resultado;
}

// ----------------------------------------------------------------------------- textos da ficha (PDF, e-mails)
/** "17 anos" / "x anos" a partir do nascimento e da data de referência (criado_em, para o PDF não envelhecer a pessoa). */
function mcp_voluntario_idade_texto(array $f): string
{
    $referencia = !empty($f['criado_em']) ? mcp_data_brt((string) $f['criado_em'], 'Y-m-d') : null;
    $idade = mcp_voluntario_idade((string) $f['data_nascimento'], $referencia ?: null);
    return $idade === null ? '' : $idade . ' anos';
}

/** "Rio de Janeiro, Centro · RJ" */
function mcp_voluntario_local_texto(array $f): string
{
    $local = (string) $f['cidade'] . (!empty($f['bairro']) ? ', ' . $f['bairro'] : '');
    return $local . (!empty($f['estado']) ? ' · ' . $f['estado'] : '');
}

/** Nascimento DD/MM/AAAA. */
function mcp_voluntario_nascimento_texto(array $f): string
{
    $d = mcp_voluntario_data((string) $f['data_nascimento']);
    return $d === null ? (string) $f['data_nascimento'] : implode('/', array_reverse(explode('-', $d)));
}

/**
 * Linhas rótulo/valor da ficha, em grupos, para o PDF e para a caixa do e-mail à equipe. $comCpf põe a linha do
 * CPF parcial (PDF e portal); os e-mails não a levam.
 *
 * @return list<array{0: string, 1: list<array{0: string, 1: string}>}> [[título do grupo, [[rótulo, valor], ...]], ...]
 */
function mcp_voluntario_grupos(array $f, bool $comCpf = false): array
{
    $saude = (int) ($f['saude'] ?? 0) === 1 ? 'Sim' . (!empty($f['saude_qual']) ? ': ' . $f['saude_qual'] : '') : 'Não';
    $pessoa = [
        ['Nome', (string) $f['nome']],
        ['Nascimento', trim(mcp_voluntario_nascimento_texto($f) . ' · ' . mcp_voluntario_idade_texto($f), ' ·')],
    ];
    if ($comCpf) {
        $pessoa[] = ['CPF', mcp_voluntario_cpf_parcial($f['cpf'] ?? null) ?: 'não informado'];
    }
    $pessoa[] = ['E-mail', (string) $f['email']];
    $pessoa[] = ['Telefone', mcp_telefone_bonito((string) $f['telefone'])];
    $pessoa[] = ['Onde mora', mcp_voluntario_local_texto($f)];
    $pessoa[] = ['Escolaridade', MCP_VOL_ESCOLARIDADES[$f['escolaridade']] ?? (string) $f['escolaridade']];
    $pessoa[] = ['Profissão', (string) ($f['profissao'] ?: 'não informada')];
    $pessoa[] = ['Formação na área da saúde', $saude];
    $grupos = [['Quem é', $pessoa]];
    $grupos[] = ['Como quer ajudar', [
        ['Frentes', mcp_voluntario_rotulos((string) $f['frentes'], MCP_VOL_FRENTES)],
        ['Dias', mcp_voluntario_rotulos((string) $f['dias'], MCP_VOL_DIAS)],
        ['Períodos', mcp_voluntario_rotulos((string) $f['periodos'], MCP_VOL_PERIODOS)],
        ['Como conheceu', !empty($f['como_conheceu']) ? (MCP_VOL_COMO_CONHECEU[$f['como_conheceu']] ?? (string) $f['como_conheceu']) : 'não informado'],
        ['Aceita contato por e-mail e telefone', (int) ($f['aceita_contato'] ?? 0) === 1 ? 'Sim' : 'Não'],
    ]];
    return $grupos;
}

/** Linhas dos consentimentos (LGPD): quando, sob qual revisão da política e a veracidade. */
function mcp_voluntario_consentimentos(array $f): array
{
    return [
        ['Política de Privacidade', 'Concordou em ' . mcp_data_brt((string) ($f['consentimento_em'] ?? ''), 'd/m/Y \à\s H\hi') . ' (Brasília), revisão ' . (string) ($f['politica_versao'] ?? '')],
        ['Veracidade das informações', 'Declarou em ' . mcp_data_brt((string) ($f['veracidade_em'] ?? ''), 'd/m/Y \à\s H\hi') . ' (Brasília)'],
    ];
}

// ----------------------------------------------------------------------------- PDF
/** "VOL-261016-0001-Maria_da_Silva.pdf" (o nome que vai para o Drive e para o portal). */
function mcp_voluntario_pdf_arquivo(array $f): string
{
    return mcp_nome_arquivo((string) ($f['protocolo'] ?: 'ficha-' . ($f['id'] ?? 0)) . ' ' . mcp_nome_proprio((string) $f['nome']));
}

/**
 * Bytes do PDF "Ficha de cadastro de voluntário(a)", em 1 ou 2 páginas: protocolo e situação, dados, frentes e
 * disponibilidade, experiência, habilidades, motivação, consentimentos (com data e revisão da política) e datas.
 * O CPF sai só com os 3 primeiros dígitos. Mesmo desenho das declarações (lib/declaracao.php).
 */
function mcp_voluntario_pdf(array $f): string
{
    [, $total] = mcp_voluntario_pdf_desenhar($f, 0);
    [$pdf] = mcp_voluntario_pdf_desenhar($f, $total);
    return $pdf->gerar();
}

/** @return array{0: McpPdf, 1: int} o PDF e o número de páginas ($total 0 = ainda não se sabe) */
function mcp_voluntario_pdf_desenhar(array $f, int $total): array
{
    $pdf = new McpPdf();
    $protocolo = (string) ($f['protocolo'] ?? '');
    $nome = (string) $f['nome'];
    $titulo = 'FICHA DE CADASTRO DE VOLUNTÁRIO(A)';
    $pdf->metadados("Ficha de cadastro de voluntário(a) — $nome", MCP_NOME_FILIAL, "Ficha de cadastro de voluntário(a) $protocolo");
    $esq = MCP_CP_MARGEM;
    $dir = McpPdf::LARGURA - MCP_CP_MARGEM;
    $largura = $dir - $esq;
    $limite = McpPdf::ALTURA - 60;
    $passo = 13.2;
    $tam = 10.0;
    $colunaValor = $esq + 150;
    $pagina = 1;
    $gerado = mcp_data_brt(mcp_agora(), 'd/m/Y \à\s H\hi');
    $rodapeTexto = 'Ficha de cadastro de voluntário(a)' . ($protocolo !== '' ? " · $protocolo" : '') . " · gerada em $gerado · $nome";

    $rodape = static function () use ($pdf, $esq, $dir, $total, &$pagina, $rodapeTexto): void {
        $base = McpPdf::ALTURA - 34;
        $pdf->linha($esq, $base - 14, $dir, $base - 14, MCP_CP_FIO, 0.8);
        $numero = $total > 0 ? "Página $pagina de $total" : "Página $pagina";
        $larguraNumero = McpPdf::larguraTexto($numero, 'normal', 8.4);
        $linhas = McpPdf::quebrar($rodapeTexto, 'normal', 8.4, $dir - $esq - $larguraNumero - 24);
        $pdf->texto($esq, $base, $linhas[0] . (count($linhas) > 1 ? '…' : ''), 'normal', 8.4, MCP_CP_CINZA);
        $pdf->texto($dir - $larguraNumero, $base, $numero, 'normal', 8.4, MCP_CP_CINZA);
    };
    $virar = static function () use ($pdf, $esq, $dir, $rodape, $titulo, &$pagina): float {
        $rodape();
        $pdf->novaPagina();
        $pagina++;
        $pdf->retangulo(0, 0, McpPdf::LARGURA, 8, MCP_CP_VERMELHO);
        $pdf->texto($esq, 38, $titulo, 'negrito', 8.6, MCP_CP_CINZA, 0.9);
        $pdf->linha($esq, 50, $dir, 50, MCP_CP_FIO, 0.8);
        return 64.0;
    };
    $y = 0.0;
    // Garante espaço para $avanco; vira a página se não couber. Devolve a linha de base nova.
    $avancar = static function (float $avanco) use ($limite, $virar, &$y): void {
        if ($y + $avanco > $limite) {
            $y = $virar() + min($avanco, 14.0);
        } else {
            $y += $avanco;
        }
    };
    $secao = static function (string $texto) use ($pdf, $esq, $dir, $avancar, &$y): void {
        $avancar(20);
        $pdf->texto($esq, $y, mb_strtoupper($texto), 'negrito', 8.8, MCP_CP_VERMELHO, 0.9);
        $pdf->linha($esq, $y + 4, $dir, $y + 4, MCP_CP_FIO, 0.8);
        $y += 4;
    };
    // Linha rótulo/valor: o valor quebra na coluna da direita; o rótulo fica na primeira linha.
    $campo = static function (string $rotulo, string $valor) use ($pdf, $esq, $dir, $colunaValor, $passo, $tam, $avancar, &$y): void {
        $linhas = McpPdf::quebrar($valor !== '' ? $valor : '—', 'normal', $tam, $dir - $colunaValor);
        foreach ($linhas as $i => $l) {
            $avancar($i === 0 ? $passo + 2 : $passo);
            if ($i === 0) {
                foreach (McpPdf::quebrar($rotulo, 'negrito', 8.6, $colunaValor - $esq - 10) as $j => $r) {
                    $pdf->texto($esq, $y + $j * 10.5, $r, 'negrito', 8.6, MCP_CP_CINZA, 0.4);
                }
            }
            $pdf->texto($colunaValor, $y, $l, 'normal', $tam, MCP_CP_TEXTO);
        }
    };
    // Texto corrido (motivação, experiência, habilidades), com os parágrafos da pessoa.
    $paragrafos = static function (string $texto) use ($pdf, $esq, $largura, $passo, $tam, $avancar, &$y): void {
        $primeiro = true;
        foreach (preg_split('/\n+/', trim($texto)) ?: [] as $paragrafo) {
            if (trim($paragrafo) === '') {
                continue;
            }
            foreach (McpPdf::quebrar($paragrafo, 'normal', $tam, $largura) as $i => $l) {
                $avancar($i === 0 ? ($primeiro ? $passo + 3 : $passo + 5) : $passo);
                $pdf->texto($esq, $y, $l, 'normal', $tam, MCP_CP_TEXTO);
            }
            $primeiro = false;
        }
    };

    // Cabeçalho da primeira página, igual ao das declarações: faixa, logo e fio.
    $pdf->retangulo(0, 0, McpPdf::LARGURA, 8, MCP_CP_VERMELHO);
    $logoL = 345.0;
    $logoA = $logoL * 398 / 1325;
    $pdf->imagemPng(MCP_COMPROVANTE_LOGO, $esq - 0.0257 * $logoL, 44 - 0.0427 * $logoA, $logoL, $logoA);
    $pdf->linha($esq, 158, $dir, 158, MCP_CP_FIO, 0.8);
    $pdf->texto($esq, 186, $titulo, 'negrito', 17, MCP_CP_TEXTO, 0.4);
    $pdf->texto($esq, 204, 'Programa de voluntariado · Cruz Vermelha Brasileira · Filial do Estado do Rio de Janeiro', 'normal', 11, MCP_CP_CINZA);

    // Caixa com a faixa vermelha: nome, protocolo, situação e quando chegou.
    $topo = 220.0;
    $recebida = mcp_data_brt((string) ($f['criado_em'] ?? ''), 'd/m/Y \à\s H\hi');
    $situacao = mcp_voluntario_status_rotulo((string) ($f['status'] ?? 'nova'))
        . (!empty($f['status_em']) ? ' desde ' . mcp_data_brt((string) $f['status_em'], 'd/m/Y') : '');
    $caixa = [
        ['VOLUNTÁRIO(A)', McpPdf::quebrar($nome, 'negrito', 14, $dir - 18 - MCP_CP_ROTULO_X)],
        ['PROTOCOLO · FICHA RECEBIDA EM', [($protocolo !== '' ? $protocolo : 'sem protocolo') . ' · ' . ($recebida !== '' ? "$recebida (Brasília)" : 'data não registrada')]],
        ['SITUAÇÃO', [$situacao]],
    ];
    $altura = 14.0;
    foreach ($caixa as [, $linhas]) {
        $altura += 20 + 13 + (count($linhas) - 1) * $passo;
    }
    $pdf->retanguloArredondado($esq, $topo, 13, $altura, [7, 0, 0, 7], MCP_CP_VERMELHO);
    $pdf->retanguloArredondado($esq + 5, $topo, $largura - 5, $altura, [0, 7, 7, 0], MCP_CP_CAIXA);
    $yy = $topo;
    foreach ($caixa as $i => [$rotulo, $linhas]) {
        $yy += 20;
        $pdf->texto(MCP_CP_ROTULO_X, $yy, $rotulo, 'negrito', 8.6, MCP_CP_CINZA, 0.9);
        $yy += 13 - $passo;
        foreach ($linhas as $l) {
            $yy += $passo;
            $pdf->texto(MCP_CP_ROTULO_X, $yy, $l, $i === 0 ? 'negrito' : 'normal', $i === 0 ? 14 : $tam, MCP_CP_TEXTO);
        }
    }
    $y = $topo + $altura;

    foreach (mcp_voluntario_grupos($f, true) as [$tituloGrupo, $linhas]) {
        $secao($tituloGrupo);
        foreach ($linhas as [$rotulo, $valor]) {
            $campo($rotulo, $valor);
        }
    }
    foreach ([['Motivação', (string) $f['motivacao']], ['Experiência com voluntariado', (string) ($f['experiencia'] ?? '')], ['Habilidades e formação', (string) ($f['habilidades'] ?? '')]] as [$tituloTexto, $texto]) {
        if (trim($texto) === '') {
            continue;
        }
        $secao($tituloTexto);
        $paragrafos($texto);
    }
    $secao('Consentimentos (LGPD)');
    foreach (mcp_voluntario_consentimentos($f) as [$rotulo, $valor]) {
        $campo($rotulo, $valor);
    }
    if (mcp_voluntario_idade((string) $f['data_nascimento'], mcp_data_brt((string) ($f['criado_em'] ?? mcp_agora()), 'Y-m-d') ?: null) < MCP_VOL_MAIORIDADE) {
        $campo('Menor de 18 anos', 'O termo de adesão é assinado também pelo responsável legal.');
    }
    $rodape();
    return [$pdf, $pagina];
}

// ----------------------------------------------------------------------------- planilha (CSV, sem CPF)
/** Planilha das fichas que passam pelos filtros (até 5000 linhas), sem CPF e sem IP. */
function mcp_voluntarios_csv(array $filtros): string
{
    return mcp_voluntarios_csv_linhas(mcp_voluntarios_listar($filtros, 1, 5000)['linhas']);
}

/** O CSV (; e BOM, abre direto no Excel e no Google Planilhas) a partir das linhas já lidas. Função pura. */
function mcp_voluntarios_csv_linhas(array $linhas): string
{
    $f = fopen('php://temp', 'w+');
    fputcsv($f, ['Recebida em (Brasília)', 'Protocolo', 'Situação', 'Nome', 'Nascimento', 'Idade', 'E-mail', 'Telefone', 'Cidade', 'Bairro', 'UF',
        'Escolaridade', 'Profissão', 'Formação em saúde', 'Qual', 'Frentes', 'Dias', 'Períodos', 'Experiência', 'Habilidades', 'Motivação',
        'Como conheceu', 'Aceita contato', 'Consentimento em (UTC)', 'Política (revisão)', 'Veracidade em (UTC)', 'Origem', 'Colaborador do ponto'], ';', '"', '');
    foreach ($linhas as $l) {
        fputcsv($f, array_map('mcp_horarios_celula', [
            mcp_data_brt((string) $l['criado_em'], 'd/m/Y H:i'), (string) ($l['protocolo'] ?? ''), mcp_voluntario_status_rotulo((string) $l['status']),
            (string) $l['nome'], mcp_voluntario_nascimento_texto($l), mcp_voluntario_idade_texto($l), (string) $l['email'],
            mcp_telefone_bonito((string) $l['telefone']), (string) $l['cidade'], (string) ($l['bairro'] ?? ''), (string) ($l['estado'] ?? ''),
            MCP_VOL_ESCOLARIDADES[$l['escolaridade']] ?? (string) $l['escolaridade'], (string) ($l['profissao'] ?? ''),
            (int) ($l['saude'] ?? 0) === 1 ? 'Sim' : 'Não', (string) ($l['saude_qual'] ?? ''),
            mcp_voluntario_rotulos((string) $l['frentes'], MCP_VOL_FRENTES), mcp_voluntario_rotulos((string) $l['dias'], MCP_VOL_DIAS),
            mcp_voluntario_rotulos((string) $l['periodos'], MCP_VOL_PERIODOS), (string) ($l['experiencia'] ?? ''), (string) ($l['habilidades'] ?? ''),
            (string) $l['motivacao'], !empty($l['como_conheceu']) ? (MCP_VOL_COMO_CONHECEU[$l['como_conheceu']] ?? (string) $l['como_conheceu']) : '',
            (int) ($l['aceita_contato'] ?? 0) === 1 ? 'Sim' : 'Não', (string) ($l['consentimento_em'] ?? ''), (string) ($l['politica_versao'] ?? ''),
            (string) ($l['veracidade_em'] ?? ''), trim((string) ($l['utm_source'] ?? '') . ' ' . (string) ($l['utm_campaign'] ?? '')) ?: 'direto',
            !empty($l['colaborador_id']) ? '#' . $l['colaborador_id'] : '',
        ]), ';', '"', '');
    }
    rewind($f);
    $csv = "\xEF\xBB\xBF" . stream_get_contents($f);
    fclose($f);
    return $csv;
}

// ----------------------------------------------------------------------------- e-mails
/** Caixa da coordenação de voluntariado, a mesma que a página da equipe publica. */
const MCP_EMAIL_VOLUNTARIADO_PADRAO = 'voluntariado@cruzvermelhariodejaneiro.org';

/**
 * Quem recebe as fichas e as mensagens do chat sobre voluntariado: EMAIL_VOLUNTARIADO (chave opcional do config) ou,
 * vazio, a coordenação de voluntariado. Até 25/10/2026 o padrão era o contato do site, e como o servidor não tinha a
 * chave, tudo de voluntariado chegava em contato@; a coordenação pediu para receber só ela.
 */
function mcp_voluntarios_email_destino(): string
{
    $destino = trim((string) (mcp_config()['EMAIL_VOLUNTARIADO'] ?? ''));
    return filter_var($destino, FILTER_VALIDATE_EMAIL) ? $destino : MCP_EMAIL_VOLUNTARIADO_PADRAO;
}

/** Link da ficha no portal da secretaria (botão "Abrir no portal"). */
function mcp_voluntario_link_portal(int $id): string
{
    return mcp_painel_url() . '?v=voluntario&id=' . $id;
}

/** A pessoa tinha menos de 18 anos quando mandou a ficha? */
function mcp_voluntario_menor(array $f): bool
{
    $referencia = !empty($f['criado_em']) ? (mcp_data_brt((string) $f['criado_em'], 'Y-m-d') ?: null) : null;
    $idade = mcp_voluntario_idade((string) $f['data_nascimento'], $referencia);
    return $idade !== null && $idade < MCP_VOL_MAIORIDADE;
}

/** Aviso à equipe de voluntariado: quem é, o que quer fazer, a motivação e o botão para abrir a ficha no portal. */
function mcp_montar_email_voluntario_equipe(array $f): array
{
    $nome = (string) $f['nome'];
    $primeiro = mcp_primeiro_nome($nome);
    $protocolo = (string) ($f['protocolo'] ?? '');
    $email = (string) $f['email'];
    $telefone = (string) $f['telefone'];
    $menor = mcp_voluntario_menor($f);
    $idade = mcp_voluntario_idade_texto($f);
    $frentes = mcp_voluntario_rotulos((string) $f['frentes'], MCP_VOL_FRENTES);
    $portal = mcp_voluntario_link_portal((int) ($f['id'] ?? 0));
    $mailto = 'mailto:' . $email . '?subject=' . rawurlencode("Re: Voluntariado · $protocolo");

    $linhas = [
        'Nome' => $nome,
        'Idade' => $idade !== '' ? $idade . ' (' . mcp_voluntario_nascimento_texto($f) . ')' : mcp_voluntario_nascimento_texto($f),
        'E-mail' => ['html' => '<a href="mailto:' . mcp_escapar($email) . '" style="color:#cc0000;text-decoration:none">' . mcp_escapar($email) . '</a>'],
        'Telefone' => ['html' => '<a href="tel:+55' . mcp_escapar(mcp_digitos($telefone)) . '" style="color:#1a202c;text-decoration:none">' . mcp_escapar(mcp_telefone_bonito($telefone)) . '</a>'],
        'Onde mora' => mcp_voluntario_local_texto($f),
        'Escolaridade' => MCP_VOL_ESCOLARIDADES[$f['escolaridade']] ?? (string) $f['escolaridade'],
        'Profissão' => (string) ($f['profissao'] ?: 'não informada'),
        'Formação na área da saúde' => (int) ($f['saude'] ?? 0) === 1 ? 'Sim' . (!empty($f['saude_qual']) ? ': ' . $f['saude_qual'] : '') : 'Não',
        'Frentes' => $frentes,
        'Disponibilidade' => mcp_voluntario_rotulos((string) $f['dias'], MCP_VOL_DIAS) . ' · ' . mcp_voluntario_rotulos((string) $f['periodos'], MCP_VOL_PERIODOS),
        'Como conheceu' => !empty($f['como_conheceu']) ? (MCP_VOL_COMO_CONHECEU[$f['como_conheceu']] ?? (string) $f['como_conheceu']) : 'não informado',
        'CPF' => !empty($f['cpf']) ? 'informado (pede-se na aceitação)' : 'não informado (pede-se na aceitação)',
        'Página' => (string) ($f['pagina'] ?: 'não informada'),
        'Origem' => trim((string) ($f['utm_source'] ?? '') . ' ' . (string) ($f['utm_campaign'] ?? '')) ?: 'direto',
    ];
    $quando = mcp_data_brt((string) ($f['criado_em'] ?? ''), 'd/m/Y \à\s H\hi');
    if ($quando !== '') {
        $linhas['Recebida em'] = $quando . ' (Brasília)';
    }
    $linhas['Protocolo'] = $protocolo;
    // A foto não vai no e-mail: fica só no portal.
    $linhas['Selfie'] = match ($f['selfie'] ?? null) {
        'recebida' => 'enviada (veja na ficha, no portal)',
        'nao_tratada' => 'a pessoa enviou, mas o servidor não conseguiu guardar; peça de novo no contato',
        default => 'não enviada (fichas de antes de 27/10/2026 não tinham o campo)',
    };

    $corpo = mcp_p('<strong>' . mcp_escapar($nome) . '</strong>' . ($idade !== '' ? ', ' . mcp_escapar($idade) . ',' : '') . ' de ' . mcp_escapar(mcp_voluntario_local_texto($f))
            . ', quer ser voluntário(a) em <strong>' . mcp_escapar($frentes) . '</strong>. ' . (mcp_vol_cbfi_aberto()
                ? 'A pessoa foi avisada da turma do CBFI de ' . MCP_VOL_CBFI_DATA_TEXTO . ' e de que a equipe entra em contato pelo WhatsApp antes dela, para a ficha de entrevista.'
                : 'A pessoa foi avisada de que as novas entradas abrem em ' . MCP_VOL_PROXIMAS_ENTRADAS . ' e de que o contato da equipe vem nessa época, pelo WhatsApp.'))
        . ($menor ? mcp_nota('<strong>Menor de 18 anos:</strong> o termo de adesão é assinado também pelo responsável legal.') : '')
        . mcp_subtitulo('Motivação') . mcp_citacao((string) $f['motivacao'])
        . (!empty($f['experiencia']) ? mcp_subtitulo('Experiência com voluntariado') . mcp_citacao((string) $f['experiencia']) : '')
        . (!empty($f['habilidades']) ? mcp_subtitulo('Habilidades e formação') . mcp_citacao((string) $f['habilidades']) : '')
        . mcp_botao($portal, 'Abrir no portal')
        . mcp_nota('No portal a ficha fica registrada com a situação (em análise, entrevista, aceita), as anotações da equipe e o PDF. Aceitar a ficha cria o cadastro no ponto da sede.')
        . mcp_botao($mailto, 'Responder por e-mail', true)
        . mcp_nota('Responder este e-mail fala direto com ' . mcp_escapar($primeiro) . '.')
        . mcp_subtitulo('Dados da ficha') . mcp_caixa($linhas)
        . mcp_nota('Consentimento com a Política de Privacidade (revisão ' . mcp_escapar((string) ($f['politica_versao'] ?? '')) . ') e declaração de veracidade registrados em '
            . mcp_escapar(mcp_data_brt((string) ($f['consentimento_em'] ?? ''), 'd/m/Y \à\s H\hi')) . ' (Brasília).');

    $texto = "$nome" . ($idade !== '' ? ", $idade," : '') . ' de ' . mcp_voluntario_local_texto($f) . ", quer ser voluntário(a) em $frentes.\n"
        . ($menor ? "Menor de 18 anos: o termo de adesão é assinado também pelo responsável legal.\n" : '')
        . "\nMotivação:\n{$f['motivacao']}\n"
        . (!empty($f['experiencia']) ? "\nExperiência com voluntariado:\n{$f['experiencia']}\n" : '')
        . (!empty($f['habilidades']) ? "\nHabilidades e formação:\n{$f['habilidades']}\n" : '')
        . "\nAbrir no portal: $portal\nResponder por e-mail: $email (ou responda este e-mail).\n\n";
    foreach ($linhas as $rotulo => $valor) {
        $texto .= "$rotulo: " . (is_array($valor) ? ($rotulo === 'E-mail' ? $email : mcp_telefone_bonito($telefone)) : $valor) . "\n";
    }
    return [
        'assunto' => '[Voluntariado] Nova ficha: ' . $nome . ' · ' . (string) $f['cidade'] . ($protocolo !== '' ? " · $protocolo" : ''),
        'html' => mcp_moldura("Nova ficha de voluntariado: $primeiro", $corpo, [
            'eyebrow' => 'Voluntariado' . ($protocolo !== '' ? " · $protocolo" : ''),
            'preheader' => "$frentes · " . mcp_voluntario_local_texto($f),
            'motivo' => 'Aviso automático do formulário de voluntariado de cruzvermelhariodejaneiro.org para ' . mcp_voluntarios_email_destino() . '.',
        ]),
        'texto' => $texto,
    ];
}

/**
 * Confirmação à pessoa: protocolo, os próximos passos (contato da equipe, entrevista, CBFI, termo de adesão), o
 * aviso ao menor de 18 e o resumo do que ela marcou. Sem WhatsApp, sem pedido de doação.
 */
function mcp_montar_email_voluntario_confirmacao(array $f): array
{
    $nome = mcp_primeiro_nome((string) $f['nome']);
    $protocolo = (string) ($f['protocolo'] ?? '');
    $email = (string) $f['email'];
    $menor = mcp_voluntario_menor($f);
    $contato = mcp_email_contato_endereco();
    $remetente = mcp_email_endereco(mcp_email_remetente_contato());
    $privacidade = mcp_site_url() . '/privacidade/';
    $quando = mcp_data_brt((string) ($f['criado_em'] ?? ''), 'd/m/Y \à\s H\hi');

    $cbfi = mcp_vol_cbfi_aberto();
    $passos = [
        [$cbfi ? 'A coordenadora do voluntariado chama você pelo WhatsApp antes do CBFI de ' . MCP_VOL_CBFI_DATA_TEXTO : 'A coordenadora do voluntariado chama você pelo WhatsApp em ' . MCP_VOL_PROXIMAS_ENTRADAS,
            ($cbfi
                ? 'Ela explica como será a entrevista. A próxima turma do CBFI, no dia ' . MCP_VOL_CBFI_DATA_TEXTO . ', está aberta a quem se cadastra agora; não é preciso enviar a ficha de novo.'
                : 'Ela explica como será a entrevista. As novas entradas no voluntariado abrem em ' . MCP_VOL_PROXIMAS_ENTRADAS . ' e a sua ficha fica guardada: até lá, não é preciso esperar resposta nem enviar a ficha de novo.')
            . ' O contato vem pelo WhatsApp, no telefone informado na ficha, <strong>' . mcp_escapar(mcp_telefone_bonito((string) ($f['telefone'] ?? ''))) . '</strong>. Tenha à mão o protocolo <strong>' . mcp_escapar($protocolo) . '</strong>. Se o número estiver errado, responda este e-mail com o certo.'],
        ['Entrevista e aprovação',
            'Na entrevista, a coordenação do voluntariado conhece você, alinha expectativas e preenche a ficha de entrevista; como ela vai ser, a coordenadora explica pelo WhatsApp. Se você for aprovado(a), recebe um <strong>código de aprovação</strong>.'],
        ['Curso Básico de Formação Institucional (CBFI)',
            'Só para quem foi aprovado(a). ' . ($cbfi ? 'A próxima turma é no dia ' . MCP_VOL_CBFI_DATA_TEXTO . ', na sede. ' : '') . 'Acontece quatro vezes por ano. Apresenta o Movimento Internacional da Cruz Vermelha, os Princípios Fundamentais e o funcionamento da filial. Todo voluntário passa por ele antes de começar.'],
        ['Termo de adesão ao serviço voluntário',
            'Assinado na sede, em duas vias, conforme a Lei nº 9.608/1998. É ele que formaliza o voluntariado' . ($menor ? ', e, como você tem menos de 18 anos, o seu responsável legal assina junto' : '') . '.'],
    ];
    $linhas = [
        'Protocolo' => $protocolo,
        'Frentes' => mcp_voluntario_rotulos((string) $f['frentes'], MCP_VOL_FRENTES),
        'Dias' => mcp_voluntario_rotulos((string) $f['dias'], MCP_VOL_DIAS),
        'Períodos' => mcp_voluntario_rotulos((string) $f['periodos'], MCP_VOL_PERIODOS),
    ];
    if (($f['selfie'] ?? null) === 'recebida') {
        $linhas['Selfie'] = 'recebida';
    }
    if ($quando !== '') {
        $linhas['Enviada em'] = $quando . ' (Brasília)';
    }
    $corpo = mcp_p('Oi, ' . mcp_escapar($nome) . '. Obrigado por querer ser voluntário(a) da Cruz Vermelha Brasileira no Rio de Janeiro. Sua ficha chegou e já está com a equipe de voluntariado. '
            . 'Guarde o protocolo <strong>' . mcp_escapar($protocolo) . '</strong>: ele identifica o seu cadastro.')
        . ($menor ? '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#fff7f7" style="margin:0 0 14px;background:#fff7f7;border:1px solid #f5c2c7;border-radius:12px"><tr><td style="padding:12px 16px;font-size:15px;line-height:1.5;color:#1a202c">'
            . '<strong>Você tem menos de 18 anos.</strong> Pode participar, sim: o termo de adesão é assinado também pelo seu responsável legal, que autoriza a sua participação nas atividades. Combine isso com a equipe na entrevista.</td></tr></table>' : '')
        . mcp_nota('<strong>Este e-mail confirma que a ficha chegou, não a aprovação.</strong> Para ser aprovado(a), você passa pela coordenação do voluntariado. Se for aprovado(a), recebe um código de aprovação; sem ele, a sua entrada no voluntariado ainda não está confirmada.')
        . mcp_subtitulo('Próximos passos') . mcp_passos($passos)
        . mcp_caixa($linhas)
        . mcp_nota('Seus dados são usados só para o processo de seleção e acompanhamento do voluntariado, conforme a <a href="' . mcp_escapar($privacidade) . '" style="color:#cc0000">Política de Privacidade</a> (revisão '
            . mcp_escapar((string) ($f['politica_versao'] ?? '')) . '), com a qual você concordou ao enviar a ficha. Para corrigir algo ou pedir a exclusão, responda este e-mail ou escreva para ' . mcp_escapar($contato) . '.')
        . mcp_nota('Não foi você quem enviou esta ficha? Ignore este e-mail.');
    $texto = "Oi, $nome. Obrigado por querer ser voluntário(a) da Cruz Vermelha Brasileira no Rio de Janeiro. Sua ficha chegou e já está com a equipe de voluntariado. Protocolo: $protocolo.\n\n"
        . ($menor ? "Você tem menos de 18 anos. Pode participar, sim: o termo de adesão é assinado também pelo seu responsável legal. Combine isso com a equipe na entrevista.\n\n" : '')
        . "Este e-mail confirma que a ficha chegou, não a aprovação. Para ser aprovado(a), você passa pela coordenação do voluntariado. Se for aprovado(a), recebe um código de aprovação; sem ele, a sua entrada no voluntariado ainda não está confirmada.\n\n"
        . "Próximos passos:\n";
    foreach ($passos as $i => [$titulo, $detalhe]) {
        $texto .= ($i + 1) . ") $titulo. " . html_entity_decode(strip_tags($detalhe), ENT_QUOTES, 'UTF-8') . "\n";
    }
    $texto .= "\n";
    foreach ($linhas as $rotulo => $valor) {
        $texto .= "$rotulo: $valor\n";
    }
    $texto .= "\nSeus dados são usados só para o processo de seleção e acompanhamento do voluntariado, conforme a Política de Privacidade ($privacidade, revisão " . (string) ($f['politica_versao'] ?? '')
        . "). Para corrigir algo ou pedir a exclusão, responda este e-mail ou escreva para $contato.\n";
    return [
        'assunto' => "Recebemos sua ficha de voluntariado · $protocolo",
        'html' => mcp_moldura("Recebemos sua ficha, $nome.", $corpo, [
            'eyebrow' => 'Voluntariado',
            'preheader' => $cbfi
                ? "Protocolo $protocolo. A próxima turma do CBFI é no dia " . MCP_VOL_CBFI_DATA_TEXTO . ', e a equipe entra em contato pelo WhatsApp antes dela.'
                : "Protocolo $protocolo. As novas entradas no voluntariado abrem em " . MCP_VOL_PROXIMAS_ENTRADAS . ', e a equipe entra em contato pelo WhatsApp nessa época.',
            'motivo' => 'Você recebeu este e-mail porque preencheu a ficha de voluntariado em cruzvermelhariodejaneiro.org.',
        ]),
        'texto' => $texto,
    ];
}

/**
 * Envia o aviso à equipe (EMAIL_VOLUNTARIADO ou, vazio, voluntariado@, responder-para = a pessoa) e a confirmação à pessoa.
 * Devolve o resultado dos dois ('resend', 'mail' ou 'falhou'); falha de e-mail nunca derruba a requisição.
 *
 * @return array{equipe: string, confirmacao: string}
 */
function mcp_email_voluntario_ficha(array $f): array
{
    $equipe = mcp_montar_email_voluntario_equipe($f);
    $r1 = mcp_enviar_email(mcp_voluntarios_email_destino(), $equipe['assunto'], $equipe['html'], $equipe['texto'], (string) $f['email']);
    $confirmacao = mcp_montar_email_voluntario_confirmacao($f);
    $r2 = mcp_enviar_email((string) $f['email'], $confirmacao['assunto'], $confirmacao['html'], $confirmacao['texto']);
    return ['equipe' => $r1, 'confirmacao' => $r2];
}
