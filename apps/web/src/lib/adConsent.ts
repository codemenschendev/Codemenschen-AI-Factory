/**
 * Consent on the storefront (2026-09-23): two purposes, each off until the visitor says yes.
 *
 * - stats: Google Analytics, through Google Tag Manager.
 * - ads: ad measurement. Tag Manager may load Google Ads and Meta tags, and a visitor who came from
 *   an ad has the click (gclid or fbclid) kept, for up to 90 days, so the API can report a request
 *   or an order back to that platform (X-Ad-Click header).
 *
 * The choice is kept in this browser so the question is asked once. Every read and write is
 * guarded: private windows and blocked storage just mean nothing is measured.
 */

const CONSENT = "appwerk.consent";
const LEGACY = "appwerk.adMeasure"; // the first version asked about ads only
const CLICK = "appwerk.adClick";
const MAX_AGE_MS = 90 * 24 * 60 * 60 * 1000;
export const OPEN_EVENT = "appwerk:consent-open";
export const CHANGE_EVENT = "appwerk:consent-change";

export type Consent = { stats: boolean; ads: boolean };
type Click = { gclid?: string; fbclid?: string; ts: number; url: string };

let arrived: Click | null | undefined;

function read(key: string): string | null {
  try {
    return window.localStorage.getItem(key);
  } catch {
    return null;
  }
}

function write(key: string, value: string | null): void {
  try {
    if (value === null) window.localStorage.removeItem(key);
    else window.localStorage.setItem(key, value);
  } catch {
    /* storage blocked: nothing is kept, nothing is measured */
  }
}

/** The click in the link this page was opened with, read once per page load. */
export function arrivedClick(): Click | null {
  if (arrived !== undefined) return arrived;
  arrived = null;
  try {
    const q = new URLSearchParams(window.location.search);
    const gclid = q.get("gclid") ?? undefined;
    const fbclid = q.get("fbclid") ?? undefined;
    if (gclid || fbclid) {
      arrived = { gclid, fbclid, ts: Date.now(), url: window.location.origin + window.location.pathname };
    }
  } catch {
    /* no window */
  }
  return arrived;
}

/** The visitor's choice, or null when they have not been asked yet. */
export function consent(): Consent | null {
  if (typeof window === "undefined") return null;
  try {
    const v = JSON.parse(read(CONSENT) ?? "null") as Partial<Consent> | null;
    if (v && typeof v.stats === "boolean" && typeof v.ads === "boolean") return { stats: v.stats, ads: v.ads };
  } catch {
    /* unreadable: ask again */
  }
  const legacy = read(LEGACY);
  return legacy === "yes" || legacy === "no" ? { stats: false, ads: legacy === "yes" } : null;
}

export function decide(value: Consent): void {
  write(CONSENT, JSON.stringify(value));
  write(LEGACY, null);
  const click = arrivedClick();
  if (value.ads && click) write(CLICK, JSON.stringify(click));
  if (!value.ads) write(CLICK, null);
  window.dispatchEvent(new CustomEvent(CHANGE_EVENT, { detail: value }));
}

/** A yes to ads keeps the newest ad click; called on every page load so a second click replaces the first. */
export function keepArrivedClick(): void {
  const click = arrivedClick();
  if (click && consent()?.ads) write(CLICK, JSON.stringify(click));
}

/** The header value for the API, or null: no consent, no click, or the click is too old. */
export function adClickHeader(): string | null {
  if (typeof window === "undefined" || !consent()?.ads) return null;
  try {
    const click = JSON.parse(read(CLICK) ?? "null") as Click | null;
    if (!click || Date.now() - click.ts > MAX_AGE_MS) return null;
    return JSON.stringify({ consent: true, ...click });
  } catch {
    return null;
  }
}

/** Opens the question again, from the footer link, so a yes can be taken back. */
export function reopen(): void {
  window.dispatchEvent(new Event(OPEN_EVENT));
}
