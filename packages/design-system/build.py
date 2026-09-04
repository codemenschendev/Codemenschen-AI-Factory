#!/usr/bin/env python3
"""Build the Claude Design bundle for the Codemenschen house style.

    packages/design-system/build.py [--out dist]

One source of truth: apps/api/resources/design/house.css, the stylesheet every generated prototype
is served with. This script never restyles anything. It inlines that file (and the font) into a
set of preview pages, one per group of classes, so Claude Design can show them as cards and build
new work on the same system the factory uses.

Why previews are authored by hand and not generated from the CSS: a card has to show the class
doing its job with real words in it. Nobody learns what .app-cta is from a grey rectangle.
"""
import argparse, os, re, shutil, sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..'))
DESIGN = os.path.join(ROOT, 'apps', 'api', 'resources', 'design')

MARK = re.compile(r'^<!-- @dsCard[^>]*-->\n')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out', default=os.path.join(HERE, 'dist'))
    args = ap.parse_args()

    house = open(os.path.join(DESIGN, 'house.css'), encoding='utf-8').read()
    font = open(os.path.join(DESIGN, 'font.css'), encoding='utf-8').read()
    if '—' in house:
        sys.exit('house.css chứa em dash')

    out = args.out
    shutil.rmtree(out, ignore_errors=True)
    os.makedirs(os.path.join(out, 'previews'))

    # The stylesheet itself, unchanged, so the design system can be attached from these two
    # files alone and stays byte-identical to what the factory ships.
    shutil.copy2(os.path.join(DESIGN, 'house.css'), os.path.join(out, 'house.css'))
    shutil.copy2(os.path.join(DESIGN, 'font.css'), os.path.join(out, 'font.css'))
    shutil.copy2(os.path.join(HERE, 'README.md'), os.path.join(out, 'README.md'))
    shutil.copy2(os.path.join(DESIGN, 'app-conventions.md'), os.path.join(out, 'app-conventions.md'))

    n = 0
    for name in sorted(os.listdir(os.path.join(HERE, 'previews'))):
        if not name.endswith('.html'):
            continue
        src = open(os.path.join(HERE, 'previews', name), encoding='utf-8').read()
        m = MARK.match(src)
        if not m:
            sys.exit(f'{name}: thiếu marker @dsCard ở dòng đầu')
        if '—' in src:
            sys.exit(f'{name}: chứa em dash')
        marker, body = m.group(0), src[m.end():]

        # Marker first, then the document: the pane reads the card index from line one.
        page = (marker
                + '<!doctype html>\n<html lang="de">\n<head>\n<meta charset="utf-8">\n'
                + '<meta name="viewport" content="width=device-width, initial-scale=1">\n'
                + f'<title>{name[:-5]}</title>\n<style>\n{font}\n{house}\n</style>\n</head>\n'
                + body.rstrip() + '\n</html>\n')
        d = os.path.join(out, 'previews', name[:-5])
        os.makedirs(d)
        open(os.path.join(d, 'index.html'), 'w', encoding='utf-8').write(page)
        n += 1

    print(f'{n} preview -> {out}')


if __name__ == '__main__':
    main()
