#!/usr/bin/env python3
"""Turma do CBFI de 28/11/2026 e a volta automática para "novas entradas em janeiro de 2027".

Pedido do Matheus (27/10/2026): o CBFI (Curso Básico de Formação Institucional) de 28/11/2026 é aberto a quem se
cadastrar agora, e as comunicações do voluntariado passam a falar dessa data. Depois dela, voltam sozinhas para a
abertura em janeiro, sem precisar publicar de novo.

Como funciona nas páginas estáticas (um bloco inteiro, como o botão da /bio/, leva data-cbfi="ate" na própria tag e
o gerador o deixa de fora a partir de 29/11): o texto da turma e o de janeiro vão juntos no HTML, em dois <span>:

    <span data-cbfi="ate">…28/11…</span><span data-cbfi="depois" hidden>…janeiro…</span>

e SCRIPT (antes de </body>) troca um pelo outro a partir de 29/11 pela data de Brasília. Sem JavaScript fica o da
turma. resolver() é a mesma troca na hora de gerar: rodar um gerador a partir de 29/11 já grava só o de janeiro e
tira o script. Os dois <span> não podem ter outro <span> dentro (a troca é por expressão regular).

A API (site/matricula-cursos-presenciais/api/lib/voluntarios.php, mcp_vol_cbfi_aberto) segue a mesma data, com
MCP_VOL_CBFI_DATA: mudou aqui, mudar lá.

Uso:  importado pelos geradores; python3 scripts/cbfi.py mostra se a turma ainda vale hoje.
"""
from __future__ import annotations

import re
from datetime import date, datetime
from zoneinfo import ZoneInfo

DATA = date(2026, 11, 28)
DATA_ISO = DATA.isoformat()
DATA_TEXTO = "28 de novembro de 2026"
DATA_CURTA = "28/11"
DIA_DA_SEMANA = "sábado"
# Depois da turma: o mesmo texto de antes (PROXIMAS_ENTRADAS em gerar_voluntariado_cadastro.py e MCP_VOL_PROXIMAS_ENTRADAS na API).
PROXIMAS_ENTRADAS = "janeiro de 2027"
URL = "/cbfi/"
FUSO = ZoneInfo("America/Sao_Paulo")

# Brasília é UTC-3 o ano todo (sem horário de verão desde 2019): 108e5 ms = 3 h.
SCRIPT = ('<script>/* CBFI de ' + DATA_CURTA + ' (scripts/cbfi.py): a partir do dia seguinte, o texto de janeiro */'
          '(function(){var h=new Date(Date.now()-108e5).toISOString().slice(0,10);if(h<="' + DATA_ISO + '")return;'
          'var l=document.querySelectorAll("[data-cbfi]");for(var i=0;i<l.length;i++){var a=l[i].getAttribute("data-cbfi")==="ate";'
          'l[i].hidden=a;if(a)l[i].style.display="none";}})();</script>')

_ATE = re.compile(r'<span data-cbfi="ate">(?:(?!</span>).)*</span>', re.S)
_DEPOIS = re.compile(r'<span data-cbfi="depois" hidden>((?:(?!</span>).)*)</span>', re.S)
_SCRIPT = re.compile(r'[ \t]*<script>/\* CBFI de .*?</script>\n?')


def hoje() -> date:
    return datetime.now(FUSO).date()


def aberto(dia: date | None = None) -> bool:
    """A turma ainda vale (até o dia dela, inclusive, em Brasília)."""
    return (dia or hoje()) <= DATA


def alternar(agora_html: str, depois_html: str) -> str:
    """Os dois textos, para o navegador escolher; depois da turma, só o de janeiro."""
    if not aberto():
        return depois_html
    return f'<span data-cbfi="ate">{agora_html}</span><span data-cbfi="depois" hidden>{depois_html}</span>'


def resolver(html: str) -> str:
    """Página pronta: com a turma valendo, garante o script antes de </body>; depois dela, deixa só o texto de janeiro."""
    html = _SCRIPT.sub("", html)
    if 'data-cbfi="' not in html:
        return html
    if aberto():
        return html.replace("</body>", "  " + SCRIPT + "\n</body>", 1)
    return _DEPOIS.sub(r"\1", _ATE.sub("", html))


if __name__ == "__main__":
    print(f"CBFI de {DATA_TEXTO}: {'vale' if aberto() else 'já passou'} hoje ({hoje():%d/%m/%Y}, Brasília)")
