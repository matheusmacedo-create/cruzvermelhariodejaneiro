<?php
/**
 * Página da coordenação do voluntariado (06/11/2026, pedido do Matheus): um link só da coordenadora, sem senha e sem
 * login, para ver as fichas com a foto, ler tudo o que a pessoa preencheu e aprovar ou recusar. Feita para quem tem
 * pouca familiaridade com computador e celular: letra grande, botões grandes, poucas palavras, uma decisão por tela e
 * sempre uma confirmação antes de aprovar ou recusar.
 *
 * Acesso: o link leva a chave COORDENACAO_CHAVE (config-coordenacao.php, só no servidor, 32+ caracteres):
 *   https://cruzvermelhariodejaneiro.org/matricula-cursos-presenciais/api/coordenacao.php?k=<chave>
 * Sem a chave configurada, a página responde 404. Chave errada, 404 também, e cada IP tem até 20 erros por hora. Quem tem o
 * link entra: se ele vazar, troque a chave no config-coordenacao.php e mande o link novo (o antigo para na hora).
 * Opcional: COORDENACAO_NOME, o nome que fica registrado em quem aprovou ou recusou (padrão "Coordenação").
 *
 * Telas (todas com ?k=):
 *   (nada)            lista "Para decidir" (fichas nova, em análise e entrevista), da mais antiga para a mais nova;
 *   ?ver=fora         as que esperam decisão e são de quem mora fora da cidade do Rio (10/11/2026, pedido do Matheus:
 *                     separadas para a coordenadora recusar com um toque; saem de "Para decidir"; a recusa delas já
 *                     vem no motivo "Mora fora da cidade do Rio", com a mensagem pronta);
 *   ?ver=aprovados    as aceitas; ?ver=recusados as recusadas e arquivadas (mais novas primeiro);
 *   ?id=N             a ficha inteira, com a foto, o WhatsApp da pessoa e os botões Aprovar e Recusar;
 *   ?id=N&passo=aprovar|recusar   a confirmação (com o CPF, se a ficha veio sem);
 *   ?foto=N           a selfie (JPEG, sem cache).
 * Ações (POST com a chave): aprovar (aceita a ficha, cria o colaborador do ponto e o código de aprovação, como na Redação),
 * recusar, desfazer a recusa (volta para "em análise") e mandar e-mail à pessoa (pela Resend, com responder-para a caixa
 * do voluntariado; o texto vem pronto e pode ser mudado; cada envio fica nas anotações da ficha; até 40 por hora).
 * Recusar (06/11/2026): a coordenadora escolhe o motivo (lotação máxima, sem vaga na área, sem contato, outro), que vai
 * para a anotação da ficha; cada motivo traz uma mensagem pronta e editável, mandada por e-mail se "Avisar a pessoa" estiver
 * marcado (vem marcado), e depois da recusa há o botão para mandar a mesma mensagem pelo WhatsApp.
 * Tutorial (?ajuda=1): "Como usar esta página", em passos, com "Ouvir este passo" (o áudio pronto em
 * /assets/coordenacao/passo-N.mp3, gravado uma vez com a voz da ElevenLabs; sem o arquivo, a voz do próprio aparelho lê
 * o texto). O botão "Como usar" fica no topo de todas as telas.
 * Assistente de voz (06/11/2026): no tutorial, e só nele (a página não mostra dado de ninguém), o widget da ElevenLabs
 * (agente CO_ASSISTENTE, voz Sarah, instruções com o tutorial; só responde sobre o uso da página e não vê as fichas).
 * Modelos de mensagem (06/11/2026): "Conversar no WhatsApp" abre ?id=N&passo=whatsapp, com modelos prontos (combinar uma
 * conversa, marcar a entrevista na sede, pedir uma informação, pessoa não respondeu, ficha em análise e, conforme a ficha,
 * pedir a foto ou mandar o código); ela escolhe, muda o texto e o formulário abre o wa.me com ele. "Mandar e-mail" abre
 * ?id=N&passo=email, com os mesmos modelos (co_modelos()). As anotações continuam gravadas, mas não aparecem nesta página
 * (pedido do Matheus: confundiam); a equipe as vê no portal.
 * Fotos da lista (06/11/2026): a hospedagem atende ~20 pedidos ao PHP ao mesmo tempo e responde 503 ao resto, e a lista
 * pedia dezenas de fotos de uma vez (algumas apareciam quebradas). Agora a página busca as fotos 4 por vez, na ordem em
 * que aparecem na tela, e tenta de novo a que falhar (até 5 vezes, esperando mais a cada vez). Sem JavaScript, <noscript>.
 * Logo (06/11/2026): no topo, o logo horizontal do site (/assets/otim/logo-cvb-rj-480.png) em faixa branca; o logo quadrado
 * (/assets/coordenacao/logo-quadrado-N.png, a partir do arquivo oficial enviado pelo Matheus) é o ícone do atalho no
 * celular e da assistente; /assets/coordenacao/previa.jpg é a imagem da prévia do link no WhatsApp.
 * Ficha sem foto: botões "Pedir a foto pelo WhatsApp" e "Pedir a foto por e-mail", com o link só daquela ficha para a
 * pessoa mandar a selfie (api/voluntario-foto.php, lib/voluntario_foto.php). Nada é enviado sem a coordenadora tocar. Quem decidiu fica em status_por como "<nome> · link da coordenação".
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/voluntario_foto.php';

const CO_FALHAS = [20, 3600];
const CO_CHAVE_MIN = 32;
const CO_LIMITE_LISTA = 500;
const CO_PARA_DECIDIR = ['nova', 'em_analise', 'entrevista'];
const CO_RECUSADOS = ['recusada', 'arquivada'];
const CO_EMAIL_LIMITE = [40, 3600];
/** Agente de conversa da ElevenLabs que tira dúvidas por voz (conta da filial; só aceita o domínio do site). */
const CO_ASSISTENTE = 'agent_7801m6qjd5j4ervve1ypbw9zvdfb';
const CO_ASSISTENTE_JS = 'https://unpkg.com/@elevenlabs/convai-widget-embed@0.21.3';
const CO_EMAIL_MIN = 10;
const CO_EMAIL_MAX = 5000;
/** Motivos da recusa: rótulo do botão e explicação curta. A mensagem de cada um está em co_recusa_mensagem(). */
const CO_MOTIVOS = [
    'fora' => ['Mora fora da cidade do Rio', 'O voluntariado é presencial, na sede, e só para quem mora na capital.'],
    'lotacao' => ['Lotação máxima', 'As vagas de voluntário estão todas preenchidas agora.'],
    'area' => ['Sem vaga na área escolhida', 'Não há lugar nas frentes que a pessoa quer.'],
    'sem_contato' => ['Não conseguimos falar com a pessoa', 'Tentamos contato e ninguém respondeu.'],
    'outro' => ['Outro motivo', 'Escreva a mensagem do seu jeito.'],
];

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

// ----------------------------------------------------------------------------- acesso
function co_chave_configurada(): string
{
    $chave = (string) mcp_cfg('COORDENACAO_CHAVE', '');
    return strlen($chave) >= CO_CHAVE_MIN ? $chave : '';
}

function co_quem(): string
{
    $nome = mcp_texto((string) mcp_cfg('COORDENACAO_NOME', ''), 80);
    return ($nome !== '' ? $nome : 'Coordenação') . ' · link da coordenação';
}

function co_nao_encontrado(): never
{
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>Página não encontrada</title></head><body style="font:20px/1.5 system-ui,sans-serif;padding:32px;color:#111">'
        . '<h1 style="font-size:28px">Página não encontrada</h1><p>Confira se o link está completo.</p></body></html>';
    exit;
}

$chave = co_chave_configurada();
if ($chave === '') {
    co_nao_encontrado();
}
[$maximo, $janela] = CO_FALHAS;
if (mcp_contar_eventos_recentes('coordenacao_negado', mcp_ip_balde(), $janela) >= $maximo) {
    http_response_code(429);
    echo 'Muitas tentativas. Tente de novo mais tarde.';
    exit;
}
$recebida = (string) ($_POST['k'] ?? $_GET['k'] ?? '');
if ($recebida === '' || !hash_equals($chave, $recebida)) {
    mcp_registrar(null, 'coordenacao_negado', mcp_ip_balde());
    co_nao_encontrado();
}

// ----------------------------------------------------------------------------- html
function co_e(?string $s): string
{
    return mcp_escapar((string) $s);
}

/** Endereço desta página com a chave e os parâmetros dados. */
function co_url(array $q = []): string
{
    global $chave;
    return 'coordenacao.php?' . http_build_query(['k' => $chave] + $q);
}

function co_data(?string $utc, string $formato = 'd/m/Y'): string
{
    return mcp_data_brt($utc, $formato);
}

function co_pagina(string $titulo, string $corpo): never
{
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">'
        . '<meta name="theme-color" content="#c8102e">' . co_cabecalho_marca()
        . '<title>' . co_e($titulo) . '</title><style>' . co_css() . '</style></head><body>'
        . '<header class="topo"><div class="faixa">'
        . '<img class="logo" src="/assets/otim/logo-cvb-rj-480.png" width="480" height="144" alt="Cruz Vermelha Brasileira · Rio de Janeiro">'
        . '<span class="secao">Voluntariado</span>'
        . '<a class="ajuda" href="' . co_e(co_url(['ajuda' => 1])) . '">Como usar</a></div></header>'
        . '<main class="conteudo">' . $corpo . '</main>'
        . '<footer class="rodape">As fotos e os dados são só para escolher os voluntários. Não mande para outras pessoas.</footer>'
        . '</body></html>';
    exit;
}

/** Ícones (atalho na tela inicial do celular, aba do navegador) e a prévia do link no WhatsApp, com o logo da filial. */
function co_cabecalho_marca(): string
{
    $site = mcp_site_url();
    return '<link rel="icon" href="/favicon.ico" sizes="any">'
        . '<link rel="icon" type="image/png" sizes="192x192" href="/assets/coordenacao/logo-quadrado-192.png">'
        . '<link rel="apple-touch-icon" href="/assets/coordenacao/logo-quadrado-180.png">'
        . '<meta name="apple-mobile-web-app-title" content="Voluntariado"><meta name="application-name" content="Voluntariado">'
        . '<meta property="og:type" content="website"><meta property="og:site_name" content="Cruz Vermelha Brasileira Rio de Janeiro">'
        . '<meta property="og:title" content="Voluntariado · fichas de voluntários">'
        . '<meta property="og:description" content="Página da coordenação do voluntariado da Cruz Vermelha Brasileira Rio de Janeiro.">'
        . '<meta property="og:image" content="' . co_e($site) . '/assets/coordenacao/previa.jpg"><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">';
}

function co_css(): string
{
    return <<<'CSS'
:root{--vermelho:#c8102e;--vermelho-escuro:#9e0b23;--verde:#1b7a3a;--verde-escuro:#13602d;--texto:#111;--suave:#444;--linha:#cfcfcf;--fundo:#f4f4f2;--branco:#fff;--amarelo:#fff4cc}
*{box-sizing:border-box}
html{font-size:20px;-webkit-text-size-adjust:100%}
body{margin:0;background:var(--fundo);color:var(--texto);font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;line-height:1.5}
a{color:var(--vermelho-escuro)}
.topo{background:#fff;border-bottom:6px solid var(--vermelho)}
.faixa{max-width:1180px;margin:0 auto;padding:12px 16px;display:flex;gap:16px;align-items:center}
.logo{height:56px;width:auto;display:block;flex:none}
.secao{font-weight:800;font-size:1.1rem;color:var(--vermelho-escuro);padding-left:16px;border-left:2px solid var(--linha)}
.ajuda{margin-left:auto;flex:none;background:var(--vermelho);color:#fff;font-weight:800;text-decoration:none;border-radius:12px;padding:10px 16px;font-size:1rem}
@media (max-width:560px){.logo{height:42px}.secao{display:none}}
.passo{background:#fff;border:2px solid var(--linha);border-radius:18px;padding:18px 20px;margin:0 0 16px;display:grid;grid-template-columns:56px 1fr;gap:4px 16px}
.passo .n{grid-row:span 3;width:56px;height:56px;border-radius:50%;background:var(--vermelho);color:#fff;font-weight:900;font-size:1.5rem;display:flex;align-items:center;justify-content:center}
.passo h2{margin:6px 0 4px}.passo p{margin:0 0 8px}.passo ul{margin:0 0 8px;padding-left:22px}
.falar{background:#fff;border:3px solid var(--vermelho);border-radius:18px;padding:18px 20px;margin:0 0 22px}
.falar h2{margin:0 0 8px;font-size:1.35rem}.falar p{margin:0 0 10px}
.falar ol{margin:0 0 12px;padding-left:0;list-style:none;counter-reset:ch}
.falar ol li{counter-increment:ch;position:relative;padding:8px 0 8px 52px;min-height:44px}
.falar ol li::before{content:counter(ch);position:absolute;left:0;top:4px;width:38px;height:38px;border-radius:50%;background:#111;color:#fff;font-weight:900;display:flex;align-items:center;justify-content:center}
.falar ul{margin:0 0 12px;padding-left:22px}.falar ul li{margin:4px 0;font-style:italic}
.falar .animo{background:#fff5f6;border-radius:12px;padding:12px 16px;margin:0 0 12px;font-weight:600}
.ouvir{justify-self:start;display:inline-flex;align-items:center;gap:8px;min-height:52px;padding:8px 18px;border-radius:12px;border:2px solid var(--linha);background:#fff;font:inherit;font-weight:800;cursor:pointer}
.ouvir[aria-pressed="true"]{border-color:var(--vermelho);background:#fff5f6}
@media (max-width:560px){.passo{grid-template-columns:1fr}.passo .n{grid-row:auto}}
.conteudo{max-width:1180px;margin:0 auto;padding:20px 16px 40px}
h1{font-size:1.6rem;line-height:1.25;margin:6px 0 6px}
h2{font-size:1.25rem;margin:0 0 12px}
.dica{color:var(--suave);margin:0 0 18px}
.abas{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 22px}
.aba{display:inline-flex;align-items:center;gap:10px;min-height:60px;padding:10px 20px;border-radius:14px;border:3px solid var(--linha);background:#fff;color:var(--texto);text-decoration:none;font-weight:700;font-size:1.05rem}
.aba[aria-current="page"]{border-color:var(--vermelho);background:var(--vermelho);color:#fff}
.num{display:inline-block;min-width:2em;padding:2px 10px;border-radius:999px;background:var(--fundo);color:var(--texto);text-align:center;font-size:.95rem}
.aba[aria-current="page"] .num{background:#fff;color:var(--vermelho-escuro)}
.grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px}
.cartao{background:#fff;border:2px solid var(--linha);border-radius:18px;overflow:hidden;display:flex;flex-direction:column;color:var(--texto);text-decoration:none}
a.cartao:hover,a.cartao:focus-visible{border-color:var(--vermelho);outline:none;box-shadow:0 0 0 4px rgba(200,16,46,.25)}
.cartao .foto{aspect-ratio:1/1;background:#e7e7e7;display:flex;align-items:center;justify-content:center;color:var(--suave);font-size:.9rem;text-align:center}
.cartao .foto img{width:100%;height:100%;object-fit:cover;display:block}
.cartao .dados{padding:14px 16px 16px;display:flex;flex-direction:column;gap:4px;flex:1}
.cartao .nome{font-size:1.25rem;font-weight:800;line-height:1.25}
.cartao .linha{color:var(--suave);font-size:.95rem}
.cartao .abrir{margin-top:10px;display:block;text-align:center;background:var(--vermelho);color:#fff;font-weight:800;border-radius:12px;padding:14px}
.selo{display:inline-block;padding:3px 12px;border-radius:999px;font-size:.85rem;font-weight:800;border:2px solid transparent}
.selo.nova{background:#ffe1e5;color:#8a0a1f;border-color:#f2a5b1}
.selo.andamento{background:var(--amarelo);color:#5c4500;border-color:#e8cf7a}
.selo.ok{background:#dff3e5;color:#0f5426;border-color:#9fd3ae}
.selo.neutro{background:#ececec;color:#333;border-color:#c9c9c9}
.vazio{background:#fff;border:2px dashed var(--linha);border-radius:18px;padding:28px;text-align:center;font-size:1.1rem}
.voltar{display:inline-flex;align-items:center;gap:8px;min-height:56px;padding:10px 18px;border-radius:12px;background:#fff;border:2px solid var(--linha);color:var(--texto);text-decoration:none;font-weight:700;margin:0 0 16px}
.ficha{display:grid;grid-template-columns:minmax(260px,420px) 1fr;grid-template-areas:"foto cabeca" "foto corpo";gap:0 24px;align-items:start}
.ficha .retrato{grid-area:foto}.ficha .cabeca{grid-area:cabeca}.ficha .corpo{grid-area:corpo}
@media (max-width:820px){.ficha{grid-template-columns:1fr;grid-template-areas:"cabeca" "foto" "corpo"}.retrato{margin-bottom:18px}.retrato img{max-height:55vh;object-fit:cover}}
.retrato{background:#fff;border:2px solid var(--linha);border-radius:18px;padding:12px;position:sticky;top:12px;grid-row:span 2}
@media (max-width:820px){.retrato{position:static}}
.retrato img{width:100%;height:auto;border-radius:12px;display:block}
.retrato .semfoto{aspect-ratio:1/1;border-radius:12px;background:#e7e7e7;display:flex;align-items:center;justify-content:center;text-align:center;padding:20px;color:var(--suave)}
.retrato .ampliar{display:block;text-align:center;margin-top:8px;font-size:.9rem}
.bloco{background:#fff;border:2px solid var(--linha);border-radius:18px;padding:18px 20px;margin:0 0 18px}
.bloco dl{margin:0;display:grid;grid-template-columns:minmax(150px,240px) 1fr;gap:10px 18px}
@media (max-width:560px){.bloco dl{grid-template-columns:1fr;gap:2px}.bloco dd{margin-bottom:10px}}
.bloco dt{color:var(--suave);font-weight:600}
.bloco dd{margin:0;font-weight:700;overflow-wrap:anywhere}
.texto{white-space:pre-wrap;overflow-wrap:anywhere;margin:0 0 6px;font-size:1.02rem}
.rotulo{color:var(--suave);font-weight:700;margin:14px 0 4px}
.rotulo:first-of-type{margin-top:0}
.botoes{display:flex;flex-wrap:wrap;gap:12px;margin:12px 0 0}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;min-height:64px;padding:12px 26px;border-radius:14px;border:3px solid transparent;font:inherit;font-size:1.1rem;font-weight:800;text-decoration:none;cursor:pointer;text-align:center}
.btn.grande{min-height:76px;font-size:1.25rem;flex:1 1 240px}
.btn.verde{background:var(--verde);color:#fff}.btn.verde:hover{background:var(--verde-escuro)}
.btn.vermelho{background:#fff;color:var(--vermelho-escuro);border-color:var(--vermelho)}.btn.vermelho:hover{background:#ffeef0}
.btn.cheio{background:var(--vermelho);color:#fff}.btn.cheio:hover{background:var(--vermelho-escuro)}
.btn.claro{background:#fff;color:var(--texto);border-color:var(--linha)}
.btn.whats{background:#1f8f4e;color:#fff}
.btn:focus-visible,.aba:focus-visible,.voltar:focus-visible{outline:4px solid #1a5fb4;outline-offset:3px}
.decidir{border-color:var(--vermelho);border-width:3px}
.aviso{border-radius:16px;padding:16px 20px;margin:0 0 18px;font-size:1.05rem;font-weight:600}
.aviso.ok{background:#dff3e5;border:2px solid #9fd3ae;color:#0f4a22}
.aviso.erro{background:#ffe1e5;border:2px solid #f2a5b1;color:#7a0a1c}
.aviso.info{background:var(--amarelo);border:2px solid #e8cf7a;color:#4d3a00}
.confirmar{max-width:640px;margin:0 auto;text-align:center}
.confirmar img{width:220px;height:220px;object-fit:cover;border-radius:18px;border:2px solid var(--linha)}
.campo{display:block;text-align:left;margin:18px 0 0}
.campo span{display:block;font-weight:700;margin-bottom:6px}
.campo input{width:100%;min-height:64px;font:inherit;font-size:1.3rem;padding:10px 16px;border:3px solid var(--linha);border-radius:12px;letter-spacing:.04em}
.campo input:focus{border-color:#1a5fb4;outline:none}
.campo textarea{width:100%;min-height:220px;font:inherit;font-size:1.05rem;line-height:1.5;padding:12px 16px;border:3px solid var(--linha);border-radius:12px;resize:vertical}
.campo textarea:focus{border-color:#1a5fb4;outline:none}
.btn.azul{background:#1a5fb4;color:#fff}.btn.azul:hover{background:#144a8c}
.motivos{display:grid;gap:10px;margin:18px 0 0;text-align:left;border:0;padding:0}
.motivos legend{font-weight:800;font-size:1.1rem;margin-bottom:8px;padding:0}
.motivo{display:flex;gap:14px;align-items:flex-start;background:#fff;border:3px solid var(--linha);border-radius:14px;padding:14px 16px;cursor:pointer}
.motivo input{width:28px;height:28px;margin:2px 0 0;flex:none;accent-color:var(--vermelho)}
.motivo b{display:block;font-size:1.1rem}.motivo small{display:block;color:var(--suave);font-size:.9rem}
.motivo:has(input:checked){border-color:var(--vermelho);background:#fff5f6}
.marcar{display:flex;gap:12px;align-items:center;text-align:left;margin:16px 0 0;font-weight:700;font-size:1.05rem}
.marcar input{width:28px;height:28px;flex:none;accent-color:var(--vermelho)}
.codigo{font-size:2.2rem;font-weight:900;letter-spacing:.08em;margin:6px 0}
.rodape{max-width:1180px;margin:0 auto;padding:0 16px 30px;color:var(--suave);font-size:.85rem}
CSS;
}

function co_selo(string $status): string
{
    $classe = match ($status) {
        'nova' => 'nova',
        'em_analise', 'entrevista' => 'andamento',
        'aceita' => 'ok',
        default => 'neutro',
    };
    $texto = match ($status) {
        'nova' => 'Nova',
        'em_analise' => 'Em análise',
        'entrevista' => 'Entrevista',
        'aceita' => 'Aprovada',
        'recusada' => 'Recusada',
        'arquivada' => 'Arquivada',
        default => $status,
    };
    return '<span class="selo ' . $classe . '">' . co_e($texto) . '</span>';
}

/** Selo das fichas de quem mora fora da cidade do Rio (mcp_voluntario_fora_da_capital). */
function co_selo_fora(): string
{
    return '<span class="selo andamento">Mora fora do Rio</span>';
}

/** Ids das fichas que têm selfie (uma consulta para a lista inteira). */
function co_com_foto(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    try {
        $stmt = mcp_db()->prepare('SELECT ficha_id FROM mcp_voluntarios_selfies WHERE ficha_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    } catch (PDOException) {
        return [];
    }
}

/** Fichas de um grupo de situações, sem paginação (até CO_LIMITE_LISTA). */
function co_fichas(array $status, bool $antigasPrimeiro): array
{
    $marcas = implode(',', array_fill(0, count($status), '?'));
    $ordem = $antigasPrimeiro ? 'ASC' : 'DESC';
    $stmt = mcp_db()->prepare('SELECT id, protocolo, nome, data_nascimento, cidade, bairro, estado, frentes, status, status_em, criado_em'
        . " FROM mcp_voluntarios WHERE status IN ($marcas) ORDER BY id $ordem LIMIT " . CO_LIMITE_LISTA);
    $stmt->execute($status);
    return $stmt->fetchAll();
}

function co_contar(array $status): int
{
    $stmt = mcp_db()->prepare('SELECT COUNT(*) FROM mcp_voluntarios WHERE status IN (' . implode(',', array_fill(0, count($status), '?')) . ')');
    $stmt->execute($status);
    return (int) $stmt->fetchColumn();
}

function co_whatsapp(string $telefone, string $mensagem): string
{
    $d = mcp_digitos($telefone);
    return $d === '' ? '' : 'https://wa.me/55' . $d . '?text=' . rawurlencode($mensagem);
}

function co_aviso(string $texto, string $classe = 'ok'): string
{
    return $texto === '' ? '' : '<div class="aviso ' . co_e($classe) . '" role="status">' . $texto . '</div>';
}

// ----------------------------------------------------------------------------- e-mail à pessoa
/** O texto pronto do e-mail, conforme a situação da ficha (a coordenadora pode mudar antes de enviar). */
function co_email_modelo(array $f): string
{
    $ola = 'Olá, ' . mcp_primeiro_nome((string) $f['nome']) . "!\n\n";
    if ((string) $f['status'] === 'aceita') {
        $a = mcp_voluntario_aprovacao((int) $f['id']);
        if ($a && empty($a['cancelada_em'])) {
            return $ola . 'Sua ficha de voluntário foi aprovada. Seu código de aprovação é ' . mcp_codigo_formatado((string) $a['codigo']) . ".\n\n"
                . 'Qualquer pessoa pode conferir o código em ' . mcp_conferir_url((string) $a['codigo']) . ". Guarde este código: ele mostra que você foi aprovado(a) para seguir para o CBFI e o termo de adesão.\n\n"
                . 'Em breve entramos em contato com as próximas orientações.';
        }
    }
    return $ola . "Recebemos a sua ficha de voluntário na Cruz Vermelha Brasileira Rio de Janeiro e gostaríamos de conversar com você.\n\n"
        . 'Pode responder este e-mail dizendo o melhor dia e horário para falarmos? Se preferir, mande uma mensagem pelo WhatsApp.';
}

/** Monta o e-mail da coordenação para a pessoa da ficha. */
function co_email_montar(array $f, string $mensagem): array
{
    $protocolo = (string) ($f['protocolo'] ?? '');
    $assinatura = 'Coordenação do voluntariado · Cruz Vermelha Brasileira Rio de Janeiro';
    $corpo = '<div style="font-size:16px;line-height:1.6;color:#1a202c">' . nl2br(mcp_escapar($mensagem), false) . '</div>'
        . '<p style="margin:22px 0 0;font-size:15px;line-height:1.5;color:#1a202c"><strong>' . mcp_escapar($assinatura) . '</strong></p>'
        . mcp_nota('Para falar com a coordenação, é só responder este e-mail' . ($protocolo !== '' ? ' (protocolo da sua ficha: <strong>' . mcp_escapar($protocolo) . '</strong>)' : '') . '.');
    return [
        'assunto' => 'Voluntariado da Cruz Vermelha Brasileira Rio de Janeiro' . ($protocolo !== '' ? " · $protocolo" : ''),
        'html' => mcp_moldura('Mensagem da coordenação do voluntariado', $corpo, [
            'eyebrow' => 'Voluntariado',
            'preheader' => mb_substr(preg_replace('/\s+/u', ' ', $mensagem) ?? '', 0, 140),
            'motivo' => 'Você recebeu este e-mail porque preencheu a ficha de voluntariado em cruzvermelhariodejaneiro.org' . ($protocolo !== '' ? " (protocolo $protocolo)" : '') . '.',
        ]),
        'texto' => "$mensagem\n\n$assinatura\n\nPara falar com a coordenação, é só responder este e-mail" . ($protocolo !== '' ? " (protocolo $protocolo)" : '') . ".\n",
    ];
}

/**
 * Modelos de mensagem para a pessoa da ficha, os mesmos no WhatsApp e no e-mail: [chave => [rótulo, explicação, texto]].
 * Os que dependem da ficha (pedir a foto, código de aprovação) só entram quando fazem sentido.
 */
function co_modelos(array $f): array
{
    $ola = 'Olá, ' . mcp_primeiro_nome((string) $f['nome']) . "! Aqui é a coordenação do voluntariado da Cruz Vermelha Brasileira Rio de Janeiro.\n\n";
    $m = [];
    if ((string) $f['status'] === 'aceita') {
        $a = mcp_voluntario_aprovacao((int) $f['id']);
        if ($a && empty($a['cancelada_em'])) {
            $m['codigo'] = ['Mandar o código de aprovação', 'A pessoa foi aprovada: mande o código.', mcp_voluntario_aprovacao_mensagem($f, (string) $a['codigo'])];
        }
    }
    $m['conversar'] = ['Combinar uma conversa', 'Primeiro contato depois do cadastro.',
        $ola . 'Recebemos a sua ficha de voluntário e gostaríamos de conversar com você. Qual é o melhor dia e horário para falarmos?'];
    $m['entrevista'] = ['Marcar a entrevista na sede', 'Convida a pessoa para vir à sede. Complete o dia e a hora.',
        $ola . "Gostaríamos de conhecer você pessoalmente. Você pode vir à sede da Cruz Vermelha, na Praça da Cruz Vermelha, 10, no Centro do Rio, no dia ___, às ___?\n\nSe não puder, diga qual dia fica melhor para você."];
    $m['informacao'] = ['Pedir uma informação', 'Falta algum dado na ficha. Complete o que precisa.',
        $ola . 'Para completar a sua ficha de voluntário, precisamos de uma informação: ___. Pode responder esta mensagem?'];
    if (mcp_voluntario_selfie((int) $f['id']) === null) {
        $m['foto'] = ['Pedir a foto', 'A ficha está sem foto. Vai com o link para mandar a selfie.', mcp_vf_mensagem($f)];
    }
    $m['sem_resposta'] = ['A pessoa não respondeu', 'Para quem não deu retorno.',
        $ola . 'Tentamos falar com você sobre a sua ficha de voluntário, mas ainda não tivemos resposta. Você ainda tem interesse? Responda esta mensagem, por favor.'];
    $m['analise'] = ['Avisar que a ficha está em análise', 'Para tranquilizar quem está esperando.',
        $ola . 'Recebemos a sua ficha de voluntário. Estamos olhando todas as fichas com cuidado e, em breve, entramos em contato. Obrigado pela paciência!'];
    $m['livre'] = ['Escrever do meu jeito', 'Só a saudação. Você escreve o resto.', $ola];
    return $m;
}

/**
 * Escolha do modelo em botões grandes e a caixa com o texto, que troca com o modelo (a não ser que já tenha sido mudado à
 * mão). Os botões ficam fora do <form> de envio (vão com form="" vazio), para não irem junto no pedido.
 */
function co_seletor(array $modelos, string $idTexto, string $nomeTexto, string $escolhido, string $mensagem, string $rotulo, string $formId): string
{
    if (!isset($modelos[$escolhido])) {
        $escolhido = (string) array_key_first($modelos);
    }
    $opcoes = '';
    $textos = [];
    foreach ($modelos as $chaveModelo => [$titulo, $explica, $texto]) {
        $textos[$chaveModelo] = $texto;
        $opcoes .= '<label class="motivo"><input type="radio" name="modelo-' . co_e($idTexto) . '" form="" value="' . co_e((string) $chaveModelo) . '"' . ($chaveModelo === $escolhido ? ' checked' : '') . '>'
            . '<span><b>' . co_e($titulo) . '</b><small>' . co_e($explica) . '</small></span></label>';
    }
    $dados = json_encode($textos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<fieldset class="motivos"><legend>Escolha um modelo de mensagem</legend>' . $opcoes . '</fieldset>'
        . '<label class="campo"><span>' . co_e($rotulo) . '</span><textarea name="' . co_e($nomeTexto) . '" id="' . co_e($idTexto) . '" form="' . co_e($formId) . '" maxlength="' . CO_EMAIL_MAX . '" required>'
        . co_e($mensagem !== '' ? $mensagem : $textos[$escolhido]) . '</textarea></label>'
        . '<script>(function(){var m=' . $dados . ',t=document.getElementById(' . json_encode($idTexto) . '),r=document.querySelectorAll(' . json_encode('input[name="modelo-' . $idTexto . '"]') . ');'
        . 'for(var i=0;i<r.length;i++)r[i].addEventListener("change",function(e){var limpo=t.value.trim()==="";for(var k in m)if(t.value.trim()===m[k].trim())limpo=true;'
        . 'if(limpo){t.value=m[e.target.value];}else if(confirm("Trocar o texto que você escreveu pelo modelo?")){t.value=m[e.target.value];}});})();</script>';
}

/** Tela do WhatsApp: os modelos, o texto e "Abrir no WhatsApp" (formulário GET para o wa.me, sem JavaScript obrigatório). */
function co_tela_whatsapp(int $id): never
{
    $f = mcp_voluntario_por_id($id);
    if (!$f) {
        co_lista('decidir', co_aviso('Essa ficha não foi encontrada.', 'erro'));
    }
    $digitos = mcp_digitos((string) $f['telefone']);
    if ($digitos === '') {
        co_ficha($id, co_aviso('Esta ficha não tem telefone.', 'info'));
    }
    $modelos = co_modelos($f);
    $escolhido = isset($modelos['codigo']) ? 'codigo' : 'conversar';
    co_pagina('Mensagem pelo WhatsApp',
        '<a class="voltar" href="' . co_e(co_url(['id' => $id])) . '">← Voltar para a ficha</a>'
        . '<div class="confirmar" style="max-width:720px;text-align:left">'
        . '<h1>Mensagem pelo WhatsApp para ' . co_e((string) $f['nome']) . '</h1>'
        . '<p>Escolha um modelo. Se quiser, mude o texto. Depois toque em <b>Abrir no WhatsApp</b>: a mensagem aparece pronta lá, e você só toca em enviar.</p>'
        . co_seletor($modelos, 'co-whats', 'text', $escolhido, '', 'Mensagem (pode mudar o texto)', 'co-form-whats')
        . '<form id="co-form-whats" method="get" action="https://wa.me/55' . co_e($digitos) . '" target="_blank" rel="noopener">'
        . '<div class="botoes"><button class="btn grande whats" type="submit">Abrir no WhatsApp</button>'
        . '<a class="btn grande claro" href="' . co_e(co_url(['id' => $id])) . '">Voltar para a ficha</a></div></form>'
        . '<p class="dica" style="margin-top:12px">Para: ' . co_e(mcp_telefone_bonito((string) $f['telefone'])) . '</p></div>');
}

/** Tela "Mandar e-mail": os modelos, o texto e o botão de enviar (como a do WhatsApp). $erro e $mensagem voltam do envio. */
function co_tela_email(int $id, string $erro = '', string $mensagem = ''): never
{
    $f = mcp_voluntario_por_id($id);
    if (!$f) {
        co_lista('decidir', co_aviso('Essa ficha não foi encontrada.', 'erro'));
    }
    $email = (string) $f['email'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        co_ficha($id, co_aviso('Esta ficha não tem um e-mail válido.', 'info'));
    }
    $modelos = co_modelos($f);
    if (isset($modelos['codigo'])) {
        $modelos['codigo'][2] = co_email_modelo($f); // no e-mail, o código vai com o texto próprio (link de conferência por extenso)
    }
    co_pagina('Mensagem por e-mail',
        '<a class="voltar" href="' . co_e(co_url(['id' => $id])) . '">← Voltar para a ficha</a>'
        . '<div class="confirmar" style="max-width:720px;text-align:left">' . ($erro !== '' ? co_aviso(co_e($erro), 'erro') : '')
        . '<h1>Mensagem por e-mail para ' . co_e((string) $f['nome']) . '</h1>'
        . '<p>Para <b>' . co_e($email) . '</b>. Escolha um modelo. Se quiser, mude o texto. Depois toque em <b>Enviar e-mail</b>. A resposta da pessoa chega na caixa do voluntariado.</p>'
        . co_seletor($modelos, 'co-email', 'mensagem', isset($modelos['codigo']) ? 'codigo' : 'conversar', $mensagem, 'Mensagem (pode mudar o texto)', 'co-form-email')
        . '<form id="co-form-email" method="post" action="coordenacao.php"><input type="hidden" name="k" value="' . co_e($GLOBALS['chave']) . '">'
        . '<input type="hidden" name="acao" value="email"><input type="hidden" name="id" value="' . $id . '">'
        . '<div class="botoes"><button class="btn grande azul" type="submit">Enviar e-mail</button>'
        . '<a class="btn grande claro" href="' . co_e(co_url(['id' => $id])) . '">Voltar para a ficha</a></div></form></div>');
}

/** Envia o e-mail e anota na ficha. Devolve null (enviado) ou a mensagem de erro para a tela. */
function co_email_enviar(array $f, string $mensagem): ?string
{
    if (!filter_var((string) $f['email'], FILTER_VALIDATE_EMAIL)) {
        return 'Esta ficha não tem um e-mail válido.';
    }
    if (mb_strlen($mensagem) < CO_EMAIL_MIN) {
        return 'A mensagem está vazia ou curta demais. Escreva um pouco mais e envie de novo.';
    }
    [$maximo, $janela] = CO_EMAIL_LIMITE;
    if (mcp_contar_eventos_recentes('coordenacao_email', 'enviado', $janela) >= $maximo) {
        return 'Muitos e-mails enviados na última hora. Espere um pouco e tente de novo.';
    }
    $m = co_email_montar($f, $mensagem);
    $envio = mcp_enviar_email((string) $f['email'], $m['assunto'], $m['html'], $m['texto'], mcp_voluntarios_email_destino());
    if ($envio === 'falhou') {
        mcp_registrar(null, 'coordenacao_email', 'falhou');
        return 'O e-mail não pôde ser enviado agora. Tente de novo daqui a pouco.';
    }
    mcp_registrar(null, 'coordenacao_email', 'enviado');
    mcp_voluntario_nota_registrar((int) $f['id'], co_quem(), "E-mail enviado à pessoa:\n\n$mensagem");
    return null;
}

// ----------------------------------------------------------------------------- telas
/** Os passos do tutorial: título, texto (HTML simples) e o texto que a voz lê. */
function co_passos(): array
{
    return [
        ['Abrir a página', '<p>Use sempre o mesmo link. Salve nos favoritos do computador ou na tela inicial do celular, para abrir com um toque.</p><p>Não precisa de senha. Por isso, <b>não passe o link para outras pessoas</b>.</p>',
            'Para abrir a página, use sempre o mesmo link. Salve nos favoritos do computador, ou na tela inicial do celular, para abrir com um toque. Não precisa de senha. Por isso, não passe o link para outras pessoas.'],
        ['Ver as fichas', '<p>A página abre na lista de fichas. Em cima há três abas:</p><ul><li><b>Para decidir</b>: as fichas que esperam a sua decisão. As que chegaram primeiro aparecem primeiro.</li><li><b>Aprovados</b>: quem você já aprovou.</li><li><b>Recusados</b>: quem foi recusado.</li></ul><p>Cada ficha aparece num cartão, com a foto, o nome, a idade e o bairro.</p>',
            'A página abre na lista de fichas. Em cima há três abas. Para decidir: as fichas que esperam a sua decisão. As que chegaram primeiro aparecem primeiro. Aprovados: quem você já aprovou. Recusados: quem foi recusado. Cada ficha aparece num cartão, com a foto, o nome, a idade e o bairro.'],
        ['Abrir uma ficha', '<p>Toque na foto ou em <b>Ver a ficha completa</b>. Aparece tudo o que a pessoa preencheu: quem é, contato, como quer ajudar e, em <b>Nas palavras da pessoa</b>, por que quer ser voluntária.</p><p>Para voltar, toque em <b>Voltar para a lista</b>, no alto.</p>',
            'Para abrir uma ficha, toque na foto, ou em Ver a ficha completa. Aparece tudo o que a pessoa preencheu: quem é, contato, como quer ajudar, e por que quer ser voluntária. Para voltar, toque em Voltar para a lista, no alto da página.'],
        ['Falar com a pessoa', '<p>Logo abaixo do nome há três botões:</p><ul><li><b>Conversar no WhatsApp</b>: mostra modelos de mensagem prontos. Escolha um, mude o texto se quiser e toque em <b>Abrir no WhatsApp</b>.</li><li><b>Ligar</b>: liga para o telefone da ficha.</li><li><b>Mandar e-mail</b>: mostra os mesmos modelos. Escolha um, mude o texto se quiser e toque em <b>Enviar e-mail</b>. A resposta chega na caixa do voluntariado.</li></ul>',
            'Para falar com a pessoa, use os botões logo abaixo do nome. Conversar no WhatsApp mostra modelos de mensagem prontos. Escolha um, mude o texto se quiser, e toque em Abrir no WhatsApp. Ligar liga para o telefone da ficha. Mandar e-mail mostra os mesmos modelos. Escolha um, mude o texto se quiser, e toque em Enviar e-mail. A resposta chega na caixa do voluntariado.'],
        ['Ficha sem foto', '<p>As fichas mais antigas vieram sem foto. Se achar necessário, toque em <b>Pedir a foto pelo WhatsApp</b> ou <b>Pedir a foto por e-mail</b>.</p><p>A pessoa recebe um link só dela para mandar a selfie. Quando ela mandar, a foto aparece na ficha sozinha.</p>',
            'As fichas mais antigas vieram sem foto. Se achar necessário, toque em Pedir a foto pelo WhatsApp, ou Pedir a foto por e-mail. A pessoa recebe um link só dela para mandar a selfie. Quando ela mandar, a foto aparece na ficha sozinha.'],
        ['Aprovar', '<p>Depois de conversar com a pessoa, toque no botão verde <b>Aprovar</b>. A página pergunta se você tem certeza: toque em <b>Sim, aprovar</b>.</p><p>Se a ficha veio sem CPF, a página pede o CPF. Pergunte à pessoa e digite.</p><p>Aparece o <b>código de aprovação</b>. Mande para a pessoa: toque em <b>Mandar pelo WhatsApp</b> ou <b>Mandar por e-mail</b>. A mensagem já vai pronta.</p>',
            'Para aprovar, depois de conversar com a pessoa, toque no botão verde Aprovar. A página pergunta se você tem certeza. Toque em Sim, aprovar. Se a ficha veio sem CPF, a página pede o CPF. Pergunte à pessoa e digite. Depois aparece o código de aprovação. Mande para a pessoa: toque em Mandar pelo WhatsApp, ou Mandar por e-mail. A mensagem já vai pronta.'],
        ['Recusar', '<p>Toque em <b>Recusar</b>. Escolha o motivo: <b>Lotação máxima</b>, <b>Sem vaga na área escolhida</b>, <b>Não conseguimos falar com a pessoa</b> ou <b>Outro motivo</b>.</p><p>A mensagem para a pessoa já vem escrita para o motivo, e você pode mudar o texto. Com <b>Avisar a pessoa por e-mail</b> marcado, ela recebe a mensagem. Depois, se quiser, mande a mesma mensagem pelo WhatsApp.</p>',
            'Para recusar, toque em Recusar, e escolha o motivo: Lotação máxima, Sem vaga na área escolhida, Não conseguimos falar com a pessoa, ou Outro motivo. A mensagem para a pessoa já vem escrita para o motivo, e você pode mudar o texto. Com Avisar a pessoa por e-mail marcado, ela recebe a mensagem. Depois, se quiser, mande a mesma mensagem pelo WhatsApp.'],
        ['Se errar', '<p>Recusou por engano? Abra a aba <b>Recusados</b>, abra a ficha e toque em <b>Desfazer a recusa</b>. Ela volta para <b>Para decidir</b>.</p><p>Aprovou por engano? Não dá para desfazer por aqui. Avise o Matheus.</p><p>Tudo o que você faz fica registrado para a equipe.</p>',
            'Se errar: recusou por engano? Abra a aba Recusados, abra a ficha, e toque em Desfazer a recusa. Ela volta para Para decidir. Aprovou por engano? Não dá para desfazer por aqui. Avise o Matheus. Tudo o que você faz fica registrado para a equipe.'],
    ];
}

/** O quadro "Tire suas dúvidas conversando com a assistente": [HTML, texto que a voz lê]. */
function co_chamada(): array
{
    return [
        '<h2 id="falar-titulo">Tire suas dúvidas conversando com a assistente</h2>'
        . '<p>Ficou com alguma dúvida? Converse com a <b>assistente do voluntariado</b>. É como uma ligação: você pergunta falando, e ela responde com voz. '
        . 'Ela conhece esta página de ponta a ponta e tem toda a paciência do mundo.</p>'
        . '<p><b>Como fazer a chamada:</b></p><ol>'
        . '<li>No canto de baixo da tela, toque no botão preto <b>Iniciar uma chamada</b>.</li>'
        . '<li>Na primeira vez, o aparelho pergunta se pode usar o microfone. Toque em <b>Permitir</b>.</li>'
        . '<li>Espere a assistente dizer "Olá". Depois, faça a sua pergunta em voz alta, do seu jeito, como se falasse com uma pessoa.</li>'
        . '<li>Ela responde falando. Quando ela terminar, pergunte de novo, peça para repetir ou para explicar mais devagar.</li>'
        . '<li>Para terminar, toque no botão vermelho de desligar.</li></ol>'
        . '<p><b>Você pode perguntar, por exemplo:</b></p><ul>'
        . '<li>"Como eu aprovo uma pessoa?"</li><li>"Como mando o código pelo WhatsApp?"</li>'
        . '<li>"Recusei uma pessoa por engano. E agora?"</li><li>"A ficha está sem foto. O que eu faço?"</li></ul>'
        . '<p class="animo">Pode usar sem medo e quantas vezes quiser. Perguntar não muda nada nas fichas, e não existe pergunta boba. '
        . 'A assistente só explica: quem decide é sempre você. Se ela não souber responder, vai dizer para você falar com o Matheus.</p>',
        'Ficou com alguma dúvida? Converse com a assistente do voluntariado. É como uma ligação: você pergunta falando, e ela responde com voz. '
        . 'Ela conhece esta página de ponta a ponta, e tem toda a paciência do mundo. Para fazer a chamada: no canto de baixo da tela, toque no botão preto Iniciar uma chamada. '
        . 'Na primeira vez, o aparelho pergunta se pode usar o microfone. Toque em Permitir. Espere a assistente dizer olá. Depois, faça a sua pergunta em voz alta, do seu jeito, como se falasse com uma pessoa. '
        . 'Ela responde falando. Quando ela terminar, pergunte de novo, peça para repetir, ou para explicar mais devagar. Para terminar, toque no botão vermelho de desligar. '
        . 'Você pode perguntar, por exemplo: como eu aprovo uma pessoa? Ou: recusei uma pessoa por engano, e agora? '
        . 'Pode usar sem medo, e quantas vezes quiser. Perguntar não muda nada nas fichas, e não existe pergunta boba. A assistente só explica: quem decide é sempre você.',
    ];
}

function co_tutorial(): never
{
    $html = '';
    foreach (co_passos() as $i => [$titulo, $texto, $fala]) {
        $n = $i + 1;
        $html .= '<section class="passo" aria-labelledby="passo-' . $n . '"><span class="n" aria-hidden="true">' . $n . '</span>'
            . '<h2 id="passo-' . $n . '">' . co_e($titulo) . '</h2><div>' . $texto . '</div>'
            . '<button class="ouvir" type="button" data-n="' . $n . '" data-audio="passo-' . $n . '" data-fala="' . co_e($fala) . '" aria-pressed="false">🔊 Ouvir este passo</button></section>';
    }
    // Toca o áudio gravado (/assets/coordenacao/passo-N.mp3); sem ele, a voz do aparelho lê o texto. Um passo por vez.
    $js = '<script>(function(){var tocando=null,botao=null;function parar(){if(tocando){tocando.pause();tocando=null;}if(window.speechSynthesis)speechSynthesis.cancel();'
        . 'if(botao){botao.setAttribute("aria-pressed","false");botao.textContent=botao.getAttribute("data-rotulo")||"🔊 Ouvir este passo";botao=null;}}'
        . 'function falar(b){if(!window.speechSynthesis){return;}var u=new SpeechSynthesisUtterance(b.getAttribute("data-fala"));u.lang="pt-BR";u.rate=.9;u.onend=parar;speechSynthesis.speak(u);}'
        . 'document.querySelectorAll(".ouvir").forEach(function(b){b.addEventListener("click",function(){var era=botao===b;parar();if(era)return;botao=b;b.setAttribute("aria-pressed","true");b.textContent="⏹ Parar";'
        . 'var a=new Audio("/assets/coordenacao/"+b.getAttribute("data-audio")+".mp3");tocando=a;a.onended=parar;a.onerror=function(){if(tocando===a){tocando=null;falar(b);}};'
        . 'a.play().catch(function(){if(tocando===a){tocando=null;falar(b);}});});});})();</script>';
    co_pagina('Como usar a página do voluntariado',
        '<a class="voltar" href="' . co_e(co_url()) . '">← Voltar para as fichas</a>'
        . '<h1>Como usar esta página</h1><p class="dica">Leia ou toque em <b>Ouvir este passo</b> para escutar. São ' . count(co_passos()) . ' passos.</p>'
        . '<section class="falar" aria-labelledby="falar-titulo">' . co_chamada()[0]
        . '<button class="ouvir" type="button" data-audio="chamada" data-rotulo="🔊 Ouvir como funciona a chamada" data-fala="' . co_e(co_chamada()[1]) . '" aria-pressed="false">🔊 Ouvir como funciona a chamada</button></section>'
        . $html
        . '<elevenlabs-convai agent-id="' . co_e(CO_ASSISTENTE) . '"></elevenlabs-convai>'
        . '<script src="' . co_e(CO_ASSISTENTE_JS) . '" async type="text/javascript"></script>' . '<a class="btn grande cheio" href="' . co_e(co_url()) . '">Entendi, ver as fichas</a>' . $js);
}

function co_lista(string $ver, string $aviso = ''): never
{
    $abas = [
        'decidir' => ['Para decidir', CO_PARA_DECIDIR, true],
        'fora' => ['De fora do Rio', CO_PARA_DECIDIR, true],
        'aprovados' => ['Aprovados', ['aceita'], false],
        'recusados' => ['Recusados', CO_RECUSADOS, false],
    ];
    if (!isset($abas[$ver])) {
        $ver = 'decidir';
    }
    [$titulo, $status, $antigas] = $abas[$ver];
    // As fichas que esperam decisão se dividem em duas abas: quem mora na cidade do Rio e quem mora fora.
    $esperando = co_fichas(CO_PARA_DECIDIR, true);
    $deFora = array_values(array_filter($esperando, 'mcp_voluntario_fora_da_capital'));
    $doRio = array_values(array_filter($esperando, static fn(array $f): bool => !mcp_voluntario_fora_da_capital($f)));
    $menu = '';
    foreach ($abas as $chaveAba => [$rotulo, $sts]) {
        $atual = $chaveAba === $ver ? ' aria-current="page"' : '';
        $quantas = match ($chaveAba) {
            'decidir' => count($doRio),
            'fora' => count($deFora),
            default => co_contar($sts),
        };
        $menu .= '<a class="aba" href="' . co_e(co_url($chaveAba === 'decidir' ? [] : ['ver' => $chaveAba])) . '"' . $atual . '>'
            . co_e($rotulo) . ' <span class="num">' . $quantas . '</span></a>';
    }
    $fichas = match ($ver) {
        'decidir' => $doRio,
        'fora' => $deFora,
        default => co_fichas($status, $antigas),
    };
    $fotos = co_com_foto(array_column($fichas, 'id'));
    $cartoes = '';
    foreach ($fichas as $f) {
        $id = (int) $f['id'];
        $idade = mcp_voluntario_idade_texto($f);
        $foto = isset($fotos[$id])
            ? '<img data-foto="' . co_e(co_url(['foto' => $id])) . '" alt="Foto de ' . co_e((string) $f['nome']) . '" decoding="async">'
                . '<noscript><img src="' . co_e(co_url(['foto' => $id])) . '" alt="Foto de ' . co_e((string) $f['nome']) . '" loading="lazy"></noscript>'
            : '<span>Sem foto</span>';
        $cartoes .= '<a class="cartao" href="' . co_e(co_url(['id' => $id])) . '">'
            . '<div class="foto">' . $foto . '</div><div class="dados">'
            . '<div>' . co_selo((string) $f['status']) . (mcp_voluntario_fora_da_capital($f) ? ' ' . co_selo_fora() : '') . '</div>'
            . '<div class="nome">' . co_e((string) $f['nome']) . '</div>'
            . '<div class="linha">' . co_e(trim($idade . ($idade !== '' ? ' · ' : '') . mcp_voluntario_local_texto($f))) . '</div>'
            . '<div class="linha">Chegou em ' . co_e(co_data((string) $f['criado_em'])) . '</div>'
            . '<span class="abrir">Ver a ficha completa</span></div></a>';
    }
    $dica = match ($ver) {
        'decidir' => 'Toque numa foto para ver a ficha completa e decidir. As que chegaram primeiro aparecem primeiro.',
        'fora' => 'Fichas de quem mora fora da cidade do Rio de Janeiro. O voluntariado é só para quem mora na capital: abra a ficha e toque em "Recusar". A mensagem para a pessoa já vem pronta.',
        'aprovados' => 'Pessoas que você já aprovou. Toque para ver a ficha e mandar o código de aprovação pelo WhatsApp.',
        default => 'Fichas recusadas ou arquivadas. Se recusou por engano, abra a ficha e toque em "Desfazer a recusa".',
    };
    $vazio = match ($ver) {
        'decidir' => 'Nenhuma ficha esperando decisão. Tudo em dia!',
        'fora' => 'Nenhuma ficha de fora do Rio esperando decisão.',
        'aprovados' => 'Ainda não há ninguém aprovado.',
        default => 'Nenhuma ficha recusada.',
    };
    co_pagina('Fichas de voluntários · ' . $titulo,
        $aviso . '<h1>Fichas de quem quer ser voluntário</h1><p class="dica">' . co_e($dica) . '</p>'
        . '<nav class="abas" aria-label="Grupos de fichas">' . $menu . '</nav>'
        . ($cartoes !== '' ? '<div class="grade">' . $cartoes . '</div>' . co_js_fotos() : '<div class="vazio">' . co_e($vazio) . '</div>'));
}

/**
 * Carrega as fotos da lista em fila: só as que estão (ou vão estar) na tela, no máximo 4 ao mesmo tempo, e tenta de novo a
 * que falhar (503 da hospedagem cheia), esperando 1, 2, 4, 8 e 16 segundos. Depois da 5ª falha, mostra "Toque para
 * carregar a foto" (tocar recomeça).
 */
function co_js_fotos(): string
{
    return '<script>(function(){var MAX=4,ativas=0,fila=[];'
        . 'function proxima(){while(ativas<MAX&&fila.length){carregar(fila.shift());}}'
        . 'function carregar(img){ativas++;var url=img.getAttribute("data-foto"),n=+(img.getAttribute("data-tentativa")||0);'
        . 'var t=new Image();t.onload=function(){img.src=t.src;img.removeAttribute("data-foto");ativas--;proxima();};'
        . 't.onerror=function(){ativas--;n++;img.setAttribute("data-tentativa",n);'
        . 'if(n>=5){img.alt="Toque para carregar a foto";img.parentNode.setAttribute("data-falhou","1");proxima();return;}'
        . 'setTimeout(function(){fila.unshift(img);proxima();},1000*Math.pow(2,n-1));proxima();};'
        . 't.src=url+(n?"&t="+n:"");}'
        . 'var imgs=[].slice.call(document.querySelectorAll("img[data-foto]"));'
        . 'function pedir(img){if(img.getAttribute("data-na-fila"))return;img.setAttribute("data-na-fila","1");fila.push(img);proxima();}'
        . 'if("IntersectionObserver" in window){var o=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){o.unobserve(e.target);pedir(e.target);}});},{rootMargin:"600px 0px"});'
        . 'imgs.forEach(function(i){o.observe(i);});}else{imgs.forEach(pedir);}'
        . 'document.addEventListener("click",function(e){var c=e.target.closest&&e.target.closest("[data-falhou]");if(!c)return;var i=c.querySelector("img[data-foto]");if(!i)return;'
        . 'e.preventDefault();c.removeAttribute("data-falhou");i.setAttribute("data-tentativa","0");i.alt="Carregando a foto";fila.unshift(i);proxima();},true);'
        . '})();</script>';
}

function co_dl(array $linhas): string
{
    $html = '';
    foreach ($linhas as $rotulo => $valor) {
        $html .= '<dt>' . co_e((string) $rotulo) . '</dt><dd>' . $valor . '</dd>';
    }
    return '<dl>' . $html . '</dl>';
}

/** Próxima ficha "para decidir" depois desta (na ordem da lista), ou null. */
function co_proxima(int $depoisDe, bool $fora = false): ?int
{
    $primeira = null;
    foreach (co_fichas(CO_PARA_DECIDIR, true) as $f) {
        $id = (int) $f['id'];
        if ($id === $depoisDe || mcp_voluntario_fora_da_capital($f) !== $fora) {
            continue;
        }
        if ($id > $depoisDe) {
            return $id;
        }
        $primeira ??= $id;
    }
    return $primeira;
}

function co_retrato(array $f, bool $temFoto): string
{
    $id = (int) $f['id'];
    return $temFoto
        ? '<img src="' . co_e(co_url(['foto' => $id])) . '" alt="Foto de ' . co_e((string) $f['nome']) . '">'
            . '<a class="ampliar" href="' . co_e(co_url(['foto' => $id])) . '" target="_blank" rel="noopener">Abrir a foto em tamanho grande</a>'
        : '<div class="semfoto">Esta ficha não tem foto. As fichas de antes de 27/10/2026 não pediam foto.</div>' . co_pedir_foto($f);
}

/** Botões para pedir a foto (só nas fichas sem foto): WhatsApp com a mensagem pronta e e-mail com um toque. */
function co_pedir_foto(array $f): string
{
    $mensagem = mcp_vf_mensagem($f);
    $whats = co_whatsapp((string) $f['telefone'], $mensagem);
    $botoes = ($whats !== '' ? '<a class="btn whats" style="width:100%" href="' . co_e($whats) . '" target="_blank" rel="noopener">Pedir a foto pelo WhatsApp</a>' : '')
        . (filter_var((string) $f['email'], FILTER_VALIDATE_EMAIL)
            ? '<form method="post" action="coordenacao.php" style="width:100%"><input type="hidden" name="k" value="' . co_e($GLOBALS['chave']) . '">'
                . '<input type="hidden" name="acao" value="email"><input type="hidden" name="id" value="' . (int) $f['id'] . '">'
                . '<input type="hidden" name="mensagem" value="' . co_e($mensagem) . '">'
                . '<button class="btn azul" style="width:100%" type="submit">Pedir a foto por e-mail</button></form>'
            : '');
    return $botoes === '' ? '' : '<p style="margin:12px 0 0;font-size:.95rem">Se achar necessário, peça a foto. A pessoa recebe um link só dela para mandar a selfie, e a foto aparece aqui sozinha.</p>'
        . '<div class="botoes" style="flex-direction:column">' . $botoes . '</div>';
}

function co_bloco_aprovacao(array $f): string
{
    $a = mcp_voluntario_aprovacao((int) $f['id']);
    if (!$a || !empty($a['cancelada_em'])) {
        return '<div class="bloco"><h2>Aprovada</h2><p style="margin:0">Esta ficha foi aprovada em ' . co_e(co_data((string) $f['status_em'])) . '.</p></div>';
    }
    $codigo = (string) $a['codigo'];
    $whats = co_whatsapp((string) $f['telefone'], mcp_voluntario_aprovacao_mensagem($f, $codigo));
    return '<div class="bloco"><h2>Aprovada</h2>'
        . '<p style="margin:0">Código de aprovação:</p><p class="codigo">' . co_e(mcp_codigo_formatado($codigo)) . '</p>'
        . '<p style="margin:0 0 4px">Mande o código para a pessoa pelo WhatsApp. A mensagem já vai pronta: é só tocar em enviar.</p>'
        . '<div class="botoes">' . ($whats !== '' ? '<a class="btn grande whats" href="' . co_e($whats) . '" target="_blank" rel="noopener">Mandar o código pelo WhatsApp</a>' : '<p>Esta ficha não tem telefone.</p>')
        . (filter_var((string) $f['email'], FILTER_VALIDATE_EMAIL) ? '<a class="btn grande azul" href="' . co_e(co_url(['id' => (int) $f['id'], 'passo' => 'email'])) . '">Mandar o código por e-mail</a>' : '') . '</div></div>';
}

function co_ficha(int $id, string $aviso = ''): never
{
    $f = mcp_voluntario_por_id($id);
    if (!$f) {
        co_lista('decidir', co_aviso('Essa ficha não foi encontrada.', 'erro'));
    }
    $status = (string) $f['status'];
    $temFoto = mcp_voluntario_selfie($id) !== null;
    $nome = (string) $f['nome'];
    $telefone = (string) $f['telefone'];
    $idade = mcp_voluntario_idade((string) $f['data_nascimento']);
    $saudacao = 'Olá, ' . mcp_primeiro_nome($nome) . '! Aqui é a coordenação do voluntariado da Cruz Vermelha Brasileira Rio de Janeiro. Recebemos a sua ficha de voluntário.';
    $whats = co_whatsapp($telefone, $saudacao);
    $contatoBotoes = ($whats !== '' ? '<a class="btn whats" href="' . co_e(co_url(['id' => $id, 'passo' => 'whatsapp'])) . '">Conversar no WhatsApp</a>' : '')
        . ($telefone !== '' ? '<a class="btn claro" href="tel:+55' . co_e(mcp_digitos($telefone)) . '">Ligar</a>' : '')
        . (filter_var((string) $f['email'], FILTER_VALIDATE_EMAIL) ? '<a class="btn claro" href="' . co_e(co_url(['id' => $id, 'passo' => 'email'])) . '">Mandar e-mail</a>' : '');

    $saude = (int) ($f['saude'] ?? 0) === 1 ? 'Sim' . (!empty($f['saude_qual']) ? ': ' . (string) $f['saude_qual'] : '') : 'Não';
    $quem = co_dl([
        'Nome' => co_e($nome),
        'Idade' => co_e(($idade !== null ? $idade . ' anos' : '') . ' (nasceu em ' . mcp_voluntario_nascimento_texto($f) . ')')
            . (mcp_voluntario_menor($f) ? ' <span class="selo andamento">menor de 18</span>' : ''),
        'Onde mora' => co_e(mcp_voluntario_local_texto($f)) . (mcp_voluntario_fora_da_capital($f) ? ' ' . co_selo_fora() : ''),
        'Profissão' => co_e((string) ($f['profissao'] ?: 'não informada')),
        'Escolaridade' => co_e(MCP_VOL_ESCOLARIDADES[$f['escolaridade']] ?? (string) $f['escolaridade']),
        'Formação na saúde' => co_e($saude),
        'CPF' => co_e(mcp_voluntario_cpf_parcial($f['cpf'] ?? null) ?: 'não informado'),
    ]);
    $contato = co_dl([
        'Telefone' => $telefone !== '' ? co_e(mcp_telefone_bonito($telefone)) : 'não informado',
        'E-mail' => '<a href="mailto:' . co_e((string) $f['email']) . '">' . co_e((string) $f['email']) . '</a>',
        'Aceita contato' => (int) ($f['aceita_contato'] ?? 0) === 1 ? 'Sim, por e-mail e telefone' : 'Não marcou',
    ]);
    $ajudar = co_dl([
        'Quer ajudar em' => co_e(mcp_voluntario_rotulos((string) $f['frentes'], MCP_VOL_FRENTES)),
        'Dias' => co_e(mcp_voluntario_rotulos((string) $f['dias'], MCP_VOL_DIAS)),
        'Horários' => co_e(mcp_voluntario_rotulos((string) $f['periodos'], MCP_VOL_PERIODOS)),
        'Como conheceu' => co_e(!empty($f['como_conheceu']) ? (MCP_VOL_COMO_CONHECEU[$f['como_conheceu']] ?? (string) $f['como_conheceu']) : 'não informado'),
    ]);
    $palavras = '<p class="rotulo">Por que quer ser voluntário</p><p class="texto">' . co_e((string) $f['motivacao']) . '</p>'
        . (!empty($f['experiencia']) ? '<p class="rotulo">Experiência com voluntariado</p><p class="texto">' . co_e((string) $f['experiencia']) . '</p>' : '')
        . (!empty($f['habilidades']) ? '<p class="rotulo">Habilidades e formação</p><p class="texto">' . co_e((string) $f['habilidades']) . '</p>' : '');
    if (in_array($status, CO_PARA_DECIDIR, true)) {
        $decisao = '<div class="bloco decidir"><h2>Sua decisão</h2>'
            . '<p style="margin:0">Depois de conversar com a pessoa, escolha uma opção. Antes de valer, a página pergunta se você tem certeza.</p>'
            . '<div class="botoes"><a class="btn grande verde" href="' . co_e(co_url(['id' => $id, 'passo' => 'aprovar'])) . '">Aprovar</a>'
            . '<a class="btn grande vermelho" href="' . co_e(co_url(['id' => $id, 'passo' => 'recusar'])) . '">Recusar</a></div></div>';
    } elseif ($status === 'aceita') {
        $decisao = co_bloco_aprovacao($f);
    } else {
        $decisao = '<div class="bloco"><h2>' . ($status === 'recusada' ? 'Recusada' : 'Arquivada') . '</h2>'
            . '<p style="margin:0">' . co_e('Em ' . co_data((string) $f['status_em']) . (!empty($f['status_por']) ? ', por ' . $f['status_por'] : '') . '.') . ' Se foi engano, a ficha volta para "Para decidir".</p>'
            . '<form method="post" action="coordenacao.php" class="botoes"><input type="hidden" name="k" value="' . co_e($GLOBALS['chave']) . '">'
            . '<input type="hidden" name="acao" value="desfazer"><input type="hidden" name="id" value="' . $id . '">'
            . '<button class="btn claro" type="submit">Desfazer a recusa</button></form></div>';
    }
    $voltar = match (true) {
        $status === 'aceita' => co_url(['ver' => 'aprovados']),
        in_array($status, CO_RECUSADOS, true) => co_url(['ver' => 'recusados']),
        mcp_voluntario_fora_da_capital($f) => co_url(['ver' => 'fora']),
        default => co_url(),
    };
    co_pagina($nome . ' · ficha de voluntário',
        '<a class="voltar" href="' . co_e($voltar) . '">← Voltar para a lista</a>' . $aviso
        . '<div class="ficha"><div class="retrato">' . co_retrato($f, $temFoto) . '</div><div class="cabeca">'
        . '<p style="margin:0">' . co_selo($status) . ' <span class="dica">Chegou em ' . co_e(co_data((string) $f['criado_em'], 'd/m/Y \à\s H\hi')) . ' · ' . co_e((string) $f['protocolo']) . '</span></p>'
        . '<h1>' . co_e($nome) . '</h1>'
        . ($contatoBotoes !== '' ? '<div class="botoes" style="margin:6px 0 18px">' . $contatoBotoes . '</div>' : '')
        . '</div><div class="corpo">' . $decisao
        . '<div class="bloco"><h2>Quem é</h2>' . $quem . '</div>'
        . '<div class="bloco"><h2>Contato</h2>' . $contato . '</div>'
        . '<div class="bloco"><h2>Como quer ajudar</h2>' . $ajudar . '</div>'
        . '<div class="bloco"><h2>Nas palavras da pessoa</h2>' . $palavras . '</div>'
        . '</div></div>');
}

/** A mensagem pronta da recusa para cada motivo (a coordenadora pode mudar antes de mandar). */
function co_recusa_mensagem(array $f, string $motivo): string
{
    $ola = 'Olá, ' . mcp_primeiro_nome((string) $f['nome']) . "!\n\n"
        . "Agradecemos muito o seu interesse em ser voluntário(a) da Cruz Vermelha Brasileira Rio de Janeiro.\n\n";
    $fim = "\n\nObrigado pela vontade de ajudar!";
    return $ola . match ($motivo) {
        'fora' => 'O voluntariado desta filial é presencial: é preciso ir 24 vezes à sede, no Centro do Rio de Janeiro, em 6 meses. '
            . 'Por isso, ele é só para quem mora na cidade do Rio de Janeiro, e não vamos conseguir seguir com a sua ficha. '
            . 'Sugerimos procurar a Cruz Vermelha da sua cidade ou da sua região.',
        'lotacao' => 'Neste momento, as vagas de voluntário chegaram à lotação máxima, e não conseguimos incluir novas pessoas. '
            . 'Quando abrirmos novas vagas, você pode fazer um novo cadastro pelo nosso site.',
        'area' => 'Neste momento, não temos vagas nas áreas que você escolheu na ficha. '
            . 'Quando abrirmos novas vagas, você pode fazer um novo cadastro pelo nosso site, escolhendo as áreas que quiser.',
        'sem_contato' => 'Tentamos falar com você pelo telefone informado na ficha, mas não conseguimos. Por isso, encerramos a sua ficha por enquanto. '
            . 'Se ainda tiver interesse, faça um novo cadastro pelo nosso site.',
        default => 'Neste momento, não vamos conseguir seguir com a sua ficha de voluntário.',
    } . $fim;
}

/** O formulário da recusa: motivo em botões grandes, a mensagem pronta (troca com o motivo) e "Avisar a pessoa por e-mail". */
function co_recusa_campos(array $f, string $motivo = 'lotacao', string $mensagem = '', bool $avisar = true): string
{
    $motivo = isset(CO_MOTIVOS[$motivo]) ? $motivo : 'lotacao';
    $modelos = [];
    $opcoes = '';
    foreach (CO_MOTIVOS as $chaveMotivo => [$rotulo, $explica]) {
        $modelos[$chaveMotivo] = co_recusa_mensagem($f, $chaveMotivo);
        $opcoes .= '<label class="motivo"><input type="radio" name="motivo" value="' . co_e($chaveMotivo) . '"' . ($chaveMotivo === $motivo ? ' checked' : '') . ' required>'
            . '<span><b>' . co_e($rotulo) . '</b><small>' . co_e($explica) . '</small></span></label>';
    }
    $temEmail = filter_var((string) $f['email'], FILTER_VALIDATE_EMAIL) !== false;
    $dados = json_encode($modelos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<fieldset class="motivos"><legend>Por que recusar?</legend>' . $opcoes . '</fieldset>'
        . '<label class="campo"><span>Mensagem para a pessoa (pode mudar o texto)</span>'
        . '<textarea name="mensagem" id="co-mensagem" maxlength="' . CO_EMAIL_MAX . '">' . co_e($mensagem !== '' ? $mensagem : $modelos[$motivo]) . '</textarea></label>'
        . ($temEmail
            ? '<label class="marcar"><input type="checkbox" name="avisar" value="1"' . ($avisar ? ' checked' : '') . '>Avisar a pessoa por e-mail (' . co_e((string) $f['email']) . ')</label>'
            : '<p class="dica" style="text-align:left">Esta ficha não tem e-mail. Depois de recusar, você pode mandar a mensagem pelo WhatsApp.</p>')
        // Trocar o motivo troca a mensagem, a não ser que ela já tenha sido mudada à mão.
        . '<script>(function(){var m=' . $dados . ',t=document.getElementById("co-mensagem"),r=document.querySelectorAll("input[name=motivo]");'
        . 'for(var i=0;i<r.length;i++)r[i].addEventListener("change",function(e){var limpo=false;for(var k in m)if(t.value.trim()===m[k].trim())limpo=true;'
        . 'if(limpo||t.value.trim()==="")t.value=m[e.target.value];});})();</script>';
}

function co_confirmar(int $id, string $passo, string $erro = '', string $cpfDigitado = '', array $recusa = []): never
{
    $f = mcp_voluntario_por_id($id);
    if (!$f) {
        co_lista('decidir', co_aviso('Essa ficha não foi encontrada.', 'erro'));
    }
    if (!in_array((string) $f['status'], CO_PARA_DECIDIR, true)) {
        co_ficha($id, co_aviso('Essa ficha já foi decidida.', 'info'));
    }
    $nome = (string) $f['nome'];
    $temFoto = mcp_voluntario_selfie($id) !== null;
    $foto = $temFoto ? '<img src="' . co_e(co_url(['foto' => $id])) . '" alt="Foto de ' . co_e($nome) . '">' : '';
    $aprovar = $passo === 'aprovar';
    $precisaCpf = $aprovar && !mcp_cpf_valido(mcp_digitos((string) ($f['cpf'] ?? '')));
    $campoCpf = $precisaCpf
        ? '<label class="campo"><span>Esta ficha veio sem CPF. Digite o CPF da pessoa (pergunte a ela pelo WhatsApp):</span>'
            . '<input name="cpf" inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" value="' . co_e($cpfDigitado) . '" required></label>'
        : '';
    $pergunta = $aprovar ? 'Aprovar ' . $nome . '?' : 'Recusar ' . $nome . '?';
    $explica = $aprovar
        ? 'Ao aprovar, a pessoa ganha um código de aprovação, e você poderá mandar esse código pelo WhatsApp.'
        : 'Escolha o motivo. A mensagem abaixo já vem escrita para ele. Se precisar, você pode desfazer a recusa depois.';
    $camposRecusa = $aprovar ? '' : co_recusa_campos($f, (string) ($recusa['motivo'] ?? (mcp_voluntario_fora_da_capital($f) ? 'fora' : 'lotacao')), (string) ($recusa['mensagem'] ?? ''), (bool) ($recusa['avisar'] ?? true));
    co_pagina($pergunta,
        '<div class="confirmar">' . ($erro !== '' ? co_aviso(co_e($erro), 'erro') : '') . $foto
        . '<h1 style="margin-top:14px">' . co_e($pergunta) . '</h1><p>' . co_e($explica) . '</p>'
        . '<form method="post" action="coordenacao.php"><input type="hidden" name="k" value="' . co_e($GLOBALS['chave']) . '">'
        . '<input type="hidden" name="acao" value="' . ($aprovar ? 'aprovar' : 'recusar') . '"><input type="hidden" name="id" value="' . $id . '">'
        . $campoCpf . $camposRecusa
        . '<div class="botoes" style="justify-content:center;margin-top:22px">'
        . '<button class="btn grande ' . ($aprovar ? 'verde' : 'cheio') . '" type="submit">' . ($aprovar ? 'Sim, aprovar' : 'Sim, recusar') . '</button>'
        . '<a class="btn grande claro" href="' . co_e(co_url(['id' => $id])) . '">Não, voltar</a></div></form></div>');
}

/** Depois de recusar: o resultado do e-mail, o WhatsApp com a mesma mensagem e a próxima ficha. */
function co_recusada(array $f, string $mensagem, ?string $email): never
{
    $id = (int) $f['id'];
    $whats = $mensagem !== '' ? co_whatsapp((string) $f['telefone'], $mensagem) : '';
    $proxima = co_proxima($id, mcp_voluntario_fora_da_capital($f));
    $avisoEmail = match (true) {
        $email === null => '',
        $email === 'enviado' => co_aviso('A mensagem foi enviada por e-mail para ' . co_e((string) $f['email']) . '.', 'ok'),
        default => co_aviso(co_e($email) . ' A ficha foi recusada mesmo assim; se quiser, mande pelo WhatsApp.', 'erro'),
    };
    co_pagina('Ficha recusada',
        '<div class="confirmar">' . co_aviso('A ficha de ' . co_e((string) $f['nome']) . ' foi recusada.', 'ok') . $avisoEmail
        . ($whats !== '' ? '<p>Se quiser, mande a mesma mensagem também pelo WhatsApp. O WhatsApp abre com o texto pronto, é só enviar.</p>'
            . '<div class="botoes" style="justify-content:center"><a class="btn grande whats" href="' . co_e($whats) . '" target="_blank" rel="noopener">Mandar pelo WhatsApp</a></div>' : '')
        . '<div class="botoes" style="justify-content:center">'
        . ($proxima !== null ? '<a class="btn grande cheio" href="' . co_e(co_url(['id' => $proxima])) . '">Ver a próxima ficha</a>' : '')
        . '<a class="btn grande claro" href="' . co_e(co_url(mcp_voluntario_fora_da_capital($f) ? ['ver' => 'fora'] : [])) . '">Voltar para a lista</a></div></div>');
}

/** Depois de aprovar: o código grande, o WhatsApp com a mensagem pronta e a próxima ficha. */
function co_aprovada(array $f, string $codigo): never
{
    $id = (int) $f['id'];
    $whats = co_whatsapp((string) $f['telefone'], mcp_voluntario_aprovacao_mensagem($f, $codigo));
    $proxima = co_proxima($id, mcp_voluntario_fora_da_capital($f));
    co_pagina('Ficha aprovada',
        '<div class="confirmar">' . co_aviso('Pronto! A ficha de ' . co_e((string) $f['nome']) . ' foi aprovada.', 'ok')
        . '<p style="margin:0">Código de aprovação:</p><p class="codigo">' . co_e(mcp_codigo_formatado($codigo)) . '</p>'
        . '<p>Agora mande o código para a pessoa. Toque no botão verde: o WhatsApp abre com a mensagem pronta, é só enviar.</p>'
        . '<div class="botoes" style="justify-content:center">'
        . ($whats !== '' ? '<a class="btn grande whats" href="' . co_e($whats) . '" target="_blank" rel="noopener">Mandar pelo WhatsApp</a>' : '<p>Esta ficha não tem telefone: entregue o código pessoalmente.</p>')
        . (filter_var((string) $f['email'], FILTER_VALIDATE_EMAIL)
            ? '<form method="post" action="coordenacao.php" style="display:contents"><input type="hidden" name="k" value="' . co_e($GLOBALS['chave']) . '">'
                . '<input type="hidden" name="acao" value="email"><input type="hidden" name="id" value="' . $id . '">'
                . '<input type="hidden" name="mensagem" value="' . co_e(co_email_modelo($f)) . '">'
                . '<button class="btn grande azul" type="submit">Mandar por e-mail</button></form>'
            : '')
        . '</div><div class="botoes" style="justify-content:center">'
        . ($proxima !== null ? '<a class="btn grande cheio" href="' . co_e(co_url(['id' => $proxima])) . '">Ver a próxima ficha</a>' : '')
        . '<a class="btn grande claro" href="' . co_e(co_url(mcp_voluntario_fora_da_capital($f) ? ['ver' => 'fora'] : [])) . '">Voltar para a lista</a></div></div>');
}

// ----------------------------------------------------------------------------- rotas
if (isset($_GET['foto'])) {
    $selfie = mcp_voluntario_selfie((int) $_GET['foto']);
    if ($selfie === null) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . strlen($selfie['jpeg']));
    header('Content-Disposition: inline; filename="foto.jpg"');
    echo $selfie['jpeg'];
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $acao = (string) ($_POST['acao'] ?? '');
    $quem = co_quem();
    $f = $id > 0 ? mcp_voluntario_por_id($id) : null;
    if (!$f) {
        co_lista('decidir', co_aviso('Essa ficha não foi encontrada.', 'erro'));
    }
    switch ($acao) {
        case 'aprovar':
            if ((string) $f['status'] !== 'aceita') {
                if (!in_array((string) $f['status'], CO_PARA_DECIDIR, true)) {
                    co_ficha($id, co_aviso('Essa ficha já foi decidida.', 'info'));
                }
                $cpf = mcp_texto($_POST['cpf'] ?? '', 20);
                if ($cpf !== '' && !mcp_cpf_valido(mcp_digitos($cpf))) {
                    co_confirmar($id, 'aprovar', 'Esse CPF não está certo. Confira os números e tente de novo.', $cpf);
                }
                $r = mcp_voluntario_aceitar($id, $quem, $cpf);
                if (!$r['ok']) {
                    co_confirmar($id, 'aprovar', (string) $r['erro'], $cpf);
                }
            }
            $c = mcp_voluntario_aprovacao_emitir($id, $quem);
            mcp_registrar(null, 'coordenacao_voluntario', "aprovar · #$id · " . ($c['ok'] ? 'ok' : 'erro'));
            if (!$c['ok']) {
                co_ficha($id, co_aviso('A ficha foi aprovada, mas o código não foi gerado agora. Avise o Matheus.', 'erro'));
            }
            co_aprovada(mcp_voluntario_por_id($id) ?? $f, (string) $c['codigo']);
        case 'recusar':
            if (!in_array((string) $f['status'], CO_PARA_DECIDIR, true)) {
                co_ficha($id, co_aviso('Essa ficha já foi decidida.', 'info'));
            }
            $motivo = (string) ($_POST['motivo'] ?? '');
            $motivo = isset(CO_MOTIVOS[$motivo]) ? $motivo : 'outro';
            $mensagem = mcp_texto_longo($_POST['mensagem'] ?? '', CO_EMAIL_MAX);
            $avisar = !empty($_POST['avisar']) && filter_var((string) $f['email'], FILTER_VALIDATE_EMAIL);
            if ($avisar && mb_strlen($mensagem) < CO_EMAIL_MIN) {
                co_confirmar($id, 'recusar', 'Escreva a mensagem para a pessoa, ou desmarque "Avisar a pessoa por e-mail".', '', ['motivo' => $motivo, 'mensagem' => $mensagem, 'avisar' => true]);
            }
            $erro = mcp_voluntario_mudar_status($id, 'recusada', $quem, 'Recusada pelo link da coordenação. Motivo: ' . CO_MOTIVOS[$motivo][0] . '.');
            mcp_registrar(null, 'coordenacao_voluntario', "recusar · #$id · $motivo · " . ($erro === null ? 'ok' : 'erro'));
            if ($erro !== null) {
                co_ficha($id, co_aviso(co_e($erro), 'erro'));
            }
            $email = null;
            if ($avisar) {
                $email = co_email_enviar($f, $mensagem) ?? 'enviado';
            }
            co_recusada(mcp_voluntario_por_id($id) ?? $f, $mensagem, $email);
        case 'email':
            $mensagem = mcp_texto_longo($_POST['mensagem'] ?? '', CO_EMAIL_MAX);
            $erro = co_email_enviar($f, $mensagem);
            if ($erro !== null) {
                co_tela_email($id, $erro, $mensagem);
            }
            co_ficha($id, co_aviso('E-mail enviado para ' . co_e((string) $f['email']) . '.', 'ok'));
        case 'desfazer':
            if (!in_array((string) $f['status'], CO_RECUSADOS, true)) {
                co_ficha($id);
            }
            $erro = mcp_voluntario_mudar_status($id, 'em_analise', $quem, 'Recusa desfeita pelo link da coordenação.');
            mcp_registrar(null, 'coordenacao_voluntario', "desfazer · #$id · " . ($erro === null ? 'ok' : 'erro'));
            co_ficha($id, co_aviso($erro === null ? 'Pronto: a ficha voltou para "Para decidir".' : co_e($erro), $erro === null ? 'ok' : 'erro'));
        default:
            co_ficha($id);
    }
}

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $passo = (string) ($_GET['passo'] ?? '');
    if ($passo === 'whatsapp') {
        co_tela_whatsapp($id);
    }
    if ($passo === 'email') {
        co_tela_email($id);
    }
    if ($passo === 'aprovar' || $passo === 'recusar') {
        co_confirmar($id, $passo);
    }
    co_ficha($id);
}
if (isset($_GET['ajuda'])) {
    co_tutorial();
}
co_lista((string) ($_GET['ver'] ?? 'decidir'));
