#!/usr/bin/env python3
"""Label the ad half of the design library, so a brief can be answered with an ad like it.

    label-ad-library.py [--root DIR] [--limit N] [--workers N] [--force] [--dry-run]
                        [--rebuild-only]

Sibling of label-design-library.py and deliberately not the same script. An app screen is
catalogued by what it is FOR (onboarding, checkout, chat); an ad is catalogued by how it PERSUADES.
Running the app vocabulary over a poster would fill the library with the word "other".

The angles are the seven the copywriter already writes to, in AdScriptWriter::ANGLES. They are
repeated here rather than derived, because this script runs on the host with no PHP; a test in the
suite fails if the two lists ever drift apart.

Format is measured, not asked: the aspect ratio says which platform slot an ad was cut for, and a
model guessing at it would be guessing at arithmetic.
"""
import argparse, base64, json, os, re, sys, threading, time
import urllib.request, urllib.error
from concurrent.futures import ThreadPoolExecutor

DEFAULT_ROOT = '/var/lib/ai-factory/design-library'
ENV_FILE = '/var/www/ai-factory/apps/api/.env'

# MUST match AdScriptWriter::ANGLES. tests/Unit/AdAngleVocabularyTest.php enforces it.
ANGLES = [
    'problem_solution', 'before_after', 'founder', 'testimonial', 'demo', 'price_anchor', 'seasonal',
]
INDUSTRIES = [
    'food_delivery', 'restaurant', 'retail_ecommerce', 'fashion', 'beauty_salon', 'health_fitness',
    'medical', 'finance_banking', 'travel', 'transport_mobility', 'real_estate', 'education',
    'productivity', 'events_culture', 'trades_crafts', 'business_saas', 'other',
]

PROMPT = """You are cataloguing one paid social advertisement for a reference library. Look at the
image and answer with ONE JSON object and nothing else: no prose, no code fence.

{"angle":"...","industry":"...","hook_position":"...","text_load":"...","has_people":true,
 "has_price":true,"cta_words":"...","notes":"..."}

- angle: how it persuades. Exactly one of %s, or null if none of them fits.
    problem_solution  opens on the reader's problem, then removes it
    before_after      the same thing on two days, the work gone
    founder           the person behind the business speaking
    testimonial       a customer's point of view, a quote, a rating
    demo              the thing working, step by step
    price_anchor      the cost next to what it replaces
    seasonal          a moment in the year is the reason to act now
- industry: exactly one of %s
- hook_position: where the strongest line sits. top, middle, bottom, or none if there is no line.
- text_load: light (a headline and nothing else), medium, or heavy (paragraphs, lists, many labels)
- has_people: true if a human being is visible
- has_price: true if a number of money is shown
- cta_words: the words on the button or the closing line, copied as they appear, or null
- notes: at most 12 words on what a designer should copy from this ad

Use only the values listed. Answer null rather than guessing. Answer in English.
""" % (ANGLES, INDUSTRIES)

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


def format_of(width, height):
    """Which platform slot this was cut for. Measured, because arithmetic is not a judgement."""
    if not width or not height:
        return None
    r = width / height
    for name, target in (('story', 9 / 16), ('feed_portrait', 4 / 5), ('feed_square', 1.0),
                         ('link', 1.91), ('landscape', 16 / 9)):
        if abs(r - target) <= 0.06:
            return name
    return 'other'


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


def parse(text, width, height):
    m = re.search(r'\{.*\}', text or '', re.S)
    if not m:
        return None
    try:
        d = json.loads(m.group(0))
    except json.JSONDecodeError:
        return None

    def one_of(v, allowed):
        return v if isinstance(v, str) and v in allowed else None

    def flag(v):
        return v if isinstance(v, bool) else None

    return {
        'angle': one_of(d.get('angle'), ANGLES),
        'industry': one_of(d.get('industry'), INDUSTRIES),
        'format': format_of(width, height),
        'hook_position': one_of(d.get('hook_position'), ['top', 'middle', 'bottom', 'none']),
        'text_load': one_of(d.get('text_load'), ['light', 'medium', 'heavy']),
        'has_people': flag(d.get('has_people')),
        'has_price': flag(d.get('has_price')),
        'cta_words': (str(d['cta_words'])[:60] if d.get('cta_words') else None),
        'notes': (str(d['notes'])[:160] if d.get('notes') else None),
    }


def sidecars(root):
    """Only the ads. An app screen has its own script and its own vocabulary."""
    for dirpath, _, files in os.walk(os.path.join(root, 'images')):
        for name in sorted(files):
            if not name.endswith('.json'):
                continue
            path = os.path.join(dirpath, name)
            rec = json.load(open(path, encoding='utf-8'))
            if rec.get('visual', {}).get('category') not in ('advertisement', 'banner'):
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
    ap.add_argument('--workers', type=int, default=3)
    ap.add_argument('--force', action='store_true', help='relabel ads that already have labels')
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
            if args.force or (r.get('labels') or {}).get('angle') is None]
    if args.limit:
        todo = todo[:args.limit]
    print(f'{len(todo)} quảng cáo cần gán nhãn', flush=True)

    done = {'ok': 0, 'failed': 0}

    def work(item):
        path, rec = item
        img = os.path.join(args.root, rec['file'])
        v = rec.get('visual', {})
        try:
            labels = parse(ask(cfg, img), v.get('width'), v.get('height'))
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
            print(f'  ✓ {rec["id"]}: {labels["angle"]} · {labels["industry"]} · '
                  f'{labels["format"]} · {labels["text_load"]}', flush=True)
        if not args.dry_run:
            # Merge, never replace: an ad may already carry labels from the app pass.
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
