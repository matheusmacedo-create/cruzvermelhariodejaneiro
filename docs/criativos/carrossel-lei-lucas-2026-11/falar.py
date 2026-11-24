#!/usr/bin/env python3
"""Fala um texto pela ElevenLabs (voz "Sarah", a da filial; modelo de narração do projeto) e guarda MP3 + tempos.
A chave não passa por aqui: o proxy do ambiente põe o cabeçalho."""
import base64, hashlib, json, os, sys, urllib.request
API = 'https://api.elevenlabs.io'
VOZ = os.environ.get('VOZ_ID', 'EXAVITQu4vr4xnSDxMaL')
MODELO = os.environ.get('MODELO', 'eleven_v4')
def falar(texto, pasta, anterior='', seguinte=''):
    chave = hashlib.sha256('\0'.join([VOZ, MODELO, texto, anterior, seguinte]).encode()).hexdigest()[:20]
    mp3, js = f'{pasta}/{chave}.mp3', f'{pasta}/{chave}.json'
    if not (os.path.exists(mp3) and os.path.exists(js)):
        corpo = {'text': texto, 'model_id': MODELO}
        if anterior: corpo['previous_text'] = anterior[-1000:]
        if seguinte: corpo['next_text'] = seguinte[:1000]
        req = urllib.request.Request(f'{API}/v1/text-to-speech/{VOZ}/with-timestamps?output_format=mp3_44100_128',
            data=json.dumps(corpo).encode(), headers={'xi-api-key': 'posta-pelo-proxy', 'content-type': 'application/json'})
        try:
            d = json.load(urllib.request.urlopen(req, timeout=180))
        except urllib.error.HTTPError as e:
            raise SystemExit(f'ElevenLabs {e.code}: {e.read()[:400].decode("utf8", "replace")}')
        open(mp3, 'wb').write(base64.b64decode(d['audio_base64']))
        json.dump({'texto': texto, 'alinhamento': d.get('alignment')}, open(js, 'w'))
    return mp3, json.load(open(js))['alinhamento']
if __name__ == '__main__':
    mp3, a = falar(sys.argv[1], sys.argv[2])
    print(mp3, 'letras:', len(a['characters']) if a else None, 'texto:', len(sys.argv[1]), 'fim:', a['character_end_times_seconds'][-1] if a else None)
