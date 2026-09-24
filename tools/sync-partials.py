#!/usr/bin/env python3
"""Copy partials/header.html and partials/footer.html into every page.

Header and footer are inlined into each HTML file (no runtime fetch) so pages
render instantly. After editing a partial, run:

    python3 tools/sync-partials.py
"""
import pathlib
import re

ROOT = pathlib.Path(__file__).resolve().parent.parent
PARTS = {
    'header': (ROOT / 'partials/header.html').read_text(),
    'footer': (ROOT / 'partials/footer.html').read_text(),
}

for page in sorted(ROOT.glob('*.html')):
    html = page.read_text()
    new = html
    for name, block in PARTS.items():
        pattern = re.compile(rf'<!-- @{name} -->.*?<!-- /@{name} -->\n?', re.S)
        new = pattern.sub(lambda _m: block, new)
    if new != html:
        page.write_text(new)
        print('updated', page.name)
