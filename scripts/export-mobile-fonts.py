#!/usr/bin/env python3
"""Converts the web's @fontsource .woff files into .ttf files bundled with the Flutter app, so both platforms use the
same typefaces and nothing is downloaded at runtime. Fonts are SIL OFL 1.1 (licences copied next to them).

    python3 scripts/export-mobile-fonts.py        (needs: pip install fonttools; npm ci)
"""
import os, shutil
from fontTools.ttLib import TTFont

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
SRC = os.path.join(ROOT, 'node_modules', '@fontsource')
OUT = os.path.join(ROOT, 'mobile', 'assets', 'fonts')

# (package, weight) - exactly the weights app.css imports.
FONTS = [('orbitron', 500), ('orbitron', 700), ('orbitron', 900), ('rajdhani', 600), ('rajdhani', 700),
         ('inter', 400), ('inter', 500), ('inter', 600), ('inter', 700)]

os.makedirs(OUT, exist_ok=True)
for pkg, weight in FONTS:
    src = os.path.join(SRC, pkg, 'files', f'{pkg}-latin-{weight}-normal.woff')
    font = TTFont(src)
    font.flavor = None  # drop the WOFF wrapper -> plain TrueType/OpenType
    ext = 'otf' if 'CFF ' in font else 'ttf'
    dst = os.path.join(OUT, f'{pkg}-{weight}.{ext}')
    font.save(dst)
    print('wrote', os.path.relpath(dst, ROOT), os.path.getsize(dst) // 1024, 'KB')

for pkg in ('orbitron', 'rajdhani', 'inter'):
    shutil.copyfile(os.path.join(SRC, pkg, 'LICENSE'), os.path.join(OUT, f'LICENSE-{pkg}.txt'))
