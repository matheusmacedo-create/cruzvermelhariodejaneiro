# Versão revisada para Ad Grants — 19/10/2026

Esta entrega corrige o código do site institucional e o gerador do portal no Palácio Virtual. Não foi publicada e não altera dados ou pagamentos existentes.

## O que muda

- Novas inscrições não incluem contribuição para divulgação. O catálogo, a sincronização, o HTML, o JavaScript e o servidor ficam em zero. Configurações antigas no servidor e respostas de API em cache não reativam a oferta. Uma página antiga que tente pagar com o adicional recebe o erro de valor indisponível já previsto no endpoint. Os comprovantes, totais e estornos de pagamentos anteriores são preservados.
- O checkout identifica a empresa da escola como vendedora e recebedora nos dois planos. Os agradecimentos pela cobertura de custos identificam a taxa da escola, sem confundi-la com doação à ONG.
- A doação deixa de prometer acesso futuro a cursos. Em seu lugar, oferece os links de financiamento e documentos públicos.
- O modelo ilustrativo de certificado tem uma nota para confirmar o emissor e as condições com a escola, sem prometer que o modelo antigo seja o da turma atual. A definição do certificado efetivamente emitido continua dependendo da escola.
- A imagem móvel de 640 × 480 passa a acompanhar o repositório: 38.426 bytes, copiada sem alteração da versão pública da própria filial. SHA-256: `b5078fff68ff29fe157889cbad64ab206f2834b58eee144097df36171201363e`. Os outros assets continuam no servidor.
- No Palácio Virtual, o portal explica o financiamento e a relação com a escola, oferece pedido de documentos e distingue integridade de arquivo de auditoria contábil. Cadastro vazio de parcerias não é tratado como prova de que nenhuma parceria esteja vigente. Precisa de deploy e regeneração do portal para chegar ao público.

## Evidência de validação

- PHP: 871 testes de checkout (incluindo as suítes de pagamento completo e seus e-mails), 59 de doação e 113 de voluntários: zero falhas. Executados em contêiner sem rede. Configuração legada de divulgação com 1490 centavos não reativa a cobrança; os testes de recibos históricos continuam passando.
- Navegador Chromium, 390 × 844: checkout com API simulada tanto em zero quanto em 1490 centavos, sem cobrança extra ou erro JavaScript; fornecedor visível e sem rolagem horizontal. Doação sem promessa futura e sem rolagem horizontal. Nenhum formulário enviado, pagamento criado ou e-mail enviado.
- Palácio Virtual: build de produção, TypeScript, ESLint nos arquivos alterados e renderização do portal com e sem documentos passaram. Ajuda: 45 áreas, zero erros (avisos existentes em outras áreas).
- Site: `scripts/conferir_ad_grants.py` passou; PHP alterado e JavaScript do checkout sem erros de sintaxe. O validador completo de vocabulário Schema.org ficou bloqueado pelo proxy; não declarar essa validação como aprovada.

## Publicação

1. Rever o diff dos dois repositórios. Não há migrações de banco. Não subir `config.example.php`, credenciais ou arquivos de teste para a hospedagem.
2. Guardar uma cópia dos arquivos atuais indicados em `scripts/publicacao-ad-grants-revisao.txt`. Publicar primeiro `api/lib/config.php`, que impede novas cobranças de divulgação mesmo se a página antiga estiver em cache; depois os demais arquivos da lista. Manter os arquivos privados de configuração existentes.
3. Limpar o cache da Hostinger. Executar `python3 scripts/conferir_ad_grants.py --url https://cruzvermelhariodejaneiro.org`. O modo HTTP só lê páginas e a API pública de configuração. Não é um teste de pagamento.
4. No Palácio Virtual, publicar o deploy revisado e usar Transparência → “Atualizar a página no site”. Isso atualiza o gerador; não exige editar os documentos nem executar SQL.
5. Conferir menu móvel, formulário de voluntário, doação e links dos PDFs no site publicado. Um teste financeiro real exige autorização e acompanhamento próprios.
6. Só depois de resolver as pendências institucionais, considerar o reenvio no Google para Organizações sem Fins Lucrativos.

Para desfazer: restaurar os arquivos guardados da hospedagem e o deploy anterior, regenerar o portal e limpar o cache. Isso também pode reativar a oferta antiga de divulgação; conferir o comportamento antes de voltar a aceitar inscrições. Não apagar registros financeiros ou documentos.

## Verificações públicas e pendências externas

Nesta revisão, home, doação, notícias, Quem somos e transparência responderam HTTP 200. O portal lista dois documentos: estatuto e ata notarial. Assim, a hipótese anterior de que a doação ainda estaria em 503 ou de que o portal estaria vazio não foi confirmada no estado atual. O código ter as correções não substitui validar a nova publicação.

O espelho de dados da Receita na BrasilAPI ainda informa CNPJ `08.560.973/0001-97` **INAPTO**, desde 12/05/2026, motivo omissão de declarações. A contabilidade deve conferir o comprovante oficial atual e tratar eventual regularização. Não foi feita uma consulta oficial autenticada à Receita.

Conferir com a diretoria: demonstrações contábeis e relatórios de atividades efetivamente aprovados e publicáveis; documentação da relação e dos repasses da escola; titularidade/controle do domínio e correspondência com o cadastro aprovado no Google para ONGs. Não inventar números ou publicar documentos inexistentes. Confirmar conversões no GA4/Google Ads: eventos no JavaScript não comprovam o vínculo e a configuração na conta.

A política oficial de sites foi consultada com sucesso: https://support.google.com/grants/answer/1657899?hl=pt-BR. Ela exige missão predominante, informações financeiras claras, atividades comerciais limitadas relacionadas à missão, navegação funcional, HTTPS e doação segura. As correções reduzem riscos; não garantem aprovação.
