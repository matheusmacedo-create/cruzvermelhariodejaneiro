#!/usr/bin/env python3
"""Confere a entrega local e, com --url, os endereços publicados; só faz leituras.

Uso: python3 scripts/conferir_ad_grants.py [--url https://cruzvermelhariodejaneiro.org]
Não envia formulários, cria pagamentos nem atesta aprovação pelo Google.
"""
import argparse
import json
import sys
from pathlib import Path
from urllib.request import urlopen

RAIZ = Path(__file__).resolve().parent.parent


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--url', help='Endereço do site para conferir depois de publicar e limpar o cache.')
    args = parser.parse_args()
    erros = []

    def conferir(condicao: bool, mensagem: str) -> None:
        if not condicao:
            erros.append(mensagem)

    checkout = (RAIZ / 'site/matricula-cursos-presenciais/checkout/index.html').read_text()
    doacao = (RAIZ / 'site/doe/index.html').read_text()
    conferir('Quero ajudar a divulgar os cursos' not in checkout, 'Checkout ainda oferece contribuição para divulgação.')
    conferir('Venda e recebimento: O-CVB Filial Rio de Janeiro Ensino Ltda' in checkout,
             'Checkout não identifica a empresa responsável pela venda.')
    conferir('Acesso futuro a cursos gravados' not in doacao, 'Doação ainda promete benefício futuro.')
    conferir('/quem-somos/#como-nos-financiamos' in doacao and '/transparencia/' in doacao,
             'Doação não permite conferir o destino da contribuição.')
    catalogo = json.loads((RAIZ / 'site/matricula-cursos-presenciais/cursos.json').read_text())
    conferir(catalogo.get('divulgacao_centavos') == 0, 'Catálogo ainda oferece contribuição para divulgação.')
    imagem = RAIZ / 'site/assets/otim/hero-turma-cbfi-640.webp'
    conferir(imagem.is_file() and imagem.stat().st_size > 0, 'Falta a imagem móvel incluída na publicação.')
    if imagem.is_file():
        conferir(imagem.stat().st_size <= 50_000, 'Imagem móvel passou de 50 KB.')

    if args.url:
        base = args.url.rstrip('/')
        for caminho in ('/', '/doe/', '/en/donate/', '/es/donar/', '/voluntariado/',
                        '/voluntariado/cadastro/', '/transparencia/', '/quem-somos/',
                        '/assets/otim/hero-turma-cbfi-640.webp', '/matricula-cursos-presenciais/checkout/'):
            try:
                with urlopen(base + caminho, timeout=20) as r:
                    corpo = r.read()
                    conferir(r.status == 200 and bool(corpo), f'{caminho}: resposta vazia ou HTTP diferente de 200.')
                    if caminho == '/doe/':
                        conferir(b'Acesso futuro a cursos gravados' not in corpo, 'Doação publicada ainda contém a promessa antiga.')
                    if caminho.endswith('/checkout/'):
                        conferir(b'Quero ajudar a divulgar os cursos' not in corpo, 'Checkout publicado ainda contém a oferta antiga.')
            except Exception as e:
                erros.append(f'{caminho}: {type(e).__name__}: {e}')
        try:
            with urlopen(base + '/matricula-cursos-presenciais/api/info.php', timeout=20) as r:
                info = json.load(r)
            conferir(info.get('ok') is True and info.get('divulgacao_centavos') == 0,
                     'API publicada não confirmou a contribuição desativada.')
        except Exception as e:
            erros.append(f'API info.php: {type(e).__name__}: {e}')
    for erro in erros:
        print(f'FALHA: {erro}', file=sys.stderr)
    print(f'{len(erros)} falha(s). Conferência de arquivos' + (' e HTTP concluída.' if args.url else ' concluída. Use --url após publicar.'))
    return 1 if erros else 0


if __name__ == '__main__':
    raise SystemExit(main())
