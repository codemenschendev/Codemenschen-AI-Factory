/**
 * Cloudflare Turnstile for the public requests that cost a model call (the API checks the token,
 * App\Http\Middleware\VerifyTurnstile). A token is good for one request, so every call asks for a
 * fresh one. Most visitors never see anything; a suspicious one gets a small check box in the
 * corner. The site key is public by design.
 */
const SITE_KEY = process.env.NEXT_PUBLIC_TURNSTILE_SITE_KEY ?? "0x4AAAAAAFL1ujbj51YOyujM";
// Cloudflare's always-pass test key: the real one only works on appmitki.com.
const TEST_KEY = "1x00000000000000000000AA";

/** The requests that carry a token. */
export const TURNSTILE_PATHS = ["/prototypes", "/prototypes/questions", "/quotes/refine"];

type Turnstile = {
  render: (el: HTMLElement, opts: Record<string, unknown>) => string;
  execute: (id: string) => void;
  reset: (id: string) => void;
};

let loading: Promise<Turnstile | null> | null = null;
let widget: string | null = null;
let waiting: ((token: string | null) => void) | null = null;

function load(): Promise<Turnstile | null> {
  if (loading) return loading;
  loading = new Promise((resolve) => {
    const w = window as unknown as { turnstile?: Turnstile };
    if (w.turnstile) return resolve(w.turnstile);
    const s = document.createElement("script");
    s.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
    s.async = true;
    s.onload = () => resolve(w.turnstile ?? null);
    s.onerror = () => {
      loading = null;
      resolve(null);
    };
    document.head.appendChild(s);
  });
  return loading;
}

function settle(token: string | null) {
  const done = waiting;
  waiting = null;
  done?.(token);
}

/** A fresh one-use token, or null when Turnstile could not load (the API then decides). */
export async function turnstileToken(): Promise<string | null> {
  if (typeof window === "undefined") return null;
  const ts = await load();
  if (!ts) return null;
  if (widget === null) {
    const el = document.createElement("div");
    el.className = "ts-box";
    document.body.appendChild(el);
    const local = /^(localhost|127\.0\.0\.1)$/.test(window.location.hostname);
    widget = ts.render(el, {
      sitekey: local ? TEST_KEY : SITE_KEY,
      execution: "execute",
      appearance: "interaction-only",
      callback: (t: string) => settle(t),
      "error-callback": () => settle(null),
      "expired-callback": () => settle(null),
    });
  } else {
    ts.reset(widget);
  }
  settle(null); // an older call still waiting gives up; its token would be the same one
  return new Promise((resolve) => {
    waiting = resolve;
    ts.execute(widget!);
    // A check the visitor never answers must not hang the form forever.
    setTimeout(() => waiting === resolve && settle(null), 60_000);
  });
}
