#!/usr/bin/env node
/**
 * Look at a generated page the way a person would, and say what is wrong with it.
 *
 *   node qa-page.cjs page.html [--viewports 320x640,768x1024,1280x900] [--timeout 15000]
 *
 * Writes one JSON report to stdout and always exits 0. A build must never fail because the
 * auditor fell over: a prototype with an unnoticed flaw still beats no prototype at all, so the
 * caller reads `ok` and decides, and a crash here shows up as a skipped audit, not a failed build.
 *
 * Findings are `blocking` when a visitor would see something broken, and `advisory` when it is a
 * judgement call. Only blocking ones are worth a repair call to the model; contrast and tap
 * targets are measured honestly but guess wrong often enough that spending a generation on them
 * would cost more than it fixes.
 *
 * playwright-core, not playwright: the browser is the chromium already in this image, so nothing
 * is downloaded and there is one browser to keep patched instead of two.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { chromium } = require('playwright-core');

/**
 * Which browser to drive, expressed as launch options rather than a path.
 *
 * `{}` means playwright has a build of its own and may pick the browser itself, which is the quiet
 * choice: for a headless run it reaches for the headless shell, and the shell opens no window and
 * claims no Dock icon. Next comes the chromium in the container image, which is the whole reason
 * this file uses playwright-core. A developer's Google Chrome is last because it is the full
 * browser: it works, but its icon flashes in the Dock once per audited page, and an auditor that
 * interrupts the person it is auditing for is a poor auditor. `null` means there is nothing to
 * drive, and the audit is skipped rather than failed.
 */
function resolveChromium() {
  try {
    if (fs.existsSync(chromium.executablePath())) return {};
  } catch {
    // playwright-core ships no browser unless one was installed for it. Keep looking.
  }

  const shell = newestHeadlessShell();
  if (shell) return { executablePath: shell };

  const installed = ['/usr/bin/chromium', '/usr/bin/chromium-browser',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find(p => fs.existsSync(p));
  return installed ? { executablePath: installed } : null;
}

/**
 * A headless shell some other project left in playwright's cache. Its revision is often not the
 * one this playwright expects, so take the newest and let the launch decide: a protocol that does
 * not match throws, and a throw here is a skipped audit, which is what the rest of this file is
 * built to survive.
 */
function newestHeadlessShell() {
  const cache = process.env.PLAYWRIGHT_BROWSERS_PATH || (process.platform === 'darwin'
    ? path.join(os.homedir(), 'Library', 'Caches', 'ms-playwright')
    : path.join(os.homedir(), '.cache', 'ms-playwright'));

  let revisions;
  try {
    revisions = fs.readdirSync(cache)
      .filter(d => d.startsWith('chromium_headless_shell-'))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]));
  } catch {
    return null;
  }

  for (const revision of revisions) {
    let platforms;
    try {
      platforms = fs.readdirSync(path.join(cache, revision), { withFileTypes: true })
        .filter(e => e.isDirectory()).map(e => e.name);
    } catch {
      continue;
    }
    // The binary was renamed from headless_shell to chrome-headless-shell along the way, and a
    // cache can hold both eras at once.
    for (const dir of platforms) {
      for (const name of ['chrome-headless-shell', 'headless_shell']) {
        const bin = path.join(cache, revision, dir, name);
        if (fs.existsSync(bin)) return bin;
      }
    }
  }
  return null;
}

const CHROMIUM = resolveChromium();

/** Words that mean the model stopped writing the visitor's business and started filling space. */
const PLACEHOLDER = /lorem ipsum|item [1-9]\b|placeholder|your (text|logo|name) here|mustertext|beispieltext|text hier|todo:|xxx+/i;

/** WCAG 2.2 AA: 24px is the floor for a target, and 4.5:1 for normal text, 3:1 for large. */
const MIN_TARGET = 24;

/** Runs inside the page. Everything it needs must be inline: it has no access to this file. */
function audit([placeholderSource, minTarget]) {
  const PLACEHOLDER = new RegExp(placeholderSource, 'i');
  const out = [];
  const sel = (el) => {
    if (!el) return '?';
    const id = el.id ? '#' + el.id : '';
    const cls = typeof el.className === 'string' && el.className.trim()
      ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '';
    return el.tagName.toLowerCase() + id + cls;
  };
  const visible = (el) => {
    const r = el.getBoundingClientRect();
    const s = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && s.opacity !== '0';
  };

  // 1. Horizontal overflow. Not the document's own width: the elements sticking out of it, because
  // "the page scrolls sideways" is useless to whoever has to fix it.
  const doc = document.documentElement;
  const over = doc.scrollWidth - doc.clientWidth;
  if (over > 1) {
    // A row of phone mocks that scrolls sideways inside its own box reaches past the viewport by
    // design and takes its children with it. Blaming those buries the one element that actually
    // pushes the document wide, which is the only one anybody can fix.
    const clipped = (el) => {
      for (let n = el.parentElement; n; n = n.parentElement) {
        if (getComputedStyle(n).overflowX !== 'visible') return true;
      }
      return false;
    };
    const wide = [];
    for (const el of document.querySelectorAll('body *')) {
      const r = el.getBoundingClientRect();
      if (visible(el) && (r.right > doc.clientWidth + 1 || r.left < -1) && !clipped(el)) {
        // The outermost offender is the one to fix; its children stick out because it does.
        if (!wide.some((w) => w.el.contains(el))) wide.push({ el, right: Math.round(r.right) });
      }
    }
    out.push({
      severity: 'blocking', check: 'overflow',
      detail: `page is ${over}px wider than the screen`,
      elements: wide.slice(0, 5).map((w) => `${sel(w.el)} reaches ${w.right}px`),
    });
  }

  // 2. Text the model did not write. A screen full of "Item 1" is the failure the conventions file
  // warns about, and it is the one a customer notices first.
  for (const el of document.querySelectorAll('body *')) {
    if (el.children.length) continue;
    const t = (el.textContent || '').trim();
    if (t && PLACEHOLDER.test(t)) {
      out.push({ severity: 'blocking', check: 'placeholder', detail: t.slice(0, 80), elements: [sel(el)] });
      if (out.filter((f) => f.check === 'placeholder').length >= 5) break;
    }
  }

  // 3. Pictures that did not arrive.
  for (const img of document.images) {
    if (img.complete && img.naturalWidth === 0) {
      out.push({ severity: 'blocking', check: 'broken-image', detail: (img.currentSrc || img.src).slice(0, 120), elements: [sel(img)] });
    }
  }

  // 4. Navigation you have to scroll down to find is not navigation. A phone's tab bar belongs
  // at the bottom of the SCREEN, not at the bottom of the document, and the difference is one
  // CSS property that is easy to leave out and invisible until somebody looks at a real phone.
  for (const bar of document.querySelectorAll('.tabbar, .app-tabs')) {
    // A tab bar drawn inside a phone mock on a landing page is a picture of an app, not this
    // page's navigation, and it is below the fold for the same reason the rest of the page is.
    if (!visible(bar) || bar.closest('.phone-frame, .phone, .device')) continue;
    const r = bar.getBoundingClientRect();
    if (r.bottom > window.innerHeight + 1) {
      out.push({
        severity: 'blocking', check: 'nav-below-the-fold',
        detail: `the tab bar ends ${Math.round(r.bottom - window.innerHeight)}px below the screen`,
        elements: [sel(bar) + ' at position:' + getComputedStyle(bar).position],
      });
    }
  }

  // 5. Emoji standing in for icons. They are a different size, a different colour and a different
  // shape on every platform, and a row of them reads as a placeholder rather than a design.
  const EMOJI = /^[\p{Extended_Pictographic}\uFE0F\u200D\s]+$/u;
  const emoji = [];
  for (const el of document.querySelectorAll('body *')) {
    if (el.children.length || !visible(el)) continue;
    const t = (el.textContent || '').trim();
    if (t && t.length <= 8 && EMOJI.test(t)) emoji.push(`${sel(el)} "${t}"`);
  }
  if (emoji.length >= 3) {
    out.push({
      severity: 'advisory', check: 'emoji-as-icon',
      detail: `${emoji.length} emoji used where an icon belongs`,
      elements: emoji.slice(0, 5),
    });
  }

  // 6. Anything a finger has to hit.
  const small = [];
  for (const el of document.querySelectorAll('a, button, input, select, textarea, [role="button"]')) {
    if (!visible(el)) continue;
    const r = el.getBoundingClientRect();
    if (r.width < minTarget || r.height < minTarget) {
      small.push(`${sel(el)} is ${Math.round(r.width)}x${Math.round(r.height)}`);
    }
  }
  if (small.length) {
    out.push({ severity: 'advisory', check: 'tap-target', detail: `${small.length} below ${minTarget}px`, elements: small.slice(0, 5) });
  }

  // 7. A scrollbar drawn across the page. A row of cards is allowed to scroll sideways, and a
  // phone shows that by cutting the last card off, never by a grey bar: on a phone frame the bar
  // reads as a broken layout, which is exactly what it was every time it appeared. Whether it is
  // drawn is a declaration, not a measurement, because whether the platform reserves space for it
  // differs per machine and the audit must find the same fault on all of them.
  const hides = [];
  for (const sheet of document.styleSheets) {
    let rules = null;
    try { rules = sheet.cssRules; } catch { continue; }   // a sheet from another origin
    for (const r of rules || []) {
      if (!r.selectorText || !/::-webkit-scrollbar\b/.test(r.selectorText)) continue;
      if (r.style.display !== 'none' && parseFloat(r.style.width) !== 0) continue;
      for (const part of r.selectorText.split(',')) {
        const p = part.trim().split('::')[0].trim();
        hides.push(p === '' ? '*' : p);
      }
    }
  }
  const bars = [];
  for (const el of document.querySelectorAll('body *')) {
    if (!visible(el)) continue;
    const s = getComputedStyle(el);
    if (!/auto|scroll/.test(s.overflowX)) continue;
    if (el.scrollWidth - el.clientWidth <= 1) continue;
    if (s.scrollbarWidth === 'none') continue;
    if (hides.some((h) => { try { return el.matches(h); } catch { return false; } })) continue;
    bars.push(`${sel(el)} scrolls ${el.scrollWidth - el.clientWidth}px sideways`);
  }
  if (bars.length) {
    out.push({
      severity: 'blocking', check: 'sideways-scrollbar',
      detail: `${bars.length} element(s) scroll sideways with the scrollbar showing`,
      elements: bars.slice(0, 5),
    });
  }

  // 8. A dash used as a sentence break. An em dash, or an en dash with a space on each side, is
  // the punctuation of generated text, and a customer reads it as such before reading anything
  // else. The words are the model's own and a comma, a colon or a full stop is a one-word fix,
  // so this is a fault to repair, not a note. The title counts: it becomes the prototype's name.
  const DASH = /\u2014|\s\u2013\s/;
  const dashes = [];
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let n = walker.nextNode(); n; n = walker.nextNode()) {
    const t = n.nodeValue || '';
    if (!DASH.test(t)) continue;
    const el = n.parentElement;
    if (!el || el.closest('script, style')) continue;
    dashes.push(`${sel(el)}: "${t.trim().slice(0, 60)}"`);
    if (dashes.length >= 8) break;
  }
  if (DASH.test(document.title || '')) dashes.unshift(`title: "${document.title.slice(0, 60)}"`);
  if (dashes.length) {
    out.push({
      severity: 'blocking', check: 'dash',
      detail: `${dashes.length} dash(es) used as a sentence break`,
      elements: dashes.slice(0, 5),
    });
  }

  // 9. Contrast. Walked up the tree for the first opaque background, which is what the eye does
  // too; a gradient or a photo behind the text defeats it, hence advisory.
  const lum = (c) => {
    const [r, g, b] = c.map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };
  // Two shapes come back from getComputedStyle: rgb(19, 28, 46) and, for anything that went
  // through color-mix(), color(srgb 0.7 0.6 0.9 / 0.5) with channels in 0..1. Read the second as
  // the first and every mixed colour looks nearly black, which is what put the active tab at 1.2:1.
  const rgb = (s) => {
    const n = (s.match(/[\d.]+/g) || []).map(Number);
    if (/^color\(srgb/.test(s)) return [n[0] * 255, n[1] * 255, n[2] * 255, n[3]];
    return n.slice(0, 4);
  };
  // Translucent layers are composited, not skipped. A sticky nav at 88% dark over a white page
  // is dark; treating it as white reported the brand at 1.1:1 on every dark hero.
  const bgOf = (el) => {
    const layers = [];
    let base = [255, 255, 255];
    for (let n = el; n && n !== document.documentElement.parentNode; n = n.parentElement) {
      const s = getComputedStyle(n);
      if (s.backgroundImage !== 'none') return null;
      const c = rgb(s.backgroundColor);
      if (c.length < 3) continue;
      const a = c[3] === undefined ? 1 : c[3];
      if (a >= 0.99) { base = c.slice(0, 3); break; }
      if (a > 0) layers.push([c[0], c[1], c[2], a]);
    }
    return layers.reduceRight((under, [r, g, b, a]) =>
      [r * a + under[0] * (1 - a), g * a + under[1] * (1 - a), b * a + under[2] * (1 - a)], base);
  };
  const bad = [];
  for (const el of document.querySelectorAll('body *')) {
    if (el.children.length || !(el.textContent || '').trim() || !visible(el)) continue;
    const s = getComputedStyle(el);
    const bg = bgOf(el);
    if (!bg) continue;
    const fg = rgb(s.color);
    if (fg.length < 3) continue;
    const l1 = lum(fg), l2 = lum(bg);
    const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    const size = parseFloat(s.fontSize);
    const large = size >= 24 || (size >= 18.66 && parseInt(s.fontWeight, 10) >= 700);
    if (ratio < (large ? 3 : 4.5)) bad.push(`${sel(el)} at ${ratio.toFixed(1)}:1`);
  }
  if (bad.length) {
    out.push({ severity: 'advisory', check: 'contrast', detail: `${bad.length} below AA`, elements: bad.slice(0, 5) });
  }

  return out;
}

async function main() {
  const file = process.argv[2];
  const arg = (name, fallback) => {
    const i = process.argv.indexOf('--' + name);
    return i > 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
  };

  const report = { ok: null, viewports: {}, findings: [] };

  if (!file || !fs.existsSync(file)) {
    report.skipped = 'no such file';
    return report;
  }
  if (!CHROMIUM) {
    report.skipped = 'no chromium on this machine';
    return report;
  }

  const viewports = arg('viewports', '320x640,768x1024,1280x900').split(',').map((v) => {
    const [w, h] = v.split('x').map(Number);
    return { name: v, width: w, height: h };
  });
  const timeout = Number(arg('timeout', 15000));

  const browser = await chromium.launch({
    ...CHROMIUM,
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
  });

  try {
    for (const vp of viewports) {
      const page = await browser.newPage({ viewport: { width: vp.width, height: vp.height } });
      const noise = [];
      page.on('pageerror', (e) => noise.push({ severity: 'blocking', check: 'script-error', detail: String(e).slice(0, 160) }));
      page.on('console', (m) => {
        // "Failed to load resource" is the console's version of a request that failed, and both
        // requestfailed and the broken-image check already say so. Reporting it three times makes
        // a repair prompt look like three problems.
        const t = m.text();
        if (m.type() === 'error' && !t.startsWith('Failed to load resource')) {
          noise.push({ severity: 'blocking', check: 'console-error', detail: t.slice(0, 160) });
        }
      });
      page.on('requestfailed', (r) => noise.push({ severity: 'advisory', check: 'request-failed', detail: r.url().slice(0, 120) }));

      await page.goto('file://' + path.resolve(file), { waitUntil: 'load', timeout });
      // Fonts and lazy images settle after load; without this the first viewport reports
      // overflow that is gone a moment later.
      await page.waitForTimeout(600);

      // Playwright ships the function's source into the page, so `audit` must close over
      // nothing from here; everything it needs arrives as arguments.
      const found = await page.evaluate(audit, [PLACEHOLDER.source, MIN_TARGET]).catch(() => null);

      const list = [...noise, ...(found || [])].map((f) => ({ ...f, viewport: vp.name }));
      report.viewports[vp.name] = list.length;
      report.findings.push(...list);
      await page.close();
    }

    // Most faults show up at every width, and three copies of one problem reads as three problems
    // to whoever, or whatever, has to fix it. One finding, and the widths it was seen at.
    const merged = new Map();
    for (const f of report.findings) {
      const key = [f.check, (f.elements || []).join(';'), f.check === 'overflow' ? '' : f.detail].join('|');
      const seen = merged.get(key);
      if (seen) seen.viewports.push(f.viewport);
      else merged.set(key, { ...f, viewport: undefined, viewports: [f.viewport] });
    }
    report.findings = [...merged.values()].map(({ viewport, ...f }) => f);
    report.ok = !report.findings.some((f) => f.severity === 'blocking');
  } finally {
    await browser.close();
  }

  return report;
}

main()
  .then((r) => process.stdout.write(JSON.stringify(r)))
  .catch((e) => process.stdout.write(JSON.stringify({ ok: null, skipped: String(e).slice(0, 200), findings: [] })));
