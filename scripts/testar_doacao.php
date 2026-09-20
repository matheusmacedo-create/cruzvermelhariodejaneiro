#!/usr/bin/env php
<?php
/**
 * Testes das funções puras do backend das doações (sem banco, sem rede, sem segredos).
 *
 * Uso:  php scripts/testar_doacao.php
 * Sai com código 1 se algum teste falhar. Usa configurações descartáveis via MCP_CONFIG_ARQUIVO
 * (geral) e DOE_CONFIG_ARQUIVO (doação).
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$configGeral = tempnam(sys_get_temp_dir(), 'mcp-config-');
file_put_contents($configGeral, "<?php return [
    'SITE_URL' => 'https://exemplo.org', 'TAXA_PIX_PCT' => 5.0, 'TAXA_PIX_FIXA' => 0,
    'TAXA_CARTAO_PCT' => 5.0, 'TAXA_CARTAO_FIXA' => 0, 'EMAIL_CONTATO' => 'contato@exemplo.org',
    'EMAIL_REMETENTE' => 'Cruz Vermelha RJ <matricula@exemplo.org>',
];");
$configDoe = tempnam(sys_get_temp_dir(), 'doe-config-');
file_put_contents($configDoe, "<?php return [
    'UNICO_API_KEY_DOACAO' => 'chave-doacao', 'DOACAO_MENSAL' => 1,
    'DOACAO_VALORES' => [3000, 6000, 10000, 15000, 25000, 50000, 99], 'DOACAO_VALOR_PADRAO' => 10000,
    'DOACAO_MINIMO_CENTAVOS' => 500, 'DOACAO_MAXIMO_CENTAVOS' => 5000000, 'EMAIL_DOACOES' => 'doacoes@exemplo.org',
];");
putenv("MCP_CONFIG_ARQUIVO=$configGeral");
putenv("DOE_CONFIG_ARQUIVO=$configDoe");
putenv('MCP_CATALOGO_ARQUIVO=' . $raiz . '/site/matricula-cursos-presenciais/cursos.json');
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $raiz . '/site/doe/api/lib.php';
restore_exception_handler();

$falhas = 0;
$total = 0;
function verificar(string $nome, mixed $obtido, mixed $esperado): void
{
    global $falhas, $total;
    $total++;
    if ($obtido === $esperado) {
        return;
    }
    $falhas++;
    fwrite(STDERR, sprintf("FALHOU %s\n  esperado: %s\n  obtido:   %s\n", $nome, var_export($esperado, true), var_export($obtido, true)));
}
function contem(string $nome, string $agulha, string $palheiro): void
{
    verificar($nome, str_contains($palheiro, $agulha), true);
}

// ----------------------------------------------------------------- configuração
verificar('chave da doação', mcp_doacao_chave(), 'chave-doacao');
verificar('cai para a configuração geral', mcp_doacao_cfg('EMAIL_CONTATO'), 'contato@exemplo.org');
verificar('valor próprio vence o geral', mcp_doacao_cfg('EMAIL_DOACOES'), 'doacoes@exemplo.org');
verificar('site vem da configuração geral', mcp_site_url(), 'https://exemplo.org');
verificar('url da página', mcp_doacao_url(), 'https://exemplo.org/doe/');
verificar('url com token', mcp_doacao_url('abc'), 'https://exemplo.org/doe/?t=abc');

// A trava da doação mensal vale mesmo com DOACAO_MENSAL = 1 na configuração: enquanto a
// integração de assinaturas não existir, a página nunca oferece o que o servidor não cobra.
verificar('mensal travada até a integração existir', mcp_doacao_mensal_ativa(), false);
verificar('constante da trava', MCP_DOACAO_MENSAL_IMPLEMENTADA, false);

// Valores sugeridos: fora dos limites saem, o resto vem ordenado.
verificar('valores sugeridos', mcp_doacao_valores(), [3000, 6000, 10000, 15000, 25000, 50000]);
verificar('valor padrão da configuração', mcp_doacao_valor_padrao(), 10000);
verificar('mínimo', mcp_doacao_minimo(), 500);
verificar('máximo', mcp_doacao_maximo(), 5000000);
verificar('sem modo de teste', mcp_doacao_teste(), false);
verificar('custos de processamento no PIX', mcp_taxa('pix', 10000), 500);

// ----------------------------------------------------------------- protocolo e rótulos
verificar('protocolo', mcp_doacao_protocolo(7, '2026-09-20 12:00:00'), 'CVD-260920-0007');
verificar('protocolo com id grande', mcp_doacao_protocolo(12345, '2026-01-02 00:00:00'), 'CVD-260102-2345');
verificar('rótulo PIX', mcp_doacao_metodo_rotulo(['metodo' => 'pix']), 'PIX');
verificar('rótulo cartão', mcp_doacao_metodo_rotulo(['metodo' => 'cartao', 'bandeira' => 'visa', 'ultimos4' => '1234']), 'Cartão Visa ···· 1234');
verificar('rótulo cartão sem bandeira', mcp_doacao_metodo_rotulo(['metodo' => 'cartao', 'bandeira' => '', 'ultimos4' => '']), 'Cartão');

// Remetente: nome curto da configuração geral vira o nome completo da filial.
verificar('remetente com nome completo', mcp_doacao_remetente(), 'Cruz Vermelha Brasileira Rio de Janeiro <matricula@exemplo.org>');
verificar('aviso interno vai para EMAIL_DOACOES', mcp_doacao_email_equipe_endereco(), 'doacoes@exemplo.org');

// ----------------------------------------------------------------- visão pública
$doacao = [
    'id' => 42, 'token' => str_repeat('a', 40), 'frequencia' => 'unica', 'status' => 'pago', 'metodo' => 'pix',
    'nome' => 'Maria da Silva Souza', 'cpf' => '52998224725', 'email' => 'maria@exemplo.org', 'telefone' => '21999998888',
    'valor_centavos' => 10000, 'taxa_centavos' => 500, 'total_centavos' => 10500, 'cobre_taxa' => 1,
    'unicopag_hash' => 'segredo-do-provedor', 'unicopag_status' => 'paid', 'pix_copia_cola' => '00020126BR',
    'pix_url' => 'https://pix.exemplo.org/1', 'pix_imagem' => null, 'bandeira' => null, 'ultimos4' => null,
    'utm_source' => 'ig', 'utm_medium' => 'social', 'utm_campaign' => null, 'utm_content' => null, 'utm_term' => null,
    'fbclid' => null, 'gclid' => null, 'ip' => '203.0.113.9',
    'criado_em' => '2026-09-20 12:00:00', 'pago_em' => '2026-09-20 12:03:00',
];
$publico = mcp_doacao_publico($doacao);
$json = json_encode($publico, JSON_UNESCAPED_UNICODE);
verificar('público: só o primeiro nome', $publico['nome'], 'Maria');
verificar('público: protocolo', $publico['protocolo'], 'CVD-260920-0042');
verificar('público: total', $publico['total_centavos'], 10500);
verificar('público: link de volta', $publico['url'], 'https://exemplo.org/doe/obrigado/?t=' . str_repeat('a', 40));
verificar('url do obrigado', mcp_doacao_url_obrigado('abc'), 'https://exemplo.org/doe/obrigado/?t=abc');
verificar('público sem CPF', str_contains($json, '52998224725'), false);
verificar('público sem hash do provedor', str_contains($json, 'segredo-do-provedor'), false);
verificar('público sem IP', str_contains($json, '203.0.113.9'), false);
verificar('público sem telefone', str_contains($json, '21999998888'), false);

// ----------------------------------------------------------------- e-mails
$pix = mcp_doacao_montar_email_pix($doacao);
verificar('e-mail do PIX vai para quem doou', $pix['para'], 'maria@exemplo.org');
verificar('assunto do PIX', $pix['assunto'], 'Seu PIX de R$ 105,00 para a Cruz Vermelha Brasileira Rio de Janeiro');
contem('e-mail do PIX traz o código', '00020126BR', $pix['html']);
contem('e-mail do PIX traz o link de volta', 'https://exemplo.org/doe/obrigado/?t=', $pix['html']);
contem('e-mail do PIX em texto puro', '00020126BR', $pix['texto']);

$ok = mcp_doacao_montar_email_confirmada($doacao);
verificar('assunto da confirmação traz nome e valor', $ok['assunto'], 'Obrigado, Maria! Sua doação de R$ 105,00 foi confirmada');
contem('agradecimento abre pelo nome', 'Obrigado, Maria.', $ok['html']);
contem('selo com o valor doado', 'Doação confirmada', $ok['html']);
contem('impacto da faixa de R$ 100', 'Voluntariado preparado', $ok['html']);
contem('reconhece quem cobriu os custos', 'chegam inteiros à filial', $ok['html']);
contem('convida para o voluntariado', 'form.spotform.com.br/voluntariocruzvermelharj', $ok['html']);
contem('assina como equipe', 'Equipe da Cruz Vermelha Brasileira Rio de Janeiro', $ok['html']);
verificar('impacto muda com o valor', mcp_doacao_impacto(3000)[0] . '|' . mcp_doacao_impacto(8000)[0] . '|' . mcp_doacao_impacto(15000)[0] . '|' . mcp_doacao_impacto(50000)[0], 'Material de primeiros socorros|Educação preventiva|Voluntariado preparado|Ação comunitária');
verificar('doação sem custos cobertos não fala neles', str_contains(mcp_doacao_montar_email_confirmada(['taxa_centavos' => 0, 'total_centavos' => 10000] + $doacao)['html'], 'chegam inteiros'), false);
contem('doação anônima é reconhecida', 'anônima', mcp_doacao_montar_email_confirmada(['anonimo' => 1] + $doacao)['html']);
contem('confirmação agradece pelo primeiro nome', 'Maria', $ok['html']);
contem('confirmação traz o CNPJ', '08.560.973/0001-97', $ok['html']);
contem('confirmação separa os custos', 'Custos de processamento', $ok['html']);
verificar('confirmação sem CPF', str_contains($ok['html'], '52998224725'), false);

$equipe = mcp_doacao_montar_email_equipe($doacao);
verificar('aviso interno vai para a caixa das doações', $equipe['para'], 'doacoes@exemplo.org');
verificar('assunto do aviso interno', $equipe['assunto'], 'Doação de R$ 105,00 · CVD-260920-0042');
contem('aviso interno traz o nome inteiro', 'Maria da Silva Souza', $equipe['html']);
contem('aviso interno traz o telefone formatado', '(21) 99999-8888', $equipe['html']);
contem('aviso interno traz a origem', 'ig · social', $equipe['html']);
verificar('aviso interno sem CPF', str_contains($equipe['html'], '52998224725'), false);

// Doação mensal: o rótulo aparece no comprovante quando a frequência é mensal.
$mensal = $doacao;
$mensal['frequencia'] = 'mensal';
contem('comprovante marca a doação mensal', 'Doação mensal', mcp_doacao_montar_email_confirmada($mensal)['html']);
contem('comprovante mensal fala do cancelamento', 'pausar ou cancelar', mcp_doacao_montar_email_confirmada($mensal)['html']);
verificar('doação única não fala em mensal', str_contains(mcp_doacao_montar_email_confirmada($doacao)['html'], 'Doação mensal'), false);
verificar('linhas do comprovante', array_keys(mcp_doacao_linhas($doacao)), ['Protocolo', 'Forma de pagamento', 'Valor da doação', 'Custos de processamento', 'Data']);

@unlink($configGeral);
@unlink($configDoe);
printf("%d testes, %d falhas\n", $total, $falhas);
exit($falhas > 0 ? 1 : 0);
