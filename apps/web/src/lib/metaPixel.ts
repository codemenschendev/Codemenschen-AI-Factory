import { API_BASE } from "./api";
/**
 * Meta Pixel, loaded only after a yes to ad measurement (2026-10-01).
 *
 * The pixel sends page views so Meta can show ads to people like those who visit, and so a
 * campaign can be measured by landing page views. Requests and purchases are not sent from here:
 * the API reports them through the Conversions API (App\Domain\Ads\Conversions), and sending them
 * twice would count them twice. A later no revokes consent in the pixel and drops its cookies.
 *
 * The steps in between (metaEvent, 2026-10-26, Patrick: "do we send the landing page's events to
 * Facebook?") go both ways with one event id, the pixel and the Conversions API, so Meta counts each
 * once. Only after a yes to ad measurement, like everything here.
 */
type Fbq = ((...args: unknown[]) => void) & { callMethod?: (...a: unknown[]) => void; queue: unknown[][]; loaded?: boolean; version?: string; push?: unknown };
declare global {
  interface Window {
    fbq?: Fbq;
    _fbq?: Fbq;
  }
}

let started = false;
// A yes right now: a later no stops the funnel events at once, not only in the pixel.
let granted = false;

export function applyMetaPixel(pixelId: string | null, ads: boolean): void {
  if (typeof window === "undefined" || !pixelId || !/^\d{6,20}$/.test(pixelId)) return;
  granted = ads;
  if (!ads) {
    if (started) window.fbq?.("consent", "revoke");
    return;
  }
  if (started) {
    window.fbq?.("consent", "grant");
    return;
  }
  // Meta's own loader, written out: a queue until fbevents.js arrives.
  const f = function (...args: unknown[]) {
    if (f.callMethod) f.callMethod(...args);
    else f.queue.push(args);
  } as Fbq;
  f.queue = [];
  f.loaded = true;
  f.version = "2.0";
  f.push = f;
  window.fbq = f;
  window._fbq = f;
  const s = document.createElement("script");
  s.async = true;
  s.src = "https://connect.facebook.net/en_US/fbevents.js";
  document.head.appendChild(s);
  window.fbq("init", pixelId);
  window.fbq("track", "PageView");
  started = true;
  for (const [name, params, once] of pending.splice(0)) metaEvent(name, params, once);
}

/** A page view after a client-side navigation; nothing before consent. */
export function metaPageView(): void {
  if (started) window.fbq?.("track", "PageView");
}

/** The steps between the landing page view and a lead. Standard names where Meta has one, custom otherwise. */
export type MetaFunnelEvent = "ViewContent" | "InitiateCheckout" | "ChatOpen" | "ChatMessage" | "CTAClick" | "Scroll75";
const STANDARD: MetaFunnelEvent[] = ["ViewContent", "InitiateCheckout"];
const sentOnce = new Set<string>();
const pending: [MetaFunnelEvent, Record<string, string | number>, boolean][] = [];

function cookie(name: string): string | undefined {
  const m = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
  return m ? decodeURIComponent(m[1]) : undefined;
}

/**
 * One funnel step to Meta, through the pixel and through our API to the Conversions API, with the
 * same event id so Meta keeps one. Nothing without consent. `once` keeps a step to one per page load.
 */
export function metaEvent(name: MetaFunnelEvent, params: Record<string, string | number> = {}, once = true): void {
  if (typeof window === "undefined") return;
  // Before the consent answer is read (the chat opens on the first render): wait for it, then send or drop.
  if (!started) {
    if (pending.length < 10) pending.push([name, params, once]);
    return;
  }
  if (!granted) return;
  const key = `${name}:${location.pathname}:${params.content_name ?? ""}`;
  if (once && sentOnce.has(key)) return;
  sentOnce.add(key);
  const eventID = typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
  window.fbq?.(STANDARD.includes(name) ? "track" : "trackCustom", name, params, { eventID });
  try {
    const click = JSON.parse(localStorage.getItem("appwerk.adClick") ?? "null") as { fbclid?: string; ts?: number } | null;
    void fetch(`${API_BASE}/api/meta-event`, {
      method: "POST",
      keepalive: true,
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ consent: true, event: name, event_id: eventID, url: location.href.split("#")[0].slice(0, 500),
        fbp: cookie("_fbp"), fbc: cookie("_fbc"), fbclid: click?.fbclid, clicked_at: click?.ts, value: params.value }),
    }).catch(() => {});
  } catch {
    /* the pixel has it; the server copy is a second chance, not a must */
  }
}
