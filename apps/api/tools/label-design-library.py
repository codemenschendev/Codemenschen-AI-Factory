#!/usr/bin/env python3
"""Label the design library: one vision call per image, written back into its sidecar.

    label-design-library.py [--root DIR] [--grade detail] [--medium app]
                            [--limit N] [--workers N] [--force] [--dry-run]

The library lives outside the repo (default /var/lib/ai-factory/design-library) because it is
data, not code, and it grows. Each image already has a `<id>.json` sidecar next to it; this fills
in the `labels` block that the import left empty, then rebuilds catalog.json and index/.

Why a controlled vocabulary: a free-text pass over 239 screens returns 239 different words for the
same thing, and the library stays unsearchable. The model may only pick from the lists below, and
must answer `null` when it is not sure. An honest gap beats a confident guess.

Runs against the same host sidecar the rest of the factory uses (AI_IMAGE_SERVICE_BASE_URL), so
there is no second token and no second route to the gateway. Resumable: an image that already has
a screen_type is skipped unless --force.
"""
import argparse, base64, json, os, re, sys, threading, time
import urllib.request, urllib.error
from concurrent.futures import ThreadPoolExecutor

DEFAULT_ROOT = '/var/lib/ai-factory/design-library'
ENV_FILE = '/var/www/ai-factory/apps/api/.env'

SCREEN_TYPES = [
    'onboarding', 'signup_login', 'home_dashboard', 'list_feed', 'detail', 'search', 'filter',
    'map', 'calendar_booking', 'checkout_payment', 'cart', 'form_input', 'profile_account',
    'settings', 'messaging_chat', 'notifications', 'empty_state', 'paywall_pricing',
    'success_confirmation', 'media_player', 'camera_scanner', 'stats_report', 'other',
]
INDUSTRIES = [
    'food_delivery', 'restaurant', 'retail_ecommerce', 'fashion', 'beauty_salon', 'health_fitness',
    'medical', 'finance_banking', 'crypto', 'travel', 'transport_mobility', 'real_estate',
    'education', 'productivity', 'social', 'media_entertainment', 'music', 'dating', 'gaming',
    'utilities', 'business_saas', 'other',
]
PATTERNS = [
    'bottom_tab_bar', 'top_app_bar', 'large_title', 'hero_image', 'card_grid', 'list_rows',
    'horizontal_carousel', 'segmented_control', 'floating_action_button', 'bottom_sheet',
    'sticky_cta', 'search_bar', 'filter_chips', 'avatar_row', 'progress_steps', 'calendar_grid',
    'map_canvas', 'form_fields', 'price_table', 'rating_stars', 'badge_labels', 'illustration',
    'stat_tiles', 'timeline',
]

PROMPT = """You are cataloguing one app screen for a design reference library. Look at the image
and answer with ONE JSON object and nothing else: no prose, no code fence.

{"screen_type":"...","industry":"...","layout_patterns":["..."],"primary_action":"...",
 "density":"...","palette":{"scheme":"light|dark","accent":"..."},"notes":"..."}

- screen_type: exactly one of %s
- industry: exactly one of %s
- layout_patterns: two to five of %s
- primary_action: the label on the most prominent button, copied as it appears, or null if none
- density: sparse, medium or dense
- palette.scheme: light or dark. palette.accent: one plain colour word for the accent.
- notes: at most 12 words on what a designer should copy from this screen.

Use only the values listed. If you cannot tell, use null rather than guessing. Answer in English.
""" % (SCREEN_TYPES, INDUSTRIES, PATTERNS)

# A 360px screenshot carries its layout and nothing else: the words on it are a few pixels tall.
# Without this the model reads them anyway and invents button labels that are not there.
LAYOUT_NOTE = """
This screenshot is 360px wide, so the text on it is not legible. Judge the layout, the blocks and
the rhythm. Set primary_action to null unless a label is genuinely readable, and do not guess a
word from the shape of it."""

# Sidecars are grouped by grade on disk, and `grade` is the field that says how much to trust the
# pixels, so the prompt is chosen per image rather than per run.
def prompt_for(grade):
    return PROMPT + (LAYOUT_NOTE if grade == 'layout' else '')

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


def ask(cfg, image_path, prompt, timeout=120):
    b64 = base64.b64encode(open(image_path, 'rb').read()).decode()
    mime = 'image/webp' if image_path.endswith('.webp') else 'image/png'
    body = {'messages': [{'role': 'user', 'content': [
        {'type': 'text', 'text': prompt},
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
    """Models still fence JSON now and then; take the outermost object."""
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
        'screen_type': one_of(d.get('screen_type'), SCREEN_TYPES),
        'industry': one_of(d.get('industry'), INDUSTRIES),
        # Anything the model invented is dropped here rather than polluting the vocabulary.
        'layout_patterns': [p for p in (d.get('layout_patterns') or []) if p in PATTERNS][:6],
        'primary_action': (str(d['primary_action'])[:60] if d.get('primary_action') else None),
        'density': one_of(d.get('density'), ['sparse', 'medium', 'dense']),
        'palette': {'scheme': one_of(pal.get('scheme'), ['light', 'dark']),
                    'accent': (str(pal.get('accent'))[:20] if pal.get('accent') else None)},
        'notes': (str(d['notes'])[:160] if d.get('notes') else None),
    }


def sidecars(root, medium, grade):
    for dirpath, _, files in os.walk(os.path.join(root, 'images')):
        for name in sorted(files):
            if not name.endswith('.json'):
                continue
            path = os.path.join(dirpath, name)
            rec = json.load(open(path, encoding='utf-8'))
            if medium and rec.get('medium') != medium:
                continue
            if grade and grade != 'all' and rec.get('visual', {}).get('grade') != grade:
                continue
            yield path, rec


def rebuild(root):
    """catalog.json and index/ are derived files: write them from the sidecars, never by hand."""
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
    ap.add_argument('--medium', default='app')
    ap.add_argument('--grade', default='detail', help="detail, layout, or all")
    ap.add_argument('--limit', type=int, default=0)
    ap.add_argument('--workers', type=int, default=3)
    ap.add_argument('--force', action='store_true', help='relabel images that already have labels')
    ap.add_argument('--dry-run', action='store_true', help='label but write nothing')
    ap.add_argument('--rebuild-only', action='store_true',
                    help='rebuild catalog.json + index/ from the sidecars and stop; no vision calls')
    args = ap.parse_args()

    if args.rebuild_only:
        print(f'catalog.json + index/ dựng lại từ {rebuild(args.root)} bản ghi', flush=True)
        return

    cfg = read_env(args.env)
    if not cfg['base'] or not cfg['token']:
        sys.exit('LỖI: chưa có AI_IMAGE_SERVICE_BASE_URL / AI_IMAGE_SERVICE_TOKEN.')

    todo = [(p, r) for p, r in sidecars(args.root, args.medium, args.grade)
            if args.force or not (r.get('labels') or {}).get('screen_type')]
    if args.limit:
        todo = todo[:args.limit]
    print(f'{len(todo)} ảnh cần gán nhãn (medium={args.medium}, grade={args.grade})', flush=True)

    done = {'ok': 0, 'failed': 0}

    def work(item):
        path, rec = item
        img = os.path.join(args.root, rec['file'])
        try:
            labels = parse(ask(cfg, img, prompt_for(rec.get('visual', {}).get('grade'))))
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
            print(f'  ✓ {rec["id"]}: {labels["screen_type"]} · {labels["industry"]} · '
                  f'{",".join(labels["layout_patterns"])}', flush=True)
        if not args.dry_run:
            rec['labels'] = labels
            rec['labelled_at'] = time.strftime('%Y-%m-%dT%H:%M:%S%z')
            json.dump(rec, open(path, 'w', encoding='utf-8'), indent=1, ensure_ascii=False)

    with ThreadPoolExecutor(max_workers=max(1, args.workers)) as pool:
        list(pool.map(work, todo))

    print(f'xong: {done["ok"]} gán nhãn, {done["failed"]} lỗi', flush=True)
    if not args.dry_run:
        print(f'catalog.json + index/ dựng lại từ {rebuild(args.root)} bản ghi', flush=True)


if __name__ == '__main__':
    main()
