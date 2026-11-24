#!/usr/bin/env python3
"""Cartão final com voz: a imagem parada + a chamada lida pela mesma voz dos vídeos. PASTA como no montar.py."""
import os, subprocess, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from falar import falar
S = os.environ.get('PASTA') or os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
falas = ['Inscreva-se na próxima turma de Primeiros Socorros Lei Lucas, na Cruz Vermelha do Rio de Janeiro.',
         'De duzentos e quarenta e nove, por cento e quarenta reais, com tudo incluso.',
         'É um preço promocional. Não sabemos até quando o desconto vai ficar.']
entradas = ['-loop', '1', '-framerate', '30', '-i', f'{S}/carrossel/10_cartao_final_1080x1350.png']
f, nomes, cursor = [], [], 0.5
for i, t in enumerate(falas):
    mp3, _ = falar(t, f'{S}/voz/cache', ' '.join(falas[:i]), ' '.join(falas[i + 1:]))
    d = float(subprocess.check_output(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', mp3]).decode())
    entradas += ['-i', mp3]
    ms = int(cursor * 1000)
    f.append(f'[{i + 1}:a]aresample=44100,adelay={ms}|{ms}[n{i}]')
    nomes.append(f'[n{i}]')
    cursor += d + 0.35
total = cursor + 1.4
f.append(f"{''.join(nomes)}amix=inputs={len(nomes)}:normalize=0:duration=longest,highpass=f=70,"
         f"acompressor=threshold=-20dB:ratio=2.5:attack=8:release=160:makeup=2,loudnorm=I=-16:TP=-1.5:LRA=9,"
         f"apad=whole_dur={total:.2f},atrim=0:{total:.2f}[a]")
f.append('[0:v]fps=30,format=yuv420p[v]')
subprocess.check_call(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', *entradas, '-filter_complex', ';'.join(f), '-map', '[v]', '-map', '[a]',
                       '-t', f'{total:.2f}', '-c:v', 'libx264', '-profile:v', 'high', '-preset', 'medium', '-crf', '17', '-tune', 'stillimage', '-r', '30',
                       '-c:a', 'aac', '-b:a', '192k', '-ar', '44100', '-ac', '2', '-movflags', '+faststart', f'{S}/carrossel/10_cartao_final_com_voz_1080x1350.mp4'])
print(f'cartão com voz: {total:.1f} s')
