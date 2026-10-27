/**
 * First-party analytics beacon (apps/api/app/Domain/Analytics/Analytics.php).
 *
 * No cookies and no storage: where the visit came from (referrer, utm_*, ad click ids) is read
 * from the first URL of the page load and kept in memory, so client-side navigation keeps it and a
 * reload starts over. The API hashes address and browser with a daily salt; nothing here
 * identifies a person. Failures are silent: analytics must never get in a visitor's way.
 */
import { API_BASE } from "@/lib/api";

type Source = { referrer?: string; utm_source?: string; utm_medium?: string; utm_campaign?: string; click_id?: string };

let source: Source | null = null;
const sentOnce = new Set<string>();

function landingSource(): Source {
  if (source) return source;
  source = {};
  try {
    const q = new URLSearchParams(window.location.search);
    source.utm_source = q.get("utm_source") ?? undefined;
    source.utm_medium = q.get("utm_medium") ?? undefined;
    // The ad within a campaign rides along as "campaign/content" (2026-10-21, banner test v3-salon,
    // v3-lokal, v3-laden), so the funnel can tell the banners apart without a new column.
    const campaign = q.get("utm_campaign") ?? undefined;
    // The landing A/B test (2026-10-27): the page says which variant it is, and it rides along in the
    // content part, for example "appwerk-6/v3-lokal.lp-desk".
    const variant = document.querySelector("[data-ab-variant]")?.getAttribute("data-ab-variant");
    const content = [q.get("utm_content"), variant ? `lp-${variant}` : null].filter(Boolean).join(".");
    source.utm_campaign = campaign && content ? `${campaign}/${content}`.slice(0, 120) : campaign;
    source.click_id = ["gclid", "fbclid", "msclkid"].find((k) => q.has(k));
    source.referrer = document.referrer || undefined;
  } catch {
    /* no window: nothing to read */
  }
  return source;
}

/** `path` defaults to the current one; a page_leave sent after a client-side navigation names the page that was left. */
export function track(name: string, props: Record<string, string | number | boolean | null> = {}, path?: string): void {
  if (typeof window === "undefined") return;
  try {
    const at = path ?? window.location.pathname;
    const locale = at.split("/")[1];
    const body = JSON.stringify({ name, path: at, locale, props, ...landingSource() });
    const url = `${API_BASE}/api/t`;
    // text/plain keeps it a simple request: no CORS preflight, and sendBeacon survives a page leave.
    if (!navigator.sendBeacon?.(url, new Blob([body], { type: "text/plain" }))) {
      void fetch(url, { method: "POST", body, keepalive: true, headers: { "content-type": "text/plain" } }).catch(() => {});
    }
  } catch {
    /* never in the visitor's way */
  }
}

/** The same event only once per page load, for "reached this step" signals. */
export function trackOnce(key: string, name: string, props: Record<string, string | number | boolean | null> = {}): void {
  if (sentOnce.has(key)) return;
  sentOnce.add(key);
  track(name, props);
}
