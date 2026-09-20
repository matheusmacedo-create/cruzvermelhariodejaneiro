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
    // Conta da Unicopag dedicada às doações (diferente da conta do checkout de cursos).
    'UNICO_API_KEY_DOACAO' => '',

    // Doação mensal (assinatura recorrente na Unicopag). Só ligue quando a integração
    // de assinaturas estiver configurada: com 0, a página mostra apenas a doação única.
    'DOACAO_MENSAL' => 0,

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
