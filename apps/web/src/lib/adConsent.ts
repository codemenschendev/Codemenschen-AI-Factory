/**
 * Ad measurement consent and the ad click it covers (2026-09-23).
 *
 * A visitor who arrives from an ad (a gclid or fbclid in the link) is asked once whether we may
 * report to that platform what happens next. Only a yes keeps the click, in this browser, for up
 * to 90 days, and only then does it travel with a quote or prototype request in the X-Ad-Click
 * header. A no is remembered so the question is not asked again; the click is dropped.
 * Every read and write is guarded: private windows and blocked storage just mean no measurement.
 */

const CHOICE = "appwerk.adMeasure";
const CLICK = "appwerk.adClick";
const MAX_AGE_MS = 90 * 24 * 60 * 60 * 1000;
export const OPEN_EVENT = "appwerk:ad-consent";

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
    /* storage blocked: nothing is kept, nothing is reported */
  }
}

/** The click in the link this page was opened with, read once per page load. */
function arrivedClick(): Click | null {
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

export function choice(): "yes" | "no" | null {
  const v = read(CHOICE);
  return v === "yes" || v === "no" ? v : null;
}

/** True when this visitor came from an ad and has not been asked yet. */
export function shouldAsk(): boolean {
  return typeof window !== "undefined" && arrivedClick() !== null && choice() === null;
}

export function decide(value: "yes" | "no"): void {
  write(CHOICE, value);
  const click = arrivedClick();
  if (value === "yes" && click) write(CLICK, JSON.stringify(click));
  if (value === "no") write(CLICK, null);
}

/** A yes keeps the newest ad click; called on every page load so a second ad click replaces the first. */
export function keepArrivedClick(): void {
  const click = arrivedClick();
  if (click && choice() === "yes") write(CLICK, JSON.stringify(click));
}

/** The header value for the API, or null: no consent, no click, or the click is too old. */
export function adClickHeader(): string | null {
  if (typeof window === "undefined" || choice() !== "yes") return null;
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
