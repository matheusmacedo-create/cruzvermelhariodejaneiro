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
    // Decisão de 18/09/2026: 5% em todos os métodos (média de PIX, cartão e checkout), sem parcela fixa.
    // (PIX medido pela API em 18/09: 1,00% + R$ 1,48.)
    'TAXA_PIX_PCT'      => 5.0,
    'TAXA_PIX_FIXA'     => 0,
    'TAXA_CARTAO_PCT'   => 5.0,
    'TAXA_CARTAO_FIXA'  => 0,

    // E-mail: Resend. O remetente precisa estar num domínio verificado na conta (em 18/09/2026:
    // info., noticias. e parceria.cruzvermelhariodejaneiro.org). Sem chave, cai no mail() da Hostinger.
    'RESEND_API_KEY'   => '',
    'EMAIL_REMETENTE'  => 'Cruz Vermelha RJ <matricula@info.cruzvermelhariodejaneiro.org>',
    // Responder-para e aviso à secretaria: só quando existir caixa de e-mail real no domínio
    // (em 18/09/2026 o contato@ ainda é Gmail e o domínio não recebe e-mail). Vazio = desligado.
    'EMAIL_RESPOSTA'   => '',
    'EMAIL_SECRETARIA' => '',
    'WHATSAPP_SECRETARIA' => '5521999922864',

    // API da escola (versão A da tela Parabéns). Vazio = versão B (secretaria fecha pelo WhatsApp).
    // Contrato: docs/briefing-matricula-cursos-presenciais.md, seção 8.2.
    'ESCOLA_API_URL'   => '',
    'ESCOLA_API_TOKEN' => '',
    'ESCOLA_URL'       => 'https://escola.cursoscruzvermelha.org',
];
