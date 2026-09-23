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
    'EMAIL_REMETENTE'  => 'Cruz Vermelha Brasileira Rio de Janeiro <matricula@info.cruzvermelhariodejaneiro.org>',
    // Contato por e-mail (19/09/2026, no lugar do WhatsApp da secretaria): destino das mensagens do
    // chat do site (api/contato.php) e endereço citado no rodapé de todos os e-mails. Precisa ser uma
    // caixa que receba de fato: em 19/09/2026 o MX do domínio ainda apontava para a Hostinger sem
    // serviço de e-mail contratado, então contato@ só passa a receber quando o MX for para o Google
    // Workspace. Até lá, coloque aqui um e-mail que funcione.
    'EMAIL_CONTATO'    => 'contato@cruzvermelhariodejaneiro.org',
    // Responder-para dos e-mails ao aluno. Vazio = EMAIL_CONTATO.
    'EMAIL_RESPOSTA'   => '',
    // Aviso interno de inscrição paga (responder-para = o aluno). Vazio = desligado.
    'EMAIL_SECRETARIA' => '',
    // Painel de contatos (api/painel.php): e-mails que podem pedir o link de entrada, separados por
    // vírgula. Vazio = EMAIL_CONTATO e EMAIL_SECRETARIA. O segredo dos links fica no banco (mcp_chaves).
    'PAINEL_EMAILS'    => '',
    // Remetente das respostas do painel ao cliente. Vazio = EMAIL_REMETENTE.
    'EMAIL_REMETENTE_CONTATO' => '',

    // API da escola (versão A da tela Parabéns). Vazio = versão B (a secretaria fecha turma e horário por e-mail).
    // Contrato: docs/briefing-matricula-cursos-presenciais.md, seção 8.2.
    'ESCOLA_API_URL'   => '',
    'ESCOLA_API_TOKEN' => '',
    'ESCOLA_URL'       => 'https://escola.cursoscruzvermelha.org',

    // Empresa recebedora no comprovante de inscrição em PDF anexado ao e-mail do aluno. Vazio = o
    // padrão de lib/comprovante.php: O-CVB FILIAL RIO DE JANEIRO ENSINO LTDA - EPP, 67.733.551/0001-35
    // (a empresa de ensino da filial, que recebe a matrícula; não é o CNPJ da filial).
    'RECEBEDOR_NOME'   => '',
    'RECEBEDOR_CNPJ'   => '',
];
