# Turmas da matrícula: sincronização com a escola

A página `/matricula-cursos-presenciais/` e o checkout mostram e vendem as turmas do
`site/matricula-cursos-presenciais/oferta.json`. Até 25/10/2026 esse arquivo era mantido à mão, e a página ficava
em "Turmas em breve" mesmo com turma aberta na escola. Desde então ele é sincronizado com a escola por
`scripts/sincronizar_turmas.py`, rodado todo dia pela rotina abaixo.

## De onde vêm os dados

A página pública de cada curso na escola (`url_escola` no `cursos.json`), seção "Escolha sua turma":

- **id da turma**: o link `/inscrever/<id>`. É o `turma_id` que a matrícula manda à escola depois do pagamento
  (`matricula_rapida_v2`), então a turma vendida aqui é a mesma da escola.
- **início e fim**: "Início em 12 de novembro de 2026" e "09:00 - 17:00", no horário de Brasília.
- **prazo de inscrição** (`inscricoes_ate`): a escola não publica. Vale a regra das turmas cadastradas à mão até
  aqui, a véspera do início às 23:59:59.

Regras do script:
- turma nova entra aberta;
- turma que mudou de data ou horário é atualizada;
- turma futura que sumiu da escola ganha `"lotada": true` (a venda dela fecha), mas não é apagada, porque pode ter
  aluno pago;
- turma que já começou fica como está;
- se alguma página da escola não abrir ou mudar de formato, **nada é gravado**: uma falha de rede nunca fecha turmas.

## Rotina das turmas (diária)

1. `python3 scripts/sincronizar_turmas.py`
2. Regerar o que depende do `oferta.json`, nesta ordem:
   - `python3 scripts/gerar_matricula_presencial.py`
   - `python3 scripts/gerar_checkout.py`
   - `python3 scripts/chat_widget.py`

   Regerar todo dia também tira da página as turmas cujo prazo passou, mesmo sem mudança na escola.
3. Se `git status` não mostrar nada em `site/`, parar: nada a publicar.
4. Conferir:
   - `php scripts/testar_checkout.php`
   - `php scripts/testar_pagar_tudo.php`
   - `python3 scripts/sincronizar_turmas.py --teste`

   Com falha, não publicar e avisar.
5. Branch, commit ("Turmas sincronizadas com a escola: …"), PR e merge na `main`.
6. Publicar os arquivos de `site/` que mudaram, com o `oferta.json` **primeiro** (o servidor passa a aceitar a turma
   antes de a página oferecê-la): `scripts/publicar_hostinger.sh site/matricula-cursos-presenciais/oferta.json …`.
   Antes, `--copiar-do-ar` para ter como desfazer, e conferir que o ar estava igual à `main` anterior. Se não estava,
   parar e avisar, porque alguém publicou por fora.
7. Limpar o cache (`hosting_cache_clear-website`) e conferir:
   - os arquivos no servidor iguais aos da `main`;
   - `python3 scripts/sincronizar_turmas.py --conferir` saindo com 0.
