<?php
// Copie para config.php (só no servidor; config.php NUNCA entra no Git) e preencha.
return [
    // Unicopag: chave da API (painel Unicopag > API). Segredo.
    'UNICO_API_KEY'  => '',
    'UNICO_BASE_URL' => 'https://api.cloud.unicopag.com.br',

    // MySQL da Hostinger (hPanel > Bancos de dados).
    'DB_HOST'  => 'localhost',
    'DB_PORT'  => 3306,
    'DB_NAME'  => 'u448697994_matricula',
    'DB_USER'  => 'u448697994_matricula',
    'DB_SENHA' => '',

    // Endereço público das páginas (monta postback_url e os links dos e-mails).
    'SITE_URL' => 'https://cruzvermelhariodejaneiro.org',

    // Inscrição em centavos. Deixe vazio para ler de cursos.json (9900).
    'INSCRICAO_CENTAVOS' => '',
    // Só em teste operacional: cobra este valor no lugar da inscrição e mostra um aviso na
    // página. Ex.: 100 = R$ 1,00. REMOVA antes de receber aluno.
    'PRECO_TESTE_CENTAVOS' => '',

    // Custos de processamento que o aluno pode escolher cobrir (opcional, por vontade própria).
    // Percentual sobre a inscrição + parcela fixa em centavos, por método.
    // PIX medido em 18/09/2026 pelo valor líquido devolvido pela API: 1,00% + R$ 1,48.
    'TAXA_PIX_PCT'      => 1.0,
    'TAXA_PIX_FIXA'     => 148,
    // Cartão: CONFIRMAR a taxa da conta (valores abaixo são estimativa).
    'TAXA_CARTAO_PCT'   => 4.99,
    'TAXA_CARTAO_FIXA'  => 49,

    // E-mail: Resend (domínio verificado). Sem chave, cai no mail() da Hostinger.
    'RESEND_API_KEY'   => '',
    'EMAIL_REMETENTE'  => 'Cruz Vermelha RJ <matricula@cruzvermelhariodejaneiro.org>',
    'EMAIL_RESPOSTA'   => 'contato@cruzvermelhariodejaneiro.org',
    // Quem recebe o aviso de cada inscrição paga (secretaria). Vazio = não avisa.
    'EMAIL_SECRETARIA' => 'contato@cruzvermelhariodejaneiro.org',
    'WHATSAPP_SECRETARIA' => '5521999922864',

    // API da escola (versão A da tela Parabéns). Vazio = versão B (secretaria fecha pelo WhatsApp).
    // Contrato: docs/briefing-matricula-cursos-presenciais.md, seção 8.2.
    'ESCOLA_API_URL'   => '',
    'ESCOLA_API_TOKEN' => '',
    'ESCOLA_URL'       => 'https://escola.cursoscruzvermelha.org',

    // Segredo para endpoints administrativos (ex.: reprocessar). Gere com openssl rand -hex 24.
    'ADMIN_TOKEN' => '',
];
