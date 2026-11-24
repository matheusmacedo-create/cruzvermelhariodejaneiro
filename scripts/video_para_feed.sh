#!/usr/bin/env bash
# Converte um vídeo qualquer para o formato do feed do Instagram que também serve de anúncio (tráfego):
# 1080 x 1350 (4:5), H.264 + AAC, 30 quadros por segundo, no máximo 60 s, em MP4 pronto para envio.
#
# Por que 4:5: é a maior área que o feed aceita e a mesma medida que a Meta aceita no anúncio de feed e
# nos cartões de carrossel. Num carrossel, todos os cartões precisam ter a mesma medida: converta todos por aqui.
#
# Uso:
#   scripts/video_para_feed.sh entrada.mp4 saida.mp4            # quadro inteiro, com fundo desfocado
#   scripts/video_para_feed.sh entrada.mp4 saida.mp4 cortar     # preenche a tela, cortando as bordas
#   INICIO=3 DURACAO=25 scripts/video_para_feed.sh entrada.mp4 saida.mp4   # começa no segundo 3, dura 25 s
#   ALINHAR=topo scripts/video_para_feed.sh entrada.mp4 saida.mp4 cortar   # no corte, fica com o alto do quadro
#
# "fundo" (padrão) mantém a cena toda visível, o que importa em imagem de câmera de segurança; "cortar"
# dá mais impacto quando a ação está no centro. No corte de um vídeo em pé, ALINHAR escolhe a parte que
# fica: topo, centro (padrão) ou base, útil quando há uma faixa de legenda a tirar. Vídeo sem som ganha uma
# faixa de silêncio.
# Precisa de ffmpeg e ffprobe. Não envia nada a lugar nenhum.
set -euo pipefail

entrada="${1:?informe o vídeo de entrada}"
saida="${2:?informe o arquivo de saída (.mp4)}"
modo="${3:-fundo}"
inicio="${INICIO:-0}"
duracao="${DURACAO:-60}"
alinhar="${ALINHAR:-centro}"
L=1080
A=1350

[ -f "$entrada" ] || { echo "arquivo não encontrado: $entrada" >&2; exit 2; }
case "$modo" in fundo | cortar) ;; *) echo "modo desconhecido: $modo (use fundo ou cortar)" >&2; exit 2 ;; esac
case "$alinhar" in
  topo) y=0 ;;
  centro) y="(ih-${A})/2" ;;
  base) y="ih-${A}" ;;
  *) echo "ALINHAR desconhecido: $alinhar (use topo, centro ou base)" >&2; exit 2 ;;
esac
awk -v d="$duracao" 'BEGIN { exit !(d > 0 && d <= 60) }' || { echo "DURACAO precisa ficar entre 1 e 60 segundos" >&2; exit 2; }

if [ "$modo" = cortar ]; then
  filtro="[0:v]scale=${L}:${A}:force_original_aspect_ratio=increase,crop=${L}:${A}:(iw-${L})/2:${y},setsar=1,fps=30,format=yuv420p[v]"
else
  filtro="[0:v]split=2[f][q];[f]scale=${L}:${A}:force_original_aspect_ratio=increase,crop=${L}:${A},boxblur=24:2,eq=brightness=-0.08[fundo];[q]scale=${L}:${A}:force_original_aspect_ratio=decrease[frente];[fundo][frente]overlay=(W-w)/2:(H-h)/2,setsar=1,fps=30,format=yuv420p[v]"
fi

tem_audio=$(ffprobe -v error -select_streams a:0 -show_entries stream=codec_type -of csv=p=0 "$entrada" | head -1)
if [ -n "$tem_audio" ]; then
  audio=(-map "[v]" -map 0:a:0)
  entradas=(-ss "$inicio" -t "$duracao" -i "$entrada")
else
  audio=(-map "[v]" -map 1:a:0 -shortest)
  entradas=(-ss "$inicio" -t "$duracao" -i "$entrada" -f lavfi -t "$duracao" -i anullsrc=channel_layout=stereo:sample_rate=44100)
fi

ffmpeg -hide_banner -loglevel error -y "${entradas[@]}" \
  -filter_complex "$filtro" "${audio[@]}" \
  -c:v libx264 -profile:v high -preset medium -crf 20 -maxrate 8M -bufsize 16M \
  -c:a aac -b:a 128k -ar 44100 -ac 2 \
  -movflags +faststart "$saida"

ffprobe -v error -show_entries stream=codec_name,width,height,r_frame_rate:format=duration,size \
  -of default=noprint_wrappers=1 "$saida" | tr '\n' ' '
echo
echo "pronto: $saida"
