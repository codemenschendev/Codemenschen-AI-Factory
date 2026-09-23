/**
 * Google Tag Manager with Consent Mode v2, loaded only after a yes (2026-09-23).
 *
 * Nothing from Google is requested before the visitor agrees to at least one purpose. Consent is
 * declared denied by default and then updated to what the visitor chose, so every tag inside the
 * container knows what it may do. A later no updates consent to denied and removes Google's
 * cookies; the container itself stays in the page until the next load, but may not store or send
 * anything the visitor refused.
 */
import type { Consent } from "./adConsent";

type DataLayer = unknown[];
declare global {
  interface Window {
    dataLayer?: DataLayer;
  }
}

let loaded = false;

// Google's own snippet: Tag Manager reads consent commands from the arguments object, not an array.
// eslint-disable-next-line @typescript-eslint/no-unused-vars
function gtag(..._args: unknown[]): void {
  window.dataLayer = window.dataLayer ?? [];
  // eslint-disable-next-line prefer-rest-params
  window.dataLayer.push(arguments);
}

function consentState(c: Consent) {
  const ads = c.ads ? "granted" : "denied";
  return {
    analytics_storage: c.stats ? "granted" : "denied",
    ad_storage: ads,
    ad_user_data: ads,
    ad_personalization: ads,
  };
}

/** Loads the container once, with the visitor's consent already in place. */
export function applyConsent(gtmId: string | null, c: Consent): void {
  if (typeof window === "undefined" || !gtmId) return;
  if (!loaded) {
    if (!c.stats && !c.ads) return;
    gtag("consent", "default", { ...consentState({ stats: false, ads: false }), wait_for_update: 500 });
    gtag("consent", "update", consentState(c));
    window.dataLayer!.push({ "gtm.start": Date.now(), event: "gtm.js" });
    const s = document.createElement("script");
    s.async = true;
    s.src = `https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(gtmId)}`;
    document.head.appendChild(s);
    loaded = true;
    return;
  }
  gtag("consent", "update", consentState(c));
  if (!c.stats) dropCookies(/^_ga/);
  if (!c.ads) dropCookies(/^(_gcl|_fbp|_fbc)/);
}

/** An event for the tags in the container, for example generate_lead. Nothing happens before consent. */
export function tagEvent(event: string, params: Record<string, string | number> = {}): void {
  if (typeof window === "undefined" || !loaded) return;
  window.dataLayer!.push({ event, ...params });
}

function dropCookies(name: RegExp): void {
  const host = window.location.hostname;
  const domains = ["", host, `.${host}`, `.${host.split(".").slice(-2).join(".")}`];
  for (const part of document.cookie.split(";")) {
    const key = part.split("=")[0]?.trim();
    if (!key || !name.test(key)) continue;
    for (const d of domains) {
      document.cookie = `${key}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/${d ? `; domain=${d}` : ""}`;
    }
  }
}
