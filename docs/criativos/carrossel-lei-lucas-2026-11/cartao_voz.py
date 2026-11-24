#!/usr/bin/env python3
"""Cartões parados com voz e música: a imagem + o texto lido pela mesma voz dos vídeos. PASTA como no montar.py.
Uso: python3 cartao_voz.py [aprende lei preco]   (sem nomes, monta os três)"""
import os, subprocess, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from falar import falar
from montar import trilha
S = os.environ.get('PASTA') or os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# 'virada': a fala (pelo número) em que a trilha tensa dá lugar à de alívio; sem ela, só a de alívio.
CARTOES = {
    'aprende': dict(imagem='cartao_o_que_voce_aprende_1080x1350.png', saida='cartao_o_que_voce_aprende_com_voz_1080x1350.mp4', desloc=30.0, falas=[
        'Em um dia de curso, você aprende, na prática, a agir quando uma criança se engasga ou para de respirar.',
        'E também em quedas, convulsões, queimaduras e hemorragias.',
        'São oito horas, com teoria, prática e certificado.']),
    'lei': dict(imagem='cartao_lei_lucas_1080x1350.png', saida='cartao_lei_lucas_com_voz_1080x1350.mp4', virada=2, falas=[
        'A Lei Lucas nasceu de uma perda. Em dois mil e dezessete, Lucas, de dez anos, morreu engasgado em um passeio da escola.',
        'Desde dois mil e dezoito, escolas e espaços de recreação infantil precisam ter professores e funcionários capacitados em primeiros socorros.',
        'O curso é para quem trabalha com crianças. E para quem tem uma em casa.']),
    'preco': dict(imagem='10_cartao_final_1080x1350.png', saida='10_cartao_final_com_voz_1080x1350.mp4', desloc=20.0, falas=[
        'Inscreva-se na próxima turma de Primeiros Socorros Lei Lucas, na Cruz Vermelha do Rio de Janeiro.',
        'De duzentos e quarenta e nove, por cento e quarenta reais, com tudo incluso.',
        'É um preço promocional. Não sabemos até quando o desconto vai ficar.']),
}


def montar(nome):
    c = CARTOES[nome]
    falas = c['falas']
    entradas = ['-loop', '1', '-framerate', '30', '-i', f'{S}/carrossel/{c["imagem"]}']
    f, nomes, cursor, comecos = [], [], 0.5, []
    for i, t in enumerate(falas):
        mp3, _ = falar(t, f'{S}/voz/cache', ' '.join(falas[:i]), ' '.join(falas[i + 1:]))
        d = float(subprocess.check_output(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', mp3]).decode())
        entradas += ['-i', mp3]
        ms = int(cursor * 1000)
        comecos.append(cursor)
        f.append(f'[{i + 1}:a]aresample=44100,adelay={ms}|{ms}[n{i}]')
        nomes.append(f'[n{i}]')
        cursor += d + 0.35
    total = cursor + 1.4
    f.append(f"{''.join(nomes)}amix=inputs={len(nomes)}:normalize=0:duration=longest,highpass=f=70,"
             f"acompressor=threshold=-20dB:ratio=2.5:attack=8:release=160:makeup=2,loudnorm=I=-16:TP=-1.5:LRA=9,"
             f"apad=whole_dur={total:.2f},atrim=0:{total:.2f}[fala]")
    virada = comecos[c['virada']] if 'virada' in c else None
    trilha(f, entradas, len(falas) + 1, total, virada, ganho_alivio=0, desloc=c.get('desloc', 0.0))
    f.append('[0:v]fps=30,format=yuv420p[v]')
    subprocess.check_call(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', *entradas, '-filter_complex', ';'.join(f), '-map', '[v]', '-map', '[a]',
                           '-t', f'{total:.2f}', '-c:v', 'libx264', '-profile:v', 'high', '-preset', 'medium', '-crf', '17', '-tune', 'stillimage', '-r', '30',
                           '-c:a', 'aac', '-b:a', '192k', '-ar', '44100', '-ac', '2', '-movflags', '+faststart', f'{S}/carrossel/{c["saida"]}'])
    print(f'{nome}: {c["saida"]} ({total:.1f} s)')


if __name__ == '__main__':
    for nome in sys.argv[1:] or list(CARTOES):
        montar(nome)
