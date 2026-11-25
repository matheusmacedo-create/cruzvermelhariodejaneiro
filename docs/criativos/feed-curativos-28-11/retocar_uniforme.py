"""Troca os emblemas redondos da foto do curso pela marca oficial (logo.png), na manga e no peito das camisas.
Uso: python3 retocar_uniforme.py ../banners-turmas-nov-dez-2026/img/curativos.jpg img/logo.png img/curativos.jpg"""
import sys
import numpy as np
from PIL import Image, ImageDraw, ImageFilter

foto, logo_arq, saida = sys.argv[1:4]
E = 2  # trabalha no dobro do tamanho, para a marca não virar borrão
im = Image.open(foto).convert("RGB")
im = im.resize((im.width * E, im.height * E), Image.LANCZOS)
a = np.asarray(im).astype(np.float64)
logo = Image.open(logo_arq).convert("RGBA")

def apagar(cx, cy, r):
    """Preenche o círculo com o tecido em volta (difusão a partir da borda)."""
    cx, cy, r = cx * E, cy * E, r * E
    m = 6 * E
    x0, x1, y0, y1 = int(cx - r - m), int(cx + r + m), int(cy - r - m), int(cy + r + m)
    reg = a[y0:y1, x0:x1].copy()
    yy, xx = np.mgrid[y0:y1, x0:x1]
    furo = (xx - cx) ** 2 + (yy - cy) ** 2 <= r ** 2
    reg[furo] = reg[~furo].mean(axis=0)
    for _ in range(4000):
        viz = (np.roll(reg, 1, 0) + np.roll(reg, -1, 0) + np.roll(reg, 1, 1) + np.roll(reg, -1, 1)) / 4
        reg[furo] = viz[furo]
    # um pouco do grão da foto de volta, para o remendo não ficar liso demais
    rng = np.random.default_rng(7)
    reg[furo] += rng.normal(0, 1.2, reg[furo].shape)
    a[y0:y1, x0:x1] = reg

def aplicar(cx, cy, largura, angulo, desfoque, fundo_branco):
    """Cola a marca centrada em (cx, cy): direto no tecido branco, ou num retângulo branco no tecido vermelho."""
    global a
    w = int(largura * E)
    h = round(w * logo.height / logo.width)
    marca = logo.resize((w, h), Image.LANCZOS)
    if fundo_branco:
        px, py = round(h * .28), round(h * .30)
        peca = Image.new("RGBA", (w + 2 * px, h + 2 * py), (0, 0, 0, 0))
        ImageDraw.Draw(peca).rounded_rectangle([0, 0, peca.width - 1, peca.height - 1], radius=round(h * .22), fill=(240, 237, 237, 255))
        peca.alpha_composite(marca, (px, py))
    else:
        peca = marca
    peca = peca.rotate(angulo, resample=Image.BICUBIC, expand=True)
    if desfoque:
        folga = int(desfoque * 4)
        maior = Image.new("RGBA", (peca.width + 2 * folga, peca.height + 2 * folga), (0, 0, 0, 0))
        maior.alpha_composite(peca, (folga, folga))
        peca = maior.filter(ImageFilter.GaussianBlur(desfoque))
    x0, y0 = round(cx * E - peca.width / 2), round(cy * E - peca.height / 2)
    p = np.asarray(peca).astype(np.float64)
    alfa = p[..., 3:4] / 255
    base = a[y0:y0 + peca.height, x0:x0 + peca.width]
    # a luz do tecido passa para a marca (multiplica pela claridade local), para ela seguir as dobras e a sombra
    claro = base.max(axis=2, keepdims=True) / 255
    sombra = np.clip(claro / max(np.percentile(claro, 90), 1e-6), .75, 1.0)
    a[y0:y0 + peca.height, x0:x0 + peca.width] = base * (1 - alfa) + p[..., :3] * sombra * alfa

# posições medidas na foto de 1000x667: (centro x, centro y, raio)
apagar(83.5, 214, 34)     # manga da instrutora
apagar(666, 235, 20)    # peito da aluna
apagar(939, 244, 22)    # peito do aluno
aplicar(103, 217, 84, -14, .6, False)
aplicar(668, 236, 46, 0, 1.8, True)
aplicar(940, 245, 50, 0, 1.9, True)

Image.fromarray(np.clip(a, 0, 255).astype(np.uint8)).save(saida, "JPEG", quality=92, optimize=True)
