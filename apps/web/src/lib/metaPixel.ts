/**
 * Meta Pixel, loaded only after a yes to ad measurement (2026-10-01).
 *
 * The pixel sends page views so Meta can show ads to people like those who visit, and so a
 * campaign can be measured by landing page views. Requests and purchases are not sent from here:
 * the API reports them through the Conversions API (App\Domain\Ads\Conversions), and sending them
 * twice would count them twice. A later no revokes consent in the pixel and drops its cookies.
 */
type Fbq = ((...args: unknown[]) => void) & { callMethod?: (...a: unknown[]) => void; queue: unknown[][]; loaded?: boolean; version?: string; push?: unknown };
declare global {
  interface Window {
    fbq?: Fbq;
    _fbq?: Fbq;
  }
}

let started = false;

export function applyMetaPixel(pixelId: string | null, ads: boolean): void {
  if (typeof window === "undefined" || !pixelId || !/^\d{6,20}$/.test(pixelId)) return;
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
}

/** A page view after a client-side navigation; nothing before consent. */
export function metaPageView(): void {
  if (started) window.fbq?.("track", "PageView");
}
