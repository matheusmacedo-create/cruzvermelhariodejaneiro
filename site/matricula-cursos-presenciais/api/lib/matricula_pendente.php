<?php
/**
 * Matrícula pendente (01/11/2026, pedido do responsável: "pagou a inscrição, falta a matrícula"). No checkout, o plano
 * padrão é "Só a taxa de inscrição": quem paga só isso tem a vaga, mas "não tem a entrada liberada. A matrícula é paga
 * antes da aula, com a secretaria da Escola". Até aqui não havia nenhum caminho no site para pagar essa matrícula
 * depois: o e-mail da taxa dizia "a secretaria escreve com o jeito de pagar", e o checkout recusa uma segunda compra do
 * mesmo curso ("para pagar só a matrícula, fale com a secretaria").
 *
 * O que este arquivo faz (carregado por lib/recuperacao_whatsapp.php, de quem usa a Kapso, os modelos, os links curtos e
 * a Resend):
 *  1. A régua: duas mensagens de WhatsApp (modelos mat_pendente_1 e mat_pendente_2, já submetidos à Meta no número
 *     novo) e dois e-mails equivalentes a quem pagou só a taxa e ainda não pagou a matrícula:
 *      - etapa 1: 1 h depois da confirmação da taxa (MCP_MP_PRIMEIRA_S);
 *      - etapa 2: na véspera da turma, às 10h (MCP_MP_VESPERA_HORA); se a turma está longe (mais de MCP_MP_LONGE_DIAS
 *        dias depois da taxa), 48 h antes do início (MCP_MP_ANTES_S), para dar tempo de pagar num dia útil;
 *      - se a 2ª já chegou quando a 1ª ficaria devida (taxa paga na véspera, ou a 1ª segurada até lá), vai só a 2ª; entre
 *        as duas, no mínimo MCP_MP_INTERVALO_S;
 *      - nada sai a menos de MCP_MP_CORTE_S do início da turma, nem depois (turma passada: não manda), só das 8h às 20h
 *        de Brasília, e cada canal anda sozinho (sem celular, só e-mail; sem e-mail, só WhatsApp).
 *     SEM grupo de controle, de propósito: aqui a pessoa já é aluna e precisa pagar para entrar na aula. Tudo fica
 *     registrado (mcp_matricula_pendente e o resumo de cada rodada) para medir quantos pagam depois de cada mensagem.
 *  2. O pagamento pelo próprio site (api/matricula.php e a página pagar-matricula/): o botão da mensagem leva, pelo
 *     link curto (api/ir.php/<w|e>/<curso>/<mat1|mat2>/<token>), à tela que cobra SÓ a matrícula da inscrição já paga,
 *     por PIX ou cartão à vista, na mesma Unicopag e com o mesmo postback. O token é o mesmo dos links da recuperação
 *     (mcp_rw_token), com a referência m<id da inscrição>: assinado, com validade, sem dado pessoal, e fora da URL da
 *     página (vai num cookie só da api/). Os dados do pagador (nome, CPF, e-mail, telefone) saem da inscrição, nunca do
 *     navegador; o cartão só passa a caminho da Unicopag. Paga a matrícula, o aluno recebe o comprovante e a secretaria
 *     recebe o aviso para marcar a matrícula como paga na plataforma da escola (a escola hoje responde 403, e não há
 *     função dela para marcar uma matrícula existente como paga: a marcação é à mão, como já é para "matrícula não
 *     marcada" do plano completo).
 *  3. Como se sabe se a matrícula já foi paga: no site, um pagamento pago desta tabela ou uma compra "taxa + matrícula"
 *     paga do mesmo CPF e curso; na escola, uma Matricula do mesmo CPF e curso com statusPagamento PAGO ou PARCELADO
 *     (lida pelo PostgREST, como a recuperação); e a marcação "Já pagou na escola" do painel. Escola fora do ar (403
 *     desde 31/10): segue com o que o site sabe, como a recuperação do carrinho, e o painel diz que quem pagou só na
 *     escola pode receber um lembrete (e oferece o botão para parar).
 *
 * Bloqueios: WhatsApp bloqueado (qualquer origem, inclusive o "Não tenho interesse" da recuperação) não recebe WhatsApp;
 * e-mail bloqueado não recebe e-mail; e o "Não quero mais receber" destes e-mails (api/avisos.php?mp=) para os dois
 * canais desta matrícula, sem tirar a pessoa dos avisos das aulas (ela é aluna). Respostas "SAIR"/"PARAR" no WhatsApp
 * bloqueiam o número, como na recuperação.
 *
 * Cota: o WhatsApp conta no mesmo teto de MCP_RW_POR_DIA em 24 h do número (mcp_rw_enviadas_24h soma as duas tabelas); o
 * e-mail conta no mesmo teto MCP_RW_EMAIL_POR_DIA e na mesma reserva dos recibos (mcp_rw_email_enviados_24h soma as
 * duas). A rodada da matrícula vem ANTES da do carrinho em api/lembretes.php: tem data de aula.
 *
 * Regras puras (conferidas no teste): mcp_mp_proxima, mcp_mp_vespera, mcp_mp_data_texto, mcp_mp_modelo, mcp_mp_email_montar.
 */
declare(strict_types=1);

// Quem entra: taxa paga nos últimos 60 dias (só a taxa), com turma marcada no futuro e matrícula com preço.
const MCP_MP_JANELA_DIAS = 60;
// A 1ª: 1 h depois da confirmação da taxa (dentro do horário, na rodada seguinte).
const MCP_MP_PRIMEIRA_S = 3600;
// Turma "longe": mais de 7 dias entre a taxa e o início. Aí a 2ª vai 48 h antes (dá tempo de pagar num dia útil); perto,
// na véspera (a 1ª ainda está fresca).
const MCP_MP_LONGE_DIAS = 7;
const MCP_MP_ANTES_S = 48 * 3600;
const MCP_MP_VESPERA_HORA = 10;
// Mínimo entre a 1ª e a 2ª (taxa paga 2 dias antes da aula: 1ª logo, 2ª na véspera, nunca as duas na mesma tarde).
const MCP_MP_INTERVALO_S = 6 * 3600;
// Nada sai a menos de 1 h do início da turma: depois disso a mensagem chegaria com a aula começando.
const MCP_MP_CORTE_S = 3600;
// Turma com o dia mas sem a hora (resposta da escola sem horário): conta como 8h. O corte fica mais cedo, nunca mais tarde.
const MCP_MP_HORA_PADRAO = 8;
const MCP_MP_MODELOS = [1 => 'mat_pendente_1', 2 => 'mat_pendente_2'];
// As campanhas dos botões (utm_campaign): um pagamento da matrícula com uma delas veio "pelo link da mensagem".
const MCP_MP_CAMPANHAS = ['mat1', 'mat2'];
// A tela de pagar a matrícula (gerada por scripts/gerar_checkout.py; o JS é static/matricula.js).
const MCP_MP_PAGINA = '/matricula-cursos-presenciais/pagar-matricula/';
// No máximo por rodada (cada canal); o teto em 24 h é o da recuperação, somado.
const MCP_MP_POR_RODADA = 20;
const MCP_MP_EMAIL_POR_RODADA = 10;
// Pagamento: o PIX pendente da mesma matrícula é reaproveitado por 20 h (vale 24 h); vence com 25 h; reconsulta a cada 6 s.
const MCP_MP_PIX_REAPROVEITA_S = 20 * 3600;
const MCP_MP_VENCIMENTO_S = 25 * 3600;
const MCP_MP_RECONSULTA_S = 6;
// Freio da cobrança: 10 pedidos por IP a cada 10 min (folga para uma pessoa, freio para robô).
const MCP_MP_LIMITE_IP = [10, 600];
// A turma começou há mais de 1 dia: a tela não cobra mais (a pessoa fala com a secretaria).
const MCP_MP_PAGAR_ATE_DEPOIS_S = 86400;
/**
 * Prévia APROXIMADA de cada modelo para o painel (o texto exato é o cadastrado na Meta, que é quem manda a mensagem;
 * só o nome e os parâmetros saem daqui): {{1}} nome, {{2}} curso, {{3}} data da turma, e o botão de link.
 */
const MCP_MP_TEXTOS = [
    'mat_pendente_1' => "Olá, {{1}}. Recebemos a taxa de inscrição do curso {{2}} da Cruz Vermelha RJ. Para liberar a sua entrada na turma de {{3}}, falta o pagamento da matrícula.\n\nO pagamento pode ser feito pelo link abaixo. Se tiver dúvida, responda esta mensagem.\n[Pagar a matrícula]",
    'mat_pendente_2' => "Olá, {{1}}. A sua turma do curso {{2}} na Cruz Vermelha RJ é {{3}}, e a matrícula ainda consta como pendente. Sem ela, a entrada na aula não é liberada.\n\nPara regularizar, use o link abaixo. Se já pagou, desconsidere esta mensagem.\n[Pagar a matrícula]",
];
// Por que alguém devido não recebeu nesta rodada (o resumo da rodada, por inscrição e canal).
const MCP_MP_MOTIVOS_RODADA = [
    'm' => 'o modelo desta mensagem não está aprovado na Meta (ou não confere); espera, sem perder a vez',
    'r' => 'na conferência antes do envio, pagou a matrícula ou pediu para parar',
    'd' => 'esta etapa já estava gravada',
    't' => 'passou do teto por rodada; vai na rodada seguinte',
    'T' => 'passou do teto em 24 h (o mesmo da recuperação); vai quando a janela andar',
    'h' => 'acabou o horário (20h) no meio da rodada',
    'p' => 'a rodada parou antes (a Kapso, a Meta ou a Resend recusaram tudo); vai na rodada seguinte',
    'x' => 'a Meta recusou só esta mensagem; a etapa não foi gasta',
    'f' => 'não chegou (número sem WhatsApp ou e-mail recusado); a sequência deste canal parou',
    'k' => 'a cota da Resend ficou para os recibos',
    'c' => 'a turma começa em menos de 1 h: não manda mais',
];

// ----------------------------------------------------------------------------- régua (puro)
/** A véspera da turma às MCP_MP_VESPERA_HORA de Brasília. Puro. */
function mcp_mp_vespera(int $turmaTs): int
{
    return (new DateTimeImmutable('@' . $turmaTs))->setTimezone(mcp_fuso_brt())->setTime(MCP_MP_VESPERA_HORA, 0)->modify('-1 day')->getTimestamp();
}

/**
 * A próxima mensagem de UM canal e quando fica devida (sem o horário), ou null. $enviadas: etapa => ['status', 'entrega',
 * 'erro', 'criado_ts'] (as linhas do canal). A 1ª com 1 h da taxa; a 2ª na véspera (ou 48 h antes, com a turma longe) e
 * pelo menos MCP_MP_INTERVALO_S depois da 1ª; se a 2ª já chegou (ou chega antes do intervalo), vai só ela. A 1ª que não
 * chegou (falhou, ou entrega falhou) para o canal. Nada devido a menos de MCP_MP_CORTE_S do início, e nada a partir desse
 * corte (turma começando ou passada): null. 'limite' é o corte, conferido de novo na hora do envio. Puro.
 * @return array{etapa: int, devida_em: int, limite: int}|null
 */
function mcp_mp_proxima(int $pagoTs, int $turmaTs, array $enviadas, int $agora): ?array
{
    $corte = $turmaTs - MCP_MP_CORTE_S;
    if (isset($enviadas[2]) || $agora >= $corte) {
        return null;
    }
    $longe = $turmaTs - $pagoTs > MCP_MP_LONGE_DIAS * 86400;
    $base2 = $longe ? $turmaTs - MCP_MP_ANTES_S : mcp_mp_vespera($turmaTs);
    $e1 = $enviadas[1] ?? null;
    if ($e1 === null) {
        $d1 = $pagoTs + MCP_MP_PRIMEIRA_S;
        $r = $d1 + MCP_MP_INTERVALO_S > $base2 || $agora >= $base2 ? ['etapa' => 2, 'devida_em' => max($base2, $d1)] : ['etapa' => 1, 'devida_em' => $d1];
    } else {
        // Reserva que ficou "enviando" (a rotina caiu no meio): pode ter saído, vale como enviada (como na recuperação).
        $saiu = ($e1['status'] ?? '') === 'enviado' || (($e1['status'] ?? '') === 'falhou' && ($e1['erro'] ?? null) === 'enviando');
        if (!$saiu || ($e1['entrega'] ?? null) === 'falhou') {
            return null;
        }
        $r = ['etapa' => 2, 'devida_em' => max($base2, (int) $e1['criado_ts'] + MCP_MP_INTERVALO_S)];
    }
    return $r['devida_em'] < $corte ? $r + ['limite' => $corte] : null;
}

/**
 * A data da turma como vai no {{3}}: na 1ª "terça-feira, 17/11"; na 2ª, com "hoje" ou "amanhã" quando é o caso (o dia de
 * Brasília de quando a mensagem sai) e a hora, se conhecida: "amanhã, terça-feira, 17/11, às 9h". Puro.
 */
function mcp_mp_data_texto(int $turmaTs, bool $comHora, int $etapa, int $agora): string
{
    $fuso = mcp_fuso_brt();
    $d = (new DateTimeImmutable('@' . $turmaTs))->setTimezone($fuso);
    $base = MCP_DIAS_SEMANA[(int) $d->format('w')] . ', ' . $d->format('d/m');
    if ($etapa === 1) {
        return $base;
    }
    $hoje = (new DateTimeImmutable('@' . $agora))->setTimezone($fuso);
    $dia = $d->format('Y-m-d');
    $prefixo = $dia === $hoje->format('Y-m-d') ? 'hoje, ' : ($dia === $hoje->modify('+1 day')->format('Y-m-d') ? 'amanhã, ' : '');
    $hora = $comHora ? ', às ' . (int) $d->format('G') . 'h' . ($d->format('i') !== '00' ? $d->format('i') : '') : '';
    return $prefixo . $base . $hora;
}

/**
 * A mensagem de WhatsApp de uma etapa para esta pessoa: ['nome' (mat_pendente_1|2, o de hoje; a rodada troca pela versão
 * da família com mcp_rw_escolher_modelo), 'etapa', 'parametros' ({{1}} primeiro nome, {{2}} curso, {{3}} data; nunca
 * vazios), 'caminho' (o link curto da campanha mat<etapa>, com o token), 'previa'] Puro.
 */
function mcp_mp_modelo(int $etapa, array $p, int $agora, string $token = ''): array
{
    $nome = mcp_rw_parametro(mcp_rw_primeiro_nome((string) ($p['nome'] ?? '')));
    $curso = mcp_rw_parametro((string) ($p['curso_nome'] ?? ''), 160);
    $parametros = [$nome !== '' ? $nome : MCP_RW_NOME_PADRAO, $curso !== '' ? $curso : MCP_RW_CURSO_GENERICO,
        mcp_rw_parametro(mcp_mp_data_texto((int) $p['turma_ts'], (bool) $p['turma_hora'], $etapa, $agora))];
    $modelo = MCP_MP_MODELOS[$etapa];
    return ['nome' => $modelo, 'etapa' => $etapa, 'parametros' => $parametros, 'caminho' => mcp_rw_link('whatsapp', (string) ($p['curso_slug'] ?? ''), 'mat' . $etapa, $token),
        'previa' => mcp_mp_previa($modelo, $parametros)];
}

/** A prévia aproximada (MCP_MP_TEXTOS) com os parâmetros; uma versão nova da família usa o texto da de hoje. Puro. */
function mcp_mp_previa(string $modelo, array $parametros): string
{
    $troca = [];
    foreach (array_values($parametros) as $i => $v) {
        $troca['{{' . ($i + 1) . '}}'] = (string) $v;
    }
    $familia = mcp_rw_familia($modelo);
    return strtr(MCP_MP_TEXTOS[$modelo] ?? MCP_MP_TEXTOS[MCP_RW_FAMILIAS[$familia] ?? ''] ?? '', $troca);
}

/**
 * Um e-mail da matrícula pendente, pronto: ['modelo' (mat1|mat2), 'assunto', 'html', 'texto', 'link', 'preheader']. Na
 * moldura dos e-mails do site, com o mesmo "Não quero mais receber" da recuperação (aqui, só destes lembretes). Diz o
 * valor da matrícula (é um valor fixo do curso, que a pessoa já viu no checkout) e a alternativa de pagar na secretaria.
 * $link: o link curto da campanha (fonte e), $descadastro: mcp_mp_descadastro_url. Puro.
 */
function mcp_mp_email_montar(int $etapa, array $p, int $agora, string $link, string $descadastro): array
{
    $nome = mcp_rw_primeiro_nome((string) ($p['nome'] ?? ''));
    $curso = trim((string) ($p['curso_nome'] ?? '')) ?: MCP_RW_CURSO_GENERICO;
    $data = mcp_mp_data_texto((int) $p['turma_ts'], (bool) $p['turma_hora'], $etapa, $agora);
    $valor = (int) ($p['valor'] ?? 0) > 0 ? mcp_brl((int) $p['valor']) : '';
    $oi = $nome !== '' ? 'Oi, ' . mcp_escapar($nome) . '.' : 'Oi.';
    $c = '<strong>' . mcp_escapar($curso) . '</strong>';
    $quanto = $valor !== '' ? ' (<strong>' . mcp_escapar($valor) . '</strong>, à vista)' : '';
    if ($etapa === 1) {
        $titulo = $nome !== '' ? "Falta a matrícula, $nome." : 'Falta a matrícula.';
        $assunto = "Sua vaga em $curso está garantida: falta a matrícula para entrar na aula";
        $preheader = "A taxa de inscrição foi paga. Para entrar na aula de $data, falta a matrícula.";
        $blocos = [
            mcp_email_b_p("$oi Recebemos a taxa de inscrição em $c: a sua vaga na turma de <strong>" . mcp_escapar($data) . '</strong> está garantida.'),
            mcp_email_b_p("A entrada na aula é liberada com a matrícula paga. Falta pagar a matrícula$quanto."),
            mcp_email_b_p('Pelo botão abaixo você paga agora, por PIX ou cartão, e o comprovante chega neste e-mail. Se preferir, pague na secretaria da Escola, antes da aula.'),
        ];
    } else {
        $titulo = 'Sua turma começa ' . $data . '.';
        $assunto = "Lembrete: sua turma de $curso começa $data e a matrícula está em aberto";
        $preheader = 'A entrada na aula é liberada com a matrícula paga. Dá para pagar agora pelo botão do e-mail.';
        $blocos = [
            mcp_email_b_p("$oi Passando para lembrar: a sua turma de $c começa <strong>" . mcp_escapar($data) . '</strong>, e a matrícula ainda está em aberto.'),
            mcp_email_b_p("A entrada na aula é liberada com a matrícula paga$quanto. Pague agora pelo botão abaixo, por PIX ou cartão, ou na secretaria da Escola antes da aula."),
        ];
    }
    $sair = mcp_escapar($descadastro);
    $j = mcp_email_juntar(array_merge($blocos, [
        mcp_email_b_botao($link, 'Pagar a matrícula'),
        mcp_email_b_nota('Já pagou a matrícula? Desconsidere este e-mail. Dúvidas sobre a turma ou o pagamento? É só responder: a secretaria da Escola de Educação e Saúde lê e responde.'),
        mcp_email_b_html(mcp_nota('Não quer mais receber estes lembretes da matrícula? <a href="' . $sair . '" style="color:#cc0000">Não quero mais receber</a>. Vale para o e-mail e para o WhatsApp; os avisos das aulas continuam.'),
            "Não quer mais receber estes lembretes da matrícula (nem por e-mail nem pelo WhatsApp)? Não quero mais receber: $descadastro"),
    ]));
    return [
        'modelo' => 'mat' . $etapa,
        'assunto' => $assunto,
        'html' => mcp_moldura($titulo, $j['html'], mcp_email_opcoes_aluno($preheader,
            'Você recebeu este e-mail porque pagou a taxa de inscrição de um curso em cruzvermelhariodejaneiro.org com este endereço. São no máximo 2 lembretes da matrícula.')),
        'texto' => $j['texto'] . "\n\nDúvidas? Responda este e-mail ou escreva para " . mcp_email_contato_endereco() . ".\n",
        'link' => $link,
        'preheader' => $preheader,
    ];
}

// ----------------------------------------------------------------------------- turma e situação (banco)
/**
 * Quando começa a turma desta inscrição: ['ts', 'hora' (a hora é conhecida), 'rotulo' ("terça-feira, 17/11")] ou null.
 * Em ordem: a resposta da escola (primeira aula ou início, com o horário dela; sem horário, o do oferta.json se for o
 * mesmo dia, senão MCP_MP_HORA_PADRAO); a turma vendida (turma_id no oferta.json); a coluna turma_inicio. Sem nada disso
 * (pagou só a taxa sem turma aberta, na lista da próxima), null: não há aula marcada para cobrar antes.
 */
function mcp_mp_turma(array $i): ?array
{
    $fuso = mcp_fuso_brt();
    $oferta = !empty($i['turma_id']) ? mcp_oferta_turma((string) $i['turma_id']) : null;
    $inicioOferta = $oferta ? mcp_iso_ts($oferta['inicio'] ?? null) : null;
    $acesso = mcp_escola_acesso($i);
    $ymd = null;
    if ($acesso && ($acesso['resultado'] ?? '') === 'matriculado') {
        foreach (['turma_primeira_aula', 'turma_inicio'] as $k) {
            $v = is_string($acesso[$k] ?? null) ? substr($acesso[$k], 0, 10) : '';
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $ymd = $v;
                break;
            }
        }
    }
    $ts = null;
    $hora = false;
    if ($ymd !== null) {
        $horario = trim((string) ($acesso['turma_horario'] ?? ''));
        if (preg_match('/^(\d{1,2})[:h](\d{2})?/', $horario, $m) && (int) $m[1] < 24) {
            $ts = (new DateTimeImmutable("$ymd 00:00", $fuso))->setTime((int) $m[1], (int) ($m[2] ?? 0))->getTimestamp();
            $hora = true;
        } elseif ($inicioOferta !== null && (new DateTimeImmutable('@' . $inicioOferta))->setTimezone($fuso)->format('Y-m-d') === $ymd) {
            [$ts, $hora] = [$inicioOferta, true];
        } else {
            $ts = (new DateTimeImmutable("$ymd 00:00", $fuso))->setTime(MCP_MP_HORA_PADRAO, 0)->getTimestamp();
        }
    } elseif ($inicioOferta !== null) {
        [$ts, $hora] = [$inicioOferta, true];
    } elseif (($coluna = mcp_utc_ts($i['turma_inicio'] ?? null)) !== null) {
        [$ts, $hora] = [$coluna, true];
    }
    if ($ts === null) {
        return null;
    }
    return ['ts' => $ts, 'hora' => $hora, 'rotulo' => mcp_mp_data_texto($ts, false, 1, $ts)];
}

/**
 * A plataforma da escola: das pessoas $cpfs, as matrículas com o curso pago (statusPagamento PAGO ou PARCELADO): "cpf|slug"
 * => quando (ts). Mesma leitura da recuperação (PostgREST, chave secreta, tempo curto). Sem a escola configurada,
 * 'desligada'; fora do ar (403 desde 31/10), 'indisponivel', e a rodada segue com o que o site sabe.
 * @return array{estado: string, erro: ?string, pagos: array<string, int>}
 */
function mcp_mp_escola(array $cpfs): array
{
    $base = mcp_rw_escola_base();
    if ($base === '') {
        return ['estado' => 'desligada', 'erro' => null, 'pagos' => []];
    }
    $slugs = mcp_rw_slugs_por_uuid();
    $pagos = [];
    foreach (array_chunk(array_values(array_unique(array_filter($cpfs))), MCP_RW_ESCOLA_LOTE) as $lote) {
        [$status, $dados] = mcp_rw_escola_get($base . '/Usuario?select=' . rawurlencode(MCP_RW_ESCOLA_SELECT) . '&or='
            . rawurlencode('(cpfCnpj.in.(' . implode(',', array_map('mcp_rw_escola_valor', $lote)) . '))') . '&limit=' . MCP_RW_ESCOLA_PAGINA);
        if ($status !== 200 || !is_array($dados)) {
            return ['estado' => 'indisponivel', 'erro' => $status === 0 ? 'sem resposta da escola' : "a escola respondeu HTTP $status", 'pagos' => []];
        }
        foreach ($dados as $u) {
            $cpf = is_array($u) ? mcp_rw_cpf($u['cpfCnpj'] ?? '') : '';
            foreach ($cpf !== '' && is_array($u['matriculas'] ?? null) ? $u['matriculas'] : [] as $m) {
                if (!is_array($m) || !in_array($m['statusPagamento'] ?? '', MCP_RW_PAGOS_ESCOLA, true)) {
                    continue;
                }
                $uuid = mb_strtolower((string) ($m['turma']['curso']['id'] ?? $m['turma']['cursoId'] ?? ''));
                $slug = $slugs[$uuid] ?? '';
                $ts = mcp_rw_ts($m['atualizadoEm'] ?? null) ?? mcp_rw_ts($m['criadoEm'] ?? null) ?? 0;
                if ($slug !== '') {
                    $pagos["$cpf|$slug"] = min($pagos["$cpf|$slug"] ?? PHP_INT_MAX, $ts);
                }
            }
        }
    }
    return ['estado' => 'ok', 'erro' => null, 'pagos' => $pagos];
}

/**
 * O público: as inscrições pagas SÓ COM A TAXA nos últimos MCP_MP_JANELA_DIAS dias (sem CPF nem curso de teste), cada uma
 * com a situação da matrícula e dos dois canais. Uma "pessoa" aqui é uma inscrição (dois cursos = duas matrículas).
 *  - paga (e paga_em, paga_como: site, completa, escola ou painel) ou pendente;
 *  - motivo (por que não está na régua): paga, sem_preco, sem_turma, turma_passou;
 *  - motivo_wa e motivo_email (por que o canal não manda): sem_celular / sem_email, bloqueou, descadastrou;
 *  - linhas: canal => etapa => linha (com criado_ts) de mcp_matricula_pendente.
 * @return array{pessoas: array, escola: array{estado: string, erro: ?string}}
 */
function mcp_mp_carregar(int $agora): array
{
    $db = mcp_db();
    $plano = mcp_colunas_plano_ok();
    $stmt = $db->prepare("SELECT * FROM mcp_inscricoes WHERE status = 'pago' AND pago_em >= ?" . ($plano ? " AND plano = 'so_taxa'" : '') . ' ORDER BY pago_em, id');
    $stmt->execute([gmdate('Y-m-d H:i:s', $agora - MCP_MP_JANELA_DIAS * 86400)]);
    $inscricoes = array_values(array_filter($stmt->fetchAll(), static fn(array $i): bool => !mcp_cpf_de_teste((string) $i['cpf'])
        && !mcp_rw_curso_de_teste((string) $i['curso_slug'], (string) $i['curso_nome'])));
    if (!$inscricoes) {
        return ['pessoas' => [], 'escola' => ['estado' => mcp_rw_escola_base() === '' ? 'desligada' : 'ok', 'erro' => null]];
    }
    $ids = array_map(static fn(array $i): int => (int) $i['id'], $inscricoes);
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    // A matrícula paga no site: um pagamento pago desta inscrição (o primeiro).
    $stmt = $db->prepare("SELECT inscricao_id, MIN(pago_em) AS em FROM mcp_matricula_pagamentos WHERE status = 'pago' AND inscricao_id IN ($marcas) GROUP BY inscricao_id");
    $stmt->execute($ids);
    $pagasSite = array_map('mcp_utc_ts', array_column($stmt->fetchAll(), 'em', 'inscricao_id'));
    // Ou uma compra "taxa + matrícula" paga depois, do mesmo CPF e curso (a pessoa voltou ao checkout).
    $completas = [];
    if ($plano) {
        foreach ($db->query("SELECT cpf, curso_slug, MIN(pago_em) AS em FROM mcp_inscricoes WHERE status = 'pago' AND plano = 'taxa_e_matricula' GROUP BY cpf, curso_slug") as $c) {
            $completas[$c['cpf'] . '|' . $c['curso_slug']] = mcp_utc_ts($c['em']);
        }
    }
    $stmt = $db->prepare("SELECT * FROM mcp_matricula_pendente WHERE inscricao_id IN ($marcas) ORDER BY id");
    $stmt->execute($ids);
    $linhas = [];
    foreach ($stmt->fetchAll() as $l) {
        $linhas[(int) $l['inscricao_id']][$l['canal']][(int) $l['etapa']] ??= $l + ['criado_ts' => (int) mcp_rw_ts($l['criado_em'])];
    }
    $escola = mcp_mp_escola(array_map(static fn(array $i): string => (string) $i['cpf'], $inscricoes));
    if ($escola['estado'] === 'indisponivel') {
        error_log('[matricula] matrícula pendente: escola indisponível (' . $escola['erro'] . '); segue com o que o site sabe');
    }
    $pessoas = [];
    foreach ($inscricoes as $i) {
        $id = (int) $i['id'];
        $doId = $linhas[$id] ?? [];
        $chave = $i['cpf'] . '|' . $i['curso_slug'];
        $painel = $doId['painel'][0] ?? null;
        [$pagaEm, $como] = match (true) {
            isset($pagasSite[$id]) => [$pagasSite[$id], 'site'],
            isset($completas[$chave]) && $completas[$chave] >= mcp_utc_ts($i['pago_em']) => [$completas[$chave], 'completa'],
            isset($escola['pagos'][$chave]) => [$escola['pagos'][$chave], 'escola'],
            $painel !== null => [(int) $painel['criado_ts'], 'painel'],
            default => [null, null],
        };
        $curso = mcp_curso((string) $i['curso_slug']);
        $valor = $curso !== null ? mcp_matricula_centavos($curso, (string) $i['cpf']) : 0;
        $turma = mcp_mp_turma($i);
        $motivo = match (true) {
            $pagaEm !== null => 'paga',
            $valor <= 0 => 'sem_preco',
            $turma === null => 'sem_turma',
            $turma['ts'] - MCP_MP_CORTE_S <= $agora => 'turma_passou',
            default => null,
        };
        $numero = mcp_whatsapp_numero((string) $i['telefone']);
        $email = mcp_rw_email((string) $i['email']);
        $descadastrou = (bool) array_filter($doId['email'] ?? [], static fn(array $l): bool => !empty($l['descadastro_em']));
        $pessoas[] = [
            'inscricao_id' => $id, 'nome' => (string) $i['nome'], 'cpf' => (string) $i['cpf'], 'numero' => $numero, 'email' => $email,
            'curso_slug' => (string) $i['curso_slug'], 'curso_nome' => (string) $i['curso_nome'], 'pago_ts' => (int) mcp_utc_ts($i['pago_em']),
            'valor' => $valor, 'turma_ts' => $turma['ts'] ?? null, 'turma_hora' => $turma['hora'] ?? false, 'turma_rotulo' => $turma['rotulo'] ?? '',
            'paga_em' => $pagaEm, 'paga_como' => $como, 'motivo' => $motivo,
            'motivo_wa' => $numero === null ? 'sem_celular' : ($descadastrou ? 'descadastrou' : (mcp_avisos_bloqueado('whatsapp', $numero) ? 'bloqueou' : null)),
            'motivo_email' => $email === '' ? 'sem_email' : ($descadastrou ? 'descadastrou' : (mcp_avisos_bloqueado('email', $email) ? 'bloqueou' : null)),
            'linhas' => $doId,
        ];
    }
    return ['pessoas' => $pessoas, 'escola' => ['estado' => $escola['estado'], 'erro' => $escola['erro']]];
}

/** As linhas de um canal no formato de mcp_mp_proxima (etapa => status, entrega, erro, criado_ts). */
function mcp_mp_enviadas(array $p, string $canal): array
{
    $saida = [];
    foreach ($p['linhas'][$canal] ?? [] as $etapa => $l) {
        if ((int) $etapa >= 1) {
            $saida[(int) $etapa] = ['status' => (string) $l['status'], 'entrega' => $l['entrega'] ?? null, 'erro' => $l['erro'] ?? null, 'criado_ts' => (int) $l['criado_ts']];
        }
    }
    return $saida;
}

/** A matrícula desta inscrição já foi paga no site (pagamento pago, ou compra completa do mesmo CPF e curso depois)? */
function mcp_mp_paga_no_site(array $i): bool
{
    $db = mcp_db();
    $stmt = $db->prepare("SELECT 1 FROM mcp_matricula_pagamentos WHERE inscricao_id = ? AND status = 'pago' LIMIT 1");
    $stmt->execute([(int) $i['id']]);
    if ($stmt->fetchColumn()) {
        return true;
    }
    if (!mcp_colunas_plano_ok()) {
        return false;
    }
    $stmt = $db->prepare("SELECT 1 FROM mcp_inscricoes WHERE cpf = ? AND curso_slug = ? AND status = 'pago' AND plano = 'taxa_e_matricula' AND pago_em >= ? LIMIT 1");
    $stmt->execute([(string) $i['cpf'], (string) $i['curso_slug'], (string) $i['pago_em']]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Confere de novo, logo antes do envio, no banco: a inscrição continua paga (não foi estornada), a matrícula não foi
 * paga no site nem marcada no painel, a pessoa não se descadastrou destes lembretes e não bloqueou o canal. O motivo,
 * ou null.
 */
function mcp_mp_reconferir(array $p, string $canal): ?string
{
    $i = mcp_inscricao_por('id', (string) $p['inscricao_id']);
    if (!$i || $i['status'] !== 'pago' || mcp_mp_paga_no_site($i)) {
        return 'pagou';
    }
    $stmt = mcp_db()->prepare("SELECT canal, status, descadastro_em FROM mcp_matricula_pendente WHERE inscricao_id = ? AND (canal = 'painel' OR descadastro_em IS NOT NULL) LIMIT 1");
    $stmt->execute([(int) $p['inscricao_id']]);
    $l = $stmt->fetch();
    if ($l) {
        return $l['canal'] === 'painel' ? 'pagou' : 'bloqueou';
    }
    return $canal === 'whatsapp'
        ? ($p['numero'] === null || mcp_avisos_bloqueado('whatsapp', (string) $p['numero']) ? 'bloqueou' : null)
        : ($p['email'] === '' || mcp_avisos_bloqueado('email', (string) $p['email']) ? 'bloqueou' : null);
}

/** Reserva a linha de uma etapa (INSERT IGNORE: a UNIQUE segura dois processos). O id, ou 0 se a etapa já existia. */
function mcp_mp_reservar(array $p, string $canal, int $etapa, ?string $modelo, string $quando): int
{
    $destino = $canal === 'whatsapp' ? mcp_avisos_hash('whatsapp', (string) $p['numero']) : mcp_avisos_hash('email', (string) $p['email']);
    $stmt = mcp_db()->prepare("INSERT IGNORE INTO mcp_matricula_pendente (inscricao_id, canal, etapa, modelo, curso_slug, turma_inicio, destino_hash, status, erro, criado_em, atualizado_em)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'falhou', 'enviando', ?, ?)");
    $stmt->execute([(int) $p['inscricao_id'], $canal, $etapa, $modelo, mb_substr((string) $p['curso_slug'], 0, 80), gmdate('Y-m-d H:i:s', (int) $p['turma_ts']), $destino, $quando, $quando]);
    return $stmt->rowCount() === 1 ? (int) mcp_db()->lastInsertId() : 0;
}

// ----------------------------------------------------------------------------- a rodada
/**
 * Uma rodada (api/lembretes.php, de hora em hora, antes da recuperação do carrinho): WhatsApp e e-mail, cada um com o
 * seu teto por rodada e o teto em 24 h somado ao da recuperação. Nunca lança por causa da Kapso, da Resend ou da escola;
 * erro de banco sobe para quem chama. O resumo vai para mcp_eventos ('matricula_rodada'): números e, por inscrição e
 * canal ("w<id>"/"e<id>"), o motivo de quem estava devido e não recebeu (MCP_MP_MOTIVOS_RODADA).
 * @return array{whatsapp: array, email: array, vistos: int, escola: string, motivo: ?string, pessoas: array}
 */
function mcp_mp_rodar(?int $agora = null): array
{
    $agora ??= time();
    $inicioReal = time();
    $agoraReal = static fn(): int => $agora + max(0, time() - $inicioReal);
    $zero = ['enviados' => 0, 'falhas' => 0, 'pulados' => 0, 'motivo' => null];
    $r = ['whatsapp' => $zero, 'email' => $zero, 'vistos' => 0, 'escola' => '-', 'motivo' => null, 'pessoas' => []];
    if (!mcp_rw_no_horario($agora)) {
        $r['motivo'] = 'fora do horário (8h–20h de Brasília)';
        return $r;
    }
    $carga = mcp_mp_carregar($agora);
    $r['escola'] = $carga['escola']['estado'];
    if ($carga['escola']['estado'] === 'indisponivel') {
        $r['motivo'] = 'escola indisponível (' . $carga['escola']['erro'] . '); segue com o que o site sabe';
    }
    $pendentes = array_values(array_filter($carga['pessoas'], static fn(array $p): bool => $p['motivo'] === null));
    $r['vistos'] = count($pendentes);
    $motivos = [];
    $r['whatsapp'] = mcp_mp_rodar_whatsapp($pendentes, $agora, $agoraReal, $motivos);
    $r['email'] = mcp_mp_rodar_email($pendentes, $agora, $agoraReal, $motivos);
    $r['pessoas'] = $motivos;
    $dados = ['em' => $agora, 'w' => $r['whatsapp'], 'e' => $r['email'], 'vistos' => $r['vistos'], 'escola' => $r['escola'], 'motivo' => $r['motivo'],
        'p' => array_slice($motivos, 0, 150, true)];
    $json = (string) json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    while (strlen($json) > 3900 && $dados['p']) {
        $dados['p'] = array_slice($dados['p'], 0, intdiv(count($dados['p']), 2), true);
        $json = (string) json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    mcp_registrar(null, 'matricula_rodada', $json);
    return $r;
}

/** O WhatsApp da rodada: as etapas devidas, pela Kapso, com os modelos da família mat_pendente_1|2. */
function mcp_mp_rodar_whatsapp(array $pendentes, int $agora, callable $agoraReal, array &$motivos): array
{
    $r = ['enviados' => 0, 'falhas' => 0, 'pulados' => 0, 'motivo' => null];
    $cfg = mcp_rw_kapso_config();
    if (!$cfg['ok']) {
        $r['motivo'] = 'sem configuração (' . implode(', ', $cfg['falta']) . ')';
        return $r;
    }
    $fila = [];
    foreach ($pendentes as $p) {
        if ($p['motivo_wa'] !== null) {
            continue;
        }
        $px = mcp_mp_proxima($p['pago_ts'], (int) $p['turma_ts'], mcp_mp_enviadas($p, 'whatsapp'), $agora);
        if ($px !== null && $px['devida_em'] <= $agora) {
            $fila[] = [$px, $p, mcp_mp_modelo($px['etapa'], $p, $agora, mcp_rw_token('m' . $p['inscricao_id'], $agora))];
        }
    }
    if (!$fila) {
        return $r;
    }
    $modelos = mcp_rw_modelos($cfg, $agora);
    $usavel = static function (array $m) use ($modelos): bool {
        return !$modelos['ok'] || (($modelos['modelos'][$m['nome']] ?? '') === 'APPROVED'
            && mcp_rw_estrutura_problema($modelos['estrutura'][$m['nome']] ?? ['botao' => null, 'params' => null], $m) === null);
    };
    $faltam = [];
    foreach ($fila as $k => [$px, $p, $m]) {
        $m = mcp_rw_escolher_modelo($m, $modelos);
        $m['botao'] = $modelos['estrutura'][$m['nome']]['botao'] ?? null;
        $fila[$k][2] = $m;
        if (!$usavel($m)) {
            $faltam[$m['nome']] = true;
            $motivos['w' . $p['inscricao_id']] = 'm';
            unset($fila[$k]);
        }
    }
    if ($faltam) {
        $r['motivo'] = 'modelo sem aprovação: ' . implode(', ', array_keys($faltam));
    }
    usort($fila, static fn(array $a, array $b): int => [$a[0]['devida_em'], $a[1]['inscricao_id']] <=> [$b[0]['devida_em'], $b[1]['inscricao_id']]);
    $db = mcp_db();
    $noDia = mcp_rw_enviadas_24h($agora);
    $parou = null;
    $ultimoErro = null;
    foreach ($fila as [$px, $p, $m]) {
        $chave = 'w' . $p['inscricao_id'];
        $agoraEnvio = $agoraReal();
        $codigo = match (true) {
            $parou !== null => $parou,
            $r['enviados'] + $r['falhas'] >= MCP_MP_POR_RODADA => 't',
            $noDia >= MCP_RW_POR_DIA => 'T',
            !mcp_rw_no_horario($agoraEnvio) => 'h',
            $agoraEnvio >= $px['limite'] => 'c',
            default => null,
        };
        if ($codigo !== null) {
            $motivos[$chave] = $codigo;
            $parou = in_array($codigo, ['t', 'T', 'h', 'p'], true) ? $codigo : $parou;
            $r['motivo'] = $codigo === 'T' ? 'teto de ' . MCP_RW_POR_DIA . ' mensagens em 24 h (somado à recuperação)' : $r['motivo'];
            continue;
        }
        if (mcp_mp_reconferir($p, 'whatsapp') !== null) {
            $r['pulados']++;
            $motivos[$chave] = 'r';
            continue;
        }
        $id = mcp_mp_reservar($p, 'whatsapp', $px['etapa'], $m['nome'], gmdate('Y-m-d H:i:s', $agoraEnvio));
        if ($id === 0) {
            $r['pulados']++;
            $motivos[$chave] = 'd';
            continue;
        }
        $envio = mcp_rw_kapso_enviar($cfg, mcp_rw_corpo_envio((string) $p['numero'], $m));
        if (!$envio['ok'] && $envio['transitorio']) {
            // Não saiu, e a culpa não é de quem recebe: a etapa não é gasta (como na recuperação).
            $db->prepare('DELETE FROM mcp_matricula_pendente WHERE id = ?')->execute([$id]);
            $r['falhas']++;
            $repetido = $envio['escopo'] === 'pessoa' && $ultimoErro === $envio['codigo'];
            $motivos[$chave] = $envio['escopo'] === 'pessoa' ? 'x:' . $envio['codigo'] : 'p';
            if ($envio['escopo'] === 'pessoa' && !$repetido) {
                $ultimoErro = $envio['codigo'];
                continue;
            }
            $r['motivo'] = 'Kapso: ' . $envio['erro'];
            $parou = 'p';
            error_log('[matricula] matrícula pendente por WhatsApp parada nesta rodada: ' . $envio['erro']);
            continue;
        }
        $ultimoErro = null;
        $db->prepare('UPDATE mcp_matricula_pendente SET status = ?, provedor_id = ?, erro = ?, atualizado_em = ? WHERE id = ?')
            ->execute([$envio['ok'] ? 'enviado' : 'falhou', $envio['id'], $envio['erro'] !== null ? mb_substr($envio['erro'], 0, 255) : null, gmdate('Y-m-d H:i:s', $agoraReal()), $id]);
        if ($envio['ok']) {
            $r['enviados']++;
            $noDia++;
            if ($envio['incerto'] && $envio['parar']) {
                $r['motivo'] = 'Kapso: ' . $envio['erro'];
                $parou = 'p';
            }
        } else {
            $r['falhas']++;
            $motivos[$chave] = 'f:' . $envio['codigo'];
        }
    }
    return $r;
}

/** Os e-mails da rodada, pela Resend, com o descadastro de um clique (api/avisos.php?mp=). */
function mcp_mp_rodar_email(array $pendentes, int $agora, callable $agoraReal, array &$motivos): array
{
    $r = ['enviados' => 0, 'falhas' => 0, 'pulados' => 0, 'motivo' => null];
    if (!mcp_rw_email_config()['ok']) {
        $r['motivo'] = 'sem configuração (RESEND_API_KEY)';
        return $r;
    }
    $fila = [];
    foreach ($pendentes as $p) {
        if ($p['motivo_email'] !== null) {
            continue;
        }
        $px = mcp_mp_proxima($p['pago_ts'], (int) $p['turma_ts'], mcp_mp_enviadas($p, 'email'), $agora);
        if ($px !== null && $px['devida_em'] <= $agora) {
            $fila[] = [$px, $p];
        }
    }
    if (!$fila) {
        return $r;
    }
    usort($fila, static fn(array $a, array $b): int => [$a[0]['devida_em'], $a[1]['inscricao_id']] <=> [$b[0]['devida_em'], $b[1]['inscricao_id']]);
    $site = mcp_rw_email_site_24h($agora);
    if ($site >= MCP_RW_EMAIL_RESERVA_SITE) {
        $r['motivo'] = "o site já mandou $site e-mails em 24 h; a cota da Resend fica para os recibos";
        foreach ($fila as [, $p]) {
            $motivos['e' . $p['inscricao_id']] = 'k';
        }
        return $r;
    }
    $db = mcp_db();
    $noDia = mcp_rw_email_enviados_24h($agora);
    $parou = null;
    $recusas = [];
    foreach ($fila as [$px, $p]) {
        $chave = 'e' . $p['inscricao_id'];
        $agoraEnvio = $agoraReal();
        $codigo = match (true) {
            $parou !== null => $parou,
            $r['enviados'] + $r['falhas'] >= MCP_MP_EMAIL_POR_RODADA => 't',
            $noDia >= MCP_RW_EMAIL_POR_DIA => 'T',
            !mcp_rw_no_horario($agoraEnvio) => 'h',
            $agoraEnvio >= $px['limite'] => 'c',
            default => null,
        };
        if ($codigo !== null) {
            $motivos[$chave] = $codigo;
            $parou = in_array($codigo, ['t', 'T', 'h', 'p'], true) ? $codigo : $parou;
            $r['motivo'] = $codigo === 'T' ? 'teto de ' . MCP_RW_EMAIL_POR_DIA . ' e-mails em 24 h (somado à recuperação)' : $r['motivo'];
            continue;
        }
        if (mcp_mp_reconferir($p, 'email') !== null) {
            $r['pulados']++;
            $motivos[$chave] = 'r';
            continue;
        }
        $id = mcp_mp_reservar($p, 'email', $px['etapa'], 'mat' . $px['etapa'], gmdate('Y-m-d H:i:s', $agoraEnvio));
        if ($id === 0) {
            $r['pulados']++;
            $motivos[$chave] = 'd';
            continue;
        }
        $link = mcp_site_url() . '/' . mcp_rw_link('email', (string) $p['curso_slug'], 'mat' . $px['etapa'], mcp_rw_token('m' . $p['inscricao_id'], $agora));
        $msg = mcp_mp_email_montar($px['etapa'], $p, $agoraEnvio, $link, mcp_mp_descadastro_url($id));
        mcp_email_ultimo_id('');
        $envio = mcp_enviar_email((string) $p['email'], $msg['assunto'], $msg['html'], $msg['texto'], mcp_email_contato_endereco(), null, [],
            ['sem_reserva' => true, 'tempo' => MCP_RW_EMAIL_TEMPO_S, 'cabecalhos' => mcp_mp_cabecalhos($id)]);
        $atualizar = static function (string $status, ?string $provedor, ?string $erro) use ($db, $id, $msg, $agoraReal): void {
            $db->prepare('UPDATE mcp_matricula_pendente SET status = ?, assunto = ?, provedor_id = ?, erro = ?, atualizado_em = ? WHERE id = ?')
                ->execute([$status, mb_substr($msg['assunto'], 0, 200), $provedor, $erro, gmdate('Y-m-d H:i:s', $agoraReal()), $id]);
        };
        if ($envio === 'resend' || $envio === 'incerto') {
            $atualizar('enviado', mcp_email_ultimo_id() ?: null, $envio === 'incerto' ? 'a Resend não confirmou a tempo; o e-mail pode ter saído (não vai de novo)' : null);
            $r['enviados']++;
            $noDia++;
            $recusas = [];
            continue;
        }
        $r['falhas']++;
        if ($envio === 'limite' || $envio === 'sem_resend') {
            $db->prepare('DELETE FROM mcp_matricula_pendente WHERE id = ?')->execute([$id]);
            $r['motivo'] = $envio === 'limite' ? 'Resend: limite de envio (429)' : 'sem configuração (RESEND_API_KEY)';
            $parou = 'p';
            $motivos[$chave] = 'p';
            continue;
        }
        $atualizar('falhou', null, 'a Resend recusou o e-mail');
        $recusas[] = $id;
        $motivos[$chave] = 'f';
        if (count($recusas) >= 2) {
            // Duas recusas seguidas: o problema é da Resend, não dos endereços. Ninguém gasta a etapa.
            $db->prepare('DELETE FROM mcp_matricula_pendente WHERE id IN (' . implode(',', array_map('intval', $recusas)) . ')')->execute();
            $r['motivo'] = 'Resend: duas recusas seguidas; tenta de novo na próxima rodada';
            $parou = 'p';
        }
    }
    return $r;
}

// ----------------------------------------------------------------------------- descadastro, painel e webhook
function mcp_mp_assinatura(int $id): string
{
    return substr(mcp_avisos_assinar("mp|$id"), 0, 16);
}

/** "Não quero mais receber" e o List-Unsubscribe destes e-mails: api/avisos.php?mp=<id>.<assinatura>. */
function mcp_mp_descadastro_url(int $id): string
{
    return mcp_site_url() . '/matricula-cursos-presenciais/api/avisos.php?mp=' . $id . '.' . mcp_mp_assinatura($id);
}

function mcp_mp_cabecalhos(int $id): array
{
    return ['List-Unsubscribe' => '<' . mcp_mp_descadastro_url($id) . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
}

/** A linha de um código de descadastro (id.assinatura) que vale, ou null. */
function mcp_mp_descadastro_linha(string $codigo): ?array
{
    if (!preg_match('/^(\d{1,10})\.([a-f0-9]{16})$/', $codigo, $m) || !hash_equals(mcp_mp_assinatura((int) $m[1]), $m[2])) {
        return null;
    }
    $stmt = mcp_db()->prepare("SELECT id, inscricao_id FROM mcp_matricula_pendente WHERE id = ? AND canal = 'email'");
    $stmt->execute([(int) $m[1]]);
    return $stmt->fetch() ?: null;
}

/**
 * Descadastro destes lembretes: a linha ganha descadastro_em e a inscrição não recebe mais nada da matrícula pendente
 * (nem e-mail nem WhatsApp). De propósito, NÃO grava mcp_avisos_bloqueios: a pessoa é aluna e os avisos das aulas (e o
 * comprovante) continuam chegando.
 */
function mcp_mp_descadastrar(array $linha): bool
{
    mcp_db()->prepare('UPDATE mcp_matricula_pendente SET descadastro_em = COALESCE(descadastro_em, ?), atualizado_em = ? WHERE id = ?')
        ->execute([mcp_agora(), mcp_agora(), (int) $linha['id']]);
    mcp_registrar((int) $linha['inscricao_id'], 'matricula_descadastro', 'e-mail #' . (int) $linha['id']);
    return true;
}

/** "Já pagou na escola" (painel): a linha canal painel, etapa 0. A inscrição sai da régua e conta como paga. */
function mcp_mp_marcar_pago_escola(int $inscricaoId, string $quem): bool
{
    $quando = mcp_agora();
    $stmt = mcp_db()->prepare("INSERT IGNORE INTO mcp_matricula_pendente (inscricao_id, canal, etapa, status, autor, criado_em, atualizado_em) VALUES (?, 'painel', 0, 'pago_escola', ?, ?, ?)");
    $stmt->execute([$inscricaoId, mb_substr($quem, 0, 190), $quando, $quando]);
    mcp_registrar($inscricaoId, 'matricula_paga_escola', mb_substr($quem, 0, 120));
    return $stmt->rowCount() === 1;
}

/**
 * Resposta de WhatsApp a uma mensagem da matrícula pendente ($citada: o wamid): guardada na linha (o painel mostra). Um
 * "parar" já guardado não é coberto por outra resposta. true se a mensagem citada é desta tabela. Sem a tabela, false.
 */
function mcp_mp_guardar_resposta(string $citada, string $texto, string $quando): bool
{
    try {
        $stmt = mcp_db()->prepare('SELECT id, resposta FROM mcp_matricula_pendente WHERE provedor_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$citada]);
        $l = $stmt->fetch();
    } catch (PDOException) {
        return false;
    }
    if (!$l) {
        return false;
    }
    if (mcp_rw_quer_parar($texto) || !mcp_rw_quer_parar((string) $l['resposta'])) {
        mcp_db()->prepare('UPDATE mcp_matricula_pendente SET resposta = ?, atualizado_em = ? WHERE id = ?')->execute([$texto, $quando, (int) $l['id']]);
    }
    return true;
}

// ----------------------------------------------------------------------------- pagamento da matrícula pelo site
/** A inscrição do token de um link da matrícula (ref m<id>), ou null (token adulterado, vencido ou de outro tipo). */
function mcp_mp_inscricao_do_token(string $token, int $agora): ?array
{
    $ref = strlen($token) <= 120 ? mcp_rw_token_ref($token, $agora) : null;
    if ($ref === null || !preg_match('/^m(\d{1,10})$/', $ref, $m)) {
        return null;
    }
    return mcp_inscricao_por('id', $m[1]);
}

/**
 * Se a matrícula desta inscrição pode ser paga agora, e por quê não: ['pode' => bool, 'motivo' => null | 'nao_paga' (a
 * taxa não está paga, ou foi estornada), 'completa' (a compra já incluía a matrícula), 'paga' (a matrícula já foi paga),
 * 'sem_preco', 'turma_passou' (começou há mais de 1 dia), 'valor', 'turma' (mcp_mp_turma)]. A escola não é consultada
 * aqui (a tela não espera a escola): quem pagou na escola e abre o link vê a cobrança, e a mensagem diz "se já pagou,
 * desconsidere".
 */
function mcp_mp_situacao(array $i, int $agora): array
{
    $curso = mcp_curso((string) $i['curso_slug']);
    $valor = $curso !== null ? mcp_matricula_centavos($curso, (string) $i['cpf']) : 0;
    $turma = mcp_mp_turma($i);
    $motivo = match (true) {
        ($i['status'] ?? '') !== 'pago' => 'nao_paga',
        ($i['plano'] ?? 'so_taxa') === 'taxa_e_matricula' => 'completa',
        mcp_mp_paga_no_site($i) => 'paga',
        $valor <= 0 => 'sem_preco',
        $turma !== null && $turma['ts'] + MCP_MP_PAGAR_ATE_DEPOIS_S < $agora => 'turma_passou',
        default => null,
    };
    return ['pode' => $motivo === null, 'motivo' => $motivo, 'valor' => $valor, 'turma' => $turma];
}

function mcp_mp_pagamento_por(string $coluna, string $valor): ?array
{
    if (!in_array($coluna, ['id', 'token', 'unicopag_hash'], true)) {
        return null;
    }
    $stmt = mcp_db()->prepare("SELECT * FROM mcp_matricula_pagamentos WHERE $coluna = ? LIMIT 1");
    $stmt->execute([$valor]);
    return $stmt->fetch() ?: null;
}

/**
 * O que a tela recebe: o primeiro nome, o curso, a turma, o valor, se pode pagar e, com um pagamento, o status e o PIX.
 * Nunca o CPF, o telefone, o hash da transação nem o cartão (só bandeira e final). O e-mail vai mascarado.
 */
function mcp_mp_publico(array $i, array $situacao, ?array $pg = null): array
{
    $turma = $situacao['turma'];
    return [
        'ok' => true,
        // O mesmo primeiro nome das mensagens ("PAULA SOUZA" → "Paula"); sem nome usável, "aluno(a)".
        'nome' => mcp_rw_primeiro_nome((string) $i['nome']) ?: MCP_RW_NOME_PADRAO,
        'curso' => ['slug' => (string) $i['curso_slug'], 'nome' => (string) $i['curso_nome']],
        'turma' => $turma ? ['rotulo' => $turma['rotulo'], 'data' => (new DateTimeImmutable('@' . $turma['ts']))->setTimezone(mcp_fuso_brt())->format('d/m/Y'),
            'hora' => $turma['hora'] ? (new DateTimeImmutable('@' . $turma['ts']))->setTimezone(mcp_fuso_brt())->format('H\hi') : null] : null,
        'valor_centavos' => (int) ($pg['valor_centavos'] ?? $situacao['valor']),
        'pode_pagar' => $situacao['pode'],
        'motivo' => $situacao['motivo'],
        'email' => mcp_email_mascarado((string) $i['email']),
        'pagamento' => $pg === null ? null : [
            'token' => (string) $pg['token'], 'status' => (string) $pg['status'], 'metodo' => (string) $pg['metodo'],
            'pix' => $pg['metodo'] === 'pix' ? ['copia_cola' => $pg['pix_copia_cola'], 'url' => $pg['pix_url'], 'imagem' => $pg['pix_imagem']] : null,
            'cartao' => $pg['metodo'] === 'cartao' ? ['bandeira' => $pg['bandeira'], 'ultimos4' => $pg['ultimos4']] : null,
            'pago_em' => $pg['pago_em'],
        ],
    ];
}

/** O PIX pendente desta matrícula, recente e do mesmo valor, ainda aberto na Unicopag (reaproveitado), ou null. */
function mcp_mp_pix_aberto(array $i, int $valor): ?array
{
    $stmt = mcp_db()->prepare("SELECT * FROM mcp_matricula_pagamentos WHERE inscricao_id = ? AND metodo = 'pix' AND status = 'pendente' AND valor_centavos = ?
        AND pix_copia_cola IS NOT NULL AND criado_em > ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([(int) $i['id'], $valor, gmdate('Y-m-d H:i:s', time() - MCP_MP_PIX_REAPROVEITA_S)]);
    $pg = $stmt->fetch();
    if (!$pg) {
        return null;
    }
    $pg = mcp_mp_sincronizar($pg);
    return ($pg['status'] ?? '') === 'pendente' ? $pg : null;
}

/** O corpo do POST /public/v1/payments da matrícula: um item só, com os dados do pagador da própria inscrição. */
function mcp_mp_montar_cobranca(array $i, string $token, int $valor, string $metodo, array $origem): array
{
    $slug = (string) $i['curso_slug'];
    $nome = (string) $i['curso_nome'];
    $pix = $metodo === 'pix';
    return [
        'amount' => $valor,
        'payment_method' => $pix ? 'pix' : 'credit_card',
        'installments' => 1,
        'customer' => ['name' => (string) $i['nome'], 'email' => (string) $i['email'], 'phone_number' => (string) $i['telefone'], 'document' => (string) $i['cpf']],
        'cart' => [['hash' => 'matricula-' . $slug, 'title' => $nome . ' — Matrícula', 'price' => $valor, 'quantity' => 1, 'operation_type' => 1]],
        'postback_url' => mcp_site_url() . '/matricula-cursos-presenciais/api/webhook.php',
        'expire_in_days' => 1,
        'origin' => 'matricula-cursos-presenciais',
        'metadata' => ['token' => $token, 'tipo' => 'matricula', 'inscricao' => (int) $i['id'], 'curso' => $slug, 'curso_nome' => $nome, 'aluno' => (string) $i['nome'],
            'utm_source' => $origem['utm_source'] ?? null, 'utm_campaign' => $origem['utm_campaign'] ?? null],
    ];
}

/**
 * Cria a cobrança da matrícula (ou devolve o PIX aberto) e grava. $cartao: de mcp_validar_cartao (só no cartão).
 * Devolve [pagamento, http] ou lança McpUnicopagErro (quem chama responde ao navegador).
 */
function mcp_mp_cobrar(array $i, int $valor, string $metodo, ?array $cartao, array $origem): array
{
    if ($metodo === 'pix' && ($aberto = mcp_mp_pix_aberto($i, $valor))) {
        mcp_registrar((int) $i['id'], 'matricula_pix_reaproveitado', '#' . (int) $aberto['id']);
        return [$aberto, 200];
    }
    $token = mcp_token_novo();
    $payload = mcp_mp_montar_cobranca($i, $token, $valor, $metodo, $origem);
    if ($cartao !== null) {
        $payload['card'] = $cartao['card'];
    }
    try {
        $cobranca = mcp_unicopag('POST', '/public/v1/payments', $payload);
    } finally {
        unset($payload, $cartao['card']);
    }
    $pix = $metodo === 'pix' && is_array($cobranca['pix'] ?? null) ? $cobranca['pix'] : [];
    $statusOrigem = mb_substr((string) ($cobranca['payment_status'] ?? ''), 0, 40);
    $status = mcp_traduzir_status($statusOrigem);
    $agora = mcp_agora();
    $limitar = static fn(mixed $v, int $n): ?string => mb_substr((string) ($v ?? ''), 0, $n) ?: null;
    $linha = [
        'inscricao_id' => (int) $i['id'], 'token' => $token, 'metodo' => $metodo, 'valor_centavos' => $valor,
        // Pago entra como pendente e vira pago pela porta única (mcp_mp_aplicar_status), que dispara o pós-pagamento uma vez.
        'status' => $status === 'pago' ? 'pendente' : $status,
        'unicopag_hash' => $limitar($cobranca['hash'] ?? null, 64), 'unicopag_status' => $statusOrigem ?: null,
        'pix_copia_cola' => $pix ? ($pix['pix_qr_code'] ?? null) : null, 'pix_url' => $pix ? $limitar($pix['pix_url'] ?? null, 255) : null,
        'pix_imagem' => $pix ? $limitar($pix['pix_base64'] ?? null, 255) : null,
        'bandeira' => $metodo === 'cartao' ? $limitar($cobranca['card_brand'] ?? null, 30) : null, 'ultimos4' => $cartao['ultimos4'] ?? null,
        'utm_source' => $limitar($origem['utm_source'] ?? null, 120), 'utm_campaign' => $limitar($origem['utm_campaign'] ?? null, 160),
        'ip' => mcp_ip(), 'criado_em' => $agora, 'atualizado_em' => $agora, 'consultado_em' => $agora,
    ];
    $db = mcp_db();
    $db->prepare('INSERT INTO mcp_matricula_pagamentos (' . implode(', ', array_keys($linha)) . ') VALUES (' . implode(', ', array_fill(0, count($linha), '?')) . ')')
        ->execute(array_values($linha));
    $id = (int) $db->lastInsertId();
    mcp_registrar((int) $i['id'], 'matricula_cobranca', "$metodo · $statusOrigem · " . mcp_brl($valor) . ' · #' . $id . ($linha['utm_campaign'] ? ' · ' . $linha['utm_campaign'] : ''));
    $pg = $status === 'pago' ? mcp_mp_aplicar_status($id, 'pago') : (mcp_mp_pagamento_por('id', (string) $id) ?? []);
    return [$pg, 201];
}

/** Reconsulta a transação na Unicopag e aplica o resultado (postback, tela e varredura passam por aqui). */
function mcp_mp_sincronizar(array $pg): array
{
    if (empty($pg['unicopag_hash'])) {
        return $pg;
    }
    $id = (int) $pg['id'];
    try {
        $transacao = mcp_unicopag('GET', '/public/v1/transactions/' . rawurlencode((string) $pg['unicopag_hash']));
    } catch (McpUnicopagErro $e) {
        mcp_registrar((int) $pg['inscricao_id'], 'matricula_consulta_falhou', $e->status . ' ' . mb_substr($e->getMessage(), 0, 120));
        mcp_db()->prepare('UPDATE mcp_matricula_pagamentos SET consultado_em = ? WHERE id = ?')->execute([mcp_agora(), $id]);
        return $pg;
    }
    $origem = (string) ($transacao['payment_status'] ?? '');
    mcp_db()->prepare('UPDATE mcp_matricula_pagamentos SET consultado_em = ?, unicopag_status = ? WHERE id = ?')->execute([mcp_agora(), mb_substr($origem, 0, 40), $id]);
    return mcp_mp_aplicar_status($id, mcp_traduzir_status($origem));
}

/**
 * Aplica um status com as transições de mcp_aplicar_status (pago é definitivo, só o estorno muda; recusado e expirado só
 * sobre pendente). O UPDATE condicional é atômico: postback e consulta da tela ao mesmo tempo disparam o pós-pagamento
 * uma vez só.
 */
function mcp_mp_aplicar_status(int $id, string $novo): array
{
    $agora = mcp_agora();
    $transicoes = [
        'pago' => ["UPDATE mcp_matricula_pagamentos SET status = 'pago', pago_em = ?, atualizado_em = ? WHERE id = ? AND status <> 'pago' AND status <> 'estornado'", [$agora, $agora, $id]],
        'recusado' => ["UPDATE mcp_matricula_pagamentos SET status = 'recusado', atualizado_em = ? WHERE id = ? AND status = 'pendente'", [$agora, $id]],
        'expirado' => ["UPDATE mcp_matricula_pagamentos SET status = 'expirado', atualizado_em = ? WHERE id = ? AND status = 'pendente'", [$agora, $id]],
        'estornado' => ["UPDATE mcp_matricula_pagamentos SET status = 'estornado', atualizado_em = ? WHERE id = ? AND status IN ('pago','pendente')", [$agora, $id]],
    ];
    if (isset($transicoes[$novo])) {
        $stmt = mcp_db()->prepare($transicoes[$novo][0]);
        $stmt->execute($transicoes[$novo][1]);
        if ($stmt->rowCount() > 0) {
            $pg = mcp_mp_pagamento_por('id', (string) $id);
            mcp_registrar($pg ? (int) $pg['inscricao_id'] : null, 'matricula_' . $novo, '#' . $id);
            if ($novo === 'pago') {
                mcp_mp_pos_pagamento($id);
            } elseif ($novo === 'estornado') {
                mcp_mp_pos_estorno($id);
            }
        }
    }
    return mcp_mp_pagamento_por('id', (string) $id) ?? [];
}

/** As linhas da caixa do aviso à secretaria (e do comprovante): a inscrição e o pagamento da matrícula. */
function mcp_mp_linhas(array $i, array $pg): array
{
    $turma = mcp_mp_turma($i);
    return [
        'Curso' => (string) $i['curso_nome'], 'Aluno' => (string) $i['nome'], 'CPF' => (string) $i['cpf'], 'E-mail' => (string) $i['email'], 'Telefone' => (string) $i['telefone'],
        'Turma' => $turma ? $turma['rotulo'] . ($turma['hora'] ? ', às ' . (new DateTimeImmutable('@' . $turma['ts']))->setTimezone(mcp_fuso_brt())->format('H\hi') : '') : 'sem data',
        'Matrícula paga' => mcp_brl((int) $pg['valor_centavos']) . ' à vista',
        'Método' => $pg['metodo'] === 'pix' ? 'PIX' : 'Cartão ' . ($pg['bandeira'] ?? '') . ' final ' . ($pg['ultimos4'] ?? ''),
        'Transação Unicopag' => (string) ($pg['unicopag_hash'] ?? ''),
        'Inscrição (taxa)' => '#' . (int) $i['id'] . ', paga em ' . mcp_data_brt((string) $i['pago_em'], 'd/m/Y'),
    ];
}

/**
 * Uma vez, na transição para pago: o comprovante ao aluno e o aviso à secretaria para marcar a matrícula como paga na
 * plataforma da escola (À vista, PAGO, Pagamento CURSO, gateway unicopag-2: o mesmo procedimento de "matrícula não
 * marcada" do plano completo). Nada aqui chama a escola nem a Meta (a compra já foi contada na taxa).
 */
function mcp_mp_pos_pagamento(int $id): void
{
    $pg = mcp_mp_pagamento_por('id', (string) $id);
    $i = $pg ? mcp_inscricao_por('id', (string) $pg['inscricao_id']) : null;
    if (!$pg || !$i) {
        return;
    }
    $linhas = mcp_mp_linhas($i, $pg);
    $m = mcp_mp_email_aluno_montar($i, $pg, $linhas);
    $aluno = mcp_enviar_email((string) $i['email'], $m['assunto'], $m['html'], $m['texto']);
    $secretaria = mcp_aviso_secretaria('Matrícula paga pelo site: ' . $i['nome'] . ' — ' . $i['curso_nome'], 'Matrícula paga pelo site',
        'O aluno pagou pelo site a matrícula de ' . mcp_escapar((string) $i['curso_nome']) . ' (' . mcp_escapar(mcp_brl((int) $pg['valor_centavos'])) . '), depois da taxa de inscrição. '
        . '<strong>Marque a matrícula como paga na plataforma da escola: À vista, PAGO, Pagamento CURSO de ' . mcp_escapar(mcp_brl((int) $pg['valor_centavos']))
        . ', gateway unicopag-2.</strong> A entrada na aula depende dessa marcação.', $linhas, (string) $i['email'], (int) $i['id']);
    mcp_db()->prepare('UPDATE mcp_matricula_pagamentos SET email_aluno = ?, email_secretaria = ?, atualizado_em = ? WHERE id = ?')
        ->execute([mb_substr($aluno, 0, 20), mb_substr($secretaria !== '' ? $secretaria : 'sem_destino', 0, 20), mcp_agora(), $id]);
}

/** O comprovante da matrícula paga ao aluno. Puro. */
function mcp_mp_email_aluno_montar(array $i, array $pg, array $linhas): array
{
    $nome = mcp_rw_primeiro_nome((string) $i['nome']) ?: mcp_primeiro_nome((string) $i['nome']);
    $curso = (string) $i['curso_nome'];
    $valor = mcp_brl((int) $pg['valor_centavos']);
    $j = mcp_email_juntar([
        mcp_email_b_p('Oi, ' . mcp_escapar($nome) . '. Recebemos o pagamento da matrícula em <strong>' . mcp_escapar($curso) . '</strong>. Com a taxa de inscrição e a matrícula pagas, a sua entrada na turma de <strong>'
            . mcp_escapar($linhas['Turma']) . '</strong> está garantida.'),
        mcp_email_b_caixa(array_intersect_key($linhas, array_flip(['Curso', 'Turma', 'Matrícula paga', 'Método']))
            + ['Pago em' => mcp_data_brt((string) ($pg['pago_em'] ?? mcp_agora()))]),
        mcp_email_b_p('A secretaria da Escola já foi avisada e registra a matrícula na plataforma. Guarde este e-mail: ele é o seu comprovante. No dia da aula, chegue com 15 minutos de antecedência ao endereço ' . mcp_escapar(MCP_EMAIL_LOCAL) . '.'),
        mcp_email_b_nota('Dúvidas? É só responder este e-mail.'),
    ]);
    return [
        'assunto' => "Matrícula paga: $curso",
        'html' => mcp_moldura("Matrícula paga, $nome!", $j['html'], mcp_email_opcoes_aluno("Pagamento de $valor da matrícula confirmado. Guarde este e-mail.")),
        'texto' => $j['texto'] . "\n\nDúvidas? Responda este e-mail ou escreva para " . mcp_email_contato_endereco() . ".\n",
    ];
}

/** Na transição para estornado: o aviso à secretaria (a matrícula volta a ficar pendente na régua). */
function mcp_mp_pos_estorno(int $id): void
{
    $pg = mcp_mp_pagamento_por('id', (string) $id);
    $i = $pg ? mcp_inscricao_por('id', (string) $pg['inscricao_id']) : null;
    if (!$pg || !$i) {
        return;
    }
    mcp_aviso_secretaria('Estorno da matrícula paga pelo site: ' . $i['nome'] . ' — ' . $i['curso_nome'], 'Estorno da matrícula',
        'A Unicopag confirmou o estorno da matrícula paga pelo site. Se ela foi marcada como paga na plataforma da escola, desfaça a marcação. A taxa de inscrição não muda.',
        mcp_mp_linhas($i, $pg), (string) $i['email'], (int) $i['id']);
}

// ----------------------------------------------------------------------------- métricas do painel (puro)
/**
 * Os números da seção "Matrícula pendente" do painel, de $carga (mcp_mp_carregar), sem dado pessoal nos agregados:
 *  - pendentes (taxa paga, matrícula não paga, turma no futuro), sem_turma, turma_passou e pagas (no período, por como);
 *  - enviadas por canal e etapa; receberam (inscrições com ao menos 1 mensagem de algum canal);
 *  - pagaram_depois: das que receberam, as que pagaram a matrícula DEPOIS da 1ª mensagem (por como: site, completa,
 *    escola, painel), e pelo_link (pagamentos pelo site com utm_campaign mat1 ou mat2, por fonte);
 *  - lista: as pendentes, com a próxima mensagem de cada canal (mcp_mp_proxima com o ajuste de horário).
 */
function mcp_mp_metricas(array $carga, int $agora, array $peloLink = []): array
{
    $m = ['pendentes' => 0, 'sem_turma' => 0, 'turma_passou' => 0, 'pagas' => ['site' => 0, 'completa' => 0, 'escola' => 0, 'painel' => 0],
        'enviadas' => ['whatsapp' => [1 => 0, 2 => 0], 'email' => [1 => 0, 2 => 0]], 'falhas' => 0, 'receberam' => 0,
        'pagaram_depois' => 0, 'depois_como' => ['site' => 0, 'completa' => 0, 'escola' => 0, 'painel' => 0], 'mediana_horas' => null,
        'pelo_link' => array_replace(['whatsapp' => ['mat1' => 0, 'mat2' => 0], 'email' => ['mat1' => 0, 'mat2' => 0]], $peloLink), 'lista' => []];
    $horas = [];
    foreach ($carga['pessoas'] as $p) {
        $enviadas = [];
        foreach (['whatsapp', 'email'] as $canal) {
            foreach ($p['linhas'][$canal] ?? [] as $etapa => $l) {
                if ($l['status'] === 'enviado' && (int) $etapa >= 1) {
                    $m['enviadas'][$canal][(int) $etapa] = ($m['enviadas'][$canal][(int) $etapa] ?? 0) + 1;
                    $enviadas[] = (int) $l['criado_ts'];
                } elseif ($l['status'] === 'falhou' && ($l['erro'] ?? null) !== 'enviando') {
                    $m['falhas']++;
                }
            }
        }
        sort($enviadas);
        if ($enviadas) {
            $m['receberam']++;
            if ($p['paga_em'] !== null && $p['paga_em'] > $enviadas[0]) {
                $m['pagaram_depois']++;
                $m['depois_como'][$p['paga_como']]++;
                $horas[] = ($p['paga_em'] - $enviadas[0]) / 3600;
            }
        }
        match ($p['motivo']) {
            'paga' => $m['pagas'][$p['paga_como']]++,
            'sem_turma' => $m['sem_turma']++,
            'turma_passou' => $m['turma_passou']++,
            null => $m['pendentes']++,
            default => null,
        };
        if ($p['motivo'] !== null) {
            continue;
        }
        $proxima = static function (string $canal) use ($p, $agora): ?array {
            if ($p[$canal === 'whatsapp' ? 'motivo_wa' : 'motivo_email'] !== null) {
                return null;
            }
            $px = mcp_mp_proxima($p['pago_ts'], (int) $p['turma_ts'], mcp_mp_enviadas($p, $canal), $agora);
            if ($px === null) {
                return null;
            }
            $quando = mcp_rw_ajustar_horario(max($px['devida_em'], $agora));
            return $quando < $px['limite'] ? ['etapa' => $px['etapa'], 'quando' => $quando] : null;
        };
        $quantas = static fn(string $canal): int => count(array_filter($p['linhas'][$canal] ?? [], static fn(array $l): bool => $l['status'] === 'enviado'));
        $respostas = array_values(array_filter(array_map(static fn(array $l): string => (string) ($l['resposta'] ?? ''), $p['linhas']['whatsapp'] ?? [])));
        $m['lista'][] = [
            'inscricao_id' => $p['inscricao_id'], 'nome' => $p['nome'], 'curso' => $p['curso_nome'], 'turma' => $p['turma_rotulo'],
            'turma_ts' => $p['turma_ts'], 'celular' => mcp_whatsapp_mascarado($p['numero']), 'email' => $p['email'] !== '' ? mcp_email_mascarado($p['email']) : '',
            'whatsapp' => ['enviadas' => $quantas('whatsapp'), 'motivo' => $p['motivo_wa'], 'proxima' => $proxima('whatsapp')],
            'canal_email' => ['enviadas' => $quantas('email'), 'motivo' => $p['motivo_email'], 'proxima' => $proxima('email')],
            'respostas' => $respostas,
        ];
    }
    $m['mediana_horas'] = mcp_rw_mediana($horas);
    usort($m['lista'], static fn(array $a, array $b): int => [$a['turma_ts'], $a['nome']] <=> [$b['turma_ts'], $b['nome']]);
    return $m;
}

/** Pagamentos da matrícula pelo botão de uma mensagem (utm_campaign mat1/mat2), por fonte, desde $desde. Só contagem. */
function mcp_mp_pelo_link(int $desde): array
{
    $saida = ['whatsapp' => ['mat1' => 0, 'mat2' => 0], 'email' => ['mat1' => 0, 'mat2' => 0]];
    $stmt = mcp_db()->prepare("SELECT utm_source, utm_campaign, COUNT(*) AS n FROM mcp_matricula_pagamentos WHERE status = 'pago' AND pago_em >= ?
        AND utm_campaign IN ('mat1', 'mat2') GROUP BY utm_source, utm_campaign");
    $stmt->execute([gmdate('Y-m-d H:i:s', $desde)]);
    foreach ($stmt->fetchAll() as $l) {
        $fonte = mb_strtolower((string) $l['utm_source']) === 'email' ? 'email' : 'whatsapp';
        $saida[$fonte][(string) $l['utm_campaign']] += (int) $l['n'];
    }
    return $saida;
}
