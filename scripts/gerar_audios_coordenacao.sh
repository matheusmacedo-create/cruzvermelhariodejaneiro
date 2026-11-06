#!/usr/bin/env bash
# Grava os áudios do tutorial da página da coordenação (api/coordenacao.php, ?ajuda=1) com a voz da ElevenLabs que a
# Redação usa (voz_config: "Sarah", eleven_v4). As falas saem de co_passos() (3º item de cada passo). Rode de novo
# sempre que o texto de um passo mudar, e publique os arquivos (site/assets/ não entra no Git; fica só no servidor):
#   scripts/publicar_hostinger.sh site/assets/coordenacao/passo-*.mp3
# Precisa da chave da ElevenLabs em ELEVENLABS_API_KEY (ou injetada pelo proxy da sessão, como nas sessões do Claude).
set -euo pipefail
cd "$(dirname "$0")/.."
VOZ="${VOZ:-EXAVITQu4vr4xnSDxMaL}"
MODELO="${MODELO:-eleven_v4}"
DESTINO=site/assets/coordenacao
mkdir -p "$DESTINO"
falas=$(mktemp -d)
php -r '$s=file_get_contents("site/matricula-cursos-presenciais/api/coordenacao.php");
preg_match("/function co_passos\(\): array\n\{\n(.*?)\n\}\n/s",$s,$m) or exit(1);
foreach (eval($m[1]) as $i=>$p) { file_put_contents($argv[1]."/".($i+1).".json", json_encode(["text"=>$p[2],"model_id"=>$argv[2],"language_code"=>"pt"], JSON_UNESCAPED_UNICODE)); }' "$falas" "$MODELO"
for corpo in "$falas"/*.json; do
  n=$(basename "$corpo" .json)
  curl -sS --fail -X POST "https://api.elevenlabs.io/v1/text-to-speech/$VOZ?output_format=mp3_44100_128" \
    -H "Content-Type: application/json" ${ELEVENLABS_API_KEY:+-H "xi-api-key: $ELEVENLABS_API_KEY"} \
    --data @"$corpo" -o "$DESTINO/passo-$n.mp3"
  echo "gravado $DESTINO/passo-$n.mp3"
done
rm -rf "$falas"
