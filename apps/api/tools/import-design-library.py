#!/usr/bin/env python3
"""Import a scraped image library into the design reference library on the server.

    import-design-library.py --source DIR [--dry-run] [--limit N]
                             [--host root@host] [--port 7172] [--no-rejects]

The scraper on this Mac collects everything it sees. Most of it is not worth keeping: the same
screen three times, a 100px thumbnail, an icon. This script decides what earns a place in the
reference library, copies those to the server, and archives everything it turned down in a second
directory so nothing is lost when the local copy is deleted.

Idempotent: an image already in the library is skipped, so the safe move after a new scrape is to
run it again over the whole source. Nothing is ever removed from the library here; a record that
would no longer pass the filter is reported and left alone, because it may already be labelled.
"""
import argparse, json, os, shutil, subprocess, sys, tempfile, time

# What each category is worth keeping for. Anything not listed is archived: an icon or a product
# cutout tells you nothing about how a screen is laid out, which is the only reason this exists.
KEEP = {
    'app-screen': 'app',
    'web-screen': 'web',
    'photo': 'asset',
    'banner': 'asset',
    'advertisement': 'asset',
}

# Below this in either direction a picture is a thumbnail or a hairline, not a reference. The one
# 1800x4 banner in the first import is why the height is checked and not only the width.
MIN_SIDE = 300

# At 700px wide the text on a phone screen is legible and the screen works as a real reference.
# Narrower than that only the composition survives, which is still worth having, and worth saying.
DETAIL_WIDTH = 700

DEFAULT_HOST = 'root@65.108.206.249'
DEFAULT_PORT = '7172'
LIB = '/var/lib/ai-factory/design-library'
REJECTS = '/var/lib/ai-factory/design-library-rejected'


def classify(rec):
    """(medium, grade) for a keeper, or (None, reason) for one we turn down."""
    v = rec['visual']
    cat = v['category']

    # Order matters only for the reason we report; a near duplicate of a too-small image is both.
    if v.get('near_duplicate_of'):
        return None, 'near-duplicate'
    if v.get('is_small_preview'):
        return None, 'small-preview'
    if cat not in KEEP:
        return None, 'category-' + cat
    if v['width'] < MIN_SIDE or v['height'] < MIN_SIDE:
        return None, 'too-small'

    return KEEP[cat], ('detail' if v['width'] >= DETAIL_WIDTH else 'layout')


def sidecar(rec, medium, grade, path):
    """The record as the library stores it: the scrape's own facts, plus an empty labels block.

    `sources` and `ai_index` are carried over untouched. Nothing is invented here; the labelling
    pass is the only thing that ever writes `labels`.
    """
    f, v = rec['file'], rec['visual']

    return {
        'id': os.path.splitext(os.path.basename(f['local_path']))[0],
        'medium': medium,
        'file': path,
        'sha256': f.get('sha256'),
        'perceptual_hash': f.get('perceptual_hash'),
        'mime_type': f.get('mime_type'),
        'byte_size': f.get('byte_size'),
        'visual': {
            'category': v['category'], 'grade': grade,
            'width': v['width'], 'height': v['height'],
            'aspect_ratio': v.get('aspect_ratio'), 'orientation': v.get('orientation'),
        },
        'sources': rec.get('sources', []),
        'ai_index': rec.get('ai_index', {}),
        'labels': {},
    }


def link(src, dst):
    """Hardlink into the staging tree, copy across filesystems. Either way the source is untouched."""
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    if os.path.exists(dst):
        return
    try:
        os.link(src, dst)
    except OSError:
        shutil.copy2(src, dst)


def ssh(host, port, script):
    return subprocess.run(['ssh', '-p', port, host, script], capture_output=True, text=True)


def existing_ids(host, port):
    """What the library already holds. Read from the sidecars, which are the source of truth."""
    r = ssh(host, port, f'ls {LIB}/images/*/*/*.json 2>/dev/null | xargs -n1 basename 2>/dev/null')
    return {line[:-5] for line in r.stdout.split() if line.endswith('.json')}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--source', required=True, help='the scraped library (holds ai_catalog.json)')
    ap.add_argument('--host', default=DEFAULT_HOST)
    ap.add_argument('--port', default=DEFAULT_PORT)
    ap.add_argument('--limit', type=int, default=0)
    ap.add_argument('--dry-run', action='store_true', help='count and print, upload nothing')
    ap.add_argument('--no-rejects', action='store_true', help='do not archive what was turned down')
    args = ap.parse_args()

    catalog = os.path.join(args.source, 'ai_catalog.json')
    if not os.path.isfile(catalog):
        sys.exit(f'LỖI: không thấy {catalog}')

    records = json.load(open(catalog, encoding='utf-8'))['images']
    print(f'nguồn: {len(records)} ảnh')

    have = set() if args.dry_run else existing_ids(args.host, args.port)
    print(f'thư viện đang có: {len(have)} ảnh')

    stage = tempfile.mkdtemp(prefix='design-import-')
    kept, rejected, skipped, missing = {}, {}, 0, 0

    for rec in records:
        src = rec['file'].get('absolute_path') or os.path.join(args.source, rec['file']['local_path'])
        rid = os.path.splitext(os.path.basename(rec['file']['local_path']))[0]
        medium, verdict = classify(rec)

        if not os.path.isfile(src):
            missing += 1
            continue
        if medium and rid in have:
            skipped += 1
            continue
        if args.limit and len(kept) + len(rejected) >= args.limit:
            break

        ext = os.path.splitext(src)[1] or '.webp'
        if medium:
            rel = f"images/{rec['visual']['category']}/{verdict}/{rid}{ext}"
            kept[rid] = verdict
            if not args.dry_run:
                link(src, os.path.join(stage, 'keep', rel))
                with open(os.path.join(stage, 'keep', rel[:-len(ext)] + '.json'), 'w', encoding='utf-8') as fh:
                    json.dump(sidecar(rec, medium, verdict, rel), fh, indent=1, ensure_ascii=False)
        else:
            rejected[rid] = verdict
            if not args.dry_run and not args.no_rejects:
                rel = f'{verdict}/{rid}{ext}'
                link(src, os.path.join(stage, 'reject', rel))
                with open(os.path.join(stage, 'reject', rel[:-len(ext)] + '.json'), 'w', encoding='utf-8') as fh:
                    json.dump({'id': rid, 'rejected_for': verdict, 'visual': rec['visual'],
                               'sources': rec.get('sources', [])}, fh, indent=1, ensure_ascii=False)

    def tally(d):
        out = {}
        for v in d.values():
            out[v] = out.get(v, 0) + 1
        return dict(sorted(out.items(), key=lambda kv: -kv[1]))

    print(f'\ngiữ lại  {len(kept):5} {tally(kept)}')
    print(f'loại ra  {len(rejected):5} {tally(rejected)}')
    print(f'đã có    {skipped:5}')
    if missing:
        print(f'thiếu file {missing}')

    if args.dry_run:
        shutil.rmtree(stage, ignore_errors=True)
        print('\n--dry-run: chưa tải lên.')
        return

    for sub, dest in (('keep', LIB), ('reject', REJECTS)):
        path = os.path.join(stage, sub)
        if not os.path.isdir(path):
            continue
        print(f'\nrsync {sub} -> {dest}', flush=True)
        subprocess.run(['ssh', '-p', args.port, args.host, f'mkdir -p {dest}'], check=True)
        # --stats, not --info=stats1: macOS still ships rsync 2.6.9, which has never heard of it.
        subprocess.run(['rsync', '-a', '--stats', '-e', f'ssh -p {args.port}',
                        path + '/', f'{args.host}:{dest}/'], check=True)

    shutil.rmtree(stage, ignore_errors=True)

    print('\ndựng lại catalog.json + index/ ...', flush=True)
    r = ssh(args.host, args.port,
            f'python3 {os.path.dirname(LIB)}/label-design-library.py --root {LIB} --rebuild-only')
    print(r.stdout.strip() or r.stderr.strip())

    # A record already in the library that today's filter would turn down. Never deleted here: it
    # may carry labels somebody spent a vision call on, so this is a report, not an action.
    stale = ssh(args.host, args.port, f'''python3 - <<'EOF'
import json
c = json.load(open("{LIB}/catalog.json"))
bad = [r["id"] for r in c["images"]
       if r["visual"]["width"] < {MIN_SIDE} or r["visual"]["height"] < {MIN_SIDE}
       or r["visual"]["category"] not in {sorted(KEEP)!r}]
print(" ".join(bad) if bad else "")
EOF''')
    if stale.stdout.strip():
        print(f'\nđã nằm trong thư viện nhưng nay không đạt: {stale.stdout.strip()}')

    print(f'\nxong lúc {time.strftime("%H:%M:%S")}')


if __name__ == '__main__':
    main()
