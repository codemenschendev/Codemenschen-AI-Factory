/**
 * Appwerk image agent.
 *
 * POST /v1/images  {prompt, size: "1080x1920", refs: [{mime, data(base64)}]}  -> {base64, mime}
 * GET  /health     -> {ok, logged_in, mode}
 *
 * Each request runs one read-only `codex exec`: the reference pictures are attached with -i,
 * Codex's image tool renders one picture, and Codex files it under CODEX_HOME/generated_images.
 * The service takes the newest file that appeared there during the run. Codex writes nothing
 * else and runs no command. Two jobs at a time: the subscription's image quota is per account.
 * Bearer IMAGEGEN_TOKEN; the service is only on the compose network.
 */
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { mkdtemp, writeFile, readFile, rm, copyFile, access, readdir, stat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const PORT = Number(process.env.PORT ?? 8400);
const TOKEN = process.env.IMAGEGEN_TOKEN ?? '';
const HOME = process.env.CODEX_HOME ?? '/codex-home';
const OUTPUT = join(HOME, 'generated_images');
const TIMEOUT_MS = Number(process.env.IMAGEGEN_TIMEOUT_MS ?? 360000);
const MAX_BODY = 25 * 1024 * 1024;

const run = (args, input, timeoutMs, cwd) => new Promise((resolve) => {
    const child = spawn('codex', args, { cwd, env: process.env, stdio: ['pipe', 'pipe', 'pipe'] });
    let out = '';
    let err = '';
    const killer = setTimeout(() => child.kill('SIGTERM'), timeoutMs);
    child.stdout.on('data', (d) => { out += d; });
    child.stderr.on('data', (d) => { err += d; });
    child.on('close', (code) => { clearTimeout(killer); resolve({ code, out, err }); });
    child.on('error', (e) => { clearTimeout(killer); resolve({ code: -1, out, err: String(e) }); });
    child.stdin.end(input ?? '');
});

const shape = (size) => {
    const [w, h] = String(size ?? '1080x1920').split('x').map(Number);
    if (!w || !h || w === h) return 'square';
    return w > h ? 'landscape (wide, about 3:2)' : 'portrait (tall, about 2:3)';
};

// The image tool draws 1:1, 3:2 or 2:3 only. An ad has an exact size (1200x628, 1080x1920, ...),
// so the picture is cropped to it afterwards, and the design has to keep its words clear of the
// part that is cut.
const fitNote = (size) => {
    const [w, h] = String(size ?? '').split('x').map(Number);
    if (!w || !h) return '';
    const want = w / h;
    const drawn = w === h ? 1 : w > h ? 1.5 : 2 / 3;
    const cut = Math.abs(want - drawn) < 0.02 ? ''
        : want > drawn
            ? ` The picture is then cropped to exactly this ratio: about ${Math.round((1 - drawn / want) * 50)}% is cut from the top and from the bottom, so keep every word, the logo and the button inside the middle band.`
            : ` The picture is then cropped to exactly this ratio: about ${Math.round((1 - want / drawn) * 50)}% is cut from the left and from the right, so keep every word, the logo and the button inside the middle column.`;
    // Said twice on purpose: the agent rewrites the request into its own prompt for the image
    // tool and dropped the crop note, and a banner lost the top of its logo to the crop.
    return `Final ad size: ${w}x${h} px (ratio ${want.toFixed(2)}:1).${cut}`
        + (cut ? ` When you call the image tool, copy this rule into its prompt word for word: "${want > drawn
            ? `Leave the top ${Math.round((1 - drawn / want) * 50) + 4}% and the bottom ${Math.round((1 - drawn / want) * 50) + 4}% of the image as plain background with no text, no logo and no button.`
            : `Leave the left ${Math.round((1 - want / drawn) * 50) + 4}% and the right ${Math.round((1 - want / drawn) * 50) + 4}% of the image as plain background with no text, no logo and no button.`}"` : '');
};

// A finished creative, text and all: the owner's test of Codex as the whole ad designer
// (2026-09-19). The words come from the request, spelled exactly; facts not given are not added.
const creative = (prompt, size, refCount) => [
    'Generate exactly ONE image with your image generation tool, then reply "done".',
    'Do not run commands and do not write files: the image tool output is all that is needed.',
    '',
    `Format: ${shape(size)}.`,
    fitNote(size),
    'Design a finished, premium advertising creative, ready to publish: headline, short supporting line, a few benefit points where they fit, and a call-to-action button, laid out by a senior art director. Spell every word correctly, in the language of the request.',
    refCount > 0
        ? `The ${refCount} attached picture(s) are the business's own real logo, product and screenshots. Use them faithfully: same logo, same design, same printed text. Show the real product, never an invented one.`
        : '',
    'Use only facts from the request and the business description below. No invented prices, awards, ratings, customer numbers or partner logos.',
    '',
    'The request:',
    prompt,
].filter((l) => l !== '').join('\n');

const instruction = (prompt, size, refCount) => [
    'Generate exactly ONE image with your image generation tool, then reply "done".',
    'Do not run commands and do not write files: the image tool output is all that is needed.',
    '',
    `Format: ${shape(size)}.`,
    refCount > 0
        ? `The ${refCount} attached picture(s) are the business's own real product and brand pictures. Where the scene shows the product, use them faithfully: same design, same printed text, no invented variations.`
        : '',
    'Never add words, letters, prices, logos or watermarks that are not already part of an attached picture. Text is set on top later.',
    '',
    'The scene:',
    prompt,
].filter((l) => l !== '').join('\n');

/** Every image file under generated_images with its modification time. */
const images = async () => {
    const found = [];
    const walk = async (dir) => {
        let names = [];
        try { names = await readdir(dir); } catch { return; }
        for (const name of names) {
            const p = join(dir, name);
            const s = await stat(p);
            if (s.isDirectory()) await walk(p);
            else if (/\.(png|jpe?g|webp)$/i.test(name)) found.push({ path: p, mtime: s.mtimeMs });
        }
    };
    await walk(OUTPUT);
    return found;
};

// Two at a time: an ad set asks for two pictures at once, and more than that is a stampede on
// one subscription's image quota.
const LIMIT = Math.max(1, Number(process.env.IMAGEGEN_CONCURRENCY ?? 2));
let active = 0;
const waiting = [];
const queued = async (job) => {
    if (active >= LIMIT) await new Promise((resolve) => waiting.push(resolve));
    active++;
    try {
        return await job();
    } finally {
        active--;
        waiting.shift()?.();
    }
};

const claimed = new Set();

const render = async (body) => {
    const dir = await mkdtemp(join(tmpdir(), 'img-'));
    try {
        const refs = [];
        for (const [i, ref] of (Array.isArray(body.refs) ? body.refs.slice(0, 4) : []).entries()) {
            const ext = /png/.test(ref.mime ?? '') ? 'png' : /webp/.test(ref.mime ?? '') ? 'webp' : 'jpg';
            const file = join(dir, `ref${i + 1}.${ext}`);
            await writeFile(file, Buffer.from(String(ref.data ?? ''), 'base64'));
            refs.push(file);
        }
        const before = new Set((await images()).map((f) => f.path));
        const started = Date.now();
        const args = ['exec', '--skip-git-repo-check', '--sandbox', 'read-only', '-C', dir];
        if (refs.length) args.push('-i', refs.join(','));
        args.push('-');
        const res = await run(args, (body.creative === true ? creative : instruction)(String(body.prompt ?? ''), body.size, refs.length), TIMEOUT_MS, dir);
        // Two renders can finish while both run; each takes a picture no other job has claimed.
        // Codex files a run's pictures under generated_images/<session id>/, and prints that id.
        const session = /session id:\s*([0-9a-f-]{36})/i.exec(res.out + res.err)?.[1];
        const fresh = (await images())
            .filter((f) => !before.has(f.path) && !claimed.has(f.path) && f.mtime >= started - 1000)
            .filter((f) => !session || f.path.includes(session))
            .sort((a, b) => b.mtime - a.mtime);
        if (fresh.length) claimed.add(fresh[0].path);
        if (fresh.length === 0) {
            const tail = (res.err || res.out).slice(-600);
            throw Object.assign(new Error(`codex produced no image (exit ${res.code}): ${tail}`), {
                status: /usage limit|rate limit|quota/i.test(tail) ? 429 : 502,
            });
        }
        return await readFile(fresh[0].path);
    } finally {
        await rm(dir, { recursive: true, force: true });
    }
};

// Codex keeps every picture it made; the pipeline has its own copy, so a day is plenty.
const sweep = async () => {
    for (const f of await images()) {
        if (Date.now() - f.mtime > 86400000) await rm(f.path, { force: true });
    }
};
setInterval(() => { sweep().catch(() => {}); }, 3600000).unref();

const send = (res, status, obj) => {
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(obj));
};

createServer(async (req, res) => {
    try {
        if (req.method === 'GET' && req.url === '/health') {
            const s = await run(['login', 'status'], '', 20000, HOME);
            const text = s.out + s.err;
            return send(res, 200, { ok: true, logged_in: /Logged in/i.test(text), mode: /ChatGPT/i.test(text) ? 'chatgpt' : 'other' });
        }
        if (TOKEN === '' || (req.headers.authorization ?? '') !== `Bearer ${TOKEN}`) {
            return send(res, 401, { error: 'unauthorized' });
        }
        if (req.method !== 'POST' || req.url !== '/v1/images') {
            return send(res, 404, { error: 'not found' });
        }
        let raw = '';
        for await (const chunk of req) {
            raw += chunk;
            if (raw.length > MAX_BODY) return send(res, 413, { error: 'body too large' });
        }
        const body = JSON.parse(raw || '{}');
        if (!String(body.prompt ?? '').trim()) return send(res, 422, { error: 'prompt missing' });
        const started = Date.now();
        const bytes = await queued(() => render(body));
        console.log(`[imagegen] rendered ${bytes.length} bytes in ${Math.round((Date.now() - started) / 1000)}s, refs=${(body.refs ?? []).length}`);
        return send(res, 200, { base64: bytes.toString('base64'), mime: 'image/png' });
    } catch (e) {
        console.error('[imagegen]', String(e.message ?? e).slice(0, 400));
        return send(res, e.status ?? 500, { error: String(e.message ?? e).slice(0, 400) });
    }
}).listen(PORT, '0.0.0.0', async () => {
    try { await access(join(HOME, 'config.toml')); } catch { await copyFile('/app/config.toml', join(HOME, 'config.toml')); }
    console.log(`[imagegen] listening on ${PORT}`);
});
