"""Writes PNG test cases and the expected RGB/alpha bytes for PngImage.php.

    python tests/make_png_cases.py [outdir]   (default: tests/fixtures/png)
"""
import json, os, random, struct, sys, zlib
from PIL import Image

out = sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(os.path.abspath(__file__)), "fixtures", "png")
os.makedirs(out, exist_ok=True)
random.seed(7)
cases = []


def expected(img):
    rgba = img.convert('RGBA')
    raw = rgba.tobytes()
    rgb = bytes(b for i, b in enumerate(raw) if i % 4 != 3)
    alpha = raw[3::4]
    return rgb, (alpha if any(a != 255 for a in alpha) else None)


def add(name, img, **save):
    path = os.path.join(out, name + '.png')
    img.save(path, **save)
    rgb, alpha = expected(Image.open(path))
    open(os.path.join(out, name + '.rgb'), 'wb').write(rgb)
    if alpha is not None:
        open(os.path.join(out, name + '.alpha'), 'wb').write(alpha)
    cases.append({'name': name, 'alpha': alpha is not None, 'readable': True})


W, H = 37, 23
base = Image.new('RGBA', (W, H))
base.putdata([(random.randrange(256), random.randrange(256), random.randrange(256),
               random.choice([0, 128, 255, 255])) for _ in range(W * H)])
add('rgba8', base)
add('rgb8', base.convert('RGB'))
add('gray8', base.convert('L'))
add('graya8', base.convert('LA'))
pal = base.convert('RGB').quantize(16)
add('palette4bit', pal, bits=4)
pal_t = base.convert('RGB').quantize(200)
add('palette_trns', pal_t, transparency=3)
add('bilevel', base.convert('1'))
add('rgb_trns', base.convert('RGB'), transparency=base.convert('RGB').getpixel((0, 0)))
# 16-bit grey
g16 = Image.new('I;16', (W, H))
g16.putdata([random.randrange(65536) for _ in range(W * H)])
path = os.path.join(out, 'gray16.png'); g16.save(path)
vals = list(Image.open(path).get_flattened_data()) if hasattr(Image.Image, "get_flattened_data") else list(Image.open(path).getdata())
open(os.path.join(out, 'gray16.rgb'), 'wb').write(bytes(v >> 8 for v in vals for _ in range(3)))
cases.append({'name': 'gray16', 'alpha': False, 'readable': True})


# Hand-made RGBA with every filter type, so all unfilter paths run.
def png(width, height, color, depth, rows, filters):
    def chunk(t, d):
        return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xFFFFFFFF)
    bpp = 4
    raw = b''
    prev = bytes(len(rows[0]))
    for y, row in enumerate(rows):
        f = filters[y % len(filters)]
        line = bytearray()
        for i, x in enumerate(row):
            a = row[i - bpp] if i >= bpp else 0
            b = prev[i]
            c = prev[i - bpp] if i >= bpp else 0
            if f == 0: p = 0
            elif f == 1: p = a
            elif f == 2: p = b
            elif f == 3: p = (a + b) >> 1
            else:
                pp = a + b - c; pa, pb, pc = abs(pp - a), abs(pp - b), abs(pp - c)
                p = a if pa <= pb and pa <= pc else (b if pb <= pc else c)
            line.append((x - p) & 0xFF)
        raw += bytes([f]) + bytes(line)
        prev = row
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', width, height, depth, color, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b''))


rows = [bytes(base.tobytes()[y * W * 4:(y + 1) * W * 4]) for y in range(H)]
open(os.path.join(out, 'filters.png'), 'wb').write(png(W, H, 6, 8, rows, [0, 1, 2, 3, 4]))
rgb, alpha = expected(base)
open(os.path.join(out, 'filters.rgb'), 'wb').write(rgb)
open(os.path.join(out, 'filters.alpha'), 'wb').write(alpha)
cases.append({'name': 'filters', 'alpha': True, 'readable': True})

# Interlaced and broken files must be refused, not crash.
data = png(W, H, 6, 8, rows, [0])
inter = bytearray(data); inter[8 + 8 + 12] = 1  # interlace byte of IHDR
open(os.path.join(out, 'interlaced.png'), 'wb').write(bytes(inter))
open(os.path.join(out, 'truncated.png'), 'wb').write(data[:60])
open(os.path.join(out, 'notpng.png'), 'wb').write(b'GIF89a not a png')
for n in ('interlaced', 'truncated', 'notpng'):
    cases.append({'name': n, 'readable': False})

json.dump(cases, open(os.path.join(out, 'cases.json'), 'w'), indent=1)
print(len(cases), 'cases')
