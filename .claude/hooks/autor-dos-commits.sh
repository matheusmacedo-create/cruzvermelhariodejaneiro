#!/bin/bash
# Nas sessões do Claude Code na web, os commits saem com o autor
# "Claude <noreply@anthropic.com>". Com o repositório privado, a Vercel no
# plano Hobby só faz deploy de commit do dono da conta: o preview das
# branches claude/* deixa de subir. Este hook troca o autor pela conta do
# GitHub de quem abriu a sessão, para o deploy reconhecer o dono.
#
# Só age na web (CLAUDE_CODE_REMOTE) e só para quem está na tabela abaixo:
# a sessão de outro colaborador continua com o autor que já tinha. No
# computador de cada pessoa não faz nada. O "Co-Authored-By: Claude" das
# mensagens de commit continua. Não lê nem grava credencial nenhuma.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

# E-mail da conta claude.ai → nome e e-mail verificados na conta do GitHub.
case "${CLAUDE_CODE_USER_EMAIL:-}" in
  matheus.macedo@unicopag.com)
    nome="matheusmacedo-create"
    email="matheus.macedo@unicopag.com"
    ;;
  *)
    exit 0
    ;;
esac

# --global: a sessão pode ter vários repositórios clonados lado a lado.
git config --global user.name "$nome"
git config --global user.email "$email"
