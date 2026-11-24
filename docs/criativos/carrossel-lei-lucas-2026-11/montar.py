#!/usr/bin/env python3
"""Monta os cartões de vídeo do carrossel: faixa de legenda embutida + narração (ElevenLabs) ou fala original.

Uso: python3 montar.py [LETRA ...]   (sem letras, monta todos)
Saída: 1080 x 1350, H.264 + AAC, 30 quadros por segundo.
"""
import os, re, subprocess, sys
sys.path.insert(0, os.path.dirname(__file__))
from falar import falar

# PASTA: onde estão videos/prontos (os vídeos já em 1080 x 1350) e onde saem carrossel/ e voz/cache/.
S = os.environ.get('PASTA') or os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PRONTOS = f'{S}/videos/prontos'
SAIDA = f'{S}/carrossel'
CACHE = f'{S}/voz/cache'
os.makedirs(SAIDA, exist_ok=True)

BAIXO = (1090, 260)   # y, altura da faixa
FOLGA = 0.35          # entre duas falas
MAX_LINHA = 25        # letras por linha da legenda
MAX_BLOCO = 46        # letras por bloco (duas linhas)

NARRADOS = {
    'D': dict(arquivo='D_pms_salvam_bebe_viatura_DF', inicio=0, teto=50, faixa=BAIXO, original=0,
              credito='Imagens: PMDF, via Metrópoles', nome='01_D_viatura_DF', falas=[
        (0.4, 'Águas Claras, Distrito Federal. |Um carro cruza com uma viatura |da Polícia Militar e para.'),
        (None, 'Uma mulher desce desesperada, |com um bebê de um ano no colo. |Ele estava inconsciente e arroxeado.'),
        (None, 'Os policiais começam na hora |a manobra de desengasgo. |E o bebê volta a chorar.'),
        (None, 'Depois, ele foi levado para avaliação médica.'),
        (None, 'E se não tivesse uma viatura passando?')]),
    'K': dict(arquivo='K_pm_salva_bebe_quartel_MG_g1', inicio=10, teto=49.9, faixa=BAIXO, original=0,
              credito='Imagens: Polícia Militar, via g1 Sul de Minas', nome='03_K_quartel_MG', falas=[
        (0.4, 'Sapucaí-Mirim, Sul de Minas. |Uma bebê de apenas um mês |chega engasgada ao quartel da Polícia Militar.'),
        (None, 'O cabo Victor Aquino estava sozinho no posto. |A bebê estava arroxeada, com o corpo rígido.'),
        (None, 'Ele fez a manobra: |cinco batidas nas costas, |cinco compressões no peito.'),
        (None, 'Do lado de fora, duas pessoas |se ajoelharam na rua para rezar.'),
        (None, 'No terceiro ciclo, a mãozinha dela abriu.'),
        (38.3, 'A bebê foi levada ao hospital |e passou por avaliação médica. |Ele sabia o que fazer.')]),
    'F': dict(arquivo='F_pms_salvam_bebe_camera_2022', inicio=14, teto=46, faixa=BAIXO, original=0,
              credito='Imagens: câmera de segurança, 2022', nome='04_F_policial_rua', falas=[
        (0.4, 'Uma família chega correndo |com um bebê engasgado |e encontra um policial militar.'),
        (8.0, 'O policial pega a criança, |vira de bruços |e começa as batidas nas costas.'),
        (20.0, 'São segundos que parecem horas.'),
        (35.5, 'Até que o bebê volta a respirar. |Ele foi levado ao hospital e passa bem.'),
        (None, 'Você saberia fazer o mesmo?')]),
    'J': dict(arquivo='J_bombeiro_salva_menina_quartel_SC_jornal', inicio=0, teto=50, faixa=(212, 296), original=0,
              credito='Imagens: Corpo de Bombeiros de SC, via Jornal Razão', nome='06_J_bombeiros_SC', falas=[
        (0.4, 'Braço do Norte, Santa Catarina. |Uma mãe entra de carro |no quartel dos bombeiros, |buzinando, com o pisca-alerta ligado.'),
        (None, 'A filha, de sete anos, |estava engasgada e inconsciente.'),
        (None, 'O cabo Michel corre até o carro |e começa a manobra ali mesmo, no pátio.'),
        (None, 'Deu certo: a menina voltou a respirar.'),
        (None, 'Depois, ela foi levada de ambulância |ao hospital, para avaliação.'),
        (None, 'E se o quartel estivesse longe?')]),
    'E': dict(arquivo='E_pm_salva_bebe_chapeco_jornal', inicio=0, teto=39, faixa=(528, 226), original=0,
              credito='Imagens: câmera do batalhão, via Jornal Razão', nome='07_E_batalhao_Chapeco', falas=[
        (0.4, 'Chapecó, Santa Catarina. |Um policial militar encerrava |um plantão de dezoito horas |quando um casal chegou pedindo socorro.'),
        (None, 'Nos braços, a filha, |que não conseguia respirar direito.'),
        (None, 'Ele vira a bebê de bruços |e faz a manobra de desengasgo.'),
        (None, 'A respiração voltou.'),
        (27.0, 'O SAMU chegou logo depois |e levou a bebê ao hospital, para avaliação.'),
        (None, 'Em casa, quem faz isso é você.')]),
    'A': dict(arquivo='A_mae_salva_bebe_camera', inicio=8, teto=46, faixa=BAIXO, original=0.16,
              credito='Imagens: arquivo da família (@juliannarvalentim)', nome='09_A_mae_em_casa', falas=[
        (0.4, 'Em casa, na sala. |O bebê teve um engasgo silencioso.'),
        (None, 'Quando a mãe percebeu, ele já não reagia.'),
        (9.5, 'Ela fez a manobra que treinava |desde que ele era recém-nascido.'),
        (17.0, 'E ele desengasgou.'),
        (20.0, 'Ali não tinha viatura nem quartel. |Tinha a mãe. |E ela sabia o que fazer.')]),
    'L': dict(arquivo='L_pms_salvam_bebe_batalhao_DF_correio', inicio=0, teto=26, faixa=(975, 270), original=0,
              credito='Imagens: PMDF, via Correio Braziliense', nome='reserva_L_batalhao_DF', falas=[
        (0.4, 'Estrutural, Distrito Federal. |Os pais chegam ao batalhão da Polícia Militar |com o bebê engasgado, já sem respirar.'),
        (None, 'Dois policiais fazem a manobra de desengasgo, |adaptada para bebês.'),
        (None, 'Ele voltou a respirar ali mesmo. |Os bombeiros avaliaram: a criança passa bem.')]),
}

# Depoimentos: a fala é a original; a legenda vem da transcrição (ElevenLabs), com o tempo de cada palavra.
DEPOIMENTOS = {
    'G': dict(arquivo='G_depoimento_1', inicio=22.05, fim=44.75, nome='02_G_depoimento_Luciana',
              credito='Luciana, aluna do curso de Primeiros Socorros', blocos=[
        (22.30, 24.60, 'Eu já sigo a Cruz Vermelha há muito tempo'),
        (24.84, 27.60, 'e queria fazer esse curso de primeiros socorros.'),
        (27.88, 29.60, 'Achei o curso muito bom,'),
        (29.92, 31.45, 'é bem dinâmico,'),
        (31.60, 33.70, 'o professor muito bom também.'),
        (34.12, 36.60, 'E adorei passar o dia aqui na Cruz Vermelha.'),
        (36.88, 40.30, 'Aproveitei pra ir no brechó também, comprar umas coisinhas.'),
        (41.38, 43.85, 'Tô muito satisfeita com o curso.'),
        (43.98, 44.70, 'Obrigada!')]),
    'H': dict(arquivo='H_depoimento_2', inicio=22.93, fim=42.85, nome='05_H_depoimento_Ana_Claudia',
              credito='Ana Cláudia, odontopediatra e aluna do curso', blocos=[
        (22.94, 25.50, 'Meu nome é Ana Cláudia, eu sou odontopediatra.'),
        (25.96, 27.76, 'Eu fiz mesmo pra reciclar,'),
        (27.80, 30.86, 'porque já tinha feito há muito tempo e mudou tudo.'),
        (30.88, 33.36, 'Adorei! O professor foi bem didático,'),
        (33.38, 34.49, 'eu gostei bastante.'),
        (34.50, 36.28, 'Recomendaria pra qualquer um,'),
        (36.30, 39.18, 'não só da área médica, dentista.'),
        (39.20, 40.70, 'Acho que todo mundo deveria fazer.'),
        (40.74, 41.43, '— Muito importante, né?'),
        (41.44, 42.80, 'É importante, sim.')]),
    'I': dict(arquivo='I_depoimento_3', inicio=25.35, fim=44.45, nome='08_I_depoimento_Luciene',
              credito='Luciene, aluna do curso de Primeiros Socorros', blocos=[
        (25.48, 28.00, 'Eu conheci através das redes sociais.'),
        (28.84, 31.50, 'Finalizei agora o curso de primeiros socorros.'),
        (32.86, 34.75, 'Superou minhas expectativas.'),
        (34.88, 37.00, 'Saí daqui totalmente confiante'),
        (37.42, 41.15, 'e sabendo, tendo a noção de como socorrer alguém,'),
        (41.30, 44.20, 'não deixar ninguém a ermo.')]),
}


def duracao(arquivo):
    return float(subprocess.check_output(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', arquivo]).decode().strip())


def quebrar(texto):
    """Uma ou duas linhas equilibradas."""
    if len(texto) <= MAX_LINHA:
        return texto
    palavras = texto.split(' ')
    melhor = None
    for i in range(1, len(palavras)):
        a, b = ' '.join(palavras[:i]), ' '.join(palavras[i:])
        custo = abs(len(a) - len(b)) + (1000 if max(len(a), len(b)) > MAX_LINHA + 4 else 0)
        if melhor is None or custo < melhor[0]:
            melhor = (custo, a + r'\N' + b)
    return melhor[1]


def blocos_da_fala(texto, alinhamento, deslocamento, marcas=()):
    """Reparte a fala em blocos de até duas linhas, com o tempo tirado das letras: frase a frase, depois nas
    vírgulas, e o que ainda sobrar comprido vai em partes equilibradas."""
    ini, fim = alinhamento['character_start_times_seconds'], alinhamento['character_end_times_seconds']
    assert len(ini) == len(texto), 'alinhamento não cobre o texto'
    if marcas:
        cortes = [0] + marcas + [len(texto)]
        saida = []
        for a, b in zip(cortes, cortes[1:]):
            b2 = len(texto[:b].rstrip())
            saida.append([deslocamento + ini[a], deslocamento + fim[b2 - 1], texto[a:b2]])
        return saida
    def partes_iguais(a, b):
        palavras = [(a + m.start(), a + m.end()) for m in re.finditer(r'\S+', texto[a:b])]
        n = -(-(b - a) // MAX_BLOCO)
        alvo, saida, comeco = (b - a) / n, [], a
        for i, (x, y) in enumerate(palavras[:-1]):
            if len(saida) < n - 1 and y - comeco >= alvo - 4 and len(texto[palavras[i + 1][0]:palavras[i + 1][1]]) > 3:
                saida.append((comeco, y)); comeco = palavras[i + 1][0]
        saida.append((comeco, b))
        return saida
    trechos = []
    for frase in re.finditer(r'[^.?!]+[.?!]+', texto):
        a = frase.start() + len(frase.group()) - len(frase.group().lstrip())
        b = frase.end()
        if b - a <= MAX_BLOCO:
            trechos.append((a, b)); continue
        oracoes, comeco = [], a
        for v in re.finditer(r'[,:]\s', texto[a:b]):
            oracoes.append((comeco, a + v.start() + 1)); comeco = a + v.end()
        oracoes.append((comeco, b))
        juntas = []
        for o in oracoes:
            if juntas and o[1] - juntas[-1][0] <= MAX_BLOCO and (o[1] - o[0] < 14 or juntas[-1][1] - juntas[-1][0] < 14):
                juntas[-1] = (juntas[-1][0], o[1])
            else:
                juntas.append(o)
        for x, y in juntas:
            trechos += [(x, y)] if y - x <= MAX_BLOCO else partes_iguais(x, y)
    return [[deslocamento + ini[a], deslocamento + fim[b - 1], texto[a:b]] for a, b in trechos]


def t_ass(s):
    s = max(0, s)
    return f'{int(s // 3600)}:{int(s % 3600 // 60):02d}:{s % 60:05.2f}'


def escrever_ass(caminho, blocos, faixa, credito, total):
    y, h = faixa
    centro = y + (h - 44) // 2 + 4
    linhas = ['[Script Info]', 'ScriptType: v4.00+', 'PlayResX: 1080', 'PlayResY: 1350', 'WrapStyle: 2', 'ScaledBorderAndShadow: yes', '',
              '[V4+ Styles]',
              'Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding',
              'Style: Leg,Inter,62,&H00FFFFFF,&H00FFFFFF,&H00000000,&H00000000,-1,0,0,0,100,100,0,0,1,0,0,5,40,40,0,1',
              'Style: Cred,Inter,25,&H00C8BDB4,&H00FFFFFF,&H00000000,&H00000000,0,0,0,0,100,100,0,0,1,0,0,5,40,40,0,1', '',
              '[Events]', 'Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text']
    linhas.append(f'Dialogue: 0,{t_ass(0)},{t_ass(total)},Cred,,0,0,0,,{{\\pos(540,{y + h - 30})}}{credito}')
    for a, b, texto in blocos:
        linhas.append(f'Dialogue: 1,{t_ass(a)},{t_ass(b)},Leg,,0,0,0,,{{\\pos(540,{centro})\\fad(60,40)}}{quebrar(texto)}')
    open(caminho, 'w', encoding='utf-8').write('\n'.join(linhas) + '\n')


def filtro_da_faixa(faixa):
    y, h = faixa
    f = f'drawbox=x=0:y={y}:w=1080:h={h}:color=0x0f1318:t=fill,drawbox=x=0:y={y}:w=1080:h=6:color=0xcc0000:t=fill'
    if y + h < 1350:
        f += f',drawbox=x=0:y={y + h - 6}:w=1080:h=6:color=0xcc0000:t=fill'
    return f


def acertar_exibicao(blocos, total):
    """Cada bloco fica até o próximo começar (se vier logo) ou um instante depois da última letra."""
    for i, b in enumerate(blocos):
        proximo = blocos[i + 1][0] if i + 1 < len(blocos) else total
        b[1] = proximo - 0.02 if proximo - b[1] < 0.7 else min(b[1] + 0.35, proximo - 0.02)
        b[0] = max(0, b[0] - 0.05)
    return blocos


def codificar(entradas, filtro, saida):
    subprocess.check_call(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', *entradas, '-filter_complex', filtro,
                           '-map', '[v]', '-map', '[a]', '-c:v', 'libx264', '-profile:v', 'high', '-preset', 'medium', '-crf', '19',
                           '-maxrate', '8M', '-bufsize', '16M', '-r', '30', '-c:a', 'aac', '-b:a', '192k', '-ar', '44100', '-ac', '2',
                           '-movflags', '+faststart', saida])


def montar_narrado(letra, c):
    textos = [t.replace('|', '') for _, t in c['falas']]
    blocos, audios, cursor = [], [], 0.0
    for i, (alvo, marcado) in enumerate(c['falas']):
        texto, marcas = textos[i], []
        for pedaco in marcado.split('|')[:-1]:
            marcas.append((marcas[-1] if marcas else 0) + len(pedaco))
        mp3, alinhamento = falar(texto, CACHE, ' '.join(textos[:i]), ' '.join(textos[i + 1:]))
        comeco = max(alvo or 0, cursor + (FOLGA if i else 0))
        blocos += blocos_da_fala(texto, alinhamento, comeco, marcas)
        audios.append((mp3, comeco))
        cursor = comeco + duracao(mp3)
    total = min(c['teto'], cursor + 1.8)
    if cursor + 0.3 > c['teto']:
        print(f'  ! {letra}: a narração ({cursor:.1f} s) passa do teto ({c["teto"]} s)')
    ass = f'{SAIDA}/{c["nome"]}.ass'
    escrever_ass(ass, acertar_exibicao(blocos, total), c['faixa'], c['credito'], total)
    origem = f'{PRONTOS}/{c["arquivo"]}_1080x1350.mp4'
    entradas = ['-ss', str(c['inicio']), '-t', f'{total:.2f}', '-i', origem]
    for mp3, _ in audios:
        entradas += ['-i', mp3]
    f = [f"[0:v]{filtro_da_faixa(c['faixa'])},ass='{ass}',fps=30,format=yuv420p[v]"]
    nomes = []
    for n, (_, comeco) in enumerate(audios, start=1):
        ms = int(comeco * 1000)
        f.append(f'[{n}:a]aresample=44100,adelay={ms}|{ms}[n{n}]')
        nomes.append(f'[n{n}]')
    f.append(f"{''.join(nomes)}amix=inputs={len(nomes)}:normalize=0:duration=longest,highpass=f=70,"
             f"acompressor=threshold=-20dB:ratio=2.5:attack=8:release=160:makeup=2,loudnorm=I=-16:TP=-1.5:LRA=9,apad=whole_dur={total:.2f}[voz]")
    if c['original'] > 0:
        f.append(f"[0:a]volume={c['original']}[orig];[voz][orig]amix=inputs=2:normalize=0:duration=first,atrim=0:{total:.2f}[a]")
    else:
        f.append(f'[voz]atrim=0:{total:.2f}[a]')
    saida = f'{SAIDA}/{c["nome"]}_1080x1350.mp4'
    codificar(entradas, ';'.join(f), saida)
    print(f'{letra}: {saida} ({total:.1f} s; narração até {cursor:.1f} s; {sum(len(t) for t in textos)} letras)')


def montar_depoimento(letra, c):
    total = c['fim'] - c['inicio']
    blocos = acertar_exibicao([[a - c['inicio'], b - c['inicio'], t] for a, b, t in c['blocos']], total)
    ass = f'{SAIDA}/{c["nome"]}.ass'
    escrever_ass(ass, blocos, BAIXO, c['credito'], total)
    origem = f'{PRONTOS}/{c["arquivo"]}_1080x1350.mp4'
    f = (f"[0:v]{filtro_da_faixa(BAIXO)},ass='{ass}',fps=30,format=yuv420p[v];"
         f"[0:a]highpass=f=90,afftdn=nr=10:nf=-32,acompressor=threshold=-22dB:ratio=3:attack=6:release=140:makeup=3,"
         f"loudnorm=I=-16:TP=-1.5:LRA=9,afade=t=in:d=0.08,afade=t=out:st={total - 0.15:.2f}:d=0.15[a]")
    saida = f'{SAIDA}/{c["nome"]}_1080x1350.mp4'
    codificar(['-ss', str(c['inicio']), '-t', f'{total:.2f}', '-i', origem], f, saida)
    print(f'{letra}: {saida} ({total:.1f} s)')


if __name__ == '__main__':
    pedidos = sys.argv[1:] or list(NARRADOS) + list(DEPOIMENTOS)
    for letra in pedidos:
        if letra in NARRADOS:
            montar_narrado(letra, NARRADOS[letra])
        else:
            montar_depoimento(letra, DEPOIMENTOS[letra])
