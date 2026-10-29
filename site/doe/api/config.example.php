<?php
/**
 * Configuração das doações (`/doe/`). Copie para config.php **no servidor** e preencha.
 * O arquivo real nunca entra no repositório (.gitignore).
 *
 * As chaves ausentes aqui caem para a configuração geral do checkout
 * (site/matricula-cursos-presenciais/api/config.php): banco, Resend, SITE_URL, taxas, e-mails.
 * Só precisa estar aqui o que é próprio da doação.
 */
declare(strict_types=1);

return [
    // Gateway das doações: 'unicopag' (padrão) ou 'donaty'. Só as doações trocam; a matrícula dos
    // cursos segue na Unicopag. Para voltar atrás, troque para 'unicopag' (sem deploy). Com 'donaty'
    // a página oferece só PIX (a Donaty só documenta PIX) e o postback vai para /doe/api/webhook.php.
    'DOACAO_GATEWAY' => 'unicopag',

    // Donaty: chave secreta (dnt_sk_…, Authorization: Bearer) e base da API. NUNCA no repositório:
    // só neste arquivo, no servidor.
    'DONATY_API_KEY' => '',
    'DONATY_BASE_URL' => 'https://api.donaty.com.br',

    // Conta da Unicopag dedicada às doações (diferente da conta do checkout de cursos).
    'UNICO_API_KEY_DOACAO' => '',

    // Doação mensal: assinatura no cartão pela API de assinaturas da Unicopag. Com 1 e o plano abaixo, a
    // página oferece "Mensal"; com 0 ou sem plano, só a doação única. O token é o UNICO_API_KEY_DOACAO,
    // salvo outro em UNICO_ASSINATURA_TOKEN. O plano mensal da filial foi criado em 29/10/2026
    // (produto cvrj-doacao-mensal, variante cvrj-doacao-mensal-v1); o valor de cada doação vai na assinatura.
    'DOACAO_MENSAL' => 0,
    'DOACAO_MENSAL_PLANO_ID' => '01m62jcwztx693tyx1y0hccgha',
    'UNICO_ASSINATURA_TOKEN' => '',
    'UNICO_ASSINATURA_BASE_URL' => 'https://subscription.unicopag.com.br/api/v1',

    // Valores sugeridos na página, em centavos, e o valor que já vem marcado.
    'DOACAO_VALORES' => [3000, 6000, 10000, 15000, 25000, 50000],
    'DOACAO_VALOR_PADRAO' => 6000,
    'DOACAO_MINIMO_CENTAVOS' => 500,
    'DOACAO_MAXIMO_CENTAVOS' => 5000000,

    // Cobrança de teste: com valor > 0, toda doação é cobrada por este valor (em centavos)
    // e a página avisa. Deixe vazio em produção.
    'DOACAO_TESTE_CENTAVOS' => '',

    // Para onde vai o aviso de cada doação confirmada. Vazio: usa EMAIL_CONTATO.
    'EMAIL_DOACOES' => '',

    // Remetente dos e-mails de doação. Vazio: usa EMAIL_REMETENTE da configuração geral (que é o
    // da matrícula, e confunde quem doou). Use um endereço do subdomínio verificado na Resend,
    // por exemplo 'Cruz Vermelha Brasileira Rio de Janeiro <doacao@info.cruzvermelhariodejaneiro.org>'.
    // A resposta continua indo para contato@ (caixa do Google Workspace), via EMAIL_RESPOSTA.
    'EMAIL_REMETENTE_DOACAO' => '',
];
