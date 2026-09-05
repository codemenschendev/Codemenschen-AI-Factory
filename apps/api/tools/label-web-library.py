#!/usr/bin/env python3
"""Label the web half of the design library, so a landing page brief can be answered with a page.

    label-web-library.py [--root DIR] [--limit N] [--workers N] [--force] [--dry-run]
                         [--rebuild-only]

Third sibling of label-design-library.py and label-ad-library.py, and again deliberately not the
same vocabulary. An app screen is catalogued by what it is FOR; an ad by how it PERSUADES; a
landing page by HOW IT IS BUILT, because that is the decision the generator actually makes: what
the hero holds, which three sections follow, how airy it is, light or dark.

Every term below maps onto something house.css can draw. A label the stylesheet cannot express
teaches nothing: it would name a shape the generator has no way to make.

Only detail-grade web screens are labelled. At 360px a landing page is a blur, and the section
order is the whole point.
"""
import argparse, base64, json, os, re, sys, threading, time
import urllib.request, urllib.error
from concurrent.futures import ThreadPoolExecutor

DEFAULT_ROOT = '/var/lib/ai-factory/design-library'
ENV_FILE = '/var/www/ai-factory/apps/api/.env'

PAGE_TYPES = [
    'landing', 'product', 'pricing', 'about', 'contact', 'portfolio', 'blog_index', 'article',
    'docs', 'signup_login', 'dashboard', 'shop', 'other',
]
INDUSTRIES = [
    'food_delivery', 'restaurant', 'retail_ecommerce', 'fashion', 'beauty_salon', 'health_fitness',
    'medical', 'finance_banking', 'travel', 'transport_mobility', 'real_estate', 'education',
    'productivity', 'events_culture', 'trades_crafts', 'business_saas', 'agency', 'other',
]
# What the first screen holds. These are the shapes .hero and .hero-split can actually take.
HERO_STYLES = [
    'split_text_visual',   # words one side, a picture or a mock the other: .hero-split
    'centered_text',       # headline in the middle, nothing beside it
    'full_bleed_image',    # a photograph behind the words
    'product_shot',        # the thing itself, large, on a plain ground
    'no_hero',             # the page starts straight into content
]
# What is IN the hero's second column, which is exactly the choice the SITE prompt offers.
HERO_VISUALS = ['browser_mock', 'phone_mock', 'card_stack', 'photograph', 'illustration', 'none']
# The sections underneath. The generator picks three, so these are what it is picking from.
SECTIONS = [
    'logo_row', 'feature_grid', 'split_text_image', 'stats', 'testimonial', 'pricing_table',
    'faq', 'steps', 'team', 'gallery', 'cta_band', 'newsletter', 'comparison', 'blog_teaser',
]

PROMPT = """You are cataloguing one web page for a reference library that a page generator learns
from. Look at the image and answer with ONE JSON object and nothing else: no prose, no code fence.

{"page_type":"...","industry":"...","hero_style":"...","hero_visual":"...","sections":["..."],
 "density":"...","palette":{"scheme":"light|dark","accent":"..."},"nav_cta":true,"notes":"..."}

- page_type: exactly one of %s
- industry: exactly one of %s
- hero_style: what the FIRST screen does. Exactly one of %s
- hero_visual: what sits beside or behind the headline. Exactly one of %s
- sections: the blocks BELOW the hero, in the order they appear, two to six of %s
- density: sparse (a lot of air, few things), medium, or dense (much on screen at once)
- palette.scheme: is the FIRST screen light or dark. palette.accent: one plain colour word.
- nav_cta: true if the navigation bar carries a button, not only links
- notes: at most 12 words on what a designer should copy from this page

Use only the values listed. Answer null rather than guessing. Answer in English.
""" % (PAGE_TYPES, INDUSTRIES, HERO_STYLES, HERO_VISUALS, SECTIONS)

print_lock = threading.Lock()


def read_env(path):
    """Base URL and token from the api .env. Never printed, only sent."""
    out = {}
    if os.path.exists(path):
        for line in open(path, encoding='utf-8', errors='replace'):
            if '=' in line and not line.lstrip().startswith('#'):
                k, _, v = line.strip().partition('=')
                out[k] = v.strip().strip('"').strip("'")
    return {
        'base': os.environ.get('AI_IMAGE_SERVICE_BASE_URL') or out.get('AI_IMAGE_SERVICE_BASE_URL', ''),
        'token': os.environ.get('AI_IMAGE_SERVICE_TOKEN') or out.get('AI_IMAGE_SERVICE_TOKEN', ''),
        'model': os.environ.get('AI_CHAT_TARGET') or out.get('AI_CHAT_TARGET', ''),
        'backend': os.environ.get('AI_CHAT_BACKEND_MODEL') or out.get('AI_CHAT_BACKEND_MODEL', ''),
    }


def ask(cfg, image_path, timeout=120):
    b64 = base64.b64encode(open(image_path, 'rb').read()).decode()
    ext = os.path.splitext(image_path)[1].lower()
    mime = {'.webp': 'image/webp', '.png': 'image/png'}.get(ext, 'image/jpeg')
    body = {'messages': [{'role': 'user', 'content': [
        {'type': 'text', 'text': PROMPT},
        {'type': 'image_url', 'image_url': {'url': f'data:{mime};base64,{b64}'}},
    ]}]}
    if cfg['model']:
        body['model'] = cfg['model']
    req = urllib.request.Request(
        cfg['base'].rstrip('/') + '/v1/chat/completions',
        data=json.dumps(body).encode(),
        headers={'authorization': 'Bearer ' + cfg['token'], 'content-type': 'application/json',
                 **({'x-openclaw-model': cfg['backend']} if cfg['backend'] else {})},
    )
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.loads(r.read())['choices'][0]['message']['content']


def parse(text):
    m = re.search(r'\{.*\}', text or '', re.S)
    if not m:
        return None
    try:
        d = json.loads(m.group(0))
    except json.JSONDecodeError:
        return None

    def one_of(v, allowed):
        return v if isinstance(v, str) and v in allowed else None

    pal = d.get('palette') if isinstance(d.get('palette'), dict) else {}

    return {
        'page_type': one_of(d.get('page_type'), PAGE_TYPES),
        'industry': one_of(d.get('industry'), INDUSTRIES),
        'hero_style': one_of(d.get('hero_style'), HERO_STYLES),
        'hero_visual': one_of(d.get('hero_visual'), HERO_VISUALS),
        # Order matters here and is kept: the generator writes three sections in a sequence.
        'sections': [s for s in (d.get('sections') or []) if s in SECTIONS][:6],
        'density': one_of(d.get('density'), ['sparse', 'medium', 'dense']),
        'palette': {'scheme': one_of(pal.get('scheme'), ['light', 'dark']),
                    'accent': (str(pal.get('accent'))[:20] if pal.get('accent') else None)},
        'nav_cta': d.get('nav_cta') if isinstance(d.get('nav_cta'), bool) else None,
        'notes': (str(d['notes'])[:160] if d.get('notes') else None),
    }


def sidecars(root):
    """Web pages only, and only the ones big enough to read. A 360px page is a blur."""
    for dirpath, _, files in os.walk(os.path.join(root, 'images')):
        for name in sorted(files):
            if not name.endswith('.json'):
                continue
            path = os.path.join(dirpath, name)
            rec = json.load(open(path, encoding='utf-8'))
            if rec.get('medium') != 'web' or rec.get('visual', {}).get('grade') != 'detail':
                continue
            yield path, rec


def rebuild(root):
    """catalog.json and index/ are derived: written from the sidecars, never by hand."""
    recs = []
    for dirpath, _, files in os.walk(os.path.join(root, 'images')):
        for name in sorted(files):
            if name.endswith('.json'):
                recs.append(json.load(open(os.path.join(dirpath, name), encoding='utf-8')))
    recs.sort(key=lambda r: r['id'])
    cat_path = os.path.join(root, 'catalog.json')
    cat = json.load(open(cat_path, encoding='utf-8')) if os.path.exists(cat_path) else {}
    cat.update({'schema': 'design-library/1', 'count': len(recs), 'images': recs,
                'rebuilt_at': time.strftime('%Y-%m-%dT%H:%M:%S%z')})
    json.dump(cat, open(cat_path, 'w', encoding='utf-8'), indent=1, ensure_ascii=False)

    os.makedirs(os.path.join(root, 'index'), exist_ok=True)
    views = {
        'by-medium': lambda r: [r.get('medium') or 'unknown'],
        'by-category': lambda r: [r['visual']['category']],
        'by-grade': lambda r: [r['visual']['grade']],
        'by-orientation': lambda r: [r['visual']['orientation']],
        'by-domain': lambda r: [r['sources'][0]['website']['domain'] if r.get('sources') else 'unknown'],
        'by-screen-type': lambda r: [r['labels'].get('screen_type') or 'unlabelled'],
        'by-industry': lambda r: [r['labels'].get('industry') or 'unlabelled'],
        'by-pattern': lambda r: (r['labels'].get('layout_patterns') or ['unlabelled']),
        'by-angle': lambda r: [r['labels'].get('angle') or 'unlabelled'],
        'by-format': lambda r: [r['labels'].get('format') or 'unlabelled'],
        'by-page-type': lambda r: [r['labels'].get('page_type') or 'unlabelled'],
        'by-hero-style': lambda r: [r['labels'].get('hero_style') or 'unlabelled'],
        'by-section': lambda r: (r['labels'].get('sections') or ['unlabelled']),
    }
    for name, keys in views.items():
        buckets = {}
        for r in recs:
            for k in keys(r):
                buckets.setdefault(k, []).append(r['id'])
        json.dump(dict(sorted(buckets.items())), open(os.path.join(root, 'index', name + '.json'), 'w'), indent=1)
    return len(recs)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--root', default=DEFAULT_ROOT)
    ap.add_argument('--env', default=ENV_FILE)
    ap.add_argument('--limit', type=int, default=0)
    ap.add_argument('--workers', type=int, default=4)
    ap.add_argument('--force', action='store_true', help='relabel pages that already have labels')
    ap.add_argument('--dry-run', action='store_true', help='label but write nothing')
    ap.add_argument('--rebuild-only', action='store_true', help='rebuild catalog + index, no vision calls')
    args = ap.parse_args()

    if args.rebuild_only:
        print(f'catalog.json + index/ dựng lại từ {rebuild(args.root)} bản ghi', flush=True)
        return

    cfg = read_env(args.env)
    if not cfg['base'] or not cfg['token']:
        sys.exit('LỖI: chưa có AI_IMAGE_SERVICE_BASE_URL / AI_IMAGE_SERVICE_TOKEN.')

    todo = [(p, r) for p, r in sidecars(args.root)
            if args.force or (r.get('labels') or {}).get('page_type') is None]
    if args.limit:
        todo = todo[:args.limit]
    print(f'{len(todo)} trang web cần gán nhãn', flush=True)

    done = {'ok': 0, 'failed': 0}

    def work(item):
        path, rec = item
        img = os.path.join(args.root, rec['file'])
        try:
            labels = parse(ask(cfg, img))
        except (urllib.error.URLError, OSError, KeyError, ValueError) as e:
            labels, err = None, str(e)[:120]
        else:
            err = 'không phân tích được câu trả lời' if labels is None else None

        with print_lock:
            if labels is None:
                done['failed'] += 1
                print(f'  ✗ {rec["id"]}: {err}', flush=True)
                return
            done['ok'] += 1
            print(f'  ✓ {rec["id"]}: {labels["page_type"]} · {labels["hero_style"]} · '
                  f'{labels["hero_visual"]} · {",".join(labels["sections"][:3])}', flush=True)
        if not args.dry_run:
            # Merge, never replace: a page may already carry labels from another pass.
            rec['labels'] = {**(rec.get('labels') or {}), **labels}
            rec['labelled_at'] = time.strftime('%Y-%m-%dT%H:%M:%S%z')
            json.dump(rec, open(path, 'w', encoding='utf-8'), indent=1, ensure_ascii=False)

    with ThreadPoolExecutor(max_workers=max(1, args.workers)) as pool:
        list(pool.map(work, todo))

    print(f'xong: {done["ok"]} gán nhãn, {done["failed"]} lỗi', flush=True)
    if not args.dry_run:
        print(f'catalog.json + index/ dựng lại từ {rebuild(args.root)} bản ghi', flush=True)


if __name__ == '__main__':
    main()
