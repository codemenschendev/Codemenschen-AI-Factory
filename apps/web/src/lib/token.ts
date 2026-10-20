/**
 * The sign-in token, as a store rather than a value read in an effect.
 *
 * Three panels used to read localStorage inside useEffect and setState with what they found,
 * which React 19's lint calls a cascading render and which is also two paints where one would
 * do. Read through useSyncExternalStore, the server renders the "not known yet" state, the
 * browser renders the real one, and every panel sees a sign-out the moment it happens.
 *
 * `undefined` means not read yet (the server, or hydration); `null` means read and absent.
 */
import { useSyncExternalStore } from "react";

const KEY = "aifactory-token";

/**
 * One sign-in for appmitki.com and console.appmitki.com (2026-10-20: signing in on one and following
 * a link to the other lost the session, because localStorage belongs to one host). The token is also
 * kept in a cookie for the parent domain. Only for appmitki.com: under codemenschen.at the same cookie
 * would reach every other site on that domain.
 */
const SHARED = /(^|\.)appmitki\.com$/;
const COOKIE_DAYS = 30;

function sharedDomain(): string | null {
  try {
    return SHARED.test(location.hostname) ? ".appmitki.com" : null;
  } catch {
    return null;
  }
}

function readCookie(): string | null {
  try {
    const m = document.cookie.match(new RegExp(`(?:^|; )${KEY}=([^;]*)`));
    return m ? decodeURIComponent(m[1]) : null;
  } catch {
    return null;
  }
}

function writeCookie(value: string | null): void {
  const domain = sharedDomain();
  if (!domain) return;
  try {
    document.cookie = value === null
      ? `${KEY}=; Domain=${domain}; Path=/; Max-Age=0; Secure; SameSite=Lax`
      : `${KEY}=${encodeURIComponent(value)}; Domain=${domain}; Path=/; Max-Age=${COOKIE_DAYS * 86400}; Secure; SameSite=Lax`;
  } catch {
    // Without cookies the sign-in stays on this host only, as before.
  }
}
const listeners = new Set<() => void>();

function publish(): void {
  for (const l of listeners) l();
}

let moved = false;

/** A sign-in from before the shared cookie lives in this host's localStorage: move it over once. */
function moveOldSignIn(): void {
  if (moved) return;
  moved = true;
  try {
    const old = localStorage.getItem(KEY);
    if (old !== null) {
      if (readCookie() === null) writeCookie(old);
      localStorage.removeItem(KEY);
    }
  } catch {
    // nothing to move
  }
}

export function getToken(): string | null {
  // On appmitki.com hosts the shared cookie is the one place: a sign-in or sign-out on one host
  // holds for all of them.
  if (sharedDomain()) {
    moveOldSignIn();
    return readCookie();
  }
  try {
    return localStorage.getItem(KEY);
  } catch {
    return null;
  }
}

export function setToken(value: string | null): void {
  if (sharedDomain()) {
    writeCookie(value);
    publish();
    return;
  }
  try {
    if (value === null) localStorage.removeItem(KEY);
    else localStorage.setItem(KEY, value);
  } catch {
    // A browser that will not store it signs the visitor out on the next load. Nothing else breaks.
  }
  publish();
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener);
  const onStorage = (e: StorageEvent) => {
    if (e.key === KEY || e.key === null) publish();
  };
  window.addEventListener("storage", onStorage);

  return () => {
    listeners.delete(listener);
    window.removeEventListener("storage", onStorage);
  };
}

const notYet = (): undefined => undefined;

export function useToken(): string | null | undefined {
  return useSyncExternalStore(subscribe, getToken, notYet);
}
